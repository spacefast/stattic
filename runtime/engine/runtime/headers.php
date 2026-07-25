<?php
declare(strict_types=1);

require_once __DIR__ . '/rules.php';
// SPACEFAST_PLATFORM_MANAGED_HEADERS (generated from the TS policy source) lives in
// shared/safety.php: the native Rust compiler filters those headers out of _headers
// rules; the runtime refuses them again at apply time so a compiler gap can never let
// user rules emit Set-Cookie, Location, X-Accel-Redirect/X-Sendfile, etc.
require_once __DIR__ . '/../shared/safety.php';

function _stattic_collect_response_headers(array $rules, string $requestHost, string $requestPath): array
{
    $applied = [];

    _stattic_for_each_ordered_rule($rules, $requestPath, function (array $rule, bool $useExact) use (&$applied, $requestHost, $requestPath): null {
        $pathMatches = [];
        $hostMatches = [];
        if (!_stattic_ordered_rule_request_matches($rule, $useExact, $requestPath, $requestHost, $pathMatches, $hostMatches)) {
            return null;
        }

        $captures = $pathMatches;
        foreach ($hostMatches as $key => $value) {
            $captures[$key] = $value;
        }
        _stattic_apply_header_operations($applied, $rule['operations'] ?? [], $captures);

        return null;
    });

    $headers = [];
    foreach ($applied as $entry) {
        $headers[$entry['name']] = $entry['value'];
    }

    return $headers;
}

function _stattic_apply_header_operations(array &$applied, array $operations, array $captures): void
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
        if (isset(SPACEFAST_PLATFORM_MANAGED_HEADERS[$lower])) {
            continue;
        }
        if (($operation['kind'] ?? 'set') === 'remove') {
            unset($applied[$lower]);
            continue;
        }

        $value = _stattic_expand_template((string) ($operation['value'] ?? ''), $captures);
        if (isset($applied[$lower])) {
            $applied[$lower]['value'] .= ',' . $value;
        } else {
            $applied[$lower] = [
                'name' => $name,
                'value' => $value,
            ];
        }
    }
}
