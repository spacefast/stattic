<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/private-tree.php';

/** One bounded WordPress lookup after every cheaper public route owner missed. */
function _stattic_wordpress_page_try_serve(array $context, string $requestPath, string $requestMethod): void
{
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];
    if (
        !empty($serving['immutable'])
        || $requestPath === '/'
        || str_starts_with($requestPath, '/__spacefast/')
        || str_starts_with($requestPath, '/__stattic/')
        || str_starts_with($requestPath, '/wp-')
        || _stattic_lookup_is_known_asset_extension(ltrim($requestPath, '/'))
    ) {
        return;
    }

    $privateRoot = is_string($context['private_root'] ?? null) ? $context['private_root'] : '';
    $spaceId = is_string($context['space_id'] ?? null) ? $context['space_id'] : '';
    if ($privateRoot === '' || $spaceId === '') {
        return;
    }
    $modelRevision = _stattic_private_tree_read_pointer(
        $privateRoot . '/spaces/' . $spaceId . '/content-model/active-release',
        128
    );
    if (!is_string($modelRevision) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $modelRevision) !== 1) {
        return;
    }

    $wpLoad = dirname(dirname($privateRoot)) . '/wp-load.php';
    if (!is_file($wpLoad)) {
        return;
    }
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_PAGE_REQUEST'] = true;
    foreach ([
        'DISALLOW_FILE_EDIT' => true,
        'DISALLOW_FILE_MODS' => true,
        'AUTOMATIC_UPDATER_DISABLED' => true,
        'WP_AUTO_UPDATE_CORE' => false,
        'WP_USE_THEMES' => false,
    ] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    // wp-load initializes core and the Space-scoping kernel, but runs no main
    // query, canonical redirect, or theme. A miss therefore returns cleanly to
    // the Spacefast SPA/404 tail.
    ob_start();
    require_once $wpLoad;
    ob_end_clean();
    if (!function_exists('get_page_by_path') || !defined('OBJECT')) {
        return;
    }
    if (function_exists('add_action')) {
        add_action('wp_enqueue_scripts', '_stattic_wordpress_page_enqueue_assets');
    }
    $pagePath = trim($requestPath, '/');
    $page = get_page_by_path($pagePath, OBJECT, 'page');
    if (!is_object($page) || (string) ($page->post_status ?? '') !== 'publish') {
        return;
    }
    $postId = (int) ($page->ID ?? 0);
    $spaceMeta = defined('SPACEFAST_CONTENT_SPACE_META')
        ? (string) constant('SPACEFAST_CONTENT_SPACE_META')
        : '_spacefast_space_id';
    $owner = function_exists('get_post_meta') ? get_post_meta($postId, $spaceMeta, true) : null;
    if (!is_string($owner) || !hash_equals($spaceId, $owner)) {
        return;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        _stattic_method_decline(['GET', 'HEAD']);
        return;
    }

    $title = _stattic_wordpress_page_escape((string) ($page->post_title ?? ''));
    $description = trim((string) ($page->post_excerpt ?? ''));
    $content = (string) ($page->post_content ?? '');
    if (function_exists('setup_postdata')) {
        $GLOBALS['post'] = $page;
        setup_postdata($page);
    }
    if (function_exists('apply_filters')) {
        $content = (string) apply_filters('the_content', $content);
    }
    if (function_exists('wp_reset_postdata')) {
        wp_reset_postdata();
    }
    $wordpressHead = _stattic_wordpress_page_capture_hook('wp_head');
    $wordpressFooter = _stattic_wordpress_page_capture_hook('wp_footer');

    $styles = [
        '<style>@layer spacefast-content{.sf-content-page{margin:0}.sf-content-page main{box-sizing:border-box;max-width:72rem;margin:0 auto;padding:clamp(1.25rem,4vw,4rem)}.sf-content-page img,.sf-content-page video,.sf-content-page iframe{max-width:100%;height:auto}.sf-content-page .alignwide{max-width:72rem}.sf-content-page .alignfull{margin-inline:calc(50% - 50vw);max-width:100vw;width:100vw}}</style>',
    ];
    foreach (['/zero.css', STATTIC_RUNTIME_THEME_STYLESHEET_URL] as $stylesheet) {
        if (_stattic_wordpress_page_version_has_entry($context, $stylesheet)) {
            $styles[] = '<link rel="stylesheet" href="' . $stylesheet . '">';
        }
    }
    $descriptionMeta = $description === ''
        ? ''
        : '<meta name="description" content="' . _stattic_wordpress_page_escape($description) . '">';
    $document = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $title . '</title>' . $descriptionMeta . implode('', $styles) . $wordpressHead
        . '</head><body class="sf-content-page"><main><article><h1>' . $title . '</h1>'
        . $content . '</article></main>' . $wordpressFooter . '</body></html>';

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8', true);
    header('Cache-Control: private, no-store', true);
    header('X-Content-Type-Options: nosniff', true);
    if ($requestMethod !== 'HEAD') {
        echo $document;
    }
    exit;
}

/** Register the public Space SDK through WordPress's normal frontend asset hook. */
function _stattic_wordpress_page_enqueue_assets(): void
{
    if (!function_exists('wp_enqueue_script')) {
        return;
    }
    wp_enqueue_script(
        'spacefast-sdk',
        STATTIC_SPACEFAST_SDK_PATH,
        [],
        null,
        ['strategy' => 'async', 'in_footer' => true]
    );
}

function _stattic_wordpress_page_capture_hook(string $hook): string
{
    if (!function_exists($hook)) {
        return '';
    }
    ob_start();
    $hook();
    $output = ob_get_clean();
    return is_string($output) ? $output : '';
}

function _stattic_wordpress_page_version_has_entry(array $context, string $path): bool
{
    $versionDir = is_string($context['version_dir'] ?? null) ? $context['version_dir'] : '';
    $root = is_array($context['root'] ?? null) ? $context['root'] : null;
    return $versionDir !== '' && is_array($root) && is_array(_stattic_v4_entry($versionDir, $root, $path));
}

function _stattic_wordpress_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
