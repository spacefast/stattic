<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Access;
use SpacePress\FieldAccess;
use SpacePress\HttpError;

/**
 * One transaction per editor save (§3.3, §4.9). A save of a SpacePress post through core REST, wp-admin
 * (the edit form, quick edit, a revision restore) or any other `wp_insert_post` caller runs, with its
 * meta, SCF fields and SpacePress's after hooks, in one MySQL transaction opened before core writes the post.
 *
 * Before it commits, a signed-in user's save is authorized on what it actually changed: the row
 * before against the row after, whichever form field, `acf` alias or core column wrote it. SpacePress's
 * field write rules run on that change, then the create rule, or the update rule with that data, as
 * in SpacePress's write pipeline. A refusal rolls the whole save back: core REST answers with SpacePress's 401/403 (code and
 * pointer), wp-admin with an error page. Calls with no signed-in user (WP-CLI, cron, other plugins'
 * code) are WordPress's trusted callers and are not authorized here, as on every core API.
 *
 * Owners: `rest` (PostsController wraps create/update), `restore` (wp-admin's revision restore, whose
 * SCF meta is copied after the post update), `delete` (a permanent delete, from before_delete_post to
 * deleted_post), `core` (everything else, ended at wp_after_insert_post). A SpacePress hook that throws in
 * any of them rolls the whole operation back ({@see fail()}).
 * A save still open at shutdown never completed (a callback threw and was caught, the request died)
 * and rolls back.
 */
final class EditorSave
{
    /** @var ?array{owner: string, collection: string, postId: ?int, before: ?array, covers: list<string>, declared: ?array, supplied: list<string>, checked: bool, claimed: bool} */
    private static ?array $open = null;
    /** Inserts of the open save's collection started inside it (hooks): their ids come first. */
    private static int $nested = 0;

    /** Native collision resolution must not reveal competing slugs before save authorization. */
    public static function uniqueSlug(mixed $override, string $slug, ?int $postId, string $status, string $postType): mixed
    {
        $postId ??= 0;
        $plugin = Plugin::instance();
        if ($plugin === null || self::busy() || get_current_user_id() === 0
            || ($collection = CoreAccess::collection($postType)) === null
            || ($column = $plugin->schema->collection($collection)['slug'] ?? null) === null) {
            return $override;
        }
        try {
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $before = $postId > 0 ? $plugin->db()->editorRow($collection, $postId) : null;
            $wrapped = self::$open !== null && self::$open['collection'] === $collection && self::$open['postId'] === $postId;
            $form = is_admin() && (int) ($_POST['post_ID'] ?? 0) === $postId
                && in_array($_POST['action'] ?? null, ['editpost', 'inline-save'], true);
            $supplied = $wrapped ? self::$open['supplied'] : self::formData($collection)[1];
            if ($before !== null && ($wrapped || $form) && !in_array($column, $supplied, true)
                && in_array(get_post_status($postId), ['publish', 'future', 'private'], true)) {
                // An omitted native column stays unchanged, including an empty stored value. Never
                // branch on hidden equality or ask WordPress to repair it against competing rows.
                return (string) ($before[$column] ?? '');
            }
            $publishing = $before !== null && !in_array(get_post_status($postId), ['publish', 'future', 'private'], true)
                && in_array($status, ['publish', 'future', 'private'], true);
            $plugin->db()->requireUniqueVisibility($collection, [$column => $slug], $viewer, $publishing, $before);
            if (!$publishing && $plugin->db()->unchangedReadableUnique($collection, $column, $slug, $before, $viewer)) {
                return $slug; // Preserve the visible value without WordPress querying competing rows.
            }
        } catch (HttpError $e) {
            if (self::$open === null && is_admin()) {
                wp_die(esc_html($e->getMessage()), '', ['response' => $e->status, 'code' => $e->errorCode, 'back_link' => true]);
            }
            self::fail($e);
        }

        return $override;
    }

    /**
     * Core REST create/update: the controller's save runs as one save, authorized before it commits;
     * a refusal answers with SpacePress's problem. Inside an already open transaction (a REST call made by
     * code running in one) the save is still authorized, and a refusal or any core error throws, so
     * the enclosing transaction rolls back instead of committing a half-done save.
     */
    public static function rest(string $collection, ?int $postId, \WP_REST_Request $request, callable $save): mixed
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        $before = $postId === null ? null : $plugin->db()->editorRow($collection, $postId);
        try {
            [$declared, $supplied] = CoreAccess::incoming($collection, $request, singleSelector: true);
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $plugin->db()->requireUniqueVisibility($collection, $declared, $viewer, $before === null, $before);
            $plugin->db()->validateWriteReferences($collection, $declared, $viewer);
            self::checkCoreFields($collection, $request, $declared, $before);
        } catch (HttpError $e) {
            if ($plugin->connection()->inTransaction()) {
                throw $e;
            }

            return CoreAccess::error($e);
        }
        if ($plugin->connection()->inTransaction()) {
            $outer = self::$open;
            self::$open = self::scope('rest', $collection, $postId, $before, $declared, $supplied) + ['nested' => true];
            try {
                $result = $save();
                if (is_wp_error($result)) {
                    // Core may fail after writing the post or some fields: the enclosing transaction is
                    // the only rollback point, so its work is abandoned rather than half-committed.
                    throw new HttpError((int) ($result->get_error_data()['status'] ?? 500), (string) $result->get_error_code(), $result->get_error_message());
                }
                if (!self::$open['checked']) {
                    self::authorize();
                }

                return $result;
            } catch (Refused $e) {
                throw $e->error;
            } finally {
                self::$open = $outer;
            }
        }
        self::open('rest', $collection, $postId, $before, $declared, $supplied);
        try {
            $result = $save();
            if (!is_wp_error($result) && !self::$open['checked']) {
                self::authorize();
            }
        } catch (Refused $e) {
            self::rollback();

            return CoreAccess::error($e->error);
        } catch (HttpError | \SpacePress\JsError $e) {
            // A SpacePress hook refused the save (§1.7): the editor sees its status and message, as the API answers.
            self::rollback();
            $problem = \SpacePress\App::problem($e);

            return CoreAccess::error(new HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
        is_wp_error($result) ? self::rollback() : self::commit();

        return $result;
    }

    /**
     * The columns core stores itself (title, content, slug) under their SpacePress rules (min, max,
     * pattern, validate), as every other write path checks them; fields in `meta` and `acf` are
     * checked where they're stored (PostMeta, Scf). A core-shaped name the request doesn't use (an
     * unknown body key) is core's to ignore, as on any other core route.
     */
    private static function checkCoreFields(string $collection, \WP_REST_Request $request, array $declared, ?array $before): void
    {
        self::checkColumns($collection, $declared, $before);
    }

    /** SpacePress's rules (min, max, pattern, validate) on the core columns (title, content, slug) in $values. */
    private static function checkColumns(string $collection, array $values, ?array $before): void
    {
        $spec = Plugin::instance()?->schema->collection($collection) ?? [];
        $core = array_filter([$spec['title'] ?? null, $spec['content'] ?? null, $spec['slug'] ?? null, $spec['excerpt'] ?? null]);
        $values = array_intersect_key($values, array_flip($core));
        if ($values === []) {
            return;
        }
        $ctx = ['data' => $values + ($before ?? []), 'user' => WpAuth::current()];
        foreach ($values as $col => $value) {
            $def = $spec['columns'][$col];
            $norm = \SpacePress\Schema::normalize($def, $value, (string) $col, HttpError::seg((string) $col));
            \SpacePress\Schema::checkRules($def, $norm, (string) $col, HttpError::seg((string) $col), $ctx, $spec['columns']);
        }
    }

    /** wp_insert_post_data: open the save of a SpacePress post before core writes it. */
    public static function begin(mixed $data, mixed $postarr, mixed $unsanitized = null, mixed $update = false): mixed
    {
        $plugin = Plugin::instance();
        if (!is_array($data) || $plugin === null || $plugin->connection()->busy()
            || ($collection = CoreAccess::collection((string) ($data['post_type'] ?? ''))) === null) {
            return $data;
        }
        if ($plugin->connection()->inTransaction()) {
            // A REST create's own insert is the save's post; any other insert of the collection inside
            // the open save (another plugin's save_post) is not, and learns its id first.
            if (self::$open !== null && !$update && self::$open['postId'] === null && $collection === self::$open['collection']) {
                self::$open['claimed'] ? self::$nested++ : self::$open['claimed'] = true;
            }

            return $data;
        }
        $id = $update && is_array($postarr) ? (int) ($postarr['ID'] ?? 0) : 0;
        $before = $id > 0 ? $plugin->db()->editorRow($collection, $id) : null;
        if ($before !== null) {
            $data = CoreAccess::keepHidden($collection, $before, $data); // controls the form left blank
        }
        [$declared, $supplied] = self::formData($collection);
        self::open('core', $collection, $id > 0 ? $id : null, $before, $declared, $supplied);
        if ($id > 0 && get_post_status($id) === 'trash') {
            self::$open['checked'] = true; // untrash: delete_post on the trashed post decided it
        }

        return $data;
    }

    /** wp_insert_post: an insert learns its post id here. */
    public static function inserted(mixed $postId): void
    {
        if (self::$open === null || self::$open['postId'] !== null || self::busy()
            || CoreAccess::collection((string) get_post_type((int) $postId)) !== self::$open['collection']) {
            return;
        }
        if (self::$nested > 0) {
            self::$nested--;

            return;
        }
        self::$open['postId'] = (int) $postId;
    }

    public static function check(mixed $post): void
    {
        if (self::$open !== null && self::$open['owner'] !== 'restore' && !self::$open['checked'] && self::owns($post)) {
            self::authorizeOrDie();
        }
    }

    public static function end(mixed $post): void
    {
        if (self::$open !== null && self::$open['owner'] === 'core' && self::owns($post)) {
            if (!self::$open['checked']) {
                self::authorizeOrDie();
            }
            // The post's URLs join this transaction's purge announcement, sent after the commit.
            Purges::collect((int) ($post instanceof \WP_Post ? $post->ID : $post));
            self::commit();
        }
    }

    public static function beginDelete(mixed $postId): void
    {
        $plugin = Plugin::instance();
        if (self::$open !== null && self::$open['owner'] === 'delete' && self::$open['postId'] === (int) $postId) {
            self::$open['prepared'] = false; // prepared at pre_delete_post: core started it, it goes on in it
            return;
        }
        if ($plugin === null || self::busy() || $plugin->connection()->inTransaction()
            || ($collection = CoreAccess::collection((string) get_post_type((int) $postId))) === null) {
            return;
        }
        self::open('delete', $collection, (int) $postId, null, null, []);
        self::$open['checked'] = true; // delete_post (the delete rule) decided it
    }

    /**
     * Open a delete's transaction before core starts it (`pre_delete_post`), for a delete whose
     * before half must be able to decline it; false when another save is open.
     */
    public static function prepareDelete(string $collection, int $postId): bool
    {
        $plugin = Plugin::instance();
        if (self::$open !== null && self::$open['owner'] === 'delete' && (self::$open['prepared'] ?? false)) {
            self::rollback(); // a prepared delete that never started: a later pre_delete_post declined it
        }
        if ($plugin === null || self::busy() || $plugin->connection()->inTransaction()) {
            return false;
        }
        self::open('delete', $collection, $postId, null, null, []);
        self::$open['checked'] = true;
        self::$open['prepared'] = true;

        return true;
    }

    /** The prepared delete was refused by its before half: nothing of it commits. */
    public static function abandonDelete(): void
    {
        if (self::$open !== null && self::$open['owner'] === 'delete') {
            self::rollback();
        }
    }

    /** A prepared delete core never started (a later pre_delete_post declined it) rolls back. */
    public static function abandonPrepared(): void
    {
        if (self::$open !== null && self::$open['owner'] === 'delete' && (self::$open['prepared'] ?? false)) {
            self::rollback();
        }
    }

    public static function endDelete(mixed $postId): void
    {
        if (self::$open !== null && self::$open['owner'] === 'delete' && self::$open['postId'] === (int) $postId) {
            self::commit();
        }
    }

    /** wp-admin's revision.php checks its nonce, then restores: the save opens here. */
    public static function beginRestore(mixed $action, mixed $result): void
    {
        $plugin = Plugin::instance();
        if ($plugin === null || $result === false || !is_string($action) || !str_starts_with($action, 'restore-post_')
            || $plugin->connection()->inTransaction()) {
            return;
        }
        $revisionId = (int) substr($action, strlen('restore-post_'));
        $revision = wp_get_post_revision($revisionId);
        $collection = $revision instanceof \WP_Post ? CoreAccess::collection((string) get_post_type($revision->post_parent)) : null;
        if ($collection !== null) {
            self::open('restore', $collection, $revision->post_parent, $plugin->db()->editorRow($collection, $revision->post_parent), null, []);
        }
    }

    public static function endRestore(mixed $postId): void
    {
        if (self::$open !== null && self::$open['owner'] === 'restore' && self::$open['postId'] === (int) $postId) {
            self::authorizeOrDie();
            self::commit();
        }
    }

    /**
     * A save still open at shutdown never reached its explicit end (wp_after_insert_post's last callback,
     * deleted_post, a restore's action, the REST controller's return): a callback threw and its caller
     * caught it, or the request died. SpacePress's after hooks may not have run, so it rolls back.
     */
    public static function shutdown(): void
    {
        if (self::$open !== null) {
            self::rollback();
        }
    }

    /**
     * A SpacePress after hook threw inside an open save (§1.7: throwing rolls back every write): nothing of
     * the save commits. Core REST answers the problem itself (rest()); wp-admin and other callers get
     * an error page with the hook's status and message, never a critical error.
     */
    public static function fail(\Throwable $e): never
    {
        if (self::$open === null || self::$open['owner'] === 'rest' || (self::$open['nested'] ?? false)) {
            throw $e;
        }
        if (self::$open['owner'] === 'delete' && defined('REST_REQUEST') && REST_REQUEST) {
            self::rollback();
            throw $e; // the controller answers it
        }
        self::rollback();
        $problem = \SpacePress\App::problem($e);
        wp_die(esc_html($problem['detail']), '', ['response' => $problem['status'], 'code' => $problem['code'], 'back_link' => true]);
        throw $e; // a wp_die handler that returns
    }

    // ================================================================ authorization

    private static function authorizeOrDie(): void
    {
        try {
            self::authorize();
        } catch (Refused $e) {
            if (self::$open['owner'] === 'rest') {
                throw $e;
            }
            if (self::$open['nested'] ?? false) {
                throw $e->error;
            }
            self::rollback();
            wp_die(esc_html($e->error->getMessage()), '', ['response' => $e->error->status, 'code' => $e->error->errorCode, 'back_link' => true]);
        }
    }

    /**
     * The save as the signed-in user wrote it: the columns it supplied (form fields, `acf` selectors and
     * aliases, core columns) and every column it changed, with their saved values. Field write rules
     * run on those, as on the API (an unchanged hidden value is still refused, so no value can be
     * probed), then the create rule, or the update rule with that data: a filter must match the row
     * as it was before the save, as the API's update selector does.
     */
    private static function authorize(): void
    {
        Plugin::instance()?->db()->current(self::authorizeInContext(...));
    }

    private static function authorizeInContext(): void
    {
        $open = self::$open;
        self::$open['checked'] = true;
        $plugin = Plugin::instance();
        if ($open['postId'] === null || $plugin === null || get_current_user_id() === 0) {
            return;
        }
        ['collection' => $collection, 'postId' => $id, 'before' => $before] = $open;
        $viewer = CoreAccess::viewer() ?? throw new Refused(new HttpError(403, 'suspended', 'This account is suspended'));
        $after = $plugin->db()->editorRow($collection, $id);
        if ($after === null) {
            return; // not a row (an auto-draft, the trash): its capability decided
        }
        $spec = $plugin->schema->collection($collection);
        $generated = array_flip([...$plugin->editorFilled($id), $spec['primaryKey']]); // trusted, not input
        $rules = Access::collectionRules($spec);
        try {
            if ($before === null) {
                // A form posts every field: supplied = named by the request, or not the column's default.
                $cols = array_filter(array_diff_key($after, $generated), static fn ($v, $col) => in_array($col, $open['supplied'], true)
                    || ($v !== null && !(isset($spec['columns'][$col]['default']['value']) && $v === $spec['columns'][$col]['default']['value'])), ARRAY_FILTER_USE_BOTH);
                $data = $after;
                FieldAccess::checkWrite('create', $collection, $data, null, $viewer, $cols);
                $plugin->db()->validateWriteReferences($collection, $after, $viewer);
                Access::require($rules['create'], $viewer, 'create', $after, "create $collection rows", $spec['columns']);

                return;
            }
            $changed = array_keys(array_filter(array_diff_key($after, $generated), static fn ($v, $col) => !\SpacePress\Js::sameJson($v, $before[$col] ?? null), ARRAY_FILTER_USE_BOTH));
            $patch = array_intersect_key($after, array_flip([...$changed, ...$open['supplied']]));
            // Title, content and slug a save changed (an autosave of one's own draft, a quick edit, any
            // wp_update_post) follow SpacePress's rules on those columns, as a REST save's do.
            self::checkColumns($collection, array_intersect_key($patch, array_flip($changed)), $before);
            $supplied = $open['owner'] === 'restore' ? $patch : self::groupPatch($spec['columns'], $patch, $before, $open['declared'] ?? []);
            $data = $patch;
            FieldAccess::checkWrite('update', $collection, $data, $before, $viewer, $supplied, stored: true);
            $plugin->db()->validateWriteReferences($collection, $patch, $viewer);
            $r = Access::evaluate($rules['update'], $viewer, 'update', $patch, $spec['columns'], $collection);
            if ($r === false || (is_array($r) && !in_array(serialize($r), $open['covers'], true))) {
                throw Access::deny($viewer->user, "update $collection rows");
            }
        } catch (HttpError $e) {
            throw new Refused($e);
        }
    }

    /** Keep stored group siblings outside the save's input; changed or explicitly sent keys are checked. */
    private static function groupPatch(array $fields, array $patch, array $before, array $declared): array
    {
        foreach ($patch as $name => $value) {
            if (($fields[$name]['type'] ?? null) !== 'group' || !is_array($value)) {
                continue;
            }
            if (array_key_exists($name, $declared) && !is_array($declared[$name])) {
                $patch[$name] = $declared[$name]; // an explicit container replacement stays input
                continue;
            }
            $old = is_array($before[$name] ?? null) ? $before[$name] : [];
            $sent = is_array($declared[$name] ?? null) ? $declared[$name] : [];
            $children = array_filter($value, static fn ($v, $key) => array_key_exists($key, $sent)
                || !\SpacePress\Js::sameJson($v, $old[$key] ?? null), ARRAY_FILTER_USE_BOTH);
            $patch[$name] = self::groupPatch($fields[$name]['fields'] ?? [], $children, $old, $sent);
        }

        return $patch;
    }

    /**
     * What a wp-admin form or quick edit posts: [declared data (JS form), columns it names]. SCF field
     * keys and core columns; a core column hidden from the viewer is never counted (its control is
     * blank and its stored value kept, CoreAccess::keepHidden).
     *
     * @return array{?array<string, mixed>, list<string>}
     */
    private static function formData(string $collection): array
    {
        if ((!is_admin() && !wp_doing_ajax()) || get_current_user_id() === 0) {
            return [null, []];
        }
        $spec = Plugin::instance()?->schema->collection($collection) ?? [];
        $data = [];
        foreach (is_array($_POST['acf'] ?? null) ? wp_unslash($_POST['acf']) : [] as $key => $value) {
            foreach ($spec['columns'] ?? [] as $col => $def) {
                if ($key === $spec['wp']['fieldKeyPrefix'] . $col) {
                    $data[(string) $col] = Scf::fromScf($def, $value, (string) $key);
                }
            }
        }
        foreach (['post_title' => 'title', 'content' => 'content', 'post_name' => 'slug'] as $param => $designation) {
            $col = $spec[$designation] ?? null;
            // A hidden column's control is rendered blank and kept (CoreAccess::keepHidden); anything else
            // posted for it is a write, refused like any other whatever its value.
            if ($col !== null && isset($_POST[$param]) && !($_POST[$param] === '' && CoreAccess::nativeHiddenFor($collection, $col))) {
                $data[$col] = wp_unslash($_POST[$param]);
            }
        }

        return [$data === [] ? null : $data, array_map('strval', array_keys($data))];
    }

    // ================================================================ transaction

    private static function open(string $owner, string $collection, ?int $postId, ?array $before, ?array $declared, array $supplied): void
    {
        $scope = self::scope($owner, $collection, $postId, $before, $declared, $supplied);
        Plugin::instance()?->connection()->begin();
        self::$nested = 0;
        self::$open = $scope;
    }

    /**
     * A save's state. `covers`: the update-rule filters (serialized) that match the row as it is
     * before the save, for the rule evaluated without data and with the request's declared data.
     */
    private static function scope(string $owner, string $collection, ?int $postId, ?array $before, ?array $declared, array $supplied): array
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        return $plugin->db()->current(fn (): array => self::scopeInContext($owner, $collection, $postId, $before, $declared, $supplied));
    }

    private static function scopeInContext(string $owner, string $collection, ?int $postId, ?array $before, ?array $declared, array $supplied): array
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        $covers = [];
        $viewer = $before === null || get_current_user_id() === 0 ? null : CoreAccess::viewer();
        if ($viewer !== null && $postId !== null) {
            $plugin->app();
            $rule = Access::collectionRules($plugin->schema->collection($collection))['update'];
            foreach ($declared === null ? [null] : [null, $declared] as $data) {
                $r = Access::evaluate($rule, $viewer, 'update', $data, $plugin->schema->collection($collection)['columns'], $collection);
                if (is_array($r) && $plugin->db()->permits($collection, 'update', $postId, $viewer, $data)) {
                    $covers[] = serialize($r);
                }
            }
        }

        // A save opened by its own insert has claimed it; a REST create claims the first one it makes.
        return ['owner' => $owner, 'collection' => $collection, 'postId' => $postId, 'before' => $before, 'covers' => $covers,
            'declared' => $declared, 'supplied' => $owner === 'restore' ? array_keys($plugin->schema->collection($collection)['columns']) : array_values(array_unique($supplied)), 'checked' => false, 'claimed' => $owner === 'core'];
    }

    /** Commit the save; a COMMIT that fails rolls back (Connection) and cleans the post's caches too. */
    private static function commit(): void
    {
        $postId = self::$open['postId'] ?? null;
        self::$open = null;
        try {
            Plugin::instance()?->connection()->commit();
        } catch (\Throwable $e) {
            self::forget($postId);
            throw $e;
        }
    }

    private static function rollback(): void
    {
        $postId = self::$open['postId'] ?? null;
        self::$open = null;
        try {
            Plugin::instance()?->connection()->rollback();
        } finally {
            self::forget($postId);
        }
    }

    /** Core wrote the post outside SpacePress's store: no cache may keep a ghost of a rolled-back save. */
    private static function forget(?int $postId): void
    {
        if ($postId !== null) {
            clean_post_cache($postId);
        }
        CoreAccess::flush();
    }

    private static function owns(mixed $post): bool
    {
        $id = $post instanceof \WP_Post ? $post->ID : (int) $post;

        return !self::busy() && self::$open['postId'] === $id;
    }

    private static function busy(): bool
    {
        return Plugin::instance()?->connection()->busy() ?? false;
    }
}
