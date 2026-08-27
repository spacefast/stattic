<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';

// A mutation that changed served bytes purges the provider edge by calling the
// wp.cloud site's LOCAL edge-cache API directly, the same
// `http://127.0.0.1:47002/api/v1.0/edge-cache/<site>/purge/<domain>` endpoint
// the platform's own Edge_Cache mu-plugin calls, reached over loopback with the
// site's `ATOMIC_SITE_API_KEY`. No WordPress bootstrap, no durable queue: a
// loopback POST to a same-box nginx gateway needs neither. A bounded in-request
// retry covers the rare transient, and the visitor never waits because the call
// is deferred past `fastcgi_finish_request`.

// Every purge is a whole-host purge. The edge keys a stored response on
// host+path+QUERY while a URI purge is queryless, so an enumerated purge can
// never name every variant an entry may live under. Eviction is structural: a
// mutation drops every edge entry for every host it names, and the copies
// re-warm on the next visit. The bounded shared TTL in serve-fast.php is the
// correctness backstop when this best-effort purge cannot sync. Do not add
// a urls-scoped lane without solving query-variant coverage first.

// The local gateway is on loopback; a failure is a transient, not a network
// partition. One retry covers it without pinning the worker.
const STATTIC_RUNTIME_PURGE_ATTEMPTS = 2;

// The site's local edge-cache API base and auth, from the platform env the FPM
// prepend (/scripts/env.php) already defined. Null when either is absent: off
// wp.cloud (dev, CI, the php -S harness) there is no edge to purge, and a purge
// there is a successful no-op, never a failure.
//
// @return array{base: string, key: string, scheme: string}|null
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
 * One whole-host purge POST to the local gateway for one hostname. Mirrors the
 * body the platform's Edge_Cache adapter sends (class-edge-cache-atomic.php).
 * Success is the gateway's `{"message":"OK"}`; a bounded retry covers a
 * loopback blip.
 */
function _stattic_runtime_edge_purge_host(array $endpoint, string $hostname, string $reason): bool
{
    $body = [
        'purge_count' => 1,
        'wp_domain' => $hostname,
        'at_host' => php_uname('n'),
        // Attribution for the provider's purge log, as the plugin sends it.
        'wp_action' => 'spacefast:' . $reason,
    ];
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

// The public→private edge is the ONE access transition that owes the edge a
// full sweep: every alias may hold formerly-public responses, including
// year-TTL immutable assets. The reverse owes nothing, since denied responses
// are `private, no-store` by construction. A missing or malformed NEW
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
 * Every hostname the space answers on, route intent plus tombstones, ordered
 * for a full access sweep: live serving hostnames first, then version-pinned
 * hostnames newest→oldest (the `v<number>--` label token is the only recency
 * the intent carries; underivable ones follow in intent order), then
 * redirect/proxy hosts and tombstoned hostnames. Order decides who gets fresh
 * bytes first; the host purge preserves it.
 *
 * THE collector for "every hostname this space answers on". It is also the set
 * a space_deleted event carries, which the control plane signs before asking
 * the runtime to delete the space — so the state preflight and the mutation
 * must both derive it here, and nothing may derive it a second way.
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
        if (!is_array($route) || !is_string($route['hostname'] ?? null) || $route['hostname'] === '') {
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
 * The same set for a caller that does not already hold the two documents.
 * Reading them here is what keeps the collector single: a caller that has the
 * documents passes them straight to _stattic_runtime_access_sweep_hostnames.
 *
 * @return list<string>
 */
function _stattic_runtime_space_sweep_hostnames(string $spaceRoot): array
{
    return _stattic_runtime_access_sweep_hostnames(
        _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json'),
        _stattic_runtime_read_json($spaceRoot . '/tombstones.json'),
    );
}

/**
 * Purge the edge for one mutation and return a receipt.
 *
 * `$input`: hostnames (required, a purge with no hostname is unaddressable)
 * and reason. Every named host is purged in full; see the header for why no
 * narrower scope exists.
 *
 * On FPM the provider round-trip is deferred past `fastcgi_finish_request`, so
 * the caller's response returns immediately and the receipt is `queued`.
 * Without it (CLI dispatch, the php -S harness) the call is synchronous and the
 * receipt carries the real outcome: `ok` the edge accepted every host, `failed`
 * at least one host refused (journaled for operators; the loopback retry
 * already tried twice). A space no hostname addresses, or a box with no edge
 * API (dev/CI), is `ok`/`none`.
 *
 * @return array{status: string, mode: string}
 */
function _stattic_runtime_purge_now(string $privateRoot, array $input): array
{
    $hostnames = _stattic_runtime_purge_hostname_list($input['hostnames'] ?? null);
    $reason = is_string($input['reason'] ?? null) ? $input['reason'] : 'runtime_mutation';

    if ($hostnames === []) {
        return ['status' => 'ok', 'mode' => 'none'];
    }

    $run = static function () use ($privateRoot, $hostnames, $reason): bool {
        return _stattic_runtime_purge_dispatch($privateRoot, $hostnames, $reason);
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

    return ['status' => $status, 'mode' => 'domain'];
}

/**
 * The actual provider calls: one whole-host POST per hostname. Off wp.cloud
 * (no edge API) it is a successful no-op. A refused host is journaled so a
 * stale edge is visible to operators.
 *
 * @param list<string> $hostnames
 */
function _stattic_runtime_purge_dispatch(
    string $privateRoot,
    array $hostnames,
    string $reason
): bool {
    $endpoint = _stattic_runtime_edge_purge_endpoint();
    if ($endpoint === null) {
        return true;
    }

    $allAccepted = true;
    $failedHosts = [];
    foreach ($hostnames as $hostname) {
        if (!_stattic_runtime_edge_purge_host($endpoint, $hostname, $reason)) {
            $allAccepted = false;
            $failedHosts[] = $hostname;
        }
    }

    if ($failedHosts !== []) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'edge_purge_failed',
            'mode' => 'domain',
            'reason' => $reason,
            'hostnames' => $failedHosts,
        ]);
    }
    return $allAccepted;
}

/**
 * `_stattic_runtime_purge_now` addressed at every hostname one space's cached
 * bytes could live under: route intent plus tombstones, read off the space root
 * so the visitor lane can call it without the management readers. A space no
 * hostname has ever served is unaddressable at the provider, so there is no
 * edge entry to drop and 'none' says so.
 *
 * @return array{status: string, mode: string}
 */
function _stattic_runtime_purge_space_hosts_now(
    string $privateRoot,
    string $spaceId,
    string $reason
): array {
    $hostnames = _stattic_runtime_space_sweep_hostnames(_stattic_space_root($privateRoot, $spaceId));
    if ($hostnames === []) {
        return ['status' => 'ok', 'mode' => 'none'];
    }
    return _stattic_runtime_purge_now($privateRoot, [
        'hostnames' => $hostnames,
        'reason' => $reason,
    ]);
}
