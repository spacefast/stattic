<?php

declare(strict_types=1);

namespace Zero;

/** One column of a Source (§3.2). */
final class ColumnRef
{
    public function __construct(
        /** SQL expression yielding the STORAGE-form value; '{a}' = base alias, '{j}' = this column's join alias. */
        public readonly string $expr,
        /** Join emitted once per (query alias, column) when the column is used; '' when none. */
        public readonly string $join,
        /** Comparison expression for typed operators; '' = use expr. */
        public readonly string $cmpExpr,
        /** 'scalar' | 'serialized-list' | 'json' — decides which operators Sql allows (§3.5). */
        public readonly string $kind,
    ) {
    }

    /** The §3.5 kind for a schema column type. */
    public static function kindFor(string $type): string
    {
        return match ($type) {
            'json', 'group', 'repeater', 'blocks' => 'json',
            'mediaList', 'select' => 'serialized-list',
            default => 'scalar',
        };
    }
}
