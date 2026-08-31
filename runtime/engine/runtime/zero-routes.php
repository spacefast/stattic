<?php
declare(strict_types=1);

// Shares _stattic_match_route_pattern_segments so Zero-route and PHP-manifest
// scoring stay in lockstep.
require_once __DIR__ . '/../shared/artifacts.php';

// Patterns only: exact Zero endpoints are compiled into the response table, so
// the artifact's `exact` bucket is never consulted at serve time.
function _stattic_resolve_zero_route_action(string $versionRoot, string $lookup, string $requestMethod): array
{
    $routes = _stattic_load_zero_routes_artifact($versionRoot);
    $lookup = trim($lookup, '/');
    $firstSegment = $lookup === '' ? '' : explode('/', $lookup, 2)[0];
    $buckets = [
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
            if (!_stattic_functions_route_method_matches($entry['method'], $requestMethod)) {
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
        'endpoint' => $entry['endpoint_id'],
        'artifact' => $entry['artifact'],
        'execution_mode' => is_string($entry['execution_mode'] ?? null)
            ? $entry['execution_mode']
            : _stattic_zero_derived_execution_mode('endpoint', $entry['method']),
        'methods' => $methods,
        'params' => $params,
    ];
    if (array_key_exists('schema_hash', $entry)) {
        $action['schema_hash'] = $entry['schema_hash'];
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
    $loaded = include $path;
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
