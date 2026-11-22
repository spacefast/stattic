<?php
declare(strict_types=1);
namespace TypedPress\Wp;

/** Platform policy shared with the compiler and doctor; fixture-guarded. */
final class Platform
{
    /**
     * The oldest WordPress TypedPress boots on: 7.1, the release Spacefast runs
     * (docs/decisions/2026-11-17-wordpress-7-1-floor.md). Where 7.2 adds an API (entity fields and
     * actions, the Secrets API, core's content abilities), TypedPress uses it when present. Compared
     * with PHP's version_compare against the whole version string, so 7.1.x, 7.2's pre-releases and
     * later pass, and 7.0.x and 7.1's own pre-releases don't.
     */
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
        'two-factor' => ['version' => '0.17.0', 'sha256' => '4d1b0f8e5d82e24876c2b4e86f119122e28a8dbaf76dfde495173226c3356f37'],
        'polylang' => ['version' => '3.9', 'sha256' => '6a8b939d5209090e33600114f6f6dae03513688856514734118613c5147c68c2'],
    ];
    /** versions() keys that differ from the plugin slug (also `runtime.wp` in the schema description). */
    private const KEYS = ['wordpress' => 'version', 'secure-custom-fields' => 'scf', 'action-scheduler' => 'actionScheduler', 'mcp-adapter' => 'mcpAdapter', 'two-factor' => 'twoFactor'];

    public static function requirements(): array
    {
        return ['wordpress' => self::WORDPRESS, 'php' => self::PHP, 'mysql' => self::MYSQL, 'mariadb' => self::MARIADB, 'sqlite' => self::SQLITE, 'plugins' => self::PLUGINS];
    }

    public static function versions(): array
    {
        return self::requiredVersions() + [
            // Not a platform requirement: spam checks use the Akismet a site sets up through Jetpack, and run
            // only while it has a key (Jetpack's connection or WPCOM_API_KEY). Without either they fail open.
            'akismet' => ['version' => defined('AKISMET_VERSION') ? AKISMET_VERSION : null, 'spamChecks' => self::spamChecks()],
            'identity' => Runtime::identity(), 'imageCdn' => function_exists('jetpack_photon_url'),
        ];
    }

    private static function requiredVersions(): array
    {
        global $wpdb;
        $server = (string) $wpdb->db_server_info();
        $maria = stripos($server, 'mariadb') !== false;
        preg_match('/(?:5\.5\.5-)?(\d+\.\d+(?:\.\d+)?)/', $server, $match);
        // The SQLite Database Integration plugin answers as MySQL 8; what matters is its own version.
        $sqlite = $wpdb->dbh instanceof \PDO;
        return [
            'version' => (string) get_bloginfo('version'), 'php' => PHP_VERSION,
            'database' => $sqlite
                ? ['kind' => 'sqlite', 'version' => defined('SQLITE_DRIVER_VERSION') ? (string) SQLITE_DRIVER_VERSION : '0']
                : ['kind' => $maria ? 'mariadb' : 'mysql', 'version' => $match[1] ?? '0'],
            'scf' => class_exists('ACF') && defined('ACF_VERSION') ? ACF_VERSION : null,
            // Action Scheduler registers its version on plugins_loaded. A plugin activated after that hook
            // fired (several activated in one WP-CLI call) never registers in that request, so the registry
            // is empty; read the plugin's header instead.
            'actionScheduler' => class_exists('ActionScheduler_Versions') ? (\ActionScheduler_Versions::instance()->latest_version() ?: self::headerVersion('action-scheduler')) : null,
            'mcpAdapter' => in_array('mcp-adapter/mcp-adapter.php', (array) get_option('active_plugins', []), true) || isset(get_site_option('active_sitewide_plugins', [])['mcp-adapter/mcp-adapter.php']) ? (defined('MCP_ADAPTER_VERSION') ? MCP_ADAPTER_VERSION : self::headerVersion('mcp-adapter')) : null,
            'twoFactor' => defined('TWO_FACTOR_VERSION') ? TWO_FACTOR_VERSION : null,
            'polylang' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
        ];
    }

    private static function headerVersion(string $slug): ?string
    {
        $file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';

        return is_file($file) ? get_file_data($file, ['version' => 'Version'])['version'] : null;
    }

    /** True when $version (a `$wp_version`) is at or above the floor. */
    public static function wordpressSupported(string $version): bool
    {
        return version_compare($version, self::WORDPRESS, '>=');
    }

    /** True when comment creates are spam-checked: Akismet is loaded and has an API key. */
    public static function spamChecks(): bool
    {
        return class_exists('Akismet') && method_exists('Akismet', 'get_api_key') && (string) \Akismet::get_api_key() !== '';
    }

    /** @return list<string> Every unmet platform row, rather than the first failure. */
    public static function problems(bool $localized = false): array
    {
        $versions = self::requiredVersions();
        $required = ['wordpress' => self::WORDPRESS, 'php' => self::PHP];
        foreach (self::PLUGINS as $slug => $spec) {
            if ($slug !== 'polylang' || $localized) {
                $required[$slug] = $spec['version'];
            }
        }
        $problems = [];
        foreach ($required as $name => $minimum) {
            $version = $versions[self::KEYS[$name] ?? $name];
            if ($version === null || version_compare($version, $minimum, '<')) {
                $problems[] = "$name $minimum+ is required (found " . ($version ?? 'inactive') . ')';
            }
        }
        // TypedPress calls Polylang's language model where 3.7 put it (PLL()->model->languages), not the
        // older names Polylang forwards for compatibility: a Polylang without it can't add a locale.
        if ($localized && $versions['polylang'] !== null && function_exists('PLL') && !Polylang::hasLanguageModel()) {
            $problems[] = 'polylang ' . self::PLUGINS['polylang']['version'] . '+ with PLL()->model->languages is required (found ' . $versions['polylang'] . ')';
        }
        $database = $versions['database'];
        $minimum = match ($database['kind']) { 'mariadb' => self::MARIADB, 'sqlite' => self::SQLITE, default => self::MYSQL };
        if (version_compare($database['version'], $minimum, '<')) {
            $problems[] = ($database['kind'] === 'sqlite' ? 'SQLite Database Integration' : $database['kind']) . " $minimum+ is required";
        }
        return $problems;
    }
}
