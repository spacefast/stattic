<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Access;
use Zero\FieldAccess;
use Zero\HttpError;
use Zero\Schema;
use Zero\Viewer;

/**
 * Core `/wp/v2/zero_*` and wp-admin under Zero's access rules. Core REST stays enabled for the block
 * editor, but it is never a second API around Zero's rules:
 *
 * - capabilities: `map_meta_cap` answers every capability of a Zero post type from the collection's
 *   rules, for the user being asked about (role caps no longer decide). Per post: `read_post` = the
 *   read rule (+ draft visibility), `edit_post`/`publish_post` = update, `delete_post` = delete; an
 *   auto-draft (not a row yet) is its author's under the create rule; a trashed post (not a row)
 *   needs a rule that covers every row. Type-wide caps (menus, create buttons) ask whether the rule
 *   could allow anything. A function rule that depends on the incoming data counts as possibly
 *   allowing here: {@see EditorSave} decides each save on its data before it commits.
 * - reads ({@see PostsController}): lists hold only rows the read rule covers, a rule that denies is
 *   401/403, a filtered-out item is 404, and every response (revisions and autosaves against their
 *   own snapshot) drops the fields the viewer can't read, with their links; a search or slug query
 *   can't probe a hidden column.
 * - wp-admin: list screens hold only readable rows; the edit form doesn't render a field holding
 *   anything the viewer can't read.
 */
final class CoreAccess
{
    private const META = ['read_post', 'edit_post', 'publish_post', 'delete_post'];
    private const STRUCTURED = ['group', 'repeater', 'blocks'];

    /** @var ?array<string, array{string, string}> primitive cap => [collection, kind] */
    private static ?array $primitives = null;
    /** @var array<string, ?list<int|string>|false> "collection op user" => keys (null = all, false = denied) */
    private static array $keys = [];
    private static int $generation = 0;

    public static function register(): void
    {
        add_filter('map_meta_cap', [self::class, 'mapMetaCap'], 10, 4);
        // Last: SCF adds its field links on the same filters at 10, registered later in the request.
        add_filter('rest_prepare_revision', [self::class, 'prepareChild'], PHP_INT_MAX, 3);
        add_filter('rest_prepare_autosave', [self::class, 'prepareChild'], PHP_INT_MAX, 3);
        add_action('pre_get_posts', [self::class, 'adminList']);
        add_filter('acf/prepare_field', [self::class, 'formField'], 20);
        // Zero's columns are written through Zero, SCF or core REST's `acf`, never wp-admin's generic
        // Custom Fields box or its AJAX: protected meta is refused on every one of those paths.
        add_filter('is_protected_meta', [self::class, 'protectedMeta'], 10, 3);
        add_filter('edit_post_content', [self::class, 'adminContent'], 10, 2);
        add_filter('editable_slug', [self::class, 'adminSlug'], 10, 2);
        // wp-admin's revision screen and its AJAX diffs: each side is judged by its own snapshot.
        add_filter('wp_get_revision_ui_diff', [self::class, 'revisionDiff'], PHP_INT_MAX, 3);
        // Anything that can change what a rule covers or who is asking drops the cached answers: a post
        // or Zero row written, a user switched, a role or user meta changed.
        foreach (['clean_post_cache', 'set_current_user', 'set_user_role', 'add_user_role', 'remove_user_role',
            'added_user_meta', 'updated_user_meta', 'deleted_user_meta', 'profile_update',
            'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'set_object_terms'] as $hook) {
            add_action($hook, [self::class, 'flush']);
        }
        \Zero\Extensions::onWritten(static function (): void {
            self::flush();
        });
    }

    public static function flush(): void
    {
        self::$keys = [];
        self::$generation++;
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    // ================================================================ capabilities

    /** @param list<string> $caps */
    public static function mapMetaCap(array $caps, string $cap, int $userId, array $args): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null) {
            return $caps;
        }
        if (in_array($cap, self::META, true)) {
            $post = isset($args[0]) ? get_post((int) $args[0]) : null;
            if ($post instanceof \WP_Post && $post->post_type === 'revision') {
                if ($cap === 'delete_post') {
                    return $caps; // core never deletes a revision through delete_post
                }
                $post = get_post($post->post_parent);
            }
            $collection = $post instanceof \WP_Post ? self::collection($post->post_type) : null;
            if ($collection === null) {
                return $caps;
            }

            return self::grant(self::postAllowed($collection, $cap, $post, $userId));
        }
        [$collection, $kind] = self::primitives()[$cap] ?? [null, null];
        if ($collection === null) {
            return $caps;
        }

        return self::grant(self::typeAllowed($collection, $kind, $userId));
    }

    private static function postAllowed(string $collection, string $cap, \WP_Post $post, int $userId): bool
    {
        $viewer = self::viewerFor($userId);
        if ($viewer === null || ($cap !== 'read_post' && $viewer->user === null)) {
            return false;
        }
        if ($post->post_status === 'auto-draft') {
            // "Add New" makes an auto-draft the editor loads and saves: its author's, under the create rule.
            return $viewer->user !== null && (int) $post->post_author === $viewer->user['id'] && self::ruleMayAllow($collection, 'create', $viewer);
        }
        $op = ['read_post' => 'read', 'edit_post' => 'update', 'publish_post' => 'update', 'delete_post' => 'delete'][$cap];
        if ($post->post_status === 'trash') {
            return self::keys($collection, $op, $viewer) === null;
        }

        return self::covers($collection, $op, $post->ID, $viewer);
    }

    private static function typeAllowed(string $collection, string $kind, int $userId): bool
    {
        $viewer = self::viewerFor($userId);
        if ($viewer === null) {
            return false;
        }
        if ($kind === 'readAll') {
            return self::keys($collection, 'read', $viewer) === null;
        }
        if ($viewer->user === null) {
            return false;
        }

        // `edit_`, `create_`, `delete_`: the rule may allow some row. The `others`/`published`/`private`
        // variants speak for any row, so they need a rule that covers every row as stored.
        return match ($kind) {
            'create' => self::ruleMayAllow($collection, 'create', $viewer),
            'edit' => self::ruleMayAllow($collection, 'update', $viewer) || self::ruleMayAllow($collection, 'create', $viewer),
            'delete' => self::ruleMayAllow($collection, 'delete', $viewer),
            'updateAll' => self::keys($collection, 'update', $viewer) === null,
            'deleteAll' => self::keys($collection, 'delete', $viewer) === null,
        };
    }

    /** @return array<string, array{string, string}> */
    private static function primitives(): array
    {
        if (self::$primitives === null) {
            self::$primitives = [];
            foreach (self::postBacked() as $name => $spec) {
                $t = $spec['wp']['postType'];
                self::$primitives += [
                    "create_$t" => [$name, 'create'],
                    "edit_$t" => [$name, 'edit'],
                    "publish_$t" => [$name, 'edit'],
                    "edit_others_$t" => [$name, 'updateAll'], "edit_published_$t" => [$name, 'updateAll'], "edit_private_$t" => [$name, 'updateAll'],
                    "delete_$t" => [$name, 'delete'], "delete_others_$t" => [$name, 'deleteAll'],
                    "delete_published_$t" => [$name, 'deleteAll'], "delete_private_$t" => [$name, 'deleteAll'],
                    "read_private_$t" => [$name, 'readAll'],
                ];
            }
        }

        return self::$primitives;
    }

    /** @param list<string> $caps */
    private static function grant(bool $allowed): array
    {
        return [$allowed ? 'exist' : 'do_not_allow'];
    }

    /**
     * True unless the rule denies this viewer outright. A filter may allow some rows, and a function
     * rule may allow given the save's data, which EditorSave checks before commit.
     */
    private static function ruleMayAllow(string $collection, string $op, Viewer $viewer): bool
    {
        $rule = Access::collectionRules(self::schema()->collection($collection))[$op];

        return isset($rule['fn']) || Access::evaluate($rule, $viewer, $op, null) !== false;
    }

    /** Does the viewer's $op rule cover row $pk (as stored, without a write's data)? */
    public static function covers(string $collection, string $op, int|string $pk, Viewer $viewer): bool
    {
        $keys = self::keys($collection, $op, $viewer);

        return $keys === null || ($keys !== false && in_array((int) $pk, array_map('intval', $keys), true));
    }

    /**
     * Rows the viewer's read, update or delete rule covers: null = every row, false = the rule denies,
     * else their primary keys. Cached until something that could change the answer happens.
     *
     * @return ?list<int|string>|false
     */
    public static function keys(string $collection, string $op, Viewer $viewer): array|null|false
    {
        $key = $collection . ' ' . $op . ' ' . ($viewer->user['id'] ?? 0) . ' ' . ($viewer->user['role'] ?? '') . ' ' . serialize($_COOKIE['wp-postpass_' . COOKIEHASH] ?? null);
        if (!array_key_exists($key, self::$keys)) {
            try {
                self::$keys[$key] = Plugin::instance()?->db()->permittedKeys($collection, $op, $viewer);
            } catch (HttpError) {
                self::$keys[$key] = false;
            }
        }

        return self::$keys[$key];
    }

    // ================================================================ viewers

    /** The viewer of this request (null when the account is suspended). */
    public static function viewer(): ?Viewer
    {
        return self::viewerFor(get_current_user_id());
    }

    private static function viewerFor(int $userId): ?Viewer
    {
        if (IdentityBridge::available() && (\Zero\Identity\Plugin::authenticationError() !== null || \Zero\Identity\Bearer::failure() !== null)) {
            return null;
        }
        $user = $userId > 0 ? get_userdata($userId) : false;
        if (!$user instanceof \WP_User) {
            return new Viewer(null);
        }
        if (IdentityBridge::suspended($userId)) {
            return null;
        }

        return new Viewer(WpAuth::map($user));
    }

    // ================================================================ reads

    /** The read rule's own refusal for this viewer (401/403), or null when it may allow rows. */
    public static function readDenial(string $collection, ?Viewer $viewer): ?\WP_Error
    {
        if ($viewer === null) {
            return self::error(new HttpError(403, 'suspended', 'This account is suspended'));
        }
        if (self::keys($collection, 'read', $viewer) === false) {
            return self::error(Access::deny($viewer->user, "read $collection"));
        }

        return null;
    }

    /**
     * Drop from a core response every field the viewer can't read: judged on $stored (the row, or the
     * snapshot a revision holds). Without one (a trashed post) every field that could be hidden from
     * this viewer goes, decided by the rules alone.
     */
    public static function strip(mixed $response, string $collection, ?array $stored, ?Viewer $viewer): mixed
    {
        if (!$response instanceof \WP_REST_Response || !is_array($data = $response->get_data())) {
            return $response;
        }
        $spec = self::schema()->collection($collection);
        $visible = $viewer === null || $stored === null ? null : (self::visibleFields($stored, $collection, $viewer));
        $dropped = false;
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            if ($col === $spec['primaryKey'] || ($def['system'] ?? false) || $col === ($spec['title'] ?? null)) {
                continue; // the title is always readable (§4.4)
            }
            $hidden = $visible === null
                ? $viewer === null || FieldAccess::mayHide($def, $viewer)
                // A structured value with a hidden sub-field is dropped whole: core shows SCF's own shape.
                : array_key_exists($col, $stored) && (!array_key_exists($col, $visible) || $visible[$col] !== $stored[$col]);
            if (!$hidden) {
                continue;
            }
            $dropped = true;
            match ($col) {
                $spec['content'] ?? null => self::unset($data, ['content', 'excerpt']),
                $spec['slug'] ?? null => self::unset($data, ['slug', 'generated_slug', 'permalink_template', 'link']),
                default => is_array($data['acf'] ?? null) ? self::unset($data['acf'], [$spec['wp']['metaPrefix'] . $col, $spec['wp']['metaPrefix'] . $col . '_source']) : null,
            };
        }
        $response->set_data($data);
        if ($dropped) {
            // SCF links every referenced post (embeddable): a hidden reference must not survive as one.
            foreach (array_keys($response->get_links()) as $rel) {
                if (str_starts_with((string) $rel, 'acf:')) {
                    $response->remove_link($rel);
                }
            }
        }

        return $response;
    }

    /** Revisions and autosaves of a Zero post are stripped against their own snapshot. */
    public static function prepareChild(mixed $response, mixed $post, mixed $request): mixed
    {
        if (!$post instanceof \WP_Post) {
            return $response;
        }
        if ($post->post_parent <= 0) {
            // Autosaving one's own draft updates the post itself, which core then prepares here.
            $collection = self::collection($post->post_type);

            return $collection === null ? $response
                : self::strip($response, $collection, Plugin::instance()?->db()->editorRow($collection, $post->ID), self::viewer());
        }
        $collection = self::collection((string) get_post_type($post->post_parent));
        if ($collection === null) {
            return $response;
        }
        try {
            $snapshot = Plugin::instance()?->db()->revisionRow($collection, $post->post_parent, $post->ID);
        } catch (HttpError) {
            $snapshot = null;
        }

        return self::strip($response, $collection, $snapshot, self::viewer());
    }

    /** A list query naming a column the viewer may not read (search over content, slug) is refused. */
    public static function queryDenial(string $collection, \WP_REST_Request $request, ?Viewer $viewer): ?\WP_Error
    {
        if ($viewer === null) {
            return null;
        }
        $spec = self::schema()->collection($collection);
        $hidden = static fn (?string $col): bool => $col !== null && FieldAccess::mayHide($spec['columns'][$col], $viewer);
        $columns = (array) ($request['search_columns'] ?? []);
        $probes = [
            'search' => self::given($request['search']) && $hidden($spec['content'] ?? null)
                && ($columns === [] || array_intersect($columns, ['post_content', 'post_excerpt']) !== []),
            'slug' => self::given($request['slug']) && $hidden($spec['slug'] ?? null),
            'orderby' => $request['orderby'] === 'slug' && $hidden($spec['slug'] ?? null),
        ];
        foreach ($probes as $param => $probe) {
            if ($probe) {
                return self::error(new HttpError(403, 'forbidden', "You can't filter, sort or search by a field hidden from you", '/' . $param));
            }
        }

        return null;
    }

    /** A query value that was supplied ("0" counts; PHP's empty() would drop it). */
    private static function given(mixed $v): bool
    {
        return is_array($v) ? $v !== [] : $v !== null && $v !== '' && $v !== false;
    }

    private static function unset(array &$data, array $keys): void
    {
        foreach ($keys as $k) {
            unset($data[$k]);
        }
    }

    /**
     * wp-admin lists of a Zero post type hold only the rows the viewer's read rule covers, and a
     * search there only reads the title when the content may be hidden from the viewer.
     */
    public static function adminList(\WP_Query $query): void
    {
        if (!is_admin() || wp_doing_ajax() || !$query->is_main_query() || !is_string($type = $query->get('post_type'))
            || ($collection = self::collection($type)) === null) {
            return;
        }
        $viewer = self::viewer();
        $keys = $viewer === null ? false : self::keys($collection, 'read', $viewer);
        if (is_array($keys) || $keys === false) {
            $query->set('post__in', $keys === false || $keys === [] ? [0] : array_map('intval', $keys));
        }
        $content = self::schema()->collection($collection)['content'] ?? null;
        if ($query->get('s') !== '' && $content !== null && ($viewer === null || FieldAccess::mayHide(self::schema()->collection($collection)['columns'][$content], $viewer))) {
            $query->set('search_columns', ['post_title']);
        }
    }

    /**
     * Drop from a revision diff every row that compares a field hidden from the viewer on either side
     * (SCF adds one per field; core adds content and the rest). A side's visibility comes from the
     * snapshot it shows; without one, from the rules alone.
     */
    public static function revisionDiff(mixed $diff, mixed $from, mixed $to): mixed
    {
        if (!is_array($diff) || !$to instanceof \WP_Post) {
            return $diff;
        }
        $parentId = $to->post_type === 'revision' ? $to->post_parent : $to->ID;
        $collection = self::collection((string) get_post_type($parentId));
        if ($collection === null) {
            return $diff;
        }
        $spec = self::schema()->collection($collection);
        $viewer = self::viewer();
        $hidden = [];
        foreach ([$from, $to] as $side) {
            if (!$side instanceof \WP_Post) {
                continue;
            }
            $row = null;
            try {
                $row = $side->ID === $parentId ? Plugin::instance()?->db()->editorRow($collection, $parentId)
                    : Plugin::instance()?->db()->revisionRow($collection, $parentId, $side->ID);
            } catch (HttpError) {
            }
            foreach ($spec['columns'] as $col => $def) {
                $col = (string) $col;
                if ($col === $spec['primaryKey'] || $col === ($spec['title'] ?? null) || ($def['system'] ?? false)) {
                    continue;
                }
                if ($viewer === null || $row === null
                    ? $viewer === null || FieldAccess::mayHide($def, $viewer)
                    : !self::fullyVisible($collection, $col, $row, $viewer)) {
                    $hidden[$col] = true;
                }
            }
        }
        $ids = [];
        foreach (array_keys($hidden) as $col) {
            $ids[] = $spec['wp']['metaPrefix'] . $col;
            if ($col === ($spec['content'] ?? null)) {
                array_push($ids, 'post_content', 'post_excerpt');
            }
            if ($col === ($spec['slug'] ?? null)) {
                $ids[] = 'post_name';
            }
        }
        $prefixes = array_map(static fn (string $id): string => $id . '_', $ids); // nested SCF sub-field rows

        return array_values(array_filter($diff, static function ($entry) use ($ids, $prefixes): bool {
            $id = is_array($entry) ? (string) ($entry['id'] ?? '') : '';
            foreach ($prefixes as $p) {
                if (str_starts_with($id, $p)) {
                    return false;
                }
            }

            return !in_array($id, $ids, true);
        }));
    }

    /** WordPress fields always belong to the plugin, even inside a secondary Db operation. */
    private static function visibleFields(array $row, string $collection, Viewer $viewer): array
    {
        return Plugin::instance()->db()->current(static fn () => FieldAccess::strip([$row], $collection, $viewer)[0] ?? []);
    }

    /** Is $col of $row readable in full (a structured value with a hidden sub-field is not)? */
    private static function fullyVisible(string $collection, string $col, array $row, Viewer $viewer): bool
    {
        $visible = self::visibleFields($row, $collection, $viewer);

        return !array_key_exists($col, $row) || (array_key_exists($col, $visible) && $visible[$col] === $row[$col]);
    }

    /** @param mixed $type the meta type (post, user, ...) */
    public static function protectedMeta(mixed $protected, mixed $key, mixed $type): mixed
    {
        if ($protected || $type !== 'post' || !is_string($key)) {
            return $protected;
        }
        foreach (self::postBacked() as $spec) {
            if (str_starts_with($key, $spec['wp']['metaPrefix']) || str_starts_with($key, '_' . $spec['wp']['metaPrefix'])) {
                return true;
            }
        }

        return $protected;
    }

    /** The classic editor's textarea: empty when the designated content is hidden from the viewer. */
    public static function adminContent(mixed $content, mixed $postId): mixed
    {
        return self::nativeHidden((int) $postId, 'content') ? '' : $content;
    }

    /** The slug box and quick edit: empty when the designated slug is hidden from the viewer. */
    public static function adminSlug(mixed $slug, mixed $post): mixed
    {
        return $post instanceof \WP_Post && self::nativeHidden($post->ID, 'slug') ? '' : $slug;
    }

    /** Is a core-column designation ('content', 'slug') of a Zero post hidden from this viewer? */
    /**
     * A wp-admin save of a post whose content or slug is hidden from the viewer: the blank control the
     * form posted doesn't overwrite the stored value (a forged non-blank one still reaches EditorSave,
     * which refuses it as a write to a hidden column).
     */
    public static function keepHidden(string $collection, array $stored, array $data): array
    {
        if (!is_admin() || get_current_user_id() === 0) {
            return $data;
        }
        $spec = self::schema()->collection($collection);
        foreach (['content' => 'post_content', 'slug' => 'post_name'] as $designation => $field) {
            $col = $spec[$designation] ?? null;
            if ($col !== null && self::nativeHiddenFor($collection, $col, $stored) && ($data[$field] ?? '') === '') {
                $data[$field] = wp_slash((string) ($stored[$col] ?? '')); // core unslashes wp_insert_post_data's result
            }
        }

        return $data;
    }

    /** Is column $col of a Zero row hidden from this viewer (judged on $stored, or the rules alone)? */
    public static function nativeHiddenFor(string $collection, string $col, ?array $stored = null): bool
    {
        $viewer = self::viewer();
        if ($viewer === null || $stored === null) {
            return $viewer === null || FieldAccess::mayHide(self::schema()->collection($collection)['columns'][$col], $viewer);
        }

        return !array_key_exists($col, self::visibleFields($stored, $collection, $viewer));
    }

    private static function nativeHidden(int $postId, string $designation): bool
    {
        $collection = $postId > 0 ? self::collection((string) get_post_type($postId)) : null;
        $col = $collection === null ? null : (self::schema()->collection($collection)[$designation] ?? null);
        if ($col === null) {
            return false;
        }
        return self::nativeHiddenFor($collection, $col, Plugin::instance()?->db()->editorRow($collection, $postId));
    }

    // ================================================================ wp-admin form

    /**
     * The edit screen doesn't render a top-level field holding anything the viewer can't read on this
     * post (a structured field with a hidden sub-field goes whole). Saves are EditorSave's to decide.
     */
    public static function formField(mixed $field): mixed
    {
        if (!is_array($field) || empty($field['zero_top']) || !is_admin()) {
            return $field;
        }
        $postId = (int) (acf_get_form_data('post_id') ?: 0);
        $collection = $postId > 0 ? self::collection((string) get_post_type($postId)) : null;
        $col = $field['zero']['name'] ?? null;
        if ($collection === null || !is_string($col)) {
            return $field;
        }
        $viewer = self::viewer();
        $spec = self::schema()->collection($collection);
        $def = $spec['columns'][$col] ?? null;
        if ($def === null || $viewer === null) {
            return $viewer === null ? false : $field;
        }
        $stored = Plugin::instance()?->db()->editorRow($collection, $postId);
        if ($stored === null) {
            // A new post holds nothing to hide; a trashed one is judged by the rules alone.
            return !FieldAccess::mayHide($def, $viewer) ? $field : false;
        }
        $visible = self::visibleFields($stored, $collection, $viewer);
        // A structured field renders inputs for every sub-field, empty ones included: any sub-field
        // that may be hidden from this viewer hides the whole field.
        $nested = in_array($def['type'], self::STRUCTURED, true) && FieldAccess::mayHide(['access' => []] + $def, $viewer);

        return !$nested && array_key_exists($col, $visible) && $visible[$col] === $stored[$col] ? $field : false;
    }

    // ================================================================ writes

    /** Does the update rule, given $data, cover row $pk as it is now? (Admission only; EditorSave decides.) */
    public static function mayUpdateWith(string $collection, int $pk, array $data, Viewer $viewer): bool
    {
        if (!isset(Access::collectionRules(self::schema()->collection($collection))['update']['fn'])) {
            return false;
        }

        return Plugin::instance()?->db()->permits($collection, 'update', $pk, $viewer, $data) ?? false;
    }

    /**
     * What a core REST create/update declares: [row data (JS form), supplied columns]. Every selector
     * SCF honours counts (meta name, field key, `_acf_field_key_map`), so no alias escapes the
     * authorization of the saved value (EditorSave).
     *
     * @return array{array<string, mixed>, list<string>}
     */
    public static function incoming(string $collection, \WP_REST_Request $request, bool $singleSelector = false): array
    {
        $spec = self::schema()->collection($collection);
        $data = [];
        foreach (['title' => $spec['title'] ?? null, 'content' => $spec['content'] ?? null, 'slug' => $spec['slug'] ?? null] as $param => $col) {
            if ($col !== null && $request->has_param($param)) {
                $v = $request->get_param($param);
                $data[$col] = is_array($v) ? ($v['raw'] ?? null) : $v;
            }
        }
        if (($spec['drafts'] ?? false) && is_string($status = $request->get_param('status'))) {
            $data['status'] = ['publish' => 'published', 'future' => 'scheduled'][$status] ?? 'draft';
        }
        $acf = $request->get_param('acf');
        if (is_array($acf)) {
            $map = is_array($acf['_acf_field_key_map'] ?? null) ? $acf['_acf_field_key_map'] : [];
            foreach ($acf as $name => $value) {
                $name = (string) ($map[$name] ?? $name);
                foreach ($spec['columns'] as $col => $def) {
                    $key = $spec['wp']['fieldKeyPrefix'] . $col;
                    if ($name === $key || $name === $spec['wp']['metaPrefix'] . $col) {
                        if ($singleSelector && array_key_exists($col, $data)) {
                            throw new HttpError(403, 'forbidden', "Submit $col only once", HttpError::seg((string) $col));
                        }
                        $data[(string) $col] = Scf::fromScf($def, $value, $key);
                    }
                }
            }
        }

        return [$data, array_map('strval', array_keys($data))];
    }

    // ================================================================ helpers

    public static function error(HttpError $e): \WP_Error
    {
        return new \WP_Error($e->errorCode, $e->getMessage(), ['status' => $e->status] + ($e->pointer === null ? [] : ['pointer' => $e->pointer]));
    }

    public static function collection(string $postType): ?string
    {
        foreach (self::postBacked() as $name => $spec) {
            if ($spec['wp']['postType'] === $postType) {
                return (string) $name;
            }
        }

        return null;
    }

    /** @return array<string, array> */
    private static function postBacked(): array
    {
        $schema = Plugin::instance()?->schema;

        return $schema === null ? [] : array_filter($schema->collections(), static fn (array $c) => ($c['editor'] ?? true) && ($c['wp'] ?? null) !== null);
    }

    private static function schema(): Schema
    {
        return Plugin::instance()?->schema ?? throw new \LogicException('No Zero app');
    }
}
