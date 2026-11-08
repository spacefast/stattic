<?php

declare(strict_types=1);

namespace Zero;

/**
 * `fetch(url, init?)` from "zero": outbound HTTPS from handlers through the WordPress HTTP API.
 *
 * The policy matches the original Zero runtime's tenant fetch: HTTPS only, no URL credentials, no
 * `localhost` names, and no address in a private, loopback, link-local, CGNAT, documentation,
 * multicast or other special-purpose range, for IPv4 and IPv6 (IPv4-mapped and 6to4 forms
 * unwrapped). Redirects are followed here, at most three, and every hop is resolved and checked
 * again, so a public URL cannot redirect into the network. The connection is pinned to the checked
 * address (CURLOPT_RESOLVE), so DNS cannot answer differently between the check and the connect.
 * Request and response bodies are capped at 1 MiB, and the whole call at 10 seconds.
 */
final class Fetch
{
    public const TIMEOUT = 10;
    public const MAX_BODY = 1048576;
    public const MAX_REDIRECTS = 3;
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const DROP_REQUEST = ['connection', 'content-length', 'host', 'keep-alive', 'proxy-authorization', 'proxy-connection', 'te', 'trailer', 'transfer-encoding', 'upgrade'];
    private const DROP_RESPONSE = ['connection', 'keep-alive', 'proxy-authenticate', 'set-cookie', 'set-cookie2', 'te', 'trailer', 'transfer-encoding', 'upgrade'];
    /** Hosts the platform itself serves; a Space never reaches another Space through them. */
    private const INTERNAL = ['space.fast', 'view.fast', 'atomicsites.net'];
    /** [network, prefix] pairs, from the original runtime's DENIED_IPV4_NETWORKS. */
    private const DENIED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.31.196.0', 24], ['192.52.193.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['192.175.48.0', 24], ['198.18.0.0', 15],
        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 3],
    ];
    /** From DENIED_IPV6_NETWORKS. IPv4-mapped addresses are checked as IPv4 first. */
    private const DENIED_V6 = [
        ['::', 96], ['::ffff:0:0', 96], ['::ffff:0:0:0', 96], ['64:ff9b::', 96], ['64:ff9b:1::', 48],
        ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['2620:4f:8000::', 48],
        ['3fff::', 20], ['5f00::', 16], ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8],
    ];

    /** @var null|\Closure(string): list<string> test seam for name resolution */
    public static ?\Closure $resolver = null;

    /**
     * @param array{method?: mixed, headers?: mixed, body?: mixed}|JsObject|JsUndefined|null $init
     */
    public static function call(mixed $url, mixed $init = null): JsObject
    {
        if (!is_string($url)) {
            throw new JsError('fetch() needs a URL string', 'TypeError');
        }
        $init = $init instanceof JsObject ? $init->p : (is_array($init) ? $init : []);
        $method = strtoupper(is_string($init['method'] ?? null) ? $init['method'] : 'GET');
        if (!in_array($method, self::METHODS, true)) {
            throw self::refuse('zero_fetch_method_denied', 'The fetch method is not permitted.');
        }
        $headers = self::requestHeaders($init['headers'] ?? null);
        $body = $init['body'] ?? null;
        if ($body === JsUndefined::Value) {
            $body = null;
        }
        if ($body !== null && !is_string($body)) {
            throw new JsError('fetch() body must be a string', 'TypeError');
        }
        if ($body !== null && strlen($body) > self::MAX_BODY) {
            throw self::refuse('zero_fetch_payload_too_large', 'The fetch request body is too large.');
        }
        if ($body !== null && in_array($method, ['GET', 'HEAD'], true)) {
            throw new JsError("Request with $method method cannot have body.", 'TypeError');
        }
        $deadline = microtime(true) + self::TIMEOUT;
        for ($hop = 0; ; $hop++) {
            [$host, $port, $address] = self::target($url);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw self::refuse('zero_fetch_timeout', 'The fetch target did not answer in time.');
            }
            $response = self::send($url, $host, $port, $address, $method, $headers, $body, $remaining);
            $status = $response['status'];
            $location = $response['headers']['location'] ?? null;
            if (in_array($status, [301, 302, 303, 307, 308], true) && is_string($location)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw self::refuse('zero_fetch_too_many_redirects', 'The fetch target redirected too many times.');
                }
                $url = self::resolveUrl($url, $location);
                // Like browsers: 303, and 301/302 for POST, become GET without a body.
                if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                    $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                    $body = null;
                    unset($headers['content-type']);
                }
                // Credentials never follow a redirect to another origin: scheme, host and effective
                // port (scheme is always https here, so host and port decide).
                [$nextHost, $nextPort] = self::origin($url);
                if ($nextHost !== $host || $nextPort !== $port) {
                    unset($headers['authorization'], $headers['cookie']);
                }
                continue;
            }
            return self::response($url, $response, $hop > 0);
        }
    }

    /** @return array<string, string> lowercase names */
    private static function requestHeaders(mixed $headers): array
    {
        if ($headers === null || $headers === JsUndefined::Value) {
            return [];
        }
        $headers = $headers instanceof JsObject ? $headers->p : $headers;
        if (!is_array($headers)) {
            throw new JsError('fetch() headers must be an object of strings', 'TypeError');
        }
        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (!is_string($value) || preg_match('/\A[!#$%&\'*+.^_`|~0-9a-z-]+\z/', $name) !== 1 || strpbrk($value, "\r\n\0") !== false) {
                throw new JsError('fetch() header names and values must be valid strings', 'TypeError');
            }
            if (!in_array($name, self::DROP_REQUEST, true)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return array{string, int, string} host, port and the checked address to connect to */
    public static function target(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw self::refuse('zero_fetch_scheme_denied', 'fetch() reaches HTTPS URLs only.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::refuse('zero_fetch_credentials_denied', 'fetch() URLs cannot carry credentials.');
        }
        $host = strtolower(trim($parts['host'], "[]. \t\n\r\0\x0B"));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw self::refuse('zero_fetch_host_denied', 'The fetch host is not permitted.');
        }
        foreach (self::INTERNAL as $internal) {
            if ($host === $internal || str_ends_with($host, '.' . $internal)) {
                throw self::refuse('zero_fetch_host_denied', 'The fetch host is not permitted.');
            }
        }
        $port = (int) ($parts['port'] ?? 443);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw self::refuse('zero_fetch_upstream_unavailable', 'The fetch target could not be reached.');
        }
        // Every address the name answers with must be public: a mixed answer is refused, not filtered.
        // `zero_fetch_allow_address` lets a trusted site plugin open one named internal target
        // (a test receiver, a sidecar); request input can never reach it.
        foreach ($addresses as $address) {
            $open = function_exists('apply_filters') && apply_filters('zero_fetch_allow_address', false, $address, $host, $port) === true;
            if (!$open && !self::allowed($address)) {
                throw self::refuse('zero_fetch_address_denied', 'The fetch target address is not permitted.');
            }
        }

        return [$host, $port, $addresses[0]];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        // A trusted plugin may answer for a name the site resolves privately; answers still pass
        // the address policy (and `zero_fetch_allow_address`).
        $answer = function_exists('apply_filters') ? apply_filters('zero_fetch_resolve', null, $host) : null;
        if (is_array($answer)) {
            return array_values(array_filter($answer, 'is_string'));
        }
        $out = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $out[] = $ip;
                }
            }
        }
        if ($out === []) {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $out[] = $ip;
            }
        }

        return array_values(array_unique($out));
    }

    public static function allowed(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $denied = strlen($packed) === 4 ? self::DENIED_V4 : self::DENIED_V6;
        foreach ($denied as [$network, $prefix]) {
            if (self::contains($packed, (string) inet_pton($network), $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function contains(string $address, string $network, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function send(string $url, string $host, int $port, string $address, string $method, array $headers, ?string $body, float $timeout): array
    {
        if (!function_exists('wp_remote_request')) {
            throw new \LogicException('fetch() needs WordPress loaded (wp_remote_request)');
        }
        // Pin the connection to the address checked above. Only the cURL transport can pin: any
        // other transport would resolve the name again, and could connect to a rebound address.
        $pinned = false;
        $pin = static function ($handle) use ($host, $port, $address, &$pinned): void {
            $ip = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $pinned = curl_setopt($handle, CURLOPT_RESOLVE, ["$host:$port:$ip"])
                && curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        };
        add_action('http_api_curl', $pin, PHP_INT_MAX);
        try {
            $response = wp_remote_request($url, [
                'method' => $method,
                'headers' => $headers,
                'body' => $body,
                'timeout' => max(1, (int) ceil($timeout)),
                'redirection' => 0,
                'limit_response_size' => self::MAX_BODY + 1,
                'reject_unsafe_urls' => false,
                'sslverify' => true,
                'user-agent' => 'Zero (WordPress)',
                'decompress' => true,
            ]);
        } finally {
            remove_action('http_api_curl', $pin, PHP_INT_MAX);
        }
        // Fail closed: a request the pin did not reach went out over another transport, unpinned,
        // so nothing it returned is used.
        if (!$pinned) {
            throw self::refuse('zero_fetch_upstream_unavailable', 'Outbound fetch needs the cURL transport to pin the checked address.');
        }
        if (is_wp_error($response)) {
            throw self::refuse('zero_fetch_upstream_unavailable', 'The fetch target could not be reached.');
        }
        $raw = (string) wp_remote_retrieve_body($response);
        if (strlen($raw) > self::MAX_BODY) {
            throw self::refuse('zero_fetch_response_too_large', 'The fetch response is too large.');
        }
        $out = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $name = strtolower((string) $name);
            if (!in_array($name, self::DROP_RESPONSE, true)) {
                $out[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'headers' => $out, 'body' => $raw];
    }

    /** @return array{string, int} lowercase host and effective port of an https URL */
    private static function origin(string $url): array
    {
        $parts = parse_url($url);

        return [strtolower((string) ($parts['host'] ?? '')), (int) ($parts['port'] ?? 443)];
    }

    /** A Location header resolved against the request URL as RFC 3986 §5.2 does; fragments drop. */
    public static function resolveUrl(string $base, string $location): string
    {
        $location = (string) preg_replace('/#.*\z/s', '', $location);
        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $authority = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        $basePath = $b['path'] ?? '/';
        $baseQuery = isset($b['query']) ? '?' . $b['query'] : '';
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        [$path, $query] = array_pad(explode('?', $location, 2), 2, null);
        $query = $query === null ? '' : '?' . $query;
        if ($path === '') {
            // `?page=2` keeps the whole base path; an empty reference keeps the base query too.
            return 'https://' . $authority . $basePath . ($query !== '' ? $query : $baseQuery);
        }
        $merged = str_starts_with($path, '/') ? $path : preg_replace('#/[^/]*\z#', '/', $basePath) . $path;

        return 'https://' . $authority . self::removeDots($merged) . $query;
    }

    /** RFC 3986 §5.2.4: drop `.` segments and fold `..` into their parent. */
    private static function removeDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && count($out) > 1) {
                    array_pop($out);
                }
                if ($i === count($segments) - 1) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }
        $result = implode('/', $out);

        return str_starts_with($result, '/') ? $result : '/' . $result;
    }

    /** The Response a handler sees: status, ok, url, redirected, headers.get/has, text(), json(). */
    private static function response(string $url, array $response, bool $redirected): JsObject
    {
        $headers = $response['headers'];
        $body = $response['body'];
        $status = $response['status'];

        return new JsObject([
            'status' => $status,
            'ok' => $status >= 200 && $status <= 299,
            'url' => $url,
            'redirected' => $redirected,
            'headers' => new JsObject([
                'get' => static fn ($name): ?string => $headers[strtolower((string) $name)] ?? null,
                'has' => static fn ($name): bool => isset($headers[strtolower((string) $name)]),
            ]),
            'text' => static fn (): string => $body,
            'json' => static fn (): mixed => Js::jsonParse($body),
        ]);
    }

    private static function refuse(string $code, string $message): HttpError
    {
        return new HttpError(502, $code, $message);
    }
}
