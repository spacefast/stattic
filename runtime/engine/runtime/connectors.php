<?php
declare(strict_types=1);

// A version's connector roles, served to its own pages at
// `/__spacefast/connectors/<role>/...`. serve.php dispatches here only after the
// Space access check admitted this path, the same check every page passes, so a
// page's data is never more public than the Space. Every answer is
// `private, no-store`: it belongs to one viewer, or to a Space that viewer was
// allowed to read.
//
// The control plane decides everything about the call (role, tool list, pinned
// arguments, read-only kinds, policy, approvals). This file only proves who is
// asking and from where, and relays the verdict.

require_once __DIR__ . '/../shared/request-origin.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/http.php';
require_once __DIR__ . '/access-rules.php';

const STATTIC_CONNECTORS_MAX_BODY_BYTES = 64 * 1024;
const STATTIC_CONNECTORS_ARGS_PARAM = '__args';
// The control plane holds a call up to 25 s for an approval; the transport
// waits a little longer so a parked run comes back as its state, not a timeout.
const STATTIC_CONNECTORS_CALL_TIMEOUT_SECONDS = 30;
// Call states a page renders as an empty state rather than an error.
const STATTIC_CONNECTORS_PAGE_STATES = ['unbound', 'connect_required', 'reconnect_required', 'approval_pending', 'running'];
const STATTIC_CONNECTORS_RESPONSE_HEADERS = [
    'Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
    STATTIC_EDGE_CACHE_HEADER => 'no-cache',
];

function _stattic_connectors_refuse(int $status, string $code, string $message): never
{
    _stattic_problem_response($status, $code, $message, [], STATTIC_CONNECTORS_RESPONSE_HEADERS);
}

/**
 * The viewer, as the same subject a Zero handler sends for them: the Space
 * Users account when the Space has Users on, otherwise the signed-in access
 * principal; null when signed out. Read from the session alone, never from a
 * request header, so a page cannot name its own visitor.
 *
 * `$navigation` marks `connect` and `callback`: top-level GETs that arrive at
 * the end of a cross-site redirect chain (the Users sign-in, the provider),
 * which the Users origin rule would refuse. Neither acts for anyone but the
 * viewer, and the sealed OAuth state binds a callback to the one who started it.
 *
 * @return array{subject: string}|null
 */
function _stattic_connectors_visitor(array $serving, string $host, bool $navigation = false): ?array
{
    if (_stattic_connectors_users_enabled($serving)) {
        require_once __DIR__ . '/space-users.php';
        $auth = _stattic_space_users_request_auth($serving, $host, !$navigation);
        return is_string($auth['userId'] ?? null) ? ['subject' => $auth['userId']] : null;
    }
    if (_stattic_visitor_cookie_from_request() === '') {
        return null;
    }
    $principal = _stattic_access_identity_principal(_stattic_verify_cookie_identity($serving, $host));
    return _stattic_access_principal_is_identified($principal) ? ['subject' => $principal] : null;
}

function _stattic_connectors_users_enabled(array $serving): bool
{
    return ($serving['users']['enabled'] ?? false) === true;
}

/**
 * Where a signed-out viewer signs in before returning to `$return`: the
 * Space's own Users sign-in when Users is on (the first provider it enables),
 * otherwise the hosted account flow.
 */
function _stattic_connectors_sign_in_url(array $serving, string $return): string
{
    if (!_stattic_connectors_users_enabled($serving)) {
        return STATTIC_ACCESS_ACCOUNT_START_PATH . '?return=' . rawurlencode($return);
    }
    $providers = is_array($serving['users']['providers'] ?? null) ? $serving['users']['providers'] : [];
    $provider = match (true) {
        ($providers['spacefast']['enabled'] ?? false) === true => 'spacefast',
        ($providers['google']['mode'] ?? 'disabled') !== 'disabled' => 'google',
        ($providers['gravatar']['enabled'] ?? false) === true => 'gravatar',
        default => 'spacefast',
    };
    return STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/auth/start?provider=' . $provider . '&returnTo=' . rawurlencode($return);
}

/**
 * A data read from the Space's own pages. Refused when the browser says it
 * came from elsewhere: `Sec-Fetch-Site` other than same-origin, or an `Origin`
 * that is not this host. A same-origin GET may carry neither (Safari before
 * 16.4 sends no fetch metadata, and browsers omit Origin on same-origin GET),
 * so a request with neither is allowed. That admits a non-browser caller too,
 * but one could forge both headers anyway: this check keeps other sites'
 * pages from reading with the viewer's cookies, nothing more. Callers outside
 * the Space use the Spacefast API with their own credential.
 */
function _stattic_connectors_not_cross_site(string $host): bool
{
    $origin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '' && $site !== 'same-origin') {
        return false;
    }
    return $origin === '' || hash_equals(strtolower(_stattic_runtime_request_origin($host)), $origin);
}

/**
 * GET: every query parameter is a string argument, and `__args` carries a JSON
 * object whose typed values win. The access flow's own parameters (`__`, the
 * `sf_access*` hints) are the runtime's, never the tool's. POST: the JSON body
 * is the arguments.
 *
 * @return array<string,mixed>
 */
function _stattic_connectors_call_args(string $method): array
{
    if ($method === 'POST') {
        $body = _stattic_bounded_request_body(STATTIC_CONNECTORS_MAX_BODY_BYTES);
        if ($body === null) {
            _stattic_connectors_refuse(413, 'runtime_request_body_too_large', 'Send at most 64 KiB of arguments.');
        }
        $args = trim($body) === '' ? [] : json_decode($body, true);
        if (!is_array($args) || array_is_list($args) && $args !== []) {
            _stattic_connectors_refuse(400, 'validation_error', 'Send the arguments as a JSON object.');
        }
        return $args;
    }
    $args = [];
    foreach (_stattic_runtime_query_pairs((string) ($_SERVER['QUERY_STRING'] ?? '')) as [$name, $raw]) {
        if ($name === '' || $raw === null || $name === STATTIC_CONNECTORS_ARGS_PARAM || in_array($name, STATTIC_ACCESS_FLOW_PARAMS, true)) {
            continue;
        }
        $args[$name] = rawurldecode(str_replace('+', '%20', $raw));
    }
    $typed = _stattic_runtime_raw_query_param(STATTIC_CONNECTORS_ARGS_PARAM);
    if (is_string($typed)) {
        $decoded = json_decode(rawurldecode(str_replace('+', '%20', $typed)), true);
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            _stattic_connectors_refuse(400, 'validation_error', 'The __args parameter must be a JSON object.');
        }
        $args = array_replace($args, $decoded);
    }
    return $args;
}

/**
 * The unified browser client is public infrastructure. Its entry revalidates
 * on reuse; lazy modules have content hashes and can be cached immutably.
 */
function _stattic_space_client_serve(string $requestPath): never
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD');
    }
    $entry = $requestPath === STATTIC_SPACE_CLIENT_PATH;
    $relative = 'client.js';
    if (!$entry) {
        $filename = substr($requestPath, strlen(STATTIC_SPACE_CLIENT_CHUNKS_PREFIX));
        if (preg_match('/\A[A-Za-z0-9_-]+-[A-Z0-9]{8}\.js\z/D', $filename) !== 1) {
            _stattic_connectors_refuse(404, 'not_found', 'This client module does not exist.');
        }
        $relative = 'chunks/' . $filename;
    }
    $path = __DIR__ . '/space-client/' . $relative;
    $body = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($body)) {
        _stattic_connectors_refuse(404, 'not_found', 'This client module does not exist.');
    }
    $etag = '"' . hash('sha256', $body) . '"';
    header('Content-Type: text/javascript; charset=utf-8', true);
    header('X-Content-Type-Options: nosniff', true);
    header('Cache-Control: ' . ($entry ? 'public, max-age=0, must-revalidate' : 'public, max-age=31536000, immutable'), true);
    header('ETag: ' . $etag, true);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($body), true);
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * `/__spacefast/client.d.ts`: the version's declaration file. Behind
 * the same access check as the data, since role and tool names are the Space's.
 */
function _stattic_space_client_serve_types(string $versionRoot, string $method): never
{
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD', ['code' => 'method_not_allowed', 'message' => '', 'headers' => STATTIC_CONNECTORS_RESPONSE_HEADERS]);
    }
    $path = $versionRoot . '/' . STATTIC_SPACE_CLIENT_TYPES_PATH;
    $body = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($body)) {
        _stattic_connectors_refuse(404, 'not_found', 'This version has no client types. Publish this Space again.');
    }
    $headers = STATTIC_CONNECTORS_RESPONSE_HEADERS + ['X-Content-Type-Options' => 'nosniff'];
    _stattic_response_send(200, $body, 'application/typescript; charset=utf-8', $headers);
}

function _stattic_connectors_serve(string $privateRoot, string $versionRoot, array $serving, string $host, string $path, string $method): never
{
    if ($path === STATTIC_SPACE_CLIENT_TYPES_URL_PATH) {
        _stattic_space_client_serve_types($versionRoot, $method);
    }
    $route = _stattic_connectors_route($path) ?? _stattic_legacy_zero_connectors_route($path);
    if ($route === null) {
        _stattic_connectors_refuse(404, 'connector_role_unknown', 'This Space has no such connector role.');
    }
    $token = _stattic_connectors_token($versionRoot);
    // A version that declares no roles has no credential: every role is unknown.
    if ($token === '') {
        _stattic_connectors_refuse(404, 'connector_role_unknown', 'This Space has no such connector role.');
    }
    $apiUrl = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    if (!_stattic_platform_destination_allowed($apiUrl)) {
        _stattic_connectors_refuse(503, 'connectors_unavailable', 'Connections are unavailable. Try again.');
    }
    // Every call below holds this worker on the control plane for up to 30 s,
    // so it takes a slot from the Space's one uncacheable-work budget first.
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_once($privateRoot, $serving, 'connectors');
    if (isset($route['operation'])) {
        _stattic_connectors_serve_visitor($serving, $host, $method, $route, $token, $apiUrl);
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        _stattic_method_not_allowed('GET, POST', ['code' => 'method_not_allowed', 'message' => 'Use GET to read or POST to write.', 'headers' => STATTIC_CONNECTORS_RESPONSE_HEADERS]);
    }
    // Cookies reach a role only from the Space's own pages: a write proves its
    // origin, and a read must not come from another site.
    if ($method === 'POST' ? !_stattic_access_same_origin_post($host) : !_stattic_connectors_not_cross_site($host)) {
        _stattic_connectors_refuse(403, 'browser_origin_not_allowed', 'Call this Space\'s connectors from its own pages. Elsewhere, use the Spacefast API.');
    }
    $call = [
        'surface' => 'page',
        'role' => $route['role'],
        'tool' => $route['tool'],
        'args' => (object) _stattic_connectors_call_args($method),
        'visitor' => _stattic_connectors_visitor($serving, $host),
        'handler' => ['name' => 'page', 'mode' => $method === 'GET' ? 'read' : 'write'],
        'acceptsSharedReads' => true,
    ];
    $requestId = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($requestId !== '' && strlen($requestId) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $requestId) !== 1) {
        $call['requestId'] = $requestId;
    }
    $result = _stattic_connectors_post($apiUrl . '/v1/runtime/connectors/calls', $token, $call, STATTIC_CONNECTORS_CALL_TIMEOUT_SECONDS);
    $answer = json_decode($result['body'], true);
    if ($result['error'] !== null || !is_array($answer)) {
        _stattic_connectors_refuse(502, 'connectors_unavailable', 'Connections are unavailable. Try again.');
    }
    // A 401 is about this runtime's credential, never the viewer's.
    if ($result['status'] === 401 || $result['status'] >= 500) {
        _stattic_connectors_refuse(502, 'connectors_unavailable', 'Connections are unavailable. Try again.');
    }
    if ($result['status'] >= 400) {
        // The control plane's verdict, code and pointer included.
        $code = is_string($answer['code'] ?? null) ? $answer['code'] : 'connectors_unavailable';
        $extra = is_string($answer['pointer'] ?? null) ? ['pointer' => $answer['pointer']] : [];
        _stattic_problem_response($result['status'], $code, is_string($answer['detail'] ?? null) ? $answer['detail'] : '', $extra, STATTIC_CONNECTORS_RESPONSE_HEADERS);
    }
    $state = is_array($answer['state'] ?? null) ? $answer['state'] : [];
    $code = is_string($state['code'] ?? null) ? $state['code'] : '';
    if ($code === 'ok') {
        // A control plane older than shared reads answers only with `run.result`;
        // drop the fallback once every control plane sends `result`.
        $run = is_array($answer['run'] ?? null) ? $answer['run'] : [];
        $result = $answer['result'] ?? $run['result'] ?? null;
        $body = ['data' => $result, 'state' => 'ok'];
        // A tool's result is Executor's `{ok: true, data, http?}`: the page gets
        // the provider's payload as `data`, and an HTTP tool's upstream status
        // and headers (pagination, rate limits) as `http`.
        if (is_array($result) && ($result['ok'] ?? null) === true && array_key_exists('data', $result)) {
            $body['data'] = $result['data'];
            if (is_array($result['http'] ?? null)) {
                $body['http'] = $result['http'];
            }
        }
        if (is_string($state['cachedAt'] ?? null)) {
            $body['cachedAt'] = $state['cachedAt'];
        }
        _stattic_connectors_send($body);
    }
    if ($code === 'approval_pending' && ($state['pendingReason'] ?? null) === 'running') {
        $code = 'running';
    }
    if (in_array($code, STATTIC_CONNECTORS_PAGE_STATES, true)) {
        $body = ['data' => null, 'state' => $code, 'message' => (string) ($state['message'] ?? '')];
        foreach (['connectUrl', 'approvalUrl'] as $link) {
            if (is_string($state[$link] ?? null) && $state[$link] !== '') {
                $body[$link] = $state[$link];
            }
        }
        _stattic_connectors_send($body);
    }
    // `forbidden` and `unavailable` are failures a page cannot wait out.
    $status = $code === 'forbidden' ? 403 : 503;
    _stattic_problem_response(
        $status,
        $code === 'forbidden' ? 'connection_forbidden' : 'connectors_unavailable',
        (string) ($state['message'] ?? ''),
        [],
        STATTIC_CONNECTORS_RESPONSE_HEADERS
    );
}

/** A JSON POST to the control plane under the version's connectors credential. */
function _stattic_connectors_post(string $url, string $token, array $body, int $timeout): array
{
    return _stattic_http_request([
        'url' => $url,
        'method' => 'POST',
        'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
        'connect_timeout' => 5,
        'timeout' => $timeout,
        'schemes' => ['https', 'http'],
    ]);
}

function _stattic_connectors_send(array $body): never
{
    _stattic_response_send(200, json_encode($body, JSON_UNESCAPED_SLASHES) . "\n", 'application/json; charset=utf-8', STATTIC_CONNECTORS_RESPONSE_HEADERS);
}

/**
 * A visitor role's own account: `connect` starts the provider sign-in,
 * `callback` completes it in the popup, `disconnect` (POST, same origin) forgets
 * it. A viewer who is not signed in is sent to sign in first and returns here.
 */
function _stattic_connectors_serve_visitor(array $serving, string $host, string $method, array $route, string $token, string $apiUrl): never
{
    ['role' => $role, 'operation' => $operation] = $route;
    // Each operation has an effect upstream, so none answers HEAD.
    $methods = $route['methods'] ?? ($operation === 'disconnect' ? ['POST'] : ['GET']);
    if (!in_array($method, $methods, true)) {
        _stattic_method_not_allowed(implode(', ', $methods), ['code' => 'method_not_allowed', 'message' => '', 'headers' => STATTIC_CONNECTORS_RESPONSE_HEADERS]);
    }
    if ($operation === 'disconnect' && !_stattic_access_same_origin_post($host)) {
        _stattic_connectors_refuse(403, 'browser_origin_not_allowed', 'Disconnect from this Space.');
    }
    $origin = _stattic_runtime_request_origin($host);
    $visitor = _stattic_connectors_visitor($serving, $host, $operation !== 'disconnect');
    $returnTo = is_string($_GET['return_to'] ?? null) ? _stattic_safe_return_path($_GET['return_to']) : null;
    if ($visitor === null) {
        // Back to the same operation on the current route, whichever spelling
        // the page used; an expired callback starts over at connect.
        $return = STATTIC_CONNECTORS_PATH_PREFIX . $role . '/' . ($operation === 'callback' ? 'connect' : $operation);
        if ($operation === 'connect' && $returnTo !== null) {
            $return .= '?return_to=' . rawurlencode($returnTo);
        }
        _stattic_access_redirect(_stattic_connectors_sign_in_url($serving, $return), 302, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
    }
    $payload = ['role' => $role, 'visitorSubject' => $visitor['subject']];
    if ($operation === 'connect') {
        $payload['origin'] = 'https://' . _stattic_canonicalize_host($host);
        $payload['returnTo'] = $returnTo ?? '/';
    } elseif ($operation === 'callback') {
        if (!is_string($_GET['code'] ?? null) || !is_string($_GET['state'] ?? null)) {
            _stattic_connectors_refuse(400, 'connector_authorization_cancelled', 'The account was not connected. Close this window and try again.');
        }
        $payload['code'] = $_GET['code'];
        $payload['state'] = $_GET['state'];
    }
    $action = match ($operation) { 'connect' => 'start', 'callback' => 'complete', default => 'revoke' };
    $result = _stattic_connectors_post($apiUrl . '/v1/runtime/connectors/visitor-connections/' . $action, $token, $payload, 30);
    $answer = json_decode($result['body'], true);
    if ($result['error'] === null && $result['status'] >= 400 && $result['status'] < 500 && $result['status'] !== 401 && is_string($answer['code'] ?? null)) {
        // The control plane's verdict with its code, as on a call: an expired
        // sign-in or an unusable role is not an outage.
        _stattic_connectors_refuse($result['status'], $answer['code'], is_string($answer['detail'] ?? null) ? $answer['detail'] : '');
    }
    if (!$result['ok']) {
        _stattic_connectors_refuse(502, 'connector_authorization_failed', 'The account could not be connected. Close this window and try again.');
    }
    $data = is_array($answer) ? ($answer['data'] ?? null) : null;
    if ($operation === 'connect') {
        $authorizeUrl = is_array($data) ? ($data['authorizeUrl'] ?? null) : null;
        if (!is_string($authorizeUrl) || parse_url($authorizeUrl, PHP_URL_SCHEME) !== 'https') {
            _stattic_connectors_refuse(502, 'connector_authorization_failed', 'The sign-in page is unavailable.');
        }
        _stattic_access_redirect($authorizeUrl, 302, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE);
    }
    if ($operation === 'disconnect') {
        _stattic_connectors_send(['data' => null]);
    }
    $returned = is_array($data) && is_string($data['returnTo'] ?? null) ? parse_url($data['returnTo']) : null;
    $returnPath = is_array($returned) ? (string) ($returned['path'] ?? '') : '';
    $returnQuery = is_array($returned) ? (string) ($returned['query'] ?? '') : '';
    $location = $origin . (_stattic_safe_return_path($returnPath) ?? '/') . ($returnQuery !== '' ? '?' . $returnQuery : '');
    $nonce = base64_encode(random_bytes(18));
    $json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $html = '<!doctype html><html><meta charset="utf-8"><title>Account connected</title><p>Account connected. You can close this window.</p>'
        . '<script nonce="' . $nonce . '">if(window.opener){window.opener.postMessage(' . $json(['type' => 'spacefast:connector-connected', 'role' => $role]) . ',' . $json($origin) . ');window.close();}else{location.replace(' . $json($location) . ');}</script></html>';
    _stattic_response_send(200, $html, 'text/html; charset=utf-8', [
        'Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-" . $nonce . "'; base-uri 'none'; frame-ancestors 'none'",
    ]);
}
