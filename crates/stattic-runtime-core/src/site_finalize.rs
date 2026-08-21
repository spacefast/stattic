//! Site finalize: commits a publish session's files from the private storage
//! root, runs the content pipeline, compiles conventions, routing and Zero
//! endpoints, and publishes the immutable version directory.

use regex::Regex;
use serde_json::{json, Map, Value};
use stattic_zero_runner::{ZERO_ENDPOINTS_INDEX_FORMAT, ZERO_ENDPOINTS_INDEX_KIND};
use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::Path;
use std::sync::LazyLock;
use std::time::Instant;
use url::Url;

use crate::access::{
    compile_conventions, retain_response_header_operations, rule_is_request_dependent,
    ConventionCompileInput,
};
use crate::artifacts::{
    build_lookup_map, compile_listings, public_files, resolve_serving_config, static_lookup_action,
};
use crate::catalog::{
    build_catalog, catalog_delta, preview_image_path, read_version_catalog, serving_digest,
    write_version_catalog, CatalogDelta, CatalogDigests, CatalogInput, DeltaServing, FileCatalog,
    ImmutablePaths, ObjectIdentity, CATALOG_DELTA_METADATA_KEY, CATALOG_DIGESTS_METADATA_KEY,
};
use crate::config::crons;
use crate::config::diagnostics::DiagnosticSeverity;
use crate::config::jsonc::parse as parse_jsonc;
use crate::content::{materialize_html_pipeline, IMPLICIT_FAVICON_PATH, PIPELINE_SOURCE_MAX_BYTES};
use crate::csp::PlatformCspSources;
use crate::finalize::{
    artifact_metadata, create_dir_all, file_meta, invalid, invalid_error, invalid_with_details,
    mime_for_path, read_bounded, remove_any, sha256, validate_id, validate_relative_path,
    write_bytes, write_generated, write_json, write_php, AdoptablePath, FileMeta, FinalizeError,
    Result,
};
use crate::hash::stable_json_sha256;
use crate::model::{
    FinalizeTelemetry, RuntimeDiagnostic, RuntimeDiagnosticSeverity, SiteFinalizeInput,
    SiteFinalizeOutput, SITE_FINALIZE_OUTPUT_FORMAT,
};
use crate::policy::{validate_finalize_policy, FinalizePolicyContext};
use crate::prepare::{
    analyze, prepare, AnalyzeInput, AnalyzeInputFields, Channel,
    ConventionFiles as PrepareConventionFiles, PrepareInput, RoutingConfig as PrepareRoutingConfig,
    VariableScope, ANALYZE_INPUT_FORMAT, PREPARE_INPUT_FORMAT,
};
use crate::protocol::{
    ARTIFACT_SCHEMA_VERSION, CONFIG_ACCEPTED_FILES, CONFIG_FILE_MAX_BYTES, CRONS_ARTIFACT_PATH,
    TEMPLATE_MAX_BYTES, TEMPLATE_VARIANT_FILE_LIMIT, TEMPLATE_VARIANT_ROUTE_LIMIT,
    TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS, THEME_STYLESHEET_PATH, VERSION_ROOT_POINTER_FILE,
};
use crate::responses::{
    compile_response_table, publish_response_tables, ResponseCompileInput, DENY_ALL_ROBOTS,
};
use crate::storage::{
    apply_templates, blob_path, blob_root, commit_session_files, install_blob_from, put_blob,
};
use crate::version_files::{
    apply_access_pages, apply_page_artifacts, convention_files, resolved_viewer,
    validate_artifacts, validate_embedded_page_inputs, PublishedArtifacts,
};
use crate::zero::{
    compile_zero_endpoints, zero_endpoint_artifact_path, zero_pack_sha256, zero_run_artifact_path,
    CompiledZeroEndpoints,
};

const ZERO_ROUTES_FORMAT: &str = "stattic.zero.routes.v1";
const ZERO_RUNS_INDEX_FORMAT: &str = "stattic.zero.runs-index.v1";

fn readiness_rules_for_path<'a>(
    request_path: &str,
    redirects_exact: &'a Map<String, Value>,
    redirects_pattern: &'a [Value],
) -> Result<Vec<&'a Value>> {
    let exact_path = if request_path == "/" {
        request_path
    } else {
        request_path.trim_end_matches('/')
    };
    let mut rules = redirects_exact
        .get(exact_path)
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .collect::<Vec<_>>();

    for rule in redirects_pattern {
        let Some(regex) = rule.get("regex").and_then(Value::as_str) else {
            continue;
        };
        if regex.is_empty() {
            continue;
        }
        let pattern = match Regex::new(regex) {
            Ok(pattern) => pattern,
            Err(_) => {
                return invalid(
                    "runtime_readiness_redirect_pattern_invalid",
                    "A finalized redirect pattern is incompatible with runtime readiness projection.",
                );
            }
        };
        if pattern.is_match(request_path) {
            rules.push(rule);
        }
    }

    rules.sort_by_key(|rule| {
        rule.get("order")
            .and_then(Value::as_i64)
            .unwrap_or(i64::MAX)
    });
    Ok(rules)
}

fn readiness_rule_destination(rule: &Value, request_path: &str) -> Option<String> {
    let mut destination = rule.get("destination")?.as_str()?.to_string();
    let Some(regex) = rule.get("regex").and_then(Value::as_str) else {
        return Some(destination);
    };
    let pattern = Regex::new(regex).ok()?;
    let captures = pattern.captures(request_path)?;
    for name in pattern.capture_names().flatten() {
        if let Some(value) = captures.name(name) {
            destination = destination.replace(&format!(":{name}"), value.as_str());
        }
    }
    Some(destination)
}

/// The base every readiness request path is encoded against. Parsed once: a
/// version with percent-encodable filenames would otherwise parse it per file
/// per lookup entry.
static READINESS_BASE: LazyLock<Url> =
    LazyLock::new(|| Url::parse("https://readiness.invalid/").expect("static readiness URL"));

/// A committed path as a request path, percent-encoded the way a visitor's URL
/// arrives.
fn readiness_request_path(path: &str) -> String {
    let mut encoded = READINESS_BASE.clone();
    encoded.set_path(&format!("/{path}"));
    encoded.path().to_string()
}

/// The version's static lookup map, indexed by the encoded request path each
/// entry answers at.
struct ReadinessLookup<'a> {
    raw: &'a Map<String, Value>,
    encoded: BTreeMap<String, &'a Value>,
}

impl<'a> ReadinessLookup<'a> {
    fn new(raw: &'a Map<String, Value>) -> Self {
        // Only paths whose encoding differs earn an index entry: `action` tries
        // the raw map first, so an entry that encodes to itself is never read.
        let encoded = raw
            .iter()
            .filter_map(|(path, action)| {
                let encoded_path = readiness_request_path(path);
                (encoded_path.trim_start_matches('/') != path).then_some((encoded_path, action))
            })
            .collect();
        Self { raw, encoded }
    }

    fn action(&self, request_path: &str) -> Option<&'a Value> {
        self.raw
            .get(request_path.trim_start_matches('/'))
            .or_else(|| self.encoded.get(request_path).copied())
    }

    fn has_stable_head(&self, request_path: &str) -> bool {
        self.action(request_path).is_some_and(|action| {
            matches!(
                action.get("action").and_then(Value::as_str),
                Some("file" | "redirect" | "not_found")
            ) && action
                .get("methods")
                .and_then(Value::as_array)
                .is_some_and(|methods| methods.iter().any(|method| method.as_str() == Some("HEAD")))
        })
    }
}

fn zero_route_can_own_root(compiled_zero_routes: &[Value]) -> bool {
    compiled_zero_routes
        .iter()
        .any(|route| route.get("pattern").and_then(Value::as_str) == Some("/:splat"))
}

fn readiness_clean_urls_enabled(serving_config: &Map<String, Value>, fallback: &Value) -> bool {
    serving_config
        .get("clean_urls")
        .and_then(Value::as_bool)
        .unwrap_or_else(|| fallback.get("status").and_then(Value::as_u64) != Some(200))
}

fn readiness_rewrite_target_path(destination: &str, request_path: &str) -> String {
    let parsed = if destination.starts_with('/') {
        Url::parse("https://readiness.invalid/").and_then(|base| base.join(destination))
    } else {
        Url::parse(destination)
    };
    parsed
        .ok()
        .map(|target| target.path().to_string())
        .filter(|path| path.starts_with('/') && !path.is_empty())
        .unwrap_or_else(|| request_path.to_string())
}

fn readiness_lookup_has_known_asset_extension(path: &str) -> bool {
    let Some(extension) = Path::new(path).extension().and_then(|value| value.to_str()) else {
        return false;
    };
    matches!(
        extension.to_ascii_lowercase().as_str(),
        "avif"
            | "bmp"
            | "br"
            | "css"
            | "eot"
            | "gif"
            | "gz"
            | "ico"
            | "jpeg"
            | "jpg"
            | "js"
            | "json"
            | "map"
            | "mjs"
            | "mp3"
            | "mp4"
            | "ogg"
            | "otf"
            | "png"
            | "svg"
            | "ttf"
            | "wasm"
            | "webm"
            | "webmanifest"
            | "webp"
            | "woff"
            | "woff2"
            | "xml"
            | "pdf"
            | "csv"
            | "rtf"
            | "txt"
            | "doc"
            | "docx"
            | "xls"
            | "xlsx"
            | "ppt"
            | "pptx"
            | "odt"
            | "ods"
            | "odp"
            | "epub"
            | "zip"
            | "tar"
            | "tgz"
            | "rar"
            | "7z"
            | "bz2"
            | "xz"
            | "zst"
            | "wav"
            | "flac"
            | "aac"
            | "m4a"
            | "m4v"
            | "mov"
            | "avi"
            | "mkv"
            | "weba"
            | "oga"
            | "ogv"
            | "opus"
            | "wmv"
            | "flv"
            | "mpg"
            | "mpeg"
            | "m3u8"
            | "tif"
            | "tiff"
            | "heic"
            | "heif"
            | "jxl"
            | "yaml"
            | "yml"
            | "toml"
            | "sql"
            | "ndjson"
            | "jsonl"
            | "geojson"
            | "ics"
            | "vcf"
            | "exe"
            | "dmg"
            | "pkg"
            | "deb"
            | "rpm"
            | "apk"
            | "msi"
            | "iso"
            | "bin"
            | "appimage"
    )
}

fn readiness_rewrite_status(
    destination: &str,
    request_path: &str,
    lookup: &ReadinessLookup<'_>,
    serving_config: &Map<String, Value>,
    fallback: &Value,
) -> Result<u64> {
    let target_path = readiness_rewrite_target_path(destination, request_path);
    if let Some(action) = lookup.action(&target_path) {
        return match action.get("action").and_then(Value::as_str) {
            Some("not_found") => Ok(404),
            Some("redirect") => Ok(action.get("status").and_then(Value::as_u64).unwrap_or(302)),
            Some("file") => Ok(action.get("status").and_then(Value::as_u64).unwrap_or(200)),
            _ => invalid(
                "runtime_readiness_rewrite_target_unsupported",
                format!(
                    "The rewrite target {target_path} does not have a stable static readiness status."
                ),
            ),
        };
    }
    let lookup_path = target_path.trim_start_matches('/');
    if !lookup_path.is_empty()
        && Path::new(lookup_path).extension().is_none()
        && readiness_clean_urls_enabled(serving_config, fallback)
    {
        let clean_path = format!("{lookup_path}.html");
        if lookup
            .action(&format!("/{clean_path}"))
            .is_some_and(|action| {
                action.get("action").and_then(Value::as_str) == Some("file")
                    && action.get("path").and_then(Value::as_str) == Some(clean_path.as_str())
            })
        {
            return Ok(200);
        }
    }
    let fallback_status = fallback
        .get("status")
        .and_then(Value::as_u64)
        .unwrap_or(404);
    if fallback_status == 200 && readiness_lookup_has_known_asset_extension(&target_path) {
        return Ok(404);
    }
    Ok(fallback_status)
}

/// Every status the readiness probe may legitimately observe at `request_path`.
///
/// `public` carries the serving context when a public object owns the target.
/// `None` means the version publishes no public object, so the root IS the
/// target: an internal rewrite cannot resolve bytes there, and the terminal
/// answer is 404 rather than 200.
fn readiness_statuses(
    request_path: &str,
    redirects_exact: &Map<String, Value>,
    redirects_pattern: &[Value],
    lookup: &ReadinessLookup<'_>,
    public: Option<(&Map<String, Value>, &Value)>,
) -> Result<Vec<u64>> {
    // Access policy is mutable route state, not a finalized-version property, so
    // the immutable host can start silent visitor-session acquisition, challenge
    // or deny at any later activation whatever the finalized routing result is.
    const MUTABLE_ACCESS: [u64; 3] = [302, 401, 403];
    let mut statuses = BTreeSet::new();
    let rules = readiness_rules_for_path(request_path, redirects_exact, redirects_pattern)?;
    if let Some(action) = lookup
        .action(request_path)
        .filter(|action| action.get("action").and_then(Value::as_str) == Some("redirect"))
    {
        statuses.insert(action.get("status").and_then(Value::as_u64).unwrap_or(302));
        statuses.extend(MUTABLE_ACCESS);
        return Ok(statuses.into_iter().collect());
    }
    let mut terminal_reachable = true;
    for rule in rules {
        if !terminal_reachable {
            break;
        }
        let Some(destination) = readiness_rule_destination(rule, request_path) else {
            continue;
        };
        if destination.is_empty() {
            continue;
        }
        let request_dependent = rule_is_request_dependent(rule);
        let action = rule
            .get("action")
            .and_then(Value::as_str)
            .unwrap_or("redirect");
        match action {
            "redirect" => {
                let status = rule.get("status").and_then(Value::as_u64).unwrap_or(302);
                if matches!(status, 301 | 302 | 303 | 307 | 308) {
                    statuses.insert(status);
                }
            }
            "rewrite" | "notFound" => match public {
                None => {
                    statuses.insert(404);
                }
                Some((serving_config, fallback)) => {
                    if !rule.get("force").and_then(Value::as_bool).unwrap_or(false) {
                        continue;
                    }
                    statuses.insert(if action == "notFound" {
                        404
                    } else {
                        readiness_rewrite_status(
                            &destination,
                            request_path,
                            lookup,
                            serving_config,
                            fallback,
                        )?
                    });
                }
            },
            "proxy" => {
                return if public.is_some() {
                    invalid(
                        "runtime_readiness_proxy_target_unsupported",
                        "A proxy route cannot own a public readiness target.",
                    )
                } else {
                    invalid(
                        "runtime_readiness_proxy_root_unsupported",
                        "A proxy route cannot own the readiness root when the version has no public object.",
                    )
                };
            }
            _ => continue,
        }
        if !request_dependent {
            terminal_reachable = false;
        }
    }
    if terminal_reachable || statuses.is_empty() {
        statuses.insert(if public.is_some() { 200 } else { 404 });
    }
    statuses.extend(MUTABLE_ACCESS);
    Ok(statuses.into_iter().collect())
}

struct RuntimeReadinessRouting<'a> {
    redirects_exact: &'a Map<String, Value>,
    redirects_pattern: &'a [Value],
    lookup: &'a Map<String, Value>,
    serving_config: &'a Map<String, Value>,
    fallback: &'a Value,
    compiled_zero_routes: &'a [Value],
}

fn runtime_readiness_target(
    files: &BTreeMap<String, FileMeta>,
    public_files: &[String],
    routing: RuntimeReadinessRouting<'_>,
) -> Result<Value> {
    let RuntimeReadinessRouting {
        redirects_exact,
        redirects_pattern,
        lookup,
        serving_config,
        fallback,
        compiled_zero_routes,
    } = routing;
    let lookup = &ReadinessLookup::new(lookup);
    if public_files.is_empty() {
        if lookup.action("/").is_none() && zero_route_can_own_root(compiled_zero_routes) {
            return invalid(
                "runtime_readiness_public_target_unavailable",
                "A dynamic runtime route owns the readiness root and no stable public object exists.",
            );
        }
        return Ok(json!({
            "path":"/",
            "expected_statuses":readiness_statuses("/", redirects_exact, redirects_pattern, lookup, None)?
        }));
    }
    let selected = public_files
        .iter()
        .filter_map(|path| {
            // wp.cloud owns this route ahead of the tenant runtime. Its
            // response therefore cannot carry Spacefast version provenance
            // and cannot prove that this finalized version is serving.
            if path == "robots.txt" {
                return None;
            }
            let file = files.get(path)?;
            let request_path = readiness_request_path(path);
            lookup
                .has_stable_head(&request_path)
                .then_some((path, file.size, request_path))
        })
        .min_by(|(left_path, left_size, _), (right_path, right_size, _)| {
            left_size
                .cmp(right_size)
                .then_with(|| left_path.cmp(right_path))
        });
    let Some((_path, _, request_path)) = selected else {
        return invalid(
            "runtime_readiness_public_target_unavailable",
            "Every public object is shadowed by a dynamic runtime action for HEAD readiness.",
        );
    };
    Ok(json!({
        "path":request_path,
        "expected_statuses":readiness_statuses(
            &request_path,
            redirects_exact,
            redirects_pattern,
            lookup,
            Some((serving_config, fallback)),
        )?
    }))
}

/// Commits, transforms, compiles and validates a publish session into the
/// staging directory, then renames it into place as the immutable version.
/// `dry_run` runs the complete pipeline and discards the stage instead.
pub fn finalize_site(input: SiteFinalizeInput, dry_run: bool) -> Result<SiteFinalizeOutput> {
    let started = Instant::now();
    let input_hash = finalize_input_hash(&input);
    let version_root_input = Path::new(&input.version_root);
    let private_root =
        fs::canonicalize(version_root_input).map_err(|source| FinalizeError::Io {
            path: version_root_input.into(),
            source,
        })?;
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
        return existing_finalize_output(&input, &version_root, &input_hash);
    }
    validate_embedded_page_inputs(&input.body)?;
    let stage_root = version_parent.join(format!(".{}.rust-finalizing", input.version_id));
    remove_any(&stage_root)?;
    create_dir_all(&stage_root.join("files"))?;

    let mut files = BTreeMap::<String, FileMeta>::new();
    let finalize_result =
        run_finalize_pipeline(&input, &input_hash, &private_root, &stage_root, &mut files);

    let result = match finalize_result {
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

    let mut telemetry = result.telemetry;
    telemetry.total_ms = elapsed_ms(started);
    Ok(SiteFinalizeOutput {
        format: SITE_FINALIZE_OUTPUT_FORMAT.to_string(),
        space_id: input.space_id,
        version_id: input.version_id,
        file_count: files.len(),
        zero_endpoint_count: result.zero_endpoint_count,
        diagnostics: result.diagnostics,
        catalog_digests: result.catalog_digests,
        delta: result.delta,
        telemetry: Some(telemetry),
    })
}

/// What one finalize produced, beyond the immutable version it published.
struct FinalizePipelineResult {
    diagnostics: Vec<RuntimeDiagnostic>,
    zero_endpoint_count: usize,
    /// Absent only on a replay of a version finalized before the catalog
    /// existed.
    catalog_digests: Option<CatalogDigests>,
    delta: Option<CatalogDelta>,
    /// Everything but `total_ms`, which only the caller can close out.
    telemetry: FinalizeTelemetry,
}

fn elapsed_ms(started: Instant) -> u64 {
    u64::try_from(started.elapsed().as_millis()).unwrap_or(u64::MAX)
}

/// Runs `work`, charging its wall time to `slot`. Stages that run in more than
/// one place accumulate.
fn timed<T>(slot: &mut u64, work: impl FnOnce() -> T) -> T {
    let started = Instant::now();
    let value = work();
    *slot = slot.saturating_add(elapsed_ms(started));
    value
}

fn run_finalize_pipeline(
    input: &SiteFinalizeInput,
    input_hash: &str,
    private_root: &Path,
    stage_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<FinalizePipelineResult> {
    let mut telemetry = FinalizeTelemetry::default();
    timed(&mut telemetry.staging_ms, || {
        commit_session_files(
            &input.space_id,
            &input.session,
            private_root,
            stage_root,
            files,
        )
    })?;
    telemetry.staged_files = files.len();
    let mut diagnostics = Vec::new();
    let substitution = timed(&mut telemetry.template_substitution_ms, || {
        let substitution = resolve_template_substitution(input, stage_root, &mut diagnostics)?;
        apply_templates(&substitution.template_files, stage_root, files)?;
        Ok::<_, FinalizeError>(substitution)
    })?;
    write_crons_artifact(stage_root, files, substitution.routing_config.as_ref())?;

    let serving = input
        .body
        .get("serving")
        .and_then(Value::as_object)
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
    // Everything the pipeline produces lands in the CAS: the tables address
    // blobs, and a blob is the only place a byte lives.
    let blobs = blob_root(private_root, &input.space_id);
    create_dir_all(&blobs)?;
    // The previous version's catalog, read ONCE: it gates per-file adoption
    // here and feeds the publish delta at the end of the pipeline.
    let previous_catalog = previous_version_catalog(input, private_root)?;
    let context_digest = pipeline_context_digest(&config, &viewer, &metadata, files);
    let adoptable = adoptable_paths(previous_catalog.as_ref(), &context_digest, files, &blobs);
    let pipeline = timed(&mut telemetry.html_pipeline_ms, || {
        materialize_html_pipeline(
            &stage_root.join("files"),
            files,
            &serving,
            &metadata,
            &viewer,
            &adoptable,
            &mut diagnostics,
        )
    })?;
    telemetry.generated_files = pipeline.generated.len();
    telemetry.decorated_files = pipeline.decorated;
    telemetry.skipped_files = pipeline.adopted.len();
    let private = pipeline.private;

    apply_access_pages(&input.body, stage_root)?;
    apply_page_artifacts(&input.body, stage_root)?;
    // The version's Functions signal comes from the same body key the worker's
    // dispatch configuration rides in: the control plane sends `functions` only
    // for a version whose compiled metadata carries a worker.
    let has_worker = input.body.get("functions").is_some_and(Value::is_object);
    let serving_config = resolve_serving_config(&config, files, &private, &metadata, has_worker)?;
    // The ONE visibility decision, recorded by the catalog and by nothing else.
    let public_files = public_files(files, &private);
    let public_set: BTreeSet<String> = public_files.iter().cloned().collect();
    // The convention text this version serves: the substituted `_redirects` /
    // `_headers` the finalizer produced from the staged files, plus any
    // committed `.stattic/routes.json`.
    let mut convention_map = timed(&mut telemetry.conventions_ms, || {
        convention_files(stage_root)
    })?;
    for (key, value) in [
        (
            "redirects",
            substitution.convention_files.redirects.as_ref(),
        ),
        ("headers", substitution.convention_files.headers.as_ref()),
    ] {
        if let Some(value) = value {
            convention_map.insert(key.into(), Value::String(value.clone()));
        }
    }
    let convention_files = Value::Object(convention_map);
    let conventions_started = Instant::now();
    let compiled_conventions = compile_conventions(
        &convention_files,
        substitution
            .routing_config
            .as_ref()
            .map(|(_, source)| source.clone()),
        substitution
            .routing_config
            .as_ref()
            .map(|(path, _)| path.clone()),
        &ConventionCompileInput {
            assigned_hostnames: input
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
                .unwrap_or_default(),
            platform_csp_sources: platform_csp_sources(&serving)?,
        },
        &mut diagnostics,
    )?;
    let metadata_convention_files = compiled_conventions
        .metadata_convention_files
        .clone()
        .unwrap_or_else(|| convention_files.clone());
    let routing_summary = compiled_conventions.routing;
    let redirects_exact = compiled_conventions.redirects_exact.unwrap_or_default();
    let redirects_pattern = compiled_conventions.redirects_pattern.unwrap_or_default();
    let mut headers_exact = compiled_conventions.headers_exact.unwrap_or_default();
    let mut headers_pattern = compiled_conventions.headers_pattern.unwrap_or_default();
    retain_response_header_operations(&mut headers_exact, &mut headers_pattern);
    telemetry.conventions_ms = telemetry
        .conventions_ms
        .saturating_add(elapsed_ms(conventions_started));
    let artifact_meta = artifact_metadata(&input.generated_at);
    let zero_started = Instant::now();
    let compiled_zero = compile_trunk_zero(input, &artifact_meta, &mut diagnostics)?;
    telemetry.zero_compile_ms = elapsed_ms(zero_started);
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
    // The exact-path projection is not an artifact: it exists only to pick the
    // readiness target the control plane probes after activation.
    let mut lookup = build_lookup_map(
        files.keys(),
        &redirects_exact,
        &redirects_pattern,
        &private,
        serving_config
            .get("index")
            .unwrap_or(&Value::String("index.html".into())),
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
    let fallback = build_fallback(&serving_config, files, &private);
    let readiness_target = runtime_readiness_target(
        files,
        &public_files,
        RuntimeReadinessRouting {
            redirects_exact: &redirects_exact,
            redirects_pattern: &redirects_pattern,
            lookup: &lookup,
            serving_config: &serving_config,
            fallback: &fallback,
            compiled_zero_routes: &compiled_zero_routes,
        },
    )?;
    let zero_endpoint_count = compiled_zero.endpoint_artifacts.len();
    let zero_run_count = compiled_zero.run_artifacts.len();

    let files_root = stage_root.join("files");
    let (originals, variant_files) = timed(&mut telemetry.blob_install_ms, || {
        for (path, meta) in files.iter() {
            // An adopted path's staged file still holds the SOURCE bytes while
            // its meta names the previously served object — installing it here
            // would file source bytes under the served hash. Both of its blobs
            // were proven present when the path became adoptable.
            if pipeline.adopted.contains(path) {
                continue;
            }
            install_blob_from(&blobs, &files_root.join(path), &meta.sha256)?;
        }
        let mut originals = original_objects(stage_root, &blobs, files)?;
        if let Some(previous) = previous_catalog.as_ref() {
            for path in &pipeline.adopted {
                // The decoration pass never wrote an original for a path it
                // adopted; the previous catalog's source identity carries over
                // so `source` survives the skip exactly as `served` does.
                let Some(entry) = previous.paths.get(path) else {
                    continue;
                };
                let served_sha = entry.served.as_ref().map(|served| &served.sha256);
                if served_sha.is_some_and(|sha| *sha != entry.source.sha256) {
                    originals.insert(path.clone(), entry.source.clone());
                }
            }
        }
        let variant_files =
            compile_template_variant_files(&substitution.template_variants, files, &blobs)?;
        Ok::<_, FinalizeError>((originals, variant_files))
    })?;

    let listings = timed(&mut telemetry.listings_ms, || {
        compile_listings(
            &blobs,
            &stage_root.join("pages"),
            files,
            &serving_config,
            &metadata,
            &viewer,
            &serving,
            &private,
        )
    })?;

    // Crawl control only. What keeps a preview host out of an index is the
    // `X-Robots-Tag` compiled into every HTML entry.
    let robots = put_blob(&blobs, DENY_ALL_ROBOTS.as_bytes())?;

    let zero_actions = zero_response_actions(&input.body, &compiled_zero_routes);
    // Base map and every channel variant compile through the SAME inputs; only
    // the file map differs, so a routing decision cannot drift between them.
    let noindex_host = noindex_host(&serving)?;
    let compile_for = |files: &BTreeMap<String, FileMeta>| {
        compile_response_table(&ResponseCompileInput {
            files,
            private: &private,
            serving_config: &serving_config,
            redirects_exact: &redirects_exact,
            redirects_pattern: &redirects_pattern,
            headers_exact: &headers_exact,
            headers_pattern: &headers_pattern,
            listings: &listings,
            zero_actions: &zero_actions,
            robots_blob: Some((robots.clone(), DENY_ALL_ROBOTS.len() as u64)),
            noindex_host,
        })
    };
    let published = timed(&mut telemetry.response_tables_ms, || {
        let table = compile_for(files);
        let route_tables: BTreeMap<_, _> = variant_files
            .iter()
            .map(|(route_name, variant_files)| (route_name.clone(), compile_for(variant_files)))
            .collect();
        publish_response_tables(stage_root, &table, &route_tables, env!("CARGO_PKG_VERSION"))
    })?;

    let diagnostics = runtime_diagnostics(diagnostics);
    let redirect_rules = flatten_rules(&redirects_exact, &redirects_pattern);
    let header_rules = flatten_rules(&headers_exact, &headers_pattern);

    // The canonical catalog, and the ONLY record of what this version holds:
    // it replaced the `files`, `manifest`, `publicFiles`, `originalShas` and
    // `templateVariants` projections outright. It rides inside `metadata.json`,
    // the one document the blob collector sweeps, so every sha it names — source,
    // served, and per-channel variant — keeps its blob alive.
    let catalog_started = Instant::now();
    let catalog = build_catalog(&CatalogInput {
        space_id: &input.space_id,
        version_id: &input.version_id,
        files,
        originals: &originals,
        public: &public_set,
        variants: &variant_files,
        serving_digest: catalog_serving_digest(
            &redirect_rules,
            &header_rules,
            &serving_config,
            &serving,
        ),
        template_paths: substitution.substituted_paths.clone(),
        generated_at: &input.generated_at,
        pipeline_context_digest: Some(context_digest),
    });
    let delta = finalize_catalog_delta(
        previous_catalog.as_ref(),
        &catalog,
        &serving_config,
        &headers_exact,
        &headers_pattern,
    );
    let catalog_digests = catalog.digests();
    telemetry.catalog_delta_ms = elapsed_ms(catalog_started);
    let preview_image = preview_image_path(&catalog);

    let debug_json = json!({
        "format": SITE_FINALIZE_OUTPUT_FORMAT,
        "spaceId": input.space_id,
        "versionId": input.version_id,
        "zeroEndpointCount": zero_endpoint_count,
        "zeroRunCount": zero_run_count,
        "diagnostics": diagnostics,
    });
    let artifacts_started = Instant::now();
    write_json(
        &stage_root.join("metadata.json"),
        &json!({
            "schema": ARTIFACT_SCHEMA_VERSION,
            "versionId": input.version_id,
            "spaceId": input.space_id,
            "finalizeInputSha256": input_hash,
            "hostingKind": "static",
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
            "zeroPackSha256": zero_pack_sha256(&compiled_zero),
            "debugJsonSha256": stable_json_sha256(&debug_json),
            "redirects": redirect_rules,
            "headers": header_rules,
            // The catalog itself is its own published artifact; what belongs in
            // the finalize record is what this publish CHANGED, so a replay
            // answers with the delta it answered the first time instead of
            // recomputing one against a live version that has since moved.
            CATALOG_DELTA_METADATA_KEY: delta,
            // The runtime-owned half of the control plane's version row, stored
            // rather than only returned: a replayed finalize (and the PHP
            // idempotent answer, which never re-runs the finalizer) has to give
            // back exactly what the first call did.
            CATALOG_DIGESTS_METADATA_KEY: catalog_digests,
            "variableDigests": substitution.variable_digests,
            "systemVariableDependencies": substitution.system_variable_dependencies,
            "routing": routing_summary,
            // The served public image a space card shows for this version.
            // Chosen once, here, from the catalog — so a replay answers with
            // the same path the first finalize picked.
            "previewImagePath": preview_image,
            "readinessTarget": readiness_target,
            "root": published.root_file,
            "tables": published.tables,
            // Pages link the theme stylesheet at a stable URL and are never
            // rewritten, so a theme change recompiles this one blob and
            // repoints the overlay at it.
            "themeStylesheetSha": files
                .get(THEME_STYLESHEET_PATH)
                .map(|meta| Value::String(meta.sha256.clone()))
                .unwrap_or(Value::Null),
            "diagnostics": diagnostics,
            "generatedAt": input.generated_at,
        }),
    )?;
    write_version_catalog(stage_root, &catalog)?;
    write_json(&stage_root.join("debug.json"), &debug_json)?;
    write_zero_artifacts(stage_root, &compiled_zero)?;
    validate_zero_artifacts(stage_root, zero_endpoint_count, zero_run_count)?;
    validate_artifacts(
        stage_root,
        &PublishedArtifacts {
            root_file: &published.root_file,
            tables: &published.tables,
        },
    )?;
    // The staging tree was a workspace, not an output: its bytes are all in the
    // CAS, and leaving it would be a second copy of every published file.
    for workspace in ["files", "files-original", "files-variants"] {
        remove_any(&stage_root.join(workspace))?;
    }
    telemetry.artifacts_write_ms = elapsed_ms(artifacts_started);
    Ok(FinalizePipelineResult {
        diagnostics,
        zero_endpoint_count,
        catalog_digests: Some(catalog_digests),
        delta,
        telemetry,
    })
}

/// Folds everything outside the file set that decides what a URL answers.
/// `serving.state_digest` is the control plane's own slice — plan entitlements
/// and access-policy state the runtime does not own and must not guess at.
fn catalog_serving_digest(
    redirect_rules: &Value,
    header_rules: &Value,
    serving_config: &Map<String, Value>,
    serving: &Map<String, Value>,
) -> String {
    serving_digest(
        redirect_rules,
        header_rules,
        &Value::Object(serving_config.clone()),
        serving.get("state_digest"),
    )
}

/// Reads the catalog of the version this finalize supersedes, once for the
/// whole pipeline: it gates per-file adoption up front and feeds the publish
/// delta at the end.
///
/// A version that predates the catalog cannot be diffed OR adopted from. That
/// is a missing migration, and the caller answers it by purging host-wide —
/// never by reconstructing the previous file set from somewhere else.
fn previous_version_catalog(
    input: &SiteFinalizeInput,
    private_root: &Path,
) -> Result<Option<FileCatalog>> {
    let Some(previous_id) = input
        .body
        .get("previous_version_id")
        .and_then(Value::as_str)
        .filter(|id| !id.is_empty())
    else {
        return Ok(None);
    };
    validate_id(previous_id, "previous_version_id")?;
    let previous_root = private_root
        .join("spaces")
        .join(&input.space_id)
        .join("versions")
        .join(previous_id);
    read_version_catalog(&previous_root)
}

/// Digest over every decoration input BESIDES the file bytes themselves: the
/// serving config (meta defaults and injected snippets ride in it), the
/// resolved viewer, the site title, the engine version, and the identity of
/// every staged file decoration reads through a name rather than through the
/// page being decorated — the implicit favicon, the theme pair, and the
/// configured image/favicon asset references whose cache-busting query embeds
/// the target's sha. Two finalizes that agree here and on a file's staged
/// bytes produce byte-identical decorated output for that file; rendered
/// pages are excluded from adoption separately because their frontmatter
/// feeds decoration without necessarily reaching the rendered bytes.
fn pipeline_context_digest(
    config: &Map<String, Value>,
    viewer: &Map<String, Value>,
    metadata: &Map<String, Value>,
    files: &BTreeMap<String, FileMeta>,
) -> String {
    let staged_sha = |path: &str| files.get(path).map(|meta| meta.sha256.clone());
    let meta = config.get("meta").and_then(Value::as_object);
    let asset_ref = |reference: Option<&Value>| -> Value {
        let Some(reference) = reference.and_then(Value::as_str) else {
            return Value::Null;
        };
        json!([reference, reference.strip_prefix('/').and_then(&staged_sha),])
    };
    stable_json_sha256(&json!({
        "engine": env!("CARGO_PKG_VERSION"),
        "config": Value::Object(config.clone()),
        "viewer": Value::Object(viewer.clone()),
        "siteTitle": metadata.get("title").cloned().unwrap_or(Value::Null),
        "faviconIco": files.contains_key(IMPLICIT_FAVICON_PATH),
        "themeJson": staged_sha("theme.json"),
        "themeStylesheet": staged_sha(THEME_STYLESHEET_PATH),
        "metaImage": asset_ref(meta.and_then(|meta| meta.get("image"))),
        "metaFavicon": asset_ref(meta.and_then(|meta| meta.get("favicon"))),
        "ogImage": asset_ref(viewer.get("og_image_path")),
    }))
}

/// The decoration targets the previous version can answer for: same staged
/// source bytes, a served identity on record, and both blobs still present in
/// the space CAS. Empty unless the previous catalog carries a context digest
/// that matches this run's — one missed decoration input here is a stale page
/// in production, so the digest is the whole correctness argument and absence
/// fails closed.
fn adoptable_paths(
    previous: Option<&FileCatalog>,
    context_digest: &str,
    files: &BTreeMap<String, FileMeta>,
    blobs: &Path,
) -> BTreeMap<String, AdoptablePath> {
    let Some(previous) = previous else {
        return BTreeMap::new();
    };
    if previous.pipeline_context_digest.as_deref() != Some(context_digest) {
        return BTreeMap::new();
    }
    let mut adoptable = BTreeMap::new();
    for (path, meta) in files {
        let lower = path.to_ascii_lowercase();
        if !(lower.ends_with(".html") || lower.ends_with(".htm")) {
            continue;
        }
        let Some(entry) = previous.paths.get(path) else {
            continue;
        };
        let Some(served) = entry.served.as_ref() else {
            continue;
        };
        if entry.source.sha256 != meta.sha256 {
            continue;
        }
        if !blob_path(blobs, &served.sha256).is_file() {
            continue;
        }
        if entry.source.sha256 != served.sha256 && !blob_path(blobs, &entry.source.sha256).is_file()
        {
            continue;
        }
        adoptable.insert(
            path.clone(),
            AdoptablePath {
                source_sha256: entry.source.sha256.clone(),
                served_sha256: served.sha256.clone(),
                served_size: served.size,
                served_content_type: served.content_type.clone(),
            },
        );
    }
    adoptable
}

/// Diffs this version against the version it supersedes. Both catalogs are
/// local to this site, which is the whole point: the control plane replaying
/// stored manifests to work out what changed was the split brain.
fn finalize_catalog_delta(
    previous: Option<&FileCatalog>,
    next: &FileCatalog,
    serving_config: &Map<String, Value>,
    headers_exact: &Map<String, Value>,
    headers_pattern: &[Value],
) -> Option<CatalogDelta> {
    let previous = previous?;
    let mut delta = catalog_delta(
        previous,
        next,
        &DeltaServing::from_serving_config(serving_config),
        &ImmutablePaths::from_header_rules(headers_exact, headers_pattern),
    );
    if previous.serving_digest != next.serving_digest {
        // Routing rules, serving config, or the control plane's serving state
        // moved, so a URL can answer differently with every file untouched. The
        // counts still describe the file set; the purge cannot be scoped.
        delta.changed_paths = None;
    }
    Some(delta)
}

/// Materializes each channel's substituted bytes in the Space CAS and returns
/// a complete file map for compiling that route's response table. The base map
/// remains the version-host representation.
fn compile_template_variant_files(
    routes: &BTreeMap<String, BTreeMap<String, String>>,
    files: &BTreeMap<String, FileMeta>,
    blobs: &Path,
) -> Result<BTreeMap<String, BTreeMap<String, FileMeta>>> {
    if routes.is_empty() {
        return Ok(BTreeMap::new());
    }
    if routes.len() > TEMPLATE_VARIANT_ROUTE_LIMIT {
        return invalid(
            "invalid_template_variants",
            format!("template_variants supports up to {TEMPLATE_VARIANT_ROUTE_LIMIT} routes."),
        );
    }

    let mut compiled = BTreeMap::new();
    for (route_name, values) in routes {
        if route_name.is_empty()
            || route_name.len() > TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS
            || !route_name
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
        {
            return invalid(
                "invalid_template_variants",
                format!("Invalid template variant route {route_name}."),
            );
        }
        if values.len() > TEMPLATE_VARIANT_FILE_LIMIT {
            return invalid(
                "invalid_template_variants",
                format!(
                    "Template variant route {route_name} supports up to {TEMPLATE_VARIANT_FILE_LIMIT} files."
                ),
            );
        }

        let mut route_files = files.clone();
        for (path, content) in values {
            validate_relative_path(path)?;
            let Some(base) = files.get(path) else {
                return invalid(
                    "template_not_in_version",
                    format!("Template variant {path} is not a committed file in this version."),
                );
            };
            if content.len() > TEMPLATE_MAX_BYTES {
                return invalid(
                    "invalid_template_variants",
                    format!("Template variant {path} exceeds {TEMPLATE_MAX_BYTES} bytes."),
                );
            }
            let bytes = content.as_bytes();
            put_blob(blobs, bytes)?;
            route_files.insert(path.clone(), file_meta(path, bytes, Some(&base.mime)));
        }
        compiled.insert(route_name.clone(), route_files);
    }
    Ok(compiled)
}

/// The substituted bytes this finalize publishes.
struct TemplateSubstitution {
    /// Committed path → the substituted bytes that replace the uploaded ones on
    /// the version host.
    template_files: BTreeMap<String, String>,
    /// Runtime route name → committed path → that channel's substituted bytes.
    template_variants: BTreeMap<String, BTreeMap<String, String>>,
    /// Every path substitution touched, from either representation.
    substituted_paths: Vec<String>,
    /// `_redirects` / `_headers` with their `{{ vars.NAME }}` references
    /// resolved, ready for the routing compiler. Basic-Auth directives are
    /// deliberately NOT stripped here: nothing crosses a boundary inside the
    /// finalizer, and the conventions compile is what refuses them and writes
    /// the sanitized text into immutable metadata.
    convention_files: PrepareConventionFiles,
    /// The staged config's committed path and its source with the routing
    /// sections substituted.
    routing_config: Option<(String, String)>,
    /// Provenance for the whole pass: dependency name (`NAME` or
    /// `NAME@channel`) → SHA-256 of the value it resolved to. The control plane
    /// stores this to answer "does a variable change require a republish?", and
    /// it can only be produced where substitution actually happens.
    variable_digests: BTreeMap<String, String>,
    /// The `SPACEFAST_*` platform names this version resolved.
    system_variable_dependencies: Vec<String>,
}

/// Resolves everything this version substitutes: declared templates, the
/// `_redirects` / `_headers` text, and the `sf.jsonc` routing sections.
///
/// All three spell the same `{{ vars.NAME }}` grammar, so they resolve in ONE
/// pass over one resolution set — a rule cannot depend on which of the three
/// shapes the publisher wrote it in. The control plane resolves the scopes
/// (secrets are never decrypted for it); the finalizer owns the substitution
/// because it holds the staged bytes.
///
/// Fail-closed: an unresolved or secret reference is an error diagnostic, and
/// any error aborts the finalize before a partially substituted byte is
/// published.
fn resolve_template_substitution(
    input: &SiteFinalizeInput,
    stage_root: &Path,
    diagnostics: &mut Vec<Value>,
) -> Result<TemplateSubstitution> {
    let variable_scopes: Vec<VariableScope> = optional_field(
        input.body.get("variable_scopes"),
        "variable_scopes",
        "invalid_variable_scopes",
        "a resolved variable scope list",
    )?;
    let system_variables: BTreeMap<String, String> = optional_field(
        input.body.get("system_variables"),
        "system_variables",
        "invalid_system_variables",
        "a name/value map",
    )?;
    let channels: Vec<Channel> = optional_field(
        input.body.get("channels"),
        "channels",
        "invalid_channels",
        "a channel list",
    )?;

    let config_candidates = staged_config_candidates(stage_root)?;
    let convention_files = staged_convention_files(stage_root)?;
    let routing_config = staged_config_source(stage_root)?
        .map(|(path, source)| PrepareRoutingConfig { path, source });
    let declared = analyze(AnalyzeInput {
        format: ANALYZE_INPUT_FORMAT.into(),
        config_candidates: config_candidates.clone(),
        convention_files: convention_files.clone(),
        routing_config: routing_config.clone(),
        template_sources: BTreeMap::new(),
    });
    let mut template_sources = BTreeMap::new();
    for path in &declared.template_paths {
        validate_relative_path(path)?;
        let staged = stage_root.join("files").join(path);
        if !staged.is_file() {
            continue;
        }
        let bytes = read_bounded(&staged, TEMPLATE_MAX_BYTES)?;
        template_sources.insert(path.clone(), String::from_utf8_lossy(&bytes).into_owned());
    }

    let prepared = prepare(PrepareInput {
        format: PREPARE_INPUT_FORMAT.into(),
        analysis: AnalyzeInputFields {
            config_candidates,
            convention_files,
            routing_config: routing_config.clone(),
            template_sources,
        },
        variable_scopes,
        system_variables,
        channels,
    });
    if let Some(error) = prepared
        .diagnostics
        .iter()
        .find(|diagnostic| diagnostic.severity == DiagnosticSeverity::Error)
    {
        // The failure is the whole point of publish-time substitution, so it
        // travels with everything it knows: which file, which line, which
        // variable, which channel. A bare code here is what turns "`_redirects`
        // line 3 references an unset API_HOST" into "publish failed".
        return invalid_with_details(
            "template_substitution_failed",
            format!("{}: {}", error.code, error.message),
            json!({
                "path": error.path,
                "diagnostics": runtime_diagnostics(
                    prepared
                        .diagnostics
                        .iter()
                        .filter(|diagnostic| diagnostic.severity == DiagnosticSeverity::Error)
                        .filter_map(|diagnostic| serde_json::to_value(diagnostic).ok())
                        .collect(),
                ),
            }),
        );
    }
    diagnostics.extend(
        prepared
            .diagnostics
            .iter()
            .filter_map(|diagnostic| serde_json::to_value(diagnostic).ok()),
    );
    Ok(TemplateSubstitution {
        template_files: prepared.template_files,
        template_variants: prepared.template_variants,
        substituted_paths: prepared.substituted_paths,
        convention_files: prepared.convention_files.unwrap_or_default(),
        routing_config: routing_config.map(|config| {
            (
                config.path,
                prepared.routing_config_source.unwrap_or(config.source),
            )
        }),
        variable_digests: prepared.dependencies,
        system_variable_dependencies: prepared.system_dependencies,
    })
}

/// The platform origins the control plane resolved for this publish, keyed by
/// the CSP directive each belongs in. Read strictly: a malformed value would
/// silently publish a policy that blocks the platform's own browser code.
fn platform_csp_sources(serving: &Map<String, Value>) -> Result<PlatformCspSources> {
    optional_field(
        serving.get("platform_csp_sources"),
        "platform_csp_sources",
        "invalid_platform_csp_sources",
        "a directive-to-source-list map",
    )
}

/// One optional, strictly-read `body`/`serving` field: absent or null yields the
/// default, anything present but malformed is a caller bug that fails the
/// publish rather than being coerced.
fn optional_field<T: serde::de::DeserializeOwned + Default>(
    field: Option<&Value>,
    key: &str,
    code: &'static str,
    expected: &str,
) -> Result<T> {
    match field {
        None | Some(Value::Null) => Ok(T::default()),
        Some(value) => serde_json::from_value(value.clone())
            .map_err(|error| invalid_error(code, format!("{key} is not {expected}: {error}"))),
    }
}

/// The staged `_redirects` / `_headers`, as the publisher committed them.
fn staged_convention_files(stage_root: &Path) -> Result<PrepareConventionFiles> {
    let mut files = PrepareConventionFiles::default();
    for (slot, name) in [
        (&mut files.redirects, "_redirects"),
        (&mut files.headers, "_headers"),
    ] {
        let path = stage_root.join("files").join(name);
        if !path.is_file() {
            continue;
        }
        let bytes = read_bounded(&path, PIPELINE_SOURCE_MAX_BYTES)?;
        *slot = Some(String::from_utf8_lossy(&bytes).into_owned());
    }
    Ok(files)
}

/// Every accepted config file present on the stage, keyed by committed path.
/// Presence is presence in the version; the compiler picks the winner.
fn staged_config_candidates(stage_root: &Path) -> Result<BTreeMap<String, String>> {
    let mut candidates = BTreeMap::new();
    for name in CONFIG_ACCEPTED_FILES {
        let path = stage_root.join("files").join(name);
        if !path.is_file() {
            continue;
        }
        let bytes = read_bounded(&path, CONFIG_FILE_MAX_BYTES)?;
        candidates.insert(
            (*name).to_string(),
            String::from_utf8_lossy(&bytes).into_owned(),
        );
    }
    Ok(candidates)
}

/// Whether this version's host class must never be indexed.
///
/// Read strictly: a value that is not a boolean is a caller bug, and silently
/// reading it as `false` would publish a preview into search results.
fn noindex_host(serving: &Map<String, Value>) -> Result<bool> {
    match serving.get("noindexHost") {
        None | Some(Value::Null) => Ok(false),
        Some(Value::Bool(value)) => Ok(*value),
        Some(_) => invalid(
            "invalid_finalize_input",
            "serving.noindexHost must be a boolean.",
        ),
    }
}

/// The uploaded object behind every path a transform rewrote, plus the original
/// bytes kept in the CAS so the record resolves to something. A path absent
/// here was never rewritten, so what it serves IS what was uploaded.
///
/// The content type comes from the served entry for the same path: same path,
/// same declared type, and taking it from there keeps the source view's type
/// identical to the served view's instead of re-guessing it from the extension.
fn original_objects(
    stage_root: &Path,
    blobs: &Path,
    files: &BTreeMap<String, FileMeta>,
) -> Result<BTreeMap<String, ObjectIdentity>> {
    let root = stage_root.join("files-original");
    let mut originals = BTreeMap::new();
    for entry in walkdir::WalkDir::new(&root)
        .follow_links(false)
        .into_iter()
        .filter_map(std::result::Result::ok)
        .filter(|entry| entry.file_type().is_file())
    {
        let Ok(relative) = entry.path().strip_prefix(&root) else {
            continue;
        };
        let bytes = fs::read(entry.path()).map_err(|source| FinalizeError::Io {
            path: entry.path().to_path_buf(),
            source,
        })?;
        let sha = put_blob(blobs, &bytes)?;
        let path = relative.to_string_lossy().replace('\\', "/");
        let content_type = files
            .get(&path)
            .map(|meta| meta.mime.clone())
            .unwrap_or_else(|| mime_for_path(&path, None));
        originals.insert(
            path,
            ObjectIdentity {
                sha256: sha,
                size: bytes.len() as u64,
                content_type,
            },
        );
    }
    Ok(originals)
}

/// The Zero control routes a version with a Zero runtime always answers, as
/// `(request path, method, operation)`. Both projections below iterate this one
/// list so a route cannot exist in the response table and not in the lookup map.
const ZERO_CONTROL_ROUTES: &[(&str, &str, &str)] = &[
    ("/__spacefast/zero/config", "GET", "config"),
    ("/__spacefast/zero/run", "POST", "run"),
    ("/__spacefast/zero/auth/gravatar/start", "GET", "auth_start"),
    ("/__spacefast/zero/auth/sign-out", "GET", "auth_sign_out"),
    (
        "/__spacefast/zero/realtime/events",
        "GET",
        "realtime_events",
    ),
];

/// The Zero control and exact-endpoint routes, as response-table actions.
/// A pattern route stays in `zero/routes.php`, which the miss lane consults.
fn zero_response_actions(body: &Value, compiled_zero_routes: &[Value]) -> Map<String, Value> {
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
    for record in compiled_zero_routes {
        let Some(pattern) = record.get("pattern").and_then(Value::as_str) else {
            continue;
        };
        if pattern.contains(':') {
            continue;
        }
        let Some(method) = record.get("method").and_then(Value::as_str) else {
            continue;
        };
        let mut action = json!({
            "t": "zero",
            "endpoint": record.get("endpointId").cloned().unwrap_or(Value::Null),
            "artifact": record.get("zeroArtifact").cloned().unwrap_or(Value::Null),
            "methods": zero_methods(method),
            "capabilities": record.get("capabilities").cloned().unwrap_or_else(|| json!({})),
        });
        if let Some(schema_hash) = record.get("schemaHash") {
            action["schema_hash"] = schema_hash.clone();
        }
        actions.insert(pattern.trim_matches('/').to_string(), action);
    }
    actions
}

fn zero_methods(method: &str) -> Value {
    if method == "GET" {
        json!(["GET", "HEAD"])
    } else {
        json!([method])
    }
}

/// The staged `sf.jsonc` this version publishes, by committed path. Reading its
/// routing sections is the routing compiler's job, so the file goes over whole.
fn staged_config_source(stage_root: &Path) -> Result<Option<(String, String)>> {
    let Some((name, path)) = CONFIG_ACCEPTED_FILES
        .iter()
        .map(|name| ((*name).to_string(), stage_root.join("files").join(name)))
        .find(|(_, path)| path.is_file())
    else {
        return Ok(None);
    };
    let bytes = read_bounded(&path, CONFIG_FILE_MAX_BYTES)?;
    Ok(Some((name, String::from_utf8_lossy(&bytes).into_owned())))
}

/// The scheduled requests this version declares, compiled into the generated
/// file the serving engine reads. It is written only when there is at least
/// one cron: the absence of the file is how a version says it has none, so a
/// promotion back to a cron-free version carries no stale schedule.
///
/// The declaration is read from the staged config AFTER variable substitution,
/// so a `{{ vars.NAME }}` in a cron path resolves the same way it does
/// everywhere else in the file.
fn write_crons_artifact(
    stage_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
    routing_config: Option<&(String, String)>,
) -> Result<()> {
    let Some((config_path, source)) = routing_config else {
        return Ok(());
    };
    // A config that does not parse has already failed this publish through the
    // shared config lane; there is nothing this pass could add.
    let Ok(document) = parse_jsonc(source) else {
        return Ok(());
    };
    let Some(declared) = document.get("crons") else {
        return Ok(());
    };
    let (entries, issues) = crons::validate(declared);
    if let Some(issue) = issues.first() {
        return invalid(issue.code(), format!("{config_path}: {}", issue.message()));
    }
    let Some(artifact) = crons::artifact(&entries) else {
        return Ok(());
    };
    let mut bytes = serde_json::to_vec_pretty(&artifact).map_err(|source| FinalizeError::Json {
        path: stage_root.join("files").join(CRONS_ARTIFACT_PATH),
        source,
    })?;
    bytes.push(b'\n');
    write_generated(
        &stage_root.join("files"),
        files,
        CRONS_ARTIFACT_PATH,
        &bytes,
        Some("application/json"),
    )
}

/// Compiles the Zero endpoints and run handlers. Error-severity diagnostics
/// abort the finalize before anything is published; info/warning diagnostics
/// join the shared stream.
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
    ZERO_CONTROL_ROUTES
        .iter()
        .map(|(path, method, operation)| {
            (
                path.trim_start_matches('/').to_string(),
                json!({
                    "action": "invoke_zero",
                    "operation": operation,
                    "methods": zero_methods(method),
                }),
            )
        })
        .collect()
}

fn exact_zero_lookup_action(record: &Value) -> Option<(String, Value)> {
    let pattern = record.get("pattern")?.as_str()?;
    if pattern.contains(':') {
        return None;
    }
    let method = record.get("method")?.as_str()?;
    let mut action = json!({
        "action": "invoke_zero",
        "endpoint_id": record.get("endpointId")?.clone(),
        "zero_artifact": record.get("zeroArtifact")?.clone(),
        "methods": zero_methods(method),
        "capabilities": record.get("capabilities").cloned().unwrap_or_else(|| json!({})),
    });
    if let Some(schema_hash) = record.get("schemaHash") {
        action["schema_hash"] = schema_hash.clone();
    }
    Some((pattern.trim_matches('/').to_string(), action))
}

/// A compiled Zero artifact as JSON. These types are finalizer-owned and always
/// serialize; a failure here is a bug in this crate, not bad publisher input.
fn serialized<T: serde::Serialize>(value: &T) -> Value {
    serde_json::to_value(value).expect("compiled Zero artifact serializes")
}

fn write_zero_artifacts(stage_root: &Path, compiled: &CompiledZeroEndpoints) -> Result<()> {
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
        write_json(&stage_root.join(format!("zero/{basename}.json")), &value)?;
        write_php(&stage_root.join(format!("zero/{basename}.php")), &value)?;
    }
    for artifact in &compiled.endpoint_artifacts {
        write_json(
            &stage_root.join(zero_endpoint_artifact_path(artifact)),
            &serialized(artifact),
        )?;
    }
    for artifact in &compiled.run_artifacts {
        write_json(
            &stage_root.join(zero_run_artifact_path(artifact)),
            &serialized(artifact),
        )?;
    }
    for generated in &compiled.generated_files {
        validate_relative_path(&generated.path)?;
        write_bytes(&stage_root.join(&generated.path), &generated.bytes)?;
    }
    Ok(())
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
) -> Result<SiteFinalizeOutput> {
    let metadata_path = version_root.join("metadata.json");
    let bytes = fs::read(&metadata_path).map_err(|_| {
        invalid_error(
            "version_existing_invalid",
            "The existing immutable version metadata is unavailable.",
        )
    })?;
    let metadata: Value = serde_json::from_slice(&bytes).map_err(|_| {
        invalid_error(
            "version_existing_invalid",
            "The existing immutable version metadata is invalid.",
        )
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
    // The catalog is what a version IS: its file count, its digests, and the
    // proof it was published at all. A committed version without a readable one
    // answers nothing, so a replay refuses it rather than reporting ready.
    let Some(catalog) = read_version_catalog(version_root)? else {
        return invalid(
            "version_existing_invalid",
            "The existing immutable version embeds no file catalog.",
        );
    };
    // This branch answers a replay of a finalize that already happened, and
    // `finalizeInputSha256` above proves the version on disk was produced by
    // exactly this input. The published surface is the pointer, the root it
    // names, and the tables that root names, so those three are checked and no
    // per-file sweep runs — a demoted version legitimately has files unlinked
    // from the tree with their bytes in the cold store.
    let pointer = version_root.join(VERSION_ROOT_POINTER_FILE);
    let root: Value = fs::read(&pointer)
        .ok()
        .and_then(|bytes| serde_json::from_slice(&bytes).ok())
        .ok_or_else(|| {
            invalid_error(
                "version_existing_invalid",
                format!("The existing immutable version is missing {VERSION_ROOT_POINTER_FILE}."),
            )
        })?;
    let root_file = root.get("root").and_then(Value::as_str).ok_or_else(|| {
        invalid_error(
            "version_existing_invalid",
            "The existing immutable version root pointer is invalid.",
        )
    })?;
    if !version_root.join(root_file).is_file() {
        return invalid(
            "version_existing_invalid",
            format!("The existing immutable version is missing {root_file}."),
        );
    }
    let tables = metadata
        .get("tables")
        .and_then(Value::as_object)
        .filter(|tables| !tables.is_empty())
        .ok_or_else(|| {
            invalid_error(
                "version_existing_invalid",
                "The existing immutable version records no response table.",
            )
        })?;
    for table in tables.values() {
        let Some(table) = table.as_str() else {
            return invalid(
                "version_existing_invalid",
                "The existing immutable version records an invalid response table.",
            );
        };
        if !version_root.join(table).is_file() {
            return invalid(
                "version_existing_invalid",
                format!("The existing immutable version is missing {table}."),
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
        .ok_or_else(|| {
            invalid_error(
                "version_existing_invalid",
                "The existing immutable version diagnostics are invalid.",
            )
        })?;
    // A replay answers with the delta the first finalize published, rather than
    // recomputing one against a live version that has since moved.
    let delta = metadata
        .get(CATALOG_DELTA_METADATA_KEY)
        .filter(|delta| !delta.is_null())
        .map(|delta| serde_json::from_value::<CatalogDelta>(delta.clone()))
        .transpose()
        .map_err(|_| {
            invalid_error(
                "version_existing_invalid",
                "The existing immutable version records an invalid catalog delta.",
            )
        })?;
    Ok(SiteFinalizeOutput {
        format: SITE_FINALIZE_OUTPUT_FORMAT.to_string(),
        space_id: input.space_id.clone(),
        version_id: input.version_id.clone(),
        file_count: catalog.paths.len(),
        zero_endpoint_count,
        diagnostics,
        catalog_digests: Some(catalog.digests()),
        delta,
        // A replay runs no stage: it reads the committed version back and
        // answers with what it already published. Reporting zeroed stages here
        // would put fake work in the very numbers the incremental path is
        // measured by, so a replay reports nothing.
        telemetry: None,
    })
}

/// Validates the presence and internal consistency of the Zero artifacts
/// against the counts recorded in immutable metadata.
fn validate_zero_artifacts(
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

/// Lowers the shared `Vec<Value>` diagnostic stream the layer modules emit into
/// the `RuntimeDiagnostic` wire shape.
///
/// Two grammars feed this stream. Substitution and Zero diagnostics carry
/// `path` + a `details` object; routing diagnostics carry `file` + `line` +
/// `source`. Both lower into ONE shape — `file:line` in `path`, `source` folded
/// into `details` — so the control plane renders a routing warning and a
/// substitution failure through the same receipt projection.
fn runtime_diagnostics(values: Vec<Value>) -> Vec<RuntimeDiagnostic> {
    values
        .into_iter()
        .map(|value| {
            let line = value.get("line").and_then(Value::as_u64).unwrap_or(0);
            let path = match value.get("path").and_then(Value::as_str) {
                Some(path) => Some(path.to_string()),
                None => value
                    .get("file")
                    .and_then(Value::as_str)
                    .map(|file| match line {
                        0 => file.to_string(),
                        line => format!("{file}:{line}"),
                    }),
            };
            let mut details = value
                .get("details")
                .and_then(Value::as_object)
                .cloned()
                .unwrap_or_default();
            if let Some(source) = value.get("source").and_then(Value::as_str) {
                details.insert("source".into(), Value::String(source.to_string()));
            }
            if line > 0 && !details.contains_key("line") {
                details.insert("line".into(), Value::from(line));
            }
            RuntimeDiagnostic {
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
                path,
                details: (!details.is_empty()).then_some(details),
            }
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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::mime_for_path;
    use crate::model::{RuntimeZeroEndpoint, RuntimeZeroRun, SITE_FINALIZE_INPUT_FORMAT};
    use std::path::PathBuf;
    use tempfile::{tempdir, TempDir};

    /// Puts bytes in the space CAS the way ingest does — hashed on arrival,
    /// stored under their own digest — and returns the publish-session manifest
    /// they were declared under.
    fn accept_blobs(private: &Path, files: &[(&str, &[u8])]) -> Vec<Value> {
        let mut manifest = Vec::new();
        for (path, bytes) in files {
            let hash = sha256(bytes);
            let blob = private.join(format!("spaces/s/blobs/{}/{hash}", &hash[..2]));
            fs::create_dir_all(blob.parent().unwrap()).unwrap();
            fs::write(&blob, bytes).unwrap();
            manifest.push(json!({
                "path": path,
                "size": bytes.len(),
                "sha256": hash,
                "contentType": mime_for_path(path, None),
            }));
        }
        manifest
    }

    /// The `accepted` map the durable publish session records once every
    /// declared blob has arrived: sha256 → the length it arrived at.
    fn accepted_from(manifest: &[Value]) -> Value {
        Value::Object(
            manifest
                .iter()
                .map(|entry| {
                    (
                        entry["sha256"].as_str().unwrap().to_string(),
                        entry["size"].clone(),
                    )
                })
                .collect(),
        )
    }

    fn fixture_input(
        private: &Path,
        manifest: Vec<Value>,
        metadata: Value,
        body: Value,
    ) -> SiteFinalizeInput {
        let accepted = accepted_from(&manifest);
        SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.into(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".into(),
            version_id: "v".into(),
            upload_id: Some("u".into()),
            generated_at: "2026-07-12T00:00:00Z".into(),
            session: json!({
                "manifest": manifest,
                "accepted": accepted,
                "metadata": metadata,
            }),
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
        let manifest = accept_blobs(&private, files);
        let input = fixture_input(&private, manifest, metadata, body);
        let result = finalize_site(input, false);
        (temp, private, result)
    }

    fn finalized_readiness_target(private: &Path) -> Value {
        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        metadata["readinessTarget"].clone()
    }

    fn finalized_metadata(private: &Path) -> Value {
        serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap()
    }

    /// The published catalog, read the way the PHP resolver reads it.
    fn finalized_catalog(private: &Path) -> FileCatalog {
        read_version_catalog(&private.join("spaces/s/versions/v"))
            .unwrap()
            .expect("every published version carries a catalog")
    }

    /// The published response tables, concatenated. This is THE artifact the
    /// serve path reads, and there is no PHP interpreter here to read it with.
    fn finalized_table(private: &Path) -> String {
        let version = private.join("spaces/s/versions/v");
        let metadata = finalized_metadata(private);
        metadata["tables"]
            .as_object()
            .expect("published tables")
            .values()
            .map(|table| fs::read_to_string(version.join(table.as_str().unwrap())).unwrap())
            .collect()
    }

    /// A published body, read out of the CAS the way the serve path reaches it:
    /// the catalog names the served object, the CAS holds its bytes.
    fn finalized_body(private: &Path, path: &str) -> Vec<u8> {
        let catalog = finalized_catalog(private);
        let sha = catalog
            .paths
            .get(path)
            .and_then(|entry| entry.served.as_ref())
            .unwrap_or_else(|| panic!("{path} is not published"))
            .sha256
            .clone();
        fs::read(private.join(format!("spaces/s/blobs/{}/{sha}", &sha[..2]))).unwrap()
    }

    #[test]
    fn the_exact_path_lookup_never_answers_ahead_of_an_earlier_pattern_rule() {
        // The engine resolves this map before it walks the ordered rules
        // (runtime/engine/runtime/serve.php), so a rule in it wins outright.
        // `/docs/:slug` is order 0 and owns `/docs/intro`; the exact rule at
        // order 1 must stay out of the map and let the ordered walk decide.
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"<h1>hi</h1>"),
                (
                    "_redirects",
                    b"/docs/:slug /guides/:slug 301\n/docs/intro /start 301",
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        let table = finalized_table(&private);
        assert!(table.contains("'/index.html' =>"), "{table}");
        // The ordered walk owns this path, so nothing precomputed answers it.
        // Table KEYS render at one indent level; the same path also appears
        // deeper inside `\0rules`, which is the ordered list itself and is
        // exactly what has to be there.
        assert!(!table.contains("\n    '/docs/intro' =>"), "{table}");
        // ...and the complete ordered list is published for that walk to use.
        assert!(table.contains("\\x00rules"), "{table}");
    }

    #[test]
    fn readiness_target_is_selected_only_from_runtime_public_files() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("sf.jsonc", br#"{"title":"private"}"#),
                (".draft", b"hidden"),
                ("theme.json", br#"{"version":3}"#),
                (
                    "__spacefast/zero/deploy.json",
                    br#"{"digest":"sha256:zero"}"#,
                ),
                // Smaller than the public proof, but provider-owned in
                // production and therefore not a valid runtime probe.
                ("robots.txt", b"x"),
                ("assets/public proof.txt", b"this object is publicly served"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{"experimental_gutenberg":true},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/assets/public%20proof.txt","expected_statuses":[200,302,401,403]})
        );
    }

    #[test]
    fn functions_bundle_is_not_selected_as_a_public_readiness_target() {
        let (_temp, private, output) = finalize_fixture(
            &[(
                "__spacefast/functions/bundles/worker/bundle.json",
                br#"{"main":"worker.js"}"#,
            )],
            json!({"mode":"website"}),
            json!({"serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/","expected_statuses":[302,401,403,404]})
        );
    }

    #[test]
    fn private_only_finalize_records_an_attributed_not_found_readiness_target() {
        let (_temp, private, output) = finalize_fixture(
            &[("sf.jsonc", b"{}"), (".draft", b"hidden")],
            json!({"mode":"website"}),
            json!({"serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/","expected_statuses":[302,401,403,404]})
        );
    }

    #[test]
    fn private_only_root_303_records_the_final_routed_status() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("sf.jsonc", b"{}"),
                (".draft", b"hidden"),
                ("_redirects", b"/ /elsewhere 303"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/","expected_statuses":[302,303,401,403]})
        );
    }

    #[test]
    fn private_only_conditional_root_pattern_records_every_reachable_status() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("sf.jsonc", b"{}"),
                (".draft", b"hidden"),
                ("_redirects", b"/* /agents 307 Agent=true"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/","expected_statuses":[302,307,401,403,404]})
        );
    }

    #[test]
    fn private_only_readiness_ignores_mutable_access_policy() {
        for (effect, auth) in [
            ("challenge", Some(json!({"requiredGrants":["space:s"]}))),
            ("deny", None),
        ] {
            let mut rule = json!({
                "id":format!("root_{effect}"),
                "match":{"pathPattern":"/"},
                "effect":effect,
            });
            if let Some(auth) = auth {
                rule["auth"] = auth;
            }
            let (_temp, private, output) = finalize_fixture(
                &[("sf.jsonc", b"{}")],
                json!({"mode":"website"}),
                json!({
                    "serving": {"config": {}},
                    "activate": {"config":{"policy":{"rules":[rule]}}}
                }),
            );
            output.unwrap();
            assert_eq!(
                finalized_readiness_target(&private),
                json!({"path":"/","expected_statuses":[302,401,403,404]})
            );
        }
    }

    #[test]
    fn private_only_root_first_match_excludes_a_later_redirect() {
        for action in ["rewrite", "notFound"] {
            let first_status = if action == "notFound" { 404 } else { 200 };
            let redirects = format!("/ /missing {first_status}!\n/ /unreachable 302");
            let (_temp, private, output) = finalize_fixture(
                &[("sf.jsonc", b"{}"), ("_redirects", redirects.as_bytes())],
                json!({"mode":"website"}),
                json!({"serving":{"config":{}}}),
            );
            output.unwrap();
            assert_eq!(
                finalized_readiness_target(&private),
                json!({"path":"/","expected_statuses":[302,401,403,404]})
            );
        }
    }

    #[test]
    fn private_only_root_lets_an_earlier_pattern_rule_beat_an_exact_one() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("sf.jsonc", b"{}"),
                ("_redirects", b"/* /pattern 307\n/ /exact 302"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        // First match wins over ONE ordered list: `/*` is order 0, so it answers
        // `/` and the exact rule at order 1 never gets asked. The exact-path
        // lookup map is a fast path, not a second precedence rule.
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/","expected_statuses":[302,307,401,403]})
        );
    }

    #[test]
    fn private_only_root_proxy_is_rejected_without_a_stable_readiness_status() {
        let (_temp, _private, output) = finalize_fixture(
            &[
                ("sf.jsonc", b"{}"),
                ("_redirects", b"/ https://origin.example.test/ 200"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        assert!(matches!(
            output,
            Err(FinalizeError::Invalid {
                code: "runtime_readiness_proxy_root_unsupported",
                ..
            })
        ));
    }

    #[test]
    fn private_only_root_rejects_dynamic_zero_fallback_ownership() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(&private, &[("sf.jsonc", b"{}")]);
        let mut input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({"zero":{"runtimeKind":"zero"},"serving":{"config":{}}}),
        );
        input.zero_endpoints = vec![RuntimeZeroEndpoint {
            method: "GET".into(),
            path: "/:splat".into(),
            source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });".into(),
            endpoint_id: None,
            schema_hash: None,
            capabilities: Default::default(),
            db: None,
        }];

        assert!(matches!(
            finalize_site(input, false),
            Err(FinalizeError::Invalid {
                code: "runtime_readiness_public_target_unavailable",
                ..
            })
        ));
    }

    #[test]
    fn public_readiness_records_forced_not_found_and_missing_rewrite_results() {
        for status in [404, 200] {
            let redirects = format!("/probe.txt /missing {status}!\n/probe.txt /unreachable 302");
            let (_temp, private, output) = finalize_fixture(
                &[
                    ("probe.txt", b"probe"),
                    ("_redirects", redirects.as_bytes()),
                ],
                json!({"mode":"website"}),
                json!({"serving":{"config":{}}}),
            );
            output.unwrap();
            assert_eq!(
                finalized_readiness_target(&private),
                json!({"path":"/probe.txt","expected_statuses":[302,401,403,404]})
            );
        }
    }

    #[test]
    fn public_readiness_records_existing_rewrite_target_result() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"p"),
                ("rewritten.html", b"the rewritten public object"),
                ("_redirects", b"/probe.txt /rewritten.html 200!"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[200,302,401,403]})
        );
    }

    #[test]
    fn public_readiness_keeps_selected_file_fallback_for_conditional_rewrite() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"probe"),
                ("_redirects", b"/probe.txt /missing 200! Country=nl"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[200,302,401,403,404]})
        );
    }

    #[test]
    fn public_readiness_treats_relative_rewrite_as_the_original_request_path() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"probe"),
                ("_redirects", b"/probe.txt relative-target 200!"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[200,302,401,403]})
        );
    }

    #[test]
    fn public_readiness_suppresses_spa_fallback_for_missing_asset_rewrite() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"p"),
                ("index.html", b"the application shell"),
                ("_redirects", b"/probe.txt /missing.png 200!"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{"fallback":{"path":"index.html","status":200}}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[302,401,403,404]})
        );
    }

    #[test]
    fn public_readiness_lets_an_earlier_pattern_rule_beat_an_exact_one() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"probe"),
                ("_redirects", b"/* /pattern 307\n/probe.txt /elsewhere 302"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[302,307,401,403]})
        );
    }

    #[test]
    fn public_readiness_preserves_rewrite_target_trailing_slash() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("probe.txt", b"p"),
                ("literal", b"literal public file"),
                ("_redirects", b"/probe.txt /literal/ 200!"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/probe.txt","expected_statuses":[302,401,403,404]})
        );
    }

    #[test]
    fn public_readiness_skips_a_file_shadowed_by_an_exact_zero_action() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(
            &private,
            &[
                ("api/status", b"x"),
                ("proof.txt", b"stable public readiness object"),
            ],
        );
        let mut input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({
                "zero":{"runtimeKind":"zero"},
                "serving":{
                    "config":{},
                    "redirects_exact":{},
                    "redirects_pattern":[],
                    "headers_exact":{},
                    "headers_pattern":[]
                }
            }),
        );
        input.zero_endpoints = vec![RuntimeZeroEndpoint {
            method: "POST".into(),
            path: "/api/status".into(),
            source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });".into(),
            endpoint_id: None,
            schema_hash: None,
            capabilities: Default::default(),
            db: None,
        }];

        finalize_site(input, false).unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/proof.txt","expected_statuses":[200,302,401,403]})
        );
    }

    #[test]
    fn transformed_markdown_readiness_targets_the_generated_public_output() {
        let (_temp, private, output) = finalize_fixture(
            &[
                (
                    "docs/page.md",
                    b"---\ntitle: Public page\n---\n\n# Public page\n",
                ),
                (
                    "_layout.html",
                    b"<!doctype html><html><body>{{ content }}</body></html>",
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{"experimental_gutenberg":true},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );
        output.unwrap();
        assert_eq!(
            finalized_readiness_target(&private),
            json!({"path":"/docs/page/index.html","expected_statuses":[200,302,401,403]})
        );
    }

    #[test]
    fn heavy_many_page_finalize_materializes_rust_artifacts_and_html() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let mut sources: Vec<(String, Vec<u8>)> = (0..5_000)
            .map(|index| {
                (
                    format!("docs/page-{index:05}.md"),
                    format!("---\ntitle: Page {index}\ndescription: Heavy parser fixture\n---\n\n# Heading {index}\n\nBody with *markup*, [link](/docs/{index}), and `code-{index}`.\n")
                        .into_bytes(),
                )
            })
            .collect();
        for (path, body) in [
            (
                "_layout.html",
                "<!doctype html><html><head><title>{{ page.title }}</title></head><body>{{ content }}</body></html>",
            ),
            ("_redirects", "/old /docs/page-00001/ 301"),
            ("_headers", "/docs/*\n  X-Parser: rust"),
        ] {
            sources.push((path.to_string(), body.as_bytes().to_vec()));
        }
        let borrowed: Vec<(&str, &[u8])> = sources
            .iter()
            .map(|(path, body)| (path.as_str(), body.as_slice()))
            .collect();
        let manifest = accept_blobs(&private, &borrowed);
        let input = fixture_input(
            &private,
            manifest,
            json!({"title":"Heavy"}),
            json!({"serving":{"config":{"experimental_gutenberg":true,"meta":{"title":"Heavy"}}}}),
        );
        let output = finalize_site(input, false).unwrap();
        assert!(output.file_count >= 10_000);
        // The stage telemetry is what makes incrementality measurable, so it
        // has to describe THIS corpus: 5_000 markdown sources plus the layout
        // and the two convention files staged, every page rendered and then
        // decorated, and nothing skipped until the incremental path exists.
        let telemetry = output
            .telemetry
            .as_ref()
            .expect("a finalize that ran reports its stages");
        assert_eq!(telemetry.staged_files, 5_003);
        assert_eq!(telemetry.generated_files, 5_000);
        assert_eq!(telemetry.decorated_files, 5_000);
        assert_eq!(telemetry.skipped_files, 0);
        assert!(telemetry.total_ms > 0, "{telemetry:?}");
        assert!(
            telemetry.staging_ms
                + telemetry.template_substitution_ms
                + telemetry.html_pipeline_ms
                + telemetry.conventions_ms
                + telemetry.zero_compile_ms
                + telemetry.blob_install_ms
                + telemetry.listings_ms
                + telemetry.response_tables_ms
                + telemetry.catalog_delta_ms
                + telemetry.artifacts_write_ms
                <= telemetry.total_ms,
            "{telemetry:?}"
        );
        let html =
            String::from_utf8(finalized_body(&private, "docs/page-04999/index.html")).unwrap();
        assert!(html.contains("<title>Page 4999</title>"));
        assert!(html.contains("<em>markup</em>"));
        // A version is its pointer, its root, and the tables the root names.
        let version = private.join("spaces/s/versions/v");
        assert!(version.join("root.json").is_file());
        let root: Value =
            serde_json::from_slice(&fs::read(version.join("root.json")).unwrap()).unwrap();
        assert!(version.join(root["root"].as_str().unwrap()).is_file());
        // The staging tree is a workspace, never an output.
        assert!(!version.join("files").exists());
    }

    #[test]
    fn interrupted_replacement_recovers_the_previous_version_before_work() {
        let temp = tempdir().unwrap();
        let private = temp.path().join("storage");
        let versions = private.join("spaces/s/versions");
        let backup = versions.join(".v.rust-previous");
        fs::create_dir_all(backup.join("files")).unwrap();
        fs::write(backup.join("files/index.html"), b"live-before-interruption").unwrap();
        let result = finalize_site(
            fixture_input(
                &private,
                Vec::new(),
                json!({}),
                json!({"access_pages":{"unknown":"invalid"},"serving":{"config":{}}}),
            ),
            false,
        );
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
            &[
                ("index.html", b"<h1>home</h1>"),
                ("_redirects", b"https://www.example.com/old /new 301"),
                (
                    "_headers",
                    b"/private\n  X-Frame-Options: DENY\n  Content-Security-Policy: default-src 'self'",
                ),
            ],
            json!({"mode":"website"}),
            json!({
                "routing_assigned_hostnames":["www.example.com"],
                "serving":{
                    "config":{},
                    "platform_csp_sources":{"connect-src":["https://api.spacefast.test"]}
                }
            }),
        );
        output.unwrap();
        // A host-qualified redirect and a host-independent header rule: one can
        // only be decided per request, the other is baked into every entry it
        // matches, so the table carries the first and the entries carry the
        // second.
        let table = finalized_table(&private);
        assert!(table.contains("www.example.com"), "{table}");
        let metadata = finalized_metadata(&private);
        let headers = serde_json::to_string(&metadata["headers"]).unwrap();
        assert!(headers.contains("X-Frame-Options"));
        // The publisher's own policy survives and the platform's source joins
        // it, so the version's own CSP cannot lock the platform's browser code
        // out of the page it published.
        assert!(
            headers.contains("default-src 'self'; connect-src 'self' https://api.spacefast.test"),
            "{headers}"
        );
    }

    #[test]
    fn raw_authorization_directive_is_rejected_without_leaking_credentials() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"<h1>home</h1>"),
                (
                    "_headers",
                    b"/private\n  Basic-Auth: user:pass\n  X-Frame-Options: DENY",
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        let output = output.expect("unsupported directive does not block safe response headers");
        assert!(output
            .diagnostics
            .iter()
            .any(|diagnostic| diagnostic.code == "header_basic_auth_unsupported"));
        let serialized = serde_json::to_string(&output).unwrap();
        assert!(!serialized.contains("user:pass"));
        assert!(!serialized.contains("\"password\":\"pass\""));
        let metadata = fs::read_to_string(private.join("spaces/s/versions/v/metadata.json"))
            .expect("private metadata");
        assert!(!metadata.contains("user:pass"));
        assert!(metadata.contains("X-Frame-Options"));
    }

    // The staged convention files and the staged config file compile together:
    // `_redirects` first, `sf.jsonc` behind it.
    #[test]
    fn staged_config_rules_merge_behind_the_staged_convention_files() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                ("_redirects", b"/old /file-wins 301"),
                (
                    "sf.jsonc",
                    br#"{
                      "version": 1,
                      "redirects": [
                        { "source": "/old", "destination": "/config-loses", "status": 302 },
                        { "source": "/legacy/*", "destination": "/archive/:splat", "status": 301 }
                      ],
                      "headers": [
                        { "source": "/*", "headers": [{ "key": "X-Config", "value": "yes" }] }
                      ]
                    }"#,
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();

        let metadata = finalized_metadata(&private);
        let redirects = metadata["redirects"].as_array().expect("redirect rules");
        assert_eq!(redirects.len(), 3);
        assert_eq!(redirects[0]["source"], "/old");
        assert_eq!(redirects[0]["destination"], "/file-wins");
        assert_eq!(redirects[1]["source"], "/old");
        assert_eq!(redirects[1]["destination"], "/config-loses");
        assert_eq!(redirects[2]["source"], "/legacy/*");
        assert_eq!(redirects[2]["destination"], "/archive/:splat");

        let headers = metadata["headers"].as_array().expect("header rules");
        assert_eq!(headers.len(), 1);
        assert_eq!(headers[0]["path"], "/*");
        assert_eq!(headers[0]["operations"][0]["kind"], "set");
        assert_eq!(headers[0]["operations"][0]["name"], "X-Config");
        assert_eq!(headers[0]["operations"][0]["value"], "yes");
    }

    /// The generated file is the whole interface with the serving engine, so
    /// its bytes and its absence are both part of the contract: a version with
    /// no `crons` key must not carry a stale schedule forward.
    #[test]
    fn declared_crons_compile_into_the_generated_runtime_file() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                (
                    "sf.jsonc",
                    br#"{
                      "crons": [
                        { "path": "/api/cron/growth-report", "schedule": "0 7 * * *" },
                        { "path": "/api/cron/digest", "schedule": "twicedaily" }
                      ]
                    }"#,
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        let catalog = finalized_catalog(&private);
        let entry = catalog
            .paths
            .get(CRONS_ARTIFACT_PATH)
            .expect("a declared cron publishes the generated schedule");
        let sha = &entry.source.sha256;
        let bytes = fs::read(private.join(format!("spaces/s/blobs/{}/{sha}", &sha[..2]))).unwrap();
        assert_eq!(
            serde_json::from_slice::<Value>(&bytes).unwrap(),
            json!({
                "version": 1,
                "crons": [
                    {
                        "key": "api-cron-growth-report",
                        "path": "/api/cron/growth-report",
                        "schedule": "0 7 * * *"
                    },
                    { "key": "api-cron-digest", "path": "/api/cron/digest", "schedule": "twicedaily" }
                ]
            })
        );
        // Generated, not content: the schedule never answers a request.
        assert_eq!(entry.served, None);
        assert!(!entry.public);

        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                ("sf.jsonc", br#"{"listing":true}"#),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        assert!(!finalized_catalog(&private)
            .paths
            .contains_key(CRONS_ARTIFACT_PATH));
    }

    #[test]
    fn finalized_routing_preserves_v1_rewrite_behavior() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                ("agents-doc/index.html", b"human"),
                ("agents-doc.md", b"agent"),
                ("agent-handoff.html", b"handoff"),
                ("blog/404.html", b"gone"),
                (
                    "_redirects",
                    b"/old /legacy 301\n\
/found /about.html 302\n\
/agents-doc /agents-doc.md 200! Agent=true\n\
/app/* /index.html 200\n\
/agent/* /agent-handoff.html 200!\n\
/gone/* /blog/404.html 404",
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();

        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        let redirects = metadata["redirects"].as_array().unwrap();
        assert_eq!(redirects.len(), 6);
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
        assert!(redirects
            .iter()
            .any(|rule| rule["destination"] == "/legacy"));
    }

    #[test]
    fn finalize_rejects_invalid_embedded_page_shapes() {
        for (body, code) in [
            (
                json!({"access_pages":{"other":"x"},"serving":{"config":{}}}),
                "invalid_access_pages",
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
        let html = String::from_utf8(finalized_body(&private, "page.html")).unwrap();
        assert!(html.contains("stattic-block-document"));
        assert!(html.contains("<title>Hello</title>"));

        // The uploaded bytes survive the transform, in the CAS, recorded per
        // transformed path — which is what lets the next publish retain
        // this path by its SOURCE hash.
        let original_hash = sha256(source);
        assert_eq!(
            finalized_catalog(&private).paths["page.html"].source.sha256,
            original_hash
        );
        assert_eq!(
            fs::read(private.join(format!(
                "spaces/s/blobs/{}/{original_hash}",
                &original_hash[..2]
            )))
            .unwrap(),
            source
        );
        let retained = finalize_site(
            SiteFinalizeInput {
                format: SITE_FINALIZE_INPUT_FORMAT.into(),
                version_root: private.to_string_lossy().into_owned(),
                space_id: "s".into(),
                version_id: "v2".into(),
                upload_id: Some("u2".into()),
                generated_at: "2026-07-12T00:00:01Z".into(),
                session: json!({
                    "manifest":[],
                    "accepted":{},
                    "reusable_version_id":"v",
                    "retained_files":[{"path":"page.html","size":source.len(),"sha256":original_hash,"contentType":"text/html"}],
                    "metadata":{"mode":"files","content":{"format":"gutenberg-blocks"}}
                }),
                body: json!({"serving":{"config":{"listing":true,"viewer":true},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
                zero_endpoints: Vec::new(),
                zero_runs: Vec::new(),
            },
            false,
        );
        retained.unwrap();
        let retained_catalog = read_version_catalog(&private.join("spaces/s/versions/v2"))
            .unwrap()
            .expect("every published version carries a catalog");
        assert!(retained_catalog.paths["page.html"].served.is_some());
    }

    // Adapted from the donor's
    // `finalize_compiles_zero_config_routes_bundles_and_static_files` to trunk
    // zero semantics: endpoints/runs arrive as typed input fields and compile
    // through trunk `compile_zero_endpoints` into trunk artifact shapes.
    // Donor-only behaviors (zero/config.json capsule, bundle/static-file
    // materialization, variable-value filtering) are not asserted.
    #[test]
    fn finalize_compiles_trunk_zero_endpoints_and_control_routes() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(&private, &[("index.html", b"home")]);
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
        let output = finalize_site(input, false).unwrap();
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

        // Zero control routes are exact, so they compile into the response
        // table; the dynamic endpoint is resolved from zero/routes.php above.
        let table = finalized_table(&private);
        assert!(table.contains("'operation' => 'config'"), "{table}");
        assert!(table.contains("'t' => 'zero'"), "{table}");
    }

    fn fixture_path(name: &str) -> PathBuf {
        Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("../../e2e-tests/fixtures/zero-all-features")
            .join(name)
    }

    fn invoke_compiled_zero(
        version: &Path,
        endpoint_id: &str,
        method: &str,
        path: &str,
        artifact_path: Option<&str>,
    ) -> (u16, Value, Vec<Value>) {
        let envelope = json!({
            "protocol": "stattic.zero.invoke.v1",
            "versionRoot": version.to_string_lossy(),
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
                "spaceId": "spc_zero_all_features",
                "versionId": "ver_zero_all_features",
                "schemaHash": null,
                "authRef": "current",
                "variablesRef": "finalized"
            },
            "auth": {
                "userId": "usr_zero_fixture",
                "isAuthenticated": true,
                "provider": "gravatar"
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

    /// The checked-in Zero all-features payload is the shared example the API
    /// contract test reads too, so finalizing it here keeps the fixture honest
    /// end to end: every capability the compiled bytecode is supposed to expose
    /// has to actually be installed when the runner invokes it.
    #[test]
    fn zero_all_features_fixture_finalizes_to_invocable_private_artifacts() {
        let mut input =
            crate::read_site_finalize_input(fixture_path("runtime-compiler-finalize-input.json"))
                .expect("fixture input");
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let body = fs::read(fixture_path("public/index.html")).unwrap();
        let manifest = input.session["manifest"]
            .as_array()
            .expect("fixture manifest");
        let [entry] = manifest.as_slice() else {
            panic!("fixture declares one public object");
        };
        assert_eq!(entry["path"], "index.html");
        let hash = entry["sha256"].as_str().expect("fixture sha256");
        assert_eq!(hash, sha256(&body));
        assert_eq!(entry["size"].as_u64(), Some(body.len() as u64));
        let blob = private.join(format!(
            "spaces/spc_zero_all_features/blobs/{}/{hash}",
            &hash[..2]
        ));
        fs::create_dir_all(blob.parent().unwrap()).unwrap();
        fs::write(&blob, &body).unwrap();
        input.version_root = private.to_string_lossy().into_owned();

        let output = finalize_site(input, false).expect("fixture finalizes");
        assert_eq!(output.zero_endpoint_count, 4);
        assert!(
            output
                .diagnostics
                .iter()
                .all(|diagnostic| diagnostic.severity != RuntimeDiagnosticSeverity::Error),
            "fixture diagnostics: {:?}",
            output.diagnostics
        );

        let version = private.join("spaces/spc_zero_all_features/versions/ver_zero_all_features");
        let endpoint_index: Value =
            serde_json::from_slice(&fs::read(version.join("zero/endpoints-index.json")).unwrap())
                .unwrap();
        let endpoint_artifacts = endpoint_index["endpoints"]
            .as_object()
            .expect("endpoint index entries");
        assert_eq!(endpoint_artifacts.len(), 4);
        assert!(endpoint_artifacts.contains_key("GET /api/capabilities"));
        let run_index: Value =
            serde_json::from_slice(&fs::read(version.join("zero/runs-index.json")).unwrap())
                .unwrap();
        let run_artifacts = run_index["runs"].as_object().expect("run index entries");
        assert_eq!(run_artifacts.len(), 2);
        let mutation_artifact_path = run_artifacts
            .get("mutation_addTodo")
            .and_then(Value::as_str)
            .expect("mutation artifact path");
        let migrations: Value =
            serde_json::from_slice(&fs::read(version.join("zero/migrations.json")).unwrap())
                .unwrap();
        assert_eq!(migrations["statements"].as_array().unwrap().len(), 1);
        for artifact_path in endpoint_artifacts.values().chain(run_artifacts.values()) {
            let artifact_path = artifact_path.as_str().expect("indexed artifact path");
            let artifact: Value =
                serde_json::from_slice(&fs::read(version.join(artifact_path)).unwrap()).unwrap();
            let bytecode_path = artifact["bytecodePath"].as_str().expect("bytecode path");
            assert!(version.join(bytecode_path).is_file(), "{bytecode_path}");
        }

        let no_capabilities = json!({
            "db": false,
            "fetch": false,
            "auth": false,
            "env": false,
            "realtime": false,
            "logging": false
        });
        let (status, body, events) =
            invoke_compiled_zero(&version, "GET /api/health", "GET", "/api/health", None);
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
            &version,
            "GET /api/capabilities",
            "GET",
            "/api/capabilities",
            None,
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
            invoke_compiled_zero(&version, "POST /api/items", "POST", "/api/items", None);
        assert_eq!(status, 201);
        assert_eq!(body["installed"], db_realtime_logging);
        assert_eq!(body["table"], "`zero_example_todos`");
        assert_eq!(body["realtime"]["ok"], true);
        assert_eq!(events.len(), 2);
        assert_eq!(events[0]["event"], "zero.realtime");
        assert_eq!(events[1]["event"], "zero.log");

        let (status, body, events) = invoke_compiled_zero(
            &version,
            "mutation_addTodo",
            "POST",
            "/__spacefast/zero/run",
            Some(mutation_artifact_path),
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
            let manifest = accept_blobs(&private, &[("index.html", b"home")]);
            let mut input = fixture_input(
                &private,
                manifest,
                json!({"mode":"website"}),
                json!({"serving":{"config":{}}}),
            );
            input.zero_endpoints = endpoints;
            let output = finalize_site(input, false);
            assert!(matches!(output, Err(FinalizeError::Invalid { .. })));
            assert!(!private.join("spaces/s/versions/v").exists());
        }
    }

    #[test]
    fn repeated_finalize_is_rust_owned_validated_and_canonical() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(&private, &[("index.html", b"home")]);
        let input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({"serving":{"config":{},"redirects_exact":{},"redirects_pattern":[],"headers_exact":{},"headers_pattern":[]}}),
        );

        let first = finalize_site(input.clone(), false).unwrap();
        let mut retry = input.clone();
        retry.generated_at = "2026-07-12T00:05:00Z".into();
        let repeated = finalize_site(retry, false).unwrap();
        assert_eq!(repeated.file_count, first.file_count);
        assert_eq!(repeated.diagnostics, first.diagnostics);
        // A replay reads the committed version back instead of running the
        // pipeline, so it reports no stage telemetry rather than a run of
        // zeroed stages.
        assert!(first.telemetry.is_some());
        assert_eq!(repeated.telemetry, None);
        let metadata: Value = serde_json::from_slice(
            &fs::read(private.join("spaces/s/versions/v/metadata.json")).unwrap(),
        )
        .unwrap();
        assert_eq!(
            finalized_catalog(&private)
                .paths
                .keys()
                .next()
                .map(String::as_str),
            Some("index.html")
        );
        assert_eq!(
            metadata.get("generatedAt"),
            Some(&json!("2026-07-12T00:00:00Z"))
        );

        let mut mismatched = input.clone();
        mismatched.body["serving"]["config"]["listing"] = json!(true);
        assert!(matches!(
            finalize_site(mismatched, false),
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
            finalize_site(zero, false),
            Err(FinalizeError::Invalid {
                code: "version_existing_mismatch",
                ..
            })
        ));

        fs::remove_file(private.join("spaces/s/versions/v/root.json")).unwrap();
        assert!(matches!(
            finalize_site(input, false),
            Err(FinalizeError::Invalid {
                code: "version_existing_invalid",
                ..
            })
        ));
    }

    /// Storage tiering unlinks a demoted file from the version tree and keeps
    /// its bytes in the cold store, so a published version is allowed to have
    /// fewer files on disk than in its metadata. A replay of that version must
    /// still answer idempotently rather than call it corrupt — and it answers
    /// without walking the tree at all, so a space with a hundred thousand
    /// unchanged files costs a replay nothing.
    #[test]
    fn a_replay_answers_for_a_version_whose_files_were_tiered_out_of_the_tree() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(
            &private,
            &[
                ("index.html", b"<p>home</p>"),
                ("big/asset.bin", b"cold bytes"),
            ],
        );
        let input = fixture_input(
            &private,
            manifest,
            json!({"title": "Tiered"}),
            json!({"serving":{"config":{}}}),
        );
        let published = finalize_site(input.clone(), false).unwrap();

        // What a demote does to a blob whose bytes moved to S3: the
        // local copy goes, the version keeps naming it.
        let sha = finalized_catalog(&private).paths["big/asset.bin"]
            .served
            .as_ref()
            .expect("a served asset")
            .sha256
            .clone();
        fs::remove_file(private.join(format!("spaces/s/blobs/{}/{sha}", &sha[..2]))).unwrap();

        let replayed = finalize_site(input, false).unwrap();
        assert_eq!(replayed.file_count, published.file_count);
        assert_eq!(replayed.diagnostics, published.diagnostics);
    }

    #[test]
    fn dry_run_finalizes_without_publishing_the_version() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let manifest = accept_blobs(&private, &[("index.html", b"home")]);
        let input = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        let output = finalize_site(input.clone(), true).unwrap();
        assert_eq!(output.file_count, 1);
        let versions = private.join("spaces/s/versions");
        assert!(!versions.join("v").exists());
        assert!(!versions.join(".v.rust-finalizing").exists());
        let published = finalize_site(input, false).unwrap();
        assert_eq!(published.file_count, 1);
        assert!(versions.join("v/root.json").is_file());
    }

    /// The catalog's visibility bit is the ONE answer three implementations
    /// used to reach independently — Rust `serving_paths`, PHP `generate.php`,
    /// and TypeScript `content-scanner.ts`. It is pinned here over an
    /// adversarial corpus, at the seam where the whole finalize decides it, so
    /// the deleted duplicates cannot come back by accident.
    #[test]
    fn the_catalog_visibility_bit_is_the_one_answer_for_an_adversarial_corpus() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"<h1>hi</h1>"),
                ("sf.jsonc", b"{}"),
                ("theme.json", b"{}"),
                ("_pages/404.html", b"<h1>gone</h1>"),
                ("docs/_layout.html", b"<html></html>"),
                (".hidden/secret.txt", b"nope"),
                (".well-known/security.txt", b"contact: x"),
                ("assets/app.js", b"console.log(1)"),
                ("assets/app.js.gz", b"gzipped"),
                ("standalone.gz", b"lonely"),
                ("_pagespeed/report.html", b"<p>ok</p>"),
                (
                    "__spacefast/functions/bundles/w/bundle.json",
                    b"{\"kind\":\"bundle\"}",
                ),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        let catalog = finalized_catalog(&private);

        let public: Vec<String> = catalog
            .paths
            .iter()
            .filter(|(_, entry)| entry.public)
            .map(|(path, _)| path.clone())
            .collect();
        assert_eq!(
            public,
            vec![
                ".well-known/security.txt".to_string(),
                "_pagespeed/report.html".into(),
                "assets/app.js".into(),
                "index.html".into(),
                "standalone.gz".into(),
            ]
        );
        // A private path resolves to nothing in the served view, so a
        // `view=served` read of one cannot reach bytes — while its source view
        // still names the object the publisher uploaded.
        for path in ["sf.jsonc", "_pages/404.html", ".hidden/secret.txt"] {
            assert!(catalog.paths[path].served.is_none(), "{path} serves");
            assert_eq!(catalog.paths[path].source.sha256.len(), 64);
        }
        assert_eq!(
            catalog.format, "spacefast.runtime.file-catalog.v1",
            "the catalog rides inside metadata.json so the blob collector sweeps its hashes"
        );
    }

    /// A path the content pipeline rewrote records BOTH identities: the byte
    /// the publisher uploaded and the byte the version serves. That is what
    /// `originalShas` held, folded into the path it belongs to and given the
    /// size and content type a `view=source` read has to answer with.
    #[test]
    fn the_catalog_separates_uploaded_bytes_from_served_bytes() {
        let source: &[u8] = b"<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->";
        let (_temp, private, output) = finalize_fixture(
            &[("page.html", source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"serving":{"config":{}}}),
        );
        output.unwrap();
        let catalog = finalized_catalog(&private);

        let page = &catalog.paths["page.html"];
        assert_eq!(page.source.sha256, sha256(source));
        assert_eq!(page.source.size, source.len() as u64);
        let served = page.served.as_ref().expect("a public page serves");
        assert_ne!(served.sha256, page.source.sha256);
        assert_eq!(served.content_type, page.source.content_type);
    }

    /// Finalize produces its own substituted bytes from resolved scopes: the
    /// base overwrite the version host serves, the per-channel variant the
    /// route table is compiled from, and the `_redirects` text the routing
    /// compiler reads. One grammar, one resolution pass — a variable resolves
    /// the same whether a template, a convention file, or a config rule spells
    /// it.
    #[test]
    fn finalize_substitutes_templates_conventions_and_variants_from_resolved_scopes() {
        let (_temp, private, output) = finalize_fixture(
            &[
                ("index.html", b"<h1>{{ vars.GREETING }}</h1>"),
                ("_redirects", b"/old /{{ vars.TARGET }} 301"),
                ("sf.jsonc", br#"{"templates":["index.html"]}"#),
            ],
            json!({"mode":"website"}),
            json!({
                "serving": {"config": {}},
                "variable_scopes": [{
                    "kind": "space",
                    "values": {
                        "GREETING": {"value": "hello", "channelValues": {"production": "howdy"}},
                        "TARGET": {"value": "resolved-target"}
                    }
                }],
                "channels": [{"name": "production", "route_name": "prod"}],
            }),
        );
        output.unwrap();
        assert_eq!(
            String::from_utf8(finalized_body(&private, "index.html")).unwrap(),
            "<h1>hello</h1>"
        );
        // The compiled serving artifact — not just the recorded text — carries
        // the resolved value, so the rule the visitor hits is the resolved one.
        let table = finalized_table(&private);
        assert!(table.contains("/resolved-target"), "{table}");
        assert!(!table.contains("vars.TARGET"), "{table}");

        let catalog = finalized_catalog(&private);
        assert_eq!(catalog.variants.keys().collect::<Vec<_>>(), vec!["prod"]);
        let variant = &catalog.variants["prod"]["index.html"];
        assert_eq!(variant.sha256, sha256(b"<h1>howdy</h1>"));
        assert_eq!(
            fs::read(private.join(format!(
                "spaces/s/blobs/{}/{}",
                &variant.sha256[..2],
                variant.sha256
            )))
            .unwrap(),
            b"<h1>howdy</h1>"
        );
        assert_eq!(catalog.template_paths, vec!["index.html".to_string()]);
    }

    /// A secret or unknown reference anywhere in the substitution set — a
    /// template, `_redirects`, or a config routing rule — fails the publish
    /// instead of publishing a partially substituted byte.
    #[test]
    fn an_unresolved_convention_variable_fails_the_publish() {
        let (_temp, _private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                ("_redirects", b"/old /{{ vars.MISSING }} 301"),
            ],
            json!({"mode":"website"}),
            json!({"serving":{"config":{}},"variable_scopes":[]}),
        );
        // The refusal is the publisher-facing artifact of this failure, so it
        // has to name the file, the line and the variable — that is what the
        // control plane renders onto the version.
        match output {
            Err(FinalizeError::Invalid {
                code: "template_substitution_failed",
                details: Some(details),
                ..
            }) => {
                assert_eq!(details["path"], json!("_redirects"));
                let diagnostic = &details["diagnostics"][0];
                assert_eq!(diagnostic["code"], json!("template_variable_unresolved"));
                assert_eq!(diagnostic["path"], json!("_redirects"));
                assert_eq!(diagnostic["details"]["variable"], json!("MISSING"));
                assert_eq!(diagnostic["details"]["line"], json!(1));
            }
            other => panic!("expected a located template_substitution_failed, got {other:?}"),
        }
    }

    /// The control plane keeps no second compiler, so what it stores about a
    /// version — its rule counts and which variables it resolved to what — has
    /// to be recoverable from the finalized version itself, on a replay as much
    /// as on the first call.
    #[test]
    fn finalize_records_its_compiled_routing_and_substitution_provenance() {
        let (_temp, _private, output) = finalize_fixture(
            &[
                ("index.html", b"home"),
                (
                    "_redirects",
                    b"/api/* https://api.example.com/:splat 200\n/old /new 301",
                ),
                ("_headers", b"/*\n  x-region: {{ vars.REGION }}"),
            ],
            json!({"mode":"website"}),
            json!({
                "serving": {"config": {}},
                "variable_scopes": [{
                    "kind": "space",
                    "values": {"REGION": {"value": "eu", "secret": false}},
                }],
            }),
        );
        let output = output.unwrap();
        let metadata = finalized_metadata(&_private);

        assert_eq!(metadata["routing"]["redirectRuleCount"], json!(2));
        assert_eq!(metadata["routing"]["headerRuleCount"], json!(1));
        assert_eq!(metadata["routing"]["proxyRuleCount"], json!(1));
        assert_eq!(
            metadata["routing"]["proxyRules"],
            json!([{"source": "/api/*", "destination": "https://api.example.com/:splat"}]),
        );
        assert_eq!(
            metadata["variableDigests"],
            json!({"REGION": sha256(b"eu")})
        );
        assert_eq!(metadata["systemVariableDependencies"], json!([]));
        assert_eq!(
            metadata[CATALOG_DIGESTS_METADATA_KEY],
            serde_json::to_value(output.catalog_digests.unwrap()).unwrap(),
        );
    }

    /// The three integers the changelog renders and the request paths the edge
    /// purge takes, computed from two catalogs on this site — and answered
    /// again, unchanged, when the finalize is replayed.
    #[test]
    fn finalize_reports_the_catalog_delta_against_the_previous_version() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let body = json!({"serving":{"config":{},"state_digest":"policy-1"}});
        finalize_site(
            fixture_input(
                &private,
                accept_blobs(
                    &private,
                    &[("index.html", b"<p>one</p>"), ("gone.css", b"body{}")],
                ),
                json!({"mode":"website"}),
                body.clone(),
            ),
            false,
        )
        .unwrap();

        let manifest = accept_blobs(
            &private,
            &[("index.html", b"<p>two</p>"), ("new.css", b"main{}")],
        );
        let mut next = fixture_input(
            &private,
            manifest,
            json!({"mode":"website"}),
            json!({
                "serving": {"config": {}, "state_digest": "policy-1"},
                "previous_version_id": "v",
            }),
        );
        next.version_id = "v2".into();
        next.upload_id = Some("u2".into());

        let published = finalize_site(next.clone(), false).unwrap();
        let delta = published.delta.clone().expect("a named previous version");
        assert_eq!((delta.added, delta.changed, delta.removed), (1, 1, 1));
        assert_eq!(
            delta.changed_paths,
            Some(vec![
                "/".to_string(),
                "/gone.css".into(),
                "/index".into(),
                "/index.html".into(),
                "/new.css".into(),
            ])
        );
        assert!(published.catalog_digests.is_some());

        let replayed = finalize_site(next, false).unwrap();
        assert_eq!(replayed.delta, published.delta);
        assert_eq!(replayed.catalog_digests, published.catalog_digests);
    }

    /// Routing, serving config, or the control plane's own serving state moving
    /// means a URL can answer differently with every file untouched. The counts
    /// still describe the file set; the purge stops being scopeable.
    #[test]
    fn a_changed_serving_state_forces_a_host_wide_purge() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        finalize_site(
            fixture_input(
                &private,
                accept_blobs(&private, &[("index.html", b"<p>one</p>")]),
                json!({"mode":"website"}),
                json!({"serving":{"config":{},"state_digest":"policy-1"}}),
            ),
            false,
        )
        .unwrap();

        let mut next = fixture_input(
            &private,
            accept_blobs(&private, &[("index.html", b"<p>two</p>")]),
            json!({"mode":"website"}),
            json!({
                "serving": {"config": {}, "state_digest": "policy-2"},
                "previous_version_id": "v",
            }),
        );
        next.version_id = "v2".into();
        next.upload_id = Some("u2".into());

        let delta = finalize_site(next, false).unwrap().delta.expect("a delta");
        assert_eq!((delta.added, delta.changed, delta.removed), (0, 1, 0));
        assert_eq!(delta.changed_paths, None);
    }

    /// The published catalog of any version on the fixture site.
    fn catalog_at(private: &Path, version: &str) -> FileCatalog {
        read_version_catalog(&private.join(format!("spaces/s/versions/{version}")))
            .unwrap()
            .expect("every published version carries a catalog")
    }

    /// A served body from any version, read the way the serve path reaches it.
    fn served_body(private: &Path, version: &str, path: &str) -> Vec<u8> {
        let sha = catalog_at(private, version)
            .paths
            .get(path)
            .and_then(|entry| entry.served.clone())
            .unwrap_or_else(|| panic!("{path} is not published in {version}"))
            .sha256;
        fs::read(private.join(format!("spaces/s/blobs/{}/{sha}", &sha[..2]))).unwrap()
    }

    /// The headline of the incremental path: a republish that changes one file
    /// adopts every other page's served identity verbatim — same catalog entry,
    /// same CAS object, no decoration work — while the changed file takes the
    /// full path and the delta scopes the purge to it.
    #[test]
    fn a_republish_adopts_unchanged_pages_and_reworks_only_the_changed_file() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let stable = b"<html><head></head><body><p>stable</p></body></html>" as &[u8];
        let metadata = json!({"mode":"website","title":"Adopt"});
        finalize_site(
            fixture_input(
                &private,
                accept_blobs(
                    &private,
                    &[
                        ("index.html", stable),
                        (
                            "about.html",
                            b"<html><head></head><body><p>one</p></body></html>",
                        ),
                        ("style.css", b"body{}"),
                    ],
                ),
                metadata.clone(),
                json!({"serving":{"config":{}}}),
            ),
            false,
        )
        .unwrap();

        let mut next = fixture_input(
            &private,
            accept_blobs(
                &private,
                &[
                    ("index.html", stable),
                    (
                        "about.html",
                        b"<html><head></head><body><p>two</p></body></html>",
                    ),
                    ("style.css", b"body{}"),
                ],
            ),
            metadata,
            json!({"serving":{"config":{}},"previous_version_id":"v"}),
        );
        next.version_id = "v2".into();
        next.upload_id = Some("u2".into());
        let output = finalize_site(next, false).unwrap();

        let telemetry = output.telemetry.as_ref().expect("a finalize that ran");
        assert_eq!(
            telemetry.skipped_files, 1,
            "index.html adopts; about.html changed; style.css is no decoration target: {telemetry:?}"
        );
        assert_eq!(
            catalog_at(&private, "v").paths["index.html"],
            catalog_at(&private, "v2").paths["index.html"],
            "an adopted path carries the previous version's identities verbatim"
        );
        assert_eq!(
            served_body(&private, "v", "index.html"),
            served_body(&private, "v2", "index.html"),
        );
        let about = String::from_utf8(served_body(&private, "v2", "about.html")).unwrap();
        assert!(about.contains("<p>two</p>"), "{about}");
        let changed = output
            .delta
            .expect("a named previous version")
            .changed_paths
            .expect("a scoped purge");
        assert!(
            changed.iter().any(|path| path == "/about.html"),
            "{changed:?}"
        );
        assert!(
            !changed.iter().any(|path| path == "/index.html"),
            "{changed:?}"
        );
    }

    /// The stale-page guard, behaviorally: a decoration input that changes with
    /// every file byte untouched must disable adoption AND show up in the
    /// republished bytes.
    #[test]
    fn a_changed_decoration_context_republishes_the_untouched_page() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let page = b"<html><head></head><body><p>stable</p></body></html>" as &[u8];
        let metadata = json!({"mode":"website","title":"Adopt"});
        finalize_site(
            fixture_input(
                &private,
                accept_blobs(&private, &[("index.html", page)]),
                metadata.clone(),
                json!({"serving":{"config":{}}}),
            ),
            false,
        )
        .unwrap();

        let mut next = fixture_input(
            &private,
            accept_blobs(&private, &[("index.html", page)]),
            metadata,
            json!({
                "serving": {"config": {"inject": {"head": ["<meta name=\"stale-guard\" content=\"1\">"]}}},
                "previous_version_id": "v",
            }),
        );
        next.version_id = "v2".into();
        next.upload_id = Some("u2".into());
        let output = finalize_site(next, false).unwrap();

        assert_eq!(output.telemetry.as_ref().unwrap().skipped_files, 0);
        let body = String::from_utf8(served_body(&private, "v2", "index.html")).unwrap();
        assert!(body.contains("stale-guard"), "{body}");
    }

    /// Every remaining context input the digest folds: flipping any one of them
    /// alone must drop adoption to zero. Each scenario pins one input — a
    /// digest that stopped reading it would ship a stale page.
    #[test]
    fn each_decoration_context_input_flip_disables_adoption() {
        let page: &'static [u8] = b"<html><head></head><body><p>stable</p></body></html>";
        struct ContextFlip {
            name: &'static str,
            first_files: Vec<(&'static str, &'static [u8])>,
            next_files: Vec<(&'static str, &'static [u8])>,
            first_meta: Value,
            next_meta: Value,
            first_body: Value,
            next_body: Value,
        }
        let scenarios = [
            ContextFlip {
                name: "site title feeds the generated favicon",
                first_files: vec![("index.html", page)],
                next_files: vec![("index.html", page)],
                first_meta: json!({"mode":"website","title":"One"}),
                next_meta: json!({"mode":"website","title":"Two"}),
                first_body: json!({"serving":{"config":{}}}),
                next_body: json!({"serving":{"config":{}}}),
            },
            ContextFlip {
                name: "a shipped favicon.ico stands the placeholder down",
                first_files: vec![("index.html", page)],
                next_files: vec![("index.html", page), ("favicon.ico", b"real icon")],
                first_meta: json!({"mode":"website","title":"Adopt"}),
                next_meta: json!({"mode":"website","title":"Adopt"}),
                first_body: json!({"serving":{"config":{}}}),
                next_body: json!({"serving":{"config":{}}}),
            },
            ContextFlip {
                name: "a configured image's bytes ride the cache-busted URL",
                first_files: vec![("index.html", page), ("logo.png", b"logo-a")],
                next_files: vec![("index.html", page), ("logo.png", b"logo-b")],
                first_meta: json!({"mode":"website","title":"Adopt"}),
                next_meta: json!({"mode":"website","title":"Adopt"}),
                first_body: json!({"serving":{"config":{"platform_meta":true,"meta":{"image":"/logo.png"}}}}),
                next_body: json!({"serving":{"config":{"platform_meta":true,"meta":{"image":"/logo.png"}}}}),
            },
        ];
        for ContextFlip {
            name,
            first_files,
            next_files,
            first_meta,
            next_meta,
            first_body,
            next_body,
        } in &scenarios
        {
            let temp = tempdir().unwrap();
            let private = temp.path().join(".stattic/storage");
            finalize_site(
                fixture_input(
                    &private,
                    accept_blobs(&private, first_files),
                    first_meta.clone(),
                    first_body.clone(),
                ),
                false,
            )
            .unwrap();
            let mut body = next_body.clone();
            body.as_object_mut()
                .unwrap()
                .insert("previous_version_id".into(), json!("v"));
            let mut next = fixture_input(
                &private,
                accept_blobs(&private, next_files),
                next_meta.clone(),
                body,
            );
            next.version_id = "v2".into();
            next.upload_id = Some("u2".into());
            let output = finalize_site(next, false).unwrap();
            assert_eq!(
                output.telemetry.as_ref().unwrap().skipped_files,
                0,
                "{name}"
            );
        }
    }

    /// A missing CAS object fails adoption closed: the page takes the full
    /// path and its served bytes come back into existence.
    #[test]
    fn a_missing_served_blob_declines_adoption_and_reinstalls() {
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let page = b"<html><head></head><body><p>stable</p></body></html>" as &[u8];
        let metadata = json!({"mode":"website","title":"Adopt"});
        finalize_site(
            fixture_input(
                &private,
                accept_blobs(&private, &[("index.html", page)]),
                metadata.clone(),
                json!({"serving":{"config":{}}}),
            ),
            false,
        )
        .unwrap();
        let served = catalog_at(&private, "v").paths["index.html"]
            .served
            .clone()
            .expect("a public page")
            .sha256;
        fs::remove_file(private.join(format!("spaces/s/blobs/{}/{served}", &served[..2]))).unwrap();

        let mut next = fixture_input(
            &private,
            accept_blobs(&private, &[("index.html", page)]),
            metadata,
            json!({"serving":{"config":{}},"previous_version_id":"v"}),
        );
        next.version_id = "v2".into();
        next.upload_id = Some("u2".into());
        let output = finalize_site(next, false).unwrap();

        assert_eq!(output.telemetry.as_ref().unwrap().skipped_files, 0);
        assert_eq!(
            served_body(&private, "v2", "index.html"),
            served_body(&private, "v", "index.html")
        );
    }
}
