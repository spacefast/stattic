<?php

declare(strict_types=1);

namespace SpacePress\Search;

use function SpacePress\db;

/**
 * SQL on `sp__search`, on the active store's connection (the request's transaction), with the
 * two ways a term matches: MySQL and MariaDB use the FULLTEXT index in boolean mode; the SQLite
 * translator, which accepts the index but not `MATCH ... AGAINST`, matches word prefixes with `LIKE`.
 */
final class Storage
{
    private static ?bool $ready = null;

    /** @param list<int|float|string|null> $params @return list<array<string, mixed>> */
    public static function run(string $sql, array $params = []): array
    {
        $store = db()->store();
        if (!$store instanceof \SpacePress\Wp\CompositeStore) {
            throw new \LogicException('SpacePress search needs the WordPress store');
        }

        return $store->db->run(str_replace(['{search}', '"'], [self::table(), '`'], $sql), $params);
    }

    /**
     * One entry written in place, keyed by (`kind`, `item`, `field`): inserted, or its `body`
     * replaced. Parameters: kind, item, field, body. The SQLite translator accepts MySQL's
     * `ON DUPLICATE KEY UPDATE`.
     */
    public static function upsert(): string
    {
        return 'INSERT INTO {search} ("kind", "item", "field", "body") VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE "body" = VALUES("body")';
    }

    /** A key value (`kind`, `item`, `field` are bytes), compared byte for byte on both engines. */
    public static function bytes(string $value): \SpacePress\Wp\Bytes
    {
        return new \SpacePress\Wp\Bytes($value);
    }

    /**
     * Locks a row's entries for the rest of the transaction, so a concurrent write to the same row
     * waits for this one (MySQL; SQLite has one writer at a time anyway).
     */
    public static function lock(string $kind, string $id): void
    {
        if (!self::translated()) {
            self::run('SELECT 1 FROM {search} WHERE "kind" = ? AND "item" = ? FOR UPDATE', [self::bytes($kind), self::bytes($id)]);
        }
    }

    /** Deletes the entries of a kind's rows that no longer exist (a rebuild's last step). */
    public static function removeMissing(string $kind, array $spec): void
    {
        $known = [];
        foreach (self::run('SELECT DISTINCT "item" FROM {search} WHERE "kind" = ?', [self::bytes($kind)]) as $row) {
            $known[] = (string) $row['item'];
        }
        $pk = $spec['primaryKey'];
        foreach (array_chunk($known, 500) as $chunk) {
            $values = array_map(static fn (string $id) => $spec['columns'][$pk]['type'] === 'integer' && ctype_digit($id) ? (int) $id : $id, $chunk);
            $present = array_map('strval', array_column(db()->findMany($kind, ['where' => [$pk => ['in' => $values]], 'columns' => [$pk => true]]
                + (\SpacePress\Locale\Module::localized($kind) ? ['locale' => '*'] : [])), $pk));
            foreach (array_diff($chunk, $present) as $gone) {
                self::run('DELETE FROM {search} WHERE "kind" = ? AND "item" = ?', [self::bytes($kind), self::bytes($gone)]);
            }
        }
    }

    /** The table, quoted and prefixed. */
    public static function table(): string
    {
        $store = db()->store();
        $prefix = $store instanceof \SpacePress\Wp\CompositeStore ? $store->db->prefix() : 'wp_';

        return '`' . $prefix . Index::TABLE . '`';
    }

    /** Whether the table exists yet: a write before the plugin's first upgrade check indexes nothing. */
    public static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }
        $store = db()->store();
        if (!$store instanceof \SpacePress\Wp\CompositeStore) {
            return false;
        }
        try {
            $store->db->run('SELECT 1 FROM ' . self::table() . ' LIMIT 1');

            return self::$ready = true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `s.item` equal to the row key $key (an SQL expression), byte for byte. MySQL compares the key
     * as bytes; the SQLite translator turns `CAST(… AS BINARY)` into text, which never equals a
     * BLOB, so there the stored bytes are read as text instead (SQLite's `=` on text is binary).
     */
    public static function itemIs(string $key): string
    {
        return self::translated() ? "CAST(s.`item` AS CHAR) = $key" : "s.`item` = CAST($key AS BINARY)";
    }

    /** Whether the connection is the SQLite translator, which has no `MATCH ... AGAINST`. */
    public static function translated(): bool
    {
        return db()->store()->dialect()->translated;
    }

    /**
     * One condition per term, on `s.body`, with its parameters. A term matches a word that starts
     * with it, so `wor` finds `world`.
     *
     * @param list<string> $terms
     * @return list<array{string, list<string>}>
     */
    public static function match(array $terms): array
    {
        $out = [];
        foreach ($terms as $term) {
            if (self::translated()) {
                // SP_WORD_PREFIX (Wp\Connection): a word of the text starts with the term, with
                // Unicode case folding as the MySQL collations do.
                $out[] = ['SP_WORD_PREFIX(s.`body`, ?) = 1', [$term]];
            } else {
                $out[] = ['MATCH (s.`body`) AGAINST (? IN BOOLEAN MODE)', ['+' . $term . '*']];
            }
        }

        return $out;
    }
}
