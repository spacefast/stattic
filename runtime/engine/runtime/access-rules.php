<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/admission.php';

// THE visitor access enforcer (packages/common/src/contracts/access.ts,
// access-plan §3). Firewall ⊂ access: one Rule
// `{match, effect, auth?{requiredGrants, issuers?, acquire?}}`, first-match-wins,
// exactly ONE satisfaction test (X-32): verified grants ∩ requiredGrants
// (glob-aware). `acquire` only configures how the challenge page lets a visitor
// obtain a token — password (local `pw:` mint / basic) or login (redirect). All
// crypto goes through shared/jwt.php; all cookies through _spacefast_set_cookie.

// Entry point. Returns TRUE when the served response must be private/no-store
// (the path is covered by a non-public rule — access-plan X-21 edge-cache
// discipline), FALSE when the path is public and may take the edge default.
// Challenge/deny/redirect responses render and exit inside; they never return.
function _stattic_enforce_unified_access_rules(array $serving, string $requestHost, string $requestPath, string $requestUri): bool
{
    $rules = _spacefast_policy_rules($serving);
    if ($rules === []) {
        return false;
    }

    // CORS preflights carry no credentials; never challenge them.
    if (_stattic_runtime_request_method() === 'OPTIONS') {
        return false;
    }

    $sessionVersion = _spacefast_policy_session_version($serving);
    $context = _spacefast_access_context($serving, $requestHost, $requestPath);
    $now = time();

    // Fresh per evaluation: the flag is only ever set by a token verification
    // that hit unreadable/corrupt revocation state (shared/jwt.php); a stale
    // true from an earlier code path must never leak into this evaluation.
    _spacefast_revocations_unavailable_flag(false);

    // One pass over the rule list: the matched-index mask feeds the admission
    // pre-check (serve.php), the pathProtected computation, and the
    // first-match enforcement loop below — the per-rule glob/CIDR matching
    // never runs more than once per (host, path) in a request.
    $matched = _spacefast_rule_match_mask($rules, $context, $now);

    // Edge-cache discipline (X-21): the served response is private whenever the
    // path is covered by ANY non-public rule — including the allowed responses,
    // not just challenges. Compute independently of the first-match decision.
    $pathProtected = false;
    foreach ($matched as $index) {
        if (_spacefast_rule_is_non_public($rules[$index])) {
            $pathProtected = true;
            break;
        }
    }
    if (!$pathProtected) {
        foreach ($rules as $rule) {
            if (_spacefast_country_not_in_rule_covers_cache_key($rule, $context, $now)) {
                $pathProtected = true;
                break;
            }
        }
    }

    foreach ($matched as $index) {
        $rule = $rules[$index];
        $result = _spacefast_apply_rule($rule, $serving, $context, $requestUri, $rules, $sessionVersion, $pathProtected);
        if ($result === null) {
            continue;
        }
        return $result;
    }
    return $pathProtected;
}

function _spacefast_rule_expired(array $rule, int $now): bool
{
    return isset($rule['expiresAt']) && is_numeric($rule['expiresAt']) && (int) $rule['expiresAt'] <= $now;
}

// Per-rule "is this a live match for the request" test: an expired rule never
// matches; everything else goes through the unified match predicate. Shared
// by both scans above and by serve.php's admission pre-check
// (_stattic_access_rules_match_request below) — one definition so "does this
// rule apply" can never drift between the admission gate and the enforcer.
function _spacefast_rule_is_live_match(array $rule, array $context, int $now): bool
{
    if (_spacefast_rule_expired($rule, $now)) {
        return false;
    }
    $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
    return _stattic_unified_rule_matches($match, $context);
}

// "Does any live rule match this request at all" — the question serve.php's
// admission gate needs answered before deciding whether unified-access
// enforcement will do any work for this request. Built on the same match mask
// the enforcer uses above, so this can't drift from the real evaluation loop
// and the pre-check's scan is reused by the enforcement pass.
function _stattic_access_rules_match_request(array $rules, array $context, int $now): bool
{
    return _spacefast_rule_match_mask(
        _spacefast_normalize_legacy_country_allow_rules($rules),
        $context,
        $now
    ) !== [];
}

// Per-request memo of the matched-rule index mask for a (host, path). The rule
// list, serving config, and client identity are request-constant, so the mask
// is pure over the context's host|path within one request; the clean-URL and
// post-rewrite re-enforcement passes evaluate a different path and get a fresh
// mask. Malformed rule shapes are a hard invariant error, exactly as the
// enforcement loop treated them.
function _spacefast_rule_match_mask(array $rules, array $context, int $now): array
{
    static $memo = [];
    $key = $context['host'] . '|' . $context['path'];
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $mask = [];
    foreach ($rules as $index => $rule) {
        if (!is_array($rule)) {
            _stattic_render_runtime_invariant_error_lazy('access-policy-malformed', 'Runtime access policy rule is malformed.');
        }
        if (_spacefast_rule_is_live_match($rule, $context, $now)) {
            $mask[] = $index;
        }
    }
    return $memo[$key] = $mask;
}

// A rule is "public" only when it is an anonymous allow (no auth, effect allow).
// Everything else — deny, challenge, or any auth-gated rule — makes its path
// protected for edge-cache purposes.
function _spacefast_rule_is_non_public(array $rule): bool
{
    if (($rule['effect'] ?? null) !== 'allow') {
        return true;
    }
    return isset($rule['auth']) && is_array($rule['auth']);
}

// A countryNotIn-conditioned rule varies by request metadata, so a public 200
// must stay out of shared caches whenever the same cache key could also match
// that rule. Otherwise the 200 can be replayed before the runtime can deny the
// later request. Preserve only URL/routing scope here. Other match predicates
// (country, IP, agent, and header) all vary between requests sharing a cache key
// and therefore must not be required for this cache-safety classification.
function _spacefast_country_not_in_rule_covers_cache_key(array $rule, array $context, int $now): bool
{
    if (_spacefast_rule_expired($rule, $now)) {
        return false;
    }
    $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
    if (!isset($match['countryNotIn'])) {
        return false;
    }

    $cacheKeyMatch = [];
    foreach (['host', 'hostPattern', 'hostTemplate', 'pathPattern', 'channel'] as $field) {
        if (array_key_exists($field, $match)) {
            $cacheKeyMatch[$field] = $match[$field];
        }
    }
    return _stattic_unified_rule_matches($cacheKeyMatch, $context);
}

// Per-request match context. Country/IP read from $_SERVER AFTER the
// trusted-header strip (X-36), so a client cannot spoof CF-IPCountry past a
// country block on an untrusted edge.
function _spacefast_access_context(array $serving, string $requestHost, string $requestPath): array
{
    // Per-request memo: the admission pre-check, the enforcer, and the page
    // overrides all build the identical context (rawurldecode + control-char
    // strip + NFC + segment resolution). $serving and the client identity in
    // $_SERVER are request-constant, so host|path fully keys it.
    static $memo = [];
    $memoKey = $requestHost . '|' . $requestPath;
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    $channel = 'live';
    if (is_string($serving['channel'] ?? null) && $serving['channel'] !== '') {
        $channel = (string) $serving['channel'];
    } elseif (is_array($serving['hostname_channels'] ?? null)) {
        $mapped = $serving['hostname_channels'][strtolower($requestHost)] ?? null;
        if (is_string($mapped) && $mapped !== '') {
            $channel = $mapped;
        }
    }

    return $memo[$memoKey] = [
        'host' => _spacefast_canonicalize_host($requestHost),
        'path' => _spacefast_canonicalize_path($requestPath),
        'channel' => $channel,
        'ip' => _stattic_unified_client_ip(),
        'agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'country' => strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ($_SERVER['GEOIP_COUNTRY_CODE'] ?? ($_SERVER['HTTP_GEOIP_COUNTRY_CODE'] ?? '')))),
        'space_id' => _spacefast_serving_space_id($serving),
        'serving' => $serving,
    ];
}

// One canonicalizer (access-plan §3.4): percent-decode once, normalize
// backslashes, drop control chars, resolve dot-segments, collapse `//`, keep the
// trailing slash. Shared by access matching and (via serve) the router.
function _spacefast_canonicalize_path(string $path): string
{
    if ($path === '') {
        return '/';
    }
    $decoded = rawurldecode($path);
    $decoded = str_replace('\\', '/', $decoded);
    $decoded = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $decoded);
    $decoded = _stattic_nfc_path($decoded);
    if ($decoded === '' || $decoded[0] !== '/') {
        $decoded = '/' . $decoded;
    }
    $hadTrailingSlash = strlen($decoded) > 1 && substr($decoded, -1) === '/';
    $out = [];
    foreach (explode('/', $decoded) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }
    $canonical = '/' . implode('/', $out);
    if ($hadTrailingSlash && $canonical !== '/' && substr($canonical, -1) !== '/') {
        $canonical .= '/';
    }
    return $canonical;
}

function _spacefast_canonicalize_host(string $host): string
{
    return rtrim(strtolower(trim($host)), '.');
}

// AND-combined match. Empty match matches everything in scope.
function _stattic_unified_rule_matches(array $match, array $context): bool
{
    if (isset($match['host']) && _spacefast_canonicalize_host((string) $match['host']) !== $context['host']) {
        return false;
    }
    if (isset($match['hostPattern']) && !_stattic_unified_host_glob_matches((string) $match['hostPattern'], (string) $context['host'])) {
        return false;
    }
    if (isset($match['hostTemplate']) && !_stattic_unified_host_template_matches((string) $match['hostTemplate'], (string) $context['host'])) {
        return false;
    }
    if (isset($match['pathPattern']) && !_stattic_unified_path_glob_matches((string) $match['pathPattern'], (string) $context['path'])) {
        return false;
    }
    if (isset($match['channel']) && (string) $match['channel'] !== (string) $context['channel']) {
        return false;
    }
    if (isset($match['ipCidrs'])) {
        $cidrs = is_array($match['ipCidrs']) ? $match['ipCidrs'] : [];
        if (!_stattic_unified_ip_in_any_cidr((string) $context['ip'], $cidrs)) {
            return false;
        }
    }
    if (isset($match['agent']) && !_stattic_unified_agent_matches((string) $match['agent'], (string) $context['agent'])) {
        return false;
    }
    if (isset($match['country']) && strtoupper((string) $match['country']) !== (string) $context['country']) {
        return false;
    }
    if (isset($match['countryNotIn'])) {
        $allowedCountries = is_array($match['countryNotIn']) ? $match['countryNotIn'] : [];
        $allowedCountries = array_map(static fn ($country): string => strtoupper((string) $country), $allowedCountries);
        if (in_array(strtoupper((string) $context['country']), $allowedCountries, true)) {
            return false;
        }
    }
    if (isset($match['header']) && is_array($match['header'])) {
        $name = (string) ($match['header']['name'] ?? '');
        $expected = (string) ($match['header']['value'] ?? '');
        if ($name === '' || _stattic_unified_request_header($name) !== $expected) {
            return false;
        }
    }
    return true;
}

function _stattic_unified_host_glob_matches(string $pattern, string $host): bool
{
    $regex = '';
    foreach (explode('*', _spacefast_canonicalize_host($pattern)) as $index => $part) {
        if ($index > 0) {
            $regex .= '.*';
        }
        $regex .= preg_quote($part, '#');
    }
    return (bool) preg_match('#^' . $regex . '$#i', _spacefast_canonicalize_host($host));
}

function _stattic_unified_host_template_matches(string $template, string $host): bool
{
    $regex = '';
    $length = strlen($template);
    $index = 0;
    while ($index < $length) {
        $char = $template[$index];
        if ($char === '*') {
            $regex .= '.*';
            $index += 1;
            continue;
        }
        if ($char === ':') {
            $tail = substr($template, $index + 1);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*/', $tail, $match)) {
                return false;
            }
            $regex .= '[^.]+';
            $index += strlen($match[0]) + 1;
            continue;
        }
        $regex .= preg_quote($char, '#');
        $index += 1;
    }
    return (bool) preg_match('#^' . $regex . '$#i', _spacefast_canonicalize_host($host));
}

// Glob path matcher: "/docs/**" (any depth), "/docs/*" (one segment), exact.
function _stattic_unified_path_glob_matches(string $pattern, string $path): bool
{
    if ($pattern === '') {
        return true;
    }
    if (!str_contains($pattern, '*')) {
        return $pattern === $path;
    }
    $regex = '';
    $length = strlen($pattern);
    $i = 0;
    while ($i < $length) {
        $char = $pattern[$i];
        if ($char === '*') {
            if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                $regex .= '.*';
                $i += 2;
                continue;
            }
            $regex .= '[^/]*';
            $i += 1;
            continue;
        }
        $regex .= preg_quote($char, '#');
        $i += 1;
    }
    return preg_match('#^' . $regex . '$#', $path) === 1;
}

function _stattic_unified_agent_matches(string $needle, string $agent): bool
{
    if ($needle === '') {
        return true;
    }
    return stripos($agent, $needle) !== false;
}

function _stattic_unified_request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? '');
}

function _stattic_unified_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return trim($value);
        }
    }
    return '';
}

function _stattic_unified_ip_in_any_cidr(string $ip, array $cidrs): bool
{
    if ($ip === '') {
        return false;
    }
    foreach ($cidrs as $cidr) {
        if (is_string($cidr) && _stattic_unified_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function _stattic_unified_ip_in_cidr(string $ip, string $cidr): bool
{
    if ($cidr === '') {
        return false;
    }
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $bitsRaw] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $bits = (int) $bitsRaw;
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    // Same packed prefix/mask comparison as the egress SSRF policy — one
    // definition of the bit-twiddling for both security surfaces.
    return _stattic_egress_ip_in_cidr($ipBin, $subnetBin, $bits);
}

// ---------------------------------------------------------------------------
// Rule application — ONE satisfaction test (X-32).
// ---------------------------------------------------------------------------

// Reviewer-managed rules bind one exact identity. Older compiled policies may
// still carry a generic `space:` level beside that identity; requiredGrants is
// ANY-OF, so accepting the legacy entry would let another same-space token
// satisfy the reviewer rule. The managedBy field is trusted server-owned
// metadata, and an absent identity fails closed with an empty requirement.
function _spacefast_rule_required_grants(array $rule): array
{
    $auth = isset($rule['auth']) && is_array($rule['auth']) ? $rule['auth'] : [];
    $requiredGrants = is_array($auth['requiredGrants'] ?? null) ? $auth['requiredGrants'] : [];
    if (($rule['managedBy'] ?? null) !== 'cast_reviewer') {
        return $requiredGrants;
    }
    return array_values(array_filter(
        $requiredGrants,
        static fn($grant): bool => is_string($grant) && str_starts_with($grant, 'email:')
    ));
}

// Returns null to keep scanning, true when the request may proceed (with the
// caller marking it private per $pathProtected). Renders + exits on a
// challenge/deny/redirect. `$pathProtected` is passed through so a satisfied
// allow/challenge returns the right cache posture.
function _spacefast_apply_rule(array $rule, array $serving, array $context, string $requestUri, array $rules, int $sessionVersion, bool $pathProtected): ?bool
{
    $effect = $rule['effect'] ?? null;
    if (!in_array($effect, ['allow', 'deny', 'challenge'], true)) {
        _stattic_render_runtime_invariant_error_lazy('access-policy-malformed', 'Runtime access policy effect is unknown.');
    }
    $auth = isset($rule['auth']) && is_array($rule['auth']) ? $rule['auth'] : null;

    // Anonymous firewall rule: the effect applies on match alone. Routed
    // through the pure kernel (X-9) for a single source of truth — grants/
    // identified are irrelevant to this branch (_spacefast_evaluate_rule
    // ignores them when $auth is null), so [] / false stand in.
    if ($auth === null) {
        $anonDecision = _spacefast_evaluate_rule($rule, [], false);
        if ($anonDecision['effect'] === 'allow') {
            return $pathProtected;
        }
        _spacefast_emit_access_event($context, $rule, 'deny', null, []);
        _spacefast_render_deny_page($rule, $context, $serving, null);
    }

    $requiredGrants = _spacefast_rule_required_grants($rule);
    $acquire = is_array($auth['acquire'] ?? null) ? $auth['acquire'] : [];

    // Verified visitor identity (Bearer BEFORE cookie): grants + sub, or null.
    $identity = _spacefast_verify_request_identity($rule, $serving, $context, $rules, $sessionVersion);

    // Revocation state unavailable for this space (corrupt/unreadable
    // revocations.json — jwt.php already failed the token closed and the read
    // layer already journaled the engine-health event, storage.php's
    // _spacefast_load_revocation_state). This rule required a token grant we
    // could not confirm was still valid, so it is a hard deny: never
    // re-challenge (a 401 implies retrying might work — it won't, this is an
    // infra fault, not a bad/expired credential) and never the misleading
    // "signed in as X" copy (the identity itself came back unverified).
    // Checked FIRST — before basic-auth synthesis and every render path — so
    // no other outcome of this rule can preempt the hard stop. This is the ONE
    // enforcement point: it sits directly after the only verification call, so
    // a fault can never survive into a later rule's evaluation.
    if (_spacefast_revocations_unavailable_flag()) {
        _spacefast_emit_access_event($context, $rule, 'deny', 'revocation_state_unavailable', []);
        _spacefast_render_deny_page($rule, $context, $serving, null);
    }

    $grants = $identity !== null ? $identity['grants'] : [];
    $identitySub = (string) ($identity['sub'] ?? '');
    // Capability subjects (pw:/link:/invite:/svc:) are credentials, not a person: when
    // unsatisfied they are re-challenged, never shown the "signed in as X" deny page
    // (which is only for real identities). This fixes multi-wall lockout and the
    // revoked-credential dead-end. Only real identities — user:/email:/sso:/ext:/team:
    // — are "identified" and get the deny page instead of a redirect loop.
    $identityIsCapability = str_starts_with($identitySub, 'pw:')
        || str_starts_with($identitySub, 'link:')
        || str_starts_with($identitySub, 'invite:')
        || str_starts_with($identitySub, 'svc:');
    $identified = $identity !== null && $identitySub !== '' && !$identityIsCapability;

    // Basic-transport password acquire (transport "basic"): stateless per-request
    // satisfaction — valid Basic credentials synthesize `pw:{ruleId}`. Browsers
    // replay Authorization, so no cookie is needed (X-4).
    $ruleId = is_string($rule['id'] ?? null) ? $rule['id'] : '';
    foreach ($acquire as $entry) {
        if (_spacefast_acquire_is_password($entry, 'basic')) {
            $secret = _spacefast_resolve_secret((string) ($entry['ref'] ?? ''), $serving);
            if ($secret !== null && _spacefast_basic_credential_valid($secret, isset($entry['username']) ? (string) $entry['username'] : null)) {
                $grants[] = 'pw:' . $ruleId;
            }
        }
    }

    // ONE satisfaction test (X-32), delegated to the pure kernel so this
    // effect switch and the drift-guarded _spacefast_evaluate_policy can never
    // diverge (access-plan §8/X-9).
    $decision = _spacefast_evaluate_rule($rule, $grants, $identified);
    // §3.2 identity forwarding: forwarding requires the rule to be satisfied by
    // the VERIFIED TOKEN's own grants — never by basic-transport password
    // synthesis (which lands in $grants, not $identity) and never anonymously.
    // Deliberately NOT folded into the pure verdict: it gates forwarding, not
    // allow/deny/challenge.
    $tokenSatisfied = $identity !== null
        && _stattic_unified_grants_intersect(is_array($identity['grants'] ?? null) ? $identity['grants'] : [], $requiredGrants);
    $sub = $identity !== null && is_string($identity['sub'] ?? null) && $identity['sub'] !== '' ? $identity['sub'] : null;

    if (isset($decision['continue'])) {
        // Unsatisfied allow/deny rule: keep scanning later rules.
        return null;
    }

    if ($decision['effect'] === 'allow') {
        // Reached by a satisfied allow rule OR a satisfied challenge rule —
        // both proceed identically.
        if ($tokenSatisfied) {
            _spacefast_access_record_forward_identity($identity);
        }
        _spacefast_emit_access_event($context, $rule, 'allow', null, $grants, $sub);
        return true;
    }

    if ($effect === 'deny') {
        // $decision['effect'] === 'deny' here implies satisfied (a deny rule
        // never reaches this point unsatisfied — that's the 'continue' case
        // above), so this is always the immediate satisfied-deny render.
        _spacefast_emit_access_event($context, $rule, 'deny', null, $grants, $sub);
        _spacefast_render_deny_page($rule, $context, $serving, $identity['sub'] ?? null);
    }

    // Unsatisfied challenge: $decision['effect'] is 'deny' (identified
    // visitor) or 'challenge' (anonymous). A form password POST is the local
    // `pw:` mint.
    $formAcquire = _spacefast_find_password_acquire($acquire, 'form');
    if ($formAcquire !== null && _spacefast_password_form_submitted()) {
        _spacefast_handle_password_mint($formAcquire, $rule, $serving, $context, $requestUri, $sessionVersion);
        // never returns
    }

    _spacefast_emit_access_event($context, $rule, $decision['effect'], null, $grants, $sub);
    if ($decision['effect'] === 'deny') {
        // NEVER re-challenge an identified visitor — the redirect-loop class is
        // structurally dead (access-plan §3.2 deny row).
        _spacefast_render_deny_page($rule, $context, $serving, $identity['sub'] ?? null);
    }
    _spacefast_render_challenge_page($rule, $context, $serving, $requestUri, false);
}

// ---------------------------------------------------------------------------
// Pure verdict kernel (access-plan §8/X-9) — no I/O, no $_SERVER, no exit.
//
// _spacefast_evaluate_rule is the single-rule decision _spacefast_apply_rule
// (above) calls for its verdict before performing any side effect
// (render/emit/forward/mint) — this is the one satisfaction test (X-32),
// extracted so it cannot silently diverge from what a drift guard checks.
//
// _spacefast_evaluate_policy walks a full rule list the same way
// _stattic_enforce_unified_access_rules does (reusing the SAME
// _spacefast_rule_expired / _stattic_unified_rule_matches helpers the real
// enforcer uses). It is deliberately NOT called by the real per-request
// enforcer: the real enforcer resolves $grants fresh for EACH matched rule
// (issuer scoping via _spacefast_verify_request_identity($rule, ...) and
// Basic-transport secrets can differ rule-to-rule), whereas
// _spacefast_evaluate_policy takes one fixed grants/identified pair for the
// whole walk — mirroring the simplification the TS simulator already makes
// (apps/control-plane/src/access/simulate.ts `simulateAccess`, which also
// resolves one grants set up front). This is the pure half of the
// simulate↔runtime parity drift guard
// (apps/control-plane/src/runtime/access-verdict-parity.test.ts).
// ---------------------------------------------------------------------------

// Decision for ONE already-matched, non-expired rule: `continue` (keep
// scanning — an unsatisfied allow/deny rule) or a resolved `{ruleId, effect,
// satisfied}`. Mirrors the effect switch in _spacefast_apply_rule exactly.
function _spacefast_evaluate_rule(array $rule, array $grants, bool $identified): array
{
    $effect = $rule['effect'] ?? null;
    $auth = isset($rule['auth']) && is_array($rule['auth']) ? $rule['auth'] : null;
    $ruleId = is_string($rule['id'] ?? null) ? $rule['id'] : null;

    if ($auth === null) {
        // Anonymous firewall rule: allow passes; deny/challenge is a deny —
        // no credential leg can ever satisfy it.
        return ['ruleId' => $ruleId, 'effect' => $effect === 'allow' ? 'allow' : 'deny', 'satisfied' => true];
    }

    $requiredGrants = _spacefast_rule_required_grants($rule);
    $satisfied = _stattic_unified_grants_intersect($grants, $requiredGrants);

    if ($effect === 'allow') {
        return $satisfied
            ? ['ruleId' => $ruleId, 'effect' => 'allow', 'satisfied' => true]
            : ['continue' => true];
    }
    if ($effect === 'deny') {
        return $satisfied
            ? ['ruleId' => $ruleId, 'effect' => 'deny', 'satisfied' => true]
            : ['continue' => true];
    }
    // challenge — and the default for any $effect other than 'allow'/'deny'.
    // A malformed effect can never actually reach here: the real enforcer
    // (_spacefast_apply_rule) validates $effect against
    // ['allow','deny','challenge'] and renders the invariant-error page
    // before it ever calls this kernel (see the guard right above this
    // function's header comment). This pure kernel is intentionally NOT
    // re-validated here — it stays a total function over $rule, matching
    // simulateAccess's identical "falls through to challenge" default
    // (apps/control-plane/src/access/simulate.ts) so the two drift-guarded
    // halves cannot diverge on an input neither production path can produce.
    if ($satisfied) {
        return ['ruleId' => $ruleId, 'effect' => 'allow', 'satisfied' => true];
    }
    return ['ruleId' => $ruleId, 'effect' => $identified ? 'deny' : 'challenge', 'satisfied' => false];
}

// Full first-match-wins walk over $rules for a FIXED $grants/$identified pair
// (see header comment above for why this differs from the real enforcer's
// per-rule identity resolution). Returns the same shape simulate.ts's verdict
// reduces to: {ruleId, effect, satisfied}. No rule matching → public allow.
function _spacefast_evaluate_policy(array $rules, array $context, array $grants, bool $identified, int $now): array
{
    foreach ($rules as $rule) {
        if (!is_array($rule) || _spacefast_rule_expired($rule, $now)) {
            continue;
        }
        $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
        if (!_stattic_unified_rule_matches($match, $context)) {
            continue;
        }
        $decision = _spacefast_evaluate_rule($rule, $grants, $identified);
        if (isset($decision['continue'])) {
            continue;
        }
        return $decision;
    }
    return ['ruleId' => null, 'effect' => 'allow', 'satisfied' => true];
}

// The one definition of "this acquire entry is a password entry (optionally on
// a specific transport)" — the enforcer, the resolver, and the challenge
// renderer all key on it, so it must not be re-derived per site.
function _spacefast_acquire_is_password(mixed $entry, ?string $transport = null): bool
{
    return is_array($entry)
        && ($entry['type'] ?? null) === 'password'
        && ($transport === null || ($entry['transport'] ?? null) === $transport);
}

// First password acquire entry (optionally on a specific transport), or null.
// Callers that must consider EVERY matching entry — the basic-credential
// synthesis loop and the local `pw:` key resolver — deliberately do not use
// this: a rule can carry several basic credentials, and the resolver keeps
// scanning past a password entry whose secret does not resolve.
function _spacefast_find_password_acquire(array $acquire, ?string $transport = null): ?array
{
    foreach ($acquire as $entry) {
        if (_spacefast_acquire_is_password($entry, $transport)) {
            return $entry;
        }
    }
    return null;
}

function _spacefast_password_form_submitted(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['_pw']);
}

function _spacefast_request_is_fetch(): bool
{
    $mode = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
    if ($mode === 'cors') {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

// Verifies the visitor token (Bearer first, then the `spacefast_access` cookie)
// against the rule's issuer keys plus the space-local `pw:` key. Returns
// ['sub', 'grants', 'exp', 'token'] (the raw verified token rides along for the
// §3.2 identity-forwarding seam) or null. No prompt emitted.
function _spacefast_verify_request_identity(array $rule, array $serving, array $context, array $rules, int $sessionVersion): ?array
{
    $token = _spacefast_visitor_token_from_request();
    if ($token === '') {
        return null;
    }
    $auth = is_array($rule['auth'] ?? null) ? $rule['auth'] : [];
    $issuers = is_array($auth['issuers'] ?? null) ? $auth['issuers'] : [];
    $session = _spacefast_access_session_verify($token, $serving, $context['host'], $sessionVersion, $issuers);
    if ($session !== null) {
        return $session;
    }
    $verified = _spacefast_visitor_verify($token, _spacefast_visitor_verify_options($serving, $context['host'], $issuers, $rules));
    if ($verified === null) {
        return null;
    }
    if (!_spacefast_direct_request_credential_allowed($verified)) {
        return null;
    }
    $verified['token'] = $token;
    return $verified;
}

const SPACEFAST_ACCESS_SESSION_PREFIX = 'sfv1_';

function _spacefast_verified_claim_has_jti(array $verified): bool
{
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    return isset($claims['jti']);
}

function _spacefast_verified_uses_local_pw(array $verified): bool
{
    $fingerprint = is_string($verified['issuerFingerprint'] ?? null) ? (string) $verified['issuerFingerprint'] : '';
    return str_starts_with($fingerprint, 'local-pw:');
}

function _spacefast_direct_request_credential_allowed(array $verified): bool
{
    if (_spacefast_verified_claim_has_jti($verified)) {
        return false;
    }
    return _spacefast_verified_uses_local_pw($verified) || _spacefast_share_credential_allowed($verified);
}

function _spacefast_access_session_dir(string $privateRoot): string
{
    return $privateRoot . '/runtime/access-sessions';
}

function _spacefast_access_session_path(string $privateRoot, string $sessionId): string
{
    return _spacefast_access_session_dir($privateRoot) . '/' . hash('sha256', $sessionId) . '.json';
}

function _spacefast_access_session_create(array $verified, string $host, int $sessionVersion): ?string
{
    $privateRoot = _spacefast_access_private_root();
    if ($privateRoot === '') {
        return null;
    }
    $sub = is_string($verified['sub'] ?? null) ? $verified['sub'] : '';
    $grants = [];
    foreach (is_array($verified['grants'] ?? null) ? $verified['grants'] : [] as $grant) {
        if (is_string($grant) && $grant !== '') {
            $grants[] = $grant;
        }
    }
    $exp = (int) ($verified['exp'] ?? 0);
    $issuerFingerprint = is_string($verified['issuerFingerprint'] ?? null) ? (string) $verified['issuerFingerprint'] : '';
    if ($sub === '' || $grants === [] || $exp <= time() || $issuerFingerprint === '') {
        return null;
    }
    $sessionId = bin2hex(random_bytes(32));
    $path = _spacefast_access_session_path($privateRoot, $sessionId);
    _stattic_runtime_write_json_atomic($path, [
        'sub' => $sub,
        'grants' => $grants,
        'exp' => $exp,
        'host' => strtolower($host),
        'sv' => $sessionVersion,
        'issuerFingerprint' => $issuerFingerprint,
        'createdAt' => time(),
    ]);
    return SPACEFAST_ACCESS_SESSION_PREFIX . $sessionId;
}

function _spacefast_access_session_verify(string $credential, array $serving, string $host, int $sessionVersion, array $issuers): ?array
{
    if (!str_starts_with($credential, SPACEFAST_ACCESS_SESSION_PREFIX)) {
        return null;
    }
    $sessionId = substr($credential, strlen(SPACEFAST_ACCESS_SESSION_PREFIX));
    if (preg_match('/\A[a-f0-9]{64}\z/', $sessionId) !== 1) {
        return null;
    }
    $privateRoot = _spacefast_access_private_root();
    if ($privateRoot === '') {
        return null;
    }
    $path = _spacefast_access_session_path($privateRoot, $sessionId);
    // Per-request memo: multi-rule policies verify the same session cookie once
    // per matched rule, and the record file does not change under a request.
    static $records = [];
    if (!array_key_exists($path, $records)) {
        $records[$path] = _stattic_runtime_read_json($path);
    }
    $record = $records[$path];
    if (!is_array($record)) {
        return null;
    }
    $exp = (int) ($record['exp'] ?? 0);
    if ($exp < time() - 300) {
        @unlink($path);
        return null;
    }
    if ((int) ($record['sv'] ?? -1) !== $sessionVersion) {
        return null;
    }
    if (strtolower((string) ($record['host'] ?? '')) !== strtolower($host)) {
        return null;
    }
    $issuerFingerprint = is_string($record['issuerFingerprint'] ?? null) ? (string) $record['issuerFingerprint'] : '';
    if ($issuerFingerprint === '') {
        return null;
    }
    $issuerStillTrusted = false;
    foreach ($issuers as $issuer) {
        if (is_array($issuer) && _spacefast_jwt_issuer_fingerprint($issuer) === $issuerFingerprint) {
            $issuerStillTrusted = true;
            break;
        }
    }
    if (!$issuerStillTrusted) {
        return null;
    }
    $revocations = _spacefast_visitor_revocations([
        'privateRoot' => $privateRoot,
        'spaceId' => _spacefast_serving_space_id($serving),
    ]);
    if (($revocations['available'] ?? true) === false) {
        _spacefast_revocations_unavailable_flag(true);
        return null;
    }
    $sub = is_string($record['sub'] ?? null) ? $record['sub'] : '';
    if ($sub === '' || isset($revocations['subs'][$sub])) {
        return null;
    }
    $grants = [];
    foreach (is_array($record['grants'] ?? null) ? $record['grants'] : [] as $grant) {
        if (is_string($grant) && $grant !== '' && !isset($revocations['grants'][$grant])) {
            $grants[] = $grant;
        }
    }
    if ($grants === []) {
        return null;
    }
    return [
        'sub' => $sub,
        'grants' => array_values($grants),
        'exp' => $exp,
        'claims' => ['sub' => $sub, 'grants' => array_values($grants), 'exp' => $exp, 'sv' => $sessionVersion],
        'token' => '',
    ];
}

// Identity-forwarding seam (access-plan §3.2, "Identity forwarding (SSO
// proxy)"): records the VERIFIED identity that satisfied the matched rule so
// the proxy boundary (runtime/proxy.php) can forward
// Spacefast-Access-Jwt/-Sub/-Grants to the external origin. Written only for
// token-satisfied allows; the inbound copies of those exact headers were
// stripped unconditionally before enforcement (context.php trusted-header
// contract), so the only possible value here is runtime-verified.
function _spacefast_access_record_forward_identity(array $identity): void
{
    $grants = [];
    foreach (is_array($identity['grants'] ?? null) ? $identity['grants'] : [] as $grant) {
        if (is_string($grant) && $grant !== '') {
            $grants[] = $grant;
        }
    }
    $GLOBALS['SPACEFAST_ACCESS_FORWARD_IDENTITY'] = [
        'token' => is_string($identity['token'] ?? null) ? $identity['token'] : '',
        'sub' => is_string($identity['sub'] ?? null) ? $identity['sub'] : '',
        'grants' => $grants,
    ];
}

function _spacefast_visitor_token_from_request(): string
{
    $bearer = _stattic_runtime_bearer_token_from_request();
    if (is_string($bearer) && $bearer !== '') {
        return $bearer;
    }
    $cookie = $_COOKIE[SPACEFAST_ACCESS_COOKIE] ?? '';
    return is_string($cookie) ? $cookie : '';
}

// Builds the local `pw:` key resolver: rule id -> derived HS256 key. The key
// derives from the rule's password-acquire secret + sessionVersion, so a bad
// rule id or rotated secret yields a key that cannot verify the signature.
function _spacefast_local_pw_resolver(array $rules, array $serving, int $sessionVersion): callable
{
    return function (string $ruleId) use ($rules, $serving, $sessionVersion): ?string {
        foreach ($rules as $rule) {
            if (!is_array($rule) || (string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $auth = is_array($rule['auth'] ?? null) ? $rule['auth'] : [];
            $acquire = is_array($auth['acquire'] ?? null) ? $auth['acquire'] : [];
            foreach ($acquire as $entry) {
                if (_spacefast_acquire_is_password($entry)) {
                    $secret = _spacefast_resolve_secret((string) ($entry['ref'] ?? ''), $serving);
                    if ($secret !== null) {
                        return _spacefast_local_pw_key($ruleId, $secret, $sessionVersion);
                    }
                }
            }
            return null;
        }
        return null;
    };
}

// Glob-aware intersection (access-rules.php:604-632 semantics preserved
// verbatim): a visitor passes when ANY verified concrete grant matches ANY
// requiredGrant. requiredGrants may carry `*` wildcards; verified grants are
// concrete.
function _stattic_unified_grants_intersect(array $grants, array $requiredGrants): bool
{
    foreach ($requiredGrants as $required) {
        if (!is_string($required) || $required === '') {
            continue;
        }
        foreach ($grants as $grant) {
            if (!is_string($grant) || $grant === '') {
                continue;
            }
            if (_stattic_unified_grant_glob_matches($required, $grant)) {
                return true;
            }
        }
    }
    return false;
}

function _stattic_unified_grant_glob_matches(string $pattern, string $grant): bool
{
    if (!str_contains($pattern, '*')) {
        return $pattern === $grant;
    }
    $quoted = preg_quote($pattern, '#');
    $regex = str_replace('\\*', '.*', $quoted);
    return preg_match('#^' . $regex . '$#', $grant) === 1;
}

// ---------------------------------------------------------------------------
// Password (secret resolution, basic credential, local mint).
// ---------------------------------------------------------------------------

function _spacefast_resolve_secret(string $ref, array $serving): ?string
{
    if ($ref === '') {
        return null;
    }
    $name = str_starts_with($ref, 'secret:') ? substr($ref, strlen('secret:')) : $ref;
    if ($name === '') {
        return null;
    }
    $secrets = is_array($serving['secrets'] ?? null) ? $serving['secrets'] : [];
    if (is_string($secrets[$name] ?? null) && $secrets[$name] !== '') {
        return (string) $secrets[$name];
    }
    $runtimeConfig = is_array($serving['runtime_config'] ?? null) ? $serving['runtime_config'] : [];
    $configSecrets = is_array($runtimeConfig['secrets'] ?? null) ? $runtimeConfig['secrets'] : [];
    if (is_string($configSecrets[$name] ?? null) && $configSecrets[$name] !== '') {
        return (string) $configSecrets[$name];
    }
    $fromEnv = _stattic_config_value('SPACEFAST_SECRET_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? ''));
    return $fromEnv !== '' ? $fromEnv : null;
}

function _spacefast_secret_equals(string $secret, string $candidate): bool
{
    if ($secret === '') {
        return false;
    }
    if (str_starts_with($secret, '$')) {
        return password_verify($candidate, $secret);
    }
    return hash_equals($secret, $candidate);
}

function _spacefast_basic_credential_valid(string $secret, ?string $username): bool
{
    $providedUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $providedPass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($username !== null && !hash_equals($username, $providedUser)) {
        return false;
    }
    return _spacefast_secret_equals($secret, $providedPass);
}

// Local `pw:` mint (X-32): a valid form POST mints the standard visitor token
// LOCALLY (HS256, space-local key) into the one `spacefast_access` cookie, then
// 303s back. A wrong password engages the brute-force throttle and re-renders
// the challenge with an error. Always terminates the request.
function _spacefast_handle_password_mint(array $acquire, array $rule, array $serving, array $context, string $requestUri, int $sessionVersion): void
{
    $ruleId = is_string($rule['id'] ?? null) ? $rule['id'] : '';
    $ip = (string) $context['ip'];
    $spaceId = (string) ($context['space_id'] ?? '');

    $retryAfter = _spacefast_throttle_retry_after($spaceId, $ip, $ruleId);
    if ($retryAfter > 0) {
        _spacefast_render_throttled($retryAfter);
    }

    $secret = _spacefast_resolve_secret((string) ($acquire['ref'] ?? ''), $serving);
    if ($secret === null) {
        _stattic_render_runtime_invariant_error_lazy('access-policy-malformed', 'Runtime access password secret is unavailable.');
    }
    $candidate = (string) ($_POST['_pw'] ?? '');
    if (!_spacefast_secret_equals($secret, $candidate)) {
        _spacefast_throttle_record_failure($spaceId, $ip, $ruleId);
        _spacefast_emit_access_event($context, $rule, 'challenge', 'password_invalid', []);
        _spacefast_render_challenge_page($rule, $context, $serving, $requestUri, true);
    }

    _spacefast_throttle_clear($spaceId, $ip, $ruleId);
    $key = _spacefast_local_pw_key($ruleId, $secret, $sessionVersion);
    $token = _spacefast_mint_local_pw_token($ruleId, $key, (string) $context['host'], $sessionVersion, 86400);
    _spacefast_set_cookie(SPACEFAST_ACCESS_COOKIE, $token, 86400);
    _spacefast_emit_access_event($context, $rule, 'allow', 'password_ok', ['pw:' . $ruleId]);
    header('Cache-Control: no-store', true);
    header('Location: ' . $requestUri, true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Brute-force throttle (access-plan §3.4). Per space × IP × rule, exponential
// backoff. State lives in the shared admission backend (shared/admission.php):
// apcu when _stattic_admission_backend() reports the cache is really alive for
// this SAPI, else a JSON counter under the space's private root. One backend
// answer per process for every runtime counter, and one space's failures never
// share a counter with another's.
// ---------------------------------------------------------------------------

const SPACEFAST_THROTTLE_THRESHOLD = 5;
const SPACEFAST_THROTTLE_WINDOW = 900;

function _spacefast_throttle_state_id(string $spaceId, string $ip, string $ruleId): string
{
    return 'throttle:' . $spaceId . ':' . hash('sha256', $ip . '|' . $ruleId);
}

// File-lane path under the private root, or '' when no private root is staged
// (CLI helpers, unit fixtures) — the file lane then no-ops instead of writing
// outside private storage.
function _spacefast_throttle_state_path(string $stateId): string
{
    $privateRoot = _spacefast_access_private_root();
    return $privateRoot === ''
        ? ''
        : $privateRoot . '/runtime/throttle/' . _stattic_admission_sanitize_key($stateId) . '.json';
}

function _spacefast_throttle_read(string $stateId): array
{
    $empty = ['count' => 0, 'first' => 0, 'blocked_until' => 0];
    if (_stattic_admission_backend() === 'apcu') {
        $value = apcu_fetch(_stattic_admission_key($stateId));
        return is_array($value) ? $value : $empty;
    }
    $path = _spacefast_throttle_state_path($stateId);
    $state = $path === '' ? null : _stattic_runtime_read_json($path);
    return is_array($state) ? $state : $empty;
}

function _spacefast_throttle_write(string $stateId, array $state): void
{
    if (_stattic_admission_backend() === 'apcu') {
        apcu_store(_stattic_admission_key($stateId), $state, SPACEFAST_THROTTLE_WINDOW);
        return;
    }
    $path = _spacefast_throttle_state_path($stateId);
    if ($path !== '') {
        _stattic_runtime_write_json_atomic($path, $state);
    }
}

function _spacefast_throttle_retry_after(string $spaceId, string $ip, string $ruleId): int
{
    if ($ip === '') {
        return 0;
    }
    $state = _spacefast_throttle_read(_spacefast_throttle_state_id($spaceId, $ip, $ruleId));
    $blockedUntil = (int) ($state['blocked_until'] ?? 0);
    return $blockedUntil > time() ? $blockedUntil - time() : 0;
}

function _spacefast_throttle_record_failure(string $spaceId, string $ip, string $ruleId): void
{
    if ($ip === '') {
        return;
    }
    $now = time();
    $stateId = _spacefast_throttle_state_id($spaceId, $ip, $ruleId);
    $state = _spacefast_throttle_read($stateId);
    $first = (int) ($state['first'] ?? 0);
    if ($first === 0 || $now - $first > SPACEFAST_THROTTLE_WINDOW) {
        $state = ['count' => 0, 'first' => $now, 'blocked_until' => 0];
    }
    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    if ($state['count'] >= SPACEFAST_THROTTLE_THRESHOLD) {
        $over = $state['count'] - SPACEFAST_THROTTLE_THRESHOLD;
        $backoff = min(3600, (int) pow(2, min(10, $over)));
        $state['blocked_until'] = $now + max(1, $backoff);
    }
    _spacefast_throttle_write($stateId, $state);
}

function _spacefast_throttle_clear(string $spaceId, string $ip, string $ruleId): void
{
    if ($ip === '') {
        return;
    }
    $stateId = _spacefast_throttle_state_id($spaceId, $ip, $ruleId);
    if (_stattic_admission_backend() === 'apcu') {
        apcu_delete(_stattic_admission_key($stateId));
        return;
    }
    $path = _spacefast_throttle_state_path($stateId);
    if ($path !== '') {
        @unlink($path);
    }
}

function _spacefast_render_throttled(int $retryAfter): void
{
    _stattic_serve_page('rate-limited', [
        'status' => 429,
        'headers' => [
            'Cache-Control' => 'no-store',
            'Retry-After' => (string) max(1, $retryAfter),
            'X-Robots-Tag' => 'noindex',
        ],
        'message' => 'Too many password attempts. Try again later.',
        'code' => 'password_rate_limited',
    ]);
}

// ---------------------------------------------------------------------------
// Access events (X-37). Emitted on every enforced decision into the
// runtime-local NDJSON journal under the private root; the cloud pulls it
// through the management `access_events` action (§5.6b). Zero request-path
// network calls; every write is best-effort and every failure is swallowed —
// the journal can never break serving.
// ---------------------------------------------------------------------------

// accessEventSchema-shaped record (packages/common/src/contracts/access.ts).
// Grants are NEVER journaled — only grantsHash (sha256 of the sorted verified
// grant list, newline-joined).
function _spacefast_emit_access_event(array $context, array $rule, string $effect, ?string $reasonCode, array $grants, ?string $sub = null): void
{
    $sorted = [];
    foreach ($grants as $grant) {
        if (is_string($grant) && $grant !== '') {
            $sorted[] = $grant;
        }
    }
    sort($sorted);
    $record = [
        'sub' => is_string($sub) && $sub !== '' ? $sub : null,
        'grantsHash' => $sorted === [] ? null : hash('sha256', implode("\n", $sorted)),
        'ruleId' => is_string($rule['id'] ?? null) && $rule['id'] !== '' ? $rule['id'] : null,
        'effect' => $effect,
        'reasonCode' => $reasonCode ?? (is_string($rule['reasonCode'] ?? null) ? $rule['reasonCode'] : null),
        'host' => (string) ($context['host'] ?? ''),
        'path' => (string) ($context['path'] ?? ''),
        'ts' => time(),
    ];
    // Record captured eagerly, written post-response: the visitor never waits
    // on the event append (or its day-file prune).
    _spacefast_defer(static function () use ($record): void {
        _spacefast_access_event_journal_append($record);
    });
}

// Appends one event line to today's day file. Rotation is the filename
// (UTC YYYY-MM-DD); the byte cap skips writes past the limit and counts each
// skipped event in the `.dropped` sidecar so the puller can report loss.
// The private-root handle arrives through a request global staged by
// _stattic_serve_request — absent (CLI helpers, unit fixtures) the emitter is
// a silent no-op.
function _spacefast_access_event_journal_append(array $record): void
{
    $root = _spacefast_access_private_root();
    if ($root === '') {
        return;
    }
    $dir = $root . '/' . SPACEFAST_ACCESS_EVENTS_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $path = $dir . '/' . gmdate('Y-m-d') . '.ndjson';
    clearstatcache(false, $path);
    $size = @filesize($path);
    if (is_int($size) && $size >= SPACEFAST_ACCESS_EVENT_FILE_MAX_BYTES) {
        _spacefast_access_event_count_dropped($path . '.dropped');
    } else {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    _spacefast_access_event_prune($dir);
}

// Dropped-events counter sidecar (`{day}.ndjson.dropped`): a single integer,
// incremented under LOCK_EX. A sidecar instead of an in-file counter line so
// the count can grow without growing the capped day file.
function _spacefast_access_event_count_dropped(string $counterPath): void
{
    $handle = @fopen($counterPath, 'c+');
    if ($handle === false) {
        return;
    }
    if (@flock($handle, LOCK_EX)) {
        $count = (int) trim((string) @stream_get_contents($handle));
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, (string) ($count + 1) . "\n");
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
}

// Opportunistic retention: unlink day files (and their sidecars) older than the
// window. Day-rotated files only need pruning about once a day per box, so the
// scandir is gated behind a `.pruned` marker (one filemtime stat per request
// instead of a directory scan on the serving path — the hot path's no-dir-scan
// contract). Concurrent prunes after a stale marker are harmless.
function _spacefast_access_event_prune(string $dir): void
{
    static $pruned = false;
    if ($pruned) {
        return;
    }
    $pruned = true;
    if (!_spacefast_marker_throttle($dir . '/.pruned', 21600)) {
        return;
    }
    $cutoff = gmdate('Y-m-d', time() - SPACEFAST_ACCESS_EVENT_RETENTION_DAYS * 86400);
    foreach (@scandir($dir) ?: [] as $entry) {
        if (
            preg_match('/^(\d{4}-\d{2}-\d{2})\.ndjson(\.dropped)?$/', (string) $entry, $matches) === 1
            && $matches[1] < $cutoff
        ) {
            @unlink($dir . '/' . $entry);
        }
    }
}

// ---------------------------------------------------------------------------
// Pages presentation. Enforcement owns decisions and protocol; publish owns
// HTML. These functions only choose the catalog page and response metadata.
// ---------------------------------------------------------------------------

// The runtime-owned challenge fragment: unstyled semantic HTML with sf-
// classes — one block per acquire entry, password forms above login links.
// The runtime owns the field name (`_pw`), the post target (the current URL),
// and the return contract, so customization can't fork the protocol; a
// compiled Pages artifact only styles it.
function _spacefast_challenge_fragment(array $acquire, string $returnPath, bool $invalidPassword): string
{
    $html = '';
    $hasPasswordForm = false;
    foreach ($acquire as $entry) {
        if (_spacefast_acquire_is_password($entry, 'form')) {
            $hasPasswordForm = true;
            $html .= '<section class="sf-block sf-password">';
            $html .= '<form method="post" action="">';
            $html .= '<label for="sf-pw">Password</label>';
            $html .= '<input id="sf-pw" class="sf-pw" type="password" name="_pw" autocomplete="current-password" required';
            if ($invalidPassword) {
                $html .= ' autofocus aria-invalid="true" aria-describedby="sf-pw-error"';
            }
            $html .= '>';
            $html .= '<button type="submit">Continue</button>';
            if ($invalidPassword) {
                $html .= '<p class="sf-error" id="sf-pw-error" role="alert">That password did not work. Try again.</p>';
            }
            $html .= '</form></section>';
        }
    }
    $loginMethod = 0;
    foreach ($acquire as $entry) {
        if (is_array($entry) && ($entry['type'] ?? null) === 'login') {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $label = is_string($entry['label'] ?? null) && $entry['label'] !== '' ? $entry['label'] : 'Continue with Spacefast';
            $href = _stattic_html_escape(SPACEFAST_ACCESS_LOGIN_PATH . '?method=' . $loginMethod . '&return=' . rawurlencode($returnPath));
            $html .= '<section class="sf-block sf-login-block"><a class="sf-login" href="' . $href . '">' . _stattic_html_escape($label) . '</a></section>';
            $loginMethod += 1;
        }
    }
    if (!$hasPasswordForm && $acquire === []) {
        $html .= '<p class="sf-copy">Access to this resource requires an authorized token.</p>';
    }
    return $html;
}

// The runtime-owned deny fragment: the rule message plus the identified
// visitor, semantic HTML with sf- classes.
function _spacefast_deny_fragment(string $message, ?string $sub): string
{
    $html = '<p class="sf-copy">' . _stattic_html_escape($message) . '</p>';
    if (is_string($sub) && $sub !== '') {
        $html .= '<p class="sf-copy">Signed in as ' . _stattic_html_escape($sub) . '.</p>';
    }
    return $html;
}

function _spacefast_render_challenge_page(array $rule, array $context, array $serving, string $requestUri, bool $invalidPassword): void
{
    $auth = is_array($rule['auth'] ?? null) ? $rule['auth'] : [];
    $acquire = is_array($auth['acquire'] ?? null) ? $auth['acquire'] : [];
    $pageId = _spacefast_find_password_acquire($acquire) !== null ? 'password' : 'login';
    $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'];
    if (_spacefast_find_password_acquire($acquire, 'basic') !== null) {
        $headers['WWW-Authenticate'] = 'Basic realm="Spacefast"';
    }
    _stattic_serve_page($pageId, [
        'status' => 401,
        'headers' => $headers,
        'message' => $invalidPassword ? 'That password did not work.' : 'Authentication required.',
        'code' => $pageId === 'password' ? 'password_required' : 'access_session_expired',
        'customizable' => true,
        'serving' => $serving,
        'request_path' => (string) parse_url($requestUri, PHP_URL_PATH),
        'fragment' => _spacefast_challenge_fragment($acquire, _spacefast_plain_return_path($requestUri), $invalidPassword),
    ]);
}

function _spacefast_render_deny_page(array $rule, array $context, ?array $serving, ?string $sub): void
{
    $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'];
    $reason = is_string($rule['reasonCode'] ?? null) ? $rule['reasonCode'] : '';
    if ($reason !== '') {
        $headers['X-Spacefast-Reason'] = $reason;
    }
    $message = is_string($rule['message'] ?? null) && $rule['message'] !== ''
        ? $rule['message']
        : 'Access to this space is restricted.';
    _stattic_serve_page('denied', [
        'status' => 403,
        'headers' => $headers,
        'message' => $message,
        'code' => 'access_denied',
        'customizable' => true,
        'serving' => is_array($serving) ? $serving : [],
        'request_path' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        'fragment' => _spacefast_deny_fragment($message, $sub),
    ]);
}

function _spacefast_render_json_unauthenticated(string $code, bool $noindex = true): void
{
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($noindex) {
        header('X-Robots-Tag: noindex');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode(['error' => ['code' => $code]], JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit;
}

// ---------------------------------------------------------------------------
// Same-origin return-path safety (kept from _stattic_unified_safe_return_path,
// but plain paths — no base64).
// ---------------------------------------------------------------------------

// Sanitizes a return target to a plain same-origin path (leading `/`, no `//`,
// no scheme, no control/backslash). Returns null when unsafe.
function _spacefast_safe_return_path(string $value): ?string
{
    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
        return null;
    }
    for ($i = 0, $length = strlen($value); $i < $length; $i += 1) {
        $ord = ord($value[$i]);
        if ($value[$i] === '\\' || $ord < 0x20 || $ord === 0x7f) {
            return null;
        }
    }
    return $value;
}

// The current request URI reduced to a plain same-origin path for a login
// `return=` (path + query, no host/scheme).
function _spacefast_plain_return_path(string $requestUri): string
{
    $path = parse_url($requestUri, PHP_URL_PATH);
    $query = parse_url($requestUri, PHP_URL_QUERY);
    $path = is_string($path) && $path !== '' ? $path : '/';
    return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

// ---------------------------------------------------------------------------
// First-party access surfaces (callback, share trade, login, logout, me, token).
// ---------------------------------------------------------------------------

// Collects every issuer key referenced by the policy — used to verify handoff
// and cookie tokens outside a single matched rule (callback / me / token).
function _spacefast_policy_issuers(array $serving): array
{
    $policy = is_array($serving['policy'] ?? null) ? $serving['policy'] : [];
    $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
    $issuers = [];
    foreach (is_array($policy['issuers'] ?? null) ? $policy['issuers'] : [] as $issuer) {
        if (is_array($issuer)) {
            $issuers[] = $issuer;
        }
    }
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $auth = is_array($rule['auth'] ?? null) ? $rule['auth'] : [];
        foreach (is_array($auth['issuers'] ?? null) ? $auth['issuers'] : [] as $issuer) {
            if (is_array($issuer)) {
                $issuers[] = $issuer;
            }
        }
    }
    return $issuers;
}

function _spacefast_policy_session_version(array $serving): int
{
    $policy = is_array($serving['policy'] ?? null) ? $serving['policy'] : [];
    return (int) ($policy['sessionVersion'] ?? 0);
}

function _spacefast_policy_rules(array $serving): array
{
    $policy = is_array($serving['policy'] ?? null) ? $serving['policy'] : [];
    $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
    return _spacefast_normalize_legacy_country_allow_rules($rules);
}

function _spacefast_serving_space_id(array $serving): string
{
    return is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
}

// THE _spacefast_visitor_verify options contract (issuers/host/sessionVersion/
// localPwResolver/privateRoot/spaceId), built once for every verify site.
// $issuers/$rules default to the policy-wide sets; _spacefast_verify_request_identity
// passes its rule-scoped $issuers and $rules through to keep the per-rule
// issuer-scoping seam explicit. $extra layers site-specific keys on top
// (callback: requireJti/iatMaxAge).
function _spacefast_visitor_verify_options(array $serving, string $host, ?array $issuers = null, ?array $rules = null, array $extra = []): array
{
    $sessionVersion = _spacefast_policy_session_version($serving);
    return array_merge([
        'issuers' => $issuers ?? _spacefast_policy_issuers($serving),
        'host' => $host,
        'sessionVersion' => $sessionVersion,
        'localPwResolver' => _spacefast_local_pw_resolver($rules ?? _spacefast_policy_rules($serving), $serving, $sessionVersion),
        'privateRoot' => _spacefast_access_private_root(),
        'spaceId' => _spacefast_serving_space_id($serving),
    ], $extra);
}

function _spacefast_iso_country_code_set(): array
{
    static $codes = null;
    if ($codes === null) {
        $codes = array_fill_keys(explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'), true);
    }
    return $codes;
}

// Route configs deployed before countryNotIn represented an allowlist as one
// exact-country firewall rule for every ISO code outside the allowlist. Decode
// that exact legacy shape in memory so already-deployed routes fail closed for
// missing, provider-special, malformed, and future country values.
function _spacefast_normalize_legacy_country_allow_rules(array $rules): array
{
    // Single-slot request memo: the admission pre-check and the enforcement
    // pass both normalize the same request-constant policy rule list, and the
    // input check is O(1) for the repeat calls (array === short-circuits on
    // the shared copy-on-write hashtable). Input-keyed rather than keyless so
    // multi-policy processes (unit tests) stay correct.
    static $memoInput = null;
    static $memoResult = null;
    if ($memoInput !== $rules) {
        $memoInput = $rules;
        $memoResult = _spacefast_normalize_legacy_country_allow_rules_walk($rules);
    }
    return $memoResult;
}

function _spacefast_normalize_legacy_country_allow_rules_walk(array $rules): array
{
    $countryIndexes = [];
    $legacyRows = [];
    $isoCodes = _spacefast_iso_country_code_set();
    foreach ($rules as $index => $rule) {
        if (!is_array($rule) || ($rule['managedBy'] ?? null) !== 'firewall') {
            continue;
        }
        $id = is_string($rule['id'] ?? null) ? (string) $rule['id'] : '';
        if (!str_starts_with($id, 'firewall-country:')) {
            continue;
        }
        $countryIndexes[] = $index;
        if ($id === 'firewall-country:allow') {
            return $rules;
        }
        $prefix = 'firewall-country:allow:';
        $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];
        $code = str_starts_with($id, $prefix) ? strtoupper(substr($id, strlen($prefix))) : '';
        if (
            !isset($isoCodes[$code])
            || array_keys($match) !== ['country']
            || strtoupper((string) ($match['country'] ?? '')) !== $code
        ) {
            return $rules;
        }
        $legacyRows[] = ['index' => $index, 'code' => $code, 'rule' => $rule];
    }
    if ($countryIndexes === [] || count($countryIndexes) !== count($legacyRows)) {
        return $rules;
    }

    $first = $legacyRows[0]['rule'];
    foreach ($legacyRows as $row) {
        $rule = $row['rule'];
        if (
            ($rule['effect'] ?? null) !== ($first['effect'] ?? null)
            || ($rule['auth'] ?? null) != ($first['auth'] ?? null)
            || ($rule['expiresAt'] ?? null) !== ($first['expiresAt'] ?? null)
            || ($rule['reasonCode'] ?? null) !== ($first['reasonCode'] ?? null)
        ) {
            return $rules;
        }
    }

    $blocked = array_fill_keys(array_column($legacyRows, 'code'), true);
    $allowed = array_values(array_diff(array_keys($isoCodes), array_keys($blocked)));
    $replacement = $first;
    $replacement['id'] = 'firewall-country:allow';
    $replacement['match'] = $allowed === [] ? [] : ['countryNotIn' => $allowed];
    $replacement['message'] = $allowed === []
        ? 'No countries are allowed to access this space.'
        : 'Only listed countries may access this space.';

    $firstIndex = $legacyRows[0]['index'];
    $legacyIndexes = array_fill_keys(array_column($legacyRows, 'index'), true);
    $normalized = [];
    foreach ($rules as $index => $rule) {
        if ($index === $firstIndex) {
            $normalized[] = $replacement;
        } elseif (!isset($legacyIndexes[$index])) {
            $normalized[] = $rule;
        }
    }
    return $normalized;
}

// Adds the two request-derived parameters to a compiler-hydrated login URL.
// The compiler owns static identity intent (`space`, `connection`, `sv`); the
// serving runtime is the sole authority for the browser's actual host and
// same-origin return path. Existing host/return values are replaced, never
// trusted or duplicated, so old route configs cannot redirect an alias or
// custom-domain visitor through a different hostname.
function _spacefast_access_login_handoff_url(string $url, string $requestHost, string $returnPath): ?string
{
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) {
        return null;
    }
    $parsed = parse_url($url);
    $scheme = is_array($parsed) ? strtolower((string) ($parsed['scheme'] ?? '')) : '';
    $targetHost = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
    $loopbackHttp = $scheme === 'http' && in_array($targetHost, ['localhost', '127.0.0.1', '::1'], true);
    if (
        !is_array($parsed)
        || !isset($parsed['scheme'], $parsed['host'])
        || ($scheme !== 'https' && !$loopbackHttp)
        || $targetHost === ''
        || isset($parsed['user'])
        || isset($parsed['pass'])
        || isset($parsed['fragment'])
    ) {
        return null;
    }

    $host = _spacefast_canonicalize_host(_stattic_normalize_hostname($requestHost));
    if ($host === '') {
        return null;
    }

    [$base, $rawQuery] = array_pad(explode('?', $url, 2), 2, null);
    $kept = [];
    foreach ($rawQuery === null || $rawQuery === '' ? [] : explode('&', $rawQuery) as $pair) {
        $rawKey = explode('=', $pair, 2)[0];
        $key = rawurldecode(str_replace('+', ' ', $rawKey));
        if ($key === 'host' || $key === 'return') {
            continue;
        }
        $kept[] = $pair;
    }
    $kept[] = 'host=' . rawurlencode($host);
    $kept[] = 'return=' . rawurlencode($returnPath);

    return $base . '?' . implode('&', $kept);
}

// Stable same-host endpoint emitted by <sf-login>. It resolves the current
// matching rule's compiler-hydrated login acquire, supplies the current
// request context, and forwards to the identity provider.
function _spacefast_access_handle_login(array $serving, string $requestHost): void
{
    if (_stattic_runtime_request_method() !== 'GET') {
        _stattic_serve_page('method-not-allowed', [
            'status' => 405,
            'headers' => ['Allow' => 'GET', 'Cache-Control' => 'no-store'],
            'message' => 'Method Not Allowed',
            'code' => 'method_not_allowed',
        ]);
    }
    $returnPath = isset($_GET['return']) && is_string($_GET['return'])
        ? _spacefast_safe_return_path($_GET['return'])
        : null;
    if ($returnPath === null) {
        $referer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER']) : null;
        if (is_array($referer) && _stattic_normalize_hostname((string) ($referer['host'] ?? '')) === _stattic_normalize_hostname($requestHost)) {
            $returnPath = _spacefast_safe_return_path((string) ($referer['path'] ?? '/') . (isset($referer['query']) ? '?' . $referer['query'] : ''));
        }
    }
    $returnPath ??= '/';
    $requestedMethod = 0;
    if (isset($_GET['method'])) {
        $requestedMethod = is_string($_GET['method']) && preg_match('/^[0-9]{1,9}$/', $_GET['method']) === 1
            ? (int) $_GET['method']
            : -1;
    }
    $context = _spacefast_access_context($serving, $requestHost, (string) parse_url($returnPath, PHP_URL_PATH));
    foreach (_spacefast_policy_rules($serving) as $rule) {
        if (!is_array($rule) || !_spacefast_rule_is_live_match($rule, $context, time())) {
            continue;
        }
        $auth = is_array($rule['auth'] ?? null) ? $rule['auth'] : [];
        $loginMethod = 0;
        foreach (is_array($auth['acquire'] ?? null) ? $auth['acquire'] : [] as $acquire) {
            if (!is_array($acquire) || ($acquire['type'] ?? null) !== 'login' || !is_string($acquire['url'] ?? null) || $acquire['url'] === '') {
                continue;
            }
            if ($loginMethod !== $requestedMethod) {
                $loginMethod += 1;
                continue;
            }
            $handoffUrl = _spacefast_access_login_handoff_url($acquire['url'], $requestHost, $returnPath);
            if ($handoffUrl === null) {
                break;
            }
            header('Cache-Control: no-store');
            header('Location: ' . $handoffUrl, true, 302);
            exit;
        }
        break;
    }
    _stattic_serve_page('denied', [
        'status' => 403,
        'headers' => ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'],
        'message' => 'No login method is available for this page.',
        'code' => 'login_unavailable',
        'customizable' => true,
        'serving' => $serving,
        'request_path' => (string) parse_url($returnPath, PHP_URL_PATH),
    ]);
}

// Access-callback handoff (access-plan §3.2, X-29). Verifies the visitor
// profile; all non-invite callback handoffs must carry a `jti`, enforce the
// ≤5-min `iat` window, and consume the jti single-use (replay → reject). Invite
// accept tokens deliberately stay re-clickable; revocation is structural via
// their invite grant. Sets the cookie (Max-Age from exp) and 303s to the
// sanitized same-origin return. `Partitioned` under an iframe destination
// (dashboard previews).
function _spacefast_access_handle_callback(array $serving, string $requestHost, string $privateRoot): void
{
    _spacefast_strip_untrusted_edge_headers();
    _spacefast_access_private_root($privateRoot);
    $token = isset($_GET['token']) && is_string($_GET['token']) ? trim((string) $_GET['token']) : '';
    $returnRaw = isset($_GET['return']) && is_string($_GET['return']) ? (string) $_GET['return'] : '/';
    $returnTo = _spacefast_safe_return_path($returnRaw);
    if ($token === '' || $returnTo === null) {
        _spacefast_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }

    $host = _spacefast_canonicalize_host($requestHost);
    $sessionVersion = _spacefast_policy_session_version($serving);
    $verifyOptions = _spacefast_visitor_verify_options($serving, $host, null, null, [
        'requireJti' => false,
        'iatMaxAge' => 300,
    ]);

    $verified = _spacefast_visitor_verify($token, $verifyOptions);
    if ($verified === null) {
        _spacefast_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }

    if (_spacefast_callback_requires_jti($verified, $serving, $host, $returnTo)) {
        $verified = _spacefast_visitor_verify($token, array_merge($verifyOptions, ['requireJti' => true]));
    }
    if ($verified === null) {
        _spacefast_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }

    $sessionToken = _spacefast_access_session_create($verified, $host, $sessionVersion);
    if ($sessionToken === null) {
        _spacefast_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }
    $maxAge = max(0, (int) $verified['exp'] - time());
    $partitioned = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'iframe';
    _spacefast_set_cookie(SPACEFAST_ACCESS_COOKIE, $sessionToken, $maxAge, $partitioned);
    header('Cache-Control: no-store', true);
    header('Location: ' . $returnTo, true, 303);
    exit;
}

function _spacefast_callback_requires_jti(array $verified, array $serving, string $host, string $returnTo): bool
{
    $sub = is_string($verified['sub'] ?? null) ? (string) $verified['sub'] : '';
    $grants = is_array($verified['grants'] ?? null) ? $verified['grants'] : [];
    if ($sub === '' || !str_starts_with($sub, 'invite:') || !in_array($sub, $grants, true)) {
        return true;
    }

    foreach ($grants as $grant) {
        if (!is_string($grant)) {
            return true;
        }
        if ($grant !== $sub) {
            return true;
        }
    }

    return !_spacefast_callback_return_allows_invite_replay($serving, $host, $returnTo, $sub, $grants);
}

function _spacefast_callback_return_allows_invite_replay(
    array $serving,
    string $host,
    string $returnTo,
    string $inviteGrant,
    array $grants
): bool {
    $path = parse_url($returnTo, PHP_URL_PATH);
    $context = _spacefast_access_context($serving, $host, is_string($path) && $path !== '' ? $path : '/');
    // Reuse the pure policy walk (_spacefast_evaluate_policy) instead of
    // re-implementing it. Rule ids are rekeyed positionally first so the
    // deciding rule can be looked back up for its requiredGrants even when the
    // stored policy carries id-less or duplicate-id rules (`id` is optional at
    // the management intake) — evaluation never reads `id`, only reports it.
    $rules = [];
    foreach (_spacefast_policy_rules($serving) as $rule) {
        if (is_array($rule)) {
            $rule['id'] = (string) count($rules);
        }
        $rules[] = $rule;
    }
    $decision = _spacefast_evaluate_policy($rules, $context, $grants, true, time());
    if ($decision['ruleId'] === null) {
        return false;
    }
    $rule = $rules[(int) $decision['ruleId']] ?? null;
    return is_array($rule)
        && $decision['effect'] === 'allow'
        && $decision['satisfied'] === true
        && in_array($inviteGrant, _spacefast_rule_required_grants($rule), true);
}

// `?sf_share=` trade (access-plan §3.2). Exp-bounded, replayable link tokens
// only (NO jti/iat requirement — the share URL IS the credential; revocation is
// structural). Callback/handoff tokens carry person/invite/external grants and
// may be single-use, so they must not be accepted through this replayable lane.
// Sets the cookie, 303s to the clean URL with the param stripped, no-store.
function _spacefast_access_handle_share(array $serving, string $requestHost, string $requestUri): void
{
    $token = isset($_GET[SPACEFAST_SHARE_QUERY_NAME]) && is_string($_GET[SPACEFAST_SHARE_QUERY_NAME])
        ? trim((string) $_GET[SPACEFAST_SHARE_QUERY_NAME])
        : '';
    $host = _spacefast_canonicalize_host($requestHost);
    $cleanUri = _spacefast_uri_without_share_param($requestUri);

    if ($token !== '') {
        $verified = _spacefast_visitor_verify($token, _spacefast_visitor_verify_options($serving, $host));
        if ($verified !== null && _spacefast_share_credential_allowed($verified)) {
            $maxAge = min(2592000, max(0, (int) $verified['exp'] - time())); // capped 30d.
            _spacefast_set_cookie(SPACEFAST_ACCESS_COOKIE, $token, $maxAge);
        }
    }
    header('Cache-Control: no-store', true);
    header('Location: ' . $cleanUri, true, 303);
    exit;
}

function _spacefast_share_credential_allowed(array $verified): bool
{
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    if (isset($claims['jti'])) {
        return false;
    }
    $sub = is_string($verified['sub'] ?? null) ? (string) $verified['sub'] : '';
    if (!str_starts_with($sub, 'link:')) {
        return false;
    }
    $grants = is_array($verified['grants'] ?? null) ? $verified['grants'] : [];
    if ($grants === []) {
        return false;
    }
    foreach ($grants as $grant) {
        if (!is_string($grant) || !str_starts_with($grant, 'link:')) {
            return false;
        }
    }
    return true;
}

function _spacefast_uri_without_share_param(string $requestUri): string
{
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $query = parse_url($requestUri, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return $path;
    }
    $kept = [];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') {
            continue;
        }
        $name = explode('=', $pair, 2)[0] ?? '';
        if (rawurldecode(str_replace('+', '%20', $name)) === SPACEFAST_SHARE_QUERY_NAME) {
            continue;
        }
        $kept[] = $pair;
    }
    return $kept === [] ? $path : $path . '?' . implode('&', $kept);
}

// Logout (access-plan §3.2): clears BOTH cookies, 303 to a same-origin-validated
// return.
function _spacefast_access_handle_logout(string $requestHost): void
{
    $returnRaw = isset($_GET['return']) && is_string($_GET['return']) ? (string) $_GET['return'] : '/';
    $returnTo = _spacefast_safe_return_path($returnRaw) ?? '/';
    _spacefast_clear_cookie(SPACEFAST_ACCESS_COOKIE);
    _spacefast_clear_cookie(SPACEFAST_CLAIM_VIEW_COOKIE);
    header('Cache-Control: no-store', true);
    header('Location: ' . $returnTo, true, 303);
    exit;
}

// `/__spacefast/access/me` (access-plan §3.2): JSON {sub, grants, exp} from the
// verified cookie; 401 access_unauthenticated when anonymous; no-store.
function _spacefast_access_handle_me(array $serving, string $requestHost): void
{
    $verified = _spacefast_verify_cookie_identity($serving, $requestHost);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($verified === null) {
        _spacefast_render_json_unauthenticated('access_unauthenticated', false);
    }
    http_response_code(200);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'sub' => $verified['sub'],
            'grants' => $verified['grants'],
            'exp' => $verified['exp'],
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit;
}

// `/__spacefast/access/token` (access-plan §3.2, §6.1): same-origin fetch
// returning the raw cookie token when valid; 401 anonymous; no-store.
function _spacefast_access_handle_token(array $serving, string $requestHost): void
{
    $cookie = $_COOKIE[SPACEFAST_ACCESS_COOKIE] ?? '';
    $verified = _spacefast_verify_cookie_identity($serving, $requestHost);
    header('Cache-Control: no-store');
    if ($verified === null || !is_string($cookie) || $cookie === '') {
        _spacefast_render_json_unauthenticated('access_unauthenticated', false);
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $cookie;
    }
    exit;
}

function _spacefast_verify_cookie_identity(array $serving, string $requestHost): ?array
{
    $cookie = $_COOKIE[SPACEFAST_ACCESS_COOKIE] ?? '';
    if (!is_string($cookie) || $cookie === '') {
        return null;
    }
    $host = _spacefast_canonicalize_host($requestHost);
    $sessionVersion = _spacefast_policy_session_version($serving);
    $session = _spacefast_access_session_verify($cookie, $serving, $host, $sessionVersion, _spacefast_policy_issuers($serving));
    if ($session !== null) {
        return $session;
    }
    $verified = _spacefast_visitor_verify($cookie, _spacefast_visitor_verify_options($serving, $host));
    if ($verified === null || !_spacefast_direct_request_credential_allowed($verified)) {
        return null;
    }
    return $verified;
}

function _spacefast_render_json_or_deny(string $code, string $message): void
{
    if (_spacefast_request_is_fetch()) {
        _spacefast_render_json_unauthenticated($code);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $message . "\n";
    }
    exit;
}
