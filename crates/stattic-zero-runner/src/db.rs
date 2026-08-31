use std::cell::RefCell;
use std::env;
use std::time::Instant;

use base64::Engine;
use mysql::prelude::*;
use mysql::{Opts, Params, Pool, PoolConstraints, PoolOpts, PooledConn, Row, Value as MysqlValue};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use crate::artifacts::ExecutionMode;

// The tenant-facing operation shape. `shared/db-broker.php` enforces the same
// numbers under the same names; it consumes them from the generated protocol
// file rather than restating them, so this declaration is the only authority.
pub const DB_OPERATION_MAX_BYTES: usize = 64 * 1024;
pub const DB_PARAM_MAX_COUNT: usize = 256;
pub const DB_TRANSACTION_MAX_STATEMENTS: usize = 64;

// Without a cap an unbounded SELECT OOMs the process instead of returning a
// named error the tenant can handle. The byte ceiling mirrors
// EXECUTION_OUTPUT_BYTES_MAX in `stattic-runtime-core/src/protocol.rs`.
pub const DB_RESULT_ROWS_MAX: usize = 50000;
const DB_RESULT_BYTES_MAX: usize = 10_485_760;

// sql_mode is written out rather than inherited: a laxer server global must not
// silently change what counts as a valid write. Applied per fresh connection.
pub const DB_SESSION_PIN: &str = "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_as_cs'\
    , SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\
    , SESSION transaction_isolation = 'REPEATABLE-READ'\
    , SESSION time_zone = '+00:00'";

thread_local! {
    static DB_METRICS: RefCell<DbMetrics> = RefCell::new(DbMetrics::default());
    static DB_TRANSACTION: RefCell<Option<InvocationTransaction>> = const { RefCell::new(None) };
    // What this handler's own `transaction_begin` opened, if it called one.
    static HANDLER_TRANSACTION: RefCell<Option<HandlerTransaction>> = const { RefCell::new(None) };
    // One pool per process: statements in one invocation share a connection
    // instead of paying a handshake each.
    static DB_POOL: RefCell<Option<Pool>> = const { RefCell::new(None) };
    // Position of the next email effect within this invocation. It is the
    // second half of the outbox's idempotency key, so it must count per
    // invocation and not per process.
    static EMAIL_EFFECT_INDEX: RefCell<u32> = const { RefCell::new(0) };
}

struct InvocationTransaction {
    conn: PooledConn,
    mode: ExecutionMode,
}

/// The savepoint a handler's own transaction control runs against.
const HANDLER_SAVEPOINT: &str = "zero_handler_transaction";

/// What a bundle's `transaction_begin` actually opened.
///
/// Bundles compiled before the invocation owned a transaction bracket their own
/// work with `transaction_begin`/`transaction_commit`, and their bytecode is
/// frozen. Those calls still mean what they meant — all of it lands or none of
/// it does — but where they now sit inside the invocation's transaction they
/// have to be a savepoint in it rather than a second transaction beside it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum HandlerTransaction {
    /// A savepoint inside a transaction the invocation already owns.
    Savepoint,
    /// The transaction itself, opened because none was running — the Functions
    /// relay tier, where this is exactly what the call always did.
    Whole,
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

pub(crate) fn reset_metrics() {
    DB_METRICS.with(|metrics| {
        *metrics.borrow_mut() = DbMetrics::default();
    });
}

pub(crate) fn rollback_open_transaction() {
    set_handler_transaction(None);
    DB_TRANSACTION.with(|transaction| {
        if let Some(mut transaction) = transaction.borrow_mut().take() {
            let _ = transaction.conn.query_drop("ROLLBACK");
        }
    });
}

fn handler_transaction() -> Option<HandlerTransaction> {
    HANDLER_TRANSACTION.with(|state| *state.borrow())
}

fn set_handler_transaction(scope: Option<HandlerTransaction>) {
    HANDLER_TRANSACTION.with(|state| *state.borrow_mut() = scope);
}

pub(crate) fn begin_invocation(mode: ExecutionMode) -> Result<(), BrokerRefusal> {
    if transaction_active() {
        return Err(BrokerRefusal::new(
            "zero_db_transaction_active",
            "A Zero invocation transaction is already active.",
        ));
    }
    let mut conn = connect_db()?;
    if mode == ExecutionMode::Read {
        conn.query_drop("SET TRANSACTION READ ONLY")
            .map_err(|error| {
                BrokerRefusal::new("zero_db_transaction_start_failed", error.to_string())
            })?;
        conn.query_drop("START TRANSACTION WITH CONSISTENT SNAPSHOT")
            .map_err(|error| {
                BrokerRefusal::new("zero_db_transaction_start_failed", error.to_string())
            })?;
    } else {
        conn.query_drop("START TRANSACTION").map_err(|error| {
            BrokerRefusal::new("zero_db_transaction_start_failed", error.to_string())
        })?;
    }
    set_handler_transaction(None);
    DB_TRANSACTION.with(|transaction| {
        *transaction.borrow_mut() = Some(InvocationTransaction { conn, mode });
    });
    Ok(())
}

pub(crate) fn commit_invocation() -> Result<(), BrokerRefusal> {
    set_handler_transaction(None);
    let mut transaction = DB_TRANSACTION
        .with(|state| state.borrow_mut().take())
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_transaction_missing",
                "No Zero invocation transaction is active.",
            )
        })?;
    transaction
        .conn
        .query_drop("COMMIT")
        .map_err(|error| BrokerRefusal::new("zero_db_transaction_commit_failed", error.to_string()))
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

fn execute_db_operation(raw: &str) -> Result<Value, BrokerRefusal> {
    if raw.len() > DB_OPERATION_MAX_BYTES {
        return Err(BrokerRefusal::new(
            "zero_db_operation_too_large",
            "Zero DB operation exceeded the request size limit.",
        ));
    }
    let operation: DbOperation = serde_json::from_str(raw)
        .map_err(|error| BrokerRefusal::new("zero_db_operation_invalid", error.to_string()))?;

    // Handler-controlled transaction ops, as bundles compiled before the
    // invocation owned a transaction still emit them. Their bytecode is frozen,
    // so the ops keep working; `begin_handler_transaction` explains how.
    match operation.mode.as_deref() {
        Some("transaction_begin") => {
            return begin_handler_transaction().map(|()| json!({"ok": true}))
        }
        Some("transaction_commit") => {
            return commit_handler_transaction().map(|()| json!({"ok": true}))
        }
        Some("transaction_rollback") => {
            return rollback_handler_transaction().map(|()| json!({"ok": true}))
        }
        Some("transaction") => return run_statement_batch(&operation.statements),
        _ => {}
    }
    if !operation.statements.is_empty() {
        return run_statement_batch(&operation.statements);
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

/// Open the handler's own transaction.
///
/// Inside an invocation transaction this is a savepoint, which is the only
/// sound reading: the handler asked for all-or-nothing over the statements it
/// brackets, and that is exactly what a savepoint gives it without a second
/// transaction on a second connection that could not see the invocation's own
/// uncommitted writes. Its commit no longer decides whether the work survives —
/// the invocation's does — but a handler that runs to completion commits either
/// way, so the outcome it can observe is unchanged.
///
/// With no invocation transaction running, this opens the real thing, which is
/// what the call always did.
fn begin_handler_transaction() -> Result<(), BrokerRefusal> {
    if handler_transaction().is_some() {
        return Err(BrokerRefusal::new(
            "zero_db_transaction_active",
            "A Zero DB transaction is already active.",
        ));
    }
    if !transaction_active() {
        begin_invocation(ExecutionMode::Write)?;
        set_handler_transaction(Some(HandlerTransaction::Whole));
        return Ok(());
    }
    with_invocation_conn(|conn| {
        conn.query_drop(format!("SAVEPOINT {HANDLER_SAVEPOINT}"))
            .map_err(|error| {
                BrokerRefusal::new("zero_db_transaction_start_failed", error.to_string())
            })
    })?;
    set_handler_transaction(Some(HandlerTransaction::Savepoint));
    Ok(())
}

fn commit_handler_transaction() -> Result<(), BrokerRefusal> {
    match handler_transaction() {
        Some(HandlerTransaction::Savepoint) => {
            with_invocation_conn(|conn| {
                conn.query_drop(format!("RELEASE SAVEPOINT {HANDLER_SAVEPOINT}"))
                    .map_err(|error| {
                        BrokerRefusal::new("zero_db_transaction_commit_failed", error.to_string())
                    })
            })?;
            set_handler_transaction(None);
            Ok(())
        }
        Some(HandlerTransaction::Whole) => commit_invocation(),
        None => Err(BrokerRefusal::new(
            "zero_db_transaction_missing",
            "No Zero DB transaction is active.",
        )),
    }
}

fn rollback_handler_transaction() -> Result<(), BrokerRefusal> {
    match handler_transaction() {
        Some(HandlerTransaction::Savepoint) => {
            with_invocation_conn(|conn| {
                conn.query_drop(format!("ROLLBACK TO SAVEPOINT {HANDLER_SAVEPOINT}"))
                    .and_then(|()| {
                        conn.query_drop(format!("RELEASE SAVEPOINT {HANDLER_SAVEPOINT}"))
                    })
                    .map_err(|error| {
                        BrokerRefusal::new("zero_db_transaction_rollback_failed", error.to_string())
                    })
            })?;
            set_handler_transaction(None);
            Ok(())
        }
        Some(HandlerTransaction::Whole) => {
            rollback_open_transaction();
            Ok(())
        }
        None => Err(BrokerRefusal::new(
            "zero_db_transaction_missing",
            "No Zero DB transaction is active.",
        )),
    }
}

/// The batched form of the same thing: one bracket around every statement,
/// where a failure anywhere discards all of them.
fn run_statement_batch(statements: &[DbStatement]) -> Result<Value, BrokerRefusal> {
    if statements.is_empty() || statements.len() > DB_TRANSACTION_MAX_STATEMENTS {
        return Err(BrokerRefusal::new(
            "zero_db_transaction_invalid",
            "Zero DB transaction statements are invalid.",
        ));
    }
    // Validation and the capability check run before any connection exists: an
    // ungranted or malformed statement must be refused without touching the
    // database at all.
    let ready = statements
        .iter()
        .map(ready_statement)
        .collect::<Result<Vec<_>, _>>()?;
    begin_handler_transaction()?;
    let mut results = Vec::with_capacity(ready.len());
    for statement in ready {
        match with_invocation_conn(|conn| run_ready_statement(conn, statement)) {
            Ok(value) => results.push(value),
            Err(error) => {
                let _ = rollback_handler_transaction();
                return Err(error);
            }
        }
    }
    commit_handler_transaction()?;
    Ok(json!({ "ok": true, "results": results }))
}

/// Runs on the connection an open transaction holds, or on a pooled one when
/// there is none. Anything that must land with the handler's own writes goes
/// through here rather than reaching for a connection of its own.
pub(crate) fn with_invocation_conn<T>(
    run: impl FnOnce(&mut PooledConn) -> Result<T, BrokerRefusal>,
) -> Result<T, BrokerRefusal> {
    if transaction_active() {
        return DB_TRANSACTION.with(|transaction| {
            let mut transaction = transaction.borrow_mut();
            let transaction = transaction
                .as_mut()
                .expect("transaction presence checked on this thread");
            run(&mut transaction.conn)
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
        run(conn).map_err(|message| BrokerRefusal::new("email_outbox_unavailable", message))
    })
    .map_err(|error| error.message)
}

fn connect_db() -> Result<PooledConn, BrokerRefusal> {
    let connect_started = Instant::now();
    let conn = DB_POOL.with(|state| {
        let mut state = state.borrow_mut();
        if state.is_none() {
            let opts = database_opts()?;
            *state = Some(Pool::new(opts).map_err(|error| {
                BrokerRefusal::new("zero_db_connect_failed", error.to_string())
            })?);
        }
        state
            .as_ref()
            .expect("pool constructed above")
            .get_conn()
            .map_err(|error| BrokerRefusal::new("zero_db_connect_failed", error.to_string()))
    })?;
    record_connect(connect_started);
    Ok(conn)
}

fn transaction_active() -> bool {
    DB_TRANSACTION.with(|transaction| transaction.borrow().is_some())
}

struct ReadyStatement<'a> {
    sql: &'a str,
    params: Params,
    execute_shape: bool,
}

/// Everything that can be decided without a connection: SQL shape and
/// parameter marshalling. There is no capability mask here — the QuickJS host
/// function this engine serves is installed or withheld whole, by the
/// endpoint's compiled `db` capability, before an operation can be issued at
/// all. The read/write split lives in `shared/db-broker.php`, whose callers
/// (the Functions relay, PHP Functions, the management dump) each hold a
/// narrower grant than the credential they run under.
fn ready_statement(statement: &DbStatement) -> Result<ReadyStatement<'_>, BrokerRefusal> {
    let sql = statement.sql.trim();
    if sql.is_empty() || sql.contains('\0') {
        return Err(BrokerRefusal::new(
            "zero_db_sql_invalid",
            "Zero DB operation SQL is invalid.",
        ));
    }
    if statement.params.len() > DB_PARAM_MAX_COUNT {
        return Err(BrokerRefusal::new(
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
    let read_only = DB_TRANSACTION.with(|transaction| {
        transaction
            .borrow()
            .as_ref()
            .is_some_and(|transaction| transaction.mode == ExecutionMode::Read)
    });
    if read_only && statement_shape(sql) == StatementShape::Mutation {
        return Err(BrokerRefusal::new(
            "zero_db_read_only",
            "A Zero read handler cannot execute a database write.",
        ));
    }
    Ok(ReadyStatement {
        sql,
        params,
        execute_shape,
    })
}

/// How a statement reads to the broker, before the server ever sees it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum StatementShape {
    /// Provably a read.
    Read,
    /// Provably a write, or a read that writes a file.
    Mutation,
    /// Neither, to this classifier. A read invocation runs inside a READ ONLY
    /// transaction, so the server refuses anything here that turns out to
    /// write — and refusing it locally instead would only ever refuse
    /// statements the server would have run.
    Ambiguous,
}

/// The statement with its leading comments removed, so the first keyword is
/// the statement's own.
///
/// `-- …`, `# …` and `/* … */` all lead real SQL, and a generated query with a
/// header comment is the ordinary case. Reading the comment's first word as the
/// statement's keyword classified every one of them as a write.
fn statement_body(sql: &str) -> &str {
    let mut rest = sql.trim_start();
    loop {
        let after_line_comment = rest
            .strip_prefix("--")
            .or_else(|| rest.strip_prefix('#'))
            .map(|tail| tail.split_once('\n').map_or("", |(_, tail)| tail));
        let after_block_comment = rest
            .strip_prefix("/*")
            .map(|tail| tail.split_once("*/").map_or("", |(_, tail)| tail));
        match after_line_comment.or(after_block_comment) {
            Some(tail) => rest = tail.trim_start(),
            None => return rest,
        }
    }
}

fn statement_shape(sql: &str) -> StatementShape {
    let body = statement_body(sql).to_ascii_uppercase();
    let mut words =
        body.split(|character: char| !character.is_ascii_alphanumeric() && character != '_');
    let keyword = words.by_ref().find(|token| !token.is_empty()).unwrap_or("");
    // `SELECT … INTO OUTFILE` reads the database and writes the filesystem, so
    // a READ ONLY transaction does not stop it. This is the one write the
    // server cannot refuse for us.
    let writes_a_file = || {
        body.split(|character: char| !character.is_ascii_alphanumeric() && character != '_')
            .any(|token| token == "OUTFILE" || token == "DUMPFILE")
    };
    match keyword {
        "SELECT" | "SHOW" | "TABLE" | "VALUES" => {
            if writes_a_file() {
                StatementShape::Mutation
            } else {
                StatementShape::Read
            }
        }
        "EXPLAIN" | "DESCRIBE" | "DESC" => {
            if body.contains("ANALYZE") {
                StatementShape::Mutation
            } else {
                StatementShape::Read
            }
        }
        // A CTE's leading keyword says nothing about what follows it: `WITH …
        // SELECT` and `WITH … DELETE` are both real SQL. Frozen bundles emit
        // the read form and were refused for it, so the transaction decides.
        "WITH" => {
            if writes_a_file() {
                StatementShape::Mutation
            } else {
                StatementShape::Ambiguous
            }
        }
        // These utility statements cause an implicit commit: run inside the
        // read invocation's transaction they commit it first and then execute in
        // autocommit, where READ ONLY no longer applies, so the server does not
        // refuse the table rewrite (`OPTIMIZE`/`REPAIR`) or statistics write
        // (`ANALYZE`). They must be refused here, as the pre-classifier did.
        // Leading `ANALYZE` is distinct from `EXPLAIN ANALYZE`, which the
        // EXPLAIN/DESCRIBE/DESC arm above handles.
        "OPTIMIZE" | "ANALYZE" | "REPAIR" | "CHECK" | "CHECKSUM" | "CACHE" => {
            StatementShape::Mutation
        }
        "INSERT" | "UPDATE" | "DELETE" | "REPLACE" | "CREATE" | "DROP" | "ALTER" | "TRUNCATE"
        | "RENAME" | "GRANT" | "REVOKE" | "LOAD" | "CALL" | "DO" | "SET" | "LOCK" | "UNLOCK"
        | "START" | "BEGIN" | "COMMIT" | "ROLLBACK" | "SAVEPOINT" | "RELEASE" | "HANDLER"
        | "IMPORT" | "INSTALL" | "UNINSTALL" | "RESET" | "FLUSH" | "KILL" | "SHUTDOWN"
        | "PREPARE" | "EXECUTE" | "DEALLOCATE" | "USE" => StatementShape::Mutation,
        _ => StatementShape::Ambiguous,
    }
}

fn run_ready_statement(
    conn: &mut mysql::PooledConn,
    ready: ReadyStatement<'_>,
) -> Result<Value, BrokerRefusal> {
    let ReadyStatement {
        sql,
        params,
        execute_shape,
    } = ready;
    if execute_shape {
        let query_started = Instant::now();
        conn.exec_drop(sql, params)
            .map_err(|error| BrokerRefusal::new("zero_db_execute_failed", error.to_string()))?;
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
        .map_err(|error| BrokerRefusal::new("zero_db_query_failed", error.to_string()))?;
    let mut rows_json: Vec<Value> = Vec::new();
    let mut encoded_bytes: usize = 0;
    for row in result.by_ref() {
        let row =
            row.map_err(|error| BrokerRefusal::new("zero_db_query_failed", error.to_string()))?;
        if rows_json.len() >= rows_max {
            return Err(BrokerRefusal::new(
                "zero_db_result_too_many_rows",
                "Zero DB result exceeded the row limit.",
            ));
        }
        let value = row_to_json(&row)?;
        encoded_bytes = encoded_bytes.saturating_add(encoded_len(&value) + 1);
        if encoded_bytes > bytes_max {
            return Err(BrokerRefusal::new(
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

/// The broker holds at most this many connections to a space's database. It is
/// small on purpose: a space's database is small, several brokers can be alive
/// for one space (one per grant), and a frame only ever needs one connection.
/// The driver's own default is `min: 10, max: 100`, and the minimum is opened
/// eagerly at `Pool::new` — ten handshakes for one statement.
const DB_POOL_MAX_CONNECTIONS: usize = 2;

fn database_opts() -> Result<Opts, BrokerRefusal> {
    let (value, source) = select_database_url(
        env::var("SPACEFAST_ZERO_DATABASE_URL").ok(),
        env::var("SPACEFAST_ZERO_DATABASE_URL_SOURCE").ok(),
    )?;
    let opts = database_opts_from_url(&value, source)?;
    let constraints = PoolConstraints::new(0, DB_POOL_MAX_CONNECTIONS)
        .expect("nought is not more than the maximum");
    Ok(mysql::OptsBuilder::from_opts(opts)
        .init(vec![DB_SESSION_PIN])
        .pool_opts(PoolOpts::default().with_constraints(constraints))
        .into())
}

/// The env may lower a cap, never raise it: an unset, unparsable, zero or
/// larger-than-the-ceiling value is the ceiling.
fn configured_limit(name: &str, ceiling: usize) -> usize {
    env::var(name)
        .ok()
        .and_then(|value| value.trim().parse::<usize>().ok())
        .filter(|value| *value > 0)
        .map_or(ceiling, |value| value.min(ceiling))
}

fn db_rows_max() -> usize {
    configured_limit("SPACEFAST_ZERO_DB_ROWS_MAX", DB_RESULT_ROWS_MAX)
}

fn db_result_bytes_max() -> usize {
    configured_limit("SPACEFAST_ZERO_DB_RESULT_BYTES_MAX", DB_RESULT_BYTES_MAX)
}

fn database_opts_from_url(url: &str, source: DatabaseUrlSource) -> Result<Opts, BrokerRefusal> {
    let opts = Opts::from_url(url).map_err(|_| {
        BrokerRefusal::new("zero_db_url_invalid", "Zero DB URL could not be parsed.")
    })?;
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
        return Err(BrokerRefusal::new(
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
    Err(BrokerRefusal::new(
        "zero_db_tls_required",
        "Application Zero DB connections require pinned, hostname-verified TLS.",
    ))
}

/// Selects the Zero database URL from the reserved labeled inputs only.
/// Ambient `DATABASE_URL` is deliberately not an input: unrelated process
/// environment must never steer native DB connections, so a missing labeled
/// URL fails closed instead of falling back.
fn select_database_url(
    url: Option<String>,
    source: Option<String>,
) -> Result<(String, DatabaseUrlSource), BrokerRefusal> {
    let value = url
        .filter(|value| !value.trim().is_empty())
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_url_missing",
                "SPACEFAST_ZERO_DATABASE_URL is required for Zero DB endpoints.",
            )
        })?;
    let source = match source.as_deref().map(str::trim) {
        Some("application") => DatabaseUrlSource::Application,
        Some("provider") => DatabaseUrlSource::Provider,
        Some(_) | None => {
            return Err(BrokerRefusal::new(
                "zero_db_url_invalid",
                "SPACEFAST_ZERO_DATABASE_URL_SOURCE must be application or provider.",
            ));
        }
    };
    Ok((value, source))
}

fn json_to_mysql_value(value: &Value) -> Result<MysqlValue, BrokerRefusal> {
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
                return Err(BrokerRefusal::new(
                    "zero_db_param_invalid",
                    "Zero DB parameter number is invalid.",
                ));
            }
        }
        Value::String(value) => MysqlValue::Bytes(value.as_bytes().to_vec()),
        Value::Array(_) | Value::Object(_) => {
            return Err(BrokerRefusal::new(
                "zero_db_param_invalid",
                "Zero DB parameters must be scalar JSON values.",
            ));
        }
    })
}

fn row_to_json(row: &Row) -> Result<Value, BrokerRefusal> {
    let mut object = serde_json::Map::new();
    for (index, column) in row.columns_ref().iter().enumerate() {
        let name = column.name_str().into_owned();
        let value = row.as_ref(index).ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_row_invalid",
                "Zero DB row could not be converted to JSON.",
            )
        })?;
        object.insert(name, mysql_value_to_json(value)?);
    }
    Ok(Value::Object(object))
}

fn mysql_value_to_json(value: &MysqlValue) -> Result<Value, BrokerRefusal> {
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

/// The refusal value every broker raises. In-band and typed: the caller is
/// tenant code that must be able to branch on a stable code, not read a
/// transport status it never sees.
#[derive(Debug)]
pub(crate) struct BrokerRefusal {
    code: &'static str,
    message: String,
}

impl BrokerRefusal {
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

    pub(crate) fn runner_response(self) -> crate::response::RunnerResponse {
        crate::response::error_response(503, self.code, &self.message)
    }
}

#[cfg(test)]
mod database_url_tests {
    use super::{database_opts_from_url, select_database_url, DatabaseUrlSource};

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
}

#[cfg(test)]
mod transaction_control_tests {
    use serde_json::{json, Value};

    use super::handle_db_operation;

    fn refusal(frame: Value) -> Value {
        serde_json::from_str(&handle_db_operation(&frame.to_string())).expect("broker response")
    }

    /// Bundles compiled before the invocation owned a transaction bracket their
    /// own work, and their bytecode is frozen. The ops answer on their own
    /// terms again — the codes here are the ones that broker produced — rather
    /// than one blanket denial. Everything asserted here is decided before a
    /// connection exists, which is why it needs no database.
    #[test]
    fn handler_transaction_control_answers_on_its_own_terms() {
        for mode in ["transaction_commit", "transaction_rollback"] {
            assert_eq!(
                refusal(json!({ "mode": mode }))["code"],
                json!("zero_db_transaction_missing"),
                "{mode}"
            );
        }

        // A batch is the other way to ask for an atomic unit, and its own
        // bounds are checked before anything is dialled.
        assert_eq!(
            refusal(json!({ "mode": "transaction", "statements": [] }))["code"],
            json!("zero_db_transaction_invalid")
        );
        let oversized: Vec<Value> = (0..=super::DB_TRANSACTION_MAX_STATEMENTS)
            .map(|_| json!({ "sql": "SELECT 1" }))
            .collect();
        assert_eq!(
            refusal(json!({ "mode": "transaction", "statements": oversized }))["code"],
            json!("zero_db_transaction_invalid")
        );
        // Statement validation still runs across the whole batch first, so a
        // malformed one is refused without a connection either.
        assert_eq!(
            refusal(json!({ "statements": [{ "sql": "SELECT 1" }, { "sql": "  " }] }))["code"],
            json!("zero_db_sql_invalid")
        );
    }
}

#[cfg(test)]
mod statement_shape_tests {
    use super::{statement_shape, StatementShape};

    /// Read mode refuses what this classifier calls a write, so what it cannot
    /// classify has to fall through to the READ ONLY transaction rather than be
    /// refused. Frozen bundles emit both shapes it used to get wrong: a CTE,
    /// and a generated query carrying a header comment.
    #[test]
    fn classifies_reads_it_cannot_parse_as_the_transaction_s_problem() {
        assert_eq!(statement_shape("SELECT 1"), StatementShape::Read);
        assert_eq!(
            statement_shape("/* generated by zero */ SELECT id FROM todos"),
            StatementShape::Read
        );
        assert_eq!(
            statement_shape("-- cached lookup\nSELECT id FROM todos"),
            StatementShape::Read
        );
        assert_eq!(
            statement_shape("WITH recent AS (SELECT id FROM todos) SELECT * FROM recent"),
            StatementShape::Ambiguous
        );
        assert_eq!(
            statement_shape("INSERT INTO todos VALUES (1)"),
            StatementShape::Mutation
        );
        assert_eq!(
            statement_shape("/* sneaky */ DELETE FROM todos"),
            StatementShape::Mutation
        );
        // The one write a READ ONLY transaction does not stop, so it is refused
        // here whichever keyword leads it.
        assert_eq!(
            statement_shape("SELECT * FROM todos INTO OUTFILE '/tmp/x'"),
            StatementShape::Mutation
        );
        assert_eq!(
            statement_shape("WITH t AS (SELECT 1) SELECT * FROM t INTO DUMPFILE '/tmp/x'"),
            StatementShape::Mutation
        );
    }

    /// Utility statements that cause an implicit commit escape the READ ONLY
    /// transaction — it is committed before they run — so the server does not
    /// refuse them and they must be classified as writes here. `EXPLAIN ANALYZE`
    /// is a read and leads with `EXPLAIN`, so it is unaffected.
    #[test]
    fn refuses_implicit_commit_utility_statements_the_read_only_txn_cannot_stop() {
        assert_eq!(
            statement_shape("OPTIMIZE TABLE t"),
            StatementShape::Mutation
        );
        assert_eq!(statement_shape("ANALYZE TABLE t"), StatementShape::Mutation);
        assert_eq!(statement_shape("REPAIR TABLE t"), StatementShape::Mutation);
        assert_eq!(statement_shape("CHECK TABLE t"), StatementShape::Mutation);
        assert_eq!(
            statement_shape("CHECKSUM TABLE t"),
            StatementShape::Mutation
        );
        assert_eq!(
            statement_shape("CACHE INDEX t IN c"),
            StatementShape::Mutation
        );
        // Still a read: the `ANALYZE` keyword only writes stats when it leads.
        assert_eq!(
            statement_shape("EXPLAIN SELECT * FROM todos"),
            StatementShape::Read
        );
    }
}
