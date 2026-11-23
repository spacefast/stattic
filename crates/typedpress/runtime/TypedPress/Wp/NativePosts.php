<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * WordPress's own posts as an app kind (`export const posts = wp.posts.extend(fields, { url })`).
 *
 * Core registers the `post` type and keeps it: its capabilities, the block editor, comments,
 * revisions, sticky posts, formats and feeds stay core's. TypedPress adds, on core's hooks:
 * - `register_post_type_args`: core's posts route answers through TypedPress's controller
 *   ({@see PostsController}), so the app's API, rules and field masks read the same rows the editor
 *   writes, and core's content abilities leave the type to the app's own abilities;
 * - the app's fields as post meta ({@see PostMeta}, registered with the app's other kinds);
 * - `post_link` builds each post's URL from the kind's `url` ({@see KindUrls});
 * - core's sitemap of posts leaves `post` out, since TypedPress's sitemap lists the kind ({@see Sitemap}).
 *
 * The app's access rules narrow core's capabilities and never widen them ({@see CoreAccess::mapMetaCap}).
 */
final class NativePosts
{
    public const TYPE = 'post';

    public static function register(\TypedPress\Schema $schema): void
    {
        if (self::kind($schema) === null) {
            return;
        }
        add_filter('register_post_type_args', static function (array $args, string $type): array {
            if ($type !== self::TYPE) {
                return $args;
            }
            return ['rest_controller_class' => PostsController::class, 'show_in_abilities' => false] + $args;
        }, PHP_INT_MAX, 2);
        // Core registered `post` before this plugin loaded (create_initial_post_types runs again on init 0,
        // and the filter above applies then): until then, its object already has the right controller.
        $object = get_post_type_object(self::TYPE);
        if ($object instanceof \WP_Post_Type) {
            $object->rest_controller_class = PostsController::class;
            $object->rest_controller = null;
            $object->show_in_abilities = false;
        }
        // A write through TypedPress's pipeline (its API, an action's `posts.update`) needs what core's
        // own screens need: the app's rule runs after this and can only narrow it.
        $kind = self::kind($schema);
        \TypedPress\Extensions::onWrite(static function (string $op, string $collection, array &$data, ?array $existing, ?\TypedPress\Viewer $viewer) use ($kind): void {
            if ($collection !== $kind || $viewer === null) {
                return;
            }
            $user = (int) ($viewer->user['id'] ?? 0);
            $id = (int) ($existing['id'] ?? 0);
            $publishing = ($data['status'] ?? null) === 'published' || ($data['status'] ?? null) === 'scheduled'
                || ($op === 'create' && !array_key_exists('status', $data));
            $allowed = match ($op) {
                'create' => user_can($user, 'edit_posts') && (!$publishing || user_can($user, 'publish_posts')),
                'update' => user_can($user, 'edit_post', $id) && (!$publishing || ($existing['status'] ?? null) === 'published' || user_can($user, 'publish_post', $id)),
                'delete' => user_can($user, 'delete_post', $id),
                default => true,
            };
            if (!$allowed) {
                throw \TypedPress\Access::deny($viewer->user, "$op $collection rows");
            }
        });
        add_filter('wp_sitemaps_post_types', static function (array $types): array {
            unset($types[self::TYPE]);
            return $types;
        });
    }

    /**
     * A deploy that turns the app's `posts` collection into WordPress's own posts: the collection's
     * items (post type `tp_posts`) become core posts in place. IDs, meta (the fields keep their
     * `tp_` keys), revisions, comments and uids stay as they were; the excerpt the collection kept as
     * the field `tp_excerpt` becomes the post's own excerpt, on each revision too, comments open when
     * the kind has them on, and a slug a core post already has is made unique by WordPress (the old one
     * kept as `_wp_old_slug`). Nothing is deleted. Runs once per app and site, in one transaction, under
     * the deploy lock and after migrations. The previous installation of this app on this site
     * must have declared the source post type.
     *
     * @return int how many items moved
     */
    public static function adopt(Plugin $plugin): int
    {
        $kind = self::kind($plugin->schema);
        if ($kind === null) {
            return 0;
        }
        $old = 'tp_' . $kind;
        $app = $plugin->schema->name();
        $previous = get_option('tp_' . $app . '_post_types');
        $types = $previous['types'] ?? null;
        $schemaHash = $previous['schemaHash'] ?? null;
        if (!is_array($types) || !in_array($old, $types, true)
            || !is_string($schemaHash) || $schemaHash !== get_option('tp_' . $app . '_schema')) {
            return 0;
        }
        foreach ($plugin->schema->collections() as $spec) {
            if (($spec['wp']['postType'] ?? null) === $old) {
                return 0; // another kind of the app still owns that type
            }
        }
        // Once per app and site: the option records that this app's `tp_<kind>` items were adopted.
        $done = 'tp_' . $app . '_adopted_posts';
        if (get_option($done) !== false) {
            return 0;
        }
        $connection = $plugin->connection();
        $wpdb = $connection->wpdb();
        $moved = $connection->transaction(static function () use ($wpdb, $connection, $plugin, $kind, $old): array {
            $ids = array_map('intval', array_column($connection->run("SELECT ID FROM `{$wpdb->posts}` WHERE post_type = ? ORDER BY ID FOR UPDATE", [$old]), 'ID'));
            // A collection without comments stored every item's comments as closed. Posts take comments
            // unless the kind turns them off, so the moved items get them; this can't tell an item an
            // editor closed by hand from one closed by the old default, and opens both.
            $open = ($plugin->schema->collection($kind)['comments'] ?? false) === true;
            foreach ($ids as $id) {
                $post = get_post($id);
                // A core post may already have this slug: WordPress makes the moved one unique, as it would
                // on a save. The old slug is kept as `_wp_old_slug`, so its old address still redirects.
                $slug = wp_unique_post_slug($post->post_name, $id, $post->post_status, self::TYPE, (int) $post->post_parent);
                $set = ['post_type' => self::TYPE] + ($open ? ['comment_status' => 'open'] : []) + ($slug !== $post->post_name ? ['post_name' => $slug] : []);
                $revisions = array_map('intval', array_column($connection->run("SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'revision' AND post_parent = ?", [$id]), 'ID'));
                foreach ([$id, ...$revisions] as $row) {
                    $excerpt = get_metadata('post', $row, 'tp_excerpt', true); // the revision's own, not its parent's
                    if (is_string($excerpt) && $excerpt !== '') {
                        $connection->run("UPDATE `{$wpdb->posts}` SET post_excerpt = ? WHERE ID = ? AND post_excerpt = ''", [$excerpt, $row]);
                    }
                }
                $assign = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($set)));
                $connection->run("UPDATE `{$wpdb->posts}` SET $assign WHERE ID = ?", [...array_values($set), $id]);
                if ($slug !== $post->post_name && $post->post_name !== '') {
                    add_post_meta($id, '_wp_old_slug', $post->post_name);
                }
            }

            return $ids;
        });
        foreach ($moved as $id) {
            clean_post_cache($id);
            foreach (wp_get_post_revisions($id, ['fields' => 'ids']) as $revision) {
                clean_post_cache((int) $revision);
            }
        }
        $moved = count($moved);
        update_option($done, (string) $moved, false);
        if ($moved > 0) {
            error_log(sprintf('TypedPress: %d %s items are now WordPress posts', $moved, $old));
        }

        return $moved;
    }

    /** Remember the post types the app declared on this site after a successful migration. */
    public static function rememberPostTypes(\TypedPress\Schema $schema, string $schemaHash): void
    {
        $types = array_column(array_column($schema->collections(), 'wp'), 'postType');
        update_option('tp_' . $schema->name() . '_post_types', ['schemaHash' => $schemaHash, 'types' => $types], false);
    }

    /**
     * Which unpublished posts $viewer may read, as core's `read_post` answers it: none for a visitor,
     * their own for an author, all for whoever has `read_others_posts`-level rights (`edit_others_posts`
     * and `read_private_posts`). Null when the viewer reads every post. A SQL predicate on `{a}`.
     */
    public static function readGuard(Connection $db, \TypedPress\Viewer $viewer): ?\TypedPress\SqlPredicate
    {
        $user = (int) ($viewer->user['id'] ?? 0);
        if ($user > 0 && user_can($user, 'edit_others_posts') && user_can($user, 'read_private_posts')) {
            return null;
        }
        // Published (and scheduled, which core shows to no visitor but whose row the API lists as
        // `scheduled` to its writers only): visible; anything else only to its author with edit_posts.
        if ($user > 0 && user_can($user, 'edit_posts')) {
            return new \TypedPress\SqlPredicate("({a}.post_status = 'publish' OR {a}.post_author = ?)", [$user]);
        }

        return new \TypedPress\SqlPredicate("{a}.post_status = 'publish'");
    }

    /**
     * Core's capability for $op on post $id as $viewer, for TypedPress paths that act on a row without
     * going through WordPress's save (sync's trash, staged versions): edit for reads of unpublished
     * work and staging, delete for deletes.
     */
    public static function allows(?\TypedPress\Viewer $viewer, string $op, int $id): bool
    {
        if ($viewer === null) {
            return true; // trusted callers (WP-CLI, the publish seed)
        }
        $user = (int) ($viewer->user['id'] ?? 0);

        return $user > 0 && user_can($user, $op === 'delete' ? 'delete_post' : 'edit_post', $id);
    }

    /** The app's name for WordPress's posts, when it exports them. */
    public static function kind(\TypedPress\Schema $schema): ?string
    {
        foreach ($schema->collections() as $name => $spec) {
            if ($spec['native'] ?? false) {
                return (string) $name;
            }
        }
        return null;
    }
}
