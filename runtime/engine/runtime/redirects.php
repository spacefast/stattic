<?php
declare(strict_types=1);

require_once __DIR__ . '/rules.php';
// _sf_cache_control: one reading of a cache class, shared with the entry lane.
require_once __DIR__ . '/../shared/cache-policy.php';

// _stattic_redirect_is_same_origin_path lives in shared/context.php.

function _stattic_rewrite_target(string $target, string $requestPath, int $status, bool $clearIncomingQuery = false): array
{
    // Split literally, not with parse_url(): it is not Unicode-safe and
    // replaces combining marks in an NFD target before the canonical path gate
    // normalizes them.
    $fragmentOffset = strpos($target, '#');
    $withoutFragment = $fragmentOffset === false ? $target : substr($target, 0, $fragmentOffset);
    $queryOffset = strpos($withoutFragment, '?');
    $targetPath = $queryOffset === false ? $withoutFragment : substr($withoutFragment, 0, $queryOffset);
    if ($targetPath === '' || !str_starts_with($targetPath, '/')) {
        return ['path' => $requestPath, 'status' => 200];
    }

    $result = ['path' => $targetPath, 'status' => $status];
    if ($clearIncomingQuery || $queryOffset !== false) {
        $result['query'] = $queryOffset === false ? '' : substr($withoutFragment, $queryOffset + 1);
    }
    return $result;
}

// $pathExists probes the response table: "does this version publish a response
// at this path". The caller supplies it, so this module needs no version
// metadata of its own.
// A redirect answers before the access lane runs and sends the same Location to
// every caller, so it makes no per-visitor privacy verdict. cache-policy.php's
// sticky per-request flags decide that for every lane.
function _stattic_apply_redirects(array $redirects, array $serving, callable $pathExists, string $requestHost, string $requestPath, string $requestMethod, int $initialStatus = 200): array
{
    // One walk answers both questions: the winning rule, and whether any
    // conditional rule's path/host/query matched on the way. A non-matching
    // condition still makes the URL request-varying, so caching the ordinary
    // branch would keep later matching visitors from the origin. A conditional
    // rule after the winner needs no mark: the winner answers every visitor at
    // this URL.
    $conditionalCandidate = false;
    $conditionalVary = [];
    $routeResult = _stattic_for_each_ordered_rule($redirects, $requestPath, function (array $rule, bool $useExact) use ($serving, $pathExists, $requestHost, $requestPath, $requestMethod, &$conditionalCandidate, &$conditionalVary): ?array {
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

        if (!empty($rule['conditions'])) {
            $conditionalCandidate = true;
            $conditionalVary = array_values(array_unique([
                ...$conditionalVary,
                ..._stattic_redirect_condition_vary_headers($rule['conditions']),
            ]));
        }

        if (!_stattic_redirect_conditions_match($rule['conditions'] ?? [])) {
            return null;
        }

        // Only after a rule matches: otherwise a pattern elsewhere in the
        // artifact makes an ordinary static path advertise methods it cannot
        // serve.
        if (!in_array($requestMethod, STATTIC_VISITOR_METHODS, true)) {
            _stattic_render_method_not_allowed_lazy(
                $action === 'redirect' ? STATTIC_VISITOR_METHODS : ['GET', 'HEAD']
            );
        }

        $target = $matches === [] ? $destination : _stattic_expand_template($destination, $matches);
        if ($action === 'proxy') {
            // This exits before the access enforcement that gates file serving,
            // so enforce on the visitor-requested path first (a token-satisfied
            // allow arms §3.2 identity forwarding).
            _stattic_enforce_access_for_proxy($serving, $requestHost, $requestPath, _stattic_runtime_effective_request_uri());
            require_once __DIR__ . '/proxy.php';
            $proxyAction = _stattic_redirect_proxy_action($target, $rule, $serving);
            if (!empty($rule['conditions'])) {
                // Per-visitor: the relayed response must never enter a shared
                // cache.
                $proxyAction['conditional_match'] = true;
            }
            _stattic_proxy_request($proxyAction, '/', $serving);
        }

        if ($action === 'rewrite' || $action === 'notFound') {
            if (empty($rule['force']) && $pathExists($requestPath)) {
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
                // Per-visitor bytes at a shared URL, and the edge cannot key its
                // cache on cookie/country/language/agent.
                $result['conditional'] = true;
            }
            return $result;
        }

        // Only the unexpanded template was origin-checked at compile time. If
        // it was same-origin, a capture must not turn the Location off-origin
        // (`/:splat` expanding to `//evil.example`). Proxy is legitimately
        // cross-origin and rewrite targets a local path; neither reaches here,
        // so this constrains redirects only.
        if (
            _stattic_redirect_is_same_origin_path($destination)
            && !_stattic_redirect_is_same_origin_path($target)
        ) {
            _stattic_render_platform_page_lazy(
                'not-found',
                404,
                ['Cache-Control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
                "Not Found\n"
            );
        }

        $queryRule = $rule['query'] ?? null;
        if (!is_array($queryRule) || count($queryRule) === 0) {
            $target = _stattic_append_current_query_to_url($target);
        }
        // An EXACT unconditional rule names one purgeable URL, so it states the
        // same `revalidate` class the compiler bakes into that rule's table
        // entry. A PATTERN expands to URLs no purge can name, so it keeps the
        // bounded shared window.
        _stattic_send_cache_policy_headers(
            false,
            empty($rule['conditions'])
                ? ($useExact
                    ? _sf_cache_control(STATTIC_RUNTIME_CACHE_CLASS_REVALIDATE, true)
                    : STATTIC_DEFAULT_EDGE_CACHE_CONTROL)
                : STATTIC_CACHE_CONTROL_NO_STORE
        );
        header('Location: ' . $target, true, $status);
        exit;
    }, true);

    if (is_array($routeResult)) {
        if ($conditionalCandidate && isset($routeResult['path'])) {
            $routeResult['conditional_candidate'] = true;
        }
        if ($conditionalVary !== []) {
            $routeResult['conditional_vary'] = $conditionalVary;
        }
        return $routeResult;
    }
    return array_filter([
        'path' => $requestPath,
        'status' => $initialStatus,
        'conditional_candidate' => $conditionalCandidate ? true : null,
        'conditional_vary' => $conditionalVary === [] ? null : $conditionalVary,
    ], static fn (mixed $value): bool => $value !== null);
}

/** @return list<string> */
function _stattic_redirect_condition_vary_headers(mixed $conditions): array
{
    if (!is_array($conditions)) {
        return [];
    }
    $headers = [];
    foreach ($conditions as $condition) {
        $kind = is_array($condition) ? (string) ($condition['kind'] ?? '') : '';
        foreach (match ($kind) {
            'agent' => ['Accept', 'User-Agent'],
            'language' => ['Cookie', 'Accept-Language'],
            'country', 'cookie' => ['Cookie'],
            default => [],
        } as $header) {
            $headers[$header] = true;
        }
    }
    return array_keys($headers);
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
    // `disabled` is compiled into the artifact; `planGated` is decided here
    // against the live entitlements doc and FAILS CLOSED, so a lagging sync can
    // only withhold the capability.
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

// Fail-closed local lookup against the synced serving-state doc. No
// control-plane call at request time.
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

function _stattic_contains_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

// Twin of isAgentRequest in packages/routing/src/match.ts, the one the CLI
// validates against. Both get their lists from
// packages/routing/fixtures/agent-detection.json; only the matching logic is
// hand-written, and it must stay substring-based in both.
function _stattic_is_agent_request(): bool
{
    // BEGIN GENERATED agent detection — DO NOT EDIT
    // Source: packages/routing/fixtures/agent-detection.json.
    // Regenerate: bun run check:agent-detection -- --write
    $agentAcceptTokens = ['text/markdown', 'text/plain'];
    $browserAcceptTokens = ['text/html'];
    $userAgentNeedles = [
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
    ];
    // END GENERATED agent detection

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (
        _stattic_contains_any($accept, $agentAcceptTokens)
        && !_stattic_contains_any($accept, $browserAcceptTokens)
    ) {
        return true;
    }

    $userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return false;
    }

    return _stattic_contains_any($userAgent, $userAgentNeedles);
}
