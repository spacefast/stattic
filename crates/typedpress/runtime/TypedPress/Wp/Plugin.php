<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\App;
use TypedPress\Db;
use TypedPress\HttpError;
use TypedPress\Schema;

/**
 * The app bootstrap (§4.9). Runtime::app constructs it after compatibility checks;
 * the App (Db pipeline + stores) is booted lazily, the first time a request or an editor save needs it.
 */
final class Plugin
{
    public const MIN_WP = Platform::WORDPRESS;
    /** What callers outside wp-admin's notices see while storage is behind; the detail is for admins. */
    public const UNAVAILABLE = 'Storage is being upgraded; try again shortly';
    public const MIN_SCF = Platform::PLUGINS['secure-custom-fields']['version'];

    private static ?self $instance = null;
    private static ?bool $keyed = null;

    private ?App $app = null;
    private string $mainFile;
    private ?Connection $connection = null;
    /** Why storage is behind this build (a failed or contended upgrade); TypedPress's REST refuses meanwhile. */
    private ?string $unavailable = null;
    /** @var array<int, ?array> post id → row before an editor update (null = treat as create) */
    private array $before = [];
    /** @var array<int, array{string, array}> post id → [collection, row] captured before an editor delete */
    private array $deleting = [];
    /** @var array<int, true> posts WP cron is publishing right now (`publish_future_post`) */
    private array $promoting = [];
    /** @var array<int, list<string>> post id → columns fillEditorDefaults generated in this save */
    private array $filled = [];
    /** @var array<string, int> `--drop` confirmations for this process's migration (SchemaCheck) */
    private array $drops = [];

    /** A standalone deploy can replace schema.php mid-request. A managed build's files are immutable. */
    private ?string $loadedHash = null;

    public function __construct(public readonly string $dir, string $mainFile, public readonly Schema $schema)
    {
        if (!Runtime::managed()) {
            $this->buildHash();
        }
        self::$instance = $this;
        $this->mainFile = $mainFile;
        if ($this->schema->commerce() !== null) { \TypedPress\Commerce\Module::register($this); }
        Hardening::apply($schema);
        Sealed::apply($schema);
        if (!Runtime::managed()) {
            InstallMarker::register();
        }
        add_action('init', [$this, 'registerPostTypes'], 0);
        NativePosts::register($schema);
        add_action('acf/init', [$this, 'registerFields'], 5);
        if (($this->schema->auth()['collection'] ?? null) !== null) {
            add_action('acf/init', fn () => WpUserStore::registerFields($this->schema), 5);
            WpUserStore::trackRoles();
            // Users created outside TypedPress become members with the collection's defaults.
            add_action('user_register', function (int $userId): void {
                if (!$this->connection()->busy()) {
                    WpUserStore::fillDefaults($this->schema, $userId);
                }
            });
        }
        CoreAccess::register();
        AgentScope::register();
        $this->registerEditorSave();
        if (($this->schema->auth()['collection'] ?? null) !== null) {
            ProfileForm::register();
            UserMeta::register();
        }
        IdentityBridge::hook($this);
        add_action('rest_api_init', fn () => TableController::registerAll($this));
        // While storage is behind, TypedPress collections' posts can't be written by any path (core REST,
        // wp-admin, other plugins): their hooks would run against tables the build doesn't match.
        // Last, so no later callback (SCF's `_acf_changed` one returns false) can undo the refusal.
        add_filter('wp_insert_post_empty_content', fn ($empty, $post) => $empty || ($this->unavailable() !== null && $this->touchesCollection($post)), PHP_INT_MAX, 2);
        $refuseDelete = fn ($check, $post) => $this->unavailable() !== null && $post instanceof \WP_Post && $this->touchesCollection((array) $post) ? false : $check;
        // WordPress empties its trash on a schedule (wp_scheduled_delete), with nobody there to read a
        // refusal: a First item's before half runs here, where core still lets a delete be declined,
        // so a refused item is skipped and logged and the run goes on.
        add_filter('pre_delete_post', fn ($check, $post) => $refuseDelete($check, $post) ?? $this->prepareDelete($check, $post), PHP_INT_MAX, 2);
        // The run's last prepared delete, if a later filter declined it, ends with the run.
        add_action('wp_scheduled_delete', [EditorSave::class, 'abandonPrepared'], PHP_INT_MAX, 0);
        add_filter('pre_trash_post', $refuseDelete, PHP_INT_MAX, 2);
        // wp_untrash_post deletes the trash metadata before its update; refuse before that.
        add_filter('pre_untrash_post', fn ($check, $post) => $this->unavailable() !== null && $post instanceof \WP_Post && $this->touchesCollection((array) $post) ? false : $check, PHP_INT_MAX, 2);
        add_filter('rest_pre_dispatch', [$this, 'refuseCoreWrites'], 10, 3);
        // wp_delete_user deletes the user (and identity its credentials) whatever happens to their
        // posts, so refuse deleting anyone who owns TypedPress posts before any of it starts.
        add_action('delete_user', function (int $id): void {
            if ($this->unavailable() !== null && $this->ownsCollectionPosts($id)) {
                wp_die(esc_html(self::UNAVAILABLE), 'TypedPress', ['response' => 503, 'back_link' => true]);
            }
        }, PHP_INT_MIN);
        // A scheduled TypedPress post that falls due while storage is behind publishes once it has caught
        // up: put its cron event back a minute instead of letting core publish it now.
        // Core's callback is unhooked for this one event and restored right after it, so other posts
        // in the same cron run still publish.
        add_action('publish_future_post', function (int $id): void {
            if ($this->unavailable() !== null && $this->isCollectionType((string) get_post_type($id))) {
                remove_action('publish_future_post', 'check_and_publish_future_post');
                add_action('publish_future_post', static function (): void {
                    add_action('publish_future_post', 'check_and_publish_future_post');
                }, PHP_INT_MAX);
                wp_schedule_single_event(time() + 60, 'publish_future_post', [$id]);
            }
        }, 0);
        add_action('current_screen', function (\WP_Screen $screen): void {
            if ($screen->base === 'post' && $this->unavailable() !== null && $this->isCollectionType((string) $screen->post_type)) {
                wp_die(esc_html(self::UNAVAILABLE), 'TypedPress', ['response' => 503, 'back_link' => true]);
            }
        });
        Facade::register($this);
        AppRouter::register($this);
        BlockTemplates::register($this);
        ThemeStyles::register($this);
        \TypedPress\Render\Prebuilt::boot($this);
        PublicFiles::register($this);
        Comments::register($this);
        // Code blocks: highlighted with their title and marked lines, grouped into tabs (no script).
        add_filter('render_block_core/code', [\TypedPress\Content\Code::class, 'render'], 10, 2);
        add_filter('the_content', [\TypedPress\Content\Code::class, 'groups'], 20);
        // Components content may use as blocks (tp/components: `export const blocks`).
        add_action('init', [\TypedPress\Content\Blocks::class, 'register'], 5);
        ContentPages::register($this);
        RoutePages::register($this);
        KindUrls::register($this);
        MarkdownTwins::register($this->mainFile);
        Sitemap::register($this);
        SearchHandler::register($this);
        Purges::register($this);
        Staged::register($this);
        add_filter('rest_pre_dispatch', [Rev::class, 'restLock'], 20, 3);
        // A build mounted to inspect it (Loader::load `inspect`) runs none of the app's own code.
        if (!Runtime::inspecting()) {
            Hooks::register($this);
        }
        add_action('admin_menu', [$this, 'adminMenu'], 9);
        add_action('tp_admin_screens', [$this, 'registerScreens']);
        add_action('tp_admin_dashboards', [$this, 'registerDashboards']);
        // A plugin updated by copying files in has no activation; the first request of any kind
        // (REST, front end, cron, CLI, admin) applies its pending migrations before TypedPress serves it.
        add_action('init', [$this, 'maybeMigrate'], 1);
        if (!Runtime::managed() && is_file($this->dir . '/tp/app/seed-version.php')) {
            // Commerce finishes setting up its kinds at init 20.
            add_action('init', [$this, 'installSeed'], 21);
        }

        // Editor saves (wp-admin, core /wp/v2/<type>): hooks run after the post and its fields are saved.
        // Generated values are filled before core snapshots its revision (wp_after_insert_post, 9).
        add_action('pre_post_update', [$this, 'capturePrevious'], 10, 1);
        add_action('wp_after_insert_post', [$this, 'fillEditorDefaults'], 8, 4);
        add_action('wp_after_insert_post', [$this, 'afterEditorSave'], 20, 4);
        // §6.3: WP cron's scheduled publish is promotion, not an edit: no hooks, no generated values, no revision.
        add_action('publish_future_post', [$this, 'promoting'], 9, 1);
        add_action('publish_future_post', [$this, 'promoted'], 11, 1);
        add_filter('wp_revisions_to_keep', [$this, 'revisionsToKeep'], PHP_INT_MAX, 2);
        add_filter('wp_save_post_revision_post_has_changed', [$this, 'revisionHasChanged'], 10, 3);
        add_action('_wp_put_post_revision', [$this, 'snapshotRevision'], 10, 2);
        add_action('before_delete_post', [$this, 'beforeEditorDelete'], 10, 2);
        add_action('deleted_post', [$this, 'afterEditorDelete'], 10, 2);
    }

    private function registerEditorSave(): void
    {
        add_filter('pre_wp_unique_post_slug', [EditorSave::class, 'uniqueSlug'], PHP_INT_MAX, 5);
        add_filter('wp_insert_post_data', [EditorSave::class, 'begin'], PHP_INT_MAX, 4);
        add_action('wp_insert_post', [EditorSave::class, 'inserted'], 0, 1);
        // After fillEditorDefaults (8) and core's revision (9), before TypedPress's after hooks (20).
        add_action('wp_after_insert_post', [EditorSave::class, 'check'], 9, 1);
        add_action('wp_after_insert_post', [EditorSave::class, 'end'], PHP_INT_MAX, 1);
        add_action('check_admin_referer', [EditorSave::class, 'beginRestore'], 10, 2);
        // A permanent delete: beforeDelete, FK actions, core's delete and afterDelete, all or nothing.
        add_action('before_delete_post', [EditorSave::class, 'beginDelete'], 0, 1);
        add_action('deleted_post', [EditorSave::class, 'endDelete'], PHP_INT_MAX, 1);
        add_action('wp_restore_post_revision', [EditorSave::class, 'endRestore'], PHP_INT_MAX, 1);
        // Keep the rollback in its original shutdown order without autoloading the editor on reads.
        register_shutdown_function(static function (): void {
            if (class_exists(EditorSave::class, false)) {
                EditorSave::shutdown();
            }
        });
    }

    /**
     * `wp typedpress <find|insert|update|delete|action> <name> [json...]` and `wp typedpress migrate`: the
     * standalone CLI's trusted surface (no access checks) on WordPress.
     *
     * @param list<string> $args
     */
    public function cli(array $args, array $assocArgs = []): void
    {
        foreach ($assocArgs as $name => $value) {
            $args[] = '--' . $name . ($value === true ? '' : '=' . ($value === false ? 'false' : (string) $value));
        }
        // (Runtime's `wp typedpress` dispatcher already refused everything but `migrate` while storage is behind.)
        if (($args[0] ?? '') === 'migrate') {
            try {
                // `--drop=likes=1204,likes.note=12`: WP-CLI keeps the last of repeated flags, so values are comma-separated.
                $this->drops = SchemaCheck::drops(array_key_exists('drop', $assocArgs) ? [$assocArgs['drop']] : []);
                $mark = $assocArgs['mark-applied'] ?? null;
                if (is_string($mark)) {
                    Migrator::locked($this->connection(), $this->schemaOption(), fn () => $this->migrator()->markApplied($mark));
                }
                $applied = $this->migrateLocked();
                // With runtimeSchema: what this build leaves to the site's own schema versions (spec §2.10).
                $runtime = SchemaVersions::of($this)?->deployCheck();
                [$code, $out] = [0, \TypedPress\Js::jsonStringify(['data' => ['applied' => $applied] + ($runtime === null ? [] : ['runtimeSchema' => array_diff_key($runtime, ['refusals' => true])])])];
            } catch (\Throwable $e) {
                [$code, $out] = [1, \TypedPress\Js::jsonStringify(['error' => \TypedPress\App::problem($e)])];
            }
        } else {
            [$code, $out] = $this->app()->cli($args);
        }
        \WP_CLI::line((string) $out);
        if ($code !== 0) {
            \WP_CLI::halt($code);
        }
    }

    /** Null when storage matches this build; otherwise why TypedPress operations are refused for now. */
    public function unavailable(): ?string
    {
        return Runtime::unavailable() ?? $this->unavailable;
    }

    /** A later typedpress-request step (Polylang languages) failed: refuse TypedPress operations for this request too. */
    public function refuse(string $problem): void
    {
        $this->unavailable ??= $problem;
    }

    /** rest_pre_dispatch: core /wp/v2 writes to a TypedPress collection get a 503 while storage is behind. */
    public function refuseCoreWrites(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($result !== null || $request->get_method() === 'GET' || $this->unavailable() === null) {
            return $result;
        }
        foreach ($this->postBacked() as $spec) {
            // WordPress matches routes case-insensitively, so this does too.
            $object = get_post_type_object($spec['wp']['postType']);
            if (preg_match('#^/wp/v2/' . preg_quote((string) ($object?->rest_base ?: $spec['wp']['postType']), '#') . '(/|$)#i', $request->get_route())) {
                return new \WP_Error('tp_unavailable', self::UNAVAILABLE, ['status' => 503]);
            }
        }

        return $result;
    }

    /**
     * A write that touches a TypedPress collection: the incoming post is one (or a revision/autosave of one),
     * or it updates a post that is one now (so changing its type can't move it out of the collection).
     */
    private function touchesCollection(array $post): bool
    {
        // The incoming post and, for an update, the post as stored: type and parent from both, so
        // neither a new type nor a new parent can move a post or revision out of a collection.
        $candidates = [[(string) ($post['post_type'] ?? ''), (int) ($post['post_parent'] ?? 0)]];
        $stored = (int) ($post['ID'] ?? 0) > 0 ? get_post((int) $post['ID']) : null;
        if ($stored instanceof \WP_Post) {
            $candidates[] = [$stored->post_type, (int) $stored->post_parent];
        }
        foreach ($candidates as [$type, $parent]) {
            if (in_array($type, ['revision'], true) && $parent > 0) {
                $type = (string) get_post_type($parent);
            }
            if ($this->isCollectionType($type)) {
                return true;
            }
        }

        return false;
    }

    private function ownsCollectionPosts(int $userId): bool
    {
        global $wpdb;
        $types = array_map(static fn (array $spec): string => (string) $spec['wp']['postType'], $this->postBacked());
        if ($types === []) {
            return false;
        }
        $in = implode(', ', array_fill(0, count($types), '%s'));

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ($in) LIMIT 1", $userId, ...$types));
    }

    private function isCollectionType(string $type): bool
    {
        foreach ($this->postBacked() as $spec) {
            if ($spec['wp']['postType'] === $type) {
                return true;
            }
        }

        return false;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function connection(): Connection
    {
        global $wpdb;

        return $this->connection ??= new Connection($wpdb);
    }

    /** A URL inside the app plugin's directory (its compiled client modules). */
    public function url(string $path): string
    {
        return plugins_url($path, $this->mainFile);
    }

    public function app(): App
    {
        if ($this->app === null) {
            $db = $this->connection();
            $store = CompositeStore::create($this->schema, $db);
            $this->app = App::boot($this->dir, $this->schema, $store, new WpAuth(), new WpMedia($db, $store->dialect()));
            \TypedPress\Auth\Module::use(IdentityBridge::available() ? new IdentityBridge($db) : null);
        }

        return $this->app;
    }

    // ================================================================ activation

    public function activate(): void
    {
        InstallMarker::forget();
        \TypedPress\Sync\Seed::retry($this);
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        try {
            $this->migrateLocked();
        } catch (SchemaRefused $refused) {
            // The plugin stays active with TypedPress's routes refusing, so `wp typedpress migrate --drop=…` can
            // confirm what was refused; deactivating would leave no way to run it. Each request
            // reports the refusal (see maybeMigrate).
            $this->unavailable = $this->remember($refused);
            $this->notice($this->unavailable);
        } catch (\Throwable $error) {
            deactivate_plugins(plugin_basename($this->mainFile));
            wp_die(esc_html($error->getMessage()), 'TypedPress', ['back_link' => true]);
        }
        // migrateLocked() already provisioned identity and backfilled members.
        PublicFiles::syncSymlinks($this);
    }

    /** Existing WordPress users are members too (§6.2): give them the member columns' defaults. */
    private function backfillMembers(): void
    {
        if (($this->schema->auth()['collection'] ?? null) === null) {
            return;
        }
        foreach (get_users(['fields' => 'ID', 'number' => -1]) as $id) {
            WpUserStore::fillDefaults($this->schema, (int) $id);
            WpUserStore::syncRole((int) $id);
        }
    }

    public function maybeMigrate(): void
    {
        // Storage from before the rename that hasn't moved yet: migrating now would create empty
        // tables under the names the data is about to take. The runtime reports why (Runtime::boot).
        if (!Runtime::installs() || ZeroNames::pending() || $this->current()) {
            return;
        }
        // A schema refusal holds until someone confirms or changes something: this build, or the
        // ledger (`wp typedpress migrate` records progress there). Requests meanwhile skip the lock and the
        // row counts and report it, rather than recounting large tables on every page load.
        $refused = get_option($this->refusalOption());
        if (is_array($refused) && ($refused['state'] ?? null) === $this->refusalState()) {
            $this->unavailable = (string) $refused['problem'];
            $this->notice($this->unavailable);

            return;
        }
        // Concurrent first requests queue on one lock; whoever gets it after the holder sees the
        // new hash. A failure or timeout leaves the hash stale, so the next request retries.
        $this->unavailable = Migrator::upgrade($this->connection(), $this->schemaOption(), fn () => $this->current(true), function (): void {
            try {
                $this->migrateNow();
            } catch (SchemaRefused $refused) {
                // upgrade() reports it (log and notice) from the message remember() builds.
                throw new \RuntimeException($this->remember($refused), 0, $refused);
            }
        });
    }

    public function installSeed(): void
    {
        if ($this->unavailable() !== null) {
            return;
        }
        try {
            // A refusal an earlier request found for this build: reported, not run again.
            $problem = \TypedPress\Sync\Seed::install($this, $this->dir . '/tp/app/seed-version.php');
            if ($problem === null) {
                return;
            }
        } catch (\Throwable $error) {
            $problem = $error->getMessage();
            InstallMarker::forget();
            error_log('[typedpress] ' . $problem);
        }
        $this->refuse($problem);
        $this->notice($problem);
    }

    /** Keeps a refusal for this build and ledger, with the command that confirms it; returns the report. */
    private function remember(SchemaRefused $refused): string
    {
        $drop = $refused->drops();
        $problem = 'Schema change refused: ' . $refused->getMessage()
            . ($drop === [] ? '' : ' To confirm: wp typedpress migrate --drop=' . implode(',', $drop));
        update_option($this->refusalOption(), ['state' => $this->refusalState(), 'problem' => $problem], false);

        return $problem;
    }

    private function notice(string $problem): void
    {
        add_action('admin_notices', static function () use ($problem): void {
            if (current_user_can('manage_options')) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('TypedPress: ' . $problem));
            }
        });
    }

    private function refusalOption(): string
    {
        return 'tp_' . $this->schema->name() . '_refused';
    }

    /** This build and the app's ledger rows: either changing means the refusal may no longer hold. */
    private function refusalState(): string
    {
        $migrator = $this->migrator();
        $done = $migrator->keyed() ? $migrator->applied() : [];

        return hash('sha256', $this->schemaHash() . "\0" . implode("\n", $done));
    }

    /**
     * Explicit host initialization; failures propagate without wp-admin or activation hooks.
     * `$drops` are the deploy's `--drop` confirmations (SchemaCheck::drops); a SchemaRefused carries
     * the refusals to report.
     *
     * @param array<string, int> $drops
     */
    public function initialize(array $drops = []): array
    {
        $this->drops = SchemaCheck::drops($drops);
        $bad = $this->storageProblems();
        if ($bad !== []) {
            throw new \RuntimeException('TypedPress requires InnoDB and utf8mb4: ' . implode(', ', $bad));
        }
        return $this->migrateLocked();
    }

    /**
     * What {@see initialize()} would do to this site's storage for this build, computed without
     * changing anything: the app's pending migrations after the deploy-time schema check with
     * `$drops`, and the runtime-schema comparison (read through {@see SchemaVersions::verify()}, which
     * never repairs the option). Refusals are returned, all of them at once, not thrown.
     *
     * @param array<string, int> $drops
     * @return array{migrations: list<array{id: string, sql: string, statements: list<int>}>, runtimeSchema: ?array, refusals: list<array>}
     */
    public function inspect(array $drops = []): array
    {
        $refusals = [];
        $runtime = null;
        try {
            $runtime = SchemaVersions::of($this)?->verify();
            $refusals = $runtime['refusals'] ?? [];
        } catch (\TypedPress\HttpError $e) {
            $refusals[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }
        $migrations = [];
        $bad = $this->storageProblems();
        if ($bad !== []) {
            $refusals[] = ['code' => 'incompatible_tables', 'message' => 'TypedPress requires InnoDB and utf8mb4: ' . implode(', ', $bad)];
        }
        try {
            $migrations = (new Migrator($this->connection(), $this->dir . '/tp/app/migrations', $this->schema, SchemaCheck::drops($drops)))->plan();
        } catch (SchemaRefused $refused) {
            array_push($refusals, ...$refused->refusals);
        } catch (\TypedPress\HttpError $e) {
            // An existingTable this site may not bind (TypedPress\Wp\Guard), refused before anything is read.
            $refusals[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }

        return ['migrations' => $migrations, 'runtimeSchema' => $runtime === null ? null : array_diff_key($runtime, ['refusals' => true]), 'refusals' => $refusals];
    }

    /** `wp typedpress migrate` and activation: the same locked path as the first request. */
    private function migrateLocked(): array
    {
        return Migrator::locked($this->connection(), $this->schemaOption(), fn () => $this->migrateNow());
    }

    private function migrateNow(): array
    {
        InstallMarker::forget();
        // A build that reads a runtime field's stored values as another type is refused before any
        // table changes, with the rest of the deploy's refusals (spec §2.10).
        $runtime = SchemaVersions::of($this)?->deployCheck();
        if (($runtime['refusals'] ?? []) !== []) {
            throw new SchemaRefused(array_map(static fn (array $r): array => $r + ['rows' => 0], $runtime['refusals']));
        }
        $applied = $this->migrator()->migrate();
        NativePosts::adopt($this);
        if (($compiled = \TypedPress\RuntimeSchema\Loader::compiled()) !== null) {
            // `runtimeSchema`: runtime changes are checked against the fields of every build that ran here.
            \TypedPress\RuntimeSchema\Loader::rememberDeclared($compiled);
        }
        delete_option($this->refusalOption());
        IdentityBridge::provision();
        $this->backfillMembers();
        update_option($this->schemaOption(), $this->schemaHash(), false);

        return $applied;
    }

    /**
     * Storage matches this build: the schema hash is recorded and the ledger is keyed by app. A
     * runtime update alone (same app build, older ledger) is therefore an upgrade too; the per-request
     * check costs one cached option read, plus one information_schema read the first time per process.
     */
    /**
     * Storage matches this build: nothing refused or held back, and every migration file of the build
     * is in the ledger. A build whose migrations haven't all run (WP-CLI before `typedpress migrate`, a
     * refused or held-back build, a ledger rewound by hand) may name tables that don't exist yet.
     * One ledger read: call it where something is about to change, not on every request.
     */
    public function migrated(): bool
    {
        return $this->unavailable() === null && $this->pendingMigrations() === [];
    }

    /** Storage matches this build, read past the option cache: what the install marker records. */
    public function installed(): bool
    {
        return $this->current(true);
    }

    private function current(bool $fresh = false): bool
    {
        if ($fresh) {
            wp_cache_delete($this->schemaOption(), 'options');
            self::$keyed = null;
        }
        if (get_option($this->schemaOption()) !== $this->schemaHash()) {
            return false;
        }

        return self::$keyed ??= $this->migrator()->keyed();
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection(), $this->dir . '/tp/app/migrations', $this->schema, $this->drops);
    }

    public function pendingMigrations(): array
    {
        return $this->migrator()->pending();
    }

    public function storageProblems(): array
    {
        return $this->migrator()->nonInnoDb();
    }

    /**
     * The named lock a deploy holds while it checks and migrates storage. Applying a runtime schema
     * change takes the same lock (spec §2.10), so it never interleaves with a deploy.
     */
    public function schemaLock(): string
    {
        return $this->schemaOption();
    }

    /**
     * Whether the build this request loaded is still the one the site runs: the build hash the last
     * deploy recorded (migrateNow), read fresh. On a managed build store a deploy changes which
     * build's directory is mounted and never rewrites the file this request loaded, so the recorded
     * hash, not the file, says what is live. Also false when a deploy replaced the file in place.
     */
    public function buildIsCurrent(): bool
    {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete($this->schemaOption(), 'options');
        clearstatcache(true, $this->dir . '/tp/app/schema.php');

        $loaded = $this->buildHash();
        return get_option($this->schemaOption()) === $loaded && $this->schemaHash() === $loaded;
    }

    private function schemaOption(): string
    {
        return 'tp_' . $this->schema->name() . '_schema';
    }

    private function schemaHash(): string
    {
        return hash_file('sha256', $this->dir . '/tp/app/schema.php');
    }

    /** Identifies the compiled schema this request runs: what a runtime schema version was validated on. */
    public function buildHash(): string
    {
        return $this->loadedHash ??= (string) @hash_file('sha256', $this->dir . '/tp/app/schema.php');
    }

    /** The manifest for this exact immutable app tree, when the build loader mounted it. */
    public function mountedManifest(): ?array
    {
        if (!Runtime::managed() || !class_exists(\TypedPress\Loader\Loader::class, false)) {
            return null;
        }
        $build = \TypedPress\Loader\Loader::loaded();
        if ($build === null) {
            return null;
        }
        $manifest = $build['manifest'];
        $app = $build['root'] . '/app/' . $manifest['app'] . '/plugins/typedpress-app-' . $manifest['name'];
        return $this->dir === $app ? $manifest : null;
    }

    // ================================================================ registration

    public function registerPostTypes(): void
    {
        foreach ($this->postBacked() as $name => $spec) {
            if ($spec['native'] ?? false) {
                // WordPress's own posts (`wp.posts.extend()`): core registers the type; the app's fields
                // are its post meta, and NativePosts gives core's type TypedPress's REST controller.
                PostMeta::register($spec);
                continue;
            }
            $label = (string) ($spec['label'] ?? $name);
            $type = $spec['wp']['postType'];
            register_post_type($type, [
                'label' => $label,
                'labels' => ['name' => $label, 'singular_name' => $label, 'menu_name' => $label, 'all_items' => $label],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_rest' => true,
                'rest_base' => $type,
                'menu_icon' => 'dashicons-database',
                'supports' => array_values(array_filter(['title', ($spec['content'] ?? null) !== null ? 'editor' : null, ($spec['revisions'] ?? false) ? 'revisions' : null, ($spec['comments'] ?? false) ? 'comments' : null, 'author', 'custom-fields', ($spec['hierarchical'] ?? false) ? 'page-attributes' : null])),
                // Singular differs from plural so `edit_<type>` stays a primitive cap. Every cap of the type
                // is answered from the collection's access rules (CoreAccess::mapMetaCap), not from roles.
                'capability_type' => [$type . '_item', $type],
                'capabilities' => ['create_posts' => 'create_' . $type],
                'map_meta_cap' => true,
                'rest_controller_class' => PostsController::class,
                // A hierarchical kind's items nest (`parent`, `order`), so the editor offers a parent and an order.
                'hierarchical' => (bool) ($spec['hierarchical'] ?? false),
                'has_archive' => false,
                'rewrite' => false,
                'query_var' => false,
                // A kind with a `url` has public items: WordPress resolves its URLs (KindUrls) and the
                // theme renders them; access rules still decide who reads which item.
                'publicly_queryable' => is_string($spec['url'] ?? null),
                // Core's content abilities (core/content-query, WordPress 7.2) read a post type's posts
                // directly, without the kind's access rules or field permissions. A kind's items reach
                // agents through the app's own abilities only.
                'show_in_abilities' => false,
            ]);
            // Set after registration too: a register_post_type_args filter that exposes every post type
            // must not expose a kind. Core resolves the exposure from this property on every call.
            $object = get_post_type_object($type);
            if ($object !== null) {
                $object->show_in_abilities = false;
            }
            PostMeta::register($spec);
        }
        $this->registerTaxonomies();
    }

    /**
     * `taxonomy()` kinds (docs/decisions/2026-11-11-authoring-surface.md, ruling 25): `tp_<name>` for
     * the kinds with its terms field, served by core at `/wp/v2/tp_<name>` ({@see TermsController}),
     * its fields as term meta. Every capability is answered from the taxonomy's rules
     * ({@see CoreAccess::mapMetaCap()}); a `url` makes it public, so core's sitemap and template
     * hierarchy serve its terms ({@see KindUrls}).
     */
    private function registerTaxonomies(): void
    {
        foreach ($this->schema->taxonomies() as $name => $spec) {
            if (isset($spec['commerce'])) { continue; }
            $label = (string) ($spec['label'] ?? $name);
            $taxonomy = (string) $spec['wp']['taxonomy'];
            $types = array_values(array_map(fn (string $kind): string => (string) $this->schema->collection($kind)['wp']['postType'], $spec['wp']['on'] ?? []));
            $public = is_string($spec['url'] ?? null);
            register_taxonomy($taxonomy, $types, [
                'label' => $label,
                'labels' => ['name' => $label, 'singular_name' => $label, 'menu_name' => $label],
                'public' => $public,
                'publicly_queryable' => $public,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_nav_menus' => $public,
                'show_admin_column' => true,
                'show_in_rest' => true,
                'rest_base' => $taxonomy,
                'rest_controller_class' => TermsController::class,
                'hierarchical' => (bool) ($spec['hierarchical'] ?? false),
                'rewrite' => false,
                'query_var' => false,
                // An item's terms keep the order they were written in (ruling 28).
                'sort' => true,
                'args' => ['orderby' => 'term_order'],
                'capabilities' => [
                    'manage_terms' => "manage_$taxonomy",
                    'edit_terms' => "edit_$taxonomy",
                    'delete_terms' => "delete_$taxonomy",
                    'assign_terms' => "assign_$taxonomy",
                ],
            ]);
            TermMeta::register($spec);
        }
    }

    public function registerFields(): void
    {
        Scf::register($this->schema);
        Scf::registerFilters();
    }

    /**
     * The app's own wp-admin menu: the parent of its globals (SCF options pages) and of TypedPress Admin's
     * table screens (runtime/admin/lib/screens.php), so it exists when the app has either.
     */
    public function adminMenu(): void
    {
        // The kinds TypedPress Admin gives a classic list screen (runtime/admin/lib/screens.php uses the same test).
        $tables = array_filter($this->schema->collections(), static fn (array $c): bool => !isset($c['wp']['postType']) && empty($c['auth'])
            && ($c['api']['list'] ?? true) !== false && ($c['agents'] ?? true) !== false);
        if ($this->schema->globals() === [] && ($tables === [] || !function_exists('tp_admin_register_screen'))) {
            return;
        }
        $slug = 'typedpress-' . $this->schema->name();
        $hook = add_menu_page(ucfirst($this->schema->name()), ucfirst($this->schema->name()), 'edit_others_posts', $slug, static fn () => self::renderAppMenuPage($slug), 'dashicons-admin-settings');
        // The entry itself has nothing of its own: with TypedPress Admin, it opens the app's collections there.
        add_action('load-' . $hook, static function (): void {
            if (function_exists('tp_admin_url')) {
                wp_safe_redirect(tp_admin_url('/collections'));
                exit;
            }
        });
    }

    /**
     * The app's own wp-admin page without TypedPress Admin (`admin: false`): a list of the pages under it,
     * the app's globals and table screens, so the entry is never an empty page.
     */
    public static function renderAppMenuPage(string $slug): void
    {
        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1><ul>';
        foreach ($GLOBALS['submenu'][$slug] ?? [] as $item) {
            if (($item[2] ?? $slug) === $slug || !current_user_can((string) ($item[1] ?? 'do_not_allow'))) {
                continue;
            }
            echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . rawurlencode((string) $item[2]))) . '">' . esc_html(wp_strip_all_tags((string) $item[0])) . '</a></li>';
        }
        echo '</ul></div>';
    }

    /**
     * The app's `adminScreen()` exports, compiled into schema.php, declared in TypedPress Admin's one
     * registry (runtime/admin/lib/screens.php) like any PHP screen. Without TypedPress Admin
     * (`admin: false`) nothing listens for `tp_admin_screens`, and the function is absent.
     */
    public function registerScreens(): void
    {
        if (!function_exists('tp_admin_register_screen')) {
            return;
        }
        foreach ($this->schema->screens() as $id => $screen) {
            tp_admin_register_screen((string) $id, $screen);
        }
    }

    /**
     * The app's `adminWidget()` and `adminDashboard()` exports, compiled into schema.php.
     */
    public function registerDashboards(): void
    {
        if (function_exists('tp_admin_register_declarative_widget')) {
            foreach ($this->schema->widgets() as $id => $widget) {
                tp_admin_register_declarative_widget((string) $id, $widget);
            }
        }
        if (function_exists('tp_admin_register_dashboard')) {
            foreach ($this->schema->dashboards() as $id => $dashboard) {
                tp_admin_register_dashboard((string) $id, $dashboard);
            }
        }
    }

    // ================================================================ editor hooks (§4.9)

    public function capturePrevious(int $postId): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || $this->connection()->busy() || $this->unavailable() !== null) {
            return;
        }
        $post = get_post($postId);
        // A trashed post is read too: its untrash is an update of a row that exists, never a create.
        $this->before[$postId] = $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            ? ($post->post_status === 'trash' ? $this->app()->db->editorRowIncludingTrash($collection, $postId) : $this->app()->db->editorRow($collection, $postId))
            : null;
    }

    /** Run $fn with trashed posts readable ({@see WpPostStore::withTrash()}): a move into the trash re-reads the row. */
    private function withTrash(callable $fn): mixed
    {
        $store = $this->app()->db->store();

        return $store instanceof CompositeStore ? $store->posts->withTrash($fn) : $fn();
    }

    public function afterEditorSave(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || isset($this->promoting[$postId]) || $this->connection()->busy() || $this->unavailable() !== null || in_array($post->post_status, ['auto-draft', 'inherit'], true)) {
            return;
        }
        $previous = $this->editorPrevious($postId, $before);
        unset($this->before[$postId]);
        // WordPress's trash is not a delete: the row stays and an untrash brings it back. Into or out of
        // the trash, the move is reported as the status change it is for visitors (a published item
        // leaving or entering the published set, as an unpublish or a publish is), and is nothing to
        // them otherwise. Delete hooks and referential actions run only on a permanent delete.
        $trash = $post->post_status === 'trash' || $before?->post_status === 'trash';
        if ($trash && ($before?->post_status === 'publish') === ($post->post_status === 'publish')) {
            return;
        }
        $app = $this->app();
        try {
            $save = fn () => $app->db->transaction(fn () => $app->db->afterEditorSave($collection, $postId, $previous, WpAuth::current()));
            $trash ? $this->withTrash($save) : $save();
        } catch (\Throwable $e) {
            EditorSave::fail($e);
        }
    }

    /**
     * `pre_delete_post` in cron: open the item's own transaction and run its before half (referential
     * actions, beforeDelete). Refused or thrown: that item rolls back, is logged, and core skips it
     * (false). Passed: before_delete_post finds the half done and doesn't run it again.
     */
    private function prepareDelete(mixed $check, mixed $post): mixed
    {
        if ($check !== null || !$post instanceof \WP_Post || !wp_doing_cron()) {
            return $check;
        }
        $collection = $this->collectionOf($post->ID);
        if ($collection === null || $this->connection()->busy() || $this->unavailable() !== null || !EditorSave::prepareDelete($collection, $post->ID)) {
            return $check;
        }
        try {
            $this->beforeEditorDelete($post->ID, $post, true);
        } catch (\Throwable $e) {
            EditorSave::abandonDelete();
            $problem = App::problem($e);
            error_log(sprintf('[typedpress] %s #%d stays in the trash: %s: %s', $collection, $post->ID, $problem['code'] ?? 'error', $problem['detail'] ?? $e->getMessage()));

            return false;
        }

        return $check;
    }

    public function beforeEditorDelete(int $postId, ?\WP_Post $post = null, bool $prepared = false): void
    {
        if (isset($this->deleting[$postId]) && !$prepared) {
            return; // its before half ran at pre_delete_post
        }
        $collection = $this->collectionOf($postId);
        if ($collection === null || $this->connection()->busy() || $this->unavailable() !== null) {
            return;
        }
        $db = $this->app()->db;
        // A post deleted from the trash is still the row it was: the delete runs its lifecycle here, once.
        $row = $db->editorRowIncludingTrash($collection, $postId);
        if ($row !== null) {
            $this->deleting[$postId] = [$collection, $row];
            try {
                $db->beforeEditorDelete($collection, $row, WpAuth::current());
            } catch (\Throwable $e) {
                unset($this->deleting[$postId]);
                if ($prepared) {
                    throw $e;
                }
                EditorSave::fail($e);
            }
        }
    }

    public function afterEditorDelete(int $postId, ?\WP_Post $post = null): void
    {
        if (!isset($this->deleting[$postId])) {
            return;
        }
        [$collection, $row] = $this->deleting[$postId];
        unset($this->deleting[$postId]);
        try {
            $this->app()->db->afterEditorDelete($collection, $row, WpAuth::current());
        } catch (\Throwable $e) {
            EditorSave::fail($e);
        }
    }

    public function promoting(mixed $post): void
    {
        $id = (int) ($post instanceof \WP_Post ? $post->ID : $post);
        if ($this->collectionOf($id) !== null) {
            $this->promoting[$id] = true;
        }
    }

    public function promoted(mixed $post): void
    {
        $id = (int) ($post instanceof \WP_Post ? $post->ID : $post);
        if (isset($this->promoting[$id]) && ($collection = $this->collectionOf($id)) !== null) {
            // Promotion runs no write hooks but `afterPublish`; watchers still need to see the row appear.
            $db = $this->app()->db;
            $db->current(static fn () => \TypedPress\Realtime\Module::promoted($collection, [$id]));
            try {
                $db->transaction(static fn () => $db->current(static fn () => $db->afterPromotion($collection, $id)));
            } catch (\Throwable $e) {
                // A scheduled publish has no request to refuse: core has published the post already.
                error_log('[typedpress] afterPublish failed for ' . $collection . ' #' . $id . ': ' . $e->getMessage());
            }
        }
        unset($this->promoting[$id]);
    }

    public function revisionsToKeep(int $keep, \WP_Post $post): int
    {
        return isset($this->promoting[$post->ID]) ? 0 : $keep;
    }

    /** Every revision of a First post, whoever saved it, carries the full row (§4.7). Autosaves keep their own meta. */
    public function snapshotRevision(int $revisionId, int $postId): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || wp_is_post_autosave($revisionId)) {
            return;
        }
        CompositeStore::create($this->schema, $this->connection())->posts->snapshotRevision($collection, $revisionId, $postId);
    }

    /** Core only compares title/content/excerpt; a change to any other First column also earns a revision. */
    public function revisionHasChanged(bool $changed, \WP_Post $latest, \WP_Post $post): bool
    {
        $collection = $this->collectionOf($post->ID);
        if ($changed || $collection === null) {
            return $changed;
        }

        return CompositeStore::create($this->schema, $this->connection())->posts->revisionDiffers($collection, $post->ID, $latest->ID);
    }

    /**
     * wp-admin knows nothing of TypedPress's generated values: on an editor save, write `default` for meta
     * columns that have none yet and `onUpdate` on every update, so editor rows decode like API rows.
     * SCF keeps an unchecked true_false and an emptied list as '', so for those types only a missing
     * meta row means "no value"; a '' boolean is stored as false, which is what SCF means by it.
     */
    public function fillEditorDefaults(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        \TypedPress\Js::atInstant(fn () => $this->fillEditorDefaultsAt($postId, $post, $update, $before));
    }

    private function fillEditorDefaultsAt(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || isset($this->promoting[$postId]) || $this->connection()->busy() || $this->unavailable() !== null || in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
            return;
        }
        $created = $this->editorPrevious($postId, $before) === null;
        $spec = $this->schema->collection($collection);
        $store = CompositeStore::create($this->schema, $this->connection())->posts;
        $values = [];
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            if (!$store->isMeta($spec, $col)) {
                continue;
            }
            $generation = !$created && isset($def['onUpdate']) ? $def['onUpdate'] : ($def['default'] ?? null);
            if ($generation !== null) {
                try {
                    $viewer = get_current_user_id() === 0 ? null : (CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended'));
                    $this->db()->requireGeneratedVisibility($collection, [$col => $generation], $viewer);
                } catch (HttpError $e) {
                    EditorSave::fail($e);
                }
            }
            $key = $spec['wp']['metaPrefix'] . $col;
            $exists = metadata_exists('post', $postId, $key);
            $raw = $exists ? get_post_meta($postId, $key, true) : null;
            $empty = !$exists || (!in_array($def['type'], ['boolean', 'select', 'mediaList'], true) && in_array($raw, ['', false], true));
            if (!$created && isset($def['onUpdate'])) {
                $values[$col] = self::generated($def, Schema::generate($def['onUpdate'], $def), $col);
            } elseif (isset($def['default']) && $empty) {
                $values[$col] = self::generated($def, Schema::generate($def['default'], $def), $col);
            } elseif ($def['type'] === 'boolean' && $raw === '') {
                $values[$col] = Schema::store($def, false);
            }
        }
        $this->filled[$postId] = array_map('strval', array_keys($values));
        if ($values !== []) {
            try {
                $viewer = get_current_user_id() === 0 ? null : (CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended'));
                $this->db()->requireUniqueVisibility($collection, $values, $viewer);
                $this->db()->validateWriteReferences($collection, $values, $viewer);
            } catch (HttpError $e) {
                EditorSave::fail($e);
            }
            $store->update($collection, $postId, $values);
        }
    }

    /** @return list<string> columns this request's editor save of $postId generated (trusted, not input) */
    public function editorFilled(int $postId): array
    {
        return $this->filled[$postId] ?? [];
    }

    /**
     * A generated default/onUpdate value in storage form, through the same codec as API writes (a
     * bare-date timestamp default becomes full ISO). The post is already saved when this runs, so a
     * value the codec refuses is stored raw as before rather than turning the editor save into a 500.
     */
    private static function generated(array $def, mixed $v, string $col): int|float|string|null
    {
        try {
            return Schema::encode($def, $v, $col, HttpError::seg($col));
        } catch (HttpError) {
            return Schema::store($def, $v);
        }
    }

    /** The row captured before this editor save, or null when the save created the post. */
    private function editorPrevious(int $postId, ?\WP_Post $before): ?array
    {
        return $before === null || $before->post_status === 'auto-draft' ? null : ($this->before[$postId] ?? null);
    }

    // ================================================================ helpers

    /** @return array<string, array> post-backed collections */
    private function postBacked(): array
    {
        return array_filter($this->schema->collections(), static fn (array $c) => ($c['editor'] ?? true) && ($c['wp'] ?? null) !== null);
    }

    private function collectionOf(int $postId): ?string
    {
        $type = get_post_type($postId);
        foreach ($this->postBacked() as $name => $spec) {
            if ($spec['wp']['postType'] === $type) {
                return (string) $name;
            }
        }

        return null;
    }

    /** Db for code that runs outside a First request (WP-CLI helpers, tests). */
    public function db(): Db
    {
        return $this->app()->db;
    }
}
