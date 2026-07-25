use std::collections::BTreeMap;

use serde::{Deserialize, Serialize};
use serde_json::Value;

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct InvokeEnvelope {
    pub protocol: String,
    pub version_root: String,
    #[serde(default)]
    pub files_root: Option<String>,
    pub endpoint_id: String,
    #[serde(default)]
    pub artifact_path: Option<String>,
    pub request: InvokeRequest,
    pub context: InvokeContext,
    #[serde(default)]
    pub auth: Value,
    #[serde(default)]
    pub variables: BTreeMap<String, String>,
}

#[derive(Debug, Deserialize, Serialize)]
pub(crate) struct InvokeRequest {
    pub method: String,
    pub path: String,
    pub uri: String,
    pub host: String,
    #[serde(default)]
    pub origin: String,
    pub query: String,
    pub headers: BTreeMap<String, String>,
    #[serde(default)]
    pub params: BTreeMap<String, String>,
    #[serde(rename = "bodyBase64")]
    pub body_base64: String,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct InvokeContext {
    pub space_id: String,
    pub version_id: String,
    #[serde(default)]
    pub schema_hash: Option<String>,
    pub auth_ref: String,
    pub variables_ref: String,
}
