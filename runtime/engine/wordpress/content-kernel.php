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

const SPACEFAST_CONTENT_QUERY_FORMAT = 'spacefast.content.query';
const SPACEFAST_CONTENT_API_VERSION = 1;
const SPACEFAST_CONTENT_SITE_TITLE_OPTION = 'spacefast_space_title';
const SPACEFAST_CONTENT_EXTERNAL_ID_META = '_spacefast_external_id';
const SPACEFAST_CONTENT_MARKDOWN_META = '_spacefast_markdown_original';
const SPACEFAST_CONTENT_SPACE_META = '_spacefast_space_id';
const SPACEFAST_CONTENT_CAPS = [
    'batch_queries' => 25,
    'collections' => 50,
    'fields' => 100,
    'filters' => 24,
    'page_size' => 100,
];

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
    add_filter('determine_current_user', 'spacefast_content_admin_current_user', 1);
    if (function_exists('_stattic_runtime_bootstrap_config')) {
        add_action('plugins_loaded', '_stattic_runtime_bootstrap_config', 0);
    }
    add_action('plugins_loaded', 'spacefast_content_admin_establish_user', 1);
    add_action('init', 'spacefast_content_register_collections', 5);
    add_action('acf/init', 'spacefast_content_register_scf_field_groups', 5);
    add_action('add_attachment', 'spacefast_content_scope_attachment');
    add_action('save_post', 'spacefast_content_scope_post', 1, 2);
    add_action('save_post', 'spacefast_content_save_fields', 10, 2);
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
    add_filter('payloadwp_run_hook', 'spacefast_content_run_payload_hook', 10, 3);
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

/**
 * Where one Space's compiled content releases live, beside every other
 * per-Space tree (versions/, routes/, content-media/).
 *
 * A wp.cloud site hosts many Spaces. This tree used to hang off the box-wide
 * private root, which made the compiled release and its active-release pointer
 * box state: one Space's schema.compile replaced the schema every Space on the
 * box read. The path is the fix — it is also the only reason a compiled release
 * needs no tenant check at read time.
 *
 * `wordpress-content-loader.php` derives the same path independently, from the
 * install root and the request's Space, because it runs before the kernel is
 * loaded. Keep the two spellings in step.
 */
function spacefast_content_release_root(string $privateRoot, string $spaceId): string
{
    return rtrim($privateRoot, '/') . '/spaces/' . $spaceId . '/content';
}

function spacefast_content_option_name(string $base): string
{
    return $base . '_' . hash('sha256', spacefast_content_require_space_id());
}

function spacefast_content_builtin_post_type(string $name): string
{
    return spacefast_content_post_type('builtin:' . $name);
}

function spacefast_content_taxonomy(string $name): string
{
    return 'sf_' . substr(hash('sha256', spacefast_content_require_space_id()), 0, 16)
        . '_' . substr(hash('sha256', 'taxonomy:' . $name), 0, 8);
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

function spacefast_content_admin_current_user(mixed $currentUser): mixed
{
    if (is_int($currentUser) && $currentUser > 0) {
        return $currentUser;
    }
    $sessionUserId = $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null;
    if (is_int($sessionUserId) && $sessionUserId > 0) {
        return $sessionUserId;
    }
    $identity = $GLOBALS['SPACEFAST_CONTENT_ADMIN_IDENTITY'] ?? null;
    if (!is_array($identity) || !function_exists('get_user_by') || !function_exists('wp_insert_user')) {
        return $currentUser;
    }
    $subject = trim((string) ($identity['subject'] ?? ''));
    if (preg_match('/^content_[a-f0-9]{64}$/', $subject) !== 1) {
        return $currentUser;
    }
    $login = 'spacefast_' . substr(hash('sha256', $subject), 0, 24);
    $displayName = 'Spacefast editor';
    $email = $login . '@spacefast.invalid';
    $user = get_user_by('login', $login);
    if (!is_object($user)) {
        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => bin2hex(random_bytes(32)),
            'user_email' => $email,
            'display_name' => $displayName,
            'role' => 'editor',
        ]);
        if (function_exists('is_wp_error') && is_wp_error($created)) {
            $code = method_exists($created, 'get_error_code')
                ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $created->get_error_code())
                : '';
            error_log('spacefast content admin user unavailable code=' . ($code === '' ? 'unknown' : $code));
            return $currentUser;
        }
        $user = get_user_by('id', (int) $created);
    }
    if (!is_object($user) || (int) ($user->ID ?? 0) < 1) {
        return $currentUser;
    }
    if (method_exists($user, 'set_role')) {
        $user->set_role('editor');
    }
    if (function_exists('wp_update_user') && ($user->display_name ?? '') !== $displayName) {
        wp_update_user([
            'ID' => (int) $user->ID,
            'display_name' => $displayName,
            'user_email' => $email,
        ]);
    }
    return (int) $user->ID;
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

function spacefast_content_admin_establish_user(): int
{
    $userId = spacefast_content_admin_current_user(0);
    if (!is_int($userId) || $userId < 1 || !function_exists('wp_set_current_user')) {
        return 0;
    }
    $user = wp_set_current_user($userId);
    return is_object($user) && (int) ($user->ID ?? 0) === $userId ? $userId : 0;
}

function spacefast_content_admin_auth_cookies(int $userId, int $ttlSeconds): ?array
{
    if (
        $userId < 1
        || $ttlSeconds < 1
        || !defined('SECURE_AUTH_COOKIE')
        || !defined('LOGGED_IN_COOKIE')
        || !class_exists('WP_Session_Tokens')
        || !function_exists('wp_generate_auth_cookie')
    ) {
        return null;
    }
    $expiration = time() + $ttlSeconds;
    $manager = WP_Session_Tokens::get_instance($userId);
    $token = is_object($manager) && method_exists($manager, 'create')
        ? $manager->create($expiration)
        : '';
    if (!is_string($token) || $token === '') {
        return null;
    }
    $secure = wp_generate_auth_cookie($userId, $expiration, 'secure_auth', $token);
    $loggedIn = wp_generate_auth_cookie($userId, $expiration, 'logged_in', $token);
    if (!is_string($secure) || $secure === '' || !is_string($loggedIn) || $loggedIn === '') {
        return null;
    }
    return [
        ['name' => SECURE_AUTH_COOKIE, 'value' => $secure],
        ['name' => LOGGED_IN_COOKIE, 'value' => $loggedIn],
    ];
}

function spacefast_content_block_wordpress_login(): void
{
    if (function_exists('wp_die')) {
        wp_die('WordPress sign-in is managed by Spacefast.', 'Not found', ['response' => 404]);
    }
    http_response_code(404);
    exit;
}

function spacefast_content_disable_rest_api(mixed $result): mixed
{
    if (
        $result !== null
        || !class_exists('WP_Error')
        || (
            spacefast_content_space_id() !== ''
            && (int) ($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? 0) > 0
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
    $userId = $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null;
    if (spacefast_content_space_id() !== '' && is_int($userId) && $userId > 0) {
        $args['include'] = [$userId];
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
        $posts = spacefast_content_compiled_collection('posts');
        wp_safe_redirect(admin_url('edit.php?post_type=' . ($posts['post_type'] ?? spacefast_content_builtin_post_type('posts'))));
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
            wp_safe_redirect(admin_url('edit.php?post_type=' . spacefast_content_builtin_post_type('posts')));
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
    if (in_array($page, ['edit-tags.php', 'term.php'], true)) {
        $taxonomy = (string) ($_GET['taxonomy'] ?? '');
        if (!in_array($taxonomy, [spacefast_content_taxonomy('categories'), spacefast_content_taxonomy('tags')], true)) {
            wp_die('Spacefast manages this WordPress taxonomy.', 'Unavailable', ['response' => 403]);
        }
    }
    if ($page === 'admin.php') {
        $screen = (string) ($_GET['page'] ?? '');
        $allowed = $screen === 'payloadwp';
        foreach (spacefast_content_compiled_schema()['globals'] as $global) {
            if (is_array($global) && $screen === 'spacefast-global-' . ($global['slug'] ?? '')) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            wp_die('Spacefast manages this WordPress screen.', 'Unavailable', ['response' => 403]);
        }
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
    if (function_exists('remove_submenu_page')) {
        foreach (spacefast_content_compiled_schema()['globals'] as $global) {
            if (is_array($global) && is_string($global['slug'] ?? null)) {
                remove_submenu_page('payloadwp', 'payloadwp-global-' . $global['slug']);
            }
        }
    }
}

function spacefast_content_admin_assets(): void
{
    $postType = (string) ($_GET['post_type'] ?? ($GLOBALS['typenow'] ?? ''));
    $collection = spacefast_content_collection_for_post_type($postType);
    if ($collection === null) {
        return;
    }
    if (!empty($collection['compiled'])) {
        if (function_exists('wp_dequeue_script')) {
            wp_dequeue_script('payloadwp-admin');
        }
        if (function_exists('wp_dequeue_style')) {
            wp_dequeue_style('payloadwp-admin');
        }
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

function spacefast_content_compiled_schema(): array
{
    static $cachedRoot = null;
    static $cachedSchema = null;
    $root = $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') {
        return ['schema_version' => 3, 'collections' => [], 'globals' => [], 'hooks' => []];
    }
    if ($cachedRoot === $root && is_array($cachedSchema)) {
        return $cachedSchema;
    }
    $decoded = json_decode((string) @file_get_contents($root . '/schema.json'), true);
    if (
        !is_array($decoded)
        || ($decoded['schema_version'] ?? null) !== 3
        || !is_array($decoded['collections'] ?? null)
        || !is_array($decoded['globals'] ?? null)
        || !is_array($decoded['hooks'] ?? null)
    ) {
        return ['schema_version' => 3, 'collections' => [], 'globals' => [], 'hooks' => []];
    }
    $cachedRoot = $root;
    $cachedSchema = $decoded;
    return $decoded;
}

function spacefast_content_run_payload_hook(mixed $result, mixed $id, mixed $arguments): mixed
{
    if ($result !== null || !is_string($id) || !is_array($arguments)) {
        return $result;
    }
    $root = $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] ?? null;
    if (
        !is_string($root)
        || !defined('PAYLOADWP_RUNNER')
        || !is_string(PAYLOADWP_RUNNER)
        || !function_exists('_stattic_runtime_run_subprocess')
    ) {
        return null;
    }
    // The compiled release is immutable for the request, so a request that runs
    // several hooks reads and decodes hooks.json once, not once per hook.
    static $cachedRoot = null;
    static $cachedHooks = [];
    if ($cachedRoot !== $root) {
        $cachedRoot = $root;
        $manifest = json_decode((string) @file_get_contents($root . '/hooks.json'), true);
        $cachedHooks = is_array($manifest) && is_array($manifest['hooks'] ?? null)
            ? $manifest['hooks']
            : [];
    }
    $hooks = $cachedHooks;
    $hook = null;
    foreach ($hooks as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
            $hook = $candidate;
            break;
        }
    }
    if (!is_array($hook) || ($hook['target'] ?? null) !== 'quick_js') {
        return null;
    }
    if ((is_array($hook['capabilities'] ?? null) ? $hook['capabilities'] : []) !== []) {
        throw new RuntimeException('This content hook requests a capability that Spacefast does not grant.');
    }
    $stdin = json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($stdin) > 1048576) {
        throw new RuntimeException('The content hook input exceeds 1 MiB.');
    }
    $run = _stattic_runtime_run_subprocess(
        [PAYLOADWP_RUNNER, 'run-hook', '--manifest', $root . '/hooks.json', '--id', $id],
        null,
        $stdin,
        $root,
        5000,
        2097152,
        65536
    );
    if (!$run['spawned'] || $run['timedOut'] || $run['exitCode'] !== 0) {
        error_log('Spacefast Payload hook failed: ' . trim($run['stderr']));
        throw new RuntimeException('The content hook could not be executed.');
    }
    return json_decode($run['stdout'], true, flags: JSON_THROW_ON_ERROR);
}

function spacefast_content_compiled_collection(string $name): ?array
{
    // A deterministic projection of the immutable compiled release, resolved on
    // hot admin hooks (map_meta_cap runs it per capability check). Cache per
    // release root so the field normalization runs once per collection.
    static $cachedRoot = null;
    static $cache = [];
    $root = $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] ?? null;
    $cacheRoot = is_string($root) ? $root : '';
    if ($cachedRoot !== $cacheRoot) {
        $cachedRoot = $cacheRoot;
        $cache = [];
    }
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    foreach (spacefast_content_compiled_schema()['collections'] as $collection) {
        if (!is_array($collection) || ($collection['slug'] ?? null) !== $name) {
            continue;
        }
        $storage = (string) ($collection['wordpress_storage'] ?? 'post');
        if (!in_array($storage, ['post', 'attachment'], true)) {
            return $cache[$name] = null;
        }
        $fields = [];
        foreach (spacefast_content_normalize_compiled_fields($collection['fields'] ?? []) as $normalized) {
            $fields[$normalized['name']] = $normalized['definition'];
        }
        return $cache[$name] = [
            'name' => $name,
            'post_type' => (string) ($collection['wordpress_post_type'] ?? ''),
            // Payload's empty read policy means authenticated-only, while an
            // explicit policy must execute before anonymous access is known.
            // The public batch gateway cannot safely infer either case.
            'public' => false,
            'fields' => $fields,
            'builtin' => false,
            'media' => $storage === 'attachment',
            'compiled' => true,
            'scoped' => false,
            'payload' => $collection,
        ];
    }
    return $cache[$name] = null;
}

/** @return list<array{name:string,definition:array<string,mixed>}> */
function spacefast_content_normalize_compiled_fields(mixed $fields): array
{
    if (!is_array($fields)) {
        return [];
    }
    $normalizedFields = [];
    foreach ($fields as $field) {
        $normalized = spacefast_content_normalize_compiled_field($field);
        if ($normalized !== null) {
            $normalizedFields[] = $normalized;
            continue;
        }
        if (is_array($field)) {
            array_push(
                $normalizedFields,
                ...spacefast_content_normalize_compiled_fields($field['children'] ?? [])
            );
        }
    }
    return $normalizedFields;
}

function spacefast_content_normalize_compiled_field(mixed $field): ?array
{
    if (!is_array($field) || !spacefast_content_payload_field_identifier($field['name'] ?? null)) {
        return null;
    }
    $name = (string) $field['name'];
    $type = (string) ($field['field_type'] ?? 'text');
    $coreProperties = ['title' => 'post_title', 'body' => 'post_content', 'excerpt' => 'post_excerpt'];
    if (isset($coreProperties[$name])) {
        return [
            'name' => $name,
            'definition' => [
                'type' => $name === 'body' ? 'richText' : 'text',
                'storageProperty' => $coreProperties[$name],
                'required' => !empty($field['required']),
            ],
        ];
    }
    $normalizedType = !empty($field['localized']) ? 'json' : match ($type) {
        'number' => 'number',
        'checkbox' => 'boolean',
        'date' => 'date',
        'select', 'radio' => 'select',
        'relationship' => 'relation',
        'upload' => 'media',
        'richText' => 'richText',
        'textarea' => 'markdown',
        'array', 'blocks', 'group', 'json', 'point', 'tabs' => 'json',
        default => 'text',
    };
    $options = [];
    foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
        $value = is_array($option) ? ($option['value'] ?? null) : $option;
        if (is_string($value) || is_int($value) || is_float($value)) {
            $options[] = (string) $value;
        }
    }
    $definition = [
        'type' => $normalizedType,
        'label' => is_string($field['label'] ?? null) ? $field['label'] : $name,
        'required' => !empty($field['required']),
        'multiple' => !empty($field['has_many']),
        'storageName' => 'payloadwp_' . $name,
        'payload' => $field,
    ];
    if ($options !== []) {
        $definition['options'] = $options;
    }
    if (is_string($field['relation_to'] ?? null)) {
        $definition['collection'] = $field['relation_to'];
    } elseif (is_array($field['relation_to'] ?? null)) {
        $definition['collections'] = array_values(array_filter(
            $field['relation_to'],
            static fn (mixed $collection): bool => is_string($collection)
        ));
    }
    return ['name' => $name, 'definition' => $definition];
}

function spacefast_content_payload_field_identifier(mixed $value): bool
{
    return is_string($value)
        && strlen($value) <= 128
        && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $value) === 1;
}

function spacefast_content_register_collections(): void
{
    if (!function_exists('register_post_type') || spacefast_content_space_id() === '') {
        return;
    }
    $posts = spacefast_content_compiled_collection('posts');
    $pages = spacefast_content_compiled_collection('pages');
    // Only read inside the `=== null` branches below, where the compiled
    // lookup returned nothing — so the post type is always the builtin one.
    $postsType = spacefast_content_builtin_post_type('posts');
    $pagesType = spacefast_content_builtin_post_type('pages');
    if ($posts === null) {
        register_post_type($postsType, spacefast_content_post_type_args('Posts', 'Post', [
            'title', 'editor', 'excerpt', 'thumbnail', 'revisions',
        ]));
    }
    if ($pages === null) {
        register_post_type($pagesType, spacefast_content_post_type_args('Pages', 'Page', [
            'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions',
        ], true));
    }
    if ($posts === null && function_exists('register_taxonomy')) {
        register_taxonomy(spacefast_content_taxonomy('categories'), [$postsType], [
            'labels' => ['name' => 'Categories', 'singular_name' => 'Category'],
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => true,
            'rewrite' => false,
        ]);
        register_taxonomy(spacefast_content_taxonomy('tags'), [$postsType], [
            'labels' => ['name' => 'Tags', 'singular_name' => 'Tag'],
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => false,
            'rewrite' => false,
        ]);
    }
    // Compiled collections register their own post types from the release's
    // payloadwp.php, which the loader requires before this runs.
}

function spacefast_content_post_type_args(
    string $label,
    string $singularLabel,
    array $supports,
    bool $hierarchical = false
): array {
    return [
        'labels' => ['name' => $label, 'singular_name' => $singularLabel],
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'hierarchical' => $hierarchical,
        'rewrite' => false,
        'supports' => $supports,
        'map_meta_cap' => true,
    ];
}

function spacefast_content_post_type(string $resourceId): string
{
    return 'sf_' . substr(hash('sha256', spacefast_content_require_space_id() . '|' . $resourceId), 0, 16);
}

function spacefast_content_scf_available(): bool
{
    return function_exists('acf_add_local_field_group');
}

function spacefast_content_scf_key(string $prefix, string $resourceId, string $fieldName = ''): string
{
    return $prefix . '_' . substr(hash(
        'sha256',
        spacefast_content_require_space_id() . '|' . $resourceId . '|' . $fieldName
    ), 0, 24);
}

function spacefast_content_register_scf_field_groups(): void
{
    if (!spacefast_content_scf_available() || spacefast_content_space_id() === '') {
        return;
    }
    foreach (spacefast_content_compiled_schema()['collections'] as $collection) {
        if (!is_array($collection) || ($collection['wordpress_storage'] ?? 'post') !== 'post') {
            continue;
        }
        $resourceId = 'payloadwp:' . (string) ($collection['slug'] ?? 'collection');
        $fields = [];
        foreach (spacefast_content_normalize_compiled_fields($collection['fields'] ?? []) as $normalized) {
            if (isset($normalized['definition']['storageProperty'])) {
                continue;
            }
            $definition = $normalized['definition'];
            $definition['name'] = $definition['storageName'];
            $fields[] = spacefast_content_scf_field(
                $resourceId,
                $normalized['name'],
                $definition
            );
        }
        if ($fields === []) {
            continue;
        }
        acf_add_local_field_group([
            'key' => spacefast_content_scf_key('group', $resourceId),
            'title' => (string) ($collection['singular_label'] ?? $collection['slug'] ?? 'Content') . ' fields',
            'fields' => $fields,
            'location' => [[[
                'param' => 'post_type',
                'operator' => '==',
                'value' => (string) ($collection['wordpress_post_type'] ?? ''),
            ]]],
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'show_in_rest' => 0,
            'allow_ai_access' => 0,
            'active' => true,
        ]);
    }
    if (!function_exists('acf_add_options_sub_page')) {
        return;
    }
    foreach (spacefast_content_compiled_schema()['globals'] as $global) {
        if (!is_array($global) || !spacefast_content_identifier($global['slug'] ?? null)) {
            continue;
        }
        $slug = (string) $global['slug'];
        $optionName = spacefast_content_compiled_global_option_name($global);
        $menuSlug = 'spacefast-global-' . $slug;
        acf_add_options_sub_page([
            'page_title' => (string) ($global['label'] ?? $slug),
            'menu_title' => (string) ($global['label'] ?? $slug),
            'menu_slug' => $menuSlug,
            'parent_slug' => 'payloadwp',
            'capability' => 'edit_posts',
            'redirect' => false,
        ]);
        $fields = [];
        foreach (spacefast_content_normalize_compiled_fields($global['fields'] ?? []) as $normalized) {
            if (isset($normalized['definition']['storageProperty'])) {
                continue;
            }
            $definition = $normalized['definition'];
            $definition['name'] = 'spacefast_global_' . substr(
                hash('sha256', $slug . '|' . $normalized['name']),
                0,
                24
            );
            $definition['globalOption'] = $optionName;
            $definition['globalField'] = $normalized['name'];
            $fields[] = spacefast_content_scf_field(
                'payloadwp-global:' . $slug,
                $normalized['name'],
                $definition
            );
        }
        if ($fields !== []) {
            acf_add_local_field_group([
                'key' => spacefast_content_scf_key('group', 'payloadwp-global:' . $slug),
                'title' => (string) ($global['label'] ?? $slug),
                'fields' => $fields,
                'location' => [[[
                    'param' => 'options_page',
                    'operator' => '==',
                    'value' => $menuSlug,
                ]]],
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'active' => true,
            ]);
        }
    }
}

function spacefast_content_compiled_global_option_name(array $global): string
{
    $slug = is_string($global['slug'] ?? null) ? $global['slug'] : 'global';
    return spacefast_content_option_name(
        (string) ($global['wordpress_option_name'] ?? 'payloadwp_global_' . $slug)
    );
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
                $relatedPostTypes[] = spacefast_content_resolve_collection($relatedName)['post_type'];
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
    try {
        spacefast_content_validate_document_references(
            ['fields' => [(string) ($field['name'] ?? '') => $definition]],
            [(string) ($field['name'] ?? '') => $normalized]
        );
    } catch (Spacefast_Content_Error $error) {
        return $error->getMessage();
    }
    return true;
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

function spacefast_content_save_fields(int $postId, object $post): void
{
    if (spacefast_content_scf_available()) {
        return;
    }
    if (
        !function_exists('wp_verify_nonce')
        || !wp_verify_nonce((string) ($_POST['spacefast_content_nonce'] ?? ''), 'spacefast_content_fields')
        || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        || (function_exists('current_user_can') && !current_user_can('edit_post', $postId))
    ) {
        return;
    }
    $collection = spacefast_content_collection_for_post_type((string) ($post->post_type ?? ''));
    if ($collection === null || !function_exists('update_post_meta')) {
        return;
    }
    $submitted = is_array($_POST['spacefast_fields'] ?? null) ? $_POST['spacefast_fields'] : [];
    $rawFields = [];
    foreach ($collection['fields'] as $name => $field) {
        $raw = ($field['type'] ?? '') === 'boolean' ? isset($submitted[$name]) : ($submitted[$name] ?? '');
        if (
            !empty($field['multiple'])
            && in_array(($field['type'] ?? ''), ['media', 'relation'], true)
            && is_string($raw)
        ) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        $rawFields[$name] = $raw;
    }
    try {
        $validated = spacefast_content_validate_document_fields($collection, $rawFields);
        spacefast_content_validate_document_references($collection, $validated);
    } catch (Spacefast_Content_Error $error) {
        if (function_exists('wp_die')) {
            wp_die($error->getMessage(), 'Invalid content', ['response' => $error->status]);
        }
        return;
    }
    foreach ($validated as $name => $value) {
        update_post_meta($postId, $name, $value);
    }
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
    // Resolved per capability check on map_meta_cap; without a memo this scans
    // every compiled collection twice (once here by post type, again inside
    // spacefast_content_compiled_collection by slug) on each hook call.
    static $cachedRoot = null;
    static $cache = [];
    $root = $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] ?? null;
    $cacheRoot = is_string($root) ? $root : '';
    if ($cachedRoot !== $cacheRoot) {
        $cachedRoot = $cacheRoot;
        $cache = [];
    }
    if (array_key_exists($postType, $cache)) {
        return $cache[$postType];
    }
    foreach (spacefast_content_compiled_schema()['collections'] as $compiled) {
        if (
            is_array($compiled)
            && ($compiled['wordpress_post_type'] ?? null) === $postType
            && is_string($compiled['slug'] ?? null)
        ) {
            return $cache[$postType] = spacefast_content_compiled_collection($compiled['slug']);
        }
    }
    foreach (['posts', 'pages'] as $name) {
        if (spacefast_content_builtin_post_type($name) === $postType) {
            return $cache[$postType] = spacefast_content_resolve_collection($name);
        }
    }
    return $cache[$postType] = null;
}

function spacefast_content_handle_request(array $request, bool $managed): array
{
    if (($request['format'] ?? null) === SPACEFAST_CONTENT_QUERY_FORMAT) {
        return spacefast_content_execute_batch($request, $managed);
    }
    return match ((string) ($request['operation'] ?? '')) {
        'schema.compile' => spacefast_content_compile_schema($request['bundle'] ?? null, $managed),
        'schema.activate' => spacefast_content_activate_release($request['revision'] ?? null, $managed),
        'document.render' => spacefast_content_render_document($request, $managed),
        'document.upsert' => spacefast_content_upsert_document($request['document'] ?? null, $managed),
        'markdown.sync' => spacefast_content_sync_markdown($request, $managed),
        default => throw new Spacefast_Content_Error(400, 'content_operation_invalid', 'The content operation is not supported.'),
    };
}

function spacefast_content_render_document(array $request, bool $managed): array
{
    if (
        ($request['format'] ?? null) !== 'spacefast.content.render'
        || ($request['version'] ?? null) !== SPACEFAST_CONTENT_API_VERSION
        || !spacefast_content_identifier($request['collection'] ?? null)
        || !spacefast_content_payload_field_identifier($request['field'] ?? 'content')
        || !in_array(($request['output'] ?? 'html'), ['blocks', 'html'], true)
        || (isset($request['id']) === isset($request['slug']))
    ) {
        throw new Spacefast_Content_Error(400, 'content_render_invalid', 'The content render request is invalid.');
    }
    $collection = spacefast_content_resolve_collection($request['collection'], $managed);
    $where = isset($request['id'])
        ? [['field' => 'id', 'operator' => 'eq', 'value' => $request['id']]]
        : [['field' => 'slug', 'operator' => 'eq', 'value' => $request['slug']]];
    $args = spacefast_content_compile_query_args([
        'where' => $where,
        'limit' => 1,
        'status' => $managed ? 'any' : 'publish',
    ], $collection, $managed);
    $query = new WP_Query($args);
    $post = $query->posts[0] ?? null;
    if (!is_object($post)) {
        throw new Spacefast_Content_Error(404, 'content_document_not_found', 'The content document was not found.');
    }
    $field = (string) ($request['field'] ?? 'content');
    if (!empty($collection['builtin'])) {
        $content = match ($field) {
            'content' => (string) ($post->post_content ?? ''),
            'excerpt' => (string) ($post->post_excerpt ?? ''),
            default => throw new Spacefast_Content_Error(400, 'content_render_field_invalid', 'The content field cannot be rendered.'),
        };
    } else {
        $definition = $collection['fields'][$field] ?? null;
        if (!is_array($definition) || !in_array(($definition['type'] ?? null), ['markdown', 'richText', 'text'], true)) {
            throw new Spacefast_Content_Error(400, 'content_render_field_invalid', 'The content field cannot be rendered.');
        }
        $property = $definition['storageProperty'] ?? null;
        $content = is_string($property)
            ? (string) ($post->$property ?? '')
            : (string) get_post_meta(
                (int) $post->ID,
                spacefast_content_field_storage_name($field, $definition),
                true
            );
    }
    $output = (string) ($request['output'] ?? 'html');
    if ($output === 'html') {
        $previousPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        if (function_exists('setup_postdata')) {
            setup_postdata($post);
        }
        try {
            $content = function_exists('apply_filters')
                ? (string) apply_filters('the_content', $content)
                : (function_exists('do_blocks') ? (string) do_blocks($content) : $content);
        } finally {
            if (function_exists('wp_reset_postdata')) {
                wp_reset_postdata();
            } else {
                $GLOBALS['post'] = $previousPost;
            }
        }
    }
    return ['id' => (int) $post->ID, 'content' => $content, 'output' => $output];
}

function spacefast_content_compile_schema(mixed $bundle, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content compilation requires Spacefast authorization.');
    }
    $bundle = spacefast_content_validate_source_bundle($bundle);
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $binary = is_string($releaseRoot) ? $releaseRoot . '/bin/stattic-runtime' : '';
    if (
        !is_string($privateRoot)
        || $privateRoot === ''
        || $binary === ''
        || !is_file($binary)
        || !is_executable($binary)
        || !function_exists('_stattic_runtime_run_subprocess')
    ) {
        throw new Spacefast_Content_Error(503, 'content_compiler_unavailable', 'The content compiler is not ready.');
    }

    $contentRoot = spacefast_content_release_root($privateRoot, spacefast_content_require_space_id());
    $releasesRoot = $contentRoot . '/releases';
    if ((!is_dir($releasesRoot) && !mkdir($releasesRoot, 0750, true)) || !is_dir($releasesRoot)) {
        throw new Spacefast_Content_Error(503, 'content_compiler_storage_unavailable', 'Content compiler storage is unavailable.');
    }
    $lock = fopen($contentRoot . '/compile.lock', 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new Spacefast_Content_Error(409, 'content_compile_busy', 'Another content compile is in progress.');
    }

    $stageRoot = $contentRoot . '/compile-' . bin2hex(random_bytes(12));
    $sourceRoot = $stageRoot . '/source';
    $outputRoot = $stageRoot . '/output';
    try {
        foreach ($bundle['files'] as $path => $source) {
            $target = $sourceRoot . '/' . $path;
            if ((!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true)) || file_put_contents($target, $source, LOCK_EX) === false) {
                throw new Spacefast_Content_Error(500, 'content_compile_stage_failed', 'The content source could not be staged.');
            }
        }
        $command = [
            $binary,
            'content-compile',
            '--config',
            $sourceRoot . '/' . $bundle['entry'],
            '--output',
            $outputRoot,
        ];
        if ($bundle['phpOnly']) {
            $command[] = '--php-only';
        }
        $run = _stattic_runtime_run_subprocess(
            $command,
            null,
            null,
            $sourceRoot,
            60000,
            262144,
            262144
        );
        if (!$run['spawned'] || $run['timedOut'] || $run['exitCode'] !== 0) {
            error_log('Spacefast content compiler failed: ' . trim($run['stderr']));
            throw new Spacefast_Content_Error(422, 'content_compile_failed', 'The Payload content config could not be compiled.');
        }
        $artifact = spacefast_content_validate_compiler_artifact($outputRoot);
        $revisionHash = hash_init('sha256');
        foreach (['schema.json', 'hooks.json', 'payloadwp.php', 'payloadwp-admin.js', 'payloadwp-admin.css'] as $file) {
            hash_update($revisionHash, $file . "\0" . (string) file_get_contents($outputRoot . '/' . $file) . "\0");
        }
        $revision = hash_final($revisionHash);
        $release = $releasesRoot . '/' . $revision;
        if (!is_dir($release) && !rename($outputRoot, $release)) {
            throw new Spacefast_Content_Error(500, 'content_compile_publish_failed', 'The compiled content revision could not be published.');
        }
        // Compiling never changes what the Space serves. The control plane binds
        // this revision to the version being published and activates it only when
        // that version goes live, so a publish that never activates — and a
        // rollback to an older version — cannot move the live content model.
        return [
            'revision' => $revision,
            'schemaVersion' => $artifact['schema_version'],
            'collections' => count($artifact['collections']),
            'fields' => spacefast_content_ir_field_count($artifact),
            'hooks' => count($artifact['hooks']),
            'quickJsHooks' => count(array_filter(
                $artifact['hooks'],
                static fn (mixed $hook): bool => is_array($hook) && ($hook['target'] ?? null) === 'quick_js'
            )),
        ];
    } finally {
        _stattic_private_tree_remove($stageRoot);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Point the Space at an already-compiled content release, or at none.
 *
 * Activation is deliberately separate from compilation: the live content model
 * is whatever the live version bound, so promoting or rolling back a version
 * replays this call with that version's revision and never recompiles.
 */
function spacefast_content_activate_release(mixed $revision, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content activation requires Spacefast authorization.');
    }
    if ($revision !== null && (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/', $revision) !== 1)) {
        throw new Spacefast_Content_Error(400, 'content_release_invalid', 'The content release revision is invalid.');
    }
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if (!is_string($privateRoot) || $privateRoot === '') {
        throw new Spacefast_Content_Error(503, 'content_compiler_storage_unavailable', 'Content compiler storage is unavailable.');
    }
    $contentRoot = spacefast_content_release_root($privateRoot, spacefast_content_require_space_id());
    $pointer = $contentRoot . '/active-release';
    // A version that shipped no content config declares "no managed content".
    // Clearing the pointer is what makes a rollback to such a version faithful.
    if ($revision === null) {
        if (is_file($pointer) && !_stattic_private_tree_remove($pointer)) {
            throw new Spacefast_Content_Error(500, 'content_release_pointer_failed', 'The content release pointer could not be cleared.');
        }
        return ['revision' => null];
    }
    // Refuse to point at a release this Space has not compiled. Serving would
    // otherwise degrade silently to an empty schema in the loader.
    if (!is_file($contentRoot . '/releases/' . $revision . '/schema.json')) {
        throw new Spacefast_Content_Error(409, 'content_release_not_found', 'The content release is not present on this Space.');
    }
    if (!_stattic_private_tree_write_pointer($pointer, $revision)) {
        throw new Spacefast_Content_Error(500, 'content_release_pointer_failed', 'The content release could not be activated.');
    }
    return ['revision' => $revision];
}

function spacefast_content_validate_source_bundle(mixed $bundle): array
{
    if (
        !is_array($bundle)
        || ($bundle['format'] ?? null) !== 'spacefast.content.source'
        || ($bundle['version'] ?? null) !== SPACEFAST_CONTENT_API_VERSION
        || !is_string($bundle['entry'] ?? null)
        || !is_array($bundle['files'] ?? null)
        || count($bundle['files']) < 1
        || count($bundle['files']) > 64
    ) {
        throw new Spacefast_Content_Error(400, 'content_source_invalid', 'The content source bundle is invalid.');
    }
    $bytes = 0;
    foreach ($bundle['files'] as $path => $source) {
        if (
            !is_string($path)
            || preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.(?:[cm]?[jt]sx?)$#', $path) !== 1
            || !is_string($source)
        ) {
            throw new Spacefast_Content_Error(400, 'content_source_invalid', 'The content source bundle contains an invalid module.');
        }
        $bytes += strlen($path) + strlen($source);
    }
    if ($bytes > 2097152 || !array_key_exists($bundle['entry'], $bundle['files'])) {
        throw new Spacefast_Content_Error(400, 'content_source_invalid', 'The content source bundle exceeds its limits or has no entry module.');
    }
    return [
        'format' => 'spacefast.content.source',
        'version' => SPACEFAST_CONTENT_API_VERSION,
        'entry' => $bundle['entry'],
        'files' => $bundle['files'],
        'phpOnly' => ($bundle['phpOnly'] ?? false) === true,
    ];
}

function spacefast_content_validate_compiler_artifact(string $root): array
{
    foreach (['schema.json', 'hooks.json', 'payloadwp.php', 'payloadwp-admin.js', 'payloadwp-admin.css'] as $file) {
        if (!is_file($root . '/' . $file)) {
            throw new Spacefast_Content_Error(500, 'content_compile_artifact_invalid', 'The content compiler returned an incomplete artifact.');
        }
    }
    $schema = json_decode((string) file_get_contents($root . '/schema.json'), true);
    if (
        !is_array($schema)
        || ($schema['schema_version'] ?? null) !== 3
        || !is_array($schema['collections'] ?? null)
        || !is_array($schema['globals'] ?? null)
        || !is_array($schema['hooks'] ?? null)
        || count($schema['collections']) > SPACEFAST_CONTENT_CAPS['collections']
    ) {
        throw new Spacefast_Content_Error(500, 'content_compile_artifact_invalid', 'The content compiler returned an invalid schema.');
    }
    foreach ($schema['collections'] as $collection) {
        if (
            is_array($collection)
            && (
                ($collection['wordpress_storage'] ?? null) === 'user'
                || !empty($collection['auth']['enabled'])
            )
        ) {
            throw new Spacefast_Content_Error(
                422,
                'content_auth_collection_unsupported',
                'Payload auth collections must use Spacefast Auth.'
            );
        }
    }
    return $schema;
}

function spacefast_content_ir_field_count(array $schema): int
{
    $countFields = static function (array $fields) use (&$countFields): int {
        $count = 0;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $count += 1 + $countFields(is_array($field['children'] ?? null) ? $field['children'] : []);
            foreach (is_array($field['blocks'] ?? null) ? $field['blocks'] : [] as $block) {
                if (is_array($block)) {
                    $count += $countFields(is_array($block['fields'] ?? null) ? $block['fields'] : []);
                }
            }
        }
        return $count;
    };
    $count = 0;
    foreach (array_merge($schema['collections'], $schema['globals']) as $owner) {
        if (is_array($owner)) {
            $count += $countFields(is_array($owner['fields'] ?? null) ? $owner['fields'] : []);
        }
    }
    return $count;
}

function spacefast_content_identifier(mixed $value): bool
{
    return is_string($value)
        && strlen($value) <= 63
        && preg_match('/^[a-z][a-z0-9_-]*$/', $value) === 1;
}

function spacefast_content_execute_batch(array $request, bool $managed): array
{
    if (
        ($request['version'] ?? null) !== SPACEFAST_CONTENT_API_VERSION
        || !is_array($request['queries'] ?? null)
        || count($request['queries']) < 1
        || count($request['queries']) > SPACEFAST_CONTENT_CAPS['batch_queries']
    ) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'The content batch is invalid.');
    }
    $cacheKey = '';
    if (!$managed && function_exists('wp_cache_get')) {
        // Key on the active compiled release, so activating a different release
        // — including a rollback to an older one — cannot serve a stale batch.
        $releaseRoot = $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] ?? null;
        $revision = is_string($releaseRoot) && $releaseRoot !== '' ? basename($releaseRoot) : 'empty';
        $changed = function_exists('wp_cache_get_last_changed') ? wp_cache_get_last_changed('posts') : '';
        $cacheKey = hash(
            'sha256',
            spacefast_content_require_space_id() . '|' . json_encode($request) . '|' . $revision . '|' . $changed
        );
        $cached = wp_cache_get($cacheKey, 'spacefast_content');
        if (is_array($cached)) {
            return $cached;
        }
    }
    $results = [];
    foreach ($request['queries'] as $key => $query) {
        if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,62}$/', $key) !== 1) {
            throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content query key is invalid.');
        }
        $results[$key] = spacefast_content_execute_query($query, $managed);
    }
    $response = [
        'format' => SPACEFAST_CONTENT_QUERY_FORMAT,
        'version' => SPACEFAST_CONTENT_API_VERSION,
        'results' => $results,
    ];
    if ($cacheKey !== '' && function_exists('wp_cache_set')) {
        wp_cache_set($cacheKey, $response, 'spacefast_content', 300);
    }
    return $response;
}

function spacefast_content_execute_query(mixed $query, bool $managed): array
{
    if (!is_array($query) || !spacefast_content_identifier($query['collection'] ?? null)) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content query is invalid.');
    }
    $status = (string) ($query['status'] ?? 'publish');
    if (!$managed && $status !== 'publish') {
        throw new Spacefast_Content_Error(403, 'content_status_forbidden', 'Public content queries can only read published content.');
    }
    $collection = spacefast_content_resolve_collection($query['collection'], $managed);
    $args = spacefast_content_compile_query_args($query, $collection, $managed);
    $wpQuery = new WP_Query($args);
    $items = [];
    foreach ($wpQuery->posts as $post) {
        $items[] = spacefast_content_serialize_post($post, $collection, $query['select'] ?? null);
    }
    $page = (int) ($args['paged'] ?? 1);
    return [
        'items' => $items,
        'total' => (int) $wpQuery->found_posts,
        'nextCursor' => $page < (int) $wpQuery->max_num_pages ? spacefast_content_encode_cursor($page + 1) : null,
    ];
}

function spacefast_content_resolve_collection(string $name, bool $managed = true): array
{
    $collection = spacefast_content_compiled_collection($name);
    if ($collection === null) {
        if ($name === 'media') {
            $collection = [
                'name' => 'media',
                'post_type' => 'attachment',
                'public' => true,
                'fields' => [],
                'builtin' => true,
                'media' => true,
                'scoped' => true,
            ];
        } elseif ($name === 'posts' || $name === 'pages') {
            $collection = [
                'name' => $name,
                'post_type' => spacefast_content_builtin_post_type($name),
                'public' => true,
                'fields' => [],
                'builtin' => true,
                'scoped' => true,
            ];
        } else {
            // The compiled release is the only source of custom collections. A
            // name the live version's content config did not declare does not
            // exist.
            throw new Spacefast_Content_Error(404, 'content_collection_not_found', 'The content collection was not found.');
        }
    }
    // Resolution owns the public-read gate: an unmanaged caller may not reach a
    // non-public collection, and an absent one is indistinguishable from one it
    // may not see. Every read funnels through here, so the check lives here once
    // rather than being re-derived at each read leaf.
    if (!$managed && !$collection['public']) {
        throw new Spacefast_Content_Error(404, 'content_collection_not_found', 'The content collection was not found.');
    }
    return $collection;
}

function spacefast_content_compile_query_args(array $query, array $collection, bool $managed): array
{
    $limit = max(1, min(SPACEFAST_CONTENT_CAPS['page_size'], (int) ($query['limit'] ?? 20)));
    $status = (string) ($query['status'] ?? 'publish');
    if (!in_array($status, ['publish', 'draft', 'any'], true) || (!$managed && $status !== 'publish')) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'The content status is invalid.');
    }
    $wpStatus = !empty($collection['media']) && $status === 'publish' ? 'inherit' : $status;
    $args = [
        'post_type' => $collection['post_type'],
        'post_status' => $wpStatus,
        'posts_per_page' => $limit,
        'paged' => spacefast_content_decode_cursor($query['cursor'] ?? null),
        'no_found_rows' => false,
        'ignore_sticky_posts' => true,
        'orderby' => 'date',
        'order' => 'DESC',
    ];
    $where = $query['where'] ?? [];
    if (!is_array($where) || count($where) > SPACEFAST_CONTENT_CAPS['filters']) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'The content filters are invalid.');
    }
    $metaQuery = ['relation' => 'AND'];
    if ($collection['scoped'] === true) {
        $metaQuery[] = spacefast_content_space_meta_clause();
    }
    foreach ($where as $filter) {
        if (!is_array($filter) || !spacefast_content_payload_field_identifier($filter['field'] ?? null)) {
            throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content filter is invalid.');
        }
        $field = $filter['field'];
        $operator = (string) ($filter['operator'] ?? '');
        $value = $filter['value'] ?? null;
        if ($field === 'id' && in_array($operator, ['eq', 'in'], true)) {
            $values = $operator === 'in' && is_array($value) ? $value : [$value];
            $ids = array_map('spacefast_content_positive_id', $values);
            if ($ids === [] || count($ids) > 100 || in_array(null, $ids, true)) {
                throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content id filter is invalid.');
            }
            $args['post__in'] = $ids;
            continue;
        }
        if ($field === 'slug' && in_array($operator, ['eq', 'in'], true)) {
            $values = $operator === 'in' && is_array($value) ? $value : [$value];
            $slugsValid = $values !== [] && count($values) <= 100;
            foreach ($values as $slug) {
                if (!is_string($slug) || trim($slug) === '' || strlen($slug) > 200) {
                    $slugsValid = false;
                }
            }
            if (!$slugsValid) {
                throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content slug filter is invalid.');
            }
            $args['post_name__in'] = $values;
            continue;
        }
        if (!isset($collection['fields'][$field])) {
            throw new Spacefast_Content_Error(400, 'content_field_unknown', 'A content query references an unknown field.');
        }
        $metaQuery[] = spacefast_content_compile_meta_filter($field, $operator, $value, $collection['fields'][$field]);
    }
    $args['meta_query'] = $metaQuery;
    $orderBy = $query['orderBy'] ?? [];
    if (!is_array($orderBy) || count($orderBy) > 1) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'Content queries support one sort field.');
    }
    if (isset($orderBy[0])) {
        $order = $orderBy[0];
        if (!is_array($order) || !spacefast_content_payload_field_identifier($order['field'] ?? null)) {
            throw new Spacefast_Content_Error(400, 'content_query_invalid', 'The content sort is invalid.');
        }
        $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $coreOrder = ['id' => 'ID', 'slug' => 'name', 'created_at' => 'date', 'updated_at' => 'modified'];
        if (isset($coreOrder[$order['field']])) {
            $args['orderby'] = $coreOrder[$order['field']];
        } elseif (isset($collection['fields'][$order['field']])) {
            $args['meta_key'] = spacefast_content_field_storage_name(
                $order['field'],
                $collection['fields'][$order['field']]
            );
            $type = $collection['fields'][$order['field']]['type'] ?? 'text';
            $args['orderby'] = in_array($type, ['number', 'boolean'], true) ? 'meta_value_num' : 'meta_value';
        } else {
            throw new Spacefast_Content_Error(400, 'content_field_unknown', 'A content sort references an unknown field.');
        }
        $args['order'] = $direction;
    }
    return $args;
}

function spacefast_content_compile_meta_filter(string $field, string $operator, mixed $value, array $definition): array
{
    $comparisons = [
        'eq' => '=',
        'neq' => '!=',
        'in' => 'IN',
        'notIn' => 'NOT IN',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'contains' => 'LIKE',
        'exists' => 'EXISTS',
    ];
    if (!isset($comparisons[$operator])) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'A content filter operator is invalid.');
    }
    if (
        in_array($operator, ['in', 'notIn'], true)
        && (!is_array($value) || $value === [] || count($value) > 100)
    ) {
        throw new Spacefast_Content_Error(400, 'content_query_invalid', 'An in filter requires an array value.');
    }
    $filter = [
        'key' => spacefast_content_field_storage_name($field, $definition),
        'compare' => $comparisons[$operator],
    ];
    if ($operator !== 'exists') {
        $filter['value'] = $value;
    }
    $type = $definition['type'] ?? 'text';
    if (in_array($type, ['number', 'boolean', 'media', 'relation'], true)) {
        $filter['type'] = 'NUMERIC';
    } elseif ($type === 'date') {
        $filter['type'] = 'DATE';
    }
    return $filter;
}

function spacefast_content_serialize_post(object $post, array $collection, mixed $select): array
{
    if ($select !== null) {
        if (!is_array($select) || count($select) > 100) {
            throw new Spacefast_Content_Error(400, 'content_query_invalid', 'The content field projection is invalid.');
        }
        $builtinFields = !empty($collection['media'])
            ? ['url', 'mime_type', 'alt', 'width', 'height']
            : (!empty($collection['builtin']) ? ['content', 'excerpt', 'featured_media'] : []);
        foreach ($select as $field) {
            if (
                !spacefast_content_payload_field_identifier($field)
                || (!in_array($field, $builtinFields, true) && !isset($collection['fields'][$field]))
            ) {
                throw new Spacefast_Content_Error(400, 'content_field_unknown', 'A content projection references an unknown field.');
            }
        }
    }
    $selected = is_array($select) ? array_fill_keys($select, true) : null;
    $fields = [];
    if (!empty($collection['media'])) {
        $metadata = function_exists('wp_get_attachment_metadata')
            ? wp_get_attachment_metadata((int) $post->ID)
            : null;
        $mediaFields = [
            'url' => function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url((int) $post->ID) : '',
            'mime_type' => function_exists('get_post_mime_type') ? (string) get_post_mime_type($post) : '',
            'alt' => function_exists('get_post_meta')
                ? (string) get_post_meta((int) $post->ID, '_wp_attachment_image_alt', true)
                : '',
            'width' => is_array($metadata) && is_int($metadata['width'] ?? null) ? $metadata['width'] : null,
            'height' => is_array($metadata) && is_int($metadata['height'] ?? null) ? $metadata['height'] : null,
        ];
        foreach ($mediaFields as $name => $value) {
            if ($selected === null || isset($selected[$name])) {
                $fields[$name] = $value;
            }
        }
    } elseif (!empty($collection['builtin'])) {
        foreach (['content' => 'post_content', 'excerpt' => 'post_excerpt'] as $name => $property) {
            if ($selected === null || isset($selected[$name])) {
                $fields[$name] = (string) ($post->$property ?? '');
            }
        }
        if ($selected === null || isset($selected['featured_media'])) {
            $fields['featured_media'] = function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($post) : 0;
        }
    }
    foreach ($collection['fields'] as $name => $definition) {
        if ($selected !== null && !isset($selected[$name])) {
            continue;
        }
        $property = $definition['storageProperty'] ?? null;
        $value = is_string($property)
            ? ($post->$property ?? '')
            : get_post_meta(
                (int) $post->ID,
                spacefast_content_field_storage_name($name, $definition),
                true
            );
        $fields[$name] = spacefast_content_cast_field_value($definition, $value);
    }
    return [
        'id' => (int) $post->ID,
        'slug' => (string) $post->post_name,
        'status' => (string) $post->post_status,
        'title' => function_exists('get_the_title') ? (string) get_the_title($post) : (string) $post->post_title,
        'createdAt' => function_exists('get_post_time') ? (string) get_post_time(DATE_ATOM, true, $post) : '',
        'updatedAt' => function_exists('get_post_modified_time') ? (string) get_post_modified_time(DATE_ATOM, true, $post) : '',
        'fields' => $fields,
    ];
}

function spacefast_content_field_storage_name(string $name, array $definition): string
{
    return is_string($definition['storageName'] ?? null) && $definition['storageName'] !== ''
        ? $definition['storageName']
        : $name;
}

function spacefast_content_upsert_document(mixed $document, bool $managed): array
{
    if (!$managed || !is_array($document)) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content writes require Spacefast authorization.');
    }
    $hasId = array_key_exists('id', $document);
    $hasExternalId = array_key_exists('externalId', $document);
    if ($hasId === $hasExternalId) {
        throw new Spacefast_Content_Error(
            400,
            'content_document_identity_invalid',
            'Set exactly one document id or external id.'
        );
    }
    $identity = (string) ($document['collection'] ?? '') . '|'
        . ($hasId ? 'id:' . (int) $document['id'] : 'external:' . (string) $document['externalId']);
    return spacefast_content_with_write_lock(
        $identity,
        static fn (): array => spacefast_content_upsert_document_locked($document, $managed)
    );
}

function spacefast_content_with_write_lock(string $identity, callable $operation): array
{
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
        return $operation();
    }
    $lockName = 'sf_content_' . substr(hash('sha256', $identity), 0, 52);
    $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName)) === '1';
    if (!$acquired) {
        throw new Spacefast_Content_Error(409, 'content_write_busy', 'This content document is already being updated.');
    }
    try {
        return $operation();
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
    }
}

function spacefast_content_upsert_document_locked(mixed $document, bool $managed): array
{
    if (!$managed || !is_array($document)) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content writes require Spacefast authorization.');
    }
    $collectionName = (string) ($document['collection'] ?? '');
    $collection = spacefast_content_resolve_collection($collectionName);
    if (!empty($collection['media'])) {
        throw new Spacefast_Content_Error(400, 'content_document_invalid', 'Media uploads use the managed content editor.');
    }
    $hasId = array_key_exists('id', $document);
    $hasExternalId = array_key_exists('externalId', $document);
    $externalId = $hasExternalId ? (string) $document['externalId'] : '';
    $slug = (string) ($document['slug'] ?? '');
    $title = (string) ($document['title'] ?? '');
    $submitted = is_array($document['fields'] ?? null) ? $document['fields'] : [];
    if (
        $hasId === $hasExternalId
        || ($hasId && (!is_int($document['id']) || $document['id'] < 1))
        || ($hasExternalId && (!is_string($document['externalId']) || $externalId === ''))
        || strlen($externalId) > 255
        || trim($slug) === ''
        || strlen($slug) > 200
        || strlen($title) > 500
        || count($submitted) > SPACEFAST_CONTENT_CAPS['fields']
    ) {
        throw new Spacefast_Content_Error(400, 'content_document_invalid', 'The content document is invalid.');
    }
    $postId = $hasId ? (int) $document['id'] : 0;
    if ($postId < 1 && $externalId !== '') {
        $existing = get_posts([
            'post_type' => $collection['post_type'],
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                spacefast_content_space_meta_clause(),
                [
                    'key' => SPACEFAST_CONTENT_EXTERNAL_ID_META,
                    'value' => $externalId,
                    'compare' => '=',
                ],
            ],
            'numberposts' => 2,
            'fields' => 'ids',
        ]);
        if (count($existing) > 1) {
            throw new Spacefast_Content_Error(
                409,
                'content_document_identity_conflict',
                'More than one content document uses this external id.'
            );
        }
        $postId = (int) ($existing[0] ?? 0);
    }
    if ($postId < 1 && $externalId === '') {
        throw new Spacefast_Content_Error(400, 'content_document_invalid', 'A document id or external id is required.');
    }
    if ($postId > 0) {
        $existingPost = get_post($postId);
        if (
            !is_object($existingPost)
            || (string) $existingPost->post_type !== $collection['post_type']
            || !spacefast_content_post_belongs_to_space($postId)
        ) {
            throw new Spacefast_Content_Error(404, 'content_document_not_found', 'The content document was not found.');
        }
    }
    $validatedFields = $submitted;
    if (empty($collection['builtin'])) {
        $existingFields = [];
        if ($postId > 0) {
            foreach ($collection['fields'] as $name => $definition) {
                if (!empty($definition['required'])) {
                    $property = $definition['storageProperty'] ?? null;
                    $existing = is_string($property)
                        ? ($existingPost->$property ?? '')
                        : get_post_meta(
                            $postId,
                            spacefast_content_field_storage_name($name, $definition),
                            true
                        );
                    $existingFields[$name] = ($definition['type'] ?? '') === 'boolean'
                        ? (bool) $existing
                        : $existing;
                }
            }
        }
        $validatedFields = spacefast_content_validate_document_fields(
            $collection,
            $submitted,
            $existingFields
        );
        spacefast_content_validate_document_references($collection, $validatedFields);
    }
    $status = (string) ($document['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'publish'], true)) {
        throw new Spacefast_Content_Error(400, 'content_document_invalid', 'The document status is invalid.');
    }
    $post = [
        'post_type' => $collection['post_type'],
        'post_status' => $status,
        'post_name' => sanitize_title($slug),
        'post_title' => $title,
    ];
    if ($postId > 0) {
        $post['ID'] = $postId;
    }
    if (!empty($collection['builtin'])) {
        $allowed = ['content', 'excerpt', 'featured_media'];
        foreach (array_keys($submitted) as $fieldName) {
            if (!is_string($fieldName) || !in_array($fieldName, $allowed, true)) {
                throw new Spacefast_Content_Error(400, 'content_field_unknown', 'A content document references an unknown field.');
            }
        }
        if (array_key_exists('content', $submitted)) {
            $post['post_content'] = function_exists('wp_kses_post')
                ? wp_kses_post((string) $submitted['content'])
                : (string) $submitted['content'];
        }
        if (array_key_exists('excerpt', $submitted)) {
            $post['post_excerpt'] = function_exists('sanitize_textarea_field')
                ? sanitize_textarea_field((string) $submitted['excerpt'])
                : (string) $submitted['excerpt'];
        }
    } else {
        foreach ($collection['fields'] as $name => $definition) {
            if (!array_key_exists($name, $validatedFields) || !is_string($definition['storageProperty'] ?? null)) {
                continue;
            }
            $post[$definition['storageProperty']] = $validatedFields[$name];
        }
    }
    $saved = wp_insert_post($post, true);
    if (is_wp_error($saved)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', $saved->get_error_message());
    }
    if ($externalId !== '') {
        update_post_meta((int) $saved, SPACEFAST_CONTENT_EXTERNAL_ID_META, $externalId);
    }
    update_post_meta((int) $saved, SPACEFAST_CONTENT_SPACE_META, spacefast_content_require_space_id());
    foreach ($collection['fields'] as $name => $definition) {
        if (array_key_exists($name, $validatedFields) && !isset($definition['storageProperty'])) {
            update_post_meta(
                (int) $saved,
                spacefast_content_field_storage_name($name, $definition),
                $validatedFields[$name]
            );
        }
    }
    if (!empty($collection['builtin']) && array_key_exists('featured_media', $submitted)) {
        $mediaId = (int) $submitted['featured_media'];
        if ($mediaId > 0) {
            spacefast_content_validate_reference_id($mediaId, ['attachment']);
        }
        if ($mediaId > 0 && function_exists('set_post_thumbnail')) {
            set_post_thumbnail((int) $saved, $mediaId);
        } elseif (function_exists('delete_post_thumbnail')) {
            delete_post_thumbnail((int) $saved);
        }
    }
    $savedPost = get_post((int) $saved);
    if (!is_object($savedPost)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The saved content document could not be read.');
    }
    return spacefast_content_serialize_post($savedPost, $collection, null);
}

function spacefast_content_validate_document_fields(
    array $collection,
    array $submitted,
    array $existing = []
): array {
    $definitions = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
    foreach (array_keys($submitted) as $name) {
        if (!is_string($name) || !isset($definitions[$name])) {
            throw new Spacefast_Content_Error(400, 'content_field_unknown', 'A content document references an unknown field.');
        }
    }
    $validated = [];
    foreach ($definitions as $name => $definition) {
        if (array_key_exists($name, $submitted)) {
            $validated[$name] = spacefast_content_validate_field_value($name, $definition, $submitted[$name]);
            continue;
        }
        if (!empty($definition['required'])) {
            if (!array_key_exists($name, $existing)) {
                throw new Spacefast_Content_Error(400, 'content_field_required', 'A required content field is missing.');
            }
            spacefast_content_validate_field_value($name, $definition, $existing[$name]);
        }
    }
    return $validated;
}

function spacefast_content_validate_field_value(string $name, array $definition, mixed $value): mixed
{
    $invalid = static function () use ($name): never {
        throw new Spacefast_Content_Error(400, 'content_field_invalid', 'The ' . $name . ' content field is invalid.');
    };
    $required = !empty($definition['required']);
    $type = (string) ($definition['type'] ?? 'text');
    if (in_array($type, ['text', 'markdown', 'richText'], true)) {
        if (!is_string($value) || ($required && trim($value) === '')) {
            $invalid();
        }
        if ($type === 'text' && isset($definition['maxLength'])) {
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($length > (int) $definition['maxLength']) {
                $invalid();
            }
        }
        return spacefast_content_sanitize_field_value($definition, $value);
    }
    if ($type === 'number') {
        if ((!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))) {
            $invalid();
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            $invalid();
        }
        return $number;
    }
    if ($type === 'boolean') {
        if (!is_bool($value)) {
            $invalid();
        }
        return $value;
    }
    if ($type === 'date') {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $invalid();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $invalid();
        }
        return $value;
    }
    if ($type === 'select') {
        $allowed = array_map('strval', is_array($definition['options'] ?? null) ? $definition['options'] : []);
        if (!empty($definition['multiple'])) {
            if (!is_array($value) || count($value) > 100 || ($required && count($value) === 0)) {
                $invalid();
            }
            foreach ($value as $option) {
                if (!is_string($option) || !in_array($option, $allowed, true)) {
                    $invalid();
                }
            }
        } elseif (!is_string($value) || !in_array($value, $allowed, true)) {
            $invalid();
        }
        return spacefast_content_sanitize_select($definition, $value);
    }
    if (in_array($type, ['media', 'relation'], true)) {
        if (!empty($definition['multiple'])) {
            if (!is_array($value) || count($value) > 100 || ($required && count($value) === 0)) {
                $invalid();
            }
            $ids = [];
            foreach ($value as $item) {
                $id = spacefast_content_positive_id($item);
                if ($id === null) {
                    $invalid();
                }
                $ids[] = $id;
            }
            return array_values(array_unique($ids));
        }
        if (!$required && in_array($value, [null, '', 0, '0'], true)) {
            return 0;
        }
        $id = spacefast_content_positive_id($value);
        if ($id === null) {
            $invalid();
        }
        return $id;
    }
    if ($type === 'json') {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid();
            }
            return $decoded;
        }
        if (!is_array($value)) {
            $invalid();
        }
        return $value;
    }
    $invalid();
}

function spacefast_content_positive_id(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($parsed) ? $parsed : null;
}

function spacefast_content_validate_document_references(array $collection, array $fields): void
{
    foreach ($fields as $name => $value) {
        $definition = $collection['fields'][$name] ?? null;
        if (!is_array($definition) || !in_array(($definition['type'] ?? null), ['media', 'relation'], true)) {
            continue;
        }
        $ids = !empty($definition['multiple']) ? $value : [$value];
        if (!is_array($ids)) {
            throw new Spacefast_Content_Error(400, 'content_reference_invalid', 'A content reference is invalid.');
        }
        if (($definition['type'] ?? '') === 'media') {
            $postTypes = ['attachment'];
        } else {
            $relatedNames = is_array($definition['collections'] ?? null)
                ? $definition['collections']
                : [$definition['collection'] ?? ''];
            $postTypes = [];
            foreach ($relatedNames as $relatedName) {
                if (is_string($relatedName) && $relatedName !== '') {
                    $postTypes[] = spacefast_content_resolve_collection($relatedName)['post_type'];
                }
            }
        }
        if ($postTypes === []) {
            throw new Spacefast_Content_Error(400, 'content_reference_invalid', 'A content reference is invalid.');
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                spacefast_content_validate_reference_id($id, $postTypes);
            }
        }
    }
}

/** @param list<string> $postTypes */
function spacefast_content_validate_reference_id(int $postId, array $postTypes): void
{
    $post = function_exists('get_post') ? get_post($postId) : null;
    if (
        !is_object($post)
        || !in_array((string) ($post->post_type ?? ''), $postTypes, true)
        || !spacefast_content_post_belongs_to_space($postId)
    ) {
        throw new Spacefast_Content_Error(
            400,
            'content_reference_invalid',
            'A content reference does not belong to this Space.'
        );
    }
}

function spacefast_content_sync_markdown(array $request, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Markdown sync requires Spacefast authorization.');
    }
    if (
        !is_string($request['markdown'] ?? null)
        || strlen($request['markdown']) > 200000
        || !is_string($request['source'] ?? null)
        || trim($request['source']) === ''
        || strlen($request['source']) > 1000
    ) {
        throw new Spacefast_Content_Error(400, 'content_markdown_invalid', 'The Markdown document is invalid.');
    }
    $collectionName = (string) ($request['collection'] ?? 'posts');
    $document = [
        'externalId' => 'markdown:' . $request['source'],
        'collection' => $collectionName,
        'slug' => (string) ($request['slug'] ?? ''),
        'title' => (string) ($request['title'] ?? ''),
        'status' => (string) ($request['status'] ?? 'draft'),
        'fields' => [],
    ];
    if (
        trim($document['slug']) === ''
        || strlen($document['slug']) > 200
        || strlen($document['title']) > 500
        || !in_array($document['status'], ['draft', 'publish'], true)
    ) {
        throw new Spacefast_Content_Error(400, 'content_markdown_invalid', 'The Markdown document is invalid.');
    }
    $collection = spacefast_content_resolve_collection($collectionName);
    if (!empty($collection['builtin'])) {
        $document['fields']['content'] = spacefast_content_markdown_to_blocks($request['markdown']);
        $saved = spacefast_content_upsert_document($document, true);
        update_post_meta($saved['id'], '_spacefast_markdown_source', $request['source']);
        update_post_meta($saved['id'], SPACEFAST_CONTENT_MARKDOWN_META, $request['markdown']);
        return $saved;
    }
    $markdownField = null;
    foreach ($collection['fields'] as $name => $definition) {
        if (($definition['type'] ?? '') === 'markdown') {
            $markdownField = $name;
            break;
        }
    }
    if ($markdownField === null) {
        throw new Spacefast_Content_Error(400, 'content_markdown_field_missing', 'The collection has no Markdown field.');
    }
    $document['fields'][$markdownField] = $request['markdown'];
    return spacefast_content_upsert_document($document, true);
}

function spacefast_content_markdown_to_blocks(string $markdown): string
{
    if (!function_exists('blocks_engine_php_transformer_transform_html')) {
        throw new Spacefast_Content_Error(
            503,
            'content_transformer_unavailable',
            'The WordPress content transformer is not ready.'
        );
    }
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $binary = is_string($releaseRoot) ? $releaseRoot . '/bin/stattic-runtime' : '';
    if (
        $binary === ''
        || !is_file($binary)
        || !is_executable($binary)
        || !function_exists('_stattic_runtime_run_subprocess')
    ) {
        throw new Spacefast_Content_Error(
            503,
            'content_markdown_runtime_unavailable',
            'The Markdown compiler is not ready.'
        );
    }
    $run = _stattic_runtime_run_subprocess(
        [$binary, 'markdown-to-html'],
        null,
        $markdown,
        null,
        15000,
        4194304,
        65536
    );
    if (!$run['spawned'] || $run['timedOut'] || $run['exitCode'] !== 0) {
        error_log('Spacefast Markdown compiler failed: ' . trim($run['stderr']));
        throw new Spacefast_Content_Error(422, 'content_markdown_compile_failed', 'The Markdown document could not be compiled.');
    }
    $html = $run['stdout'];
    $result = blocks_engine_php_transformer_transform_html($html, [
        'source' => 'spacefast:markdown-sync',
        'scope' => 'content-sync',
        'strict' => true,
        'allow_fallbacks' => false,
    ]);
    $blocks = is_array($result) ? ($result['serialized_blocks'] ?? null) : null;
    if (($result['status'] ?? null) !== 'success' || !is_string($blocks)) {
        throw new Spacefast_Content_Error(
            422,
            'content_markdown_transform_failed',
            'The Markdown document could not be converted to WordPress blocks.'
        );
    }
    return $blocks;
}

function spacefast_content_sanitize_field_value(array $definition, mixed $value): mixed
{
    return match ($definition['type'] ?? 'text') {
        'boolean' => (bool) $value,
        'number' => (float) $value,
        'media', 'relation' => !empty($definition['multiple'])
            ? array_values(array_filter(array_map(
                'intval',
                is_array($value) ? $value : explode(',', (string) $value)
            ), static fn (int $id): bool => $id > 0))
            : (int) $value,
        'select' => spacefast_content_sanitize_select($definition, $value),
        'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
        'richText' => function_exists('wp_kses_post') ? wp_kses_post((string) $value) : (string) $value,
        default => function_exists('sanitize_textarea_field')
            ? sanitize_textarea_field((string) $value)
            : trim((string) $value),
    };
}

function spacefast_content_sanitize_select(array $definition, mixed $value): mixed
{
    $allowed = array_map('strval', is_array($definition['options'] ?? null) ? $definition['options'] : []);
    if (!empty($definition['multiple'])) {
        return array_values(array_intersect(array_map('strval', is_array($value) ? $value : []), $allowed));
    }
    return in_array((string) $value, $allowed, true) ? (string) $value : '';
}

function spacefast_content_cast_field_value(array $definition, mixed $value): mixed
{
    return match ($definition['type'] ?? 'text') {
        'boolean' => (bool) $value,
        'number' => (float) $value,
        'media', 'relation' => !empty($definition['multiple'])
            ? array_values(array_map('intval', is_array($value) ? $value : []))
            : ($value === '' ? null : (int) $value),
        'json' => is_string($value) ? (json_decode($value, true) ?? $value) : $value,
        default => $value,
    };
}

function spacefast_content_encode_cursor(int $page): string
{
    return rtrim(strtr(base64_encode(json_encode(['page' => $page])), '+/', '-_'), '=');
}

function spacefast_content_decode_cursor(mixed $cursor): int
{
    if ($cursor === null || $cursor === '') {
        return 1;
    }
    if (!is_string($cursor) || strlen($cursor) > 512) {
        throw new Spacefast_Content_Error(400, 'content_cursor_invalid', 'The content cursor is invalid.');
    }
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $payload = is_string($decoded) ? json_decode($decoded, true) : null;
    $page = is_array($payload) ? (int) ($payload['page'] ?? 0) : 0;
    if ($page < 1 || $page > 100000) {
        throw new Spacefast_Content_Error(400, 'content_cursor_invalid', 'The content cursor is invalid.');
    }
    return $page;
}
