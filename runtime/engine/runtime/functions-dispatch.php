<?php

/**
 * Dispatch to the Spacefast Functions host. Everything the host needs travels
 * in `sf-fx-*` request headers, so it holds no per-tenant state. Dispatch is
 * route-driven: finalize compiles the worker's declared routes into
 * `functions/routes.php`, and a request matching no entry never wakes the
 * worker — anything on disk is answered here first. The origin never holds a
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

// Compiled beside the version's file tree, never inside it, like the config.
// Absent means this version dispatches nothing; present-but-malformed is an
// invariant failure, not a silent static site.
function _stattic_load_functions_routes_artifact(string $versionRoot): ?array
{
    static $cache = [];
    $path = dirname($versionRoot) . '/functions/routes.php';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    if (!is_file($path)) {
        return $cache[$path] = null;
    }
    $loaded = @include $path;
    if (
        !is_array($loaded)
        || ($loaded['artifact_kind'] ?? null) !== 'functions_routes'
        || !is_array($loaded['exact'] ?? null)
        || !is_array($loaded['by_first_segment'] ?? null)
        || !is_array($loaded['fallback'] ?? null)
    ) {
        _stattic_render_runtime_invariant_error_lazy('functions-route-metadata-missing', 'Runtime Functions route metadata is malformed.');
    }

    return $cache[$path] = $loaded;
}

function _stattic_functions_route_method_matches(?string $routeMethod, string $requestMethod): bool
{
    return $routeMethod === null
        || $routeMethod === $requestMethod
        || ($routeMethod === 'GET' && $requestMethod === 'HEAD');
}

/**
 * Runs after every static and Zero resolution: assets win, and a request
 * matching no compiled route never wakes the worker. The worker owns status
 * semantics for matched paths, so the outputs are three: dispatch when a route
 * claims this path and method; a router-built 405 with the allowed methods when
 * a route claims the path at other methods (before the worker, since the table
 * already knows what it answers); and null when no route claims the path at all.
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
            // matches, so only a concrete method reaches here — and GET carries
            // HEAD with it, exactly as the match does.
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

// Stored beside the version's file tree, never inside it: a publish reaches
// `files/` and nothing else, so no upload can create or amend this document.
function _stattic_functions_config(string $versionRoot): ?array
{
    $path = dirname($versionRoot) . '/functions/config.json';
    $config = _stattic_runtime_read_json($path);
    if (!is_array($config) || ($config['runtimeKind'] ?? null) !== 'functions') {
        return null;
    }
    $host = is_array($config['host'] ?? null) ? $config['host'] : [];
    $artifact = is_array($config['artifact'] ?? null) ? $config['artifact'] : [];
    if (
        !is_string($host['hostname'] ?? null) || $host['hostname'] === ''
        || !is_string($host['bundleUrl'] ?? null) || $host['bundleUrl'] === ''
        || !is_string($artifact['mainModule'] ?? null) || $artifact['mainModule'] === ''
        || !is_string($artifact['compatibilityDate'] ?? null) || $artifact['compatibilityDate'] === ''
    ) {
        return null;
    }
    return $config;
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

// The grant and the relay credential travel together or not at all.
function _stattic_functions_dispatch_headers(
    array $config,
    string $spaceId,
    string $versionId,
    string $requestId,
    string $dispatchToken,
    string $originBaseUrl
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
        // Storage, database and platform services transit the relay. The Next
        // cache and tenant fetch live with the worker, and log delivery
        // degrades on its own below.
        $capabilities = array_values(array_filter(
            $capabilities,
            static fn($c) => in_array($c, ['fetch', 'log', 'next.cache'], true)
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
        'sf-fx-caps' => implode(',', $capabilities),
        'sf-fx-space' => $spaceId,
        'sf-fx-version' => $versionId,
        'sf-fx-request' => $requestId,
        'sf-fx-dispatch-token' => $dispatchToken,
        // Base64 so a value containing a newline cannot inject a header.
        'sf-fx-env' => base64_encode($encodedVariableValues),
    ];
    $relayNeeded = array_filter(
        $capabilities,
        static fn($c) => !in_array($c, ['fetch', 'log', 'next.cache'], true)
    ) !== [];
    if ($relayUsable && $relayNeeded) {
        $headers['sf-fx-relay'] = (string) $relay['url'];
        $headers['sf-fx-relay-token'] = (string) $relay['token'];
    }
    if ($relayUsable) {
        $headers['sf-fx-log'] = $originBaseUrl . '/' . STATTIC_FUNCTIONS_LOGS_PATH;
        $headers['sf-fx-log-token'] = (string) $relay['token'];
    }
    // The purge channel is independent of the relay too: its credential is its
    // own (minted at finalize beside the relay token, verified by this origin's
    // purge route), and a worker granted nothing else must still be able to
    // evict the pages it rendered. The URL is composed from the request host —
    // like log intake — because the purge must land on the cache in front of
    // the hostname the visitor actually hit.
    $purge = is_array($config['purge'] ?? null) ? $config['purge'] : null;
    if ($purge !== null && is_string($purge['token'] ?? null) && $purge['token'] !== '') {
        $headers['sf-fx-purge'] = $originBaseUrl . '/' . STATTIC_FUNCTIONS_PURGE_PATH;
        $headers['sf-fx-purge-token'] = (string) $purge['token'];
    }
    // Usage reporting is independent of the relay: the credential is the
    // control plane's, the destination is the control plane, and this origin
    // only forwards them. So a version whose relay is unusable — and which
    // therefore has no log channel — is still counted.
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

// Inbound `sf-fx-*` is stripped: a visitor sending `sf-fx-caps: db.write` must
// not reach the host, where it would be indistinguishable from ours.
// `authorization` is deliberately absent — Spacefast credentials ride
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

// Cloudflare terminates the internal Functions hop; its response metadata
// describes that hop, and the outer CDN would cache stale Ray IDs.
function _stattic_functions_relay_response_lane(): array
{
    return [
        'deny' => ['content-encoding', 'strict-transport-security'],
        'deny_prefixes' => [SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX, 'cf-'],
        'deny_value' => static function (string $name, string $value): bool {
            $lowerValue = strtolower($value);
            return ($name === 'server' && trim($lowerValue) === 'cloudflare')
                || (
                    in_array($name, ['nel', 'report-to'], true)
                    && (str_contains($lowerValue, 'cf-nel') || str_contains($lowerValue, 'cloudflare.com'))
                );
        },
    ];
}

// Without this the tenant worker's own Cache-Control governs the edge, so a
// private space's Functions responses would be shared-cacheable by worker fiat.
// A public space keeps worker-declared caching — the lane declares no policy —
// but only while the worker's response carries nothing a shared store cannot
// honor: the wp.cloud edge keys a stored response on host+path+query alone and
// ignores Vary, so a worker answering `Vary: RSC` beside a public s-maxage
// would be stored once by URL and replayed to every variant. The signals that
// revoke a proxy origin's shared-cache grant (any Vary beyond Accept-Encoding,
// a Set-Cookie, a private/no-cache directive) therefore revoke the worker's the
// same way, down to the same no-store.
function _stattic_functions_response_cache_policy(bool $privateCache, array $workerHeaders): array
{
    return _stattic_cache_policy([
        'private' => $privateCache,
        'public' => _stattic_cache_policy_upstream_revokes_shared_store($workerHeaders)
            ? STATTIC_CACHE_CONTROL_NO_STORE
            : null,
    ]);
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
    // the execution edge or answers 503 — it never degrades to static and never
    // runs the worker on the origin (the origin decides; the edge executes). A
    // version with no functions carries no config, so this code is never entered
    // for it: the down-ramp is not-compiling-routes, not a serve-time flag check.
    $config = _stattic_functions_config($versionRoot);
    $dispatchToken = (string) (_stattic_config_value('SPACEFAST_FUNCTIONS_DISPATCH_TOKEN') ?? '');
    // "No reachable edge host" is any of: a config finalize wrote no host into,
    // no dispatch credential on this origin (the runtime-wide rollback), curl
    // missing, or a host egress policy blocks. Order matters: the destination
    // check dereferences the host, so it runs only once $config is non-null.
    if (
        $config === null
        || $dispatchToken === ''
        || !_stattic_http_available()
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
        'https://' . $requestHost
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
        'on_headers' => static function (int $responseStatus, array $headerPairs) use (&$status, &$headersSent, $privateCache, $insertSnippets): void {
            $status = $responseStatus;
            http_response_code($responseStatus);
            $headersSent = true;
            // Decided per response, never before the dispatch: the worker's own
            // headers are the lane input that can revoke shared caching, and
            // they exist only once the host has answered.
            $cachePolicy = _stattic_functions_response_cache_policy($privateCache, $headerPairs);
            _stattic_relay_send_response_headers(
                _stattic_cache_policy_apply_lines(
                    $cachePolicy,
                    _stattic_relay_response_header_lines($headerPairs, $cachePolicy, _stattic_functions_relay_response_lane())
                ),
                $cachePolicy
            );
            // After the response headers, before the first body byte: the
            // filter reads the declared content type and never sees a platform
            // page (a failure past this point truncates instead, by design).
            _stattic_html_insert_stream_begin($insertSnippets);
        },
    ];
    $input = null;
    if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
        $input = fopen('php://input', 'rb');
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
// for every "no reachable edge" cause, so swapping the edge system stays a
// config change with no new serve-time verdict.
function _stattic_functions_edge_unconfigured(): void
{
    _stattic_serve_page('runtime-unavailable', [
        'status' => 503,
        'code' => 'functions_edge_unconfigured',
        'message' => 'Function execution is not available.',
    ]);
}
