//! Convention-file compilation into runtime buckets and unified access
//! rules: raw `_redirects`/`_headers` text becomes redirect/header buckets,
//! `Basic-Auth` operations become file-lane access rules with bcrypt verifier
//! secrets (the hashing itself lives in `crate::routing`), and legacy
//! Basic-Auth response-header shapes are stripped before artifacts are
//! written. Ported from the donor finalizer's root `conventions.rs`.

use serde_json::{json, Map, Value};
use std::collections::BTreeMap;

use crate::finalize::{invalid, Result};
use crate::routing::{compile_routing_files, RoutingInput};
use crate::transforms::{lower_runtime_conventions, RuntimeConventionsInput};

#[derive(Default)]
pub struct CompiledConventions {
    pub redirects_exact: Option<Map<String, Value>>,
    pub redirects_pattern: Option<Vec<Value>>,
    pub headers_exact: Option<Map<String, Value>>,
    pub headers_pattern: Option<Vec<Value>>,
    pub access_rules: Vec<Value>,
    pub access_secrets: BTreeMap<String, String>,
    pub metadata_convention_files: Option<Value>,
}

pub fn convention_files_precompiled(body: &Value) -> Result<bool> {
    match body.get("convention_files_precompiled") {
        None => Ok(false),
        Some(Value::Bool(value)) => Ok(*value),
        Some(_) => invalid(
            "invalid_convention_files_precompiled",
            "convention_files_precompiled must be a boolean.",
        ),
    }
}

pub fn compile_conventions(
    raw: &Value,
    assigned_hostnames: Vec<String>,
    basic_auth_salt_context: String,
    diagnostics: &mut Vec<Value>,
) -> Result<CompiledConventions> {
    let Some(raw) = raw.as_object() else {
        return Ok(CompiledConventions::default());
    };
    let has_redirects = raw.get("redirects").and_then(Value::as_str).is_some();
    let has_headers = raw.get("headers").and_then(Value::as_str).is_some();
    if !has_redirects && !has_headers {
        return Ok(CompiledConventions::default());
    }
    let compilation = compile_routing_files(&RoutingInput {
        redirects: raw
            .get("redirects")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string(),
        headers: raw
            .get("headers")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string(),
        assigned_hostnames,
        basic_auth_allowed: None,
        basic_auth_salt_context: Some(basic_auth_salt_context),
    });
    diagnostics.extend(
        compilation
            .diagnostics
            .iter()
            .filter_map(|item| serde_json::to_value(item).ok()),
    );
    let access_rules = compilation
        .basic_auth
        .iter()
        .filter_map(|plan| serde_json::to_value(&plan.rule).ok())
        .collect();
    let access_secrets = compilation
        .basic_auth
        .iter()
        .flat_map(|plan| plan.secret_values.clone())
        .collect();
    let mut metadata_convention_files = raw.clone();
    if has_headers {
        metadata_convention_files.insert(
            "headers".into(),
            Value::String(compilation.sanitized_headers.clone().unwrap_or_default()),
        );
    }
    let lowered = lower_runtime_conventions(RuntimeConventionsInput {
        redirects: compilation
            .redirects
            .iter()
            .filter_map(|rule| serde_json::to_value(rule).ok())
            .collect(),
        headers: compilation
            .headers
            .iter()
            .filter_map(|rule| serde_json::to_value(rule).ok())
            .collect(),
    });
    Ok(CompiledConventions {
        redirects_exact: has_redirects.then_some(lowered.redirects_exact),
        redirects_pattern: has_redirects.then_some(lowered.redirects_pattern),
        headers_exact: has_headers.then_some(lowered.headers_exact),
        headers_pattern: has_headers.then_some(lowered.headers_pattern),
        access_rules,
        access_secrets,
        metadata_convention_files: Some(Value::Object(metadata_convention_files)),
    })
}

pub fn header_manifest(
    meta: &Map<String, Value>,
    exact: &Map<String, Value>,
    pattern: &[Value],
) -> Value {
    let mut root = meta.clone();
    root.insert(
        "headers".into(),
        json!({"exact":exact,"pattern":bucket_pattern_rules(pattern, "path")}),
    );
    // Basic-Auth never reaches this writer: Rust lowers it to unified access
    // rules before native or WASM callers cross the boundary. New artifacts
    // contain only the canonical response-header lane; the reader retains
    // compatibility with historical artifacts that still include `auth`.
    Value::Object(root)
}

pub fn strip_legacy_basic_auth(exact: &mut Map<String, Value>, pattern: &mut Vec<Value>) {
    exact.retain(|_, bucket| {
        let Some(rules) = bucket.as_array_mut() else {
            return false;
        };
        rules.retain_mut(strip_basic_auth_from_rule);
        !rules.is_empty()
    });
    pattern.retain_mut(strip_basic_auth_from_rule);
}

fn strip_basic_auth_from_rule(rule: &mut Value) -> bool {
    let Some(rule) = rule.as_object_mut() else {
        return false;
    };
    if let Some(operations) = rule.get_mut("operations").and_then(Value::as_array_mut) {
        operations
            .retain(|operation| operation.get("kind").and_then(Value::as_str) != Some("basicAuth"));
    }
    if let Some(headers) = rule.get_mut("headers").and_then(Value::as_object_mut) {
        headers.retain(|name, _| !name.eq_ignore_ascii_case("basic-auth"));
    }
    rule.get("operations")
        .and_then(Value::as_array)
        .is_some_and(|operations| !operations.is_empty())
        || rule
            .get("headers")
            .and_then(Value::as_object)
            .is_some_and(|headers| !headers.is_empty())
}

pub fn redirect_manifest(
    meta: &Map<String, Value>,
    exact: &Map<String, Value>,
    pattern: &[Value],
) -> Value {
    let mut root = meta.clone();
    root.insert("exact".into(), Value::Object(exact.clone()));
    root.insert("pattern".into(), bucket_pattern_rules(pattern, "source"));
    Value::Object(root)
}

fn bucket_pattern_rules(rules: &[Value], field: &str) -> Value {
    if rules.is_empty() {
        return Value::Array(Vec::new());
    }
    let mut fallback = Vec::new();
    let mut by_first_segment = Map::new();
    for rule in rules {
        let segment = rule
            .get(field)
            .and_then(Value::as_str)
            .filter(|path| path.starts_with('/'))
            .and_then(|path| path.trim_start_matches('/').split('/').next())
            .filter(|segment| !segment.is_empty() && !segment.contains([':', '*']));
        if let Some(segment) = segment {
            by_first_segment
                .entry(segment)
                .or_insert_with(|| Value::Array(Vec::new()))
                .as_array_mut()
                .expect("pattern bucket")
                .push(rule.clone());
        } else {
            fallback.push(rule.clone());
        }
    }
    json!({"fallback": fallback, "by_first_segment": by_first_segment})
}

pub fn inherit_redirect_metadata(
    exact: &mut Map<String, Value>,
    pattern: &mut [Value],
    source_exact: &Map<String, Value>,
    source_pattern: &[Value],
) {
    let source_rules = source_exact
        .values()
        .flat_map(|value| value.as_array().into_iter().flatten())
        .chain(source_pattern.iter());
    let metadata = source_rules
        .filter_map(|rule| Some((redirect_identity(rule)?, rule.clone())))
        .collect::<BTreeMap<_, _>>();
    for rule in exact
        .values_mut()
        .flat_map(|value| value.as_array_mut().into_iter().flatten())
        .chain(pattern.iter_mut())
    {
        let Some(key) = redirect_identity(rule) else {
            continue;
        };
        let Some(source) = metadata.get(&key) else {
            continue;
        };
        for field in ["planGated", "disabled", "disabledReason"] {
            if let Some(value) = source.get(field) {
                rule[field] = value.clone();
            }
        }
    }
}

fn redirect_identity(rule: &Value) -> Option<(u64, String)> {
    let order = rule.get("order")?.as_u64()?;
    let shape = [
        "source",
        "destination",
        "action",
        "status",
        "match",
        "regex",
        "host",
        "hostRegex",
        "force",
        "query",
        "conditions",
    ]
    .into_iter()
    .map(|field| rule.get(field).cloned().unwrap_or(Value::Null))
    .collect::<Vec<_>>();
    serde_json::to_string(&shape)
        .ok()
        .map(|shape| (order, shape))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn metadata_inheritance_distinguishes_same_target_proxy_and_redirect_rules() {
        let proxy = json!({
            "source":"/same",
            "destination":"https://api.example.test",
            "action":"proxy",
            "status":200,
            "match":"exact",
            "regex":null,
            "host":null,
            "hostRegex":null,
            "force":false,
            "query":null,
            "conditions":[],
            "order":0,
            "planGated":"external_proxy"
        });
        let redirect = json!({
            "source":"/same",
            "destination":"https://api.example.test",
            "action":"redirect",
            "status":301,
            "match":"exact",
            "regex":null,
            "host":null,
            "hostRegex":null,
            "force":false,
            "query":null,
            "conditions":[],
            "order":1
        });
        let mut exact = Map::from_iter([(
            "/same".into(),
            Value::Array(vec![without_metadata(&proxy), without_metadata(&redirect)]),
        )]);
        let source_exact = Map::from_iter([("/same".into(), Value::Array(vec![proxy, redirect]))]);

        inherit_redirect_metadata(&mut exact, &mut [], &source_exact, &[]);

        let rules = exact["/same"].as_array().expect("exact redirect bucket");
        assert_eq!(rules[0]["planGated"], json!("external_proxy"));
        assert!(rules[1].get("planGated").is_none());
    }

    #[test]
    fn legacy_basic_auth_never_enters_response_header_artifacts() {
        let mut exact = Map::from_iter([(
            "/protected".into(),
            json!([{
                "path": "/protected",
                "operations": [
                    {"kind": "basicAuth", "name": "Basic-Auth", "value": "secret"},
                    {"kind": "set", "name": "x-safe", "value": "yes"}
                ],
                "headers": {"Basic-Auth": "", "x-safe": "yes"}
            }]),
        )]);

        strip_legacy_basic_auth(&mut exact, &mut Vec::new());

        let rule = &exact["/protected"][0];
        assert_eq!(rule["operations"].as_array().map(Vec::len), Some(1));
        assert_eq!(rule["operations"][0]["kind"], "set");
        assert!(rule["headers"].get("Basic-Auth").is_none());
        assert_eq!(rule["headers"]["x-safe"], "yes");
    }

    // Adapted from the donor's finalize-level
    // `raw_basic_auth_compiles_to_unified_access_without_plaintext_output`:
    // the convention compiler is exercised directly (the donor additionally
    // compared native finalize output against the WASM `compile_routing_files`
    // shape and inspected metadata.json; both need the orchestration stage).
    // The verifier and no-plaintext assertions are donor-verbatim.
    #[test]
    fn raw_basic_auth_compiles_to_unified_access_without_plaintext_output() {
        let mut diagnostics = Vec::new();
        let compiled = compile_conventions(
            &json!({"headers":"/private\n  Basic-Auth: user:pass\n  X-Frame-Options: DENY"}),
            Vec::new(),
            "s/v".into(),
            &mut diagnostics,
        )
        .unwrap();
        assert_eq!(compiled.access_rules.len(), 1);
        let hash = compiled
            .access_secrets
            .get("headers-basic-auth-1")
            .expect("compiled verifier");
        let wasm_shape = compile_routing_files(&RoutingInput {
            headers: "/private\n  Basic-Auth: user:pass\n  X-Frame-Options: DENY".into(),
            basic_auth_salt_context: Some("s/v".into()),
            ..RoutingInput::default()
        });
        assert_eq!(
            wasm_shape.basic_auth[0].secret_values["headers-basic-auth-1"],
            *hash
        );
        assert!(bcrypt::verify("pass", hash).unwrap());
        assert!(!bcrypt::verify("wrong", hash).unwrap());
        let serialized = format!(
            "{}{}{}",
            serde_json::to_string(&compiled.access_rules).unwrap(),
            serde_json::to_string(&compiled.metadata_convention_files).unwrap(),
            serde_json::to_string(&diagnostics).unwrap(),
        );
        assert!(!serialized.contains("user:pass"));
        assert!(!serialized.contains("\"password\":\"pass\""));
        let metadata = serde_json::to_string(&compiled.metadata_convention_files).unwrap();
        assert!(!metadata.contains("user:pass"));
        assert!(metadata.contains("X-Frame-Options"));
    }

    // Adapted from the donor's finalize-level
    // `precompiled_convention_marker_must_be_boolean`.
    #[test]
    fn precompiled_convention_marker_must_be_boolean() {
        assert!(matches!(
            convention_files_precompiled(&json!({"convention_files_precompiled": "true"})),
            Err(crate::finalize::FinalizeError::Invalid {
                code: "invalid_convention_files_precompiled",
                ..
            })
        ));
        assert!(!convention_files_precompiled(&json!({})).unwrap());
        assert!(
            convention_files_precompiled(&json!({"convention_files_precompiled": true})).unwrap()
        );
    }

    fn without_metadata(value: &Value) -> Value {
        let mut value = value.clone();
        value
            .as_object_mut()
            .expect("redirect rule")
            .remove("planGated");
        value
    }
}
