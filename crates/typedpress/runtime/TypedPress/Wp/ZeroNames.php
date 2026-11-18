<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * One-time upgrade of a site that ran a build from before the rename to TypedPress (compiler 0.3.0,
 * runtime series 0.3): every name it stored as `zero…` moves to TypedPress's name for the same thing.
 *
 * It runs once, on the first boot of the runtime on a site the 0.3 runtime ran on (see
 * `Runtime::boot` and `Loader::initialize`); on any other site it records that it ran and touches
 * nothing, under the site's migration lock, before TypedPress creates
 * any table or option of its own. Every step checks the database first and only renames: it never
 * drops a table, deletes a row or overwrites a value. A step that finds its TypedPress name already
 * taken stops the upgrade with the conflict named, so nothing is guessed. Re-running it after an
 * interruption picks up at the first step that has not happened yet; `tp_zero_names` records
 * completion.
 *
 * TypedPress Identity's own names (`{p}zero_identity_*` tables, `zero_identity_*` options, its WP-Cron
 * events) are renamed by `TypedPress\Identity\Legacy\ZeroNames` when that plugin boots, before its schema
 * step; this class leaves them alone, so either plugin can run without the other.
 *
 * What it renames (names as 0.3.0 wrote them; `{p}` is `$wpdb->prefix`):
 * - tables: `{p}zero_<table>` and `{p}zero__<system>` → `tp_…`;
 *   foreign keys `zero_<t>_<c>_fk` follow the table (MySQL keeps the constraint name, which no TypedPress
 *   code reads by name except schema-change plans, and those name the table, so they stay valid);
 * - post types `zero_<table>` → `tp_<table>` on posts and their revisions, with the SCF field
 *   references (`_<meta>` companions holding `field_zero_…`) and TypedPress's own post meta;
 * - post meta `zero_<col>` (and nested `zero_<col>_…`), `_zero_uid`, `_zero_system`, `_zero_focal`,
 *   `_zero_uploads`, `_zero_pages_*`; user meta `zero_<col>`, `zero_role`, and their SCF companions;
 * - options: `zero_<app>_schema`, `zero_<app>_refused`, `zero_runtime_*`,
 *   `zero_upload_namespace`, `zero_upload_cursor_*`, `zero_polylang_languages`, `zero_admin_rewrite`,
 *   `zero_pages_<app>` (+ `_error`), globals `zero_global_<name>_<field>` and their SCF companions;
 * - the `zero_guest` role, in `{p}user_roles` and in every user's `{p}capabilities`;
 * - stored job names (`zero.mail`, `zero.webhook`, `zero.services.*`) in the jobs and outbox tables (TypedPress no longer writes the outbox; its rows are kept);
 * - pending Action Scheduler actions and groups under `zero_…` / `zero.…` hooks.
 *
 * The upgrade is one-way: a 0.3 build can't read the renamed data, so a host must not roll a site back
 * to a 0.3 version once a TypedPress build has initialized on it.
 *
 * An editor collection whose TypedPress post type would exceed WordPress's 20 characters keeps
 * its posts under the old type, untouched; the upgrade records it in
 * `tp_zero_names` and every admin screen names it with the fix. The compiler refuses such a
 * collection name with the same fix, so no TypedPress build ever reads that type.
 *
 * Kept on purpose: `_zero_resource_id`, `zero_collection` and `_zero_<resource>_<field>` (Zero 0.8
 * storage the 0.8 migration reads), `_zero_legacy_member` (its marker), the `zero-sync-file` uid
 * hash input, the `app_zero_guest` client id, private file paths (`uploads/zero-private/<app>/…`
 * are moved by directory rename, see `files()`), and S3 object keys (the stored key is the address,
 * so the rows keep pointing at the objects they name). Sessions are carried: their stored hash is
 * re-keyed lazily on first use (`Identity\Legacy\ZeroSession`), never in bulk.
 */
final class ZeroNames
{
    public const DONE = 'tp_zero_names';

    /** Options renamed by exact name. */
    private const OPTIONS = [
        'zero_runtime_tables', 'zero_runtime_secret', 'zero_runtime_private_ok', 'zero_runtime_private_checked',
        'zero_runtime_site_id', 'zero_upload_namespace', 'zero_polylang_languages', 'zero_admin_rewrite',
    ];

    /** Option name prefixes renamed with whatever follows (per-app and per-global names). */
    private const OPTION_PREFIXES = ['zero_global_', '_zero_global_', 'zero_upload_cursor_', 'zero_pages_'];

    /** Post meta renamed by exact key (TypedPress's own bookkeeping). */
    private const POST_META = ['_zero_uid', '_zero_system', '_zero_focal', '_zero_uploads', '_zero_pages_app', '_zero_pages_route', '_zero_pages_digest', '_zero_pages_retired'];

    /** Stored job names: the jobs and outbox tables key handlers by them. */
    private const JOBS = ['zero.mail' => 'typedpress.mail', 'zero.webhook' => 'typedpress.webhook', 'zero.services.mail' => 'typedpress.services.mail', 'zero.services.checkout' => 'typedpress.services.checkout'];

    /** Action Scheduler hooks. */
    private const HOOKS = [
        'zero_job' => 'tp_job', 'zero_jobs_maintain' => 'tp_jobs_maintain', 'zero_uploads_maintain' => 'tp_uploads_maintain',
        'zero.prune-guests' => 'typedpress.prune-guests',
    ];

    /** Whether this site has not been checked yet: one cached option read once it has. */
    public static function pending(): bool
    {
        return get_option(self::DONE) === false;
    }

    /** Post types the upgrade had to leave under their old name (see the class comment). */
    public static function kept(): array
    {
        $done = get_option(self::DONE);

        return is_array($done) ? array_values(array_map('strval', $done['kept'] ?? [])) : [];
    }

    /**
     * Rename everything, holding the site's migration lock, then record that it ran. Returns what
     * changed, by kind; empty when another request finished first or the site never ran 0.3.
     *
     * @return array<string, int>
     */
    public static function upgrade(Connection $db): array
    {
        return Migrator::locked($db, self::DONE, static function () use ($db): array {
            wp_cache_delete(self::DONE, 'options');
            if (!self::pending()) {
                return [];
            }
            $upgrade = new self($db);
            // Only a site the 0.3 runtime ran on: it left its tables version option or its migration
            // ledger. Anywhere else (a fresh site, one that never ran TypedPress) another plugin's `zero_`
            // names are not ours, so nothing is touched.
            $changed = self::ranZero($db) ? $upgrade->run() : [];
            // Options and meta read before this moved under new names: forget every cached copy, and
            // reload the roles WordPress already holds in memory (a later add_role() would write the old
            // role list back over the renamed one).
            wp_cache_flush();
            if (function_exists('wp_roles')) {
                wp_roles()->for_site();
            }
            add_option(self::DONE, ['at' => gmdate('c'), 'changed' => $changed, 'kept' => $upgrade->kept], '', false);
            InstallMarker::forget();

            return $changed;
        });
    }

    /**
     * Where this site stands, read without writing: `done` once the upgrade recorded that it ran;
     * `pending` on a site the 0.3 runtime ran on and nothing has moved yet; `started` when it began and
     * did not finish (an interrupted run: some 0.3 names moved, its tables-version marker or its ledger
     * still under the old name, or TypedPress's names already there); null on a site 0.3 never ran on, where
     * the upgrade would only record that it checked.
     */
    public static function state(Connection $db): ?string
    {
        $options = $db->wpdb()->options;
        if ($db->run("SELECT 1 FROM `$options` WHERE option_name = ?", [self::DONE]) !== []) {
            return 'done';
        }
        if (!self::ranZero($db)) {
            return null;
        }
        $prefix = $db->prefix();
        // What a run renames first is the tables, then the options holding the 0.3 markers: a site where
        // a TypedPress table already sits beside a 0.3 marker was part way through.
        $moved = $db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND TABLE_NAME NOT LIKE ? LIMIT 1',
            [$db->wpdb()->esc_like($prefix . 'tp_') . '%', $db->wpdb()->esc_like($prefix . 'tp_identity_') . '%']) !== [];

        return $moved ? 'started' : 'pending';
    }

    private static function ranZero(Connection $db): bool
    {
        $options = $db->wpdb()->options;

        return $db->run("SELECT 1 FROM `$options` WHERE option_name IN ('zero_runtime_tables', 'zero_runtime_site_id') LIMIT 1") !== []
            || $db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefix() . 'zero__migrations']) !== [];
    }

    /** Post types left under their old name. @var list<string> */
    private array $kept = [];

    /** @var array<string, int> */
    private array $changed = [];

    private function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, int> */
    private function run(): array
    {
        $this->tables();
        $types = $this->postTypes();
        $this->postMeta($types);
        $this->userMeta();
        $this->options();
        $this->roles();
        $this->jobs();
        $this->actionScheduler();
        $this->files();

        return array_filter($this->changed);
    }

    /** Run one UPDATE and return how many rows it changed. */
    private function changed(string $sql, array $params = []): int
    {
        $this->db->run($sql, $params);

        return $this->db->changes();
    }

    private function count(string $kind, int $n): void
    {
        $this->changed[$kind] = ($this->changed[$kind] ?? 0) + $n;
    }

    /** Escaped LIKE pattern for a literal prefix. */
    private function like(string $prefix): string
    {
        return $this->db->wpdb()->esc_like($prefix) . '%';
    }

    // ------------------------------------------------------------------ tables

    private function tables(): void
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->run('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME', [$this->like($prefix . 'zero_')]);
        foreach (array_column($rows, 't') as $old) {
            if (str_starts_with((string) $old, $prefix . 'zero_identity_')) {
                continue; // TypedPress Identity's (Identity\Legacy\ZeroNames)
            }
            $new = $prefix . 'tp_' . substr((string) $old, strlen($prefix . 'zero_'));
            if (strlen($new) > 64) {
                throw new \RuntimeException("Cannot rename table $old to $new: MySQL table names are limited to 64 characters. Rename it by hand to a shorter TypedPress table name, then reload.");
            }
            if ($this->db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$new]) !== []) {
                throw new \RuntimeException("Cannot rename table $old: $new already exists. Compare the two tables, keep the one with the site's data under $new, then reload.");
            }
            $this->renameTable((string) $old, $new);
            $this->count('tables', 1);
        }
    }

    private function renameTable(string $old, string $new): void
    {
        $q = static fn (string $n): string => '`' . str_replace('`', '``', $n) . '`';
        if (!$this->db->sqlite()) {
            $this->db->run('RENAME TABLE ' . $q($old) . ' TO ' . $q($new));

            return;
        }
        // The SQLite driver has no RENAME TABLE (see Migrator::execute): copy, then drop the copy's source,
        // in one transaction, so the rows are under exactly one of the two names at every moment.
        $create = array_values($this->db->run('SHOW CREATE TABLE ' . $q($old))[0])[1];
        $dbh = $this->db->wpdb()->dbh;
        $dbh->query('START TRANSACTION');
        try {
            $dbh->query((string) preg_replace('/^CREATE TABLE `(?:[^`]|``)+`/', 'CREATE TABLE ' . $q($new), $create));
            $dbh->query('INSERT INTO ' . $q($new) . ' SELECT * FROM ' . $q($old));
            $dbh->query('DROP TABLE ' . $q($old));
            $dbh->query('COMMIT');
        } catch (\Throwable $e) {
            $dbh->query('ROLLBACK');
            throw $e;
        }
    }

    // ------------------------------------------------------------------ posts

    /** @return list<string> the TypedPress post types the site now has content under */
    private function postTypes(): array
    {
        $posts = $this->db->wpdb()->posts;
        $types = array_column($this->db->run("SELECT DISTINCT post_type AS t FROM `$posts` WHERE post_type LIKE ?", [$this->like('zero_')]), 't');
        $renamed = [];
        foreach ($types as $old) {
            $new = 'tp_' . substr((string) $old, 5);
            if (strlen($new) > 20) {
                // WordPress caps post types at 20 characters. The posts stay as they are under the old
                // type, never truncated into another collection's name (see the class comment).
                $this->kept[] = (string) $old;
                continue;
            }
            $n = $this->changed("UPDATE `$posts` SET post_type = ? WHERE post_type = ?", [$new, $old]);
            $this->count('posts', $n);
            $renamed[] = $new;
        }

        return $renamed;
    }

    /** @param list<string> $types */
    private function postMeta(array $types): void
    {
        $meta = $this->db->wpdb()->postmeta;
        foreach (self::POST_META as $old) {
            $this->count('postmeta', $this->changed("UPDATE `$meta` SET meta_key = ? WHERE meta_key = ?", ['_tp_' . substr($old, 6), $old]));
        }
        if ($types === [] && $this->db->run("SELECT 1 FROM `$meta` WHERE meta_key LIKE ? LIMIT 1", [$this->like('zero_')]) === []) {
            return;
        }
        $posts = $this->db->wpdb()->posts;
        // Field values (`zero_<col>`, nested `zero_<col>_<i>_<sub>`) and SCF's companions (`_zero_…`, whose
        // value is the field key `field_zero_<table>_…`), on TypedPress posts and their revisions only:
        // another plugin's meta key that happens to start with zero_ is not TypedPress's.
        $scope = "post_id IN (SELECT ID FROM `$posts` WHERE post_type LIKE 'tp\\_%' OR (post_type = 'revision' AND post_parent IN (SELECT ID FROM `$posts` WHERE post_type LIKE 'tp\\_%')))";
        $this->count('postmeta', $this->changed(
            "UPDATE `$meta` SET meta_key = CONCAT('tp_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE 'zero\\_%' AND $scope"
        ));
        $this->count('postmeta', $this->changed(
            "UPDATE `$meta` SET meta_key = CONCAT('_tp_', SUBSTRING(meta_key, 7)), meta_value = CONCAT('field_tp_', SUBSTRING(meta_value, 12)) WHERE meta_key LIKE '\\_zero\\_%' AND meta_value LIKE 'field\\_zero\\_%' AND $scope"
        ));
    }

    private function userMeta(): void
    {
        $meta = $this->db->wpdb()->usermeta;
        // Member fields (`zero_<col>`), the role ladder (`zero_role`) and their SCF companions
        // (`_zero_<col>` = `field_zero_member_<col>`). Keys TypedPress keeps for 0.8 (`_zero_legacy_member`) stay.
        $this->count('usermeta', $this->changed("UPDATE `$meta` SET meta_key = CONCAT('tp_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE 'zero\\_%'"));
        $this->count('usermeta', $this->changed(
            "UPDATE `$meta` SET meta_key = CONCAT('_tp_', SUBSTRING(meta_key, 7)), meta_value = CONCAT('field_tp_', SUBSTRING(meta_value, 12)) WHERE meta_key LIKE '\\_zero\\_%' AND meta_value LIKE 'field\\_zero\\_%'"
        ));
    }

    // ------------------------------------------------------------------ options

    private function options(): void
    {
        $options = $this->db->wpdb()->options;
        $names = self::OPTIONS;
        foreach ($this->db->run("SELECT option_name AS n FROM `$options` WHERE option_name LIKE 'zero\\_%\\_schema' OR option_name LIKE 'zero\\_%\\_refused'") as $row) {
            $names[] = (string) $row['n'];
        }
        foreach (self::OPTION_PREFIXES as $prefix) {
            foreach ($this->db->run("SELECT option_name AS n FROM `$options` WHERE option_name LIKE ?", [$this->like($prefix)]) as $row) {
                $names[] = (string) $row['n'];
            }
        }
        foreach (array_unique($names) as $old) {
            $new = str_starts_with($old, '_') ? '_tp_' . substr($old, 6) : 'tp_' . substr($old, 5);
            $taken = $this->db->run("SELECT 1 FROM `$options` WHERE option_name = ?", [$new]) !== [];
            $exists = $this->db->run("SELECT 1 FROM `$options` WHERE option_name = ?", [$old]) !== [];
            if (!$exists) {
                continue;
            }
            if ($taken) {
                throw new \RuntimeException("Cannot rename option $old: $new already exists. Keep the value the site should use under $new, delete $old by hand, then reload.");
            }
            $this->count('options', $this->changed("UPDATE `$options` SET option_name = ? WHERE option_name = ?", [$new, $old]));
        }
        // Globals' SCF companions hold field keys `field_zero_global_<name>_<field>`.
        $this->count('options', $this->changed(
            "UPDATE `$options` SET option_value = CONCAT('field_tp_', SUBSTRING(option_value, 12)) WHERE option_name LIKE '\\_tp\\_global\\_%' AND option_value LIKE 'field\\_zero\\_%'"
        ));
    }

    // ------------------------------------------------------------------ roles

    private function roles(): void
    {
        $prefix = $this->db->wpdb()->get_blog_prefix();
        $options = $this->db->wpdb()->options;
        $row = $this->db->run("SELECT option_value AS v FROM `$options` WHERE option_name = ?", [$prefix . 'user_roles'])[0] ?? null;
        $roles = $row === null ? null : maybe_unserialize((string) $row['v']);
        if (is_array($roles) && isset($roles['zero_guest'])) {
            if (isset($roles['tp_guest'])) {
                throw new \RuntimeException('Cannot rename role zero_guest: tp_guest already exists. Remove one of them by hand, then reload.');
            }
            $renamed = [];
            foreach ($roles as $name => $role) {
                $renamed[$name === 'zero_guest' ? 'tp_guest' : $name] = $role;
            }
            // Serialized data changes length with the name: unserialize, rename the key, serialize again.
            $this->db->run("UPDATE `$options` SET option_value = ? WHERE option_name = ?", [serialize($renamed), $prefix . 'user_roles']);
            $this->count('roles', 1);
        }
        $meta = $this->db->wpdb()->usermeta;
        foreach ($this->db->run("SELECT umeta_id AS id, meta_value AS v FROM `$meta` WHERE meta_key = ? AND meta_value LIKE ?", [$prefix . 'capabilities', '%"zero\\_guest"%']) as $row) {
            $caps = maybe_unserialize((string) $row['v']);
            if (!is_array($caps) || !array_key_exists('zero_guest', $caps)) {
                continue;
            }
            $renamed = [];
            foreach ($caps as $name => $granted) {
                $renamed[$name === 'zero_guest' ? 'tp_guest' : $name] = $granted;
            }
            $this->db->run("UPDATE `$meta` SET meta_value = ? WHERE umeta_id = ?", [serialize($renamed), (int) $row['id']]);
            $this->count('usermeta', 1);
        }
    }

    // ------------------------------------------------------------------ jobs and schedules

    private function jobs(): void
    {
        $prefix = $this->db->prefix();
        foreach (['tp__jobs', 'tp__outbox'] as $table) {
            if ($this->db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$prefix . $table]) === []) {
                continue;
            }
            foreach (self::JOBS as $old => $new) {
                $this->count('jobs', $this->changed("UPDATE `$prefix$table` SET `job` = ? WHERE `job` = ?", [$new, $old]));
            }
        }
    }

    private function actionScheduler(): void
    {
        $prefix = $this->db->prefix();
        $actions = $prefix . 'actionscheduler_actions';
        $groups = $prefix . 'actionscheduler_groups';
        if ($this->db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$actions]) === []) {
            return;
        }
        foreach (self::HOOKS as $old => $new) {
            $this->count('actions', $this->changed("UPDATE `$actions` SET hook = ? WHERE hook = ?", [$new, $old]));
        }
        // Groups `zero` (runtime maintenance) and `zero-<app>` (job deliveries), unless TypedPress's exists.
        foreach ($this->db->run("SELECT group_id AS id, slug FROM `$groups` WHERE slug = 'zero' OR slug LIKE 'zero-%'") as $row) {
            $new = 'typedpress' . substr((string) $row['slug'], 4);
            $target = $this->db->run("SELECT group_id AS id FROM `$groups` WHERE slug = ?", [$new])[0]['id'] ?? null;
            if ($target === null) {
                $this->db->run("UPDATE `$groups` SET slug = ? WHERE group_id = ?", [$new, (int) $row['id']]);
            } else {
                // Both exist: point the old group's actions at TypedPress's group; the old group row stays, empty.
                $this->db->run("UPDATE `$actions` SET group_id = ? WHERE group_id = ?", [(int) $target, (int) $row['id']]);
            }
            $this->count('actionGroups', 1);
        }
    }

    // ------------------------------------------------------------------ files

    /** Private files: `uploads/zero-private/` becomes `uploads/typedpress-private/` (rows store paths below it). */
    private function files(): void
    {
        $old = WP_CONTENT_DIR . '/uploads/zero-private';
        $new = WpFiles::root();
        if (!is_dir($old)) {
            return;
        }
        if (file_exists($new)) {
            throw new \RuntimeException("Cannot move $old: $new already exists. Move the files of each app from one into the other by hand, then reload.");
        }
        rename($old, $new) || throw new \RuntimeException("Cannot move $old to $new");
        $this->count('files', 1);
    }
}
