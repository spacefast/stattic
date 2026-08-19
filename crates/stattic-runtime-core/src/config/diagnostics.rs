//! Preparation diagnostics shared by the config compilers. The prepare
//! pipeline that surfaces these to callers arrives in a later stage.

use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct PrepareDiagnostic {
    pub severity: DiagnosticSeverity,
    pub code: String,
    pub message: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub path: Option<String>,
    #[serde(default, skip_serializing_if = "Map::is_empty")]
    pub details: Map<String, Value>,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum DiagnosticSeverity {
    Info,
    Warning,
    Error,
}

pub fn diagnostic(
    severity: DiagnosticSeverity,
    code: impl Into<String>,
    message: String,
    path: Option<String>,
) -> PrepareDiagnostic {
    PrepareDiagnostic {
        severity,
        code: code.into(),
        message,
        path,
        details: Map::new(),
    }
}
