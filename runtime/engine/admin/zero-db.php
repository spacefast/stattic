<?php
declare(strict_types=1);

/**
 * The management read of a version's Zero database. It never takes a table name
 * as SQL: the dumpable set comes from the version's persisted capsule schema,
 * every physical name is recomputed from this space's scoping rule (foreign
 * Space and WordPress core tables fail that equality), and the broker grant is
 * db.read only.
 *
 * The read runs on the engine's own MySQL broker (shared/db-broker.php) in this
 * worker. A dump touches one table per operation and an export pages through
 * one, so a link outliving the operation saves a handshake per table.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/db-broker.php';

const STATTIC_ZERO_DB_DUMP_ROWS_DEFAULT = 25;
const STATTIC_ZERO_DB_DUMP_ROWS_MAX = 100;
const STATTIC_ZERO_DB_DUMP_TABLES_MAX = 50;
const STATTIC_ZERO_DB_DUMP_MAX_BYTES = 1048576; // 1 MiB
// Covers the whole set: the compiler admits at most 128 endpoints and 128 runs.
const STATTIC_ZERO_DB_DUMP_ARTIFACT_SCAN_MAX = 256;
const STATTIC_ZERO_DB_EXPORT_ROWS_DEFAULT = 500;
const STATTIC_ZERO_DB_EXPORT_ROWS_MAX = 1000;
const STATTIC_ZERO_DB_EXPORT_PAGE_MAX_BYTES = 16777216; // 16 MiB
const STATTIC_ZERO_DB_READ_DEADLINE_MS = 30000;

function _stattic_zero_db_dump(string $privateRoot, string $spaceId, string $versionId): void
{
    $versionRoot = _stattic_zero_db_require_capsule_version($privateRoot, $spaceId, $versionId, 'enumerated');

    $requested = _stattic_zero_db_requested_table();
    $limit = _stattic_zero_db_dump_limit();
    $resolved = _stattic_zero_db_resolve_scoped($versionRoot, $spaceId);
    $tables = $resolved['scoped']['tables'];
    $schemaHash = _stattic_zero_db_dump_schema_hash($resolved['config'], $resolved['artifacts']);

    if ($requested !== null) {
        if (!isset($tables[$requested])) {
            // Identical answer for a table that never existed and one owned by
            // another space, and it lands before any connection is opened.
            _stattic_problem_response(
                404,
                'zero_db_table_not_found',
                'Table is not part of this version\'s Zero database.',
                ['details' => ['table' => $requested]],
            );
        }
        $tables = [$requested => $tables[$requested]];
    }
    if ($tables === []) {
        _stattic_zero_db_dump_response($versionId, $schemaHash, []);
    }
    if (count($tables) > STATTIC_ZERO_DB_DUMP_TABLES_MAX) {
        _stattic_zero_db_dump_too_large('This Zero database has more tables than one dump returns; request a single table.');
    }

    $dumped = [];
    $bytes = 0;
    foreach ($tables as $name => $table) {
        $response = _stattic_zero_db_read_operation($resolved['config'], _stattic_zero_db_dump_operation($table, $limit));
        $bytes += strlen($response);
        if ($bytes > STATTIC_ZERO_DB_DUMP_MAX_BYTES) {
            _stattic_zero_db_dump_too_large('This Zero database dump exceeds the response size limit; request a single table or a smaller limit.');
        }
        // JSON_BIGINT_AS_STRING: PHP has no integer for an unsigned BIGINT past
        // 2^63; decoding to float would round it away.
        $decoded = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true || !is_array($decoded['rows'] ?? null)) {
            // 500 rather than a retryable status: no broker refusal that lands
            // here clears by asking again.
            _stattic_problem_response(
                500,
                'zero_db_dump_failed',
                'Zero database dump failed.',
                ['details' => [
                    'table' => $name,
                    'zero_db_code' => is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : null,
                ]],
            );
        }
        $dumped[] = ['name' => $name, 'rows' => array_values($decoded['rows'])];
    }

    _stattic_zero_db_dump_response($versionId, $schemaHash, $dumped);
}

// Inventory, or one keyset page. Each page re-reads the schema hash so a publish
// mid-export stops it instead of producing a mixed file.
function _stattic_zero_db_export(string $privateRoot, string $spaceId, string $versionId): void
{
    $versionRoot = _stattic_zero_db_require_capsule_version($privateRoot, $spaceId, $versionId, 'exported');

    $resolved = _stattic_zero_db_resolve_scoped($versionRoot, $spaceId);
    $scoped = $resolved['scoped'];
    $schemaHash = _stattic_zero_db_dump_schema_hash($resolved['config'], $resolved['artifacts']);
    if ($schemaHash === null) {
        _stattic_problem_response(
            409,
            'zero_db_export_schema_unavailable',
            'This capsule has no stable schema hash, so a complete export cannot be fenced safely.',
        );
    }

    $tableName = _stattic_zero_db_requested_table();
    if ($tableName === null) {
        _stattic_json_response(200, [
            'spaceId' => $spaceId,
            'versionId' => $versionId,
            'schemaHash' => $schemaHash,
            'consistency' => 'per_page',
            'ordering' => ['tables' => 'name_asc', 'rows' => 'created_at_id_asc'],
            'tables' => array_keys($scoped['tables']),
        ]);
    }
    if (!isset($scoped['tables'][$tableName])) {
        _stattic_problem_response(
            404,
            'zero_db_table_not_found',
            'Table is not part of this version\'s Zero database.',
            ['details' => ['table' => $tableName]],
        );
    }
    $requestedSchemaHash = $_GET['schemaHash'] ?? null;
    if (!is_string($requestedSchemaHash) || $requestedSchemaHash !== $schemaHash) {
        _stattic_problem_response(
            409,
            'zero_db_export_schema_changed',
            'Database schema changed during export. Start a fresh export.',
        );
    }
    $limit = _stattic_zero_db_export_limit();
    $after = _stattic_zero_db_export_cursor($tableName, $schemaHash);

    $response = _stattic_zero_db_read_operation(
        $resolved['config'],
        _stattic_zero_db_export_operation($scoped['tables'][$tableName], $limit, $after)
    );
    if (strlen($response) > STATTIC_ZERO_DB_EXPORT_PAGE_MAX_BYTES) {
        _stattic_problem_response(
            413,
            'zero_db_export_page_too_large',
            'Database export page exceeds 16 MiB. Retry with a smaller page size.',
        );
    }
    $decoded = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true || !is_array($decoded['rows'] ?? null)) {
        _stattic_problem_response(
            500,
            'zero_db_export_failed',
            'Zero database export failed.',
            ['details' => [
                'table' => $tableName,
                'zero_db_code' => is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : null,
            ]],
        );
    }
    $rows = array_values($decoded['rows']);
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $last = array_last($rows);
    $table = $scoped['tables'][$tableName];
    $lastCreatedAt = is_array($last)
        ? _stattic_zero_db_export_row_cursor_value($last, $table['createdAt'], 64)
        : null;
    $lastId = is_array($last)
        ? _stattic_zero_db_export_row_cursor_value($last, $table['id'], 256)
        : null;
    if ($last !== null && ($lastCreatedAt === null || $lastId === null)) {
        _stattic_problem_response(500, 'zero_db_export_failed', 'Zero database export row identity is invalid.');
    }
    _stattic_json_response(200, [
        'rows' => $rows,
        'cursor' => $hasMore && $lastCreatedAt !== null && $lastId !== null
            ? _stattic_zero_db_export_encode_cursor($tableName, $schemaHash, $lastCreatedAt, $lastId)
            : null,
    ]);
}

function _stattic_zero_db_require_capsule_version(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $verb
): string
{
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    if (!is_dir($versionRoot)) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    if (
        is_file($versionRoot . '/functions/config.json')
        && !is_file($versionRoot . '/zero/config.json')
    ) {
        _stattic_problem_response(
            409,
            'zero_db_kind_unsupported',
            'A Functions worker declares no tables, so its database cannot be ' . $verb . '.',
            ['details' => ['runtimeKind' => 'functions']],
        );
    }

    return $versionRoot;
}

/**
 * @return array{config:array<string,mixed>,artifacts:list<array<string,mixed>>,scoped:array{tables:array<string,array{physical:string,orderBy:?string,createdAt:string,id:string}>,unscoped:list<string>}}
 */
function _stattic_zero_db_resolve_scoped(string $versionRoot, string $spaceId): array
{
    $config = _stattic_runtime_read_json($versionRoot . '/zero/config.json');
    $config = is_array($config) ? $config : [];
    $artifacts = _stattic_zero_db_program_artifacts($versionRoot);
    $scoped = _stattic_zero_db_scoped_tables($spaceId, $artifacts, $config);
    if ($scoped['unscoped'] !== []) {
        // Fail the whole read: a name outside this space's scope means the
        // artifacts are not the ones this space published, and a partial answer
        // would hide that behind the rows that did pass.
        _stattic_problem_response(
            409,
            'zero_db_table_unscoped',
            'This version declares a table outside the space\'s database scope.',
            ['details' => ['tables' => $scoped['unscoped']]],
        );
    }

    return ['config' => $config, 'artifacts' => $artifacts, 'scoped' => $scoped];
}

// Withholding db.write is what stops these routes changing tenant data, however
// their SQL is later edited: the broker classifies the SQL itself, so a mutation
// is refused even when spelled as a read. A refusal comes back as an `ok:false`
// document, which every caller treats as a failed read.
function _stattic_zero_db_read_operation(array $config, string $operation): string
{
    $env = _stattic_zero_runner_base_env($config);
    _stattic_db_broker_set_read_deadline_ms(_stattic_zero_db_read_deadline_ms());
    _stattic_db_broker_bind(
        is_string($env['SPACEFAST_ZERO_DATABASE_URL'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL'] : null,
        is_string($env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] : null
    );
    _stattic_db_broker_grant(['db.read']);
    $answer = _stattic_db_broker_execute($operation);
    // A dump reads many tables on one link, so the rollback lands per operation,
    // not per route: no read here opens a transaction, and one that did must not
    // hold row locks across the next table's query.
    _stattic_db_broker_rollback_open_transaction();

    return $answer;
}

function _stattic_zero_db_read_deadline_ms(): int
{
    // Production is fixed. The real-MySQL behavior suite shortens the deadline
    // to prove a blocked query is interrupted without adding thirty seconds to
    // every acceptance run.
    if (getenv('SPACEFAST_RUNTIME_TEST_MODE') === '1') {
        $override = getenv('SPACEFAST_ZERO_DB_READ_DEADLINE_MS');
        if (is_string($override) && preg_match('/^[1-9][0-9]{0,4}$/', $override) === 1) {
            return min(STATTIC_ZERO_DB_READ_DEADLINE_MS, (int) $override);
        }
    }
    return STATTIC_ZERO_DB_READ_DEADLINE_MS;
}

/** @param array<string,mixed> $row */
function _stattic_zero_db_export_row_cursor_value(array $row, string $column, int $maxLength): ?string
{
    $value = $row[$column] ?? null;
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $encoded = (string) $value;
    return $encoded !== '' && strlen($encoded) <= $maxLength ? $encoded : null;
}

function _stattic_zero_db_export_limit(): int
{
    return _stattic_query_limit(STATTIC_ZERO_DB_EXPORT_ROWS_DEFAULT, STATTIC_ZERO_DB_EXPORT_ROWS_MAX, 'Export page size must be between 1 and 1000.');
}

/** @return array{createdAt:string,id:string}|null */
function _stattic_zero_db_export_cursor(string $tableName, string $schemaHash): ?array
{
    $payload = _stattic_query_cursor_payload(4096, '_stattic_zero_db_export_bad_cursor');
    if ($payload === null) {
        return null;
    }
    if (
        ($payload['v'] ?? null) !== 1
        || ($payload['table'] ?? null) !== $tableName
        || ($payload['schemaHash'] ?? null) !== $schemaHash
        || !is_string($payload['createdAt'] ?? null)
        || $payload['createdAt'] === ''
        || strlen($payload['createdAt']) > 64
        || !is_string($payload['id'] ?? null)
        || $payload['id'] === ''
        || strlen($payload['id']) > 256
    ) {
        _stattic_zero_db_export_bad_cursor();
    }
    return ['createdAt' => $payload['createdAt'], 'id' => $payload['id']];
}

function _stattic_zero_db_export_encode_cursor(
    string $tableName,
    string $schemaHash,
    string $createdAt,
    string $id
): string
{
    return _stattic_query_cursor_encode([
        'v' => 1,
        'table' => $tableName,
        'schemaHash' => $schemaHash,
        'createdAt' => $createdAt,
        'id' => $id,
    ]);
}

function _stattic_zero_db_export_bad_cursor(): never
{
    _stattic_problem_response(422, 'validation_error', 'Database export cursor is invalid for this table and schema.');
}

/**
 * @param array{physical:string,orderBy:?string,createdAt:string,id:string} $table
 * @param array{createdAt:string,id:string}|null $after
 */
function _stattic_zero_db_export_operation(array $table, int $limit, ?array $after): string
{
    $sql = 'SELECT * FROM ' . _stattic_zero_db_quote($table['physical']);
    $createdAt = _stattic_zero_db_quote($table['createdAt']);
    $id = _stattic_zero_db_quote($table['id']);
    $params = [];
    if ($after !== null) {
        $sql .= ' WHERE (' . $createdAt . ' > ? OR (' . $createdAt . ' = ? AND ' . $id . ' > ?))';
        $params = [$after['createdAt'], $after['createdAt'], $after['id']];
    }
    $sql .= ' ORDER BY ' . $createdAt . ' ASC, ' . $id . ' ASC LIMIT ' . ($limit + 1);
    $encoded = json_encode(['sql' => $sql, 'params' => $params], JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '{}';
}

/**
 * No part of this SQL is caller-supplied text: identifiers were validated as bare
 * identifiers before arriving and the limit is a literal integer.
 *
 * @param array{physical:string,orderBy:?string} $table
 */
function _stattic_zero_db_dump_operation(array $table, int $limit): string
{
    $sql = 'SELECT * FROM ' . _stattic_zero_db_quote($table['physical']);
    if ($table['orderBy'] !== null) {
        $sql .= ' ORDER BY ' . _stattic_zero_db_quote($table['orderBy']);
    }
    $sql .= ' LIMIT ' . $limit;
    $encoded = json_encode(['sql' => $sql], JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '{}';
}

function _stattic_zero_db_dump_response(string $versionId, ?string $schemaHash, array $tables): never
{
    _stattic_json_response(200, [
        'versionId' => $versionId,
        // The engine stores capsules, not capsule identities: the control plane
        // fills this in from its own version metadata.
        'artifactId' => null,
        'schemaHash' => $schemaHash,
        'tables' => $tables,
    ]);
}

function _stattic_zero_db_dump_too_large(string $message): never
{
    _stattic_problem_response(413, 'zero_db_dump_too_large', $message);
}

function _stattic_zero_db_requested_table(): ?string
{
    $raw = $_GET['table'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw) || !_stattic_zero_db_logical_name_valid($raw)) {
        _stattic_problem_response(422, 'zero_db_table_invalid', 'Table name is not a Zero table identifier.');
    }

    return $raw;
}

/** Clamped, not refused: the contract's bounds are enforced at the control plane. */
function _stattic_zero_db_dump_limit(): int
{
    $raw = $_GET['limit'] ?? null;
    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
        return STATTIC_ZERO_DB_DUMP_ROWS_DEFAULT;
    }

    return min((int) $raw, STATTIC_ZERO_DB_DUMP_ROWS_MAX);
}

/**
 * Recomputing each physical name makes the artifact untrusted input rather than
 * authority; anything failing that equality lands in `unscoped`.
 *
 * @param list<array<string,mixed>> $artifacts
 * @param array<string,mixed> $config
 * @return array{tables:array<string,array{physical:string,orderBy:?string,createdAt:string,id:string}>,unscoped:list<string>}
 */
function _stattic_zero_db_scoped_tables(string $spaceId, array $artifacts, array $config = []): array
{
    $artifactTables = [];
    $unscoped = [];
    foreach ($artifacts as $artifact) {
        $db = is_array($artifact['db'] ?? null) ? $artifact['db'] : [];
        $declared = is_array($db['tables'] ?? null) ? $db['tables'] : [];
        foreach ($declared as $name => $table) {
            if (!is_string($name) || isset($artifactTables[$name]) || in_array($name, $unscoped, true) || !is_array($table)) {
                continue;
            }
            if (!_stattic_zero_db_logical_name_valid($name)) {
                continue;
            }
            $physical = $table['physicalName'] ?? null;
            if (!is_string($physical) || $physical !== _stattic_zero_db_expected_physical_name($spaceId, $name)) {
                $unscoped[] = $name;
                continue;
            }
            $artifactTables[$name] = [
                'physical' => $physical,
                'orderBy' => _stattic_zero_db_primary_column($table),
                'createdAt' => _stattic_zero_db_physical_column($table, 'createdAt') ?? 'createdAt',
                'id' => _stattic_zero_db_physical_column($table, 'id') ?? 'id',
            ];
        }
    }

    $schema = _stattic_zero_db_persisted_schema($config);
    if ($schema === null) {
        $tables = $artifactTables;
    } else {
        $tables = [];
        foreach ($schema as $name => $table) {
            if (!is_string($name) || !is_array($table) || !_stattic_zero_db_logical_name_valid($name)) {
                continue;
            }
            if (isset($artifactTables[$name])) {
                $tables[$name] = $artifactTables[$name];
                continue;
            }
            if (in_array($name, $unscoped, true)) {
                continue;
            }
            $tables[$name] = [
                'physical' => _stattic_zero_db_expected_physical_name($spaceId, $name),
                'orderBy' => 'id',
                'createdAt' => 'createdAt',
                'id' => 'id',
            ];
        }
    }
    ksort($tables, SORT_STRING);
    sort($unscoped, SORT_STRING);

    return ['tables' => $tables, 'unscoped' => $unscoped];
}

function _stattic_zero_db_persisted_schema(array $config): ?array
{
    $artifact = is_array($config['artifact'] ?? null) ? $config['artifact'] : null;
    $server = is_array($artifact['server'] ?? null) ? $artifact['server'] : null;
    if ($server === null || !array_key_exists('schema', $server) || !is_array($server['schema'])) {
        return null;
    }
    return $server['schema'];
}

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

function _stattic_zero_db_primary_column(array $table): ?string
{
    $primary = $table['primaryKey'] ?? null;
    if (!is_string($primary) || $primary === '') {
        return null;
    }
    return _stattic_zero_db_physical_column($table, $primary) ?? (
        _stattic_zero_db_identifier_valid($primary) ? $primary : null
    );
}

function _stattic_zero_db_physical_column(array $table, string $logical): ?string
{
    $columns = is_array($table['columns'] ?? null) ? $table['columns'] : [];
    $column = $columns[$logical] ?? null;
    $physical = is_string($column)
        ? $column
        : (is_array($column) && is_string($column['physicalName'] ?? null) ? $column['physicalName'] : null);

    return is_string($physical) && _stattic_zero_db_identifier_valid($physical) ? $physical : null;
}

function _stattic_zero_db_program_artifacts(string $versionRoot): array
{
    $artifacts = [];
    $lanes = [
        ['zero/endpoints-index.json', 'endpoints', 'zero/endpoints/', 'stattic.zero.endpoint.v1'],
        ['zero/runs-index.json', 'runs', 'zero/runs/', 'stattic.zero.run.v1'],
    ];
    foreach ($lanes as [$indexPath, $key, $prefix, $format]) {
        $index = _stattic_runtime_read_json($versionRoot . '/' . $indexPath);
        $entries = is_array($index) && is_array($index[$key] ?? null) ? $index[$key] : [];
        foreach ($entries as $artifactPath) {
            if (count($artifacts) >= STATTIC_ZERO_DB_DUMP_ARTIFACT_SCAN_MAX) {
                return $artifacts;
            }
            if (
                !is_string($artifactPath)
                || !str_starts_with($artifactPath, $prefix)
                || !str_ends_with($artifactPath, '.json')
                || !_stattic_runtime_relative_artifact_path_valid($artifactPath)
            ) {
                continue;
            }
            $artifact = _stattic_runtime_read_json($versionRoot . '/' . $artifactPath);
            if (is_array($artifact) && ($artifact['format'] ?? null) === $format) {
                $artifacts[] = $artifact;
            }
        }
    }

    return $artifacts;
}

// A non-sha256 value is reported as absent rather than passed on.
function _stattic_zero_db_dump_schema_hash(array $config, array $artifacts): ?string
{
    $migrations = is_array($config['migrations'] ?? null) ? $config['migrations'] : [];
    $hash = $migrations['schemaHash'] ?? null;
    if (is_string($hash) && _stattic_zero_db_schema_hash_valid($hash)) {
        return $hash;
    }
    foreach ($artifacts as $artifact) {
        $db = is_array($artifact['db'] ?? null) ? $artifact['db'] : [];
        $candidate = $db['schemaHash'] ?? null;
        if (is_string($candidate) && _stattic_zero_db_schema_hash_valid($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function _stattic_zero_db_schema_hash_valid(string $value): bool
{
    return str_starts_with($value, 'sha256:') && _stattic_is_sha256_hex(substr($value, 7));
}

function _stattic_zero_db_logical_name_valid(string $value): bool
{
    return strlen($value) <= 128 && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
}

// Narrower than MySQL's own rules: the generator only emits `[a-z0-9_]`, and
// anything wider is a reason to refuse rather than escape.
function _stattic_zero_db_identifier_valid(string $value): bool
{
    return preg_match('/^[A-Za-z0-9_$]{1,64}$/', $value) === 1;
}

function _stattic_zero_db_quote(string $identifier): string
{
    return '`' . $identifier . '`';
}
