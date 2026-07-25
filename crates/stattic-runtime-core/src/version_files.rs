//! Version-scoped auxiliary files: embedded access/layout pages, template
//! variants, sharded file metadata, artifact presence validation, and the
//! convention-file / viewer metadata carried into `metadata.json`.

use serde_json::{json, Map, Value};
use std::collections::BTreeMap;
use std::fs;
use std::path::Path;

use crate::finalize::{
    artifact_metadata, file_meta, invalid, remove_any, sha256, validate_id, validate_relative_path,
    write_bytes, write_php, FileMeta, FinalizeError, Result,
};
use crate::protocol::{
    PAGE_MAX_BYTES, TEMPLATE_MAX_BYTES, TEMPLATE_VARIANT_FILE_LIMIT, TEMPLATE_VARIANT_ROUTE_LIMIT,
};
use crate::transforms::html::{
    validate_installed_page, AccessSlot, InstalledPageError, InstalledPageKind,
};

pub fn validate_embedded_page_inputs(body: &Value) -> Result<()> {
    if let Some(raw_artifacts) = body.get("page_artifacts") {
        let artifacts = raw_artifacts
            .as_object()
            .ok_or_else(|| FinalizeError::Invalid {
                code: "invalid_page_artifacts",
                message: "page_artifacts must be a bounded document map.".into(),
                details: None,
            })?;
        if artifacts.len() > 100_000 {
            return invalid(
                "invalid_page_artifacts",
                "page_artifacts must be a bounded document map.",
            );
        }
        for (key, value) in artifacts {
            let safe_key = !key.is_empty()
                && key.len() <= 240
                && key
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-');
            let content = value.as_str();
            if !safe_key || content.is_none_or(|content| content.len() > PAGE_MAX_BYTES) {
                return invalid(
                    "invalid_page_artifacts",
                    "Every page artifact needs a safe key and an HTML document up to 2 MiB.",
                );
            }
        }
    }
    if let Some(raw_pages) = body.get("access_pages") {
        let pages = raw_pages
            .as_object()
            .ok_or_else(|| FinalizeError::Invalid {
                code: "invalid_access_pages",
                message: "access_pages must be an object.".into(),
                details: None,
            })?;
        if pages
            .keys()
            .any(|key| !matches!(key.as_str(), "challenge" | "deny"))
        {
            return invalid(
                "invalid_access_pages",
                "access_pages supports only challenge and deny.",
            );
        }
        for (slot, kind) in [
            (
                "challenge",
                InstalledPageKind::Access(AccessSlot::Challenge),
            ),
            ("deny", InstalledPageKind::Access(AccessSlot::Deny)),
        ] {
            let Some(value) = pages.get(slot) else {
                continue;
            };
            let content = value.as_str().ok_or_else(|| FinalizeError::Invalid {
                code: "invalid_access_pages",
                message: format!("access_pages.{slot} must be a string."),
                details: None,
            })?;
            if let Err(error) = validate_installed_page(content, kind) {
                return match error {
                    InstalledPageError::TooLarge => invalid(
                        "invalid_access_pages",
                        format!("access_pages.{slot} exceeds 2 MiB."),
                    ),
                    InstalledPageError::MissingMarker => invalid(
                        "invalid_access_pages",
                        format!("The {slot} document is missing its baked slot marker."),
                    ),
                };
            }
        }
    }
    if let Some(value) = body.get("layout_template") {
        let content = value.as_str().ok_or_else(|| FinalizeError::Invalid {
            code: "invalid_layout_template",
            message: "layout_template must be a string.".into(),
            details: None,
        })?;
        if let Err(error) = validate_installed_page(content, InstalledPageKind::Layout) {
            return match error {
                InstalledPageError::TooLarge => {
                    invalid("invalid_layout_template", "layout_template exceeds 2 MiB.")
                }
                InstalledPageError::MissingMarker => invalid(
                    "invalid_layout_template",
                    "layout_template is missing its baked slot marker.",
                ),
            };
        }
    }
    Ok(())
}

pub fn apply_page_artifacts(body: &Value, stage_root: &Path) -> Result<()> {
    let root = stage_root.join("pages");
    remove_any(&root)?;
    let Some(raw_artifacts) = body.get("page_artifacts") else {
        return Ok(());
    };
    for (key, value) in raw_artifacts
        .as_object()
        .expect("page artifacts prevalidated")
    {
        write_bytes(
            &root.join(format!("{key}.html")),
            value
                .as_str()
                .expect("page artifact contents prevalidated")
                .as_bytes(),
        )?;
    }
    Ok(())
}

pub fn apply_access_pages(body: &Value, stage_root: &Path) -> Result<()> {
    let root = stage_root.join("access-pages");
    remove_any(&root)?;
    if let Some(raw_pages) = body.get("access_pages") {
        let pages = raw_pages.as_object().expect("embedded pages prevalidated");
        for (key, name) in [("challenge", "challenge.html"), ("deny", "deny.html")] {
            if let Some(value) = pages.get(key) {
                let content = value.as_str().expect("embedded page prevalidated");
                write_bytes(&root.join(name), content.as_bytes())?;
            }
        }
    }
    if let Some(value) = body.get("layout_template") {
        let content = value.as_str().expect("layout template prevalidated");
        write_bytes(&root.join("layout.html"), content.as_bytes())?;
    }
    Ok(())
}

pub fn apply_template_variants(
    body: &Value,
    stage_root: &Path,
    files: &BTreeMap<String, FileMeta>,
) -> Result<Map<String, Value>> {
    let mut routes = Map::new();
    let Some(raw_variants) = body.get("template_variants") else {
        return Ok(routes);
    };
    let variants = raw_variants
        .as_object()
        .ok_or_else(|| FinalizeError::Invalid {
            code: "invalid_template_variants",
            message: "template_variants must be an object.".into(),
            details: None,
        })?;
    if variants.len() > TEMPLATE_VARIANT_ROUTE_LIMIT {
        return invalid(
            "invalid_template_variants",
            "Too many template variant routes.",
        );
    }
    for (route, values) in variants {
        validate_id(route, "route_name")?;
        let values = values.as_object().ok_or_else(|| FinalizeError::Invalid {
            code: "invalid_template_variants",
            message: "Variant values must be objects.".into(),
            details: None,
        })?;
        if values.is_empty() || values.len() > TEMPLATE_VARIANT_FILE_LIMIT {
            return invalid(
                "invalid_template_variants",
                "Each template variant route requires 1 to 100 files.",
            );
        }
        let mut route_files = Map::new();
        for (path, content) in values {
            validate_relative_path(path)?;
            if !files.contains_key(path) {
                return invalid(
                    "template_not_in_version",
                    format!("Variant path {path} is not committed."),
                );
            }
            let content = content.as_str().ok_or_else(|| FinalizeError::Invalid {
                code: "invalid_template_variants",
                message: "Variant contents must be strings.".into(),
                details: None,
            })?;
            if content.len() > TEMPLATE_MAX_BYTES {
                return invalid(
                    "invalid_template_variants",
                    format!("Variant contents for {path} exceed 2 MiB."),
                );
            }
            let bytes = content.as_bytes();
            let relative = format!("files-variants/{route}/{path}");
            write_bytes(&stage_root.join(&relative), bytes)?;
            route_files.insert(path.clone(), json!(file_meta(path, bytes, None)));
        }
        routes.insert(route.clone(), Value::Object(route_files));
    }
    Ok(routes)
}

pub fn write_file_shards(
    root: &Path,
    files: &BTreeMap<String, FileMeta>,
    generated_at: &str,
) -> Result<()> {
    let shard_root = root.join("file-shards");
    remove_any(&shard_root)?;
    let mut shards = BTreeMap::<String, Map<String, Value>>::new();
    for (path, meta) in files {
        let shard = &sha256(path.as_bytes())[..2];
        let entry = shards.entry(shard.into()).or_default();
        entry.insert(
            path.clone(),
            serde_json::to_value(meta).unwrap_or(Value::Null),
        );
    }
    for (shard, values) in shards {
        let mut root_value = artifact_metadata(generated_at);
        root_value.insert("files".into(), Value::Object(values));
        write_php(
            &shard_root.join(format!("{shard}.php")),
            &Value::Object(root_value),
        )?;
    }
    Ok(())
}

// stage-2b: zero artifact presence (`zero/config.json`, endpoint/run indexes)
// is validated by the trunk zero writer (`validate_zero_artifacts`); the lead
// wires that in alongside this check in the finalize orchestration.
pub fn validate_artifacts(root: &Path, files: &BTreeMap<String, FileMeta>) -> Result<()> {
    for name in [
        "metadata.json",
        "serving.php",
        "php-manifest.php",
        "headers.php",
        "redirects.php",
    ] {
        if !root.join(name).is_file() {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("Missing {name}."),
            );
        }
    }
    for path in files.keys() {
        if !root.join("files").join(path).is_file() {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("Missing committed file {path}."),
            );
        }
    }
    Ok(())
}

/// Resolves the convention files recorded in immutable version metadata from
/// the session and body payloads plus any committed `.stattic/routes.json`.
pub fn convention_files(
    session: &Value,
    body: &Value,
    stage_root: &Path,
    precompiled: bool,
) -> Result<Value> {
    // A precompiled body is authoritative, including the empty object. This
    // prevents stale session convention text (and historical plaintext
    // Basic-Auth operations) from crossing into immutable metadata.
    let mut result = if precompiled {
        Map::new()
    } else {
        session
            .get("convention_files")
            .and_then(Value::as_object)
            .cloned()
            .unwrap_or_default()
    };
    if let Some(body) = body.get("convention_files").and_then(Value::as_object) {
        for (key, value) in body {
            result.insert(key.clone(), value.clone());
        }
    }
    let routes = stage_root.join("files/.stattic/routes.json");
    if routes.is_file() {
        result.insert(
            "routes".into(),
            Value::String(
                fs::read_to_string(&routes).map_err(|source| FinalizeError::Io {
                    path: routes,
                    source,
                })?,
            ),
        );
    }
    Ok(Value::Object(result))
}

pub fn resolved_viewer(
    metadata: &Map<String, Value>,
    config: &Map<String, Value>,
) -> Map<String, Value> {
    let mut viewer = metadata
        .get("viewer")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    if let Some(meta) = config.get("meta").and_then(Value::as_object) {
        viewer.insert(
            "title".into(),
            meta.get("title").cloned().unwrap_or(Value::Null),
        );
        viewer.insert(
            "description".into(),
            meta.get("description").cloned().unwrap_or(Value::Null),
        );
        viewer.insert(
            "og_image_path".into(),
            meta.get("image").cloned().unwrap_or(Value::Null),
        );
    }
    viewer
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::tempdir;

    #[test]
    fn template_variant_metadata_is_directly_servable() {
        let temp = tempdir().unwrap();
        let mut files = BTreeMap::new();
        files.insert(
            "config.js".to_string(),
            file_meta("config.js", b"base", None),
        );
        let routes = apply_template_variants(
            &json!({"template_variants":{"production":{"config.js":"variant"}}}),
            temp.path(),
            &files,
        )
        .unwrap();
        let meta = routes
            .get("production")
            .and_then(Value::as_object)
            .and_then(|files| files.get("config.js"))
            .unwrap();

        assert_eq!(meta.get("disk_path"), Some(&json!("config.js")));
        assert_eq!(
            meta.pointer("/headers/Content-Type"),
            Some(&json!("text/javascript; charset=utf-8"))
        );
        assert!(meta.pointer("/headers/ETag").is_some());
        assert!(temp
            .path()
            .join("files-variants/production/config.js")
            .is_file());
    }

    // Adapted from the donor's finalize-level
    // `finalize_rejects_invalid_access_and_variant_shapes`: the validators are
    // exercised directly; the rejected shapes and codes are donor-verbatim.
    #[test]
    fn embedded_page_and_variant_shapes_are_rejected() {
        for (body, code) in [
            (
                json!({"access_pages":{"other":"x"}}),
                "invalid_access_pages",
            ),
            (json!({"layout_template":false}), "invalid_layout_template"),
        ] {
            assert!(matches!(
                validate_embedded_page_inputs(&body),
                Err(FinalizeError::Invalid { code: actual, .. }) if actual == code
            ));
        }
        assert!(matches!(
            validate_embedded_page_inputs(&json!({"page_artifacts":{"Unsafe/Key":"x"}})),
            Err(FinalizeError::Invalid {
                code: "invalid_page_artifacts",
                ..
            })
        ));
        let pages = tempdir().unwrap();
        let body = json!({"page_artifacts":{"page-home":"<h1>Home</h1>"}});
        validate_embedded_page_inputs(&body).unwrap();
        apply_page_artifacts(&body, pages.path()).unwrap();
        assert_eq!(
            fs::read_to_string(pages.path().join("pages/page-home.html")).unwrap(),
            "<h1>Home</h1>"
        );
        let temp = tempdir().unwrap();
        assert!(matches!(
            apply_template_variants(
                &json!({"template_variants":{"production":{}}}),
                temp.path(),
                &BTreeMap::new(),
            ),
            Err(FinalizeError::Invalid {
                code: "invalid_template_variants",
                ..
            })
        ));
    }

    // Adapted from the donor's finalize-level
    // `precompiled_conventions_use_body_buckets_and_drop_stale_session_credentials`
    // (first half): a precompiled body drops stale session convention text so
    // plaintext Basic-Auth never reaches immutable metadata. The finalize half
    // (body redirect buckets winning in redirects.php) needs the orchestration
    // stage.
    #[test]
    fn precompiled_conventions_drop_stale_session_credentials() {
        let temp = tempdir().unwrap();
        let stage = temp.path().join("stage");
        fs::create_dir_all(stage.join("files")).unwrap();
        let session = json!({
            "convention_files": {
                "headers": "/*\n  Basic-Auth: admin:correct-horse"
            }
        });
        let body = json!({
            "convention_files": {"redirects": "/raw /wrong 301"},
            "convention_files_precompiled": true
        });
        let metadata_conventions = convention_files(&session, &body, &stage, true).unwrap();
        let serialized = serde_json::to_string(&metadata_conventions).unwrap();
        assert!(!serialized.contains("correct-horse"));
        assert!(!serialized.to_ascii_lowercase().contains("basic-auth"));
    }
}
