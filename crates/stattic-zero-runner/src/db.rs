use std::cell::RefCell;
use std::collections::BTreeMap;
use std::env;
use std::time::Instant;

use base64::Engine;
use mysql::prelude::*;
use mysql::{Opts, Params, Pool, PoolConstraints, PoolOpts, PooledConn, Row, Value as MysqlValue};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use crate::artifacts::EndpointDbMetadata;
use crate::artifacts::ExecutionMode;

// Request and parameter limits shared with the parent-process PHP broker. The
// protocols differ, but both consume these values from generated code so the
// resource ceilings stay aligned.
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
struct DbStatement {
    sql: String,
    #[serde(default)]
    params: Vec<Value>,
    #[serde(default)]
    mode: Option<String>,
}

/// The complete database authority tenant bytecode can exercise. SQL and
/// physical identifiers never cross the QuickJS boundary; each logical name is
/// resolved against the finalized endpoint artifact before a statement exists.
#[derive(Debug, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
enum DbCapabilityOperation {
    Select {
        table: String,
        #[serde(default)]
        filters: Vec<DbCapabilityFilter>,
        #[serde(default)]
        order: Vec<DbCapabilityOrder>,
        #[serde(default)]
        cursor: Option<Vec<Value>>,
        #[serde(default)]
        limit: Option<u64>,
        #[serde(default)]
        offset: Option<u64>,
    },
    Count {
        table: String,
        #[serde(default)]
        filters: Vec<DbCapabilityFilter>,
    },
    Get {
        table: String,
        id: Value,
    },
    Insert {
        table: String,
        values: BTreeMap<String, Value>,
    },
    Update {
        table: String,
        id: Value,
        values: BTreeMap<String, Value>,
    },
    Delete {
        table: String,
        id: Value,
    },
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct DbCapabilityFilter {
    field: String,
    op: DbCapabilityComparison,
    value: Value,
}

#[derive(Clone, Copy, Debug, Deserialize)]
#[serde(rename_all = "lowercase")]
enum DbCapabilityComparison {
    Eq,
    Gt,
    Gte,
    Lt,
    Lte,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct DbCapabilityOrder {
    field: String,
    direction: DbCapabilityDirection,
}

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
enum DbCapabilityDirection {
    Asc,
    Desc,
}

struct ResolvedTable<'a> {
    logical_name: String,
    physical_name: &'a str,
    primary_key: &'a str,
    columns: &'a serde_json::Map<String, Value>,
    indexes: Option<&'a serde_json::Map<String, Value>>,
}

impl ResolvedTable<'_> {
    fn column(&self, logical_name: &str) -> Result<&str, BrokerRefusal> {
        self.columns
            .get(logical_name)
            .and_then(|column| column.get("physicalName"))
            .and_then(Value::as_str)
            .filter(|name| !name.is_empty())
            .ok_or_else(|| {
                BrokerRefusal::new(
                    "zero_db_capability_denied",
                    format!(
                        "Zero DB field {}.{} is not declared.",
                        self.logical_name, logical_name
                    ),
                )
            })
    }

    fn projection(&self) -> Result<String, BrokerRefusal> {
        if self.columns.is_empty() {
            return Err(BrokerRefusal::new(
                "zero_db_capability_invalid",
                format!(
                    "Zero DB table {} has no declared fields.",
                    self.logical_name
                ),
            ));
        }
        self.columns
            .iter()
            .map(|(logical, _)| {
                Ok(format!(
                    "{} AS {}",
                    quote_mysql_identifier(self.column(logical)?),
                    quote_mysql_identifier(logical)
                ))
            })
            .collect::<Result<Vec<_>, BrokerRefusal>>()
            .map(|columns| columns.join(", "))
    }
}

pub(crate) fn reset_metrics() {
    DB_METRICS.with(|metrics| {
        *metrics.borrow_mut() = DbMetrics::default();
    });
}

pub(crate) fn rollback_open_transaction() {
    DB_TRANSACTION.with(|transaction| {
        if let Some(mut transaction) = transaction.borrow_mut().take() {
            let _ = transaction.conn.query_drop("ROLLBACK");
        }
    });
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
        conn.query_drop("SET TRANSACTION READ ONLY").map_err(|_| {
            BrokerRefusal::new(
                "zero_db_transaction_start_failed",
                "The Zero DB transaction could not be started.",
            )
        })?;
        conn.query_drop("START TRANSACTION WITH CONSISTENT SNAPSHOT")
            .map_err(|_| {
                BrokerRefusal::new(
                    "zero_db_transaction_start_failed",
                    "The Zero DB transaction could not be started.",
                )
            })?;
    } else {
        conn.query_drop("START TRANSACTION").map_err(|_| {
            BrokerRefusal::new(
                "zero_db_transaction_start_failed",
                "The Zero DB transaction could not be started.",
            )
        })?;
    }
    DB_TRANSACTION.with(|transaction| {
        *transaction.borrow_mut() = Some(InvocationTransaction { conn, mode });
    });
    Ok(())
}

pub(crate) fn commit_invocation() -> Result<(), BrokerRefusal> {
    let mut transaction = DB_TRANSACTION
        .with(|state| state.borrow_mut().take())
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_transaction_missing",
                "No Zero invocation transaction is active.",
            )
        })?;
    transaction.conn.query_drop("COMMIT").map_err(|_| {
        BrokerRefusal::new(
            "zero_db_transaction_commit_failed",
            "The Zero DB transaction could not be committed.",
        )
    })
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

pub(crate) fn handle_db_capability_operation(raw: &str, metadata: &EndpointDbMetadata) -> String {
    match execute_db_capability_operation(raw, metadata) {
        Ok(value) => value.to_string(),
        Err(error) => error.refusal_json(),
    }
}

/// The metadata tenant code needs to build logical operations. Physical table
/// and column names stay on the native side of the capability boundary.
pub(crate) fn tenant_db_metadata(metadata: &EndpointDbMetadata) -> Result<Value, BrokerRefusal> {
    let mut tables = serde_json::Map::new();
    for logical_table in metadata.tables.keys() {
        let table = resolve_table(metadata, logical_table)?;
        let mut columns = serde_json::Map::new();
        for (logical_column, column) in table.columns {
            let column_type =
                column
                    .get("type")
                    .and_then(Value::as_str)
                    .unwrap_or(if logical_column == "id" {
                        "id"
                    } else {
                        "string"
                    });
            columns.insert(
                logical_column.clone(),
                json!({ "name": logical_column, "type": column_type }),
            );
        }
        let indexes = table.indexes.cloned().unwrap_or_default();
        tables.insert(
            logical_table.clone(),
            json!({
                "name": logical_table,
                "primaryKey": table.primary_key,
                "columns": columns,
                "indexes": indexes,
            }),
        );
    }
    Ok(json!({
        "schemaHash": metadata.schema_hash,
        "tables": tables,
    }))
}

fn execute_db_capability_operation(
    raw: &str,
    metadata: &EndpointDbMetadata,
) -> Result<Value, BrokerRefusal> {
    if raw.len() > DB_OPERATION_MAX_BYTES {
        return Err(BrokerRefusal::new(
            "zero_db_operation_too_large",
            "Zero DB operation exceeded the request size limit.",
        ));
    }
    let operation: DbCapabilityOperation = serde_json::from_str(raw).map_err(|_| {
        BrokerRefusal::new(
            "zero_db_capability_invalid",
            "Zero DB accepts only declared structured operations.",
        )
    })?;
    let statement = capability_statement(operation, metadata)?;
    let ready = ready_statement(&statement)?;
    with_invocation_conn(|conn| run_ready_statement(conn, ready))
}

fn capability_statement(
    operation: DbCapabilityOperation,
    metadata: &EndpointDbMetadata,
) -> Result<DbStatement, BrokerRefusal> {
    match operation {
        DbCapabilityOperation::Select {
            table,
            filters,
            order,
            cursor,
            limit,
            offset,
        } => select_statement(metadata, &table, filters, order, cursor, limit, offset),
        DbCapabilityOperation::Count { table, filters } => {
            let table = resolve_table(metadata, &table)?;
            let (where_sql, params) = capability_filters(&table, filters)?;
            Ok(DbStatement {
                sql: format!(
                    "SELECT COUNT(*) AS `count` FROM {}{where_sql}",
                    quote_mysql_identifier(table.physical_name)
                ),
                params,
                mode: None,
            })
        }
        DbCapabilityOperation::Get { table, id } => {
            let table = resolve_table(metadata, &table)?;
            let key = table.column(table.primary_key)?;
            Ok(DbStatement {
                sql: format!(
                    "SELECT {} FROM {} WHERE {} = ? LIMIT 1",
                    table.projection()?,
                    quote_mysql_identifier(table.physical_name),
                    quote_mysql_identifier(key)
                ),
                params: vec![id],
                mode: None,
            })
        }
        DbCapabilityOperation::Insert { table, values } => {
            let table = resolve_table(metadata, &table)?;
            if values.is_empty() {
                return Err(invalid_capability_operation(
                    "Zero DB insert values must not be empty.",
                ));
            }
            let columns = values
                .keys()
                .map(|field| table.column(field).map(quote_mysql_identifier))
                .collect::<Result<Vec<_>, _>>()?;
            let placeholders = vec!["?"; columns.len()].join(", ");
            Ok(DbStatement {
                sql: format!(
                    "INSERT INTO {} ({}) VALUES ({placeholders})",
                    quote_mysql_identifier(table.physical_name),
                    columns.join(", ")
                ),
                params: values.into_values().collect(),
                mode: Some("execute".to_string()),
            })
        }
        DbCapabilityOperation::Update { table, id, values } => {
            let table = resolve_table(metadata, &table)?;
            if values.is_empty() {
                return Err(invalid_capability_operation(
                    "Zero DB update values must not be empty.",
                ));
            }
            let assignments = values
                .keys()
                .map(|field| {
                    Ok(format!(
                        "{} = ?",
                        quote_mysql_identifier(table.column(field)?)
                    ))
                })
                .collect::<Result<Vec<_>, BrokerRefusal>>()?;
            let key = table.column(table.primary_key)?;
            let mut params = values.into_values().collect::<Vec<_>>();
            params.push(id);
            Ok(DbStatement {
                sql: format!(
                    "UPDATE {} SET {} WHERE {} = ?",
                    quote_mysql_identifier(table.physical_name),
                    assignments.join(", "),
                    quote_mysql_identifier(key)
                ),
                params,
                mode: Some("execute".to_string()),
            })
        }
        DbCapabilityOperation::Delete { table, id } => {
            let table = resolve_table(metadata, &table)?;
            let key = table.column(table.primary_key)?;
            Ok(DbStatement {
                sql: format!(
                    "DELETE FROM {} WHERE {} = ?",
                    quote_mysql_identifier(table.physical_name),
                    quote_mysql_identifier(key)
                ),
                params: vec![id],
                mode: Some("execute".to_string()),
            })
        }
    }
}

fn select_statement(
    metadata: &EndpointDbMetadata,
    logical_table: &str,
    filters: Vec<DbCapabilityFilter>,
    order: Vec<DbCapabilityOrder>,
    cursor: Option<Vec<Value>>,
    limit: Option<u64>,
    offset: Option<u64>,
) -> Result<DbStatement, BrokerRefusal> {
    let table = resolve_table(metadata, logical_table)?;
    if limit.is_some_and(|value| value > 1001) {
        return Err(invalid_capability_operation(
            "Zero DB select limit exceeds 1001 rows.",
        ));
    }
    if cursor.is_some() && (order.is_empty() || offset.is_some()) {
        return Err(invalid_capability_operation(
            "Zero DB cursors require an order and cannot use an offset.",
        ));
    }
    let (base_where, mut params) = capability_filters(&table, filters)?;
    let mut conditions = base_where
        .strip_prefix(" WHERE ")
        .map(str::to_string)
        .into_iter()
        .collect::<Vec<_>>();
    if let Some(keys) = cursor {
        if keys.len() != order.len() {
            return Err(invalid_capability_operation(
                "Zero DB cursor does not match the operation order.",
            ));
        }
        let (cursor_sql, cursor_params) = capability_cursor(&table, &order, keys)?;
        conditions.push(cursor_sql);
        params.extend(cursor_params);
    }
    let order_sql = if order.is_empty() {
        String::new()
    } else {
        let fields = order
            .iter()
            .map(|entry| {
                Ok(format!(
                    "{} {}",
                    quote_mysql_identifier(table.column(&entry.field)?),
                    direction_sql(entry.direction)
                ))
            })
            .collect::<Result<Vec<_>, BrokerRefusal>>()?;
        format!(" ORDER BY {}", fields.join(", "))
    };
    let where_sql = if conditions.is_empty() {
        String::new()
    } else {
        format!(" WHERE {}", conditions.join(" AND "))
    };
    // MySQL does not accept a bare OFFSET. Its documented maximum unsigned
    // LIMIT preserves the public offset-only builder semantics without
    // introducing a lower native cap.
    let limit_sql = match (limit, offset) {
        (Some(value), _) => format!(" LIMIT {value}"),
        (None, Some(_)) => " LIMIT 18446744073709551615".to_string(),
        (None, None) => String::new(),
    };
    let offset_sql = offset.map_or_else(String::new, |value| format!(" OFFSET {value}"));
    Ok(DbStatement {
        sql: format!(
            "SELECT {} FROM {}{where_sql}{order_sql}{limit_sql}{offset_sql}",
            table.projection()?,
            quote_mysql_identifier(table.physical_name)
        ),
        params,
        mode: None,
    })
}

fn capability_filters(
    table: &ResolvedTable<'_>,
    filters: Vec<DbCapabilityFilter>,
) -> Result<(String, Vec<Value>), BrokerRefusal> {
    let mut clauses = Vec::with_capacity(filters.len());
    let mut params = Vec::with_capacity(filters.len());
    for filter in filters {
        let column = quote_mysql_identifier(table.column(&filter.field)?);
        if filter.value.is_null() {
            if matches!(filter.op, DbCapabilityComparison::Eq) {
                clauses.push(format!("{column} IS NULL"));
                continue;
            }
            return Err(invalid_capability_operation(
                "Zero DB range filters cannot compare against null.",
            ));
        }
        let operator = match filter.op {
            DbCapabilityComparison::Eq => "=",
            DbCapabilityComparison::Gt => ">",
            DbCapabilityComparison::Gte => ">=",
            DbCapabilityComparison::Lt => "<",
            DbCapabilityComparison::Lte => "<=",
        };
        clauses.push(format!("{column} {operator} ?"));
        params.push(filter.value);
    }
    Ok((
        if clauses.is_empty() {
            String::new()
        } else {
            format!(" WHERE {}", clauses.join(" AND "))
        },
        params,
    ))
}

fn capability_cursor(
    table: &ResolvedTable<'_>,
    order: &[DbCapabilityOrder],
    keys: Vec<Value>,
) -> Result<(String, Vec<Value>), BrokerRefusal> {
    let mut branches = Vec::with_capacity(order.len());
    let mut params = Vec::new();
    for index in 0..order.len() {
        let mut parts = Vec::new();
        let mut branch_params = Vec::new();
        for prefix in 0..index {
            let column = quote_mysql_identifier(table.column(&order[prefix].field)?);
            if keys[prefix].is_null() {
                parts.push(format!("{column} IS NULL"));
            } else {
                parts.push(format!("{column} = ?"));
                branch_params.push(keys[prefix].clone());
            }
        }
        let column = quote_mysql_identifier(table.column(&order[index].field)?);
        if keys[index].is_null() {
            if order[index].direction == DbCapabilityDirection::Asc {
                parts.push(format!("{column} IS NOT NULL"));
            } else {
                continue;
            }
        } else if order[index].direction == DbCapabilityDirection::Desc {
            parts.push(format!("({column} < ? OR {column} IS NULL)"));
            branch_params.push(keys[index].clone());
        } else {
            parts.push(format!("{column} > ?"));
            branch_params.push(keys[index].clone());
        }
        branches.push(format!("({})", parts.join(" AND ")));
        params.extend(branch_params);
    }
    Ok((
        if branches.is_empty() {
            "0 = 1".to_string()
        } else {
            format!("({})", branches.join(" OR "))
        },
        params,
    ))
}

fn resolve_table<'a>(
    metadata: &'a EndpointDbMetadata,
    logical_name: &str,
) -> Result<ResolvedTable<'a>, BrokerRefusal> {
    let table = metadata
        .tables
        .get(logical_name)
        .and_then(Value::as_object)
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_capability_denied",
                format!("Zero DB table {logical_name} is not declared."),
            )
        })?;
    let physical_name = table
        .get("physicalName")
        .and_then(Value::as_str)
        .filter(|name| !name.is_empty())
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_capability_invalid",
                format!("Zero DB table {logical_name} has no physical binding."),
            )
        })?;
    let columns = table
        .get("columns")
        .and_then(Value::as_object)
        .ok_or_else(|| {
            BrokerRefusal::new(
                "zero_db_capability_invalid",
                format!("Zero DB table {logical_name} has invalid fields."),
            )
        })?;
    Ok(ResolvedTable {
        logical_name: logical_name.to_string(),
        physical_name,
        primary_key: table
            .get("primaryKey")
            .and_then(Value::as_str)
            .unwrap_or("id"),
        columns,
        indexes: table.get("indexes").and_then(Value::as_object),
    })
}

fn quote_mysql_identifier(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

fn direction_sql(direction: DbCapabilityDirection) -> &'static str {
    match direction {
        DbCapabilityDirection::Asc => "ASC",
        DbCapabilityDirection::Desc => "DESC",
    }
}

fn invalid_capability_operation(message: impl Into<String>) -> BrokerRefusal {
    BrokerRefusal::new("zero_db_capability_invalid", message)
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
        run(conn).map_err(|_| {
            BrokerRefusal::new(
                "email_outbox_unavailable",
                "The email outbox could not be updated.",
            )
        })
    })
    .map_err(|error| error.message)
}

fn connect_db() -> Result<PooledConn, BrokerRefusal> {
    let connect_started = Instant::now();
    let conn = DB_POOL.with(|state| {
        let mut state = state.borrow_mut();
        if state.is_none() {
            let opts = database_opts()?;
            *state = Some(Pool::new(opts).map_err(|_| {
                BrokerRefusal::new(
                    "zero_db_connect_failed",
                    "The Zero DB connection could not be established.",
                )
            })?);
        }
        state
            .as_ref()
            .expect("pool constructed above")
            .get_conn()
            .map_err(|_| {
                BrokerRefusal::new(
                    "zero_db_connect_failed",
                    "The Zero DB connection could not be established.",
                )
            })
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

/// Everything that can be decided without a connection: the host-generated SQL
/// shape, parameter marshalling, and the invocation's read/write grant. Logical
/// table and field authority has already been resolved from finalized metadata
/// before a statement reaches this point.
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
        conn.exec_drop(sql, params).map_err(|_| {
            BrokerRefusal::new(
                "zero_db_execute_failed",
                "The Zero DB write could not be completed.",
            )
        })?;
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
    let mut result = conn.exec_iter(sql, params).map_err(|_| {
        BrokerRefusal::new(
            "zero_db_query_failed",
            "The Zero DB query could not be completed.",
        )
    })?;
    let mut rows_json: Vec<Value> = Vec::new();
    let mut encoded_bytes: usize = 0;
    for row in result.by_ref() {
        let row = row.map_err(|_| {
            BrokerRefusal::new(
                "zero_db_query_failed",
                "The Zero DB query could not be completed.",
            )
        })?;
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
