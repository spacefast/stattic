<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/storage.php';

const STATTIC_ADMISSION_DEFAULT_LIMIT = 6;
const STATTIC_ADMISSION_RETRY_AFTER_SECONDS = 2;
// Crash-recovery self-heal window and maximum generation lifetime. A generation
// rotates after this bound even while surviving traffic mutates its count, so
// crashed workers cannot leak capacity forever. Must stay at request-timeout
// scale: a holder that legitimately outlives the generation releases only into
// its retired generation and cannot decrement the successor.
// Generation scoping guarantees a rotated-away holder's release cannot
// decrement its successor.
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

// Counter keys carry caller-controlled input (space id, bucket id): this
// allowlist makes them safe as a filesystem path segment.
function _stattic_admission_sanitize_key(string $value): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
}

function _stattic_admission_counter_path(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/runtime/admission/' . _stattic_admission_sanitize_key($spaceId) . '.json';
}

// THE windowed counter — flock-serialized files, shared by every worker that
// serves the site regardless of pool. Every mutation serializes on the generation
// pointer's lock, so concurrent increments cannot lose each other. $decide gets
// the in-window count and returns the delta to persist. $requireGeneration
// binds a caller to the window it joined — a rotated-away generation makes the
// update a no-op instead of landing on its successor.
function _stattic_admission_counter_update(
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
    $generation = _stattic_admission_counter_generation($pointerRaw);
    if ($requireGeneration !== null && ($generation === null || !hash_equals($requireGeneration, $generation))) {
        _stattic_lock_release($pointerHandle);
        return null;
    }

    $updatedAt = 0;
    $startedAt = 0;
    $count = 0;
    if ($generation !== null) {
        $countPath = $path . '.' . $generation;
        _stattic_runtime_assert_private_path($countPath);
        $raw = is_file($countPath) ? file_get_contents($countPath) : false;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $updatedAt = is_array($decoded) && is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
        // Generation files created before `started_at` use their last update as
        // the migration baseline, then gain an explicit lifetime on next write.
        $startedAt = is_array($decoded) && is_int($decoded['started_at'] ?? null)
            ? $decoded['started_at']
            : $updatedAt;
        $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count']) : 0;
    }

    $now = time();
    $rotating = $requireGeneration === null && ($generation === null || $startedAt < $now - $staleSeconds);
    if ($rotating) {
        $count = 0;
        $generation = bin2hex(random_bytes(16));
        $startedAt = $now;
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
            'started_at' => $startedAt,
            'updated_at' => $delta === 0 ? $updatedAt : $now,
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
                    unlink($stalePath);
                }
            }
            unlink($path);
        }
    }
    _stattic_lock_release($pointerHandle);

    return ['count' => $next, 'generation' => (string) $generation];
}

function _stattic_admission_counter_generation(mixed $pointerRaw): ?string
{
    $pointer = is_string($pointerRaw) && $pointerRaw !== '' ? json_decode($pointerRaw, true) : null;
    return is_array($pointer)
        && is_string($pointer['generation'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/', $pointer['generation']) === 1
            ? $pointer['generation']
            : null;
}

function _stattic_admission_counter_acquire(string $path, int $limit, int $staleSeconds): callable|false
{
    $admitted = false;
    $result = _stattic_admission_counter_update(
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
        _stattic_admission_counter_update(
            $path,
            $staleSeconds,
            static fn (int $count): int => -1,
            $generation,
        );
    };
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
        _stattic_admission_counter_path($privateRoot, $spaceId),
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
