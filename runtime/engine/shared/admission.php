<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/storage.php';

const STATTIC_ADMISSION_DEFAULT_LIMIT = 6;
const STATTIC_ADMISSION_RETRY_AFTER_SECONDS = 2;
// Crash-recovery self-heal window, NOT the accounting lifetime: apcu sets the
// TTL once at creation and never refreshes it, so a holder outliving this window
// has its key purged mid-request and the per-space cap is bypassed. Must stay at
// request-timeout scale. Residual (apcu): the window is anchored at generation
// creation and shared by every holder, so one admitted near rollover is
// forgotten — up to `limit` extra admissions once per window. Generation scoping
// still guarantees a forgotten holder's release cannot decrement its successor.
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

// Picked once per process so a request never mixes an apcu acquire with a file
// release. Feature-detect via apcu_enabled(), not the apc.enabled ini flag:
// under CLI / `php -S` the functions exist and the flag reads on while the
// cache is dead without apc.enable_cli.
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

// Counter keys carry caller-controlled input (space id, bucket id): this
// allowlist makes them safe as both an apcu key and a filesystem path segment.
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
    // Throttle stat first so the steady-state cost is one filemtime.
    $marker = $privateRoot . '/runtime/admission/file-fallback-journaled';
    if (!_stattic_marker_throttle($marker, PHP_INT_MAX)) {
        return;
    }
    _stattic_runtime_mkdir(dirname($marker));
    if (!@touch($marker)) {
        return;
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'admission_counter_fallback',
        'backend' => 'file',
    ]);
}

// A TTL'd generation selects its own count key, so a killed worker's slot
// self-heals without letting an old release touch a replacement generation.
function _stattic_admission_apcu_generation(string $key, int $windowSeconds): ?string
{
    $generationKey = $key . ':generation';
    @apcu_add($generationKey, bin2hex(random_bytes(16)), $windowSeconds);
    $generation = @apcu_fetch($generationKey, $found);
    return $found && is_string($generation) ? $generation : null;
}

// Null (not a count) means apcu itself failed: callers must fall back rather
// than treat it as a real reading.
function _stattic_admission_apcu_counter_increment(string $key, int $windowSeconds): ?array
{
    $generation = _stattic_admission_apcu_generation($key, $windowSeconds);
    if ($generation === null) {
        return null;
    }
    $countKey = $key . ':' . $generation;
    @apcu_add($countKey, 0, $windowSeconds);
    $count = @apcu_inc($countKey, 1, $ok, $windowSeconds);
    return $ok && is_int($count) ? ['count' => $count, 'count_key' => $countKey] : null;
}

function _stattic_admission_apcu_counter_acquire(string $key, int $limit, int $windowSeconds): callable|false|null
{
    $incremented = _stattic_admission_apcu_counter_increment($key, $windowSeconds);
    if ($incremented === null) {
        return null;
    }
    $countKey = $incremented['count_key'];
    if ($incremented['count'] > $limit) {
        @apcu_dec($countKey, 1);
        return false;
    }
    return static function () use ($countKey): void {
        // A late release may only touch the generation that admitted it.
        if (!apcu_exists($countKey)) {
            return;
        }
        $value = @apcu_dec($countKey, 1, $ok);
        if ($ok && is_int($value) && $value < 0) {
            @apcu_cas($countKey, $value, 0);
        }
    };
}

// THE file-backed windowed counter. Every mutation serializes on the generation
// pointer's lock, so concurrent increments cannot lose each other. $decide gets
// the in-window count and returns the delta to persist. $requireGeneration
// binds a caller to the window it joined — a rotated-away generation makes the
// update a no-op instead of landing on its successor.
function _stattic_admission_file_counter_update(
    string $path,
    int $staleSeconds,
    callable $decide,
    ?string $requireGeneration = null
): ?array {
    _stattic_runtime_mkdir(dirname($path));
    _stattic_runtime_assert_private_path($path);
    $pointerPath = $path . '.generation';
    $pointerHandle = _stattic_lock_acquire($pointerPath, STATTIC_LOCK_WAIT);
    if ($pointerHandle === false) {
        return null;
    }

    rewind($pointerHandle);
    $pointerRaw = stream_get_contents($pointerHandle);
    $generation = _stattic_admission_file_counter_generation($pointerRaw);
    if ($requireGeneration !== null && ($generation === null || !hash_equals($requireGeneration, $generation))) {
        _stattic_lock_release($pointerHandle);
        return null;
    }

    $updatedAt = 0;
    $count = 0;
    if ($generation !== null) {
        $countPath = $path . '.' . $generation;
        _stattic_runtime_assert_private_path($countPath);
        $raw = is_file($countPath) ? @file_get_contents($countPath) : false;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $updatedAt = is_array($decoded) && is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
        $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count']) : 0;
    }

    $rotating = $requireGeneration === null && ($generation === null || $updatedAt < time() - $staleSeconds);
    if ($rotating) {
        $count = 0;
        $generation = bin2hex(random_bytes(16));
    }

    $delta = (int) $decide($count);
    $next = max(0, $count + $delta);
    if ($rotating || $delta !== 0) {
        $countHandle = _stattic_lock_acquire($path . '.' . $generation, STATTIC_LOCK_WAIT);
        if ($countHandle === false) {
            _stattic_lock_release($pointerHandle);
            return null;
        }
        ftruncate($countHandle, 0);
        rewind($countHandle);
        fwrite($countHandle, json_encode([
            'count' => $next,
            'updated_at' => $delta === 0 ? $updatedAt : time(),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        fflush($countHandle);
        _stattic_lock_release($countHandle);

        if ($rotating) {
            ftruncate($pointerHandle, 0);
            rewind($pointerHandle);
            fwrite($pointerHandle, json_encode(['generation' => $generation], JSON_UNESCAPED_SLASHES) . "\n");
            fflush($pointerHandle);

            // Under the pointer lock: reaps crash-orphaned generations whose
            // pointer publish or unlink never completed.
            foreach (glob($path . '.*') ?: [] as $stalePath) {
                $suffix = substr($stalePath, strlen($path));
                if (preg_match('/^\.[a-f0-9]{32}$/', $suffix) === 1 && $suffix !== '.' . $generation) {
                    @unlink($stalePath);
                }
            }
            @unlink($path);
        }
    }
    _stattic_lock_release($pointerHandle);

    return ['count' => $next, 'generation' => (string) $generation];
}

function _stattic_admission_file_counter_generation(mixed $pointerRaw): ?string
{
    $pointer = is_string($pointerRaw) && $pointerRaw !== '' ? json_decode($pointerRaw, true) : null;
    return is_array($pointer)
        && is_string($pointer['generation'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/', $pointer['generation']) === 1
            ? $pointer['generation']
            : null;
}

function _stattic_admission_file_counter_acquire(string $path, int $limit, int $staleSeconds): callable|false
{
    $admitted = false;
    $result = _stattic_admission_file_counter_update(
        $path,
        $staleSeconds,
        static function (int $count) use ($limit, &$admitted): int {
            $admitted = $count < $limit;
            return $admitted ? 1 : 0;
        },
    );
    if ($result === null || !$admitted) {
        return false;
    }
    $generation = $result['generation'];
    return static function () use ($path, $staleSeconds, $generation): void {
        _stattic_admission_file_counter_update(
            $path,
            $staleSeconds,
            static fn (int $count): int => -1,
            $generation,
        );
    };
}

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
        // Ingest gets its own stable code (D25); every other lane keeps the
        // generic visitor-facing rate_limited.
        _stattic_render_admission_shed(
            STATTIC_ADMISSION_RETRY_AFTER_SECONDS,
            $reason === 'ingest' ? 'ingest_admission_exceeded' : 'rate_limited'
        );
    }
    if (is_callable($release)) {
        register_shutdown_function($release);
    }
}

// One slot per request, whichever lane charges it first. Callers reach this
// from wherever the "this request needs a slot" verdict is known, so the
// once-guard lives here instead of at each of them.
function _stattic_admission_acquire_once(string $privateRoot, array $serving, string $lane): void
{
    if (!empty($GLOBALS['SPACEFAST_ADMISSION_ACQUIRED'])) {
        return;
    }
    _stattic_admission_acquire_or_shed($privateRoot, $serving, $lane);
    $GLOBALS['SPACEFAST_ADMISSION_ACQUIRED'] = true;
    _stattic_admission_test_hold_if_requested();
}

// The access lane exempts OPTIONS: a preflight never runs identity work.
function _stattic_admission_acquire_access_lane(string $privateRoot, array $serving): void
{
    if (_stattic_runtime_request_method() === 'OPTIONS') {
        return;
    }
    _stattic_admission_acquire_once($privateRoot, $serving, 'access_rule');
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
