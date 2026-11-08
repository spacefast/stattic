<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/response.php';

require_once __DIR__ . '/access-rules.php';

/**
 * Returns visitor identity only from a server-owned FastCGI parameter.
 *
 * A request header named Spacefast-Visitor-Ip lands in
 * HTTP_SPACEFAST_VISITOR_IP and cannot reach this key. REMOTE_ADDR is also
 * excluded: on wp.cloud it is the provider proxy, not the browser. The ingress
 * must set SPACEFAST_VISITOR_IP per request after resolving its trusted peer.
 */
function _stattic_zero_trusted_visitor_ip(): ?string
{
    $serverValue = $_SERVER['SPACEFAST_VISITOR_IP'] ?? getenv('SPACEFAST_VISITOR_IP');
    $candidate = is_string($serverValue) ? trim($serverValue) : '';
    return $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false
        ? $candidate
        : null;
}

function _stattic_zero_send_auth_redirect(
    array $config,
    array $serving,
    string $operation,
    string $requestMethod,
    string $requestHost
): void
{
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    $returnToParam = is_string($auth['returnToParam'] ?? null) && $auth['returnToParam'] !== ''
        ? (string) $auth['returnToParam']
        : 'returnTo';
    $returnTo = _stattic_zero_auth_return_to($requestHost, $returnToParam);
    $path = parse_url($returnTo, PHP_URL_PATH);
    $query = parse_url($returnTo, PHP_URL_QUERY);
    $returnPath = (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    $usersEnabled = ($serving['users']['enabled'] ?? false) === true;
    if ($operation === 'auth_start') {
        $provider = is_string($_GET['provider'] ?? null) ? $_GET['provider'] : 'spacefast';
        if (!in_array($provider, ['google', 'gravatar', 'spacefast'], true)) {
            _stattic_problem_refused(400, 'space_users_provider_invalid', 'Choose a supported sign-in method.');
        }
        // Capsules built against the SDK have always named their provider on
        // this route, and a Space that never opted into Users still has to
        // serve them: fall through to the hosted account flow below instead of
        // refusing. Only a Space WITH Users on owes a configured provider.
        if ($provider !== 'spacefast' && $usersEnabled) {
            if (!function_exists('spacefast_space_users_available') || !spacefast_space_users_available()) {
                _stattic_problem_refused(503, 'space_users_provider_unavailable', 'Enable Users and configure this sign-in method first.');
            }
            _stattic_space_users_start($provider, $returnPath);
        }
        if ($usersEnabled && ($serving['users']['providers']['spacefast']['enabled'] ?? false) !== true) {
            _stattic_problem_refused(403, 'space_users_provider_disabled', 'Spacefast sign-in is not enabled for this Space.');
        }
        if ($usersEnabled) $returnPath = _stattic_space_users_native_begin($returnPath);
    }
    if ($operation === 'auth_sign_out' && $requestMethod === 'POST') {
        if (!$usersEnabled || !function_exists('spacefast_space_users_live_session')) {
            _stattic_problem_refused(404, 'space_users_disabled', 'Users is not enabled for this Space.');
        }
        spacefast_space_users_identity('Api')::checkOrigin((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), true);
        $session = spacefast_space_users_live_session();
        if ($session !== null) spacefast_space_users_identity('Plugin')::$sessions->revoke((int) $session['wp_user_id'], $session['id']);
        spacefast_space_users_identity('Sessions')::cookie('', time() - 3600);
        _stattic_zero_json_response(200, ['data' => ['signedOut' => true]]);
    }
    if ($operation === 'auth_sign_out') {
        // The one runtime logout route: it clears the single host session
        // cookie. There is no Zero-specific cookie.
        $redirect = 'https://' . $requestHost . STATTIC_ACCESS_LOGOUT_PATH . '?return=' . rawurlencode($returnPath);
    } else {
        $browserState = _stattic_access_browser_state_begin();
        $descriptor = _stattic_access_page_descriptor($serving);
        $accountUrl = is_array($descriptor) && is_string($descriptor['accountUrl'] ?? null)
            ? $descriptor['accountUrl']
            : '';
        if ($accountUrl !== '') {
            $separator = str_contains($accountUrl, '?') ? '&' : '?';
            $redirect = $accountUrl
                . $separator . 'host=' . rawurlencode(_stattic_zero_hostname_without_port($requestHost))
                . '&return=' . rawurlencode($returnPath)
                . '&browserState=' . rawurlencode($browserState)
                . (($_GET['provider'] ?? '') === 'google' ? '&provider=google' : '');
        } else {
            $target = is_string($auth['signInUrl'] ?? null) ? trim((string) $auth['signInUrl']) : '';
            if (!_stattic_platform_destination_allowed($target)) {
                _stattic_problem_refused(404, 'zero_auth_unavailable', 'Zero hosted auth is not configured.');
            }
            $redirect = _stattic_zero_auth_url_with_return_to(
                $target,
                $returnToParam,
                $requestHost,
                $browserState
            );
        }
    }
    http_response_code(302);
    header('Location: ' . $redirect, true, 302);
    header('Cache-Control: no-store', false);
    if ($requestMethod !== 'HEAD') {
        echo "Redirecting.\n";
    }
    exit;
}

// Identity is the principal alone; what the session may do never decides who it is.
function _stattic_zero_auth_context(array $serving, string $requestHost): array
{
    if (($serving['users']['enabled'] ?? false) === true) {
        require_once __DIR__ . '/space-users.php';
        return (function_exists('spacefast_space_users_auth') ? spacefast_space_users_auth() : _stattic_space_users_request_auth($serving, $requestHost))
            ?? _stattic_zero_guest_auth_context();
    }
    $verified = _stattic_verify_cookie_identity($serving, $requestHost);
    $principal = _stattic_access_identity_principal($verified);
    if (_stattic_access_principal_is_identified($principal)) {
        return _stattic_zero_identity_from_principal($verified);
    }

    // The document response already minted this for a Zero page's first render
    // (_stattic_zero_prime_anonymous_document_session), so the ordinary path here
    // just reads the cookie back. Minting stays as the fallback for a request
    // that reached an endpoint without one — an edge-cached document, a direct
    // API call, a client that never loaded a page.
    $record = _stattic_access_identity_record($verified) ?? [];
    $anonymousId = _stattic_collab_anonymous_id($record)
        ?? _stattic_anonymous_session_ensure_anonymous_id($serving, $requestHost);
    return _stattic_zero_guest_auth_context($anonymousId);
}

function _stattic_zero_guest_auth_context(?string $anonymousId = null): array
{
    $guestName = $anonymousId ?? 'local';
    return [
        'user' => null,
        'userId' => 'guest:' . $guestName,
        'displayName' => 'Guest',
        'provider' => 'guest',
        'isGuest' => true,
        'isSignedIn' => false,
        'isAuthenticated' => false,
    ];
}

function _stattic_zero_identity_from_principal(?array $verified): array
{
    $principal = _stattic_access_identity_principal($verified);
    if (!_stattic_access_principal_is_identified($principal)) {
        return _stattic_zero_guest_auth_context();
    }

    $profile = _stattic_access_public_profile($verified['profile'] ?? null) ?? [];
    $displayName = is_string($profile['name'] ?? null)
        ? $profile['name']
        : (is_string($profile['username'] ?? null) ? $profile['username'] : $principal);
    $avatarUrl = is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null;
    $profileUrl = _stattic_zero_gravatar_profile_url($avatarUrl);
    $user = ['id' => $principal, 'displayName' => $displayName];
    if ($avatarUrl !== null) {
        $user['picture'] = $avatarUrl;
    }
    if ($profileUrl !== null) {
        $user['profileUrl'] = $profileUrl;
    }
    return [
        'user' => $user,
        'userId' => $principal,
        'provider' => in_array($verified['authProvider'] ?? null, ['google', 'gravatar'], true) ? $verified['authProvider'] : 'account',
        'isGuest' => false,
        'isSignedIn' => true,
        'isAuthenticated' => true,
        'displayName' => $displayName,
        ...($avatarUrl !== null ? ['picture' => $avatarUrl] : []),
        ...($profileUrl !== null ? ['profileUrl' => $profileUrl] : []),
    ];
}

// The avatar URL carries the profile hash, so the profile page derives without
// ever touching the email behind it.
function _stattic_zero_gravatar_profile_url(?string $avatarUrl): ?string
{
    if ($avatarUrl === null) {
        return null;
    }
    $pattern = '~\Ahttps?://(?:[a-z0-9-]+\.)?gravatar\.com/avatar/([a-fA-F0-9]{32,64})(?:[/?#]|\z)~';
    if (preg_match($pattern, $avatarUrl, $matches) !== 1) {
        return null;
    }
    return 'https://gravatar.com/' . strtolower($matches[1]);
}

function _stattic_zero_auth_url_with_return_to(
    string $target,
    string $returnToParam,
    string $requestHost,
    string $browserState
): string
{
    $returnTo = _stattic_zero_auth_return_to($requestHost, $returnToParam);
    $separator = str_contains($target, '?') ? '&' : '?';
    return $target . $separator . rawurlencode($returnToParam) . '=' . rawurlencode($returnTo)
        . '&browserState=' . rawurlencode($browserState)
                . (($_GET['provider'] ?? '') === 'google' ? '&provider=google' : '');
}

function _stattic_zero_auth_return_to(string $requestHost, string $returnToParam): string
{
    $rawValue = $_GET[$returnToParam] ?? null;
    $raw = is_string($rawValue) ? trim($rawValue) : '';
    if ($raw !== '') {
        $safe = _stattic_zero_safe_return_to($raw, $requestHost);
        if ($safe !== null) {
            return $safe;
        }
    }
    return 'https://' . $requestHost . '/';
}

function _stattic_zero_safe_return_to(string $value, string $requestHost): ?string
{
    if (strlen($value) > 2048 || str_contains($value, "\0")) {
        return null;
    }
    $origin = 'https://' . $requestHost;
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return $origin . $value;
    }
    $parts = parse_url($value);
    $originParts = parse_url($origin);
    if (!is_array($parts) || !is_array($originParts)) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $expectedScheme = strtolower((string) ($originParts['scheme'] ?? ''));
    $expectedHost = strtolower((string) ($originParts['host'] ?? _stattic_zero_hostname_without_port($requestHost)));
    if (
        ($scheme !== 'http' && $scheme !== 'https')
        || $scheme !== $expectedScheme
        || $host === ''
        || $host !== $expectedHost
        || isset($parts['user'])
        || isset($parts['pass'])
        || _stattic_zero_effective_url_port($parts) !== _stattic_zero_effective_url_port($originParts)
    ) {
        return null;
    }
    return $value;
}

function _stattic_zero_effective_url_port(array $parts): int
{
    if (isset($parts['port']) && is_int($parts['port'])) {
        return $parts['port'];
    }
    return strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443;
}

function _stattic_zero_hostname_without_port(string $host): string
{
    if (str_starts_with($host, '[')) {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 1, $end - 1);
    }
    $parts = explode(':', $host, 2);
    return array_first($parts) ?? $host;
}

// The zero lane's whole contribution to the shared emitter is its cache policy
// (see the no-store note at the top of this file). Endpoint-lane refusals are
// RFC 9457 problem documents; the run lane instead keeps `ok:false` frames
// (_stattic_zero_send_run_frame) because its reader discriminates on `ok`, not
// on status.
function _stattic_zero_json_response(int $status, array $body): never
{
    _stattic_json_response($status, $body, 'application/json', ['Cache-Control' => 'no-store']);
}
