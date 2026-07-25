<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/egress.php';

// Proxy caching is an explicit route capability, never inferred from an
// upstream response. `cache=shared` grants only the platform's short,
// revalidatable edge policy; every request/response personalization signal can
// revoke it. Unopted and unsafe responses retain the historical no-store
// default, and access-protected responses use the stronger private pin.
const STATTIC_PROXY_EDGE_CACHE_POLICY = 'no-store';
const STATTIC_PROXY_SHARED_CACHE_POLICY = STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
const STATTIC_PROXY_PRIVATE_CACHE_POLICY = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
const STATTIC_PROXY_SHARED_CACHE_STATUSES = [200, 203, 300, 301, 304, 404, 410];

// Hop-by-hop / transport response headers never relayed (RFC 9110 §7.6.1).
// Content-Length is dropped because this server re-frames the relayed body.
const STATTIC_PROXY_HOP_BY_HOP_RESPONSE_HEADERS = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'transfer-encoding', 'upgrade', 'content-length'];

// Origin response headers never relayed regardless of the cache verdict.
// Set-Cookie must not cross onto the space hostname, and upstream/CDN cache
// metadata must not replace the platform policy selected below.
const STATTIC_PROXY_STRIPPED_RESPONSE_HEADERS = ['set-cookie', 'set-cookie2', 'pragma', 'surrogate-control', 'cdn-cache-control'];

function _stattic_proxy_cache_request_eligible(
    mixed $cacheMode,
    array $routeHeaders,
    array $forwardHeaders,
    string $requestMethod,
    string $upstreamMethod,
    bool $privateResponse,
    bool $identityHeadersAttached
): bool
{
    return $cacheMode === 'shared'
        && in_array(strtoupper($requestMethod), ['GET', 'HEAD'], true)
        && in_array(strtoupper($upstreamMethod), ['GET', 'HEAD'], true)
        && !$privateResponse
        && !$identityHeadersAttached
        && $routeHeaders === []
        && $forwardHeaders === [];
}

function _stattic_proxy_response_revokes_shared_cache(array $originHeaders): bool
{
    foreach ($originHeaders as $header) {
        if (!is_array($header)) {
            return true;
        }
        $name = strtolower(trim((string) ($header[0] ?? '')));
        $value = trim((string) ($header[1] ?? ''));
        if (in_array($name, ['set-cookie', 'set-cookie2'], true)) {
            return true;
        }
        if ($name === 'vary') {
            $varyNames = array_values(array_filter(array_map(
                static fn (string $varyName): string => strtolower(trim($varyName)),
                explode(',', $value)
            )));
            if ($varyNames === [] || array_diff($varyNames, ['accept-encoding']) !== []) {
                return true;
            }
            continue;
        }
        if (in_array($name, ['cache-control', 'cdn-cache-control', 'surrogate-control'], true)) {
            foreach (explode(',', $value) as $directive) {
                $directiveName = strtolower(trim((string) (explode('=', trim($directive), 2)[0] ?? '')));
                if (in_array($directiveName, ['private', 'no-store', 'no-cache'], true)) {
                    return true;
                }
            }
            continue;
        }
        if ($name === 'pragma' && str_contains(strtolower($value), 'no-cache')) {
            return true;
        }
    }
    return false;
}

function _stattic_proxy_response_cache_policy(
    bool $sharedCacheRequest,
    int $responseStatus,
    array $originHeaders,
    bool $privateResponse
): string
{
    if ($privateResponse) {
        return STATTIC_PROXY_PRIVATE_CACHE_POLICY;
    }
    if (
        !$sharedCacheRequest
        || !in_array($responseStatus, STATTIC_PROXY_SHARED_CACHE_STATUSES, true)
        || _stattic_proxy_response_revokes_shared_cache($originHeaders)
    ) {
        return STATTIC_PROXY_EDGE_CACHE_POLICY;
    }
    return STATTIC_PROXY_SHARED_CACHE_POLICY;
}

// Computes the exact emitted header lines. Origin and route-level cache
// metadata are stripped; the final classifier policy is the single caching
// authority. Validators remain available for safe 304 revalidation.
function _stattic_proxy_response_header_lines(array $originHeaders, array $platformHeaderNames, array $platformHeaders, string $cachePolicy): array
{
    $lines = [];
    foreach ($originHeaders as [$name, $value]) {
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, STATTIC_PROXY_HOP_BY_HOP_RESPONSE_HEADERS, true)
            || in_array($lowerName, STATTIC_PROXY_STRIPPED_RESPONSE_HEADERS, true)
            || in_array($lowerName, STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS, true)
            || isset($platformHeaderNames[$lowerName])
        ) {
            continue;
        }
        $lines[] = [$name, $value];
    }
    foreach ($platformHeaders as [$name, $value]) {
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, STATTIC_PROXY_STRIPPED_RESPONSE_HEADERS, true)
            || in_array($lowerName, STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS, true)
        ) {
            continue;
        }
        $lines[] = [$name, $value];
    }
    $lines[] = ['Cache-Control', $cachePolicy];
    return $lines;
}

function _stattic_proxy_request(array $route, string $remainder): void
{
    if (($route['action'] ?? null) !== 'proxy') {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy route metadata is malformed.\n");
    }

    if (!function_exists('curl_init')) {
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

    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query !== '') {
        $target .= (str_contains($target, '?') ? '&' : '?') . $query;
    }
    $targetParts = _stattic_assert_proxy_target_allowed($target);

    $requestMethod = _stattic_runtime_request_method();
    $allowedMethods = _stattic_proxy_allowed_methods($route['methods']);
    if (!in_array($requestMethod, $allowedMethods, true)) {
        _stattic_render_platform_page('method-not-allowed', 405, ['Allow' => implode(', ', $allowedMethods)], "Proxy route does not allow this method.\n");
    }
    $method = isset($route['method']) && is_string($route['method']) ? strtoupper($route['method']) : $requestMethod;
    // Access enforcement ran before proxy dispatch. A condition-matched rule
    // and any verified identity forwarding are equally per-visitor.
    $privateResponse = _spacefast_access_private_cache_flag() || !empty($route['conditional_match']);
    $identityHeadersAttached = _stattic_proxy_identity_headers_available();
    $sharedCacheRequest = _stattic_proxy_cache_request_eligible(
        $route['cache'] ?? null,
        $route['headers'],
        $route['forwardHeaders'],
        $requestMethod,
        $method,
        $privateResponse,
        $identityHeadersAttached
    );
    $headers = _stattic_collect_proxy_request_headers(
        $route['headers'],
        $route['forwardHeaders'],
        // A shared-cache-eligible request is the only one that forwards
        // validators (permitting an upstream 304 without opening a
        // personalization seam) and the only one that must reach the origin
        // anonymously — in particular, REMOTE_ADDR must never become an
        // origin-side variant which an origin forgets to declare with Vary.
        $sharedCacheRequest
    );
    $bodyLimit = $route['bodySizeLimitBytes'];
    $body = null;
    if ($method !== 'GET' && $method !== 'HEAD') {
        $body = _stattic_read_proxy_request_body($bodyLimit);
    }

    $ch = curl_init($target);
    if ($ch === false) {
        _stattic_render_platform_page('proxy-error', 502, [], "Proxy target could not be initialized.\n");
    }

    _stattic_proxy_pin_resolved_upstream($ch, $targetParts);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $route['timeoutSeconds']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $route['connectTimeoutSeconds']);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    if ($method !== 'GET' && $method !== 'HEAD' && $body !== null && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $headerLine) use (&$responseHeaders, &$responseStatus): int {
        $length = strlen($headerLine);
        $trimmed = trim($headerLine);
        if ($trimmed === '') {
            return $length;
        }

        if (str_starts_with(strtoupper($trimmed), 'HTTP/')) {
            if (preg_match('/\s(\d{3})(?:\s|$)/', $trimmed, $matches)) {
                $responseStatus = (int) $matches[1];
                http_response_code($responseStatus);
            }
            // A new status line starts a new header block (e.g. after a 1xx
            // interim response); discard anything collected for the prior one.
            $responseHeaders = [];
            return $length;
        }

        $separator = strpos($trimmed, ':');
        if ($separator === false) {
            return $length;
        }

        $responseHeaders[] = [trim(substr($trimmed, 0, $separator)), trim(substr($trimmed, $separator + 1))];
        return $length;
    });

    $proxyCachePolicy = static function () use (&$responseHeaders, &$responseStatus, $platformHeadersToEmit, $sharedCacheRequest, $privateResponse): string {
        return _stattic_proxy_response_cache_policy(
            $sharedCacheRequest,
            $responseStatus,
            [...$responseHeaders, ...$platformHeadersToEmit],
            $privateResponse
        );
    };

    $sendProxyResponseHeaders = static function () use (&$responseHeaders, $platformHeaderNames, $platformHeadersToEmit, $proxyCachePolicy): void {
        foreach (_stattic_proxy_response_header_lines($responseHeaders, $platformHeaderNames, $platformHeadersToEmit, $proxyCachePolicy()) as [$name, $value]) {
            if (strtolower($name) === 'cache-control') {
                // Replace any host-level default with the classifier's single
                // authoritative policy.
                header($name . ': ' . $value, true);
            } else {
                header($name . ': ' . $value, false);
            }
        }
    };

    // Unsafe/no-store responses may stream. A response that could enter a
    // shared cache is buffered up to the route limit and emitted only after
    // curl reports a complete transfer; a truncated upstream can therefore
    // never leave public cache headers attached to partial bytes.
    $relayedBodyBytes = 0;
    $bufferedBody = '';
    $bufferSharedResponse = null;
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($sendProxyResponseHeaders, $proxyCachePolicy, &$headersSent, &$responseHeaders, &$relayedBodyBytes, &$bufferedBody, &$bufferSharedResponse, $bodyLimit): int {
        if ($bufferSharedResponse === null) {
            $bufferSharedResponse = $proxyCachePolicy() === STATTIC_PROXY_SHARED_CACHE_POLICY;
        }
        $decision = _stattic_proxy_stream_limit_decision(
            _stattic_proxy_origin_content_length($responseHeaders),
            $headersSent,
            $relayedBodyBytes,
            strlen($chunk),
            $bodyLimit
        );
        if ($decision === 'reject') {
            // Header block complete, nothing emitted yet: the origin declared
            // an oversize Content-Length (or the very first chunk already
            // exceeds the limit), so the common oversize case stays a clean,
            // never-cached platform error instead of a truncated stream.
            _stattic_render_platform_page('proxy-response-too-large', 502, [
                'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
                'X-Spacefast-Proxy-Response-Limit' => (string) $bodyLimit,
            ], "Proxy response exceeds the configured limit.\n");
        }
        if ($decision === 'abort') {
            return -1;
        }
        $relayedBodyBytes += strlen($chunk);
        if ($bufferSharedResponse) {
            $bufferedBody .= $chunk;
            return strlen($chunk);
        }
        if (!$headersSent) {
            $sendProxyResponseHeaders();
            $headersSent = true;
        }

        echo $chunk;
        flush();
        return strlen($chunk);
    });

    $success = curl_exec($ch);
    if ($success === false) {
        $error = curl_error($ch);
        if ($headersSent) {
            // Mid-stream failure (body-limit abort or the origin dying after
            // its first byte): status, headers, and some body bytes are on the
            // wire. Appending an error page here would corrupt the stream —
            // stop without another byte so the response ends short. PHP cannot
            // force an abortive close through FastCGI; ending the script
            // mid-body is the strongest truncation signal available.
            exit;
        }
        _stattic_render_platform_page('proxy-error', 502, [
            'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
        ], "Proxy request failed: " . $error . "\n");
    }

    if (!$headersSent) {
        $sendProxyResponseHeaders();
        if ($bufferedBody !== '') {
            echo $bufferedBody;
            flush();
        }
    }

    exit;
}

// Origin-declared body length from the raw collected header pairs: null when
// absent, malformed, or self-contradictory (then the streaming counter is the
// only enforcement). Pure — tests/unit.php exercises it directly.
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

// Body-limit decision for one streamed chunk. Pure — tests/unit.php exercises
// the state machine directly (the egress policy denies loopback upstreams, so
// the curl leg itself cannot be driven in-process; see
// tests/access-forwarding.test.ts).
//  - 'reject': nothing emitted yet and the body is provably oversize (declared
//    Content-Length over the limit, or the first chunk alone is) — clean 502.
//  - 'abort': headers already emitted and the accumulated body would cross the
//    limit — abort the transfer, leaving the response truncated.
//  - 'relay': within the limit, stream the chunk through.
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
    if (!in_array($parts['scheme'], ['http', 'https'], true) || $host === '') {
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
    if (str_starts_with($path, '/__spacefast')) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy route cannot target runtime control paths.\n");
    }
    return $parts;
}

// Resolve the upstream host, deny non-public addresses, and pin curl to the validated
// addresses so the connection cannot be rebound to a different IP after validation.
// $parts are the target URL parts returned by _stattic_assert_proxy_target_allowed.
function _stattic_proxy_pin_resolved_upstream(\CurlHandle $ch, array $parts): void
{
    $scheme = (string) ($parts['scheme'] ?? '');
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ips = _stattic_egress_resolve_public_ips($host, $port);
    if ($ips === null) {
        _stattic_render_platform_page('proxy-disabled', 403, [], "Proxy target host is not allowed.\n");
    }
    curl_setopt($ch, CURLOPT_RESOLVE, _stattic_egress_curl_resolve_entries($host, $port, $ips));
}

function _stattic_proxy_identity_headers_available(): bool
{
    $identity = $GLOBALS['SPACEFAST_ACCESS_FORWARD_IDENTITY'] ?? null;
    return is_array($identity)
        && is_string($identity['token'] ?? null)
        && $identity['token'] !== '';
}

function _stattic_collect_proxy_request_headers(
    array $routeHeaders,
    array $forwardHeaderNames,
    bool $sharedCacheRequest = false
): array
{
    $forwarded = [];
    $headerMap = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headerMap)) {
        $headerMap = [];
    }

    $allowed = [];
    foreach ($forwardHeaderNames as $name) {
        if (is_string($name) && $name !== '') {
            $allowed[] = strtolower($name);
        }
    }

    // Anonymous shared-cache requests do not inherit any visitor-controlled
    // request headers. Eligibility already requires an empty allowlist; this
    // branch keeps the outbound-request boundary safe if those checks drift.
    if (!$sharedCacheRequest) {
        foreach ($headerMap as $name => $value) {
            $lowerName = strtolower((string) $name);
            if (in_array($lowerName, ['host', 'content-length', 'authorization', 'connection'], true)) {
                continue;
            }
            // Identity-forwarding contract (access-plan §3.2 / X-36):
            // Spacefast-Access-* is a runtime→origin primitive — an inbound copy is
            // forged by definition and never crosses the proxy boundary, even when
            // a route's forwardHeaders lists it. The verified values (if any) are
            // appended below.
            if (str_starts_with($lowerName, 'spacefast-access-')) {
                continue;
            }

            if (in_array($lowerName, $allowed, true)) {
                $forwarded[] = $name . ': ' . $value;
            }
        }
    }

    if ($sharedCacheRequest) {
        // Cache-validator relay (safe upstream methods only): forward the
        // client's conditional headers so a revalidation-capable origin can
        // answer 304 end to end. Routes that already forwardHeaders-list a
        // validator keep that copy (no duplicate line is added here).
        foreach (['if-none-match' => 'If-None-Match', 'if-modified-since' => 'If-Modified-Since'] as $lowerName => $canonical) {
            if (in_array($lowerName, $allowed, true)) {
                continue;
            }
            foreach ($headerMap as $name => $value) {
                if (strtolower((string) $name) === $lowerName && is_string($value) && $value !== '') {
                    $forwarded[] = $canonical . ': ' . _stattic_proxy_safe_header_value($value);
                    break;
                }
            }
        }
    }

    $forwarded[] = 'X-Forwarded-Host: ' . (string) ($_SERVER['HTTP_HOST'] ?? '');
    $forwarded[] = 'X-Forwarded-Proto: ' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    if (
        !$sharedCacheRequest
        && isset($_SERVER['REMOTE_ADDR'])
        && is_string($_SERVER['REMOTE_ADDR'])
        && $_SERVER['REMOTE_ADDR'] !== ''
    ) {
        $forwarded[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    }

    foreach ($routeHeaders as $name => $value) {
        if (str_starts_with(strtolower((string) $name), 'spacefast-access-')) {
            continue; // Static route headers can never impersonate an identity.
        }
        $forwarded[] = $name . ': ' . $value;
    }

    // Identity forwarding (access-plan §3.2, "Identity forwarding (SSO
    // proxy)"): when THIS request was authorized by a verified visitor token
    // (never password-basic, never anonymous-allow — access-rules.php records
    // the seam only for token-satisfied allows), forward the raw verified
    // token, its sub, and its comma-joined verified grants so the origin can
    // verify against the published JWKS. Supported, not advertised.
    $identity = $GLOBALS['SPACEFAST_ACCESS_FORWARD_IDENTITY'] ?? null;
    if (
        !$sharedCacheRequest
        && is_array($identity)
        && is_string($identity['token'] ?? null)
        && $identity['token'] !== ''
    ) {
        $grants = [];
        foreach (is_array($identity['grants'] ?? null) ? $identity['grants'] : [] as $grant) {
            if (is_string($grant) && $grant !== '') {
                $grants[] = $grant;
            }
        }
        $forwarded[] = 'Spacefast-Access-Jwt: ' . _stattic_proxy_safe_header_value($identity['token']);
        $forwarded[] = 'Spacefast-Access-Sub: ' . _stattic_proxy_safe_header_value(is_string($identity['sub'] ?? null) ? $identity['sub'] : '');
        $forwarded[] = 'Spacefast-Access-Grants: ' . _stattic_proxy_safe_header_value(implode(',', $grants));
    }

    return $forwarded;
}

// Belt-and-braces header-injection guard for forwarded identity values: the
// inputs come from a signature-verified JWT, but a control character in a
// claim must still never split an outbound header line.
function _stattic_proxy_safe_header_value(string $value): string
{
    return (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);
}

function _stattic_read_proxy_request_body(int $bodyLimit): ?string
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > $bodyLimit) {
        _stattic_render_platform_page('request-too-large', 413, [], "Proxy request body exceeds the configured limit.\n");
    }

    $stream = fopen('php://input', 'rb');
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
