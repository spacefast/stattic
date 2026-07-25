<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';

const STATTIC_ADMISSION_DEFAULT_LIMIT = 6;
const STATTIC_ADMISSION_RETRY_AFTER_SECONDS = 2;
// Crash-recovery self-heal window ONLY — not the accounting lifetime (I-8).
// apcu_add/apcu_inc set the key's TTL once, at creation, and apcu_inc never
// refreshes it; a legitimately in-flight uncacheable request (or the tier
// fetch in-flight cap, which shares this same primitive) that holds its slot
// longer than this window has its key silently purged mid-request, so a
// fresh request re-creates the key at 0 and the per-space cap is bypassed
// while the original holder's FPM slot is still occupied (confirmed finding
// F4). Sized to request-timeout scale (minutes) so it can't expire under a
// genuinely in-flight holder; configurable for tests via the same
// _stattic_config_int seam every other engine timeout uses.
//
// Known residual (apcu backend): the window is anchored at generation
// creation and never refreshed (apcu has no atomic inc-and-touch), so its
// age is shared by every holder — one admitted near the end of the window is
// forgotten at rollover, allowing up to `limit` extra admissions for the
// life of those holders, once per window at worst. Accepted crash-recovery
// trade-off (it predates the generation scheme); what generation scoping
// guarantees is that a forgotten holder's release can never decrement the
// replacement generation.
const STATTIC_ADMISSION_STALE_SECONDS_DEFAULT = 120;

function _stattic_admission_stale_seconds(): int
{
    return _stattic_config_int('SPACEFAST_ADMISSION_STALE_SECONDS', STATTIC_ADMISSION_STALE_SECONDS_DEFAULT);
}

function _stattic_admission_configured_limit(array $serving): int
{
    $limit = _stattic_config_int('SPACEFAST_UNCACHEABLE_CONC_PER_SPACE', STATTIC_ADMISSION_DEFAULT_LIMIT);
    $admission = is_array($serving['admission'] ?? null) ? $serving['admission'] : [];
    $override = $admission['concurrency'] ?? null;
    if (is_int($override) || (is_string($override) && preg_match('/^[0-9]+$/', $override) === 1)) {
        $limit = (int) $override;
    }
    return max(1, $limit);
}

// Backend picked ONCE per process (function-static), so a request never mixes
// an apcu acquire with a file release or vice versa. APCu state is per-FPM-pool
// and resets on pool restart/reload — acceptable for admission: the worst case
// is brief over-admission right after a restart while counters rebuild, never a
// lost release (a fresh pool has no holders to release). Feature-detect via
// apcu_enabled(), not the apc.enabled ini flag: under CLI / `php -S` the apcu
// functions exist and the ini flag reads on, but the cache is dead without
// apc.enable_cli — apcu_enabled() reports the truth for the current SAPI, so
// tests keep full coverage through the file lane instead of a per-request
// apcu-miss fallback.
function _stattic_admission_backend(): string
{
    static $backend = null;
    if ($backend !== null) {
        return $backend;
    }

    $forced = strtolower(_stattic_config_value('SPACEFAST_ADMISSION_COUNTER_BACKEND'));
    if ($forced === 'file') {
        $backend = 'file';
        return $backend;
    }
    if ($forced === 'apcu') {
        $backend = 'apcu';
        return $backend;
    }
    $backend = function_exists('apcu_inc') && function_exists('apcu_dec') && function_exists('apcu_enabled') && apcu_enabled()
        ? 'apcu'
        : 'file';
    return $backend;
}

// Shared apcu-key / file-path sanitizer: every counter key derived from
// caller-controlled input (space id, bucket id) runs through this same
// allowlist so it's safe as both an apcu key and a filesystem path segment.
function _stattic_admission_sanitize_key(string $value): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
}

function _stattic_admission_key(string $spaceId): string
{
    return 'spacefast:adm:' . _stattic_admission_sanitize_key($spaceId);
}

function _stattic_admission_fallback_path(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/runtime/admission/' . _stattic_admission_sanitize_key($spaceId) . '.json';
}

function _stattic_admission_journal_fallback_once(string $privateRoot): void
{
    // Throttle stat first so the steady-state cost is one filemtime; the mkdir
    // only runs on the very first call (whose touch inside the throttle fails
    // while the directory is still missing).
    $marker = $privateRoot . '/runtime/admission/file-fallback-journaled';
    if (!_spacefast_marker_throttle($marker, PHP_INT_MAX)) {
        return;
    }
    _stattic_runtime_mkdir(dirname($marker));
    if (!@touch($marker)) {
        return; // storage unwritable: stay silent instead of journaling per request.
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'admission_counter_fallback',
        'backend' => 'file',
    ]);
}

// Generic apcu counter acquire: a TTL'd generation selects its own count key,
// so a killed worker's slot self-heals without letting an old release touch a
// replacement generation. Returns null (not false) when apcu itself is
// unavailable/failed THIS call, so callers fall back to the file backend
// instead of treating an apcu hiccup as a real over-limit rejection.
function _stattic_admission_apcu_counter_acquire(string $key, int $limit, int $windowSeconds): callable|false|null
{
    $generationKey = $key . ':generation';
    @apcu_add($generationKey, bin2hex(random_bytes(16)), $windowSeconds);
    $generation = @apcu_fetch($generationKey, $generationFound);
    if (!$generationFound || !is_string($generation)) {
        return null;
    }

    $countKey = $key . ':' . $generation;
    @apcu_add($countKey, 0, $windowSeconds);
    $count = @apcu_inc($countKey, 1, $ok, $windowSeconds);
    if (!$ok || !is_int($count)) {
        return null;
    }
    if ($count > $limit) {
        @apcu_dec($countKey, 1);
        return false;
    }
    return static function () use ($countKey): void {
        // An expired generation gets a new count key, so a late release can
        // only touch the generation that admitted it.
        if (!apcu_exists($countKey)) {
            return;
        }
        $value = @apcu_dec($countKey, 1, $ok);
        if ($ok && is_int($value) && $value < 0) {
            @apcu_cas($countKey, $value, 0);
        }
    };
}

// Generic flock+JSON file counter acquire (the apcu-unavailable fallback
// backend): a pointer file selects a generation-scoped count file.
function _stattic_admission_file_counter_acquire(string $path, int $limit, int $staleSeconds): callable|false
{
    _stattic_runtime_mkdir(dirname($path));
    _stattic_runtime_assert_private_path($path);
    $pointerPath = $path . '.generation';
    _stattic_runtime_assert_private_path($pointerPath);
    $pointerHandle = @fopen($pointerPath, 'c+');
    if (!is_resource($pointerHandle)) {
        return false;
    }
    if (!flock($pointerHandle, LOCK_EX)) {
        fclose($pointerHandle);
        return false;
    }

    $pointerRaw = stream_get_contents($pointerHandle);
    $pointer = is_string($pointerRaw) && $pointerRaw !== '' ? json_decode($pointerRaw, true) : null;
    $generation = is_array($pointer)
        && is_string($pointer['generation'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/', $pointer['generation']) === 1
            ? $pointer['generation']
            : null;
    $initializing = $generation === null;

    $decoded = null;
    if (!$initializing) {
        $countPath = $path . '.' . $generation;
        _stattic_runtime_assert_private_path($countPath);
        $raw = is_file($countPath) ? @file_get_contents($countPath) : false;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    }

    $updatedAt = is_array($decoded) && is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
    $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count']) : 0;
    if ($initializing || $updatedAt < time() - $staleSeconds) {
        $count = 0;
        $generation = bin2hex(random_bytes(16));
        $initializing = true;
    }

    $admitted = $count < $limit;
    if ($admitted) {
        $count++;
        $updatedAt = time();
    }

    if ($initializing || $admitted) {
        $countPath = $path . '.' . $generation;
        _stattic_runtime_assert_private_path($countPath);
        $countHandle = @fopen($countPath, 'c+');
        if (!is_resource($countHandle) || !flock($countHandle, LOCK_EX)) {
            if (is_resource($countHandle)) {
                fclose($countHandle);
            }
            flock($pointerHandle, LOCK_UN);
            fclose($pointerHandle);
            return false;
        }
        ftruncate($countHandle, 0);
        rewind($countHandle);
        fwrite($countHandle, json_encode(['count' => $count, 'updated_at' => $updatedAt], JSON_UNESCAPED_SLASHES) . "\n");
        fflush($countHandle);
        flock($countHandle, LOCK_UN);
        fclose($countHandle);

        if ($initializing) {
            ftruncate($pointerHandle, 0);
            rewind($pointerHandle);
            fwrite($pointerHandle, json_encode(['generation' => $generation], JSON_UNESCAPED_SLASHES) . "\n");
            fflush($pointerHandle);

            // Reap superseded artifacts under the pointer lock: every prior
            // generation's count file — including crash-orphaned ones whose
            // pointer publish or unlink never completed.
            foreach (glob($path . '.*') ?: [] as $stalePath) {
                $suffix = substr($stalePath, strlen($path));
                if (preg_match('/^\.[a-f0-9]{32}$/', $suffix) === 1 && $suffix !== '.' . $generation) {
                    @unlink($stalePath);
                }
            }
            @unlink($path); // pre-generation counter file; inert, reaped once on rotation.
        }
    }
    flock($pointerHandle, LOCK_UN);
    fclose($pointerHandle);

    if (!$admitted) {
        return false;
    }

    return static function () use ($path, $pointerPath, $generation): void {
        $pointerHandle = @fopen($pointerPath, 'c+');
        if (!is_resource($pointerHandle) || !flock($pointerHandle, LOCK_EX)) {
            if (is_resource($pointerHandle)) {
                fclose($pointerHandle);
            }
            return;
        }
        $pointerRaw = stream_get_contents($pointerHandle);
        $pointer = is_string($pointerRaw) && $pointerRaw !== '' ? json_decode($pointerRaw, true) : null;
        $storedGeneration = is_array($pointer) && is_string($pointer['generation'] ?? null)
            ? $pointer['generation']
            : null;
        if ($storedGeneration === null || !hash_equals($generation, $storedGeneration)) {
            flock($pointerHandle, LOCK_UN);
            fclose($pointerHandle);
            return;
        }

        $countPath = $path . '.' . $generation;
        $countHandle = @fopen($countPath, 'c+');
        if (!is_resource($countHandle) || !flock($countHandle, LOCK_EX)) {
            if (is_resource($countHandle)) {
                fclose($countHandle);
            }
            flock($pointerHandle, LOCK_UN);
            fclose($pointerHandle);
            return;
        }
        $raw = stream_get_contents($countHandle);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count'] - 1) : 0;
        ftruncate($countHandle, 0);
        rewind($countHandle);
        fwrite($countHandle, json_encode(['count' => $count, 'updated_at' => time()], JSON_UNESCAPED_SLASHES) . "\n");
        fflush($countHandle);
        flock($countHandle, LOCK_UN);
        fclose($countHandle);
        flock($pointerHandle, LOCK_UN);
        fclose($pointerHandle);
    };
}

// Single acquire entry point shared by the per-space uncacheable-concurrency
// gate AND the tier fetch in-flight cap (runtime/tier.php): picks apcu vs.
// file per _stattic_admission_backend() (itself honoring the
// SPACEFAST_ADMISSION_COUNTER_BACKEND force-override), journals the
// file-fallback marker once, and falls back to the file backend on any apcu
// miss.
function _stattic_admission_counter_acquire(
    string $privateRoot,
    string $key,
    string $path,
    int $limit,
    int $staleSeconds
): callable|false {
    if (_stattic_admission_backend() === 'apcu') {
        $release = _stattic_admission_apcu_counter_acquire($key, $limit, $staleSeconds);
        if ($release !== null) {
            return $release;
        }
    }
    _stattic_admission_journal_fallback_once($privateRoot);
    return _stattic_admission_file_counter_acquire($path, $limit, $staleSeconds);
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
    ]);
}

function _stattic_admission_acquire_or_shed(string $privateRoot, array $serving, string $reason): void
{
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    if ($spaceId === '') {
        return;
    }
    $limit = _stattic_admission_configured_limit($serving);
    $release = _stattic_admission_counter_acquire(
        $privateRoot,
        _stattic_admission_key($spaceId),
        _stattic_admission_fallback_path($privateRoot, $spaceId),
        $limit,
        _stattic_admission_stale_seconds(),
    );
    if ($release === false) {
        require_once __DIR__ . '/errors.php';
        _stattic_admission_record_shed($privateRoot, $spaceId, $limit, $reason);
        _stattic_render_admission_shed(STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }
    if (is_callable($release)) {
        register_shutdown_function($release);
    }
}

function _stattic_admission_test_hold_if_requested(): void
{
    if (_stattic_config_value('SPACEFAST_RUNTIME_TEST_ADMISSION_HOLD') !== '1') {
        return;
    }
    $raw = $_SERVER['HTTP_X_SPACEFAST_TEST_ADMISSION_HOLD_US'] ?? '';
    if (!is_string($raw) || preg_match('/^[0-9]{1,7}$/', $raw) !== 1) {
        return;
    }
    usleep(min(5_000_000, (int) $raw));
}
