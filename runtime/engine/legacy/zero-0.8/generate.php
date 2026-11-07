<?php
declare(strict_types=1);

function _stattic_runtime_zero_compiler_entries(array $input, string $snakeIdKey, string $camelIdKey): array
{
    $entries = [];
    foreach ($input as $entry) {
        if (!is_array($entry)) {
            $entries[] = $entry;
            continue;
        }
        if (is_string($entry[$snakeIdKey] ?? null) && !array_key_exists($camelIdKey, $entry)) {
            $entry[$camelIdKey] = $entry[$snakeIdKey];
        }
        if (is_string($entry['schema_hash'] ?? null) && !array_key_exists('schemaHash', $entry)) {
            $entry['schemaHash'] = $entry['schema_hash'];
        }
        if (is_string($entry['execution_mode'] ?? null) && !array_key_exists('executionMode', $entry)) {
            $entry['executionMode'] = $entry['execution_mode'];
        }
        if (!array_key_exists('executionMode', $entry)) {
            // Capsules built before the execution law declare no mode. Derive
            // it, sharing the one rule the serve path applies to the same
            // capsule: an endpoint from its HTTP method, a run from the
            // operation its id names. The engine forwards this stamped mode on
            // every invocation and the runner refuses an envelope that disagrees
            // with the artifact, so the default is the lane, not a widening.
            $entry['executionMode'] = $camelIdKey === 'endpointId'
                ? _stattic_zero_derived_endpoint_execution_mode((string) ($entry['method'] ?? ''))
                : _stattic_zero_derived_run_execution_mode((string) ($entry[$camelIdKey] ?? ''));
        }
        $entry['capabilities'] = _stattic_runtime_zero_endpoint_capabilities($entry['capabilities'] ?? []);
        if (is_array($entry['crypto_keys'] ?? null) && !array_key_exists('cryptoKeys', $entry)) {
            $entry['cryptoKeys'] = array_values(array_filter($entry['crypto_keys'], 'is_string'));
        }
        unset($entry[$snakeIdKey], $entry['schema_hash'], $entry['execution_mode'], $entry['crypto_keys']);
        $entries[] = $entry;
    }

    return $entries;
}

/**
 * Apply the version's compiled Zero migrations, in this worker. The database
 * URL resolution is the one every brokered lane uses, so a capsule's schema is
 * created against exactly the database its endpoints will later read.
 */
function _stattic_runtime_apply_zero_migrations(string $versionRoot): void
{
    $path = $versionRoot . '/zero/migrations.json';
    if (!is_file($path)) {
        return;
    }
    $zeroConfig = _stattic_runtime_read_json($versionRoot . '/zero/config.json');
    $env = _stattic_zero_runner_base_env(is_array($zeroConfig) ? $zeroConfig : []);
    _stattic_db_broker_bind_env($env);
    $result = _stattic_db_broker_apply_migrations($path);
    // Publishing is the only thing this request does with a database, and the
    // link is worth nothing to the rest of it.
    _stattic_db_broker_close();
    if ($result['ok']) {
        return;
    }
    _stattic_problem_response(
        500,
        'zero_migration_failed',
        _stattic_zero_debug_message('Zero DB migrations failed.', $result['message']),
        ['details' => ['zero_db_code' => $result['code']]],
    );
}

function _stattic_runtime_zero_endpoint_capabilities(mixed $input): array
{
    $raw = is_array($input) ? $input : [];
    $out = [];
    foreach (STATTIC_RUNTIME_ZERO_CAPABILITIES as $capability => $granted) {
        $out[$capability] = array_key_exists($capability, $raw)
            ? (bool) $raw[$capability]
            : $granted;
    }

    return $out;
}


// Every capability the publish path recognizes, with the grant applied when the
// descriptor omits it. `fetch` is open to every handler kind — where a request
// may reach is the space's egress scope at request time, not a per-version
// declaration. The remaining write-side authorities default closed: a `read`
// handler may not carry one, so an open default would compile an artifact the
// runner refuses on every request.
// Mirrors ZeroCapabilities in stattic-runtime-core's model.rs, key for key
// and default for default. A capability missing here cannot be declared
// through this intake at all — the normalizer emits exactly these keys — so
// drift between the two lists silently strips grants from every artifact.
const STATTIC_RUNTIME_ZERO_CAPABILITIES = [
    'db' => true,
    'fetch' => true,
    'auth' => true,
    'env' => true,
    'realtime' => false,
    'logging' => true,
    'gravatar' => true,
    'spam' => true,
    'email' => false,
    'content' => false,
    'storage' => false,
    'connectors' => false,
    'crypto' => false,
];
