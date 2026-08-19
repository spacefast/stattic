<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/context.php';

// The runtime and the provider edge own their own explicit cache contracts.
// Batcache ignores those contracts and can replay private or pre-publish bytes.
if (function_exists('batcache_cancel')) {
    batcache_cancel();
}

// Must run before anything can exit: every early return below is a response that
// has to be attributable to this runtime, including access denials that would
// otherwise be indistinguishable from an edge or origin 401.
_stattic_emit_runtime_identity();

// Same reason, same place: a request whose URL carries an access token must not
// leak that URL through Referer, and it must not matter which of the exits below
// answers it. The header policy re-asserts this on every lane that rebuilds the
// response header map.
if (_stattic_access_query_token_present()) {
    header('Referrer-Policy: no-referrer', true);
}

$engineRoot = __DIR__;
$storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';

$requestMethod = _stattic_runtime_request_method();
// Raw, not _stattic_runtime_request_path(): this entrypoint stages the
// alias-dispatch override those helpers honour.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$rawRequestPath = _stattic_request_uri_path((string) $requestUri);
$requestPath = _stattic_canonical_request_path($rawRequestPath);
if ($requestPath === null) {
    require_once __DIR__ . '/shared/errors.php';
    // Encoded separators must not turn a private engine namespace into a public
    // alias: those spellings keep the uniform private-path denial.
    if (_stattic_is_runtime_private_http_path(rawurldecode($rawRequestPath))) {
        _stattic_deny_private_path();
    }
    _stattic_render_platform_page(
        'invalid-path',
        403,
        ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE],
        "Access path is invalid.\n"
    );
}
$requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));

_stattic_dispatch_public_alias_entrypoint($engineRoot, $requestPath, $requestUri);

if (_stattic_is_runtime_private_http_path($requestPath) && !_stattic_control_path_admits_visitor($requestPath)) {
    _stattic_deny_private_path();
}

// The visitor lane: generated config, then straight into runtime/serve.php's
// pointer -> shard -> overlay -> root -> table walk. There is no memo in front
// of it (D98 withdrawn) — the opcached artifact includes ARE the fast path.
require_once __DIR__ . '/runtime/serve-fast.php';
_sf_serve_fast($storageRoot, $requestMethod, $requestUri, $requestPath, $requestHost);

function _stattic_dispatch_public_alias_entrypoint(string $engineRoot, string $requestPath, string $requestUri): void
{
    if (!isset(SPACEFAST_RUNTIME_ENTRYPOINT_PATHS[$requestPath])) {
        return;
    }

    // Resolved lazily: only the four literal alias paths ever need the publish
    // root, so the common request pays no realpath/stat here.
    $publishRoot = realpath(dirname(_stattic_runtime_install_root($engineRoot)));
    if (!is_string($publishRoot)) {
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

// Identical for every spelling that reaches it, so the response can never tell a
// prober which private namespace it guessed.
function _stattic_deny_private_path(): never
{
    require_once __DIR__ . '/shared/errors.php';
    _stattic_render_platform_page(
        'private-path-forbidden',
        403,
        ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE],
        "Forbidden.\n",
        true
    );
    exit;
}

function _stattic_is_runtime_private_http_path(string $requestPath): bool
{
    $normalized = strtolower($requestPath === '' ? '/' : $requestPath);
    if ($normalized === '/.well-known/spacefast-runtime' || $normalized === '/.well-known/stattic-runtime') {
        return true;
    }
    if (_stattic_control_path_namespace_is_private($normalized)) {
        return true;
    }
    return _stattic_path_has_hidden_segment(trim($normalized, '/'));
}
