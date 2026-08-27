<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/cache-policy.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/content-access.php';
require_once $engineRoot . '/shared/content-admin.php';
require_once $engineRoot . '/shared/content-request.php';
require_once $engineRoot . '/shared/native-process.php';
require_once $engineRoot . '/shared/storage.php';
require_once $engineRoot . '/admin/auth.php';

_stattic_emit_runtime_identity();

const SPACEFAST_CONTENT_REQUEST_MAX_BYTES = 4194304;

// The read policy this lane WOULD offer a shared cache. It is deliberately
// stated rather than sent: shared/cache-policy.php decides what actually goes
// out, and while this endpoint stays POST-only its method-blind edge rule pins
// every answer to private no-store.
const SPACEFAST_CONTENT_READ_CACHE_CONTROL = 'public, max-age=0, s-maxage=60, stale-while-revalidate=300';

/**
 * THE Cache-Control author for this endpoint.
 *
 * The lane states one input — is this answer private — and shared/cache-policy.php
 * decides. That seam is what carries the method-blind edge rule here: the
 * provider edge keys a stored response on host+path+query alone, so a
 * publicly-storable answer to a POST would be replayed to every later GET of
 * the same URL. This endpoint accepts nothing but POST, so today the verdict is
 * always private no-store; hand-rolling that literal is how the lane drifted
 * out of the rule in the first place.
 *
 * The verdict is all this lane takes. It deliberately does NOT go through
 * _stattic_cache_policy_send()'s private-CONTENT boundary, whose same-origin
 * CORP and Access-Control-Allow-Origin strip protect page bytes: the content
 * API is an intentional `Access-Control-Allow-Origin: *` JSON surface.
 */
function _stattic_content_cache_control(bool $private): string
{
    return (string) _stattic_cache_policy([
        'private' => $private,
        'public' => SPACEFAST_CONTENT_READ_CACHE_CONTROL,
    ])['cache_control'];
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
header('Access-Control-Allow-Origin: *', true);
header('Access-Control-Allow-Headers: Authorization, Content-Type', true);
header('Access-Control-Allow-Methods: POST, OPTIONS', true);
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    _stattic_method_not_allowed('POST, OPTIONS', [
        'code' => 'content_method_not_allowed',
        'message' => 'The content endpoint accepts POST requests.',
    ]);
}

$rawBody = _stattic_bounded_request_body(SPACEFAST_CONTENT_REQUEST_MAX_BYTES);
if ($rawBody === null) {
    _stattic_problem_response(413, 'content_request_too_large', 'The content request exceeds 4 MiB.');
}
$request = json_decode($rawBody, true);
if (!is_array($request)) {
    _stattic_problem_response(400, 'content_request_invalid', 'The content request must be a JSON object.');
}

$managed = false;
$operation = _stattic_content_management_action($request);
if ($operation === false) {
    _stattic_problem_response(400, 'content_operation_invalid', 'The content operation is not supported.');
}

if (is_string($operation)) {
    $privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
    if (!is_dir($privateRoot)) {
        _stattic_problem_response(503, 'runtime_undeployed', 'Runtime storage is not provisioned on this site.');
    }
    $requestedAuthorization = in_array(
        $operation,
        ['content.authorization.apply', 'content.admin.launch'],
        true
    )
        ? _stattic_content_admin_authorization($request['authorization'] ?? null)
        : null;
    if (
        in_array($operation, ['content.authorization.apply', 'content.admin.launch'], true)
        && $requestedAuthorization === null
    ) {
        _stattic_problem_response(422, 'content_authorization_invalid', 'The content authorization is invalid.');
    }
    $claims = _stattic_runtime_require_management_jwt(
        $privateRoot,
        $operation,
        $requestedAuthorization === null ? [] : ['space_id' => $requestedAuthorization['space_id']]
    );
    $spaceId = (string) ($requestedAuthorization['space_id'] ?? $claims['space_id'] ?? '');
    if (!_stattic_id_valid($spaceId)) {
        _stattic_problem_response(403, 'content_space_scope_invalid', 'Content management requires a Space-scoped token.');
    }
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $managed = true;
    if ($operation === 'content.authorization.apply') {
        $authorization = _stattic_content_admin_apply_authorization(
            $privateRoot,
            $requestedAuthorization
        );
        if ($authorization === null) {
            _stattic_problem_response(422, 'content_authorization_invalid', 'The content authorization could not be applied.');
        }
        _stattic_json_response(200, [
            'spaceId' => $authorization['space_id'],
            'accessGeneration' => $authorization['access_generation'],
        ]);
    }
    if ($operation === 'content.admin.launch') {
        $identity = _stattic_content_admin_identity($request['identity'] ?? null);
        $frameOrigin = _stattic_content_admin_frame_origin($request['frameOrigin'] ?? null);
        $authorization = _stattic_content_admin_apply_authorization(
            $privateRoot,
            $requestedAuthorization
        );
        $host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $ticket = $identity === null
            || $frameOrigin === null
            || $requestedAuthorization === null
            || $authorization === null
            || $authorization !== $requestedAuthorization
            ? null
            : _stattic_content_admin_mint_ticket(
                $privateRoot,
                $host,
                $identity,
                $authorization,
                $frameOrigin
            );
        if ($ticket === null) {
            _stattic_problem_response(422, 'content_admin_launch_invalid', 'The content editor session could not be created.');
        }
        _stattic_json_response(200, [
            'path' => '/__spacefast/content-admin.php?ticket=' . rawurlencode($ticket['token']),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $ticket['expires_at']),
        ], 'application/json', ['Cache-Control' => _stattic_content_cache_control(true)]);
    }
} else {
    $privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
    if (!is_dir($privateRoot)) {
        _stattic_problem_response(503, 'runtime_undeployed', 'Runtime storage is not provisioned on this site.');
    }
    _stattic_visitor_lane_begin($privateRoot);
    require_once $engineRoot . '/runtime/serve.php';
    _sf_load_generated_config($privateRoot);
    $requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $target = _stattic_content_access_target($privateRoot, $requestHost);
    if ($target['kind'] === 'unavailable') {
        _stattic_problem_response(503, 'content_access_unavailable', 'The Space access policy is temporarily unavailable.');
    }
    if ($target['kind'] !== 'present') {
        _stattic_problem_response(404, 'content_space_not_found', 'No Space is active for this host.');
    }
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $target['space_id'];
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = $target['serving'];
    if (!$target['open']) {
        require_once $engineRoot . '/runtime/access-rules.php';
        _stattic_access_enforce_v4($requestHost, '/', '/');
    }
}
unset($request['managed']);

$publicRoot = dirname(_stattic_runtime_install_root($engineRoot));
$wpLoad = $publicRoot . '/wp-load.php';
if (!is_file($wpLoad)) {
    _stattic_problem_response(503, 'content_wordpress_unavailable', 'The Space content service is not ready.');
}

if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}
if (!defined('DISALLOW_FILE_MODS')) {
    define('DISALLOW_FILE_MODS', true);
}

require $wpLoad;
if (!function_exists('spacefast_content_handle_request')) {
    _stattic_problem_response(503, 'content_kernel_unavailable', 'The Space content kernel is not ready.');
}

try {
    $result = spacefast_content_handle_request($request, $managed);
} catch (Spacefast_Content_Error $error) {
    _stattic_problem_response($error->status, $error->codeName, $error->getMessage());
} catch (Throwable $error) {
    $correlationId = bin2hex(random_bytes(8));
    error_log(sprintf(
        'spacefast content uncaught %s [%s]: %s',
        get_debug_type($error),
        $correlationId,
        $error->getMessage()
    ));
    _stattic_problem_response(
        500,
        'content_internal_error',
        'The content service hit an unexpected error.',
        ['details' => ['correlation_id' => $correlationId]]
    );
}

$privateRead = !$managed
    && (_stattic_access_private_cache_flag() || is_string($_SERVER['HTTP_COOKIE'] ?? null));
$headers = ['Cache-Control' => _stattic_content_cache_control($managed || $privateRead)];
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    _stattic_problem_response(500, 'content_response_invalid', 'The content response could not be encoded.');
}
$etag = '"' . hash('sha256', $encoded) . '"';
$headers['ETag'] = $etag;
// Revalidation is not storability: a client that kept the previous answer on
// purpose still gets to skip the bytes, whatever any cache was allowed to do
// with it. The gate stays on the lane's own read verdict.
if (!$managed && !$privateRead && trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    _stattic_response_send(304, '', '', $headers);
}
_stattic_json_response(200, $result, 'application/json', $headers);
