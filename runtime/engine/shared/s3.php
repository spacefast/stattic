<?php
declare(strict_types=1);

// Dependency-free SigV4 signer + minimal S3-compatible client for the engine
// (plan §10/§26, DECISIONS I-12/I-13). Ported from the proven HMAC-chain
// pattern in .upstream/wpcom/.../class.aws-call.php (date/region/service/
// request key derivation, credential-scope + string-to-sign shape) into the
// engine's object canonicalization needs: S3 GET/PUT/HEAD, path-style AND
// virtual-host addressing, Range/If-None-Match pass-through as signed
// headers, and a real (never UNSIGNED-PAYLOAD) payload hash on every write.
// No SDK, no presigned URLs — static keys delivered via persist-data
// (SPACEFAST_STORAGE_BUCKETS_JSON), read through _stattic_config_value()
// (context.php) exactly like every other SPACEFAST_* runtime setting.
//
// Callers (later waves): runtime/serve.php's tiered-serve branch, the
// demote/promote bulk-lane jobs, and the moves-V2 ensure-blobs/install
// steppers. This file only signs and moves bytes; it never decides
// eligibility/policy and never touches the private-storage path-safety
// helpers in shared/storage.php (kept dependency-free) — callers are
// responsible for their own destination-path safety before handing a
// dest_path to _stattic_s3_multi_get().
require_once __DIR__ . '/context.php';

// Every PUT path in this file computes and signs the real payload hash
// (DECISIONS I-12: "PUT always signs the real payload hash; never
// UNSIGNED-PAYLOAD on writes") — never the S3 dialect's UNSIGNED-PAYLOAD sentinel.
// hash('sha256', '') — literal so no function call is needed at const-eval time.
const STATTIC_S3_EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
const STATTIC_S3_CONNECT_TIMEOUT_SECONDS = 10;
const STATTIC_S3_TOTAL_TIMEOUT_SECONDS = 30;
const STATTIC_S3_DEFAULT_PARALLEL_STREAMS = 4;

// ---------------------------------------------------------------------------
// Bucket registry / manifest (DECISIONS I-13)
// ---------------------------------------------------------------------------

// SPACEFAST_STORAGE_BUCKETS_JSON: array of
// {id, endpoint, region, bucket, urlStyle, getKeyId, getKeySecret, putKeyId,
//  putKeySecret, integrity}. Cached per-process; pass $forceReload to re-read
// after a stale-key failure (the manifest may have rotated).
function _stattic_s3_bucket_manifest(bool $forceReload = false): array
{
    static $cached = null;
    if ($cached !== null && !$forceReload) {
        return $cached;
    }

    $raw = _stattic_config_value('SPACEFAST_STORAGE_BUCKETS_JSON');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    $rows = [];
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                $rows[$row['id']] = $row;
            }
        }
    }
    $cached = $rows;
    return $rows;
}

function _stattic_s3_bucket_row(string $bucketId, bool $forceReload = false): ?array
{
    if ($bucketId === '') {
        return null;
    }
    $manifest = _stattic_s3_bucket_manifest($forceReload);
    return $manifest[$bucketId] ?? null;
}

// Two credentials per bucket (DECISIONS I-13): a GET-only key for the serve
// path, a PUT-capable key for the demote/transfer lane. $mode is 'get'|'put'.
function _stattic_s3_credentials(array $bucketRow, string $mode): array
{
    if ($mode === 'put') {
        return [
            'key' => trim((string) ($bucketRow['putKeyId'] ?? '')),
            'secret' => trim((string) ($bucketRow['putKeySecret'] ?? '')),
        ];
    }
    return [
        'key' => trim((string) ($bucketRow['getKeyId'] ?? '')),
        'secret' => trim((string) ($bucketRow['getKeySecret'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// URL / canonicalization
// ---------------------------------------------------------------------------

function _stattic_s3_uri_encode(string $value): string
{
    // rawurlencode() already leaves unreserved chars (A-Za-z0-9-_.~) intact,
    // matching AWS's URI-encode rule (RFC 3986); no further fixup needed.
    return rawurlencode($value);
}

// Encodes an object key as a canonical request path: each '/'-delimited
// segment is percent-encoded independently, the '/' separators are kept
// literal (S3 object keys are themselves path-shaped).
function _stattic_s3_canonical_object_path(string $key): string
{
    $key = ltrim($key, '/');
    if ($key === '') {
        return '/';
    }
    $segments = array_map('_stattic_s3_uri_encode', explode('/', $key));
    return '/' . implode('/', $segments);
}

// Builds the request URL/host/path for one bucket + key, honoring the
// registry's urlStyle ('vhost' or 'path'; anything else falls back to path
// style, the safer default — every provider supports it).
function _stattic_s3_locator(array $bucketRow, string $key): ?array
{
    $endpoint = trim((string) ($bucketRow['endpoint'] ?? ''));
    $bucket = trim((string) ($bucketRow['bucket'] ?? ''));
    if ($endpoint === '' || $bucket === '') {
        return null;
    }
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $scheme = $parts['scheme'] ?? 'https';
    $endpointHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $objectPath = _stattic_s3_canonical_object_path($key);

    if (($bucketRow['urlStyle'] ?? 'path') === 'vhost') {
        $host = $bucket . '.' . $endpointHost;
        $path = $objectPath;
    } else {
        $host = $endpointHost;
        $path = '/' . $bucket . $objectPath;
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'path' => $path,
        'url' => $scheme . '://' . $host . $path,
    ];
}

// Sorted, lowercase "name:value\n" canonical header block + the matching
// ';'-joined SignedHeaders list (SigV4 canonicalization).
function _stattic_s3_canonical_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower(trim((string) $name))] = trim((string) $value);
    }
    ksort($normalized, SORT_STRING);

    $canonical = '';
    foreach ($normalized as $name => $value) {
        $canonical .= $name . ':' . $value . "\n";
    }
    return [$canonical, implode(';', array_keys($normalized))];
}

// Readable "Name-Value" header casing for the actual HTTP wire (case is
// cosmetic — HTTP header names are case-insensitive — but this keeps
// CURLOPT_HTTPHEADER lines matching AWS's own convention).
function _stattic_s3_header_lines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $display = implode('-', array_map('ucfirst', explode('-', strtolower((string) $name))));
        $lines[] = $display . ': ' . $value;
    }
    return $lines;
}

// ---------------------------------------------------------------------------
// Signing (HMAC-chain derivation ported from the wpcom AWS_Call pattern)
// ---------------------------------------------------------------------------

function _stattic_s3_signing_key(string $secretAccessKey, string $dateStamp, string $region, string $service): string
{
    $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretAccessKey, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
    return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
}

// Returns the full header set to send on the wire: $extraHeaders plus Host,
// X-Amz-Date, X-Amz-Content-Sha256, and Authorization. $payloadHash must be
// the real body hash for writes (never the S3 dialect's UNSIGNED-PAYLOAD sentinel).
function _stattic_s3_sign(
    array $bucketRow,
    array $credentials,
    string $method,
    string $host,
    string $path,
    array $extraHeaders,
    string $payloadHash
): array {
    $region = trim((string) ($bucketRow['region'] ?? '')) ?: 'us-east-1';
    $service = 's3';
    $timestamp = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');

    $headers = array_merge($extraHeaders, [
        'host' => $host,
        'x-amz-date' => $timestamp,
        'x-amz-content-sha256' => $payloadHash,
    ]);

    [$canonicalHeaders, $signedHeaders] = _stattic_s3_canonical_headers($headers);
    $canonicalRequest = implode("\n", [
        strtoupper($method),
        $path,
        // No signed request carries a query string; the runtime addresses S3
        // objects by path only.
        '',
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $timestamp,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $signingKey = _stattic_s3_signing_key($credentials['secret'], $dateStamp, $region, $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $headers['authorization'] = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $credentials['key'] . '/' . $credentialScope . ', '
        . 'SignedHeaders=' . $signedHeaders . ', '
        . 'Signature=' . $signature;

    return $headers;
}

// ---------------------------------------------------------------------------
// Transport primitives
// ---------------------------------------------------------------------------

// One curl handle reused across calls within the current process instead of
// curl_init()/curl_close() per request: libcurl's connection cache is tied
// to the handle, so successive calls in the same script run (breaker
// retries, sequential ops) can reuse a warm connection. PHP-FPM tears down
// static state between requests like any other request-scoped globals, so
// this does not persist a connection *across* separate HTTP requests to the
// engine — it is "one handle for the life of the current request/script",
// which is what ext-curl can actually offer; there is no persistent-curl API.
function _stattic_s3_curl_handle(): \CurlHandle
{
    static $handle = null;
    if ($handle === null) {
        $handle = curl_init();
    } else {
        curl_reset($handle);
    }
    return $handle;
}

function _stattic_s3_header_collector(array &$headers): callable
{
    return static function ($ch, string $line) use (&$headers): int {
        $trimmed = rtrim($line, "\r\n");
        if ($trimmed !== '' && str_contains($trimmed, ':')) {
            [$name, $value] = explode(':', $trimmed, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return strlen($line);
    };
}

function _stattic_s3_payload_hash(string $body): string
{
    return hash('sha256', $body);
}

// THE credential/locator gate in front of every signed request: a bucket row
// missing either half of its $mode credential pair can only produce an
// unsignable request, so it fails closed here rather than at the provider.
// Returns ['credentials' => array, 'locator' => array], or
// ['error' => 's3_credentials_missing'|'s3_bucket_config_invalid'] — callers
// map that onto their own failure shape.
function _stattic_s3_prepare(array $bucketRow, string $mode, string $key): array
{
    $credentials = _stattic_s3_credentials($bucketRow, $mode);
    if ($credentials['key'] === '' || $credentials['secret'] === '') {
        return ['error' => 's3_credentials_missing'];
    }
    $locator = _stattic_s3_locator($bucketRow, $key);
    if ($locator === null) {
        return ['error' => 's3_bucket_config_invalid'];
    }
    return ['credentials' => $credentials, 'locator' => $locator];
}

// Buffered small-op request: GET (small bodies)/PUT/HEAD. $options:
//   body            (PUT) request body — hashed for real (never unsigned).
//   content_type    (PUT) optional Content-Type header.
//   range           signed Range header pass-through.
//   if_none_match   signed If-None-Match header pass-through.
//   resolve         optional list of "host:port:ip" CURLOPT_RESOLVE entries
//                    (pins a hostname to an IP without touching system DNS —
//                    used by the fixture test harness for vhost-style
//                    addressing against a loopback fake; also useful
//                    operationally to bypass DNS during an incident).
function _stattic_s3_request(array $bucketRow, string $mode, string $method, string $key, array $options = []): array
{
    $prepared = _stattic_s3_prepare($bucketRow, $mode, $key);
    if (isset($prepared['error'])) {
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => $prepared['error']];
    }
    ['credentials' => $credentials, 'locator' => $locator] = $prepared;

    $method = strtoupper($method);
    $body = (string) ($options['body'] ?? '');
    $payloadHash = $method === 'PUT'
        ? _stattic_s3_payload_hash($body)
        : STATTIC_S3_EMPTY_PAYLOAD_SHA256;

    $extraHeaders = [];
    if (isset($options['range']) && is_string($options['range']) && $options['range'] !== '') {
        $extraHeaders['range'] = $options['range'];
    }
    if (isset($options['if_none_match']) && is_string($options['if_none_match']) && $options['if_none_match'] !== '') {
        $extraHeaders['if-none-match'] = $options['if_none_match'];
    }
    if ($method === 'PUT') {
        $extraHeaders['content-length'] = (string) strlen($body);
        if (isset($options['content_type']) && is_string($options['content_type']) && $options['content_type'] !== '') {
            $extraHeaders['content-type'] = $options['content_type'];
        }
    }

    $signedHeaders = _stattic_s3_sign($bucketRow, $credentials, $method, $locator['host'], $locator['path'], $extraHeaders, $payloadHash);

    $ch = _stattic_s3_curl_handle();
    $curlOptions = [
        CURLOPT_URL => $locator['url'],
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => _stattic_s3_header_lines($signedHeaders),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
    ];
    if ($method === 'PUT') {
        $curlOptions[CURLOPT_POSTFIELDS] = $body;
    }
    if (isset($options['resolve']) && is_array($options['resolve']) && $options['resolve'] !== []) {
        $curlOptions[CURLOPT_RESOLVE] = array_values(array_map('strval', $options['resolve']));
    }
    curl_setopt_array($ch, $curlOptions);

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, _stattic_s3_header_collector($responseHeaders));

    $responseBody = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($responseBody === false || $errno !== 0) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => $responseHeaders,
            'body' => '',
            'error' => 's3_transport_error:' . curl_error($ch),
        ];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => null,
    ];
}

function _stattic_s3_get(array $bucketRow, string $key, array $options = []): array
{
    return _stattic_s3_request($bucketRow, 'get', 'GET', $key, $options);
}

function _stattic_s3_head(array $bucketRow, string $key, array $options = []): array
{
    return _stattic_s3_request($bucketRow, 'get', 'HEAD', $key, $options);
}

function _stattic_s3_exists(array $bucketRow, string $key, string $mode = 'get'): bool
{
    $result = _stattic_s3_request($bucketRow, $mode, 'HEAD', $key);
    return $result['ok'] && $result['status'] === 200;
}

// Looks the bucket up by id (retrying once against a freshly-read manifest
// on an auth failure — DECISIONS I-13: "stale-key failures fail closed to
// the breaker, then retry with a freshly-read manifest") before delegating
// to _stattic_s3_request(). The caller's breaker/error-surfacing decision
// still runs off this function's returned ['ok','status','error'] shape.
function _stattic_s3_request_by_bucket_id(string $bucketId, string $mode, string $method, string $key, array $options = []): array
{
    $bucketRow = _stattic_s3_bucket_row($bucketId);
    if ($bucketRow === null) {
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => 's3_bucket_unknown'];
    }
    $result = _stattic_s3_request($bucketRow, $mode, $method, $key, $options);
    if (!$result['ok'] && in_array($result['status'], [401, 403], true)) {
        $freshRow = _stattic_s3_bucket_row($bucketId, true);
        if ($freshRow !== null && $freshRow !== $bucketRow) {
            return _stattic_s3_request($freshRow, $mode, $method, $key, $options);
        }
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Streaming GET (serve-path seam consumed by a later wave's tiered-serve
// branch in runtime/serve.php — see plan §26 pseudocode). Returns a curl
// handle pre-configured for a signed streamed GET; the caller curl_exec()s
// it. $onHeaders(int $status, array $headers) fires once, after the full
// header block for the final response is parsed and before any body bytes
// arrive — this is the correct (and only) point at which the caller can
// translate the bucket's status/Content-Range into the client's HTTP
// response before streaming starts. $onChunk(string $chunk) fires per body
// chunk; returning false aborts the transfer (curl semantics: a write
// callback that doesn't report having consumed all bytes stops the
// transfer). With no callbacks the default behavior is a direct
// pass-through stream to output — never buffered. $curlOptions carries rare
// per-call curl overrides; today only 'resolve' (a list of "host:port:ip"
// CURLOPT_RESOLVE entries — see _stattic_s3_request()'s matching option for
// why this exists: pin a hostname to an IP without touching system DNS).
// ---------------------------------------------------------------------------

function _stattic_s3_open(
    array $remoteLocator,
    ?string $rangeHeader = null,
    ?callable $onHeaders = null,
    ?callable $onChunk = null,
    array $curlOptions = []
): array|false {
    $bucketId = (string) ($remoteLocator['bucket'] ?? '');
    $key = (string) ($remoteLocator['key'] ?? '');
    if ($bucketId === '' || $key === '') {
        return false;
    }

    $bucketRow = _stattic_s3_bucket_row($bucketId);
    if ($bucketRow === null) {
        return false;
    }
    $prepared = _stattic_s3_prepare($bucketRow, 'get', $key);
    if (isset($prepared['error'])) {
        return false;
    }
    ['credentials' => $credentials, 'locator' => $locator] = $prepared;

    $extraHeaders = [];
    if ($rangeHeader !== null && $rangeHeader !== '') {
        $extraHeaders['range'] = $rangeHeader;
    }
    $signedHeaders = _stattic_s3_sign(
        $bucketRow,
        $credentials,
        'GET',
        $locator['host'],
        $locator['path'],
        $extraHeaders,
        STATTIC_S3_EMPTY_PAYLOAD_SHA256
    );

    $state = (object) ['status' => null, 'headers' => []];
    $handle = _stattic_s3_curl_handle();
    $opts = [
        CURLOPT_URL => $locator['url'],
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => _stattic_s3_header_lines($signedHeaders),
        CURLOPT_CONNECTTIMEOUT => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
        CURLOPT_RETURNTRANSFER => false,
    ];
    if (isset($curlOptions['resolve']) && is_array($curlOptions['resolve']) && $curlOptions['resolve'] !== []) {
        $opts[CURLOPT_RESOLVE] = array_values(array_map('strval', $curlOptions['resolve']));
    }
    curl_setopt_array($handle, $opts + [
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state, $onHeaders): int {
            $trimmed = rtrim($line, "\r\n");
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m) === 1) {
                // A new status line (initial response, or a 100-continue
                // preamble) starts a fresh header block.
                $state->status = (int) $m[1];
                $state->headers = [];
                return strlen($line);
            }
            if ($trimmed === '') {
                // Blank line = end of the current header block. Fire once
                // per real (non-1xx) response, before any body bytes.
                if ($onHeaders !== null && $state->status !== null && $state->status >= 200) {
                    $onHeaders($state->status, $state->headers);
                }
                return strlen($line);
            }
            if (str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $state->headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($onChunk): int {
            if ($onChunk !== null) {
                return $onChunk($chunk) === false ? 0 : strlen($chunk);
            }
            echo $chunk;
            return strlen($chunk);
        },
    ]);

    return ['handle' => $handle, 'state' => $state];
}

// ---------------------------------------------------------------------------
// curl-multi parallel transfer (DECISIONS I-12: native curl_multi_*, 4
// streams default; sha256 verified via hash_update in the stream callbacks).
// ---------------------------------------------------------------------------

// Generic bounded-concurrency curl-multi driver. $jobs: list of
// ['id' => string, 'handle' => CurlHandle]. Returns a map keyed by job id of
// ['status' => int, 'error' => ?string] (transport-level; HTTP status only,
// callers apply their own 2xx/expected-status check).
function _stattic_s3_multi_run(array $jobs, int $streams = STATTIC_S3_DEFAULT_PARALLEL_STREAMS): array
{
    $streams = max(1, $streams);
    $multi = curl_multi_init();
    $queue = $jobs;
    $active = [];
    $results = [];

    $fill = static function () use (&$queue, &$active, $multi, $streams): void {
        while (count($active) < $streams && $queue !== []) {
            $job = array_shift($queue);
            curl_multi_add_handle($multi, $job['handle']);
            $active[spl_object_id($job['handle'])] = $job;
        }
    };
    $fill();

    $running = 0;
    do {
        do {
            $execStatus = curl_multi_exec($multi, $running);
        } while ($execStatus === CURLM_CALL_MULTI_PERFORM);
        if ($active !== [] && curl_multi_select($multi, 1.0) === -1) {
            usleep(10_000);
        }
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $id = spl_object_id($handle);
            $job = $active[$id] ?? null;
            unset($active[$id]);
            curl_multi_remove_handle($multi, $handle);
            if ($job !== null) {
                $result = (int) $info['result'];
                $results[$job['id']] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'error' => $result === CURLE_OK ? null : curl_strerror($result),
                ];
            }
            // No curl_close(): deprecated since PHP 8.5 and a no-op since 8.0
            // — CurlHandle objects since PHP 8.0 are freed by normal GC once
            // curl_multi_remove_handle() drops the multi handle's reference.
            $fill();
        }
    } while ($running > 0 || $active !== [] || $queue !== []);

    curl_multi_close($multi);
    return $results;
}

// Parallel GET into local files, sha256-verified on stream (single pass:
// hash_update happens in the same write callback that pipes bytes to disk,
// never a second buffered read). $items: list of
//   {id?, bucket, key, dest_path, sha256?}   -- sha256 is the expected digest
//                                                (known ahead of time: blobs
//                                                are content-addressed by
//                                                their key), checked after
//                                                the transfer completes.
// Writes to "{dest_path}.part" and renames into place only on a verified,
// 2xx-status transfer; leaves no partial file behind on any failure.
// Returns a map keyed by item id: {ok, status, error, sha256, bytes}.
function _stattic_s3_multi_get(array $items, int $streams = STATTIC_S3_DEFAULT_PARALLEL_STREAMS): array
{
    $jobs = [];
    $contexts = [];

    foreach ($items as $item) {
        $itemId = (string) ($item['id'] ?? $item['key'] ?? bin2hex(random_bytes(6)));
        $bucketRow = _stattic_s3_bucket_row((string) ($item['bucket'] ?? ''));
        $key = (string) ($item['key'] ?? '');
        $destPath = (string) ($item['dest_path'] ?? '');

        if ($bucketRow === null || $key === '' || $destPath === '') {
            $contexts[$itemId] = ['error' => 's3_invalid_item'];
            continue;
        }
        $prepared = _stattic_s3_prepare($bucketRow, 'get', $key);
        if (isset($prepared['error'])) {
            $contexts[$itemId] = ['error' => $prepared['error']];
            continue;
        }
        ['credentials' => $credentials, 'locator' => $locator] = $prepared;
        $fh = @fopen($destPath . '.part', 'wb');
        if ($fh === false) {
            $contexts[$itemId] = ['error' => 's3_dest_open_failed'];
            continue;
        }

        $signedHeaders = _stattic_s3_sign($bucketRow, $credentials, 'GET', $locator['host'], $locator['path'], [], STATTIC_S3_EMPTY_PAYLOAD_SHA256);
        $hashContext = hash_init('sha256');
        $state = (object) ['bytes' => 0];

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $locator['url'],
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => _stattic_s3_header_lines($signedHeaders),
            CURLOPT_CONNECTTIMEOUT => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fh, $hashContext, $state): int {
                hash_update($hashContext, $chunk);
                $state->bytes += strlen($chunk);
                $written = fwrite($fh, $chunk);
                return $written === false ? 0 : $written;
            },
        ]);

        $jobs[] = ['id' => $itemId, 'handle' => $handle];
        $contexts[$itemId] = [
            'fh' => $fh,
            'hash' => $hashContext,
            'state' => $state,
            'dest_path' => $destPath,
            'expected_sha256' => isset($item['sha256']) && is_string($item['sha256']) && $item['sha256'] !== ''
                ? $item['sha256']
                : null,
        ];
    }

    $transferResults = _stattic_s3_multi_run($jobs, $streams);

    $results = [];
    foreach ($contexts as $itemId => $context) {
        if (isset($context['error'])) {
            $results[$itemId] = ['ok' => false, 'status' => 0, 'error' => $context['error'], 'sha256' => null, 'bytes' => 0];
            continue;
        }
        fclose($context['fh']);
        $transfer = $transferResults[$itemId] ?? ['status' => 0, 'error' => 's3_multi_missing_result'];
        $digest = hash_final($context['hash']);
        $ok = $transfer['error'] === null && $transfer['status'] >= 200 && $transfer['status'] < 300;
        $error = $transfer['error'];

        if ($ok && $context['expected_sha256'] !== null && !hash_equals($context['expected_sha256'], $digest)) {
            $ok = false;
            $error = 's3_integrity_mismatch';
        }
        if ($ok) {
            rename($context['dest_path'] . '.part', $context['dest_path']);
        } else {
            @unlink($context['dest_path'] . '.part');
        }

        $results[$itemId] = [
            'ok' => $ok,
            'status' => $transfer['status'],
            'error' => $error,
            'sha256' => $digest,
            'bytes' => $context['state']->bytes,
        ];
    }
    return $results;
}

// Parallel PUT from local files. SigV4 requires the payload hash in a signed
// header sent before the body, so (unlike the GET side) the hash cannot be
// computed in the same pass as the network write when it isn't already
// known: $items may supply 'sha256' directly (the common case — blobs are
// content-addressed, so the sha is already the source of truth for the
// filename/key) or omit it, in which case one hash_file() pass over the
// local blob computes it up front. Either way the actual upload body is
// streamed from disk via CURLOPT_UPLOAD/CURLOPT_INFILE — never buffered
// into a single in-memory string. $items: list of
//   {id?, bucket, key, source_path, sha256?, content_type?}
// Returns a map keyed by item id: {ok, status, error, sha256}.
function _stattic_s3_multi_put(array $items, int $streams = STATTIC_S3_DEFAULT_PARALLEL_STREAMS): array
{
    $jobs = [];
    $contexts = [];

    foreach ($items as $item) {
        $itemId = (string) ($item['id'] ?? $item['key'] ?? bin2hex(random_bytes(6)));
        $bucketRow = _stattic_s3_bucket_row((string) ($item['bucket'] ?? ''));
        $key = (string) ($item['key'] ?? '');
        $sourcePath = (string) ($item['source_path'] ?? '');

        if ($bucketRow === null || $key === '' || $sourcePath === '' || !is_file($sourcePath)) {
            $contexts[$itemId] = ['error' => 's3_invalid_item'];
            continue;
        }
        $prepared = _stattic_s3_prepare($bucketRow, 'put', $key);
        if (isset($prepared['error'])) {
            $contexts[$itemId] = ['error' => $prepared['error']];
            continue;
        }
        ['credentials' => $credentials, 'locator' => $locator] = $prepared;

        $size = filesize($sourcePath);
        $payloadHash = isset($item['sha256']) && is_string($item['sha256']) && $item['sha256'] !== ''
            ? $item['sha256']
            : hash_file('sha256', $sourcePath);
        if ($size === false || $payloadHash === false) {
            $contexts[$itemId] = ['error' => 's3_source_unreadable'];
            continue;
        }
        $fh = @fopen($sourcePath, 'rb');
        if ($fh === false) {
            $contexts[$itemId] = ['error' => 's3_source_open_failed'];
            continue;
        }

        $extraHeaders = ['content-length' => (string) $size];
        if (isset($item['content_type']) && is_string($item['content_type']) && $item['content_type'] !== '') {
            $extraHeaders['content-type'] = $item['content_type'];
        }
        $signedHeaders = _stattic_s3_sign($bucketRow, $credentials, 'PUT', $locator['host'], $locator['path'], $extraHeaders, $payloadHash);

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $locator['url'],
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => _stattic_s3_header_lines($signedHeaders),
            CURLOPT_CONNECTTIMEOUT => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
        ]);

        $jobs[] = ['id' => $itemId, 'handle' => $handle];
        $contexts[$itemId] = ['fh' => $fh, 'sha256' => $payloadHash];
    }

    $transferResults = _stattic_s3_multi_run($jobs, $streams);

    $results = [];
    foreach ($contexts as $itemId => $context) {
        if (isset($context['error'])) {
            $results[$itemId] = ['ok' => false, 'status' => 0, 'error' => $context['error'], 'sha256' => null];
            continue;
        }
        fclose($context['fh']);
        $transfer = $transferResults[$itemId] ?? ['status' => 0, 'error' => 's3_multi_missing_result'];
        $ok = $transfer['error'] === null && $transfer['status'] >= 200 && $transfer['status'] < 300;
        $results[$itemId] = [
            'ok' => $ok,
            'status' => $transfer['status'],
            'error' => $transfer['error'],
            'sha256' => $context['sha256'],
        ];
    }
    return $results;
}
