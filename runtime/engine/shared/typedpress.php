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
