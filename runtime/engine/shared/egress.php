<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

// Proxy egress policy: one invariant for all proxy surfaces (_redirects 200 external
// proxy, domain proxy bindings, and runtime proxy route actions). Upstreams must never
// reach loopback, link-local, cloud-metadata, RFC1918/ULA/private ranges, or
// Spacefast-internal hosts. The denylist is generic infrastructure policy and carries no
// tenant data.

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

function _stattic_egress_host_is_stattic_internal(string $host): bool
{
    // Spacefast-internal host patterns: runtime provider hosts, control-plane hosts,
    // this runtime's own management hostname, and the configured API host.
    foreach (['view.fast', 'atomicsites.net'] as $internal) {
        if ($host === $internal || str_ends_with($host, '.' . $internal)) {
            return true;
        }
    }
    $management = _stattic_management_hostname();
    if ($management !== '' && $host === $management) {
        return true;
    }
    $apiHost = strtolower((string) parse_url(_stattic_config_value('SPACEFAST_API_BASE_URL'), PHP_URL_HOST));
    if ($apiHost !== '' && $host === $apiHost) {
        return true;
    }
    return false;
}

function _stattic_egress_ip_public(string $ip): bool
{
    $packed = @inet_pton(strtolower(trim($ip, '[]')));
    if (!is_string($packed)) {
        return false;
    }
    if (strlen($packed) === 16) {
        // IPv4-mapped IPv6 must pass the IPv4 policy for the inner address.
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return _stattic_egress_ipv4_public(substr($packed, 12));
        }
        return _stattic_egress_ipv6_public($packed);
    }
    if (strlen($packed) === 4) {
        return _stattic_egress_ipv4_public($packed);
    }
    return false;
}

function _stattic_egress_ipv4_public(string $packed): bool
{
    foreach ([
        ['0.0.0.0', 8],        // "this network"
        ['10.0.0.0', 8],       // RFC1918
        ['100.64.0.0', 10],    // CGNAT
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local incl. cloud metadata 169.254.169.254
        ['172.16.0.0', 12],    // RFC1918
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // TEST-NET-1
        ['192.168.0.0', 16],   // RFC1918
        ['198.18.0.0', 15],    // benchmarking
        ['198.51.100.0', 24],  // TEST-NET-2
        ['203.0.113.0', 24],   // TEST-NET-3
        ['224.0.0.0', 3],      // multicast + reserved + broadcast
    ] as [$network, $bits]) {
        if (_stattic_egress_ip_in_cidr($packed, (string) inet_pton($network), $bits)) {
            return false;
        }
    }
    return true;
}

function _stattic_egress_ipv6_public(string $packed): bool
{
    foreach ([
        ['::', 96],           // unspecified + loopback + deprecated IPv4-compatible ::a.b.c.d
        ['::ffff:0:0', 96],   // IPv4-mapped (handled separately; deny raw)
        ['64:ff9b::', 96],    // NAT64 well-known prefix
        ['100::', 64],        // discard-only
        ['2001:db8::', 32],   // documentation
        ['fc00::', 7],        // ULA incl. cloud metadata fd00:ec2::254
        ['fe80::', 10],       // link-local
        ['ff00::', 8],        // multicast
    ] as [$network, $bits]) {
        if (_stattic_egress_ip_in_cidr($packed, (string) inet_pton($network), $bits)) {
            return false;
        }
    }
    return true;
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

// Proxy route policy shape — ONE validator shared by the compile-time writer
// (admin/generate.php) and the serve-time enforcer (runtime/proxy.php) so
// acceptance and enforcement cannot drift. The field limits ARE the contract:
// body <= 10 MiB, timeout 1-60s, connect timeout 1-10s, token-shaped header
// names, the fixed method allowlist.
function _stattic_egress_proxy_policy_shape_valid(array $route): bool
{
    if (!is_string($route['upstream'] ?? null) || $route['upstream'] === '') {
        return false;
    }
    if (!is_string($route['target_prefix'] ?? null) || $route['target_prefix'] === '' || $route['target_prefix'][0] !== '/') {
        return false;
    }
    if (array_key_exists('method', $route) && $route['method'] !== null && !_stattic_egress_proxy_method_valid($route['method'])) {
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
    return is_string($method) && in_array(strtoupper($method), ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true);
}

function _stattic_egress_proxy_header_name_valid(string $name): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1;
}

function _stattic_egress_int_range(mixed $value, int $min, int $max): bool
{
    return is_int($value) && $value >= $min && $value <= $max;
}

// Resolve every address for the upstream host and validate each against the public-IP
// policy. Returns the validated address list, or null when resolution fails or any
// resolved address is non-public. Callers must connect only to these pinned addresses
// (CURLOPT_RESOLVE), which defeats DNS rebinding between validation and connect.
function _stattic_egress_resolve_public_ips(string $host, ?int $port = null): ?array
{
    $normalized = strtolower(trim($host, '[]'));
    if (_stattic_egress_test_target_allowlisted($normalized, $port) && filter_var($normalized, FILTER_VALIDATE_IP)) {
        return [$normalized];
    }
    if (filter_var($normalized, FILTER_VALIDATE_IP)) {
        return _stattic_egress_ip_public($normalized) ? [$normalized] : null;
    }

    $ips = [];
    foreach (@dns_get_record($normalized, DNS_A) ?: [] as $record) {
        if (is_array($record) && is_string($record['ip'] ?? null)) {
            $ips[] = $record['ip'];
        }
    }
    foreach (@dns_get_record($normalized, DNS_AAAA) ?: [] as $record) {
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

// Exact host:port escape used only by the real-upstream PHP integration test.
// Production does not set this process env var; a tenant cannot supply it, and
// every non-matching target still passes the unchanged SSRF policy above.
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
    foreach (explode(',', $raw) as $entry) {
        if (strtolower(trim($entry)) === $target) {
            return true;
        }
    }
    return false;
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
