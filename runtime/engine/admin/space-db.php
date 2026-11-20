<?php
declare(strict_types=1);

// Space data: the rows of a Space's own MySQL, listed, read and edited by
// primary key for the control plane's listSpaceDatabaseTables,
// listSpaceDatabaseRows and create/update/deleteSpaceDatabaseRows.
//
// Nothing here needs a Zero version or a declaration. A box serves one Space,
// so the box's database is that Space's database, and information_schema says
// what is in it. What these routes refuse is decided here and only here:
//
// - Spacefast's own tables are never listed, read or written: the application
//   journal and deliveries, the content-source journal, D1 migration ledgers,
//   Zero v2 and TypedPress system, identity and migration-receipt tables, the
//   content-model ledger, the identity plugin's `sfi_` tables, and any other
//   Space's Zero v2 binding.
// - In WordPress tables, secrets read as "[redacted]" and can't be filtered on
//   or written: `users.user_pass` and `users.user_activation_key`, and the
//   value of any option or meta row whose name says secret
//   (space-db-secrets.json). Without valid rules, guarded tables are refused.
//   A guarded table whose names are binary can't be classified, so its
//   value column is a whole-column secret and its rows are read-only.
// - Rows change only where a change can be found again and undone whole: a
//   table needs a primary key with no binary or protected column, a
//   transactional (InnoDB) engine, and no triggers, which could write outside
//   the call's transaction. A table whose triggers this account can't see
//   counts as having some. Binary and generated columns are read-only.
//
// Every SQL identifier comes from information_schema for the addressed table
// and is backtick-quoted; every value is a bound parameter, typed by its
// column so a string key never compares numerically. A write call is one
// transaction: until it commits, any failure rolls back everything in it. It
// holds the table's definition from its first statement, and checks and writes
// against the definition it holds. A committed write to a table WordPress
// caches then clears WordPress's cached copies of what it touched.

require_once __DIR__ . '/../shared/db-broker.php';
require_once __DIR__ . '/../shared/space-users-site.php';
require_once __DIR__ . '/../shared/storage.php';

const STATTIC_SPACE_DB_BODY_MAX_BYTES = 1_048_576;
const STATTIC_SPACE_DB_MAX_BATCH = 50;
const STATTIC_SPACE_DB_ROWS_DEFAULT_LIMIT = 50;
const STATTIC_SPACE_DB_ROWS_MAX_LIMIT = 100;
const STATTIC_SPACE_DB_DEADLINE_MS = 30_000;
const STATTIC_SPACE_DB_CURSOR_MAX_BYTES = 4_096;
const STATTIC_SPACE_DB_REDACTED = '[redacted]';
const STATTIC_SPACE_DB_NO_KEY_REASON = 'This table has no primary key, so its rows can be read but not changed here.';
const STATTIC_SPACE_DB_BINARY_KEY_REASON = 'This table\'s primary key holds binary values, so its rows can be read but not changed here.';
const STATTIC_SPACE_DB_ENGINE_REASON = 'This table\'s storage engine can\'t undo a partly applied change, so its rows can be read but not changed here.';
const STATTIC_SPACE_DB_PROTECTED_KEY_REASON = 'This table\'s primary key holds protected values, so its rows can be read but not changed here.';
const STATTIC_SPACE_DB_BINARY_NAMES_REASON = 'This table stores its option or meta names as binary, so which rows hold secrets can\'t be told: every value that isn\'t empty reads as "[redacted]", and its rows can be read but not changed here.';
const STATTIC_SPACE_DB_TRIGGER_REASON = 'This table has triggers, which can change other tables outside this request\'s transaction, so its rows can be read but not changed here.';
const STATTIC_SPACE_DB_UNSEEN_TRIGGER_REASON = 'This Space\'s database account can\'t see whether this table has triggers, which can change other tables outside this request\'s transaction, so its rows can be read but not changed here.';
// WordPress core tables whose rows WordPress caches, by name after the site's
// table prefix, with the columns naming each row's cached object. A term's
// cache is cleared per taxonomy, so a term_taxonomy row names both.
const STATTIC_SPACE_DB_WORDPRESS_CACHED = [
    'options' => ['option_name'],
    'users' => ['ID'],
    'usermeta' => ['user_id'],
    'posts' => ['ID'],
    'postmeta' => ['post_id'],
    'term_relationships' => ['object_id'],
    'comments' => ['comment_ID'],
    'commentmeta' => ['comment_id'],
    'terms' => ['term_id'],
    'term_taxonomy' => ['term_id', 'taxonomy'],
    'termmeta' => ['term_id'],
];
// JSON readers keep an integer exact only this far; past it a value travels as
// its digits.
const STATTIC_SPACE_DB_EXACT_INTEGER = 9_007_199_254_740_991;
// PHP's trim() set, as a pattern the database applies before it classifies a
// name the way _stattic_space_db_secret_name() does.
const STATTIC_SPACE_DB_TRIM_PATTERN = '^[ \t\n\r\x0b\x00]+|[ \t\n\r\x0b\x00]+$';

// Bytes the broker hands back base64-encoded when they are not UTF-8, so a
// value read from one of these could not be written back unchanged.
const STATTIC_SPACE_DB_BINARY_TYPES = [
    'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
    'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring',
    'multipolygon', 'geometrycollection', 'geomcollection',
];

// Columns a bound number compares against as a number. Every other column
// receives key and filter values as strings: MySQL compares a string column
// with a number numerically, so `1` would match '1', '01' and '1x'.
const STATTIC_SPACE_DB_NUMERIC_TYPES = [
    'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
    'decimal', 'numeric', 'float', 'double', 'real', 'year',
];
const STATTIC_SPACE_DB_INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year'];

const STATTIC_SPACE_DB_SECRET_MATCHES = ['exact', 'prefix', 'suffix', 'contains'];

// --- Routes --------------------------------------------------------------------

function _stattic_space_db_tables_route(string $privateRoot, string $spaceId, array $claims = []): never
{
    $scope = _stattic_space_db_open($privateRoot, $spaceId, false);
    $tables = [];
    foreach (_stattic_space_db_catalog(null) as $name => $entry) {
        // A numeric table name comes back from PHP as an integer key.
        $name = (string) $name;
        $kind = _stattic_space_db_table_kind($name, $scope);
        if ($kind === null) {
            continue;
        }
        $tables[] = _stattic_space_db_table_document($name, $kind, $entry, $scope);
    }
    _stattic_json_response(200, ['tables' => $tables]);
}

function _stattic_space_db_rows_query_route(string $privateRoot, string $spaceId, array $claims = []): never
{
    $body = _stattic_space_db_body();
    $tableName = _stattic_space_db_requested_table($body);
    $limit = $body['limit'] ?? STATTIC_SPACE_DB_ROWS_DEFAULT_LIMIT;
    if (!is_int($limit) || $limit < 1 || $limit > STATTIC_SPACE_DB_ROWS_MAX_LIMIT) {
        _stattic_space_db_invalid('`limit` must be a whole number from 1 to ' . STATTIC_SPACE_DB_ROWS_MAX_LIMIT . '.', '/limit');
    }
    $where = _stattic_space_db_object($body['where'] ?? new stdClass(), '/where');
    $cursor = $body['cursor'] ?? null;
    if ($cursor !== null && !is_string($cursor)) {
        _stattic_space_db_invalid('`cursor` must be a string.', '/cursor');
    }

    $scope = _stattic_space_db_open($privateRoot, $spaceId, false);
    $table = _stattic_space_db_table($tableName, $scope);
    $columns = _stattic_space_db_column_names($table);

    [$conditions, $params, $guardedValue] = _stattic_space_db_where($table, $where);
    $primaryKey = $table['primaryKey'];
    // Keyset pages carry the last key in the cursor. A key that reads back
    // base64-encoded can't seek, and a key holding a protected value must not
    // leave the box in a cursor: such tables page by position, in key order.
    $keyset = _stattic_space_db_keyset($table);
    $position = $cursor === null ? [] : _stattic_space_db_cursor($cursor, $tableName, $keyset ? count($primaryKey) : 0);
    // An offset cursor also continues a keyset table whose last key was too
    // long to carry in a cursor; it counts rows in key order.
    $seeking = $keyset && !isset($position['offset']);
    $offset = $position['offset'] ?? 0;
    [$filtered, $filteredParams] = [$conditions, $params];
    if (isset($position['key'])) {
        [$after, $afterParams] = _stattic_space_db_key_bound($table, '>', $position['key']);
        $conditions[] = $after;
        array_push($params, ...$afterParams);
    }

    // Columns are named, not `*`: an invisible primary key is still a key.
    $sql = 'SELECT ' . implode(', ', array_map('_stattic_space_db_quote', $columns))
        . ' FROM ' . _stattic_space_db_quote($tableName)
        . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
        . ($primaryKey === [] ? '' : ' ORDER BY ' . implode(', ', array_map(static fn (string $c): string => _stattic_space_db_quote($c) . ' ASC', $primaryKey)))
        . ' LIMIT ' . ($limit + 1)
        . ($offset > 0 ? ' OFFSET ' . $offset : '');
    $rows = _stattic_space_db_select($sql, $params);

    $next = null;
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[$limit - 1];
        $next = _stattic_query_cursor_encode(!$seeking
            ? ['t' => $tableName, 'o' => $offset + $limit]
            : ['t' => $tableName, 'k' => array_map(static fn (string $c): mixed => $last[$c] ?? null, $primaryKey)]);
        if ($seeking && strlen($next) > STATTIC_SPACE_DB_CURSOR_MAX_BYTES) {
            // Every cursor handed out must also be accepted back.
            [$through, $throughParams] = _stattic_space_db_key_bound($table, '<=', array_map(static fn (string $c): mixed => $last[$c] ?? null, $primaryKey));
            $counted = _stattic_space_db_select(
                'SELECT COUNT(*) AS n FROM ' . _stattic_space_db_quote($tableName) . ' WHERE ' . implode(' AND ', [...$filtered, $through]),
                [...$filteredParams, ...$throughParams],
            );
            $next = _stattic_query_cursor_encode(['t' => $tableName, 'o' => (int) ($counted[0]['n'] ?? 0)]);
        }
    }

    $redacted = [];
    $out = [];
    foreach ($rows as $row) {
        // The query already left secret rows out; this is the same rule in
        // PHP, so the two can never disagree in the caller's favour.
        if ($guardedValue && _stattic_space_db_secret_name($row[$table['protection']['guard']['name']] ?? null)) {
            continue;
        }
        $out[] = (object) _stattic_space_db_redact_row($table, $row, $redacted);
    }
    _stattic_json_response(200, [
        'table' => $tableName,
        'primaryKey' => $primaryKey,
        'columns' => $columns,
        'redactedColumns' => array_keys($redacted),
        'rows' => $out,
        'cursor' => $next,
    ]);
}

function _stattic_space_db_rows_insert_route(string $privateRoot, string $spaceId, array $claims = []): never
{
    $body = _stattic_space_db_body();
    $tableName = _stattic_space_db_requested_table($body);
    $rows = _stattic_space_db_batch($body['rows'] ?? null, '/rows');

    $scope = _stattic_space_db_open($privateRoot, $spaceId, true);
    _stattic_space_db_editable_table($tableName, $scope);
    _stattic_space_db_begin();
    $table = _stattic_space_db_hold($tableName, $scope);
    foreach ($rows as $index => $row) {
        $row = _stattic_space_db_object($row, '/rows/' . $index);
        _stattic_space_db_check_values($table, $row, '/rows/' . $index);
        // The receipt names each row's key, so a key the database would
        // generate some other way (a default, a trigger) has to be given.
        foreach ($table['primaryKey'] as $column) {
            if (!array_key_exists($column, $row)) {
                if (!$table['columns'][$column]['autoIncrement']) {
                    _stattic_problem_response(422, 'space_db_key_invalid', 'Give every primary-key column a value; only an auto-increment column may be left out.', ['pointer' => '/rows/' . $index . '/' . $column]);
                }
            } elseif (!is_string($row[$column]) && !is_int($row[$column]) && !is_float($row[$column])) {
                _stattic_problem_response(422, 'space_db_key_invalid', 'A key value must be a string or a number.', ['pointer' => '/rows/' . $index . '/' . $column]);
            }
        }
        $rows[$index] = $row;
    }

    $quoted = _stattic_space_db_quote($tableName);
    $guard = $table['protection']['guard'];
    $keys = [];
    $touched = [];
    foreach ($rows as $index => $row) {
        $columns = array_map('strval', array_keys($row));
        $sql = 'INSERT INTO ' . $quoted . ' ('
            . implode(', ', array_map('_stattic_space_db_quote', $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $result = _stattic_space_db_execute($sql, array_map('_stattic_space_db_param', array_values($row)));
        $key = [];
        foreach ($table['primaryKey'] as $column) {
            // The value MySQL stored: an explicit 0 or NULL in an
            // auto-increment column is generated, like an omitted one.
            $generated = !array_key_exists($column, $row)
                || ($table['columns'][$column]['autoIncrement'] && !in_array($result['lastInsertId'], [0, '0'], true));
            $key[$column] = _stattic_space_db_typed($table, $column, $generated ? $result['lastInsertId'] : $row[$column]);
        }
        // The receipt's key has to find the row just stored: a value its
        // column rounds or converts (1.234 in a DECIMAL(5,2)) names another
        // row, or none.
        [$match, $keyParams] = _stattic_space_db_key_match($table, $key);
        $stored = _stattic_space_db_select('SELECT ' . _stattic_space_db_watched_columns($table) . ' FROM ' . $quoted . ' WHERE ' . $match . ' LIMIT 2', $keyParams);
        if (count($stored) !== 1) {
            _stattic_db_broker_rollback_open_transaction();
            _stattic_problem_response(422, 'space_db_key_invalid', 'This row\'s key isn\'t stored as given: its column rounds or converts the value. Nothing in this request was written.', ['pointer' => '/rows/' . $index]);
        }
        // The stored name decides, not the submitted one: a column default
        // can supply it.
        if ($guard !== null && _stattic_space_db_secret_name($stored[0]['n'] ?? null)) {
            _stattic_db_broker_rollback_open_transaction();
            _stattic_problem_response(403, 'space_db_value_protected', 'This row would hold a secret, so it can\'t be written here.', ['pointer' => '/rows/' . $index]);
        }
        $touched[] = _stattic_space_db_watched($table, $stored[0]);
        $keys[] = (object) array_map('_stattic_space_db_exact', $key);
    }
    $touched = _stattic_space_db_term_taxonomies($table, $touched);
    _stattic_space_db_commit();
    _stattic_space_db_forget_cached($privateRoot, $spaceId, $table, $touched);

    _stattic_json_response(200, ['table' => $tableName, 'inserted' => count($rows), 'keys' => $keys]);
}

function _stattic_space_db_rows_update_route(string $privateRoot, string $spaceId, array $claims = []): never
{
    $body = _stattic_space_db_body();
    $tableName = _stattic_space_db_requested_table($body);
    $changes = _stattic_space_db_batch($body['rows'] ?? null, '/rows');

    $scope = _stattic_space_db_open($privateRoot, $spaceId, true);
    _stattic_space_db_editable_table($tableName, $scope);
    _stattic_space_db_begin();
    $table = _stattic_space_db_hold($tableName, $scope);
    $plan = [];
    foreach ($changes as $index => $change) {
        $change = _stattic_space_db_object($change, '/rows/' . $index);
        $key = _stattic_space_db_key($table, $change['key'] ?? null, '/rows/' . $index . '/key');
        $set = _stattic_space_db_object($change['set'] ?? null, '/rows/' . $index . '/set');
        if ($set === []) {
            _stattic_space_db_invalid('`set` must name at least one column.', '/rows/' . $index . '/set');
        }
        _stattic_space_db_check_values($table, $set, '/rows/' . $index . '/set');
        $plan[] = ['key' => $key, 'set' => $set];
    }

    $quoted = _stattic_space_db_quote($tableName);
    $updated = 0;
    $missing = [];
    $touched = [];
    foreach ($plan as $index => $step) {
        $locked = _stattic_space_db_lock_row($table, $step['key'], '/rows/' . $index . '/key');
        if ($locked === false) {
            $missing[] = (object) array_map('_stattic_space_db_exact', $step['key']);
            continue;
        }
        // The object before the change and, when the change moves the row to
        // another one, after it.
        $touched[] = _stattic_space_db_watched($table, $locked);
        $touched[] = _stattic_space_db_watched($table, $locked, $step['set']);
        $assignments = array_map(static fn (int|string $c): string => _stattic_space_db_quote((string) $c) . ' = ?', array_keys($step['set']));
        [$match, $keyParams] = _stattic_space_db_key_match($table, $step['key']);
        _stattic_space_db_execute(
            'UPDATE ' . $quoted . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $match,
            [...array_map('_stattic_space_db_param', array_values($step['set'])), ...$keyParams],
        );
        $updated++;
    }
    $touched = _stattic_space_db_term_taxonomies($table, $touched);
    _stattic_space_db_commit();
    _stattic_space_db_forget_cached($privateRoot, $spaceId, $table, $touched);

    _stattic_json_response(200, ['table' => $tableName, 'updated' => $updated, 'missing' => $missing]);
}

function _stattic_space_db_rows_delete_route(string $privateRoot, string $spaceId, array $claims = []): never
{
    $body = _stattic_space_db_body();
    $tableName = _stattic_space_db_requested_table($body);
    $keys = _stattic_space_db_batch($body['keys'] ?? null, '/keys');

    $scope = _stattic_space_db_open($privateRoot, $spaceId, true);
    _stattic_space_db_editable_table($tableName, $scope);
    _stattic_space_db_begin();
    $table = _stattic_space_db_hold($tableName, $scope);
    foreach ($keys as $index => $key) {
        $keys[$index] = _stattic_space_db_key($table, $key, '/keys/' . $index);
    }

    $quoted = _stattic_space_db_quote($tableName);
    $deleted = 0;
    $missing = [];
    $touched = [];
    foreach ($keys as $index => $key) {
        $locked = _stattic_space_db_lock_row($table, $key, '/keys/' . $index);
        if ($locked === false) {
            $missing[] = (object) array_map('_stattic_space_db_exact', $key);
            continue;
        }
        $touched[] = _stattic_space_db_watched($table, $locked);
        [$match, $keyParams] = _stattic_space_db_key_match($table, $key);
        $deleted += _stattic_space_db_execute('DELETE FROM ' . $quoted . ' WHERE ' . $match, $keyParams)['affectedRows'];
    }
    $touched = _stattic_space_db_term_taxonomies($table, $touched);
    _stattic_space_db_commit();
    _stattic_space_db_forget_cached($privateRoot, $spaceId, $table, $touched);

    _stattic_json_response(200, ['table' => $tableName, 'deleted' => $deleted, 'missing' => $missing]);
}

// --- Scope: which tables, which values -------------------------------------------

/**
 * Bind the box's database and name the Space's WordPress table prefixes: the
 * site's own `$table_prefix`, and the Space's Zero v2 binding (the formula
 * shared/zero-v2-scope.php binds WordPress to).
 *
 * @return array{prefixes:list<string>, zeroV2:string}
 */
function _stattic_space_db_open(string $privateRoot, string $spaceId, bool $write): array
{
    $site = _stattic_space_users_site_config(dirname(dirname($privateRoot)));
    $url = _stattic_space_users_site_database_url($site ?? []);
    if ($url === '') {
        _stattic_problem_response(503, 'space_db_unavailable', "This Space's database isn't configured on its server yet.");
    }
    _stattic_db_broker_bind($url, 'provider');
    _stattic_db_broker_set_read_deadline_ms(STATTIC_SPACE_DB_DEADLINE_MS);
    _stattic_db_broker_grant($write ? ['db.read', 'db.write'] : ['db.read']);
    $zeroV2 = 'sfz_' . substr(hash('sha256', $spaceId), 0, 24) . '_';
    $prefixes = array_values(array_unique([is_string($site['prefix'] ?? null) ? $site['prefix'] : 'wp_', $zeroV2]));
    usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    return ['prefixes' => $prefixes, 'zeroV2' => $zeroV2];
}

/**
 * `wordpress`, `app`, or null for a table that is Spacefast's own and never
 * served here.
 *
 * @param array{prefixes:list<string>, zeroV2:string} $scope
 */
function _stattic_space_db_table_kind(string $table, array $scope): ?string
{
    $name = strtolower($table);
    if (str_starts_with($name, '_spacefast_') || preg_match('/^sf_d1_m_[0-9a-f]{40}$/', $name) === 1) {
        return null;
    }
    // Another Space's Zero v2 binding: never this Space's data.
    if (preg_match('/^sfz_[0-9a-f]{24}_/', $name) === 1 && !str_starts_with($name, $scope['zeroV2'])) {
        return null;
    }
    foreach ($scope['prefixes'] as $prefix) {
        if (!str_starts_with($name, strtolower($prefix))) {
            continue;
        }
        $rest = substr($name, strlen($prefix));
        if (
            str_starts_with($rest, 'sfi_')
            || str_starts_with($rest, 'zero__')
            || str_starts_with($rest, 'zero_identity_')
            || str_starts_with($rest, 'zero_spacefast_')
            || str_starts_with($rest, 'tp_spacefast_')
            || str_starts_with($rest, 'tp__')
            || str_starts_with($rest, 'tp_identity_')
            || preg_match('/^zero_[0-9a-f]{16}_migrations$/', $rest) === 1
        ) {
            return null;
        }
        return str_starts_with($rest, 'zero_') || str_starts_with($rest, 'tp_') ? 'app' : 'wordpress';
    }
    return 'app';
}

/**
 * The secret-name rules, validated. Rules that can't be read or don't parse
 * refuse the request rather than redact nothing.
 *
 * @return list<array{match:string,name:string}>
 */
function _stattic_space_db_secret_rules(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }
    $raw = @file_get_contents(__DIR__ . '/space-db-secrets.json');
    // Decoded with objects kept, so a JSON object can't pass as a list.
    $decoded = is_string($raw) ? json_decode($raw, false) : null;
    $loaded = [];
    // Both lists must be present, non-empty JSON arrays: `{}` or `[]` for
    // either would silently drop the rules it carries.
    $valid = $decoded instanceof stdClass;
    foreach (['names', 'spacefast'] as $list) {
        $valid = $valid && is_array($decoded->{$list} ?? null) && $decoded->{$list} !== [];
    }
    foreach ($valid ? [...$decoded->names, ...$decoded->spacefast] : [] as $rule) {
        $match = $rule instanceof stdClass ? ($rule->match ?? null) : null;
        $name = $rule instanceof stdClass ? ($rule->name ?? null) : null;
        if (!in_array($match, STATTIC_SPACE_DB_SECRET_MATCHES, true) || !is_string($name) || $name === '') {
            $valid = false;
            break;
        }
        $loaded[] = ['match' => $match, 'name' => $name];
    }
    if (!$valid) {
        error_log('spacefast space-db secret rules unreadable');
        _stattic_problem_response(500, 'space_db_query_failed', 'Spacefast could not load the rules that keep this table\'s secrets hidden, so it won\'t read or change it.');
    }
    return $rules = $loaded;
}

/** Whether an option or meta name says its value is a secret. */
function _stattic_space_db_secret_name(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $name = trim($value, " \t\n\r\0\x0B");
    if (preg_match('/[^\x20-\x7e]/', $name) === 1) {
        return true;
    }
    $name = strtolower($name);
    foreach (_stattic_space_db_secret_rules() as $rule) {
        $want = $rule['name'];
        $hit = match ($rule['match']) {
            'exact' => $name === $want,
            'prefix' => str_starts_with($name, $want),
            'suffix' => str_ends_with($name, $want),
            'contains' => str_contains($name, $want),
        };
        if ($hit) {
            return true;
        }
    }
    return false;
}

/**
 * What a WordPress table keeps secret: whole columns (`users`), and for option
 * and meta tables the value column of each row whose name column says secret.
 * Names match case-insensitively, as MySQL's do; the catalog spelling is kept.
 *
 * @return array{columns:list<string>, guard:?array{name:string,value:string}}
 */
function _stattic_space_db_protection(string $table, string $kind, array $columns, array $scope): array
{
    $none = ['columns' => [], 'guard' => null, 'binaryNames' => false];
    if ($kind !== 'wordpress') {
        return $none;
    }
    $spelled = [];
    foreach (array_keys($columns) as $column) {
        $spelled[strtolower((string) $column)] = (string) $column;
    }
    foreach ($scope['prefixes'] as $prefix) {
        if (strtolower($table) === strtolower($prefix . 'users')) {
            $protected = [];
            foreach (['user_pass', 'user_activation_key'] as $column) {
                if (isset($spelled[$column])) {
                    $protected[] = $spelled[$column];
                }
            }
            return ['columns' => $protected, 'guard' => null, 'binaryNames' => false];
        }
    }
    foreach ([['option_name', 'option_value'], ['meta_key', 'meta_value']] as [$name, $value]) {
        if (isset($spelled[$name], $spelled[$value])) {
            if ($columns[$spelled[$name]]['binary']) {
                // Binary names needn't be text the rules can read.
                return ['columns' => [$spelled[$value]], 'guard' => null, 'binaryNames' => true];
            }
            return ['columns' => [], 'guard' => ['name' => $spelled[$name], 'value' => $spelled[$value]], 'binaryNames' => false];
        }
    }
    return $none;
}

// --- Catalog -------------------------------------------------------------------

/**
 * Base tables with their engine, columns and primary keys, from
 * information_schema.
 *
 * `triggers` is null when this account can't see the table's triggers.
 *
 * @return array<string, array{approxRows:?int, engine:string, triggers:?bool, columns:array<string,array<string,mixed>>, primaryKey:list<string>}>
 */
function _stattic_space_db_catalog(?string $table): array
{
    $filter = 'TABLE_SCHEMA = DATABASE()' . ($table === null ? '' : ' AND TABLE_NAME = ?');
    $params = $table === null ? [] : [$table];
    $catalog = [];
    foreach (_stattic_space_db_select(
        "SELECT TABLE_NAME AS tbl, TABLE_ROWS AS approx, ENGINE AS engine FROM information_schema.TABLES WHERE {$filter} AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
        $params,
    ) as $row) {
        $approx = $row['approx'] ?? null;
        $catalog[(string) $row['tbl']] = [
            'approxRows' => is_int($approx) || (is_string($approx) && ctype_digit($approx)) ? (int) $approx : null,
            'engine' => strtolower((string) ($row['engine'] ?? '')),
            'triggers' => false,
            'columns' => [],
            'primaryKey' => [],
        ];
    }
    foreach (_stattic_space_db_select(
        "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type, DATA_TYPE AS dataType, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra FROM information_schema.COLUMNS WHERE {$filter} ORDER BY TABLE_NAME, ORDINAL_POSITION",
        $params,
    ) as $row) {
        $name = (string) $row['tbl'];
        if (!isset($catalog[$name])) {
            continue;
        }
        $extra = strtolower((string) ($row['extra'] ?? ''));
        $dataType = strtolower((string) $row['dataType']);
        $catalog[$name]['columns'][(string) $row['col']] = [
            'type' => (string) $row['type'],
            'binary' => in_array($dataType, STATTIC_SPACE_DB_BINARY_TYPES, true),
            'numeric' => in_array($dataType, STATTIC_SPACE_DB_NUMERIC_TYPES, true),
            'integer' => in_array($dataType, STATTIC_SPACE_DB_INTEGER_TYPES, true),
            'nullable' => strtoupper((string) $row['nullable']) === 'YES',
            'default' => is_scalar($row['dflt'] ?? null) ? (string) $row['dflt'] : null,
            'autoIncrement' => str_contains($extra, 'auto_increment'),
            // `DEFAULT_GENERATED` marks an expression default, not a generated column.
            'generated' => preg_match('/\b(virtual|stored|persistent)\b/', $extra) === 1,
        ];
    }
    foreach (_stattic_space_db_select(
        "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col FROM information_schema.KEY_COLUMN_USAGE WHERE {$filter} AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION",
        $params,
    ) as $row) {
        $name = (string) $row['tbl'];
        if (isset($catalog[$name])) {
            $catalog[$name]['primaryKey'][] = (string) $row['col'];
        }
    }
    $triggerFilter = 'EVENT_OBJECT_SCHEMA = DATABASE()' . ($table === null ? '' : ' AND EVENT_OBJECT_TABLE = ?');
    foreach (_stattic_space_db_select("SELECT DISTINCT EVENT_OBJECT_TABLE AS tbl FROM information_schema.TRIGGERS WHERE {$triggerFilter}", $params) as $row) {
        $name = (string) $row['tbl'];
        if (isset($catalog[$name])) {
            $catalog[$name]['triggers'] = true;
        }
    }
    $sight = _stattic_space_db_trigger_sight();
    foreach ($catalog as $name => $entry) {
        if (!$entry['triggers'] && !$sight['all'] && !isset($sight['tables'][$name])) {
            $catalog[$name]['triggers'] = null;
        }
    }
    return $catalog;
}

/**
 * The tables whose triggers this account can see. MySQL and MariaDB list a
 * table's triggers only to an account holding TRIGGER on it, so without that
 * privilege an empty list proves nothing. Only a grant whose effect is certain
 * counts; any other table stays read-only rather than trust the list:
 * - A global grant, unless MySQL's partial revokes are on: a revoke for this
 *   database isn't listed in information_schema.
 * - A database grant, when every grant row matching this database holds
 *   TRIGGER. The server applies only one of them, chosen by its own ordering.
 *   Under partial revokes a grant names its database literally.
 * - A table grant, for its table.
 * A privilege that arrives through a role isn't listed at all.
 *
 * @return array{all:bool, tables:array<string,true>}
 */
function _stattic_space_db_trigger_sight(): array
{
    $grantee = "CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')";
    $literal = _stattic_space_db_partial_revokes();
    $global = $literal ? [] : _stattic_space_db_select("SELECT 1 AS found FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE = 'TRIGGER' AND GRANTEE = {$grantee}");
    // Without partial revokes a database grant may name a pattern (`wp\_%`),
    // matched as LIKE matches.
    [$match, $params] = $literal
        ? ['REPLACE(REPLACE(TABLE_SCHEMA, ?, ?), ?, ?) = DATABASE()', ['\\_', '_', '\\%', '%']]
        : ['DATABASE() LIKE TABLE_SCHEMA', []];
    $grants = _stattic_space_db_select(
        "SELECT TABLE_SCHEMA AS sch, MAX(PRIVILEGE_TYPE = 'TRIGGER') AS hasTrigger FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = {$grantee} AND {$match} GROUP BY TABLE_SCHEMA",
        $params,
    );
    $everyGrant = $grants !== [] && array_filter($grants, static fn (array $grant): bool => (int) ($grant['hasTrigger'] ?? 0) !== 1) === [];
    $sight = ['all' => $global !== [] || $everyGrant, 'tables' => []];
    if (!$sight['all']) {
        foreach (_stattic_space_db_select("SELECT TABLE_NAME AS tbl FROM information_schema.TABLE_PRIVILEGES WHERE PRIVILEGE_TYPE = 'TRIGGER' AND GRANTEE = {$grantee} AND TABLE_SCHEMA = DATABASE()") as $row) {
            $sight['tables'][(string) $row['tbl']] = true;
        }
    }
    return $sight;
}

/**
 * Whether MySQL's partial revokes are on (8.0.16 and later). They restrict a
 * global grant per database out of information_schema's sight, and make a
 * database grant name its database literally. MariaDB has neither. A version
 * that can't be read counts as on.
 */
function _stattic_space_db_partial_revokes(): bool
{
    $version = (string) (_stattic_space_db_select('SELECT VERSION() AS v')[0]['v'] ?? '');
    if (stripos($version, 'mariadb') !== false) {
        return false;
    }
    if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) !== 1) {
        return true;
    }
    if (version_compare($match[1], '8.0.16', '<')) {
        return false;
    }
    return !in_array(_stattic_space_db_select('SELECT @@GLOBAL.partial_revokes AS p')[0]['p'] ?? null, [0, '0', 'OFF'], true);
}

/** The addressed table, served or refused. */
function _stattic_space_db_table(string $tableName, array $scope): array
{
    $kind = _stattic_space_db_table_kind($tableName, $scope);
    if ($kind === null) {
        _stattic_problem_response(403, 'space_db_table_protected', 'This table is Spacefast\'s own data, so it can\'t be read or changed here.', ['details' => ['table' => $tableName]]);
    }
    $entry = _stattic_space_db_catalog($tableName)[$tableName] ?? null;
    if ($entry === null) {
        _stattic_problem_response(404, 'space_db_table_not_found', 'This Space\'s database has no table with this name.', ['details' => ['table' => $tableName]]);
    }
    $protection = _stattic_space_db_protection($tableName, $kind, $entry['columns'], $scope);
    if ($protection['guard'] !== null) {
        // Fail closed before any row of a guarded table is read.
        _stattic_space_db_secret_rules();
    }
    return [
        'name' => $tableName, 'kind' => $kind, ...$entry, 'protection' => $protection,
        'cached' => _stattic_space_db_cached($tableName, $kind, $entry['columns'], $scope),
    ];
}

/** @return list<string> every column, in table order */
function _stattic_space_db_column_names(array $table): array
{
    return array_map('strval', array_keys($table['columns']));
}

/**
 * Whether every primary-key column reads back exactly as stored. The broker
 * returns non-UTF-8 bytes base64-encoded, so a binary key column cannot be
 * matched or paged against by the value a read returned.
 *
 * @param array{columns:array<string,array<string,mixed>>, primaryKey:list<string>} $entry
 */
function _stattic_space_db_exact_key(array $entry): bool
{
    if ($entry['primaryKey'] === []) {
        return false;
    }
    foreach ($entry['primaryKey'] as $column) {
        if (($entry['columns'][$column]['binary'] ?? true) === true) {
            return false;
        }
    }
    return true;
}

/**
 * Whether a primary-key column holds a protected value. Such a key must not
 * travel in a cursor, nor be matched against: whether it matched would answer
 * a guess at the secret.
 */
function _stattic_space_db_protected_key(array $table): bool
{
    $guard = $table['protection']['guard'];
    $protected = [...$table['protection']['columns'], ...($guard === null ? [] : [$guard['value']])];
    return array_intersect($table['primaryKey'], $protected) !== [];
}

/** Whether pages may seek by key: an exact key holding no protected value. */
function _stattic_space_db_keyset(array $table): bool
{
    return _stattic_space_db_exact_key($table) && !_stattic_space_db_protected_key($table);
}

/** Why rows can't be changed here, or null when they can. */
function _stattic_space_db_read_only_reason(array $table): ?string
{
    return match (true) {
        $table['primaryKey'] === [] => STATTIC_SPACE_DB_NO_KEY_REASON,
        !_stattic_space_db_exact_key($table) => STATTIC_SPACE_DB_BINARY_KEY_REASON,
        _stattic_space_db_protected_key($table) => STATTIC_SPACE_DB_PROTECTED_KEY_REASON,
        $table['protection']['binaryNames'] => STATTIC_SPACE_DB_BINARY_NAMES_REASON,
        // A batch is one transaction only on an engine that can roll it back,
        // and only when no trigger writes somewhere that can't.
        $table['engine'] !== 'innodb' => STATTIC_SPACE_DB_ENGINE_REASON,
        $table['triggers'] === true => STATTIC_SPACE_DB_TRIGGER_REASON,
        $table['triggers'] === null => STATTIC_SPACE_DB_UNSEEN_TRIGGER_REASON,
        default => null,
    };
}

/**
 * Hold the table's definition until this call's transaction ends, then read
 * it again. Reading the table takes a metadata lock that lasts until commit or
 * rollback, and creating a trigger or changing a column, key or engine waits
 * for it; a change that landed after the first check shows in this read. A
 * refusal from here on ends the request, and the broker rolls back the open
 * transaction at shutdown.
 */
function _stattic_space_db_hold(string $tableName, array $scope): array
{
    _stattic_space_db_select('SELECT 1 FROM ' . _stattic_space_db_quote($tableName) . ' LIMIT 0');
    return _stattic_space_db_editable_table($tableName, $scope);
}

function _stattic_space_db_editable_table(string $tableName, array $scope): array
{
    $table = _stattic_space_db_table($tableName, $scope);
    $reason = _stattic_space_db_read_only_reason($table);
    if ($reason !== null) {
        _stattic_problem_response(422, 'space_db_table_not_editable', $reason, ['details' => ['table' => $tableName]]);
    }
    return $table;
}

function _stattic_space_db_table_document(string $name, string $kind, array $entry, array $scope): array
{
    $protection = _stattic_space_db_protection($name, $kind, $entry['columns'], $scope);
    $columns = [];
    foreach ($entry['columns'] as $column => $info) {
        $column = (string) $column;
        $columns[] = [
            'name' => $column,
            'type' => $info['type'],
            'nullable' => $info['nullable'],
            'default' => $info['default'],
            'autoIncrement' => $info['autoIncrement'],
            'generated' => $info['generated'],
            'primaryKey' => in_array($column, $entry['primaryKey'], true),
            'editable' => !$info['binary'] && !$info['generated'] && !in_array($column, $protection['columns'], true),
        ];
    }
    $reason = _stattic_space_db_read_only_reason([...$entry, 'protection' => $protection]);
    return [
        'name' => $name,
        'kind' => $kind,
        'primaryKey' => $entry['primaryKey'],
        'approxRows' => $entry['approxRows'],
        'editable' => $reason === null,
        'readOnlyReason' => $reason,
        'columns' => $columns,
    ];
}

// --- Reading -------------------------------------------------------------------

/**
 * `where` as SQL: exact matches on named columns. A protected column can't be
 * filtered on, and a filter on a guarded value column never matches a secret
 * row, so a guess can't be confirmed by whether a row comes back. The third
 * element says whether such a filter is present, so the page is checked too.
 *
 * @return array{0:list<string>, 1:list<mixed>, 2:bool}
 */
function _stattic_space_db_where(array $table, array $where): array
{
    $conditions = [];
    $params = [];
    $guard = $table['protection']['guard'];
    $guardedValue = false;
    foreach ($where as $column => $value) {
        $column = (string) $column;
        _stattic_space_db_column($table, $column, '/where/' . $column);
        if (in_array($column, $table['protection']['columns'], true)) {
            _stattic_problem_response(403, 'space_db_value_protected', 'This column holds secrets, so it can\'t be filtered on.', ['pointer' => '/where/' . $column]);
        }
        if ($guard !== null && $column === $guard['value']) {
            $guardedValue = true;
        }
        if ($value === null) {
            $conditions[] = _stattic_space_db_quote($column) . ' IS NULL';
            continue;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            _stattic_space_db_invalid('Each `where` value must be a string, number, boolean or null.', '/where/' . $column);
        }
        $conditions[] = _stattic_space_db_quote($column) . ' = ?';
        $params[] = _stattic_space_db_typed($table, $column, is_bool($value) ? (int) $value : $value);
    }
    if ($guardedValue) {
        // In the page query itself, so the limit and the cursor only ever see
        // rows that may be shown, whatever is written meanwhile.
        [$notSecret, $secretParams] = _stattic_space_db_not_secret_sql($guard['name']);
        $conditions[] = $notSecret;
        array_push($params, ...$secretParams);
    }
    return [$conditions, $params, $guardedValue];
}

/**
 * SQL that holds for a row whose name column is NULL or not secret by the
 * rules _stattic_space_db_secret_name() applies. It errs toward secret: any
 * character outside printable ASCII counts, where the PHP check trims first.
 *
 * @return array{0:string, 1:list<string>}
 */
function _stattic_space_db_not_secret_sql(string $column): array
{
    $quoted = _stattic_space_db_quote($column);
    // Trimmed as PHP trims, so a name PHP calls ordinary stays a match.
    $trimmed = 'REGEXP_REPLACE(' . $quoted . ", ?, '')";
    $name = 'LOWER(' . $trimmed . ')';
    $tests = [$trimmed . " REGEXP '[^ -~]'"];
    $params = [STATTIC_SPACE_DB_TRIM_PATTERN];
    foreach (_stattic_space_db_secret_rules() as $rule) {
        $escaped = strtr($rule['name'], ['!' => '!!', '%' => '!%', '_' => '!_']);
        [$tests[], $value] = match ($rule['match']) {
            'exact' => [$name . ' = ?', $rule['name']],
            'prefix' => [$name . " LIKE ? ESCAPE '!'", $escaped . '%'],
            'suffix' => [$name . " LIKE ? ESCAPE '!'", '%' . $escaped],
            'contains' => [$name . " LIKE ? ESCAPE '!'", '%' . $escaped . '%'],
        };
        array_push($params, STATTIC_SPACE_DB_TRIM_PATTERN, $value);
    }
    return ['(' . $quoted . ' IS NULL OR NOT (' . implode(' OR ', $tests) . '))', $params];
}

/** @param array<string,true> $redacted */
function _stattic_space_db_redact_row(array $table, array $row, array &$redacted): array
{
    foreach ($table['protection']['columns'] as $column) {
        if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
            $row[$column] = STATTIC_SPACE_DB_REDACTED;
            $redacted[$column] = true;
        }
    }
    $guard = $table['protection']['guard'];
    if ($guard !== null && _stattic_space_db_secret_name($row[$guard['name']] ?? null) && array_key_exists($guard['value'], $row)) {
        $row[$guard['value']] = STATTIC_SPACE_DB_REDACTED;
        $redacted[$guard['value']] = true;
    }
    // Table column order, not the broker's sorted keys.
    $ordered = [];
    foreach (_stattic_space_db_column_names($table) as $column) {
        if (array_key_exists($column, $row)) {
            $ordered[$column] = _stattic_space_db_exact($row[$column]);
        }
    }
    return $ordered;
}

/**
 * The cursor's position: `key` (the last key, for a keyset table) or `offset`
 * (rows already read, in key order where there is one).
 *
 * @return array{key?:list<mixed>, offset?:int}
 */
function _stattic_space_db_cursor(string $cursor, string $tableName, int $keyColumns): array
{
    $invalid = static fn () => _stattic_problem_response(422, 'space_db_cursor_invalid', 'This cursor does not belong to this table.', ['pointer' => '/cursor']);
    if (strlen($cursor) > STATTIC_SPACE_DB_CURSOR_MAX_BYTES || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
        $invalid();
    }
    $payload = json_decode(_stattic_base64url_decode($cursor), true, 8, JSON_BIGINT_AS_STRING);
    if (!is_array($payload) || ($payload['t'] ?? null) !== $tableName) {
        $invalid();
    }
    if (array_key_exists('o', $payload)) {
        $offset = $payload['o'];
        if (!is_int($offset) || $offset < 0) {
            $invalid();
        }
        return ['offset' => $offset];
    }
    $values = $payload['k'] ?? null;
    if ($keyColumns === 0 || !is_array($values) || !array_is_list($values) || count($values) !== $keyColumns) {
        $invalid();
    }
    foreach ($values as $value) {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $invalid();
        }
    }
    return ['key' => $values];
}

/**
 * `(key columns) <op> (values)` with each value bound as its column compares
 * it; row-value comparison keeps a composite key in key order.
 *
 * @return array{0:string, 1:list<mixed>}
 */
function _stattic_space_db_key_bound(array $table, string $operator, array $values): array
{
    $params = [];
    foreach ($table['primaryKey'] as $index => $column) {
        $params[] = _stattic_space_db_typed($table, $column, $values[$index] ?? null);
    }
    return [
        '(' . implode(', ', array_map('_stattic_space_db_quote', $table['primaryKey'])) . ') ' . $operator
            . ' (' . implode(', ', array_fill(0, count($table['primaryKey']), '?')) . ')',
        $params,
    ];
}

// --- Writing -------------------------------------------------------------------

/** Every column named exists, may be written, and is not a secret. */
function _stattic_space_db_check_values(array $table, array $values, string $pointer): void
{
    $guard = $table['protection']['guard'];
    foreach ($values as $column => $value) {
        $column = (string) $column;
        $info = _stattic_space_db_column($table, $column, $pointer . '/' . $column);
        if (in_array($column, $table['protection']['columns'], true)) {
            _stattic_problem_response(403, 'space_db_value_protected', 'This column holds secrets, so it can\'t be written here.', ['pointer' => $pointer . '/' . $column]);
        }
        if ($info['binary'] || $info['generated']) {
            _stattic_problem_response(422, 'space_db_column_not_editable', $info['generated']
                ? 'This column is generated, so its value can\'t be written.'
                : 'This column holds binary data, which can\'t be written here.', ['pointer' => $pointer . '/' . $column]);
        }
        if ($guard !== null && $column === $guard['name'] && _stattic_space_db_secret_name($value)) {
            _stattic_problem_response(403, 'space_db_value_protected', 'This row would hold a secret, so it can\'t be written here.', ['pointer' => $pointer . '/' . $column]);
        }
    }
}

/**
 * A row key naming exactly the primary-key columns, in key order, each value
 * typed by its column.
 *
 * @return array<string, string|int|float>
 */
function _stattic_space_db_key(array $table, mixed $key, string $pointer): array
{
    $key = _stattic_space_db_object($key, $pointer);
    $names = array_map('strval', array_keys($key));
    $expected = $table['primaryKey'];
    if (count($names) !== count($expected) || array_diff($names, $expected) !== []) {
        _stattic_problem_response(422, 'space_db_key_invalid', 'A key must name every primary-key column (' . implode(', ', $expected) . ') and nothing else.', ['pointer' => $pointer]);
    }
    $ordered = [];
    foreach ($expected as $column) {
        $value = $key[$column];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            _stattic_problem_response(422, 'space_db_key_invalid', 'A key value must be a string or a number.', ['pointer' => $pointer . '/' . $column]);
        }
        $ordered[$column] = _stattic_space_db_typed($table, $column, $value);
    }
    return $ordered;
}

/** @return array{0:string, 1:list<mixed>} */
function _stattic_space_db_key_match(array $table, array $key): array
{
    return [
        implode(' AND ', array_map(static fn (int|string $c): string => _stattic_space_db_quote((string) $c) . ' = ?', array_keys($key))),
        array_map('_stattic_space_db_param', array_values($key)),
    ];
}

/**
 * Lock the keyed row for the rest of the transaction, and return its watched
 * columns (_stattic_space_db_watched_columns). False when no row has this
 * key. A key matching several rows, or a row holding a secret, refuses the
 * whole call.
 *
 * @return array<string,mixed>|false
 */
function _stattic_space_db_lock_row(array $table, array $key, string $pointer): array|false
{
    $guard = $table['protection']['guard'];
    [$match, $params] = _stattic_space_db_key_match($table, $key);
    $rows = _stattic_space_db_select('SELECT ' . _stattic_space_db_watched_columns($table) . ' FROM ' . _stattic_space_db_quote($table['name']) . ' WHERE ' . $match . ' LIMIT 2 FOR UPDATE', $params);
    if ($rows === []) {
        return false;
    }
    if (count($rows) > 1) {
        _stattic_db_broker_rollback_open_transaction();
        _stattic_problem_response(422, 'space_db_key_invalid', 'This key matches more than one row, so nothing was changed.', ['pointer' => $pointer]);
    }
    if ($guard !== null && _stattic_space_db_secret_name($rows[0]['n'] ?? null)) {
        _stattic_db_broker_rollback_open_transaction();
        _stattic_problem_response(403, 'space_db_value_protected', 'This row holds a secret, so it can\'t be changed or deleted here.', ['pointer' => $pointer]);
    }
    return $rows[0];
}

/** What a lock or read-back returns: the guard name as `n`, the cached object's columns as `w0`, `w1`. */
function _stattic_space_db_watched_columns(array $table): string
{
    $columns = ['1 AS found'];
    if ($table['protection']['guard'] !== null) {
        $columns[] = _stattic_space_db_quote($table['protection']['guard']['name']) . ' AS n';
    }
    foreach ($table['cached']['columns'] ?? [] as $index => $column) {
        $columns[] = _stattic_space_db_quote($column) . ' AS w' . $index;
    }
    return implode(', ', $columns);
}

/**
 * The cached object a locked or read-back row names, as its watched columns'
 * values; with `$set`, the object the row names once that change is made.
 *
 * @return list<mixed>|null
 */
function _stattic_space_db_watched(array $table, array $row, array $set = []): ?array
{
    $columns = $table['cached']['columns'] ?? [];
    if ($columns === []) {
        return null;
    }
    $object = [];
    foreach ($columns as $index => $column) {
        $object[] = array_key_exists($column, $set) ? $set[$column] : ($row['w' . $index] ?? null);
    }
    return $object;
}

/**
 * A term's cache is cleared per taxonomy, so a write to terms or term meta
 * finds its terms' taxonomies while the rows still exist, inside the call's
 * transaction. A term in no taxonomy still has its own entries cleared. Every
 * other table's touched objects pass through.
 *
 * @param list<mixed> $touched
 * @return list<mixed>
 */
function _stattic_space_db_term_taxonomies(array $table, array $touched): array
{
    $cached = $table['cached'];
    if (!in_array($cached['table'] ?? null, ['terms', 'termmeta'], true)) {
        return $touched;
    }
    $ids = [];
    foreach ($touched as $object) {
        $id = is_array($object) ? filter_var($object[0] ?? null, FILTER_VALIDATE_INT) : false;
        if (is_int($id) && $id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        return [];
    }
    $objects = array_map(static fn (int $id): array => [$id, null], $ids);
    $rows = _stattic_space_db_select(
        'SELECT term_id AS t, taxonomy AS x FROM ' . _stattic_space_db_quote($cached['prefix'] . 'term_taxonomy')
        . ' WHERE term_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
        $ids,
    );
    foreach ($rows as $row) {
        $objects[] = [(int) $row['t'], (string) $row['x']];
    }
    return $objects;
}

/**
 * What WordPress caches of this table's rows: a core table under the site's
 * prefix, with the column naming each row's cached object, or any WordPress
 * table under a Zero v2 Space's prefix, cached under the scope's generation.
 * Null when WordPress caches none of it.
 *
 * @return array{prefix:string, table:?string, columns:list<string>}|null
 */
function _stattic_space_db_cached(string $table, string $kind, array $columns, array $scope): ?array
{
    if ($kind !== 'wordpress') {
        return null;
    }
    if (str_starts_with(strtolower($table), strtolower($scope['zeroV2']))) {
        return ['prefix' => $scope['zeroV2'], 'table' => null, 'columns' => []];
    }
    $spelled = [];
    foreach (array_keys($columns) as $column) {
        $spelled[strtolower((string) $column)] = (string) $column;
    }
    foreach ($scope['prefixes'] as $prefix) {
        if ($prefix === $scope['zeroV2'] || !str_starts_with(strtolower($table), strtolower($prefix))) {
            continue;
        }
        $base = strtolower(substr($table, strlen($prefix)));
        $watched = [];
        foreach (STATTIC_SPACE_DB_WORDPRESS_CACHED[$base] ?? [] as $column) {
            if (!isset($spelled[strtolower($column)])) {
                return null;
            }
            $watched[] = $spelled[strtolower($column)];
        }
        return $watched === [] ? null : ['prefix' => $prefix, 'table' => $base, 'columns' => $watched];
    }
    return null;
}

/**
 * After a committed write to a table WordPress caches, drop WordPress's cached
 * copies of what it touched. Behind a persistent object cache WordPress would
 * otherwise keep answering the old rows, such as an option's value or a
 * user's capabilities, until the entries expired. This boots WordPress as the
 * subject rewrite does and clears each touched object, before and after the
 * change, through WordPress's own functions; for a Zero v2 Space's tables it
 * drops the scope's generation. The write has committed, so a failure here is
 * logged, never answered as the write's.
 *
 * @param list<mixed> $touched the objects the call touched, as their watched columns' values
 */
function _stattic_space_db_forget_cached(string $privateRoot, string $spaceId, array $table, array $touched): void
{
    $cached = $table['cached'];
    if ($cached === null) {
        return;
    }
    require_once __DIR__ . '/space-subjects.php';
    // WordPress and its plugins may print while they boot; none of it belongs
    // in this answer.
    ob_start();
    try {
        if (!_stattic_space_subjects_wordpress($privateRoot, $spaceId) || !function_exists('wp_cache_delete')) {
            return;
        }
        if ($cached['table'] === null) {
            wp_cache_delete('generation', $cached['prefix']);
            return;
        }
        $objects = [];
        foreach ($touched as $object) {
            if (is_array($object) && (is_string($object[0] ?? null) || is_int($object[0] ?? null))) {
                $objects[(string) json_encode($object)] = $object;
            }
        }
        _stattic_space_db_forget_wordpress_objects($cached['table'], array_values($objects));
    } catch (Throwable $error) {
        error_log('spacefast space data could not clear the WordPress object cache: ' . $error::class);
    } finally {
        ob_end_clean();
    }
}

/**
 * Clear WordPress's cache of these objects of one core table.
 *
 * @param list<list<mixed>> $objects an option's name, an object's ID, or a
 *   term's ID and taxonomy
 */
function _stattic_space_db_forget_wordpress_objects(string $table, array $objects): void
{
    if ($table === 'options') {
        foreach ($objects as [$name]) {
            wp_cache_delete((string) $name, 'options');
        }
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        return;
    }
    if (in_array($table, ['terms', 'term_taxonomy', 'termmeta'], true)) {
        foreach ($objects as $object) {
            $id = (int) $object[0];
            $taxonomy = $object[1] ?? null;
            // Given a taxonomy WordPress reads the ID as a term's and clears
            // that taxonomy's tree; without one it would read a
            // term_taxonomy ID.
            if ($id > 0 && is_string($taxonomy) && $taxonomy !== '' && function_exists('clean_term_cache')) {
                clean_term_cache($id, $taxonomy);
            }
            wp_cache_delete($id, 'terms');
            wp_cache_delete($id, 'term_meta');
        }
        if ($objects !== [] && function_exists('wp_cache_set_terms_last_changed')) {
            wp_cache_set_terms_last_changed();
        }
        return;
    }
    $ids = [];
    foreach ($objects as $object) {
        if ((int) $object[0] > 0) {
            $ids[] = (int) $object[0];
        }
    }
    [$clean, $group, $metaGroup, $changed] = match ($table) {
        'users', 'usermeta' => ['clean_user_cache', 'users', 'user_meta', 'wp_cache_set_users_last_changed'],
        'posts', 'postmeta', 'term_relationships' => ['clean_post_cache', 'posts', 'post_meta', 'wp_cache_set_posts_last_changed'],
        default => ['clean_comment_cache', 'comment', 'comment_meta', 'wp_cache_set_comments_last_changed'],
    };
    foreach (array_unique($ids) as $id) {
        // The clean function finds the object first, so it skips one that is
        // gone; its own entry and its meta can still be cached apart.
        if (function_exists($clean)) {
            $clean($id);
        }
        wp_cache_delete($id, $group);
        wp_cache_delete($id, $metaGroup);
    }
    // Cached queries name their results by this stamp.
    if ($ids !== [] && function_exists($changed)) {
        $changed();
    }
}

// --- Broker --------------------------------------------------------------------

/** @return list<array<string,mixed>> */
function _stattic_space_db_select(string $sql, array $params = []): array
{
    $rows = _stattic_space_db_run(['sql' => $sql, 'params' => $params])['rows'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

/** @return array{affectedRows:int, lastInsertId:int|string} */
function _stattic_space_db_execute(string $sql, array $params): array
{
    $answer = _stattic_space_db_run(['sql' => $sql, 'params' => $params, 'mode' => 'execute']);
    $insertId = $answer['lastInsertId'] ?? 0;
    return [
        'affectedRows' => (int) ($answer['affectedRows'] ?? 0),
        'lastInsertId' => is_int($insertId) || is_string($insertId) ? $insertId : 0,
    ];
}

function _stattic_space_db_begin(): void
{
    _stattic_space_db_run(['mode' => 'transaction_begin']);
}

/**
 * Commit the call's transaction. Every failure before this point rolls back,
 * so it proves nothing was written. A failed commit proves nothing either way:
 * the server may have applied it before the answer was lost.
 */
function _stattic_space_db_commit(): void
{
    $answer = json_decode(_stattic_db_broker_execute('{"mode":"transaction_commit"}'), true);
    if (is_array($answer) && ($answer['ok'] ?? null) === true) {
        return;
    }
    _stattic_db_broker_rollback_open_transaction();
    _stattic_problem_response(500, 'space_db_write_outcome_unknown', 'The database stopped answering while saving this change, so it may or may not have been written. Read the rows before sending it again.');
}

/** @return array<string,mixed> the broker's answer, which is always ok here */
function _stattic_space_db_run(array $frame): array
{
    $answer = json_decode(
        _stattic_db_broker_execute((string) json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
        true,
        512,
        JSON_BIGINT_AS_STRING,
    );
    if (!is_array($answer) || ($answer['ok'] ?? null) !== true) {
        _stattic_space_db_refuse(is_array($answer) ? $answer : []);
    }
    return $answer;
}

/**
 * The broker's refusal as this surface's problem. Rolls back first: whatever
 * part of a write call already ran is undone, so nothing in it was written.
 * Only the MySQL error number goes back; its text can quote stored values.
 */
function _stattic_space_db_refuse(array $answer): never
{
    _stattic_db_broker_rollback_open_transaction();
    $code = is_string($answer['code'] ?? null) ? $answer['code'] : '';
    $message = is_string($answer['message'] ?? null) ? $answer['message'] : '';
    if (in_array($code, ['zero_db_operation_too_large', 'zero_db_too_many_params', 'zero_db_result_too_large', 'zero_db_result_too_many_rows'], true)) {
        _stattic_problem_response(413, 'space_db_too_large', 'This request or its page of rows is too large for one database statement.');
    }
    $errno = preg_match('/ERROR (\d+) \(/', $message, $match) === 1 ? (int) $match[1] : 0;
    [$status, $problem, $text] = match (true) {
        in_array($errno, [1062, 1586], true) => [409, 'space_db_duplicate_key', 'A row with the same key already exists; nothing in this request was written.'],
        in_array($errno, [1216, 1217, 1451, 1452, 3819, 4025], true) => [409, 'space_db_constraint_failed', 'A foreign key or check constraint refused the change; nothing in this request was written.'],
        in_array($errno, [1048, 1263, 1264, 1265, 1292, 1364, 1366, 1406, 1411, 3140], true) => [422, 'space_db_value_invalid', 'The database refused a value: it is missing, the wrong type, or too long for its column. Nothing in this request was written.'],
        in_array($code, ['zero_db_connect_failed', 'zero_db_url_invalid'], true) || in_array($errno, [1040, 1205, 1213, 2002, 2006, 2013], true) => [503, 'space_db_unavailable', 'This Space\'s database did not answer. Nothing in this request was written; try again.'],
        default => [500, 'space_db_query_failed', 'The database could not complete this request. Nothing in it was written.'],
    };
    _stattic_problem_response($status, $problem, $text, $errno === 0 ? [] : ['details' => ['mysqlErrno' => $errno]]);
}

// --- Input ---------------------------------------------------------------------

/**
 * The request as a map, decoded with JSON objects kept as objects: a JSON
 * column's `{}` must not arrive as an empty list.
 *
 * @return array<string,mixed>
 */
function _stattic_space_db_body(): array
{
    $raw = _stattic_bounded_request_body(STATTIC_SPACE_DB_BODY_MAX_BYTES);
    if ($raw === null) {
        _stattic_problem_response(413, 'space_db_too_large', 'This request is larger than one Space data call accepts; send fewer rows.');
    }
    $decoded = json_decode(is_string($raw) ? $raw : '', false, 64, JSON_BIGINT_AS_STRING);
    if (!$decoded instanceof stdClass) {
        _stattic_space_db_invalid('The request body must be a JSON object.', '');
    }
    return get_object_vars($decoded);
}

function _stattic_space_db_requested_table(array $body): string
{
    $table = $body['table'] ?? null;
    if (!is_string($table) || preg_match('/^[A-Za-z0-9_$]{1,64}$/', $table) !== 1) {
        _stattic_space_db_invalid('`table` must be a table name.', '/table');
    }
    return $table;
}

/** @return list<mixed> */
function _stattic_space_db_batch(mixed $items, string $pointer): array
{
    if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > STATTIC_SPACE_DB_MAX_BATCH) {
        _stattic_space_db_invalid('Send from 1 to ' . STATTIC_SPACE_DB_MAX_BATCH . ' items per call.', $pointer);
    }
    return $items;
}

/**
 * A JSON object as a map of its members. Member values stay as decoded, so a
 * nested object is still an object.
 *
 * @return array<int|string,mixed>
 */
function _stattic_space_db_object(mixed $value, string $pointer): array
{
    if (!$value instanceof stdClass) {
        _stattic_space_db_invalid('This must be a JSON object.', $pointer);
    }
    return get_object_vars($value);
}

/** @return array<string,mixed> the column's catalog entry */
function _stattic_space_db_column(array $table, string $column, string $pointer): array
{
    $info = $table['columns'][$column] ?? null;
    if (!is_array($info)) {
        _stattic_problem_response(422, 'space_db_column_not_found', 'Table ' . $table['name'] . ' has no column named ' . $column . '.', ['pointer' => $pointer]);
    }
    return $info;
}

/**
 * A key, cursor or filter value bound as its column compares it: a string
 * unless the column is numeric, and an integer column's digits as an exact
 * integer, so a key past 2^53 that travelled as a string still matches.
 */
function _stattic_space_db_typed(array $table, string $column, mixed $value): string|int|float|null
{
    if ($value === null) {
        return null;
    }
    $info = $table['columns'][$column] ?? [];
    if (!($info['numeric'] ?? false)) {
        return (string) $value;
    }
    if (is_string($value) && ($info['integer'] ?? false)) {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? $value : $integer;
    }
    return is_int($value) || is_float($value) ? $value : (string) $value;
}

/** An integer past what JSON readers keep exact, as its digits. */
function _stattic_space_db_exact(mixed $value): mixed
{
    return is_int($value) && abs($value) > STATTIC_SPACE_DB_EXACT_INTEGER ? (string) $value : $value;
}

/** A bound parameter: objects and arrays go in as JSON text. */
function _stattic_space_db_param(mixed $value): string|int|float|bool|null
{
    if (is_array($value) || $value instanceof stdClass) {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return $value;
}

function _stattic_space_db_quote(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function _stattic_space_db_invalid(string $message, string $pointer): never
{
    _stattic_problem_response(422, 'validation_error', $message, $pointer === '' ? [] : ['pointer' => $pointer]);
}
