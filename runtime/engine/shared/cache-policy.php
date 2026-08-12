<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

// One answer to "may anything store this response, and where". Every lane asks
// here with its own inputs; none of them re-reads the request-scoped flags
// itself, because reading a subset is how one lane stays shareable while the
// rest are not.

// Sticky per-request reasons nothing may store the response at all, in any cache
// including the presenting browser: a share-link URL is itself the secret, a
// Set-Cookie response is stateful, and clearing an invalid identity cookie makes
// the turn state-changing.
function _stattic_cache_policy_no_store_flags(): bool
{
    return _stattic_access_query_token_present()
        || _stattic_identity_cookie_mutated()
        || _stattic_invalid_access_cookie_cleared();
}

// An upstream's headers are as untrusted as a visitor's: any personalization or
// restriction signal revokes the shared-cache grant the route opted into.
function _stattic_cache_policy_upstream_revokes_shared_store(array $headerLines): bool
{
    foreach ($headerLines as $header) {
        if (!is_array($header)) {
            return true;
        }
        $name = strtolower(trim((string) ($header[0] ?? '')));
        $value = trim((string) ($header[1] ?? ''));
        if (in_array($name, ['set-cookie', 'set-cookie2'], true)) {
            return true;
        }
        if ($name === 'vary') {
            $varyNames = array_values(array_filter(array_map(
                static fn (string $varyName): string => strtolower(trim($varyName)),
                explode(',', $value)
            )));
            if ($varyNames === [] || array_diff($varyNames, ['accept-encoding']) !== []) {
                return true;
            }
            continue;
        }
        if (in_array($name, ['cache-control', 'cdn-cache-control', 'surrogate-control'], true)) {
            foreach (explode(',', $value) as $directive) {
                $directiveName = strtolower(trim((string) (explode('=', trim($directive), 2)[0] ?? '')));
                if (in_array($directiveName, ['private', 'no-store', 'no-cache'], true)) {
                    return true;
                }
            }
            continue;
        }
        if ($name === 'pragma' && str_contains(strtolower($value), 'no-cache')) {
            return true;
        }
    }
    return false;
}

/**
 * Lane inputs, all optional:
 *   private   bool    the lane's own "not for a shared cache" verdict
 *   no_store  bool    a private response the visitor's browser must not keep
 *                     either (request-varying body, unpurgeable synthetic URL,
 *                     byte range, ...)
 *   public    ?string the policy when the response is not private; null hands
 *                     that path back to the lane's own headers
 *   upstream  array   untrusted upstream response lines
 *   public_revoked ?string  the public policy when the upstream revokes it
 *
 * @return array{private: bool, no_store: bool, cache_control: ?string, suppress: list<string>, lines: list<array{0: string, 1: string}>}
 */
function _stattic_cache_policy(array $lane = []): array
{
    $flags = _stattic_cache_policy_no_store_flags();
    $noStore = $flags || !empty($lane['no_store']);
    $private = $flags || !empty($lane['private']) || _stattic_access_private_cache_flag();

    $cacheControl = array_key_exists('public', $lane) ? $lane['public'] : STATTIC_CACHE_CONTROL_NO_STORE;
    if (!$private && is_string($cacheControl) && !empty($lane['upstream'])) {
        if (_stattic_cache_policy_upstream_revokes_shared_store($lane['upstream'])) {
            $cacheControl = $lane['public_revoked'] ?? STATTIC_CACHE_CONTROL_NO_STORE;
        }
    }
    if ($private) {
        $cacheControl = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }

    if (!is_string($cacheControl)) {
        return ['private' => $private, 'no_store' => $noStore, 'cache_control' => null, 'suppress' => [], 'lines' => []];
    }

    $policy = [
        'private' => $private,
        'no_store' => $noStore,
        'cache_control' => $cacheControl,
        'suppress' => $private
            ? array_values(array_unique([
                ...STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS,
                ...STATTIC_PRIVATE_CONTENT_SUPPRESSED_HEADERS,
            ]))
            : STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS,
        'lines' => [],
    ];
    $policy['lines'] = _stattic_cache_policy_apply_lines($policy, []);
    return $policy;
}

// Merges a lane's own response lines with the decided policy: the Cache-Control
// goes last, and on a private response the whole list passes through the
// private-content boundary so no relayed header survives it.
function _stattic_cache_policy_apply_lines(array $policy, array $lines): array
{
    if ($policy['cache_control'] === null) {
        return $lines;
    }
    $lines[] = ['Cache-Control', $policy['cache_control']];
    return $policy['private']
        ? _stattic_private_content_response_header_lines($lines)
        : $lines;
}

function _stattic_cache_policy_header_map(array $policy): array
{
    $headers = [];
    foreach ($policy['lines'] as [$name, $value]) {
        $headers[$name] = $value;
    }
    return $headers;
}

// Replace, never append: the decided policy stomps anything a route, a host
// default or an upstream emitted earlier.
function _stattic_cache_policy_send(array $policy): void
{
    if ($policy['private']) {
        _stattic_clear_private_content_response_headers();
    }
    foreach ($policy['lines'] as [$name, $value]) {
        if (_stattic_platform_owns_header($name)) {
            continue;
        }
        header($name . ': ' . $value, true);
    }
    // §16: the edge opt-in travels with the policy that justifies it, and this is
    // its only writer — a tenant can never steer the edge.
    _stattic_clear_platform_owned_response_headers();
    header(
        STATTIC_EDGE_CACHE_HEADER . ': ' . _stattic_edge_cache_directive($policy['cache_control']),
        true
    );
}

// Decide and send in one step, for the lanes whose only input is "is this
// private" plus the policy they would otherwise use.
function _stattic_send_cache_policy_headers(bool $privateCache, string $publicCacheControl): void
{
    _stattic_cache_policy_send(_stattic_cache_policy([
        'private' => $privateCache,
        'public' => $publicCacheControl,
    ]));
}
