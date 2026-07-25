use std::cell::RefCell;
use std::collections::BTreeMap;
use std::env;
use std::fs;
use std::path::Path;
use std::time::Instant;

use base64::Engine;
use mysql::prelude::*;
use mysql::{Opts, Params, Pool, PooledConn, Row, Value as MysqlValue};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

const DB_OPERATION_MAX_BYTES: usize = 64 * 1024;
const DB_PARAM_MAX_COUNT: usize = 256;
const DB_TRANSACTION_MAX_STATEMENTS: usize = 64;

thread_local! {
    static DB_METRICS: RefCell<DbMetrics> = RefCell::new(DbMetrics::default());
    static DB_TRANSACTION: RefCell<Option<PooledConn>> = const { RefCell::new(None) };
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
        Err(error) => json!({
            "ok": false,
            "code": error.code,
            "message": error.message,
        })
        .to_string(),
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

    let database_url = database_url().map_err(|error| error.message)?;
    let opts = Opts::from_url(&database_url).map_err(|error| error.to_string())?;
    let pool = Pool::new(opts).map_err(|error| error.to_string())?;
    let mut conn = pool.get_conn().map_err(|error| error.to_string())?;
    for statement in artifact.statements {
        let sql = statement.trim();
        if sql.is_empty() || sql.contains('\0') {
            return Err("Zero migration statement is invalid.".to_string());
        }
        conn.query_drop(sql).map_err(|error| error.to_string())?;
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
        let mut conn = connect_db()?;
        conn.query_drop("START TRANSACTION")
            .map_err(|error| DbError::new("zero_db_transaction_start_failed", error.to_string()))?;
        let mut results = Vec::with_capacity(operation.statements.len());
        for statement in &operation.statements {
            match execute_statement(&mut conn, statement) {
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
    if transaction_active() {
        return DB_TRANSACTION.with(|transaction| {
            let mut transaction = transaction.borrow_mut();
            let conn = transaction
                .as_mut()
                .expect("transaction presence checked on this thread");
            execute_statement(conn, &statement)
        });
    }
    let mut conn = connect_db()?;
    execute_statement(&mut conn, &statement)
}

fn connect_db() -> Result<PooledConn, DbError> {
    let connect_started = Instant::now();
    let database_url = database_url()?;
    let opts = Opts::from_url(&database_url)
        .map_err(|error| DbError::new("zero_db_url_invalid", error.to_string()))?;
    let pool = Pool::new(opts)
        .map_err(|error| DbError::new("zero_db_connect_failed", error.to_string()))?;
    let conn = pool
        .get_conn()
        .map_err(|error| DbError::new("zero_db_connect_failed", error.to_string()))?;
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

fn execute_statement(
    conn: &mut mysql::PooledConn,
    statement: &DbStatement,
) -> Result<Value, DbError> {
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

    if matches!(
        statement.mode.as_deref(),
        Some("execute") | Some("exec") | Some("mutation")
    ) {
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

    let query_started = Instant::now();
    let rows = conn.exec::<Row, _, _>(sql, params);
    record_query(query_started);
    let rows = rows.map_err(|error| DbError::new("zero_db_query_failed", error.to_string()))?;
    let rows_json = rows
        .iter()
        .map(row_to_json)
        .collect::<Result<Vec<_>, _>>()?;
    Ok(json!({
        "ok": true,
        "rows": rows_json,
    }))
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
/// the platform-scoped database. Today the label is only validated (an
/// unknown value fails closed); connection-time policy differences for
/// application URLs (public-address/DNS pinning) arrive together with
/// outbound fetch.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum DatabaseUrlSource {
    Application,
    Provider,
}

fn database_url() -> Result<String, DbError> {
    let (value, _source) = select_database_url(
        env::var("SPACEFAST_ZERO_DATABASE_URL").ok(),
        env::var("SPACEFAST_ZERO_DATABASE_URL_SOURCE").ok(),
    )?;
    Ok(value)
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
        Some("provider") | Some("") | None => DatabaseUrlSource::Provider,
        Some(_) => {
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

#[derive(Debug)]
struct DbError {
    code: &'static str,
    message: String,
}

impl DbError {
    fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }
}

#[cfg(test)]
mod database_url_tests {
    use super::{select_database_url, DatabaseUrlSource};

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
        // Absent or empty labels default to the provider-scoped database.
        assert_eq!(
            select_database_url(url(), None).unwrap().1,
            DatabaseUrlSource::Provider
        );
        assert_eq!(
            select_database_url(url(), Some("  ".into())).unwrap().1,
            DatabaseUrlSource::Provider
        );
        assert_eq!(
            select_database_url(url(), Some("ambient".into()))
                .unwrap_err()
                .code,
            "zero_db_url_invalid"
        );
    }
}
