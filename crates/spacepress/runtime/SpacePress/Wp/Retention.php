<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Js;
use SpacePress\Schema;

/**
 * Config-driven retention policy (ADR 2026-11-21 §4).
 *
 * By default (`retention` omitted or `"forever"`), SpacePress NEVER automatically purges
 * soft-deleted rows, trashed posts, or revisions — WordPress's 30-day `wp_scheduled_delete`
 * is unhooked for SpacePress post types so retention is governed solely by `spacepress.config.ts`.
 *
 * When finite retention is configured (globally or per kind), `sweep()` purges expired soft-deleted
 * rows and prunes old revisions in bounded batches from Action Scheduler maintenance.
 */
final class Retention
{
    /** Effective retention in seconds for soft-deleted rows of $collection, or null for `"forever"`. */
    public static function deletedSeconds(Schema $schema, string $collection): ?int
    {
        $spec = $schema->hasCollection($collection) ? $schema->collection($collection) : null;
        if ($spec !== null && array_key_exists('retention', $spec) && is_array($spec['retention'])) {
            $sec = $spec['retention']['deletedSeconds'] ?? null;

            return is_int($sec) ? $sec : null;
        }
        $global = $schema->raw()['retention'] ?? null;
        if (is_array($global)) {
            $sec = $global['deletedSeconds'] ?? null;

            return is_int($sec) ? $sec : null;
        }

        return null;
    }

    /**
     * Effective revision retention for $collection: `['keepLast' => ?int, 'maxAgeSeconds' => ?int]`
     * or null for `"forever"`.
     *
     * @return ?array{keepLast: ?int, maxAgeSeconds: ?int}
     */
    public static function revisionsPolicy(Schema $schema, string $collection): ?array
    {
        $spec = $schema->hasCollection($collection) ? $schema->collection($collection) : null;
        if ($spec !== null && array_key_exists('retention', $spec) && is_array($spec['retention'])) {
            return is_array($spec['retention']['revisions'] ?? null) ? $spec['retention']['revisions'] : null;
        }
        $global = $schema->raw()['retention'] ?? null;
        if (is_array($global) && is_array($global['revisions'] ?? null)) {
            return $global['revisions'];
        }

        return null;
    }

    /**
     * True when WordPress's built-in 30-day `wp_scheduled_delete` must NOT auto-purge this post type.
     * Any SpacePress post type with `softDelete: true` is governed by SpacePress's retention policy
     * (default `"forever"`), never WordPress's hardcoded 30-day trash timer.
     */
    public static function blocksWpScheduledDelete(Schema $schema, string $postType): bool
    {
        foreach ($schema->collections() as $spec) {
            if (($spec['wp']['postType'] ?? null) === $postType && ($spec['softDelete'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sweep expired soft-deleted rows and revisions in bounded batches (called from hourly maintenance).
     *
     * @return array{purged: int, revisionsPruned: int}
     */
    public static function sweep(Plugin $plugin, int $batchSize = 100): array
    {
        $schema = $plugin->schema;
        $db = $plugin->db();
        $nowMs = Js::now();
        $purged = 0;
        $revisionsPruned = 0;

        $budget = max(1, $batchSize);

        foreach ($schema->collections() as $name => $spec) {
            if ($purged >= $budget) {
                break;
            }
            $name = (string) $name;
            if (!($spec['softDelete'] ?? false)) {
                continue;
            }
            $deletedSeconds = self::deletedSeconds($schema, $name);
            if ($deletedSeconds === null) {
                continue;
            }
            $cutoff = Js::iso($nowMs - ($deletedSeconds * 1000));
            $pk = $spec['primaryKey'];
            $remaining = $budget - $purged;
            $expired = $db->findMany($name, [
                'deleted' => 'only',
                'where' => ['deletedAt' => ['lte' => $cutoff]],
                'columns' => [$pk => true],
                'limit' => $remaining,
            ]);
            foreach ($expired as $row) {
                if ($purged >= $budget) {
                    break;
                }
                // Recheck `deleted = 'only'` and the cutoff inside the purge transaction so a row
                // restored or re-deleted after selection is never purged.
                $gone = $db->transaction(static fn (): array => $db->purge(
                    $name,
                    [$pk => $row[$pk], 'deletedAt' => ['lte' => $cutoff]],
                    returning: true,
                    onlyDeleted: true,
                ) ?? []);
                $purged += count($gone);
            }
        }

        $conn = $plugin->connection();
        $revTableName = $conn->prefix() . 'sp__revisions';
        $revTable = '`' . $revTableName . '`';
        $hasRevTable = $conn->run(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$revTableName],
        ) !== [];
        $postsTable = '`' . $conn->wpdb()->posts . '`';

        foreach ($schema->collections() as $name => $spec) {
            if ($revisionsPruned >= $budget) {
                break;
            }
            $name = (string) $name;
            $policy = self::revisionsPolicy($schema, $name);
            if ($policy === null) {
                continue;
            }
            if (isset($spec['wp']['postType'])) {
                $postType = (string) $spec['wp']['postType'];
                if (is_int($policy['maxAgeSeconds'] ?? null)) {
                    $remaining = $budget - $revisionsPruned;
                    $cutoffMysql = gmdate('Y-m-d H:i:s', (int) floor(($nowMs - ($policy['maxAgeSeconds'] * 1000)) / 1000));
                    $old = $conn->run(
                        "SELECT r.ID FROM $postsTable r JOIN $postsTable p ON p.ID = r.post_parent WHERE p.post_type = ? AND r.post_type = 'revision' AND r.post_status = 'inherit' AND r.post_name NOT LIKE '%autosave%' AND r.post_date_gmt <> '0000-00-00 00:00:00' AND r.post_date_gmt <= ? LIMIT $remaining",
                        [$postType, $cutoffMysql],
                    );
                    foreach ($old as $r) {
                        if ($revisionsPruned >= $budget) {
                            break;
                        }
                        if ($conn->core(static fn () => wp_delete_post_revision((int) $r['ID']))) {
                            $revisionsPruned++;
                        }
                    }
                }
                if ($revisionsPruned < $budget && is_int($policy['keepLast'] ?? null) && $policy['keepLast'] >= 1) {
                    $keep = (int) $policy['keepLast'];
                    $remaining = $budget - $revisionsPruned;
                    $parents = $conn->run(
                        "SELECT r.post_parent FROM $postsTable r JOIN $postsTable p ON p.ID = r.post_parent WHERE p.post_type = ? AND r.post_type = 'revision' AND r.post_status = 'inherit' AND r.post_name NOT LIKE '%autosave%' GROUP BY r.post_parent HAVING COUNT(*) > ? LIMIT $remaining",
                        [$postType, $keep],
                    );
                    foreach ($parents as $parent) {
                        if ($revisionsPruned >= $budget) {
                            break;
                        }
                        $rem = $budget - $revisionsPruned;
                        $rows = $conn->run(
                            "SELECT ID FROM $postsTable WHERE post_type = 'revision' AND post_parent = ? AND post_status = 'inherit' AND post_name NOT LIKE '%autosave%' ORDER BY ID DESC LIMIT $rem OFFSET $keep",
                            [(int) $parent['post_parent']],
                        );
                        foreach ($rows as $r) {
                            if ($revisionsPruned >= $budget) {
                                break;
                            }
                            if ($conn->core(static fn () => wp_delete_post_revision((int) $r['ID']))) {
                                $revisionsPruned++;
                            }
                        }
                    }
                }
                continue;
            }
            if (!$hasRevTable) {
                continue;
            }
            if (is_int($policy['maxAgeSeconds'] ?? null)) {
                $remaining = $budget - $revisionsPruned;
                $cutoff = Js::iso($nowMs - ($policy['maxAgeSeconds'] * 1000));
                $old = $conn->run("SELECT `id` FROM $revTable WHERE `collection` = ? AND `at` <= ? LIMIT $remaining", [$name, $cutoff]);
                foreach ($old as $r) {
                    if ($revisionsPruned >= $budget) {
                        break;
                    }
                    $conn->run("DELETE FROM $revTable WHERE `id` = ?", [(int) $r['id']]);
                    $revisionsPruned++;
                }
            }
            if ($revisionsPruned < $budget && is_int($policy['keepLast'] ?? null) && $policy['keepLast'] >= 1) {
                $keep = (int) $policy['keepLast'];
                $remaining = $budget - $revisionsPruned;
                $pks = $conn->run("SELECT `pk` FROM $revTable WHERE `collection` = ? GROUP BY `pk` HAVING COUNT(*) > ? LIMIT $remaining", [$name, $keep]);
                foreach ($pks as $p) {
                    if ($revisionsPruned >= $budget) {
                        break;
                    }
                    $rem = $budget - $revisionsPruned;
                    $rows = $conn->run("SELECT `id` FROM $revTable WHERE `collection` = ? AND `pk` = ? ORDER BY `id` DESC LIMIT $rem OFFSET $keep", [$name, (string) $p['pk']]);
                    foreach ($rows as $r) {
                        if ($revisionsPruned >= $budget) {
                            break;
                        }
                        $conn->run("DELETE FROM $revTable WHERE `id` = ?", [(int) $r['id']]);
                        $revisionsPruned++;
                    }
                }
            }
        }

        return ['purged' => $purged, 'revisionsPruned' => $revisionsPruned];
    }
}
