use std::collections::BTreeMap;

use serde::{Deserialize, Serialize};
use serde_json::Value;
use stattic_runtime_egress::EgressScope;

use crate::artifacts::ExecutionMode;

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct InvokeEnvelope {
    pub protocol: String,
    pub version_root: String,
    pub endpoint_id: String,
    pub execution_mode: ExecutionMode,
    pub artifact_path: String,
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
    /// The visitor address asserted by trusted ingress. It is intentionally
    /// not read from request headers, which are browser-controlled at the PHP
    /// boundary.
    #[serde(default)]
    pub visitor_ip: Option<String>,
    /// How far this space's code may reach: `open` once the space is claimed,
    /// `trusted` while it is anonymous. An engine that predates the scope sends
    /// none, and the serde default is the narrow one — a frozen envelope must
    /// not be the way out of the trusted list.
    #[serde(default)]
    pub egress_scope: EgressScope,
}

#[cfg(test)]
mod tests {
    use super::*;

    fn envelope(context: serde_json::Value) -> InvokeEnvelope {
        serde_json::from_value(serde_json::json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": "/versions/ver_1",
            "endpointId": "GET /api/thing",
            "executionMode": "read",
            "artifactPath": "zero/endpoints/thing.json",
            "request": {
                "method": "GET",
                "path": "/api/thing",
                "uri": "/api/thing",
                "host": "space.test",
                "query": "",
                "headers": {},
                "bodyBase64": "",
            },
            "context": context,
        }))
        .expect("envelope")
    }

    #[test]
    fn an_envelope_without_a_scope_reaches_the_trusted_list_only() {
        let base = serde_json::json!({
            "spaceId": "spc_1",
            "versionId": "ver_1",
        });
        // An engine that predates the scope sends none, and a frozen capsule is
        // not a way out of the trusted list.
        assert_eq!(
            envelope(base.clone()).context.egress_scope,
            EgressScope::Trusted
        );
        for (declared, expected) in [
            ("open", EgressScope::Open),
            ("trusted", EgressScope::Trusted),
        ] {
            let mut context = base.clone();
            context["egressScope"] = serde_json::json!(declared);
            assert_eq!(
                envelope(context).context.egress_scope,
                expected,
                "{declared}"
            );
        }
    }
}
