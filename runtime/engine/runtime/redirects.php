<?php
declare(strict_types=1);

require_once __DIR__ . '/rules.php';

function _stattic_rewrite_target(string $target, string $requestPath, int $status, bool $clearIncomingQuery = false): array
{
    $parts = parse_url($target);
    $targetPath = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    if ($targetPath === '' || !str_starts_with($targetPath, '/')) {
        return ['path' => $requestPath, 'status' => 200];
    }

    $result = ['path' => $targetPath, 'status' => $status];
    if ($clearIncomingQuery || (is_array($parts) && array_key_exists('query', $parts))) {
        $result['query'] = (string) ($parts['query'] ?? '');
    }
    return $result;
}

function _stattic_apply_redirects(array $redirects, array $serving, array $version, string $requestHost, string $requestPath, bool $privateCache, int $initialStatus = 200, ?string $mountPrefix = null): array
{
    $routeResult = _stattic_for_each_ordered_rule($redirects, $requestPath, function (array $rule, bool $useExact) use ($serving, $version, $requestHost, $requestPath, $privateCache, $mountPrefix): ?array {
        $destination = (string) ($rule['destination'] ?? '');
        $status = (int) ($rule['status'] ?? 302);
        $action = (string) ($rule['action'] ?? 'redirect');
        if ($destination === '') {
            return null;
        }

        $matches = [];
        if (!_stattic_ordered_rule_request_matches($rule, $useExact, $requestPath, $requestHost, $matches)) {
            return null;
        }

        if (!_stattic_redirect_query_matches($rule['query'] ?? null, $matches)) {
            return null;
        }

        if (!_stattic_redirect_conditions_match($rule['conditions'] ?? [])) {
            return null;
        }

        $target = $matches === [] ? $destination : _stattic_expand_template($destination, $matches);
        if ($action === 'proxy') {
            // External 200-proxy exits the pipeline here, before the unified
            // access enforcement that gates file serving — enforce on the
            // visitor-requested path first (challenge/deny render inside; a
            // token-satisfied allow arms §3.2 identity forwarding).
            _stattic_enforce_access_for_proxy($serving, $requestHost, $requestPath, _stattic_runtime_effective_request_uri());
            require_once __DIR__ . '/proxy.php';
            $proxyAction = _stattic_redirect_proxy_action($target, $rule, $serving);
            if (!empty($rule['conditions'])) {
                // Condition-matched dispatch is per-visitor; the relayed
                // response must never enter a shared cache (proxy.php pins
                // `private, no-store` and drops the origin's cache policy).
                $proxyAction['conditional_match'] = true;
            }
            _stattic_proxy_request($proxyAction, '/');
        }

        if ($action === 'rewrite' || $action === 'notFound') {
            $force = !empty($rule['force']);
            $lookup = ltrim(rawurldecode($requestPath), '/');
            if (!$force && _stattic_resolve_file($version, $lookup) !== null) {
                return null;
            }

            $queryRule = $rule['query'] ?? null;
            $result = _stattic_rewrite_target(
                $target,
                $requestPath,
                $action === 'notFound' ? 404 : 200,
                is_array($queryRule) && count($queryRule) > 0
            );
            if (!empty($rule['conditions'])) {
                // Serve-time consumers downgrade condition-matched rewrites to
                // private/no-store: the served bytes are per-visitor (cookie /
                // country / language / agent) at a shared URL, and the edge
                // cannot key its cache on any of those inputs.
                $result['conditional'] = true;
            }
            return $result;
        }

        $queryRule = $rule['query'] ?? null;
        if (!is_array($queryRule) || count($queryRule) === 0) {
            $target = _stattic_append_current_query_to_url($target);
        }
        $target = _stattic_mount_local_location($target, $mountPrefix);

        // Explicit edge policy on the artifact-lane redirect (the compiled
        // exact-rule lane already emits one via _stattic_emit_redirect_response;
        // this lane serves pattern/host/query/conditional rules). Unconditional
        // rules are a pure function of the cache key (host+path+query) and take
        // the short default edge TTL; a condition-matched rule (cookie /
        // country / language / agent) is per-visitor and must never be stored.
        _stattic_send_cache_policy_headers(
            $privateCache,
            empty($rule['conditions']) ? STATTIC_DEFAULT_EDGE_CACHE_CONTROL : STATTIC_CACHE_CONTROL_NO_STORE
        );
        header('Location: ' . $target, true, $status);
        exit;
    }, true);

    return is_array($routeResult) ? $routeResult : ['path' => $requestPath, 'status' => $initialStatus];
}

function _stattic_redirect_exact_rule_exists(array $redirects, string $requestPath): bool
{
    $exactPath = _stattic_redirect_match_path($requestPath);
    $rules = $redirects['exact'][$exactPath] ?? [];
    return is_array($rules) && count($rules) > 0;
}

function _stattic_redirect_proxy_action(string $target, array $rule, array $serving): array
{
    $action = [
        'action' => 'proxy',
        'upstream' => $target,
        'target_prefix' => '/',
        'methods' => ['GET', 'HEAD'],
        'headers' => [],
        'forwardHeaders' => [],
        'cache' => ($rule['cache'] ?? null) === 'shared' ? 'shared' : null,
        'bodySizeLimitBytes' => 1048576,
        'timeoutSeconds' => 30,
        'connectTimeoutSeconds' => 10,
    ];
    // DUAL READ, two generations of plan gating (proxy-routes.md: "the rule
    // stays in your config and activates the moment you upgrade — no redeploy
    // needed"):
    //  - `disabled` (BACK-COMPAT): already-finalized artifacts compiled the
    //    plan verdict in at publish time. Still honored forever — those rules
    //    only come back to life on a republish, exactly as before this change.
    //  - `planGated` (CURRENT): the compiled artifact is plan-agnostic; the
    //    verdict is decided HERE, against the live serving-state entitlements
    //    doc synced independently of any publish. FAIL CLOSED — an absent or
    //    stale entitlements doc (sync lag, never-synced space) always resolves
    //    to "not entitled": a lagging sync can only withhold the capability,
    //    never grant one that hasn't been confirmed.
    if (!empty($rule['disabled'])) {
        $action['disabled'] = true;
        if (is_string($rule['disabledReason'] ?? null) && $rule['disabledReason'] !== '') {
            $action['disabledReason'] = $rule['disabledReason'];
        }
        return $action;
    }
    $gate = $rule['planGated'] ?? null;
    if (is_string($gate) && $gate !== '' && !_stattic_serving_entitlement_allows($serving, $gate)) {
        $action['disabled'] = true;
        $action['disabledReason'] = 'free_external_proxy_disabled';
    }
    return $action;
}

// Fail-closed local lookup against the per-request serving-state doc
// ($serving['entitlements'], populated by shared/artifacts.php from the
// space's synced entitlements — see admin/generate.php
// _stattic_runtime_stored_entitlements). No control-plane call at request
// time: this is exactly the local array lookup the architecture requires.
function _stattic_serving_entitlement_allows(array $serving, string $gate): bool
{
    $entitlements = is_array($serving['entitlements'] ?? null) ? $serving['entitlements'] : [];
    return match ($gate) {
        'external_proxy' => !empty($entitlements['externalProxy']),
        default => false,
    };
}

function _stattic_redirect_query_matches(mixed $queryRule, array &$matches): bool
{
    if (!is_array($queryRule) || count($queryRule) === 0) {
        return true;
    }

    $query = _stattic_parse_query_preserving_names((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if (count($query) !== count($queryRule)) {
        return false;
    }

    foreach ($queryRule as $name => $capture) {
        $queryName = (string) $name;
        if (!array_key_exists($queryName, $query)) {
            return false;
        }
        if (is_string($capture)) {
            $value = $query[$queryName];
            $matches[$capture] = is_array($value) ? implode(",", $value) : (string) $value;
        }
    }

    return true;
}

function _stattic_parse_query_preserving_names(string $queryString): array
{
    $query = [];
    foreach (explode('&', $queryString) as $pair) {
        if ($pair === '') {
            continue;
        }

        $parts = explode('=', $pair, 2);
        $name = rawurldecode(str_replace('+', ' ', $parts[0] ?? ''));
        if ($name === '') {
            continue;
        }

        $value = rawurldecode(str_replace('+', ' ', $parts[1] ?? ''));
        if (array_key_exists($name, $query)) {
            if (!is_array($query[$name])) {
                $query[$name] = [$query[$name]];
            }
            $query[$name][] = $value;
        } else {
            $query[$name] = $value;
        }
    }

    return $query;
}

function _stattic_redirect_conditions_match(mixed $conditions): bool
{
    if (!is_array($conditions) || count($conditions) === 0) {
        return true;
    }

    $cookieNames = null;

    foreach ($conditions as $condition) {
        if (!is_array($condition)) {
            return false;
        }
        $kind = (string) ($condition['kind'] ?? '');
        $values = is_array($condition['values'] ?? null) ? $condition['values'] : [];

        if ($kind === 'country') {
            $country = strtolower((string) ($_COOKIE['nf_country'] ?? ''));
            if ($country === '' || !in_array($country, $values, true)) {
                return false;
            }
            continue;
        }

        if ($kind === 'language') {
            $language = strtolower(trim(explode(';', (string) ($_COOKIE['nf_lang'] ?? ''), 2)[0] ?? ''));
            if ($language === '') {
                $acceptLanguage = explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 2)[0] ?? '';
                $language = strtolower(trim(explode(';', $acceptLanguage, 2)[0] ?? ''));
            }
            if ($language === '' || !in_array($language, $values, true)) {
                return false;
            }
            continue;
        }

        if ($kind === 'cookie') {
            $cookieNames ??= array_change_key_case($_COOKIE, CASE_LOWER);
            $matched = false;
            foreach ($values as $name) {
                if (isset($cookieNames[strtolower((string) $name)])) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
            continue;
        }

        if ($kind === 'agent') {
            if (!_stattic_is_agent_request()) {
                return false;
            }
            continue;
        }

        return false;
    }

    return true;
}

// Must stay in lockstep with isAgentRequest in packages/routing/src/match.ts —
// the TS matcher is what the CLI validates and simulates against, this function
// is what serves. Agent-like: an Accept that prefers text without asking for
// HTML, or a known agent/script user agent (curl and wget count: on this
// platform a shell fetch is an agent, not a person). The shared expectation
// table lives in packages/routing/fixtures/agent-detection.json (replayed
// against both twins, needle list parity-checked): widen there first, then
// both implementations.
function _stattic_is_agent_request(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (
        (str_contains($accept, 'text/plain') || str_contains($accept, 'text/markdown'))
        && !str_contains($accept, 'text/html')
    ) {
        return true;
    }

    $userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return false;
    }

    foreach ([
        'anthropic',
        'chatgpt',
        'claude',
        'codex',
        'curl',
        'cursor',
        'gptbot',
        'llm',
        'openai',
        'perplexity',
        'wget',
    ] as $needle) {
        if (str_contains($userAgent, $needle)) {
            return true;
        }
    }

    return false;
}
