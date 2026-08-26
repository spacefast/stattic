<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/content-access.php';
require_once $engineRoot . '/shared/content-admin.php';
require_once $engineRoot . '/shared/response.php';
require_once $engineRoot . '/shared/safety.php';
require_once $engineRoot . '/shared/storage.php';

const SPACEFAST_CONTENT_MEDIA_PATH_PREFIX = '/__spacefast/content-media/';

function _stattic_content_media_refuse(int $status): never
{
    _stattic_response_send($status, $status === 503 ? 'Unavailable' : 'Not Found', 'text/plain; charset=UTF-8', [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
    ]);
}

/** @return array{scope: string, relative: string}|null */
function _stattic_content_media_request_path(string $requestUri): ?array
{
    $encodedPath = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($encodedPath) || !str_starts_with($encodedPath, SPACEFAST_CONTENT_MEDIA_PATH_PREFIX)) {
        return null;
    }
    $decoded = rawurldecode(substr($encodedPath, strlen(SPACEFAST_CONTENT_MEDIA_PATH_PREFIX)));
    if (
        $decoded === ''
        || str_contains($decoded, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
    ) {
        return null;
    }
    $segments = explode('/', $decoded);
    $scope = array_shift($segments);
    if (!is_string($scope) || preg_match('/\A[a-f0-9]{32}\z/', $scope) !== 1 || $segments === []) {
        return null;
    }
    foreach ($segments as $segment) {
        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || str_starts_with($segment, '.')
            || _stattic_path_is_php_like($segment)
        ) {
            return null;
        }
    }
    return ['scope' => $scope, 'relative' => implode('/', $segments)];
}

function _stattic_content_media_admin_session(
    string $privateRoot,
    string $requestHost
): ?array {
    $cookieName = _stattic_content_admin_cookie_name();
    $token = is_string($_COOKIE[$cookieName] ?? null) ? $_COOKIE[$cookieName] : '';
    return $token === ''
        ? null
        : _stattic_content_admin_verify_session($privateRoot, $token, $requestHost);
}

function _stattic_content_media_type(string $path): string
{
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $detected = $finfo === false ? false : finfo_file($finfo, $path);
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    return is_string($detected) && preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/i', $detected) === 1
        ? strtolower($detected)
        : 'application/octet-stream';
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    _stattic_content_media_refuse(404);
}
$request = _stattic_content_media_request_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
if ($request === null) {
    _stattic_content_media_refuse(404);
}
$privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
if (!is_dir($privateRoot)) {
    _stattic_content_media_refuse(503);
}

$requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
$adminSession = _stattic_content_media_admin_session($privateRoot, $requestHost);
$target = null;
if (is_array($adminSession)) {
    $spaceId = $adminSession['space_id'];
} else {
    _stattic_visitor_lane_begin($privateRoot);
    require_once $engineRoot . '/runtime/serve.php';
    _sf_load_generated_config($privateRoot);
    $target = _stattic_content_access_target($privateRoot, $requestHost);
    if ($target['kind'] === 'unavailable') {
        _stattic_content_media_refuse(503);
    }
    if ($target['kind'] !== 'present') {
        _stattic_content_media_refuse(404);
    }
    $spaceId = $target['space_id'];
}
$expectedScope = substr(hash('sha256', $spaceId), 0, 32);
if (!hash_equals($expectedScope, $request['scope'])) {
    _stattic_content_media_refuse(404);
}

$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
if (is_array($target)) {
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = $target['serving'];
}
if (is_array($target) && !$target['open']) {
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    require_once $engineRoot . '/runtime/access-rules.php';
    _stattic_access_enforce_v4($requestHost, $requestPath, $requestPath);
}

$mediaRoot = $privateRoot . '/spaces/' . $spaceId . '/content-media';
$rootReal = realpath($mediaRoot);
$fileReal = realpath($mediaRoot . '/' . $request['relative']);
if (
    !is_string($rootReal)
    || !is_string($fileReal)
    || !str_starts_with($fileReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($fileReal)
) {
    _stattic_content_media_refuse(404);
}

$size = filesize($fileReal);
$headers = [
    'Cache-Control' => 'private, no-store',
    'Content-Security-Policy' => 'sandbox',
    'Content-Type' => _stattic_content_media_type($fileReal),
    'Referrer-Policy' => 'no-referrer',
    'X-Content-Type-Options' => 'nosniff',
    ...($size === false ? [] : ['Content-Length' => (string) $size]),
];
_stattic_send_response_headers($headers);
http_response_code(200);
if ($method === 'HEAD') {
    exit;
}
$stream = fopen($fileReal, 'rb');
if ($stream === false) {
    _stattic_content_media_refuse(404);
}
_stattic_stream_file($stream, $size === false ? PHP_INT_MAX : $size);
fclose($stream);
exit;
