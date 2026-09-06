<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/private-tree.php';

/** Resume a document after the provider has supplied WordPress's environment. */
function _stattic_wordpress_page_resume_deferred_request(): void
{
    $deferred = $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null;
    if (!is_array($deferred) || !function_exists('_sf_serve_fast')) {
        return;
    }
    unset($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST']);
    $GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'] = false;
    _sf_serve_fast(
        $deferred['private_root'],
        $deferred['method'],
        $deferred['uri'],
        $deferred['path'],
        $deferred['host']
    );
    exit;
}

/**
 * Render only the canonical document route selected by the runtime inventory.
 *
 * Returns true when the request was handed to the document root's front
 * controller instead of being answered here; the caller must then return
 * without answering. Every other outcome either serves and exits or declines.
 */
function _stattic_wordpress_page_try_serve(array $context, string $requestPath, string $requestMethod, array $route): bool
{
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];
    $immutable = !empty($serving['immutable']);
    if (
        ($route['render'] ?? null) !== 'document'
        || ($route['params'] ?? null) !== []
        || !is_string($route['bindingId'] ?? null)
    ) {
        return false;
    }

    $privateRoot = is_string($context['private_root'] ?? null) ? $context['private_root'] : '';
    $spaceId = is_string($context['space_id'] ?? null) ? $context['space_id'] : '';
    if ($privateRoot === '' || $spaceId === '') {
        return false;
    }
    $snapshot = $immutable ? _stattic_wordpress_page_snapshot($context, $route) : null;
    if ($immutable && $snapshot === null) {
        return false;
    }
    $modelRevision = $immutable ? $snapshot['modelRevision'] : _stattic_private_tree_read_pointer(
        $privateRoot . '/spaces/' . $spaceId . '/content-model/active-release',
        128
    );
    if (!is_string($modelRevision) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $modelRevision) !== 1) {
        return false;
    }

    $wpLoad = dirname(dirname($privateRoot)) . '/wp-load.php';
    if (!is_file($wpLoad)) {
        return false;
    }

    // The provider's auto_prepend (`/scripts/env.php`) requires this engine's
    // `custom-redirects.php` copy BEFORE it defines DB_NAME/DB_USER/DB_HOST,
    // WP_CONTENT_DIR, WP_CACHE_KEY_SALT and the rest of the WordPress
    // environment. WordPress booted inside that pass gets an empty database
    // tuple and the shared core's wp-content, dies in dead_db(), and the
    // provider's own header callback turns that 500 into a 502 with nothing in
    // the engine's error log. A request that needs WordPress therefore hands
    // itself to the front controller. The engine controller resumes this lane
    // directly; the provider's WordPress controller resumes it at wp_loaded.
    // Which Space this request belongs to, established BEFORE the lane can hand
    // the request on. Whatever serves it next boots WordPress in this same
    // process, and the content loader mu-plugin reads these while core is coming
    // up — which is what makes the Space's templates, its post scoping and its
    // media resolve. Left until after the hand-off, WordPress serves the URL as
    // nobody's Space: the right post, rendered through no template, which is an
    // empty document.
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_PAGE_REQUEST'] = true;
    if ($immutable) {
        $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] = $modelRevision;
    }

    if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) {
        return true;
    }

    // Only this lane renders the document itself, so only this lane turns themes
    // off. A request handed on is served by WordPress's own front controller,
    // which needs them.
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
    if (!function_exists('spacefast_content_model_sync_binding') || !function_exists('spacefast_content_sync_find_post')) {
        return false;
    }
    if (function_exists('add_action')) {
        add_action('wp_enqueue_scripts', '_stattic_wordpress_page_enqueue_assets');
    }
    $binding = spacefast_content_model_sync_binding($route['bindingId']);
    if (!is_array($binding) || !in_array($binding['post_type'] ?? null, ['page', 'post'], true)) {
        return false;
    }
    if ($immutable && (($binding['format'] ?? null) !== $snapshot['format']
        || ($binding['documentSeed']['sha256'] ?? null) !== $snapshot['sha256'])) {
        return false;
    }
    if ($immutable && (($binding['documentSeed']['sha256'] ?? null) !== $snapshot['sha256']
        || ($binding['format'] ?? null) !== $snapshot['format'])) {
        return false;
    }
    $page = $immutable
        ? _stattic_wordpress_page_snapshot_post($route, $snapshot)
        : spacefast_content_sync_find_post($route['bindingId'], $binding, false);
    if (!is_object($page) || (string) ($page->post_status ?? '') !== 'publish') {
        return false;
    }
    $postId = (int) ($page->ID ?? 0);
    $spaceMeta = defined('SPACEFAST_CONTENT_SPACE_META')
        ? (string) constant('SPACEFAST_CONTENT_SPACE_META')
        : '_spacefast_space_id';
    $owner = function_exists('get_post_meta') ? get_post_meta($postId, $spaceMeta, true) : null;
    if (!$immutable && (!is_string($owner) || !hash_equals($spaceId, $owner))) {
        return false;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        _stattic_method_decline(['GET', 'HEAD']);
        return false;
    }

    $title = _stattic_wordpress_page_escape((string) ($page->post_title ?? ''));
    $description = trim((string) ($page->post_excerpt ?? ''));
    // A resolved template renders INSTEAD of the post's own content: its markup
    // is what core/post-content and core/post-title read the post through, and
    // both mean nothing without $GLOBALS['post'] set around the filter.
    // Snapshot document code must not flow through an editor-mutated template.
    $template = $immutable ? null : _stattic_wordpress_page_template_markup($page);
    $content = $template ?? (string) ($page->post_content ?? '');
    if (function_exists('setup_postdata')) {
        $GLOBALS['post'] = $page;
        setup_postdata($page);
    }
    if (function_exists('apply_filters')) {
        // `the_content` is where do_blocks lives, so this one call is what runs
        // the real render_block over a dynamic block like core/query. The lane
        // runs no main query and never will: a core/query with inherit:false
        // builds its own, and pre_get_posts scopes it to this Space.
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
        . '</head><body class="sf-content-page">'
        // A template brings its own document structure — the managed theme's is
        // a core/group with tagName main — so wrapping it again would nest one
        // main inside another. Only the last-resort rendering supplies a frame.
        . ($template === null
            ? '<main><article><h1>' . $title . '</h1>' . $content . '</article></main>'
            : $content)
        . $wordpressFooter . '</body></html>';

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8', true);
    header('Cache-Control: private, no-store', true);
    header('X-Content-Type-Options: nosniff', true);
    if ($requestMethod !== 'HEAD') {
        echo $document;
    }
    exit;
}

/**
 * The block template this document renders through, or null to fall back to the
 * lane's own heading-and-content rendering.
 *
 * Three sources, in order, and each is one bounded read:
 *
 * 1. The Space's own template — the one its release implies, or the one a human
 *    edited in the site editor. Per Space by construction, because the kernel
 *    resolves it against this request's Space id.
 * 2. The managed theme's `templates/index.html`, so a Space that never opened
 *    the site editor still renders through the same markup the editor shows it.
 * 3. Nothing, which leaves today's rendering exactly as it was.
 */
function _stattic_wordpress_page_template_markup(object $post): ?string
{
    if (function_exists('spacefast_content_template_for_post')) {
        $template = spacefast_content_template_for_post($post);
        $markup = is_object($template) ? (string) ($template->content ?? '') : '';
        if (trim($markup) !== '') {
            return $markup;
        }
    }
    if (function_exists('get_theme_file_path')) {
        $file = get_theme_file_path('templates/index.html');
        $markup = is_string($file) && is_file($file) ? file_get_contents($file) : false;
        if (is_string($markup) && trim($markup) !== '') {
            return $markup;
        }
    }
    return null;
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

/** Read only the route's sealed version artifact, never a live post or source file. */
function _stattic_wordpress_page_snapshot(array $context, array $route): ?array
{
    $id = $route['id'] ?? null;
    $privateRoot = $context['private_root'] ?? null;
    $spaceId = $context['space_id'] ?? null;
    $versionId = $context['version_id'] ?? null;
    if (!is_string($id) || preg_match('/\Apage\.[a-f0-9]{32}\z/D', $id) !== 1
        || !is_string($privateRoot) || !is_string($spaceId) || !is_string($versionId)) {
        return null;
    }
    require_once __DIR__ . '/../shared/storage.php';
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    $entry = $catalog['paths']['_spacefast/pages/documents/' . $id . '.json']['source'] ?? null;
    $object = _stattic_runtime_catalog_object($entry);
    $sha = $object['sha'] ?? null;
    $length = $object['size'] ?? null;
    if (!is_string($sha) || !is_int($length) || $length < 1 || $length > 2000000) {
        return null;
    }
    $bytes = _stattic_v4_blob_contents($context, $sha);
    if (!is_string($bytes) || strlen($bytes) !== $length
        || !hash_equals(preg_replace('/^sha256:/', '', $sha), hash('sha256', $bytes))) {
        return null;
    }
    $seed = json_decode($bytes, true);
    if (!is_array($seed) || ($seed['bindingId'] ?? null) !== ($route['bindingId'] ?? null)
        || !in_array($seed['format'] ?? null, ['md', 'html', 'tsx'], true)
        || !is_string($seed['modelRevision'] ?? null)
        || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $seed['modelRevision']) !== 1
        || !is_string($seed['text'] ?? null) || strlen($seed['text']) > 1000000
        || !is_string($seed['sha256'] ?? null)
        || !hash_equals('sha256:' . hash('sha256', $seed['text']), $seed['sha256'])) {
        return null;
    }
    return $seed;
}

function _stattic_wordpress_page_snapshot_post(array $route, array $seed): object
{
    $post = (object) [
        'ID' => 0,
        'post_type' => ($seed['postType'] ?? null) === 'post' ? 'post' : 'page',
        'post_status' => 'publish',
        'post_name' => $route['id'],
        'post_title' => is_string($seed['title'] ?? null) ? $seed['title'] : ($route['path'] === '/' ? 'Home' : ucwords(str_replace('-', ' ', basename($route['path'])))),
        'post_excerpt' => '',
        'post_content' => $seed['format'] === 'tsx'
            ? $seed['text']
            : spacefast_content_sync_to_blocks($seed['format'], $seed['text']),
        'post_author' => 0,
        'post_date' => '1970-01-01 00:00:00',
        'post_date_gmt' => '1970-01-01 00:00:00',
        'filter' => 'raw',
    ];
    return class_exists('WP_Post') ? new WP_Post($post) : $post;
}

function _stattic_wordpress_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
