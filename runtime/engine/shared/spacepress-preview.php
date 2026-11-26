<?php
declare(strict_types=1);

// SpacePress previews: every version served on a version or branch hostname,
// except the one the production route selects, runs on its own SQLite
// database, seeded from that version's code on its first WordPress request.
// Production keeps the Space's WordPress tables. See
// docs/decisions/2026-11-20-spacepress-previews-use-a-database-per-version.md.
//
// One directory per preview version, next to the immutable version:
//
//   spaces/<space>/previews/<version>/
//     scope              random token; with the version it names the table
//                        prefix and object-cache scope, new for every seed
//     database.sqlite*   the preview's database (plus -wal, -shm, .lock files)
//     manifest.json      the version's manifest as the seed read it: the build
//                        the preview loads, though promote may compile the
//                        version again and point the version at a newer one
//     ready.json         the preview's ready marker, written after the seed
//     store/             the version's SpacePress store, with its own ready/
//     content-media/     the preview's uploads
//     .seed.lock         held by the one request that seeds

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/spacepress.php';

const STATTIC_SPACEPRESS_PREVIEW_DATABASE = 'database.sqlite';
const STATTIC_SPACEPRESS_PREVIEW_PLUGIN = 'sqlite-database-integration';

function _stattic_spacepress_preview_dir(string $privateRoot, string $spaceId, string $versionId): string
{
    return $privateRoot . '/spaces/' . $spaceId . '/previews/' . $versionId;
}

/** The version the production route selects, from the Space's overlay. */
function _stattic_spacepress_production_version(string $privateRoot, string $spaceId): ?string
{
    require_once __DIR__ . '/artifacts.php';
    $pointer = _stattic_runtime_read_json($privateRoot . '/spaces/' . $spaceId . '/space.json');
    $overlay = is_array($pointer) ? _stattic_v4_overlay($privateRoot, $spaceId, $pointer) : null;
    $versions = is_array($overlay) ? ($overlay['versions'] ?? null) : null;
    $version = is_array($versions) ? ($versions['production'] ?? null) : null;
    return is_string($version) && _stattic_id_valid($version) ? $version : null;
}

/** The version's compiled SpacePress root (`.spacepress-compiled`, or `.zero-compiled` before the rename). */
function _stattic_spacepress_preview_compiled(string $privateRoot, string $spaceId, string $versionId): ?string
{
    return _stattic_spacepress_version_root(_stattic_version_root($privateRoot, $spaceId, $versionId));
}

/**
 * Whether this request binds the version as a preview. Only a version or
 * branch hostname can, and only while the production route selects another
 * version: a link to the live version always serves the live site.
 */
function _stattic_spacepress_is_preview(string $privateRoot, string $spaceId, string $versionId, array $serving): bool
{
    $routeName = is_string($serving['route_name'] ?? null) ? $serving['route_name'] : '';
    $previewHost = !empty($serving['immutable']) || !in_array($routeName, ['', 'production', 'live'], true);
    if (!$previewHost) return false;
    if (_stattic_spacepress_preview_compiled($privateRoot, $spaceId, $versionId) === null) return false;
    return _stattic_spacepress_production_version($privateRoot, $spaceId) !== $versionId;
}

/** Whether a SpacePress version finished its finalize, with or without a database. */
function _stattic_spacepress_finalized(string $compiledRoot): bool
{
    return is_file($compiledRoot . '/finalized.json') || is_file($compiledRoot . '/ready.json');
}

/**
 * Bind the preview before WordPress loads, and decide who seeds it. Returns the
 * binding the database opener, the binder and the mount read from
 * $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'].
 *
 * A request for an unready preview that does not get the seed lock is answered
 * here, before WordPress or the provider's page cache run, so the answer is
 * never stored as a page and holds no PHP worker.
 */
function _stattic_spacepress_preview_bind(string $privateRoot, string $spaceId, string $versionId): array
{
    $compiled = _stattic_spacepress_preview_compiled($privateRoot, $spaceId, $versionId);
    $manifest = $compiled === null ? null : _stattic_spacepress_manifest($compiled);
    if ($compiled === null || $manifest === null || !_stattic_spacepress_finalized($compiled)) {
        throw new RuntimeException('This SpacePress version has not finalized.');
    }
    if (!is_file($compiled . '/variables-preview.json')) {
        // Versions finalized before previews had their own variables hold one
        // set that may be production's. They serve only on production.
        _stattic_problem_response(409, 'spacepress_preview_republish', 'Publish this version again to preview it.');
    }
    _stattic_spacepress_preview_require_platform($privateRoot);
    $dir = _stattic_spacepress_preview_dir($privateRoot, $spaceId, $versionId);
    _stattic_runtime_mkdir($dir);
    $binding = [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'dir' => $dir,
        'database' => $dir . '/' . STATTIC_SPACEPRESS_PREVIEW_DATABASE,
        'store' => $dir . '/store',
        'seeding' => false,
    ];
    if (is_file($dir . '/ready.json') && is_file($dir . '/manifest.json')) return _stattic_spacepress_preview_pinned($binding);
    $lock = fopen($dir . '/.seed.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        _stattic_spacepress_preview_preparing();
    }
    // Under the lock: another request may have finished the seed meanwhile.
    clearstatcache();
    // A preview seeded before builds were pinned has no manifest.json, but its
    // ready marker names the build it seeded. While the version still serves
    // that build, the version's manifest is that pin and the database stays;
    // after a recompile it seeds again rather than guess.
    if (is_file($dir . '/ready.json') && !is_file($dir . '/manifest.json')) {
        $ready = _stattic_runtime_read_json($dir . '/ready.json');
        if (is_array($ready) && ($ready['build'] ?? null) === $manifest['build']) {
            _stattic_runtime_write_json_atomic($dir . '/manifest.json', $manifest, true);
        }
    }
    if (is_file($dir . '/ready.json') && is_file($dir . '/manifest.json')) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return _stattic_spacepress_preview_pinned($binding);
    }
    // A database without a ready marker is a seed that died. Start again from
    // nothing, under a new scope so no cache entry of the old one is read.
    _stattic_spacepress_preview_reset($dir);
    _stattic_runtime_write_private_string($dir . '/scope', bin2hex(random_bytes(16)), true);
    _stattic_runtime_write_json_atomic($dir . '/manifest.json', $manifest, true);
    _stattic_spacepress_preview_store($compiled . '/store', $binding['store']);
    $binding['manifest'] = $manifest;
    $binding['build'] = $manifest['build'];
    $binding['seeding'] = true;
    $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW_LOCK'] = $lock;
    return _stattic_spacepress_preview_scoped($binding);
}

/** What a box needs to give a version a preview database of its own. */
function _stattic_spacepress_preview_require_platform(string $privateRoot): void
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('SpacePress previews need PHP\'s pdo_sqlite extension on this box.');
    }
    if (!is_file(_stattic_spacepress_preview_plugin_root($privateRoot) . '/wp-includes/sqlite/db.php')) {
        throw new RuntimeException('SpacePress previews need the SQLite Database Integration plugin on this box.');
    }
}

/**
 * Open the preview's database inside WordPress's own boot, with no
 * wp-content/db.php of ours: a site keeps its own drop-in. Core runs
 * enable_loading_object_cache_dropin once, after it built $wpdb (with any
 * drop-in) and before the object cache or any query, so a preview's
 * connection replaces whichever one core built. Before WordPress loads, the
 * filter goes into $wp_filter, which core adopts when it loads plugin.php.
 * The binder refuses a request whose connection is not the preview's.
 */
function _stattic_spacepress_preview_hook_database(): void
{
    $open = static function ($enabled) {
        require_once __DIR__ . '/../wordpress/preview-db.php';
        return $enabled;
    };
    if (function_exists('add_filter')) {
        add_filter('enable_loading_object_cache_dropin', $open, PHP_INT_MIN);
        return;
    }
    $GLOBALS['wp_filter']['enable_loading_object_cache_dropin'][PHP_INT_MIN][] = ['function' => $open, 'accepted_args' => 1];
}

/** A seeded preview loads the build it seeded, whatever the version names now. */
function _stattic_spacepress_preview_pinned(array $binding): array
{
    $manifest = _stattic_spacepress_manifest($binding['dir']);
    if ($manifest === null) throw new RuntimeException('The SpacePress preview has no pinned build.');
    $binding['manifest'] = $manifest;
    $binding['build'] = $manifest['build'];
    return _stattic_spacepress_preview_scoped($binding);
}

/** The table prefix and cache scope: 16 characters, inside WooCommerce's 20. */
function _stattic_spacepress_preview_scoped(array $binding): array
{
    $token = @file_get_contents($binding['dir'] . '/scope');
    if (!is_string($token) || preg_match('/\A[0-9a-f]{32}\z/D', $token) !== 1) {
        throw new RuntimeException('The SpacePress preview has no cache scope.');
    }
    $binding['prefix'] = 'sfp' . substr(hash('sha256', $binding['version_id'] . ':' . $token), 0, 12) . '_';
    return $binding;
}

/** Remove everything a seed wrote, keeping only the directory and its lock. */
function _stattic_spacepress_preview_reset(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.seed.lock') continue;
        _stattic_runtime_rm_recursive($dir . '/' . $entry);
    }
}

/**
 * The preview's own SpacePress store: the version's content-addressed runtime,
 * app and build entries, linked, and an empty ready/. The production initialize
 * reads the version's own store, so a seeded preview never marks the build
 * ready for production, and the reverse.
 */
function _stattic_spacepress_preview_store(string $versionStore, string $previewStore): void
{
    _stattic_runtime_mkdir($previewStore . '/ready');
    foreach (['runtime', 'app', 'build'] as $entry) {
        $target = realpath($versionStore . '/' . $entry);
        if (!is_string($target) || !symlink($target, $previewStore . '/' . $entry)) {
            throw new RuntimeException('Cannot prepare the SpacePress preview store.');
        }
    }
}

function _stattic_spacepress_preview_preparing(): never
{
    $headers = ['Retry-After' => '2', 'Cache-Control' => 'no-store'];
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'text/html')) {
        _stattic_response_send(503, '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="2">'
            . '<title>Preparing this preview</title><p>Preparing this preview. This page reloads by itself.</p>',
            'text/html; charset=utf-8', $headers);
    }
    _stattic_problem_response(503, 'spacepress_preview_preparing', 'This preview is being prepared. Retry shortly.', [], $headers);
}

/**
 * Where the box keeps the pinned SQLite Database Integration plugin, in the
 * site's wp-content. This runs before WordPress defines
 * WP_CONTENT_DIR; the private root is `<site>/.stattic/storage`.
 */
function _stattic_spacepress_preview_plugin_root(string $privateRoot): string
{
    return dirname($privateRoot, 2) . '/wp-content/plugins/' . STATTIC_SPACEPRESS_PREVIEW_PLUGIN;
}

/**
 * The seeding request, once WordPress has loaded: install the app and run its
 * seed into the preview database, then mark the preview ready. A failed seed
 * leaves nothing behind, and the next request tries again.
 */
function _stattic_spacepress_preview_seed(): void
{
    $binding = $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'] ?? null;
    if (!is_array($binding) || $binding['seeding'] !== true) return;
    $binding['seeding'] = false;
    $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'] = $binding;
    try {
        $loader = _stattic_spacepress_loader_class();
        $loader::initialize($binding['store'], $binding['build']);
        // A seeded preview schedules core's update checks. Previews never
        // call wordpress.org.
        foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $hook) wp_clear_scheduled_hook($hook);
        _stattic_runtime_write_json_atomic($binding['dir'] . '/ready.json', ['version_id' => $binding['version_id'], 'build' => $binding['build']], true);
    } catch (Throwable $failure) {
        if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'close')) $GLOBALS['wpdb']->close();
        _stattic_spacepress_preview_reset($binding['dir']);
        _stattic_spacepress_preview_release();
        error_log('SpacePress preview seed failed: ' . $failure->getMessage());
        _stattic_problem_response(503, 'spacepress_preview_seed_failed', 'This preview could not be prepared. Reload to try again.', [], ['Retry-After' => '5', 'Cache-Control' => 'no-store']);
    }
    _stattic_spacepress_preview_release();
}

function _stattic_spacepress_preview_release(): void
{
    $lock = $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW_LOCK'] ?? null;
    unset($GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW_LOCK']);
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * The box's active plugins, read from its MySQL options with a connection of
 * their own: a preview's database has no platform plugin list, and production
 * fixes it. The connection runs this one query and is closed.
 */
function _stattic_spacepress_preview_box_plugins(string $boxPrefix): array
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $boxPrefix) !== 1) throw new RuntimeException('Invalid box table prefix.');
    $box = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    try {
        $value = $box->get_var("SELECT option_value FROM `{$boxPrefix}options` WHERE option_name = 'active_plugins' LIMIT 1");
        if ($box->last_error !== '') throw new RuntimeException('Cannot read the box plugin list.');
    } finally {
        $box->close();
    }
    $plugins = is_string($value) ? maybe_unserialize($value) : [];
    return is_array($plugins) ? array_values(array_filter($plugins, 'is_string')) : [];
}

/**
 * Remove the preview directories of versions no route or version hostname
 * serves any more. Runs after every routing change; a version's preview goes
 * with its last hostname.
 */
function _stattic_spacepress_preview_prune(string $privateRoot, string $spaceId): void
{
    $previews = $privateRoot . '/spaces/' . $spaceId . '/previews';
    if (!is_dir($previews)) return;
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $intent = _stattic_runtime_space_routing_doc($spaceRoot, 'intent') ?? ['routes' => []];
    $tombstones = array_fill_keys(array_filter((_stattic_runtime_space_routing_doc($spaceRoot, 'tombstones') ?? [])['hostnames'] ?? [], 'is_string'), true);
    $served = [];
    foreach ($intent['routes'] as $route) {
        $target = is_array($route) && is_array($route['target'] ?? null) ? $route['target'] : [];
        if (($target['type'] ?? null) === 'version' && is_string($target['version_id'] ?? null)
            && !isset($tombstones[(string) ($route['hostname'] ?? '')])) {
            $served[$target['version_id']] = true;
        }
    }
    foreach (glob($spaceRoot . '/routes/*.json') ?: [] as $pointer) {
        $route = _stattic_runtime_read_json($pointer);
        // A pointer that exists but can't be read might name any version:
        // delete nothing rather than a preview a route still serves.
        if (!is_array($route) || !is_string($route['version_id'] ?? null)) return;
        $served[$route['version_id']] = true;
    }
    foreach (scandir($previews) ?: [] as $versionId) {
        if ($versionId === '.' || $versionId === '..' || isset($served[$versionId])) continue;
        $dir = $previews . '/' . $versionId;
        // A request still seeding this preview holds its lock; it goes next time.
        $lock = @fopen($dir . '/.seed.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            continue;
        }
        _stattic_runtime_rm_recursive($dir);
        if (is_resource($lock)) fclose($lock);
    }
}

/**
 * Bind WordPress to the preview before plugins load (from
 * `_stattic_spacepress_bind_wordpress`). The opener already opened the
 * preview's SQLite database under its prefix; this scopes the object cache to
 * that prefix, keeps only the platform plugins an app needs (never the site's
 * Jetpack or WooCommerce, which would act as the live site), keeps links on the
 * preview's own host, and while seeding installs WordPress's tables.
 */
function _stattic_spacepress_preview_bind_wordpress(array $preview): void
{
    global $wpdb, $wp_object_cache;
    require_once __DIR__ . '/wordpress-scope.php';
    require_once __DIR__ . '/wordpress-cache-scope.php';
    if (!($wpdb instanceof Spacefast_Preview_SQLite_DB)) {
        throw new RuntimeException('The SpacePress preview database did not load.');
    }
    if (did_action('plugins_loaded') > 0 || isset($GLOBALS['current_user']) || isset($GLOBALS['wp_roles'])) {
        throw new RuntimeException('A SpacePress preview must bind WordPress before plugins and identity bootstrap.');
    }
    $scope = $preview['prefix'];
    if (is_object($wp_object_cache) && !$wp_object_cache instanceof Spacefast_Zero_Cache) {
        $wp_object_cache = new Spacefast_Zero_Cache($wp_object_cache, $scope);
    }
    $allowed = ['secure-custom-fields', 'action-scheduler', 'mcp-adapter', 'polylang'];
    $platform = array_values(array_filter(
        _stattic_spacepress_preview_box_plugins($wpdb->spacefast_box_prefix),
        static fn (string $plugin): bool => in_array(explode('/', $plugin)[0], $allowed, true),
    ));
    add_filter('pre_option_active_plugins', static fn (): array => $platform);
    add_filter('pre_site_option_active_sitewide_plugins', static fn (): array => []);
    // Action Scheduler's async runner posts to admin-ajax.php, in the visitor
    // lane and its access rules. Its queue runs from core's cron instead.
    add_filter('action_scheduler_allow_async_request_runner', '__return_false');
    // The seed clears core's update checks, and core schedules them again on
    // every init. A preview never calls wordpress.org.
    remove_action('init', 'wp_schedule_update_checks');
    // A preview's links stay on the preview's own host, never the production
    // domain, and never the host its database was installed under.
    remove_filter('home_url', 'spacefast_content_public_url', 1);
    add_filter('home_url', 'spacefast_content_request_url', 1);
    $origin = static fn (): string => rtrim((string) spacefast_content_request_url('/'), '/');
    add_filter('option_home', $origin);
    add_filter('option_siteurl', $origin);
    add_filter('upload_dir', 'spacefast_content_scope_upload_dir');
    // The opener creates the options table before core's installed check, so
    // a preview installs its WordPress tables while it has no posts table.
    $installed = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($scope . 'posts'))) !== null;
    if ($preview['seeding'] === true && !$installed) {
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        foreach (explode(';', wp_get_db_schema('all')) as $query) {
            if (trim($query) === '') continue;
            $wpdb->query(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', trim($query)));
            if ($wpdb->last_error !== '') throw new RuntimeException('Cannot create the preview WordPress tables: ' . $wpdb->last_error);
        }
        // A preview starts from the engine's managed theme, not the site's.
        if (!defined('WP_DEFAULT_THEME')) define('WP_DEFAULT_THEME', 'spacefast-managed');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        populate_options();
        update_option('home', 'http://localhost');
        update_option('siteurl', 'http://localhost');
        update_option('blog_public', 0);
        update_option('permalink_structure', '/%postname%/');
        populate_roles();
        $installed = true;
    }
    if (!$installed) {
        throw new RuntimeException('The SpacePress preview database has not been seeded.');
    }
}
