use std::collections::BTreeMap;
use std::fs;
use std::path::{Component, Path, PathBuf};

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use crate::constants::{
    ENDPOINTS_INDEX_FORMAT, ENDPOINTS_INDEX_KIND, ENDPOINT_FORMAT, QUICKJS_ABI, RUNNER_ABI,
    RUN_FORMAT,
};
use crate::protocol::InvokeEnvelope;
use crate::response::{error_response, RunnerResponse};

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub(crate) enum ExecutionMode {
    Read,
    Write,
}

/// The execution mode a capsule published before the execution law never
/// declared. `runtime/engine/admin/generate.php` stamps exactly this
/// derivation onto an endpoint entry that arrives without one, and a run keeps
/// `write` — everything a run could do before the split. The serve path must
/// agree with the publish path, because both read the same frozen capsule.
pub(crate) fn derived_execution_mode(kind: &str, method: &str) -> ExecutionMode {
    if kind == "run" {
        return ExecutionMode::Write;
    }
    match method.to_ascii_uppercase().as_str() {
        "GET" | "HEAD" | "OPTIONS" => ExecutionMode::Read,
        _ => ExecutionMode::Write,
    }
}

/// The artifact exactly as it sits on disk. Every field a capsule published
/// before the execution law could omit is optional here so `resolve` can tell
/// "absent" from "declared", and apply the pre-law reading rather than reject
/// a version nobody can rebuild.
#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct RawEndpointArtifact {
    format: String,
    #[serde(default)]
    endpoint_id: String,
    #[serde(default)]
    run_id: String,
    kind: String,
    #[serde(default)]
    execution_mode: Option<ExecutionMode>,
    #[serde(default)]
    method: String,
    #[serde(default)]
    path: String,
    source_path: String,
    bytecode_path: String,
    source_sha256: String,
    bytecode_sha256: String,
    runner_abi: String,
    quickjs_abi: String,
    #[serde(default)]
    capabilities: DeclaredCapabilities,
    #[serde(default)]
    db: EndpointDbMetadata,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct EndpointArtifact {
    pub format: String,
    pub endpoint_id: String,
    pub run_id: String,
    pub kind: String,
    pub execution_mode: ExecutionMode,
    pub method: String,
    pub path: String,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    pub capabilities: EndpointCapabilities,
    pub db: EndpointDbMetadata,
    /// True when the artifact declared no execution mode, which only a capsule
    /// finalized before the execution law does. Its bytes are frozen — the
    /// version is immutable and its source may be long gone — so the readings
    /// that changed under the law are applied as that capsule meant them.
    pub frozen_shape: bool,
}

impl RawEndpointArtifact {
    fn resolve(self, envelope: &InvokeEnvelope) -> EndpointArtifact {
        let frozen_shape = self.execution_mode.is_none();
        // The engine's mode is the better signal when the artifact has none:
        // it comes from the compiled route entry, or from the run operation,
        // which distinguishes a query run from a mutation run where the
        // artifact's own `POST` cannot. Fall back to the method derivation
        // only when the engine predates the law too.
        let execution_mode = self
            .execution_mode
            .or(envelope.declared_execution_mode)
            .unwrap_or_else(|| derived_execution_mode(&self.kind, &self.method));
        EndpointArtifact {
            format: self.format,
            endpoint_id: self.endpoint_id,
            run_id: self.run_id,
            kind: self.kind,
            execution_mode,
            method: self.method,
            path: self.path,
            source_path: self.source_path,
            bytecode_path: self.bytecode_path,
            source_sha256: self.source_sha256,
            bytecode_sha256: self.bytecode_sha256,
            runner_abi: self.runner_abi,
            quickjs_abi: self.quickjs_abi,
            capabilities: self.capabilities.resolve(if frozen_shape {
                EndpointCapabilities::conservative()
            } else {
                EndpointCapabilities::declared_defaults()
            }),
            db: self.db,
            frozen_shape,
        }
    }
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct EndpointIndexArtifact {
    format: String,
    #[serde(rename = "artifact_kind")]
    artifact_kind: String,
    endpoints: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub struct EndpointCapabilities {
    #[serde(default = "default_true")]
    pub db: bool,
    // The write-side authorities default closed. A read artifact that never
    // named them would otherwise inherit an open grant the execution mode
    // forbids, and `validate_for` would reject at serve time an artifact the
    // finalizer was happy to publish.
    #[serde(default)]
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
}

impl Default for EndpointCapabilities {
    fn default() -> Self {
        Self::conservative()
    }
}

impl EndpointCapabilities {
    pub(crate) fn conservative() -> Self {
        Self {
            db: true,
            fetch: true,
            auth: true,
            env: true,
            realtime: true,
            logging: true,
            gravatar: true,
            spam: true,
            email: true,
            content: false,
            storage: false,
        }
    }

    /// What an artifact compiled under the execution law gets for a capability
    /// it left out. Field for field, this is what the `#[serde(default …)]`
    /// attributes above produce, and a test holds the two together.
    pub(crate) fn declared_defaults() -> Self {
        Self {
            db: true,
            fetch: false,
            auth: true,
            env: true,
            realtime: false,
            logging: true,
            gravatar: true,
            spam: true,
            email: false,
            content: false,
            storage: false,
        }
    }

    /// Whether this handler reaches any brokered platform service. The prelude
    /// installs one bridge for all of them, so this is what gates it.
    pub(crate) fn any_service(&self) -> bool {
        self.gravatar || self.spam || self.email || self.content || self.storage
    }
}

/// The capability block exactly as written, before any default is applied.
///
/// A frozen artifact was compiled against a prelude that installed fetch,
/// realtime and email unless told otherwise, so an omission there means "open".
/// An artifact compiled under the execution law means "closed" by the same
/// omission. Telling absent from declared-false is the only way to read both
/// generations correctly, and neither can be re-finalized into the other's
/// shape.
#[derive(Debug, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
struct DeclaredCapabilities {
    db: Option<bool>,
    fetch: Option<bool>,
    auth: Option<bool>,
    env: Option<bool>,
    realtime: Option<bool>,
    logging: Option<bool>,
    gravatar: Option<bool>,
    spam: Option<bool>,
    email: Option<bool>,
    content: Option<bool>,
    storage: Option<bool>,
}

impl DeclaredCapabilities {
    /// Fill every omitted capability from `absent`, the reading this
    /// artifact's generation gives an unstated one.
    fn resolve(&self, absent: EndpointCapabilities) -> EndpointCapabilities {
        EndpointCapabilities {
            db: self.db.unwrap_or(absent.db),
            fetch: self.fetch.unwrap_or(absent.fetch),
            auth: self.auth.unwrap_or(absent.auth),
            env: self.env.unwrap_or(absent.env),
            realtime: self.realtime.unwrap_or(absent.realtime),
            logging: self.logging.unwrap_or(absent.logging),
            gravatar: self.gravatar.unwrap_or(absent.gravatar),
            spam: self.spam.unwrap_or(absent.spam),
            email: self.email.unwrap_or(absent.email),
            content: self.content.unwrap_or(absent.content),
            storage: self.storage.unwrap_or(absent.storage),
        }
    }
}

fn default_true() -> bool {
    true
}

#[derive(Debug, Default, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct EndpointDbMetadata {
    #[serde(default)]
    pub schema_hash: Option<String>,
    #[serde(default)]
    pub tables: BTreeMap<String, serde_json::Value>,
}

pub(crate) fn read_endpoint_artifact(
    path: &Path,
    envelope: &InvokeEnvelope,
) -> Result<EndpointArtifact, RunnerResponse> {
    let raw = fs::read_to_string(path)
        .map_err(|error| error_response(503, "zero_artifact_unreadable", &error.to_string()))?;
    let artifact: RawEndpointArtifact = serde_json::from_str(&raw)
        .map_err(|error| error_response(422, "zero_artifact_malformed", &error.to_string()))?;
    Ok(artifact.resolve(envelope))
}

pub(crate) fn resolve_endpoint_artifact_path(
    envelope: &InvokeEnvelope,
) -> Result<PathBuf, RunnerResponse> {
    if let Some(artifact_path) = envelope
        .artifact_path
        .as_deref()
        .filter(|value| !value.is_empty())
    {
        return resolve_version_path(&envelope.version_root, artifact_path)
            .map_err(|message| error_response(422, "zero_artifact_path_invalid", &message));
    }

    let index_path = resolve_version_path(&envelope.version_root, "zero/endpoints-index.json")
        .map_err(|message| error_response(422, "zero_endpoint_index_path_invalid", &message))?;
    let raw = fs::read_to_string(&index_path).map_err(|error| {
        error_response(503, "zero_endpoint_index_unreadable", &error.to_string())
    })?;
    let index: EndpointIndexArtifact = serde_json::from_str(&raw).map_err(|error| {
        error_response(422, "zero_endpoint_index_malformed", &error.to_string())
    })?;
    if index.format != ENDPOINTS_INDEX_FORMAT || index.artifact_kind != ENDPOINTS_INDEX_KIND {
        return Err(error_response(
            422,
            "zero_endpoint_index_invalid",
            "Zero endpoint index format is unsupported.",
        ));
    }
    let artifact_path = index.endpoints.get(&envelope.endpoint_id).ok_or_else(|| {
        error_response(
            404,
            "zero_endpoint_not_found",
            "Zero endpoint id is not present in the compiled endpoint index.",
        )
    })?;
    resolve_version_path(&envelope.version_root, artifact_path)
        .map_err(|message| error_response(422, "zero_artifact_path_invalid", &message))
}

impl EndpointArtifact {
    pub(crate) fn validate_for(&self, envelope: &InvokeEnvelope) -> Result<(), RunnerResponse> {
        if self.execution_mode != envelope.execution_mode() {
            return Err(error_response(
                422,
                "zero_artifact_mode_invalid",
                "Zero artifact mode does not match the invocation mode.",
            ));
        }
        if self.kind == "run" {
            if self.format != RUN_FORMAT {
                return Err(error_response(
                    422,
                    "zero_artifact_invalid",
                    "Run artifact format is unsupported.",
                ));
            }
            if self.run_id != envelope.endpoint_id {
                return Err(error_response(
                    422,
                    "zero_endpoint_mismatch",
                    "Run artifact does not match the invoke envelope.",
                ));
            }
        } else if self.format != ENDPOINT_FORMAT || self.kind != "endpoint" {
            return Err(error_response(
                422,
                "zero_artifact_invalid",
                "Endpoint artifact format is unsupported.",
            ));
        } else if self.endpoint_id != envelope.endpoint_id {
            return Err(error_response(
                422,
                "zero_endpoint_mismatch",
                "Endpoint artifact does not match the invoke envelope.",
            ));
        }
        if self.runner_abi != RUNNER_ABI || self.quickjs_abi != QUICKJS_ABI {
            return Err(error_response(
                422,
                "zero_artifact_abi_mismatch",
                "Endpoint artifact ABI does not match this runner.",
            ));
        }
        // A frozen artifact is exempt: it carries the open write-side grant
        // every artifact carried before the law, so this check would refuse the
        // whole generation for holding the only shape it could have been
        // published in. What a read invocation may actually DO is unchanged —
        // the service broker refuses mail, spam reports and storage writes on
        // the invocation's mode, not on the artifact's grant, and the read
        // transaction is READ ONLY at the server. This check only keeps a NEW
        // artifact from being finalized with a grant its mode contradicts.
        if !self.frozen_shape
            && self.execution_mode == ExecutionMode::Read
            && (self.capabilities.fetch || self.capabilities.email || self.capabilities.realtime)
        {
            return Err(error_response(
                422,
                "zero_artifact_mode_invalid",
                "A read handler cannot carry write-side capabilities.",
            ));
        }
        if !relative_path_valid(&self.source_path) || !relative_path_valid(&self.bytecode_path) {
            return Err(error_response(
                422,
                "zero_artifact_path_invalid",
                "Endpoint artifact paths must stay inside the version.",
            ));
        }
        Ok(())
    }

    pub(crate) fn method_matches(&self, request_method: &str) -> bool {
        if self.kind == "run" {
            return request_method.eq_ignore_ascii_case("POST");
        }
        self.method.eq_ignore_ascii_case(request_method)
            || (self.method.eq_ignore_ascii_case("GET")
                && request_method.eq_ignore_ascii_case("HEAD"))
    }

    pub(crate) fn read_verified_bytecode(&self, path: &Path) -> Result<Vec<u8>, RunnerResponse> {
        let bytecode = fs::read(path)
            .map_err(|error| error_response(503, "zero_bytecode_unreadable", &error.to_string()))?;
        verify_sha256(&bytecode, &self.bytecode_sha256)
            .map_err(|message| error_response(422, "zero_bytecode_hash_mismatch", &message))?;
        Ok(bytecode)
    }
}

pub(crate) fn resolve_version_path(
    version_root: &str,
    relative_path: &str,
) -> Result<PathBuf, String> {
    if version_root.is_empty() || !relative_path_valid(relative_path) {
        return Err("Path is invalid.".to_string());
    }
    Ok(Path::new(version_root).join(relative_path))
}

fn relative_path_valid(relative_path: &str) -> bool {
    if relative_path.is_empty() || relative_path.contains('\0') || relative_path.contains('\\') {
        return false;
    }
    let path = Path::new(relative_path);
    !path.is_absolute()
        && path
            .components()
            .all(|component| matches!(component, Component::Normal(_)))
}

pub(crate) fn sha256_hex(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}

pub(crate) fn sha256_prefixed(bytes: &[u8]) -> String {
    format!("sha256:{}", sha256_hex(bytes))
}

fn verify_sha256(bytes: &[u8], expected: &str) -> Result<(), String> {
    let actual = sha256_prefixed(bytes);
    let normalized_expected = if expected.starts_with("sha256:") {
        expected.to_string()
    } else {
        format!("sha256:{expected}")
    };
    if actual == normalized_expected {
        Ok(())
    } else {
        Err("Endpoint artifact hash does not match finalized bytes.".to_string())
    }
}
