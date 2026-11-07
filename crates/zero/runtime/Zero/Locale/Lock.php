<?php

declare(strict_types=1);

namespace Zero\Locale;

use Zero\HttpError;
use Zero\Js;
use Zero\Wp\CompositeStore;

use function Zero\db;

/**
 * Concurrency for translation groups on MySQL (§7.3). InnoDB's REPEATABLE READ gives a request a
 * snapshot, so a value copied from a group, or the set of rows a change is propagated to, could be
 * stale by the time this request commits. Locking reads see the latest committed state and hold it:
 * when that differs from the request's snapshot, another request changed the group first and this
 * one fails with 409 `conflict` (retry) instead of committing a divergent group. The SQLite
 * translator serializes writers (one transaction at a time), so there the locking read is a plain one.
 */
final class Lock
{
    /**
     * The row as this request sees it must still be the latest committed version: compares a plain
     * read (the request's snapshot) with a locking read of every place the row is stored (the
     * wp_posts row and its Zero postmeta, or the table row), and holds the lock.
     */
    public static function unchanged(string $collection, int|string $pk): void
    {
        $store = db()->store();
        if (!$store instanceof CompositeStore) {
            return;
        }
        $queries = $store->isPostBacked($collection)
            ? [
                ['SELECT * FROM `' . $store->db->wpdb()->posts . '` WHERE ID = ?', [(int) $pk]],
                ['SELECT meta_id, meta_key, meta_value FROM `' . $store->db->wpdb()->postmeta . "` WHERE post_id = ? AND (meta_key LIKE 'zero\\_%' OR meta_key LIKE '\\_zero\\_%') ORDER BY meta_id", [(int) $pk]],
            ]
            : [['SELECT * FROM `' . $store->tables->table($collection) . '` WHERE `' . db()->schema()->collection($collection)['primaryKey'] . '` = ?', [$pk]]];
        foreach ($queries as [$sql, $params]) {
            if (Js::jsonStringify($store->db->run($sql, $params)) !== Js::jsonStringify($store->db->run($sql . ' FOR UPDATE', $params))) {
                throw self::conflict();
            }
        }
    }

    /**
     * Every member of $row's group as currently committed (locked), as locale => pk. Throws when a
     * member exists that this request's snapshot can't see.
     *
     * @param array<string, int|string> $seen locale => pk as this request sees the group
     * @return array<string, int|string>
     */
    public static function members(string $collection, array $row, array $seen): array
    {
        $store = db()->store();
        if (!$store instanceof CompositeStore) {
            return $seen;
        }
        $pk = db()->schema()->collection($collection)['primaryKey'];
        if ($store->isPostBacked($collection)) {
            $current = \Zero\Wp\Polylang::locked($store->db, array_map('intval', array_values($seen)));
        } else {
            $current = [];
            if (($row['translationGroup'] ?? null) !== null) {
                foreach ($store->db->run('SELECT `' . $pk . '` AS pk, `locale` FROM `' . $store->tables->table($collection) . '` WHERE `translationGroup` = ? FOR UPDATE', [$row['translationGroup']]) as $r) {
                    $current[(string) $r['locale']] = \Zero\Schema::decode(db()->schema()->collection($collection)['columns'][$pk], $r['pk']);
                }
            }
            $current += $seen;
        }
        if (array_diff(array_map('strval', $current), array_map('strval', $seen)) !== []) {
            throw self::conflict();
        }

        return $current;
    }

    private static function conflict(): HttpError
    {
        return new HttpError(409, 'conflict', 'The translation group changed while this request ran; retry it');
    }
}
