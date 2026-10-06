<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';

// Each affected hostname has one durable pending record. Post-response work
// delivers it promptly; engine housekeeping recovers after request or provider
// failure. A later mutation replaces the generation, so an older delivery
// cannot acknowledge newer work. Records survive Space/version deletion.
//
// Purges are whole-host: the provider keys on host+path+query, while its URI
// purge cannot address every query variant.
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

function _stattic_runtime_require_edge_purge_endpoint(string $sapi = PHP_SAPI): ?array
{
    $endpoint = _stattic_runtime_edge_purge_endpoint();
    // The CLI and local PHP server have no provider edge. Web requests on the
    // deployed runtime must refuse serving mutations when credentials vanish.
    if ($endpoint === null && !in_array($sapi, ['cli', 'cli-server'], true)) {
        throw new RuntimeException('edge_purge_endpoint_unavailable');
    }
    return $endpoint;
}

/**
 * One whole-host purge POST to the local gateway for one hostname. Mirrors the
 * body the platform's Edge_Cache adapter sends (class-edge-cache-atomic.php).
 * Success is the gateway's `{"message":"OK"}`; a bounded retry covers a
 * loopback blip.
 */
function _stattic_runtime_edge_purge_host(array $endpoint, string $hostname, string $reason, float $deadline): bool
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
        $remainingMs = (int) (($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            return false;
        }
        $request['timeout_ms'] = min(5000, $remainingMs);
        $request['connect_timeout_ms'] = min(2000, $remainingMs);
        $result = _stattic_http_request($request);
        if ($result['ok'] && $result['status'] >= 200 && $result['status'] < 300
            && (json_decode((string) $result['body'], true)['message'] ?? null) === 'OK') {
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

// Any anonymous-access change may narrow a path or version target even while
// another public grant remains. Sweep all aliases unless exposure is unchanged
// or the previous configuration admitted no public responses.
function _stattic_runtime_exposure_needs_sweep(?array $previous, ?array $next): bool
{
    if (is_array($previous) && ($previous['public'] ?? null) === false) {
        return false;
    }
    return $previous === null || $next === null || $previous != $next;
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
    try {
        return _stattic_runtime_access_sweep_hostnames(
            _stattic_runtime_space_routing_doc($spaceRoot, 'intent'),
            _stattic_runtime_space_routing_doc($spaceRoot, 'tombstones'),
        );
    } catch (RuntimeException $error) {
        throw new RuntimeException('edge_purge_hostnames_unavailable', 0, $error);
    }
}

/** @return list<string> Every Space's sweep hostnames, normalized. */
function _stattic_runtime_all_space_sweep_hostnames(string $privateRoot): array
{
    $hostnames = [];
    foreach (_stattic_runtime_space_roots_strict($privateRoot) as $spaceRoot) {
        $hostnames = [...$hostnames, ..._stattic_runtime_space_sweep_hostnames($spaceRoot)];
    }
    return _stattic_runtime_purge_hostname_list($hostnames);
}

function _stattic_runtime_purge_store(string $privateRoot): array
{
    return _stattic_record_store($privateRoot . '/runtime/edge-purges');
}

function _stattic_runtime_purge_read(array $store, string $hostname): ?array
{
    $read = _stattic_record_store_read($store, $hostname);
    if ($read['state'] === 'unavailable') {
        throw new RuntimeException('edge_purge_record_unavailable');
    }
    return $read['record'];
}

/** Persist before returning a receipt or starting any provider request. */
function _stattic_runtime_purge_enqueue(string $privateRoot, array $hostnames, string $reason, array $mutationLocks = []): void
{
    $store = _stattic_runtime_purge_store($privateRoot);
    _stattic_record_store_ensure($store);
    foreach ($hostnames as $hostname) {
        _stattic_lock_with(
            _stattic_lock_stripe_path($store['root'], $hostname),
            STATTIC_LOCK_WAIT,
            static fn () => throw new RuntimeException('edge_purge_queue_busy'),
            static function () use ($store, $hostname, $reason, $mutationLocks): void {
                $previous = _stattic_runtime_purge_read($store, $hostname);
                _stattic_record_store_put($store, $hostname, [
                    'generation' => bin2hex(random_bytes(16)),
                    'mutation_locks' => array_values(array_unique([
                        ...($previous['mutation_locks'] ?? []),
                        ...$mutationLocks,
                    ])),
                    'reason' => $reason,
                    'created_at' => is_array($previous) ? ($previous['created_at'] ?? time()) : time(),
                    'attempts' => 0,
                    'next_attempt_at' => 0,
                ]);
            },
        );
    }
}

// Call while holding the Space write lock, before the first serving mutation.
// Recovery retains the hostname set even if the mutation deletes its metadata.
function _stattic_runtime_prepare_purge(string $privateRoot, string $spaceId, array $hostnames, string $reason): void
{
    if (_stattic_runtime_require_edge_purge_endpoint() !== null) {
        _stattic_runtime_purge_enqueue($privateRoot, _stattic_runtime_purge_hostname_list($hostnames), $reason, [_stattic_space_write_lock_path($privateRoot, $spaceId)]);
    }
}

/**
 * Returns true only when every selected hostname has no pending generation.
 * The network call holds a delivery lock, not the writer lock. New mutations
 * can enqueue while it runs; completion compares generations under the writer
 * lock. A killed process releases its locks and leaves its record for retry.
 */
function _stattic_runtime_purge_drain(string $privateRoot, float $deadline, ?int $now = null, ?array $hostnames = null): bool
{
    $store = _stattic_runtime_purge_store($privateRoot);
    if (!file_exists($store['root'])) {
        return true;
    }
    if (!is_dir($store['root']) || !is_readable($store['root'])) {
        throw new RuntimeException('edge_purge_queue_unavailable');
    }
    $endpoint = _stattic_runtime_edge_purge_endpoint();
    $now ??= time();
    $complete = true;
    if ($hostnames === null) {
        // Retry the least recently attempted work first. A failing hostname
        // must not consume every cron budget ahead of untouched hosts.
        $due = [];
        foreach (_stattic_record_store_ids($store) as $hostname) {
            $record = _stattic_runtime_purge_read($store, $hostname);
            if ($record !== null) {
                $due[$hostname] = (int) ($record['next_attempt_at'] ?? 0);
            }
        }
        asort($due, SORT_NUMERIC);
        $hostnames = array_keys($due);
    }
    foreach ($hostnames as $hostname) {
        if (microtime(true) >= $deadline) {
            return false;
        }
        $settled = _stattic_lock_with(
            _stattic_lock_stripe_path($store['root'], $hostname, 'delivery-'),
            STATTIC_LOCK_TRY,
            static fn (): bool => false,
            static function () use ($store, $hostname, $endpoint, $privateRoot, $deadline, $now): bool {
                $record = _stattic_runtime_purge_read($store, $hostname);
                if ($record === null) {
                    return true;
                }
                if (!is_array($record) || !is_string($record['generation'] ?? null) || !is_string($record['reason'] ?? null)) {
                    throw new RuntimeException('edge_purge_record_invalid');
                }
                if (($record['next_attempt_at'] ?? 0) > $now || $endpoint === null) {
                    return false;
                }
                // An obligation may be persisted before its serving mutation.
                // Wait for that writer to finish (or die) before sending it.
                foreach ($record['mutation_locks'] ?? [] as $mutationLock) {
                    if (!_stattic_lock_with($mutationLock, STATTIC_LOCK_TRY,
                        static fn (): bool => false, static fn (): bool => true)) {
                        return false;
                    }
                }
                $accepted = _stattic_runtime_edge_purge_host($endpoint, $hostname, $record['reason'], $deadline);
                return _stattic_lock_with(
                    _stattic_lock_stripe_path($store['root'], $hostname),
                    STATTIC_LOCK_WAIT,
                    static fn () => throw new RuntimeException('edge_purge_queue_busy'),
                    static function () use ($store, $hostname, $privateRoot, $record, $accepted, $now): bool {
                        $current = _stattic_runtime_purge_read($store, $hostname);
                        if (!is_array($current) || ($current['generation'] ?? null) !== $record['generation']) {
                            return false;
                        }
                        if ($accepted) {
                            _stattic_record_store_delete($store, $hostname);
                            return true;
                        }
                        $attempts = ((int) ($record['attempts'] ?? 0)) + 1;
                        _stattic_record_store_put($store, $hostname, [
                            ...$record,
                            'attempts' => $attempts,
                            'next_attempt_at' => $now + min(300, 5 * (2 ** min(6, $attempts - 1))),
                        ]);
                        _stattic_runtime_append_journal($privateRoot, [
                            'event' => 'edge_purge_failed',
                            'mode' => 'domain',
                            'reason' => $record['reason'],
                            'hostnames' => [$hostname],
                            'attempts' => $attempts,
                        ]);
                        return false;
                    },
                );
            },
        );
        $complete = $settled && $complete;
    }
    return $complete;
}

/** @return array{status:string, mode:string, urls?:int, hosts?:int} */
function _stattic_runtime_purge_now(string $privateRoot, array $input): array
{
    $hostnames = _stattic_runtime_purge_hostname_list($input['hostnames'] ?? null);
    $reason = is_string($input['reason'] ?? null) ? $input['reason'] : 'runtime_mutation';
    if ($hostnames === [] || _stattic_runtime_require_edge_purge_endpoint() === null) {
        // A zero-URL receipt retains the old control-plane wire enum while
        // saying exactly how much provider work was needed.
        return ['status' => 'ok', 'mode' => 'urls', 'urls' => 0];
    }
    _stattic_runtime_purge_enqueue($privateRoot, $hostnames, $reason);
    $run = static fn (): bool => _stattic_runtime_purge_drain($privateRoot, microtime(true) + 20, null, $hostnames);
    if (function_exists('fastcgi_finish_request')) {
        _stattic_flush_response_before_deferred(true);
        _stattic_defer($run);
        $status = 'queued';
    } else {
        $status = $run() ? 'ok' : 'queued';
    }
    // The host count lets the control plane log what each mutation cost the edge.
    return ['status' => $status, 'mode' => 'domain', 'hosts' => count($hostnames)];
}

/** Synchronous callers share the same persisted retry ownership. */
function _stattic_runtime_purge_dispatch(string $privateRoot, array $hostnames, string $reason): bool
{
    if (_stattic_runtime_require_edge_purge_endpoint() === null) {
        return true;
    }
    $hostnames = _stattic_runtime_purge_hostname_list($hostnames);
    _stattic_runtime_purge_enqueue($privateRoot, $hostnames, $reason);
    return _stattic_runtime_purge_drain($privateRoot, microtime(true) + 20, null, $hostnames);
}
