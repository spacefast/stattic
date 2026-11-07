<?php

declare(strict_types=1);

namespace Zero\Jobs;

use function Zero\db;

/**
 * Raw SQL on the jobs system tables, on the active store's connection (so it shares the request
 * transaction). Statements are written with "double-quoted" identifiers and `{outbox}`, `{jobs}`,
 * `{receipts}` placeholders, rendered with backticks and the WordPress table prefix.
 */
final class Storage
{
    public const TABLES = ['outbox', 'jobs', 'receipts'];

    /** @param list<int|float|string|null> $params @return list<array<string, mixed>> */
    public static function run(string $sql, array $params = []): array
    {
        $store = db()->store();
        if ($store instanceof \Zero\Wp\CompositeStore) {
            return $store->db->run(self::sql($sql, $store->db->prefix()), $params);
        }

        throw new \LogicException('Zero jobs need the WordPress store');
    }

    /** A dedupe key, receipt scope or key as a parameter: a `VARBINARY` column's value, compared byte for byte. */
    public static function bytes(string $value): \Zero\Wp\Bytes
    {
        return new \Zero\Wp\Bytes($value);
    }

    public static function forUpdate(): string
    {
        return ' FOR UPDATE';
    }

    /** A shared locking read: sees the latest commit without asking for an exclusive lock. */
    public static function forShare(): string
    {
        return ' LOCK IN SHARE MODE';
    }

    /** Rows changed by the previous statement on this connection (0 for an ignored insert). */
    public static function changes(): int
    {
        $store = db()->store();
        if ($store instanceof \Zero\Wp\CompositeStore) {
            return $store->db->changes();
        }

        throw new \LogicException('Zero jobs need the WordPress store');
    }

    /** `UPDATE IGNORE`: a unique conflict leaves the row unchanged instead of failing. */
    public static function updateIgnore(): string
    {
        return 'UPDATE IGNORE';
    }

    public static function insertIgnore(): string
    {
        return 'INSERT IGNORE';
    }

    /** The statement with `{outbox}`/`{jobs}`/`{receipts}` and "double-quoted" identifiers made MySQL's. */
    public static function sql(string $sql, string $prefix): string
    {
        foreach (self::TABLES as $t) {
            $sql = str_replace('{' . $t . '}', "\"{$prefix}zero__$t\"", $sql);
        }

        return str_replace('"', '`', $sql);
    }

    /** Idempotent DDL for SystemTables. */
    public static function register(): void
    {
        $text = 'COLLATE utf8mb4_bin';
        // Keys compare byte for byte: a PAD SPACE collation would make "k" and "k " collide.
        $bytes = 'VARBINARY(764)';
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        \Zero\SystemTables::register('__zero_outbox', [
            "CREATE TABLE IF NOT EXISTS `{prefix}zero__outbox` (`id` CHAR(36) NOT NULL, `job` VARCHAR(191) $text NOT NULL, `input` LONGTEXT NOT NULL, `key` VARCHAR(191) $text NULL, `runAt` CHAR(24) NOT NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`id`)) $tail",
        ]);
        // `dedupe` is the key while the job is pending or running (§6.1) and NULL otherwise;
        // UNIQUE(job, dedupe) is the one dedupe mechanism (NULLs never collide).
        \Zero\SystemTables::register('__zero_jobs', [
            "CREATE TABLE IF NOT EXISTS `{prefix}zero__jobs` (`id` CHAR(36) NOT NULL, `job` VARCHAR(191) $text NOT NULL, `input` LONGTEXT NOT NULL, `key` $bytes NULL, `dedupe` $bytes NULL, `state` VARCHAR(16) NOT NULL, `attempts` INT NOT NULL DEFAULT 0, `runAt` CHAR(24) NOT NULL, `lockedAt` CHAR(24) NULL, `lastError` TEXT NULL, `createdAt` CHAR(24) NOT NULL, `doneAt` CHAR(24) NULL, PRIMARY KEY (`id`), UNIQUE KEY `dedupe` (`job`, `dedupe`), KEY `due` (`state`, `runAt`)) $tail",
        ]);
        \Zero\SystemTables::register('__zero_receipts', [
            "CREATE TABLE IF NOT EXISTS `{prefix}zero__receipts` (`scope` VARBINARY(764) NOT NULL, `key` VARBINARY(256) NOT NULL, `userId` BIGINT NOT NULL, `inputHash` CHAR(64) NOT NULL, `state` VARCHAR(16) NOT NULL, `result` LONGTEXT NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`scope`, `key`, `userId`), KEY `created` (`createdAt`)) $tail",
        ]);
    }
}
