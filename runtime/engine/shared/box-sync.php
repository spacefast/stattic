<?php
declare(strict_types=1);

// The box's sync watermark: when the control plane last proved, with a signed
// `/heartbeat`, that it reaches this box. A private Space's access rules live
// in its overlay, which only a reachable control plane can change; a box cut
// off from it for an hour keeps admitting with rules nobody can revoke. So a
// private Space fails closed past the cutoff, while a public one keeps
// serving: there is nothing on it to revoke.
//
// Only a heartbeat arms the cutoff, and only for its lease: the heartbeat's
// signed `box_sync_lease` (seconds, capped here at a day). A box the control
// plane stops heartbeating (it no longer serves a private Space, or the
// control plane that ran the sweep was rolled back) stops failing closed once
// the lease runs out, and an engine re-installed over an old watermark never
// trips on a lease long gone. A verified management write that is not stamped
// `box_sync` comes from a control plane that runs no heartbeat sweep at all,
// so it disarms the box at once.
//
// The watermark only moves forward. The time it records is the heartbeat
// token's own `iat` (capped at now), so a token signed long ago and delivered
// late does not count as fresh contact, and the read-compare-write holds an
// exclusive lock so two heartbeats landing together never move it back.

const STATTIC_BOX_SYNC_STALE_SECONDS = 3600;
const STATTIC_BOX_SYNC_RETRY_AFTER_SECONDS = 60;
const STATTIC_BOX_SYNC_MAX_LEASE_SECONDS = 86400;

function _stattic_box_sync_path(string $privateRoot): string
{
    return $privateRoot . '/runtime/box-sync.json';
}

/**
 * The recorded watermark and lease (unix seconds), or null when none is
 * recorded.
 *
 * @return array{synced_at: int, enforce_until: int}|null
 */
function _stattic_box_sync_read(string $privateRoot): ?array
{
    $raw = @file_get_contents(_stattic_box_sync_path($privateRoot));
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    $syncedAt = is_array($decoded) ? ($decoded['synced_at'] ?? null) : null;
    $enforceUntil = is_array($decoded) ? ($decoded['enforce_until'] ?? null) : null;
    return is_int($syncedAt) && $syncedAt > 0 && is_int($enforceUntil)
        ? ['synced_at' => $syncedAt, 'enforce_until' => $enforceUntil]
        : null;
}

/** Runs `$change` holding the watermark's lock; storage that refuses skips it. */
function _stattic_box_sync_locked(string $privateRoot, callable $change): void
{
    require_once __DIR__ . '/storage.php';
    $path = _stattic_box_sync_path($privateRoot);
    if (!_stattic_runtime_mkdir_soft(dirname($path))) {
        return;
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return;
    }
    try {
        if (flock($lock, LOCK_EX)) {
            $change($path);
        }
    } catch (Throwable) {
        // A watermark that cannot be written leaves the old one standing; the
        // request that carried it still succeeds.
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * A signed heartbeat: moves the watermark to its `iat` (capped at now) if that
 * is newer, and sets the lease it carries from there.
 */
function _stattic_box_sync_record(string $privateRoot, int $issuedAt, int $leaseSeconds): void
{
    $syncedAt = min($issuedAt, time());
    $lease = max(0, min($leaseSeconds, STATTIC_BOX_SYNC_MAX_LEASE_SECONDS));
    _stattic_box_sync_locked($privateRoot, static function (string $path) use ($privateRoot, $syncedAt, $lease): void {
        $current = _stattic_box_sync_read($privateRoot);
        if ($current !== null && $current['synced_at'] >= $syncedAt) {
            return;
        }
        _stattic_runtime_write_json_atomic(
            $path,
            ['synced_at' => $syncedAt, 'enforce_until' => $syncedAt + $lease],
            true
        );
    });
}

/** A control plane that runs no heartbeat sweep: no cutoff on this box. */
function _stattic_box_sync_disarm(string $privateRoot): void
{
    if (!is_file(_stattic_box_sync_path($privateRoot))) {
        return;
    }
    _stattic_box_sync_locked($privateRoot, static function (string $path): void {
        @unlink($path);
    });
}

/**
 * What one verified management request means for the watermark: a heartbeat
 * records, a write from a control plane that does not stamp `box_sync`
 * disarms, anything else leaves it.
 */
function _stattic_box_sync_observe(string $privateRoot, string $action, string $method, array $claims): void
{
    if ($action === 'box_heartbeat') {
        $lease = $claims['box_sync_lease'] ?? null;
        if (is_int($lease) && $lease > 0) {
            _stattic_box_sync_record($privateRoot, is_int($claims['iat'] ?? null) ? $claims['iat'] : time(), $lease);
        }
        return;
    }
    if ($method !== 'GET' && $method !== 'HEAD' && ($claims['box_sync'] ?? null) !== 1) {
        _stattic_box_sync_disarm($privateRoot);
    }
}

/**
 * Whether this box has lost the control plane for longer than the cutoff,
 * while a heartbeat's lease still holds. False when none was recorded.
 */
function _stattic_box_sync_stale(string $privateRoot, ?int $now = null): bool
{
    $recorded = _stattic_box_sync_read($privateRoot);
    $now ??= time();
    return $recorded !== null
        && $now <= $recorded['enforce_until']
        && $now - $recorded['synced_at'] > STATTIC_BOX_SYNC_STALE_SECONDS;
}
