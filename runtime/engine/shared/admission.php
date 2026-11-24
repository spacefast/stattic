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

function _stattic_admission_counter_generation(mixed $pointerRaw): ?string
{
    $pointer = is_string($pointerRaw) && $pointerRaw !== '' ? json_decode($pointerRaw, true) : null;
    return is_array($pointer)
        && is_string($pointer['generation'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/', $pointer['generation']) === 1
            ? $pointer['generation']
            : null;
}

// THE windowed concurrency counter: flock-serialized files, shared by every
// worker that serves the site regardless of pool. Acquire, generation rotation,
// and release-descriptor binding happen under ONE pointer lock so there is no
// intermediate acquired-but-unbound state and no compensating decrement path.
function _stattic_admission_counter_acquire(string $path, int $limit, int $staleSeconds): callable|false
{
    _stattic_runtime_mkdir(dirname($path));
    _stattic_runtime_assert_private_path($path);
    $pointerPath = $path . '.generation';
    $pointerHandle = _stattic_lock_acquire($pointerPath, STATTIC_LOCK_WAIT);
    if ($pointerHandle === false) {
        return false;
    }

    $countHandle = false;
    $releasePointer = false;
    $releaseCounter = false;
    $rotating = false;
    $countPath = '';

    try {
        rewind($pointerHandle);
        $generation = _stattic_admission_counter_generation(stream_get_contents($pointerHandle));

        $startedAt = 0;
        $count = 0;
        if ($generation !== null) {
            $existingCountPath = $path . '.' . $generation;
            _stattic_runtime_assert_private_path($existingCountPath);
            $raw = is_file($existingCountPath) ? file_get_contents($existingCountPath) : false;
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $updatedAt = is_array($decoded) && is_int($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : 0;
            // Generation files created before `started_at` use their last update
            // as the migration baseline, then gain an explicit lifetime on next write.
            $startedAt = is_array($decoded) && is_int($decoded['started_at'] ?? null)
                ? $decoded['started_at']
                : $updatedAt;
            $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count']) : 0;
        }

        $now = time();
        $rotating = $generation === null || $startedAt < $now - $staleSeconds;
        if ($rotating) {
            $count = 0;
            $generation = bin2hex(random_bytes(16));
            $startedAt = $now;
        }

        if ($count >= $limit) {
            return false;
        }

        $countPath = $path . '.' . $generation;
        _stattic_runtime_assert_private_path($countPath);
        $countHandle = _stattic_lock_acquire($countPath, STATTIC_LOCK_WAIT);
        if ($countHandle === false) {
            return false;
        }

        // Bind the release descriptors BEFORE committing the charge and before a
        // PHP handler tightens open_basedir. Release later uses only these FDs,
        // never a path outside the handler's jail.
        $releasePointer = fopen($pointerPath, 'r+');
        $releaseCounter = fopen($countPath, 'r+');
        if (!is_resource($releasePointer) || !is_resource($releaseCounter)) {
            if ($rotating && is_file($countPath)) {
                unlink($countPath);
            }
            return false;
        }

        ftruncate($countHandle, 0);
        rewind($countHandle);
        fwrite($countHandle, json_encode([
            'count' => $count + 1,
            'started_at' => $startedAt,
            'updated_at' => $now,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        fflush($countHandle);
        _stattic_lock_release($countHandle);
        $countHandle = false;

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
            if (is_file($path)) {
                unlink($path);
            }
        }

        $boundPointer = $releasePointer;
        $boundCounter = $releaseCounter;
        $boundGeneration = (string) $generation;
        $releasePointer = false;
        $releaseCounter = false;

        $released = false;
        return static function () use ($boundPointer, $boundCounter, $boundGeneration, &$released): void {
            if ($released) return;
            $released = true;
            try {
                if (!_stattic_lock_flock($boundPointer, LOCK_EX, true)) {
                    error_log('spacefast admission release lock unavailable');
                    return;
                }
                rewind($boundPointer);
                if (_stattic_admission_counter_generation(stream_get_contents($boundPointer)) !== $boundGeneration) {
                    return;
                }
                rewind($boundCounter);
                $value = json_decode((string) stream_get_contents($boundCounter), true);
                if (!is_array($value) || !is_int($value['count'] ?? null)) {
                    error_log('spacefast admission release counter unavailable');
                    return;
                }
                $value['count'] = max(0, $value['count'] - 1);
                $value['updated_at'] = time();
                $payload = json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
                rewind($boundCounter);
                if (!ftruncate($boundCounter, 0) || fwrite($boundCounter, $payload) !== strlen($payload) || !fflush($boundCounter)) {
                    error_log('spacefast admission release write failed');
                }
            } finally {
                _stattic_lock_release($boundPointer);
                fclose($boundCounter);
            }
        };
    } finally {
        if (is_resource($releasePointer)) fclose($releasePointer);
        if (is_resource($releaseCounter)) fclose($releaseCounter);
        if (is_resource($countHandle)) _stattic_lock_release($countHandle);
        _stattic_lock_release($pointerHandle);
    }
}

// Concurrent Worker dispatches one Space may hold open. Each holds a PHP-FPM
// worker for up to the dispatch timeout, often streaming, while the Worker's
// own env.SPACE, D1, purge and log callbacks re-enter the same pool. Below the
// uncacheable limit so dispatches alone can never take every worker the
// callbacks need: otherwise a burst of streaming requests waits on callbacks
// that wait on the requests.
const STATTIC_FUNCTIONS_DISPATCH_DEFAULT_LIMIT = 3;

function _stattic_admission_functions_dispatch_limit(array $serving): int
{
    $configured = _stattic_admission_configured_limit($serving);
    $baseUncacheable = max(1, _stattic_config_int('SPACEFAST_UNCACHEABLE_CONC_PER_SPACE', STATTIC_ADMISSION_DEFAULT_LIMIT));
    $defaultDispatch = _stattic_config_int('SPACEFAST_FUNCTIONS_DISPATCH_CONC_PER_SPACE', STATTIC_FUNCTIONS_DISPATCH_DEFAULT_LIMIT);
    $reserve = max(1, $baseUncacheable - min($defaultDispatch, max(1, $baseUncacheable - 1)));
    $limit = $configured > $baseUncacheable
        ? max($defaultDispatch, $configured - $reserve)
        : $defaultDispatch;
    // Never as many as the Space's whole uncacheable allowance.
    return max(1, min($limit, $configured - 1));
}

function _stattic_admission_functions_dispatch_path(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/runtime/admission/' . _stattic_admission_sanitize_key($spaceId) . '.functions-dispatch.json';
}

/**
 * Take one of the Space's Worker dispatch slots, or answer 503 with
 * Retry-After. Never waits: a dispatch that queued here would hold an FPM
 * worker while it waited, which is the exhaustion this cap prevents.
 */
function _stattic_admission_acquire_functions_dispatch(string $privateRoot, array $serving): void
{
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    if ($spaceId === '') {
        return;
    }
    $limit = _stattic_admission_functions_dispatch_limit($serving);
    $release = _stattic_admission_counter_acquire(
        _stattic_admission_functions_dispatch_path($privateRoot, $spaceId),
        $limit,
        _stattic_admission_stale_seconds(),
    );
    if ($release === false) {
        require_once __DIR__ . '/errors.php';
        _stattic_admission_record_shed($privateRoot, $spaceId, $limit, 'functions_dispatch');
        _stattic_serve_page('tier-unavailable', [
            'status' => 503,
            'headers' => [
                'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
                'Retry-After' => (string) STATTIC_ADMISSION_RETRY_AFTER_SECONDS,
            ],
            'message' => 'This Space is answering as many Worker requests as it can. Try again in a moment.',
            'code' => 'functions_dispatch_busy',
            'customizable' => true,
        ]);
        exit;
    }
    register_shutdown_function($release);
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

// One slot per request, whichever lane charges it first: the once-guard lives
// here instead of at each caller.
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
