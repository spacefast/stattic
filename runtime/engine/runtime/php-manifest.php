<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/safety.php';
// Shared route matcher (_stattic_match_route_pattern_segments) and relative-path
// guard (_stattic_runtime_relative_artifact_path_valid) live in artifacts.php.
require_once __DIR__ . '/../shared/artifacts.php';

const STATTIC_PHP_MANIFEST_FORMAT = 'stattic.php.manifest.v1';

function _stattic_load_php_manifest_artifact(string $versionRoot): ?array
{
    // Per-request memoization keyed by the artifact path (constant within a
    // request): the rewrite/notFound path resolves the lookup twice, and a bare
    // @include re-materializes the entire route-map array each time even though
    // opcache caches the compiled file. array_key_exists distinguishes a cached
    // null (file absent) from "not yet loaded". Mirrors _stattic_template_variant_meta.
    static $cache = [];
    $path = dirname($versionRoot) . '/php-manifest.php';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    if (!is_file($path)) {
        return $cache[$path] = null;
    }
    $loaded = @include $path;
    if (!_stattic_php_manifest_valid($loaded)) {
        _stattic_render_runtime_invariant_error_lazy('php-manifest-metadata-missing', 'Runtime PHP manifest metadata is malformed.');
    }

    return $cache[$path] = $loaded;
}

function _stattic_php_manifest_valid(mixed $manifest): bool
{
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== STATTIC_PHP_MANIFEST_FORMAT) {
        return false;
    }
    if (array_key_exists('versionId', $manifest) && !(is_string($manifest['versionId']) || $manifest['versionId'] === null)) {
        return false;
    }
    if (!is_array($manifest['routes'] ?? null)) {
        return false;
    }
    foreach ($manifest['routes'] as $route) {
        if (!_stattic_php_manifest_route_valid($route)) {
            return false;
        }
    }

    return true;
}

function _stattic_resolve_php_manifest_lookup(array $manifest, string $lookup, string $requestMethod): array
{
    $result = ['record' => null, 'method_not_allowed' => false];
    // The manifest is always fully validated upstream by _stattic_load_php_manifest_artifact
    // before it reaches here, so only the cheap shape check the loop below relies on is
    // needed — not a second O(routes) deep walk per request. The loop still guards each
    // route (is_array + per-field string checks) defensively.
    if (!is_array($manifest['routes'] ?? null)) {
        return $result;
    }
    $pattern = _stattic_php_manifest_lookup_pattern($lookup);
    $dynamicBest = null;
    $dynamicBestScore = -1;
    foreach ($manifest['routes'] as $route) {
        if (!is_array($route)) {
            continue;
        }
        $routePattern = $route['pattern'] ?? null;
        if (!is_string($routePattern)) {
            continue;
        }
        if ($routePattern === $pattern) {
            if (!_stattic_php_manifest_record_allows_method($route, $requestMethod)) {
                $result['method_not_allowed'] = true;
                continue;
            }
            $result['record'] = $route;
            return $result;
        }
        if (($route['action'] ?? null) !== 'invoke_zero' || !str_contains($routePattern, ':')) {
            continue;
        }
        $match = _stattic_match_route_pattern_segments($routePattern, $pattern);
        if (!is_array($match)) {
            continue;
        }
        if (!_stattic_php_manifest_record_allows_method($route, $requestMethod)) {
            $result['method_not_allowed'] = true;
            continue;
        }
        if ($match['score'] > $dynamicBestScore) {
            $dynamicBest = $route;
            $dynamicBest['_params'] = $match['params'];
            $dynamicBestScore = $match['score'];
        }
    }

    $result['record'] = $dynamicBest;
    return $result;
}

function _stattic_php_manifest_methods_for(string $method): array
{
    return $method === 'GET' ? ['GET', 'HEAD'] : [$method];
}

function _stattic_php_manifest_lookup_result(array $manifest, string $lookup, string $requestMethod): array
{
    $lookupResult = _stattic_resolve_php_manifest_lookup($manifest, $lookup, $requestMethod);
    $record = $lookupResult['record'] ?? null;
    if (!is_array($record)) {
        return ['action' => null, 'method_not_allowed' => !empty($lookupResult['method_not_allowed'])];
    }
    if (($record['action'] ?? null) === 'serve_static') {
        $file = (string) $record['file'];
        return ['action' => [
            'action' => 'file',
            'path' => $file,
            'file_shard' => substr(hash('sha256', $file), 0, 2),
            'status' => 200,
            'methods' => _stattic_php_manifest_methods_for('GET'),
            'forced_download_or_text' => _stattic_path_is_php_like($file),
        ], 'method_not_allowed' => false];
    }
    if (($record['action'] ?? null) === 'redirect') {
        return ['action' => [
            'action' => 'redirect',
            'destination' => (string) $record['destination'],
            'status' => (int) $record['status'],
            'cache_control' => (string) $record['cacheControl'],
            'methods' => _stattic_php_manifest_methods_for('GET'),
        ], 'method_not_allowed' => false];
    }
    if (($record['action'] ?? null) === 'invoke_zero' && is_string($record['operation'] ?? null)) {
        $method = (string) $record['method'];
        return ['action' => [
            'action' => 'invoke_zero',
            'operation' => (string) $record['operation'],
            'methods' => _stattic_php_manifest_methods_for($method),
        ], 'method_not_allowed' => false];
    }
    if (($record['action'] ?? null) === 'invoke_zero' && is_string($record['zeroArtifact'] ?? null)) {
        $method = (string) $record['method'];
        $action = [
            'action' => !empty($record['activating']) ? 'zero_activating' : 'invoke_zero',
            'endpoint_id' => (string) $record['endpointId'],
            'zero_artifact' => (string) $record['zeroArtifact'],
            'methods' => _stattic_php_manifest_methods_for($method),
        ];
        if (is_string($record['schemaHash'] ?? null)) {
            $action['schema_hash'] = $record['schemaHash'];
        }
        if (is_array($record['capabilities'] ?? null)) {
            $action['capabilities'] = $record['capabilities'];
        }
        if (is_array($record['_params'] ?? null)) {
            $action['params'] = $record['_params'];
        }
        return ['action' => $action, 'method_not_allowed' => false];
    }

    return ['action' => null, 'method_not_allowed' => false];
}

function _stattic_php_manifest_route_valid(mixed $route): bool
{
    if (!is_array($route) || !is_string($route['action'] ?? null)) {
        return false;
    }
    if (!_stattic_runtime_route_pattern_valid($route['pattern'] ?? null)) {
        return false;
    }
    if ($route['action'] === 'serve_static') {
        return _stattic_runtime_relative_artifact_path_valid($route['file'] ?? null)
            && (!array_key_exists('contentType', $route) || is_string($route['contentType']))
            && (!array_key_exists('etag', $route) || is_string($route['etag']));
    }
    if ($route['action'] === 'redirect') {
        return is_string($route['destination'] ?? null)
            && $route['destination'] !== ''
            && in_array((int) ($route['status'] ?? 0), [301, 302, 303, 307, 308], true)
            && is_string($route['cacheControl'] ?? null)
            && $route['cacheControl'] !== '';
    }
    if ($route['action'] === 'invoke_zero') {
        if (is_string($route['operation'] ?? null)) {
            return is_string($route['method'] ?? null)
                && in_array($route['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
                && _stattic_lookup_zero_control_operation_valid($route['operation']);
        }
        if (
            !is_string($route['method'] ?? null)
            || !in_array($route['method'], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            || !is_string($route['endpointId'] ?? null)
            || $route['endpointId'] === ''
            || (!_stattic_runtime_relative_artifact_path_valid($route['zeroPackPath'] ?? null) && !_stattic_runtime_relative_artifact_path_valid($route['zeroArtifact'] ?? null))
            || !is_array($route['capabilities'] ?? null)
        ) {
            return false;
        }
        if (array_key_exists('schemaHash', $route) && !(is_string($route['schemaHash']) || $route['schemaHash'] === null)) {
            return false;
        }
        if (array_key_exists('activating', $route) && !is_bool($route['activating'])) {
            return false;
        }
        foreach (['db', 'fetch', 'auth', 'env', 'realtime', 'logging'] as $capability) {
            if (array_key_exists($capability, $route['capabilities']) && !is_bool($route['capabilities'][$capability])) {
                return false;
            }
        }
        return true;
    }

    return false;
}

function _stattic_php_manifest_record_allows_method(array $route, string $requestMethod): bool
{
    $action = $route['action'] ?? null;
    if ($action !== 'serve_static' && $action !== 'redirect' && $action !== 'invoke_zero') {
        return false;
    }
    $declared = $action === 'invoke_zero' ? (string) ($route['method'] ?? '') : 'GET';
    return in_array($requestMethod, _stattic_php_manifest_methods_for($declared), true);
}

function _stattic_php_manifest_lookup_pattern(string $lookup): string
{
    $trimmed = ltrim($lookup, '/');
    return $trimmed === '' ? '/' : '/' . $trimmed;
}
