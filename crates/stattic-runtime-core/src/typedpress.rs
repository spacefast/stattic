//! TypedPress builds use the existing private, immutable version lifecycle.
use crate::{
    finalize::{invalid, invalid_error, validate_id, FileMeta, Result},
    model::SiteFinalizeInput,
    zero_v2::Compiled,
};
use serde_json::Value;
use std::{collections::BTreeMap, fs, path::Path};

pub(crate) fn declared(path: &str) -> bool {
    path == "typedpress.config.ts" || path.starts_with(".typedpress-source/")
}

fn copy_tree(from: &Path, to: &Path) -> std::io::Result<()> {
    fs::create_dir_all(to)?;
    for entry in fs::read_dir(from)? {
        let entry = entry?;
        if entry.file_type()?.is_dir() {
            copy_tree(&entry.path(), &to.join(entry.file_name()))?;
        } else {
            fs::copy(entry.path(), to.join(entry.file_name()))?;
        }
    }
    Ok(())
}

pub(crate) fn compile(
    input: &SiteFinalizeInput,
    private: &Path,
    stage: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<Option<Compiled>> {
    if !files.keys().any(|p| declared(p)) {
        return Ok(None);
    }
    if files
        .keys()
        .any(|p| p == "zero.config.ts" || p.starts_with(".zero-source/"))
    {
        return invalid(
            "typedpress_mixed_sources",
            "Publish one framework's source at a time. Use a fresh Space for TypedPress.",
        );
    }
    let captured = files.keys().any(|p| p.starts_with(".typedpress-source/"));
    if captured && files.contains_key("typedpress.config.ts") {
        return invalid(
            "typedpress_mixed_sources",
            "Publish either the TypedPress project or its captured bundle. If retention carried forward typedpress.config.ts, use retention none or a list that excludes the old project.",
        );
    }
    for path in files.keys() {
        let lower = path.to_ascii_lowercase();
        if lower.ends_with(".php")
            || lower.starts_with(".zero-compiled/")
            || lower.starts_with(".typedpress-compiled/")
        {
            return invalid("typedpress_reserved_output", "TypedPress accepts source only; PHP and compiled output are reserved for the host.");
        }
        if path.starts_with(".typedpress-source/")
            && !matches!(
                path.as_str(),
                ".typedpress-source/app.typedpress.json"
                    | ".typedpress-source/vendor.typedpress.json"
                    | ".typedpress-source/manifest.json"
            )
        {
            return invalid(
                "typedpress_source_layout",
                "TypedPress requires an app bundle, a vendor bundle and a manifest.",
            );
        }
    }
    let failure = |e: std::io::Error| invalid_error("typedpress_build_io", e.to_string());
    let output = stage.join(".zero-compiled");
    let store = output.join("store");
    fs::create_dir_all(&store).map_err(failure)?;
    let sources = stage.join("files/.typedpress-source");
    if !captured {
        typedpress::host::sources(&stage.join("files"), &sources)
            .map_err(|e| invalid_error("typedpress_capture_failed", e.message))?;
    }
    let mut previous_build = None;
    if let Some(previous) = input.body.get("zero_v2_previous").and_then(Value::as_str) {
        validate_id(previous, "zero_v2_previous")?;
        let old = private
            .join("spaces")
            .join(&input.space_id)
            .join("versions")
            .join(previous)
            .join(".zero-compiled");
        let manifest: Value =
            serde_json::from_slice(&fs::read(old.join("manifest.json")).map_err(failure)?)
                .map_err(|e| invalid_error("typedpress_baseline_invalid", e.to_string()))?;
        let build = manifest
            .get("build")
            .and_then(Value::as_str)
            .filter(|s| typedpress::store::valid_id(s))
            .ok_or_else(|| {
                invalid_error(
                    "typedpress_migration_required",
                    "Use a fresh Space; this baseline is not a TypedPress build.",
                )
            })?;
        let old_store = old.join("store");
        let build_file = format!("build/{build}.json");
        let bytes = fs::read(old_store.join(&build_file)).map_err(failure)?;
        let baseline: typedpress::host::BuildManifest = serde_json::from_slice(&bytes)
            .map_err(|e| invalid_error("typedpress_baseline_invalid", e.to_string()))?;
        if !typedpress::store::valid_id(&baseline.app) {
            return invalid("typedpress_baseline_invalid", "Invalid baseline app hash.");
        }
        fs::create_dir_all(store.join("build")).map_err(failure)?;
        fs::write(store.join(build_file), bytes).map_err(failure)?;
        // Only trusted host-produced snapshot and migration metadata form the baseline.
        let app = store.join("app").join(&baseline.app);
        copy_tree(&old_store.join("app").join(&baseline.app), &app).map_err(failure)?;
        previous_build = Some(build.to_owned());
    }
    let build = typedpress::host::compile_to_store(
        &sources,
        &store,
        &stage.join("files"),
        previous_build.as_deref(),
    )
    .map_err(|e| invalid_error("typedpress_compile_failed", e.message))?;
    let app = store.join("app").join(&build.app_hash);
    let mut manifest: Value =
        serde_json::from_slice(&fs::read(app.join("manifest.json")).map_err(failure)?)
            .map_err(|e| invalid_error("typedpress_manifest_invalid", e.to_string()))?;
    if manifest["hostContract"] != 1 {
        return invalid(
            "typedpress_host_contract",
            "Unsupported TypedPress host contract.",
        );
    }
    let pin: Value = serde_json::from_str(include_str!("../../typedpress/SOURCE.json")).unwrap();
    manifest["compilerRevision"] = pin["revision"].clone();
    manifest["summary"]["clientPath"] = "/__zero/client.js".into();
    manifest["summary"]["actionPathPrefix"] = "/__tp/api/v1/actions/".into();
    manifest["build"] = build.build_id.into();
    manifest["runtimeRevision"] = build.runtime_revision.into();
    manifest["appHash"] = build.app_hash.into();
    fs::write(
        output.join("manifest.json"),
        serde_json::to_vec(&manifest).unwrap(),
    )
    .map_err(failure)?;
    if app.join("client").is_dir() {
        copy_tree(&app.join("client"), &output.join("client")).map_err(failure)?;
    }
    let mut private_paths: std::collections::BTreeSet<String> = files
        .keys()
        .filter(|p| !captured || p.starts_with(".typedpress-source/"))
        .cloned()
        .collect();
    if !captured {
        let assets: Vec<_> = files
            .iter()
            .filter_map(|(p, meta)| {
                p.strip_prefix("public/")
                    .map(|name| (name.to_owned(), meta.clone()))
            })
            .collect();
        for (name, meta) in assets {
            if name.starts_with('.') || name.starts_with("public/") {
                return invalid(
                    "typedpress_public_collision",
                    format!("public/{name} collides with a project or reserved path."),
                );
            }
            let target = stage.join("files").join(&name);
            if target.exists() {
                fs::remove_file(&target).map_err(failure)?;
            }
            private_paths.remove(&name);
            fs::create_dir_all(target.parent().unwrap()).map_err(failure)?;
            fs::copy(stage.join("files/public").join(&name), target).map_err(failure)?;
            files.insert(name, meta);
        }
    }
    Ok(Some(Compiled {
        manifest,
        private_paths,
    }))
}
