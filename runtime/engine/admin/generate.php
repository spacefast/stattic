<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/../shared/artifacts.php';

function _stattic_runtime_routes_from_hostname_intent(string $routeName, array $intent): array
{
    if (array_key_exists('routes', $intent)) {
        _stattic_json_response(422, ['error' => ['code' => 'routes_input_retired', 'message' => 'Runtime compiles routes from hostname intent. Send production_hostnames and version_hostnames.']]);
    }
    $productionHostnames = _stattic_runtime_hostname_list($intent['production_hostnames'] ?? []);
    $noindexProductionHostnames = array_fill_keys(_stattic_runtime_hostname_list($intent['noindex_production_hostnames'] ?? []), true);
    $versionHostnames = _stattic_runtime_version_hostname_entries($intent['version_hostnames'] ?? []);
    $served = array_fill_keys($productionHostnames, true) + array_fill_keys(array_keys($versionHostnames), true);
    // Noindex host classing (spec "Routing And Headers"): immutable version URLs and
    // non-production route hostnames (cloud channel hosts) are ALWAYS noindex;
    // production hostnames are noindex only when the control plane classes them so
    // (unclaimed anonymous spaces). _headers can never override X-Robots-Tag on
    // noindex-classed hosts.
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
    // Each immutable version host targets ITS OWN version (the intent is the
    // complete retained-version map, not just the version being activated), so
    // activating a new version never orphans older ver- URLs.
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
    foreach (_stattic_runtime_static_mount_routes($intent['static_mount_routes'] ?? [], $productionHostnames) as $route) {
        $routes[] = $route;
    }
    return $routes;
}

function _stattic_runtime_static_mount_routes(mixed $raw, array $productionHostnames): array
{
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_static_mount_routes', 'message' => 'Static mount routes must be an array.']]);
    }
    $served = array_fill_keys($productionHostnames, true);
    $routes = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (
            !is_array($entry)
            || !is_string($entry['hostname'] ?? null)
            || !is_string($entry['path_prefix'] ?? null)
            || !is_string($entry['target_space_id'] ?? null)
            || !is_string($entry['target_version_id'] ?? null)
        ) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_static_mount_route', 'message' => 'Static mount route is malformed.']]);
        }
        $hostname = _stattic_runtime_normalize_route_hostname($entry['hostname']);
        $pathPrefix = _stattic_runtime_normalize_route_path($entry['path_prefix']);
        $targetSpaceId = _stattic_runtime_id($entry['target_space_id'], 'target_space_id');
        $targetVersionId = _stattic_runtime_id($entry['target_version_id'], 'target_version_id');
        $key = $hostname . "\n" . $pathPrefix;
        if ($hostname === '' || !isset($served[$hostname]) || $pathPrefix === '/' || isset($seen[$key])) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_static_mount_route', 'message' => 'Static mount route is invalid.']]);
        }
        $seen[$key] = true;
        $routes[] = [
            'hostname' => $hostname,
            'path_prefix' => $pathPrefix,
            'mount' => 'strip_prefix',
            'target' => [
                'type' => 'version',
                'space_id' => $targetSpaceId,
                'version_id' => $targetVersionId,
                'static_mount' => true,
            ],
            'options' => ['noindex' => false],
        ];
    }
    return $routes;
}

function _stattic_runtime_proxy_host_routes(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_proxy_host_routes', 'message' => 'Proxy host routes must be an array.']]);
    }
    $routes = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !is_string($entry['hostname'] ?? null) || !is_string($entry['upstream'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_proxy_host_route', 'message' => 'Proxy host route entries must carry hostname and upstream.']]);
        }
        $hostname = _stattic_runtime_normalize_route_hostname($entry['hostname']);
        $pathPrefix = _stattic_runtime_route_path_prefix($entry);
        $upstream = (string) $entry['upstream'];
        $key = $hostname . "\n" . $pathPrefix;
        if ($hostname === '' || isset($seen[$key]) || !_stattic_runtime_proxy_upstream_public($upstream)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_proxy_host_route', 'message' => 'Proxy host route is invalid.']]);
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
        _stattic_json_response(422, ['error' => ['code' => 'invalid_host_canonical_redirects', 'message' => 'Host canonical redirects must be an array.']]);
    }
    $routes = [];
    $seen = [];
    foreach ($raw as $redirect) {
        if (!is_array($redirect) || !is_string($redirect['from'] ?? null) || !is_string($redirect['to'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_host_canonical_redirect', 'message' => 'Host canonical redirect is invalid.']]);
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
            _stattic_json_response(422, ['error' => ['code' => 'invalid_host_canonical_redirect', 'message' => 'Host canonical redirect is invalid.']]);
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

function _stattic_runtime_artifact_metadata(?string $generatedAt = null): array
{
    return [
        'runtime_schema' => STATTIC_RUNTIME_SCHEMA,
        'runtime_engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
        'generated_at' => $generatedAt ?? gmdate('c'),
    ];
}

// Immutable version hosts arrive as {hostname, version_id} entries (the
// complete retained-version map). Returns hostname => version_id.
function _stattic_runtime_version_hostname_entries(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_version_hostnames', 'message' => 'Version hostnames must be an array.']]);
    }
    $entries = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !is_string($entry['hostname'] ?? null) || !is_string($entry['version_id'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_version_hostname', 'message' => 'Version hostname entries must carry hostname and version_id.']]);
        }
        $normalized = _stattic_runtime_normalize_route_hostname($entry['hostname']);
        $targetVersionId = _stattic_runtime_id($entry['version_id'], 'version_id');
        if ($normalized === '') {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_version_hostname', 'message' => 'Version hostname is invalid.']]);
        }
        $entries[$normalized] = $targetVersionId;
    }
    return $entries;
}

function _stattic_runtime_hostname_list(mixed $raw): array
{
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_hostnames', 'message' => 'Hostnames must be an array.']]);
    }
    $hostnames = [];
    foreach ($raw as $hostname) {
        if (!is_string($hostname)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_hostname', 'message' => 'Hostname is invalid.']]);
        }
        $normalized = _stattic_runtime_normalize_route_hostname($hostname);
        if ($normalized === '') {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_hostname', 'message' => 'Hostname is invalid.']]);
        }
        $hostnames[$normalized] = true;
    }
    return array_keys($hostnames);
}

function _stattic_runtime_store_hostname_intent(string $privateRoot, string $spaceId, array $rawRoutes, array $claims = []): void
{
    $previousIntent = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
    $previousRoutes = is_array($previousIntent) && is_array($previousIntent['routes'] ?? null)
        ? $previousIntent['routes']
        : [];
    $routes = [];
    foreach ($rawRoutes as $route) {
        if (!is_array($route)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_route', 'message' => 'Each route must be an object.']]);
        }
        $routes[] = _stattic_runtime_normalize_route($route);
    }
    $changedHostnames = _stattic_runtime_hostname_intent_repoint_diff($previousRoutes, $routes);
    _stattic_runtime_write_json_atomic(_spacefast_space_root($privateRoot, $spaceId) . '/hostname-intent.json', [
        'space_id' => $spaceId,
        'routes' => $routes,
        'updated_at' => gmdate('c'),
    ]);
    if ($claims !== []) {
        _stattic_runtime_record_management_event($privateRoot, $claims, [
            'event' => 'space_hostname_intent_updated',
            'space_id' => $spaceId,
            'route_count' => count($routes),
            'hostnames' => $changedHostnames,
        ]);
        return;
    }
    _stattic_runtime_append_journal($privateRoot, [
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

// Both call sites pass already-normalized routes (the incoming set from
// _stattic_runtime_store_hostname_intent, the stored set from
// hostname-intent.json, which only that function writes), so this reads the
// route fields instead of re-running _stattic_runtime_normalize_route — a read
// of stored state can no longer 422.
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
        // One encode per entry instead of one per comparison; asort is stable,
        // so equal encodings (identical entries) keep their order.
        $order = array_map(static fn (array $entry): string => (string) json_encode($entry), $entries);
        asort($order, SORT_STRING);
        $entries = array_values(array_replace($order, $entries));
    }
    unset($entries);
    return $targets;
}

// Hostnames whose serving output a route-pointer flip or version delete
// affects: production hosts targeting the route pointer, or immutable version
// hosts targeting a specific version. These ride the journal event so the
// control plane can scope provider purges (spec "Cache Management").
function _stattic_runtime_affected_intent_hostnames(string $privateRoot, string $spaceId, ?string $routeName, ?string $versionId = null): array
{
    $intent = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
    if (!is_array($intent) || !is_array($intent['routes'] ?? null)) {
        return [];
    }
    $hostnames = [];
    foreach ($intent['routes'] as $route) {
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

// Sanitized changed-path set echoed from a management request into the journal
// event (cap mirrors RUNTIME_CHANGED_PATHS_MAX in the shared contract):
// canonical leading-slash request paths. An invalid or over-cap set degrades
// to [] — host-wide purge — never a rejected mutation.
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
    $tombstonePath = _spacefast_space_root($privateRoot, $spaceId) . '/tombstones.json';
    $tombstones = [];
    $existing = _stattic_runtime_read_json($tombstonePath);
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
            _stattic_json_response(422, ['error' => ['code' => 'invalid_hostname', 'message' => 'Hostname is invalid.']]);
        }
        if ($mode === 'remove') {
            unset($tombstones[$normalized]);
        } else {
            $tombstones[$normalized] = true;
        }
    }
    // Reason/category are space-level disabled metadata: an explicit value on a
    // tombstone-setting call updates them; absence on add/remove preserves the
    // existing metadata so a follow-up host edit never silently downgrades a
    // CSAM/DMCA tombstone to the generic page.
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
        _stattic_json_response(422, ['error' => ['code' => 'invalid_hostname', 'message' => 'Route hostname is invalid.']]);
    }
    $pathPrefix = _stattic_runtime_route_path_prefix($route);
    $mount = isset($route['mount']) && is_string($route['mount']) && in_array($route['mount'], ['strip_prefix', 'preserve_path'], true)
        ? $route['mount']
        : 'strip_prefix';
    $target = is_array($route['target'] ?? null) ? $route['target'] : null;
    if (!is_array($target) || !is_string($target['type'] ?? null)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Route target is invalid.']]);
    }
    if ($target['type'] === 'version') {
        if (!is_string($target['version_id'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Version route target requires version_id.']]);
        }
        $normalizedTarget = [
            'type' => 'version',
            'version_id' => _stattic_runtime_id($target['version_id'], 'version_id'),
            ...(is_string($target['space_id'] ?? null)
                ? ['space_id' => _stattic_runtime_id($target['space_id'], 'target_space_id')]
                : []),
            ...(!empty($target['static_mount']) ? ['static_mount' => true] : []),
        ];
    } elseif ($target['type'] === 'route') {
        if (!is_string($target['route_name'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Route pointer target requires route_name.']]);
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
            _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Host redirect route target is invalid.']]);
        }
        $normalizedTarget = [
            'type' => 'host_redirect',
            'destination' => $destination,
            'status' => $status,
        ];
    } elseif ($target['type'] === 'host_proxy') {
        $upstream = is_string($target['upstream'] ?? null) ? $target['upstream'] : '';
        if ($upstream === '' || !_stattic_runtime_proxy_upstream_public($upstream)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Host proxy route target is invalid.']]);
        }
        $normalizedTarget = [
            'type' => 'host_proxy',
            'upstream' => $upstream,
        ];
    } else {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_route_target', 'message' => 'Route target type is invalid.']]);
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
    $hostname = trim(strtolower($hostname));
    $hostname = preg_replace('/:\d+$/', '', $hostname) ?: '';
    return preg_match('/^(\\*\\.)?[a-z0-9.-]{1,253}$/', $hostname) === 1 ? $hostname : '';
}

function _stattic_runtime_normalize_route_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return '/';
    }
    return '/' . trim($path, '/');
}

function _stattic_runtime_route_path_prefix(array $route): string
{
    return isset($route['path_prefix']) && is_string($route['path_prefix'])
        ? _stattic_runtime_normalize_route_path($route['path_prefix'])
        : '/';
}

// All route-index writes serialize on the dedicated routes/index.lock
// (innermost lock; site -> space -> index ordering, see the lock primitives
// in admin/management.php). The lock is re-entrant per request, so the
// update->full-rebuild fallback below and site-locked callers both nest
// harmlessly. This is what lets space-locked mutations (route PUT, hostname
// intent, tombstones) publish generations concurrently with site-locked ones
// without ever interleaving a read-modify-write of current.php.
function _stattic_runtime_rebuild_route_index(string $privateRoot): void
{
    _stattic_runtime_with_route_index_lock($privateRoot, static function () use ($privateRoot): void {
        _stattic_runtime_rebuild_route_index_unlocked($privateRoot);
    });
}

function _stattic_runtime_rebuild_route_index_unlocked(string $privateRoot): void
{
    $contributions = [];
    foreach (glob($privateRoot . '/spaces/*', GLOB_ONLYDIR) ?: [] as $spaceRoot) {
        $contribution = _stattic_runtime_space_index_contribution($privateRoot, basename((string) $spaceRoot));
        if (_stattic_runtime_contribution_hostnames($contribution) !== []) {
            $contributions[] = $contribution;
        }
    }
    $merged = _stattic_runtime_merge_host_contributions($contributions);
    $split = _stattic_runtime_split_route_index($merged['hostnames'], $merged['host_routes']);
    _stattic_runtime_write_route_generation(
        $privateRoot,
        $split['shards'],
        [],
        ['fresh' => $split['wildcards']],
        _stattic_runtime_contribution_owners($contributions),
        gmdate('c')
    );
}

// Incremental route-index update for one space's mutation (route PUT, tombstone
// PUT, finalize+activate, version delete, space delete): recompiles ONLY the
// hostnames this space contributes now or contributed in the current generation,
// reuses every untouched host shard via hardlink, and publishes a complete new
// immutable generation. Index work is O(affected hostnames), not O(site spaces)
// — the full synchronous rebuild multiplied every management call's cost on
// many-space pooled sites and helped arm the provider's edge protection
// (e2e checkpoint 3). Any unexpected on-disk state falls back to a full rebuild.
function _stattic_runtime_update_route_index(string $privateRoot, string $spaceId): void
{
    _stattic_runtime_with_route_index_lock($privateRoot, static function () use ($privateRoot, $spaceId): void {
        _stattic_runtime_update_route_index_unlocked($privateRoot, $spaceId);
    });
}

function _stattic_runtime_update_route_index_unlocked(string $privateRoot, string $spaceId): void
{
    $current = is_file($privateRoot . '/routes/current.php')
        ? @include $privateRoot . '/routes/current.php'
        : null;
    $generation = is_array($current) && is_string($current['generation'] ?? null) ? $current['generation'] : null;
    $shardManifest = is_array($current) && is_array($current['shards'] ?? null) ? $current['shards'] : null;
    $wildcardsGeneration = is_array($current) && is_string($current['wildcards_generation'] ?? null)
        ? $current['wildcards_generation']
        : null;
    $generationRoot = $privateRoot . '/routes/generations/' . ($generation ?? '');
    $ownersArtifact = $generation !== null ? @include $generationRoot . '/owners.php' : null;
    $owners = is_array($ownersArtifact)
        && ($ownersArtifact['artifact_kind'] ?? null) === 'route_owners'
        && ($ownersArtifact['generation'] ?? null) === $generation
        && is_array($ownersArtifact['owners'] ?? null)
        ? $ownersArtifact['owners']
        : null;
    if ($generation === null || $shardManifest === null || $wildcardsGeneration === null || $owners === null) {
        _stattic_runtime_rebuild_route_index($privateRoot);
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

    // Recompute each affected hostname from its contributor spaces only; the
    // merge sorts contributors by space id (the full rebuild's glob order) so
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
        if (str_starts_with((string) $hostname, '*.')) {
            $wildcardAffected = true;
        } else {
            $affectedShards[_stattic_runtime_route_host_shard((string) $hostname)] = true;
        }
    }

    $freshShards = [];
    $reusedShards = [];
    foreach ($shardManifest as $shard => $builtGeneration) {
        // PHP normalizes numeric-string array keys (e.g. shard "36") to ints.
        $shard = (string) $shard;
        if (preg_match('/^[a-f0-9]{2}$/', $shard) !== 1 || !is_string($builtGeneration) || !is_file($generationRoot . '/hosts/' . $shard . '.php')) {
            _stattic_runtime_rebuild_route_index($privateRoot);
            return;
        }
        if (!isset($affectedShards[$shard])) {
            $reusedShards[$shard] = $builtGeneration;
        }
    }
    foreach (array_keys($affectedShards) as $shard) {
        // PHP normalizes numeric-string array keys (e.g. shard "36") to ints.
        $shard = (string) $shard;
        $contents = ['hostnames' => [], 'host_routes' => []];
        if (isset($shardManifest[$shard])) {
            $existing = @include $generationRoot . '/hosts/' . $shard . '.php';
            if (!is_array($existing) || !is_array($existing['hostnames'] ?? null) || !is_array($existing['host_routes'] ?? null)) {
                _stattic_runtime_rebuild_route_index($privateRoot);
                return;
            }
            $contents = ['hostnames' => $existing['hostnames'], 'host_routes' => $existing['host_routes']];
        }
        $freshShards[$shard] = _stattic_runtime_apply_host_updates($contents, $shard, $affected, $updatedHostnames, $updatedHostRoutes);
    }

    if ($wildcardAffected) {
        $existing = @include $generationRoot . '/wildcards.php';
        if (!is_array($existing) || !is_array($existing['hostnames'] ?? null) || !is_array($existing['host_routes'] ?? null)) {
            _stattic_runtime_rebuild_route_index($privateRoot);
            return;
        }
        $contents = ['hostnames' => $existing['hostnames'], 'host_routes' => $existing['host_routes']];
        $wildcards = ['fresh' => _stattic_runtime_apply_host_updates($contents, null, $affected, $updatedHostnames, $updatedHostRoutes)];
    } else {
        if (!is_file($generationRoot . '/wildcards.php')) {
            _stattic_runtime_rebuild_route_index($privateRoot);
            return;
        }
        $wildcards = ['reuse' => $wildcardsGeneration, 'has_wildcards' => !empty($current['has_wildcards'])];
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

    _stattic_runtime_write_route_generation(
        $privateRoot,
        $freshShards,
        $reusedShards,
        $wildcards,
        $newOwners,
        gmdate('c'),
        $generationRoot
    );
}

// Replaces every affected hostname's entries in one shard payload (or the
// wildcard payload when $shard is null) with the freshly recomputed ones.
function _stattic_runtime_apply_host_updates(array $contents, ?string $shard, array $affected, array $updatedHostnames, array $updatedHostRoutes): array
{
    foreach (array_keys($affected) as $hostname) {
        $hostname = (string) $hostname;
        $isWildcard = str_starts_with($hostname, '*.');
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

// One space's complete contribution to the route index: compiled hostname-intent
// entries (root host entries, prefixed routes, host canonical redirects) plus
// tombstoned hostnames. Shared by the full rebuild and the incremental update.
function _stattic_runtime_space_index_contribution(string $privateRoot, string $spaceId): array
{
    $contribution = ['space_id' => $spaceId, 'hostnames' => [], 'routes' => [], 'canonical' => [], 'tombstones' => [], 'tombstone_reason' => null, 'tombstone_category' => null];
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    $routesConfig = _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json');
    if (is_array($routesConfig) && ($routesConfig['space_id'] ?? null) === $spaceId && is_array($routesConfig['routes'] ?? null)) {
        // THE unified policy lane stamped on every host entry, so the runtime
        // enforcer (access-rules.php) sees serving['policy']; the serving secrets
        // the password rules resolve ride beside it on each host entry. Team plan
        // entitlements (serve-time gating, proxy-routes.md: "activates the moment
        // you upgrade — no redeploy needed") ride the SAME per-space stored-doc
        // pattern, refreshed on every runtime.sync.
        $policy = _stattic_runtime_stored_unified_policy($privateRoot, $spaceId);
        $secrets = _stattic_runtime_stored_policy_secrets($privateRoot, $spaceId);
        $entitlements = _stattic_runtime_stored_entitlements($privateRoot, $spaceId);
        foreach ($routesConfig['routes'] as $route) {
            if (!is_array($route)) {
                continue;
            }
            $compiled = _stattic_runtime_compile_route($privateRoot, $spaceId, $route, $policy, $secrets, $entitlements);
            if (!is_array($compiled)) {
                continue;
            }
            if (!empty($compiled['host_route'])) {
                // Host canonicalization redirects are host-level route actions evaluated before
                // user redirect rules; they must return before version/file metadata loads.
                $contribution['canonical'][$compiled['hostname']][] = $compiled['route'];
            } elseif (($compiled['route']['location'] ?? '/') === '/') {
                $contribution['hostnames'][$compiled['hostname']][] = $compiled['host_entry'];
            } else {
                $contribution['routes'][$compiled['hostname']][] = $compiled['route'];
            }
        }
    }
    $tombstoneConfig = _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
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

// hostname => sorted owning space ids. Builder-only metadata persisted with each
// generation so the incremental update knows which spaces to recompute a
// hostname from (cross-space collisions: a serve entry wins over a tombstone,
// and releasing a hostname must resurface another space's claim).
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

// Merges per-space contributions into final hostname entries and host routes.
// Contributions merge in space-id order (the full rebuild's glob order); intent
// entries merge first across all spaces, tombstones after (a serve entry always
// wins over a tombstone), then per-host routes sort longest-location-first with
// host canonical redirects prepended.
function _stattic_runtime_merge_host_contributions(array $contributions): array
{
    usort($contributions, static fn (array $left, array $right): int => strcmp((string) $left['space_id'], (string) $right['space_id']));
    $hostnames = [];
    $hostRoutes = [];
    $hostCanonicalRoutes = [];
    foreach ($contributions as $contribution) {
        foreach ($contribution['hostnames'] as $hostname => $entries) {
            foreach ($entries as $entry) {
                _stattic_runtime_set_host_entry($hostnames, (string) $hostname, $entry);
                if (isset($entry['response_headers']['X-Robots-Tag'])) {
                    $hostRoutes[$hostname][] = _stattic_runtime_noindex_robots_route();
                }
            }
        }
        _stattic_runtime_append_contribution_routes($contribution['routes'], $hostRoutes);
        _stattic_runtime_append_contribution_routes($contribution['canonical'], $hostCanonicalRoutes);
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
    return ['hostnames' => $hostnames, 'host_routes' => $hostRoutes];
}

function _stattic_runtime_append_contribution_routes(array $routesByHost, array &$bucket): void
{
    foreach ($routesByHost as $hostname => $routes) {
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

function _stattic_runtime_compile_route(string $privateRoot, string $spaceId, array $route, array $policy = ['rules' => []], array $secrets = [], array $entitlements = []): ?array
{
    $hostname = is_string($route['hostname'] ?? null) ? $route['hostname'] : '';
    $target = is_array($route['target'] ?? null) ? $route['target'] : [];
    if ($hostname === '') {
        return null;
    }
    $location = _stattic_runtime_route_path_prefix($route);
    if (($target['type'] ?? null) === 'host_redirect') {
        $status = (int) ($target['status'] ?? 0);
        if (
            !is_string($target['destination'] ?? null)
            || !_stattic_runtime_redirect_target_safe($target['destination'], 'redirect')
            || !in_array($status, [301, 302, 307, 308], true)
        ) {
            return null;
        }
        return [
            'hostname' => $hostname,
            'host_route' => true,
            'route' => [
                'route_action' => [
                    'action' => 'redirect',
                    'destination' => (string) $target['destination'],
                    'status' => $status,
                    'cache_control' => _stattic_runtime_redirect_cache_control($status),
                    'methods' => ['GET', 'HEAD'],
                ],
                'location' => $location,
            ],
        ];
    }
    if (($target['type'] ?? null) === 'host_proxy') {
        if (!is_string($target['upstream'] ?? null)) {
            return null;
        }
        $noIndex = !empty($route['options']['noindex']);
        $action = [
            'action' => 'proxy',
            'upstream' => (string) $target['upstream'],
            'target_prefix' => '/',
            'methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'headers' => [],
            'forwardHeaders' => [],
            'bodySizeLimitBytes' => 10485760,
            'timeoutSeconds' => 30,
            'connectTimeoutSeconds' => 10,
        ];
        if ($noIndex) {
            $action['response_headers'] = ['X-Robots-Tag' => 'noindex, nofollow'];
        }
        return [
            'hostname' => $hostname,
            'host_route' => true,
            'route' => [
                'route_action' => $action,
                'location' => $location,
            ],
        ];
    }
    $targetSpaceId = is_string($target['space_id'] ?? null) ? $target['space_id'] : $spaceId;
    $resolved = _stattic_runtime_resolve_route_target($privateRoot, $targetSpaceId, $target);
    $versionId = is_array($resolved) && is_string($resolved['version_id'] ?? null) ? $resolved['version_id'] : null;
    $config = is_array($resolved['config'] ?? null) ? $resolved['config'] : [];
    if ($versionId === null) {
        if (!empty($target['static_mount'])) {
            _stattic_json_response(409, ['error' => [
                'code' => 'static_mount_version_unavailable',
                'message' => 'The mounted immutable version is not available on this runtime.',
            ]]);
        }
        return null;
    }
    $entry = [
        'route_action' => [
            'action' => 'serve',
            'version_id' => $versionId,
            'space_id' => $targetSpaceId,
            'target_prefix' => (($route['mount'] ?? 'strip_prefix') === 'preserve_path') ? $location : '/',
            ...(!empty($target['static_mount']) ? ['static_mount' => true, 'mount_prefix' => $location] : []),
        ],
        'location' => $location,
    ];
    $noIndex = !empty($route['options']['noindex']);
    $compiled = [
        'hostname' => $hostname,
        'route' => $entry,
    ];
    if (empty($target['static_mount'])) {
        $compiled['host_entry'] = [
            'route_action' => [
                'action' => 'serve',
                'version_id' => $versionId,
                'space_id' => $spaceId,
                'target_prefix' => '/',
            ],
            'response_headers' => $noIndex ? ['X-Robots-Tag' => 'noindex, nofollow'] : [],
            'runtime_config' => $config,
            'admission' => is_array($config['admission'] ?? null) ? $config['admission'] : [],
            // Access-policy match context: direct version routes are the immutable
            // version URLs; route-pointer routes carry their compiled route name.
            'route_name' => is_string($resolved['route_name'] ?? null) ? $resolved['route_name'] : null,
            'immutable' => ($target['type'] ?? null) === 'version',
            // Ready timestamp for immutable version hosts: rules carrying windowDays
            // (the plan public-access window) compare request time against it locally.
            'ready_at' => isset($resolved['ready_at']) && is_int($resolved['ready_at']) ? $resolved['ready_at'] : null,
            // THE unified policy lane ({ rules }) read by runtime/access-rules.php
            // via $serving['policy'], plus the serving secrets its password rules
            // resolve. Both travel per host entry.
            'policy' => $policy,
            'secrets' => $secrets,
            // Serve-time plan entitlements (proxy-routes.md gating): read by
            // runtime/redirects.php via $serving['entitlements'] to decide whether a
            // `planGated` proxy rule may run RIGHT NOW, independent of what the
            // compiled version artifact baked at publish time.
            'entitlements' => $entitlements,
        ];
    }
    return $compiled;
}

// Read the stored unified policy lane for a space: { rules: RuntimeRule[], issuers? }.
// This is THE access policy the runtime enforcer reads (serving['policy']).
// Returns { rules: [] } when absent or malformed so serving compile degrades to
// "no access control".
function _stattic_runtime_stored_unified_policy(string $privateRoot, string $spaceId): array
{
    $stored = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/policy.json');
    if (!is_array($stored) || !is_array($stored['policy'] ?? null) || !is_array($stored['policy']['rules'] ?? null)) {
        return ['rules' => [], 'sessionVersion' => 0];
    }
    $policy = ['rules' => $stored['policy']['rules']];
    if (is_array($stored['policy']['issuers'] ?? null)) {
        $policy['issuers'] = $stored['policy']['issuers'];
    }
    // sessionVersion travels on the host-entry policy so the enforcer can reject
    // a visitor token whose `sv` mismatches (logout-all / password rotation).
    $policy['sessionVersion'] = isset($stored['policy']['sessionVersion']) ? (int) $stored['policy']['sessionVersion'] : 0;
    return $policy;
}

// Read the stored serving-secret map for a space: { <name>: <value> }. The
// password rules in the unified policy resolve a `secret:<name>` ref against
// this map (serving['secrets']). Returns [] when absent or malformed.
function _stattic_runtime_stored_policy_secrets(string $privateRoot, string $spaceId): array
{
    $stored = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/policy-secrets.json');
    if (!is_array($stored) || !is_array($stored['secrets'] ?? null)) {
        return [];
    }
    $secrets = [];
    foreach ($stored['secrets'] as $name => $value) {
        if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
            $secrets[$name] = $value;
        }
    }
    return $secrets;
}

// Read the stored plan-entitlements doc for a space: { externalProxy: bool }.
// This is the SERVE-TIME source of truth for `planGated` compiled rules
// (proxy-routes.md: "activates the moment you upgrade — no redeploy needed").
// FAIL CLOSED: absent/malformed doc (never synced yet, or a sync lag) returns
// every entitlement false — a lagging sync may only withhold a capability, it
// must never grant one that hasn't been confirmed.
function _stattic_runtime_stored_entitlements(string $privateRoot, string $spaceId): array
{
    $stored = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/entitlements.json');
    if (!is_array($stored) || !is_array($stored['entitlements'] ?? null)) {
        return ['externalProxy' => false];
    }
    return ['externalProxy' => !empty($stored['entitlements']['externalProxy'])];
}

// Unified policy doc (policyDocSchema): { rules: RuntimeRule[], issuers? }. The unified
// Rule has no version literal — { match, effect, auth? } where effect is one of
// allow|deny|challenge and auth (when present) carries a password and/or token
// leg. The runtime enforcer (runtime/access-rules.php) reads this verbatim, so
// the writer normalizes rather than fully re-validating every match sub-field;
// it keeps recognized match keys and drops unknown ones.
function _stattic_runtime_unified_policy(mixed $raw): array
{
    if (!is_array($raw) || !is_array($raw['rules'] ?? null)) {
        _stattic_runtime_invalid_policy('Unified policy requires a rules array.');
    }
    $rules = [];
    foreach ($raw['rules'] as $rule) {
        $rules[] = _stattic_runtime_unified_policy_rule($rule);
    }
    $policy = ['rules' => $rules];
    $issuers = [];
    foreach (is_array($raw['issuers'] ?? null) ? $raw['issuers'] : [] as $issuer) {
        if (!is_array($issuer)) {
            _stattic_runtime_invalid_policy('Unified policy issuer must be an object.');
        }
        $issuers[] = $issuer;
    }
    if ($issuers !== []) {
        $policy['issuers'] = $issuers;
    }
    // Space session version (access-plan §3.1/§4.4): visitor tokens must carry a
    // matching `sv`; bumping it (logout-all) re-keys the space-local `pw:` mint.
    if (array_key_exists('sessionVersion', $raw) && $raw['sessionVersion'] !== null) {
        if (!is_int($raw['sessionVersion']) && !(is_string($raw['sessionVersion']) && ctype_digit($raw['sessionVersion']))) {
            _stattic_runtime_invalid_policy('Unified policy sessionVersion must be a non-negative integer.');
        }
        $policy['sessionVersion'] = (int) $raw['sessionVersion'];
    }
    return $policy;
}

function _stattic_runtime_unified_policy_rule(mixed $rule): array
{
    if (!is_array($rule)) {
        _stattic_runtime_invalid_policy('Unified policy rule must be an object.');
    }
    $effect = $rule['effect'] ?? null;
    if (!in_array($effect, ['allow', 'deny', 'challenge'], true)) {
        _stattic_runtime_invalid_policy('Unified policy rule effect is invalid.');
    }
    $rawMatch = $rule['match'] ?? [];
    if (!is_array($rawMatch)) {
        _stattic_runtime_invalid_policy('Unified policy rule match must be an object.');
    }
    $match = [];
    // Scalar string match keys (host, hostPattern, hostTemplate, pathPattern, channel, agent, country).
    foreach (['host', 'hostPattern', 'hostTemplate', 'pathPattern', 'channel', 'agent', 'country'] as $key) {
        if (!array_key_exists($key, $rawMatch)) {
            continue;
        }
        if (!is_string($rawMatch[$key]) || $rawMatch[$key] === '') {
            _stattic_runtime_invalid_policy('Unified policy rule match.' . $key . ' is invalid.');
        }
        $match[$key] = $rawMatch[$key];
    }
    if (array_key_exists('ipCidrs', $rawMatch)) {
        if (!is_array($rawMatch['ipCidrs'])) {
            _stattic_runtime_invalid_policy('Unified policy rule match.ipCidrs must be an array.');
        }
        $cidrs = [];
        foreach ($rawMatch['ipCidrs'] as $cidr) {
            if (!is_string($cidr) || $cidr === '') {
                _stattic_runtime_invalid_policy('Unified policy rule match.ipCidrs entries must be non-empty strings.');
            }
            $cidrs[] = $cidr;
        }
        $match['ipCidrs'] = $cidrs;
    }
    if (array_key_exists('countryNotIn', $rawMatch)) {
        if (!is_array($rawMatch['countryNotIn']) || $rawMatch['countryNotIn'] === [] || count($rawMatch['countryNotIn']) > 250) {
            _stattic_runtime_invalid_policy('Unified policy rule match.countryNotIn must contain 1-250 country codes.');
        }
        $countries = [];
        foreach ($rawMatch['countryNotIn'] as $country) {
            if (!is_string($country) || strlen($country) !== 2) {
                _stattic_runtime_invalid_policy('Unified policy rule match.countryNotIn entries must be two-character strings.');
            }
            $countries[] = strtoupper($country);
        }
        $match['countryNotIn'] = $countries;
    }
    if (array_key_exists('header', $rawMatch)) {
        $header = $rawMatch['header'];
        if (!is_array($header) || !is_string($header['name'] ?? null) || $header['name'] === '' || !is_string($header['value'] ?? null)) {
            _stattic_runtime_invalid_policy('Unified policy rule match.header is invalid.');
        }
        $match['header'] = ['name' => $header['name'], 'value' => $header['value']];
    }
    $normalized = ['match' => $match, 'effect' => $effect];
    // Stable rule id (referenced by `pw:{ruleId}` grants and the local mint).
    if (array_key_exists('id', $rule) && $rule['id'] !== null) {
        if (!is_string($rule['id']) || $rule['id'] === '') {
            _stattic_runtime_invalid_policy('Unified policy rule id must be a non-empty string.');
        }
        $normalized['id'] = $rule['id'];
    }
    if (array_key_exists('auth', $rule) && $rule['auth'] !== null) {
        $normalized['auth'] = _stattic_runtime_unified_policy_auth($rule['auth']);
    }
    // managedBy (X-34): which product surface owns a managed block. The runtime
    // stores it verbatim; ownership is a schema field, never a reasonCode.
    if (array_key_exists('managedBy', $rule) && $rule['managedBy'] !== null) {
        if (!is_string($rule['managedBy']) || $rule['managedBy'] === '') {
            _stattic_runtime_invalid_policy('Unified policy rule managedBy must be a non-empty string.');
        }
        $normalized['managedBy'] = $rule['managedBy'];
    }
    // expiresAt (X-10): unix seconds; the runtime skips an expired rule at match
    // time (one timestamp compare, no recompile needed).
    if (array_key_exists('expiresAt', $rule) && $rule['expiresAt'] !== null) {
        if ((!is_int($rule['expiresAt']) && !(is_string($rule['expiresAt']) && ctype_digit($rule['expiresAt']))) || (int) $rule['expiresAt'] < 0) {
            _stattic_runtime_invalid_policy('Unified policy rule expiresAt must be a non-negative integer.');
        }
        $normalized['expiresAt'] = (int) $rule['expiresAt'];
    }
    // reasonCode/message surface on a deny (X-Spacefast-Reason header + platform
    // page body); the runtime enforcer reads them verbatim.
    foreach (['reasonCode', 'message'] as $key) {
        if (!array_key_exists($key, $rule) || $rule[$key] === null) {
            continue;
        }
        if (!is_string($rule[$key]) || $rule[$key] === '') {
            _stattic_runtime_invalid_policy('Unified policy rule ' . $key . ' must be a non-empty string.');
        }
        $normalized[$key] = $rule[$key];
    }
    return $normalized;
}

// The one `auth` object (access-plan X-32): {requiredGrants, issuers?, acquire?}.
// ONE satisfaction test lives in the runtime (grants ∩ requiredGrants); `acquire`
// only configures how the challenge page lets a visitor obtain a token.
function _stattic_runtime_unified_policy_auth(mixed $auth): array
{
    if (!is_array($auth)) {
        _stattic_runtime_invalid_policy('Unified policy rule auth must be an object.');
    }
    $requiredGrants = $auth['requiredGrants'] ?? null;
    if (!is_array($requiredGrants) || $requiredGrants === []) {
        _stattic_runtime_invalid_policy('Unified policy rule auth.requiredGrants is invalid.');
    }
    $grants = [];
    foreach ($requiredGrants as $grant) {
        if (!is_string($grant) || $grant === '') {
            _stattic_runtime_invalid_policy('Unified policy rule auth.requiredGrants entries must be non-empty strings.');
        }
        $grants[] = $grant;
    }
    $normalized = ['requiredGrants' => $grants];
    if (array_key_exists('issuers', $auth) && $auth['issuers'] !== null) {
        if (!is_array($auth['issuers'])) {
            _stattic_runtime_invalid_policy('Unified policy rule auth.issuers must be an array.');
        }
        $normalized['issuers'] = $auth['issuers'];
    }
    if (array_key_exists('acquire', $auth) && $auth['acquire'] !== null) {
        if (!is_array($auth['acquire'])) {
            _stattic_runtime_invalid_policy('Unified policy rule auth.acquire must be an array.');
        }
        $acquire = [];
        foreach ($auth['acquire'] as $entry) {
            $acquire[] = _stattic_runtime_unified_policy_acquire($entry);
        }
        $normalized['acquire'] = $acquire;
    }
    return $normalized;
}

function _stattic_runtime_unified_policy_acquire(mixed $entry): array
{
    if (!is_array($entry)) {
        _stattic_runtime_invalid_policy('Unified policy rule auth.acquire entries must be objects.');
    }
    $type = $entry['type'] ?? null;
    if ($type === 'password') {
        if (!is_string($entry['ref'] ?? null) || $entry['ref'] === '' || !in_array($entry['transport'] ?? null, ['basic', 'form'], true)) {
            _stattic_runtime_invalid_policy('Unified policy rule auth.acquire password entry is invalid.');
        }
        $normalized = ['type' => 'password', 'ref' => $entry['ref'], 'transport' => $entry['transport']];
        if (array_key_exists('username', $entry) && $entry['username'] !== null) {
            if (!is_string($entry['username']) || $entry['username'] === '') {
                _stattic_runtime_invalid_policy('Unified policy rule auth.acquire password.username is invalid.');
            }
            $normalized['username'] = $entry['username'];
        }
        return $normalized;
    }
    if ($type === 'login') {
        if (!is_string($entry['url'] ?? null) || $entry['url'] === '') {
            _stattic_runtime_invalid_policy('Unified policy rule auth.acquire login.url is invalid.');
        }
        $normalized = ['type' => 'login', 'url' => $entry['url']];
        if (array_key_exists('label', $entry) && $entry['label'] !== null) {
            if (!is_string($entry['label']) || $entry['label'] === '') {
                _stattic_runtime_invalid_policy('Unified policy rule auth.acquire login.label is invalid.');
            }
            $normalized['label'] = $entry['label'];
        }
        return $normalized;
    }
    _stattic_runtime_invalid_policy('Unified policy rule auth.acquire type must be "password" or "login".');
}

function _stattic_runtime_invalid_policy(string $message): void
{
    _stattic_json_response(422, ['error' => ['code' => 'invalid_policy', 'message' => $message]]);
}

function _stattic_runtime_resolve_route_target(string $privateRoot, string $spaceId, array $target): ?array
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
        $servingPath = _spacefast_version_root($privateRoot, $spaceId, $versionId) . '/serving.php';
        if (!is_file($servingPath)) {
            return null;
        }
        // The ready timestamp is the stamp persisted at finalize (control-plane
        // readyAt wins; stamped once otherwise). Never a filesystem mtime —
        // export/import and provider migration re-materialize serving.php with
        // fresh mtimes, which would restart the plan's public window.
        $versionMetadata = _stattic_runtime_read_json(_spacefast_version_root($privateRoot, $spaceId, $versionId) . '/metadata.json');
        $readyAt = is_array($versionMetadata) && isset($versionMetadata['readyAt']) && is_int($versionMetadata['readyAt'])
            ? $versionMetadata['readyAt']
            : null;
        return [
            'version_id' => $versionId,
            'config' => [],
            'ready_at' => $readyAt,
        ];
    }
    if ($type === 'route') {
        $routeName = is_string($target['route_name'] ?? null) ? _stattic_runtime_id($target['route_name'], 'route_name') : 'production';
        $pointer = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/routes/' . $routeName . '.json');
        if (!is_array($pointer) || !is_string($pointer['version_id'] ?? null)) {
            return null;
        }
        $versionId = $pointer['version_id'];
        if (!is_file(_spacefast_version_root($privateRoot, $spaceId, $versionId) . '/serving.php')) {
            return null;
        }
        return [
            'version_id' => $versionId,
            'route_name' => $routeName,
            'config' => is_array($pointer['config'] ?? null) ? $pointer['config'] : [],
        ];
    }
    return null;
}

function _stattic_runtime_set_host_entry(array &$hostnames, string $hostname, array $entry): void
{
    $existing = $hostnames[$hostname] ?? null;
    $existingAction = is_array($existing) && is_array($existing['route_action'] ?? null) ? $existing['route_action'] : null;
    $entryAction = is_array($entry['route_action'] ?? null) ? $entry['route_action'] : null;
    if (
        is_array($existingAction)
        && ($existingAction['action'] ?? null) === 'serve'
        && is_string($existingAction['version_id'] ?? null)
        && (!is_array($entryAction) || ($entryAction['action'] ?? null) !== 'serve' || !is_string($entryAction['version_id'] ?? null))
    ) {
        return;
    }
    $hostnames[$hostname] = $entry;
}

function _stattic_runtime_set_tombstone_host_entry(array &$hostnames, string $hostname, string $spaceId, ?string $reason = null, ?string $category = null): void
{
    $existing = $hostnames[$hostname] ?? null;
    $existingAction = is_array($existing) && is_array($existing['route_action'] ?? null) ? $existing['route_action'] : null;
    if (is_array($existingAction) && ($existingAction['action'] ?? null) === 'serve' && is_string($existingAction['version_id'] ?? null)) {
        return;
    }
    $variant = _stattic_runtime_tombstone_variant($reason, $category);
    $action = [
        'action' => 'tombstone',
        'status' => $variant['status'],
        'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
        'space_id' => $spaceId,
        // The served tombstone page is selected from this page_id (C10); serving
        // differentiates the body/headers by it.
        'page_id' => $variant['page_id'],
        'methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    ];
    $hostnames[$hostname] = [
        'route_action' => $action,
        // Robots posture comes from the shared variant table: CSAM must not
        // advertise a noindex tag (it leaks that the host exists).
        'response_headers' => (SPACEFAST_TOMBSTONE_VARIANTS[$variant['page_id']]['robots'] ?? true)
            ? ['X-Robots-Tag' => 'noindex, nofollow']
            : [],
        'runtime_config' => [],
    ];
}

// Maps a space's tombstone disabled reason/category to the served page variant
// (C10). CSAM is a plain 404 (no signal the host ever existed); copyright/DMCA
// is 451; a suspended tenant gets a neutral suspended page; everything else (abuse
// takedowns) is the generic 404 "unavailable" page. Category wins over reason;
// an unknown/absent value degrades to the generic page.
function _stattic_runtime_tombstone_variant(?string $reason, ?string $category): array
{
    $key = is_string($category) && $category !== '' ? strtolower($category) : (is_string($reason) ? strtolower($reason) : '');
    $pageId = match ($key) {
        'csam' => 'tombstone-csam',
        'copyright', 'dmca' => 'tombstone-dmca',
        'tenant_suspended', 'account_suspended' => 'tombstone-suspended',
        'visit_cap_exceeded' => 'tombstone-visit-cap',
        default => 'tombstone-generic',
    };
    return ['page_id' => $pageId, 'status' => SPACEFAST_TOMBSTONE_VARIANTS[$pageId]['status']];
}

function _stattic_runtime_route_host_shard(string $hostname): string
{
    return substr(hash('sha256', $hostname), 0, 2);
}

// Splits a complete hostnames/host_routes index into per-shard payloads plus
// the wildcard payload (pure; no artifact metadata yet).
function _stattic_runtime_split_route_index(array $hostnames, array $hostRoutes): array
{
    $shards = [];
    $wildcards = ['hostnames' => [], 'host_routes' => []];
    foreach (['hostnames' => $hostnames, 'host_routes' => $hostRoutes] as $field => $entries) {
        foreach ($entries as $hostname => $value) {
            if (!is_string($hostname)) {
                continue;
            }
            if (str_starts_with($hostname, '*.')) {
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

// Publishes a complete immutable route generation (spec "Runtime Artifacts"):
// fresh shards are written with this generation's stamp; reused shards are
// HARDLINKED from the previous generation and keep the generation stamp they
// were built in — current.php's `shards` manifest records the built generation
// per shard so serve-time validation stays exact. After the current pointer
// flips, generations past a short in-flight grace window are pruned.
function _stattic_runtime_write_route_generation(string $privateRoot, array $freshShards, array $reusedShards, array $wildcards, array $owners, string $generatedAt, string $reuseRoot = ''): void
{
    $generation = 'gen-' . str_replace('.', '', (string) microtime(true)) . '-' . bin2hex(random_bytes(4));
    $generationRoot = $privateRoot . '/routes/generations/' . $generation;
    $hostsRoot = $generationRoot . '/hosts';
    _stattic_runtime_mkdir($hostsRoot);

    $manifest = [];
    $writtenShards = [];
    foreach ($freshShards as $shard => $contents) {
        $shard = (string) $shard;
        if (($contents['hostnames'] ?? []) === [] && ($contents['host_routes'] ?? []) === []) {
            continue;
        }
        _stattic_runtime_write_php_atomic($hostsRoot . '/' . $shard . '.php', [
            ..._stattic_runtime_artifact_metadata($generatedAt),
            'artifact_kind' => 'route_host_shard',
            'generation' => $generation,
            'hostnames' => $contents['hostnames'] ?? [],
            'host_routes' => $contents['host_routes'] ?? [],
        ]);
        $manifest[$shard] = $generation;
        $writtenShards[$shard] = $generation;
    }
    foreach ($reusedShards as $shard => $builtGeneration) {
        $shard = (string) $shard;
        _stattic_runtime_link_route_artifact($reuseRoot . '/hosts/' . $shard . '.php', $hostsRoot . '/' . $shard . '.php');
        $manifest[$shard] = (string) $builtGeneration;
    }
    ksort($manifest);

    if (is_string($wildcards['reuse'] ?? null)) {
        _stattic_runtime_link_route_artifact($reuseRoot . '/wildcards.php', $generationRoot . '/wildcards.php');
        $wildcardsGeneration = $wildcards['reuse'];
        $hasWildcards = !empty($wildcards['has_wildcards']);
    } else {
        $payload = is_array($wildcards['fresh'] ?? null) ? $wildcards['fresh'] : ['hostnames' => [], 'host_routes' => []];
        $hasWildcards = ($payload['hostnames'] ?? []) !== [] || ($payload['host_routes'] ?? []) !== [];
        _stattic_runtime_write_php_atomic($generationRoot . '/wildcards.php', [
            ..._stattic_runtime_artifact_metadata($generatedAt),
            'artifact_kind' => 'route_wildcards',
            'generation' => $generation,
            'hostnames' => $payload['hostnames'] ?? [],
            'host_routes' => $payload['host_routes'] ?? [],
        ]);
        $wildcardsGeneration = $generation;
    }

    _stattic_runtime_write_php_atomic($generationRoot . '/owners.php', [
        ..._stattic_runtime_artifact_metadata($generatedAt),
        'artifact_kind' => 'route_owners',
        'generation' => $generation,
        'owners' => $owners,
    ]);
    // Validate only the artifacts built in THIS generation; reused (hardlinked)
    // artifacts were validated when their generation was published and are
    // immutable since. Re-including them here would also re-execute any
    // manually corrupted artifact — corruption recovery is repair_space's
    // full rebuild, not the hot mutation path.
    _stattic_runtime_validate_route_generation(
        $generationRoot,
        $writtenShards,
        $wildcardsGeneration === $generation ? $generation : null
    );
    _stattic_runtime_write_php_atomic($privateRoot . '/routes/current.php', [
        ..._stattic_runtime_artifact_metadata($generatedAt),
        'artifact_kind' => 'route_current',
        'generation' => $generation,
        'has_wildcards' => $hasWildcards,
        'shards' => $manifest,
        'wildcards_generation' => $wildcardsGeneration,
    ]);
    _stattic_runtime_prune_route_generations($privateRoot, $generation);
}

function _stattic_runtime_link_route_artifact(string $source, string $target): void
{
    _stattic_runtime_assert_private_path($source);
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_assert_private_path($target);
    if (@link($source, $target)) {
        return;
    }
    if (!@copy($source, $target)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_route_artifact_link_failed', 'message' => 'Runtime route artifact could not be linked into the new generation.']]);
    }
}

// Generations are immutable; once current.php moves on, an old generation only
// matters to requests already mid-flight. Keep the current generation plus a
// short grace window and delete the rest — hardlink reuse means shard contents
// shared with newer generations survive the unlink.
const STATTIC_RUNTIME_ROUTE_GENERATION_GRACE_SECONDS = 300;

const STATTIC_RUNTIME_ZERO_CAPABILITIES = ['db', 'fetch', 'auth', 'env', 'realtime', 'logging'];

// ABI pins stamped into Zero program artifacts at compile time and re-checked
// by the artifact validators — one definition for the writer and both readers.
const STATTIC_RUNTIME_ZERO_RUNNER_ABI = 'stattic-zero-runner-abi-v2';
const STATTIC_RUNTIME_ZERO_QUICKJS_ABI = 'rquickjs-0.12';

function _stattic_runtime_prune_route_generations(string $privateRoot, string $currentGeneration): void
{
    $deadline = time() - STATTIC_RUNTIME_ROUTE_GENERATION_GRACE_SECONDS;
    foreach (glob($privateRoot . '/routes/generations/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_string($dir) || basename($dir) === $currentGeneration) {
            continue;
        }
        $mtime = @filemtime($dir);
        if (is_int($mtime) && $mtime < $deadline) {
            _stattic_runtime_rm_recursive($dir);
        }
    }
}

function _stattic_runtime_validate_route_generation(string $generationRoot, array $shardManifest, ?string $wildcardsGeneration): void
{
    if ($wildcardsGeneration !== null) {
        $wildcards = @include $generationRoot . '/wildcards.php';
        if (!is_array($wildcards) || !_stattic_runtime_route_artifact_metadata_valid_lazy($wildcards, $wildcardsGeneration, 'route_wildcards')) {
            _stattic_runtime_route_generation_validation_failed('wildcards');
        }
        foreach (['hostnames', 'host_routes'] as $key) {
            if (!is_array($wildcards[$key] ?? null)) {
                _stattic_runtime_route_generation_validation_failed('wildcards');
            }
        }
        _stattic_runtime_validate_route_entries($wildcards['hostnames'], $wildcards['host_routes'], 'wildcards');
    }

    foreach ($shardManifest as $shard => $builtGeneration) {
        $shard = (string) $shard;
        if (!preg_match('/^[a-f0-9]{2}$/', $shard) || !is_string($builtGeneration)) {
            _stattic_runtime_route_generation_validation_failed('hosts');
        }
        $loaded = @include $generationRoot . '/hosts/' . $shard . '.php';
        if (!is_array($loaded) || !_stattic_runtime_route_artifact_metadata_valid_lazy($loaded, $builtGeneration, 'route_host_shard')) {
            _stattic_runtime_route_generation_validation_failed('hosts');
        }
        foreach (['hostnames', 'host_routes'] as $key) {
            if (!is_array($loaded[$key] ?? null)) {
                _stattic_runtime_route_generation_validation_failed('hosts');
            }
        }
        _stattic_runtime_validate_route_entries($loaded['hostnames'], $loaded['host_routes'], 'hosts');
    }
}

function _stattic_runtime_validate_route_entries(array $hostnames, array $hostRoutes, string $artifact): void
{
    foreach ($hostnames as $hostname => $entry) {
        if (!is_string($hostname) || !is_array($entry) || !_stattic_runtime_route_action_valid($entry['route_action'] ?? null, true)) {
            _stattic_runtime_route_generation_validation_failed($artifact);
        }
    }
    foreach ($hostRoutes as $hostname => $routes) {
        if (!is_string($hostname) || !is_array($routes)) {
            _stattic_runtime_route_generation_validation_failed($artifact);
        }
        foreach ($routes as $route) {
            if (!is_array($route) || !_stattic_runtime_route_entry_valid($route)) {
                _stattic_runtime_route_generation_validation_failed($artifact);
            }
        }
    }
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
        // Reason-differentiated tombstones (C10): generic/CSAM 404, DMCA 451, a
        // suspended tenant 402. page_id is mandatory.
        return $allowTombstone
            && in_array((int) ($action['status'] ?? 0), [402, 404, 451], true)
            && is_string($action['page_id'] ?? null)
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && _stattic_runtime_optional_id_valid($action['space_id'] ?? null)
            && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    }
    if ($action['action'] === 'platform_error') {
        return ($action['page_id'] ?? null) === 'version-pending'
            && (int) ($action['status'] ?? 0) === 503
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && is_string($action['message'] ?? null)
            && $action['message'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    }
    return false;
}

function _stattic_runtime_optional_id_valid(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1 && !str_contains($value, '..');
}

function _stattic_runtime_proxy_route_policy_valid(array $action): bool
{
    // Same shape contract the serve-time enforcer applies (shared/egress.php),
    // plus the compile-time-only egress check on the upstream host.
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
    return in_array($scheme, ['http', 'https'], true)
        && $host !== ''
        && _stattic_egress_host_allowed($host, $port)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function _stattic_runtime_route_generation_validation_failed(string $artifact): void
{
    _stattic_json_response(422, [
        'error' => [
            'code' => 'runtime_route_generation_validation_failed',
            'message' => 'Runtime route generation validation failed.',
            'details' => ['artifact' => $artifact],
        ],
    ]);
}

function _stattic_runtime_private_files(): array
{
    static $private = null;
    $private ??= SPACEFAST_PRIVATE_COMPILE_FILES + SPACEFAST_PRIVATE_CONFIG_FILES;
    return $private;
}

function _stattic_runtime_zero_activating_action(array $action): array
{
    $methods = is_array($action['methods'] ?? null) ? $action['methods'] : ['GET', 'HEAD'];
    if (is_string($action['operation'] ?? null)) {
        return [
            'action' => 'zero_activating',
            'operation' => $action['operation'],
            'methods' => $methods,
        ];
    }

    return [
        'action' => 'zero_activating',
        'endpoint_id' => is_string($action['endpoint_id'] ?? null) ? $action['endpoint_id'] : '',
        'zero_artifact' => is_string($action['zero_artifact'] ?? null) ? $action['zero_artifact'] : '',
        'methods' => $methods,
    ];
}

function _stattic_runtime_mark_zero_routes_activating(array $routes): array
{
    $marked = ['exact' => [], 'by_first_segment' => [], 'fallback' => []];
    foreach (['exact', 'fallback'] as $bucket) {
        foreach (is_array($routes[$bucket] ?? null) ? $routes[$bucket] : [] as $entry) {
            $marked[$bucket][] = is_array($entry) ? [...$entry, 'activating' => true] : $entry;
        }
    }
    foreach (is_array($routes['by_first_segment'] ?? null) ? $routes['by_first_segment'] : [] as $segment => $entries) {
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $marked['by_first_segment'][$segment][] = is_array($entry) ? [...$entry, 'activating' => true] : $entry;
        }
    }
    return _stattic_runtime_zero_routes_artifact($marked);
}

// Finalize and import use the same fail-safe transition rule: only the
// literal active state enables Zero dispatch. Unknown or absent values keep
// the version activating.
function _stattic_runtime_zero_mode(mixed $raw): string
{
    return $raw === 'active' ? 'active' : 'activating';
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

function _stattic_runtime_subprocess_fail(array $result, string $code, string $message): void
{
    _stattic_json_response(500, ['error' => [
        'code' => $code,
        'message' => _stattic_zero_debug_message($message, $result['stderr']),
        'details' => ['exitCode' => $result['exitCode'], 'stdout' => is_string($result['stdout']) ? substr($result['stdout'], 0, 512) : ''],
    ]]);
}

function _stattic_runtime_import_zero_sidecar(string $outDir, string $versionRoot, string $relPath, callable $valid, string $what): ?array
{
    if (!is_file($outDir . '/' . $relPath)) {
        return null;
    }
    $artifact = _stattic_runtime_read_json($outDir . '/' . $relPath);
    if (!is_array($artifact) || !_stattic_runtime_artifact_metadata_valid_lazy($artifact) || !$valid($artifact)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_compiler_output_invalid', 'message' => 'Runtime compiler emitted malformed ' . $what . '.']]);
    }
    _stattic_runtime_copy_private_file($outDir . '/' . $relPath, $versionRoot . '/' . $relPath);
    return $artifact;
}

function _stattic_runtime_apply_zero_migrations(string $versionRoot): void
{
    if (!is_file($versionRoot . '/zero/migrations.json')) {
        return;
    }
    $zeroConfig = _stattic_runtime_read_json($versionRoot . '/zero/config.json');
    $result = _stattic_runtime_run_subprocess(
        [_stattic_zero_runner_binary(), 'migrate', $versionRoot],
        _stattic_zero_runner_base_env(is_array($zeroConfig) ? $zeroConfig : [])
    );
    if (!$result['spawned']) {
        _stattic_json_response(500, ['error' => ['code' => 'zero_runner_unavailable', 'message' => 'Zero runner migration command is unavailable.']]);
    }
    if ($result['exitCode'] !== 0) {
        _stattic_runtime_subprocess_fail($result, 'zero_migration_failed', 'Zero DB migrations failed.');
    }
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

function _stattic_runtime_zero_routes_artifact(mixed $input): array
{
    if ($input === null) {
        return [];
    }
    if (!is_array($input)) {
        _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero routes artifact must be an object.']]);
    }

    $exact = _stattic_runtime_zero_route_entries($input['exact'] ?? []);
    $byFirstSegment = [];
    $rawByFirstSegment = $input['by_first_segment'] ?? [];
    if (!is_array($rawByFirstSegment)) {
        _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route buckets must be an object.']]);
    }
    foreach ($rawByFirstSegment as $segment => $entries) {
        if (!is_string($segment) || !_stattic_runtime_zero_route_segment_valid($segment) || !is_array($entries)) {
            _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route bucket is invalid.']]);
        }
        $normalizedEntries = _stattic_runtime_zero_route_entries($entries);
        if ($normalizedEntries !== []) {
            $byFirstSegment[$segment] = $normalizedEntries;
        }
    }

    $fallback = _stattic_runtime_zero_route_entries($input['fallback'] ?? []);
    if ($exact === [] && $byFirstSegment === [] && $fallback === []) {
        return [];
    }

    return [
        'exact' => $exact,
        'by_first_segment' => $byFirstSegment,
        'fallback' => $fallback,
    ];
}

function _stattic_runtime_zero_route_entries(mixed $entries): array
{
    if ($entries === null || $entries === []) {
        return [];
    }
    if (!is_array($entries)) {
        _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route entries must be an array.']]);
    }

    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route entry is invalid.']]);
        }
        $method = is_string($entry['method'] ?? null) ? strtoupper($entry['method']) : '';
        $pattern = is_string($entry['pattern'] ?? null) ? _stattic_runtime_zero_route_pattern($entry['pattern']) : '';
        $endpointId = is_string($entry['endpoint_id'] ?? null) ? $entry['endpoint_id'] : '';
        $artifact = is_string($entry['artifact'] ?? null) ? $entry['artifact'] : '';
        if (
            !_stattic_lookup_zero_methods_valid([$method])
            || $pattern === ''
            || $endpointId === ''
            || strlen($endpointId) > 256
            || !_stattic_runtime_relative_artifact_path_valid($artifact)
        ) {
            _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route entry is invalid.']]);
        }
        $out = [
            'method' => $method,
            'pattern' => $pattern,
            'endpoint_id' => $endpointId,
            'artifact' => $artifact,
        ];
        if (array_key_exists('schema_hash', $entry)) {
            if (!(is_string($entry['schema_hash']) || $entry['schema_hash'] === null)) {
                _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route schema hash is invalid.']]);
            }
            $out['schema_hash'] = $entry['schema_hash'];
        }
        if (array_key_exists('capabilities', $entry)) {
            $out['capabilities'] = _stattic_runtime_zero_endpoint_capabilities($entry['capabilities']);
        }
        if (array_key_exists('zero_indexed', $entry)) {
            if (!is_bool($entry['zero_indexed'])) {
                _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route indexed marker is invalid.']]);
            }
            $out['zero_indexed'] = $entry['zero_indexed'];
        }
        if (array_key_exists('activating', $entry)) {
            if (!is_bool($entry['activating'])) {
                _stattic_json_response(422, ['error' => ['code' => 'zero_routes_invalid', 'message' => 'Zero route activating marker is invalid.']]);
            }
            $out['activating'] = $entry['activating'];
        }
        $normalized[] = $out;
    }

    return $normalized;
}

function _stattic_runtime_zero_route_pattern(string $pattern): string
{
    $patternLength = _stattic_runtime_utf16_code_units($pattern);
    if (
        $patternLength === null
        || $patternLength > 2048
        || !_stattic_runtime_nfc_string_valid($pattern)
        || !_stattic_runtime_route_pattern_valid($pattern)
        || str_contains($pattern, '//')
        || str_contains($pattern, '*')
        || str_contains($pattern, '?')
        || str_contains($pattern, '#')
        || preg_match('/[\x00-\x1f\x7f]/', $pattern) === 1
        || str_ends_with($pattern, '/')
    ) {
        return '';
    }
    $segments = explode('/', substr($pattern, 1));
    $lastSegment = count($segments) - 1;
    foreach ($segments as $index => $segment) {
        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || $segment === ':'
            || ($segment === ':splat' && $index !== $lastSegment)
        ) {
            return '';
        }
    }

    if (
        in_array($pattern, ['/', '/index.html', '/client.js', '/auth/callback', '/__spacefast', '/__span'], true)
        || str_starts_with($pattern, '/auth/')
        || str_starts_with($pattern, '/__spacefast/')
        || str_starts_with($pattern, '/__span/')
        || $pattern === '/__stattic'
        || str_starts_with($pattern, '/__stattic/')
    ) {
        return '';
    }

    return $pattern;
}

function _stattic_runtime_nfc_string_valid(string $value): bool
{
    if (preg_match('/[\x80-\xff]/', $value) !== 1) {
        return true;
    }
    if (!class_exists('Normalizer')) {
        return false;
    }
    $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
    return is_string($normalized) && $normalized === $value;
}

function _stattic_runtime_utf16_code_units(string $value): ?int
{
    $units = 0;
    $byteLength = strlen($value);
    for ($index = 0; $index < $byteLength;) {
        $lead = ord($value[$index]);
        if ($lead <= 0x7f) {
            $width = 1;
            $codeUnits = 1;
        } elseif ($lead >= 0xc2 && $lead <= 0xdf) {
            $width = 2;
            $codeUnits = 1;
        } elseif ($lead >= 0xe0 && $lead <= 0xef) {
            $width = 3;
            $codeUnits = 1;
        } elseif ($lead >= 0xf0 && $lead <= 0xf4) {
            $width = 4;
            $codeUnits = 2;
        } else {
            return null;
        }

        if ($index + $width > $byteLength) {
            return null;
        }
        for ($offset = 1; $offset < $width; $offset++) {
            $continuation = ord($value[$index + $offset]);
            if ($continuation < 0x80 || $continuation > 0xbf) {
                return null;
            }
        }
        if ($width === 3) {
            $second = ord($value[$index + 1]);
            if (($lead === 0xe0 && $second < 0xa0) || ($lead === 0xed && $second > 0x9f)) {
                return null;
            }
        } elseif ($width === 4) {
            $second = ord($value[$index + 1]);
            if (($lead === 0xf0 && $second < 0x90) || ($lead === 0xf4 && $second > 0x8f)) {
                return null;
            }
        }

        $units += $codeUnits;
        $index += $width;
    }

    return $units;
}

function _stattic_runtime_zero_route_segment_valid(string $segment): bool
{
    return $segment !== ''
        && $segment[0] !== ':'
        && !str_contains($segment, '/')
        && !str_contains($segment, '\\')
        && !str_contains($segment, "\0")
        && $segment !== '..';
}

// Flattens the three-section zero-routes shape (exact + fallback + the
// by_first_segment buckets, in that order) into one entry list. Guards only the
// containers — entries are yielded as-is so callers keep their own per-entry
// validation.
function _stattic_runtime_zero_routes_all_entries(array $routes): array
{
    $entries = [];
    foreach (['exact', 'fallback'] as $section) {
        foreach (is_array($routes[$section] ?? null) ? $routes[$section] : [] as $entry) {
            $entries[] = $entry;
        }
    }
    foreach (is_array($routes['by_first_segment'] ?? null) ? $routes['by_first_segment'] : [] as $bucket) {
        foreach (is_array($bucket) ? $bucket : [] as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function _stattic_runtime_file_private(string $path, array $privateFiles): bool
{
    $lowerPath = strtolower($path);
    $privateConfigFiles = SPACEFAST_PRIVATE_CONFIG_FILES;
    $segments = explode('/', $lowerPath);
    if (isset($privateFiles[$path]) || isset($privateConfigFiles[$lowerPath]) || $lowerPath === 'theme.json' || in_array('_pages', $segments, true) || $lowerPath === '_layout.html' || str_ends_with($lowerPath, '/_layout.html') || $path === '.well-known/spacefast-runtime' || $path === '.well-known/stattic-runtime' || $path === 'zero' || str_starts_with($path, 'zero/')) {
        return true;
    }
    if ((str_ends_with($lowerPath, '.br') || str_ends_with($lowerPath, '.gz')) && _stattic_runtime_file_private(substr($path, 0, -3), $privateFiles)) {
        return true;
    }
    return _stattic_path_has_hidden_segment($lowerPath);
}

function _stattic_runtime_redirect_cache_control(int $status): string
{
    return in_array($status, [301, 308], true)
        ? 'public, max-age=31536000, immutable'
        : STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
}

function _stattic_runtime_file_metadata_shard(string $path): string
{
    return substr(hash('sha256', $path), 0, 2);
}

function _stattic_runtime_validate_version_artifacts(string $versionRoot, array $filesByPath): void
{
    $serving = @include $versionRoot . '/serving.php';
    if (!is_array($serving) || !_stattic_runtime_artifact_metadata_valid_lazy($serving)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing or has the wrong schema.');
    }
    foreach (['file_shards', 'header_artifact', 'redirect_artifact'] as $flag) {
        if (!isset($serving[$flag]) || !is_bool($serving[$flag])) {
            _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing required artifact flags.');
        }
    }
    if (array_key_exists('zero_routes', $serving) && !is_bool($serving['zero_routes'])) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact zero_routes flag must be a boolean.');
    }
    if (array_key_exists('php_manifest', $serving) && !is_bool($serving['php_manifest'])) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact php_manifest flag must be a boolean.');
    }
    // Additive flag: versions finalized before channel variants existed have
    // no `template_variants` key and read as variant-free.
    if (array_key_exists('template_variants', $serving) && !is_bool($serving['template_variants'])) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact template_variants flag must be a boolean.');
    }
    if (!empty($serving['template_variants'])) {
        $variants = @include $versionRoot . '/template-variants.php';
        if (!is_array($variants) || !_stattic_runtime_artifact_metadata_valid_lazy($variants) || !is_array($variants['routes'] ?? null) || $variants['routes'] === []) {
            _stattic_runtime_artifact_validation_failed('template_variants', 'Template variant artifact is missing or has the wrong schema.');
        }
        foreach ($variants['routes'] as $route => $files) {
            if (!is_string($route) || !is_array($files) || $files === []) {
                _stattic_runtime_artifact_validation_failed('template_variants', 'Template variant routes must map to non-empty path maps.');
            }
            foreach ($files as $path => $meta) {
                if (!is_string($path) || !is_array($meta) || !isset($filesByPath[$path]) || ($meta['disk_path'] ?? null) !== $path) {
                    _stattic_runtime_artifact_validation_failed('template_variants', 'Template variant metadata must point to a committed file.', ['route' => $route, 'path' => $path]);
                }
                $variantFile = $versionRoot . '/files-variants/' . $route . '/' . $path;
                if (!is_file($variantFile) || filesize($variantFile) !== (int) ($meta['size'] ?? -1)) {
                    _stattic_runtime_artifact_validation_failed('template_variants', 'Template variant bytes are missing or do not match the artifact metadata.', ['route' => $route, 'path' => $path]);
                }
            }
        }
    }
    if (!$serving['file_shards']) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact must use file shards.');
    }
    if (!is_array($serving['lookup'] ?? null) || !array_key_exists('fallback', $serving) || !is_array($serving['nearest_404'] ?? null) || !array_key_exists('not_found', $serving) || !is_array($serving['public_files'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing required lookup metadata.');
    }
    if (!is_array($serving['serving_config'] ?? null) || !array_key_exists('index', $serving['serving_config']) || !array_key_exists('fallback', $serving['serving_config']) || !is_bool($serving['serving_config']['listing'] ?? null) || !is_bool($serving['serving_config']['viewer'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing compiled serving config.');
    }
    // Additive key: versions finalized before the clean-URL knob existed have no
    // `clean_urls` and read as "default" (on unless a 200-status SPA fallback).
    if (array_key_exists('clean_urls', $serving['serving_config']) && !is_bool($serving['serving_config']['clean_urls'])) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact clean_urls flag must be a boolean.');
    }
    _stattic_runtime_validate_concern_manifest_shape($serving['concerns'] ?? null);
    $zeroEndpointReferences = [];
    if (!empty($serving['php_manifest'])) {
        _stattic_runtime_validate_php_manifest_artifact(
            $versionRoot,
            $versionRoot . '/php-manifest.php',
            $filesByPath,
            $zeroEndpointReferences
        );
    }

    $headerArtifact = null;
    if ($serving['header_artifact']) {
        $headerArtifact = _stattic_runtime_validate_rule_artifact($versionRoot . '/headers.php', 'headers');
    }
    $redirectArtifact = null;
    if ($serving['redirect_artifact']) {
        $redirectArtifact = _stattic_runtime_validate_rule_artifact($versionRoot . '/redirects.php', 'redirects');
    }
    _stattic_runtime_validate_concern_manifest_matches_artifacts($serving['concerns'], $headerArtifact, $redirectArtifact);
    if (!empty($serving['zero_routes'])) {
        $zeroRoutes = @include $versionRoot . '/zero/routes.php';
        if (!is_array($zeroRoutes) || !_stattic_runtime_artifact_metadata_valid_lazy($zeroRoutes) || ($zeroRoutes['artifact_kind'] ?? null) !== 'zero_routes') {
            _stattic_runtime_artifact_validation_failed('zero_routes', 'Zero routes artifact is missing or has the wrong schema.');
        }
        _stattic_runtime_validate_zero_routes_artifact($zeroRoutes);
    }
    foreach ($serving['lookup'] as $lookup => $action) {
        if (!(is_string($lookup) || is_int($lookup)) || !_stattic_runtime_lookup_action_valid($action, $filesByPath)) {
            _stattic_runtime_artifact_validation_failed('lookup', 'Lookup metadata must point to a valid action.', [
                'lookup' => $lookup,
                'action' => $action,
            ]);
        }
        if (is_array($action) && in_array($action['action'] ?? null, ['invoke_zero', 'zero_activating'], true) && !array_key_exists('operation', $action)) {
            _stattic_runtime_collect_zero_endpoint_reference(
                $zeroEndpointReferences,
                $action['zero_artifact'],
                $action['endpoint_id'],
                $action['methods'][0],
                '/' . trim((string) $lookup, '/')
            );
        }
    }
    if (isset($zeroRoutes) && is_array($zeroRoutes)) {
        _stattic_runtime_collect_zero_route_endpoint_references($zeroEndpointReferences, $zeroRoutes);
    }
    _stattic_runtime_validate_zero_endpoint_artifacts($versionRoot, $zeroEndpointReferences);
    _stattic_runtime_validate_zero_endpoint_reference_integrity($zeroEndpointReferences);
    _stattic_runtime_validate_zero_endpoint_index($versionRoot, $zeroEndpointReferences);
    _stattic_runtime_validate_zero_migrations($versionRoot);
    _stattic_runtime_validate_zero_run_artifacts($versionRoot);

    if ($serving['fallback'] !== null && !_stattic_runtime_lookup_action_valid($serving['fallback'], $filesByPath)) {
        _stattic_runtime_artifact_validation_failed('fallback', 'Fallback metadata must point to a valid action.');
    }
    foreach ($serving['nearest_404'] as $directory => $action) {
        if (!(is_string($directory) || is_int($directory)) || !_stattic_runtime_lookup_action_valid($action, $filesByPath)) {
            _stattic_runtime_artifact_validation_failed('nearest_404', 'Nearest 404 metadata must point to a valid action.', [
                'directory' => $directory,
                'action' => $action,
            ]);
        }
    }
    if (!_stattic_runtime_lookup_action_valid($serving['not_found'], $filesByPath)) {
        _stattic_runtime_artifact_validation_failed('not_found', 'Default not-found metadata must point to a valid action.');
    }

    // Membership check only: the filesByPath loop below validates every file
    // artifact (public files included) with identical metadata.
    foreach ($serving['public_files'] as $path) {
        if (!is_string($path) || !isset($filesByPath[$path])) {
            _stattic_runtime_artifact_validation_failed('public_files', 'Public file metadata points to an unknown file.');
        }
    }

    $shardCache = [];
    foreach ($filesByPath as $path => $meta) {
        if (!is_string($path) || !is_array($meta)) {
            _stattic_runtime_artifact_validation_failed('files', 'File metadata is malformed.');
        }
        _stattic_runtime_validate_file_artifact($versionRoot, $path, $meta, $shardCache);
    }
}

function _stattic_runtime_lookup_action_valid(mixed $action, array $filesByPath): bool
{
    if (!is_array($action) || !is_string($action['action'] ?? null)) {
        return false;
    }
    if ($action['action'] === 'file' || $action['action'] === 'fallback') {
        return is_string($action['path'] ?? null)
            && isset($filesByPath[$action['path']])
            && _stattic_runtime_lookup_file_shard_valid($action)
            && in_array((int) ($action['status'] ?? 0), [200, 404], true)
            && ($action['methods'] ?? null) === ['GET', 'HEAD'];
    }
    if ($action['action'] === 'nearest_404') {
        return is_string($action['path'] ?? null)
            && isset($filesByPath[$action['path']])
            && _stattic_runtime_lookup_file_shard_valid($action)
            && (int) ($action['status'] ?? 0) === 404
            && ($action['methods'] ?? null) === ['GET', 'HEAD'];
    }
    if ($action['action'] === 'redirect') {
        $status = (int) ($action['status'] ?? 0);
        return is_string($action['destination'] ?? null)
            && $action['destination'] !== ''
            && in_array($status, [301, 302, 303, 307, 308], true)
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD'];
    }
    if ($action['action'] === 'invoke_zero' || $action['action'] === 'zero_activating') {
        return _stattic_runtime_zero_lookup_action_valid($action);
    }
    if ($action['action'] === 'not_found') {
        return (int) ($action['status'] ?? 0) === 404
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    }
    return false;
}

function _stattic_runtime_validate_php_manifest_artifact(string $versionRoot, string $path, array $filesByPath, array &$zeroEndpointReferences): void
{
    $manifest = @include $path;
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'stattic.php.manifest.v1' || !is_array($manifest['routes'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest artifact is missing or has the wrong schema.');
    }
    if (array_key_exists('versionId', $manifest) && !(is_string($manifest['versionId']) || $manifest['versionId'] === null)) {
        _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest version id is malformed.');
    }
    $seen = [];
    foreach ($manifest['routes'] as $route) {
        if (!is_array($route) || !is_string($route['action'] ?? null) || !is_string($route['pattern'] ?? null)) {
            _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest route is malformed.');
        }
        $pattern = $route['pattern'];
        if (!_stattic_runtime_route_pattern_valid($pattern)) {
            _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest route pattern is invalid.');
        }
        $key = ($route['action'] ?? '') . "\0" . $pattern . "\0" . (string) ($route['method'] ?? '');
        if (isset($seen[$key])) {
            _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest routes must be unique.', ['pattern' => $pattern]);
        }
        $seen[$key] = true;
        if ($route['action'] === 'serve_static') {
            $file = $route['file'] ?? null;
            if (!is_string($file) || !isset($filesByPath[$file])) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest static route points to a missing file.', ['pattern' => $pattern, 'path' => $file]);
            }
            if (array_key_exists('contentType', $route) && !is_string($route['contentType'])) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest static route content type is malformed.', ['pattern' => $pattern]);
            }
            if (array_key_exists('etag', $route) && !is_string($route['etag'])) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest static route etag is malformed.', ['pattern' => $pattern]);
            }
            continue;
        }
        if ($route['action'] === 'redirect') {
            $destination = $route['destination'] ?? null;
            $status = (int) ($route['status'] ?? 0);
            if (
                !is_string($destination)
                || $destination === ''
                || !_stattic_runtime_redirect_target_safe($destination, 'redirect')
                || !in_array($status, [301, 302, 303, 307, 308], true)
                || !is_string($route['cacheControl'] ?? null)
                || $route['cacheControl'] === ''
            ) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest redirect route is malformed.', ['pattern' => $pattern]);
            }
            continue;
        }
        if ($route['action'] === 'invoke_zero') {
            if (is_string($route['operation'] ?? null)) {
                if (
                    !_stattic_lookup_zero_control_operation_valid($route['operation'])
                    || !is_string($route['method'] ?? null)
                    || !in_array($route['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
                ) {
                    _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero control route is malformed.', ['pattern' => $pattern]);
                }
                continue;
            }
            $zeroArtifact = $route['zeroArtifact'] ?? null;
            $zeroPackPath = $route['zeroPackPath'] ?? null;
            if (
                !is_string($route['method'] ?? null)
                || !in_array($route['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
                || !is_string($route['endpointId'] ?? null)
                || $route['endpointId'] === ''
                || (!is_string($zeroArtifact) && !is_string($zeroPackPath))
                || !is_array($route['capabilities'] ?? null)
            ) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero route is malformed.', ['pattern' => $pattern]);
            }
            if (is_string($zeroArtifact)) {
                if (!_stattic_runtime_zero_private_artifact_path_valid($zeroArtifact, '.json') || !is_file($versionRoot . '/' . $zeroArtifact)) {
                    _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero route points to a missing endpoint artifact.', ['pattern' => $pattern, 'path' => $zeroArtifact]);
                }
                _stattic_runtime_collect_zero_endpoint_reference(
                    $zeroEndpointReferences,
                    $zeroArtifact,
                    $route['endpointId'],
                    $route['method'],
                    $pattern
                );
            }
            if (is_string($zeroPackPath) && !_stattic_runtime_relative_artifact_path_valid($zeroPackPath)) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero route pack path is invalid.', ['pattern' => $pattern, 'path' => $zeroPackPath]);
            }
            if (array_key_exists('schemaHash', $route) && !(is_string($route['schemaHash']) || $route['schemaHash'] === null)) {
                _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero route schema hash is malformed.', ['pattern' => $pattern]);
            }
            foreach (STATTIC_RUNTIME_ZERO_CAPABILITIES as $capability) {
                if (array_key_exists($capability, $route['capabilities']) && !is_bool($route['capabilities'][$capability])) {
                    _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest Zero route capabilities are malformed.', ['pattern' => $pattern]);
                }
            }
            continue;
        }
        _stattic_runtime_artifact_validation_failed('php_manifest', 'PHP manifest route action is unsupported.', ['pattern' => $pattern]);
    }
}

// Management-lane refinement over the shared serving-side Zero action
// validator (shared/artifacts.php).
function _stattic_runtime_zero_lookup_action_valid(mixed $action): bool
{
    return is_array($action)
        && in_array($action['action'] ?? null, ['invoke_zero', 'zero_activating'], true)
        && _stattic_lookup_zero_action_valid($action);
}

function _stattic_runtime_lookup_file_shard_valid(array $action): bool
{
    return is_string($action['path'] ?? null)
        && is_string($action['file_shard'] ?? null)
        && $action['file_shard'] === _stattic_runtime_file_metadata_shard($action['path']);
}

function _stattic_runtime_validate_concern_manifest_shape(mixed $concerns): void
{
    if (!is_array($concerns)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing concern metadata.');
    }
    // Versions finalized before the header-artifact auth lane was deleted also
    // carry an `auth` concern section; it is tolerated and ignored (nothing at
    // serve time ever consulted it).
    foreach (['headers', 'redirects'] as $sectionName) {
        $section = $concerns[$sectionName] ?? null;
        if (!is_array($section) || !is_array($section['exact'] ?? null) || !is_bool($section['pattern'] ?? null)) {
            _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact concern metadata is malformed.');
        }
        foreach ($section['exact'] as $path => $enabled) {
            if (!is_string($path) || $path === '' || $path[0] !== '/' || $enabled !== true) {
                _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact concern metadata is malformed.');
            }
        }
    }
}

function _stattic_runtime_validate_zero_routes_artifact(array $routes): void
{
    foreach (['exact', 'by_first_segment', 'fallback'] as $key) {
        if (!array_key_exists($key, $routes) || !is_array($routes[$key])) {
            _stattic_runtime_artifact_validation_failed('zero_routes', 'Zero routes artifact is malformed.');
        }
    }
    foreach ($routes['by_first_segment'] as $segment => $entries) {
        if (!is_string($segment) || !_stattic_runtime_zero_route_segment_valid($segment) || !is_array($entries)) {
            _stattic_runtime_artifact_validation_failed('zero_routes', 'Zero route bucket is malformed.');
        }
    }
    foreach (_stattic_runtime_zero_routes_all_entries($routes) as $entry) {
        if (!_stattic_runtime_zero_route_entry_valid($entry)) {
            _stattic_runtime_artifact_validation_failed('zero_routes', 'Zero route entry is malformed.');
        }
    }
}

function _stattic_runtime_zero_route_entry_valid(mixed $entry): bool
{
    // Shared shape (the serve-side contract) plus compile-time-only strictness:
    // normalized pattern round-trip and the endpoint-id length cap.
    return _stattic_zero_route_entry_shape_valid($entry)
        && _stattic_runtime_zero_route_pattern($entry['pattern']) === $entry['pattern']
        && strlen($entry['endpoint_id']) <= 256;
}

function _stattic_runtime_collect_zero_endpoint_reference(array &$references, string $artifactPath, string $endpointId, string $method, string $path): void
{
    $references[$artifactPath][] = [
        'endpoint_id' => $endpointId,
        'method' => $method,
        'path' => $path === '/' ? '/' : _stattic_runtime_zero_route_pattern($path),
    ];
}

function _stattic_runtime_collect_zero_route_endpoint_references(array &$references, array $routes): void
{
    foreach (_stattic_runtime_zero_routes_all_entries($routes) as $entry) {
        _stattic_runtime_collect_zero_endpoint_reference(
            $references,
            $entry['artifact'],
            $entry['endpoint_id'],
            $entry['method'],
            $entry['pattern']
        );
    }
}

// Serving lookup, zero/routes.php, and php-manifest.php are redundant runtime
// projections of the same Zero endpoints. Repeated references to the same
// endpoint are expected, but an endpoint id must never identify two artifacts
// or routes, and equal-priority overlapping route patterns must never identify
// two endpoints. The runner resolves indexed invocations by endpoint id and
// breaks equal-score route ties by declaration order, so accepting either
// ambiguity can make a request execute a different route's artifact.
function _stattic_runtime_validate_zero_endpoint_reference_integrity(array $references): void
{
    $byEndpointId = [];
    $routeIdentities = [];
    foreach ($references as $artifactPath => $expectedReferences) {
        if (!is_string($artifactPath) || !is_array($expectedReferences)) {
            continue;
        }
        foreach ($expectedReferences as $reference) {
            if (
                !is_array($reference)
                || !is_string($reference['endpoint_id'] ?? null)
                || !is_string($reference['method'] ?? null)
                || !is_string($reference['path'] ?? null)
            ) {
                continue;
            }
            $endpointId = $reference['endpoint_id'];
            $method = $reference['method'];
            $path = $reference['path'];
            $identity = [
                'artifact' => $artifactPath,
                'endpoint_id' => $endpointId,
                'method' => $method,
                'path' => $path,
            ];

            if (isset($byEndpointId[$endpointId]) && $byEndpointId[$endpointId] !== $identity) {
                _stattic_runtime_artifact_validation_failed(
                    'zero_endpoint_index',
                    'Zero endpoint ids must map to exactly one route artifact.',
                    ['endpoint_id' => $endpointId]
                );
            }
            $byEndpointId[$endpointId] = $identity;

            $knownIdentity = false;
            foreach ($routeIdentities as $existing) {
                if ($existing === $identity) {
                    $knownIdentity = true;
                    break;
                }
                if (_stattic_zero_route_patterns_ambiguous($method, $path, $existing['method'], $existing['path'])) {
                    _stattic_runtime_artifact_validation_failed(
                        'zero_endpoint_index',
                        'Zero routes must not have equal-priority overlapping matches.',
                        ['method' => $method, 'path' => $path]
                    );
                }
            }
            if (!$knownIdentity) {
                $routeIdentities[] = $identity;
            }
        }
    }
}

function _stattic_runtime_validate_zero_endpoint_artifacts(string $versionRoot, array $references): void
{
    foreach ($references as $artifactPath => $expectedReferences) {
        if (!is_string($artifactPath) || !_stattic_runtime_zero_private_artifact_path_valid($artifactPath, '.json')) {
            _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint artifact path is invalid.');
        }
        $artifact = _stattic_runtime_read_json($versionRoot . '/' . $artifactPath);
        if (!is_array($artifact)) {
            _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint artifact is missing or malformed.', ['path' => $artifactPath]);
        }
        _stattic_runtime_validate_zero_endpoint_artifact($versionRoot, $artifactPath, $artifact, $expectedReferences);
    }
}

function _stattic_runtime_validate_zero_endpoint_index(string $versionRoot, array $references): void
{
    $index = _stattic_runtime_read_zero_sidecar($versionRoot, 'zero/endpoints-index.json', '_stattic_runtime_zero_endpoint_index_artifact_valid', 'zero_endpoint_index', 'Zero endpoint index artifact is malformed.');
    if ($index === null) {
        return;
    }
    $expected = [];
    foreach ($references as $artifactPath => $expectedReferences) {
        if (!is_string($artifactPath) || !is_array($expectedReferences)) {
            continue;
        }
        foreach ($expectedReferences as $reference) {
            if (is_array($reference) && is_string($reference['endpoint_id'] ?? null)) {
                $expected[$reference['endpoint_id']] = $artifactPath;
            }
        }
    }
    if (count($index['endpoints']) !== count($expected)) {
        _stattic_runtime_artifact_validation_failed('zero_endpoint_index', 'Zero endpoint index contains stale or missing endpoint references.');
    }
    foreach ($index['endpoints'] as $endpointId => $artifactPath) {
        if (!is_string($endpointId) || !is_string($artifactPath) || ($expected[$endpointId] ?? null) !== $artifactPath) {
            _stattic_runtime_artifact_validation_failed('zero_endpoint_index', 'Zero endpoint index contains an unexpected endpoint reference.', ['endpoint_id' => $endpointId]);
        }
    }
}

function _stattic_runtime_validate_zero_migrations(string $versionRoot): void
{
    _stattic_runtime_read_zero_sidecar($versionRoot, 'zero/migrations.json', '_stattic_runtime_zero_migrations_artifact_valid', 'zero_migrations', 'Zero migrations artifact is malformed.');
}

// Reads one optional zero/*.json sidecar during the validate pass: null when
// absent, 422 artifact_validation_failed when malformed. The import lane's
// _stattic_runtime_import_zero_sidecar keeps its own copy because its failure
// contract is a 500 runtime_compiler_output_invalid, not a 422.
function _stattic_runtime_read_zero_sidecar(string $versionRoot, string $relPath, callable $valid, string $artifact, string $message): ?array
{
    $path = $versionRoot . '/' . $relPath;
    if (!is_file($path)) {
        return null;
    }
    $decoded = _stattic_runtime_read_json($path);
    if (!is_array($decoded) || !_stattic_runtime_artifact_metadata_valid_lazy($decoded) || !$valid($decoded)) {
        _stattic_runtime_artifact_validation_failed($artifact, $message);
    }
    return $decoded;
}

function _stattic_runtime_zero_endpoint_index_artifact_valid(array $index): bool
{
    if (($index['format'] ?? null) !== 'stattic.zero.endpoints-index.v1' || ($index['artifact_kind'] ?? null) !== 'zero_endpoints_index' || !is_array($index['endpoints'] ?? null)) {
        return false;
    }
    foreach ($index['endpoints'] as $endpointId => $artifactPath) {
        if (!is_string($endpointId) || $endpointId === '' || strlen($endpointId) > 256 || !is_string($artifactPath) || !_stattic_runtime_relative_artifact_path_valid($artifactPath)) {
            return false;
        }
    }
    return true;
}

function _stattic_runtime_validate_zero_run_artifacts(string $versionRoot): void
{
    $index = _stattic_runtime_read_zero_sidecar($versionRoot, 'zero/runs-index.json', '_stattic_runtime_zero_run_index_artifact_valid', 'zero_run_index', 'Zero run index artifact is malformed.');
    if ($index === null) {
        return;
    }
    foreach ($index['runs'] as $runId => $artifactPath) {
        $artifact = _stattic_runtime_read_json($versionRoot . '/' . $artifactPath);
        if (!is_array($artifact)
            || ($artifact['format'] ?? null) !== 'stattic.zero.run.v1'
            || ($artifact['kind'] ?? null) !== 'run'
            || ($artifact['runId'] ?? null) !== $runId
            || !_stattic_runtime_zero_program_artifact_core_valid($artifact, static fn (string $path, string $suffix): bool => _stattic_runtime_zero_run_artifact_valid($path))
            || !is_array($artifact['db'] ?? null)
        ) {
            _stattic_runtime_artifact_validation_failed('zero_run', 'Zero run artifact is missing or malformed.', ['path' => $artifactPath]);
        }
        _stattic_runtime_validate_zero_artifact_file_hash($versionRoot, $artifact['sourcePath'], $artifact['sourceSha256'], 'source');
        _stattic_runtime_validate_zero_artifact_file_hash($versionRoot, $artifact['bytecodePath'], $artifact['bytecodeSha256'], 'bytecode');
    }
}

// Shared core of the Zero endpoint/run program-artifact contract: source and
// bytecode paths + hashes plus the ABI pins and capabilities map. Callers keep
// their own format/kind/id checks, db check, and failure response.
function _stattic_runtime_zero_program_artifact_core_valid(array $artifact, callable $pathValid): bool
{
    return is_string($artifact['sourcePath'] ?? null)
        && is_string($artifact['bytecodePath'] ?? null)
        && $pathValid($artifact['sourcePath'], '.source.js')
        && $pathValid($artifact['bytecodePath'], '.bytecode')
        && is_string($artifact['sourceSha256'] ?? null)
        && is_string($artifact['bytecodeSha256'] ?? null)
        && ($artifact['runnerAbi'] ?? null) === STATTIC_RUNTIME_ZERO_RUNNER_ABI
        && ($artifact['quickjsAbi'] ?? null) === STATTIC_RUNTIME_ZERO_QUICKJS_ABI
        && _stattic_runtime_zero_capabilities_artifact_valid($artifact['capabilities'] ?? null);
}

function _stattic_runtime_zero_migrations_artifact_valid(array $artifact): bool
{
    if (($artifact['format'] ?? null) !== 'stattic.zero.migrations.v1' || ($artifact['artifact_kind'] ?? null) !== 'zero_migrations' || !is_array($artifact['statements'] ?? null)) {
        return false;
    }
    if (count($artifact['statements']) > 256) {
        return false;
    }
    foreach ($artifact['statements'] as $statement) {
        if (!is_string($statement) || trim($statement) === '' || str_contains($statement, "\0") || strlen($statement) > 65536) {
            return false;
        }
    }
    return true;
}

function _stattic_runtime_validate_zero_endpoint_artifact(string $versionRoot, string $artifactPath, array $artifact, array $expectedReferences): void
{
    if (
        ($artifact['format'] ?? null) !== 'stattic.zero.endpoint.v1'
        || ($artifact['kind'] ?? null) !== 'endpoint'
        || !is_string($artifact['endpointId'] ?? null)
        || $artifact['endpointId'] === ''
        || strlen($artifact['endpointId']) > 256
        || !is_string($artifact['method'] ?? null)
        || !_stattic_lookup_zero_methods_valid([$artifact['method']])
        || !is_string($artifact['path'] ?? null)
        || _stattic_runtime_zero_route_pattern($artifact['path']) !== $artifact['path']
        || !_stattic_runtime_zero_program_artifact_core_valid($artifact, '_stattic_runtime_zero_private_artifact_path_valid')
        || !_stattic_runtime_zero_db_artifact_valid($artifact['db'] ?? null)
    ) {
        _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint artifact metadata is invalid.', ['path' => $artifactPath]);
    }

    foreach ($expectedReferences as $reference) {
        if (
            !is_array($reference)
            || ($reference['endpoint_id'] ?? null) !== $artifact['endpointId']
            || ($reference['method'] ?? null) !== $artifact['method']
            || ($reference['path'] ?? null) !== $artifact['path']
        ) {
            _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint artifact does not match its route references.', ['path' => $artifactPath]);
        }
    }

    _stattic_runtime_validate_zero_artifact_file_hash($versionRoot, $artifact['sourcePath'], $artifact['sourceSha256'], 'source');
    _stattic_runtime_validate_zero_artifact_file_hash($versionRoot, $artifact['bytecodePath'], $artifact['bytecodeSha256'], 'bytecode');
}

function _stattic_runtime_zero_private_artifact_path_valid(string $path, string $suffix): bool
{
    if (!str_starts_with($path, 'zero/endpoints/') || !str_ends_with($path, $suffix) || !_stattic_runtime_relative_artifact_path_valid($path)) {
        return false;
    }
    $relative = substr($path, strlen('zero/endpoints/'));
    if ($relative === '' || str_contains($relative, '//')) {
        return false;
    }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.') {
            return false;
        }
    }
    return strlen(basename($relative)) > strlen($suffix);
}

function _stattic_runtime_zero_run_artifact_valid(string $path): bool
{
    return str_starts_with($path, 'zero/runs/')
        && (str_ends_with($path, '.json') || str_ends_with($path, '.source.js') || str_ends_with($path, '.bytecode'))
        && _stattic_runtime_relative_artifact_path_valid($path);
}

function _stattic_runtime_zero_run_index_artifact_valid(array $index): bool
{
    if (($index['format'] ?? null) !== 'stattic.zero.runs-index.v1' || ($index['artifact_kind'] ?? null) !== 'zero_runs_index' || !is_array($index['runs'] ?? null)) {
        return false;
    }
    foreach ($index['runs'] as $runId => $artifactPath) {
        if (!is_string($runId) || $runId === '' || strlen($runId) > 256 || !is_string($artifactPath) || !_stattic_runtime_zero_run_artifact_valid($artifactPath) || !str_ends_with($artifactPath, '.json')) {
            return false;
        }
    }

    return true;
}

function _stattic_runtime_validate_zero_artifact_file_hash(string $versionRoot, string $relativePath, string $expectedHash, string $kind): void
{
    $path = $versionRoot . '/' . $relativePath;
    if (!is_file($path)) {
        _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint ' . $kind . ' artifact is missing.', ['path' => $relativePath]);
    }
    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !_stattic_runtime_sha256_equals($expectedHash, $actual)) {
        _stattic_runtime_artifact_validation_failed('zero_endpoint', 'Zero endpoint ' . $kind . ' artifact hash does not match metadata.', ['path' => $relativePath]);
    }
}

function _stattic_runtime_sha256_equals(string $expected, string $actual): bool
{
    $normalized = str_starts_with($expected, 'sha256:') ? substr($expected, 7) : $expected;
    return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 && hash_equals(strtolower($normalized), strtolower($actual));
}

function _stattic_runtime_zero_capabilities_artifact_valid(mixed $capabilities): bool
{
    if (!is_array($capabilities)) {
        return false;
    }
    foreach (STATTIC_RUNTIME_ZERO_CAPABILITIES as $name) {
        if (!is_bool($capabilities[$name] ?? null)) {
            return false;
        }
    }
    return true;
}

function _stattic_runtime_zero_db_artifact_valid(mixed $db): bool
{
    return is_array($db)
        && (!array_key_exists('schemaHash', $db) || is_string($db['schemaHash']) || $db['schemaHash'] === null)
        && is_array($db['tables'] ?? null);
}

function _stattic_runtime_validate_concern_manifest_matches_artifacts(mixed $concerns, ?array $headerArtifact, ?array $redirectArtifact): void
{
    if (!is_array($concerns)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact is missing concern metadata.');
    }

    $headers = is_array($headerArtifact) && is_array($headerArtifact['headers'] ?? null) ? $headerArtifact['headers'] : ['exact' => [], 'pattern' => []];
    $redirects = is_array($redirectArtifact) ? $redirectArtifact : ['exact' => [], 'pattern' => []];

    _stattic_runtime_validate_concern_section_matches_rules($concerns['headers'] ?? null, $headers, 'headers');
    _stattic_runtime_validate_concern_section_matches_rules($concerns['redirects'] ?? null, $redirects, 'redirects');
}

function _stattic_runtime_validate_concern_section_matches_rules(mixed $section, array $artifactSection, string $kind): void
{
    if (!is_array($section) || !is_array($section['exact'] ?? null) || !is_bool($section['pattern'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact concern metadata is malformed.');
    }

    $expectedPattern = _stattic_runtime_pattern_rules_present($artifactSection['pattern'] ?? []);
    if ($section['pattern'] !== $expectedPattern) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact concern metadata does not match rule artifacts.');
    }

    $expectedExact = [];
    foreach (($artifactSection['exact'] ?? []) as $path => $bucket) {
        if (is_string($path) && is_array($bucket) && $bucket !== []) {
            $expectedExact[$path] = true;
        }
    }

    $actualExact = $section['exact'];
    ksort($expectedExact);
    ksort($actualExact);
    if ($actualExact !== $expectedExact) {
        _stattic_runtime_artifact_validation_failed('serving', 'Serving artifact concern metadata does not match rule artifacts.');
    }
}

function _stattic_runtime_validate_rule_artifact(string $path, string $kind): array
{
    $artifact = @include $path;
    if (!is_array($artifact) || !_stattic_runtime_artifact_metadata_valid_lazy($artifact)) {
        _stattic_runtime_artifact_validation_failed($kind, ucfirst($kind) . ' artifact is missing or malformed.');
    }
    if ($kind === 'headers') {
        if (!is_array($artifact['headers'] ?? null) || !is_array($artifact['headers']['exact'] ?? null) || !is_array($artifact['headers']['pattern'] ?? null)) {
            _stattic_runtime_artifact_validation_failed($kind, ucfirst($kind) . ' artifact is missing or malformed.');
        }
        // Artifacts finalized before the auth header-lane was deleted (and
        // imports of archives exported back then) still carry an `auth`
        // section; it is tolerated and ignored — nothing reads it.
        _stattic_runtime_validate_header_rule_section($artifact['headers']);
        return $artifact;
    }
    if (!is_array($artifact['exact'] ?? null) || !is_array($artifact['pattern'] ?? null)) {
        _stattic_runtime_artifact_validation_failed($kind, ucfirst($kind) . ' artifact is missing or malformed.');
    }
    _stattic_runtime_validate_redirect_rule_section($artifact);
    return $artifact;
}

function _stattic_runtime_validate_redirect_rule_section(array $artifact): void
{
    foreach (_stattic_runtime_flatten_rules($artifact['exact'] ?? [], $artifact['pattern'] ?? []) as $rule) {
        if (!is_array($rule)) {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact contains a malformed rule.');
        }
        $action = (string) ($rule['action'] ?? 'redirect');
        if (!in_array($action, ['redirect', 'rewrite', 'proxy', 'notFound'], true)) {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact contains an unknown action.');
        }
        $destination = $rule['destination'] ?? null;
        if (!is_string($destination) || $destination === '') {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact contains an empty destination.');
        }
        $status = (int) ($rule['status'] ?? 0);
        if (!in_array($status, [200, 301, 302, 303, 307, 308, 404], true)) {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact contains an unsupported status.');
        }
        $cache = $rule['cache'] ?? null;
        if (!in_array($cache, [null, 'shared'], true) || ($cache === 'shared' && $action !== 'proxy')) {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact contains an invalid proxy cache mode.');
        }
        $failureDetails = [];
        if (!_stattic_runtime_redirect_target_safe($destination, $action, $failureDetails)) {
            _stattic_runtime_artifact_validation_failed('redirects', 'Redirect artifact targets a runtime private or control path.', [
                'source' => (string) ($rule['source'] ?? ''),
                'destination' => $destination,
                'action' => $action,
                'status' => $status,
                ...$failureDetails,
            ]);
        }
    }
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

    if (in_array($action, ['rewrite', 'notFound'], true) && _stattic_runtime_file_private($validationPath, _stattic_runtime_private_files())) {
        $failureDetails = ['path' => $path, 'validation_path' => $validationPath, 'reason' => 'private_runtime_file'];
        return false;
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

function _stattic_runtime_validate_header_rule_section(array $section): void
{
    foreach (($section['exact'] ?? []) as $bucket) {
        if (is_array($bucket)) {
            foreach ($bucket as $rule) {
                if (is_array($rule)) {
                    _stattic_runtime_validate_header_response_rule($rule);
                }
            }
        }
    }
    foreach (_stattic_runtime_flatten_pattern_rules($section['pattern'] ?? []) as $rule) {
        if (is_array($rule)) {
            _stattic_runtime_validate_header_response_rule($rule);
        }
    }
}

function _stattic_runtime_validate_header_response_rule(array $rule): void
{
    if (!is_array($rule['headers'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact is missing response headers.');
    }
    foreach (($rule['headers'] ?? []) as $name => $value) {
        if (!_stattic_runtime_response_header_name_valid((string) $name) || !is_string($value) || !_stattic_runtime_response_header_value_valid($value)) {
            _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains malformed response headers.');
        }
        if (isset(SPACEFAST_PLATFORM_MANAGED_HEADERS[strtolower(trim((string) $name))])) {
            _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains a platform-managed header.');
        }
    }
    foreach (($rule['operations'] ?? []) as $operation) {
        if (!is_array($operation)) {
            _stattic_runtime_artifact_validation_failed('headers', 'Header artifact contains malformed operations.');
        }
        // Rejects basicAuth (and any other non set/remove kind): compiled
        // basic-auth lives in the unified access rules, never the header lane.
        _stattic_runtime_validate_response_header_operation($operation);
    }
}

// SPACEFAST_PLATFORM_MANAGED_HEADERS (shared/safety.php, generated from the TS policy
// source) backs both this artifact validator and the serve-time apply guard.
// Cache-Control stays accepted; the compiler emits a diagnostic for it instead.

function _stattic_runtime_validate_response_header_operation(array $operation): void
{
    $kind = $operation['kind'] ?? null;
    if (!in_array($kind, ['set', 'remove'], true)) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains unsupported operations.');
    }
    if (!_stattic_runtime_response_header_name_valid((string) ($operation['name'] ?? ''))) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains malformed header names.');
    }
    if (isset(SPACEFAST_PLATFORM_MANAGED_HEADERS[strtolower(trim((string) ($operation['name'] ?? '')))])) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains a platform-managed header.');
    }
    if ($kind === 'set' && (!is_string($operation['value'] ?? null) || !_stattic_runtime_response_header_value_valid($operation['value']))) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains malformed header values.');
    }
    if ($kind === 'remove' && array_key_exists('value', $operation) && $operation['value'] !== null) {
        _stattic_runtime_artifact_validation_failed('headers', 'Header response artifact contains malformed remove operations.');
    }
}

function _stattic_runtime_response_header_name_valid(string $name): bool
{
    return preg_match('/^[A-Za-z0-9-]+$/', trim($name)) === 1;
}

function _stattic_runtime_response_header_value_valid(string $value): bool
{
    return !str_contains($value, "\n") && !str_contains($value, "\r");
}

function _stattic_runtime_validate_file_artifact(string $versionRoot, string $path, array $expectedMeta, array &$shardCache): void
{
    if (!is_string($expectedMeta['disk_path'] ?? null) || $expectedMeta['disk_path'] !== $path) {
        _stattic_runtime_artifact_validation_failed('files', 'File metadata is missing committed disk path.');
    }
    $diskPath = $versionRoot . '/files/' . $expectedMeta['disk_path'];
    if (!is_file($diskPath) && !_stattic_runtime_file_meta_has_remote_locator($expectedMeta)) {
        _stattic_runtime_artifact_validation_failed('files', 'File metadata points to a missing disk file.');
    }

    // Load and shard-level-validate each file-shards artifact once per
    // validation pass, not once per file.
    $shard = _stattic_runtime_file_metadata_shard($path);
    if (!array_key_exists($shard, $shardCache)) {
        $artifact = @include $versionRoot . '/file-shards/' . $shard . '.php';
        if (!is_array($artifact) || !_stattic_runtime_artifact_metadata_valid_lazy($artifact)) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard artifact is missing or malformed.');
        }
        $shardCache[$shard] = $artifact;
    }
    $meta = is_array($shardCache[$shard]['files'] ?? null) ? ($shardCache[$shard]['files'][$path] ?? null) : null;
    if (!is_array($meta)) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard artifact is missing or malformed.');
    }

    foreach (['disk_path', 'sha256', 'mime', 'last_modified'] as $key) {
        if (!is_string($meta[$key] ?? null) || ($expectedMeta[$key] ?? null) !== $meta[$key]) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata does not match committed metadata.');
        }
    }
    if (($meta['methods'] ?? null) !== ['GET', 'HEAD'] || ($expectedMeta['methods'] ?? null) !== ['GET', 'HEAD']) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata is missing method allowance.');
    }
    if (($meta['executable'] ?? null) !== false || ($expectedMeta['executable'] ?? null) !== false) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata must be non-executable.');
    }
    $expectedInert = _stattic_path_is_php_like($path);
    if (($meta['forced_download_or_text'] ?? null) !== $expectedInert || ($expectedMeta['forced_download_or_text'] ?? null) !== $expectedInert) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard inert metadata does not match path safety policy.');
    }
    if (!isset($meta['size'], $meta['mtime']) || (int) $expectedMeta['size'] !== (int) $meta['size'] || (int) $expectedMeta['mtime'] !== (int) $meta['mtime']) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata does not match committed metadata.');
    }
    if (!is_array($meta['headers'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata is missing precomputed headers.');
    }
    foreach (['Content-Type', 'ETag', 'X-Content-Type-Options', 'Cache-Control', 'Last-Modified'] as $headerName) {
        if (!is_string($meta['headers'][$headerName] ?? null) || $meta['headers'][$headerName] === '') {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard metadata is missing required precomputed headers.');
        }
    }
    _stattic_runtime_validate_compressed_file_artifact($versionRoot, $path, $expectedMeta, $meta);
}

function _stattic_runtime_validate_compressed_file_artifact(string $versionRoot, string $path, array $expectedMeta, array $meta): void
{
    if (!array_key_exists('compressed', $expectedMeta) && !array_key_exists('compressed', $meta)) {
        return;
    }
    if (($expectedMeta['compressed'] ?? null) !== ($meta['compressed'] ?? null) || !is_array($meta['compressed'] ?? null)) {
        _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata does not match committed metadata.');
    }

    foreach ($meta['compressed'] as $encoding => $compressed) {
        if (!is_string($encoding) || !in_array($encoding, ['br', 'gzip'], true) || !is_array($compressed)) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata has an unsupported encoding.');
        }
        $suffix = $encoding === 'br' ? '.br' : '.gz';
        $sidecarPath = $path . $suffix;
        if (($compressed['disk_path'] ?? null) !== $sidecarPath || !isset($compressed['size']) || !is_string($compressed['sha256'] ?? null)) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata is missing sidecar identity.');
        }
        $diskPath = $versionRoot . '/files/' . $sidecarPath;
        $hasLocalSidecar = is_file($diskPath)
            && filesize($diskPath) === (int) $compressed['size']
            && hash_file('sha256', $diskPath) === $compressed['sha256'];
        if (!$hasLocalSidecar && !_stattic_runtime_file_meta_has_remote_locator($compressed)) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata points to missing or mismatched sidecar bytes.');
        }
        if (!is_array($compressed['headers'] ?? null)) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata is missing precomputed headers.');
        }
        foreach (['Content-Type', 'ETag', 'X-Content-Type-Options', 'Cache-Control', 'Last-Modified', 'Content-Encoding', 'Vary'] as $headerName) {
            if (!is_string($compressed['headers'][$headerName] ?? null) || $compressed['headers'][$headerName] === '') {
                _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed metadata is missing required precomputed headers.');
            }
        }
        if (
            $compressed['headers']['Content-Type'] !== $meta['headers']['Content-Type']
            || $compressed['headers']['Content-Encoding'] !== $encoding
            || !str_contains(strtolower($compressed['headers']['Vary']), 'accept-encoding')
        ) {
            _stattic_runtime_artifact_validation_failed('file_shards', 'File shard compressed headers do not match the representation metadata.');
        }
    }
}

function _stattic_runtime_file_meta_has_remote_locator(array $meta): bool
{
    if (($meta['local'] ?? true) !== false) {
        return false;
    }
    $remote = $meta['remote'] ?? null;
    if (!is_array($remote)) {
        return false;
    }
    $bucket = $remote['bucket'] ?? null;
    $key = $remote['key'] ?? null;
    $sha = strtolower((string) ($meta['sha256'] ?? ''));
    return is_string($bucket)
        && _stattic_runtime_id_valid($bucket)
        && is_string($key)
        && preg_match('/^spaces\/(?![.]+\/)[A-Za-z0-9._-]{1,128}\/blobs\/[a-f0-9]{2}\/[a-f0-9]{64}$/', $key) === 1
        && $sha !== ''
        && str_ends_with($key, '/' . $sha);
}

function _stattic_runtime_artifact_validation_failed(string $artifact, string $message, array $details = []): void
{
    _stattic_json_response(422, [
        'error' => [
            'code' => 'runtime_artifact_validation_failed',
            'message' => $message,
            'details' => array_merge(['artifact' => $artifact], $details),
        ],
    ]);
}

function _stattic_runtime_flatten_rules(mixed $exact, mixed $pattern): array
{
    $rules = [];
    if (is_array($exact)) {
        foreach ($exact as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $rule) {
                if (is_array($rule)) {
                    $rules[] = $rule;
                }
            }
        }
    }
    foreach (_stattic_runtime_flatten_pattern_rules($pattern) as $rule) {
        if (is_array($rule)) {
            $rules[] = $rule;
        }
    }
    usort($rules, static function ($left, $right): int {
        $leftOrder = is_array($left) ? (int) ($left['order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
        $rightOrder = is_array($right) ? (int) ($right['order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
        return $leftOrder <=> $rightOrder;
    });
    foreach ($rules as &$rule) {
        unset($rule['order']);
    }
    unset($rule);
    return $rules;
}

// Compiles the response-header lane of the headers.php artifact. basicAuth
// operations are stripped here, not copied anywhere: compiled basic-auth is
// enforced exclusively through the unified access rules (access-rules.php).
function _stattic_runtime_flatten_pattern_rules(mixed $pattern): array
{
    if (!is_array($pattern) || $pattern === []) {
        return [];
    }
    if (array_key_exists('fallback', $pattern) || array_key_exists('by_first_segment', $pattern)) {
        $rules = [];
        foreach (($pattern['fallback'] ?? []) as $rule) {
            if (is_array($rule)) {
                $rules[] = $rule;
            }
        }
        foreach (($pattern['by_first_segment'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $rule) {
                if (is_array($rule)) {
                    $rules[] = $rule;
                }
            }
        }
        return $rules;
    }

    return $pattern;
}

function _stattic_runtime_pattern_rules_present(mixed $pattern): bool
{
    return _stattic_runtime_flatten_pattern_rules($pattern) !== [];
}
