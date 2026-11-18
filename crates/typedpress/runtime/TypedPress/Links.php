<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * The join tables of `ref(kind).many()` fields (docs/decisions/2026-11-11-authoring-surface.md,
 * ruling 3): `{prefix}tp_<join>` with `owner_id`, `target_id` and a 0-based `position` (write
 * order), one row per link. `$join` is the field's `references.join` in schema.php.
 */
interface Links
{
    /** The quoted, prefixed table, for SQL that reads it (filters). */
    public function linkTable(string $join): string;

    /**
     * Links whose `$by` column ('owner_id' | 'target_id') is one of `$ids`, in position order.
     *
     * @param list<int|string> $ids
     * @return list<array{owner_id: int, target_id: int, position: int}>
     */
    public function links(string $join, string $by, array $ids): array;

    /** Replace an owner's links with `$targets`, positions in list order. @param list<int> $targets */
    public function setLinks(string $join, int|string $owner, array $targets): void;

    /** Remove every link whose `$by` column is `$id`. */
    public function unlink(string $join, string $by, int|string $id): void;
}
