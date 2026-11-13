<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/legacy-zero.php';
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

// The one refusal a signed machine route gives for every failure (bad path,
// bad token, missing bytes, no credential): distinguishing them would confirm
// which spaces carry what. Callers load shared/response.php.
function _stattic_opaque_not_found(): never
{
    _stattic_response_send(404, "Not found.\n", 'text/plain; charset=utf-8', ['Cache-Control' => 'no-store']);
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

// The config lives in the version directory's `functions/`, outside the
// published file tree, so a publish can never write it. Only verified absence proves "no
// worker"; an unreadable or malformed config keeps the Functions lanes engaged
// so a transient failure never collapses into 404.
function _stattic_version_has_functions(string $versionRoot): bool
{
    return _stattic_functions_config_read($versionRoot)['kind'] !== 'absent';
}

// The ONE reader of the version's functions/config.json: the static
// bypass probe (serve.php), the dispatch lane (functions-dispatch.php) and the
// purge credential (functions-purge.php) all answer from this memo. Settled
// outcomes (present, verified absent, malformed) are memoized; `unavailable` is
// transient and every caller gets its own retry.
/** @return array{kind: 'present', value: array}|array{kind: 'absent'|'unavailable'|'malformed'} */
function _stattic_functions_config_read(string $versionRoot): array
{
    static $cache = [];
    $path = $versionRoot . '/functions/config.json';
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

// A host with nothing published is a 404, not a fault: a 503 here pages the
// fleet 5xx alert whenever a crawler finds an attached-but-unpublished domain.
const STATTIC_UNDEPLOYED_STATUS = 404;
const STATTIC_UNDEPLOYED_BODY = "This space hasn't been published yet.\n";

// Tombstone page variants (C10). The CSAM variant must stay byte-identical to a
// never-deployed host: the undeployed page, no page identity, and 'robots' =>
// false to suppress the noindex marker other variants advertise.
const STATTIC_TOMBSTONE_VARIANTS = [
    'tombstone-csam' => ['template_id' => 'undeployed', 'status' => STATTIC_UNDEPLOYED_STATUS, 'body' => STATTIC_UNDEPLOYED_BODY, 'robots' => false, 'cache_control' => STATTIC_CACHE_CONTROL_NO_STORE],
    'tombstone-dmca' => ['template_id' => 'tombstone-dmca', 'status' => 451, 'body' => "This content is unavailable for legal reasons.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
    'tombstone-suspended' => ['template_id' => 'tombstone-suspended', 'status' => 402, 'body' => "This space is paused.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
    'tombstone-version-deleted' => ['template_id' => 'tombstone-version-deleted', 'status' => 404, 'body' => "This version has been deleted.\n", 'robots' => true, 'cache_control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL],
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

// Restrict service invocation identities to the runtime's wire format.
function _stattic_service_invocation_id(mixed $raw): string
{
    return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{1,96}$/', $raw) === 1 ? $raw : '';
}

// A version's connector credential, from the finalize request's
// `connectors_token`. It sits beside the version, never inside its file tree, so
// no publish can forge or read it; pages, workers and capsules all call
// connectors with this one value.
const STATTIC_CONNECTORS_TOKEN_PATH = 'connectors/token';
// The declaration file for the no-build client, from finalize's
// `connectors_types`, served at `/__spacefast/client.d.ts`.
const STATTIC_SPACE_CLIENT_TYPES_PATH = 'space-client.d.ts';

/** The one reader of a version's connectors credential; '' when it has none. */
function _stattic_connectors_token(string $versionRoot): string
{
    $path = $versionRoot . '/' . STATTIC_CONNECTORS_TOKEN_PATH;
    if (!is_file($path)) {
        // old-engine-compat: a Zero 0.8 version finalized before S12 shipped
        // may keep its credential only in `zero/config.json`. Delete under the
        // fallback's own condition in S12, apps/control-plane/src/compat/old-engine.ts.
        return _stattic_legacy_zero_connectors_token($versionRoot);
    }
    $token = file_get_contents($path);
    return is_string($token) ? trim($token) : '';
}

/**
 * The environment the platform-service broker runs with.
 *
 * Every value here is the runtime's, never the caller's, and none of it may
 * exist inside tenant code. The blog URL is the space's own canonical origin:
 * Akismet partitions reputation by it, so a caller that could name it could
 * spend another space's standing. Each space is its own site with its own
 * generated config, which makes a site-level constant a per-space value.
 *
 * `WPCOM_API_KEY` is the SITE's own Akismet key, provisioned by its Jetpack
 * connection — not a platform credential lent to it. It travels under the name
 * Akismet itself resolves rather than a Spacefast-prefixed one, because the
 * platform brokers no third-party service key: it forwards what the site
 * already holds, and a site holding none gets a refusal.
 *
 */
function _stattic_service_broker_env(array $identity, array $config = []): array
{
    require_once __DIR__ . '/context.php';
    require_once __DIR__ . '/native-process.php';
    $privateRoot = _stattic_access_private_root();
    $spaceId = (string) ($identity['spaceId'] ?? '');
    $env = [
        'SPACEFAST_SERVICE_USAGE_ROOT' => $privateRoot,
        'SPACEFAST_SERVICE_SPACE_ID' => $spaceId,
    ];
    $versionId = (string) ($identity['versionId'] ?? '');
    $token = $spaceId !== '' && $versionId !== '' && $privateRoot !== ''
        ? _stattic_connectors_token(_stattic_version_root($privateRoot, $spaceId, $versionId))
        : '';
    $apiUrl = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    if ($token !== '' && $apiUrl !== '') {
        $env['SPACEFAST_SERVICE_CONNECTORS_URL'] = $apiUrl . '/v1/runtime/connectors/calls';
        $env['SPACEFAST_SERVICE_CONNECTORS_TOKEN'] = $token;
        $env['SPACEFAST_SERVICE_CONNECTORS_VISITOR'] = json_encode($identity['visitor'] ?? null, JSON_UNESCAPED_SLASHES);
    }
    $names = ['WPCOM_API_KEY', 'SPACEFAST_SERVICE_BLOG_URL'];
    foreach ($names as $name) {
        $value = _stattic_config_value($name);
        if ($value !== '') {
            $env[$name] = $value;
        }
    }
    foreach ([
        'SPACEFAST_SERVICE_INVOCATION_ID' => 'invocationId',
        'SPACEFAST_SERVICE_CONTENT_URL' => 'contentUrl',
        'SPACEFAST_SERVICE_CONTENT_COOKIE' => 'contentCookie',
        'SPACEFAST_SERVICE_CONTENT_AUTHORIZATION' => 'contentAuthorization',
    ] as $name => $key) {
        $value = is_string($identity[$key] ?? null) ? trim($identity[$key]) : '';
        if ($value !== '') {
            $env[$name] = $value;
        }
    }
    $emailDomain = is_string($config['email']['senderDomain'] ?? null)
        ? strtolower(trim($config['email']['senderDomain']))
        : '';
    $emailLimit = (int) ($config['billing']['emailRecipientsDaily'] ?? 0);
    if ($emailLimit > 0 && $emailDomain !== '') {
        $env['SPACEFAST_SERVICE_EMAIL_DOMAIN'] = $emailDomain;
        require_once __DIR__ . '/space-mail.php';
        // The box's own hostname: it always reaches this site, whichever host
        // the visitor used, and a stale sender domain could name someone else's.
        $mailUrl = _stattic_config_value('SPACEFAST_SERVICE_EMAIL_URL');
        if ($mailUrl === '') {
            $boxHostname = _stattic_config_value('SPACEFAST_MANAGEMENT_HOSTNAME');
            $mailUrl = $boxHostname === '' ? '' : 'https://' . $boxHostname . STATTIC_SPACE_MAIL_ENDPOINT_PATH;
        }
        $mailGrant = _stattic_space_mail_mint_grant(
            $privateRoot,
            $spaceId,
            $emailDomain,
            $emailLimit
        );
        if ($mailUrl !== '' && $mailGrant !== null) {
            $env['SPACEFAST_SERVICE_EMAIL_URL'] = $mailUrl;
            $env['SPACEFAST_SERVICE_EMAIL_GRANT'] = $mailGrant;
        }
    }
    return $env;
}

function _stattic_zero_runner_base_env(array $config = []): array
{
    $env = [];
    // The runner mints its own RFC 9457 problem documents, so it needs the same
    // brand document the pages resolve. Forwarded only when this site overrides
    // it: absent, the runner's compiled-in defaults already agree with ours.
    require_once __DIR__ . '/brand.php';
    $brand = _stattic_brand_config_env();
    if ($brand !== '') {
        $env[STATTIC_BRAND_CONFIG_KEY] = $brand;
    }
    // DATABASE_URL travels under a reserved name with explicit provenance so
    // the runner can tell an application URL from the provider database and
    // reject ambient configuration.
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
    $shape = _stattic_route_match_shape($pattern);
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
function _stattic_route_match_shape(string $pattern): array
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
 * false = a shard this host may live in exists but could not be read. The
 * host's existence is UNKNOWN and must never collapse into "undeployed".
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

// D49: `*` matches exactly one label, and the pattern with the most literal
// labels wins. `*.a.example` and `*.*.example` both match `x.a.example`; the
// first one takes it.
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

// false = the overlay artifact exists but could not be read. The caller must
// answer unavailable, not denied: a failure is not an access decision.
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

// The Space's live production surface, or one of the surfaces that stand in
// front of it. Same three-way split `_stattic_grant_target` makes in
// access-rules.php: an immutable version host is a preview, a host following a
// route other than `production` is a branch, and everything else is live.
//
// Which matters to anything the edge answers on the Space's behalf: the WP
// Cloud edge holds rules scoped to the PRODUCTION hostnames only, so those are
// the hosts where the origin can stand back. Version and branch hosts serve
// other content and keep the full runtime ruleset.
function _stattic_serving_is_production_host(array $serving): bool
{
    // No serving context is no evidence. Answering "production" here would let
    // a caller that never resolved a host drop rules the edge may not hold.
    if ($serving === [] || !empty($serving['immutable'])) {
        return false;
    }
    $routeName = is_string($serving['route_name'] ?? null) ? $serving['route_name'] : '';
    return $routeName === '' || $routeName === 'production';
}

/**
 * Whether the WP Cloud edge answers this version's placed rules for THIS
 * request, which is the one condition under which the origin may leave them
 * alone.
 *
 * Two facts, both required. The surface has to be the live production one. And
 * the request host has to be one the edge was actually told about: the placed
 * rules were scoped, at finalize, to the production hostnames the Space had
 * then, and that scope is frozen in the version's artifact. A domain attached
 * afterwards serves the same live route but holds none of those rules at the
 * edge, so the origin keeps answering them for it until a republish widens the
 * scope. Erring this way costs an answer the edge would have given; erring the
 * other way loses the rule outright.
 *
 * @param list<mixed> $placedHostnames the version's edge scope, empty when nothing was placed
 */
function _stattic_edge_owns_placed_rules(array $serving, array $placedHostnames, string $requestHost): bool
{
    if ($placedHostnames === [] || !_stattic_serving_is_production_host($serving)) {
        return false;
    }
    $host = _stattic_normalize_hostname($requestHost);
    foreach ($placedHostnames as $hostname) {
        if (is_string($hostname) && _stattic_normalize_hostname($hostname) === $host) {
            return true;
        }
    }
    return false;
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
// include failed (I/O). Failures are never memoized, so the next call re-reads.
// Callers must route false to the unavailable 503, never fold it into the
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
        // Without this the sfv2_/sfa1_ HMAC key cannot be derived
        // (access-rules.php `_stattic_access_session_hmac_key` reads
        // `accessPage.exchange.credential`), so a protected Space could neither
        // mint nor verify a session: permanently deny-only. The overlay carries
        // the descriptor verbatim under `access_page`; access-rules.php
        // re-validates every field before use.
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
        'users' => is_array($overlay['users'] ?? null) ? $overlay['users'] : [],
        // Read straight off the overlay, not through the authorization
        // projection: that projection is null for a Space with no grants, which
        // is most of them, and egress scope must answer for those too.
        'space_claimed' => ($overlay['space_claimed'] ?? null) === true,
        'visitor_issuer' => is_string($exchange['issuer'] ?? null) ? $exchange['issuer'] : null,
        'visitor_jwks' => is_array($exchange['jwks'] ?? null) ? $exchange['jwks'] : null,
        'ownership_revision' => $overlay['ownership_revision'] ?? null,
        'zero_generation' => $overlay['zero_generation'] ?? 'none',
        'zero_generation_set_at' => $overlay['zero_generation_set_at'] ?? null,
        'live_hostname' => $overlay['live_hostname'] ?? null,
        'projection_generation' => is_int($overlay['access_gen'] ?? null) ? $overlay['access_gen'] : null,
        'billing' => is_array($overlay['billing'] ?? null) ? $overlay['billing'] : [],
        'email' => is_array($overlay['email'] ?? null) ? $overlay['email'] : null,
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
        // come from here. There is no second SDK artifact and no serve-time
        // configuration call back to the control plane.
        'sdk' => is_array($overlay['sdk'] ?? null) && is_array($overlay['sdk']['config'] ?? null)
            ? $overlay['sdk']
            : null,
    ];
}
