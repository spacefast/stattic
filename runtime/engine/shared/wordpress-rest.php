<?php
declare(strict_types=1);

require_once __DIR__ . '/request-origin.php';

/** Ambient credentials belong to the exact origin, never to sibling Spaces. */
function _stattic_wordpress_rest_origin_allowed(string $host, string $method): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if (!in_array($site, ['', 'same-origin', 'none'], true)
        || ($origin !== '' && $origin !== _stattic_runtime_request_origin($host))) {
        return false;
    }
    // Core prefers the query override to the header. Requiring browser intent
    // for any unsafe signal also covers conflicting and repeated overrides.
    foreach ([$method, $_GET['_method'] ?? $method, $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $method] as $signal) {
        if (!is_string($signal) || !in_array(strtoupper($signal), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return _stattic_access_same_origin_post($host);
        }
    }
    return true;
}

/** Presence only suppresses cookies; the receiving authenticator must verify it. */
function _stattic_wordpress_rest_has_explicit_credentials(): bool
{
    foreach (['HTTP_X_SF_AUTHORIZATION', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW'] as $header) {
        if (trim((string) ($_SERVER[$header] ?? '')) !== '') return true;
    }
    return false;
}

/** Every REST cookie-to-user resolver applies this rule before reading a cookie. */
function _stattic_wordpress_rest_cookie_allowed(string $host): bool
{
    return !_stattic_wordpress_rest_has_explicit_credentials()
        && _stattic_wordpress_rest_origin_allowed($host, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/** WordPress also resolves users on ordinary pages and during CLI/cron bootstrap. */
function _stattic_wordpress_cookie_allowed(): bool
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $rest = (defined('REST_REQUEST') && REST_REQUEST) || !empty($_GET['rest_route'])
        || (is_string($path) && preg_match('~^/wp-json(?:/|$)~', $path) === 1);
    return !$rest || _stattic_wordpress_rest_cookie_allowed((string) ($_SERVER['HTTP_HOST'] ?? ''));
}

function _stattic_wordpress_identity_rest_route(string $route): bool
{
    return preg_match('~^/(?:spacefast-identity|zero-identity)/v1(?:/|$)~i', $route) === 1;
}

/** Registered application origins opt in only to this plugin's non-admin API. */
function _stattic_wordpress_identity_app_origin_allowed(string $route): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === _stattic_runtime_request_origin((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        return false;
    }
    // The bundled spacefast-identity REST API accepts only its own origin.
    if (preg_match('~^/zero-identity/v1/(?!admin(?:/|$))~', $route) !== 1) {
        return false;
    }
    $plugin = \Zero\Identity\Plugin::class;
    $oauth = \Zero\Identity\OAuth::class;
    return method_exists($oauth, 'allowedOrigin') && isset($plugin::$db)
        && $oauth::allowedOrigin($plugin::$db, $origin);
}

/** Run before identity dispatch or any identity session lookup. */
function _stattic_wordpress_identity_rest_origin(mixed $result): mixed
{
    $route = (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? '');
    if (_stattic_wordpress_identity_rest_route($route)
        && !_stattic_wordpress_rest_origin_allowed(
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        ) && !_stattic_wordpress_identity_app_origin_allowed($route)) {
        return new WP_Error('identity_origin_denied', 'Use this Space from its own origin.', ['status' => 403]);
    }
    return $result;
}

/** Core's CORS handler reflects arbitrary origins with credentials. */
function _stattic_wordpress_rest_cors(mixed $served, mixed $result = null, mixed $request = null): mixed
{
    if ($served) {
        return $served;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $ownOrigin = $origin !== '' && $origin === _stattic_runtime_request_origin((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
    rest_send_cors_headers($served);
    // Public REST remains readable cross-origin. Only own-origin requests and
    // identity's exact registered app origins can read with browser credentials.
    if (!$ownOrigin && !_stattic_wordpress_identity_app_origin_allowed($route)) {
        header_remove('Access-Control-Allow-Credentials');
    }
    return $served;
}

function _stattic_wordpress_rest_install_hooks(): void
{
    add_filter('rest_jsonp_enabled', '__return_false');
    add_filter('rest_authentication_errors', '_stattic_wordpress_identity_rest_origin', -100);
    // Core installs its default during rest_api_init, after mu-plugins load.
    add_action('rest_api_init', static function (): void {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        // Zero emits raw bodies at 11; identity can add CORS at 20.
        add_filter('rest_pre_serve_request', '_stattic_wordpress_rest_cors', 10, 3);
        add_filter('rest_pre_serve_request', '_stattic_wordpress_rest_cors', 100, 3);
    }, 100);
}
