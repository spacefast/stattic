<?php
declare(strict_types=1);

function _stattic_runtime_apply_version_zero_migrations(string $privateRoot, string $spaceId, string $versionId): void
{
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    if (!is_dir($versionRoot)) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    if (!is_file($versionRoot . '/zero/migrations.json')) {
        _stattic_problem_response(409, 'zero_migrations_not_found', 'This version has no stored Zero migration plan.');
    }
    _stattic_runtime_apply_zero_migrations($versionRoot);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'applied' => true,
    ]);
}

function _stattic_runtime_zero_endpoint_count(string $versionRoot): int
{
    $index = _stattic_runtime_read_json($versionRoot . '/zero/endpoints-index.json');
    return is_array($index) && is_array($index['endpoints'] ?? null) ? count($index['endpoints']) : 0;
}

// The one authority for "this version HAS Zero" during finalize: a Space with
// no Zero must not acquire the pattern-route lane.
function _stattic_runtime_version_has_zero_pack(string $versionRoot): bool
{
    return is_file($versionRoot . '/zero/routes.php')
        || is_file($versionRoot . '/zero/endpoints-index.json')
        || is_file($versionRoot . '/zero/runs-index.json');
}

function _stattic_runtime_write_zero_config_artifact(string $versionRoot, array $zero): void
{
    _stattic_runtime_write_json_atomic($versionRoot . '/zero/config.json', _stattic_runtime_zero_config_artifact($zero));
}

function _stattic_runtime_zero_config_artifact(array $zero): array
{
    $artifact = [
        'runtimeKind' => 'zero',
        'artifact' => is_array($zero['artifact'] ?? null) ? $zero['artifact'] : [],
        'install' => is_array($zero['install'] ?? null) ? $zero['install'] : [],
        'variables' => is_array($zero['variables'] ?? null) ? $zero['variables'] : [],
        'variableValues' => is_array($zero['variableValues'] ?? null) ? _stattic_zero_string_map($zero['variableValues']) : [],
        'auth' => is_array($zero['auth'] ?? null) ? $zero['auth'] : [],
        'realtime' => is_array($zero['realtime'] ?? null) ? $zero['realtime'] : [],
        'inspect' => is_array($zero['inspect'] ?? null) ? $zero['inspect'] : [],
    ];
    if (is_string($zero['connectors']['token'] ?? null) && $zero['connectors']['token'] !== '') {
        $artifact['connectors'] = ['token' => $zero['connectors']['token']];
    }
    if (in_array($zero['databaseUrlSource'] ?? null, ['application', 'provider'], true)) {
        // Only ever from the authenticated control-plane finalize request; never
        // inferred from tenant variable contents.
        $artifact['databaseUrlSource'] = $zero['databaseUrlSource'];
    }
    if (array_key_exists('migrations', $zero)) {
        $artifact['migrations'] = $zero['migrations'];
    }
    return $artifact;
}

function _stattic_legacy_zero_write_version_config(string $versionRoot, array $body): void
{
    $zeroFinalize = is_array($body['zero'] ?? null) ? $body['zero'] : null;
    // The config artifact is the marker that turns the pattern-route lane on,
    // so it follows the compiled Zero pack, not the caller's `zero` block.
    if ($zeroFinalize !== null || _stattic_runtime_version_has_zero_pack($versionRoot)) {
        _stattic_runtime_write_zero_config_artifact($versionRoot, $zeroFinalize ?? []);
    }
}
