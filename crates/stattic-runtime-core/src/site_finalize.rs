//! The v2 site-finalize orchestration: walks and commits session files from
//! the private storage root, runs the content pipeline, compiles conventions,
//! routing, and trunk Zero endpoints, and publishes the immutable version
//! directory with restore-previous-version-on-failure backup semantics and
//! repeated-finalize idempotency. Ported from the donor finalizer's
//! `finalize_site`, with the donor Zero compiler replaced by trunk
//! `zero::compile_zero_endpoints` (abi-v2) and trunk artifact shapes.

use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Path, PathBuf};

use crate::access::{
    compile_conventions, convention_files_precompiled, header_manifest, inherit_redirect_metadata,
    redirect_manifest, strip_legacy_basic_auth, CompiledConventions,
};
use crate::artifacts::{
    build_lookup_map, generate_listing_artifacts, not_found_action, php_manifest, public_files,
    resolve_serving_config, static_lookup_action, validated_theme_css,
};
use crate::content::{materialize_html_pipeline, PIPELINE_SOURCE_MAX_BYTES};
use crate::finalize::{
    artifact_metadata, attach_precompressed_metadata, create_dir_all, invalid, read_bounded,
    remove_any, sha256, validate_relative_path, write_bytes, write_json, write_php, FileMeta,
    FinalizeError, Result, ARTIFACT_SCHEMA, ENGINE_VERSION,
};
use crate::hash::stable_json_sha256;
use crate::model::{
    RuntimeDiagnostic, RuntimeDiagnosticSeverity, SiteFinalizeInput, SiteFinalizeOutput,
    SITE_FINALIZE_OUTPUT_FORMAT,
};
use crate::policy::{validate_finalize_policy, FinalizePolicyContext};
use crate::storage::{apply_templates, commit_session_files};
use crate::version_files::{
    apply_access_pages, apply_page_artifacts, apply_template_variants, convention_files,
    resolved_viewer, validate_artifacts, validate_embedded_page_inputs, write_file_shards,
};
use crate::zero::{
    compile_zero_endpoints, zero_endpoint_artifact_path, zero_pack_sha256, zero_run_artifact_path,
    CompiledZeroEndpoints,
};

/// The committed paths that are always privately held configuration,
/// regardless of the pipeline-private set. Donor-authoritative list.
const PRIVATE_FILES: &[&str] = &[
    "_redirects",
    "_headers",
    "_config.json",
    "_routes.json",
    "sf.jsonc",
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];

const ZERO_ROUTES_FORMAT: &str = "stattic.zero.routes.v1";
const ZERO_ENDPOINTS_INDEX_FORMAT: &str = "stattic.zero.endpoints-index.v1";
const ZERO_RUNS_INDEX_FORMAT: &str = "stattic.zero.runs-index.v1";

pub fn finalize_site(input: SiteFinalizeInput) -> Result<SiteFinalizeOutput> {
    finalize_site_impl(input, false)
}

/// Runs the complete finalize pipeline — commits, transforms, compiles, and
/// validates into the staging directory — but discards the stage instead of
/// publishing the immutable version.
pub fn finalize_site_dry_run(input: SiteFinalizeInput) -> Result<SiteFinalizeOutput> {
    finalize_site_impl(input, true)
}

fn finalize_site_impl(input: SiteFinalizeInput, dry_run: bool) -> Result<SiteFinalizeOutput> {
    let input_hash = finalize_input_hash(&input);
    let session_mode = input
        .session
        .get("mode")
        .or_else(|| input.session.get("session_mode"))
        .and_then(Value::as_str)
        .unwrap_or("declared");
    let private_root = canonical_root(Path::new(&input.version_root))?;
    let version_parent = private_root
        .join("spaces")
        .join(&input.space_id)
        .join("versions");
    create_dir_all(&version_parent)?;
    let version_root = version_parent.join(&input.version_id);
    let backup = version_parent.join(format!(".{}.rust-previous", input.version_id));
    if backup.exists() {
        if version_root.exists() {
            remove_any(&backup)?;
        } else {
            fs::rename(&backup, &version_root).map_err(|source| FinalizeError::Io {
                path: version_root.clone(),
                source,
            })?;
        }
    }
    if version_root.exists() {
        return existing_finalize_output(&input, &version_root, &input_hash, session_mode);
    }
    validate_embedded_page_inputs(&input.body)?;
    let conventions_precompiled = convention_files_precompiled(&input.body)?;
    let stage_root = version_parent.join(format!(".{}.rust-finalizing", input.version_id));
    remove_any(&stage_root)?;
    create_dir_all(&stage_root.join("files"))?;

    let mut files = BTreeMap::<String, FileMeta>::new();
    let finalize_result = run_finalize_pipeline(
        &input,
        conventions_precompiled,
        &private_root,
        &stage_root,
        &mut files,
    );

    let (diagnostics, zero_endpoint_count, access_rules, access_secrets) = match finalize_result {
        Ok(result) => result,
        Err(error) => {
            let _ = remove_any(&stage_root);
            return Err(error);
        }
    };

    if dry_run {
        remove_any(&stage_root)?;
    } else {
        fs::rename(&stage_root, &version_root).map_err(|source| FinalizeError::Io {
            path: version_root,
            source,
        })?;
    }

    let committed_manifest = canonical_manifest(&files);
    Ok(SiteFinalizeOutput {
        format: SITE_FINALIZE_OUTPUT_FORMAT.to_string(),
        space_id: input.space_id,
        version_id: input.version_id,
        file_count: files.len(),
        zero_endpoint_count,
        access_rules,
        access_secrets,
        manifest: (session_mode == "open").then_some(committed_manifest),
        manifest_path: None,
        diagnostics,
    })
}

type PipelineResult = (
    Vec<RuntimeDiagnostic>,
    usize,
    Vec<Value>,
    BTreeMap<String, String>,
);

fn run_finalize_pipeline(
    input: &SiteFinalizeInput,
    conventions_precompiled: bool,
    private_root: &Path,
    stage_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<PipelineResult> {
    let input_hash = finalize_input_hash(input);
    commit_session_files(
        &input.space_id,
        input.upload_id.as_deref().unwrap_or_default(),
        &input.session,
        private_root,
        stage_root,
        files,
    )?;
    apply_templates(&input.body, stage_root, files)?;

    let serving = object_at(&input.body, "serving")
        .cloned()
        .unwrap_or_default();
    let config = serving
        .get("config")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let metadata = input
        .session
        .get("metadata")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let viewer = resolved_viewer(&metadata, &config);
    let mut diagnostics = Vec::new();
    let pipeline_private = materialize_html_pipeline(
        &stage_root.join("files"),
        files,
        &serving,
        &metadata,
        &viewer,
        &mut diagnostics,
    )?;

    apply_access_pages(&input.body, stage_root)?;
    apply_page_artifacts(&input.body, stage_root)?;
    let template_variants = apply_template_variants(&input.body, stage_root, files)?;
    let mut private = BTreeSet::from_iter(PRIVATE_FILES.iter().map(|v| (*v).to_string()));
    private.extend(pipeline_private);
    let serving_config = resolve_serving_config(&config, files, &private, &metadata)?;
    let generated = generate_listing_artifacts(
        &stage_root.join("files"),
        files,
        &serving_config,
        &metadata,
        &viewer,
        &serving,
        &private,
    )?;
    attach_precompressed_metadata(files);

    // Generated directory listings are public serving artifacts, not tenant
    // source files. Keep them out of scan/additive-publish manifests while
    // retaining their lookup and file-shard metadata for serving.
    let public_files = public_files(files, &private)
        .into_iter()
        .filter(|path| !generated.contains(path))
        .collect::<Vec<_>>();
    let convention_files = resolved_convention_files(input, stage_root, conventions_precompiled)?;

    let body_redirects_exact = serving
        .get("redirects_exact")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let body_redirects_pattern = serving
        .get("redirects_pattern")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    let body_headers_exact = serving
        .get("headers_exact")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let body_headers_pattern = serving
        .get("headers_pattern")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    let assigned_hostnames = input
        .body
        .get("routing_assigned_hostnames")
        .and_then(Value::as_array)
        .map(|hostnames| {
            hostnames
                .iter()
                .filter_map(Value::as_str)
                .map(str::to_string)
                .collect()
        })
        .unwrap_or_default();
    let compiled_conventions = if conventions_precompiled {
        CompiledConventions::default()
    } else {
        compile_conventions(
            &convention_files,
            assigned_hostnames,
            format!("{}/{}", input.space_id, input.version_id),
            &mut diagnostics,
        )?
    };
    let metadata_convention_files = compiled_conventions
        .metadata_convention_files
        .clone()
        .unwrap_or_else(|| convention_files.clone());
    // The finalized serving buckets are the v1 authority whenever the caller
    // supplied any redirect rules. Raw convention text is a fallback for
    // direct native callers that did not precompile `_redirects`; it must not
    // replace the complete body buckets with only the rules found in the
    // uploaded convention file.
    let body_redirects_present =
        !body_redirects_exact.is_empty() || !body_redirects_pattern.is_empty();
    let mut redirects_exact = if body_redirects_present {
        body_redirects_exact.clone()
    } else {
        compiled_conventions.redirects_exact.unwrap_or_default()
    };
    let mut redirects_pattern = if body_redirects_present {
        body_redirects_pattern.clone()
    } else {
        compiled_conventions.redirects_pattern.unwrap_or_default()
    };
    inherit_redirect_metadata(
        &mut redirects_exact,
        &mut redirects_pattern,
        &body_redirects_exact,
        &body_redirects_pattern,
    );
    // As with redirects, caller-prepared buckets remain authoritative when
    // present. Raw convention text is a fallback for native callers; replacing
    // prepared buckets here would discard generated safety rules such as the
    // no-store policy for negotiated /ai representations.
    let body_headers_present = !body_headers_exact.is_empty() || !body_headers_pattern.is_empty();
    let mut headers_exact = if body_headers_present {
        body_headers_exact
    } else {
        compiled_conventions.headers_exact.unwrap_or_default()
    };
    let mut headers_pattern = if body_headers_present {
        body_headers_pattern
    } else {
        compiled_conventions.headers_pattern.unwrap_or_default()
    };
    let access_rules = compiled_conventions.access_rules;
    let access_secrets = compiled_conventions.access_secrets;
    strip_legacy_basic_auth(&mut headers_exact, &mut headers_pattern);
    let artifact_meta = artifact_metadata(&input.generated_at);
    let compiled_zero = compile_trunk_zero(input, &artifact_meta, &mut diagnostics)?;
    validate_finalize_policy(FinalizePolicyContext {
        config: &config,
        files,
        redirects_exact: &redirects_exact,
        redirects_pattern: &redirects_pattern,
        headers_exact: &headers_exact,
        headers_pattern: &headers_pattern,
        body: &input.body,
        private: &private,
    })?;
    let header_manifest = header_manifest(&artifact_meta, &headers_exact, &headers_pattern);
    let redirect_manifest = redirect_manifest(&artifact_meta, &redirects_exact, &redirects_pattern);
    let mut lookup = build_lookup_map(
        files.keys(),
        &redirects_exact,
        &private,
        serving_config
            .get("index")
            .unwrap_or(&Value::String("index.html".into())),
        &generated,
    );
    let compiled_zero_routes: Vec<Value> = compiled_zero
        .php_routes
        .iter()
        .filter_map(|record| serde_json::to_value(record).ok())
        .collect();
    lookup.extend(zero_control_lookup_actions(&input.body));
    for record in &compiled_zero_routes {
        let Some((path, action)) = exact_zero_lookup_action(record) else {
            continue;
        };
        lookup.insert(path, action);
    }
    let php_manifest = php_manifest(&input.version_id, &lookup, &compiled_zero_routes);
    let nearest_404 = nearest_404(files.keys(), &private);
    let fallback = build_fallback(&serving_config, files, &private);
    let serving_viewer = metadata.get("viewer").cloned().unwrap_or_else(|| {
        json!({
            "title": metadata.get("title").cloned().unwrap_or(Value::Null),
            "description": metadata.get("description").cloned().unwrap_or(Value::Null),
            "og_image_path": metadata.get("og_image_path").cloned().unwrap_or(Value::Null),
        })
    });
    let zero_endpoint_count = compiled_zero.endpoint_artifacts.len();
    let zero_run_count = compiled_zero.run_artifacts.len();
    let serving_manifest = json!({
        "runtime_schema": ARTIFACT_SCHEMA,
        "runtime_engine_version": ENGINE_VERSION,
        "generated_at": input.generated_at,
        "serving_config": serving_config,
        "theme_css": validated_theme_css(serving.get("theme_css")),
        "file_shards": true,
        "header_artifact": header_artifact_required(&header_manifest),
        "redirect_artifact": redirect_artifact_required(&redirect_manifest),
        "php_manifest": true,
        "zero_routes": compiled_zero.zero_routes.is_some(),
        "template_variants": !template_variants.is_empty(),
        "concerns": concern_manifest(&header_manifest, &redirect_manifest),
        "lookup": lookup,
        "fallback": fallback,
        "nearest_404": nearest_404,
        "not_found": not_found_action(),
        "viewer": serving_viewer,
        "public_files": public_files,
    });

    let diagnostics = runtime_diagnostics(diagnostics);
    let canonical_manifest = canonical_manifest(files);
    write_json(
        &stage_root.join("metadata.json"),
        &json!({
            "versionId": input.version_id,
            "spaceId": input.space_id,
            "finalizeInputSha256": input_hash,
            "hostingKind": "static",
            "readyAt": input.ready_at,
            "servingConfig": serving_config,
            "siteTitle": metadata.get("title").and_then(Value::as_str).unwrap_or(""),
            "viewer": {
                "title": viewer.get("title").cloned().unwrap_or(Value::Null),
                "description": viewer.get("description").cloned().unwrap_or(Value::Null),
                "ogImagePath": viewer.get("og_image_path").cloned().unwrap_or(Value::Null),
            },
            "conventionFiles": metadata_convention_files,
            "routes": compiled_zero_routes,
            "zeroEndpointCount": zero_endpoint_count,
            "zeroRunCount": zero_run_count,
            "redirects": flatten_rules(&redirects_exact, &redirects_pattern),
            "headers": flatten_rules(&headers_exact, &headers_pattern),
            "accessRules": access_rules,
            "accessSecrets": access_secrets,
            "files": files,
            // Durable response and recovery truth for finalize retries after
            // the upload session has been consumed.
            "manifest": canonical_manifest,
            "diagnostics": diagnostics,
            "generatedAt": input.generated_at,
        }),
    )?;
    write_php(&stage_root.join("serving.php"), &serving_manifest)?;
    write_php(&stage_root.join("php-manifest.php"), &php_manifest)?;
    write_php(&stage_root.join("headers.php"), &header_manifest)?;
    write_php(&stage_root.join("redirects.php"), &redirect_manifest)?;
    write_zero_artifacts(stage_root, &compiled_zero)?;
    validate_zero_artifacts(stage_root, zero_endpoint_count, zero_run_count)?;
    write_active_pack(
        input,
        stage_root,
        &php_manifest,
        &compiled_zero,
        &diagnostics,
    )?;
    if !template_variants.is_empty() {
        let mut value = artifact_meta.clone();
        value.insert("routes".into(), Value::Object(template_variants));
        write_php(
            &stage_root.join("template-variants.php"),
            &Value::Object(value),
        )?;
    }
    write_file_shards(stage_root, files, &input.generated_at)?;
    validate_artifacts(stage_root, files)?;
    Ok((
        diagnostics,
        zero_endpoint_count,
        access_rules,
        access_secrets,
    ))
}

fn canonical_manifest(files: &BTreeMap<String, FileMeta>) -> Vec<Value> {
    files
        .iter()
        .map(|(path, file)| {
            json!({
                "path": path,
                "size": file.size,
                "sha256": file.sha256,
                "contentType": file.mime,
            })
        })
        .collect()
}

/// Convention text authority for v2: the session/body payloads and committed
/// `.stattic/routes.json` (as before), with raw `_redirects`/`_headers` read
/// from the committed files themselves when the payloads carry none — PHP no
/// longer pre-parses convention files.
fn resolved_convention_files(
    input: &SiteFinalizeInput,
    stage_root: &Path,
    precompiled: bool,
) -> Result<Value> {
    let mut resolved = convention_files(&input.session, &input.body, stage_root, precompiled)?;
    if !precompiled {
        if let Some(object) = resolved.as_object_mut() {
            for (key, file) in [("redirects", "_redirects"), ("headers", "_headers")] {
                if object.get(key).and_then(Value::as_str).is_some() {
                    continue;
                }
                let path = stage_root.join("files").join(file);
                if path.is_file() {
                    let bytes = read_bounded(&path, PIPELINE_SOURCE_MAX_BYTES)?;
                    object.insert(
                        key.into(),
                        Value::String(String::from_utf8_lossy(&bytes).into_owned()),
                    );
                }
            }
        }
    }
    Ok(resolved)
}

/// Compiles the trunk Zero endpoints and run handlers (abi-v2). Error-severity
/// diagnostics abort the finalize before anything is published; info/warning
/// diagnostics join the shared stream.
fn compile_trunk_zero(
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

/// The generated Zero control actions merged into the exact lookup map when a
/// version declares a Zero runtime.
fn zero_control_lookup_actions(body: &Value) -> Map<String, Value> {
    if !body.get("zero").is_some_and(Value::is_object) {
        return Map::new();
    }
    [
        ("/__spacefast/zero/config", "GET", "config"),
        ("/__spacefast/zero/run", "POST", "run"),
        ("/__spacefast/zero/auth/wpcom/start", "GET", "auth_start"),
        ("/__spacefast/zero/auth/sign-out", "GET", "auth_sign_out"),
        (
            "/__spacefast/zero/realtime/events",
            "GET",
            "realtime_events",
        ),
    ]
    .into_iter()
    .map(|(path, method, operation)| {
        (
            path.trim_start_matches('/').to_string(),
            json!({
                "action": "invoke_zero",
                "operation": operation,
                "methods": if method == "GET" { json!(["GET", "HEAD"]) } else { json!([method]) },
            }),
        )
    })
    .collect()
}

pub(crate) fn exact_zero_lookup_action(record: &Value) -> Option<(String, Value)> {
    let pattern = record.get("pattern")?.as_str()?;
    if pattern.contains(':') {
        return None;
    }
    let method = record.get("method")?.as_str()?;
    let mut action = json!({
        "action": "invoke_zero",
        "endpoint_id": record.get("endpointId")?.clone(),
        "zero_artifact": record.get("zeroArtifact")?.clone(),
        "methods": if method == "GET" { json!(["GET", "HEAD"]) } else { json!([method]) },
        "capabilities": record.get("capabilities").cloned().unwrap_or_else(|| json!({})),
    });
    if let Some(schema_hash) = record.get("schemaHash") {
        action["schema_hash"] = schema_hash.clone();
    }
    Some((pattern.trim_matches('/').to_string(), action))
}

pub(crate) fn write_zero_artifacts(
    stage_root: &Path,
    compiled: &CompiledZeroEndpoints,
) -> Result<()> {
    if let Some(routes) = &compiled.zero_routes {
        let routes = serde_json::to_value(routes).expect("Zero routes serialize");
        write_json(&stage_root.join("zero/routes.json"), &routes)?;
        write_php(&stage_root.join("zero/routes.php"), &routes)?;
    }
    if let Some(migrations) = &compiled.zero_migrations {
        let migrations = serde_json::to_value(migrations).expect("Zero migrations serialize");
        write_json(&stage_root.join("zero/migrations.json"), &migrations)?;
        write_php(&stage_root.join("zero/migrations.php"), &migrations)?;
    }
    if let Some(index) = &compiled.zero_endpoint_index {
        let index = serde_json::to_value(index).expect("Zero endpoint index serializes");
        write_json(&stage_root.join("zero/endpoints-index.json"), &index)?;
        write_php(&stage_root.join("zero/endpoints-index.php"), &index)?;
    }
    if let Some(index) = &compiled.zero_run_index {
        let index = serde_json::to_value(index).expect("Zero run index serializes");
        write_json(&stage_root.join("zero/runs-index.json"), &index)?;
        write_php(&stage_root.join("zero/runs-index.php"), &index)?;
    }
    for artifact in &compiled.endpoint_artifacts {
        write_json(
            &stage_root.join(zero_endpoint_artifact_path(artifact)),
            &serde_json::to_value(artifact).expect("Zero endpoint artifact serializes"),
        )?;
    }
    for artifact in &compiled.run_artifacts {
        write_json(
            &stage_root.join(zero_run_artifact_path(artifact)),
            &serde_json::to_value(artifact).expect("Zero run artifact serializes"),
        )?;
    }
    for generated in &compiled.generated_files {
        validate_relative_path(&generated.path)?;
        write_bytes(&stage_root.join(&generated.path), &generated.bytes)?;
    }
    Ok(())
}

/// The active runtime pack: content hashes of the serving surface so the
/// runtime can verify what it is executing.
fn write_active_pack(
    input: &SiteFinalizeInput,
    stage_root: &Path,
    php_manifest: &Value,
    compiled_zero: &CompiledZeroEndpoints,
    diagnostics: &[RuntimeDiagnostic],
) -> Result<()> {
    let debug_json = json!({
        "format": SITE_FINALIZE_OUTPUT_FORMAT,
        "spaceId": input.space_id,
        "versionId": input.version_id,
        "zeroEndpointCount": compiled_zero.endpoint_artifacts.len(),
        "zeroRunCount": compiled_zero.run_artifacts.len(),
        "diagnostics": diagnostics,
    });
    let active = json!({
        "format": "stattic.runtime.active.v1",
        "phpManifestSha256": stable_json_sha256(php_manifest),
        "debugJsonSha256": stable_json_sha256(&debug_json),
        "zeroPackSha256": zero_pack_sha256(compiled_zero),
    });
    write_json(&stage_root.join("debug.json"), &debug_json)?;
    write_json(&stage_root.join("active.json"), &active)?;
    write_php(&stage_root.join("active.php"), &active)
}

fn finalize_input_hash(input: &SiteFinalizeInput) -> String {
    let value = json!({
        "spaceId": input.space_id,
        "versionId": input.version_id,
        "uploadId": input.upload_id,
        "session": input.session,
        "body": input.body,
        "zeroEndpoints": input.zero_endpoints,
        "zeroRuns": input.zero_runs,
    });
    sha256(&serde_json::to_vec(&value).unwrap_or_default())
}

fn existing_finalize_output(
    input: &SiteFinalizeInput,
    version_root: &Path,
    input_hash: &str,
    session_mode: &str,
) -> Result<SiteFinalizeOutput> {
    let metadata_path = version_root.join("metadata.json");
    let bytes = fs::read(&metadata_path).map_err(|_| FinalizeError::Invalid {
        code: "version_existing_invalid",
        message: "The existing immutable version metadata is unavailable.".into(),
        details: None,
    })?;
    let metadata: Value = serde_json::from_slice(&bytes).map_err(|_| FinalizeError::Invalid {
        code: "version_existing_invalid",
        message: "The existing immutable version metadata is invalid.".into(),
        details: None,
    })?;
    let Some(metadata) = metadata.as_object() else {
        return invalid(
            "version_existing_invalid",
            "The existing immutable version metadata is invalid.",
        );
    };
    if metadata.get("spaceId").and_then(Value::as_str) != Some(&input.space_id)
        || metadata.get("versionId").and_then(Value::as_str) != Some(&input.version_id)
        || metadata.get("finalizeInputSha256").and_then(Value::as_str) != Some(input_hash)
    {
        return invalid(
            "version_existing_mismatch",
            "The existing immutable version was finalized from different input.",
        );
    }
    let Some(files) = metadata.get("files").and_then(Value::as_object) else {
        return invalid(
            "version_existing_invalid",
            "The existing immutable version file metadata is invalid.",
        );
    };
    for required in [
        "serving.php",
        "php-manifest.php",
        "headers.php",
        "redirects.php",
        "active.php",
    ] {
        if !version_root.join(required).is_file() {
            return invalid(
                "version_existing_invalid",
                format!("The existing immutable version is missing {required}."),
            );
        }
    }
    for path in files.keys() {
        validate_relative_path(path)?;
        if !version_root.join("files").join(path).is_file() {
            return invalid(
                "version_existing_invalid",
                format!("The existing immutable version is missing {path}."),
            );
        }
    }
    let zero_endpoint_count = metadata
        .get("zeroEndpointCount")
        .and_then(Value::as_u64)
        .and_then(|value| usize::try_from(value).ok())
        .unwrap_or(0);
    let zero_run_count = metadata
        .get("zeroRunCount")
        .and_then(Value::as_u64)
        .and_then(|value| usize::try_from(value).ok())
        .unwrap_or(0);
    validate_zero_artifacts(version_root, zero_endpoint_count, zero_run_count)?;
    let diagnostics = metadata
        .get("diagnostics")
        .cloned()
        .and_then(|value| serde_json::from_value::<Vec<RuntimeDiagnostic>>(value).ok())
        .ok_or_else(|| FinalizeError::Invalid {
            code: "version_existing_invalid",
            message: "The existing immutable version diagnostics are invalid.".into(),
            details: None,
        })?;
    let access_rules = metadata
        .get("accessRules")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    let access_secrets = metadata
        .get("accessSecrets")
        .and_then(Value::as_object)
        .map(|secrets| {
            secrets
                .iter()
                .filter_map(|(name, value)| Some((name.clone(), value.as_str()?.to_string())))
                .collect()
        })
        .unwrap_or_default();
    let manifest = if session_mode == "open" {
        Some(
            metadata
                .get("manifest")
                .and_then(Value::as_array)
                .cloned()
                .ok_or_else(|| FinalizeError::Invalid {
                    code: "version_existing_invalid",
                    message: "The existing immutable version manifest is invalid.".into(),
                    details: None,
                })?,
        )
    } else {
        None
    };
    Ok(SiteFinalizeOutput {
        format: SITE_FINALIZE_OUTPUT_FORMAT.to_string(),
        space_id: input.space_id.clone(),
        version_id: input.version_id.clone(),
        file_count: files.len(),
        zero_endpoint_count,
        access_rules,
        access_secrets,
        manifest,
        manifest_path: None,
        diagnostics,
    })
}

/// Validates the presence and internal consistency of the trunk Zero
/// artifacts against the counts recorded in immutable metadata. Donor
/// validation logic against trunk artifact formats.
fn validate_zero_artifacts(
    version_root: &Path,
    endpoint_count: usize,
    run_count: usize,
) -> Result<()> {
    validate_zero_index(
        version_root,
        "zero/endpoints-index.json",
        ZERO_ENDPOINTS_INDEX_FORMAT,
        "zero_endpoints_index",
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

fn validate_zero_routes(version_root: &Path, expected_count: usize) -> Result<()> {
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
        .ok_or_else(|| FinalizeError::Invalid {
            code: "runtime_artifact_validation_failed",
            message: "Zero endpoint index entries are invalid.".into(),
            details: None,
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

fn validate_zero_index(
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
        .ok_or_else(|| FinalizeError::Invalid {
            code: "runtime_artifact_validation_failed",
            message: format!("{relative_path} has invalid entries."),
            details: None,
        })?;
    if entries.len() != expected_count {
        return invalid(
            "runtime_artifact_validation_failed",
            format!("{relative_path} count does not match version metadata."),
        );
    }
    for (identity, artifact_path) in entries {
        let artifact_path = artifact_path
            .as_str()
            .ok_or_else(|| FinalizeError::Invalid {
                code: "runtime_artifact_validation_failed",
                message: format!("{relative_path} contains an invalid artifact path."),
                details: None,
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
                    .ok_or_else(|| FinalizeError::Invalid {
                        code: "runtime_artifact_validation_failed",
                        message: format!("{artifact_path} is missing {path_key}."),
                        details: None,
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
                .ok_or_else(|| FinalizeError::Invalid {
                    code: "runtime_artifact_validation_failed",
                    message: format!("{artifact_path} is missing {hash_key}."),
                    details: None,
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

fn read_json_artifact(path: &Path) -> Result<Value> {
    let bytes = fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })?;
    serde_json::from_slice(&bytes).map_err(|source| FinalizeError::Json {
        path: path.to_path_buf(),
        source,
    })
}

/// Lowers the shared `Vec<Value>` diagnostic stream the layer modules emit
/// into the trunk `RuntimeDiagnostic` wire shape.
fn runtime_diagnostics(values: Vec<Value>) -> Vec<RuntimeDiagnostic> {
    values
        .into_iter()
        .map(|value| RuntimeDiagnostic {
            severity: match value.get("severity").and_then(Value::as_str) {
                Some("info") => RuntimeDiagnosticSeverity::Info,
                Some("error") => RuntimeDiagnosticSeverity::Error,
                _ => RuntimeDiagnosticSeverity::Warning,
            },
            code: value
                .get("code")
                .and_then(Value::as_str)
                .unwrap_or("diagnostic")
                .to_string(),
            message: value
                .get("message")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string(),
            path: value
                .get("path")
                .or_else(|| value.get("file"))
                .and_then(Value::as_str)
                .map(str::to_string),
        })
        .collect()
}

fn flatten_rules(exact: &Map<String, Value>, pattern: &[Value]) -> Value {
    let mut out = Vec::new();
    for value in exact.values() {
        out.extend(value.as_array().cloned().unwrap_or_default())
    }
    out.extend_from_slice(pattern);
    Value::Array(out)
}

fn nearest_404<'a>(paths: impl Iterator<Item = &'a String>, private: &BTreeSet<String>) -> Value {
    let mut map = Map::new();
    for path in paths {
        if path.ends_with("404.html") && !private.contains(path) {
            let dir = path
                .strip_suffix("404.html")
                .unwrap_or("")
                .trim_matches('/');
            map.insert(dir.into(), static_lookup_action("nearest_404", path, 404));
        }
    }
    Value::Object(map)
}

fn build_fallback(
    config: &Map<String, Value>,
    files: &BTreeMap<String, FileMeta>,
    private: &BTreeSet<String>,
) -> Value {
    let Some(f) = config.get("fallback") else {
        return Value::Null;
    };
    let Some(path) = f.get("path").and_then(Value::as_str) else {
        return Value::Null;
    };
    if private.contains(path)
        || crate::serving_paths::is_private_serving_path(path)
        || !files.contains_key(path)
    {
        return Value::Null;
    }
    static_lookup_action(
        "fallback",
        path,
        f.get("status").and_then(Value::as_u64).unwrap_or(200),
    )
}

fn header_artifact_required(value: &Value) -> bool {
    value
        .pointer("/headers/exact")
        .and_then(Value::as_object)
        .is_some_and(|v| !v.is_empty())
        || value
            .pointer("/headers/pattern")
            .is_some_and(pattern_rules_present)
        || value
            .pointer("/auth/exact")
            .and_then(Value::as_object)
            .is_some_and(|v| !v.is_empty())
}

fn redirect_artifact_required(value: &Value) -> bool {
    value
        .get("exact")
        .and_then(Value::as_object)
        .is_some_and(|v| !v.is_empty())
        || value
            .get("pattern")
            .and_then(Value::as_object)
            .is_some_and(|v| !v.is_empty())
}

fn concern_manifest(headers: &Value, redirects: &Value) -> Value {
    json!({
        "headers": concern_section(
            headers.pointer("/headers/exact").and_then(Value::as_object),
            headers.pointer("/headers/pattern"),
        ),
        "auth": concern_section(
            headers.pointer("/auth/exact").and_then(Value::as_object),
            headers.pointer("/auth/pattern"),
        ),
        "redirects": concern_section(
            redirects.get("exact").and_then(Value::as_object),
            redirects.get("pattern"),
        ),
    })
}

fn concern_section(exact: Option<&Map<String, Value>>, pattern: Option<&Value>) -> Value {
    let paths = exact
        .into_iter()
        .flat_map(|entries| entries.iter())
        .filter(|(_, bucket)| bucket.as_array().is_some_and(|items| !items.is_empty()))
        .map(|(path, _)| (path.clone(), Value::Bool(true)))
        .collect::<Map<String, Value>>();
    let has_pattern = pattern.is_some_and(pattern_rules_present);
    json!({"exact": paths, "pattern": has_pattern})
}

fn pattern_rules_present(value: &Value) -> bool {
    match value {
        Value::Array(items) => !items.is_empty(),
        Value::Object(entries) => entries.values().any(pattern_rules_present),
        _ => false,
    }
}

fn object_at<'a>(value: &'a Value, key: &str) -> Option<&'a Map<String, Value>> {
    value.get(key).and_then(Value::as_object)
}

fn canonical_root(path: &Path) -> Result<PathBuf> {
    fs::canonicalize(path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::mime_for_path;
    use crate::model::{RuntimeZeroEndpoint, RuntimeZeroRun, SITE_FINALIZE_INPUT_FORMAT};
    use crate::routing::{compile_routing_files, RoutingInput};
    use tempfile::{tempdir, TempDir};

    fn write_upload(private: &Path, files: &[(&str, &[u8])]) -> Vec<Value> {
        let upload = private.join("runtime/uploads/u/files");
        fs::create_dir_all(&upload).unwrap();
        let mut manifest = Vec::new();
        for (path, bytes) in files {
            let target = upload.join(path);
            fs::create_dir_all(target.parent().unwrap()).unwrap();
            fs::write(target, bytes).unwrap();
            manifest.push(json!({
                "path": path,
                "size": bytes.len(),
                "sha256": sha256(bytes),
                "contentType": mime_for_path(path, None),
            }));
        }
        manifest
    }

    fn fixture_input(
        private: &Path,
        manifest: Vec<Value>,
        metadata: Value,
        body: Value,
    ) -> SiteFinalizeInput {
        SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.into(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".into(),
            version_id: "v".into(),
            upload_id: Some("u".into()),
            previous_pack: None,
            generated_at: "2026-07-12T00:00:00Z".into(),
            ready_at: 1,
            session: json!({"mode":"declared","files":manifest,"metadata":metadata}),
            body,
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        }
    }

    fn finalize_fixture(
        files: &[(&str, &[u8])],
        metadata: Value,
        body: Value,
    ) -> (TempDir, PathBuf, Result<SiteFinalizeOutput>) {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = write_upload(&private, files);
        let input = fixture_input(&private, manifest, metadata, body);
        let result = finalize_site(input);
        (temp, private, result)
    }

    #[test]
    fn heavy_many_page_finalize_materializes_rust_artifacts_and_html() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let upload = private.join("runtime/uploads/u/files");
        fs::create_dir_all(&upload).unwrap();
        let mut manifest = Vec::new();
        for index in 0..5_000 {
            let path = format!("docs/page-{index:05}.md");
            let body = format!("---\ntitle: Page {index}\ndescription: Heavy parser fixture\n---\n\n# Heading {index}\n\nBody with *markup*, [link](/docs/{index}), and `code-{index}`.\n");
            let target = upload.join(&path);
            fs::create_dir_all(target.parent().unwrap()).unwrap();
            fs::write(&target, &body).unwrap();
            manifest.push(json!({"path":path,"size":body.len(),"sha256":sha256(body.as_bytes()),"contentType":"text/markdown; charset=utf-8"}));
        }
        for (path,body) in [("_layout.html","<!doctype html><html><head><title>{{ page.title }}</title></head><body>{{ content }}</body></html>"),("_redirects","/old /docs/page-00001/ 301"),("_headers","/docs/*\n  X-Parser: rust")] {
            let target=upload.join(path);fs::create_dir_all(target.parent().unwrap()).unwrap();fs::write(&target,body).unwrap();manifest.push(json!({"path":path,"size":body.len(),"sha256":sha256(body.as_bytes())}));
        }
        let input = fixture_input(
            &private,
            manifest,
            json!({"title":"Heavy"}),
            json!({"convention_files":{"redirects":"/old /docs/page-00001/ 301","headers":"/docs/*\n  X-Parser: rust"},"serving":{"config":{"experimental_gutenberg":true,"meta":{"title":"Heavy"}},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        let output = finalize_site(input).unwrap();
        assert!(output.file_count >= 10_000);
        let html = fs::read_to_string(
            private.join("spaces/s/versions/v/files/docs/page-04999/index.html"),
        )
        .unwrap();
        assert!(html.contains("<title>Page 4999</title>"));
        assert!(html.contains("<em>markup</em>"));
        assert!(private.join("spaces/s/versions/v/serving.php").is_file());
        assert!(private.join("spaces/s/versions/v/file-shards").is_dir());
    }

    #[test]
    fn interrupted_replacement_recovers_the_previous_version_before_work() {
        let temp = tempdir().unwrap();
        let private = temp.path().join("storage");
        let versions = private.join("spaces/s/versions");
        let backup = versions.join(".v.rust-previous");
        fs::create_dir_all(backup.join("files")).unwrap();
        fs::write(backup.join("files/index.html"), b"live-before-interruption").unwrap();
        fs::create_dir_all(private.join("runtime/uploads/u/files")).unwrap();
        let result = finalize_site(fixture_input(
            &private,
            Vec::new(),
            json!({}),
            json!({"access_pages":{"unknown":"invalid"},"serving":{"config":{}}}),
        ));
        assert!(matches!(
            result,
            Err(FinalizeError::Invalid {
                code: "version_existing_invalid",
                ..
            })
        ));
        assert_eq!(
            fs::read(versions.join("v/files/index.html")).unwrap(),
            b"live-before-interruption"
        );
        assert!(!backup.exists());
    }

    #[test]
    fn runtime_routing_preserves_host_context_and_response_headers() {
        let (_temp, private, output) = finalize_fixture(
            &[("index.html", b"<h1>home</h1>")],
            json!({"mode":"website"}),
            json!({
                "convention_files":{
                    "redirects":"https://www.example.com/old /new 301",
                    "headers":"/private\n  X-Frame-Options: DENY"
                },
                "routing_assigned_hostnames":["www.example.com"],
                "serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}
            }),
        );
        output.unwrap();
        let redirects =
            fs::read_to_string(private.join("spaces/s/versions/v/redirects.php")).unwrap();
        let headers = fs::read_to_string(private.join("spaces/s/versions/v/headers.php")).unwrap();
        assert!(redirects.contains("www.example.com"));
        assert!(headers.contains("X-Frame-Options"));
        assert!(headers.contains("'headers'"));
    }

    // v2 delta: the raw `_headers` text is read from the committed files
    // themselves when the session/body payloads carry no convention text.
    #[test]
    fn committed_convention_files_compile_without_body_payloads() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"<h1>home</h1>"),
                ("_redirects", b"/old /new 301"),
                ("_headers", b"/private\n  X-Frame-Options: DENY"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        let redirects =
            fs::read_to_string(private.join("spaces/s/versions/v/redirects.php")).unwrap();
        let headers = fs::read_to_string(private.join("spaces/s/versions/v/headers.php")).unwrap();
        assert!(redirects.contains("'/new'"));
        assert!(headers.contains("X-Frame-Options"));
    }

    #[test]
    fn raw_basic_auth_compiles_to_unified_access_without_plaintext_output() {
        let (_temp, private, output) = finalize_fixture(
            &[("index.html", b"<h1>home</h1>")],
            json!({"mode":"website"}),
            json!({
                "convention_files":{
                    "headers":"/private\n  Basic-Auth: user:pass\n  X-Frame-Options: DENY"
                },
                "serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}
            }),
        );
        let output = output.expect("raw Basic-Auth finalizes");
        assert_eq!(output.access_rules.len(), 1);
        let hash = output
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
        let serialized = serde_json::to_string(&output).unwrap();
        assert!(!serialized.contains("user:pass"));
        assert!(!serialized.contains("\"password\":\"pass\""));
        let metadata = fs::read_to_string(private.join("spaces/s/versions/v/metadata.json"))
            .expect("private metadata");
        assert!(!metadata.contains("user:pass"));
        assert!(metadata.contains("X-Frame-Options"));
    }

    // The finalize half of the donor's
    // `precompiled_conventions_use_body_buckets_and_drop_stale_session_credentials`;
    // the metadata half lives in `version_files.rs`.
    #[test]
    fn precompiled_conventions_use_body_buckets() {
        let (_temp, private, output) = finalize_fixture(
            &[("index.html", b"home")],
            json!({"mode":"website"}),
            json!({
                "convention_files": {"redirects": "/raw /wrong 301"},
                "convention_files_precompiled": true,
                "serving": {
                    "config": {},
                    "redirects_exact": {
                        "/compiled": [{
                            "action":"redirect",
                            "destination":"/winner",
                            "status":301,
                            "order":0
                        }]
                    },
                    "redirects_pattern": [],
                    "headers_exact": {},
                    "headers_pattern": []
                }
            }),
        );
        output.unwrap();
        let redirects =
            fs::read_to_string(private.join("spaces/s/versions/v/redirects.php")).unwrap();
        assert!(redirects.contains("/winner"));
        assert!(!redirects.contains("/wrong"));
    }

    #[test]
    fn finalized_body_routing_buckets_preserve_v1_rewrite_behavior() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                ("agents-doc/index.html", b"human"),
                ("agents-doc.md", b"agent"),
                ("agent-handoff.html", b"handoff"),
                ("blog/404.html", b"gone"),
                ("_redirects", b"/old /legacy 301"),
            ],
            json!({"mode":"website"}),
            json!({
                "serving": {
                    "config": {},
                    "redirects_exact": {
                        "/found": [{"action":"redirect","destination":"/about.html","status":302,"order":0}],
                        "/agents-doc": [{
                            "action":"rewrite",
                            "destination":"/agents-doc.md",
                            "status":200,
                            "force":true,
                            "conditions":[{"kind":"agent","values":["true"]}],
                            "order":1
                        }]
                    },
                    "redirects_pattern": [
                        {"source":"/app/*","regex":"^/app/(?<splat>.*)$","destination":"/index.html","action":"rewrite","status":200,"force":false,"order":2},
                        {"source":"/agent/*","regex":"^/agent/(?<splat>.*)$","destination":"/agent-handoff.html","action":"rewrite","status":200,"force":true,"order":3},
                        {"source":"/gone/*","regex":"^/gone/(?<splat>.*)$","destination":"/blog/404.html","action":"notFound","status":404,"force":false,"order":4}
                    ],
                    "headers_exact": {},
                    "headers_pattern": []
                }
            }),
        );
        output.unwrap();

        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        let redirects = metadata["redirects"].as_array().unwrap();
        assert_eq!(redirects.len(), 5);
        assert!(redirects.iter().any(|rule| {
            rule["action"] == "redirect"
                && rule["destination"] == "/about.html"
                && rule["status"] == 302
        }));
        assert!(redirects.iter().any(|rule| {
            rule["action"] == "rewrite"
                && rule["destination"] == "/agents-doc.md"
                && rule["conditions"][0]["kind"] == "agent"
        }));
        assert!(redirects.iter().any(|rule| {
            rule["source"] == "/app/*" && rule["action"] == "rewrite" && rule["force"] == false
        }));
        assert!(redirects.iter().any(|rule| {
            rule["source"] == "/agent/*" && rule["action"] == "rewrite" && rule["force"] == true
        }));
        assert!(redirects.iter().any(|rule| {
            rule["source"] == "/gone/*" && rule["action"] == "notFound" && rule["status"] == 404
        }));
        assert!(!redirects
            .iter()
            .any(|rule| rule["destination"] == "/legacy"));
    }

    #[test]
    fn finalize_rejects_invalid_access_and_variant_shapes() {
        for (body, code) in [
            (
                json!({"access_pages":{"other":"x"},"serving":{"config":{}}}),
                "invalid_access_pages",
            ),
            (
                json!({"template_variants":{"production":{}},"serving":{"config":{}}}),
                "invalid_template_variants",
            ),
            (
                json!({"layout_template":false,"serving":{"config":{}}}),
                "invalid_layout_template",
            ),
        ] {
            let (_temp, _private, output) =
                finalize_fixture(&[("index.html", b"home")], json!({"mode":"website"}), body);
            assert!(
                matches!(output, Err(FinalizeError::Invalid { code: actual, .. }) if actual == code)
            );
        }
    }

    // The retained-files half of the donor's
    // `files_mode_gutenberg_is_strict_and_generates_the_document_shell`; the
    // strict-shell and rejection halves are covered by the content pipeline
    // tests.
    #[test]
    fn files_mode_gutenberg_retained_files_reuse_the_previous_version() {
        let source: &[u8] = b"<!-- wp:heading --><h2>Hello</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->";
        let (_temp, private, output) = finalize_fixture(
            &[("page.html", source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"serving":{"config":{"listing":true,"viewer":true},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        let html = fs::read_to_string(private.join("spaces/s/versions/v/files/page.html")).unwrap();
        assert!(html.contains("stattic-block-document"));
        assert!(html.contains("<title>Hello</title>"));
        assert_eq!(
            fs::read(private.join("spaces/s/versions/v/files-original/page.html")).unwrap(),
            source
        );

        let original_hash = sha256(source);
        let original_blob = private.join(format!(
            "spaces/s/blobs/{}/{original_hash}",
            &original_hash[..2]
        ));
        fs::remove_file(&original_blob).unwrap();
        fs::create_dir_all(original_blob.parent().unwrap()).unwrap();
        fs::copy(
            private.join("spaces/s/versions/v/files-original/page.html"),
            &original_blob,
        )
        .unwrap();
        let retained = finalize_site(SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.into(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".into(),
            version_id: "v2".into(),
            upload_id: Some("u2".into()),
            previous_pack: None,
            generated_at: "2026-07-12T00:00:01Z".into(),
            ready_at: 2,
            session: json!({
                "mode":"declared",
                "files":[],
                "reusable_version_id":"v",
                "retained_files":[{"path":"page.html","size":source.len(),"sha256":original_hash,"contentType":"text/html"}],
                "metadata":{"mode":"files","content":{"format":"gutenberg-blocks"}}
            }),
            body: json!({"serving":{"config":{"listing":true,"viewer":true},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        });
        retained.unwrap();
        assert!(private
            .join("spaces/s/versions/v2/files/page.html")
            .is_file());
    }

    // Adapted from the donor's
    // `finalize_compiles_zero_config_routes_bundles_and_static_files` to trunk
    // zero semantics: endpoints/runs arrive as typed input fields and compile
    // through trunk `compile_zero_endpoints` into trunk artifact shapes.
    // Donor-only behaviors (zero/config.json capsule, bundle/static-file
    // materialization, variable-value filtering, `zero_activating` routes) are
    // not asserted.
    #[test]
    fn finalize_compiles_trunk_zero_endpoints_and_control_routes() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = write_upload(&private, &[("index.html", b"home")]);
        let mut input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({"zero": {"runtimeKind": "zero"}, "serving": {"config": {}}}),
        );
        input.zero_endpoints = vec![RuntimeZeroEndpoint {
            method: "GET".into(),
            path: "/api/items/:id".into(),
            source:
                "globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: 'ok' });"
                    .into(),
            endpoint_id: None,
            schema_hash: None,
            capabilities: serde_json::from_value(json!({"db":false,"fetch":false,"auth":false,"env":false,"realtime":false,"logging":false})).unwrap(),
            db: None,
        }];
        input.zero_runs = vec![RuntimeZeroRun {
            run_id: "query_items".into(),
            source:
                "globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: '[]' });"
                    .into(),
            schema_hash: None,
            capabilities: serde_json::from_value(json!({"db":false,"fetch":false,"auth":false,"env":false,"realtime":false,"logging":false})).unwrap(),
            db: None,
        }];
        let output = finalize_site(input).unwrap();
        assert_eq!(output.zero_endpoint_count, 1);
        let version = private.join("spaces/s/versions/v");

        let endpoint_index: Value =
            serde_json::from_slice(&fs::read(version.join("zero/endpoints-index.json")).unwrap())
                .unwrap();
        let artifact_path = endpoint_index
            .pointer("/endpoints/GET ~1api~1items~1:id")
            .and_then(Value::as_str)
            .unwrap();
        let routes: Value =
            serde_json::from_slice(&fs::read(version.join("zero/routes.json")).unwrap()).unwrap();
        let route = routes.pointer("/by_first_segment/api/0").unwrap();
        assert_eq!(route.get("endpoint_id"), Some(&json!("GET /api/items/:id")));
        assert_eq!(route.get("artifact"), Some(&json!(artifact_path)));
        let artifact: Value =
            serde_json::from_slice(&fs::read(version.join(artifact_path)).unwrap()).unwrap();
        let source_path = artifact.get("sourcePath").and_then(Value::as_str).unwrap();
        let bytecode_path = artifact
            .get("bytecodePath")
            .and_then(Value::as_str)
            .unwrap();
        assert!(fs::read(version.join(source_path)).unwrap().len() > 100);
        assert!(!fs::read(version.join(bytecode_path)).unwrap().is_empty());
        assert!(version.join("zero/routes.php").is_file());
        assert!(version.join("zero/runs-index.json").is_file());

        let manifest = fs::read_to_string(version.join("php-manifest.php")).unwrap();
        assert!(manifest.contains("'operation' => 'config'"));
        assert!(manifest.contains("'endpointId' => 'GET /api/items/:id'"));
        assert!(manifest.contains("'action' => 'invoke_zero'"));
        let serving = fs::read_to_string(version.join("serving.php")).unwrap();
        assert!(serving.contains("'zero_routes' => true"));
    }

    #[test]
    fn invalid_zero_source_and_ambiguous_routes_fail_before_publish() {
        let endpoint = |path: &str, source: &str| RuntimeZeroEndpoint {
            method: "GET".into(),
            path: path.into(),
            source: source.into(),
            endpoint_id: None,
            schema_hash: None,
            capabilities: Default::default(),
            db: None,
        };
        for endpoints in [
            vec![endpoint("/api/:id", "export default {")],
            vec![
                endpoint("/api/:id", "export default {};"),
                endpoint("/api/:name", "export default {};"),
            ],
        ] {
            let temp = tempdir().unwrap();
            let private = temp.path().join(".stattic/storage");
            let manifest = write_upload(&private, &[("index.html", b"home")]);
            let mut input = fixture_input(
                &private,
                manifest,
                json!({"mode":"website"}),
                json!({"serving":{"config":{}}}),
            );
            input.zero_endpoints = endpoints;
            let output = finalize_site(input);
            assert!(matches!(output, Err(FinalizeError::Invalid { .. })));
            assert!(!private.join("spaces/s/versions/v").exists());
        }
    }

    #[test]
    fn repeated_finalize_is_rust_owned_validated_and_canonical() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let upload = private.join("runtime/uploads/u/files");
        fs::create_dir_all(&upload).unwrap();
        fs::write(upload.join("index.html"), b"home").unwrap();
        let input = SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.into(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".into(),
            version_id: "v".into(),
            upload_id: Some("u".into()),
            previous_pack: None,
            generated_at: "2026-07-12T00:00:00Z".into(),
            ready_at: 1,
            session: json!({"mode":"open","metadata":{"mode":"website"}}),
            body: json!({"serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        };

        let first = finalize_site(input.clone()).unwrap();
        let mut retry = input.clone();
        retry.generated_at = "2026-07-12T00:05:00Z".into();
        retry.ready_at = 301;
        let repeated = finalize_site(retry).unwrap();
        assert_eq!(repeated.file_count, first.file_count);
        assert_eq!(repeated.diagnostics, first.diagnostics);
        assert_eq!(repeated.manifest, first.manifest);
        assert!(repeated.manifest.as_ref().is_some_and(|manifest| {
            manifest
                .iter()
                .any(|entry| entry.get("path") == Some(&json!("index.html")))
        }));
        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        assert_eq!(metadata.get("readyAt"), Some(&json!(1)));
        assert_eq!(
            metadata
                .get("manifest")
                .and_then(Value::as_array)
                .and_then(|manifest| manifest.first())
                .and_then(|entry| entry.get("path")),
            Some(&json!("index.html"))
        );
        assert_eq!(
            metadata.get("generatedAt"),
            Some(&json!("2026-07-12T00:00:00Z"))
        );

        let mut mismatched = input.clone();
        mismatched.body["serving"]["config"]["listing"] = json!(true);
        assert!(matches!(
            finalize_site(mismatched),
            Err(FinalizeError::Invalid {
                code: "version_existing_mismatch",
                ..
            })
        ));

        let mut zero = input.clone();
        zero.zero_endpoints = vec![RuntimeZeroEndpoint {
            method: "GET".into(),
            path: "/api/status".into(),
            source: "globalThis.__statticZeroResult = '{}';".into(),
            endpoint_id: None,
            schema_hash: None,
            capabilities: Default::default(),
            db: None,
        }];
        assert!(matches!(
            finalize_site(zero),
            Err(FinalizeError::Invalid {
                code: "version_existing_mismatch",
                ..
            })
        ));

        fs::remove_file(private.join("spaces/s/versions/v/serving.php")).unwrap();
        assert!(matches!(
            finalize_site(input),
            Err(FinalizeError::Invalid {
                code: "version_existing_invalid",
                ..
            })
        ));
    }

    #[test]
    fn open_session_returns_the_post_transform_committed_manifest() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let upload = private.join("runtime/uploads/u/files");
        fs::create_dir_all(&upload).unwrap();
        fs::write(upload.join("index.html"), b"<p>{{ value }}</p>").unwrap();
        let committed = b"<p>published</p>";
        let output = finalize_site(SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.into(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".into(),
            version_id: "v".into(),
            upload_id: Some("u".into()),
            previous_pack: None,
            generated_at: "2026-07-12T00:00:00Z".into(),
            ready_at: 1,
            session: json!({"mode":"open","metadata":{"mode":"website"}}),
            body: json!({
                "template_files":{"index.html":"<p>published</p>"},
                "serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}
            }),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        })
        .unwrap();

        let manifest = output.manifest.expect("open manifest");
        assert_eq!(
            manifest,
            vec![json!({
                "path":"index.html",
                "size":committed.len(),
                "sha256":sha256(committed),
                "contentType":"text/html; charset=utf-8"
            })]
        );
        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        assert_eq!(metadata.get("manifest"), Some(&Value::Array(manifest)));
        assert!(metadata.get("openManifest").is_none());
    }

    #[test]
    fn dry_run_finalizes_without_publishing_the_version() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = write_upload(&private, &[("index.html", b"home")]);
        let input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        let output = finalize_site_dry_run(input.clone()).unwrap();
        assert_eq!(output.file_count, 1);
        let versions = private.join("spaces/s/versions");
        assert!(!versions.join("v").exists());
        assert!(!versions.join(".v.rust-finalizing").exists());
        let published = finalize_site(input).unwrap();
        assert_eq!(published.file_count, 1);
        assert!(versions.join("v/files/index.html").is_file());
    }
}
