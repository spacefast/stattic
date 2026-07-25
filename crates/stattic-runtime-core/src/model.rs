use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::collections::BTreeMap;
use std::io;
use std::path::PathBuf;

pub const BUILD_INPUT_FORMAT: &str = "stattic.runtime.build.input.v1";
pub const FINALIZE_INPUT_FORMAT: &str = "stattic.runtime.finalize.input.v1";
pub const SITE_FINALIZE_INPUT_FORMAT: &str = "stattic.runtime.finalize.input.v2";
pub const SITE_FINALIZE_OUTPUT_FORMAT: &str = "stattic.runtime.finalize.output.v2";
pub const OUTPUT_FORMAT: &str = "stattic.runtime.compile.output.v1";
pub const PHP_MANIFEST_FORMAT: &str = "stattic.php.manifest.v1";

/// The filesystem-rooted finalize input (`stattic.runtime.finalize.input.v2`).
/// Rust walks, commits, and writes under `version_root` itself; the v1 wire
/// fields that pre-parsed file lists, convention text, and routing buckets in
/// PHP are gone.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct SiteFinalizeInput {
    pub format: String,
    /// The private storage root: `spaces/<spaceId>/versions/<versionId>` is
    /// created (immutably) under it and `runtime/uploads/<uploadId>/files`
    /// is read from it.
    pub version_root: String,
    pub space_id: String,
    pub version_id: String,
    #[serde(default)]
    pub upload_id: Option<String>,
    #[serde(default)]
    pub previous_pack: Option<String>,
    pub generated_at: String,
    pub ready_at: i64,
    #[serde(default)]
    pub session: Value,
    #[serde(default)]
    pub body: Value,
    #[serde(default)]
    pub zero_endpoints: Vec<RuntimeZeroEndpoint>,
    #[serde(default)]
    pub zero_runs: Vec<RuntimeZeroRun>,
}

/// The result envelope of a v2 site finalize
/// (`stattic.runtime.finalize.output.v2`).
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct SiteFinalizeOutput {
    pub format: String,
    pub space_id: String,
    pub version_id: String,
    pub file_count: usize,
    pub zero_endpoint_count: usize,
    /// File-lane access rules generated from raw `_headers` Basic-Auth.
    /// Callers merge these after authored file rules so explicit allow rules
    /// retain first-match precedence.
    pub access_rules: Vec<Value>,
    /// Bcrypt verifier hashes referenced by `access_rules`; plaintext never
    /// crosses the native finalizer output boundary.
    pub access_secrets: BTreeMap<String, String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub manifest: Option<Vec<Value>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub manifest_path: Option<String>,
    pub diagnostics: Vec<RuntimeDiagnostic>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeBuildInput {
    pub format: String,
    pub source_root: String,
    #[serde(default)]
    pub version_id: Option<String>,
    #[serde(default)]
    pub files: Vec<RuntimeFile>,
    #[serde(default)]
    pub convention_files: RuntimeConventionFiles,
    #[serde(default)]
    pub config: Option<Value>,
    #[serde(default)]
    pub artifact_metadata: Option<Value>,
    #[serde(default)]
    pub redirects_exact: BTreeMap<String, Vec<Value>>,
    #[serde(default)]
    pub redirects_pattern: Vec<Value>,
    #[serde(default)]
    pub headers_exact: BTreeMap<String, Vec<Value>>,
    #[serde(default)]
    pub headers_pattern: Vec<Value>,
    #[serde(default)]
    pub zero_endpoints: Vec<RuntimeZeroEndpoint>,
    #[serde(default)]
    pub zero_runs: Vec<RuntimeZeroRun>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeFinalizeInput {
    pub format: String,
    pub version_root: String,
    #[serde(default)]
    pub previous_pack: Option<String>,
    #[serde(default)]
    pub version_id: Option<String>,
    #[serde(default)]
    pub files: Vec<RuntimeFile>,
    #[serde(default)]
    pub convention_files: RuntimeConventionFiles,
    #[serde(default)]
    pub config: Option<Value>,
    #[serde(default)]
    pub artifact_metadata: Option<Value>,
    #[serde(default)]
    pub redirects_exact: BTreeMap<String, Vec<Value>>,
    #[serde(default)]
    pub redirects_pattern: Vec<Value>,
    #[serde(default)]
    pub headers_exact: BTreeMap<String, Vec<Value>>,
    #[serde(default)]
    pub headers_pattern: Vec<Value>,
    #[serde(default)]
    pub zero_endpoints: Vec<RuntimeZeroEndpoint>,
    #[serde(default)]
    pub zero_runs: Vec<RuntimeZeroRun>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeFile {
    pub path: String,
    pub size: u64,
    #[serde(default)]
    pub sha256: Option<String>,
    #[serde(default)]
    pub content_type: Option<String>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeConventionFiles {
    #[serde(default)]
    pub redirects: Option<String>,
    #[serde(default)]
    pub headers: Option<String>,
    #[serde(default)]
    pub routes: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeZeroEndpoint {
    pub method: String,
    pub path: String,
    pub source: String,
    #[serde(default)]
    pub endpoint_id: Option<String>,
    #[serde(default)]
    pub schema_hash: Option<String>,
    #[serde(default)]
    pub capabilities: ZeroCapabilities,
    #[serde(default)]
    pub db: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeZeroRun {
    pub run_id: String,
    pub source: String,
    #[serde(default)]
    pub schema_hash: Option<String>,
    #[serde(default)]
    pub capabilities: ZeroCapabilities,
    #[serde(default)]
    pub db: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeCompileOutput {
    pub format: String,
    pub mode: CompileMode,
    pub version_id: Option<String>,
    pub source_root: Option<String>,
    pub version_root: Option<String>,
    pub diagnostics: Vec<RuntimeDiagnostic>,
    pub artifacts: RuntimeArtifacts,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum CompileMode {
    Build,
    Finalize,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeArtifacts {
    pub php_manifest: PhpManifest,
    pub php_manifest_sha256: String,
    pub debug_json_sha256: String,
    #[serde(default, skip_serializing)]
    pub debug_json: Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub header_artifact: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub redirect_artifact: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub zero_routes: Option<ZeroRoutesArtifact>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub zero_migrations: Option<ZeroMigrationsArtifact>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub zero_endpoint_index: Option<ZeroEndpointIndexArtifact>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub zero_endpoint_artifacts: Vec<ZeroEndpointArtifact>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub zero_run_index: Option<ZeroRunIndexArtifact>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub zero_run_artifacts: Vec<ZeroRunArtifact>,
    #[serde(skip)]
    pub generated_zero_files: Vec<GeneratedRuntimeFile>,
    pub active: ActiveRuntimePack,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct PhpManifest {
    pub format: String,
    pub version_id: Option<String>,
    pub routes: Vec<PhpActionRecord>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(
    tag = "action",
    rename_all = "snake_case",
    rename_all_fields = "camelCase"
)]
pub enum PhpActionRecord {
    ServeStatic {
        pattern: String,
        file: String,
        #[serde(skip_serializing_if = "Option::is_none")]
        content_type: Option<String>,
        #[serde(skip_serializing_if = "Option::is_none")]
        etag: Option<String>,
    },
    Redirect {
        pattern: String,
        destination: String,
        status: u16,
        cache_control: String,
    },
    InvokeZero {
        pattern: String,
        method: String,
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
    #[serde(default = "default_true")]
    pub fetch: bool,
    #[serde(default = "default_true")]
    pub auth: bool,
    #[serde(default = "default_true")]
    pub env: bool,
    #[serde(default = "default_true")]
    pub realtime: bool,
    #[serde(default = "default_true")]
    pub logging: bool,
}

impl Default for ZeroCapabilities {
    fn default() -> Self {
        Self {
            db: true,
            fetch: true,
            auth: true,
            env: true,
            realtime: true,
            logging: true,
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
    pub method: String,
    pub path: String,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    pub source_fallback: bool,
    pub capabilities: ZeroCapabilities,
    pub db: Value,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ZeroRunArtifact {
    pub format: String,
    pub run_id: String,
    pub kind: String,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    pub source_fallback: bool,
    pub capabilities: ZeroCapabilities,
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
    pub pattern: String,
    pub endpoint_id: String,
    pub artifact: String,
    pub capabilities: ZeroCapabilities,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub schema_hash: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GeneratedRuntimeFile {
    pub path: String,
    pub bytes: Vec<u8>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ActiveRuntimePack {
    pub format: String,
    pub php_manifest_sha256: String,
    pub debug_json_sha256: String,
    pub zero_pack_sha256: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeDiagnostic {
    pub severity: RuntimeDiagnosticSeverity,
    pub code: String,
    pub message: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub path: Option<String>,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum RuntimeDiagnosticSeverity {
    Info,
    Warning,
    Error,
}

#[derive(Debug)]
pub enum RuntimeCompileError {
    InvalidFormat {
        expected: &'static str,
        actual: String,
    },
    Io {
        path: PathBuf,
        source: io::Error,
    },
    Json {
        path: PathBuf,
        source: serde_json::Error,
    },
}

impl std::fmt::Display for RuntimeCompileError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::InvalidFormat { expected, actual } => {
                write!(f, "invalid input format {actual:?}, expected {expected}")
            }
            Self::Io { path, source } => write!(f, "{}: {source}", path.display()),
            Self::Json { path, source } => write!(f, "{}: {source}", path.display()),
        }
    }
}

impl std::error::Error for RuntimeCompileError {}

pub type RuntimeCompileResult<T> = Result<T, RuntimeCompileError>;
