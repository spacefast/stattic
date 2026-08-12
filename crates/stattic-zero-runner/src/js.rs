use std::collections::BTreeMap;
#[cfg(unix)]
use std::mem::MaybeUninit;
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
use crate::image::ImageRenderSpec;
use crate::protocol::InvokeEnvelope;
use crate::response::{
    error_response, record_js_execution, record_js_module_load, record_js_runtime_init,
    RunnerResponse,
};
use crate::response_headers::validate_response_headers;

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
    bytecode: &[u8],
) -> Result<RunnerResponse, RunnerResponse> {
    crate::db::rollback_open_transaction();
    let runtime_started = Instant::now();
    let runtime = Runtime::new()
        .map_err(|error| error_response(500, "zero_js_runtime_init_failed", &error.to_string()))?;
    runtime.set_memory_limit(JS_MEMORY_LIMIT_BYTES);
    runtime.set_max_stack_size(JS_STACK_LIMIT_BYTES);
    let execution_clock = ExecutionClock::start();
    let execution_interrupted = Arc::new(AtomicBool::new(false));
    let interrupt_state = Arc::clone(&execution_interrupted);
    runtime.set_interrupt_handler(Some(Box::new(move || {
        let interrupted =
            execution_clock.elapsed() > Duration::from_millis(JS_EXECUTION_TIMEOUT_MS);
        if interrupted {
            interrupt_state.store(true, Ordering::SeqCst);
        }
        interrupted
    })));

    let context = Context::full(&runtime)
        .map_err(|error| error_response(500, "zero_js_context_init_failed", &error.to_string()))?;
    record_js_runtime_init(runtime_started);
    let result = context.with(|ctx| {
        let js_error = |error: rquickjs::Error| {
            js_execution_error_response(
                &error.to_string(),
                execution_clock.elapsed(),
                &execution_interrupted,
            )
        };
        install_globals(&ctx, envelope, artifact)?;
        let module_load_started = Instant::now();
        let module = unsafe { Module::load(ctx.clone(), bytecode) }
            .map_err(|error| error_response(422, "zero_bytecode_invalid", &error.to_string()))?;
        record_js_module_load(module_load_started);
        let execution_started = Instant::now();
        let (_, promise) = module.eval().map_err(js_error)?;
        promise.finish::<()>().map_err(js_error)?;
        let result_json: String = ctx.globals().get("__statticZeroResult").map_err(js_error)?;
        let result: EndpointExecutionResult =
            serde_json::from_str(&result_json).map_err(|error| {
                error_response(502, "zero_js_response_malformed", &error.to_string())
            })?;
        let events_json: String = ctx
            .eval("JSON.stringify(globalThis.__statticZeroEvents || [])")
            .map_err(js_error)?;
        let events: Vec<Value> = serde_json::from_str(&events_json).unwrap_or_default();
        record_js_execution(execution_started);
        let mut headers = validate_response_headers(result.headers.unwrap_or_default())
            .map_err(|error| error_response(502, error.code, error.message))?;
        let mut body = result.body.unwrap_or_default();
        let mut body_base64 = result.body_base64;
        if let Some(image) = result.image {
            body_base64 = Some(render_response_image(
                &image,
                &envelope.version_root,
                &body,
                body_base64.as_deref(),
                &mut headers,
            )?);
            body = String::new();
        }
        Ok(RunnerResponse {
            status: http_status(result.status, 200),
            headers,
            body: enforce_response_body_limit(body)?,
            body_base64: enforce_response_body_base64_limit(body_base64)?,
            events,
            metrics: None,
        })
    });
    crate::db::rollback_open_transaction();
    result
}

/// Renders an endpoint's `image` result to a base64 PNG and stamps the response
/// headers that describe it.
///
/// `content-type` is the runner's to set: the bytes are a PNG whatever the
/// author declared. `etag` is derived from those bytes unless the author
/// supplied their own validator, and `cache-control` is left entirely alone —
/// the Zero glue owns the freshness policy.
fn render_response_image(
    image: &ImageRenderSpec,
    version_root: &str,
    body: &str,
    body_base64: Option<&str>,
    headers: &mut BTreeMap<String, String>,
) -> Result<String, RunnerResponse> {
    if !body.is_empty() || body_base64.is_some_and(|encoded| !encoded.is_empty()) {
        return Err(error_response(
            502,
            "zero_js_response_malformed",
            "Zero image response must not also set a body.",
        ));
    }
    let png = crate::image::render_png(image, version_root)
        .map_err(|message| error_response(502, "zero_image_render_failed", &message))?;
    headers.insert("content-type".to_string(), "image/png".to_string());
    headers
        .entry("etag".to_string())
        .or_insert_with(|| crate::image::etag_for(&png));
    Ok(base64::engine::general_purpose::STANDARD.encode(&png))
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
    if artifact.capabilities.fetch {
        let globals = ctx.globals();
        globals
            .set(
                "__statticFetchHost",
                Func::from(|frame: String| -> String { crate::fetch::handle_fetch_frame(&frame) }),
            )
            .map_err(|error| {
                error_response(500, "zero_fetch_host_install_failed", &error.to_string())
            })?;
    }
    // The grant is set even when nothing is installed: it is what the broker
    // itself checks, and a stale grant from an earlier invocation on this
    // thread must not survive into this one.
    crate::services::set_grant(crate::services::ServiceGrant {
        gravatar: artifact.capabilities.gravatar,
        spam: artifact.capabilities.spam,
        email: artifact.capabilities.email,
    });
    if artifact.capabilities.any_service() {
        let globals = ctx.globals();
        globals
            .set(
                "__statticServiceHost",
                Func::from(|frame: String| -> String {
                    crate::services::handle_service_frame(&frame)
                }),
            )
            .map_err(|error| {
                error_response(500, "zero_service_host_install_failed", &error.to_string())
            })?;
    }
    Ok(())
}

/// The interrupt handler is the only thing that stops a handler at the budget,
/// and it records that it fired — so that flag, not the engine's wording, says
/// whether this failure is a timeout. Matching on the message instead missed
/// every budget kill that landed inside an `await`: rquickjs reports those as a
/// dead-locked promise, which matched none of the strings, so the request came
/// back 500 `zero_js_execution_failed` carrying raw engine text instead of 504.
fn js_execution_error_response(
    message: &str,
    elapsed: Duration,
    execution_interrupted: &AtomicBool,
) -> RunnerResponse {
    let lower = message.to_ascii_lowercase();
    // An interrupt can abort a queued async continuation without settling its
    // parent promise. rquickjs then sees an empty queue and reports WouldBlock,
    // so the interrupt flag is the timeout authority, not the final message.
    if execution_interrupted.load(Ordering::SeqCst)
        || lower.contains("interrupted")
        || (lower.contains("exception generated by quickjs")
            && elapsed >= Duration::from_millis(JS_EXECUTION_TIMEOUT_MS))
    {
        return error_response(
            504,
            "zero_js_execution_timeout",
            "Zero JavaScript execution exceeded the request CPU budget.",
        );
    }
    error_response(500, "zero_js_execution_failed", message)
}

#[derive(Clone, Copy)]
struct ExecutionClock {
    wall_started: Instant,
    thread_cpu_started: Option<Duration>,
}

impl ExecutionClock {
    fn start() -> Self {
        Self {
            wall_started: Instant::now(),
            thread_cpu_started: thread_cpu_time(),
        }
    }

    fn elapsed(self) -> Duration {
        match (self.thread_cpu_started, thread_cpu_time()) {
            (Some(started), Some(current)) => current.saturating_sub(started),
            _ => self.wall_started.elapsed(),
        }
    }
}

// QuickJS and its synchronous host brokers share one thread. A wall clock
// charges MySQL and HTTP wait time to JavaScript; when that deadline fires on
// the continuation after a host call, top-level await is left pending. The
// thread CPU clock preserves the CPU limit without counting blocked I/O.
#[cfg(unix)]
fn thread_cpu_time() -> Option<Duration> {
    let mut time = MaybeUninit::<libc::timespec>::uninit();
    // SAFETY: `time` points to writable storage for one `timespec`; a successful
    // call initializes it before `assume_init`, and the clock id has no caller-
    // owned lifetime or aliasing requirements.
    if unsafe { libc::clock_gettime(libc::CLOCK_THREAD_CPUTIME_ID, time.as_mut_ptr()) } != 0 {
        return None;
    }
    // SAFETY: the successful `clock_gettime` call above initialized `time`.
    let time = unsafe { time.assume_init() };
    let seconds = u64::try_from(time.tv_sec).ok()?;
    let nanoseconds = u32::try_from(time.tv_nsec).ok()?;
    (nanoseconds < 1_000_000_000).then(|| Duration::new(seconds, nanoseconds))
}

#[cfg(not(unix))]
fn thread_cpu_time() -> Option<Duration> {
    None
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
    #[serde(default)]
    image: Option<ImageRenderSpec>,
}
