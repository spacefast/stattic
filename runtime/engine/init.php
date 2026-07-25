<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/context.php';

$engineRoot = __DIR__;
$storageRoot = dirname($engineRoot) . '/storage';
$publishRoot = realpath(dirname(dirname($engineRoot)));
if (!is_string($publishRoot) || !is_dir($storageRoot)) {
    require_once __DIR__ . '/shared/errors.php';
    _stattic_render_platform_page('undeployed', 503, [], "This site has not been deployed yet.\n");
}

$requestMethod = _stattic_runtime_request_method();
// Read raw, not through _stattic_runtime_request_path(): this is the entrypoint
// that stages the alias-dispatch override those helpers honour.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));

if (is_string($publishRoot)) {
    _stattic_dispatch_public_alias_entrypoint($publishRoot, $requestPath, $requestUri);
}

if (_stattic_is_runtime_private_http_path($requestPath) && !_stattic_is_public_runtime_path($requestPath)) {
    require_once __DIR__ . '/shared/errors.php';
    _stattic_render_platform_page('private-path-forbidden', 403, [
        'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
    ], "Forbidden.\n");
}

require_once __DIR__ . '/runtime/serve.php';
_stattic_serve_request($storageRoot, $requestMethod, $requestUri, $requestPath, $requestHost);

function _stattic_dispatch_public_alias_entrypoint(string $publishRoot, string $requestPath, string $requestUri): void
{
    if (!isset(SPACEFAST_RUNTIME_ENTRYPOINT_PATHS[$requestPath])) {
        return;
    }

    $entrypoint = realpath($publishRoot . $requestPath);
    if (!is_string($entrypoint) || !str_starts_with($entrypoint, $publishRoot . DIRECTORY_SEPARATOR) || !is_file($entrypoint)) {
        return;
    }

    $GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] = $requestUri;
    $GLOBALS['SPACEFAST_RUNTIME_REQUEST_PATH'] = $requestPath;
    require $entrypoint;
    exit;
}

function _stattic_is_runtime_private_http_path(string $requestPath): bool
{
    $decoded = rawurldecode($requestPath);
    $normalized = strtolower($decoded === '' ? '/' : $decoded);
    $path = trim($normalized, '/');
    $segments = $path === '' ? [] : explode('/', $path);

    if ($normalized === '/.well-known/spacefast-runtime' || $normalized === '/.well-known/stattic-runtime') {
        return true;
    }
    if (($segments[0] ?? '') === '__spacefast') {
        return true;
    }
    return _stattic_path_has_hidden_segment($path);
}

function _stattic_is_public_runtime_path(string $requestPath): bool
{
    if (_stattic_page_font_filename($requestPath) !== null) {
        return true;
    }
    $path = trim(strtolower(rawurldecode($requestPath)), '/');
    if (isset(SPACEFAST_ZERO_CONTROL_ROUTES[$path])) {
        return true;
    }
    return in_array($path, [
        ltrim(STATTIC_SPACEFAST_SDK_PATH, '/'),
        // First-party visitor-access surfaces (access-plan §3.2) — dispatched in
        // serve.php, not backed by files under the publish root. Paths owned by
        // context.php next to the rest of the access lane.
        ltrim(SPACEFAST_ACCESS_LOGOUT_PATH, '/'),
        ltrim(SPACEFAST_ACCESS_ME_PATH, '/'),
        ltrim(SPACEFAST_ACCESS_TOKEN_PATH, '/'),
        ltrim(SPACEFAST_ACCESS_LOGIN_PATH, '/'),
    ], true);
}
