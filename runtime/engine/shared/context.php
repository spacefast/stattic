<?php
declare(strict_types=1);

// Every HTTP entrypoint loads this module. Reassert the runtime's logging
// policy after any provider bootstrap: all PHP severities reach the configured
// error log, while raw diagnostics never become part of a customer response.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

function _stattic_runtime_php_version_supported(int $versionId): bool
{
    return $versionId >= 80500 && $versionId < 80600;
}

if (!_stattic_runtime_php_version_supported(PHP_VERSION_ID)) {
    error_log('Spacefast runtime requires PHP 8.5; running PHP ' . PHP_VERSION);
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit(1);
}

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/finalizer-protocol.generated.php';

const STATTIC_RUNTIME_SCHEMA = 'static-runtime-v4';
const SPACEFAST_RUNTIME_ENGINE_VERSION = 'static-runtime-v2';
const SPACEFAST_RUNTIME_ENGINE_REVISION = 'source-tree';

function _stattic_runtime_install_root(string $engineRoot): string
{
    $releaseRoot = dirname($engineRoot);
    $releasesRoot = dirname($releaseRoot);
    return basename($releasesRoot) === 'releases' ? dirname($releasesRoot) : $releaseRoot;
}
const STATTIC_RUNTIME_NAMESPACE_PATH = '/__spacefast';
const STATTIC_RUNTIME_VISITOR_NAMESPACE_PATH = '/__sf';
// Security boundary: only these files may be direct-executed. The drift guard
// proves this region matches the manifest; it cannot see a second table added
// outside the markers, so widen the manifest, never the region.
// BEGIN GENERATED runtime entrypoints — DO NOT EDIT
// Source: runtime/engine-manifest.json (aliases under __spacefast/).
// Regenerate: bun run check:runtime-entrypoints -- --write
const STATTIC_RUNTIME_MANAGEMENT_API_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/api.php';
const STATTIC_RUNTIME_CONTENT_ADMIN_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/content-admin.php';
const STATTIC_RUNTIME_CONTENT_API_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/content.php';
const STATTIC_RUNTIME_UPLOAD_API_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/upload.php';
const SPACEFAST_RUNTIME_ENTRYPOINT_PATHS = [
    STATTIC_RUNTIME_NAMESPACE_PATH . '/api.php' => true,
    STATTIC_RUNTIME_NAMESPACE_PATH . '/content-admin.php' => true,
    STATTIC_RUNTIME_NAMESPACE_PATH . '/content.php' => true,
    STATTIC_RUNTIME_NAMESPACE_PATH . '/health.php' => true,
    STATTIC_RUNTIME_NAMESPACE_PATH . '/upload.php' => true,
];
// END GENERATED runtime entrypoints
const STATTIC_SPACEFAST_SDK_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/sdk.js';
const STATTIC_COMMENTS_CONFIG_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/comments/config';
const STATTIC_COMMENTS_TICKET_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/comments/ticket';
const STATTIC_ZERO_REALTIME_TICKET_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/realtime-ticket';
// The canonical Zero control namespace. `/__spacefast/zero/*` is served forever
// as an alias: frozen capsule clients baked those paths at build time and a
// republish cannot be assumed. Registration covers both prefixes; every
// emission (config JSON, invocation descriptors) uses the canonical one.
const STATTIC_ZERO_CANONICAL_NAMESPACE_PATH = '/__zero';
const STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH = STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/realtime-ticket';
const STATTIC_COMMENTS_VERSION_URLS_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/comments/version-urls';
// Mirrors COLLAB_VERSION_URLS_MAX_IDS in packages/common.
const STATTIC_COMMENTS_VERSION_URLS_MAX_IDS = 50;
// Recoleta is the only downloaded platform page face; body and mono are system
// stacks. It is commercial, never shipped in the engine, and loads from
// wordpress.com's font CDN, so every space hostname reuses one warm
// browser-cache entry. Mirrors packages/common/src/utils/page-fonts.ts.
// url => [family, weight, preload]; emission order is CSS order, and preload
// marks the face the pages render (Recoleta 400 headings).
const STATTIC_PLATFORM_PAGE_FONTS = [
    'https://wordpress.com/i/fonts/recoleta/300.woff2' => ['Recoleta', '300', false],
    'https://wordpress.com/i/fonts/recoleta/400.woff2' => ['Recoleta', '400', true],
    'https://wordpress.com/i/fonts/recoleta/500.woff2' => ['Recoleta', '500', false],
    'https://wordpress.com/i/fonts/recoleta/600.woff2' => ['Recoleta', '600', false],
    'https://wordpress.com/i/fonts/recoleta/700.woff2' => ['Recoleta', '700', false],
];
const STATTIC_TAG_PREVIEW_QUERY_NAME = 'spacefast_tag_preview';
// Mirrors PAGE_PREVIEW_QUERY in packages/common.
const STATTIC_PAGE_PREVIEW_QUERY_NAME = 'spacefast_view';

// Wire contract: the compiler bakes this set into artifacts, the validators
// compare with `===`, and the serving lane answers 405 with it. Widening one
// side alone breaks the others.
const STATTIC_VISITOR_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

// `X-Spacefast-Runtime: 1` is emitted from init.php before ANY gate can exit, so
// it rides every response shape including denials. On a denial it is the only
// thing separating our access wall from an upstream proxy/CDN 401.
function _stattic_emit_runtime_identity(?string $versionId = null): void
{
    header('X-Spacefast-Runtime: 1', true);
    if (is_string($versionId) && $versionId !== '') {
        header('X-Spacefast-Version: ' . $versionId, true);
    }
}

// The __Host- prefix forbids Domain and requires Secure + Path=/ in conforming
// browsers; the dev name exists because __Host- requires Secure.
const STATTIC_SESSION_COOKIE = '__Host-spacefast_session';
const STATTIC_SESSION_DEV_COOKIE = 'spacefast_session_dev';
// System view URLs briefly echo their already host-bound JWT into a separate
// host-only cookie so a renderer can fetch the page's same-origin subresources.
// This is not a visitor session: it has no server record and is re-verified on
// every request against the token's Space, host, generation, scope and expiry.
const STATTIC_SYSTEM_VIEW_COOKIE = '__Host-spacefast_system_view';
const STATTIC_SYSTEM_VIEW_DEV_COOKIE = 'spacefast_system_view_dev';
const STATTIC_SYSTEM_VIEW_COOKIE_SECONDS = 5 * 60;
// An origin-bound Frame proof is a CHIPS cookie. It never enters the ordinary
// first-party visitor or system-view cookie namespaces.
const STATTIC_FRAME_SESSION_COOKIE = '__Host-spacefast_frame';
const STATTIC_FRAME_SESSION_DEV_COOKIE = 'spacefast_frame_dev';
// `?__=` is THE access lane: present a token on the page you want and that page
// is the response. Every token that may ride it names itself with a prefix, so
// dispatch is a table lookup and an unclaimed token is refused by name instead
// of falling through to the gate. /__/<token> is the older reserved-path form,
// kept for links minted before the prefixes existed and for the space-key door.
const STATTIC_ACCESS_QUERY_TOKEN_PARAM = '__';
const STATTIC_ACCESS_ENTRY_PREFIX = '/__/';
// A customer share link: an opaque durable secret, redeemed with the control
// plane. Mirrors ACCESS_LINK_TOKEN_PREFIX in
// apps/control-plane/src/access/share-links.ts.
const STATTIC_ACCESS_QUERY_TOKEN_LINK_PREFIX = 'sfl_';
// A space key: the same opaque-secret lane, and the same exchange route reads
// it. Mirrors CLAIM_TOKEN_PREFIX in apps/control-plane/src/spaces/claim-token.ts.
const STATTIC_ACCESS_QUERY_TOKEN_SPACE_KEY_PREFIX = 'sfc_';
// A platform system view token: an Ed25519 JWT verified locally against the
// Space's own keys, never exchanged. Mirrors SYSTEM_VIEW_TOKEN_PREFIX in
// apps/control-plane/src/access/system-view-token.ts.
const STATTIC_ACCESS_QUERY_TOKEN_SYSTEM_VIEW_PREFIX = 'sfv_';
// One short-lived origin-bound Frame session, signed by the platform and
// verified locally against the Space's own keys. Mirrors
// FRAME_SESSION_TOKEN_PREFIX in apps/control-plane/src/access/frame-session-token.ts.
const STATTIC_ACCESS_QUERY_TOKEN_FRAME_SESSION_PREFIX = 'sff_';
// The Collab frame shell. Short and shareable because this URL IS the review
// link people paste; it sits inside the token-entry prefix and therefore ahead
// of it in the table below. The TS mirror is RUNTIME_COLLAB_FRAME_PATH in
// packages/common/src/utils/runtime-paths.ts.
const SPACEFAST_COLLAB_FRAME_PATH = '/__/collab';
// The Space's own review room, when it publishes one. The source template is
// private like every `_pages` file; this URL answers with the document finalize
// compiled from it. The TS mirror is COLLAB_PAGE_PATH in
// packages/common/src/contracts/pages.ts.
const SPACEFAST_COLLAB_PAGE_PATH = '/_pages/collab.html';
const STATTIC_ACCESS_LOGOUT_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/logout';
const STATTIC_ACCESS_PASSWORD_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/password';
const STATTIC_ACCESS_EMAIL_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/email';
const STATTIC_ACCESS_REQUEST_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/request';
const STATTIC_ACCESS_CLIENT_SCRIPT_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/client.js';
// The namespace is private-by-default: a Zero control route missing from this
// table silently 403s at the front door (init.php).
const STATTIC_ZERO_CONTROL_ROUTES = [
    '__zero/config' => ['operation' => 'config', 'methods' => ['GET', 'HEAD']],
    '__zero/run' => ['operation' => 'run', 'methods' => ['POST']],
    '__zero/auth/start' => ['operation' => 'auth_start', 'methods' => ['GET', 'HEAD']],
    '__zero/auth/sign-out' => ['operation' => 'auth_sign_out', 'methods' => ['GET', 'HEAD']],
    '__zero/realtime/events' => ['operation' => 'realtime_events', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/config' => ['operation' => 'config', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/run' => ['operation' => 'run', 'methods' => ['POST']],
    '__spacefast/zero/auth/gravatar/start' => ['operation' => 'auth_start', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/auth/sign-out' => ['operation' => 'auth_sign_out', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/realtime/events' => ['operation' => 'realtime_events', 'methods' => ['GET', 'HEAD']],
];

// Backed by no file under the publish root, so the namespace's
// private-by-default rule 403s them unless named here.
const STATTIC_FUNCTIONS_BUNDLE_PREFIX = '__spacefast/functions/b/';
const STATTIC_FUNCTIONS_RELAY_PATH = '__spacefast/functions/relay';
const STATTIC_FUNCTIONS_LOGS_PATH = '__spacefast/functions/logs';
const STATTIC_FUNCTIONS_PURGE_PATH = '__spacefast/functions/purge';

// The ONE stable visitor URL of a public uploads-store object.
const STATTIC_UPLOADS_PUBLIC_URL_PREFIX = '/__stattic/u/';

// Runtime-owned files keep their existing physical layout inside a version.
// They are implementation state, regardless of any catalog visibility bit.
function _stattic_path_is_internal_artifact(string $path): bool
{
    $normalized = trim($path, '/');
    $foldedPlatformPath = strtolower($normalized);
    return $normalized === 'zero'
        || str_starts_with($normalized, 'zero/')
        || $foldedPlatformPath === '__spacefast'
        || str_starts_with($foldedPlatformPath, '__spacefast/');
}

// Every platform control path, once: which exist, whether the visitor lane
// admits them at the front door (init.php), whether a tenant may publish under
// them (admin/generate.php, Functions dispatch), and which handler answers.
// Ordered: the walk takes the first matching row whose `admit` passes, so
// specific rows precede the namespace they sit in, and `entry`-stage order IS
// serve.php's dispatch ladder order.
// `match`: exact | prefix | namespace (whole-segment, so `/__spanish/page` is a
// tenant path and `/__span/x` is ours). `fold` compares case-insensitively.
// `admit` narrows a row to the exact set its handler can answer.
const SPACEFAST_CONTROL_PATHS = [
    ['path' => '/__stattic_probe', 'match' => 'exact', 'visitor' => true, 'tenant' => true, 'stage' => 'probe', 'handler' => 'probe'],
    ['path' => STATTIC_RUNTIME_VISITOR_NAMESPACE_PATH . '/redeem', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_callback'],
    ['path' => STATTIC_ACCESS_CLIENT_SCRIPT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_client_script'],
    ['path' => STATTIC_ACCESS_LOGOUT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_logout'],
    ['path' => STATTIC_ACCESS_PASSWORD_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_password'],
    ['path' => STATTIC_ACCESS_EMAIL_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_email'],
    ['path' => STATTIC_ACCESS_REQUEST_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_request'],
    ['path' => STATTIC_COMMENTS_CONFIG_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_COMMENTS_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_COMMENTS_VERSION_URLS_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_ZERO_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    // Reserved (tenant false) so no publisher can shadow the review link, and
    // ahead of the token-entry prefix it sits inside, because first match wins.
    // serve.php dispatches it AFTER the access check: the shell's bytes vary by
    // Space and must ride the host session that check just minted.
    ['path' => SPACEFAST_COLLAB_FRAME_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'frame', 'handler' => 'collab_frame'],
    ['path' => STATTIC_ACCESS_ENTRY_PREFIX, 'match' => 'prefix', 'admit' => '_stattic_access_entry_token', 'visitor' => true, 'tenant' => true, 'stage' => 'entry', 'handler' => 'access_link_entry'],
    ['path' => STATTIC_SPACEFAST_SDK_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    // Each is authorized by its own signed token rather than by being a known path.
    ['path' => '/' . STATTIC_FUNCTIONS_BUNDLE_PREFIX, 'match' => 'prefix', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_artifact'],
    ['path' => '/' . STATTIC_FUNCTIONS_RELAY_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_relay'],
    ['path' => '/' . STATTIC_FUNCTIONS_LOGS_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_logs'],
    ['path' => '/' . STATTIC_FUNCTIONS_PURGE_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_purge'],
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_is_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/zero', 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_is_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],
    // Unknown `/__zero/*` paths refuse exactly like unknown `/__spacefast/*`:
    // the namespace is private-by-default on both spellings.
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_RUNTIME_VISITOR_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    // An entry-stage row so the front door admits it and no tenant can publish
    // over it; serve.php dispatches it AFTER the Space access check. Must precede
    // the '/__stattic' catch-all below.
    ['path' => STATTIC_UPLOADS_PUBLIC_URL_PREFIX, 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'uploads_object'],
    // No visitor route lives here, but a tenant must not own them either.
    ['path' => '/__stattic', 'match' => 'namespace', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__span', 'match' => 'namespace', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
];

function _stattic_control_path_is_zero_route(string $path): bool
{
    return isset(STATTIC_ZERO_CONTROL_ROUTES[trim(strtolower($path), '/')]);
}

// Response tables compiled before the /__zero cutover carry only the legacy
// spellings, and a version is immutable: the serve ladder folds a canonical
// control path to its legacy twin when the table has no canonical row.
function _stattic_zero_legacy_control_path(string $path): ?string
{
    $normalized = '/' . trim(strtolower($path), '/');
    if ($normalized === STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/auth/start') {
        return STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/auth/gravatar/start';
    }
    if (str_starts_with($normalized, STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/')) {
        return STATTIC_RUNTIME_NAMESPACE_PATH . '/zero'
            . substr($normalized, strlen(STATTIC_ZERO_CANONICAL_NAMESPACE_PATH));
    }
    return null;
}

function _stattic_control_path_row(string $path): ?array
{
    // Fold before the fast-exit (a raw '/__' prefix survives folding, so the
    // folded check covers both): '//__' spellings must reach the fold-enabled
    // rows exactly like their canonical spelling.
    $folded = '/' . trim(strtolower($path), '/');
    if (!str_starts_with($folded, '/__')) {
        return null;
    }
    foreach (SPACEFAST_CONTROL_PATHS as $row) {
        $subject = empty($row['fold']) ? $path : $folded;
        $target = $row['path'];
        $matches = match ($row['match']) {
            'exact' => $subject === $target,
            'prefix' => str_starts_with($subject, $target),
            'namespace' => $subject === $target || str_starts_with($subject, $target . '/'),
        };
        if (!$matches) {
            continue;
        }
        $admit = $row['admit'] ?? null;
        if ($admit !== null) {
            $verdict = $admit($path);
            if ($verdict === null || $verdict === false) {
                continue;
            }
        }
        return $row;
    }
    return null;
}

// "Reaches the handler" only. Each surface refuses without a valid signature.
function _stattic_control_path_admits_visitor(string $path): bool
{
    $row = _stattic_control_path_row($path);
    return $row !== null && $row['visitor'] === true;
}

function _stattic_control_path_namespace_is_private(string $path): bool
{
    // Fold BEFORE the fast-exit: '//__spacefast' spellings must answer exactly
    // like '/__spacefast', or the early return becomes a probe oracle for
    // which encoded spellings the runtime recognizes as private.
    $folded = '/' . trim(strtolower($path), '/');
    // Every private-namespace row is '/__'-prefixed; the ordinary visitor path
    // exits on one comparison instead of walking the table.
    if (!str_starts_with($folded, '/__')) {
        return false;
    }
    return array_any(SPACEFAST_CONTROL_PATHS, static fn (array $row): bool =>
        $row['match'] === 'namespace'
        && $row['visitor'] === false
        && ($folded === $row['path'] || str_starts_with($folded, $row['path'] . '/')));
}

function _stattic_path_is_reserved(string $path): bool
{
    $row = _stattic_control_path_row($path);
    return $row !== null && $row['tenant'] === false;
}

// serve.php's control ladder: the entry-stage rows, in table order.
function _stattic_control_path_entry_handler(string $path): ?string
{
    $row = _stattic_control_path_row($path);
    return $row !== null && ($row['stage'] ?? null) === 'entry' ? $row['handler'] : null;
}

// Secure ALWAYS: never derive it from request signals like X-Forwarded-Proto,
// which are stripped when SPACEFAST_TRUSTED_EDGE_HEADERS is unset and would
// silently drop Secure on a genuinely-HTTPS site. The only escape is the
// dev/test flag SPACEFAST_INSECURE_COOKIES=1.
function _stattic_cookies_secure(): bool
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') !== '1';
}

function _stattic_set_cookie(
    string $name,
    string $value,
    int $maxAgeSeconds,
    bool $partitioned = false
): void {
    if ($name === _stattic_session_cookie_name()) {
        _stattic_identity_cookie_mutated(true);
    }
    $secure = _stattic_cookies_secure();
    $usePartitioned = $partitioned && $secure;
    $attributes = [
        $name . '=' . rawurlencode($value),
        'Path=/',
        'Max-Age=' . max(0, $maxAgeSeconds),
        'HttpOnly',
        'SameSite=' . ($usePartitioned ? 'None' : 'Lax'),
    ];
    if ($secure) {
        $attributes[] = 'Secure';
    }
    if ($usePartitioned) {
        $attributes[] = 'Partitioned';
    }
    // Appended, never replaced: the response may already carry another cookie.
    header('Set-Cookie: ' . implode('; ', $attributes), false);
    // Keep $_COOKIE coherent for same-request reads after a set.
    $_COOKIE[$name] = $value;
}

function _stattic_session_cookie_name(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_SESSION_DEV_COOKIE
        : STATTIC_SESSION_COOKIE;
}

// Resolved from the ORIGINAL request URI, never $_SERVER['QUERY_STRING']: route
// rewrites overwrite that, and every cache/forwarding decision below must see
// the same answer whatever the routing layer did.
function _stattic_access_query_token_state(): array
{
    static $state = null;
    if (is_array($state)) {
        return $state;
    }
    $state = ['present' => false, 'kind' => null, 'token' => null];
    $query = parse_url(_stattic_runtime_effective_request_uri(), PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return $state;
    }
    foreach (_stattic_runtime_query_pairs($query) as [$name, $rawValue]) {
        if ($name !== STATTIC_ACCESS_QUERY_TOKEN_PARAM) {
            continue;
        }
        $state['present'] = true;
        if ($state['kind'] !== null) {
            continue;
        }
        $value = rawurldecode(str_replace('+', '%20', $rawValue ?? ''));
        $classified = _stattic_access_query_token_classify($value);
        if ($classified !== null) {
            [$state['kind'], $state['token']] = $classified;
        }
    }
    return $state;
}

// The dispatch table. A prefix names the lane and the rest is that lane's token,
// already length- and alphabet-bounded so no lane parses an unbounded string.
// Null means no lane claims it, a refusal and never a fall-through: the sniffing
// this replaced silently sent customer share links to the access gate.
function _stattic_access_query_token_classify(string $value): ?array
{
    // `keep` says whether the lane's token still carries its prefix: the two
    // exchange lanes hand the control plane the whole secret (it keys on the
    // prefix too), while the JWT is verified here and its prefix is packaging.
    $lanes = [
        STATTIC_ACCESS_QUERY_TOKEN_LINK_PREFIX =>
            ['link', '/\A[A-Za-z0-9_-]{16,512}\z/', true],
        STATTIC_ACCESS_QUERY_TOKEN_SPACE_KEY_PREFIX =>
            ['link', '/\A[A-Za-z0-9_-]{16,512}\z/', true],
        STATTIC_ACCESS_QUERY_TOKEN_SYSTEM_VIEW_PREFIX => [
            'system-view',
            '/\A[A-Za-z0-9_-]{4,1024}\.[A-Za-z0-9_-]{4,4096}\.[A-Za-z0-9_-]{4,1024}\z/',
            false,
        ],
        STATTIC_ACCESS_QUERY_TOKEN_FRAME_SESSION_PREFIX => [
            'frame-session',
            '/\A[A-Za-z0-9_-]{4,1024}\.[A-Za-z0-9_-]{4,4096}\.[A-Za-z0-9_-]{4,1024}\z/',
            false,
        ],
    ];
    foreach ($lanes as $prefix => [$kind, $pattern, $keep]) {
        if (!str_starts_with($value, $prefix)) {
            continue;
        }
        $body = substr($value, strlen($prefix));
        return preg_match($pattern, $body) === 1 ? [$kind, $keep ? $value : $body] : null;
    }
    return null;
}

// Presence, not validity: a malformed token serves like none, but the response
// still carries the secret in its URL and must stay out of every shared cache.
function _stattic_access_query_token_present(): bool
{
    return _stattic_access_query_token_state()['present'];
}

function _stattic_access_query_token(): ?string
{
    return _stattic_access_query_token_state()['token'];
}

// Which lane the presented token named, or null when the parameter is absent or
// names no lane. The two are deliberately different answers: the second is a
// refusal a serving path has to render, not a request that carried nothing.
function _stattic_access_query_token_kind(): ?string
{
    return _stattic_access_query_token_state()['kind'];
}

// Publisher upstreams and rewritten QUERY_STRING values must never carry the
// visitor's link secret.
function _stattic_strip_access_query_token(string $query): string
{
    if ($query === '' || !str_contains($query, STATTIC_ACCESS_QUERY_TOKEN_PARAM)) {
        return $query;
    }
    $kept = [];
    foreach (_stattic_runtime_query_pairs($query) as [$name, , $rawPart]) {
        if ($name === STATTIC_ACCESS_QUERY_TOKEN_PARAM) {
            continue;
        }
        $kept[] = $rawPart;
    }
    return implode('&', $kept);
}

// THE scrubber for anything that records a URL: journals, telemetry, error
// bodies, a relayed request line. A share token is a durable secret and a log is
// durable storage, so a record that keeps one has published it. Both spellings
// carry the same secret: the `?__=` value and the `/__/<token>` path segment.
// Secrets are replaced, never shortened away, so a record still shows that a
// token was presented.
function _stattic_redact_access_secrets(string $uri): string
{
    $redacted = preg_replace(
        '~(^|[?&])' . preg_quote(STATTIC_ACCESS_QUERY_TOKEN_PARAM, '~') . '=[^&#]*~',
        '$1' . STATTIC_ACCESS_QUERY_TOKEN_PARAM . '=[redacted]',
        $uri
    );
    $uri = is_string($redacted) ? $redacted : $uri;
    // The frame shell shares the prefix and is not a secret; everything else
    // under it is one.
    $redacted = preg_replace(
        '~^' . preg_quote(STATTIC_ACCESS_ENTRY_PREFIX, '~') . '(?!collab([/?#]|$))[^/?#]+~',
        STATTIC_ACCESS_ENTRY_PREFIX . '[redacted]',
        $uri
    );
    return is_string($redacted) ? $redacted : $uri;
}

function _stattic_clear_cookie(string $name, bool $partitioned = false): void
{
    // Deletion must match the partitioned namespace used by framed previews.
    _stattic_set_cookie($name, '', 0, $partitioned);
    unset($_COOKIE[$name]);
}

// A live wp.cloud probe (2026-08-07) proved X-Real-IP, CF-Connecting-IP and
// X-Forwarded-Host reach PHP attacker-controlled, and X-Forwarded-For has no
// written trust contract. There is no trusted-edge-headers mode: every
// spoofable identity/geo/IP header is stripped unconditionally.
function _stattic_strip_untrusted_edge_headers(): void
{
    foreach ([
        'HTTP_SPACEFAST_ACCESS_JWT',
        'HTTP_SPACEFAST_ACCESS_SUB',
        'HTTP_SPACEFAST_ACCESS_GRANTS',
        'HTTP_SPACEFAST_VISITOR_IP',
        'HTTP_CF_IPCOUNTRY',
        'HTTP_GEOIP_COUNTRY_CODE',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_HOST',
        // Asserted by the cron dispatcher and by nothing else. An inbound copy
        // is forged by definition, the same rule the relay applies to
        // Spacefast-Access-* identity forwarding.
        'HTTP_X_SPACEFAST_CRON',
    ] as $key) {
        unset($_SERVER[$key]);
    }
    // ...and then what THIS process staged goes back. Only a CLI entry can
    // populate this (entrypoints/cron.php), so downstream a name above is
    // present exactly when the engine synthesized the request.
    foreach (_stattic_platform_asserted_request_params() as $key => $value) {
        $_SERVER[$key] = $value;
    }
}

/**
 * Request params the ENGINE asserts, surviving the strip above.
 *
 * Staged once, before dispatch, by the process that synthesizes a request. A
 * request that arrived over the wire never sets these, so the strip is total for
 * every visitor and total for every lane.
 *
 * @param array<string,string>|null $set
 * @return array<string,string>
 */
function _stattic_platform_asserted_request_params(?array $set = null): array
{
    static $params = [];
    if ($set !== null) {
        $params = $set;
    }
    return $params;
}

/**
 * A request body staged by a non-CGI entrypoint. PHP CLI exposes piped bytes as
 * `php://stdin`, while every visitor lane correctly reads `php://input`; this
 * one override lets the shared dispatch keep using visitor semantics.
 */
function _stattic_request_body_override(?string $set = null): ?string
{
    static $override = null;
    if ($set !== null) {
        $override = $set;
    }
    return $override;
}

function _stattic_request_body_contents(?int $limit = null): string|false
{
    $override = _stattic_request_body_override();
    if ($override !== null) {
        return $limit === null ? $override : substr($override, 0, $limit);
    }
    return $limit === null
        ? file_get_contents('php://input')
        : file_get_contents('php://input', false, null, 0, $limit);
}

// Declared-then-read bound: an over-declared body is refused before the read,
// an under-declared one after it.
function _stattic_bounded_request_body(int $limit): ?string
{
    $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($declared > $limit) {
        return null;
    }
    $body = _stattic_request_body_contents($limit + 1);
    if ($body === false || strlen($body) > $limit) {
        return null;
    }
    return $body;
}

/** @return resource|false */
function _stattic_request_body_stream()
{
    $override = _stattic_request_body_override();
    if ($override === null) {
        return fopen('php://input', 'rb');
    }
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return false;
    }
    fwrite($stream, $override);
    rewind($stream);
    return $stream;
}

// Hand-mirrored by the control-plane generator (admin/generate.php) onto baked
// artifacts. Keep the two byte-identical when tuning it.
const STATTIC_DEFAULT_EDGE_CACHE_CONTROL = 'public, max-age=0, s-maxage=600, stale-while-revalidate=60';
const STATTIC_CACHE_CONTROL_NO_STORE = 'no-store';
// Protected and request-bound bytes are never retained by the browser, edge or
// WordPress page cache. Authorization and revocation therefore run every time.
const STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE = 'private, no-store';
const STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS = ['cache-control', 'expires', 'age'];

// Platform-owned on a private response, even though their public-response
// counterparts stay publisher-controlled.
const STATTIC_PRIVATE_CONTENT_SUPPRESSED_HEADERS = [
    'access-control-allow-origin',
    'cross-origin-resource-policy',
    'vary',
    'x-robots-tag',
];

function _stattic_private_content_vary(string $existing = ''): string
{
    $values = [];
    foreach (explode(',', $existing) as $value) {
        $trimmed = trim($value);
        if ($trimmed !== '' && $trimmed !== '*' && !isset($values[strtolower($trimmed)])) {
            $values[strtolower($trimmed)] = $trimmed;
        }
    }
    $values['cookie'] = 'Cookie';
    return implode(', ', array_values($values));
}

/**
 * Platform-owned request headers that selected this response representation.
 * The provider cache still cannot key on Vary, so conditional routes remain
 * no-store; this declaration is for downstream HTTP caches and clients.
 *
 * @param list<string>|null $add
 * @return list<string>
 */
function _stattic_platform_vary_headers(?array $add = null): array
{
    static $headers = [];
    foreach ($add ?? [] as $name) {
        $trimmed = trim((string) $name);
        if ($trimmed !== '' && !isset($headers[strtolower($trimmed)])) {
            $headers[strtolower($trimmed)] = $trimmed;
        }
    }
    return array_values($headers);
}

/**
 * The origin a verified system-view `embed` proof nominated as its framer, or
 * null.
 *
 * Set only from a signed claim (access-rules.php verifies it), never from a
 * request header: the whole point is that a private Space cannot be framed by
 * whoever asks. It lives here rather than beside the rest of the system-view
 * lane because both readers are here — the private-content CSP below and the
 * partitioned echo cookie `_stattic_set_cookie` writes — and context.php is
 * loaded on every request while access-rules.php is not.
 */
function _stattic_system_view_embed(?string $origin = null): ?string
{
    static $admitted = null;
    if ($origin !== null) {
        $admitted = $origin;
    }
    return $admitted;
}

/**
 * The ONE decision about which origins may frame a Space's private content.
 *
 * `'self'` is the main path: the Collab shell at `/__/collab` and the page it
 * frames are the same origin, so a Space behind an access gate can review its
 * own work. The Space's live origin is the one OTHER origin the runtime can
 * PROVE belongs to this same Space. An immutable version host is framed from the
 * live Space for time travel, and both hosts serve this Space under one
 * authorization projection, so the live origin is already trusted with these
 * bytes and its publisher already controls them.
 *
 * The remaining entries only ever arrive proven, per request: the parents the
 * Space's own Link projection admitted for this response (the Frame verifier
 * and the public Link projection are the only writers), and a system-view
 * proof minted with an `embed` origin (a dashboard preview session) naming the
 * one framer the platform vouched for, for that request alone.
 *
 * Nothing here is caller-stated. A Space with no live origin, an overlay that
 * states one in a form that is not an origin, or a request carrying no framing
 * proof each contribute nothing and land on `'self'`, never on a value someone
 * else chose.
 */
function _stattic_space_frame_ancestors(): string
{
    $serving = is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null)
        ? $GLOBALS['SPACEFAST_PAGE_SERVING']
        : [];
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    $config = is_array($sdk['config'] ?? null) ? $sdk['config'] : [];
    $comments = is_array($config['comments'] ?? null) ? $config['comments'] : [];
    $origins = ["'self'"];
    // The embed claim is already an origin by the time it lands here (a claim
    // that is not one fails the whole token), so nothing unvalidated can reach
    // the header.
    foreach ([
        _stattic_absolute_url_origin($comments['live_url'] ?? null),
        ...(function_exists('_stattic_frame_ancestor_origins')
            ? _stattic_frame_ancestor_origins()
            : []),
        _stattic_system_view_embed(),
    ] as $origin) {
        if (is_string($origin) && $origin !== '' && !in_array($origin, $origins, true)) {
            $origins[] = $origin;
        }
    }
    return implode(' ', $origins);
}

/**
 * `scheme://host[:port]` for an absolute http(s) URL, or null.
 *
 * Null is a refusal, never a fallback. A value that is not an origin must not
 * reach a security header at all: a scheme-relative `//host/`, a `javascript:`
 * URL, anything carrying userinfo or a character that could close a CSP
 * directive.
 */
function _stattic_absolute_url_origin(mixed $url): ?string
{
    if (!is_string($url) || strlen($url) > 2048) {
        return null;
    }
    $parts = parse_url(trim($url));
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $ipv6 = str_starts_with($host, '[')
        && str_ends_with($host, ']')
        && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    if (
        ($scheme !== 'https' && $scheme !== 'http')
        || (preg_match('/\A[a-z0-9.-]+\z/', $host) !== 1 && !$ipv6)
    ) {
        return null;
    }
    $port = $parts['port'] ?? null;
    return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
}

function _stattic_private_content_csp(string $existing = ''): string
{
    $directives = [];
    foreach (explode(';', $existing) as $directive) {
        $trimmed = trim($directive);
        if ($trimmed === '' || strtolower(trim(explode(' ', $trimmed, 2)[0])) === 'frame-ancestors') {
            continue;
        }
        $directives[] = $trimmed;
    }
    $directives[] = 'frame-ancestors ' . _stattic_space_frame_ancestors();
    return implode('; ', $directives);
}

function _stattic_private_content_csp_values(array $existing): string
{
    $policies = [];
    // A comma-joined CSP field is a list of independent policies; each is
    // rewritten so none of them is discarded.
    foreach ($existing as $value) {
        foreach (explode(',', $value) as $policy) {
            $trimmed = trim($policy);
            if ($trimmed !== '') {
                $policies[] = $trimmed;
            }
        }
    }
    if ($policies === []) {
        $policies[] = '';
    }
    return implode(', ', array_map(_stattic_private_content_csp(...), $policies));
}

// The one shape: [name, value] lines, the only representation that survives a
// relay. The map form below is a projection of it.
function _stattic_private_content_response_header_lines(array $lines): array
{
    $vary = '';
    $csp = [];
    $out = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $name = (string) ($line[0] ?? '');
        $value = (string) ($line[1] ?? '');
        $lower = strtolower($name);
        if ($lower === 'vary') {
            $vary = $vary === '' ? $value : $vary . ', ' . $value;
            continue;
        }
        if ($lower === 'content-security-policy') {
            if (trim($value) !== '') {
                $csp[] = trim($value);
            }
            continue;
        }
        if (in_array($lower, STATTIC_PRIVATE_CONTENT_SUPPRESSED_HEADERS, true)) {
            continue;
        }
        // The URL is the secret on a tokened request, and Referer would hand it
        // to every third-party asset the page loads. The publisher's own policy
        // does not get to widen that.
        if ($lower === 'referrer-policy' && _stattic_access_query_token_present()) {
            continue;
        }
        $out[] = [$name, $value];
    }
    if (_stattic_access_query_token_present()) {
        $out[] = ['Referrer-Policy', 'no-referrer'];
    }
    $out[] = ['Vary', _stattic_private_content_vary($vary)];
    $out[] = ['X-Robots-Tag', 'noindex, nofollow'];
    $out[] = ['Cross-Origin-Resource-Policy', 'same-origin'];
    $out[] = ['Content-Security-Policy', _stattic_private_content_csp_values($csp)];
    return $out;
}

function _stattic_private_content_response_headers(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = [(string) $name, is_scalar($value) ? (string) $value : ''];
    }
    $out = [];
    foreach (_stattic_private_content_response_header_lines($lines) as [$name, $value]) {
        $out[$name] = $value;
    }
    return $out;
}

function _stattic_clear_private_content_response_headers(): void
{
    foreach (STATTIC_PRIVATE_CONTENT_SUPPRESSED_HEADERS as $name) {
        header_remove($name);
    }
    header_remove('Content-Security-Policy');
}

// Sticky per-request "must not be shared" verdict; once true it stays true. A
// URL carrying a share-link token is unconditionally in that class.
function _stattic_access_private_cache_flag(?bool $set = null): bool
{
    static $private = false;
    if ($set === true) {
        $private = true;
    }
    return $private || _stattic_access_query_token_present();
}

// A Set-Cookie response is stateful: this sticky marker stops a later public
// admission from making it eligible for shared storage.
function _stattic_identity_cookie_mutated(?bool $set = null): bool
{
    static $mutated = false;
    if ($set === true) {
        $mutated = true;
    }
    return $mutated;
}

// Set when a presented access cookie failed session verification and was cleared.
function _stattic_invalid_access_cookie_cleared(?bool $set = null): bool
{
    static $cleared = false;
    if ($set === true) {
        $cleared = true;
    }
    return $cleared;
}
// Lives here, not with its readers: shared/errors.php needs it and cannot
// require runtime/access-rules.php, since that dependency runs the other way.
function _stattic_access_private_root(?string $set = null): string
{
    if ($set !== null) {
        $GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT'] = $set;
    }
    return is_string($GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT'] ?? null)
        ? $GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT']
        : '';
}

// Every visitor-facing lane opens the same way, and must: edge headers stripped
// before any read of geo/proto/IP/Spacefast-Access-*, the private root bound,
// the flush intent declared, and the runtime identity on the wire before any
// gate can exit. Management/upload lanes pass false, because their
// fatal-envelope shutdown handlers still need to write a response.
function _stattic_visitor_lane_begin(string $privateRoot, bool $flushResponseBeforeDeferred = true): void
{
    _stattic_strip_untrusted_edge_headers();
    _stattic_flush_response_before_deferred($flushResponseBeforeDeferred);
    _stattic_access_private_root($privateRoot);
    _stattic_emit_runtime_identity();
}
// Lives here, not with the file server: shared/errors.php reads the same verdict
// and cannot require runtime/serve.php.
function _stattic_cache_control_has_directive(string $cacheControl, string $directive): bool
{
    $wanted = strtolower($directive);
    foreach (explode(',', $cacheControl) as $part) {
        $name = strtolower(trim(explode('=', trim($part), 2)[0] ?? ''));
        if ($name === $wanted) {
            return true;
        }
    }
    return false;
}

function _stattic_cache_control_allows_shared_store(string $cacheControl): bool
{
    return !_stattic_cache_control_has_directive($cacheControl, 'no-store')
        && !_stattic_cache_control_has_directive($cacheControl, 'no-cache')
        && !_stattic_cache_control_has_directive($cacheControl, 'private');
}

// --- provider-owned response headers (§16) ---------------------------------
//
// A8C-* is the platform's edge-cache control channel; x-ac/x-sc/x-nc and their
// diagnostic spellings are the provider's own; HSTS the provider rewrites. A
// publisher header map naming any of them is stripped at send time, on every
// lane: a tenant must never steer the edge that fronts every Space.
//
// The generated protocol owns the list the compiler also enforces; these two are
// the spellings it does not carry yet, kept separate so a regeneration cannot
// silently drop them.
const STATTIC_PLATFORM_OWNED_HEADER_PREFIXES_EXTRA = ['x-nananana', 'x-hacker', 'host-header'];
const STATTIC_PLATFORM_OWNED_HEADERS_EXTRA = ['strict-transport-security'];

const STATTIC_EDGE_CACHE_HEADER = 'a8c-edge-cache';
const SPACEFAST_EDGE_CACHE_OPT_IN = 'cache';
const SPACEFAST_EDGE_CACHE_OPT_OUT = 'no-cache';

function _stattic_platform_owns_header(string $name): bool
{
    $lower = strtolower(trim($name));
    return in_array($lower, STATTIC_RUNTIME_PLATFORM_OWNED_HEADERS, true)
        || in_array($lower, STATTIC_PLATFORM_OWNED_HEADERS_EXTRA, true)
        || array_any(
            [...STATTIC_RUNTIME_PLATFORM_OWNED_HEADER_PREFIXES, ...STATTIC_PLATFORM_OWNED_HEADER_PREFIXES_EXTRA],
            static fn (string $prefix): bool => str_starts_with($lower, $prefix)
        );
}

/**
 * @param array<string, mixed> $headers
 * @return array<string, mixed>
 */
function _stattic_strip_platform_owned_headers(array $headers): array
{
    foreach (array_keys($headers) as $name) {
        if (is_string($name) && _stattic_platform_owns_header($name)) {
            unset($headers[$name]);
        }
    }
    return $headers;
}

// The provider edge is METHOD-BLIND: it keys a stored response on
// host+path+query alone, so a stored answer to a POST/PUT/DELETE would be
// replayed to every later GET of the same URL. No response to a non-GET/HEAD
// request may therefore be storable by any cache, edge included: the URL alone
// does not say what the method did. The verdict lives here, beside the seams
// that compose platform response headers, so every lane inherits it
// structurally instead of re-deciding it.
function _stattic_request_method_forbids_shared_store(): bool
{
    return !in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true);
}

// §16: the edge stores a PHP-lane response only when it sees BOTH a public
// Cache-Control and this opt-in. Derived from the Cache-Control about to be
// sent rather than decided separately, so the two can never drift apart.
function _stattic_edge_cache_directive(?string $cacheControl): string
{
    // Method-blind edge (see above): a non-GET/HEAD response opts out no
    // matter what policy a worker, tenant rule or upstream composed.
    if (_stattic_request_method_forbids_shared_store()) {
        return SPACEFAST_EDGE_CACHE_OPT_OUT;
    }
    if (!is_string($cacheControl) || trim($cacheControl) === '') {
        return SPACEFAST_EDGE_CACHE_OPT_OUT;
    }
    return _stattic_cache_control_has_directive($cacheControl, 'public')
        && _stattic_cache_control_allows_shared_store($cacheControl)
            ? SPACEFAST_EDGE_CACHE_OPT_IN
            : SPACEFAST_EDGE_CACHE_OPT_OUT;
}

// A relayed upstream response can have emitted a provider header through
// header() long before the response is finished, so the strip has to reach the
// already-staged list, not just the map in hand.
function _stattic_clear_platform_owned_response_headers(): void
{
    if (headers_sent()) {
        return;
    }
    foreach (headers_list() as $line) {
        $name = trim((string) (explode(':', $line, 2)[0] ?? ''));
        if ($name !== '' && _stattic_platform_owns_header($name)) {
            header_remove($name);
        }
    }
}

// THE response-header emitter for file bodies and server-file handoffs, on
// every lane: publisher filtering and wp.cloud edge activation happen here, so
// no caller can emit a header map that skipped the platform policy. Set-Cookie
// appends; everything else replaces.
function _stattic_send_response_headers(array $headers): void
{
    header_remove('Pragma');
    header_remove('Expires');
    $headers = _stattic_apply_platform_header_policy($headers);
    foreach ($headers as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        if (strtolower($name) === 'set-cookie') {
            header($name . ': ' . (string) $value, false);
            continue;
        }
        header_remove($name);
        header($name . ': ' . (string) $value);
    }
}

/**
 * The one send-time transform every lane's header map passes through: strip what
 * the provider owns, from the map AND from anything already staged, then state
 * this response's edge intent.
 *
 * @param array<string, mixed> $headers
 * @return array<string, mixed>
 */
function _stattic_apply_platform_header_policy(array $headers, string $cacheControlKey = 'cache-control'): array
{
    $cacheControl = null;
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_scalar($value) && strtolower($name) === $cacheControlKey) {
            $cacheControl = (string) $value;
        }
    }
    // Method-blind edge (see _stattic_request_method_forbids_shared_store):
    // every response to a non-GET/HEAD request is pinned private no-store here,
    // where the map every lane sends passes through, regardless of what the
    // entry, a tenant rule or a worker composed above.
    if (_stattic_request_method_forbids_shared_store()) {
        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strtolower($name) === $cacheControlKey) {
                unset($headers[$name]);
            }
        }
        $headers[$cacheControlKey] = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
        $cacheControl = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }
    $headers = _stattic_strip_platform_owned_headers($headers);
    _stattic_clear_platform_owned_response_headers();
    $vary = _stattic_platform_vary_headers();
    if ($vary !== []) {
        $headers['Vary'] = implode(', ', $vary);
    }
    // A token in the URL makes the URL the secret, and Referer would hand it to
    // every third-party asset the page loads. Platform-owned for this request
    // only: the publisher's own policy applies on every untokened response.
    if (_stattic_access_query_token_present()) {
        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strtolower($name) === 'referrer-policy') {
                unset($headers[$name]);
            }
        }
        header_remove('Referrer-Policy');
        $headers['Referrer-Policy'] = 'no-referrer';
    }
    $headers[STATTIC_EDGE_CACHE_HEADER] = _stattic_edge_cache_directive($cacheControl);
    return $headers;
}

// Post-response work: runs in a shutdown handler, after fastcgi_finish_request()
// on lanes that opt in. Deferred work must be self-contained (capture eagerly,
// write lazily) and best-effort: failures are swallowed, never surfaced.
function &_stattic_deferred_work(): array
{
    static $queue = [];
    return $queue;
}

function _stattic_flush_response_before_deferred(?bool $set = null): bool
{
    static $flush = false;
    if ($set !== null) {
        $flush = $set;
    }
    return $flush;
}

function _stattic_defer(callable $work): void
{
    static $registered = false;
    $queue = &_stattic_deferred_work();
    $queue[] = $work;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function (): void {
        if (_stattic_flush_response_before_deferred() && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $queue = &_stattic_deferred_work();
        // Drain by shifting, not a snapshot foreach: work a deferred callback
        // itself defers (deferred-from-deferred) is appended to this same queue
        // and must run in the same shutdown pass, not be dropped.
        while ($queue !== []) {
            $work = array_shift($queue);
            try {
                $work();
            } catch (Throwable $error) {
                // Post-response: the visitor already has their bytes, but the
                // operator must still see why deferred work failed.
                error_log(sprintf(
                    'spacefast deferred work failed type=%s message=%s',
                    get_debug_type($error),
                    $error->getMessage(),
                ));
            }
        }
    });
}

// Private-storage layout: every layer builds these paths through here.
function _stattic_space_root(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/spaces/' . $spaceId;
}

function _stattic_version_root(string $privateRoot, string $spaceId, string $versionId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/versions/' . $versionId;
}

function _stattic_space_routes_root(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/routes';
}

function _stattic_route_pointer_path(string $privateRoot, string $spaceId, string $routeName): string
{
    return _stattic_space_routes_root($privateRoot, $spaceId) . '/' . $routeName . '.json';
}

// The write lock lives OUTSIDE the space tree deliberately: inside it,
// delete_space unlinks the lock file while a holder still has it open, the next
// request locks a FRESH inode, and two writers believe they hold the same lock.
// An in-flight publish then recreates a just-deleted Space. Never move it back
// under spaces/{spaceId}/.
function _stattic_space_write_lock_path(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/runtime/locks/spaces/' . $spaceId . '.lock';
}

// One id shape for space ids, version ids, route names and bucket ids: what
// management intake admits, the serve-path artifact loaders must be able to load.
function _stattic_id_valid(string $value): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1;
}

function _stattic_base64url_decode(string $value): string
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return is_string($decoded) ? $decoded : '';
}

function _stattic_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

// The one spelling of "a lowercase sha-256 digest": every CAS key, blob path
// and content digest check shares it.
function _stattic_is_sha256_hex(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
}

// The one spelling of a CAS blob's relative key: the local blob tree and the
// S3 object namespace both derive from it and can never disagree on layout.
function _stattic_blob_relative_key(string $spaceId, string $sha256): ?string
{
    $sha256 = strtolower(trim($sha256));
    if (!_stattic_id_valid($spaceId) || !_stattic_is_sha256_hex($sha256)) {
        return null;
    }
    return 'spaces/' . $spaceId . '/blobs/' . substr($sha256, 0, 2) . '/' . $sha256;
}

// Spec "Space Configuration Files": private after compile, never served. Shared
// by the compile-side exclusion (admin/generate.php) and the serve-side
// terminal-404 gate (runtime/serve.php); keys are canonical lowercase paths.
const STATTIC_PRIVATE_CONFIG_FILES = [
    'sf.jsonc' => true,
    'spacefast.jsonc' => true,
    'spacefast.json' => true,
    'sf.json' => true,
    '.sf/sf.json' => true,
    '.sf/config.jsonc' => true,
    '.sf/config.json' => true,
];
// Compile-input sidecars, same posture.
const STATTIC_PRIVATE_COMPILE_FILES = [
    '_redirects' => true,
    '_headers' => true,
    '_config.json' => true,
    '_routes.json' => true,
];

// Spec "Hidden Files": a dot-prefixed segment is private, except a root-level
// `.well-known`. Files inside it are public; dot-prefixed entries deeper are
// not. Callers pass the path pre-lowercased and slash-trimmed.
function _stattic_path_has_hidden_segment(string $lowerPath): bool
{
    return array_any(
        explode('/', $lowerPath),
        static fn (string $segment, int $index): bool =>
            !($index === 0 && $segment === '.well-known') && str_starts_with($segment, '.')
    );
}

function _stattic_config_value(string $envName): string
{
    if (defined($envName)) {
        $value = constant($envName);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    $raw = getenv($envName);
    if (is_string($raw) && trim($raw) !== '') {
        return trim($raw);
    }

    // Decoded once per request: the blob is env-provided and process-immutable.
    static $atomic = null;
    if ($atomic === null) {
        $atomicJson = getenv('SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON');
        $decoded = is_string($atomicJson) && $atomicJson !== '' ? json_decode($atomicJson, true) : null;
        $atomic = is_array($decoded) ? $decoded : [];
    }
    $value = $atomic[$envName] ?? null;
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    return '';
}

// Spec "Upload Contract": every object path is UTF-8, NFC, percent-decoded
// exactly once. Applied at upload intake, manifest intake and the serve-time
// route-map lookup, so NFC and NFD forms resolve identically.
function _stattic_nfc_path(string $path): string
{
    $normalized = _stattic_nfc_string($path);
    return is_string($normalized) ? $normalized : $path;
}

// ASCII is already NFC, so the fast path never touches intl. Without intl, NFD
// and NFC spellings of one path stop resolving to each other. That degrades
// matching without corrupting anything, so the request proceeds unnormalized and
// the operator gets one journal record per process instead of a hard failure.
function _stattic_nfc_string(string $value): string|false
{
    if (preg_match('/[\x80-\xff]/', $value) !== 1) {
        return $value;
    }
    if (class_exists('Normalizer')) {
        return Normalizer::normalize($value, Normalizer::FORM_C);
    }
    _stattic_nfc_no_intl_journal_once();
    return preg_match('//u', $value) === 1 ? $value : false;
}

function _stattic_nfc_no_intl_journal_once(): void
{
    static $journaled = false;
    if ($journaled) {
        return;
    }
    $journaled = true;
    $privateRoot = _stattic_access_private_root();
    if ($privateRoot === '') {
        return;
    }
    // Lazy: the hot path must never parse storage.php.
    require_once __DIR__ . '/storage.php';
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'runtime_intl_missing',
        'detail' => 'Normalizer is unavailable; request paths are served without NFC normalization.',
    ], false);
}

// RFC 3986 accepts ASCII URI bytes. HTTP request targets may carry authored
// UTF-8 directly, so encode only those bytes before parsing; the canonical path
// gate below performs the one validated percent-decode and NFC normalization.
function _stattic_uri_ascii(string $uri): ?string
{
    if (preg_match('//u', $uri) !== 1) {
        return null;
    }
    return preg_replace_callback(
        '/[^\x00-\x7f]+/u',
        static fn (array $match): string => rawurlencode($match[0]),
        $uri,
    );
}

// Preserve getRawPath(): getPath() removes dot segments before our security
// gate can reject them. There is deliberately no parse_url fallback.
function _stattic_request_uri_path(string $requestUri): string
{
    $ascii = _stattic_uri_ascii($requestUri);
    if ($ascii === null) {
        return '';
    }
    try {
        $path = (new \Uri\Rfc3986\Uri($ascii))->getRawPath();
        return $path === '' ? '/' : $path;
    } catch (Throwable $error) {
        // The URI can contain credentials, so log only the parser failure class.
        error_log('spacefast URI parser failed type=' . get_debug_type($error));
        return '';
    }
}

function _stattic_request_uri_query(string $requestUri): ?string
{
    $ascii = _stattic_uri_ascii($requestUri);
    if ($ascii === null) {
        return null;
    }
    try {
        return (new \Uri\Rfc3986\Uri($ascii))->getRawQuery();
    } catch (Throwable) {
        return null;
    }
}

// The URI stays raw for return URLs and redirect query handling; matchers get
// one validated percent-decode plus NFC, so browser URLs and authored Unicode
// rules share an identity.
function _stattic_canonical_request_path(string $path): ?string
{
    if (
        $path === ''
        || $path[0] !== '/'
        || str_starts_with($path, '//')
        || strlen($path) > 2048
        || preg_match('/[\\x00-\\x1F\\x7F\\\\?#]/', $path) === 1
        || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
        || preg_match('/%(?:2f|5c)/i', $path) === 1
    ) {
        return null;
    }
    $decoded = rawurldecode($path);
    if (
        preg_match('//u', $decoded) !== 1
        || preg_match('/[\\x00-\\x1F\\x7F\\\\?#]/u', $decoded) === 1
    ) {
        return null;
    }
    foreach (explode('/', $decoded) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return null;
        }
    }
    $normalized = _stattic_nfc_string($decoded);
    return is_string($normalized) ? $normalized : null;
}

function _stattic_path_has_residual_dot_segment(string $path): bool
{
    $decoded = $path;
    for ($depth = 0; $depth < 16; $depth++) {
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            return false;
        }
        $decoded = $next;
    }

    // Bounds attacker-controlled decoding work: a path that has not reached a
    // fixed point is ambiguous to a deeper decoder, so fail closed.
    return true;
}

// Deliberately no trim(): callers feed bytes that must compare as-is (the
// same-host Referer check relies on both sides staying untrimmed).
function _stattic_normalize_hostname(string $hostname): string
{
    return preg_replace('/:\d+$/', '', strtolower($hostname)) ?: '';
}

/**
 * The one browser origin allowed to READ a blob-gate response cross-origin.
 *
 * Pinned from configuration, never reflected from `Origin` (the
 * header-conformance probe's `Access-Control-Allow-Origin` entry has the same
 * posture): the token is the authorization, and CORS only decides whether the
 * dashboard's `fetch()` may see bytes the browser already fetched. Unset
 * configuration means NO header. A gate link then still downloads, it just
 * cannot be read by script.
 *
 * Returns a bare origin (scheme://host[:port]) or '' when unusable.
 */
function _stattic_dashboard_origin(): string
{
    static $origin = null;
    if (is_string($origin) && $origin !== '') {
        return $origin;
    }
    $resolved = _stattic_absolute_url_origin(_stattic_config_value('SPACEFAST_DASHBOARD_ORIGIN'));
    if (is_string($resolved) && $resolved !== '') {
        $origin = $resolved;
        return $origin;
    }
    return '';
}

// A non-digit config value falls back to $default; the result is floored at $min.
function _stattic_config_int(string $envName, int $default, int $min = 1): int
{
    $raw = _stattic_config_value($envName);
    if (preg_match('/^[0-9]+$/', $raw) === 1) {
        return max($min, (int) $raw);
    }
    return max($min, $default);
}

// Attribute-safe: ENT_QUOTES covers both quote styles.
function _stattic_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// '' means the prefix was present but empty, null means no Bearer header at
// all. The distinction is load-bearing: an empty Bearer token must NOT fall
// through to file-fetch's query-param fallback.
function _stattic_bearer_token_from_header(string $authorization): ?string
{
    if (preg_match('/^\s*Bearer\s(.*)$/is', $authorization, $matches) === 1) {
        return trim($matches[1]);
    }
    return null;
}

// REDIRECT_HTTP_AUTHORIZATION is the fallback some FPM/CGI setups need.
function _stattic_runtime_bearer_token_from_request(): ?string
{
    return _stattic_bearer_token_from_header(
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''))
    );
}

// Authorization belongs to the customer's application and must reach Functions
// unchanged, so Spacefast credentials use their own header.
function _stattic_platform_bearer_token_from_request(): ?string
{
    return _stattic_bearer_token_from_header((string) ($_SERVER['HTTP_X_SF_AUTHORIZATION'] ?? ''));
}

function _stattic_access_entry_token(string $requestPath): ?string
{
    if (!str_starts_with($requestPath, STATTIC_ACCESS_ENTRY_PREFIX)) {
        return null;
    }
    $token = substr($requestPath, strlen(STATTIC_ACCESS_ENTRY_PREFIX));
    return preg_match('/^[A-Za-z0-9_-]{16,512}$/D', $token) === 1 ? $token : null;
}

// init.php's alias dispatch stages the original request in the
// SPACEFAST_RUNTIME_REQUEST_URI/_PATH globals; direct hits fall back to $_SERVER.
function _stattic_runtime_effective_request_uri(): string
{
    $override = $GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] ?? null;
    if (is_string($override)) {
        return $override;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return is_string($uri) ? $uri : '/';
}

function _stattic_runtime_request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function _stattic_runtime_request_path(): string
{
    $override = $GLOBALS['SPACEFAST_RUNTIME_REQUEST_PATH'] ?? null;
    return is_string($override) ? $override : (parse_url(_stattic_runtime_effective_request_uri(), PHP_URL_PATH) ?: '/');
}

function _stattic_runtime_entrypoint_request(): array
{
    return [_stattic_runtime_request_method(), _stattic_runtime_request_path()];
}

function _stattic_runtime_management_api_route_path(string $requestPath): ?string
{
    if ($requestPath !== STATTIC_RUNTIME_MANAGEMENT_API_PATH) {
        return null;
    }
    $route = _stattic_runtime_query_param('route');
    if ($route === '') {
        return null;
    }
    return str_starts_with($route, '/') ? $route : '/' . $route;
}

function _stattic_runtime_upload_api_route_path(string $requestPath): ?string
{
    if ($requestPath !== STATTIC_RUNTIME_UPLOAD_API_PATH) {
        return null;
    }
    $route = _stattic_runtime_query_param('route');
    if ($route !== '') {
        return str_starts_with($route, '/') ? $route : '/' . $route;
    }
    $op = _stattic_runtime_query_param('op');
    $uploadId = _stattic_runtime_query_param('upload_id');
    if ($op === '' || $uploadId === '') {
        return '/';
    }
    // The operation segment precedes the object path so the path capture is the
    // last, greedy group.
    if ($op === 'fetch' || $op === 'file') {
        // URLSearchParams escapes the already-canonical path while encoding the
        // outer query string. PHP removes that outer layer in $_GET; preserve
        // the inner percent escapes for the upload path's decode-once contract.
        $encodedPath = isset($_GET['path']) && is_string($_GET['path'])
            ? $_GET['path']
            : _stattic_runtime_raw_query_param('path');
        $operationPath = $op === 'fetch' ? '/fetch/files/' : '/files/';
        if ($encodedPath === null || $encodedPath === '') {
            return '/' . rawurlencode($uploadId) . $operationPath;
        }
        return '/' . rawurlencode($uploadId) . $operationPath . $encodedPath;
    }
    return null;
}

// Only the name is decoded; the raw value and untouched raw pair come back with
// it so callers that re-emit a query string stay byte-identical.
// Returns a list of [string $name, ?string $rawValue, string $rawPart].
function _stattic_runtime_query_pairs(string $query): array
{
    $pairs = [];
    foreach (explode('&', $query) as $part) {
        $pair = explode('=', $part, 2);
        $pairs[] = [rawurldecode(str_replace('+', '%20', $pair[0] ?? '')), $pair[1] ?? null, $part];
    }
    return $pairs;
}

// Trimmed query value, '' for both absent and empty. `+` is the form encoding
// of a space, which rawurldecode() alone leaves as a literal plus.
function _stattic_runtime_query_param(string $name): string
{
    $value = isset($_GET[$name]) && is_string($_GET[$name]) ? trim($_GET[$name]) : '';
    if ($value !== '') {
        return $value;
    }
    $raw = _stattic_runtime_raw_query_param($name);
    return is_string($raw) ? trim(rawurldecode(str_replace('+', '%20', $raw))) : '';
}

function _stattic_runtime_raw_query_param(string $name): ?string
{
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query === '') {
        $query = parse_url(_stattic_runtime_effective_request_uri(), PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
    }
    foreach (_stattic_runtime_query_pairs($query) as [$pairName, $rawValue]) {
        if ($pairName === $name) {
            return $rawValue ?? '';
        }
    }
    return null;
}

// --- request-path and URL joining ------------------------------------------
//
// Lives here, not in runtime/serve.php: runtime/proxy.php and
// runtime/redirects.php reach these without the visitor serve path.

function _stattic_join_request_path(string $prefix, string $remainder): string
{
    $rawPrefix = $prefix === '' ? '/' : $prefix;
    $prefixEndsWithSlash = $rawPrefix !== '/' && str_ends_with($rawPrefix, '/');
    $normalizedPrefix = '/' . trim($rawPrefix, '/');
    if ($normalizedPrefix === '//') {
        $normalizedPrefix = '/';
    }
    $normalizedRemainder = $remainder === '/' ? '' : ltrim($remainder, '/');
    if ($normalizedPrefix === '/') {
        return $normalizedRemainder === '' ? '/' : '/' . $normalizedRemainder;
    }
    if ($normalizedRemainder === '') {
        return $prefixEndsWithSlash ? $normalizedPrefix . '/' : $normalizedPrefix;
    }
    return $normalizedPrefix . '/' . $normalizedRemainder;
}

function _stattic_append_path_to_url(string $baseUrl, string $remainder): string
{
    $parts = parse_url($baseUrl);
    if (!is_array($parts)) {
        return $baseUrl;
    }
    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $authority = $parts['host'] ?? '';
    if (isset($parts['port'])) {
        $authority .= ':' . $parts['port'];
    }
    if (isset($parts['user'])) {
        $userinfo = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
        $authority = $userinfo . '@' . $authority;
    }
    $path = _stattic_join_request_path($parts['path'] ?? '/', $remainder);
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $authority . $path . $query . $fragment;
}

function _stattic_append_query_before_fragment(string $target, string $query): string
{
    $parts = explode('#', $target, 2);
    return $parts[0] . '?' . $query . (isset($parts[1]) ? '#' . $parts[1] : '');
}

// A same-origin destination is a path: exactly one leading slash and no
// authority. Browsers treat `//host`, `/\host`, and a leading `\` as
// protocol-relative and a `scheme:` prefix as absolute, so none of those are
// same-origin. Used to classify a redirect template, re-check its expansion,
// and decide whether a redirect may keep the visitor's link token.
function _stattic_redirect_is_same_origin_path(string $value): bool
{
    if (($value[0] ?? '') !== '/') {
        return false;
    }
    $second = $value[1] ?? '';
    return $second !== '/' && $second !== '\\';
}

function _stattic_append_current_query_to_url(string $target): string
{
    $requestQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
    // A successful query-Link exchange has already installed the session, but
    // the token still rides a same-origin canonical redirect: the visitor
    // already presented it to this host, and a share link an agent cannot follow
    // without a cookie jar is not a link. A target that leaves this origin must
    // never receive the durable Link secret. Before exchange (a host redirect,
    // say) the cookie marker is false, so the token still reaches the host that
    // must redeem it.
    if (
        _stattic_access_query_token_present()
        && _stattic_identity_cookie_mutated()
        && !_stattic_redirect_is_same_origin_path($target)
    ) {
        $requestQuery = _stattic_strip_access_query_token($requestQuery);
    }
    if ($requestQuery !== '' && !str_contains($target, '?')) {
        $target = _stattic_append_query_before_fragment($target, $requestQuery);
    }
    return $target;
}

function _stattic_runtime_cors_headers(): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
        ? trim($_SERVER['HTTP_ORIGIN'])
        : '';
    if ($origin === '') {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin, false);
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS', false);
    header('Access-Control-Allow-Headers: Authorization, X-SF-Authorization, Content-Type, Content-Length', false);
    header('Access-Control-Expose-Headers: ETag', false);
    header('Access-Control-Max-Age: 600', false);
    header('Vary: Origin', false);
}
