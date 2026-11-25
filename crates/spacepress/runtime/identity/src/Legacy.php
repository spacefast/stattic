<?php

declare(strict_types=1);

namespace SpacePress\Identity;

/**
 * Identity's own storage as a pre-release build kept it, moved to the current names when this plugin
 * runs without the SpacePress runtime (a site that uses identity alone). With the runtime, the
 * runtime's move (SpacePress\Wp\BrandUpgrade) takes identity's share as part of the whole site's,
 * and this does nothing.
 *
 * Forward only, nothing deleted: the tables are renamed (accounts, sessions, passkeys, keys keep
 * every row), the options renamed, the maintenance and inventory events kept with their schedule.
 * The whole set is checked first: when any new name is taken, nothing changes.
 */
final class Legacy
{
    private const SHORT = 'tp_identity_';
    private const OPTIONS = ['schema_version', 'signing_keys', 'webhooks', 'dummy_password_hash', 'password_profiles', 'password_inventory'];
    private const EVENTS = ['maintenance', 'password_inventory'];
    /** Options identity writes on boot when it finds none: a random dummy hash and the cursor of its inventory. */
    private const REGENERATED = ['dummy_password_hash', 'password_profiles', 'password_inventory'];
    private const SCHEDULE = 'tp_identity_minute';
    /** Schema::migrateLocked()'s tables. */
    private const TABLES = ['accounts', 'emails', 'providers', 'sessions', 'challenges', 'applications', 'codes', 'tokens', 'events', 'limits', 'organizations', 'members', 'passkeys', 'mfa', 'recovery_codes', 'deliveries'];

    /** Identity's Two Factor provider as a WordPress sign-in a pre-release build made recorded it on its session. */
    public const TWO_FACTOR_KEY = 'TypedPress_Identity_TwoFactorProvider';

    /** A session secret's digest as a pre-release build stored it (Sessions::digest() under its domain). */
    public static function sessionDigest(string $secret): string
    {
        return hash("sha256", "typedpress-identity-session-v1\0" . $secret);
    }

    /** Recorded (autoloaded) once identity found no storage under the earlier name, or moved it. */
    private const SETTLED = 'sp_identity_upgrade';

    /**
     * Whether identity has nothing left under the earlier name: its own marker, or the runtime's once
     * it moved the whole site. Read from the autoloaded options, so a settled site sends no query.
     */
    public static function settled(): bool
    {
        $options = wp_load_alloptions();

        return isset($options[self::SETTLED]) || (int) ($options['sp_brand_upgrade'] ?? 0) >= 1;
    }

    /** Records that identity has nothing left to move. */
    public static function settle(): void
    {
        add_option(self::SETTLED, 1, '', true);
    }

    /** Whether identity's schema version is stored under the earlier name: one indexed read. */
    public static function stored(): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name = %s", self::SHORT . 'schema_version')) !== null;
    }

    /** Whether there is identity storage under the earlier name the runtime hasn't moved. */
    public static function pending(): bool
    {
        return (int) get_option('sp_brand_upgrade', 0) < 1 && self::stored();
    }

    /** Moves identity's storage when the runtime isn't here to move the whole site. */
    public static function move(\wpdb $wp): void
    {
        if (class_exists(\SpacePress\Wp\BrandUpgrade::class) || !self::pending()) {
            return;
        }
        $lock = 'spacepress:' . substr(hash('sha256', DB_NAME . ':' . $wp->prefix . ':sp_brand_upgrade'), 0, 40);
        // MySQL: a named lock. SQLite: a file lock beside the database, as the runtime takes one.
        $file = null;
        if ($wp->dbh instanceof \PDO) {
            $file = fopen((defined('DB_PATH') ? (string) DB_PATH : (string) (DB_DIR . '/' . DB_FILE)) . '.' . substr(hash('sha256', $lock), 0, 16) . '.lock', 'c');
            if ($file === false || !flock($file, LOCK_EX)) {
                return;
            }
        } elseif ((int) $wp->get_var($wp->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== 1) {
            return;
        }
        try {
            wp_cache_delete('sp_brand_upgrade', 'options');
            if (!self::pending()) {
                return;
            }
            $tables = [];
            foreach (self::TABLES as $name) {
                $from = $wp->prefix . self::SHORT . $name;
                $to = $wp->prefix . 'sp_identity_' . $name;
                if (self::table($wp, $from)) {
                    if (self::table($wp, $to)) {
                        throw new Failure('storage_unavailable', "Identity storage exists under both $from and $to; keep the one this site uses and move the other aside, then reload. Nothing was changed.", 503);
                    }
                    $tables[$from] = $to;
                }
            }
            foreach (self::OPTIONS as $name) {
                if ($wp->get_var($wp->prepare("SELECT 1 FROM {$wp->options} WHERE option_name = %s", 'sp_identity_' . $name)) === null
                    || $wp->get_var($wp->prepare("SELECT 1 FROM {$wp->options} WHERE option_name = %s", self::SHORT . $name)) === null) {
                    continue;
                }
                if (!in_array($name, self::REGENERATED, true)) {
                    throw new Failure('storage_unavailable', "Identity's option exists under both " . self::SHORT . "$name and sp_identity_$name; keep the one this site uses and move the other aside, then reload. Nothing was changed.", 503);
                }
            }
            // An event under the current hook at the same time and arguments is the same one only when
            // nothing else about it differs; otherwise moving would overwrite someone's schedule.
            $cron = _get_cron_array();
            foreach (self::events() as [$time, $hook, $key, $job]) {
                $there = $cron[$time][$hook][$key] ?? null;
                if ($there !== null && $there != $job) {
                    throw new Failure('storage_unavailable', "Identity's event $hook at $time is scheduled under both " . self::SHORT . substr($hook, strlen('sp_identity_')) . " and $hook, differently; keep the one this site uses and unschedule the other, then reload. Nothing was changed.", 503);
                }
            }
            foreach (self::REGENERATED as $name) {
                // Made by a boot that found nothing: the site's own value takes the name, that one is set aside.
                if ($wp->get_var($wp->prepare("SELECT 1 FROM {$wp->options} WHERE option_name = %s", self::SHORT . $name)) !== null) {
                    if ($wp->update($wp->options, ['option_name' => 'sp_identity_' . $name . '__superseded_' . time(), 'autoload' => 'off'], ['option_name' => 'sp_identity_' . $name]) === false) {
                        throw new Failure('storage_unavailable', "Cannot set identity's option sp_identity_$name aside: " . $wp->last_error, 503);
                    }
                }
            }
            if ($tables !== []) {
                if ($wp->dbh instanceof \PDO) {
                    $pdo = method_exists($wp->dbh, 'get_sqlite_pdo') ? $wp->dbh->get_sqlite_pdo() : $wp->dbh;
                    $pdo->beginTransaction();
                    try {
                        foreach ($tables as $from => $to) {
                            $pdo->exec('ALTER TABLE "' . $from . '" RENAME TO "' . $to . '"');
                            foreach (['tables', 'columns', 'statistics', 'table_constraints', 'key_column_usage'] as $meta) {
                                $pdo->prepare('UPDATE "_wp_sqlite_mysql_information_schema_' . $meta . '" SET TABLE_NAME = ? WHERE TABLE_NAME = ?')->execute([$to, $from]);
                            }
                            foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = " . $pdo->quote($to) . ' AND sql IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                                if (str_starts_with((string) $index['name'], $from . '__')) {
                                    $pdo->exec('DROP INDEX "' . $index['name'] . '"');
                                    $pdo->exec((string) preg_replace('/^(CREATE (?:UNIQUE )?INDEX )(?:`(?:[^`]|``)+`|"(?:[^"]|"")+")/', '$1"' . $to . substr((string) $index['name'], strlen($from)) . '"', (string) $index['sql'], 1));
                                }
                            }
                        }
                        $pdo->commit();
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        throw new Failure('storage_unavailable', 'Cannot move identity\'s tables: ' . $e->getMessage(), 503);
                    }
                } elseif ($wp->query('RENAME TABLE ' . implode(', ', array_map(static fn ($from, $to) => "`$from` TO `$to`", array_keys($tables), $tables))) === false) {
                    throw new Failure('storage_unavailable', 'Cannot move identity\'s tables: ' . $wp->last_error, 503);
                }
            }
            // Every option but the schema version first: the schema version under the earlier name is
            // what says identity still has storage to move, so it moves last, once the rest has.
            foreach ([...array_diff(self::OPTIONS, ['schema_version']), 'schema_version'] as $name) {
                if ($name === 'schema_version') {
                    foreach (self::TABLES as $table) {
                        if (self::table($wp, $wp->prefix . self::SHORT . $table)) {
                            throw new Failure('storage_unavailable', "Identity's table {$wp->prefix}" . self::SHORT . "$table did not move; reload to try again.", 503);
                        }
                    }
                    self::moveEvents();
                }
                if ($wp->update($wp->options, ['option_name' => 'sp_identity_' . $name], ['option_name' => self::SHORT . $name]) === false) {
                    throw new Failure('storage_unavailable', 'Cannot move identity\'s option ' . self::SHORT . $name . ': ' . $wp->last_error, 503);
                }
            }
            wp_cache_flush();
            self::settle();
        } finally {
            if ($file !== null) {
                flock($file, LOCK_UN);
                fclose($file);
            } else {
                $wp->query($wp->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * Identity's wp-cron events under their current hooks, with the same times, arguments and schedule.
     * A save that fails throws: the schema version moves after this, and once it has, nothing retries.
     */
    private static function moveEvents(): void
    {
        $cron = _get_cron_array();
        $events = self::events();
        foreach ($events as [$time, $hook, $key, $job]) {
            $cron[$time][$hook][$key] = $job;
            unset($cron[$time][self::SHORT . substr($hook, strlen('sp_identity_'))]);
        }
        if ($events !== [] && _set_cron_array($cron, true) instanceof \WP_Error) {
            throw new Failure('storage_unavailable', "Cannot move identity's scheduled events; reload to try again.", 503);
        }
    }

    /**
     * Each event under an earlier hook as it moves: its time, current hook, argument key and job.
     *
     * @return list<array{int, string, string, array}>
     */
    private static function events(): array
    {
        $cron = _get_cron_array();
        $events = [];
        foreach (is_array($cron) ? $cron : [] as $time => $hooks) {
            foreach (self::EVENTS as $event) {
                foreach ((array) ($hooks[self::SHORT . $event] ?? []) as $key => $job) {
                    $job['schedule'] = ($job['schedule'] ?? null) === self::SCHEDULE ? 'sp_identity_minute' : ($job['schedule'] ?? false);
                    $events[] = [$time, 'sp_identity_' . $event, $key, $job];
                }
            }
        }

        return $events;
    }

    private static function table(\wpdb $wp, string $name): bool
    {
        return $wp->dbh instanceof \PDO
            ? $wp->get_var($wp->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = %s", $name)) !== null
            : $wp->get_var($wp->prepare('SHOW TABLES LIKE %s', $wp->esc_like($name))) === $name;
    }
}
