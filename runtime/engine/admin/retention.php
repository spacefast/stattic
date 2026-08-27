<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/purge.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/upload.php';

// THE retention registry: everything the bulk tick reclaims is listed here, and
// listing it is all it takes. A store carries its own window in its descriptor
// (record-store.php `retention`); a staging root carries one here, because a
// root is a tree, not a record. A tree whose survivors are chosen by something
// other than age gets its own sweeper here, called from the same tick — not its
// own driver somewhere else.
//
// Stores swept where they are written are deliberately absent: the jti replay
// markers on token consume, upload sessions on version create. They already
// have a driver, on a lane that runs when the tick may not.

// An interrupted worker's staging debris. Nothing under these roots is a
// recovery source, so the window is only a margin against a live worker slower
// than the sweep.
const STATTIC_RUNTIME_STAGING_RETENTION_SECONDS = 86400;

// One root IS a recovery source: .rust-failed-* is the sole surviving artifact of
// a failed native finalize. Long enough that abandonment, not slowness, is what
// reclaims it.
const STATTIC_RUNTIME_ABANDONED_RETENTION_SECONDS = 14 * 86400;

// The journal (shared/storage.php) caps the one live file at 8 MiB and rolls
// it aside; this bounds how long a rolled-aside generation survives before the
// sweep reclaims it.
const STATTIC_RUNTIME_LOG_RETENTION_SECONDS = 14 * 86400;

// A killed content compile's stage root. Nothing reads it; the only reason to
// wait at all is a live compile slower than the sweep, and the compiler's own
// subprocess deadline is 60s.
const STATTIC_RUNTIME_CONTENT_STAGE_RETENTION_SECONDS = 3600;

// How many compiled content releases a Space keeps behind its active one, and
// how long an unreachable one may sit before the sweep takes it.
const STATTIC_RUNTIME_CONTENT_RELEASES_KEPT = 3;
const STATTIC_RUNTIME_CONTENT_RELEASE_RETENTION_SECONDS = 7 * 86400;

// Every registered root is globbed on this cadence, not on every bulk tick.
const STATTIC_RUNTIME_RECLAIM_INTERVAL_SECONDS = 3600;

/** @return list<array> record stores the bulk tick sweeps. */
function _stattic_runtime_retention_stores(string $privateRoot): array
{
    // The journal is the only sink and rotates by size, and the pull cursor is
    // a single file (D53), so neither needs sweeping.
    $stores = [
        _stattic_runtime_jobs_queue_store($privateRoot),
        _stattic_runtime_jobs_dead_store($privateRoot),
    ];
    // Per-space stores: publish sessions and GC pins both carry their own
    // expires_at. A pin outlives its session whenever the release call never
    // arrived (a dead mover, or a deferred release), so it is swept here too.
    // An unenumerable space tree aborts the sweep: silently seeing zero
    // spaces would complete retention without doing its per-space half.
    $spaceRoots = _stattic_runtime_space_roots($privateRoot);
    if ($spaceRoots === null) {
        throw new RuntimeException('runtime document enumeration failed: ' . $privateRoot . '/spaces');
    }
    foreach ($spaceRoots as $spaceRoot) {
        $spaceId = basename($spaceRoot);
        $stores[] = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
        $stores[] = _stattic_runtime_publish_pins_store($privateRoot, $spaceId);
    }
    return $stores;
}

/** @return list<array{0:string,1:int}> glob pattern => how long an entry may sit untouched. */
function _stattic_runtime_retention_roots(string $privateRoot): array
{
    return [
        [$privateRoot . '/runtime/blob-staging/*', STATTIC_RUNTIME_STAGING_RETENTION_SECONDS],
        [$privateRoot . '/runtime/finalizer-inputs/*', STATTIC_RUNTIME_STAGING_RETENTION_SECONDS],
        // D140 probe blobs: content-addressed, rewritten on demand, worthless once cold.
        [$privateRoot . '/runtime/probe/*', STATTIC_RUNTIME_STAGING_RETENTION_SECONDS],
        // The live journal.jsonl is written continuously and never stale; only
        // a rolled-aside generation nothing appends to ages out.
        [$privateRoot . '/runtime/journal-*.jsonl', STATTIC_RUNTIME_LOG_RETENTION_SECONDS],
        // An interrupted native finalize's staging tree: Rust removes it on
        // every path it survives, so one that is still here is debris from a
        // process that was killed. Its hardlinks into the space CAS also pin
        // blobs the GC has already unlinked, so leaving it frees no disk. The
        // staging window is ~278x the finalizer's 310s subprocess deadline
        // (admin/finalize-rust.php), so a live run's stage is never reclaimed
        // under it.
        [$privateRoot . '/spaces/*/versions/.*.rust-finalizing', STATTIC_RUNTIME_STAGING_RETENTION_SECONDS],
        // Quarantined by _stattic_runtime_restore_interrupted_version. The
        // sibling `.rust-previous` is live recovery state and is NOT listed.
        [$privateRoot . '/spaces/*/versions/.*.rust-failed-*', STATTIC_RUNTIME_ABANDONED_RETENTION_SECONDS],
        // A content compile's stage root (content-kernel.php
        // spacefast_content_compile_schema). The compile removes it on every
        // path it survives, including its own failures, so one still here is
        // debris from a killed worker. Nothing reads it and nothing recovers
        // from it, so the window is only a margin over the compiler's own 60s
        // subprocess deadline.
        [$privateRoot . '/spaces/*/content/compile-*', STATTIC_RUNTIME_CONTENT_STAGE_RETENTION_SECONDS],
    ];
}

/**
 * Compiled content releases, which age out by COUNT before they age out by
 * clock: every schema.compile writes a new revision tree and repoints
 * active-release at it, so a Space that compiles often would otherwise keep one
 * tree per compile forever.
 *
 * Two things must survive: the release the pointer names, whatever its age —
 * deleting it is a site outage for that Space's compiled schema — and the
 * newest few behind it, so a worker that resolved the pointer just before a
 * compile keeps serving, and so a bad compile can be pointed back at its
 * predecessor. Everything past that is unreachable, and reclaimed once it is
 * also cold.
 *
 * @return int releases reclaimed
 */
function _stattic_runtime_reclaim_content_releases(string $privateRoot, ?int $now = null): int
{
    $now ??= time();
    $deadline = $now - STATTIC_RUNTIME_CONTENT_RELEASE_RETENTION_SECONDS;
    $reclaimed = 0;
    foreach (glob($privateRoot . '/spaces/*/content') ?: [] as $contentRoot) {
        $active = _stattic_private_tree_read_pointer($contentRoot . '/active-release', 128);
        $releases = [];
        foreach (glob($contentRoot . '/releases/*') ?: [] as $release) {
            if (!is_dir($release)) {
                continue;
            }
            $mtime = filemtime($release);
            $releases[$release] = $mtime === false ? $now : $mtime;
        }
        arsort($releases);
        $kept = 0;
        foreach ($releases as $release => $mtime) {
            if (basename($release) === $active || $kept < STATTIC_RUNTIME_CONTENT_RELEASES_KEPT) {
                $kept += 1;
                continue;
            }
            if ($mtime > $deadline) {
                continue;
            }
            _stattic_runtime_rm_recursive($release);
            $reclaimed += 1;
        }
    }
    return $reclaimed;
}

function _stattic_runtime_job_housekeeping_retention(string $privateRoot, array $claims = []): void
{
    foreach (_stattic_runtime_retention_stores($privateRoot) as $store) {
        _stattic_record_store_sweep($store);
    }

    _stattic_sweep_throttled(
        $privateRoot . '/runtime/reclaim.marker',
        STATTIC_RUNTIME_RECLAIM_INTERVAL_SECONDS,
        static function () use ($privateRoot): void {
            $reclaimed = [];
            foreach (_stattic_runtime_retention_roots($privateRoot) as [$pattern, $maxAgeSeconds]) {
                $count = _stattic_reclaim_stale_paths($pattern, $maxAgeSeconds);
                if ($count > 0) {
                    $reclaimed[_stattic_runtime_relative_to($privateRoot, $pattern)] = $count;
                }
            }
            $releases = _stattic_runtime_reclaim_content_releases($privateRoot);
            if ($releases > 0) {
                $reclaimed['spaces/*/content/releases'] = $releases;
            }
            if ($reclaimed !== []) {
                _stattic_runtime_append_journal($privateRoot, [
                    'event' => 'runtime_staging_reclaimed',
                    'roots' => $reclaimed,
                ]);
            }
        },
    );
}
