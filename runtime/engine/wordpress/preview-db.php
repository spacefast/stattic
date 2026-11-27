<?php
declare(strict_types=1);

// Opens the preview's SQLite database for a request bound to a SpacePress
// preview (shared/spacepress-preview.php), through the box's pinned SQLite
// Database Integration plugin, under the preview's table prefix. Core loads
// this from enable_loading_object_cache_dropin: after it built $wpdb (its own,
// or a site's wp-content/db.php) and before the object cache or any query.

$spacefastPreview = $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'];
$spacefastSqlite = WP_CONTENT_DIR . '/plugins/sqlite-database-integration';
// A site's own SQLite setup may have defined these first; a constant cannot
// change, and the driver opens whatever DB_PATH names.
if (defined('DB_PATH') && DB_PATH !== $spacefastPreview['database']) {
    throw new RuntimeException('This site sets its own DB_PATH, so a SpacePress preview cannot open its database.');
}
if (!defined('DB_ENGINE')) define('DB_ENGINE', 'sqlite');
if (!defined('DB_PATH')) define('DB_PATH', $spacefastPreview['database']);
if (!defined('SQLITE_JOURNAL_MODE')) define('SQLITE_JOURNAL_MODE', 'WAL');
if (!defined('SQLITE_BUSY_TIMEOUT')) define('SQLITE_BUSY_TIMEOUT', 50000);
require_once $spacefastSqlite . '/wp-includes/database/version.php';
require_once $spacefastSqlite . '/constants.php';
require_once $spacefastSqlite . '/wp-includes/database/load.php';
require_once $spacefastSqlite . '/wp-includes/sqlite/class-wp-sqlite-db.php';
require_once $spacefastSqlite . '/wp-includes/sqlite/install-functions.php';
require_once __DIR__ . '/preview-db-driver.php';

/**
 * Core starts the object cache under the global table prefix. This keeps the
 * preview's prefix in both places, so even core's first reads use the
 * preview's tables and cache keys, and remembers the box's prefix for the one
 * read of its plugin list.
 */
final class Spacefast_Preview_SQLite_DB extends Spacefast_SQLite_DB
{
    public string $spacefast_box_prefix = '';

    public function set_prefix($prefix, $set_table_names = true)
    {
        $preview = $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW']['prefix'];
        if ($this->spacefast_box_prefix === '' && is_string($prefix) && $prefix !== $preview) $this->spacefast_box_prefix = $prefix;
        $GLOBALS['table_prefix'] = $preview;
        return parent::set_prefix($preview, $set_table_names);
    }
}

// The connection core built has run no query. The constructor installs the
// preview's as $GLOBALS['wpdb']; core's setup then runs again on it.
$spacefastSiteDb = $GLOBALS['wpdb'] ?? null;
new Spacefast_Preview_SQLite_DB(defined('DB_NAME') && DB_NAME !== '' ? DB_NAME : 'wordpress');
if (is_object($spacefastSiteDb) && method_exists($spacefastSiteDb, 'close')) $spacefastSiteDb->close();
wp_set_wpdb_vars();

if ($spacefastPreview['seeding'] === true) {
    // Core's installed check reads siteurl before any plugin loads. The binder
    // creates the other tables once WordPress has booted.
    $spacefastOptions = $spacefastPreview['prefix'] . 'options';
    $GLOBALS['wpdb']->query("CREATE TABLE IF NOT EXISTS `$spacefastOptions` (
        option_id bigint(20) unsigned NOT NULL auto_increment,
        option_name varchar(191) NOT NULL default '',
        option_value longtext NOT NULL,
        autoload varchar(20) NOT NULL default 'yes',
        PRIMARY KEY (option_id),
        UNIQUE KEY option_name (option_name),
        KEY autoload (autoload))");
    $GLOBALS['wpdb']->query("INSERT INTO `$spacefastOptions` (option_name, option_value, autoload) VALUES ('siteurl', 'http://localhost', 'on')");
    if ($GLOBALS['wpdb']->last_error !== '') throw new RuntimeException('Cannot prepare the SpacePress preview database: ' . $GLOBALS['wpdb']->last_error);
}
unset($spacefastPreview, $spacefastSqlite, $spacefastOptions, $spacefastSiteDb);
