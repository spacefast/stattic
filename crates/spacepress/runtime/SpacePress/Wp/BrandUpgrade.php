<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * Moves what a site stored under SpacePress's pre-release name onto the current names, once, in place.
 *
 * Until the rename the runtime stored its state as `typedpress…` and `tp_…`: tables, options, meta
 * keys, post types, taxonomies, roles and capabilities, Action Scheduler hooks and groups, wp-cron
 * events, the private-files directory and plugin basenames. This class holds the runtime's list of
 * those spellings (identity and the deploy plugin keep the few they read before the runtime is
 * there, in their own Legacy classes), and it names each one exactly: the runtime's own names
 * ({@see RUNTIME_OPTIONS} and friends) and the names it derives from each app's schema
 * ({@see plan()}). A `tp_` name the runtime never wrote (another plugin's option) is left alone.
 *
 * Forward only, and nothing is deleted. Tables are renamed, not recreated, so the migration ledger
 * keeps each applied file's recorded SHA-256, the revisions table its chain, and every row its ids.
 * The ledger gains one `data` row per app saying what moved. A site whose config sets no
 * `uploads.prefix` keeps the S3 key prefix its objects were stored under ({@see uploadPrefix()}).
 *
 * The whole plan is computed and checked before anything changes: if any new name is already taken
 * by something else, nothing is renamed, and the site reports which name to look at. Runs under the
 * migration lock, before anything reads storage: when a plugin-installed site's app registers
 * ({@see Runtime::app()}, on `plugins_loaded` or its activation) and when the host initializes a
 * managed build. Until then the runtime and identity install nothing on such a site, so no empty
 * table is created beside a stored one. A site with no legacy state changes nothing; running it
 * again changes nothing.
 */
final class BrandUpgrade
{
    public const DONE = 'sp_brand_upgrade';
    public const VERSION = 1;
    /** Where files stored before the rename stay: S3 key names and the local private root ({@see uploadKeys()}). */
    public const UPLOAD_KEYS = 'sp_upload_keys';

    private const LEGACY = 'typedpress';
    private const LEGACY_UPLOAD_PREFIX = 'typedpress';

    /**
     * Options a request before the move may write under the current name from its own state, with no
     * input of the site's: a derived cache (rewrite fingerprint, probe verdict, jobs prepared) or a
     * random value made because none was found (the runtime secret, identity's dummy hash). The
     * site's stored value takes the name; the row the request wrote is kept beside it, as
     * `<name>__superseded_<time>`, never deleted. Any other option under both names stops the move.
     */
    private const REGENERATED = [
        'tp_runtime_secret', 'tp_runtime_private_ok', 'tp_runtime_private_checked', 'tp_rewrite_rules',
        'tp_jobs_prepared', 'tp_jobs_scheduled', 'tp_search_shape', 'tp_kind_urls', 'tp_installed',
        'tp_identity_dummy_password_hash', 'tp_identity_password_profiles', 'tp_identity_password_inventory',
    ];
    /** Options with fixed names (runtime, deploy plugin, identity, admin). */
    private const RUNTIME_OPTIONS = [
        'tp_runtime_tables', 'tp_installed', 'tp_runtime_site_id', 'tp_runtime_secret', 'tp_runtime_private_ok',
        'tp_runtime_private_checked', 'tp_jobs_prepared', 'tp_jobs_scheduled', 'tp_rewrite_rules', 'tp_kind_urls',
        'tp_polylang_languages', 'tp_upload_namespace', 'tp_search_shape', 'tp_schema_apply_granted',
        'tp_sitemap_pages', 'tp_public_symlinks', 'tp_prebuilt_id', 'tp_prebuilt_stale', 'tp_prebuilt_forgotten',
        'tp_git_dispatch_repository', 'tp_live_build', 'tp_deployments', 'tp_pending_deployments', 'tp_store_key',
        'tp_identity_schema_version', 'tp_identity_signing_keys', 'tp_identity_webhooks',
        'tp_identity_dummy_password_hash', 'tp_identity_password_profiles', 'tp_identity_password_inventory',
        'tp_admin_rewrite',
    ];
    /** Options named `<prefix><hash>`, by the exact shape of the hash: per build, per upload prefix. */
    private const RUNTIME_OPTION_SHAPES = ['tp_prebuilt_' => '/\A[0-9a-f]{16}\z/', 'tp_upload_cursor_' => '/\A[0-9a-f]{64}\z/'];
    /** Options named `tp_<app><suffix>`. */
    private const APP_OPTION_SUFFIXES = [
        '_schema', '_refused', '_post_types', '_adopted_posts', '_seed', '_seed_refused', '_shipping', '_content',
        '_route_pages', '_runtime_schema', '_declared_fields', '_schema_apply_denied',
    ];
    /** Transients by prefix and the exact shape of the rest (`_transient_` and `_transient_timeout_` rows), and site transients. */
    private const TRANSIENT_SHAPES = ['tp_setup_notice_' => '/\A[0-9]+\z/', 'tp_api_reference_' => '/\A[0-9a-f]{32}\z/'];
    private const SITE_TRANSIENTS = ['tp_admin_prev_update_plugins'];
    /** System tables (below `{prefix}`); identity's are `tp_identity_<name>`. */
    private const SYSTEM_TABLES = ['tp__migrations', 'tp__revisions', 'tp__jobs', 'tp__receipts', 'tp__versions', 'tp__files', 'tp__rates', 'tp__app_reads', 'tp__search', 'tp__schema_versions'];
    private const IDENTITY_TABLES = ['accounts', 'emails', 'providers', 'sessions', 'challenges', 'applications', 'codes', 'tokens', 'events', 'limits', 'organizations', 'members', 'passkeys', 'mfa', 'recovery_codes', 'deliveries'];
    /** Meta keys the runtime writes on posts, terms and users; app field values are `tp_<field>` ({@see plan()}). */
    private const META_KEYS = [
        '_tp_uid', '_tp_created_at', '_tp_updated_at', '_tp_deleted_at', '_tp_deleted_by', '_tp_deleted_group',
        '_tp_rev_meta', '_tp_rev_seq', '_tp_system', '_tp_staged', '_tp_route', '_tp_code_page', '_tp_focal',
        '_tp_uploads', 'tp_role', 'tp_admin_developer_mode', 'tp_excerpt',
    ];
    private const META_PREFIXES = ['_tp_coediting_schema', '_tp_field_'];
    /** Action Scheduler and wp-cron hooks, and runtime job names in `{prefix}sp__jobs`. */
    private const HOOKS = ['tp_job', 'tp_jobs_maintain', 'tp_job_schedule', 'tp_prebuild', 'tp_uploads_maintain', 'typedpress.prune-guests', 'tp_identity_maintenance', 'tp_identity_password_inventory'];
    private const CRON_SCHEDULES = ['tp_identity_minute' => 'sp_identity_minute'];
    private const JOBS = ['typedpress.mail', 'typedpress.connector', 'typedpress.webhook', 'typedpress.git-dispatch', 'typedpress.search.reindex'];
    private const ROLES = ['tp_guest' => 'sp_guest'];
    private const CAPABILITIES = ['tp_apply_schema' => 'sp_apply_schema'];
    private const POST_STATUSES = ['typedpress-staged' => 'spacepress-staged'];
    private const PAGE_SLUGS = ['typedpress-home' => 'spacepress-home'];
    /** The runtime's block templates by slug, and its blocks, as an owner's saved copy of one names them. */
    private const TEMPLATE_SLUGS = ['tp-page' => 'sp-page', 'tp-blank' => 'sp-blank', 'tp-login' => 'sp-login'];
    private const BLOCKS = ['<!-- wp:tp/page ' => '<!-- wp:sp/page ', '<!-- wp:tp/login-form ' => '<!-- wp:sp/login-form '];
    private const GUEST_CLIENT = ['app_typedpress_guest' => 'app_spacepress_guest'];
    private const TWO_FACTOR = ['TypedPress_Identity_TwoFactorProvider' => 'SpacePress_Identity_TwoFactorProvider'];
    /** The local private root a pre-release build kept under uploads. */
    private const LEGACY_PRIVATE_DIR = 'typedpress-private';
    /** The key of an application password's scope on its entry. */
    private const SCOPE_KEY = 'tp_scope';

    /** `sp_brand_upgrade`: the version once the move finished, `started` while one is under way. */
    private const STARTED = 'started';

    /** True while this request runs the move, under its lock. */
    private static bool $moving = false;

    public static function done(): bool
    {
        $value = get_option(self::DONE, 0);

        return $value !== self::STARTED && (int) $value >= self::VERSION;
    }

    /**
     * Whether this site holds state under the pre-release name at all: the runtime's tables version
     * option, which every installed site has. One indexed read; false on a new site.
     */
    public static function pending(): bool
    {
        // Settled sites answer from the autoloaded marker: no query.
        $marker = get_option(self::DONE, 0);
        if ($marker === self::STARTED) {
            // A move that started and stopped part way is still pending: the next request resumes it.
            return true;
        }
        if ((int) $marker >= self::VERSION) {
            return false;
        }
        if (self::legacyState()) {
            return true;
        }
        // Inside the move itself (it holds the lock and writes the marker), there is nothing to decide.
        if (self::$moving) {
            return true;
        }
        // No state under the earlier name (a site installed after the rename): record that once,
        // under the lock and from a fresh read, so a move another request just started is never
        // marked finished. Later requests answer from the autoloaded marker.
        global $wpdb;
        // The answer is the database's, read under the lock: this request's cache may predate a
        // move another request started.
        $pending = (bool) Migrator::locked(new Connection($wpdb), 'sp_brand_upgrade', static function () use ($wpdb): bool {
            $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::DONE));
            if ($stored === null && !self::legacyState()) {
                add_option(self::DONE, self::VERSION, '', true);

                return false;
            }

            return $stored === null || $stored === self::STARTED || (int) $stored < self::VERSION;
        });
        wp_cache_delete(self::DONE, 'options');
        wp_cache_delete('alloptions', 'options');

        return $pending;
    }

    /** Whether the database holds what a pre-release runtime or identity wrote: one indexed read. */
    private static function legacyState(): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name IN (%s, %s) LIMIT 1", 'tp_runtime_tables', 'tp_identity_schema_version')) !== null;
    }

    /**
     * Runs the move once under the migration lock, for the app `$schema` describes (the build being
     * loaded: its storage names are the current ones, each the old one with `sp_` for `tp_`). The app
     * is needed: its tables, post types and fields are named from it, so until one registers, the
     * runtime installs nothing on a site that still holds the earlier names ({@see pending()}). Returns
     * what it renamed, by kind; empty when nothing was left or it already ran. Throws when the plan
     * conflicts, leaving everything as it was.
     *
     * @return array<string, int>
     */
    public static function ensure(\SpacePress\Schema $schema): array
    {
        if (self::done()) {
            return [];
        }
        global $wpdb;
        $db = new Connection($wpdb);
        // On a network, users, their meta and the network options are the sites' shared rows: one
        // site's move at a time, under a lock of the network's (its base prefix), then this site's own.
        $network = is_multisite() ? 'spacepress-network:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->base_prefix . ':' . get_current_network_id()), 0, 40) : null;
        $site = static fn (callable $fn) => $network === null ? $fn() : $db->locked($network, 30, $fn);
        // What moved comes back through both locks as the inner function's result.
        return (array) $site(static fn () => Migrator::locked($db, 'sp_brand_upgrade', static function () use ($db, $schema): array {
            // Under the lock, from the database: another request may have finished, or started, since.
            $stored = $db->wpdb()->get_var($db->wpdb()->prepare("SELECT option_value FROM {$db->wpdb()->options} WHERE option_name = %s", self::DONE));
            wp_cache_delete(self::DONE, 'options');
            wp_cache_delete('alloptions', 'options');
            if ($stored !== null && $stored !== self::STARTED && (int) $stored >= self::VERSION) {
                return [];
            }
            $plan = self::plan($db, self::withRuntimeKinds($schema));
            // A resumed move finds the names it already moved under both spellings only where it left
            // them half done; each step below is safe to run again, so only a first attempt checks.
            if ($stored !== self::STARTED) {
                $conflicts = self::conflicts($db, $plan);
                if ($conflicts !== []) {
                    throw new \RuntimeException('SpacePress cannot move this site\'s stored names: ' . implode('; ', $conflicts) . '. Nothing was changed. Each of these exists under both its earlier name and its current one; keep the one this site uses and rename or export the other, then reload.');
                }
                update_option(self::DONE, self::STARTED, true);
            }
            self::$moving = true;
            try {
                $moved = self::apply($db, ['resumed' => $stored === self::STARTED] + $plan);
            } finally {
                self::$moving = false;
            }
            update_option(self::DONE, self::VERSION, true);
            InstallMarker::forget();

            return $moved;
        }));
    }

    /**
     * An application password's scope as a pre-release build stored it, while it may still be there:
     * before the move, or on a network where another site's move hasn't run yet (the passwords are
     * user meta, which the sites share). Ability names in its allowlist are read as they are now.
     * Null when the entry has none. A stored null was the narrowest scope then (normalize() of a
     * non-array) and stays so: it comes back as `false`, which normalize() narrows the same way.
     */
    public static function earlierScope(array $entry): mixed
    {
        if (!array_key_exists(self::SCOPE_KEY, $entry)) {
            return null;
        }
        $scope = $entry[self::SCOPE_KEY];
        if (is_array($scope) && is_array($scope['abilities'] ?? null)) {
            $scope['abilities'] = array_map(static fn ($name) => is_string($name) ? self::abilityName($name) : $name, $scope['abilities']);
        }

        return $scope ?? false;
    }

    /** The earlier name of an app renamed with the brand, under which its schema versions were written; else the app. */
    public static function historyApp(string $app): string
    {
        return str_starts_with($app, 'spacepress-') ? self::earlierApp($app) : $app;
    }

    /** The name an app had under the pre-release brand: `spacepress-site` was `typedpress-site`. */
    private static function earlierApp(string $app): string
    {
        return str_starts_with($app, 'spacepress-') ? self::LEGACY . substr($app, strlen('spacepress')) : $app;
    }

    /**
     * An application password entry given a new scope (or none), as a pre-release build reads it too.
     * On a network the entry is shared with sites that may not have moved yet and read only the
     * earlier key, so every scope written there is written under both keys (ability names under their
     * earlier spelling), and none clears both. Elsewhere the earlier key is dropped.
     */
    public static function withEarlierScope(array $entry, ?array $scope): array
    {
        unset($entry[self::SCOPE_KEY]);
        if ($scope !== null && is_multisite()) {
            if (is_array($scope['abilities'] ?? null)) {
                $scope['abilities'] = array_map(static fn ($name) => is_string($name) ? self::earlierAbilityName($name) : $name, $scope['abilities']);
            }
            $entry[self::SCOPE_KEY] = $scope;
        }

        return $entry;
    }

    /**
     * The narrower of a scope and the one under the earlier key on the same entry: on a network a site
     * not yet moved may have narrowed the earlier one only. Each grant holds only when both give it.
     *
     * @param array{write: bool, personal: bool, abilities: ?list<string>} $current
     * @param array{write: bool, personal: bool, abilities: ?list<string>} $earlier
     * @return array{write: bool, personal: bool, abilities: ?list<string>}
     */
    public static function narrower(array $current, array $earlier): array
    {
        // A name, or a `<namespace>/*` pattern, holds when the other list grants it too (exactly, or by a
        // pattern that covers it): the abilities both lists let a request run, as AgentScope reads them.
        $covers = static function (string $name, array $list): bool {
            foreach ($list as $pattern) {
                if ($pattern === $name || (str_ends_with($pattern, '/*') && strlen($pattern) > 2 && str_starts_with($name, substr($pattern, 0, -1)))) {
                    return true;
                }
            }

            return false;
        };
        $abilities = match (true) {
            $current['abilities'] === null => $earlier['abilities'],
            $earlier['abilities'] === null => $current['abilities'],
            default => array_values(array_unique([
                ...array_filter($current['abilities'], static fn (string $n): bool => $covers($n, $earlier['abilities'])),
                ...array_filter($earlier['abilities'], static fn (string $n): bool => $covers($n, $current['abilities'])),
            ])),
        };

        return ['write' => $current['write'] && $earlier['write'], 'personal' => $current['personal'] && $earlier['personal'], 'abilities' => $abilities];
    }

    /** A built-in ability's name as a pre-release build knew it (the inverse of abilityName()). */
    private static function earlierAbilityName(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'spacepress-') || str_starts_with($name, 'spacepress/') => self::LEGACY . substr($name, strlen('spacepress')),
            str_starts_with($name, 'sp/') => 'tp/' . substr($name, 3),
            default => $name,
        };
    }

    /** A built-in ability's current name: `typedpress-identity/account` is `spacepress-identity/account`, `tp/…` is `sp/…`. */
    private static function abilityName(string $name): string
    {
        return match (true) {
            str_starts_with($name, self::LEGACY . '-') || str_starts_with($name, self::LEGACY . '/') => 'spacepress' . substr($name, strlen(self::LEGACY)),
            str_starts_with($name, 'tp/') => 'sp/' . substr($name, 3),
            default => $name,
        };
    }

    /**
     * The app's schema with the kinds and fields its site added at runtime (`runtimeSchema: true`),
     * read from the stored state under whichever name it is in now: their posts, terms and fields are
     * the site's too and move with the rest. A state not written by apply under either key adds nothing.
     */
    private static function withRuntimeKinds(\SpacePress\Schema $schema): \SpacePress\Schema
    {
        $raw = $schema->raw();
        if (!isset($raw['runtimeSchema'])) {
            return $schema;
        }
        $app = $schema->name();
        foreach ([\SpacePress\RuntimeSchema\Loader::option($app), 'tp_' . self::earlierApp($app) . '_runtime_schema'] as $option) {
            $state = get_option($option);
            if (!is_array($state)) {
                continue;
            }
            $current = \SpacePress\RuntimeSchema\Loader::sealedBy($state) ? $state : \SpacePress\RuntimeSchema\Loader::reseal($state, self::LEGACY . '-runtime-schema:');
            if ($current !== null) {
                return new \SpacePress\Schema(\SpacePress\RuntimeSchema\Loader::apply($raw, $current));
            }
        }

        return $schema;
    }

    /**
     * Where a site's files stored before the rename stay, as they were written, in one autoloaded
     * option written whole (so a resumed move never finds half of it), present only on a site that
     * stored any; every other site reads nothing for it:
     *   prefix, apps: the S3 key prefix (when the config sets no `uploads.prefix`) and the earlier
     *     name of an app renamed with the brand, which its keys are derived from;
     *   private, privateApps: the local private root under uploads and the earlier app directory in it.
     *
     * A compatibility exception, kept on purpose, as the S3 keys are: the local private root stays at
     * its earlier path rather than moving under another URL, so the owner's files keep the deny rules
     * and server config that protect them (their own rules, every host of a network), and nothing is
     * copied or moved. A site installed since uses `spacepress-private`.
     */
    private static function uploadKeys(): array
    {
        $kept = wp_load_alloptions()[self::UPLOAD_KEYS] ?? null;
        $kept = is_string($kept) ? maybe_unserialize($kept) : $kept;

        return is_array($kept) ? $kept : [];
    }

    /** Whether a stored `sp_upload_keys` row is exactly `$keys`, autoloaded. */
    private static function uploadKeysAre(array $row, array $keys): bool
    {
        return maybe_unserialize((string) $row['option_value']) === $keys && in_array($row['autoload'], ['on', 'yes', 'auto-on'], true);
    }

    /** The local private root a pre-release build made, kept where it is; null on a site that had none. */
    public static function privateDir(): ?string
    {
        // Only the one directory a pre-release build made: nothing else this option holds names a path.
        return (self::uploadKeys()['private'] ?? null) === self::LEGACY_PRIVATE_DIR ? self::LEGACY_PRIVATE_DIR : null;
    }

    /** The directory an app's local private files are under: the earlier name, for an app renamed while it had some. */
    public static function privateApp(string $app): string
    {
        $apps = self::uploadKeys()['privateApps'] ?? [];

        // Only the app's earlier name, as the rename made it: never a path elsewhere.
        return ($apps[$app] ?? null) === self::earlierApp($app) ? self::earlierApp($app) : $app;
    }

    /** The app name its S3 keys are derived from: the earlier one, for an app the rename renamed. */
    public static function uploadApp(string $app): string
    {
        $apps = self::uploadKeys()['apps'] ?? [];

        return is_string($apps[$app] ?? null) ? $apps[$app] : $app;
    }

    /** The S3 key prefix uploads use when the app's config sets none. */
    public static function uploadPrefix(): string
    {
        $prefix = self::uploadKeys()['prefix'] ?? null;

        return is_string($prefix) && $prefix !== '' ? $prefix : 'spacepress';
    }

    // ================================================================ the plan

    /**
     * Every rename this site needs, as exact old => new names, read without changing anything.
     *
     * @return array{tables: array<string, string>, options: array<string, string>, sitemeta: array<string, string>, meta: array<string, array<string, string>>, metaValues: array<string, string>, postTypes: array<string, string>, taxonomies: array<string, string>, hooks: array<string, string>, groups: array<string, string>, jobs: array<string, string>, plugins: array<string, string>, keys: array<string, mixed>, apps: list<string>}
     */
    public static function plan(Connection $db, \SpacePress\Schema $schema): array
    {
        $wpdb = $db->wpdb();
        $prefix = $db->prefix();
        $old = static fn (string $current): string => 'tp_' . substr($current, 3);

        $names = self::SYSTEM_TABLES;
        foreach (self::IDENTITY_TABLES as $table) {
            $names[] = 'tp_identity_' . $table;
        }
        $apps = [];
        $postTypes = [];
        $taxonomies = [];
        $fields = [];
        $fieldKeys = [];
        $fieldKeyPrefixes = [];
        $apps[] = $schema->name();
        foreach (self::appStorage($schema) as $entry) {
            [$kind, $current] = $entry;
            match ($kind) {
                'table' => $names[] = $old($current),
                'postType' => $postTypes[$old($current)] = $current,
                'taxonomy' => $taxonomies[$old($current)] = $current,
                // By owner: `$fields[table][object type ('' for users)][old] = new`.
                'field' => $fields[$entry[2]][(string) $entry[3]][$old($current)] = $current,
                'fieldKey' => $fieldKeys['field_tp_' . substr($current, 9)] = $current,
                'fieldKeyPrefix' => $fieldKeyPrefixes[] = 'field_tp_' . substr($current, 9),
            };
        }
        // An app whose name carried the pre-release brand (`typedpress-site`) is named `spacepress-site`
        // now; what its site keyed by the name moves to the new one.
        $renamedApp = null;
        if (str_starts_with($schema->name(), 'spacepress-')) {
            $renamedApp = [self::LEGACY . substr($schema->name(), strlen('spacepress')) => $schema->name()];
        }
        foreach ([$prefix . 'tp__migrations', $prefix . 'sp__migrations'] as $ledger) {
            if (self::existingTables($db, $ledger, exact: true) !== []) {
                foreach (array_column($db->run("SELECT DISTINCT `app` FROM `$ledger`"), 'app') as $app) {
                    $apps[] = $renamedApp[(string) $app] ?? (string) $app;
                }
            }
        }
        $apps = array_values(array_unique($apps));
        $earlierApps = array_merge(...array_map(static fn (string $a): array => [$a => $a], $apps));
        foreach ((array) $renamedApp as $from => $to) {
            $earlierApps[$to] = $from;
        }
        $tables = [];
        $owned = [];
        foreach (array_unique($names) as $name) {
            $owned[] = $prefix . 'sp_' . substr($name, 3);
            if (self::existingTables($db, $prefix . $name, exact: true) !== []) {
                $tables[$prefix . $name] = $prefix . 'sp_' . substr($name, 3);
            }
        }

        $options = [];
        foreach (self::RUNTIME_OPTIONS as $name) {
            $options[$name] = 'sp_' . substr($name, 3);
        }
        foreach ($apps as $app) {
            foreach (self::APP_OPTION_SUFFIXES as $suffix) {
                $options['tp_' . $earlierApps[$app] . $suffix] = 'sp_' . $app . $suffix;
            }
        }
        foreach (self::RUNTIME_OPTION_SHAPES as $head => $shape) {
            foreach (self::keys($db, $wpdb->options, 'option_name', $head) as $name) {
                if (preg_match($shape, substr($name, strlen($head))) === 1) {
                    $options[$name] = 'sp_' . substr($name, 3);
                }
            }
        }
        // A global's field values (and SCF's `_` reference beside each): `tp_global_<global>_<field>`.
        $globalRefs = [];
        foreach ($schema->globals() as $global => $spec) {
            foreach (array_keys((array) ($spec['fields'] ?? $spec['columns'] ?? [])) as $field) {
                $options["tp_global_{$global}_{$field}"] = "sp_global_{$global}_{$field}";
                $options["_tp_global_{$global}_{$field}"] = "_sp_global_{$global}_{$field}";
            }
            // SCF's reference options under the global, nested sub-fields included (`_tp_global_<g>_<f>_0_<sub>`).
            foreach ($db->run("SELECT `option_name` AS n, `option_value` AS v FROM `{$wpdb->options}` WHERE `option_name` LIKE ? AND `option_value` LIKE ?",
                [addcslashes("_tp_global_{$global}_", '\\_%') . '%', addcslashes("field_tp_global_{$global}_", '\\_%') . '%']) as $row) {
                $ref = (string) $row['n'];
                $options[$ref] = '_sp_' . substr($ref, 4);
                $options[substr($ref, 1)] = 'sp_' . substr($ref, 4);
                $globalRefs[(string) $row['v']] = 'field_sp_' . substr((string) $row['v'], 9);
            }
        }
        foreach (self::TRANSIENT_SHAPES as $head => $shape) {
            foreach (['_transient_', '_transient_timeout_'] as $kind) {
                foreach (self::keys($db, $wpdb->options, 'option_name', $kind . $head) as $name) {
                    if (preg_match($shape, substr($name, strlen($kind . $head))) === 1) {
                        $options[$name] = $kind . 'sp_' . substr($name, strlen($kind) + 3);
                    }
                }
            }
        }
        $sitemeta = [];
        foreach (self::SITE_TRANSIENTS as $name) {
            foreach (['_site_transient_', '_site_transient_timeout_'] as $kind) {
                $options[$kind . $name] = $kind . 'sp_' . substr($name, 3);
                $sitemeta[$kind . $name] = $kind . 'sp_' . substr($name, 3);
            }
        }
        $options = self::present($db, $wpdb->options, 'option_name', $options);
        // Every kind the schema names, for capability names on roles: a kind with no posts yet (or
        // whose posts a stopped attempt already moved) still has its caps granted.
        $kinds = $postTypes + $taxonomies;
        $postTypes = self::present($db, $wpdb->posts, 'post_type', $postTypes);
        $taxonomies = self::present($db, $wpdb->term_taxonomy, 'taxonomy', $taxonomies);
        $sitemeta = is_multisite() ? self::present($db, $wpdb->sitemeta, 'meta_key', $sitemeta, '`site_id` = ' . (int) get_current_network_id()) : [];

        $metaKeys = [];
        foreach (self::META_KEYS as $key) {
            $metaKeys[$key] = str_starts_with($key, '_') ? '_sp_' . substr($key, 4) : 'sp_' . substr($key, 3);
        }
        // The app's field values, and SCF's reference row beside each holding the field key: each moved
        // on the objects of the kind that declares it only ({@see fieldScope()}), as another plugin (or
        // another of the app's kinds) may use the same key.
        $fieldsOn = [];
        foreach ($fields as $table => $byType) {
            foreach ($byType as $type => $map) {
                $keys = [];
                foreach ($map as $from => $to) {
                    $keys[$from] = $to;
                    $keys['_' . $from] = '_' . $to;
                }
                $keys = self::present($db, $wpdb->$table, 'meta_key', $keys, self::fieldScope($wpdb, $table, (string) $type));
                $keys !== [] && $fieldsOn[] = [$table, (string) $type, $keys];
            }
        }
        $meta = [];
        // Every SCF field key a reference row holds under the app's prefixes, nested ones included, on
        // a reference row of either spelling (a stopped attempt may have moved the row, not its value).
        foreach (['postmeta', 'termmeta', 'usermeta'] as $table) {
            foreach ([...$fieldKeyPrefixes, 'field_tp_member_'] as $head) {
                foreach ($db->run("SELECT DISTINCT `meta_value` AS v FROM `{$wpdb->$table}` WHERE (`meta_key` LIKE '\\_tp\\_%' OR `meta_key` LIKE '\\_sp\\_%') AND `meta_value` LIKE ?", [addcslashes($head, '\\_%') . '%']) as $row) {
                    $fieldKeys[(string) $row['v']] = 'field_sp_' . substr((string) $row['v'], 9);
                }
            }
        }
        foreach (['postmeta', 'termmeta', 'usermeta'] as $table) {
            $found = $metaKeys;
            foreach (self::META_PREFIXES as $head) {
                foreach (self::keys($db, $wpdb->$table, 'meta_key', $head) as $key) {
                    $found[$key] = '_sp_' . substr($key, 4);
                }
            }
            $meta[$table] = self::present($db, $wpdb->$table, 'meta_key', $found);
        }

        $hooks = [];
        foreach (self::HOOKS as $hook) {
            $hooks[$hook] = str_starts_with($hook, self::LEGACY) ? 'spacepress' . substr($hook, strlen(self::LEGACY)) : 'sp_' . substr($hook, 3);
        }
        $groups = [self::LEGACY => 'spacepress', self::LEGACY . '-app' => 'spacepress-app'];
        foreach ($apps as $app) {
            $groups[self::LEGACY . '-' . $earlierApps[$app]] = 'spacepress-' . $app;
        }
        $jobs = [];
        foreach (self::JOBS as $job) {
            $jobs[$job] = 'spacepress' . substr($job, strlen(self::LEGACY));
        }

        $plugins = [];
        foreach (self::activePluginList() as $basename) {
            $to = self::pluginBasename($basename, (array) $renamedApp);
            if ($to !== $basename && is_file(WP_PLUGIN_DIR . '/' . $to)) {
                $plugins[$basename] = $to;
            }
        }
        // Where the files this site stored stay ({@see uploadKeys()}): S3 objects under the prefix and
        // app name their keys were derived from; the local private root, and a renamed app's directory
        // in it, where they are.
        // Only a site that ran a pre-release build here has any: on a network the uploads directory is
        // shared, and a site added since (whose own options hold nothing of the earlier runtime's)
        // must not take another site's private root as its own.
        $keys = [];
        if (isset($options['tp_upload_namespace'])) {
            $keys += ['prefix' => self::LEGACY_UPLOAD_PREFIX, 'apps' => array_flip($renamedApp ?? [])];
        }
        $root = WP_CONTENT_DIR . '/uploads/' . self::LEGACY_PRIVATE_DIR;
        // A link to a directory (a persistent volume, say) is the root as the earlier build used it.
        if (is_dir($root) && ($tables !== [] || $options !== [] || get_option(self::DONE) === self::STARTED)) {
            $keys['private'] = self::LEGACY_PRIVATE_DIR;
            $privateApps = array_filter(array_flip($renamedApp ?? []), static fn (string $earlier): bool => is_dir("$root/$earlier"));
            $privateApps !== [] && $keys['privateApps'] = $privateApps;
        }

        return ['scfPrefixes' => [...$fieldKeyPrefixes, 'field_tp_member_'], 'kinds' => $kinds, 'renamedApp' => $renamedApp ?? [], 'globalRefs' => $globalRefs, 'tables' => $tables, 'owned' => $owned, 'options' => $options, 'sitemeta' => $sitemeta, 'meta' => $meta, 'fieldMeta' => $fieldsOn, 'metaValues' => $fieldKeys,
            'postTypes' => $postTypes, 'taxonomies' => $taxonomies, 'hooks' => $hooks, 'groups' => $groups, 'jobs' => $jobs,
            'plugins' => $plugins, 'keys' => $keys, 'apps' => $apps];
    }

    /**
     * The objects of one of the app's kinds in a meta table, as SQL on its object column: posts of a
     * post type, terms of a taxonomy (under either spelling, for a resumed move); users (null) for
     * the auth collection's members.
     */
    private static function fieldScope(\wpdb $wpdb, string $table, string $type): ?string
    {
        if ($table === 'usermeta') {
            return null;
        }
        $names = array_unique([$type, str_starts_with($type, 'sp_') ? 'tp_' . substr($type, 3) : $type]);
        $in = implode(', ', array_map(static fn (string $n): string => "'" . esc_sql($n) . "'", $names));

        return $table === 'postmeta'
            ? "`post_id` IN (SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_type` IN ($in))"
            : "`term_id` IN (SELECT `term_id` FROM `{$wpdb->term_taxonomy}` WHERE `taxonomy` IN ($in))";
    }

    /**
     * What the app's schema names in storage, by its current names: `[kind, name]` for its tables, post
     * types, taxonomies, field meta keys and SCF field keys.
     *
     * @return list<array{0: string, 1: string, 2?: string, 3?: ?string}>
     */
    private static function appStorage(\SpacePress\Schema $schema): array
    {
        $out = [];
        foreach ([...$schema->kinds(), ...$schema->globals()] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $wp = is_array($spec['wp'] ?? null) ? $spec['wp'] : [];
            if (is_string($spec['table'] ?? null) && str_starts_with($spec['table'], 'sp_')) {
                $out[] = ['table', $spec['table']];
            }
            foreach (['postType', 'taxonomy'] as $kind) {
                if (is_string($wp[$kind] ?? null) && str_starts_with($wp[$kind], 'sp_')) {
                    $out[] = [$kind, $wp[$kind]];
                }
            }
            if (is_string($wp['fieldKeyPrefix'] ?? null) && str_starts_with($wp['fieldKeyPrefix'], 'field_sp_')) {
                $out[] = ['fieldKeyPrefix', $wp['fieldKeyPrefix']];
            }
            $member = ($schema->auth()['collection'] ?? null) !== null && ($spec['key'] ?? null) === $schema->auth()['collection'];
            foreach ((array) ($spec['columns'] ?? $spec['fields'] ?? []) as $column => $def) {
                // Post, term and user meta: `sp_<field>` (an auth collection's members keep theirs on the user).
                if (($wp['metaPrefix'] ?? null) === 'sp_' || $member) {
                    // `[table, object type]`: the members' fields are on users, a kind's on its posts or
                    // terms; only the columns each store keeps in meta (not a core column: a user's
                    // email, a post's title, a term's name, a many-ref's join rows).
                    $owner = match (true) {
                        $member => in_array($column, [$spec['primaryKey'] ?? null, 'email', 'name', 'role', 'guest', 'passwordHash', 'suspendedAt'], true) ? null : ['usermeta', null],
                        // WpPostStore::isMeta(): not a wp_posts column, a system column or a many-ref's join rows.
                        is_string($wp['postType'] ?? null) => !in_array($column, [$spec['primaryKey'] ?? null, $spec['title'] ?? null, $spec['content'] ?? null, $spec['slug'] ?? null, $spec['excerpt'] ?? null], true)
                            && !(is_array($def) && ($def['system'] ?? false)) && (is_array($def) ? ($def['type'] ?? null) : null) !== 'refList' ? ['postmeta', $wp['postType']] : null,
                        is_string($wp['taxonomy'] ?? null) => WpTermStore::isMeta($spec, (string) $column) ? ['termmeta', $wp['taxonomy']] : null,
                        default => null,
                    };
                    $owner !== null && $out[] = ['field', 'sp_' . $column, ...$owner];
                }
                if (is_string($wp['fieldKeyPrefix'] ?? null) && str_starts_with($wp['fieldKeyPrefix'], 'field_sp_')) {
                    $out[] = ['fieldKey', $wp['fieldKeyPrefix'] . $column];
                }
                if ($member) {
                    $out[] = ['fieldKey', 'field_sp_member_' . $column];
                }
                $join = is_array($def) ? ($def['references']['join'] ?? null) : null;
                if (is_string($join) && !str_starts_with($join, 'term:')) {
                    $out[] = ['table', 'sp__join_' . $join];
                }
            }
        }

        return $out;
    }

    /**
     * SCF's own rows of a field tree, object by object: a meta row and its reference row (`_<key>`),
     * where the reference names one of the app's field keys (`field_tp_<kind>_…`). Covers what a
     * schema can't list by name: a group's and a repeater's sub-field rows (`tp_<field>_<n>_<sub>`)
     * and flexible layouts. Another object's row of the same spelling, or another plugin's, has no
     * such reference beside it and stays as it is.
     *
     * @param list<string> $fieldKeyPrefixes `field_tp_<kind>_`, one per kind
     * @return int rows moved
     */
    private static function scfPairs(Connection $db, string $table, array $fieldKeyPrefixes, ?array &$taken = null): int
    {
        $wpdb = $db->wpdb();
        $object = ['postmeta' => 'post_id', 'termmeta' => 'term_id', 'usermeta' => 'user_id'][$table];
        $id = $table === 'usermeta' ? 'umeta_id' : 'meta_id';
        $table = $wpdb->$table;
        $count = 0;
        // Each reference row of one object, under either spelling (a resumed move finds the pairs it
        // left half done), with the app's field key under either spelling too.
        $refs = [];
        foreach ($fieldKeyPrefixes as $head) {
            foreach ([$head, 'field_sp_' . substr($head, 9)] as $value) {
                foreach ($db->run("SELECT `$id` AS id, `$object` AS o, `meta_key` AS k FROM `$table` WHERE (`meta_key` LIKE '\\_tp\\_%' OR `meta_key` LIKE '\\_sp\\_%') AND `meta_value` LIKE ?", [addcslashes($value, '\\_%') . '%']) as $row) {
                    $refs[(int) $row['id']] = $row;
                }
            }
        }
        // Every row of the objects concerned, by object and key, in one read per batch of objects:
        // a set, so each pair is checked and moved without a query of its own.
        $have = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['o'], $refs))), 500) as $objects) {
            $marks = implode(', ', array_fill(0, count($objects), '?'));
            foreach ($db->run("SELECT `$object` AS o, `meta_key` AS k FROM `$table` WHERE `$object` IN ($marks) AND (`meta_key` LIKE 'tp\\_%' OR `meta_key` LIKE 'sp\\_%' OR `meta_key` LIKE '\\_tp\\_%' OR `meta_key` LIKE '\\_sp\\_%')", $objects) as $row) {
                // Keyed as the database compares meta keys (case- and accent-insensitive collations).
                $have[(int) $row['o']][strtolower(remove_accents((string) $row['k']))] = true;
            }
        }
        foreach ($refs as $row) {
            $o = (int) $row['o'];
            $rest = substr((string) $row['k'], 4);
            // The pair moves together, on that object only: its reference row and its value row.
            foreach (['_tp_' . $rest => '_sp_' . $rest, 'tp_' . $rest => 'sp_' . $rest] as $from => $to) {
                $f = strtolower(remove_accents($from));
                $t = strtolower(remove_accents($to));
                if (!isset($have[$o][$f])) {
                    continue;
                }
                if (isset($have[$o][$t])) {
                    if ($taken !== null) {
                        $taken[] = "$table key $to on object $o (and $from)";
                        continue;
                    }
                    throw new \RuntimeException("$table: object $o holds both $from and $to; keep the one it uses and remove the other, then reload.");
                }
                if ($taken !== null) {
                    continue;
                }
                $db->run("UPDATE `$table` SET `meta_key` = ? WHERE `$object` = ? AND `meta_key` = ?", [$to, $o, $from]);
                unset($have[$o][$f]);
                $have[$o][$t] = true;
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every new name that is already taken: a table, option, meta key, post type, taxonomy or
     * directory present under both spellings.
     *
     * @return list<string>
     */
    public static function conflicts(Connection $db, array $plan): array
    {
        $wpdb = $db->wpdb();
        $found = [];
        foreach ($plan['tables'] as $from => $to) {
            self::existingTables($db, $to, exact: true) !== [] && $found[] = "table $to (and $from)";
        }
        $regenerated = array_map(static fn (string $o): string => 'sp_' . substr($o, 3), self::REGENERATED);
        foreach (self::taken($db, $wpdb->options, 'option_name', $plan['options']) as $name) {
            in_array($name, $regenerated, true) || $found[] = "option $name";
        }
        foreach ($plan['meta'] as $table => $keys) {
            foreach (self::taken($db, $wpdb->$table, 'meta_key', $keys) as $key) {
                $found[] = "$table key $key";
            }
        }
        // A field moves on each object of the kind that declares it: taken only where one of those
        // objects holds both spellings (the same predicate as the move).
        foreach ($plan['fieldMeta'] ?? [] as [$table, $type, $keys]) {
            $object = ['postmeta' => 'post_id', 'termmeta' => 'term_id', 'usermeta' => 'user_id'][$table];
            $scope = self::fieldScope($wpdb, $table, $type);
            foreach ($keys as $from => $to) {
                foreach ($db->run("SELECT DISTINCT a.`$object` AS o FROM `{$wpdb->$table}` a JOIN `{$wpdb->$table}` b ON b.`$object` = a.`$object` AND b.`meta_key` = ? WHERE a.`meta_key` = ?" . ($scope === null ? '' : ' AND a.' . $scope), [$to, $from]) as $row) {
                    $found[] = "$table key $to on object {$row['o']} (and $from)";
                }
            }
        }
        $taken = [];
        self::cron($plan['hooks'] ?? [], $taken);
        array_push($found, ...$taken);
        // SCF's pairs, object by object, checked as scfPairs() would move them, without moving any.
        foreach (['postmeta', 'termmeta', 'usermeta'] as $table) {
            $taken = [];
            self::scfPairs($db, $table, $plan['scfPrefixes'] ?? [], $taken);
            array_push($found, ...$taken);
        }
        if (is_multisite()) {
            foreach (self::taken($db, $wpdb->sitemeta, 'meta_key', $plan['sitemeta'], '`site_id` = ' . (int) get_current_network_id()) as $key) {
                $found[] = "network option $key";
            }
        }
        foreach (self::taken($db, $wpdb->posts, 'post_type', $plan['postTypes']) as $type) {
            $found[] = "post type $type";
        }
        foreach (self::taken($db, $wpdb->term_taxonomy, 'taxonomy', $plan['taxonomies']) as $taxonomy) {
            $found[] = "taxonomy $taxonomy";
        }
        // The guest OAuth client, once identity's tables have moved: its new id may be taken already.
        // The fallback theme's saves: the earlier theme's and the current one's both present means the
        // active theme switch would hide one set.
        [$theme, $current] = [self::LEGACY . '-default', Hardening::THEME];
        $term = static fn (string $slug): bool => $db->run("SELECT 1 AS x FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` x ON x.`term_id` = t.`term_id` WHERE x.`taxonomy` = 'wp_theme' AND t.`slug` = ?", [$slug]) !== [];
        if ($term($theme) && $term($current)) {
            $found[] = "saved templates of the theme $current (and $theme)";
        }
        if (count(self::present($db, $wpdb->options, 'option_name', ["theme_mods_$theme" => 1, "theme_mods_$current" => 1])) === 2) {
            $found[] = "option theme_mods_$current (and theme_mods_$theme)";
        }
        if (count(self::present($db, $wpdb->posts, 'post_name', ["wp-global-styles-$theme" => 1, "wp-global-styles-$current" => 1], "`post_type` = 'wp_global_styles'")) === 2) {
            $found[] = "global styles of the theme $current (and $theme)";
        }
        $apps = $db->prefix() . (isset($plan['tables'][$db->prefix() . 'tp_identity_applications']) ? 'tp_identity_applications' : 'sp_identity_applications');
        if (self::existingTables($db, $apps, exact: true) !== []) {
            foreach (self::GUEST_CLIENT as $from => $to) {
                if ($db->run("SELECT 1 AS x FROM `$apps` WHERE `id` = ?", [$from]) !== [] && $db->run("SELECT 1 AS x FROM `$apps` WHERE `id` = ?", [$to]) !== []) {
                    $found[] = "identity application $to";
                }
            }
        }
        // A foreign key of a table about to move takes its current name ({@see renameForeignKeys()}),
        // and MySQL names constraints once per database: that name may be taken by any table in it.
        if (!$db->sqlite() && $plan['tables'] !== []) {
            $marks = implode(', ', array_fill(0, count($plan['tables']), '?'));
            $next = [];
            foreach ($db->run("SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)", array_keys($plan['tables'])) as $row) {
                if (preg_match('/\A(m[0-9a-f]{7}_)?tp_/', (string) $row['n'], $m) === 1) {
                    $next[substr(($m[1] ?? '') . 'sp_' . substr((string) $row['n'], strlen(($m[1] ?? '') . 'tp_')), 0, 64)] = $row['t'];
                }
            }
            if ($next !== []) {
                $marks = implode(', ', array_fill(0, count($next), '?'));
                foreach ($db->run("SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME IN ($marks)", array_keys($next)) as $row) {
                    $found[] = "foreign key {$row['n']} on {$row['t']} (wanted by {$next[$row['n']]})";
                }
            }
        }
        // Where the stored files stay: a record already there (written by anything but this move,
        // which writes it only after this check) must be exactly what this move writes, or absent.
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", self::UPLOAD_KEYS), ARRAY_A);
        if ($row !== null && (($plan['keys'] ?? []) === [] || !self::uploadKeysAre($row, $plan['keys']))) {
            $found[] = 'option ' . self::UPLOAD_KEYS;
        }
        // A renamed app's local files are found under one directory: the earlier one, when it has
        // one. Files under the current name too would no longer be found.
        foreach ($plan['keys']['privateApps'] ?? [] as $current => $earlier) {
            $dir = WP_CONTENT_DIR . '/uploads/' . self::LEGACY_PRIVATE_DIR . "/$current";
            if (file_exists($dir) || is_link($dir)) {
                $found[] = "directory $dir (and " . dirname($dir) . "/$earlier): the files under both names would be read from one";
            }
        }
        // A renamed app reads its runtime schema versions under both names as one chain ({@see historyApp()}):
        // a version number written under each would break it.
        $versions = $db->prefix() . (isset($plan['tables'][$db->prefix() . 'tp__schema_versions']) ? 'tp__schema_versions' : 'sp__schema_versions');
        if ($plan['renamedApp'] !== [] && self::existingTables($db, $versions, exact: true) !== []) {
            foreach ($plan['renamedApp'] as $from => $to) {
                foreach ($db->run("SELECT a.`version` AS v FROM `$versions` a JOIN `$versions` b ON b.`version` = a.`version` AND b.`app` = ? WHERE a.`app` = ?", [$to, $from]) as $row) {
                    $found[] = "runtime schema version {$row['v']} recorded for both $from and $to";
                }
            }
        }
        // A renamed app's ledger rows take its current name, and the ledger is keyed by (app, id).
        $ledger = $db->prefix() . (isset($plan['tables'][$db->prefix() . 'tp__migrations']) ? 'tp__migrations' : 'sp__migrations');
        if ($plan['renamedApp'] !== [] && self::existingTables($db, $ledger, exact: true) !== []) {
            foreach ($plan['renamedApp'] as $from => $to) {
                foreach ($db->run("SELECT a.`id` AS id FROM `$ledger` a JOIN `$ledger` b ON b.`id` = a.`id` AND b.`app` = ? WHERE a.`app` = ?", [$to, $from]) as $row) {
                    $found[] = "migration {$row['id']} recorded for both $from and $to";
                }
            }
        }

        return $found;
    }

    // ================================================================ applying it

    /** @return array<string, int> */
    private static function apply(Connection $db, array $plan): array
    {
        $wpdb = $db->wpdb();
        $moved = [];
        // Files already stored stay where they are ({@see uploadKeys()}). Recorded first, while the
        // earlier namespace option and directory are still there to show the site has stored any, so
        // a resumed run never loses it.
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", self::UPLOAD_KEYS), ARRAY_A);
        if ($row !== null && $plan['resumed'] && is_array($stored = maybe_unserialize((string) $row['option_value']))
            && array_intersect_key($stored, $plan['keys']) == $plan['keys'] && self::uploadKeysAre($row, $stored)) {
            // A resumed move: the record a first attempt wrote, while the namespace option it reads
            // was still under the earlier name, already holds this plan's keys and the S3 ones.
            $plan['keys'] = $stored;
        }
        if ($plan['keys'] !== []) {
            $keys = $plan['keys'];
            if ($row === null) {
                $db->run("INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (?, ?, 'on')", [self::UPLOAD_KEYS, serialize($keys)]);
            } elseif (!self::uploadKeysAre($row, $keys)) {
                // A resumed move wrote it whole; anything else was not this move's.
                throw new \RuntimeException('The option ' . self::UPLOAD_KEYS . ' holds something this move did not write; move it aside, then reload. Where stored uploads are found depends on it.');
            }
            // Checked once written, before the options it stands for move.
            if (maybe_unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::UPLOAD_KEYS))) !== $keys) {
                throw new \RuntimeException('Cannot record where stored uploads are; reload to try again.');
            }
            wp_cache_delete('alloptions', 'options');
        }
        $moved['tables'] = self::moveTables($db, $plan['tables']);
        // The app's own name, where the site keyed something by it: ledger rows (each keeps its id and
        // SHA-256) and private file records. Stored S3 keys and local private files stay as written
        // ({@see uploadKeys()}), runtime schema versions too ({@see historyApp()}).
        foreach ($plan['renamedApp'] as $from => $to) {
            // Runtime schema versions stay under the name they were written with: each row's hash covers
            // it (SchemaVersions::hashOf()), and the app reads them by both names ({@see historyApp()}).
            foreach (['sp__migrations', 'sp__files'] as $table) {
                $moved['app'] = ($moved['app'] ?? 0) + self::rename($db, $db->prefix() . $table, 'app', [$from => $to], optional: true);
            }
        }
        if (!$db->sqlite()) {
            // Every table the runtime and this app own, by its current name: those moved just now, and
            // those an earlier attempt moved before it stopped.
            self::renameForeignKeys($db, array_values(array_filter($plan['owned'], static fn (string $t): bool => self::existingTables($db, $t, exact: true) !== [])));
        }
        // A value a boot wrote under the current name moments ago gives way to the site's stored one.
        // The plan lists only old names still present, so on a resumed run an option already moved
        // is not in it and keeps its value.
        foreach (self::REGENERATED as $name) {
            if (!isset($plan['options'][$name])) {
                continue;
            }
            // The site's row moves into the name; the one a request wrote there is kept, set aside.
            $to = $plan['options'][$name];
            $db->run("UPDATE `{$wpdb->options}` SET `option_name` = ?, `autoload` = 'off' WHERE `option_name` = ?", [$to . '__superseded_' . time(), $to]);
        }
        $moved['options'] = self::rename($db, $wpdb->options, 'option_name', $plan['options']);
        // Renamed by SQL past WordPress's cache: what this request writes from here (a probe's verdict,
        // a role) compares against the stored rows, not values cached before the move.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        foreach ($plan['options'] as $from => $to) {
            wp_cache_delete($from, 'options');
            wp_cache_delete($to, 'options');
        }
        $moved['sitemeta'] = is_multisite() ? self::renameWhere($db, $wpdb->sitemeta, 'meta_key', $plan['sitemeta'], '`site_id` = ' . (int) get_current_network_id()) : 0;
        $moved['meta'] = 0;
        foreach ($plan['meta'] as $table => $keys) {
            $moved['meta'] += self::rename($db, $wpdb->$table, 'meta_key', $keys);
        }
        foreach ($plan['fieldMeta'] as [$table, $type, $keys]) {
            $moved['meta'] += self::renameWhere($db, $wpdb->$table, 'meta_key', $keys, self::fieldScope($wpdb, $table, $type));
        }
        // SCF's reference rows only (`_sp_<field>` once their keys moved above): a value elsewhere
        // that happens to read `field_tp_…` is someone else's.
        foreach (['postmeta', 'termmeta', 'usermeta'] as $table) {
            $moved['meta'] += self::scfPairs($db, $table, $plan['scfPrefixes']);
            $moved['meta'] += self::renameWhere($db, $wpdb->$table, 'meta_value', $plan['metaValues'], "`meta_key` LIKE '\\_sp\\_%'");
        }
        $moved['options'] += self::renameWhere($db, $wpdb->options, 'option_value', $plan['globalRefs'], "`option_name` LIKE '\\_sp\\_global\\_%'");
        $moved['posts'] = self::rename($db, $wpdb->posts, 'post_type', $plan['postTypes'])
            + self::rename($db, $wpdb->posts, 'post_status', self::POST_STATUSES)
            + self::renameWhere($db, $wpdb->posts, 'post_name', self::PAGE_SLUGS, "`post_type` = 'page' AND `ID` IN (SELECT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ('_sp_route', '_tp_route'))");
        $moved['taxonomies'] = self::rename($db, $wpdb->term_taxonomy, 'taxonomy', $plan['taxonomies']);
        $moved['templates'] = self::templates($db);
        $moved['theme'] = self::fallbackTheme($db);
        $moved['roles'] = self::roles($db, $plan['kinds']);
        // Two Factor's own keys only: the user's chosen provider (a network keeps it, as the scope, for
        // sites not yet moved, which read the earlier name) and the list of enabled ones.
        $moved['twoFactor'] = (is_multisite() ? 0 : self::renameWhere($db, $wpdb->usermeta, 'meta_value', self::TWO_FACTOR, "`meta_key` = '_two_factor_provider'"))
            + self::twoFactorLists($db);
        $moved['scopes'] = self::scopes($db);
        $moved['scheduler'] = self::scheduler($db, $plan['hooks'], $plan['groups']);
        $moved['cron'] = self::cron($plan['hooks']);
        $moved['jobs'] = self::rename($db, $db->prefix() . 'sp__jobs', 'job', $plan['jobs'], optional: true);
        $identity = $db->prefix() . 'sp_identity_applications';
        $moved['clients'] = self::rename($db, $identity, 'id', self::GUEST_CLIENT, optional: true)
            + self::rename($db, $db->prefix() . 'sp_identity_codes', 'client_id', self::GUEST_CLIENT, optional: true)
            + self::rename($db, $db->prefix() . 'sp_identity_tokens', 'client_id', self::GUEST_CLIENT, optional: true);
        $moved['plugins'] = self::activePlugins($plan['plugins']);
        // Runtime schema states are sealed with a key that named the pre-release build: one that checks
        // out under it is sealed again under the current one (its versions and hashes stay as they are).
        foreach ($plan['apps'] as $app) {
            // Read from the database: the options were renamed by SQL, past WordPress's cache.
            $option = \SpacePress\RuntimeSchema\Loader::option($app);
            wp_cache_delete($option, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $raw = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option), ARRAY_A);
            $state = $raw === null ? null : maybe_unserialize((string) $raw['option_value']);
            if (is_array($state) && isset($state['seal']) && !\SpacePress\RuntimeSchema\Loader::sealedBy($state)) {
                $resealed = \SpacePress\RuntimeSchema\Loader::reseal($state, self::LEGACY . '-runtime-schema:');
                if ($resealed !== null) {
                    // As apply() writes it (autoloaded), and checked once written.
                    update_option($option, $resealed, true);
                    wp_cache_delete($option, 'options');
                    $written = maybe_unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)));
                    if (!is_array($written) || !\SpacePress\RuntimeSchema\Loader::sealedBy($written)) {
                        throw new \RuntimeException("The runtime schema state of $app did not keep its new seal; reload to try again.");
                    }
                    $moved['runtimeSchema'] = ($moved['runtimeSchema'] ?? 0) + 1;
                }
            }
        }
        // Core's cache of theme directories, which named the earlier runtime's theme path; core rebuilds it.
        delete_site_transient('theme_roots');
        wp_cache_flush();
        $moved = array_filter($moved);
        self::ledger($db, $plan['apps'], $moved);

        return $moved;
    }

    /** Renames tables in one pass; SQLite keeps its rows, indexes, foreign keys and MySQL metadata. */
    private static function moveTables(Connection $db, array $tables): int
    {
        if ($tables === []) {
            return 0;
        }
        $q = static fn (string $n): string => '`' . str_replace('`', '``', $n) . '`';
        if (!$db->sqlite()) {
            // One statement: atomic, and foreign keys follow the renamed tables.
            $db->run('RENAME TABLE ' . implode(', ', array_map(static fn (string $from, string $to): string => $q($from) . ' TO ' . $q($to), array_keys($tables), $tables)));

            return count($tables);
        }
        $driver = $db->wpdb()->dbh;
        $pdo = method_exists($driver, 'get_sqlite_pdo') ? $driver->get_sqlite_pdo() : $driver;
        $quote = static fn (string $n): string => '"' . str_replace('"', '""', $n) . '"';
        $counts = [];
        foreach (array_keys($tables) as $from) {
            $counts[$from] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quote($from))->fetchColumn();
        }
        $info = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '\\_wp\\_sqlite\\_mysql\\_information\\_schema\\_%' ESCAPE '\\'")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $pdo->beginTransaction();
        try {
            foreach ($tables as $from => $to) {
                // Native rename: SQLite rewrites references to the table in other tables' foreign keys.
                $pdo->exec('ALTER TABLE ' . $quote($from) . ' RENAME TO ' . $quote($to));
                // The driver names an index `<table>__<index>`; it keeps answering by those names.
                foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = " . $pdo->quote($to) . ' AND sql IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                    if (!str_starts_with((string) $index['name'], $from . '__')) {
                        continue;
                    }
                    $renamed = $to . substr((string) $index['name'], strlen($from));
                    $sql = preg_replace('/^(CREATE (?:UNIQUE )?INDEX )(?:`(?:[^`]|``)+`|"(?:[^"]|"")+")/', '$1' . $quote($renamed), (string) $index['sql'], 1, $replaced);
                    if ($replaced !== 1) {
                        throw new \RuntimeException("Cannot rename index {$index['name']}");
                    }
                    $pdo->exec('DROP INDEX ' . $quote((string) $index['name']));
                    $pdo->exec((string) $sql);
                }
                foreach ($info as $meta) {
                    $columns = array_column($pdo->query('PRAGMA table_info(' . $quote((string) $meta) . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
                    foreach (['TABLE_NAME', 'REFERENCED_TABLE_NAME'] as $column) {
                        if (in_array($column, $columns, true)) {
                            $pdo->prepare('UPDATE ' . $quote((string) $meta) . " SET $column = ? WHERE $column = ?")->execute([$to, $from]);
                        }
                    }
                }
            }
            // Constraint names, as on MySQL ({@see renameForeignKeys()}): the driver rebuilds a table's
            // SQLite definition from these rows on its next ALTER, so this is where the name lives.
            foreach ($info as $meta) {
                $columns = array_column($pdo->query('PRAGMA table_info(' . $quote((string) $meta) . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
                if (!in_array('CONSTRAINT_NAME', $columns, true) || !in_array('TABLE_NAME', $columns, true)) {
                    continue;
                }
                $marks = implode(', ', array_fill(0, count($tables), '?'));
                // `tp_<t>_<c>_fk`, or `m<hash7>_tp_…` on a non-default prefix (Migrator::scopeForeignKeys()).
                $rename = $pdo->prepare('UPDATE ' . $quote((string) $meta) . " SET CONSTRAINT_NAME = 'sp_' || SUBSTR(CONSTRAINT_NAME, 4) WHERE CONSTRAINT_NAME LIKE 'tp\\_%' ESCAPE '\\' AND TABLE_NAME IN ($marks)");
                $rename->execute(array_values($tables));
                $rename = $pdo->prepare('UPDATE ' . $quote((string) $meta) . " SET CONSTRAINT_NAME = SUBSTR(CONSTRAINT_NAME, 1, 9) || 'sp_' || SUBSTR(CONSTRAINT_NAME, 13) WHERE CONSTRAINT_NAME GLOB 'm[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]_tp_*' AND TABLE_NAME IN ($marks)");
                $rename->execute(array_values($tables));
            }
            foreach ($tables as $from => $to) {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quote($to))->fetchColumn();
                if ($count !== $counts[$from]) {
                    throw new \RuntimeException("$to holds $count rows after the move, $from held {$counts[$from]}");
                }
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new \RuntimeException('a foreign key no longer holds after moving the tables');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return count($tables);
    }

    /**
     * Foreign keys keep the name they were created with across RENAME TABLE, and a later migration
     * drops one by the name the compiler gives it now (`sp_<table>_<column>_fk`, or `m<hash>_sp_…` on
     * a non-default prefix). Each `tp_…` constraint on `$tables` (current names) is added again under
     * that name, with the same columns, target (schema included) and rules, then the old one dropped.
     * Two statements per key, not one combined ALTER: MySQL refuses some drop-and-add combinations of
     * one key that MariaDB takes. It reads the keys from the database each time, so a run that stopped
     * between the rename and this step finishes it on the next attempt.
     *
     * @param list<string> $tables
     */
    private static function renameForeignKeys(Connection $db, array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $marks = implode(', ', array_fill(0, count($tables), '?'));
        $rows = $db->run(
            "SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_SCHEMA AS rs, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS d, r.UPDATE_RULE AS u
             FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME IN ($marks) AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
            $tables,
        );
        // Every key of these tables by name, as `columns -> schema.table(columns) delete/update`: a key
        // already added under its new name by a run that stopped before dropping the old one is reused.
        $defined = [];
        foreach ($rows as $row) {
            $defined[$row['t']][(string) $row['n']][] = $row;
        }
        // InnoDB enforces NO ACTION as RESTRICT, and MySQL reports an omitted rule as either.
        $rule = static fn (string $r): string => $r === 'NO ACTION' ? 'RESTRICT' : $r;
        $shape = static fn (array $parts): string => implode(',', array_column($parts, 'c')) . '>' . $parts[0]['rs'] . '.' . $parts[0]['rt'] . '(' . implode(',', array_column($parts, 'rc')) . ')'
            . $rule((string) $parts[0]['d']) . '/' . $rule((string) $parts[0]['u']);
        $keys = [];
        foreach ($rows as $row) {
            $name = (string) $row['n'];
            if (preg_match('/\A(m[0-9a-f]{7}_)?tp_/', $name, $m) !== 1) {
                continue;
            }
            $head = $m[1] ?? '';
            $key = &$keys[$row['t']][$name];
            $key ??= ['to' => substr($head . 'sp_' . substr($name, strlen($head . 'tp_')), 0, 64), 'cols' => [], 'refs' => [],
                'schema' => (string) $row['rs'], 'table' => (string) $row['rt'], 'delete' => $row['d'], 'update' => $row['u']];
            $key['cols'][] = $row['c'];
            $key['refs'][] = $row['rc'];
            unset($key);
        }
        $q = static fn (string $n): string => '`' . str_replace('`', '``', $n) . '`';
        $rules = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];
        $here = (string) ($db->run('SELECT DATABASE() AS d')[0]['d'] ?? '');
        foreach ($keys as $table => $constraints) {
            foreach ($constraints as $name => $k) {
                $delete = in_array($k['delete'], $rules, true) ? $k['delete'] : 'RESTRICT';
                $update = in_array($k['update'], $rules, true) ? $k['update'] : 'RESTRICT';
                // The target as stored: another database's table stays that database's.
                $target = ($k['schema'] !== '' && $k['schema'] !== $here ? $q($k['schema']) . '.' : '') . $q($k['table']);
                $existing = $defined[$table][$k['to']] ?? null;
                if ($existing !== null && $shape($existing) !== $shape($defined[$table][$name])) {
                    throw new \RuntimeException("Cannot rename foreign key $name on $table: $table already has a different key named {$k['to']}");
                }
                if ($existing === null) {
                    $db->run('ALTER TABLE ' . $q((string) $table) . ' ADD CONSTRAINT ' . $q($k['to']) . ' FOREIGN KEY (' . implode(', ', array_map($q, $k['cols'])) . ') REFERENCES ' . $target
                        . ' (' . implode(', ', array_map($q, $k['refs'])) . ") ON DELETE $delete ON UPDATE $update");
                }
                // The index behind the old key is taken over by the new one (MySQL and MariaDB rename it).
                $db->run('ALTER TABLE ' . $q((string) $table) . ' DROP FOREIGN KEY ' . $q($name));
            }
        }
    }

    /**
     * Templates an owner saved in the Site Editor from the runtime's own: a `wp_template` post named by
     * the template's slug, its revisions, and the runtime's blocks inside them. Only those exact slugs,
     * and only the block comments of the runtime's two blocks, are rewritten; the owner's markup stays.
     */
    private static function templates(Connection $db): int
    {
        $posts = $db->wpdb()->posts;
        $count = self::renameWhere($db, $posts, 'post_name', self::TEMPLATE_SLUGS, "`post_type` = 'wp_template'");
        $where = "(`post_type` IN ('wp_template', 'wp_template_part') OR (`post_type` = 'revision' AND `post_parent` IN (SELECT `ID` FROM (SELECT `ID` FROM `$posts` WHERE `post_type` IN ('wp_template', 'wp_template_part')) AS t)))";
        foreach (self::BLOCKS as $from => $to) {
            $rows = $db->run("SELECT `ID` AS id FROM `$posts` WHERE $where AND `post_content` LIKE ?", ['%' . addcslashes($from, '\\_%') . '%']);
            foreach ($rows as $row) {
                $db->run("UPDATE `$posts` SET `post_content` = REPLACE(`post_content`, ?, ?) WHERE `ID` = ?", [$from, $to, (int) $row['id']]);
            }
            // The self-closing and closing forms of the same comment.
            foreach ([rtrim($from) . '/-->' => rtrim($to) . '/-->', '<!-- /' . substr($from, 5) => '<!-- /' . substr($to, 5)] as $a => $b) {
                $db->run("UPDATE `$posts` SET `post_content` = REPLACE(`post_content`, ?, ?) WHERE $where AND `post_content` LIKE ?", [$a, $b, '%' . addcslashes($a, '\\_%') . '%']);
            }
            $count += count($rows);
        }

        return $count;
    }

    /**
     * What the Site Editor saved for the runtime's own fallback theme, which a pre-release build named
     * after its brand: the `wp_theme` term its templates and parts are filed under, its global styles
     * post, its theme mods, and the stylesheet and template options when the owner chose it. The
     * saved content itself stays as written; an owner's own theme is never touched.
     */
    private static function fallbackTheme(Connection $db): int
    {
        [$from, $to] = [self::LEGACY . '-default', Hardening::THEME];
        $wpdb = $db->wpdb();
        $count = 0;
        foreach ($db->run("SELECT t.`term_id` AS id FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` x ON x.`term_id` = t.`term_id` WHERE x.`taxonomy` = 'wp_theme' AND t.`slug` = ?", [$from]) as $row) {
            if ($db->run("SELECT 1 AS x FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` x ON x.`term_id` = t.`term_id` WHERE x.`taxonomy` = 'wp_theme' AND t.`slug` = ?", [$to]) !== []) {
                // The current theme has its own saves already: they stay, the earlier ones stay under their name.
                continue;
            }
            $db->run("UPDATE `{$wpdb->terms}` SET `slug` = ?, `name` = ? WHERE `term_id` = ?", [$to, $to, (int) $row['id']]);
            $count++;
        }
        $count += self::renameWhere($db, $wpdb->posts, 'post_name', ['wp-global-styles-' . $from => 'wp-global-styles-' . $to], "`post_type` = 'wp_global_styles'");
        $count += self::update_unless_taken($db, $wpdb->options, 'option_name', 'theme_mods_' . $from, 'theme_mods_' . $to);
        foreach (['stylesheet', 'template'] as $option) {
            $count += self::renameWhere($db, $wpdb->options, 'option_value', [$from => $to], "`option_name` = '$option'");
        }

        return $count;
    }

    /** Renames one exact value unless the new one is already there (both stay then). */
    private static function update_unless_taken(Connection $db, string $table, string $column, string $from, string $to): int
    {
        if ($db->run("SELECT 1 AS x FROM `$table` WHERE `$column` = ?", [$to]) !== []) {
            return 0;
        }

        return self::rename($db, $table, $column, [$from => $to]);
    }

    /** Capability names on roles and users: the schema capability, the guest role, post-type and taxonomy caps. */
    private static function roles(Connection $db, array $kinds): int
    {
        $wpdb = $db->wpdb();
        $map = static function (string $name) use ($kinds): string {
            if (isset(self::ROLES[$name])) {
                return self::ROLES[$name];
            }
            if (isset(self::CAPABILITIES[$name])) {
                return self::CAPABILITIES[$name];
            }
            // `edit_tp_x_item`, `create_tp_x`, `manage_tp_tax`: a kind's own caps.
            foreach ($kinds as $from => $to) {
                if (preg_match('/^([a-z_]+_)' . preg_quote($from, '/') . '(_[a-z]+)?$/D', $name, $m)) {
                    return $m[1] . $to . ($m[2] ?? '');
                }
            }

            return $name;
        };
        $count = 0;
        $roles = get_option($wpdb->prefix . 'user_roles');
        if (is_array($roles)) {
            $next = [];
            foreach ($roles as $role => $spec) {
                $caps = [];
                foreach ((array) ($spec['capabilities'] ?? []) as $cap => $grant) {
                    $caps[$map((string) $cap)] = $grant;
                }
                $to = $map((string) $role);
                // A boot may have added the role under its new name already: one role, both grants.
                $next[$to] = ['capabilities' => $caps + (array) ($next[$to]['capabilities'] ?? [])] + (array) $spec + (array) ($next[$to] ?? []);
            }
            if ($next !== $roles) {
                update_option($wpdb->prefix . 'user_roles', $next);
                // WP_Roles holds its own copy and writes it back on the next role change: reload it.
                wp_roles()->for_site();
                $count++;
            }
        }
        $key = $wpdb->prefix . 'capabilities';
        foreach ($db->run("SELECT `umeta_id` AS id, `meta_value` AS v FROM `{$wpdb->usermeta}` WHERE `meta_key` = ?", [$key]) as $row) {
            $caps = maybe_unserialize((string) $row['v']);
            if (!is_array($caps)) {
                continue;
            }
            $next = [];
            foreach ($caps as $cap => $grant) {
                $next[$map((string) $cap)] = $grant;
            }
            if ($next !== $caps) {
                $db->run("UPDATE `{$wpdb->usermeta}` SET `meta_value` = ? WHERE `umeta_id` = ?", [serialize($next), (int) $row['id']]);
                $count++;
            }
        }

        return $count;
    }

    /** Each application password's scope, kept on its entry under {@see AgentScope::KEY}. */
    private static function scopes(Connection $db): int
    {
        $wpdb = $db->wpdb();
        [$from, $to] = [self::SCOPE_KEY, AgentScope::KEY];
        $count = 0;
        foreach ($db->run("SELECT `umeta_id` AS id, `meta_value` AS v FROM `{$wpdb->usermeta}` WHERE `meta_key` = '_application_passwords' AND `meta_value` LIKE ?", ['%"' . $from . '"%']) as $row) {
            $passwords = maybe_unserialize((string) $row['v']);
            if (!is_array($passwords)) {
                continue;
            }
            foreach ($passwords as $i => $entry) {
                if (is_array($entry) && array_key_exists($from, $entry)) {
                    // What the entry granted before the move, read as the runtime reads it (both keys:
                    // the narrower; a present null under either is that key's narrowest), kept whole.
                    $earlier = AgentScope::normalize(self::earlierScope($entry));
                    $passwords[$i][$to] = array_key_exists($to, $entry) ? self::narrower(AgentScope::normalize($entry[$to]), $earlier) : $earlier;
                    // User meta is shared by a network's sites: another site may not have moved yet and
                    // still reads the earlier key. It stays there on a network, unused once all moved.
                    if (!is_multisite()) {
                        unset($passwords[$i][$from]);
                    }
                }
            }
            $db->run("UPDATE `{$wpdb->usermeta}` SET `meta_value` = ? WHERE `umeta_id` = ?", [serialize($passwords), (int) $row['id']]);
            $count++;
        }

        return $count;
    }

    /** Two Factor's enabled-providers list, a serialized array that names the provider class. */
    private static function twoFactorLists(Connection $db): int
    {
        $wpdb = $db->wpdb();
        [$from, $to] = [array_key_first(self::TWO_FACTOR), self::TWO_FACTOR[array_key_first(self::TWO_FACTOR)]];
        $count = 0;
        foreach ($db->run("SELECT `umeta_id` AS id, `meta_value` AS v FROM `{$wpdb->usermeta}` WHERE `meta_key` = '_two_factor_enabled_providers' AND `meta_value` LIKE ?", ['%' . $from . '%']) as $row) {
            $list = maybe_unserialize((string) $row['v']);
            if (is_array($list)) {
                // On a network both names stay enabled until every site has moved; the earlier one is unused after.
                $next = is_multisite() ? array_values(array_unique([...$list, $to])) : array_values(array_unique(array_map(static fn ($p) => $p === $from ? $to : $p, $list)));
                $db->run("UPDATE `{$wpdb->usermeta}` SET `meta_value` = ? WHERE `umeta_id` = ?", [serialize($next), (int) $row['id']]);
                $count++;
            }
        }

        return $count;
    }

    /** Action Scheduler: every action's hook, and the groups under their current slugs. */
    private static function scheduler(Connection $db, array $hooks, array $groups): int
    {
        $prefix = $db->prefix();
        $actions = $prefix . 'actionscheduler_actions';
        $table = $prefix . 'actionscheduler_groups';
        if (self::existingTables($db, $actions, exact: true) === [] || self::existingTables($db, $table, exact: true) === []) {
            return 0;
        }
        $count = self::rename($db, $actions, 'hook', $hooks);
        foreach ($groups as $from => $to) {
            $old = $db->run("SELECT `group_id` AS id FROM `$table` WHERE `slug` = ?", [$from]);
            if ($old === []) {
                continue;
            }
            $current = $db->run("SELECT MIN(`group_id`) AS id FROM `$table` WHERE `slug` = ?", [$to])[0]['id'] ?? null;
            if ($current === null) {
                $db->run("UPDATE `$table` SET `slug` = ? WHERE `slug` = ?", [$to, $from]);
            } else {
                // A request already scheduled under the new slug: its group takes the old one's actions.
                foreach (array_column($old, 'id') as $id) {
                    $db->run("UPDATE `$actions` SET `group_id` = ? WHERE `group_id` = ?", [(int) $current, (int) $id]);
                }
            }
            wp_cache_delete($from, \ActionScheduler_DBStore::GROUP_IDS_CACHE_GROUP);
            wp_cache_delete($to, \ActionScheduler_DBStore::GROUP_IDS_CACHE_GROUP);
            $count += count($old);
        }

        return $count;
    }

    /** wp-cron events of the runtime's hooks, with the same times, arguments and schedules. */
    private static function cron(array $hooks, ?array &$taken = null): int
    {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return 0;
        }
        $count = 0;
        foreach ($cron as $time => $events) {
            foreach ((array) $events as $hook => $instances) {
                if (!isset($hooks[$hook])) {
                    continue;
                }
                foreach ((array) $instances as $key => $event) {
                    if (is_string($event['schedule'] ?? null) && isset(self::CRON_SCHEDULES[$event['schedule']])) {
                        $event['schedule'] = self::CRON_SCHEDULES[$event['schedule']];
                    }
                    // The same event under its current hook is the one moving; a different one is not this move's to replace.
                    if (isset($cron[$time][$hooks[$hook]][$key]) && $cron[$time][$hooks[$hook]][$key] != $event) {
                        if ($taken !== null) {
                            $taken[] = "scheduled event {$hooks[$hook]} at $time";
                            continue;
                        }
                        throw new \RuntimeException("A different {$hooks[$hook]} event at $time is already scheduled beside $hook; unschedule the one this site doesn't use, then reload.");
                    }
                    $cron[$time][$hooks[$hook]][$key] = $event;
                }
                unset($cron[$time][$hook]);
                $count++;
            }
        }
        if ($taken !== null) {
            return 0;
        }
        // Checked: the move is recorded done only once the events run under their current hooks.
        if ($count > 0 && is_wp_error($saved = _set_cron_array($cron, true))) {
            throw new \RuntimeException('Cannot save the scheduled events under their current hooks: ' . $saved->get_error_message());
        }

        return $count;
    }

    /**
     * Replaces renamed plugins in the active lists, in place, so the same set stays active. When this
     * runs inside a plugin's activation (`activate_plugin()` appends that plugin to the list after its
     * hook), the plugin may already be listed by now: the rest of this request keeps the list unique.
     */
    private static function activePlugins(array $plugins): int
    {
        if ($plugins === []) {
            return 0;
        }
        add_filter('pre_update_option_active_plugins', static fn ($list) => is_array($list) ? array_values(array_unique($list)) : $list);
        $active = get_option('active_plugins', []);
        if (is_array($active)) {
            update_option('active_plugins', array_values(array_unique(array_map(static fn ($p) => $plugins[$p] ?? $p, $active))));
        }
        if (is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                $next = [];
                foreach ($network as $basename => $time) {
                    $next[$plugins[$basename] ?? $basename] = $time;
                }
                update_site_option('active_sitewide_plugins', $next);
            }
        }

        return count($plugins);
    }

    /** @return list<string> */
    private static function activePluginList(): array
    {
        $list = array_values(array_filter((array) get_option('active_plugins', []), 'is_string'));
        if (is_multisite()) {
            $list = [...$list, ...array_keys((array) get_site_option('active_sitewide_plugins', []))];
        }

        return array_values(array_unique($list));
    }

    /** The plugins the runtime shipped under the earlier name, by directory; an app's is `typedpress-app-<name>`. */
    private const PLUGINS = ['typedpress' => 'spacepress', 'typedpress-runtime' => 'spacepress-runtime', 'typedpress-identity' => 'spacepress-identity', 'typedpress-admin' => 'spacepress-admin'];

    /**
     * The current basename of one of the runtime's own plugins, or the basename unchanged for any other
     * plugin. A renamed app's plugin takes the app's current name: `<brand>-app-<earlier app name>`.
     *
     * @param array<string, string> $renamedApp
     */
    private static function pluginBasename(string $basename, array $renamedApp): string
    {
        $parts = explode('/', $basename);
        if (count($parts) !== 2 || $parts[1] !== $parts[0] . '.php') {
            return $basename;
        }
        $to = self::PLUGINS[$parts[0]] ?? (preg_match('/\A' . self::LEGACY . '-app-([a-z0-9][a-z0-9-]*)\z/D', $parts[0], $m) === 1 ? 'spacepress-app-' . ($renamedApp[$m[1]] ?? $m[1]) : null);

        return $to === null ? $basename : "$to/$to.php";
    }

    /** One `data` row per app in the ledger: what moved. The rows already there are left as they are. */
    private static function ledger(Connection $db, array $apps, array $moved): void
    {
        $ledger = $db->prefix() . 'sp__migrations';
        if ($apps === [] || self::existingTables($db, $ledger, exact: true) === []) {
            return;
        }
        $id = 'brand-upgrade-v' . self::VERSION;
        foreach ($apps as $app) {
            if ($db->run("SELECT 1 AS x FROM `$ledger` WHERE `app` = ? AND `id` = ?", [$app, $id]) === []) {
                $db->run(
                    "INSERT INTO `$ledger` (`app`, `id`, `kind`, `applied_at`, `sql`, `actor`, `reason`, `rows_affected`) VALUES (?, ?, 'data', ?, NULL, 'spacepress', ?, ?)",
                    [$app, $id, gmdate('Y-m-d\TH:i:s.000\Z'), 'stored names moved from the pre-release name: ' . (string) \SpacePress\Json::encode($moved), array_sum($moved)],
                );
            }
        }
    }

    // ================================================================ helpers

    /** @return list<string> tables named `$name` exactly, or starting with it */
    private static function existingTables(Connection $db, string $name, bool $exact = false): array
    {
        $match = $exact ? $name : addcslashes($name, '\\_%') . '%';
        $op = $exact ? '=' : 'LIKE';
        $rows = $db->sqlite()
            ? $db->run("SELECT name AS n FROM sqlite_master WHERE type = 'table' AND name $op ?", [$match])
            : $db->run("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME $op ?", [$match]);
        $names = array_map('strval', array_column($rows, 'n'));

        // LIKE is case-insensitive; table names here are not.
        return array_values(array_filter($names, static fn (string $n): bool => $exact ? $n === $name : str_starts_with($n, $name)));
    }

    /** @return list<string> values of `$column` starting with `$head` (and ending with `$tail`) */
    private static function keys(Connection $db, string $table, string $column, string $head, string $tail = ''): array
    {
        $like = addcslashes($head, '\\_%') . '%' . addcslashes($tail, '\\_%');
        $values = array_map('strval', array_column($db->run("SELECT DISTINCT `$column` AS v FROM `$table` WHERE `$column` LIKE ?", [$like]), 'v'));

        return array_values(array_filter($values, static fn (string $v): bool => str_starts_with($v, $head) && str_ends_with($v, $tail)));
    }

    /** The entries of `$map` whose old name exists in `$column`. */
    private static function present(Connection $db, string $table, string $column, array $map, ?string $where = null): array
    {
        $out = [];
        foreach (array_chunk(array_keys($map), 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            foreach (array_column($db->run("SELECT DISTINCT `$column` AS v FROM `$table` WHERE `$column` IN ($marks)" . ($where ? " AND $where" : ''), $chunk), 'v') as $name) {
                if (isset($map[(string) $name])) {
                    $out[(string) $name] = $map[(string) $name];
                }
            }
        }

        return $out;
    }

    /** New names of `$map` already present in `$column`. @return list<string> */
    private static function taken(Connection $db, string $table, string $column, array $map, ?string $where = null): array
    {
        return array_keys(self::present($db, $table, $column, array_combine(array_values($map), array_values($map)) ?: [], $where));
    }

    private static function rename(Connection $db, string $table, string $column, array $map, bool $optional = false): int
    {
        return self::renameWhere($db, $table, $column, $map, null, $optional);
    }

    /** Sets `$column` from each old value to its new one; with `$optional`, a missing table is no work. */
    private static function renameWhere(Connection $db, string $table, string $column, array $map, ?string $where = null, bool $optional = false): int
    {
        if ($map === [] || ($optional && self::existingTables($db, $table, exact: true) === [])) {
            return 0;
        }
        $count = 0;
        foreach ($map as $from => $to) {
            $rows = (int) ($db->run("SELECT COUNT(*) AS n FROM `$table` WHERE `$column` = ?" . ($where ? " AND $where" : ''), [(string) $from])[0]['n'] ?? 0);
            if ($rows > 0) {
                $db->run("UPDATE `$table` SET `$column` = ? WHERE `$column` = ?" . ($where ? " AND $where" : ''), [(string) $to, (string) $from]);
                $count += $rows;
            }
        }

        return $count;
    }
}
