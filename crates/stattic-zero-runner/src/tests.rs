use std::fs;
use std::path::PathBuf;

use base64::Engine;
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
    fetchInstalled: typeof globalThis.__statticFetch === "function",
    authInstalled: typeof globalThis.__statticAuth === "object",
    envInstalled: typeof globalThis.__statticEnv === "object",
    realtimeInstalled: typeof globalThis.__statticRealtime === "object",
    loggingInstalled: typeof globalThis.__statticLog === "function",
    serviceInstalled: typeof globalThis.__statticService === "function"
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

/// An endpoint source that emits `result` verbatim, except that `__LABEL__`
/// anywhere in it becomes the request's `route` param — the seam one fixture
/// uses to render two different node trees without recompiling.
fn result_source(result: Value) -> String {
    let json = serde_json::to_string(&result).expect("result json");
    let literal = serde_json::to_string(&json).expect("result literal");
    format!(
        "globalThis.__statticZeroResult = {literal}\n  .split(\"__LABEL__\")\n  .join(globalThis.__statticZeroRequest.params.route);"
    )
}

fn no_capabilities() -> EndpointCapabilities {
    EndpointCapabilities {
        db: false,
        fetch: false,
        auth: false,
        env: false,
        realtime: false,
        logging: false,
        gravatar: false,
        spam: false,
        email: false,
        content: false,
    }
}

/// The site directory holding the version root, so the render cache (a sibling
/// of the version) is isolated per fixture.
struct Fixture {
    site: TempDir,
    root: PathBuf,
}

impl Fixture {
    fn new(db: bool) -> Self {
        Self::with_capabilities(EndpointCapabilities {
            db,
            ..no_capabilities()
        })
    }

    fn with_capabilities(capabilities: EndpointCapabilities) -> Self {
        Self::with_source_and_capabilities(endpoint_source(), capabilities)
    }

    fn with_source(source: &str) -> Self {
        Self::with_source_and_capabilities(source, no_capabilities())
    }

    fn with_source_and_capabilities(source: &str, capabilities: EndpointCapabilities) -> Self {
        let site = tempfile::tempdir().expect("tempdir");
        let root = site.path().join("current");
        fs::create_dir_all(root.join("zero/endpoints")).expect("dirs");
        let source_path = root.join("zero/endpoints/test.source.js");
        let bytecode_path = root.join("zero/endpoints/test.bytecode");
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
            root.join("zero/endpoints/test.json"),
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
                    "logging": capabilities.logging,
                    "gravatar": capabilities.gravatar,
                    "spam": capabilities.spam,
                    "email": capabilities.email,
                    "content": capabilities.content
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
            root.join("zero/endpoints-index.json"),
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
        Self { site, root }
    }

    /// The image render cache, a sibling of the version root.
    fn render_cache(&self) -> PathBuf {
        self.site.path().join("zero-render-cache")
    }

    /// Every cached PNG across the cache's hash shards.
    fn cached_renders(&self) -> Vec<PathBuf> {
        let Ok(shards) = fs::read_dir(self.render_cache()) else {
            return Vec::new();
        };
        let mut entries: Vec<PathBuf> = shards
            .flatten()
            .filter_map(|shard| fs::read_dir(shard.path()).ok())
            .flat_map(|files| files.flatten().map(|file| file.path()))
            .collect();
        entries.sort();
        entries
    }

    fn envelope(&self) -> String {
        json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": self.root.to_string_lossy(),
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
                "provider": "gravatar"
            },
            "variables": {
                "FEATURE_FLAG": "enabled"
            }
        })
        .to_string()
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
            "fetchInstalled": false,
            "authInstalled": false,
            "envInstalled": false,
            "realtimeInstalled": false,
            "loggingInstalled": false,
            "serviceInstalled": false
        })
    );
}

#[test]
fn installs_db_host_only_when_capability_declares_db() {
    let fixture = Fixture::new(true);

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response_body(&response)["dbInstalled"], true);
    assert_eq!(response_body(&response)["dbCapability"], true);
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
        gravatar: true,
        spam: true,
        email: true,
        content: true,
    });

    let response = handle_invoke(&fixture.envelope()).expect("response");
    let body = response_body(&response);

    assert_eq!(body["dbInstalled"], true);
    assert_eq!(body["fetchInstalled"], true);
    assert_eq!(body["authInstalled"], true);
    assert_eq!(body["envInstalled"], true);
    assert_eq!(body["realtimeInstalled"], true);
    assert_eq!(body["loggingInstalled"], true);
    assert_eq!(body["serviceInstalled"], true);
}

#[test]
fn declared_fetch_exposes_the_author_api_and_projects_the_broker_response() {
    let fixture = Fixture::with_source_and_capabilities(
        r#"
function endpointResponseBodyBytes(value) { return [...String(value || "")].map((character) => character.charCodeAt(0)); }
function endpointEncodeBase64(bytes) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  let output = "";
  for (let index = 0; index < bytes.length; index += 3) {
    const first = bytes[index], second = bytes[index + 1], third = bytes[index + 2];
    output += alphabet[first >> 2];
    output += alphabet[((first & 3) << 4) | ((second || 0) >> 4)];
    output += second === undefined ? "=" : alphabet[((second & 15) << 2) | ((third || 0) >> 6)];
    output += third === undefined ? "=" : alphabet[third & 63];
  }
  return output;
}
function decodeBase64Bytes(value) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  let bits = 0, length = 0; const bytes = [];
  for (const character of value) {
    if (character === "=") break;
    bits = (bits << 6) | alphabet.indexOf(character); length += 6;
    if (length >= 8) { length -= 8; bytes.push((bits >> length) & 255); }
  }
  return bytes;
}
globalThis.Headers = class Headers {
  constructor(values = {}) { this.values = values; }
  entries() { return Object.entries(this.values)[Symbol.iterator](); }
};
globalThis.Response = class Response {
  constructor(body, init) { this.body = body; this.status = init.status; this.headers = init.headers; }
  async text() { return String.fromCharCode(...this.body); }
};
globalThis.__statticFetchHost = (frame) => {
  const request = JSON.parse(frame);
  return JSON.stringify({
    ok: true,
    result: {
      status: request.method === "POST" ? 201 : 400,
      headers: { "content-type": "text/plain" },
      bodyBase64: "aG9zdGVkLWZldGNo",
    },
  });
};
const response = await fetch("https://example.com/probe", { method: "POST", body: "hello" });
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  body: JSON.stringify({ status: response.status, body: await response.text() }),
});
"#,
        EndpointCapabilities {
            fetch: true,
            ..no_capabilities()
        },
    );

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response_body(&response)["status"], 201);
    assert_eq!(response_body(&response)["body"], "hosted-fetch");
}

/// One service is enough to install the bridge, because the bridge is shared
/// and the broker's own grant decides what it may carry.
#[test]
fn one_declared_service_installs_the_bridge_for_all_of_them() {
    let fixture = Fixture::with_capabilities(EndpointCapabilities {
        spam: true,
        ..no_capabilities()
    });

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response_body(&response)["serviceInstalled"], true);
}

#[test]
fn capability_shims_read_bootstrap_and_emit_events() {
    let fixture = Fixture::with_source_and_capabilities(
        capability_source(),
        EndpointCapabilities {
            auth: true,
            env: true,
            realtime: true,
            logging: true,
            ..no_capabilities()
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
    assert!(!missing.content);

    let partial: EndpointCapabilities =
        serde_json::from_value(json!({ "db": false })).expect("capabilities");
    assert!(!partial.db);
    assert!(partial.fetch);
    assert!(partial.auth);
    assert!(partial.env);
    assert!(partial.realtime);
    assert!(partial.logging);
    assert!(!partial.content);

    let explicit: EndpointCapabilities =
        serde_json::from_value(json!({ "content": true })).expect("capabilities");
    assert!(explicit.content);
}

#[test]
fn rejects_tampered_bytecode() {
    let fixture = Fixture::new(false);
    fs::write(
        fixture.root.join("zero/endpoints/test.bytecode"),
        b"tampered",
    )
    .expect("tamper");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(
        response_body(&response)["code"],
        "zero_bytecode_hash_mismatch"
    );
}

#[test]
fn rejects_malformed_bytecode() {
    let fixture = Fixture::new(false);
    let malformed = b"not quickjs bytecode";
    fs::write(fixture.root.join("zero/endpoints/test.bytecode"), malformed)
        .expect("write malformed bytecode");
    let artifact_path = fixture.root.join("zero/endpoints/test.json");
    let mut artifact: Value =
        serde_json::from_slice(&fs::read(&artifact_path).expect("read artifact"))
            .expect("parse artifact");
    artifact["bytecodeSha256"] = json!(sha256_prefixed(malformed));
    fs::write(&artifact_path, artifact.to_string()).expect("update artifact checksum");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(response_body(&response)["code"], "zero_bytecode_invalid");
}

#[test]
fn rejects_missing_bytecode() {
    let fixture = Fixture::new(false);
    fs::remove_file(fixture.root.join("zero/endpoints/test.bytecode")).expect("remove bytecode");

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 503);
    assert_eq!(response_body(&response)["code"], "zero_bytecode_unreadable");
}

#[test]
fn rejects_wrong_endpoint_id() {
    let fixture = Fixture::new(false);
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["endpointId"] = json!("GET /api/other");
    envelope["artifactPath"] = json!("zero/endpoints/test.json");

    let response = handle_invoke(&envelope.to_string()).unwrap_err();

    assert_eq!(response.status, 422);
    assert_eq!(response_body(&response)["code"], "zero_endpoint_mismatch");
}

#[test]
fn accepts_legacy_artifact_path_when_endpoint_index_is_absent() {
    let fixture = Fixture::new(false);
    fs::remove_file(fixture.root.join("zero/endpoints-index.json")).expect("remove index");
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["artifactPath"] = json!("zero/endpoints/test.json");

    let response = handle_invoke(&envelope.to_string()).expect("response");

    assert_eq!(response.status, 202);
}

fn image_result(width: u32, height: u32) -> Value {
    json!({
        "status": 200,
        "headers": {
            "content-type": "text/plain",
            "cache-control": "public, max-age=60"
        },
        "image": {
            "node": {
                "type": "container",
                "style": { "width": "100%", "height": "100%", "backgroundColor": "#1d4ed8" },
                "children": [
                    { "type": "text", "text": "__LABEL__", "style": { "color": "#ffffff" } }
                ]
            },
            "width": width,
            "height": height
        }
    })
}

#[test]
fn renders_image_results_to_png_and_reuses_the_site_render_cache() {
    let fixture = Fixture::with_source(&result_source(image_result(64, 32)));

    let response = handle_invoke(&fixture.envelope()).expect("response");

    assert_eq!(response.status, 200);
    // The bytes are a PNG whatever the endpoint declared, but freshness stays
    // the author's (and the Zero glue's) to set.
    assert_eq!(
        response.headers.get("content-type").map(String::as_str),
        Some("image/png")
    );
    assert_eq!(
        response.headers.get("cache-control").map(String::as_str),
        Some("public, max-age=60")
    );
    let etag = response.headers.get("etag").expect("etag").clone();
    assert_eq!(etag.len(), 34, "{etag}");
    assert!(
        etag.starts_with('"')
            && etag.ends_with('"')
            && etag[1..33].bytes().all(|byte| byte.is_ascii_hexdigit()),
        "{etag}"
    );
    assert_eq!(response.body, "");

    let encoded = response.body_base64.clone().expect("bodyBase64");
    let png = base64::engine::general_purpose::STANDARD
        .decode(&encoded)
        .expect("png bytes");
    assert_eq!(&png[..8], &[137, 80, 78, 71, 13, 10, 26, 10]);
    assert_eq!(
        u32::from_be_bytes(png[16..20].try_into().expect("ihdr width")),
        64
    );
    assert_eq!(
        u32::from_be_bytes(png[20..24].try_into().expect("ihdr height")),
        32
    );

    // The same tree and viewport resolve to the one cache entry, byte for byte.
    let repeated = handle_invoke(&fixture.envelope()).expect("response");
    assert_eq!(repeated.body_base64.as_deref(), Some(encoded.as_str()));
    let cached = fixture.cached_renders();
    assert_eq!(cached.len(), 1, "{cached:?}");
    assert_eq!(fs::read(&cached[0]).expect("cached png"), png);

    // A changed tree is a different content address, so it renders and caches
    // separately instead of serving the first entry.
    let mut envelope: Value = serde_json::from_str(&fixture.envelope()).expect("envelope");
    envelope["request"]["params"]["route"] = json!("changed");
    handle_invoke(&envelope.to_string()).expect("response");
    assert_eq!(fixture.cached_renders().len(), 2);
}

#[test]
fn rejects_image_results_that_cannot_render() {
    let mut oversized = image_result(4096, 630);
    let mut malformed = image_result(64, 32);
    malformed["image"]["node"] = json!({ "type": "nope" });
    let mut with_body = image_result(64, 32);
    with_body["body"] = json!("an image result owns the body");

    for (result, code) in [
        (&mut oversized, "zero_image_render_failed"),
        (&mut malformed, "zero_image_render_failed"),
        (&mut with_body, "zero_js_response_malformed"),
    ] {
        let fixture = Fixture::with_source(&result_source(result.take()));

        let response = handle_invoke(&fixture.envelope()).unwrap_err();

        assert_eq!(response.status, 502, "{code}");
        assert_eq!(response_body(&response)["code"], code);
        assert!(fixture.cached_renders().is_empty());
    }
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

// A handler that burns its budget between awaits is the shape every real one
// has, and it is the shape the engine reports worst: QuickJS drops the pending
// continuation, leaving the module promise unsettled, and rquickjs calls that a
// dead-locked promise rather than an interruption. The visitor must still get
// the budget error, not a 500 quoting the engine.
#[test]
fn budget_exhausted_between_awaits_is_a_timeout_not_an_engine_failure() {
    let fixture = Fixture::with_source_and_capabilities(
        r#"
async function burn(deadline) {
  while (Date.now() < deadline) {}
  return deadline;
}
const deadline = Date.now() + 5000;
await burn(deadline);
await burn(deadline);
globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: "unreachable" });
"#,
        no_capabilities(),
    );

    let response = handle_invoke(&fixture.envelope()).unwrap_err();

    assert_eq!(response.status, 504);
    assert_eq!(
        response_body(&response)["code"],
        "zero_js_execution_timeout"
    );
}
