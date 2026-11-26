//! Where a project's files live (docs/spec/spec.md §1). Paths are relative to the directory that
//! holds `spacepress.config.ts`; every reader and writer takes them from here.
//!
//! By default the SpacePress app directory is `sp/`, and projects can customize it via
//! `defineConfig({ dir: "..." })` in `spacepress.config.ts` so SpacePress coexists cleanly in repos
//! that already host another app at the root.
//!
//! - `<dir>/_generated/` is written by SpacePress and committed: the migration ledger, the type
//!   declarations, the typed client and the locks.
//! - `<dir>/dist/` is written by `spacepress build` and ignored by git: the deployable output.

use crate::diag::{self, Code, Diag, Result, Span};
use oxc_allocator::Allocator;
use oxc_ast::ast::{
    BindingPattern, ExportDefaultDeclarationKind, Expression, ObjectPropertyKind, PropertyKey, Statement,
};
use oxc_parser::{ParseOptions, Parser};
use oxc_span::{GetSpan, SourceType};
use std::path::{Path, PathBuf};

/// The config at the project root: the entry every module is reached from.
pub const CONFIG: &str = "spacepress.config.ts";
/// Default SpacePress app root directory.
pub const ROOT: &str = "sp";
/// Default generated directory (`sp/_generated`).
pub const GENERATED: &str = "sp/_generated";
/// Default type declarations that register the app's schema with `"spacepress"`.
pub const DB_TYPES: &str = "sp/_generated/db.d.ts";
/// Default typed links and params for `<dir>/app/` routes.
pub const ROUTES_TYPES: &str = "sp/_generated/routes.d.ts";
/// Default typed client output directory.
pub const CLIENT: &str = "sp/_generated/client";
/// Default OpenAPI 3.1 document path.
pub const OPENAPI: &str = "sp/_generated/openapi.json";
/// Default pinned WordPress plugin lock path.
pub const WP_PLUGINS_LOCK: &str = "sp/_generated/wp-plugins.lock.json";
/// Default build output directory (`sp/dist`), ignored by git.
pub const DIST: &str = "sp/dist";
/// Default `spacepress build` output: a `wp-content` directory.
pub const WP_CONTENT: &str = "sp/dist/wp-content";

/// Resolved project directory layout for a SpacePress app, parameterized by `defineConfig({ dir })`
/// (default `"sp"`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Layout {
    /// Relative path of the SpacePress app directory (e.g. `"sp"`, `"cms"`, `"src/sp"`).
    pub root: String,
}

impl Default for Layout {
    fn default() -> Self {
        Self { root: ROOT.to_string() }
    }
}

impl Layout {
    pub fn new(dir: impl Into<String>) -> Result<Self> {
        validate_dir(&dir.into(), Span::NONE).map(|root| Self { root })
    }

    pub fn from_dir(dir: &str, span: Span) -> Result<Self> {
        validate_dir(dir, span).map(|root| Self { root })
    }

    /// Reads `defineConfig({ dir })` from `spacepress.config.ts` on disk (defaults to `"sp"` if
    /// unset or if the file does not exist).
    pub fn from_project(project: &Path) -> Result<Self> {
        let entry = project.join(CONFIG);
        match std::fs::read_to_string(&entry) {
            Ok(source) => Self::from_config_source(&entry, &source),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Self::default()),
            Err(e) => Err(Diag::new(Span::NONE, format!("can't read {}: {e}", entry.display())).code(Code::Import)),
        }
    }

    /// Reads `defineConfig({ dir })` from a `spacepress.config.ts` source string through the given
    /// [`crate::loader::Fs`].
    pub fn from_fs(fs: &dyn crate::loader::Fs, entry: &Path) -> Result<Self> {
        match fs.read_to_string(entry) {
            Ok(source) => Self::from_config_source(entry, &source),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Self::default()),
            Err(e) => Err(Diag::new(Span::NONE, format!("can't read {}: {e}", entry.display())).code(Code::Import)),
        }
    }

    /// Extracts a static `dir` option from `spacepress.config.ts` before module discovery runs.
    pub fn from_config_source(path: &Path, source: &str) -> Result<Self> {
        let allocator = Allocator::default();
        let source_type = SourceType::from_path(path).unwrap_or_else(|_| SourceType::ts());
        let parsed = Parser::new(&allocator, source, source_type)
            .with_options(ParseOptions {
                parse_regular_expression: true,
                ..ParseOptions::default()
            })
            .parse();
        if !parsed.diagnostics.is_empty() {
            // Let the main loader report syntax errors with full diagnostics.
            return Ok(Self::default());
        }
        let file = diag::register_file(path, source);
        let span_of = |s: oxc_span::Span| Span {
            file,
            start: s.start,
            end: s.end,
        };
        let Some(config_expr) = find_default_config_object(&parsed.program.body) else {
            return Ok(Self::default());
        };
        let Expression::ObjectExpression(obj) = config_expr else {
            return Ok(Self::default());
        };
        for prop in &obj.properties {
            let ObjectPropertyKind::ObjectProperty(prop) = prop else {
                continue;
            };
            let is_dir = match &prop.key {
                PropertyKey::StaticIdentifier(id) => id.name == "dir",
                PropertyKey::StringLiteral(s) => s.value == "dir",
                _ => false,
            };
            if !is_dir {
                continue;
            }
            let val = unwrap_expr(&prop.value);
            if matches!(val, Expression::Identifier(id) if id.name == "undefined") {
                return Ok(Self::default());
            }
            match val {
                Expression::StringLiteral(lit) => {
                    return Self::from_dir(lit.value.as_str().unwrap_or(""), span_of(lit.span()));
                }
                Expression::TemplateLiteral(tpl) if tpl.expressions.is_empty() => {
                    let raw = tpl
                        .quasis
                        .first()
                        .and_then(|q| q.value.cooked.as_ref().and_then(|s| s.as_str()))
                        .unwrap_or("");
                    return Self::from_dir(raw, span_of(tpl.span()));
                }
                other => {
                    return Err(Diag::new(
                        span_of(other.span()),
                        "`dir` in defineConfig() must be a static string literal (e.g. \"sp\" or \"cms\")",
                    )
                    .help("set `dir` directly to a relative path string so SpacePress knows which folder to scan")
                    .code(Code::Schema));
                }
            }
        }
        Ok(Self::default())
    }

    pub fn root(&self) -> &str {
        &self.root
    }

    pub fn is_default(&self) -> bool {
        self.root == ROOT
    }

    pub fn rel(&self, sub: &str) -> String {
        if sub.is_empty() {
            self.root.clone()
        } else {
            format!("{}/{sub}", self.root)
        }
    }

    pub fn generated(&self) -> String {
        self.rel("_generated")
    }

    pub fn migrations(&self) -> String {
        self.rel("_generated/migrations")
    }

    pub fn old_ledger(&self) -> String {
        self.rel("migrations")
    }

    pub fn db_types(&self) -> String {
        self.rel("_generated/db.d.ts")
    }

    pub fn routes_types(&self) -> String {
        self.rel("_generated/routes.d.ts")
    }

    pub fn route_types_dir(&self) -> String {
        self.rel("_generated/types")
    }

    pub fn api_js(&self) -> String {
        self.rel("_generated/api.js")
    }

    pub fn api_types(&self) -> String {
        self.rel("_generated/api.d.ts")
    }

    pub fn client(&self) -> String {
        self.rel("_generated/client")
    }

    pub fn openapi(&self) -> String {
        self.rel("_generated/openapi.json")
    }

    pub fn wp_plugins_lock(&self) -> String {
        self.rel("_generated/wp-plugins.lock.json")
    }

    pub fn plugins_lock(&self) -> String {
        self.rel("_generated/plugins.lock.json")
    }

    pub fn abilities_lock(&self) -> String {
        self.rel("_generated/abilities.json")
    }

    pub fn runtime_schema_lock(&self) -> String {
        self.rel("_generated/runtime-schema.json")
    }

    pub fn theme_tokens(&self) -> String {
        self.rel("_generated/theme.css")
    }

    pub fn frontend_styles(&self) -> String {
        self.rel("_generated/frontend-styles.json")
    }

    pub fn theme_file(&self) -> String {
        self.rel("theme.json")
    }

    pub fn wp_hooks(&self) -> String {
        self.rel("wp-hooks")
    }

    pub fn components(&self) -> String {
        self.rel("components")
    }

    pub fn app(&self) -> String {
        self.rel("app")
    }

    pub fn dist(&self) -> String {
        self.rel("dist")
    }

    pub fn wp_content(&self) -> String {
        self.rel("dist/wp-content")
    }

    pub fn static_out(&self) -> String {
        self.rel("dist/static")
    }

    pub fn astro_content_module(&self) -> String {
        self.rel("_astro_content.ts")
    }

    pub fn root_path(&self, project: &Path) -> PathBuf {
        project.join(&self.root)
    }

    pub fn is_under_root(&self, normalized_rel: &str) -> bool {
        normalized_rel
            .strip_prefix(&self.root)
            .is_some_and(|rest| rest.starts_with('/'))
    }

    pub fn is_under_generated(&self, normalized_rel: &str) -> bool {
        let gen_prefix = format!("{}/", self.generated());
        normalized_rel.starts_with(&gen_prefix) || normalized_rel.contains(&format!("/{gen_prefix}"))
    }
}

/// Validates `defineConfig({ dir })`: a clean relative path with forward slashes, no traversal,
/// no dot-directories, and not colliding with reserved root directories (`node_modules`, `public`,
/// `emails`).
pub fn validate_dir(raw: &str, span: Span) -> Result<String> {
    let trimmed = raw.trim();
    if trimmed.is_empty() || trimmed != raw {
        return Err(Diag::new(
            span,
            format!("invalid `dir` {raw:?}: must be a non-empty relative path"),
        )
        .help("use a relative folder name like \"sp\", \"cms\", or \"src/sp\"")
        .code(Code::Schema));
    }
    if raw.starts_with('/') || raw.ends_with('/') || raw.contains('\\') || raw.contains('\0') {
        return Err(Diag::new(
            span,
            format!("invalid `dir` \"{raw}\": use a relative path without leading/trailing slashes or backslashes"),
        )
        .help("write `dir: \"cms\"` or `dir: \"src/sp\"`")
        .code(Code::Schema));
    }
    let segments: Vec<&str> = raw.split('/').collect();
    if segments.iter().any(|s| s.is_empty() || *s == "." || *s == "..") {
        return Err(Diag::new(
            span,
            format!("invalid `dir` \"{raw}\": cannot contain `.` or `..` or empty segments"),
        )
        .help("write a clean relative path inside the project, e.g. \"sp\" or \"src/sp\"")
        .code(Code::Schema));
    }
    if segments.iter().any(|s| s.starts_with('.') || *s == "node_modules") {
        return Err(Diag::new(
            span,
            format!("invalid `dir` \"{raw}\": cannot use dot-prefixed folders or node_modules"),
        )
        .help("choose a regular project directory like \"sp\" or \"cms\"")
        .code(Code::Schema));
    }
    if matches!(segments[0], "public" | "emails") {
        return Err(Diag::new(
            span,
            format!(
                "invalid `dir` \"{raw}\": `{}` is reserved by SpacePress at the project root",
                segments[0]
            ),
        )
        .help("choose another folder name like \"sp\" or \"cms\"")
        .code(Code::Schema));
    }
    Ok(raw.to_string())
}

fn unwrap_expr<'a>(mut expr: &'a Expression<'a>) -> &'a Expression<'a> {
    loop {
        match expr {
            Expression::ParenthesizedExpression(p) => expr = &p.expression,
            Expression::TSAsExpression(e) => expr = &e.expression,
            Expression::TSSatisfiesExpression(e) => expr = &e.expression,
            Expression::TSNonNullExpression(e) => expr = &e.expression,
            _ => return expr,
        }
    }
}

fn unwrap_define_config<'a>(expr: &'a Expression<'a>) -> &'a Expression<'a> {
    let expr = unwrap_expr(expr);
    if let Expression::CallExpression(call) = expr
        && let Expression::Identifier(id) = unwrap_expr(&call.callee)
        && id.name == "defineConfig"
        && let Some(first) = call.arguments.first()
        && let Some(arg_expr) = first.as_expression()
    {
        return unwrap_expr(arg_expr);
    }
    expr
}

fn find_default_config_object<'a>(body: &'a [Statement<'a>]) -> Option<&'a Expression<'a>> {
    for stmt in body {
        let Statement::ExportDefaultDeclaration(decl) = stmt else {
            continue;
        };
        let Some(expr) = (match &decl.declaration {
            ExportDefaultDeclarationKind::FunctionDeclaration(_)
            | ExportDefaultDeclarationKind::ClassDeclaration(_)
            | ExportDefaultDeclarationKind::TSInterfaceDeclaration(_) => None,
            _ => decl.declaration.as_expression(),
        }) else {
            continue;
        };
        let unwrapped = unwrap_define_config(expr);
        if matches!(unwrapped, Expression::ObjectExpression(_)) {
            return Some(unwrapped);
        }
        if let Expression::Identifier(ident) = unwrapped {
            // Look up `const config = defineConfig({ ... }); export default config;`
            for s in body {
                if let Statement::VariableDeclaration(var) = s {
                    for d in &var.declarations {
                        if let BindingPattern::BindingIdentifier(id) = &d.id
                            && id.name == ident.name
                            && let Some(init) = &d.init
                        {
                            let target = unwrap_define_config(init);
                            if matches!(target, Expression::ObjectExpression(_)) {
                                return Some(target);
                            }
                        }
                    }
                }
            }
        }
    }
    None
}

/// Committed files a live checkout may still have at their place before `sp/_generated/`: (old,
/// new, what goes wrong if it is left there). Refuse these paths before loading sources so stale generated files cannot be used.
const OLD_IN_CHECKOUT: &[(&str, &str, &str)] = &[
    (
        "sp/plugins.lock.json",
        "sp/_generated/plugins.lock.json",
        "its grants would be ignored and a new lock written that grants everything the plugins use",
    ),
    (
        "sp/wp-plugins.lock.json",
        "sp/_generated/wp-plugins.lock.json",
        "the plugins it pins would be resolved again",
    ),
    (
        "sp/abilities.json",
        "sp/_generated/abilities.json",
        "the abilities it locks would be unknown",
    ),
    (
        "sp/client",
        "sp/_generated/client",
        "its files would load as app code from sp/",
    ),
    (
        "spacepress.gen.ts",
        "sp/_generated/db.d.ts",
        "editors would read the old registration",
    ),
];

/// Every committed file a checkout still keeps at its place from before `sp/_generated/`, in one
/// (message, help): the message names each file and what goes wrong if it stays, and the help is
/// one command that moves them all. Callers refuse to build: see [`OLD_IN_CHECKOUT`].
pub fn old_in_checkout(
    exists: impl Fn(&std::path::Path) -> bool,
    project: &std::path::Path,
) -> Option<(String, String)> {
    old_in_checkout_for(exists, project, &Layout::default())
}

pub fn old_in_checkout_for(
    exists: impl Fn(&std::path::Path) -> bool,
    project: &std::path::Path,
    layout: &Layout,
) -> Option<(String, String)> {
    let entries: Vec<(String, String, &str)> = if layout.is_default() {
        OLD_IN_CHECKOUT
            .iter()
            .map(|(old, new, why)| ((*old).to_string(), (*new).to_string(), *why))
            .collect()
    } else {
        vec![(
            "spacepress.gen.ts".to_string(),
            layout.db_types(),
            "editors would read the old registration",
        )]
    };
    let found: Vec<_> = entries.iter().filter(|(old, ..)| exists(&project.join(old))).collect();
    if found.is_empty() {
        return None;
    }
    let lines: Vec<String> = found
        .iter()
        .map(|(old, new, why)| format!("{old} moved to {new}; left where it is, {why}"))
        .collect();
    let message = match lines.as_slice() {
        [one] => one.clone(),
        many => format!(
            "{} files are from before {}/:\n  - {}",
            many.len(),
            layout.generated(),
            many.join("\n  - ")
        ),
    };
    let mut parents: Vec<String> = found
        .iter()
        .map(|(_, new, _)| {
            std::path::Path::new(new)
                .parent()
                .expect("under _generated")
                .display()
                .to_string()
        })
        .collect();
    parents.dedup();
    let moves: Vec<String> = found
        .iter()
        .map(|(old, new, _)| format!("git mv {old} {new}"))
        .collect();
    let help = format!(
        "move {} with `mkdir -p {} && {}`, then build again",
        if found.len() == 1 { "it" } else { "them" },
        parents.join(" "),
        moves.join(" && ")
    );
    Some((message, help))
}
