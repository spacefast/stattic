<?php
declare(strict_types=1);

// Legacy pre-Sign-in-with-Spacefast account bounce (`accountUrl` +
// `browserState` + `sf_access_as` account hint) and S18 `request_verify`
// access-request status mapping.
//
// Retirement milestone: `retire_legacy_surfaces` / pre-SIWS S14 & S18.
// Tracked in `apps/control-plane/src/compat/old-engine.ts` (S14, S18) and
// `GET /superadmin/product-analytics/share-retirement`.

const STATTIC_ACCESS_BROWSER_STATE_COOKIE_PREFIX = '__Host-spacefast_access_state_';
const STATTIC_ACCESS_BROWSER_STATE_DEV_COOKIE_PREFIX = 'spacefast_access_state_dev_';
const STATTIC_ACCESS_BROWSER_STATE_SECONDS = 600;

// The control plane's no-grant bounce names the account it found: a visitor
// without authority gets no runtime session, so this host cannot know who it is.
// The hint has its own purpose, is never redeemable, and opens nothing. Its one
// power beyond display: a verified one marks this browser's invite request for
// that exact address as from a verified address.
const STATTIC_ACCESS_ACCOUNT_HINT_PARAM = 'sf_access_as';
const STATTIC_ACCESS_ACCOUNT_HINT_PURPOSE = 'account-hint';

function _stattic_access_browser_state_cookie_prefix(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_ACCESS_BROWSER_STATE_DEV_COOKIE_PREFIX
        : STATTIC_ACCESS_BROWSER_STATE_COOKIE_PREFIX;
}

function _stattic_access_browser_state_cookie_name(string $state): string
{
    return _stattic_access_browser_state_cookie_prefix() . substr($state, 0, 16);
}

function _stattic_access_browser_state_from_request(?string $expected = null): string
{
    if ($expected !== null) {
        $value = $_COOKIE[_stattic_access_browser_state_cookie_name($expected)] ?? '';
        return is_string($value) && hash_equals($expected, $value) ? $value : '';
    }
    $prefix = _stattic_access_browser_state_cookie_prefix();
    foreach ($_COOKIE as $name => $value) {
        if (
            is_string($name)
            && is_string($value)
            && str_starts_with($name, $prefix)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1
            && hash_equals($name, _stattic_access_browser_state_cookie_name($value))
        ) {
            return $value;
        }
    }
    return '';
}

// Each nonce gets its own short-lived cookie name, so concurrent first-time
// starts cannot overwrite each other before their callbacks return. Sequential
// starts reuse an existing nonce and avoid growing the cookie jar.
function _stattic_access_browser_state_begin(): string
{
    static $state = null;
    if (is_string($state)) {
        return $state;
    }
    $state = _stattic_access_browser_state_from_request();
    if ($state !== '') {
        return $state;
    }
    $state = bin2hex(random_bytes(32));
    _stattic_set_cookie(
        _stattic_access_browser_state_cookie_name($state),
        $state,
        STATTIC_ACCESS_BROWSER_STATE_SECONDS
    );
    return $state;
}

// The token's browserState claim when this browser holds the matching state
// cookie, else ''.
function _stattic_access_claims_browser_state(array $claims): string
{
    $tokenState = $claims['browserState'] ?? null;
    if (!is_string($tokenState) || preg_match('/\A[a-f0-9]{64}\z/D', $tokenState) !== 1) {
        return '';
    }
    return _stattic_access_browser_state_from_request($tokenState);
}

// ['email' => ..., 'token' => ..., 'browserState' => ...] for a hint that
// verifies here, else null.
function _stattic_access_account_hint(array $serving, string $host, mixed $token): ?array
{
    if (!is_string($token) || $token === '' || strlen($token) > 4096) {
        return null;
    }
    $verified = _stattic_visitor_verify(
        $token,
        _stattic_visitor_verify_options($serving, $host, null, ['requireJti' => false])
    );
    $claims = is_array($verified) && is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    if (($claims['purpose'] ?? null) !== STATTIC_ACCESS_ACCOUNT_HINT_PURPOSE) {
        return null;
    }
    // Bound to the browser that started the sign-in, so a forwarded URL never
    // shows one person's address in someone else's browser.
    $browserState = _stattic_access_claims_browser_state($claims);
    if ($browserState === '') {
        return null;
    }
    $email = is_string($claims['email'] ?? null) ? trim($claims['email']) : '';
    if ($email === '' || strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return ['email' => $email, 'token' => $token, 'browserState' => $browserState];
}

// Pre-SIWS account-start bounce to the control plane's `accountUrl` with a
// browser-bound `browserState` cookie.
function _stattic_access_legacy_account_start_redirect(
    string $accountUrl,
    string $host,
    string $returnPath
): never {
    $redirect = _stattic_access_url_with_params($accountUrl, [
        'host' => $host,
        'return' => $returnPath,
        'browserState' => _stattic_access_browser_state_begin(),
        // "Use another account": sign in as someone else, not re-check this one.
        // The bar's Switch Account asks with `prompt=select_account`, also on a
        // page still open after Sign in with Spacefast was switched off.
        ...((($_GET['addAccount'] ?? null) === '1' || ($_GET['prompt'] ?? null) === 'select_account')
            ? ['addAccount' => '1'] : []),
    ]);
    _stattic_access_redirect($redirect, 302, STATTIC_CACHE_CONTROL_NO_STORE, true);
}

// Pre-SIWS silent account probe bounce to the control plane's `accountUrl`.
function _stattic_access_legacy_silent_sso_redirect(
    array $serving,
    string $requestHost,
    string $host,
    string $accountUrl,
    string $returnPath,
    ?array $identity,
    bool $expired
): never {
    $redirectParams = [
        'silent' => '1',
        'host' => $host,
        'return' => $returnPath,
        'browserState' => _stattic_access_browser_state_begin(),
    ];
    // A silent recovery with no upstream session must still preserve
    // the expiry diagnosis instead of the generic signed-out page.
    if ($expired) {
        $redirectParams['fallback'] = 'session-expired';
    }
    $redirect = _stattic_access_url_with_params($accountUrl, $redirectParams);
    // Win or lose, the probe ran: recording it before leaving is what
    // stops a bounce that finds nothing from looping. That hands this
    // browser a stateless session, so the bounce cannot be cacheable.
    _stattic_access_record_identity_check($serving, $requestHost, $identity);
    _stattic_access_redirect($redirect, 302, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE);
}

// The control plane says whether anything is left to confirm: `sent` means
// the request is with the owner. An older one answers `pending` and mails
// an unhinted visitor a confirmation link first (control planes before
// #5288, such as prod/2026.11.13-1321). old-engine-compat: S18 — delete the
// `pending` reading and the `request-confirm` status once no control-plane
// image a box can reach, including a rollback target, has the
// `request_verify` handoff flow.
function _stattic_access_legacy_request_invite_status(mixed $responseBody, string $accountHint): string
{
    $sent = is_array($responseBody)
        && (($responseBody['data']['status'] ?? null) === 'sent');
    return $sent
        ? 'request-sent'
        : ($accountHint === '' ? 'request-confirm' : 'request-pending');
}
