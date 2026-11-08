//! Finds, parses, and lowers every module reachable from `zero.config.ts`.
//! Resolves local sources and installed source packages; never installs or executes package code.

use crate::diag::{self, Diag, Result, Span};
use crate::ir::{ArrEl, DeclKind, Expr, ExprKind, FnBody, Func, Module, Pat, Prop, PropKey, Stmt};
use crate::lower::Lowerer;
use oxc_allocator::Allocator;
use oxc_parser::{ParseOptions, Parser};
use oxc_span::SourceType;
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::rc::Rc;

pub const ZERO_MODULE: &str = "zero";
/// WordPress core from TypeScript: abilities, hook types and functions (docs/decisions/2026-11-07-wordpress-from-typescript.md).
pub const WORDPRESS_MODULE: &str = "php/wordpress";

/// Modules the compiler provides itself; nothing is loaded for them. Node built-ins come from the
/// one lookup, `builtins::module`.
pub fn builtin_module(spec: &str) -> bool {
    spec == ZERO_MODULE || spec == WORDPRESS_MODULE || crate::builtins::module(spec).is_some()
}

/// Where sources come from: the project directory for the CLI, memory for the browser build. Paths
/// are absolute either way, so diagnostics and module identity work the same.
pub trait Fs {
    fn read_dir(&self, _path: &Path) -> std::io::Result<Vec<PathBuf>> {
        Err(std::io::Error::other("directory listing is unavailable"))
    }
    fn is_symlink(&self, _path: &Path) -> bool {
        false
    }
    fn allow_packages(&self) -> bool {
        true
    }
    fn read_to_string(&self, path: &Path) -> std::io::Result<String>;
    fn is_file(&self, path: &Path) -> bool;
    fn is_dir(&self, path: &Path) -> bool;
    /// Whether anything at all (a file, a directory, a dangling link) is at `path`.
    fn exists(&self, path: &Path) -> bool;
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf>;
}

/// The real file system.
pub struct Disk;

impl Fs for Disk {
    fn read_dir(&self, path: &Path) -> std::io::Result<Vec<PathBuf>> {
        std::fs::read_dir(path)?.map(|e| e.map(|e| e.path())).collect()
    }
    fn is_symlink(&self, path: &Path) -> bool {
        std::fs::symlink_metadata(path).is_ok_and(|m| m.is_symlink())
    }
    fn read_to_string(&self, path: &Path) -> std::io::Result<String> {
        std::fs::read_to_string(path)
    }
    fn is_file(&self, path: &Path) -> bool {
        path.is_file()
    }
    fn is_dir(&self, path: &Path) -> bool {
        path.is_dir()
    }
    fn exists(&self, path: &Path) -> bool {
        std::fs::symlink_metadata(path).is_ok()
    }
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf> {
        path.canonicalize()
    }
}

pub struct Program {
    pub modules: Vec<Rc<Module>>,
    /// (module id, specifier) → resolved module id.
    pub resolved: HashMap<(usize, String), usize>,
    pub entry: usize,
    /// The source of these modules; later passes that look beside them (sync directories) ask it.
    pub fs: Rc<dyn Fs>,
    /// `wp-hooks/` handler files beside the entry, in registration order (see [`wp_hook_files`]).
    pub wp_hooks: Vec<WpHookFile>,
}

/// One `wp-hooks/` file: `wp-hooks/<hook>.ts`, or `wp-hooks/<hook>/<file>.ts`.
#[derive(Debug, Clone)]
pub struct WpHookFile {
    pub hook: String,
    pub module: usize,
    /// Position among the files of its hook, from 0 in alphabetical order.
    pub order: usize,
}

pub fn load(entry: &Path) -> Result<Program> {
    load_all(entry).map_err(|diagnostics| diagnostics.into_iter().next().expect("failed load has diagnostics"))
}

pub fn load_all(entry: &Path) -> std::result::Result<Program, Vec<Diag>> {
    load_from(Rc::new(Disk), entry)
}

pub fn load_from(fs: Rc<dyn Fs>, entry: &Path) -> std::result::Result<Program, Vec<Diag>> {
    let mut program = Program {
        modules: Vec::new(),
        resolved: HashMap::new(),
        entry: 0,
        fs: fs.clone(),
        wp_hooks: Vec::new(),
    };
    let mut by_path: HashMap<PathBuf, usize> = HashMap::new();
    let entry = fs
        .canonicalize(entry)
        .map_err(|e| vec![Diag::new(Span::NONE, format!("{}: {e}", entry.display())).code(diag::Code::Import)])?;
    let mut diagnostics = Vec::new();
    match load_module(&*fs, &entry, &mut program, &mut by_path, &mut diagnostics) {
        Ok(id) => program.entry = id,
        Err(d) => diagnostics.push(d.phase(diag::Code::Import)),
    }
    let dir = entry.parent().expect("entry has a directory").join(WP_HOOKS_DIR);
    match wp_hook_files(&*fs, &dir) {
        Ok(files) => {
            for (hook, order, path) in files {
                match load_module(&*fs, &path, &mut program, &mut by_path, &mut diagnostics) {
                    Ok(module) => program.wp_hooks.push(WpHookFile { hook, module, order }),
                    Err(d) => diagnostics.push(d.phase(diag::Code::Import)),
                }
            }
        }
        Err(d) => diagnostics.push(d.phase(diag::Code::Import)),
    }
    if diagnostics.is_empty() {
        Ok(program)
    } else {
        Err(diagnostics)
    }
}

/// The WordPress hooks directory beside `zero.config.ts`.
pub const WP_HOOKS_DIR: &str = "wp-hooks";

/// `wp-hooks/<hook>.ts` and `wp-hooks/<hook>/*.ts`, as `(hook, order, path)` sorted by hook, then by
/// file name. Anything else in the directory is an error that names it: a non-`.ts` file would
/// otherwise be silently skipped, and the author would wonder why their handler never runs.
pub fn wp_hook_files(fs: &dyn Fs, dir: &Path) -> Result<Vec<(String, usize, PathBuf)>> {
    if !fs.exists(dir) {
        return Ok(Vec::new());
    }
    let listing = |d: &Path| -> Result<Vec<PathBuf>> {
        let mut entries = fs
            .read_dir(d)
            .map_err(|e| Diag::new(Span::NONE, format!("can't read {}: {e}", d.display())))?;
        entries.sort();
        Ok(entries)
    };
    // Located at the offending path (registered with no source), so `check --json` names the file.
    let refuse = |path: &Path, why: &str| {
        let file = diag::register_file(path, "");
        Diag::new(Span { file, start: 0, end: 0 }, why.to_string()).help(
            "wp-hooks/ holds <hook_name>.ts files, or <hook_name>/ directories of .ts files, each default-exporting its handler",
        )
    };
    let ts =
        |p: &Path| p.extension().is_some_and(|e| e == "ts") && !p.to_string_lossy().ends_with(".d.ts") && fs.is_file(p);
    let mut out = Vec::new();
    for path in listing(dir)? {
        let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
        if fs.is_dir(&path) {
            for (order, file) in listing(&path)?.into_iter().enumerate() {
                if !ts(&file) {
                    return Err(refuse(&file, "not a .ts handler file"));
                }
                out.push((name.clone(), order, file));
            }
        } else if ts(&path) {
            out.push((name.trim_end_matches(".ts").to_string(), 0, path));
        } else {
            return Err(refuse(&path, "not a .ts handler file"));
        }
    }
    // A hook both as a file and as a directory would have two independent orders.
    let mut seen: HashMap<&str, &Path> = HashMap::new();
    for (hook, _, path) in &out {
        let parent = path.parent().expect("listed under wp-hooks/");
        if seen.insert(hook, parent).is_some_and(|p| p != parent) {
            return Err(refuse(
                path,
                &format!("`{hook}` is both a file and a directory; keep one"),
            ));
        }
    }
    Ok(out)
}

fn load_module(
    fs: &dyn Fs,
    path: &Path,
    program: &mut Program,
    by_path: &mut HashMap<PathBuf, usize>,
    diagnostics: &mut Vec<Diag>,
) -> Result<usize> {
    if let Some(&id) = by_path.get(path) {
        return Ok(id);
    }
    let source = fs
        .read_to_string(path)
        .map_err(|e| Diag::new(Span::NONE, format!("can't read {}: {e}", path.display())))?;
    let file = diag::register_file(path, &source);
    let id = program.modules.len();
    by_path.insert(path.to_path_buf(), id);
    let Parsed {
        body,
        commonjs,
        type_names,
    } = parse(fs, &source, path, file, id, diagnostics)?;
    let mut imports: Vec<(String, Span)> = body.iter().filter_map(import_source).collect();
    let first_require = imports.len();
    let commonjs = commonjs.map(|CommonJs { names, requires }| {
        imports.extend(requires);
        names
    });
    program.modules.push(Rc::new(Module {
        id,
        file,
        path: path.to_path_buf(),
        body,
        commonjs,
        dynamic: crate::ir::dynamic_source(path),
        type_names,
    }));
    for (index, (spec, span)) in imports.into_iter().enumerate() {
        if builtin_module(&spec) {
            continue;
        }
        if spec == "php" || spec.starts_with("php/") {
            diagnostics.push(
                Diag::new(span, format!("\"{spec}\" isn't available"))
                    .help("WordPress core is \"php/wordpress\"; plugin modules (\"php/<plugin>\") and PHP built-ins (\"php\") are not available yet")
                    .code(diag::Code::Import),
            );
            continue;
        }
        let required = index >= first_require;
        let target = if required {
            resolve_require(fs, path, &spec, span)
        } else {
            resolve(fs, path, &spec, span)
        };
        match target.and_then(|target| load_module(fs, &target, program, by_path, diagnostics)) {
            Ok(target_id) => {
                program.resolved.insert((id, spec), target_id);
            }
            Err(d) => diagnostics.push(d.phase(diag::Code::Import)),
        }
    }
    Ok(id)
}

fn import_source(s: &Stmt) -> Option<(String, Span)> {
    match s {
        Stmt::Import {
            source,
            specs,
            type_only,
            span,
        } => {
            // Type-only imports are erased; they never load code.
            if *type_only || (!specs.is_empty() && specs.iter().all(|s| s.type_only)) {
                if source.starts_with('.') {
                    Some((source.clone(), *span))
                } else {
                    None
                }
            } else {
                Some((source.clone(), *span))
            }
        }
        Stmt::ExportNames {
            from: Some(from), span, ..
        }
        | Stmt::ExportAll { from, span } => Some((from.clone(), *span)),
        _ => None,
    }
}

fn parse(
    fs: &dyn Fs,
    source: &str,
    path: &Path,
    file: u32,
    module: usize,
    diagnostics: &mut Vec<Diag>,
) -> Result<Parsed> {
    let allocator = Allocator::default();
    let source_type = SourceType::from_path(path).unwrap_or_else(|_| SourceType::ts());
    if source_type.is_jsx() {
        return Err(Diag::new(
            Span { file, start: 0, end: 0 },
            "JSX files can't be part of a Zero config",
        ));
    }
    let ret = Parser::new(&allocator, source, source_type)
        .with_options(ParseOptions {
            parse_regular_expression: true,
            ..ParseOptions::default()
        })
        .parse();
    let mut syntax_failed = false;
    for err in ret.diagnostics.errors() {
        syntax_failed = true;
        let span = err
            .labels
            .first()
            .map(|l| Span {
                file,
                start: l.offset(),
                end: l.offset() + l.len(),
            })
            .unwrap_or(Span { file, start: 0, end: 0 });
        diagnostics.push(Diag::new(span, format!("syntax error: {}", err.message)).code(diag::Code::Syntax));
    }
    if syntax_failed {
        return Ok(Parsed::default());
    }
    let lowerer = Lowerer {
        file,
        module,
        class_context: std::cell::Cell::new(false),
        super_call: std::cell::Cell::new(false),
        erase_types: source_type.is_typescript() && crate::ir::dynamic_source(path),
        erased_types: Default::default(),
    };
    let (body, errors) = lowerer.program_diagnostics(&ret.program);
    let mut commonjs = None;
    if errors.is_empty() {
        commonjs = commonjs_module(fs, path, &body)?;
    }
    diagnostics.extend(errors);
    Ok(Parsed {
        body,
        commonjs,
        type_names: lowerer.erased_types.into_inner(),
    })
}

fn commonjs_error(span: Span, reason: &str) -> Diag {
    Diag::new(span, format!("unsupported CommonJS source: {reason}"))
        .code(diag::Code::NpmImport)
        .help("use static CommonJS: top-level `require(\"<literal>\")`, and `module.exports = …` or `exports.name = …` outside functions; Node wrapper globals and dynamic require() are unavailable")
}

#[derive(Default)]
struct Parsed {
    body: Vec<Stmt>,
    commonjs: Option<CommonJs>,
    type_names: std::collections::HashSet<String>,
}

/// What the loader learned about a CommonJS source.
struct CommonJs {
    /// Named exports for ESM importers, like Node's cjs-module-lexer finds them.
    names: Vec<String>,
    /// Every `require("<literal>")`, resolved and loaded like an import.
    requires: Vec<(String, Span)>,
}

fn is_ident(expression: &Expr, name: &str) -> bool {
    matches!(&expression.kind, ExprKind::Ident(found) if found == name)
}

/// `module.exports`, without optional chaining.
fn is_module_exports(expression: &Expr) -> bool {
    matches!(&expression.kind, ExprKind::Member { obj, prop, optional: false, .. }
        if prop == "exports" && is_ident(obj, "module"))
}

/// The object a write lands on when its root is `module` or `exports`.
fn wrapper_root(expression: &Expr) -> Option<&'static str> {
    match &expression.kind {
        ExprKind::Ident(name) if name == "module" => Some("module"),
        ExprKind::Ident(name) if name == "exports" => Some("exports"),
        ExprKind::Member { obj, .. } | ExprKind::Index { obj, .. } => wrapper_root(obj),
        _ => None,
    }
}

/// Top-level `exports.name = …`, `module.exports.name = …` and the keys of
/// `module.exports = { … }`. Names are kept only while `module.exports` stays that object:
/// after `module.exports = f`, a function, Node's named exports are its own properties.
fn commonjs_names(body: &[Stmt]) -> Vec<String> {
    let mut names = Vec::new();
    let mut replaced = false;
    for statement in body {
        let Stmt::Expr(Expr {
            kind:
                ExprKind::Assign {
                    op: None,
                    target,
                    value,
                },
            ..
        }) = statement
        else {
            continue;
        };
        if is_module_exports(target) {
            match &value.kind {
                ExprKind::Object(properties) => {
                    names.clear();
                    for property in properties {
                        match property {
                            Prop::KV(PropKey::Static(key), _) => names.push(key.clone()),
                            _ => replaced = true,
                        }
                    }
                }
                _ => replaced = true,
            }
        } else if let ExprKind::Member { obj, prop, .. } = &target.kind
            && (is_ident(obj, "exports") || is_module_exports(obj))
        {
            names.push(prop.clone());
        }
    }
    if replaced {
        return Vec::new();
    }
    let mut seen = std::collections::HashSet::new();
    names.retain(|name| name != "default" && name != "__esModule" && seen.insert(name.clone()));
    names
}

fn esm_package_source(fs: &dyn Fs, path: &Path, span: Span) -> Result<bool> {
    for directory in path.parent().into_iter().flat_map(Path::ancestors) {
        let manifest_path = directory.join("package.json");
        if !fs.is_file(&manifest_path) {
            continue;
        }
        let source = fs
            .read_to_string(&manifest_path)
            .map_err(|error| commonjs_error(span, &format!("can't read {}: {error}", manifest_path.display())))?;
        let manifest: serde_json::Value = serde_json::from_str(&source)
            .map_err(|error| commonjs_error(span, &format!("invalid {}: {error}", manifest_path.display())))?;
        let module_target = manifest
            .get("module")
            .and_then(|value| value.as_str())
            .and_then(|target| fs.canonicalize(&directory.join(target)).ok());
        return Ok(manifest.get("type").and_then(|value| value.as_str()) == Some("module")
            || module_target.as_deref() == Some(path));
    }
    Ok(false)
}

/// A `.cjs` source, or a `.js` source with no ESM syntax outside an ESM package, is CommonJS.
/// Its body is kept as written: the evaluator supplies the module wrapper bindings and runs each
/// static `require` at its call, as Node does. This checks that nothing needs the wrapper at
/// request time and collects the `require` specifiers to load.
fn commonjs_module(fs: &dyn Fs, path: &Path, body: &[Stmt]) -> Result<Option<CommonJs>> {
    let has_esm = body.iter().any(|statement| {
        matches!(
            statement,
            Stmt::Import { .. }
                | Stmt::ExportNames { .. }
                | Stmt::ExportAll { .. }
                | Stmt::Exported(_)
                | Stmt::ExportDefault(_)
        )
    });
    let first_export = body.iter().find_map(|statement| match statement {
        Stmt::Expr(Expr {
            kind: ExprKind::Assign { target, .. },
            span,
        }) if is_module_exports(target) => Some(*span),
        _ => None,
    });
    let extension = path.extension().and_then(|extension| extension.to_str());
    let esm_package = extension == Some("js")
        && (first_export.is_some() || !has_esm)
        && esm_package_source(fs, path, first_export.unwrap_or(Span::NONE))?;
    if let Some(span) = first_export {
        if !matches!(extension, Some("js" | "cjs")) {
            return Err(commonjs_error(
                span,
                "module.exports is only supported in .js or .cjs sources",
            ));
        }
        if esm_package {
            return Err(commonjs_error(
                span,
                "module.exports is unavailable in an ESM package source",
            ));
        }
        if has_esm {
            return Err(commonjs_error(span, "CommonJS and ESM declarations cannot be mixed"));
        }
    }
    if has_esm || esm_package || !matches!(extension, Some("js" | "cjs")) {
        return Ok(None);
    }
    let requires = std::cell::RefCell::new(Vec::new());
    CommonJsScope {
        arguments_bound: false,
        in_function: false,
        requires: &requires,
    }
    .block(body)?;
    Ok(Some(CommonJs {
        names: commonjs_names(body),
        requires: requires.into_inner(),
    }))
}

fn validate_commonjs_name(name: &str, span: Span) -> Result<()> {
    if matches!(name, "module" | "exports" | "require" | "__filename" | "__dirname") {
        return Err(commonjs_error(
            span,
            &format!("Node wrapper name `{name}` can't be declared"),
        ));
    }
    Ok(())
}

fn binds_arguments(pattern: &Pat) -> bool {
    let mut names = Vec::new();
    pattern.bindings(&mut names);
    names.iter().any(|(name, _)| *name == "arguments")
}

fn declares_lexical_arguments(statement: &Stmt) -> bool {
    match statement {
        Stmt::Decl { kind, pat, .. } => *kind != DeclKind::Var && binds_arguments(pat),
        Stmt::Func(function) => function.name.as_deref() == Some("arguments"),
        _ => false,
    }
}

/// Sloppy CommonJS `arguments` can alias parameters. Until strictness is represented in IR, only
/// explicit lexical/parameter bindings named `arguments` are accepted in static CommonJS sources.
#[derive(Clone, Copy)]
struct CommonJsScope<'a> {
    arguments_bound: bool,
    /// Inside a function body, which runs in PHP at request time.
    in_function: bool,
    requires: &'a std::cell::RefCell<Vec<(String, Span)>>,
}

impl CommonJsScope<'_> {
    fn block(self, body: &[Stmt]) -> Result<()> {
        let scope = Self {
            arguments_bound: self.arguments_bound || body.iter().any(declares_lexical_arguments),
            ..self
        };
        for statement in body {
            scope.statement(statement)?;
        }
        Ok(())
    }

    fn pattern(self, pattern: &Pat) -> Result<()> {
        match pattern {
            Pat::Id(name, span) => validate_commonjs_name(name, *span),
            Pat::Obj { props, rest, .. } => {
                for (_, pattern) in props {
                    self.pattern(pattern)?;
                }
                if let Some((name, span)) = rest {
                    validate_commonjs_name(name, *span)?;
                }
                Ok(())
            }
            Pat::Arr { elems, rest, .. } => {
                for pattern in elems.iter().flatten() {
                    self.pattern(pattern)?;
                }
                if let Some((name, span)) = rest {
                    validate_commonjs_name(name, *span)?;
                }
                Ok(())
            }
            Pat::Default(pattern, value) => {
                self.pattern(pattern)?;
                self.expression(value)
            }
        }
    }

    fn function(self, function: &Func) -> Result<()> {
        if let Some(name) = &function.name {
            validate_commonjs_name(name, function.span)?;
        }
        let scope = Self {
            arguments_bound: (function.is_arrow && self.arguments_bound)
                || function.params.iter().any(|parameter| binds_arguments(&parameter.pat)),
            in_function: true,
            ..self
        };
        for parameter in &function.params {
            scope.pattern(&parameter.pat)?;
            if let Some(value) = &parameter.default {
                scope.expression(value)?;
            }
        }
        match &function.body {
            FnBody::Expr(expression) => scope.expression(expression),
            FnBody::Block(body) => scope.block(body),
        }
    }

    fn statement(self, statement: &Stmt) -> Result<()> {
        match statement {
            Stmt::Decl { pat, init, .. } => {
                self.pattern(pat)?;
                if let Some(value) = init {
                    self.expression(value)?;
                }
            }
            Stmt::Func(function) => self.function(function)?,
            Stmt::Expr(expression) | Stmt::Throw(expression) | Stmt::ExportDefault(expression) => {
                self.expression(expression)?
            }
            Stmt::Return(expression, _) => {
                if let Some(expression) = expression {
                    self.expression(expression)?;
                }
            }
            Stmt::If(test, yes, no) => {
                self.expression(test)?;
                self.block(yes)?;
                if let Some(no) = no {
                    self.block(no)?;
                }
            }
            Stmt::For {
                init,
                test,
                update,
                body,
                ..
            } => {
                let scope = Self {
                    arguments_bound: self.arguments_bound || init.as_deref().is_some_and(declares_lexical_arguments),
                    ..self
                };
                if let Some(init) = init {
                    scope.statement(init)?;
                }
                for expression in test.iter().chain(update) {
                    scope.expression(expression)?;
                }
                scope.block(body)?;
            }
            Stmt::ForOf {
                kind, pat, iter, body, ..
            } => {
                let scope = Self {
                    arguments_bound: self.arguments_bound || (*kind != DeclKind::Var && binds_arguments(pat)),
                    ..self
                };
                scope.pattern(pat)?;
                scope.expression(iter)?;
                scope.block(body)?;
            }
            Stmt::ForIn {
                kind,
                target,
                iter,
                body,
                ..
            } => {
                let scope = Self {
                    arguments_bound: self.arguments_bound
                        || (matches!(kind, Some(DeclKind::Let | DeclKind::Const))
                            && matches!(&target.kind, ExprKind::Ident(name) if name == "arguments")),
                    ..self
                };
                scope.expression(target)?;
                scope.expression(iter)?;
                scope.block(body)?;
            }
            Stmt::While(test, body) | Stmt::DoWhile(body, test) => {
                self.expression(test)?;
                self.block(body)?;
            }
            Stmt::Labeled(_, body, _) => self.block(std::slice::from_ref(body))?,
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                self.block(block)?;
                if let Some((name, span)) = param {
                    validate_commonjs_name(name, *span)?;
                }
                if let Some(handler) = handler {
                    Self {
                        arguments_bound: self.arguments_bound
                            || param.as_ref().is_some_and(|(name, _)| name == "arguments"),
                        ..self
                    }
                    .block(handler)?;
                }
                if let Some(finalizer) = finalizer {
                    self.block(finalizer)?;
                }
            }
            Stmt::Switch { disc, cases, .. } => {
                self.expression(disc)?;
                let scope = Self {
                    arguments_bound: self.arguments_bound
                        || cases
                            .iter()
                            .any(|(_, body)| body.iter().any(declares_lexical_arguments)),
                    ..self
                };
                for (test, body) in cases {
                    if let Some(test) = test {
                        scope.expression(test)?;
                    }
                    scope.block(body)?;
                }
            }
            Stmt::Block(body) => self.block(body)?,
            Stmt::Exported(statement) => self.statement(statement)?,
            Stmt::Import { .. }
            | Stmt::ExportNames { .. }
            | Stmt::ExportAll { .. }
            | Stmt::TypeAlias { .. }
            | Stmt::Break(..)
            | Stmt::Continue(..) => {}
        }
        Ok(())
    }

    fn expression(self, expression: &Expr) -> Result<()> {
        match &expression.kind {
            ExprKind::Ident(name) => {
                match name.as_str() {
                    // `module.exports` is matched by its member expression below.
                    "module" => {
                        return Err(commonjs_error(
                            expression.span,
                            "`module` is only available as `module.exports`",
                        ));
                    }
                    "exports" => {}
                    "require" => {
                        return Err(commonjs_error(
                            expression.span,
                            "`require` must be called with one string literal",
                        ));
                    }
                    "__filename" | "__dirname" => {
                        return Err(commonjs_error(
                            expression.span,
                            &format!("Node wrapper name `{name}` is unavailable"),
                        ));
                    }
                    _ => {}
                }
                if name == "arguments" && !self.arguments_bound {
                    return Err(commonjs_error(
                        expression.span,
                        "implicit arguments can alias parameters in CommonJS; only explicit bindings named arguments are supported",
                    ));
                }
            }
            ExprKind::Member { obj, .. } if is_ident(obj, "module") => {
                if !is_module_exports(expression) {
                    return Err(commonjs_error(
                        expression.span,
                        "`module` is only available as `module.exports`",
                    ));
                }
            }
            ExprKind::Call { callee, args, .. } if is_ident(callee, "require") => {
                let [
                    ArrEl::Item(Expr {
                        kind: ExprKind::Str(spec),
                        ..
                    }),
                ] = args.as_slice()
                else {
                    return Err(commonjs_error(
                        expression.span,
                        "dynamic require() is unavailable; require one string literal",
                    ));
                };
                if self.in_function {
                    return Err(commonjs_error(
                        expression.span,
                        "require() inside a function would load at request time; move it to the top level",
                    ));
                }
                self.requires.borrow_mut().push((spec.clone(), expression.span));
            }
            ExprKind::Assign { target, .. } | ExprKind::Update { target, .. } if wrapper_root(target).is_some() => {
                let root = wrapper_root(target).expect("matched above");
                if self.in_function {
                    return Err(commonjs_error(
                        expression.span,
                        &format!(
                            "writing to `{root}` inside a function would change a build-time value at request time"
                        ),
                    ));
                }
                if is_ident(target, root) {
                    return Err(commonjs_error(
                        expression.span,
                        &format!("`{root}` can't be reassigned; assign `module.exports` instead"),
                    ));
                }
                self.expression(target)?;
                if let ExprKind::Assign { value, .. } = &expression.kind {
                    self.expression(value)?;
                }
            }
            ExprKind::Func(function) => self.function(function)?,
            ExprKind::Class(class) => {
                validate_commonjs_name(&class.name, class.span)?;
                if let Some(base) = &class.base {
                    validate_commonjs_name(base, class.span)?;
                }
                for field in &class.fields {
                    if let Some(value) = &field.init {
                        self.expression(value)?;
                    }
                }
                for (_, _, method) in &class.methods {
                    self.function(method)?;
                }
                if let Some(constructor) = &class.constructor {
                    self.function(constructor)?;
                }
                for accessor in &class.accessors {
                    self.function(&accessor.func)?;
                }
            }
            ExprKind::Array(items) => {
                for ArrEl::Item(value) | ArrEl::Spread(value) in items {
                    self.expression(value)?;
                }
            }
            ExprKind::Object(properties) => {
                for property in properties {
                    match property {
                        Prop::KV(key, value) => {
                            if let PropKey::Computed(key) = key {
                                self.expression(key)?;
                            }
                            self.expression(value)?;
                        }
                        Prop::Spread(value) => self.expression(value)?,
                    }
                }
            }
            ExprKind::Template(_, expressions) | ExprKind::Sequence(expressions) => {
                for expression in expressions {
                    self.expression(expression)?;
                }
            }
            ExprKind::Unary(_, value)
            | ExprKind::Delete(value)
            | ExprKind::IsNullish(value, _)
            | ExprKind::Update { target: value, .. }
            | ExprKind::Member { obj: value, .. }
            | ExprKind::Chain(value)
            | ExprKind::Await(value)
            | ExprKind::Yield { arg: Some(value), .. }
            | ExprKind::As(value, _)
            | ExprKind::NonNull(value) => self.expression(value)?,
            ExprKind::Yield { arg: None, .. } => {}
            ExprKind::Instanceof(left, right)
            | ExprKind::In(left, right)
            | ExprKind::Binary(_, left, right)
            | ExprKind::Logical(_, left, right)
            | ExprKind::Assign {
                target: left,
                value: right,
                ..
            }
            | ExprKind::Index {
                obj: left,
                index: right,
                ..
            } => {
                self.expression(left)?;
                self.expression(right)?;
            }
            ExprKind::Cond(test, yes, no) => {
                for expression in [test, yes, no] {
                    self.expression(expression)?;
                }
            }
            ExprKind::AssignPattern(pattern, value) => {
                for expression in pattern.exprs() {
                    self.expression(expression)?;
                }
                self.expression(value)?;
            }
            ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args, .. } => {
                self.expression(callee)?;
                for ArrEl::Item(value) | ArrEl::Spread(value) in args {
                    self.expression(value)?;
                }
            }
            ExprKind::Num(_)
            | ExprKind::Str(_)
            | ExprKind::Bool(_)
            | ExprKind::Null
            | ExprKind::Undefined
            | ExprKind::Regex { .. }
            | ExprKind::BigInt(_)
            | ExprKind::TemplateStrings { .. } => {}
        }
        Ok(())
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Conditions {
    /// `import`, then `default`; a `module` entry is used before `main`.
    Import,
    /// `require`, then `default`; `module` entries are ignored, as Node does.
    Require,
}

fn resolve(fs: &dyn Fs, from: &Path, spec: &str, span: Span) -> Result<PathBuf> {
    resolve_with(fs, from, spec, span, Conditions::Import)
}

/// `require("<spec>")` from a CommonJS source. The target must be CommonJS too: `require(esm)`
/// returns a namespace object, which Zero doesn't model.
fn resolve_require(fs: &dyn Fs, from: &Path, spec: &str, span: Span) -> Result<PathBuf> {
    let target = resolve_with(fs, from, spec, span, Conditions::Require)?;
    let extension = target.extension().and_then(|extension| extension.to_str());
    let esm = match extension {
        Some("cjs") => false,
        Some("js") => esm_package_source(fs, &target, span)?,
        _ => true,
    };
    if esm {
        return Err(commonjs_error(
            span,
            &format!("require(\"{spec}\") resolves to an ES module; import it from an ES module instead"),
        ));
    }
    Ok(target)
}

fn resolve_with(fs: &dyn Fs, from: &Path, spec: &str, span: Span, conditions: Conditions) -> Result<PathBuf> {
    if !spec.starts_with('.') && !fs.allow_packages() {
        return Err(
            Diag::new(span, format!("npm import {spec:?} needs local source capture"))
                .code(diag::Code::SourceNeedsLocalCapture)
                .help("publish this project from the Spacefast CLI to capture installed npm sources"),
        );
    }
    if !spec.starts_with("./") && !spec.starts_with("../") {
        return resolve_package(fs, from, spec, span, conditions);
    }
    let base = from.parent().expect("file has a directory").join(spec);
    resolve_source(fs, &base, spec, span)
}

fn resolve_source(fs: &dyn Fs, base: &Path, spec: &str, span: Span) -> Result<PathBuf> {
    let stripped = base.with_extension("");
    // `./util.js` conventionally names `./util.ts` in TypeScript ESM.
    let mut candidates = Vec::new();
    if matches!(base.extension().and_then(|e| e.to_str()), Some("js" | "mjs")) {
        candidates.push(stripped.with_extension("ts"));
        candidates.push(stripped.with_extension("mts"));
    }
    candidates.extend([
        base.to_path_buf(),
        base.with_extension("ts"),
        base.with_extension("mts"),
        base.with_extension("mjs"),
        base.with_extension("js"),
        base.with_extension("cjs"),
        base.join("index.ts"),
        base.join("index.mts"),
        base.join("index.mjs"),
        base.join("index.js"),
        base.join("index.cjs"),
    ]);
    for c in candidates {
        if fs.is_file(&c)
            && !c.to_string_lossy().ends_with(".d.ts")
            && matches!(
                c.extension().and_then(|e| e.to_str()),
                Some("ts" | "mts" | "js" | "mjs" | "cjs")
            )
        {
            return fs.canonicalize(&c).map_err(|e| Diag::new(span, e.to_string()));
        }
    }
    if fs.is_file(base) {
        return Err(Diag::new(
            span,
            format!("can't import \"{spec}\": expected TypeScript or JavaScript source"),
        ));
    }
    Err(Diag::new(span, format!("can't find \"{spec}\"")))
}

fn package_error(spec: &str, span: Span, reason: &str) -> Diag {
    Diag::new(span, format!("can't import \"{spec}\": {reason}"))
        .code(diag::Code::NpmImport)
        .help(if spec.starts_with("node:") {
            "handlers run in PHP: use the global fetch, process.env.NAME and \"node:crypto\""
        } else {
            "install a source package locally; its TypeScript, ESM, or static CommonJS source must fit Zero's PHP compiler subset"
        })
}

fn resolve_package(fs: &dyn Fs, from: &Path, spec: &str, span: Span, conditions: Conditions) -> Result<PathBuf> {
    let parts: Vec<_> = spec.split('/').collect();
    let count = if spec.starts_with('@') { 2 } else { 1 };
    if parts.len() < count
        || parts.iter().any(|p| p.is_empty() || *p == "." || *p == "..")
        || spec.contains([':', '\\', '%', '#', '?', '*'])
        || (spec.starts_with('@') && parts[0].len() == 1)
    {
        return Err(package_error(
            spec,
            span,
            if spec.starts_with("node:") {
                "Node built-ins other than node:crypto are unavailable"
            } else {
                "unsupported package specifier (package aliases are unavailable)"
            },
        ));
    }
    let name = parts[..count].join("/");
    let subpath = if parts.len() == count {
        ".".to_string()
    } else {
        format!("./{}", parts[count..].join("/"))
    };
    let package = from
        .parent()
        .into_iter()
        .flat_map(Path::ancestors)
        .filter(|dir| dir.file_name().is_none_or(|name| name != "node_modules"))
        .map(|dir| dir.join("node_modules").join(&name))
        .find(|dir| fs.is_file(&dir.join("package.json")))
        .ok_or_else(|| package_error(spec, span, "package is not installed in node_modules"))?;
    let source = fs
        .read_to_string(&package.join("package.json"))
        .map_err(|e| package_error(spec, span, &format!("can't read package.json: {e}")))?;
    let manifest: serde_json::Value =
        serde_json::from_str(&source).map_err(|e| package_error(spec, span, &format!("invalid package.json: {e}")))?;
    let module_entry = manifest
        .get("module")
        .and_then(|value| value.as_str())
        .filter(|_| conditions == Conditions::Import);
    // Without an export map, `require("pkg/sub")` probes like Node's CommonJS resolver.
    let legacy_main = manifest.get("exports").is_none()
        && ((subpath == "." && module_entry.is_none()) || conditions == Conditions::Require);
    let target =
        if let Some(exports) = manifest.get("exports") {
            let entry = if exports
                .as_object()
                .is_some_and(|map| map.keys().any(|key| key.starts_with('.')))
            {
                exports.get(&subpath)
            } else if subpath == "." {
                Some(exports)
            } else {
                None
            };
            let target = entry.and_then(|entry| package_export(entry, conditions)).ok_or_else(|| {
            package_error(
                spec,
                span,
                match conditions {
                    Conditions::Import => {
                        "no exact import/default source export (patterns and require-only exports are unsupported)"
                    }
                    Conditions::Require => "no exact require/default source export (patterns are unsupported)",
                },
            )
        })?;
            if !target.starts_with("./") {
                return Err(package_error(spec, span, "package export targets must start with ./"));
            }
            target.to_string()
        } else if subpath != "." {
            subpath
        } else if let Some(module) = module_entry {
            module.to_string()
        } else {
            manifest
                .get("main")
                .and_then(|v| v.as_str())
                .unwrap_or("./index.js")
                .to_string()
        };
    if target.starts_with('/')
        || target.contains(['\\', '%', '#', '?', '*'])
        || target.split('/').any(|part| part == ".." || part == "node_modules")
    {
        return Err(package_error(
            spec,
            span,
            "package source target must stay inside its package",
        ));
    }
    let path = package.join(&target);
    // Node's legacy `main` field, and CommonJS subpaths of a package without an export map, can
    // omit .js or name a directory. Explicit exports and source `module` targets stay exact; no
    // probing may bypass an export map's selected target.
    let path = if legacy_main && !fs.is_file(&path) {
        let mut with_js = path.clone().into_os_string();
        with_js.push(".js");
        [PathBuf::from(with_js), path.join("index.js")]
            .into_iter()
            .find(|candidate| fs.is_file(candidate))
            .unwrap_or(path)
    } else {
        path
    };
    if !fs.is_file(&path)
        || target.ends_with(".d.ts")
        || target.ends_with(".d.mts")
        || !matches!(
            path.extension().and_then(|extension| extension.to_str()),
            Some("ts" | "mts" | "js" | "mjs" | "cjs")
        )
    {
        return Err(package_error(
            spec,
            span,
            "expected TypeScript or JavaScript source at the exact package target",
        ));
    }
    let resolved = fs
        .canonicalize(&path)
        .map_err(|e| package_error(spec, span, &e.to_string()))?;
    let package = fs
        .canonicalize(&package)
        .map_err(|e| package_error(spec, span, &e.to_string()))?;
    if !resolved.starts_with(package) {
        return Err(package_error(spec, span, "package source target escapes its package"));
    }
    Ok(resolved)
}

fn package_export(value: &serde_json::Value, conditions: Conditions) -> Option<&str> {
    if let Some(target) = value.as_str() {
        return Some(target);
    }
    let wanted = match conditions {
        Conditions::Import => "import",
        Conditions::Require => "require",
    };
    let (_, target) = value
        .as_object()?
        .iter()
        .find(|(condition, _)| *condition == wanted || *condition == "default")?;
    package_export(target, conditions)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn memory(files: &[(&str, &str)]) -> crate::wasm::Memory {
        crate::wasm::Memory::new(
            files
                .iter()
                .map(|(path, source)| (PathBuf::from(path), source.to_string()))
                .collect(),
        )
    }

    #[test]
    fn installed_sources_follow_nearest_packages_exact_exports_and_relative_ts_imports() {
        let fs = memory(&[
            (
                "/p/zero.config.ts",
                "import { value } from '@demo/helpers'; import { other } from '@demo/helpers/extra';",
            ),
            (
                "/p/node_modules/@demo/helpers/package.json",
                r#"{"exports":{".":{"types":"./absent.d.ts","import":{"import":"./src/index.ts","default":"./absent.ts"}},"./extra":"./src/extra.mjs"}}"#,
            ),
            (
                "/p/node_modules/@demo/helpers/src/index.ts",
                "export { value } from './value.js'; import { value as dependency } from 'shared';",
            ),
            ("/p/node_modules/@demo/helpers/src/value.ts", "export const value = 3;"),
            ("/p/node_modules/@demo/helpers/src/extra.mjs", "export const other = 4;"),
            (
                "/p/node_modules/@demo/helpers/node_modules/shared/package.json",
                r#"{"type":"module","main":"./lib"}"#,
            ),
            (
                "/p/node_modules/@demo/helpers/node_modules/shared/lib/index.js",
                "import './helper.js'; const module = 5; export const value = module;",
            ),
            (
                "/p/node_modules/@demo/helpers/node_modules/shared/lib/helper.js",
                "function count() { return arguments.length; }",
            ),
            ("/p/node_modules/shared/package.json", r#"{"module":"./index.mjs"}"#),
            ("/p/node_modules/shared/index.mjs", "export const value = 9;"),
        ]);
        let program = load_from(Rc::new(fs), Path::new("/p/zero.config.ts")).unwrap_or_else(|ds| panic!("{ds:?}"));
        let paths: Vec<_> = program
            .modules
            .iter()
            .map(|module| module.path.to_string_lossy().into_owned())
            .collect();
        assert_eq!(paths.len(), 6, "{paths:?}");
        assert!(paths.contains(&"/p/node_modules/@demo/helpers/src/value.ts".to_string()));
        assert!(paths.contains(&"/p/node_modules/@demo/helpers/node_modules/shared/lib/index.js".to_string()));
        assert_eq!(
            resolve(&*program.fs, Path::new("/p/zero.config.ts"), "shared", Span::NONE).unwrap(),
            PathBuf::from("/p/node_modules/shared/index.mjs")
        );
        for (main, file) in [
            ("./build/index.es5", "/p/node_modules/pkg/build/index.es5.js"),
            ("./dist/lib.umd", "/p/node_modules/pkg/dist/lib.umd/index.js"),
        ] {
            let fs = memory(&[
                ("/p/zero.config.ts", ""),
                ("/p/node_modules/pkg/package.json", &format!(r#"{{"main":"{main}"}}"#)),
                (file, "export const value = 1;"),
            ]);
            assert_eq!(
                resolve(&fs, Path::new("/p/zero.config.ts"), "pkg", Span::NONE).unwrap(),
                PathBuf::from(file)
            );
        }
    }

    #[test]
    fn package_exports_refuse_unavailable_formats_and_escaping_targets_without_fallback() {
        for (manifest, spec, reason) in [
            (
                r#"{"exports":{"import":null,"default":"./source.ts"}}"#,
                "pkg",
                "no exact import/default",
            ),
            (
                r#"{"exports":{"require":"./source.ts"}}"#,
                "pkg",
                "no exact import/default",
            ),
            (r#"{"exports":"./../outside.ts"}"#, "pkg", "must stay inside"),
            (r#"{"exports":"./source"}"#, "pkg", "exact package target"),
            (
                r#"{"exports":{"./*":"./source.ts"}}"#,
                "pkg/private",
                "no exact import/default",
            ),
            (
                r#"{"exports":"./source.d.ts"}"#,
                "pkg",
                "expected TypeScript or JavaScript",
            ),
            (
                r#"{"exports":"./source.ts"}"#,
                "node:fs",
                "Node built-ins other than node:crypto are unavailable",
            ),
        ] {
            let fs = memory(&[
                ("/p/zero.config.ts", ""),
                ("/p/node_modules/pkg/package.json", manifest),
                ("/p/node_modules/pkg/source.ts", "export const value = 1;"),
                ("/p/node_modules/pkg/source.js", "module.exports = 1;"),
                ("/p/node_modules/pkg/source.d.ts", "export declare const value: number;"),
            ]);
            let error = resolve(&fs, Path::new("/p/zero.config.ts"), spec, Span::NONE).unwrap_err();
            assert!(error.message.contains(reason), "{manifest}: {error:?}");
        }
    }

    #[test]
    fn static_commonjs_function_exports_evaluate_with_their_lexical_environment() {
        let fs = memory(&[
            (
                "/p/zero.config.ts",
                "import add from 'anonymous'; import multiply from './local.cjs'; import subtract from 'local'; export default [add(4), multiply(5), subtract(6)];",
            ),
            ("/p/node_modules/anonymous/package.json", "{}"),
            (
                "/p/node_modules/anonymous/index.js",
                "'use strict'; const offset = 3; module.exports = function(value) { return value + offset; };",
            ),
            (
                "/p/local.cjs",
                "module.exports = multiply; function multiply(value) { const arguments = [value]; const read = () => arguments[0]; return read() * factor; } const factor = 2;",
            ),
            ("/p/node_modules/local/package.json", r#"{"main":"./source"}"#),
            (
                "/p/node_modules/local/source.js",
                "const subtract = function(arguments) { return arguments - 1; }; module.exports = subtract;",
            ),
        ]);
        let program = load_from(Rc::new(fs), Path::new("/p/zero.config.ts")).unwrap_or_else(|ds| panic!("{ds:?}"));
        let result = crate::eval::Evaluator::new(&program).run().unwrap();
        let crate::value::Value::Array(values) = result else {
            panic!("{result:?}");
        };
        let actual: Vec<_> = values
            .borrow()
            .iter()
            .map(|value| {
                let crate::value::Value::Num(value) = value else {
                    panic!("{value:?}");
                };
                *value
            })
            .collect();
        assert_eq!(actual, [7.0, 10.0, 5.0]);
    }

    fn evaluate(files: &[(&str, &str)]) -> crate::value::Value {
        let program =
            load_from(Rc::new(memory(files)), Path::new("/p/zero.config.ts")).unwrap_or_else(|ds| panic!("{ds:?}"));
        crate::eval::Evaluator::new(&program)
            .run()
            .unwrap_or_else(|d| panic!("{d:?}"))
    }

    fn json(value: &crate::value::Value) -> String {
        use crate::value::Value;
        match value {
            Value::Num(n) => n.to_string(),
            Value::Str(s) => format!("{s:?}"),
            Value::Bool(b) => b.to_string(),
            Value::Undef => "undefined".into(),
            Value::Null => "null".into(),
            Value::Array(items) => format!("[{}]", items.borrow().iter().map(json).collect::<Vec<_>>().join(",")),
            Value::Object(fields) => format!(
                "{{{}}}",
                fields
                    .borrow()
                    .iter()
                    .map(|(k, v)| format!("{k}:{}", json(v)))
                    .collect::<Vec<_>>()
                    .join(",")
            ),
            other => other.describe(),
        }
    }

    #[test]
    fn static_commonjs_requires_run_in_node_order_and_share_one_module_exports() {
        // Mirrors picomatch: relative require, destructured require, `exports.x =` that reads
        // `exports` later, `module.exports = { … }`, and a module required twice.
        let result = evaluate(&[
            (
                "/p/zero.config.ts",
                "import esm from 'pm'; import pm from './entry.cjs'; import { log } from 'pm/log'; export default [esm, pm.answer, pm.isMatch, log.join(','), pm.same];",
            ),
            (
                "/p/node_modules/pm/package.json",
                r#"{"exports":{".":{"import":"./esm.mjs","require":"./index.js"},"./log":"./log.js"}}"#,
            ),
            ("/p/node_modules/pm/esm.mjs", "export default 'esm entry';"),
            ("/p/entry.cjs", "module.exports = require('pm');"),
            ("/p/node_modules/pm/log.js", "exports.log = [];"),
            (
                "/p/node_modules/pm/index.js",
                "'use strict'; const { log } = require('./log'); log.push('index'); const utils = require('./lib/utils'); const again = require('./lib/utils'); log.push('after'); module.exports = { answer: utils.double(21), isMatch: utils.isMatch, same: utils === again };",
            ),
            (
                "/p/node_modules/pm/lib/utils.js",
                "const { log } = require('../log'); log.push('utils'); const { TWO } = require('./constants'); exports.double = n => n * TWO; exports.isMatch = exports.double(1) === TWO; module.exports.extra = 1;",
            ),
            (
                "/p/node_modules/pm/lib/constants.js",
                "module.exports = { TWO: 2, __proto_safe: true };",
            ),
        ]);
        assert_eq!(json(&result), r#"["esm entry",42,true,"index,utils,after",true]"#);
    }

    #[test]
    fn commonjs_named_exports_follow_static_assignments_like_node() {
        let result = evaluate(&[
            (
                "/p/zero.config.ts",
                "import a, { x } from './a.cjs'; import b, { y } from './b.cjs'; import * as c from './c.cjs'; export default [a, x, b, y, c.default.z];",
            ),
            ("/p/a.cjs", "exports.x = 1; exports.default = 'ignored'; exports.x = 2;"),
            ("/p/b.cjs", "module.exports = { y: 3 }; module.exports.y = 4;"),
            ("/p/c.cjs", "if (true) { module.exports.z = 5; }"),
        ]);
        assert_eq!(json(&result), r#"[{x:2,default:"ignored"},2,{y:4},4,5]"#);
    }

    #[test]
    fn installed_typescript_erases_types_and_elides_type_only_imports_and_re_exports() {
        let fs = memory(&[
            (
                "/p/zero.config.ts",
                "import { scale, Factor } from 'typed'; import { typeOnly } from './local'; export default [scale(2), typeOnly];",
            ),
            (
                "/p/local.ts",
                "export type Local = { n: number }; export const typeOnly = 1;",
            ),
            (
                "/p/node_modules/typed/package.json",
                r#"{"exports":{".":"./src/index.ts"}}"#,
            ),
            (
                "/p/node_modules/typed/src/index.ts",
                "export { scale } from './scale.js'; export type { Factor } from './types.js'; export { Shape } from './types.js'; export * from './types.js';",
            ),
            (
                "/p/node_modules/typed/src/types.ts",
                "export interface Shape { (n: number): number; new (n: number): object } export type Factor = <T>(value: T, ...rest: unknown[]) => T;",
            ),
            (
                "/p/node_modules/typed/src/scale.ts",
                "import { Shape } from './types.js'; declare const brand: unique symbol; export function scale<T extends number>(this: void, value: T, by?: number): number { assert(value); return (value as number) * (by ?? 3)!; } function assert(value: unknown): asserts value is number {}",
            ),
        ]);
        let program = load_from(Rc::new(fs), Path::new("/p/zero.config.ts")).unwrap_or_else(|ds| panic!("{ds:?}"));
        let package: Vec<_> = program.modules.iter().filter(|m| m.dynamic).collect();
        assert_eq!(package.len(), 3);
        assert!(package.iter().any(|m| m.type_names.contains("Factor")));
        assert!(!program.modules[program.entry].dynamic);
        // Authored modules keep their static type layer; only the app's lookup of a package type fails.
        let error = crate::eval::Evaluator::new(&program).run().unwrap_err();
        assert!(
            error.message.contains("`Factor` comes from an installed package"),
            "{error:?}"
        );
        let fs = memory(&[
            (
                "/p/zero.config.ts",
                "import { scale } from 'typed'; export default scale(2);",
            ),
            (
                "/p/node_modules/typed/package.json",
                r#"{"exports":{".":"./index.ts"}}"#,
            ),
            (
                "/p/node_modules/typed/index.ts",
                "export function scale<T>(value: T & number, by?: number): number { return value * (by ?? 3); }",
            ),
        ]);
        let program = load_from(Rc::new(fs), Path::new("/p/zero.config.ts")).unwrap_or_else(|ds| panic!("{ds:?}"));
        let result = crate::eval::Evaluator::new(&program).run().unwrap();
        assert!(matches!(result, crate::value::Value::Num(n) if n == 6.0), "{result:?}");
    }

    #[test]
    fn commonjs_refuses_dynamic_exports_wrapper_access_and_esm_mixing() {
        for (source, target, manifest, reason) in [
            (
                "module['exports'] = function() {};",
                "source.cjs",
                r#"{"main":"./source.cjs"}"#,
                "`module`",
            ),
            (
                "module.exports = function() { if (false) { return require('fs'); } };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "inside a function",
            ),
            (
                "const name = './x'; module.exports = require(name);",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "dynamic require()",
            ),
            (
                "module.exports = require.resolve('./x');",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "`require` must be called",
            ),
            (
                "module.exports = require('esm-only');",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "resolves to an ES module",
            ),
            (
                "module.exports = function() {}; function mutate() { module.exports = function() {}; }",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "inside a function",
            ),
            (
                "module.exports = function() { exports.count = 1; };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "inside a function",
            ),
            (
                "exports = { value: 1 };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "can't be reassigned",
            ),
            (
                "module.exports = module.id;",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "only available as `module.exports`",
            ),
            (
                "const module = {}; module.exports = function() {};",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "`module`",
            ),
            (
                "module.exports = function() {}; export const value = 1;",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "cannot be mixed",
            ),
            (
                "module.exports = function() {};",
                "source.js",
                r#"{"type":"module","main":"./source.js"}"#,
                "ESM package",
            ),
            (
                "module.exports = function() {};",
                "source.js",
                r#"{"module":"./source.js"}"#,
                "ESM package",
            ),
            (
                "module.exports = function() {};",
                "source.mjs",
                r#"{"exports":"./source.mjs"}"#,
                ".js or .cjs",
            ),
            (
                "module.exports = function() { return __dirname; };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "`__dirname`",
            ),
            (
                "module.exports = function(value) { value = 2; return arguments[0]; };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "implicit arguments",
            ),
            (
                "module.exports = function(arguments) { return function() { return arguments[0]; }; };",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "implicit arguments",
            ),
        ] {
            let target_path = format!("/p/node_modules/pkg/{target}");
            let fs = memory(&[
                ("/p/zero.config.ts", "import fn from 'pkg'; export default fn;"),
                ("/p/node_modules/pkg/package.json", manifest),
                (&target_path, source),
                ("/p/node_modules/pkg/x.js", "module.exports = 1;"),
                (
                    "/p/node_modules/esm-only/package.json",
                    r#"{"type":"module","exports":"./index.js"}"#,
                ),
                ("/p/node_modules/esm-only/index.js", "export default 1;"),
            ]);
            let diagnostics = load_from(Rc::new(fs), Path::new("/p/zero.config.ts"))
                .err()
                .unwrap_or_else(|| panic!("accepted {source}"));
            assert!(
                diagnostics
                    .iter()
                    .any(|diagnostic| diagnostic.code == diag::Code::NpmImport && diagnostic.message.contains(reason)),
                "{source}: {diagnostics:?}"
            );
        }
    }

    #[cfg(all(unix, feature = "cli"))]
    #[test]
    fn package_exports_cannot_follow_a_symlink_outside_the_package() {
        let temp = tempfile::tempdir().unwrap();
        let package = temp.path().join("node_modules/pkg");
        std::fs::create_dir_all(&package).unwrap();
        std::fs::write(temp.path().join("outside.ts"), "export const value = 1;").unwrap();
        std::fs::write(package.join("package.json"), r#"{"exports":"./source.ts"}"#).unwrap();
        std::os::unix::fs::symlink(temp.path().join("outside.ts"), package.join("source.ts")).unwrap();
        let error = resolve(&Disk, &temp.path().join("zero.config.ts"), "pkg", Span::NONE).unwrap_err();
        assert!(error.message.contains("escapes its package"), "{error:?}");
    }
}
