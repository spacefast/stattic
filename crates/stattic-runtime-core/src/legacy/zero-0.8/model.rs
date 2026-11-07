use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::collections::BTreeMap;

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum ZeroExecutionMode {
    Read,
    Write,
    /// Reads the database outside any invocation transaction, reaches the
    /// network, and emits effects on success. What a handler that talks to the
    /// outside world needs, without holding a write transaction open across it.
    Action,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeZeroEndpoint {
    pub execution_mode: ZeroExecutionMode,
    pub method: String,
    pub path: String,
    pub source: String,
    #[serde(default)]
    pub endpoint_id: Option<String>,
    #[serde(default)]
    pub schema_hash: Option<String>,
    #[serde(default)]
    pub capabilities: ZeroCapabilities,
    /// Variables the handler names as `ctx.jwt` / `ctx.crypto` keys.
    #[serde(default)]
    pub crypto_keys: Vec<String>,
    #[serde(default)]
    pub db: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeZeroRun {
    pub execution_mode: ZeroExecutionMode,
    pub run_id: String,
    pub source: String,
    #[serde(default)]
    pub schema_hash: Option<String>,
    #[serde(default)]
    pub capabilities: ZeroCapabilities,
    /// Variables the handler names as `ctx.jwt` / `ctx.crypto` keys.
    #[serde(default)]
    pub crypto_keys: Vec<String>,
    #[serde(default)]
    pub db: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(
    tag = "action",
    rename_all = "snake_case",
    rename_all_fields = "camelCase"
)]
pub enum PhpActionRecord {
    InvokeZero {
        pattern: String,
        method: String,
        execution_mode: ZeroExecutionMode,
        endpoint_id: String,
        zero_artifact: String,
        #[serde(skip_serializing_if = "Option::is_none")]
        schema_hash: Option<String>,
        capabilities: ZeroCapabilities,
    },
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ZeroCapabilities {
    #[serde(default = "default_true")]
    pub db: bool,
    // Outbound fetch is a global in every handler kind, on both tiers: what a
    // request may reach is decided by the space's egress scope at request time,
    // not by a per-version declaration. The remaining write-side authorities
    // still default closed, so an omitted field cannot hand a `read` handler a
    // grant its execution mode forbids.
    #[serde(default = "default_true")]
    pub fetch: bool,
    #[serde(default = "default_true")]
    pub auth: bool,
    #[serde(default = "default_true")]
    pub env: bool,
    #[serde(default)]
    pub realtime: bool,
    #[serde(default = "default_true")]
    pub logging: bool,
    #[serde(default = "default_true")]
    pub gravatar: bool,
    #[serde(default = "default_true")]
    pub spam: bool,
    #[serde(default)]
    pub email: bool,
    #[serde(default)]
    pub content: bool,
    #[serde(default)]
    pub storage: bool,
    #[serde(default)]
    pub connectors: bool,
    #[serde(default)]
    pub crypto: bool,
}

impl Default for ZeroCapabilities {
    fn default() -> Self {
        Self {
            db: true,
            fetch: true,
            auth: true,
            env: true,
            realtime: false,
            logging: true,
            gravatar: true,
            spam: true,
            email: false,
            content: false,
            storage: false,
            connectors: false,
            crypto: false,
        }
    }
}

fn default_true() -> bool {
    true
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ZeroEndpointArtifact {
    pub format: String,
    pub endpoint_id: String,
    pub kind: String,
    pub execution_mode: ZeroExecutionMode,
    pub method: String,
    pub path: String,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    pub db_capability_abi: String,
    pub capabilities: ZeroCapabilities,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub crypto_keys: Vec<String>,
    pub db: Value,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ZeroRunArtifact {
    pub format: String,
    pub run_id: String,
    pub kind: String,
    pub execution_mode: ZeroExecutionMode,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    pub db_capability_abi: String,
    pub capabilities: ZeroCapabilities,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub crypto_keys: Vec<String>,
    pub db: Value,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ZeroRoutesArtifact {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_schema: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_engine_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub generated_at: Option<String>,
    pub format: String,
    #[serde(rename = "artifact_kind")]
    pub artifact_kind: String,
    pub exact: Vec<ZeroRouteEntry>,
    pub by_first_segment: BTreeMap<String, Vec<ZeroRouteEntry>>,
    pub fallback: Vec<ZeroRouteEntry>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ZeroMigrationsArtifact {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_schema: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_engine_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub generated_at: Option<String>,
    pub format: String,
    #[serde(rename = "artifact_kind")]
    pub artifact_kind: String,
    pub statements: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ZeroEndpointIndexArtifact {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_schema: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_engine_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub generated_at: Option<String>,
    pub format: String,
    #[serde(rename = "artifact_kind")]
    pub artifact_kind: String,
    pub endpoints: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ZeroRunIndexArtifact {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_schema: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub runtime_engine_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub generated_at: Option<String>,
    pub format: String,
    #[serde(rename = "artifact_kind")]
    pub artifact_kind: String,
    pub runs: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ZeroRouteEntry {
    pub method: String,
    pub execution_mode: ZeroExecutionMode,
    pub pattern: String,
    pub endpoint_id: String,
    pub artifact: String,
    pub capabilities: ZeroCapabilities,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub schema_hash: Option<String>,
}
