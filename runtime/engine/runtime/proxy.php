<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/http.php';
require_once __DIR__ . '/../shared/cache-policy.php';
require_once __DIR__ . '/../shared/upstream-relay.php';
require_once __DIR__ . '/../shared/html-insert.php';

// Proxy caching is an explicit route capability, never inferred from an
// upstream response: `cache=shared` grants only the short revalidatable edge
// policy, and any personalization signal revokes it back to no-store.
const STATTIC_PROXY_EDGE_CACHE_POLICY = 'no-store';
const STATTIC_PROXY_SHARED_CACHE_POLICY = STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
const STATTIC_PROXY_SHARED_CACHE_STATUSES = [200, 203, 300, 301, 304, 404, 410];

// The visitor's credential opens THIS space, so a publisher's origin never sees
// it; the rest is the shared inbound denylist.
const STATTIC_PROXY_DENIED_REQUEST_HEADERS = ['authorization'];

function _stattic_proxy_cache_request_eligible(
    mixed $cacheMode,
    array $routeHeaders,
    array $forwardHeaders,
    string $requestMethod,
    string $upstreamMethod,
    bool $privateResponse
): bool
{
    return $cacheMode === 'shared'
        && in_array(strtoupper($requestMethod), ['GET', 'HEAD'], true)
        && in_array(strtoupper($upstreamMethod), ['GET', 'HEAD'], true)
        && !$privateResponse
        && $routeHeaders === []
        && $forwardHeaders === [];
}

// The route's shared-cache grant is a request-side opt-in; the upstream's own
// headers are the lane input that can revoke it.
function _stattic_proxy_response_cache_policy(
    bool $sharedCacheRequest,
    int $responseStatus,
    array $originHeaders,
    bool $privateResponse
): array
{
    $eligible = $sharedCacheRequest
        && in_array($responseStatus, STATTIC_PROXY_SHARED_CACHE_STATUSES, true);
    return _stattic_cache_policy([
        'private' => $privateResponse,
        'public' => $eligible ? STATTIC_PROXY_SHARED_CACHE_POLICY : STATTIC_PROXY_EDGE_CACHE_POLICY,
        'upstream' => $eligible ? $originHeaders : [],
        'public_revoked' => STATTIC_PROXY_EDGE_CACHE_POLICY,
    ]);
}

function _stattic_proxy_response_header_lines(array $originHeaders, array $platformHeaderNames, array $platformHeaders, array $cachePolicy): array
{
    $lines = _stattic_relay_response_header_lines($originHeaders, $cachePolicy, ['suppress' => $platformHeaderNames]);
    foreach ($platformHeaders as [$name, $value]) {
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, STATTIC_RELAY_STRIPPED_RESPONSE_HEADERS, true)
            || in_array($lowerName, STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS, true)
        ) {
            continue;
        }
        $lines[] = [$name, $value];
    }
    return _stattic_cache_policy_apply_lines($cachePolicy, $lines);
}

// $serving carries the version's resolved runtime config; the only thing this
// lane reads from it is the head-insert snippets.
function _stattic_proxy_request(array $route, string $remainder, array $serving = []): void
{
    $insertSnippets = _stattic_html_insert_snippets($serving);
    if (($route['action'] ?? null) !== 'proxy') {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy route metadata is malformed.\n");
    }

    if (!_stattic_http_available()) {
        _stattic_render_platform_page('runtime-unavailable', 503, [], "Proxy runtime requires curl.\n");
    }

    if (!empty($route['disabled'])) {
        _stattic_render_platform_page('proxy-disabled', 403, [], (string) ($route['disabledReason'] ?? "Proxy route is disabled.\n"));
    }

    if (!_stattic_egress_proxy_policy_shape_valid($route)) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy route policy is incomplete.\n");
    }

    $upstream = (string) ($route['upstream'] ?? '');
    if ($upstream === '') {
        _stattic_render_not_found();
    }

    $target = _stattic_append_path_to_url(
        _stattic_join_upstream_base($upstream, (string) ($route['target_prefix'] ?? '/')),
        $remainder
    );

    // The share-link token opens THIS space and nothing else: a publisher's
    // origin (and its logs) must never see it, however it arrived.
    $query = _stattic_strip_access_query_token((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($query !== '') {
        $target .= (str_contains($target, '?') ? '&' : '?') . $query;
    }
    $targetParts = _stattic_assert_proxy_target_allowed($target);
    if (_stattic_path_has_residual_dot_segment((string) ($targetParts['path'] ?? '/'))) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy request path is invalid.\n");
    }

    $requestMethod = _stattic_runtime_request_method();
    $allowedMethods = _stattic_proxy_allowed_methods($route['methods']);
    if (!in_array($requestMethod, $allowedMethods, true)) {
        _stattic_render_platform_page('method-not-allowed', 405, ['Allow' => implode(', ', $allowedMethods)], "Proxy route does not allow this method.\n");
    }
    $method = isset($route['method']) && is_string($route['method']) ? strtoupper($route['method']) : $requestMethod;
    // A condition-matched rule is per-visitor, so never shared-cacheable.
    $privateResponse = _stattic_access_private_cache_flag() || !empty($route['conditional_match']);
    $sharedCacheRequest = _stattic_proxy_cache_request_eligible(
        $route['cache'] ?? null,
        $route['headers'],
        $route['forwardHeaders'],
        $requestMethod,
        $method,
        $privateResponse
    );
    $headers = _stattic_collect_proxy_request_headers(
        $route['headers'],
        $route['forwardHeaders'],
        $sharedCacheRequest
    );
    $bodyLimit = $route['bodySizeLimitBytes'];
    $body = null;
    if ($method !== 'GET' && $method !== 'HEAD') {
        $body = _stattic_read_proxy_request_body($bodyLimit);
    }

    $responseHeaders = [];
    $responseStatus = 0;
    $headersSent = false;
    $platformResponseHeaders = is_array($route['response_headers'] ?? null) ? $route['response_headers'] : [];
    $platformHeaderNames = [];
    $platformHeadersToEmit = [];
    foreach ($platformResponseHeaders as $name => $value) {
        if (!is_string($name) || !is_string($value) || $name === '') {
            continue;
        }
        $platformHeaderNames[strtolower($name)] = true;
        if ($value !== '') {
            $platformHeadersToEmit[] = [$name, $value];
        }
    }
    $collectResponseHeaders = static function (int $status, array $headerPairs) use (&$responseHeaders, &$responseStatus): void {
        $responseStatus = $status;
        $responseHeaders = $headerPairs;
        http_response_code($status);
    };

    $proxyCachePolicy = static function () use (&$responseHeaders, &$responseStatus, $platformHeadersToEmit, $sharedCacheRequest, $privateResponse): array {
        return _stattic_proxy_response_cache_policy(
            $sharedCacheRequest,
            $responseStatus,
            [...$responseHeaders, ...$platformHeadersToEmit],
            $privateResponse
        );
    };

    $sendProxyResponseHeaders = static function () use (&$responseHeaders, $platformHeaderNames, $platformHeadersToEmit, $proxyCachePolicy): void {
        $cachePolicy = $proxyCachePolicy();
        _stattic_relay_send_response_headers(
            _stattic_proxy_response_header_lines($responseHeaders, $platformHeaderNames, $platformHeadersToEmit, $cachePolicy),
            $cachePolicy
        );
    };

    // A shared-cacheable response is buffered and emitted only after curl
    // reports a complete transfer, so a truncated upstream can never leave
    // public cache headers attached to partial bytes.
    $relayedBodyBytes = 0;
    $bufferedBody = '';
    $bufferSharedResponse = null;
    // false is the unlatched sentinel: null is a legitimate declared length.
    $declaredLength = false;
    $relayChunk = static function (string $chunk) use ($sendProxyResponseHeaders, $proxyCachePolicy, &$headersSent, &$responseHeaders, &$relayedBodyBytes, &$bufferedBody, &$bufferSharedResponse, &$declaredLength, $bodyLimit, $insertSnippets): bool {
        if ($bufferSharedResponse === null) {
            $bufferSharedResponse = $proxyCachePolicy()['cache_control'] === STATTIC_PROXY_SHARED_CACHE_POLICY;
        }
        if ($declaredLength === false) {
            $declaredLength = _stattic_proxy_origin_content_length($responseHeaders);
        }
        $decision = _stattic_proxy_stream_limit_decision(
            $declaredLength,
            $headersSent,
            $relayedBodyBytes,
            strlen($chunk),
            $bodyLimit
        );
        if ($decision === 'reject') {
            _stattic_render_platform_page('proxy-response-too-large', 502, [
                'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
                'X-Spacefast-Proxy-Response-Limit' => (string) $bodyLimit,
            ], "Proxy response exceeds the configured limit.\n");
        }
        if ($decision === 'abort') {
            return false;
        }
        $relayedBodyBytes += strlen($chunk);
        if ($bufferSharedResponse) {
            $bufferedBody .= $chunk;
            return true;
        }
        if (!$headersSent) {
            $sendProxyResponseHeaders();
            $headersSent = true;
            // Installed here and nowhere earlier: past this point every byte is
            // upstream body, so a platform error page can never be filtered.
            _stattic_html_insert_stream_begin($insertSnippets);
        }

        echo $chunk;
        flush();
        return true;
    };

    $request = [
        'url' => $target,
        'method' => $method,
        'headers' => $headers,
        'connect_timeout' => $route['connectTimeoutSeconds'],
        'timeout' => $route['timeoutSeconds'],
        'schemes' => STATTIC_RUNTIME_EGRESS_PROXY_ROUTE_ALLOWED_SCHEMES,
        'resolve' => _stattic_proxy_upstream_pins($targetParts),
        'on_headers' => $collectResponseHeaders,
        'sink' => $relayChunk,
    ];
    if ($method !== 'GET' && $method !== 'HEAD' && $body !== null && $body !== '') {
        $request['body'] = $body;
    }

    $result = _stattic_http_request($request);
    if ($result['error'] !== null) {
        _stattic_relay_abort_after_headers($headersSent);
        _stattic_render_platform_page('proxy-error', 502, [
            'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
        ], "Proxy request failed: " . $result['error'] . "\n");
    }

    if (!$headersSent) {
        $sendProxyResponseHeaders();
        if ($bufferedBody !== '') {
            _stattic_html_insert_stream_begin($insertSnippets);
            echo $bufferedBody;
            flush();
        }
    }

    _stattic_html_insert_stream_end();
    exit;
}

// Null when absent, malformed, or self-contradictory — the streaming counter is
// then the only enforcement.
function _stattic_proxy_origin_content_length(array $originHeaders): ?int
{
    $declared = null;
    foreach ($originHeaders as [$name, $value]) {
        if (strtolower((string) $name) !== 'content-length') {
            continue;
        }
        $trimmed = trim((string) $value);
        if (preg_match('/^\d{1,15}$/', $trimmed) !== 1) {
            return null;
        }
        $length = (int) $trimmed;
        if ($declared !== null && $declared !== $length) {
            return null;
        }
        $declared = $length;
    }
    return $declared;
}

// 'reject': provably oversize with nothing emitted yet — clean 502.
// 'abort': headers already out, so the transfer ends truncated.
// 'relay': within the limit.
function _stattic_proxy_stream_limit_decision(?int $declaredLength, bool $headersSent, int $relayedBytes, int $chunkBytes, int $limit): string
{
    if ($relayedBytes + $chunkBytes > $limit) {
        return $headersSent ? 'abort' : 'reject';
    }
    if (!$headersSent && $declaredLength !== null && $declaredLength > $limit) {
        return 'reject';
    }
    return 'relay';
}

function _stattic_proxy_allowed_methods(array $methods): array
{
    $normalized = [];
    foreach ($methods as $method) {
        $normalized[strtoupper($method)] = true;
    }
    return array_keys($normalized);
}

function _stattic_assert_proxy_target_allowed(string $target): array
{
    $parts = parse_url($target);
    if (!is_array($parts)) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target is invalid.\n");
    }
    $parts['scheme'] = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($parts['scheme'], STATTIC_RUNTIME_EGRESS_PROXY_ROUTE_ALLOWED_SCHEMES, true) || $host === '') {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target must be an absolute HTTP(S) URL.\n");
    }
    $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
    if (!_stattic_egress_host_allowed($host, $port)) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target host is not allowed.\n");
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target must not include credentials.\n");
    }
    $path = (string) ($parts['path'] ?? '/');
    if (_stattic_path_is_reserved($path)) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy route cannot target runtime control paths.\n");
    }
    return $parts;
}

// Pins the transport to the validated addresses so the connection cannot be
// rebound to a different IP after validation.
function _stattic_proxy_upstream_pins(array $parts): array
{
    $scheme = (string) ($parts['scheme'] ?? '');
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ips = _stattic_egress_resolve_public_ips($host, $port);
    if ($ips === null) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target host is not allowed.\n");
    }
    return _stattic_egress_curl_resolve_entries($host, $port, $ips);
}

function _stattic_collect_proxy_request_headers(
    array $routeHeaders,
    array $forwardHeaderNames,
    bool $sharedCacheRequest = false
): array
{
    $forwarded = [];
    $allowed = [];
    foreach ($forwardHeaderNames as $name) {
        if (is_string($name) && $name !== '') {
            $allowed[] = strtolower($name);
        }
    }

    // An anonymous shared-cache request inherits no visitor-controlled header
    // beyond the validators an origin needs to answer 304 end to end, and its
    // REMOTE_ADDR is withheld so it cannot become an origin-side variant the
    // origin forgets to declare with Vary.
    $inbound = _stattic_relay_request_headers([
        'allow' => $sharedCacheRequest
            ? array_values(array_diff(['if-none-match', 'if-modified-since'], $allowed))
            : $allowed,
        'deny' => STATTIC_PROXY_DENIED_REQUEST_HEADERS,
    ]);
    if ($sharedCacheRequest) {
        // Re-spelled canonically: a shared-cache candidate must not vary by how
        // the client capitalized the name.
        foreach (['if-none-match' => 'If-None-Match', 'if-modified-since' => 'If-Modified-Since'] as $lowerName => $canonical) {
            foreach ($inbound as [$name, $value]) {
                if (strtolower($name) === $lowerName && $value !== '') {
                    $forwarded[] = $canonical . ': ' . $value;
                    break;
                }
            }
        }
    } else {
        foreach ($inbound as [$name, $value]) {
            $forwarded[] = $name . ': ' . $value;
        }
    }

    $forwarded[] = 'X-Forwarded-Host: ' . _stattic_relay_safe_header_value((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $forwarded[] = 'X-Forwarded-Proto: ' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    if (
        !$sharedCacheRequest
        && isset($_SERVER['REMOTE_ADDR'])
        && is_string($_SERVER['REMOTE_ADDR'])
        && $_SERVER['REMOTE_ADDR'] !== ''
    ) {
        $forwarded[] = 'X-Forwarded-For: ' . _stattic_relay_safe_header_value($_SERVER['REMOTE_ADDR']);
    }

    foreach ($routeHeaders as $name => $value) {
        if (_stattic_relay_header_prefixed(strtolower((string) $name), STATTIC_RELAY_DENIED_REQUEST_PREFIXES)) {
            continue; // Static route headers can never impersonate an identity.
        }
        $forwarded[] = $name . ': ' . _stattic_relay_safe_header_value((string) $value);
    }

    return $forwarded;
}

function _stattic_read_proxy_request_body(int $bodyLimit): ?string
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > $bodyLimit) {
        _stattic_render_platform_page('request-too-large', 413, [], "Proxy request body exceeds the configured limit.\n");
    }

    $stream = _stattic_request_body_stream();
    if ($stream === false) {
        return null;
    }

    $body = '';
    while (!feof($stream)) {
        $chunk = fread($stream, 8192);
        if ($chunk === false) {
            fclose($stream);
            return null;
        }

        $body .= $chunk;
        if (strlen($body) > $bodyLimit) {
            fclose($stream);
            _stattic_render_platform_page('request-too-large', 413, [], "Proxy request body exceeds the configured limit.\n");
        }
    }

    fclose($stream);
    return $body;
}

function _stattic_join_upstream_base(string $upstream, string $targetPrefix): string
{
    if ($targetPrefix === '' || $targetPrefix === '/') {
        return $upstream;
    }

    return _stattic_append_path_to_url($upstream, $targetPrefix);
}
