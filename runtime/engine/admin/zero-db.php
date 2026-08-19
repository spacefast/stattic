<?php
declare(strict_types=1);

/**
 * The management read of a version's Zero database. It never takes a table name
 * as SQL: the dumpable set comes from the version's persisted capsule schema,
 * every physical name is recomputed from this space's scoping rule (co-tenant
 * and WordPress core tables fail that equality), and the broker grant is
 * db.read only.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/native-process.php';

const STATTIC_ZERO_DB_DUMP_ROWS_DEFAULT = 25;
const STATTIC_ZERO_DB_DUMP_ROWS_MAX = 100;
const STATTIC_ZERO_DB_DUMP_TABLES_MAX = 50;
const STATTIC_ZERO_DB_DUMP_MAX_BYTES = 1048576; // 1 MiB
// Covers the whole set: the compiler admits at most 128 endpoints and 128 runs.
const STATTIC_ZERO_DB_DUMP_ARTIFACT_SCAN_MAX = 256;
const STATTIC_ZERO_DB_EXPORT_ROWS_DEFAULT = 500;
const STATTIC_ZERO_DB_EXPORT_ROWS_MAX = 1000;
const STATTIC_ZERO_DB_EXPORT_PAGE_MAX_BYTES = 16777216; // 16 MiB
const STATTIC_ZERO_DB_IMPORT_ROWS_MAX = 1000;
// The native broker binds at most 256 positional parameters per statement
// (crates/stattic-zero-runner/src/db.rs DB_PARAM_MAX_COUNT), so an import page
// is chunked to fit rather than refused.
const STATTIC_ZERO_DB_IMPORT_PARAMS_MAX = 256;
// And at most 64 KiB of operation JSON on stdin (DB_OPERATION_MAX_BYTES); stay a
// little under it so the broker never sees the overrun refusal.
const STATTIC_ZERO_DB_IMPORT_OPERATION_MAX_BYTES = 60000;

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
    $last = $rows === [] ? null : $rows[array_key_last($rows)];
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

/**
 * Replays an exported page against a schema the target already migrated: this
 * route creates nothing, and is idempotent on primary key so the mover may retry.
 *
 * Column identifiers are never taken from the request. The physical column set
 * comes from the DATABASE (`SHOW COLUMNS`), and a row key outside it is refused —
 * so no caller-supplied text ever reaches the SQL text, only the parameters.
 */
function _stattic_zero_db_import(string $privateRoot, string $spaceId, string $versionId): void
{
    $versionRoot = _stattic_zero_db_require_capsule_version($privateRoot, $spaceId, $versionId, 'imported');

    $resolved = _stattic_zero_db_resolve_scoped($versionRoot, $spaceId);
    $schemaHash = _stattic_zero_db_dump_schema_hash($resolved['config'], $resolved['artifacts']);
    if ($schemaHash === null) {
        _stattic_problem_response(
            409,
            'zero_db_import_schema_unavailable',
            'This capsule has no stable schema hash, so an import cannot be fenced safely.',
        );
    }

    $body = _stattic_json_body();
    if (($body['schema_hash'] ?? null) !== $schemaHash) {
        _stattic_problem_response(
            409,
            'zero_db_import_schema_changed',
            'Database schema does not match the export being replayed. Start a fresh export.',
        );
    }
    $tableName = $body['table'] ?? null;
    if (!is_string($tableName) || !_stattic_zero_db_logical_name_valid($tableName)) {
        _stattic_problem_response(422, 'zero_db_table_invalid', 'Table name is not a Zero table identifier.');
    }
    if (!isset($resolved['scoped']['tables'][$tableName])) {
        _stattic_problem_response(
            404,
            'zero_db_table_not_found',
            'Table is not part of this version\'s Zero database.',
            ['details' => ['table' => $tableName]],
        );
    }
    $rows = $body['rows'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > STATTIC_ZERO_DB_IMPORT_ROWS_MAX) {
        _stattic_problem_response(422, 'zero_db_import_rows_invalid', 'rows must be a list of at most 1000 row objects.');
    }
    if (!is_bool($body['done'] ?? null)) {
        _stattic_problem_response(422, 'zero_db_import_rows_invalid', 'done must be a boolean.');
    }

    $physical = $resolved['scoped']['tables'][$tableName]['physical'];
    $imported = $rows === []
        ? 0
        : _stattic_zero_db_import_rows($resolved['config'], $physical, $tableName, $rows);

    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'table' => $tableName,
        'imported' => $imported,
    ]);
}

/**
 * @param list<mixed> $rows
 * @return int rows sent to the database (the import's own count, not affectedRows:
 *             MySQL reports 2 for an ON DUPLICATE KEY update and 0 for a no-op
 *             update, neither of which is a row the mover replayed).
 */
function _stattic_zero_db_import_rows(array $config, string $physical, string $tableName, array $rows): int
{
    $columns = _stattic_zero_db_physical_columns($config, $physical);
    if ($columns === []) {
        _stattic_problem_response(
            409,
            'zero_db_import_table_unavailable',
            'The target table has no readable columns; migrate the target before importing.',
            ['details' => ['table' => $tableName]],
        );
    }

    // Every row in one statement must name the SAME columns — the VALUES tuples
    // are positional — so rows are grouped by their exact column set.
    $groups = [];
    foreach ($rows as $row) {
        if (!is_array($row) || $row === [] || array_is_list($row)) {
            _stattic_problem_response(422, 'zero_db_import_rows_invalid', 'Each import row must be a non-empty object.');
        }
        $names = [];
        foreach ($row as $column => $value) {
            if (!is_string($column) || !isset($columns[$column])) {
                _stattic_problem_response(
                    422,
                    'zero_db_import_column_unknown',
                    'An import row names a column the target table does not have.',
                    ['details' => ['table' => $tableName, 'column' => is_string($column) ? $column : null]],
                );
            }
            if (is_array($value) || is_object($value)) {
                _stattic_problem_response(
                    422,
                    'zero_db_import_rows_invalid',
                    'Import row values must be scalars or null.',
                    ['details' => ['table' => $tableName, 'column' => $column]],
                );
            }
            $names[] = $column;
        }
        sort($names, SORT_STRING);
        $key = implode(',', $names);
        $groups[$key] ??= ['columns' => $names, 'rows' => []];
        $ordered = [];
        foreach ($names as $column) {
            $ordered[] = $row[$column];
        }
        $groups[$key]['rows'][] = $ordered;
    }

    $imported = 0;
    foreach ($groups as $group) {
        $perRow = max(1, count($group['columns']));
        $chunkRows = max(1, intdiv(STATTIC_ZERO_DB_IMPORT_PARAMS_MAX, $perRow));
        $pending = array_chunk($group['rows'], $chunkRows);
        while ($pending !== []) {
            $chunk = array_shift($pending);
            $operation = _stattic_zero_db_import_operation($physical, $group['columns'], $chunk);
            if (strlen($operation) > STATTIC_ZERO_DB_IMPORT_OPERATION_MAX_BYTES) {
                if (count($chunk) === 1) {
                    _stattic_problem_response(
                        413,
                        'zero_db_import_row_too_large',
                        'A single import row exceeds the database operation size limit.',
                        ['details' => ['table' => $tableName]],
                    );
                }
                $half = intdiv(count($chunk), 2);
                array_unshift($pending, array_slice($chunk, 0, $half), array_slice($chunk, $half));
                continue;
            }
            $decoded = json_decode(_stattic_zero_db_write_operation($config, $operation), true, 512, JSON_BIGINT_AS_STRING);
            if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
                _stattic_problem_response(
                    500,
                    'zero_db_import_failed',
                    'Zero database import failed.',
                    ['details' => [
                        'table' => $tableName,
                        'zero_db_code' => is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : null,
                    ]],
                );
            }
            $imported += count($chunk);
        }
    }

    return $imported;
}

/**
 * The target's own column set, read from the database rather than the request or
 * the artifact: that is what makes every identifier in the SQL below
 * database-supplied.
 *
 * @return array<string,true>
 */
function _stattic_zero_db_physical_columns(array $config, string $physical): array
{
    $operation = json_encode(
        ['sql' => 'SHOW COLUMNS FROM ' . _stattic_zero_db_quote($physical)],
        JSON_UNESCAPED_SLASHES
    );
    $decoded = json_decode(
        _stattic_zero_db_read_operation($config, is_string($operation) ? $operation : '{}'),
        true
    );
    if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true || !is_array($decoded['rows'] ?? null)) {
        return [];
    }
    $columns = [];
    foreach ($decoded['rows'] as $row) {
        $name = is_array($row) ? ($row['Field'] ?? null) : null;
        if (is_string($name) && _stattic_zero_db_identifier_valid($name)) {
            $columns[$name] = true;
        }
    }
    return $columns;
}

/**
 * @param list<string> $columns database-supplied identifiers
 * @param list<list<mixed>> $rows values positionally aligned to $columns
 */
function _stattic_zero_db_import_operation(string $physical, array $columns, array $rows): string
{
    $quoted = array_map('_stattic_zero_db_quote', $columns);
    $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $params = [];
    foreach ($rows as $row) {
        foreach ($row as $value) {
            $params[] = $value;
        }
    }
    $assignments = [];
    foreach ($quoted as $column) {
        $assignments[] = $column . ' = VALUES(' . $column . ')';
    }
    $sql = 'INSERT INTO ' . _stattic_zero_db_quote($physical)
        . ' (' . implode(', ', $quoted) . ') VALUES '
        . implode(', ', array_fill(0, count($rows), $tuple))
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    $encoded = json_encode(['sql' => $sql, 'params' => $params, 'mode' => 'execute'], JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '{}';
}

// The ONLY place in this file that grants db.write: the read lanes stay
// structurally incapable of changing tenant data.
function _stattic_zero_db_write_operation(array $config, string $operation): string
{
    $env = _stattic_zero_runner_base_env($config);
    $env['SPACEFAST_DB_BROKER_GRANT'] = 'db.write';
    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), 'db-broker'],
        $env,
        $operation,
        null,
        30000,
        65536,
        65536
    );
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0) {
        return '';
    }
    return trim($result['stdout']);
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

// Withholding db.write is what structurally prevents these routes from changing
// tenant data, however their SQL is later edited. A failed executor returns an
// empty string, which every caller treats as a failed read.
function _stattic_zero_db_read_operation(array $config, string $operation): string
{
    $env = _stattic_zero_runner_base_env($config);
    $env['SPACEFAST_DB_BROKER_GRANT'] = 'db.read';
    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), 'db-broker'],
        $env,
        $operation,
        null,
        30000,
        STATTIC_ZERO_DB_EXPORT_PAGE_MAX_BYTES * 2,
        65536
    );
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0) {
        return '';
    }
    return trim($result['stdout']);
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
    $raw = $_GET['limit'] ?? null;
    if ($raw === null || $raw === '') {
        return STATTIC_ZERO_DB_EXPORT_ROWS_DEFAULT;
    }
    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1 || (int) $raw > STATTIC_ZERO_DB_EXPORT_ROWS_MAX) {
        _stattic_problem_response(422, 'validation_error', 'Export page size must be between 1 and 1000.');
    }
    return (int) $raw;
}

/** @return array{createdAt:string,id:string}|null */
function _stattic_zero_db_export_cursor(string $tableName, string $schemaHash): ?array
{
    $raw = $_GET['cursor'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw) || strlen($raw) > 4096 || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
        _stattic_zero_db_export_bad_cursor();
    }
    $decoded = _stattic_base64url_decode($raw);
    $payload = json_decode($decoded, true);
    if (
        !is_array($payload)
        || ($payload['v'] ?? null) !== 1
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
    $json = json_encode([
        'v' => 1,
        'table' => $tableName,
        'schemaHash' => $schemaHash,
        'createdAt' => $createdAt,
        'id' => $id,
    ], JSON_UNESCAPED_SLASHES);
    return _stattic_base64url_encode(is_string($json) ? $json : '{}');
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
    return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1;
}

function _stattic_zero_db_logical_name_valid(string $value): bool
{
    return strlen($value) <= 128 && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
}

// Deliberately narrower than MySQL's own rules: the generator only emits
// `[a-z0-9_]`, and anything wider is a reason to refuse rather than escape.
function _stattic_zero_db_identifier_valid(string $value): bool
{
    return preg_match('/^[A-Za-z0-9_$]{1,64}$/', $value) === 1;
}

function _stattic_zero_db_quote(string $identifier): string
{
    return '`' . $identifier . '`';
}
