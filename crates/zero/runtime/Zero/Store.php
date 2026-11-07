<?php

declare(strict_types=1);

namespace Zero;

/*
 * CHANGED vs design §3.2 (additive only; every §3.2 signature is unchanged):
 *  - Dialect gains a 6th constructor parameter `ciCollation` (default 'utf8mb4_0900_ai_ci') and the
 *    factories Dialect::mysql($ciCollation) / Dialect::mysqlOnSqlite(). §3.5 says Dialect carries the
 *    case-insensitive collation chosen from $wpdb->db_version(); §3.2 had no field for it.
 *  - `likeFn` is a sprintf template applied as sprintf($likeFn, <expr>, <ci arg>): MySQL gets the
 *    collation name ('utf8mb4_bin' for like, ciCollation for ilike), the SQLite translator BINARY or
 *    nothing. The MySQL form is `<expr> LIKE ? COLLATE <c> ESCAPE '\\'`: the collation sits on the
 *    utf8mb4 parameter so it is valid even when the column is utf8mb3.
 *  - SQL from Sql uses positional `?` params and may contain literal `%` (DATE_FORMAT): bind with
 *    mysqli prepared statements (or the translator's literal binding), never pass it through $wpdb->prepare().
 *
 * Conventions Sql reads from a ColumnRef (so a store only has to produce the mapping):
 *  - cmpExpr non-empty means "compare in the WordPress meta form": params for timestamp columns are
 *    sent as 'Y-m-d H:i:s' (UTC), date as 'Ymd', boolean as '1'/'0'. Plain tables leave cmpExpr ''.
 *  - kind 'serialized-list' with a non-empty cmpExpr on the mysql dialect = PHP-serialized meta:
 *    `has` compiles to `<cmpExpr> LIKE ?` with '%' . esc_like(serialize(v)) . '%' (integers: also the
 *    serialized-string form, since SCF's gallery stores ids as strings), `eq: []` to
 *    COALESCE(NULLIF(<cmpExpr>, ''), 'a:0:{}') = 'a:0:{}'.
 *    Without cmpExpr the list is JSON text: JSON_CONTAINS(expr, ?).
 *  - A Source with a non-empty baseWhere is post-backed: `eq`/`ne` on group/repeater/blocks columns
 *    are rejected there. For kind 'json', `isNull`/`isNotNull` test `cmpExpr` when given, else `expr`
 *    (post-backed group/repeater/blocks: expr 'NULL', cmpExpr e.g. "NULLIF(NULLIF({j}.meta_value, ''), '0')"
 *    with join M(zero_<col>), so "no rows/empty" is null). Scalars always test `expr`.
 *  - A join is emitted only when the expression actually used contains '{j}', so a SELECT of a column
 *    whose expr is 'NULL' costs no join.
 */

/** Storage for collections, globals and revisions. Implemented by the Wp\*Store classes. */
interface Store
{
    /** Open a transaction (or join one). Any Throwable rolls back everything written inside. */
    public function transaction(callable $fn): mixed;

    /** Run after the outermost commit (immediately without a transaction); discarded on rollback. */
    public function afterCommit(callable $fn): void;

    /** @param list<string> $statements idempotent DDL; MySQL replaces {prefix}. Never inside a request transaction. */
    public function applySystemStatements(array $statements): void;

    /**
     * Raw rows matching a compiled query. $q is produced by Sql::select() against $this->dialect()
     * and $this->source($collection): ['sql' => string, 'params' => list<scalar>]. Rows come back as
     * assoc arrays keyed by column name, values in STORAGE form (§3.4; strings/ints/null); Db decodes
     * them with Schema::decodeRow. Every column of the collection is present in every row.
     *
     * @param array{sql: string, params: list<int|float|string|null>} $q
     * @return list<array<string, mixed>>
     */
    public function select(string $collection, array $q): array;

    /** Insert one encoded row ($values keyed by column, storage form). Returns the primary key value. */
    public function insert(string $collection, array $values): int|string;

    /** Update one row by primary key with encoded values. */
    public function update(string $collection, int|string $pk, array $values): void;

    /** Delete one row by primary key. */
    public function delete(string $collection, int|string $pk): void;

    /** The SQL dialect filters compile to for this store. */
    public function dialect(): Dialect;

    /**
     * How Sql reads this collection: the base table, its primary-key expression, and one ColumnRef per
     * column. WpTableStore returns a plain table where every ColumnRef is `<alias>.<col>`;
     * WpPostStore returns wp_posts with core columns as expressions and meta columns as joins (§3.5.1).
     * System tables ('__zero_revisions', '__zero_tokens', '__zero_globals', '__zero_media') are plain.
     */
    public function source(string $collection): Source;

    /** @return list<array> Storage-native visibility filters for this explicit viewer, applied before pagination and relation matching. */
    public function readGuards(string $collection, Viewer $viewer): array;

    /** Encoded values keyed by field, null if never written. */
    public function globalRead(string $name): ?array;

    public function globalWrite(string $name, array $values): void;

    /** @return list<array{id: int, at: string, userId: ?int, row: array}> newest first */
    public function revisions(string $collection, int|string $pk): array;

    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void;

    /** @return ?array{id: int, at: string, userId: ?int, row: array} */
    public function revision(string $collection, int|string $pk, int $revisionId): ?array;

    /**
     * Scheduled publishing. WpTable: UPDATE ... SET status='published' WHERE status='scheduled'
     * AND publishedAt <= $nowIso; returns pks. WpPost: no-op (WP cron does it). Fires no hooks, writes no revision.
     *
     * @return list<int|string>
     */
    public function promoteDue(string $collection, string $nowIso): array;

    /** Map a driver exception to a HttpError (unique_violation with pointer when the column is known, reference_violation) or rethrow. */
    public function mapError(\Throwable $e): \Throwable;
}
