//! Version-scoped auxiliary files: embedded access/layout pages, artifact
//! presence validation, and the convention-file / viewer metadata carried into
//! `metadata.json`.

use serde_json::{Map, Value};
use std::collections::BTreeMap;
use std::fs;
use std::path::Path;

use crate::catalog::read_version_catalog;
use crate::finalize::{invalid, remove_any, write_bytes, FinalizeError, Result};
use crate::protocol::{PAGE_MAX_BYTES, VERSION_ROOT_POINTER_FILE};
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

/// Zero artifact presence is validated by the trunk zero writer
/// (`validate_zero_artifacts`); this is the schema-v4 version surface.
///
/// There is no file tree to walk any more: a version is its root
/// pointer, the root it names, and the tables the root names. The bytes live in
/// the CAS, and a demoted blob is legitimately absent from local disk, so
/// "every committed path exists here" stopped being a truth a publish could
/// assert.
pub fn validate_artifacts(root: &Path, published: &PublishedArtifacts<'_>) -> Result<()> {
    for name in ["metadata.json", VERSION_ROOT_POINTER_FILE] {
        if !root.join(name).is_file() {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("Missing {name}."),
            );
        }
    }
    // The catalog is load-bearing, not a projection: the resolver, the list
    // route and the finalize receipt all read it and none of them has a
    // fallback. A version published without one answers nothing.
    if read_version_catalog(root)?.is_none() {
        return invalid(
            "runtime_artifact_validation_failed",
            "metadata.json embeds no file catalog.",
        );
    }
    if !root.join(published.root_file).is_file() {
        return invalid(
            "runtime_artifact_validation_failed",
            format!("Missing {}.", published.root_file),
        );
    }
    if published.tables.is_empty() {
        return invalid(
            "runtime_artifact_validation_failed",
            "The version root names no response table.",
        );
    }
    for table in published.tables.values() {
        if !root.join(table).is_file() {
            return invalid(
                "runtime_artifact_validation_failed",
                format!("Missing response table {table}."),
            );
        }
    }
    Ok(())
}

/// What a finalize published, for the presence check above.
pub struct PublishedArtifacts<'a> {
    pub root_file: &'a str,
    pub tables: &'a BTreeMap<String, String>,
}

/// The convention text this version records in immutable metadata beyond
/// `_redirects`/`_headers` — those two are staged files the finalizer reads and
/// substitutes for itself. `.stattic/routes.json` is committed, never sent.
pub fn convention_files(stage_root: &Path) -> Result<Map<String, Value>> {
    let mut result = Map::new();
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
    Ok(result)
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
    use serde_json::json;
    use tempfile::tempdir;

    // Adapted from the donor's finalize-level
    // `finalize_rejects_invalid_access_and_variant_shapes`: the validators are
    // exercised directly; the rejected shapes and codes are donor-verbatim.
    #[test]
    fn embedded_page_shapes_are_rejected() {
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
    }
}
