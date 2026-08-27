<?php
declare(strict_types=1);

// The two private-storage primitives the engine and the WordPress content
// kernel BOTH need: a containment-guarded recursive delete, and a text pointer
// written so a reader can never see a half-written one.
//
// Why they live in their own file rather than in shared/storage.php: the kernel
// runs inside WordPress and answers failures by throwing Spacefast_Content_Error
// for the entrypoint to turn into a problem document. shared/storage.php's
// spellings answer with _stattic_problem_response(), which emits an HTTP
// response and exits — fatal inside a WordPress request. So this file has no
// requires at all, and every function here REPORTS (bool/null) instead of
// deciding what a failure means. shared/storage.php keeps its loud engine-lane
// wrappers on top; the kernel throws its own error.
//
// Nothing here is a substitute for the engine's asserts on the ENGINE lane:
// _stattic_runtime_assert_private_path() still runs there first.

// Every private tree on a site lives under this suffix of the install root, so
// containment is decidable from the path text alone — no config, no globals.
const STATTIC_PRIVATE_TREE_MARKER = '/.stattic/storage';

/** Lexical resolution: no filesystem access, so a path that does not exist yet still resolves. */
function _stattic_private_tree_normalize(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || $path[0] !== '/') {
        return null;
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

/**
 * True when `$path` is strictly inside a private storage root. The root itself
 * is deliberately NOT inside itself: no caller may delete or overwrite it.
 */
function _stattic_private_tree_contains(string $path): bool
{
    $normalized = _stattic_private_tree_normalize($path);
    if ($normalized === null) {
        return false;
    }
    $index = strpos($normalized, STATTIC_PRIVATE_TREE_MARKER);
    if ($index === false) {
        return false;
    }
    $root = substr($normalized, 0, $index + strlen(STATTIC_PRIVATE_TREE_MARKER));
    return str_starts_with($normalized, $root . '/');
}

/**
 * Recursive delete, refusing anything outside private storage — per entry, so a
 * symlinked directory inside the tree is unlinked rather than descended into.
 *
 * Returns false on a refusal or an incomplete removal; the caller decides what
 * that means. Absent is success: removal is idempotent.
 */
function _stattic_private_tree_remove(string $path): bool
{
    if (!_stattic_private_tree_contains($path)) {
        return false;
    }
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (is_link($path) || !is_dir($path)) {
        return unlink($path);
    }
    $entries = scandir($path);
    if ($entries === false) {
        return false;
    }
    $removed = true;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removed = _stattic_private_tree_remove($path . '/' . $entry) && $removed;
    }
    return rmdir($path) && $removed;
}

/** A short text pointer's value, trimmed; null when absent, unreadable or over `$maxBytes`. */
function _stattic_private_tree_read_pointer(string $path, int $maxBytes = 256): ?string
{
    if (!_stattic_private_tree_contains($path)) {
        return null;
    }
    $raw = @file_get_contents($path, false, null, 0, $maxBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        return null;
    }
    $value = trim($raw);
    return $value === '' ? null : $value;
}

/**
 * Publish a text pointer: write a uniquely named sibling, then rename over the
 * target, so a concurrent reader sees the old value or the new one and never a
 * partial line.
 *
 * The read-back is not paranoia. `rename()` reports success from the directory
 * entry alone, and this is the pointer a whole release tree hangs off: a
 * truncated or short write that still renamed would silently strand every
 * reader on an unresolvable target until the next publish. On a mismatch the
 * pointer is removed rather than left in place — an unresolvable pointer and no
 * pointer land a reader in the same fallback, and only one of them is honest
 * about it — and the caller is told the publish did not happen.
 */
function _stattic_private_tree_write_pointer(string $path, string $value): bool
{
    $value = trim($value);
    if ($value === '' || !_stattic_private_tree_contains($path)) {
        return false;
    }
    $contents = $value . "\n";
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    $written = @file_put_contents($temporary, $contents, LOCK_EX);
    if ($written !== strlen($contents) || !@chmod($temporary, 0640)) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    if (_stattic_private_tree_read_pointer($path, strlen($contents)) === $value) {
        return true;
    }
    @unlink($path);
    return false;
}
