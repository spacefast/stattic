<?php
declare(strict_types=1);
namespace Zero\Wp;

/** Platform policy shared with the compiler and doctor; fixture-guarded. */
final class Platform
{
    public const WORDPRESS = '7.1';
    public const PHP = '8.2';
    public const MYSQL = '8.0';
    public const MARIADB = '10.11';
    /** The SQLite Database Integration plugin (WordPress Playground, sites without a MySQL server). */
    public const SQLITE = '3.1.2';
    public const PLUGINS = [
        'secure-custom-fields' => ['version' => '6.9.7', 'sha256' => '5b10edab948f64b84d44d81a29fd5794a2573205e0d5e7ea9538024fc93ff52c'],
        'action-scheduler' => ['version' => '4.2.0', 'sha256' => 'a360712a5adb066a0d60db76cbe2768234731cbcc363ba7dc2d51bf4559e4551'],
        'mcp-adapter' => ['version' => '0.7.1', 'sha256' => '719d81aa1250615acfd8256f0acc8c13f1712f1ae398c3d5883c8b166024fe9b'],
        'polylang' => ['version' => '3.9', 'sha256' => '6a8b939d5209090e33600114f6f6dae03513688856514734118613c5147c68c2'],
    ];

    public static function requirements(): array
    {
        return ['wordpress' => self::WORDPRESS, 'php' => self::PHP, 'mysql' => self::MYSQL, 'mariadb' => self::MARIADB, 'sqlite' => self::SQLITE, 'plugins' => self::PLUGINS];
    }

    public static function versions(): array
    {
        global $wpdb;
        $server = (string) $wpdb->db_server_info();
        $maria = stripos($server, 'mariadb') !== false;
        preg_match('/(?:5\.5\.5-)?(\d+\.\d+(?:\.\d+)?)/', $server, $match);
        // The SQLite Database Integration plugin answers as MySQL 8; what matters is its own version.
        $sqlite = $wpdb->dbh instanceof \PDO;
        $headers = [];
        foreach (self::PLUGINS as $slug => $_) {
            $file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
            $headers[$slug] = is_file($file) ? get_file_data($file, ['version' => 'Version'])['version'] : null;
        }
        return [
            'version' => (string) get_bloginfo('version'), 'php' => PHP_VERSION,
            'database' => $sqlite
                ? ['kind' => 'sqlite', 'version' => defined('SQLITE_DRIVER_VERSION') ? (string) SQLITE_DRIVER_VERSION : '0']
                : ['kind' => $maria ? 'mariadb' : 'mysql', 'version' => $match[1] ?? '0'],
            'scf' => class_exists('ACF') && defined('ACF_VERSION') ? ACF_VERSION : null,
            // Action Scheduler registers its version on plugins_loaded. A plugin activated after that hook
            // fired (several activated in one WP-CLI call) never registers in that request, so the registry
            // is empty; read the plugin's header instead.
            'actionScheduler' => class_exists('ActionScheduler_Versions') ? (\ActionScheduler_Versions::instance()->latest_version() ?: $headers['action-scheduler']) : null,
            'mcpAdapter' => in_array('mcp-adapter/mcp-adapter.php', (array) get_option('active_plugins', []), true) || isset(get_site_option('active_sitewide_plugins', [])['mcp-adapter/mcp-adapter.php']) ? (defined('MCP_ADAPTER_VERSION') ? MCP_ADAPTER_VERSION : $headers['mcp-adapter']) : null,
            'polylang' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
            'identity' => Runtime::identity(), 'imageCdn' => function_exists('jetpack_photon_url'),
        ];
    }

    /** @return list<string> Every unmet platform row, rather than the first failure. */
    public static function problems(bool $localized = false): array
    {
        $versions = self::versions();
        $required = ['wordpress' => self::WORDPRESS, 'php' => self::PHP];
        foreach (self::PLUGINS as $slug => $spec) {
            if ($slug !== 'polylang' || $localized) {
                $required[$slug] = $spec['version'];
            }
        }
        $problems = [];
        foreach ($required as $name => $minimum) {
            $key = match ($name) { 'wordpress' => 'version', 'secure-custom-fields' => 'scf', 'action-scheduler' => 'actionScheduler', 'mcp-adapter' => 'mcpAdapter', default => $name };
            $version = $versions[$key];
            if ($version === null || version_compare($version, $minimum, '<')) {
                $problems[] = "$name $minimum+ is required (found " . ($version ?? 'inactive') . ')';
            }
        }
        $database = $versions['database'];
        $minimum = match ($database['kind']) { 'mariadb' => self::MARIADB, 'sqlite' => self::SQLITE, default => self::MYSQL };
        if (version_compare($database['version'], $minimum, '<')) {
            $problems[] = ($database['kind'] === 'sqlite' ? 'SQLite Database Integration' : $database['kind']) . " $minimum+ is required";
        }
        return $problems;
    }
}
