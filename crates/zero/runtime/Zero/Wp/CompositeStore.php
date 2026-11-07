<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Dialect;
use Zero\Schema;
use Zero\Source;
use Zero\Store;

/**
 * The plugin's Store (§4.9): routes each collection to WpPostStore (`editor: true`) or WpTableStore
 * (`editor: false`). Both share one Connection, so one transaction spans posts, meta, custom
 * tables and options. Globals always go to the options-backed WpTableStore.
 */
final class CompositeStore implements Store
{
    public function __construct(
        private readonly Schema $schema,
        public readonly Connection $db,
        public readonly WpPostStore $posts,
        public readonly WpTableStore $tables,
        public readonly ?WpUserStore $users = null,
    ) {
    }

    public static function create(Schema $schema, Connection $db): self
    {
        $dialect = $db->sqlite() ? Dialect::mysqlOnSqlite() : Dialect::mysql($db->ciCollation());
        $tables = new WpTableStore($db, $schema, $dialect);

        return new self($schema, $db, new WpPostStore($db, $schema, $dialect), $tables, new WpUserStore($db, $schema, $dialect, $tables));
    }

    public function isPostBacked(string $collection): bool
    {
        $spec = $this->schema->collection($collection);

        return ($spec['editor'] ?? true) && ($spec['wp'] ?? null) !== null;
    }

    private function for(string $collection): Store
    {
        // The auth collection's rows are WordPress users (§6.2).
        if ($this->users !== null && $collection === ($this->schema->auth()['collection'] ?? null)) {
            return $this->users;
        }

        return $this->isPostBacked($collection) ? $this->posts : $this->tables;
    }

    public function afterCommit(callable $fn): void
    {
        $this->db->afterCommit($fn);
    }

    public function applySystemStatements(array $statements): void
    {
        $this->tables->applySystemStatements($statements);
    }

    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    public function select(string $collection, array $q): array
    {
        return $this->for($collection)->select($collection, $q);
    }

    public function insert(string $collection, array $values): int|string
    {
        return $this->for($collection)->insert($collection, $values);
    }

    public function update(string $collection, int|string $pk, array $values): void
    {
        $this->for($collection)->update($collection, $pk, $values);
    }

    public function delete(string $collection, int|string $pk): void
    {
        $this->for($collection)->delete($collection, $pk);
    }

    public function dialect(): Dialect
    {
        return $this->tables->dialect();
    }

    public function source(string $collection): Source
    {
        return $this->for($collection)->source($collection);
    }

    public function readGuards(string $collection, \Zero\Viewer $viewer): array
    {
        return $this->for($collection)->readGuards($collection, $viewer);
    }

    public function globalRead(string $name): ?array
    {
        return $this->tables->globalRead($name);
    }

    public function globalWrite(string $name, array $values): void
    {
        $this->tables->globalWrite($name, $values);
    }

    public function revisions(string $collection, int|string $pk): array
    {
        return $this->for($collection)->revisions($collection, $pk);
    }

    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void
    {
        $this->for($collection)->revisionWrite($collection, $pk, $encodedRow, $userId);
    }

    public function revision(string $collection, int|string $pk, int $revisionId): ?array
    {
        return $this->for($collection)->revision($collection, $pk, $revisionId);
    }

    public function promoteDue(string $collection, string $nowIso): array
    {
        return $this->for($collection)->promoteDue($collection, $nowIso);
    }

    public function mapError(\Throwable $e): \Throwable
    {
        return $this->tables->mapError($e);
    }
}
