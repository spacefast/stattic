<?php
declare(strict_types=1);

function _stattic_typedpress_manifest(string $root): ?array
{
    if (!is_file($root . '/manifest.json')) return null;
    // Zero v2 requests ask this before serving any file, so a manifest that
    // isn't TypedPress's, readable or not, leaves them as they were.
    $manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512);
    return is_array($manifest) && ($manifest['format'] ?? null) === 'typedpress.hosted.v1' ? $manifest : null;
}

function _stattic_typedpress_mount(string $root, array $manifest): void
{
    require_once __DIR__ . '/storage.php';
    if (($manifest['hostContract'] ?? null) !== 1) throw new RuntimeException('Unsupported TypedPress host contract.');
    foreach (['build', 'runtimeRevision', 'appHash'] as $key) {
        if (!is_string($manifest[$key] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $manifest[$key]) !== 1) {
            throw new RuntimeException('Invalid TypedPress build reference.');
        }
    }
    global $wp_version;
    if (!is_string($wp_version) || version_compare($wp_version, $manifest['wordpress'], '<')) {
        throw new RuntimeException('This TypedPress build requires WordPress ' . $manifest['wordpress'] . '; use a compatible test box.');
    }
    require_once $root . '/store/runtime/' . $manifest['runtimeRevision'] . '/typedpress-loader.php';
    $variables = is_file($root . '/variables.json') ? json_decode((string) file_get_contents($root . '/variables.json'), true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($variables)) throw new RuntimeException('Invalid TypedPress variables.');
    $mail = _stattic_zero_v2_mail_variables();
    if (isset($mail['ZERO_MAIL_SENDER_DOMAIN'])) $variables += ['TP_MAIL_SENDER_DOMAIN' => $mail['ZERO_MAIL_SENDER_DOMAIN']];
    \TypedPress\Loader\Loader::load($root . '/store', $manifest['build'], [
        'variables' => $variables,
        'url' => '/__tp/assets',
    ]);
    add_filter('tp_mail_transport', static fn (mixed $accepted, array $message): bool => _stattic_zero_v2_mail_send($message), 10, 2);
}

/** Route only the selected build's declared pages, handlers and framework paths. */
function _stattic_typedpress_try_serve(array $context, string $path, string $method): bool
{
    $root = _stattic_version_root($context['private_root'], $context['space_id'], $context['version_id']) . '/.zero-compiled';
    $manifest = _stattic_typedpress_manifest($root);
    if ($manifest === null) return false;
    if (str_starts_with($path, '/__tp/assets/')) {
        if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
        $relative = substr($path, strlen('/__tp/assets/'));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
        $store = realpath($root . '/store');
        $file = realpath($root . '/store/' . $relative);
        $allowed = str_starts_with($relative, 'app/' . $manifest['appHash'] . '/plugins/')
            || str_starts_with($relative, 'runtime/' . $manifest['runtimeRevision'] . '/');
        if (!$allowed || !isset($types[$extension]) || !is_string($file) || !is_string($store)
            || !str_starts_with($file, $store . '/') || !is_file($file)) {
            _stattic_problem_response(404, 'not_found', 'No such TypedPress asset.');
        }
        // The Space's content-type allowlist covers app assets as it covers its files.
        $policy = _stattic_v4_content_type_policy_check($context, ['content-type' => $types[$extension]]);
        if ($policy !== null) _stattic_render_content_type_blocked($policy);
        _stattic_response_send(200, (string) file_get_contents($file), $types[$extension], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    // Sign in with Spacefast returns to the Space's reserved app callback
    // (wordpress/typedpress.php names it); it is TypedPress Identity's own
    // provider callback, for the Spacefast provider.
    if ($path === '/__spacefast/oauth/typedpress') {
        if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SERVER['QUERY_STRING'] = 'provider=spacefast' . ($query === '' ? '' : '&' . $query);
        $_GET = ['provider' => 'spacefast'] + $_GET;
        $path = '/identity/provider/callback';
    }
    $claimed = in_array($path, ['/identity', '/wp-admin/new', '/typedpress-admin'], true) || str_starts_with($path, '/__tp/') || str_starts_with($path, '/identity/')
        || str_starts_with($path, '/wp-json/typedpress-identity/') || str_starts_with($path, '/wp-admin/new/')
        || str_starts_with($path, '/typedpress-admin/');
    foreach (array_merge($manifest['pagePaths'] ?? [], array_column($manifest['routes'] ?? [], 'path')) as $pattern) {
        $segments = explode('/', trim($pattern, '/'));
        $regex = implode('/', array_map(static fn (string $part): string => str_starts_with($part, ':') ? '[^/]+' : (str_starts_with($part, '*') ? '.*' : preg_quote($part, '~')), $segments));
        if (preg_match('~^/' . $regex . '/?$~D', $path) === 1) $claimed = true;
    }
    if (!$claimed) return false;
    _stattic_zero_v2_select($context['private_root'], $context['space_id'], $context['version_id'], $context['serving']);
    require_once __DIR__ . '/../runtime/content-page.php';
    // TypedPress pages route through WordPress's request parsing. A Space rewrite
    // matched the effective path above, so WordPress has to see that path, not
    // the visitor's, including when the provider's front controller boots it
    // after this pass. WP::parse_request prefers PATH_INFO when a server sets it.
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $_SERVER['REQUEST_URI'] = $path . ($query === '' ? '' : '?' . $query);
    if (isset($_SERVER['PATH_INFO'])) $_SERVER['PATH_INFO'] = $path;
    // Who the visitor is and which Space role they hold, before WordPress
    // boots here or in the provider's front controller: the Spacefast plugin
    // (wordpress/typedpress.php) makes that person the app's WordPress user
    // and lends the role for this request only.
    _stattic_typedpress_resolve_visitor($context['serving'], (string) $context['host'], $path, $method);
    // A page whose admission needed a Grant, or that runs as a platform
    // principal, is never a shared-cache copy, whatever the app's render cache
    // sets (shared/response.php applies it when the headers leave PHP).
    if (!empty($context['private_cache']) || isset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'])) {
        _stattic_authenticated_response(true);
    }
    if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) return true;
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', true);
    require_once dirname($context['private_root'], 2) . '/wp-load.php';
    _stattic_wordpress_lane_refuse_page_cache();
    wp();
    _stattic_document_isolation_stream_begin();
    require ABSPATH . WPINC . '/template-loader.php';
    _stattic_enforce_document_isolation();
    exit;
}

/**
 * The one per-request principal resolver for a TypedPress request: the account
 * the live Space session names becomes `SPACEFAST_CONTENT_PRINCIPAL` (as the
 * WordPress REST door resolves it in loader/request.php), and the Space role it
 * holds now becomes `SPACEFAST_SPACE_ROLE`. Enforcement already admitted the
 * request; this only names who WordPress attributes it to. An open Space loads
 * access code only for a browser holding an account-format session cookie. An
 * unsafe method acts as the person only from this Space's own origin, so a
 * cross-site form never acts as them. Idempotent.
 */
function _stattic_typedpress_resolve_visitor(array $serving, string $host, string $path, string $method): void
{
    if (array_key_exists('SPACEFAST_SPACE_ROLE', $GLOBALS)) return;
    $GLOBALS['SPACEFAST_SPACE_ROLE'] = null;
    $cookie = $_COOKIE[_stattic_session_cookie_name()] ?? null;
    if (!is_string($cookie) || !str_starts_with($cookie, 'sfv2_') || $host === '') return;
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        require_once __DIR__ . '/request-origin.php';
        if (!_stattic_access_same_origin_post($host)) return;
    }
    require_once __DIR__ . '/../runtime/access-rules.php';
    if (!isset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'])) {
        $principal = _stattic_access_wordpress_principal($serving, $host, $path);
        if ($principal !== null) {
            $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
            $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $principal['wordpress_role'];
        }
    }
    $GLOBALS['SPACEFAST_SPACE_ROLE'] = _stattic_typedpress_space_role($serving, $host);
}

/**
 * The account the live Space session names, and the strongest role a single
 * whole-Space Grant gives it now. A Grant on one folder or one preview is
 * access to that folder or preview, not a role in the app, so only a Grant on
 * every path (`/**`, no excludes), on the live target, with no use limit,
 * network rule or frame origin counts. Capabilities never combine across
 * Grants. Null for an anonymous visitor, a runtime bearer, or a preview or
 * version host.
 *
 * @return array{role: string, issuer: string, subject: string}|null
 */
function _stattic_typedpress_space_role(array $serving, string $host): ?array
{
    if (_stattic_platform_bearer_token_from_request() !== null || _stattic_grant_target($serving) !== ['kind' => 'live']) {
        return null;
    }
    $identity = _stattic_current_session_identity($serving, $host);
    $account = is_array($identity)
        ? _stattic_access_account_identity(_stattic_access_identity_record($identity) ?? [])
        : null;
    $projection = _stattic_authorization_projection_current($serving['authorization'] ?? null);
    if ($account === null || $projection === null) return null;
    $role = _stattic_whole_space_role(
        $projection,
        is_array($identity['authorities'] ?? null) ? $identity['authorities'] : [],
        is_array($identity['emailVerifiedAuthorities'] ?? null) ? $identity['emailVerifiedAuthorities'] : []
    );
    return $role === null ? null : ['role' => $role] + $account;
}

/**
 * The strongest Space role one Grant on the whole live Space gives these
 * authorities now, or null.
 *
 * The one reader that looks past a Space-origin session's capability cap: a
 * Sign in with Spacefast session may only view and comment on the Space's
 * origin (_stattic_grant_decision applies the cap to every page, comment and
 * WordPress-principal decision), but the role it names to the app is the
 * person's live role, read from the real Grant behind each authority. The
 * role is lent for this request only (wordpress/space-role.php), so it never
 * outlives that Grant or the session, and an ended session holds no
 * authority to read a role from.
 */
function _stattic_whole_space_role(array $projection, array $authorities, array $verifiedAuthorities): ?string
{
    require_once __DIR__ . '/space-roles.php';
    $best = null;
    foreach (_stattic_grant_candidates($projection, $authorities) as $grant) {
        if (!is_array($grant) || ($grant['include'] ?? null) !== [['**']] || ($grant['exclude'] ?? null) !== []
            || ($grant['target'] ?? null) !== ['kind' => 'live'] || ($grant['maxUses'] ?? null) !== null
            || ($grant['network'] ?? null) !== null || ($grant['frameOrigin'] ?? null) !== null) {
            continue;
        }
        // The one Grant alone, through the same decision every request runs:
        // its window, its email check and its target still apply.
        $alone = ['grantIndex' => ['public' => ['fallback' => [$grant]], 'authorities' => []]] + $projection;
        $capabilities = _stattic_grant_decision($alone, '/', ['kind' => 'live'], [], $verifiedAuthorities, '', '', false, true)['capabilities'];
        if (!in_array('page.view', $capabilities, true)) continue;
        $role = _stattic_space_role_for_capabilities($capabilities);
        if ($best === null || _stattic_space_role_rank($role) > _stattic_space_role_rank($best)) $best = $role;
    }
    return $best;
}

/**
 * Before the app mounts, the platform's own credentials leave the request: the
 * Space session cookie and the platform bearer header. They admitted the
 * request and named its principal above; the app's code (its route handlers
 * read every request header) sees only the application's own cookies.
 */
function _stattic_typedpress_scrub_platform_credentials(): void
{
    require_once __DIR__ . '/upstream-relay.php';
    foreach (STATTIC_PLATFORM_CREDENTIAL_REQUEST_HEADERS as $header) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        unset($_SERVER[$key], $_SERVER['REDIRECT_' . $key]);
    }
    $cookie = is_string($_SERVER['HTTP_COOKIE'] ?? null) ? _stattic_cookie_header_without_platform_cookies($_SERVER['HTTP_COOKIE']) : '';
    unset($_SERVER['HTTP_COOKIE']);
    if ($cookie !== '') $_SERVER['HTTP_COOKIE'] = $cookie;
    foreach (array_keys($_COOKIE) as $name) {
        if (_stattic_platform_cookie_name((string) $name)) unset($_COOKIE[$name]);
    }
}
