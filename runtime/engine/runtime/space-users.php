<?php
declare(strict_types=1);

// Read before WordPress loads, so it cannot ask the identity plugin for the name.
// In production the plugin only runs over HTTPS and always sets the __Host- name;
// the bare name is plain-HTTP local development only. A Zero v2 Space's accounts
// live in its own tables under zero-identity's cookie.
function _stattic_space_users_session_cookie(array $serving = []): ?string
{
    $name = ($serving['zero_generation'] ?? null) === 'v2' ? 'zi_session' : 'sfi_session';
    $cookie = $_COOKIE['__Host-' . $name]
        ?? (_stattic_cookies_secure() ? null : ($_COOKIE[$name] ?? null));
    return is_string($cookie) ? $cookie : null;
}

// Named by the identity plugin's rule so every sfi_* cookie shares one prefix.
function _stattic_space_users_set_cookie(string $name, string $value, int $expires): void
{
    $cookieName = spacefast_space_users_cookie_name($name);
    setcookie($cookieName, $value, [
        'expires' => $expires, 'path' => '/',
        'secure' => _stattic_cookies_secure() || str_starts_with($cookieName, '__Host-'),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function _stattic_space_users_cookie(string $name): string
{
    return (string) ($_COOKIE[spacefast_space_users_cookie_name($name)] ?? '');
}

function _stattic_space_users_route(string $path): bool
{
    return $path === '/identity' || str_starts_with($path, '/identity/')
        || $path === '/__zero/auth/user' || $path === '/__zero/auth/native' || $path === '/__zero/auth/complete' || str_starts_with($path, '/__zero/auth/api/');
}

/** Returns true only when the provider must finish its environment before re-entry. */
function _stattic_space_users_prepare(
    string $privateRoot, array $serving, string $host, string $path, string $uri, string $method, ?array $providerConfiguration = null
): bool {
    $route = _stattic_space_users_route($path);
    $control = STATTIC_ZERO_CONTROL_ROUTES[trim($path, '/')] ?? null;
    $start = is_array($control) && in_array($control['operation'], ['auth_start', 'auth_sign_out'], true);
    if (!$route && !$start) return false;
    require_once __DIR__ . '/../shared/zero-v2.php';
    $hasSession = _stattic_space_users_session_cookie($serving) !== null;
    if (($serving['users']['enabled'] ?? false) !== true) {
        if ($route) _stattic_problem_refused(404, 'space_users_disabled', 'Users is not enabled for this Space.');
        return false;
    }
    // Each sign-in method belongs to one part of app sign-in; a part that is off
    // refuses its methods here, before WordPress loads.
    require_once __DIR__ . '/../shared/space-users-parts.php';
    $signIn = is_array($control) && $control['operation'] === 'auth_start'
        ? (is_string($_GET['provider'] ?? null) ? $_GET['provider'] : 'spacefast')
        : (str_starts_with($path, '/__zero/auth/api/') ? _stattic_space_users_operation_method(substr($path, strlen('/__zero/auth/api/'))) : null);
    if ($signIn !== null && _stattic_space_users_method_part($serving['users'], $signIn) !== null
        && !_stattic_space_users_method_allowed($serving['users'], $signIn)) {
        _stattic_problem_refused(403, 'space_users_provider_disabled', 'This sign-in method is not enabled for this Space.');
    }
    if (!$hasSession) _stattic_space_users_access_only_entry($serving['users'], $path, $method, $providerConfiguration);
    require_once __DIR__ . '/content-page.php';
    require_once __DIR__ . '/identity-context.php';
    $spaceId = (string) ($serving['space_id'] ?? '');
    // A Zero v2 Space keeps its users in its own tables, under the identity code
    // of its current initialized version. That is not the version this host
    // serves: a preview or a plain static version can be served while the
    // Space is on v2, and an older version may predate the identity code.
    $zeroVersion = _stattic_zero_generation($serving) === 'v2'
        ? _stattic_space_users_zero_version($privateRoot, $spaceId, $serving) : null;
    $wpLoad = dirname(dirname($privateRoot)) . '/wp-load.php';
    if ($spaceId === '' || !is_file($wpLoad)) {
        _stattic_problem_refused(503, 'space_users_unavailable', 'Users is not ready on this Space.');
    }
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_once($privateRoot, $serving, 'space_users_auth');
    $GLOBALS['SPACEFAST_SPACE_USERS_PROVIDER_CONFIGURATION'] = $providerConfiguration;
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = _stattic_runtime_request_origin($host);
    if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) {
        $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] = [
            'private_root' => $privateRoot, 'method' => $method,
            'uri' => $uri, 'path' => $path, 'host' => $host,
        ];
        return true;
    }
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    (static function (string $bootstrap): void {
        require_once $bootstrap;
    })($wpLoad);
    _stattic_wordpress_lane_refuse_page_cache();
    if (!function_exists('spacefast_space_users_available') || !spacefast_space_users_available()) {
        _stattic_problem_refused(503, 'space_users_unavailable', 'Users is not installed on this Space yet.');
    }
    if ($hasSession) {
        _stattic_access_private_cache_flag(true);
        // The sign-in page also shows for a session cookie that no longer signs
        // anyone in, and for a fresh sign-in an OAuth client asked for.
        if (isset($_GET['reauth']) || spacefast_space_users_live_session() === null) {
            _stattic_space_users_access_only_entry($serving['users'], $path, $method, $providerConfiguration);
        }
    }
    if ($route) _stattic_space_users_dispatch($path, $method, $host);
    return false;
}

/**
 * Select the Space's current initialized Zero v2 version for Users, or refuse
 * with a problem document. Versions built before Spacefast Access have no
 * `runtime.users.access-shape.v1`-era identity code, so they are refused with a
 * republish instruction rather than crashing.
 */
function _stattic_space_users_zero_version(string $privateRoot, string $spaceId, array $serving): string
{
    $current = $spaceId === '' ? null : _stattic_runtime_read_json($privateRoot . '/spaces/' . $spaceId . '/zero-v2-current.json');
    $versionId = is_array($current) ? ($current['version_id'] ?? null) : null;
    if (!is_string($versionId) || !_stattic_id_valid($versionId)) {
        _stattic_problem_refused(503, 'space_users_zero_version_required', 'Users sign-in needs this Space\'s Zero app. Publish a version with zero.config.ts.');
    }
    $identity = _stattic_version_root($privateRoot, $spaceId, $versionId) . '/.zero-compiled/plugins/zero-identity/src/Sessions.php';
    $source = is_file($identity) ? file_get_contents($identity) : false;
    if (!is_string($source) || !str_contains($source, 'function cookieName(')) {
        _stattic_problem_refused(409, 'space_users_zero_version_outdated', 'This Space\'s Zero app was built before app sign-in could use it. Publish the Space again.');
    }
    require_once __DIR__ . '/../shared/space-users-site.php';
    _stattic_space_users_refuse_site_accounts($privateRoot, $spaceId);
    try {
        $selected = _stattic_zero_v2_select($privateRoot, $spaceId, $versionId, $serving);
    } catch (RuntimeException) {
        $selected = false;
    }
    if (!$selected) {
        _stattic_problem_refused(503, 'space_users_zero_version_unavailable', 'Users is not ready on this Space. Try again shortly.');
    }
    return $versionId;
}

/**
 * The hosted `/identity` sign-in page offers email codes and passkeys, which
 * are Zero Users' methods. With only Spacefast Access on, a signed-out visitor
 * starts a Spacefast Access sign-in instead, returning where the page would
 * have (an OAuth authorize resume, or the account page). The multifactor step
 * (`?factor=`) still renders the page, and so does a signed-in visit to the
 * account page.
 */
function _stattic_space_users_access_only_entry(array $users, string $path, string $method, ?array $providerConfiguration): void
{
    if (!in_array($path, ['/identity', '/identity/', '/identity/account'], true) || !in_array($method, ['GET', 'HEAD'], true) || isset($_GET['factor'])) return;
    $provider = _stattic_space_users_access_only_provider($users, $providerConfiguration);
    if ($provider === null) return;
    require_once __DIR__ . '/access-rules.php';
    $returnTo = isset($_GET['reauth']) ? '/identity/authorize?resume=1' : '/identity';
    $requested = is_string($_GET['return_to'] ?? null) ? $_GET['return_to'] : '';
    if (str_starts_with($requested, '/identity/authorize?') && _stattic_safe_return_path($requested) !== null) $returnTo = $requested;
    header('Cache-Control: private, no-store');
    header('Location: /__zero/auth/start?' . http_build_query(['provider' => $provider, 'returnTo' => $returnTo]), true, 303);
    exit;
}

/** The Spacefast Access method a signed-out `/identity` visit starts, or null while Zero Users serves the page. */
function _stattic_space_users_access_only_provider(array $users, ?array $providerConfiguration): ?string
{
    $parts = _stattic_space_users_parts($users);
    if ($parts['zeroUsers'] || !$parts['spacefastAccess']) return null;
    $available = is_array($providerConfiguration['availability'] ?? null) ? $providerConfiguration['availability'] : [];
    if (($users['providers']['spacefast']['enabled'] ?? false) === true) return 'spacefast';
    if (($users['providers']['google']['mode'] ?? '') === 'managed' && ($available['google']['managed'] ?? false) === true) return 'google';
    if (($users['providers']['gravatar']['enabled'] ?? false) === true && ($available['gravatar'] ?? false) === true) return 'gravatar';
    return null;
}

function _stattic_space_users_dispatch(string $path, string $method, string $host): never
{
    header('Cache-Control: private, no-store');
    if ($path === '/__zero/auth/complete') {
        if ($method !== 'GET') _stattic_method_not_allowed('GET');
        if (spacefast_space_users_auth() === null) _stattic_problem_refused(401, 'space_users_sign_in_required', 'Finish signing in to continue.');
        [$id, $binding] = array_pad(explode('.', _stattic_space_users_cookie('sfi_app_return'), 2), 2, '');
        try {
            $challenge = spacefast_space_users_identity('Plugin')::$security->consume($id, 'space_app_return', $binding);
        } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
            _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
        }
        _stattic_space_users_set_cookie('sfi_app_return', '', time() - 3600);
        header('Location: ' . (_stattic_safe_return_path((string) ($challenge['payload']['path'] ?? '/')) ?? '/'), true, 303);
        exit;
    }
    if ($path === '/__zero/auth/native') _stattic_space_users_native_complete($host, $method);
    if ($path === '/__zero/auth/user') {
        if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
        _stattic_space_users_admit_origin($method, $host);
        _stattic_zero_json_response(200, ['data' => spacefast_space_users_auth()]);
    }
    if (str_starts_with($path, '/__zero/auth/api/')) {
        $operation = substr($path, strlen('/__zero/auth/api/'));
        if (str_starts_with($operation, 'admin/')) {
            _stattic_problem_refused(403, 'space_users_management_forbidden', 'Manage Users from your Space dashboard.');
        }
        // The content kernel's REST serializer; a Zero v2 binding loads no kernel.
        require_once __DIR__ . '/../wordpress/content-admin-api.php';
        $request = new WP_REST_Request($method === 'HEAD' ? 'GET' : $method, '/' . spacefast_space_users_rest_namespace() . '/' . $operation);
        $request->set_query_params($_GET);
        $request->set_header('origin', (string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $request->set_header('x-identity-csrf', (string) ($_SERVER['HTTP_X_IDENTITY_CSRF'] ?? ''));
        $request->set_header('content-type', (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/json'));
        $body = _stattic_bounded_request_body(1048576);
        if ($body === null) _stattic_problem_refused(413, 'space_users_request_too_large', 'The request is too large.');
        $request->set_body($body);
        $response = spacefast_content_rest_response($request);
        foreach ($response['headers'] as $name => $value) {
            if (strtolower($name) !== 'cache-control') header($name . ': ' . $value);
        }
        if ($response['body'] === null) {
            _stattic_response_send($response['status'], '', 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
        }
        _stattic_zero_json_response($response['status'], $response['body']);
    }
    if (str_starts_with($path, '/identity/assets/')) {
        $asset = substr($path, strlen('/identity/assets/'));
        if (!in_array($asset, ['build/hosted.js', 'build/hosted.css'], true)) {
            _stattic_problem_refused(404, 'space_users_asset_not_found', 'This asset is not available.');
        }
        if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
        $file = spacefast_space_users_scoped()
            ? $GLOBALS['SPACEFAST_ZERO_V2_ROOT'] . '/plugins/zero-identity/' . $asset
            : dirname(__DIR__, 2) . '/wordpress/spacefast-identity/' . $asset;
        header('Content-Type: ' . (str_ends_with($asset, '.js') ? 'text/javascript' : 'text/css') . '; charset=utf-8');
        if ($method !== 'HEAD') readfile($file);
        exit;
    }
    if ($path === '/identity/provider/start' || $path === '/identity/provider/link') {
        $provider = is_string($_GET['provider'] ?? null) ? $_GET['provider'] : '';
        if ($provider === 'spacefast') {
            $GLOBALS['SPACEFAST_SPACE_USERS_NATIVE_LINK'] = $path === '/identity/provider/link';
            $serving = $GLOBALS['SPACEFAST_PAGE_SERVING'] ?? [];
            _stattic_zero_send_auth_redirect([], $serving, 'auth_start', $method, $host);
        }
        $_GET['provider'] = _stattic_space_users_provider($provider);
    }
    do_action('template_redirect');
    _stattic_problem_refused(404, 'space_users_route_not_found', 'This account page is not available.');
}

function _stattic_space_users_native_begin(string $returnPath): string
{
    $payload = ['path' => $returnPath, 'kind' => 'signin'];
    if (($GLOBALS['SPACEFAST_SPACE_USERS_NATIVE_LINK'] ?? false) === true) {
        _stattic_space_users_admit_origin('GET', (string) $_SERVER['HTTP_HOST']);
        try {
            $session = spacefast_space_users_identity('Plugin')::$sessions->require();
            spacefast_space_users_identity('Plugin')::$sessions->recent($session);
        } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
            _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
        }
        $payload = ['path' => '/identity/account', 'kind' => 'link', 'userId' => (int) $session['wp_user_id'], 'sessionId' => $session['id']];
    }
    $binding = spacefast_space_users_identity('Security')::token();
    $id = spacefast_space_users_identity('Plugin')::$security->challenge('space_native', 0, '', $payload, $binding, 300);
    _stattic_space_users_set_cookie('sfi_native_start', $id . '.' . $binding, time() + 300);
    return '/__zero/auth/native';
}

function _stattic_space_users_native_complete(string $host, string $method): never
{
    if ($method !== 'GET') _stattic_method_not_allowed('GET');
    $serving = $GLOBALS['SPACEFAST_PAGE_SERVING'] ?? [];
    if (($serving['users']['providers']['spacefast']['enabled'] ?? false) !== true) {
        _stattic_problem_refused(403, 'space_users_provider_disabled', 'Spacefast sign-in is not enabled.');
    }
    $verified = _stattic_current_session_identity($serving, $host);
    $account = _stattic_access_account_identity(_stattic_access_identity_record($verified) ?? []);
    if ($account === null) _stattic_problem_refused(401, 'space_users_native_identity_missing', 'Start Spacefast sign-in again.');
    [$id, $binding] = array_pad(explode('.', _stattic_space_users_cookie('sfi_native_start'), 2), 2, '');
    try {
        $challenge = spacefast_space_users_identity('Plugin')::$security->consume($id, 'space_native', $binding);
        $name = (string) ($verified['profile']['name'] ?? 'Spacefast user');
        if (($challenge['payload']['kind'] ?? '') === 'link') {
            $session = spacefast_space_users_identity('Plugin')::$sessions->require();
            if ((int) $session['wp_user_id'] !== ($challenge['payload']['userId'] ?? null)
                || $session['id'] !== ($challenge['payload']['sessionId'] ?? null)) {
                _stattic_problem_refused(403, 'link_session_invalid', 'Sign in to the account that started linking and try again.');
            }
            spacefast_space_users_link_native($account['issuer'], $account['subject'], $name, $session);
            $result = ['kind' => 'linked'];
        } else {
            $result = spacefast_space_users_native_sign_in($account['issuer'], $account['subject'], $name);
        }
    } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
        _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
    }
    _stattic_space_users_set_cookie('sfi_native_start', '', time() - 3600);
    $returnPath = _stattic_safe_return_path((string) ($challenge['payload']['path'] ?? '/')) ?? '/';
    if ($result['kind'] === 'mfa_required') _stattic_space_users_return_begin($returnPath);
    $destination = $result['kind'] === 'mfa_required'
        ? '/identity?factor=' . rawurlencode($result['challenge_id']) . '&return_to=' . rawurlencode('/__zero/auth/complete')
        : $returnPath;
    header('Location: ' . $destination, true, 303);
    exit;
}

function _stattic_space_users_provider(string $selected): string
{
    $settings = spacefast_space_users_settings();
    $google = $settings['providers']['google']['mode'] ?? 'disabled';
    if (($selected === 'google' && $google !== 'disabled')
        || ($selected === 'gravatar' && ($settings['providers']['gravatar']['enabled'] ?? false) === true)) {
        $available = spacefast_space_users_provider_configuration()['availability'] ?? [];
        if (($selected === 'google' && ($available['google'][$google] ?? false) !== true)
            || ($selected === 'gravatar' && ($available['gravatar'] ?? false) !== true)) {
            _stattic_problem_refused(503, 'space_users_provider_unavailable', 'This sign-in method is not configured yet.');
        }
        $GLOBALS['SPACEFAST_SPACE_USERS_SELECTED_PROVIDER'] = $selected;
        return $selected === 'google' && $google === 'direct' ? 'google' : 'spacefast';
    }
    _stattic_problem_refused(403, 'space_users_provider_disabled', 'This sign-in method is not enabled for this Space.');
}

function _stattic_space_users_start(string $selected, string $returnPath): never
{
    $provider = _stattic_space_users_provider($selected);
    _stattic_space_users_return_begin($returnPath);
    $plugin = spacefast_space_users_identity('Plugin');
    $providers = spacefast_space_users_identity('Providers');
    try {
        $destination = (new $providers($plugin::$security, $plugin::$accounts, $plugin::$sessions))->start($provider);
    } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
        _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
    }
    header('Cache-Control: private, no-store');
    header('Location: ' . $destination, true, 302);
    exit;
}

/** Cookie-authenticated app writes require the exact app origin, including its port. */
function _stattic_space_users_admit_origin(string $method, string $host): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = _stattic_runtime_request_origin($host);
    if (($origin !== '' && $origin !== $expected)
        || (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $origin !== $expected)
        || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        _stattic_problem_refused(403, 'space_users_origin_denied', 'Use this app from its own origin.');
    }
}

/**
 * In-process verification against the site's own WordPress database.
 *
 * A request cannot delegate this to a WP-CLI child: the wp.cloud FPM pool runs
 * in a mount namespace with no `php` or `wp` on PATH and no loopback listener
 * (measured 2026-09-20 on the retained contract site: every proc_open of the
 * CLI fails, and curl to 127.0.0.1:80/443 is refused). It cannot boot WordPress
 * either, because the engine serves from the provider's auto_prepend pass,
 * before /scripts/env.php defines the database constants (custom-redirects.php).
 * What the pass does have is the DB_* tuple and wp-config.php, which is all the
 * identity plugin's session lookup needs.
 *
 * Same verdict as spacefast_space_users_auth(): a live, unrevoked session on an
 * active account whose WordPress user is this Space's app user, answering the
 * Space-scoped opaque subject. wordpress/space-users.php owns the WordPress
 * side; a change to either side changes both. Only the Zero 0.8 lanes ask; a
 * Zero v2 Space answers identity from WordPress (zero-identity on its tables).
 */
function _stattic_space_users_verify_session(string $privateRoot, string $spaceId, string $host, array $settings, string $cookie): ?array
{
    if ($cookie === '' || strlen($cookie) > 512 || $spaceId === '') return null;
    require_once __DIR__ . '/../shared/artifacts.php';
    require_once __DIR__ . '/../shared/db-broker.php';
    require_once __DIR__ . '/../shared/space-users-site.php';
    $site = _stattic_space_users_site_config(dirname(dirname($privateRoot)));
    $url = _stattic_zero_runner_base_env()['SPACEFAST_ZERO_DATABASE_URL'] ?? '';
    if ($site === null || !is_string($url) || $url === '') {
        _stattic_problem_refused(503, 'space_users_verifier_unavailable', 'Account verification is temporarily unavailable.');
    }
    // The broker is the engine's one mysqli owner. Its binding is request state
    // another lane may already hold (sf_db() binds the application database
    // after this verify), so the identity lookup borrows it and hands it back.
    $state = &_stattic_db_broker_state();
    [$previousUrl, $previousSource] = [$state['url'], $state['source']];
    _stattic_db_broker_bind($url, 'provider');
    $link = _stattic_db_broker_connection();
    if (!$link instanceof mysqli) {
        _stattic_problem_refused(503, 'space_users_verifier_unavailable', 'Account verification is temporarily unavailable.');
    }
    $auth = _stattic_space_users_lookup_session($link, $site, $spaceId, $cookie);
    _stattic_db_broker_bind($previousUrl, $previousSource);
    if ($auth === false) {
        _stattic_problem_refused(503, 'space_users_verifier_invalid', 'Account verification is temporarily unavailable.');
    }
    return $auth;
}

/**
 * @param array{prefix:string,key:string,salt:string} $site
 * @return array|null|false null = no live session for this Space; false = the lookup itself failed
 */
function _stattic_space_users_lookup_session(mysqli $link, array $site, string $spaceId, string $secret): array|null|false
{
    $p = $site['prefix'];
    $one = static function (string $sql, string $types, array $params) use ($link): array|null|false {
        $statement = $link->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            // 1146: the identity tables do not exist yet, so no account can either.
            return $link->errno === 1146 ? null : false;
        }
        if ($params !== [] && !$statement->bind_param($types, ...$params)) return false;
        if (!$statement->execute()) return false;
        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : false;
        $statement->close();
        return is_array($row) ? $row : ($row === false ? false : null);
    };
    $key = $site['key'];
    $salt = $site['salt'];
    if ($key === '' || $salt === '') {
        $names = ['auth_key', 'auth_salt'];
        $rows = $one(
            "SELECT MAX(CASE WHEN option_name = 'auth_key' THEN option_value END) AS k, MAX(CASE WHEN option_name = 'auth_salt' THEN option_value END) AS s FROM {$p}options WHERE option_name IN (?, ?)",
            'ss', $names
        );
        if ($rows === false) return false;
        $key = $key !== '' ? $key : (string) ($rows['k'] ?? '');
        $salt = $salt !== '' ? $salt : (string) ($rows['s'] ?? '');
        if ($key === '' || $salt === '') return null;
    }
    $sessionParams = [hash_hmac('sha256', $secret, $key . $salt), time()];
    $session = $one(
        "SELECT s.wp_user_id FROM {$p}sfi_sessions s JOIN {$p}sfi_accounts a ON a.wp_user_id = s.wp_user_id"
        . " WHERE s.secret_hash = ? AND s.revoked_at IS NULL AND s.expires_at > ? AND a.status = 'active' LIMIT 1",
        'si', $sessionParams
    );
    if ($session === false) return false;
    if ($session === null) return null;
    $userId = (int) $session['wp_user_id'];
    $userParams = [$spaceId, 'spacefast_app_subject_' . $userId . '_' . $spaceId, $userId];
    $user = $one(
        "SELECT u.display_name,"
        . " (SELECT m.meta_value FROM {$p}usermeta m WHERE m.user_id = u.ID AND m.meta_key = '_spacefast_app_user' ORDER BY m.umeta_id LIMIT 1) AS app_space,"
        . " EXISTS(SELECT 1 FROM {$p}usermeta n WHERE n.user_id = u.ID AND n.meta_key = '_spacefast_space_id' AND n.meta_value = ?) AS is_member,"
        . " (SELECT o.option_value FROM {$p}options o WHERE o.option_name = ? LIMIT 1) AS subject"
        . " FROM {$p}users u WHERE u.ID = ? LIMIT 1",
        'ssi', $userParams
    );
    if ($user === false) return false;
    if ($user === null || (string) ($user['app_space'] ?? '') !== $spaceId || (int) ($user['is_member'] ?? 0) !== 1) return null;
    $subject = (string) ($user['subject'] ?? '');
    if (preg_match('/\Ausr_[a-f0-9]{64}\z/', $subject) !== 1) return null;
    $displayName = (string) ($user['display_name'] ?? '');
    return [
        'user' => ['id' => $subject, 'displayName' => $displayName],
        'userId' => $subject, 'displayName' => $displayName,
        'provider' => 'space-users', 'isAuthenticated' => true, 'isGuest' => false,
    ];
}

function _stattic_space_users_return_begin(string $returnPath): void
{
    $binding = spacefast_space_users_identity('Security')::token();
    $challenge = spacefast_space_users_identity('Plugin')::$security->challenge('space_app_return', 0, '', ['path' => $returnPath], $binding, 300);
    _stattic_space_users_set_cookie('sfi_app_return', $challenge . '.' . $binding, time() + 300);
}

// `$admitOrigin` false only for a top-level navigation whose own request is
// bound to this viewer some other way (a connector OAuth callback's sealed state).
function _stattic_space_users_request_auth(array $serving, string $host, bool $admitOrigin = true): ?array
{
    if (array_key_exists('SPACEFAST_SPACE_USERS_AUTH', $GLOBALS)) return $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'];
    $cookie = _stattic_space_users_session_cookie($serving);
    if ($cookie === null || strlen($cookie) > 512) return null;
    $privateRoot = _stattic_access_private_root();
    require_once __DIR__ . '/../shared/admission.php';
    if ($admitOrigin) _stattic_space_users_admit_origin(_stattic_runtime_request_method(), $host);
    _stattic_admission_acquire_once($privateRoot, $serving, 'space_users_verify');
    _stattic_access_private_cache_flag(true);
    return $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'] = _stattic_space_users_verify_session(
        $privateRoot, (string) $serving['space_id'], $host, $serving['users'], $cookie
    );
}
