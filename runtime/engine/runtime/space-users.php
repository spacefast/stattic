<?php
declare(strict_types=1);

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
    $hasSession = isset($_COOKIE['sfi_session']);
    $enabled = ($serving['users']['enabled'] ?? false) === true;
    if (!$route && !$start) return false;
    if (!$enabled) {
        if ($route) _stattic_problem_refused(404, 'space_users_disabled', 'Users is not enabled for this Space.');
        return false;
    }
    require_once __DIR__ . '/content-page.php';
    require_once __DIR__ . '/zero.php';
    $spaceId = (string) ($serving['space_id'] ?? '');
    $wpLoad = dirname(dirname($privateRoot)) . '/wp-load.php';
    if ($spaceId === '' || !is_file($wpLoad)) {
        _stattic_problem_refused(503, 'space_users_unavailable', 'Users is not ready on this Space.');
    }
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_once($privateRoot, $serving, 'space_users_auth');
    $GLOBALS['SPACEFAST_SPACE_USERS_PROVIDER_CONFIGURATION'] = $providerConfiguration;
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = _stattic_space_users_origin($host);
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
    if (!function_exists('spacefast_space_users_available') || !spacefast_space_users_available()) {
        _stattic_problem_refused(503, 'space_users_unavailable', 'Users is not installed on this Space yet.');
    }
    if ($hasSession) {
        _stattic_access_private_cache_flag(true);
    }
    if ($route) _stattic_space_users_dispatch($path, $method, $host);
    return false;
}

function _stattic_space_users_dispatch(string $path, string $method, string $host): never
{
    header('Cache-Control: private, no-store');
    if ($path === '/__zero/auth/complete') {
        if ($method !== 'GET') _stattic_method_not_allowed('GET');
        if (spacefast_space_users_auth() === null) _stattic_problem_refused(401, 'space_users_sign_in_required', 'Finish signing in to continue.');
        [$id, $binding] = array_pad(explode('.', (string) ($_COOKIE['sfi_app_return'] ?? ''), 2), 2, '');
        try {
            $challenge = \Spacefast\Identity\Plugin::$security->consume($id, 'space_app_return', $binding);
        } catch (\Spacefast\Identity\Failure $error) {
            _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
        }
        setcookie('sfi_app_return', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => _stattic_cookies_secure(), 'httponly' => true, 'samesite' => 'Lax']);
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
        $request = new WP_REST_Request($method, '/spacefast-identity/v1/' . $operation);
        $request->set_query_params($_GET);
        $request->set_header('origin', (string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $request->set_header('x-identity-csrf', (string) ($_SERVER['HTTP_X_IDENTITY_CSRF'] ?? ''));
        $request->set_header('content-type', (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/json'));
        $body = _stattic_bounded_request_body(1048576);
        if ($body === null) _stattic_problem_refused(413, 'space_users_request_too_large', 'The request is too large.');
        $request->set_body($body);
        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        foreach ($response->get_headers() as $name => $value) {
            if (strtolower($name) !== 'cache-control') header($name . ': ' . $value);
        }
        _stattic_zero_json_response($response->get_status(), $response->get_data());
    }
    if (str_starts_with($path, '/identity/assets/')) {
        $asset = substr($path, strlen('/identity/assets/'));
        if (!in_array($asset, ['build/hosted.js', 'build/hosted.css'], true)) {
            _stattic_problem_refused(404, 'space_users_asset_not_found', 'This asset is not available.');
        }
        if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
        $file = dirname(__DIR__, 2) . '/wordpress/spacefast-identity/' . $asset;
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
            $session = \Spacefast\Identity\Plugin::$sessions->require();
            \Spacefast\Identity\Plugin::$sessions->recent($session);
        } catch (\Spacefast\Identity\Failure $error) {
            _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
        }
        $payload = ['path' => '/identity/account', 'kind' => 'link', 'userId' => (int) $session['wp_user_id'], 'sessionId' => $session['id']];
    }
    $binding = \Spacefast\Identity\Security::token();
    $id = \Spacefast\Identity\Plugin::$security->challenge('space_native', 0, '', $payload, $binding, 300);
    setcookie('sfi_native_start', $id . '.' . $binding, [
        'expires' => time() + 300, 'path' => '/__zero/auth/native', 'secure' => _stattic_cookies_secure(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
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
    [$id, $binding] = array_pad(explode('.', (string) ($_COOKIE['sfi_native_start'] ?? ''), 2), 2, '');
    try {
        $challenge = \Spacefast\Identity\Plugin::$security->consume($id, 'space_native', $binding);
        $name = (string) ($verified['profile']['name'] ?? 'Spacefast user');
        if (($challenge['payload']['kind'] ?? '') === 'link') {
            $session = \Spacefast\Identity\Plugin::$sessions->require();
            if ((int) $session['wp_user_id'] !== ($challenge['payload']['userId'] ?? null)
                || $session['id'] !== ($challenge['payload']['sessionId'] ?? null)) {
                _stattic_problem_refused(403, 'link_session_invalid', 'Sign in to the account that started linking and try again.');
            }
            spacefast_space_users_link_native($account['issuer'], $account['subject'], $name, $session);
            $result = ['kind' => 'linked'];
        } else {
            $result = spacefast_space_users_identity_lock($account['issuer'], $account['subject'], static function () use ($account, $name): array {
            $userId = spacefast_content_principal_ensure_user([
                'kind' => 'user', 'issuer' => $account['issuer'], 'subject' => $account['subject'],
                'principal_id' => spacefast_content_principal_authority($account['issuer'], $account['subject']),
                'profile' => ['display_name' => $name],
            ]);
            if ($userId < 1) _stattic_problem_refused(503, 'space_users_account_unavailable', 'The app account is unavailable.');
            $resolved = \Spacefast\Identity\Plugin::$accounts->provider($account['issuer'], $account['subject'], null, $name);
            if ($resolved !== $userId) _stattic_problem_refused(409, 'space_users_identity_conflict', 'This identity belongs to a different account.');
            return \Spacefast\Identity\Plugin::$api->providerLogin($userId);
            });
        }
    } catch (\Spacefast\Identity\Failure $error) {
        _stattic_problem_refused($error->status, $error->kind, $error->getMessage());
    }
    setcookie('sfi_native_start', '', ['expires' => time() - 3600, 'path' => '/__zero/auth/native', 'secure' => _stattic_cookies_secure(), 'httponly' => true, 'samesite' => 'Lax']);
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
    try {
        $destination = (new \Spacefast\Identity\Providers(
            \Spacefast\Identity\Plugin::$security, \Spacefast\Identity\Plugin::$accounts,
            \Spacefast\Identity\Plugin::$sessions
        ))->start($provider);
    } catch (\Spacefast\Identity\Failure $error) {
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
    $expected = _stattic_space_users_origin($host);
    if (($origin !== '' && $origin !== $expected)
        || (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $origin !== $expected)
        || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        _stattic_problem_refused(403, 'space_users_origin_denied', 'Use this app from its own origin.');
    }
}

/** Live database verification stays outside the process that will execute tenant PHP. */
function _stattic_space_users_verify_session(string $privateRoot, string $spaceId, string $host, array $settings, string $cookie): ?array
{
    if (strlen($cookie) > 512) return null;
    require_once __DIR__ . '/../shared/native-process.php';
    $input = json_encode([
        'privateRoot' => $privateRoot, 'spaceId' => $spaceId, 'host' => (string) parse_url(_stattic_space_users_origin($host), PHP_URL_HOST) . (($port = parse_url(_stattic_space_users_origin($host), PHP_URL_PORT)) === null ? '' : ':' . $port),
        'scheme' => _stattic_request_scheme(), 'settings' => $settings, 'cookie' => $cookie,
    ], JSON_THROW_ON_ERROR);
    $result = _stattic_runtime_run_subprocess([
        _stattic_config_value('SPACEFAST_RUNTIME_WP_CLI_BIN') ?: 'wp',
        '--path=' . dirname(dirname($privateRoot)),
        '--require=' . __DIR__ . '/../entrypoints/space-users-session.php',
        'eval', 'spacefast_space_users_session_reply();',
    ], null, $input, null, 10000, 8192, 1024);
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0) {
        _stattic_problem_refused(503, 'space_users_verifier_unavailable', 'Account verification is temporarily unavailable.');
    }
    $reply = json_decode($result['stdout'], true);
    if (!is_array($reply) || !array_key_exists('data', $reply)) {
        _stattic_problem_refused(503, 'space_users_verifier_invalid', 'Account verification is temporarily unavailable.');
    }
    if ($reply['data'] === null) return null;
    $auth = $reply['data'];
    if (!is_array($auth) || !is_string($auth['userId'] ?? null)
        || preg_match('/\Ausr_[a-f0-9]{64}\z/', $auth['userId']) !== 1
        || !is_string($auth['displayName'] ?? null) || strlen($auth['displayName']) > 2048
        || ($auth['provider'] ?? null) !== 'space-users'
        || ($auth['isAuthenticated'] ?? null) !== true || ($auth['isGuest'] ?? null) !== false) {
        _stattic_problem_refused(503, 'space_users_verifier_invalid', 'Account verification is temporarily unavailable.');
    }
    return [
        'user' => ['id' => $auth['userId'], 'displayName' => $auth['displayName']],
        'userId' => $auth['userId'], 'displayName' => $auth['displayName'],
        'provider' => 'space-users', 'isAuthenticated' => true, 'isGuest' => false,
    ];
}

function _stattic_space_users_return_begin(string $returnPath): void
{
    $binding = \Spacefast\Identity\Security::token();
    $challenge = \Spacefast\Identity\Plugin::$security->challenge('space_app_return', 0, '', ['path' => $returnPath], $binding, 300);
    setcookie('sfi_app_return', $challenge . '.' . $binding, [
        'expires' => time() + 300, 'path' => '/', 'secure' => _stattic_cookies_secure(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function _stattic_space_users_origin(string $host): string
{
    $authority = (string) ($_SERVER['HTTP_HOST'] ?? $host);
    if (preg_match('/\A[a-zA-Z0-9.-]+(?::[1-9][0-9]{0,4})?\z/', $authority) !== 1
        || _stattic_normalize_hostname($authority) !== _stattic_normalize_hostname($host)) {
        $authority = $host;
    }
    return _stattic_request_scheme() . '://' . strtolower($authority);
}

function _stattic_space_users_request_auth(array $serving, string $host): ?array
{
    if (array_key_exists('SPACEFAST_SPACE_USERS_AUTH', $GLOBALS)) return $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'];
    $cookie = $_COOKIE['sfi_session'] ?? null;
    if (!is_string($cookie) || strlen($cookie) > 512) return null;
    $privateRoot = _stattic_access_private_root();
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_space_users_admit_origin(_stattic_runtime_request_method(), $host);
    if (empty($GLOBALS['SPACEFAST_PHP_FUNCTIONS_ADMISSION_ACQUIRED'])) _stattic_admission_acquire_once($privateRoot, $serving, 'space_users_verify');
    _stattic_access_private_cache_flag(true);
    return $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'] = _stattic_space_users_verify_session(
        $privateRoot, (string) $serving['space_id'], $host, $serving['users'], $cookie
    );
}
