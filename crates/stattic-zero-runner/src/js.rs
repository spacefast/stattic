use std::collections::BTreeMap;
use std::sync::{
    atomic::{AtomicBool, Ordering},
    Arc,
};
use std::time::{Duration, Instant};

use base64::Engine;
use rquickjs::{function::Func, Context, Ctx, Module, Runtime, WriteOptions};
use serde::Deserialize;
use serde_json::Value;

use crate::artifacts::EndpointArtifact;
use crate::constants::{
    JS_EXECUTION_TIMEOUT_MS, JS_MEMORY_LIMIT_BYTES, JS_STACK_LIMIT_BYTES,
    ZERO_RESPONSE_BODY_MAX_BYTES,
};
use crate::protocol::InvokeEnvelope;
use crate::response::{
    error_response, record_js_execution, record_js_module_load, record_js_runtime_init,
    RunnerResponse,
};
use crate::response_headers::validate_response_headers;

pub(crate) enum EndpointProgram {
    Bytecode(Vec<u8>),
    Source(String),
}

pub(crate) fn compile_endpoint_source(source: &str, name: &str) -> rquickjs::Result<Vec<u8>> {
    let runtime = Runtime::new()?;
    let context = Context::full(&runtime)?;
    context.with(|ctx| {
        let module = Module::declare(ctx.clone(), name, source)?;
        module.write(WriteOptions {
            strip_source: true,
            strip_debug: true,
            ..Default::default()
        })
    })
}

pub(crate) fn execute_endpoint_module(
    envelope: &InvokeEnvelope,
    artifact: &EndpointArtifact,
    program: &EndpointProgram,
) -> Result<RunnerResponse, RunnerResponse> {
    crate::db::rollback_open_transaction();
    let runtime_started = Instant::now();
    let runtime = Runtime::new()
        .map_err(|error| error_response(500, "zero_js_runtime_init_failed", &error.to_string()))?;
    runtime.set_memory_limit(JS_MEMORY_LIMIT_BYTES);
    runtime.set_max_stack_size(JS_STACK_LIMIT_BYTES);
    let started = Instant::now();
    let execution_interrupted = Arc::new(AtomicBool::new(false));
    let interrupt_state = Arc::clone(&execution_interrupted);
    runtime.set_interrupt_handler(Some(Box::new(move || {
        let interrupted = started.elapsed() > Duration::from_millis(JS_EXECUTION_TIMEOUT_MS);
        if interrupted {
            interrupt_state.store(true, Ordering::SeqCst);
        }
        interrupted
    })));

    let context = Context::full(&runtime)
        .map_err(|error| error_response(500, "zero_js_context_init_failed", &error.to_string()))?;
    record_js_runtime_init(runtime_started);
    let result = context.with(|ctx| {
        install_globals(&ctx, envelope, artifact)?;
        let module_load_started = Instant::now();
        let module = match program {
            EndpointProgram::Bytecode(bytecode) => {
                let module = unsafe { Module::load(ctx.clone(), bytecode) };
                module.map_err(|error| {
                    error_response(422, "zero_bytecode_invalid", &error.to_string())
                })?
            }
            EndpointProgram::Source(source) => {
                Module::declare(ctx.clone(), artifact.source_path.as_str(), source.as_str())
                    .map_err(|error| {
                        error_response(422, "zero_source_compile_failed", &error.to_string())
                    })?
            }
        };
        record_js_module_load(module_load_started);
        let execution_started = Instant::now();
        let (_, promise) = module.eval().map_err(|error| {
            js_execution_error_response(
                &error.to_string(),
                started.elapsed(),
                &execution_interrupted,
            )
        })?;
        promise.finish::<()>().map_err(|error| {
            js_execution_error_response(
                &error.to_string(),
                started.elapsed(),
                &execution_interrupted,
            )
        })?;
        let result_json: String = ctx.globals().get("__statticZeroResult").map_err(|error| {
            js_execution_error_response(
                &error.to_string(),
                started.elapsed(),
                &execution_interrupted,
            )
        })?;
        let result: EndpointExecutionResult =
            serde_json::from_str(&result_json).map_err(|error| {
                error_response(502, "zero_js_response_malformed", &error.to_string())
            })?;
        let events_json: String = ctx
            .eval("JSON.stringify(globalThis.__statticZeroEvents || [])")
            .map_err(|error| {
                js_execution_error_response(
                    &error.to_string(),
                    started.elapsed(),
                    &execution_interrupted,
                )
            })?;
        let events: Vec<Value> = serde_json::from_str(&events_json).unwrap_or_default();
        record_js_execution(execution_started);
        let headers = validate_response_headers(result.headers.unwrap_or_default())
            .map_err(|error| error_response(502, error.code, error.message))?;
        Ok(RunnerResponse {
            status: http_status(result.status, 200),
            headers,
            body: enforce_response_body_limit(result.body.unwrap_or_default())?,
            body_base64: enforce_response_body_base64_limit(result.body_base64)?,
            events,
            metrics: None,
        })
    });
    crate::db::rollback_open_transaction();
    result
}

fn install_globals(
    ctx: &Ctx<'_>,
    envelope: &InvokeEnvelope,
    artifact: &EndpointArtifact,
) -> Result<(), RunnerResponse> {
    let bootstrap_json = serde_json::to_string(&serde_json::json!({
        "request": envelope.request,
        "context": envelope.context,
        "auth": envelope.auth,
        "variables": envelope.variables,
        "capabilities": artifact.capabilities,
        "endpoint": {
            "endpointId": if artifact.kind == "run" { &artifact.run_id } else { &artifact.endpoint_id },
            "method": if artifact.kind == "run" { "POST" } else { artifact.method.as_str() },
            "path": if artifact.kind == "run" { "/__spacefast/zero/run" } else { artifact.path.as_str() },
            "db": artifact.db,
        },
    }))
    .map_err(|error| error_response(500, "zero_bootstrap_encode_failed", &error.to_string()))?;
    let bootstrap = format!("globalThis.__statticZeroBootstrap = {bootstrap_json};");
    ctx.eval::<(), _>(bootstrap)
        .map_err(|error| error_response(500, "zero_js_globals_failed", &error.to_string()))?;

    if artifact.capabilities.db {
        let globals = ctx.globals();
        globals
            .set(
                "__statticDbHost",
                Func::from(|operation: String| -> String {
                    crate::db::handle_db_operation(&operation)
                }),
            )
            .map_err(|error| {
                error_response(500, "zero_db_host_install_failed", &error.to_string())
            })?;
    }
    Ok(())
}

fn js_execution_error_response(
    message: &str,
    elapsed: Duration,
    execution_interrupted: &AtomicBool,
) -> RunnerResponse {
    let lower = message.to_ascii_lowercase();
    if lower.contains("interrupted")
        || (lower.contains("exception generated by quickjs")
            && (execution_interrupted.load(Ordering::SeqCst)
                || elapsed >= Duration::from_millis(JS_EXECUTION_TIMEOUT_MS)))
    {
        return error_response(
            504,
            "zero_js_execution_timeout",
            "Zero JavaScript execution exceeded the request CPU budget.",
        );
    }
    error_response(500, "zero_js_execution_failed", message)
}

fn http_status(status: Option<u16>, fallback: u16) -> u16 {
    match status {
        Some(status) if (100..=599).contains(&status) => status,
        _ => fallback,
    }
}

fn enforce_response_body_limit(body: String) -> Result<String, RunnerResponse> {
    if body.len() > ZERO_RESPONSE_BODY_MAX_BYTES {
        return Err(error_response(
            502,
            "zero_response_too_large",
            "Zero runner response exceeded the response size limit.",
        ));
    }
    Ok(body)
}

fn enforce_response_body_base64_limit(
    body: Option<String>,
) -> Result<Option<String>, RunnerResponse> {
    let Some(body) = body else {
        return Ok(None);
    };
    let decoded = base64::engine::general_purpose::STANDARD
        .decode(&body)
        .map_err(|error| error_response(502, "zero_js_response_malformed", &error.to_string()))?;
    if decoded.len() > ZERO_RESPONSE_BODY_MAX_BYTES {
        return Err(error_response(
            502,
            "zero_response_too_large",
            "Zero runner response exceeded the response size limit.",
        ));
    }
    Ok(Some(body))
}

#[derive(Debug, Deserialize)]
struct EndpointExecutionResult {
    status: Option<u16>,
    headers: Option<BTreeMap<String, String>>,
    body: Option<String>,
    #[serde(rename = "bodyBase64")]
    body_base64: Option<String>,
}
