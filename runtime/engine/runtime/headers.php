<?php
declare(strict_types=1);

require_once __DIR__ . '/rules.php';
// _stattic_platform_managed_header() re-refuses at apply time what the compilers
// already filter, so a compiler gap cannot let user rules emit Set-Cookie,
// Location, X-Accel-Redirect/X-Sendfile, or forge x-spacefast-*/x-stattic-*.
require_once __DIR__ . '/../shared/safety.php';

// Every `_headers` rule the version declares, in publisher order. $requestPath
// is the CLIENT path: the table key that answers a request is not always the
// URL the visitor asked for, so matching cannot happen at compile time.
//
// Removals survive collection so a later rule can delete a header an entry's
// compiled set carries, not just one an earlier rule set.
//
// @return array{0: array<string, string>, 1: array<string, array{origin: string}>}
function _stattic_collect_response_headers(array $rules, string $requestHost, string $requestPath): array
{
    $applied = [];
    $removed = [];

    _stattic_for_each_ordered_rule($rules, $requestPath, function (array $rule, bool $useExact) use (&$applied, &$removed, $requestHost, $requestPath): null {
        $pathMatches = [];
        $hostMatches = [];
        if (!_stattic_ordered_rule_request_matches($rule, $useExact, $requestPath, $requestHost, $pathMatches, $hostMatches)) {
            return null;
        }

        $captures = $pathMatches;
        foreach ($hostMatches as $key => $value) {
            $captures[$key] = $value;
        }
        _stattic_apply_header_operations($applied, $rule['operations'] ?? [], $captures, (string) ($rule['origin'] ?? 'file'), $removed);

        return null;
    });

    $headers = [];
    foreach ($applied as $entry) {
        $headers[$entry['name']] = $entry['value'];
    }

    return [$headers, $removed];
}

// Repeated `set` ops for one name fold into a comma-joined value, but only
// within a grammar: `_headers` is authoritative, so a sf.jsonc rule naming a
// header a file rule already set is skipped, because folding would produce
// values browsers discard outright (`X-Frame-Options: DENY,SAMEORIGIN`).
function _stattic_apply_header_operations(array &$applied, array $operations, array $captures, string $origin = 'file', ?array &$removed = null): void
{
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $name = trim((string) ($operation['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lower = strtolower($name);
        if (_stattic_platform_managed_header($lower)) {
            continue;
        }
        if (($operation['kind'] ?? 'set') === 'remove') {
            unset($applied[$lower]);
            if ($removed !== null) {
                $removed[$lower] = ['origin' => $origin];
            }
            continue;
        }

        if ($removed !== null) {
            if ($origin === 'config' && ($removed[$lower]['origin'] ?? null) === 'file') {
                continue;
            }
            unset($removed[$lower]);
        }
        $value = _stattic_expand_template((string) ($operation['value'] ?? ''), $captures);
        if (isset($applied[$lower])) {
            if ($origin === 'config' && ($applied[$lower]['origin'] ?? 'file') === 'file') {
                continue;
            }
            $applied[$lower]['value'] .= ',' . $value;
        } else {
            $applied[$lower] = [
                'name' => $name,
                'value' => $value,
                'origin' => $origin,
            ];
        }
    }
}
