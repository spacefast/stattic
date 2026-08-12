<?php
declare(strict_types=1);

// The ordered-rule evaluator, and nothing else. Its input is the residue the
// compiler could not decide, which rides the response table under "\0rules"
// (contracts §5).

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
        // Skip a non-array element by advancing its OWN cursor: a frozen index
        // would spin here forever, growing $merged until the worker is killed.
        if ($leftIndex < $leftCount && !is_array($left[$leftIndex])) {
            $leftIndex += 1;
            continue;
        }
        if ($rightIndex < $rightCount && !is_array($right[$rightIndex])) {
            $rightIndex += 1;
            continue;
        }
        $leftRule = $leftIndex < $leftCount ? $left[$leftIndex] : null;
        $rightRule = $rightIndex < $rightCount ? $right[$rightIndex] : null;
        if ($leftRule === null) {
            $merged[] = $rightRule;
            $rightIndex += 1;
        } elseif ($rightRule === null || _stattic_rule_order($leftRule) <= _stattic_rule_order($rightRule)) {
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

// Single pass over every `:name` placeholder, mirroring the JS twin `expand` in
// packages/routing/src/match.ts: a longer name (`:idx`) must not be corrupted by
// an earlier substitution of a prefix (`:id`), and an unmatched placeholder
// resolves to the empty string rather than leaking its literal `:name`.
function _stattic_expand_template(string $template, array $matches): string
{
    return (string) preg_replace_callback(
        '/:([A-Za-z][A-Za-z0-9_]*)/',
        static function (array $match) use ($matches): string {
            $value = $matches[$match[1]] ?? '';
            return is_array($value) ? '' : (string) $value;
        },
        $template
    );
}
