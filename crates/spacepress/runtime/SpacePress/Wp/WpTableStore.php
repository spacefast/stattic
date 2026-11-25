<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Dialect;
use SpacePress\HttpError;
use SpacePress\Js;
use SpacePress\Links;
use SpacePress\Schema;
use SpacePress\Source;
use SpacePress\Store;

/**
 * `editor: false` collections (§4.2): real `{prefix}sp_<table>` tables in the §3.4 storage form,
 * plus the custom-table halves of the Store surface: `{prefix}sp__revisions`, globals as SCF
 * options (§4.5), and lazy scheduled promotion.
 */
final class WpTableStore implements Store, Links
{
    /** @var array<string, Source> */
    private array $sources = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Schema $schema,
        private readonly Dialect $dialect,
    ) {
    }

    public function afterCommit(callable $fn): void
    {
        $this->db->afterCommit($fn);
    }

    public function applySystemStatements(array $statements): void
    {
        $this->db->applySystemStatements($statements);
    }

    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function table(string $collection): string
    {
        $spec = $this->schema->collection($collection);
        if (isset($spec['existingTable'])) {
            Guard::table($spec['existingTable'], $this->db->prefix());

            return $spec['existingTable'];
        }

        return $this->db->prefix() . ($spec['table'] ?? 'sp_' . $collection);
    }

    public function source(string $collection): Source
    {
        $key = $this->db->prefix() . ':' . $collection;

        return $this->sources[$key] ??= Source::plain(
            $this->dialect,
            $this->dialect->quote($this->table($collection)),
            $this->schema->collection($collection)['columns'],
            textBooleans: isset($this->schema->collection($collection)['existingTable']),
        );
    }

    public function select(string $collection, array $q): array
    {
        $rows = $this->db->run($q['sql'], $q['params']);
        \SpacePress\Extensions::lookup($collection, array_column($rows, $this->schema->collection($collection)['primaryKey']));

        return $rows;
    }

    public function showsUnique(string $collection, string $column, \SpacePress\Viewer $viewer): bool
    {
        return false;
    }

    public function readGuards(string $collection, \SpacePress\Viewer $viewer, bool $forSelect = false): array
    {
        return [];
    }

    private function physicalColumn(string $collection, string $name): string
    {
        $cols = $this->schema->collection($collection)['columns'];

        return (string) ($cols[$name]['column'] ?? $name);
    }

    public function insert(string $collection, array $values): int|string
    {
        $q = $this->dialect->quote(...);
        $physical = [];
        foreach ($values as $col => $val) {
            $physical[$this->physicalColumn($collection, (string) $col)] = $val;
        }
        $sql = 'INSERT INTO ' . $q($this->table($collection)) . ($physical === []
            ? ' () VALUES ()'
            : ' (' . implode(', ', array_map($q, array_keys($physical))) . ') VALUES (' . implode(', ', array_fill(0, count($physical), '?')) . ')');
        $this->db->run($sql, array_values($physical));
        $pk = $this->schema->collection($collection)['primaryKey'];

        return $values[$pk] ?? $this->db->insertId();
    }

    public function update(string $collection, int|string $pk, array $values): void
    {
        if ($values === []) {
            return;
        }
        $q = $this->dialect->quote(...);
        $assign = implode(', ', array_map(fn ($c) => $q($this->physicalColumn($collection, (string) $c)) . ' = ?', array_keys($values)));
        $pkCol = $this->physicalColumn($collection, $this->schema->collection($collection)['primaryKey']);
        $this->db->run(
            'UPDATE ' . $q($this->table($collection)) . " SET $assign WHERE " . $q($pkCol) . ' = ?',
            [...array_values($values), $pk],
        );
    }

    public function delete(string $collection, int|string $pk): void
    {
        $q = $this->dialect->quote(...);
        $pkCol = $this->physicalColumn($collection, $this->schema->collection($collection)['primaryKey']);
        $this->db->run('DELETE FROM ' . $q($this->table($collection)) . ' WHERE ' . $q($pkCol) . ' = ?', [$pk]);
    }

    public function softDelete(string $collection, int|string $pk, string $at, ?int $userId, ?string $group): void
    {
        $this->update($collection, $pk, [
            'deletedAt' => $at,
            'deletedBy' => $userId,
            'deletedGroup' => $group,
            '_live' => null,
        ]);
    }

    public function restore(string $collection, int|string $pk): void
    {
        $this->update($collection, $pk, [
            'deletedAt' => null,
            'deletedBy' => null,
            'deletedGroup' => null,
            '_live' => 1,
        ]);
    }

    // ================================================================ many-ref links

    public function linkTable(string $join): string
    {
        return $this->dialect->quote($this->db->prefix() . 'sp__join_' . $join);
    }

    public function links(string $join, string $by, array $ids, bool $forUpdate = false): array
    {
        if ($ids === []) {
            return [];
        }
        $by = $by === 'target_id' ? 'target_id' : 'owner_id';
        $rows = $this->db->run(
            'SELECT `owner_id`, `target_id`, `position` FROM ' . $this->linkTable($join) . " WHERE `$by` IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY `owner_id`, `position`'
            . ($forUpdate ? $this->dialect->writeLock() : ''),
            array_values(array_map('intval', $ids)),
        );

        return array_map(static fn (array $r) => ['owner_id' => (int) $r['owner_id'], 'target_id' => (int) $r['target_id'], 'position' => (int) $r['position']], $rows);
    }

    public function setLinks(string $join, int|string $owner, array $targets): void
    {
        $this->unlink($join, 'owner_id', $owner);
        if ($targets === []) {
            return;
        }
        $params = [];
        foreach (array_values($targets) as $i => $target) {
            array_push($params, (int) $owner, (int) $target, $i);
        }
        $this->db->run(
            'INSERT INTO ' . $this->linkTable($join) . ' (`owner_id`, `target_id`, `position`) VALUES ' . implode(', ', array_fill(0, count($targets), '(?, ?, ?)')),
            $params,
        );
    }

    public function unlink(string $join, string $by, int|string $id): void
    {
        $by = $by === 'target_id' ? 'target_id' : 'owner_id';
        $this->db->run('DELETE FROM ' . $this->linkTable($join) . " WHERE `$by` = ?", [(int) $id]);
    }

    // ================================================================ globals (§4.5): SCF options

    /**
     * Encoded values keyed by field, read through SCF (`get_field($field, 'sp_global_<name>', false)`)
     * so repeater/group option layouts decode the same way wp-admin wrote them. Null when never written.
     */
    public function globalRead(string $name): ?array
    {
        $g = $this->schema->global($name);
        $postId = $g['wp']['postId'];
        wp_prime_option_caches(array_map(static fn ($field) => $postId . '_' . $field, array_keys($g['fields'])));
        $out = [];
        foreach ($g['fields'] as $field => $col) {
            $fieldKey = $g['wp']['fieldKeyPrefix'] . $field;
            if (get_option($postId . '_' . $field, null) === null) {
                continue;
            }
            $out[$field] = Scf::toStorage($col, get_field($fieldKey, $postId, false), $fieldKey);
        }

        return $out === [] ? null : $out;
    }

    public function globalWrite(string $name, array $values): void
    {
        $g = $this->schema->global($name);
        $postId = $g['wp']['postId'];
        foreach ($values as $field => $stored) {
            $col = $g['fields'][$field];
            $fieldKey = $g['wp']['fieldKeyPrefix'] . $field;
            $this->db->touchOption($postId . '_' . $field);
            $this->db->core(static fn () => update_field($fieldKey, Scf::toScf($col, $stored, $fieldKey), $postId));
        }
    }

    // ================================================================ revisions (§4.7, custom tables)

    public function hasRevisionsTable(): bool
    {
        return $this->db->run(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefix() . 'sp__revisions'],
        ) !== [];
    }

    public function latestSchemaMigration(): ?string
    {
        $ledger = $this->db->prefix() . 'sp__migrations';
        if ($this->db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$ledger]) === []) {
            return null;
        }
        $hasKind = $this->db->run(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$ledger, 'kind'],
        ) !== [];
        $kindClause = $hasKind ? " AND `kind` = 'schema'" : '';
        $rows = $this->db->run(
            "SELECT `id` FROM `$ledger` WHERE `app` = ? AND `id` NOT LIKE '%:%'{$kindClause} ORDER BY `applied_at` DESC, `id` DESC LIMIT 1",
            [$this->schema->name()],
        );

        return isset($rows[0]['id']) ? (string) $rows[0]['id'] : null;
    }

    public function revisions(string $collection, int|string $pk): array
    {
        $rows = $this->db->run(
            'SELECT * FROM `' . $this->db->prefix() . 'sp__revisions` WHERE `collection` = ? AND `pk` = ? ORDER BY `version` DESC, `id` DESC',
            [$collection, (string) $pk],
        );

        return array_map(self::revisionRow(...), $rows);
    }

    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId, array $meta = []): void
    {
        $table = '`' . $this->db->prefix() . 'sp__revisions`';
        // The insert owns its newly allocated key. A locking read of its revision history can lock
        // the gap around that key and deadlock with another insert allocating a different key.
        $forUpdate = $this->db->sqlite() || ($meta['allocatedKey'] ?? false) ? '' : ' FOR UPDATE';
        $max = $this->db->run("SELECT MAX(`version`) AS v FROM $table WHERE `collection` = ? AND `pk` = ?$forUpdate", [$collection, (string) $pk]);
        $version = ((int) ($max[0]['v'] ?? 0)) + 1;
        $op = (string) ($meta['op'] ?? 'update');
        $schemaMigration = is_string($meta['schemaMigration'] ?? null) && $meta['schemaMigration'] !== '' ? $meta['schemaMigration'] : null;
        $changed = array_key_exists('changed', $meta) && is_array($meta['changed']) ? Js::jsonStringify(array_values($meta['changed'])) : null;
        $this->db->run(
            "INSERT INTO $table (`collection`, `pk`, `version`, `op`, `at`, `userId`, `schemaMigration`, `row`, `changed`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$collection, (string) $pk, $version, $op, Js::iso(Js::now()), $userId, $schemaMigration, Js::jsonStringify($encodedRow), $changed],
        );
    }

    public function revision(string $collection, int|string $pk, int $revisionId, bool $byVersion = false): ?array
    {
        $table = '`' . $this->db->prefix() . 'sp__revisions`';
        $primary = $byVersion ? 'version' : 'id';
        $fallback = $byVersion ? 'id' : 'version';
        $rows = $this->db->run("SELECT * FROM $table WHERE `collection` = ? AND `pk` = ? AND `$primary` = ? LIMIT 1", [$collection, (string) $pk, $revisionId]);
        if ($rows === []) {
            $rows = $this->db->run("SELECT * FROM $table WHERE `collection` = ? AND `pk` = ? AND `$fallback` = ? LIMIT 1", [$collection, (string) $pk, $revisionId]);
        }

        return $rows === [] ? null : self::revisionRow($rows[0]);
    }

    private static function revisionRow(array $r): array
    {
        $changed = isset($r['changed']) && is_string($r['changed']) ? json_decode($r['changed'], true) : null;

        return [
            'id' => (int) $r['id'],
            'version' => (int) ($r['version'] ?? 1),
            'op' => (string) ($r['op'] ?? 'update'),
            'at' => (string) $r['at'],
            'userId' => $r['userId'] === null ? null : (int) $r['userId'],
            'schemaMigration' => isset($r['schemaMigration']) && $r['schemaMigration'] !== '' ? (string) $r['schemaMigration'] : null,
            'changed' => is_array($changed) ? array_values($changed) : null,
            'row' => json_decode((string) $r['row'], true),
        ];
    }

    public function promoteDue(string $collection, string $nowIso): array
    {
        $table = $this->dialect->quote($this->table($collection));
        $pk = $this->dialect->quote($this->schema->collection($collection)['primaryKey']);
        $where = " WHERE `status` = 'scheduled' AND `publishedAt` <= ?";
        $pks = array_column($this->db->run("SELECT $pk AS pk FROM $table$where", [$nowIso]), 'pk');
        if ($pks !== []) {
            $this->db->run("UPDATE $table SET `status` = 'published'$where", [$nowIso]);
        }

        return $pks;
    }

    // ================================================================ errors

    public function mapError(\Throwable $e): \Throwable
    {
        if (!$e instanceof DbError) {
            return $e;
        }
        $code = $e->getCode();
        if ($code === 1062) { // ER_DUP_ENTRY: "Duplicate entry 'x' for key 'name'"
            $col = preg_match("/for key '(?:[^'.]*\\.)?([^']+)'/", $e->getMessage(), $m) ? self::uniqueColumn($m[1]) : null;

            return new HttpError(409, 'unique_violation', 'A row with this ' . ($col ?? 'value') . ' already exists', $col === null ? null : HttpError::seg($col));
        }
        if (in_array($code, [1451, 1452, 1216, 1217], true)) {
            return new HttpError(409, 'reference_violation', 'This change would break a reference between rows');
        }
        if ($code === 1048 && preg_match("/Column '([^']+)'/", $e->getMessage(), $m)) {
            return HttpError::invalidInput("{$m[1]} is required", HttpError::seg($m[1]));
        }

        return $e;
    }

    /** Index names emitted by the compiler: `<col>_unique`, `<col>__live` (SQLite soft-delete unique), or the column itself. */
    private static function uniqueColumn(string $key): ?string
    {
        if ($key === 'translationGroup_locale') { // §7.3: one row per locale per group
            return 'locale';
        }
        if (str_ends_with($key, '__live')) {
            $key = substr($key, 0, -strlen('__live'));
        }
        if (preg_match('/^(?:.*__)?([A-Za-z0-9_]+)_unique$/', $key, $m)) {
            return $m[1];
        }

        return $key === 'PRIMARY' ? null : $key;
    }
}
