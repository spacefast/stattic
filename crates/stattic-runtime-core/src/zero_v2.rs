use crate::{
    finalize::{invalid, invalid_error, validate_id, FileMeta, Result},
    model::SiteFinalizeInput,
};
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    path::Path,
};

pub(crate) struct Compiled {
    pub manifest: Value,
    pub private_paths: BTreeSet<String>,
}

pub(crate) fn compile(
    input: &SiteFinalizeInput,
    private: &Path,
    stage: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<Option<Compiled>> {
    for path in files.keys() {
        let lower = path.to_ascii_lowercase();
        if lower == ".zero-compiled" || lower.starts_with(".zero-compiled/") {
            return invalid(
                "zero_v2_output_uploaded",
                "The compiled Zero directory is reserved for the engine.",
            );
        }
    }
    let captured = files.keys().any(|p| p.starts_with(".zero-source/"));
    let v2 = captured || files.contains_key("zero.config.ts");
    if !v2 {
        return Ok(None);
    }
    if files.keys().any(|p| {
        p.starts_with(".zero-source/")
            && !matches!(
                p.as_str(),
                ".zero-source/app.zero.json"
                    | ".zero-source/vendor.zero.json"
                    | ".zero-source/manifest.json"
            )
    }) {
        return invalid(
            "zero_v2_source_layout",
            "Zero v2 requires one app bundle, one vendor bundle and a manifest.",
        );
    }
    if files
        .keys()
        .any(|p| p.to_ascii_lowercase().ends_with(".php"))
    {
        return invalid(
            "zero_v2_php_uploaded",
            "Zero v2 accepts source only; uploaded PHP is forbidden.",
        );
    }
    let previous = input
        .body
        .get("zero_v2_previous")
        .and_then(Value::as_str)
        .map(|id| {
            validate_id(id, "zero_v2_previous")?;
            Ok(private
                .join("spaces")
                .join(&input.space_id)
                .join("versions")
                .join(id)
                .join(".zero-compiled"))
        })
        .transpose()?;
    let pin: Value = serde_json::from_str(include_str!("../../zero/SOURCE.json"))
        .expect("checked-in compiler source");
    let sources = stage.join("files/.zero-source");
    if !captured {
        zero::host::sources(&stage.join("files"), &sources).map_err(|error| {
            let code = if error.code == zero::diag::Code::SourceNeedsLocalCapture {
                "zero_source_needs_local_capture"
            } else {
                "zero_v2_capture_failed"
            };
            invalid_error(code, error.message)
        })?;
    }
    zero::host::compile_sources(
        &sources,
        &stage.join(".zero-compiled"),
        &stage.join("files"),
        previous.as_deref(),
    )
    .map_err(|error| invalid_error("zero_v2_compile_failed", error.message))?;
    let mut manifest: Value = serde_json::from_slice(
        &std::fs::read(stage.join(".zero-compiled/manifest.json"))
            .map_err(|e| invalid_error("zero_v2_manifest_read_failed", e.to_string()))?,
    )
    .map_err(|e| invalid_error("zero_v2_manifest_invalid", e.to_string()))?;
    if manifest["compiler"] != pin["version"] {
        return invalid(
            "zero_v2_compiler_mismatch",
            "The server compiler differs from the engine pin.",
        );
    }
    manifest["compilerRevision"] = pin["revision"].clone();
    manifest["summary"] = app_summary(&stage.join(".zero-compiled"), &manifest);
    std::fs::write(
        stage.join(".zero-compiled/manifest.json"),
        serde_json::to_vec(&manifest).unwrap(),
    )
    .map_err(|error| invalid_error("zero_v2_manifest_write_failed", error.to_string()))?;
    let mut private_paths = BTreeSet::new();
    if !captured {
        private_paths.extend(files.keys().cloned());
        let public: Vec<_> = files
            .iter()
            .filter_map(|(name, meta)| {
                name.strip_prefix("public/")
                    .map(|path| (path.to_owned(), meta.clone()))
            })
            .collect();
        for (name, meta) in public {
            if files.contains_key(&name)
                || name.starts_with(".zero-source/")
                || name.starts_with(".zero-compiled/")
            {
                return invalid(
                    "zero_v2_source_layout",
                    "A public asset conflicts with the hosted project layout.",
                );
            }
            let destination = stage.join("files").join(&name);
            std::fs::create_dir_all(destination.parent().unwrap())
                .and_then(|()| {
                    std::fs::copy(stage.join("files/public").join(&name), &destination).map(|_| ())
                })
                .map_err(|error| invalid_error("zero_v2_asset_write_failed", error.to_string()))?;
            files.insert(name, meta);
        }
    }
    Ok(Some(Compiled {
        manifest,
        private_paths,
    }))
}

/// What the control plane shows for a v2 app: its collections and their
/// tables, its actions, and its generated client. Read from the compiled
/// `client/schema.ts` descriptor and `snapshot.json`, never from source.
fn app_summary(compiled: &Path, manifest: &Value) -> Value {
    let descriptor = std::fs::read_to_string(compiled.join("client/schema.ts"))
        .ok()
        .and_then(|text| {
            let start = text.find("export const SCHEMA = ")? + "export const SCHEMA = ".len();
            let end = text[start..].find(" as const;")? + start;
            serde_json::from_str::<Value>(&text[start..end]).ok()
        })
        .unwrap_or(Value::Null);
    let tables: BTreeMap<String, Value> = std::fs::read(compiled.join("snapshot.json"))
        .ok()
        .and_then(|bytes| serde_json::from_slice::<Value>(&bytes).ok())
        .and_then(|snapshot| snapshot.get("tables").and_then(Value::as_object).cloned())
        .map(|tables| tables.into_iter().collect())
        .unwrap_or_default();
    let mut collections: Vec<&String> = descriptor
        .get("collections")
        .and_then(Value::as_object)
        .map(|map| map.keys().collect())
        .unwrap_or_default();
    collections.sort();
    let mut actions: Vec<String> = descriptor
        .get("actions")
        .and_then(Value::as_array)
        .map(|items| {
            items
                .iter()
                .filter_map(Value::as_str)
                .map(str::to_owned)
                .collect()
        })
        .unwrap_or_default();
    actions.sort();
    serde_json::json!({
        "name": manifest.get("app").cloned().unwrap_or(Value::Null),
        "collections": collections,
        "tables": tables.keys().collect::<Vec<_>>(),
        "actions": actions,
        "clientPath": "/__spacefast/client.js",
    })
}
