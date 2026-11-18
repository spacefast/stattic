<?php

declare(strict_types=1);
namespace TypedPress\Identity\Legacy;

use TypedPress\Identity\DB;

/**
 * One-time upgrade of TypedPress Identity's own names on a site that ran a build from before the rename
 * to TypedPress (`zero-identity`, series 0.3): its tables `{prefix}zero_identity_<name>`, its options
 * `zero_identity_*`, and its pending WP-Cron events. It runs from `Plugin::boot`, before the schema
 * step would create empty `tp_identity_*` tables, under the same named lock as that step. It only
 * renames; a TypedPress name that already exists stops it with the conflict named. On a site zero-identity
 * never ran on it records that it ran and touches nothing. Sessions keep working
 * through `ZeroSession`. `tp_identity_zero_names` records that it ran. The runtime's names are
 * `TypedPress\Wp\ZeroNames`'s.
 */
final class ZeroNames
{
    public const DONE = 'tp_identity_zero_names';

    private const OPTIONS = [
        'zero_identity_schema_version', 'zero_identity_webhooks', 'zero_identity_signing_keys',
        'zero_identity_dummy_password_hash', 'zero_identity_password_profiles', 'zero_identity_password_inventory',
    ];

    private const HOOKS = [
        'zero_identity_maintenance' => 'tp_identity_maintenance',
        'zero_identity_password_inventory' => 'tp_identity_password_inventory',
    ];

    /** The recurrence name identity registers (`cron_schedules`), stored with each recurring event. */
    private const SCHEDULES = ['zero_identity_minute' => 'tp_identity_minute'];

    public static function upgrade(DB $db): void
    {
        if (get_option(self::DONE) !== false) {
            return;
        }
        $wp = $db->wp;
        $lock = 'typedpress-identity-zero-names:' . substr(hash('sha256', DB_NAME . ':' . $wp->prefix), 0, 32);
        if ((int) $wp->get_var($wp->prepare('SELECT GET_LOCK(%s, 30)', $lock)) !== 1) {
            throw new \RuntimeException('Another request is upgrading TypedPress Identity; try again shortly');
        }
        try {
            wp_cache_delete(self::DONE, 'options');
            if (get_option(self::DONE) !== false) {
                return;
            }
            // Only a site zero-identity ran on (its schema version option); elsewhere touch nothing.
            $ran = $wp->get_var($wp->prepare("SELECT 1 FROM `{$wp->options}` WHERE option_name = %s", 'zero_identity_schema_version')) !== null;
            $changed = $ran ? self::tables($wp) + self::options($wp) + self::cron() : [];
            wp_cache_flush();
            add_option(self::DONE, ['at' => gmdate('c'), 'changed' => array_filter($changed)], '', false);
        } finally {
            $wp->query($wp->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Where this site stands, read without writing, as {@see \TypedPress\Wp\ZeroNames::state()} says it:
     * `done`, `pending` (zero-identity ran here and nothing moved yet), `started` (an interrupted run:
     * some of its tables already under TypedPress's name beside its schema-version marker), or null where
     * zero-identity never ran.
     */
    public static function state(\wpdb $wp): ?string
    {
        if ($wp->get_var($wp->prepare("SELECT 1 FROM `{$wp->options}` WHERE option_name = %s", self::DONE)) !== null) {
            return 'done';
        }
        if ($wp->get_var($wp->prepare("SELECT 1 FROM `{$wp->options}` WHERE option_name = %s", 'zero_identity_schema_version')) === null) {
            return null;
        }
        $moved = $wp->get_var($wp->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s LIMIT 1', $wp->esc_like($wp->prefix . 'tp_identity_') . '%')) !== null;

        return $moved ? 'started' : 'pending';
    }

    /** @return array{tables: int} */
    private static function tables(\wpdb $wp): array
    {
        $old = $wp->prefix . 'zero_identity_';
        $names = $wp->get_col($wp->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $wp->esc_like($old) . '%'));
        $n = 0;
        foreach ($names as $table) {
            $new = $wp->prefix . 'tp_identity_' . substr((string) $table, strlen($old));
            if ($wp->get_var($wp->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $new)) !== null) {
                throw new \RuntimeException("Cannot rename table $table: $new already exists. Keep the one with the site's accounts under $new, then reload.");
            }
            self::rename($wp, (string) $table, $new);
            $n++;
        }

        return ['tables' => $n];
    }

    private static function rename(\wpdb $wp, string $old, string $new): void
    {
        if (!$wp->dbh instanceof \PDO) {
            if ($wp->query("RENAME TABLE `$old` TO `$new`") === false) {
                throw new \RuntimeException("Cannot rename table $old to $new: " . $wp->last_error);
            }

            return;
        }
        // The SQLite driver has no RENAME TABLE: copy the table under its new name, then drop the
        // old one, in one transaction (as TypedPress\Wp\ZeroNames and the Migrator do).
        $create = (string) $wp->get_row("SHOW CREATE TABLE `$old`", ARRAY_N)[1];
        $wp->query('START TRANSACTION');
        $ok = $wp->query((string) preg_replace('/^CREATE TABLE `(?:[^`]|``)+`/', "CREATE TABLE `$new`", $create)) !== false
            && $wp->query("INSERT INTO `$new` SELECT * FROM `$old`") !== false
            && $wp->query("DROP TABLE `$old`") !== false;
        $wp->query($ok ? 'COMMIT' : 'ROLLBACK');
        if (!$ok) {
            throw new \RuntimeException("Cannot rename table $old to $new: " . $wp->last_error);
        }
    }

    /** @return array{options: int} */
    private static function options(\wpdb $wp): array
    {
        $n = 0;
        foreach (self::OPTIONS as $old) {
            $new = 'tp_' . substr($old, 5);
            if ($wp->get_var($wp->prepare("SELECT 1 FROM `{$wp->options}` WHERE option_name = %s", $old)) === null) {
                continue;
            }
            if ($wp->get_var($wp->prepare("SELECT 1 FROM `{$wp->options}` WHERE option_name = %s", $new)) !== null) {
                throw new \RuntimeException("Cannot rename option $old: $new already exists. Keep the value the site should use under $new, delete $old by hand, then reload.");
            }
            $n += (int) $wp->query($wp->prepare("UPDATE `{$wp->options}` SET option_name = %s WHERE option_name = %s", $new, $old));
        }

        return ['options' => $n];
    }

    /** Pending WP-Cron events live in one serialized option: re-keyed in PHP, never by string replace. */
    private static function cron(): array
    {
        $cron = _get_cron_array();
        $n = 0;
        foreach (is_array($cron) ? $cron : [] as $at => $hooks) {
            foreach (self::HOOKS as $old => $new) {
                if (!is_array($hooks) || !isset($hooks[$old])) {
                    continue;
                }
                foreach ($hooks[$old] as $key => $event) {
                    if (isset($event['schedule'], self::SCHEDULES[$event['schedule']])) {
                        $event['schedule'] = self::SCHEDULES[$event['schedule']];
                    }
                    $cron[$at][$new][$key] ??= $event;
                    $n++;
                }
                unset($cron[$at][$old]);
            }
        }
        if ($n > 0 && _set_cron_array($cron, true) !== true) {
            throw new \RuntimeException('Cannot store the renamed WP-Cron events');
        }

        return ['cron' => $n];
    }
}
