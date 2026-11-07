use serde::{Deserialize, Serialize};

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub(crate) enum ExecutionMode {
    Read,
    Write,
    /// No invocation transaction: every statement autocommits on a pooled
    /// connection, and only reads are allowed. A handler that calls out to the
    /// network gets to do so without a write transaction open behind it.
    Action,
}
