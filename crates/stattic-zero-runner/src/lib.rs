#![expect(
    clippy::result_large_err,
    reason = "RunnerResponse is the serialized process-boundary wire value and stays inline to avoid per-response allocation"
)]

mod artifacts;
mod constants;
mod db;
mod fetch;
mod image;
mod js;
mod protocol;
mod response;
mod response_headers;
mod services;
mod templates;

use std::fs;
use std::io::{self, Read};
use std::path::Path;
use std::time::Instant;

use artifacts::{
    read_endpoint_artifact, resolve_endpoint_artifact_path, resolve_version_path,
    EndpointCapabilities,
};
use constants::{PROTOCOL, ZERO_INVOKE_ENVELOPE_MAX_BYTES};
use js::{compile_endpoint_source, execute_endpoint_module};
use protocol::InvokeEnvelope;
use response::{
    attach_runner_metrics, error_response, record_artifact_read, record_bytecode_read,
    record_endpoint_index, record_envelope_parse, reset_stage_metrics, write_response,
    RunnerResponse,
};

pub use artifacts::EndpointCapabilities as ZeroEndpointCapabilities;

/// Every upstream the platform-service broker can contact. Re-exported so
/// the runtime compiler can source its egress allowlist from this crate
/// without this crate depending on it — the dependency only runs the other way.
pub use services::SERVICE_UPSTREAM_HOSTS;

/// Runner/QuickJS ABI identifiers, re-exported for protocol codegen so the
/// generated PHP constants stay sourced from this crate.
pub use constants::{QUICKJS_ABI, RUNNER_ABI};

/// The MySQL broker's operation shape and session pin. `shared/db-broker.php`
/// is specified against this engine down to the bytes, so protocol codegen
/// emits these into the generated PHP constants instead of leaving the PHP
/// side to restate them and drift.
pub use db::{
    DB_OPERATION_MAX_BYTES, DB_PARAM_MAX_COUNT, DB_RESULT_ROWS_MAX, DB_SESSION_PIN,
    DB_TRANSACTION_MAX_STATEMENTS,
};

/// The on-disk artifact identifiers this runner refuses a request over,
/// re-exported so the compiler that writes them spells them once — here, where
/// they are read.
pub use constants::{
    ENDPOINTS_INDEX_FORMAT as ZERO_ENDPOINTS_INDEX_FORMAT,
    ENDPOINTS_INDEX_KIND as ZERO_ENDPOINTS_INDEX_KIND, ENDPOINT_FORMAT as ZERO_ENDPOINT_FORMAT,
    MIGRATIONS_FORMAT as ZERO_MIGRATIONS_FORMAT, RUN_FORMAT as ZERO_RUN_FORMAT,
};

pub struct CompiledEndpointProgram {
    pub generated_source: String,
    pub bytecode: Vec<u8>,
}

pub fn self_test() -> Result<(), String> {
    match compile_endpoint_source(
        "export default async function () { return { status: 204 }; }",
        "self-test.js",
    ) {
        Ok(bytecode) if !bytecode.is_empty() => Ok(()),
        Ok(_) => Err("self-test produced empty endpoint bytecode".to_string()),
        Err(error) => Err(format!("self-test endpoint compile failed: {error}")),
    }
}

/// The Functions relay's per-request platform-service executor: one frame JSON
/// document on stdin, one result JSON line on stdout, exit 0 either way — so
/// the transport treats protocol refusals and driver failures identically.
/// Which services the frame may name is decided by the relay from the version's
/// grant before this process is ever spawned; by the time a frame arrives the
/// authority question is already answered.
///
/// Reading one byte past the limit is enough — the handler turns the overrun
/// into its own typed refusal without buffering the rest. The rollback happens
/// before the answer is printed: an email effect writes the outbox row on this
/// process's own connection, and a client that vanishes mid-frame must not
/// leave row locks behind for the lifetime of the connection pool.
pub fn run_service_broker_stdio() {
    services::set_grant(services::ServiceGrant::from_wire(
        &std::env::var("SPACEFAST_SERVICE_BROKER_GRANT").unwrap_or_default(),
    ));
    let mut input = String::new();
    let outcome = io::stdin()
        .take(services::SERVICE_FRAME_MAX_BYTES as u64 + 1)
        .read_to_string(&mut input);
    let response = match outcome {
        Ok(_) => services::handle_service_frame(&input),
        Err(_) => {
            "{\"ok\":false,\"code\":\"service_payload_invalid\",\"message\":\"The service frame could not be read.\"}"
                .to_string()
        }
    };
    db::rollback_open_transaction();
    println!("{response}");
}

pub fn run_stdio() {
    let input = match read_invoke_stdin(io::stdin()) {
        Ok(input) => input,
        Err(response) => {
            write_response(response);
            return;
        }
    };

    let response = match handle_invoke(&input) {
        Ok(response) | Err(response) => response,
    };
    write_response(response);
}

pub fn handle_invoke(input: &str) -> Result<RunnerResponse, RunnerResponse> {
    let started = Instant::now();
    db::reset_metrics();
    // Effect positions are the outbox's idempotency key and count within one
    // invocation, so they reset with the rest of the per-invocation state.
    db::reset_email_effect_index();
    reset_stage_metrics();
    handle_invoke_inner(input)
        .map(|mut response| {
            attach_runner_metrics(&mut response, started);
            response
        })
        .map_err(|mut response| {
            attach_runner_metrics(&mut response, started);
            response
        })
}

pub(crate) fn compile_file_with_capabilities(
    source_path: &Path,
    bytecode_path: &Path,
    generated_source_path: Option<&Path>,
    capabilities: &EndpointCapabilities,
) -> Result<(), String> {
    let source = fs::read_to_string(source_path).map_err(|error| error.to_string())?;
    let compile_name = generated_source_path
        .unwrap_or(source_path)
        .to_string_lossy();
    let compiled = compile_endpoint_program(&source, compile_name.as_ref(), capabilities)?;
    if let Some(generated_source_path) = generated_source_path {
        if let Some(parent) = generated_source_path.parent() {
            fs::create_dir_all(parent).map_err(|error| error.to_string())?;
        }
        fs::write(generated_source_path, compiled.generated_source)
            .map_err(|error| error.to_string())?;
    }
    if let Some(parent) = bytecode_path.parent() {
        fs::create_dir_all(parent).map_err(|error| error.to_string())?;
    }
    fs::write(bytecode_path, compiled.bytecode).map_err(|error| error.to_string())
}

pub fn compile_endpoint_program(
    source: &str,
    name: &str,
    capabilities: &EndpointCapabilities,
) -> Result<CompiledEndpointProgram, String> {
    let generated_source = templates::render_endpoint_program(source, capabilities);
    let bytecode =
        compile_endpoint_source(&generated_source, name).map_err(|error| error.to_string())?;
    Ok(CompiledEndpointProgram {
        generated_source,
        bytecode,
    })
}

/// Compiles one endpoint source to bytecode ahead of serving. The argument
/// grammar belongs to the CLI that spells it; this takes the resolved values.
pub fn prepare(
    source: &str,
    bytecode: &str,
    capabilities_json: Option<&str>,
    generated_source: Option<&str>,
) -> i32 {
    let capabilities = match capabilities_json {
        Some(raw) => match serde_json::from_str::<EndpointCapabilities>(raw) {
            Ok(capabilities) => capabilities,
            Err(error) => {
                eprintln!("zero capability metadata invalid: {error}");
                return 2;
            }
        },
        None => EndpointCapabilities::conservative(),
    };
    match compile_file_with_capabilities(
        Path::new(source),
        Path::new(bytecode),
        generated_source.map(Path::new),
        &capabilities,
    ) {
        Ok(()) => 0,
        Err(error) => {
            eprintln!("zero bytecode compile failed: {error}");
            1
        }
    }
}

fn read_invoke_stdin(mut reader: impl Read) -> Result<String, RunnerResponse> {
    let mut input = String::new();
    let limit = ZERO_INVOKE_ENVELOPE_MAX_BYTES as u64 + 1;
    reader
        .by_ref()
        .take(limit)
        .read_to_string(&mut input)
        .map_err(|error| error_response(400, "zero_runner_stdin_invalid", &error.to_string()))?;
    if input.len() > ZERO_INVOKE_ENVELOPE_MAX_BYTES {
        return Err(error_response(
            413,
            "zero_runner_stdin_too_large",
            "Zero runner invoke envelope exceeded the stdin size limit.",
        ));
    }
    Ok(input)
}

fn handle_invoke_inner(input: &str) -> Result<RunnerResponse, RunnerResponse> {
    let envelope_started = Instant::now();
    let envelope: InvokeEnvelope = serde_json::from_str(input)
        .map_err(|error| error_response(400, "zero_runner_envelope_invalid", &error.to_string()))?;
    record_envelope_parse(envelope_started);
    if envelope.protocol != PROTOCOL {
        return Err(error_response(
            400,
            "zero_runner_protocol_unsupported",
            "Unsupported Zero runner protocol.",
        ));
    }

    let index_started = Instant::now();
    let artifact_path = resolve_endpoint_artifact_path(&envelope)?;
    if envelope.artifact_path.as_deref().unwrap_or("").is_empty() {
        record_endpoint_index(index_started);
    }
    let artifact_started = Instant::now();
    let artifact = read_endpoint_artifact(&artifact_path)?;
    record_artifact_read(artifact_started);
    artifact.validate_for(&envelope)?;

    if !artifact.method_matches(&envelope.request.method) {
        return Err(error_response(
            405,
            "zero_method_not_allowed",
            "Zero endpoint method is not allowed.",
        ));
    }

    let bytecode_path = resolve_version_path(&envelope.version_root, &artifact.bytecode_path)
        .map_err(|message| error_response(422, "zero_bytecode_path_invalid", &message))?;
    let bytecode_started = Instant::now();
    let bytecode = artifact.read_verified_bytecode(&bytecode_path);
    record_bytecode_read(bytecode_started);

    execute_endpoint_module(&envelope, &artifact, &bytecode?)
}

#[cfg(test)]
mod tests;
