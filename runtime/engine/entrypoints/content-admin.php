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
// Bind the lazy private root before any config-dependent check: config.php is
// only readable through this global (see _stattic_config_value).
_stattic_access_private_root($privateRoot);
$host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
$ticket = is_string($_GET['ticket'] ?? null) ? $_GET['ticket'] : '';
$launch = _stattic_content_admin_consume_ticket($privateRoot, $ticket, $host);
if (
    $launch === null
    || !_stattic_content_admin_authorization_matches($privateRoot, $launch['authorization'])
) {
    _stattic_problem_response(401, 'content_admin_ticket_invalid', 'This content editor link is invalid or expired.');
}
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $launch['principal'];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $launch['wordpress_role'];
$publicRoot = dirname(_stattic_runtime_install_root($engineRoot));
$wpLoad = $publicRoot . '/wp-load.php';
if (!is_file($wpLoad)) {
    _stattic_problem_response(503, 'content_wordpress_unavailable', 'The Space content service is not ready.');
}
_stattic_content_admin_enter_wordpress(
    $privateRoot,
    $launch['authorization']['space_id'],
    $launch['frame_origin'],
    $launch['access']
);
require $wpLoad;

$userId = function_exists('spacefast_content_principal_establish_user')
    ? spacefast_content_principal_establish_user()
    : 0;
$session = _stattic_content_admin_mint_session(
    $privateRoot,
    $host,
    $userId,
    $launch['principal'],
    $launch['authorization'],
    $launch['wordpress_role'],
    $launch['frame_origin'],
    $launch['access']
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
// Where the launch lands.
//
// `post` is the type both landings open. A ContentModel projects its
// collections as a taxonomy over WordPress's own posts — see
// register_taxonomy(SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, ['post'])
// in wordpress/content-model-kernel.php — so there is no per-collection post
// type to resolve, and asking for one is what the removed
// spacefast_content_builtin_post_type() call was doing. That function has not
// existed since the ContentModel rename, so every redemption was answering
// 503 content_kernel_unavailable; kernel readiness is already proven above by
// the principal and auth-cookie calls, which fail closed on their own.
//
// The Content screens live in the zero-admin plugin, and its own route builder
// composes their URL: the page slug and the `p` parameter are that plugin's
// vocabulary, and spelling them here would be a second copy to keep in step.
// A launch that named no screen — the dashboard's "Open WordPress admin"
// escape hatch — lands on WordPress's own list instead.
$access = $launch['access'];
$screen = $access['surface'] === 'zero' ? $access['initial_screen'] : null;
$landing = $screen !== null && function_exists('zero_admin_route_url')
    ? zero_admin_route_url($screen === 'users' ? '/users' : '/types/post')
    : '/wp-admin/edit.php';
header('Location: ' . $landing, true, 303);
exit;
