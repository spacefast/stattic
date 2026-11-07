<?php
declare(strict_types=1);
require_once __DIR__ . "/wordpress-scope.php";

/** Namespace every cache group, including WordPress's global users/options groups. */
final class Spacefast_Zero_Cache
{
    private string $generation;

    public function __construct(private object $cache, private string $scope)
    {
        $value = $cache->get('generation', $scope);
        if (!is_string($value)) {
            $cache->add('generation', bin2hex(random_bytes(16)), $scope);
            $value = $cache->get('generation', $scope);
        }
        $this->generation = is_string($value) ? $value : 'local';
    }

    private function group(string $group): string { return $this->scope . ':' . $this->generation . ':' . $group; }
    public function get(mixed $key, string $group = '', bool $force = false, mixed &$found = null): mixed { return $this->cache->get($key, $this->group($group), $force, $found); }
    public function set(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->set($key, $value, $this->group($group), $expire); }
    public function add(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->add($key, $value, $this->group($group), $expire); }
    public function replace(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->replace($key, $value, $this->group($group), $expire); }
    public function delete(mixed $key, string $group = '', bool $deprecated = false): bool { return $this->cache->delete($key, $this->group($group), $deprecated); }
    public function incr(mixed $key, int $offset = 1, string $group = ''): mixed { return $this->cache->incr($key, $offset, $this->group($group)); }
    public function decr(mixed $key, int $offset = 1, string $group = ''): mixed { return $this->cache->decr($key, $offset, $this->group($group)); }
    public function get_multiple(array $keys, string $group = '', bool $force = false): array { return $this->cache->get_multiple($keys, $this->group($group), $force); }
    public function set_multiple(array $data, string $group = '', int $expire = 0): array { return $this->cache->set_multiple($data, $this->group($group), $expire); }
    public function add_multiple(array $data, string $group = '', int $expire = 0): array { return $this->cache->add_multiple($data, $this->group($group), $expire); }
    public function delete_multiple(array $keys, string $group = ''): array { return $this->cache->delete_multiple($keys, $this->group($group)); }
    public function add_global_groups(array|string $groups): void { $this->cache->add_global_groups(array_map($this->group(...), (array) $groups)); }
    public function add_non_persistent_groups(array|string $groups): void { $this->cache->add_non_persistent_groups(array_map($this->group(...), (array) $groups)); }
    public function switch_to_blog(int $blog): void { throw new RuntimeException('Managed Zero requests cannot change their database binding.'); }
    public function flush_group(string $group): bool { return $this->cache->flush_group($this->group($group)); }
    public function flush_runtime(): bool { return $this->flush(); }
    public function flush(): bool
    {
        $this->generation = bin2hex(random_bytes(16));
        return $this->cache->set('generation', $this->generation, $this->scope);
    }
    public function close(): bool { return method_exists($this->cache, 'close') ? $this->cache->close() : true; }
    public function reset(): void { $this->flush(); }
    public function stats(): void { $this->cache->stats(); }
}

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
    $platform = array_values(array_filter($platform, static fn (string $plugin): bool => in_array(explode('/', $plugin)[0], ['secure-custom-fields', 'action-scheduler', 'mcp-adapter', 'polylang'], true)));
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
    // binding. Scoped workers must enter through an explicit host/version.
    add_filter('pre_spawn_cron', '__return_false');
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
