<?php
declare(strict_types=1);

const STATTIC_SHARE_OAUTH_ISSUER_PATH = '/v1/auth';

// Sign-in is the standard authorization code flow against Spacefast's OpenID
// Connect server. Only new engines receive this account URL, its authorization
// endpoint. Old projections keep account acquisition.
function _stattic_share_oauth_url(array $serving): ?string
{
    $url = _stattic_access_page_descriptor($serving)['accountUrl'] ?? null;
    return _stattic_share_oauth_is_account_url($url) ? $url : null;
}

function _stattic_share_oauth_is_account_url(mixed $url): bool
{
    return is_string($url) && parse_url($url, PHP_URL_PATH) === STATTIC_SHARE_OAUTH_ISSUER_PATH . '/oauth2/authorize';
}

// The issuer the control plane configured. A split auth host or the dev relay
// puts it on another origin than the authorize URL, so it is never derived here.
// Its token endpoint, and the `iss` every authorization response must name
// (RFC 9207), hang off it. A projection without one cannot finish a sign-in.
function _stattic_share_oauth_issuer(array $serving): ?string
{
    $issuer = _stattic_access_page_descriptor($serving)['issuer'] ?? null;
    return is_string($issuer) && $issuer !== '' ? $issuer : null;
}

// The token endpoint this box calls: the one the control plane named, else the
// issuer's own.
function _stattic_share_oauth_token_url(array $serving, string $issuer): string
{
    $url = _stattic_access_page_descriptor($serving)['tokenUrl'] ?? null;
    return is_string($url) && $url !== '' ? $url : $issuer . '/oauth2/token';
}

function _stattic_share_oauth_cookie_name(array $serving): string
{
    return '__Host-spacefast_oauth_' . _stattic_serving_space_id($serving);
}

function _stattic_share_oauth_pending(array $serving, string $host): array
{
    $cookie = $_COOKIE[_stattic_share_oauth_cookie_name($serving)] ?? '';
    $payload = is_string($cookie)
        ? _stattic_signed_cookie_decode($serving, $host, 'sfo2_', 'share-oauth', 3072, $cookie) : null;
    $pending = $payload['pending'] ?? [];
    if (!is_array($pending)) return [];
    return array_values(array_filter(array_slice($pending, -3), static fn ($entry): bool =>
        is_array($entry) && is_string($entry['state'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/D', $entry['state']) === 1
        && is_string($entry['return'] ?? null) && is_int($entry['expiresAt'] ?? null)
        && $entry['expiresAt'] > _stattic_access_session_now()));
}

function _stattic_share_oauth_set_pending(array $serving, string $host, array $pending): void
{
    $name = _stattic_share_oauth_cookie_name($serving);
    if ($pending === []) {
        _stattic_clear_cookie($name);
        return;
    }
    $cookie = _stattic_signed_cookie_encode($serving, $host, 'sfo2_', 'share-oauth', 3072, ['pending' => $pending]);
    if ($cookie === null) _stattic_render_access_route_not_found();
    _stattic_set_cookie($name, $cookie, 600);
}

// How long the gate's proof that it started a sign-in lives (`sf_gate`).
const STATTIC_SHARE_GATE_PROOF_SECONDS = 120;

// The access gate's proof that it started this sign-in, for a page this
// visitor could not open (`runtime.share-gate-proof.v1`): a JWS signed with the
// Space's own Ed25519 key (shared/space-identity.php), which never leaves this
// box, over the exact runtime callback, the request the gate refused (its path
// and query, the flow's own params left out) and this sign-in's PKCE
// challenge, alive two minutes. Only such a start may sign a member in through
// company SSO without a page (apps/control-plane/src/share/gate-proof.ts).
// Null, so the start reads as the app's, until the control plane has
// registered the key it would verify with.
function _stattic_share_gate_proof(array $serving, string $host, string $deniedResource, string $challenge): ?string
{
    $issuer = _stattic_share_oauth_issuer($serving);
    if ($issuer === null) return null;
    require_once __DIR__ . '/../shared/space-identity.php';
    $spaceId = _stattic_serving_space_id($serving);
    $config = _stattic_space_identity_config($serving, _stattic_access_private_root(), $spaceId);
    if ($config === null) return null;
    $now = _stattic_access_session_now();
    $header = ['alg' => 'EdDSA', 'typ' => 'sf-gate+jwt', 'kid' => $config['key']['kid']];
    $payload = [
        'iss' => $config['clientId'],
        'sub' => $spaceId,
        'aud' => $issuer,
        'host' => $host,
        'cb' => 'https://' . $host . STATTIC_SHARE_OAUTH_CALLBACK_PATH,
        'res' => $deniedResource,
        'cc' => $challenge,
        'iat' => $now,
        'exp' => $now + STATTIC_SHARE_GATE_PROOF_SECONDS,
    ];
    $input = _stattic_base64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . _stattic_base64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    $secret = (string) base64_decode($config['key']['secret'], true);
    $signature = sodium_crypto_sign_detached($input, $secret);
    sodium_memzero($secret);
    return $input . '.' . _stattic_base64url_encode($signature);
}

// A silent begin is one nobody clicked for: the gate's probe, a link holder's
// identity check or a returning account's renewal. Its refusal lands back on
// the page it left, and $expired keeps the dead session's diagnosis for it.
// $deniedResource is set only by the access gate's own probe for a page this
// visitor cannot open, which it names, and that start carries the gate's
// proof; `/__spacefast/sign-in` (an app's `signIn()`, the bar's "Switch
// account", a returning account's renewal) never passes it, since any page or
// app on the Space can send a browser there.
function _stattic_share_oauth_begin(array $serving, string $host, string $returnPath, bool $silent, bool $expired = false, ?string $deniedResource = null): never
{
    header('Referrer-Policy: no-referrer');
    $url = _stattic_share_oauth_url($serving);
    if ($url === null) _stattic_render_access_route_not_found();
    // An invite email's one-use token, passed through untouched: only the API
    // reads it, and nothing is accepted until the person clicks on the door.
    $invite = $_GET['invite'] ?? null;
    if (!is_string($invite) || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $invite) !== 1) $invite = null;
    // The state lives in this browser's signed, host-only cookie and nowhere
    // else; the PKCE verifier and the nonce are derived from it under this
    // Space's key. A callback carrying another browser's code and state finds no
    // entry here and signs nobody in (login CSRF).
    $state = bin2hex(random_bytes(32));
    $key = _stattic_access_session_hmac_key($serving, 'share-oauth');
    if ($key === null) _stattic_render_access_route_not_found();
    $returnPath = _stattic_safe_return_path($returnPath) ?? '/';
    // Keep three entries below the browser's 4 KiB cookie limit, including JSON escaping.
    if (strlen(json_encode($returnPath, JSON_THROW_ON_ERROR)) > 512) $returnPath = '/';
    $pending = array_slice(_stattic_share_oauth_pending($serving, $host), -2);
    $pending[] = [
        'state' => $state,
        'return' => $returnPath,
        ...($invite !== null ? ['invite' => $invite] : []),
        ...($silent ? ['silent' => true] : []),
        ...($silent && $expired ? ['expired' => true] : []),
        'expiresAt' => _stattic_access_session_now() + 600,
    ];
    _stattic_share_oauth_set_pending($serving, $host, $pending);
    $verifier = hash_hmac('sha256', 'share-oauth-pkce:' . $state, $key);
    $nonce = hash_hmac('sha256', 'share-oauth-nonce:' . $state, $key);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $proof = $deniedResource !== null
        ? _stattic_share_gate_proof($serving, $host, $deniedResource, $challenge)
        : null;
    $redirect = _stattic_access_url_with_params($url, [
        'client_id' => 'spacefast-share:' . _stattic_serving_space_id($serving),
        'response_type' => 'code',
        'redirect_uri' => 'https://' . $host . STATTIC_SHARE_OAUTH_CALLBACK_PATH,
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => $nonce,
        'return' => $returnPath,
        ...($invite !== null ? ['invite' => $invite] : []),
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        ...($proof !== null ? ['sf_gate' => $proof] : []),
        // "Switch account" from the Space bar and the gate's "Use another
        // account": always ask which account signs in to this Space. The
        // dashboard keeps its own. `addAccount=1` is the older gate's spelling,
        // still on pages rendered before this engine took the new sign-in.
        ...($silent ? ['prompt' => 'none']
            : ((($_GET['prompt'] ?? null) === 'select_account' || ($_GET['addAccount'] ?? null) === '1')
                ? ['prompt' => 'select_account'] : [])),
    ]);
    _stattic_access_redirect($redirect, 302, STATTIC_CACHE_CONTROL_NO_STORE, true);
}

// The API claims each challenge and allows one successful exchange per challenge.
function _stattic_share_oauth_consume(array $serving, string $host, string $state): ?array
{
    $pending = _stattic_share_oauth_pending($serving, $host);
    foreach ($pending as $index => $entry) {
        if ($entry['state'] !== $state) continue;
        $key = _stattic_access_session_hmac_key($serving, 'share-oauth');
        if ($key === null) return null;
        unset($pending[$index]);
        _stattic_share_oauth_set_pending($serving, $host, array_values($pending));
        return [
            ...$entry,
            'verifier' => hash_hmac('sha256', 'share-oauth-pkce:' . $state, $key),
            'nonce' => hash_hmac('sha256', 'share-oauth-nonce:' . $state, $key),
        ];
    }
    return null;
}

// A callback this browser never started, or started too long ago: no code goes
// anywhere, and the visitor gets a way to start over instead of a bare refusal.
function _stattic_share_oauth_restart(): never
{
    $message = 'Start sign-in again in this browser.';
    if (_stattic_request_is_fetch()) {
        _stattic_render_json_unauthenticated('invalid_state');
    }
    _stattic_serve_page('sign-in-restart', [
        'status' => 403,
        'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, 'Referrer-Policy' => 'no-referrer'],
        'private' => true,
        'message' => $message,
        'code' => 'invalid_state',
    ]);
    exit;
}

function _stattic_share_oauth_callback(array $serving, string $requestHost): never
{
    if (_stattic_runtime_request_method() !== 'GET') _stattic_method_not_allowed('GET');
    header('Referrer-Policy: no-referrer');
    $url = _stattic_share_oauth_url($serving);
    if ($url === null) _stattic_render_access_route_not_found();
    $host = _stattic_canonicalize_host($requestHost);
    $issuer = _stattic_share_oauth_issuer($serving);
    if ($issuer === null) _stattic_render_access_route_not_found();
    // RFC 9207: the response must name the issuer this sign-in went to. One that
    // names another, or none, was not meant for it, and its code goes nowhere.
    if (!is_string($_GET['iss'] ?? null) || !hash_equals($issuer, $_GET['iss'])) {
        _stattic_share_oauth_restart();
    }
    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    $record = _stattic_share_oauth_consume($serving, $host, $state);
    if ($record === null) _stattic_share_oauth_restart();
    $returnPath = _stattic_safe_return_path($record['return'] ?? '/') ?? '/';
    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    if ($code === '' || strlen($code) > 512) {
        if (($record['silent'] ?? false) === true) {
            if (($record['expired'] ?? false) === true) {
                _stattic_access_gate_after_post($serving, $host, $returnPath, 'session-expired');
            }
            _stattic_access_redirect($returnPath, 303);
        }
        _stattic_access_gate_after_post($serving, $host, $returnPath, 'no-grant');
    }
    $exchange = _stattic_access_page_exchange($serving);
    if ($exchange === null) _stattic_render_access_route_not_found();
    // The standard token request. This runtime's credential is what adds the
    // session handoff to the standard response.
    $fields = [
        'grant_type' => 'authorization_code',
        'client_id' => 'spacefast-share:' . _stattic_serving_space_id($serving),
        'redirect_uri' => 'https://' . $host . STATTIC_SHARE_OAUTH_CALLBACK_PATH,
        'code' => $code,
        'code_verifier' => $record['verifier'],
    ];
    $headers = [
        ..._stattic_access_exchange_headers($exchange, _stattic_access_context($serving, $host, '/'), 'application/json'),
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $result = _stattic_access_exchange_post(_stattic_share_oauth_token_url($serving, $issuer), $fields, $headers);
    // An older control plane refuses any Authorization on a Space client's
    // exchange (`invalid_client`) before it spends the code. Send it once more
    // with the older credential alone; the code is still unspent.
    if (($result['status'] ?? null) === 401 && ($result['body']['error'] ?? null) === 'invalid_client') {
        $withoutToken = array_values(array_filter($headers, static fn (string $header): bool => stripos($header, 'authorization:') !== 0));
        if (count($withoutToken) !== count($headers)) {
            require_once __DIR__ . '/../shared/space-identity.php';
            _stattic_space_identity_refused(_stattic_access_private_root(), _stattic_serving_space_id($serving), 401);
            $result = _stattic_access_exchange_post(_stattic_share_oauth_token_url($serving, $issuer), $fields, $withoutToken);
        }
    }
    $token = $result['body']['spacefast_handoff']['token'] ?? null;
    if (($result['status'] ?? null) === 200 && is_string($token)) {
        $stored = null;
        $session = _stattic_access_consume_handoff_token($serving, $host, $token, $stored, replaceSession: true, expectedNonce: $record['nonce']);
        if ($session !== null) {
            _stattic_access_set_session_cookie($session);
            // The marker lets the next expiry renew silently instead of at the gate.
            if (_stattic_access_principal_is_account((string) ($stored['principal'] ?? ''))) {
                _stattic_access_remember_returning_account();
            }
            _stattic_access_redirect($returnPath, 303);
        }
    }
    if (($record['silent'] ?? false) === true) {
        _stattic_access_redirect($returnPath, 303);
    }
    _stattic_access_gate_after_post($serving, $host, $returnPath,
        ($result['status'] ?? null) === 429 ? 'rate-limited' : 'exchange-unavailable');
}
