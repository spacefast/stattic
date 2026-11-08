//! The build-time evaluator. Runs `zero.config.ts` (and the local modules it
//! imports) over Zero's IR, so composition works: helpers, factories, spreads,
//! loops. It is pure: no I/O, no clock, no randomness, and a step budget stops
//! runaway loops. Functions stored in the result (hooks, actions, `$default`
//! callbacks) are never called here; they keep their defining scope so the
//! PHP compiler can resolve what they captured.

use crate::diag::{Diag, Span, suggest};
use crate::ir::*;
use crate::loader::{Program, WORDPRESS_MODULE, ZERO_MODULE};
use crate::num::js_number_to_string;
use crate::schema::{ColType, RefAction, RelKind};
use crate::value::*;
use indexmap::IndexMap;
use std::collections::{HashMap, HashSet};
use std::rc::Rc;

const STEP_BUDGET: u64 = 5_000_000;
const MAX_DEPTH: usize = 400;

pub enum Ctl {
    Err(Diag),
    Throw(Value, Span),
    /// Optional chain hit null/undefined; unwinds to the nearest `Chain`.
    Short,
}

impl From<Diag> for Ctl {
    fn from(d: Diag) -> Self {
        Ctl::Err(d)
    }
}

type R<T> = std::result::Result<T, Ctl>;

struct ValueIterator {
    source: Value,
    index: usize,
    done: bool,
}

impl ValueIterator {
    fn new(source: Value, span: Span) -> R<Self> {
        let source = match source {
            Value::Array(_) | Value::Arguments(_) => source,
            Value::Str(s) => Value::array(s.chars().map(|c| Value::str(&c.to_string())).collect()),
            other => return err(span, format!("can't iterate {}", other.describe())),
        };
        Ok(Self {
            source,
            index: 0,
            done: false,
        })
    }

    fn next(&mut self, span: Span) -> R<Option<Value>> {
        if self.done {
            return Ok(None);
        }
        let item = match &self.source {
            Value::Array(array) => array.borrow().get(self.index).cloned(),
            Value::Arguments(object) => {
                if self.index < array_like_length(object, span)? {
                    Some(
                        object
                            .borrow()
                            .get(&self.index.to_string())
                            .cloned()
                            .unwrap_or(Value::Undef),
                    )
                } else {
                    None
                }
            }
            _ => unreachable!("iterator source"),
        };
        self.done = item.is_none();
        self.index += 1;
        Ok(item)
    }
}

/// Where a destructuring-assignment leaf reads its value: already read (array elements, rests)
/// or a property read that runs after the leaf resolves its target.
enum PatternSource {
    Value(Value),
    Property(Value, String),
}

enum ValuePlace {
    Binding(String),
    Property(Value, Value),
}

enum Flow {
    Normal,
    Return(Value),
    /// `break` or `break label`.
    Break(Option<String>),
    /// `continue` or `continue label`.
    Continue(Option<String>),
}

/// What a loop does with the flow its body ended with. `labels` are the labels directly on the
/// loop statement: a labeled `continue` naming one of them continues this loop.
enum LoopStep {
    Next,
    Exit,
    Leave(Flow),
}

fn loop_step(flow: Flow, labels: &[String]) -> LoopStep {
    match flow {
        Flow::Normal | Flow::Continue(None) => LoopStep::Next,
        Flow::Continue(Some(l)) if labels.contains(&l) => LoopStep::Next,
        Flow::Break(None) => LoopStep::Exit,
        other => LoopStep::Leave(other),
    }
}

fn err<T>(span: Span, msg: impl Into<String>) -> R<T> {
    Err(Ctl::Err(Diag::new(span, msg)))
}

fn err_help<T>(span: Span, msg: impl Into<String>, help: impl Into<String>) -> R<T> {
    Err(Ctl::Err(Diag::new(span, msg).help(help)))
}

fn supported_power(a: f64, b: f64, span: Span) -> R<f64> {
    crate::num::pow(a, b).ok_or_else(|| Ctl::Err(crate::builtins::power_diagnostic(span)))
}

fn require_callable(value: &Value, span: Span) -> R<()> {
    let accepted = |surface, name| crate::builtins::contract(surface, name).is_some_and(|c| c.evaluator.is_some());
    let callable = match value {
        Value::Closure(_) => true,
        Value::Builtin(Builtin::Global(name)) => accepted("global", name),
        Value::Builtin(Builtin::Static(surface, name)) => accepted(surface, name),
        Value::Bound(receiver, name) => match receiver.as_ref() {
            Value::Array(_) => accepted("array", name),
            Value::Str(_) => accepted("string", name),
            Value::Num(_) => accepted("number", name),
            _ => false,
        },
        _ => false,
    };
    if callable {
        Ok(())
    } else {
        err_help(
            span,
            "callback must be a function",
            "pass a callable callback, including for empty inputs",
        )
    }
}

/// Runtime tokens may be carried through config composition, but never consumed as build-time data.
fn require_static_data(value: &Value, span: Span) -> R<()> {
    fn visit(value: &Value, span: Span, seen: &mut HashSet<usize>) -> R<()> {
        match value {
            Value::Env(_) => err_help(
                span,
                "env() is only allowed in webhooks/signature",
                "pass the token directly as a webhook URL/secret or signature secret; it cannot be evaluated at build time",
            ),
            Value::Array(a) if seen.insert(Rc::as_ptr(a) as usize) => {
                for value in a.borrow().iter() {
                    visit(value, span, seen)?;
                }
                Ok(())
            }
            Value::Object(o) | Value::Arguments(o) if seen.insert(Rc::as_ptr(o) as usize) => {
                for value in o.borrow().values() {
                    visit(value, span, seen)?;
                }
                Ok(())
            }
            _ => Ok(()),
        }
    }
    visit(value, span, &mut HashSet::new())
}

/// Pure conversions do not execute user-defined ToPrimitive hooks.
fn require_static_coercion(value: &Value, span: Span) -> R<()> {
    require_static_data(value, span)?;
    fn visit(value: &Value, span: Span, seen: &mut HashSet<usize>) -> R<()> {
        match value {
            Value::Array(a) if seen.insert(Rc::as_ptr(a) as usize) => {
                for value in a.borrow().iter() {
                    visit(value, span, seen)?;
                }
            }
            Value::Object(o) | Value::Arguments(o)
                if o.borrow().contains_key("valueOf") || o.borrow().contains_key("toString") =>
            {
                return err_help(
                    span,
                    "custom object coercion isn't supported during configuration evaluation",
                    "pass a primitive value, or perform the conversion inside a handler",
                );
            }
            Value::Closure(_) | Value::Builtin(_) | Value::Bound(..) | Value::Class(_) => {
                return err_help(
                    span,
                    "function coercion isn't supported during configuration evaluation",
                    "pass a primitive value, or perform the conversion inside a handler",
                );
            }
            _ => {}
        }
        Ok(())
    }
    visit(value, span, &mut HashSet::new())
}

/// Names exported by `"zero"`.
pub const ZERO_EXPORTS: &[&str] = &[
    "collection",
    "table",
    "userProjection",
    "id",
    "integer",
    "real",
    "text",
    "boolean",
    "date",
    "timestamp",
    "json",
    "email",
    "url",
    "slug",
    "richText",
    "media",
    "select",
    "group",
    "repeater",
    "blocks",
    "global",
    "defineRelations",
    "defineConfig",
    "action",
    "db",
    "HttpError",
    "file",
    "files",
    "job",
    "route",
    "env",
    "once",
    "jobs",
    "mail",
    "definePlugin",
];
/// Type-only exports of `"zero"`: importable, erased.
const ZERO_TYPES: &[&str] = &[
    "InferSelectModel",
    "InferInsertModel",
    "Config",
    "Register",
    "User",
    "Role",
    "HookCtx",
    "AccessCtx",
    "ValidateCtx",
    "FieldUi",
    "AccessRule",
    "AccessConfig",
    "CollectionOptions",
    "FieldsConfig",
    "BlockValue",
    "Global",
    "AnyGlobal",
    "FieldRule",
    "FieldAccessCtx",
    "SiblingFilter",
    "AccessShorthand",
    "Job",
    "AnyJob",
    "JobCtx",
    "JobsApi",
    "JobHandle",
    "Route",
    "RouteRequest",
    "RouteResponse",
    "EnqueueOptions",
    "EnvValue",
    "Webhook",
    "WebhookEvent",
    "FileBucket",
    "AnyFileBucket",
    "FileRule",
    "Locale",
    "LocalizedColumns",
    "ColumnsConfig",
    "ConfigInput",
    "ServicesConfig",
    "ServiceForm",
    "ServiceFormField",
    "ServiceProduct",
    "InferActionInput",
    "Plugin",
];

pub const GLOBALS: &[&str] = &[
    "Math",
    "Object",
    "Array",
    "Set",
    "Map",
    "RegExp",
    "JSON",
    "String",
    "structuredClone",
    "Number",
    "BigInt",
    "Boolean",
    "parseInt",
    "parseFloat",
    "encodeURI",
    "encodeURIComponent",
    "isNaN",
    "isFinite",
    "Date",
    "console",
    "Error",
    "TypeError",
    "RangeError",
    "Infinity",
    "NaN",
    "fetch",
    "process",
    "require",
    "globalThis",
    "eval",
    "Function",
    "Promise",
    "ReadableStream",
    "WritableStream",
    "TransformStream",
    "setTimeout",
    "crypto",
    "Symbol",
    "WeakMap",
    "WeakSet",
    "Buffer",
    "Headers",
    "clearTimeout",
    "AbortController",
    "AbortSignal",
    "URL",
    "URLSearchParams",
    "URL",
    "Request",
    "Response",
    "FormData",
    "Blob",
    "AbortController",
];

/// `globalThis` members that host-globals work owns. Detecting them stays refused
/// until that work defines what Zero provides.
const HOST_GLOBALS: &[&str] = &[
    "fetch",
    "process",
    "require",
    "crypto",
    "Buffer",
    "atob",
    "btoa",
    "setTimeout",
    "setInterval",
    "clearTimeout",
    "clearInterval",
    "queueMicrotask",
    "setImmediate",
    "Promise",
    "eval",
    "Function",
];

/// Browser, worker and other server-runtime globals that neither the PHP runtime
/// nor Node provides, so `undefined` matches the Node oracle. `navigator` is not
/// here: Node 21+ defines it.
pub const ABSENT_GLOBALS: &[&str] = &[
    "window",
    "document",
    "self",
    "location",
    "Bun",
    "Deno",
    "EdgeRuntime",
    "DOMParser",
    "WebSocketPair",
    "XMLHttpRequest",
    "importScripts",
    "WorkerGlobalScope",
];

/// What `globalThis.<name>` is in the PHP runtime, decided at compile time.
pub enum GlobalMember {
    /// One of Zero's globals, read as that global.
    Provided(&'static str),
    /// A host Zero never provides: `undefined`.
    Absent,
    /// Unknown or owned by host-global work: refused.
    Refused,
}

pub fn global_member(name: &str) -> GlobalMember {
    if HOST_GLOBALS.contains(&name) {
        return GlobalMember::Refused;
    }
    if let Some(g) = GLOBALS.iter().find(|g| **g == name) {
        return GlobalMember::Provided(g);
    }
    if ABSENT_GLOBALS.contains(&name) {
        return GlobalMember::Absent;
    }
    GlobalMember::Refused
}

/// `typeof` of a provided global, matching JavaScript.
pub fn global_type_of(g: &str) -> &'static str {
    match g {
        "Math" | "JSON" | "console" | "globalThis" => "object",
        "Infinity" | "NaN" => "number",
        _ => "function",
    }
}

pub fn refused_global_member(name: &str, span: Span) -> Diag {
    Diag::new(
        span,
        format!("`globalThis.{name}` depends on the host and can't be resolved at compile time"),
    )
    .help(if HOST_GLOBALS.contains(&name) {
        format!("remove the `{name}` detection and use a capability Zero supports in the handler")
    } else {
        "only Zero's own globals and absent browser or server runtimes (window, document, Bun, Deno, ...) can be detected; remove the detection".into()
    })
}

pub struct Evaluator<'p> {
    program: &'p Program,
    module_scopes: Vec<Option<Rc<Scope>>>,
    exports: Vec<IndexMap<String, Value>>,
    evaluating: Vec<bool>,
    steps: u64,
    depth: usize,
    /// Span of each property in an object literal: (allocation, key).
    pub key_spans: HashMap<(usize, String), Span>,
    // Retain collided objects so allocation identities cannot be reused.
    duplicate_keys: HashMap<usize, (Value, Vec<(String, Span)>)>,
    /// Labels directly on the statement being executed, taken by the loop they label.
    pending_labels: Vec<String>,
}

pub fn ptr_of(v: &Value) -> Option<usize> {
    match v {
        Value::Object(o) | Value::Arguments(o) => Some(Rc::as_ptr(o) as *const () as usize),
        Value::Array(a) => Some(Rc::as_ptr(a) as *const () as usize),
        _ => None,
    }
}

impl<'p> Evaluator<'p> {
    pub fn new(program: &'p Program) -> Self {
        let n = program.modules.len();
        Evaluator {
            program,
            module_scopes: vec![None; n],
            exports: vec![IndexMap::new(); n],
            evaluating: vec![false; n],
            steps: 0,
            depth: 0,
            key_spans: HashMap::new(),
            duplicate_keys: HashMap::new(),
            pending_labels: Vec::new(),
        }
    }

    /// Evaluates the entry module and returns its default export.
    pub fn run(&mut self) -> Result<Value, Diag> {
        let entry = self.program.entry;
        self.eval_module(entry).map_err(uncaught)?;
        match self.exports[entry].get("default") {
            Some(v) => Ok(v.clone()),
            None => Err(Diag::new(
                Span {
                    file: self.program.modules[entry].file,
                    start: 0,
                    end: 0,
                },
                "zero.config.ts has no default export",
            )
            .help("end the file with `export default defineConfig({ collections, tables, hooks, actions })`")),
        }
    }

    pub fn program(&self) -> &'p Program {
        self.program
    }

    /// Evaluates another entry module (a `wp-hooks/` file) and returns its exports.
    pub fn run_module(&mut self, module: usize) -> Result<IndexMap<String, Value>, Diag> {
        self.eval_module(module).map_err(uncaught)?;
        Ok(self.exports[module].clone())
    }

    pub fn module_scope(&self, module: usize) -> Option<Rc<Scope>> {
        self.module_scopes[module].clone()
    }

    pub fn call_closure_public(&mut self, f: &Rc<Closure>, args: Vec<Value>, span: Span) -> Result<Value, Diag> {
        self.call_closure(f, args, span).map_err(uncaught)
    }

    pub fn key_span(&self, obj: &Value, key: &str) -> Option<Span> {
        self.key_spans.get(&(ptr_of(obj)?, key.to_string())).copied()
    }

    /// Config consumers can forbid overwrites without changing JS object semantics.
    pub fn duplicate_key(&self, obj: &Value, key: Option<&str>) -> Option<(String, Span)> {
        self.duplicate_keys
            .get(&ptr_of(obj)?)?
            .1
            .iter()
            .find(|(name, _)| key.is_none_or(|key| name == key))
            .cloned()
    }

    fn record_duplicates(&mut self, obj: &Value, duplicates: Vec<(String, Span)>) {
        if !duplicates.is_empty() {
            self.duplicate_keys
                .entry(ptr_of(obj).expect("object"))
                .or_insert_with(|| (obj.clone(), Vec::new()))
                .1
                .extend(duplicates);
        }
    }

    fn set_key(&mut self, obj: &Value, key: &str, value: Value, span: Span) -> R<()> {
        match obj {
            Value::Arguments(_) if key == "callee" => err_help(
                span,
                "restricted arguments properties aren't supported during configuration evaluation",
                "access arguments.callee inside a runtime handler; native Error descriptors aren't modeled while building",
            ),
            Value::Object(map) | Value::Arguments(map) => {
                if key == "__proto__" && !map.borrow().contains_key(key) {
                    return err_help(
                        span,
                        "prototype mutation isn't supported",
                        "use an ordinary own data property",
                    );
                }
                if map.borrow_mut().insert(key.to_string(), value).is_some() {
                    self.record_duplicates(obj, vec![(key.to_string(), span)]);
                }
                Ok(())
            }
            Value::Closure(c) => {
                if crate::value::FUNCTION_BUILTIN_PROPERTIES.contains(&key) {
                    return err_help(
                        span,
                        format!("can't set `{key}` on a function while the config is built"),
                        "function `name`, `length`, `prototype` and `Function.prototype` members aren't modeled; use another property name",
                    );
                }
                c.props.borrow_mut().insert(key.to_string(), value);
                Ok(())
            }
            other => err(span, format!("can't set `{key}` on {}", other.describe())),
        }
    }

    fn tick(&mut self, span: Span) -> R<()> {
        self.steps += 1;
        if self.steps > STEP_BUDGET {
            return err_help(
                span,
                "the config ran too long while being evaluated (step budget exceeded)",
                "look for a loop that never ends",
            );
        }
        Ok(())
    }

    fn eval_module(&mut self, id: usize) -> R<()> {
        if self.module_scopes[id].is_some() {
            if self.evaluating[id] {
                let path = self.program.modules[id].path.display().to_string();
                return err(Span::NONE, format!("import cycle through {path}"));
            }
            return Ok(());
        }
        let module = self.program.modules[id].clone();
        let scope = Scope::new(None, id);
        self.module_scopes[id] = Some(scope.clone());
        self.evaluating[id] = true;
        // The CommonJS wrapper: `exports` starts as `module.exports`, and `require` runs its
        // target when called, as Node does.
        let commonjs = module.commonjs.as_ref().map(|names| {
            let exports = Value::object(IndexMap::new());
            let wrapper = Value::object(IndexMap::from([("exports".to_string(), exports.clone())]));
            declare(&scope, "module", wrapper.clone(), false);
            declare(&scope, "exports", exports, false);
            declare(&scope, "require", Value::Builtin(Builtin::Require(id)), false);
            (wrapper, names)
        });
        // Dependencies first, like ESM. A CommonJS module loads its `require` targets lazily.
        for stmt in &module.body {
            if let Stmt::Import { source, .. }
            | Stmt::ExportNames { from: Some(source), .. }
            | Stmt::ExportAll { from: source, .. } = stmt
                && !crate::loader::builtin_module(source)
                && let Some(&target) = self.program.resolved.get(&(id, source.clone()))
            {
                self.eval_module(target)?;
            }
        }
        hoist(&module.body, &scope);
        hoist_vars(&module.body, &scope);
        for stmt in &module.body {
            match stmt {
                Stmt::Import {
                    source, specs, span, ..
                } => self.bind_imports(id, source, specs, *span, &scope)?,
                Stmt::Exported(inner) => {
                    self.exec(inner, &scope)?;
                    let mut names = Vec::new();
                    match inner.as_ref() {
                        Stmt::Decl { pat, .. } => pat.bindings(&mut names),
                        Stmt::Func(f) => names.extend(f.name.as_deref().map(|name| (name, f.span))),
                        _ => {}
                    }
                    for (n, _) in names {
                        scope.exported.borrow_mut().insert(n.to_string());
                        let v = scope.lookup(n).map(|b| b.value).unwrap_or(Value::Undef);
                        self.exports[id].insert(n.to_string(), v);
                    }
                }
                Stmt::ExportNames { names, from, span } => {
                    for (local, exported) in names {
                        let erased = module.dynamic
                            && match from {
                                Some(source) => self
                                    .program
                                    .resolved
                                    .get(&(id, source.clone()))
                                    .is_some_and(|&target| self.erased_type_export(target, local, 0)),
                                None => scope.lookup(local).is_none() && self.erased_type_import(id, local, 0),
                            };
                        if erased {
                            continue;
                        }
                        let v = match from {
                            None => match scope.lookup(local) {
                                Some(b) if b.initialized => b.value,
                                Some(_) => return err(*span, format!("`{local}` isn't initialized")),
                                None => return err(*span, format!("can't export `{local}`: it isn't defined")),
                            },
                            Some(src) => self.import_value(id, src, local, *span)?,
                        };
                        if from.is_none() {
                            scope.exported.borrow_mut().insert(local.clone());
                        }
                        self.exports[id].insert(exported.clone(), v);
                    }
                }
                Stmt::ExportAll { from, span } => {
                    let Some(&target) = self.program.resolved.get(&(id, from.clone())) else {
                        return err(*span, "`export * from \"zero\"` isn't supported");
                    };
                    let all: Vec<(String, Value)> = self.exports[target]
                        .iter()
                        .filter(|(k, _)| *k != "default")
                        .map(|(k, v)| (k.clone(), v.clone()))
                        .collect();
                    self.exports[id].extend(all);
                }
                Stmt::ExportDefault(e) => {
                    let v = self.eval(e, &scope)?;
                    self.exports[id].insert("default".into(), v);
                }
                other => {
                    if !matches!(self.exec(other, &scope)?, Flow::Normal) {
                        return err(Span::NONE, "`return`, `break`, or `continue` outside a function");
                    }
                }
            }
        }
        if let Some((wrapper, names)) = commonjs {
            let exports = self.get_prop(&wrapper, "exports", Span::NONE)?;
            for name in names {
                let value = match &exports {
                    Value::Object(object) => object.borrow().get(name).cloned().unwrap_or(Value::Undef),
                    _ => Value::Undef,
                };
                self.exports[id].insert(name.clone(), value);
            }
            self.exports[id].insert("default".into(), exports);
        }
        self.evaluating[id] = false;
        Ok(())
    }

    /// `require("<spec>")` from CommonJS module `from`: run the target if it hasn't run, and
    /// return its `module.exports`. Node would hand a cycle a partial export object.
    fn require(&mut self, from: usize, args: &[Value], span: Span) -> R<Value> {
        let [Value::Str(spec)] = args else {
            return err(span, "require() needs one string literal");
        };
        // Node built-ins resolve through the same lookup as `import` (`builtins::module`).
        if crate::builtins::module(spec).is_some() {
            return Ok(Value::Builtin(Builtin::NodeCrypto(None)));
        }
        let Some(&target) = self.program.resolved.get(&(from, spec.to_string())) else {
            return err(
                span,
                format!("require(\"{spec}\") wasn't loaded; it must be a top-level static require"),
            );
        };
        if self.evaluating[target] {
            let path = self.program.modules[target].path.display().to_string();
            return err_help(
                span,
                format!("require cycle through {path}"),
                "Node returns a partially initialized module.exports here; break the cycle",
            );
        }
        self.eval_module(target)?;
        Ok(self.exports[target].get("default").cloned().unwrap_or(Value::Undef))
    }

    /// Whether `name` is a type that a dynamic module erased, directly or through re-exports.
    fn erased_type_export(&self, module: usize, name: &str, depth: usize) -> bool {
        let m = &self.program.modules[module];
        if depth > 64 || !m.dynamic || self.exports[module].contains_key(name) {
            return false;
        }
        if m.type_names.contains(name) {
            return true;
        }
        let target = |source: &str| self.program.resolved.get(&(module, source.to_string())).copied();
        m.body.iter().any(|stmt| match stmt {
            Stmt::ExportNames { names, from, .. } => names.iter().any(|(local, exported)| {
                exported == name
                    && match from {
                        Some(source) => target(source).is_some_and(|t| self.erased_type_export(t, local, depth + 1)),
                        None => self.erased_type_import(module, local, depth),
                    }
            }),
            Stmt::ExportAll { from, .. } => target(from).is_some_and(|t| self.erased_type_export(t, name, depth + 1)),
            _ => false,
        })
    }

    fn erased_type_import(&self, module: usize, local: &str, depth: usize) -> bool {
        let m = &self.program.modules[module];
        m.type_names.contains(local)
            || m.body.iter().any(|stmt| {
                let Stmt::Import { source, specs, .. } = stmt else {
                    return false;
                };
                specs.iter().any(
                    |spec| match (&spec.imported, self.program.resolved.get(&(module, source.clone()))) {
                        (ImportName::Named(imported), Some(&t)) if spec.local == local => {
                            self.erased_type_export(t, imported, depth + 1)
                        }
                        _ => false,
                    },
                )
            })
    }

    fn import_value(&mut self, from: usize, source: &str, name: &str, span: Span) -> R<Value> {
        if source == ZERO_MODULE {
            return zero_export(name, span);
        }
        if let Some(module) = crate::builtins::module(source) {
            return node_crypto_export(module, source, name, span);
        }
        if source == WORDPRESS_MODULE {
            return wordpress_export(name, span);
        }
        let target = self.program.resolved[&(from, source.to_string())];
        match self.exports[target].get(name) {
            Some(v) => Ok(v.clone()),
            None if self.erased_type_export(target, name, 0) => err_help(
                span,
                format!("`{name}` comes from an installed package or JavaScript module, whose types Zero erases"),
                "describe the value with a local type, or narrow it with `typeof`/`instanceof` at the call site",
            ),
            None => {
                let mut d = Diag::new(span, format!("\"{source}\" has no export named `{name}`"));
                if let Some(s) = suggest(name, self.exports[target].keys().map(String::as_str)) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                Err(Ctl::Err(d))
            }
        }
    }

    fn bind_imports(&mut self, id: usize, source: &str, specs: &[ImportSpec], span: Span, scope: &Rc<Scope>) -> R<()> {
        for spec in specs {
            if spec.type_only {
                continue;
            }
            // TypeScript elides imports that only name types.
            if let ImportName::Named(name) = &spec.imported
                && self.program.modules[id].dynamic
                && let Some(&target) = self.program.resolved.get(&(id, source.to_string()))
                && self.erased_type_export(target, name, 0)
            {
                continue;
            }
            let value = match &spec.imported {
                ImportName::Named(n) => self.import_value(id, source, n, spec.span)?,
                ImportName::Default if crate::builtins::module(source).is_some() => {
                    Value::Builtin(Builtin::NodeCrypto(None))
                }
                ImportName::Default => {
                    if crate::loader::builtin_module(source) {
                        return err_help(
                            spec.span,
                            format!("\"{source}\" has no default export"),
                            format!("import names: `import {{ ... }} from \"{source}\"`"),
                        );
                    }
                    self.import_value(id, source, "default", spec.span)?
                }
                ImportName::Namespace if crate::builtins::module(source).is_some() => {
                    Value::Builtin(Builtin::NodeCrypto(None))
                }
                ImportName::Namespace => {
                    if source == WORDPRESS_MODULE {
                        return err_help(
                            spec.span,
                            "\"php/wordpress\" can't be imported as a namespace",
                            "import the names you use: `import { abilities } from \"php/wordpress\"`",
                        );
                    }
                    if source == ZERO_MODULE {
                        let mut map = IndexMap::new();
                        for n in ZERO_EXPORTS {
                            map.insert(n.to_string(), zero_export(n, span)?);
                        }
                        Value::object(map)
                    } else {
                        let target = self.program.resolved[&(id, source.to_string())];
                        Value::object(self.exports[target].clone())
                    }
                }
            };
            scope.vars.borrow_mut().insert(
                spec.local.clone(),
                Binding {
                    initialized: true,
                    value,
                    mutable: false,
                    module_level: true,
                    ann: None,
                },
            );
        }
        Ok(())
    }

    fn bind_pattern(&mut self, pat: &Pat, value: Value, scope: &Rc<Scope>, mutable: bool) -> R<()> {
        self.initialize_pattern(pat, value, scope, Some(mutable))
    }

    /// A `var` initializer writes its existing function binding, including when
    /// that binding is a parameter. Lexical declarations create a fresh binding.
    fn initialize_pattern(&mut self, pat: &Pat, value: Value, scope: &Rc<Scope>, declaration: Option<bool>) -> R<()> {
        match pat {
            Pat::Id(name, _) => {
                if let Some(mutable) = declaration {
                    declare(scope, name, value, mutable);
                } else {
                    self.assign_ident(name, value, scope, pat.span())?;
                }
                Ok(())
            }
            Pat::Default(inner, default) => {
                let v = if matches!(value, Value::Undef) {
                    self.eval(default, scope)?
                } else {
                    value
                };
                self.initialize_pattern(inner, v, scope, declaration)
            }
            Pat::Obj { props, rest, span } => {
                if let Value::Builtin(Builtin::NodeCrypto(None)) = &value {
                    // `const { createHash } = require("node:crypto")`: each name is that function.
                    if rest.is_some() {
                        return err(
                            *span,
                            "list the node:crypto functions you use; a rest binding isn't supported",
                        );
                    }
                    for (key, p) in props {
                        let f =
                            node_crypto_export(crate::builtins::BuiltinModule::NodeCrypto, "node:crypto", key, *span)?;
                        self.initialize_pattern(p, f, scope, declaration)?;
                    }
                    return Ok(());
                }
                let Value::Object(obj) = &value else {
                    return err(*span, format!("can't destructure {} as an object", value.describe()));
                };
                for (key, p) in props {
                    let v = self.get_prop(&value, key, p.span())?;
                    self.initialize_pattern(p, v, scope, declaration)?;
                }
                if let Some((name, _)) = rest {
                    let mut map = obj.borrow().clone();
                    for (key, _) in props {
                        map.shift_remove(key);
                    }
                    self.initialize_pattern(&Pat::Id(name.clone(), *span), Value::object(map), scope, declaration)?;
                }
                Ok(())
            }
            Pat::Arr { elems, rest, span } => {
                let mut items = ValueIterator::new(value, *span)?;
                for p in elems {
                    let item = items.next(*span)?.unwrap_or(Value::Undef);
                    if let Some(p) = p {
                        self.initialize_pattern(p, item, scope, declaration)?;
                    }
                }
                if let Some((name, _)) = rest {
                    let mut tail = Vec::new();
                    while let Some(item) = items.next(*span)? {
                        self.tick(*span)?;
                        tail.push(item);
                    }
                    self.initialize_pattern(&Pat::Id(name.clone(), *span), Value::array(tail), scope, declaration)?;
                }
                Ok(())
            }
        }
    }

    /// A destructuring assignment, in JavaScript order: each leaf resolves its target, then reads
    /// its value, then applies its default, then writes.
    fn assign_pattern(&mut self, pattern: &AssignPat, value: Value, scope: &Rc<Scope>) -> R<()> {
        self.assign_pattern_from(pattern, PatternSource::Value(value), scope)
    }

    fn read_source(&self, source: PatternSource, span: Span) -> R<Value> {
        match source {
            PatternSource::Value(value) => Ok(value),
            PatternSource::Property(object, key) => self.get_prop(&object, &key, span),
        }
    }

    fn assign_pattern_from(&mut self, pattern: &AssignPat, source: PatternSource, scope: &Rc<Scope>) -> R<()> {
        let span = pattern.span();
        let (target, default) = match pattern {
            AssignPat::Target(target) => (Some(target), None),
            AssignPat::Default(inner, default) => match &**inner {
                AssignPat::Target(target) => (Some(target), Some(default)),
                _ => (None, Some(default)),
            },
            _ => (None, None),
        };
        if let Some(target) = target {
            let place = self.capture_place(target, scope)?;
            let mut value = self.read_source(source, span)?;
            if let Some(default) = default
                && matches!(value, Value::Undef)
            {
                value = self.eval(default, scope)?;
            }
            return self.write_place(place, value, scope, target.span);
        }
        let mut value = self.read_source(source, span)?;
        let inner = match pattern {
            AssignPat::Default(inner, default) => {
                if matches!(value, Value::Undef) {
                    value = self.eval(default, scope)?;
                }
                &**inner
            }
            other => other,
        };
        match inner {
            AssignPat::Obj { props, rest, span } => {
                let Value::Object(obj) = &value else {
                    return err(*span, format!("can't destructure {} as an object", value.describe()));
                };
                let mut taken = Vec::new();
                for (key, p) in props {
                    let key = match key {
                        PropKey::Static(k) => k.clone(),
                        PropKey::Computed(k) => {
                            let k = self.eval(k, scope)?;
                            require_static_coercion(&k, *span)?;
                            k.to_js_string()
                                .ok_or_else(|| Ctl::Err(Diag::new(*span, "destructuring keys must be primitive")))?
                        }
                    };
                    taken.push(key.clone());
                    self.assign_pattern_from(p, PatternSource::Property(value.clone(), key), scope)?;
                }
                if let Some(r) = rest {
                    let mut map = obj.borrow().clone();
                    for key in &taken {
                        map.shift_remove(key);
                    }
                    self.assign_pattern_from(r, PatternSource::Value(Value::object(map)), scope)?;
                }
                Ok(())
            }
            AssignPat::Arr { elems, rest, span } => {
                let mut items = ValueIterator::new(value, *span)?;
                for p in elems {
                    let item = items.next(*span)?.unwrap_or(Value::Undef);
                    if let Some(p) = p {
                        self.assign_pattern_from(p, PatternSource::Value(item), scope)?;
                    }
                }
                if let Some(r) = rest {
                    let mut tail = Vec::new();
                    while let Some(item) = items.next(*span)? {
                        self.tick(*span)?;
                        tail.push(item);
                    }
                    self.assign_pattern_from(r, PatternSource::Value(Value::array(tail)), scope)?;
                }
                Ok(())
            }
            AssignPat::Target(_) | AssignPat::Default(..) => unreachable!("handled above"),
        }
    }

    fn exec_block(&mut self, body: &[Stmt], parent: &Rc<Scope>) -> R<Flow> {
        let scope = Scope::new(Some(parent.clone()), parent.module);
        hoist(body, &scope);
        self.exec_body(body, &scope)
    }

    fn exec_body(&mut self, body: &[Stmt], scope: &Rc<Scope>) -> R<Flow> {
        for stmt in body {
            match self.exec(stmt, scope)? {
                Flow::Normal => {}
                other => return Ok(other),
            }
        }
        Ok(Flow::Normal)
    }

    fn exec(&mut self, stmt: &Stmt, scope: &Rc<Scope>) -> R<Flow> {
        // Labels apply to this statement only; a loop keeps them for `continue label`.
        let labels = std::mem::take(&mut self.pending_labels);
        match stmt {
            Stmt::Decl {
                kind,
                pat,
                init,
                span,
                ann,
            } => {
                self.tick(*span)?;
                if *kind == DeclKind::Var && init.is_none() {
                    return Ok(Flow::Normal);
                }
                let v = match init {
                    Some(e) => self.eval(e, scope)?,
                    None => Value::Undef,
                };
                self.initialize_pattern(
                    pat,
                    v,
                    scope,
                    (*kind != DeclKind::Var).then_some(*kind == DeclKind::Let),
                )?;
                if let (Pat::Id(name, _), Some(a)) = (pat, ann)
                    && let Some(b) = scope.vars.borrow_mut().get_mut(name)
                {
                    b.ann = Some((a.clone(), scope.module));
                }
            }
            Stmt::Func(_) | Stmt::TypeAlias { .. } => {}
            Stmt::Expr(e) => {
                self.eval(e, scope)?;
            }
            Stmt::Return(e, _) => {
                let v = match e {
                    Some(e) => self.eval(e, scope)?,
                    None => Value::Undef,
                };
                return Ok(Flow::Return(v));
            }
            Stmt::If(test, then, otherwise) => {
                if self.eval_static_data(test, scope)?.truthy() {
                    return self.exec_block(then, scope);
                } else if let Some(o) = otherwise {
                    return self.exec_block(o, scope);
                }
            }
            Stmt::Block(body) => return self.exec_block(body, scope),
            Stmt::For {
                init,
                test,
                update,
                body,
                span,
            } => {
                let mut loop_scope = Scope::new(Some(scope.clone()), scope.module);
                let per_iteration = matches!(
                    init.as_deref(),
                    Some(Stmt::Decl {
                        kind: DeclKind::Let,
                        ..
                    })
                );
                if let Some(init) = init {
                    hoist(std::slice::from_ref(init), &loop_scope);
                    self.exec(init, &loop_scope)?;
                }
                if per_iteration {
                    loop_scope = loop_scope.fork_bindings();
                }
                loop {
                    self.tick(*span)?;
                    if let Some(t) = test
                        && !self.eval_static_data(t, &loop_scope)?.truthy()
                    {
                        break;
                    }
                    match loop_step(self.exec_block(body, &loop_scope)?, &labels) {
                        LoopStep::Next => {}
                        LoopStep::Exit => break,
                        LoopStep::Leave(flow) => return Ok(flow),
                    }
                    if per_iteration {
                        loop_scope = loop_scope.fork_bindings();
                    }
                    if let Some(u) = update {
                        self.eval(u, &loop_scope)?;
                    }
                }
            }
            Stmt::ForOf {
                is_await,
                kind,
                pat,
                iter,
                body,
                span,
            } => {
                if *is_await {
                    return err_help(
                        *span,
                        "`for await` can't run while the config is evaluated",
                        "iterate asynchronously inside hooks and actions, which run in PHP",
                    );
                }
                if *kind == DeclKind::Var && !matches!(pat, Pat::Id(..)) {
                    return err_help(
                        *span,
                        "destructured var loops aren't supported yet",
                        "use const or let for loop destructuring",
                    );
                }
                let head = Scope::new(Some(scope.clone()), scope.module);
                if *kind != DeclKind::Var {
                    predeclare(pat, &head, *kind == DeclKind::Let);
                }
                let mut items = ValueIterator::new(self.eval(iter, &head)?, iter.span)?;
                while let Some(item) = items.next(iter.span)? {
                    self.tick(*span)?;
                    let iter_scope = Scope::new(Some(scope.clone()), scope.module);
                    if *kind != DeclKind::Var {
                        predeclare(pat, &iter_scope, *kind == DeclKind::Let);
                    }
                    self.initialize_pattern(
                        pat,
                        item,
                        &iter_scope,
                        (*kind != DeclKind::Var).then_some(*kind == DeclKind::Let),
                    )?;
                    match loop_step(self.exec_block(body, &iter_scope)?, &labels) {
                        LoopStep::Next => {}
                        LoopStep::Exit => break,
                        LoopStep::Leave(flow) => return Ok(flow),
                    }
                }
            }
            Stmt::ForIn {
                kind,
                target,
                iter,
                body,
                span,
            } => {
                let head = Scope::new(Some(scope.clone()), scope.module);
                if let Some(kind) = kind.filter(|kind| *kind != DeclKind::Var) {
                    let ExprKind::Ident(name) = &target.kind else {
                        unreachable!("declared for-in identifier")
                    };
                    predeclare(&Pat::Id(name.clone(), target.span), &head, kind == DeclKind::Let);
                }
                let source = self.eval(iter, &head)?;
                let keys = enumerable_keys(&source, iter.span)?;
                for key in keys {
                    self.tick(*span)?;
                    let iter_scope = Scope::new(Some(scope.clone()), scope.module);
                    if let Some(kind) = kind.filter(|kind| *kind != DeclKind::Var) {
                        let ExprKind::Ident(name) = &target.kind else {
                            unreachable!("declared for-in identifier")
                        };
                        declare(&iter_scope, name, Value::str(&key), kind == DeclKind::Let);
                    } else {
                        self.assign(target, Value::str(&key), &iter_scope)?;
                    }
                    match loop_step(self.exec_block(body, &iter_scope)?, &labels) {
                        LoopStep::Next => {}
                        LoopStep::Exit => break,
                        LoopStep::Leave(flow) => return Ok(flow),
                    }
                }
            }
            Stmt::While(test, body) => loop {
                self.tick(test.span)?;
                if !self.eval_static_data(test, scope)?.truthy() {
                    break;
                }
                match loop_step(self.exec_block(body, scope)?, &labels) {
                    LoopStep::Next => {}
                    LoopStep::Exit => break,
                    LoopStep::Leave(flow) => return Ok(flow),
                }
            },
            Stmt::DoWhile(body, test) => loop {
                self.tick(test.span)?;
                match loop_step(self.exec_block(body, scope)?, &labels) {
                    LoopStep::Next => {}
                    LoopStep::Exit => break,
                    LoopStep::Leave(flow) => return Ok(flow),
                }
                if !self.eval_static_data(test, scope)?.truthy() {
                    break;
                }
            },
            Stmt::Labeled(label, body, _) => {
                let mut inner = labels;
                inner.push(label.clone());
                self.pending_labels = inner;
                return match self.exec(body, scope)? {
                    Flow::Break(Some(l)) if l == *label => Ok(Flow::Normal),
                    other => Ok(other),
                };
            }
            Stmt::Break(label, _) => return Ok(Flow::Break(label.clone())),
            Stmt::Continue(label, _) => return Ok(Flow::Continue(label.clone())),
            Stmt::Throw(e) => {
                let v = self.eval(e, scope)?;
                return Err(Ctl::Throw(v, e.span));
            }
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                let result = match self.exec_block(block, scope) {
                    Err(Ctl::Throw(v, _)) if handler.is_some() => {
                        let catch_scope = Scope::new(Some(scope.clone()), scope.module);
                        if let Some((name, _)) = param {
                            declare(&catch_scope, name, v, true);
                        }
                        self.exec_block(handler.as_ref().expect("handler"), &catch_scope)
                    }
                    other => other,
                };
                if let Some(f) = finalizer {
                    match self.exec_block(f, scope)? {
                        Flow::Normal => {}
                        other => return Ok(other),
                    }
                }
                return result;
            }
            Stmt::Switch { disc, cases, .. } => {
                let d = self.eval_static_data(disc, scope)?;
                let switch_scope = Scope::new(Some(scope.clone()), scope.module);
                let mut start = None;
                for (i, (test, _)) in cases.iter().enumerate() {
                    if let Some(t) = test
                        && self.eval_static_data(t, &switch_scope)?.strict_eq(&d)
                    {
                        start = Some(i);
                        break;
                    }
                }
                if let Some(start) = start.or_else(|| cases.iter().position(|(t, _)| t.is_none())) {
                    for (_, body) in &cases[start..] {
                        match self.exec_block(body, &switch_scope)? {
                            Flow::Normal => {}
                            Flow::Break(None) => break,
                            other => return Ok(other),
                        }
                    }
                }
            }
            Stmt::Import { span, .. } | Stmt::ExportNames { span, .. } | Stmt::ExportAll { span, .. } => {
                return err(*span, "imports and exports belong at the top level of a module");
            }
            Stmt::ExportDefault(e) => return err(e.span, "`export default` belongs at the top level of a module"),
            Stmt::Exported(inner) => return self.exec(inner, scope),
        }
        Ok(Flow::Normal)
    }

    fn lookup(&self, name: &str, scope: &Rc<Scope>, span: Span) -> R<Value> {
        if let Some(b) = scope.lookup(name) {
            if !b.initialized {
                return err_help(
                    span,
                    format!("`{name}` isn't initialized"),
                    "initialize the lexical binding before reading or assigning it",
                );
            }
            return Ok(b.value);
        }
        if let Some(g) = GLOBALS.iter().find(|g| **g == name) {
            return Ok(match *g {
                "Infinity" => Value::Num(f64::INFINITY),
                "NaN" => Value::Num(f64::NAN),
                g => Value::Builtin(Builtin::Global(g)),
            });
        }
        let mut d = Diag::new(span, format!("`{name}` isn't defined"));
        let mut candidates: Vec<String> = Vec::new();
        let mut s = Some(scope.clone());
        while let Some(sc) = s {
            candidates.extend(sc.vars.borrow().keys().cloned());
            s = sc.parent.clone();
        }
        if ZERO_EXPORTS.contains(&name) {
            d = d.help(format!("import it: `import {{ {name} }} from \"zero\"`"));
        } else if let Some(sug) = suggest(name, candidates.iter().map(String::as_str)) {
            d = d.help(format!("did you mean `{sug}`?"));
        }
        Err(Ctl::Err(d))
    }

    fn assign_ident(&mut self, name: &str, value: Value, scope: &Rc<Scope>, span: Span) -> R<()> {
        let mut s = Some(scope.clone());
        while let Some(sc) = s {
            if let Some(b) = sc.vars.borrow_mut().get_mut(name) {
                if !b.initialized {
                    return err_help(
                        span,
                        format!("`{name}` isn't initialized"),
                        "initialize the lexical binding before reading or assigning it",
                    );
                }
                if !b.mutable {
                    return err(span, format!("can't assign to `{name}`: it's a constant"));
                }
                if sc.exported.borrow().contains(name) {
                    return err_help(
                        span,
                        "reassigning named exports isn't supported",
                        "finish composing the value before exporting it, or export a const object; live import bindings aren't modeled",
                    );
                }
                b.value = value;
                return Ok(());
            }
            s = sc.parent.clone();
        }
        err(span, format!("`{name}` isn't defined"))
    }

    pub fn eval(&mut self, e: &Expr, scope: &Rc<Scope>) -> R<Value> {
        self.tick(e.span)?;
        Ok(match &e.kind {
            ExprKind::Class(class) => {
                if !scope.is_module_scope() {
                    return err(e.span, "class declarations must be at module scope");
                }
                let mut builtin_base = None;
                let base = match &class.base {
                    Some(name) => match self.lookup(name, scope, e.span)? {
                        Value::Class(c) => Some(c),
                        Value::Builtin(Builtin::Global(g @ ("Error" | "TypeError" | "RangeError"))) => {
                            builtin_base = Some(g);
                            None
                        }
                        _ => {
                            return err_help(
                                e.span,
                                "extend a named Zero class or Error; other built-in and dynamic inheritance are unsupported",
                                "extend Error, TypeError, RangeError or a class declared in this program",
                            );
                        }
                    },
                    None => None,
                };
                let mut statics = IndexMap::new();
                for field in &class.fields {
                    if field.is_static {
                        let value = match &field.init {
                            Some(e) => self.eval_static_data(e, scope)?,
                            None => Value::Undef,
                        };
                        statics.insert(field.name.clone(), value);
                    }
                }
                Value::Class(Rc::new(ClassValue {
                    class: class.clone(),
                    base,
                    builtin_base,
                    env: scope.clone(),
                    statics,
                }))
            }
            ExprKind::Instanceof(_, _) => return err(e.span, "instanceof is only available inside compiled callbacks"),
            // Evaluator arrays carry no named properties, so the strings array's `raw` can't be
            // modeled during configuration evaluation; handlers compile tagged templates.
            ExprKind::BigInt(_) => {
                return err_help(
                    e.span,
                    "BigInt is only available inside compiled callbacks",
                    "compute it inside a handler, or use a number in configuration",
                );
            }
            ExprKind::TemplateStrings { .. } => {
                return err_help(
                    e.span,
                    "tagged templates are only available inside compiled callbacks",
                    "call the tag inside a handler, or use an ordinary template literal",
                );
            }
            ExprKind::AssignPattern(pattern, value) => {
                let value = self.eval(value, scope)?;
                self.assign_pattern(pattern, value.clone(), scope)?;
                value
            }
            ExprKind::Delete(target) => {
                let inner = match &target.kind {
                    ExprKind::Chain(inner) => inner,
                    _ => target,
                };
                let (object, key) = match &inner.kind {
                    ExprKind::Member {
                        obj, prop, optional, ..
                    } => {
                        let object = self.eval(obj, scope)?;
                        if *optional && matches!(object, Value::Null | Value::Undef) {
                            return Ok(Value::Bool(true));
                        }
                        (object, prop.clone())
                    }
                    ExprKind::Index { obj, index, optional } => {
                        let object = self.eval(obj, scope)?;
                        if *optional && matches!(object, Value::Null | Value::Undef) {
                            return Ok(Value::Bool(true));
                        }
                        let key = self.eval(index, scope)?;
                        require_static_data(&key, e.span)?;
                        require_static_coercion(&key, e.span)?;
                        let Some(key) = key.to_js_string() else {
                            return err(e.span, "`delete` at build time needs a primitive property key");
                        };
                        (object, key)
                    }
                    _ => return err(e.span, "`delete` needs an object property"),
                };
                match &object {
                    Value::Object(o) => {
                        o.borrow_mut().shift_remove(&key);
                    }
                    Value::Array(a) => {
                        let in_range = key
                            .parse::<usize>()
                            .is_ok_and(|i| i.to_string() == key && i < a.borrow().len());
                        if key == "length" || in_range {
                            return err_help(
                                e.span,
                                "`delete` would leave a hole in a dense Zero array",
                                "use splice to remove elements",
                            );
                        }
                    }
                    Value::Null | Value::Undef => {
                        return err(e.span, "`delete` on null or undefined throws a TypeError");
                    }
                    _ => {
                        return err_help(
                            e.span,
                            "`delete` at build time removes only plain object properties",
                            "move the deletion into a handler",
                        );
                    }
                }
                Value::Bool(true)
            }
            ExprKind::In(key, object) => {
                let key = self.eval(key, scope)?;
                let object = self.eval(object, scope)?;
                require_static_data(&key, e.span)?;
                require_static_coercion(&key, e.span)?;
                let Some(key) = key.to_js_string() else {
                    return err(e.span, "`in` at build time needs a primitive property key");
                };
                Value::Bool(build_time_in(&key, &object, e.span)?)
            }
            ExprKind::Num(n) => Value::Num(*n),
            ExprKind::Str(s) => Value::str(s),
            ExprKind::Bool(b) => Value::Bool(*b),
            ExprKind::Null => Value::Null,
            ExprKind::Undefined => Value::Undef,
            ExprKind::Regex { pattern, flags } => {
                crate::builtins::check_regex(pattern, flags, e.span).map_err(Ctl::Err)?;
                Value::Regex(Rc::from(pattern.as_str()), Rc::from(flags.as_str()))
            }
            ExprKind::Template(quasis, exprs) => {
                let mut out = quasis[0].clone();
                for (i, x) in exprs.iter().enumerate() {
                    let v = self.eval_static_data(x, scope)?;
                    require_static_coercion(&v, x.span)?;
                    match v.to_js_string() {
                        Some(s) => out.push_str(&s),
                        None => return err(x.span, format!("can't put {} into a string", v.describe())),
                    }
                    out.push_str(&quasis[i + 1]);
                }
                Value::str(&out)
            }
            ExprKind::Ident(name) => self.lookup(name, scope, e.span)?,
            ExprKind::Array(items) => {
                let mut out = Vec::new();
                for item in items {
                    match item {
                        ArrEl::Item(x) => out.push(self.eval(x, scope)?),
                        ArrEl::Spread(x) => match self.eval(x, scope)? {
                            Value::Array(a) => out.extend(a.borrow().iter().cloned()),
                            Value::Arguments(object) => out.extend(arguments_items(&object, x.span)?),
                            other => return err(x.span, format!("can't spread {} into an array", other.describe())),
                        },
                    }
                }
                Value::array(out)
            }
            ExprKind::Object(props) => {
                let mut map = IndexMap::new();
                let mut spans = Vec::new();
                let mut duplicates = Vec::new();
                for p in props {
                    match p {
                        Prop::KV(key, value) => {
                            let k = match key {
                                PropKey::Static(s) => s.clone(),
                                PropKey::Computed(x) => match self.eval_static_data(x, scope)? {
                                    Value::Str(s) => s.to_string(),
                                    Value::Num(n) => js_number_to_string(n),
                                    Value::Symbol(_) => {
                                        return err_help(
                                            x.span,
                                            "symbol-keyed properties can't be created while the config is evaluated",
                                            "create the object inside a function that runs in PHP, or use a string key",
                                        );
                                    }
                                    other => return err(x.span, format!("can't use {} as a key", other.describe())),
                                },
                            };
                            let v = self.eval(value, scope)?;
                            if map.contains_key(&k) {
                                duplicates.push((k.clone(), value.span));
                            }
                            spans.push((k.clone(), value.span));
                            map.insert(k, v);
                        }
                        Prop::Spread(x) => match self.eval(x, scope)? {
                            src @ Value::Object(_) => {
                                let Value::Object(o) = &src else { unreachable!() };
                                if let Some((_, inherited)) = self.duplicate_keys.get(&ptr_of(&src).expect("object")) {
                                    duplicates.extend(inherited.iter().cloned());
                                }
                                for (k, v) in o.borrow().iter() {
                                    if map.contains_key(k) {
                                        duplicates.push((k.clone(), x.span));
                                    }
                                    map.insert(k.clone(), v.clone());
                                    spans.push((k.clone(), self.key_span(&src, k).unwrap_or(x.span)));
                                }
                            }
                            Value::Arguments(object) => {
                                let source = Value::Arguments(object.clone());
                                for key in enumerable_keys(&source, x.span)? {
                                    let value = object.borrow().get(&key).cloned().unwrap_or(Value::Undef);
                                    if map.contains_key(&key) {
                                        duplicates.push((key.clone(), x.span));
                                    }
                                    spans.push((key.clone(), x.span));
                                    map.insert(key, value);
                                }
                            }
                            Value::Undef | Value::Null => {}
                            other => return err(x.span, format!("can't spread {} into an object", other.describe())),
                        },
                    }
                }
                let v = Value::object(map);
                self.record_duplicates(&v, duplicates);
                let p = ptr_of(&v).expect("object");
                for (k, s) in spans {
                    self.key_spans.insert((p, k), s);
                }
                v
            }
            ExprKind::Func(f) => Value::Closure(Rc::new(Closure::new(f.clone(), scope.clone()))),
            ExprKind::Unary(op, x) => {
                let v = self.eval_static_data(x, scope)?;
                if matches!(op, UnOp::Neg | UnOp::Plus | UnOp::BitNot) {
                    require_static_coercion(&v, e.span)?;
                }
                match op {
                    UnOp::Not => Value::Bool(!v.truthy()),
                    UnOp::Neg => Value::Num(-to_number(&v)),
                    UnOp::Plus => Value::Num(to_number(&v)),
                    UnOp::BitNot => Value::Num(f64::from(!crate::num::to_int32(to_number(&v)))),
                    UnOp::TypeOf => {
                        if let Value::Builtin(Builtin::Global(name @ ("process" | "require" | "crypto"))) = &v {
                            return err_help(
                                x.span,
                                format!("typeof `{name}` depends on the host and isn't supported"),
                                "use supported JavaScript values instead of host-global detection",
                            );
                        }
                        Value::str(v.type_of())
                    }
                }
            }
            ExprKind::Binary(op, l, r) => {
                let a = self.eval(l, scope)?;
                let b = self.eval(r, scope)?;
                binary(*op, &a, &b, e.span)?
            }
            ExprKind::IsNullish(x, negated) => {
                let v = self.eval_static_data(x, scope)?;
                Value::Bool(matches!(v, Value::Null | Value::Undef) != *negated)
            }
            ExprKind::Logical(op, l, r) => {
                let a = self.eval_static_data(l, scope)?;
                match op {
                    LogOp::And if a.truthy() => self.eval(r, scope)?,
                    LogOp::Or if !a.truthy() => self.eval(r, scope)?,
                    LogOp::Nullish if matches!(a, Value::Null | Value::Undef) => self.eval(r, scope)?,
                    _ => a,
                }
            }
            ExprKind::Cond(t, a, b) => {
                if self.eval_static_data(t, scope)?.truthy() {
                    self.eval(a, scope)?
                } else {
                    self.eval(b, scope)?
                }
            }
            ExprKind::Sequence(exprs) => {
                let mut value = Value::Undef;
                for expr in exprs {
                    value = self.eval(expr, scope)?;
                }
                value
            }
            ExprKind::Assign { op, target, value } => {
                let place = self.capture_place(target, scope)?;
                let new = match op {
                    None => self.eval(value, scope)?,
                    Some(AssignOp::Bin(b)) => {
                        let cur = self.read_place(&place, scope, target.span)?;
                        let rhs = self.eval(value, scope)?;
                        binary(*b, &cur, &rhs, e.span)?
                    }
                    Some(AssignOp::Log(l)) => {
                        let cur = self.read_place(&place, scope, target.span)?;
                        require_static_data(&cur, target.span)?;
                        let keep = match l {
                            LogOp::And => !cur.truthy(),
                            LogOp::Or => cur.truthy(),
                            LogOp::Nullish => !matches!(cur, Value::Null | Value::Undef),
                        };
                        if keep {
                            return Ok(cur);
                        }
                        self.eval(value, scope)?
                    }
                };
                self.write_place(place, new.clone(), scope, target.span)?;
                new
            }
            ExprKind::Update { inc, prefix, target } => {
                let place = self.capture_place(target, scope)?;
                let value = self.read_place(&place, scope, target.span)?;
                require_static_data(&value, target.span)?;
                require_static_coercion(&value, e.span)?;
                let old = to_number(&value);
                let new = if *inc { old + 1.0 } else { old - 1.0 };
                self.write_place(place, Value::Num(new), scope, target.span)?;
                Value::Num(if *prefix { new } else { old })
            }
            ExprKind::Call {
                callee,
                args,
                optional,
                type_args,
            } => {
                let mut call_span = e.span;
                let f = match &callee.kind {
                    ExprKind::Member {
                        obj,
                        prop,
                        optional: o,
                        prop_span,
                    } => {
                        let recv = self.eval(obj, scope)?;
                        if *o && matches!(recv, Value::Null | Value::Undef) {
                            return Err(Ctl::Short);
                        }
                        if matches!(recv, Value::Column(_)) {
                            // Column modifiers point at `.max(...)`, not at the start of the chain.
                            call_span = Span {
                                start: prop_span.start,
                                ..e.span
                            };
                        }
                        self.get_prop(&recv, prop, *prop_span)?
                    }
                    ExprKind::Index {
                        obj,
                        index,
                        optional: o,
                    } => {
                        let recv = self.eval(obj, scope)?;
                        if *o && matches!(recv, Value::Null | Value::Undef) {
                            return Err(Ctl::Short);
                        }
                        let index = self.eval(index, scope)?;
                        self.get_index(&recv, &index, callee.span)?
                    }
                    _ => self.eval(callee, scope)?,
                };
                if *optional && matches!(f, Value::Null | Value::Undef) {
                    return Err(Ctl::Short);
                }
                let argv = self.eval_args(args, scope)?;
                if let Value::Bound(recv, m) = &f
                    && &**m == "$type"
                    && let Value::Column(c) = recv.as_ref()
                {
                    crate::builtins::check(
                        "column",
                        "$type",
                        argv.len(),
                        crate::builtins::Phase::Evaluator,
                        call_span,
                    )
                    .map_err(Ctl::Err)?;
                    let Some(t) = type_args.first() else {
                        return err(e.span, "`$type` needs a type argument: `json().$type<string[]>()`");
                    };
                    if type_args.len() != 1 {
                        return err_help(
                            e.span,
                            "$type takes exactly one type argument",
                            "pass one type and no runtime arguments",
                        );
                    }
                    let mut c = (**c).clone();
                    c.ts_type = Some((t.clone(), scope.module, call_span));
                    return Ok(Value::Column(Rc::new(c)));
                }
                if let Value::Bound(receiver, method) = &f
                    && matches!(callee.kind, ExprKind::Member { .. } | ExprKind::Index { .. })
                {
                    self.call_method(receiver, method, argv, call_span)?
                } else {
                    self.call(&f, argv, call_span)?
                }
            }
            ExprKind::New { callee, args, .. } => {
                let f = self.eval(callee, scope)?;
                let argv = self.eval_args(args, scope)?;
                let constructor = match &f {
                    Value::Builtin(Builtin::Global(g)) if *g != "Date" => Some(*g),
                    Value::Builtin(Builtin::HttpError) => Some("HttpError"),
                    _ => None,
                };
                if let Some(name) = constructor {
                    crate::builtins::check(
                        "constructor",
                        name,
                        argv.len(),
                        crate::builtins::Phase::Evaluator,
                        e.span,
                    )
                    .map_err(Ctl::Err)?;
                }
                match f {
                    Value::Class(_) => {
                        return err(
                            e.span,
                            "class instances are only constructed inside compiled callbacks; configuration stays plain data",
                        );
                    }
                    Value::Builtin(Builtin::Global(g @ ("Map" | "Set"))) => {
                        // Module constants like `new Set([404])`: build-time contents, one runtime collection.
                        let set = g == "Set";
                        let mut entries: Vec<(Value, Value)> = Vec::new();
                        if let Some(source) = argv
                            .into_iter()
                            .next()
                            .filter(|v| !matches!(v, Value::Undef | Value::Null))
                        {
                            let mut items = ValueIterator::new(source, e.span)?;
                            while let Some(item) = items.next(e.span)? {
                                self.tick(e.span)?;
                                let (key, value) = if set {
                                    (item.clone(), item)
                                } else {
                                    let Value::Array(pair) = &item else {
                                        return err(e.span, "Map entries must be [key, value] arrays");
                                    };
                                    let pair = pair.borrow();
                                    (
                                        pair.first().cloned().unwrap_or(Value::Undef),
                                        pair.get(1).cloned().unwrap_or(Value::Undef),
                                    )
                                };
                                // SameValueZero stores -0 as +0.
                                let key = match key {
                                    Value::Num(n) => Value::Num(n + 0.0),
                                    k => k,
                                };
                                match entries.iter_mut().find(|(k, _)| k.same_value_zero(&key)) {
                                    Some(entry) => entry.1 = value,
                                    None => entries.push((key, value)),
                                }
                            }
                        }
                        return Ok(Value::Coll(Rc::new(crate::value::CollVal { set, entries })));
                    }
                    Value::Builtin(Builtin::Global(kind @ ("WeakMap" | "WeakSet"))) => {
                        Value::Weak(Rc::new(WeakVal { set: kind == "WeakSet" }))
                    }
                    Value::Builtin(Builtin::Global("Date")) => {
                        return err_help(
                            e.span,
                            "dates aren't available while the config is evaluated (builds must be repeatable)",
                            "use `new Date()` inside a hook, an action, or `$default(() => ...)`; those run in PHP",
                        );
                    }
                    other => return err(callee.span, format!("can't use `new` with {}", other.describe())),
                }
            }
            ExprKind::Member {
                obj,
                prop,
                optional,
                prop_span,
            } => {
                let v = self.eval(obj, scope)?;
                if *optional && matches!(v, Value::Null | Value::Undef) {
                    return Err(Ctl::Short);
                }
                self.stored_property(self.get_prop(&v, prop, *prop_span)?, e.span)?
            }
            ExprKind::Index { obj, index, optional } => {
                let v = self.eval(obj, scope)?;
                if *optional && matches!(v, Value::Null | Value::Undef) {
                    return Err(Ctl::Short);
                }
                let i = self.eval(index, scope)?;
                self.stored_property(self.get_index(&v, &i, e.span)?, e.span)?
            }
            ExprKind::Chain(inner) => match self.eval(inner, scope) {
                Err(Ctl::Short) => Value::Undef,
                other => other?,
            },
            ExprKind::Yield { .. } => {
                return err_help(
                    e.span,
                    "generators can't run while the config is evaluated",
                    "call generators inside hooks and actions, which run in PHP",
                );
            }
            ExprKind::Await(_) => {
                return err_help(
                    e.span,
                    "`await` can't run while the config is evaluated",
                    "await belongs inside hooks and actions, which run in PHP",
                );
            }
            ExprKind::As(x, _) | ExprKind::NonNull(x) => self.eval(x, scope)?,
        })
    }

    fn eval_static_data(&mut self, expr: &Expr, scope: &Rc<Scope>) -> R<Value> {
        let value = self.eval(expr, scope)?;
        require_static_data(&value, expr.span)?;
        Ok(value)
    }

    fn eval_args(&mut self, args: &[ArrEl], scope: &Rc<Scope>) -> R<Vec<Value>> {
        let mut out = Vec::new();
        for a in args {
            match a {
                ArrEl::Item(x) => out.push(self.eval(x, scope)?),
                ArrEl::Spread(x) => match self.eval(x, scope)? {
                    Value::Array(arr) => out.extend(arr.borrow().iter().cloned()),
                    Value::Arguments(object) => out.extend(arguments_items(&object, x.span)?),
                    other => return err(x.span, format!("can't spread {} into arguments", other.describe())),
                },
            }
        }
        Ok(out)
    }

    fn capture_place(&mut self, target: &Expr, scope: &Rc<Scope>) -> R<ValuePlace> {
        match &target.kind {
            ExprKind::Ident(name) => Ok(ValuePlace::Binding(name.clone())),
            ExprKind::Member { obj, prop, .. } => Ok(ValuePlace::Property(self.eval(obj, scope)?, Value::str(prop))),
            ExprKind::Index { obj, index, .. } => {
                let object = self.eval(obj, scope)?;
                let index = self.eval(index, scope)?;
                require_static_coercion(&index, target.span)?;
                Ok(ValuePlace::Property(object, index))
            }
            _ => err(target.span, "can't assign to this"),
        }
    }

    fn read_place(&self, place: &ValuePlace, scope: &Rc<Scope>, span: Span) -> R<Value> {
        match place {
            ValuePlace::Binding(name) => self.lookup(name, scope, span),
            ValuePlace::Property(object, index) => self.get_index(object, index, span),
        }
    }

    fn write_place(&mut self, place: ValuePlace, value: Value, scope: &Rc<Scope>, span: Span) -> R<()> {
        match place {
            ValuePlace::Binding(name) => self.assign_ident(&name, value, scope, span),
            ValuePlace::Property(object, index) => match (&object, &index) {
                (Value::Array(array), Value::Num(n)) => {
                    let mut array = array.borrow_mut();
                    if *n < 0.0 || n.fract() != 0.0 || *n as usize > array.len() {
                        return err(span, "array writes must be at an existing index or the end");
                    }
                    let n = *n as usize;
                    if n == array.len() {
                        array.push(value);
                    } else {
                        array[n] = value;
                    }
                    Ok(())
                }
                (_, Value::Str(key)) => self.set_key(&object, key, value, span),
                (_, Value::Num(n)) => self.set_key(&object, &js_number_to_string(*n), value, span),
                _ => err(span, format!("can't assign into {}", object.describe())),
            },
        }
    }

    fn assign(&mut self, target: &Expr, value: Value, scope: &Rc<Scope>) -> R<()> {
        let place = self.capture_place(target, scope)?;
        self.write_place(place, value, scope, target.span)
    }

    fn stored_property(&self, value: Value, span: Span) -> R<Value> {
        if matches!(value, Value::Bound(..)) {
            return err_help(
                span,
                "reading extracted methods isn't supported during configuration evaluation",
                "call the method on its receiver, or wrap that call in an arrow function",
            );
        }
        Ok(value)
    }

    fn get_index(&self, v: &Value, i: &Value, span: Span) -> R<Value> {
        require_static_data(i, span)?;
        match (v, i) {
            (Value::Array(a), Value::Num(n)) => self.stored_property(
                if *n >= 0.0 && n.fract() == 0.0 {
                    a.borrow().get(*n as usize).cloned().unwrap_or(Value::Undef)
                } else {
                    Value::Undef
                },
                span,
            ),
            (Value::Str(s), Value::Num(n)) => {
                if *n >= 0.0 && n.fract() == 0.0 {
                    match s.encode_utf16().nth(*n as usize) {
                        Some(unit) => utf16_value(&[unit], span),
                        None => Ok(Value::Undef),
                    }
                } else {
                    Ok(Value::Undef)
                }
            }
            (_, Value::Str(k)) => self.get_prop(v, k, span),
            (_, Value::Num(n)) => self.get_prop(v, &js_number_to_string(*n), span),
            _ => err(span, format!("can't index {} with {}", v.describe(), i.describe())),
        }
    }

    fn get_prop(&self, v: &Value, prop: &str, span: Span) -> R<Value> {
        let bound = || {
            let surface = match v {
                Value::Array(_) => "array",
                Value::Str(_) => "string",
                Value::Num(_) => "number",
                Value::Column(_) => "column",
                Value::Regex(..) => "regex",
                _ => unreachable!(),
            };
            if crate::builtins::contract(surface, prop).is_none() {
                return err_help(
                    span,
                    format!("unsupported built-in property `{surface}.{prop}`"),
                    "use a property listed in the supported built-in contract",
                );
            }
            Ok(Value::Bound(Rc::new(v.clone()), Rc::from(prop)))
        };
        match v {
            Value::Undef | Value::Null => err(span, format!("can't read `{prop}` of {}", v.describe())),
            Value::Arguments(_) if prop == "callee" => err_help(
                span,
                "restricted arguments properties aren't supported during configuration evaluation",
                "access arguments.callee inside a runtime handler; native Error descriptors aren't modeled while building",
            ),
            Value::Object(o) | Value::Arguments(o) => match o.borrow().get(prop) {
                Some(value) => self.stored_property(value.clone(), span),
                None if crate::builtins::contract("object", prop).is_some() => err_help(
                    span,
                    "reading inherited configuration properties isn't supported",
                    "use Object.hasOwn before reading a property, or store an own property",
                ),
                None => Ok(Value::Undef),
            },
            Value::Array(a) => match prop {
                "length" => Ok(Value::Num(a.borrow().len() as f64)),
                _ => match prop
                    .parse::<u32>()
                    .ok()
                    .filter(|i| *i < u32::MAX && i.to_string() == prop)
                {
                    Some(i) => self.stored_property(a.borrow().get(i as usize).cloned().unwrap_or(Value::Undef), span),
                    None => bound(),
                },
            },
            Value::Str(s) => match prop {
                "length" => Ok(Value::Num(s.encode_utf16().count() as f64)),
                _ => bound(),
            },
            Value::Coll(c) if prop == "size" => Ok(Value::Num(c.entries.len() as f64)),
            Value::Coll(_) => bound(),
            Value::Regex(p, _) if prop == "source" => Ok(Value::str(&crate::builtins::regex_source(p))),
            Value::Regex(_, f) if prop == "flags" => Ok(Value::str(f)),
            Value::Num(_) | Value::Column(_) | Value::Regex(..) => bound(),
            Value::Collection(c) => {
                if c.columns.contains_key(prop) {
                    Ok(Value::ColRef(Rc::new(ColRefVal {
                        collection: c.clone(),
                        column: prop.to_string(),
                    })))
                } else {
                    let mut d = Diag::new(span, format!("collection \"{}\" has no column `{prop}`", c.table()));
                    if let Some(s) = suggest(prop, c.columns.keys().map(String::as_str)) {
                        d = d.help(format!("did you mean `{s}`?"));
                    }
                    Err(Ctl::Err(d))
                }
            }
            Value::Builtin(Builtin::NodeCrypto(None)) => {
                node_crypto_export(crate::builtins::BuiltinModule::NodeCrypto, "node:crypto", prop, span)
            }
            Value::Builtin(Builtin::Db) => err(
                span,
                "`db` only works inside hooks and actions, which run in PHP at request time",
            ),
            Value::Builtin(Builtin::Global("globalThis")) => match global_member(prop) {
                GlobalMember::Provided("Infinity") => Ok(Value::Num(f64::INFINITY)),
                GlobalMember::Provided("NaN") => Ok(Value::Num(f64::NAN)),
                GlobalMember::Provided(g) => Ok(Value::Builtin(Builtin::Global(g))),
                GlobalMember::Absent => Ok(Value::Undef),
                GlobalMember::Refused => Err(Ctl::Err(refused_global_member(prop, span))),
            },
            Value::Builtin(Builtin::Global("Symbol")) if crate::value::WELL_KNOWN_SYMBOLS.contains(&prop) => {
                let name = crate::value::WELL_KNOWN_SYMBOLS
                    .iter()
                    .find(|n| **n == prop)
                    .expect("well-known");
                Ok(Value::Symbol(Rc::new(SymbolVal::WellKnown(name))))
            }
            Value::Symbol(symbol) if prop == "description" => {
                Ok(symbol.description().map_or(Value::Undef, |d| Value::str(&d)))
            }
            Value::Builtin(Builtin::Global(g @ ("process" | "fetch" | "crypto" | "Buffer"))) => err_help(
                span,
                format!("`{g}` only works inside hooks, actions, routes and jobs, which run in PHP at request time"),
                if *g == "process" {
                    "read `process.env.NAME` inside the handler; for webhook and upload settings use env(\"NAME\") from \"zero\""
                } else {
                    "move the call into a handler"
                },
            ),
            Value::Builtin(Builtin::Global(g)) => match (*g, prop) {
                ("Math", "PI") => Ok(Value::Num(std::f64::consts::PI)),
                ("Math", "E") => Ok(Value::Num(std::f64::consts::E)),
                ("Number", "MAX_SAFE_INTEGER") => Ok(Value::Num(9_007_199_254_740_991.0)),
                ("Number", "MIN_SAFE_INTEGER") => Ok(Value::Num(-9_007_199_254_740_991.0)),
                ("Number", "EPSILON") => Ok(Value::Num(f64::EPSILON)),
                _ if crate::builtins::contract(g, prop).is_some() => {
                    Ok(Value::Builtin(Builtin::Static(g, Rc::from(prop))))
                }
                _ => err_help(
                    span,
                    format!("unsupported built-in property `{g}.{prop}`"),
                    "use a property listed in the supported built-in contract",
                ),
            },
            Value::Closure(c) => match c.props.borrow().get(prop) {
                Some(value) => self.stored_property(value.clone(), span),
                None if crate::value::FUNCTION_BUILTIN_PROPERTIES.contains(&prop) => err_help(
                    span,
                    format!("reading `{prop}` of a function isn't supported while the config is built"),
                    "function `name`, `length`, `prototype` and `Function.prototype` members aren't modeled",
                ),
                None => Ok(Value::Undef),
            },
            other => err(span, format!("can't read `{prop}` of {}", other.describe())),
        }
    }

    fn call_closure(&mut self, f: &Rc<Closure>, args: Vec<Value>, span: Span) -> R<Value> {
        if f.func.is_async {
            return err_help(
                span,
                "async functions can't run while the config is evaluated",
                "async functions belong in hooks and actions, which run in PHP",
            );
        }
        self.depth += 1;
        if self.depth > MAX_DEPTH {
            self.depth -= 1;
            return err(span, "too much recursion while evaluating the config");
        }
        let result = self.invoke(f, args);
        self.depth -= 1;
        result
    }

    fn invoke(&mut self, f: &Rc<Closure>, args: Vec<Value>) -> R<Value> {
        let scope = Scope::new(Some(f.env.clone()), f.func.module);
        if !f.func.is_arrow {
            let receiver = usize::from(matches!(
                f.func.params.first().map(|param| &param.pat),
                Some(Pat::Id(name, _)) if name == "this"
            ));
            let supplied = args.get(receiver..).unwrap_or_default();
            let mut arguments: IndexMap<String, Value> = supplied
                .iter()
                .enumerate()
                .map(|(index, value)| (index.to_string(), value.clone()))
                .collect();
            arguments.insert("length".into(), Value::Num(supplied.len() as f64));
            let Value::Object(arguments) = Value::object(arguments) else {
                unreachable!()
            };
            declare(&scope, "arguments", Value::Arguments(arguments), true);
        }
        for p in &f.func.params {
            predeclare(&p.pat, &scope, true);
        }
        let mut args = args.into_iter();
        for p in &f.func.params {
            if p.rest {
                let rest: Vec<Value> = args.by_ref().collect();
                self.bind_pattern(&p.pat, Value::array(rest), &scope, true)?;
                continue;
            }
            let mut v = args.next().unwrap_or(Value::Undef);
            if matches!(v, Value::Undef)
                && let Some(d) = &p.default
            {
                v = self.eval(d, &scope)?;
            }
            self.bind_pattern(&p.pat, v, &scope, true)?;
        }
        match &f.func.body {
            FnBody::Expr(e) => self.eval(e, &scope),
            FnBody::Block(body) => {
                hoist(body, &scope);
                hoist_vars(body, &scope);
                match self.exec_body(body, &scope)? {
                    Flow::Return(v) => Ok(v),
                    _ => Ok(Value::Undef),
                }
            }
        }
    }

    /// An array method's callback: a global builtin (`.filter(Boolean)`, `Array.from(xs, Number)`)
    /// receives only the arguments its contract takes, as JavaScript ignores the extra index and
    /// array; `parseInt` keeps the index as its radix, as in JavaScript.
    fn callback(&mut self, f: &Value, mut args: Vec<Value>, span: Span) -> R<Value> {
        if let Value::Builtin(Builtin::Global(g)) = f
            && let Some(max) = crate::builtins::contract("global", g)
                .and_then(|c| c.evaluator)
                .map(|a| a.max)
        {
            args.truncate(max);
        }
        self.call(f, args, span)
    }

    fn call(&mut self, f: &Value, args: Vec<Value>, span: Span) -> R<Value> {
        match f {
            Value::Closure(c) => self.call_closure(c, args, span),
            Value::Builtin(b) => self.call_builtin(b, args, span),
            Value::Bound(..) => err_help(
                span,
                "reading extracted methods isn't supported during configuration evaluation",
                "call the method on its receiver, or wrap that call in an arrow function",
            ),
            other => err(span, format!("{} isn't a function", other.describe())),
        }
    }

    fn call_builtin(&mut self, b: &Builtin, args: Vec<Value>, span: Span) -> R<Value> {
        let name = match b {
            Builtin::Collection(kind) => Some(kind.builder()),
            Builtin::Id => Some("id"),
            Builtin::DefineGlobal => Some("global"),
            Builtin::DefineRelations => Some("defineRelations"),
            Builtin::DefineConfig => Some("defineConfig"),
            Builtin::Env => Some("env"),
            Builtin::Files => Some("files"),
            Builtin::Action => Some("action"),
            Builtin::Job => Some("job"),
            Builtin::RelFn(..) => Some("relation"),
            _ => None,
        };
        if let Some(name) = name {
            crate::builtins::check("zero", name, args.len(), crate::builtins::Phase::Evaluator, span)
                .map_err(Ctl::Err)?;
        }
        let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
        match b {
            Builtin::Collection(kind) => {
                let cols_val = arg(0);
                let Value::Object(cols) = &cols_val else {
                    return err(span, format!("{}() needs an object of fields first", kind.builder()));
                };
                let mut columns = IndexMap::new();
                for (k, v) in cols.borrow().iter() {
                    match v {
                        Value::Column(c) => {
                            columns.insert(k.clone(), c.clone());
                        }
                        other => {
                            return err_help(
                                self.key_span(&cols_val, k).unwrap_or(span),
                                format!("column `{k}` is {}, not a column", other.describe()),
                                "columns are built with integer(), text(), boolean(), ...",
                            );
                        }
                    }
                }
                // Both declaration and reference evaluation see the same implicit primary key.
                if !columns.values().any(|c| c.primary_key) && !columns.contains_key("id") {
                    let mut id = ColBuilder::new(ColType::Integer, span);
                    id.primary_key = true;
                    id.auto_increment = true;
                    id.not_null = true;
                    columns.shift_insert(0, "id".into(), Rc::new(id));
                }
                let options = match arg(1) {
                    Value::Undef => None,
                    o @ Value::Object(_) => Some(o),
                    other => {
                        return err(
                            span,
                            format!("collection options must be an object, not {}", other.describe()),
                        );
                    }
                };
                Ok(Value::Collection(Rc::new(CollectionVal {
                    kind: *kind,
                    table: std::cell::OnceCell::new(),
                    columns,
                    options,
                    span,
                })))
            }
            Builtin::Column(ty) => self.make_column(ty.name(), &args, span),
            Builtin::Field(name) => self.make_column(name, &args, span),
            Builtin::DefineGlobal => {
                let Value::Str(name) = arg(0) else {
                    return err(span, "global() needs a name first: `global(\"settings\", { ... })`");
                };
                let fields_val = arg(1);
                let fields = self.fields_object(
                    &fields_val,
                    span,
                    "global() needs an object of fields as its second argument",
                )?;
                let options = match arg(2) {
                    Value::Undef => None,
                    o @ Value::Object(_) => Some(o),
                    other => {
                        return err(
                            span,
                            format!("global options must be an object, not {}", other.describe()),
                        );
                    }
                };
                Ok(Value::Global(Rc::new(GlobalVal {
                    name: name.to_string(),
                    fields,
                    options,
                    span,
                })))
            }
            Builtin::Id => {
                let mut c = ColBuilder::new(ColType::Integer, span);
                c.primary_key = true;
                c.auto_increment = true;
                c.not_null = true;
                Ok(Value::Column(Rc::new(c)))
            }
            Builtin::DefineRelations => {
                let Value::Object(schema) = arg(0) else {
                    return err(
                        span,
                        "defineRelations() needs your schema first: `defineRelations(schema, (r) => ({ ... }))`",
                    );
                };
                let mut one = IndexMap::new();
                let mut many = IndexMap::new();
                let mut r = IndexMap::new();
                for (k, v) in schema.borrow().iter() {
                    if !matches!(v, Value::Collection(_)) {
                        return err(
                            span,
                            format!("schema entry `{k}` is {}, not a collection", v.describe()),
                        );
                    }
                    one.insert(
                        k.clone(),
                        Value::Builtin(Builtin::RelFn(RelKind::One, Rc::from(k.as_str()))),
                    );
                    many.insert(
                        k.clone(),
                        Value::Builtin(Builtin::RelFn(RelKind::Many, Rc::from(k.as_str()))),
                    );
                    r.insert(k.clone(), v.clone());
                }
                r.insert("one".into(), Value::object(one));
                r.insert("many".into(), Value::object(many));
                let f = arg(1);
                if !matches!(f, Value::Closure(_)) {
                    return err(span, "defineRelations() needs a function as its second argument");
                }
                let defs = self.call(&f, vec![Value::object(r)], span)?;
                let mut out = IndexMap::new();
                out.insert("schema".to_string(), arg(0));
                out.insert("relations".to_string(), defs);
                out.insert("$zeroRelations".to_string(), Value::Bool(true));
                Ok(Value::object(out))
            }
            Builtin::DefineConfig => Ok(arg(0)),
            Builtin::DefinePlugin => match arg(0) {
                config @ Value::Object(_) => Ok(Value::Plugin(Rc::new(ActionVal { config, span }))),
                _ => err_help(
                    span,
                    "definePlugin() needs `{ name, hooks?, actions?, jobs?, routes? }`",
                    "pass the plugin definition as an object literal",
                ),
            },
            Builtin::Env => match arg(0) {
                Value::Str(n) if regex::Regex::new(r"^[A-Za-z_][A-Za-z0-9_]*$").unwrap().is_match(&n) => {
                    Ok(Value::Env(n))
                }
                _ => err_help(
                    span,
                    "env() needs an environment variable name",
                    "use env(\"HOOK_SECRET\")",
                ),
            },
            Builtin::Files => {
                let Value::Str(name) = arg(0) else {
                    return err(span, "files() needs a bucket name");
                };
                let options = arg(1);
                if !matches!(options, Value::Object(_)) {
                    return err(span, "files() needs an options object");
                }
                Ok(Value::FileBucket(Rc::new(FileBucketVal {
                    name: name.to_string(),
                    options,
                    span,
                })))
            }
            Builtin::Once
            | Builtin::Jobs
            | Builtin::Mail
            | Builtin::Abilities
            | Builtin::WpFunction(_)
            | Builtin::NodeCrypto(_) => err_help(
                span,
                "once(), jobs, mail, abilities, WordPress functions and node:crypto only work inside hooks, actions, routes and jobs, which run in PHP at request time",
                "move the call into a handler",
            ),
            Builtin::Action | Builtin::Job | Builtin::Route => {
                let cfg = arg(0);
                if !matches!(cfg, Value::Object(_)) {
                    return err(
                        span,
                        if *b == Builtin::Route {
                            "route() needs `{ method, path, handler }`"
                        } else {
                            "action() needs `{ input, handler }`"
                        },
                    );
                }
                let value = Rc::new(ActionVal { config: cfg, span });
                Ok(match b {
                    Builtin::Job => Value::Job(value),
                    Builtin::Route => Value::Route(value),
                    _ => Value::Action(value),
                })
            }
            Builtin::RelFn(kind, target) => {
                let config = match arg(0) {
                    Value::Undef => None,
                    o @ Value::Object(_) => Some(o),
                    other => {
                        return err(
                            span,
                            format!("relation config must be an object, not {}", other.describe()),
                        );
                    }
                };
                Ok(Value::Relation(Rc::new(RelVal {
                    kind: *kind,
                    target: target.clone(),
                    config,
                })))
            }
            Builtin::Db => err(
                span,
                "`db` only works inside hooks and actions, which run in PHP at request time",
            ),
            Builtin::HttpError => err(span, "use `new HttpError(status, code, message)`"),
            Builtin::Global(g) => call_global(g, &args, span),
            Builtin::Static(g, m) => self.call_static(g, m, args, span),
            Builtin::Require(from) => self.require(*from, &args, span),
        }
    }

    /// An object of column builders (collection columns, sub-fields, layouts, global fields).
    fn fields_object(&self, v: &Value, span: Span, what: &str) -> R<IndexMap<String, Rc<ColBuilder>>> {
        let Value::Object(o) = v else {
            return err(span, what.to_string());
        };
        let mut out = IndexMap::new();
        for (k, f) in o.borrow().iter() {
            match f {
                Value::Column(c) => {
                    out.insert(k.clone(), c.clone());
                }
                other => {
                    return err_help(
                        self.key_span(v, k).unwrap_or(span),
                        format!("field `{k}` is {}, not a column", other.describe()),
                        "fields are built with text(), integer(), media(), ...",
                    );
                }
            }
        }
        Ok(out)
    }

    /// `text()`, `email()`, `select({ options })`, `group({ fields })`, ...
    fn make_column(&self, builder: &'static str, args: &[Value], span: Span) -> R<Value> {
        crate::builtins::check("field", builder, args.len(), crate::builtins::Phase::Evaluator, span)
            .map_err(Ctl::Err)?;
        let ty = match builder {
            "integer" => ColType::Integer,
            "real" => ColType::Real,
            "text" => ColType::Text,
            "boolean" => ColType::Boolean,
            "date" => ColType::Date,
            "timestamp" => ColType::Timestamp,
            "json" => ColType::Json,
            "email" => ColType::Email,
            "url" => ColType::Url,
            "slug" => ColType::Slug,
            "richText" => ColType::RichText,
            "media" => ColType::Media,
            "file" => ColType::File,
            "select" => ColType::Text,
            "group" => ColType::Group,
            "repeater" => ColType::Repeater,
            "blocks" => ColType::Blocks,
            _ => unreachable!("unknown builder {builder}"),
        };
        let mut c = ColBuilder::new(ty, span);
        let opts_val = args.first().cloned().unwrap_or(Value::Undef);
        let allowed: &[&str] = match builder {
            "text" => &["enum", "multiline"],
            "slug" => &["source"],
            "file" => &["bucket"],
            "media" => &["accept", "multiple"],
            "select" => &["options", "multiple"],
            "group" | "repeater" => &["fields"],
            "blocks" => &["layouts"],
            _ => &[],
        };
        let required: Option<&str> = match builder {
            "select" => Some("options"),
            "file" => Some("bucket"),
            "group" | "repeater" => Some("fields"),
            "blocks" => Some("layouts"),
            _ => None,
        };
        let opts = match &opts_val {
            Value::Undef => IndexMap::new(),
            Value::Object(o) => o.borrow().clone(),
            other => {
                return err(
                    span,
                    format!("{builder}() takes an options object, not {}", other.describe()),
                );
            }
        };
        if let Some(r) = required
            && !opts.contains_key(r)
        {
            return err_help(
                span,
                format!("{builder}() needs `{r}`"),
                match builder {
                    "select" => "select({ options: [\"a\", \"b\"] })",
                    "blocks" => "blocks({ layouts: { hero: { heading: text() } } })",
                    _ => "group({ fields: { title: text() } })",
                },
            );
        }
        let strings = |v: &Value, what: &str, sp: Span| -> R<Vec<String>> {
            let Value::Array(items) = v else {
                return err(sp, format!("`{what}` must be an array of strings"));
            };
            let mut values = Vec::new();
            for item in items.borrow().iter() {
                match item {
                    Value::Str(s) => values.push(s.to_string()),
                    other => return err(sp, format!("{what} values must be strings, got {}", other.describe())),
                }
            }
            if values.is_empty() {
                return err(sp, format!("`{what}` needs at least one value"));
            }
            Ok(values)
        };
        let mut multiple = false;
        for (k, v) in &opts {
            let ksp = self.key_span(&opts_val, k).unwrap_or(span);
            if !allowed.contains(&k.as_str()) {
                let mut d = Diag::new(ksp, format!("{builder}() has no option `{k}`"));
                if let Some(s) = suggest(k, allowed.iter().copied()) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                return Err(Ctl::Err(d));
            }
            let flag = |v: &Value| match v {
                Value::Bool(b) => Ok(*b),
                other => err(ksp, format!("`{k}` must be true or false, not {}", other.describe())),
            };
            match k.as_str() {
                "enum" | "options" => c.enum_values = Some(strings(v, k, ksp)?),
                "multiline" => c.multiline = flag(v)?,
                "multiple" => multiple = flag(v)?,
                "bucket" => match v {
                    Value::Str(s) if !s.is_empty() => c.bucket = Some(s.to_string()),
                    _ => return err(ksp, "`bucket` must be a bucket name"),
                },
                "source" => match v {
                    Value::Str(s) => c.source = Some(s.to_string()),
                    other => return err(ksp, format!("`source` must be a column name, not {}", other.describe())),
                },
                "accept" => match v {
                    Value::Str(s) if &**s == "image" || &**s == "any" => c.accept = Some(s.to_string()),
                    other => {
                        return err_help(
                            ksp,
                            format!("`accept` can't be {}", other.describe()),
                            "use \"image\" or \"any\"",
                        );
                    }
                },
                "fields" => c.fields = Some(self.fields_object(v, ksp, "`fields` must be an object of columns")?),
                "layouts" => {
                    let Value::Object(lo) = v else {
                        return err(ksp, "`layouts` must be an object like `{ hero: { heading: text() } }`");
                    };
                    let mut layouts = IndexMap::new();
                    for (lk, lv) in lo.borrow().iter() {
                        let lsp = self.key_span(v, lk).unwrap_or(ksp);
                        layouts.insert(
                            lk.clone(),
                            self.fields_object(lv, lsp, &format!("layout `{lk}` must be an object of columns"))?,
                        );
                    }
                    if layouts.is_empty() {
                        return err(ksp, "`layouts` needs at least one layout");
                    }
                    c.layouts = Some(layouts);
                }
                _ => unreachable!(),
            }
        }
        if multiple {
            c.ty = if builder == "media" {
                ColType::MediaList
            } else {
                ColType::Select
            };
        }
        if builder == "media" && c.accept.is_none() {
            c.accept = Some("any".into());
        }
        if builder == "slug" {
            c.unique = true;
        }
        Ok(Value::Column(Rc::new(c)))
    }

    fn call_static(&mut self, g: &str, m: &str, args: Vec<Value>, span: Span) -> R<Value> {
        if matches!(g, "Math" | "Number" | "JSON") || matches!((g, m), ("Array", "isArray")) {
            for value in &args {
                require_static_data(value, span)?;
            }
        }
        // hasOwn reads an object's keys only; env() tokens among its values are carried, not consumed.
        if g == "Symbol" {
            crate::builtins::check(g, m, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
            return match (m, args.first()) {
                ("for", Some(key)) => {
                    require_static_coercion(key, span)?;
                    let Some(key) = key.to_js_string() else {
                        return err(span, format!("`Symbol.for` needs a string key, not {}", key.describe()));
                    };
                    Ok(Value::Symbol(Rc::new(SymbolVal::Registered(Rc::from(key.as_str())))))
                }
                ("keyFor", Some(Value::Symbol(symbol))) => Ok(match &**symbol {
                    SymbolVal::Registered(key) => Value::Str(key.clone()),
                    _ => Value::Undef,
                }),
                (_, other) => err(
                    span,
                    format!(
                        "`Symbol.keyFor` needs a symbol, not {}",
                        other.map_or("nothing".into(), Value::describe)
                    ),
                ),
            };
        }
        if (g, m) == ("Object", "hasOwn") {
            if let Some(receiver @ Value::Env(_)) = args.first() {
                require_static_data(receiver, span)?;
            }
            if let Some(key) = args.get(1) {
                require_static_data(key, span)?;
            }
        }
        crate::builtins::check(g, m, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
        if g == "Math" {
            for value in &args {
                require_static_coercion(value, span)?;
            }
        }
        let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
        let num = |i: usize| to_number(&args.get(i).cloned().unwrap_or(Value::Undef));
        Ok(match (g, m) {
            ("Math", "max") => Value::Num(args.iter().map(to_number).fold(f64::NEG_INFINITY, crate::num::max)),
            ("Math", "min") => Value::Num(args.iter().map(to_number).fold(f64::INFINITY, crate::num::min)),
            ("Math", "floor") => Value::Num(num(0).floor()),
            ("Math", "ceil") => Value::Num(num(0).ceil()),
            ("Math", "round") => Value::Num(crate::num::round(num(0))),
            ("Math", "trunc") => Value::Num(num(0).trunc()),
            ("Math", "abs") => Value::Num(num(0).abs()),
            ("Math", "sqrt") => Value::Num(num(0).sqrt()),
            ("Math", "pow") => Value::Num(supported_power(num(0), num(1), span)?),
            ("Math", "random") => {
                return err(
                    span,
                    "`Math.random()` isn't available while the config is evaluated (builds must be repeatable)",
                );
            }
            ("Object", "keys" | "values" | "entries") => {
                let object = arg(0);
                if matches!(object, Value::Undef | Value::Null) {
                    return err(span, format!("Object.{m} needs an object, not {}", object.describe()));
                }
                let mut values = Vec::new();
                for key in enumerable_keys(&object, span)? {
                    let read = |this: &Self| match &object {
                        Value::Str(_) => this.get_index(&object, &Value::Num(key.parse().expect("string index")), span),
                        _ => this.get_prop(&object, &key, span),
                    };
                    values.push(match m {
                        "keys" => Value::str(&key),
                        "values" => read(self)?,
                        _ => Value::array(vec![Value::str(&key), read(self)?]),
                    });
                }
                Value::array(values)
            }
            ("Object", "assign") => {
                let target = arg(0);
                if !matches!(target, Value::Object(_) | Value::Closure(_)) {
                    return err_help(
                        span,
                        format!(
                            "Object.assign at build time needs an object or function target, not {}",
                            target.describe()
                        ),
                        "assign into a plain object or a function",
                    );
                }
                for source in args.iter().skip(1) {
                    if matches!(source, Value::Undef | Value::Null) {
                        continue;
                    }
                    for key in enumerable_keys(source, span)? {
                        let value = match source {
                            Value::Str(_) => {
                                self.get_index(source, &Value::Num(key.parse().expect("string index")), span)?
                            }
                            _ => self.get_prop(source, &key, span)?,
                        };
                        self.set_key(&target, &key, value, span)?;
                    }
                }
                target
            }
            ("Object", "fromEntries") => match arg(0) {
                Value::Array(a) => {
                    let mut map = IndexMap::new();
                    let mut duplicates = Vec::new();
                    for e in a.borrow().iter() {
                        let Value::Array(pair) = e else {
                            return err(span, "Object.fromEntries needs [key, value] pairs");
                        };
                        let pair = pair.borrow();
                        let key = pair.first().cloned().unwrap_or(Value::Undef);
                        require_static_coercion(&key, span)?;
                        let Some(k) = key.to_js_string() else {
                            return err(
                                span,
                                "Object.fromEntries cannot convert this property key at build time",
                            );
                        };
                        if map
                            .insert(k.clone(), pair.get(1).cloned().unwrap_or(Value::Undef))
                            .is_some()
                        {
                            duplicates.push((k, span));
                        }
                    }
                    let value = Value::object(map);
                    self.record_duplicates(&value, duplicates);
                    value
                }
                other => {
                    return err(
                        span,
                        format!("Object.fromEntries needs an array, not {}", other.describe()),
                    );
                }
            },
            ("Object", "hasOwn") => {
                let key = arg(1);
                if !matches!(
                    key,
                    Value::Str(_) | Value::Num(_) | Value::Bool(_) | Value::Null | Value::Undef
                ) {
                    return err(span, "Object.hasOwn at build time needs a primitive property key");
                }
                let key = key.to_js_string().unwrap();
                let index_in = |length| {
                    key.parse::<usize>()
                        .ok()
                        .is_some_and(|i| i.to_string() == key && i < length)
                };
                Value::Bool(match arg(0) {
                    Value::Arguments(_) if key == "callee" => true,
                    Value::Object(o) | Value::Arguments(o) => o.borrow().contains_key(&key),
                    Value::Array(a) => key == "length" || index_in(a.borrow().len()),
                    Value::Str(s) => key == "length" || index_in(s.encode_utf16().count()),
                    Value::Regex(..) => key == "lastIndex",
                    Value::Bool(_) | Value::Num(_) => false,
                    Value::Null | Value::Undef => return err(span, "Object.hasOwn cannot inspect null or undefined"),
                    _ => {
                        return err_help(
                            span,
                            "Object.hasOwn does not support this receiver at build time",
                            "inspect a plain object, array or primitive value",
                        );
                    }
                })
            }
            ("Array", "isArray") => Value::Bool(matches!(arg(0), Value::Array(_))),
            ("Array", "from") => {
                let f = arg(1);
                if !matches!(f, Value::Undef) {
                    require_callable(&f, span)?;
                }
                let source = arg(0);
                let fixed_length = match &source {
                    Value::Array(_) | Value::Arguments(_) => None,
                    Value::Object(o) => Some(array_like_length(o, span)?),
                    other => {
                        return err(
                            span,
                            format!("Array.from needs an array or {{ length }}, not {}", other.describe()),
                        );
                    }
                };
                let mut out = Vec::new();
                let mut i = 0;
                loop {
                    let length = match (&source, fixed_length) {
                        (_, Some(length)) => length,
                        (Value::Array(a), None) => a.borrow().len(),
                        (Value::Arguments(o), None) => array_like_length(o, span)?,
                        _ => unreachable!(),
                    };
                    if i >= length {
                        break;
                    }
                    let value = match &source {
                        Value::Array(a) => a.borrow().get(i).cloned().unwrap_or(Value::Undef),
                        Value::Object(o) | Value::Arguments(o) => {
                            o.borrow().get(&i.to_string()).cloned().unwrap_or(Value::Undef)
                        }
                        _ => unreachable!(),
                    };
                    out.push(if matches!(f, Value::Undef) {
                        value
                    } else {
                        self.callback(&f, vec![value, Value::Num(i as f64)], span)?
                    });
                    i += 1;
                }
                Value::array(out)
            }
            ("Number", "isInteger") => {
                Value::Bool(matches!(arg(0), Value::Num(n) if n.is_finite() && n.fract() == 0.0))
            }
            ("Number", "isFinite") => Value::Bool(matches!(arg(0), Value::Num(n) if n.is_finite())),
            ("Date", "now") => {
                return Err(Ctl::Err(
                    Diag::new(
                        span,
                        "`Date.now()` isn't available while the config is evaluated (builds must be repeatable)",
                    )
                    .code(crate::diag::Code::BuildTimeClock)
                    .help("compute the time in a hook or use timestamp().defaultNow()"),
                ));
            }
            ("console", _) => return err(span, "`console` isn't available while the config is evaluated"),
            _ => return err(span, format!("`{g}.{m}` isn't supported while the config is evaluated")),
        })
    }

    fn call_method(&mut self, recv: &Value, m: &str, args: Vec<Value>, span: Span) -> R<Value> {
        if matches!(recv, Value::Str(_) | Value::Num(_)) {
            for value in &args {
                require_static_data(value, span)?;
            }
        }
        match recv {
            Value::Column(c) => column_method(c, m, &args, span),
            Value::Str(s) => string_method(s, m, &args, span),
            Value::Num(n) => {
                crate::builtins::check("number", m, args.len(), crate::builtins::Phase::Evaluator, span)
                    .map_err(Ctl::Err)?;
                match m {
                    "toString" => Ok(Value::str(&js_number_to_string(*n))),
                    _ => err(span, format!("numbers have no method `{m}` at build time")),
                }
            }
            Value::Array(a) => self.array_method(a, m, args, span),
            Value::Coll(c) => {
                let find = |key: &Value| c.entries.iter().find(|(k, _)| k.same_value_zero(key));
                let key = args.first().cloned().unwrap_or(Value::Undef);
                match (c.set, m) {
                    (_, "has") => Ok(Value::Bool(find(&key).is_some())),
                    (false, "get") => Ok(find(&key).map_or(Value::Undef, |(_, v)| v.clone())),
                    _ => err_help(
                        span,
                        format!(
                            "`{m}` on a {} isn't available while the config is evaluated",
                            if c.set { "Set" } else { "Map" }
                        ),
                        "build the collection with its entries; mutate it inside a hook or action",
                    ),
                }
            }
            other => err(span, format!("{} has no method `{m}`", other.describe())),
        }
    }

    fn array_method(&mut self, a: &Arr, m: &str, args: Vec<Value>, span: Span) -> R<Value> {
        crate::builtins::check("array", m, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
        if matches!(m, "join" | "includes" | "indexOf") {
            require_static_data(&Value::Array(a.clone()), span)?;
        }
        if matches!(m, "join" | "includes" | "indexOf" | "slice") {
            for value in &args {
                require_static_data(value, span)?;
            }
        }
        if m == "join" {
            require_static_coercion(&Value::Array(a.clone()), span)?;
        }
        let numeric_start = if matches!(m, "includes" | "indexOf") { 1 } else { 0 };
        if matches!(m, "slice" | "join" | "includes" | "indexOf") {
            for value in args.iter().skip(numeric_start) {
                require_static_coercion(value, span)?;
            }
        }
        let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
        let items = a.borrow().clone();
        let f = arg(0);
        if matches!(
            m,
            "map" | "filter" | "find" | "findIndex" | "some" | "every" | "flatMap" | "forEach" | "reduce"
        ) {
            require_callable(&f, span)?;
        }
        Ok(match m {
            "map" | "filter" | "find" | "findIndex" | "some" | "every" | "flatMap" | "forEach" => {
                let mut out = Vec::new();
                for i in 0..items.len() {
                    let x = a.borrow().get(i).cloned();
                    let Some(x) = x.or_else(|| matches!(m, "find" | "findIndex").then_some(Value::Undef)) else {
                        if m == "map" {
                            return err(span, "map() cannot produce a sparse array at build time");
                        }
                        continue;
                    };
                    let r = self.callback(&f, vec![x.clone(), Value::Num(i as f64), Value::Array(a.clone())], span)?;
                    if matches!(m, "filter" | "find" | "findIndex" | "some" | "every") {
                        require_static_data(&r, span)?;
                    }
                    match m {
                        "map" => out.push(r),
                        "flatMap" => match r {
                            Value::Array(inner) => out.extend(inner.borrow().iter().cloned()),
                            other => out.push(other),
                        },
                        "filter" if r.truthy() => out.push(x.clone()),
                        "find" if r.truthy() => return Ok(x.clone()),
                        "findIndex" if r.truthy() => return Ok(Value::Num(i as f64)),
                        "some" if r.truthy() => return Ok(Value::Bool(true)),
                        "every" if !r.truthy() => return Ok(Value::Bool(false)),
                        _ => {}
                    }
                }
                match m {
                    "find" | "forEach" => Value::Undef,
                    "findIndex" => Value::Num(-1.0),
                    "some" => Value::Bool(false),
                    "every" => Value::Bool(true),
                    _ => Value::array(out),
                }
            }
            "reduce" => {
                let mut iter = items.into_iter().enumerate();
                let mut acc = if args.len() > 1 {
                    arg(1)
                } else {
                    match iter.next() {
                        Some((_, x)) => x,
                        None => return err(span, "reduce of an empty array with no initial value"),
                    }
                };
                for (i, _) in iter {
                    let x = a.borrow().get(i).cloned();
                    if let Some(x) = x {
                        acc = self.callback(&f, vec![acc, x, Value::Num(i as f64), Value::Array(a.clone())], span)?;
                    }
                }
                acc
            }
            "includes" | "indexOf" => {
                let (start, _) = slice_bounds(items.len() as f64, &arg(1), &Value::Undef);
                let found = items.iter().enumerate().skip(start).find(|(_, x)| {
                    if m == "includes" {
                        x.same_value_zero(&f)
                    } else {
                        x.strict_eq(&f)
                    }
                });
                if m == "includes" {
                    Value::Bool(found.is_some())
                } else {
                    Value::Num(found.map_or(-1.0, |(i, _)| i as f64))
                }
            }
            "join" => {
                let sep = match arg(0) {
                    Value::Undef => ",".to_string(),
                    v => v.to_js_string().unwrap_or_default(),
                };
                Value::str(&Value::array_join(a, &sep).unwrap_or_default())
            }
            "slice" => {
                let (s, e) = slice_bounds(items.len() as f64, &arg(0), &arg(1));
                Value::array(items[s..e.max(s)].to_vec())
            }
            "concat" => {
                let mut out = items;
                for x in args {
                    match x {
                        Value::Array(more) => out.extend(more.borrow().iter().cloned()),
                        other => out.push(other),
                    }
                }
                Value::array(out)
            }
            "push" => {
                a.borrow_mut().extend(args);
                Value::Num(a.borrow().len() as f64)
            }
            _ => return err(span, format!("arrays have no method `{m}` at build time")),
        })
    }
}

pub fn uncaught(c: Ctl) -> Diag {
    match c {
        Ctl::Err(d) => d,
        Ctl::Throw(v, span) => {
            let msg = match &v {
                Value::Object(o) => o
                    .borrow()
                    .get("message")
                    .and_then(Value::to_js_string)
                    .unwrap_or_default(),
                other => other.to_js_string().unwrap_or_else(|| other.describe()),
            };
            Diag::new(span, format!("the config threw while being evaluated: {msg}"))
        }
        Ctl::Short => Diag::new(Span::NONE, "internal: unhandled optional chain"),
    }
}

fn zero_export(name: &str, span: Span) -> R<Value> {
    Ok(Value::Builtin(match name {
        "collection" => Builtin::Collection(StorageKind::Collection),
        "table" => Builtin::Collection(StorageKind::Table),
        "userProjection" => Builtin::Collection(StorageKind::UserProjection),
        "id" => Builtin::Id,
        "integer" => Builtin::Column(ColType::Integer),
        "real" => Builtin::Column(ColType::Real),
        "text" => Builtin::Column(ColType::Text),
        "boolean" => Builtin::Column(ColType::Boolean),
        "date" => Builtin::Column(ColType::Date),
        "timestamp" => Builtin::Column(ColType::Timestamp),
        "json" => Builtin::Column(ColType::Json),
        "email" => Builtin::Field("email"),
        "url" => Builtin::Field("url"),
        "slug" => Builtin::Field("slug"),
        "richText" => Builtin::Field("richText"),
        "media" => Builtin::Field("media"),
        "file" => Builtin::Field("file"),
        "files" => Builtin::Files,
        "job" => Builtin::Job,
        "route" => Builtin::Route,
        "jobs" => Builtin::Jobs,
        "once" => Builtin::Once,
        "mail" => Builtin::Mail,
        "env" => Builtin::Env,
        "select" => Builtin::Field("select"),
        "group" => Builtin::Field("group"),
        "repeater" => Builtin::Field("repeater"),
        "blocks" => Builtin::Field("blocks"),
        "global" => Builtin::DefineGlobal,
        "defineRelations" => Builtin::DefineRelations,
        "defineConfig" => Builtin::DefineConfig,
        "definePlugin" => Builtin::DefinePlugin,
        "action" => Builtin::Action,
        "db" => Builtin::Db,
        "HttpError" => Builtin::HttpError,
        n if ZERO_TYPES.contains(&n) => return Ok(Value::Undef),
        "fetch" | "crypto" => {
            return err_help(
                span,
                format!("\"zero\" has no export named `{name}`"),
                format!(
                    "`{name}` is the standard global: remove it from this import{}",
                    if name == "crypto" {
                        ", or import { createHash, createHmac, randomBytes } from \"node:crypto\""
                    } else {
                        ""
                    }
                ),
            );
        }
        _ => {
            let mut d = Diag::new(span, format!("\"zero\" has no export named `{name}`"));
            if let Some(s) = suggest(name, ZERO_EXPORTS.iter().copied()) {
                d = d.help(format!("did you mean `{s}`?"));
            }
            return Err(Ctl::Err(d));
        }
    }))
}

fn node_crypto_export(module: crate::builtins::BuiltinModule, source: &str, name: &str, span: Span) -> R<Value> {
    let exports = module.exports();
    if let Some(f) = exports.iter().find(|f| **f == name) {
        return Ok(Value::Builtin(Builtin::NodeCrypto(Some(f))));
    }
    let mut d = Diag::new(span, format!("\"{source}\" has no `{name}` in Zero code"));
    d = match suggest(name, exports.iter().copied()) {
        Some(s) => d.help(format!("did you mean `{s}`?")),
        None => d.help(format!(
            "Zero compiles {} from node:crypto to PHP; other functions, keys, ciphers and streams aren't available",
            exports.join(", ")
        )),
    };
    Err(Ctl::Err(d))
}

/// Names exported by `"php/wordpress"`.
pub const WORDPRESS_EXPORTS: &[&str] = &["abilities"];
/// Type-only exports of `"php/wordpress"`: importable, erased.
const WORDPRESS_TYPES: &[&str] = &[
    "Abilities",
    "AbilityName",
    "AbilityInput",
    "AbilityOutput",
    "Hooks",
    "HookName",
    "Handler",
    "WP_Post",
    "WP_User",
    "WP_Query",
];

fn wordpress_export(name: &str, span: Span) -> R<Value> {
    Ok(Value::Builtin(match name {
        "abilities" => Builtin::Abilities,
        n if crate::wordpress::function(n).is_some() => Builtin::WpFunction(n.into()),
        n if WORDPRESS_TYPES.contains(&n) => return Ok(Value::Undef),
        _ => {
            let mut d = Diag::new(span, format!("\"php/wordpress\" has no export named `{name}`"));
            d = match suggest(
                name,
                WORDPRESS_EXPORTS
                    .iter()
                    .copied()
                    .chain(crate::wordpress::function_names()),
            ) {
                Some(s) => d.help(format!("did you mean `{s}`?")),
                None => d.help(format!(
                    "WordPress functions are admitted one by one when they map soundly to TypeScript; available: {}",
                    crate::wordpress::function_names().collect::<Vec<_>>().join(", ")
                )),
            };
            return Err(Ctl::Err(d));
        }
    }))
}

/// Functions are ready immediately; lexical names are reserved until initialized.
fn hoist(body: &[Stmt], scope: &Rc<Scope>) {
    let module_level = scope.is_module_scope();
    for stmt in body {
        let stmt = match stmt {
            Stmt::Exported(inner) => inner.as_ref(),
            other => other,
        };
        if let Stmt::Decl { kind, pat, .. } = stmt
            && *kind != DeclKind::Var
        {
            predeclare(pat, scope, *kind == DeclKind::Let);
        }
        let Stmt::Func(f) = stmt else { continue };
        let closure = Value::Closure(Rc::new(Closure::new(f.clone(), scope.clone())));
        let name = f.name.clone().expect("declared functions have names");
        scope.vars.borrow_mut().insert(
            name,
            Binding {
                initialized: true,
                value: closure,
                mutable: false,
                module_level,
                ann: None,
            },
        );
    }
}

/// `var` belongs to the nearest function or module, even when its declaration
/// sits in a branch that never executes. Do not traverse nested functions.
fn hoist_vars(body: &[Stmt], scope: &Rc<Scope>) {
    fn binding(pat: &Pat, ann: &Option<TyAnn>, scope: &Rc<Scope>) {
        let mut names = Vec::new();
        pat.bindings(&mut names);
        for (name, _) in names {
            let mut vars = scope.vars.borrow_mut();
            let b = vars.entry(name.to_string()).or_insert_with(|| Binding {
                initialized: true,
                value: Value::Undef,
                mutable: true,
                module_level: scope.is_module_scope(),
                ann: None,
            });
            b.mutable = true;
            if let Some(ann) = ann {
                b.ann = Some((ann.clone(), scope.module));
            }
        }
    }

    for stmt in body {
        match stmt {
            Stmt::Decl {
                kind: DeclKind::Var,
                pat,
                ann,
                ..
            } => binding(pat, ann, scope),
            Stmt::Block(body) | Stmt::While(_, body) | Stmt::DoWhile(body, _) => hoist_vars(body, scope),
            Stmt::Labeled(_, body, _) => hoist_vars(std::slice::from_ref(body), scope),
            Stmt::If(_, yes, no) => {
                hoist_vars(yes, scope);
                if let Some(no) = no {
                    hoist_vars(no, scope);
                }
            }
            Stmt::For { init, body, .. } => {
                if let Some(init) = init {
                    hoist_vars(std::slice::from_ref(init), scope);
                }
                hoist_vars(body, scope);
            }
            Stmt::ForOf { kind, pat, body, .. } => {
                if *kind == DeclKind::Var {
                    binding(pat, &None, scope);
                }
                hoist_vars(body, scope);
            }
            Stmt::ForIn { kind, target, body, .. } => {
                if *kind == Some(DeclKind::Var) {
                    let ExprKind::Ident(name) = &target.kind else {
                        unreachable!("declared for-in identifier")
                    };
                    binding(&Pat::Id(name.clone(), target.span), &None, scope);
                }
                hoist_vars(body, scope);
            }
            Stmt::Try {
                block,
                handler,
                finalizer,
                ..
            } => {
                hoist_vars(block, scope);
                if let Some(handler) = handler {
                    hoist_vars(handler, scope);
                }
                if let Some(finalizer) = finalizer {
                    hoist_vars(finalizer, scope);
                }
            }
            Stmt::Switch { cases, .. } => {
                for (_, body) in cases {
                    hoist_vars(body, scope);
                }
            }
            Stmt::Exported(inner) => hoist_vars(std::slice::from_ref(inner), scope),
            _ => {}
        }
    }
}

fn array_like_length(object: &Obj, span: Span) -> R<usize> {
    let length = object.borrow().get("length").cloned().unwrap_or(Value::Undef);
    require_static_coercion(&length, span)?;
    let length = to_number(&length).max(0.0).floor();
    if length > STEP_BUDGET as f64 {
        return err(span, "the arguments object is too large to evaluate at build time");
    }
    Ok(length as usize)
}

fn arguments_items(object: &Obj, span: Span) -> R<Vec<Value>> {
    let length = array_like_length(object, span)?;
    let object = object.borrow();
    Ok((0..length)
        .map(|index| object.get(&index.to_string()).cloned().unwrap_or(Value::Undef))
        .collect())
}

/// OrdinaryOwnPropertyKeys for string keys: array indexes ascending, then the rest in insertion order.
fn ordered_keys<'a>(keys: impl Iterator<Item = &'a String>) -> Vec<String> {
    let mut indexes = Vec::new();
    let mut names = Vec::new();
    for key in keys {
        if let Ok(index) = key.parse::<u32>()
            && index < u32::MAX
            && index.to_string() == *key
        {
            indexes.push(index);
        } else {
            names.push(key.clone());
        }
    }
    indexes.sort_unstable();
    indexes
        .into_iter()
        .map(|index| index.to_string())
        .chain(names)
        .collect()
}

fn enumerable_keys(value: &Value, span: Span) -> R<Vec<String>> {
    Ok(match value {
        Value::Object(object) | Value::Arguments(object) => ordered_keys(
            object
                .borrow()
                .keys()
                .filter(|key| !(matches!(value, Value::Arguments(_)) && *key == "length")),
        ),
        Value::Closure(c) => ordered_keys(c.props.borrow().keys()),
        Value::Array(array) => (0..array.borrow().len()).map(|index| index.to_string()).collect(),
        Value::Str(value) => (0..value.encode_utf16().count())
            .map(|index| index.to_string())
            .collect(),
        Value::Undef | Value::Null | Value::Bool(_) | Value::Num(_) | Value::Regex(..) => vec![],
        other => return err(span, format!("can't enumerate {}", other.describe())),
    })
}

fn predeclare(pat: &Pat, scope: &Rc<Scope>, mutable: bool) {
    let mut names = Vec::new();
    pat.bindings(&mut names);
    for (name, _) in names {
        declare(scope, name, Value::Undef, mutable);
        scope.vars.borrow_mut().get_mut(name).expect("declared").initialized = false;
    }
}

fn declare(scope: &Rc<Scope>, name: &str, value: Value, mutable: bool) {
    let module_level = scope.is_module_scope();
    scope.vars.borrow_mut().insert(
        name.to_string(),
        Binding {
            initialized: true,
            value,
            mutable,
            module_level,
            ann: None,
        },
    );
}

fn call_global(g: &str, args: &[Value], span: Span) -> R<Value> {
    crate::builtins::check("global", g, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
    for value in args {
        require_static_data(value, span)?;
    }
    if g == "Symbol" {
        let description = match args.first() {
            None | Some(Value::Undef) => None,
            Some(value) => {
                require_static_coercion(value, span)?;
                let Some(text) = value.to_js_string() else {
                    return err(
                        span,
                        format!("can't convert {} to a symbol description", value.describe()),
                    );
                };
                Some(Rc::from(text.as_str()))
            }
        };
        return Ok(Value::Symbol(Rc::new(SymbolVal::Unique(description))));
    }
    if let ("String", Some(Value::Symbol(symbol))) = (g, args.first()) {
        return Ok(Value::str(&format!(
            "Symbol({})",
            symbol.description().unwrap_or_default()
        )));
    }
    if matches!(
        g,
        "String" | "Number" | "parseInt" | "parseFloat" | "isNaN" | "isFinite"
    ) {
        for value in args {
            require_static_coercion(value, span)?;
        }
    }
    let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
    Ok(match g {
        "String" if args.is_empty() => Value::str(""),
        "Number" if args.is_empty() => Value::Num(0.0),
        "String" => Value::str(&arg(0).to_js_string().unwrap_or_else(|| "[object]".into())),
        "Number" => Value::Num(to_number(&arg(0))),
        "Boolean" => Value::Bool(arg(0).truthy()),
        "encodeURI" | "encodeURIComponent" => match arg(0) {
            Value::Str(value) => Value::str(&crate::uri::encode(&value, g == "encodeURIComponent")),
            _ => return err(span, format!("`{g}` needs a string")),
        },
        "parseInt" => {
            let value = crate::num::parse_int(
                &arg(0).to_js_string().unwrap_or_default(),
                crate::num::to_int32(to_number(&arg(1))),
            );
            let Some(value) = value else {
                return err_help(
                    span,
                    crate::builtins::UNSAFE_RADIX,
                    "use base 10, a power-of-two base, or a safe integer result",
                );
            };
            Value::Num(value)
        }
        "parseFloat" => Value::Num(parse_float(&arg(0).to_js_string().unwrap_or_default())),
        "isNaN" => Value::Bool(to_number(&arg(0)).is_nan()),
        "isFinite" => Value::Bool(to_number(&arg(0)).is_finite()),
        "Date" => return err(span, "dates aren't available while the config is evaluated"),
        "console" => return err(span, "`console` isn't available while the config is evaluated"),
        _ => return err(span, format!("`{g}` isn't available in Zero code")),
    })
}

fn column_method(c: &Rc<ColBuilder>, m: &str, args: &[Value], span: Span) -> R<Value> {
    crate::builtins::check("column", m, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
    let mut c = (**c).clone();
    let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
    let closure_arg = || match arg(0) {
        Value::Closure(f) => Ok(f),
        other => err(span, format!("`{m}` needs a function, not {}", other.describe())),
    };
    let now_or_fn = |f: Rc<Closure>, ty: ColType| {
        if returns_new_date(&f.func) && matches!(ty, ColType::Timestamp | ColType::Date) {
            DefaultVal::Now
        } else {
            DefaultVal::Fn(f)
        }
    };
    if let Some(why) = modifier_invalid(&c, m) {
        return err_help(span, why.0, why.1);
    }
    let mod_name: Option<&'static str> = MODIFIERS.iter().copied().find(|x| *x == m);
    if let Some(name) = mod_name {
        c.mods.push((name, span));
    }
    match m {
        "access" => c.access = Some(arg(0)),
        "showIf" => c.show_if = Some(arg(0)),
        "ui" => c.ui = Some(arg(0)),
        "localized" => c.localized = true,
        "notNull" => c.not_null = true,
        "unique" => c.unique = true,
        "min" | "max" => {
            let n = match arg(0) {
                Value::Num(n) if n.is_finite() => n,
                other => return err(span, format!("`.{m}()` needs a number, not {}", other.describe())),
            };
            let counts = !matches!(c.ty, ColType::Integer | ColType::Real);
            if counts && (n < 0.0 || n.fract() != 0.0) {
                return err(
                    span,
                    format!("`.{m}()` here is a length or count, so it needs a whole number ≥ 0"),
                );
            }
            if m == "min" {
                c.min = Some(n);
            } else {
                c.max = Some(n);
            }
        }
        "pattern" => match arg(0) {
            Value::Regex(p, f) => {
                if let Some(bad) = f.chars().find(|x| matches!(x, 'g' | 'y')) {
                    return err_help(
                        span,
                        format!("the `{bad}` flag makes a pattern stateful"),
                        "drop it: `.pattern(/^[a-z]+$/i)`",
                    );
                }
                c.pattern = Some((p.to_string(), f.to_string()));
            }
            other => {
                return err_help(
                    span,
                    format!("`.pattern()` needs a RegExp literal, not {}", other.describe()),
                    "write `.pattern(/^[a-z0-9-]+$/)`",
                );
            }
        },
        "validate" => c.validate = Some(closure_arg()?),
        "primaryKey" => {
            c.primary_key = true;
            c.not_null = true;
            c.auto_increment = c.ty == ColType::Integer;
        }
        "default" => {
            let v = arg(0);
            if matches!(v, Value::Closure(_)) {
                return err(
                    span,
                    "`.default()` takes a value; use `.$default(() => ...)` for a function",
                );
            }
            c.default = Some(DefaultVal::Value(v));
        }
        "defaultNow" => {
            if !matches!(c.ty, ColType::Timestamp | ColType::Date) {
                return err(
                    span,
                    format!(
                        "`.defaultNow()` only works on timestamp() and date(), not {}()",
                        c.ty.name()
                    ),
                );
            }
            c.default = Some(DefaultVal::Now);
        }
        "$default" | "$defaultFn" => c.default = Some(now_or_fn(closure_arg()?, c.ty)),
        "$onUpdate" | "$onUpdateFn" => c.on_update = Some(now_or_fn(closure_arg()?, c.ty)),
        "references" => {
            let f = closure_arg()?;
            let (mut on_delete, mut on_update) = (None, None);
            match arg(1) {
                Value::Undef => {}
                Value::Object(o) => {
                    for (k, v) in o.borrow().iter() {
                        let action = match v {
                            Value::Str(s) => match &**s {
                                "cascade" => RefAction::Cascade,
                                "restrict" => RefAction::Restrict,
                                "set null" => RefAction::SetNull,
                                "no action" => RefAction::NoAction,
                                other => {
                                    return err_help(
                                        span,
                                        format!("unknown reference action \"{other}\""),
                                        "use \"cascade\", \"restrict\", \"set null\", or \"no action\"",
                                    );
                                }
                            },
                            other => {
                                return err(span, format!("reference actions are strings, not {}", other.describe()));
                            }
                        };
                        match k.as_str() {
                            "onDelete" => on_delete = Some(action),
                            "onUpdate" => on_update = Some(action),
                            other => return err(span, format!("references() has no option `{other}`")),
                        }
                    }
                }
                other => {
                    return err(
                        span,
                        format!("references() options must be an object, not {}", other.describe()),
                    );
                }
            }
            c.references = Some((f, on_delete, on_update, span));
        }
        _ => {
            let mut d = Diag::new(span, format!("columns have no method `{m}`"));
            if let Some(s) = suggest(m, MODIFIERS.iter().copied()) {
                d = d.help(format!("did you mean `{s}`?"));
            }
            return Err(Ctl::Err(d));
        }
    }
    Ok(Value::Column(Rc::new(c)))
}

const MODIFIERS: &[&str] = &[
    "notNull",
    "unique",
    "primaryKey",
    "default",
    "defaultNow",
    "$default",
    "$defaultFn",
    "$onUpdate",
    "$onUpdateFn",
    "references",
    "$type",
    "min",
    "max",
    "pattern",
    "validate",
    "access",
    "showIf",
    "ui",
    "localized",
];

/// "a text column", "an email column".
pub fn kind_phrase(ty: ColType) -> String {
    let n = ty.name();
    let article = if n.starts_with(['a', 'e', 'i', 'o', 'u']) {
        "an"
    } else {
        "a"
    };
    format!("{article} {n} column")
}

/// The §1.2 table: which modifiers each column kind accepts. Returns (message, fix).
fn modifier_invalid(c: &ColBuilder, m: &str) -> Option<(String, String)> {
    use ColType as T;
    let ty = c.ty;
    let ok = match m {
        "min" | "max" => !matches!(
            ty,
            T::Boolean | T::Date | T::Timestamp | T::Json | T::Media | T::File | T::Group
        ),
        "pattern" => ty.is_stringy(),
        "unique" => matches!(ty, T::Integer | T::Real | T::Date | T::Timestamp) || ty.is_stringy(),
        "references" => matches!(ty, T::Integer | T::Real),
        "default" | "$default" | "$defaultFn" => ty != T::Media,
        "$type" => ty == T::Json,
        _ => true,
    };
    if ok {
        return None;
    }
    let fix = match (m, ty) {
        ("min" | "max", T::Date | T::Timestamp) => "compare dates in `.validate((v) => ...)`".to_string(),
        ("min" | "max" | "pattern" | "unique", _) | ("references", _) => {
            "use `.validate((value) => ... )` for custom checks".to_string()
        }
        ("default" | "$default" | "$defaultFn", T::Media) => "media columns start empty; set them on write".into(),
        _ => "remove it".into(),
    };
    Some((format!("`.{m}()` isn't valid on {}", kind_phrase(ty)), fix))
}

impl ColBuilder {
    pub fn new(ty: ColType, span: Span) -> Self {
        ColBuilder {
            ty,
            not_null: false,
            primary_key: false,
            auto_increment: false,
            unique: false,
            enum_values: None,
            multiline: false,
            accept: None,
            source: None,
            min: None,
            max: None,
            pattern: None,
            validate: None,
            fields: None,
            layouts: None,
            default: None,
            on_update: None,
            references: None,
            ts_type: None,
            access: None,
            show_if: None,
            ui: None,
            localized: false,
            bucket: None,
            span,
            mods: Vec::new(),
        }
    }
}

/// `() => new Date()` on a timestamp column means "now"; no compiled function needed.
fn returns_new_date(f: &Func) -> bool {
    let body = match &f.body {
        FnBody::Expr(e) => e,
        FnBody::Block(stmts) => match stmts.as_slice() {
            [Stmt::Return(Some(e), _)] => e,
            _ => return false,
        },
    };
    matches!(&body.kind, ExprKind::New { callee, args, .. }
        if args.is_empty() && matches!(&callee.kind, ExprKind::Ident(n) if n == "Date"))
}

pub fn to_number(v: &Value) -> f64 {
    // Arrays convert through their comma-joined string, rather than by
    // recursively coercing the element (Number([false]) is NaN, not zero).
    fn array_number(a: &Arr, active: &mut HashSet<usize>) -> f64 {
        let key = Rc::as_ptr(a) as usize;
        if !active.insert(key) {
            return 0.0; // Array stringification omits a cyclic element.
        }
        let items = a.borrow();
        let number = match items.as_slice() {
            [] | [Value::Null | Value::Undef] => 0.0,
            [Value::Num(n)] => {
                if *n == 0.0 {
                    0.0
                } else {
                    *n
                }
            }
            [value @ Value::Str(_)] => to_number(value),
            [Value::Array(inner)] => array_number(inner, active),
            _ => f64::NAN,
        };
        active.remove(&key);
        number
    }
    match v {
        Value::Num(n) => *n,
        Value::Bool(b) => f64::from(u8::from(*b)),
        Value::Null => 0.0,
        Value::Array(a) => array_number(a, &mut HashSet::new()),
        Value::Str(s) => {
            let t = s.trim_matches(crate::num::is_js_whitespace);
            if t.is_empty() {
                0.0
            } else if let Some((radix, digits)) =
                [("0x", "0X", 16), ("0o", "0O", 8), ("0b", "0B", 2)]
                    .iter()
                    .find_map(|(lower, upper, radix)| {
                        t.strip_prefix(lower)
                            .or_else(|| t.strip_prefix(upper))
                            .map(|digits| (*radix, digits))
                    })
            {
                if digits.is_empty() || !digits.chars().all(|c| c.is_digit(radix)) {
                    f64::NAN
                } else {
                    crate::num::radix_number(
                        &digits.chars().map(|c| c.to_digit(radix).unwrap()).collect::<Vec<_>>(),
                        radix,
                    )
                }
            } else if t == "Infinity" || t == "+Infinity" {
                f64::INFINITY
            } else if t == "-Infinity" {
                f64::NEG_INFINITY
            } else if t.chars().all(|c| c.is_ascii_digit() || "+-.eE".contains(c)) {
                t.parse().unwrap_or(f64::NAN)
            } else {
                f64::NAN
            }
        }
        _ => f64::NAN,
    }
}

fn parse_float(s: &str) -> f64 {
    let t = s.trim_start_matches(crate::num::is_js_whitespace);
    if t.starts_with("Infinity") || t.starts_with("+Infinity") {
        return f64::INFINITY;
    }
    if t.starts_with("-Infinity") {
        return f64::NEG_INFINITY;
    }
    let mut candidate: String = t
        .chars()
        .take_while(|c| c.is_ascii_digit() || "+-.eE".contains(*c))
        .collect();
    while !candidate.is_empty() {
        if let Ok(n) = candidate.parse::<f64>() {
            return n;
        }
        candidate.pop();
    }
    f64::NAN
}

fn slice_bounds(len: f64, start: &Value, end: &Value) -> (usize, usize) {
    let clamp = |v: &Value, default: f64| {
        let n = match v {
            Value::Undef => default,
            v => to_number(v).trunc(),
        };
        let n = if n.is_nan() { 0.0 } else { n };
        (if n < 0.0 { (len + n).max(0.0) } else { n.min(len) }) as usize
    };
    (clamp(start, 0.0), clamp(end, len))
}

/// Own string names of Object.prototype and Array.prototype, as Node reports them.
const OBJECT_PROTOTYPE: &[&str] = &[
    "constructor",
    "__defineGetter__",
    "__defineSetter__",
    "hasOwnProperty",
    "__lookupGetter__",
    "__lookupSetter__",
    "isPrototypeOf",
    "propertyIsEnumerable",
    "toString",
    "valueOf",
    "__proto__",
    "toLocaleString",
];
const ARRAY_PROTOTYPE: &[&str] = &[
    "length",
    "constructor",
    "at",
    "concat",
    "copyWithin",
    "fill",
    "find",
    "findIndex",
    "findLast",
    "findLastIndex",
    "lastIndexOf",
    "pop",
    "push",
    "reverse",
    "shift",
    "unshift",
    "slice",
    "sort",
    "splice",
    "includes",
    "indexOf",
    "join",
    "keys",
    "entries",
    "values",
    "forEach",
    "filter",
    "flat",
    "flatMap",
    "map",
    "every",
    "some",
    "reduce",
    "reduceRight",
    "toReversed",
    "toSorted",
    "toSpliced",
    "with",
    "toLocaleString",
    "toString",
];

/// `key in object` during configuration evaluation, for the plain data values the evaluator
/// models. Runtime `Js::hasProperty` covers every runtime value.
fn build_time_in(key: &str, object: &Value, span: Span) -> R<bool> {
    let index_below = |length: usize| key.parse::<usize>().is_ok_and(|i| i.to_string() == key && i < length);
    Ok(match object {
        Value::Object(o) => o.borrow().contains_key(key) || OBJECT_PROTOTYPE.contains(&key),
        Value::Arguments(o) => {
            o.borrow().contains_key(key) || matches!(key, "length" | "callee") || OBJECT_PROTOTYPE.contains(&key)
        }
        Value::Array(a) => {
            index_below(a.borrow().len()) || ARRAY_PROTOTYPE.contains(&key) || OBJECT_PROTOTYPE.contains(&key)
        }
        Value::Undef | Value::Null | Value::Bool(_) | Value::Num(_) | Value::Str(_) => {
            return err_help(
                span,
                "`in` needs an object on its right side; JavaScript throws a TypeError here",
                "check `typeof value === \"object\" && value !== null` first",
            );
        }
        _ => {
            return err_help(
                span,
                "`in` at build time inspects only plain objects, arrays and arguments",
                "move the check into a handler",
            );
        }
    })
}

fn build_regex(pattern: &str, flags: &str, span: Span) -> R<regex::Regex> {
    let mut prefix = String::new();
    for f in flags.chars() {
        match f {
            'i' | 'm' | 's' => prefix.push(f),
            'g' | 'u' => {}
            other => return err(span, format!("regex flag `{other}` isn't supported at build time")),
        }
    }
    let full = if prefix.is_empty() {
        pattern.to_string()
    } else {
        format!("(?{prefix}){pattern}")
    };
    regex::Regex::new(&full).map_err(|_| {
        Ctl::Err(Diag::new(
            span,
            "this regular expression can't be evaluated at build time",
        ))
    })
}

fn utf16_value(units: &[u16], span: Span) -> R<Value> {
    String::from_utf16(units).map(|s| Value::str(&s)).map_err(|_| {
        Ctl::Err(
            Diag::new(
                span,
                "this string result splits a character in two (a lone UTF-16 surrogate)",
            )
            .help("slice at character boundaries; strings cannot represent a lone surrogate"),
        )
    })
}

/// ECMAScript GetSubstitution. Captures are 1-based; $0 stays literal.
fn get_substitution(
    template: &str,
    input: &str,
    start: usize,
    end: usize,
    groups: &[Option<&str>],
    names: Option<&IndexMap<&str, Option<&str>>>,
) -> String {
    let mut out = String::new();
    let mut chars = template.chars().peekable();
    while let Some(c) = chars.next() {
        if c != '$' {
            out.push(c);
            continue;
        }
        match chars.peek().copied() {
            Some('$') => {
                chars.next();
                out.push('$');
            }
            Some('&') => {
                chars.next();
                out.push_str(&input[start..end]);
            }
            Some('`') => {
                chars.next();
                out.push_str(&input[..start]);
            }
            Some('\'') => {
                chars.next();
                out.push_str(&input[end..]);
            }
            Some('0'..='9') => {
                let first = chars.next().unwrap();
                let one = first.to_digit(10).unwrap() as usize;
                let two = chars.peek().and_then(|c| c.to_digit(10)).map(|n| one * 10 + n as usize);
                if let Some(n) = two.filter(|n| *n > 0 && *n <= groups.len()) {
                    chars.next();
                    out.push_str(groups[n - 1].unwrap_or(""));
                } else if one > 0 && one <= groups.len() {
                    out.push_str(groups[one - 1].unwrap_or(""));
                } else {
                    out.push('$');
                    out.push(first);
                }
            }
            Some('<') if names.is_some() => {
                let rest: String = chars.clone().collect();
                if let Some(close) = rest.find('>') {
                    let name = &rest[1..close];
                    out.push_str(names.unwrap().get(name).copied().flatten().unwrap_or(""));
                    for _ in 0..rest[..=close].chars().count() {
                        chars.next();
                    }
                } else {
                    out.push('$');
                }
            }
            _ => out.push('$'),
        }
    }
    out
}

fn string_method(s: &str, m: &str, args: &[Value], span: Span) -> R<Value> {
    crate::builtins::check("string", m, args.len(), crate::builtins::Phase::Evaluator, span).map_err(Ctl::Err)?;
    let arg = |i: usize| args.get(i).cloned().unwrap_or(Value::Undef);
    let str_arg = |i: usize| arg(i).to_js_string().unwrap_or_default();
    if matches!(m, "slice" | "split" | "repeat" | "includes" | "startsWith" | "endsWith") {
        for value in args {
            require_static_coercion(value, span)?;
        }
    }
    if matches!(m, "includes" | "startsWith" | "endsWith") && matches!(arg(0), Value::Regex(..)) {
        return err(span, "string search needs a string, not a regular expression");
    }
    Ok(match m {
        "toLowerCase" => Value::str(&s.to_lowercase()),
        "toUpperCase" => Value::str(&s.to_uppercase()),
        "trim" => Value::str(s.trim_matches(crate::num::is_js_whitespace)),
        "trimStart" => Value::str(s.trim_start_matches(crate::num::is_js_whitespace)),
        "trimEnd" => Value::str(s.trim_end_matches(crate::num::is_js_whitespace)),
        "startsWith" => Value::Bool(s.starts_with(&str_arg(0))),
        "endsWith" => Value::Bool(s.ends_with(&str_arg(0))),
        "includes" => Value::Bool(s.contains(&str_arg(0))),
        "slice" => {
            let units: Vec<u16> = s.encode_utf16().collect();
            let (a, b) = slice_bounds(units.len() as f64, &arg(0), &arg(1));
            utf16_value(&units[a..b.max(a)], span)?
        }
        "split" => {
            let limit = match arg(1) {
                Value::Undef => u32::MAX as usize,
                v => crate::num::to_uint32(to_number(&v)) as usize,
            };
            match arg(0) {
                Value::Str(sep) if sep.is_empty() => Value::array(
                    s.encode_utf16()
                        .take(limit)
                        .map(|u| utf16_value(&[u], span))
                        .collect::<R<Vec<_>>>()?,
                ),
                Value::Str(sep) => Value::array(s.split(&*sep).take(limit).map(Value::str).collect()),
                other => {
                    return err(
                        span,
                        format!(
                            "split() at build time needs a string separator, not {}",
                            other.describe()
                        ),
                    );
                }
            }
        }
        "repeat" => {
            let count = to_number(&arg(0));
            let count = if count.is_nan() { 0.0 } else { count.trunc() };
            if !(0.0..=536870888.0).contains(&count) || count * s.len() as f64 > 536870888.0 {
                return err(span, "repeat() count or result length is out of range");
            }
            Value::str(&s.repeat(count as usize))
        }
        "replace" | "replaceAll" => {
            let Value::Str(rep) = arg(1) else {
                return err(span, format!("{m}() at build time needs a string replacement"));
            };
            match arg(0) {
                Value::Str(p) => {
                    let mut out = String::new();
                    let mut last = 0;
                    // Empty string patterns match at every UTF-16 boundary. Inserting inside
                    // an astral pair cannot be represented; refuse that case explicitly.
                    if p.is_empty() && m == "replaceAll" && s.chars().any(|c| c.len_utf16() == 2) {
                        return err(span, "empty replaceAll on astral text isn't supported at build time");
                    }
                    for (start, matched) in s.match_indices(&*p) {
                        let end = start + matched.len();
                        out.push_str(&s[last..start]);
                        out.push_str(&get_substitution(&rep, s, start, end, &[], None));
                        last = end;
                        if m == "replace" {
                            break;
                        }
                    }
                    out.push_str(&s[last..]);
                    Value::str(&out)
                }
                Value::Regex(p, f) => {
                    if m == "replaceAll" && !f.contains('g') {
                        return err(span, "replaceAll() needs a global regular expression");
                    }
                    if !s.is_ascii()
                        || !p.is_ascii()
                        || p.contains('\\')
                        || f.contains('m')
                        || s.contains(['\r', '\n'])
                        || ["&&", "~~", "||", "--"].iter().any(|token| p.contains(token))
                    {
                        return err_help(
                            span,
                            "build-time regex replacement supports ASCII text and patterns without escapes, set operations, multiline mode or line terminators",
                            "move this replacement into a handler",
                        );
                    }
                    let mut in_class = false;
                    for c in p.chars() {
                        if c == '[' {
                            if in_class {
                                return err_help(
                                    span,
                                    "nested regex character classes aren't supported at build time",
                                    "move this replacement into a handler",
                                );
                            }
                            in_class = true;
                        } else if c == ']' {
                            if !in_class {
                                return err_help(
                                    span,
                                    "unmatched regex class delimiters aren't supported at build time",
                                    "move this replacement into a handler",
                                );
                            }
                            in_class = false;
                        }
                    }
                    let re = build_regex(&p, &f, span)?;
                    let mut out = String::new();
                    let mut last = 0;
                    let mut offset = 0;
                    while offset <= s.len() {
                        let Some(caps) = re.captures_at(s, offset) else {
                            break;
                        };
                        let matched = caps.get(0).expect("complete match");
                        let (start, end) = (matched.start(), matched.end());
                        let groups: Vec<_> = caps.iter().skip(1).map(|m| m.map(|m| m.as_str())).collect();
                        let names: IndexMap<_, _> = re
                            .capture_names()
                            .flatten()
                            .map(|name| (name, caps.name(name).map(|m| m.as_str())))
                            .collect();
                        out.push_str(&s[last..start]);
                        out.push_str(&get_substitution(
                            &rep,
                            s,
                            start,
                            end,
                            &groups,
                            if names.is_empty() { None } else { Some(&names) },
                        ));
                        last = end;
                        if !f.contains('g') {
                            break;
                        }
                        offset = if end > start { end } else { end + 1 };
                    }
                    out.push_str(&s[last..]);
                    Value::str(&out)
                }
                other => return err(span, format!("can't replace {}", other.describe())),
            }
        }
        "toString" => Value::str(s),
        _ => return err(span, format!("strings have no method `{m}` at build time")),
    })
}

fn loose_eq(a: &Value, b: &Value, span: Span) -> R<bool> {
    fn primitive(value: &Value) -> bool {
        matches!(
            value,
            Value::Undef | Value::Null | Value::Bool(_) | Value::Num(_) | Value::Str(_)
        )
    }

    Ok(match (a, b) {
        (Value::Undef | Value::Null, Value::Undef | Value::Null) => true,
        (Value::Undef | Value::Null, _) | (_, Value::Undef | Value::Null) => false,
        (Value::Num(left), Value::Str(_)) => *left == to_number(b),
        (Value::Str(_), Value::Num(right)) => to_number(a) == *right,
        (Value::Bool(value), _) => loose_eq(&Value::Num(if *value { 1.0 } else { 0.0 }), b, span)?,
        (_, Value::Bool(value)) => loose_eq(a, &Value::Num(if *value { 1.0 } else { 0.0 }), span)?,
        (left, right) if !primitive(left) && primitive(right) => {
            let Some(value) = left.to_js_string() else {
                return err(span, format!("can't coerce {} in a loose comparison", left.describe()));
            };
            loose_eq(&Value::str(&value), right, span)?
        }
        (left, right) if primitive(left) && !primitive(right) => loose_eq(right, left, span)?,
        _ => a.strict_eq(b),
    })
}

fn binary(op: BinOp, a: &Value, b: &Value, span: Span) -> R<Value> {
    require_static_data(a, span)?;
    require_static_data(b, span)?;
    if !matches!(op, BinOp::Eq | BinOp::Ne) {
        require_static_coercion(a, span)?;
        require_static_coercion(b, span)?;
    }
    let prim = |v: &Value| -> R<Value> {
        match v {
            Value::Array(_) | Value::Object(_) | Value::Arguments(_) | Value::Regex(..) => {
                Ok(Value::str(&v.to_js_string().unwrap_or_default()))
            }
            Value::Undef | Value::Null | Value::Bool(_) | Value::Num(_) | Value::Str(_) => Ok(v.clone()),
            other => err(span, format!("can't use {} in an expression", other.describe())),
        }
    };
    Ok(match op {
        BinOp::Add => {
            let (a, b) = (prim(a)?, prim(b)?);
            if matches!(a, Value::Str(_)) || matches!(b, Value::Str(_)) {
                Value::str(&format!(
                    "{}{}",
                    a.to_js_string().unwrap_or_default(),
                    b.to_js_string().unwrap_or_default()
                ))
            } else {
                Value::Num(to_number(&a) + to_number(&b))
            }
        }
        BinOp::Sub => Value::Num(to_number(a) - to_number(b)),
        BinOp::Mul => Value::Num(to_number(a) * to_number(b)),
        BinOp::Div => Value::Num(to_number(a) / to_number(b)),
        BinOp::Rem => Value::Num(to_number(a) % to_number(b)),
        BinOp::Exp => Value::Num(supported_power(to_number(a), to_number(b), span)?),
        BinOp::Eq => Value::Bool(a.strict_eq(b)),
        BinOp::Ne => Value::Bool(!a.strict_eq(b)),
        BinOp::LooseEq => Value::Bool(loose_eq(a, b, span)?),
        BinOp::LooseNe => Value::Bool(!loose_eq(a, b, span)?),
        BinOp::BitAnd | BinOp::BitOr | BinOp::BitXor | BinOp::Shl | BinOp::Shr | BinOp::UShr => {
            let (a, b) = (to_number(&prim(a)?), to_number(&prim(b)?));
            Value::Num(crate::num::bitwise(op, a, b))
        }
        BinOp::Lt | BinOp::Le | BinOp::Gt | BinOp::Ge => {
            let (a, b) = (prim(a)?, prim(b)?);
            let ord = match (&a, &b) {
                (Value::Str(x), Value::Str(y)) => Some(x.encode_utf16().cmp(y.encode_utf16())),
                _ => to_number(&a).partial_cmp(&to_number(&b)),
            };
            Value::Bool(ord.is_some_and(|o| match op {
                BinOp::Lt => o.is_lt(),
                BinOp::Le => o.is_le(),
                BinOp::Gt => o.is_gt(),
                _ => o.is_ge(),
            }))
        }
    })
}

/// Plain data → JSON. `None` for functions and other non-data values.
pub fn to_json(v: &Value) -> Option<serde_json::Value> {
    use serde_json::Value as J;
    Some(match v {
        Value::Null | Value::Undef => J::Null,
        Value::Bool(b) => J::Bool(*b),
        Value::Num(n) => {
            if crate::num::is_safe_int(*n) {
                J::from(*n as i64)
            } else if n.is_finite() {
                serde_json::Number::from_f64(*n).map(J::Number)?
            } else {
                J::Null
            }
        }
        Value::Str(s) => J::String(s.to_string()),
        Value::Array(a) => J::Array(a.borrow().iter().map(|x| to_json(x).unwrap_or(J::Null)).collect()),
        Value::Object(o) => {
            let mut map = serde_json::Map::new();
            for (k, x) in o.borrow().iter() {
                if !matches!(x, Value::Undef) {
                    map.insert(k.clone(), to_json(x)?);
                }
            }
            J::Object(map)
        }
        Value::Arguments(object) => {
            let mut map = serde_json::Map::new();
            for key in enumerable_keys(v, Span::NONE).ok()? {
                if let Some(value) = object.borrow().get(&key)
                    && !matches!(value, Value::Undef)
                    && let Some(value) = to_json(value)
                {
                    map.insert(key, value);
                }
            }
            J::Object(map)
        }
        _ => return None,
    })
}

#[cfg(test)]
mod tests {
    #[test]
    fn object_enumeration_reads_string_elements() {
        let tmp = tempfile::tempdir().unwrap();
        let entry = tmp.path().join("zero.config.ts");
        std::fs::write(&entry, "export default [Object.values('aé'), Object.entries('aé')];").unwrap();
        let program = crate::loader::load(&entry).unwrap();
        let result = super::Evaluator::new(&program).run().unwrap();
        assert_eq!(
            super::to_json(&result).unwrap(),
            serde_json::json!([["a", "é"], [["0", "a"], ["1", "é"]]])
        );
        std::fs::write(&entry, "export default Object.values('🙂');").unwrap();
        let program = crate::loader::load(&entry).unwrap();
        let error = super::Evaluator::new(&program).run().unwrap_err();
        assert!(error.message.contains("lone UTF-16 surrogate"), "{error:?}");
    }

    #[test]
    fn global_builtins_work_as_array_callbacks() {
        let tmp = tempfile::tempdir().unwrap();
        let entry = tmp.path().join("zero.config.ts");
        std::fs::write(
            &entry,
            r#"export default [[1, 0, 2, "", null].filter(Boolean), Array.from(["1", "2.5"], Number), ["10", "10", "10"].map(parseInt), ["a", ""].map(String), [0, 1].every(Boolean), ["1", "x"].map(Number)];"#,
        )
        .unwrap();
        let program = crate::loader::load(&entry).unwrap();
        let result = super::Evaluator::new(&program).run().unwrap();
        // Node prints the same for these expressions; parseInt keeps the index as its radix.
        assert_eq!(
            super::to_json(&result).unwrap(),
            serde_json::json!([[1, 2], [1, 2.5], [10, null, 2], ["a", ""], false, [1, null]])
        );
    }

    #[test]
    fn missing_default_export_points_to_the_current_file_after_repeated_loads() {
        let tmp = tempfile::tempdir().unwrap();
        for name in ["first.ts", "second.ts"] {
            let entry = tmp.path().join(name);
            std::fs::write(&entry, "export const value = 1;").unwrap();
            let program = crate::loader::load(&entry).unwrap();
            let error = super::Evaluator::new(&program).run().unwrap_err();
            let rendered = crate::diag::render(&error, tmp.path());
            assert!(rendered.contains(&format!("--> {name}:1:1")), "{rendered}");
        }
    }
}
