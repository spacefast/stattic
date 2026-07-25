<?php
declare(strict_types=1);

// SSH management dispatcher: reads ONE JSON request envelope from stdin
// ({method, path, authorization, body?}) and executes the SAME management
// surface as HTTP (_stattic_runtime_admin_api) — identical routing, identical JWT
// verification (the Authorization value from the envelope is staged into
// $_SERVER exactly where the HTTP layer reads it), identical handlers. The
// response is emitted on stdout as one {status, body} JSON envelope.
//
// This is the WP.Cloud provider-adapter transport for when the provider's
// public edge re-arms 403/429 protection on /__spacefast/api.php/* (e2e
// checkpoint 3); the runtime operator contract for self-hosting stays pure
// HTTP and never requires this file.
//
// Invocation (WP.Cloud sites prepend a runtime bootstrap to every PHP
// process; it must be disabled exactly like the engine installer run):
//   php -d auto_prepend_file= htdocs/.stattic/engine/admin/dispatch.php < request.json

const STATTIC_RUNTIME_DISPATCH_CLI = true;
const STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES = 67108864;

require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';

if (PHP_SAPI !== 'cli') {
    _stattic_runtime_route_not_found();
}
// Keep handler notices/warnings off stdout — stdout carries only the envelope.
ini_set('display_errors', 'stderr');

$envInputPath = getenv('SPACEFAST_RUNTIME_DISPATCH_REQUEST_PATH');
$inputPath = is_string($envInputPath) && trim($envInputPath) !== ''
    ? trim($envInputPath)
    : (isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '');
$raw = $inputPath === ''
    ? stream_get_contents(STDIN, STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES + 1)
    : @file_get_contents($inputPath, false, null, 0, STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES + 1);
if (!is_string($raw) || strlen($raw) > STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES) {
    _stattic_json_response(413, ['error' => ['code' => 'runtime_dispatch_request_too_large', 'message' => 'Dispatch request envelope is too large.']]);
}
$request = json_decode($raw, true);
$method = is_array($request) && is_string($request['method'] ?? null) ? strtoupper(trim($request['method'])) : '';
$path = is_array($request) && is_string($request['path'] ?? null) ? trim($request['path']) : '';
$requestPath = parse_url($path, PHP_URL_PATH) ?: $path;
$requestQuery = parse_url($path, PHP_URL_QUERY);
$queryParams = [];
if (is_string($requestQuery) && $requestQuery !== '') {
    parse_str($requestQuery, $queryParams);
}
// Route derivation below reads $_GET; stage query params before it runs.
$_GET = $queryParams;
$handlerRoutePath = _stattic_runtime_management_api_route_path($requestPath);
$uploadRoutePath = _stattic_runtime_upload_api_route_path($requestPath);
$authorization = is_array($request) && is_string($request['authorization'] ?? null) ? trim($request['authorization']) : '';
$bodyEncoding = is_array($request) && is_string($request['bodyEncoding'] ?? null) ? trim($request['bodyEncoding']) : '';
if (
    !is_array($request)
    || !in_array($method, ['GET', 'POST', 'PUT'], true)
    || (!is_string($handlerRoutePath) && !is_string($uploadRoutePath))
    || ($handlerRoutePath === '/' || $uploadRoutePath === '/')
    || $authorization === ''
    || (array_key_exists('body', $request) && $request['body'] !== null && !is_string($request['body']))
) {
    _stattic_json_response(400, ['error' => ['code' => 'runtime_dispatch_invalid_request', 'message' => 'Dispatch request envelope is invalid.']]);
}
$isUploadDispatch = is_string($uploadRoutePath);
if ($isUploadDispatch) {
    if (!in_array($method, ['POST', 'PUT'], true) || !is_string($request['body'] ?? null) || $bodyEncoding !== 'base64') {
        _stattic_json_response(400, ['error' => ['code' => 'runtime_dispatch_invalid_upload_request', 'message' => 'Upload dispatch request envelope is invalid.']]);
    }
    $decodedBody = base64_decode((string) $request['body'], true);
    if (!is_string($decodedBody)) {
        _stattic_json_response(400, ['error' => ['code' => 'runtime_dispatch_invalid_upload_body', 'message' => 'Upload dispatch body must be base64.']]);
    }
    _stattic_binary_body_override($decodedBody);
    $_SERVER['CONTENT_LENGTH'] = (string) strlen($decodedBody);
}

$engineRoot = dirname(__DIR__);
$storageRoot = dirname($engineRoot) . '/storage';
if (!is_dir($storageRoot)) {
    _stattic_json_response(503, ['error' => ['code' => 'runtime_dispatch_undeployed', 'message' => 'Runtime storage is not provisioned on this site.']]);
}

// Stage the request exactly where the HTTP handlers read it. The management
// JWT is required and verified identically to HTTP; the hostname assertion
// passes by construction (this process IS the management surface).
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['HTTP_HOST'] = _stattic_management_hostname();
$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['QUERY_STRING'] = is_string($requestQuery) ? $requestQuery : '';
unset($_SERVER['HTTP_ORIGIN']);
if (!$isUploadDispatch) {
    _stattic_json_body_override(is_string($request['body'] ?? null) ? $request['body'] : '');
}

// One dispatcher for both dispatchable surfaces. Rows flagged `binary`
// (archive streams, file-fetch reads) stay HTTP-only: the dispatcher reads
// the matched row's flag and answers runtime_dispatch_unsupported_path in
// this CLI mode.
require_once __DIR__ . '/api.php';
_stattic_runtime_admin_api($storageRoot, $method, $requestPath, $isUploadDispatch ? 'upload' : 'management');
