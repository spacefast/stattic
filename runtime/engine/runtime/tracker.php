<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/injection-plan.php';
require_once __DIR__ . '/space-bar.php';

// Beacon's own lifetime for t.js, so a new tracker reaches pages as fast
// through this copy as it does from Beacon. It is how long the edge keeps a
// fresh copy, and how long a box keeps its copy before asking Beacon again.
const STATTIC_SPACEFAST_TRACKER_TTL_SECONDS = 300;
// A browser's lifetime for every answer, and the edge's for everything that is
// not a fresh copy: an empty script for a Space with no tracker or a URL with a
// query, or an old copy while Beacon is down. The edge asks again after a
// minute, so switching a Space on, or Beacon coming back, shows within one.
const STATTIC_SPACEFAST_TRACKER_SHORT_SECONDS = 60;
const STATTIC_SPACEFAST_TRACKER_MAX_BYTES = 524288;
const STATTIC_SPACEFAST_TRACKER_EMPTY = "/* No Spacefast analytics here. */\n";

/**
 * The tracker this Space's pages load, as the control plane projected it, or
 * null when they load none: the Space has analytics off, or the deployment has
 * no Beacon. `src` is a path on this host (or a platform URL), `endpoint` is
 * Beacon's origin, `key` is the tracker's `data-site`, and `wait` says the
 * tracker sends nothing until the visitor agrees in the page's consent banner.
 */
function _stattic_spacefast_tracker_config(array $serving): ?array
{
    require_once __DIR__ . '/../shared/egress.php';
    $tracker = _stattic_spacefast_sdk_config($serving)['tracker'] ?? null;
    if (!is_array($tracker) || !_stattic_injection_on($serving, 'tracker')) {
        return null;
    }
    $src = is_string($tracker['src'] ?? null) ? $tracker['src'] : '';
    $endpoint = is_string($tracker['endpoint'] ?? null) ? rtrim($tracker['endpoint'], '/') : '';
    $key = is_string($tracker['key'] ?? null) ? $tracker['key'] : '';
    $samePath = preg_match('~\A/(?![/\\\\])[^\s"\'<>\\\\]*\z~', $src) === 1;
    if (
        !($samePath || _stattic_platform_destination_allowed($src))
        || !_stattic_platform_destination_allowed($endpoint)
        || $key === ''
        || strlen($key) > 256
        || preg_match('/[\x00-\x1f\x7f]/', $key) === 1
    ) {
        return null;
    }
    return [
        'src' => $src,
        'endpoint' => $endpoint,
        'key' => $key,
        'wait' => ($tracker['consent'] ?? null) === 'wait',
    ];
}

// The tracker this page view counts with, or null: only a visitor's view of
// the live surface counts.
function _stattic_spacefast_tracker_for_page(array $serving, ?string $previewToken): ?array
{
    $tracker = _stattic_spacefast_tracker_config($serving);
    if (
        $tracker === null
        || $previewToken !== null
        || isset($_GET['review'])
        || _stattic_system_view_review() !== null
        || _stattic_spacefast_preview_surface($serving)
    ) {
        return null;
    }
    return $tracker;
}

/**
 * `/__spacefast/t.js`: Beacon's tracker, fetched from the Beacon this Space
 * reports to and served from the Space's own origin. Proxied rather than
 * bundled, so the page always runs the tracker that Beacon's wire format
 * expects. It is served after the access check, like sdk.js, so a visitor who
 * may not view a protected Space gets the answer every other path gives and
 * learns nothing about whether it has analytics on. On an open Space every
 * answer is public, so the edge serves repeats and PHP sees only misses; on a
 * protected one it stays out of shared caches. No admission slot is taken,
 * because a miss is no Space work: the box keeps one copy per Beacon and asks
 * Beacon at most once per lifetime.
 *
 * A miss must not be something a visitor can manufacture. The edge keys on
 * host, path and query, so a URL with any query gets the empty script without
 * touching the copy or Beacon, as a Space with no tracker does.
 *
 * Beacon unreachable is the last copy, or the empty script, never an error: a
 * page must not break because its analytics are down.
 */
function _stattic_serve_spacefast_tracker(string $privateRoot, array $serving, bool $privateCache): never
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD', [
            'body' => '',
            'media_type' => 'application/javascript; charset=utf-8',
        ]);
    }
    $tracker = (string) ($_SERVER['QUERY_STRING'] ?? '') === ''
        ? _stattic_spacefast_tracker_config($serving)
        : null;
    $copy = $tracker === null
        ? ['body' => null, 'fresh' => false]
        : _stattic_spacefast_tracker_copy($privateRoot, $tracker['endpoint']);
    $body = $copy['body'] ?? STATTIC_SPACEFAST_TRACKER_EMPTY;
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8', true);
    header('X-Content-Type-Options: nosniff', true);
    // A browser keeps even a fresh copy for only a minute. The edge sends no
    // Age, so a browser counts its lifetime from its own fetch, however long
    // the edge had already held that copy.
    _stattic_send_cache_policy_headers(
        $privateCache,
        'public, max-age=' . STATTIC_SPACEFAST_TRACKER_SHORT_SECONDS . ($copy['fresh']
            ? ', s-maxage=' . STATTIC_SPACEFAST_TRACKER_TTL_SECONDS
            : '')
    );
    header('ETag: "' . hash('sha256', $body) . '"', true);
    header('Content-Length: ' . strlen($body), true);
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * The box's copy of one Beacon's t.js: fresh for its lifetime, then refreshed by
 * the one worker that takes the lock while the others wait for its answer.
 * A refresh that fails keeps the old copy and is not tried again for a short
 * lifetime, so a Beacon outage costs a box one request a minute. An answer
 * Beacon's edge marked no-cache is waited out the same way, though its body
 * replaces the copy.
 *
 * @return array{body: ?string, fresh: bool}
 */
function _stattic_spacefast_tracker_copy(string $privateRoot, string $endpoint): array
{
    require_once __DIR__ . '/../shared/lock.php';
    require_once __DIR__ . '/../shared/pointers.php';
    $directory = $privateRoot . '/runtime/tracker';
    if (!_stattic_runtime_mkdir_soft($directory)) {
        return ['body' => null, 'fresh' => false];
    }
    $path = $directory . '/' . hash('sha256', $endpoint) . '.json';
    $copy = _stattic_spacefast_tracker_read($path);
    $now = time();
    $answer = static fn (array $copy): array => [
        'body' => $copy['body'],
        'fresh' => $copy['body'] !== null
            && $now - $copy['fetched_at'] < STATTIC_SPACEFAST_TRACKER_TTL_SECONDS,
    ];
    if ($answer($copy)['fresh']) {
        return $answer($copy);
    }
    return _stattic_lock_with(
        $path . '.lock',
        STATTIC_LOCK_WAIT,
        static function () use ($path, $copy, $answer): array {
            error_log('spacefast tracker copy busy, served the last copy path=' . $path);
            return $answer($copy);
        },
        static function () use ($path, $endpoint, $now, $answer): array {
            // Another worker may have refreshed it, or just failed to, while
            // this one waited.
            $copy = _stattic_spacefast_tracker_read($path);
            if (
                $answer($copy)['fresh']
                || $now - $copy['attempted_at'] < STATTIC_SPACEFAST_TRACKER_SHORT_SECONDS
            ) {
                return $answer($copy);
            }
            $fetched = _stattic_spacefast_tracker_upstream($endpoint, $noCache);
            // Once per tracker release per box. The short shas are the heads of
            // the ETags t.js is served with, old and new.
            if ($fetched !== null && $fetched !== $copy['body']) {
                error_log(
                    'spacefast tracker copy refreshed endpoint=' . $endpoint
                    . ' sha256=' . substr(hash('sha256', $fetched), 0, 12)
                    . ' previous=' . ($copy['body'] === null ? 'none' : substr(hash('sha256', $copy['body']), 0, 12))
                );
            }
            // A no-cache answer is kept but already expired: it is served as not
            // fresh, and asked for again once the retry window has passed.
            $next = [
                'fetched_at' => $fetched === null ? $copy['fetched_at'] : ($noCache ? 0 : $now),
                'attempted_at' => $now,
                'body' => $fetched ?? $copy['body'],
            ];
            $encoded = json_encode($next, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || !_sf_atomic_put($path, $encoded, false)) {
                error_log('spacefast tracker copy not written path=' . $path);
            }
            return $answer($next);
        }
    );
}

/** @return array{fetched_at: int, attempted_at: int, body: ?string} */
function _stattic_spacefast_tracker_read(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $copy = is_array($decoded) ? $decoded : [];
    return [
        'fetched_at' => is_int($copy['fetched_at'] ?? null) ? $copy['fetched_at'] : 0,
        'attempted_at' => is_int($copy['attempted_at'] ?? null) ? $copy['attempted_at'] : 0,
        'body' => is_string($copy['body'] ?? null) && $copy['body'] !== '' ? $copy['body'] : null,
    ];
}

// Beacon's t.js, or null when Beacon did not answer with a script. The
// endpoint is the control plane's, so it is held to the platform destination
// rule, not to the rule for URLs a tenant names. `$noCache` says the answer
// carried Cache-Control no-cache, which Beacon's edge sends on a copy it served
// stale while it asks Beacon again.
function _stattic_spacefast_tracker_upstream(string $endpoint, ?bool &$noCache = null): ?string
{
    require_once __DIR__ . '/../shared/egress.php';
    require_once __DIR__ . '/../shared/http.php';
    $noCache = false;
    $url = $endpoint . '/t.js';
    if (!_stattic_platform_destination_allowed($url)) {
        return null;
    }
    $result = _stattic_http_request([
        'url' => $url,
        'method' => 'GET',
        'headers' => ['Accept' => 'text/javascript, application/javascript'],
        'connect_timeout_ms' => 1000,
        'timeout_ms' => 2000,
        'schemes' => ['https', 'http'],
        'max_body_bytes' => STATTIC_SPACEFAST_TRACKER_MAX_BYTES,
    ]);
    $type = strtolower((string) (_stattic_http_header_map($result['headers'])['content-type'] ?? ''));
    if ($result['status'] !== 200 || !$result['ok'] || trim($result['body']) === '' || !str_contains($type, 'javascript')) {
        error_log(
            'spacefast tracker upstream unavailable status=' . $result['status']
            . ' error=' . ($result['error'] ?? 'not_a_script')
        );
        return null;
    }
    // Every Cache-Control line counts: the edge may add its own beside Beacon's.
    $noCache = array_any(
        $result['headers'],
        static fn (array $header): bool => strtolower((string) $header[0]) === 'cache-control'
            && _stattic_cache_control_has_directive((string) $header[1], 'no-cache')
    );
    return $result['body'];
}
