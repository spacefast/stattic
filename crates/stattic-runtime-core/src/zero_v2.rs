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
    let declared: Vec<String> = input
        .session
        .get("manifest")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter_map(|entry| entry.get("path").and_then(Value::as_str))
        .map(str::to_owned)
        .collect();
    let captured = match compiled_form(&declared, files.keys())? {
        None => return Ok(None),
        Some(form) => form == SourceForm::Captured,
    };
    if !captured {
        // A raw publish over a base that still carries a capture: the capture
        // is the older app, and the capture step below writes a fresh one.
        // The staged files are hardlinks into the CAS that earlier versions
        // still serve, so unlink them rather than write through them.
        let stale: Vec<String> = files
            .keys()
            .filter(|path| path.starts_with(".zero-source/"))
            .cloned()
            .collect();
        for path in stale {
            std::fs::remove_file(stage.join("files").join(&path))
                .map_err(|error| invalid_error("zero_v2_capture_failed", error.to_string()))?;
            files.remove(&path);
        }
        let directory = stage.join("files/.zero-source");
        if directory.is_dir() {
            std::fs::remove_dir_all(&directory)
                .map_err(|error| invalid_error("zero_v2_capture_failed", error.to_string()))?;
        }
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
    if !captured {
        let public: Vec<_> = files
            .iter()
            .filter_map(|(name, meta)| {
                name.strip_prefix("public/")
                    .map(|path| (path.to_owned(), meta.clone()))
            })
            .collect();
        for (name, meta) in public {
            if name.starts_with(".zero-source/") || name.starts_with(".zero-compiled/") {
                return invalid(
                    "zero_v2_source_layout",
                    format!("public/{name} would overwrite a directory the engine owns."),
                );
            }
            // A nested public/public/x would replace public/x before x is
            // copied from it, so x would serve bytes its catalog entry
            // doesn't describe.
            if name.starts_with("public/") {
                return invalid(
                    "zero_v2_source_layout",
                    format!("public/{name} nests public/ inside public/."),
                );
            }
            let destination = stage.join("files").join(&name);
            if files.contains_key(&name) {
                // In a First project the root is private source, so the
                // public asset wins. That covers both a static page the base
                // version served and the copy an earlier First version made.
                // The staged file is a hardlink into the CAS, which earlier
                // versions still serve. Unlink it; copying over it would
                // rewrite their bytes.
                std::fs::remove_file(&destination).map_err(|error| {
                    invalid_error("zero_v2_asset_write_failed", error.to_string())
                })?;
                files.remove(&name);
            }
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
        private_paths: private_source_paths(files, &proven_public(input)),
    }))
}

/// The form a v2 version's source takes. The control plane applies the same
/// rule (`packages/common/src/utils/zero-v2-source.ts`), and
/// `zero-v2-source.fixtures.json` pins both.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum SourceForm {
    /// `.zero-source/` bundles a client captured; assets sit at the root.
    Captured,
    /// The project itself: `zero.config.ts` and the files beside it, with
    /// assets under `public/` that finalize copies to the root.
    Raw,
    /// Both, which finalize refuses.
    Mixed,
}

pub(crate) fn source_form<'a>(paths: impl IntoIterator<Item = &'a String>) -> Option<SourceForm> {
    let (mut captured, mut raw) = (false, false);
    for path in paths {
        if path.starts_with(".zero-source/") {
            captured = true;
        } else if path == "zero.config.ts" {
            raw = true;
        }
    }
    match (captured, raw) {
        (true, true) => Some(SourceForm::Mixed),
        (true, false) => Some(SourceForm::Captured),
        (false, true) => Some(SourceForm::Raw),
        (false, false) => None,
    }
}

/// The form finalize compiles, from what this publish uploaded (`declared`)
/// and the whole tree it produced (`tree`, retained files included).
///
/// - An upload that itself carries both forms is refused: nothing says which
///   one the author meant.
/// - Otherwise a tree holding one form compiles it.
/// - A tree holding both compiles the form the upload declares. One that
///   declares neither (a config-only publish, a partial update, a refreshing
///   rollback) carries forward a version published before forms were
///   replaced; it compiles its capture, which is what that version served.
///   [`private_source_paths`] keeps such a tree's raw source private either way.
pub(crate) fn compiled_form<'a>(
    declared: &[String],
    tree: impl IntoIterator<Item = &'a String>,
) -> Result<Option<SourceForm>> {
    let declared = source_form(declared);
    if declared == Some(SourceForm::Mixed) {
        return invalid(
            "zero_v2_mixed_source_forms",
            "This publish uploads both a captured Zero source bundle (.zero-source/) and a raw project (zero.config.ts). Upload one of them: the project, or the bundle a current CLI captures from it.",
        );
    }
    Ok(match source_form(tree) {
        Some(SourceForm::Mixed) => Some(declared.unwrap_or(SourceForm::Captured)),
        form => form,
    })
}

/// Root files proven public for a tree that holds both forms: path -> sha256,
/// from the control plane's `serving.zero_v2_proven_public`. Absent proves
/// nothing.
pub(crate) fn proven_public(input: &SiteFinalizeInput) -> BTreeMap<String, String> {
    input
        .body
        .pointer("/serving/zero_v2_proven_public")
        .and_then(Value::as_object)
        .into_iter()
        .flatten()
        .filter_map(|(path, sha)| Some((path.clone(), sha.as_str()?.to_owned())))
        .collect()
}

/// The paths of a v2 tree that are source, never served. They depend only on
/// the tree and the proof, never on what one publish uploaded.
///
/// - A captured tree's source is its capture.
/// - A raw tree (after a raw compile copied each `public/<path>` to `<path>`)
///   is all source except those copies: `zero.config.ts`, every module it
///   reaches, data and package files, source maps, and `public/` itself.
/// - A tree that still holds both forms compiled its capture, so nothing was
///   copied. Everything is private except a root file proven public by
///   identity: its hash is the one `proven` names for its path (what the
///   clean captured ancestor served), or its `public/<path>` file's hash.
pub(crate) fn private_source_paths(
    files: &BTreeMap<String, FileMeta>,
    proven: &BTreeMap<String, String>,
) -> BTreeSet<String> {
    let form = source_form(files.keys());
    files
        .iter()
        .filter(|(path, meta)| {
            let twin = files.get(&format!("public/{path}"));
            match form {
                None => false,
                Some(SourceForm::Captured) => path.starts_with(".zero-source/"),
                Some(SourceForm::Raw) => twin.is_none(),
                Some(SourceForm::Mixed) => {
                    path.starts_with(".zero-source/")
                        || path.as_str() == "zero.config.ts"
                        || !(proven.get(*path) == Some(&meta.sha256)
                            || twin.is_some_and(|twin| twin.sha256 == meta.sha256))
                }
            }
        })
        .map(|(path, _)| path.clone())
        .collect()
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
        "clientPath": "/__zero/client.js",
    })
}

#[cfg(test)]
mod tests {
    use super::{compiled_form, private_source_paths, source_form, FileMeta, SourceForm};
    use serde_json::Value;
    use std::collections::BTreeMap;

    /// The control plane decides the same form and private set from the same
    /// fixtures (packages/common/src/utils/zero-v2-source.test.ts).
    #[test]
    fn source_form_and_private_paths_match_the_shared_fixtures() {
        let fixtures: Value = serde_json::from_str(include_str!(concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../../packages/common/src/utils/zero-v2-source.fixtures.json"
        )))
        .expect("the shared Zero v2 source fixtures must be valid JSON");
        let strings = |value: &Value| -> Vec<String> {
            value
                .as_array()
                .expect("a fixture path list")
                .iter()
                .map(|path| path.as_str().expect("a fixture path").to_owned())
                .collect()
        };
        for fixture in fixtures["classify"].as_array().expect("classify fixtures") {
            let name = fixture["name"].as_str().unwrap();
            let paths = strings(&fixture["paths"]);
            let form = source_form(&paths).map(|form| match form {
                SourceForm::Captured => "captured",
                SourceForm::Raw => "raw",
                SourceForm::Mixed => "mixed",
            });
            assert_eq!(form, fixture["form"].as_str(), "{name}");
            // A path's bytes are named by `bytes` when two paths share them,
            // else unique to the path.
            let files: BTreeMap<String, FileMeta> = paths
                .iter()
                .map(|path| {
                    let bytes = fixture["bytes"][path.as_str()].as_str().unwrap_or(path);
                    let meta = FileMeta {
                        size: 1,
                        mime: "text/plain".into(),
                        sha256: bytes.to_owned(),
                        mtime: 0,
                    };
                    (path.clone(), meta)
                })
                .collect();
            let proven: BTreeMap<String, String> = fixture["proven"]
                .as_object()
                .into_iter()
                .flatten()
                .map(|(path, bytes)| (path.clone(), bytes.as_str().unwrap().to_owned()))
                .collect();
            let private: Vec<String> = private_source_paths(&files, &proven).into_iter().collect();
            assert_eq!(private, strings(&fixture["private"]), "{name}");
        }
        for fixture in fixtures["compile"].as_array().expect("compile fixtures") {
            let name = fixture["name"].as_str().unwrap();
            let tree = strings(&fixture["tree"]);
            let compiled = match compiled_form(&strings(&fixture["declared"]), &tree) {
                Ok(None) => None,
                Ok(Some(SourceForm::Captured)) => Some("captured"),
                Ok(Some(SourceForm::Raw)) => Some("raw"),
                Ok(Some(SourceForm::Mixed)) => Some("mixed"),
                Err(_) => Some("refused"),
            };
            assert_eq!(compiled, fixture["compiles"].as_str(), "{name}");
        }
    }
}
