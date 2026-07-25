//! Import rebind: when a space archive is imported into a NEW target space,
//! the previously-finalized Zero executable graph it carries is untrusted as a
//! set and bound to the source space. This module recompiles the imported
//! finalized sources for the target space (bytecode-only, ABI-v2 — no second
//! prelude), deletes the old executable graph, writes the rebuilt graph, and
//! rebinds the serving/php-manifest/metadata artifacts to the target identity.
//! Ported from the donor finalizer's `import_rebind.rs` onto trunk's artifact
//! shapes and `stattic_zero_runner::compile_finalized_endpoint_program`.

use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::fs;
use std::path::{Path, PathBuf};

use crate::finalize::{
    artifact_metadata, invalid, validate_relative_path, write_json, write_php, FinalizeError,
    Result,
};
use crate::model::{
    RuntimeDiagnosticSeverity, RuntimeZeroEndpoint, RuntimeZeroRun, ZeroEndpointArtifact,
    ZeroEndpointIndexArtifact, ZeroRunArtifact, ZeroRunIndexArtifact,
};
use crate::site_finalize::{exact_zero_lookup_action, write_zero_artifacts};
use crate::zero::recompile_finalized_zero_endpoints;

pub const IMPORT_REBIND_INPUT_FORMAT: &str = "spacefast.finalizer.import-rebind.input.v1";
pub const IMPORT_REBIND_OUTPUT_FORMAT: &str = "spacefast.finalizer.import-rebind.output.v1";

const ZERO_ENDPOINT_FORMAT: &str = "stattic.zero.endpoint.v1";
const ZERO_RUN_FORMAT: &str = "stattic.zero.run.v1";
const ZERO_ENDPOINTS_INDEX_FORMAT: &str = "stattic.zero.endpoints-index.v1";
const ZERO_RUNS_INDEX_FORMAT: &str = "stattic.zero.runs-index.v1";

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct ImportRebindInput {
    format: String,
    version_root: PathBuf,
    target_space_id: String,
    target_version_id: String,
    zero_mode: String,
    #[serde(default)]
    target_zero: Option<Value>,
    artifacts: ImportPhpArtifacts,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct ImportPhpArtifacts {
    serving: Value,
    php_manifest: Value,
    zero_routes: Option<Value>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ImportRebindOutput {
    format: &'static str,
    target_space_id: String,
    target_version_id: String,
    zero_rebound: bool,
    activating: bool,
    target_bindings_required: bool,
}

pub fn rebind_imported_version(mut input: ImportRebindInput) -> Result<ImportRebindOutput> {
    if input.format != IMPORT_REBIND_INPUT_FORMAT {
        return invalid(
            "import_rebind_input_format_invalid",
            "Unsupported imported-version rebind input format.",
        );
    }
    validate_id(&input.target_space_id, "space")?;
    validate_id(&input.target_version_id, "version")?;
    if !matches!(input.zero_mode.as_str(), "active" | "activating") {
        return invalid(
            "import_rebind_zero_mode_invalid",
            "Imported Zero mode must be active or activating.",
        );
    }
    let version_root =
        fs::canonicalize(&input.version_root).map_err(|source| FinalizeError::Io {
            path: input.version_root.clone(),
            source,
        })?;
    if !version_root.is_dir() {
        return invalid(
            "import_rebind_version_root_invalid",
            "Imported version root must be a directory.",
        );
    }

    // The imported Zero config is optional even for program-bearing versions
    // (trunk finalize writes zero/config.json only when the finalize body
    // carried a `zero` object). When present it must never carry the source
    // space's runtime bindings across the boundary.
    let zero_config_path = version_root.join("zero/config.json");
    let zero_rebound = zero_config_path.is_file();
    let zero_config = if zero_rebound {
        let mut config = read_json_object(&zero_config_path, "Imported Zero config is invalid.")?;
        if let Some(target_zero) = input.target_zero.take() {
            let target = require_object(target_zero, "Target Zero bindings are invalid.")?;
            config = zero_config_artifact(&target);
        } else if source_bound_zero_config(&config) {
            return Ok(ImportRebindOutput {
                format: IMPORT_REBIND_OUTPUT_FORMAT,
                target_space_id: input.target_space_id,
                target_version_id: input.target_version_id,
                zero_rebound: true,
                activating: input.zero_mode != "active",
                target_bindings_required: true,
            });
        } else {
            rebind_zero_config(&mut config);
        }
        Some(config)
    } else if input.target_zero.is_some() {
        return invalid(
            "import_rebind_zero_config_missing",
            "Target Zero bindings require an imported Zero config artifact.",
        );
    } else {
        None
    };

    let activating = input.zero_mode != "active";
    let metadata_path = version_root.join("metadata.json");
    let metadata = read_json_object(&metadata_path, "Imported version metadata is invalid.")?;
    let generated_at = metadata
        .get("generatedAt")
        .and_then(Value::as_str)
        .unwrap_or("1970-01-01T00:00:00Z")
        .to_string();
    let imported = read_imported_zero_programs(&version_root)?;
    let compiled = recompile_imported_zero(&generated_at, &imported)?;
    if input.artifacts.zero_routes.is_some() != compiled.zero_routes.is_some() {
        return invalid(
            "import_rebind_zero_routes_invalid",
            "Imported Zero routes do not match the recompiled endpoint graph.",
        );
    }
    // Imported migrations may only target the runtime's provider-managed
    // database. An application DATABASE_URL belongs to the target tenant and
    // must never receive schema statements reconstructed from an archive.
    let has_migrations = compiled
        .zero_migrations
        .as_ref()
        .is_some_and(|migrations| !migrations.statements.is_empty());
    let application_database = zero_config.as_ref().is_some_and(|config| {
        config.get("databaseUrlSource").and_then(Value::as_str) == Some("application")
    });
    if has_migrations && application_database {
        return invalid(
            "zero_db_destination_forbidden",
            "Imported Zero migrations require a provider-managed database.",
        );
    }

    // The imported program artifacts are untrusted as a set: the complete old
    // executable graph is deleted before the recompiled graph is written, so
    // stale archive bytecode cannot remain reachable through an overlooked
    // index.
    replace_zero_program_artifacts(&version_root, zero_config.as_ref(), &compiled)?;

    let mut serving = require_object(
        input.artifacts.serving,
        "Imported serving artifact is invalid.",
    )?;
    let mut php_manifest = require_object(
        input.artifacts.php_manifest,
        "Imported PHP manifest is invalid.",
    )?;
    php_manifest.insert("versionId".into(), json!(input.target_version_id));
    let compiled_routes: Vec<Value> = compiled
        .php_routes
        .iter()
        .filter_map(|record| serde_json::to_value(record).ok())
        .collect();
    replace_serving_zero_lookup(&mut serving, &compiled_routes)?;
    serving.insert("zero_routes".into(), json!(compiled.zero_routes.is_some()));
    replace_php_zero_routes(&mut php_manifest, &compiled_routes)?;
    write_php(&version_root.join("serving.php"), &Value::Object(serving))?;
    write_php(
        &version_root.join("php-manifest.php"),
        &Value::Object(php_manifest),
    )?;

    rebind_metadata(
        &metadata_path,
        &input.target_space_id,
        &input.target_version_id,
        &compiled_routes,
        compiled.run_artifacts.len(),
    )?;

    Ok(ImportRebindOutput {
        format: IMPORT_REBIND_OUTPUT_FORMAT,
        target_space_id: input.target_space_id,
        target_version_id: input.target_version_id,
        zero_rebound,
        activating,
        target_bindings_required: false,
    })
}

/// Recompiles the imported finalized programs through the trunk pipeline;
/// error-severity diagnostics abort the rebind (the archive graph stays
/// staged, never published).
fn recompile_imported_zero(
    generated_at: &str,
    imported: &ImportedZeroPrograms,
) -> Result<crate::zero::CompiledZeroEndpoints> {
    let mut diagnostics = Vec::new();
    let metadata = Value::Object(artifact_metadata(generated_at));
    let compiled = recompile_finalized_zero_endpoints(
        Some(&metadata),
        &imported.endpoints,
        &imported.runs,
        &mut diagnostics,
    );
    if let Some(error) = diagnostics
        .iter()
        .find(|diagnostic| diagnostic.severity == RuntimeDiagnosticSeverity::Error)
    {
        return invalid(
            "import_rebind_zero_compile_failed",
            format!("{}: {}", error.code, error.message),
        );
    }
    Ok(compiled)
}

/// Deletes the imported executable graph and writes the recompiled one (plus
/// the rebound zero/config.json when the archive carried one).
fn replace_zero_program_artifacts(
    version_root: &Path,
    zero_config: Option<&Map<String, Value>>,
    compiled: &crate::zero::CompiledZeroEndpoints,
) -> Result<()> {
    for relative in ["zero/endpoints", "zero/runs"] {
        let path = version_root.join(relative);
        if path.exists() {
            fs::remove_dir_all(&path).map_err(|source| FinalizeError::Io { path, source })?;
        }
    }
    for relative in [
        "zero/routes.json",
        "zero/routes.php",
        "zero/migrations.json",
        "zero/migrations.php",
        "zero/endpoints-index.json",
        "zero/endpoints-index.php",
        "zero/runs-index.json",
        "zero/runs-index.php",
    ] {
        let path = version_root.join(relative);
        if path.exists() {
            fs::remove_file(&path).map_err(|source| FinalizeError::Io { path, source })?;
        }
    }
    if let Some(config) = zero_config {
        write_json(
            &version_root.join("zero/config.json"),
            &Value::Object(config.clone()),
        )?;
    }
    write_zero_artifacts(version_root, compiled)
}

fn validate_id(value: &str, label: &str) -> Result<()> {
    if value.is_empty()
        || value.len() > 128
        || !value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
    {
        return invalid(
            "import_rebind_target_invalid",
            format!("Imported {label} id is invalid."),
        );
    }
    Ok(())
}

fn read_json_object(path: &Path, message: &'static str) -> Result<Map<String, Value>> {
    let value: Value =
        serde_json::from_slice(&fs::read(path).map_err(|source| FinalizeError::Io {
            path: path.into(),
            source,
        })?)
        .map_err(|source| FinalizeError::Json {
            path: path.into(),
            source,
        })?;
    require_object(value, message)
}

fn require_object(value: Value, message: &'static str) -> Result<Map<String, Value>> {
    value
        .as_object()
        .cloned()
        .ok_or_else(|| FinalizeError::Invalid {
            code: "import_rebind_artifact_invalid",
            message: message.into(),
            details: None,
        })
}

#[derive(Default)]
struct ImportedZeroPrograms {
    endpoints: Vec<RuntimeZeroEndpoint>,
    runs: Vec<RuntimeZeroRun>,
}

fn read_imported_zero_programs(version_root: &Path) -> Result<ImportedZeroPrograms> {
    let mut imported = ImportedZeroPrograms::default();
    let endpoint_index_path = version_root.join("zero/endpoints-index.json");
    if version_root.join("zero/endpoints").exists() && !endpoint_index_path.is_file() {
        return invalid(
            "import_rebind_zero_index_invalid",
            "Imported Zero endpoint programs require an endpoint index.",
        );
    }
    if endpoint_index_path.is_file() {
        let index: ZeroEndpointIndexArtifact = read_json(&endpoint_index_path)?;
        if index.format != ZERO_ENDPOINTS_INDEX_FORMAT
            || index.artifact_kind != "zero_endpoints_index"
        {
            return invalid(
                "import_rebind_zero_index_invalid",
                "Imported Zero endpoint index is invalid.",
            );
        }
        for (endpoint_id, artifact_path) in index.endpoints {
            validate_program_artifact_path(&artifact_path, "zero/endpoints/", ".json")?;
            let artifact: ZeroEndpointArtifact = read_json(&version_root.join(&artifact_path))?;
            if artifact.format != ZERO_ENDPOINT_FORMAT
                || artifact.kind != "endpoint"
                || artifact.endpoint_id != endpoint_id
            {
                return invalid(
                    "import_rebind_zero_artifact_invalid",
                    "Imported Zero endpoint artifact is invalid.",
                );
            }
            validate_program_artifact_path(&artifact.source_path, "zero/endpoints/", ".source.js")?;
            validate_program_artifact_path(
                &artifact.bytecode_path,
                "zero/endpoints/",
                ".bytecode",
            )?;
            let source = read_utf8(&version_root.join(&artifact.source_path))?;
            imported.endpoints.push(RuntimeZeroEndpoint {
                method: artifact.method,
                path: artifact.path,
                source,
                endpoint_id: Some(artifact.endpoint_id),
                schema_hash: artifact
                    .db
                    .get("schemaHash")
                    .and_then(Value::as_str)
                    .map(str::to_string),
                capabilities: artifact.capabilities,
                db: Some(artifact.db),
            });
        }
    }

    let run_index_path = version_root.join("zero/runs-index.json");
    if version_root.join("zero/runs").exists() && !run_index_path.is_file() {
        return invalid(
            "import_rebind_zero_index_invalid",
            "Imported Zero run programs require a run index.",
        );
    }
    if run_index_path.is_file() {
        let index: ZeroRunIndexArtifact = read_json(&run_index_path)?;
        if index.format != ZERO_RUNS_INDEX_FORMAT || index.artifact_kind != "zero_runs_index" {
            return invalid(
                "import_rebind_zero_index_invalid",
                "Imported Zero run index is invalid.",
            );
        }
        for (run_id, artifact_path) in index.runs {
            validate_program_artifact_path(&artifact_path, "zero/runs/", ".json")?;
            let artifact: ZeroRunArtifact = read_json(&version_root.join(&artifact_path))?;
            if artifact.format != ZERO_RUN_FORMAT
                || artifact.kind != "run"
                || artifact.run_id != run_id
            {
                return invalid(
                    "import_rebind_zero_artifact_invalid",
                    "Imported Zero run artifact is invalid.",
                );
            }
            validate_program_artifact_path(&artifact.source_path, "zero/runs/", ".source.js")?;
            validate_program_artifact_path(&artifact.bytecode_path, "zero/runs/", ".bytecode")?;
            let source = read_utf8(&version_root.join(&artifact.source_path))?;
            imported.runs.push(RuntimeZeroRun {
                run_id: artifact.run_id,
                source,
                schema_hash: artifact
                    .db
                    .get("schemaHash")
                    .and_then(Value::as_str)
                    .map(str::to_string),
                capabilities: artifact.capabilities,
                db: Some(artifact.db),
            });
        }
    }
    Ok(imported)
}

fn read_json<T: serde::de::DeserializeOwned>(path: &Path) -> Result<T> {
    serde_json::from_slice(&fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })?)
    .map_err(|source| FinalizeError::Json {
        path: path.into(),
        source,
    })
}

fn read_utf8(path: &Path) -> Result<String> {
    String::from_utf8(fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })?)
    .map_err(|_| FinalizeError::Invalid {
        code: "import_rebind_zero_source_invalid",
        message: "Imported Zero source must be UTF-8 JavaScript.".into(),
        details: None,
    })
}

fn validate_program_artifact_path(path: &str, prefix: &str, suffix: &str) -> Result<()> {
    validate_relative_path(path)?;
    if !path.starts_with(prefix) || !path.ends_with(suffix) {
        return invalid(
            "import_rebind_zero_artifact_invalid",
            "Imported Zero artifact path is invalid.",
        );
    }
    Ok(())
}

/// The trunk zero/config.json artifact shape (mirrors PHP's
/// `_stattic_runtime_zero_config_artifact` in management.php) built from the
/// caller-supplied target bindings.
fn zero_config_artifact(zero: &Map<String, Value>) -> Map<String, Value> {
    let mut artifact = Map::new();
    artifact.insert("runtimeKind".into(), json!("zero"));
    for key in [
        "artifact",
        "install",
        "variables",
        "auth",
        "realtime",
        "inspect",
    ] {
        artifact.insert(
            key.into(),
            zero.get(key)
                .filter(|value| value.is_object())
                .cloned()
                .unwrap_or_else(|| json!({})),
        );
    }
    let variable_values = zero
        .get("variableValues")
        .and_then(Value::as_object)
        .map(|values| {
            values
                .iter()
                .filter_map(|(name, value)| {
                    if name.is_empty() {
                        return None;
                    }
                    value.as_str().map(|value| (name.clone(), json!(value)))
                })
                .collect::<Map<String, Value>>()
        })
        .unwrap_or_default();
    artifact.insert("variableValues".into(), Value::Object(variable_values));
    if matches!(
        zero.get("databaseUrlSource").and_then(Value::as_str),
        Some("provider" | "application")
    ) {
        artifact.insert(
            "databaseUrlSource".into(),
            zero.get("databaseUrlSource")
                .cloned()
                .unwrap_or(Value::Null),
        );
    }
    if let Some(migrations) = zero.get("migrations") {
        artifact.insert("migrations".into(), migrations.clone());
    }
    artifact
}

fn rebind_zero_config(config: &mut Map<String, Value>) {
    // Archive exports are portable application artifacts, never portable
    // credentials or provider bindings. The target runtime injects its own
    // provider database after import; no source value crosses the boundary.
    config.insert("variableValues".into(), json!({}));
    config.insert("databaseUrlSource".into(), json!("provider"));
    config.insert("auth".into(), json!({}));
    config.insert("realtime".into(), json!({}));
}

fn source_bound_zero_config(config: &Map<String, Value>) -> bool {
    ["variableValues", "auth", "realtime"].iter().any(|key| {
        config
            .get(*key)
            .and_then(Value::as_object)
            .is_some_and(|value| !value.is_empty())
    })
}

/// A tenant-authored Zero dispatch leaf. Zero's own control routes
/// (config/run/auth/realtime) carry an `operation` string instead of an
/// endpoint_id/zero_artifact pair and are never rebound — they are platform
/// bootstrapping, not tenant code.
fn tenant_zero_action(value: &Value) -> bool {
    matches!(
        value.get("action").and_then(Value::as_str),
        Some("invoke_zero" | "zero_activating")
    ) && !value.get("operation").is_some_and(Value::is_string)
}

/// Drops the imported tenant Zero actions from the serving lookup map and
/// inserts the exact-match actions for the recompiled routes (the same entries
/// a trunk finalize generates).
fn replace_serving_zero_lookup(
    serving: &mut Map<String, Value>,
    compiled_routes: &[Value],
) -> Result<()> {
    let Some(lookup) = serving.get_mut("lookup") else {
        return Ok(());
    };
    // An empty PHP lookup map decodes from json_encode as `[]`, not `{}`.
    if lookup.as_array().is_some_and(Vec::is_empty) {
        *lookup = Value::Object(Map::new());
    }
    let lookup = lookup
        .as_object_mut()
        .ok_or_else(|| FinalizeError::Invalid {
            code: "import_rebind_artifact_invalid",
            message: "Imported serving lookup is invalid.".into(),
            details: None,
        })?;
    lookup.retain(|_, action| !tenant_zero_action(action));
    for record in compiled_routes {
        if let Some((path, action)) = exact_zero_lookup_action(record) {
            lookup.insert(path, action);
        }
    }
    Ok(())
}

fn replace_php_zero_routes(manifest: &mut Map<String, Value>, compiled: &[Value]) -> Result<()> {
    let routes = manifest
        .get_mut("routes")
        .and_then(Value::as_array_mut)
        .ok_or_else(|| FinalizeError::Invalid {
            code: "import_rebind_artifact_invalid",
            message: "Imported PHP manifest routes are invalid.".into(),
            details: None,
        })?;
    routes.retain(|route| !tenant_zero_action(route));
    let insertion = routes
        .iter()
        .rposition(|route| {
            route.get("action").and_then(Value::as_str) == Some("invoke_zero")
                && route.get("operation").is_some_and(Value::is_string)
        })
        .map_or(0, |index| index + 1);
    routes.splice(insertion..insertion, compiled.iter().cloned());
    Ok(())
}

fn rebind_metadata(
    path: &Path,
    space_id: &str,
    version_id: &str,
    compiled_routes: &[Value],
    run_count: usize,
) -> Result<()> {
    let mut metadata = read_json_object(path, "Imported version metadata is invalid.")?;
    metadata.insert("spaceId".into(), json!(space_id));
    metadata.insert("versionId".into(), json!(version_id));
    metadata.insert("routes".into(), Value::Array(compiled_routes.to_vec()));
    metadata.insert("zeroEndpointCount".into(), json!(compiled_routes.len()));
    metadata.insert("zeroRunCount".into(), json!(run_count));
    write_json(path, &Value::Object(metadata))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::sha256;
    use crate::zero::compile_zero_endpoints;
    use tempfile::TempDir;

    fn compiled_fixture(
        endpoints: &[RuntimeZeroEndpoint],
        runs: &[RuntimeZeroRun],
    ) -> crate::zero::CompiledZeroEndpoints {
        let mut diagnostics = Vec::new();
        let metadata = Value::Object(artifact_metadata("2026-07-18T00:00:00Z"));
        let compiled = compile_zero_endpoints(Some(&metadata), endpoints, runs, &mut diagnostics);
        assert!(
            !diagnostics
                .iter()
                .any(|d| d.severity == RuntimeDiagnosticSeverity::Error),
            "fixture compile failed: {diagnostics:?}"
        );
        compiled
    }

    fn write_version_fixture(
        version_root: &Path,
        compiled: &crate::zero::CompiledZeroEndpoints,
        config: Option<Value>,
    ) {
        fs::create_dir_all(version_root).unwrap();
        write_zero_artifacts(version_root, compiled).unwrap();
        if let Some(config) = config {
            write_json(&version_root.join("zero/config.json"), &config).unwrap();
        }
        let routes: Vec<Value> = compiled
            .php_routes
            .iter()
            .filter_map(|record| serde_json::to_value(record).ok())
            .collect();
        write_json(
            &version_root.join("metadata.json"),
            &json!({
                "spaceId": "spc_source",
                "versionId": "ver_source",
                "generatedAt": "2026-07-18T00:00:00Z",
                "routes": routes,
            }),
        )
        .unwrap();
    }

    fn endpoint(method: &str, path: &str, source: &str) -> RuntimeZeroEndpoint {
        RuntimeZeroEndpoint {
            method: method.into(),
            path: path.into(),
            source: source.into(),
            endpoint_id: Some(format!("{method} {path}")),
            schema_hash: None,
            capabilities: Default::default(),
            db: None,
        }
    }

    fn rebind_input(
        version_root: &Path,
        compiled: &crate::zero::CompiledZeroEndpoints,
        target_zero: Option<Value>,
    ) -> ImportRebindInput {
        let routes: Vec<Value> = compiled
            .php_routes
            .iter()
            .filter_map(|record| serde_json::to_value(record).ok())
            .collect();
        let mut lookup = Map::new();
        for record in &routes {
            if let Some((path, action)) = exact_zero_lookup_action(record) {
                lookup.insert(path, action);
            }
        }
        let mut manifest_routes = vec![json!({
            "action": "invoke_zero",
            "pattern": "/__spacefast/zero/run",
            "method": "POST",
            "operation": "run"
        })];
        manifest_routes.extend(routes.iter().cloned());
        ImportRebindInput {
            format: IMPORT_REBIND_INPUT_FORMAT.into(),
            version_root: version_root.to_path_buf(),
            target_space_id: "spc_target".into(),
            target_version_id: "ver_target".into(),
            zero_mode: "active".into(),
            target_zero,
            artifacts: ImportPhpArtifacts {
                serving: json!({
                    "lookup": Value::Object(lookup),
                    "zero_routes": compiled.zero_routes.is_some(),
                }),
                php_manifest: json!({
                    "format": "stattic.php.manifest.v1",
                    "versionId": "ver_source",
                    "routes": manifest_routes,
                }),
                zero_routes: compiled
                    .zero_routes
                    .as_ref()
                    .map(|routes| serde_json::to_value(routes).unwrap()),
            },
        }
    }

    #[test]
    fn rebind_clears_source_runtime_bindings_and_replaces_tenant_dispatch() {
        let mut config = Map::from_iter([
            (
                "variableValues".into(),
                json!({"DATABASE_URL":"mysql://source/forged","TOKEN":"source"}),
            ),
            ("auth".into(), json!({"signInUrl":"https://source/auth"})),
            (
                "realtime".into(),
                json!({"centralUrl":"https://source/events"}),
            ),
        ]);
        assert!(source_bound_zero_config(&config));
        rebind_zero_config(&mut config);
        assert_eq!(config["variableValues"], json!({}));
        assert_eq!(config["databaseUrlSource"], json!("provider"));
        assert_eq!(config["auth"], json!({}));
        assert_eq!(config["realtime"], json!({}));
        assert!(!source_bound_zero_config(&config));

        let mut manifest = Map::from_iter([(
            "routes".into(),
            json!([
                {"action":"invoke_zero","pattern":"/api/app","zeroArtifact":"zero/endpoints/app.json"},
                {"action":"invoke_zero","pattern":"/__spacefast/zero/run","operation":"run"}
            ]),
        )]);
        replace_php_zero_routes(
            &mut manifest,
            &[json!({"action":"invoke_zero","pattern":"/api/rebuilt","method":"GET","endpointId":"GET /api/rebuilt","zeroArtifact":"zero/endpoints/rebuilt.json"})],
        )
        .unwrap();
        // The control route survives; the tenant dispatch is replaced by the
        // recompiled route spliced after it.
        assert_eq!(manifest["routes"][0]["operation"], json!("run"));
        assert_eq!(manifest["routes"][1]["pattern"], json!("/api/rebuilt"));
        assert_eq!(
            manifest["routes"].as_array().map(Vec::len),
            Some(2),
            "the imported tenant route was dropped"
        );
    }

    #[test]
    fn import_rebuilds_self_hash_valid_malicious_bytecode_before_dispatch() {
        let root = TempDir::new().unwrap();
        let version_root = root.path().join("version");
        let endpoints = [endpoint(
            "GET",
            "/api/imported",
            "globalThis.__statticZeroResult = JSON.stringify({ status: 204, body: '' });",
        )];
        let compiled = compiled_fixture(&endpoints, &[]);
        write_version_fixture(&version_root, &compiled, None);

        // Forge: replace the bytecode with malicious bytes and update the
        // artifact's declared hash so the forged pair is self-consistent.
        let source_artifact = &compiled.endpoint_artifacts[0];
        let malicious = b"archive supplied bytes that are not QuickJS bytecode";
        fs::write(version_root.join(&source_artifact.bytecode_path), malicious).unwrap();
        let artifact_path =
            version_root.join(crate::zero::zero_endpoint_artifact_path(source_artifact));
        let mut forged: Value = read_json(&artifact_path).unwrap();
        forged["bytecodeSha256"] = json!(format!("sha256:{}", sha256(malicious)));
        write_json(&artifact_path, &forged).unwrap();

        let result = rebind_imported_version(rebind_input(&version_root, &compiled, None)).unwrap();
        assert!(!result.activating);
        assert!(!result.target_bindings_required);

        let rebuilt_index: ZeroEndpointIndexArtifact =
            read_json(&version_root.join("zero/endpoints-index.json")).unwrap();
        let rebuilt_artifact_path = rebuilt_index.endpoints.get("GET /api/imported").unwrap();
        let rebuilt_artifact: ZeroEndpointArtifact =
            read_json(&version_root.join(rebuilt_artifact_path)).unwrap();
        assert_eq!(rebuilt_artifact.runner_abi, "stattic-zero-runner-abi-v2");
        let rebuilt_bytecode =
            fs::read(version_root.join(&rebuilt_artifact.bytecode_path)).unwrap();
        assert_ne!(rebuilt_bytecode.as_slice(), malicious.as_slice());
        assert_eq!(
            rebuilt_artifact.bytecode_sha256,
            format!("sha256:{}", sha256(&rebuilt_bytecode))
        );

        // The rebuilt program executes under the target space identity.
        let invocation = json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": version_root,
            "endpointId": "GET /api/imported",
            "request": {
                "method": "GET", "path": "/api/imported", "uri": "/api/imported",
                "host": "target.test", "query": "", "headers": {}, "params": {}, "bodyBase64": ""
            },
            "context": {
                "spaceId": "spc_target", "versionId": "ver_target", "schemaHash": null,
                "authRef": "current", "variablesRef": "finalized"
            },
            "auth": {}, "variables": {}
        });
        assert!(stattic_zero_runner::handle_invoke(&invocation.to_string()).is_ok());

        // Target identity landed in the immutable metadata.
        let metadata = read_json_object(&version_root.join("metadata.json"), "metadata").unwrap();
        assert_eq!(metadata["spaceId"], json!("spc_target"));
        assert_eq!(metadata["versionId"], json!("ver_target"));
        assert_eq!(metadata["zeroEndpointCount"], json!(1));
    }

    #[test]
    fn source_bound_config_without_target_bindings_requires_bindings_and_rewrites_nothing() {
        let root = TempDir::new().unwrap();
        let version_root = root.path().join("version");
        let endpoints = [endpoint(
            "GET",
            "/api/bound",
            "globalThis.__statticZeroResult = JSON.stringify({ status: 204, body: '' });",
        )];
        let compiled = compiled_fixture(&endpoints, &[]);
        write_version_fixture(
            &version_root,
            &compiled,
            Some(json!({
                "runtimeKind": "zero",
                "variableValues": {"DATABASE_URL": "mysql://source/db"},
                "auth": {}, "realtime": {}
            })),
        );
        let before =
            fs::read(version_root.join(&compiled.endpoint_artifacts[0].bytecode_path)).unwrap();

        let result = rebind_imported_version(rebind_input(&version_root, &compiled, None)).unwrap();
        assert!(result.target_bindings_required);
        // Nothing was rewritten: the staged graph is untouched (and unpublished).
        let after =
            fs::read(version_root.join(&compiled.endpoint_artifacts[0].bytecode_path)).unwrap();
        assert_eq!(before, after);
        let config = read_json_object(&version_root.join("zero/config.json"), "config").unwrap();
        assert_eq!(
            config["variableValues"],
            json!({"DATABASE_URL": "mysql://source/db"})
        );

        // Supplying target bindings resolves the same import; the source
        // credentials never survive into the rebound config.
        let result = rebind_imported_version(rebind_input(
            &version_root,
            &compiled,
            Some(json!({
                "variableValues": {"DATABASE_URL": "mysql://target/db"},
                "databaseUrlSource": "provider"
            })),
        ))
        .unwrap();
        assert!(!result.target_bindings_required);
        let config = read_json_object(&version_root.join("zero/config.json"), "config").unwrap();
        assert_eq!(config["runtimeKind"], json!("zero"));
        assert_eq!(
            config["variableValues"],
            json!({"DATABASE_URL": "mysql://target/db"})
        );
        assert_eq!(config["databaseUrlSource"], json!("provider"));
    }

    #[test]
    fn db_bearing_programs_regenerate_target_scoped_migrations() {
        let root = TempDir::new().unwrap();
        let version_root = root.path().join("version");
        let endpoints = [RuntimeZeroEndpoint {
            method: "POST".into(),
            path: "/api/todos".into(),
            source: "globalThis.__statticZeroResult = JSON.stringify({ status: 204, body: '' });"
                .into(),
            endpoint_id: Some("POST /api/todos".into()),
            schema_hash: Some("sha256:todos".into()),
            capabilities: Default::default(),
            db: Some(json!({
                "schemaHash": "sha256:todos",
                "tables": {"todos": {
                    "physicalName": "lb_spc_source_todos",
                    "columns": {"id": "id", "title": "title"}
                }}
            })),
        }];
        let compiled = compiled_fixture(&endpoints, &[]);
        write_version_fixture(
            &version_root,
            &compiled,
            Some(json!({
                "runtimeKind": "zero",
                "variableValues": {},
                "auth": {},
                "realtime": {}
            })),
        );
        // Delete the staged migrations artifact so its presence after the
        // rebind proves regeneration, not carry-over.
        fs::remove_file(version_root.join("zero/migrations.json")).unwrap();
        fs::remove_file(version_root.join("zero/migrations.php")).unwrap();

        let error = rebind_imported_version(rebind_input(
            &version_root,
            &compiled,
            Some(json!({
                "variableValues": {"DATABASE_URL": "mysql://application/db"},
                "databaseUrlSource": "application"
            })),
        ))
        .unwrap_err();
        match error {
            FinalizeError::Invalid { code, .. } => {
                assert_eq!(code, "zero_db_destination_forbidden");
            }
            other => panic!("unexpected rebind result: {other}"),
        }

        rebind_imported_version(rebind_input(
            &version_root,
            &compiled,
            Some(json!({
                "variableValues": {"DATABASE_URL": "mysql://provider/db"},
                "databaseUrlSource": "provider"
            })),
        ))
        .unwrap();
        let migrations =
            read_json_object(&version_root.join("zero/migrations.json"), "migrations").unwrap();
        let statements = migrations["statements"].as_array().unwrap();
        assert_eq!(statements.len(), 1);
        assert!(statements[0]
            .as_str()
            .unwrap()
            .contains("CREATE TABLE IF NOT EXISTS `lb_spc_source_todos`"));
    }

    #[test]
    fn index_dropped_archive_with_live_dispatch_is_rejected() {
        let root = TempDir::new().unwrap();
        let version_root = root.path().join("version");
        let endpoints = [endpoint(
            "GET",
            "/api/exact",
            "globalThis.__statticZeroResult = '{}';",
        )];
        let compiled = compiled_fixture(&endpoints, &[]);
        write_version_fixture(&version_root, &compiled, None);
        // Tamper: drop the endpoint index while keeping bytecode and the live
        // dispatch artifacts — the shape a truncated or malicious export takes.
        fs::remove_file(version_root.join("zero/endpoints-index.json")).unwrap();

        let error =
            rebind_imported_version(rebind_input(&version_root, &compiled, None)).unwrap_err();
        match error {
            FinalizeError::Invalid { code, .. } => {
                assert_eq!(code, "import_rebind_zero_index_invalid");
            }
            other => panic!("unexpected rebind result: {other}"),
        }
    }
}
