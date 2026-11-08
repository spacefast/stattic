<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/security-telemetry.php';
require_once __DIR__ . '/upload.php';

// The record stores the bulk tick expires. Only bookkeeping that holds no
// customer data or history is listed here: security-telemetry gap markers,
// publish pins and the version-to-session pointers. Everything else the engine
// writes (versions, blobs, staging trees, compiled releases, rolled journals,
// job records, publish sessions, route shards) is kept until the control plane
// asks for an explicit delete.

/** @return list<array> record stores the bulk tick sweeps. */
function _stattic_runtime_retention_stores(string $privateRoot): array
{
    $stores = [_stattic_security_gap_store($privateRoot)];
    // A pin outlives its session whenever the release call never arrived (a
    // dead mover, or a deferred release), so expired pins are swept here. An
    // unenumerable space tree aborts the sweep: silently seeing zero spaces
    // would complete retention without doing its per-space half.
    $spaceRoots = _stattic_runtime_space_roots($privateRoot);
    if ($spaceRoots === null) {
        throw new RuntimeException('runtime document enumeration failed: ' . $privateRoot . '/spaces');
    }
    foreach ($spaceRoots as $spaceRoot) {
        $spaceId = basename($spaceRoot);
        $stores[] = _stattic_runtime_publish_session_index_store($privateRoot, $spaceId);
        $stores[] = _stattic_runtime_publish_pins_store($privateRoot, $spaceId);
    }
    return $stores;
}

/**
 * Throws rather than half-sweep: an unenumerable space tree is a
 * RuntimeException from _stattic_runtime_retention_stores.
 *
 * $deadline is the TICK's, consulted between stores and, inside each store,
 * between records, so a large store cannot hold the bulk lane past the budget.
 */
function _stattic_runtime_job_housekeeping_retention(string $privateRoot, array $claims, float $deadline): bool
{
    foreach (_stattic_runtime_retention_stores($privateRoot) as $store) {
        if (microtime(true) >= $deadline) {
            return false;
        }
        _stattic_record_store_sweep($store, null, $deadline);
    }
    return microtime(true) < $deadline;
}
