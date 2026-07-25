<?php
declare(strict_types=1);

// Tiered (remote-blob) serving machinery — plan §8/§26. This module is loaded
// LAZILY from serve.php (the _stattic_serve_remote_* wrappers), the same
// convention as proxy.php: a tiered request is a slow path by construction
// (one bucket RTT), so the local hot path never parses the SigV4 client, the
// per-bucket breaker, or the in-flight cap. Keep every curl_/json_decode/
// config dependency HERE, not in serve.php — the public-runtime boundary
// suite enforces that split.
//
// bootstrap-config.php runs here (not in init.php) so Atomic persistent data
// (SPACEFAST_STORAGE_BUCKETS_JSON and the tier knobs) is only materialized
// when a tiered file is actually being served.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/s3.php';
require_once __DIR__ . '/../shared/admission.php';

const STATTIC_TIER_BREAKER_FAILURE_THRESHOLD = 3;
const STATTIC_TIER_BREAKER_WINDOW_SECONDS = 60;
const STATTIC_TIER_RETRY_AFTER_SECONDS = 30;
const STATTIC_TIER_DEFAULT_IN_FLIGHT_LIMIT = 8;

function _stattic_tier_key(string $prefix, string $bucketId): string
{
    return 'spacefast:tier:' . $prefix . ':' . _stattic_admission_sanitize_key($bucketId);
}

function _stattic_tier_breaker_path(string $privateRoot, string $bucketId): string
{
    return $privateRoot . '/runtime/tier/breakers/' . _stattic_admission_sanitize_key($bucketId) . '.json';
}

// Per-bucket circuit breaker, stored in the admission backend: apcu when
// available (atomic, no serve-path flock), the JSON file otherwise — the
// backend is picked once per process by _stattic_admission_backend(), so
// tripped/success/failure never mix lanes within a request. APCu state is
// per-FPM-pool and vanishes on pool restart: the breaker simply closes and
// re-probes the bucket after a restart, which is the desired default (a
// stale open verdict outliving its window would be worse). All keys are
// TTL'd to the breaker window, so both lanes are self-expiring.
function _stattic_tier_breaker_tripped(string $privateRoot, string $bucketId): bool
{
    $now = time();
    if (_stattic_admission_backend() === 'apcu') {
        $until = @apcu_fetch(_stattic_tier_key('open_until', $bucketId), $ok);
        return $ok && is_int($until) && $until > $now;
    }
    $path = _stattic_tier_breaker_path($privateRoot, $bucketId);
    if (!is_file($path)) {
        return false;
    }
    $state = _stattic_runtime_read_json($path);
    return is_array($state) && (int) ($state['open_until'] ?? 0) > $now;
}

function _stattic_tier_breaker_success(string $privateRoot, string $bucketId): void
{
    if (_stattic_admission_backend() === 'apcu') {
        @apcu_delete(_stattic_tier_key('failures', $bucketId));
        @apcu_delete(_stattic_tier_key('open_until', $bucketId));
        return;
    }
    $path = _stattic_tier_breaker_path($privateRoot, $bucketId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function _stattic_tier_breaker_failure(string $privateRoot, string $bucketId): void
{
    $threshold = _stattic_config_int('SPACEFAST_TIER_FETCH_BREAKER_FAILURES', STATTIC_TIER_BREAKER_FAILURE_THRESHOLD);
    $now = time();
    if (_stattic_admission_backend() === 'apcu') {
        $key = _stattic_tier_key('failures', $bucketId);
        @apcu_add($key, 0, STATTIC_TIER_BREAKER_WINDOW_SECONDS);
        $count = @apcu_inc($key, 1, $ok, STATTIC_TIER_BREAKER_WINDOW_SECONDS);
        if ($ok && is_int($count) && $count >= $threshold) {
            @apcu_store(_stattic_tier_key('open_until', $bucketId), $now + STATTIC_TIER_BREAKER_WINDOW_SECONDS, STATTIC_TIER_BREAKER_WINDOW_SECONDS);
        }
        return;
    }
    $path = _stattic_tier_breaker_path($privateRoot, $bucketId);
    $state = is_file($path) ? _stattic_runtime_read_json($path) : null;
    $count = is_array($state) && (int) ($state['updated_at'] ?? 0) >= $now - STATTIC_TIER_BREAKER_WINDOW_SECONDS
        ? (int) ($state['failures'] ?? 0)
        : 0;
    $count++;
    _stattic_runtime_write_json_atomic($path, [
        'failures' => $count,
        'open_until' => $count >= $threshold ? $now + STATTIC_TIER_BREAKER_WINDOW_SECONDS : 0,
        'updated_at' => $now,
    ]);
}

// Per-box tier-fetch concurrency cap, backed by the same apcu+flock counter
// primitive as the per-space uncacheable admission gate (shared/admission.php)
// — this used to hand-roll its own copy (~60 duplicated LOC, no
// backend-force-override support). See _stattic_admission_counter_acquire.
function _stattic_tier_in_flight_acquire(string $privateRoot): callable|false
{
    $limit = _stattic_config_int('SPACEFAST_TIER_FETCH_MAX_IN_FLIGHT', STATTIC_TIER_DEFAULT_IN_FLIGHT_LIMIT);
    return _stattic_admission_counter_acquire(
        $privateRoot,
        _stattic_tier_key('inflight', 'box'),
        $privateRoot . '/runtime/tier/inflight.json',
        $limit,
        STATTIC_TIER_BREAKER_WINDOW_SECONDS,
    );
}

function _stattic_tier_fetch_failed(string $privateRoot, array $remote, int $status = 0, ?string $error = null): void
{
    $bucketId = (string) ($remote['bucket'] ?? '');
    if ($bucketId !== '') {
        _stattic_tier_breaker_failure($privateRoot, $bucketId);
    }
    $entry = [
        'event' => 'tier_fetch_failed',
        'bucket' => $bucketId,
        'key' => (string) ($remote['key'] ?? ''),
        'status' => $status,
        'error' => $error,
    ];
    _stattic_runtime_append_journal($privateRoot, $entry);
    _stattic_tier_spool_failure_event($privateRoot, $entry);
}

// Spools a bucket-fetch failure for the bulk tick to ship as a real callback
// (the control plane's auto-pause canary counts these per bucket). The serve
// path has no callback claims, so the spool file is the hand-off. Bounded:
// the breaker already caps failure frequency, and we stop writing past 500
// undrained events. `failure_id` keeps each occurrence's event_id distinct —
// the CP dedupe table would otherwise collapse identical failures into one
// and the rolling threshold could never trip.
function _stattic_tier_spool_failure_event(string $privateRoot, array $entry): void
{
    $dir = $privateRoot . '/runtime/tier/pending-events';
    _stattic_runtime_mkdir($dir);
    $existing = glob($dir . '/*.json') ?: [];
    if (count($existing) >= 500) {
        return;
    }
    $entry['failure_id'] = bin2hex(random_bytes(8));
    $entry['occurred_at'] = gmdate('c');
    _stattic_runtime_write_json_atomic($dir . '/' . $entry['failure_id'] . '.json', $entry);
}

function _stattic_tier_stream_remote(string $privateRoot, array $servedMeta, array $headers, int $status, int $size, ?array $range, string $spaceId, string $path): void
{
    $remote = _stattic_tier_remote_locator($servedMeta);
    if ($remote === null) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime file metadata points to a missing file.');
    }
    $bucketId = (string) $remote['bucket'];
    if (_stattic_tier_breaker_tripped($privateRoot, $bucketId)) {
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }
    $release = _stattic_tier_in_flight_acquire($privateRoot);
    if ($release === false) {
        _stattic_runtime_append_journal($privateRoot, ['event' => 'tier_fetch_failed', 'bucket' => $bucketId, 'key' => (string) $remote['key'], 'error' => 'in_flight_cap']);
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }

    $rangeHeader = is_array($range) ? (string) $range['header'] : null;
    $sentHeaders = false;
    $remoteStatus = 0;
    $remoteHeaders = [];
    $open = _stattic_s3_open(
        $remote,
        $rangeHeader,
        static function (int $bucketStatus, array $bucketHeaders) use (&$sentHeaders, &$remoteStatus, &$remoteHeaders, $headers, $status, $size, $range): void {
            $remoteStatus = $bucketStatus;
            $remoteHeaders = $bucketHeaders;
            if ($bucketStatus >= 200 && $bucketStatus < 300) {
                _stattic_send_file_headers($headers);
                header('Accept-Ranges: bytes');
                if (is_array($range) && $bucketStatus === 206 && is_string($bucketHeaders['content-range'] ?? null)) {
                    header('Content-Range: ' . $bucketHeaders['content-range']);
                    $length = (int) $range['end'] - (int) $range['start'] + 1;
                    header('Content-Length: ' . $length);
                    http_response_code(206);
                } else {
                    header('Content-Length: ' . $size);
                    http_response_code($status);
                }
                $sentHeaders = true;
            }
        }
    );
    if ($open === false) {
        if (is_callable($release)) {
            $release();
        }
        _stattic_tier_fetch_failed($privateRoot, $remote, 0, 's3_open_failed');
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }
    $exec = curl_exec($open['handle']);
    $errno = curl_errno($open['handle']);
    if (is_callable($release)) {
        $release();
    }
    if ($errno === 0 && $remoteStatus === 416) {
        // The bucket disagreeing with our locally-computed range (e.g. its
        // stored object size drifted from our stamped $size) is a CLIENT
        // range problem, not a bucket/breaker failure — relay it as a real
        // 416 and never count it against the breaker (confirmed finding F7).
        // serve.php:1226-1229 already 416s locally-unsatisfiable ranges
        // before ever dialing, so this is edge-hardening for the rest.
        _stattic_tier_send_416($headers, $size);
    }
    if ($exec === false || $errno !== 0 || $remoteStatus < 200 || $remoteStatus >= 300) {
        _stattic_tier_fetch_failed($privateRoot, $remote, $remoteStatus, $errno !== 0 ? curl_error($open['handle']) : 's3_status_' . $remoteStatus);
        if (!$sentHeaders) {
            _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
        }
        exit;
    }
    _stattic_tier_breaker_success($privateRoot, $bucketId);
    // Post-response and non-blocking: tier_hit is telemetry, and this runs on
    // the visitor serve path — the append happens after the response is
    // flushed, and never queues on the shared journal lock even then.
    $tierHit = [
        'event' => 'tier_hit',
        'space_id' => $spaceId,
        'path' => $path,
        'bucket' => $bucketId,
        'key' => (string) $remote['key'],
        'status' => is_array($range) ? 206 : $status,
    ];
    _spacefast_defer(static function () use ($privateRoot, $tierHit): void {
        _stattic_runtime_append_journal($privateRoot, $tierHit, false);
    });
    exit;
}

function _stattic_tier_read_remote_body(string $privateRoot, array $servedMeta, int $size): string
{
    if ($size > 2097152) {
        _stattic_render_runtime_invariant_error_lazy('file-too-large', 'Runtime file is too large to transform.');
    }
    $remote = _stattic_tier_remote_locator($servedMeta);
    if ($remote === null) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime file metadata points to a missing file.');
    }
    // Same protections as the streaming path: an open breaker means 503
    // WITHOUT dialing, and the buffered transform read still occupies an FPM
    // slot, so it counts against the per-box in-flight cap too.
    $bucketId = (string) $remote['bucket'];
    if (_stattic_tier_breaker_tripped($privateRoot, $bucketId)) {
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }
    $release = _stattic_tier_in_flight_acquire($privateRoot);
    if ($release === false) {
        _stattic_runtime_append_journal($privateRoot, ['event' => 'tier_fetch_failed', 'bucket' => $bucketId, 'key' => (string) $remote['key'], 'error' => 'in_flight_cap']);
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }
    $result = _stattic_s3_request_by_bucket_id($bucketId, 'get', 'GET', (string) $remote['key']);
    if (is_callable($release)) {
        $release();
    }
    if (!$result['ok'] || (int) $result['status'] !== 200 || strlen((string) $result['body']) !== $size) {
        _stattic_tier_fetch_failed($privateRoot, $remote, (int) ($result['status'] ?? 0), (string) ($result['error'] ?? 's3_read_failed'));
        _stattic_render_tier_fetch_unavailable(STATTIC_TIER_RETRY_AFTER_SECONDS);
    }
    _stattic_tier_breaker_success($privateRoot, $bucketId);
    return (string) $result['body'];
}
