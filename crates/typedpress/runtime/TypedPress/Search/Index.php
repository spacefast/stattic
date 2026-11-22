<?php

declare(strict_types=1);

namespace TypedPress\Search;

use TypedPress\Extensions;
use TypedPress\FieldAccess;
use TypedPress\HttpError;
use TypedPress\Jobs\Jobs;
use TypedPress\Schema;
use TypedPress\SqlPredicate;
use TypedPress\Viewer;

use function TypedPress\db;

/**
 * The search index (docs/decisions/2026-11-13-search-index.md): one row per (kind, row, field) in
 * `tp__search`, holding the field's text as a visitor would read it. The index is a candidate
 * filter only. A query matches terms against it and hands the matching row ids to the kind's own
 * read pipeline as one more condition, so the read rule, draft visibility, a filter rule and field
 * access decide which rows come back, how many there are and in what order, exactly as for any list.
 *
 * - Searchable fields (spec §2.6): the title, the content and every text and richText field. A
 *   field the viewer may not read is not matched, so a hidden value can't be found, counted or
 *   ranked: each field is its own index row, and only the readable fields' rows are consulted.
 * - Kept in step on write: every pipeline write (API, actions, hooks, jobs, wp-admin saves) reports
 *   through `Extensions::onWritten` inside its transaction, and the row's index entries are replaced
 *   there, so the index commits or rolls back with the write. Scheduled promotion changes status
 *   only, which the index doesn't hold. A full rebuild of a kind runs as an Action Scheduler job
 *   (`typedpress.search.reindex`), queued when a build's searchable fields change.
 * - MySQL and MariaDB: a FULLTEXT index, queried in boolean mode with each term as a prefix. The
 *   SQLite translator (dev sites, Playground) accepts the index but not `MATCH`, so it matches each
 *   term as a word prefix with `LIKE` over the same rows.
 */
final class Index
{
    public const TABLE = 'tp__search';
    public const JOB = 'typedpress.search.reindex';
    /** Longest search text: more terms than this add nothing a person types. */
    public const MAX_QUERY = 200;
    /** Terms of a query; the rest are ignored. */
    public const MAX_TERMS = 8;
    /** Rows a kind's rebuild indexes per job run before it queues the next run. */
    public const BATCH = 200;
    /** The option that records which searchable fields each kind was last indexed with. */
    private const SHAPE = 'tp_search_shape';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        \TypedPress\SystemTables::register('__' . self::TABLE, [
            // `kind`, `item` and `field` are bytes: a row's id is matched exactly as stored (`a` is not
            // `A`), and comparing them involves no collation to clash with the connection's.
            // A table from before ids were bytes is dropped and created again: it holds nothing that
            // isn't rebuilt from the rows (the shape is cleared when tables are installed).
            \TypedPress\SystemTables::step(1, static function (\TypedPress\Store $store): void {
                global $wpdb;
                $connection = new \TypedPress\Wp\Connection($wpdb);
                if ($connection->run('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND DATA_TYPE = ?',
                    [$connection->prefix() . self::TABLE, 'item', 'varchar']) !== []) {
                    $store->applySystemStatements(['DROP TABLE IF EXISTS `{prefix}' . self::TABLE . '`']);
                }
            }),
            'CREATE TABLE IF NOT EXISTS `{prefix}' . self::TABLE . '` (`kind` VARBINARY(64) NOT NULL, `item` VARBINARY(764) NOT NULL, `field` VARBINARY(64) NOT NULL, '
            . '`body` LONGTEXT NOT NULL, PRIMARY KEY (`kind`, `item`, `field`), FULLTEXT KEY `body` (`body`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ]);
        Extensions::onWritten(self::written(...));
        if (function_exists('add_action')) {
            // The runtime created or changed this table: every kind is indexed again (the shape
            // recorded for the old table no longer says what the new one holds).
            add_action('tp_runtime_tables_installed', static fn () => delete_option(self::SHAPE));
            // After every build and every runtime update, once storage matches the build: kinds whose
            // searchable fields changed since they were last indexed are rebuilt by a queued job. One
            // cached option read when nothing changed. A managed build does it from initialize.
            add_action('wp_loaded', static fn () => \TypedPress\Wp\Runtime::installs() ? self::reindexChanged() : null);
            // Users written outside TypedPress's pipeline (wp_insert_user, wp_update_user, the profile
            // form, identity's sign-up) report nothing: the member's entries follow core's user
            // hooks instead, re-read from the stored row. Inside a TypedPress write this repeats what
            // its own report does.
            add_action('user_register', static fn (int $id) => self::user($id), PHP_INT_MAX);
            add_action('profile_update', static fn (int $id) => self::user($id), PHP_INT_MAX);
            add_action('deleted_user', static fn (int $id) => self::user($id), PHP_INT_MAX);
        }
    }

    /** A WordPress user changed outside the pipeline: their member entries, from the user as stored now. */
    public static function user(int $id): void
    {
        $plugin = \TypedPress\Wp\Plugin::instance();
        $collection = $plugin?->schema->auth()['collection'] ?? null;
        // A user written before this build's migration ran (activation, a refused build) is
        // indexed by the rebuild that follows the migration, not now.
        if ($collection === null || $plugin->unavailable() !== null || !$plugin->migrated()) {
            return;
        }
        $plugin->app();
        // The schema the running Db reads, as for every other write report: a kind it doesn't hold
        // (a Db swapped in for a task, an app without members) has no entries to keep.
        $schema = db()->schema();
        if (!$schema->hasCollection($collection) || !Storage::ready()) {
            return;
        }
        $spec = $schema->collection($collection);
        $fields = self::fields($spec);
        if ($fields !== []) {
            self::refresh($collection, $spec, $id, $fields);
        }
    }

    /** The runtime-native job that rebuilds one kind's entries, a batch per run (Action Scheduler). */
    public static function jobSpec(): array
    {
        return [
            'input' => ['kind' => ['type' => 'text', 'notNull' => true], 'after' => ['type' => 'text'], 'generation' => ['type' => 'text']],
            'handler' => [self::class, 'rebuild'], 'retries' => 5, 'agents' => false,
        ];
    }

    // ================================================================ which fields

    /** @return list<string> the kind's searchable fields: title, content, text and richText, never system ones */
    public static function fields(array $spec): array
    {
        if (($spec['taxonomy'] ?? false) || ($spec['commerce'] ?? '') === 'order') {
            return []; // `search` on a taxonomy isn't built yet
        }
        $out = [];
        foreach ($spec['columns'] as $name => $col) {
            $name = (string) $name;
            if (($col['hidden'] ?? false) || ($col['system'] ?? false) && $name !== ($spec['title'] ?? null) && $name !== ($spec['content'] ?? null)) {
                continue;
            }
            if ($name === ($spec['title'] ?? null) || $name === ($spec['content'] ?? null)
                || in_array($col['type'], ['text', 'richText'], true) && !isset($col['enum'])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** The searchable fields $viewer may read in every row: a field that may be hidden is never matched. */
    public static function readable(array $spec, ?Viewer $viewer): array
    {
        return array_values(array_filter(self::fields($spec), static fn (string $f): bool => $viewer === null || !FieldAccess::mayHide($spec['columns'][$f], $viewer)));
    }

    // ================================================================ writing

    /** Extensions::onWritten: the row's entries, replaced inside the write's transaction. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        $schema = db()->schema();
        if (!$schema->hasCollection($collection) || !Storage::ready()) {
            return;
        }
        $spec = $schema->collection($collection);
        $fields = self::fields($spec);
        if ($fields === []) {
            return;
        }
        $id = $row[$spec['primaryKey']] ?? $previous[$spec['primaryKey']] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return;
        }
        if ($op === 'delete') {
            Storage::run('DELETE FROM {search} WHERE "kind" = ? AND "item" = ?', [Storage::bytes($collection), Storage::bytes((string) $id)]);

            return;
        }
        if ($op === 'update' && $previous !== null && !self::changed($fields, $row, $previous)) {
            return; // no searchable field changed: the row's entries already hold this text
        }
        self::refresh($collection, $spec, $id, $fields);
    }

    /**
     * Whether an update changed a searchable field: both rows are the pipeline's full decoded rows
     * (before the write, and as stored after it). Only a searchable field's value is indexed, so an
     * update of anything else (a status, a number, a relation) leaves the entries as they are.
     *
     * @param list<string> $fields
     */
    private static function changed(array $fields, array $row, array $previous): bool
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row) || !array_key_exists($field, $previous) || $row[$field] !== $previous[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * A row's entries from the row as it is stored now, read as the system. An after-hook that wrote
     * the row again reported its own write first; the outer report then reads the same final row, so
     * the last write to the index is never an older snapshot.
     *
     * @param list<string> $fields
     */
    private static function refresh(string $collection, array $spec, int|string $id, array $fields): void
    {
        $stored = db()->findFirst($collection, ['where' => [$spec['primaryKey'] => $id], 'columns' => array_fill_keys([$spec['primaryKey'], ...$fields], true)]
            + self::everyLocale($collection));
        if ($stored === null) {
            Storage::run('DELETE FROM {search} WHERE "kind" = ? AND "item" = ?', [Storage::bytes($collection), Storage::bytes((string) $id)]);

            return;
        }
        self::put($collection, (string) $id, $fields, $stored);
    }

    /**
     * The row's entries, replaced: each field with text is written in place (an upsert on the row's
     * own key), then the entries of the fields that have none now are deleted by key.
     *
     * Deleting the row's whole key range first and inserting after deadlocked under concurrent
     * creates on InnoDB: a DELETE that matches no rows takes a gap lock, and two new rows whose ids
     * fall in the same gap then each waited on the other's INSERT. Every statement here names an
     * exact key, so no gap is locked.
     *
     * @param list<string> $fields
     */
    private static function put(string $collection, string $id, array $fields, array $row): void
    {
        $kind = Storage::bytes($collection);
        $item = Storage::bytes($id);
        foreach ($fields as $field) {
            $text = self::text($row[$field] ?? null);
            if ($text === '') {
                Storage::run('DELETE FROM {search} WHERE "kind" = ? AND "item" = ? AND "field" = ?', [$kind, $item, Storage::bytes($field)]);
            } else {
                Storage::run(Storage::upsert(), [$kind, $item, Storage::bytes($field), $text]);
            }
        }
        // Entries of a field that is no longer searchable (a build changed the kind's fields).
        $keep = array_map(static fn (string $f) => Storage::bytes($f), $fields);
        Storage::run('DELETE FROM {search} WHERE "kind" = ? AND "item" = ?' . ($keep === [] ? '' : ' AND "field" NOT IN (' . implode(', ', array_fill(0, count($keep), '?')) . ')'), [$kind, $item, ...$keep]);
    }

    /** A stored value as the words a reader sees: markup and block comments gone, entities decoded. */
    public static function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $text = preg_replace('/<!--.*?-->/s', ' ', $value) ?? $value;
        // `<p>green</p><p>tea</p>` reads as two words: every tag is a boundary before tags go.
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * One run of a kind's rebuild: the next BATCH rows after `after`, each re-indexed from the row as
     * stored now (locked for the write, so a concurrent edit commits before or after it, never under
     * it), then the next run is queued. Rows already indexed stay searchable meanwhile; the last run
     * removes entries of rows that no longer exist and of fields no longer searchable.
     *
     * @param array{kind: string, after?: ?string, generation?: ?string} $input
     */
    public static function rebuild(array $input, array $ctx = []): void
    {
        $kind = (string) $input['kind'];
        $schema = db()->schema();
        if (!$schema->hasCollection($kind) || !Storage::ready()) {
            return;
        }
        $spec = $schema->collection($kind);
        $pk = $spec['primaryKey'];
        $fields = self::fields($spec);
        $after = $input['after'] ?? null;
        $generation = (string) ($input['generation'] ?? self::generation($kind, $fields));
        if ($generation !== self::generation($kind, $fields)) {
            return; // a newer build's rebuild of this kind has taken over
        }
        db()->transaction(static function () use ($kind, $spec, $pk, $fields, $after, $generation): void {
            $where = $after === null ? [] : ['where' => [$pk => ['gt' => is_numeric($after) && $spec['columns'][$pk]['type'] === 'integer' ? (int) $after : $after]]];
            $ids = array_column(db()->findMany($kind, $where + ['columns' => [$pk => true], 'orderBy' => [$pk => 'asc'], 'limit' => self::BATCH] + self::everyLocale($kind)), $pk);
            foreach ($ids as $id) {
                Storage::lock($kind, (string) $id);
                self::refresh($kind, $spec, $id, $fields);
            }
            if (count($ids) === self::BATCH) {
                $last = (string) $ids[count($ids) - 1];
                Jobs::enqueue(self::JOB, ['kind' => $kind, 'after' => $last, 'generation' => $generation], ['key' => substr(hash('sha256', "$generation:$last"), 0, 40)]);

                return;
            }
            // The last batch: what the rebuild didn't write is stale.
            $in = $fields === [] ? '' : ' AND "field" NOT IN (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
            Storage::run('DELETE FROM {search} WHERE "kind" = ?' . $in, [Storage::bytes($kind), ...array_map(Storage::bytes(...), $fields)]);
            Storage::removeMissing($kind, $spec);
        });
    }

    /** What a rebuild of $kind is for: the kind and its searchable fields, as a short key. */
    private static function generation(string $kind, array $fields): string
    {
        return substr(hash('sha256', $kind . ':' . implode(',', $fields)), 0, 32);
    }

    /**
     * Whether {@see reindexChanged()} would write once storage matches the build: the stored search
     * shape is not this build's. Read without writing (the app boots in memory to read its schema).
     */
    public static function shapeChanged(): bool
    {
        $plugin = \TypedPress\Wp\Plugin::instance();
        if ($plugin === null) {
            return false;
        }
        $previous = get_option(self::SHAPE, null);

        return (is_array($previous) ? $previous : []) !== self::shape($plugin->schema->collections());
    }

    /** @return array<string, list<string>> each searchable kind's fields */
    private static function shape(array $collections): array
    {
        $shape = [];
        foreach ($collections as $name => $spec) {
            $fields = self::fields($spec);
            if ($fields !== []) {
                $shape[(string) $name] = $fields;
            }
        }

        return $shape;
    }

    /**
     * After a build (the plugin's first request on a new schema): each kind whose searchable fields
     * changed is rebuilt by a job, once. Nothing runs when nothing changed.
     */
    public static function reindexChanged(): void
    {
        $plugin = \TypedPress\Wp\Plugin::instance();
        if ($plugin === null || $plugin->unavailable() !== null) {
            return;
        }
        // Compared before the app boots: a schema with no searchable field, or none changed, does nothing.
        $shape = self::shape($plugin->schema->collections());
        $previous = get_option(self::SHAPE, null);
        $previous = is_array($previous) ? $previous : [];
        if ($previous === $shape) {
            return;
        }
        $plugin->app();
        // Only on storage this build migrated: a kind's table may not exist before its migration (a
        // WP-CLI command, a refused or held-back build), and counting its rows would fail.
        if (!Storage::ready() || !$plugin->migrated()) {
            return;
        }
        db()->transaction(static function () use ($shape, $previous): void {
            foreach ($shape as $kind => $fields) {
                // A kind with no rows yet (a new kind, a new site) has nothing to index: its rows are
                // indexed as they are written.
                if (($previous[$kind] ?? null) !== $fields && db()->count($kind, null, null, self::everyLocale($kind)) > 0) {
                    $generation = self::generation($kind, $fields);
                    Jobs::enqueue(self::JOB, ['kind' => $kind, 'after' => null, 'generation' => $generation], ['key' => $generation]);
                }
            }
            foreach (array_keys(array_diff_key($previous, $shape)) as $gone) {
                Storage::run('DELETE FROM {search} WHERE "kind" = ?', [Storage::bytes((string) $gone)]);
            }
            update_option(self::SHAPE, $shape, false);
        });
    }

    // ================================================================ reading

    /**
     * The condition a `search` adds to a list of $collection for $viewer: the rows whose readable
     * searchable fields hold every term, among $only when given (core's `search_columns`). Null when
     * the viewer may read none of the fields or the text has no word: the read then matches nothing.
     */
    public static function predicate(string $collection, string $search, ?Viewer $viewer, ?array $only = null): ?SqlPredicate
    {
        $spec = db()->schema()->collection($collection);
        $fields = self::readable($spec, $viewer);
        if ($only !== null) {
            $fields = array_values(array_intersect($fields, $only));
        }
        $terms = self::terms($search);
        if ($fields === [] || $terms === []) {
            return null;
        }
        // The row's primary key as the store selects it (a post kind's `ID`, a table's own column).
        $key = db()->store()->source($collection)->columns[$spec['primaryKey']]->expr;
        $table = Storage::table();
        $match = Storage::match($terms);
        $in = implode(', ', array_fill(0, count($fields), '?'));
        // Every term in some readable field of the row (one EXISTS per term), so a term found only in
        // a hidden field never qualifies a row.
        $sql = [];
        $params = [];
        foreach ($match as [$condition, $values]) {
            $sql[] = "EXISTS (SELECT 1 FROM $table s WHERE s.`kind` = ? AND " . Storage::itemIs($key) . " AND s.`field` IN ($in) AND $condition)";
            array_push($params, Storage::bytes($collection), ...array_map(Storage::bytes(...), $fields), ...$values);
        }

        return new SqlPredicate(implode(' AND ', $sql), $params);
    }

    /** @return list<string> the words of a query, at most MAX_TERMS, without operators */
    public static function terms(string $search): array
    {
        if (mb_strlen($search, 'UTF-8') > self::MAX_QUERY) {
            throw HttpError::invalidQuery('search is at most ' . self::MAX_QUERY . ' characters', '/search');
        }
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($search, 'UTF-8'), $m);

        return array_slice(array_values(array_unique($m[0])), 0, self::MAX_TERMS);
    }

    private static function everyLocale(string $kind): array
    {
        return \TypedPress\Locale\Module::localized($kind) ? ['locale' => '*'] : [];
    }

    /** Ids of the kinds a site-wide search covers: every kind with a searchable field and an API list. */
    public static function kinds(): array
    {
        $out = [];
        foreach (db()->schema()->collections() as $name => $spec) {
            if (self::fields($spec) !== [] && ($spec['api']['list'] ?? true)) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }
}
