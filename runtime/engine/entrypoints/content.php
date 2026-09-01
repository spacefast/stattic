<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/content-admin.php';
require_once $engineRoot . '/shared/content-principal.php';
require_once $engineRoot . '/shared/content-request.php';
require_once $engineRoot . '/shared/native-process.php';
require_once $engineRoot . '/shared/storage.php';
require_once $engineRoot . '/admin/auth.php';

_stattic_emit_runtime_identity();

const SPACEFAST_CONTENT_REQUEST_MAX_BYTES = 4194304;

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    _stattic_method_not_allowed('POST', [
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

$operation = _stattic_content_management_action($request);
if ($operation === false) {
    _stattic_problem_response(400, 'content_operation_invalid', 'The content operation is not supported.');
}

$privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
// Bind the lazy private root before the JWT verify below: the verifier
// resolves SPACEFAST_RUNTIME_INSTANCE_ID through _stattic_config_value, which
// reads `<privateRoot>/config.php` only through this global.
_stattic_access_private_root($privateRoot);
if (!is_dir($privateRoot)) {
    _stattic_problem_response(503, 'runtime_undeployed', 'Runtime storage is not provisioned on this site.');
}

$needsAuthorization = in_array($operation, ['content.authorization.apply', 'content.admin.launch'], true);
$requestedAuthorization = $needsAuthorization
    ? _stattic_content_admin_authorization($request['authorization'] ?? null)
    : null;
if ($needsAuthorization && $requestedAuthorization === null) {
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
$host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
// The principal assertion carries the acting identity for operations performed
// on behalf of a person. The content-model/document lanes act on management-JWT
// authority alone (the control plane is the actor), so only the person-scoped
// operations below require it.
$principalGated = in_array(
    $operation,
    [
        'content.authorization.apply',
        'content.admin.launch',
        // Storage authorizes against the caller's projected WordPress role,
        // so it acts for a person and needs the assertion that names one.
        'content.storage.list',
        'content.storage.get',
        'content.storage.delete',
    ],
    true
);
$principal = _stattic_content_principal_assertion(
    $request['principal'] ?? null,
    $spaceId,
    $host
);
if (
    $principalGated
    && (
        $principal === null
        || (
            $requestedAuthorization !== null
            && $principal['access_generation'] !== $requestedAuthorization['access_generation']
        )
    )
) {
    _stattic_problem_response(403, 'content_principal_invalid', 'The content principal assertion is invalid or expired.');
}
if ($principal !== null) {
    $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
}
// The role is the control plane's Grant decision, re-derived for this one
// request and never read back from WordPress. The management JWT above
// already decided WHETHER this operation may run; the role only shapes
// what WordPress lets it touch while it runs.
$wordpressRole = $principal === null ? null : ($principal['wordpress_role'] ?? null);
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $wordpressRole;
if ($operation === 'content.authorization.apply') {
    $authorization = _stattic_content_admin_apply_authorization($privateRoot, $requestedAuthorization);
    if ($authorization === null) {
        _stattic_problem_response(422, 'content_authorization_invalid', 'The content authorization could not be applied.');
    }
    _stattic_json_response(200, [
        'spaceId' => $authorization['space_id'],
        'accessGeneration' => $authorization['access_generation'],
    ], 'application/json', ['Cache-Control' => 'private, no-store']);
}
if ($operation === 'content.admin.launch') {
    $frameOrigin = _stattic_content_admin_frame_origin($request['frameOrigin'] ?? null);
    $authorization = _stattic_content_admin_apply_authorization(
        $privateRoot,
        $requestedAuthorization
    );
    $access = _stattic_content_admin_access($request['access'] ?? null);
    // The editor frame is for humans holding a WordPress role: a service
    // actor, or anyone whose Grants earn no role, gets no ticket.
    $ticket = $frameOrigin === null
        || $requestedAuthorization === null
        || $authorization === null
        || $authorization !== $requestedAuthorization
        || ($principal['kind'] ?? null) !== 'user'
        || !is_string($wordpressRole)
        || $access === null
        ? null
        : _stattic_content_admin_mint_ticket(
            $privateRoot,
            $host,
            $principal,
            $authorization,
            $wordpressRole,
            $frameOrigin,
            $access
        );
    if ($ticket === null) {
        _stattic_problem_response(422, 'content_admin_launch_invalid', 'The content editor session could not be created.');
    }
    _stattic_json_response(200, [
        'path' => '/__spacefast/content-admin.php?ticket=' . rawurlencode($ticket['token']),
        'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $ticket['expires_at']),
    ], 'application/json', ['Cache-Control' => 'private, no-store']);
}
// Transport, not payload: the kernel is handed the content request, and reads
// who is asking from the globals this entrypoint already established.
unset($request['managed'], $request['principal']);

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
    $result = spacefast_content_handle_request($request, true);
} catch (Spacefast_Content_Error $error) {
    _stattic_problem_response($error->status, $error->codeName, $error->getMessage());
} catch (Spacefast_Content_Conflict $conflict) {
    // A Markdown conflict is the one failure whose body the caller has to read:
    // it carries the three representations needed to show the diff.
    _stattic_problem_response(
        $conflict->status,
        $conflict->codeName,
        $conflict->getMessage(),
        ['details' => $conflict->details()]
    );
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

_stattic_json_response(200, $result, 'application/json', ['Cache-Control' => 'private, no-store']);
