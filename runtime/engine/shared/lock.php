<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';

// THE advisory-lock primitive: every flock in the engine goes through it, do not
// open-code fopen+flock at call sites. Callers choose a mode and a failure
// disposition; the deadline, the re-entrancy registry and the private-path
// assertion are the same for all of them.
const STATTIC_LOCK_WAIT = 'wait';
const STATTIC_LOCK_TRY = 'try';

const STATTIC_LOCK_DEADLINE_MS = 10000;
const STATTIC_LOCK_RETRY_MIN_US = 1000;
const STATTIC_LOCK_RETRY_MAX_US = 100000;

// flock() is per open file description, so a second handle on a lock this
// process already holds conflicts with itself: a nested acquire would block
// until its own deadline. Every acquire is registered here so a nested one
// reuses the open handle and only the outermost release unlocks it. Statics
// reset per request under FPM and the CLI server.
function &_stattic_lock_registry(): array
{
    static $held = [];
    return $held;
}

function &_stattic_lock_registry_paths(): array
{
    static $paths = [];
    return $paths;
}

function _stattic_lock_flock($handle, int $operation, bool $wait): bool
{
    if (flock($handle, $operation | LOCK_NB)) {
        return true;
    }
    if (!$wait) {
        return false;
    }
    $deadline = microtime(true) + (STATTIC_LOCK_DEADLINE_MS / 1000);
    $backoffUs = STATTIC_LOCK_RETRY_MIN_US;
    while (microtime(true) < $deadline) {
        usleep($backoffUs);
        if (flock($handle, $operation | LOCK_NB)) {
            return true;
        }
        $backoffUs = min($backoffUs * 2, STATTIC_LOCK_RETRY_MAX_US);
    }
    return false;
}

/**
 * @param  string $mode STATTIC_LOCK_WAIT | STATTIC_LOCK_TRY
 * @return resource|false
 */
function _stattic_lock_acquire(string $path, string $mode = STATTIC_LOCK_WAIT)
{
    _stattic_runtime_assert_private_path($path);
    $wait = $mode !== STATTIC_LOCK_TRY;
    $held = &_stattic_lock_registry();
    if (isset($held[$path])) {
        $held[$path]['depth']++;
        return $held[$path]['handle'];
    }

    $handle = fopen($path, 'c+');
    if (!is_resource($handle)) {
        return false;
    }
    if (!_stattic_lock_flock($handle, LOCK_EX, $wait)) {
        fclose($handle);
        return false;
    }
    $held[$path] = ['handle' => $handle, 'depth' => 1];
    $paths = &_stattic_lock_registry_paths();
    $paths[get_resource_id($handle)] = $path;
    return $handle;
}

/** @param resource|false|null $handle */
function _stattic_lock_release($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    $paths = &_stattic_lock_registry_paths();
    $id = get_resource_id($handle);
    $path = $paths[$id] ?? null;
    $held = &_stattic_lock_registry();
    if (!is_string($path) || !isset($held[$path])) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return;
    }
    if (--$held[$path]['depth'] > 0) {
        return;
    }
    unset($paths[$id], $held[$path]);
    flock($handle, LOCK_UN);
    fclose($handle);
}

// A response sent with exit from inside a critical callback never unwinds that
// callback's finally block. Deferred shutdown work can take seconds, so release
// every completed request mutation before its post-response queue starts.
function _stattic_lock_release_all(): void
{
    $held = &_stattic_lock_registry();
    foreach ($held as $entry) {
        $handle = $entry['handle'] ?? null;
        if (!is_resource($handle)) {
            continue;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $held = [];
    $paths = &_stattic_lock_registry_paths();
    $paths = [];
}

/** @param ?callable $unavailable disposition for a lock this process could not take. */
function _stattic_lock_with(
    string $path,
    string $mode,
    ?callable $unavailable,
    callable $critical
): mixed {
    $handle = _stattic_lock_acquire($path, $mode);
    if ($handle === false) {
        return $unavailable === null ? null : $unavailable();
    }
    try {
        return $critical($handle);
    } finally {
        _stattic_lock_release($handle);
    }
}

// One striped-lock shape for every unbounded key space: a fixed 256 lock files
// beside the records they guard.
function _stattic_lock_stripe_path(string $directory, string $key, string $prefix = ''): string
{
    return $directory . '/' . $prefix . substr(hash('sha256', $key), 0, 2) . '.lock';
}

// The one lock every lane that writes inside spaces/{spaceId}/ contends on:
// management routes, the visitor-facing /storage lane, and the local blob GC
// scan. They differ only in mode and disposition.
function _stattic_space_write_lock_with(
    string $privateRoot,
    string $spaceId,
    string $mode,
    ?callable $unavailable,
    callable $critical
): mixed {
    $path = _stattic_space_write_lock_path($privateRoot, $spaceId);
    // The blob GC takes this lock once per blob, so the O(all blobs) scan must
    // not pay a mkdir per acquire; an unwritable root is still this caller's
    // graceful outcome, never a 500.
    if (!is_file($path) && !_stattic_runtime_mkdir_soft(dirname($path))) {
        return $unavailable === null ? null : $unavailable();
    }
    return _stattic_lock_with($path, $mode, $unavailable, $critical);
}
