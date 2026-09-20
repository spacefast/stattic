<?php
declare(strict_types=1);

require_once __DIR__ . '/d1-sql.php';
require_once __DIR__ . '/db-broker.php';

function _sf_d1_plan(string $spaceId, array $database): array
{
    $name = $database['databaseName'] ?? null;
    if (!is_string($name) || $name === '' || strlen($name) > 128) throw new RuntimeException('D1_CONFIG_INVALID: database name.');
    $scope = $spaceId . "\0" . $name;
    $schema = []; $plan = []; $names = []; $count = 0;
    $migrations = $database['migrations'] ?? [];
    if (!is_array($migrations) || !array_is_list($migrations) || count($migrations) > 64) throw new RuntimeException('D1_CONFIG_INVALID: migrations must be a list of at most 64 files.');
    foreach ($migrations as $migration) {
        $sql = $migration['sql'] ?? null; $file = $migration['name'] ?? null;
        if (!is_string($sql) || strlen($sql) > 65536 || !is_string($file) || $file === '' || strlen($file) > 255 || isset($names[$file])) throw new RuntimeException('D1_CONFIG_INVALID: migration.');
        $names[$file] = true;
        $statements = [];
        foreach (SpacefastD1Sql::statements($sql) as $tokens) {
            if (++$count > 256) throw new RuntimeException('D1_CONFIG_INVALID: too many migration statements.');
            $statements[] = SpacefastD1Sql::fromTokens($tokens)->migration($scope, $schema);
        }
        $plan[] = ['name' => $file, 'sha256' => hash('sha256', $sql), 'statements' => $statements];
    }
    return ['scope' => $scope, 'schema' => $schema, 'migrations' => $plan];
}

function _sf_d1_run(array $operation): array
{
    $response = json_decode(_stattic_db_broker_execute(json_encode($operation, JSON_THROW_ON_ERROR)), true, 64, JSON_THROW_ON_ERROR);
    if (($response['ok'] ?? false) !== true) throw new RuntimeException((string) ($response['message'] ?? 'D1_ERROR: database operation failed.'));
    return $response;
}

/** The database lock serializes migrations across versions and PHP workers. */
function _sf_d1_migrate(string $spaceId, array $databases): void
{
    // Parse every migration before creating tables, so unsupported SQL leaves no partial schema.
    $plans = array_map(fn ($database) => _sf_d1_plan($spaceId, $database), $databases);
    $operations = array_sum(array_map(fn ($plan) => 4 + count($plan['migrations']) + 3 * array_sum(array_map(fn ($m) => count($m['statements']), $plan['migrations'])), $plans));
    if ($operations > _stattic_db_broker_operation_budget()) throw new RuntimeException('D1_MIGRATION_LIMIT: migration plan exceeds the publish database operation budget.');
    foreach ($plans as $plan) {
        $ledger = SpacefastD1Sql::quote('sf_d1_m_' . substr(hash('sha256', $plan['scope']), 0, 40));
        $lock = 'sf_d1_' . substr(hash('sha256', $plan['scope']), 0, 40);
        $acquired = _sf_d1_run(['mode' => 'query', 'sql' => 'SELECT GET_LOCK(?, 10) AS acquired', 'params' => [$lock]]);
        if (($acquired['rows'][0]['acquired'] ?? null) !== 1) throw new RuntimeException('D1_MIGRATION_BUSY: another publish is migrating this database.');
        try {
            _sf_d1_run(['mode' => 'execute', 'sql' => "CREATE TABLE IF NOT EXISTS $ledger (name VARBINARY(255) PRIMARY KEY, digest CHAR(64) NOT NULL, completed INT NOT NULL, pending TINYINT NOT NULL)"]);
            $rows = _sf_d1_run(['mode' => 'query', 'sql' => "SELECT name, digest, completed, pending FROM $ledger ORDER BY name"]);
            if (array_column($rows['rows'], 'name') !== array_slice(array_column($plan['migrations'], 'name'), 0, count($rows['rows']))) throw new RuntimeException('D1_MIGRATION_CHANGED: new migrations must follow applied files in filename order.');
            $applied = array_column($rows['rows'], null, 'name');
            foreach ($applied as $file => $row) {
                $declared = array_values(array_filter($plan['migrations'], fn ($m) => $m['name'] === $file));
                if ($declared === [] || !hash_equals($row['digest'], $declared[0]['sha256'])) throw new RuntimeException('D1_MIGRATION_CHANGED: preserve applied migrations and append new files.');
                if ($row['pending'] !== 0) throw new RuntimeException('D1_MIGRATION_INTERRUPTED: the previous DDL outcome requires recovery before publishing.');
            }
            foreach ($plan['migrations'] as $migration) {
                $row = $applied[$migration['name']] ?? null;
                if ($row === null) {
                    _sf_d1_run(['mode' => 'execute', 'sql' => "INSERT INTO $ledger (name,digest,completed,pending) VALUES (?,?,0,0)", 'params' => [$migration['name'], $migration['sha256']]]);
                }
                foreach ($migration['statements'] as $index => $sql) {
                    if ($index < ($row['completed'] ?? 0)) continue;
                    _sf_d1_run(['mode' => 'execute', 'sql' => "UPDATE $ledger SET pending=1 WHERE name=?", 'params' => [$migration['name']]]);
                    // MySQL DDL commits implicitly. Record an intent first; after a crash an
                    // ambiguous outcome must never be replayed or reported as successful.
                    _sf_d1_run(['mode' => 'execute', 'sql' => $sql]);
                    _sf_d1_run(['mode' => 'execute', 'sql' => "UPDATE $ledger SET completed=?, pending=0 WHERE name=?", 'params' => [$index + 1, $migration['name']]]);
                }
            }
        } finally {
            _sf_d1_run(['mode' => 'query', 'sql' => 'SELECT RELEASE_LOCK(?)', 'params' => [$lock]]);
        }
    }
}

function _sf_d1_statement(array $statement, array $plan): array
{
    if (!is_string($statement['sql'] ?? null) || !is_array($statement['params'] ?? []) || !array_is_list($statement['params'] ?? [])) throw new RuntimeException('D1_TYPE_ERROR: invalid prepared statement.');
    $compiled = (new SpacefastD1Sql($statement['sql']))->query($plan['scope'], $plan['schema']);
    return $compiled + ['params' => $statement['params'] ?? []];
}

function _sf_d1_result(array $result, array $statement): array
{
    if ($statement['conflict'] === 'update' && isset($result['affectedRows'])) {
        // A supported upsert inserts one row or updates one row. D1 counts the
        // matched update even when its value is unchanged; MySQL reports 0 or 2.
        $result['affectedRows'] = 1;
    }
    return $result;
}

/** Uses the authenticated version's database declarations, never caller-provided schema. */
function _sf_d1_execute(string $body, string $spaceId, array $databases): string
{
    try {
        $operation = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $binding = $operation['d1'] ?? null;
        $database = null;
        foreach ($databases as $candidate) {
            if (($candidate['binding'] ?? null) === $binding) { $database = $candidate; break; }
        }
        if ($database === null) throw new RuntimeException('D1_BINDING_NOT_FOUND: database is not declared by this version.');
        $plan = _sf_d1_plan($spaceId, $database);
        if (($operation['mode'] ?? null) === 'transaction') {
            $statements = $operation['statements'] ?? null;
            if (!is_array($statements) || !array_is_list($statements) || $statements === []) throw new RuntimeException('D1_TYPE_ERROR: invalid batch.');
            $compiled = array_map(fn ($statement) => _sf_d1_statement($statement, $plan), $statements);
            $result = _sf_d1_run(['mode' => 'transaction', 'statements' => $compiled]);
            foreach ($compiled as $index => $statement) $result['results'][$index] = _sf_d1_result($result['results'][$index], $statement);
        } else {
            $statement = _sf_d1_statement($operation, $plan);
            $result = _sf_d1_result(_sf_d1_run($statement), $statement);
        }
        return json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        $message = $error->getMessage();
        // Do not expose MySQL's duplicate values or provider details through D1 errors.
        if (str_contains($message, 'ERROR 1062 ')) $message = 'UNIQUE constraint failed';
        elseif (str_contains($message, 'ERROR 1146 ')) $message = 'no such table';
        elseif (!preg_match('/^(D1_[A-Z_]+:|no such table:)/', $message)) $message = 'D1_ERROR: database operation failed.';
        return json_encode(['ok' => false, 'code' => 'd1_error', 'message' => $message], JSON_THROW_ON_ERROR);
    }
}
