<?php
declare(strict_types=1);

/**
 * Mirror of `apps/control-plane/src/zero/db-scope.ts`. The formula is baked into
 * the capsule's own DDL: changing it is a data migration, not an edit.
 */
function _stattic_zero_db_expected_physical_name(string $spaceId, string $tableName): string
{
    $suffix = '_' . substr(hash('sha256', $spaceId . "\0" . $tableName), 0, 10);
    $base = 'sf_' . _stattic_zero_db_identifier_part($spaceId) . '_' . _stattic_zero_db_identifier_part($tableName);

    return substr($base, 0, 64 - strlen($suffix)) . $suffix;
}

function _stattic_zero_db_identifier_part(string $value): string
{
    $part = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    $part = substr($part, 0, 48);

    return $part === '' ? 'zero' : $part;
}
