<?php
declare(strict_types=1);

// Legacy access redemption entry points kept for backwards compatibility.
//
// Retirement milestone: `retire_legacy_surfaces` / S12 (`/__sf/redeem` and
// `/__/<token>`). Tracked in `apps/control-plane/src/compat/old-engine.ts`
// (S12 redeem) and `GET /superadmin/product-analytics/share-retirement`.

// The credential lives in one reserved path for one request only: exchange it,
// set the host-only session cookie, then move to the clean landing URL. Customer
// bytes are never served from a credential path.
function _stattic_access_handle_link_entry(
    array $serving,
    string $requestPath,
    string $requestHost
): void {
    $token = _stattic_access_entry_token($requestPath);
    if ($token === null) {
        return;
    }
    _stattic_access_redeem_link_entry($serving, $requestHost, $token);
}

// `/__sf/redeem`: the handoff address of a control plane that predates
// `/__spacefast/in/`, with every shape it sends there. A browser follows
// `GET ?sf_token=<handoff>&return=<path>` (with `silent=1` on a renewal nobody
// clicked for, or `display=popup` from a popup), and a released CLI posts the
// JSON handoff's `token` and `return` as a same-origin form. Same consumption
// and session as `/__spacefast/in/`. Delete with compat/old-engine.ts S12 once
// no control plane image or CLI release that sends it can reach a box.
function _stattic_access_handle_legacy_redeem(array $serving, string $requestHost): never
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    if (
        $method === 'POST'
        && (
            !_stattic_access_same_origin_post($requestHost)
            || strtolower(trim((string) strstr(($_SERVER['CONTENT_TYPE'] ?? '') . ';', ';', true)))
                !== 'application/x-www-form-urlencoded'
        )
    ) {
        _stattic_render_json_or_deny('access_origin_invalid', 'Cross-origin access submissions are not accepted.');
    }
    $fields = $method === 'POST' ? $_POST : $_GET;
    $tokenField = $method === 'POST' ? 'token' : 'sf_token';
    $token = is_string($fields[$tokenField] ?? null) ? trim((string) $fields[$tokenField]) : '';
    $returnTo = _stattic_safe_return_path(is_string($fields['return'] ?? null) ? (string) $fields['return'] : '/');
    $parsed = $token === '' ? null : _stattic_access_in_token(STATTIC_ACCESS_IN_PREFIX . $token);
    if ($parsed === null || $parsed[0] !== 'handoff' || $returnTo === null) {
        _stattic_render_access_route_not_found();
    }
    $storedRecord = null;
    $credential = _stattic_access_consume_handoff_token(
        $serving,
        _stattic_canonicalize_host($requestHost),
        $parsed[1],
        $storedRecord
    );
    if ($credential === null) {
        if ($method !== 'POST' && ($fields['silent'] ?? null) === '1') {
            _stattic_access_redirect($returnTo, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        }
        _stattic_render_access_route_not_found();
    }
    _stattic_access_set_session_cookie($credential);
    // As it always has: the saved record's principal decides, so a handoff
    // that attaches to a browser already signed in to an account keeps its
    // marker. The canonical route decides from the handoff's own claims.
    if (_stattic_access_principal_is_account((string) ($storedRecord['principal'] ?? ''))) {
        _stattic_access_remember_returning_account();
    }
    if ($method !== 'POST' && ($fields['display'] ?? '') === 'popup') {
        // The session cookie is already set browser-wide: tell the opener
        // (same origin only) and close. No token ever crosses postMessage.
        $nonce = bin2hex(random_bytes(16));
        header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        header('Referrer-Policy: no-referrer', true);
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; base-uri 'none'; frame-ancestors 'none'", true);
        header('Content-Type: text/html; charset=utf-8', true);
        echo '<!doctype html><meta name="referrer" content="no-referrer"><title>Signed in</title>'
            . '<p>You&#039;re in. You can close this window.</p>'
            . '<p><a href="' . _stattic_html_escape($returnTo) . '">Continue</a></p>'
            . '<script nonce="' . $nonce . '">'
            . 'if(window.opener){try{window.opener.postMessage("sf-access-complete",window.location.origin)}catch(e){}}'
            . 'window.close();'
            . '</script>';
        exit;
    }
    _stattic_access_redirect($returnTo, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
}
