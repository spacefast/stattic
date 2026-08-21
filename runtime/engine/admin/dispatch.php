<?php
declare(strict_types=1);

// CLI management bypasses the HTTP loader, so it owns the same policy here.
// PHP's configured error log is stderr for this process; stdout stays reserved
// for the machine-readable dispatch envelope.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

// SSH management dispatcher: one {method, path, authorization, body?} JSON
// envelope on stdin, one {status, body} envelope on stdout, running the same
// routing, JWT verification and handlers as HTTP.
//
// WP.Cloud prepends a runtime bootstrap to every PHP process — disable it:
//   php -d auto_prepend_file= htdocs/.stattic/releases/<release>/engine/admin/dispatch.php < request.json

const STATTIC_RUNTIME_DISPATCH_CLI = true;
const STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES = 67108864;

require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';

if (PHP_SAPI !== 'cli') {
    _stattic_runtime_route_not_found();
}

$envInputPath = getenv('SPACEFAST_RUNTIME_DISPATCH_REQUEST_PATH');
$inputPath = is_string($envInputPath) && trim($envInputPath) !== ''
    ? trim($envInputPath)
    : (isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '');
$raw = $inputPath === ''
    ? stream_get_contents(STDIN, STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES + 1)
    : file_get_contents($inputPath, false, null, 0, STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES + 1);
if (!is_string($raw) || strlen($raw) > STATTIC_RUNTIME_DISPATCH_MAX_ENVELOPE_BYTES) {
    _stattic_problem_response(413, 'runtime_dispatch_request_too_large', 'Dispatch request envelope is too large.');
}
$request = json_decode($raw, true);
$method = is_array($request) && is_string($request['method'] ?? null) ? strtoupper(trim($request['method'])) : '';
$path = is_array($request) && is_string($request['path'] ?? null) ? trim($request['path']) : '';
$requestPath = _stattic_request_uri_path($path);
$requestQuery = _stattic_request_uri_query($path);
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
    _stattic_problem_response(400, 'runtime_dispatch_invalid_request', 'Dispatch request envelope is invalid.');
}
$isUploadDispatch = is_string($uploadRoutePath);
if ($isUploadDispatch) {
    if (!in_array($method, ['POST', 'PUT'], true) || !is_string($request['body'] ?? null) || $bodyEncoding !== 'base64') {
        _stattic_problem_response(400, 'runtime_dispatch_invalid_upload_request', 'Upload dispatch request envelope is invalid.');
    }
    $decodedBody = base64_decode((string) $request['body'], true);
    if (!is_string($decodedBody)) {
        _stattic_problem_response(400, 'runtime_dispatch_invalid_upload_body', 'Upload dispatch body must be base64.');
    }
    _stattic_request_body_override($decodedBody);
    $_SERVER['CONTENT_LENGTH'] = (string) strlen($decodedBody);
}

$engineRoot = dirname(__DIR__);
$storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
if (!is_dir($storageRoot)) {
    _stattic_problem_response(503, 'runtime_dispatch_undeployed', 'Runtime storage is not provisioned on this site.');
}

// Stage the request exactly where the HTTP handlers read it; the management JWT
// is still required and verified identically.
$_SERVER['REQUEST_METHOD'] = $method;
// Fail loudly as a config error rather than staging a blank host, which the
// api.php host assert would answer with the generic public 404 — masking the
// misconfiguration as "no such route".
$managementHostname = _stattic_management_hostname();
if ($managementHostname === '') {
    _stattic_problem_response(500, 'runtime_dispatch_management_hostname_unconfigured', 'SPACEFAST_MANAGEMENT_HOSTNAME is not configured; the dispatch transport cannot assert the management host.');
}
$_SERVER['HTTP_HOST'] = $managementHostname;
$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['QUERY_STRING'] = is_string($requestQuery) ? $requestQuery : '';
unset($_SERVER['HTTP_ORIGIN']);
if (!$isUploadDispatch) {
    _stattic_request_body_override(is_string($request['body'] ?? null) ? $request['body'] : '');
}

require_once __DIR__ . '/api.php';
_stattic_runtime_admin_api($storageRoot, $method, $requestPath, $isUploadDispatch ? 'upload' : 'management');
