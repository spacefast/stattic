<?php
declare(strict_types=1);

// The artifact rule (contracts §1), in one place.
//
//  - Every mutable pointer is JSON, read with one file_get_contents + APCu.
//  - Every PHP artifact is immutable and content-addressed: `<base>-<h16>.php`
//    where h16 is the first 16 hex of sha256 over the FINAL bytes. Never written
//    in place, never reused under a different content.
//
// Deliberately dependency-free — runtime/serve-fast.php loads this and nothing
// else from shared/ on the hot path, so it must not pull context.php in.

const SF_POINTER_APCU_PREFIX = 'sf:p:';

// DELIBERATE DEVIATION from the contracts' "TTL 0" wording. The writer's
// apcu_delete is not sufficient on its own: a reader that has already read the
// OLD file but not yet stored it races the swap (rename -> apcu_delete), and
// with TTL 0 its store lands AFTER the delete and pins the superseded pointer
// for the life of the worker. A short freshness window bounds that race
// without a lock.
const SF_POINTER_FRESH_SECONDS = 5;

// How long a stale cached value may keep answering while re-reads of an
// EXISTING file fail (the last-known-good bound). Past it the entry expires
// and a still-failing pointer becomes `unavailable`, never `absent` — bounded
// staleness is the ceiling a revocation/takedown can be behind by.
const SF_POINTER_LKG_CAP_SECONDS = 30;

function _sf_apcu_available(): bool
{
    static $available = null;
    if ($available === null) {
        $available = function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && (!function_exists('apcu_enabled') || apcu_enabled());
    }
    return $available;
}

function _sf_apcu_get(string $key): mixed
{
    if (!_sf_apcu_available()) {
        return null;
    }
    $ok = false;
    $value = apcu_fetch($key, $ok);
    return $ok === true ? $value : null;
}

function _sf_apcu_put(string $key, mixed $value, int $ttlSeconds = 0): void
{
    if (_sf_apcu_available()) {
        apcu_store($key, $value, $ttlSeconds);
    }
}

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
        $entries = scandir($parent);
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

// Failure logging for the runtime read paths: one line per second per kind
// per pool (apcu_add is the atomic gate; without APCu, log unconditionally).
// Plain error_log on purpose — the `sf-log/1 ` marker is the TENANT log lane
// and these are platform-internal.
function _sf_runtime_log_gate_key(string $kind): string
{
    return 'sf:log:' . $kind;
}

function _sf_runtime_log_read_failure(string $kind, string $path, ?string $identity = null): void
{
    if (_sf_apcu_available() && function_exists('apcu_add') && !apcu_add(_sf_runtime_log_gate_key($kind), 1, 1)) {
        return;
    }
    $error = error_get_last();
    error_log('spacefast runtime ' . $kind . ' path=' . $path
        . ($identity !== null ? ' pointer=' . $identity : '')
        . ($error !== null ? ' msg=' . $error['message'] : ''));
}

// $name is the pointer identity, not the path: `routes` or `space:<spaceId>`.
// The path hash scopes the APCu key to the site, so a shared-pool topology can
// never cross-talk pointers between private roots.
function _sf_pointer_apcu_key(string $name, string $path): string
{
    return SF_POINTER_APCU_PREFIX . $name . ':' . substr(hash('sha256', $path), 0, 8);
}

/**
 * One read attempt: read the file, decode it, cache and return the present or
 * verified-absent outcome. Null means this attempt failed — an unreadable file
 * whose absence could not be proven, or bytes that exist but are not a pointer
 * document (corruption, not absence — the absence probe never runs for it).
 *
 * @return array{kind: 'present'|'absent', value: ?array}|null
 */
function _sf_pointer_attempt(string $key, string $path, int $now): ?array
{
    error_clear_last();
    $raw = file_get_contents($path);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            _sf_apcu_put($key, ['value' => $decoded, 'fresh_until' => $now + SF_POINTER_FRESH_SECONDS], SF_POINTER_LKG_CAP_SECONDS);
            return ['kind' => 'present', 'value' => $decoded];
        }
        // Bytes exist but are not a pointer document: corruption, not absence.
        return null;
    }
    clearstatcache(true, $path);
    if (_sf_path_verifiably_absent($path)) {
        _sf_apcu_put($key, ['value' => null, 'fresh_until' => $now + SF_POINTER_FRESH_SECONDS], SF_POINTER_LKG_CAP_SECONDS);
        return ['kind' => 'absent', 'value' => null];
    }
    return null;
}

/**
 * Absence is a fact this function verifies (ENOENT-confirmed); a failed read
 * of an EXISTING pointer is never a state claim. Kinds:
 *
 *  - `present`     value is the pointer (possibly last-known-good while a
 *                  re-read fails, stale by at most SF_POINTER_LKG_CAP_SECONDS)
 *  - `absent`      the file verifiably does not exist
 *  - `unavailable` the file exists but could not be read, and no usable
 *                  last-known-good survives — the caller must answer 5xx
 *                  without claiming anything about deployment or access state
 *
 * @return array{kind: 'present'|'absent'|'unavailable', value: ?array}
 */
function _sf_pointer_read(string $name, string $path): array
{
    $key = _sf_pointer_apcu_key($name, $path);
    $now = time();
    $entry = _sf_apcu_get($key);
    $cached = is_array($entry) && array_key_exists('value', $entry) && is_int($entry['fresh_until'] ?? null)
        ? $entry
        : null;
    if ($cached !== null && $now < $cached['fresh_until']) {
        return $cached['value'] === null
            ? ['kind' => 'absent', 'value' => null]
            : ['kind' => 'present', 'value' => $cached['value']];
    }

    for ($attempt = 0; $attempt < 2; $attempt += 1) {
        if ($attempt === 1) {
            _sf_runtime_log_read_failure('pointer_read_failed', $path, $name);
            if ($cached !== null && is_array($cached['value'])) {
                // Serve last-known-good without retrying: with the answer in
                // hand the retry rate stays proportional to cold workers, not
                // to traffic.
                return ['kind' => 'present', 'value' => $cached['value']];
            }
            // One immediate retry after clearstatcache: it covers the
            // atomic-swap visibility race (which is instantaneous), and nothing
            // a delay would cover — sustained failure lands on `unavailable`
            // and the next request.
            clearstatcache(true, $path);
        }
        $read = _sf_pointer_attempt($key, $path, $now);
        if ($read !== null) {
            return $read;
        }
    }
    _sf_runtime_log_read_failure('pointer_unavailable', $path, $name);
    // Never cached: the next request re-reads rather than inheriting failure.
    return ['kind' => 'unavailable', 'value' => null];
}

// Derived, not passed: a pointer's APCu identity is a property of where it
// lives, so a writer cannot swap the file and forget the key.
function _sf_pointer_name_for_path(string $path): ?string
{
    $normalized = str_replace('\\', '/', $path);
    if (str_ends_with($normalized, '/routes/current.json')) {
        return 'routes';
    }
    if (str_ends_with($normalized, '/runtime/storage-read-key.json')) {
        return 'storage-read-key';
    }
    if (str_ends_with($normalized, '/runtime/cron-key.json')) {
        return 'cron-key';
    }
    if (preg_match('#/spaces/([^/]+)/space\.json$#', $normalized, $matches) === 1) {
        return 'space:' . $matches[1];
    }
    return null;
}

// Atomic JSON write, no APCu identity: the sidecars of a pointer go through here
// so the whole family shares one primitive.
function _sf_json_write(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('pointer payload is not encodable: ' . $path);
    }
    _sf_artifact_mkdir(dirname($path));
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $body = $encoded . "\n";
    if (file_put_contents($tmp, $body) !== strlen($body) || !rename($tmp, $path)) {
        unlink($tmp);
        throw new RuntimeException('pointer write failed: ' . $path);
    }
}

// tmp + rename + apcu_delete, in that order: a reader either sees the whole old
// pointer or the whole new one, never a torn one. The delete is what makes the
// new pointer visible immediately; the reader's freshness window is what bounds
// the one case the delete cannot cover (a read already in flight over this swap).
function _sf_pointer_swap(string $path, array $value): void
{
    _sf_json_write($path, $value);
    $name = _sf_pointer_name_for_path($path);
    if ($name !== null && _sf_apcu_available() && function_exists('apcu_delete')) {
        apcu_delete(_sf_pointer_apcu_key($name, $path));
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
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $written = file_put_contents($tmp, $code, LOCK_EX);
    if ($written === false || $written !== strlen($code) || !rename($tmp, $path)) {
        unlink($tmp);
        throw new RuntimeException('artifact write failed: ' . $path);
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
    return $name;
}

function _sf_artifact_mkdir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('artifact directory could not be created: ' . $dir);
    }
}
