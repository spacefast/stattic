<?php

/**
 * Dispatch to the Spacefast Functions host. Everything the host needs travels
 * in `sf-fx-*` request headers, so it holds no per-tenant state. Dispatch is
 * route-driven: finalize compiles the worker's declared routes into
 * `functions/routes.php`, and a request matching no entry never wakes the
 * worker. Anything on disk is answered here first. The origin holds no
 * Cloudflare credential: no API token, account id, or namespace.
 */

require_once __DIR__ . '/../shared/context.php';
// Config reads answer empty until this has run, which for a credential check
// means silently refusing valid tokens.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/http.php';
require_once __DIR__ . '/../shared/cache-policy.php';
require_once __DIR__ . '/../shared/upstream-relay.php';
require_once __DIR__ . '/../shared/html-insert.php';

const SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX = 'sf-fx-';

// A hung dispatch holds a PHP-FPM slot against every other request to this
// space, while its relay calls demand more from the same pool.
const STATTIC_FUNCTIONS_DISPATCH_TIMEOUT_SECONDS = 30;
const STATTIC_FUNCTIONS_DISPATCH_CONNECT_TIMEOUT_SECONDS = 5;

// Storage, database and platform services transit the relay. The Next cache
// lives with the worker, and log delivery degrades on its own. Tenant fetch is
// not a capability at all: every worker has it, bounded by `sf-fx-egress`.
const STATTIC_FUNCTIONS_RELAY_FREE_CAPABILITIES = ['log', 'next.cache'];

// Compiled beside the version's file tree, never inside it, like the config.
// The non-terminal reader lets the static lane tell verified absence from a
// transient or malformed artifact: the latter must keep the committed response
// out of shared cache without replacing those bytes with a 500.
//
// @return array{kind: 'present', value: array}|array{kind: 'absent'|'unavailable'}
function _stattic_try_load_functions_routes_artifact(string $versionRoot): array
{
    // Present and verified-absent outcomes are memoized per request; the
    // transient `unavailable` outcome never is, so a later call retries.
    static $cache = [];
    $path = dirname($versionRoot) . '/functions/routes.php';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    if (!is_file($path)) {
        return _sf_path_verifiably_absent($path)
            ? $cache[$path] = ['kind' => 'absent']
            : ['kind' => 'unavailable'];
    }
    if (!ob_start()) {
        _sf_runtime_log_read_failure('functions_routes_buffer_failed', $path);
        return ['kind' => 'unavailable'];
    }
    error_clear_last();
    try {
        $loaded = include $path;
    } catch (Throwable $error) {
        ob_end_clean();
        error_log('spacefast runtime functions_routes_include_failed path=' . $path . ' msg=' . $error->getMessage());
        return ['kind' => 'unavailable'];
    }
    $unexpectedOutput = ob_get_clean();
    if (
        $unexpectedOutput !== ''
        || !is_array($loaded)
        || ($loaded['artifact_kind'] ?? null) !== 'functions_routes'
        || !is_array($loaded['exact'] ?? null)
        || !is_array($loaded['by_first_segment'] ?? null)
        || !is_array($loaded['fallback'] ?? null)
    ) {
        _sf_runtime_log_read_failure('functions_routes_invalid', $path);
        return ['kind' => 'unavailable'];
    }

    return $cache[$path] = ['kind' => 'present', 'value' => $loaded];
}

// Absent means this version dispatches nothing. Present-but-malformed is a
// terminal invariant failure once the request reaches the Functions route lane.
function _stattic_load_functions_routes_artifact(string $versionRoot): ?array
{
    $read = _stattic_try_load_functions_routes_artifact($versionRoot);
    if ($read['kind'] === 'absent') {
        return null;
    }
    if ($read['kind'] === 'unavailable') {
        _stattic_render_runtime_invariant_error_lazy('functions-route-metadata-missing', 'Runtime Functions route metadata is malformed.');
    }
    return $read['value'];
}

/**
 * Runs after every static and Zero resolution: assets win, and a request
 * matching no compiled route never wakes the worker. The worker owns status
 * semantics for matched paths, so there are three outcomes: dispatch when a
 * route claims this path and method; a router-built 405 with the allowed
 * methods when a route claims the path at other methods, since the table
 * already knows what it answers; null when no route claims the path.
 */
function _stattic_resolve_functions_route_action(string $versionRoot, string $lookup, string $requestMethod): ?array
{
    $routes = _stattic_load_functions_routes_artifact($versionRoot);
    if ($routes === null) {
        return null;
    }
    $trimmed = trim($lookup, '/');
    $firstSegment = $trimmed === '' ? '' : explode('/', $trimmed, 2)[0];
    $buckets = [
        $routes['exact'],
        is_array($routes['by_first_segment'][$firstSegment] ?? null) ? $routes['by_first_segment'][$firstSegment] : [],
        $routes['fallback'],
    ];
    $allowed = [];
    foreach ($buckets as $bucket) {
        foreach ($bucket as $entry) {
            $method = is_array($entry) ? ($entry['method'] ?? null) : false;
            $pattern = is_array($entry) ? ($entry['pattern'] ?? null) : null;
            if (!is_string($pattern) || ($method !== null && !is_string($method))) {
                _stattic_render_runtime_invariant_error_lazy('functions-route-metadata-missing', 'Runtime Functions route metadata is malformed.');
            }
            if (!is_array(_stattic_match_route_pattern_segments($pattern, $trimmed))) {
                continue;
            }
            if (_stattic_functions_route_method_matches($method, $requestMethod)) {
                return ['action' => 'dispatch_functions'];
            }
            // The path is a route, but not at this method. A null method always
            // matches, so only a concrete method reaches here. GET carries HEAD
            // with it, exactly as the match does.
            $allowed[$method] = true;
            if ($method === 'GET') {
                $allowed['HEAD'] = true;
            }
        }
    }

    if ($allowed !== []) {
        return ['method_not_allowed' => true, 'allow' => array_keys($allowed)];
    }

    return null;
}

// The valid config or null; artifacts.php's `_stattic_functions_config_read`
// owns the read and its memo.
function _stattic_functions_config(string $versionRoot): ?array
{
    $read = _stattic_functions_config_read($versionRoot);
    return $read['kind'] === 'present' ? $read['value'] : null;
}

/**
 * Whether this request must skip the static fast path and dispatch instead: a
 * draft/preview session, meaning a request carrying one of the version's
 * declared bypass cookies, asking for a path the worker claims. A prerendered
 * page serves from disk for everyone else, but a draft request needs the worker
 * to render draft content. The caller has already established the cheap facts
 * (GET/HEAD, a non-empty Cookie header, a functions version), so this only
 * reads the config and consults the cached route table.
 */
function _stattic_functions_bypass_requested(string $versionRoot, string $requestPath, string $requestMethod): bool
{
    $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';
    if (!is_string($cookieHeader) || $cookieHeader === '') {
        return false;
    }
    $config = _stattic_functions_config($versionRoot);
    if ($config === null) {
        return false;
    }
    $artifact = is_array($config['artifact'] ?? null) ? $config['artifact'] : [];
    $present = false;
    foreach (is_array($artifact['bypassCookies'] ?? null) ? $artifact['bypassCookies'] : [] as $cookie) {
        if (
            is_string($cookie) && $cookie !== ''
            // Anchored to a cookie boundary: `foo__prerender_bypass` is a
            // different cookie and must not trip the platform's rule.
            && preg_match('/(?:^|;\s*)' . preg_quote($cookie, '/') . '=/', $cookieHeader) === 1
        ) {
            $present = true;
            break;
        }
    }
    if (!$present) {
        return false;
    }
    $route = _stattic_resolve_functions_route_action($versionRoot, ltrim($requestPath, '/'), $requestMethod);
    return is_array($route) && ($route['action'] ?? null) === 'dispatch_functions';
}

// Control paths stay terminal: a tenant worker must never answer
// `/__spacefast/...` or `/_headers` on the platform's behalf.
function _stattic_functions_dispatchable(string $requestPath, string $requestMethod): bool
{
    if ($requestMethod === 'TRACE' || $requestMethod === 'CONNECT') {
        return false;
    }
    if (_stattic_path_is_reserved($requestPath)) {
        return false;
    }
    return true;
}

// These are FastCGI parameters, never HTTP_* headers or the provider's REMOTE_ADDR.
function _stattic_functions_visitor_context(): array
{
    $context = [];
    $ip = $_SERVER['SPACEFAST_VISITOR_IP'] ?? getenv('SPACEFAST_VISITOR_IP');
    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) $context['ip'] = $ip;
    $country = strtoupper((string) ($_SERVER['GEOIP_COUNTRY_CODE'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/D', $country)) $context['country'] = $country;
    $city = $_SERVER['GEOIP_CITY'] ?? null;
    if (is_string($city) && $city !== '' && strlen($city) <= 128) $context['city'] = $city;
    return $context;
}

// The grant and the relay credential travel together or not at all.
function _stattic_functions_dispatch_headers(
    array $config,
    string $spaceId,
    string $versionId,
    string $requestId,
    string $dispatchToken,
    string $originBaseUrl,
    string $egressScope
): array {
    $host = $config['host'];
    $artifact = $config['artifact'];
    $capabilities = [];
    foreach (is_array($config['grantedCapabilities'] ?? null) ? $config['grantedCapabilities'] : [] as $capability) {
        if (is_string($capability) && $capability !== '') {
            $capabilities[] = $capability;
        }
    }
    $relay = is_array($config['relay'] ?? null) ? $config['relay'] : null;
    $relayUsable = $relay !== null
        && is_string($relay['url'] ?? null) && $relay['url'] !== ''
        && is_string($relay['token'] ?? null) && $relay['token'] !== '';
    if (!$relayUsable) {
        $capabilities = array_values(array_filter(
            $capabilities,
            static fn($c) => in_array($c, STATTIC_FUNCTIONS_RELAY_FREE_CAPABILITIES, true)
        ));
    }

    $flags = [];
    foreach (is_array($artifact['compatibilityFlags'] ?? null) ? $artifact['compatibilityFlags'] : [] as $flag) {
        if (is_string($flag) && $flag !== '') {
            $flags[] = $flag;
        }
    }

    $variableValues = is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [];
    // The host contract requires an object; json_encode([]) would emit `[]`.
    $encodedVariableValues = $variableValues === []
        ? '{}'
        : (string) json_encode($variableValues, JSON_UNESCAPED_SLASHES);

    $headers = [
        'sf-fx-bundle' => (string) $host['bundleUrl'],
        'sf-fx-main' => (string) $artifact['mainModule'],
        'sf-fx-compat-date' => (string) $artifact['compatibilityDate'],
        'sf-fx-compat-flags' => implode(',', $flags),
        'sf-fx-visitor' => base64_encode((string) json_encode((object) _stattic_functions_visitor_context(), JSON_INVALID_UTF8_SUBSTITUTE)),
        'sf-fx-d1' => implode(',', array_column($artifact['d1'] ?? [], 'binding')),
        'sf-fx-caps' => implode(',', $capabilities),
        'sf-fx-space' => $spaceId,
        'sf-fx-version' => $versionId,
        'sf-fx-request' => $requestId,
        'sf-fx-dispatch-token' => $dispatchToken,
        // How far the worker's outbound fetch may reach. Origin-decided, like
        // the grant: the host reads it and never infers it. Stripped inbound by
        // the `sf-fx-` prefix rule below, so a visitor cannot send their own.
        'sf-fx-egress' => $egressScope,
        // Base64 so a value containing a newline cannot inject a header.
        'sf-fx-env' => base64_encode($encodedVariableValues),
    ];
    // The cache seed's signed read URL, minted at finalize beside the bundle
    // URL. Optional: a version without one starts its cache cold.
    if (is_string($host['seedUrl'] ?? null) && $host['seedUrl'] !== '') {
        $headers['sf-fx-seed'] = (string) $host['seedUrl'];
    }
    // A needed relay is a usable one: when it is not, the filter above leaves
    // only relay-free capabilities behind.
    $relayNeeded = array_filter(
        $capabilities,
        static fn($c) => !in_array($c, STATTIC_FUNCTIONS_RELAY_FREE_CAPABILITIES, true)
    ) !== [];
    if ($relayNeeded) {
        $headers['sf-fx-relay'] = (string) $relay['url'];
        $headers['sf-fx-relay-token'] = (string) $relay['token'];
    }
    if ($relayUsable) {
        $headers['sf-fx-log'] = $originBaseUrl . '/' . STATTIC_FUNCTIONS_LOGS_PATH;
        $headers['sf-fx-log-token'] = (string) $relay['token'];
    }
    // The purge channel is independent of the relay: its credential is its own,
    // minted at finalize beside the relay token and verified by this origin's
    // purge route, and a worker granted nothing else must still evict the pages
    // it rendered. The URL is composed from the request host, like log intake,
    // because the purge must land on the cache in front of the hostname the
    // visitor hit.
    $purge = is_array($config['purge'] ?? null) ? $config['purge'] : null;
    if ($purge !== null && is_string($purge['token'] ?? null) && $purge['token'] !== '') {
        $headers['sf-fx-purge'] = $originBaseUrl . '/' . STATTIC_FUNCTIONS_PURGE_PATH;
        $headers['sf-fx-purge-token'] = (string) $purge['token'];
    }
    // Usage reporting is independent of the relay: the credential and the
    // destination are both the control plane's, and this origin only forwards
    // them. A version whose relay is unusable, and so has no log channel, is
    // still counted.
    $usage = is_array($config['usage'] ?? null) ? $config['usage'] : null;
    if (
        $usage !== null
        && is_string($usage['url'] ?? null) && $usage['url'] !== ''
        && is_string($usage['token'] ?? null) && $usage['token'] !== ''
    ) {
        $headers['sf-fx-usage'] = (string) $usage['url'];
        $headers['sf-fx-usage-token'] = (string) $usage['token'];
    }
    return $headers;
}

// Inbound `sf-fx-*` is stripped: a visitor's `sf-fx-caps: db.write` must not
// reach the host, where it would be indistinguishable from ours.
// `authorization` is absent on purpose. Spacefast credentials ride
// `x-sf-authorization`, so Authorization belongs to the customer's application
// and reaches the worker unchanged. Accept-Encoding goes because the response
// relay strips Content-Encoding.
function _stattic_functions_relay_request_lane(): array
{
    return [
        'deny' => ['x-sf-authorization', 'accept-encoding'],
        'deny_prefixes' => [SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX],
    ];
}

function _stattic_functions_cookie_domain_escapes_host(string $value, string $requestHost): bool
{
    $host = strtolower(rtrim(trim($requestHost), '.'));
    foreach (array_slice(explode(';', $value), 1) as $attribute) {
        $parts = explode('=', trim($attribute), 2);
        if (strtolower(trim((string) ($parts[0] ?? ''))) !== 'domain') {
            continue;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $domain = strtolower(trim((string) $parts[1]));
        $domain = str_starts_with($domain, '.') ? substr($domain, 1) : $domain;
        if ($domain === '' || $domain !== $host) {
            return true;
        }
    }
    return false;
}

// Cloudflare terminates the internal Functions hop; its response metadata
// describes that hop, and the outer CDN would cache stale Ray IDs. Application
// cookies relay only host-only or scoped to the exact request host: a Space's
// hostname shares its parent with sibling Spaces (view.fast, a partner apex),
// and the runtime cannot prove a custom domain's parent belongs to this Space.
function _stattic_functions_relay_response_lane(string $requestHost = ''): array
{
    return [
        'deny' => ['content-encoding', 'strict-transport-security'],
        'deny_prefixes' => [SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX, 'cf-'],
        'allow_cookies' => true,
        'deny_value' => static function (string $name, string $value) use ($requestHost): bool {
            $lowerValue = strtolower($value);
            // §16: a worker never steers the edge (A8C-*) or forges its verdict (x-ac).
            return _stattic_platform_owns_header($name)
                || (in_array($name, ['set-cookie', 'set-cookie2'], true)
                    && _stattic_functions_cookie_domain_escapes_host($value, $requestHost))
                || ($name === 'server' && trim($lowerValue) === 'cloudflare')
                || (
                    in_array($name, ['nel', 'report-to'], true)
                    && (str_contains($lowerValue, 'cf-nel') || str_contains($lowerValue, 'cloudflare.com'))
                );
        },
    ];
}

// Without this the tenant worker's own Cache-Control governs the edge, so a
// private space's Functions responses would be shared-cacheable by worker fiat.
// A public space keeps worker-declared caching, since the lane declares no
// policy, but only while the response carries nothing a shared store cannot
// honor: the wp.cloud edge keys a stored response on host+path+query alone and
// ignores Vary, so a worker answering `Vary: RSC` beside a public s-maxage
// would be stored once by URL and replayed to every variant. The signals that
// revoke a proxy origin's shared-cache grant (any Vary beyond Accept-Encoding,
// a Set-Cookie, a private/no-cache directive) revoke the worker's the same way,
// down to the same no-store. A worker that declares no Cache-Control at all
// asked for nothing to be stored: its answer is usually computed per request
// (a database read), so it leaves as no-store rather than falling to whatever
// the edge does with an undeclared response.
function _stattic_functions_response_cache_policy(bool $privateCache, array $workerHeaders): array
{
    return _stattic_cache_policy([
        'private' => $privateCache,
        'public' => _stattic_cache_policy_upstream_revokes_shared_store($workerHeaders)
            || _stattic_functions_worker_cache_control($workerHeaders) === null
            ? STATTIC_CACHE_CONTROL_NO_STORE
            : null,
    ]);
}

function _stattic_functions_worker_cache_control(array $workerHeaders): ?string
{
    foreach ($workerHeaders as $header) {
        if (is_array($header) && strtolower(trim((string) ($header[0] ?? ''))) === 'cache-control') {
            return (string) ($header[1] ?? '');
        }
    }
    return null;
}

// The edge opt-in for a relayed worker response, derived from the Cache-Control
// that actually leaves (the decided policy, else the worker's own), the same
// way every PHP lane derives it, so the worker's caching and the edge's can
// never disagree.
function _stattic_functions_edge_cache_directive(array $cachePolicy, array $sentLines): string
{
    return _stattic_edge_cache_directive(
        $cachePolicy['cache_control'] ?? _stattic_functions_worker_cache_control($sentLines)
    );
}

// Never returns when it dispatches; returns normally only when this request is
// not the worker's to answer, so the caller continues the static fallback chain.
function _stattic_functions_dispatch(
    string $versionRoot,
    string $spaceId,
    string $versionId,
    string $requestPath,
    string $requestMethod,
    string $requestHost,
    bool $privateCache = false,
    array $serving = []
): void {
    if (!_stattic_functions_dispatchable($requestPath, $requestMethod)) {
        return;
    }
    // D44: the worker's HTML leaves the runtime through this relay, so the
    // insert applies here exactly as it does to a proxy route or Zero.
    $insertSnippets = _stattic_html_insert_snippets($serving);
    // serve.php reaches here only for a version the compile step wrote a functions
    // config for, so this request IS a function path. From here it either reaches
    // the execution edge or answers 503. It never degrades to static and never
    // runs the worker on the origin: the origin decides, the edge executes. A
    // version with no functions carries no config, so the down-ramp is
    // not-compiling-routes, not a serve-time flag check.
    $config = _stattic_functions_config($versionRoot);
    // This box's own dispatch credential: a JWT the control plane minted for
    // THIS SPACEFAST_RUNTIME_INSTANCE_ID and pushed through persistent data. The
    // origin forwards it and the edge verifies it. It is not interchangeable
    // between boxes, so stealing one buys nothing against another.
    $dispatchToken = (string) (_stattic_config_value('SPACEFAST_FUNCTIONS_DISPATCH_TOKEN') ?? '');
    // "No reachable edge host" is any of: a config finalize wrote no host into,
    // no dispatch credential on this origin (a box that has never had a
    // persistent-data sync), curl missing, or a host egress policy blocks.
    // Order matters: the destination check dereferences the host, so it runs
    // only once $config is non-null.
    if (
        $config === null
        || $dispatchToken === ''
        || !_stattic_platform_destination_allowed('https://' . $config['host']['hostname'])
    ) {
        _stattic_functions_edge_unconfigured();
    }

    $requestId = _stattic_functions_request_id();
    // Same rule the publisher-upstream proxy follows: the visitor's link secret
    // stops at this runtime. The edge sees the request line, and so does every
    // log it keeps.
    $query = _stattic_strip_access_query_token((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $target = 'https://' . $config['host']['hostname'] . $requestPath . ($query !== '' ? '?' . $query : '');

    $headers = [];
    foreach (_stattic_relay_request_headers(_stattic_functions_relay_request_lane()) as [$name, $value]) {
        $headers[strtolower($name)] = $value;
    }
    $headers['x-forwarded-host'] = $requestHost;
    $headers['x-forwarded-proto'] = 'https';
    foreach (_stattic_functions_dispatch_headers(
        $config,
        $spaceId,
        $versionId,
        $requestId,
        $dispatchToken,
        'https://' . $requestHost,
        _stattic_egress_scope($serving)
    ) as $name => $value) {
        $headers[$name] = $value;
    }

    $status = 0;
    $headersSent = false;
    $request = [
        'url' => $target,
        'method' => $requestMethod,
        'headers' => array_map('_stattic_relay_safe_header_value', $headers),
        'connect_timeout' => STATTIC_FUNCTIONS_DISPATCH_CONNECT_TIMEOUT_SECONDS,
        'timeout' => STATTIC_FUNCTIONS_DISPATCH_TIMEOUT_SECONDS,
        'sink' => 'output',
        'on_headers' => static function (int $responseStatus, array $headerPairs) use (&$status, &$headersSent, $privateCache, $insertSnippets, $requestHost): void {
            $status = $responseStatus;
            http_response_code($responseStatus);
            $headersSent = true;
            // Decided per response, never before the dispatch: the worker's own
            // headers are the lane input that can revoke shared caching, and
            // they exist only once the host has answered.
            $cachePolicy = _stattic_functions_response_cache_policy($privateCache, $headerPairs);
            $responseLines = _stattic_cache_policy_apply_lines(
                $cachePolicy,
                _stattic_relay_response_header_lines(
                    $headerPairs,
                    $cachePolicy,
                    _stattic_functions_relay_response_lane($requestHost)
                )
            );
            _stattic_relay_send_response_headers($responseLines, $cachePolicy);
            _stattic_clear_platform_owned_response_headers();
            header(STATTIC_EDGE_CACHE_HEADER . ': ' . _stattic_functions_edge_cache_directive($cachePolicy, $responseLines), true);
            // After the response headers, before the first body byte: the
            // filter reads the declared content type and never sees a platform
            // page (a failure past this point truncates instead, by design).
            _stattic_html_insert_stream_begin($insertSnippets);
        },
    ];
    $input = null;
    if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
        $input = _stattic_request_body_stream();
        if ($input !== false) {
            $request['body_stream'] = $input;
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $request['body_size'] = (int) $_SERVER['CONTENT_LENGTH'];
        }
    }

    $result = _stattic_http_request($request);
    if (is_resource($input)) {
        fclose($input);
    }

    if ($result['error'] !== null) {
        _stattic_relay_abort_after_headers($headersSent);
        _stattic_functions_bad_gateway();
    }
    if (!$headersSent) {
        http_response_code($status > 0 ? $status : 502);
    }
    _stattic_html_insert_stream_end();
    exit;
}

// Travels as a header, never bound at isolate creation: the isolate outlives
// the request that created it.
function _stattic_functions_request_id(): string
{
    $existing = $_SERVER['HTTP_X_SPACEFAST_REQUEST_ID'] ?? null;
    if (is_string($existing) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $existing) === 1) {
        return $existing;
    }
    return 'fxr_' . bin2hex(random_bytes(12));
}

function _stattic_functions_bad_gateway(): void
{
    _stattic_render_platform_page('proxy-error', 502, [], "The function did not respond.\n");
}

// A function path whose execution edge is unreachable answers 503, never a
// static file: once a version compiles a function route, that route is the
// worker's or it is nothing. `functions_edge_unconfigured` is the single code
// for every "no reachable edge" cause, so swapping the edge system needs no new
// serve-time verdict.
function _stattic_functions_edge_unconfigured(): void
{
    _stattic_serve_page('runtime-unavailable', [
        'status' => 503,
        'code' => 'functions_edge_unconfigured',
        'message' => 'Function execution is not available.',
    ]);
}
