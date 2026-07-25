<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/upload-policy.php';
require_once __DIR__ . '/auth.php';

const STATTIC_RUNTIME_UPLOAD_BATCH_MAX_FILES = 500;
const STATTIC_RUNTIME_UPLOAD_BATCH_MAX_BYTES = 8388608;
const STATTIC_RUNTIME_UPLOAD_MAX_PARTS = 10000;
// Fail-safe caps for open (manifestless) sessions when the control plane sends none.
const STATTIC_RUNTIME_OPEN_UPLOAD_MAX_TOTAL_BYTES = 2147483648;
const STATTIC_RUNTIME_OPEN_UPLOAD_MAX_FILE_COUNT = 100000;
const STATTIC_RUNTIME_UPLOAD_SOURCE_URL_TIMEOUT_SECONDS = 30;
const STATTIC_RUNTIME_UPLOAD_SOURCE_URL_CONNECT_TIMEOUT_SECONDS = 5;

// Routing lives in the unified admin dispatcher (admin/api.php): its
// upload-lane rows declare the S3-shaped route patterns and lazily require
// this module — the bearer/JWT gate, the S3-control-operation guard, and the
// ingest handlers — when one matches.

function _stattic_runtime_require_upload_authorization(string $privateRoot): array
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($authorization, 'Bearer ')) {
        _stattic_json_response(401, ['error' => ['code' => 'runtime_upload_bearer_required', 'message' => 'Runtime uploads require Authorization: Bearer.']]);
    }
    return _stattic_runtime_require_upload_jwt($privateRoot);
}

function _stattic_runtime_reject_s3_control_operation(string $allowedMethod): void
{
    $query = _stattic_runtime_non_platform_upload_query();
    if ($query !== []) {
        _stattic_runtime_unsupported_s3_operation($allowedMethod);
    }

    foreach ([
        'HTTP_X_AMZ_COPY_SOURCE',
        'HTTP_X_AMZ_ACL',
        'HTTP_X_AMZ_TAGGING',
        'HTTP_X_AMZ_WEBSITE_REDIRECT_LOCATION',
    ] as $header) {
        if (isset($_SERVER[$header]) && (string) $_SERVER[$header] !== '') {
            _stattic_runtime_unsupported_s3_operation($allowedMethod);
        }
    }
}

function _stattic_runtime_non_platform_upload_query(): array
{
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query === '') {
        return [];
    }
    $unsupported = [];
    foreach (explode('&', $query) as $part) {
        $name = rawurldecode(str_replace('+', '%20', explode('=', $part, 2)[0] ?? ''));
        if (!in_array($name, ['op', 'upload_id', 'path', 'part_number', 'complete'], true)) {
            $unsupported[] = $name;
        }
    }
    return $unsupported;
}

function _stattic_runtime_unsupported_s3_operation(string $allowedMethod): void
{
    header('Allow: ' . $allowedMethod, false);
    _stattic_json_response(405, [
        'error' => [
            'code' => 'runtime_upload_operation_not_supported',
            'message' => 'Runtime upload only supports scoped file and batch uploads.',
        ],
    ]);
}

// Shared handler prologue: validates the upload id exactly once, loads and
// scope-asserts the session, and (when $encodedPath is given) resolves the
// canonical file path.
function _stattic_runtime_upload_request(string $privateRoot, string $uploadId, array $claims, ?string $encodedPath = null): array
{
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    return [
        'upload_id' => $uploadId,
        'session' => _stattic_runtime_upload_session($privateRoot, $uploadId, $claims),
        'file_path' => $encodedPath === null ? null : _stattic_runtime_canonical_upload_path($encodedPath),
    ];
}

function _stattic_runtime_upload_session(string $privateRoot, string $uploadId, array $claims): array
{
    $sessionPath = $privateRoot . '/runtime/uploads/' . $uploadId . '/session.json';
    $session = _stattic_runtime_read_json($sessionPath);
    if (!is_array($session)) {
        _stattic_json_response(404, ['error' => ['code' => 'upload_not_found', 'message' => 'Upload session not found.']]);
    }
    _stattic_runtime_assert_upload_scope($uploadId, $session, $claims);
    return $session;
}

function _stattic_runtime_upload_session_mode(array $session): string
{
    return ($session['session_mode'] ?? 'declared') === 'open' ? 'open' : 'declared';
}

function _stattic_runtime_assert_upload_scope(string $uploadId, array $session, array $claims): void
{
    foreach ([
        'deploy_session_id' => $uploadId,
        'space_id' => (string) ($session['space_id'] ?? ''),
        'version_id' => (string) ($session['version_id'] ?? ''),
        'session_mode' => _stattic_runtime_upload_session_mode($session),
    ] as $key => $value) {
        $actual = isset($claims[$key]) ? (string) $claims[$key] : '';
        if ($actual === '' || !hash_equals($value, $actual)) {
            _stattic_json_response(403, ['error' => ['code' => 'upload_scope_forbidden', 'message' => 'Upload token scope does not match this session (claim: ' . $key . ').']]);
        }
    }
}

function _stattic_runtime_declared_upload_file(array $session, string $filePath): ?array
{
    static $indexUploadId = null;
    static $index = [];
    $uploadId = (string) ($session['upload_id'] ?? '');
    if ($uploadId !== $indexUploadId) {
        $index = [];
        foreach (($session['files'] ?? []) as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && !isset($index[$file['path']])) {
                $index[$file['path']] = $file;
            }
        }
        $indexUploadId = $uploadId;
    }
    return $index[$filePath] ?? null;
}

function _stattic_runtime_expected_upload_file(array $session, string $filePath): array
{
    $file = _stattic_runtime_declared_upload_file($session, $filePath);
    if ($file === null) {
        _stattic_json_response(422, ['error' => [
            'code' => 'upload_path_not_declared',
            'message' => 'Path ' . $filePath . ' was not declared in this version\'s manifest.',
            'details' => ['path' => $filePath],
        ]]);
    }
    return $file;
}

function _stattic_runtime_canonical_upload_path(string $encodedPath): string
{
    $filePath = _stattic_runtime_file_path(rawurldecode($encodedPath));
    _stattic_runtime_assert_static_upload_path($filePath);
    $canonicalEncodedPath = str_replace('%2F', '/', rawurlencode($filePath));
    if (!hash_equals($canonicalEncodedPath, $encodedPath)) {
        _stattic_json_response(403, ['error' => ['code' => 'upload_path_not_canonical', 'message' => 'Upload request path must use canonical encoding.']]);
    }
    return $filePath;
}

function _stattic_runtime_upload_target(string $privateRoot, string $uploadId, string $filePath): string
{
    $target = $privateRoot . '/runtime/uploads/' . $uploadId . '/files/' . $filePath;
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    return $target;
}

function _stattic_runtime_upload_tmp_path(string $path): string
{
    return $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
}

// Streams every read handle $nextHandle yields into $tmpPath while computing a
// streaming sha256 over the concatenation: null ends the sequence, false is a
// handle that could not be opened. Responds with the given error once more than
// $limit bytes have arrived, closing the open handles and unlinking $tmpPath on
// every failure.
function _stattic_runtime_stream_handles_to_tmp(string $tmpPath, callable $nextHandle, int $limit, int $overflowStatus, string $overflowCode, string $overflowMessage): array
{
    $output = fopen($tmpPath, 'wb');
    if ($output === false) {
        _stattic_json_response(500, ['error' => ['code' => 'upload_open_failed', 'message' => 'Could not open upload streams.']]);
    }
    $context = hash_init('sha256');
    $size = 0;
    while (($input = $nextHandle()) !== null) {
        if ($input === false) {
            fclose($output);
            @unlink($tmpPath);
            _stattic_json_response(500, ['error' => ['code' => 'upload_open_failed', 'message' => 'Could not open upload streams.']]);
        }
        while (($chunk = fread($input, 65536)) !== false && $chunk !== '') {
            $size += strlen($chunk);
            if ($size > $limit) {
                fclose($input);
                fclose($output);
                @unlink($tmpPath);
                _stattic_json_response($overflowStatus, ['error' => ['code' => $overflowCode, 'message' => $overflowMessage]]);
            }
            hash_update($context, $chunk);
            if (fwrite($output, $chunk) === false) {
                fclose($input);
                fclose($output);
                @unlink($tmpPath);
                _stattic_json_response(500, ['error' => ['code' => 'upload_write_failed', 'message' => 'Uploaded bytes could not be written.']]);
            }
        }
        fclose($input);
    }
    fclose($output);
    return ['size' => $size, 'sha256' => hash_final($context)];
}

// Streams the request body to $tmpPath while computing a streaming sha256.
// Responds with the given error once more than $limit bytes arrive.
function _stattic_runtime_stream_upload_to_tmp(string $tmpPath, int $limit, int $overflowStatus, string $overflowCode, string $overflowMessage): array
{
    $handles = [_stattic_binary_body_stream()];
    return _stattic_runtime_stream_handles_to_tmp($tmpPath, static function () use (&$handles) {
        return $handles === [] ? null : array_shift($handles);
    }, $limit, $overflowStatus, $overflowCode, $overflowMessage);
}

function _stattic_runtime_verify_declared_upload(string $tmpPath, string $filePath, array $expected, array $streamed): void
{
    if ((int) $streamed['size'] !== (int) $expected['size']) {
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => [
            'code' => 'upload_size_mismatch',
            'message' => 'Uploaded file size does not match the manifest for ' . $filePath . ': declared ' . (int) $expected['size'] . ' bytes, received ' . (int) $streamed['size'] . '.',
            'details' => ['path' => $filePath, 'declared_size' => (int) $expected['size'], 'received_size' => (int) $streamed['size']],
        ]]);
    }
    if (isset($expected['sha256']) && is_string($expected['sha256']) && !hash_equals(strtolower($expected['sha256']), strtolower((string) $streamed['sha256']))) {
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => [
            'code' => 'upload_hash_mismatch',
            'message' => 'Uploaded file hash does not match the manifest for ' . $filePath . ': declared ' . strtolower((string) $expected['sha256']) . ', received ' . strtolower((string) $streamed['sha256']) . '.',
            'details' => ['path' => $filePath, 'declared_sha256' => strtolower((string) $expected['sha256']), 'received_sha256' => strtolower((string) $streamed['sha256'])],
        ]]);
    }
}

// Open (manifestless) sessions: plan-policy caps live in session state, never in the JWT.
function _stattic_runtime_open_upload_caps(array $session): array
{
    $maxBytes = (int) ($session['max_total_bytes'] ?? 0);
    $maxFiles = (int) ($session['max_file_count'] ?? 0);
    return [
        'max_total_bytes' => $maxBytes > 0 ? $maxBytes : STATTIC_RUNTIME_OPEN_UPLOAD_MAX_TOTAL_BYTES,
        'max_file_count' => $maxFiles > 0 ? $maxFiles : STATTIC_RUNTIME_OPEN_UPLOAD_MAX_FILE_COUNT,
    ];
}

function _stattic_runtime_open_upload_cap_exceeded(): void
{
    _stattic_json_response(413, ['error' => ['code' => 'upload_session_cap_exceeded', 'message' => 'Upload exceeds the open session byte or file-count limits.']]);
}

function _stattic_runtime_uploaded_ledger_path(string $privateRoot, string $uploadId): string
{
    return $privateRoot . '/runtime/uploads/' . $uploadId . '/uploaded.json';
}

// path => ['path' => ..., 'size' => ..., 'sha256' => ...] for every committed upload of
// the session so far — the runtime-truth ledger resume reads (spec: declared sessions
// track uploaded status runtime-side, same as open sessions).
function _stattic_runtime_uploaded_files(string $privateRoot, string $uploadId): array
{
    $ledger = _stattic_runtime_read_json(_stattic_runtime_uploaded_ledger_path($privateRoot, $uploadId));
    $files = is_array($ledger) && is_array($ledger['files'] ?? null) ? $ledger['files'] : [];
    $uploaded = [];
    foreach ($files as $path => $entry) {
        if (is_string($path) && is_array($entry)) {
            $uploaded[$path] = $entry;
        }
    }
    return $uploaded;
}

function _stattic_runtime_with_upload_session_lock(string $privateRoot, string $uploadId, callable $callback): void
{
    $lockPath = $privateRoot . '/runtime/uploads/' . $uploadId . '/ledger.lock';
    _stattic_runtime_assert_private_path($lockPath);
    $handle = @fopen($lockPath, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        _stattic_json_response(503, ['error' => ['code' => 'upload_session_lock_unavailable', 'message' => 'Upload session lock is unavailable.']]);
    }
    try {
        $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Bytes staged on disk as chunked-upload parts, keyed by part root.
function _stattic_runtime_open_staged_part_bytes(string $privateRoot, string $uploadId): array
{
    $byRoot = [];
    foreach (glob($privateRoot . '/runtime/uploads/' . $uploadId . '/parts/*/*.part', GLOB_NOSORT) ?: [] as $path) {
        $root = dirname($path);
        $byRoot[$root] = ($byRoot[$root] ?? 0) + max(0, (int) @filesize($path));
    }
    return $byRoot;
}

// Session disk usage charged against the open-session caps: committed ledger bytes plus
// staged chunked-upload parts, excluding $filePath's own committed entry. Each in-flight
// part root other than $filePath's reserves a file slot (conservatively, even if its path
// was already committed). $excludeOwnParts skips $filePath's staged parts for callers that
// are about to turn them into the file itself.
function _stattic_runtime_open_upload_usage(string $privateRoot, string $uploadId, string $filePath, bool $excludeOwnParts, ?array $uploaded = null, ?array $stagedPartBytes = null): array
{
    $uploaded ??= _stattic_runtime_uploaded_files($privateRoot, $uploadId);
    $ownPartRoot = _stattic_runtime_upload_part_root($privateRoot, $uploadId, $filePath);
    $bytes = 0;
    foreach ($uploaded as $path => $entry) {
        if ($path !== $filePath) {
            $bytes += max(0, (int) ($entry['size'] ?? 0));
        }
    }
    $fileCount = count($uploaded) + (isset($uploaded[$filePath]) ? 0 : 1);
    foreach ($stagedPartBytes ?? _stattic_runtime_open_staged_part_bytes($privateRoot, $uploadId) as $root => $rootBytes) {
        if ($root === $ownPartRoot) {
            if (!$excludeOwnParts) {
                $bytes += $rootBytes;
            }
            continue;
        }
        $bytes += $rootBytes;
        $fileCount += 1;
    }
    return ['bytes' => $bytes, 'file_count' => $fileCount];
}

// Bytes this file may still occupy; also fails early on the file-count cap.
function _stattic_runtime_open_upload_allowance(string $privateRoot, string $uploadId, array $session, string $filePath, bool $excludeOwnParts = false): int
{
    $caps = _stattic_runtime_open_upload_caps($session);
    $usage = _stattic_runtime_open_upload_usage($privateRoot, $uploadId, $filePath, $excludeOwnParts);
    if ($usage['file_count'] > $caps['max_file_count']) {
        _stattic_runtime_open_upload_cap_exceeded();
    }
    return max(0, $caps['max_total_bytes'] - $usage['bytes']);
}

// Rechecks caps under the session lock, installs the file, and records it in the ledger
// so finalize can derive the manifest from what was actually uploaded.
function _stattic_runtime_commit_open_upload(string $privateRoot, string $uploadId, array $session, string $filePath, string $tmpPath, string $target, array $streamed): void
{
    $caps = _stattic_runtime_open_upload_caps($session);
    _stattic_runtime_with_upload_session_lock($privateRoot, $uploadId, static function () use ($privateRoot, $uploadId, $caps, $filePath, $tmpPath, $streamed, $target): void {
        $uploaded = _stattic_runtime_uploaded_files($privateRoot, $uploadId);
        $usage = _stattic_runtime_open_upload_usage($privateRoot, $uploadId, $filePath, true, $uploaded);
        if ($usage['bytes'] + (int) $streamed['size'] > $caps['max_total_bytes'] || $usage['file_count'] > $caps['max_file_count']) {
            @unlink($tmpPath);
            _stattic_runtime_open_upload_cap_exceeded();
        }
        rename($tmpPath, $target);
        _stattic_runtime_record_uploaded_file($privateRoot, $uploadId, $filePath, $streamed, $uploaded);
    });
}

// Appends one committed file to the session's uploaded ledger and returns the
// updated ledger. Callers must hold the session lock or accept last-writer-wins
// for retried PUTs of the same path. Callers that already parsed the ledger
// under the lock pass it as $uploaded.
function _stattic_runtime_record_uploaded_file(string $privateRoot, string $uploadId, string $filePath, array $streamed, ?array $uploaded = null): array
{
    $uploaded ??= _stattic_runtime_uploaded_files($privateRoot, $uploadId);
    $uploaded[$filePath] = ['path' => $filePath, 'size' => (int) $streamed['size'], 'sha256' => (string) $streamed['sha256']];
    _stattic_runtime_write_json_atomic(_stattic_runtime_uploaded_ledger_path($privateRoot, $uploadId), ['files' => $uploaded]);
    return $uploaded;
}

// Declared sessions track per-file uploaded status runtime-side too, so resume can
// return runtime truth instead of control-plane guesses (spec "Upload Contract").
function _stattic_runtime_commit_declared_upload(string $privateRoot, string $uploadId, string $filePath, string $tmpPath, string $target, array $streamed): void
{
    _stattic_runtime_with_upload_session_lock($privateRoot, $uploadId, static function () use ($privateRoot, $uploadId, $filePath, $tmpPath, $target, $streamed): void {
        rename($tmpPath, $target);
        _stattic_runtime_record_uploaded_file($privateRoot, $uploadId, $filePath, $streamed);
    });
}

// Shared open-vs-declared ingest tail for the single-file handlers: $streamToTmp
// receives (tmpPath, byte limit, open?) and returns the streamed size+sha256;
// open sessions cap-check and commit to the ledger, declared sessions verify the
// bytes against the manifest before committing. Returns the streamed result.
function _stattic_runtime_ingest_upload(string $privateRoot, string $uploadId, array $session, string $filePath, callable $streamToTmp, bool $excludeOwnParts = false): array
{
    $target = _stattic_runtime_upload_target($privateRoot, $uploadId, $filePath);
    $tmpPath = _stattic_runtime_upload_tmp_path($target);
    if (_stattic_runtime_upload_session_mode($session) === 'open') {
        $allowance = _stattic_runtime_open_upload_allowance($privateRoot, $uploadId, $session, $filePath, $excludeOwnParts);
        $streamed = $streamToTmp($tmpPath, $allowance, true);
        _stattic_runtime_commit_open_upload($privateRoot, $uploadId, $session, $filePath, $tmpPath, $target, $streamed);
    } else {
        $expected = _stattic_runtime_expected_upload_file($session, $filePath);
        $streamed = $streamToTmp($tmpPath, (int) $expected['size'], false);
        _stattic_runtime_verify_declared_upload($tmpPath, $filePath, $expected, $streamed);
        _stattic_runtime_commit_declared_upload($privateRoot, $uploadId, $filePath, $tmpPath, $target, $streamed);
    }
    return $streamed;
}

function _stattic_runtime_upload_file(string $privateRoot, string $uploadId, string $encodedPath, array $claims): void
{
    ['upload_id' => $uploadId, 'session' => $session, 'file_path' => $filePath] = _stattic_runtime_upload_request($privateRoot, $uploadId, $claims, $encodedPath);
    $streamed = _stattic_runtime_ingest_upload($privateRoot, $uploadId, $session, $filePath, static function (string $tmpPath, int $limit, bool $open): array {
        return $open
            ? _stattic_runtime_stream_upload_to_tmp($tmpPath, $limit, 413, 'upload_session_cap_exceeded', 'Upload exceeds the open session byte limit.')
            : _stattic_runtime_stream_upload_to_tmp($tmpPath, $limit, 422, 'upload_size_mismatch', 'Uploaded file exceeds the declared size.');
    });
    header('ETag: "' . $streamed['sha256'] . '"', false);
    _stattic_json_response(200, ['ok' => true]);
}

function _stattic_runtime_fetch_url_from_body(): array
{
    $body = _stattic_json_body();
    $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
    if ($url === '') {
        _stattic_json_response(400, ['error' => ['code' => 'upload_source_url_required', 'message' => 'URL upload requires a non-empty url.']]);
    }
    return _stattic_runtime_assert_fetch_url($url);
}

function _stattic_runtime_assert_fetch_url(string $url): array
{
    $parts = parse_url($url);
    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
    $host = is_array($parts) && is_string($parts['host'] ?? null) ? trim((string) $parts['host']) : '';
    if (!in_array($scheme, ['https'], true) || $host === '') {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_invalid', 'message' => 'URL uploads require an absolute HTTPS URL.']]);
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_invalid', 'message' => 'URL uploads must not include credentials.']]);
    }
    if (!_stattic_egress_host_allowed($host)) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_forbidden', 'message' => 'URL upload host is not allowed.']]);
    }
    $ips = _stattic_egress_resolve_public_ips($host);
    if ($ips === null) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_unresolvable', 'message' => 'URL upload host could not be resolved.']]);
    }
    $port = (int) ($parts['port'] ?? 443);
    if ($port < 1 || $port > 65535) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_invalid', 'message' => 'URL upload port is invalid.']]);
    }
    return [
        'url' => $url,
        'host' => strtolower(trim($host, '[]')),
        'port' => $port,
        'resolve' => _stattic_egress_curl_resolve_entries($host, $port, $ips),
    ];
}

function _stattic_runtime_stream_url_to_tmp(array $source, string $tmpPath, int $limit): array
{
    $output = fopen($tmpPath, 'wb');
    if ($output === false) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_fetch_failed', 'message' => 'Could not fetch the source URL.']]);
    }
    if (!function_exists('curl_init')) {
        fclose($output);
        @unlink($tmpPath);
        _stattic_json_response(500, ['error' => ['code' => 'upload_source_url_fetch_unavailable', 'message' => 'URL uploads require curl support.']]);
    }

    $contextHash = hash_init('sha256');
    $size = 0;
    $status = 0;
    $tooLarge = false;
    $writeFailed = false;
    $ch = curl_init((string) $source['url']);
    if ($ch === false) {
        fclose($output);
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_fetch_failed', 'message' => 'Could not fetch the source URL.']]);
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => STATTIC_RUNTIME_UPLOAD_SOURCE_URL_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => STATTIC_RUNTIME_UPLOAD_SOURCE_URL_TIMEOUT_SECONDS,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => is_array($source['resolve'] ?? null) ? $source['resolve'] : [],
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'User-Agent: Spacefast-Runtime-Upload/1',
        ],
    ]);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $headerLine) use (&$status, &$tooLarge, $limit): int {
        $trimmed = trim($headerLine);
        if (preg_match('/^HTTP\/\S+\s+([0-9]{3})\b/', $trimmed, $matches) === 1) {
            $status = (int) $matches[1];
            return strlen($headerLine);
        }
        if (preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', $trimmed, $matches) === 1 && (int) $matches[1] > $limit) {
            $tooLarge = true;
            return 0;
        }
        return strlen($headerLine);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($output, $limit, &$contextHash, &$size, &$status, &$tooLarge, &$writeFailed): int {
        $length = strlen($chunk);
        if ($status < 200 || $status >= 300) {
            return $length;
        }
        $size += $length;
        if ($size > $limit) {
            $tooLarge = true;
            return 0;
        }
        hash_update($contextHash, $chunk);
        if (fwrite($output, $chunk) === false) {
            $writeFailed = true;
            return 0;
        }
        return $length;
    });
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    fclose($output);

    if ($tooLarge) {
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => ['code' => 'upload_size_mismatch', 'message' => 'Fetched file exceeds the declared size.']]);
    }
    if ($writeFailed) {
        @unlink($tmpPath);
        _stattic_json_response(500, ['error' => ['code' => 'upload_write_failed', 'message' => 'Fetched bytes could not be written.']]);
    }
    if ($ok === false || $errno !== 0) {
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_fetch_failed', 'message' => 'Could not fetch the source URL.']]);
    }
    if ($status < 200 || $status >= 300) {
        @unlink($tmpPath);
        _stattic_json_response(422, ['error' => ['code' => 'upload_source_url_fetch_failed', 'message' => 'Source URL did not return a successful response.']]);
    }
    return ['size' => $size, 'sha256' => hash_final($contextHash)];
}

function _stattic_runtime_upload_file_from_url(string $privateRoot, string $uploadId, string $encodedPath, array $claims): void
{
    ['upload_id' => $uploadId, 'session' => $session, 'file_path' => $filePath] = _stattic_runtime_upload_request($privateRoot, $uploadId, $claims, $encodedPath);
    $source = _stattic_runtime_fetch_url_from_body();
    $streamed = _stattic_runtime_ingest_upload($privateRoot, $uploadId, $session, $filePath, static function (string $tmpPath, int $limit, bool $open) use ($source): array {
        return _stattic_runtime_stream_url_to_tmp($source, $tmpPath, $limit);
    });
    header('ETag: "' . $streamed['sha256'] . '"', false);
    _stattic_json_response(200, ['ok' => true]);
}

function _stattic_runtime_upload_file_part(string $privateRoot, string $uploadId, string $encodedPath, int $partNumber, array $claims): void
{
    ['upload_id' => $uploadId, 'session' => $session] = _stattic_runtime_upload_request($privateRoot, $uploadId, $claims);
    $filePath = _stattic_runtime_canonical_upload_path($encodedPath);
    if ($partNumber < 1 || $partNumber > STATTIC_RUNTIME_UPLOAD_MAX_PARTS) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_part_number_invalid', 'message' => 'Upload part number is out of range.']]);
    }

    $partRoot = _stattic_runtime_upload_part_root($privateRoot, $uploadId, $filePath);
    $partPath = $partRoot . '/' . $partNumber . '.part';
    _stattic_runtime_mkdir($partRoot);
    _stattic_runtime_assert_private_path($partPath);
    if (!is_file($partRoot . '/path.json')) {
        _stattic_runtime_write_json_atomic($partRoot . '/path.json', ['path' => $filePath]);
    }
    $tmpPath = _stattic_runtime_upload_tmp_path($partPath);
    if (_stattic_runtime_upload_session_mode($session) === 'open') {
        // Staged parts are charged against the session caps like committed files, so an
        // attacker cannot stage unbounded part data without ever calling /complete. A
        // retried part gets the bytes of the part it replaces back. The check repeats
        // under the session lock before the part is persisted.
        $replacedBytes = max(0, (int) @filesize($partPath));
        $allowance = _stattic_runtime_open_upload_allowance($privateRoot, $uploadId, $session, $filePath) + $replacedBytes;
        $streamed = _stattic_runtime_stream_upload_to_tmp($tmpPath, $allowance, 413, 'upload_session_cap_exceeded', 'Upload part exceeds the open session byte limit.');
        $caps = _stattic_runtime_open_upload_caps($session);
        _stattic_runtime_with_upload_session_lock($privateRoot, $uploadId, static function () use ($privateRoot, $uploadId, $caps, $filePath, $tmpPath, $partPath, $streamed): void {
            $usage = _stattic_runtime_open_upload_usage($privateRoot, $uploadId, $filePath, false);
            $replacedBytes = max(0, (int) @filesize($partPath));
            if ($usage['bytes'] - $replacedBytes + (int) $streamed['size'] > $caps['max_total_bytes'] || $usage['file_count'] > $caps['max_file_count']) {
                @unlink($tmpPath);
                _stattic_runtime_open_upload_cap_exceeded();
            }
            rename($tmpPath, $partPath);
        });
    } else {
        $expected = _stattic_runtime_expected_upload_file($session, $filePath);
        _stattic_runtime_stream_upload_to_tmp($tmpPath, (int) $expected['size'], 422, 'upload_size_mismatch', 'Uploaded part exceeds the declared file size.');
        rename($tmpPath, $partPath);
    }
    _stattic_json_response(200, ['ok' => true, 'part_number' => $partNumber]);
}

function _stattic_runtime_collect_upload_part_numbers(string $partRoot): array
{
    $partNumbers = [];
    foreach (glob($partRoot . '/*.part') ?: [] as $path) {
        $partNumbers[] = (int) basename((string) $path, '.part');
    }
    sort($partNumbers, SORT_NUMERIC);
    if ($partNumbers === [] || $partNumbers !== range(1, count($partNumbers))) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_parts_incomplete', 'message' => 'Chunked upload parts are missing or non-contiguous.']]);
    }
    return $partNumbers;
}

// Concatenates contiguous parts into $tmpPath while computing a streaming sha256.
function _stattic_runtime_assemble_upload_parts(string $partRoot, array $partNumbers, string $tmpPath, int $limit, int $overflowStatus, string $overflowCode, string $overflowMessage): array
{
    return _stattic_runtime_stream_handles_to_tmp($tmpPath, static function () use ($partRoot, &$partNumbers) {
        return $partNumbers === [] ? null : fopen($partRoot . '/' . array_shift($partNumbers) . '.part', 'rb');
    }, $limit, $overflowStatus, $overflowCode, $overflowMessage);
}

function _stattic_runtime_complete_chunked_upload(string $privateRoot, string $uploadId, string $encodedPath, array $claims): void
{
    ['upload_id' => $uploadId, 'session' => $session, 'file_path' => $filePath] = _stattic_runtime_upload_request($privateRoot, $uploadId, $claims, $encodedPath);
    if (_stattic_runtime_upload_session_mode($session) !== 'open') {
        _stattic_runtime_expected_upload_file($session, $filePath);
    }

    $partRoot = _stattic_runtime_upload_part_root($privateRoot, $uploadId, $filePath);
    $partNumbers = [];
    // The staged parts become the assembled file, so they are excluded from usage here.
    $streamed = _stattic_runtime_ingest_upload($privateRoot, $uploadId, $session, $filePath, static function (string $tmpPath, int $limit, bool $open) use ($partRoot, &$partNumbers): array {
        $partNumbers = _stattic_runtime_collect_upload_part_numbers($partRoot);
        return $open
            ? _stattic_runtime_assemble_upload_parts($partRoot, $partNumbers, $tmpPath, $limit, 413, 'upload_session_cap_exceeded', 'Upload exceeds the open session byte limit.')
            : _stattic_runtime_assemble_upload_parts($partRoot, $partNumbers, $tmpPath, $limit, 422, 'upload_size_mismatch', 'Uploaded file exceeds the declared size.');
    }, true);
    _stattic_runtime_rm_recursive($partRoot);
    header('ETag: "' . $streamed['sha256'] . '"', false);
    _stattic_json_response(200, ['ok' => true, 'part_count' => count($partNumbers)]);
}

function _stattic_runtime_upload_part_root(string $privateRoot, string $uploadId, string $filePath): string
{
    return $privateRoot . '/runtime/uploads/' . $uploadId . '/parts/' . hash('sha256', $filePath);
}

function _stattic_runtime_upload_batch(string $privateRoot, string $uploadId, array $claims): void
{
    ['upload_id' => $uploadId, 'session' => $session] = _stattic_runtime_upload_request($privateRoot, $uploadId, $claims);
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength <= 0 || $contentLength > STATTIC_RUNTIME_UPLOAD_BATCH_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'upload_batch_too_large', 'message' => 'Batch upload exceeds the runtime size limit.']]);
    }
    $input = _stattic_binary_body_stream();
    if ($input === false) {
        _stattic_json_response(500, ['error' => ['code' => 'upload_batch_open_failed', 'message' => 'Could not read batch upload.']]);
    }
    $uploaded = _stattic_runtime_store_tar_upload_batch($privateRoot, $uploadId, $session, $input);
    _stattic_json_response(200, ['ok' => true, 'uploaded' => $uploaded]);
}

// Consumes the request body as a sequential tar stream, committing entries under one
// session lock with the ledger and staged-part usage held in memory: the ledger is
// still rewritten after every committed entry (mid-batch errors leave prior entries
// visible for resume/finalize) but is never re-read, re-globbed, or re-locked per file.
function _stattic_runtime_store_tar_upload_batch(string $privateRoot, string $uploadId, array $session, $handle): int
{
    $open = _stattic_runtime_upload_session_mode($session) === 'open';
    $caps = _stattic_runtime_open_upload_caps($session);
    $uploaded = 0;
    _stattic_runtime_with_upload_session_lock($privateRoot, $uploadId, static function () use ($privateRoot, $uploadId, $session, $handle, $open, $caps, &$uploaded): void {
        $ledger = _stattic_runtime_uploaded_files($privateRoot, $uploadId);
        $stagedPartBytes = _stattic_runtime_open_staged_part_bytes($privateRoot, $uploadId);
        $totalBytes = 0;
        while (!feof($handle)) {
            $header = fread($handle, 512);
            if ($header === '' || $header === false) {
                break;
            }
            $totalBytes += strlen($header);
            if ($totalBytes > STATTIC_RUNTIME_UPLOAD_BATCH_MAX_BYTES) {
                fclose($handle);
                _stattic_json_response(413, ['error' => ['code' => 'upload_batch_too_large', 'message' => 'Batch upload exceeds the runtime size limit.']]);
            }
            if (strlen($header) !== 512) {
                fclose($handle);
                _stattic_json_response(422, ['error' => ['code' => 'upload_batch_invalid', 'message' => 'Batch tar header is incomplete.']]);
            }
            if ($header === str_repeat("\0", 512)) {
                break;
            }
            $entry = _stattic_runtime_tar_header($header);
            if ($entry['type'] !== '' && $entry['type'] !== '0') {
                fclose($handle);
                _stattic_json_response(422, ['error' => ['code' => 'upload_batch_invalid_entry', 'message' => 'Batch upload accepts only regular files.']]);
            }
            $filePath = _stattic_runtime_file_path($entry['path']);
            _stattic_runtime_assert_static_upload_path($filePath);
            $expected = null;
            if ($open) {
                $usage = _stattic_runtime_open_upload_usage($privateRoot, $uploadId, $filePath, false, $ledger, $stagedPartBytes);
                if ($usage['file_count'] > $caps['max_file_count'] || $entry['size'] > max(0, $caps['max_total_bytes'] - $usage['bytes'])) {
                    fclose($handle);
                    _stattic_runtime_open_upload_cap_exceeded();
                }
            } else {
                $expected = _stattic_runtime_expected_upload_file($session, $filePath);
            }
            $target = _stattic_runtime_upload_target($privateRoot, $uploadId, $filePath);
            $tmpPath = _stattic_runtime_upload_tmp_path($target);
            $output = fopen($tmpPath, 'wb');
            if ($output === false) {
                fclose($handle);
                _stattic_json_response(500, ['error' => ['code' => 'upload_open_failed', 'message' => 'Could not open upload streams.']]);
            }
            $context = hash_init('sha256');
            $remaining = $entry['size'];
            while ($remaining > 0) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === '' || $chunk === false) {
                    fclose($output);
                    fclose($handle);
                    @unlink($tmpPath);
                    _stattic_json_response(422, ['error' => ['code' => 'upload_batch_invalid', 'message' => 'Batch tar file ended before entry content completed.']]);
                }
                $totalBytes += strlen($chunk);
                if ($totalBytes > STATTIC_RUNTIME_UPLOAD_BATCH_MAX_BYTES) {
                    fclose($output);
                    fclose($handle);
                    @unlink($tmpPath);
                    _stattic_json_response(413, ['error' => ['code' => 'upload_batch_too_large', 'message' => 'Batch upload exceeds the runtime size limit.']]);
                }
                hash_update($context, $chunk);
                fwrite($output, $chunk);
                $remaining -= strlen($chunk);
            }
            fclose($output);
            $streamed = ['size' => $entry['size'], 'sha256' => hash_final($context)];
            if ($open) {
                $usage = _stattic_runtime_open_upload_usage($privateRoot, $uploadId, $filePath, true, $ledger, $stagedPartBytes);
                if ($usage['bytes'] + (int) $streamed['size'] > $caps['max_total_bytes'] || $usage['file_count'] > $caps['max_file_count']) {
                    @unlink($tmpPath);
                    fclose($handle);
                    _stattic_runtime_open_upload_cap_exceeded();
                }
            } else {
                _stattic_runtime_verify_declared_upload($tmpPath, $filePath, $expected, $streamed);
            }
            rename($tmpPath, $target);
            $ledger = _stattic_runtime_record_uploaded_file($privateRoot, $uploadId, $filePath, $streamed, $ledger);
            // Trailing tar padding: read-and-discard (the stream is not seekable), and a
            // short read here ends the archive silently, matching the old fseek-past-EOF.
            $padding = (512 - ($entry['size'] % 512)) % 512;
            while ($padding > 0) {
                $skip = fread($handle, $padding);
                if ($skip === '' || $skip === false) {
                    break;
                }
                $totalBytes += strlen($skip);
                $padding -= strlen($skip);
            }
            $uploaded += 1;
            if ($uploaded > STATTIC_RUNTIME_UPLOAD_BATCH_MAX_FILES) {
                fclose($handle);
                _stattic_json_response(413, ['error' => ['code' => 'upload_batch_too_many_files', 'message' => 'Batch upload exceeds the runtime file limit.']]);
            }
        }
        fclose($handle);
    });
    return $uploaded;
}

function _stattic_runtime_tar_header(string $header): array
{
    $checksum = substr($header, 148, 8);
    $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $actualChecksum = 0;
    for ($index = 0; $index < 512; $index += 1) {
        $actualChecksum += ord($checksumHeader[$index]);
    }
    $expectedChecksum = octdec(trim($checksum, " \0"));
    if ($expectedChecksum <= 0 || $expectedChecksum !== $actualChecksum) {
        _stattic_json_response(422, ['error' => ['code' => 'upload_batch_invalid_checksum', 'message' => 'Batch tar checksum is invalid.']]);
    }
    $name = rtrim(substr($header, 0, 100), "\0");
    $prefix = rtrim(substr($header, 345, 155), "\0");
    $path = $prefix !== '' ? $prefix . '/' . $name : $name;
    if ($path === '') {
        _stattic_json_response(422, ['error' => ['code' => 'upload_batch_invalid_entry', 'message' => 'Batch tar entry path is empty.']]);
    }
    return [
        'path' => $path,
        'size' => octdec(trim(substr($header, 124, 12), " \0")),
        'type' => rtrim(substr($header, 156, 1), "\0"),
    ];
}
