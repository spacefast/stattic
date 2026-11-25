<?php

declare(strict_types=1);

namespace SpacePress\Live;

use SpacePress\Extensions;
use SpacePress\SystemTables;
use SpacePress\Wp\CompositeStore;
use SpacePress\Wp\Connection;

use function SpacePress\db;

/**
 * Change counters for reactive queries (docs/decisions/2026-11-13-reactive-queries.md), in one
 * table, `sp__versions`. Each kind has up to two:
 *
 * - `all` counts every committed transaction that wrote a row of the kind;
 * - `published` (kinds with drafts) counts only those that wrote a row which is published before
 *   or after the write, so a visitor who reads published rows only never has a counter that moves
 *   for a draft.
 *
 * Every pipeline write reports through Extensions::onWritten inside its transaction: API and
 * action writes, hooks, jobs, the CLI, wp-admin and core REST saves. The counters are written as
 * that transaction's last statements, just before COMMIT. They commit or roll back with the
 * write, and a kind's counter row is locked only while the commit runs, so a slow handler never
 * makes other writers of the kind wait. Kinds are taken in name order, so transactions that wrote
 * the same kinds can't deadlock on their counters.
 *
 * The numbers never leave the server in the clear: Token seals them.
 */
final class Versions
{
    public const ALL = 'all';
    public const PUBLISHED = 'published';

    /** @var array<string, bool> collection => whether its `published` counter moves too */
    private static array $pending = [];
    private static bool $armed = false;

    public static function register(): void
    {
        $text = 'COLLATE utf8mb4_bin';
        SystemTables::register('__sp_versions', [
            "CREATE TABLE IF NOT EXISTS `{prefix}sp__versions` (`kind` VARCHAR(191) $text NOT NULL, `scope` VARCHAR(16) $text NOT NULL, `version` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`kind`, `scope`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin",
        ]);
        Extensions::onWritten(self::written(...));
        if (function_exists('add_action')) {
            // Moving a post to the trash or out of it is a status change WordPress makes without
            // SpacePress's write path (the editor's save hooks skip trashed posts): count it here.
            add_action('transition_post_status', [self::class, 'trashed'], 10, 3);
        }
    }

    /** `transition_post_status`: a post of a SpacePress kind moved into or out of the trash. */
    public static function trashed(string $new, string $old, \WP_Post $post): void
    {
        if ($new === $old || ($new !== 'trash' && $old !== 'trash')) {
            return;
        }
        $collection = \SpacePress\Wp\CoreAccess::collection($post->post_type);
        $store = $collection === null ? null : db()->store();
        if ($collection === null || !$store instanceof CompositeStore) {
            return;
        }
        self::touch($store->db, $collection, $new === 'publish' || $old === 'publish');
    }

    /** Extensions::onWritten: inside the write's transaction. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        $db = db();
        $spec = $db->schema()->collections()[$collection] ?? null;
        $store = $db->store();
        if ($spec === null || !$store instanceof CompositeStore) {
            return;
        }
        // Published before or after. A row whose status can't be told counts as published: a
        // counter may move once too often, never once too rarely. A kind without drafts has no
        // `published` counter.
        $draft = static fn (?array $r): bool => $r === null || (array_key_exists('status', $r) && $r['status'] !== 'published');
        self::touch($store->db, $collection, ($spec['drafts'] ?? false) && (!$draft($row) || !$draft($previous)));
    }

    /**
     * Counts one change to $collection in $connection's open transaction, or at once when none is
     * open; $published also moves the `published` counter of a kind with drafts. For writes that
     * don't pass the write pipeline (scheduled promotion).
     */
    public static function touch(Connection $connection, string $collection, bool $published = false): void
    {
        self::$pending[$collection] = ($published || (self::$pending[$collection] ?? false));
        if (self::$armed) {
            return;
        }
        self::$armed = true;
        if (!$connection->inTransaction()) {
            $connection->transaction(static fn () => self::flush($connection));

            return;
        }
        $connection->onRollback(static function (): void {
            self::$pending = [];
            self::$armed = false;
        });
        $connection->beforeCommit(static fn () => self::flush($connection));
    }

    /** The committing transaction's last statements. */
    private static function flush(Connection $connection): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$armed = false;
        ksort($pending, SORT_STRING);
        $table = self::table($connection);
        foreach ($pending as $kind => $published) {
            foreach ($published ? [self::ALL, self::PUBLISHED] : [self::ALL] as $scope) {
                $connection->run("INSERT INTO $table (`kind`, `scope`, `version`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `version` = `version` + 1", [(string) $kind, $scope]);
            }
        }
    }

    /**
     * The counters of $pairs as they stand now, 0 for one never written, in $pairs' order.
     *
     * @param list<array{string, string}> $pairs [kind, scope]
     * @return list<int>
     */
    public static function read(Connection $connection, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($pairs as [$kind, $scope]) {
            $where[] = '(`kind` = ? AND `scope` = ?)';
            $params[] = $kind;
            $params[] = $scope;
        }
        $found = [];
        foreach ($connection->run('SELECT `kind`, `scope`, `version` FROM ' . self::table($connection) . ' WHERE ' . implode(' OR ', $where), $params) as $row) {
            $found[$row['kind'] . "\0" . $row['scope']] = (int) $row['version'];
        }

        return array_map(static fn (array $pair): int => $found[$pair[0] . "\0" . $pair[1]] ?? 0, $pairs);
    }

    private static function table(Connection $connection): string
    {
        return '`' . $connection->prefix() . 'sp__versions`';
    }
}
