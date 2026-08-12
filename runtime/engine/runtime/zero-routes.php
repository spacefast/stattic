<?php
declare(strict_types=1);

// Shares _stattic_match_route_pattern_segments so Zero-route and PHP-manifest
// scoring stay in lockstep.
require_once __DIR__ . '/../shared/artifacts.php';

// Two-pass by contract: callers MUST run the $exactOnly pass first (so a
// committed file cannot steal endpoint precedence); the pattern pass assumes it
// ran and skips the exact bucket entirely.
function _stattic_resolve_zero_route_action(string $versionRoot, array $version, string $lookup, string $requestMethod, bool $exactOnly = false): array
{
    if (empty($version['zero_routes'])) {
        return ['action' => null, 'method_not_allowed' => false];
    }

    $routes = _stattic_load_zero_routes_artifact($versionRoot);
    $lookup = trim($lookup, '/');
    $firstSegment = $lookup === '' ? '' : explode('/', $lookup, 2)[0];
    $buckets = $exactOnly
        ? [is_array($routes['exact'] ?? null) ? $routes['exact'] : []]
        : [
            is_array($routes['by_first_segment'][$firstSegment] ?? null) ? $routes['by_first_segment'][$firstSegment] : [],
            is_array($routes['fallback'] ?? null) ? $routes['fallback'] : [],
        ];

    $best = null;
    $bestScore = -1;
    $pathMatchedOtherMethod = false;
    foreach ($buckets as $bucket) {
        foreach ($bucket as $entry) {
            if (!_stattic_zero_route_entry_shape_valid($entry)) {
                _stattic_render_runtime_invariant_error_lazy('zero-route-metadata-missing', 'Runtime Zero route metadata is malformed.');
            }
            $match = _stattic_match_route_pattern_segments($entry['pattern'], $lookup);
            if (!is_array($match)) {
                continue;
            }
            if (!_stattic_zero_route_method_matches($entry['method'], $requestMethod)) {
                $pathMatchedOtherMethod = true;
                continue;
            }
            if ($match['score'] > $bestScore) {
                $best = [$entry, $match['params']];
                $bestScore = $match['score'];
            }
        }
    }

    if ($best === null) {
        return ['action' => null, 'method_not_allowed' => $pathMatchedOtherMethod];
    }

    [$entry, $params] = $best;
    $methods = $entry['method'] === 'GET' ? ['GET', 'HEAD'] : [$entry['method']];
    $action = [
        'action' => 'invoke_zero',
        'endpoint_id' => $entry['endpoint_id'],
        'zero_artifact' => $entry['artifact'],
        'methods' => $methods,
        'params' => $params,
    ];
    if (array_key_exists('schema_hash', $entry)) {
        $action['schema_hash'] = $entry['schema_hash'];
    }
    if (!empty($entry['zero_indexed'])) {
        $action['zero_indexed'] = true;
    }

    return ['action' => $action, 'method_not_allowed' => false];
}

function _stattic_load_zero_routes_artifact(string $versionRoot): array
{
    // The error paths render and exit, so only validated arrays are ever cached.
    static $cache = [];
    $path = dirname($versionRoot) . '/zero/routes.php';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    $loaded = @include $path;
    if (!is_array($loaded) || !_stattic_runtime_artifact_metadata_valid_lazy($loaded) || ($loaded['artifact_kind'] ?? null) !== 'zero_routes') {
        _stattic_render_runtime_invariant_error_lazy('zero-route-metadata-missing', 'Runtime Zero route metadata is missing.');
    }
    foreach (['exact', 'by_first_segment', 'fallback'] as $key) {
        if (!is_array($loaded[$key] ?? null)) {
            _stattic_render_runtime_invariant_error_lazy('zero-route-metadata-missing', 'Runtime Zero route metadata is malformed.');
        }
    }

    return $cache[$path] = $loaded;
}

function _stattic_zero_route_method_matches(string $routeMethod, string $requestMethod): bool
{
    return $routeMethod === $requestMethod || ($routeMethod === 'GET' && $requestMethod === 'HEAD');
}
