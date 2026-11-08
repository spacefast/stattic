<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\App;
use Zero\Db;
use Zero\HttpError;
use Zero\Schema;

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
    /** Why storage is behind this build (a failed or contended upgrade); Zero's REST refuses meanwhile. */
    private ?string $unavailable = null;
    /** @var array<int, ?array> post id → row before an editor update (null = treat as create) */
    private array $before = [];
    /** @var array<int, array{string, array}> post id → [collection, row] captured before an editor delete */
    private array $deleting = [];
    /** @var array<int, true> posts WP cron is publishing right now (`publish_future_post`) */
    private array $promoting = [];
    /** @var array<int, list<string>> post id → columns fillEditorDefaults generated in this save */
    private array $filled = [];

    public function __construct(public readonly string $dir, string $mainFile, public readonly Schema $schema)
    {
        self::$instance = $this;
        $this->mainFile = $mainFile;
        add_action('init', [$this, 'registerPostTypes'], 0);
        add_action('acf/init', [$this, 'registerFields'], 5);
        add_action('acf/init', fn () => WpUserStore::registerFields($this->schema), 5);
        if (($this->schema->auth()['collection'] ?? null) !== null) {
            WpUserStore::trackRoles();
        }
        // Users created outside Zero (identity's hosted sign-up, wp-admin) become members with defaults.
        add_action('user_register', function (int $userId): void {
            if (!$this->connection()->busy()) {
                WpUserStore::fillDefaults($this->schema, $userId);
            }
        });
        CoreAccess::register();
        EditorSave::register();
        ProfileForm::register();
        IdentityBridge::hook($this->connection());
        add_action('rest_api_init', fn () => $this->unavailable() === null
            ? Rest::register(fn () => $this->app())
            : Rest::unavailable(self::UNAVAILABLE));
        // While storage is behind, Zero collections' posts can't be written by any path (core REST,
        // wp-admin, other plugins): their hooks would run against tables the build doesn't match.
        // Last, so no later callback (SCF's `_acf_changed` one returns false) can undo the refusal.
        add_filter('wp_insert_post_empty_content', fn ($empty, $post) => $empty || ($this->unavailable() !== null && $this->touchesCollection($post)), PHP_INT_MAX, 2);
        $refuseDelete = fn ($check, $post) => $this->unavailable() !== null && $post instanceof \WP_Post && $this->touchesCollection((array) $post) ? false : $check;
        add_filter('pre_delete_post', $refuseDelete, PHP_INT_MAX, 2);
        add_filter('pre_trash_post', $refuseDelete, PHP_INT_MAX, 2);
        // wp_untrash_post deletes the trash metadata before its update; refuse before that.
        add_filter('pre_untrash_post', fn ($check, $post) => $this->unavailable() !== null && $post instanceof \WP_Post && $this->touchesCollection((array) $post) ? false : $check, PHP_INT_MAX, 2);
        add_filter('rest_pre_dispatch', [$this, 'refuseCoreWrites'], 10, 3);
        // wp_delete_user deletes the user (and identity its credentials) whatever happens to their
        // posts, so refuse deleting anyone who owns Zero posts before any of it starts.
        add_action('delete_user', function (int $id): void {
            if ($this->unavailable() !== null && $this->ownsCollectionPosts($id)) {
                wp_die(esc_html(self::UNAVAILABLE), 'Zero', ['response' => 503, 'back_link' => true]);
            }
        }, PHP_INT_MIN);
        // A scheduled Zero post that falls due while storage is behind publishes once it has caught
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
                wp_die(esc_html(self::UNAVAILABLE), 'Zero', ['response' => 503, 'back_link' => true]);
            }
        });
        Rest::boot();
        HttpRoutes::register($this);
        Hooks::register($this);
        add_action('admin_menu', [$this, 'adminMenu'], 9);
        // A plugin updated by copying files in has no activation; the first request of any kind
        // (REST, front end, cron, CLI, admin) applies its pending migrations before Zero serves it.
        add_action('init', [$this, 'maybeMigrate'], 1);

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

    /**
     * `wp zero <find|insert|update|delete|action> <name> [json...]` and `wp zero migrate`: the
     * standalone CLI's trusted surface (no access checks) on WordPress.
     *
     * @param list<string> $args
     */
    public function cli(array $args, array $assocArgs = []): void
    {
        foreach ($assocArgs as $name => $value) {
            $args[] = '--' . $name . ($value === true ? '' : '=' . ($value === false ? 'false' : (string) $value));
        }
        // (Runtime's `wp zero` dispatcher already refused everything but `migrate` while storage is behind.)
        if (($args[0] ?? '') === 'migrate') {
            try {
                $mark = $assocArgs['mark-applied'] ?? null;
                if (is_string($mark)) {
                    Migrator::locked($this->connection(), $this->schemaOption(), fn () => $this->migrator()->markApplied($mark));
                }
                [$code, $out] = [0, \Zero\Js::jsonStringify(['data' => ['applied' => $this->migrateLocked()]])];
            } catch (\Throwable $e) {
                [$code, $out] = [1, \Zero\Js::jsonStringify(['error' => \Zero\App::problem($e)])];
            }
        } else {
            [$code, $out] = $this->app()->cli($args);
        }
        \WP_CLI::line((string) $out);
        if ($code !== 0) {
            \WP_CLI::halt($code);
        }
    }

    /** Null when storage matches this build; otherwise why Zero operations are refused for now. */
    public function unavailable(): ?string
    {
        return Runtime::unavailable() ?? $this->unavailable;
    }

    /** A later first-request step (Polylang languages) failed: refuse Zero operations for this request too. */
    public function refuse(string $problem): void
    {
        $this->unavailable ??= $problem;
    }

    /** rest_pre_dispatch: core /wp/v2 writes to a Zero collection get a 503 while storage is behind. */
    public function refuseCoreWrites(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($result !== null || $request->get_method() === 'GET' || $this->unavailable() === null) {
            return $result;
        }
        foreach ($this->postBacked() as $spec) {
            // WordPress matches routes case-insensitively, so this does too.
            if (preg_match('#^/wp/v2/' . preg_quote($spec['wp']['postType'], '#') . '(/|$)#i', $request->get_route())) {
                return new \WP_Error('zero_unavailable', self::UNAVAILABLE, ['status' => 503]);
            }
        }

        return $result;
    }

    /**
     * A write that touches a Zero collection: the incoming post is one (or a revision/autosave of one),
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

    public function app(): App
    {
        if ($this->app === null) {
            $db = $this->connection();
            $store = CompositeStore::create($this->schema, $db);
            $this->app = App::boot($this->dir, $this->schema, $store, new WpAuth(), new WpMedia($db, $store->dialect()));
            \Zero\Auth\Module::use(IdentityBridge::available() ? new IdentityBridge($db) : null);
        }

        return $this->app;
    }

    // ================================================================ activation

    public function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        try {
            $this->migrateLocked();
        } catch (\Throwable $error) {
            deactivate_plugins(plugin_basename($this->mainFile));
            wp_die(esc_html($error->getMessage()), 'Zero', ['back_link' => true]);
        }
        // migrateLocked() already provisioned identity and backfilled members.
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
        if (Runtime::managed() || $this->current()) {
            return;
        }
        // Concurrent first requests queue on one lock; whoever gets it after the holder sees the
        // new hash. A failure or timeout leaves the hash stale, so the next request retries.
        $this->unavailable = Migrator::upgrade($this->connection(), $this->schemaOption(), fn () => $this->current(true), fn () => $this->migrateNow());
    }

    /** Explicit host initialization; failures propagate without wp-admin or activation hooks. */
    public function initialize(): array
    {
        $bad = $this->storageProblems();
        if ($bad !== []) {
            throw new \RuntimeException('Zero requires InnoDB and utf8mb4: ' . implode(', ', $bad));
        }
        return $this->migrateLocked();
    }

    /** `wp zero migrate` and activation: the same locked path as the first request. */
    private function migrateLocked(): array
    {
        return Migrator::locked($this->connection(), $this->schemaOption(), fn () => $this->migrateNow());
    }

    private function migrateNow(): array
    {
        $applied = $this->migrator()->migrate();
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
        return new Migrator($this->connection(), $this->dir . '/zero/app/migrations', $this->schema);
    }

    public function pendingMigrations(): array
    {
        return $this->migrator()->pending();
    }

    public function storageProblems(): array
    {
        return $this->migrator()->nonInnoDb();
    }

    private function schemaOption(): string
    {
        return 'zero_' . $this->schema->name() . '_schema';
    }

    private function schemaHash(): string
    {
        return hash_file('sha256', $this->dir . '/zero/app/schema.php');
    }

    // ================================================================ registration

    public function registerPostTypes(): void
    {
        foreach ($this->postBacked() as $name => $spec) {
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
                'supports' => array_values(array_filter(['title', ($spec['content'] ?? null) !== null ? 'editor' : null, ($spec['revisions'] ?? false) ? 'revisions' : null, 'author', 'custom-fields'])),
                // Singular differs from plural so `edit_<type>` stays a primitive cap. Every cap of the type
                // is answered from the collection's access rules (CoreAccess::mapMetaCap), not from roles.
                'capability_type' => [$type . '_item', $type],
                'capabilities' => ['create_posts' => 'create_' . $type],
                'map_meta_cap' => true,
                'rest_controller_class' => PostsController::class,
                'hierarchical' => false,
                'has_archive' => false,
                'rewrite' => false,
                'query_var' => false,
            ]);
        }
    }

    public function registerFields(): void
    {
        Scf::register($this->schema);
        Scf::registerFilters();
    }

    public function adminMenu(): void
    {
        if ($this->schema->globals() === []) {
            return;
        }
        $slug = 'zero-' . $this->schema->name();
        add_menu_page(ucfirst($this->schema->name()), ucfirst($this->schema->name()), 'edit_others_posts', $slug, static function () use ($slug): void {
            echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1></div>';
        }, 'dashicons-admin-settings');
    }

    // ================================================================ editor hooks (§4.9)

    public function capturePrevious(int $postId): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || $this->connection()->busy() || $this->unavailable() !== null) {
            return;
        }
        $post = get_post($postId);
        $this->before[$postId] = $post instanceof \WP_Post && $post->post_status !== 'auto-draft'
            ? $this->app()->db->editorRow($collection, $postId)
            : null;
    }

    public function afterEditorSave(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || isset($this->promoting[$postId]) || $this->connection()->busy() || $this->unavailable() !== null || in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
            return;
        }
        $previous = $this->editorPrevious($postId, $before);
        unset($this->before[$postId]);
        $app = $this->app();
        try {
            $app->db->transaction(fn () => $app->db->afterEditorSave($collection, $postId, $previous, WpAuth::current()));
        } catch (\Throwable $e) {
            EditorSave::fail($e);
        }
    }

    public function beforeEditorDelete(int $postId, ?\WP_Post $post = null): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || $this->connection()->busy() || $this->unavailable() !== null) {
            return;
        }
        $db = $this->app()->db;
        $row = $db->editorRow($collection, $postId);
        if ($row !== null) {
            $this->deleting[$postId] = [$collection, $row];
            try {
                $db->beforeEditorDelete($collection, $row, WpAuth::current());
            } catch (\Throwable $e) {
                unset($this->deleting[$postId]);
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
            // Promotion runs no write hooks; watchers still need to see the row appear.
            $this->app()->db->current(static fn () => \Zero\Realtime\Module::promoted($collection, [$id]));
        }
        unset($this->promoting[$id]);
    }

    public function revisionsToKeep(int $keep, \WP_Post $post): int
    {
        return isset($this->promoting[$post->ID]) ? 0 : $keep;
    }

    /** Every revision of a Zero post, whoever saved it, carries the full row (§4.7). Autosaves keep their own meta. */
    public function snapshotRevision(int $revisionId, int $postId): void
    {
        $collection = $this->collectionOf($postId);
        if ($collection === null || wp_is_post_autosave($revisionId)) {
            return;
        }
        CompositeStore::create($this->schema, $this->connection())->posts->snapshotRevision($collection, $revisionId, $postId);
    }

    /** Core only compares title/content/excerpt; a change to any other Zero column also earns a revision. */
    public function revisionHasChanged(bool $changed, \WP_Post $latest, \WP_Post $post): bool
    {
        $collection = $this->collectionOf($post->ID);
        if ($changed || $collection === null) {
            return $changed;
        }

        return CompositeStore::create($this->schema, $this->connection())->posts->revisionDiffers($collection, $post->ID, $latest->ID);
    }

    /**
     * wp-admin knows nothing of Zero's generated values: on an editor save, write `default` for meta
     * columns that have none yet and `onUpdate` on every update, so editor rows decode like API rows.
     * SCF keeps an unchecked true_false and an emptied list as '', so for those types only a missing
     * meta row means "no value"; a '' boolean is stored as false, which is what SCF means by it.
     */
    public function fillEditorDefaults(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        \Zero\Js::atInstant(fn () => $this->fillEditorDefaultsAt($postId, $post, $update, $before));
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

    /** Db for code that runs outside a Zero request (WP-CLI helpers, tests). */
    public function db(): Db
    {
        return $this->app()->db;
    }
}
