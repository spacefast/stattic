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

// A pointer that does not exist is cached as this sentinel: a publish always
// apcu_delete()s the key, so caching absence cannot outlive the first write.
const SF_POINTER_ABSENT = "\0absent";

// DELIBERATE DEVIATION from the contracts' "TTL 0" wording. The writer's
// apcu_delete is not sufficient on its own: a reader that has already read the
// OLD file but not yet stored it races the swap (rename -> apcu_delete), and
// with TTL 0 its store lands AFTER the delete and pins the superseded pointer
// for the life of the worker. A short TTL bounds that window without a lock.
const SF_POINTER_APCU_TTL_SECONDS = 5;

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

// $name is the pointer identity, not the path: `routes` or `space:<spaceId>`.
function _sf_pointer_read(string $name, string $path): ?array
{
    $key = SF_POINTER_APCU_PREFIX . $name;
    $cached = _sf_apcu_get($key);
    if ($cached === SF_POINTER_ABSENT) {
        return null;
    }
    if (is_array($cached)) {
        return $cached;
    }

    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $value = is_array($decoded) ? $decoded : null;
    _sf_apcu_put($key, $value ?? SF_POINTER_ABSENT, SF_POINTER_APCU_TTL_SECONDS);
    return $value;
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
    if (@file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('pointer write failed: ' . $path);
    }
}

// tmp + rename + apcu_delete, in that order: a reader either sees the whole old
// pointer or the whole new one, never a torn one. The delete is what makes the
// new pointer visible immediately; the reader's short TTL is what bounds the
// one case the delete cannot cover (a read already in flight over this swap).
function _sf_pointer_swap(string $path, array $value): void
{
    _sf_json_write($path, $value);
    $name = _sf_pointer_name_for_path($path);
    if ($name !== null && _sf_apcu_available() && function_exists('apcu_delete')) {
        apcu_delete(SF_POINTER_APCU_PREFIX . $name);
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
        @touch($path);
        return $name;
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $written = @file_put_contents($tmp, $code, LOCK_EX);
    if ($written === false || $written !== strlen($code) || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('artifact write failed: ' . $path);
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return $name;
}

function _sf_artifact_mkdir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('artifact directory could not be created: ' . $dir);
    }
}
