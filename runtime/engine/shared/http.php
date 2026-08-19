<?php
declare(strict_types=1);

// Every outbound request the engine makes is opened, bounded, run and
// classified here. Redirects default to never: libcurl and PHP's http stream
// wrapper both replay an Authorization header to whatever host a redirect
// names, and several callers here carry long-lived bearer credentials.

const STATTIC_HTTP_CONNECT_TIMEOUT_SECONDS = 10;
const STATTIC_HTTP_TIMEOUT_SECONDS = 30;

function _stattic_http_available(): bool
{
    return function_exists('curl_init');
}

// One handle per process, so libcurl's handle-scoped connection cache survives
// across calls. Sharing is safe only because a request is opened, run and
// classified inside _stattic_http_request(): no caller can hold this handle
// across another call's curl_exec().
function _stattic_http_handle(): \CurlHandle
{
    static $handle = null;
    if ($handle === null) {
        $handle = curl_init();
    } else {
        curl_reset($handle);
    }
    return $handle;
}

function _stattic_http_scheme_mask(array $schemes): int
{
    $mask = 0;
    foreach ($schemes as $scheme) {
        $mask |= match (strtolower(trim((string) $scheme))) {
            'https' => CURLPROTO_HTTPS,
            'http' => CURLPROTO_HTTP,
            default => 0,
        };
    }
    return $mask;
}

// Accepts either a name => value map or a list of ready "Name: value" lines.
function _stattic_http_header_lines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = is_int($name) ? (string) $value : $name . ': ' . $value;
    }
    return $lines;
}

// Last value wins, matching how a single-valued header is read.
function _stattic_http_header_map(array $headerPairs): array
{
    $map = [];
    foreach ($headerPairs as [$name, $value]) {
        $map[strtolower($name)] = $value;
    }
    return $map;
}

function _stattic_http_failure(string $error): array
{
    return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => $error];
}

// $request: url, method, headers, body|body_stream+body_size, connect_timeout,
// timeout, connect_timeout_ms, timeout_ms, redirects (0 = never follow), schemes, resolve (CURLOPT_RESOLVE
// pins), max_body_bytes, sink ('buffer'|'output'|callable) and on_headers.
// on_headers(int $status, array $headerPairs) fires once per non-1xx header
// block, before any body byte; returning false aborts the transfer, as does a
// sink callable returning false.
function _stattic_http_configure(\CurlHandle $handle, array $request, object $state): ?string
{
    $url = (string) ($request['url'] ?? '');
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        return 'http_url_invalid';
    }
    $schemes = is_array($request['schemes'] ?? null) && $request['schemes'] !== []
        ? $request['schemes']
        : ['https'];
    $mask = _stattic_http_scheme_mask($schemes);
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    if ($mask === 0 || !in_array($scheme, array_map(static fn ($s): string => strtolower(trim((string) $s)), $schemes), true)) {
        return 'http_scheme_not_allowed';
    }

    $method = strtoupper((string) ($request['method'] ?? 'GET'));
    $redirects = max(0, (int) ($request['redirects'] ?? 0));
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => _stattic_http_header_lines(is_array($request['headers'] ?? null) ? $request['headers'] : []),
        CURLOPT_CONNECTTIMEOUT => (int) ($request['connect_timeout'] ?? STATTIC_HTTP_CONNECT_TIMEOUT_SECONDS),
        CURLOPT_TIMEOUT => (int) ($request['timeout'] ?? STATTIC_HTTP_TIMEOUT_SECONDS),
        CURLOPT_FOLLOWLOCATION => $redirects > 0,
        CURLOPT_MAXREDIRS => $redirects,
        CURLOPT_PROTOCOLS => $mask,
        CURLOPT_REDIR_PROTOCOLS => $mask,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
    ];
    if (array_key_exists('connect_timeout_ms', $request)) {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = max(1, (int) $request['connect_timeout_ms']);
        unset($options[CURLOPT_CONNECTTIMEOUT]);
    }
    if (array_key_exists('timeout_ms', $request)) {
        $options[CURLOPT_TIMEOUT_MS] = max(1, (int) $request['timeout_ms']);
        unset($options[CURLOPT_TIMEOUT]);
    }

    if (isset($request['body_stream']) && is_resource($request['body_stream'])) {
        $options[CURLOPT_UPLOAD] = true;
        $options[CURLOPT_INFILE] = $request['body_stream'];
        if (isset($request['body_size'])) {
            $options[CURLOPT_INFILESIZE] = (int) $request['body_size'];
        }
    } elseif (array_key_exists('body', $request) && is_string($request['body'])) {
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }

    $resolve = is_array($request['resolve'] ?? null) ? array_values(array_map('strval', $request['resolve'])) : [];
    if ($resolve !== []) {
        $options[CURLOPT_RESOLVE] = $resolve;
    }

    $onHeaders = is_callable($request['on_headers'] ?? null) ? $request['on_headers'] : null;
    $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use ($state, $onHeaders): int {
        $length = strlen($line);
        $trimmed = trim($line);
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches) === 1) {
            $state->status = (int) $matches[1];
            $state->headers = [];
            return $length;
        }
        if ($trimmed === '') {
            if ($onHeaders !== null && $state->status >= 200 && $onHeaders($state->status, $state->headers) === false) {
                $state->aborted = true;
                return 0;
            }
            return $length;
        }
        $separator = strpos($trimmed, ':');
        if ($separator === false) {
            return $length;
        }
        $state->headers[] = [trim(substr($trimmed, 0, $separator)), trim(substr($trimmed, $separator + 1))];
        return $length;
    };

    $sink = $request['sink'] ?? 'buffer';
    $maxBodyBytes = isset($request['max_body_bytes']) ? (int) $request['max_body_bytes'] : null;
    $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use ($state, $sink, $maxBodyBytes): int {
        $length = strlen($chunk);
        if (is_callable($sink)) {
            if ($sink($chunk) === false) {
                $state->aborted = true;
                return 0;
            }
            return $length;
        }
        if ($sink === 'output') {
            echo $chunk;
            return $length;
        }
        if ($maxBodyBytes !== null && strlen($state->body) + $length > $maxBodyBytes) {
            $state->aborted = true;
            return 0;
        }
        $state->body .= $chunk;
        return $length;
    };

    return curl_setopt_array($handle, $options) ? null : 'http_transport_unconfigurable';
}

function _stattic_http_state(): object
{
    return (object) ['status' => 0, 'headers' => [], 'body' => '', 'aborted' => false];
}

// Returns ['ok', 'status', 'headers' (ordered [name, value] pairs), 'body',
// 'error']. 'ok' means a 2xx arrived intact; a non-2xx is a successful
// transport with error null, so callers classify status themselves.
function _stattic_http_request(array $request): array
{
    if (!_stattic_http_available()) {
        return _stattic_http_failure('http_transport_unavailable');
    }
    $state = _stattic_http_state();
    $handle = _stattic_http_handle();
    $configured = _stattic_http_configure($handle, $request, $state);
    if ($configured !== null) {
        return _stattic_http_failure($configured);
    }

    $executed = curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($executed === false || $errno !== 0) {
        return [
            'ok' => false,
            'status' => $status,
            'headers' => $state->headers,
            'body' => $state->body,
            'error' => $state->aborted ? 'http_response_rejected' : (curl_error($handle) ?: 'http_transport_failed'),
        ];
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'headers' => $state->headers,
        'body' => $state->body,
        'error' => null,
    ];
}

// A handle configured by the same policy record but not run: the S3 batch lane
// drives many of these through one curl_multi loop.
function _stattic_http_prepare(array $request): array|false
{
    if (!_stattic_http_available()) {
        return false;
    }
    $handle = curl_init();
    if ($handle === false) {
        return false;
    }
    $state = _stattic_http_state();
    if (_stattic_http_configure($handle, $request, $state) !== null) {
        return false;
    }
    return ['handle' => $handle, 'state' => $state];
}
