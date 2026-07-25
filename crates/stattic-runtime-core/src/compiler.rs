use serde_json::{json, Value};
use std::collections::BTreeMap;

use crate::artifacts::DEFAULT_EDGE_CACHE_CONTROL;
use crate::hash::stable_json_sha256;
use crate::model::*;
use crate::zero::{compile_zero_endpoints, zero_pack_sha256};

#[cfg(test)]
use crate::hash::sha256_hex;
#[cfg(test)]
use std::fs;

pub fn compile_build(input: RuntimeBuildInput) -> RuntimeCompileOutput {
    compile_runtime(RuntimeCompileRequest {
        mode: CompileMode::Build,
        version_id: input.version_id,
        source_root: Some(input.source_root),
        version_root: None,
        files: input.files,
        convention_files: input.convention_files,
        config: input.config,
        artifact_metadata: input.artifact_metadata,
        redirects_exact: input.redirects_exact,
        redirects_pattern: input.redirects_pattern,
        headers_exact: input.headers_exact,
        headers_pattern: input.headers_pattern,
        zero_endpoints: input.zero_endpoints,
        zero_runs: input.zero_runs,
    })
}

pub fn compile_finalize(input: RuntimeFinalizeInput) -> RuntimeCompileOutput {
    compile_runtime(RuntimeCompileRequest {
        mode: CompileMode::Finalize,
        version_id: input.version_id,
        source_root: None,
        version_root: Some(input.version_root),
        files: input.files,
        convention_files: input.convention_files,
        config: input.config,
        artifact_metadata: input.artifact_metadata,
        redirects_exact: input.redirects_exact,
        redirects_pattern: input.redirects_pattern,
        headers_exact: input.headers_exact,
        headers_pattern: input.headers_pattern,
        zero_endpoints: input.zero_endpoints,
        zero_runs: input.zero_runs,
    })
}

struct RuntimeCompileRequest {
    mode: CompileMode,
    version_id: Option<String>,
    source_root: Option<String>,
    version_root: Option<String>,
    files: Vec<RuntimeFile>,
    convention_files: RuntimeConventionFiles,
    config: Option<Value>,
    artifact_metadata: Option<Value>,
    redirects_exact: BTreeMap<String, Vec<Value>>,
    redirects_pattern: Vec<Value>,
    headers_exact: BTreeMap<String, Vec<Value>>,
    headers_pattern: Vec<Value>,
    zero_endpoints: Vec<RuntimeZeroEndpoint>,
    zero_runs: Vec<RuntimeZeroRun>,
}

fn compile_runtime(mut request: RuntimeCompileRequest) -> RuntimeCompileOutput {
    request.files.sort_by(|a, b| a.path.cmp(&b.path));
    let mut diagnostics = Vec::new();
    let compiled_zero = compile_zero_endpoints(
        request.artifact_metadata.as_ref(),
        &request.zero_endpoints,
        &request.zero_runs,
        &mut diagnostics,
    );
    let zero_pack_sha256 = zero_pack_sha256(&compiled_zero);
    let mut routes = compiled_zero.php_routes;
    routes.extend(compile_php_action_records(
        &request.files,
        &request.convention_files,
        request.config.as_ref(),
        &request.redirects_exact,
        &mut diagnostics,
    ));
    let header_artifact = compile_header_artifact(
        request.artifact_metadata.as_ref(),
        &request.headers_exact,
        &request.headers_pattern,
    );
    let redirect_artifact = compile_redirect_artifact(
        request.artifact_metadata.as_ref(),
        &request.redirects_exact,
        &request.redirects_pattern,
    );
    if request.config.is_some() {
        diagnostics.push(RuntimeDiagnostic {
            severity: RuntimeDiagnosticSeverity::Info,
            code: "runtime_config_seen".to_string(),
            message:
                "Runtime compiler received a config payload for canonical serving compilation."
                    .to_string(),
            path: None,
        });
    }
    let php_manifest = PhpManifest {
        format: PHP_MANIFEST_FORMAT.to_string(),
        version_id: request.version_id.clone(),
        routes,
    };
    let php_manifest_sha256 = stable_json_sha256(&php_manifest);
    let debug_json = json!({
        "format": OUTPUT_FORMAT,
        "mode": request.mode,
        "versionId": request.version_id,
        "fileCount": request.files.len(),
        "zeroEndpointCount": request.zero_endpoints.len(),
        "zeroRunCount": request.zero_runs.len(),
        "diagnostics": diagnostics,
    });
    let debug_json_sha256 = stable_json_sha256(&debug_json);
    let active = ActiveRuntimePack {
        format: "stattic.runtime.active.v1".to_string(),
        php_manifest_sha256: php_manifest_sha256.clone(),
        debug_json_sha256: debug_json_sha256.clone(),
        zero_pack_sha256,
    };
    RuntimeCompileOutput {
        format: OUTPUT_FORMAT.to_string(),
        mode: request.mode,
        version_id: request.version_id,
        source_root: request.source_root,
        version_root: request.version_root,
        diagnostics,
        artifacts: RuntimeArtifacts {
            php_manifest,
            php_manifest_sha256,
            debug_json_sha256,
            debug_json,
            header_artifact,
            redirect_artifact,
            zero_routes: compiled_zero.zero_routes,
            zero_migrations: compiled_zero.zero_migrations,
            zero_endpoint_index: compiled_zero.zero_endpoint_index,
            zero_endpoint_artifacts: compiled_zero.endpoint_artifacts,
            zero_run_index: compiled_zero.zero_run_index,
            zero_run_artifacts: compiled_zero.run_artifacts,
            generated_zero_files: compiled_zero.generated_files,
            active,
        },
    }
}

fn compile_php_action_records(
    files: &[RuntimeFile],
    convention_files: &RuntimeConventionFiles,
    config: Option<&Value>,
    redirects_exact: &BTreeMap<String, Vec<Value>>,
    diagnostics: &mut Vec<RuntimeDiagnostic>,
) -> Vec<PhpActionRecord> {
    let index_name = resolved_index_name(files, config);
    let mut routes = BTreeMap::new();
    for file in files {
        if file.path.starts_with("zero/endpoints/") {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Warning,
                code: "zero_artifact_input_ignored".to_string(),
                message:
                    "Uploaded Zero endpoint artifacts are ignored by the trusted compiler shell."
                        .to_string(),
                path: Some(file.path.clone()),
            });
            continue;
        }
        if file.path.ends_with('/') || private_runtime_file(&file.path) {
            continue;
        }
        for pattern in static_route_patterns(&file.path, index_name.as_deref()) {
            routes
                .entry(pattern.clone())
                .or_insert_with(|| PhpActionRecord::ServeStatic {
                    pattern,
                    file: file.path.clone(),
                    content_type: file.content_type.clone(),
                    etag: file.sha256.as_ref().map(|sha| {
                        if sha.starts_with("sha256:") {
                            sha.clone()
                        } else {
                            format!("sha256:{sha}")
                        }
                    }),
                });
        }
    }
    for (path, rules) in redirects_exact {
        let Some(rule) = first_redirect_rule(rules) else {
            continue;
        };
        let pattern = normalize_php_manifest_pattern(path);
        routes.insert(
            pattern.clone(),
            PhpActionRecord::Redirect {
                pattern,
                destination: redirect_destination(rule).unwrap_or_default().to_string(),
                status: redirect_status(rule),
                cache_control: redirect_cache_control(redirect_status(rule)).to_string(),
            },
        );
    }
    if convention_files.redirects.is_some()
        || convention_files.headers.is_some()
        || convention_files.routes.is_some()
    {
        diagnostics.push(RuntimeDiagnostic {
            severity: RuntimeDiagnosticSeverity::Info,
            code: "routing_conventions_deferred".to_string(),
            message: "Routing convention files were received; canonical route lowering is deferred to the next compiler phase.".to_string(),
            path: None,
        });
    }
    routes.into_values().collect()
}

fn first_redirect_rule(rules: &[Value]) -> Option<&Value> {
    rules
        .iter()
        .filter(|rule| {
            redirect_action(rule) == "redirect"
                && redirect_status_valid(redirect_status(rule))
                && redirect_destination(rule).is_some_and(|destination| !destination.is_empty())
        })
        .min_by_key(|rule| redirect_order(rule))
}

fn redirect_action(rule: &Value) -> &str {
    rule.get("action")
        .and_then(Value::as_str)
        .unwrap_or("redirect")
}

fn redirect_destination(rule: &Value) -> Option<&str> {
    rule.get("destination").and_then(Value::as_str)
}

fn redirect_status(rule: &Value) -> u16 {
    rule.get("status")
        .and_then(Value::as_u64)
        .and_then(|value| u16::try_from(value).ok())
        .unwrap_or(302)
}

fn redirect_order(rule: &Value) -> i64 {
    rule.get("order")
        .and_then(Value::as_i64)
        .unwrap_or(i64::MAX)
}

fn redirect_status_valid(status: u16) -> bool {
    matches!(status, 301 | 302 | 303 | 307 | 308)
}

fn redirect_cache_control(status: u16) -> &'static str {
    if matches!(status, 301 | 308) {
        "public, max-age=31536000, immutable"
    } else {
        DEFAULT_EDGE_CACHE_CONTROL
    }
}

fn compile_redirect_artifact(
    artifact_metadata: Option<&Value>,
    redirects_exact: &BTreeMap<String, Vec<Value>>,
    redirects_pattern: &[Value],
) -> Option<Value> {
    let exact: BTreeMap<String, Vec<Value>> = redirects_exact
        .iter()
        .filter(|(_, rules)| !rules.is_empty())
        .map(|(path, rules)| (path.clone(), rules.clone()))
        .collect();
    let pattern = bucket_pattern_rules(redirects_pattern, "source");

    if exact.is_empty() && pattern.is_none() {
        return None;
    }

    Some(with_artifact_metadata(
        artifact_metadata,
        json!({
        "exact": exact,
        "pattern": pattern.unwrap_or_else(|| json!([])),
        }),
    ))
}

fn compile_header_artifact(
    artifact_metadata: Option<&Value>,
    headers_exact: &BTreeMap<String, Vec<Value>>,
    headers_pattern: &[Value],
) -> Option<Value> {
    let mut response_exact: BTreeMap<String, Vec<Value>> = BTreeMap::new();
    let mut auth_exact: BTreeMap<String, Vec<Value>> = BTreeMap::new();
    let mut response_pattern = Vec::new();
    let mut auth_pattern = Vec::new();

    for (path, rules) in headers_exact {
        for rule in rules {
            if !rule.is_object() {
                continue;
            }
            if header_rule_has_basic_auth(rule) {
                auth_exact
                    .entry(path.clone())
                    .or_default()
                    .push(header_rule_with_compiled_credentials(rule));
            }
            if let Some(header_rule) = header_rule_without_basic_auth(rule) {
                response_exact
                    .entry(path.clone())
                    .or_default()
                    .push(header_rule);
            }
        }
    }
    for rule in headers_pattern {
        if !rule.is_object() {
            continue;
        }
        if header_rule_has_basic_auth(rule) {
            auth_pattern.push(header_rule_with_compiled_credentials(rule));
        }
        if let Some(header_rule) = header_rule_without_basic_auth(rule) {
            response_pattern.push(header_rule);
        }
    }

    let response_pattern = bucket_pattern_rules(&response_pattern, "path");
    let auth_pattern = bucket_pattern_rules(&auth_pattern, "path");

    if response_exact.is_empty()
        && auth_exact.is_empty()
        && response_pattern.is_none()
        && auth_pattern.is_none()
    {
        return None;
    }

    Some(with_artifact_metadata(
        artifact_metadata,
        json!({
        "headers": {
            "exact": response_exact,
            "pattern": response_pattern.unwrap_or_else(|| json!([])),
        },
        "auth": {
            "exact": auth_exact,
            "pattern": auth_pattern.unwrap_or_else(|| json!([])),
        },
        }),
    ))
}

fn with_artifact_metadata(artifact_metadata: Option<&Value>, mut artifact: Value) -> Value {
    let Some(metadata) = artifact_metadata.and_then(Value::as_object) else {
        return artifact;
    };
    let Some(artifact_object) = artifact.as_object_mut() else {
        return artifact;
    };
    for key in ["runtime_schema", "runtime_engine_version", "generated_at"] {
        if let Some(value) = metadata.get(key).cloned() {
            artifact_object.insert(key.to_string(), value);
        }
    }
    artifact
}

fn bucket_pattern_rules(rules: &[Value], path_field: &str) -> Option<Value> {
    if rules.is_empty() {
        return None;
    }

    let mut fallback = Vec::new();
    let mut by_first_segment: BTreeMap<String, Vec<Value>> = BTreeMap::new();
    for rule in rules {
        if !rule.is_object() {
            continue;
        }
        if let Some(bucket) = pattern_first_segment_bucket(rule, path_field) {
            by_first_segment
                .entry(bucket.to_string())
                .or_default()
                .push(rule.clone());
        } else {
            fallback.push(rule.clone());
        }
    }

    if fallback.is_empty() && by_first_segment.is_empty() {
        None
    } else {
        Some(json!({
            "fallback": fallback,
            "by_first_segment": by_first_segment,
        }))
    }
}

fn pattern_first_segment_bucket<'a>(rule: &'a Value, path_field: &str) -> Option<&'a str> {
    let path = rule.get(path_field).and_then(Value::as_str)?;
    if path.is_empty() || !path.starts_with('/') {
        return None;
    }
    let trimmed = path.trim_start_matches('/');
    if trimmed.is_empty() {
        return None;
    }
    let segment = trimmed.split('/').next()?;
    if segment.is_empty() || segment.contains(':') || segment.contains('*') {
        None
    } else {
        Some(segment)
    }
}

fn header_rule_has_basic_auth(rule: &Value) -> bool {
    rule.get("operations")
        .and_then(Value::as_array)
        .is_some_and(|operations| {
            operations
                .iter()
                .any(|operation| header_operation_kind(operation) == Some("basicAuth"))
        })
}

fn header_rule_without_basic_auth(rule: &Value) -> Option<Value> {
    let operations: Vec<Value> = rule
        .get("operations")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter(|operation| header_operation_kind(operation) != Some("basicAuth"))
        .cloned()
        .collect();
    if operations.is_empty() {
        return None;
    }

    let mut rule = rule.clone();
    if let Some(object) = rule.as_object_mut() {
        object.insert("operations".to_string(), Value::Array(operations));
        if let Some(headers) = object.get_mut("headers").and_then(Value::as_object_mut) {
            let basic_auth_keys: Vec<String> = headers
                .keys()
                .filter(|name| name.eq_ignore_ascii_case("basic-auth"))
                .cloned()
                .collect();
            for name in basic_auth_keys {
                headers.remove(&name);
            }
        }
    }
    Some(rule)
}

fn header_rule_with_compiled_credentials(rule: &Value) -> Value {
    let operations = rule
        .get("operations")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter(|operation| header_operation_kind(operation) == Some("basicAuth"))
        .map(|operation| {
            let mut operation = operation.clone();
            if let Some(object) = operation.as_object_mut() {
                let credentials = object
                    .get("value")
                    .and_then(Value::as_str)
                    .and_then(|value| serde_json::from_str::<Value>(value).ok())
                    .filter(Value::is_array)
                    .unwrap_or_else(|| Value::Array(Vec::new()));
                object.insert("value".to_string(), Value::String(String::new()));
                object.insert("credentials".to_string(), credentials);
            }
            operation
        })
        .collect();

    let mut rule = rule.clone();
    if let Some(object) = rule.as_object_mut() {
        object.insert("operations".to_string(), Value::Array(operations));
        object.insert("headers".to_string(), json!({}));
    }
    rule
}

fn header_operation_kind(operation: &Value) -> Option<&str> {
    operation
        .as_object()
        .and_then(|object| object.get("kind"))
        .and_then(Value::as_str)
}

fn resolved_index_name(files: &[RuntimeFile], config: Option<&Value>) -> Option<String> {
    if let Some(index) = config.and_then(|value| value.get("index")) {
        if index == false {
            return None;
        }
        if let Some(index) = index.as_str().filter(|value| !value.is_empty()) {
            return Some(normalize_manifest_path(index));
        }
    }

    if !files
        .iter()
        .any(|file| file.path == "index.html" && !private_runtime_file(&file.path))
    {
        if let Some(path) = single_public_html_path(files) {
            return Some(path);
        }
    }

    Some("index.html".to_string())
}

fn single_public_html_path(files: &[RuntimeFile]) -> Option<String> {
    let mut public_html = files
        .iter()
        .filter(|file| {
            !file.path.ends_with('/')
                && !private_runtime_file(&file.path)
                && file.path.ends_with(".html")
        })
        .map(|file| file.path.clone());
    let only = public_html.next()?;
    if public_html.next().is_none() {
        Some(only)
    } else {
        None
    }
}

fn static_route_patterns(file_path: &str, index_name: Option<&str>) -> Vec<String> {
    let mut patterns = vec![format!("/{file_path}")];
    let Some(index_name) = index_name else {
        return patterns;
    };

    if file_path == index_name {
        patterns.push("/".to_string());
        return patterns;
    }
    if let Some(prefix) = file_path.strip_suffix(&format!("/{index_name}")) {
        patterns.push(format!("/{prefix}"));
        patterns.push(format!("/{prefix}/"));
    }
    patterns
}

fn normalize_php_manifest_pattern(path: &str) -> String {
    let trimmed = path.trim_start_matches('/');
    if trimmed.is_empty() {
        "/".to_string()
    } else {
        format!("/{trimmed}")
    }
}

fn private_runtime_file(path: &str) -> bool {
    if let Some((base, extension)) = path.rsplit_once('.') {
        if (extension.eq_ignore_ascii_case("br") || extension.eq_ignore_ascii_case("gz"))
            && private_runtime_file(base)
        {
            return true;
        }
    }

    matches!(
        path,
        "_redirects"
            | "_headers"
            | "_config.json"
            | "_routes.json"
            | "stattic.jsonc"
            | "stattic.json"
    ) || path.eq_ignore_ascii_case("sf.jsonc")
        || path.eq_ignore_ascii_case("spacefast.jsonc")
        || path.eq_ignore_ascii_case("spacefast.json")
        || path.eq_ignore_ascii_case("sf.json")
        || path.eq_ignore_ascii_case(".sf/sf.json")
        || path.eq_ignore_ascii_case(".sf/config.jsonc")
        || path.eq_ignore_ascii_case(".sf/config.json")
}

fn normalize_manifest_path(path: &str) -> String {
    path.trim_start_matches('/').replace('\\', "/")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{read_finalize_input, write_output};
    use std::path::Path;

    fn invoke_compiled_zero(
        version_root: &Path,
        endpoint_id: &str,
        method: &str,
        path: &str,
    ) -> (u16, Value, Vec<Value>) {
        invoke_compiled_zero_artifact(version_root, endpoint_id, method, path, None)
    }

    fn invoke_compiled_zero_artifact(
        version_root: &Path,
        endpoint_id: &str,
        method: &str,
        path: &str,
        artifact_path: Option<&str>,
    ) -> (u16, Value, Vec<Value>) {
        let envelope = json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": version_root.to_string_lossy(),
            "endpointId": endpoint_id,
            "artifactPath": artifact_path,
            "request": {
                "method": method,
                "path": path,
                "uri": path,
                "host": "fixture.test",
                "query": "",
                "headers": {},
                "params": {},
                "bodyBase64": ""
            },
            "context": {
                "spaceId": "spc_zero_fixture",
                "versionId": "ver_zero_fixture",
                "schemaHash": null,
                "authRef": "current",
                "variablesRef": "finalized"
            },
            "auth": {
                "userId": "usr_zero_fixture",
                "isAuthenticated": true,
                "provider": "wpcom"
            },
            "variables": {
                "FEATURE_FLAG": "enabled"
            }
        })
        .to_string();
        let response = stattic_zero_runner::handle_invoke(&envelope).expect("compiled endpoint");
        let body = if response.body.is_empty() {
            Value::Null
        } else {
            serde_json::from_str(&response.body).expect("endpoint response body")
        };
        (response.status, body, response.events)
    }

    #[test]
    fn build_compiles_static_php_manifest_deterministically() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: Some("ver_test".to_string()),
            files: vec![
                RuntimeFile {
                    path: "docs/index.html".to_string(),
                    size: 12,
                    sha256: Some("def".to_string()),
                    content_type: Some("text/html".to_string()),
                },
                RuntimeFile {
                    path: "index.html".to_string(),
                    size: 10,
                    sha256: Some("abc".to_string()),
                    content_type: Some("text/html".to_string()),
                },
            ],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert_eq!(output.format, OUTPUT_FORMAT);
        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![
                PhpActionRecord::ServeStatic {
                    pattern: "/".to_string(),
                    file: "index.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:abc".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/docs".to_string(),
                    file: "docs/index.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:def".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/docs/".to_string(),
                    file: "docs/index.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:def".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/docs/index.html".to_string(),
                    file: "docs/index.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:def".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/index.html".to_string(),
                    file: "index.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:abc".to_string()),
                },
            ]
        );
        assert_eq!(
            output.artifacts.php_manifest_sha256,
            stable_json_sha256(&output.artifacts.php_manifest)
        );
    }

    #[test]
    fn private_runtime_files_are_not_served_by_the_php_manifest() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: None,
            files: vec![
                RuntimeFile {
                    path: "_headers".to_string(),
                    size: 2,
                    sha256: Some("abc".to_string()),
                    content_type: Some("text/plain".to_string()),
                },
                RuntimeFile {
                    path: "_HEADERS".to_string(),
                    size: 2,
                    sha256: Some("abc0".to_string()),
                    content_type: Some("text/plain".to_string()),
                },
                RuntimeFile {
                    path: "sf.jsonc".to_string(),
                    size: 2,
                    sha256: Some("def".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "SF.JSONC".to_string(),
                    size: 2,
                    sha256: Some("def0".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "SF.JSONC.gz".to_string(),
                    size: 2,
                    sha256: Some("def0a".to_string()),
                    content_type: Some("application/gzip".to_string()),
                },
                RuntimeFile {
                    path: "spacefast.jsonc".to_string(),
                    size: 2,
                    sha256: Some("def1".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "spacefast.json".to_string(),
                    size: 2,
                    sha256: Some("def2".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "sf.json".to_string(),
                    size: 2,
                    sha256: Some("def3".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".sf/sf.json".to_string(),
                    size: 2,
                    sha256: Some("def4".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".sf/config.jsonc".to_string(),
                    size: 2,
                    sha256: Some("def5".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".sF/CoNfIg.JsOnC".to_string(),
                    size: 2,
                    sha256: Some("def5a".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".sf/config.json".to_string(),
                    size: 2,
                    sha256: Some("def6".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".SF/CONFIG.JSON".to_string(),
                    size: 2,
                    sha256: Some("def6a".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: ".SF/CONFIG.JSON.BR".to_string(),
                    size: 2,
                    sha256: Some("def6b".to_string()),
                    content_type: Some("application/octet-stream".to_string()),
                },
                RuntimeFile {
                    path: "stattic.jsonc".to_string(),
                    size: 2,
                    sha256: Some("def7".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "stattic.json".to_string(),
                    size: 2,
                    sha256: Some("def8".to_string()),
                    content_type: Some("application/json".to_string()),
                },
                RuntimeFile {
                    path: "public.txt".to_string(),
                    size: 2,
                    sha256: Some("fed".to_string()),
                    content_type: Some("text/plain".to_string()),
                },
            ],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![
                PhpActionRecord::ServeStatic {
                    pattern: "/_HEADERS".to_string(),
                    file: "_HEADERS".to_string(),
                    content_type: Some("text/plain".to_string()),
                    etag: Some("sha256:abc0".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/public.txt".to_string(),
                    file: "public.txt".to_string(),
                    content_type: Some("text/plain".to_string()),
                    etag: Some("sha256:fed".to_string()),
                },
            ]
        );
    }

    #[test]
    fn serving_config_controls_index_aliases() {
        let input_files = vec![RuntimeFile {
            path: "docs/index.html".to_string(),
            size: 12,
            sha256: Some("abc".to_string()),
            content_type: Some("text/html".to_string()),
        }];
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: None,
            files: input_files.clone(),
            convention_files: RuntimeConventionFiles::default(),
            config: Some(json!({ "index": false })),
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![PhpActionRecord::ServeStatic {
                pattern: "/docs/index.html".to_string(),
                file: "docs/index.html".to_string(),
                content_type: Some("text/html".to_string()),
                etag: Some("sha256:abc".to_string()),
            }]
        );

        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: None,
            files: input_files,
            convention_files: RuntimeConventionFiles::default(),
            config: Some(json!({ "index": "home.html" })),
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![PhpActionRecord::ServeStatic {
                pattern: "/docs/index.html".to_string(),
                file: "docs/index.html".to_string(),
                content_type: Some("text/html".to_string()),
                etag: Some("sha256:abc".to_string()),
            }]
        );
    }

    #[test]
    fn single_public_html_file_is_inferred_as_the_root_index() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: None,
            files: vec![RuntimeFile {
                path: "landing.html".to_string(),
                size: 12,
                sha256: Some("abc".to_string()),
                content_type: Some("text/html".to_string()),
            }],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![
                PhpActionRecord::ServeStatic {
                    pattern: "/".to_string(),
                    file: "landing.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:abc".to_string()),
                },
                PhpActionRecord::ServeStatic {
                    pattern: "/landing.html".to_string(),
                    file: "landing.html".to_string(),
                    content_type: Some("text/html".to_string()),
                    etag: Some("sha256:abc".to_string()),
                },
            ]
        );
    }

    #[test]
    fn uploaded_zero_artifacts_are_not_promoted_to_runtime_actions() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: None,
            files: vec![RuntimeFile {
                path: "zero/endpoints/uploaded.json".to_string(),
                size: 2,
                sha256: Some("abc".to_string()),
                content_type: Some("application/json".to_string()),
            }],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        assert!(output.artifacts.php_manifest.routes.is_empty());
        assert_eq!(output.diagnostics[0].code, "zero_artifact_input_ignored");
    }

    #[test]
    fn exact_redirects_override_static_routes_in_php_manifest() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: Some("ver_redirect".to_string()),
            files: vec![RuntimeFile {
                path: "old.html".to_string(),
                size: 10,
                sha256: Some("abc".to_string()),
                content_type: Some("text/html".to_string()),
            }],
            convention_files: RuntimeConventionFiles::default(),
            config: Some(json!({ "index": false })),
            artifact_metadata: None,
            redirects_exact: BTreeMap::from([(
                "/old.html".to_string(),
                vec![
                    json!({
                        "destination": "/later.html",
                        "status": 302,
                        "action": "redirect",
                        "order": 2
                    }),
                    json!({
                        "destination": "/new.html",
                        "status": 308,
                        "action": "redirect",
                        "order": 1
                    }),
                ],
            )]),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });

        assert_eq!(
            output.artifacts.php_manifest.routes,
            vec![PhpActionRecord::Redirect {
                pattern: "/old.html".to_string(),
                destination: "/new.html".to_string(),
                status: 308,
                cache_control: "public, max-age=31536000, immutable".to_string(),
            }]
        );
    }

    #[test]
    fn redirect_artifact_preserves_exact_rules_and_buckets_patterns() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: Some("ver_redirects".to_string()),
            files: Vec::new(),
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: Some(json!({
                "runtime_schema": "static-runtime-v2",
                "runtime_engine_version": "static-runtime-v2",
                "generated_at": "2026-06-24T00:00:00+00:00"
            })),
            redirects_exact: BTreeMap::from([(
                "/old".to_string(),
                vec![json!({
                    "destination": "/new",
                    "status": 302,
                    "action": "redirect",
                    "order": 1
                })],
            )]),
            redirects_pattern: vec![
                json!({
                    "source": "/assets/*",
                    "regex": "^/assets/(?<file>.*)$",
                    "destination": "/static/:file",
                    "status": 200,
                    "action": "rewrite",
                    "order": 2
                }),
                json!({
                    "source": "/*",
                    "regex": "^/(?<path>.*)$",
                    "destination": "/404.html",
                    "status": 404,
                    "action": "notFound",
                    "order": 3
                }),
            ],
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });

        assert_eq!(
            output.artifacts.redirect_artifact,
            Some(json!({
                "runtime_schema": "static-runtime-v2",
                "runtime_engine_version": "static-runtime-v2",
                "generated_at": "2026-06-24T00:00:00+00:00",
                "exact": {
                    "/old": [
                        {
                            "destination": "/new",
                            "status": 302,
                            "action": "redirect",
                            "order": 1
                        }
                    ]
                },
                "pattern": {
                    "fallback": [
                        {
                            "source": "/*",
                            "regex": "^/(?<path>.*)$",
                            "destination": "/404.html",
                            "status": 404,
                            "action": "notFound",
                            "order": 3
                        }
                    ],
                    "by_first_segment": {
                        "assets": [
                            {
                                "source": "/assets/*",
                                "regex": "^/assets/(?<file>.*)$",
                                "destination": "/static/:file",
                                "status": 200,
                                "action": "rewrite",
                                "order": 2
                            }
                        ]
                    }
                }
            }))
        );
    }

    #[test]
    fn exact_header_rules_split_response_headers_and_basic_auth() {
        let output = compile_build(RuntimeBuildInput {
            format: BUILD_INPUT_FORMAT.to_string(),
            source_root: "/tmp/site".to_string(),
            version_id: Some("ver_headers".to_string()),
            files: Vec::new(),
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: Some(json!({
                "runtime_schema": "static-runtime-v2",
                "runtime_engine_version": "static-runtime-v2",
                "generated_at": "2026-06-24T00:00:00+00:00"
            })),
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::from([(
                "/secure".to_string(),
                vec![json!({
                    "order": 7,
                    "operations": [
                        { "kind": "set", "name": "X-Secure", "value": "yes" },
                        {
                            "kind": "basicAuth",
                            "name": "Basic-Auth",
                            "value": "[{\"username\":\"deploy\",\"passwordHash\":\"hash\"}]"
                        }
                    ],
                    "headers": {
                        "Basic-Auth": "compiled",
                        "X-Secure": "yes"
                    }
                })],
            )]),
            headers_pattern: vec![
                json!({
                    "path": "/assets/*",
                    "regex": "^/assets/(?<file>[^/]+)$",
                    "order": 8,
                    "operations": [
                        { "kind": "set", "name": "X-Asset", "value": "name=:file" }
                    ],
                    "headers": { "X-Asset": "name=:file" }
                }),
                json!({
                    "path": "/*",
                    "regex": "^/(?<path>.*)$",
                    "order": 9,
                    "operations": [
                        {
                            "kind": "basicAuth",
                            "name": "Basic-Auth",
                            "value": "[{\"username\":\"fallback\",\"passwordHash\":\"hash2\"}]"
                        }
                    ],
                    "headers": {}
                }),
            ],
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });

        assert_eq!(
            output.artifacts.header_artifact,
            Some(json!({
                "runtime_schema": "static-runtime-v2",
                "runtime_engine_version": "static-runtime-v2",
                "generated_at": "2026-06-24T00:00:00+00:00",
                "headers": {
                    "exact": {
                        "/secure": [
                            {
                                "order": 7,
                                "operations": [
                                    { "kind": "set", "name": "X-Secure", "value": "yes" }
                                ],
                                "headers": {
                                    "X-Secure": "yes"
                                }
                            }
                        ]
                    },
                    "pattern": {
                        "fallback": [],
                        "by_first_segment": {
                            "assets": [
                                {
                                    "path": "/assets/*",
                                    "regex": "^/assets/(?<file>[^/]+)$",
                                    "order": 8,
                                    "operations": [
                                        { "kind": "set", "name": "X-Asset", "value": "name=:file" }
                                    ],
                                    "headers": { "X-Asset": "name=:file" }
                                }
                            ]
                        }
                    }
                },
                "auth": {
                    "exact": {
                        "/secure": [
                            {
                                "order": 7,
                                "operations": [
                                    {
                                        "kind": "basicAuth",
                                        "name": "Basic-Auth",
                                        "value": "",
                                        "credentials": [
                                            { "username": "deploy", "passwordHash": "hash" }
                                        ]
                                    }
                                ],
                                "headers": {}
                            }
                        ]
                    },
                    "pattern": {
                        "fallback": [
                            {
                                "path": "/*",
                                "regex": "^/(?<path>.*)$",
                                "order": 9,
                                "operations": [
                                    {
                                        "kind": "basicAuth",
                                        "name": "Basic-Auth",
                                        "value": "",
                                        "credentials": [
                                            { "username": "fallback", "passwordHash": "hash2" }
                                        ]
                                    }
                                ],
                                "headers": {}
                            }
                        ],
                        "by_first_segment": {}
                    }
                }
            }))
        );
    }

    #[test]
    fn finalize_compiles_zero_endpoints_to_manifest_routes_and_private_files() {
        let dir = tempfile::tempdir().unwrap();
        let output = compile_finalize(RuntimeFinalizeInput {
            format: FINALIZE_INPUT_FORMAT.to_string(),
            version_root: "/tmp/version".to_string(),
            previous_pack: None,
            version_id: Some("ver_zero".to_string()),
            files: vec![RuntimeFile {
                path: "index.html".to_string(),
                size: 10,
                sha256: Some("abc".to_string()),
                content_type: Some("text/html".to_string()),
            }],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: Some(json!({
                "runtime_schema": "static-runtime-v2",
                "runtime_engine_version": "static-runtime-v2",
                "generated_at": "2026-06-24T00:00:00+00:00"
            })),
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: vec![
                RuntimeZeroEndpoint {
                    method: "POST".to_string(),
                    path: "/api/status".to_string(),
                    source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: JSON.stringify({ dbHost: typeof globalThis.__statticDbHost, log: typeof globalThis.__statticLog }) });".to_string(),
                    endpoint_id: None,
                    schema_hash: None,
                    capabilities: ZeroCapabilities {
                        db: false,
                        fetch: false,
                        auth: false,
                        env: false,
                        realtime: false,
                        logging: false,
                    },
                    db: None,
                },
                RuntimeZeroEndpoint {
                    method: "GET".to_string(),
                    path: "/api/items/:id".to_string(),
                    source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });"
                        .to_string(),
                    endpoint_id: None,
                    schema_hash: Some("sha256:schema".to_string()),
                    capabilities: ZeroCapabilities {
                        db: true,
                        fetch: false,
                        auth: false,
                        env: false,
                        realtime: false,
                        logging: false,
                    },
                    db: Some(json!({
                        "tables": {
                            "todos": {
                                "physicalName": "zero_items",
                                "primaryKey": "id",
                                "columns": {
                                    "id": "todo_id",
                                    "title": { "physicalName": "todo_title" }
                                }
                            }
                        }
                    })),
                },
            ],
            zero_runs: Vec::new(),
        });

        assert!(output
            .diagnostics
            .iter()
            .all(|diagnostic| { diagnostic.severity != RuntimeDiagnosticSeverity::Error }));
        let exact_slug = format!(
            "post_api_status_{}",
            &sha256_hex("POST\n/api/status\n0".as_bytes())[..12]
        );
        let dynamic_slug = format!(
            "get_api_items_id_{}",
            &sha256_hex("GET\n/api/items/:id\n1".as_bytes())[..12]
        );
        assert_eq!(
            output.artifacts.php_manifest.routes[0],
            PhpActionRecord::InvokeZero {
                pattern: "/api/status".to_string(),
                method: "POST".to_string(),
                endpoint_id: "POST /api/status".to_string(),
                zero_artifact: format!("zero/endpoints/{exact_slug}.json"),
                schema_hash: None,
                capabilities: ZeroCapabilities {
                    db: false,
                    fetch: false,
                    auth: false,
                    env: false,
                    realtime: false,
                    logging: false,
                },
            }
        );
        assert_eq!(
            output.artifacts.php_manifest.routes[1],
            PhpActionRecord::InvokeZero {
                pattern: "/api/items/:id".to_string(),
                method: "GET".to_string(),
                endpoint_id: "GET /api/items/:id".to_string(),
                zero_artifact: format!("zero/endpoints/{dynamic_slug}.json"),
                schema_hash: Some("sha256:schema".to_string()),
                capabilities: ZeroCapabilities {
                    db: true,
                    fetch: false,
                    auth: false,
                    env: false,
                    realtime: false,
                    logging: false,
                },
            }
        );
        let zero_routes = output.artifacts.zero_routes.as_ref().expect("zero routes");
        assert_eq!(
            zero_routes.runtime_schema.as_deref(),
            Some("static-runtime-v2")
        );
        assert_eq!(
            zero_routes.runtime_engine_version.as_deref(),
            Some("static-runtime-v2")
        );
        assert_eq!(
            zero_routes.generated_at.as_deref(),
            Some("2026-06-24T00:00:00+00:00")
        );
        assert_eq!(zero_routes.exact.len(), 1);
        assert_eq!(zero_routes.by_first_segment["api"].len(), 1);
        let zero_endpoint_index = output
            .artifacts
            .zero_endpoint_index
            .as_ref()
            .expect("zero endpoint index");
        assert_eq!(
            zero_endpoint_index.runtime_schema.as_deref(),
            Some("static-runtime-v2")
        );
        let exact_artifact_path = format!("zero/endpoints/{exact_slug}.json");
        assert_eq!(
            zero_endpoint_index
                .endpoints
                .get("POST /api/status")
                .map(String::as_str),
            Some(exact_artifact_path.as_str())
        );
        let zero_migrations = output
            .artifacts
            .zero_migrations
            .as_ref()
            .expect("zero migrations");
        assert_eq!(
            zero_migrations.runtime_schema.as_deref(),
            Some("static-runtime-v2")
        );
        assert_eq!(zero_migrations.artifact_kind.as_str(), "zero_migrations");
        assert!(zero_migrations.statements.contains(&"CREATE TABLE IF NOT EXISTS `zero_items` (`todo_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `todo_title` TEXT NULL, PRIMARY KEY (`todo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci".to_string()));
        assert_eq!(output.artifacts.zero_endpoint_artifacts.len(), 2);
        assert!(!output.artifacts.zero_endpoint_artifacts[0].source_fallback);
        assert_eq!(
            output.artifacts.zero_endpoint_artifacts[1].db["tables"]["todos"]["quotedName"],
            json!("`zero_items`")
        );
        assert_eq!(
            output
                .artifacts
                .active
                .zero_pack_sha256
                .as_deref()
                .map(|hash| (hash.starts_with("sha256:"), hash.len())),
            Some((true, 71))
        );

        write_output(dir.path(), &output).unwrap();
        assert!(dir
            .path()
            .join(format!("zero/endpoints/{exact_slug}.json"))
            .exists());
        assert!(dir
            .path()
            .join(format!("zero/endpoints/{exact_slug}.source.js"))
            .exists());
        assert!(dir
            .path()
            .join(format!("zero/endpoints/{exact_slug}.bytecode"))
            .exists());
        assert!(dir.path().join("debug.json").exists());
        assert!(dir.path().join("zero/routes.php").exists());
        let (status, body, events) =
            invoke_compiled_zero(dir.path(), "POST /api/status", "POST", "/api/status");
        assert_eq!(status, 200);
        assert_eq!(
            body,
            json!({
                "dbHost": "undefined",
                "log": "undefined"
            })
        );
        assert!(events.is_empty());
    }

    #[test]
    fn zero_all_features_fixture_compiles_to_private_artifacts() {
        let fixture_path = Path::new(env!("CARGO_MANIFEST_DIR")).join(
            "../../e2e-tests/fixtures/zero-all-features/runtime-compiler-finalize-input.json",
        );
        let input = read_finalize_input(&fixture_path).expect("fixture input");
        let output = compile_finalize(input);

        assert!(
            output
                .diagnostics
                .iter()
                .all(|diagnostic| diagnostic.severity != RuntimeDiagnosticSeverity::Error),
            "fixture diagnostics: {:?}",
            output.diagnostics
        );
        assert_eq!(
            output
                .artifacts
                .php_manifest
                .routes
                .iter()
                .filter(|route| matches!(route, PhpActionRecord::InvokeZero { .. }))
                .count(),
            4
        );
        assert_eq!(output.artifacts.zero_endpoint_artifacts.len(), 4);
        assert_eq!(output.artifacts.zero_run_artifacts.len(), 2);
        assert!(output.artifacts.zero_routes.is_some());
        assert!(output.artifacts.zero_migrations.is_some());
        assert!(output.artifacts.zero_endpoint_index.is_some());
        assert!(output.artifacts.zero_run_index.is_some());
        assert_eq!(
            output
                .artifacts
                .zero_endpoint_index
                .as_ref()
                .and_then(|index| index.endpoints.get("GET /api/capabilities"))
                .map(String::as_str),
            Some("zero/endpoints/get_api_capabilities_d243f6db3bf8.json")
        );
        assert_eq!(
            output
                .artifacts
                .zero_run_index
                .as_ref()
                .and_then(|index| index.runs.get("mutation_addTodo"))
                .map(String::as_str),
            Some("zero/runs/mutation_addtodo_9a19c00926af.json")
        );
        assert_eq!(
            output
                .artifacts
                .zero_migrations
                .as_ref()
                .map(|migrations| migrations.statements.len()),
            Some(1)
        );

        let dir = tempfile::tempdir().unwrap();
        write_output(dir.path(), &output).unwrap();
        assert!(dir
            .path()
            .join("zero/endpoints/get_api_health_ed3265c4a864.bytecode")
            .exists());
        assert!(dir
            .path()
            .join("zero/endpoints/get_api_items_id_b706cfee8e0b.bytecode")
            .exists());
        assert!(dir
            .path()
            .join("zero/endpoints/post_api_items_9a1704424f51.bytecode")
            .exists());
        assert!(dir
            .path()
            .join("zero/endpoints/get_api_capabilities_d243f6db3bf8.bytecode")
            .exists());
        assert!(dir.path().join("zero/runs-index.json").exists());
        assert!(dir
            .path()
            .join("zero/runs/query_todos_bc0fc9be16e0.bytecode")
            .exists());
        assert!(dir
            .path()
            .join("zero/runs/mutation_addtodo_9a19c00926af.bytecode")
            .exists());

        let no_capabilities = json!({
            "db": false,
            "fetch": false,
            "auth": false,
            "env": false,
            "realtime": false,
            "logging": false
        });
        let (status, body, events) =
            invoke_compiled_zero(dir.path(), "GET /api/health", "GET", "/api/health");
        assert_eq!(status, 200);
        assert_eq!(body["installed"], no_capabilities);
        assert!(events.is_empty());

        let fetch_auth_env_logging = json!({
            "db": false,
            "fetch": true,
            "auth": true,
            "env": true,
            "realtime": false,
            "logging": true
        });
        let (status, body, events) = invoke_compiled_zero(
            dir.path(),
            "GET /api/capabilities",
            "GET",
            "/api/capabilities",
        );
        assert_eq!(status, 200);
        assert_eq!(body["installed"], fetch_auth_env_logging);
        assert_eq!(body["envKeys"], json!(["FEATURE_FLAG"]));
        assert_eq!(body["auth"]["userId"], "usr_zero_fixture");
        assert_eq!(events.len(), 1);
        assert_eq!(events[0]["event"], "zero.log");

        let db_realtime_logging = json!({
            "db": true,
            "fetch": false,
            "auth": false,
            "env": false,
            "realtime": true,
            "logging": true
        });
        let (status, body, events) =
            invoke_compiled_zero(dir.path(), "POST /api/items", "POST", "/api/items");
        assert_eq!(status, 201);
        assert_eq!(body["installed"], db_realtime_logging);
        assert_eq!(body["table"], "`zero_example_todos`");
        assert_eq!(body["realtime"]["ok"], true);
        assert_eq!(events.len(), 2);
        assert_eq!(events[0]["event"], "zero.realtime");
        assert_eq!(events[1]["event"], "zero.log");

        let (status, body, events) = invoke_compiled_zero_artifact(
            dir.path(),
            "mutation_addTodo",
            "POST",
            "/__spacefast/zero/run",
            Some("zero/runs/mutation_addtodo_9a19c00926af.json"),
        );
        assert_eq!(status, 200);
        assert_eq!(body["installed"], db_realtime_logging);
        assert_eq!(body["run"], "mutation_addTodo");
        assert_eq!(body["realtime"]["ok"], true);
        assert_eq!(events.len(), 2);
        assert_eq!(events[0]["event"], "zero.realtime");
        assert_eq!(events[1]["event"], "zero.log");
    }

    #[test]
    fn writes_json_artifacts() {
        let dir = tempfile::tempdir().unwrap();
        let output = compile_finalize(RuntimeFinalizeInput {
            format: FINALIZE_INPUT_FORMAT.to_string(),
            version_root: "/tmp/version".to_string(),
            previous_pack: None,
            version_id: Some("ver_final".to_string()),
            files: vec![RuntimeFile {
                path: "index.html".to_string(),
                size: 10,
                sha256: Some("abc".to_string()),
                content_type: Some("text/html".to_string()),
            }],
            convention_files: RuntimeConventionFiles::default(),
            config: None,
            artifact_metadata: None,
            redirects_exact: BTreeMap::new(),
            redirects_pattern: Vec::new(),
            headers_exact: BTreeMap::new(),
            headers_pattern: Vec::new(),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        write_output(dir.path(), &output).unwrap();
        assert!(dir.path().join("runtime-compile-output.json").exists());
        assert!(dir.path().join("debug.json").exists());
        assert!(dir.path().join("php-manifest.json").exists());
        assert!(dir.path().join("php-manifest.php").exists());
        assert!(dir.path().join("active.json").exists());
        assert!(dir.path().join("active.php").exists());
        let php_manifest = fs::read_to_string(dir.path().join("php-manifest.php")).unwrap();
        assert!(php_manifest.starts_with("<?php\nreturn [\n"));
        assert!(php_manifest.contains("'format' => 'stattic.php.manifest.v1'"));
        assert!(php_manifest.contains("'pattern' => '/'"));
    }

    #[test]
    fn zero_route_structure_is_validated_before_compilation() {
        let endpoint = |path: &str| -> RuntimeZeroEndpoint {
            serde_json::from_value(json!({
                "method": "GET",
                "path": path,
                "source": "globalThis.__statticZeroResult = '{}';"
            }))
            .expect("endpoint")
        };
        let compile = |paths: &[&str]| {
            compile_build(RuntimeBuildInput {
                format: BUILD_INPUT_FORMAT.to_string(),
                source_root: "/tmp/site".to_string(),
                version_id: Some("ver_zero_paths".to_string()),
                files: Vec::new(),
                convention_files: RuntimeConventionFiles::default(),
                config: None,
                artifact_metadata: None,
                redirects_exact: BTreeMap::new(),
                redirects_pattern: Vec::new(),
                headers_exact: BTreeMap::new(),
                headers_pattern: Vec::new(),
                zero_endpoints: paths.iter().map(|path| endpoint(path)).collect(),
                zero_runs: Vec::new(),
            })
        };

        let long_segment = "a".repeat(2_049);
        let invalid = [
            "/api/\0nul",
            "/api/../escape",
            "/api//empty",
            "/api/:",
            "/api/:splat/tail",
            long_segment.as_str(),
        ];
        for path in invalid {
            let output = compile([path].as_slice());
            assert!(
                output.diagnostics.iter().any(|diagnostic| {
                    diagnostic.severity == RuntimeDiagnosticSeverity::Error
                        && diagnostic.code == "zero_endpoint_invalid"
                }),
                "expected zero_endpoint_invalid for {path:?}"
            );
        }

        let reserved = compile(["/__spacefast/zero/config"].as_slice());
        assert!(reserved.diagnostics.iter().any(|diagnostic| {
            diagnostic.severity == RuntimeDiagnosticSeverity::Error
                && diagnostic.code == "zero_endpoint_conflict"
        }));

        let valid = compile(["/api/users/:id", "/api/:bad-name", "/files/:splat"].as_slice());
        assert!(
            !valid
                .diagnostics
                .iter()
                .any(|diagnostic| diagnostic.severity == RuntimeDiagnosticSeverity::Error),
            "structurally valid routes must not be rejected: {:?}",
            valid.diagnostics
        );
    }

    #[test]
    fn zero_capabilities_default_conservatively_when_metadata_is_absent_or_partial() {
        let missing: RuntimeZeroEndpoint = serde_json::from_value(json!({
            "method": "GET",
            "path": "/api/defaults",
            "source": "globalThis.__statticZeroResult = '{}';"
        }))
        .expect("endpoint");
        assert_eq!(missing.capabilities, ZeroCapabilities::default());

        let partial: RuntimeZeroEndpoint = serde_json::from_value(json!({
            "method": "GET",
            "path": "/api/partial",
            "source": "globalThis.__statticZeroResult = '{}';",
            "capabilities": { "db": false }
        }))
        .expect("endpoint");
        assert_eq!(
            partial.capabilities,
            ZeroCapabilities {
                db: false,
                fetch: true,
                auth: true,
                env: true,
                realtime: true,
                logging: true,
            }
        );
    }
}
