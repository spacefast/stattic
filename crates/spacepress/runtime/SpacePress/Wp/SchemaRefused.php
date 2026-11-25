<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * The deploy-time schema check refused a migration (see SchemaCheck). Nothing of the migration ran.
 * `wp spacepress migrate --json` reports `code: "schema_refused"` with one entry per refusal, each with
 * its own stable code (`drop_has_rows`, `drop_count_mismatch`, `drop_adopted_table`,
 * `required_field_missing`), the live row count, and the fix.
 */
final class SchemaRefused extends \SpacePress\HttpError
{
    /** @param list<array{code: string, name: string, rows: int, message: string, fix: string}> $refusals */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(409, 'schema_refused', implode(' ', array_map(
            static fn (array $r): string => $r['message'] . ' ' . $r['fix'],
            $refusals,
        )));
    }

    /** The `--drop` arguments that would accept every drop refusal at today's counts. */
    public function drops(): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['drop'],
            array_filter($this->refusals, static fn (array $r): bool => isset($r['drop'])),
        ));
    }
}
