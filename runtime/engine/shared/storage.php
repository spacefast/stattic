<?php
declare(strict_types=1);

require_once __DIR__ . '/streaming.php';
require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/record-store.php';
require_once __DIR__ . '/pointers.php';

function _stattic_json_body(): array
{
    $raw = _stattic_request_body_contents();
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        _stattic_problem_response(400, 'invalid_json', 'Request body must be valid JSON.');
    }
    return $decoded;
}

// ?limit= for a paged management read: the default when absent, 422 outside
// [1, $max].
function _stattic_query_limit(int $default, int $max, string $message): int
{
    $raw = $_GET['limit'] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }
    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1 || (int) $raw > $max) {
        _stattic_problem_response(422, 'validation_error', $message);
    }
    return (int) $raw;
}

// ?cursor= as base64url JSON: null when absent, $reject() when malformed. The
// caller owns its payload schema and its rejection message.
function _stattic_query_cursor_payload(int $maxLength, callable $reject): ?array
{
    $raw = $_GET['cursor'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw) || strlen($raw) > $maxLength || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
        $reject();
    }
    $payload = json_decode(_stattic_base64url_decode($raw), true);
    if (!is_array($payload)) {
        $reject();
    }
    return $payload;
}

function _stattic_query_cursor_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    return _stattic_base64url_encode(is_string($json) ? $json : '{}');
}

function _stattic_runtime_route_not_found(): never
{
    _stattic_problem_response(404, 'runtime_route_not_found', 'Runtime API route not found.');
}

function _stattic_runtime_upload_route_not_found(): never
{
    _stattic_problem_response(404, 'runtime_upload_route_not_found', 'Runtime upload route not found.');
}

function _stattic_runtime_id(string $value, string $field): string
{
    $value = trim($value);
    if (!_stattic_runtime_id_valid($value)) {
        _stattic_problem_response(422, 'invalid_' . $field, $field . ' is invalid: expected 1-128 characters of letters, digits, \'.\', \'_\', or \'-\' (and not dots only).');
    }
    return $value;
}

function _stattic_runtime_id_valid(string $value): bool
{
    return _stattic_id_valid(trim($value));
}

function _stattic_runtime_new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(12));
}

/**
 * The one path normalizer: NFC, no surrounding space, no backslash, no empty or
 * dot segment, no NUL, no leading slash, valid UTF-8. Returns null instead of
 * responding, so fail-closed callers like the blob gate reuse the intake rule
 * without inheriting intake's 422.
 */
function _stattic_runtime_file_path_or_null(string $path): ?string
{
    // A path that is not UTF-8 has no canonical form, and every surface below
    // assumes one.
    if (preg_match('//u', $path) !== 1) {
        return null;
    }
    // NFC is the spec's canonical path form; every path surface means it.
    $path = _stattic_nfc_path($path);
    $original = $path;
    $path = trim($path);
    if ($path !== $original || str_contains($path, '\\') || str_contains($path, '//')) {
        return null;
    }
    $path = ltrim($path, '/');
    if ($path === '' || $path !== $original || str_contains($path, "\0")) {
        return null;
    }
    // Dot segments are rejected, never collapsed: rewriting `a/../b` into `b`
    // would make two spellings of one path, and the catalog is keyed by one.
    $bounded = '/' . $path . '/';
    if (str_contains($bounded, '/../') || str_contains($bounded, '/./')) {
        return null;
    }
    return $path;
}

function _stattic_runtime_file_path(string $path): string
{
    $normalized = _stattic_runtime_file_path_or_null($path);
    if ($normalized === null) {
        _stattic_problem_response(422, 'invalid_file_path', 'File path is invalid.');
    }
    return $normalized;
}

function _stattic_runtime_mkdir(string $path): void
{
    if (!_stattic_runtime_mkdir_soft($path)) {
        _stattic_problem_response(500, 'runtime_mkdir_failed', 'Runtime storage directory could not be created.');
    }
}

// For callers with their own graceful "storage unavailable" outcome: returns
// false instead of a hard 500. A path escape still fails hard.
function _stattic_runtime_mkdir_soft(string $path): bool
{
    _stattic_runtime_assert_private_path($path);
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        return false;
    }
    chmod($path, 0775);
    return true;
}

/**
 * A box secret minted lazily under the site write lock; the loser of a mint
 * race reads the winner's key. Minting is key ROTATION for anyone already
 * holding the secret, so it may only follow a VERIFIED absence: a failed read,
 * or one with a malformed key, returns null and the caller decides what
 * unavailable means.
 *
 * The record is `{key, minted_at}` at `runtime/<name>.json`; the key is $bytes
 * of entropy as lowercase hex. Rotation lanes may re-stamp the record with
 * their own provenance. Only `key` is ever read back.
 */
function _stattic_lazy_minted_secret(string $privateRoot, string $name, int $bytes): ?string
{
    $path = $privateRoot . '/runtime/' . $name . '.json';
    $pattern = '/^[a-f0-9]{' . ($bytes * 2) . '}$/';
    // string = the key; null = verified absent; false = unavailable.
    $read = static function () use ($name, $path, $pattern): string|null|false {
        $record = _sf_pointer_read($name, $path);
        if ($record['kind'] === 'absent') {
            return null;
        }
        $key = is_array($record['value']) ? ($record['value']['key'] ?? null) : null;
        return is_string($key) && preg_match($pattern, $key) === 1 ? $key : false;
    };
    $state = $read();
    if (is_string($state)) {
        return $state;
    }
    if ($state === false || !_stattic_runtime_mkdir_soft($privateRoot . '/runtime')) {
        return null;
    }
    $minted = _stattic_lock_with(
        $privateRoot . '/runtime/write.lock',
        STATTIC_LOCK_WAIT,
        null,
        static function () use ($read, $path, $bytes): ?string {
            $existing = $read();
            if (is_string($existing)) {
                return $existing;
            }
            if ($existing === false) {
                return null;
            }
            $key = bin2hex(random_bytes($bytes));
            _sf_json_write($path, ['key' => $key, 'minted_at' => gmdate('c')]);
            return $key;
        },
    );
    return is_string($minted) ? $minted : null;
}

// Every space root on this box, or null when the space tree could not be
// enumerated this instant. Sweeps MUST abort on null, never treat an unreadable
// tree as "no spaces": a pass that sees zero spaces completes without doing its
// job.
function _stattic_runtime_space_roots(string $privateRoot): ?array
{
    $entries = _stattic_runtime_directory_entries($privateRoot . '/spaces');
    return $entries === null ? null : array_values(array_filter($entries, 'is_dir'));
}

// The admin-lane strict spelling: rebuilds and state scans abort loudly on an
// unenumerable space tree.
function _stattic_runtime_space_roots_strict(string $privateRoot): array
{
    $roots = _stattic_runtime_space_roots($privateRoot);
    if ($roots === null) {
        throw new RuntimeException('runtime document enumeration failed: ' . $privateRoot . '/spaces');
    }
    return $roots;
}

// Full child paths of a directory: [] when the directory verifiably does not
// exist, null when it could not be enumerated this instant.
function _stattic_runtime_directory_entries(string $root): ?array
{
    clearstatcache(true, $root);
    if (!is_dir($root)) {
        return _sf_path_verifiably_absent($root) ? [] : null;
    }
    $entries = scandir($root);
    if (!is_array($entries)) {
        return null;
    }
    $paths = [];
    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $paths[] = $root . '/' . $entry;
        }
    }
    return $paths;
}

// null = the file verifiably does not exist (ENOENT); false = the file exists
// but could not be read or decoded this instant. Destructive and index-write
// callers MUST treat false as "abort", never as "absent". No runtime document
// is the bare JSON literal `false`, so the sentinel is unambiguous.
function _stattic_runtime_read_json(string $path): mixed
{
    if (!is_file($path) && _sf_path_verifiably_absent($path)) {
        return null;
    }
    error_clear_last();
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        clearstatcache(true, $path);
        if (_sf_path_verifiably_absent($path)) {
            return null;
        }
        _sf_runtime_log_read_failure('json_read_failed', $path);
        return false;
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null && trim($raw) !== 'null') {
        _sf_runtime_log_read_failure('json_decode_failed', $path);
        return false;
    }
    return $decoded;
}

// A marker in the future (wall-clock rollback) reads as due: it must never
// suppress a sweep permanently.
function _stattic_marker_due(string $markerPath, int $intervalSeconds, int $now): bool
{
    $last = filemtime($markerPath);
    return $last === false || $last > $now || $now - $last >= $intervalSeconds;
}

function _stattic_marker_stamp(string $markerPath, int $at): void
{
    touch($markerPath, $at);
}

// Concurrent callers racing a stale marker may both run: every throttled job
// must be idempotent.
function _stattic_marker_throttle(string $markerPath, int $intervalSeconds, ?int $now = null): bool
{
    $now ??= time();
    if (!_stattic_marker_due($markerPath, $intervalSeconds, $now)) {
        return false;
    }
    _stattic_marker_stamp($markerPath, $now);
    return true;
}

// ADVANCE_ALWAYS stamps before running. ADVANCE_ON_COMPLETE stamps only when
// $sweep reports a whole pass, at the time that pass FINISHED.
const STATTIC_SWEEP_ADVANCE_ALWAYS = 'always';
const STATTIC_SWEEP_ADVANCE_ON_COMPLETE = 'complete';

function _stattic_sweep_throttled(
    string $markerPath,
    int $intervalSeconds,
    callable $sweep,
    string $advance = STATTIC_SWEEP_ADVANCE_ALWAYS,
    ?int $now = null,
    ?callable $clock = null
): bool {
    $now ??= time();
    if ($advance === STATTIC_SWEEP_ADVANCE_ALWAYS) {
        if (!_stattic_marker_throttle($markerPath, $intervalSeconds, $now)) {
            return false;
        }
        $sweep();
        return true;
    }
    if (!_stattic_marker_due($markerPath, $intervalSeconds, $now)) {
        return false;
    }
    if ($sweep() !== true) {
        return false;
    }
    $clock ??= static fn (): int => time();
    _stattic_marker_stamp($markerPath, max($now, (int) $clock()));
    return true;
}

// Reclaims whole entries, file or tree, under a glob whose mtime is older than
// $maxAgeSeconds. admin/retention.php decides what gets one of these.
function _stattic_reclaim_stale_paths(string $pattern, int $maxAgeSeconds, ?int $now = null): int
{
    $now ??= time();
    $deadline = $now - max(0, $maxAgeSeconds);
    $reclaimed = 0;
    foreach (glob($pattern) ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        $mtime = filemtime($path);
        if ($mtime === false || $mtime > $deadline) {
            continue;
        }
        _stattic_runtime_rm_recursive($path);
        $reclaimed += 1;
    }
    return $reclaimed;
}

// THE atomic private-write primitive: every private-root write goes through it,
// do not open-code the tmp+rename idiom at call sites.
function _stattic_runtime_write_private_string(string $path, string $content): void
{
    _stattic_runtime_mkdir(dirname($path));
    _stattic_runtime_assert_private_path($path);
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $written = file_put_contents($tmp, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        unlink($tmp);
        _stattic_problem_response(500, 'runtime_write_failed', 'Runtime private write failed.');
    }
    if (!rename($tmp, $path)) {
        unlink($tmp);
        _stattic_problem_response(500, 'runtime_write_failed', 'Runtime private write failed.');
    }
}

function _stattic_runtime_write_json_atomic(string $path, array $value): void
{
    _stattic_runtime_write_private_string($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function _stattic_runtime_json_object(array $value): object|array
{
    return $value === [] ? (object) [] : $value;
}

// Fixed-name but write-once: callers write version-directory artifacts at
// finalize, before any reader has included the path, so opcache has never
// compiled it and no invalidation protocol exists. The invalidate is parity
// with `_sf_php_artifact_write`, result ignored.
function _stattic_runtime_write_php_atomic(string $path, array $value): void
{
    _stattic_runtime_write_private_string($path, _sf_php_artifact_source($value));
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
}

// THE operator journal: one append-only NDJSON file under the private root,
// drained forward by the control plane through the events API. Nothing scans it
// backwards, and nothing else is written here. A space's own runtime output
// goes to PHP's error log instead (shared/runtime-log.php), the only stream the
// provider ships off the box. Append through this function, never an open-coded
// file_put_contents(FILE_APPEND).
const STATTIC_RUNTIME_JOURNAL_MAX_BYTES = 8388608; // 8 MiB per generation
const STATTIC_RUNTIME_JOURNAL_READ_CHUNK_BYTES = 262144;

// $blocking = false is the serve-path mode: a contended append is skipped
// rather than making a visitor wait for the lock. Best-effort either way. An
// unwritable root loses the records, it never fails the request.
function _stattic_runtime_append_journal(string $privateRoot, array $entry, bool $blocking = true): void
{
    $entry['created_at'] = gmdate('c');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    $path = $privateRoot . '/runtime/journal.jsonl';
    if (!is_string($line) || !_stattic_runtime_mkdir_soft(dirname($path))) {
        return;
    }
    // Appends lock beside the file, never on the file itself: a roll-aside
    // renames the data file out from under any handle on it, so the lock has to
    // outlive the bytes it guards.
    _stattic_lock_with(
        $privateRoot . '/runtime/journal.lock',
        $blocking ? STATTIC_LOCK_WAIT : STATTIC_LOCK_TRY,
        null,
        static function () use ($privateRoot, $path, $line): void {
            if (!_stattic_runtime_journal_admits($privateRoot, $path)) {
                return;
            }
            file_put_contents($path, $line . "\n", FILE_APPEND);
        },
    );
}

// Under the append lock: a live file at the cap is rolled aside so the append
// lands in a fresh generation at the same path. Rolled-aside generations are
// reclaimed by admin/retention.php, the one retention registry.
function _stattic_runtime_journal_admits(string $privateRoot, string $path): bool
{
    // Without this, appends within one request all read the size the first one
    // saw and roll aside at the cap forever.
    clearstatcache(true, $path);
    if (!is_file($path) || (int) filesize($path) < STATTIC_RUNTIME_JOURNAL_MAX_BYTES) {
        return true;
    }
    $target = $privateRoot . '/runtime/journal-'
        . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.jsonl';
    _stattic_runtime_assert_private_path($target);
    return rename($path, $target) || !is_file($path);
}

// @return list<string> file basenames under runtime/, oldest generation first;
// `journal.jsonl` is always last, even when it does not exist yet.
function _stattic_runtime_journal_files(string $privateRoot): array
{
    $rotated = [];
    foreach (glob($privateRoot . '/runtime/journal-*.jsonl') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        $rotated[] = ['name' => basename($path), 'mtime' => (int) filemtime($path)];
    }
    usort(
        $rotated,
        static fn (array $left, array $right): int => $left['mtime'] <=> $right['mtime']
            ?: strcmp($left['name'], $right['name'])
    );
    $names = array_column($rotated, 'name');
    $names[] = 'journal.jsonl';
    return $names;
}

// Complete lines from $offset, appending decoded records to $entries until it
// holds $max. The returned resume offset is always a line start.
function _stattic_runtime_journal_read_file(string $path, int $offset, int $max, array &$entries): int
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $offset;
    }
    clearstatcache(true, $path);
    $size = (int) filesize($path);
    if ($offset > $size) {
        // Resuming past EOF would wedge the cursor forever; re-read instead.
        fclose($handle);
        return 0;
    }
    if (fseek($handle, $offset) !== 0) {
        fclose($handle);
        return $offset;
    }
    $position = $offset;
    $buffer = '';
    while (count($entries) < $max) {
        $chunk = fread($handle, STATTIC_RUNTIME_JOURNAL_READ_CHUNK_BYTES);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
        while (count($entries) < $max) {
            $newline = strpos($buffer, "\n");
            if ($newline === false) {
                break;
            }
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);
            $position += $newline + 1;
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
    }
    fclose($handle);
    return $position;
}

// The inode a journal generation currently occupies, or 0 when it cannot be
// stat'ed.
function _stattic_runtime_journal_inode(string $privateRoot, string $name): int
{
    $path = $privateRoot . '/runtime/' . $name;
    clearstatcache(true, $path);
    $stat = stat($path);
    return is_array($stat) ? (int) ($stat['ino'] ?? 0) : 0;
}

/**
 * Resolves a persisted cursor onto the CURRENT generation list. A generation's
 * identity is its INODE, never its name (rotation renames `journal.jsonl` and a
 * fresh empty one takes its place); offsets are never compared across files.
 *
 * @param  list<string> $files
 * @return array{0: int, 1: int}  [index into $files, offset to resume at]
 */
function _stattic_runtime_journal_cursor_position(
    string $privateRoot,
    array $files,
    string $file,
    int $offset,
    int $inode
): array {
    if ($file === '' || $files === [] || $inode <= 0) {
        return [0, 0];
    }
    foreach ($files as $position => $name) {
        if (_stattic_runtime_journal_inode($privateRoot, $name) === $inode) {
            return [$position, $offset];
        }
    }
    // The generation this cursor was reading is gone (retention reclaimed it).
    // Resume at the oldest surviving one rather than skip to the tail.
    return [0, 0];
}

/**
 * Journal records from $cursor forward, across rotation generations, never the
 * same record twice.
 *
 * @param  array{file?: string, offset?: int, inode?: int} $cursor  {} starts at
 *         the oldest surviving generation.
 * @return array{entries: list<array>, cursor: array{file: string, offset: int, inode: int}}
 */
function _stattic_runtime_journal_read(string $privateRoot, array $cursor, int $max): array
{
    $max = max(1, $max);
    $files = _stattic_runtime_journal_files($privateRoot);
    $file = is_string($cursor['file'] ?? null) ? basename($cursor['file']) : '';
    $offset = is_int($cursor['offset'] ?? null) ? max(0, $cursor['offset']) : 0;
    $inode = is_int($cursor['inode'] ?? null) ? max(0, $cursor['inode']) : 0;

    [$index, $offset] = _stattic_runtime_journal_cursor_position(
        $privateRoot,
        $files,
        $file,
        $offset,
        $inode
    );

    $entries = [];
    $file = $files[$index] ?? 'journal.jsonl';
    $inode = 0;
    for ($position = $index; $position < count($files); $position += 1) {
        // Identity BEFORE the read: if this generation is rotated out under the
        // reader, the inode still names the bytes that were just consumed.
        $positionInode = _stattic_runtime_journal_inode($privateRoot, $files[$position]);
        $offset = _stattic_runtime_journal_read_file(
            $privateRoot . '/runtime/' . $files[$position],
            $position === $index ? $offset : 0,
            $max,
            $entries
        );
        $file = $files[$position];
        $inode = $positionInode;
        if (count($entries) >= $max) {
            break;
        }
    }

    return [
        'entries' => $entries,
        'cursor' => ['file' => $file, 'offset' => $offset, 'inode' => $inode],
    ];
}

// The event id hashes these exact bytes, so the hashed entry and the persisted
// one must be enriched by the same code. Idempotent.
function _stattic_runtime_management_event_entry(array $claims, array $entry): array
{
    $operationId = isset($claims['operation_id']) && is_string($claims['operation_id'])
        ? trim($claims['operation_id'])
        : '';
    if ($operationId !== '') {
        $entry['operation_id'] = $operationId;
    }
    if (isset($claims['action']) && is_string($claims['action']) && $claims['action'] !== '') {
        $entry['operation_action'] = $claims['action'];
    }
    return $entry;
}

function _stattic_runtime_management_event_id(array $claims, array $entry): string
{
    $operationId = isset($claims['operation_id']) && is_string($claims['operation_id'])
        ? trim($claims['operation_id'])
        : '';
    $entry = _stattic_runtime_management_event_entry($claims, $entry);
    // Also the control plane's purge idempotency key: it must stay derived from
    // entry content so replayed deliveries enqueue no duplicate purges.
    return hash(
        'sha256',
        $operationId . ':' . (string) ($entry['event'] ?? '') . ':' . json_encode($entry, JSON_UNESCAPED_SLASHES)
    );
}

function _stattic_runtime_record_management_event(string $privateRoot, array $claims, array $entry): string
{
    $entry = _stattic_runtime_management_event_entry($claims, $entry);
    $eventId = _stattic_runtime_management_event_id($claims, $entry);
    $entry['event_id'] = $eventId;
    _stattic_runtime_append_journal($privateRoot, $entry);
    return $eventId;
}

// Publish-lifecycle records (version_created, version_finalized, route_updated,
// hostname intent) are operator diagnostics: their receipt rides the management
// response itself (activation_event_id, purge status), so they carry no
// event_id and the drain cursor walks past them. Records that need
// control-plane delivery use _stattic_runtime_record_management_event instead.
function _stattic_runtime_journal_management_diagnostic(string $privateRoot, array $claims, array $entry): void
{
    _stattic_runtime_append_journal($privateRoot, _stattic_runtime_management_event_entry($claims, $entry));
}

function _stattic_runtime_copy_private_file(string $source, string $target): void
{
    _stattic_runtime_assert_private_path($source);
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    // Fail LOUDLY: callers treat a returned copy as bytes-on-disk.
    if (!copy($source, $target)) {
        _stattic_problem_response(500, 'runtime_copy_failed', 'Private file copy failed.');
    }
}

function _stattic_runtime_blob_path(string $privateRoot, string $spaceId, string $sha): string
{
    $relative = _stattic_blob_relative_key(_stattic_runtime_id($spaceId, 'space_id'), $sha);
    if ($relative === null) {
        _stattic_problem_response(422, 'invalid_blob_sha', 'Blob sha256 is invalid.');
    }
    return $privateRoot . '/' . $relative;
}

// Must agree exactly with `content_mtime` in
// crates/stattic-runtime-core/src/finalize.rs: nginx stamps `ETag: "<hex
// mtime>-<hex size>"` off the inode and never sees our sha256, so the same
// bytes written back later must land on the same content-derived mtime or the
// validator differs across instances.
function _stattic_content_mtime(string $sha): int
{
    $prefix = substr(strtolower(trim($sha)), 0, 12);
    if (preg_match('/^[a-f0-9]{12}$/', $prefix) !== 1) {
        return 0;
    }
    return (int) (hexdec($prefix) % 1450000000);
}

function _stattic_stamp_content_mtime(string $path, string $sha): void
{
    $when = _stattic_content_mtime($sha);
    touch($path, $when, $when);
}

function _stattic_runtime_blob_has(string $privateRoot, string $spaceId, string $sha): bool
{
    $path = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    _stattic_runtime_assert_private_path($path);
    return is_file($path);
}

// The resident length of a CAS object, or null when nothing is stored under
// that name. Length is the one property checkable for free, and the only one
// that catches a copy the CAS holds under a name its bytes do not hash to.
function _stattic_runtime_blob_size(string $privateRoot, string $spaceId, string $sha): ?int
{
    $path = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    _stattic_runtime_assert_private_path($path);
    return _stattic_runtime_path_size($path);
}

function _stattic_runtime_path_size(string $path): ?int
{
    if (!is_file($path)) {
        return null;
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    return is_int($size) ? $size : null;
}

// --- the version file catalog ----------------------------------------------
//
// The ONE file authority for a committed version: a path-keyed document the
// finalizer writes into the version's immutable metadata, naming each path's
// source (uploaded, pre-substitution) identity, its served (post-substitution,
// publicly deliverable) identity, and the single visibility bit. The scan
// manifest, the retained-file sha recovery and the finalize replay manifest all
// resolve through it.
//
//   metadata.json → "catalog": {
//     "format": "spacefast.runtime.file-catalog.v1",
//     "spaceId": "spc_…", "versionId": "ver_…",
//     "paths": {
//       "index.html": {
//         "source": {"sha256": "…", "size": 812, "contentType": "text/html"},
//         "served": {"sha256": "…", "size": 906, "contentType": "text/html"},
//         "public": true
//       },
//       "sf.jsonc": {"source": {…}, "served": null, "public": false}
//     },
//     "variants": {"preview": {"config.js": {"sha256": "…", "size": 928, "contentType": "…"}}}
//   }
//
// It rides INSIDE metadata.json, not beside it, because admin/tier.php sweeps
// every 64-hex string in that one document to decide which blobs are still live
// and is deliberately shape-agnostic. A sibling document would be invisible to
// the collector, and live bytes would get deleted. `variants` is sparse: a
// channel entry lists only the paths whose served object differs from the base.
//
// Rust owns the serialization; nothing in PHP writes it. A version whose
// catalog is missing or malformed has NO fallback: a second projection to
// reconstruct from would be a split brain.
const STATTIC_RUNTIME_VERSION_CATALOG_KEY = 'catalog';
const STATTIC_RUNTIME_VERSION_CATALOG_FORMAT = 'spacefast.runtime.file-catalog.v1';
// Same ceiling the blob gate applies to metadata.json: a document past it is
// treated as unreadable rather than parsed into a worker's memory.
//
// Measured, not estimated, on the largest catalog the publish contract admits
// (php 8.4, compact metadata.json, 100,000 paths, the
// MANIFEST_MAX_FILES_CEILING, each carrying a source AND a served identity of
// 64-hex sha + 8-digit size + `application/octet-stream`, ~308 bytes of catalog
// per path on top of the path itself):
//
//   60-byte paths  -> 35.4 MiB, json_decode peak 218 MiB
//   360-byte paths -> 64.4 MiB (incl. a 2,000-path variant lane), peak 275 MiB
//   1024-byte paths (MANIFEST_MAX_PATH_BYTES, every path) -> 127 MiB, peak 599 MiB
//
// So 64 MiB: the workers' 512 MB memory limit decodes that with room to spare,
// and it covers 100,000 files at any sane path length. It is a MEMORY bound,
// not a contract bound. The extreme above cannot be decoded by any worker, so
// it is refused here instead of OOMing one.
const STATTIC_RUNTIME_VERSION_CATALOG_MAX_BYTES = 67108864;
// Read once per (space, version) per request, and across requests from opcache
// SHM via a write-once sidecar: a scan pulls one blob per request, so
// re-decoding the catalog every time would be quadratic. No TTL is needed. A
// version id is written exactly once (a republish is a NEW version id, and
// create_version 409s on a committed one), and the sidecars live INSIDE the
// version directory, so deleting the version deletes its caches and no
// invalidation protocol exists. A MISS is memoized only for the request: a
// version finalized a moment later must not stay invisible.
const STATTIC_RUNTIME_VERSION_CATALOG_SIDECAR = 'catalog-cache.php';
// The blob gate's derived lane map: one sidecar when it fits the derived-cache
// ceiling, else sha-prefix shards so the gate includes exactly one small shard
// per request instead of decoding the whole catalog.
const STATTIC_RUNTIME_VERSION_GATE_SIDECAR = 'gate-cache.php';
const STATTIC_RUNTIME_VERSION_GATE_SHARD_DIR = 'gate-cache';
// The path lane's counterpart for versions whose catalog exceeds the whole-
// sidecar ceiling: sha(path)-prefix shards so the file resolver includes one
// small shard per request instead of re-decoding a multi-MB metadata.json.
const STATTIC_RUNTIME_VERSION_PATH_SHARD_DIR = 'path-cache';

const STATTIC_RUNTIME_VERSION_FILE_VIEWS = ['source', 'served'];

// A publish session's life when its record carries no explicit expiry.
const STATTIC_RUNTIME_UPLOAD_SESSION_DEFAULT_TTL_SECONDS = 86400;

/**
 * The open-publish-session record store. Here rather than on the upload surface
 * because it has two readers: the upload lane that writes it, and the blob gate,
 * which answers for bytes uploaded but not yet finalized.
 */
function _stattic_runtime_publish_sessions_store(string $privateRoot, string $spaceId): array
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $root = _stattic_space_root($privateRoot, $spaceId) . '/publish-sessions';
    return _stattic_record_store($root, [
        'retention' => [
            'field' => 'expires_at',
            'fallback_field' => 'created_at',
            'fallback_seconds' => STATTIC_RUNTIME_UPLOAD_SESSION_DEFAULT_TTL_SECONDS,
        ],
    ]);
}

/**
 * The blob a capability minted against an OPEN publish session may read.
 *
 * A version being finalized has no catalog yet, so the version-sha and path
 * lanes cannot authorize the bytes this publish just uploaded. The session
 * authorizes them, and only while it is open: finalize consumes it and the
 * catalog lanes take over.
 *
 * `accepted` is the authority, never `manifest`: a declared digest is a promise,
 * an accepted one is bytes this runtime hashed and committed to the CAS. An
 * invalid id, an absent, expired or unreadable session, and a sha the session
 * has not accepted are ONE outcome: null, the gate's uniform 404.
 *
 * Read-only by design: an expired session reads as absent rather than being
 * released, because releasing is a locked mutation the upload surface owns.
 *
 * @return array{sha: string, mime: string}|null
 */
function _stattic_runtime_publish_session_blob(
    string $privateRoot,
    string $spaceId,
    string $uploadId,
    string $sha256
): ?array {
    if (!_stattic_id_valid($uploadId) || !_stattic_is_sha256_hex($sha256)) {
        return null;
    }
    $session = _stattic_record_store_get(
        _stattic_runtime_publish_sessions_store($privateRoot, $spaceId),
        $uploadId
    );
    if (!is_array($session) || ($session['space_id'] ?? null) !== $spaceId) {
        return null;
    }
    $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        return null;
    }
    $accepted = is_array($session['accepted'] ?? null) ? $session['accepted'] : [];
    if (!array_key_exists($sha256, $accepted)) {
        return null;
    }
    $mime = 'application/octet-stream';
    foreach ((is_array($session['manifest'] ?? null) ? $session['manifest'] : []) as $entry) {
        if (!is_array($entry) || !is_string($entry['sha256'] ?? null)) {
            continue;
        }
        if (!hash_equals(strtolower($entry['sha256']), $sha256)) {
            continue;
        }
        if (is_string($entry['contentType'] ?? null) && $entry['contentType'] !== '') {
            $mime = $entry['contentType'];
        }
        break;
    }
    return ['sha' => $sha256, 'mime' => $mime];
}

// The lane key for the version's own objects, as opposed to one channel's
// variant overrides. NUL-prefixed so it can never collide with a route name.
const STATTIC_RUNTIME_VERSION_BLOB_BASE_LANE = "\0base";

/**
 * The decoded catalog for one version, or null when it is absent, oversized,
 * malformed, or bound to a different space/version. Memoized per request; warm
 * reads come out of the opcached sidecar written by the first reader. The
 * sidecar embeds the (space, version) identity and is checked against the
 * caller's: a cache is never trusted to be about what its path claims.
 *
 * @return array{paths: array<string,mixed>, variants: array<string,mixed>}|null
 */
function _stattic_runtime_version_catalog(string $privateRoot, string $spaceId, string $versionId): ?array
{
    static $memo = [];
    $memoKey = $spaceId . "\0" . $versionId;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $identity = ['spaceId' => $spaceId, 'versionId' => $versionId];
    $cached = _sf_php_cache_read($root . '/' . STATTIC_RUNTIME_VERSION_CATALOG_SIDECAR, $identity);
    if (is_array($cached) && is_array($cached['paths'] ?? null) && is_array($cached['variants'] ?? null)) {
        return $memo[$memoKey] = ['paths' => $cached['paths'], 'variants' => $cached['variants']];
    }
    $path = $root . '/metadata.json';
    if (!is_file($path)) {
        return $memo[$memoKey] = null;
    }
    $size = filesize($path);
    if ($size === false || $size > STATTIC_RUNTIME_VERSION_CATALOG_MAX_BYTES) {
        return $memo[$memoKey] = null;
    }
    $metadata = _stattic_runtime_read_json($path);
    $decoded = is_array($metadata) ? ($metadata[STATTIC_RUNTIME_VERSION_CATALOG_KEY] ?? null) : null;
    if (
        !is_array($decoded)
        || ($decoded['format'] ?? null) !== STATTIC_RUNTIME_VERSION_CATALOG_FORMAT
        || ($decoded['spaceId'] ?? null) !== $spaceId
        || ($decoded['versionId'] ?? null) !== $versionId
        || !is_array($decoded['paths'] ?? null)
    ) {
        return $memo[$memoKey] = null;
    }
    $catalog = [
        'paths' => $decoded['paths'],
        'variants' => is_array($decoded['variants'] ?? null) ? $decoded['variants'] : [],
    ];
    // A catalog too large for the whole sidecar falls to path shards, so the
    // per-path lane never re-decodes metadata.json once a reader has built
    // them. The sha lane has its own gate shards.
    if (!_sf_php_cache_write($root . '/' . STATTIC_RUNTIME_VERSION_CATALOG_SIDECAR, $catalog + $identity)) {
        _stattic_runtime_write_path_sidecars($root, $spaceId, $versionId, $catalog['paths']);
    }
    return $memo[$memoKey] = $catalog;
}

/**
 * One catalog object normalized to the resolver's answer shape, or null when
 * the object is absent (a private input has no served identity) or malformed.
 *
 * @return array{sha: string, size: int, mime: string}|null
 */
function _stattic_runtime_catalog_object(mixed $object): ?array
{
    if (!is_array($object)) {
        return null;
    }
    $sha = is_string($object['sha256'] ?? null) ? strtolower($object['sha256']) : '';
    if (!_stattic_is_sha256_hex($sha)) {
        return null;
    }
    $mime = is_string($object['contentType'] ?? null) && $object['contentType'] !== ''
        ? $object['contentType']
        : 'application/octet-stream';
    return [
        'sha' => $sha,
        'size' => max(0, (int) ($object['size'] ?? 0)),
        'mime' => $mime,
    ];
}

/** @return array<string,string> sha256 => content type, first declaration wins. */
function _stattic_runtime_catalog_lane_shas(array $objects): array
{
    $shas = [];
    foreach ($objects as $object) {
        $normalized = _stattic_runtime_catalog_object($object);
        if ($normalized === null) {
            continue;
        }
        // The same bytes under two paths are one blob, and the compiler records
        // one type per object.
        $shas[$normalized['sha']] ??= $normalized['mime'];
    }
    return $shas;
}

/**
 * Every sha the blob gate may serve for one version, as lane => (sha => type),
 * built from the catalog. The base lane holds the version's own objects, BOTH
 * identities: a sha claim may name the uploaded object or the served one, and
 * either is a byte this version legitimately holds. Each further lane is one
 * channel's variant overrides, which is what a variant-scoped token resolves
 * against; a channel this version overrides nothing for has no lane and
 * resolves nothing.
 *
 * @param array{paths: array<string,mixed>, variants: array<string,mixed>} $catalog
 * @return array<string,array<string,string>>
 */
function _stattic_runtime_catalog_lanes(array $catalog): array
{
    $base = [];
    foreach ($catalog['paths'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $base[] = $entry['source'] ?? null;
        $base[] = $entry['served'] ?? null;
    }
    $lanes = [STATTIC_RUNTIME_VERSION_BLOB_BASE_LANE => _stattic_runtime_catalog_lane_shas($base)];
    foreach ($catalog['variants'] as $routeName => $variant) {
        if (is_string($routeName) && is_array($variant)) {
            $lanes[$routeName] = _stattic_runtime_catalog_lane_shas(array_values($variant));
        }
    }
    return $lanes;
}

/**
 * The content type one sha claim resolves to in one lane of one version, or
 * null when the version has no readable catalog, the channel overrides nothing,
 * or the sha is not a byte this version holds. The gate answers its uniform 404
 * either way.
 *
 * Warm reads never touch the catalog: the whole lane map rides in one opcached
 * gate sidecar when it fits the derived-cache ceiling, else in sha-prefix
 * shards so a request includes exactly one small shard. Both are built from ONE
 * catalog decode the first time any sha is asked, which keeps a scan linear
 * instead of quadratic.
 */
function _stattic_runtime_version_blob_mime(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $sha256,
    ?string $routeName = null
): ?string {
    if (!_stattic_is_sha256_hex($sha256)) {
        return null;
    }
    $lane = $routeName ?? STATTIC_RUNTIME_VERSION_BLOB_BASE_LANE;
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $identity = ['spaceId' => $spaceId, 'versionId' => $versionId];
    foreach (
        [
            $root . '/' . STATTIC_RUNTIME_VERSION_GATE_SIDECAR,
            $root . '/' . STATTIC_RUNTIME_VERSION_GATE_SHARD_DIR . '/' . substr($sha256, 0, 2) . '.php',
        ] as $sidecar
    ) {
        $cached = _sf_php_cache_read($sidecar, $identity);
        if (is_array($cached) && is_array($cached['lanes'] ?? null)) {
            $mime = $cached['lanes'][$lane][$sha256] ?? null;
            return is_string($mime) ? $mime : null;
        }
    }
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    if ($catalog === null) {
        return null;
    }
    $lanes = _stattic_runtime_catalog_lanes($catalog);
    _stattic_runtime_write_gate_sidecars($root, $spaceId, $versionId, $lanes);
    $mime = $lanes[$lane][$sha256] ?? null;
    return is_string($mime) ? $mime : null;
}

/**
 * Best-effort, once per version: one gate sidecar when the lane map fits the
 * derived-cache ceiling, else all 256 sha-prefix shards. Every prefix gets a
 * file, because an absent shard means "not built" and sends the next lookup
 * back to a full catalog decode. Failures leave the next reader to rebuild.
 *
 * @param array<string,array<string,string>> $lanes
 */
function _stattic_runtime_write_gate_sidecars(string $root, string $spaceId, string $versionId, array $lanes): void
{
    $identity = ['spaceId' => $spaceId, 'versionId' => $versionId];
    if (_sf_php_cache_write($root . '/' . STATTIC_RUNTIME_VERSION_GATE_SIDECAR, $identity + ['lanes' => $lanes])) {
        return;
    }
    $shards = [];
    foreach ($lanes as $lane => $shas) {
        foreach ($shas as $sha => $mime) {
            $shards[substr((string) $sha, 0, 2)][$lane][$sha] = $mime;
        }
    }
    $dir = $root . '/' . STATTIC_RUNTIME_VERSION_GATE_SHARD_DIR;
    for ($i = 0; $i < 256; $i += 1) {
        $prefix = str_pad(dechex($i), 2, '0', STR_PAD_LEFT);
        if (!_sf_php_cache_write($dir . '/' . $prefix . '.php', $identity + ['lanes' => $shards[$prefix] ?? []])) {
            return;
        }
    }
}

/**
 * Best-effort, once per version whose catalog exceeds the whole-sidecar
 * ceiling: 256 sha(path)-prefix shards mirroring the gate shards, so the file
 * resolver answers a path from one small opcached shard. Every prefix gets a
 * file. A written shard that lacks the path is an authoritative miss, while an
 * absent shard means "not built" and falls back to the catalog.
 *
 * @param array<string,mixed> $paths
 */
function _stattic_runtime_write_path_sidecars(string $root, string $spaceId, string $versionId, array $paths): void
{
    $identity = ['spaceId' => $spaceId, 'versionId' => $versionId];
    $shards = [];
    foreach ($paths as $path => $entry) {
        if (is_string($path) && $path !== '' && is_array($entry)) {
            $shards[substr(hash('sha256', $path), 0, 2)][$path] = $entry;
        }
    }
    $dir = $root . '/' . STATTIC_RUNTIME_VERSION_PATH_SHARD_DIR;
    for ($i = 0; $i < 256; $i += 1) {
        $prefix = str_pad(dechex($i), 2, '0', STR_PAD_LEFT);
        if (!_sf_php_cache_write($dir . '/' . $prefix . '.php', $identity + ['paths' => $shards[$prefix] ?? []])) {
            return;
        }
    }
}

/**
 * THE version-file resolver, shared by the blob gate and the management list
 * route.
 *
 * Absent path, absent view for that path, unreadable catalog and unnormalizable
 * path are ONE outcome: null, the stable `version_file_not_found`. A caller
 * cannot act differently on any of them and the gate must not say which it was.
 * Existence in the CAS is the caller's next question: the gate answers a cold
 * tier 503, never 404, once it has an identity.
 *
 * @return array{sha: string, size: int, mime: string, visibility: string}|null
 */
function _stattic_runtime_resolve_version_file(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $path,
    string $view
): ?array {
    if (!in_array($view, STATTIC_RUNTIME_VERSION_FILE_VIEWS, true)) {
        return null;
    }
    $path = _stattic_runtime_file_path_or_null($path);
    if ($path === null || _stattic_path_is_internal_artifact($path)) {
        return null;
    }
    // Warm path for catalogs past the whole-sidecar ceiling: one opcached
    // shard holds this path's entry (or its authoritative absence) without
    // touching metadata.json.
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $shard = _sf_php_cache_read(
        $root . '/' . STATTIC_RUNTIME_VERSION_PATH_SHARD_DIR . '/' . substr(hash('sha256', $path), 0, 2) . '.php',
        ['spaceId' => $spaceId, 'versionId' => $versionId]
    );
    if (is_array($shard) && is_array($shard['paths'] ?? null)) {
        $entry = $shard['paths'][$path] ?? null;
    } else {
        $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
        if ($catalog === null) {
            return null;
        }
        $entry = $catalog['paths'][$path] ?? null;
    }
    if (!is_array($entry)) {
        return null;
    }
    $object = _stattic_runtime_catalog_object($entry[$view] ?? null);
    if ($object === null) {
        return null;
    }
    return $object + ['visibility' => ($entry['public'] ?? false) === true ? 'public' : 'private'];
}

/**
 * The stable refusal for a path the selected view does not carry, shared by
 * every caller of the resolver so one wrong path reads the same everywhere. The
 * blob gate keeps its bare 404 for anything that failed BEFORE authorization,
 * and passes its CORS grant in `$headers` for this one.
 *
 * @param array<string,scalar> $headers
 */
function _stattic_runtime_version_file_not_found(array $headers = []): never
{
    _stattic_problem_response(
        404,
        'version_file_not_found',
        'This version does not carry that file in the requested view.',
        [],
        $headers,
    );
}

/**
 * The committed identity of every path in a version, as the control plane's
 * finalize receipt spells it: the served object when the path is served, the
 * uploaded object otherwise. Sorted by path so a replay is byte-identical.
 *
 * @return list<array{path: string, size: int, sha256: string, contentType: string}>
 */
function _stattic_runtime_catalog_manifest(array $catalog): array
{
    $manifest = [];
    foreach ($catalog['paths'] as $path => $entry) {
        if (
            !is_string($path)
            || $path === ''
            || _stattic_path_is_internal_artifact($path)
            || !is_array($entry)
        ) {
            continue;
        }
        $object = _stattic_runtime_catalog_object($entry['served'] ?? null)
            ?? _stattic_runtime_catalog_object($entry['source'] ?? null);
        if ($object === null) {
            continue;
        }
        $manifest[] = [
            'path' => $path,
            'size' => $object['size'],
            'sha256' => $object['sha'],
            'contentType' => $object['mime'],
        ];
    }
    usort($manifest, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $manifest;
}

// The demote sidecar: "these bytes are in S3". Written BEFORE the local copy is
// released, and it OUTLIVES the body: with the body gone it is the evidence
// that a promote is the only way back to these bytes. Deleted only together
// with a body that has left the live set.
const STATTIC_BLOB_DEMOTE_MARK_SUFFIX = '.demote';

function _stattic_storage_blob_demote_mark_path(string $blobPath): string
{
    return $blobPath . STATTIC_BLOB_DEMOTE_MARK_SUFFIX;
}

function _stattic_storage_blob_demote_mark(string $blobPath, array $details = []): void
{
    _stattic_runtime_write_json_atomic(
        _stattic_storage_blob_demote_mark_path($blobPath),
        $details + ['demoted_at' => gmdate('c')]
    );
}

// Expected input/limit failures are RETURNED so each surface keeps its own
// error vocabulary; private-path invariant failures still fail hard.
function _stattic_runtime_blob_stage_stream(
    string $privateRoot,
    mixed $input,
    int $limit,
    int $prefixBytes = 0
): array {
    if (!is_resource($input)) {
        return ['ok' => false, 'reason' => 'input_open_failed'];
    }
    $stagingRoot = $privateRoot . '/runtime/blob-staging';
    _stattic_runtime_mkdir($stagingRoot);
    $tmpPath = $stagingRoot . '/blob-' . bin2hex(random_bytes(12)) . '.tmp';
    _stattic_runtime_assert_private_path($tmpPath);
    return _stattic_runtime_stream_to_path($tmpPath, $input, $limit, $prefixBytes);
}

function _stattic_runtime_require_blob_source(string $tmpPath): void
{
    _stattic_runtime_assert_private_path($tmpPath);
    if (!is_file($tmpPath)) {
        _stattic_problem_response(404, 'blob_source_not_found', 'Blob source file was not found.');
    }
}

// Trusts an already-computed digest, so keep it internal: public and intake
// boundaries must use _blob_put or _blob_stage_stream.
function _stattic_runtime_blob_commit_verified(string $privateRoot, string $spaceId, string $tmpPath, string $sha): void
{
    _stattic_runtime_require_blob_source($tmpPath);
    $expected = strtolower(trim($sha));
    $target = _stattic_runtime_blob_path($privateRoot, $spaceId, $expected);
    // Presence is not possession. These bytes were verified against $expected
    // at the intake boundary, so a resident object of a DIFFERENT length is
    // provably not what this name promises: deduping on presence alone would
    // discard the only good copy on offer and leave the CAS unrepairable. Equal
    // length keeps the cheap no-op path; only divergence installs.
    $stagedSize = _stattic_runtime_path_size($tmpPath);
    $residentSize = _stattic_runtime_path_size($target);
    $repair = $residentSize !== null && $residentSize !== $stagedSize;
    if ($residentSize !== null && !$repair) {
        unlink($tmpPath);
        return;
    }
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    $pending = $target . '.tmp-' . bin2hex(random_bytes(6));
    if (!rename($tmpPath, $pending)) {
        _stattic_runtime_copy_private_file($tmpPath, $pending);
        unlink($tmpPath);
    }
    // Before the commit rename, so the blob is never visible at $target
    // carrying the download's clock reading.
    _stattic_stamp_content_mtime($pending, $expected);
    if ($repair) {
        // rename(2) over the damaged entry, so serving never observes a gap.
        // This repairs the CAS, not version trees already pointing at the old
        // inode through a hardlink.
        if (!rename($pending, $target)) {
            unlink($pending);
            _stattic_problem_response(500, 'blob_put_failed', 'Blob could not be repaired.');
        }
    } elseif (!is_file($target) && !rename($pending, $target)) {
        if (!is_file($target)) {
            unlink($pending);
            _stattic_problem_response(500, 'blob_put_failed', 'Blob could not be committed.');
        }
    }
    if (is_file($pending)) {
        unlink($pending);
    }
    // The body is back, so the demote mark must not survive: the GC releases a
    // live blob whose mark is older than grace. Cleared here rather than at each
    // installer (promote, URL fetch, upload, dedupe), and only on the install
    // path. A body that is still present but marked is the pending-release
    // state eviction depends on.
    $demoteMark = _stattic_storage_blob_demote_mark_path($target);
    if (is_file($demoteMark)) {
        unlink($demoteMark);
    }
}

function _stattic_runtime_blob_put(string $privateRoot, string $spaceId, string $tmpPath, string $sha): void
{
    // Keep this ahead of hash_file: a missing source must stay a 404
    // blob_source_not_found, not a 409 blob_sha_mismatch.
    _stattic_runtime_require_blob_source($tmpPath);
    $expected = strtolower(trim($sha));
    $actual = hash_file('sha256', $tmpPath);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        _stattic_problem_response(409, 'blob_sha_mismatch', 'Blob source did not match its sha256.');
    }
    _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, $tmpPath, $expected);
}

function _stattic_runtime_rm_recursive(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    _stattic_runtime_assert_private_path($path);
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        _stattic_runtime_rm_recursive($path . '/' . $entry);
    }
    rmdir($path);
}

function _stattic_runtime_assert_private_path(string $path): void
{
    $root = _stattic_runtime_real_private_root($path);
    $dir = is_dir($path) ? $path : dirname($path);
    $ancestor = $dir;
    while (!is_dir($ancestor)) {
        $next = dirname($ancestor);
        if ($next === $ancestor) {
            _stattic_problem_response(500, 'runtime_path_unresolved', 'Runtime path could not be resolved.');
        }
        $ancestor = $next;
    }
    $realAncestor = realpath($ancestor);
    if (!is_string($realAncestor) || !_stattic_runtime_path_is_inside($realAncestor, $root)) {
        _stattic_problem_response(500, 'runtime_path_escape', 'Runtime path escaped private storage.');
    }
    $candidate = _stattic_runtime_normalize_absolute_path($path);
    if ($candidate === null || !_stattic_runtime_path_is_inside($candidate, $root)) {
        _stattic_problem_response(500, 'runtime_path_escape', 'Runtime path escaped private storage.');
    }
}

// Fail closed per entry: a symlink inside the tree that resolves outside
// private storage is skipped and never reaches the caller. The private root is
// resolved once; it cannot change between entries of one walk.
function _stattic_runtime_walk_private_files(string $root): Generator
{
    if (!is_dir($root)) {
        return;
    }
    $realRoot = _stattic_runtime_real_private_root($root);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        if (!is_string($real) || !_stattic_runtime_path_is_inside(str_replace('\\', '/', $real), $realRoot)) {
            continue;
        }
        yield $real;
    }
}

// Purely lexical: no realpath, no private-root anchoring. The caller must have
// already validated the root.
function _stattic_runtime_relative_to(string $root, string $path): string
{
    return ltrim(substr(str_replace('\\', '/', $path), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
}

function _stattic_runtime_real_private_root(string $path): string
{
    $marker = '/.stattic/storage';
    $index = strpos(str_replace('\\', '/', $path), $marker);
    if ($index === false) {
        _stattic_problem_response(500, 'runtime_path_unconfined', 'Runtime path is outside private storage.');
    }
    $root = substr($path, 0, $index + strlen($marker));
    // The resolved root cannot change within a worker, and the path asserts run
    // this several times per request. Memoize the success.
    static $resolved = [];
    if (isset($resolved[$root])) {
        return $resolved[$root];
    }
    $realRoot = realpath($root);
    if (!is_string($realRoot)) {
        _stattic_problem_response(500, 'runtime_private_root_missing', 'Runtime private storage is missing.');
    }
    return $resolved[$root] = rtrim(str_replace('\\', '/', $realRoot), '/');
}

function _stattic_runtime_normalize_absolute_path(string $path): ?string
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
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

function _stattic_runtime_path_is_inside(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return $path === $root || str_starts_with($path, $root . '/');
}
