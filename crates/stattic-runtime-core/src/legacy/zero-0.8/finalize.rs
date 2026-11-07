use super::compile::{
    compile_zero_endpoints, zero_endpoint_artifact_path, zero_run_artifact_path,
    CompiledZeroEndpoints,
};
use super::inventory::ZERO_CONTROL_ROUTES;
use crate::finalize::{
    invalid, invalid_error, php_source, sha256, validate_relative_path, FinalizeError, Result,
};
use crate::model::{PhpActionRecord, RuntimeDiagnosticSeverity, SiteFinalizeInput};
use crate::storage::write_blob_backed;
use serde_json::{json, Map, Value};
use stattic_zero_runner::legacy_zero::{ZERO_ENDPOINTS_INDEX_FORMAT, ZERO_ENDPOINTS_INDEX_KIND};
use std::{collections::BTreeMap, fs, path::Path};
const ZERO_ROUTES_FORMAT: &str = "stattic.zero.routes.v1";
const ZERO_RUNS_INDEX_FORMAT: &str = "stattic.zero.runs-index.v1";

pub(crate) fn zero_route_can_own_root(zero_routes: &[PhpActionRecord]) -> bool {
    zero_routes
        .iter()
        .any(|PhpActionRecord::InvokeZero { pattern, .. }| pattern == "/:splat")
}

/// The Zero control and exact-endpoint routes, as response-table actions.
/// A pattern route stays in `zero/routes.php`, which the miss lane consults.
pub(crate) fn zero_response_actions(
    body: &Value,
    zero_routes: &[PhpActionRecord],
) -> Map<String, Value> {
    let mut actions = Map::new();
    if body.get("zero").is_some_and(Value::is_object) {
        for (path, method, operation) in ZERO_CONTROL_ROUTES {
            actions.insert(
                path.trim_start_matches('/').to_string(),
                json!({
                    "t": "zero",
                    "operation": operation,
                    "methods": zero_methods(method),
                }),
            );
        }
    }
    for PhpActionRecord::InvokeZero {
        pattern,
        method,
        execution_mode,
        endpoint_id,
        zero_artifact,
        schema_hash,
        capabilities,
    } in zero_routes
    {
        if pattern.contains(':') {
            continue;
        }
        let mut action = json!({
            "endpoint": endpoint_id,
            "artifact": zero_artifact,
            "execution_mode": execution_mode,
            "capabilities": capabilities,
        });
        if let Some(schema_hash) = schema_hash {
            action["schema_hash"] = json!(schema_hash);
        }
        let route = actions
            .entry(pattern.trim_matches('/').to_string())
            .or_insert_with(|| json!({"t": "zero", "methods": [], "endpoints": {}}));
        // Stored versions survive engine rollback. Older dispatchers read one
        // handler at the top level; prefer GET regardless of compiler order.
        if method == "GET" || route.get("endpoint").is_none() {
            for (key, value) in action.as_object().expect("Zero handler metadata") {
                route[key] = value.clone();
            }
            if schema_hash.is_none() {
                route
                    .as_object_mut()
                    .expect("Zero route metadata")
                    .remove("schema_hash");
            }
        }
        route["endpoints"][method] = action;
        let methods = route["methods"].as_array_mut().expect("Zero route methods");
        for allowed in zero_methods(method)
            .as_array()
            .expect("Zero endpoint methods")
        {
            if !methods.contains(allowed) {
                methods.push(allowed.clone());
            }
        }
    }
    actions
}

pub(crate) fn zero_methods(method: &str) -> Value {
    if method == "GET" {
        json!(["GET", "HEAD"])
    } else {
        json!([method])
    }
}

/// Compiles the Zero endpoints and run handlers. Error-severity diagnostics
/// abort the finalize before anything is published; info/warning diagnostics
/// join the shared stream.
pub(crate) fn compile_trunk_zero(
    input: &SiteFinalizeInput,
    artifact_meta: &Map<String, Value>,
    diagnostics: &mut Vec<Value>,
) -> Result<CompiledZeroEndpoints> {
    let mut zero_diagnostics = Vec::new();
    let metadata = Value::Object(artifact_meta.clone());
    let compiled = compile_zero_endpoints(
        Some(&metadata),
        &input.zero_endpoints,
        &input.zero_runs,
        &mut zero_diagnostics,
    );
    if let Some(error) = zero_diagnostics
        .iter()
        .find(|diagnostic| diagnostic.severity == RuntimeDiagnosticSeverity::Error)
    {
        if error.code == "zero_migrations_too_many" {
            return Err(FinalizeError::Invalid {
                code: "zero_migrations_too_many",
                message: error.message.clone(),
                details: error.details.clone().map(Value::Object),
            });
        }
        let code = match error.code.as_str() {
            "zero_endpoint_duplicate" => "zero_endpoint_duplicate",
            _ => "zero_endpoint_compile_failed",
        };
        return invalid(code, format!("{}: {}", error.code, error.message));
    }
    diagnostics.extend(
        zero_diagnostics
            .iter()
            .filter_map(|diagnostic| serde_json::to_value(diagnostic).ok()),
    );
    Ok(compiled)
}

/// A compiled Zero artifact as JSON. These types are finalizer-owned and always
/// serialize; a failure here is a bug in this crate, not bad publisher input.
pub(crate) fn serialized<T: serde::Serialize>(value: &T) -> Value {
    serde_json::to_value(value).expect("compiled Zero artifact serializes")
}

/// Writes the Zero pack into the version tree through the space CAS, so a
/// republish that compiles to the same bytes links the blob it already has
/// instead of storing another copy. Returns path → sha for `metadata.json`,
/// which is what keeps those blobs live for the collector.
pub(crate) fn write_zero_artifacts(
    stage_root: &Path,
    blobs: &Path,
    compiled: &CompiledZeroEndpoints,
) -> Result<BTreeMap<String, String>> {
    let mut written = BTreeMap::new();
    let mut place = |path: String, bytes: &[u8]| -> Result<()> {
        validate_relative_path(&path)?;
        let sha = write_blob_backed(blobs, &stage_root.join(&path), bytes)?;
        written.insert(path, sha);
        Ok(())
    };
    let json = |value: &Value| -> Result<Vec<u8>> {
        let mut bytes = serde_json::to_vec_pretty(value).map_err(|source| FinalizeError::Json {
            path: stage_root.join("zero"),
            source,
        })?;
        bytes.push(b'\n');
        Ok(bytes)
    };
    // Each Zero index is published twice — JSON for tooling, PHP for the serving
    // lane's `require` — and both spellings must always come from one value.
    for (basename, value) in [
        ("routes", compiled.zero_routes.as_ref().map(serialized)),
        (
            "migrations",
            compiled.zero_migrations.as_ref().map(serialized),
        ),
        (
            "endpoints-index",
            compiled.zero_endpoint_index.as_ref().map(serialized),
        ),
        (
            "runs-index",
            compiled.zero_run_index.as_ref().map(serialized),
        ),
    ] {
        let Some(value) = value else {
            continue;
        };
        place(format!("zero/{basename}.json"), &json(&value)?)?;
        place(
            format!("zero/{basename}.php"),
            php_source(&value).as_bytes(),
        )?;
    }
    for artifact in &compiled.endpoint_artifacts {
        place(
            zero_endpoint_artifact_path(artifact),
            &json(&serialized(artifact))?,
        )?;
    }
    for artifact in &compiled.run_artifacts {
        place(
            zero_run_artifact_path(artifact),
            &json(&serialized(artifact))?,
        )?;
    }
    for generated in &compiled.generated_files {
        place(generated.path.clone(), &generated.bytes)?;
    }
    Ok(written)
}

/// Validates the presence and internal consistency of the Zero artifacts
/// against the counts recorded in immutable metadata.
pub(crate) fn validate_zero_artifacts(
    version_root: &Path,
    endpoint_count: usize,
    run_count: usize,
) -> Result<()> {
    validate_zero_index(
        version_root,
        "zero/endpoints-index.json",
        ZERO_ENDPOINTS_INDEX_FORMAT,
        ZERO_ENDPOINTS_INDEX_KIND,
        "endpoints",
        endpoint_count,
        "endpointId",
    )?;
    validate_zero_routes(version_root, endpoint_count)?;
    validate_zero_index(
        version_root,
        "zero/runs-index.json",
        ZERO_RUNS_INDEX_FORMAT,
        "zero_runs_index",
        "runs",
        run_count,
        "runId",
    )
}

pub(crate) fn validate_zero_routes(version_root: &Path, expected_count: usize) -> Result<()> {
    let path = version_root.join("zero/routes.json");
    if expected_count == 0 {
        if path.exists() {
            return invalid(
                "runtime_artifact_validation_failed",
                "Unexpected zero/routes.json.",
            );
        }
        return Ok(());
    }
    let routes: Value = read_json_artifact(&path)?;
    if routes.get("format").and_then(Value::as_str) != Some(ZERO_ROUTES_FORMAT)
        || routes.get("artifact_kind").and_then(Value::as_str) != Some("zero_routes")
    {
        return invalid(
            "runtime_artifact_validation_failed",
            "zero/routes.json has invalid metadata.",
        );
    }
    let endpoint_index: Value =
        read_json_artifact(&version_root.join("zero/endpoints-index.json"))?;
    let endpoints = endpoint_index
        .get("endpoints")
        .and_then(Value::as_object)
        .ok_or_else(|| {
            invalid_error(
                "runtime_artifact_validation_failed",
                "Zero endpoint index entries are invalid.",
            )
        })?;
    let mut entries = Vec::new();
    entries.extend(
        routes
            .get("exact")
            .and_then(Value::as_array)
            .into_iter()
            .flatten(),
    );
    entries.extend(
        routes
            .get("by_first_segment")
            .and_then(Value::as_object)
            .into_iter()
            .flat_map(|buckets| buckets.values())
            .filter_map(Value::as_array)
            .flatten(),
    );
    entries.extend(
        routes
            .get("fallback")
            .and_then(Value::as_array)
            .into_iter()
            .flatten(),
    );
    if entries.len() != expected_count {
        return invalid(
            "runtime_artifact_validation_failed",
            "Zero route count does not match version metadata.",
        );
    }
    for entry in entries {
        let endpoint_id = entry.get("endpoint_id").and_then(Value::as_str);
        let artifact = entry.get("artifact").and_then(Value::as_str);
        if endpoint_id.is_none()
            || artifact.is_none()
            || endpoints
                .get(endpoint_id.unwrap_or_default())
                .and_then(Value::as_str)
                != artifact
            || !entry
                .get("execution_mode")
                .and_then(Value::as_str)
                .is_some_and(|mode| matches!(mode, "read" | "write"))
            || !entry
                .get("method")
                .and_then(Value::as_str)
                .is_some_and(|method| {
                    matches!(
                        method,
                        "GET" | "POST" | "PUT" | "PATCH" | "DELETE" | "OPTIONS"
                    )
                })
            || !entry
                .get("pattern")
                .and_then(Value::as_str)
                .is_some_and(|pattern| pattern.starts_with('/'))
        {
            return invalid(
                "runtime_artifact_validation_failed",
                "Zero route does not match the endpoint index.",
            );
        }
    }
    Ok(())
}

pub(crate) fn validate_zero_index(
    version_root: &Path,
    relative_path: &str,
    format: &str,
    artifact_kind: &str,
    entries_key: &str,
    expected_count: usize,
    identity_key: &str,
) -> Result<()> {
    let path = version_root.join(relative_path);
    if expected_count == 0 {
        if path.exists() {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("Unexpected {relative_path}."),
            );
        }
        return Ok(());
    }
    let index: Value = read_json_artifact(&path)?;
    if index.get("format").and_then(Value::as_str) != Some(format)
        || index.get("artifact_kind").and_then(Value::as_str) != Some(artifact_kind)
    {
        return invalid(
            "runtime_artifact_validation_failed",
            format!("{relative_path} has invalid metadata."),
        );
    }
    let entries = index
        .get(entries_key)
        .and_then(Value::as_object)
        .ok_or_else(|| {
            invalid_error(
                "runtime_artifact_validation_failed",
                format!("{relative_path} has invalid entries."),
            )
        })?;
    if entries.len() != expected_count {
        return invalid(
            "runtime_artifact_validation_failed",
            format!("{relative_path} count does not match version metadata."),
        );
    }
    for (identity, artifact_path) in entries {
        let artifact_path = artifact_path.as_str().ok_or_else(|| {
            invalid_error(
                "runtime_artifact_validation_failed",
                format!("{relative_path} contains an invalid artifact path."),
            )
        })?;
        validate_relative_path(artifact_path)?;
        let artifact: Value = read_json_artifact(&version_root.join(artifact_path))?;
        if artifact.get(identity_key).and_then(Value::as_str) != Some(identity) {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("{artifact_path} identity does not match its index."),
            );
        }
        for (path_key, hash_key) in [
            ("sourcePath", "sourceSha256"),
            ("bytecodePath", "bytecodeSha256"),
        ] {
            let generated_path =
                artifact
                    .get(path_key)
                    .and_then(Value::as_str)
                    .ok_or_else(|| {
                        invalid_error(
                            "runtime_artifact_validation_failed",
                            format!("{artifact_path} is missing {path_key}."),
                        )
                    })?;
            validate_relative_path(generated_path)?;
            let generated = fs::read(version_root.join(generated_path)).map_err(|source| {
                FinalizeError::Io {
                    path: version_root.join(generated_path),
                    source,
                }
            })?;
            let expected = artifact
                .get(hash_key)
                .and_then(Value::as_str)
                .and_then(|value| value.strip_prefix("sha256:"))
                .ok_or_else(|| {
                    invalid_error(
                        "runtime_artifact_validation_failed",
                        format!("{artifact_path} is missing {hash_key}."),
                    )
                })?;
            if sha256(&generated) != expected {
                return invalid(
                    "runtime_artifact_validation_failed",
                    format!("{generated_path} hash does not match its artifact."),
                );
            }
        }
    }
    Ok(())
}

pub(crate) fn read_json_artifact(path: &Path) -> Result<Value> {
    let bytes = fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })?;
    serde_json::from_slice(&bytes).map_err(|source| FinalizeError::Json {
        path: path.to_path_buf(),
        source,
    })
}
