<?php
declare(strict_types=1);

function _stattic_load_rule_artifact(string $versionRoot, string $name, string $errorCode, string $message): array
{
    // Per-request memoization keyed by the artifact path (constant within a
    // request); mirrors _stattic_load_php_manifest_artifact. The error path
    // renders and exits, so only validated arrays are ever cached.
    static $cache = [];
    $path = dirname($versionRoot) . '/' . $name . '.php';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    $loaded = @include $path;
    if (!is_array($loaded) || !_stattic_runtime_artifact_metadata_valid_lazy($loaded)) {
        _stattic_render_runtime_invariant_error_lazy($errorCode, $message);
    }
    return $cache[$path] = $loaded;
}

// Loads an ordered-rule artifact (redirects, headers) into the {exact, pattern}
// shape the ordered-rule visitors expect. $section descends one level first for
// artifacts that nest their rules under a named key; a missing or malformed
// section is an invariant failure, never an empty rule set.
function _stattic_load_ordered_rule_artifact(string $versionRoot, string $name, string $errorCode, string $message, ?string $section = null): array
{
    $loaded = _stattic_load_rule_artifact($versionRoot, $name, $errorCode, $message);
    if ($section !== null) {
        $inner = is_array($loaded[$section] ?? null) ? $loaded[$section] : null;
        if ($inner === null) {
            _stattic_render_runtime_invariant_error_lazy($errorCode, $message);
        }
        $loaded = $inner;
    }
    $exact = $loaded['exact'] ?? [];
    $pattern = $loaded['pattern'] ?? [];
    return [
        'exact' => is_array($exact) ? $exact : [],
        'pattern' => is_array($pattern) ? $pattern : [],
    ];
}

function _stattic_for_each_ordered_rule(array $rules, string $requestPath, callable $visit, bool $normalizeTrailingSlash = false): mixed
{
    $exactPath = $normalizeTrailingSlash ? _stattic_redirect_match_path($requestPath) : $requestPath;
    $exactRules = $rules['exact'][$exactPath] ?? [];
    if (!is_array($exactRules)) {
        $exactRules = [];
    }

    $patternRules = _stattic_pattern_rules_for_path($rules['pattern'] ?? [], $exactPath);

    $tagged = [];
    foreach ($exactRules as $exactRule) {
        if (is_array($exactRule)) {
            $tagged[] = $exactRule + ['_exact' => true];
        }
    }

    foreach (_stattic_merge_ordered_rules($tagged, $patternRules) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $result = $visit($rule, !empty($rule['_exact']));
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

// Shared per-rule request-match preamble for the ordered-rule visitors: the
// path regex (pattern rules only; an empty regex never matches) and the
// case-insensitive hostRegex. Fills the capture out-params from the matches.
function _stattic_ordered_rule_request_matches(array $rule, bool $useExact, string $requestPath, string $requestHost, array &$pathMatches = [], array &$hostMatches = []): bool
{
    $pathMatches = [];
    $hostMatches = [];
    if (!$useExact) {
        $regex = (string) ($rule['regex'] ?? '');
        if ($regex === '' || !preg_match('#' . $regex . '#', $requestPath, $pathMatches)) {
            return false;
        }
    }

    $hostRegex = (string) ($rule['hostRegex'] ?? '');
    if ($hostRegex !== '' && !preg_match('#' . $hostRegex . '#i', $requestHost, $hostMatches)) {
        return false;
    }

    return true;
}

function _stattic_rule_order(array $rule): int
{
    return isset($rule['order']) ? (int) $rule['order'] : PHP_INT_MAX;
}

function _stattic_pattern_rules_for_path(mixed $pattern, string $requestPath): array
{
    if (!is_array($pattern) || $pattern === []) {
        return [];
    }
    if (!array_key_exists('fallback', $pattern) && !array_key_exists('by_first_segment', $pattern)) {
        return $pattern;
    }

    $fallback = is_array($pattern['fallback'] ?? null) ? $pattern['fallback'] : [];
    $byFirstSegment = is_array($pattern['by_first_segment'] ?? null) ? $pattern['by_first_segment'] : [];
    $segment = _stattic_first_path_segment($requestPath);
    $bucket = $segment !== '' && is_array($byFirstSegment[$segment] ?? null)
        ? $byFirstSegment[$segment]
        : [];

    return _stattic_merge_ordered_rules($fallback, $bucket);
}

function _stattic_first_path_segment(string $path): string
{
    $trimmed = ltrim($path, '/');
    if ($trimmed === '') {
        return '';
    }
    return explode('/', $trimmed, 2)[0] ?? '';
}

function _stattic_merge_ordered_rules(array $left, array $right): array
{
    if ($left === []) {
        return $right;
    }
    if ($right === []) {
        return $left;
    }

    $merged = [];
    $leftIndex = 0;
    $rightIndex = 0;
    $leftCount = count($left);
    $rightCount = count($right);
    while ($leftIndex < $leftCount || $rightIndex < $rightCount) {
        $leftRule = $left[$leftIndex] ?? null;
        $rightRule = $right[$rightIndex] ?? null;
        $useLeft = is_array($leftRule) && (!is_array($rightRule) || _stattic_rule_order($leftRule) <= _stattic_rule_order($rightRule));
        if ($useLeft) {
            $merged[] = $leftRule;
            $leftIndex += 1;
        } else {
            $merged[] = $rightRule;
            $rightIndex += 1;
        }
    }
    return $merged;
}

function _stattic_redirect_match_path(string $path): string
{
    return $path === '/' ? '/' : rtrim($path, '/');
}

function _stattic_expand_template(string $template, array $matches): string
{
    $expanded = $template;
    foreach ($matches as $name => $value) {
        if (is_int($name)) {
            continue;
        }
        $expanded = str_replace(':' . $name, (string) $value, $expanded);
    }
    return $expanded;
}
