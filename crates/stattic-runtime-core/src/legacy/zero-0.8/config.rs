use crate::config::current::retain_runtime_keys;
use crate::config::diagnostics::{diagnostic, DiagnosticSeverity, PrepareDiagnostic};
use serde_json::{json, Map, Value};

pub(crate) fn normalize_runtime(
    runtime: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    retain_runtime_keys(runtime, &["kind", "server", "client"], diagnostics);
    // Zero is never inferred: the server remains explicit. Server-only
    // capsules use the compiler's platform client when the default is absent.
    if !runtime.contains_key("client") {
        runtime.insert("client".into(), Value::String("client/index.tsx".into()));
    }
    for key in ["server", "client"] {
        let trimmed = runtime
            .get(key)
            .and_then(Value::as_str)
            .map(str::trim)
            .filter(|entry| !entry.is_empty())
            .map(str::to_owned);
        match trimmed {
                Some(entry) => {
                    runtime.insert(key.into(), Value::String(entry));
                }
                None => diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "config_runtime_entry_missing",
                    format!(
                        "runtime.{key} is required and must name a module when runtime.kind is \"zero\"."
                    ),
                    Some(format!("runtime.{key}")),
                )),
            }
    }
}

pub(crate) fn runtime_schema() -> Value {
    json!({
        "type": "object",
        "required": ["kind", "server"],
        "properties": {
            "kind": { "const": "zero" },
            "server": { "type": "string", "minLength": 1 },
            "client": { "type": "string", "minLength": 1, "default": "client/index.tsx" }
        },
        "additionalProperties": false
    })
}
