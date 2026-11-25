//! Unified SpacePress compilation in the immutable version lifecycle.
//!
//! Handles:
//! - `spacepress.config.ts` (raw project) and `.spacepress-source/` (captured bundle)
//! - Inline `app` section inside `spacefast.config.ts`
//! - Legacy `zero.config.ts` and `.zero-source/`, handed to the quarantined Zero v2 compiler (`zero_v2`)
//!
//! Built frontend output (`index.html`, `_astro/*`, `_next/*`, `assets/*`, etc.) and
//! Cloudflare Functions (`functions/*`, `__spacefast/functions/bundles/*`, `_worker.js`)
//! coexist beside SpacePress in the same finalized version: SpacePress hides only its own
//! source files and captured bundles, leaving static frontend assets and Functions intact.

use spacepress as compiler;

use crate::{
    catalog::read_version_catalog,
    finalize::{
        create_dir_all, invalid, invalid_error, remove_any, sha256, validate_id,
        validate_relative_path, write_bytes_in_place, FileMeta, Result,
    },
    model::SiteFinalizeInput,
    protocol::CONFIG_TYPESCRIPT_FILE,
    storage::{blob_path, blob_root},
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
const STYLE_DIRS: [&str; 2] = ["spacepress-styles/assets", "typedpress-styles/assets"];

fn published_stylesheet(path: &str) -> bool {
    let Some((parent, file)) = path.rsplit_once('/') else {
        return false;
    };
    STYLE_DIRS.contains(&parent) && file.ends_with(".css")
}

const SPACEPRESS_HOSTED_FORMAT: &str = "spacepress.hosted.v1";

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
        if let Ok(bytes) =
            crate::finalize::read_bounded(&config_path, crate::protocol::CONFIG_FILE_MAX_BYTES)
        {
            if let Ok(source) = String::from_utf8(bytes) {
                return extract_inline_app_config(&source).is_some();
            }
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
    serde_json::from_str(include_str!("../../spacepress/SOURCE.json"))
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

    let target_module = compiler::loader::SP_MODULE;
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
                    out.push_str(slice);
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

    if needs_define_wrap {
        out.push_str(&format!("export default __sp_defineConfig({app_slice});\n"));
    } else {
        out.push_str(&format!("export default {app_slice};\n"));
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
    let mut visited = std::collections::HashSet::new();
    find_inline_app_expr_inner(expr, bindings, &mut visited, 0)
}

fn find_inline_app_expr_inner<'a>(
    expr: &'a Expression<'a>,
    bindings: &BTreeMap<&str, &'a Expression<'a>>,
    visited: &mut std::collections::HashSet<&'a str>,
    depth: usize,
) -> Option<&'a Expression<'a>> {
    if depth > 64 {
        return None;
    }
    match unwrap_expr(expr) {
        Expression::ObjectExpression(obj) => find_app_property(obj),
        Expression::CallExpression(call) => {
            let first = call.arguments.first()?;
            match first {
                Argument::SpreadElement(_) => None,
                arg => arg.as_expression().and_then(|inner| {
                    find_inline_app_expr_inner(inner, bindings, visited, depth + 1)
                }),
            }
        }
        Expression::Identifier(ident) => {
            let name = ident.name.as_str();
            if !visited.insert(name) {
                return None;
            }
            let result = bindings
                .get(name)
                .and_then(|bound| find_inline_app_expr_inner(bound, bindings, visited, depth + 1));
            visited.remove(name);
            result
        }
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

// A released CLI can send a SpacePress envelope containing the TypedPress layout.
// Adapt only paths and import/export specifiers. Keep arbitrary application strings intact.
fn current_source_path(path: &str) -> String {
    match path {
        "typedpress.config.ts" => SPACEPRESS_CONFIG_FILE.into(),
        "tp" => "sp".into(),
        _ => path
            .strip_prefix("tp/")
            .map_or_else(|| path.into(), |rest| format!("sp/{rest}")),
    }
}

// Rewrite only components reached at the bundle root. A nested tp/ is app-owned.
fn current_relative_import(path: &str, name: &str) -> String {
    let mut depth = path.matches('/').count() as isize;
    let mut components: Vec<_> = name.split('/').collect();
    let last = components.len() - 1;
    for (index, part) in components.iter_mut().enumerate() {
        match *part {
            "." | "" => continue,
            ".." => depth -= 1,
            _ => {
                if depth == 0 {
                    *part = match *part {
                        "tp" => "sp",
                        "typedpress.config" if index == last => "spacepress.config",
                        "typedpress.config.ts" if index == last => SPACEPRESS_CONFIG_FILE,
                        other => other,
                    };
                }
                depth += 1;
            }
        }
    }
    components.join("/")
}

fn current_source_text(path: &str, mut text: String) -> String {
    let Ok(kind) = SourceType::from_path(path) else {
        return text;
    };
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, &text, kind).parse();
    let mut edits = Vec::new();
    for (specifier, requests) in &parsed.module_record.requested_modules {
        let name = specifier.as_str();
        let replacement =
            if name == "typedpress" || name.starts_with("typedpress/") || name == "typedpress:db" {
                name.replacen("typedpress", "spacepress", 1)
            } else if name.starts_with("./") || name.starts_with("../") {
                let mapped = current_relative_import(path, name);
                if mapped == name {
                    continue;
                }
                mapped
            } else {
                continue;
            };
        for request in requests {
            edits.push((
                request.span.start as usize + 1,
                request.span.end as usize - 1,
                replacement.clone(),
            ));
        }
    }
    edits.sort_by_key(|edit| edit.0);
    for (start, end, replacement) in edits.into_iter().rev() {
        text.replace_range(start..end, &replacement);
    }
    text
}

fn prepare_captured_spacepress_sources(source_dir: &Path, compat_dir: &Path) -> Result<PathBuf> {
    let failure = |e: std::io::Error| invalid_error("spacepress_build_io", e.to_string());
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
    let parse = |bytes: &[u8]| {
        serde_json::from_slice::<compiler::bundle::SourceBundle>(bytes)
            .map_err(|e| invalid_error("spacepress_source_layout", e.to_string()))
    };
    let app = parse(&app_raw)?;
    if manifest["format"] == SPACEPRESS_SOURCE_FORMAT
        && !app
            .files
            .keys()
            .any(|path| path == "typedpress.config.ts" || path.starts_with("tp/"))
    {
        return Ok(source_dir.to_owned());
    }
    let vendor_raw = fs::read(source_dir.join("vendor.spacepress.json")).map_err(failure)?;
    if manifest.get("app_sha256").and_then(Value::as_str) != Some(sha256(&app_raw).as_str())
        || manifest.get("vendor_sha256").and_then(Value::as_str)
            != Some(sha256(&vendor_raw).as_str())
    {
        return invalid("spacepress_compile_failed", "source bundle hash mismatch");
    }

    fn rename_map<T>(
        entries: BTreeMap<String, T>,
        adapt: impl Fn(&str, T) -> T,
    ) -> Result<BTreeMap<String, T>> {
        let mut renamed = BTreeMap::new();
        for (path, value) in entries {
            if renamed
                .insert(current_source_path(&path), adapt(&path, value))
                .is_some()
            {
                return invalid(
                    "spacepress_source_layout",
                    "Source bundle mixes old and new names.",
                );
            }
        }
        Ok(renamed)
    }
    let adapt_bundle = |mut bundle: compiler::bundle::SourceBundle| -> Result<Vec<u8>> {
        // CSS @import and url() are resolved by the compiler. Preserve the old lookup names
        // while keeping one copy of each file at its new canonical path.
        let aliases: BTreeMap<_, _> = bundle
            .files
            .keys()
            .chain(bundle.binary.keys())
            .chain(bundle.canonical.keys())
            .filter(|path| path.starts_with("tp/"))
            .map(|path| {
                let target = bundle.canonical.get(path).unwrap_or(path);
                (path.clone(), current_source_path(target))
            })
            .collect();
        if let Some(text) = bundle.files.get_mut("tp/_generated/frontend-styles.json") {
            let invalid_styles = |e: serde_json::Error| {
                invalid_error(
                    "spacepress_source_layout",
                    format!("Invalid frontend styles manifest: {e}"),
                )
            };
            let mut manifest: Value = serde_json::from_str(text).map_err(invalid_styles)?;
            if let Some(styles) = manifest.get_mut("styles") {
                let entries: BTreeMap<String, String> =
                    serde_json::from_value(styles.take()).map_err(invalid_styles)?;
                *styles = serde_json::to_value(rename_map(entries, |_, url| url)?)
                    .map_err(invalid_styles)?;
            }
            *text = serde_json::to_string(&manifest).map_err(invalid_styles)?;
        }
        bundle.files = rename_map(bundle.files, current_source_text)?;
        bundle.binary = rename_map(bundle.binary, |_, bytes| bytes)?;
        bundle.canonical = rename_map(bundle.canonical, |_, path| current_source_path(&path))?;
        if !aliases.is_empty() {
            bundle.canonical.extend(aliases);
            // The compiler resolves evaluated sync globs against their original source root.
            bundle.canonical.insert("tp".into(), "sp".into());
        }
        serde_json::to_vec(&bundle)
            .map_err(|e| invalid_error("spacepress_source_layout", e.to_string()))
    };
    let app_adapted = adapt_bundle(app)?;
    let vendor_adapted = adapt_bundle(parse(&vendor_raw)?)?;
    fs::create_dir_all(compat_dir).map_err(failure)?;
    manifest["format"] = Value::String(SPACEPRESS_SOURCE_FORMAT.into());
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
    for file in [compiler::bundle::APP_FILE, compiler::bundle::VENDOR_FILE] {
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
        crate::finalize::read_bounded(
            &stage.join("files").join(CONFIG_TYPESCRIPT_FILE),
            crate::protocol::CONFIG_FILE_MAX_BYTES,
        )
        .ok()
        .and_then(|bytes| String::from_utf8(bytes).ok())
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

    let previous_build = input
        .body
        .get("spacepress_previous")
        .or_else(|| input.body.get("zero_v2_previous"))
        .and_then(Value::as_str)
        .map(|previous| seed_baseline(private, &input.space_id, previous, &store))
        .transpose()?;

    let work = SourceWork::new(stage);
    let compile_result = compiler_sources(kind, &work, &stage.join("files"), inline_app_source)
        .and_then(|sources_dir| {
            let assets = work.compiler_assets(kind, &stage.join("files"))?;
            let build = compiler::host::compile_to_store(
                &sources_dir,
                &store,
                &assets,
                previous_build.as_deref(),
            )
            .map_err(|e| invalid_error("spacepress_compile_failed", e.message))?;
            let captured_paths = matches!(kind, AppKind::Raw | AppKind::InlineConfig)
                .then(|| captured_source_paths(&sources_dir));
            Ok((build, captured_paths))
        });
    // Clean up temporary capture/compat directories so nothing extraneous lands in stage.
    work.remove();
    let (build, captured_paths) = compile_result?;

    let manifest = hosted_manifest(&store, build)?;
    fs::write(
        output.join("manifest.json"),
        serde_json::to_vec(&manifest).unwrap(),
    )
    .map_err(failure)?;
    let app = store
        .join("app")
        .join(manifest["appHash"].as_str().unwrap_or_default());
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

/// Scratch directories the compiler's source capture and pre-rename shims write under a stage.
struct SourceWork {
    capture: PathBuf,
    compat_project: PathBuf,
    compat_sources: PathBuf,
}

impl SourceWork {
    fn new(stage: &Path) -> Self {
        Self {
            capture: stage.join(".spacepress-capture"),
            compat_project: stage.join(".spacepress-compat-project"),
            compat_sources: stage.join(".spacepress-compat-sources"),
        }
    }

    // A captured CLI upload serves public files from the version root. Present its built
    // styles at the project-relative paths the compiler expects, without editing the catalog.
    fn compiler_assets(&self, kind: AppKind, files: &Path) -> Result<PathBuf> {
        if kind != AppKind::Captured || !STYLE_DIRS.iter().any(|dir| files.join(dir).is_dir()) {
            return Ok(files.to_owned());
        }
        let public = self.compat_project.join("public");
        if files.join("public").is_dir() {
            copy_tree(&files.join("public"), &public).map_err(io_failure)?;
        }
        for dir in STYLE_DIRS {
            let source = files.join(dir);
            if !source.is_dir() {
                continue;
            }
            let target = public.join(dir);
            fs::create_dir_all(&target).map_err(io_failure)?;
            for entry in fs::read_dir(source).map_err(io_failure)? {
                let entry = entry.map_err(io_failure)?;
                if entry.file_type().map_err(io_failure)?.is_file()
                    && entry.path().extension().is_some_and(|ext| ext == "css")
                {
                    fs::copy(entry.path(), target.join(entry.file_name())).map_err(io_failure)?;
                }
            }
        }
        Ok(self.compat_project.clone())
    }

    fn remove(&self) {
        for dir in [&self.capture, &self.compat_project, &self.compat_sources] {
            if dir.exists() {
                let _ = fs::remove_dir_all(dir);
            }
        }
    }
}

/// The source bundle directory the compiler reads for an app of `kind` whose files sit in
/// `files_root`: the captured bundle, or one captured now from the raw or inline project.
fn compiler_sources(
    kind: AppKind,
    work: &SourceWork,
    files_root: &Path,
    inline_app_source: Option<String>,
) -> Result<PathBuf> {
    let failure = |e: std::io::Error| invalid_error("spacepress_build_io", e.to_string());
    if !matches!(kind, AppKind::Captured) && files_root.join("tp").is_dir() {
        return invalid(
            "spacepress_source_layout",
            "Upgrade the project's tp/ directory to sp/ before publishing raw source.",
        );
    }
    match kind {
        AppKind::Captured => prepare_captured_spacepress_sources(
            &files_root.join(SPACEPRESS_SOURCE_DIR),
            &work.compat_sources,
        ),
        AppKind::Raw => {
            compiler::host::sources(files_root, &work.capture).map_err(map_capture_error)?;
            Ok(work.capture.clone())
        }
        AppKind::InlineConfig => {
            let synthesized = inline_app_source.ok_or_else(|| {
                invalid_error(
                    "spacepress_source_layout",
                    "spacefast.config.ts declares no SpacePress app.",
                )
            })?;
            copy_tree(files_root, &work.compat_project).map_err(failure)?;
            fs::write(
                work.compat_project.join(SPACEPRESS_CONFIG_FILE),
                synthesized,
            )
            .map_err(failure)?;
            compiler::host::sources(&work.compat_project, &work.capture)
                .map_err(map_capture_error)?;
            Ok(work.capture.clone())
        }
    }
}

fn io_failure(e: std::io::Error) -> crate::finalize::FinalizeError {
    invalid_error("spacepress_build_io", e.to_string())
}

/// Copy the baseline version's build manifest and app tree into `store`, and return its build ID.
/// Only trusted host-produced snapshot and migration metadata form the baseline.
fn seed_baseline(private: &Path, space_id: &str, previous: &str, store: &Path) -> Result<String> {
    validate_id(previous, "zero_v2_previous")?;
    let old = private
        .join("spaces")
        .join(space_id)
        .join("versions")
        .join(previous)
        .join(".zero-compiled");
    let manifest: Value =
        serde_json::from_slice(&fs::read(old.join("manifest.json")).map_err(io_failure)?)
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
    let bytes = fs::read(old_store.join(&build_file)).map_err(io_failure)?;
    let baseline: compiler::host::BuildManifest = serde_json::from_slice(&bytes)
        .map_err(|e| invalid_error("spacepress_baseline_invalid", e.to_string()))?;
    if !compiler::store::valid_id(&baseline.app) {
        return invalid("spacepress_baseline_invalid", "Invalid baseline app hash.");
    }
    // Store entries are content-addressed and never edited, so one already in
    // place is these bytes. Each is staged under `.tmp/` and renamed in whole,
    // so an interrupted copy never leaves a partial entry under its real name.
    let staging = store.join(".tmp");
    fs::create_dir_all(&staging).map_err(io_failure)?;
    fs::create_dir_all(store.join("build")).map_err(io_failure)?;
    fs::create_dir_all(store.join("app")).map_err(io_failure)?;
    let unique = format!("{}-{}", std::process::id(), build);
    if !store.join(&build_file).is_file() {
        let staged = staging.join(format!("build-{unique}.json"));
        fs::write(&staged, bytes).map_err(io_failure)?;
        fs::rename(&staged, store.join(&build_file)).map_err(io_failure)?;
    }
    let app = store.join("app").join(&baseline.app);
    if !app.is_dir() {
        let staged = staging.join(format!("app-{unique}"));
        if staged.exists() {
            fs::remove_dir_all(&staged).map_err(io_failure)?;
        }
        copy_tree(&old_store.join("app").join(&baseline.app), &staged).map_err(io_failure)?;
        if let Err(error) = fs::rename(&staged, &app) {
            // Another promote placed the same entry first: those are these bytes.
            let _ = fs::remove_dir_all(&staged);
            if !app.is_dir() {
                return Err(io_failure(error));
            }
        }
    }
    Ok(build.to_owned())
}

/// The version's `.zero-compiled/manifest.json` for a build in its store.
fn hosted_manifest(store: &Path, build: compiler::host::StoredBuild) -> Result<Value> {
    let app = store.join("app").join(&build.app_hash);
    let mut manifest: Value =
        serde_json::from_slice(&fs::read(app.join("manifest.json")).map_err(io_failure)?)
            .map_err(|e| invalid_error("spacepress_manifest_invalid", e.to_string()))?;
    if manifest["hostContract"] != compiler::host_contract::HOST_CONTRACT {
        return invalid(
            "spacepress_host_contract",
            "Unsupported SpacePress host contract.",
        );
    }
    let pin = compiler_source_pin();
    manifest["format"] = SPACEPRESS_HOSTED_FORMAT.into();
    manifest["compilerRevision"] = pin["revision"].clone();
    manifest["summary"]["clientPath"] = "/__zero/client.js".into();
    manifest["summary"]["actionPathPrefix"] = SPACEPRESS_ACTION_PREFIX.into();
    manifest["build"] = build.build_id.into();
    manifest["runtimeRevision"] = build.runtime_revision.into();
    manifest["appHash"] = build.app_hash.into();
    Ok(manifest)
}

/// A recompile may change only which build a version names. A different runtime or compiler
/// revision, page, route or generated client would put code its preview never ran on production.
fn without_build_identity(manifest: &Value) -> Value {
    let mut manifest = manifest.clone();
    if let Some(object) = manifest.as_object_mut() {
        for key in ["build", "appHash"] {
            object.remove(key);
        }
    }
    manifest
}

fn tree(root: &Path) -> std::io::Result<BTreeMap<String, Vec<u8>>> {
    fn walk(root: &Path, dir: &Path, out: &mut BTreeMap<String, Vec<u8>>) -> std::io::Result<()> {
        for entry in fs::read_dir(dir)? {
            let entry = entry?;
            if entry.file_type()?.is_dir() {
                walk(root, &entry.path(), out)?;
            } else {
                let rel = entry
                    .path()
                    .strip_prefix(root)
                    .unwrap()
                    .to_string_lossy()
                    .into_owned();
                out.insert(rel, fs::read(entry.path())?);
            }
        }
        Ok(())
    }
    let mut out = BTreeMap::new();
    if root.is_dir() {
        walk(root, root, &mut out)?;
    }
    Ok(out)
}

fn not_spacepress<T>() -> Result<T> {
    invalid(
        "spacepress_recompile_not_spacepress",
        "This version is not a SpacePress build.",
    )
}

/// Compile a finalized SpacePress version's migrations again, against `baseline` (the version
/// currently initialized on the Space's WordPress; `None` when none is).
///
/// The compile reads the version's own committed sources from the Space's CAS: the captured
/// `.spacepress-source/` bundle, or the raw or inline project it captures again, and `public/`.
/// It writes the new build, app tree and baseline entries into the version's store beside the old
/// ones, and points the version's manifest at the new build. Nothing it writes replaces a store
/// entry, so a preview that seeded the old build keeps loading it. A recompile that would change
/// anything the version serves besides its build (pages, routes, generated client, compiler or
/// runtime revision) is refused: the version has to be published again.
///
/// The caller holds the Space's Zero v2 lock.
pub fn recompile(
    private: &Path,
    space_id: &str,
    version_id: &str,
    baseline: Option<&str>,
) -> Result<Value> {
    validate_id(space_id, "space_id")?;
    validate_id(version_id, "version_id")?;
    let versions = private.join("spaces").join(space_id).join("versions");
    let version = versions.join(version_id);
    let compiled = version.join(".zero-compiled");
    let manifest_path = compiled.join("manifest.json");
    let current: Value = match fs::read(&manifest_path) {
        Ok(bytes) => serde_json::from_slice(&bytes)
            .map_err(|e| invalid_error("spacepress_manifest_invalid", e.to_string()))?,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return not_spacepress(),
        Err(e) => return Err(io_failure(e)),
    };
    if current["format"] != SPACEPRESS_HOSTED_FORMAT {
        return not_spacepress();
    }
    let catalog = read_version_catalog(&version)?.ok_or_else(|| {
        invalid_error(
            "spacepress_recompile_no_catalog",
            "This version has no file catalog.",
        )
    })?;
    let stage = versions.join(format!(".{version_id}.spacepress-recompile"));
    remove_any(&stage)?;
    let result = (|| {
        let files = stage.join("files");
        create_dir_all(&files)?;
        // Compile inputs only: private sources, and the public/ tree the compile reads assets from.
        let blobs = blob_root(private, space_id);
        for (path, entry) in &catalog.paths {
            if entry.public && !path.starts_with("public/") && !published_stylesheet(path) {
                continue;
            }
            validate_relative_path(path)?;
            let target = files.join(path);
            create_dir_all(target.parent().unwrap())?;
            fs::copy(blob_path(&blobs, &entry.source.sha256), &target).map_err(io_failure)?;
        }
        let inline_app_source = fs::read_to_string(files.join(CONFIG_TYPESCRIPT_FILE))
            .ok()
            .and_then(|source| extract_inline_app_config(&source));
        let kind = if catalog
            .paths
            .keys()
            .any(|path| path.starts_with(SPACEPRESS_SOURCE_PREFIX))
        {
            AppKind::Captured
        } else if catalog.paths.contains_key(SPACEPRESS_CONFIG_FILE) {
            AppKind::Raw
        } else if inline_app_source.is_some() {
            AppKind::InlineConfig
        } else {
            return not_spacepress();
        };
        let work = SourceWork::new(&stage);
        let sources = compiler_sources(kind, &work, &files, inline_app_source)?;
        let assets = work.compiler_assets(kind, &files)?;
        let store = compiled.join("store");
        let previous = baseline
            .map(|previous| seed_baseline(private, space_id, previous, &store))
            .transpose()?;
        let build =
            compiler::host::compile_to_store(&sources, &store, &assets, previous.as_deref())
                .map_err(|e| invalid_error("spacepress_compile_failed", e.message))?;
        let client =
            tree(&store.join("app").join(&build.app_hash).join("client")).map_err(io_failure)?;
        let manifest = hosted_manifest(&store, build)?;
        if without_build_identity(&manifest) != without_build_identity(&current)
            || client != tree(&compiled.join("client")).map_err(io_failure)?
        {
            return invalid(
                "spacepress_recompile_changed",
                "Compiling this version again would change what it serves. Publish it again to promote it.",
            );
        }
        if manifest != current {
            write_bytes_in_place(&manifest_path, &serde_json::to_vec(&manifest).unwrap())?;
        }
        Ok(manifest)
    })();
    remove_any(&stage)?;
    result
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
