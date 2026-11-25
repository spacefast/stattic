<?php
declare(strict_types=1);
namespace SpacePress\Wp;

/** The single runtime and app registry; optional features attach to existing seams. */
final class Runtime
{
    public const SERIES = \SpacePress\Schema::SERIES;
    public const VERSION = '0.3.0';
    private static ?Plugin $plugin = null;
    private static ?string $appFile = null;
    private static bool $booted = false;
    /** The runtime plugin's main file, for URLs of the runtime's own browser modules. */
    private static ?string $runtimeFile = null;
    private static bool $managed = false;
    /** @var array<string, callable> `wp spacepress` commands other SpacePress plugins register (identity) */
    private static array $commands = [];
    /** Why the shared system tables are behind this runtime (failed or contended upgrade), else null. */
    private static ?string $unavailable = null;

    public static function boot(string $runtimeFile): void
    {
        if (self::$booted) {
            return;
        }
        if (!Platform::wordpressSupported((string) get_bloginfo('version')) || version_compare(PHP_VERSION, Platform::PHP, '<')) {
            self::notice('WordPress ' . Platform::WORDPRESS . '+ and PHP ' . Platform::PHP . '+ are required');
            return;
        }
        self::$booted = true;
        self::$runtimeFile = $runtimeFile;
        RouteRequest::register();
        AgentScope::trackCookie();
        // Application passwords sign in where core accepts them and nowhere else: on a request WordPress
        // itself has made a REST request (`REST_REQUEST`), never one a URL only appears to name.
        // A scoped application password signs in only where an active app enforces its scope.
        add_action('wp_authenticate_application_password_errors', [AgentScope::class, 'authenticating'], 10, 3);
        add_filter('rest_authentication_errors', [AgentScope::class, 'booted'], 0);
        add_filter('rest_authentication_errors', [AgentScope::class, 'unenforced'], 95);
        register_activation_hook($runtimeFile, [self::class, 'activate']);
        PageCaches::register();
        // As with app migrations: a runtime updated by copying files in sets up its tables on the
        // first request of any kind, once, under a lock.
        add_action('init', static function (): void {
            if (!self::installs() || get_option('sp_runtime_tables') === self::tablesVersion() || Platform::problems() !== [] || BrandUpgrade::pending()) {
                return;
            }
            global $wpdb;
            $current = static function (): bool {
                wp_cache_delete('sp_runtime_tables', 'options');
                return get_option('sp_runtime_tables') === self::tablesVersion();
            };
            self::$unavailable = Migrator::upgrade(new Connection($wpdb), 'sp_runtime_tables', $current, [self::class, 'applySystemTables']);
        }, 1);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('spacepress', static function (array $args, array $flags): void {
                if (in_array($args[0] ?? 'help', ['help', '--help'], true)) {
                    \WP_CLI::line(self::help());
                    return;
                }
                // Commands of other SpacePress plugins (identity) don't need an app.
                if (isset(self::$commands[$args[0]])) {
                    $code = (self::$commands[$args[0]])(array_slice($args, 1), $flags);
                    $code === 0 || \WP_CLI::halt($code);
                    return;
                }
                if (self::$plugin === null) {
                    \WP_CLI::error('No compatible SpacePress app is active.');
                }
                // While storage is behind, only `migrate` can run.
                if (($args[0] ?? '') !== 'migrate' && self::$plugin->unavailable() !== null) {
                    \WP_CLI::line((string) \SpacePress\Js::jsonStringify(['error' => \SpacePress\App::problem(new \SpacePress\HttpError(503, 'unavailable', (string) self::$plugin->unavailable()))]));
                    \WP_CLI::halt(1);
                }
                if (($args[0] ?? '') === 'seed') {
                    $code = \SpacePress\Sync\Seed::wpCli(self::$plugin, $args, $flags);
                    $code === 0 || \WP_CLI::halt($code);
                    return;
                }
                self::$plugin->cli($args, $flags);
            });
        }
        do_action('sp_runtime_loaded');
    }

    /**
     * A `wp spacepress <name>` command owned by another SpacePress plugin; it runs without an app transaction.
     *
     * @param callable(list<string> $args, array<string, string|true> $flags): int $fn prints its own JSON; returns the exit code
     */
    public static function command(string $name, callable $fn): void
    {
        if ($name === '' || isset(self::$commands[$name])) {
            throw new \LogicException("Invalid or duplicate wp spacepress command '$name'");
        }
        self::$commands[$name] = $fn;
    }

    /** `wp spacepress help`: the site commands of the table `spacepress help` prints (Cli/commands.json). */
    private static function help(): string
    {
        $table = json_decode((string) file_get_contents(dirname(__DIR__) . '/Cli/commands.json'), true, 8, JSON_THROW_ON_ERROR);
        $rows = array_values(array_filter($table['site'], static fn (array $row): bool => ($row['via'] ?? null) !== 'wp'));
        $width = max(array_map(static fn (array $row): int => strlen($row['name']), $rows));
        $lines = ['usage: wp spacepress <command> (or spacepress <command> from a project)', ''];
        foreach ($rows as $row) {
            $lines[] = '  ' . str_pad($row['name'], $width) . '  ' . $row['summary'];
        }

        return implode("\n", $lines);
    }

    /** Mount exactly one version after platform plugins load. Visitors never migrate managed apps. */
    public static function mount(string $runtimeFile, string $appDir, array $variables = [], ?\SpacePress\Schema $schema = null): Plugin
    {
        if (self::$booted && !self::$managed) {
            throw new \RuntimeException('A site-wide SpacePress runtime is already loaded');
        }
        self::$managed = true;
        \SpacePress\Env::isolate($variables);
        self::boot($runtimeFile);
        $plugin = self::app($appDir, $appDir . '/' . basename($appDir) . '.php', $schema);
        if ($plugin === null) {
            throw new \RuntimeException('Cannot mount the managed SpacePress app; check platform requirements');
        }
        return $plugin;
    }

    public static function managed(): bool
    {
        return self::$managed;
    }

    /**
     * Whether this request runs the install checks: a plugin-installed site whose install marker
     * doesn't match this build ({@see InstallMarker}). A managed build installs only from initialize().
     */
    public static function installs(): bool
    {
        return !self::$managed && !InstallMarker::current();
    }

    /**
     * The host calls this on the candidate before exposing its immutable URL. `$drops` are the
     * publisher's `--drop` confirmations (`['likes' => 1204]`); a refused schema change throws
     * SchemaRefused with every refusal and its fix, before any table changes.
     *
     * @param array<string, int> $drops
     */
    public static function initialize(array $drops = []): array
    {
        if (!self::$managed || self::$plugin === null) {
            throw new \LogicException('Mount a managed app before initializing it');
        }
        $problems = Platform::problems(self::$plugin->schema->locales() !== null);
        if ($problems !== []) {
            throw new \RuntimeException(implode('; ', $problems));
        }
        global $wpdb;
        Migrator::locked(new Connection($wpdb), 'sp_runtime_tables', [self::class, 'applySystemTables']);
        $applied = self::$plugin->initialize($drops);
        self::install();

        return $applied;
    }

    /**
     * What a plugin-installed site installs on requests and a managed build installs only here, once
     * storage matches the build: each step is idempotent and checks whether it is already done.
     */
    private static function install(): void
    {
        \SpacePress\RuntimeSchema\Surfaces::grant();
        Uploads::install();
        if (self::$plugin->schema->files() !== []) {
            WpFiles::install();
        }
        if (function_exists('as_schedule_recurring_action')) {
            Jobs\ActionSchedulerQueue::install();
            Guests::install();
        }
        \SpacePress\Commerce\Module::initialize(self::$plugin);
        ContentPages::install();
        RoutePages::install();
        \SpacePress\Search\Index::reindexChanged();
        self::installRewrites();
        // The Pages log and swallow a failure on a request (the next one retries); initialize's
        // receipt must mean everything is installed, so what is still to do is a failure here.
        $left = self::installPlan();
        if ($left !== []) {
            throw new \RuntimeException('SpacePress could not install ' . implode(', ', array_column($left, 'id')) . '; see the PHP error log');
        }
    }

    /**
     * Whether the stored rewrite rules need rebuilding for this build: kind URL patterns other than the
     * ones recorded, rules SpacePress didn't build (or none), or SpacePress Admin's rule not recorded for its version.
     */
    private static function rewritesBehind(): bool
    {
        return !KindUrls::recorded() || !Hardening::rulesCurrent()
            || (function_exists('sp_admin_rewrite_recorded') && !sp_admin_rewrite_recorded());
    }

    /**
     * One rebuild of the stored rewrite rules, with every rule this build registers (kind URLs, SpacePress
     * Admin's): first the `rewrite_rules` option (and SpacePress's fingerprint of it), then, where WordPress
     * writes one, the server's `.htaccess`. Two steps because WP-CLI, where a host initializes, has no
     * Apache module to ask: WordPress there never writes `.htaccess`, so the file is written only when
     * the site's own web server is Apache with mod_rewrite (`$is_apache`, set from the server software).
     * A server that never reads the file sends `/wp-admin/new/` to index.php, where SpacePress Admin answers.
     *
     * @return array{rules: bool, htaccess: ?bool} what was written; `htaccess` null when not attempted
     */
    public static function installRewrites(): array
    {
        if (!self::rewritesBehind()) {
            return ['rules' => false, 'htaccess' => null];
        }
        // The rules this build registers on `init`, which has run: KindUrls, SpacePress Admin's.
        if (function_exists('sp_admin_add_rewrite_rule')) {
            sp_admin_add_rewrite_rule();
        }
        flush_rewrite_rules(false);
        KindUrls::record();
        $htaccess = null;
        if (function_exists('sp_admin_add_rewrite_rule')) {
            $htaccess = self::writeHtaccess();
            sp_admin_record_rewrite();
        }

        return ['rules' => true, 'htaccess' => $htaccess];
    }

    /**
     * `.htaccess` with the rules just stored, as WordPress writes it from a web request under Apache
     * with mod_rewrite. From WP-CLI, `got_mod_rewrite()` is false (no Apache module to ask), so it says
     * yes when the site's `.htaccess` already holds WordPress's own block: core put it there from a web
     * request under Apache. Anywhere else it writes nothing.
     */
    private static function writeHtaccess(): bool
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        $file = get_home_path() . '.htaccess';
        $apache = is_file($file) && str_contains((string) @file_get_contents($file), '# BEGIN WordPress');
        $force = static fn (bool $got): bool => $got || $apache;
        add_filter('got_rewrite', $force);
        try {
            return save_mod_rewrite_rules() === true;
        } finally {
            remove_filter('got_rewrite', $force);
        }
    }

    /**
     * One step of a plan: `kind` says what it does (`page.create`, `rewrite-rules`, …; the set is open:
     * a reader must accept kinds it doesn't know, data migrations among the expected ones), `id` names
     * it uniquely within the plan, and `state` is `pending` until a deployment records its progress.
     * Anything else is the kind's own detail.
     *
     * @param array<string, mixed> $detail
     * @return array{kind: string, id: string, state: string}
     */
    public static function step(string $kind, string $id, array $detail = []): array
    {
        return ['kind' => $kind, 'id' => $id, 'state' => 'pending'] + $detail;
    }

    /**
     * The install steps {@see install()} would run for this build, computed without writing: Pages
     * (each created, changed, adopted, hidden, restored or trashed), the front-page options, and one
     * rebuild of the rewrite rules. An empty list means installing changes nothing.
     *
     * @return list<array>
     */
    public static function installPlan(): array
    {
        $steps = [...ContentPages::plan(), ...RoutePages::plan()];
        if (self::$plugin !== null) { $steps = [...$steps, ...\SpacePress\Commerce\Module::plan(self::$plugin)]; }
        if (class_exists(\SpacePress\Search\Index::class, false) && \SpacePress\Search\Index::shapeChanged()) {
            $steps[] = self::step('search-shape', 'search-shape');
        }
        if (self::rewritesBehind()) {
            $steps[] = self::step('rewrite-rules', 'rewrite-rules');
        }

        return $steps;
    }

    /**
     * What {@see initialize()} would run on this site for the mounted managed build, computed without
     * writing anything: the shared system tables, the identity schema, the app's migrations and the runtime-schema
     * comparison. Refusals (platform, schema check with `$drops`, runtime schema) are listed, all at
     * once; nothing is thrown for them.
     *
     * @param array<string, int> $drops
     * @return array{steps: list<array{kind: string, id: string, state: string}>, platform: list<string>, systemTables: ?array{from: mixed, to: string, steps: array<string, list<string>>}, identitySchema: ?array{from: int, to: int}, migrations: list<array>, install: list<array>, runtimeSchema: ?array, refusals: list<array>}
     */
    public static function inspect(array $drops = []): array
    {
        if (!self::$managed || self::$plugin === null) {
            throw new \LogicException('Mount a managed app before inspecting it');
        }
        global $wpdb;
        // Read past the object cache: another process may have written since this request started.
        $option = static function (string $name) use ($wpdb): mixed {
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));

            return $value === null ? null : maybe_unserialize($value);
        };
        $platform = Platform::problems(self::$plugin->schema->locales() !== null);
        $recorded = $option('sp_runtime_tables');
        $identity = null;
        if (class_exists(\SpacePress\Identity\Schema::class)) {
            $from = (int) ($option('sp_identity_schema_version') ?? 0);
            $identity = $from !== \SpacePress\Identity\Schema::VERSION ? ['from' => $from, 'to' => \SpacePress\Identity\Schema::VERSION] : null;
        }
        $app = self::$plugin->inspect($drops);
        $install = self::installPlan();
        // Every step initialize would run, in order, as one open set: whatever reads it (the deployment
        // that decides whether a plan is empty) counts kinds it doesn't know too.
        $steps = [];
        if ($recorded !== self::tablesVersion()) {
            $steps[] = self::step('system-tables', 'system-tables', ['to' => self::tablesVersion()]);
        }
        if ($identity !== null) {
            $steps[] = self::step('identity-schema', 'identity-schema', $identity);
        }
        foreach ($app['migrations'] as $migration) {
            $steps[] = self::step('migration', 'migration:' . $migration['id'], ['sql' => $migration['sql']]);
        }

        return [
            'steps' => [...$steps, ...$install],
            'platform' => $platform,
            'systemTables' => $recorded === self::tablesVersion() ? null : ['from' => $recorded, 'to' => self::tablesVersion(), 'steps' => \SpacePress\SystemTables::plan()],
            'identitySchema' => $identity,
            'migrations' => $app['migrations'],
            'install' => $install,
            'runtimeSchema' => $app['runtimeSchema'],
            'refusals' => array_merge(array_map(static fn (string $p): array => ['code' => 'platform', 'message' => $p], $platform), $app['refusals']),
        ];
    }

    public static function activate(): void
    {
        $problems = Platform::problems();
        if ($problems !== []) {
            wp_die(esc_html('SpacePress: ' . implode('; ', $problems)), 'SpacePress', ['back_link' => true]);
        }
        // A site still under the pre-release name installs once its app registers and moves it.
        if (BrandUpgrade::pending()) {
            return;
        }
        global $wpdb;
        InstallMarker::forget();
        Migrator::locked(new Connection($wpdb), 'sp_runtime_tables', [self::class, 'applySystemTables']);
    }

    /** Shared system tables (jobs, receipts, …): idempotent DDL, recorded by tablesVersion(). */
    public static function applySystemTables(): void
    {
        global $wpdb;
        $schema = new \SpacePress\Schema(['version' => 5, 'runtime' => self::SERIES]);
        \SpacePress\SystemTables::apply(CompositeStore::create($schema, new Connection($wpdb)));
        // Features finish their own install here (private files write their deny rules and probe).
        do_action('sp_runtime_tables_installed');
        update_option('sp_runtime_tables', self::tablesVersion(), false);
        InstallMarker::forget();
    }

    /** `<series>+<fingerprint>`: a runtime that registers another system table or step upgrades sites. */
    public static function tablesVersion(): string
    {
        return self::SERIES . '+' . \SpacePress\SystemTables::fingerprint();
    }

    public static function app(string $dir, string $mainFile, ?\SpacePress\Schema $schema = null): ?Plugin
    {
        if (self::$appFile === $mainFile) {
            return self::$plugin;
        }
        try {
            if (self::$plugin !== null) {
                throw new \RuntimeException('only one SpacePress app can be active; deactivate the other app first');
            }
            $schema ??= \SpacePress\Schema::load($dir . '/sp/app/schema.php');
            $problems = Platform::problems($schema->locales() !== null);
            if ($problems !== []) {
                throw new \RuntimeException(implode('; ', $problems));
            }
            // A plugin-installed site moves storage it kept under the pre-release name before the app
            // reads any of it; a managed build does it when the host initializes it.
            if (!self::$managed && BrandUpgrade::pending()) {
                BrandUpgrade::ensure($schema);
                // Identity waited for the move to install its storage.
                class_exists(\SpacePress\Identity\Plugin::class, false) && \SpacePress\Identity\Plugin::install();
            }
            self::$plugin = new Plugin($dir, $mainFile, $schema);
            self::$appFile = $mainFile;
            \SpacePress\Extensions::schemaDescribe(static function (array $description): array {
                $description['runtime']['wp'] = Platform::versions();
                $description['runtime']['wp']['hardening'] = Hardening::switches(self::$plugin->schema);
                $description['runtime']['wp']['publicAuthors'] = Authors::public(self::$plugin->schema);
                // The names of what is loaded are for the site's administrators (and `spacepress doctor`
                // signed in as one); anyone else learns only whether the site is sealed.
                $sealed = Sealed::report();
                if ($sealed !== null) {
                    $description['runtime']['wp']['sealed'] = current_user_can('activate_plugins') ? $sealed : ['enforced' => $sealed['enforced']];
                }
                $description['runtime']['wp']['incompatibleTables'] = self::$plugin->storageProblems();
                $description['runtime']['identity'] = self::identity();
                $description['runtime']['version'] = self::VERSION;
                $description['runtime']['pendingMigrations'] = self::$plugin->pendingMigrations();
                $description['runtime']['languages'] = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
                return $description;
            });
            return self::$plugin;
        } catch (\Throwable $error) {
            self::notice($error->getMessage());
            return null;
        }
    }

    /** The runtime plugin's main file (`spacepress-runtime/spacepress-runtime.php`, or the build store's mount). */
    public static function file(): string
    {
        return self::$runtimeFile ?? dirname(__DIR__, 2) . '/spacepress-runtime.php';
    }

    public static function unavailable(): ?string
    {
        return self::$unavailable;
    }

    public static function plugin(): ?Plugin
    {
        return self::$plugin;
    }

    /**
     * Whether this request mounted its build to inspect it (`inspect` in the loader): the app's own
     * code doesn't run, and nothing that boots it schedules work or writes to the site.
     */
    public static function inspecting(): bool
    {
        return class_exists(\SpacePress\Loader\Loader::class, false) && \SpacePress\Loader\Loader::inspecting();
    }

    public static function identity(): bool
    {
        return class_exists(\SpacePress\Identity\Plugin::class) && defined(\SpacePress\Identity\Plugin::class . '::VERSION')
            && constant(\SpacePress\Identity\Plugin::class . '::VERSION') === self::VERSION;
    }

    private static function notice(string $problem): void
    {
        add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('SpacePress: ' . $problem . '. Rebuild with spacepress ' . self::SERIES . '.x.')));
    }
}
