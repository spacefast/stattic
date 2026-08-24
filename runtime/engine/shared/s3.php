<?php
declare(strict_types=1);

// Dependency-free SigV4 signer + minimal S3 client. Deliberately does not depend
// on shared/storage.php: callers own destination-path safety.
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/streaming.php';
require_once __DIR__ . '/http.php';

// hash('sha256', ''), literal for const-eval. A PUT always signs the real payload
// hash, never the UNSIGNED-PAYLOAD sentinel.
const STATTIC_S3_EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
const STATTIC_S3_CONNECT_TIMEOUT_SECONDS = 10;
const STATTIC_S3_TOTAL_TIMEOUT_SECONDS = 30;
const STATTIC_S3_DEFAULT_PARALLEL_STREAMS = 4;
const STATTIC_S3_LIST_PAGE_SIZE = 1000;
// Reclamation is best-effort: a bucket outliving a deleted space costs storage,
// not correctness. Bound the walk rather than hold a request open indefinitely.
const STATTIC_S3_DELETE_PARALLEL_STREAMS = 8;
const STATTIC_S3_DELETE_PREFIX_MAX_OBJECTS = 20000;

// SPACEFAST_STORAGE_BUCKETS_JSON rows:
// {id, endpoint, region, bucket, urlStyle, getKeyId, getKeySecret, putKeyId,
//  putKeySecret, integrity}. $forceReload re-reads after a stale-key failure.
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

// The control plane pushes exactly one active bucket per site;
// SPACEFAST_STORAGE_BUCKET_ID pins a choice if a site is ever handed more.
function _stattic_s3_default_bucket_id(): ?string
{
    $pinned = _stattic_config_value('SPACEFAST_STORAGE_BUCKET_ID');
    if ($pinned !== '') {
        return _stattic_s3_bucket_row($pinned) === null ? null : $pinned;
    }
    $manifest = _stattic_s3_bucket_manifest();
    if (count($manifest) !== 1) {
        return null;
    }
    return (string) array_key_first($manifest);
}

// The object key is derived from (space, sha) at every call site and never
// stored: not in a shard, a metadata record, or a job payload. That makes a
// space move a pure prefix operation and a stale stored key impossible.
function _stattic_s3_blob_locator(string $bucketId, string $spaceId, string $sha256): ?array
{
    $key = _stattic_blob_relative_key($spaceId, $sha256);
    return $key === null ? null : ['bucket' => $bucketId, 'key' => $key];
}

// Object size when the blob is in the bucket, null when it is not or the HEAD
// went unanswered. The demote lane's upload check for buckets whose PUTs are
// not server-verified.
function _stattic_s3_blob_head(string $spaceId, string $sha256, ?string $bucketId = null): ?int
{
    $bucketId ??= _stattic_s3_default_bucket_id();
    $key = $bucketId === null ? null : _stattic_blob_relative_key($spaceId, $sha256);
    if ($key === null) {
        return null;
    }
    $result = _stattic_s3_request_by_bucket_id((string) $bucketId, 'put', 'HEAD', $key);
    if (!$result['ok'] || (int) $result['status'] !== 200) {
        return null;
    }
    $length = $result['headers']['content-length'] ?? null;
    return is_string($length) && preg_match('/^[0-9]+$/', $length) === 1 ? (int) $length : 0;
}

// A GET-only key for the serve path, a PUT-capable key for the demote/transfer
// lane.
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

function _stattic_s3_uri_encode(string $value): string
{
    return rawurlencode($value);
}

function _stattic_s3_canonical_object_path(string $key): string
{
    $key = ltrim($key, '/');
    if ($key === '') {
        return '/';
    }
    $segments = array_map('_stattic_s3_uri_encode', explode('/', $key));
    return '/' . implode('/', $segments);
}

// urlStyle other than 'vhost' falls back to path style: every provider
// supports it.
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

// SigV4 canonical query string: keys sorted bytewise, both halves RFC3986
// encoded. Empty for every keyed object op; only the listing carries a query.
function _stattic_s3_canonical_query(array $query): string
{
    if ($query === []) {
        return '';
    }
    ksort($query, SORT_STRING);
    $pairs = [];
    foreach ($query as $name => $value) {
        $pairs[] = _stattic_s3_uri_encode((string) $name) . '=' . _stattic_s3_uri_encode((string) $value);
    }
    return implode('&', $pairs);
}

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

function _stattic_s3_header_lines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $display = implode('-', array_map('ucfirst', explode('-', strtolower((string) $name))));
        $lines[] = $display . ': ' . $value;
    }
    return $lines;
}

function _stattic_s3_signing_key(string $secretAccessKey, string $dateStamp, string $region, string $service): string
{
    static $cache = [];
    $cacheKey = $secretAccessKey . "\0" . $dateStamp . "\0" . $region . "\0" . $service;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretAccessKey, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
    return $cache[$cacheKey] = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
}

// $payloadHash must be the real body hash for writes, never UNSIGNED-PAYLOAD.
function _stattic_s3_sign(
    array $bucketRow,
    array $credentials,
    string $method,
    string $host,
    string $path,
    array $extraHeaders,
    string $payloadHash,
    string $canonicalQuery = ''
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
        $canonicalQuery,
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

function _stattic_s3_payload_hash(string $body): string
{
    return hash('sha256', $body);
}

// Fails closed before the wire: an incomplete credential pair cannot sign a
// request. Returns ['credentials', 'locator'] or
// ['error' => 's3_credentials_missing'|'s3_bucket_config_invalid'].
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

// A signed request as a transport policy record. $options adds resolve (a list
// of "host:port:ip" CURLOPT_RESOLVE pins), sink and on_headers.
function _stattic_s3_transport_request(
    array $bucketRow,
    array $credentials,
    array $locator,
    string $method,
    array $extraHeaders,
    string $payloadHash,
    array $options = []
): array {
    $canonicalQuery = _stattic_s3_canonical_query(
        isset($options['query']) && is_array($options['query']) ? $options['query'] : []
    );
    $signedHeaders = _stattic_s3_sign(
        $bucketRow,
        $credentials,
        $method,
        $locator['host'],
        $locator['path'],
        $extraHeaders,
        $payloadHash,
        $canonicalQuery
    );
    $request = [
        'url' => $locator['url'] . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery),
        'method' => $method,
        'headers' => _stattic_s3_header_lines($signedHeaders),
        'connect_timeout' => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
        'timeout' => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
        'schemes' => [$locator['scheme']],
    ];
    foreach (['resolve', 'sink', 'on_headers', 'body', 'body_stream', 'body_size'] as $field) {
        if (isset($options[$field])) {
            $request[$field] = $options[$field];
        }
    }
    return $request;
}

function _stattic_s3_signed_headers(string $method, string $body, array $options): array
{
    $headers = [];
    if (isset($options['range']) && is_string($options['range']) && $options['range'] !== '') {
        $headers['range'] = $options['range'];
    }
    if (isset($options['if_none_match']) && is_string($options['if_none_match']) && $options['if_none_match'] !== '') {
        $headers['if-none-match'] = $options['if_none_match'];
    }
    if ($method === 'PUT') {
        $headers['content-length'] = (string) strlen($body);
        if (isset($options['content_type']) && is_string($options['content_type']) && $options['content_type'] !== '') {
            $headers['content-type'] = $options['content_type'];
        }
    }
    return $headers;
}

// Buffered small-op request (GET/PUT/HEAD). $options: body, content_type,
// range, if_none_match, resolve.
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

    $transportOptions = [
        'resolve' => $options['resolve'] ?? [],
        'query' => $options['query'] ?? [],
    ];
    if ($method === 'PUT') {
        $transportOptions['body'] = $body;
    }
    $result = _stattic_http_request(_stattic_s3_transport_request(
        $bucketRow,
        $credentials,
        $locator,
        $method,
        _stattic_s3_signed_headers($method, $body, $options),
        $payloadHash,
        $transportOptions
    ));

    return [
        'ok' => $result['ok'],
        'status' => $result['status'],
        'headers' => _stattic_http_header_map($result['headers']),
        'body' => $result['body'],
        'error' => $result['error'] === null ? null : 's3_transport_error:' . $result['error'],
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

/**
 * One ListObjectsV2 page under $prefix. No continuation token: the only caller
 * deletes every key it is handed, so the next unpaged listing resumes where
 * this one stopped.
 *
 * The response is read with a regex, not an XML parser: this client is
 * dependency-free by contract and only ever lists derived
 * `spaces/<id>/blobs/<aa>/<sha>` keys. Entities are still decoded so a bucket
 * holding foreign objects cannot hand back a mangled key.
 *
 * @return list<string>|null null on any failure.
 */
function _stattic_s3_list_prefix(string $bucketId, string $prefix, array $options = []): ?array
{
    $result = _stattic_s3_request_by_bucket_id($bucketId, 'put', 'GET', '', [
        'query' => [
            'list-type' => '2',
            'prefix' => $prefix,
            'max-keys' => (string) STATTIC_S3_LIST_PAGE_SIZE,
        ],
    ] + $options);
    if (!$result['ok'] || (int) $result['status'] !== 200) {
        return null;
    }
    $keys = [];
    if (preg_match_all('#<Key>(.*?)</Key>#s', $result['body'], $matches) > 0) {
        foreach ($matches[1] as $key) {
            $keys[] = html_entity_decode($key, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return $keys;
}

/**
 * Best-effort removal of everything under $prefix, one listing page at a time.
 * Deletions within a page run in parallel: a space's prefix is thousands of
 * objects and serial round trips would never finish inside a request.
 *
 * `complete` false means the walk stopped early on a listing failure, a
 * deletion failure, or the object ceiling. The caller must report the remainder
 * as orphans it did not reclaim.
 *
 * @return array{deleted: int, complete: bool}
 */
function _stattic_s3_delete_prefix(
    string $bucketId,
    string $prefix,
    int $maxObjects = STATTIC_S3_DELETE_PREFIX_MAX_OBJECTS,
    array $options = []
): array {
    $deleted = 0;
    while ($deleted < $maxObjects) {
        $keys = _stattic_s3_list_prefix($bucketId, $prefix, $options);
        if ($keys === null) {
            return ['deleted' => $deleted, 'complete' => false];
        }
        if ($keys === []) {
            return ['deleted' => $deleted, 'complete' => true];
        }
        $keys = array_slice($keys, 0, $maxObjects - $deleted);
        $results = _stattic_s3_multi_delete($bucketId, $keys, $options);
        foreach ($keys as $key) {
            if (($results[$key] ?? false) !== true) {
                return ['deleted' => $deleted, 'complete' => false];
            }
            $deleted += 1;
        }
    }
    // The ceiling stopped the walk with the prefix unproven-empty.
    return ['deleted' => $deleted, 'complete' => false];
}

/**
 * Parallel signed DELETEs, mirroring _stattic_s3_multi_put's shape. Signed with
 * the write credential; the get key is deliberately read-only. S3 DELETE is
 * idempotent, so true means "the key is not in the bucket", never "I removed
 * it".
 *
 * @param list<string> $keys
 * @return array<string,bool> key => gone
 */
function _stattic_s3_multi_delete(string $bucketId, array $keys, array $options = []): array
{
    $bucketRow = _stattic_s3_bucket_row($bucketId);
    if ($bucketRow === null) {
        return [];
    }
    $jobs = [];
    $results = [];
    foreach ($keys as $key) {
        $prepared = _stattic_s3_prepare($bucketRow, 'put', $key);
        if (isset($prepared['error'])) {
            $results[$key] = false;
            continue;
        }
        ['credentials' => $credentials, 'locator' => $locator] = $prepared;
        $job = _stattic_http_prepare(_stattic_s3_transport_request(
            $bucketRow,
            $credentials,
            $locator,
            'DELETE',
            [],
            STATTIC_S3_EMPTY_PAYLOAD_SHA256,
            [
                'resolve' => $options['resolve'] ?? [],
                'sink' => static fn (string $chunk): bool => true,
            ]
        ));
        if ($job === false) {
            $results[$key] = false;
            continue;
        }
        $jobs[] = ['id' => $key, 'handle' => $job['handle']];
    }

    foreach (_stattic_s3_multi_run($jobs, STATTIC_S3_DELETE_PARALLEL_STREAMS) as $key => $transfer) {
        $results[$key] = $transfer['error'] === null
            && in_array((int) $transfer['status'], [200, 204, 404], true);
    }
    return $results;
}

// Stale-key failures retry once against a freshly-read manifest.
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

// Runs a signed streamed GET. $onHeaders(int $status, array $headers) fires
// once, after the final header block and before any body byte, the last point
// where the caller can set its own status/headers; $headers is a lowercase
// name => value map. $onChunk(string) fires per chunk and returning false
// aborts; without it the body streams to output. No buffered body is returned.
function _stattic_s3_stream_get(
    array $remoteLocator,
    ?string $rangeHeader = null,
    ?callable $onHeaders = null,
    ?callable $onChunk = null,
    array $options = []
): array {
    $bucketId = (string) ($remoteLocator['bucket'] ?? '');
    $key = (string) ($remoteLocator['key'] ?? '');
    $bucketRow = $bucketId === '' ? null : _stattic_s3_bucket_row($bucketId);
    $prepared = $bucketRow === null || $key === ''
        ? ['error' => 's3_open_failed']
        : _stattic_s3_prepare($bucketRow, 'get', $key);
    if (isset($prepared['error'])) {
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => $prepared['error']];
    }
    ['credentials' => $credentials, 'locator' => $locator] = $prepared;

    return _stattic_http_request(_stattic_s3_transport_request(
        $bucketRow,
        $credentials,
        $locator,
        'GET',
        $rangeHeader !== null && $rangeHeader !== '' ? ['range' => $rangeHeader] : [],
        STATTIC_S3_EMPTY_PAYLOAD_SHA256,
        [
            'resolve' => $options['resolve'] ?? [],
            'sink' => $onChunk === null ? 'output' : $onChunk,
            'on_headers' => $onHeaders === null
                ? null
                : static fn (int $status, array $headerPairs): mixed => $onHeaders($status, _stattic_http_header_map($headerPairs)),
        ]
    ));
}

// $jobs: list of ['id' => string, 'handle' => CurlHandle]. Returns a map keyed
// by job id of ['status' => int, 'error' => ?string]. Transport level only;
// callers apply their own status check.
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
            // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5. GC
            // frees the handle once curl_multi_remove_handle() drops its ref.
            $fill();
        }
    } while ($running > 0 || $active !== [] || $queue !== []);

    curl_multi_close($multi);
    return $results;
}

// $items: list of {id?, bucket, key, source_path, sha256?, content_type?};
// returns a map keyed by item id of {ok, status, error, sha256}. SigV4 signs
// the payload hash in a header sent before the body, so an omitted 'sha256'
// costs a hash_file() pass. The body still streams from disk, never buffered.
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
        $fh = fopen($sourcePath, 'rb');
        if ($fh === false) {
            $contexts[$itemId] = ['error' => 's3_source_open_failed'];
            continue;
        }

        $extraHeaders = ['content-length' => (string) $size];
        if (isset($item['content_type']) && is_string($item['content_type']) && $item['content_type'] !== '') {
            $extraHeaders['content-type'] = $item['content_type'];
        }
        $job = _stattic_http_prepare(_stattic_s3_transport_request(
            $bucketRow,
            $credentials,
            $locator,
            'PUT',
            $extraHeaders,
            $payloadHash,
            [
                'body_stream' => $fh,
                'body_size' => $size,
                'sink' => static fn (string $chunk): bool => true,
            ]
        ));
        if ($job === false) {
            fclose($fh);
            $contexts[$itemId] = ['error' => 's3_transport_unavailable'];
            continue;
        }

        $jobs[] = ['id' => $itemId, 'handle' => $job['handle']];
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
