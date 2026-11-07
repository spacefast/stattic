<?php
declare(strict_types=1);
namespace Zero\Wp;

/** The single runtime and app registry; optional features attach to existing seams. */
final class Runtime
{
    public const SERIES = \Zero\Schema::SERIES;
    public const VERSION = '0.3.0';
    private static ?Plugin $plugin = null;
    private static ?string $appFile = null;
    private static bool $booted = false;
    private static bool $managed = false;
    /** Why the shared system tables are behind this runtime (failed or contended upgrade), else null. */
    private static ?string $unavailable = null;

    public static function boot(string $runtimeFile): void
    {
        if (self::$booted) {
            return;
        }
        if (version_compare((string) get_bloginfo('version'), Platform::WORDPRESS, '<') || version_compare(PHP_VERSION, Platform::PHP, '<')) {
            self::notice('WordPress ' . Platform::WORDPRESS . '+ and PHP ' . Platform::PHP . '+ are required');
            return;
        }
        self::$booted = true;
        register_activation_hook($runtimeFile, [self::class, 'activate']);
        // As with app migrations: a runtime updated by copying files in sets up its tables on the
        // first request of any kind, once, under a lock.
        add_action('init', static function (): void {
            if (self::$managed || get_option('zero_runtime_tables') === self::tablesVersion() || Platform::problems() !== []) {
                return;
            }
            global $wpdb;
            $current = static function (): bool {
                wp_cache_delete('zero_runtime_tables', 'options');
                return get_option('zero_runtime_tables') === self::tablesVersion();
            };
            self::$unavailable = Migrator::upgrade(new Connection($wpdb), 'zero_runtime_tables', $current, [self::class, 'applySystemTables']);
        }, 1);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('zero', static function (array $args, array $flags): void {
                if (self::$plugin === null) {
                    \WP_CLI::error('No compatible Zero app is active.');
                }
                // While storage is behind, only `migrate` runs; every other command (legacy migration
                // included) would write against tables that don't match the build.
                if (($args[0] ?? '') !== 'migrate' && self::$plugin->unavailable() !== null) {
                    \WP_CLI::line((string) \Zero\Js::jsonStringify(['error' => \Zero\App::problem(new \Zero\HttpError(503, 'unavailable', (string) self::$plugin->unavailable()))]));
                    \WP_CLI::halt(1);
                }
                if (($args[0] ?? '') === 'seed') {
                    $code = \Zero\Sync\Seed::wpCli(self::$plugin, $args, $flags);
                    $code === 0 || \WP_CLI::halt($code);
                    return;
                }
                if (\Zero\Wp\Legacy\Module::handles($args)) {
                    $code = \Zero\Wp\Legacy\Module::wpCli(self::$plugin, $args, $flags);
                    $code === 0 || \WP_CLI::halt($code);
                    return;
                }
                self::$plugin->cli($args, $flags);
            });
        }
        do_action('zero_runtime_loaded');
    }

    /** Mount exactly one version after platform plugins load. Visitors never migrate managed apps. */
    public static function mount(string $runtimeFile, string $appDir, array $variables = []): Plugin
    {
        if (self::$booted && !self::$managed) {
            throw new \RuntimeException('A site-wide Zero runtime is already loaded');
        }
        self::$managed = true;
        \Zero\Env::isolate($variables);
        self::boot($runtimeFile);
        $plugin = self::app($appDir, $appDir . '/' . basename($appDir) . '.php');
        if ($plugin === null) {
            throw new \RuntimeException('Cannot mount the managed Zero app; check platform requirements');
        }
        return $plugin;
    }

    public static function managed(): bool
    {
        return self::$managed;
    }

    /** The host calls this on the candidate before exposing its immutable URL. */
    public static function initialize(): array
    {
        if (!self::$managed || self::$plugin === null) {
            throw new \LogicException('Mount a managed app before initializing it');
        }
        $problems = Platform::problems(self::$plugin->schema->locales() !== null);
        if ($problems !== []) {
            throw new \RuntimeException(implode('; ', $problems));
        }
        global $wpdb;
        Migrator::locked(new Connection($wpdb), 'zero_runtime_tables', [self::class, 'applySystemTables']);
        return self::$plugin->initialize();
    }

    public static function activate(): void
    {
        $problems = Platform::problems();
        if ($problems !== []) {
            wp_die(esc_html('Zero: ' . implode('; ', $problems)), 'Zero', ['back_link' => true]);
        }
        global $wpdb;
        Migrator::locked(new Connection($wpdb), 'zero_runtime_tables', [self::class, 'applySystemTables']);
    }

    /** Shared system tables (jobs, outbox, receipts, …): idempotent DDL, recorded by tablesVersion(). */
    public static function applySystemTables(): void
    {
        global $wpdb;
        $schema = new \Zero\Schema(['version' => 3, 'runtime' => self::SERIES]);
        \Zero\SystemTables::apply(CompositeStore::create($schema, new Connection($wpdb)));
        // Features finish their own install here (private files write their deny rules and probe).
        do_action('zero_runtime_tables_installed');
        update_option('zero_runtime_tables', self::tablesVersion(), false);
    }

    /** `<series>+<fingerprint>`: a runtime that registers another system table or step upgrades sites. */
    public static function tablesVersion(): string
    {
        return self::SERIES . '+' . \Zero\SystemTables::fingerprint();
    }

    public static function app(string $dir, string $mainFile): ?Plugin
    {
        if (self::$appFile === $mainFile) {
            return self::$plugin;
        }
        try {
            if (self::$plugin !== null) {
                throw new \RuntimeException('only one Zero app can be active; deactivate the other app first');
            }
            $schema = \Zero\Schema::load($dir . '/zero/app/schema.php');
            $problems = Platform::problems($schema->locales() !== null);
            if ($problems !== []) {
                throw new \RuntimeException(implode('; ', $problems));
            }
            self::$plugin = new Plugin($dir, $mainFile);
            self::$appFile = $mainFile;
            \Zero\Extensions::schemaDescribe(static function (array $description): array {
                $description['runtime']['wp'] = Platform::versions();
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

    public static function unavailable(): ?string
    {
        return self::$unavailable;
    }

    public static function plugin(): ?Plugin
    {
        return self::$plugin;
    }

    public static function identity(): bool
    {
        return class_exists(\Zero\Identity\Plugin::class) && defined(\Zero\Identity\Plugin::class . '::VERSION')
            && constant(\Zero\Identity\Plugin::class . '::VERSION') === self::VERSION;
    }

    private static function notice(string $problem): void
    {
        add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('Zero: ' . $problem . '. Rebuild with zero ' . self::SERIES . '.x.')));
    }
}
