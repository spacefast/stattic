use std::cell::RefCell;
use std::collections::BTreeMap;
use std::time::Instant;

use serde::Serialize;
use serde_json::{json, Value};

use crate::db::DbMetrics;

thread_local! {
    static STAGE_METRICS: RefCell<RunnerStageMetrics> = RefCell::new(RunnerStageMetrics::default());
}

#[derive(Debug, Serialize)]
pub struct RunnerResponse {
    pub status: u16,
    pub headers: BTreeMap<String, String>,
    pub body: String,
    #[serde(rename = "bodyBase64", skip_serializing_if = "Option::is_none")]
    pub body_base64: Option<String>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub events: Vec<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub metrics: Option<RunnerMetrics>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RunnerMetrics {
    pub duration_ms: u64,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub db: Option<DbMetrics>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub stages: Option<RunnerStageMetrics>,
}

#[derive(Clone, Debug, Default, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RunnerStageMetrics {
    pub envelope_parse_ms: f64,
    pub endpoint_index_ms: f64,
    pub artifact_read_ms: f64,
    pub bytecode_read_ms: f64,
    pub source_read_ms: f64,
    pub js_runtime_init_ms: f64,
    pub js_module_load_ms: f64,
    pub js_execution_ms: f64,
}

impl RunnerStageMetrics {
    fn has_samples(&self) -> bool {
        self.envelope_parse_ms > 0.0
            || self.endpoint_index_ms > 0.0
            || self.artifact_read_ms > 0.0
            || self.bytecode_read_ms > 0.0
            || self.source_read_ms > 0.0
            || self.js_runtime_init_ms > 0.0
            || self.js_module_load_ms > 0.0
            || self.js_execution_ms > 0.0
    }
}

pub(crate) fn write_response(response: RunnerResponse) {
    match serde_json::to_string(&response) {
        Ok(encoded) => println!("{encoded}"),
        Err(_) => println!(
            r#"{{"status":500,"headers":{{"content-type":"application/json; charset=utf-8"}},"body":"{{\"error\":{{\"code\":\"zero_runner_response_encode_failed\"}}}}"}}"#
        ),
    }
}

pub(crate) fn attach_runner_metrics(response: &mut RunnerResponse, started: Instant) {
    response.metrics = Some(RunnerMetrics {
        duration_ms: started.elapsed().as_millis().min(u128::from(u64::MAX)) as u64,
        db: crate::db::take_metrics(),
        stages: take_stage_metrics(),
    });
}

pub(crate) fn reset_stage_metrics() {
    STAGE_METRICS.with(|metrics| {
        *metrics.borrow_mut() = RunnerStageMetrics::default();
    });
}

fn take_stage_metrics() -> Option<RunnerStageMetrics> {
    STAGE_METRICS.with(|metrics| {
        let mut metrics = metrics.borrow_mut();
        if !metrics.has_samples() {
            return None;
        }
        Some(std::mem::take(&mut *metrics))
    })
}

pub(crate) fn record_envelope_parse(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.envelope_parse_ms += elapsed;
    });
}

pub(crate) fn record_endpoint_index(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.endpoint_index_ms += elapsed;
    });
}

pub(crate) fn record_artifact_read(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.artifact_read_ms += elapsed;
    });
}

pub(crate) fn record_bytecode_read(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.bytecode_read_ms += elapsed;
    });
}

pub(crate) fn record_source_read(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.source_read_ms += elapsed;
    });
}

pub(crate) fn record_js_runtime_init(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.js_runtime_init_ms += elapsed;
    });
}

pub(crate) fn record_js_module_load(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.js_module_load_ms += elapsed;
    });
}

pub(crate) fn record_js_execution(started: Instant) {
    record_stage(started, |metrics, elapsed| {
        metrics.js_execution_ms += elapsed;
    });
}

fn record_stage(started: Instant, update: impl FnOnce(&mut RunnerStageMetrics, f64)) {
    let elapsed = started.elapsed().as_secs_f64() * 1000.0;
    STAGE_METRICS.with(|metrics| update(&mut metrics.borrow_mut(), elapsed));
}

pub(crate) fn error_response(status: u16, code: &str, message: &str) -> RunnerResponse {
    json_response(
        status,
        json!({
            "error": {
                "code": code,
                "docsUrl": format!("https://spacefast.com/docs/errors/{code}"),
                "message": message,
            }
        }),
    )
}

pub(crate) fn json_response(status: u16, body: Value) -> RunnerResponse {
    let mut headers = BTreeMap::new();
    headers.insert(
        "content-type".to_string(),
        "application/json; charset=utf-8".to_string(),
    );
    RunnerResponse {
        status,
        headers,
        body: serde_json::to_string(&body).unwrap_or_else(|_| "{}".to_string()),
        body_base64: None,
        events: Vec::new(),
        metrics: None,
    }
}
