<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/storage.php';

// Shared concurrency cap across long-blocking requests (Worker dispatches,
// synchronous connector calls, publish uploads, legacy Zero invocations, and
// control-plane exchanges). On a synced 10-worker box, 6 covers parallel
// browser connections while keeping 4 FPM workers free for re-entrant Worker
// callbacks (env.DB, env.STORAGE, env.ASSETS, env.SPACE, purge, logs).
const STATTIC_BLOCKING_DEFAULT_LIMIT = 6;
// Conservative ceiling when neither the route overlay (`admission.php_workers`)
// nor `SPACEFAST_PHP_CONNS` names the box's worker count (e.g., an un-synced
// pre-upgrade overlay). `1` keeps at least one callback worker free even on
// the smallest supported 2-worker box until the control plane projects
// `php_workers`.
const STATTIC_BLOCKING_UNKNOWN_CAPACITY_CEILING = 1;
const STATTIC_ADMISSION_RETRY_AFTER_SECONDS = 2;
// Legacy JSON counter freshness window read during forward cutover so old-engine
// in-flight holders are subtracted from the shared blocking budget.
const STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS = 120;

// Keys carry caller-controlled input (space id, lane): this allowlist makes
// them safe as filesystem path segments.
function _stattic_admission_sanitize_key(string $value): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
}

function _stattic_admission_slot_dir(string $privateRoot, string $lane, string $spaceId): string
{
    return $privateRoot
        . '/runtime/admission/'
        . _stattic_admission_sanitize_key($lane)
        . '/'
        . _stattic_admission_sanitize_key($spaceId);
}

function _stattic_admission_positive_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        return (int) $value;
    }
    return null;
}

/**
 * Effective guaranteed PHP worker count when projected by the control plane
 * (`admission.php_workers`) or configured via `SPACEFAST_PHP_CONNS`, or null
 * when unknown (pre-upgrade overlay).
 */
function _stattic_admission_php_workers(array $serving): ?int
{
    $admission = is_array($serving['admission'] ?? null) ? $serving['admission'] : [];
    $projected = _stattic_admission_positive_int($admission['php_workers'] ?? null);
    if ($projected !== null) {
        return $projected;
    }
    return _stattic_admission_positive_int(_stattic_config_value('SPACEFAST_PHP_CONNS'));
}

// Always keep a callback reserve below the box's guaranteed PHP worker count:
// half the pool on tiny boxes (4 -> 2, 2 -> 1), capped at 3 on standard and
// larger boxes (10 -> 7, 24 -> 21). Unknown box capacity stays at 1 slot so
// even an un-synced 2-worker box cannot exhaust its callback reserve.
function _stattic_admission_blocking_ceiling(?int $phpWorkers): int
{
    if ($phpWorkers === null) {
        return STATTIC_BLOCKING_UNKNOWN_CAPACITY_CEILING;
    }
    $phpWorkers = max(1, $phpWorkers);
    $reserve = max(1, min(3, intdiv($phpWorkers, 2)));
    return max(1, $phpWorkers - $reserve);
}

function _stattic_admission_blocking_limit(array $serving): int
{
    $requested = _stattic_config_int(
        'SPACEFAST_FUNCTIONS_DISPATCH_CONC_PER_SPACE',
        STATTIC_BLOCKING_DEFAULT_LIMIT,
    );
    $admission = is_array($serving['admission'] ?? null) ? $serving['admission'] : [];
    $override = _stattic_admission_positive_int($admission['concurrency'] ?? null);
    if ($override !== null) {
        $requested = $override;
    }
    $ceiling = _stattic_admission_blocking_ceiling(_stattic_admission_php_workers($serving));
    return max(1, min(max(1, $requested), $ceiling));
}

/**
 * Read a Space's admission overlay for upload ingest. Only a verifiably absent
 * `space.json` (first publish before any route exists) falls back to empty
 * admission settings; an unreadable/corrupt pointer or a broken/missing overlay
 * reference returns `null` so callers fail closed with 503 instead of widening
 * a configured limit.
 */
function _stattic_admission_serving_for_space(string $privateRoot, string $spaceId): ?array
{
    $empty = ['space_id' => $spaceId, 'admission' => []];
    if (!_stattic_id_valid($spaceId)) {
        return $empty;
    }
    $spaceRead = _sf_pointer_read(
        'admission-space:' . $spaceId,
        _stattic_space_root($privateRoot, $spaceId) . '/space.json',
    );
    if ($spaceRead['kind'] === 'absent') {
        return $empty;
    }
    if ($spaceRead['kind'] !== 'present' || !is_array($spaceRead['value'] ?? null)) {
        return null;
    }
    require_once __DIR__ . '/artifacts.php';
    $read = _stattic_v4_read_overlay($privateRoot, $spaceId, $spaceRead['value']);
    if ($read['kind'] !== 'present') {
        return null;
    }
    if (is_array($read['overlay']['admission'] ?? null)) {
        $empty['admission'] = $read['overlay']['admission'];
    }
    return $empty;
}

// Inspect one legacy windowed JSON counter file under a nonblocking SHARED lock
// (`LOCK_SH | LOCK_NB`) on the `.generation` pointer so concurrent new-engine
// readers never contend with each other, while an active old-engine writer
// holding `LOCK_EX` during truncate+rewrite is detected immediately without
// waiting and tying up FPM workers. Stale legacy files past the bridge window
// are unlinked under a nonblocking exclusive check so subsequent requests take
// the zero-lock absent fast path.
function _stattic_admission_legacy_counter_count(string $basePath, int $now): ?int
{
    $pointerPath = $basePath . '.generation';
    if (!file_exists($pointerPath) && !is_link($pointerPath)) {
        return 0;
    }
    if (!is_file($pointerPath)) {
        return null;
    }
    $pointerHandle = _stattic_lock_try_shared($pointerPath);
    if ($pointerHandle === 'missing') {
        return 0;
    }
    if (!is_resource($pointerHandle)) {
        return null;
    }
    $shouldUnlink = false;
    $countPathToUnlink = null;
    try {
        rewind($pointerHandle);
        $pointerRaw = stream_get_contents($pointerHandle);
        $pointerMtime = @filemtime($pointerPath);
        $stalePointer = is_int($pointerMtime)
            && $pointerMtime > 0
            && $pointerMtime < $now - STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS;
        if (!is_string($pointerRaw) || trim($pointerRaw) === '') {
            if ($stalePointer) {
                $shouldUnlink = true;
                return 0;
            }
            return null;
        }
        $pointer = json_decode($pointerRaw, true);
        $generation = is_array($pointer) && is_string($pointer['generation'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/', $pointer['generation']) === 1
            ? $pointer['generation']
            : null;
        if ($generation === null) {
            if ($stalePointer) {
                $shouldUnlink = true;
                return 0;
            }
            return null;
        }
        $countPath = $basePath . '.' . $generation;
        if (!is_file($countPath)) {
            if ($stalePointer) {
                $shouldUnlink = true;
                return 0;
            }
            return null;
        }
        $countRaw = @file_get_contents($countPath);
        $decoded = is_string($countRaw) && $countRaw !== '' ? json_decode($countRaw, true) : null;
        if (!is_array($decoded) || !is_int($decoded['count'] ?? null) || $decoded['count'] < 0) {
            $countMtime = @filemtime($countPath);
            $staleCount = is_int($countMtime)
                && $countMtime > 0
                && $countMtime < $now - STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS;
            if ($stalePointer || $staleCount) {
                $shouldUnlink = true;
                $countPathToUnlink = $countPath;
                return 0;
            }
            return null;
        }
        $updatedAt = is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
        if ($updatedAt <= 0 || $updatedAt < $now - STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS) {
            $shouldUnlink = true;
            $countPathToUnlink = $countPath;
            return 0;
        }
        return $decoded['count'];
    } finally {
        _stattic_lock_release_slot($pointerHandle);
        if ($shouldUnlink) {
            _stattic_admission_legacy_unlink_stale($pointerPath, $countPathToUnlink, $now);
        }
    }
}

// Best-effort cleanup of stale legacy counter files under a nonblocking
// exclusive lock so we never remove a pointer an old-engine process just
// refreshed.
function _stattic_admission_legacy_unlink_stale(string $pointerPath, ?string $countPath, int $now): void
{
    if (!is_file($pointerPath)) {
        return;
    }
    $exclusive = _stattic_lock_try_existing_exclusive($pointerPath);
    if (!is_resource($exclusive)) {
        return;
    }
    try {
        clearstatcache(true, $pointerPath);
        $lockedStat = fstat($exclusive);
        $pathStat = @stat($pointerPath);
        if (
            !is_array($lockedStat)
            || !is_array($pathStat)
            || ($lockedStat['ino'] ?? null) !== ($pathStat['ino'] ?? null)
            || ($lockedStat['dev'] ?? null) !== ($pathStat['dev'] ?? null)
        ) {
            return;
        }
        $pointerMtime = is_int($lockedStat['mtime'] ?? null) ? $lockedStat['mtime'] : 0;
        $stillStale = $pointerMtime > 0
            && $pointerMtime < $now - STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS;
        if ($countPath !== null && is_file($countPath)) {
            $countRaw = @file_get_contents($countPath);
            $decoded = is_string($countRaw) && $countRaw !== '' ? json_decode($countRaw, true) : null;
            $updatedAt = is_array($decoded) && is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
            if ($updatedAt >= $now - STATTIC_ADMISSION_LEGACY_BRIDGE_SECONDS) {
                return;
            }
            @unlink($countPath);
            $stillStale = true;
        }
        if ($stillStale) {
            @unlink($pointerPath);
        }
    } finally {
        _stattic_lock_release_slot($exclusive);
    }
}

// Count fresh in-flight requests held by pre-cutover engine workers so forward
// chroot cutover accounts for old-engine holders cleanly. In the old engine,
// every blocking request incremented `<spaceId>.json` (and Worker dispatches
// also incremented `<spaceId>.functions-dispatch.json`), so max() counts every
// distinct in-flight holder without double-counting dispatches. Returns `null`
// if a present legacy counter could not be locked or decoded.
function _stattic_admission_legacy_inflight(string $privateRoot, string $spaceId): ?int
{
    $prefix = $privateRoot . '/runtime/admission/' . _stattic_admission_sanitize_key($spaceId);
    $now = time();
    $general = _stattic_admission_legacy_counter_count($prefix . '.json', $now);
    if ($general === null) {
        return null;
    }
    $dispatch = _stattic_admission_legacy_counter_count($prefix . '.functions-dispatch.json', $now);
    if ($dispatch === null) {
        return null;
    }
    return max($general, $dispatch);
}

/**
 * Claim one slot from a fixed kernel-managed flock pool, or return immediately
 * with `'busy'` (all slots contended) or `'unavailable'` (lock directory or
 * slot files failed due to storage I/O). Also accounts for any active holders
 * on higher-index slot files left over from before a limit reduction so a
 * shrink cannot over-admit while those holders drain.
 *
 * @return array{status: 'acquired', release: callable}|array{status: 'busy'|'unavailable'}
 */
function _stattic_admission_slot_acquire(string $dir, int $limit): array
{
    if ($limit <= 0) {
        return ['status' => 'busy'];
    }
    _stattic_runtime_assert_private_path($dir);
    if (!is_dir($dir) && !_stattic_runtime_mkdir_soft($dir)) {
        return ['status' => 'unavailable'];
    }
    $entries = _stattic_runtime_directory_entries($dir);
    if ($entries === null) {
        return ['status' => 'unavailable'];
    }
    $overflowPaths = [];
    foreach ($entries as $slotPath) {
        if (preg_match('/\/slot-([0-9]+)\.lock$/', $slotPath, $match) !== 1) {
            continue;
        }
        if ((int) $match[1] >= $limit) {
            $overflowPaths[] = $slotPath;
        }
    }
    // Fast path: no higher-index slot files exist from before a limit shrink.
    if ($overflowPaths === []) {
        for ($slot = 0; $slot < $limit; $slot++) {
            $result = _stattic_lock_try_slot($dir . '/slot-' . $slot . '.lock');
            if ($result === 'error') {
                return ['status' => 'unavailable'];
            }
            if (is_resource($result)) {
                $released = false;
                return [
                    'status' => 'acquired',
                    'release' => static function () use ($result, &$released): void {
                        if ($released) {
                            return;
                        }
                        $released = true;
                        _stattic_lock_release_slot($result);
                    },
                ];
            }
        }
        return ['status' => 'busy'];
    }

    // Shrink/drain path: higher-index slot files exist. Claim the first free
    // slot in [0, $limit), count every other held slot across BOTH [0, $limit)
    // and overflow indices, and release the candidate if total held > $limit.
    $sawError = false;
    $candidate = null;
    $heldOthers = 0;
    for ($slot = 0; $slot < $limit; $slot++) {
        $result = _stattic_lock_try_slot($dir . '/slot-' . $slot . '.lock');
        if (is_resource($result)) {
            if ($candidate === null) {
                $candidate = $result;
            } else {
                _stattic_lock_release_slot($result);
            }
        } elseif ($result === 'busy') {
            $heldOthers++;
        } else {
            $sawError = true;
        }
    }
    if ($candidate === null) {
        return ['status' => $sawError ? 'unavailable' : 'busy'];
    }
    foreach ($overflowPaths as $slotPath) {
        $probe = _stattic_lock_try_slot($slotPath);
        if (is_resource($probe)) {
            _stattic_lock_release_slot($probe);
        } elseif ($probe === 'busy') {
            $heldOthers++;
        } else {
            $sawError = true;
        }
    }
    if ($sawError || 1 + $heldOthers > $limit) {
        _stattic_lock_release_slot($candidate);
        return ['status' => $sawError ? 'unavailable' : 'busy'];
    }
    $released = false;
    return [
        'status' => 'acquired',
        'release' => static function () use ($candidate, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            _stattic_lock_release_slot($candidate);
        },
    ];
}

function _stattic_admission_record_shed(string $privateRoot, string $spaceId, int $limit, string $reason): void
{
    if (random_int(1, 10) !== 1) {
        return;
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'admission_shed',
        'space_id' => $spaceId,
        'limit' => $limit,
        'reason' => $reason,
    ], false);
}

function _stattic_admission_acquire_blocking_lane(
    string $privateRoot,
    array $serving,
    string $reason,
    string $code,
    string $message = 'Too Many Requests',
): void {
    if (!empty($GLOBALS['SPACEFAST_BLOCKING_ADMISSION_ACQUIRED'])) {
        return;
    }
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    if ($spaceId === '') {
        return;
    }
    $limit = _stattic_admission_blocking_limit($serving);
    $legacyInflight = _stattic_admission_legacy_inflight($privateRoot, $spaceId);
    if ($legacyInflight === null) {
        require_once __DIR__ . '/errors.php';
        _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    $effectiveLimit = max(0, $limit - $legacyInflight);
    $acquired = _stattic_admission_slot_acquire(
        _stattic_admission_slot_dir($privateRoot, 'blocking', $spaceId),
        $effectiveLimit,
    );
    if ($acquired['status'] === 'unavailable') {
        require_once __DIR__ . '/errors.php';
        _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    if ($acquired['status'] !== 'acquired') {
        require_once __DIR__ . '/errors.php';
        _stattic_admission_record_shed($privateRoot, $spaceId, $limit, $reason);
        _stattic_render_admission_shed(STATTIC_ADMISSION_RETRY_AFTER_SECONDS, $code, $message);
    }
    $GLOBALS['SPACEFAST_BLOCKING_ADMISSION_ACQUIRED'] = true;
    register_shutdown_function($acquired['release']);
}

/**
 * Take one of the box's shared blocking slots for a Worker dispatch, or answer
 * 429 with Retry-After. Never waits: a dispatch that queued here would hold the
 * FPM worker its own callbacks need.
 */
function _stattic_admission_acquire_functions_dispatch(string $privateRoot, array $serving): void
{
    _stattic_admission_acquire_blocking_lane(
        $privateRoot,
        $serving,
        'functions_dispatch',
        'functions_dispatch_busy',
        'This Space is answering as many Worker requests as it can. Try again in a moment.',
    );
}

/**
 * Take one of the box's shared blocking slots before a synchronous upstream
 * connector call (up to 30 s) so connector bursts cannot starve Worker
 * callbacks on the same box.
 */
function _stattic_admission_acquire_connector(string $privateRoot, array $serving): void
{
    _stattic_admission_acquire_blocking_lane(
        $privateRoot,
        $serving,
        'connectors',
        'rate_limited',
    );
}

/**
 * Bound concurrent publish uploads with both the per-space ingest cap and the
 * box's shared blocking pool so uploads plus active Worker dispatches still
 * leave PHP-FPM workers free for callbacks and live traffic.
 */
function _stattic_admission_acquire_ingest(string $privateRoot, string $spaceId, int $limit): void
{
    if ($spaceId === '') {
        return;
    }
    $serving = _stattic_admission_serving_for_space($privateRoot, $spaceId);
    if ($serving === null) {
        require_once __DIR__ . '/errors.php';
        _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    $blockingLimit = _stattic_admission_blocking_limit($serving);
    $legacyInflight = _stattic_admission_legacy_inflight($privateRoot, $spaceId);
    if ($legacyInflight === null) {
        require_once __DIR__ . '/errors.php';
        _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    $effectiveBlockingLimit = max(0, $blockingLimit - $legacyInflight);
    $ingestLimit = max(0, min(max(1, $limit), $effectiveBlockingLimit));
    $ingest = _stattic_admission_slot_acquire(
        _stattic_admission_slot_dir($privateRoot, 'ingest', $spaceId),
        $ingestLimit,
    );
    if ($ingest['status'] === 'unavailable') {
        require_once __DIR__ . '/errors.php';
        _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    if ($ingest['status'] !== 'acquired') {
        require_once __DIR__ . '/errors.php';
        _stattic_admission_record_shed($privateRoot, $spaceId, $blockingLimit, 'ingest');
        _stattic_render_admission_shed(STATTIC_ADMISSION_RETRY_AFTER_SECONDS, 'ingest_admission_exceeded');
    }

    $blocking = _stattic_admission_slot_acquire(
        _stattic_admission_slot_dir($privateRoot, 'blocking', $spaceId),
        $effectiveBlockingLimit,
    );
    if ($blocking['status'] !== 'acquired') {
        ($ingest['release'])();
        require_once __DIR__ . '/errors.php';
        if ($blocking['status'] === 'unavailable') {
            _stattic_render_runtime_unavailable('admission_storage_unavailable', STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
        }
        _stattic_admission_record_shed($privateRoot, $spaceId, $blockingLimit, 'ingest');
        _stattic_render_admission_shed(STATTIC_ADMISSION_RETRY_AFTER_SECONDS, 'ingest_admission_exceeded');
    }

    $releaseIngest = $ingest['release'];
    $releaseBlocking = $blocking['release'];
    register_shutdown_function(static function () use ($releaseBlocking, $releaseIngest): void {
        $releaseBlocking();
        $releaseIngest();
    });
}
