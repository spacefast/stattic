use serde_json::json;

pub const DB_OPERATION_MAX_BYTES: usize = 64 * 1024;
pub const DB_PARAM_MAX_COUNT: usize = 256;
pub const DB_TRANSACTION_MAX_STATEMENTS: usize = 64;

// Without a cap an unbounded SELECT OOMs the process instead of returning a
// named error the tenant can handle. The byte ceiling mirrors
// EXECUTION_OUTPUT_BYTES_MAX in `stattic-runtime-core/src/protocol.rs`.
pub const DB_RESULT_ROWS_MAX: usize = 50000;
pub(crate) const DB_RESULT_BYTES_MAX: usize = 10_485_760;

// sql_mode is written out rather than inherited: a laxer server global must not
// silently change what counts as a valid write. Applied per fresh connection.
pub const DB_SESSION_PIN: &str = "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_as_cs'\
    , SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\
    , SESSION transaction_isolation = 'REPEATABLE-READ'\
    , SESSION time_zone = '+00:00'";

/// The refusal value every broker raises. In-band and typed: the caller is
/// tenant code that must be able to branch on a stable code, not read a
/// transport status it never sees.
#[derive(Debug)]
pub(crate) struct BrokerRefusal {
    pub(crate) code: &'static str,
    pub(crate) message: String,
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
}
