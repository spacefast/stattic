<?php
/**
 * Plugin Name: Spacefast Content Kernel
 * Description: Schema-defined collections and batched headless content for Spacefast Spaces.
 * Version: 1
 */
declare(strict_types=1);
// The kernel's one dependency on the engine tree. shared/private-tree.php has
// no requires of its own and decides nothing on the kernel's behalf: it holds
// the containment-guarded delete and the verified pointer publish that the
// kernel would otherwise open-code (and once did, unguarded and @-suppressed).
// Relative to this file, so it resolves inside whichever immutable release
// loaded the kernel.
require_once __DIR__ . '/../shared/private-tree.php';
require_once __DIR__ . '/content-model-kernel.php';
require_once __DIR__ . '/content-principals.php';
require_once __DIR__ . '/content-markdown.php';
require_once __DIR__ . '/content-html.php';
require_once __DIR__ . '/content-source-sync.php';
require_once __DIR__ . '/content-source-journal.php';
// The site editor's half: the templates a release implies, supplied per request
// so they are per Space, plus the scoping that keeps a human's edits private.
require_once __DIR__ . '/content-templates.php';
// The users feature: WordPress's own user model over the principal substrate,
// exposed through the Abilities API. Default-on, like every other capability
// this kernel activates by registering WordPress hooks at load.
require_once __DIR__ . '/content-users.php';
// The storage feature: WordPress attachments as a Space's files — a folder
// taxonomy, registered meta, and the abilities that publish them.
require_once __DIR__ . '/content-storage.php';
const SPACEFAST_CONTENT_SITE_TITLE_OPTION = 'spacefast_space_title';
const SPACEFAST_CONTENT_EXTERNAL_ID_META = '_spacefast_external_id';
const SPACEFAST_CONTENT_SPACE_META = '_spacefast_space_id';
final class Spacefast_Content_Error extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $codeName,
        string $message,
    ) {
        parent::__construct($message);
    }
}
if (function_exists('add_action')) {
    if (function_exists('_stattic_runtime_bootstrap_config')) {
        add_action('plugins_loaded', '_stattic_runtime_bootstrap_config', 0);
    }
    add_action('init', 'spacefast_content_model_register_wordpress_projection', 5);
    // The Abilities API refuses a registration made on any other action, so
    // these two are separate hooks rather than part of the projection above.
    add_action('wp_abilities_api_categories_init', 'spacefast_content_model_register_ability_category');
    add_action('wp_abilities_api_init', 'spacefast_content_model_register_active_abilities');
    add_action('acf/init', 'spacefast_content_model_register_scf_field_groups', 5);
    add_action('init', 'spacefast_content_source_journal_install', 4);
    add_action('add_attachment', 'spacefast_content_scope_attachment');
    add_action('save_post', 'spacefast_content_scope_post', 1, 2);
    // Late, so the Space meta and the revision the save cut are both in place.
    add_action('save_post', 'spacefast_content_source_journal_record_save', 20, 2);
    add_action('pre_get_posts', 'spacefast_content_scope_post_query');
    add_action('admin_init', 'spacefast_content_lock_admin', 1);
    add_action('admin_menu', 'spacefast_content_admin_menu', 999);
    add_action('admin_enqueue_scripts', 'spacefast_content_admin_assets', 1000);
    add_action('admin_footer', 'spacefast_content_admin_handshake', 1000);
    add_action('login_init', 'spacefast_content_block_wordpress_login', 1);
    add_action('send_headers', 'spacefast_content_admin_frame_headers', 1);
    add_action('admin_head', 'spacefast_content_admin_frame_headers', 1);
    remove_action('admin_init', 'send_frame_options_header');
    // Spacefast owns this site's URL space: the serving lane resolves a path and
    // answers it, and a Space's content is published at a flat `/<slug>`.
    // WordPress's canonical redirect exists to move readers to the URL WordPress
    // would have chosen, which on a managed site is a different answer to the
    // same question — it 301'd every published slug at the URL we handed out.
    // Removing it leaves exactly one authority for what a path means. Both
    // halves are needed: the action is what fires on a front-controller request,
    // and the filter is what any direct caller of redirect_canonical() consults.
    remove_action('template_redirect', 'redirect_canonical');
    add_filter('redirect_canonical', '__return_false');
    add_filter('xmlrpc_enabled', '__return_false');
    add_filter('wp_is_application_passwords_available', '__return_false');
    add_filter('rest_authentication_errors', 'spacefast_content_disable_rest_api', 1);
    add_filter('rest_request_before_callbacks', 'spacefast_content_gate_users_rest', 10, 3);
    add_filter('pre_option_blogname', 'spacefast_content_managed_site_title');
    add_filter('rest_user_query', 'spacefast_content_scope_rest_user_query', 10, 2);
    add_filter('site_url', 'spacefast_content_request_url', 1, 4);
    add_filter('home_url', 'spacefast_content_request_url', 1, 4);
    add_filter('page_link', 'spacefast_content_model_page_link', 10, 2);
    add_filter('upload_dir', 'spacefast_content_scope_upload_dir');
    add_filter('show_admin_bar', '__return_false');
    add_filter('automatic_updater_disabled', '__return_true');
    add_filter('auto_update_core', '__return_false');
    add_filter('auto_update_plugin', '__return_false');
    add_filter('auto_update_theme', '__return_false');
    add_filter('comments_open', '__return_false', 20);
    add_filter('pings_open', '__return_false', 20);
    add_filter('wp_is_site_protected_by_basic_auth', '__return_false');
    add_filter('admin_email_check_interval', '__return_zero');
    add_filter('admin_footer_text', 'spacefast_content_admin_footer');
    add_filter('ajax_query_attachments_args', 'spacefast_content_scope_attachment_query');
    add_filter('map_meta_cap', 'spacefast_content_scope_meta_cap', 10, 4);
    // The by-id half of publicRead: check_read_permission short-circuits on a
    // published post, so the read_post cap gate never runs for the single-item
    // REST route. rest_prepare_{post_type} does, for every served post type.
    add_filter('rest_prepare_post', 'spacefast_content_rest_guard_single_read', 10, 3);
    add_filter('rest_prepare_page', 'spacefast_content_rest_guard_single_read', 10, 3);
    add_filter('rest_prepare_attachment', 'spacefast_content_rest_guard_single_read', 10, 3);
    add_filter('get_block_templates', 'spacefast_content_templates_filter', 10, 3);
    add_filter('update_footer', '__return_empty_string', 999);
    add_filter('acf/settings/show_admin', '__return_false');
    add_filter('acf/settings/show_updates', '__return_false');
    add_filter('acf/load_value', 'spacefast_content_load_scf_value', 10, 3);
    add_filter('acf/update_value', 'spacefast_content_prepare_scf_value', 10, 3);
    add_filter('acf/validate_value', 'spacefast_content_validate_scf_value', 10, 4);
}
function spacefast_content_space_id(): string
{
    $spaceId = $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null;
    return is_string($spaceId)
        && strlen($spaceId) >= 1
        && strlen($spaceId) <= 128
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $spaceId) === 1
        ? $spaceId
        : '';
}

function spacefast_content_require_space_id(): string
{
    $spaceId = spacefast_content_space_id();
    if ($spaceId === '') {
        throw new Spacefast_Content_Error(403, 'content_space_scope_invalid', 'Content requires a valid Space scope.');
    }
    return $spaceId;
}

function spacefast_content_request_origin(): string
{
    // Recomputed on every site_url/home_url/upload_dir filter, of which one
    // request fires many; the request host and cookie-secure verdict are both
    // request-invariant, so the first non-empty answer stands for the rest.
    static $origin = null;
    if (is_string($origin)) {
        return $origin;
    }
    if (spacefast_content_space_id() === '') {
        return '';
    }
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (preg_match('/^[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$/', $host) !== 1) {
        return '';
    }
    $secure = !function_exists('_stattic_cookies_secure') || _stattic_cookies_secure();
    return $origin = ($secure ? 'https://' : 'http://') . $host;
}

function spacefast_content_request_url(mixed $url): mixed
{
    $origin = spacefast_content_request_origin();
    if ($origin === '' || !is_string($url)) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    return $origin
        . (is_string($parts['path'] ?? null) ? $parts['path'] : '')
        . (is_string($parts['query'] ?? null) ? '?' . $parts['query'] : '')
        . (is_string($parts['fragment'] ?? null) ? '#' . $parts['fragment'] : '');
}

function spacefast_content_scope_upload_dir(array $uploads): array
{
    $spaceId = spacefast_content_space_id();
    $baseDir = $uploads['basedir'] ?? null;
    $baseUrl = $uploads['baseurl'] ?? null;
    if ($spaceId === '' || !is_string($baseDir) || !is_string($baseUrl)) {
        return $uploads;
    }
    $subdir = is_string($uploads['subdir'] ?? null) ? $uploads['subdir'] : '';
    $origin = spacefast_content_request_origin();
    if ($origin === '' || !defined('ABSPATH')) {
        return $uploads;
    }
    $spaceHash = substr(hash('sha256', $spaceId), 0, 32);
    $uploads['basedir'] = rtrim((string) ABSPATH, '/')
        . '/.stattic/storage/spaces/' . $spaceId . '/content-media';
    $uploads['baseurl'] = $origin . '/__spacefast/content-media/' . $spaceHash;
    $uploads['path'] = $uploads['basedir'] . $subdir;
    $uploads['url'] = $uploads['baseurl'] . $subdir;
    return $uploads;
}

function spacefast_content_managed_site_title(mixed $pre): mixed
{
    if (!function_exists('get_option')) {
        return $pre;
    }
    $siteTitle = get_option(SPACEFAST_CONTENT_SITE_TITLE_OPTION, null);
    return is_string($siteTitle) && trim($siteTitle) !== '' && strlen($siteTitle) <= 1020
        ? $siteTitle
        : $pre;
}

function spacefast_content_block_wordpress_login(): void
{
    if (function_exists('wp_die')) {
        wp_die('WordPress sign-in is managed by Spacefast.', 'Not found', ['response' => 404]);
    }
    http_response_code(404);
    exit;
}

/**
 * REST is reachable by exactly the doors the gate opens it for: an editor
 * session, and the WP API door in custom-redirects.php, which asks the access
 * engine and, on admission, sets SPACEFAST_CONTENT_REST_ADMITTED. Everything
 * else — a stray front-end request, a request that reached WordPress with no
 * Spacefast context — still gets the 404 that keeps a managed Space from
 * exposing WordPress's API by accident. This filter is not the authorization:
 * the gate already ran the access engine, so whether REST answers at all is the
 * gate's admission, not the resolved role. The role, when there is one, is what
 * user_has_cap projects; a null role on an admitted request is an anonymous
 * caller, which WordPress answers unauthenticated exactly as it would on its own.
 */
function spacefast_content_disable_rest_api(mixed $result): mixed
{
    if (
        $result !== null
        || !class_exists('WP_Error')
        || (
            spacefast_content_space_id() !== ''
            && (
                (int) ($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? 0) > 0
                || spacefast_content_principal_role() !== null
                || ($GLOBALS['SPACEFAST_CONTENT_REST_ADMITTED'] ?? false) === true
            )
        )
    ) {
        return $result;
    }
    return new WP_Error(
        'spacefast_rest_disabled',
        'WordPress REST access is managed by Spacefast.',
        ['status' => 404]
    );
}

/**
 * A content-admin session carries the control plane's signed content.users
 * decision. WordPress administrators otherwise satisfy core's users endpoint
 * permissions, so hiding the Zero route alone would leave the same directory
 * reachable through a nonce-bearing REST request. WordPress launches and the
 * separate WP API door carry no Zero allowlist and keep their existing access.
 */
function spacefast_content_gate_users_rest(mixed $response, mixed $handler, mixed $request): mixed
{
    $access = $GLOBALS['SPACEFAST_CONTENT_ADMIN_ACCESS'] ?? null;
    if (
        $response !== null
        || !class_exists('WP_Error')
        || !is_array($access)
        || ($access['surface'] ?? null) !== 'zero'
        || in_array('users', $access['allowed_screens'] ?? [], true)
        || !is_object($request)
        || !method_exists($request, 'get_route')
    ) {
        return $response;
    }
    $route = $request->get_route();
    if (!is_string($route) || preg_match('#^/wp/v2/users(?:/|$)#', $route) !== 1) {
        return $response;
    }
    return new WP_Error(
        'spacefast_content_users_unavailable',
        'Users are not enabled for this content editor session.',
        ['status' => 403]
    );
}

function spacefast_content_scope_rest_user_query(array $args, mixed $request): array
{
    // Scope core's user queries to this request's Space, the same membership
    // meta spacefast_content_users_list() filters on, so /wp/v2/users is the
    // Space's directory and not the box's. The scope depends only on the Space,
    // never on the caller: both REST doors reach this filter, and the WP API
    // door (spacefast_content_disable_rest_api) admits on a principal role
    // while setting no SPACEFAST_CONTENT_ADMIN_USER_ID. Keying on that global
    // would leave the API door unscoped, so the guard is the Space alone.
    $spaceId = spacefast_content_space_id();
    if ($spaceId !== '') {
        $args['meta_key'] = SPACEFAST_CONTENT_SPACE_META;
        $args['meta_value'] = $spaceId;
    }
    return $args;
}

function spacefast_content_lock_admin(): void
{
    if (function_exists('_stattic_runtime_bootstrap_config')) {
        _stattic_runtime_bootstrap_config();
    }
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
    if (!defined('DISALLOW_FILE_MODS')) {
        define('DISALLOW_FILE_MODS', true);
    }
    if (!defined('AUTOMATIC_UPDATER_DISABLED')) {
        define('AUTOMATIC_UPDATER_DISABLED', true);
    }
    if (!defined('WP_AUTO_UPDATE_CORE')) {
        define('WP_AUTO_UPDATE_CORE', false);
    }
    spacefast_content_admin_frame_headers();
    $page = basename((string) ($GLOBALS['pagenow'] ?? ''));
    if ($page === 'index.php' && function_exists('wp_safe_redirect') && function_exists('admin_url')) {
        wp_safe_redirect(admin_url('edit.php'));
        exit;
    }
    if ($page !== '' && !spacefast_content_admin_page_allowed($page) && function_exists('wp_die')) {
        wp_die('Spacefast manages this WordPress screen.', 'Unavailable', ['response' => 403]);
    }
    spacefast_content_enforce_admin_resource($page);
}

function spacefast_content_enforce_admin_resource(string $page): void
{
    if (!function_exists('wp_die')) {
        return;
    }
    if ($page === 'edit.php' || $page === 'post-new.php') {
        $postType = (string) ($_GET['post_type'] ?? ($GLOBALS['typenow'] ?? ''));
        if ($postType === '' && $page === 'edit.php') {
            $postType = 'post';
        }
        if (spacefast_content_collection_for_post_type($postType) === null) {
            wp_die('Spacefast manages this WordPress content type.', 'Unavailable', ['response' => 403]);
        }
    }
    if (in_array($page, ['post.php', 'revision.php', 'media.php'], true) && function_exists('get_post')) {
        $postId = (int) (
            $_GET['post']
            ?? $_GET['revision']
            ?? $_GET['attachment_id']
            ?? $_POST['post_ID']
            ?? $_POST['attachment_id']
            ?? 0
        );
        $post = $postId > 0 ? get_post($postId) : null;
        if (is_object($post) && ($page === 'revision.php' || (string) ($post->post_type ?? '') === 'revision')) {
            $post = get_post((int) ($post->post_parent ?? 0));
        }
        $postType = is_object($post) ? (string) ($post->post_type ?? '') : '';
        $allowed = $postType === 'attachment'
            ? spacefast_content_post_belongs_to_space((int) ($post->ID ?? 0))
            : spacefast_content_collection_for_post_type($postType) !== null
                && spacefast_content_post_belongs_to_space((int) ($post->ID ?? 0));
        if (!$allowed) {
            wp_die('This content belongs to another Space.', 'Unavailable', ['response' => 403]);
        }
    }
    if ($page === 'site-editor.php') {
        // The editor addresses one resource at a time. A template id belonging
        // to another Space is a 403 here rather than a rendered editor, which is
        // the same answer post.php gives for another Space's post.
        $resourceId = is_string($_GET['postId'] ?? null) ? $_GET['postId'] : '';
        $postType = is_string($_GET['postType'] ?? null) ? $_GET['postType'] : '';
        if ($postType !== '' && !in_array($postType, SPACEFAST_CONTENT_TEMPLATE_POST_TYPES, true)) {
            wp_die('Spacefast manages this WordPress content type.', 'Unavailable', ['response' => 403]);
        }
        if (!spacefast_content_templates_resource_allowed($resourceId)) {
            wp_die('This content belongs to another Space.', 'Unavailable', ['response' => 403]);
        }
    }
    if ($page === 'admin.php') {
        $screen = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
        if (!defined('ZERO_ADMIN_PAGE_SLUG') || $screen !== ZERO_ADMIN_PAGE_SLUG) {
            wp_die('Spacefast manages this WordPress screen.', 'Unavailable', ['response' => 403]);
        }
    }
    if ($page === 'tools.php' && !spacefast_content_redirection_screen_requested()) {
        wp_die('Spacefast manages this WordPress screen.', 'Unavailable', ['response' => 403]);
    }
}

function spacefast_content_admin_page_allowed(string $page): bool
{
    if ($page === 'site-editor.php') {
        // The one screen this task admits, and only on a Space that has content
        // to edit — the same gate every other content surface uses. A release
        // the kernel cannot read is not a Space with an editor.
        try {
            return spacefast_content_model_active_release() !== null;
        } catch (Throwable $error) {
            error_log('spacefast content site editor refused: ' . get_debug_type($error));
            return false;
        }
    }
    if ($page === 'tools.php') {
        return spacefast_content_redirection_screen_requested();
    }
    return in_array($page, [
        'admin-ajax.php',
        'admin.php',
        'async-upload.php',
        'edit-tags.php',
        'edit.php',
        'load-scripts.php',
        'load-styles.php',
        'media-new.php',
        'media.php',
        'post-new.php',
        'post.php',
        'revision.php',
        'term.php',
        'upload.php',
    ], true);
}

function spacefast_content_redirection_screen_requested(): bool
{
    $screen = $_GET['page'] ?? null;
    return is_string($screen) && $screen === 'redirection.php';
}

function spacefast_content_admin_frame_headers(): void
{
    if (!headers_sent()) {
        header_remove('X-Frame-Options');
        $sessionOrigin = $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] ?? null;
        $origin = is_string($sessionOrigin)
            ? $sessionOrigin
            : (function_exists('_stattic_dashboard_origin') ? _stattic_dashboard_origin() : '');
        header("Content-Security-Policy: frame-ancestors 'self'" . ($origin === '' ? '' : ' ' . $origin), true);
        header('Cache-Control: private, no-store', true);
        header('Referrer-Policy: same-origin', true);
    }
}

function spacefast_content_admin_footer(): string
{
    return 'Content is managed by Spacefast.';
}

function spacefast_content_admin_handshake_payload(?int $now = null): ?array
{
    $origin = $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] ?? null;
    $expiresAt = $GLOBALS['SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT'] ?? null;
    $now ??= time();
    if (
        !is_string($origin)
        || filter_var($origin, FILTER_VALIDATE_URL) === false
        || !is_int($expiresAt)
        || $expiresAt <= $now
    ) {
        return null;
    }
    return [
        'type' => 'spacefast.content.admin.ready',
        'version' => 1,
        'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        'origin' => $origin,
    ];
}

function spacefast_content_admin_handshake(): void
{
    $payload = spacefast_content_admin_handshake_payload();
    if ($payload === null) {
        return;
    }
    $origin = $payload['origin'];
    unset($payload['origin']);
    $encodedPayload = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $encodedOrigin = json_encode($origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($encodedPayload) || !is_string($encodedOrigin)) {
        return;
    }
    echo '<script>window.parent.postMessage(' . $encodedPayload . ',' . $encodedOrigin . ');</script>';
}

function spacefast_content_admin_menu(): void
{
    if (!function_exists('remove_menu_page')) {
        return;
    }
    foreach ([
        'index.php',
        'edit.php',
        'edit.php?post_type=page',
        'edit-comments.php',
        'themes.php',
        'plugins.php',
        'users.php',
        'tools.php',
        'options-general.php',
        'profile.php',
        'edit.php?post_type=acf-field-group',
    ] as $slug) {
        remove_menu_page($slug);
    }
}

function spacefast_content_admin_assets(): void
{
    $postType = (string) ($_GET['post_type'] ?? ($GLOBALS['typenow'] ?? ''));
    $collection = spacefast_content_collection_for_post_type($postType);
    if ($collection === null) {
        return;
    }
    if (!function_exists('wp_enqueue_media')) {
        return;
    }
    wp_enqueue_media();
    if (function_exists('wp_add_inline_script')) {
        wp_add_inline_script('media-editor', <<<'JS'
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-spacefast-media]');
  if (!button || !window.wp || !wp.media) return;
  event.preventDefault();
  var input = document.getElementById(button.getAttribute('data-spacefast-media'));
  var multiple = button.getAttribute('data-multiple') === 'true';
  var frame = wp.media({ title: 'Choose media', button: { text: 'Use media' }, multiple: multiple });
  frame.on('select', function () {
    var ids = frame.state().get('selection').map(function (item) { return item.get('id'); });
    input.value = multiple ? ids.join(',') : (ids[0] || '');
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  frame.open();
});
JS);
    }
}

function spacefast_content_scf_key(string $prefix, string $resourceId, string $fieldName = ''): string
{
    return $prefix . '_' . substr(hash(
        'sha256',
        spacefast_content_require_space_id() . '|' . $resourceId . '|' . $fieldName
    ), 0, 24);
}

function spacefast_content_scf_field(string $resourceId, string $name, array $definition): array
{
    $type = (string) ($definition['type'] ?? 'text');
    $field = [
        'key' => spacefast_content_scf_key('field', $resourceId, $name),
        'label' => (string) ($definition['label'] ?? $name),
        'name' => (string) ($definition['name'] ?? $name),
        'instructions' => (string) ($definition['description'] ?? ''),
        'required' => !empty($definition['required']) ? 1 : 0,
        'conditional_logic' => 0,
        'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
        'spacefast_definition' => $definition,
    ];
    if ($type === 'text') {
        $field['type'] = !empty($definition['multiline']) ? 'textarea' : 'text';
        $field['maxlength'] = isset($definition['maxLength']) ? (int) $definition['maxLength'] : '';
        if ($field['type'] === 'textarea') {
            $field['rows'] = 8;
            $field['new_lines'] = '';
        }
    } elseif ($type === 'markdown') {
        $field += ['type' => 'textarea', 'rows' => 16, 'new_lines' => ''];
    } elseif ($type === 'richText') {
        $field += ['type' => 'wysiwyg', 'toolbar' => 'full', 'media_upload' => 1];
    } elseif ($type === 'number') {
        $field['type'] = 'number';
    } elseif ($type === 'boolean') {
        $field += ['type' => 'true_false', 'ui' => 1, 'default_value' => 0];
    } elseif ($type === 'date') {
        $field += [
            'type' => 'date_picker',
            'display_format' => 'Y-m-d',
            'return_format' => 'Y-m-d',
            'first_day' => 1,
        ];
    } elseif ($type === 'select') {
        $choices = [];
        foreach (is_array($definition['options'] ?? null) ? $definition['options'] : [] as $option) {
            $choices[(string) $option] = (string) $option;
        }
        $field += [
            'type' => 'select',
            'choices' => $choices,
            'allow_null' => empty($definition['required']) ? 1 : 0,
            'multiple' => !empty($definition['multiple']) ? 1 : 0,
            'return_format' => 'value',
            'ui' => 1,
        ];
    } elseif ($type === 'relation') {
        $relatedNames = is_array($definition['collections'] ?? null)
            ? $definition['collections']
            : [$definition['collection'] ?? ''];
        $relatedPostTypes = [];
        foreach ($relatedNames as $relatedName) {
            if (is_string($relatedName) && $relatedName !== '') {
                $projection = spacefast_content_model_collection_projection($relatedName);
                if ($projection !== null) {
                    $relatedPostTypes[] = $projection['post_type'];
                }
            }
        }
        if ($relatedPostTypes === []) {
            throw new Spacefast_Content_Error(500, 'content_scf_relation_invalid', 'A relationship field must name a collection.');
        }
        $field += [
            'type' => !empty($definition['multiple']) ? 'relationship' : 'post_object',
            'post_type' => array_values(array_unique($relatedPostTypes)),
            'taxonomy' => [],
            'allow_null' => empty($definition['required']) ? 1 : 0,
            'multiple' => !empty($definition['multiple']) ? 1 : 0,
            'return_format' => 'id',
        ];
    } elseif ($type === 'media') {
        $field += !empty($definition['multiple'])
            ? ['type' => 'gallery', 'return_format' => 'id', 'preview_size' => 'medium']
            : ['type' => 'file', 'return_format' => 'id', 'library' => 'all'];
    } elseif ($type === 'json') {
        $field += [
            'type' => 'textarea',
            'rows' => 12,
            'new_lines' => '',
            'instructions' => trim((string) ($field['instructions'] ?? '') . ' Enter valid JSON.'),
        ];
    } else {
        throw new Spacefast_Content_Error(500, 'content_scf_field_invalid', 'A content field cannot be rendered by Secure Custom Fields.');
    }
    return $field;
}

function spacefast_content_validate_scf_value(
    mixed $valid,
    mixed $value,
    mixed $field,
    mixed $_input
): mixed {
    if ($valid !== true || !is_array($field) || !is_array($field['spacefast_definition'] ?? null)) {
        return $valid;
    }
    $definition = $field['spacefast_definition'];
    if (($definition['type'] ?? null) === 'json') {
        json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? true : 'Enter valid JSON.';
    }
    if (!in_array(($definition['type'] ?? null), ['media', 'relation'], true)) {
        return $valid;
    }
    $normalized = !empty($definition['multiple'])
        ? array_values(array_filter(array_map('intval', is_array($value) ? $value : [])))
        : (int) $value;
    return spacefast_content_model_validate_reference_value($definition, $normalized)
        ? true
        : 'The referenced content does not belong to this Space or resource.';
}

function spacefast_content_prepare_scf_value(mixed $value, mixed $postId, mixed $field): mixed
{
    unset($postId);
    $definition = is_array($field) ? ($field['spacefast_definition'] ?? null) : null;
    if (!is_array($definition)) {
        return $value;
    }
    if (($definition['type'] ?? null) === 'json' && is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        }
    }
    $optionName = $definition['globalOption'] ?? null;
    $globalField = $definition['globalField'] ?? null;
    if (is_string($optionName) && is_string($globalField) && function_exists('update_option')) {
        $global = function_exists('get_option') ? get_option($optionName, []) : [];
        $global = is_array($global) ? $global : [];
        $global[$globalField] = $value;
        update_option($optionName, $global, false);
    }
    return $value;
}

function spacefast_content_load_scf_value(mixed $value, mixed $postId, mixed $field): mixed
{
    unset($postId);
    $definition = is_array($field) ? ($field['spacefast_definition'] ?? null) : null;
    $optionName = is_array($definition) ? ($definition['globalOption'] ?? null) : null;
    $globalField = is_array($definition) ? ($definition['globalField'] ?? null) : null;
    if (!is_string($optionName) || !is_string($globalField) || !function_exists('get_option')) {
        return $value;
    }
    $global = get_option($optionName, []);
    return is_array($global) && array_key_exists($globalField, $global)
        ? $global[$globalField]
        : $value;
}

function spacefast_content_scope_post(int $postId, object $post): void
{
    $postType = (string) ($post->post_type ?? '');
    $parentId = (int) ($post->post_parent ?? 0);
    $scopedRevision = $postType === 'revision' && spacefast_content_post_belongs_to_space($parentId);
    // The site editor's own post types are stamped as an explicit branch rather
    // than by widening the collection map: a template is not a collection, but
    // an unstamped one leaks into every co-hosted Space's editor, and an
    // unstamped wp_global_styles gives them all one shared appearance.
    $scopedTemplate = in_array($postType, SPACEFAST_CONTENT_TEMPLATE_POST_TYPES, true);
    if (
        spacefast_content_space_id() === ''
        || (!$scopedRevision
            && !$scopedTemplate
            && spacefast_content_collection_for_post_type($postType) === null)
        || !function_exists('update_post_meta')
    ) {
        return;
    }
    update_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id());
    if ($scopedTemplate) {
        spacefast_content_templates_scope_theme($postId, $postType);
    }
}

function spacefast_content_scope_attachment(int $attachmentId): void
{
    if (spacefast_content_space_id() !== '' && function_exists('update_post_meta')) {
        update_post_meta($attachmentId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id());
    }
}

function spacefast_content_post_belongs_to_space(int $postId): bool
{
    return $postId > 0
        && spacefast_content_space_id() !== ''
        && function_exists('get_post_meta')
        && hash_equals(
            spacefast_content_space_id(),
            (string) get_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, true)
        );
}

function spacefast_content_space_meta_clause(): array
{
    return [
        'key' => SPACEFAST_CONTENT_SPACE_META,
        'value' => spacefast_content_require_space_id(),
        'compare' => '=',
    ];
}

function spacefast_content_scope_meta_query(mixed $query): array
{
    $query = is_array($query) ? $query : [];
    if ($query === []) {
        return [spacefast_content_space_meta_clause()];
    }
    $relation = strtoupper((string) ($query['relation'] ?? 'AND'));
    foreach ($query as $clause) {
        if (
            $relation === 'AND'
            && is_array($clause)
            && ($clause['key'] ?? null) === SPACEFAST_CONTENT_SPACE_META
            && ($clause['value'] ?? null) === spacefast_content_space_id()
            && ($clause['compare'] ?? '=') === '='
        ) {
            return $query;
        }
    }
    return [
        'relation' => 'AND',
        spacefast_content_space_meta_clause(),
        $query,
    ];
}

/**
 * Whether this request may read a resource that is not publicRead.
 *
 * True for an editor session and for a caller the access engine resolved to a
 * WordPress role; false for an anonymous visitor, which is what an island on a
 * public page is. Deliberately not `is_user_logged_in()`: the WP API door
 * admits a machine caller without ever creating a user for a person, and the
 * role is the thing that says how much of WordPress a request may touch. It is
 * the same pair spacefast_content_disable_rest_api() reads, for the same reason
 * — there is one policy here, not two.
 */
function spacefast_content_may_read_private_resources(): bool
{
    return (int) ($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? 0) > 0
        || spacefast_content_principal_role() !== null;
}

/**
 * Resource ids the active release marks `publicRead: false`.
 *
 * Collections only: a collection is projected as a term, and a term is the one
 * thing a query can exclude. Throws exactly where the release read throws, so a
 * caller can tell "nothing is private" from "the private set is unknown".
 *
 * @return list<string>
 */
function spacefast_content_private_resource_ids(): array
{
    $contentModel = spacefast_content_model_active_release();
    $ids = [];
    foreach (is_array($contentModel['postTypes'] ?? null) ? $contentModel['postTypes'] : [] as $resource) {
        if (
            is_array($resource)
            && ($resource['kind'] ?? '') === 'collection'
            && ($resource['publicRead'] ?? true) !== true
            && is_string($resource['id'] ?? null)
        ) {
            $ids[] = $resource['id'];
        }
    }
    return $ids;
}

/**
 * The collection terms this request may not read: a list of term slugs, the
 * empty string for "every collection term", or null when nothing is hidden.
 *
 * The empty string is the fail-closed answer for a release that exists but
 * cannot be read. An unknown private set admits nothing private, and costs
 * nothing readable: every declared collection carries a term and posts, pages
 * and media carry none, so they answer exactly as they did. A Space with no
 * release at all has no declared collection to hide, and gets no clause.
 *
 * @return list<string>|string|null
 */
function spacefast_content_private_collection_terms(): array|string|null
{
    if (spacefast_content_may_read_private_resources()) {
        return null;
    }
    try {
        $resourceIds = spacefast_content_private_resource_ids();
    } catch (Throwable $error) {
        error_log('spacefast content privacy set unavailable: ' . get_debug_type($error));
        return '';
    }
    $terms = [];
    foreach ($resourceIds as $resourceId) {
        $terms[] = spacefast_content_model_collection_term_slug(
            spacefast_content_require_space_id(),
            $resourceId
        );
    }
    return $terms === [] ? null : $terms;
}

function spacefast_content_scope_tax_query(mixed $query, array $clause): array
{
    $query = is_array($query) ? $query : [];
    if ($query === []) {
        return [$clause];
    }
    // pre_get_posts can reach one query object twice; the clause is idempotent
    // and must not stack, exactly like the Space meta clause above.
    foreach ($query as $existing) {
        if ($existing === $clause) {
            return $query;
        }
    }
    return ['relation' => 'AND', $clause, $query];
}

/**
 * The collection a REST read named, or null when it named none.
 *
 * The generated typed client's `content.<collection>.list()` sends
 * `zero_collection=<term slug>`, because a slug is the only name a build can
 * know — core's own taxonomy filters take term ids, which no capsule can
 * predict. Nothing in WordPress reads an unregistered collection parameter, so
 * without this the read answered with every post in the Space.
 *
 * REST only: this is the lane the typed client speaks on, and the page-serving
 * lane resolves what a path means from the route, never from a query string a
 * visitor could append. The value is matched against the term-slug shape
 * `spacefast_content_model_collection_term_slug()` mints rather than trusted;
 * a slug that names no term simply matches nothing, and one that names a
 * private collection is still excluded by the clause below.
 */
function spacefast_content_requested_collection_term(): ?string
{
    if (!defined('REST_REQUEST') || REST_REQUEST !== true) {
        return null;
    }
    $requested = $_GET[SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY] ?? null;
    return is_string($requested) && preg_match('/\A[a-z0-9-]{1,190}\z/D', $requested) === 1
        ? $requested
        : null;
}

function spacefast_content_scope_post_query(mixed $query): void
{
    if (
        spacefast_content_space_id() === ''
        || !is_object($query)
        || !method_exists($query, 'get')
        || !method_exists($query, 'set')
    ) {
        return;
    }
    $query->set('meta_query', spacefast_content_scope_meta_query($query->get('meta_query')));
    $requested = spacefast_content_requested_collection_term();
    if ($requested !== null) {
        // Narrowing, never widening: the privacy exclusion is added after this
        // and both clauses must hold, so naming a private collection's slug
        // asks for the intersection of "in it" and "not in it" — nothing.
        $query->set('tax_query', spacefast_content_scope_tax_query($query->get('tax_query'), [
            'taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY,
            'field' => 'slug',
            'terms' => [$requested],
            'operator' => 'IN',
        ]));
    }
    $private = spacefast_content_private_collection_terms();
    if ($private === null) {
        return;
    }
    $clause = $private === ''
        ? ['taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'operator' => 'NOT EXISTS']
        : [
            'taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY,
            'field' => 'slug',
            'terms' => $private,
            'operator' => 'NOT IN',
        ];
    $query->set('tax_query', spacefast_content_scope_tax_query($query->get('tax_query'), $clause));
}

function spacefast_content_scope_attachment_query(array $query): array
{
    if (spacefast_content_space_id() !== '') {
        $query['meta_query'] = spacefast_content_scope_meta_query($query['meta_query'] ?? []);
    }
    return $query;
}

function spacefast_content_scope_meta_cap(array $caps, string $cap, int $userId, array $args): array
{
    if (
        spacefast_content_space_id() === ''
        || !in_array($cap, ['delete_post', 'edit_post', 'read_post'], true)
        || !isset($args[0])
        || !function_exists('get_post')
    ) {
        return $caps;
    }
    $post = get_post((int) $args[0]);
    if (!is_object($post)) {
        return $caps;
    }
    $postType = (string) ($post->post_type ?? '');
    if ($postType === 'revision') {
        $post = get_post((int) ($post->post_parent ?? 0));
        $postType = is_object($post) ? (string) ($post->post_type ?? '') : '';
    }
    $postId = is_object($post) ? (int) ($post->ID ?? 0) : 0;
    $allowedType = $postType === 'attachment'
        || spacefast_content_collection_for_post_type($postType) !== null;
    if (!$allowedType || !spacefast_content_post_belongs_to_space($postId)) {
        return ['do_not_allow'];
    }
    // The by-id half of publicRead. Without it the list lane is closed and the
    // single-item route stays open, which is the worse half of a half-fix.
    if ($cap === 'read_post' && spacefast_content_post_is_private($postId)) {
        return ['do_not_allow'];
    }
    return $caps;
}

/**
 * The single-item REST read gate.
 *
 * WordPress core answers `WP_REST_Posts_Controller::check_read_permission()`
 * true for any `publish` post before it ever maps the `read_post` cap, so the
 * map_meta_cap gate above never fires for the by-id REST route the way it does
 * for a front-end permalink — `get_item` would hand a private-collection post
 * back in full. `rest_prepare_{$post_type}` does run for a published single
 * read, so it is where the by-id half of publicRead has to live. A post the
 * list lane hides is refused here with the same 404 the exclusion produces.
 * A privileged reader is unaffected: `spacefast_content_post_is_private()`
 * returns false whenever the request may read private resources.
 */
function spacefast_content_rest_guard_single_read(mixed $response, mixed $post, mixed $request): mixed
{
    if (!is_object($post) || !class_exists('WP_Error')) {
        return $response;
    }
    $postId = (int) ($post->ID ?? 0);
    if ($postId > 0 && spacefast_content_post_is_private($postId)) {
        return new WP_Error(
            'spacefast_content_not_found',
            'This document is not available.',
            ['status' => 404]
        );
    }
    return $response;
}

/** Whether this post sits in a collection the current request may not read. */
function spacefast_content_post_is_private(int $postId): bool
{
    $private = spacefast_content_private_collection_terms();
    if ($private === null) {
        return false;
    }
    if (!function_exists('has_term')) {
        // Something is private and there is no way to ask whether this is it.
        // Refusing costs a readable post; admitting costs a private one.
        return true;
    }
    return has_term($private, SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, $postId) === true;
}

function spacefast_content_collection_for_post_type(string $postType): ?array
{
    return match ($postType) {
        'post' => spacefast_content_model_collection_projection('posts'),
        'page' => spacefast_content_model_collection_projection('pages'),
        'attachment' => spacefast_content_model_collection_projection('media'),
        default => null,
    };
}

function spacefast_content_handle_request(array $request, bool $managed): array
{
    return match ((string) ($request['operation'] ?? '')) {
        'model.stage' => spacefast_content_model_stage_release(
            $request['revision'] ?? null,
            $request['contentModelPhp'] ?? null,
            $request['artifactDigest'] ?? null,
            $managed
        ),
        'model.activate' => spacefast_content_model_activate_release($request['revision'] ?? null, $managed),
        'source.reconcile' => spacefast_content_reconcile_source($request, $managed),
        'source.acknowledge' => spacefast_content_acknowledge_source($request, $managed),
        'source.materialize' => spacefast_content_materialize_source($request, $managed),
        // Storage answers over this endpoint for a caller that can reach it.
        // No Zero handler can today -- ctx.storage is withdrawn until the
        // service transport lands -- but the dispatcher runs the ability's own
        // permission_callback, so any future caller gets the same check an
        // agent's call goes through.
        'storage.list', 'storage.get', 'storage.delete' =>
            spacefast_content_storage_dispatch((string) $request['operation'], $request),
        default => throw new Spacefast_Content_Error(400, 'content_operation_invalid', 'The content operation is not supported.'),
    };
}
