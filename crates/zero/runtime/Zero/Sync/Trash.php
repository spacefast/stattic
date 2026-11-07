<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\Wp\Plugin;

use function Zero\db;

/**
 * WordPress trash for post-backed collections (§5.3): sync moves posts to the trash instead of deleting
 * them, lists trashed posts as `trashed: true`, and restores one when a record with its uid is pushed
 * again. Every other store has no trash: deletes go through the pipeline and revisions keep the row.
 */
final class Trash
{
    private const UID_META = '_zero_uid';

    public static function postBacked(array $spec): bool
    {
        return ($spec['editor'] ?? true) && ($spec['wp'] ?? null) !== null;
    }

    /** @return array<int, string> post id → uid of trashed posts with id > $after, ascending, at most $limit */
    public static function listed(string $collection, array $spec, int $after, int $limit): array
    {
        if (!self::postBacked($spec)) {
            return [];
        }
        $wpdb = Plugin::instance()->connection()->wpdb();
        $rows = Plugin::instance()->connection()->run(
            "SELECT p.ID, m.meta_value AS uid FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id = p.ID AND m.meta_key = ?"
            . " WHERE p.post_type = ? AND p.post_status = 'trash' AND p.ID > ? ORDER BY p.ID LIMIT $limit",
            [self::UID_META, $spec['wp']['postType'], $after],
        );

        return array_column($rows, 'uid', 'ID');
    }

    /**
     * Move a post to the trash with the pipeline's delete lifecycle around it (delete extensions,
     * beforeDelete, FK actions, afterDelete), in the request transaction; false when the collection has
     * no trash. $row is the full stored row and the caller has authorized the delete.
     */
    public static function trash(string $collection, array $spec, array $row): bool
    {
        if (!self::postBacked($spec)) {
            return false;
        }
        $pk = (int) $row[$spec['primaryKey']];
        $connection = Plugin::instance()->connection();
        $user = db()->actor();
        db()->beforeEditorDelete($collection, $row, $user);
        $connection->touchPost($pk);
        if (!$connection->core(static fn () => wp_trash_post($pk))) {
            throw new \RuntimeException("Could not move post $pk to the trash");
        }
        db()->afterEditorDelete($collection, $row, $user);

        return true;
    }

    /** The trashed post holding this uid, locked until commit; null when none or no trash. */
    public static function trashed(array $spec, string $uid): ?int
    {
        if (!self::postBacked($spec)) {
            return null;
        }
        $connection = Plugin::instance()->connection();
        $wpdb = $connection->wpdb();
        $id = $connection->run(
            "SELECT p.ID FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id = p.ID AND m.meta_key = ? AND m.meta_value = ?"
            . " WHERE p.post_type = ? AND p.post_status = 'trash' LIMIT 1 FOR UPDATE",
            [self::UID_META, $uid, $spec['wp']['postType']],
        )[0]['ID'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** Bring a trashed post back to the status it had, so the push updates it in place. */
    public static function restore(int $id): void
    {
        $connection = Plugin::instance()->connection();
        $previous = static fn ($status, $postId, $before) => $before;
        add_filter('wp_untrash_post_status', $previous, 10, 3);
        try {
            $connection->touchPost($id);
            if (!$connection->core(static fn () => wp_untrash_post($id))) {
                throw new \RuntimeException("Could not restore post $id from the trash");
            }
        } finally {
            remove_filter('wp_untrash_post_status', $previous, 10);
        }
    }
}
