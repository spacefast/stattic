use std::cell::RefCell;
use std::collections::BTreeMap;
use std::env;
use std::fs;
use std::net::{IpAddr, Ipv4Addr, Ipv6Addr};
use std::path::Path;
use std::time::Instant;

use base64::Engine;
use mysql::prelude::*;
use mysql::{Opts, Params, Pool, PooledConn, Row, Value as MysqlValue};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

pub(crate) const DB_OPERATION_MAX_BYTES: usize = 64 * 1024;
const DB_PARAM_MAX_COUNT: usize = 256;
const DB_TRANSACTION_MAX_STATEMENTS: usize = 64;

// Without a cap an unbounded SELECT OOMs the process instead of returning a
// named error the tenant can handle. The byte ceiling mirrors
// EXECUTION_OUTPUT_BYTES_MAX in `stattic-runtime-core/src/protocol.rs`.
const DB_RESULT_ROWS_MAX: usize = 50000;
const DB_RESULT_BYTES_MAX: usize = 10_485_760;

// sql_mode is written out rather than inherited: a laxer server global must not
// silently change what counts as a valid write. Applied per fresh connection.
const DB_SESSION_PIN: &str = "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_as_cs'\
    , SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\
    , SESSION transaction_isolation = 'REPEATABLE-READ'\
    , SESSION time_zone = '+00:00'";

/// The capability set a broker invocation runs under. `None` grant state means
/// the in-process Zero path, which is all-or-nothing by capability mask before
/// the host function is even installed.
#[derive(Clone, Copy, Debug)]
pub(crate) struct DbGrant {
    pub read: bool,
    pub write: bool,
}

thread_local! {
    static DB_METRICS: RefCell<DbMetrics> = RefCell::new(DbMetrics::default());
    static DB_TRANSACTION: RefCell<Option<PooledConn>> = const { RefCell::new(None) };
    static DB_GRANT: RefCell<Option<DbGrant>> = const { RefCell::new(None) };
    // One pool per process: statements in one invocation share a connection
    // instead of paying a handshake each.
    static DB_POOL: RefCell<Option<Pool>> = const { RefCell::new(None) };
    // Position of the next email effect within this invocation. It is the
    // second half of the outbox's idempotency key, so it must count per
    // invocation and not per process.
    static EMAIL_EFFECT_INDEX: RefCell<u32> = const { RefCell::new(0) };
}

#[derive(Clone, Debug, Default, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DbMetrics {
    pub operations: u64,
    pub connect_ms: f64,
    pub query_ms: f64,
    pub execute_ms: f64,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct DbOperation {
    #[serde(default)]
    sql: Option<String>,
    #[serde(default)]
    params: Vec<Value>,
    #[serde(default)]
    mode: Option<String>,
    #[serde(default)]
    statements: Vec<DbStatement>,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct DbStatement {
    sql: String,
    #[serde(default)]
    params: Vec<Value>,
    #[serde(default)]
    mode: Option<String>,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct MigrationArtifact {
    format: String,
    #[serde(rename = "artifact_kind")]
    artifact_kind: String,
    statements: Vec<String>,
}

pub(crate) fn set_grant(grant: Option<DbGrant>) {
    DB_GRANT.with(|state| {
        *state.borrow_mut() = grant;
    });
}

fn capability_granted(mutation: bool) -> bool {
    DB_GRANT.with(|state| match *state.borrow() {
        None => true,
        Some(grant) => {
            if mutation {
                grant.write
            } else {
                grant.read
            }
        }
    })
}

pub(crate) fn reset_metrics() {
    DB_METRICS.with(|metrics| {
        *metrics.borrow_mut() = DbMetrics::default();
    });
}

pub(crate) fn rollback_open_transaction() {
    DB_TRANSACTION.with(|transaction| {
        if let Some(mut conn) = transaction.borrow_mut().take() {
            let _ = conn.query_drop("ROLLBACK");
        }
    });
}

pub(crate) fn take_metrics() -> Option<DbMetrics> {
    DB_METRICS.with(|metrics| {
        let mut metrics = metrics.borrow_mut();
        if metrics.operations == 0 {
            return None;
        }
        Some(std::mem::take(&mut *metrics))
    })
}

pub(crate) fn handle_db_operation(raw: &str) -> String {
    match execute_db_operation(raw) {
        Ok(value) => value.to_string(),
        Err(error) => error.refusal_json(),
    }
}

pub(crate) fn apply_migrations_file(path: &Path) -> Result<(), String> {
    let raw = fs::read_to_string(path).map_err(|error| error.to_string())?;
    let artifact: MigrationArtifact =
        serde_json::from_str(&raw).map_err(|error| error.to_string())?;
    if artifact.format != "stattic.zero.migrations.v1"
        || artifact.artifact_kind != "zero_migrations"
    {
        return Err("Zero migration artifact format is unsupported.".to_string());
    }
    if artifact.statements.len() > 256 {
        return Err("Zero migration artifact has too many statements.".to_string());
    }

    let opts = database_opts().map_err(|error| error.message)?;
    let pool = Pool::new(opts).map_err(|error| error.to_string())?;
    let mut conn = pool.get_conn().map_err(|error| error.to_string())?;
    for statement in artifact.statements {
        let sql = statement.trim();
        if sql.is_empty() || sql.contains('\0') {
            return Err("Zero migration statement is invalid.".to_string());
        }
        if let Err(error) = conn.query_drop(sql) {
            // MySQL has no `ADD COLUMN IF NOT EXISTS`, so replaying a migration that
            // already landed is reported as duplicate-column (1060) the same way a
            // replayed index is duplicate-key (1061) or missing-key (1091).
            let ignorable_schema_state = matches!(
                &error,
                mysql::Error::MySqlError(database_error)
                    if (sql.starts_with("CREATE INDEX ") && database_error.code == 1061)
                        || (sql.starts_with("DROP INDEX ") && database_error.code == 1091)
                        || (sql.starts_with("ALTER TABLE ") && database_error.code == 1060)
            );
            if !ignorable_schema_state {
                return Err(error.to_string());
            }
        }
    }
    Ok(())
}

fn execute_db_operation(raw: &str) -> Result<Value, DbError> {
    if raw.len() > DB_OPERATION_MAX_BYTES {
        return Err(DbError::new(
            "zero_db_operation_too_large",
            "Zero DB operation exceeded the request size limit.",
        ));
    }
    let operation: DbOperation = serde_json::from_str(raw)
        .map_err(|error| DbError::new("zero_db_operation_invalid", error.to_string()))?;

    match operation.mode.as_deref() {
        Some("transaction_begin") => return begin_transaction(),
        Some("transaction_commit") => return finish_transaction("COMMIT"),
        Some("transaction_rollback") => return finish_transaction("ROLLBACK"),
        _ => {}
    }

    if matches!(operation.mode.as_deref(), Some("transaction")) {
        if transaction_active() {
            return Err(DbError::new(
                "zero_db_transaction_active",
                "A Zero DB transaction is already active.",
            ));
        }
        if operation.statements.is_empty()
            || operation.statements.len() > DB_TRANSACTION_MAX_STATEMENTS
        {
            return Err(DbError::new(
                "zero_db_transaction_invalid",
                "Zero DB transaction statements are invalid.",
            ));
        }
        // Validation and the capability check run before any connection exists:
        // an ungranted or malformed statement must be refused without touching
        // the database at all.
        let ready = operation
            .statements
            .iter()
            .map(ready_statement)
            .collect::<Result<Vec<_>, _>>()?;
        let mut conn = connect_db()?;
        conn.query_drop("START TRANSACTION")
            .map_err(|error| DbError::new("zero_db_transaction_start_failed", error.to_string()))?;
        let mut results = Vec::with_capacity(ready.len());
        for statement in ready {
            match run_ready_statement(&mut conn, statement) {
                Ok(value) => results.push(value),
                Err(error) => {
                    let _ = conn.query_drop("ROLLBACK");
                    return Err(error);
                }
            }
        }
        conn.query_drop("COMMIT").map_err(|error| {
            DbError::new("zero_db_transaction_commit_failed", error.to_string())
        })?;
        return Ok(json!({
            "ok": true,
            "results": results,
        }));
    }

    let statement = DbStatement {
        sql: operation.sql.unwrap_or_default(),
        params: operation.params,
        mode: operation.mode,
    };
    // Validation and the capability check run before any connection exists: an
    // ungranted or malformed statement must be refused without touching the
    // database at all.
    let ready = ready_statement(&statement)?;
    with_invocation_conn(|conn| run_ready_statement(conn, ready))
}

/// Runs on the connection an open transaction holds, or on a pooled one when
/// there is none. Anything that must land with the handler's own writes goes
/// through here rather than reaching for a connection of its own.
fn with_invocation_conn<T>(
    run: impl FnOnce(&mut PooledConn) -> Result<T, DbError>,
) -> Result<T, DbError> {
    if transaction_active() {
        return DB_TRANSACTION.with(|transaction| {
            let mut transaction = transaction.borrow_mut();
            let conn = transaction
                .as_mut()
                .expect("transaction presence checked on this thread");
            run(conn)
        });
    }
    let mut conn = connect_db()?;
    run(&mut conn)
}

/// The private outbox table. Platform-owned: it is deliberately not part of any
/// tenant schema, so schema introspection, exports, and the `db` binding never
/// see it, and a capsule cannot read another handler's recipients.
const EMAIL_OUTBOX_TABLE: &str = "_spacefast_email_outbox";

pub(crate) struct EmailOutboxRow<'a> {
    pub message_id: &'a str,
    pub space_id: &'a str,
    pub version_id: &'a str,
    pub invocation_id: &'a str,
    pub effect_index: u32,
    pub payload_json: &'a str,
}

/// Creates the outbox if this database has never carried one.
///
/// Deliberately on its own connection, never the invocation's. MySQL commits
/// implicitly on DDL, so issuing this on a connection inside a handler's
/// transaction would silently commit that handler's writes — destroying the
/// exact guarantee `ctx.email.send()` exists to provide.
///
/// Reached only when an insert finds no table, so in practice this runs once
/// per database, on the first send it ever takes — not once per process, which
/// bought nothing when every broker process is one-shot.
fn ensure_email_outbox_table() -> Result<(), String> {
    let mut conn = connect_db().map_err(|error| error.message)?;
    conn.query_drop(format!(
        "CREATE TABLE IF NOT EXISTS {EMAIL_OUTBOX_TABLE} (
            message_id VARCHAR(80) NOT NULL PRIMARY KEY,
            space_id VARCHAR(128) NOT NULL,
            version_id VARCHAR(128) NOT NULL,
            invocation_id VARCHAR(96) NOT NULL,
            effect_index SMALLINT UNSIGNED NOT NULL,
            state VARCHAR(24) NOT NULL,
            payload_json MEDIUMBLOB NOT NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME(6) NOT NULL,
            lease_token VARCHAR(80) NULL,
            lease_expires_at DATETIME(6) NULL,
            provider_message_id VARCHAR(255) NULL,
            last_error_code VARCHAR(96) NULL,
            accepted_at DATETIME(6) NULL,
            terminal_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uniq_invocation_effect (space_id, invocation_id, effect_index),
            KEY idx_due (state, available_at),
            KEY idx_space_created (space_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ))
    .map_err(|error| error.to_string())?;
    Ok(())
}

pub(crate) fn next_email_effect_index() -> u32 {
    EMAIL_EFFECT_INDEX.with(|index| {
        let mut index = index.borrow_mut();
        let current = *index;
        *index = index.saturating_add(1);
        current
    })
}

pub(crate) fn reset_email_effect_index() {
    EMAIL_EFFECT_INDEX.with(|index| *index.borrow_mut() = 0);
}

/// Inserts one queued message.
///
/// This runs on whatever connection the invocation is already using, so inside
/// a handler's transaction it commits or rolls back with the handler's own
/// writes — the guarantee that distinguishes a capsule's send from a worker's.
///
/// It does not pass through `ready_statement`: that gate exists to police
/// tenant SQL against the tenant's grant, and this statement is the platform's
/// own, with no tenant text in it. Every value is bound, never interpolated.
///
/// `INSERT IGNORE` is the idempotency: the unique key is
/// (space, invocation, effect index), so a replayed invocation writes nothing
/// the second time and still reports the same message id to its caller.
///
/// Creating the table is the error path, not a preamble: a missing table is a
/// once-per-database event, while the DDL round trip it guarded would otherwise
/// be paid by every send — on a second connection, with a visitor waiting. A
/// failed INSERT does not abort the handler's transaction, so the retry lands
/// on the same connection the first attempt used.
pub(crate) fn insert_email_outbox_row(row: EmailOutboxRow<'_>) -> Result<(), String> {
    let sql = format!(
        "INSERT IGNORE INTO {EMAIL_OUTBOX_TABLE} \
         (message_id, space_id, version_id, invocation_id, effect_index, state, payload_json, \
          attempt_count, available_at, created_at, updated_at) \
         VALUES (?, ?, ?, ?, ?, 'queued', ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    let params = || {
        Params::Positional(vec![
            MysqlValue::from(row.message_id),
            MysqlValue::from(row.space_id),
            MysqlValue::from(row.version_id),
            MysqlValue::from(row.invocation_id),
            MysqlValue::from(row.effect_index),
            MysqlValue::from(row.payload_json),
        ])
    };
    let run = |conn: &mut PooledConn| match conn.exec_drop(&sql, params()) {
        Ok(()) => Ok(()),
        Err(mysql::Error::MySqlError(database_error)) if database_error.code == 1146 => {
            ensure_email_outbox_table()?;
            conn.exec_drop(&sql, params())
                .map_err(|error| error.to_string())
        }
        Err(error) => Err(error.to_string()),
    };
    with_invocation_conn(|conn| {
        run(conn).map_err(|message| DbError::new("email_outbox_unavailable", message))
    })
    .map_err(|error| error.message)
}

fn connect_db() -> Result<PooledConn, DbError> {
    let connect_started = Instant::now();
    let conn = DB_POOL.with(|state| {
        let mut state = state.borrow_mut();
        if state.is_none() {
            let opts = database_opts()?;
            *state = Some(
                Pool::new(opts)
                    .map_err(|error| DbError::new("zero_db_connect_failed", error.to_string()))?,
            );
        }
        state
            .as_ref()
            .expect("pool constructed above")
            .get_conn()
            .map_err(|error| DbError::new("zero_db_connect_failed", error.to_string()))
    })?;
    record_connect(connect_started);
    Ok(conn)
}

fn transaction_active() -> bool {
    DB_TRANSACTION.with(|transaction| transaction.borrow().is_some())
}

fn begin_transaction() -> Result<Value, DbError> {
    if transaction_active() {
        return Err(DbError::new(
            "zero_db_transaction_active",
            "A Zero DB transaction is already active.",
        ));
    }
    let mut conn = connect_db()?;
    conn.query_drop("START TRANSACTION")
        .map_err(|error| DbError::new("zero_db_transaction_start_failed", error.to_string()))?;
    DB_TRANSACTION.with(|transaction| {
        *transaction.borrow_mut() = Some(conn);
    });
    Ok(json!({ "ok": true }))
}

fn finish_transaction(command: &'static str) -> Result<Value, DbError> {
    let mut conn = DB_TRANSACTION
        .with(|transaction| transaction.borrow_mut().take())
        .ok_or_else(|| {
            DbError::new(
                "zero_db_transaction_missing",
                "No Zero DB transaction is active.",
            )
        })?;
    conn.query_drop(command).map_err(|error| {
        let code = if command == "COMMIT" {
            "zero_db_transaction_commit_failed"
        } else {
            "zero_db_transaction_rollback_failed"
        };
        DbError::new(code, error.to_string())
    })?;
    Ok(json!({ "ok": true }))
}

struct ReadyStatement<'a> {
    sql: &'a str,
    params: Params,
    execute_shape: bool,
}

/// Everything that can be decided without a connection: SQL shape, parameter
/// marshalling, and whether the grant covers the statement itself.
fn ready_statement(statement: &DbStatement) -> Result<ReadyStatement<'_>, DbError> {
    let sql = statement.sql.trim();
    if sql.is_empty() || sql.contains('\0') {
        return Err(DbError::new(
            "zero_db_sql_invalid",
            "Zero DB operation SQL is invalid.",
        ));
    }
    if statement.params.len() > DB_PARAM_MAX_COUNT {
        return Err(DbError::new(
            "zero_db_too_many_params",
            "Zero DB operation has too many parameters.",
        ));
    }
    let params = Params::Positional(
        statement
            .params
            .iter()
            .map(json_to_mysql_value)
            .collect::<Result<Vec<_>, _>>()?,
    );
    let execute_shape = matches!(
        statement.mode.as_deref(),
        Some("execute") | Some("exec") | Some("mutation")
    );
    if !capability_granted(statement_is_mutation(sql)) {
        return Err(DbError::new(
            "zero_db_capability_denied",
            "Zero DB capability is not granted.",
        ));
    }
    Ok(ReadyStatement {
        sql,
        params,
        execute_shape,
    })
}

/// Authorization follows the SQL, never the caller-supplied result-shape hint.
/// Anything not positively recognized as a read fails closed as a mutation.
fn statement_is_mutation(sql: &str) -> bool {
    let Some((keyword, rest)) = leading_sql_keyword(sql) else {
        return true;
    };

    if keyword.eq_ignore_ascii_case("SELECT") || keyword.eq_ignore_ascii_case("SHOW") {
        return contains_sql_keyword(sql, "OUTFILE") || contains_sql_keyword(sql, "DUMPFILE");
    }

    if keyword.eq_ignore_ascii_case("EXPLAIN")
        || keyword.eq_ignore_ascii_case("DESCRIBE")
        || keyword.eq_ignore_ascii_case("DESC")
    {
        return leading_sql_keyword(rest)
            .is_some_and(|(next, _)| next.eq_ignore_ascii_case("ANALYZE"));
    }

    true
}

/// Finds the first SQL keyword after syntax MySQL skips before the statement.
/// Executable version comments (`/*! ... */`) remain unrecognized and therefore
/// classify as writes.
fn leading_sql_keyword(mut sql: &str) -> Option<(&str, &str)> {
    loop {
        sql = sql.trim_start();
        if let Some(rest) = sql.strip_prefix('(') {
            sql = rest;
            continue;
        }
        if let Some(rest) = sql.strip_prefix('#') {
            sql = skip_sql_line(rest);
            continue;
        }
        if let Some(rest) = sql.strip_prefix("--") {
            if !rest.chars().next().is_some_and(char::is_whitespace) {
                return None;
            }
            sql = skip_sql_line(rest);
            continue;
        }
        if let Some(rest) = sql.strip_prefix("/*") {
            if rest.starts_with('!') {
                return None;
            }
            let end = rest.find("*/")?;
            sql = &rest[end + 2..];
            continue;
        }
        break;
    }

    let mut bytes = sql.bytes();
    let first = bytes.next()?;
    if !first.is_ascii_alphabetic() && first != b'_' {
        return None;
    }
    let end = 1 + bytes
        .position(|byte| !sql_keyword_byte(byte))
        .unwrap_or(sql.len() - 1);
    Some((&sql[..end], &sql[end..]))
}

fn skip_sql_line(sql: &str) -> &str {
    sql.find(['\n', '\r']).map_or("", |end| &sql[end..])
}

fn contains_sql_keyword(sql: &str, expected: &str) -> bool {
    sql.split(|character: char| {
        !character.is_ascii_alphanumeric() && character != '_' && character != '$'
    })
    .any(|token| token.eq_ignore_ascii_case(expected))
}

fn sql_keyword_byte(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'$'
}

fn run_ready_statement(
    conn: &mut mysql::PooledConn,
    ready: ReadyStatement<'_>,
) -> Result<Value, DbError> {
    let ReadyStatement {
        sql,
        params,
        execute_shape,
    } = ready;
    if execute_shape {
        let query_started = Instant::now();
        conn.exec_drop(sql, params)
            .map_err(|error| DbError::new("zero_db_execute_failed", error.to_string()))?;
        record_execute(query_started);
        return Ok(json!({
            "ok": true,
            "affectedRows": conn.affected_rows(),
            "lastInsertId": conn.last_insert_id(),
        }));
    }

    // Caps are enforced while materialising: a runaway SELECT must never be
    // collected first, or the process dies on memory instead of erroring.
    let rows_max = db_rows_max();
    let bytes_max = db_result_bytes_max();
    let query_started = Instant::now();
    let mut result = conn
        .exec_iter(sql, params)
        .map_err(|error| DbError::new("zero_db_query_failed", error.to_string()))?;
    let mut rows_json: Vec<Value> = Vec::new();
    let mut encoded_bytes: usize = 0;
    for row in result.by_ref() {
        let row = row.map_err(|error| DbError::new("zero_db_query_failed", error.to_string()))?;
        if rows_json.len() >= rows_max {
            return Err(DbError::new(
                "zero_db_result_too_many_rows",
                "Zero DB result exceeded the row limit.",
            ));
        }
        let value = row_to_json(&row)?;
        encoded_bytes = encoded_bytes.saturating_add(encoded_len(&value) + 1);
        if encoded_bytes > bytes_max {
            return Err(DbError::new(
                "zero_db_result_too_large",
                "Zero DB result exceeded the size limit.",
            ));
        }
        rows_json.push(value);
    }
    record_query(query_started);
    Ok(json!({
        "ok": true,
        "rows": rows_json,
    }))
}

/// The JSON encoding length of a value, without building the encoding.
fn encoded_len(value: &Value) -> usize {
    struct ByteCounter(usize);
    impl std::io::Write for ByteCounter {
        fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
            self.0 += buf.len();
            Ok(buf.len())
        }
        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }
    let mut counter = ByteCounter(0);
    serde_json::to_writer(&mut counter, value).map_or(0, |()| counter.0)
}

fn record_connect(started: Instant) {
    DB_METRICS.with(|metrics| {
        let mut metrics = metrics.borrow_mut();
        metrics.operations = metrics.operations.saturating_add(1);
        metrics.connect_ms += elapsed_ms(started);
    });
}

fn record_query(started: Instant) {
    DB_METRICS.with(|metrics| {
        let mut metrics = metrics.borrow_mut();
        metrics.query_ms += elapsed_ms(started);
    });
}

fn record_execute(started: Instant) {
    DB_METRICS.with(|metrics| {
        let mut metrics = metrics.borrow_mut();
        metrics.execute_ms += elapsed_ms(started);
    });
}

fn elapsed_ms(started: Instant) -> f64 {
    started.elapsed().as_secs_f64() * 1000.0
}

/// Provenance label for the one reserved database URL the PHP adapter passes
/// down: `application` marks an app-declared external MySQL URL, `provider`
/// the platform-scoped database. Application URLs fail closed until the
/// reviewed public-address policy can be paired with authenticated final-
/// connect pinning; provider connectivity is a separate explicit authority
/// and never falls back from a missing label.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum DatabaseUrlSource {
    Application,
    Provider,
}

fn database_opts() -> Result<Opts, DbError> {
    let (value, source) = select_database_url(
        env::var("SPACEFAST_ZERO_DATABASE_URL").ok(),
        env::var("SPACEFAST_ZERO_DATABASE_URL_SOURCE").ok(),
    )?;
    let opts = database_opts_from_url(&value, source)?;
    Ok(mysql::OptsBuilder::from_opts(opts)
        .init(vec![DB_SESSION_PIN])
        .into())
}

fn configured_limit(name: &str) -> Option<usize> {
    env::var(name)
        .ok()
        .and_then(|value| value.trim().parse::<usize>().ok())
        .filter(|value| *value > 0)
}

fn db_rows_max() -> usize {
    // The env may lower a cap, never raise it — same as the byte ceiling below.
    configured_limit("SPACEFAST_ZERO_DB_ROWS_MAX")
        .map_or(DB_RESULT_ROWS_MAX, |value| value.min(DB_RESULT_ROWS_MAX))
}

fn db_result_bytes_max() -> usize {
    configured_limit("SPACEFAST_ZERO_DB_RESULT_BYTES_MAX")
        .map(|value| value.min(DB_RESULT_BYTES_MAX))
        .unwrap_or(DB_RESULT_BYTES_MAX)
}

fn database_opts_from_url(url: &str, source: DatabaseUrlSource) -> Result<Opts, DbError> {
    let opts = Opts::from_url(url)
        .map_err(|_| DbError::new("zero_db_url_invalid", "Zero DB URL could not be parsed."))?;
    if source == DatabaseUrlSource::Provider {
        return Ok(opts);
    }

    // The URL option namespace can replace host/user/database, enable local
    // transports or relax authentication. It also cannot safely express
    // "connect to this pinned IP but verify this original DNS name" in both
    // native engines, so TLS/certificate options must not be accepted and then
    // silently applied to the pinned IP identity. Provider-owned connectivity
    // retains its platform-configured options above.
    if url.contains('?') || opts.get_socket().is_some() {
        return Err(DbError::new(
            "zero_db_url_invalid",
            "Application Zero DB URLs cannot contain driver options.",
        ));
    }

    // mysql v26 uses the same host for TCP resolution and TLS identity. It
    // cannot dial a validated IP while preserving the original hostname for
    // SNI and certificate verification. Connecting with the hostname reopens
    // DNS rebinding; connecting with the IP changes TLS identity; accepting no
    // TLS sends the credential in plaintext. Until the driver exposes separate
    // dial and server-name inputs, application databases fail closed here.
    Err(DbError::new(
        "zero_db_tls_required",
        "Application Zero DB connections require pinned, hostname-verified TLS.",
    ))
}

// Retained address policy for the future verified-TLS application transport.
//
// Nothing calls this today: application databases fail closed above, before any
// name is resolved, so no address ever reaches the check. It stays because it is
// the reviewed answer to "which addresses may a tenant database live at", it is
// expensive to re-derive (the IANA allocation and special-use tables below), and
// it is held honest by the address corpus in
// `runtime/tests/fixtures/database-egress-addresses.json`. Wire it up when the
// driver can dial a pinned IP while verifying the original hostname.
#[allow(dead_code)]
fn database_address_allowed(address: IpAddr) -> bool {
    match address {
        IpAddr::V4(address) => database_ipv4_allowed(address),
        IpAddr::V6(address) => address
            .to_ipv4_mapped()
            .map_or_else(|| database_ipv6_allowed(address), database_ipv4_allowed),
    }
}

fn database_ipv4_allowed(address: Ipv4Addr) -> bool {
    let value = u32::from(address);
    ![
        (Ipv4Addr::new(0, 0, 0, 0), 8),
        (Ipv4Addr::new(10, 0, 0, 0), 8),
        (Ipv4Addr::new(100, 64, 0, 0), 10),
        (Ipv4Addr::new(127, 0, 0, 0), 8),
        (Ipv4Addr::new(169, 254, 0, 0), 16),
        (Ipv4Addr::new(172, 16, 0, 0), 12),
        (Ipv4Addr::new(192, 0, 0, 0), 24),
        (Ipv4Addr::new(192, 0, 2, 0), 24),
        (Ipv4Addr::new(192, 88, 99, 0), 24),
        (Ipv4Addr::new(192, 168, 0, 0), 16),
        (Ipv4Addr::new(198, 18, 0, 0), 15),
        (Ipv4Addr::new(198, 51, 100, 0), 24),
        (Ipv4Addr::new(203, 0, 113, 0), 24),
        (Ipv4Addr::new(224, 0, 0, 0), 3),
    ]
    .iter()
    .any(|(network, prefix)| {
        let mask = u32::MAX << (32 - (*prefix as u32));
        value & mask == u32::from(*network) & mask
    })
}

fn database_ipv6_allowed(address: Ipv6Addr) -> bool {
    let value = u128::from(address);
    // IANA's Global Unicast Address Space registry (updated 2025-10-10) is fail-closed:
    // only allocated prefixes enter, rather than treating the whole
    // assignable 2000::/3 envelope as public. The special-use exclusions below
    // then remove documentation, transition, and other non-public subranges.
    let allocated = [
        (Ipv6Addr::new(0x2001, 0x0200, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x0400, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x0600, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x0800, 0, 0, 0, 0, 0, 0), 22),
        (Ipv6Addr::new(0x2001, 0x0c00, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x0e00, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x1200, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x1400, 0, 0, 0, 0, 0, 0), 22),
        (Ipv6Addr::new(0x2001, 0x1800, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x1a00, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x1c00, 0, 0, 0, 0, 0, 0), 22),
        (Ipv6Addr::new(0x2001, 0x2000, 0, 0, 0, 0, 0, 0), 19),
        (Ipv6Addr::new(0x2001, 0x4000, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4200, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4400, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4600, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4800, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4a00, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x4c00, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2001, 0x5000, 0, 0, 0, 0, 0, 0), 20),
        (Ipv6Addr::new(0x2001, 0x8000, 0, 0, 0, 0, 0, 0), 19),
        (Ipv6Addr::new(0x2001, 0xa000, 0, 0, 0, 0, 0, 0), 20),
        (Ipv6Addr::new(0x2001, 0xb000, 0, 0, 0, 0, 0, 0), 20),
        (Ipv6Addr::new(0x2003, 0, 0, 0, 0, 0, 0, 0), 18),
        (Ipv6Addr::new(0x2400, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2410, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2600, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2610, 0, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2620, 0, 0, 0, 0, 0, 0, 0), 23),
        (Ipv6Addr::new(0x2630, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2800, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2a00, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2a10, 0, 0, 0, 0, 0, 0, 0), 12),
        (Ipv6Addr::new(0x2c00, 0, 0, 0, 0, 0, 0, 0), 12),
    ]
    .iter()
    .any(|(network, prefix)| {
        let mask = u128::MAX << (128 - (*prefix as u32));
        value & mask == u128::from(*network) & mask
    });
    if !allocated {
        return false;
    }

    ![
        (Ipv6Addr::UNSPECIFIED, 96),
        (Ipv6Addr::new(0, 0, 0, 0, 0xffff, 0, 0, 0), 96),
        // IANA dummy IPv6 prefix.
        (Ipv6Addr::new(0x100, 0, 0, 1, 0, 0, 0, 0), 64),
        (Ipv6Addr::new(0x64, 0xff9b, 0, 0, 0, 0, 0, 0), 96),
        (Ipv6Addr::new(0x64, 0xff9b, 1, 0, 0, 0, 0, 0), 48),
        (Ipv6Addr::new(0x100, 0, 0, 0, 0, 0, 0, 0), 64),
        (Ipv6Addr::new(0x2001, 0, 0, 0, 0, 0, 0, 0), 23),
        // Documentation prefixes.
        (Ipv6Addr::new(0x2001, 0xdb8, 0, 0, 0, 0, 0, 0), 32),
        (Ipv6Addr::new(0x2002, 0, 0, 0, 0, 0, 0, 0), 16),
        // IANA has not allocated this block for global unicast.
        (Ipv6Addr::new(0x3f00, 0, 0, 0, 0, 0, 0, 0), 8),
        (Ipv6Addr::new(0x3fff, 0, 0, 0, 0, 0, 0, 0), 20),
        // Segment-routing SIDs are forwardable but not globally reachable.
        (Ipv6Addr::new(0x5f00, 0, 0, 0, 0, 0, 0, 0), 16),
        (Ipv6Addr::new(0xfc00, 0, 0, 0, 0, 0, 0, 0), 7),
        (Ipv6Addr::new(0xfe80, 0, 0, 0, 0, 0, 0, 0), 10),
        (Ipv6Addr::new(0xfec0, 0, 0, 0, 0, 0, 0, 0), 10),
        (Ipv6Addr::new(0xff00, 0, 0, 0, 0, 0, 0, 0), 8),
    ]
    .iter()
    .any(|(network, prefix)| {
        let mask = u128::MAX << (128 - (*prefix as u32));
        value & mask == u128::from(*network) & mask
    })
}

/// Selects the Zero database URL from the reserved labeled inputs only.
/// Ambient `DATABASE_URL` is deliberately not an input: unrelated process
/// environment must never steer native DB connections, so a missing labeled
/// URL fails closed instead of falling back.
fn select_database_url(
    url: Option<String>,
    source: Option<String>,
) -> Result<(String, DatabaseUrlSource), DbError> {
    let value = url
        .filter(|value| !value.trim().is_empty())
        .ok_or_else(|| {
            DbError::new(
                "zero_db_url_missing",
                "SPACEFAST_ZERO_DATABASE_URL is required for Zero DB endpoints.",
            )
        })?;
    let source = match source.as_deref().map(str::trim) {
        Some("application") => DatabaseUrlSource::Application,
        Some("provider") => DatabaseUrlSource::Provider,
        Some(_) | None => {
            return Err(DbError::new(
                "zero_db_url_invalid",
                "SPACEFAST_ZERO_DATABASE_URL_SOURCE must be application or provider.",
            ));
        }
    };
    Ok((value, source))
}

fn json_to_mysql_value(value: &Value) -> Result<MysqlValue, DbError> {
    Ok(match value {
        Value::Null => MysqlValue::NULL,
        Value::Bool(value) => MysqlValue::Int(if *value { 1 } else { 0 }),
        Value::Number(value) => {
            if let Some(value) = value.as_i64() {
                MysqlValue::Int(value)
            } else if let Some(value) = value.as_u64() {
                MysqlValue::UInt(value)
            } else if let Some(value) = value.as_f64() {
                MysqlValue::Double(value)
            } else {
                return Err(DbError::new(
                    "zero_db_param_invalid",
                    "Zero DB parameter number is invalid.",
                ));
            }
        }
        Value::String(value) => MysqlValue::Bytes(value.as_bytes().to_vec()),
        Value::Array(_) | Value::Object(_) => {
            return Err(DbError::new(
                "zero_db_param_invalid",
                "Zero DB parameters must be scalar JSON values.",
            ));
        }
    })
}

fn row_to_json(row: &Row) -> Result<Value, DbError> {
    let mut object = BTreeMap::new();
    for (index, column) in row.columns_ref().iter().enumerate() {
        let name = column.name_str().into_owned();
        let value = row.as_ref(index).ok_or_else(|| {
            DbError::new(
                "zero_db_row_invalid",
                "Zero DB row could not be converted to JSON.",
            )
        })?;
        object.insert(name, mysql_value_to_json(value)?);
    }
    Ok(json!(object))
}

fn mysql_value_to_json(value: &MysqlValue) -> Result<Value, DbError> {
    Ok(match value {
        MysqlValue::NULL => Value::Null,
        MysqlValue::Bytes(value) => String::from_utf8(value.clone())
            .map(Value::String)
            .unwrap_or_else(|_| {
                Value::String(base64::engine::general_purpose::STANDARD.encode(value))
            }),
        MysqlValue::Int(value) => json!(value),
        MysqlValue::UInt(value) => json!(value),
        MysqlValue::Float(value) => json!(value),
        MysqlValue::Double(value) => json!(value),
        MysqlValue::Date(year, month, day, hour, minute, second, micros) => json!(format!(
            "{year:04}-{month:02}-{day:02}T{hour:02}:{minute:02}:{second:02}.{micros:06}Z"
        )),
        MysqlValue::Time(is_negative, days, hours, minutes, seconds, micros) => {
            let sign = if *is_negative { "-" } else { "" };
            json!(format!(
                "{sign}{days} {hours:02}:{minutes:02}:{seconds:02}.{micros:06}"
            ))
        }
    })
}

/// The refusal value both brokers raise. In-band and typed: the caller is
/// tenant code that must be able to branch on a stable code, not read a
/// transport status it never sees.
#[derive(Debug)]
pub(crate) struct DbError {
    code: &'static str,
    message: String,
}

impl DbError {
    pub(crate) fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }

    /// The wire refusal envelope, parsed by both the PHP relay and the JS
    /// bridge — one shape, defined once.
    pub(crate) fn refusal_json(&self) -> String {
        json!({
            "ok": false,
            "code": self.code,
            "message": self.message,
        })
        .to_string()
    }
}

#[cfg(test)]
mod statement_authorization_tests {
    use super::{ready_statement, set_grant, statement_is_mutation, DbGrant, DbStatement};

    #[test]
    fn classifies_only_known_non_executing_sql_as_read() {
        for sql in [
            "SELECT 1",
            "/* ordinary comment */ -- another comment\n SHOW TABLES",
            "DESCRIBE notes",
            "EXPLAIN SELECT * FROM notes",
        ] {
            assert!(!statement_is_mutation(sql), "expected read: {sql}");
        }

        for sql in [
            "INSERT INTO notes (body) VALUES ('write')",
            "WITH note AS (SELECT 1) SELECT * FROM note",
            "/*!50000 DELETE FROM notes */",
            "EXPLAIN ANALYZE SELECT * FROM notes",
            "SELECT 1 INTO OUTFILE '/tmp/notes'",
            "--not-a-comment\nSELECT 1",
        ] {
            assert!(statement_is_mutation(sql), "expected mutation: {sql}");
        }
    }

    #[test]
    fn authorizes_the_sql_statement_instead_of_the_callers_result_shape() {
        set_grant(Some(DbGrant {
            read: true,
            write: false,
        }));

        let read_statement = DbStatement {
            sql: "SELECT 1".into(),
            params: vec![],
            mode: Some("query".into()),
        };
        let disguised_write_statement = DbStatement {
            sql: "INSERT INTO notes (body) VALUES ('should-not-land')".into(),
            params: vec![],
            mode: Some("query".into()),
        };
        let read = ready_statement(&read_statement);
        let disguised_write = ready_statement(&disguised_write_statement);

        set_grant(None);
        assert!(read.is_ok());
        assert_eq!(
            disguised_write.err().map(|error| error.code),
            Some("zero_db_capability_denied")
        );
    }
}

#[cfg(test)]
mod database_url_tests {
    use std::net::IpAddr;

    use super::{
        database_address_allowed, database_opts_from_url, select_database_url, DatabaseUrlSource,
    };

    #[test]
    fn fails_closed_without_the_labeled_url_and_never_reads_ambient_database_url() {
        // Ambient DATABASE_URL is not an input to selection at all: only the
        // reserved labeled name can supply a URL, so absence fails closed.
        assert_eq!(
            select_database_url(None, None).unwrap_err().code,
            "zero_db_url_missing"
        );
        assert_eq!(
            select_database_url(Some("   ".into()), Some("provider".into()))
                .unwrap_err()
                .code,
            "zero_db_url_missing"
        );
    }

    #[test]
    fn accepts_only_application_or_provider_source_labels() {
        let url = || Some("mysql://db.internal/app".to_string());
        assert_eq!(
            select_database_url(url(), Some("application".into())).unwrap(),
            (
                "mysql://db.internal/app".into(),
                DatabaseUrlSource::Application
            )
        );
        assert_eq!(
            select_database_url(url(), Some("provider".into()))
                .unwrap()
                .1,
            DatabaseUrlSource::Provider
        );
        assert_eq!(
            select_database_url(url(), None).unwrap_err().code,
            "zero_db_url_invalid"
        );
        assert_eq!(
            select_database_url(url(), Some("  ".into()))
                .unwrap_err()
                .code,
            "zero_db_url_invalid"
        );
        assert_eq!(
            select_database_url(url(), Some("ambient".into()))
                .unwrap_err()
                .code,
            "zero_db_url_invalid"
        );
    }

    #[test]
    fn application_urls_fail_before_dns_without_authenticated_pinned_tls() {
        for url in [
            "mysql://user:pass@db.example/app",
            "mysql://db.example/app",
            "mysql://user:pass@[2606:4700:4700::1111]/app",
        ] {
            let error = database_opts_from_url(url, DatabaseUrlSource::Application).unwrap_err();
            assert_eq!(error.code, "zero_db_tls_required", "{url}");
        }
    }

    #[test]
    fn application_driver_options_fail_before_resolution_while_provider_authority_is_separate() {
        for url in [
            "mysql://db.example/app?socket=%2Ftmp%2Fmysql.sock",
            "mysql://db.example/app?prefer_socket=true",
            "mysql://db.example/app?host=127.0.0.1",
            "mysql://db.example/app?enable_cleartext_plugin=true",
        ] {
            let error = database_opts_from_url(url, DatabaseUrlSource::Application).unwrap_err();
            assert_eq!(error.code, "zero_db_url_invalid", "{url}");
        }

        let provider = database_opts_from_url(
            "mysql://provider.internal/app?socket=%2Fvar%2Frun%2Fmysql.sock",
            DatabaseUrlSource::Provider,
        )
        .unwrap();
        assert_eq!(provider.get_socket(), Some("/var/run/mysql.sock"));
    }

    #[test]
    fn retained_database_address_policy_blocks_local_and_transition_forms() {
        let corpus: serde_json::Value = serde_json::from_str(include_str!(
            "../../../runtime/tests/fixtures/database-egress-addresses.json"
        ))
        .unwrap();
        for case in corpus.as_array().unwrap() {
            let value = case["address"].as_str().unwrap();
            assert_eq!(
                database_address_allowed(value.parse::<IpAddr>().unwrap()),
                case["allowed"].as_bool().unwrap(),
                "{value}"
            );
        }
    }
}
