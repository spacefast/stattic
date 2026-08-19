//! Versioned JSON ABI for pure finalizer preparation.
//!
//! The module is intentionally separate from the native transaction entry
//! point. The `#[no_mangle]` WASM exports live in the `stattic-runtime-wasi`
//! cdylib crate, which forwards to [`dispatch_json`]; no finalization logic
//! belongs there.

use crate::config::strict::{compile as compile_strict_config, Input as StrictConfigInput};
use crate::prepare::{
    analyze, prepare, resolve_dependency_digest, AnalyzeInput, PrepareInput, VariableScope,
};
use crate::transforms::{
    build_runtime_payload, compile_page, resolve_effective_config, validate_theme_json,
    PageCompileInput, ResolveEffectiveInput, RuntimePayloadInput, ValidateThemeJsonInput,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

pub const REQUEST_FORMAT: &str = "spacefast.finalizer.prepare.request.v1";
pub const RESPONSE_FORMAT: &str = "spacefast.finalizer.prepare.response.v1";

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct Request {
    format: String,
    #[serde(flatten)]
    operation: Operation,
}

#[derive(Debug, Deserialize)]
struct MetadataInput {}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct ResolveDependencyDigestInput {
    #[serde(default)]
    scopes: Vec<VariableScope>,
    dependency: String,
}

#[derive(Debug, Deserialize)]
#[serde(tag = "operation", content = "input", rename_all = "snake_case")]
enum Operation {
    Metadata(MetadataInput),
    Analyze(AnalyzeInput),
    Prepare(PrepareInput),
    CompileStrictV1(StrictConfigInput),
    ResolveDependencyDigest(ResolveDependencyDigestInput),
    ResolveEffectiveConfig(ResolveEffectiveInput),
    ValidateThemeJson(ValidateThemeJsonInput),
    BuildRuntimePayload(RuntimePayloadInput),
    CompilePage(PageCompileInput),
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct Response {
    format: &'static str,
    ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    output: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<AbiError>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct AbiError {
    code: &'static str,
    message: String,
}

pub fn dispatch_json(input: &[u8]) -> Vec<u8> {
    let response = match serde_json::from_slice::<Request>(input) {
        Ok(request) if request.format == REQUEST_FORMAT => {
            let output = match request.operation {
                Operation::Metadata(_) => serde_json::to_value(crate::protocol::metadata()),
                Operation::Analyze(input) => serde_json::to_value(analyze(input)),
                Operation::Prepare(input) => serde_json::to_value(prepare(input)),
                Operation::CompileStrictV1(input) => {
                    serde_json::to_value(compile_strict_config(input))
                }
                Operation::ResolveDependencyDigest(input) => serde_json::to_value(
                    resolve_dependency_digest(&input.scopes, &input.dependency),
                ),
                Operation::ResolveEffectiveConfig(input) => {
                    serde_json::to_value(resolve_effective_config(input))
                }
                Operation::ValidateThemeJson(input) => {
                    serde_json::to_value(validate_theme_json(input))
                }
                Operation::BuildRuntimePayload(input) => {
                    serde_json::to_value(build_runtime_payload(input))
                }
                Operation::CompilePage(input) => serde_json::to_value(compile_page(input)),
            };
            match output {
                Ok(output) => Response {
                    format: RESPONSE_FORMAT,
                    ok: true,
                    output: Some(output),
                    error: None,
                },
                Err(error) => error_response("finalizer_prepare_output_invalid", error.to_string()),
            }
        }
        Ok(request) => error_response(
            "finalizer_prepare_request_format_invalid",
            format!("Unsupported prepare request format {:?}.", request.format),
        ),
        Err(error) => error_response("finalizer_prepare_request_invalid", error.to_string()),
    };
    serde_json::to_vec(&response).unwrap_or_else(|error| {
        serde_json::to_vec(&json!({
            "format": RESPONSE_FORMAT,
            "ok": false,
            "error": {
                "code": "finalizer_prepare_response_invalid",
                "message": error.to_string(),
            }
        }))
        .expect("static prepare error response serializes")
    })
}

fn error_response(code: &'static str, message: String) -> Response {
    Response {
        format: RESPONSE_FORMAT,
        ok: false,
        output: None,
        error: Some(AbiError { code, message }),
    }
}
