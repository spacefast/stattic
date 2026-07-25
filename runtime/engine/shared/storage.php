<?php
declare(strict_types=1);

// Request-body seam for the SSH management dispatcher (admin/dispatch.php):
// in CLI dispatch mode php://input is the dispatch envelope itself, so the
// dispatcher stages the request body here before invoking the SAME handlers
// the HTTP surface runs.
function _stattic_json_body_override(?string $set = null): ?string
{
    static $override = null;
    if ($set !== null) {
        $override = $set;
    }
    return $override;
}

function _stattic_binary_body_override(?string $set = null): ?string
{
    static $override = null;
    if ($set !== null) {
        $override = $set;
    }
    return $override;
}

function _stattic_binary_body_stream()
{
    $override = _stattic_binary_body_override();
    if ($override === null) {
        return fopen('php://input', 'rb');
    }
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return false;
    }
    fwrite($stream, $override);
    rewind($stream);
    return $stream;
}

function _stattic_json_body(): array
{
    $raw = _stattic_json_body_override() ?? file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        _stattic_json_response(400, ['error' => ['code' => 'invalid_json', 'message' => 'Request body must be valid JSON.']]);
    }
    return $decoded;
}

function _stattic_json_response(int $status, array $body): void
{
    // SSH management dispatch (admin/dispatch.php): the response rides stdout
    // as one {status, body} JSON envelope instead of an HTTP response. Always
    // exit 0 — transport failures are the non-zero/garbage cases.
    if (defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
        echo json_encode(['status' => $status, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($body, JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function _stattic_runtime_route_not_found(): never
{
    _stattic_json_response(404, [
        'error' => [
            'code' => 'runtime_route_not_found',
            'message' => 'Runtime API route not found.',
        ],
    ]);
    exit;
}

function _stattic_runtime_upload_route_not_found(): never
{
    _stattic_json_response(404, [
        'error' => [
            'code' => 'runtime_upload_route_not_found',
            'message' => 'Runtime upload route not found.',
        ],
    ]);
    exit;
}

function _stattic_runtime_id(string $value, string $field): string
{
    $value = trim($value);
    if (!_stattic_runtime_id_valid($value)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_' . $field, 'message' => $field . ' is invalid: expected 1-128 characters of letters, digits, \'.\', \'_\', or \'-\' (and not dots only).']]);
    }
    return $value;
}

// Admin-API error envelope (the CLI/API `{error}` contract): uncaught
// Throwables and fatals answer as JSON instead of a bare PHP 500. Registered
// once by EVERY admin API surface prologue (management, file-fetch, upload) —
// lives here beside _stattic_json_response so no surface has to import another
// surface to get it.
function _stattic_runtime_api_error_message(Throwable $error): string
{
    $message = trim($error->getMessage());
    return $message !== '' ? $message : 'Runtime API internal error.';
}

function _stattic_runtime_api_fatal_message(array $error): string
{
    $message = isset($error['message']) && is_string($error['message']) ? trim($error['message']) : '';
    return $message !== '' ? $message : 'Runtime API fatal error.';
}

function _stattic_runtime_api_register_error_envelope(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    set_exception_handler(static function (Throwable $error): void {
        _stattic_json_response(500, [
            'error' => [
                'code' => 'runtime_internal_error',
                'message' => _stattic_runtime_api_error_message($error),
                'details' => [
                    'type' => get_debug_type($error),
                ],
            ],
        ]);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $type = is_int($error['type'] ?? null) ? $error['type'] : 0;
        if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (!defined('STATTIC_RUNTIME_DISPATCH_CLI') && headers_sent()) {
            return;
        }
        _stattic_json_response(500, [
            'error' => [
                'code' => 'runtime_fatal_error',
                'message' => _stattic_runtime_api_fatal_message($error),
                'details' => [
                    'type' => $type,
                ],
            ],
        ]);
    });
}

function _stattic_runtime_id_valid(string $value): bool
{
    return _spacefast_id_valid(trim($value));
}

function _stattic_runtime_new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(12));
}

function _stattic_runtime_file_path(string $path): string
{
    // Canonicalize to NFC first (spec canonical path form): manifest entries,
    // batch entries, and retained-file paths all mean the canonical form.
    $path = _stattic_nfc_path($path);
    $original = $path;
    $path = trim($path);
    if ($path !== $original || str_contains($path, '\\') || str_contains($path, '//')) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_file_path', 'message' => 'File path is invalid.']]);
    }
    $path = ltrim($path, '/');
    if ($path === '' || $path !== $original || str_contains($path, "\0") || str_contains('/' . $path . '/', '/../') || str_starts_with($path, '../')) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_file_path', 'message' => 'File path is invalid.']]);
    }
    return $path;
}

function _stattic_runtime_mkdir(string $path): void
{
    _stattic_runtime_assert_private_path($path);
    // mkdir() is not atomic against this check: two concurrent requests (the
    // job runner's lane ticks are the first genuinely concurrent callers of
    // this function) can both pass !is_dir() and race into mkdir(), so a
    // "File exists" warning from the loser is expected, not a real failure —
    // only report an error if the directory is STILL missing afterward.
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_mkdir_failed', 'message' => 'Runtime storage directory could not be created.']]);
    }
    @chmod($path, 0775);
}

// Soft variant: same private-path invariant + same race-tolerant mkdir as
// _stattic_runtime_mkdir above, but returns false instead of rendering a hard
// 500 when the directory still doesn't exist afterward (disk quota,
// permissions, read-only mount). For callers whose OWN contract already has a
// graceful "storage unavailable" outcome — shared/jwt.php's jti replay guard
// is the original of this shape (see _spacefast_jwt_consume_jti) — so a
// storage hiccup surfaces as that outcome instead of crashing the request.
// A path-escape attempt is still a hard invariant failure either way:
// _stattic_runtime_assert_private_path never returns false, it renders 500.
function _stattic_runtime_mkdir_soft(string $path): bool
{
    _stattic_runtime_assert_private_path($path);
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        return false;
    }
    @chmod($path, 0775);
    return true;
}

function _stattic_runtime_read_json(string $path): mixed
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }
    return json_decode($raw, true);
}

// Sentinel distinguishing "file does not exist" (the legitimate empty state)
// from "file exists but could not be read or parsed" (state UNAVAILABLE).
// _stattic_runtime_read_json above collapses both into null, which is the
// right call for most callers (a missing settings file and an unreadable one
// both just mean "use the default") but wrong for a revocation store: an
// unreadable revocations.json must never look identical to "no space has
// ever revoked anything" — that would fail a grant-backed access decision
// open. This read-layer distinction is deliberately narrow; the fail-closed
// POLICY lives at the revocations load site below.
const STATTIC_RUNTIME_READ_UNREADABLE = "\0stattic:unreadable\0";

function _stattic_runtime_read_json_tristate(string $path): mixed
{
    if (!is_file($path)) {
        return null; // legitimate empty state — nothing has ever been written.
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return STATTIC_RUNTIME_READ_UNREADABLE;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return STATTIC_RUNTIME_READ_UNREADABLE;
    }
    return $decoded;
}

// Marker-file throttle for periodic housekeeping (event-file pruning, replay-
// cache sweeps): returns true — touching the marker — when at least
// $intervalSeconds have passed since the last run, false to skip. Concurrent
// callers racing a stale marker may both run; every throttled job must be
// idempotent, which the callers' contracts already require.
function _spacefast_marker_throttle(string $markerPath, int $intervalSeconds, ?int $now = null): bool
{
    $now ??= time();
    $last = @filemtime($markerPath);
    if ($last !== false && $now - $last < $intervalSeconds) {
        return false;
    }
    @touch($markerPath, $now);
    return true;
}

// THE atomic private-write primitive (mkdir + private-path assert + tmp write
// + rename). Every private-root string/JSON/PHP artifact write goes through
// this idiom — do not open-code it at call sites.
function _stattic_runtime_write_private_string(string $path, string $content): void
{
    _stattic_runtime_mkdir(dirname($path));
    _stattic_runtime_assert_private_path($path);
    file_put_contents($path . '.tmp', $content, LOCK_EX);
    rename($path . '.tmp', $path);
}

function _stattic_runtime_write_json_atomic(string $path, array $value): void
{
    _stattic_runtime_write_private_string($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function _spacefast_revocations_path(string $privateRoot, string $spaceId): string
{
    return _spacefast_space_root($privateRoot, $spaceId) . '/revocations.json';
}

function _spacefast_revocation_grant_valid(string $grant): bool
{
    return $grant !== ''
        && strlen($grant) <= 256
        && !str_contains($grant, "\0")
        && (str_starts_with($grant, 'link:') || str_starts_with($grant, 'invite:') || str_starts_with($grant, 'svc:'));
}

function _spacefast_revocation_sub_valid(string $sub): bool
{
    return $sub !== ''
        && strlen($sub) <= 512
        && !str_contains($sub, "\0")
        && preg_match('/[\x00-\x1f\x7f]/', $sub) !== 1;
}

function _spacefast_normalize_revocation_records(mixed $raw): array
{
    $empty = ['grants' => [], 'subs' => []];
    if (!is_array($raw)) {
        return $empty;
    }
    $records = $empty;
    foreach (['grants', 'subs'] as $kind) {
        if (!isset($raw[$kind]) || !is_array($raw[$kind])) {
            continue;
        }
        foreach ($raw[$kind] as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($kind === 'grants' && !_spacefast_revocation_grant_valid($key)) {
                continue;
            }
            if ($kind === 'subs' && !_spacefast_revocation_sub_valid($key)) {
                continue;
            }
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                continue;
            }
            $ts = (int) $value;
            if ($ts <= 0) {
                continue;
            }
            $records[$kind][$key] = $ts;
        }
    }
    return $records;
}

function _spacefast_load_revocation_records(string $privateRoot, string $spaceId): array
{
    return _spacefast_load_revocation_state($privateRoot, $spaceId)['records'];
}

// Loads a space's revocation tombstones plus an `available` flag distinguishing
// "revocations.json has never been written" (available=true, empty records —
// the legitimate empty state) from "revocations.json exists but could not be
// read or parsed" (available=false — corrupt/unreadable disk state). Grant-
// backed access decisions (jwt.php's _spacefast_visitor_verify, via
// _spacefast_load_revocations below) MUST fail closed on available=false —
// treating a read failure as "nothing revoked" would silently let a revoked
// grant keep working. Management writers that only ever replace/merge the
// record set (_stattic_runtime_store_revocations_replace,
// _stattic_runtime_update_access_revocations) go through
// _spacefast_load_revocation_records above and keep their existing
// treat-as-empty behavior — unaffected by this change.
function _spacefast_load_revocation_state(string $privateRoot, string $spaceId): array
{
    $spaceId = trim($spaceId);
    $empty = ['grants' => [], 'subs' => []];
    if ($privateRoot === '' || !_stattic_runtime_id_valid($spaceId)) {
        return ['records' => $empty, 'available' => true];
    }
    // Per-request memo: identity verification runs once per matched auth rule
    // (plus the clean-URL and post-rewrite re-enforcement passes), and the
    // revocation file does not change under a serving request. Writers replace
    // the file and must call _spacefast_forget_revocation_state.
    $memo = &_spacefast_revocation_state_memo();
    $key = $privateRoot . '|' . $spaceId;
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $path = _spacefast_revocations_path($privateRoot, $spaceId);
    _stattic_runtime_assert_private_path($path);
    $raw = _stattic_runtime_read_json_tristate($path);
    if ($raw === STATTIC_RUNTIME_READ_UNREADABLE) {
        _stattic_runtime_record_repair_state($privateRoot, 'revocations_unreadable', ['space_id' => $spaceId]);
        return $memo[$key] = ['records' => $empty, 'available' => false];
    }
    return $memo[$key] = ['records' => _spacefast_normalize_revocation_records($raw), 'available' => true];
}

function &_spacefast_revocation_state_memo(): array
{
    static $memo = [];
    return $memo;
}

function _spacefast_forget_revocation_state(string $privateRoot, string $spaceId): void
{
    $memo = &_spacefast_revocation_state_memo();
    unset($memo[$privateRoot . '|' . trim($spaceId)]);
}

function _spacefast_load_revocations(string $privateRoot, string $spaceId): array
{
    $state = _spacefast_load_revocation_state($privateRoot, $spaceId);
    return [
        'grants' => array_fill_keys(array_keys($state['records']['grants']), true),
        'subs' => array_fill_keys(array_keys($state['records']['subs']), true),
        'available' => $state['available'],
    ];
}

function _stattic_runtime_json_object(array $value): object|array
{
    return $value === [] ? (object) [] : $value;
}

function _stattic_runtime_write_php_atomic(string $path, array $value): void
{
    _stattic_runtime_write_private_string($path, "<?php\nreturn " . var_export($value, true) . ";\n");
    _stattic_runtime_invalidate_php_artifact($path);
}

function _stattic_runtime_invalidate_php_artifact(string $path): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }

    if (@opcache_invalidate($path, true)) {
        return;
    }

    $privateRoot = _stattic_runtime_real_private_root($path);
    _stattic_runtime_record_repair_state($privateRoot, 'opcache_invalidation_failed', [
        'path' => _stattic_runtime_relative_private_path($privateRoot, $path),
    ]);
}

function _stattic_runtime_record_repair_state(string $privateRoot, string $code, array $details): void
{
    $record = [
        'code' => $code,
        'details' => $details,
        'created_at' => gmdate('c'),
    ];
    _stattic_runtime_write_json_atomic($privateRoot . '/runtime/repair-state.json', $record);
    // Best-effort, NON-blocking journal append: repair state is recorded from
    // serve-path reads too (e.g. a corrupt revocations.json detected during a
    // GET), and the request path must never block behind journal-lock
    // contention. repair-state.json above is the durable signal the reconcile
    // sweep reads; a skipped journal line under contention loses nothing the
    // repair flow depends on.
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'runtime_repair_required',
        'code' => $code,
        'details' => $details,
    ], false);
}

function _stattic_runtime_relative_private_path(string $privateRoot, string $path): string
{
    $realRoot = realpath($privateRoot);
    $realPath = realpath($path);
    if (!is_string($realRoot) || !is_string($realPath) || !_stattic_runtime_path_is_inside($realPath, $realRoot)) {
        return basename($path);
    }
    return ltrim(substr($realPath, strlen($realRoot)), '/');
}

// Appends one journal line. Management mutations use the default blocking
// append (at-least-once journaling under the write lock). `$blocking = false`
// is the serve-path mode (repair-state records fired from a GET): the append
// is best-effort — on lock contention it is skipped rather than ever making a
// visitor request wait on the journal.
function _stattic_runtime_append_journal(string $privateRoot, array $entry, bool $blocking = true): void
{
    $entry['created_at'] = gmdate('c');
    _stattic_runtime_mkdir($privateRoot . '/runtime');
    $path = $privateRoot . '/runtime/journal.jsonl';
    _stattic_runtime_assert_private_path($path);
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    if ($blocking) {
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        return;
    }
    $handle = @fopen($path, 'a');
    if ($handle === false) {
        return;
    }
    if (@flock($handle, LOCK_EX | LOCK_NB)) {
        @fwrite($handle, $line);
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
}

// THE POST-JSON-event-with-Bearer sender for every callback surface (the
// management journal drain and the Zero platform callbacks). Never follows
// redirects: the callback URL is vetted only as configured, and PHP would
// forward the Authorization header (a long-lived bearer credential) to
// wherever a redirect points. Returns the response HTTP status, or 0 on
// encode/transport failure or when the encoded body exceeds $maxBodyBytes.
function _spacefast_post_callback_event(string $url, string $token, array $event, int $timeoutSeconds, ?int $maxBodyBytes = null): int
{
    $body = json_encode(['event' => $event], JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || ($maxBodyBytes !== null && strlen($body) > $maxBodyBytes)) {
        return 0;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response)) {
        return 0;
    }
    return _stattic_runtime_http_response_status($http_response_header ?? []);
}

// Status code from a PHP streams $http_response_header capture; 0 when absent.
function _stattic_runtime_http_response_status(array $headers): int
{
    foreach ($headers as $header) {
        if (is_string($header) && preg_match('/^HTTP\/\S+\s+([0-9]{3})\b/', $header, $matches) === 1) {
            return (int) $matches[1];
        }
    }
    return 0;
}

function _stattic_runtime_record_management_event(string $privateRoot, array $claims, array $entry): void
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
    // Stable event id derived from the entry content: the journal id, the
    // callback file name, and the control plane's purge idempotency key
    // (replayed deliveries enqueue no duplicate purges).
    $eventId = hash(
        'sha256',
        $operationId . ':' . (string) ($entry['event'] ?? '') . ':' . json_encode($entry, JSON_UNESCAPED_SLASHES)
    );
    $entry['event_id'] = $eventId;
    _stattic_runtime_append_journal($privateRoot, $entry);

    $callbackToken = isset($claims['callback_token']) && is_string($claims['callback_token'])
        ? trim($claims['callback_token'])
        : '';
    if ($callbackToken === '') {
        return;
    }
    $callbackUrl = isset($claims['callback_url']) && is_string($claims['callback_url'])
        ? trim($claims['callback_url'])
        : '';
    if ($callbackUrl === '' || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'runtime_callback_rejected',
            'operation_id' => $operationId,
            'reason' => 'invalid_callback_url',
        ]);
        return;
    }

    $callbackEntry = [
        'id' => $eventId,
        'status' => 'pending',
        'callback_url' => $callbackUrl,
        'callback_token' => $callbackToken,
        'operation_id' => $operationId,
        'event' => $entry,
        'created_at' => gmdate('c'),
        'attempts' => 0,
    ];
    _stattic_runtime_write_json_atomic($privateRoot . '/runtime/callbacks/pending/' . $eventId . '.json', $callbackEntry);
}

function _stattic_runtime_copy_private_file(string $source, string $target): void
{
    _stattic_runtime_assert_private_path($source);
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    // Fail LOUDLY. Every caller treats a returned copy as bytes-on-disk (the
    // finalize commit path hardlinks/serves the target next); swallowing a
    // copy() failure here once produced a version that finalized 200 with a
    // file silently missing. A 500 (or the CLI-dispatch envelope) is always
    // preferable to silent corruption, whatever lane we are in.
    if (!@copy($source, $target)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_copy_failed', 'message' => 'Private file copy failed.']]);
    }
}

function _stattic_runtime_blob_key(string $sha): string
{
    $sha = strtolower(trim($sha));
    if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_blob_sha', 'message' => 'Blob sha256 is invalid.']]);
    }
    return substr($sha, 0, 2) . '/' . $sha;
}

function _stattic_runtime_blob_path(string $privateRoot, string $spaceId, string $sha): string
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    return _spacefast_space_root($privateRoot, $spaceId) . '/blobs/' . _stattic_runtime_blob_key($sha);
}

function _stattic_runtime_blob_has(string $privateRoot, string $spaceId, string $sha): bool
{
    $path = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    _stattic_runtime_assert_private_path($path);
    return is_file($path);
}

function _stattic_runtime_blob_put(string $privateRoot, string $spaceId, string $tmpPath, string $sha): void
{
    _stattic_runtime_assert_private_path($tmpPath);
    if (!is_file($tmpPath)) {
        _stattic_json_response(404, ['error' => ['code' => 'blob_source_not_found', 'message' => 'Blob source file was not found.']]);
    }
    $expected = strtolower(trim($sha));
    $actual = hash_file('sha256', $tmpPath);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        _stattic_json_response(409, ['error' => ['code' => 'blob_sha_mismatch', 'message' => 'Blob source did not match its sha256.']]);
    }

    $target = _stattic_runtime_blob_path($privateRoot, $spaceId, $expected);
    if (is_file($target)) {
        @unlink($tmpPath);
        return;
    }
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    $pending = $target . '.tmp-' . bin2hex(random_bytes(6));
    if (!@rename($tmpPath, $pending)) {
        _stattic_runtime_copy_private_file($tmpPath, $pending);
        @unlink($tmpPath);
    }
    if (!is_file($target) && !@rename($pending, $target)) {
        if (!is_file($target)) {
            @unlink($pending);
            _stattic_json_response(500, ['error' => ['code' => 'blob_put_failed', 'message' => 'Blob could not be committed.']]);
        }
    }
    @unlink($pending);
}

// One-time link() capability probe per private root (contract A3: fall back
// to copy() only when hardlinks are UNSUPPORTED, detected once — never
// because one link() call failed transiently). Probes with a scratch file so
// the answer is about the filesystem, not about any particular blob.
function _stattic_runtime_blob_link_supported(string $privateRoot, string $rootKey): bool
{
    static $supported = [];
    if (isset($supported[$rootKey])) {
        return $supported[$rootKey];
    }
    $probeDir = $privateRoot . '/runtime/link-probe';
    _stattic_runtime_mkdir($probeDir);
    $probeSource = $probeDir . '/probe-' . bin2hex(random_bytes(6));
    $probeDest = $probeSource . '-link';
    file_put_contents($probeSource, 'link-probe');
    $ok = @link($probeSource, $probeDest);
    @unlink($probeDest);
    @unlink($probeSource);
    if (!$ok) {
        _stattic_runtime_append_journal($privateRoot, ['event' => 'blob_link_unsupported']);
    }
    return $supported[$rootKey] = $ok;
}

function _stattic_runtime_blob_link(string $privateRoot, string $spaceId, string $sha, string $dest): void
{
    $source = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    _stattic_runtime_assert_private_path($source);
    if (!is_file($source)) {
        _stattic_json_response(404, ['error' => ['code' => 'blob_not_found', 'message' => 'Blob was not found.']]);
    }
    _stattic_runtime_mkdir(dirname($dest));
    _stattic_runtime_assert_private_path($dest);
    if (is_file($dest)) {
        $existing = hash_file('sha256', $dest);
        if (is_string($existing) && hash_equals(strtolower($sha), strtolower($existing))) {
            return;
        }
        @unlink($dest);
    }

    $rootKey = _stattic_runtime_real_private_root($source);
    if (_stattic_runtime_blob_link_supported($privateRoot, $rootKey)) {
        if (@link($source, $dest)) {
            return;
        }
        // EEXIST race: a concurrent link/copy landed the same content-addressed
        // bytes between our is_file() check and link().
        if (is_file($dest)) {
            $existing = hash_file('sha256', $dest);
            if (is_string($existing) && hash_equals(strtolower($sha), strtolower($existing))) {
                return;
            }
        }
        // Transient failure (EMLINK, ENOSPC, ...): copy THIS file only. The
        // capability answer is unchanged — one bad call must not silently
        // degrade the whole worker's CAS cutover to copies forever.
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'blob_link_copy_once',
            'space_id' => $spaceId,
        ]);
    }
    _stattic_runtime_copy_private_file($source, $dest);
}

function _stattic_runtime_rm_recursive(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    _stattic_runtime_assert_private_path($path);
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        _stattic_runtime_rm_recursive($path . '/' . $entry);
    }
    @rmdir($path);
}

function _stattic_runtime_assert_private_path(string $path): void
{
    $root = _stattic_runtime_real_private_root($path);
    $dir = is_dir($path) ? $path : dirname($path);
    $ancestor = $dir;
    while (!is_dir($ancestor)) {
        $next = dirname($ancestor);
        if ($next === $ancestor) {
            _stattic_json_response(500, ['error' => ['code' => 'runtime_path_unresolved', 'message' => 'Runtime path could not be resolved.']]);
        }
        $ancestor = $next;
    }
    $realAncestor = realpath($ancestor);
    if (!is_string($realAncestor) || !_stattic_runtime_path_is_inside($realAncestor, $root)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_path_escape', 'message' => 'Runtime path escaped private storage.']]);
    }
    $candidate = _stattic_runtime_normalize_absolute_path($path);
    if ($candidate === null || !_stattic_runtime_path_is_inside($candidate, $root)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_path_escape', 'message' => 'Runtime path escaped private storage.']]);
    }
}

// Recursively walks a private-root subtree, yielding each regular file's
// canonical realpath with the shared private-path safety assert applied to
// EVERY entry (a symlink inside the tree resolving outside private storage is
// rejected before the caller ever sees it) — the space-export/transfer-bundle/
// tier-disk-usage walks all hand-copied this same iterator+assert idiom, one
// of them without the per-entry assert. Callers own their own path semantics
// (relative-path construction, byte/inode accounting); this owns only the
// walk and the safety invariant.
function _stattic_runtime_walk_private_files(string $root): Generator
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        if (!is_string($real)) {
            continue;
        }
        _stattic_runtime_assert_private_path($real);
        yield $real;
    }
}

// Derives a path's root-relative form: normalize separators, strip the
// (rtrim'd) root prefix, drop the leading slash. Purely lexical — no realpath,
// no private-root anchoring (that is _stattic_runtime_relative_private_path's
// job) — so it works for any root the caller already validated; the space-
// export, transfer-bundle, and copy-tree walks each hand-rolled this exact
// incantation before it lived here.
function _stattic_runtime_relative_to(string $root, string $path): string
{
    return ltrim(substr(str_replace('\\', '/', $path), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
}

function _stattic_runtime_real_private_root(string $path): string
{
    $marker = '/.stattic/storage';
    $index = strpos(str_replace('\\', '/', $path), $marker);
    if ($index === false) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_path_unconfined', 'message' => 'Runtime path is outside private storage.']]);
    }
    $root = substr($path, 0, $index + strlen($marker));
    $realRoot = realpath($root);
    if (!is_string($realRoot)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_private_root_missing', 'message' => 'Runtime private storage is missing.']]);
    }
    return rtrim(str_replace('\\', '/', $realRoot), '/');
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

function _stattic_runtime_mime(string $path): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($extension) {
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'gz', 'tgz' => 'application/gzip',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'txt' => 'text/plain; charset=utf-8',
        // Parity with contentTypeForPath (packages/common/src/utils/content-type.ts),
        // the declared-MIME mapper every real upload path goes through: .md only.
        'md' => 'text/markdown; charset=utf-8',
        'wasm' => 'application/wasm',
        default => 'application/octet-stream',
    };
}

function _stattic_runtime_file_looks_like_gzip(string $filePath): bool
{
    $stream = fopen($filePath, 'rb');
    if ($stream === false) {
        return false;
    }
    $prefix = fread($stream, 3);
    fclose($stream);
    return $prefix === "\x1f\x8b\x08";
}

function _stattic_runtime_detect_file_mime(string $path, string $filePath, ?string $declaredMime = null): string
{
    $mime = is_string($declaredMime) && trim($declaredMime) !== ''
        ? $declaredMime
        : _stattic_runtime_mime($path);
    $normalized = strtolower(trim(explode(';', $mime, 2)[0] ?? ''));
    if (($normalized === '' || $normalized === 'application/octet-stream') && _stattic_runtime_file_looks_like_gzip($filePath)) {
        return 'application/gzip';
    }
    return $mime;
}

function _stattic_runtime_file_response_headers(string $path, array $meta): array
{
    // The fingerprint heuristic is regex-heavy and runs for every uploaded
    // file at finalize — evaluate it once and derive all three cache headers.
    $immutable = _stattic_runtime_is_immutable_asset_path($path);
    $edgeCacheControl = $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=31536000, must-revalidate';
    $headers = [
        'Content-Type' => _stattic_safe_content_type($path, (string) ($meta['mime'] ?? 'application/octet-stream')),
        'ETag' => '"' . (string) ($meta['sha256'] ?? '') . '"',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate',
        'CDN-Cache-Control' => $edgeCacheControl,
        'Surrogate-Control' => $edgeCacheControl,
        'Last-Modified' => (string) ($meta['last_modified'] ?? ''),
    ];

    return array_filter($headers, static fn ($value): bool => is_string($value) && $value !== '' && $value !== '""');
}

function _stattic_runtime_mtime_for_hash(string $sha256): int
{
    $prefix = substr(strtolower($sha256), 0, 12);
    if (!preg_match('/^[a-f0-9]{12}$/', $prefix)) {
        return 0;
    }
    return (int) (hexdec($prefix) % 1450000000);
}

function _stattic_runtime_http_date(int $timestamp): string
{
    return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
}

// Single source of truth for the demote-eligibility size floor: the demoter
// (admin/tier.php) gates on tier_class alone rather than re-checking size, so
// this constant lives where tier_class is stamped, not duplicated at the
// demote call site. D21: env-overridable (default unchanged).
const STATTIC_TIER_MIN_DEMOTE_BYTES_DEFAULT = 32768;

function _stattic_runtime_tier_min_demote_bytes(): int
{
    return _stattic_config_int('SPACEFAST_TIER_MIN_DEMOTE_BYTES', STATTIC_TIER_MIN_DEMOTE_BYTES_DEFAULT, 0);
}

// tier_class is DECLARED by the producer, never sniffed from the path: shared
// storage must not know feature vocabulary, and any substring heuristic
// over-reaches user space (a user file under a directory named `zero/` is a
// user file, not an engine artifact). Engine-artifact producers pass
// `artifact: true`; user-file producers pass nothing. tier_class is baked per
// version at finalize, so versions finalized before this contract keep their
// old classification on disk — only new finalizes are reclassified.
function _stattic_runtime_tier_class(int $size, bool $artifact): string
{
    if ($size < _stattic_runtime_tier_min_demote_bytes()) {
        return 'small';
    }
    return $artifact ? 'artifact' : 'eligible';
}

function _stattic_runtime_build_file_meta(string $path, int $size, string $mime, string $sha256, bool $artifact = false): array
{
    $mtime = _stattic_runtime_mtime_for_hash($sha256);
    $meta = [
        'disk_path' => $path,
        'size' => $size,
        'mime' => $mime,
        'sha256' => $sha256,
        'mtime' => $mtime,
        'last_modified' => _stattic_runtime_http_date($mtime),
        'methods' => ['GET', 'HEAD'],
        'executable' => false,
        'forced_download_or_text' => _stattic_path_is_php_like($path),
        'local' => true,
        'tier_class' => _stattic_runtime_tier_class($size, $artifact),
    ];
    $meta['headers'] = _stattic_runtime_file_response_headers($path, $meta);
    return $meta;
}
