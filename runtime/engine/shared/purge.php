<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';

// A mutation that changed served bytes purges the provider edge by calling the
// wp.cloud site's LOCAL edge-cache API directly — the same
// `http://127.0.0.1:47002/api/v1.0/edge-cache/<site>/purge/<domain>` endpoint
// the platform's own Edge_Cache mu-plugin calls, reached over loopback with the
// site's `ATOMIC_SITE_API_KEY`. No WordPress bootstrap, no durable queue: the
// old bridge loaded `wp-load.php` (80-99 ms, tenant hooks) only to reach that
// same endpoint through a plugin object, and wrapped it in a record store,
// coalescer and dead-letter lane built for a heavy, failure-prone call. A
// loopback POST to a same-box nginx gateway is a different reliability class; a
// bounded in-request retry covers the rare transient, and the visitor never
// waits because the call is deferred past `fastcgi_finish_request`.

// URI purges are used only for pure assets. For mutable document keys
// (directory, extensionless, HTML, JSON, XML) a single URI invalidation does
// not prove every visitor-facing key converged, so any such path widens the
// activation to a full-host purge — a fail-closed default. The 300 URL ceiling
// keeps one activation off an unproven mega-batch.
const STATTIC_RUNTIME_PURGE_URL_MAX = 300;

const STATTIC_RUNTIME_PURGE_ASSET_EXTENSIONS = [
    'woff2', 'woff', 'ttf', 'otf', 'eot', 'css', 'js',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg',
    'mp4', 'webm', 'mp3', 'pdf',
];

// The local gateway is on loopback; a failure is a transient, not a network
// partition. One retry covers it without pinning the worker.
const STATTIC_RUNTIME_PURGE_ATTEMPTS = 2;

// The site's local edge-cache API base and auth, from the platform env the FPM
// prepend (/scripts/env.php) already defined. Null when either is absent — off
// wp.cloud (dev, CI, the php -S harness) there is no edge to purge, and a purge
// there is a successful no-op, never a failure.
//
// @return array{base: string, key: string}|null
function _stattic_runtime_edge_purge_endpoint(): ?array
{
    $siteId = _stattic_config_value('ATOMIC_SITE_ID');
    $key = _stattic_config_value('ATOMIC_SITE_API_KEY');
    if ($siteId === '' || $key === '' || preg_match('/\A\d+\z/', $siteId) !== 1) {
        return null;
    }
    // The gateway lives at a fixed loopback address on every wp.cloud box; the
    // override exists only so the test harness can point purges at a capture
    // server (the live wire is exercised by the credential-gated e2e suite).
    $apiRoot = _stattic_config_value('SPACEFAST_EDGE_CACHE_API_BASE');
    if ($apiRoot === '') {
        $apiRoot = 'http://127.0.0.1:47002/api/v1.0';
    }
    return [
        'base' => rtrim($apiRoot, '/') . '/edge-cache/' . $siteId . '/purge/',
        'key' => $key,
        // http.php defaults to https-only; the loopback gateway is http.
        'scheme' => strtolower((string) parse_url($apiRoot, PHP_URL_SCHEME)) === 'https' ? 'https' : 'http',
    ];
}

/**
 * One purge POST to the local gateway for one hostname. `$uris` empty purges the
 * whole host; otherwise it purges exactly those URLs. Mirrors the body the
 * platform's Edge_Cache adapter sends (class-edge-cache-atomic.php). Success is
 * the gateway's `{"message":"OK"}`; a bounded retry covers a loopback blip.
 *
 * @param list<string> $uris
 */
function _stattic_runtime_edge_purge_host(array $endpoint, string $hostname, array $uris, string $reason): bool
{
    $body = [
        'purge_count' => count($uris) ?: 1,
        'wp_domain' => $hostname,
        'at_host' => php_uname('n'),
        // Attribution for the provider's purge log, as the plugin sends it.
        'wp_action' => 'spacefast:' . $reason,
    ];
    if ($uris !== []) {
        $body['purge_uris'] = array_values($uris);
    }
    $request = [
        'url' => $endpoint['base'] . rawurlencode($hostname),
        'method' => 'POST',
        'schemes' => [$endpoint['scheme'] ?? 'http'],
        'headers' => ['Auth' => $endpoint['key'], 'Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
        'connect_timeout' => 2,
        'timeout' => 5,
    ];
    for ($attempt = 0; $attempt < STATTIC_RUNTIME_PURGE_ATTEMPTS; $attempt++) {
        $result = _stattic_http_request($request);
        if ($result['ok'] && str_contains((string) $result['body'], '"message":"OK"')) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function _stattic_runtime_purge_hostname_list(mixed $raw): array
{
    $hostnames = [];
    foreach (is_array($raw) ? $raw : [] as $hostname) {
        if (!is_string($hostname)) {
            continue;
        }
        $normalized = _stattic_normalize_hostname($hostname);
        if ($normalized !== '' && preg_match('/\A[a-z0-9.-]{1,253}\z/', $normalized) === 1) {
            $hostnames[$normalized] = true;
        }
    }
    return array_keys($hostnames);
}

/** @return list<string> queryless request paths, deduplicated, in input order. */
function _stattic_runtime_purge_path_list(mixed $raw): array
{
    $paths = [];
    foreach (is_array($raw) ? $raw : [] as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > 2048) {
            continue;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            continue;
        }
        $normalized = parse_url($path, PHP_URL_PATH);
        if (!is_string($normalized) || $normalized === '' || $normalized[0] !== '/') {
            continue;
        }
        $paths[$normalized] = true;
    }
    return array_keys($paths);
}

/**
 * The provider purges URLs, the runtime knows paths and hostnames: the cross
 * product IS the changed set. Two hostnames and twenty paths are forty edge
 * entries, not twenty.
 *
 * @return list<string>
 */
function _stattic_runtime_purge_expand_urls(array $hostnames, array $paths): array
{
    $urls = [];
    foreach ($hostnames as $hostname) {
        foreach ($paths as $path) {
            $urls[] = 'https://' . $hostname . $path;
        }
    }
    return $urls;
}

/** @param list<string> $urls */
function _stattic_runtime_purge_urls_are_assets(array $urls): bool
{
    foreach ($urls as $url) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || str_ends_with($path, '/')) {
            return false;
        }
        $segment = substr($path, (int) strrpos($path, '/') + 1);
        $dot = strrpos($segment, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }
        if (!in_array(strtolower(substr($segment, $dot + 1)), STATTIC_RUNTIME_PURGE_ASSET_EXTENSIONS, true)) {
            return false;
        }
    }
    return $urls !== [];
}

/** @param list<string> $urls */
function _stattic_runtime_purge_scope(array $urls): string
{
    return count($urls) <= STATTIC_RUNTIME_PURGE_URL_MAX
        && _stattic_runtime_purge_urls_are_assets($urls)
        ? 'urls'
        : 'domain';
}

// The public→private edge is the ONE access transition that owes the edge a
// full sweep: every alias may hold formerly-public responses, including
// year-TTL immutable assets. The reverse owes nothing — denied responses are
// `private, no-store` by construction — and a missing or malformed NEW
// descriptor reads as not-public, so the ambiguous side still sweeps.
function _stattic_runtime_exposure_became_private(?array $previous, ?array $next): bool
{
    return is_array($previous)
        && ($previous['public'] ?? null) === true
        && !(is_array($next) && ($next['public'] ?? null) === true);
}

/**
 * Every hostname the space's route intent names. Pass `$intent` when the
 * document is already in hand. Tombstoned hostnames are deliberately absent.
 *
 * @return list<string>
 */
function _stattic_runtime_route_intent_hostnames(string $spaceRoot, ?array $intent = null): array
{
    $hostnames = [];
    $intent ??= _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json');
    if (is_array($intent) && is_array($intent['routes'] ?? null)) {
        foreach ($intent['routes'] as $route) {
            if (is_array($route) && is_string($route['hostname'] ?? null) && $route['hostname'] !== '') {
                $hostnames[$route['hostname']] = true;
            }
        }
    }
    return array_keys($hostnames);
}

/**
 * Every hostname the space answers on — route intent plus tombstones — ordered
 * for a full access sweep: live serving hostnames first, then version-pinned
 * hostnames newest→oldest (the `v<number>--` label token is the only recency
 * the intent carries; underivable ones follow in intent order), then
 * redirect/proxy hosts and tombstoned hostnames. Order decides who gets fresh
 * bytes first; the host purge preserves it.
 *
 * @return list<string>
 */
function _stattic_runtime_access_sweep_hostnames(?array $intent, ?array $tombstones): array
{
    $production = [];
    $numbered = [];
    $unnumbered = [];
    $rest = [];
    foreach (is_array($intent['routes'] ?? null) ? $intent['routes'] : [] as $route) {
        if (!is_array($route) || !is_string($route['hostname'] ?? null)) {
            continue;
        }
        $type = is_array($route['target'] ?? null) ? ($route['target']['type'] ?? null) : null;
        if ($type === 'route') {
            $production[] = $route['hostname'];
        } elseif ($type === 'version') {
            $label = strstr($route['hostname'], '.', true);
            if (preg_match('/\Av(\d+)--/', $label === false ? $route['hostname'] : $label, $token) === 1) {
                $numbered[(int) $token[1]][] = $route['hostname'];
            } else {
                $unnumbered[] = $route['hostname'];
            }
        } else {
            $rest[] = $route['hostname'];
        }
    }
    krsort($numbered);
    $newestFirst = $numbered === [] ? [] : array_merge(...array_values($numbered));
    $hostnames = [...$production, ...$newestFirst, ...$unnumbered, ...$rest];
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null)) {
        foreach ($tombstones['hostnames'] as $hostname) {
            if (is_string($hostname)) {
                $hostnames[] = $hostname;
            }
        }
    }
    // First occurrence wins, so a tombstoned hostname the intent still serves
    // keeps its serving-order slot.
    return array_values(array_unique($hostnames));
}

/**
 * Purge the edge for one mutation and return a receipt.
 *
 * `$input`: hostnames (required — a purge with no hostname is unaddressable),
 * paths, reason. Scope is DERIVED, never passed: at most 300 normalized URLs
 * that are all pure assets purge by URI; document, oversized and unaddressable
 * sets purge every named host in full.
 *
 * On FPM the provider round-trip is deferred past `fastcgi_finish_request`, so
 * the caller's response returns immediately and the receipt is `queued`.
 * Without it (CLI dispatch, the php -S harness) the call is synchronous and the
 * receipt carries the real outcome: `ok` the edge accepted every host, `failed`
 * at least one host refused (journaled for operators; the loopback retry
 * already tried twice). A space no hostname addresses, or a box with no edge
 * API (dev/CI), is `ok`/`none`.
 *
 * @return array{status: string, mode: string, urls?: int}
 */
function _stattic_runtime_purge_now(string $privateRoot, array $input): array
{
    $hostnames = _stattic_runtime_purge_hostname_list($input['hostnames'] ?? null);
    $paths = _stattic_runtime_purge_path_list($input['paths'] ?? null);
    $urls = _stattic_runtime_purge_expand_urls($hostnames, $paths);
    $scope = _stattic_runtime_purge_scope($urls);
    $reason = is_string($input['reason'] ?? null) ? $input['reason'] : 'runtime_mutation';

    if ($hostnames === []) {
        return ['status' => 'ok', 'mode' => 'none'];
    }

    $mode = $scope === 'urls' ? 'urls' : 'domain';
    $urlCount = count($urls);
    $run = static function () use ($privateRoot, $hostnames, $urls, $scope, $reason): bool {
        return _stattic_runtime_purge_dispatch($privateRoot, $hostnames, $urls, $scope, $reason);
    };

    if (function_exists('fastcgi_finish_request')) {
        _stattic_flush_response_before_deferred(true);
        _stattic_defer(static function () use ($run): void {
            $run();
        });
        $status = 'queued';
    } else {
        $status = $run() ? 'ok' : 'failed';
    }

    return $mode === 'urls'
        ? ['status' => $status, 'mode' => $mode, 'urls' => $urlCount]
        : ['status' => $status, 'mode' => $mode];
}

/**
 * The one purge POST per hostname, as a plan: `hostname => list<url>`. A
 * domain-scoped purge maps every hostname to `[]` (whole host). A urls-scoped
 * purge maps each hostname to only ITS OWN urls, and omits a hostname that owns
 * none — each host is its own edge key, so a host with nothing to purge is not
 * called. Pure so the plan is testable without a provider.
 *
 * @param list<string> $hostnames
 * @param list<string> $urls
 * @return array<string, list<string>>
 */
function _stattic_runtime_purge_host_targets(array $hostnames, array $urls, string $scope): array
{
    if ($scope !== 'urls') {
        return array_fill_keys($hostnames, []);
    }
    $urlsByHost = [];
    foreach ($urls as $url) {
        $host = _stattic_normalize_hostname((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '') {
            $urlsByHost[$host][] = $url;
        }
    }
    $targets = [];
    foreach ($hostnames as $hostname) {
        if (($urlsByHost[$hostname] ?? []) !== []) {
            $targets[$hostname] = $urlsByHost[$hostname];
        }
    }
    return $targets;
}

/**
 * The actual provider calls: one POST per planned hostname. Off wp.cloud (no
 * edge API) it is a successful no-op. A refused host is journaled so a stale
 * edge is visible to operators.
 *
 * @param list<string> $hostnames
 * @param list<string> $urls
 */
function _stattic_runtime_purge_dispatch(
    string $privateRoot,
    array $hostnames,
    array $urls,
    string $scope,
    string $reason
): bool {
    $endpoint = _stattic_runtime_edge_purge_endpoint();
    if ($endpoint === null) {
        return true;
    }

    $allAccepted = true;
    $failedHosts = [];
    foreach (_stattic_runtime_purge_host_targets($hostnames, $urls, $scope) as $hostname => $hostUrls) {
        if (!_stattic_runtime_edge_purge_host($endpoint, $hostname, $hostUrls, $reason)) {
            $allAccepted = false;
            $failedHosts[] = $hostname;
        }
    }

    if ($failedHosts !== []) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'edge_purge_failed',
            'mode' => $scope === 'urls' ? 'urls' : 'domain',
            'reason' => $reason,
            'hostnames' => $failedHosts,
        ]);
    }
    return $allAccepted;
}

/**
 * `_stattic_runtime_purge_now` addressed at every hostname one space's cached
 * bytes could live under — route intent plus tombstones, read off the space
 * root so the visitor lane can call it without the management readers. A space
 * no hostname has ever served is unaddressable at the provider; there is no
 * edge entry to drop, and 'none' says so honestly.
 *
 * @return array{status: string, mode: string, urls?: int}
 */
function _stattic_runtime_purge_space_paths_now(
    string $privateRoot,
    string $spaceId,
    array $paths,
    string $reason
): array {
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $hostnames = _stattic_runtime_access_sweep_hostnames(
        _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json'),
        _stattic_runtime_read_json($spaceRoot . '/tombstones.json'),
    );
    if ($hostnames === []) {
        return ['status' => 'ok', 'mode' => 'none'];
    }
    return _stattic_runtime_purge_now($privateRoot, [
        'hostnames' => $hostnames,
        'paths' => $paths,
        'reason' => $reason,
    ]);
}
