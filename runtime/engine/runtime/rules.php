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

// The compiled `regex`/`hostRegex` is a bare PCRE body: the routing compilers
// escape `| \ { } ( ) [ ] ^ $ + ? .` and nothing else, so a literal `#` from a
// source like `/a#b/:id` arrives unescaped and would close the delimiter. PCRE
// then reads the rest as modifiers and the rule silently never matches. This is
// also the only side that can fix versions already published, whose artifacts
// are immutable. If the compilers ever start escaping `#`, this must change with
// them: `\#` would become `\\#` here.
function _stattic_rule_pattern(string $regex, string $modifiers = ''): string
{
    return '#' . str_replace('#', '\\#', $regex) . '#' . $modifiers;
}

// Most compiled path patterns are `^<literal>$`, a route with no placeholder
// and no wildcard. Recognising that spelling costs one strpbrk over the body,
// where running it costs a pattern-string build plus a PCRE match, and every
// rule in the walk pays that on every request.
function _stattic_rule_literal_path(string $regex): ?string
{
    return strlen($regex) >= 2
        && $regex[0] === '^'
        && $regex[strlen($regex) - 1] === '$'
        && strpbrk(substr($regex, 1, -1), '\\^$.[]|()?*+{}') === false
            ? substr($regex, 1, -1)
            : null;
}

function _stattic_ordered_rule_request_matches(array $rule, bool $useExact, string $requestPath, string $requestHost, array &$pathMatches = [], array &$hostMatches = []): bool
{
    $pathMatches = [];
    $hostMatches = [];
    if (!$useExact) {
        $regex = (string) ($rule['regex'] ?? '');
        if ($regex === '') {
            return false;
        }
        // The trailing-newline exclusion is what makes equality and PCRE agree
        // on exactly the same set of matches: an unanchored-by-`D` `$` also
        // matches before a final newline. A canonical request path can never
        // carry one, so this only ever costs the check.
        $literal = str_ends_with($requestPath, "\n") ? null : _stattic_rule_literal_path($regex);
        if ($literal !== null) {
            if ($literal !== $requestPath) {
                return false;
            }
            // What preg_match would have written: the whole match, no groups.
            $pathMatches = [$requestPath];
        } elseif (!preg_match(_stattic_rule_pattern($regex), $requestPath, $pathMatches)) {
            return false;
        }
    }

    $hostRegex = (string) ($rule['hostRegex'] ?? '');
    if ($hostRegex !== '' && !preg_match(_stattic_rule_pattern($hostRegex, 'i'), $requestHost, $hostMatches)) {
        return false;
    }

    return true;
}

function _stattic_rule_order(array $rule): int
{
    return isset($rule['order']) ? (int) $rule['order'] : PHP_INT_MAX;
}

// The compiler emits only the bucketed {fallback, by_first_segment} shape.
function _stattic_pattern_rules_for_path(mixed $pattern, string $requestPath): array
{
    if (!is_array($pattern) || $pattern === []) {
        return [];
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
