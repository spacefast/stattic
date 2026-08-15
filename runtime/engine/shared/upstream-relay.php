<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

/**
 * One relay for every lane that puts an untrusted upstream's response on the
 * visitor's connection. A proxy route's origin and a tenant's Functions worker
 * are the same trust class — both answer on the space's own hostname — so both
 * cross here, and a lane's difference is an option, never a second filter.
 *
 * The header state machine itself lives one layer down, in http.php: a status
 * line resets the collected block, and on_headers fires once for the first
 * non-1xx block, so a 1xx preamble's headers can never reach a visitor.
 */

// Hop-by-hop / transport headers describe the hop we terminate (RFC 9110 §7.6.1);
// Content-Length is dropped because this server re-frames the relayed body.
const STATTIC_RELAY_HOP_BY_HOP_RESPONSE_HEADERS = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'transfer-encoding', 'upgrade', 'content-length'];

// Never relayed regardless of the cache verdict: Set-Cookie must not cross onto
// the space hostname, and upstream cache metadata must not replace the platform
// policy the lane decided.
const STATTIC_RELAY_STRIPPED_RESPONSE_HEADERS = ['set-cookie', 'set-cookie2', 'pragma', 'surrogate-control', 'cdn-cache-control'];

// Inbound framing that describes the hop we terminate, not the one we open.
const STATTIC_RELAY_DENIED_REQUEST_HEADERS = ['host', 'content-length', 'connection', 'keep-alive', 'transfer-encoding', 'te', 'trailers', 'upgrade', 'expect'];

// Spacefast-Access-* is platform-owned identity forwarding, runtime→upstream: an
// inbound copy is forged by definition.
const STATTIC_RELAY_DENIED_REQUEST_PREFIXES = ['spacefast-access-'];

function _stattic_relay_header_prefixed(string $lowerName, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (str_starts_with($lowerName, $prefix)) {
            return true;
        }
    }
    return false;
}

// Header-injection guard for every value this runtime hands an upstream. The
// response direction needs none: http.php trims and splits one header line at a
// time, so no control character survives into a relayed value.
function _stattic_relay_safe_header_value(string $value): string
{
    return (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);
}

/**
 * The visitor's own headers, filtered once for every upstream lane.
 *   allow          ?list<string>  lowercase names that may forward at all;
 *                                 null forwards everything not denied
 *   deny           list<string>   lane-specific extra names
 *   deny_prefixes  list<string>   lane-specific extra prefixes
 *
 * @return list<array{0: string, 1: string}>
 */
function _stattic_relay_request_headers(array $lane = []): array
{
    $headerMap = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headerMap)) {
        $headerMap = [];
    }
    $allow = is_array($lane['allow'] ?? null) ? $lane['allow'] : null;
    $deny = [...STATTIC_RELAY_DENIED_REQUEST_HEADERS, ...(is_array($lane['deny'] ?? null) ? $lane['deny'] : [])];
    $denyPrefixes = [...STATTIC_RELAY_DENIED_REQUEST_PREFIXES, ...(is_array($lane['deny_prefixes'] ?? null) ? $lane['deny_prefixes'] : [])];

    $forwarded = [];
    foreach ($headerMap as $name => $value) {
        $name = (string) $name;
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, $deny, true)
            || _stattic_relay_header_prefixed($lowerName, $denyPrefixes)
            || ($allow !== null && !in_array($lowerName, $allow, true))
            || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1
        ) {
            continue;
        }
        $forwarded[] = [$name, _stattic_relay_safe_header_value(is_scalar($value) ? (string) $value : '')];
    }
    return $forwarded;
}

/**
 * What an untrusted upstream may put on the space's hostname. Everything not
 * named below relays verbatim, EXCEPT the internal-redirect/sendfile family:
 * under nginx X-Accel-Redirect/X-Sendfile would make the server serve an
 * arbitrary internal path (every space's private version tree) and
 * X-Accel-Limit-Rate pins the worker.
 *   suppress       array<string, true>  platform names that win over the upstream
 *   deny           list<string>         lane-specific extra names
 *   deny_prefixes  list<string>         lane-specific extra prefixes
 *   deny_value     ?callable            lane rules that read the value too
 *
 * @return list<array{0: string, 1: string}>
 */
function _stattic_relay_response_header_lines(array $upstreamHeaders, array $cachePolicy, array $lane = []): array
{
    // Lazy: a lane that never reaches an upstream has no headers to filter and
    // should not pay for the policy module.
    require_once __DIR__ . '/safety.php';

    $deny = [
        ...STATTIC_RELAY_HOP_BY_HOP_RESPONSE_HEADERS,
        ...STATTIC_RELAY_STRIPPED_RESPONSE_HEADERS,
        ...(is_array($lane['deny'] ?? null) ? $lane['deny'] : []),
        // A decided policy replaces the upstream's cache metadata outright; a
        // lane that declared none (a public space's worker) keeps it.
        ...(($cachePolicy['cache_control'] ?? null) !== null ? STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS : []),
    ];
    $denyPrefixes = is_array($lane['deny_prefixes'] ?? null) ? $lane['deny_prefixes'] : [];
    $denyValue = is_callable($lane['deny_value'] ?? null) ? $lane['deny_value'] : null;
    $suppress = is_array($lane['suppress'] ?? null) ? $lane['suppress'] : [];

    $lines = [];
    foreach ($upstreamHeaders as [$name, $value]) {
        $name = (string) $name;
        $value = (string) $value;
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, $deny, true)
            || isset(SPACEFAST_INTERNAL_REDIRECT_HEADERS[$lowerName])
            || isset($suppress[$lowerName])
            || _stattic_relay_header_prefixed($lowerName, $denyPrefixes)
            || ($denyValue !== null && $denyValue($lowerName, $value) === true)
        ) {
            continue;
        }
        $lines[] = [$name, $value];
    }
    return $lines;
}

function _stattic_relay_send_response_headers(array $lines, array $cachePolicy): void
{
    if (!empty($cachePolicy['private'])) {
        _stattic_clear_private_content_response_headers();
    }
    foreach ($lines as [$name, $value]) {
        // Cache-Control replaces any host-level default, never appends to it.
        header($name . ': ' . $value, strtolower((string) $name) === 'cache-control');
    }
}

// Mid-stream failure: the status and some bytes are already on the wire, so
// appending an error page would corrupt the response. PHP cannot force an
// abortive close through FastCGI, so ending the script mid-body is the
// strongest truncation signal available.
function _stattic_relay_abort_after_headers(bool $headersSent): void
{
    if ($headersSent) {
        exit;
    }
}
