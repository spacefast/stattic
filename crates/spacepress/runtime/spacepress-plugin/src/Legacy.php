<?php
declare(strict_types=1);

namespace SpacePressPlugin;

/**
 * What a site deployed with the pre-release deploy plugin keeps, moved to this plugin's names before
 * the plugin reads or writes any of it, and the earlier credential restrictions honoured until the
 * runtime has moved the rest of the site (SpacePress\Wp\BrandUpgrade).
 *
 * The deploy plugin's own state (the store key and the store directory named by it, the live build,
 * the history, pending deployments) is this plugin's alone, so it moves here, on `plugins_loaded`
 * priority 0, under a MySQL named lock: once, in place, nothing deleted. The history is never pruned
 * and keeps every entry as written. The store directory is renamed, not copied: the builds in it are
 * named by the hash of their bytes and stay valid.
 */
final class Legacy
{
    private const LEGACY = 'typedpress';
    /** Option names the earlier deploy plugin wrote => this plugin's. */
    private const OPTIONS = ['tp_store_key' => Site::KEY, 'tp_live_build' => Site::LIVE, 'tp_deployments' => Site::HISTORY, 'tp_pending_deployments' => Site::PENDING];
    /** The key an application password's scope had on its entry. */
    public const SCOPE = 'tp_scope';
    /** Where identity kept accounts before the move. */
    private const ACCOUNTS = 'tp_identity_accounts';
    private const DONE = 'sp_deploy_upgrade';
    /** The manifest format of builds a pre-release receiver stored. */
    public const BUILD_FORMAT = 'typedpress.build.v1';

    /** Whether a stored build is one a pre-release receiver took: kept as a record, never mounted. */
    public static function build(array $manifest): bool
    {
        return ($manifest['format'] ?? null) === self::BUILD_FORMAT;
    }

    /** An app's name as this plugin's builds name it: one named for the pre-release brand has the current one. */
    public static function appName(string $name): string
    {
        return str_starts_with($name, self::LEGACY . '-') ? 'spacepress' . substr($name, strlen(self::LEGACY)) : $name;
    }

    /** Why the deploy plugin's earlier state didn't move this request, while it hasn't. */
    private static ?string $problem = null;

    public static function register(): void
    {
        add_action('plugins_loaded', [self::class, 'move'], 0);
        // Until the earlier state has moved, a deployment would start a store and history beside it.
        add_filter('sp_deploy_closed', static fn (?string $reason): ?string => $reason ?? self::$problem);
    }

    /** Moves the deploy plugin's state once; a site with none records that and changes nothing. */
    public static function move(): void
    {
        if ((int) get_option(self::DONE, 0) === 1) {
            return;
        }
        global $wpdb;
        $lock = 'spacepress-deploy:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
        $file = null;
        if ($wpdb->dbh instanceof \PDO) {
            $file = fopen((defined('DB_PATH') ? (string) DB_PATH : (string) (DB_DIR . '/' . DB_FILE)) . '.' . substr(hash('sha256', $lock), 0, 16) . '.lock', 'c');
            $held = $file !== false && flock($file, LOCK_EX);
        } else {
            $held = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) === 1;
        }
        if (!$held) {
            self::$problem = 'SpacePress is moving this site\'s deployments to their current names. Try again in a moment.';

            return;
        }
        try {
            wp_cache_delete(self::DONE, 'options');
            if ((int) get_option(self::DONE, 0) === 1) {
                return;
            }
            $key = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'tp_store_key'));
            if (is_string($key) && preg_match('/\A[0-9a-f]{32}\z/D', $key) === 1 && !(defined('SP_STORE_DIR') && is_string(SP_STORE_DIR) && SP_STORE_DIR !== '')) {
                $from = WP_CONTENT_DIR . '/' . self::LEGACY . '-store-' . $key;
                $to = WP_CONTENT_DIR . '/spacepress-store-' . $key;
                if (is_dir($from) && !is_link($from)) {
                    if (file_exists($to) && !self::empty($to)) {
                        throw new \RuntimeException("Both $from and $to exist; keep the one this site deploys from and move the other aside, then reload.");
                    }
                    if (is_dir($to)) {
                        rmdir($to);
                    }
                    if (!rename($from, $to)) {
                        throw new \RuntimeException("Cannot move $from to $to");
                    }
                }
            }
            foreach (self::OPTIONS as $old => $new) {
                $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $old));
                if ($value === null) {
                    continue;
                }
                // The earlier value is the site's. One under the new name since this plugin was installed
                // is kept beside it, set aside, never deleted.
                if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name = %s", $new)) !== null) {
                    $wpdb->update($wpdb->options, ['option_name' => $new . '__superseded_' . time(), 'autoload' => 'off'], ['option_name' => $new]);
                }
                if ($wpdb->update($wpdb->options, ['option_name' => $new], ['option_name' => $old]) === false) {
                    throw new \RuntimeException("Cannot move the option $old: " . $wpdb->last_error);
                }
                wp_cache_delete($old, 'options');
                wp_cache_delete($new, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            update_option(self::DONE, 1, true);
        } catch (\Throwable $error) {
            error_log('[spacepress] ' . $error->getMessage());
            self::$problem = 'SpacePress could not move this site\'s deployments to their current names, so it takes no deployment until it has: ' . $error->getMessage();
        } finally {
            if ($file) {
                flock($file, LOCK_UN);
                fclose($file);
            } elseif (!($wpdb->dbh instanceof \PDO)) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * Whether the site has finished moving its stored names. Until then an account's status may sit
     * in the earlier identity table.
     */
    public static function settled(): bool
    {
        $done = get_option('sp_brand_upgrade', 0);

        return $done !== 'started' && (int) $done >= 1;
    }

    /**
     * Whether the application password carries a scope under the earlier key. Checked after the site's
     * move too: user meta is a network's, and another site on it may still run the pre-release plugin
     * and write the earlier key.
     */
    public static function scoped(array $password): bool
    {
        return array_key_exists(self::SCOPE, $password);
    }

    /** The earlier identity table, while the site hasn't moved it. */
    public static function accounts(): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . self::ACCOUNTS;

        return !self::settled() && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table ? $table : null;
    }

    private static function empty(string $dir): bool
    {
        return is_dir($dir) && !is_link($dir) && array_diff(scandir($dir) ?: [], ['.', '..']) === [];
    }
}
