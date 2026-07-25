<?php
declare(strict_types=1);

function _stattic_load_lazy_serving_config(string $privateRoot, string $requestHost, string $requestPath = '/', string $requestMethod = 'GET'): ?array
{
    $current = @include $privateRoot . '/routes/current.php';
    if (!is_array($current) || !is_string($current['generation'] ?? null)) {
        return null;
    }
    if (!_stattic_runtime_current_route_pointer_valid_lazy($current)) {
        _stattic_render_runtime_invariant_error_lazy('artifact-schema-mismatch', 'Runtime artifact schema does not match this runtime.');
    }

    $generationRoot = $privateRoot . '/routes/generations/' . $current['generation'];
    $hasWildcardFallback = !empty($current['has_wildcards']);
    $exactShard = _stattic_route_shard_for_host($generationRoot, $current, $requestHost);
    $wildcardShard = null;
    $hostEntry = null;
    $hostnames = is_array($exactShard['hostnames'] ?? null) ? $exactShard['hostnames'] : [];
    if ($requestHost !== '' && is_array($hostnames) && isset($hostnames[$requestHost]) && is_array($hostnames[$requestHost])) {
        $hostEntry = $hostnames[$requestHost];
    } elseif ($requestHost !== '' && $hasWildcardFallback) {
        $wildcardShard = _stattic_load_wildcard_route_shard($generationRoot, $current);
        $wildcardHostnames = is_array($wildcardShard['hostnames'] ?? null) ? $wildcardShard['hostnames'] : [];
        $hostEntry = _stattic_match_wildcard_host_entry($wildcardHostnames, $requestHost);
    }
    $routeAction = is_array($hostEntry) ? _stattic_host_route_action($hostEntry) : null;
    $hostResponseHeaders = is_array($hostEntry) && is_array($hostEntry['response_headers'] ?? null)
        ? _stattic_valid_response_headers($hostEntry['response_headers'])
        : [];
    if (
        is_array($routeAction)
        && in_array(($routeAction['action'] ?? null), ['tombstone', 'platform_error'], true)
    ) {
        return _stattic_platform_action_serving_config(
            $routeAction,
            is_string($routeAction['space_id'] ?? null) ? $routeAction['space_id'] : '',
            $hostResponseHeaders
        );
    }
    $hostRoutes = is_array($exactShard['host_routes'] ?? null) ? $exactShard['host_routes'] : [];
    $requestHostRoutes = _stattic_routes_for_host($hostRoutes, $requestHost);
    if ($hasWildcardFallback && $wildcardShard === null && !is_array($hostEntry) && $requestHostRoutes === []) {
        $wildcardShard = _stattic_load_wildcard_route_shard($generationRoot, $current);
    }
    if (is_array($wildcardShard)) {
        $wildcardRoutes = is_array($wildcardShard['host_routes'] ?? null) ? $wildcardShard['host_routes'] : [];
        foreach (_stattic_wildcard_routes_for_host($wildcardRoutes, $requestHost) as $route) {
            $requestHostRoutes[] = $route;
        }
    }
    if (!is_array($hostEntry) && $requestHostRoutes === []) {
        return null;
    }

    $matchedHostRoute = _stattic_lazy_match_host_route($requestHostRoutes, $requestPath, $requestMethod);
    $matchedRouteAction = is_array($matchedHostRoute) && is_array($matchedHostRoute['route_action'] ?? null)
        ? $matchedHostRoute['route_action']
        : null;
    $routeActionCanReturnWithoutVersion = is_array($matchedRouteAction)
        && in_array(($matchedRouteAction['action'] ?? null), ['redirect', 'proxy', 'robots_txt', 'platform_error', 'tombstone'], true);

    $versionId = is_array($routeAction) && ($routeAction['action'] ?? null) === 'serve' && is_string($routeAction['version_id'] ?? null)
        ? $routeAction['version_id']
        : null;
    $actionSpaceId = is_array($routeAction) && ($routeAction['action'] ?? null) === 'serve' && is_string($routeAction['space_id'] ?? null)
        ? $routeAction['space_id']
        : '';
    $version = null;
    $versions = [];
    if (!$routeActionCanReturnWithoutVersion && $versionId !== null && _spacefast_id_valid($versionId)) {
        $loadedVersion = _stattic_load_version_manifest($privateRoot, $actionSpaceId, $versionId);
        if (is_array($loadedVersion)) {
            $version = $loadedVersion;
            $versions[$versionId] = $loadedVersion;
        }
    }
    if (
        !$routeActionCanReturnWithoutVersion
        && is_array($matchedRouteAction)
        && ($matchedRouteAction['action'] ?? null) === 'serve'
        && is_string($matchedRouteAction['version_id'] ?? null)
        && !isset($versions[$matchedRouteAction['version_id']])
    ) {
        if (is_string($matchedRouteAction['space_id'] ?? null)) {
            $matchedVersion = _stattic_load_version_manifest($privateRoot, $matchedRouteAction['space_id'], $matchedRouteAction['version_id']);
            if (is_array($matchedVersion)) {
                $versions[$matchedRouteAction['version_id']] = $matchedVersion;
            }
        }
        if (!isset($versions[$matchedRouteAction['version_id']])) {
            return _stattic_platform_action_serving_config(_stattic_version_pending_action());
        }
    }
    if (!$routeActionCanReturnWithoutVersion && $versionId !== null && !is_array($version)) {
        return _stattic_platform_action_serving_config(_stattic_version_pending_action());
    }

    $runtimeConfig = is_array($hostEntry) && is_array($hostEntry['runtime_config'] ?? null)
        ? $hostEntry['runtime_config']
        : [];
    return [
        'version_id' => $versionId,
        'space_id' => $actionSpaceId,
        // THE unified policy lane ({ rules }) for runtime/access-rules.php; absent
        // or malformed host entries degrade to no access control. The serving
        // secrets the password rules resolve ride beside it.
        'policy' => is_array($hostEntry) && is_array($hostEntry['policy'] ?? null) ? $hostEntry['policy'] : [],
        'secrets' => is_array($hostEntry) && is_array($hostEntry['secrets'] ?? null) ? $hostEntry['secrets'] : [],
        'admission' => is_array($hostEntry) && is_array($hostEntry['admission'] ?? null) ? $hostEntry['admission'] : [],
        'route_name' => is_array($hostEntry) && is_string($hostEntry['route_name'] ?? null) ? $hostEntry['route_name'] : null,
        'immutable' => is_array($hostEntry) && !empty($hostEntry['immutable']),
        // Unix timestamp the served version became ready (version-host entries only);
        // access rules with windowDays compare against it locally.
        'ready_at' => is_array($hostEntry) && isset($hostEntry['ready_at']) ? (int) $hostEntry['ready_at'] : null,
        // Anonymous claim-window countdown for the expiry-rescue banner session.
        'anonymous_expires_at' => is_string($runtimeConfig['anonymous_expires_at'] ?? null) ? $runtimeConfig['anonymous_expires_at'] : null,
        // Generic serve-time content-type allowlist; absent/malformed -> no
        // restriction (the policy is opt-in per space via route config).
        'content_types' => is_array($runtimeConfig['content_types'] ?? null) && is_array($runtimeConfig['content_types']['allowed'] ?? null)
            ? $runtimeConfig['content_types']
            : null,
        // Serve-time plan entitlements (proxy-routes.md gating): read by
        // runtime/redirects.php against a `planGated` compiled rule. Absent/
        // malformed host entry -> {} -> every entitlement check fails closed
        // (runtime/redirects.php _stattic_serving_entitlement_allows).
        'entitlements' => is_array($hostEntry) && is_array($hostEntry['entitlements'] ?? null)
            ? $hostEntry['entitlements']
            : [],
        'host_routes' => $requestHostRoutes,
        'matched_host_route' => $matchedHostRoute,
        'response_headers' => $hostResponseHeaders,
        'versions' => $versions,
    ];
}

function _stattic_platform_action_serving_config(array $action, string $spaceId = '', array $responseHeaders = []): array
{
    return [
        'version_id' => null,
        'space_id' => $spaceId,
        'host_routes' => [],
        'response_headers' => $responseHeaders,
        'versions' => [],
        'platform_action' => $action,
    ];
}

function _stattic_valid_response_headers(array $headers): array
{
    $valid = [];
    foreach ($headers as $name => $value) {
        if (!is_string($name) || !is_string($value) || $name === '' || $value === '' || preg_match('/[^A-Za-z0-9-]/', $name) === 1) {
            continue;
        }
        $valid[$name] = $value;
    }
    return $valid;
}

function _stattic_platform_error_action(string $pageId, int $status, string $message, string $cacheControl = 'no-store', array $responseHeaders = []): array
{
    $action = [
        'action' => 'platform_error',
        'page_id' => $pageId,
        'status' => $status,
        'cache_control' => $cacheControl,
        'message' => $message,
        'methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    ];
    if ($responseHeaders !== []) {
        $action['response_headers'] = $responseHeaders;
    }
    return $action;
}

function _stattic_version_pending_action(): array
{
    return _stattic_platform_error_action('version-pending', 503, "This version is not active yet.\n", 'public, max-age=0, s-maxage=30, must-revalidate');
}

function _stattic_runtime_artifact_metadata_valid_lazy(mixed $artifact): bool
{
    return is_array($artifact)
        && ($artifact['runtime_schema'] ?? null) === STATTIC_RUNTIME_SCHEMA
        && is_string($artifact['runtime_engine_version'] ?? null)
        && $artifact['runtime_engine_version'] === SPACEFAST_RUNTIME_ENGINE_VERSION
        && is_string($artifact['generated_at'] ?? null)
        && $artifact['generated_at'] !== '';
}

function _stattic_runtime_current_route_pointer_valid_lazy(mixed $artifact): bool
{
    return _stattic_runtime_artifact_metadata_valid_lazy($artifact)
        && is_array($artifact)
        && ($artifact['artifact_kind'] ?? null) === 'route_current'
        && is_string($artifact['generation'] ?? null)
        && $artifact['generation'] !== ''
        && is_bool($artifact['has_wildcards'] ?? null);
}

function _stattic_runtime_route_artifact_metadata_valid_lazy(mixed $artifact, string $generation, string $kind): bool
{
    return _stattic_runtime_artifact_metadata_valid_lazy($artifact)
        && is_array($artifact)
        && ($artifact['artifact_kind'] ?? null) === $kind
        && ($artifact['generation'] ?? null) === $generation;
}

function _stattic_lazy_match_host_route(array $routes, string $requestPath, string $requestMethod): ?array
{
    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        $method = isset($route['method']) && is_string($route['method']) ? strtoupper($route['method']) : null;
        if ($method !== null && $method !== $requestMethod) {
            continue;
        }

        $location = (string) ($route['location'] ?? '/');
        $remainder = _stattic_lazy_match_path_prefix($location, $requestPath);
        if ($remainder === null) {
            continue;
        }

        $route['_remainder'] = $remainder;
        return $route;
    }

    return null;
}

function _stattic_lazy_match_path_prefix(string $prefix, string $requestPath): ?string
{
    $normalized = rtrim($prefix, '/');
    if ($normalized === '') {
        $normalized = '/';
    }
    if ($normalized === '/') {
        return $requestPath;
    }
    if ($requestPath === $normalized) {
        return '/';
    }
    if (str_starts_with($requestPath, $normalized . '/')) {
        return substr($requestPath, strlen($normalized));
    }
    return null;
}

// Host shards may be hardlinked forward from older generations (incremental
// route-index updates); current.php's `shards` manifest records the generation
// each shard was BUILT in, and that is the stamp validated here. A shard absent
// from the manifest holds no hosts and is never read.
function _stattic_route_shard_for_host(string $generationRoot, array $current, string $requestHost): array
{
    if ($requestHost === '') {
        return [];
    }
    $shard = substr(hash('sha256', $requestHost), 0, 2);
    $manifest = is_array($current['shards'] ?? null) ? $current['shards'] : null;
    $expectedGeneration = $manifest === null
        ? (string) $current['generation']
        : ($manifest[$shard] ?? null);
    if ($expectedGeneration === null) {
        return [];
    }
    if (!is_string($expectedGeneration) || $expectedGeneration === '') {
        _stattic_render_runtime_invariant_error_lazy('artifact-schema-mismatch', 'Runtime host shard schema does not match this runtime.');
    }
    $loaded = @include $generationRoot . '/hosts/' . $shard . '.php';
    if (!is_array($loaded)) {
        return [];
    }
    if (!_stattic_runtime_route_artifact_metadata_valid_lazy($loaded, $expectedGeneration, 'route_host_shard')) {
        _stattic_render_runtime_invariant_error_lazy('artifact-schema-mismatch', 'Runtime host shard schema does not match this runtime.');
    }
    return $loaded;
}

function _stattic_load_wildcard_route_shard(string $generationRoot, array $current): array
{
    $expectedGeneration = is_string($current['wildcards_generation'] ?? null)
        ? $current['wildcards_generation']
        : (string) $current['generation'];
    $loaded = @include $generationRoot . '/wildcards.php';
    if (!is_array($loaded)) {
        return [];
    }
    if (!_stattic_runtime_route_artifact_metadata_valid_lazy($loaded, $expectedGeneration, 'route_wildcards')) {
        _stattic_render_runtime_invariant_error_lazy('artifact-schema-mismatch', 'Runtime wildcard shard schema does not match this runtime.');
    }
    return $loaded;
}

function _stattic_version_root(string $privateRoot, string $spaceId, string $versionId): string
{
    return _spacefast_version_root($privateRoot, $spaceId, $versionId) . '/files';
}

function _stattic_load_version_manifest(string $privateRoot, string $spaceId, string $versionId): ?array
{
    if (!_spacefast_id_valid($spaceId) || !_spacefast_id_valid($versionId)) {
        return null;
    }

    $loaded = @include _spacefast_version_root($privateRoot, $spaceId, $versionId) . '/serving.php';
    if (is_array($loaded)) {
        if (!_stattic_runtime_artifact_metadata_valid_lazy($loaded)) {
            _stattic_render_runtime_invariant_error_lazy('artifact-schema-mismatch', 'Runtime serving artifact schema does not match this runtime.');
        }
        return $loaded;
    }

    return null;
}

function _stattic_host_route_action(array $hostEntry): ?array
{
    $action = $hostEntry['route_action'] ?? null;
    if (is_array($action) && is_string($action['action'] ?? null)) {
        if (
            $action['action'] === 'tombstone'
            // Reason-differentiated tombstones (C10): generic/CSAM are 404, DMCA
            // 451, suspended tenant state 402. page_id is mandatory.
            && in_array((int) ($action['status'] ?? 0), [402, 404, 451], true)
            && is_string($action['page_id'] ?? null)
            && is_string($action['cache_control'] ?? null)
            && is_string($action['space_id'] ?? null)
            && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        ) {
            return $action;
        }
        if (
            $action['action'] === 'platform_error'
            && ($action['page_id'] ?? null) === 'version-pending'
            && (int) ($action['status'] ?? 0) === 503
            && is_string($action['cache_control'] ?? null)
            && $action['cache_control'] !== ''
            && is_string($action['message'] ?? null)
            && $action['message'] !== ''
            && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        ) {
            return $action;
        }
        if (
            $action['action'] === 'serve'
            && is_string($action['version_id'] ?? null)
            && is_string($action['space_id'] ?? null)
            && is_string($action['target_prefix'] ?? null)
        ) {
            return $action;
        }
        _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime route action metadata is malformed.');
    }
    _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime host route action metadata is missing.');
}

function _stattic_match_wildcard_host_entry(array $entries, string $requestHost): ?array
{
    foreach ($entries as $hostname => $entry) {
        if (is_string($hostname) && is_array($entry) && _stattic_wildcard_host_matches($hostname, $requestHost)) {
            return $entry;
        }
    }

    return null;
}

function _stattic_routes_for_host(array $routesByHost, string $requestHost): array
{
    if ($requestHost !== '' && isset($routesByHost[$requestHost]) && is_array($routesByHost[$requestHost])) {
        return $routesByHost[$requestHost];
    }

    return [];
}

function _stattic_wildcard_routes_for_host(array $routesByHost, string $requestHost): array
{
    $routes = [];
    foreach ($routesByHost as $hostname => $hostRoutes) {
        if (!is_string($hostname) || !is_array($hostRoutes) || !_stattic_wildcard_host_matches($hostname, $requestHost)) {
            continue;
        }
        foreach ($hostRoutes as $route) {
            $routes[] = $route;
        }
    }

    return $routes;
}

// The one wildcard-hostname rule shared by the host-entry and host-routes
// lookups: a '*.suffix' pattern matches any host that ends in '.suffix' with
// at least one extra label's worth of bytes before it.
function _stattic_wildcard_host_matches(string $hostname, string $host): bool
{
    if (!str_starts_with($hostname, '*.')) {
        return false;
    }
    $suffix = substr($hostname, 1);
    return str_ends_with($host, $suffix) && strlen($host) > strlen($suffix);
}

function _stattic_resolve_file(array $version, string $lookup): ?string
{
    $action = _stattic_resolve_lookup_action($version, $lookup);
    return _stattic_file_path_from_lookup_action($action);
}

function _stattic_file_path_from_lookup_action(?array $action): ?string
{
    return is_array($action) && in_array(($action['action'] ?? null), ['file', 'fallback', 'nearest_404'], true) ? (string) $action['path'] : null;
}

function _stattic_status_from_lookup_action(?array $action): int
{
    if (!is_array($action) || !in_array(($action['action'] ?? null), ['file', 'fallback', 'nearest_404'], true)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup action status metadata is malformed.');
    }
    $status = (int) ($action['status'] ?? 0);
    if (!in_array($status, _stattic_lookup_valid_statuses((string) $action['action']), true)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup action status metadata is malformed.');
    }

    return $status;
}

// The per-action valid-status policy for the file-reference lookup actions,
// shared by _stattic_status_from_lookup_action and _stattic_lookup_action.
function _stattic_lookup_valid_statuses(string $action): array
{
    return match ($action) {
        'nearest_404' => [404],
        default => [200, 404],
    };
}

function _stattic_resolve_lookup_action(array $version, string $lookup): ?array
{
    $lookupMap = $version['lookup'] ?? null;
    if (!is_array($lookupMap)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup metadata is missing.');
    }

    return _stattic_lookup_action($lookupMap[trim($lookup, '/')] ?? null);
}

function _stattic_lookup_action(mixed $action): ?array
{
    if ($action === null) {
        return null;
    }
    if (!is_array($action) || !is_string($action['action'] ?? null)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup action metadata is malformed.');
    }
    if (
        $action['action'] === 'file'
        || $action['action'] === 'fallback'
        || $action['action'] === 'nearest_404'
    ) {
        if (
            _stattic_lookup_file_reference_valid($action)
            && in_array((int) ($action['status'] ?? 0), _stattic_lookup_valid_statuses($action['action']), true)
            && ($action['methods'] ?? null) === ['GET', 'HEAD']
            && is_bool($action['forced_download_or_text'] ?? null)
        ) {
            return $action;
        }
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup action metadata is malformed.');
    }
    if (
        $action['action'] === 'redirect'
        && is_string($action['destination'] ?? null)
        && $action['destination'] !== ''
        && isset($action['status'])
        && is_string($action['cache_control'] ?? null)
        && $action['cache_control'] !== ''
        && ($action['methods'] ?? null) === ['GET', 'HEAD']
    ) {
        return $action;
    }
    if (
        $action['action'] === 'invoke_zero'
        && _stattic_lookup_zero_action_valid($action)
    ) {
        return $action;
    }
    if (
        $action['action'] === 'zero_activating'
        && _stattic_lookup_zero_action_valid($action)
    ) {
        return $action;
    }
    if (
        $action['action'] === 'not_found'
        && (int) ($action['status'] ?? 0) === 404
        && is_string($action['cache_control'] ?? null)
        && $action['cache_control'] !== ''
        && ($action['methods'] ?? null) === ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
    ) {
        return $action;
    }
    _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime lookup action metadata is malformed.');
}

function _stattic_lookup_zero_action_valid(array $action): bool
{
    if (array_key_exists('operation', $action)) {
        return _stattic_lookup_zero_control_operation_valid($action['operation'] ?? null)
            && _stattic_lookup_zero_methods_valid($action['methods'] ?? null)
            && !array_key_exists('endpoint_id', $action)
            && !array_key_exists('zero_artifact', $action);
    }
    if (
        !is_string($action['endpoint_id'] ?? null)
        || $action['endpoint_id'] === ''
        || strlen($action['endpoint_id']) > 256
        || !is_string($action['zero_artifact'] ?? null)
        || !_stattic_runtime_relative_artifact_path_valid($action['zero_artifact'])
        || !_stattic_lookup_zero_methods_valid($action['methods'] ?? null)
    ) {
        return false;
    }

    if (array_key_exists('schema_hash', $action) && !(is_string($action['schema_hash']) || $action['schema_hash'] === null)) {
        return false;
    }
    if (array_key_exists('zero_indexed', $action) && !is_bool($action['zero_indexed'])) {
        return false;
    }

    if (array_key_exists('params', $action)) {
        if (!is_array($action['params'])) {
            return false;
        }
        foreach ($action['params'] as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return false;
            }
        }
    }

    return true;
}

function _stattic_lookup_zero_control_operation_valid(mixed $operation): bool
{
    return is_string($operation)
        && in_array($operation, array_column(SPACEFAST_ZERO_CONTROL_ROUTES, 'operation'), true);
}

// Tombstone page variants (C10) — ONE table mapping the baked page_id to its
// serve-time identity/status/body and compile-time robots posture. The native
// Rust compiler resolves disabled reason/category → page_id
// and bakes {page_id, status}; the server (runtime/serve.php) renders from
// this same table — a new tombstone class is added here once. CSAM serves the
// exact undeployed response, byte-identical to a host that has never been
// deployed: no body, page identity, or robots header that signals the host
// ever existed ('robots' => false suppresses the noindex marker every other
// variant advertises).
const SPACEFAST_TOMBSTONE_VARIANTS = [
    'tombstone-csam' => ['page_id' => 'undeployed', 'status' => 503, 'body' => "This site has not been deployed yet.\n", 'robots' => false],
    'tombstone-dmca' => ['page_id' => 'tombstone-dmca', 'status' => 451, 'body' => "This content is unavailable for legal reasons.\n", 'robots' => true],
    'tombstone-suspended' => ['page_id' => 'tombstone-suspended', 'status' => 402, 'body' => "This site is suspended.\n", 'robots' => true],
    'tombstone-visit-cap' => ['page_id' => 'tombstone-visit-cap', 'status' => 402, 'body' => "This site hit the visitor limit for unclaimed sites. Claim it to bring it back.\n", 'robots' => true],
    'tombstone-generic' => ['page_id' => 'tombstone-generic', 'status' => 404, 'body' => "This site is no longer available.\n", 'robots' => true],
];

// Zero-route entry shape used by the PHP import/transfer validator and the
// serve-time reader. The native Rust compiler is the only writer.
function _stattic_zero_route_entry_shape_valid(mixed $entry): bool
{
    return is_array($entry)
        && is_string($entry['method'] ?? null)
        && _stattic_lookup_zero_methods_valid([$entry['method']])
        && is_string($entry['pattern'] ?? null)
        && $entry['pattern'] !== ''
        && $entry['pattern'][0] === '/'
        && is_string($entry['endpoint_id'] ?? null)
        && $entry['endpoint_id'] !== ''
        && is_string($entry['artifact'] ?? null)
        && _stattic_runtime_relative_artifact_path_valid($entry['artifact'])
        && (!array_key_exists('schema_hash', $entry) || is_string($entry['schema_hash']) || $entry['schema_hash'] === null)
        && (!array_key_exists('zero_indexed', $entry) || is_bool($entry['zero_indexed']))
        && (!array_key_exists('activating', $entry) || is_bool($entry['activating']));
}

// Zero-runner invocation contract, shared by finalize-time DB migration
// application and the serve-time runner (runtime/zero.php) so both sides
// launch the same native binary with the same base environment.
function _stattic_zero_runner_binary(): string
{
    $binary = _stattic_config_value('SPACEFAST_ZERO_RUNNER');
    if ($binary !== '') {
        return $binary;
    }
    $releaseRoot = _stattic_config_value('SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT');
    if ($releaseRoot !== '') {
        return rtrim($releaseRoot, '/') . '/bin/stattic-zero-runner';
    }
    return dirname(__DIR__, 2) . '/bin/stattic-zero-runner';
}

function _stattic_zero_runner_base_env(array $config = []): array
{
    // Tenant variables stay inside the invocation envelope. DATABASE_URL is
    // the one explicit native-process authority, transported under a reserved
    // name with provenance so the runner can reject ambient configuration.
    $variables = is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [];
    $applicationDatabaseUrl = is_string($variables['DATABASE_URL'] ?? null)
        ? trim($variables['DATABASE_URL'])
        : '';
    if ($applicationDatabaseUrl !== '') {
        return [
            'SPACEFAST_ZERO_DATABASE_URL' => $applicationDatabaseUrl,
            'SPACEFAST_ZERO_DATABASE_URL_SOURCE' => ($config['databaseUrlSource'] ?? null) === 'provider'
                ? 'provider'
                : 'application',
        ];
    }

    $providerDatabaseUrl = _stattic_config_value('SPACEFAST_ZERO_DATABASE_URL');
    if ($providerDatabaseUrl === '') {
        $providerDatabaseUrl = _stattic_config_value('DATABASE_URL');
    }
    if ($providerDatabaseUrl === '') {
        return [];
    }
    return [
        'SPACEFAST_ZERO_DATABASE_URL' => $providerDatabaseUrl,
        'SPACEFAST_ZERO_DATABASE_URL_SOURCE' => 'provider',
    ];
}

// Shared subprocess launch mechanics (spawn, optional stdin payload, collect
// stdout/stderr, close), used by finalize-time DB migration application and the
// serve-time runner (runtime/zero.php). Returns
// ['spawned' => bool, 'exitCode' => int, 'stdout' => string, 'stderr' => string];
// spawned === false means proc_open itself failed (exitCode is meaningless).
// Error rendering stays lane-specific at the call sites: the serve lane 502s,
// the migration lane 500s.
//
// All three pipes are pumped together through stream_select. Doing this
// sequentially deadlocks in two real ways: a single blocking fwrite() stalls
// forever once stdin exceeds the ~64KB pipe buffer and the child is not
// draining it yet, and draining stdout to EOF before touching stderr stalls
// forever once the child fills the stderr pipe (it blocks writing stderr, so it
// never closes stdout). Both are reachable here — the serve lane pipes whole
// request bodies into the Zero runner, and the migration lane's runner is
// chatty on stderr.
function _stattic_runtime_run_subprocess(array $cmd, ?array $env, ?string $stdin = null): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        return ['spawned' => false, 'exitCode' => -1, 'stdout' => '', 'stderr' => ''];
    }

    $input = $stdin ?? '';
    $inputLength = strlen($input);
    $written = 0;
    $stdinPipe = $pipes[0];
    if ($inputLength === 0) {
        fclose($stdinPipe);
        $stdinPipe = null;
    } else {
        stream_set_blocking($stdinPipe, false);
    }

    // Keyed by pipe index so the select-ready resources map back to the right
    // buffer without identity games once entries start dropping out.
    $outputPipes = [1 => $pipes[1], 2 => $pipes[2]];
    stream_set_blocking($outputPipes[1], false);
    stream_set_blocking($outputPipes[2], false);
    $buffers = [1 => '', 2 => ''];

    while ($stdinPipe !== null || $outputPipes !== []) {
        $read = array_values($outputPipes);
        $write = $stdinPipe !== null ? [$stdinPipe] : [];
        $except = null;
        if (@stream_select($read, $write, $except, 1) === false) {
            break;
        }

        if ($write !== [] && $stdinPipe !== null) {
            $chunk = substr($input, $written, 65536);
            $sent = @fwrite($stdinPipe, $chunk);
            if ($sent === false) {
                // Child closed stdin (or died) — stop feeding it, keep reading.
                fclose($stdinPipe);
                $stdinPipe = null;
            } else {
                $written += $sent;
                if ($written >= $inputLength) {
                    fclose($stdinPipe);
                    $stdinPipe = null;
                }
            }
        }

        foreach ($read as $ready) {
            $index = array_search($ready, $outputPipes, true);
            if ($index === false) {
                continue;
            }
            $chunk = fread($ready, 65536);
            if (is_string($chunk) && $chunk !== '') {
                $buffers[$index] .= $chunk;
                continue;
            }
            if (feof($ready)) {
                fclose($ready);
                unset($outputPipes[$index]);
            }
        }
    }

    if ($stdinPipe !== null) {
        fclose($stdinPipe);
    }
    foreach ($outputPipes as $pipe) {
        fclose($pipe);
    }

    return ['spawned' => true, 'exitCode' => proc_close($process), 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
}

// Appends a truncated diagnostic detail (typically subprocess stderr/stdout) to
// an error message when SPACEFAST_ZERO_RUNNER_DEBUG is on; the bare message
// otherwise. Shared by both subprocess lanes.
function _stattic_zero_debug_message(string $message, mixed $detail): string
{
    if (_stattic_config_value('SPACEFAST_ZERO_RUNNER_DEBUG') !== '1' || !is_string($detail) || $detail === '') {
        return $message;
    }

    return $message . ' ' . substr($detail, 0, 2048);
}

// String-keyed string-value map filter — the variableValues sanitization
// contract, shared by the compile-side zero/config.json writer
// (admin/management.php) and the serve-time reader (runtime/zero.php) so the
// value contract cannot drift.
function _stattic_zero_string_map(array $values): array
{
    $out = [];
    foreach ($values as $name => $value) {
        if (is_string($name) && $name !== '' && is_string($value)) {
            $out[$name] = $value;
        }
    }
    return $out;
}

// Shared relative-artifact-path traversal guard: a path is valid only if it is a
// non-empty string <=512 bytes, is not absolute, contains no NUL or backslash,
// and has no parent traversal segment. Literal consecutive dots inside a normal
// segment are safe. One definition so the path-escape rules cannot drift between the
// lookup-zero artifact path and the PHP-manifest relative path. (Not hosted in
// shared/safety.php, which is generated from the TS policy and must not be edited
// by hand; this guard is runtime-only and not part of that policy surface.)
function _stattic_runtime_relative_artifact_path_valid(mixed $path): bool
{
    if (
        !is_string($path)
        || $path === ''
        || strlen($path) > 512
        || $path[0] === '/'
        || str_contains($path, "\0")
        || str_contains($path, '\\')
    ) {
        return false;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

function _stattic_lookup_zero_methods_valid(mixed $methods): bool
{
    if (!is_array($methods) || $methods === []) {
        return false;
    }
    $allowed = ['GET' => true, 'HEAD' => true, 'POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true, 'OPTIONS' => true];
    foreach ($methods as $method) {
        if (!is_string($method) || !isset($allowed[$method])) {
            return false;
        }
    }

    return true;
}

function _stattic_lookup_file_reference_valid(array $action): bool
{
    return is_string($action['path'] ?? null)
        && $action['path'] !== ''
        && is_string($action['file_shard'] ?? null)
        && preg_match('/^[a-f0-9]{2}$/', $action['file_shard']) === 1;
}

// Compiled fallback action ({path, status}; SPA is the 200/index.html shape) — the
// serving-resolution step between exact lookup and the nearest-404 chain.
function _stattic_resolve_fallback_action(array $version): ?array
{
    if (!array_key_exists('fallback', $version)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime fallback metadata is missing.');
    }
    return _stattic_lookup_action($version['fallback']);
}

// W7.1 clean-URL knob (SpaceConfig `cleanUrls` -> serving_config.clean_urls): an
// explicit boolean wins; absent (versions finalized before the knob existed)
// resolves to the smart default — ON for plain static sites, OFF when a
// 200-status SPA fallback is configured, because an SPA owns its extensionless
// routes and the fallback answers them instead.
function _stattic_serving_clean_urls_enabled(array $version): bool
{
    $config = is_array($version['serving_config'] ?? null) ? $version['serving_config'] : [];
    $explicit = $config['clean_urls'] ?? null;
    if (is_bool($explicit)) {
        return $explicit;
    }
    $fallback = $config['fallback'] ?? null;
    return !(is_array($fallback) && (int) ($fallback['status'] ?? 0) === 200);
}

function _stattic_find_nearest_404_action(array $version, string $lookup): ?array
{
    $nearest = $version['nearest_404'] ?? null;
    if (!is_array($nearest)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime 404 lookup metadata is missing.');
    }
    $segments = trim($lookup, '/') === '' ? [] : explode('/', trim($lookup, '/'));
    while (!empty($segments)) {
        array_pop($segments);
        $directory = implode('/', $segments);
        $action = _stattic_lookup_action($nearest[$directory] ?? null);
        if (is_array($action)) {
            return $action;
        }
    }

    return _stattic_lookup_action($nearest[''] ?? null);
}

function _stattic_resolve_not_found_action(array $version): array
{
    if (!array_key_exists('not_found', $version)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime not-found metadata is missing.');
    }
    $action = _stattic_lookup_action($version['not_found']);
    if (!is_array($action) || ($action['action'] ?? null) !== 'not_found') {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime not-found metadata is malformed.');
    }

    return $action;
}

function _stattic_load_file_metadata(string $versionRoot, string $path, array $lookupAction, array $version): array
{
    if (empty($version['file_shards'])) {
        _stattic_render_runtime_invariant_error_lazy('file-shard-metadata-missing', 'Runtime file shard metadata is missing.');
    }

    $shard = $lookupAction['file_shard'] ?? null;
    if (!is_string($shard) || preg_match('/^[a-f0-9]{2}$/', $shard) !== 1) {
        _stattic_render_runtime_invariant_error_lazy('file-shard-metadata-missing', 'Runtime file shard metadata is missing.');
    }
    $loaded = @include dirname($versionRoot) . '/file-shards/' . $shard . '.php';
    if (!is_array($loaded) || !_stattic_runtime_artifact_metadata_valid_lazy($loaded) || !is_array($loaded['files'] ?? null)) {
        _stattic_render_runtime_invariant_error_lazy('file-shard-metadata-missing', 'Runtime file metadata shard is missing.');
    }

    $meta = $loaded['files'][$path] ?? null;
    if (!is_array($meta)) {
        _stattic_render_runtime_invariant_error_lazy('file-metadata-missing', 'Runtime file metadata is incomplete.');
    }

    return $meta;
}

// Route patterns are URL paths, not filesystem paths. Reject a traversal
// segment, while preserving ordinary literal names that happen to contain two
// dots (for example Astro's `[...slug].astro`).
function _stattic_runtime_route_pattern_valid(mixed $pattern): bool
{
    if (
        !is_string($pattern)
        || $pattern === ''
        || $pattern[0] !== '/'
        || str_contains($pattern, "\0")
        || str_contains($pattern, '\\')
    ) {
        return false;
    }
    foreach (explode('/', trim($pattern, '/')) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

// Shared segment route-pattern matcher used by both the Zero-route resolver
// (runtime/zero-routes.php) and the PHP-manifest resolver (runtime/php-manifest.php)
// so the :splat / :name / exact scoring can never drift between the two paths.
// Pure: trims and splits both inputs on '/', scores an exact segment +10, a named
// param (:name) +2 after rawurldecoding, and a trailing :splat +1 capturing the
// remainder; returns null on any mismatch or a leftover lookup segment.
function _stattic_match_route_pattern_segments(string $pattern, string $lookup): ?array
{
    $p = trim($pattern, '/');
    $patternSegments = $p === '' ? [] : explode('/', $p);
    $l = trim($lookup, '/');
    $pathSegments = $l === '' ? [] : explode('/', $l);
    $params = [];
    $score = 0;
    $pathIndex = 0;
    foreach ($patternSegments as $segment) {
        if ($segment === ':splat') {
            $params['splat'] = implode('/', array_slice($pathSegments, $pathIndex));
            return ['params' => $params, 'score' => $score + 1];
        }
        if (!array_key_exists($pathIndex, $pathSegments)) {
            return null;
        }
        $pathSegment = $pathSegments[$pathIndex];
        if (str_starts_with($segment, ':')) {
            $name = substr($segment, 1);
            if ($name === '') {
                return null;
            }
            $params[$name] = rawurldecode($pathSegment);
            $score += 2;
        } elseif ($segment === $pathSegment) {
            $score += 10;
        } else {
            return null;
        }
        $pathIndex++;
    }
    if ($pathIndex !== count($pathSegments)) {
        return null;
    }

    return ['params' => $params, 'score' => $score];
}

// Two routes are ambiguous only when the runtime can match the same request to
// both with the same score. Parameter names do not constrain the path, and a
// :splat terminates matching exactly as _stattic_match_route_pattern_segments
// does. GET and explicit HEAD also share the HEAD request domain.
function _stattic_zero_route_patterns_ambiguous(string $leftMethod, string $leftPattern, string $rightMethod, string $rightPattern): bool
{
    if (!(
        $leftMethod === $rightMethod
        || ($leftMethod === 'GET' && $rightMethod === 'HEAD')
        || ($leftMethod === 'HEAD' && $rightMethod === 'GET')
    )) {
        return false;
    }

    $left = _stattic_zero_route_match_shape($leftPattern);
    $right = _stattic_zero_route_match_shape($rightPattern);
    if ($left['score'] !== $right['score']) {
        return false;
    }

    $leftCount = count($left['segments']);
    $rightCount = count($right['segments']);
    if (!$left['splat'] && $leftCount < $rightCount) {
        return false;
    }
    if (!$right['splat'] && $rightCount < $leftCount) {
        return false;
    }
    for ($index = 0; $index < min($leftCount, $rightCount); $index += 1) {
        $leftLiteral = $left['segments'][$index];
        $rightLiteral = $right['segments'][$index];
        if (is_string($leftLiteral) && is_string($rightLiteral) && $leftLiteral !== $rightLiteral) {
            return false;
        }
    }

    return true;
}

function _stattic_zero_route_match_shape(string $pattern): array
{
    $trimmed = trim($pattern, '/');
    $segments = $trimmed === '' ? [] : explode('/', $trimmed);
    $constraints = [];
    $score = 0;
    $splat = false;
    foreach ($segments as $segment) {
        if ($segment === ':splat') {
            $score += 1;
            $splat = true;
            break;
        }
        if (str_starts_with($segment, ':')) {
            $constraints[] = null;
            $score += 2;
            continue;
        }
        $constraints[] = $segment;
        $score += 10;
    }

    return ['segments' => $constraints, 'splat' => $splat, 'score' => $score];
}
