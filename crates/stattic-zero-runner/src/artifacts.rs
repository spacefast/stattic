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

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct EndpointArtifact {
    pub format: String,
    #[serde(default)]
    pub endpoint_id: String,
    #[serde(default)]
    pub run_id: String,
    pub kind: String,
    #[serde(default)]
    pub method: String,
    #[serde(default)]
    pub path: String,
    pub source_path: String,
    pub bytecode_path: String,
    pub source_sha256: String,
    pub bytecode_sha256: String,
    pub runner_abi: String,
    pub quickjs_abi: String,
    #[serde(default)]
    pub capabilities: EndpointCapabilities,
    #[serde(default)]
    pub db: EndpointDbMetadata,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct EndpointIndexArtifact {
    format: String,
    #[serde(rename = "artifact_kind")]
    artifact_kind: String,
    endpoints: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct EndpointCapabilities {
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
    #[serde(default = "default_true")]
    pub gravatar: bool,
    #[serde(default = "default_true")]
    pub spam: bool,
    #[serde(default = "default_true")]
    pub email: bool,
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
        }
    }

    /// Whether this handler reaches any brokered platform service. The prelude
    /// installs one bridge for all three, so this is what gates it.
    pub(crate) fn any_service(&self) -> bool {
        self.gravatar || self.spam || self.email
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

pub(crate) fn read_endpoint_artifact(path: &Path) -> Result<EndpointArtifact, RunnerResponse> {
    let raw = fs::read_to_string(path)
        .map_err(|error| error_response(503, "zero_artifact_unreadable", &error.to_string()))?;
    let artifact: EndpointArtifact = serde_json::from_str(&raw)
        .map_err(|error| error_response(422, "zero_artifact_malformed", &error.to_string()))?;
    Ok(artifact)
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
