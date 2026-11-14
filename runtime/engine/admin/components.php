<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/canonical-json.php';

function _stattic_component_digest(mixed $value): string
{
    return 'sha256:' . hash('sha256', _stattic_canonical_json($value));
}

function _stattic_component_lock(string $engineRoot): ?array
{
    $raw = @file_get_contents($engineRoot . '/wordpress/components.json');
    $lock = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($lock)
        || ($lock['format'] ?? null) !== 'spacefast.wordpress-components'
        || ($lock['version'] ?? null) !== 1
        || !is_string($lock['platformRelease'] ?? null)
        || !is_string($lock['digest'] ?? null)
        || !is_array($lock['components'] ?? null)
    ) {
        return null;
    }
    // Digest the lock as JSON objects, not PHP arrays, so an empty object the
    // control plane wrote as `{}` is digested as `{}` rather than `[]`.
    $meaning = json_decode($raw, false);
    unset($meaning->digest);
    return hash_equals(_stattic_component_digest($meaning), $lock['digest']) ? $lock : null;
}
