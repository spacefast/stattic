//! Unified SpacePress compilation in the immutable version lifecycle.
//!
//! Handles:
//! - `spacepress.config.ts` (raw project) and `.spacepress-source/` (captured bundle)
//! - Inline `app` section inside `spacefast.config.ts`
//! - Legacy `zero.config.ts` and `.zero-source/` via the compiler's `zero_bundle` path
//!
//! Built frontend output (`index.html`, `_astro/*`, `_next/*`, `assets/*`, etc.) and
//! Cloudflare Functions (`functions/*`, `__spacefast/functions/bundles/*`, `_worker.js`)
//! coexist beside SpacePress in the same finalized version: SpacePress hides only its own
//! source files and captured bundles, leaving static frontend assets and Functions intact.

// Swap note when Track 0's `spacepress-source.zip` replaces `typedpress-source.zip`:
// 1. Flip `crates/typedpress` -> `crates/spacepress` in root `Cargo.toml` and
//    `typedpress = ...` -> `spacepress = ...` in `crates/stattic-runtime-core/Cargo.toml`.
// 2. Flip this single import to `use spacepress as compiler;` and the `include_str!`
//    path in `compiler_source_pin()` to `../../spacepress/SOURCE.json`.
use typedpress as compiler;

use crate::{
    finalize::{invalid, invalid_error, sha256, validate_id, FileMeta, Result},
    model::SiteFinalizeInput,
    protocol::CONFIG_TYPESCRIPT_FILE,
    zero_v2::{self, Compiled},
};
use oxc_allocator::Allocator;
use oxc_ast::ast::{
    Argument, BindingPattern, ExportDefaultDeclarationKind, Expression, ObjectPropertyKind,
    PropertyKey, Statement,
};
use oxc_parser::Parser;
use oxc_span::{GetSpan, SourceType};
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::{Path, PathBuf},
};

pub(crate) const SPACEPRESS_CONFIG_FILE: &str = "spacepress.config.ts";
pub(crate) const SPACEPRESS_SOURCE_DIR: &str = ".spacepress-source";
pub(crate) const SPACEPRESS_SOURCE_PREFIX: &str = ".spacepress-source/";
pub(crate) const SPACEPRESS_APP_BUNDLE: &str = ".spacepress-source/app.spacepress.json";
pub(crate) const SPACEPRESS_VENDOR_BUNDLE: &str = ".spacepress-source/vendor.spacepress.json";
pub(crate) const SPACEPRESS_MANIFEST: &str = ".spacepress-source/manifest.json";
const SPACEPRESS_ACTION_PREFIX: &str = "/wp-json/spacepress/v1/actions/";
const SPACEPRESS_SOURCE_FORMAT: &str = "spacepress.source-bundle.v1";

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum AppKind {
    Captured,
    Raw,
    InlineConfig,
}

pub(crate) fn is_spacepress_path(path: &str) -> bool {
    path == SPACEPRESS_CONFIG_FILE || path.starts_with(SPACEPRESS_SOURCE_PREFIX)
}

pub(crate) fn is_legacy_zero_path(path: &str) -> bool {
    path == "zero.config.ts" || path.starts_with(".zero-source/")
}

/// Whether the staged version tree declares a SpacePress app (standalone config,
/// captured `.spacepress-source/`, or inline `app` in `spacefast.config.ts`).
pub(crate) fn is_spacepress_declared(stage: &Path, files: &BTreeMap<String, FileMeta>) -> bool {
    if files.keys().any(|path| is_spacepress_path(path)) {
        return true;
    }
    if !files.keys().any(|path| is_legacy_zero_path(path))
        && files.contains_key(CONFIG_TYPESCRIPT_FILE)
    {
        let config_path = stage.join("files").join(CONFIG_TYPESCRIPT_FILE);
        if let Ok(source) = fs::read_to_string(config_path) {
            return extract_inline_app_config(&source).is_some();
        }
    }
    false
}

/// Whether the staged version tree declares a SpacePress or quarantined legacy
/// Zero v2 app.
pub(crate) fn is_declared(stage: &Path, files: &BTreeMap<String, FileMeta>) -> bool {
    files.keys().any(|path| is_legacy_zero_path(path)) || is_spacepress_declared(stage, files)
}

fn compiler_source_pin() -> Value {
    serde_json::from_str(include_str!("../../typedpress/SOURCE.json"))
        .expect("checked-in SpacePress compiler SOURCE.json")
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

/// Inspects `spacefast.config.ts` via Oxc and extracts a standalone SpacePress
/// config module if its default export carries a top-level `app` property.
/// Returns `None` for ordinary `spacefast.config.ts` files without `app`.
pub(crate) fn extract_inline_app_config(source: &str) -> Option<String> {
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, SourceType::ts()).parse();
    if !parsed.diagnostics.is_empty() {
        return None;
    }

    // Collect top-level `const x = ...` bindings in case `export default x;` is used.
    let mut bindings: BTreeMap<&str, &Expression<'_>> = BTreeMap::new();
    let mut default_expr: Option<&Expression<'_>> = None;
    let mut default_stmt_span = None;

    for stmt in &parsed.program.body {
        match stmt {
            Statement::VariableDeclaration(decl) => {
                for declarator in &decl.declarations {
                    if let (BindingPattern::BindingIdentifier(ident), Some(init)) =
                        (&declarator.id, &declarator.init)
                    {
                        bindings.insert(ident.name.as_str(), init);
                    }
                }
            }
            Statement::ExportDefaultDeclaration(export) => {
                default_stmt_span = Some(export.span);
                if let Some(expr) = export_default_expression(&export.declaration) {
                    default_expr = Some(expr);
                }
            }
            _ => {}
        }
    }

    let app_expr = find_inline_app_expr(default_expr?, &bindings)?;
    let app_span = app_expr.span();
    let app_slice = source
        .get(app_span.start as usize..app_span.end as usize)?
        .trim();
    if app_slice.is_empty() {
        return None;
    }

    let target_module = compiler::loader::TP_MODULE;
    let mut out = String::new();
    let needs_define_wrap = matches!(unwrap_expr(app_expr), Expression::ObjectExpression(_));
    if needs_define_wrap {
        out.push_str(&format!(
            "import {{ defineConfig as __sp_defineConfig }} from \"{target_module}\";\n"
        ));
    }

    for stmt in &parsed.program.body {
        if default_stmt_span.is_some_and(|span| span == stmt.span()) {
            continue;
        }
        match stmt {
            Statement::ImportDeclaration(import) => {
                let spec = import.source.value.as_str();
                if matches!(
                    spec,
                    "spacefast" | "@spacefast/config" | "@spacefast/cli" | "@spacefast/common"
                ) {
                    continue;
                }
                let span = import.span;
                if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                    out.push_str(&rewrite_module_specifiers(slice, target_module));
                    out.push('\n');
                }
            }
            Statement::VariableDeclaration(decl) => {
                // Skip the outer config binding itself if `export default config;` referenced it.
                if let Some(Expression::Identifier(ident)) = default_expr.map(unwrap_expr) {
                    let declares_default = decl.declarations.iter().any(|d| {
                        matches!(&d.id, BindingPattern::BindingIdentifier(id) if id.name == ident.name)
                    });
                    if declares_default {
                        continue;
                    }
                }
                let span = decl.span;
                if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                    out.push_str(slice);
                    out.push('\n');
                }
            }
            _ => {}
        }
    }

    let rewritten_app = rewrite_module_specifiers(app_slice, target_module);
    if needs_define_wrap {
        out.push_str(&format!(
            "export default __sp_defineConfig({rewritten_app});\n"
        ));
    } else {
        out.push_str(&format!("export default {rewritten_app};\n"));
    }
    Some(out)
}

fn export_default_expression<'a>(
    decl: &'a ExportDefaultDeclarationKind<'a>,
) -> Option<&'a Expression<'a>> {
    decl.as_expression()
}

fn unwrap_expr<'a>(mut expr: &'a Expression<'a>) -> &'a Expression<'a> {
    loop {
        match expr {
            Expression::ParenthesizedExpression(inner) => expr = &inner.expression,
            Expression::TSAsExpression(inner) => expr = &inner.expression,
            Expression::TSSatisfiesExpression(inner) => expr = &inner.expression,
            Expression::TSNonNullExpression(inner) => expr = &inner.expression,
            Expression::TSTypeAssertion(inner) => expr = &inner.expression,
            _ => return expr,
        }
    }
}

fn find_inline_app_expr<'a>(
    expr: &'a Expression<'a>,
    bindings: &BTreeMap<&str, &'a Expression<'a>>,
) -> Option<&'a Expression<'a>> {
    match unwrap_expr(expr) {
        Expression::ObjectExpression(obj) => find_app_property(obj),
        Expression::CallExpression(call) => {
            let first = call.arguments.first()?;
            match first {
                Argument::SpreadElement(_) => None,
                arg => arg
                    .as_expression()
                    .and_then(|inner| find_inline_app_expr(inner, bindings)),
            }
        }
        Expression::Identifier(ident) => bindings
            .get(ident.name.as_str())
            .and_then(|bound| find_inline_app_expr(bound, bindings)),
        _ => None,
    }
}

fn find_app_property<'a>(
    obj: &'a oxc_ast::ast::ObjectExpression<'a>,
) -> Option<&'a Expression<'a>> {
    for prop in &obj.properties {
        let ObjectPropertyKind::ObjectProperty(prop) = prop else {
            continue;
        };
        let is_app = match &prop.key {
            PropertyKey::StaticIdentifier(id) => id.name == "app",
            PropertyKey::StringLiteral(lit) => lit.value == "app",
            _ => false,
        };
        if is_app {
            return Some(&prop.value);
        }
    }
    None
}

/// Pre-zip-swap helper: while the bundled compiler crate is still named `typedpress`,
/// map `"spacepress"` / `"spacepress/*"` / `"spacepress:db"` imports to `"typedpress"`
/// equivalents. Once `spacepress-source.zip` lands, `target_module == "spacepress"` and
/// this becomes a no-op.
fn rewrite_module_specifiers(source: &str, target_module: &str) -> String {
    if target_module == "spacepress" {
        return source.to_owned();
    }
    source
        .replace("\"spacepress\"", &format!("\"{target_module}\""))
        .replace("'spacepress'", &format!("'{target_module}'"))
        .replace("\"spacepress/", &format!("\"{target_module}/"))
        .replace("'spacepress/", &format!("'{target_module}/"))
        .replace("\"spacepress:db\"", &format!("\"{target_module}:db\""))
        .replace("'spacepress:db'", &format!("'{target_module}:db'"))
}

fn needs_pre_rename_shim() -> bool {
    compiler::layout::CONFIG != SPACEPRESS_CONFIG_FILE
        || compiler::bundle::APP_FILE != "app.spacepress.json"
}

fn stage_compat_project(
    files_root: &Path,
    compat_root: &Path,
    config_override: Option<(&str, String)>,
) -> std::io::Result<()> {
    if compat_root.exists() {
        fs::remove_dir_all(compat_root)?;
    }
    fs::create_dir_all(compat_root)?;
    let target_module = compiler::loader::TP_MODULE;
    let target_config = compiler::layout::CONFIG;
    copy_project_for_compat(files_root, compat_root, target_module)?;
    if let Some((_orig, content)) = config_override {
        fs::write(compat_root.join(target_config), content)?;
    } else if target_config != SPACEPRESS_CONFIG_FILE {
        let spacepress_cfg = compat_root.join(SPACEPRESS_CONFIG_FILE);
        if spacepress_cfg.is_file() {
            let text = fs::read_to_string(&spacepress_cfg)?;
            fs::write(
                compat_root.join(target_config),
                rewrite_module_specifiers(&text, target_module),
            )?;
        }
    }
    Ok(())
}

fn copy_project_for_compat(from: &Path, to: &Path, target_module: &str) -> std::io::Result<()> {
    fs::create_dir_all(to)?;
    for entry in fs::read_dir(from)? {
        let entry = entry?;
        let name = entry.file_name();
        let name_str = name.to_string_lossy();
        if matches!(
            name_str.as_ref(),
            ".spacepress-source" | ".zero-source" | ".zero-compiled" | ".spacepress-compiled"
        ) {
            continue;
        }
        let dest = to.join(&name);
        if entry.file_type()?.is_dir() {
            copy_project_for_compat(&entry.path(), &dest, target_module)?;
        } else if target_module != "spacepress"
            && matches!(
                entry.path().extension().and_then(|e| e.to_str()),
                Some("ts" | "tsx" | "mts" | "cts" | "js" | "jsx" | "mjs" | "cjs")
            )
        {
            if let Ok(text) = fs::read_to_string(entry.path()) {
                fs::write(&dest, rewrite_module_specifiers(&text, target_module))?;
            } else {
                fs::copy(entry.path(), &dest)?;
            }
        } else {
            fs::copy(entry.path(), &dest)?;
        }
    }
    Ok(())
}

/// Pre-zip-swap helper for `.spacepress-source/` bundles while `crates/typedpress`
/// expects `app.typedpress.json`, `vendor.typedpress.json`, and `typedpress.source-bundle.v1`.
fn prepare_captured_spacepress_sources(source_dir: &Path, compat_dir: &Path) -> Result<PathBuf> {
    if !needs_pre_rename_shim() {
        return Ok(source_dir.to_path_buf());
    }
    let failure = |e: std::io::Error| invalid_error("spacepress_build_io", e.to_string());
    if compat_dir.exists() {
        fs::remove_dir_all(compat_dir).map_err(failure)?;
    }
    fs::create_dir_all(compat_dir).map_err(failure)?;

    let manifest_bytes = fs::read(source_dir.join("manifest.json")).map_err(failure)?;
    let mut manifest: Value = serde_json::from_slice(&manifest_bytes)
        .map_err(|e| invalid_error("spacepress_source_layout", e.to_string()))?;
    if !matches!(
        manifest.get("format").and_then(Value::as_str),
        Some(SPACEPRESS_SOURCE_FORMAT | "typedpress.source-bundle.v1")
    ) {
        return invalid(
            "spacepress_source_layout",
            "SpacePress source bundle must declare format spacepress.source-bundle.v1.",
        );
    }

    let app_raw = fs::read(source_dir.join("app.spacepress.json")).map_err(failure)?;
    let vendor_raw = fs::read(source_dir.join("vendor.spacepress.json")).map_err(failure)?;
    if manifest.get("app_sha256").and_then(Value::as_str) != Some(sha256(&app_raw).as_str())
        || manifest.get("vendor_sha256").and_then(Value::as_str)
            != Some(sha256(&vendor_raw).as_str())
    {
        return invalid("spacepress_compile_failed", "source bundle hash mismatch");
    }

    let target_module = compiler::loader::TP_MODULE;
    let target_config = compiler::layout::CONFIG;
    let adapt_bundle = |bytes: &[u8]| -> Result<Vec<u8>> {
        let mut bundle: compiler::bundle::SourceBundle = serde_json::from_slice(bytes)
            .map_err(|e| invalid_error("spacepress_source_layout", e.to_string()))?;
        let adapt_path = |p: String| {
            if p == SPACEPRESS_CONFIG_FILE {
                target_config.to_owned()
            } else {
                p
            }
        };
        bundle.files = bundle
            .files
            .into_iter()
            .map(|(k, v)| (adapt_path(k), rewrite_module_specifiers(&v, target_module)))
            .collect();
        bundle.canonical = bundle
            .canonical
            .into_iter()
            .map(|(k, v)| (adapt_path(k), adapt_path(v)))
            .collect();
        serde_json::to_vec(&bundle)
            .map_err(|e| invalid_error("spacepress_source_layout", e.to_string()))
    };

    let app_adapted = adapt_bundle(&app_raw)?;
    let vendor_adapted = adapt_bundle(&vendor_raw)?;
    manifest["format"] = Value::String("typedpress.source-bundle.v1".into());
    manifest["app_sha256"] = Value::String(sha256(&app_adapted));
    manifest["vendor_sha256"] = Value::String(sha256(&vendor_adapted));

    fs::write(compat_dir.join(compiler::bundle::APP_FILE), app_adapted).map_err(failure)?;
    fs::write(
        compat_dir.join(compiler::bundle::VENDOR_FILE),
        vendor_adapted,
    )
    .map_err(failure)?;
    fs::write(
        compat_dir.join("manifest.json"),
        serde_json::to_vec(&manifest).unwrap(),
    )
    .map_err(failure)?;
    Ok(compat_dir.to_path_buf())
}

/// Reads the exact set of project paths captured in `app` and `vendor` bundles so
/// raw SpacePress compiles keep only SpacePress source inputs private and leave
/// built frontend assets (`index.html`, `_astro/*`, `assets/*`, etc.) public.
fn captured_source_paths(sources_dir: &Path) -> BTreeSet<String> {
    let mut paths = BTreeSet::new();
    for file in [
        "app.spacepress.json",
        "vendor.spacepress.json",
        compiler::bundle::APP_FILE,
        compiler::bundle::VENDOR_FILE,
    ] {
        let Ok(bytes) = fs::read(sources_dir.join(file)) else {
            continue;
        };
        let Ok(bundle) = serde_json::from_slice::<compiler::bundle::SourceBundle>(&bytes) else {
            continue;
        };
        for path in bundle
            .files
            .keys()
            .chain(bundle.canonical.keys())
            .chain(bundle.canonical.values())
        {
            paths.insert(path.clone());
        }
    }
    paths
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
                "The compiled SpacePress/Zero directory is reserved for the engine.",
            );
        }
        if lower == ".spacepress-compiled" || lower.starts_with(".spacepress-compiled/") {
            return invalid(
                "spacepress_reserved_output",
                "The compiled SpacePress directory is reserved for the engine.",
            );
        }
    }

    let has_spacepress_captured = files
        .keys()
        .any(|p| p.starts_with(SPACEPRESS_SOURCE_PREFIX));
    let has_spacepress_raw = files.contains_key(SPACEPRESS_CONFIG_FILE);
    let has_legacy_zero = files.keys().any(|p| is_legacy_zero_path(p));

    let inline_app_source = if !has_spacepress_captured
        && !has_spacepress_raw
        && !has_legacy_zero
        && files.contains_key(CONFIG_TYPESCRIPT_FILE)
    {
        fs::read_to_string(stage.join("files").join(CONFIG_TYPESCRIPT_FILE))
            .ok()
            .and_then(|source| extract_inline_app_config(&source))
    } else {
        None
    };

    if (has_spacepress_captured || has_spacepress_raw) && has_legacy_zero {
        return invalid(
            "spacepress_mixed_sources",
            "Publish one framework's source at a time. Remove legacy zero.config.ts/.zero-source/ when publishing SpacePress.",
        );
    }

    if has_spacepress_captured && has_spacepress_raw {
        return invalid(
            "spacepress_mixed_sources",
            "Publish either the SpacePress project (spacepress.config.ts) or its captured bundle (.spacepress-source/). If retention carried forward spacepress.config.ts, use retention none or a list that excludes it.",
        );
    }

    let kind = if has_spacepress_captured {
        AppKind::Captured
    } else if has_spacepress_raw {
        AppKind::Raw
    } else if inline_app_source.is_some() {
        AppKind::InlineConfig
    } else if has_legacy_zero {
        return zero_v2::compile(input, private, stage, files);
    } else {
        return Ok(None);
    };

    for path in files.keys() {
        let lower = path.to_ascii_lowercase();
        if lower.ends_with(".php") {
            return invalid(
                "spacepress_reserved_output",
                "SpacePress accepts source only; uploaded PHP is reserved for the host.",
            );
        }
        if path.starts_with(SPACEPRESS_SOURCE_PREFIX)
            && !matches!(
                path.as_str(),
                SPACEPRESS_APP_BUNDLE | SPACEPRESS_VENDOR_BUNDLE | SPACEPRESS_MANIFEST
            )
        {
            return invalid(
                "spacepress_source_layout",
                "SpacePress requires an app bundle, a vendor bundle and a manifest.",
            );
        }
    }

    let failure = |e: std::io::Error| invalid_error("spacepress_build_io", e.to_string());
    let output = stage.join(".zero-compiled");
    let store = output.join("store");
    fs::create_dir_all(&store).map_err(failure)?;

    let capture_dir = stage.join(".spacepress-capture");
    let compat_project_dir = stage.join(".spacepress-compat-project");
    let compat_sources_dir = stage.join(".spacepress-compat-sources");

    let sources_dir = match kind {
        AppKind::Captured => prepare_captured_spacepress_sources(
            &stage.join("files").join(SPACEPRESS_SOURCE_DIR),
            &compat_sources_dir,
        )?,
        AppKind::Raw => {
            if needs_pre_rename_shim() {
                stage_compat_project(&stage.join("files"), &compat_project_dir, None)
                    .map_err(failure)?;
                compiler::host::sources(&compat_project_dir, &capture_dir)
                    .map_err(map_capture_error)?;
            } else {
                compiler::host::sources(&stage.join("files"), &capture_dir)
                    .map_err(map_capture_error)?;
            }
            capture_dir.clone()
        }
        AppKind::InlineConfig => {
            let synthesized = inline_app_source.expect("checked above");
            stage_compat_project(
                &stage.join("files"),
                &compat_project_dir,
                Some((CONFIG_TYPESCRIPT_FILE, synthesized)),
            )
            .map_err(failure)?;
            compiler::host::sources(&compat_project_dir, &capture_dir)
                .map_err(map_capture_error)?;
            capture_dir.clone()
        }
    };

    let mut previous_build = None;
    let previous_version = input
        .body
        .get("spacepress_previous")
        .or_else(|| input.body.get("zero_v2_previous"))
        .and_then(Value::as_str);
    if let Some(previous) = previous_version {
        validate_id(previous, "zero_v2_previous")?;
        let old = private
            .join("spaces")
            .join(&input.space_id)
            .join("versions")
            .join(previous)
            .join(".zero-compiled");
        let manifest: Value =
            serde_json::from_slice(&fs::read(old.join("manifest.json")).map_err(failure)?)
                .map_err(|e| invalid_error("spacepress_baseline_invalid", e.to_string()))?;
        let build = manifest
            .get("build")
            .and_then(Value::as_str)
            .filter(|s| compiler::store::valid_id(s))
            .ok_or_else(|| {
                invalid_error(
                    "spacepress_migration_required",
                    "Use a fresh Space; this baseline is not a SpacePress build.",
                )
            })?;
        let old_store = old.join("store");
        let build_file = format!("build/{build}.json");
        let bytes = fs::read(old_store.join(&build_file)).map_err(failure)?;
        let baseline: compiler::host::BuildManifest = serde_json::from_slice(&bytes)
            .map_err(|e| invalid_error("spacepress_baseline_invalid", e.to_string()))?;
        if !compiler::store::valid_id(&baseline.app) {
            return invalid("spacepress_baseline_invalid", "Invalid baseline app hash.");
        }
        fs::create_dir_all(store.join("build")).map_err(failure)?;
        fs::write(store.join(build_file), bytes).map_err(failure)?;
        // Only trusted host-produced snapshot and migration metadata form the baseline.
        let app = store.join("app").join(&baseline.app);
        copy_tree(&old_store.join("app").join(&baseline.app), &app).map_err(failure)?;
        previous_build = Some(build.to_owned());
    }

    let compile_result = compiler::host::compile_to_store(
        &sources_dir,
        &store,
        &stage.join("files"),
        previous_build.as_deref(),
    );

    let captured_paths = matches!(kind, AppKind::Raw | AppKind::InlineConfig)
        .then(|| captured_source_paths(&sources_dir));

    // Clean up temporary capture/compat directories so nothing extraneous lands in stage.
    for temp_dir in [&capture_dir, &compat_project_dir, &compat_sources_dir] {
        if temp_dir.exists() {
            let _ = fs::remove_dir_all(temp_dir);
        }
    }

    let build =
        compile_result.map_err(|e| invalid_error("spacepress_compile_failed", e.message))?;

    let app = store.join("app").join(&build.app_hash);
    let mut manifest: Value =
        serde_json::from_slice(&fs::read(app.join("manifest.json")).map_err(failure)?)
            .map_err(|e| invalid_error("spacepress_manifest_invalid", e.to_string()))?;
    if manifest["hostContract"] != compiler::host_contract::HOST_CONTRACT {
        return invalid(
            "spacepress_host_contract",
            "Unsupported SpacePress host contract.",
        );
    }
    let pin = compiler_source_pin();
    manifest["format"] = "spacepress.hosted.v1".into();
    manifest["compilerRevision"] = pin["revision"].clone();
    manifest["summary"]["clientPath"] = "/__zero/client.js".into();
    manifest["summary"]["actionPathPrefix"] = SPACEPRESS_ACTION_PREFIX.into();
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

    let private_paths = match kind {
        AppKind::Captured => {
            // Only the captured `.spacepress-source/` bundle is private; built
            // frontend output (`index.html`, `_astro/*`, `assets/*`, etc.) and
            // Cloudflare Functions stay public/finalized normally side-by-side.
            files
                .keys()
                .filter(|p| p.starts_with(SPACEPRESS_SOURCE_PREFIX))
                .cloned()
                .collect()
        }
        AppKind::Raw | AppKind::InlineConfig => {
            // Promote any `public/*` assets to the root without hiding built
            // frontend output already sitting beside the SpacePress config.
            promote_public_assets(stage, files)?;
            let captured = captured_paths.unwrap_or_default();
            let mut private_paths = BTreeSet::new();
            for path in files.keys() {
                if path == SPACEPRESS_CONFIG_FILE
                    || path == CONFIG_TYPESCRIPT_FILE
                    || path.starts_with(SPACEPRESS_SOURCE_PREFIX)
                    || path.starts_with("public/")
                    || path.starts_with("sp/")
                    || path.starts_with("tp/")
                    || captured.contains(path)
                {
                    private_paths.insert(path.clone());
                }
            }
            private_paths
        }
    };

    Ok(Some(Compiled {
        manifest,
        private_paths,
    }))
}

fn map_capture_error(error: compiler::diag::Diag) -> crate::finalize::FinalizeError {
    let code = if error.code == compiler::diag::Code::SourceNeedsLocalCapture {
        "spacepress_source_needs_local_capture"
    } else {
        "spacepress_capture_failed"
    };
    invalid_error(code, error.message)
}

fn promote_public_assets(stage: &Path, files: &mut BTreeMap<String, FileMeta>) -> Result<()> {
    let layout_code = "spacepress_public_collision";
    let io_code = "spacepress_build_io";
    let assets: Vec<_> = files
        .iter()
        .filter_map(|(path, meta)| {
            path.strip_prefix("public/")
                .map(|name| (name.to_owned(), meta.clone()))
        })
        .collect();
    for (name, meta) in assets {
        if name.starts_with('.')
            || name.starts_with("public/")
            || name.starts_with(".zero-source/")
            || name.starts_with(".zero-compiled/")
            || name.starts_with(SPACEPRESS_SOURCE_PREFIX)
            || name.starts_with(".spacepress-compiled/")
        {
            return invalid(
                layout_code,
                format!("public/{name} collides with a project or reserved path."),
            );
        }
        let target = stage.join("files").join(&name);
        if files.contains_key(&name) || target.exists() {
            // The staged file may be a hardlink into the CAS that earlier
            // versions still serve; unlink before copying so we never mutate CAS bytes.
            if target.exists() {
                fs::remove_file(&target)
                    .map_err(|error| invalid_error(io_code, error.to_string()))?;
            }
            files.remove(&name);
        }
        if let Some(parent) = target.parent() {
            fs::create_dir_all(parent)
                .map_err(|error| invalid_error(io_code, error.to_string()))?;
        }
        fs::copy(stage.join("files/public").join(&name), &target)
            .map_err(|error| invalid_error(io_code, error.to_string()))?;
        files.insert(name, meta);
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::extract_inline_app_config;

    #[test]
    fn inline_app_extraction_detects_app_section_and_ignores_plain_configs() {
        let plain = r#"
            export default {
                cleanUrls: true,
                connectors: { crm: { connector: "hubspot", tools: ["list"] } }
            };
        "#;
        assert!(extract_inline_app_config(plain).is_none());

        let with_app = r#"
            import { defineSpaceConfig } from "spacefast";
            import { posts } from "./app/collections";
            export default defineSpaceConfig({
                cleanUrls: true,
                app: {
                    name: "blog",
                    admin: false,
                    collections: { posts }
                }
            });
        "#;
        let extracted = extract_inline_app_config(with_app).expect("inline app extracted");
        assert!(extracted.contains("import { posts } from \"./app/collections\""));
        assert!(!extracted.contains("defineSpaceConfig"));
        assert!(extracted.contains("name: \"blog\""));
    }
}
