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
    if diagnostics.is_empty() {
        Ok(program)
    } else {
        Err(diagnostics)
    }
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
    let body = parse(fs, &source, path, file, id, diagnostics)?;
    let imports: Vec<(String, Span)> = body.iter().filter_map(import_source).collect();
    program.modules.push(Rc::new(Module {
        id,
        file,
        path: path.to_path_buf(),
        body,
    }));
    for (spec, span) in imports {
        if spec == ZERO_MODULE {
            continue;
        }
        match resolve(fs, path, &spec, span).and_then(|target| load_module(fs, &target, program, by_path, diagnostics))
        {
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
) -> Result<Vec<Stmt>> {
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
        return Ok(Vec::new());
    }
    let (mut body, errors) = Lowerer {
        file,
        module,
        class_context: std::cell::Cell::new(false),
        super_call: std::cell::Cell::new(false),
    }
    .program_diagnostics(&ret.program);
    if errors.is_empty() {
        normalize_commonjs(fs, path, &mut body)?;
    }
    diagnostics.extend(errors);
    Ok(body)
}

fn commonjs_error(span: Span, reason: &str) -> Diag {
    Diag::new(span, format!("unsupported CommonJS source: {reason}"))
        .code(diag::Code::NpmImport)
        .help("use one top-level `module.exports = function (...) { ... }` or export a local function; require(), named exports, and Node wrapper globals are unavailable")
}

fn commonjs_export(statement: &Stmt) -> Option<&Expr> {
    let Stmt::Expr(Expr {
        kind: ExprKind::Assign {
            op: None,
            target,
            value,
        },
        ..
    }) = statement
    else {
        return None;
    };
    matches!(&target.kind, ExprKind::Member { obj, prop, optional: false, .. }
        if prop == "exports" && matches!(&obj.kind, ExprKind::Ident(name) if name == "module"))
    .then_some(value)
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

/// A static function export needs no Node module wrapper. Normalize only that assignment, retaining
/// its evaluation position and the original function's source spans and lexical environment.
fn normalize_commonjs(fs: &dyn Fs, path: &Path, body: &mut [Stmt]) -> Result<()> {
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
    let exports: Vec<_> = body
        .iter()
        .enumerate()
        .filter_map(|(index, statement)| commonjs_export(statement).map(|value| (index, value.span)))
        .collect();
    let extension = path.extension().and_then(|extension| extension.to_str());
    let esm_package = extension == Some("js")
        && (!exports.is_empty() || !has_esm)
        && esm_package_source(fs, path, exports.first().map_or(Span::NONE, |(_, span)| *span))?;
    if let Some(&(index, span)) = exports.first() {
        if exports.len() != 1 {
            return Err(commonjs_error(
                exports[1].1,
                "module.exports must be assigned exactly once",
            ));
        }
        if !matches!(
            path.extension().and_then(|extension| extension.to_str()),
            Some("js" | "cjs")
        ) {
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
        let value = commonjs_export(&body[index]).expect("export was found above");
        let local_function = match &value.kind {
            ExprKind::Func(_) => true,
            ExprKind::Ident(name) => body.iter().enumerate().any(|(declaration_index, statement)| {
                matches!(statement, Stmt::Func(function) if function.name.as_ref() == Some(name))
                    || (declaration_index < index
                        && matches!(statement, Stmt::Decl {
                        pat: Pat::Id(local, _), init: Some(Expr { kind: ExprKind::Func(_), .. }), ..
                    } if local == name))
            }),
            _ => false,
        };
        if !local_function {
            return Err(commonjs_error(
                span,
                "module.exports must directly name a local function or contain a function expression",
            ));
        }
        let statement = std::mem::replace(&mut body[index], Stmt::Block(Vec::new()));
        let Stmt::Expr(Expr {
            kind: ExprKind::Assign { value, .. },
            ..
        }) = statement
        else {
            unreachable!("export was found above");
        };
        body[index] = Stmt::ExportDefault(*value);
    }
    if !exports.is_empty() || (!has_esm && !esm_package && matches!(extension, Some("js" | "cjs"))) {
        CommonJsScope { arguments_bound: false }.block(body)?;
    }
    Ok(())
}

fn validate_commonjs_name(name: &str, span: Span) -> Result<()> {
    if matches!(name, "module" | "exports" | "require" | "__filename" | "__dirname") {
        return Err(commonjs_error(
            span,
            &format!("Node wrapper name `{name}` is unavailable outside the static export assignment"),
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
struct CommonJsScope {
    arguments_bound: bool,
}

impl CommonJsScope {
    fn block(self, body: &[Stmt]) -> Result<()> {
        let scope = Self {
            arguments_bound: self.arguments_bound || body.iter().any(declares_lexical_arguments),
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
                };
                scope.expression(target)?;
                scope.expression(iter)?;
                scope.block(body)?;
            }
            Stmt::While(test, body) => {
                self.expression(test)?;
                self.block(body)?;
            }
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
            | Stmt::Break(_)
            | Stmt::Continue(_) => {}
        }
        Ok(())
    }

    fn expression(self, expression: &Expr) -> Result<()> {
        match &expression.kind {
            ExprKind::Ident(name) => {
                validate_commonjs_name(name, expression.span)?;
                if name == "arguments" && !self.arguments_bound {
                    return Err(commonjs_error(
                        expression.span,
                        "implicit arguments can alias parameters in CommonJS; only explicit bindings named arguments are supported",
                    ));
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
            | ExprKind::IsNullish(value, _)
            | ExprKind::Update { target: value, .. }
            | ExprKind::Member { obj: value, .. }
            | ExprKind::Chain(value)
            | ExprKind::Await(value)
            | ExprKind::As(value, _)
            | ExprKind::NonNull(value) => self.expression(value)?,
            ExprKind::Instanceof(left, right)
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
            ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args } => {
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
            | ExprKind::Regex { .. } => {}
        }
        Ok(())
    }
}

fn resolve(fs: &dyn Fs, from: &Path, spec: &str, span: Span) -> Result<PathBuf> {
    if !spec.starts_with('.') && !fs.allow_packages() {
        return Err(
            Diag::new(span, format!("npm import {spec:?} needs local source capture"))
                .code(diag::Code::SourceNeedsLocalCapture)
                .help("publish this project from the Spacefast CLI to capture installed npm sources"),
        );
    }
    if !spec.starts_with("./") && !spec.starts_with("../") {
        return resolve_package(fs, from, spec, span);
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
        .help("install a source package locally; its TypeScript, ESM, or static CommonJS source must fit Zero's PHP compiler subset")
}

fn resolve_package(fs: &dyn Fs, from: &Path, spec: &str, span: Span) -> Result<PathBuf> {
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
            "unsupported package specifier (Node built-ins and package aliases are unavailable)",
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
    let legacy_main = manifest.get("exports").is_none()
        && subpath == "."
        && manifest.get("module").and_then(|value| value.as_str()).is_none();
    let target = if let Some(exports) = manifest.get("exports") {
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
        let target = entry.and_then(package_export).ok_or_else(|| {
            package_error(
                spec,
                span,
                "no exact import/default source export (patterns and require-only exports are unsupported)",
            )
        })?;
        if !target.starts_with("./") {
            return Err(package_error(spec, span, "package export targets must start with ./"));
        }
        target.to_string()
    } else if subpath != "." {
        subpath
    } else if let Some(module) = manifest.get("module").and_then(|v| v.as_str()) {
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
    // Node's legacy `main` field can omit .js or name a directory. Explicit exports and source
    // `module` targets stay exact; no probing may bypass an export map's selected target.
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

fn package_export(value: &serde_json::Value) -> Option<&str> {
    if let Some(target) = value.as_str() {
        return Some(target);
    }
    let (_, target) = value
        .as_object()?
        .iter()
        .find(|(condition, _)| *condition == "import" || *condition == "default")?;
    package_export(target)
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
                "unsupported package specifier",
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

    #[test]
    fn commonjs_refuses_dynamic_exports_wrapper_access_and_esm_mixing() {
        for (source, target, manifest, reason) in [
            (
                "exports.value = function() {};",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "`exports`",
            ),
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
                "`require`",
            ),
            (
                "module.exports = function() {}; module.exports = function() {};",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "exactly once",
            ),
            (
                "module.exports = function() {}; function mutate() { module.exports = function() {}; }",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "`module`",
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
                "module.exports = 1;",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "local function",
            ),
            (
                "module.exports = factory(); function factory() { return function() {}; }",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "local function",
            ),
            (
                "module.exports = missing;",
                "source.js",
                r#"{"main":"./source.js"}"#,
                "local function",
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
