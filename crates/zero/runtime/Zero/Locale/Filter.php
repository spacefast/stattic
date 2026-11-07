<?php

declare(strict_types=1);

namespace Zero\Locale;

/**
 * The per-query locale rule (§7.3), placed under the `locale` key of a filter by Read and compiled
 * by Sql. Rows in $locale; with $fallback, also the $fallback row of every translation group that
 * has no row in $locale the viewer may read. $groupOf limits the read to the translation group of
 * one row (item reads with an explicit locale).
 */
final class Filter
{
    public function __construct(
        public readonly string $locale,
        public readonly ?string $fallback,
        public readonly int|string|null $groupOf = null,
    ) {
    }
}
