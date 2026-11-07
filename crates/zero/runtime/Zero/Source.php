<?php

declare(strict_types=1);

namespace Zero;

/** How Sql reads one collection (§3.2). */
final class Source
{
    public function __construct(
        /** Quoted, prefixed: '"posts"' | '`wp_zero_comments`' | '`wp_posts`'. */
        public readonly string $table,
        /** '' for plain tables; "{a}.post_type = 'zero_posts' AND ..." for posts. */
        public readonly string $baseWhere,
        /** @var array<string, ColumnRef> keyed by column name */
        public readonly array $columns,
    ) {
    }

    /** A plain table: every column is `{a}.<quoted col>`, kind from the schema type. */
    public static function plain(Dialect $d, string $quotedTable, array $columnSpecs, bool $textBooleans = false): self
    {
        $refs = [];
        foreach ($columnSpecs as $name => $col) {
            $expr = '{a}.' . $d->quote((string) $name);
            $cmp = $textBooleans && $col['type'] === 'boolean'
                ? "CASE CAST($expr AS CHAR) WHEN 'true' THEN '1' WHEN 'false' THEN '0' ELSE CAST($expr AS CHAR) END"
                : '';
            $refs[$name] = new ColumnRef($expr, '', $cmp, ColumnRef::kindFor($col['type']));
        }

        return new self($quotedTable, '', $refs);
    }
}
