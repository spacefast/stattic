<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Dialect;
use Zero\HttpError;
use Zero\Js;
use Zero\Schema;
use Zero\Source;
use Zero\Store;

/**
 * `editor: false` collections (§4.2): real `{prefix}zero_<table>` tables in the §3.4 storage form,
 * plus the custom-table halves of the Store surface: `{prefix}zero__revisions`, globals as SCF
 * options (§4.5), and lazy scheduled promotion.
 */
final class WpTableStore implements Store
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

        return $spec['existingTable'] ?? $this->db->prefix() . ($spec['table'] ?? 'zero_' . $collection);
    }

    public function source(string $collection): Source
    {
        return $this->sources[$collection] ??= Source::plain(
            $this->dialect,
            $this->dialect->quote($this->table($collection)),
            $this->schema->collection($collection)['columns'],
            textBooleans: isset($this->schema->collection($collection)['existingTable']),
        );
    }

    public function select(string $collection, array $q): array
    {
        $rows = $this->db->run($q['sql'], $q['params']);
        \Zero\Extensions::lookup($collection, array_column($rows, $this->schema->collection($collection)['primaryKey']));

        return $rows;
    }

    public function readGuards(string $collection, \Zero\Viewer $viewer): array
    {
        return [];
    }

    public function insert(string $collection, array $values): int|string
    {
        $q = $this->dialect->quote(...);
        $sql = 'INSERT INTO ' . $q($this->table($collection)) . ($values === []
            ? ' () VALUES ()'
            : ' (' . implode(', ', array_map($q, array_keys($values))) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')');
        $this->db->run($sql, array_values($values));
        $pk = $this->schema->collection($collection)['primaryKey'];

        return $values[$pk] ?? $this->db->insertId();
    }

    public function update(string $collection, int|string $pk, array $values): void
    {
        if ($values === []) {
            return;
        }
        $q = $this->dialect->quote(...);
        $assign = implode(', ', array_map(static fn ($c) => $q((string) $c) . ' = ?', array_keys($values)));
        $this->db->run(
            'UPDATE ' . $q($this->table($collection)) . " SET $assign WHERE " . $q($this->schema->collection($collection)['primaryKey']) . ' = ?',
            [...array_values($values), $pk],
        );
    }

    public function delete(string $collection, int|string $pk): void
    {
        $q = $this->dialect->quote(...);
        $this->db->run('DELETE FROM ' . $q($this->table($collection)) . ' WHERE ' . $q($this->schema->collection($collection)['primaryKey']) . ' = ?', [$pk]);
    }

    // ================================================================ globals (§4.5): SCF options

    /**
     * Encoded values keyed by field, read through SCF (`get_field($field, 'zero_global_<name>', false)`)
     * so repeater/group option layouts decode the same way wp-admin wrote them. Null when never written.
     */
    public function globalRead(string $name): ?array
    {
        $g = $this->schema->global($name);
        $postId = $g['wp']['postId'];
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

    public function revisions(string $collection, int|string $pk): array
    {
        $rows = $this->db->run('SELECT * FROM `' . $this->db->prefix() . 'zero__revisions` WHERE `collection` = ? AND `pk` = ? ORDER BY `id` DESC', [$collection, (string) $pk]);

        return array_map(self::revisionRow(...), $rows);
    }

    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void
    {
        $this->db->run(
            'INSERT INTO `' . $this->db->prefix() . 'zero__revisions` (`collection`, `pk`, `at`, `userId`, `row`) VALUES (?, ?, ?, ?, ?)',
            [$collection, (string) $pk, Js::iso(Js::now()), $userId, Js::jsonStringify($encodedRow)],
        );
    }

    public function revision(string $collection, int|string $pk, int $revisionId): ?array
    {
        $rows = $this->db->run('SELECT * FROM `' . $this->db->prefix() . 'zero__revisions` WHERE `collection` = ? AND `pk` = ? AND `id` = ?', [$collection, (string) $pk, $revisionId]);

        return $rows === [] ? null : self::revisionRow($rows[0]);
    }

    private static function revisionRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'at' => (string) $r['at'], 'userId' => $r['userId'] === null ? null : (int) $r['userId'], 'row' => json_decode((string) $r['row'], true)];
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

    /** Index names emitted by the compiler: `<col>_unique`, or the column itself. */
    private static function uniqueColumn(string $key): ?string
    {
        if ($key === 'translationGroup_locale') { // §7.3: one row per locale per group
            return 'locale';
        }
        if (preg_match('/^(?:.*__)?([A-Za-z0-9]+)_unique$/', $key, $m)) {
            return $m[1];
        }

        return $key === 'PRIMARY' ? null : $key;
    }
}
