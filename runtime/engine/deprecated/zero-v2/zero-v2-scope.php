<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/wordpress-scope.php';
require_once __DIR__ . '/../../shared/wordpress-cache-scope.php';

/** Bind before normal plugins, roles, sessions, Action Scheduler or Zero bootstrap. */
function _stattic_zero_v2_bind_wordpress(): void
{
    global $wpdb, $table_prefix, $wp_object_cache;
    if (!isset($GLOBALS['SPACEFAST_ZERO_V2_ROOT'])) return;
    if (did_action('plugins_loaded') > 0 || isset($GLOBALS['current_user']) || isset($GLOBALS['wp_roles'])) {
        throw new RuntimeException('Zero must bind WordPress state before plugins and identity bootstrap.');
    }
    if (is_multisite()) throw new RuntimeException('The Zero MySQL binding requires single-site WordPress.');
    $space = spacefast_content_space_id();
    if ($space === '') throw new RuntimeException('Zero requires an admitted Space binding.');
    // All immutable versions share this environment's current tables. A code
    // rollback cannot select a historical database or change the namespace.
    $scope = 'sfz_' . substr(hash('sha256', $space), 0, 24) . '_';
    // WordPress loads pluggable.php after MU plugins. Seed option-backed salts
    // in the host binding now, then preserve them across the prefix switch.
    $salts = [];
    foreach (['auth', 'secure_auth', 'logged_in', 'nonce'] as $scheme) {
        foreach (['key', 'salt'] as $part) {
            $name = $scheme . '_' . $part;
            $value = get_site_option($name);
            if (!is_string($value) || $value === '') {
                $value = bin2hex(random_bytes(64));
                update_site_option($name, $value);
            }
            $salts[$name] = $value;
        }
    }
    $platform = get_option('active_plugins', []);
    $hostTheme = get_option('template');
    $allowed = ['secure-custom-fields', 'action-scheduler', 'mcp-adapter', 'polylang'];
    $platform = array_values(array_filter($platform, static fn (string $plugin): bool => in_array(explode('/', $plugin)[0], $allowed, true)));
    $cache = new Spacefast_Zero_Cache($wp_object_cache, $scope);
    $table_prefix = $scope;
    $wpdb->set_prefix($scope);
    // CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE are host configuration, not
    // a permission to put app identities in the Atomic site's user tables.
    $wpdb->users = $scope . 'users';
    $wpdb->usermeta = $scope . 'usermeta';
    $wp_object_cache = $cache;
    add_filter('pre_option_active_plugins', static fn (): array => $platform);
    add_filter('pre_site_option_active_sitewide_plugins', static fn (): array => []);
    add_filter('action_scheduler_allow_async_request_runner', '__return_false');
    // Core can spawn only a site-wide wp-cron request, which carries no Space
    // binding, and its lock would make the next worker tick skip. Scoped work
    // runs only from the box worker's explicit host/version binding.
    remove_action('init', 'wp_cron');
    // The box's WordPress belongs to the platform: a Space-bound cron tick must
    // never update core, plugins or themes, or mail the Space about it.
    add_filter('automatic_updater_disabled', '__return_true');
    add_filter('auto_update_core', '__return_false');
    add_filter('auto_update_plugin', '__return_false');
    add_filter('auto_update_theme', '__return_false');
    add_filter('site_url', 'spacefast_content_request_url', 1);
    add_filter('home_url', 'spacefast_content_public_url', 1);
    add_filter('rest_url', 'spacefast_content_request_url', 1);
    add_filter('upload_dir', 'spacefast_content_scope_upload_dir');
    $initializing = ($GLOBALS['SPACEFAST_ZERO_V2_INITIALIZING'] ?? false) === true;
    if ($initializing && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($scope . 'options'))) === null) {
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        foreach (explode(';', wp_get_db_schema('all')) as $query) {
            if (trim($query) === '') continue;
            $wpdb->query(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', trim($query)));
            if ($wpdb->last_error !== '') throw new RuntimeException('Cannot create the Space WordPress tables: ' . $wpdb->last_error);
        }
        if (!defined('WP_DEFAULT_THEME')) {
            if (!is_string($hostTheme) || $hostTheme === '') throw new RuntimeException('WordPress has no host template.');
            define('WP_DEFAULT_THEME', $hostTheme);
        }
        if ($wpdb->last_error !== '') throw new RuntimeException('Cannot create the Space WordPress tables: ' . $wpdb->last_error);
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        populate_options();
        // populate_options() guesses home and siteurl from this request, the
        // finalize API URL. The request-origin filters supply the host; the
        // stored value must be the root path or every home_url() keeps that path.
        update_option('home', 'http://localhost');
        update_option('siteurl', 'http://localhost');
        update_option('blog_public', 0);
        update_option('permalink_structure', '/%postname%/');
        populate_roles();
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($scope . 'options'))) === null) {
        throw new RuntimeException('The Space WordPress binding has not been initialized.');
    }
    foreach ($salts as $name => $value) {
        if ($initializing) update_option($name, $value, false);
        add_filter('pre_site_option_' . $name, static fn (): string => $value);
    }
    $GLOBALS['SPACEFAST_ZERO_V2_DATABASE_PREFIX'] = $scope;
}

/**
 * Legacy `sfz_<24hex>_` prefix helper for pre-cutover boxes with an explicit
 * `legacyPrefix` flag. New SpacePress Spaces never call this and instead share
 * Atomic's native `$table_prefix` from `wp-config.php`.
 */
function _stattic_zero_v2_bind_legacy_prefix(string $spaceId, bool $initializing = false): void
{
    global $wpdb, $table_prefix, $wp_object_cache;
    if ($spaceId === '') {
        return;
    }
    $scope = 'sfz_' . substr(hash('sha256', $spaceId), 0, 24) . '_';
    $hasWordPress = function_exists('get_site_option')
        && function_exists('get_option')
        && function_exists('add_filter')
        && is_object($wpdb)
        && method_exists($wpdb, 'get_var')
        && defined('ABSPATH');

    $salts = [];
    $hostTheme = null;
    $hostPlugins = null;
    if ($hasWordPress) {
        foreach (['auth', 'secure_auth', 'logged_in', 'nonce'] as $scheme) {
            foreach (['key', 'salt'] as $part) {
                $name = $scheme . '_' . $part;
                $value = get_site_option($name);
                if (!is_string($value) || $value === '') {
                    $value = bin2hex(random_bytes(64));
                    update_site_option($name, $value);
                }
                $salts[$name] = $value;
            }
        }
        $hostTheme = get_option('template');
        $plugins = get_option('active_plugins', []);
        $hostPlugins = is_array($plugins) ? array_values($plugins) : [];
        if (
            defined('WP_PLUGIN_DIR')
            && is_file(WP_PLUGIN_DIR . '/two-factor/two-factor.php')
            && !in_array('two-factor/two-factor.php', $hostPlugins, true)
        ) {
            $hostPlugins[] = 'two-factor/two-factor.php';
        }
    }

    $table_prefix = $scope;
    $wpdb->set_prefix($scope);
    $wpdb->users = $scope . 'users';
    $wpdb->usermeta = $scope . 'usermeta';
    if (is_object($wp_object_cache) && !$wp_object_cache instanceof Spacefast_Zero_Cache) {
        $wp_object_cache = new Spacefast_Zero_Cache($wp_object_cache, $scope);
    }

    if ($hasWordPress) {
        add_filter('pre_option_active_plugins', static fn (): array => $hostPlugins ?? []);
        add_filter('pre_site_option_active_sitewide_plugins', static fn (): array => []);
        add_filter('action_scheduler_allow_async_request_runner', '__return_false');
        if (function_exists('remove_action')) {
            remove_action('init', 'wp_cron');
        }
        add_filter('upload_dir', 'spacefast_content_scope_upload_dir');

        $optionsTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($scope . 'options')));
        if ($initializing && $optionsTable === null) {
            require_once ABSPATH . 'wp-admin/includes/schema.php';
            foreach (explode(';', wp_get_db_schema('all')) as $query) {
                if (trim($query) === '') continue;
                $wpdb->query(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', trim($query)));
                if ($wpdb->last_error !== '') throw new RuntimeException('Cannot create the Space WordPress tables: ' . $wpdb->last_error);
            }
            if (!defined('WP_DEFAULT_THEME')) {
                if (!is_string($hostTheme) || $hostTheme === '') throw new RuntimeException('WordPress has no host template.');
                define('WP_DEFAULT_THEME', $hostTheme);
            }
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            populate_options();
            update_option('home', 'http://localhost');
            update_option('siteurl', 'http://localhost');
            update_option('blog_public', 0);
            update_option('permalink_structure', '/%postname%/');
            populate_roles();
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($scope . 'options'))) === null) {
            throw new RuntimeException('The Space WordPress binding has not been initialized.');
        }
        foreach ($salts as $name => $value) {
            if ($initializing) update_option($name, $value, false);
            add_filter('pre_site_option_' . $name, static fn (): string => $value);
        }
    }

    $GLOBALS['SPACEFAST_ZERO_V2_DATABASE_PREFIX'] = $scope;
}
