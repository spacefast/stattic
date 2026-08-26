<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/finalizer-protocol.generated.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/native-process.php';
// A published capsule's schema is created here, over the same MySQL broker the
// serving lanes use, never a subprocess.
require_once __DIR__ . '/../shared/db-broker.php';

function _stattic_runtime_routes_from_hostname_intent(string $routeName, array $intent): array
{
    $productionHostnames = _stattic_runtime_hostname_list($intent['production_hostnames'] ?? []);
    $noindexProductionHostnames = array_fill_keys(_stattic_runtime_hostname_list($intent['noindex_production_hostnames'] ?? []), true);
    $versionHostnames = _stattic_runtime_version_hostname_entries($intent['version_hostnames'] ?? []);
    $served = array_fill_keys($productionHostnames, true) + array_fill_keys(array_keys($versionHostnames), true);
    // Spec "Routing And Headers": version URLs and non-production hosts are ALWAYS
    // noindex; _headers can never override X-Robots-Tag on noindex-classed hosts.
    $routes = [];
    foreach ($productionHostnames as $hostname) {
        $routes[] = [
            'hostname' => $hostname,
            'path_prefix' => '/',
            'mount' => 'strip_prefix',
            'target' => ['type' => 'route', 'route_name' => $routeName],
            'options' => ['noindex' => $routeName !== 'production' || isset($noindexProductionHostnames[$hostname])],
        ];
    }
    // The intent is the complete retained-version map, not just the version being
    // activated, so activating a new version never orphans older ver- URLs.
    foreach ($versionHostnames as $hostname => $targetVersionId) {
        $routes[] = [
            'hostname' => $hostname,
            'path_prefix' => '/',
            'mount' => 'strip_prefix',
            'target' => ['type' => 'version', 'version_id' => $targetVersionId],
            'options' => ['noindex' => true],
        ];
    }
    foreach (_stattic_runtime_host_canonical_redirects($intent['host_canonical_redirects'] ?? [], $served) as $route) {
        $routes[] = $route;
    }
    foreach (_stattic_runtime_proxy_host_routes($intent['proxy_host_routes'] ?? []) as $route) {
        $routes[] = $route;
    }
    return $routes;
}

function _stattic_runtime_proxy_host_routes(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'invalid_proxy_host_routes', 'Proxy host routes must be an array.');
    }
    $routes = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !is_string($entry['hostname'] ?? null) || !is_string($entry['upstream'] ?? null)) {
            _stattic_problem_response(422, 'invalid_proxy_host_route', 'Proxy host route entries must carry hostname and upstream.');
        }
        $hostname = _stattic_runtime_normalize_route_hostname($entry['hostname']);
        $pathPrefix = _stattic_runtime_route_path_prefix($entry);
        $upstream = (string) $entry['upstream'];
        $key = $hostname . "\n" . $pathPrefix;
        if ($hostname === '' || isset($seen[$key]) || !_stattic_runtime_proxy_upstream_public($upstream)) {
            _stattic_problem_response(422, 'invalid_proxy_host_route', 'Proxy host route is invalid.');
        }
        $seen[$key] = true;
        $routes[] = [
            'hostname' => $hostname,
            'path_prefix' => $pathPrefix,
            'mount' => 'strip_prefix',
            'target' => ['type' => 'host_proxy', 'upstream' => $upstream],
            'options' => ['noindex' => !empty($entry['noindex'])],
        ];
    }
    return $routes;
}

function _stattic_runtime_host_canonical_redirects(mixed $raw, array $served): array
{
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'invalid_host_canonical_redirects', 'Host canonical redirects must be an array.');
    }
    $routes = [];
    $seen = [];
    foreach ($raw as $redirect) {
        if (!is_array($redirect) || !is_string($redirect['from'] ?? null) || !is_string($redirect['to'] ?? null)) {
            _stattic_problem_response(422, 'invalid_host_canonical_redirect', 'Host canonical redirect is invalid.');
        }
        $from = _stattic_runtime_normalize_route_hostname($redirect['from']);
        $to = trim($redirect['to']);
        $status = (int) ($redirect['status'] ?? 308);
        if (
            $from === ''
            || $to === ''
            || !in_array($status, [301, 302, 307, 308], true)
            || !_stattic_runtime_redirect_target_safe($to, 'redirect')
        ) {
            _stattic_problem_response(422, 'invalid_host_canonical_redirect', 'Host canonical redirect is invalid.');
        }
        // A host that is served directly must never be canonicalized away.
        if (isset($served[$from]) || isset($seen[$from])) {
            continue;
        }
        $seen[$from] = true;
        $routes[] = [
            'hostname' => $from,
            'path_prefix' => '/',
            'mount' => 'strip_prefix',
            'target' => ['type' => 'host_redirect', 'destination' => $to, 'status' => $status],
            'options' => ['noindex' => false],
        ];
    }
    return $routes;
}

function _stattic_runtime_version_hostname_entries(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'invalid_version_hostnames', 'Version hostnames must be an array.');
    }
    $entries = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !is_string($entry['hostname'] ?? null) || !is_string($entry['version_id'] ?? null)) {
            _stattic_problem_response(422, 'invalid_version_hostname', 'Version hostname entries must carry hostname and version_id.');
        }
        $normalized = _stattic_runtime_normalize_route_hostname($entry['hostname']);
        $targetVersionId = _stattic_runtime_id($entry['version_id'], 'version_id');
        if ($normalized === '') {
            _stattic_problem_response(422, 'invalid_version_hostname', 'Version hostname is invalid.');
        }
        $entries[$normalized] = $targetVersionId;
    }
    return $entries;
}

function _stattic_runtime_hostname_list(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'invalid_hostnames', 'Hostnames must be an array.');
    }
    $hostnames = [];
    foreach ($raw as $hostname) {
        if (!is_string($hostname)) {
            _stattic_problem_response(422, 'invalid_hostname', 'Hostname is invalid.');
        }
        $normalized = _stattic_runtime_normalize_route_hostname($hostname);
        if ($normalized === '') {
            _stattic_problem_response(422, 'invalid_hostname', 'Hostname is invalid.');
        }
        $hostnames[$normalized] = true;
    }
    return array_keys($hostnames);
}

function _stattic_runtime_store_hostname_intent(string $privateRoot, string $spaceId, array $rawRoutes, array $claims = []): void
{
    $previousIntent = _stattic_runtime_read_json_strict(_stattic_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
    $routes = _stattic_runtime_normalize_hostname_intent_routes($rawRoutes);
    _stattic_runtime_store_hostname_intent_from_snapshot(
        $privateRoot,
        $spaceId,
        $routes,
        $previousIntent,
        $claims,
    );
}

function _stattic_runtime_normalize_hostname_intent_routes(array $rawRoutes): array
{
    $routes = [];
    foreach ($rawRoutes as $route) {
        if (!is_array($route)) {
            _stattic_problem_response(422, 'invalid_route', 'Each route must be an object.');
        }
        $routes[] = _stattic_runtime_normalize_route($route);
    }
    return $routes;
}

// Activation already owns a strict preflight snapshot. Reusing it here keeps
// every potentially failing read before the route/access mutation, while the
// public wrapper above preserves the same preflight for standalone callers.
function _stattic_runtime_store_hostname_intent_from_snapshot(
    string $privateRoot,
    string $spaceId,
    array $routes,
    mixed $previousIntent,
    array $claims = [],
): void {
    if ($previousIntent !== null && (!is_array($previousIntent) || !is_array($previousIntent['routes'] ?? null))) {
        throw new RuntimeException('runtime hostname intent is invalid');
    }
    $previousRoutes = is_array($previousIntent) && is_array($previousIntent['routes'] ?? null)
        ? $previousIntent['routes']
        : [];
    $changedHostnames = _stattic_runtime_hostname_intent_repoint_diff($previousRoutes, $routes);
    _stattic_runtime_write_json_atomic(_stattic_space_root($privateRoot, $spaceId) . '/hostname-intent.json', [
        'space_id' => $spaceId,
        'routes' => $routes,
        'updated_at' => gmdate('c'),
    ]);
    // A diagnostic in every caller shape: with claims it names the operation
    // that caused it, without them a bare record. Never a delivery.
    _stattic_runtime_journal_management_diagnostic($privateRoot, $claims, [
        'event' => 'space_hostname_intent_updated',
        'space_id' => $spaceId,
        'route_count' => count($routes),
        'hostnames' => $changedHostnames,
    ]);
}

function _stattic_runtime_hostname_intent_repoint_diff(array $previousRoutes, array $routes): array
{
    $previousTargets = _stattic_runtime_hostname_intent_targets($previousRoutes);
    $targets = _stattic_runtime_hostname_intent_targets($routes);
    $changed = [];
    foreach ($previousTargets as $hostname => $target) {
        if (!array_key_exists($hostname, $targets) || $targets[$hostname] !== $target) {
            $changed[] = $hostname;
        }
    }
    return $changed;
}

// Both call sites pass already-normalized routes; re-running normalization here
// would let a read of stored state 422.
function _stattic_runtime_hostname_intent_targets(array $routes): array
{
    $targets = [];
    foreach ($routes as $route) {
        if (!is_array($route) || !is_string($route['hostname'] ?? null)) {
            continue;
        }
        $targets[$route['hostname']][] = [
            'path_prefix' => is_string($route['path_prefix'] ?? null) ? $route['path_prefix'] : '/',
            'mount' => is_string($route['mount'] ?? null) ? $route['mount'] : 'strip_prefix',
            'target' => is_array($route['target'] ?? null) ? $route['target'] : [],
        ];
    }
    foreach ($targets as &$entries) {
        // asort is stable, so equal encodings (identical entries) keep their order.
        $order = array_map(static fn (array $entry): string => (string) json_encode($entry), $entries);
        asort($order, SORT_STRING);
        $entries = array_values(array_replace($order, $entries));
    }
    unset($entries);
    return $targets;
}

// Rides the journal event so the control plane can scope provider purges
// (spec "Cache Management").
function _stattic_runtime_affected_intent_hostnames(string $privateRoot, string $spaceId, ?string $routeName, ?string $versionId = null): array
{
    $intent = _stattic_runtime_read_json_strict(_stattic_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
    if ($intent === null) {
        return [];
    }
    if (!is_array($intent) || !is_array($intent['routes'] ?? null)) {
        throw new RuntimeException('runtime hostname intent is invalid');
    }
    return _stattic_runtime_affected_intent_hostnames_from_routes($intent['routes'], $routeName, $versionId);
}

function _stattic_runtime_affected_intent_hostnames_from_routes(array $routes, ?string $routeName, ?string $versionId = null): array
{
    $hostnames = [];
    foreach ($routes as $route) {
        if (!is_array($route) || !is_string($route['hostname'] ?? null)) {
            continue;
        }
        $target = is_array($route['target'] ?? null) ? $route['target'] : [];
        $matchesRoute = $routeName !== null
            && ($target['type'] ?? null) === 'route'
            && ($target['route_name'] ?? null) === $routeName;
        $matchesVersion = $versionId !== null
            && ($target['type'] ?? null) === 'version'
            && ($target['version_id'] ?? null) === $versionId;
        $matchesHostRoute = $routeName !== null
            && in_array(($target['type'] ?? null), ['host_proxy', 'host_redirect'], true);
        if ($matchesRoute || $matchesVersion || $matchesHostRoute) {
            $hostnames[$route['hostname']] = true;
        }
    }
    return array_keys($hostnames);
}

// Mirrors RUNTIME_CHANGED_PATHS_MAX in the shared contract (parity-guarded in
// apps/control-plane/src/runtime/php-policy-parity.test.ts).
const STATTIC_RUNTIME_CHANGED_PATHS_MAX = 900;

// An invalid or over-cap set degrades to []: a host-wide purge, never a
// rejected mutation.
function _stattic_runtime_changed_path_list(mixed $raw, ?bool &$known = null): array
{
    $known = false;
    if (!is_array($raw) || count($raw) > STATTIC_RUNTIME_CHANGED_PATHS_MAX) {
        return [];
    }
    $paths = [];
    foreach ($raw as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > 2048) {
            return [];
        }
        $paths[$path] = true;
    }
    $known = true;
    return array_keys($paths);
}

function _stattic_runtime_store_space_tombstones(string $privateRoot, string $spaceId, array $hostnames, string $mode = 'replace', ?string $reason = null, ?string $category = null): int
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $tombstonePath = _stattic_space_root($privateRoot, $spaceId) . '/tombstones.json';
    $tombstones = [];
    $existing = _stattic_runtime_read_json_strict($tombstonePath);
    if ($mode !== 'replace') {
        if (is_array($existing) && is_array($existing['hostnames'] ?? null)) {
            foreach ($existing['hostnames'] as $hostname) {
                if (!is_string($hostname)) {
                    continue;
                }
                $normalized = _stattic_runtime_normalize_route_hostname($hostname);
                if ($normalized !== '') {
                    $tombstones[$normalized] = true;
                }
            }
        }
    }
    foreach ($hostnames as $hostname) {
        if (!is_string($hostname)) {
            continue;
        }
        $normalized = _stattic_runtime_normalize_route_hostname($hostname);
        if ($normalized === '') {
            _stattic_problem_response(422, 'invalid_hostname', 'Hostname is invalid.');
        }
        if ($mode === 'remove') {
            unset($tombstones[$normalized]);
        } else {
            $tombstones[$normalized] = true;
        }
    }
    // Absence preserves existing metadata so a follow-up host edit never silently
    // downgrades a CSAM/DMCA tombstone to the generic page.
    if ($reason === null && is_array($existing) && is_string($existing['reason'] ?? null)) {
        $reason = $existing['reason'];
    }
    if ($category === null && is_array($existing) && is_string($existing['category'] ?? null)) {
        $category = $existing['category'];
    }
    $record = [
        'space_id' => $spaceId,
        'hostnames' => array_keys($tombstones),
        'updated_at' => gmdate('c'),
    ];
    if ($reason !== null && $reason !== '') {
        $record['reason'] = $reason;
    }
    if ($category !== null && $category !== '') {
        $record['category'] = $category;
    }
    _stattic_runtime_write_json_atomic($tombstonePath, $record);
    return count($tombstones);
}

function _stattic_runtime_normalize_route(array $route): array
{
    $hostname = isset($route['hostname']) && is_string($route['hostname'])
        ? _stattic_runtime_normalize_route_hostname($route['hostname'])
        : '';
    if ($hostname === '') {
        _stattic_problem_response(422, 'invalid_hostname', 'Route hostname is invalid.');
    }
    $pathPrefix = _stattic_runtime_route_path_prefix($route);
    $mount = isset($route['mount']) && is_string($route['mount']) && in_array($route['mount'], ['strip_prefix', 'preserve_path'], true)
        ? $route['mount']
        : 'strip_prefix';
    $target = is_array($route['target'] ?? null) ? $route['target'] : null;
    if (!is_array($target) || !is_string($target['type'] ?? null)) {
        _stattic_problem_response(422, 'invalid_route_target', 'Route target is invalid.');
    }
    if ($target['type'] === 'version') {
        if (!is_string($target['version_id'] ?? null)) {
            _stattic_problem_response(422, 'invalid_route_target', 'Version route target requires version_id.');
        }
        $normalizedTarget = [
            'type' => 'version',
            'version_id' => _stattic_runtime_id($target['version_id'], 'version_id'),
        ];
    } elseif ($target['type'] === 'route') {
        if (!is_string($target['route_name'] ?? null)) {
            _stattic_problem_response(422, 'invalid_route_target', 'Route pointer target requires route_name.');
        }
        $normalizedTarget = [
            'type' => 'route',
            'route_name' => _stattic_runtime_id($target['route_name'], 'route_name'),
        ];
    } elseif ($target['type'] === 'host_redirect') {
        $destination = is_string($target['destination'] ?? null) ? $target['destination'] : '';
        $status = (int) ($target['status'] ?? 308);
        if (
            $destination === ''
            || !in_array($status, [301, 302, 307, 308], true)
            || !_stattic_runtime_redirect_target_safe($destination, 'redirect')
        ) {
            _stattic_problem_response(422, 'invalid_route_target', 'Host redirect route target is invalid.');
        }
        $normalizedTarget = [
            'type' => 'host_redirect',
            'destination' => $destination,
            'status' => $status,
        ];
    } elseif ($target['type'] === 'host_proxy') {
        $upstream = is_string($target['upstream'] ?? null) ? $target['upstream'] : '';
        if ($upstream === '' || !_stattic_runtime_proxy_upstream_public($upstream)) {
            _stattic_problem_response(422, 'invalid_route_target', 'Host proxy route target is invalid.');
        }
        $normalizedTarget = [
            'type' => 'host_proxy',
            'upstream' => $upstream,
        ];
    } else {
        _stattic_problem_response(422, 'invalid_route_target', 'Route target type is invalid.');
    }
    $options = is_array($route['options'] ?? null) ? $route['options'] : [];
    return [
        'hostname' => $hostname,
        'path_prefix' => $pathPrefix,
        'mount' => $mount,
        'target' => $normalizedTarget,
        'options' => [
            'noindex' => !empty($options['noindex']),
        ],
    ];
}

function _stattic_runtime_normalize_route_hostname(string $hostname): string
{
    $hostname = _stattic_normalize_hostname(trim($hostname));
    return preg_match('/^(\\*\\.)?[a-z0-9.-]{1,253}$/', $hostname) === 1 ? $hostname : '';
}

function _stattic_runtime_normalize_route_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return '/';
    }
    $normalized = _stattic_canonical_request_path('/' . trim($path, '/'));
    if ($normalized === null) {
        _stattic_problem_response(422, 'invalid_route_path', 'Route path is invalid.');
    }
    return $normalized;
}

function _stattic_runtime_route_path_prefix(array $route): string
{
    return isset($route['path_prefix']) && is_string($route['path_prefix'])
        ? _stattic_runtime_normalize_route_path($route['path_prefix'])
        : '/';
}

// All route-index writes serialize on routes/index.lock (innermost lock;
// site -> space -> index ordering, see admin/management.php), so no two
// mutations interleave a read-modify-write of the pointer. Callers already
// holding the lock use the _unlocked variant.
function _stattic_runtime_rebuild_route_index(string $privateRoot): void
{
    _stattic_runtime_with_route_index_lock($privateRoot, static function () use ($privateRoot): void {
        _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
    });
}

function _stattic_runtime_rebuild_route_index_unlocked(string $privateRoot): void
{
    $contributions = [];
    foreach (_stattic_runtime_space_roots_strict($privateRoot) as $spaceRoot) {
        $spaceId = basename((string) $spaceRoot);
        _stattic_runtime_sync_space_overlay($privateRoot, $spaceId);
        $contribution = _stattic_runtime_space_index_contribution($privateRoot, $spaceId);
        if (_stattic_runtime_contribution_hostnames($contribution) !== []) {
            $contributions[] = $contribution;
        }
    }
    $merged = _stattic_runtime_merge_host_contributions($contributions);
    $split = _stattic_runtime_split_route_index($merged['hostnames'], $merged['host_routes']);
    _stattic_runtime_write_route_index(
        $privateRoot,
        $split['shards'],
        [],
        ['fresh' => $split['wildcards']],
        _stattic_runtime_contribution_owners($contributions)
    );
}

// Any unexpected on-disk state falls back to a full rebuild.
function _stattic_runtime_update_route_index(string $privateRoot, string $spaceId): void
{
    _stattic_runtime_with_route_index_lock($privateRoot, static function () use ($privateRoot, $spaceId): void {
        _stattic_runtime_update_route_index_unlocked($privateRoot, $spaceId);
    });
}

function _stattic_runtime_update_route_index_unlocked(string $privateRoot, string $spaceId): void
{
    // The overlay carries this Space's access state and route->version map, and
    // is what the serve path reads for both; it swaps before the route pointer
    // so a host that becomes reachable already has its access answer on disk.
    _stattic_runtime_sync_space_overlay($privateRoot, $spaceId);

    $current = _stattic_runtime_read_route_pointer($privateRoot);
    if ($current === false) {
        throw new RuntimeException('route index read failed: ' . $privateRoot . '/routes/current.json');
    }
    $shardManifest = is_array($current['shards'] ?? null) ? $current['shards'] : null;
    $wildcardsName = is_string($current['wildcards'] ?? null) ? $current['wildcards'] : null;
    $owners = _stattic_runtime_read_route_owners($privateRoot, $current);
    if ($current === null || $shardManifest === null || $wildcardsName === null || $owners === null) {
        _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
        return;
    }

    $contributions = [$spaceId => _stattic_runtime_space_index_contribution($privateRoot, $spaceId)];
    $affected = array_fill_keys(_stattic_runtime_contribution_hostnames($contributions[$spaceId]), true);
    foreach ($owners as $hostname => $owningSpaces) {
        if (is_string($hostname) && is_array($owningSpaces) && in_array($spaceId, $owningSpaces, true)) {
            $affected[$hostname] = true;
        }
    }
    if ($affected === []) {
        return;
    }

    // The merge sorts contributors by space id (the full rebuild's glob order) so
    // cross-space results (serve-wins, tombstone fallback) are identical.
    $updatedHostnames = [];
    $updatedHostRoutes = [];
    foreach (array_keys($affected) as $hostname) {
        $hostname = (string) $hostname;
        $contributors = [$spaceId => true];
        foreach (($owners[$hostname] ?? []) as $owner) {
            if (is_string($owner)) {
                $contributors[$owner] = true;
            }
        }
        $hostContributions = [];
        foreach (array_keys($contributors) as $contributor) {
            $contributor = (string) $contributor;
            $contributions[$contributor] ??= _stattic_runtime_space_index_contribution($privateRoot, $contributor);
            $hostContributions[] = _stattic_runtime_filter_contribution_to_hostname($contributions[$contributor], $hostname);
        }
        $merged = _stattic_runtime_merge_host_contributions($hostContributions);
        if (isset($merged['hostnames'][$hostname])) {
            $updatedHostnames[$hostname] = $merged['hostnames'][$hostname];
        }
        if (($merged['host_routes'][$hostname] ?? []) !== []) {
            $updatedHostRoutes[$hostname] = $merged['host_routes'][$hostname];
        }
    }

    $affectedShards = [];
    $wildcardAffected = false;
    foreach (array_keys($affected) as $hostname) {
        if (str_contains((string) $hostname, '*')) {
            $wildcardAffected = true;
        } else {
            $affectedShards[_stattic_runtime_route_host_shard((string) $hostname)] = true;
        }
    }

    $freshShards = [];
    $reusedShards = [];
    foreach ($shardManifest as $shard => $shardFile) {
        // PHP normalizes numeric-string array keys (e.g. shard "36") to ints.
        $shard = (string) $shard;
        if (
            preg_match('/^[a-f0-9]{2}$/', $shard) !== 1
            || !is_string($shardFile)
            || !is_file($privateRoot . '/routes/' . $shardFile)
        ) {
            _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
            return;
        }
        if (!isset($affectedShards[$shard])) {
            $reusedShards[$shard] = $shardFile;
        }
    }
    foreach (array_keys($affectedShards) as $shard) {
        $shard = (string) $shard;
        $contents = ['hostnames' => [], 'host_routes' => []];
        if (isset($shardManifest[$shard])) {
            $existing = _stattic_runtime_read_route_shard($privateRoot . '/routes/' . $shardManifest[$shard]);
            if ($existing === null) {
                _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
                return;
            }
            $contents = $existing;
        }
        $freshShards[$shard] = _stattic_runtime_apply_host_updates($contents, $shard, $affected, $updatedHostnames, $updatedHostRoutes);
    }

    if ($wildcardAffected) {
        $contents = _stattic_runtime_read_route_shard($privateRoot . '/routes/' . $wildcardsName);
        if ($contents === null) {
            _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
            return;
        }
        $wildcards = ['fresh' => _stattic_runtime_apply_host_updates($contents, null, $affected, $updatedHostnames, $updatedHostRoutes)];
    } else {
        $wildcards = ['reuse' => $wildcardsName, 'has_wildcards' => !empty($current['has_wildcards'])];
    }

    $newOwners = [];
    foreach ($owners as $hostname => $owningSpaces) {
        if (!is_string($hostname) || !is_array($owningSpaces)) {
            continue;
        }
        $kept = array_values(array_filter($owningSpaces, static fn ($owner): bool => is_string($owner) && $owner !== $spaceId));
        if ($kept !== []) {
            $newOwners[$hostname] = $kept;
        }
    }
    foreach (_stattic_runtime_contribution_hostnames($contributions[$spaceId]) as $hostname) {
        $merged = array_unique([...($newOwners[$hostname] ?? []), $spaceId]);
        sort($merged);
        $newOwners[$hostname] = array_values($merged);
    }
    ksort($newOwners);

    _stattic_runtime_write_route_index($privateRoot, $freshShards, $reusedShards, $wildcards, $newOwners);
}

// ENOENT is a normal "no document" answer; a read FAILURE of an existing
// document must abort the operation that needed it. Publishing a route index,
// purge set, or tombstone state derived from a failed read deletes live state
// (the management route answers 5xx and the control plane retries; the
// maintenance tick journals maintenance_step_failed). Falling back to a full
// rebuild is NOT a safe disposition either: the rebuild does the same reads.
function _stattic_runtime_read_json_strict(string $path): mixed
{
    $decoded = _stattic_runtime_read_json($path);
    if ($decoded === false) {
        throw new RuntimeException('runtime document read failed: ' . $path);
    }
    return $decoded;
}

/**
 * @return list<string> absolute child paths
 */
function _stattic_runtime_directory_entries_strict(string $root): array
{
    $paths = _stattic_runtime_directory_entries($root);
    if ($paths === null) {
        throw new RuntimeException('runtime document enumeration failed: ' . $root);
    }
    return $paths;
}

// Read under the index lock: this is the read half of a read-modify-write.
// false = current.json exists but could not be read. The caller must abort,
// never treat it as gen 0.
function _stattic_runtime_read_route_pointer(string $privateRoot): array|false|null
{
    $pointer = _stattic_runtime_read_json($privateRoot . '/routes/current.json');
    if ($pointer === false) {
        return false;
    }
    return is_array($pointer) && is_int($pointer['gen'] ?? null) ? $pointer : null;
}

function _stattic_runtime_read_route_owners(string $privateRoot, ?array $pointer): ?array
{
    if (!is_array($pointer) || !is_string($pointer['owners'] ?? null)) {
        return null;
    }
    $path = $privateRoot . '/routes/' . $pointer['owners'];
    $loaded = include $path;
    if (!is_array($loaded)) {
        clearstatcache(true, $path);
        if (!_sf_path_verifiably_absent($path)) {
            throw new RuntimeException('route index read failed: ' . $path);
        }
    }
    return is_array($loaded) && is_array($loaded['owners'] ?? null) ? $loaded['owners'] : null;
}

// Any unexpected on-disk SHAPE returns null. The caller falls back to a full
// rebuild. A failed include of an existing shard aborts instead: the shard's
// bytes are fine, this read is not, and a rebuild would drop its hosts.
function _stattic_runtime_read_route_shard(string $path): ?array
{
    $existing = include $path;
    if (!is_array($existing)) {
        clearstatcache(true, $path);
        if (!_sf_path_verifiably_absent($path)) {
            throw new RuntimeException('route index read failed: ' . $path);
        }
        return null;
    }
    if (
        !is_array($existing['hostnames'] ?? null)
        || !is_array($existing['host_routes'] ?? null)
        || !_stattic_runtime_route_entries_valid($existing['hostnames'], $existing['host_routes'])
    ) {
        return null;
    }
    return ['hostnames' => $existing['hostnames'], 'host_routes' => $existing['host_routes']];
}

// $shard null selects the wildcard payload.
function _stattic_runtime_apply_host_updates(array $contents, ?string $shard, array $affected, array $updatedHostnames, array $updatedHostRoutes): array
{
    foreach (array_keys($affected) as $hostname) {
        $hostname = (string) $hostname;
        $isWildcard = str_contains($hostname, '*');
        if ($shard === null ? !$isWildcard : ($isWildcard || _stattic_runtime_route_host_shard($hostname) !== $shard)) {
            continue;
        }
        unset($contents['hostnames'][$hostname], $contents['host_routes'][$hostname]);
        if (isset($updatedHostnames[$hostname])) {
            $contents['hostnames'][$hostname] = $updatedHostnames[$hostname];
        }
        if (isset($updatedHostRoutes[$hostname])) {
            $contents['host_routes'][$hostname] = $updatedHostRoutes[$hostname];
        }
    }
    return $contents;
}

function _stattic_runtime_space_index_contribution(string $privateRoot, string $spaceId): array
{
    $contribution = ['space_id' => $spaceId, 'hostnames' => [], 'routes' => [], 'canonical' => [], 'tombstones' => [], 'tombstone_reason' => null, 'tombstone_category' => null];
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $routesConfig = _stattic_runtime_read_json_strict($spaceRoot . '/hostname-intent.json');
    if ($routesConfig !== null && (!is_array($routesConfig) || ($routesConfig['space_id'] ?? null) !== $spaceId || !is_array($routesConfig['routes'] ?? null))) {
        throw new RuntimeException('runtime hostname intent is invalid: ' . $spaceRoot);
    }
    if (is_array($routesConfig) && ($routesConfig['space_id'] ?? null) === $spaceId && is_array($routesConfig['routes'] ?? null)) {
        // Safe to memoize: nothing writes pointers while a contribution is built.
        $pointerCache = [];
        foreach ($routesConfig['routes'] as $route) {
            if (!is_array($route)) {
                continue;
            }
            $compiled = _stattic_runtime_compile_route($privateRoot, $spaceId, $route, $pointerCache);
            if (!is_array($compiled)) {
                continue;
            }
            if (!empty($compiled['host_route'])) {
                // Evaluated before user redirect rules; must return before
                // version/file metadata loads.
                $contribution['canonical'][$compiled['hostname']][] = $compiled['route'];
            } elseif (($compiled['route']['location'] ?? '/') === '/') {
                $contribution['hostnames'][$compiled['hostname']][] = $compiled['host_entry'];
            } else {
                $contribution['routes'][$compiled['hostname']][] = $compiled['route'];
            }
        }
    }
    $tombstoneConfig = _stattic_runtime_read_json_strict($spaceRoot . '/tombstones.json');
    if ($tombstoneConfig !== null && (!is_array($tombstoneConfig) || ($tombstoneConfig['space_id'] ?? null) !== $spaceId || !is_array($tombstoneConfig['hostnames'] ?? null))) {
        throw new RuntimeException('runtime tombstones are invalid: ' . $spaceRoot);
    }
    if (is_array($tombstoneConfig) && ($tombstoneConfig['space_id'] ?? null) === $spaceId && is_array($tombstoneConfig['hostnames'] ?? null)) {
        foreach ($tombstoneConfig['hostnames'] as $hostname) {
            if (is_string($hostname) && $hostname !== '') {
                $contribution['tombstones'][] = $hostname;
            }
        }
        $contribution['tombstone_reason'] = is_string($tombstoneConfig['reason'] ?? null) ? $tombstoneConfig['reason'] : null;
        $contribution['tombstone_category'] = is_string($tombstoneConfig['category'] ?? null) ? $tombstoneConfig['category'] : null;
    }
    return $contribution;
}

function _stattic_runtime_contribution_hostnames(array $contribution): array
{
    $hostnames = [];
    foreach (['hostnames', 'routes', 'canonical'] as $field) {
        foreach (array_keys($contribution[$field]) as $hostname) {
            $hostnames[(string) $hostname] = true;
        }
    }
    foreach ($contribution['tombstones'] as $hostname) {
        $hostnames[(string) $hostname] = true;
    }
    return array_keys($hostnames);
}

// hostname => sorted owning space ids; persisted per generation so the
// incremental update can resolve cross-space collisions (a serve entry wins over
// a tombstone, and releasing a hostname must resurface another space's claim).
function _stattic_runtime_contribution_owners(array $contributions): array
{
    $owners = [];
    foreach ($contributions as $contribution) {
        $spaceId = (string) $contribution['space_id'];
        foreach (_stattic_runtime_contribution_hostnames($contribution) as $hostname) {
            $owners[$hostname][$spaceId] = true;
        }
    }
    ksort($owners);
    $sorted = [];
    foreach ($owners as $hostname => $owningSpaces) {
        $spaceIds = array_keys($owningSpaces);
        sort($spaceIds);
        $sorted[$hostname] = $spaceIds;
    }
    return $sorted;
}

function _stattic_runtime_filter_contribution_to_hostname(array $contribution, string $hostname): array
{
    $filtered = [
        'space_id' => $contribution['space_id'],
        'hostnames' => [],
        'routes' => [],
        'canonical' => [],
        'tombstones' => [],
        'tombstone_reason' => $contribution['tombstone_reason'] ?? null,
        'tombstone_category' => $contribution['tombstone_category'] ?? null,
    ];
    foreach (['hostnames', 'routes', 'canonical'] as $field) {
        if (isset($contribution[$field][$hostname])) {
            $filtered[$field][$hostname] = $contribution[$field][$hostname];
        }
    }
    if (in_array($hostname, $contribution['tombstones'], true)) {
        $filtered['tombstones'][] = $hostname;
    }
    return $filtered;
}

// Contributions merge in space-id order (the full rebuild's glob order); intent
// entries merge first across all spaces, tombstones after. A tombstone overrides
// a serve entry owned by the same space (takedown authority), while another
// space's current serve entry wins over the stale tombstone.
function _stattic_runtime_merge_host_contributions(array $contributions): array
{
    usort($contributions, static fn (array $left, array $right): int => strcmp((string) $left['space_id'], (string) $right['space_id']));
    $hostnames = [];
    $hostRoutes = [];
    $hostCanonicalRoutes = [];
    foreach ($contributions as $contribution) {
        // Drop the space's own live claim before collision resolution so another
        // space's claim wins regardless of which space id sorts last.
        $tombstonedHostnames = array_fill_keys($contribution['tombstones'], true);
        foreach ($contribution['hostnames'] as $hostname => $entries) {
            if (isset($tombstonedHostnames[$hostname])) {
                continue;
            }
            foreach ($entries as $entry) {
                _stattic_runtime_set_host_entry($hostnames, (string) $hostname, $entry);
                if (!empty($entry['noindex'])) {
                    $hostRoutes[$hostname][] = _stattic_runtime_noindex_robots_route();
                }
            }
        }
        _stattic_runtime_append_contribution_routes($contribution['routes'], $hostRoutes, $tombstonedHostnames);
        _stattic_runtime_append_contribution_routes($contribution['canonical'], $hostCanonicalRoutes, $tombstonedHostnames);
    }
    foreach ($contributions as $contribution) {
        foreach ($contribution['tombstones'] as $hostname) {
            _stattic_runtime_set_tombstone_host_entry(
                $hostnames,
                (string) $hostname,
                (string) $contribution['space_id'],
                is_string($contribution['tombstone_reason'] ?? null) ? $contribution['tombstone_reason'] : null,
                is_string($contribution['tombstone_category'] ?? null) ? $contribution['tombstone_category'] : null
            );
        }
    }
    foreach ($hostRoutes as &$routes) {
        usort($routes, static function ($left, $right): int {
            $leftLocation = is_array($left) ? (string) ($left['location'] ?? '/') : '/';
            $rightLocation = is_array($right) ? (string) ($right['location'] ?? '/') : '/';
            return strlen($rightLocation) <=> strlen($leftLocation) ?: strcmp($leftLocation, $rightLocation);
        });
    }
    unset($routes);
    // Host canonicalization redirects win over user redirect rules for the same host.
    foreach ($hostCanonicalRoutes as $hostname => $canonicalRoutes) {
        $hostRoutes[$hostname] = array_merge($canonicalRoutes, $hostRoutes[$hostname] ?? []);
    }
    // A host that only has sub-path routes (a pure redirect or proxy host) still
    // needs a hostnames entry: the serve path resolves the Space from there, and
    // with it the overlay that answers access. Without the entry it treats the
    // host as undeployed.
    foreach ($hostRoutes as $hostname => $routes) {
        if (isset($hostnames[$hostname])) {
            continue;
        }
        foreach ($routes as $route) {
            if (is_array($route) && is_string($route['space_id'] ?? null) && $route['space_id'] !== '') {
                $hostnames[$hostname] = ['space_id' => $route['space_id']];
                break;
            }
        }
    }
    return ['hostnames' => $hostnames, 'host_routes' => $hostRoutes];
}

function _stattic_runtime_append_contribution_routes(array $routesByHost, array &$bucket, array $excludedHostnames = []): void
{
    foreach ($routesByHost as $hostname => $routes) {
        if (isset($excludedHostnames[$hostname])) {
            continue;
        }
        foreach ($routes as $route) {
            $routeAction = is_array($route) && is_array($route['route_action'] ?? null) ? $route['route_action'] : null;
            if (
                is_array($routeAction)
                && ($routeAction['action'] ?? null) === 'proxy'
                && isset($routeAction['response_headers']['X-Robots-Tag'])
            ) {
                $bucket[$hostname][] = _stattic_runtime_noindex_robots_route();
            }
            $bucket[$hostname][] = $route;
        }
    }
}

function _stattic_runtime_noindex_robots_route(): array
{
    return [
        'route_action' => [
            'action' => 'robots_txt',
            'body' => "User-agent: *\nDisallow: /\n",
            'content_type' => 'text/plain; charset=utf-8',
            'cache_control' => 'public, max-age=0, s-maxage=300, must-revalidate',
            'methods' => ['GET', 'HEAD'],
        ],
        'location' => '/robots.txt',
    ];
}

function _stattic_runtime_compile_route(string $privateRoot, string $spaceId, array $route, array &$pointerCache = []): ?array
{
    $hostname = is_string($route['hostname'] ?? null) ? $route['hostname'] : '';
    $target = is_array($route['target'] ?? null) ? $route['target'] : [];
    if ($hostname === '') {
        return null;
    }
    $location = _stattic_runtime_route_path_prefix($route);
    $hostAction = null;
    if (($target['type'] ?? null) === 'host_redirect') {
        $status = (int) ($target['status'] ?? 0);
        if (
            !is_string($target['destination'] ?? null)
            || !_stattic_runtime_redirect_target_safe($target['destination'], 'redirect')
            || !in_array($status, [301, 302, 307, 308], true)
        ) {
            return null;
        }
        $hostAction = [
            'action' => 'redirect',
            'destination' => (string) $target['destination'],
            'status' => $status,
            'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
            'methods' => ['GET', 'HEAD'],
        ];
    } elseif (($target['type'] ?? null) === 'host_proxy') {
        if (!is_string($target['upstream'] ?? null)) {
            return null;
        }
        $hostAction = [
            'action' => 'proxy',
            'upstream' => (string) $target['upstream'],
            'target_prefix' => '/',
            'methods' => STATTIC_VISITOR_METHODS,
            'headers' => [],
            'forwardHeaders' => [],
            'bodySizeLimitBytes' => 10485760,
            'timeoutSeconds' => 30,
            'connectTimeoutSeconds' => 10,
        ];
        if (!empty($route['options']['noindex'])) {
            $hostAction['response_headers'] = ['X-Robots-Tag' => 'noindex, nofollow'];
        }
    }
    if ($hostAction !== null) {
        return [
            'hostname' => $hostname,
            'host_route' => true,
            'route' => [
                'route_action' => $hostAction,
                'space_id' => $spaceId,
                'location' => $location,
            ],
        ];
    }
    $resolved = _stattic_runtime_resolve_route_target($privateRoot, $spaceId, $target, $pointerCache);
    $versionId = is_array($resolved) && is_string($resolved['version_id'] ?? null) ? $resolved['version_id'] : null;
    $config = is_array($resolved['config'] ?? null) ? $resolved['config'] : [];
    if ($versionId === null) {
        return null;
    }
    $entry = [
        'route_action' => [
            'action' => 'serve',
            'version_id' => $versionId,
            'space_id' => $spaceId,
            'target_prefix' => (($route['mount'] ?? 'strip_prefix') === 'preserve_path') ? $location : '/',
        ],
        'space_id' => $spaceId,
        'location' => $location,
    ];
    // Contracts §3: the shard maps host -> {space_id, version_id | route_action}
    // plus the few flags that decide which version and which robots policy.
    // Access fields (`runtime_config`, `admission`, `entitlements`) live in the
    // Space overlay, so an access change swaps one overlay instead of rewriting
    // every shard that names the Space.
    return [
        'hostname' => $hostname,
        'route' => $entry,
        'host_entry' => [
            'space_id' => $spaceId,
            // A route-following host records the route and resolves it through
            // the overlay instead.
            'version_id' => ($target['type'] ?? null) === 'version' ? $versionId : null,
            'route_name' => is_string($resolved['route_name'] ?? null) ? $resolved['route_name'] : null,
            'immutable' => ($target['type'] ?? null) === 'version',
            'noindex' => !empty($route['options']['noindex']),
        ],
    ];
}

// FAIL CLOSED: an absent or malformed doc returns every entitlement false. A
// lagging sync may only withhold a capability, never grant an unconfirmed one.
function _stattic_runtime_stored_entitlements(string $privateRoot, string $spaceId): array
{
    $stored = _stattic_runtime_read_json_strict(_stattic_space_root($privateRoot, $spaceId) . '/entitlements.json');
    if (!is_array($stored) || !is_array($stored['entitlements'] ?? null)) {
        return ['externalProxy' => false];
    }
    return ['externalProxy' => !empty($stored['entitlements']['externalProxy'])];
}


function _stattic_runtime_resolve_route_target(string $privateRoot, string $spaceId, array $target, array &$pointerCache = []): ?array
{
    if ($spaceId === '') {
        return null;
    }
    $type = is_string($target['type'] ?? null) ? $target['type'] : '';
    if ($type === 'version') {
        if (!is_string($target['version_id'] ?? null)) {
            return null;
        }
        $versionId = _stattic_runtime_id($target['version_id'], 'version_id');
        $cacheKey = $spaceId . '|version:' . $versionId;
        if (array_key_exists($cacheKey, $pointerCache)) {
            return $pointerCache[$cacheKey];
        }
        // The v4 version root pointer is what proves a version can serve; the
        // v3 serving.php artifact no longer exists.
        $rootPointer = _stattic_version_root($privateRoot, $spaceId, $versionId)
            . '/' . STATTIC_RUNTIME_VERSION_ROOT_POINTER_FILE;
        if (!is_file($rootPointer)) {
            $pointerCache[$cacheKey] = null;
            return null;
        }
        // Immutable hosts pin content bytes, not access: they deliberately reuse
        // the Space's current production authorization projection. Missing
        // production state stays fail-closed. [] is not a valid projection.
        $production = _stattic_runtime_read_json_strict(_stattic_route_pointer_path($privateRoot, $spaceId, 'production'));
        $pointerCache[$cacheKey] = [
            'version_id' => $versionId,
            'config' => is_array($production) && is_array($production['config'] ?? null)
                ? $production['config']
                : [],
        ];
        return $pointerCache[$cacheKey];
    }
    if ($type === 'route') {
        $routeName = is_string($target['route_name'] ?? null) ? _stattic_runtime_id($target['route_name'], 'route_name') : 'production';
        $cacheKey = $spaceId . '|route:' . $routeName;
        if (array_key_exists($cacheKey, $pointerCache)) {
            return $pointerCache[$cacheKey];
        }
        $pointer = _stattic_runtime_read_json_strict(_stattic_route_pointer_path($privateRoot, $spaceId, $routeName));
        if (!is_array($pointer) || !is_string($pointer['version_id'] ?? null)) {
            $pointerCache[$cacheKey] = null;
            return null;
        }
        $versionId = $pointer['version_id'];
        $rootPointer = _stattic_version_root($privateRoot, $spaceId, $versionId)
            . '/' . STATTIC_RUNTIME_VERSION_ROOT_POINTER_FILE;
        if (!is_file($rootPointer)) {
            $pointerCache[$cacheKey] = null;
            return null;
        }
        $pointerCache[$cacheKey] = [
            'version_id' => $versionId,
            'route_name' => $routeName,
            'config' => is_array($pointer['config'] ?? null) ? $pointer['config'] : [],
        ];
        return $pointerCache[$cacheKey];
    }
    return null;
}

// A host entry that serves a Space wins over one that does not; among serving
// entries the last contributor in space-id order takes the host.
function _stattic_runtime_host_entry_serves(mixed $entry): bool
{
    return is_array($entry)
        && is_string($entry['space_id'] ?? null)
        && $entry['space_id'] !== ''
        && !isset($entry['route_action']);
}

function _stattic_runtime_set_host_entry(array &$hostnames, string $hostname, array $entry): void
{
    if (_stattic_runtime_host_entry_serves($hostnames[$hostname] ?? null) && !_stattic_runtime_host_entry_serves($entry)) {
        return;
    }
    $hostnames[$hostname] = $entry;
}

function _stattic_runtime_set_tombstone_host_entry(array &$hostnames, string $hostname, string $spaceId, ?string $reason = null, ?string $category = null): void
{
    $existing = $hostnames[$hostname] ?? null;
    if (_stattic_runtime_host_entry_serves($existing) && ($existing['space_id'] ?? null) !== $spaceId) {
        // Another Space's live claim outranks this Space's stale tombstone.
        return;
    }
    $variant = _stattic_runtime_tombstone_variant($reason, $category);
    $action = [
        'action' => 'tombstone',
        'status' => $variant['status'],
        'cache_control' => $variant['cache_control'],
        'space_id' => $spaceId,
        // Serving selects the tombstone body/headers from this page_id (C10).
        'page_id' => $variant['page_id'],
        'methods' => STATTIC_VISITOR_METHODS,
    ];
    $hostnames[$hostname] = [
        'space_id' => $spaceId,
        'route_action' => $action,
        // CSAM must not advertise a noindex tag. It leaks that the host exists.
        'noindex' => (STATTIC_TOMBSTONE_VARIANTS[$variant['page_id']]['robots'] ?? true),
    ];
}

// Variant table C10: CSAM matches an undeployed 503 (no signal the host ever
// existed). Category wins over reason; unknown values degrade to the generic page.
function _stattic_runtime_tombstone_variant(?string $reason, ?string $category): array
{
    $key = is_string($category) && $category !== '' ? strtolower($category) : (is_string($reason) ? strtolower($reason) : '');
    $pageId = match ($key) {
        'csam' => 'tombstone-csam',
        'copyright', 'dmca' => 'tombstone-dmca',
        'tenant_suspended', 'account_suspended', 'site_suspended' => 'tombstone-suspended',
        default => 'tombstone-generic',
    };
    return [
        'page_id' => $pageId,
        'status' => STATTIC_TOMBSTONE_VARIANTS[$pageId]['status'],
        'cache_control' => STATTIC_TOMBSTONE_VARIANTS[$pageId]['cache_control'],
    ];
}

function _stattic_runtime_route_host_shard(string $hostname): string
{
    return substr(hash('sha256', $hostname), 0, 2);
}

function _stattic_runtime_split_route_index(array $hostnames, array $hostRoutes): array
{
    $shards = [];
    $wildcards = ['hostnames' => [], 'host_routes' => []];
    foreach (['hostnames' => $hostnames, 'host_routes' => $hostRoutes] as $field => $entries) {
        foreach ($entries as $hostname => $value) {
            if (!is_string($hostname)) {
                continue;
            }
            // D49: any label may be `*`, so a pattern is a wildcard when it
            // contains one anywhere, not only as a leading `*.`.
            if (str_contains($hostname, '*')) {
                $wildcards[$field][$hostname] = $value;
                continue;
            }
            $shard = _stattic_runtime_route_host_shard($hostname);
            $shards[$shard] ??= ['hostnames' => [], 'host_routes' => []];
            $shards[$shard][$field][$hostname] = $value;
        }
    }
    return ['shards' => $shards, 'wildcards' => $wildcards];
}

// Contracts §3. Shards are immutable and content-addressed, so an unchanged
// shard keeps its name across pointer writes and nothing is hardlinked, copied
// or pruned by age. `gen` is a monotonically increasing int that counts REAL
// index changes: an identical index skips the write below, so gen does not
// move for no-op regenerations (overlay-only activations included).
function _stattic_runtime_write_route_index(string $privateRoot, array $freshShards, array $reusedShards, array $wildcards, array $owners): void
{
    $routesRoot = $privateRoot . '/routes';
    $shardsRoot = $routesRoot . '/shards';
    $previous = _stattic_runtime_read_route_pointer($privateRoot);
    if ($previous === false) {
        // Never treat a failed read of an existing pointer as generation zero:
        // that resets `gen` and loses previous.json's grace set.
        throw new RuntimeException('route index read failed: ' . $routesRoot . '/current.json');
    }

    $manifest = [];
    foreach ($freshShards as $shard => $contents) {
        $shard = (string) $shard;
        $hostnames = is_array($contents['hostnames'] ?? null) ? $contents['hostnames'] : [];
        $hostRoutes = is_array($contents['host_routes'] ?? null) ? $contents['host_routes'] : [];
        if ($hostnames === [] && $hostRoutes === []) {
            continue;
        }
        $manifest[$shard] = 'shards/' . _stattic_runtime_write_route_shard('hosts-' . $shard, $shardsRoot, $hostnames, $hostRoutes);
    }
    foreach ($reusedShards as $shard => $shardFile) {
        $manifest[(string) $shard] = (string) $shardFile;
    }
    ksort($manifest);

    if (is_string($wildcards['reuse'] ?? null)) {
        $wildcardsFile = $wildcards['reuse'];
        $hasWildcards = !empty($wildcards['has_wildcards']);
    } else {
        $payload = is_array($wildcards['fresh'] ?? null) ? $wildcards['fresh'] : [];
        $hostnames = is_array($payload['hostnames'] ?? null) ? $payload['hostnames'] : [];
        $hostRoutes = is_array($payload['host_routes'] ?? null) ? $payload['host_routes'] : [];
        $hasWildcards = $hostnames !== [] || $hostRoutes !== [];
        $wildcardsFile = 'shards/' . _stattic_runtime_write_route_shard('wildcards', $shardsRoot, $hostnames, $hostRoutes);
    }

    $ownersFile = 'shards/' . _sf_php_artifact_write(
        $shardsRoot,
        'owners',
        _sf_php_artifact_source(['owners' => $owners])
    );

    // Content-addressed names make this a content comparison: an identical
    // index skips the whole write. Gen stays put, previous.json keeps its
    // true predecessor, and the unlink clock never starts for shards that
    // never retired. (Same rule the overlay writer already follows.)
    if (
        is_array($previous)
        && ($previous['schema'] ?? null) === STATTIC_RUNTIME_ARTIFACT_SCHEMA
        && (bool) ($previous['has_wildcards'] ?? false) === $hasWildcards
        && ($previous['shards'] ?? null) == $manifest
        && ($previous['wildcards'] ?? null) === $wildcardsFile
        && ($previous['owners'] ?? null) === $ownersFile
    ) {
        return;
    }

    $pointer = [
        'schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA,
        'gen' => is_array($previous) ? ((int) $previous['gen']) + 1 : 1,
        'has_wildcards' => $hasWildcards,
        'shards' => $manifest,
        'wildcards' => $wildcardsFile,
        'owners' => $ownersFile,
    ];

    // The pointer this write retires. Read BEFORE previous.json is overwritten,
    // because the shards it names, and nothing else, are the ones whose grace
    // period starts with THIS write.
    $retired = _stattic_runtime_read_json($routesRoot . '/previous.json');

    // previous.json is written BEFORE the swap, not after: it is what keeps the
    // superseded shards alive for requests already mid-flight, and a GC racing
    // the swap must never see a window where they are named by neither pointer.
    if (is_array($previous)) {
        _sf_json_write($routesRoot . '/previous.json', $previous);
    }
    _sf_json_write($routesRoot . '/current.json', $pointer);

    // D71/D93: the unlink-candidacy stamp. A shard that just fell out of both
    // pointers gets its mtime set here, which is when its grace period starts.
    // The maintenance tick only deletes, it never marks.
    _stattic_runtime_mark_unreferenced_route_shards(
        $privateRoot,
        [$pointer, $previous],
        is_array($retired) ? $retired : null
    );
}

function _stattic_runtime_write_route_shard(string $base, string $shardsRoot, array $hostnames, array $hostRoutes): string
{
    $payload = ['hostnames' => $hostnames, 'host_routes' => $hostRoutes];
    // Validate before the bytes become addressable: a shard is immutable, so a
    // malformed one would be served until the next mutation happened to replace
    // the pointer that names it.
    if (!_stattic_runtime_route_entries_valid($hostnames, $hostRoutes)) {
        _stattic_runtime_route_index_validation_failed($base);
    }
    return _sf_php_artifact_write($shardsRoot, $base, _sf_php_artifact_source($payload));
}

/**
 * @param list<?array> $pointers
 */
function _stattic_runtime_referenced_route_shards(array $pointers): array
{
    $named = [];
    foreach ($pointers as $pointer) {
        if (!is_array($pointer)) {
            continue;
        }
        foreach (is_array($pointer['shards'] ?? null) ? $pointer['shards'] : [] as $shardFile) {
            if (is_string($shardFile)) {
                $named[basename($shardFile)] = true;
            }
        }
        foreach (['wildcards', 'owners'] as $key) {
            if (is_string($pointer[$key] ?? null)) {
                $named[basename($pointer[$key])] = true;
            }
        }
    }
    return $named;
}

// Marks only what THIS write retired: the shards named by the pointer that just
// fell off the end (`$retired`) and are named by neither surviving pointer.
//
// The alternative, touching every unreferenced shard in the whole directory on
// every write, restarts the grace period of shards that stopped being
// referenced long ago, so on a busy host nothing ever ages out and the GC
// deletes nothing. A shard that is already marked keeps the mtime it was marked
// with; an orphan no pointer ever named keeps its write mtime, which is the
// same clock, so it ages out too.
function _stattic_runtime_mark_unreferenced_route_shards(string $privateRoot, array $pointers, ?array $retired = null): void
{
    if ($retired === null) {
        return;
    }
    $named = _stattic_runtime_referenced_route_shards($pointers);
    foreach (array_keys(_stattic_runtime_referenced_route_shards([$retired])) as $name) {
        if (isset($named[$name])) {
            continue;
        }
        $path = $privateRoot . '/routes/shards/' . $name;
        if (is_file($path)) {
            touch($path);
        }
    }
}

// Grace window for requests already mid-flight against a superseded pointer.
const STATTIC_RUNTIME_ROUTE_SHARD_GRACE_SECONDS = 300;

// D93, run by the maintenance tick. Deletes only; the writer above is what marks
// a shard as an unlink candidate, so the grace period measures how long ago the
// shard stopped being referenced rather than how long ago it was written.
//
// The referenced set must be PROVEN, never inferred from a failed read: a
// transient failure on current.json would read as "nothing is referenced" and
// unlink every live shard, a persistent site-wide 503 until the next route
// write. So: no valid current pointer (including never-provisioned), no pass;
// previous.json may be genuinely absent, but not unreadable. The index lock
// (try, skip on miss: the pass is idempotent and reruns every tick) closes
// the stat-then-unlink race against a concurrent writer's is_file+touch reuse.
function _stattic_runtime_route_shard_gc(string $privateRoot): int
{
    $deleted = _stattic_lock_with(
        $privateRoot . '/routes/index.lock',
        STATTIC_LOCK_TRY,
        null,
        static function () use ($privateRoot): int {
            $current = _stattic_runtime_read_route_pointer($privateRoot);
            if (!is_array($current)) {
                return 0;
            }
            $previous = _stattic_runtime_read_json($privateRoot . '/routes/previous.json');
            if ($previous === false) {
                return 0;
            }
            $named = _stattic_runtime_referenced_route_shards([
                $current,
                is_array($previous) ? $previous : null,
            ]);
            $deadline = time() - STATTIC_RUNTIME_ROUTE_SHARD_GRACE_SECONDS;
            $deleted = 0;
            foreach (glob($privateRoot . '/routes/shards/*.php') ?: [] as $path) {
                if (!is_string($path) || isset($named[basename($path)])) {
                    continue;
                }
                $mtime = filemtime($path);
                if (is_int($mtime) && $mtime < $deadline && unlink($path)) {
                    $deleted += 1;
                }
            }
            return $deleted;
        },
    );
    return is_int($deleted) ? $deleted : 0;
}

const STATTIC_RUNTIME_ZERO_CAPABILITIES = ['db', 'fetch', 'auth', 'env', 'realtime', 'logging'];

// ---------------------------------------------------------------------------
// Space overlay (contracts §4)
// ---------------------------------------------------------------------------

// The control plane's public-exposure descriptor, validated. This is THE
// authority on whether an anonymous response may be public; the runtime never
// re-derives it. Shape mirrors packages/common
// `runtimePublicExposureDescriptorSchema` (and admin/management.php's
// `_stattic_runtime_public_exposure_descriptor`, which validates the same
// document on the way in).
function _stattic_runtime_overlay_public_exposure(array $config): ?array
{
    $descriptor = $config['public_exposure'] ?? null;
    if (
        !is_array($descriptor)
        || !is_int($descriptor['v'] ?? null)
        || $descriptor['v'] < 1
        || !is_bool($descriptor['public'] ?? null)
    ) {
        return null;
    }
    return $descriptor;
}

// `open` = "this Space can be served to everyone, for every host it answers on,
// without loading access-rules.php at all". Two independent things must hold,
// because either alone leaks:
//
//  1. the control plane's public-exposure descriptor says `public` (the
//     authority, never a grant count, never a guess), and no serving fence is
//     up; and
//  2. the compiled projection structurally admits everything anonymously for
//     every target this Space can present. That is the compiler's own
//     `unconditionalTargets` verdict (access-rules.php
//     _stattic_compile_authorization_grant_index), never re-derived here from
//     grant fields. The descriptor's `public` only means "SOME anonymous
//     response here is public": a Space whose public Grant covers `/docs/**`
//     sets it too, and skipping enforcement for it would publish the rest.
//
// Target algebra (access-rules.php `_stattic_grant_target`): a production/live
// host is `live`, any other route name is `branch`, a version-pinned host is
// `version` (matched by an `all_versions` selector). Branch targets are named
// individually and can appear at any time, so a Space with any non-live route
// is never provably open. Anything unproven takes the enforcement lane, which
// still admits anonymously and keeps its shared-cache policy. The cost of being
// wrong here is one `require`, the cost of being wrong the other way is the
// whole Space.
function _stattic_runtime_overlay_open(
    array $config,
    ?array $authorization,
    ?array $grantIndex,
    string $fence,
    array $versions
): bool {
    $exposure = _stattic_runtime_overlay_public_exposure($config);
    if (
        $exposure === null
        || $exposure['public'] !== true
        || $fence !== 'none'
        || $authorization === null
        || $grantIndex === null
    ) {
        return false;
    }
    foreach (array_keys($versions) as $routeName) {
        if ($routeName !== 'production' && $routeName !== 'live') {
            return false;
        }
    }
    $unconditional = is_array($grantIndex['unconditionalTargets'] ?? null)
        ? $grantIndex['unconditionalTargets']
        : [];
    return ($unconditional['live'] ?? null) === true
        && ($unconditional['all_versions'] ?? null) === true;
}

// A tag body above this rides the CAS instead of the overlay. The overlay is
// an opcached PHP array included on EVERY request this Space serves, so a
// megabyte of tag JavaScript in it is a megabyte of opcache and a megabyte of
// array-building per request. The CAS pays for it only on the SDK lane.
const STATTIC_RUNTIME_SDK_INLINE_BODY_MAX = 32768;

// ONE SDK section (contracts §4). The control plane sends `sdk` on the route
// config; this is where its body is placed and where the section the serve
// path reads is shaped. Absent/invalid means no SDK projection at all, which
// the serve path renders as an inert loader, never as a failure.
function _stattic_runtime_overlay_sdk_section(string $privateRoot, string $spaceId, mixed $sdk): ?array
{
    if (!is_array($sdk) || !is_array($sdk['config'] ?? null)) {
        return null;
    }
    $section = [
        'revision' => is_string($sdk['revision'] ?? null) && $sdk['revision'] !== ''
            ? $sdk['revision']
            : null,
        'config' => $sdk['config'],
    ];
    $body = is_string($sdk['body'] ?? null) && $sdk['body'] !== '' ? $sdk['body'] : null;
    if ($body === null) {
        return $section;
    }
    if (strlen($body) <= STATTIC_RUNTIME_SDK_INLINE_BODY_MAX) {
        return $section + ['body' => $body];
    }
    // Content-addressed by the same rule as every other blob, so an unchanged
    // release rewrites nothing and the overlay's own content hash is stable.
    $sha = hash('sha256', $body);
    require_once __DIR__ . '/../shared/storage.php';
    if (!_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
        $staging = $privateRoot . '/runtime/blob-staging';
        _stattic_runtime_mkdir($staging);
        $tmp = $staging . '/sdk-' . bin2hex(random_bytes(12)) . '.tmp';
        if (file_put_contents($tmp, $body) !== strlen($body)) {
            unlink($tmp);
            _stattic_runtime_route_index_validation_failed('overlay');
        }
        _stattic_runtime_blob_put($privateRoot, $spaceId, $tmp, $sha);
    }
    return $section + ['body_sha256' => $sha, 'body_len' => strlen($body)];
}

// Everything access-shaped about a Space, in one artifact the serve path
// includes after the host shard. Rebuilt from the Space's own state, so every
// mutation that already calls _stattic_runtime_update_route_index refreshes it
// without the management lane having to know it exists.
function _stattic_runtime_sync_space_overlay(string $privateRoot, string $spaceId): void
{
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    if (!is_dir($spaceRoot)) {
        return;
    }

    $versions = [];
    $production = null;
    foreach (_stattic_runtime_directory_entries_strict($spaceRoot . '/routes') as $pointerPath) {
        if (!str_ends_with($pointerPath, '.json')) {
            continue;
        }
        $pointer = _stattic_runtime_read_json_strict($pointerPath);
        if (
            !is_array($pointer)
            || !is_string($pointer['route_name'] ?? null)
            || !is_string($pointer['version_id'] ?? null)
        ) {
            throw new RuntimeException('runtime route pointer is invalid: ' . $pointerPath);
        }
        $versions[$pointer['route_name']] = $pointer['version_id'];
        if ($pointer['route_name'] === 'production') {
            $production = $pointer;
        }
    }
    // `live` is the name the shard uses when a host follows the Space's current
    // version without naming a route.
    if (isset($versions['production'])) {
        $versions['live'] ??= $versions['production'];
    }
    ksort($versions);

    $config = is_array($production) && is_array($production['config'] ?? null) ? $production['config'] : [];
    // The route config and the live VERSION's compiled serving config are two
    // different documents, and reading a version-owned key off the route
    // pointer silently yields null forever. Anything finalize resolved (page
    // templates) comes from the version's own metadata.json; anything the
    // control plane may change without a republish (access, the SDK section)
    // comes from the route config above.
    $liveVersionId = $versions['production'] ?? null;
    $liveMetadata = is_string($liveVersionId)
        ? _stattic_runtime_read_json_strict(_stattic_version_root($privateRoot, $spaceId, $liveVersionId) . '/metadata.json')
        : null;
    $liveServingConfig = is_array($liveMetadata) && is_array($liveMetadata['servingConfig'] ?? null)
        ? $liveMetadata['servingConfig']
        : [];
    $authorization = is_array($config['authorization'] ?? null) ? $config['authorization'] : null;
    $grantIndex = is_array($authorization['grantIndex'] ?? null) ? $authorization['grantIndex'] : null;
    $fence = is_string($authorization['fence'] ?? null) ? $authorization['fence'] : 'none';

    // Deliberately narrow (D34): `open` decides whether the serve path may skip
    // loading access code AT ALL, so it is true only when there is provably
    // nothing to enforce. A Space with grants takes the enforcement lane, which
    // may still admit anonymously and keep its shared-cache policy.
    //
    // It is NEVER derived from how many grants the projection carries. "Zero
    // grants" is the *most* protected projection there is, admitting nobody.
    // The authority is the control plane's own public-exposure descriptor
    // (packages/common runtimePublicExposureDescriptorSchema, v>=1, `public`);
    // absent or malformed means not public, which is the fail-closed answer.
    $open = _stattic_runtime_overlay_open($config, $authorization, $grantIndex, $fence, $versions);

    _stattic_runtime_write_space_overlay($privateRoot, $spaceId, [
        'open' => $open,
        'fence' => $fence === 'none' ? null : $fence,
        // A tuple, never summed (D30).
        'access_gen' => is_int($authorization['generation'] ?? null) ? $authorization['generation'] : 0,
        'session_ver' => is_int($authorization['sessionVersion'] ?? null) ? $authorization['sessionVersion'] : 0,
        'versions' => $versions,
        'grants' => $grantIndex,
        // Copied through so the serve path can rebuild the projection without
        // loading access-rules.php to learn the marker.
        'compiled_version' => $authorization['compiledVersion'] ?? null,
        'space_claimed' => ($authorization['spaceClaimed'] ?? null) === true,
        // Required, not presentation trivia: `exchange.credential` is the
        // material every sfv2_/sfa1_ session key derives from (access-rules.php
        // _stattic_access_session_hmac_key), so without it a protected Space is
        // permanently deny-only. Carried verbatim; access-rules.php re-validates
        // every URL in it against the platform destination allowlist.
        'access_page' => is_array($authorization['accessPage'] ?? null)
            ? $authorization['accessPage']
            : null,
        'exchange' => [
            'acquire_url' => is_string($authorization['acquireUrl'] ?? null) ? $authorization['acquireUrl'] : null,
            'issuer' => is_string($config['visitor_issuer'] ?? null) ? $config['visitor_issuer'] : null,
            'jwks' => is_array($config['visitor_jwks'] ?? null) ? $config['visitor_jwks'] : null,
            // The runtime's own server-to-server credential, mirrored out of the
            // access-page descriptor so a caller holding only `exchange` (the
            // legacy serving shape) can still derive the session key.
            'credential' => is_array($authorization['accessPage']['exchange'] ?? null)
                && is_string($authorization['accessPage']['exchange']['credential'] ?? null)
                ? $authorization['accessPage']['exchange']['credential']
                : null,
        ],
        'anonymous_expires_at' => is_string($config['anonymous_expires_at'] ?? null) ? $config['anonymous_expires_at'] : null,
        // The live version's compiled page pointers, so a publisher's own access
        // and 404 templates resolve. `pages.routes[<route>].pages[<id>]` names
        // an artifact under `<version>/pages/`, so it is read from the metadata
        // of the version `production` points at, never from the route config,
        // which has no such key.
        'pages' => is_array($liveServingConfig['pages'] ?? null) ? $liveServingConfig['pages'] : null,
        'content_types' => is_array($config['content_types'] ?? null) ? $config['content_types'] : null,
        // ONE SDK section: Cast endpoints, the Comments configuration the SDK
        // lane answers out of this overlay instead of calling the control
        // plane per request, and the production tag bytes. A large body is
        // moved into the CAS.
        'sdk' => _stattic_runtime_overlay_sdk_section($privateRoot, $spaceId, $config['sdk'] ?? null),
        'admission' => is_array($config['admission'] ?? null) ? $config['admission'] : [],
        // Read at serve time so a `planGated` proxy rule follows the current
        // plan, not what publish baked.
        'entitlements' => _stattic_runtime_stored_entitlements($privateRoot, $spaceId),
    ]);
}

// The §4 swap protocol: write the content-addressed overlay -> include it back
// as a self-check -> swap space.json (tmp + rename; the next request's fresh
// pointer read is the visibility protocol).
// An overlay whose content is unchanged keeps its name, and the pointer is left
// alone so `gen` only moves when something actually changed.
function _stattic_runtime_write_space_overlay(string $privateRoot, string $spaceId, array $overlay): void
{
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $pointerPath = $spaceRoot . '/space.json';
    $current = _stattic_runtime_read_json_strict($pointerPath);
    $current = is_array($current) ? $current : null;

    $overlay = ['schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA] + $overlay;
    if (!is_bool($overlay['open'])) {
        _stattic_runtime_route_index_validation_failed('overlay');
    }
    $name = 'overlays/' . _sf_php_artifact_write(
        $spaceRoot . '/overlays',
        'overlay',
        _sf_php_artifact_source($overlay)
    );
    if (($current['overlay'] ?? null) === $name) {
        return;
    }

    // Self-check before the pointer names it: a torn or unparsable overlay is a
    // site-wide denial (D28), so it must never become reachable.
    $loaded = include $spaceRoot . '/' . $name;
    if (!is_array($loaded) || !is_bool($loaded['open'] ?? null) || ($loaded['schema'] ?? null) !== STATTIC_RUNTIME_ARTIFACT_SCHEMA) {
        _stattic_runtime_route_index_validation_failed('overlay');
    }

    _sf_json_write($pointerPath, [
        'schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA,
        'gen' => is_int($current['gen'] ?? null) ? $current['gen'] + 1 : 1,
        'overlay' => $name,
    ]);
}

function _stattic_runtime_route_entries_valid(array $hostnames, array $hostRoutes): bool
{
    foreach ($hostnames as $hostname => $entry) {
        if (!is_string($hostname) || !is_array($entry) || !_stattic_runtime_optional_id_valid($entry['space_id'] ?? null)) {
            return false;
        }
        if (array_key_exists('route_action', $entry)) {
            if (!_stattic_runtime_route_action_valid($entry['route_action'], true)) {
                return false;
            }
            continue;
        }
        if (
            ($entry['version_id'] ?? null) !== null && !_stattic_runtime_optional_id_valid($entry['version_id'])
        ) {
            return false;
        }
    }
    return array_all($hostRoutes, static fn (mixed $routes, mixed $hostname): bool =>
        is_string($hostname)
        && is_array($routes)
        && array_all($routes, static fn (mixed $route): bool =>
            is_array($route) && _stattic_runtime_route_entry_valid($route)));
}

function _stattic_runtime_route_entry_valid(array $route): bool
{
    return _stattic_runtime_route_action_valid($route['route_action'] ?? null, false)
        && is_string($route['location'] ?? null)
        && $route['location'] !== ''
        && $route['location'][0] === '/';
}

function _stattic_runtime_route_action_valid(mixed $action, bool $allowTombstone): bool
{
    if (!is_array($action) || !is_string($action['action'] ?? null)) {
        return false;
    }
    if ($action['action'] === 'serve') {
        return _stattic_runtime_optional_id_valid($action['version_id'] ?? null)
            && _stattic_runtime_optional_id_valid($action['space_id'] ?? null)
            && is_string($action['target_prefix'] ?? null)
            && $action['target_prefix'] !== ''
            && $action['target_prefix'][0] === '/';
    }
    if ($action['action'] === 'redirect') {
        $status = (int) ($action['status'] ?? 0);
        return is_string($action['destination'] ?? null)
            && $action['destination'] !== ''
            && _stattic_runtime_redirect_target_safe($action['destination'], 'redirect')
            && in_array($status, [301, 302, 303, 307, 308], true)
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD'];
    }
    if ($action['action'] === 'proxy') {
        return _stattic_runtime_proxy_route_policy_valid($action);
    }
    if ($action['action'] === 'robots_txt') {
        return ($action['body'] ?? null) === "User-agent: *\nDisallow: /\n"
            && ($action['content_type'] ?? null) === 'text/plain; charset=utf-8'
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD'];
    }
    if ($action['action'] === 'tombstone') {
        return $allowTombstone && _stattic_runtime_tombstone_route_action_valid($action);
    }
    if ($action['action'] === 'platform_error') {
        return ($action['page_id'] ?? null) === 'version-pending'
            && (int) ($action['status'] ?? 0) === 503
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && is_string($action['message'] ?? null)
            && $action['message'] !== ''
            && ($action['methods'] ?? null) === STATTIC_VISITOR_METHODS;
    }
    return false;
}

function _stattic_runtime_optional_id_valid(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1 && !str_contains($value, '..');
}

function _stattic_runtime_proxy_route_policy_valid(array $action): bool
{
    // Serve-time shape contract (shared/egress.php) plus a compile-time-only
    // egress check on the upstream host.
    return _stattic_egress_proxy_policy_shape_valid($action)
        && _stattic_runtime_proxy_upstream_public((string) $action['upstream']);
}

function _stattic_runtime_proxy_upstream_public(string $upstream): bool
{
    $parts = parse_url($upstream);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    // _stattic_egress_host_allowed is only a compile-time syntactic gate: it
    // rejects internal/loopback names and non-public IP literals but cannot
    // resolve a DNS name, so a hostname pointing at a private address passes
    // here. That is intentional. The authoritative SSRF gate is the
    // request-time resolution in runtime/proxy.php
    // (_stattic_egress_resolve_public_ips), which re-checks every resolved IP
    // and pins curl to it.
    return in_array($scheme, ['http', 'https'], true)
        && $host !== ''
        && _stattic_egress_host_allowed($host, $port)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function _stattic_runtime_route_index_validation_failed(string $artifact): never
{
    _stattic_problem_response(
        422,
        'runtime_route_index_validation_failed',
        'Runtime route index validation failed.',
        ['details' => ['artifact' => $artifact]],
    );
}

function _stattic_runtime_zero_compiler_entries(array $input, string $snakeIdKey, string $camelIdKey): array
{
    $entries = [];
    foreach ($input as $entry) {
        if (!is_array($entry)) {
            $entries[] = $entry;
            continue;
        }
        if (is_string($entry[$snakeIdKey] ?? null) && !array_key_exists($camelIdKey, $entry)) {
            $entry[$camelIdKey] = $entry[$snakeIdKey];
        }
        if (is_string($entry['schema_hash'] ?? null) && !array_key_exists('schemaHash', $entry)) {
            $entry['schemaHash'] = $entry['schema_hash'];
        }
        $entry['capabilities'] = _stattic_runtime_zero_endpoint_capabilities($entry['capabilities'] ?? []);
        unset($entry[$snakeIdKey], $entry['schema_hash']);
        $entries[] = $entry;
    }

    return $entries;
}

/**
 * Apply the version's compiled Zero migrations, in this worker. The database
 * URL resolution is the one every brokered lane uses, so a capsule's schema is
 * created against exactly the database its endpoints will later read.
 */
function _stattic_runtime_apply_zero_migrations(string $versionRoot): void
{
    $path = $versionRoot . '/zero/migrations.json';
    if (!is_file($path)) {
        return;
    }
    $zeroConfig = _stattic_runtime_read_json($versionRoot . '/zero/config.json');
    $env = _stattic_zero_runner_base_env(is_array($zeroConfig) ? $zeroConfig : []);
    _stattic_db_broker_bind(
        is_string($env['SPACEFAST_ZERO_DATABASE_URL'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL'] : null,
        is_string($env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] : null
    );
    $result = _stattic_db_broker_apply_migrations($path);
    // Publishing is the only thing this request does with a database, and the
    // link is worth nothing to the rest of it.
    _stattic_db_broker_close();
    if ($result['ok']) {
        return;
    }
    _stattic_problem_response(
        500,
        'zero_migration_failed',
        _stattic_zero_debug_message('Zero DB migrations failed.', $result['message']),
        ['details' => ['zero_db_code' => $result['code']]],
    );
}

function _stattic_runtime_zero_endpoint_capabilities(mixed $input): array
{
    $raw = is_array($input) ? $input : [];
    $out = [];
    foreach (STATTIC_RUNTIME_ZERO_CAPABILITIES as $capability) {
        $out[$capability] = array_key_exists($capability, $raw)
            ? (bool) $raw[$capability]
            : true;
    }

    return $out;
}

function _stattic_runtime_redirect_target_safe(string $destination, string $action, ?array &$failureDetails = null): bool
{
    $parts = parse_url($destination);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : $destination;
    if ($path === '') {
        $safe = $action === 'redirect';
        if (!$safe) {
            $failureDetails = ['path' => $path, 'reason' => 'empty_non_redirect_target'];
        }
        return $safe;
    }

    if ($action === 'proxy') {
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $failureDetails = ['path' => $path, 'reason' => 'invalid_proxy_url'];
            return false;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        // Compile-time syntactic gate only (internal names + non-public IP
        // literals). It never resolves DNS, so a private-pointing hostname
        // passes; the authoritative SSRF enforcement is request-time in
        // runtime/proxy.php (_stattic_egress_resolve_public_ips). Not a
        // complete safety check on its own.
        if (!_stattic_egress_host_allowed($host, $port)) {
            $failureDetails = ['path' => $path, 'host' => $host, 'reason' => 'proxy_host_not_allowed'];
            return false;
        }
    }

    if (!str_starts_with($path, '/')) {
        $safe = $action === 'redirect';
        if (!$safe) {
            $failureDetails = ['path' => $path, 'reason' => 'relative_non_redirect_target'];
        }
        return $safe;
    }
    if ($path === '/') {
        return true;
    }

    $validationPath = rtrim(ltrim($path, '/'), '/');
    if ($validationPath === '') {
        return true;
    }

    $violation = _stattic_static_upload_path_violation($validationPath);
    if (is_array($violation)) {
        $failureDetails = [
            'path' => $path,
            'validation_path' => $validationPath,
            'reason' => (string) ($violation['code'] ?? 'static_upload_path_violation'),
        ];
        return false;
    }

    return true;
}
