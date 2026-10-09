<?php
declare(strict_types=1);

// The artifact rule (contracts §1), in one place.
//
//  - Every mutable pointer is JSON, read fresh from disk every time. Small on
//    purpose: at pointer sizes a direct read costs single-digit microseconds,
//    which no cache at any scope beats once its own risks are priced in.
//  - Every PHP artifact is immutable and content-addressed: `<base>-<h16>.php`
//    where h16 is the first 16 hex of sha256 over the FINAL bytes. Never written
//    in place, never reused under a different content.
//  - Every derived cache is a write-once `<?php return [...]` file served from
//    opcache SHM (`_sf_php_cache_read`/`_sf_php_cache_write`). The fleet runs
//    opcache.validate_timestamps=Off, so a PHP file's content may NEVER change
//    under a path opcache has seen. Immutability is what makes staleness
//    structurally impossible, not any invalidation protocol.
//
// Shared mutable state is files (flock where it counts); shared derived state
// is opcached PHP. Runtime read failures always log immediately.
//
// Deliberately dependency-free: the serve lane loads this and nothing else from
// shared/ on the hot path, so it must not pull context.php in.

// Proves absence by successfully listing the nearest readable ancestor and
// observing the first missing path component. `is_file()` cannot do this: false
// also means stat/traversal failed or the path exists with the wrong type.
function _sf_path_verifiably_absent(string $path): bool
{
    $cursor = rtrim(str_replace('\\', '/', $path), '/');
    while ($cursor !== '' && $cursor !== '/') {
        $parent = dirname($cursor);
        if (!is_dir($parent)) {
            if (file_exists($parent) || is_link($parent)) {
                return false;
            }
            $cursor = $parent;
            continue;
        }
        // Unsorted: the listing only ever feeds the in_array below, and this
        // proof runs on the visitor hot path.
        $entries = scandir($parent, SCANDIR_SORT_NONE);
        if (is_array($entries)) {
            return !in_array(basename($cursor), $entries, true);
        }
        if ($parent === $cursor) {
            return false;
        }
        $cursor = $parent;
    }
    return false;
}

// A concurrent request can make a filesystem call warn even though it got the
// outcome its caller wanted: mkdir loses a create race ("File exists"), or a
// read or unlink loses a delete race ("No such file or directory"). PHP has no
// errno for these, so the call runs here with its warning held back. The caller
// checks the post-condition, and if that fails it passes the held warning to
// _sf_fs_warn. A lost race is then silent, and a real failure still reaches the
// error log and error_get_last().
//
// @return array{0: mixed, 1: ?string} the call's result and its held warning
function _sf_fs_attempt(callable $call): array
{
    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return true;
    }, E_WARNING | E_NOTICE);
    try {
        $result = $call();
    } finally {
        restore_error_handler();
    }
    return [$result, $warning];
}

function _sf_fs_warn(?string $warning): void
{
    if ($warning !== null) {
        trigger_error($warning, E_USER_WARNING);
    }
}

/** @return resource|false */
function _sf_fopen_exclusive_racing(string $path): mixed
{
    [$handle, $warning] = _sf_fs_attempt(static fn () => fopen($path, 'x'));
    if (is_resource($handle)) {
        return $handle;
    }
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) {
        _sf_fs_warn($warning);
    }
    return false;
}

// The create step for a directory that another request may be creating at the
// same moment. Callers check is_dir first; reaching here with the directory
// already present IS the lost race, and that counts as success. Anything that
// leaves no directory behind (permission denied, a file in the way, quota) still
// returns false with mkdir's own warning raised unchanged: the replay guard
// classifies that message by its operation and errno text.
function _sf_mkdir_racing(string $dir, int $mode): bool
{
    [$created, $warning] = _sf_fs_attempt(static fn (): bool => mkdir($dir, $mode, true));
    clearstatcache(true, $dir);
    if ($created === true || is_dir($dir)) {
        return true;
    }
    _sf_fs_warn($warning);
    return false;
}

// Idempotent delete: a file that a concurrent request deleted first is gone,
// which is what the caller asked for. Only a file that is still there (or whose
// absence cannot be proven) returns false with unlink's warning raised.
function _sf_unlink_racing(string $path): bool
{
    [$unlinked, $warning] = _sf_fs_attempt(static fn (): bool => unlink($path));
    if ($unlinked === true) {
        return true;
    }
    clearstatcache(true, $path);
    if (_sf_path_verifiably_absent($path)) {
        return true;
    }
    _sf_fs_warn($warning);
    return false;
}

// A whole-file read that may lose a delete race. Returns the bytes, null when
// the file verifiably does not exist (the read lost a race or it never
// existed), or false when it exists but could not be read. A false return has
// already raised the read's warning, so error_get_last() describes it.
function _sf_read_racing(string $path): string|null|false
{
    [$raw, $warning] = _sf_fs_attempt(static fn (): string|false => file_get_contents($path));
    if (is_string($raw)) {
        _sf_fs_warn($warning);
        return $warning === null ? $raw : false;
    }
    clearstatcache(true, $path);
    if (_sf_path_verifiably_absent($path)) {
        return null;
    }
    _sf_fs_warn($warning);
    return false;
}

// Failure logging for the runtime read paths. Plain error_log on purpose: the
// `sf-log/1 ` marker is the TENANT log lane and these are platform-internal.
function _sf_runtime_log_read_failure(string $kind, string $path, ?string $identity = null): void
{
    $error = error_get_last();
    error_log('spacefast runtime ' . $kind . ' path=' . $path
        . ($identity !== null ? ' pointer=' . $identity : '')
        . ($error !== null ? ' msg=' . $error['message'] : ''));
}

/**
 * One read attempt. Null means it failed: an unreadable file whose absence
 * could not be proven, or bytes that exist but are not a pointer document.
 * That second case is corruption, not absence, so the absence probe never runs
 * for it.
 *
 * @return array{kind: 'present'|'absent', value: ?array}|null
 */
function _sf_pointer_attempt(string $path): ?array
{
    error_clear_last();
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path) && _sf_path_verifiably_absent($path)) {
        return ['kind' => 'absent', 'value' => null];
    }
    $raw = _sf_read_racing($path);
    if ($raw === null) {
        return ['kind' => 'absent', 'value' => null];
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return ['kind' => 'present', 'value' => $decoded];
        }
    }
    return null;
}

/**
 * Absence is a fact this function verifies (ENOENT-confirmed); a failed read
 * of an EXISTING pointer is never a state claim. Kinds:
 *
 *  - `present`     value is the pointer, read fresh this request
 *  - `absent`      the file verifiably does not exist
 *  - `unavailable` the file exists but could not be read. The caller answers
 *                  5xx without claiming anything about deployment or access
 *                  state
 *
 * Every read comes from disk, no cache at any scope. A swap is visible on the
 * very next read, a takedown is never masked, a swap-then-reread lane sees its
 * own write, and a failure one read observed is inherited by nobody.
 *
 * @return array{kind: 'present'|'absent'|'unavailable', value: ?array}
 */
function _sf_pointer_read(string $name, string $path): array
{
    for ($attempt = 0; $attempt < 2; $attempt += 1) {
        if ($attempt === 1) {
            _sf_runtime_log_read_failure('pointer_read_failed', $path, $name);
            // One immediate retry after clearstatcache covers the atomic-swap
            // visibility race and nothing else. Sustained failure lands on
            // `unavailable` and the next request.
            clearstatcache(true, $path);
        }
        $read = _sf_pointer_attempt($path);
        if ($read !== null) {
            return $read;
        }
    }
    _sf_runtime_log_read_failure('pointer_unavailable', $path, $name);
    return ['kind' => 'unavailable', 'value' => null];
}

// Private writes share a staging root so retention can enumerate interrupted
// writes without walking every version and content-addressed blob directory.
function _sf_atomic_temp_path(string $path): string
{
    $marker = '/.stattic/storage/';
    $offset = strpos($path, $marker);
    if ($offset === false) {
        return $path . '.tmp-' . bin2hex(random_bytes(6));
    }
    $root = substr($path, 0, $offset + strlen($marker)) . 'runtime/atomic-staging';
    _sf_artifact_mkdir($root);
    return $root . '/.tmp-' . bin2hex(random_bytes(6));
}

// THE tmp + rename idiom, so a reader sees the whole old file or the whole new
// one, never a torn one. The directory must exist; the caller owns what a
// failure means. $mode, when given, is applied to the tmp file before it
// becomes visible.
function _sf_atomic_put(string $path, string $bytes, bool $lock, ?int $mode = null): bool
{
    try {
        $tmp = _sf_atomic_temp_path($path);
    } catch (RuntimeException) {
        return false;
    }
    if (
        file_put_contents($tmp, $bytes, $lock ? LOCK_EX : 0) !== strlen($bytes)
        || ($mode !== null && !chmod($tmp, $mode))
        || !rename($tmp, $path)
    ) {
        unlink($tmp);
        return false;
    }
    return true;
}

// THE pointer write. Nothing to invalidate: the next read IS the visibility
// protocol.
function _sf_json_write(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('pointer payload is not encodable: ' . $path);
    }
    _sf_artifact_mkdir(dirname($path));
    if (!_sf_atomic_put($path, $encoded . "\n", false)) {
        throw new RuntimeException('pointer write failed: ' . $path);
    }
}

function _sf_php_artifact_source(array $value): string
{
    return "<?php\nreturn " . var_export($value, true) . ";\n";
}

// Returns the artifact FILE NAME (not the path): callers store that name in the
// pointer. Identical content yields an identical name, which is what makes an
// unchanged shard survive a pointer write untouched (§3).
function _sf_php_artifact_write(string $dir, string $base, string $code): string
{
    $name = $base . '-' . substr(hash('sha256', $code), 0, 16) . '.php';
    $path = $dir . '/' . $name;
    _sf_artifact_mkdir($dir);
    if (is_file($path)) {
        // The bytes already on disk ARE these bytes; touch it so shard GC's mtime
        // grace treats it as live.
        touch($path);
        return $name;
    }
    if (!_sf_atomic_put($path, $code, true)) {
        throw new RuntimeException('artifact write failed: ' . $path);
    }
    return $name;
}

function _sf_artifact_mkdir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!_sf_mkdir_racing($dir, 0775)) {
        throw new RuntimeException('artifact directory could not be created: ' . $dir);
    }
}

// Half the fleet's opcache.max_file_size (1 MiB): a sidecar past the ceiling
// would be re-parsed on every request, slower than the source it derives from,
// so it is refused instead. Same split point as the response tables.
const SF_PHP_CACHE_MAX_BYTES = 524288;

/**
 * THE derived-cache read: a write-once `<?php return [...]` file served from
 * opcache SHM. With validate_timestamps=Off a warm hit costs one is_file stat
 * and zero further syscalls, and the array is shared, not copied. The is_file
 * probe is the whole freshness protocol a write-once file needs: present means
 * final, absent means not built yet or deleted with its owner. That is why a
 * sidecar must live INSIDE the directory whose data it derives from.
 *
 * $expect is the payload's identity (e.g. spaceId/versionId): every pair must
 * match strictly or the read is a miss. A cache is never trusted to be about
 * what its path claims.
 *
 * @param array<string,string> $expect
 */
function _sf_php_cache_read(string $path, array $expect = []): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $loaded = include $path;
    if (!is_array($loaded)) {
        return null;
    }
    foreach ($expect as $key => $value) {
        if (($loaded[$key] ?? null) !== $value) {
            return null;
        }
    }
    return $loaded;
}

/**
 * THE derived-cache write: best-effort and never load-bearing. Any failure
 * means the next reader rebuilds from source, so callers ignore the result
 * except to decide sharding. tmp + rename; a given path's content never
 * changes (the source is immutable), so no invalidation exists anywhere. The
 * temporary paths are never included, so publication needs no invalidation.
 */
function _sf_php_cache_write(string $path, array $value): bool
{
    // Cheap refusal before serializing: var_export spells every node in at
    // least ~16 bytes, so a value past this count can never fit the ceiling.
    // Building a multi-MB source string just to measure it would tax every
    // request that retries an oversized write, and failed writes are never
    // memoized.
    if (count($value, COUNT_RECURSIVE) * 16 > SF_PHP_CACHE_MAX_BYTES) {
        return false;
    }
    $code = _sf_php_artifact_source($value);
    if (strlen($code) > SF_PHP_CACHE_MAX_BYTES) {
        return false;
    }
    try {
        _sf_artifact_mkdir(dirname($path));
        if (!_sf_atomic_put($path, $code, false)) {
            return false;
        }
    } catch (Throwable) {
        return false;
    }
    return true;
}

// OPcache restricts the calling script, not the artifact being invalidated.
// Under FPM it prefix-matches SCRIPT_FILENAME as the web server passed it,
// symlinks unresolved; the CLI compares the resolved script path instead.
function _sf_opcache_api_allowed(): bool
{
    if (!function_exists('opcache_invalidate')) {
        return false;
    }
    $restriction = (string) ini_get('opcache.restrict_api');
    if ($restriction === '') {
        return true;
    }
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (PHP_SAPI === 'cli') {
        $script = (string) realpath($script);
    }
    return $script !== '' && str_starts_with($script, $restriction);
}
