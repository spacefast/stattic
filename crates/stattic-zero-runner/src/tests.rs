use std::fs;

use serde_json::{json, Value};
use tempfile::TempDir;

use crate::artifacts::{sha256_prefixed, EndpointCapabilities};
use crate::constants::{ENDPOINT_FORMAT, QUICKJS_ABI, RUNNER_ABI};
use crate::{compile_file_with_capabilities, handle_invoke};

fn endpoint_source() -> &'static str {
    r#"
const request = globalThis.__statticZeroRequest;
const context = globalThis.__statticZeroContext;
const caps = globalThis.__statticZeroCapabilities;
globalThis.__statticZeroResult = JSON.stringify({
  status: 202,
  headers: { "content-type": "application/json; charset=utf-8", "x-runner": "rust" },
    body: JSON.stringify({
    endpointId: globalThis.__statticZeroEndpoint.endpointId,
    method: request.method,
    path: request.path,
    params: request.params,
    spaceId: context.spaceId,
    dbInstalled: typeof globalThis.__statticDb !== "undefined",
    dbCapability: caps.db === true,
    templateDb: globalThis.__statticZeroTemplateCapabilities.db === true,
    fetchInstalled: typeof globalThis.__statticFetch === "function",
    authInstalled: typeof globalThis.__statticAuth === "object",
    envInstalled: typeof globalThis.__statticEnv === "object",
    realtimeInstalled: typeof globalThis.__statticRealtime === "object",
    loggingInstalled: typeof globalThis.__statticLog === "function"
  })
});
"#
}

fn capability_source() -> &'static str {
    r#"
const auth = globalThis.__statticAuth.current();
const env = globalThis.__statticEnv;
globalThis.__statticLog("info", "mutation committed", { table: "todos" });
const published = globalThis.__statticRealtime.publish({
  changedTables: ["todos"],
  invalidate: ["todos.active"]
});
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ auth, env, published })
});
"#
}

struct Fixture {
    root: TempDir,
}

impl Fixture {
    fn new(db: bool) -> Self {
        Self::with_capabilities(EndpointCapabilities {
            db,
            fetch: false,
            auth: false,
            env: false,
            realtime: false,
            logging: false,
        })
    }

    fn with_capabilities(capabilities: EndpointCapabilities) -> Self {
        Self::with_source_and_capabilities(endpoint_source(), capabilities)
    }

    fn with_source_and_capabilities(source: &str, capabilities: EndpointCapabilities) -> Self {
        let root = tempfile::tempdir().expect("tempdir");
        fs::create_dir_all(root.path().join("zero/endpoints")).expect("dirs");
        let source_path = root.path().join("zero/endpoints/test.source.js");
        let bytecode_path = root.path().join("zero/endpoints/test.bytecode");
        fs::write(&source_path, source).expect("source");
        compile_file_with_capabilities(
            &source_path,
            &bytecode_path,
            Some(&source_path),
            &capabilities,
        )
        .expect("compile bytecode");
        let source = fs::read(&source_path).expect("source bytes");
        let bytecode = fs::read(&bytecode_path).expect("bytecode bytes");
        fs::write(
            root.path().join("zero/endpoints/test.json"),
            json!({
                "format": ENDPOINT_FORMAT,
                "endpointId": "GET /api/status",
                "kind": "endpoint",
                "method": "GET",
                "path": "/api/status",
                "sourcePath": "zero/endpoints/test.source.js",
                "bytecodePath": "zero/endpoints/test.bytecode",
                "sourceSha256": sha256_prefixed(&source),
                "bytecodeSha256": sha256_prefixed(&bytecode),
                "runnerAbi": RUNNER_ABI,
                "quickjsAbi": QUICKJS_ABI,
                "capabilities": {
                    "db": capabilities.db,
                    "fetch": capabilities.fetch,
                    "auth": capabilities.auth,
                    "env": capabilities.env,
                    "realtime": capabilities.realtime,
                    "logging": capabilities.logging
                },
                "db": {
                    "schemaHash": null,
                    "tables": {}
                }
            })
            .to_string(),
        )
        .expect("artifact");
        fs::write(
            root.path().join("zero/endpoints-index.json"),
            json!({
                "format": "stattic.zero.endpoints-index.v1",
                "artifact_kind": "zero_endpoints_index",
                "endpoints": {
                    "GET /api/status": "zero/endpoints/test.json"
                }
            })
            .to_string(),
        )
        .expect("endpoint index");
        Self { root }
    }

    fn envelope(&self) -> String {
        json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": self.root.path().to_string_lossy(),
            "endpointId": "GET /api/status",
            "request": {
                "method": "GET",
                "path": "/api/status",
                "uri": "/api/status?debug=1",
                "host": "site.test",
                "query": "debug=1",
                "headers": {},
                "params": { "route": "status" },
                "bodyBase64": ""
            },
            "context": {
                "spaceId": "spc_test",
                "versionId": "ver_test",
                "schemaHash": null,
                "authRef": "current",
                "variablesRef": "finalized"
            },
            "auth": {
                "userId": "usr_test",
                "isAuthenticated": true,
                "provider": "wpcom"
            },
            "variables": {
                "FEATURE_FLAG": "enabled"
            }
        })
        .to_string()
    }

    fn set_source_fallback(&self, enabled: bool) {
        let artifact_path = self.root.path().join("zero/endpoints/test.json");
        let mut artifact: Value =
            serde_json::from_str(&fs::read_to_string(&artifact_path).expect("artifact"))
                .expect("artifact json");
        artifact["sourceFallback"] = json!(enabled);
        fs::write(artifact_path, artifact.to_string()).expect("artifact");
    }
}

fn response_body(response: &crate::response::RunnerResponse) -> Value {
    serde_json::from_str(&response.body).expect("body")
}

#[test]
fn invokes_bytecode_endpoint_artifact() {
    let fixture = Fixture::new(false);

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response.status, 202);
    assert_eq!(
        response.headers.get("x-runner").map(String::as_str),
        Some("rust")
    );
    assert_eq!(
        response_body(&response),
        json!({
            "endpointId": "GET /api/status",
            "method": "GET",
            "path": "/api/status",
            "params": { "route": "status" },
            "spaceId": "spc_test",
            "dbInstalled": false,
            "dbCapability": false,
            "templateDb": false,
            "fetchInstalled": false,
            "authInstalled": false,
            "envInstalled": false,
            "realtimeInstalled": false,
            "loggingInstalled": false
        })
    );
}

#[test]
fn installs_db_host_only_when_capability_declares_db() {
    let fixture = Fixture::new(true);

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response_body(&response)["dbInstalled"], true);
    assert_eq!(response_body(&response)["dbCapability"], true);
    assert_eq!(response_body(&response)["templateDb"], true);
    assert_eq!(response_body(&response)["fetchInstalled"], false);
}

#[test]
fn renders_capability_templates_only_when_declared() {
    let fixture = Fixture::with_capabilities(EndpointCapabilities {
        db: true,
        fetch: true,
        auth: true,
        env: true,
        realtime: true,
        logging: true,
    });

    let response = handle_invoke(&fixture.envelope()).expect("response");
    let body = response_body(&response);

    assert_eq!(body["dbInstalled"], true);
    assert_eq!(body["fetchInstalled"], true);
    assert_eq!(body["authInstalled"], true);
    assert_eq!(body["envInstalled"], true);
    assert_eq!(body["realtimeInstalled"], true);
    assert_eq!(body["loggingInstalled"], true);
}

#[test]
fn capability_shims_read_bootstrap_and_emit_events() {
    let fixture = Fixture::with_source_and_capabilities(
        capability_source(),
        EndpointCapabilities {
            db: false,
            fetch: false,
            auth: true,
            env: true,
            realtime: true,
            logging: true,
        },
    );

    let response = handle_invoke(&fixture.envelope()).expect("response");
    let body = response_body(&response);

    assert_eq!(body["auth"]["userId"], "usr_test");
    assert_eq!(body["env"]["FEATURE_FLAG"], "enabled");
    assert_eq!(body["published"]["ok"], true);
    assert_eq!(response.events.len(), 2);
    assert_eq!(response.events[0]["event"], "zero.log");
    assert_eq!(response.events[0]["level"], "info");
    assert_eq!(response.events[1]["event"], "zero.realtime");
    assert_eq!(response.events[1]["payload"]["spaceId"], "spc_test");
    assert_eq!(response.events[1]["payload"]["versionId"], "ver_test");
    assert_eq!(
        response.events[1]["payload"]["changedTables"],
        json!(["todos"])
    );
    assert_eq!(
        response.events[1]["payload"]["changedQueries"],
        json!(["todos.active"])
    );
}

#[test]
fn endpoint_capabilities_default_conservatively_when_metadata_is_absent_or_partial() {
    let missing: EndpointCapabilities = serde_json::from_value(json!({})).expect("capabilities");
    assert!(missing.db);
    assert!(missing.fetch);
    assert!(missing.auth);
    assert!(missing.env);
    assert!(missing.realtime);
    assert!(missing.logging);

    let partial: EndpointCapabilities =
        serde_json::from_value(json!({ "db": false })).expect("capabilities");
    assert!(!partial.db);
    assert!(partial.fetch);
    assert!(partial.auth);
    assert!(partial.env);
    assert!(partial.realtime);
    assert!(partial.logging);
}

#[test]
fn rejects_tampered_bytecode() {
    let fixture = Fixture::new(false);
    fs::write(
        fixture.root.path().join("zero/endpoints/test.bytecode"),
        b"tampered",
    )
    .expect("tamper");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(
        response_body(&response)["error"]["code"],
        "zero_bytecode_hash_mismatch"
    );
}

#[test]
fn rejects_missing_bytecode_without_source_fallback() {
    let fixture = Fixture::new(false);
    fs::remove_file(fixture.root.path().join("zero/endpoints/test.bytecode"))
        .expect("remove bytecode");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 503);
    assert_eq!(
        response_body(&response)["error"]["code"],
        "zero_bytecode_unreadable"
    );
}

#[test]
fn falls_back_to_finalized_source_only_when_artifact_policy_allows() {
    let fixture = Fixture::new(false);
    fixture.set_source_fallback(true);
    fs::write(
        fixture.root.path().join("zero/endpoints/test.bytecode"),
        b"tampered",
    )
    .expect("tamper");

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response.status, 202);
    assert_eq!(
        response_body(&response)["endpointId"],
        json!("GET /api/status")
    );
}

#[test]
fn rejects_source_fallback_when_finalized_source_hash_mismatches() {
    let fixture = Fixture::new(false);
    fixture.set_source_fallback(true);
    fs::write(
        fixture.root.path().join("zero/endpoints/test.bytecode"),
        b"tampered",
    )
    .expect("tamper bytecode");
    fs::write(
        fixture.root.path().join("zero/endpoints/test.source.js"),
        b"tampered source",
    )
    .expect("tamper source");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(
        response_body(&response)["error"]["code"],
        "zero_source_hash_mismatch"
    );
}

#[test]
fn rejects_wrong_endpoint_id() {
    let fixture = Fixture::new(false);
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["endpointId"] = json!("GET /api/other");
    envelope["artifactPath"] = json!("zero/endpoints/test.json");

    let response = handle_invoke(&envelope.to_string()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(
        response_body(&response)["error"]["code"],
        "zero_endpoint_mismatch"
    );
}

#[test]
fn accepts_legacy_artifact_path_when_endpoint_index_is_absent() {
    let fixture = Fixture::new(false);
    fs::remove_file(fixture.root.path().join("zero/endpoints-index.json")).expect("remove index");
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["artifactPath"] = json!("zero/endpoints/test.json");

    let response = handle_invoke(&envelope.to_string()).expect("response");

    assert_eq!(response.status, 202);
}

#[test]
fn invokes_by_endpoint_id_without_revalidating_route_path() {
    let fixture = Fixture::new(false);
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["request"]["path"] = json!("/rewritten/api/status");
    envelope["request"]["uri"] = json!("/rewritten/api/status?debug=1");

    let response = handle_invoke(&envelope.to_string()).expect("response");

    assert_eq!(response.status, 202);
    assert_eq!(
        response_body(&response)["path"],
        json!("/rewritten/api/status")
    );
}
