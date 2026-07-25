#![expect(
    clippy::result_large_err,
    reason = "RunnerResponse is the serialized process-boundary wire value and stays inline to avoid per-response allocation"
)]

mod artifacts;
mod constants;
mod db;
mod js;
mod protocol;
mod response;
mod response_headers;
mod templates;

use std::env;
use std::fs;
use std::io::{self, Read};
use std::path::Path;
use std::time::Instant;

use artifacts::{
    read_endpoint_artifact, resolve_endpoint_artifact_path, resolve_version_path,
    EndpointCapabilities,
};
use constants::{PROTOCOL, ZERO_INVOKE_ENVELOPE_MAX_BYTES};
use js::{compile_endpoint_source, execute_endpoint_module, EndpointProgram};
use protocol::InvokeEnvelope;
use response::{
    attach_runner_metrics, error_response, record_artifact_read, record_bytecode_read,
    record_endpoint_index, record_envelope_parse, record_source_read, reset_stage_metrics,
    write_response, RunnerResponse,
};

pub use artifacts::EndpointCapabilities as ZeroEndpointCapabilities;

/// Runner/QuickJS ABI identifiers, re-exported for protocol codegen so the
/// generated PHP constants stay sourced from this crate.
pub const RUNNER_ABI: &str = constants::RUNNER_ABI;
pub const QUICKJS_ABI: &str = constants::QUICKJS_ABI;

pub struct CompiledEndpointProgram {
    pub generated_source: String,
    pub bytecode: Vec<u8>,
}

pub fn main_entry() {
    let args = env::args().skip(1).collect::<Vec<_>>();
    if matches!(args.as_slice(), [arg] if arg == "--self-test") {
        let status = match compile_endpoint_source(
            "export default async function () { return { status: 204 }; }",
            "self-test.js",
        ) {
            Ok(bytecode) if !bytecode.is_empty() => {
                println!(
                    "{{\"format\":\"stattic.zero.runner.self-test.v1\",\"abi\":\"{}\"}}",
                    RUNNER_ABI
                );
                0
            }
            Ok(_) => {
                eprintln!("stattic-zero-runner self-test produced empty bytecode");
                1
            }
            Err(error) => {
                eprintln!("stattic-zero-runner self-test failed: {error}");
                1
            }
        };
        std::process::exit(status);
    }
    if args.first().map(String::as_str) == Some("compile") {
        let status = match args.as_slice() {
            [_, source, bytecode] => compile_command(source, bytecode, None, None),
            [_, source, bytecode, capabilities] => {
                compile_command(source, bytecode, Some(capabilities), None)
            }
            [_, source, bytecode, capabilities, generated_source] => {
                compile_command(source, bytecode, Some(capabilities), Some(generated_source))
            }
            _ => {
                eprintln!("usage: stattic-zero-runner compile <source.js> <bytecode> [capabilities-json] [generated-source.js]");
                2
            }
        };
        std::process::exit(status);
    }
    if args.first().map(String::as_str) == Some("migrate") {
        let status = match args.as_slice() {
            [_, version_root] => migrate_command(version_root),
            _ => {
                eprintln!("usage: stattic-zero-runner migrate <version-root>");
                2
            }
        };
        std::process::exit(status);
    }

    run_stdio();
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

pub fn compile_file(source_path: &Path, bytecode_path: &Path) -> Result<(), String> {
    compile_file_with_capabilities(
        source_path,
        bytecode_path,
        None,
        &EndpointCapabilities::conservative(),
    )
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

/// Recompiles an already-finalized endpoint program: the source is the
/// previously generated ABI-v2 program and is compiled to bytecode verbatim,
/// without rendering another prelude. Used by import rebind, where finalized
/// sources from an exported space are rebound to a new target space.
pub fn compile_finalized_endpoint_program(
    generated_source: &str,
    name: &str,
) -> Result<Vec<u8>, String> {
    compile_endpoint_source(generated_source, name).map_err(|error| error.to_string())
}

fn compile_command(
    source: &str,
    bytecode: &str,
    capabilities_json: Option<&String>,
    generated_source: Option<&String>,
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

fn migrate_command(version_root: &str) -> i32 {
    let migrations_path = match resolve_version_path(version_root, "zero/migrations.json") {
        Ok(path) => path,
        Err(error) => {
            eprintln!("zero migration path invalid: {error}");
            return 2;
        }
    };
    match db::apply_migrations_file(&migrations_path) {
        Ok(()) => 0,
        Err(error) => {
            eprintln!("zero migration failed: {error}");
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
    let program = match artifact.read_verified_bytecode(&bytecode_path) {
        Ok(bytecode) => {
            record_bytecode_read(bytecode_started);
            EndpointProgram::Bytecode(bytecode)
        }
        Err(_) if artifact.source_fallback => {
            record_bytecode_read(bytecode_started);
            fallback_source_program(&envelope, &artifact)?
        }
        Err(response) => {
            record_bytecode_read(bytecode_started);
            return Err(response);
        }
    };

    match execute_endpoint_module(&envelope, &artifact, &program) {
        Err(response) => {
            if artifact.source_fallback
                && response_error_code_is(&response, "zero_bytecode_invalid")
            {
                let fallback = fallback_source_program(&envelope, &artifact)?;
                execute_endpoint_module(&envelope, &artifact, &fallback)
            } else {
                Err(response)
            }
        }
        result => result,
    }
}

fn fallback_source_program(
    envelope: &InvokeEnvelope,
    artifact: &artifacts::EndpointArtifact,
) -> Result<EndpointProgram, RunnerResponse> {
    let source_path = resolve_version_path(&envelope.version_root, &artifact.source_path)
        .map_err(|message| error_response(422, "zero_source_path_invalid", &message))?;
    let source_started = Instant::now();
    let source = artifact.read_verified_source(&source_path);
    record_source_read(source_started);
    source.map(EndpointProgram::Source)
}

fn response_error_code_is(response: &RunnerResponse, expected: &str) -> bool {
    serde_json::from_str::<serde_json::Value>(&response.body)
        .ok()
        .and_then(|body| body.get("error")?.get("code")?.as_str().map(str::to_string))
        .is_some_and(|code| code == expected)
}

#[cfg(test)]
mod tests;
