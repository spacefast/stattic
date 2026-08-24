<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/finalizer-protocol.generated.php';

// SSRF policy for every proxy surface: upstreams must never reach loopback,
// link-local, cloud-metadata, RFC1918/ULA/private ranges, or Spacefast-internal
// hosts. Policy tables are generated from stattic-runtime-core.

// PARTIAL check, a name/literal-level screen only, NOT the SSRF verdict. The
// name overstates it. It rejects empty/localhost, Spacefast-internal hosts and
// non-public IP literals, but returns true for every resolvable hostname
// WITHOUT resolving it. The binding check is _stattic_egress_resolve_public_ips,
// which pins the connect IPs; callers must gate the connection on that, never
// on this predicate alone.
function _stattic_egress_host_allowed(string $host, ?int $port = null): bool
{
    $normalized = strtolower(trim($host, "[] \t\n\r\0\x0B."));
    if (_stattic_egress_test_target_allowlisted($normalized, $port)) {
        return true;
    }
    if ($normalized === '' || $normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
        return false;
    }
    if (_stattic_egress_host_is_stattic_internal($normalized)) {
        return false;
    }
    if (filter_var($normalized, FILTER_VALIDATE_IP)) {
        return _stattic_egress_ip_public($normalized);
    }
    return true;
}

// The inverse problem to the tenant policy above, and why there are two: a
// tenant-named upstream must never be a Spacefast host, while a callback,
// exchange or dispatch destination must be exactly that. Platform URLs arrive
// from the control plane, so this checks the shape and an optional operator
// allowlist and never resolves DNS.
function _stattic_platform_destination_allowed(string $url): bool
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    $host = strtolower((string) $parts['host']);
    if ($host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $loopback = in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
        return false;
    }
    $allowed = array_filter(array_map(
        static fn (string $entry): string => strtolower(trim($entry)),
        explode(',', _stattic_config_value('SPACEFAST_ZERO_CALLBACK_ALLOWED_HOSTS')),
    ));
    if ($allowed === []) {
        return true;
    }
    return array_any($allowed, static fn (string $allowedHost): bool =>
        $host === $allowedHost || str_ends_with($host, '.' . $allowedHost));
}

function _stattic_egress_host_is_stattic_internal(string $host): bool
{
    foreach (STATTIC_RUNTIME_EGRESS_INTERNAL_HOSTS as $internal) {
        if ($host === $internal || str_ends_with($host, '.' . $internal)) {
            return true;
        }
    }
    $apiHost = strtolower((string) parse_url(_stattic_config_value('SPACEFAST_API_BASE_URL'), PHP_URL_HOST));
    if ($apiHost !== '' && $host === $apiHost) {
        return true;
    }
    return false;
}

function _stattic_egress_ip_public(string $ip): bool
{
    $packed = inet_pton(strtolower(trim($ip, '[]')));
    if (!is_string($packed)) {
        return false;
    }
    if (strlen($packed) === 16) {
        // IPv4-mapped IPv6 must pass the IPv4 policy for the inner address.
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return _stattic_egress_ip_outside_denylist(substr($packed, 12), STATTIC_RUNTIME_EGRESS_DENIED_IPV4);
        }
        return _stattic_egress_ip_outside_denylist($packed, STATTIC_RUNTIME_EGRESS_DENIED_IPV6);
    }
    if (strlen($packed) === 4) {
        return _stattic_egress_ip_outside_denylist($packed, STATTIC_RUNTIME_EGRESS_DENIED_IPV4);
    }
    return false;
}

function _stattic_egress_ip_outside_denylist(string $packed, array $deniedCidrs): bool
{
    return !array_any($deniedCidrs, static function (string $cidr) use ($packed): bool {
        [$network, $bits] = explode('/', $cidr, 2);
        return _stattic_egress_ip_in_cidr($packed, (string) inet_pton($network), (int) $bits);
    });
}

function _stattic_egress_ip_in_cidr(string $packedIp, string $packedNetwork, int $bits): bool
{
    if (strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
        return false;
    }
    $remainder = $bits % 8;
    if ($remainder === 0) {
        return true;
    }
    $mask = 0xff << (8 - $remainder) & 0xff;
    return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
}

// Shared by the compile-time writer (admin/generate.php) and the serve-time
// enforcer (runtime/proxy.php) so acceptance and enforcement cannot drift.
function _stattic_egress_proxy_policy_shape_valid(array $route): bool
{
    if (!is_string($route['upstream'] ?? null) || $route['upstream'] === '') {
        return false;
    }
    if (!is_string($route['target_prefix'] ?? null) || $route['target_prefix'] === '' || $route['target_prefix'][0] !== '/') {
        return false;
    }
    if (!is_array($route['methods'] ?? null) || $route['methods'] === []) {
        return false;
    }
    foreach ($route['methods'] as $method) {
        if (!_stattic_egress_proxy_method_valid($method)) {
            return false;
        }
    }
    if (!is_array($route['headers'] ?? null)) {
        return false;
    }
    foreach ($route['headers'] as $name => $value) {
        if (!is_string($name) || !is_string($value) || !_stattic_egress_proxy_header_name_valid($name)) {
            return false;
        }
    }
    if (!is_array($route['forwardHeaders'] ?? null)) {
        return false;
    }
    foreach ($route['forwardHeaders'] as $name) {
        if (!is_string($name) || !_stattic_egress_proxy_header_name_valid($name)) {
            return false;
        }
    }
    if (array_key_exists('cache', $route) && !in_array($route['cache'], [null, 'shared'], true)) {
        return false;
    }

    return _stattic_egress_int_range($route['bodySizeLimitBytes'] ?? null, 0, 10485760)
        && _stattic_egress_int_range($route['timeoutSeconds'] ?? null, 1, 60)
        && _stattic_egress_int_range($route['connectTimeoutSeconds'] ?? null, 1, 10);
}

function _stattic_egress_proxy_method_valid(mixed $method): bool
{
    return is_string($method) && in_array(strtoupper($method), STATTIC_VISITOR_METHODS, true);
}

function _stattic_egress_proxy_header_name_valid(string $name): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1;
}

function _stattic_egress_int_range(mixed $value, int $min, int $max): bool
{
    return is_int($value) && $value >= $min && $value <= $max;
}

// How long a resolved verdict may be reused. The connect-IP pin already defeats
// rebinding within one request; this caps how long the cache can mask a genuine
// DNS change, so it is deliberately a few seconds, not minutes.
const STATTIC_EGRESS_RESOLVE_TTL_SECONDS = 5;

// Callers must connect only to the returned addresses (CURLOPT_RESOLVE), which
// defeats DNS rebinding between validation and connect. Null when resolution
// fails or ANY resolved address is non-public.
//
// Memoized per (host,port) within the request only, the whole verdict including
// the "any non-public => null" decision. Deliberately no cross-process cache: a
// shared entry would let one worker's transient resolver failure deny every
// request on the box for the TTL.
function _stattic_egress_resolve_public_ips(string $host, ?int $port = null): ?array
{
    static $memo = [];
    $key = strtolower(trim($host, '[]')) . '|' . ($port ?? '');
    $now = time();
    if (isset($memo[$key]) && $memo[$key]['exp'] > $now) {
        return $memo[$key]['ips'];
    }
    $ips = _stattic_egress_resolve_public_ips_compute($host, $port);
    $memo[$key] = ['exp' => $now + STATTIC_EGRESS_RESOLVE_TTL_SECONDS, 'ips' => $ips];
    return $ips;
}

function _stattic_egress_resolve_public_ips_compute(string $host, ?int $port = null): ?array
{
    $normalized = strtolower(trim($host, '[]'));
    if (_stattic_egress_test_target_allowlisted($normalized, $port) && filter_var($normalized, FILTER_VALIDATE_IP)) {
        return [$normalized];
    }
    if (filter_var($normalized, FILTER_VALIDATE_IP)) {
        return _stattic_egress_ip_public($normalized) ? [$normalized] : null;
    }

    $ips = [];
    foreach (dns_get_record($normalized, DNS_A) ?: [] as $record) {
        if (is_array($record) && is_string($record['ip'] ?? null)) {
            $ips[] = $record['ip'];
        }
    }
    foreach (dns_get_record($normalized, DNS_AAAA) ?: [] as $record) {
        if (is_array($record) && is_string($record['ipv6'] ?? null)) {
            $ips[] = $record['ipv6'];
        }
    }
    if ($ips === []) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!_stattic_egress_ip_public($ip)) {
            return null;
        }
    }
    return array_values(array_unique($ips));
}

// Test-only escape: production does not set this process env var and a tenant
// cannot supply it; non-matching targets still face the full SSRF policy.
function _stattic_egress_test_target_allowlisted(string $host, ?int $port): bool
{
    if ($host === '' || $port === null || $port < 1 || $port > 65535) {
        return false;
    }
    $raw = getenv('SPACEFAST_EGRESS_TEST_ALLOWLIST');
    if (!is_string($raw) || trim($raw) === '') {
        return false;
    }
    $target = strtolower(trim($host, '[]')) . ':' . $port;
    return array_any(explode(',', $raw), static fn (string $entry): bool =>
        strtolower(trim($entry)) === $target);
}

function _stattic_egress_curl_resolve_entries(string $host, int $port, array $ips): array
{
    $normalized = strtolower(trim($host, '[]'));
    if (filter_var($normalized, FILTER_VALIDATE_IP) && str_contains($normalized, ':')) {
        return [];
    }
    $connectIps = array_values(array_filter(
        $ips,
        static fn (string $ip): bool => !str_contains($ip, ':'),
    ));
    if ($connectIps === []) {
        $connectIps = $ips;
    }
    $entries = [];
    foreach ($connectIps as $ip) {
        $entries[] = $normalized . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
    }
    return $entries;
}
