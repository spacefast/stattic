<?php

declare(strict_types=1);

namespace SpacePress\Sync;

use SpacePress\HttpError;
use SpacePress\Viewer;
use SpacePress\Wp\CompositeStore;
use SpacePress\Wp\Plugin;

use function SpacePress\db;

/**
 * WordPress trash for post-backed collections (§5.3): sync moves posts to the trash instead of deleting
 * them, lists trashed posts as `trashed: true`, and restores one when a record with its uid is pushed
 * again. A site whose trash is off (`EMPTY_TRASH_DAYS` 0) refuses a sync trash rather than delete. Every other store has no trash: deletes go through the pipeline and revisions keep the row.
 *
 * A trash is what wp-admin's "Move to Trash" is (docs/decisions/2026-11-14-trash-is-unpublish.md): the
 * item leaving the published set, and nothing else. Rows that point at it, its links and its revisions
 * stay, so a restore brings back exactly what was there. The delete lifecycle (beforeDelete, onDelete
 * actions, afterDelete) runs only when someone deletes the post from the trash for good.
 */
final class Trash
{
    private const UID_META = '_sp_uid';

    public static function postBacked(array $spec): bool
    {
        return (($spec['editor'] ?? true) || ($spec['commerce'] ?? null) === 'product') && isset($spec['wp']['postType']);
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
     * Move a post to the trash, in the request transaction; false when the collection has no trash.
     * A published item is unpublished: afterUpdate with the published row before and the trashed one
     * after, and the update notice. A draft leaving is nothing to visitors. $row is the full stored row
     * and the caller has authorized the trash.
     */
    public static function trash(string $collection, array $spec, array $row, Viewer $viewer): bool
    {
        if (!self::postBacked($spec)) {
            return false;
        }
        // With WordPress's trash turned off, wp_trash_post() deletes the post for good, with nobody
        // having chosen to. A sync never deletes: it refuses, before anything is written.
        if (defined('EMPTY_TRASH_DAYS') && !EMPTY_TRASH_DAYS) {
            throw new HttpError(409, 'trash_disabled', "This site turns WordPress's trash off (EMPTY_TRASH_DAYS is 0), so spacepress sync can't move $collection items to it, and won't delete them. Delete the item in wp-admin, or turn the trash on.");
        }
        db()->requireDeleteVisibility($collection, $row, $viewer);
        $pk = (int) $row[$spec['primaryKey']];
        $connection = Plugin::instance()->connection();
        $connection->touchPost($pk);
        $trashed = $connection->core(static function () use ($pk, $spec): mixed {
            if (($spec['commerce'] ?? null) === 'product') { $product = wc_get_product($pk); if (!$product) { return false; } $product->delete(false); return get_post_status($pk) === 'trash'; }
            return wp_trash_post($pk);
        });
        if (!$trashed) {
            throw new \RuntimeException("Could not move post $pk to the trash");
        }
        if (($row['status'] ?? null) === 'published') {
            self::changed($collection, $pk, $row, $viewer);
        }

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

    /**
     * Take a trashed post out of the trash as a draft, with no hooks of its own: the push that restores
     * it then writes the record's values, status included, as one update from the trashed row (which
     * reads as a draft) to what the file says. So its hooks run once, with the final values: back to
     * published is a publish, back to a draft is nothing to visitors.
     */
    public static function restore(int $id): void
    {
        $connection = Plugin::instance()->connection();
        $draft = static fn (): string => 'draft';
        add_filter('wp_untrash_post_status', $draft, PHP_INT_MAX, 0);
        try {
            $connection->touchPost($id);
            if (!$connection->core(static fn () => wp_untrash_post($id))) {
                throw new \RuntimeException("Could not restore post $id from the trash");
            }
        } finally {
            remove_filter('wp_untrash_post_status', $draft, PHP_INT_MAX);
        }
    }

    /** The update lifecycle of a published item moving into the trash, with trashed posts readable. */
    private static function changed(string $collection, int $id, array $before, Viewer $viewer): void
    {
        $store = db()->store();
        $run = static fn () => db()->afterEditorSave($collection, $id, $before, $viewer->user);
        $store instanceof CompositeStore ? $store->withTrash($collection, $run) : $run();
    }
}
