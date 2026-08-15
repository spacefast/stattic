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
// root is a tree, not a record.
//
// Stores swept where they are written — the jti replay markers on token
// consume, upload sessions on version create — are deliberately absent: they
// already have a driver, on a lane that runs when the tick may not.

// An interrupted worker's staging debris. Nothing under these roots is a
// recovery source, so the window is only a margin against a live worker slower
// than the sweep.
const STATTIC_RUNTIME_STAGING_RETENTION_SECONDS = 86400;

// One root IS a recovery source: .rust-failed-* is the sole surviving artifact of
// a failed native finalize. Long enough that abandonment, not slowness, is what
// reclaims it.
const STATTIC_RUNTIME_ABANDONED_RETENTION_SECONDS = 14 * 86400;

// An append-only log (shared/ndjson-log.php) caps one file; this is what bounds
// the history. Longer than the Functions reader's own horizon — it opens at most
// STATTIC_FUNCTIONS_LOG_SCAN_MAX_FILES day files — so nothing readable is ever
// reclaimed out from under a page.
const STATTIC_RUNTIME_LOG_RETENTION_SECONDS = 14 * 86400;

// Every registered root is globbed on this cadence, not on every bulk tick.
const STATTIC_RUNTIME_RECLAIM_INTERVAL_SECONDS = 3600;

/** @return list<array> record stores the bulk tick sweeps. */
function _stattic_runtime_retention_stores(string $privateRoot): array
{
    // The callback delivered/pending stores and the tier spool are gone with the
    // push lane (D53): the journal is the only sink and rotates by size, and the
    // pull cursor is a single file, so neither needs sweeping.
    $stores = [
        _stattic_runtime_jobs_queue_store($privateRoot),
        _stattic_runtime_jobs_dead_store($privateRoot),
        // Dead purge records only — a queued one is still owed to the edge and is
        // never swept (shared/purge.php pins that in the descriptor).
        _stattic_runtime_purge_store($privateRoot),
    ];
    // Per-space stores: publish sessions and GC pins both carry their own
    // expires_at. A pin outlives its session whenever the release call never
    // arrived (a dead mover, or a deferred release), so it is swept here too.
    foreach (glob($privateRoot . '/spaces/*', GLOB_ONLYDIR) ?: [] as $spaceRoot) {
        if (is_string($spaceRoot)) {
            $spaceId = basename($spaceRoot);
            $stores[] = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
            $stores[] = _stattic_runtime_publish_pins_store($privateRoot, $spaceId);
        }
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
        // The live journal.jsonl and today's day file are never stale: both are
        // written continuously, and only a generation nothing appends to ages out.
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
    ];
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
            if ($reclaimed !== []) {
                _stattic_runtime_append_journal($privateRoot, [
                    'event' => 'runtime_staging_reclaimed',
                    'roots' => $reclaimed,
                ]);
            }
        },
    );
}
