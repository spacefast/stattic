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
    add_filter('xmlrpc_enabled', '__return_false');
    add_filter('wp_is_application_passwords_available', '__return_false');
    add_filter('rest_authentication_errors', 'spacefast_content_disable_rest_api', 1);
    add_filter('pre_option_blogname', 'spacefast_content_managed_site_title');
    add_filter('rest_user_query', 'spacefast_content_scope_rest_user_query', 10, 2);
    add_filter('site_url', 'spacefast_content_request_url', 1, 4);
    add_filter('home_url', 'spacefast_content_request_url', 1, 4);
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

function spacefast_content_scope_rest_user_query(array $args, mixed $request): array
{
    // Scope core's user queries to this request's Space, the same membership
    // meta spacefast_content_users_list() filters on, so /wp/v2/users is the
    // Space's directory and not the box's. The scope depends only on the Space,
    // never on the caller: both REST doors reach this filter, and the WP API
    // door (spacefast_content_disable_rest_api) admits on a principal role
    // while setting no SPACEFAST_CONTENT_ADMIN_USER_ID. Keying on that global
    // would leave the API door unscoped and enumerate every Space's users on a
    // shared box, so the guard is the Space alone.
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
        if ($postType === '' && $page === 'edit.php' && function_exists('wp_safe_redirect') && function_exists('admin_url')) {
            wp_safe_redirect(admin_url('edit.php'));
            exit;
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
    if ($page === 'admin.php') {
        wp_die('Spacefast manages this WordPress screen.', 'Unavailable', ['response' => 403]);
    }
}

function spacefast_content_admin_page_allowed(string $page): bool
{
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
    if (
        spacefast_content_space_id() === ''
        || (!$scopedRevision && spacefast_content_collection_for_post_type($postType) === null)
        || !function_exists('update_post_meta')
    ) {
        return;
    }
    update_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id());
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
    return $caps;
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
