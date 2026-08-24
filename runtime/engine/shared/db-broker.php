<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/finalizer-protocol.generated.php';

// The MySQL capability broker. PHP owns the database credential and the socket;
// tenant code owns neither and only ever sees operation frames. Every caller is
// in this process. There is no stdio lane and no broker subprocess:
//
//   php-functions.php:   sf_db() inside the tenant's own request, after the
//                        jail; the credential was read before the scrub.
//   functions-relay.php: a dispatched Cloudflare worker calls back into the
//                        space origin over HTTPS, so every brokered call burns
//                        a PHP-FPM worker and round-trip count is the cost that
//                        matters. `mode: transaction` batching is what makes
//                        that lane affordable.
//   admin/zero-db.php:   the management dump/export, on a db.read-only grant.
//
// The `p:` link outlives the request in PHP's own persistent pool, the only
// lifetime this host allows, so a handler's Kth operation costs a round trip
// rather than a connect.
//
// The observable contract is `crates/stattic-zero-runner/src/db.rs`, the
// in-process executor behind the Zero runner's QuickJS host function, down to
// the bytes: same operation shape, same JSON encoding of every MySQL type, same
// error codes, same limits, same transaction semantics, pinned by the
// db-broker.test.ts corpus. Two engines that disagree about how a DECIMAL or an
// unsigned BIGINT becomes JSON diverge silently in production, so the encoder
// here follows the Rust driver's wire behaviour rather than PHP's conveniences.
//
// STATTIC_DB_OPERATION_MAX_BYTES, _PARAM_MAX_COUNT, _TRANSACTION_MAX_STATEMENTS,
// _RESULT_ROWS_MAX and _SESSION_PIN are the shared half of that contract and
// arrive generated from db.rs (finalizer-protocol.generated.php). The rest of
// the tuning below is this engine's alone.

// Server-side prepared statements are a finite server resource
// (`max_prepared_stmt_count` is global, not per-connection), so the
// per-connection cache is bounded and evicts least-recently-used entries by
// closing them. A capsule store issues a small, repetitive statement set, so a
// modest cache runs at a high hit rate.
const STATTIC_DB_STMT_CACHE_MAX = 32;

// A capsule's whole schema in one artifact. Well past what the compiler emits
// for any real store, and low enough that a malformed artifact stops here
// rather than after a few thousand DDL round trips.
const STATTIC_DB_MIGRATION_STATEMENTS_MAX = 256;

/**
 * Process-wide broker state, returned by reference so callers mutate the one
 * copy. Everything that outlives a single operation lives here: the pooled
 * link, its statement cache, the open-transaction flag and the metrics.
 *
 * @return array<string,mixed>
 */
function &_stattic_db_broker_state(): array
{
    static $state = [
        'link' => null,
        'stmts' => [],
        'txn' => false,
        'shutdown' => false,
        'operations' => 0,
        'metrics' => [
            'operations' => 0,
            'connectMs' => 0.0,
            'queryMs' => 0.0,
            'executeMs' => 0.0,
            'stmtCacheHits' => 0,
            'stmtCacheMisses' => 0,
        ],
        'url' => null,
        'source' => null,
        'capabilities' => null,
        'readDeadlineMs' => null,
    ];

    return $state;
}

/**
 * Bind the database URL for this process. Callers pass the application-declared
 * `DATABASE_URL` they already resolve through `_stattic_zero_runner_base_env()`;
 * leaving it unbound falls back to the same reserved configuration names db.rs
 * reads, under the same fail-closed rule: ambient configuration never steers a
 * broker connection.
 */
function _stattic_db_broker_bind(?string $url, ?string $source = null): void
{
    $state = &_stattic_db_broker_state();
    $bound = $url === null ? null : trim($url);
    if ($bound !== $state['url'] || $source !== $state['source']) {
        _stattic_db_broker_close();
    }
    $state['url'] = $bound;
    $state['source'] = $source;
}

/**
 * Restrict this process to a subset of the broker capabilities
 * (STATTIC_RUNTIME_BROKER_CAPABILITIES). Unset means both database
 * capabilities are granted, which is the all-or-nothing grant db.rs implements
 * by installing or withholding the host function entirely.
 *
 * @param list<string>|null $capabilities
 */
function _stattic_db_broker_grant(?array $capabilities): void
{
    $state = &_stattic_db_broker_state();
    $state['capabilities'] = $capabilities;
}

/**
 * Bound both server statement execution and the client socket read. This is a
 * trusted caller policy, not part of the tenant operation frame: allowing SQL
 * to choose its own timeout would make the resource bound optional.
 */
function _stattic_db_broker_set_read_deadline_ms(?int $deadlineMs): void
{
    $state = &_stattic_db_broker_state();
    $normalized = $deadlineMs === null ? null : max(1, $deadlineMs);
    if ($normalized !== $state['readDeadlineMs'] && $state['link'] instanceof mysqli) {
        // mysqli socket options are fixed before real_connect(). Reacquire the
        // persistent handle rather than pretending an existing socket changed.
        _stattic_db_broker_close();
    }
    $state['readDeadlineMs'] = $normalized;
}

/**
 * Execute one operation and return the response JSON. The analogue of db.rs
 * `handle_db_operation`: raw JSON text in, one JSON object out, never a thrown
 * exception, byte-identical output for every input the two engines share.
 */
function _stattic_db_broker_execute(string $raw): string
{
    $outcome = _stattic_db_broker_operation($raw);
    if ($outcome['ok']) {
        return $outcome['json'];
    }

    return '{"code":' . _stattic_db_broker_json_string($outcome['code'])
        . ',"message":' . _stattic_db_broker_json_string($outcome['message'])
        . ',"ok":false}';
}

/**
 * Apply a version's compiled Zero migrations, in order, on one connection.
 *
 * PHP owns this outright, so the artifact is validated here before a single
 * statement runs: the format and artifact kind the compiler stamps, a bounded
 * statement count, and no empty or NUL-bearing SQL. The statements are DDL, so
 * they run as plain queries rather than through the prepared-statement path:
 * MySQL cannot prepare most DDL, and there are no parameters to bind.
 *
 * Replay is expected. `generate.php` applies a version's migrations on every
 * publish, and MySQL has no `ADD COLUMN IF NOT EXISTS`. A statement that only
 * restates schema the last publish created reports duplicate column (1060),
 * duplicate key (1061) or missing key (1091), and each is tolerated only for
 * the statement shape that can legitimately produce it. Every other driver
 * error stops the run: a half-migrated schema must fail the publish rather
 * than serve.
 *
 * @return array{ok:true}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_apply_migrations(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return _stattic_db_broker_error('zero_migration_artifact_unreadable', 'Zero migration artifact could not be read.');
    }
    $artifact = json_decode($raw, true);
    if (
        !is_array($artifact)
        || ($artifact['format'] ?? null) !== STATTIC_RUNTIME_ZERO_MIGRATIONS_FORMAT
        || ($artifact['artifact_kind'] ?? null) !== 'zero_migrations'
        || !is_array($artifact['statements'] ?? null)
    ) {
        return _stattic_db_broker_error('zero_migration_artifact_invalid', 'Zero migration artifact format is unsupported.');
    }
    $statements = $artifact['statements'];
    if (count($statements) > STATTIC_DB_MIGRATION_STATEMENTS_MAX) {
        return _stattic_db_broker_error('zero_migration_artifact_invalid', 'Zero migration artifact has too many statements.');
    }

    $link = _stattic_db_broker_connection();
    if (!$link instanceof mysqli) {
        return $link;
    }
    foreach ($statements as $statement) {
        if (!is_string($statement)) {
            return _stattic_db_broker_error('zero_migration_statement_invalid', 'Zero migration statement is invalid.');
        }
        $sql = _stattic_db_broker_trim($statement);
        if ($sql === '' || str_contains($sql, "\0")) {
            return _stattic_db_broker_error('zero_migration_statement_invalid', 'Zero migration statement is invalid.');
        }
        if ($link->query($sql) !== false) {
            continue;
        }
        if (!_stattic_db_broker_migration_replay($sql, $link->errno)) {
            return _stattic_db_broker_driver_error('zero_migration_failed', $link);
        }
    }

    return ['ok' => true];
}

/** Whether this driver error is this statement shape's own "already applied". */
function _stattic_db_broker_migration_replay(string $sql, int $errno): bool
{
    return (str_starts_with($sql, 'CREATE INDEX ') && $errno === 1061)
        || (str_starts_with($sql, 'DROP INDEX ') && $errno === 1091)
        || (str_starts_with($sql, 'ALTER TABLE ') && $errno === 1060);
}

/**
 * Take and clear the accumulated metrics, or null when nothing ran. The same
 * "absent unless used" shape db.rs attaches to a runner response.
 *
 * @return array<string,float|int>|null
 */
function _stattic_db_broker_take_metrics(): ?array
{
    $state = &_stattic_db_broker_state();
    if ($state['metrics']['operations'] === 0) {
        return null;
    }
    $metrics = $state['metrics'];
    _stattic_db_broker_reset_metrics();

    return $metrics;
}

function _stattic_db_broker_reset_metrics(): void
{
    $state = &_stattic_db_broker_state();
    $state['metrics'] = [
        'operations' => 0,
        'connectMs' => 0.0,
        'queryMs' => 0.0,
        'executeMs' => 0.0,
        'stmtCacheHits' => 0,
        'stmtCacheMisses' => 0,
    ];
    $state['operations'] = 0;
}

/**
 * Roll back a transaction the tenant opened and never finished. Called after
 * every invocation and again from the shutdown hook, mirroring the two places
 * db.rs calls `rollback_open_transaction`.
 */
function _stattic_db_broker_rollback_open_transaction(): void
{
    $state = &_stattic_db_broker_state();
    if (!$state['txn']) {
        return;
    }
    $state['txn'] = false;
    if ($state['link'] instanceof mysqli) {
        $state['link']->query('ROLLBACK');
    }
}

/**
 * Drop the pooled link and its statement cache. The link goes back to PHP's
 * persistent pool, where the next acquisition's implicit reset scrubs whatever
 * this one left behind.
 */
function _stattic_db_broker_close(): void
{
    $state = &_stattic_db_broker_state();
    foreach ($state['stmts'] as $stmt) {
        $stmt->close();
    }
    $state['stmts'] = [];
    if ($state['link'] instanceof mysqli) {
        _stattic_db_broker_rollback_open_transaction();
        $state['link']->close();
    }
    $state['link'] = null;
    $state['txn'] = false;
}

// --- operation engine ----------------------------------------------------------------

/**
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_operation(string $raw): array
{
    if (strlen($raw) > STATTIC_DB_OPERATION_MAX_BYTES) {
        return _stattic_db_broker_error('zero_db_operation_too_large', 'Zero DB operation exceeded the request size limit.');
    }

    // Decoded to objects rather than associative arrays: PHP renders both `{}`
    // and `[]` as an empty array, and the two differ here. `{}` is a valid
    // operation with no SQL, `[]` is a parse failure. JSON_BIGINT_AS_STRING
    // keeps integer parameters past PHP's signed range exact instead of
    // rounding them through a float.
    $operation = json_decode($raw, false, 512, JSON_BIGINT_AS_STRING);
    if (!$operation instanceof stdClass) {
        return _stattic_db_broker_error('zero_db_operation_invalid', 'Zero DB operation could not be parsed.');
    }

    $mode = $operation->mode ?? null;
    if ($mode !== null && !is_string($mode)) {
        return _stattic_db_broker_error('zero_db_operation_invalid', 'Zero DB operation could not be parsed.');
    }

    if ($mode === 'transaction_begin') {
        return _stattic_db_broker_begin_transaction();
    }
    if ($mode === 'transaction_commit') {
        return _stattic_db_broker_finish_transaction('COMMIT');
    }
    if ($mode === 'transaction_rollback') {
        return _stattic_db_broker_finish_transaction('ROLLBACK');
    }

    if ($mode === 'transaction') {
        return _stattic_db_broker_transaction($operation);
    }

    $statement = _stattic_db_broker_statement_shape($operation, false);
    if (isset($statement['code'])) {
        return $statement;
    }

    return _stattic_db_broker_run_statement($statement);
}

/**
 * Validate one statement-shaped object the way serde validates `DbStatement`:
 * `sql` is a required string on batch members and an optional one at the top
 * level, `params` is an array when present, `mode` is a string or null. A type
 * mismatch is a parse failure, not a downstream error.
 *
 * @return array{sql:string,params:list<mixed>,mode:string|null}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_statement_shape(stdClass $source, bool $sqlRequired): array
{
    $invalid = _stattic_db_broker_error('zero_db_operation_invalid', 'Zero DB operation could not be parsed.');

    $sql = $source->sql ?? null;
    if ($sqlRequired) {
        if (!is_string($sql)) {
            return $invalid;
        }
    } elseif ($sql !== null && !is_string($sql)) {
        return $invalid;
    }

    // `#[serde(default)]` fills in an absent list, but an explicit null is a
    // type error there just as it is here.
    $params = $source->params ?? [];
    if (!is_array($params) || !array_is_list($params)) {
        return $invalid;
    }

    $mode = $source->mode ?? null;
    if ($mode !== null && !is_string($mode)) {
        return $invalid;
    }

    return ['sql' => is_string($sql) ? $sql : '', 'params' => $params, 'mode' => $mode];
}

/**
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_transaction(stdClass $operation): array
{
    $state = &_stattic_db_broker_state();
    if ($state['txn']) {
        return _stattic_db_broker_error('zero_db_transaction_active', 'A Zero DB transaction is already active.');
    }

    $statements = $operation->statements ?? [];
    if (!is_array($statements) || !array_is_list($statements)) {
        return _stattic_db_broker_error('zero_db_operation_invalid', 'Zero DB operation could not be parsed.');
    }
    if ($statements === [] || count($statements) > STATTIC_DB_TRANSACTION_MAX_STATEMENTS) {
        return _stattic_db_broker_error('zero_db_transaction_invalid', 'Zero DB transaction statements are invalid.');
    }

    $shapes = [];
    foreach ($statements as $statement) {
        if (!$statement instanceof stdClass) {
            return _stattic_db_broker_error('zero_db_operation_invalid', 'Zero DB operation could not be parsed.');
        }
        $shape = _stattic_db_broker_statement_shape($statement, true);
        if (isset($shape['code'])) {
            return $shape;
        }
        $shapes[] = $shape;
    }

    $connection = _stattic_db_broker_open_transaction();
    if (is_array($connection)) {
        return $connection;
    }

    $results = [];
    foreach ($shapes as $shape) {
        $outcome = _stattic_db_broker_run_statement($shape);
        if (!$outcome['ok']) {
            $connection->query('ROLLBACK');
            return $outcome;
        }
        $results[] = $outcome['json'];
    }

    if ($connection->query('COMMIT') === false) {
        return _stattic_db_broker_driver_error('zero_db_transaction_commit_failed', $connection);
    }

    return ['ok' => true, 'json' => '{"ok":true,"results":[' . implode(',', $results) . ']}'];
}

/**
 * The one way a transaction starts, batched or held: nothing else may be open,
 * the connection has to be live, and the server has to accept the START.
 *
 * @return mysqli|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_open_transaction(): mysqli|array
{
    $state = &_stattic_db_broker_state();
    if ($state['txn']) {
        return _stattic_db_broker_error('zero_db_transaction_active', 'A Zero DB transaction is already active.');
    }
    $connection = _stattic_db_broker_connection();
    if (is_array($connection)) {
        return $connection;
    }
    if ($connection->query('START TRANSACTION') === false) {
        return _stattic_db_broker_driver_error('zero_db_transaction_start_failed', $connection);
    }

    return $connection;
}

/**
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_begin_transaction(): array
{
    $connection = _stattic_db_broker_open_transaction();
    if (is_array($connection)) {
        return $connection;
    }
    $state = &_stattic_db_broker_state();
    $state['txn'] = true;

    return ['ok' => true, 'json' => '{"ok":true}'];
}

/**
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_finish_transaction(string $command): array
{
    $state = &_stattic_db_broker_state();
    if (!$state['txn']) {
        return _stattic_db_broker_error('zero_db_transaction_missing', 'No Zero DB transaction is active.');
    }
    $state['txn'] = false;
    $connection = $state['link'];
    if (!$connection instanceof mysqli) {
        return _stattic_db_broker_error('zero_db_transaction_missing', 'No Zero DB transaction is active.');
    }
    if ($connection->query($command) === false) {
        $code = $command === 'COMMIT' ? 'zero_db_transaction_commit_failed' : 'zero_db_transaction_rollback_failed';
        return _stattic_db_broker_driver_error($code, $connection);
    }

    return ['ok' => true, 'json' => '{"ok":true}'];
}

/**
 * Authorization follows the SQL, never the caller-supplied result-shape hint.
 * Ported clause for clause from db.rs `statement_is_mutation`. Anything not
 * positively recognized as a read fails closed as a mutation. The local tier
 * never notices (its grant is all-or-nothing); this classifier exists for the
 * relay's narrowed read-only credential.
 */
function _stattic_db_broker_statement_is_mutation(string $sql): bool
{
    $keyword = _stattic_db_broker_leading_sql_keyword($sql);
    if ($keyword === null) {
        return true;
    }
    [$word, $rest] = $keyword;

    if (strcasecmp($word, 'SELECT') === 0 || strcasecmp($word, 'SHOW') === 0) {
        return _stattic_db_broker_contains_sql_keyword($sql, 'OUTFILE')
            || _stattic_db_broker_contains_sql_keyword($sql, 'DUMPFILE');
    }

    if (strcasecmp($word, 'EXPLAIN') === 0 || strcasecmp($word, 'DESCRIBE') === 0 || strcasecmp($word, 'DESC') === 0) {
        $next = _stattic_db_broker_leading_sql_keyword($rest);
        return $next !== null && strcasecmp($next[0], 'ANALYZE') === 0;
    }

    return true;
}

/**
 * Finds the first SQL keyword after syntax MySQL skips before the statement.
 * Executable version comments (the "slash star bang" form) remain unrecognized
 * and therefore classify as writes.
 *
 * @return array{0:string,1:string}|null keyword and the text after it
 */
function _stattic_db_broker_leading_sql_keyword(string $sql): ?array
{
    while (true) {
        // \f is in the trim set because the Rust side trims it too.
        $sql = ltrim($sql, " \t\n\r\0\x0B\f");
        if (str_starts_with($sql, '(')) {
            $sql = substr($sql, 1);
            continue;
        }
        if (str_starts_with($sql, '#')) {
            $sql = _stattic_db_broker_skip_sql_line(substr($sql, 1));
            continue;
        }
        if (str_starts_with($sql, '--')) {
            $rest = substr($sql, 2);
            // MySQL only treats `--` as a comment when whitespace follows it.
            if ($rest === '' || strspn($rest, " \t\n\r\x0B\f") === 0) {
                return null;
            }
            $sql = _stattic_db_broker_skip_sql_line($rest);
            continue;
        }
        if (str_starts_with($sql, '/*')) {
            $rest = substr($sql, 2);
            if (str_starts_with($rest, '!')) {
                return null;
            }
            $end = strpos($rest, '*/');
            if ($end === false) {
                return null;
            }
            $sql = substr($rest, $end + 2);
            continue;
        }
        break;
    }

    if ($sql === '' || (!ctype_alpha($sql[0]) && $sql[0] !== '_')) {
        return null;
    }
    $length = strspn($sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$');

    return [substr($sql, 0, $length), substr($sql, $length)];
}

function _stattic_db_broker_skip_sql_line(string $sql): string
{
    $end = strcspn($sql, "\n\r");

    return $end >= strlen($sql) ? '' : substr($sql, $end);
}

function _stattic_db_broker_contains_sql_keyword(string $sql, string $expected): bool
{
    foreach (preg_split('/[^A-Za-z0-9_$]+/', $sql) ?: [] as $token) {
        if (strcasecmp($token, $expected) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{sql:string,params:list<mixed>,mode:string|null} $statement
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_run_statement(array $statement): array
{
    $sql = _stattic_db_broker_trim($statement['sql']);
    if ($sql === '' || str_contains($sql, "\0")) {
        return _stattic_db_broker_error('zero_db_sql_invalid', 'Zero DB operation SQL is invalid.');
    }
    if (count($statement['params']) > STATTIC_DB_PARAM_MAX_COUNT) {
        return _stattic_db_broker_error('zero_db_too_many_params', 'Zero DB operation has too many parameters.');
    }

    $bindings = _stattic_db_broker_bindings($statement['params']);
    if (isset($bindings['code'])) {
        return $bindings;
    }

    $state = &_stattic_db_broker_state();
    $budget = _stattic_db_broker_operation_budget();
    if ($state['operations'] >= $budget) {
        return _stattic_db_broker_error(
            'zero_db_operation_budget_exhausted',
            'Zero DB operation budget for this invocation is exhausted.'
        );
    }
    $state['operations'] += 1;

    $mutation = $statement['mode'] === 'execute' || $statement['mode'] === 'exec' || $statement['mode'] === 'mutation';
    // $mutation steers the result shape, error codes and metrics (the caller's
    // declared intent, db.rs `execute_shape`); the grant check follows the SQL
    // itself, so a write disguised as a query needs db.write.
    if (!_stattic_db_broker_capability_granted(_stattic_db_broker_statement_is_mutation($sql) ? 'db.write' : 'db.read')) {
        return _stattic_db_broker_error('zero_db_capability_denied', 'Zero DB capability is not granted.');
    }

    $connection = _stattic_db_broker_connection();
    if (is_array($connection)) {
        return $connection;
    }
    $cacheKey = _stattic_db_broker_cache_key($sql, $bindings['types']);
    $prepared = _stattic_db_broker_statement($connection, $sql, $cacheKey, $mutation);
    if (is_array($prepared)) {
        return $prepared;
    }

    $state['metrics']['operations'] += 1;
    $expected = $prepared->param_count;
    $supplied = count($bindings['values']);
    if ($expected !== $supplied) {
        // The Rust driver refuses the same mismatch before touching the wire,
        // in the same words. The counts are read before eviction, which closes
        // the statement and takes its metadata with it.
        _stattic_db_broker_evict($cacheKey);
        return _stattic_db_broker_error(
            $mutation ? 'zero_db_execute_failed' : 'zero_db_query_failed',
            sprintf('DriverError { Statement takes %d parameters but %d was supplied }', $expected, $supplied)
        );
    }

    $started = hrtime(true);
    $bound = _stattic_db_broker_bind_params($prepared, $bindings['types'], $bindings['values']);
    $executed = $bound && $prepared->execute();
    $elapsed = (hrtime(true) - $started) / 1e6;
    $state['metrics'][$mutation ? 'executeMs' : 'queryMs'] += $elapsed;

    if (!$executed) {
        // The driver detail is read before eviction: closing the statement
        // first would take its errno with it.
        $failure = _stattic_db_broker_driver_error($mutation ? 'zero_db_execute_failed' : 'zero_db_query_failed', $connection, $prepared);
        // A statement that failed mid-execute can hold a half-consumed result,
        // so it never goes back into the cache.
        _stattic_db_broker_evict($cacheKey);
        return $failure;
    }

    if ($mutation) {
        $affected = _stattic_db_broker_unsigned_literal($prepared->affected_rows);
        $insertId = _stattic_db_broker_unsigned_literal($prepared->insert_id);
        // A mutation-mode statement that returned a result set leaves the
        // statement unusable until the set is drained.
        if ($prepared->field_count > 0) {
            $discard = $prepared->get_result();
            if ($discard instanceof mysqli_result) {
                $discard->free();
            }
        }

        return ['ok' => true, 'json' => '{"affectedRows":' . $affected . ',"lastInsertId":' . $insertId . ',"ok":true}'];
    }

    return _stattic_db_broker_rows($prepared, $cacheKey);
}

/**
 * Encode a result set incrementally. Both caps are enforced while encoding so a
 * runaway SELECT is refused with a named error instead of being materialised
 * first and killing the worker on the memory limit.
 *
 * @return array{ok:true,json:string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_rows(mysqli_stmt $prepared, string $cacheKey): array
{
    $result = $prepared->get_result();
    if (!$result instanceof mysqli_result) {
        // No result set at all (a DDL or DML statement run in query mode):
        // db.rs reports the same empty row list.
        return ['ok' => true, 'json' => '{"ok":true,"rows":[]}'];
    }

    $fields = $result->fetch_fields();
    // Column names are sorted bytewise and duplicates collapse to the last
    // occurrence, because db.rs collects a row into a BTreeMap keyed by name.
    $order = range(0, count($fields) - 1);
    usort($order, static function (int $left, int $right) use ($fields): int {
        $byName = strcmp($fields[$left]->name, $fields[$right]->name);
        return $byName !== 0 ? $byName : $left <=> $right;
    });
    $emit = [];
    foreach ($order as $index) {
        $emit[$fields[$index]->name] = $index;
    }

    $rowsMax = _stattic_db_broker_rows_max();
    $bytesMax = _stattic_db_broker_bytes_max();
    $encoded = [];
    $bytes = 0;
    $count = 0;
    while (($row = $result->fetch_row()) !== null) {
        $count += 1;
        if ($count > $rowsMax) {
            $result->free();
            _stattic_db_broker_evict($cacheKey);
            return _stattic_db_broker_error('zero_db_result_too_many_rows', 'Zero DB result set exceeded the row limit.');
        }
        $parts = [];
        foreach ($emit as $name => $index) {
            $parts[] = _stattic_db_broker_json_string((string) $name) . ':'
                . _stattic_db_broker_column_json($row[$index], $fields[$index]);
        }
        $json = '{' . implode(',', $parts) . '}';
        $bytes += strlen($json) + 1;
        if ($bytes > $bytesMax) {
            $result->free();
            _stattic_db_broker_evict($cacheKey);
            return _stattic_db_broker_error('zero_db_result_too_large', 'Zero DB result set exceeded the encoded size limit.');
        }
        $encoded[] = $json;
    }
    $result->free();

    return ['ok' => true, 'json' => '{"ok":true,"rows":[' . implode(',', $encoded) . ']}'];
}

/**
 * Trim exactly what Rust's `str::trim` trims: Unicode White_Space. PHP's own
 * trim() would also eat NUL, the one byte the SQL guard exists to catch. A
 * trailing NUL has to survive the trim to be rejected.
 */
function _stattic_db_broker_trim(string $value): string
{
    $class = '[\x{09}-\x{0D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
    $trimmed = preg_replace('/^' . $class . '+|' . $class . '+$/u', '', $value);

    return is_string($trimmed) ? $trimmed : $value;
}

// --- value encoding ------------------------------------------------------------------

/**
 * One column value as a JSON fragment. The mapping composes two things that
 * must not drift: how the MySQL binary protocol types a column, and how db.rs
 * `mysql_value_to_json` turns that type into JSON. mysqlnd pre-converts some of
 * those (BIT to an integer, YEAR to a string), undone here before encoding.
 */
function _stattic_db_broker_column_json(mixed $value, object $field): string
{
    if ($value === null) {
        return 'null';
    }

    switch ($field->type) {
        case MYSQLI_TYPE_BIT:
            // Rust reads BIT as the raw big-endian bytes MySQL sends
            // (ceil(bits/8) of them) and encodes those as a string; mysqlnd
            // hands over the integer it decoded from exactly those bytes.
            return _stattic_db_broker_bytes_json(
                _stattic_db_broker_bit_bytes($value, (int) $field->length)
            );

        case MYSQLI_TYPE_YEAR:
            // mysqlnd returns YEAR as a string; the binary protocol carries it
            // as an unsigned short, which Rust encodes as a JSON number.
            return _stattic_db_broker_integer_literal($value);

        case MYSQLI_TYPE_TINY:
        case MYSQLI_TYPE_SHORT:
        case MYSQLI_TYPE_INT24:
        case MYSQLI_TYPE_LONG:
        case MYSQLI_TYPE_LONGLONG:
            return _stattic_db_broker_integer_literal($value);

        case MYSQLI_TYPE_FLOAT:
            // mysqlnd rounds a 4-byte float to a short decimal on the way into
            // a PHP float, so 0.1f arrives as 0.1 rather than the 0.1000000015
            // the wire actually carried. Rust widens the raw f32 to f64, so the
            // value is put back through a 4-byte float to recover those bits.
            return _stattic_db_broker_float_literal(
                (float) (unpack('g', pack('g', (float) $value))[1] ?? $value)
            );

        case MYSQLI_TYPE_DOUBLE:
            return _stattic_db_broker_float_literal((float) $value);

        case MYSQLI_TYPE_DATE:
        case MYSQLI_TYPE_NEWDATE:
        case MYSQLI_TYPE_DATETIME:
        case MYSQLI_TYPE_TIMESTAMP:
            return _stattic_db_broker_json_string(_stattic_db_broker_datetime((string) $value));

        case MYSQLI_TYPE_TIME:
            return _stattic_db_broker_json_string(_stattic_db_broker_time((string) $value));

        default:
            // Everything else reaches Rust as `Bytes`: CHAR, VARCHAR, BLOB,
            // TEXT, DECIMAL, ENUM, SET, JSON, GEOMETRY.
            return _stattic_db_broker_bytes_json((string) $value);
    }
}

/**
 * Bytes become a JSON string when they are valid UTF-8, standard base64
 * otherwise. The same fallback db.rs takes when `String::from_utf8` fails.
 */
function _stattic_db_broker_bytes_json(string $bytes): string
{
    if ($bytes !== '' && preg_match('//u', $bytes) !== 1) {
        return _stattic_db_broker_json_string(base64_encode($bytes));
    }

    return _stattic_db_broker_json_string($bytes);
}

/**
 * Rebuild the wire bytes of a BIT(M) column from the value mysqlnd decoded.
 * MySQL sends ceil(M/8) big-endian bytes, so a BIT(1) holding 1 is one 0x01
 * byte and a BIT(17) holding 65537 is 0x01 0x00 0x01.
 */
function _stattic_db_broker_bit_bytes(mixed $value, int $bits): string
{
    $width = max(1, intdiv(max($bits, 1) + 7, 8));
    if (is_string($value)) {
        // BIT(64) above PHP's signed integer range arrives as decimal digits.
        $bytes = '';
        $digits = ltrim($value, '0');
        if ($digits === '') {
            return str_repeat("\0", $width);
        }
        while ($digits !== '' && $digits !== '0') {
            $remainder = 0;
            $quotient = '';
            $length = strlen($digits);
            for ($i = 0; $i < $length; $i++) {
                $current = $remainder * 10 + (int) $digits[$i];
                $quotient .= intdiv($current, 256);
                $remainder = $current % 256;
            }
            $bytes = chr($remainder) . $bytes;
            $digits = ltrim($quotient, '0');
        }

        return str_pad($bytes, $width, "\0", STR_PAD_LEFT);
    }

    $number = (int) $value;
    $bytes = '';
    for ($i = 0; $i < $width; $i++) {
        $bytes = chr($number & 0xFF) . $bytes;
        $number >>= 8;
    }

    return $bytes;
}

/**
 * An integer JSON literal. mysqlnd returns unsigned BIGINT above PHP's signed
 * integer range as a decimal string; Rust encodes it as a full-width JSON
 * number, so the digits are emitted verbatim rather than routed through a PHP
 * float.
 */
function _stattic_db_broker_integer_literal(mixed $value): string
{
    if (is_int($value)) {
        return (string) $value;
    }
    $digits = (string) $value;
    if (preg_match('/^-?\d+$/', $digits) === 1) {
        return $digits;
    }

    return '0';
}

/**
 * Format an unsigned 64-bit driver counter (affected rows, insert id) the way
 * Rust prints a u64. mysqli widens values above PHP's integer range to strings
 * and reports "no rows" as -1, which on the wire was u64::MAX.
 */
function _stattic_db_broker_unsigned_literal(mixed $value): string
{
    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        return $value;
    }
    $number = (int) $value;
    if ($number >= 0) {
        return (string) $number;
    }

    // Two's-complement reinterpretation, printed without a bignum extension.
    return _stattic_db_broker_u64_string($number);
}

/** Decimal form of a negative PHP integer reinterpreted as unsigned 64-bit. */
function _stattic_db_broker_u64_string(int $number): string
{
    $high = ($number >> 32) & 0xFFFFFFFF;
    $low = $number & 0xFFFFFFFF;
    // 2^32 * high + low by long multiplication, so no step exceeds 2^53.
    $digits = '0';
    foreach ([($high >> 16) & 0xFFFF, $high & 0xFFFF, ($low >> 16) & 0xFFFF, $low & 0xFFFF] as $chunk) {
        $digits = _stattic_db_broker_decimal_mul_add($digits, 65536, $chunk);
    }

    return $digits;
}

/** $digits * $multiplier + $addend on a decimal string. */
function _stattic_db_broker_decimal_mul_add(string $digits, int $multiplier, int $addend): string
{
    $out = '';
    $carry = $addend;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $current = ((int) $digits[$i]) * $multiplier + $carry;
        $out = ((string) ($current % 10)) . $out;
        $carry = intdiv($current, 10);
    }
    while ($carry > 0) {
        $out = ((string) ($carry % 10)) . $out;
        $carry = intdiv($carry, 10);
    }

    return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
}

/**
 * A float JSON literal in serde_json's exact textual form. PHP and serde_json
 * agree on the shortest round-tripping digits but not on how to lay them out:
 * PHP prints 1.0 as `1`, -0.0 as `-0` and 1e25 as `1.0e+25`, where serde_json
 * prints `1.0`, `-0.0` and `1e+25`. The digits are reused and the layout is
 * redone here: fixed notation for a decimal exponent in -5..=15, scientific
 * outside it, always at least one fractional digit in fixed notation and never
 * a trailing `.0` in scientific notation.
 */
function _stattic_db_broker_float_literal(float $value): string
{
    // serde_json cannot represent a non-finite float and emits null instead.
    if (is_nan($value) || is_infinite($value)) {
        return 'null';
    }

    $printed = json_encode($value);
    if (!is_string($printed)) {
        return 'null';
    }
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $printed, $match) !== 1) {
        return 'null';
    }

    $sign = $match[1];
    $digits = $match[2] . ($match[3] ?? '');
    $pointPos = strlen($match[2]);
    $exponent = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0;

    $leading = strlen($digits) - strlen(ltrim($digits, '0'));
    $digits = ltrim($digits, '0');
    $pointPos -= $leading;
    if ($digits === '') {
        return $sign . '0.0';
    }
    $digits = rtrim($digits, '0');
    if ($digits === '') {
        $digits = '0';
    }
    $decExp = $pointPos - 1 + $exponent;
    $length = strlen($digits);

    if ($decExp < -5 || $decExp > 15) {
        $mantissa = $length === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
        return $sign . $mantissa . 'e' . ($decExp >= 0 ? '+' : '-') . abs($decExp);
    }
    if ($decExp < 0) {
        return $sign . '0.' . str_repeat('0', -$decExp - 1) . $digits;
    }
    if ($decExp >= $length - 1) {
        return $sign . $digits . str_repeat('0', $decExp - $length + 1) . '.0';
    }

    return $sign . substr($digits, 0, $decExp + 1) . '.' . substr($digits, $decExp + 1);
}

/**
 * `YYYY-MM-DDTHH:MM:SS.uuuuuuZ`, the shape db.rs formats a `Date` value into.
 * mysqlnd renders only as many fractional digits as the column declares, so the
 * fraction is right-padded to the six digits the wire value carries.
 */
function _stattic_db_broker_datetime(string $value): string
{
    if (preg_match('/^(-?\d+)-(\d+)-(\d+)(?:[ T](\d+):(\d+):(\d+)(?:\.(\d+))?)?/', $value, $match) !== 1) {
        return $value;
    }
    $micros = isset($match[7]) ? substr(str_pad($match[7], 6, '0'), 0, 6) : '000000';

    return sprintf(
        '%04d-%02d-%02dT%02d:%02d:%02d.%sZ',
        (int) $match[1],
        (int) $match[2],
        (int) $match[3],
        (int) ($match[4] ?? 0),
        (int) ($match[5] ?? 0),
        (int) ($match[6] ?? 0),
        $micros
    );
}

/**
 * `[-]D HH:MM:SS.uuuuuu`, the shape db.rs formats a `Time` value into. MySQL
 * sends TIME as a day count plus an hour remainder, which mysqlnd flattens back
 * into total hours, so the split is redone here.
 */
function _stattic_db_broker_time(string $value): string
{
    if (preg_match('/^(-)?(\d+):(\d+):(\d+)(?:\.(\d+))?/', $value, $match) !== 1) {
        return $value;
    }
    $hours = (int) $match[2];
    $micros = isset($match[5]) ? substr(str_pad($match[5], 6, '0'), 0, 6) : '000000';

    return sprintf(
        '%s%d %02d:%02d:%02d.%s',
        $match[1] !== '' ? '-' : '',
        intdiv($hours, 24),
        $hours % 24,
        (int) $match[3],
        (int) $match[4],
        $micros
    );
}

/**
 * A JSON string literal. The flag set is what makes PHP's encoder agree with
 * serde_json byte for byte: slashes raw, non-ASCII raw UTF-8, and U+2028/U+2029
 * raw rather than escaped.
 */
function _stattic_db_broker_json_string(string $value): string
{
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
    );

    return is_string($encoded) ? $encoded : '""';
}

// --- parameters ----------------------------------------------------------------------

/**
 * Turn decoded JSON parameters into a mysqli bind spec. The parameter's MySQL
 * wire type is observable, since `SELECT ?` echoes it back as the column type,
 * so integers, floats and strings are bound as themselves rather than all as
 * strings the way `mysqli_stmt::execute($params)` would.
 *
 * @param list<mixed> $params
 * @return array{types:string,values:list<mixed>}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_bindings(array $params): array
{
    $types = '';
    $values = [];
    foreach ($params as $param) {
        if ($param === null) {
            $types .= 's';
            $values[] = null;
            continue;
        }
        if (is_bool($param)) {
            $types .= 'i';
            $values[] = $param ? 1 : 0;
            continue;
        }
        if (is_int($param)) {
            $types .= 'i';
            $values[] = $param;
            continue;
        }
        if (is_float($param)) {
            $types .= 'd';
            $values[] = $param;
            continue;
        }
        if (is_string($param)) {
            $types .= 's';
            $values[] = $param;
            continue;
        }

        return _stattic_db_broker_error('zero_db_param_invalid', 'Zero DB parameters must be scalar JSON values.');
    }

    return ['types' => $types, 'values' => $values];
}

/**
 * @param list<mixed> $values
 */
function _stattic_db_broker_bind_params(mysqli_stmt $stmt, string $types, array $values): bool
{
    if ($types === '') {
        return true;
    }
    // bind_param takes its arguments by reference, so the values are bound
    // through references into a local array rather than spread directly.
    $refs = [$types];
    foreach (array_keys($values) as $index) {
        $refs[] = &$values[$index];
    }

    try {
        return (bool) call_user_func_array([$stmt, 'bind_param'], $refs);
    } catch (Throwable $error) {
        error_log('spacefast db broker bind failed type=' . get_debug_type($error));
        return false;
    }
}

// --- connection ----------------------------------------------------------------------

/**
 * The pooled link for this process, pinned and ready. Returns an error array
 * rather than throwing, so a connection failure becomes an ordinary operation
 * error frame.
 *
 * @return mysqli|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_connection(): mysqli|array
{
    $state = &_stattic_db_broker_state();
    if ($state['link'] instanceof mysqli) {
        return $state['link'];
    }

    // mysqli throws by default since PHP 8.1. Every failure here has to become
    // an error value instead: an escaping mysqli_sql_exception carries driver
    // text into a stack trace, where the DSN must never appear. This is the
    // only mysqli entry point in the engine, so owning the process-global
    // reporting mode is safe.
    mysqli_report(MYSQLI_REPORT_OFF);

    $dsn = _stattic_db_broker_dsn();
    if (isset($dsn['code'])) {
        return $dsn;
    }

    $started = hrtime(true);
    try {
        // The `p:` prefix keeps the socket open across requests in this worker.
        // PHP does an implicit session reset when it hands the link back out,
        // which is what makes an abandoned transaction safe: measured on MySQL
        // 8.4, reuse rolls back open transactions, drops temp tables, releases
        // named locks and clears user variables, prepared statements and every
        // session variable.
        $link = mysqli_init();
        if (!$link instanceof mysqli) {
            return _stattic_db_broker_error('zero_db_connect_failed', 'Zero DB connection could not be established.');
        }
        $readDeadlineMs = is_int($state['readDeadlineMs']) ? $state['readDeadlineMs'] : null;
        if ($readDeadlineMs !== null) {
            $socketSeconds = max(1, (int) ceil($readDeadlineMs / 1000));
            if (
                !$link->options(MYSQLI_OPT_CONNECT_TIMEOUT, $socketSeconds)
                || !$link->options(MYSQLI_OPT_READ_TIMEOUT, $socketSeconds)
            ) {
                $link->close();
                return _stattic_db_broker_error('zero_db_connect_failed', 'Zero DB connection deadline could not be configured.');
            }
        }
        $link->real_connect(
            'p:' . $dsn['host'],
            $dsn['user'],
            $dsn['pass'],
            $dsn['db'],
            $dsn['port'],
            $dsn['socket']
        );
    } catch (Throwable $error) {
        // Driver messages can embed DSN fields. Keep the diagnostic useful
        // without copying credentials into the provider log.
        error_log('spacefast db broker connection failed type=' . get_debug_type($error));
        return _stattic_db_broker_error('zero_db_connect_failed', 'Zero DB connection could not be established.');
    }

    if ($link->connect_errno !== 0) {
        // connect_error carries the host and user; neither leaves this file.
        return _stattic_db_broker_error('zero_db_connect_failed', 'Zero DB connection could not be established.');
    }

    // Because reuse discards session state, the pin is re-applied on every
    // acquisition. One combined SET keeps that at a single round trip.
    $sessionPin = STATTIC_DB_SESSION_PIN;
    if (is_int($state['readDeadlineMs'])) {
        // MySQL's MAX_EXECUTION_TIME is milliseconds and applies to SELECTs.
        // The socket deadline above remains the outer bound for a stalled
        // server or connection that cannot return the timeout response.
        $sessionPin .= ', SESSION max_execution_time = ' . $state['readDeadlineMs'];
    }
    if ($link->query($sessionPin) === false) {
        $link->close();
        return _stattic_db_broker_error('zero_db_connect_failed', 'Zero DB session could not be initialised.');
    }
    if ($link->character_set_name() !== 'utf8mb4') {
        // SET NAMES moved the wire charset without telling the client library;
        // this realigns mysqlnd's own idea of it without another round trip
        // when it already agrees.
        $link->set_charset('utf8mb4');
    }

    $state['link'] = $link;
    $state['stmts'] = [];
    $state['txn'] = false;
    $state['metrics']['connectMs'] += (hrtime(true) - $started) / 1e6;

    if (!$state['shutdown']) {
        $state['shutdown'] = true;
        // A request that dies between START TRANSACTION and COMMIT must not
        // hand a lock-holding connection back to the pool. The shutdown hook
        // covers ordinary aborts; the reuse reset covers the kills that never
        // reach it.
        register_shutdown_function('_stattic_db_broker_rollback_open_transaction');
    }

    return $link;
}

/**
 * A prepared statement for this SQL, from the per-connection LRU cache. A hit
 * turns two round trips per operation into one.
 *
 * @return mysqli_stmt|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_statement(mysqli $link, string $sql, string $key, bool $mutation): mysqli_stmt|array
{
    $state = &_stattic_db_broker_state();
    if (isset($state['stmts'][$key])) {
        $stmt = $state['stmts'][$key];
        unset($state['stmts'][$key]);
        $state['stmts'][$key] = $stmt;
        $state['metrics']['stmtCacheHits'] += 1;
        return $stmt;
    }

    $stmt = $link->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        // Rust prepares inside exec/exec_drop, so a prepare failure surfaces
        // under the calling lane's code rather than a separate one.
        return _stattic_db_broker_driver_error($mutation ? 'zero_db_execute_failed' : 'zero_db_query_failed', $link);
    }
    $state['metrics']['stmtCacheMisses'] += 1;

    $limit = _stattic_db_broker_stmt_cache_max();
    while (count($state['stmts']) >= $limit && $state['stmts'] !== []) {
        $evictedSql = array_key_first($state['stmts']);
        $state['stmts'][$evictedSql]->close();
        unset($state['stmts'][$evictedSql]);
    }
    if ($limit > 0) {
        $state['stmts'][$key] = $stmt;
    }

    return $stmt;
}

/**
 * Statement cache key: the SQL plus the parameter types. MySQL fixes a
 * statement's result metadata to the types of its first execution, so
 * re-binding `SELECT ?` from a double to a string on a reused statement
 * silently decodes the answer as a double. The Rust engine never meets this
 * because it reconnects per operation, so its statement cache is always cold.
 */
function _stattic_db_broker_cache_key(string $sql, string $types): string
{
    return $types . "\0" . $sql;
}

function _stattic_db_broker_evict(string $key): void
{
    $state = &_stattic_db_broker_state();
    if (!isset($state['stmts'][$key])) {
        return;
    }
    $state['stmts'][$key]->close();
    unset($state['stmts'][$key]);
}

/**
 * Resolve the connection parameters. The URL never leaves this function's
 * return value, and the return value never reaches a message, a log line or a
 * metric label.
 *
 * @return array{host:string,user:string,pass:string,db:string,port:int,socket:?string}|array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_dsn(): array
{
    $state = &_stattic_db_broker_state();
    $url = $state['url'];
    $source = $state['source'];
    if ($url === null || trim($url) === '') {
        // Same fail-closed selection as db.rs: only the reserved labelled name
        // can supply a URL, and ambient configuration is not an input.
        $url = _stattic_config_value('SPACEFAST_ZERO_DATABASE_URL');
        $source = _stattic_config_value('SPACEFAST_ZERO_DATABASE_URL_SOURCE');
    }
    if (!is_string($url) || trim($url) === '') {
        return _stattic_db_broker_error(
            'zero_db_url_missing',
            'SPACEFAST_ZERO_DATABASE_URL is required for Zero DB endpoints.'
        );
    }
    $label = is_string($source) ? trim($source) : '';
    if ($label !== '' && $label !== 'application' && $label !== 'provider') {
        return _stattic_db_broker_error(
            'zero_db_url_invalid',
            'SPACEFAST_ZERO_DATABASE_URL_SOURCE must be application or provider.'
        );
    }

    $parts = parse_url(trim($url));
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql' || !is_string($parts['host'] ?? null)) {
        return _stattic_db_broker_error('zero_db_url_invalid', 'Zero DB URL could not be parsed.');
    }

    $socket = null;
    if (is_string($parts['query'] ?? null)) {
        parse_str($parts['query'], $query);
        if (is_string($query['socket'] ?? null) && $query['socket'] !== '') {
            $socket = $query['socket'];
        }
    }

    return [
        'host' => $parts['host'],
        'user' => rawurldecode((string) ($parts['user'] ?? '')),
        'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
        'db' => ltrim((string) ($parts['path'] ?? ''), '/'),
        'port' => is_int($parts['port'] ?? null) ? $parts['port'] : 3306,
        'socket' => $socket,
    ];
}

// --- limits and errors ---------------------------------------------------------------

function _stattic_db_broker_capability_granted(string $capability): bool
{
    $state = &_stattic_db_broker_state();
    if ($state['capabilities'] === null) {
        return true;
    }

    return in_array($capability, $state['capabilities'], true);
}

function _stattic_db_broker_operation_budget(): int
{
    $configured = (int) _stattic_config_value('SPACEFAST_ZERO_DB_OPERATIONS');

    return $configured > 0
        ? min($configured, STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_MAX)
        : STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_MAX;
}

function _stattic_db_broker_rows_max(): int
{
    $configured = (int) _stattic_config_value('SPACEFAST_ZERO_DB_ROWS_MAX');

    return $configured > 0 ? $configured : STATTIC_DB_RESULT_ROWS_MAX;
}

function _stattic_db_broker_bytes_max(): int
{
    $configured = (int) _stattic_config_value('SPACEFAST_ZERO_DB_RESULT_BYTES_MAX');

    return $configured > 0
        ? min($configured, STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_MAX)
        : STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_DEFAULT;
}

function _stattic_db_broker_stmt_cache_max(): int
{
    $configured = (int) _stattic_config_value('SPACEFAST_ZERO_DB_STMT_CACHE');

    return $configured > 0 ? $configured : STATTIC_DB_STMT_CACHE_MAX;
}

/**
 * @return array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_error(string $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'message' => $message];
}

/**
 * A server-side failure, rendered the way the Rust driver's `Display` renders
 * it, so a tenant sees the same text whichever engine served the operation.
 *
 * @return array{ok:false,code:string,message:string}
 */
function _stattic_db_broker_driver_error(string $code, mysqli $link, ?mysqli_stmt $stmt = null): array
{
    $errno = $stmt !== null && $stmt->errno !== 0 ? $stmt->errno : $link->errno;
    $state = $stmt !== null && $stmt->errno !== 0 ? $stmt->sqlstate : $link->sqlstate;
    $error = $stmt !== null && $stmt->errno !== 0 ? $stmt->error : $link->error;

    return _stattic_db_broker_error($code, _stattic_db_broker_driver_message($errno, $state, $error));
}

function _stattic_db_broker_driver_message(int $errno, string $sqlstate, string $error): string
{
    if ($errno === 0) {
        return 'Zero DB operation failed.';
    }

    return sprintf('MySqlError { ERROR %d (%s): %s }', $errno, $sqlstate, $error);
}
