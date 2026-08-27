<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/cache-policy.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/content-admin.php';
require_once $engineRoot . '/shared/storage.php';

_stattic_emit_runtime_identity();
header('Cache-Control: ' . (string) _stattic_cache_policy(['private' => true])['cache_control'], true);
header('Referrer-Policy: no-referrer', true);

$privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
$host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
$ticket = is_string($_GET['ticket'] ?? null) ? $_GET['ticket'] : '';
$launch = _stattic_content_admin_consume_ticket($privateRoot, $ticket, $host);
if (
    $launch === null
    || !_stattic_content_admin_authorization_matches($privateRoot, $launch['authorization'])
) {
    _stattic_problem_response(401, 'content_admin_ticket_invalid', 'This content editor link is invalid or expired.');
}
$GLOBALS['SPACEFAST_CONTENT_ADMIN_IDENTITY'] = $launch['identity'];
$publicRoot = dirname(_stattic_runtime_install_root($engineRoot));
$wpLoad = $publicRoot . '/wp-load.php';
if (!is_file($wpLoad)) {
    _stattic_problem_response(503, 'content_wordpress_unavailable', 'The Space content service is not ready.');
}
_stattic_content_admin_enter_wordpress(
    $privateRoot,
    $launch['authorization']['space_id'],
    $launch['frame_origin']
);
require $wpLoad;

$userId = function_exists('spacefast_content_admin_establish_user')
    ? spacefast_content_admin_establish_user()
    : 0;
$session = _stattic_content_admin_mint_session(
    $privateRoot,
    $host,
    $userId,
    $launch['authorization'],
    $launch['frame_origin']
);
if ($session === null) {
    _stattic_problem_response(503, 'content_admin_session_unavailable', 'The content editor session could not be started.');
}
$wpAuthCookies = function_exists('spacefast_content_admin_auth_cookies')
    ? spacefast_content_admin_auth_cookies($userId, SPACEFAST_CONTENT_ADMIN_SESSION_TTL)
    : null;
if ($wpAuthCookies === null) {
    _stattic_problem_response(503, 'content_admin_session_unavailable', 'The content editor session could not be started.');
}
_stattic_set_cookie(
    _stattic_content_admin_cookie_name(),
    $session['token'],
    SPACEFAST_CONTENT_ADMIN_SESSION_TTL,
    true
);
foreach ($wpAuthCookies as $wpAuthCookie) {
    _stattic_set_cookie(
        $wpAuthCookie['name'],
        $wpAuthCookie['value'],
        SPACEFAST_CONTENT_ADMIN_SESSION_TTL,
        true
    );
}
$postsType = function_exists('spacefast_content_builtin_post_type')
    ? spacefast_content_builtin_post_type('posts')
    : '';
if ($postsType === '') {
    _stattic_problem_response(503, 'content_kernel_unavailable', 'The Space content kernel is not ready.');
}
header('Location: /wp-admin/edit.php?post_type=' . rawurlencode($postsType), true, 303);
exit;
