<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/pointers.php';
require_once __DIR__ . '/finalizer-protocol.generated.php';

function _stattic_render_runtime_invariant_error_lazy(string $code, string $message): void
{
    require_once __DIR__ . '/errors.php';
    _stattic_render_runtime_invariant_error($code, $message);
}

function _stattic_platform_error_action(string $pageId, int $status, string $message, string $cacheControl = 'no-store', array $responseHeaders = []): array
{
    $action = [
        'action' => 'platform_error',
        'page_id' => $pageId,
        'status' => $status,
        'cache_control' => $cacheControl,
        'message' => $message,
        'methods' => STATTIC_VISITOR_METHODS,
    ];
    if ($responseHeaders !== []) {
        $action['response_headers'] = $responseHeaders;
    }
    return $action;
}

function _stattic_version_pending_action(): array
{
    return _stattic_platform_error_action('version-pending', 503, "This version is not active yet.\n", 'public, max-age=0, s-maxage=30, must-revalidate');
}

function _stattic_runtime_artifact_metadata_valid_lazy(mixed $artifact): bool
{
    return is_array($artifact)
        && ($artifact['runtime_schema'] ?? null) === STATTIC_RUNTIME_SCHEMA
        && is_string($artifact['runtime_engine_version'] ?? null)
        && $artifact['runtime_engine_version'] === SPACEFAST_RUNTIME_ENGINE_VERSION
        && is_string($artifact['generated_at'] ?? null)
        && $artifact['generated_at'] !== '';
}

function _stattic_version_files_root(string $privateRoot, string $spaceId, string $versionId): string
{
    return _stattic_version_root($privateRoot, $spaceId, $versionId) . '/files';
}

// The config lives beside the version's files (`../functions/`), not inside
// them, so a publish can never write it. Verified absence is the only state
// that proves "no worker" — an unreadable or malformed config keeps the
// Functions lanes engaged so a transient failure never collapses into 404.
function _stattic_version_has_functions(string $versionRoot): bool
{
    return _stattic_functions_config_read($versionRoot)['kind'] !== 'absent';
}

// The ONE reader of the version-adjacent functions/config.json: the static
// bypass probe (serve.php), the dispatch lane (functions-dispatch.php) and the
// purge credential (functions-purge.php) all answer from this memo. Stored
// beside the version's file tree, never inside it: a publish reaches `files/`
// and nothing else, so no upload can create or amend this document. Settled
// outcomes — present, verified absent, malformed — are memoized; `unavailable`
// is transient and every caller gets its own retry.
/** @return array{kind: 'present', value: array}|array{kind: 'absent'|'unavailable'|'malformed'} */
function _stattic_functions_config_read(string $versionRoot): array
{
    static $cache = [];
    $path = dirname($versionRoot) . '/functions/config.json';
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    // Absence is the common case (every static version): answer it from one
    // stat before the pointer reader's warning-emitting open.
    if (!is_file($path) && _sf_path_verifiably_absent($path)) {
        return $cache[$path] = ['kind' => 'absent'];
    }
    $read = _sf_pointer_read('functions-config', $path);
    if ($read['kind'] === 'absent') {
        return $cache[$path] = ['kind' => 'absent'];
    }
    if ($read['kind'] === 'unavailable') {
        return ['kind' => 'unavailable'];
    }
    $decoded = $read['value'];
    $host = is_array($decoded['host'] ?? null) ? $decoded['host'] : [];
    $artifact = is_array($decoded['artifact'] ?? null) ? $decoded['artifact'] : [];
    if (
        ($decoded['runtimeKind'] ?? null) !== 'functions'
        || !is_string($host['hostname'] ?? null) || $host['hostname'] === ''
        || !is_string($host['bundleUrl'] ?? null) || $host['bundleUrl'] === ''
        || !is_string($artifact['mainModule'] ?? null) || $artifact['mainModule'] === ''
        || !is_string($artifact['compatibilityDate'] ?? null) || $artifact['compatibilityDate'] === ''
    ) {
        return $cache[$path] = ['kind' => 'malformed'];
    }
    return $cache[$path] = ['kind' => 'present', 'value' => $decoded];
}

// Tombstone page variants (C10). The CSAM variant must stay byte-identical to a
// never-deployed host — same 503 body, no page identity, and 'robots' => false
// suppressing the noindex marker every other variant advertises.
const STATTIC_TOMBSTONE_VARIANTS = [
    'tombstone-csam' => ['template_id' => 'undeployed', 'status' => 503, 'body' => "This space hasn't been published yet.\n", 'robots' => false, 'cache_control' => STATTIC_CACHE_CONTROL_NO_STORE],
    'tombstone-dmca' => ['template_id' => 'tombstone-dmca', 'status' => 451, 'body' => "This content is unavailable for legal reasons.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
    'tombstone-suspended' => ['template_id' => 'tombstone-suspended', 'status' => 402, 'body' => "This space is paused.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
    'tombstone-generic' => ['template_id' => 'tombstone-generic', 'status' => 404, 'body' => "This space is no longer available.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
];

function _stattic_runtime_tombstone_route_action_valid(mixed $action): bool
{
    if (!is_array($action) || ($action['action'] ?? null) !== 'tombstone') {
        return false;
    }
    $pageId = $action['page_id'] ?? null;
    $variant = is_string($pageId) ? (STATTIC_TOMBSTONE_VARIANTS[$pageId] ?? null) : null;
    return is_array($variant)
        && (int) ($action['status'] ?? 0) === $variant['status']
        && is_string($action['cache_control'] ?? null)
        && $action['cache_control'] === $variant['cache_control']
        && is_string($action['space_id'] ?? null)
        && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $action['space_id']) === 1
        && !str_contains($action['space_id'], '..')
        && ($action['methods'] ?? null) === STATTIC_VISITOR_METHODS;
}

function _stattic_zero_route_entry_shape_valid(mixed $entry): bool
{
    return is_array($entry)
        && is_string($entry['method'] ?? null)
        && in_array($entry['method'], STATTIC_VISITOR_METHODS, true)
        && is_string($entry['pattern'] ?? null)
        && $entry['pattern'] !== ''
        && $entry['pattern'][0] === '/'
        && is_string($entry['endpoint_id'] ?? null)
        && $entry['endpoint_id'] !== ''
        && is_string($entry['artifact'] ?? null)
        && _stattic_runtime_relative_artifact_path_valid($entry['artifact'])
        && (!array_key_exists('schema_hash', $entry) || is_string($entry['schema_hash']) || $entry['schema_hash'] === null);
}

// The invocation half of the outbox key. The bound matches the outbox's
// VARCHAR(96) invocation_id column; anything else yields an empty identity.
function _stattic_service_invocation_id(mixed $raw): string
{
    return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{1,96}$/', $raw) === 1 ? $raw : '';
}

/**
 * The environment the platform-service broker runs with.
 *
 * Every value here is the runtime's, never the caller's. The Akismet key and
 * the Gravatar token are credentials that must not exist inside tenant code in
 * any form, and the blog URL is the space's own canonical origin — Akismet
 * partitions reputation by it, so a caller that could name it could spend
 * another space's standing. Each space is its own site with its own generated
 * config, which is what makes a site-level constant a per-space value.
 *
 * The identity triple is the outbox's idempotency key. A replayed invocation
 * has to arrive at the same key, or it sends the mail twice.
 */
function _stattic_service_broker_env(array $identity): array
{
    $env = [];
    foreach ([
        'SPACEFAST_SERVICE_AKISMET_KEY',
        'SPACEFAST_SERVICE_GRAVATAR_KEY',
        'SPACEFAST_SERVICE_BLOG_URL',
        'SPACEFAST_SERVICE_EMAIL_SENDERS',
    ] as $name) {
        $value = _stattic_config_value($name);
        if ($value !== '') {
            $env[$name] = $value;
        }
    }
    foreach ([
        'SPACEFAST_SERVICE_SPACE_ID' => 'spaceId',
        'SPACEFAST_SERVICE_VERSION_ID' => 'versionId',
        'SPACEFAST_SERVICE_INVOCATION_ID' => 'invocationId',
    ] as $name => $key) {
        $value = is_string($identity[$key] ?? null) ? trim($identity[$key]) : '';
        if ($value !== '') {
            $env[$name] = $value;
        }
    }
    return $env;
}

function _stattic_zero_runner_base_env(array $config = []): array
{
    $env = _stattic_zero_internal_hosts_env(
        _stattic_config_value('SPACEFAST_MANAGEMENT_HOSTNAME'),
        _stattic_config_value('SPACEFAST_API_BASE_URL')
    );
    // DATABASE_URL travels under a reserved name with explicit provenance so the
    // runner can tell an application URL from the provider database and reject
    // ambient configuration.
    $variables = is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [];
    $selectedDatabaseUrl = is_string($variables['DATABASE_URL'] ?? null)
        ? trim($variables['DATABASE_URL'])
        : '';
    if ($selectedDatabaseUrl !== '') {
        $databaseUrlSource = ($config['databaseUrlSource'] ?? null) === 'provider'
            ? 'provider'
            : 'application';
        return $env + [
            'SPACEFAST_ZERO_DATABASE_URL' => $selectedDatabaseUrl,
            'SPACEFAST_ZERO_DATABASE_URL_SOURCE' => $databaseUrlSource,
        ];
    }

    $providerDatabaseUrl = _stattic_config_value('SPACEFAST_ZERO_DATABASE_URL');
    if ($providerDatabaseUrl === '') {
        $providerDatabaseUrl = _stattic_config_value('DATABASE_URL');
    }
    if ($providerDatabaseUrl === '') {
        // The provider-owned DB_* tuple is translated at the native-process
        // boundary so tenant code never receives the credential.
        $providerDatabaseUrl = _stattic_zero_mysql_database_url_from_config();
    }
    if ($providerDatabaseUrl === '') {
        return $env;
    }
    return $env + [
        'SPACEFAST_ZERO_DATABASE_URL' => $providerDatabaseUrl,
        'SPACEFAST_ZERO_DATABASE_URL_SOURCE' => 'provider',
    ];
}

/**
 * Spacefast-owned hosts that tenant fetch must never reach.
 *
 * The native runner owns DNS resolution and address pinning. PHP only supplies
 * the environment-specific hostnames that cannot live in its compiled policy.
 */
function _stattic_zero_internal_hosts_env(string $managementHostname, string $apiBaseUrl): array
{
    $hosts = [];
    $managementHostname = strtolower(trim($managementHostname, "[] \t\n\r\0\x0B."));
    if ($managementHostname !== '') {
        $hosts[] = $managementHostname;
    }
    $apiHostname = strtolower((string) parse_url($apiBaseUrl, PHP_URL_HOST));
    if ($apiHostname !== '') {
        $hosts[] = $apiHostname;
    }
    $hosts = array_values(array_unique($hosts));
    return $hosts === [] ? [] : ['SPACEFAST_ZERO_INTERNAL_HOSTS' => implode(',', $hosts)];
}

function _stattic_zero_mysql_database_url_from_config(): string
{
    $host = _stattic_config_value('DB_HOST');
    $database = _stattic_config_value('DB_NAME');
    $user = _stattic_config_value('DB_USER');
    $password = _stattic_config_value('DB_PASSWORD');
    if ($database === '' || $user === '' || $password === '') {
        return '';
    }
    // DB_* convention: an omitted host means local. The native runner cannot
    // inherit PHP's implicit socket/localhost default, so make it explicit.
    if ($host === '') {
        $host = '127.0.0.1';
    }

    $socket = '';
    if (preg_match('/^([A-Za-z0-9._-]+):(\/.*)$/', $host, $socketMatch) === 1) {
        $host = $socketMatch[1];
        $socket = $socketMatch[2];
    }
    if (preg_match('/^(?:\[[0-9A-Fa-f:]+\]|[A-Za-z0-9._-]+)(?::[1-9][0-9]{0,4})?$/', $host) !== 1) {
        return '';
    }

    return 'mysql://'
        . rawurlencode($user)
        . ':'
        . rawurlencode($password)
        . '@'
        . $host
        . '/'
        . rawurlencode($database)
        . ($socket !== '' ? '?socket=' . rawurlencode($socket) : '');
}

function _stattic_zero_debug_message(string $message, mixed $detail): string
{
    if (_stattic_config_value('SPACEFAST_ZERO_RUNNER_DEBUG') !== '1' || !is_string($detail) || $detail === '') {
        return $message;
    }

    return $message . ' ' . substr($detail, 0, 2048);
}

function _stattic_zero_string_map(array $values): array
{
    $out = [];
    foreach ($values as $name => $value) {
        if (is_string($name) && $name !== '' && is_string($value)) {
            $out[$name] = $value;
        }
    }
    return $out;
}

// THE segment-escape rule behind both the relative-artifact-path and
// route-pattern guards; literal consecutive dots inside a segment are safe.
function _stattic_runtime_path_segments_safe(string $value): bool
{
    return !str_contains($value, "\0")
        && !str_contains($value, '\\')
        && !in_array('..', explode('/', $value), true);
}

// Relative-artifact-path traversal guard. Deliberately not in shared/safety.php:
// that file is generated from the TS policy and must not be hand-edited.
function _stattic_runtime_relative_artifact_path_valid(mixed $path): bool
{
    if (
        !is_string($path)
        || $path === ''
        || strlen($path) > 512
        || $path[0] === '/'
    ) {
        return false;
    }

    return _stattic_runtime_path_segments_safe($path);
}

// Rejects traversal segments while preserving literal names that contain two
// dots (Astro's `[...slug].astro`).
function _stattic_runtime_route_pattern_valid(mixed $pattern): bool
{
    if (
        !is_string($pattern)
        || $pattern === ''
        || $pattern[0] !== '/'
    ) {
        return false;
    }

    return _stattic_runtime_path_segments_safe($pattern);
}

// THE method rule for both compiled route tables: HEAD is served by the GET
// route, and a null method (Functions-only) claims every verb.
function _stattic_functions_route_method_matches(?string $routeMethod, string $requestMethod): bool
{
    return $routeMethod === null
        || $routeMethod === $requestMethod
        || ($routeMethod === 'GET' && $requestMethod === 'HEAD');
}

function _stattic_match_route_pattern_segments(string $pattern, string $lookup): ?array
{
    $shape = _stattic_zero_route_match_shape($pattern);
    $l = trim($lookup, '/');
    $pathSegments = $l === '' ? [] : explode('/', $l);
    $params = [];
    $pathIndex = 0;
    foreach ($shape['segments'] as $index => $segment) {
        if (!array_key_exists($pathIndex, $pathSegments)) {
            return null;
        }
        $pathSegment = $pathSegments[$pathIndex];
        if ($segment === null) {
            $name = $shape['names'][$index];
            if ($name === '') {
                return null;
            }
            $params[$name] = rawurldecode($pathSegment);
        } elseif ($segment !== $pathSegment) {
            return null;
        }
        $pathIndex++;
    }
    if ($shape['splat']) {
        $params['splat'] = implode('/', array_slice($pathSegments, $pathIndex));
        return ['params' => $params, 'score' => $shape['score']];
    }
    if ($pathIndex !== count($pathSegments)) {
        return null;
    }

    return ['params' => $params, 'score' => $shape['score']];
}

// THE route-pattern parser and score table: the runtime matcher above reads a
// pattern through here so scoring lives in exactly one place.
function _stattic_zero_route_match_shape(string $pattern): array
{
    $trimmed = trim($pattern, '/');
    $segments = $trimmed === '' ? [] : explode('/', $trimmed);
    $constraints = [];
    $names = [];
    $score = 0;
    $splat = false;
    foreach ($segments as $segment) {
        if ($segment === ':splat') {
            $score += 1;
            $splat = true;
            break;
        }
        if (str_starts_with($segment, ':')) {
            $constraints[] = null;
            $names[] = substr($segment, 1);
            $score += 2;
            continue;
        }
        $constraints[] = $segment;
        $names[] = null;
        $score += 10;
    }

    return ['segments' => $constraints, 'names' => $names, 'splat' => $splat, 'score' => $score];
}

// ---------------------------------------------------------------------------
// schema-v4 route/space resolution (contracts §3, §4)
//
// Where the visitor path starts: route pointer -> host shard -> space pointer ->
// overlay -> the legacy `$serving` array the modules outside the serve path
// still read. runtime/serve.php walks these in order.
// ---------------------------------------------------------------------------

function _stattic_v4_assert_schema(mixed $schema, string $what): void
{
    if (
        !is_int($schema)
        || $schema < STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN
        || $schema > STATTIC_RUNTIME_ARTIFACT_SCHEMA_MAX
    ) {
        _stattic_render_runtime_invariant_error_lazy(
            'artifact-schema-mismatch',
            'Runtime ' . $what . ' schema does not match this runtime.'
        );
    }
}

/**
 * false = a shard this host may live in exists but could not be read — the
 * host's existence is UNKNOWN, which must never collapse into "undeployed".
 *
 * @return array{entry: array, routes: array}|false|null
 */
function _stattic_v4_host_lookup(string $privateRoot, array $routes, string $requestHost): array|false|null
{
    if ($requestHost === '') {
        return null;
    }
    $shardName = is_array($routes['shards'] ?? null)
        ? ($routes['shards'][substr(hash('sha256', $requestHost), 0, 2)] ?? null)
        : null;
    $shard = is_string($shardName) ? _stattic_v4_include_artifact($privateRoot . '/routes/' . $shardName) : null;
    if ($shard === false) {
        return false;
    }
    $entry = null;
    $hostRoutes = [];
    if (is_array($shard)) {
        $candidate = $shard['hostnames'][$requestHost] ?? null;
        if (is_array($candidate)) {
            $entry = $candidate;
        }
        $routeList = $shard['host_routes'][$requestHost] ?? null;
        if (is_array($routeList)) {
            $hostRoutes = $routeList;
        }
    }
    if ($entry === null && !empty($routes['has_wildcards']) && is_string($routes['wildcards'] ?? null)) {
        $wildcards = _stattic_v4_include_artifact($privateRoot . '/routes/' . $routes['wildcards']);
        if ($wildcards === false) {
            return false;
        }
        if (is_array($wildcards)) {
            $entry = _stattic_v4_wildcard_match(
                is_array($wildcards['hostnames'] ?? null) ? $wildcards['hostnames'] : [],
                $requestHost
            );
            if ($hostRoutes === []) {
                $matchedRoutes = _stattic_v4_wildcard_match(
                    is_array($wildcards['host_routes'] ?? null) ? $wildcards['host_routes'] : [],
                    $requestHost
                );
                $hostRoutes = is_array($matchedRoutes) ? $matchedRoutes : [];
            }
        }
    }
    if (!is_array($entry)) {
        return null;
    }
    return ['entry' => $entry, 'routes' => $hostRoutes];
}

// D49: `*` matches exactly one label, and the most specific pattern wins —
// specificity being the number of literal labels. `*.a.example` and
// `*.*.example` both match `x.a.example`; the first one takes it.
function _stattic_v4_wildcard_match(array $entries, string $requestHost): ?array
{
    $hostLabels = explode('.', $requestHost);
    $hostLabelCount = count($hostLabels);
    $best = null;
    $bestScore = -1;
    foreach ($entries as $pattern => $entry) {
        if (!is_string($pattern) || !is_array($entry) || !str_contains($pattern, '*')) {
            continue;
        }
        $patternLabels = explode('.', $pattern);
        if (count($patternLabels) !== $hostLabelCount) {
            continue;
        }
        $score = 0;
        $matches = true;
        foreach ($patternLabels as $index => $label) {
            if ($label === '*') {
                continue;
            }
            if ($label !== $hostLabels[$index]) {
                $matches = false;
                break;
            }
            $score += 1;
        }
        if ($matches && $score > $bestScore) {
            $bestScore = $score;
            $best = $entry;
        }
    }
    return $best;
}

// false = the overlay artifact exists but could not be read; the caller must
// answer unavailable, not denied — a failure is not an access decision either.
function _stattic_v4_overlay(string $privateRoot, string $spaceId, mixed $space): array|false|null
{
    if ($spaceId === '' || !is_array($space) || !is_string($space['overlay'] ?? null)) {
        return null;
    }
    _stattic_v4_assert_schema($space['schema'] ?? null, 'space pointer');
    $overlay = _stattic_v4_include_artifact(
        _stattic_space_root($privateRoot, $spaceId) . '/' . $space['overlay']
    );
    if ($overlay === false) {
        return false;
    }
    if (!is_array($overlay) || !is_bool($overlay['open'] ?? null)) {
        return null;
    }
    _stattic_v4_assert_schema($overlay['schema'] ?? null, 'space overlay');
    return $overlay;
}

function _stattic_v4_version_for_host(array $hostEntry, array $overlay): ?string
{
    // A version-pinned host names its version outright; a route-following host
    // reads the overlay, so repointing a route is one overlay swap.
    $versionId = $hostEntry['version_id'] ?? null;
    if (is_string($versionId) && $versionId !== '' && _stattic_id_valid($versionId)) {
        return $versionId;
    }
    $routeName = is_string($hostEntry['route_name'] ?? null) ? $hostEntry['route_name'] : 'live';
    $versions = is_array($overlay['versions'] ?? null) ? $overlay['versions'] : [];
    $resolved = $versions[$routeName] ?? null;
    return is_string($resolved) && $resolved !== '' && _stattic_id_valid($resolved) ? $resolved : null;
}

// null = the artifact verifiably does not exist; false = it exists but this
// include failed (I/O). Failures are never memoized — the next call re-reads —
// and callers must route false to the unavailable 503, never fold it into the
// absent answer (undeployed / version-pending / 404 / denied).
function _stattic_v4_include_artifact(string $path): array|false|null
{
    static $cache = [];
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }
    error_clear_last();
    $loaded = include $path;
    if (is_array($loaded)) {
        return $cache[$path] = $loaded;
    }
    clearstatcache(true, $path);
    if (_sf_path_verifiably_absent($path)) {
        return $cache[$path] = null;
    }
    _sf_runtime_log_read_failure('artifact_include_failed', $path);
    $loaded = include $path;
    if (is_array($loaded)) {
        return $cache[$path] = $loaded;
    }
    return false;
}

// A pointer or artifact that EXISTS could not be read this instant. Never a
// deployment or access claim: 503 + no-store + Retry-After, so the edge holds
// nothing and the retry lands on a healed read.
function _stattic_render_runtime_unavailable_lazy(string $code): never
{
    require_once __DIR__ . '/errors.php';
    _stattic_render_runtime_unavailable($code);
}


// Overlay -> the compiled projection access-rules.php reads today. Deleted with
// the seam body once E2 lands.
function _stattic_v4_authorization_projection(array $overlay): ?array
{
    $grants = is_array($overlay['grants'] ?? null) ? $overlay['grants'] : null;
    $exchange = is_array($overlay['exchange'] ?? null) ? $overlay['exchange'] : [];
    $compiledVersion = $overlay['compiled_version'] ?? null;
    if ($grants === null || $compiledVersion === null || !is_string($exchange['acquire_url'] ?? null)) {
        // Fail closed: null is the canonical "no usable projection" marker.
        return null;
    }
    return [
        // Carried by the overlay, not read from access-rules.php: an open Space
        // must be able to build this without loading any access code (D34).
        'compiledVersion' => $compiledVersion,
        'generation' => is_int($overlay['access_gen'] ?? null) ? $overlay['access_gen'] : 0,
        'sessionVersion' => is_int($overlay['session_ver'] ?? null) ? $overlay['session_ver'] : 0,
        'fence' => is_string($overlay['fence'] ?? null) ? $overlay['fence'] : 'none',
        'acquireUrl' => $exchange['acquire_url'],
        // Without this the sfv2_/sfa1_ HMAC key cannot be derived at all
        // (access-rules.php `_stattic_access_session_hmac_key` reads
        // `accessPage.exchange.credential`), so a protected Space could neither
        // mint nor verify a session: permanently deny-only. The overlay carries
        // the descriptor verbatim under `access_page`; access-rules.php
        // re-validates every field of it before use.
        'accessPage' => is_array($overlay['access_page'] ?? null)
            ? $overlay['access_page']
            : null,
        'spaceClaimed' => ($overlay['space_claimed'] ?? null) === true,
        'grantIndex' => $grants,
    ];
}

// The legacy `$serving` array every module outside the serve path still reads
// (access-rules, zero, proxy, spacefast-sdk, errors' custom pages). Built once
// from the v4 host entry + overlay so those modules need no edit.
function _stattic_v4_legacy_serving(string $spaceId, ?string $versionId, array $hostEntry, array $overlay): array
{
    $exchange = is_array($overlay['exchange'] ?? null) ? $overlay['exchange'] : [];
    return [
        'version_id' => $versionId,
        'space_id' => $spaceId,
        'authorization' => _stattic_v4_authorization_projection($overlay),
        'visitor_issuer' => is_string($exchange['issuer'] ?? null) ? $exchange['issuer'] : null,
        'visitor_jwks' => is_array($exchange['jwks'] ?? null) ? $exchange['jwks'] : null,
        'projection_generation' => is_int($overlay['access_gen'] ?? null) ? $overlay['access_gen'] : null,
        'admission' => is_array($overlay['admission'] ?? null) ? $overlay['admission'] : [],
        'route_name' => is_string($hostEntry['route_name'] ?? null) ? $hostEntry['route_name'] : null,
        'immutable' => !empty($hostEntry['immutable']),
        // Absent/malformed -> null -> no restriction: the policy is opt-in.
        'content_types' => is_array($overlay['content_types'] ?? null) && is_array($overlay['content_types']['allowed'] ?? null)
            ? $overlay['content_types']
            : null,
        // Fail closed: [] makes every entitlement check in redirects.php false.
        'entitlements' => is_array($overlay['entitlements'] ?? null) ? $overlay['entitlements'] : [],
        // The Space's current live version, straight off the overlay's route
        // map. Comments needs it to tell a preview visitor what is live now.
        'live_version_id' => is_array($overlay['versions'] ?? null)
            && is_string($overlay['versions']['live'] ?? null)
            ? $overlay['versions']['live']
            : null,
        'pages' => is_array($overlay['pages'] ?? null) ? $overlay['pages'] : [],
        // ONE SDK section (§4): {revision, config, body|body_sha256}. The
        // loader, its Comments configuration and the production tag bytes all
        // come from here — there is no second SDK artifact and no serve-time
        // configuration call back to the control plane.
        'sdk' => is_array($overlay['sdk'] ?? null) && is_array($overlay['sdk']['config'] ?? null)
            ? $overlay['sdk']
            : null,
    ];
}
