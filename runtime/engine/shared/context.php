<?php
declare(strict_types=1);

// Engine identity constants live here (the one file every entrypoint requires
// first) so the HTTP entry (init.php) and the SSH management dispatcher
// (admin/dispatch.php) share a single definition.
const STATTIC_RUNTIME_SCHEMA = 'static-runtime-v2';
const SPACEFAST_RUNTIME_ENGINE_VERSION = 'static-runtime-v2';
const SPACEFAST_RUNTIME_ENGINE_REVISION = 'source-tree';
const STATTIC_RUNTIME_NAMESPACE_PATH = '/__spacefast';
const STATTIC_RUNTIME_MANAGEMENT_API_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/api.php';
const STATTIC_RUNTIME_UPLOAD_API_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/upload.php';
const STATTIC_RUNTIME_HEALTH_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/health.php';
const STATTIC_RUNTIME_ACCESS_CALLBACK_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access-callback.php';
// THE set of real PHP entrypoint files under the runtime namespace. One table
// consumed by the wp.cloud custom-redirects hook (which must let php-fpm serve
// them directly) and init.php's alias dispatch, so the two cannot drift.
const SPACEFAST_RUNTIME_ENTRYPOINT_PATHS = [
    STATTIC_RUNTIME_HEALTH_PATH => true,
    STATTIC_RUNTIME_MANAGEMENT_API_PATH => true,
    STATTIC_RUNTIME_UPLOAD_API_PATH => true,
    STATTIC_RUNTIME_ACCESS_CALLBACK_PATH => true,
];
const STATTIC_SPACEFAST_SDK_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/sdk.js';
const STATTIC_PAGE_FONT_PATH_PREFIX = STATTIC_RUNTIME_NAMESPACE_PATH . '/pages/fonts/';
const SPACEFAST_TAG_PREVIEW_QUERY_NAME = 'spacefast_tag_preview';

// The visitor-access lane (access-plan §3.2/§3.3). Exactly TWO cookies exist:
// `spacefast_access` (THE visitor token — identity, links, invites, password
// passes) and `spacefast_claim_view` (the expiry-rescue banner, not auth). No
// other cookie name is ever read or written by the access lane.
const SPACEFAST_ACCESS_COOKIE = 'spacefast_access';
const SPACEFAST_CLAIM_VIEW_COOKIE = 'spacefast_claim_view';
// First-party access surfaces under the runtime namespace (all PHP, no script).
const SPACEFAST_ACCESS_ROUTE_PREFIX = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/';
const SPACEFAST_ACCESS_LOGOUT_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/logout';
const SPACEFAST_ACCESS_ME_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/me';
const SPACEFAST_ACCESS_TOKEN_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/token';
const SPACEFAST_ACCESS_LOGIN_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/access/login';
// The share-URL trade param (access-plan §3.2). Replayable (the share URL IS the
// credential); stripped from the clean-URL redirect and log-scrubbed.
const SPACEFAST_SHARE_QUERY_NAME = 'sf_share';

// Zero control routes under the runtime namespace — THE path → operation/
// methods table. Consumed by the compile-time control-route writer
// (admin/management.php), the front-door public-path gate (init.php — the
// namespace is private-by-default, so a route missing here silently 403s), and
// the operation allowlist (shared/artifacts.php). Add a new Zero control
// surface HERE once.
const SPACEFAST_ZERO_CONTROL_ROUTES = [
    '__spacefast/zero/config' => ['operation' => 'config', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/run' => ['operation' => 'run', 'methods' => ['POST']],
    '__spacefast/zero/auth/wpcom/start' => ['operation' => 'auth_start', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/auth/sign-out' => ['operation' => 'auth_sign_out', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/realtime/events' => ['operation' => 'realtime_events', 'methods' => ['GET', 'HEAD']],
];

// Access-event journal (access-plan X-37 / §5.6b): every enforced decision
// appends one accessEventSchema-shaped NDJSON line under the private root;
// the cloud PULLS it through the management `access_events` action (doctrine:
// zero request-path network calls — the runtime never pushes). Day-rotated
// files, hard per-file byte cap (overflow counted in a `.dropped` sidecar),
// pruned after the retention window. Constants live here because the writer
// (runtime/access-rules.php, serve lane) and the reader (admin/management.php,
// management lane) share them.
const SPACEFAST_ACCESS_EVENTS_DIR = 'runtime/access-events';
const SPACEFAST_ACCESS_EVENT_FILE_MAX_BYTES = 33554432; // 32 MiB per day file
const SPACEFAST_ACCESS_EVENT_RETENTION_DAYS = 14;

// THE one cookie setter for the access lane (access-plan §3.3). Path=/; HttpOnly;
// SameSite=Lax; Secure ALWAYS — Spacefast never serves plain http, the edge
// always terminates TLS, so Secure must not depend on request-derived signals
// like X-Forwarded-Proto (those are stripped whenever SPACEFAST_TRUSTED_EDGE_HEADERS
// is unset, which would otherwise silently drop Secure on a genuinely-HTTPS
// site). The only escape is the explicit dev/test flag SPACEFAST_INSECURE_COOKIES=1,
// needed so a spec-compliant cookie jar will send the cookie over http://localhost
// in local dev and the php -S test harness. `Partitioned` (CHIPS) is opt-in for
// dashboard iframe previews and stays gated on Secure being emitted.
// Replaces every ad-hoc Secure-detection idiom.
function _spacefast_cookies_secure(): bool
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') !== '1';
}

function _spacefast_set_cookie(string $name, string $value, int $maxAgeSeconds, bool $partitioned = false): void
{
    $secure = _spacefast_cookies_secure();
    $attributes = [
        $name . '=' . rawurlencode($value),
        'Path=/',
        'Max-Age=' . max(0, $maxAgeSeconds),
        'HttpOnly',
        'SameSite=Lax',
    ];
    if ($secure) {
        $attributes[] = 'Secure';
    }
    if ($partitioned && $secure) {
        $attributes[] = 'Partitioned';
    }
    header('Set-Cookie: ' . implode('; ', $attributes), false);
    // Keep $_COOKIE coherent for same-request reads after a set.
    $_COOKIE[$name] = $value;
}

function _spacefast_clear_cookie(string $name): void
{
    // An empty value with Max-Age=0 IS the clear header; never `Partitioned`.
    _spacefast_set_cookie($name, '', 0);
    unset($_COOKIE[$name]);
}

// Trusted-header contract (access-plan X-36). The edge owns geo, forwarded
// proto/for/IP, and the Spacefast-Access-* identity headers; a direct client
// must never spoof them past the edge. Unless the deployment is explicitly
// marked trusted (SPACEFAST_TRUSTED_EDGE_HEADERS=1), these are stripped from the
// inbound request so country rules, Secure detection, and CIDR firewalling read
// only edge-set values. Spacefast-Access-* is stripped UNCONDITIONALLY — a
// client never legitimately sends it (identity forwarding is runtime→upstream).
function _spacefast_edge_headers_trusted(): bool
{
    return _stattic_config_value('SPACEFAST_TRUSTED_EDGE_HEADERS') === '1';
}

function _spacefast_strip_untrusted_edge_headers(): void
{
    // Never trust an inbound identity-forwarding header — it is a runtime→origin
    // primitive, forged if it arrives from a client.
    foreach (['HTTP_SPACEFAST_ACCESS_JWT', 'HTTP_SPACEFAST_ACCESS_SUB', 'HTTP_SPACEFAST_ACCESS_GRANTS'] as $key) {
        unset($_SERVER[$key]);
    }
    if (_spacefast_edge_headers_trusted()) {
        return;
    }
    foreach ([
        'HTTP_CF_IPCOUNTRY',
        'HTTP_GEOIP_COUNTRY_CODE',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_PROTO',
    ] as $key) {
        unset($_SERVER[$key]);
    }
}

// Single source of truth for the default edge/CDN cache policy emitted on the
// not-found / redirect / tombstone / error-page response classes. Lives here
// (the first file every entrypoint requires) so the non-lazy redirect and
// file-serve paths in runtime/serve.php — which run before errors.php is
// lazy-loaded — always see it defined. Keep byte-identical when tuning the
// CDN short-cache policy: this value is hand-mirrored by the control-plane
// generator (admin/generate.php) onto the baked artifacts it emits.
const STATTIC_DEFAULT_EDGE_CACHE_CONTROL = 'public, max-age=0, s-maxage=60, must-revalidate';
const STATTIC_CACHE_CONTROL_NO_STORE = 'no-store';
// THE never-shared-cache pin for access-protected / per-visitor responses —
// one platform policy consumed by the file server (serve.php), the proxy
// relay (proxy.php), and the Zero cache plan (zero.php) so the
// security-relevant string can never drift between surfaces. The stripped
// list is the cache-policy header set those surfaces additionally discard on
// private responses (validators still relay, inert under no-store).
const STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE = 'private, no-store';
const STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS = ['cache-control', 'expires', 'age'];

// Sticky per-request "this request touched a protected path" verdict: set by
// the access enforcement in serve.php, read by the proxy cache relay
// (runtime/proxy.php) and the Zero cache plan (runtime/zero.php) to pin
// private, no-store. Accessor instead of a bare global — same idiom as
// _spacefast_revocations_unavailable_flag (shared/jwt.php). Pass a bool to
// set, no argument to read; once true it stays true for the request.
function _spacefast_access_private_cache_flag(?bool $set = null): bool
{
    static $private = false;
    if ($set === true) {
        $private = true;
    }
    return $private;
}
// Private-root handle for request-path private state (the access-event journal
// and the runtime-local revocation store): staged by the serving entrypoint and
// the access-callback lane so the enforcer's signature stays serving-shaped, and
// read by the access lane and the platform page renderer. Lives here rather than
// next to its readers because shared/errors.php needs it and cannot require
// runtime/access-rules.php (that dependency runs the other way). Pass a string
// to set, no argument to read.
function _spacefast_access_private_root(?string $set = null): string
{
    if ($set !== null) {
        $GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT'] = $set;
    }
    return is_string($GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT'] ?? null)
        ? $GLOBALS['SPACEFAST_ACCESS_PRIVATE_ROOT']
        : '';
}
// stale-while-revalidate window (W7) for revalidatable static file responses:
// the brief grace during which a shared/browser cache may serve a stale copy
// while it revalidates in the background. Applied only to the non-immutable,
// shared-cacheable file class (see _stattic_file_response_shared_cache_headers);
// never to the immutable long-max-age set or to no-store/no-cache/private
// responses. Kept deliberately small — freshness is unchanged, only the
// post-freshness stale grace is opened.
const STATTIC_STALE_WHILE_REVALIDATE_SECONDS = 60;

// Post-response work (wp.cloud php-fpm): telemetry and housekeeping that no
// visitor should ever wait on. Queued work runs in a shutdown handler; on the
// serve lane (which opts in via _spacefast_flush_response_before_deferred) the
// handler first flushes the finished response to the client with
// fastcgi_finish_request(), so the deferred writes happen after the last byte
// left the box. Under other SAPIs (php -S test harness, CLI dispatch) the
// flush is a no-op and the work simply runs at shutdown — same effects, same
// order. Deferred work must be self-contained (capture data eagerly, write
// lazily) and best-effort: failures are swallowed, never surfaced into a
// response that has already been sent.
function &_spacefast_deferred_work(): array
{
    static $queue = [];
    return $queue;
}

function _spacefast_flush_response_before_deferred(?bool $set = null): bool
{
    static $flush = false;
    if ($set !== null) {
        $flush = $set;
    }
    return $flush;
}

function _spacefast_defer(callable $work): void
{
    static $registered = false;
    $queue = &_spacefast_deferred_work();
    $queue[] = $work;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function (): void {
        if (_spacefast_flush_response_before_deferred() && function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $queue = &_spacefast_deferred_work();
        foreach ($queue as $work) {
            try {
                $work();
            } catch (Throwable) {
                // Post-response: the visitor already has their bytes; nothing
                // to surface. The work itself is best-effort telemetry.
            }
        }
        $queue = [];
    });
}

// Private-storage layout — THE path builders for space and version roots
// (`spaces/<spaceId>` / `spaces/<spaceId>/versions/<versionId>`). Every layer
// (admin, serve, access) builds these paths through here so a layout change is
// one edit, not a shotgun sweep.
function _spacefast_space_root(string $privateRoot, string $spaceId): string
{
    return $privateRoot . '/spaces/' . $spaceId;
}

function _spacefast_version_root(string $privateRoot, string $spaceId, string $versionId): string
{
    return $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId;
}

// THE id-shape validator for the runtime's identifier vocabulary (space ids,
// version ids, route names, bucket ids): 1-128 chars of [A-Za-z0-9._-],
// starting alphanumeric. One definition consumed by the management intake
// (shared/storage.php) and the serve-path artifact loaders
// (shared/artifacts.php) so an id the management surface admits can never be
// one the serve layer refuses to load.
function _spacefast_id_valid(string $value): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1;
}

// Reserved space-config filenames (spec "Space Configuration Files"):
// finalize inputs, private after compile, never served as ordinary files. ONE
// definition consumed by the compile-side exclusion (admin/generate.php,
// _stattic_runtime_private_files) and the serve-side terminal-404 gate
// (runtime/serve.php, _stattic_lookup_not_found_is_terminal) so compile-time
// privacy and serve-time terminality cannot drift. Keys are the canonical
// lowercase relative paths.
const SPACEFAST_PRIVATE_CONFIG_FILES = [
    'sf.jsonc' => true,
    'spacefast.jsonc' => true,
    'spacefast.json' => true,
    'sf.json' => true,
    '.sf/sf.json' => true,
    '.sf/config.jsonc' => true,
    '.sf/config.json' => true,
];
// Compile-input sidecars, same posture (private after compile).
const SPACEFAST_PRIVATE_COMPILE_FILES = [
    '_redirects' => true,
    '_headers' => true,
    '_config.json' => true,
    '_routes.json' => true,
];

// Hidden-segment rule (spec "Hidden Files"): any path with a dot-prefixed
// segment is private, except a root-level `.well-known` directory itself —
// files *inside* it are public, dot-prefixed entries deeper in it are not.
// ONE definition consumed by the HTTP gate (init.php), the serve-side
// terminal-404 gate (runtime/serve.php), and the compile-side privacy scan
// (admin/generate.php) so the three altitudes cannot drift. Callers pass the
// path pre-lowercased and slash-trimmed exactly as they always did; this
// helper owns only the segment walk.
function _stattic_path_has_hidden_segment(string $lowerPath): bool
{
    foreach (explode('/', $lowerPath) as $index => $segment) {
        if ($index === 0 && $segment === '.well-known') {
            continue;
        }
        if (str_starts_with($segment, '.')) {
            return true;
        }
    }
    return false;
}

function _stattic_page_font_filename(string $requestPath): ?string
{
    return [
        STATTIC_PAGE_FONT_PATH_PREFIX . 'haskoy-latin-variable.woff2' => 'haskoy-latin-variable.woff2',
        STATTIC_PAGE_FONT_PATH_PREFIX . 'merriweather-latin-variable.woff2' => 'merriweather-latin-variable.woff2',
    ][$requestPath] ?? null;
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

    // Decoded once per request: the blob is env-provided and immutable for the
    // process, and serve-path requests can miss on several knobs per request.
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

// Canonical path form (spec "Upload Contract"): every object path is UTF-8,
// Unicode NFC, percent-decoded exactly once. This is the pure decode-once+NFC
// transform applied at upload intake, manifest intake, and the serve-time
// route-map lookup so NFC and NFD forms resolve identically. ASCII fast path;
// no config parsing, no I/O.
function _stattic_nfc_path(string $path): string
{
    if (preg_match('/[\\x80-\\xff]/', $path) !== 1 || !class_exists('Normalizer')) {
        return $path;
    }
    $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
    return is_string($normalized) ? $normalized : $path;
}

// THE hostname normalizer: lowercase + strip a trailing :port. No surrounding
// trim() — every caller feeds token-parsed HTTP_HOST, already-trimmed config
// values, or parse_url() output whose bytes must be compared as-is (the
// same-host Referer check relies on both sides staying untrimmed).
function _stattic_normalize_hostname(string $hostname): string
{
    return preg_replace('/:\d+$/', '', strtolower($hostname)) ?: '';
}

function _stattic_management_hostname(): string
{
    return _stattic_normalize_hostname(
        _stattic_config_value('SPACEFAST_MANAGEMENT_HOSTNAME')
    );
}

function _stattic_is_management_host(string $host): bool
{
    $host = _stattic_normalize_hostname($host);
    if ($host === '') {
        return false;
    }

    $allowed = _stattic_management_hostname();
    if ($allowed !== '' && hash_equals($allowed, $host)) {
        return true;
    }

    // Test/multi-host harness only: one local-atomic node serves every space, so
    // the management hostname differs per request ({siteId}.local-atomic.test).
    // SPACEFAST_MANAGEMENT_HOST_PATTERN carries a single leading-`*.` suffix
    // wildcard (e.g. `*.local-atomic.test`); production leaves it unset and the
    // exact-match above is the only path. The wildcard accepts exactly one extra
    // label before the suffix — no nested subdomains, no bare apex.
    $pattern = _stattic_normalize_hostname(
        _stattic_config_value('SPACEFAST_MANAGEMENT_HOST_PATTERN')
    );
    if ($pattern !== '' && str_starts_with($pattern, '*.')) {
        $suffix = substr($pattern, 1); // drops the `*`, keeps the leading dot
        if (str_ends_with($host, $suffix)) {
            $label = substr($host, 0, -strlen($suffix));
            return $label !== '' && !str_contains($label, '.');
        }
    }

    return false;
}

// Shared digits-only int-knob parser (admission concurrency limit, tier grace
// windows, tier breaker/in-flight thresholds all hand-rolled this same
// preg_match-or-default): a non-digit-string config value falls back to the
// caller's default; either way the result is floored at $min.
function _stattic_config_int(string $envName, int $default, int $min = 1): int
{
    $raw = _stattic_config_value($envName);
    if (preg_match('/^[0-9]+$/', $raw) === 1) {
        return max($min, (int) $raw);
    }
    return max($min, $default);
}

// Canonical HTML escape for every engine-rendered text/attribute value
// (attribute-safe: ENT_QUOTES covers both quote styles).
function _stattic_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// THE Bearer-token extractor for every Authorization-header surface: the
// management/upload auth lane (admin/auth.php), the read-only file-fetch lane
// (admin/file-fetch.php), and the visitor-access lane (runtime/access-rules.php).
// Case-insensitive `Bearer` prefix, with the REDIRECT_HTTP_AUTHORIZATION
// fallback some FPM/CGI setups need — one parse so the lanes cannot drift.
// Returns the trimmed token when the prefix is present (this may be '' if the
// prefix is followed only by whitespace), or null when no Bearer header exists.
// The null-vs-'' distinction is load-bearing for file-fetch: when the header
// presents an empty Bearer token it must NOT fall through to the query-param
// fallback.
function _stattic_runtime_bearer_token_from_request(): ?string
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^\s*Bearer\s(.*)$/is', $authorization, $matches) === 1) {
        return trim($matches[1]);
    }
    return null;
}

// Request URI/path resolution shared by the /__spacefast entrypoints and the
// raw-query fallback below. init.php's alias dispatch stages the original
// request in the SPACEFAST_RUNTIME_REQUEST_URI/_PATH globals; direct hits fall
// back to $_SERVER.
function _stattic_runtime_effective_request_uri(): string
{
    $override = $GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] ?? null;
    if (is_string($override)) {
        return $override;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return is_string($uri) ? $uri : '/';
}

// THE canonical request method/path reads for every engine surface: uppercased
// method, and the path with init.php's alias-dispatch override honoured.
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
    $route = isset($_GET['route']) && is_string($_GET['route']) ? trim($_GET['route']) : '';
    if ($route === '') {
        $rawRoute = _stattic_runtime_raw_query_param('route');
        $route = is_string($rawRoute) ? trim(rawurldecode(str_replace('+', '%20', $rawRoute))) : '';
    }
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
    $op = isset($_GET['op']) && is_string($_GET['op']) ? trim($_GET['op']) : '';
    $uploadId = isset($_GET['upload_id']) && is_string($_GET['upload_id']) ? trim($_GET['upload_id']) : '';
    if ($op === '' || $uploadId === '') {
        return '/';
    }
    if ($op === 'batch') {
        return '/' . rawurlencode($uploadId) . '/batch';
    }
    // The operation segment precedes the object path so the path capture is the
    // last, greedy group. Encoding it the other way round made `/parts/{n}` and
    // `/complete` ambiguous with a declared object whose own path ends in those
    // segments, which the part handler then had to disambiguate against the
    // session manifest.
    if ($op === 'file' || $op === 'fetch') {
        $encodedPath = _stattic_runtime_raw_query_param('path');
        if ($encodedPath === null || $encodedPath === '') {
            return '/' . rawurlencode($uploadId) . '/files/';
        }
        if ($op === 'fetch') {
            return '/' . rawurlencode($uploadId) . '/fetch/files/' . $encodedPath;
        }
        $partNumber = isset($_GET['part_number']) && is_string($_GET['part_number']) ? trim($_GET['part_number']) : '';
        $complete = isset($_GET['complete']) && is_string($_GET['complete']) ? trim($_GET['complete']) : '';
        if ($partNumber !== '') {
            return '/' . rawurlencode($uploadId) . '/parts/' . rawurlencode($partNumber) . '/files/' . $encodedPath;
        }
        if ($complete === '1') {
            return '/' . rawurlencode($uploadId) . '/complete/files/' . $encodedPath;
        }
        return '/' . rawurlencode($uploadId) . '/files/' . $encodedPath;
    }
    return null;
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
    foreach (explode('&', $query) as $part) {
        $pair = explode('=', $part, 2);
        if (rawurldecode(str_replace('+', '%20', $pair[0] ?? '')) === $name) {
            return $pair[1] ?? '';
        }
    }
    return null;
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
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Content-Length', false);
    header('Access-Control-Expose-Headers: ETag', false);
    header('Access-Control-Max-Age: 600', false);
    header('Vary: Origin', false);
}

function _stattic_runtime_assert_api_hostname(): void
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if (_stattic_is_management_host($host)) {
        return;
    }
    // Deliberately generic: public hostnames must not advertise that the
    // management/upload API exists or how to reach it.
    _stattic_json_response(404, [
        'error' => [
            'code' => 'runtime_api_not_found',
            'message' => 'Not found.',
        ],
    ]);
}
