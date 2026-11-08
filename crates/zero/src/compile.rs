//! Compiles hooks, actions, and the helpers they call into PHP.
//!
//! Each function is type-checked against what the config declared (the schema
//! gives hooks their row types) and lowered to native PHP wherever the types
//! make it safe. Where JavaScript and PHP genuinely differ (truthiness, `+` on
//! unknown operands, string indexing, sort order) the output calls a `Js::`
//! helper. Where neither is sound, the build fails with a location and a fix.

mod annotations;
mod classes;
mod effects;
mod host;
mod javascript;

use crate::diag::{Diag, Result, Span, suggest};
use crate::eval::{Evaluator, GLOBALS};
use crate::extract::{Extracted, Root, Slot};
use crate::ir::*;
use crate::num::php_number;
use crate::schema::{ColType, Column, HookKind, Owner, RelKind, Schema};
use crate::types::{self, Builder, BuilderKind, JSON_TYPES, Ty};
use crate::value::*;
use indexmap::IndexMap;
use std::collections::{HashMap, HashSet};
use std::fmt::Write as _;
use std::rc::Rc;

pub fn php_str(s: &str) -> String {
    // Generated bodies are indented after compilation; physical line breaks in
    // quoted literals would acquire that indentation as part of their value.
    if s.contains(['\n', '\r']) {
        let mut out = String::with_capacity(s.len() + 2);
        out.push('"');
        for c in s.chars() {
            match c {
                '\n' => out.push_str("\\n"),
                '\r' => out.push_str("\\r"),
                '\\' => out.push_str("\\\\"),
                '"' => out.push_str("\\\""),
                '$' => out.push_str("\\$"),
                c => out.push(c),
            }
        }
        out.push('"');
        return out;
    }
    let mut out = String::with_capacity(s.len() + 2);
    out.push('\'');
    for c in s.chars() {
        match c {
            '\'' => out.push_str("\\'"),
            '\\' => out.push_str("\\\\"),
            c => out.push(c),
        }
    }
    out.push('\'');
    out
}

/// PHP literal for a JSON value (used for constants captured by hooks).
pub fn php_json(v: &serde_json::Value) -> String {
    use serde_json::Value as J;
    match v {
        J::Null => "null".into(),
        J::Bool(b) => b.to_string(),
        J::Number(n) => match n.as_i64() {
            Some(i) => i.to_string(),
            None => php_number(n.as_f64().unwrap_or(f64::NAN)),
        },
        J::String(s) => php_str(s),
        J::Array(items) => format!("[{}]", items.iter().map(php_json).collect::<Vec<_>>().join(", ")),
        J::Object(map) => format!(
            "[{}]",
            map.iter()
                .map(|(k, v)| format!("{} => {}", php_str(k), php_json(v)))
                .collect::<Vec<_>>()
                .join(", ")
        ),
    }
}

fn err<T>(span: Span, msg: impl Into<String>) -> Result<T> {
    Err(Diag::new(span, msg))
}

fn err_help<T>(span: Span, msg: impl Into<String>, help: impl Into<String>) -> Result<T> {
    Err(Diag::new(span, msg).help(help))
}

fn is_env_name(s: &str) -> bool {
    let mut chars = s.chars();
    chars.next().is_some_and(|c| c == '_' || c.is_ascii_alphabetic())
        && chars.all(|c| c == '_' || c.is_ascii_alphanumeric())
}

fn validate_captured_data(v: &Value, span: Span, seen: &mut HashSet<usize>) -> Result<()> {
    match v {
        Value::Array(a) => {
            if !seen.insert(Rc::as_ptr(a) as usize) {
                return err_help(
                    span,
                    "captured data cannot contain cycles",
                    "capture plain data without circular references",
                );
            }
            for value in a.borrow().iter() {
                validate_captured_data(value, span, seen)?;
            }
            seen.remove(&(Rc::as_ptr(a) as usize));
            Ok(())
        }
        Value::Object(o) => {
            if !seen.insert(Rc::as_ptr(o) as usize) {
                return err_help(
                    span,
                    "captured data cannot contain cycles",
                    "capture plain data without circular references",
                );
            }
            for value in o.borrow().values() {
                validate_captured_data(value, span, seen)?;
            }
            seen.remove(&(Rc::as_ptr(o) as usize));
            Ok(())
        }
        Value::Env(_) => err_help(
            span,
            "env() values can't be stored inside captured data",
            "read it where it's used: `env(\"NAME\")` inside the hook, action or job",
        ),
        Value::Null | Value::Undef | Value::Bool(_) | Value::Num(_) | Value::Str(_) => Ok(()),
        _ => err_help(
            span,
            format!("captured data contains {}, which can't be used in PHP", v.describe()),
            "capture plain data, or call and read the members of an object that holds functions directly (`ns.fn(x)`)",
        ),
    }
}

/// A build-time object or function that has no PHP form, so compiled code may only follow
/// member chains through it: an object holding code (functions, classes, regexes, env tokens)
/// or a function with own properties. Plain data keeps its runtime singleton.
fn is_static_namespace(v: &Value) -> bool {
    fn holds_env(v: &Value, seen: &mut HashSet<usize>) -> bool {
        match v {
            Value::Env(_) => true,
            Value::Array(a) if seen.insert(Rc::as_ptr(a) as usize) => a.borrow().iter().any(|v| holds_env(v, seen)),
            Value::Object(o) if seen.insert(Rc::as_ptr(o) as usize) => o.borrow().values().any(|v| holds_env(v, seen)),
            _ => false,
        }
    }
    match v {
        // env() tokens keep their own diagnostic: read them where they're used.
        Value::Object(_) => {
            validate_captured_data(v, Span::NONE, &mut HashSet::new()).is_err() && !holds_env(v, &mut HashSet::new())
        }
        Value::Closure(c) => c.has_props(),
        _ => false,
    }
}

/// The first member of a static namespace that has no PHP data form, as `name.key`.
fn namespace_example(name: &str, v: &Value) -> String {
    let Value::Object(o) = v else {
        return format!("{name}.member");
    };
    let o = o.borrow();
    let key = o
        .iter()
        .find(|(_, v)| matches!(v, Value::Closure(_)))
        .or_else(|| o.iter().next())
        .map_or("member", |(k, _)| k.as_str());
    match o.get(key) {
        Some(Value::Closure(_)) => format!("{name}.{key}(...)"),
        _ => format!("{name}.{key}"),
    }
}

/// A compiled expression: PHP source, its type, and whether it's a plain variable/property path.
#[derive(Clone, Debug)]
struct Out {
    php: String,
    ty: Ty,
    /// Assignable PHP place (`$x`, `$x['k']`) rooted at a local.
    place: Option<String>,
    /// Precedence: true when `php` is atomic (no parens needed).
    atomic: bool,
    /// Set when the value is a compiled helper: call it by name.
    helper: Option<String>,
}

/// Writes keep their authored constraint even when flow changes the type read
/// from the same location. Dereferences and read-modify-write results use read.
struct AssignmentPlace {
    read: Out,
    constraint: Ty,
    /// A captured handle and key keep the reference stable while the right side runs.
    target: Option<WriteTarget>,
}

struct WriteTarget {
    receiver: String,
    key: String,
    kind: WriteKind,
}

enum WriteKind {
    Object,
    NativeObject,
    Array,
    Dynamic,
    /// A `#name` slot owned by the PHP class descriptor expression.
    Private(String),
}

enum CapturedPlace {
    Binding(String),
    Object {
        receiver: String,
        key: String,
    },
    NativeObject {
        receiver: String,
        key: String,
    },
    Array {
        receiver: String,
        key: String,
    },
    Dynamic {
        receiver: String,
        key: String,
    },
    Private {
        class: String,
        receiver: String,
        key: String,
    },
}

impl Out {
    fn new(php: impl Into<String>, ty: Ty) -> Out {
        Out {
            php: php.into(),
            ty,
            place: None,
            atomic: false,
            helper: None,
        }
    }

    fn atom(php: impl Into<String>, ty: Ty) -> Out {
        Out {
            php: php.into(),
            ty,
            place: None,
            atomic: true,
            helper: None,
        }
    }

    fn checked_str(self, arg: &Out, span: Span) -> Result<Out> {
        if arg.ty.is_str() {
            Ok(self)
        } else {
            err(span, format!("this needs a string, not {}", arg.ty.describe()))
        }
    }

    fn wrapped(&self) -> String {
        if self.atomic {
            self.php.clone()
        } else {
            format!("({})", self.php)
        }
    }
}

#[derive(Clone)]
struct Local {
    php: String,
    ty: Ty,
    constraint: Ty,
    mutable: bool,
    binding: Span,
    view: Vec<effects::Step>,
    /// A PHP JsObject parameter whose binding is never replaced, including by captures.
    native_object: bool,
    initialized: bool,
}

/// Binding reads may be widened by runtime effects independently of what the
/// authored declaration permits assigning.
struct BindingTypes {
    read: Ty,
    constraint: Ty,
}

/// What an identifier in compiled code refers to, resolved through the closure's captured scope.
enum Resolved {
    Local(Local),
    Value(Value, Binding),
    Global(&'static str),
}

struct FnCtx {
    function: Span,
    scopes: Vec<HashMap<String, Local>>,
    is_async: bool,
    /// `function*`: `yield` is legal and the body compiles to a PHP Generator.
    is_generator: bool,
    ret: Option<Ty>,
    /// Inferred return types (when no annotation).
    returns: Vec<Ty>,
    /// Expressions proven not to complete by their compiled type.
    diverging: HashSet<Span>,
    /// Switch statements whose cases cover the compiled discriminant type.
    exhaustive_switches: HashSet<Span>,
    loop_bindings: HashSet<Span>,
    /// The closure whose captured environment resolves free names.
    env: Rc<Scope>,
    module: usize,
    /// PHP names reserved by locals, temporaries and enclosing capture candidates.
    php_names: HashSet<String>,
    /// Inside a nested closure: names from the enclosing function it uses.
    captures: Option<Vec<(String, bool)>>,
    outer: Option<Box<FnCtx>>,
    tmp: usize,
    /// Enclosing loops, switches and labeled blocks, innermost last. PHP's `break N` and
    /// `continue N` count each of them as one level.
    jumps: Vec<JumpTarget>,
    /// Labels for the next statement compiled, when it is a loop or switch.
    pending_labels: Vec<String>,
    conditional_depth: usize,
    /// Access rules can't query (§1.6): `db` is rejected inside them and their closures.
    no_db: bool,
    /// Set while compiling an access rule's own body: (collection table, op). Its returns are
    /// `true`/`false` or a filter literal checked against that collection.
    access: Option<(Option<String>, String)>,
}

pub struct Compiler<'a, 'p> {
    classes: HashMap<usize, classes::CompiledClass>,
    /// Class ids by (owning plugin, class value).
    class_ids: HashMap<(usize, usize), usize>,
    pub schema: &'a Schema,
    ev: &'a mut Evaluator<'p>,
    collections: &'a [Rc<CollectionVal>],
    type_aliases: HashMap<(usize, String), (Vec<TypeParam>, TyAnn)>,
    duplicate_type_aliases: HashSet<(usize, String)>,
    annotation_depth: usize,
    annotation_steps: usize,
    resolving_json_types: HashSet<usize>,
    /// Compiled module-level helpers, separated by whether database access is forbidden.
    helpers: HashMap<(usize, usize, bool), String>,
    helper_values: HashMap<(usize, usize), String>,
    helper_closures: HashMap<usize, Rc<Closure>>,
    helper_types: HashMap<usize, Ty>,
    /// Emitted helpers for captured values: (plugin owner, kind, allocation). Kinds in use:
    /// 0 array, 1 object, 2 regex, 3 Map/Set (`Value::Coll`), 5 unregistered symbol, 6
    /// WeakMap/WeakSet. feat/binary-data claims 4 (Value::Native); a new value kind takes the
    /// next unused number so addresses of different kinds never collide.
    data_helpers: HashMap<(usize, u8, usize), String>,
    effects: effects::Effects,
    used_names: HashSet<String>,
    pending: Vec<(Rc<Closure>, String, Option<Vec<Ty>>)>,
    pub output: String,
    fx: Option<FnCtx>,
    /// Inferred result type of each action handler (for the typed client).
    pub action_results: HashMap<String, Ty>,
    /// Set for the one callback `once()` runs in place, which may be async (lane D).
    async_callback: bool,
    /// A named function expression passed as a callback: its inner declaration's span and the
    /// callback's parameter types.
    named_callback: Option<(Span, Vec<Ty>)>,
    /// Generated code queues promise jobs, so each root drains them and checks rejections at its end.
    uses_jobs: bool,
    /// Every ability `abilities.call` may name, typed (src/abilities.rs).
    pub abilities: crate::wordpress::abilities::Catalog,
    /// What the compiled code uses from WordPress, in source order (see [`Compiler::use_wordpress`]).
    pub wordpress_uses: Vec<WordPressUse>,
    /// The plugin whose root is compiling, and its name; `None` for the app's own code.
    plugin: Option<(usize, String)>,
    /// The PHP function compiling right now, for the read→send report.
    current: String,
    /// Every capability-bearing call in a plugin's compiled code.
    pub uses: Vec<crate::plugins::Use>,
    /// Which compiled functions each function calls, for the read→send report.
    pub calls: HashMap<String, HashSet<String>>,
    plugin_names: Vec<String>,
    /// Each plugin's PHP function answering its locked fetch hosts, written once the lock settles.
    pub host_functions: Vec<String>,
}

/// What kind of WordPress feature a module uses: the capability kinds a plugin gate counts.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub enum WordPressKind {
    /// `abilities.call("vendor/name", ...)`.
    Ability,
    /// A `wp-hooks/<hook>` handler.
    Hook,
    /// A WordPress function called from TypeScript.
    Function,
}

/// One use of a WordPress feature, located at the source that makes it.
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
pub struct WordPressUse {
    pub kind: WordPressKind,
    pub name: String,
    /// The project-relative or absolute path of the module that makes the use.
    pub module: String,
    #[serde(skip)]
    pub span: Span,
}

fn closure_key(c: &Rc<Closure>) -> usize {
    Rc::as_ptr(c) as usize
}

impl<'a, 'p> Compiler<'a, 'p> {
    pub fn new(
        schema: &'a Schema,
        ev: &'a mut Evaluator<'p>,
        ext: &'a Extracted,
        program: &crate::loader::Program,
    ) -> Self {
        let mut type_aliases = HashMap::new();
        let mut duplicate_type_aliases = HashSet::new();
        for m in &program.modules {
            collect_aliases(&m.body, m.id, &mut type_aliases, &mut duplicate_type_aliases);
        }
        Compiler {
            classes: HashMap::new(),
            class_ids: HashMap::new(),
            schema,
            ev,
            collections: &ext.collections,
            type_aliases,
            duplicate_type_aliases,
            annotation_depth: 0,
            annotation_steps: 0,
            resolving_json_types: HashSet::new(),
            async_callback: false,
            named_callback: None,
            uses_jobs: false,
            helpers: HashMap::new(),
            helper_values: HashMap::new(),
            helper_closures: HashMap::new(),
            helper_types: HashMap::new(),
            data_helpers: HashMap::new(),
            effects: effects::Effects::default(),
            used_names: HashSet::new(),
            pending: Vec::new(),
            output: String::new(),
            fx: None,
            action_results: HashMap::new(),
            plugin: None,
            current: String::new(),
            uses: Vec::new(),
            calls: HashMap::new(),
            host_functions: Vec::new(),
            plugin_names: ext.uses.iter().map(|p| p.name.clone()).collect(),
            abilities: crate::wordpress::abilities::Catalog::default(),
            wordpress_uses: Vec::new(),
        }
    }

    /// What registering a plugin root itself uses: the collection a hook sees, its own action or
    /// route, and the secret a signature reads.
    fn root_capabilities(&mut self, root: &Root) {
        let span = root.closure.func.span;
        match &root.slot {
            Slot::Hook { table, kind } => {
                let key = self.schema.collections[table].key.clone();
                // Every hook receives its row: that read is granted, but it isn't data the
                // handler chose to read, so the flow report doesn't attribute it.
                let handler = std::mem::take(&mut self.current);
                self.uses("read", key.clone(), span);
                self.current = handler;
                self.uses("hooks", format!("{key}.{}", kind.name()), span);
            }
            Slot::ActionHandler { action } => {
                self.uses("actions", action.clone(), span);
                if let Some(env) = self.schema.actions[action]
                    .signature
                    .as_ref()
                    .and_then(|s| s["secret"]["env"].as_str())
                {
                    self.uses("env", env.to_string(), span);
                }
            }
            Slot::RouteHandler { route } => {
                let r = &self.schema.routes[route];
                self.uses("routes", format!("{} {}", r.method, r.path), span);
                if let Some(env) = r.signature.as_ref().and_then(|s| s["secret"]["env"].as_str()) {
                    self.uses("env", env.to_string(), span);
                }
            }
            _ => {}
        }
    }

    /// A before-hook writes the fields it sets on `data` (directly, through helpers it passes
    /// `data` to, or through `ctx.data`) and the fields of a replacement it returns.
    fn hook_writes(&mut self, root: &Root) {
        let Slot::Hook {
            table,
            kind: HookKind::BeforeCreate | HookKind::BeforeUpdate,
        } = &root.slot
        else {
            return;
        };
        if self.plugin.is_none() {
            return;
        }
        let key = self.schema.collections[table].key.clone();
        let f = &root.closure.func;
        let mut fields = std::collections::BTreeSet::new();
        if let Some(p) = f.params.first() {
            let columns = &self.schema.collections[table].columns;
            for path in self.effects.writes_below(p.pat.span()) {
                match path.as_slice() {
                    [] => {}
                    [effects::Step::Field(name), ..] if columns.contains_key(name) => {
                        fields.insert(name.clone());
                    }
                    _ => {
                        fields.insert("*".to_string());
                    }
                }
            }
            if !matches!(p.pat, Pat::Id(..)) {
                fields.insert("*".to_string());
            }
        }
        if let Some(Ty::Fn(_, ret)) = self.helper_types.get(&closure_key(&root.closure)) {
            let ret = match &**ret {
                Ty::Promise(t) => (**t).clone(),
                t => t.clone(),
            };
            let parts = match ret {
                Ty::Union(ts) => ts,
                t => vec![t],
            };
            for t in parts {
                match t {
                    Ty::Void | Ty::Undefined | Ty::Null | Ty::Never => {}
                    Ty::Obj(f) => fields.extend(f.keys().cloned()),
                    _ => {
                        fields.insert("*".to_string());
                    }
                }
            }
        }
        for field in fields {
            self.uses("write", format!("{key}.{field}"), f.span);
        }
    }

    /// One PHP function running several hooks of a collection in order: plugins in `use` order,
    /// then the app's. A before-hook's returned replacement becomes the next hook's `data`.
    pub fn chain(&mut self, table: &str, kind: HookKind, functions: &[String]) -> String {
        let name = self.unique_name(&format!("{table}__{}__chain", kind.name()));
        let calls: Vec<String> = functions.iter().map(|f| format!("'\\\\ZeroApp\\\\{f}'")).collect();
        let list = calls.join(", ");
        let body = match kind {
            HookKind::BeforeCreate => format!(
                "function {name}(\\Zero\\JsObject $data, $ctx)\n{{\n    foreach ([{list}] as $hook) {{\n        $result = $hook($data, $ctx);\n        if ($result instanceof \\Zero\\JsObject) {{\n            $data = $result;\n            $ctx->p['data'] = $result;\n        }}\n    }}\n    return $data;\n}}\n\n"
            ),
            HookKind::BeforeUpdate => format!(
                "function {name}(\\Zero\\JsObject $data, $existing, $ctx)\n{{\n    foreach ([{list}] as $hook) {{\n        $result = $hook($data, $existing, $ctx);\n        if ($result instanceof \\Zero\\JsObject) {{\n            $data = $result;\n            $ctx->p['data'] = $result;\n        }}\n    }}\n    return $data;\n}}\n\n"
            ),
            _ => format!(
                "function {name}(...$arguments)\n{{\n    foreach ([{list}] as $hook) {{\n        $hook(...$arguments);\n    }}\n}}\n\n"
            ),
        };
        self.output.push_str(&body);
        name
    }

    /// Records a capability the plugin compiling now uses; app code is unrestricted.
    fn uses(&mut self, kind: &'static str, value: impl Into<String>, span: Span) {
        if let Some((plugin, _)) = &self.plugin {
            self.uses.push(crate::plugins::Use {
                plugin: *plugin,
                function: self.current.clone(),
                kind,
                value: value.into(),
                span,
            });
        }
    }

    /// Plugins never share compiled state: their helpers, captured data and classes are emitted
    /// once per plugin under its own prefix.
    fn owner(&self) -> usize {
        self.plugin.as_ref().map_or(0, |(i, _)| i + 1)
    }

    fn named(&self, base: &str) -> String {
        match &self.plugin {
            Some((_, name)) if !base.starts_with(&format!("plugin_{name}__")) => format!("plugin_{name}__{base}"),
            _ => base.to_string(),
        }
    }

    fn fx(&mut self) -> &mut FnCtx {
        self.fx.as_mut().expect("inside a function")
    }

    fn unique_name(&mut self, base: &str) -> String {
        let base = &self.named(base);
        let mut name: String = base
            .chars()
            .map(|c| if c.is_ascii_alphanumeric() || c == '_' { c } else { '_' })
            .collect();
        if name.is_empty()
            || name.starts_with(|c: char| c.is_ascii_digit())
            || matches!(
                name.to_ascii_lowercase().as_str(),
                "__halt_compiler"
                    | "abstract"
                    | "and"
                    | "array"
                    | "as"
                    | "break"
                    | "callable"
                    | "case"
                    | "catch"
                    | "class"
                    | "clone"
                    | "const"
                    | "continue"
                    | "declare"
                    | "default"
                    | "die"
                    | "do"
                    | "echo"
                    | "else"
                    | "elseif"
                    | "empty"
                    | "enddeclare"
                    | "endfor"
                    | "endforeach"
                    | "endif"
                    | "endswitch"
                    | "endwhile"
                    | "enum"
                    | "eval"
                    | "exit"
                    | "extends"
                    | "false"
                    | "final"
                    | "finally"
                    | "fn"
                    | "for"
                    | "foreach"
                    | "function"
                    | "global"
                    | "goto"
                    | "if"
                    | "implements"
                    | "include"
                    | "include_once"
                    | "instanceof"
                    | "insteadof"
                    | "interface"
                    | "isset"
                    | "list"
                    | "match"
                    | "namespace"
                    | "new"
                    | "null"
                    | "or"
                    | "parent"
                    | "print"
                    | "private"
                    | "protected"
                    | "public"
                    | "readonly"
                    | "require"
                    | "require_once"
                    | "return"
                    | "self"
                    | "static"
                    | "switch"
                    | "throw"
                    | "trait"
                    | "true"
                    | "try"
                    | "unset"
                    | "use"
                    | "var"
                    | "while"
                    | "xor"
                    | "yield"
            )
        {
            name = format!("zero_{name}");
        }
        if self.used_names.contains(&name.to_ascii_lowercase()) {
            let mut i = 2;
            while self.used_names.contains(&format!("{name}_{i}").to_ascii_lowercase()) {
                i += 1;
            }
            name = format!("{name}_{i}");
        }
        self.used_names.insert(name.to_ascii_lowercase());
        name
    }

    /// Resolves all JSON annotations before any function is compiled, including nested handler inputs.
    pub fn resolve_json_types(&mut self) -> Result<()> {
        JSON_TYPES.with(|m| m.borrow_mut().clear());
        for collection in self.schema.collections.values() {
            self.resolve_json_columns(&collection.columns)?;
        }
        for global in self.schema.globals.values() {
            self.resolve_json_columns(&global.fields)?;
        }
        for action in self.schema.actions.values() {
            self.resolve_json_columns(&action.input)?;
        }
        for job in self.schema.jobs.values() {
            self.resolve_json_columns(&job.input)?;
        }
        Ok(())
    }

    fn resolve_json_columns(&mut self, columns: &IndexMap<String, Column>) -> Result<()> {
        for col in columns.values() {
            self.resolve_json_column(col)?;
            if let Some(fields) = &col.fields {
                self.resolve_json_columns(fields)?;
            }
            if let Some(layouts) = &col.layouts {
                for fields in layouts.values() {
                    self.resolve_json_columns(fields)?;
                }
            }
        }
        Ok(())
    }

    fn resolve_json_column(&mut self, col: &Column) -> Result<()> {
        let Some((ann, module, span)) = &col.ts_type else {
            return Ok(());
        };
        let key = col as *const Column as usize;
        if JSON_TYPES.with(|m| m.borrow().contains_key(&key)) {
            return Ok(());
        }
        if self.resolving_json_types.len() >= 96 {
            return err(*span, "JSON model expansion exceeds Zero's 96-level limit");
        }
        if !self.resolving_json_types.insert(key) {
            return err(*span, "recursive JSON model annotations aren't supported");
        }
        let result = (|| {
            let authored = self.resolve_annotation_root(ann, *module)?;
            let ty = annotations::runtime_type(authored.clone());
            if classes::wire_type(&ty) != ty {
                return err_help(
                    *span,
                    "JSON schemas cannot use class instance types",
                    "describe the stored own fields with an object type; JSON does not restore methods or instanceof identity",
                );
            }
            if !assignable(&types::json_wire_type(&ty), &ty) {
                return err_help(
                    *span,
                    "JSON schemas must accept their serialized values",
                    "use null for undefined array slots, and optional properties for omitted values",
                );
            }
            JSON_TYPES.with(|m| m.borrow_mut().insert(key, types::JsonType { authored, runtime: ty }));
            Ok(())
        })();
        self.resolving_json_types.remove(&key);
        result
    }

    /// Compiles every root (hooks, actions, defaults) and returns slot → PHP function name.
    pub fn compile_roots(&mut self, roots: &[Root]) -> Result<Vec<(Slot, String)>> {
        // Solve all roots together before emitting shared helper signatures.
        self.effects = effects::Effects::analyze(roots, self.schema, self.ev_program());
        let mut out = Vec::new();
        for i in 0..self.plugin_names.len() {
            self.plugin = Some((i, self.plugin_names[i].clone()));
            let name = self.unique_name("fetch_hosts");
            self.host_functions.push(name);
        }
        for root in roots {
            self.plugin = root.plugin.map(|i| (i, self.plugin_names[i].clone()));
            let params = self.root_params(&root.slot);
            let name = self.unique_name(&root.name);
            self.current = name.clone();
            self.root_capabilities(root);
            // A hook given by reference (`afterCreate: restock`) reuses the helper's compiled body.
            let key = closure_key(&root.closure);
            let no_db = matches!(root.slot, Slot::Access { .. } | Slot::FieldAccess { .. });
            self.compile_function(&root.closure, &name, Some(params), Some(&root.slot), no_db)?;
            if let Slot::ActionHandler { action } = &root.slot
                && let Some(Ty::Fn(_, ret)) = self.helper_types.get(&key)
            {
                let ret = match &**ret {
                    Ty::Promise(t) => (**t).clone(),
                    t => t.clone(),
                };
                crate::builtins::check_json_serialization_type(&ret, root.closure.func.span)?;
                self.check_class_wire_type(&ret, root.closure.func.span)?;
                self.action_results.insert(action.clone(), ret);
            }
            if let Slot::WpHook { index } = &root.slot {
                let hook = &self.schema.wp_hooks[*index];
                let returns = crate::wordpress::hook(&hook.hook).expect("catalog hook").returns;
                let ret = match self.helper_types.get(&key) {
                    Some(Ty::Fn(_, ret)) => (**ret).clone(),
                    _ => Ty::Unknown,
                };
                if matches!(ret, Ty::Promise(_)) {
                    return err_help(
                        root.closure.func.span,
                        format!("a `{}` handler can't be async", hook.hook),
                        "WordPress calls hooks synchronously; remove `async` and `await`",
                    );
                }
                if hook.filter && !assignable(&ret, &returns) {
                    return err_help(
                        root.closure.func.span,
                        format!(
                            "the `{}` filter must return {}, not {}",
                            hook.hook,
                            returns.describe(),
                            ret.describe()
                        ),
                        "return the filtered value, changed or not",
                    );
                }
                self.use_wordpress(WordPressKind::Hook, &hook.hook, root.closure.func.span);
            }
            if let Slot::RouteHandler { route } = &root.slot {
                let ret = match self.helper_types.get(&key) {
                    Some(Ty::Fn(_, ret)) => match &**ret {
                        Ty::Promise(t) => (**t).clone(),
                        t => t.clone(),
                    },
                    _ => Ty::Unknown,
                };
                if !assignable(&ret, &types::route_response()) {
                    return err_help(
                        root.closure.func.span,
                        format!("routes.{route} returns {}, not a route response", ret.describe()),
                        "return { status?: number, headers?: Record<string, string>, body?: string }",
                    );
                }
            }
            self.helpers
                .entry((self.owner(), key, no_db))
                .or_insert_with(|| name.clone());
            self.hook_writes(root);
            out.push((root.slot.clone(), name));
        }
        self.plugin = None;
        while let Some((closure, name, params)) = self.pending.pop() {
            self.compile_function(&closure, &name, params, None, false)?;
        }
        if self.uses_jobs {
            self.wrap_roots_with_job_queue(&out);
        }
        Ok(out)
    }

    fn root_params(&self, slot: &Slot) -> Vec<Ty> {
        let ctx = types::hook_ctx();
        match slot {
            Slot::Hook { table, kind } => {
                let row = types::row(self.schema, table);
                match kind {
                    HookKind::BeforeCreate => {
                        let data = types::insert(self.schema, table);
                        vec![data.clone(), types::data_ctx(data)]
                    }
                    HookKind::BeforeUpdate => {
                        let data = types::partial(types::insert(self.schema, table));
                        vec![data.clone(), row, types::data_ctx(data)]
                    }
                    HookKind::AfterUpdate => vec![row.clone(), row, ctx],
                    _ => vec![row, ctx],
                }
            }
            Slot::JobHandler { job } => vec![
                types::action_input(&self.schema.jobs[job].input, &types::json_ty),
                types::job_ctx(),
            ],
            Slot::FieldAccess { .. } => vec![types::field_access_ctx()],
            Slot::WpHook { index } => crate::wordpress::hook(&self.schema.wp_hooks[*index].hook)
                .expect("checked in the pipeline")
                .params
                .into_iter()
                .map(|(_, t)| t)
                .collect(),
            Slot::RouteHandler { .. } => vec![types::route_request(), ctx],
            Slot::ActionHandler { action } => vec![
                types::action_input(&self.schema.actions[action].input, &types::json_ty),
                ctx,
            ],
            Slot::Access { owner, op } => {
                let data = match owner {
                    Owner::Collection(t) if op == "create" => types::partial(types::insert(self.schema, t)),
                    Owner::Collection(t) if op == "update" => types::partial(types::insert(self.schema, t)),
                    Owner::Global(g) if op == "update" => types::partial(types::global_row(&self.schema.globals[g])),
                    _ => Ty::Map(Box::new(Ty::Unknown)),
                };
                vec![types::access_ctx(data)]
            }
            Slot::Validate { at } => {
                let col = self.schema.column_at(at);
                let fields = match &at.owner {
                    Owner::Collection(table) => &self.schema.collections[table].columns,
                    Owner::Global(name) => &self.schema.globals[name].fields,
                    Owner::Action(name) => &self.schema.actions[name].input,
                    Owner::Job(name) => &self.schema.jobs[name].input,
                    Owner::FileBucket(_) | Owner::Route(_) => {
                        unreachable!("file buckets and routes have no validator fields")
                    }
                };
                vec![
                    types::col_base(col, &types::json_ty, false),
                    types::data_ctx(types::action_input(fields, &types::json_ty)),
                ]
            }
            Slot::Default { .. } | Slot::OnUpdate { .. } => vec![],
        }
    }

    fn compile_function(
        &mut self,
        closure: &Rc<Closure>,
        name: &str,
        params: Option<Vec<Ty>>,
        slot: Option<&Slot>,
        no_db: bool,
    ) -> Result<()> {
        // Keep identity keys alive while helper signatures and wrappers refer to them.
        self.helper_closures
            .entry(closure_key(closure))
            .or_insert_with(|| closure.clone());
        let f = closure.func.clone();
        if f.is_generator && !self.javascript_module(f.module) {
            return err_help(
                f.span,
                "generators only work in imported JavaScript",
                "return an array, or move the generator into a .js module",
            );
        }
        if matches!(slot, Some(Slot::FieldAccess { .. })) && f.is_async {
            return err_help(
                f.span,
                "field rules must be synchronous",
                "return a boolean without async/await",
            );
        }
        let mut fx = FnCtx {
            function: f.span,
            scopes: vec![HashMap::new()],
            is_async: f.is_async,
            is_generator: f.is_generator,
            ret: None,
            returns: Vec::new(),
            diverging: HashSet::new(),
            exhaustive_switches: HashSet::new(),
            loop_bindings: HashSet::new(),
            env: closure.env.clone(),
            module: f.module,
            php_names: HashSet::new(),
            captures: None,
            outer: None,
            tmp: 0,
            jumps: Vec::new(),
            pending_labels: Vec::new(),
            conditional_depth: 0,
            no_db,
            access: match slot {
                Some(Slot::FieldAccess { .. }) => Some((None, "field".into())),
                Some(Slot::Access { owner, op }) => Some((
                    match owner {
                        Owner::Collection(t) => Some(t.clone()),
                        _ => None,
                    },
                    op.clone(),
                )),
                _ => None,
            },
        };
        if !f.type_params.is_empty() {
            return err_help(
                f.span,
                "generic runtime functions aren't supported",
                "use generic type aliases with concrete function signatures",
            );
        }
        let mut param_tys = Vec::new();
        for (i, p) in f.params.iter().enumerate() {
            let declared = match &p.ann {
                Some(a) => Some(self.resolve_runtime_ann(a, f.module)?),
                None => None,
            };
            let given = params.as_ref().and_then(|ps| ps.get(i)).map(|ty| match ty {
                Ty::Rest(inner) => (**inner).clone(),
                other => other.clone(),
            });
            let t = match (declared, given) {
                (Some(d), Some(g)) => {
                    check_parameter_argument(&g, &parameter_input_type(p, d.clone()), p.span)?;
                    d
                }
                (Some(d), None) => d,
                (None, Some(g)) => widen_fresh_literals(&g),
                (None, None) if self.javascript_module(f.module) => {
                    if p.rest {
                        Ty::Array(Box::new(Ty::Dynamic))
                    } else {
                        Ty::Dynamic
                    }
                }
                (None, None) => {
                    return err_help(
                        p.span,
                        "this parameter needs a type",
                        "annotate it, e.g. `(item: Item)`; Zero compiles by type",
                    );
                }
            };
            param_tys.push(parameter_input_type(p, t));
        }
        let predicate = self.function_predicate(&f, &param_tys)?;
        if predicate.is_some() {
            fx.ret = Some(Ty::Bool);
        } else if let Some(r) = &f.ret {
            let t = self.resolve_runtime_ann(r, f.module)?;
            fx.ret = Some(match t {
                Ty::Promise(inner) if f.is_async => *inner,
                t => t,
            });
        }
        if let Some(ps) = &params
            && ps.len()
                < f.params
                    .iter()
                    .filter(|p| !p.optional && p.default.is_none() && !p.rest)
                    .count()
        {
            return err(
                f.span,
                format!(
                    "this function takes {} parameters, but Zero calls it with {}",
                    f.params.len(),
                    ps.len()
                ),
            );
        }
        // Recursive calls need the signature before the body is compiled.
        if fx.ret.is_some() || self.javascript_module(f.module) {
            let fallback = Ty::Dynamic;
            let ret = fx.ret.as_ref().unwrap_or(&fallback);
            let ret = if let Some(predicate) = &predicate {
                predicate.clone()
            } else if f.is_async {
                Ty::Promise(Box::new(ret.clone()))
            } else {
                ret.clone()
            };
            self.helper_types.insert(
                closure_key(closure),
                Ty::Fn(callable_parameters(&f, &param_tys), Box::new(ret)),
            );
        }
        let saved = self.fx.replace(fx);
        let caller = std::mem::replace(&mut self.current, name.to_string());
        let result = self.compile_function_body(&f, name, &param_tys, slot);
        self.current = caller;
        let fx = std::mem::replace(&mut self.fx, saved).expect("function context");
        let (params_php, body) = result?;
        let ret_ty = predicate.unwrap_or_else(|| fx.ret.clone().unwrap_or_else(|| Ty::union(fx.returns.clone())));
        self.helper_types.insert(
            closure_key(closure),
            Ty::Fn(
                callable_parameters(&f, &param_tys),
                Box::new(if f.is_async {
                    Ty::Promise(Box::new(ret_ty))
                } else {
                    ret_ty
                }),
            ),
        );
        if self.suspends(&f) {
            let wrapper = self.suspending_wrapper(&f, &format!("\\ZeroApp\\{name}__body"));
            let _ = writeln!(
                self.output,
                "function {name}__body({params_php})\n{{\n{body}}}\n\nfunction {name}(mixed ...$args)\n{{\n    return {wrapper};\n}}\n"
            );
        } else {
            let _ = writeln!(self.output, "function {name}({params_php})\n{{\n{body}}}\n");
        }
        Ok(())
    }

    fn function_predicate(&mut self, f: &Func, params: &[Ty]) -> Result<Option<Ty>> {
        let Some(TyAnn::Predicate(name, target, span)) = &f.ret else {
            return Ok(None);
        };
        if f.is_async {
            return err(*span, "type predicates must be synchronous");
        }
        let Some(index) = f
            .params
            .iter()
            .position(|p| matches!(&p.pat, Pat::Id(n, _) if n == name))
        else {
            return err(*span, "a type predicate must name one plain function parameter");
        };
        if f.params[index].rest {
            return err(*span, "a type predicate cannot refine a rest parameter");
        }
        let target = self.resolve_annotation_root(target, f.module)?;
        let parameter = &f.params[index];
        let declared = if let Some(annotation) = &parameter.ann {
            let declared = self.resolve_annotation_root(annotation, f.module)?;
            if parameter.optional || parameter.default.is_some() {
                Ty::union([declared, Ty::Undefined])
            } else {
                declared
            }
        } else {
            params[index].clone()
        };
        if !annotations::structural_extends(&target, &declared) {
            return err(
                *span,
                format!(
                    "predicate target {} must be assignable to parameter {}",
                    target.describe(),
                    declared.describe()
                ),
            );
        }
        Ok(Some(Ty::Predicate(index, Box::new(annotations::runtime_type(target)))))
    }

    fn compile_function_body(
        &mut self,
        f: &Func,
        _name: &str,
        param_tys: &[Ty],
        _slot: Option<&Slot>,
    ) -> Result<(String, String)> {
        let arguments = self.javascript_arguments(f, 1)?;
        let mut params_php = Vec::new();
        let mut prologue = String::new();
        for (i, p) in f.params.iter().enumerate() {
            let t = param_tys[i].clone();
            if p.rest {
                let Pat::Id(name, _) = &p.pat else {
                    return err(p.span, "rest parameters need a plain name");
                };
                let php = self.declare_local(name, t, true, p.pat.span());
                let rest = self.tmp_var();
                params_php.push(format!("...{rest}"));
                let _ = writeln!(prologue, "    {php} = new \\Zero\\JsArray({rest});");
                continue;
            }
            match &p.pat {
                Pat::Id(_, _) => {
                    let php = self.declare_parameter(p, &t, &mut prologue, 1)?;
                    let default = if self.javascript_module(f.module) {
                        " = \\Zero\\JsUndefined::Value"
                    } else {
                        parameter_default_suffix(&f.params[i..], &param_tys[i..f.params.len()])
                    };
                    let hint = php_hint(&t);
                    if hint == "\\Zero\\JsObject "
                        && matches!(t, Ty::Obj(_) | Ty::Map(_))
                        && p.default.is_none()
                        && !self.effects.binding_reassigned(p.pat.span())
                    {
                        let Pat::Id(name, _) = &p.pat else { unreachable!() };
                        self.fx()
                            .scopes
                            .last_mut()
                            .expect("scope")
                            .get_mut(name)
                            .expect("parameter")
                            .native_object = true;
                    }
                    params_php.push(format!("{hint}{php}{default}"));
                }
                pat => {
                    let tmp = self.tmp_var();
                    params_php.push(format!(
                        "{}{tmp}{}",
                        php_hint(&t),
                        parameter_default_suffix(&f.params[i..], &param_tys[i..f.params.len()])
                    ));
                    let (read, initialization) = self.initialize_parameter(p, &tmp, &t, 1)?;
                    prologue.push_str(&initialization);
                    self.bind_pattern_types(
                        pat,
                        &tmp,
                        BindingTypes {
                            read,
                            constraint: t.clone(),
                        },
                        true,
                        &mut prologue,
                        1,
                    )?;
                }
            }
        }
        let mut body = arguments + &self.javascript_prologue(f, 1)?;
        body.push_str(&prologue);
        body.push_str(&self.generator_prologue(f, 1));
        match &f.body {
            FnBody::Expr(e) => {
                let v = self.return_value(e)?;
                self.note_return(&v.ty, e.span)?;
                let _ = writeln!(body, "    return {};", v.php);
            }
            FnBody::Block(stmts) => {
                body.push_str(&self.block(stmts, 1)?);
                self.note_fallthrough(stmts, f.span)?;
                body.push_str("    return \\Zero\\Js::undefined();\n");
            }
        }
        Ok((params_php.join(", "), body))
    }

    fn note_return(&mut self, t: &Ty, span: Span) -> Result<()> {
        let fx = self.fx();
        if matches!(&fx.access, Some((_, op)) if op == "field") && !assignable(t, &Ty::Bool) {
            return err_help(
                span,
                "field rules must return a boolean on every path",
                "return true or false, or throw an error",
            );
        }
        if let Some(r) = &fx.ret {
            if !assignable(t, r) {
                let r = r.clone();
                return err(
                    span,
                    format!(
                        "this returns {}, but the function is declared to return {}",
                        t.describe(),
                        r.describe()
                    ),
                );
            }
        } else {
            fx.returns.push(t.clone());
        }
        Ok(())
    }

    fn note_fallthrough(&mut self, stmts: &[Stmt], span: Span) -> Result<()> {
        let fx = self.fx();
        if block_completions(stmts, &fx.diverging, &fx.exhaustive_switches).contains(&Completion::Normal) {
            self.note_return(&Ty::Undefined, span)?;
        }
        Ok(())
    }

    // ---- scopes -------------------------------------------------------------

    fn declare_local(&mut self, name: &str, ty: Ty, mutable: bool, binding: Span) -> String {
        let read_ty = self.effects.parameter_type(binding, &ty);
        let fx = self.fx();
        let php = if let Some(local) = fx.scopes.last().expect("scope").get(name)
            && local.binding == binding
        {
            local.php.clone()
        } else {
            let base = php_local_base(name);
            let mut candidate = base.clone();
            while !fx.php_names.insert(candidate.clone()) {
                fx.tmp += 1;
                candidate = format!("{base}_{}", fx.tmp);
            }
            format!("${candidate}")
        };
        fx.scopes.last_mut().expect("scope").insert(
            name.to_string(),
            Local {
                php: php.clone(),
                constraint: ty.clone(),
                ty: read_ty,
                mutable,
                binding,
                view: Vec::new(),
                native_object: false,
                initialized: true,
            },
        );
        php
    }

    fn predeclare_lexical(&mut self, pat: &Pat, mutable: bool) {
        let mut bindings = Vec::new();
        pat.bindings(&mut bindings);
        for (name, binding) in bindings {
            if self
                .fx()
                .scopes
                .last()
                .expect("scope")
                .get(name)
                .is_some_and(|local| local.binding == binding)
            {
                continue;
            }
            self.declare_local(name, Ty::Unknown, mutable, binding);
            self.fx()
                .scopes
                .last_mut()
                .expect("scope")
                .get_mut(name)
                .expect("binding")
                .initialized = false;
        }
    }

    fn set_local_ty(&mut self, name: &str, ty: Ty) {
        for s in self.fx().scopes.iter_mut().rev() {
            if let Some(l) = s.get_mut(name) {
                l.ty = ty;
                return;
            }
        }
    }

    fn resolve(&mut self, name: &str, span: Span) -> Result<Resolved> {
        // Locals of the current function, then enclosing compiled functions (closures).
        let fx = self.fx.as_mut().expect("fn");
        for s in fx.scopes.iter().rev() {
            if let Some(l) = s.get(name) {
                require_initialized_local(name, l, span)?;
                return Ok(Resolved::Local(l.clone()));
            }
        }
        let mut functions = vec![fx.function];
        let mut outer = fx.outer.as_deref();
        let mut found = None;
        while let Some(o) = outer {
            if let Some(l) = o.scopes.iter().rev().find_map(|s| s.get(name)) {
                require_initialized_local(name, l, span)?;
                found = Some(l.clone());
                break;
            }
            functions.push(o.function);
            outer = o.outer.as_deref();
        }
        if let Some(mut l) = found {
            for function in functions.into_iter().rev() {
                l.ty = self
                    .effects
                    .capture_type(function, l.binding, &l.view, &l.ty, &l.constraint);
            }
            // Record the capture on every closure level between here and the owner.
            let fx = self.fx.as_mut().expect("fn");
            let var = l.php.trim_start_matches('$').to_string();
            let mut cur = Some(fx);
            while let Some(c) = cur {
                if c.scopes.iter().any(|s| s.values().any(|x| x.php == l.php)) {
                    break;
                }
                c.php_names.insert(var.clone());
                if let Some(caps) = &mut c.captures
                    && !caps.iter().any(|(n, _)| *n == var)
                {
                    caps.push((var.clone(), l.mutable));
                }
                cur = c.outer.as_deref_mut();
            }
            return Ok(Resolved::Local(l));
        }
        if let Some(b) = fx.env.lookup(name) {
            if !b.initialized {
                return err_help(
                    span,
                    format!("`{name}` isn't initialized"),
                    "initialize the lexical binding before reading or assigning it",
                );
            }
            return Ok(Resolved::Value(b.value.clone(), b));
        }
        if let Some(g) = GLOBALS.iter().find(|g| **g == name) {
            return Ok(Resolved::Global(g));
        }
        let mut d = Diag::new(span, format!("`{name}` isn't defined"));
        let names: Vec<String> = fx.scopes.iter().flat_map(|s| s.keys().cloned()).collect();
        if let Some(s) = suggest(name, names.iter().map(String::as_str)) {
            d = d.help(format!("did you mean `{s}`?"));
        }
        Err(d)
    }

    // ---- types --------------------------------------------------------------

    fn ev_program(&self) -> &crate::loader::Program {
        self.ev.program()
    }

    fn collection_from_query(&mut self, path: &[String], span: Span, module: usize) -> Result<String> {
        let scope = self.ev.module_scope(module).expect("module evaluated");
        let mut v = match scope.lookup(&path[0]) {
            Some(b) => b.value,
            None => return err(span, format!("`{}` isn't defined", path[0])),
        };
        for p in &path[1..] {
            v = match &v {
                Value::Object(o) => o.borrow().get(p).cloned().unwrap_or(Value::Undef),
                _ => Value::Undef,
            };
        }
        match v {
            Value::Collection(c) => self.table_of(&c, span),
            other => err(
                span,
                format!("`typeof {}` is {}, not a collection", path.join("."), other.describe()),
            ),
        }
    }

    fn table_of(&self, c: &Rc<CollectionVal>, span: Span) -> Result<String> {
        if self.collections.iter().any(|x| Rc::ptr_eq(x, c)) {
            Ok(c.table().to_string())
        } else {
            err(span, format!("collection \"{}\" is not registered", c.table()))
        }
    }

    // ---- statements -----------------------------------------------------------

    fn block(&mut self, stmts: &[Stmt], indent: usize) -> Result<String> {
        self.fx().scopes.push(HashMap::new());
        let r = self.stmts(stmts, indent);
        self.fx().scopes.pop();
        r
    }

    fn stmts(&mut self, stmts: &[Stmt], indent: usize) -> Result<String> {
        let mut out = String::new();
        for stmt in stmts {
            if let Stmt::Decl { kind, pat, .. } = stmt
                && *kind != DeclKind::Var
            {
                self.predeclare_lexical(pat, *kind == DeclKind::Let);
            }
        }
        // Local function declarations are hoisted: compile them first. A declared signature (or
        // an imported-JavaScript function) types the binding before its body compiles, so the
        // body may call itself; PHP sees the binding by reference, so the call finds the closure.
        for s in stmts {
            if let Stmt::Func(f) = s {
                let name = f.name.clone().expect("named");
                let context = self
                    .named_callback
                    .take_if(|(span, _)| *span == f.span)
                    .map(|(_, params)| params);
                let signature = self.declared_signature(f)?;
                let recursive = signature.is_some();
                let php = self.declare_local(&name, signature.unwrap_or(Ty::Unknown), recursive, f.span);
                let c = match &context {
                    Some(params) => self.closure_with_types(f, Some(params))?,
                    None => self.closure_expr(f)?,
                };
                self.set_local_ty(&name, c.ty.clone());
                let _ = writeln!(out, "{}{php} = {};", pad(indent), c.php);
            }
        }
        let mut terminated = false;
        for s in stmts {
            if terminated {
                return err(stmt_span(s), "this code can never run");
            }
            out.push_str(&self.stmt(s, indent)?);
            terminated = matches!(
                s,
                Stmt::Return(..) | Stmt::Throw(_) | Stmt::Break(..) | Stmt::Continue(..)
            );
        }
        Ok(out)
    }

    fn conditional_stmts(&mut self, stmts: &[Stmt], indent: usize) -> Result<String> {
        self.fx().conditional_depth += 1;
        let result = self.stmts(stmts, indent);
        self.fx().conditional_depth -= 1;
        result
    }

    fn stmt(&mut self, s: &Stmt, indent: usize) -> Result<String> {
        let p = pad(indent);
        let mut out = String::new();
        // Labels written directly on this statement (only a loop or switch receives them).
        let labels = std::mem::take(&mut self.fx().pending_labels);
        match s {
            Stmt::Decl {
                kind,
                pat,
                ann,
                init,
                span,
            } => {
                if *kind == DeclKind::Var {
                    if let Some(init) = init {
                        let value = self.expr(init)?;
                        let value = self.finish_value(value, init.span)?;
                        let Pat::Id(name, _) = pat else {
                            return err(*span, "destructured var declarations aren't supported yet");
                        };
                        let Resolved::Local(local) = self.resolve(name, *span)? else {
                            return err(*span, "var binding wasn't hoisted");
                        };
                        if let Some(ann) = ann {
                            let declared = self.resolve_runtime_ann(ann, self.fx.as_ref().expect("fn").module)?;
                            if !assignable(&value.ty, &declared) {
                                return err(*span, "var initializer doesn't fit its annotation");
                            }
                        }
                        let _ = writeln!(out, "{p}{} = {};", local.php, value.php);
                        if local.constraint != Ty::Dynamic {
                            let value_ty = widen_fresh_literals(&value.ty);
                            let ty = if self.fx().conditional_depth > 0 {
                                Ty::union([local.ty.clone(), value_ty.clone()])
                            } else {
                                value_ty.clone()
                            };
                            for scope in self.fx().scopes.iter_mut().rev() {
                                if let Some(local) = scope.get_mut(name) {
                                    if local.constraint == Ty::Unknown {
                                        local.constraint = value_ty.clone();
                                    }
                                    local.ty = ty;
                                    break;
                                }
                            }
                        }
                    }
                    return Ok(out);
                }
                self.predeclare_lexical(pat, *kind == DeclKind::Let);
                let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                let declared = match ann {
                    Some(a) => {
                        let module = self.fx().module;
                        Some(self.resolve_runtime_ann(a, module)?)
                    }
                    None => None,
                };
                let value = match init {
                    Some(e) => {
                        let v = self.expr(e)?;
                        Some(self.finish_value(v, e.span)?)
                    }
                    None => None,
                };
                let ty = match (&declared, &value) {
                    (None, _) if javascript => Ty::Dynamic,
                    (Some(d), Some(v)) => {
                        if !assignable(&v.ty, d) {
                            let help = if matches!(d.non_null(), Ty::MapOf(..) | Ty::SetOf(_)) {
                                "Map and Set type arguments must match exactly, because both are mutable; copy into a new collection to widen"
                            } else {
                                "change the annotation or the value so their types agree"
                            };
                            return err_help(
                                *span,
                                format!("{} isn't assignable to {}", v.ty.describe(), d.describe()),
                                help,
                            );
                        }
                        d.clone()
                    }
                    (Some(d), None) => Ty::union([d.clone(), Ty::Undefined]),
                    (None, Some(v)) => inferred_binding_type(&v.ty, *kind == DeclKind::Let),
                    (None, None) => {
                        return err_help(
                            *span,
                            "this variable needs a type or a value",
                            "write `let x: number = 0`",
                        );
                    }
                };
                let mutable = *kind != DeclKind::Const;
                match pat {
                    Pat::Id(n, _) => {
                        let php = self.declare_local(n, ty, mutable, pat.span());
                        let v = value.map(|v| v.php).unwrap_or_else(|| "\\Zero\\Js::undefined()".into());
                        self.fresh_binding(&php, pat.span(), &mut out, indent);
                        let _ = writeln!(out, "{p}{php} = {v};");
                    }
                    pat => {
                        let v = value.expect("destructuring needs a value");
                        let src = if v.place.is_some() || v.atomic && v.php.starts_with('$') {
                            v.php.clone()
                        } else {
                            let tmp = self.tmp_var();
                            let _ = writeln!(out, "{p}{tmp} = {};", v.php);
                            tmp
                        };
                        self.bind_pattern(pat, &src, &ty, mutable, &mut out, indent)?;
                    }
                }
            }
            Stmt::Func(_) => {} // hoisted in `stmts`
            Stmt::TypeAlias { .. } => {}
            Stmt::Expr(e) => {
                let v = match &e.kind {
                    ExprKind::Assign { op, target, value } => self.assign(*op, target, value, e.span, true)?,
                    _ => self.expr(e)?,
                };
                if matches!(v.ty, Ty::Promise(_)) {
                    return err_help(
                        e.span,
                        "this async call isn't awaited",
                        "add `await`; Zero runs async calls in order",
                    );
                }
                if matches!(v.ty, Ty::Builder(_)) {
                    return err_help(e.span, "this database call never runs", "add `await` in front of it");
                }
                self.apply_effects(e.span);
                let _ = writeln!(out, "{p}{};", v.php.replace('\n', &format!("\n{p}")));
            }
            Stmt::Return(e, span) => match e {
                Some(e) => {
                    let v = self.return_value(e)?;
                    self.note_return(&v.ty, *span)?;
                    let _ = writeln!(out, "{p}return {};", v.php);
                }
                None => {
                    self.note_return(&Ty::Undefined, *span)?;
                    let _ = writeln!(out, "{p}return \\Zero\\Js::undefined();");
                }
            },
            Stmt::If(test, then, otherwise) => {
                let narrow = self.narrowing(test);
                let t = self.cond(test)?;
                let _ = writeln!(out, "{p}if ({t}) {{");
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, true);
                out.push_str(&self.conditional_stmts(then, indent + 1)?);
                self.fx().scopes.pop();
                let then_exits = ends_with_exit(then);
                if let Some(o) = otherwise {
                    let _ = writeln!(out, "{p}}} else {{");
                    self.fx().scopes.push(HashMap::new());
                    self.apply_narrowing(&narrow, false);
                    out.push_str(&self.conditional_stmts(o, indent + 1)?);
                    self.fx().scopes.pop();
                }
                let _ = writeln!(out, "{p}}}");
                // `if (!x) return;` narrows `x` for the rest of the block.
                if then_exits && otherwise.is_none() {
                    self.apply_narrowing(&narrow, false);
                }
            }
            Stmt::Block(b) => {
                let inner = self.block(b, indent)?;
                out.push_str(&inner);
            }
            Stmt::For {
                init,
                test,
                update,
                body,
                span,
            } => {
                self.fx().scopes.push(HashMap::new());
                if let Some(init) = init {
                    if let Stmt::Decl { kind, pat, .. } = init.as_ref()
                        && *kind != DeclKind::Var
                    {
                        self.predeclare_lexical(pat, *kind == DeclKind::Let);
                    }
                    out.push_str(&self.stmt(init, indent)?);
                }
                self.invalidate_loop(*span);
                let test_php = match test {
                    Some(t) => self.cond(t)?,
                    None => String::new(),
                };
                let update_php = match update {
                    Some(u) => self.expr(u)?.php,
                    None => String::new(),
                };
                // `for (let i ...)`: JS gives each iteration its own `i`, and a closure keeps the one
                // it saw. A fresh cell before each update keeps earlier closures on their binding,
                // including across `continue`; copying a handle preserves the object identity.
                let fresh: Vec<String> = match init.as_deref() {
                    Some(Stmt::Decl {
                        kind: DeclKind::Let,
                        pat,
                        ..
                    }) => {
                        // Every binding the declaration introduces (`let { i } = ...` too).
                        let mut bindings = Vec::new();
                        pat.bindings(&mut bindings);
                        let captured: Vec<Span> = bindings
                            .into_iter()
                            .map(|(_, span)| span)
                            .filter(|span| self.effects.captured(*span))
                            .collect();
                        let scope = self.fx().scopes.last().cloned().unwrap_or_default();
                        let mut locals: Vec<&Local> =
                            scope.values().filter(|l| captured.contains(&l.binding)).collect();
                        locals.sort_by(|a, b| a.php.cmp(&b.php));
                        locals
                            .into_iter()
                            .map(|l| format!("{php} = &\\Zero\\Js::cell({php})", php = l.php))
                            .collect()
                    }
                    _ => Vec::new(),
                };
                // JS also copies the initializer's bindings into the first iteration's: a closure
                // made in the initializer keeps the initializer's binding.
                for cell in &fresh {
                    let _ = writeln!(out, "{p}{cell};");
                }
                let update_php = fresh
                    .into_iter()
                    .chain((!update_php.is_empty()).then_some(update_php))
                    .collect::<Vec<_>>()
                    .join(", ");
                let _ = writeln!(out, "{p}for (; {test_php}; {update_php}) {{");
                out.push_str(&self.loop_body(body, indent + 1, labels.clone())?);
                let _ = writeln!(out, "{p}}}");
                self.fx().scopes.pop();
            }
            Stmt::ForOf {
                is_await,
                kind,
                pat,
                iter,
                body,
                span,
            } => {
                let module = self.fx().module;
                // Authored TypeScript has no job queue, so `for await` is accepted only where the
                // blocking model is exact: a sync iterable (array, tuple, string) whose elements are
                // Zero's own promises or plain values. Each element is then already settled, and
                // awaiting it yields its value. Async iterables, generators and values from imported
                // JavaScript go through the job queue, which only imported JavaScript has.
                let typed_await = *is_await && !self.javascript_module(module);
                if *is_await && !self.fx().is_async {
                    return err_help(
                        *span,
                        "`for await` only works in async functions",
                        "mark the function `async`",
                    );
                }
                self.fx().scopes.push(HashMap::new());
                if *kind != DeclKind::Var {
                    self.predeclare_lexical(pat, *kind == DeclKind::Let);
                }
                // `for await (const v of [a(), b()])` awaits each element as the loop reaches it, after
                // the previous iterations' bodies ran. The blocking model would settle every call
                // before the loop starts, so a promise in the literal is refused with the exact form.
                if typed_await && let ExprKind::Array(items) = &iter.kind {
                    for item in items {
                        let ArrEl::Item(e) = item else { continue };
                        let saved = self.fx().tmp;
                        let ty = self.expr(e).map(|v| v.ty);
                        self.fx().tmp = saved;
                        if matches!(ty, Ok(Ty::Promise(_))) {
                            return err_help(
                                e.span,
                                "`for await` over a promise written in an array literal isn't supported",
                                "await each call where it is written: `for (const v of [await a(), await b()])`",
                            );
                        }
                    }
                }
                let it = self.expr(iter)?;
                let it = self.finish_value(it, iter.span)?;
                let (el, src) = match &it.ty.non_null() {
                    Ty::Dynamic => (Ty::Dynamic, format!("\\Zero\\Js::dynamicIterator({})", it.php)),
                    Ty::Array(t) | Ty::NonEmptyArray(t) => ((**t).clone(), it.php.clone()),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => (Ty::union(ts.clone()), it.php.clone()),
                    t if t.is_str() => (Ty::Str, format!("\\Zero\\Js::from({})", it.php)),
                    t @ (Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_) | Ty::UrlParams) => (
                        self.iterable_element(t).expect("collection element"),
                        format!("\\Zero\\Js::iter({})", it.php),
                    ),
                    other => return err(iter.span, format!("can't loop over {}", other.describe())),
                };
                if it.ty.is_nullable() {
                    return err_help(
                        iter.span,
                        format!("this might be null ({})", it.ty.describe()),
                        "check it first, or use `?? []`",
                    );
                }
                if typed_await {
                    let settled = awaited_element(&el);
                    // A class instance may be a thenable through a `then` method; `for await` would
                    // adopt it, which only the job queue can do.
                    if el.non_null().all(|t| matches!(t, Ty::Instance(..) | Ty::Class(_))) && !el.is_nullable() {
                        return err_help(
                            iter.span,
                            "`for await` over class instances isn't supported in authored TypeScript",
                            "use `for (const v of items)`; `for await` would adopt an instance with a `then` method as a promise",
                        );
                    }
                    if it.ty == Ty::Dynamic || settled.is_none() {
                        return err_help(
                            *span,
                            "`for await` only works in imported JavaScript",
                            "await each value inside a `for ... of` loop",
                        );
                    }
                }
                // Each `for await` value is awaited, so its type is the awaited one.
                let el = if typed_await {
                    widen_fresh_literals(&awaited_element(&el).expect("checked above"))
                } else if *is_await {
                    Ty::Dynamic
                } else {
                    widen_fresh_literals(&el)
                };
                let mutable = *kind != DeclKind::Const;
                let source = self.tmp_var();
                let index = self.tmp_var();
                let value = self.tmp_var();
                // Checked JavaScript iterates by protocol and closes the iterator on any early exit.
                let protocol = (it.ty == Ty::Dynamic || *is_await) && self.javascript_module(module);
                if protocol {
                    let open = if *is_await { "openAsync" } else { "open" };
                    if *is_await {
                        self.uses_jobs = true;
                    }
                    let _ = writeln!(
                        out,
                        "{p}{source} = \\Zero\\JsIter::{open}({});\n{p}try {{\n{p}while ({source}->step()) {{",
                        it.php
                    );
                    let _ = writeln!(out, "{}{value} = {source}->value;", pad(indent + 1));
                } else if it.ty == Ty::Dynamic
                    || matches!(it.ty, Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_) | Ty::UrlParams)
                {
                    let _ = writeln!(out, "{p}{source} = {src};\n{p}foreach ({source} as {value}) {{");
                } else {
                    let _ = writeln!(
                        out,
                        "{p}{source} = {src};\n{p}for ({index} = 0; {index} < count({source}->a); {index}++) {{"
                    );
                    let _ = writeln!(out, "{}{value} = {source}->a[{index}];", pad(indent + 1));
                }
                let mut previous = None;
                if *kind == DeclKind::Var {
                    let Pat::Id(name, _) = pat else {
                        return err(*span, "destructured var loops aren't supported yet");
                    };
                    let Resolved::Local(local) = self.resolve(name, *span)? else {
                        return err(*span, "var loop binding wasn't hoisted");
                    };
                    previous = Some((name.clone(), local.ty.clone()));
                    let _ = writeln!(out, "{}{} = {value};", pad(indent + 1), local.php);
                    self.set_var_iteration_type(name, &el, *span)?;
                } else {
                    self.bind_pattern(pat, &value, &el, mutable, &mut out, indent + 1)?;
                }
                self.invalidate_loop(*span);
                out.push_str(&self.loop_body(body, indent + 1, labels.clone())?);
                let _ = writeln!(out, "{p}}}");
                if protocol {
                    // A throw closes quietly; break/return/normal completion close and may throw.
                    let _ = writeln!(
                        out,
                        "{p}}} catch (\\Throwable {value}) {{\n{p}    {source}->abrupt();\n{p}    throw {value};\n{p}}} finally {{\n{p}    {source}->close();\n{p}}}"
                    );
                }
                self.fx().scopes.pop();
                if let Some((name, before)) = previous
                    && let Resolved::Local(local) = self.resolve(&name, *span)?
                {
                    self.set_local_ty(&name, Ty::union([before, local.ty]));
                }
            }
            Stmt::ForIn {
                kind,
                target,
                iter,
                body,
                span,
            } => {
                self.fx().scopes.push(HashMap::new());
                if let Some(kind) = kind.filter(|kind| *kind != DeclKind::Var) {
                    let ExprKind::Ident(name) = &target.kind else {
                        return err(*span, "for-in declarations need a plain name");
                    };
                    self.predeclare_lexical(&Pat::Id(name.clone(), target.span), kind == DeclKind::Let);
                }
                let value = self.expr(iter)?;
                let source = self.tmp_var();
                let index = self.tmp_var();
                let mut previous = None;
                let php = if let Some(kind) = kind {
                    let ExprKind::Ident(name) = &target.kind else {
                        return err(*span, "for-in declarations need a plain name");
                    };
                    if *kind == DeclKind::Var {
                        let Resolved::Local(local) = self.resolve(name, target.span)? else {
                            return err(*span, "var loop binding wasn't hoisted");
                        };
                        previous = Some((name.clone(), local.ty.clone()));
                        self.set_var_iteration_type(name, &Ty::Str, *span)?;
                        local.php
                    } else {
                        self.declare_local(name, Ty::Str, *kind == DeclKind::Let, target.span)
                    }
                } else {
                    let place = self.place(target)?;
                    if place.target.is_some() {
                        return err(*span, "for-in assignment targets must be a local name");
                    }
                    if !assignable(&Ty::Str, &place.constraint) {
                        return err(
                            *span,
                            format!("a for-in key is a string, not {}", place.constraint.describe()),
                        );
                    }
                    if let ExprKind::Ident(name) = &target.kind {
                        previous = Some((name.clone(), place.read.ty.clone()));
                    }
                    place.read.php
                };
                let _ = writeln!(
                    out,
                    "{p}{source} = \\Zero\\Js::dynamicForIn({});\n{p}foreach ({source} as {index}) {{",
                    value.php
                );
                if matches!(kind, Some(DeclKind::Let | DeclKind::Const)) {
                    self.fresh_binding(&php, target.span, &mut out, indent + 1);
                }
                let _ = writeln!(out, "{}{php} = {index};", pad(indent + 1));
                self.invalidate_loop(*span);
                if let Some((name, _)) = &previous
                    && let Resolved::Local(local) = self.resolve(name, *span)?
                {
                    self.set_local_ty(
                        name,
                        if local.constraint == Ty::Dynamic {
                            Ty::Dynamic
                        } else {
                            Ty::Str
                        },
                    );
                }
                out.push_str(&self.loop_body(body, indent + 1, labels.clone())?);
                let _ = writeln!(out, "{p}}}");
                self.fx().scopes.pop();
                if let Some((name, before)) = previous
                    && let Resolved::Local(local) = self.resolve(&name, *span)?
                {
                    self.set_local_ty(&name, Ty::union([before, local.ty]));
                }
            }
            Stmt::While(test, body) => {
                self.invalidate_loop(test.span);
                let t = self.cond(test)?;
                let _ = writeln!(out, "{p}while ({t}) {{");
                out.push_str(&self.loop_body(body, indent + 1, labels.clone())?);
                let _ = writeln!(out, "{p}}}");
            }
            Stmt::Break(label, span) => {
                let level = self.jump_level(label.as_deref(), false, *span)?;
                let _ = writeln!(
                    out,
                    "{p}break{};",
                    if level == 1 { String::new() } else { format!(" {level}") }
                );
            }
            Stmt::Continue(label, span) => {
                let level = self.jump_level(label.as_deref(), true, *span)?;
                let _ = writeln!(
                    out,
                    "{p}continue{};",
                    if level == 1 { String::new() } else { format!(" {level}") }
                );
            }
            Stmt::DoWhile(body, test) => {
                self.invalidate_loop(test.span);
                let _ = writeln!(out, "{p}do {{");
                out.push_str(&self.loop_body(body, indent + 1, labels)?);
                let t = self.cond(test)?;
                let _ = writeln!(out, "{p}}} while ({t});");
            }
            Stmt::Labeled(label, body, _) => {
                let mut labels = labels;
                labels.push(label.clone());
                let mut body = &**body;
                while let Stmt::Labeled(label, inner, _) = body {
                    labels.push(label.clone());
                    body = inner;
                }
                if matches!(
                    body,
                    Stmt::While(..)
                        | Stmt::DoWhile(..)
                        | Stmt::For { .. }
                        | Stmt::ForOf { .. }
                        | Stmt::ForIn { .. }
                        | Stmt::Switch { .. }
                ) {
                    self.fx().pending_labels = labels;
                    out.push_str(&self.stmt(body, indent)?);
                } else {
                    // A labeled block is left by `break label`: PHP gets a one-pass loop to break.
                    self.fx().jumps.push(JumpTarget {
                        kind: JumpKind::Block,
                        labels,
                    });
                    let _ = writeln!(out, "{p}do {{");
                    self.fx().conditional_depth += 1;
                    let inner = self.stmts(std::slice::from_ref(body), indent + 1);
                    self.fx().conditional_depth -= 1;
                    self.fx().jumps.pop();
                    out.push_str(&inner?);
                    let _ = writeln!(out, "{p}}} while (false);");
                }
            }
            Stmt::Throw(e) => {
                let v = self.throw_value(e)?;
                let _ = writeln!(out, "{p}throw {v};");
            }
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                let _ = writeln!(out, "{p}try {{");
                self.fx().conditional_depth += 1;
                let inner = self.block(block, indent + 1);
                self.fx().conditional_depth -= 1;
                out.push_str(&inner?);
                if let Some(h) = handler {
                    self.fx().scopes.push(HashMap::new());
                    // JavaScript binds the thrown value itself; JsThrow carries non-JsError values.
                    let module = self.fx().module;
                    let javascript = self.javascript_module(module);
                    let caught = self.tmp_var();
                    // Database and validation errors propagate: they must roll back the request.
                    let _ = writeln!(out, "{p}}} catch (\\Zero\\JsError {caught}) {{");
                    if let Some((n, span)) = param {
                        let var =
                            self.declare_local(n, if javascript { Ty::Dynamic } else { Ty::Error }, javascript, *span);
                        // Typed catch bindings are Errors: Error subclass instances pass, other values are refused.
                        let unwrap = if javascript { "thrownValue" } else { "caughtError" };
                        let _ = writeln!(out, "{p}    {var} = \\Zero\\Js::{unwrap}({caught});");
                    }
                    out.push_str(&self.conditional_stmts(h, indent + 1)?);
                    self.fx().scopes.pop();
                }
                if let Some(f) = finalizer {
                    let _ = writeln!(out, "{p}}} finally {{");
                    out.push_str(&self.block(f, indent + 1)?);
                }
                let _ = writeln!(out, "{p}}}");
            }
            Stmt::Switch { disc, cases, span } => {
                let d = self.expr(disc)?;
                let d = self.finish_value(d, disc.span)?;
                if !(d.ty == Ty::Dynamic || d.ty.is_num() || d.ty.non_null().is_str() || d.ty.non_null().is_bool()) {
                    return err(
                        *span,
                        format!(
                            "`switch` works on numbers, strings, and booleans, not {}",
                            d.ty.describe()
                        ),
                    );
                }
                // PHP `switch` compares loosely; compile to strict comparisons.
                let mut case_types = Vec::new();
                let tmp = self.tmp_var();
                let _ = writeln!(out, "{p}{tmp} = {};", d.php);
                let _ = writeln!(out, "{p}switch (true) {{");
                for (test, body) in cases {
                    match test {
                        Some(t) => {
                            let tv = self.expr(t)?;
                            let cmp = self.strict_eq(&Out::atom(tmp.clone(), d.ty.clone()), &tv);
                            case_types.push(tv.ty);
                            let _ = writeln!(out, "{p}    case {cmp}:");
                        }
                        None => {
                            let _ = writeln!(out, "{p}    default:");
                        }
                    }
                    self.fx().jumps.push(JumpTarget {
                        kind: JumpKind::Switch,
                        labels: labels.clone(),
                    });
                    self.fx().conditional_depth += 1;
                    let inner = self.block(body, indent + 2);
                    self.fx().conditional_depth -= 1;
                    self.fx().jumps.pop();
                    out.push_str(&inner?);
                }
                if switch_is_exhaustive(&d.ty, &case_types) {
                    self.fx().exhaustive_switches.insert(*span);
                }
                let _ = writeln!(out, "{p}}}");
            }
            Stmt::Import { span, .. } | Stmt::ExportNames { span, .. } | Stmt::ExportAll { span, .. } => {
                return err(*span, "imports belong at the top level of a module");
            }
            Stmt::ExportDefault(e) => return err(e.span, "`export default` belongs at the top level"),
            Stmt::Exported(inner) => return self.stmt(inner, indent),
        }
        Ok(out)
    }

    fn invalidate_loop(&mut self, span: Span) {
        for local in self
            .fx
            .as_mut()
            .expect("fn")
            .scopes
            .iter_mut()
            .flat_map(|scope| scope.values_mut())
        {
            local.ty = self
                .effects
                .invalidate_loop(span, local.binding, &local.view, &local.ty, &local.constraint);
        }
    }

    /// PHP's level for `break` / `continue` (optionally to a label): the number of enclosing
    /// loops, switches and labeled blocks up to and including the target.
    fn jump_level(&mut self, label: Option<&str>, is_continue: bool, span: Span) -> Result<usize> {
        let jumps = &self.fx().jumps;
        let target = jumps.iter().rposition(|target| match label {
            Some(label) => target.labels.iter().any(|l| l == label),
            None if is_continue => target.kind == JumpKind::Loop,
            None => target.kind != JumpKind::Block,
        });
        let Some(index) = target else {
            return err(span, "this jump has no enclosing target");
        };
        if is_continue && jumps[index].kind != JumpKind::Loop {
            return err(span, "`continue` must name a loop");
        }
        Ok(jumps.len() - index)
    }

    fn loop_body(&mut self, body: &[Stmt], indent: usize, labels: Vec<String>) -> Result<String> {
        self.fx().jumps.push(JumpTarget {
            kind: JumpKind::Loop,
            labels,
        });
        let saved_bindings = self.fx().loop_bindings.clone();
        let bindings: Vec<_> = self
            .fx()
            .scopes
            .iter()
            .flat_map(|scope| scope.values().map(|l| l.binding))
            .collect();
        self.fx().loop_bindings.extend(bindings);
        self.fx().conditional_depth += 1;
        let r = self.block(body, indent);
        self.fx().conditional_depth -= 1;
        self.fx().loop_bindings = saved_bindings;
        self.fx().jumps.pop();
        r
    }

    fn tmp_var(&mut self) -> String {
        let fx = self.fx();
        loop {
            fx.tmp += 1;
            let name = format!("__t{}", fx.tmp);
            if fx.php_names.insert(name.clone()) {
                return format!("${name}");
            }
        }
    }

    /// A `return` operand. In an access rule it must be a boolean or a filter literal (§1.6).
    fn return_value(&mut self, e: &Expr) -> Result<Out> {
        let Some((table, op)) = self.fx().access.clone() else {
            let v = self.expr(e)?;
            return self.finish_value(v, e.span);
        };
        match &e.kind {
            ExprKind::Cond(t, a, b) => {
                let narrow = self.narrowing(t);
                let tc = self.cond(t)?;
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, true);
                let av = self.return_value(a);
                self.fx().scopes.pop();
                let av = av?;
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, false);
                let bv = self.return_value(b);
                self.fx().scopes.pop();
                let bv = bv?;
                Ok(Out::new(
                    format!("{} ? {} : {}", paren(&tc), av.wrapped(), bv.wrapped()),
                    Ty::union([av.ty, bv.ty]),
                ))
            }
            ExprKind::Object(_) => match table {
                Some(t) if op != "create" => {
                    let php = self.filter(&t, e, true)?;
                    Ok(Out::atom(php, Ty::Map(Box::new(Ty::Unknown))))
                }
                Some(_) => err_help(
                    e.span,
                    "create rules return a boolean",
                    "a new row has nothing to filter; return true or false (check `ctx.data` if needed)",
                ),
                None => err_help(
                    e.span,
                    format!("{op} rules here return a boolean"),
                    "filters only apply to collection read, update, and delete rules",
                ),
            },
            _ => {
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                if v.ty.is_bool() || v.ty == Ty::Never {
                    return Ok(v);
                }
                err_help(
                    e.span,
                    format!("access rules return true, false, or a filter, not {}", v.ty.describe()),
                    "write a filter inline (`{ status: \"published\" }`) or turn the value into a boolean",
                )
            }
        }
    }

    fn throw_value(&mut self, e: &Expr) -> Result<String> {
        if let ExprKind::New { callee, args, .. } = &e.kind {
            let callee_v = match &callee.kind {
                ExprKind::Ident(n) => self.resolve(n, callee.span)?,
                _ => return err(callee.span, "throw `new Error(...)` or `new HttpError(...)`"),
            };
            match callee_v {
                Resolved::Global(g @ ("Error" | "TypeError" | "RangeError")) => {
                    return self.error_constructor(g, args).map(|value| value.php);
                }
                Resolved::Value(Value::Builtin(Builtin::HttpError), _) => {
                    let mut parts = Vec::new();
                    for (i, a) in args.iter().enumerate() {
                        let ArrEl::Item(a) = a else {
                            return err(e.span, "spread arguments aren't supported here");
                        };
                        let v = self.expr(a)?;
                        let want = if i == 0 { Ty::Num } else { Ty::Str };
                        if !assignable(&v.ty, &want) {
                            return err(
                                a.span,
                                format!(
                                    "HttpError argument {} must be a {}, not {}",
                                    i + 1,
                                    want.describe(),
                                    v.ty.describe()
                                ),
                            );
                        }
                        parts.push(if i == 0 {
                            format!("(int) {}", v.wrapped())
                        } else {
                            v.php
                        });
                    }
                    if !(3..=4).contains(&parts.len()) {
                        return err(e.span, "HttpError takes (status, code, message)");
                    }
                    return Ok(format!("new \\Zero\\HttpError({})", parts.join(", ")));
                }
                _ => {}
            }
        }
        let v = self.expr(e)?;
        if v.ty == Ty::Error {
            return Ok(v.php);
        }
        let module = self.fx().module;
        if self.javascript_module(module) || self.error_instance(&v.ty) {
            let v = self.finish_value(v, e.span)?;
            return Ok(format!("\\Zero\\Js::throwable({})", v.php));
        }
        err_help(
            e.span,
            "only errors can be thrown",
            "throw `new Error(\"...\")` or `new HttpError(status, code, message)`",
        )
    }

    fn bind_pattern(
        &mut self,
        pat: &Pat,
        src: &str,
        ty: &Ty,
        mutable: bool,
        out: &mut String,
        indent: usize,
    ) -> Result<()> {
        self.bind_pattern_types(
            pat,
            src,
            BindingTypes {
                read: ty.clone(),
                constraint: ty.clone(),
            },
            mutable,
            out,
            indent,
        )
    }

    fn declare_binding(&mut self, name: &str, types: BindingTypes, mutable: bool, binding: Span) -> String {
        let php = self.declare_local(name, types.constraint, mutable, binding);
        let read = self.effects.parameter_type(binding, &types.read);
        self.fx()
            .scopes
            .last_mut()
            .expect("scope")
            .get_mut(name)
            .expect("binding")
            .ty = read;
        php
    }

    fn fresh_binding(&self, php: &str, binding: Span, out: &mut String, indent: usize) {
        if self.effects.captured(binding) {
            let _ = writeln!(out, "{}unset({php});", pad(indent));
        }
    }

    fn bind_pattern_types(
        &mut self,
        pat: &Pat,
        src: &str,
        types: BindingTypes,
        mutable: bool,
        out: &mut String,
        indent: usize,
    ) -> Result<()> {
        let p = pad(indent);
        let BindingTypes { read: ty, constraint } = types;
        match pat {
            Pat::Id(n, _) => {
                let php = self.declare_binding(n, BindingTypes { read: ty, constraint }, mutable, pat.span());
                self.fresh_binding(&php, pat.span(), out, indent);
                let _ = writeln!(out, "{p}{php} = {src};");
            }
            Pat::Default(inner, default) => {
                let d = self.expr(default)?;
                let types = BindingTypes {
                    read: default_binding_type(&ty, &d.ty, mutable),
                    constraint: default_binding_type(&constraint, &d.ty, mutable),
                };
                let tmp = self.tmp_var();
                let assigned = format!("{tmp} = {src}");
                let missing = format!("({assigned}) === \\Zero\\Js::undefined()");
                let _ = writeln!(out, "{p}{tmp} = ({missing} ? {} : {tmp});", d.php);
                self.bind_pattern_types(inner, &tmp, types, mutable, out, indent)?;
            }
            Pat::Obj { props, rest, span } => {
                let source = self.tmp_var();
                let _ = writeln!(out, "{p}{source} = \\Zero\\Js::requirePresent({src});");
                let src = &source;
                let t = ty.non_null();
                if ty.is_nullable() && ty != Ty::Dynamic {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
                }
                // Imported JavaScript: any field may be read, and an absent one is undefined.
                if ty == Ty::Dynamic {
                    for (key, p2) in props {
                        let access = format!("\\Zero\\Js::dynamicGet({src}, {})", php_str(key));
                        let types = BindingTypes {
                            read: Ty::Dynamic,
                            constraint: Ty::Dynamic,
                        };
                        self.bind_pattern_types(p2, &access, types, mutable, out, indent)?;
                    }
                    if let Some((name, span)) = rest {
                        let keys: Vec<String> = props.iter().map(|(k, _)| php_str(k)).collect();
                        let types = BindingTypes {
                            read: Ty::Dynamic,
                            constraint: Ty::Dynamic,
                        };
                        let php = self.declare_binding(name, types, mutable, *span);
                        self.fresh_binding(&php, *span, out, indent);
                        let _ = writeln!(out, "{p}{php} = \\Zero\\Js::dynamicRest({src}, [{}]);", keys.join(", "));
                    }
                    return Ok(());
                }
                for (key, p2) in props {
                    let Some((ft, optional)) = t.field(key) else {
                        let mut d = Diag::new(p2.span(), format!("{} has no field `{key}`", t.describe()));
                        if let Ty::Obj(f) = &t
                            && let Some(s) = suggest(key, f.keys().map(String::as_str))
                        {
                            d = d.help(format!("did you mean `{s}`?"));
                        }
                        return Err(d);
                    };
                    let access = format!("\\Zero\\Js::dataGet({src}, {})", php_str(key));
                    let read = if optional || matches!(t, Ty::Map(_)) {
                        Ty::union([ft, Ty::Undefined])
                    } else {
                        ft
                    };
                    let original = constraint.non_null();
                    let (ct, optional) = original.field(key).expect("authored field");
                    let constraint = if optional || matches!(original, Ty::Map(_)) {
                        Ty::union([ct, Ty::Undefined])
                    } else {
                        ct
                    };
                    self.bind_pattern_types(p2, &access, BindingTypes { read, constraint }, mutable, out, indent)?;
                }
                if let Some((name, span)) = rest {
                    let keys: Vec<String> = props.iter().map(|(k, _)| php_str(k)).collect();
                    let omit = |ty: &Ty| match ty.non_null() {
                        Ty::Obj(f) => Ty::Obj(
                            f.into_iter()
                                .filter(|(k, _)| !props.iter().any(|(pk, _)| pk == k))
                                .collect(),
                        ),
                        other => other,
                    };
                    let types = BindingTypes {
                        read: omit(&ty),
                        constraint: omit(&constraint),
                    };
                    let php = self.declare_binding(name, types, mutable, *span);
                    self.fresh_binding(&php, *span, out, indent);
                    let _ = writeln!(out, "{p}{php} = \\Zero\\Js::omit({src}, [{}]);", keys.join(", "));
                }
            }
            Pat::Arr { elems, rest, span } => {
                let source = self.tmp_var();
                // Imported JavaScript destructures through the iterator protocol: it takes exactly
                // the elements the pattern names (all of them for a rest), then closes the iterator.
                if ty == Ty::Dynamic {
                    let take = if rest.is_some() {
                        "null".to_string()
                    } else {
                        elems.len().to_string()
                    };
                    let _ = writeln!(out, "{p}{source} = \\Zero\\Js::destructure({src}, {take});");
                    for (i, e) in elems.iter().enumerate() {
                        if let Some(e) = e {
                            let access = format!("\\Zero\\Js::dynamicGet({source}, {i})");
                            let types = BindingTypes {
                                read: Ty::Dynamic,
                                constraint: Ty::Dynamic,
                            };
                            self.bind_pattern_types(e, &access, types, mutable, out, indent)?;
                        }
                    }
                    if let Some((name, span)) = rest {
                        let types = BindingTypes {
                            read: Ty::Dynamic,
                            constraint: Ty::Dynamic,
                        };
                        let php = self.declare_binding(name, types, mutable, *span);
                        self.fresh_binding(&php, *span, out, indent);
                        let _ = writeln!(out, "{p}{php} = \\Zero\\Js::arrSlice({source}, {});", elems.len());
                    }
                    return Ok(());
                }
                let _ = writeln!(out, "{p}{source} = {src};");
                let src = &source;
                if ty.is_nullable() {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
                }
                let element = |ty: &Ty, i: usize| match ty.non_null() {
                    Ty::Array(t) | Ty::NonEmptyArray(t) => Some(Ty::union([*t, Ty::Undefined])),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Some(ts.get(i).cloned().unwrap_or(Ty::Undefined)),
                    _ => None,
                };
                if element(&ty, 0).is_none() {
                    return err(*span, format!("can't destructure {} as an array", ty.describe()));
                }
                let done = self.tmp_var();
                let _ = writeln!(out, "{p}{done} = false;");
                let mut changed = false;
                let live_element = |ty: &Ty, i: usize, changed: bool| {
                    if changed && let Ty::Tuple(items) | Ty::ArrayLiteral(items) = ty.non_null() {
                        Some(Ty::union(items.into_iter().chain([Ty::Undefined])))
                    } else {
                        element(ty, i)
                    }
                };
                for (i, e) in elems.iter().enumerate() {
                    let _ = writeln!(out, "{p}{done} = {done} || {i} >= count({src}->a);");
                    if let Some(e) = e {
                        let access = format!("({done} ? \\Zero\\Js::undefined() : \\Zero\\Js::dynamicGet({src}, {i}))");
                        let types = BindingTypes {
                            read: live_element(&ty, i, changed).expect("array read"),
                            constraint: live_element(&constraint, i, changed).expect("authored array"),
                        };
                        self.bind_pattern_types(e, &access, types, mutable, out, indent)?;
                        changed |= pattern_default_effects(e);
                    }
                }
                if let Some((name, span)) = rest {
                    let tail = |ty: &Ty| match ty.non_null() {
                        Ty::Array(t) | Ty::NonEmptyArray(t) => Ty::Array(t),
                        Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => {
                            Ty::Array(Box::new(Ty::union(ts.into_iter().skip(if changed {
                                0
                            } else {
                                elems.len()
                            }))))
                        }
                        _ => unreachable!("array binding"),
                    };
                    let types = BindingTypes {
                        read: tail(&ty),
                        constraint: tail(&constraint),
                    };
                    let php = self.declare_binding(name, types, mutable, *span);
                    self.fresh_binding(&php, *span, out, indent);
                    let _ = writeln!(
                        out,
                        "{p}{php} = {done} ? new \\Zero\\JsArray() : \\Zero\\Js::arrSlice({src}, {});",
                        elems.len()
                    );
                }
            }
        }
        Ok(())
    }

    // ---- narrowing ------------------------------------------------------------

    /// `x`, `!x`, `x === null`, `x != null`, `x && ...` narrow `x` in branches.
    fn narrowing(&mut self, test: &Expr) -> Vec<(String, Ty, Ty)> {
        let mut out = Vec::new();
        self.collect_narrowing(test, false, &mut out);
        out
    }

    fn collect_narrowing(&mut self, test: &Expr, negate: bool, out: &mut Vec<(String, Ty, Ty)>) {
        match &test.kind {
            ExprKind::Call {
                callee,
                args,
                optional: false,
                ..
            } => {
                if let Ok(function) = self.expr(callee)
                    && let Ty::Fn(_, result) = function.ty
                    && let Ty::Predicate(index, target) = *result
                    && let Some(ArrEl::Item(argument)) = args.get(index)
                    && let Some((path, original)) = self.path_ty(argument)
                {
                    let refined = *target;
                    out.push(if negate {
                        (path, original.clone(), refined)
                    } else {
                        (path, refined, original)
                    });
                }
            }
            ExprKind::Ident(_) | ExprKind::Member { .. } | ExprKind::Chain(_) => {
                if let Some((n, t)) = self.path_ty(test) {
                    let (yes, no) = (t.non_null(), t.clone());
                    out.push(if negate { (n, no, yes) } else { (n, yes, no) });
                }
            }
            ExprKind::Unary(UnOp::Not, inner) => self.collect_narrowing(inner, !negate, out),
            // `x instanceof C` narrows an unknown or caught value to C's instance type. Only the
            // true branch narrows: a failed check says nothing about other classes.
            ExprKind::Instanceof(value, class) => {
                if let ExprKind::Ident(name) = &class.kind
                    && let Ok(Resolved::Value(Value::Class(class), _)) = self.resolve(name, class.span)
                    && let Some((path, original)) = self.path_ty(value)
                    && matches!(original, Ty::Unknown | Ty::Error)
                    && let Ok(id) = self.ensure_class(&class)
                    && !self.classes[&id].javascript
                {
                    let instance = self.classes[&id].instance.clone();
                    out.push(if negate {
                        (path, original.clone(), instance)
                    } else {
                        (path, instance, original)
                    });
                }
            }
            // `"key" in x` keeps the object-union members that declare `key` (TypeScript's `in`
            // narrowing). A member with an open map or an optional `key` stays on both sides.
            ExprKind::In(key, object) => {
                if let ExprKind::Str(key) = &key.kind
                    && let Some((path, original)) = self.path_ty(object)
                    && original == Ty::Unknown
                {
                    // TypeScript narrows `unknown` to `object & Record<key, unknown>`; reads
                    // through it stay checked (`dataGet`), whatever object the value is.
                    let yes = Ty::Obj([(key.clone(), (Ty::Unknown, false))].into_iter().collect());
                    out.push(if negate {
                        (path, Ty::Unknown, yes)
                    } else {
                        (path, yes, Ty::Unknown)
                    });
                } else if let ExprKind::Str(key) = &key.kind
                    && let Some((path, original)) = self.path_ty(object)
                    && let Ty::Union(items) = &original
                {
                    let declares = |item: &Ty| match item {
                        Ty::Obj(fields) => fields.get(key.as_str()).map(|(_, optional)| !optional),
                        Ty::Instance(_, fields) => match &**fields {
                            Ty::Obj(fields) => fields.get(key.as_str()).map(|(_, optional)| !optional),
                            _ => None,
                        },
                        _ => None,
                    };
                    let (mut hit, mut miss) = (Vec::new(), Vec::new());
                    for item in items {
                        match item {
                            Ty::Obj(_) | Ty::Instance(..) => match declares(item) {
                                Some(true) => hit.push(item.clone()),
                                Some(false) => {
                                    hit.push(item.clone());
                                    miss.push(item.clone());
                                }
                                None => miss.push(item.clone()),
                            },
                            other => {
                                hit.push(other.clone());
                                miss.push(other.clone());
                            }
                        }
                    }
                    let (yes, no) = (Ty::union(hit), Ty::union(miss));
                    out.push(if negate { (path, no, yes) } else { (path, yes, no) });
                }
            }
            ExprKind::IsNullish(inner, negated) => {
                if let Some((n, t)) = self.path_ty(inner) {
                    // `x == null` retains each nullish member, including undefined.
                    let is_null_branch = *negated == negate;
                    let (yes, no) = if is_null_branch {
                        (t.nullish(), t.non_null())
                    } else {
                        (t.non_null(), t.nullish())
                    };
                    out.push((n, yes, no));
                }
            }
            ExprKind::Binary(op @ (BinOp::Eq | BinOp::Ne), l, r) => {
                let typed = match (&l.kind, &r.kind) {
                    (ExprKind::Unary(UnOp::TypeOf, value), ExprKind::Str(kind))
                    | (ExprKind::Str(kind), ExprKind::Unary(UnOp::TypeOf, value)) => Some((value, kind)),
                    _ => None,
                };
                if let Some((value, kind)) = typed
                    && let Some(target) = match kind.as_str() {
                        "number" => Some(Ty::Num),
                        "string" => Some(Ty::Str),
                        "boolean" => Some(Ty::Bool),
                        _ => None,
                    }
                    && let Some((path, original)) = self.path_ty(value)
                {
                    let hit = scalar_typeof_part(&original, &target, true);
                    let miss = scalar_typeof_part(&original, &target, false);
                    out.push(if (*op == BinOp::Eq) != negate {
                        (path, hit, miss)
                    } else {
                        (path, miss, hit)
                    });
                    return;
                }
                // `item.layout === "hero"` picks that member of a blocks item union.
                let (member, lit) = match (&l.kind, &r.kind) {
                    (ExprKind::Member { obj, prop, .. }, ExprKind::Str(s)) if prop == "layout" => (Some(obj), s),
                    (ExprKind::Str(s), ExprKind::Member { obj, prop, .. }) if prop == "layout" => (Some(obj), s),
                    _ => (None, &String::new()),
                };
                if let Some(obj) = member
                    && let Some((n, t)) = self.path_ty(obj)
                    && let Ty::Union(items) = t.non_null()
                    && items.iter().any(|i| types::layout_of(i).is_some())
                {
                    let (hit, miss): (Vec<Ty>, Vec<Ty>) = items
                        .iter()
                        .cloned()
                        .partition(|i| types::layout_of(i) == Some(lit.as_str()));
                    let yes_is_hit = (*op == BinOp::Eq) != negate;
                    let (yes, no) = if yes_is_hit {
                        (Ty::union(hit), Ty::union(miss))
                    } else {
                        (Ty::union(miss), Ty::union(hit))
                    };
                    out.push((n, yes, no));
                    return;
                }
                let (subject, other) = match (&l.kind, &r.kind) {
                    (ExprKind::Null | ExprKind::Undefined, _) => (r, l),
                    _ => (l, r),
                };
                if matches!(other.kind, ExprKind::Null | ExprKind::Undefined)
                    && let Some((n, t)) = self.path_ty(subject)
                {
                    let target = if matches!(other.kind, ExprKind::Undefined) {
                        Ty::Undefined
                    } else {
                        Ty::Null
                    };
                    let remaining = match &t {
                        Ty::Union(items) => Ty::union(items.iter().filter(|item| **item != target).cloned()),
                        t if *t == target => Ty::Never,
                        _ => t.clone(),
                    };
                    let is_null_branch = (*op == BinOp::Eq) != negate;
                    let (yes, no) = if is_null_branch {
                        (target, remaining)
                    } else {
                        (remaining, target)
                    };
                    out.push((n, yes, no));
                }
            }
            ExprKind::Logical(op @ (LogOp::And | LogOp::Or), l, r) if (*op == LogOp::And) != negate => {
                let start = out.len();
                self.collect_narrowing(l, negate, out);
                // The right side is checked with the left side's narrowing applied.
                let left: Vec<_> = out[start..].to_vec();
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&left, true);
                self.collect_narrowing(r, negate, out);
                self.fx().scopes.pop();
                let right = out.split_off(start + left.len());
                out.truncate(start);
                out.extend(
                    left.into_iter()
                        .filter(|(name, _, _)| self.refinement_survives(r, name)),
                );
                out.extend(right);
                // Both operands hold only on the conjunction's true side or
                // the disjunction's false side. Keep the other side conservative.
                for entry in &mut out[start..] {
                    if let Some(declared) = self.declared_path_ty(&entry.0) {
                        entry.2 = declared;
                    }
                }
            }
            ExprKind::Logical(LogOp::And | LogOp::Or, _, _) => {
                let start = out.len();
                self.collect_narrowing(test, !negate, out);
                for (_, yes, no) in &mut out[start..] {
                    std::mem::swap(yes, no);
                }
            }
            _ => {}
        }
    }

    fn refinement_survives(&self, expression: &Expr, path: &str) -> bool {
        let mut parts = path.split('.');
        let name = parts.next().expect("path");
        let Some(local) = self.scope_local(name) else {
            return true;
        };
        let view: Vec<_> = parts.map(|part| effects::Step::Field(part.to_string())).collect();
        !self.effects.changes_refinement(expression.span, local.binding, &view)
    }

    /// `ctx.user` → ("ctx.user", its type) for paths rooted at a local.
    fn path_ty(&mut self, e: &Expr) -> Option<(String, Ty)> {
        let path = self.path_of(e)?;
        let saved_tmp = self.fx().tmp;
        let t = self.expr(e).ok().map(|o| o.ty);
        self.fx().tmp = saved_tmp;
        Some((path, t?))
    }

    fn scope_local(&self, name: &str) -> Option<&Local> {
        let mut context = self.fx.as_ref();
        while let Some(fx) = context {
            if let Some(local) = fx.scopes.iter().rev().find_map(|scope| scope.get(name)) {
                return Some(local);
            }
            context = fx.outer.as_deref();
        }
        None
    }

    fn path_of(&self, e: &Expr) -> Option<String> {
        match &e.kind {
            ExprKind::Ident(n) => self.scope_local(n).map(|_| n.clone()),
            ExprKind::Member { obj, prop, .. } => Some(format!("{}.{prop}", self.path_of(obj)?)),
            ExprKind::Chain(inner) | ExprKind::NonNull(inner) => self.path_of(inner),
            _ => None,
        }
    }

    fn native_object_receiver(&self, e: &Expr) -> bool {
        matches!(&e.kind, ExprKind::Ident(name) if self.scope_local(name).is_some_and(|local| local.native_object))
    }

    /// The type a path had before any narrowing in the current branch stack (outermost entry).
    fn declared_path_ty(&self, name: &str) -> Option<Ty> {
        self.fx
            .as_ref()?
            .scopes
            .iter()
            .find_map(|s| s.get(name))
            .or_else(|| self.scope_local(name))
            .map(|l| l.ty.clone())
    }

    /// A narrowed type recorded for a member path (`ctx.user` after `if (ctx.user)`).
    fn narrowed_path(&self, path: &str) -> Option<Ty> {
        self.fx
            .as_ref()?
            .scopes
            .iter()
            .rev()
            .find_map(|s| s.get(path))
            .map(|l| {
                if self.fx.as_ref().unwrap().loop_bindings.contains(&l.binding) {
                    self.effects.read_view_type(l.binding, &l.view, &l.ty)
                } else {
                    l.ty.clone()
                }
            })
    }

    fn apply_narrowing(&mut self, n: &[(String, Ty, Ty)], truthy: bool) {
        for (name, yes, no) in n {
            let t = if truthy { yes.clone() } else { no.clone() };
            if name.contains('.') {
                let mut parts = name.split('.');
                let (binding, mut constraint) = match self.resolve(parts.next().unwrap(), Span::NONE) {
                    Ok(Resolved::Local(local)) => (local.binding, local.constraint),
                    _ => (Span::NONE, t.clone()),
                };
                let mut view = Vec::new();
                for part in parts {
                    constraint = constraint
                        .non_null()
                        .field(part)
                        .map(|(ty, optional)| if optional { Ty::union([ty, Ty::Undefined]) } else { ty })
                        .unwrap_or_else(|| t.clone());
                    view.push(effects::Step::Field(part.to_string()));
                }
                // Member path: a type override read by `member()`, never a PHP variable.
                self.fx().scopes.last_mut().expect("scope").insert(
                    name.clone(),
                    Local {
                        php: String::new(),
                        constraint,
                        ty: t,
                        mutable: false,
                        binding,
                        view,
                        native_object: false,
                        initialized: true,
                    },
                );
                continue;
            }
            let local = match self.resolve(name, Span::NONE) {
                Ok(Resolved::Local(local)) => local,
                _ => continue,
            };
            self.fx()
                .scopes
                .last_mut()
                .expect("scope")
                .insert(name.clone(), Local { ty: t, ..local });
        }
    }

    // ---- expressions ------------------------------------------------------------

    /// A condition: native when the value is boolean, `Js::truthy` otherwise.
    fn cond(&mut self, e: &Expr) -> Result<String> {
        let v = self.expr(e)?;
        let v = self.finish_value(v, e.span)?;
        Ok(self.truthy(&v))
    }

    fn truthy(&self, v: &Out) -> String {
        match &v.ty {
            t if t.is_bool() => v.php.clone(),
            // Objects, arrays, and functions are truthy; both nullish values are falsy.
            t if t.non_null().all(|x| {
                matches!(
                    x,
                    Ty::Obj(_)
                        | Ty::Map(_)
                        | Ty::Array(_)
                        | Ty::Tuple(_)
                        | Ty::ArrayLiteral(_)
                        | Ty::Fn(..)
                        | Ty::Date
                        | Ty::Error
                )
            }) =>
            {
                format!("!\\Zero\\Js::isNullish({})", v.php)
            }
            t if t.is_str() => format!("{} !== ''", v.wrapped()),
            _ => format!("\\Zero\\Js::truthy({})", v.php),
        }
    }

    /// Rejects values that can't exist at runtime as plain data (unawaited calls, builders).
    fn finish_value(&mut self, v: Out, span: Span) -> Result<Out> {
        if self.constructing_class() && matches!(v.ty.non_null(), Ty::Instance(..)) {
            return err_help(
                span,
                "instances cannot escape during construction",
                "assign fields through this; pass or capture the instance after new returns",
            );
        }
        match &v.ty {
            Ty::Class(_) => err(
                span,
                "class values can only be used with new, instanceof, typeof, or static member access",
            ),
            Ty::Builder(_) => err_help(span, "this database call never runs", "add `await` in front of it"),
            Ty::Promise(_) => err_help(
                span,
                "this async call isn't awaited",
                "add `await`; Zero runs async calls in order",
            ),
            Ty::Db | Ty::DbQuery | Ty::QueryColl(_) | Ty::DbGlobals | Ty::DbGlobal(_) => {
                err(span, "`db` can't be stored in a variable; call it directly")
            }
            Ty::Collection(_) => err_help(
                span,
                "collections can't be used as values in hooks",
                "pass the collection to db.insert/update/delete",
            ),
            _ => Ok(v),
        }
    }

    fn expr(&mut self, e: &Expr) -> Result<Out> {
        let span = e.span;
        let out = match &e.kind {
            ExprKind::Class(_) => return err(span, "class declarations must be at module scope"),
            ExprKind::Instanceof(left, right) => {
                // Host classes Zero implements answer `instanceof` from imported JavaScript.
                if let ExprKind::Ident(name) = &right.kind
                    && matches!(
                        name.as_str(),
                        "Promise" | "ReadableStream" | "WritableStream" | "TransformStream"
                    )
                    && self.javascript_module(self.fx.as_ref().expect("fn").module)
                    && matches!(self.resolve(name, right.span)?, Resolved::Global(_))
                {
                    let left = self.expr(left)?;
                    let class = match name.as_str() {
                        "Promise" => "JsPromise",
                        "ReadableStream" => "JsReadableStream",
                        "WritableStream" => "JsWritableStream",
                        _ => "JsTransformStream",
                    };
                    return Ok(Out::atom(
                        format!("({} instanceof \\Zero\\{class})", left.php),
                        Ty::Bool,
                    ));
                }
                if let ExprKind::Ident(name) = &right.kind
                    && let Resolved::Global(g @ ("Error" | "TypeError" | "RangeError")) =
                        self.resolve(name, right.span)?
                {
                    let left = self.expr(left)?;
                    return Ok(Out::atom(
                        format!("\\Zero\\Js::isError({}, {})", left.php, php_str(g)),
                        Ty::Bool,
                    ));
                }
                let left = self.expr(left)?;
                let right = self.expr(right)?;
                if right.ty == Ty::Dynamic {
                    return Ok(Out::atom(
                        format!("\\Zero\\Js::dynamicInstanceof({}, {})", left.php, right.php),
                        Ty::Bool,
                    ));
                }
                if !matches!(right.ty, Ty::Class(_)) {
                    return err(span, "instanceof requires a named Zero class");
                }
                Out::atom(
                    format!("\\Zero\\JsClass::isInstance({}, {})", left.php, right.php),
                    Ty::Bool,
                )
            }
            ExprKind::Delete(target) => self.delete(target, span)?,
            // A tagged template's strings: one frozen-in-spirit array per call site with a `raw`
            // property. Cached per site, as JavaScript reuses the same array for every call.
            ExprKind::TemplateStrings { cooked, raw } => {
                let items = cooked
                    .iter()
                    .map(|c| c.as_deref().map_or("\\Zero\\Js::undefined()".to_string(), php_str))
                    .collect::<Vec<_>>()
                    .join(", ");
                let raw = raw.iter().map(|r| php_str(r)).collect::<Vec<_>>().join(", ");
                // The site key keeps one array per call site across calls, as in JavaScript.
                let site = format!("{}:{}:{}", span.file, span.start, span.end);
                Out::atom(
                    format!("\\Zero\\Js::templateStrings({}, [{items}], [{raw}])", php_str(&site)),
                    // TypeScript types every cooked string as `string`, so a TemplateStringsArray
                    // tag still accepts an invalid escape; its element is undefined at runtime.
                    Ty::NonEmptyArray(Box::new(Ty::Str)),
                )
            }
            ExprKind::AssignPattern(pattern, value) => self.assign_pattern(pattern, value)?,
            ExprKind::In(key, object) => {
                let key = self.expr(key)?;
                let key = self.finish_value(key, span)?;
                let object = self.expr(object)?;
                let object = if matches!(object.ty, Ty::Class(_)) {
                    object
                } else {
                    self.finish_value(object, span)?
                };
                // Js::hasProperty: own properties, then the value's fixed prototype chain.
                Out::atom(
                    format!("\\Zero\\Js::hasProperty({}, {})", key.php, object.php),
                    Ty::Bool,
                )
            }
            ExprKind::Num(n) => Out::atom(php_number(*n), Ty::FreshNumLit(*n)),
            ExprKind::BigInt(digits) => {
                Out::atom(format!("\\Zero\\JsBigInt::literal({})", php_str(digits)), Ty::BigInt)
            }
            ExprKind::Str(s) => Out::atom(php_str(s), Ty::FreshStrLit(s.clone())),
            ExprKind::Bool(b) => Out::atom(b.to_string(), Ty::FreshBoolLit(*b)),
            ExprKind::Undefined => Out::atom("\\Zero\\Js::undefined()", Ty::Undefined),
            ExprKind::Null => Out::atom("null", Ty::Null),
            ExprKind::Regex { pattern, flags } => {
                crate::builtins::check_regex(pattern, flags, span)?;
                let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                Out::atom(
                    if javascript {
                        format!("new \\Zero\\RegExp({}, {})", php_str(pattern), php_str(flags))
                    } else {
                        format!("\\Zero\\Js::re({}, {})", php_str(pattern), php_str(flags))
                    },
                    if javascript { Ty::Dynamic } else { Ty::Regex },
                )
            }
            ExprKind::Template(quasis, exprs) => {
                if exprs.is_empty() {
                    return Ok(Out::atom(php_str(&quasis[0]), Ty::FreshStrLit(quasis[0].clone())));
                }
                let mut parts = Vec::new();
                if !quasis[0].is_empty() {
                    parts.push(php_str(&quasis[0]));
                }
                for (i, x) in exprs.iter().enumerate() {
                    let v = self.expr(x)?;
                    let v = self.finish_value(v, x.span)?;
                    parts.push(self.to_string_php(&v, x.span)?);
                    if !quasis[i + 1].is_empty() {
                        parts.push(php_str(&quasis[i + 1]));
                    }
                }
                if parts.is_empty() {
                    Out::atom("''", Ty::Str)
                } else {
                    Out::atom(format!("implode('', [{}])", parts.join(", ")), Ty::Str)
                }
            }
            ExprKind::Sequence(items) => {
                let mut values = Vec::new();
                let mut ty = Ty::Null;
                for item in items {
                    let value = self.expr(item)?;
                    ty = value.ty;
                    values.push(value.php);
                }
                let last = values.pop().unwrap_or_else(|| "null".into());
                Out::new(Self::sequence(values, last), ty)
            }
            ExprKind::Ident(n) => self.ident(n, span)?,
            ExprKind::Array(items) => {
                let mut parts = Vec::new();
                let mut tys = Vec::new();
                for item in items {
                    match item {
                        ArrEl::Item(x) => {
                            let v = self.expr(x)?;
                            let v = self.finish_value(v, x.span)?;
                            tys.push(v.ty.clone());
                            parts.push(v.php);
                        }
                        ArrEl::Spread(x) => {
                            let v = self.expr(x)?;
                            match v.ty.non_null() {
                                Ty::Dynamic => {
                                    tys.push(Ty::Dynamic);
                                    parts.push(format!("...(\\Zero\\Js::dynamicIterable({}))->a", v.php));
                                    continue;
                                }
                                Ty::Array(t) | Ty::NonEmptyArray(t) => tys.push(*t),
                                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => tys.extend(ts),
                                t @ (Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_) | Ty::UrlParams) => {
                                    if v.ty.is_nullable() {
                                        return err(
                                            x.span,
                                            format!("can't spread {}: it might be null", v.ty.describe()),
                                        );
                                    }
                                    tys.push(self.iterable_element(&t).expect("collection element"));
                                    parts.push(format!("...(\\Zero\\Js::iterArray({}))->a", v.php));
                                    continue;
                                }
                                other => {
                                    return err(x.span, format!("can't spread {} into an array", other.describe()));
                                }
                            }
                            parts.push(format!("...({})->a", v.php));
                        }
                    }
                }
                let ty = if items.iter().all(|i| matches!(i, ArrEl::Item(_))) && !tys.is_empty() && tys.len() <= 8 {
                    Ty::ArrayLiteral(tys)
                } else {
                    Ty::Array(Box::new(Ty::union(tys)))
                };
                Out::atom(format!("(new \\Zero\\JsArray([{}]))", parts.join(", ")), ty)
            }
            ExprKind::Object(props)
                if self.javascript_module(self.fx.as_ref().expect("fn").module)
                    && props.iter().any(|p| !matches!(p, Prop::KV(PropKey::Static(_), _))) =>
            {
                self.javascript_object(props)?
            }
            ExprKind::Object(props) => {
                let mut parts = Vec::new();
                let mut fields: IndexMap<String, (Ty, bool)> = IndexMap::new();
                let mut map_values = Vec::new();
                let mut is_map = false;
                for p in props {
                    match p {
                        Prop::KV(key, value) => {
                            let (key_php, name) = match key {
                                PropKey::Static(k) => (php_str(k), Some(k)),
                                PropKey::Computed(kx) => {
                                    let k = self.expr(kx)?;
                                    if !k.ty.all(|t| t.is_str() || t.is_num() || t.is_bool() || *t == Ty::Null) {
                                        return err(
                                            kx.span,
                                            format!("computed key type is not supported: {}", k.ty.describe()),
                                        );
                                    }
                                    is_map = true;
                                    (format!("\\Zero\\Js::key({})", k.php), None)
                                }
                            };
                            let v = self.expr(value)?;
                            let v = self.finish_value(v, value.span)?;
                            if let Some(k) = name {
                                fields.shift_remove(k);
                                fields.insert(k.clone(), (v.ty.clone(), false));
                            } else {
                                map_values.push(v.ty.clone());
                            }
                            parts.push(format!("[{key_php} => {}]", v.php));
                        }
                        Prop::Spread(x) => {
                            let v = self.expr(x)?;
                            let shape = match v.ty.non_null() {
                                Ty::Instance(_, fields) => *fields,
                                ty => ty,
                            };
                            match shape {
                                Ty::Obj(f) => {
                                    for (k, t) in f {
                                        fields.shift_remove(&k);
                                        fields.insert(k, t);
                                    }
                                }
                                Ty::Map(t) => {
                                    is_map = true;
                                    map_values.push(*t);
                                }
                                other => {
                                    return err(x.span, format!("can't spread {} into an object", other.describe()));
                                }
                            }
                            parts.push(format!("({})->p ?? []", v.wrapped()));
                        }
                    }
                }
                let ty = if is_map {
                    Ty::Map(Box::new(Ty::union(
                        fields.values().map(|(t, _)| t.clone()).chain(map_values),
                    )))
                } else {
                    Ty::Obj(fields)
                };
                let php = if props.iter().all(|p| matches!(p, Prop::KV(PropKey::Static(_), _))) {
                    let fields = parts.iter().map(|p| &p[1..p.len() - 1]).collect::<Vec<_>>().join(", ");
                    format!("(new \\Zero\\JsObject([{fields}]))")
                } else {
                    format!("\\Zero\\Js::obj({})", parts.join(", "))
                };
                Out::atom(php, ty)
            }
            ExprKind::Func(f) => self.closure_expr(f)?,
            ExprKind::Unary(op, x) => {
                if *op == UnOp::TypeOf
                    && let Some(kind) = self.global_type_of(x)?
                {
                    return Ok(Out::atom(php_str(kind), Ty::StrLit(kind.into())));
                }
                let v = self.expr(x)?;
                let v = if matches!(op, UnOp::TypeOf) && matches!(v.ty, Ty::Class(_)) {
                    v
                } else {
                    self.finish_value(v, x.span)?
                };
                match op {
                    UnOp::Not => {
                        let ty = match (truthiness_part(&v.ty, true), truthiness_part(&v.ty, false)) {
                            (Ty::Never, Ty::Never) => Ty::Never,
                            (Ty::Never, _) => Ty::FreshBoolLit(true),
                            (_, Ty::Never) => Ty::FreshBoolLit(false),
                            _ => Ty::Bool,
                        };
                        Out::new(format!("!{}", paren(&self.truthy(&v))), ty)
                    }
                    UnOp::Neg if v.ty == Ty::BigInt => Out::atom(format!("\\Zero\\Js::neg({})", v.php), Ty::BigInt),
                    UnOp::BitNot if v.ty == Ty::BigInt => {
                        Out::atom(format!("\\Zero\\Js::bitNot({})", v.php), Ty::BigInt)
                    }
                    UnOp::Plus if v.ty == Ty::BigInt => {
                        return err_help(
                            x.span,
                            "unary `+` on a bigint throws a TypeError",
                            "use Number(b) to convert",
                        );
                    }
                    UnOp::Neg => {
                        if v.ty != Ty::Dynamic {
                            self.need_num(&v, x.span, "`-`")?;
                        }
                        match v.ty {
                            Ty::NumLit(n) | Ty::FreshNumLit(n) if matches!(x.kind, ExprKind::Num(_)) => {
                                Out::atom(php_number(-n), Ty::FreshNumLit(-n))
                            }
                            // Js::neg: the operand as a JS number, like the binary operators (or a
                            // BigInt, which checked JavaScript can't rule out).
                            _ => Out::atom(
                                format!("\\Zero\\Js::neg({})", v.php),
                                if v.ty == Ty::Dynamic { Ty::Dynamic } else { Ty::Num },
                            ),
                        }
                    }
                    UnOp::BitNot => {
                        if v.ty != Ty::Dynamic {
                            self.need_num(&v, x.span, "`~`")?;
                        }
                        Out::atom(
                            format!("\\Zero\\Js::bitNot({})", v.php),
                            if v.ty == Ty::Dynamic { Ty::Dynamic } else { Ty::Num },
                        )
                    }
                    UnOp::Plus => {
                        if v.ty.is_num() {
                            v
                        } else {
                            Out::atom(format!("\\Zero\\Js::toNumber({})", v.php), Ty::Num)
                        }
                    }
                    UnOp::TypeOf if v.ty == Ty::Undefined => {
                        Out::atom(Self::sequence(vec![v.php], php_str("undefined")), Ty::Str)
                    }
                    UnOp::TypeOf => Out::atom(format!("\\Zero\\Js::typeOf({})", v.php), Ty::Str),
                }
            }
            ExprKind::Binary(op, l, r) => {
                let a = self.expr(l)?;
                let a = self.finish_value(a, l.span)?;
                let b = self.expr(r)?;
                let b = self.finish_value(b, r.span)?;
                if *op == BinOp::Add
                    && let Some(out) = self.unknown_plus_unchecked_text(&a, l, &b, r)
                {
                    return Ok(out);
                }
                let container = |ty: &Ty| {
                    ty.all(|t| {
                        matches!(
                            t,
                            Ty::Obj(_)
                                | Ty::Map(_)
                                | Ty::Array(_)
                                | Ty::NonEmptyArray(_)
                                | Ty::Tuple(_)
                                | Ty::ArrayLiteral(_)
                        )
                    })
                };
                if matches!(op, BinOp::Eq | BinOp::Ne) && container(&a.ty) && container(&b.ty) && pure_read(r) {
                    Out::new(
                        format!(
                            "{} {} {}",
                            a.wrapped(),
                            if *op == BinOp::Eq { "===" } else { "!==" },
                            b.wrapped()
                        ),
                        Ty::Bool,
                    )
                } else {
                    self.binary(*op, a, b, span)?
                }
            }
            ExprKind::IsNullish(x, negated) => {
                let v = self.expr(x)?;
                Out::atom(
                    format!("{}\\Zero\\Js::isNullish({})", if *negated { "!" } else { "" }, v.php),
                    Ty::Bool,
                )
            }
            ExprKind::Logical(op, l, r) => {
                let a = self.expr(l)?;
                let a = self.finish_value(a, l.span)?;
                // Narrow the right side by the left (`x && x.y`).
                let narrow = self.narrowing(l);
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, *op == LogOp::And);
                let b = self.expr(r);
                self.fx().scopes.pop();
                let b = b?;
                let b = self.finish_value(b, r.span)?;
                let ty = logical_result_type(*op, &a.ty, &b.ty);
                match op {
                    LogOp::Nullish => {
                        let tmp = self.tmp_var();
                        Out::new(
                            format!("(\\Zero\\Js::isNullish({tmp} = {}) ? {} : {tmp})", a.php, b.php),
                            ty,
                        )
                    }
                    LogOp::Or | LogOp::And => {
                        if a.ty.is_bool() && b.ty.is_bool() {
                            return Ok(Out::new(
                                format!(
                                    "{} {} {}",
                                    a.wrapped(),
                                    if *op == LogOp::And { "&&" } else { "||" },
                                    b.wrapped()
                                ),
                                ty,
                            ));
                        }
                        // `x || y` / `x && y` return an operand, not a boolean.
                        let tmp = self.tmp_var();
                        let test = self.truthy(&Out::new(format!("{tmp} = {}", a.php), a.ty.clone()));
                        let (yes, no) = if *op == LogOp::Or {
                            (tmp.clone(), b.wrapped())
                        } else {
                            (b.wrapped(), tmp.clone())
                        };
                        Out::new(format!("{} ? {yes} : {no}", paren(&test)), ty)
                    }
                }
            }
            ExprKind::Cond(t, a, b) => {
                let narrow = self.narrowing(t);
                let tc = self.cond(t)?;
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, true);
                let av = self.expr(a);
                self.fx().scopes.pop();
                let av = self.finish_value(av?, a.span)?;
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, false);
                let bv = self.expr(b);
                self.fx().scopes.pop();
                let bv = self.finish_value(bv?, b.span)?;
                Out::new(
                    format!("{} ? {} : {}", paren(&tc), av.wrapped(), bv.wrapped()),
                    Ty::union([av.ty, bv.ty]),
                )
            }
            ExprKind::Assign { op, target, value } => self.assign(*op, target, value, span, false)?,
            ExprKind::Update { inc, prefix, target } => self.update(target, *inc, *prefix)?,
            ExprKind::Call {
                callee, args, optional, ..
            } => self.call(callee, args, *optional, span)?,
            ExprKind::New {
                callee,
                args,
                type_args,
            } => self.new_expr(callee, args, type_args, span)?,
            ExprKind::Member {
                obj,
                prop,
                optional,
                prop_span,
            } => self.member(obj, prop, *optional, *prop_span, span)?,
            ExprKind::Index { obj, index, optional } => self.index(obj, index, *optional, span)?,
            ExprKind::Chain(inner) => {
                check_optional_chain(inner)?;
                self.expr(inner)?
            }
            ExprKind::Await(x) => self.await_expr(x, span)?,
            ExprKind::Yield { arg, delegate } => self.yield_expr(arg.as_deref(), *delegate, span)?,
            ExprKind::As(x, ann) => {
                let v = self.expr(x)?;
                let module = self.fx().module;
                let t = self.resolve_runtime_ann(ann, module)?;
                if classes::wire_type(&t) != t && !assignable(&v.ty, &t) {
                    return err(
                        span,
                        "a class instance must come from new; assertions cannot restore class identity",
                    );
                }
                // `b as unknown` and `x as bigint` are TypeScript's escape hatches; a value asserted
                // away from bigint is still a BigInt at runtime, so it stays on the checked path.
                if v.ty == Ty::BigInt && t == Ty::Unknown {
                    return Ok(Out { ty: Ty::Dynamic, ..v });
                }
                if !(assignable(&v.ty, &t) || assignable(&t, &v.ty) || v.ty == Ty::Unknown || v.ty == Ty::Dynamic) {
                    return err(span, format!("can't treat {} as {}", v.ty.describe(), t.describe()));
                }
                if v.ty == Ty::Dynamic {
                    return Ok(v);
                }
                Out { ty: t, ..v }
            }
            ExprKind::NonNull(x) => {
                let v = self.expr(x)?;
                let t = v.ty.non_null();
                Out { ty: t, ..v }
            }
        };
        let database_receiver = match &out.ty {
            Ty::Db | Ty::DbQuery | Ty::QueryColl(_) | Ty::DbGlobals | Ty::DbGlobal(_) => Some("\\Zero\\db()"),
            Ty::Builder(_) => Some(""),
            _ => None,
        };
        if let Some(expected) = database_receiver
            && out.php != expected
        {
            return err_help(
                span,
                "unsupported database receiver expression",
                "call database operations directly; evaluate side effects in a preceding statement",
            );
        }
        if out.ty == Ty::Never {
            self.fx().diverging.insert(span);
        }
        self.apply_effects(span);
        Ok(out)
    }

    fn apply_effects(&mut self, span: Span) {
        // A closure's body is compiled now, but its captured writes occur at
        // its call sites. Do not apply them to the enclosing function here.
        for local in self
            .fx
            .as_mut()
            .expect("fn")
            .scopes
            .iter_mut()
            .flat_map(|scope| scope.values_mut())
        {
            local.ty = self
                .effects
                .invalidate(span, local.binding, &local.view, &local.ty, &local.constraint);
            local.ty = self.effects.write_type(span, local.binding, &local.view, &local.ty);
        }
    }

    fn need_num(&self, v: &Out, span: Span, what: &str) -> Result<()> {
        if v.ty.is_num() {
            Ok(())
        } else if v.ty.non_null().is_num() {
            err_help(
                span,
                format!("{what} needs a number, but this might be null"),
                "check it first, or use `?? 0`",
            )
        } else {
            err(span, format!("{what} needs a number, not {}", v.ty.describe()))
        }
    }

    /// The value as a PHP string expression, safe to join with `.`.
    fn to_string_php(&self, v: &Out, span: Span) -> Result<String> {
        crate::builtins::check_string_coercion_type(&v.ty, span)?;
        Ok(match &v.ty {
            t if t.is_str() => v.wrapped(),
            Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_) => format!("\\Zero\\Js::numStr({})", v.php),
            Ty::BigInt => format!("(string) ({})", v.php),
            Ty::Builder(_) | Ty::Promise(_) => return err(span, "this value needs `await` first"),
            _ => format!("\\Zero\\Js::str({})", v.php),
        })
    }

    /// Preserve missing string elements as undefined during checked addition.
    /// Unlike ordinary text concatenation, a missing element can produce NaN.
    fn unknown_plus_unchecked_text(&mut self, a: &Out, l: &Expr, b: &Out, r: &Expr) -> Option<Out> {
        let read = |out: &Out, e: &Expr, this: &mut Self| -> Option<String> {
            let ExprKind::Index {
                obj, optional: false, ..
            } = &e.kind
            else {
                return None;
            };
            let (Ty::Array(element) | Ty::NonEmptyArray(element)) = this.argument_constraint(obj)?.non_null() else {
                return None;
            };
            (element.is_str() && out.ty.is_nullable() && out.ty.non_null().is_str())
                .then(|| format!("({} ?? \\Zero\\Js::undefined())", out.php))
        };
        let (left, right) = match (&a.ty, &b.ty) {
            (Ty::Unknown, _) => (a.php.clone(), read(b, r, self)?),
            (_, Ty::Unknown) => (read(a, l, self)?, b.php.clone()),
            _ => return None,
        };
        Some(Out::atom(
            format!("\\Zero\\Js::dynamicBinary('+', {left}, {right})"),
            Ty::union([Ty::Str, Ty::Num]),
        ))
    }

    fn binary(&mut self, op: BinOp, a: Out, b: Out, span: Span) -> Result<Out> {
        let bigint = |t: &Ty| *t == Ty::BigInt;
        if bigint(&a.ty) || bigint(&b.ty) {
            return self.bigint_binary(op, a, b, span);
        }
        if op == BinOp::Add {
            crate::builtins::check_string_coercion_type(&a.ty, span)?;
            crate::builtins::check_string_coercion_type(&b.ty, span)?;
        }
        if a.ty == Ty::Dynamic || b.ty == Ty::Dynamic || matches!(op, BinOp::LooseEq | BinOp::LooseNe) {
            return self.javascript_binary(op, a, b, span);
        }
        Ok(match op {
            BinOp::Add => {
                if a.ty.is_num() && b.ty.is_num() {
                    // Js::add: operands and result as JS numbers (exact ints past ±2^53 round, as in JS).
                    Out::atom(format!("\\Zero\\Js::add({}, {})", a.php, b.php), Ty::Num)
                } else if a.ty.is_str() || b.ty.is_str() {
                    Out::atom(format!("\\Zero\\Js::dynamicBinary('+', {}, {})", a.php, b.php), Ty::Str)
                } else {
                    return err_help(
                        span,
                        format!("`+` between {} and {} is ambiguous", a.ty.describe(), b.ty.describe()),
                        "make both sides numbers, or use a template literal for text",
                    );
                }
            }
            BinOp::Sub | BinOp::Mul | BinOp::Div | BinOp::Rem | BinOp::Exp => {
                let sym = match op {
                    BinOp::Sub => "-",
                    BinOp::Mul => "*",
                    BinOp::Div => "/",
                    BinOp::Rem => "%",
                    _ => "**",
                };
                self.need_num(&a, span, &format!("`{sym}`"))?;
                self.need_num(&b, span, &format!("`{sym}`"))?;
                if op == BinOp::Exp {
                    let literal = |ty: &Ty| match ty {
                        Ty::NumLit(n) | Ty::FreshNumLit(n) => Some(*n),
                        _ => None,
                    };
                    if let (Some(a), Some(b)) = (literal(&a.ty), literal(&b.ty)) {
                        crate::builtins::check_power(a, b, span)?;
                    }
                }
                match op {
                    BinOp::Div => Out::atom(format!("fdiv({}, {})", a.php, b.php), Ty::Num),
                    BinOp::Rem => Out::atom(format!("fmod({}, {})", a.php, b.php), Ty::Num),
                    BinOp::Exp => Out::atom(format!("\\Zero\\Js::pow({}, {})", a.php, b.php), Ty::Num),
                    BinOp::Sub => Out::atom(format!("\\Zero\\Js::sub({}, {})", a.php, b.php), Ty::Num),
                    _ => Out::atom(format!("\\Zero\\Js::mul({}, {})", a.php, b.php), Ty::Num),
                }
            }
            BinOp::Lt | BinOp::Le | BinOp::Gt | BinOp::Ge => {
                let sym = match op {
                    BinOp::Lt => "<",
                    BinOp::Le => "<=",
                    BinOp::Gt => ">",
                    _ => ">=",
                };
                if a.ty.is_num() && b.ty.is_num() {
                    self.javascript_binary(op, a, b, span)?
                } else if a.ty.is_str() && b.ty.is_str() {
                    Out::new(
                        format!("\\Zero\\Js::stringCompare({}, {}) {sym} 0", a.php, b.php),
                        Ty::Bool,
                    )
                } else {
                    return err(
                        span,
                        format!(
                            "can't compare {} with {} using `{sym}`",
                            a.ty.describe(),
                            b.ty.describe()
                        ),
                    );
                }
            }
            BinOp::Eq | BinOp::Ne => {
                let eq = self.strict_eq(&a, &b);
                if op == BinOp::Eq {
                    Out::new(eq, Ty::Bool)
                } else {
                    Out::new(format!("!({eq})"), Ty::Bool)
                }
            }
            BinOp::LooseEq | BinOp::LooseNe => unreachable!("loose equality uses checked JavaScript dispatch"),
            BinOp::BitAnd | BinOp::BitOr | BinOp::BitXor | BinOp::Shl | BinOp::Shr | BinOp::UShr => {
                let sym = op.bitwise_symbol().expect("bitwise operator");
                self.need_num(&a, span, &format!("`{sym}`"))?;
                self.need_num(&b, span, &format!("`{sym}`"))?;
                // Js::bitwise: ToInt32/ToUint32 on JS numbers, a signed or (`>>>`) unsigned 32-bit result.
                Out::atom(
                    format!("\\Zero\\Js::bitwise({}, {}, {})", php_str(sym), a.php, b.php),
                    Ty::Num,
                )
            }
        })
    }

    /// An operator with a BigInt operand. Arithmetic, shifts and bitwise operators need both sides
    /// BigInt (JavaScript throws TypeError on a mix); comparisons and equality compare
    /// mathematically across BigInt and number, as JavaScript does. `+` with a string concatenates.
    fn bigint_binary(&mut self, op: BinOp, a: Out, b: Out, span: Span) -> Result<Out> {
        // Checked JavaScript can't rule out a BigInt in a dynamic operand; the runtime decides.
        let both = matches!(a.ty, Ty::BigInt | Ty::Dynamic) && matches!(b.ty, Ty::BigInt | Ty::Dynamic);
        let symbol = match op {
            BinOp::Add => "+",
            BinOp::Sub => "-",
            BinOp::Mul => "*",
            BinOp::Div => "/",
            BinOp::Rem => "%",
            BinOp::Exp => "**",
            BinOp::Lt => "<",
            BinOp::Le => "<=",
            BinOp::Gt => ">",
            BinOp::Ge => ">=",
            BinOp::Eq => "===",
            BinOp::Ne => "!==",
            BinOp::LooseEq => "==",
            BinOp::LooseNe => "!=",
            BinOp::UShr => {
                return err_help(
                    span,
                    "BigInts have no unsigned right shift",
                    "use `>>`, as JavaScript requires",
                );
            }
            other => other.bitwise_symbol().expect("bitwise operator"),
        };
        let result = match op {
            BinOp::Lt | BinOp::Le | BinOp::Gt | BinOp::Ge | BinOp::Eq | BinOp::Ne | BinOp::LooseEq | BinOp::LooseNe => {
                Ty::Bool
            }
            BinOp::Add if a.ty.is_str() || b.ty.is_str() => Ty::Str,
            _ if !both => {
                // A static mix is a TypeError in JavaScript, so it is refused at the source.
                return err_help(
                    span,
                    format!(
                        "can't mix bigint and {} in `{symbol}`",
                        if a.ty == Ty::BigInt {
                            b.ty.describe()
                        } else {
                            a.ty.describe()
                        }
                    ),
                    "convert explicitly with BigInt(n) or Number(b), as JavaScript requires",
                );
            }
            _ => Ty::BigInt,
        };
        Ok(Out::atom(
            format!("\\Zero\\Js::dynamicBinary({}, {}, {})", php_str(symbol), a.php, b.php),
            result,
        ))
    }

    fn strict_eq(&self, a: &Out, b: &Out) -> String {
        format!("\\Zero\\Js::strictEq({}, {})", a.php, b.php)
    }

    fn ident(&mut self, n: &str, span: Span) -> Result<Out> {
        match self.resolve(n, span)? {
            Resolved::Local(l) => Ok(Out {
                php: l.php.clone(),
                ty: if self.fx().loop_bindings.contains(&l.binding) {
                    self.effects.read_view_type(l.binding, &l.view, &l.ty)
                } else {
                    l.ty
                },
                place: Some(l.php),
                atomic: true,
                helper: None,
            }),
            Resolved::Value(v, b) => self.captured(n, v, &b, span),
            Resolved::Global("NaN") => Ok(Out::atom("NAN", Ty::Num)),
            Resolved::Global("Infinity") => Ok(Out::atom("INF", Ty::Num)),
            Resolved::Global("Math") => Ok(Out::atom("\\Zero\\Js::dynamicNamespace('Math')", Ty::Dynamic)),
            Resolved::Global("AbortSignal") if self.javascript_module(self.fx.as_ref().expect("fn").module) => {
                Ok(Out::atom("\\Zero\\Js::dynamicNamespace('AbortSignal')", Ty::Dynamic))
            }
            Resolved::Global(g) => err_help(
                span,
                format!("`{g}` can't be used as a value; call one of its functions"),
                "use supported built-in calls or primitive values; host-global detection isn't supported",
            ),
        }
    }

    /// A name captured from the config's build-time scope.
    fn captured(&mut self, n: &str, v: Value, b: &Binding, span: Span) -> Result<Out> {
        self.captured_use(n, v, b, span, false, false)
    }

    /// `callee`: the value is called directly, so a function's own properties don't matter.
    /// `member`: the value was read through a static member chain (`ns.a.b`), so its type
    /// is the widened property type rather than a `const` binding's literal.
    fn captured_use(&mut self, n: &str, v: Value, b: &Binding, span: Span, callee: bool, member: bool) -> Result<Out> {
        if b.mutable && b.module_level && !self.javascript_module(self.fx.as_ref().expect("fn").module) {
            return err_help(
                span,
                format!("hooks can't read `{n}`: it's a module-level `let`"),
                "make it a `const`; each request starts fresh in PHP",
            );
        }
        match &v {
            Value::Class(class) => self.class_value(class),
            Value::Closure(c) if c.has_props() && !callee => err_help(
                span,
                format!("`{n}` is a function with properties, which can't be used as a value in PHP"),
                format!(
                    "call it (`{n}(...)`) or read its properties directly (`{n}.{}`); a PHP closure can't carry properties",
                    c.props.borrow().keys().next().map_or("prop", String::as_str)
                ),
            ),
            Value::Closure(c) => {
                let key = closure_key(c);
                let helper_key = (self.owner(), key, self.fx().no_db);
                let name = match self.helpers.get(&helper_key) {
                    Some(n) => n.clone(),
                    None => {
                        let name = self.unique_name(c.func.name.as_deref().unwrap_or(n));
                        self.helpers.insert(helper_key, name.clone());
                        self.compile_helper_now(c, &name)?;
                        name
                    }
                };
                let caller = self.current.clone();
                self.calls.entry(caller).or_default().insert(name.clone());
                let Some(ty) = self.helper_types.get(&key).cloned() else {
                    return err_help(
                        span,
                        format!("`{n}` calls itself, so it needs a return type"),
                        format!("annotate it: `function {n}(...): number`"),
                    );
                };
                let wrapper = match self.helper_values.get(&(self.owner(), key)) {
                    Some(wrapper) => wrapper.clone(),
                    None => {
                        let wrapper = self.unique_name(&format!("{name}_value"));
                        self.helper_values.insert((self.owner(), key), wrapper.clone());
                        let _ = writeln!(
                            self.output,
                            "function {wrapper}(): \\Closure {{ static $f; return $f ??= \\Closure::fromCallable('\\\\ZeroApp\\\\{name}'); }}\n"
                        );
                        wrapper
                    }
                };
                let mut out = Out::atom(
                    format!("\\ZeroApp\\{wrapper}()"),
                    if self.javascript_module(c.func.module) {
                        Ty::Dynamic
                    } else {
                        ty
                    },
                );
                out.helper = Some(format!("\\ZeroApp\\{name}"));
                Ok(out)
            }
            Value::Collection(c) => {
                let t = self.table_of(c, span)?;
                Ok(Out::atom(php_str(&t), Ty::Collection(t)))
            }
            Value::Builtin(Builtin::Db) => {
                if self.fx().no_db {
                    return Err(Diag::new(span, "access rules can't query; put that in a hook")
                        .code(crate::diag::Code::AccessQuery)
                        .help("return a filter instead, e.g. `{ ownerId: ctx.user?.id ?? -1 }`"));
                }
                Ok(Out::atom("\\Zero\\db()", Ty::Db))
            }
            Value::Env(name) => {
                self.uses("env", name.to_string(), span);
                Ok(Out::atom(
                    format!("\\Zero\\Env::get({})", php_str(name)),
                    Ty::Str.nullable(),
                ))
            }
            Value::Builtin(Builtin::Env) => err_help(
                span,
                "env() is a config reference for webhooks, signatures and upload credentials",
                "read runtime variables in handlers with `process.env.NAME`",
            ),
            Value::Builtin(Builtin::Once) => err_help(
                span,
                format!("`{n}` can't be used as a value"),
                format!("call it directly: `{n}(...)`"),
            ),
            Value::Builtin(Builtin::NodeCrypto(_)) => err_help(
                span,
                format!("`{n}` from \"node:crypto\" can't be used as a value"),
                "call it where it's used, e.g. `createHash(\"sha256\").update(text).digest(\"hex\")`",
            ),
            Value::Builtin(Builtin::Mail) => err_help(
                span,
                "`mail` can't be used as a value",
                "call `mail.send({ to, subject, text })` directly",
            ),
            Value::Builtin(Builtin::WpFunction(name)) => err_help(
                span,
                format!("`{name}` from \"php/wordpress\" can't be used as a value"),
                format!("call it directly: `{name}(...)`"),
            ),
            Value::Builtin(Builtin::Abilities) => err_help(
                span,
                "`abilities` can't be used as a value",
                "call `abilities.call(\"vendor/name\", input)` directly",
            ),
            Value::Builtin(Builtin::Jobs) => err_help(
                span,
                "`jobs` can't be used as a value",
                "call `jobs.<name>.enqueue(input)` directly",
            ),
            Value::Builtin(Builtin::HttpError) => err(span, "use `throw new HttpError(status, code, message)`"),
            Value::Builtin(Builtin::Static("Number", method)) if method.as_ref() == "isFinite" => {
                Ok(number_is_finite_function())
            }
            Value::Builtin(_)
            | Value::Column(_)
            | Value::ColRef(_)
            | Value::Relation(_)
            | Value::Action(_)
            | Value::Job(_)
            | Value::Route(_)
            | Value::Plugin(_)
            | Value::FileBucket(_)
            | Value::Bound(..) => err(
                span,
                format!("`{n}` is {}, which only exists while the config is built", v.describe()),
            ),
            Value::Coll(c) => {
                let key = (self.owner(), 3, Rc::as_ptr(c) as *const () as usize);
                let name = if let Some(name) = self.data_helpers.get(&key) {
                    name.clone()
                } else {
                    let name = self.unique_name("captured_collection");
                    self.data_helpers.insert(key, name.clone());
                    let mut body = format!(
                        "function {name}(): \\Zero\\{} {{\n    static $value;\n    if ($value === null) {{\n        $value = new \\Zero\\{0}();\n",
                        if c.set { "JsSet" } else { "JsMap" }
                    );
                    for (k, v) in &c.entries {
                        validate_captured_data(k, span, &mut HashSet::new())?;
                        validate_captured_data(v, span, &mut HashSet::new())?;
                        let k = self.captured_data(k)?;
                        if c.set {
                            let _ = writeln!(body, "        $value->add({k});");
                        } else {
                            let v = self.captured_data(v)?;
                            let _ = writeln!(body, "        $value->set({k}, {v});");
                        }
                    }
                    body.push_str("    }\n    return $value;\n}\n\n");
                    self.output.push_str(&body);
                    name
                };
                let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                let ty = match &b.ann {
                    _ if javascript => Ty::Dynamic,
                    Some((ann, module)) => self.resolve_runtime_ann(ann, *module)?,
                    None => {
                        let keys = Ty::union(c.entries.iter().map(|(k, _)| widen_fresh_literals(&ty_of_data(k))));
                        let values = Ty::union(c.entries.iter().map(|(_, v)| widen_fresh_literals(&ty_of_data(v))));
                        if c.entries.is_empty() {
                            return err_help(
                                span,
                                format!("`{n}` is an empty collection without a type"),
                                "annotate it, for example `const seen: Set<string> = new Set()`",
                            );
                        }
                        if c.set {
                            Ty::SetOf(Box::new(keys))
                        } else {
                            Ty::MapOf(Box::new(keys), Box::new(values))
                        }
                    }
                };
                Ok(Out::atom(format!("\\ZeroApp\\{name}()"), ty))
            }
            Value::Symbol(symbol) => {
                self.require_javascript_symbols(span)?;
                let php = match &**symbol {
                    SymbolVal::Registered(key) => format!("\\Zero\\JsSymbol::for({})", php_str(key)),
                    SymbolVal::WellKnown(name) => format!("\\Zero\\JsSymbol::wellKnown({})", php_str(name)),
                    SymbolVal::Unique(description) => {
                        let key = (self.owner(), 5, Rc::as_ptr(symbol) as usize);
                        let name = match self.data_helpers.get(&key) {
                            Some(name) => name.clone(),
                            None => {
                                let name = self.unique_name("captured_symbol");
                                self.data_helpers.insert(key, name.clone());
                                let description = description.as_deref().map(php_str).unwrap_or_default();
                                let _ = writeln!(
                                    self.output,
                                    "function {name}(): \\Zero\\JsSymbol {{ static $value; return $value ??= \\Zero\\JsSymbol::create(new \\Zero\\JsArray([{description}])); }}\n"
                                );
                                name
                            }
                        };
                        format!("\\ZeroApp\\{name}()")
                    }
                };
                Ok(Out::atom(php, Ty::Dynamic))
            }
            Value::Weak(weak) => {
                let kind = if weak.set { "WeakSet" } else { "WeakMap" };
                if !self.javascript_module(self.fx.as_ref().expect("fn").module) {
                    return err_help(
                        span,
                        format!("`{n}` is a {kind}, which TypeScript code here can't use"),
                        format!("use the {kind} from imported JavaScript, or key a Record<string, T> by a string id"),
                    );
                }
                let key = (self.owner(), 6, Rc::as_ptr(weak) as usize);
                let name = match self.data_helpers.get(&key) {
                    Some(name) => name.clone(),
                    None => {
                        let name = self.unique_name("captured_weak");
                        self.data_helpers.insert(key, name.clone());
                        let _ = writeln!(
                            self.output,
                            "function {name}(): \\Zero\\Js{kind} {{ static $value; return $value ??= new \\Zero\\Js{kind}(); }}\n"
                        );
                        name
                    }
                };
                Ok(Out::atom(format!("\\ZeroApp\\{name}()"), Ty::Dynamic))
            }
            Value::Regex(p, f) => {
                let key = (self.owner(), 2, Rc::as_ptr(p) as *const () as usize);
                let name = if let Some(name) = self.data_helpers.get(&key) {
                    name.clone()
                } else {
                    let name = self.unique_name("captured_regex");
                    self.data_helpers.insert(key, name.clone());
                    let _ = writeln!(
                        self.output,
                        "function {name}(): \\Zero\\RegExp {{ static $value; return $value ??= new \\Zero\\RegExp({}, {}); }}\n",
                        php_str(p),
                        php_str(f)
                    );
                    name
                };
                Ok(Out::atom(
                    format!("\\ZeroApp\\{name}()"),
                    if self.javascript_module(self.fx.as_ref().expect("fn").module) {
                        Ty::Dynamic
                    } else {
                        Ty::Regex
                    },
                ))
            }
            data => {
                if is_static_namespace(data) {
                    return err_help(
                        span,
                        format!(
                            "`{n}` holds functions, so it exists only while the config is built and can't be used as a value in PHP"
                        ),
                        format!(
                            "call or read its members directly, e.g. `{}`; it can't be passed, stored, spread, iterated or written",
                            namespace_example(n, data)
                        ),
                    );
                }
                validate_captured_data(data, span, &mut HashSet::new())?;
                let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                let ty = match &b.ann {
                    _ if javascript => Ty::Dynamic,
                    Some((ann, module)) => self.resolve_runtime_ann(ann, *module)?,
                    None => inferred_binding_type(&ty_of_data(data), member),
                };
                let ty = self.effects.captured_data_type(data, &ty);
                Ok(Out::atom(self.captured_data(data)?, ty))
            }
        }
    }

    /// The build-time value at the end of `e` when `e` is a captured binding or a static
    /// member chain from one. Locals and other expressions are None.
    fn static_receiver(&mut self, e: &Expr) -> Result<Option<(String, Value, Binding)>> {
        match &e.kind {
            ExprKind::Ident(n) => Ok(match self.resolve(n, e.span) {
                Ok(Resolved::Value(v, b)) => Some((n.clone(), v, b)),
                _ => None,
            }),
            ExprKind::Member {
                obj, prop, prop_span, ..
            } => self.static_member(obj, prop, *prop_span),
            _ => Ok(None),
        }
    }

    /// `obj.prop` resolved at compile time when `obj` is a static namespace (see
    /// `is_static_namespace`). The namespace is never built in PHP, so its members are constant.
    fn static_member(&mut self, obj: &Expr, prop: &str, prop_span: Span) -> Result<Option<(String, Value, Binding)>> {
        let Some((path, receiver, binding)) = self.static_receiver(obj)? else {
            return Ok(None);
        };
        if !is_static_namespace(&receiver) {
            return Ok(None);
        }
        let path = format!("{path}.{prop}");
        let (own, function) = match &receiver {
            Value::Object(o) => (o.borrow().get(prop).cloned(), false),
            Value::Closure(c) => (c.props.borrow().get(prop).cloned(), true),
            _ => unreachable!("static namespaces are objects or functions"),
        };
        let value = match own {
            Some(value) => value,
            None if function && crate::value::FUNCTION_BUILTIN_PROPERTIES.contains(&prop) => {
                return err_help(
                    prop_span,
                    format!("`{path}` isn't supported on a function with properties"),
                    "function `name`, `length`, `prototype` and `Function.prototype` members aren't modeled; read a property you assigned",
                );
            }
            None if !function && crate::builtins::contract("object", prop).is_some() => {
                return err_help(
                    prop_span,
                    format!("`{path}` reads an inherited property, which isn't supported"),
                    "read an own property of the object",
                );
            }
            None if !self.javascript_module(self.fx.as_ref().expect("fn").module) => {
                let keys: Vec<String> = match &receiver {
                    Value::Object(o) => o.borrow().keys().cloned().collect(),
                    Value::Closure(c) => c.props.borrow().keys().cloned().collect(),
                    _ => unreachable!(),
                };
                let mut d = Diag::new(prop_span, format!("`{path}` doesn't exist"));
                if let Some(s) = suggest(prop, keys.iter().map(String::as_str)) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                return Err(d);
            }
            None => Value::Undef,
        };
        Ok(Some((path, value, Binding { ann: None, ..binding })))
    }

    fn captured_data(&mut self, value: &Value) -> Result<String> {
        let owner = self.owner();
        let (key, array) = match value {
            Value::Array(a) => ((owner, 0, Rc::as_ptr(a) as usize), true),
            Value::Object(o) => ((owner, 1, Rc::as_ptr(o) as usize), false),
            _ => {
                return Ok(match value {
                    Value::Undef => "\\Zero\\Js::undefined()".into(),
                    Value::Null => "null".into(),
                    Value::Bool(b) => b.to_string(),
                    Value::Num(n) => php_number(*n),
                    Value::Str(s) => php_str(s),
                    _ => unreachable!("validated captured scalar"),
                });
            }
        };
        if let Some(name) = self.data_helpers.get(&key) {
            return Ok(format!("\\ZeroApp\\{name}()"));
        }
        let name = self.unique_name("captured");
        self.data_helpers.insert(key, name.clone());
        let fields: Vec<(String, Value)> = match value {
            Value::Array(a) => a
                .borrow()
                .iter()
                .enumerate()
                .map(|(i, v)| (i.to_string(), v.clone()))
                .collect(),
            Value::Object(o) => o.borrow().iter().map(|(k, v)| (php_str(k), v.clone())).collect(),
            _ => unreachable!(),
        };
        let class = if array { "JsArray" } else { "JsObject" };
        let storage = if array { "a" } else { "p" };
        let mut body = format!(
            "function {name}(): \\Zero\\{class}\n{{\n    static $value;\n    if ($value === null) {{\n        $value = new \\Zero\\{class}();\n"
        );
        for (key, value) in fields {
            let value = self.captured_data(&value)?;
            let _ = writeln!(body, "        $value->{storage}[{key}] = {value};");
        }
        body.push_str("    }\n    return $value;\n}\n\n");
        self.output.push_str(&body);
        Ok(format!("\\ZeroApp\\{name}()"))
    }

    fn compile_helper_now(&mut self, c: &Rc<Closure>, name: &str) -> Result<()> {
        // Compile the helper before its caller so its return type is known.
        let no_db = self.fx().no_db;
        let saved_out = std::mem::take(&mut self.output);
        let saved_fx = self.fx.take();
        let r = self.compile_function(c, name, None, None, no_db);
        self.fx = saved_fx;
        let helper_src = std::mem::replace(&mut self.output, saved_out);
        self.output.push_str(&helper_src);
        r
    }

    fn closure_expr(&mut self, f: &Rc<Func>) -> Result<Out> {
        self.closure_with_types(f, None)
    }

    fn initialize_parameter(&mut self, parameter: &Param, php: &str, ty: &Ty, indent: usize) -> Result<(Ty, String)> {
        let read = self.effects.parameter_type(parameter.pat.span(), ty);
        let Some(default) = &parameter.default else {
            return Ok((read, String::new()));
        };
        let value = self.expr(default)?;
        if !assignable(&value.ty, ty) {
            return err(
                default.span,
                format!(
                    "this default is {}, but the parameter expects {}",
                    value.ty.describe(),
                    ty.describe()
                ),
            );
        }
        let present = defined_type(&read);
        let initialized = if assignable(&value.ty, &present) {
            present
        } else {
            Ty::union([present, value.ty])
        };
        let initialization = format!(
            "{}if ({php} === \\Zero\\JsUndefined::Value) {{ {php} = {}; }}\n",
            pad(indent),
            value.php
        );
        Ok((initialized, initialization))
    }

    fn declare_parameter(&mut self, parameter: &Param, ty: &Ty, body: &mut String, indent: usize) -> Result<String> {
        let Pat::Id(name, _) = &parameter.pat else {
            unreachable!("identifier parameter")
        };
        let php = self.declare_local(name, ty.clone(), true, parameter.pat.span());
        let (read, initialization) = self.initialize_parameter(parameter, &php, ty, indent)?;
        let read = if name == "this" {
            self.constructor_receiver_read(&read)
        } else {
            read
        };
        body.push_str(&initialization);
        let local = self
            .fx()
            .scopes
            .last_mut()
            .expect("scope")
            .get_mut(name)
            .expect("parameter");
        local.ty = read;
        Ok(php)
    }

    fn closure_body(&mut self, f: &Func, param_tys: &[Ty]) -> Result<(String, String)> {
        let arguments = self.javascript_arguments(f, 2)?;
        let mut params = Vec::new();
        let mut body = String::new();
        for (i, p) in f.params.iter().enumerate() {
            if p.rest {
                let Pat::Id(name, _) = &p.pat else {
                    return err(p.span, "rest parameters need a plain name");
                };
                let php = self.declare_local(name, param_tys[i].clone(), true, p.pat.span());
                let rest = self.tmp_var();
                params.push(format!("...{rest}"));
                let _ = writeln!(body, "        {php} = new \\Zero\\JsArray({rest});");
                continue;
            }
            match &p.pat {
                Pat::Id(_, _) => {
                    let php = self.declare_parameter(p, &param_tys[i], &mut body, 2)?;
                    let default = if self.javascript_module(f.module) {
                        " = \\Zero\\JsUndefined::Value"
                    } else {
                        parameter_default_suffix(&f.params[i..], &param_tys[i..f.params.len()])
                    };
                    params.push(format!("{php}{default}"));
                }
                pat => {
                    let tmp = self.tmp_var();
                    params.push(format!(
                        "{tmp}{}",
                        parameter_default_suffix(&f.params[i..], &param_tys[i..f.params.len()])
                    ));
                    let (read, initialization) = self.initialize_parameter(p, &tmp, &param_tys[i], 2)?;
                    body.push_str(&initialization);
                    self.bind_pattern_types(
                        pat,
                        &tmp,
                        BindingTypes {
                            read,
                            constraint: param_tys[i].clone(),
                        },
                        true,
                        &mut body,
                        2,
                    )?;
                }
            }
        }
        body = arguments + &self.javascript_prologue(f, 2)? + &body + &self.generator_prologue(f, 2);
        match &f.body {
            FnBody::Expr(e) => {
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                self.note_return(&v.ty, e.span)?;
                let _ = writeln!(body, "{}return {};", pad(2), v.php);
            }
            FnBody::Block(stmts) => {
                body.push_str(&self.block(stmts, 2)?);
                self.note_fallthrough(stmts, f.span)?;
                body.push_str("        return \\Zero\\Js::undefined();\n");
            }
        }
        Ok((params.join(", "), body))
    }

    /// Compiles a callback argument with its parameters typed from context.
    fn callback(&mut self, e: &Expr, params: &[Ty]) -> Result<Out> {
        if let ExprKind::Ident(name) = &e.kind
            && matches!(self.resolve(name, e.span), Ok(Resolved::Global("Boolean")))
        {
            return Ok(Out::atom(
                "fn($value) => \\Zero\\Js::truthy($value)",
                Ty::Fn(vec![Ty::Unknown], Box::new(Ty::Bool)),
            ));
        }
        if let ExprKind::Func(f) = &e.kind {
            return self.closure_with_types(f, Some(params));
        }
        // A named function expression lowers to `(() => { function name() {} return name; })()`;
        // its inner declaration takes the callback's parameter types, as the bare function would.
        if let Some(inner) = named_function_expression(e) {
            let saved = self.named_callback.replace((inner.span, params.to_vec()));
            let v = self.expr(e);
            self.named_callback = saved;
            return v;
        }
        let v = self.expr(e)?;
        let Ty::Fn(declared, _) = &v.ty else {
            return err(e.span, format!("expected a function, got {}", v.ty.describe()));
        };
        check_callback_parameters(params, declared, e.span)?;
        Ok(v)
    }

    /// A function's type from its annotations alone: every parameter and the result annotated,
    /// or a function in imported JavaScript. `None` when the body must be compiled to know it.
    fn declared_signature(&mut self, f: &Func) -> Result<Option<Ty>> {
        if self.javascript_module(f.module) {
            let params = f
                .params
                .iter()
                .map(|p| {
                    if p.rest {
                        Ty::Rest(Box::new(Ty::Array(Box::new(Ty::Dynamic))))
                    } else {
                        Ty::Dynamic
                    }
                })
                .collect();
            return Ok(Some(Ty::Fn(params, Box::new(Ty::Dynamic))));
        }
        let (Some(ret), false) = (&f.ret, f.is_async || !f.type_params.is_empty()) else {
            return Ok(None);
        };
        let mut params = Vec::new();
        for p in &f.params {
            let Some(ann) = &p.ann else { return Ok(None) };
            let ty = parameter_input_type(p, self.resolve_runtime_ann(ann, f.module)?);
            params.push(if p.rest { Ty::Rest(Box::new(ty)) } else { ty });
        }
        let ret = self.resolve_runtime_ann(ret, f.module)?;
        Ok(Some(Ty::Fn(params, Box::new(ret))))
    }

    fn closure_with_types(&mut self, f: &Rc<Func>, params: Option<&[Ty]>) -> Result<Out> {
        if !f.type_params.is_empty() {
            return err_help(
                f.span,
                "generic runtime functions aren't supported",
                "use a concrete signature, for example function identity(value: string): string",
            );
        }
        let module = f.module;
        let mut param_tys = Vec::new();
        for (i, p) in f.params.iter().enumerate() {
            let given = params.and_then(|ps| {
                if p.rest {
                    Some(Ty::Array(Box::new(Ty::union(
                        ps.iter().skip(i).map(widen_fresh_literals),
                    ))))
                } else {
                    ps.get(i).cloned()
                }
            });
            let t = match &p.ann {
                Some(a) => {
                    let declared = self.resolve_runtime_ann(a, module)?;
                    if let Some(given) = &given {
                        check_parameter_argument(given, &parameter_input_type(p, declared.clone()), p.span)?;
                    } else if params.is_some() && !p.optional && p.default.is_none() {
                        return err(p.span, "this callback parameter is never given a value");
                    }
                    declared
                }
                None => match given {
                    Some(t) => widen_fresh_literals(&t),
                    None if self.javascript_module(f.module) => {
                        if p.rest {
                            Ty::Array(Box::new(Ty::Dynamic))
                        } else {
                            Ty::Dynamic
                        }
                    }
                    None => return err(p.span, "this callback parameter is never given a value"),
                },
            };
            param_tys.push(parameter_input_type(p, t));
        }
        let mut inner = FnCtx {
            function: f.span,
            scopes: vec![HashMap::new()],
            is_async: f.is_async,
            is_generator: f.is_generator,
            ret: None,
            returns: Vec::new(),
            diverging: HashSet::new(),
            exhaustive_switches: HashSet::new(),
            loop_bindings: HashSet::new(),
            env: self.fx().env.clone(),
            module,
            php_names: self.fx().php_names.clone(),
            captures: Some(Vec::new()),
            outer: None,
            tmp: 0,
            jumps: Vec::new(),
            pending_labels: Vec::new(),
            conditional_depth: 0,
            no_db: self.fx().no_db,
            access: None,
        };
        if f.is_generator && !self.javascript_module(module) {
            return err_help(
                f.span,
                "generators only work in imported JavaScript",
                "return an array, or move the generator into a .js module",
            );
        }
        if f.is_async && !self.javascript_module(module) && !std::mem::take(&mut self.async_callback) {
            return err_help(
                f.span,
                "async callbacks aren't supported",
                "use a `for ... of` loop with `await` inside",
            );
        }
        self.async_callback = false;
        let predicate = self.function_predicate(f, &param_tys)?;
        if predicate.is_some() {
            inner.ret = Some(Ty::Bool);
        } else if let Some(r) = &f.ret {
            inner.ret = Some(match self.resolve_runtime_ann(r, module)? {
                Ty::Promise(t) if f.is_async => *t,
                t => t,
            });
        }
        let outer = self.fx.take().expect("fn");
        inner.outer = Some(Box::new(outer));
        self.fx = Some(inner);
        let result = self.closure_body(f, &param_tys);
        let mut inner = self.fx.take().expect("inner");
        self.fx = Some(*inner.outer.take().expect("outer"));
        let (params_php, body) = result?;
        let ret = predicate.unwrap_or_else(|| inner.ret.clone().unwrap_or_else(|| Ty::union(inner.returns.clone())));
        let caps = inner.captures.take().unwrap_or_default();
        let uses: Vec<String> = caps
            .iter()
            .map(|(n, m)| format!("{}${n}", if *m { "&" } else { "" }))
            .collect();
        let use_clause = if uses.is_empty() {
            String::new()
        } else {
            format!(" use ({})", uses.join(", "))
        };
        // Single-expression arrows become PHP arrow functions (auto-capture by value).
        let php = match (&f.body, caps.iter().any(|(_, m)| *m)) {
            (FnBody::Expr(_), false) => {
                let ret_line = body
                    .trim()
                    .trim_start_matches("return ")
                    .trim_end_matches(';')
                    .to_string();
                if body.trim().lines().count() == 1 {
                    format!("fn({params_php}) => {ret_line}")
                } else {
                    format!("function ({params_php}){use_clause} {{\n{body}{}}}", pad(1))
                }
            }
            _ => format!("function ({params_php}){use_clause} {{\n{body}{}}}", pad(1)),
        };
        let php = if self.suspends(f) {
            self.suspending_closure(f, php)
        } else {
            php
        };
        let php = if let Some((keys, descending)) = crate::builtins::sort_key(f) {
            let keys = keys.iter().map(|key| php_str(key)).collect::<Vec<_>>().join(", ");
            format!(
                "\\Zero\\Js::sortComparator({php}, [{keys}], {})",
                if descending { "true" } else { "false" }
            )
        } else {
            php
        };
        Ok(Out::atom(
            php,
            Ty::Fn(callable_parameters(f, &param_tys), Box::new(ret)),
        ))
    }

    fn assign(
        &mut self,
        op: Option<AssignOp>,
        target: &Expr,
        value: &Expr,
        span: Span,
        statement: bool,
    ) -> Result<Out> {
        let prototype_receiver = match &target.kind {
            ExprKind::Member { obj, prop, .. } if prop == "__proto__" => Some(obj),
            ExprKind::Index { obj, index, .. } if matches!(&index.kind, ExprKind::Str(key) if key == "__proto__") => {
                Some(obj)
            }
            _ => None,
        };
        if let Some(receiver) = prototype_receiver {
            let own = self
                .argument_constraint(receiver)
                .is_some_and(|ty| matches!(ty.non_null(), Ty::Obj(fields) if fields.contains_key("__proto__")));
            if !own {
                return err_help(
                    target.span,
                    "prototype mutation isn't supported",
                    "use an ordinary own data property",
                );
            }
        }
        let place = self.place(target)?;
        if op.is_none()
            && !statement
            && let Some(target) = place.target.as_ref()
            && !matches!(target.kind, WriteKind::NativeObject | WriteKind::Private(_))
        {
            let v = self.expr(value)?;
            let v = self.finish_value(v, value.span)?;
            if !assignable(&v.ty, &place.constraint) {
                return err(
                    span,
                    format!(
                        "can't assign {} to something of type {}",
                        v.ty.describe(),
                        place.constraint.describe()
                    ),
                );
            }
            return Ok(Out::atom(
                format!(
                    "\\Zero\\Js::{}({}, {}, {})",
                    match target.kind {
                        WriteKind::Dynamic => "dynamicSet",
                        WriteKind::Array => "setAt",
                        WriteKind::Object => "set",
                        WriteKind::NativeObject | WriteKind::Private(_) => unreachable!(),
                    },
                    target.receiver,
                    target.key,
                    v.php
                ),
                v.ty,
            ));
        }
        let (mut steps, read, write) = self.capture_place(place.target, &place.read);
        if let Some(op) = op {
            let old = self.tmp_var();
            steps.push(format!("{old} = {read}"));
            let present = if matches!(op, AssignOp::Bin(_)) {
                match &write {
                    CapturedPlace::Binding(_) | CapturedPlace::Dynamic { .. } | CapturedPlace::Private { .. } => None,
                    CapturedPlace::Array { receiver, key }
                    | CapturedPlace::Object { receiver, key }
                    | CapturedPlace::NativeObject { receiver, key } => {
                        let present = self.tmp_var();
                        steps.push(format!("{present} = \\Zero\\Js::has({receiver}, {key})"));
                        Some(present)
                    }
                }
            } else {
                None
            };
            let v = self.expr(value)?;
            let v = self.finish_value(v, value.span)?;
            let (result, ty) = match op {
                AssignOp::Bin(b) => {
                    let mut left = Out::atom(old.clone(), place.read.ty.clone());
                    if let Some(present) = &present
                        && place.constraint.non_null().is_num()
                    {
                        left = Out::atom(format!("({present} ? \\Zero\\Js::toNumber({old}) : NAN)"), Ty::Num);
                    }
                    let right = v.php.clone();
                    let mut result = self.binary(b, left, v, span)?;
                    if b == BinOp::Add
                        && let Some(present) = present
                    {
                        result.php = format!("\\Zero\\Js::plus({old}, {right}, !{present})");
                        result.atomic = true;
                    }
                    if !(assignable(&result.ty, &place.constraint) || result.ty.is_num() && place.constraint.is_num()) {
                        return err(
                            span,
                            format!(
                                "can't assign {} to something of type {}",
                                result.ty.describe(),
                                place.constraint.describe()
                            ),
                        );
                    }
                    (Self::write_place(&write, &result.php), result.ty)
                }
                AssignOp::Log(l) => {
                    if !assignable(&v.ty, &place.constraint) {
                        return err(
                            span,
                            format!(
                                "can't assign {} to something of type {}",
                                v.ty.describe(),
                                place.constraint.describe()
                            ),
                        );
                    }
                    let ty = logical_result_type(l, &place.read.ty, &v.ty);
                    let keep = match l {
                        LogOp::Nullish => format!("!\\Zero\\Js::isNullish({old})"),
                        LogOp::Or => self.truthy(&Out::atom(old.clone(), place.read.ty)),
                        LogOp::And => format!("!({})", self.truthy(&Out::atom(old.clone(), place.read.ty))),
                    };
                    (format!("({keep} ? {old} : {})", Self::write_place(&write, &v.php)), ty)
                }
            };
            return Ok(Out::new(Self::assignment_sequence(steps, result, statement), ty));
        }
        let v = self.expr(value)?;
        let v = self.finish_value(v, value.span)?;
        if !assignable(&v.ty, &place.constraint) {
            return err(
                span,
                format!(
                    "can't assign {} to something of type {}",
                    v.ty.describe(),
                    place.constraint.describe()
                ),
            );
        }
        Ok(Out::new(
            Self::assignment_sequence(steps, Self::write_place(&write, &v.php), statement),
            v.ty,
        ))
    }

    /// `[a, b] = value` / `({ a, b: c.d = 1, ...rest } = value)`. The value is evaluated once and is
    /// the result. Each leaf runs in JavaScript order: its computed key, then its target reference,
    /// then the read from the value, then its default (only for undefined), then the write.
    fn assign_pattern(&mut self, pattern: &AssignPat, value: &Expr) -> Result<Out> {
        let v = self.expr(value)?;
        let v = self.finish_value(v, value.span)?;
        let source = self.tmp_var();
        let mut steps = vec![format!("{source} = {}", v.php)];
        // Untyped JavaScript reads absent fields as undefined: destructure through checked dispatch.
        let ty = if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            Ty::Dynamic
        } else {
            v.ty.clone()
        };
        self.assign_pattern_steps(pattern, &source, None, &ty, &mut steps)?;
        Ok(Out::new(Self::sequence(steps, source), v.ty))
    }

    /// `pending` is the step that reads this pattern's value into `src`. A leaf target resolves
    /// its reference first, as JavaScript does; a nested pattern reads first.
    fn assign_pattern_steps(
        &mut self,
        pattern: &AssignPat,
        src: &str,
        pending: Option<String>,
        ty: &Ty,
        steps: &mut Vec<String>,
    ) -> Result<()> {
        match pattern {
            AssignPat::Target(target) => self.assign_pattern_leaf(target, None, src, pending, ty, steps),
            AssignPat::Default(inner, default) => {
                if let AssignPat::Target(target) = &**inner {
                    return self.assign_pattern_leaf(target, Some(default), src, pending, ty, steps);
                }
                steps.extend(pending);
                let (value, ty) = self.assign_pattern_default(default, src, ty, steps)?;
                self.assign_pattern_steps(inner, &value, None, &ty, steps)
            }
            AssignPat::Obj { props, rest, span } => {
                steps.extend(pending);
                if ty.is_nullable() && *ty != Ty::Dynamic {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
                }
                let object = self.tmp_var();
                steps.push(format!("{object} = \\Zero\\Js::requirePresent({src})"));
                let mut keys = Vec::new();
                let mut literal_keys: Vec<Option<String>> = Vec::new();
                for (key, p) in props {
                    let mut computed_literal = None;
                    let (key_php, field_ty) = match key {
                        PropKey::Static(k) => {
                            let field = if *ty == Ty::Dynamic {
                                Some(Ty::Dynamic)
                            } else {
                                ty.non_null().field(k).map(|(t, optional)| {
                                    if optional || matches!(ty.non_null(), Ty::Map(_)) {
                                        Ty::union([t, Ty::Undefined])
                                    } else {
                                        t
                                    }
                                })
                            };
                            let Some(field) = field else {
                                return Err(self.no_field(ty, k, p.span()));
                            };
                            (php_str(k), field)
                        }
                        PropKey::Computed(k) => {
                            let key = self.expr(k)?;
                            let key = self.finish_value(key, k.span)?;
                            let tmp = self.tmp_var();
                            steps.push(format!("{tmp} = \\Zero\\Js::key({})", key.php));
                            let literal = match &key.ty {
                                Ty::StrLit(s) | Ty::FreshStrLit(s) => Some(s.clone()),
                                _ => None,
                            };
                            computed_literal = literal.clone();
                            let field = match ty.non_null() {
                                Ty::Dynamic => Ty::Dynamic,
                                Ty::Map(t) => Ty::union([*t, Ty::Undefined]),
                                fields @ (Ty::Obj(_) | Ty::Instance(..)) if literal.is_some() => {
                                    let name = literal.expect("literal key");
                                    let Some((t, optional)) = fields.field(&name) else {
                                        return Err(self.no_field(ty, &name, k.span));
                                    };
                                    if optional { Ty::union([t, Ty::Undefined]) } else { t }
                                }
                                other => {
                                    return err(
                                        k.span,
                                        format!(
                                            "a computed destructuring key needs an open map, not {}",
                                            other.describe()
                                        ),
                                    );
                                }
                            };
                            (tmp, field)
                        }
                    };
                    keys.push(key_php.clone());
                    literal_keys.push(match key {
                        PropKey::Static(k) => Some(k.clone()),
                        PropKey::Computed(_) => computed_literal.clone(),
                    });
                    let read = self.tmp_var();
                    let getter = if *ty == Ty::Dynamic { "dynamicGet" } else { "dataGet" };
                    let pending = format!("{read} = \\Zero\\Js::{getter}({object}, {key_php})");
                    self.assign_pattern_steps(p, &read, Some(pending), &field_ty, steps)?;
                }
                if let Some(r) = rest {
                    let value = self.tmp_var();
                    let pending = format!("{value} = \\Zero\\Js::omit({object}, [{}])", keys.join(", "));
                    let rest_ty = match ty.non_null() {
                        Ty::Obj(f) => Ty::Obj(
                            f.into_iter()
                                .filter(|(k, _)| !literal_keys.iter().flatten().any(|taken| taken == k))
                                .collect(),
                        ),
                        other => other,
                    };
                    self.assign_pattern_steps(r, &value, Some(pending), &rest_ty, steps)?;
                }
                Ok(())
            }
            AssignPat::Arr { elems, rest, span } => {
                steps.extend(pending);
                let element = match ty.non_null() {
                    Ty::Dynamic => None,
                    Ty::Array(t) | Ty::NonEmptyArray(t) => Some(Ty::union([*t, Ty::Undefined])),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Some(Ty::union(ts.into_iter().chain([Ty::Undefined]))),
                    _ => return err(*span, format!("can't destructure {} as an array", ty.describe())),
                };
                if ty.is_nullable() && *ty != Ty::Dynamic {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
                }
                // An array is read live, element by element, as JavaScript's array iterator does,
                // so an earlier target write is seen by later reads. Checked JavaScript may pass any
                // other iterable, which is read once up front.
                let list = self.tmp_var();
                steps.push(match element {
                    None => format!("{list} = \\Zero\\Js::dynamicIterableLive({src})"),
                    Some(_) => format!("{list} = {src}"),
                });
                for (i, p) in elems.iter().enumerate() {
                    let Some(p) = p else { continue };
                    let read = self.tmp_var();
                    let el_ty = match (&element, ty.non_null()) {
                        (None, _) => Ty::Dynamic,
                        (Some(_), Ty::Tuple(ts) | Ty::ArrayLiteral(ts)) => ts.get(i).cloned().unwrap_or(Ty::Undefined),
                        (Some(t), _) => t.clone(),
                    };
                    let pending = format!("{read} = \\Zero\\Js::dynamicGet({list}, {i})");
                    self.assign_pattern_steps(p, &read, Some(pending), &el_ty, steps)?;
                }
                if let Some(r) = rest {
                    let value = self.tmp_var();
                    let pending = format!("{value} = \\Zero\\Js::restFrom({list}, {})", elems.len());
                    let rest_ty = match (&element, ty.non_null()) {
                        (None, _) => Ty::Dynamic,
                        (Some(_), Ty::Tuple(ts) | Ty::ArrayLiteral(ts)) => {
                            Ty::Array(Box::new(Ty::union(ts.into_iter().skip(elems.len()))))
                        }
                        (Some(_), Ty::Array(t) | Ty::NonEmptyArray(t)) => Ty::Array(t),
                        _ => unreachable!("array element type"),
                    };
                    self.assign_pattern_steps(r, &value, Some(pending), &rest_ty, steps)?;
                }
                Ok(())
            }
        }
    }

    fn assign_pattern_default(
        &mut self,
        default: &Expr,
        src: &str,
        ty: &Ty,
        steps: &mut Vec<String>,
    ) -> Result<(String, Ty)> {
        let value = self.tmp_var();
        let d = self.expr(default)?;
        let d = self.finish_value(d, default.span)?;
        steps.push(format!(
            "{value} = ({src} === \\Zero\\Js::undefined() ? {} : {src})",
            d.php
        ));
        Ok((value, Ty::union([defined_type(ty), d.ty])))
    }

    fn assign_pattern_leaf(
        &mut self,
        target: &Expr,
        default: Option<&Expr>,
        src: &str,
        pending: Option<String>,
        ty: &Ty,
        steps: &mut Vec<String>,
    ) -> Result<()> {
        let place = self.place(target)?;
        let (captured, _, write) = self.capture_place(place.target, &place.read);
        steps.extend(captured);
        steps.extend(pending);
        let (value, ty) = match default {
            Some(default) => self.assign_pattern_default(default, src, ty, steps)?,
            None => (src.to_string(), ty.clone()),
        };
        if !assignable(&ty, &place.constraint) {
            return err(
                target.span,
                format!(
                    "can't assign {} to something of type {}",
                    ty.describe(),
                    place.constraint.describe()
                ),
            );
        }
        steps.push(Self::write_place(&write, &value));
        if let ExprKind::Ident(name) = &target.kind {
            self.set_local_ty(name, ty);
        }
        Ok(())
    }

    fn assignment_sequence(mut steps: Vec<String>, result: String, statement: bool) -> String {
        if statement {
            steps.push(result);
            return steps.join(";\n");
        }
        Self::sequence(steps, result)
    }

    fn sequence(mut steps: Vec<String>, result: String) -> String {
        if steps.is_empty() {
            return result;
        }
        let index = steps.len();
        steps.push(result);
        format!("[{}][{index}]", steps.join(", "))
    }

    fn capture_place(&mut self, target: Option<WriteTarget>, read: &Out) -> (Vec<String>, String, CapturedPlace) {
        match target {
            None => (Vec::new(), read.php.clone(), CapturedPlace::Binding(read.php.clone())),
            Some(target) => {
                let receiver = self.tmp_var();
                let key = self.tmp_var();
                let steps = vec![
                    format!("{receiver} = {}", target.receiver),
                    format!("{key} = {}", target.key),
                ];
                let read = match target.kind {
                    WriteKind::Dynamic | WriteKind::Array => format!("\\Zero\\Js::dynamicGet({receiver}, {key})"),
                    // Present non-null values skip the checked read; absent keys keep dataGet's semantics.
                    WriteKind::NativeObject => {
                        format!("({receiver}->p[{key}] ?? \\Zero\\Js::dataGet({receiver}, {key}))")
                    }
                    WriteKind::Object => format!("\\Zero\\Js::dataGet({receiver}, {key})"),
                    WriteKind::Private(ref class) => format!("{class}->privateGet({receiver}, {key})"),
                };
                let write = match target.kind {
                    WriteKind::Private(class) => CapturedPlace::Private { class, receiver, key },
                    WriteKind::Dynamic => CapturedPlace::Dynamic { receiver, key },
                    WriteKind::Array => CapturedPlace::Array { receiver, key },
                    WriteKind::NativeObject => CapturedPlace::NativeObject { receiver, key },
                    WriteKind::Object => CapturedPlace::Object { receiver, key },
                };
                (steps, read, write)
            }
        }
    }

    fn write_place(write: &CapturedPlace, value: &str) -> String {
        match write {
            CapturedPlace::Binding(binding) => format!("({binding} = {value})"),
            CapturedPlace::Object { receiver, key } => format!("\\Zero\\Js::set({receiver}, {key}, {value})"),
            CapturedPlace::NativeObject { receiver, key } => format!("({receiver}->p[{key}] = {value})"),
            CapturedPlace::Array { receiver, key } => format!("\\Zero\\Js::setAt({receiver}, {key}, {value})"),
            CapturedPlace::Dynamic { receiver, key } => format!("\\Zero\\Js::dynamicSet({receiver}, {key}, {value})"),
            CapturedPlace::Private { class, receiver, key } => {
                format!("{class}->privateSet({receiver}, {key}, {value})")
            }
        }
    }

    fn update(&mut self, target: &Expr, inc: bool, prefix: bool) -> Result<Out> {
        let place = self.place(target)?;
        // Private slots always exist once read; the presence check only applies to properties.
        let property_number = place
            .target
            .as_ref()
            .is_some_and(|t| !matches!(t.kind, WriteKind::Private(_)))
            && place.constraint.non_null().is_num();
        if !property_number && place.read.ty != Ty::Dynamic {
            self.need_num(&place.read, target.span, if inc { "`++`" } else { "`--`" })?;
        }
        let (mut steps, read, write) = self.capture_place(place.target, &place.read);
        let old = self.tmp_var();
        steps.push(format!("{old} = {read}"));
        if place.read.ty == Ty::Dynamic {
            steps.push(format!("{old} = \\Zero\\Js::toNumber({old})"));
        }
        if property_number {
            let (CapturedPlace::Array { receiver, key }
            | CapturedPlace::Object { receiver, key }
            | CapturedPlace::NativeObject { receiver, key }) = &write
            else {
                unreachable!()
            };
            steps.push(format!(
                "{old} = (\\Zero\\Js::has({receiver}, {key}) ? \\Zero\\Js::toNumber({old}) : NAN)"
            ));
        }
        let value = format!("\\Zero\\Js::add({old}, {})", if inc { "1" } else { "-1" });
        let written = Self::write_place(&write, &value);
        let result = if prefix {
            written
        } else {
            steps.push(written);
            old
        };
        Ok(Out::new(Self::sequence(steps, result), Ty::Num))
    }

    fn place(&mut self, target: &Expr) -> Result<AssignmentPlace> {
        self.host_place(target)?;
        match &target.kind {
            ExprKind::Ident(n) => match self.resolve(n, target.span)? {
                Resolved::Local(l) => {
                    if !l.mutable {
                        return err(target.span, format!("can't assign to `{n}`: it's a constant"));
                    }
                    Ok(AssignmentPlace {
                        constraint: l.constraint,
                        target: None,
                        read: Out::atom(l.php, l.ty),
                    })
                }
                Resolved::Value(_, binding) if binding.mutable && binding.module_level => err_help(
                    target.span,
                    format!("hooks can't change `{n}`: it's a module-level `let`"),
                    "store state in the database or use a local variable",
                ),
                _ => err(target.span, format!("can't assign to `{n}`")),
            },
            ExprKind::Member {
                obj, prop, prop_span, ..
            } => {
                if prop.starts_with('#') {
                    let (class, ty) = self.private_member(prop, *prop_span)?;
                    let o = self.private_receiver(obj)?;
                    return Ok(AssignmentPlace {
                        read: Out::atom(format!("{class}->privateGet({}, {})", o.php, php_str(prop)), ty.clone()),
                        constraint: ty,
                        target: Some(WriteTarget {
                            receiver: o.php,
                            key: php_str(prop),
                            kind: WriteKind::Private(class),
                        }),
                    });
                }
                let mut o = self.expr(obj)?;
                if o.ty == Ty::Dynamic {
                    return Ok(self.javascript_place(o, Out::atom(php_str(prop), Ty::Str)));
                }
                if o.ty == Ty::Arguments && prop == "length" {
                    let mut place = self.javascript_place(o, Out::atom(php_str(prop), Ty::Str));
                    place.read.ty = Ty::Num;
                    place.constraint = Ty::Num;
                    return Ok(place);
                }
                if let Some(path) = self.path_of(obj)
                    && let Some(ty) = self.narrowed_path(&path)
                {
                    o.ty = ty;
                }
                if o.ty.is_nullable() {
                    return err(target.span, format!("can't set `{prop}`: the object might be null"));
                }
                if let Some(ty) = self.class_accessor_write(&o.ty, prop, *prop_span)? {
                    return Ok(AssignmentPlace {
                        read: Out::atom(
                            format!("\\Zero\\Js::dynamicGet({}, {})", o.php, php_str(prop)),
                            ty.clone(),
                        ),
                        constraint: ty,
                        target: Some(WriteTarget {
                            receiver: o.php,
                            key: php_str(prop),
                            kind: WriteKind::Dynamic,
                        }),
                    });
                }
                // URL component setters (`u.pathname = "/x"`) run the URL Standard's setter steps in
                // `JsUrl::set` through checked property assignment.
                if o.ty == Ty::Url {
                    if !URL_SETTERS.contains(&prop.as_str()) {
                        return err_help(
                            *prop_span,
                            format!("URL has no writable property `{prop}`"),
                            format!("assign one of {}", URL_SETTERS.join(", ")),
                        );
                    }
                    return Ok(AssignmentPlace {
                        read: Out::atom(format!("{}->get({})", o.wrapped(), php_str(prop)), Ty::Str),
                        constraint: Ty::Str,
                        target: Some(WriteTarget {
                            receiver: o.php,
                            key: php_str(prop),
                            kind: WriteKind::Dynamic,
                        }),
                    });
                }
                if let Ty::Class(id) = o.ty {
                    o.ty = self.class_shape(id);
                }
                if !matches!(
                    o.ty.non_null(),
                    Ty::Obj(_) | Ty::Map(_) | Ty::Union(_) | Ty::Instance(..)
                ) {
                    return err(target.span, "can't assign this property");
                }
                let Some((ft, optional)) = o.ty.non_null().field(prop) else {
                    return Err(self.no_field(&o.ty, prop, *prop_span));
                };
                let constraint = self
                    .argument_constraint(obj)
                    .unwrap_or(o.ty.clone())
                    .non_null()
                    .field(prop)
                    .map(|(t, optional)| if optional { Ty::union([t, Ty::Undefined]) } else { t })
                    .unwrap_or(ft.clone());
                let read_ty = self
                    .path_of(target)
                    .and_then(|path| self.narrowed_path(&path))
                    .unwrap_or_else(|| {
                        if optional || matches!(o.ty.non_null(), Ty::Map(_)) {
                            Ty::union([ft, Ty::Undefined])
                        } else {
                            ft
                        }
                    });
                let read = Out::atom(format!("\\Zero\\Js::dataGet({}, {})", o.php, php_str(prop)), read_ty);
                Ok(AssignmentPlace {
                    constraint,
                    read,
                    target: Some(WriteTarget {
                        receiver: o.php,
                        key: php_str(prop),
                        kind: if self.native_object_receiver(obj) {
                            WriteKind::NativeObject
                        } else {
                            WriteKind::Object
                        },
                    }),
                })
            }
            ExprKind::Index { obj, index, .. } => {
                let o = self.expr(obj)?;
                let i = self.expr(index)?;
                if o.ty == Ty::Dynamic {
                    return Ok(self.javascript_place(o, i));
                }
                if o.ty == Ty::Arguments && i.ty.is_num() {
                    let mut place = self.javascript_place(o, i);
                    place.read.ty = Ty::Unknown;
                    place.constraint = Ty::Unknown;
                    return Ok(place);
                }
                if o.ty.is_nullable() {
                    return err(target.span, "can't index this: it might be null");
                }
                let original = self.argument_constraint(obj).unwrap_or(o.ty.clone());
                let (ty, constraint, array) = match (o.ty.non_null(), original.non_null()) {
                    (Ty::Array(t) | Ty::NonEmptyArray(t), Ty::Array(c) | Ty::NonEmptyArray(c)) if i.ty.is_num() => {
                        (*t, *c, true)
                    }
                    (Ty::Tuple(ts) | Ty::ArrayLiteral(ts), Ty::Tuple(cs) | Ty::ArrayLiteral(cs)) if i.ty.is_num() => {
                        (Ty::union(ts), Ty::union(cs), true)
                    }
                    (Ty::Map(t), Ty::Map(c)) if i.ty.is_str() => (*t, *c, false),
                    _ => {
                        return err(
                            target.span,
                            format!("can't assign into {} with {}", o.ty.describe(), i.ty.describe()),
                        );
                    }
                };
                let getter = if array { "dynamicGet" } else { "dataGet" };
                let read = Out::atom(
                    format!("\\Zero\\Js::{getter}({}, {})", o.php, i.php),
                    Ty::union([ty, Ty::Undefined]),
                );
                let key = if array {
                    i.php
                } else {
                    format!("\\Zero\\Js::key({})", i.php)
                };
                Ok(AssignmentPlace {
                    read,
                    constraint,
                    target: Some(WriteTarget {
                        receiver: o.php,
                        key,
                        kind: if array {
                            WriteKind::Array
                        } else if self.native_object_receiver(obj) {
                            WriteKind::NativeObject
                        } else {
                            WriteKind::Object
                        },
                    }),
                })
            }
            ExprKind::NonNull(x) => {
                let mut p = self.place(x)?;
                p.read.ty = p.read.ty.non_null();
                Ok(p)
            }
            ExprKind::As(x, _) => self.place(x),
            _ => err(target.span, "can't assign to this"),
        }
    }

    /// `delete obj.key` / `delete obj[key]` / `delete obj?.key`. As in TypeScript, a typed object
    /// may lose only an optional property (or a map entry), so later reads stay sound; arrays,
    /// checked JavaScript and other receivers go through Js::delete's runtime semantics.
    fn delete(&mut self, target: &Expr, span: Span) -> Result<Out> {
        let (chain, inner) = match &target.kind {
            ExprKind::Chain(inner) => {
                check_optional_chain(inner)?;
                (true, &**inner)
            }
            _ => (false, target),
        };
        let (obj, key, optional, prop) = match &inner.kind {
            ExprKind::Member {
                obj, prop, optional, ..
            } => (obj, None, *optional, Some(prop.as_str())),
            ExprKind::Index { obj, index, optional } => (obj, Some(index), *optional, None),
            _ => return err(span, "`delete` needs an object property"),
        };
        if chain && !optional {
            return err_help(
                span,
                "`delete` through an optional chain is supported only at its last step",
                "check the earlier steps first",
            );
        }
        let o = self.expr(obj)?;
        let o = if matches!(o.ty, Ty::Class(_)) {
            o
        } else {
            self.finish_value(o, obj.span)?
        };
        let key_php = match (key, prop) {
            (Some(index), _) => {
                let k = self.expr(index)?;
                self.finish_value(k, index.span)?.php
            }
            (None, Some(prop)) => php_str(prop),
            _ => unreachable!(),
        };
        let receiver_ty = self.argument_constraint(obj).unwrap_or(o.ty.clone());
        if key.is_some()
            && matches!(
                receiver_ty.non_null(),
                Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_)
            )
        {
            return err_help(
                span,
                "`delete` on an array element would leave a hole; Zero arrays are dense",
                "remove it with `splice(index, 1)`, or assign `undefined` to keep the length",
            );
        }
        if let Some(prop) = prop.map(str::to_string).or_else(|| match key.map(|k| &k.kind) {
            Some(ExprKind::Str(s)) => Some(s.clone()),
            _ => None,
        }) {
            let required = |ty: &Ty| match ty {
                Ty::Obj(fields) => fields.get(&prop).is_some_and(|(_, optional)| !optional),
                Ty::Instance(_, fields) => {
                    matches!(&**fields, Ty::Obj(f) if f.get(&prop).is_some_and(|(_, optional)| !optional))
                }
                _ => false,
            };
            let mut members = Vec::new();
            match receiver_ty.non_null() {
                Ty::Union(items) => members.extend(items),
                other => members.push(other),
            }
            if members.iter().any(required) {
                return err_help(
                    span,
                    format!("can't delete `{prop}`: the type requires it"),
                    "make the property optional (`key?: T`), as TypeScript requires for `delete`",
                );
            }
            // A closed object type without the property: TypeScript rejects the read, and a typo
            // here would silently delete nothing.
            let closed_without = |ty: &Ty| match ty {
                Ty::Obj(fields) => !fields.contains_key(&prop),
                Ty::Instance(_, fields) => matches!(&**fields, Ty::Obj(f) if !f.contains_key(&prop)),
                _ => false,
            };
            if members.iter().any(closed_without) {
                return Err(self.no_field(&receiver_ty, &prop, span));
            }
        } else if matches!(receiver_ty.non_null(), Ty::Obj(_) | Ty::Instance(..)) {
            return err_help(
                span,
                "`delete` with a computed key needs an open map type",
                "use Record<string, T>, or delete a named optional property",
            );
        }
        if o.ty.is_nullable() && !optional {
            return err_help(
                span,
                format!("can't delete from this: it might be null ({})", o.ty.describe()),
                "check it first, or use `delete obj?.key`",
            );
        }
        // Js::delete: strict-mode [[Delete]] on Zero's value model.
        Ok(if optional {
            let tmp = self.tmp_var();
            Out::atom(
                format!(
                    "(\\Zero\\Js::isNullish({tmp} = {}) ? true : \\Zero\\Js::delete({tmp}, {key_php}))",
                    o.php
                ),
                Ty::Bool,
            )
        } else {
            Out::atom(format!("\\Zero\\Js::delete({}, {key_php})", o.php), Ty::Bool)
        })
    }

    fn no_field(&self, t: &Ty, prop: &str, span: Span) -> Diag {
        let mut d = Diag::new(span, format!("{} has no field `{prop}`", t.non_null().describe()));
        if let Ty::Obj(f) = t.non_null()
            && let Some(s) = suggest(prop, f.keys().map(String::as_str))
        {
            d = d.help(format!("did you mean `{s}`?"));
        }
        d
    }

    fn member(&mut self, obj: &Expr, prop: &str, optional: bool, prop_span: Span, span: Span) -> Result<Out> {
        if matches!(&obj.kind, ExprKind::Ident(n) if n == "super") {
            return self.super_member(prop, prop_span);
        }
        if prop.starts_with('#') {
            return self.private_read(obj, prop, optional, prop_span);
        }
        if let Some(out) = self.host_member(obj, prop, prop_span)? {
            return Ok(out);
        }
        let native_object = self.native_object_receiver(obj);
        // Namespaces: Math.PI etc.
        if let ExprKind::Ident(n) = &obj.kind
            && let Ok(Resolved::Global(g)) = self.resolve(n, obj.span)
        {
            return match (g, prop) {
                ("Math", "PI") => Ok(Out::atom("M_PI", Ty::Num)),
                ("Math", "E") => Ok(Out::atom("M_E", Ty::Num)),
                ("Number", "MAX_SAFE_INTEGER") => Ok(Out::atom("9007199254740991", Ty::Num)),
                ("Number", "MIN_SAFE_INTEGER") => Ok(Out::atom("-9007199254740991", Ty::Num)),
                ("Number", "isFinite") => Ok(number_is_finite_function()),
                ("globalThis", _) => match crate::eval::global_member(prop) {
                    crate::eval::GlobalMember::Absent => Ok(Out::atom("\\Zero\\Js::undefined()", Ty::Undefined)),
                    crate::eval::GlobalMember::Provided("NaN") => Ok(Out::atom("NAN", Ty::Num)),
                    crate::eval::GlobalMember::Provided("Infinity") => Ok(Out::atom("INF", Ty::Num)),
                    crate::eval::GlobalMember::Provided(g) => err_help(
                        span,
                        format!("`globalThis.{g}` can't be used as a value"),
                        format!("use `{g}` directly; `typeof globalThis.{g}` is resolved at compile time"),
                    ),
                    crate::eval::GlobalMember::Refused => Err(crate::eval::refused_global_member(prop, prop_span)),
                },
                ("Symbol", name) if WELL_KNOWN_SYMBOLS.contains(&name) => {
                    self.require_javascript_symbols(span)?;
                    Ok(Out::atom(
                        format!("\\Zero\\JsSymbol::wellKnown({})", php_str(name)),
                        Ty::Dynamic,
                    ))
                }
                (
                    "Math",
                    method @ ("abs" | "floor" | "ceil" | "trunc" | "round" | "min" | "max" | "pow" | "sqrt" | "sign"),
                ) if self.javascript_module(self.fx.as_ref().expect("fn").module) => Ok(Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicGet(\\Zero\\Js::dynamicNamespace('Math'), {})",
                        php_str(method)
                    ),
                    Ty::Dynamic,
                )),
                ("AbortSignal", method @ ("any" | "abort" | "timeout"))
                    if self.javascript_module(self.fx.as_ref().expect("fn").module) =>
                {
                    if method == "timeout" {
                        self.uses("timers", "AbortSignal.timeout", span);
                    }
                    Ok(Out::atom(
                        format!(
                            "\\Zero\\Js::dynamicGet(\\Zero\\Js::dynamicNamespace('AbortSignal'), {})",
                            php_str(method)
                        ),
                        Ty::Dynamic,
                    ))
                }
                _ => err(span, format!("`{g}.{prop}` isn't supported")),
            };
        }
        if let Some((path, value, binding)) = self.static_member(obj, prop, prop_span)? {
            return self.captured_use(&path, value, &binding, span, false, true);
        }
        let o = self.expr(obj)?;
        if matches!(o.ty.non_null(), Ty::Instance(..) | Ty::Class(_)) {
            if optional {
                return err(
                    span,
                    "optional class member reads are unsupported; check the instance first",
                );
            }
            return self.class_member(o, prop, prop_span);
        }
        match &o.ty {
            Ty::Db if prop == "query" => return Ok(Out::atom("\\Zero\\db()", Ty::DbQuery)),
            Ty::Db if prop == "globals" => return Ok(Out::atom("\\Zero\\db()", Ty::DbGlobals)),
            Ty::DbGlobals => {
                return if self.schema.globals.contains_key(prop) {
                    Ok(Out::atom("\\Zero\\db()", Ty::DbGlobal(prop.to_string())))
                } else {
                    let mut d = Diag::new(
                        prop_span,
                        format!("`db.globals.{prop}` doesn't match a global in `globals`"),
                    );
                    if let Some(s) = suggest(prop, self.schema.globals.keys().map(String::as_str)) {
                        d = d.help(format!("did you mean `{s}`?"));
                    }
                    Err(d)
                };
            }
            Ty::DbQuery => {
                return match self.schema.by_key(prop) {
                    Some(c) => Ok(Out::atom("\\Zero\\db()", Ty::QueryColl(c.table.clone()))),
                    None => {
                        let mut d = Diag::new(
                            prop_span,
                            format!(
                                "`db.query.{prop}` doesn't match a registered collection, table, or user projection"
                            ),
                        );
                        let keys: Vec<&str> = self.schema.collections.values().map(|c| c.key.as_str()).collect();
                        if let Some(s) = suggest(prop, keys) {
                            d = d.help(format!("did you mean `{s}`?"));
                        }
                        Err(d)
                    }
                };
            }
            _ => {}
        }
        let mut o = self.finish_value(o, obj.span)?;
        if o.ty == Ty::Arguments && prop == "length" {
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, 'length')", o.php),
                Ty::Num,
            ));
        }
        if o.ty == Ty::Dynamic
            || (self.javascript_module(self.fx.as_ref().expect("fn").module)
                && prop == "slice"
                && matches!(
                    o.ty.non_null(),
                    Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_)
                ))
        {
            return Ok(self.javascript_get(o, Out::atom(php_str(prop), Ty::Str), optional));
        }
        if let Some(path) = self.path_of(obj)
            && let Some(t) = self.narrowed_path(&path)
        {
            o.ty = t;
        }
        let base = o.ty.non_null();
        if o.ty.is_nullable() && !optional {
            return err_help(
                span,
                format!("can't read `{prop}`: this might be null ({})", o.ty.describe()),
                "check it first, or use `?.`",
            );
        }
        let suffix_null = |t: Ty| {
            if optional && o.ty.is_nullable() {
                Ty::union([t, Ty::Undefined])
            } else {
                t
            }
        };
        // `x?.length` on a nullable value: evaluate once, short-circuit to undefined.
        let guarded = |c: &mut Self, f: &dyn Fn(&str) -> String| {
            if optional && o.ty.is_nullable() {
                let tmp = c.tmp_var();
                format!(
                    "(\\Zero\\Js::isNullish({tmp} = {}) ? \\Zero\\Js::undefined() : {})",
                    o.php,
                    f(&tmp)
                )
            } else {
                f(&o.php)
            }
        };
        Ok(match (&base, prop) {
            (t, "length") if t.is_str() => {
                let php = guarded(self, &|x| format!("\\Zero\\Js::length({x})"));
                Out::atom(php, suffix_null(Ty::Num))
            }
            (Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_), "length") => {
                let php = guarded(self, &|x| format!("count(({x})->a)"));
                Out::atom(php, suffix_null(Ty::Num))
            }
            // `TemplateStringsArray.raw`: a tagged template's strings carry their source text.
            // The annotation resolves to a non-empty string array; a plain array has no `raw`,
            // so the checked read throws instead of returning a wrong value.
            (Ty::NonEmptyArray(element) | Ty::Array(element), "raw") if **element == Ty::Str => {
                let php = guarded(self, &|x| format!("\\Zero\\Js::templateRaw({x})"));
                Out::atom(php, suffix_null(Ty::Array(Box::new(Ty::Str))))
            }
            (Ty::Error, "cause") => {
                let php = guarded(self, &|x| format!("\\Zero\\Js::dynamicGet({x}, 'cause')"));
                Out::atom(php, Ty::Unknown)
            }
            (Ty::Error, "message" | "name" | "stack") => {
                // A caught value may be an Error subclass instance, so read through JavaScript lookup.
                let php = guarded(self, &|x| {
                    format!("\\Zero\\Js::str(\\Zero\\Js::dynamicGet({x}, {}))", php_str(prop))
                });
                Out::atom(php, suffix_null(Ty::Str))
            }
            (Ty::Regex, "source" | "flags") => {
                let php = guarded(self, &|x| format!("({x})->{prop}"));
                Out::atom(php, suffix_null(Ty::Str))
            }
            (Ty::AbortController, "signal") => {
                let php = guarded(self, &|x| format!("({x})->signal"));
                Out::atom(php, suffix_null(Ty::AbortSignal))
            }
            (Ty::AbortSignal, "aborted") => {
                let php = guarded(self, &|x| format!("({x})->aborted"));
                Out::atom(php, suffix_null(Ty::Bool))
            }
            (Ty::AbortSignal, "reason") => {
                let php = guarded(self, &|x| format!("({x})->reason"));
                Out::atom(php, Ty::Unknown)
            }
            (Ty::Url, "searchParams") => {
                let php = guarded(self, &|x| format!("({x})->get('searchParams')"));
                Out::atom(php, suffix_null(Ty::UrlParams))
            }
            (Ty::Url, p) if URL_PROPERTIES.contains(&p) => {
                let php = guarded(self, &|x| format!("({x})->get({})", php_str(p)));
                Out::atom(php, suffix_null(Ty::Str))
            }
            (Ty::UrlParams, "size") => {
                let php = guarded(self, &|x| format!("({x})->size()"));
                Out::atom(php, suffix_null(Ty::Num))
            }
            (Ty::AbortController | Ty::AbortSignal | Ty::Url | Ty::UrlParams, _) => {
                return err(prop_span, format!("{} has no property `{prop}`", base.describe()));
            }
            (Ty::MapOf(..) | Ty::SetOf(_), "size") => {
                let php = guarded(self, &|x| format!("({x})->size()"));
                Out::atom(php, suffix_null(Ty::Num))
            }
            (Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_), _) => {
                return err(prop_span, format!("{} has no property `{prop}`", base.describe()));
            }
            (Ty::Host(h), _) => self.host_property(h, &o, prop, prop_span)?,
            (Ty::Obj(_) | Ty::Map(_) | Ty::Union(_), _) => {
                let Some((ft, opt_field)) = base.field(prop) else {
                    if matches!(base, Ty::Union(_)) {
                        return err(prop_span, format!("{} has no property `{prop}`", base.describe()));
                    }
                    return Err(self.no_field(&o.ty, prop, prop_span));
                };
                let map = matches!(base, Ty::Map(_));
                let php = guarded(self, &|x| {
                    if native_object && !map && !opt_field {
                        // Present non-null values skip the checked read; absent keys keep dataGet's semantics.
                        format!("({x}->p[{0}] ?? \\Zero\\Js::dataGet({x}, {0}))", php_str(prop))
                    } else {
                        format!("\\Zero\\Js::dataGet({x}, {})", php_str(prop))
                    }
                });
                let ft = if map || opt_field {
                    Ty::union([ft, Ty::Undefined])
                } else {
                    ft
                };
                let place = o.place.as_ref().map(|p| format!("{p}->p[{}]", php_str(prop)));
                let ty = self
                    .path_of(obj)
                    .and_then(|path| self.narrowed_path(&format!("{path}.{prop}")))
                    .unwrap_or_else(|| suffix_null(ft));
                Out {
                    php,
                    ty,
                    place,
                    atomic: true,
                    helper: None,
                }
            }
            (Ty::Unknown, _) => {
                return err_help(
                    span,
                    format!("can't read `{prop}` of a value with unknown shape"),
                    "give it a type, e.g. `json().$type<{ ... }>()`",
                );
            }
            _ => return err(prop_span, format!("{} has no property `{prop}`", base.describe())),
        })
    }

    fn index(&mut self, obj: &Expr, index: &Expr, optional: bool, span: Span) -> Result<Out> {
        if let Some(out) = self.host_index(obj, index)? {
            return Ok(out);
        }
        let o = self.expr(obj)?;
        let mut o = self.finish_value(o, obj.span)?;
        let i = self.expr(index)?;
        if o.ty == Ty::Arguments {
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
                Ty::Unknown,
            ));
        }
        if let Ty::NonEmptyArray(element) = &o.ty
            && matches!(index.kind, ExprKind::Num(n) if n == 0.0)
        {
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
                (**element).clone(),
            ));
        }
        if o.ty == Ty::Dynamic {
            return Ok(self.javascript_get(o, i, optional));
        }
        if o.ty.is_nullable() && !optional {
            return err_help(
                span,
                format!("can't index this: it might be null ({})", o.ty.describe()),
                "check it first, or use `?.[]`",
            );
        }
        let base = o.ty.non_null();
        let receiver = o.php.clone();
        let guard = optional && o.ty.is_nullable();
        if guard {
            o.php = self.tmp_var();
            o.place = None;
        }
        let wrap = |t: Ty| {
            if optional && o.ty.is_nullable() {
                Ty::union([t, Ty::Undefined])
            } else {
                t
            }
        };
        let mut out = match (&base, &i.ty) {
            (Ty::Array(t) | Ty::NonEmptyArray(t), it) if it.is_num() => Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
                wrap(Ty::union([(**t).clone(), Ty::Undefined])),
            ),
            (Ty::Tuple(ts) | Ty::ArrayLiteral(ts), _) => match &index.kind {
                ExprKind::Num(n) if n.fract() == 0.0 && *n >= 0.0 => {
                    let t = ts.get(*n as usize).cloned().unwrap_or(Ty::Undefined);
                    let place = o.place.as_ref().map(|p| format!("{p}->a[{}]", *n as usize));
                    Out {
                        php: format!("\\Zero\\Js::dynamicGet({}, {})", o.php, *n as usize),
                        ty: wrap(Ty::union([t, Ty::Undefined])),
                        place,
                        atomic: true,
                        helper: None,
                    }
                }
                _ if i.ty.is_num() => Out::atom(
                    format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
                    wrap(Ty::union(ts.iter().cloned().chain([Ty::Undefined]))),
                ),
                _ => return err(index.span, "tuples are indexed by number"),
            },
            (Ty::Map(t), key_ty) if key_ty.is_str() => Out::atom(
                format!("\\Zero\\Js::dataGet({}, {})", o.php, i.php),
                wrap(Ty::union([(**t).clone(), Ty::Undefined])),
            ),
            (Ty::Obj(fields), key_ty) if key_ty.is_str() => {
                if let ExprKind::Str(k) = &index.kind {
                    return self.member(obj, k, optional, index.span, span);
                }
                let keys = match key_ty {
                    Ty::Union(parts) => parts.clone(),
                    t => vec![t.clone()],
                };
                let mut values = Vec::new();
                for key in keys {
                    let name = match key {
                        Ty::StrLit(k) | Ty::FreshStrLit(k) => k,
                        _ => {
                            return err_help(
                                index.span,
                                "dynamic keys on an object need a finite key union",
                                "use `keyof` on a finite object, a `Record<string, T>` or a `switch`",
                            );
                        }
                    };
                    let Some((t, opt)) = fields.get(&name) else {
                        return err(index.span, format!("this object has no property `{name}`"));
                    };
                    values.push(if *opt {
                        Ty::union([t.clone(), Ty::Undefined])
                    } else {
                        t.clone()
                    });
                }
                Out::atom(
                    format!("\\Zero\\Js::dataGet({}, {})", o.php, i.php),
                    wrap(Ty::union(values)),
                )
            }
            (s, it) if s.is_str() && it.is_num() => Out::atom(
                format!("\\Zero\\Js::strIndex({}, {})", o.php, i.php),
                wrap(Ty::union([Ty::Str, Ty::Undefined])),
            ),
            _ => {
                return err(
                    span,
                    format!("can't index {} with {}", base.describe(), i.ty.describe()),
                );
            }
        };
        if guard {
            out.php = format!(
                "(\\Zero\\Js::isNullish({} = {receiver}) ? \\Zero\\Js::undefined() : {})",
                o.php, out.php
            );
            out.place = None;
        }
        Ok(out)
    }

    fn await_expr(&mut self, x: &Expr, span: Span) -> Result<Out> {
        if !self.fx().is_async {
            return err_help(
                span,
                "`await` only works in async functions",
                "mark the function `async`",
            );
        }
        let v = self.expr(x)?;
        let module = self.fx().module;
        // Zero's own typed async calls (fetch, database builders, typed helpers) block, as in
        // TypeScript; any other awaited value from checked JavaScript goes through the job queue.
        if self.javascript_module(module) && !matches!(v.ty, Ty::Promise(_) | Ty::Builder(_)) {
            let v = self.finish_value(v, x.span)?;
            self.uses_jobs = true;
            return Ok(Out::atom(
                format!(
                    "\\Zero\\JsJobs::await({}, {})",
                    v.php,
                    php_str(&crate::diag::site(span))
                ),
                Ty::Dynamic,
            ));
        }
        Ok(match v.ty {
            Ty::Promise(t) => Out { ty: *t, ..v },
            Ty::Builder(b) => Out::new(b.php(), b.result(self.schema)),
            // A call into imported JavaScript may return a promise; awaiting it drives the job queue.
            Ty::Dynamic => {
                self.uses_jobs = true;
                Out::atom(
                    format!(
                        "\\Zero\\JsJobs::await({}, {})",
                        v.php,
                        php_str(&crate::diag::site(span))
                    ),
                    Ty::Dynamic,
                )
            }
            other => {
                return err_help(
                    span,
                    format!("awaiting {} does nothing", other.describe()),
                    "only await Zero database calls and your own async functions",
                );
            }
        })
    }

    fn new_expr(&mut self, callee: &Expr, args: &[ArrEl], type_args: &[TyAnn], span: Span) -> Result<Out> {
        if let Some(out) = self.host_new(callee, args, span)? {
            return Ok(out);
        }
        if let ExprKind::Ident(name) = &callee.kind
            && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            && (!matches!(name.as_str(), "Map" | "Set" | "WeakMap" | "WeakSet")
                || self.javascript_module(self.fx.as_ref().expect("fn").module))
        {
            self.builtin_args("constructor", name, args, span)?;
        }
        if let ExprKind::Ident(name) = &callee.kind
            && matches!(name.as_str(), "Map" | "Set")
            && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            && !self.javascript_module(self.fx.as_ref().expect("fn").module)
        {
            return self.typed_collection(name == "Map", args, type_args, span);
        }
        if let ExprKind::Ident(name) = &callee.kind
            && matches!(name.as_str(), "URL" | "URLSearchParams")
            && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            && !self.javascript_module(self.fx.as_ref().expect("fn").module)
        {
            return self.typed_url(name == "URL", args, span);
        }
        if let ExprKind::Ident(name) = &callee.kind
            && name == "AbortController"
            && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            && !self.javascript_module(self.fx.as_ref().expect("fn").module)
        {
            if !args.is_empty() {
                return err(span, "`new AbortController()` takes no arguments");
            }
            return Ok(Out::atom("(new \\Zero\\JsAbortController())", Ty::AbortController));
        }
        if let ExprKind::Ident(name) = &callee.kind
            && name == "RegExp"
            && matches!(self.resolve(name, callee.span)?, Resolved::Global("RegExp"))
            && !self.javascript_module(self.fx.as_ref().expect("fn").module)
        {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicConstruct('RegExp', {argv})"),
                Ty::Regex,
            ));
        }
        if self.javascript_module(self.fx.as_ref().expect("fn").module)
            && let ExprKind::Ident(name) = &callee.kind
            && matches!(
                name.as_str(),
                "Promise" | "ReadableStream" | "WritableStream" | "TransformStream"
            )
            && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
        {
            let argv = self.javascript_args(args)?;
            self.uses_jobs = true;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicConstruct({}, {argv})", php_str(name)),
                Ty::Dynamic,
            ));
        }
        if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            if let ExprKind::Ident(name) = &callee.kind
                && matches!(
                    name.as_str(),
                    "Set"
                        | "Map"
                        | "WeakMap"
                        | "WeakSet"
                        | "Date"
                        | "RegExp"
                        | "URL"
                        | "URLSearchParams"
                        | "AbortController"
                )
                && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            {
                if name == "Date" {
                    crate::builtins::check_date_literal(args, span)?;
                }
                let argv = self.javascript_args(args)?;
                return Ok(Out::atom(
                    format!("\\Zero\\Js::dynamicConstruct({}, {argv})", php_str(name)),
                    Ty::Dynamic,
                ));
            }
            if !matches!(callee.kind, ExprKind::Ident(_)) {
                let ctor = self.expr(callee)?;
                if ctor.ty == Ty::Dynamic {
                    let argv = self.javascript_args(args)?;
                    return Ok(Out::atom(
                        format!("\\Zero\\Js::dynamicConstruct({}, {argv})", ctor.php),
                        Ty::Dynamic,
                    ));
                }
            }
        }
        if let ExprKind::Ident(n) = &callee.kind
            && let Resolved::Value(Value::Class(class), _) = self.resolve(n, callee.span)?
        {
            return self.construct_class(&class, args, span);
        }
        let ExprKind::Ident(n) = &callee.kind else {
            return err(span, "`new` only works with Date, Error, and HttpError");
        };
        match self.resolve(n, callee.span)? {
            Resolved::Global("Date") => {
                crate::builtins::check_date_literal(args, span)?;
                let argv = self.plain_args(args)?;
                Ok(match argv.as_slice() {
                    [] => Out::atom("new \\Zero\\JsDate(\\Zero\\Js::now())", Ty::Date),
                    [a] => Out::atom(format!("new \\Zero\\JsDate(\\Zero\\Js::date({}))", a.php), Ty::Date),
                    _ => {
                        return err(
                            span,
                            "`new Date(y, m, d)` isn't supported; use an ISO string or a number",
                        );
                    }
                })
            }
            Resolved::Global(g @ ("Error" | "TypeError" | "RangeError")) => self.error_constructor(g, args),
            Resolved::Value(Value::Builtin(Builtin::HttpError), _) => err(
                span,
                "create HttpError directly in a `throw`: `throw new HttpError(404, \"not_found\", \"...\")`",
            ),
            _ => err_help(
                span,
                format!("`new {n}` isn't supported"),
                if n == "Map" {
                    "use Record<string, T> for string keys, or an array for simple membership; these do not provide Map key identity"
                } else if matches!(n.as_str(), "WeakMap" | "WeakSet") {
                    "use it from imported JavaScript, or key a Record<string, T> by a string id"
                } else {
                    "use plain objects and arrays, or a supported named class"
                },
            ),
        }
    }

    fn collection_method(&mut self, recv: &Out, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        let r = recv.wrapped();
        let (key, value, set) = match &recv.ty.non_null() {
            Ty::MapOf(k, v) => ((**k).clone(), (**v).clone(), false),
            Ty::SetOf(t) => ((**t).clone(), (**t).clone(), true),
            Ty::Iter(_) => {
                return err_help(
                    m_span,
                    format!("calling `{m}()` on an iterator isn't supported in typed code"),
                    "iterate with for…of, spread or Array.from",
                );
            }
            _ => unreachable!("collection receiver"),
        };
        let arg = |this: &mut Self, i: usize, want: &Ty, what: &str| -> Result<Out> {
            let x = this.arg(args, i, span, what)?;
            let v = this.expr(x)?;
            let v = this.finish_value(v, x.span)?;
            if !assignable(&v.ty, want) {
                return err_help(
                    x.span,
                    format!("{} isn't assignable to {}", v.ty.describe(), want.describe()),
                    "pass a value of the collection's declared type, or widen its type arguments",
                );
            }
            Ok(v)
        };
        let iter = |kind: &str, el: Ty| {
            Out::atom(
                format!(
                    "(new \\Zero\\JsIterator({r}->{kind}Iterator(), {}))",
                    php_str(if set { "Set Iterator" } else { "Map Iterator" })
                ),
                Ty::Iter(Box::new(el)),
            )
        };
        Ok(match (set, m) {
            (false, "get") => {
                let k = arg(self, 0, &key, "a key")?;
                Out::atom(format!("{r}->get({})", k.php), Ty::union([value, Ty::Undefined]))
            }
            (false, "set") => {
                let k = arg(self, 0, &key, "a key")?;
                let v = arg(self, 1, &value, "a value")?;
                Out::atom(format!("{r}->set({}, {})", k.php, v.php), recv.ty.non_null())
            }
            (true, "add") => {
                let k = arg(self, 0, &key, "a value")?;
                Out::atom(format!("{r}->add({})", k.php), recv.ty.non_null())
            }
            (_, "has" | "delete") => {
                // Like TypeScript, a probe must have the key type; SameValueZero then decides.
                let k = arg(self, 0, &key, "a key")?;
                Out::atom(format!("{r}->{m}({})", k.php), Ty::Bool)
            }
            (_, "clear") => Out::atom(format!("\\Zero\\Js::clearCollection({r})"), Ty::Undefined),
            (_, "forEach") => {
                let f = self.arg(args, 0, span, "a function")?;
                let cb = self.callback(f, &[value, key, recv.ty.non_null()])?;
                Out::atom(format!("\\Zero\\Js::collectionForEach({r}, {})", cb.php), Ty::Undefined)
            }
            (false, "keys") => iter("keys", key),
            (_, "values") => iter("values", value),
            (true, "keys") => iter("values", key),
            (_, "entries") => iter("entries", Ty::Tuple(vec![key, value])),
            _ => return err(m_span, format!("{} has no method `{m}`", recv.ty.describe())),
        })
    }

    fn abort_method(&mut self, recv: &Out, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        let r = recv.wrapped();
        Ok(match (&recv.ty.non_null(), m) {
            (Ty::AbortController, "abort") => {
                let reason = match args.first() {
                    None => "\\Zero\\Js::undefined()".to_string(),
                    Some(ArrEl::Item(x)) => {
                        let v = self.expr(x)?;
                        self.finish_value(v, x.span)?.php
                    }
                    Some(ArrEl::Spread(x)) => return err(x.span, "spread arguments aren't supported here"),
                };
                Out::atom(format!("{r}->abort({reason})"), Ty::Undefined)
            }
            (Ty::AbortSignal, "throwIfAborted") => Out::atom(format!("{r}->throwIfAborted()"), Ty::Undefined),
            (Ty::AbortSignal, "addEventListener" | "removeEventListener") => {
                let kind = self.arg(args, 0, span, "an event type")?;
                if !matches!(&kind.kind, ExprKind::Str(t) if t == "abort") {
                    return err_help(kind.span, "AbortSignal only dispatches \"abort\"", "pass \"abort\"");
                }
                let f = self.arg(args, 1, span, "a listener")?;
                let cb = self.callback(f, &[Ty::Unknown])?;
                let options = match args.get(2) {
                    None => "\\Zero\\Js::undefined()".to_string(),
                    Some(ArrEl::Item(x)) => {
                        let v = self.expr(x)?;
                        self.finish_value(v, x.span)?.php
                    }
                    Some(ArrEl::Spread(x)) => return err(x.span, "spread arguments aren't supported here"),
                };
                Out::atom(format!("{r}->{m}('abort', {}, {options})", cb.php), Ty::Undefined)
            }
            _ => return err(m_span, format!("{} has no method `{m}`", recv.ty.describe())),
        })
    }

    /// `new URL(url, base?)` and `new URLSearchParams(init?)` in typed code.
    fn typed_url(&mut self, url: bool, args: &[ArrEl], span: Span) -> Result<Out> {
        let mut argv = Vec::new();
        for (i, a) in args.iter().enumerate() {
            let ArrEl::Item(x) = a else {
                return err(span, "spread arguments aren't supported here");
            };
            let v = self.expr(x)?;
            let v = self.finish_value(v, x.span)?;
            let ok = if url {
                v.ty.is_str() || v.ty == Ty::Url || (i == 1 && v.ty == Ty::Undefined)
            } else {
                v.ty.is_str()
                    || v.ty == Ty::UrlParams
                    || match v.ty.non_null() {
                        Ty::Obj(fields) => fields.values().all(|(t, optional)| !optional && t.is_str()),
                        Ty::Map(t) => t.is_str(),
                        _ => false,
                    }
                    || self.iterable_element(&v.ty).is_some_and(|el| {
                        let pair = |t: &Ty| {
                            matches!(t, Ty::Tuple(ts) | Ty::ArrayLiteral(ts) if ts.len() == 2 && ts.iter().all(Ty::is_str))
                        };
                        match &el {
                            Ty::Union(parts) => parts.iter().all(pair),
                            t => pair(t),
                        }
                    })
            };
            if !ok {
                return err_help(
                    x.span,
                    format!(
                        "`new {}` doesn't take {}",
                        if url { "URL" } else { "URLSearchParams" },
                        v.ty.describe()
                    ),
                    if url {
                        "pass a string or URL"
                    } else {
                        "pass a query string, [name, value] string pairs, or a Record<string, string>"
                    },
                );
            }
            argv.push(v.php);
        }
        if url && !(1..=2).contains(&argv.len()) || !url && argv.len() > 1 {
            return err(span, "wrong number of arguments");
        }
        let class = if url { "JsUrl" } else { "JsUrlSearchParams" };
        Ok(Out::atom(
            format!("\\Zero\\{class}::construct({})", argv.join(", ")),
            if url { Ty::Url } else { Ty::UrlParams },
        ))
    }

    fn url_method(&mut self, recv: &Out, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        let r = recv.wrapped();
        let mut strs = Vec::new();
        for a in args {
            let ArrEl::Item(x) = a else {
                return err(span, "spread arguments aren't supported here");
            };
            if m == "forEach" {
                break;
            }
            let v = self.expr(x)?;
            let v = self.finish_value(v, x.span)?;
            if !v.ty.is_str() {
                return err_help(
                    x.span,
                    format!("`{m}` needs strings, not {}", v.ty.describe()),
                    "convert with String(...)",
                );
            }
            strs.push(v.php);
        }
        let call = |ty: Ty| Out::atom(format!("{r}->{m}({})", strs.join(", ")), ty);
        let iter = |kind: &str, el: Ty| {
            Out::atom(
                format!("(new \\Zero\\JsIterator({r}->{kind}Iterator(), 'URLSearchParams Iterator'))"),
                Ty::Iter(Box::new(el)),
            )
        };
        Ok(match (&recv.ty.non_null(), m) {
            (Ty::Url, "toString" | "toJSON") => call(Ty::Str),
            (Ty::UrlParams, "toString") => call(Ty::Str),
            (Ty::UrlParams, "get") => call(Ty::Str.nullable()),
            (Ty::UrlParams, "getAll") => call(Ty::Array(Box::new(Ty::Str))),
            (Ty::UrlParams, "has") => call(Ty::Bool),
            (Ty::UrlParams, "append" | "set" | "delete" | "sort") => call(Ty::Undefined),
            (Ty::UrlParams, "keys" | "values") => iter(m, Ty::Str),
            (Ty::UrlParams, "entries") => iter("entries", Ty::Tuple(vec![Ty::Str, Ty::Str])),
            (Ty::UrlParams, "forEach") => {
                let f = self.arg(args, 0, span, "a function")?;
                let cb = self.callback(f, &[Ty::Str, Ty::Str, Ty::UrlParams])?;
                Out::atom(format!("{r}->forEach({})", cb.php), Ty::Undefined)
            }
            _ => return err(m_span, format!("{} has no method `{m}`", recv.ty.describe())),
        })
    }

    /// `new Map<K, V>(entries?)` / `new Set<T>(values?)` in typed code. Type arguments come from the
    /// call or are inferred from a non-empty source; an empty `new Map()` needs them.
    fn typed_collection(&mut self, map: bool, args: &[ArrEl], type_args: &[TyAnn], span: Span) -> Result<Out> {
        let what = if map { "Map" } else { "Set" };
        if args.len() > 1 {
            return err(span, format!("`new {what}` takes at most one iterable"));
        }
        let module = self.fx().module;
        let mut declared = Vec::new();
        for a in type_args {
            declared.push(self.resolve_runtime_ann(a, module)?);
        }
        if !declared.is_empty() && declared.len() != if map { 2 } else { 1 } {
            return err(
                span,
                format!(
                    "`{what}` takes {} type argument{}",
                    if map { 2 } else { 1 },
                    if map { "s" } else { "" }
                ),
            );
        }
        let source = match args.first() {
            None => None,
            Some(ArrEl::Spread(x)) => return err(x.span, "spread arguments aren't supported here"),
            Some(ArrEl::Item(x)) => {
                let v = self.expr(x)?;
                let v = self.finish_value(v, x.span)?;
                if matches!(v.ty, Ty::Null | Ty::Undefined) {
                    None
                } else {
                    let el = self.iterable_element(&v.ty).ok_or_else(|| {
                        Diag::new(
                            x.span,
                            format!("`new {what}` needs an iterable, not {}", v.ty.describe()),
                        )
                    })?;
                    Some((v, el, x.span))
                }
            }
        };
        fn entry(el: &Ty) -> Option<(Ty, Ty)> {
            match el {
                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) if ts.len() >= 2 => Some((ts[0].clone(), ts[1].clone())),
                Ty::Array(t) | Ty::NonEmptyArray(t) => Some(((**t).clone(), (**t).clone())),
                Ty::Union(parts) => {
                    let pairs: Option<Vec<_>> = parts.iter().map(entry).collect();
                    let (ks, vs): (Vec<_>, Vec<_>) = pairs?.into_iter().unzip();
                    Some((Ty::union(ks), Ty::union(vs)))
                }
                _ => None,
            }
        }
        let elem = |el: &Ty, at: Span| -> Result<(Ty, Ty)> {
            entry(el).ok_or_else(|| {
                Diag::new(
                    at,
                    format!("Map entries must be [key, value] pairs, not {}", el.describe()),
                )
            })
        };
        let ty = match (map, declared.as_slice(), &source) {
            (true, [k, v], src) => {
                if let Some((_, el, at)) = src {
                    let (ek, ev) = elem(el, *at)?;
                    if !assignable(&ek, k) || !assignable(&ev, v) {
                        return err(
                            *at,
                            format!("these entries don't fit Map<{}, {}>", k.describe(), v.describe()),
                        );
                    }
                }
                Ty::MapOf(Box::new(k.clone()), Box::new(v.clone()))
            }
            (false, [t], src) => {
                if let Some((_, el, at)) = src
                    && !assignable(el, t)
                {
                    return err(*at, format!("these values don't fit Set<{}>", t.describe()));
                }
                Ty::SetOf(Box::new(t.clone()))
            }
            (true, [], Some((_, el, at))) => {
                let (k, v) = elem(el, *at)?;
                Ty::MapOf(Box::new(widen_fresh_literals(&k)), Box::new(widen_fresh_literals(&v)))
            }
            (false, [], Some((_, el, _))) => Ty::SetOf(Box::new(widen_fresh_literals(el))),
            _ => {
                return err_help(
                    span,
                    format!("an empty `new {what}()` needs type arguments"),
                    if map {
                        "write `new Map<string, number>()`"
                    } else {
                        "write `new Set<string>()`"
                    },
                );
            }
        };
        let php = match source {
            Some((v, _, _)) => format!("\\Zero\\Js::new{what}({})", v.php),
            None => format!("\\Zero\\Js::new{what}()"),
        };
        Ok(Out::atom(php, ty))
    }

    /// The element type `for…of`, spread and `Array.from` see for a typed iterable.
    fn iterable_element(&self, t: &Ty) -> Option<Ty> {
        Some(match t {
            Ty::Array(t) | Ty::NonEmptyArray(t) => (**t).clone(),
            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts.clone()),
            t if t.is_str() => Ty::Str,
            Ty::MapOf(k, v) => Ty::Tuple(vec![(**k).clone(), (**v).clone()]),
            Ty::SetOf(t) | Ty::Iter(t) => (**t).clone(),
            Ty::UrlParams => Ty::Tuple(vec![Ty::Str, Ty::Str]),
            _ => return None,
        })
    }

    /// The PHP value model erases undefined into null. Only literal string-search positions are
    /// accepted: runtime positions can hide that distinction through assertions, aliases or calls.
    fn position_args(&mut self, args: &[ArrEl], position: usize, m: &str) -> Result<Vec<Out>> {
        let mut argv = self.plain_args(args)?;
        let Some(ArrEl::Item(p)) = args.get(position) else {
            return Ok(argv);
        };
        let mut e: &Expr = p;
        while let ExprKind::As(x, _) | ExprKind::NonNull(x) = &e.kind {
            e = x;
        }
        match &e.kind {
            ExprKind::Undefined => argv.truncate(position),
            ExprKind::Null => argv[position] = Out::atom("0", Ty::Num),
            _ if literal_position(e) => {}
            _ => {
                return err_help(
                    p.span,
                    format!("`{m}()`'s position must be a literal number, null or undefined"),
                    "use a literal position (for example `0` or `-1`), or leave the argument out",
                );
            }
        }
        Ok(argv)
    }

    fn plain_args(&mut self, args: &[ArrEl]) -> Result<Vec<Out>> {
        let mut out = Vec::new();
        for a in args {
            match a {
                ArrEl::Item(x) => {
                    let v = self.expr(x)?;
                    out.push(self.finish_value(v, x.span)?);
                }
                ArrEl::Spread(x) => return err(x.span, "spread arguments aren't supported in this call"),
            }
        }
        Ok(out)
    }

    fn arg<'e>(&self, args: &'e [ArrEl], i: usize, span: Span, what: &str) -> Result<&'e Expr> {
        match args.get(i) {
            Some(ArrEl::Item(x)) => Ok(x),
            Some(ArrEl::Spread(x)) => err(x.span, "spread arguments aren't supported in this call"),
            None => err(span, format!("missing {what}")),
        }
    }

    /// Numeric updates broaden read/return inference, while authored parameter
    /// and assignment constraints still govern which values can be supplied.
    fn argument_constraint(&mut self, e: &Expr) -> Option<Ty> {
        match &e.kind {
            ExprKind::Ident(name) => match self.resolve(name, e.span).ok()? {
                Resolved::Local(local) => Some(local.constraint),
                _ => None,
            },
            ExprKind::Member {
                obj, prop, optional, ..
            } => {
                let receiver = self.argument_constraint(obj)?;
                let missing = *optional && receiver.is_nullable();
                let shape = receiver.non_null();
                shape.field(prop).map(|(ty, optional)| {
                    if optional || missing || matches!(shape, Ty::Map(_)) {
                        Ty::union([ty, Ty::Undefined])
                    } else {
                        ty
                    }
                })
            }
            ExprKind::Index { obj, index, optional } => {
                let receiver = self.argument_constraint(obj)?;
                let missing = *optional && receiver.is_nullable();
                match receiver.non_null() {
                    Ty::Array(ty) | Ty::NonEmptyArray(ty) | Ty::Map(ty) => Some(Ty::union([*ty, Ty::Undefined])),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => match &index.kind {
                        ExprKind::Num(n) if *n >= 0.0 && n.fract() == 0.0 => Some(Ty::union([
                            ts.get(*n as usize).cloned().unwrap_or(Ty::Undefined),
                            Ty::Undefined,
                        ])),
                        _ => Some(Ty::union(ts.into_iter().chain([Ty::Undefined]))),
                    },
                    Ty::Obj(fields) => match &index.kind {
                        ExprKind::Str(key) => fields.get(key).map(|(t, optional)| {
                            if *optional || missing {
                                Ty::union([t.clone(), Ty::Undefined])
                            } else {
                                t.clone()
                            }
                        }),
                        _ => None,
                    },
                    _ => None,
                }
            }
            ExprKind::NonNull(e) => self.argument_constraint(e).map(|ty| ty.non_null()),
            _ => None,
        }
    }

    fn call(&mut self, callee: &Expr, args: &[ArrEl], optional: bool, span: Span) -> Result<Out> {
        if let ExprKind::Member { obj, prop, .. } = &callee.kind
            && prop == "call"
            && let ExprKind::Member {
                obj: prototype, prop, ..
            } = &obj.kind
            && prop == "toString"
            && matches!(&prototype.kind, ExprKind::Object(props) if props.is_empty())
        {
            self.arg(args, 0, span, "a receiver")?;
            let argv = self.plain_args(args)?;
            let values = argv
                .iter()
                .map(|value| value.php.clone())
                .collect::<Vec<_>>()
                .join(", ");
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicObjectTag([{values}][0])"),
                Ty::Str,
            ));
        }
        if let ExprKind::Ident(name) = &callee.kind
            && let Some(out) = self.class_internal_call(name, args, span)?
        {
            return Ok(out);
        }
        if optional {
            return err(span, "optional calls (`f?.()`) aren't supported");
        }
        if let Some(out) = self.host_call(callee, args, span)? {
            return Ok(out);
        }
        if let Some(out) = self.jobs_call(callee, args, span)? {
            return Ok(out);
        }
        match &callee.kind {
            ExprKind::Member {
                obj,
                prop,
                optional: opt,
                prop_span,
            } => {
                if matches!(&obj.kind, ExprKind::Ident(n) if n == "super") {
                    return self.super_method(prop, args, span);
                }
                if prop.starts_with('#') {
                    return self.private_call(obj, prop, *opt, *prop_span, args, span);
                }
                // Static functions on globals: Math.max, Object.keys, ...
                if let ExprKind::Ident(n) = &obj.kind
                    && let Ok(Resolved::Global(g)) = self.resolve(n, obj.span)
                {
                    return self.static_call(g, prop, args, span);
                }
                // `ns.f(x)` on a build-time namespace calls the resolved function directly.
                // Zero refuses `this` outside classes, so the receiver is unobservable.
                if let Some((path, value, binding)) = self.static_member(obj, prop, *prop_span)? {
                    let f = self.captured_use(&path, value, &binding, callee.span, true, true)?;
                    return self.call_value(f, args, span, callee.span);
                }
                let recv = self.expr(obj)?;
                self.method_call(recv, obj, prop, *opt, *prop_span, args, span)
            }
            ExprKind::Index {
                obj,
                index,
                optional: opt,
            } => {
                let recv = self.expr(obj)?;
                if recv.ty == Ty::Dynamic || self.javascript_module(self.fx.as_ref().expect("fn").module) {
                    let recv = self.finish_value(recv, obj.span)?;
                    let key = self.expr(index)?;
                    let argv = self.javascript_args(args)?;
                    let receiver = if *opt { self.tmp_var() } else { recv.php.clone() };
                    let call = format!("(\\Zero\\Js::dynamicMethod({receiver}, {}))(...({argv})->a)", key.php);
                    return Ok(Out::atom(
                        if *opt {
                            format!(
                                "(\\Zero\\Js::isNullish({receiver} = {}) ? \\Zero\\Js::undefined() : {call})",
                                recv.php
                            )
                        } else {
                            call
                        },
                        Ty::Dynamic,
                    ));
                }
                let mut f = self.expr(callee)?;
                let key = self.expr(index)?;
                let receiver = if *opt && recv.ty.is_nullable() {
                    self.tmp_var()
                } else {
                    recv.php.clone()
                };
                // Retain the indexed callee's type checks, but bind its receiver before
                // evaluating arguments, just as for a direct data-property call.
                f.php = format!("\\Zero\\Js::dataMethod({receiver}, {})", key.php);
                f.helper = None;
                let mut out = self.call_value(f, args, span, callee.span)?;
                if *opt && recv.ty.is_nullable() {
                    out.php = format!(
                        "(\\Zero\\Js::isNullish({receiver} = {}) ? \\Zero\\Js::undefined() : {})",
                        recv.php, out.php
                    );
                }
                Ok(out)
            }
            ExprKind::Ident(n) => {
                let f = match self.resolve(n, callee.span)? {
                    Resolved::Global(g) => return self.global_call(g, args, span),
                    Resolved::Value(v @ Value::Closure(_), b) => {
                        self.captured_use(n, v, &b, callee.span, true, false)?
                    }
                    _ => self.ident(n, callee.span)?,
                };
                self.call_value(f, args, span, callee.span)
            }
            _ => {
                let f = self.expr(callee)?;
                self.call_value(f, args, span, callee.span)
            }
        }
    }

    // ---- jobs (§6.1, lane D) ------------------------------------------------------

    /// `once(key, fn)`, `jobs.<name>.enqueue(input, opts?)` and `env("NAME")`; None for any other call.
    fn jobs_call(&mut self, callee: &Expr, args: &[ArrEl], span: Span) -> Result<Option<Out>> {
        let builtin = |this: &mut Self, e: &Expr| -> Result<Option<Builtin>> {
            let ExprKind::Ident(n) = &e.kind else { return Ok(None) };
            Ok(match this.resolve(n, e.span)? {
                Resolved::Value(
                    Value::Builtin(
                        b @ (Builtin::Once
                        | Builtin::Jobs
                        | Builtin::Env
                        | Builtin::Mail
                        | Builtin::Abilities
                        | Builtin::WpFunction(_)),
                    ),
                    _,
                ) => Some(b),
                _ => None,
            })
        };
        match &callee.kind {
            ExprKind::Ident(_) => match builtin(self, callee)? {
                Some(Builtin::Once) => self.once_call(args, span).map(Some),
                Some(Builtin::WpFunction(name)) => self.wp_function_call(&name, args, span).map(Some),
                Some(Builtin::Env) => err_help(
                    callee.span,
                    "env() is a config reference for webhooks, signatures and upload credentials",
                    "read runtime variables in handlers with `process.env.NAME`",
                ),
                _ => Ok(None),
            },
            ExprKind::Member { obj, prop, .. }
                if matches!(&obj.kind, ExprKind::Ident(n) if n != "super")
                    && builtin(self, obj).ok().flatten() == Some(Builtin::Mail) =>
            {
                self.mail_call(prop, args, span).map(Some)
            }
            ExprKind::Member { obj, prop, .. }
                if matches!(&obj.kind, ExprKind::Ident(n) if n != "super")
                    && builtin(self, obj).ok().flatten() == Some(Builtin::Abilities) =>
            {
                self.abilities_call(prop, args, span).map(Some)
            }
            ExprKind::Member { obj, prop, .. } if prop == "enqueue" => match &obj.kind {
                ExprKind::Member {
                    obj: root, prop: job, ..
                } if builtin(self, root)? == Some(Builtin::Jobs) => {
                    self.enqueue_call(job, obj.span, args, span).map(Some)
                }
                _ => Ok(None),
            },
            _ => Ok(None),
        }
    }

    fn mail_call(&mut self, name: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if name != "send" {
            return err_help(
                span,
                format!("`mail` has no function `{name}`"),
                "use `mail.send(message)`",
            );
        }
        if self.fx().no_db {
            return err_help(
                span,
                "access rules can't send mail",
                "move it into a hook, action, route or job",
            );
        }
        if args.len() != 1 {
            return err_help(
                span,
                "mail.send() takes one message",
                "write mail.send({ to, subject, text })",
            );
        }
        let e = self.arg(args, 0, span, "a message")?;
        let v = self.expr(e)?;
        let v = self.finish_value(v, e.span)?;
        let address = |t: &Ty| {
            t.is_str() && !t.is_nullable()
                || matches!(t, Ty::Obj(f) if f.keys().all(|k| k == "email" || k == "name")
                    && f.get("email").is_some_and(|(t, o)| !o && t.is_str() && !t.is_nullable()))
        };
        let list = |t: &Ty| {
            address(t)
                || matches!(t, Ty::Array(x) | Ty::NonEmptyArray(x) if address(x))
                || matches!(t, Ty::ArrayLiteral(xs) | Ty::Tuple(xs) if xs.iter().all(address))
        };
        let optional = |t: &Ty, ok: &dyn Fn(&Ty) -> bool| t.all(|t| matches!(t, Ty::Null | Ty::Undefined) || ok(t));
        let ok = match &v.ty {
            Ty::Obj(f) => {
                f.keys().all(|k| {
                    [
                        "from", "to", "subject", "text", "html", "cc", "bcc", "replyTo", "headers",
                    ]
                    .contains(&k.as_str())
                }) && f.get("to").is_some_and(|(t, o)| !o && list(t))
                    && f.get("subject")
                        .is_some_and(|(t, o)| !o && t.is_str() && !t.is_nullable())
                    && (f.contains_key("text") || f.contains_key("html"))
                    && f.iter().all(|(k, (t, _))| match k.as_str() {
                        "to" | "subject" => true,
                        "text" | "html" => optional(t, &|t| t.is_str()),
                        "from" | "replyTo" => optional(t, &address),
                        "cc" | "bcc" => optional(t, &list),
                        "headers" => optional(t, &|t| matches!(t, Ty::Obj(_) | Ty::Map(_))),
                        _ => false,
                    })
            }
            _ => false,
        };
        if !ok {
            return err_help(
                e.span,
                format!(
                    "mail.send() needs {{ to, subject, text or html }}, not {}",
                    v.ty.describe()
                ),
                "addresses are strings or { email, name? }; to/cc/bcc may be lists",
            );
        }
        self.uses("mail", "send", span);
        Ok(Out::atom(
            format!("\\Zero\\Mail::send({})", v.php),
            Ty::Promise(Box::new(types::obj_pub(&[("id", Ty::Str)]))),
        ))
    }

    /// Records one use of a WordPress feature: in `Built::wordpress_uses` for every module, and as
    /// a capability when a plugin's code makes it. This is the single place each use is noted.
    pub fn use_wordpress(&mut self, kind: WordPressKind, name: &str, span: Span) {
        match kind {
            WordPressKind::Ability => self.uses("abilities", name, span),
            WordPressKind::Hook => self.uses("wpHooks", name, span),
            WordPressKind::Function => self.uses("php", format!("{}:{name}", crate::loader::WORDPRESS_MODULE), span),
        }
        let module = self
            .ev_program()
            .modules
            .iter()
            .find(|m| m.file == span.file)
            .map(|m| m.path.display().to_string())
            .unwrap_or_default();
        self.wordpress_uses.push(WordPressUse {
            kind,
            name: name.to_string(),
            module,
            span,
        });
    }

    /// `abilities.call("vendor/name", input?)` from "php/wordpress": core's `WP_Ability::execute()`.
    fn abilities_call(&mut self, name: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if name != "call" {
            return err_help(
                span,
                format!("`abilities` has no function `{name}`"),
                "use `abilities.call(\"vendor/name\", input)`",
            );
        }
        if self.fx().no_db {
            return err_help(
                span,
                "access rules can't call abilities",
                "move the call into a hook, action, route or job",
            );
        }
        if args.is_empty() || args.len() > 2 {
            return err_help(
                span,
                "abilities.call() takes an ability name and its input",
                "write abilities.call(\"vendor/name\", { ... })",
            );
        }
        let name_expr = self.arg(args, 0, span, "an ability name")?;
        let ExprKind::Str(ability) = &name_expr.kind else {
            return err_help(
                name_expr.span,
                "abilities.call() needs the ability name as a string literal",
                "write the name in place, e.g. abilities.call(\"woocommerce/create-order\", input), so the build can type it",
            );
        };
        let Some((input_ty, output_ty)) = self.abilities.abilities.get(ability).cloned() else {
            let mut d = Diag::new(name_expr.span, format!("unknown ability `{ability}`"));
            d = match suggest(ability, self.abilities.abilities.keys().map(String::as_str)) {
                Some(s) => d.help(format!("did you mean `{s}`?")),
                None => d.help(format!(
                    "abilities outside this app come from {}; run `zero abilities pull --site <url>` and commit it",
                    crate::wordpress::abilities::LOCK
                )),
            };
            return Err(d);
        };
        let input = match args.get(1) {
            // No schema: core wants no input at all. An object schema gets `{}`, which core's
            // validation accepts when nothing is required.
            None if accepts_omission(&input_ty) => "null".to_string(),
            None if matches!(&input_ty, Ty::Map(_))
                || matches!(&input_ty, Ty::Obj(f) if f.values().all(|(_, o)| *o)) =>
            {
                "new \\Zero\\JsObject()".to_string()
            }
            None => {
                return err_help(
                    span,
                    format!("`{ability}` needs input {}", input_ty.describe()),
                    "pass the input as the second argument",
                );
            }
            Some(_) => {
                let e = self.arg(args, 1, span, "the ability input")?;
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                // An inline literal keeps its literal type, so `{ status: "draft" }` meets an enum.
                if !assignable(&v.ty, &input_ty) {
                    return err_help(
                        e.span,
                        format!("`{ability}` takes {}, not {}", input_ty.describe(), v.ty.describe()),
                        "the input type comes from the ability's input schema",
                    );
                }
                v.php
            }
        };
        self.use_wordpress(WordPressKind::Ability, ability, name_expr.span);
        Ok(Out::atom(
            format!("\\Zero\\Wp\\Abilities\\Call::run({}, {input})", php_str(ability)),
            Ty::Promise(Box::new(output_ty)),
        ))
    }

    /// A catalog WordPress function: arguments checked against the catalog's types, called through
    /// `Zero\Wp\Functions::call` with the catalog's fixed arguments appended.
    fn wp_function_call(&mut self, name: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        let f = crate::wordpress::function(name).expect("only catalog functions are imported");
        if self.fx().no_db {
            return err_help(
                span,
                format!("access rules can't call `{name}`"),
                "move the call into a hook, action, route or job",
            );
        }
        if args.len() > f.params.len() {
            return err_help(
                span,
                format!("`{name}` takes {} argument(s) from TypeScript", f.params.len()),
                "Zero admits the arguments that map soundly; later PHP parameters keep their defaults",
            );
        }
        let mut php = Vec::new();
        for (i, (param, want)) in f.params.iter().enumerate() {
            let Some(_) = args.get(i) else {
                return err_help(
                    span,
                    format!("`{name}` needs `{param}` ({})", want.describe()),
                    format!("pass {} argument(s)", f.params.len()),
                );
            };
            let e = self.arg(args, i, span, param)?;
            let v = self.expr(e)?;
            let v = self.finish_value(v, e.span)?;
            if !assignable(&v.ty, want) {
                return err_help(
                    e.span,
                    format!(
                        "`{name}` takes `{param}` as {}, not {}",
                        want.describe(),
                        v.ty.describe()
                    ),
                    "the type comes from WordPress's own documentation of the function",
                );
            }
            php.push(v.php);
        }
        php.extend(f.fixed.iter().map(php_json));
        self.use_wordpress(WordPressKind::Function, name, span);
        Ok(Out::atom(
            format!("\\Zero\\Wp\\Functions::call({}, [{}])", php_str(name), php.join(", ")),
            f.returns,
        ))
    }

    fn once_call(&mut self, args: &[ArrEl], span: Span) -> Result<Out> {
        if self.fx().no_db {
            return err_help(span, "access rules can't use once()", "move it into a hook or action");
        }
        if args.len() != 2 {
            return err_help(
                span,
                "once() takes a key and a function",
                "write once(key, async () => ...)",
            );
        }
        let key_expr = self.arg(args, 0, span, "a key")?;
        let key = self.expr(key_expr)?;
        let key = self.finish_value(key, key_expr.span)?;
        if !key.ty.is_str() || key.ty.is_nullable() {
            return err_help(key_expr.span, "once key must be a string", "use a stable string key");
        }
        let f = self.arg(args, 1, span, "a function")?;
        // The callback runs to completion inside once(), so an async arrow is awaited in place.
        self.async_callback = true;
        let cb = self.callback(f, &[]);
        self.async_callback = false;
        let cb = cb?;
        let Ty::Fn(_, ret) = cb.ty else {
            return err(f.span, "once() needs a function as its second argument");
        };
        let ret = match *ret {
            Ty::Promise(t) => *t,
            t => t,
        };
        Ok(Out::atom(
            // Receipts are shared storage: each plugin keeps its own `once` keys.
            format!(
                "\\Zero\\Jobs\\Receipts::once({}, {}, {})",
                php_str(
                    &self
                        .plugin
                        .as_ref()
                        .map_or("fn".to_string(), |(_, p)| format!("plugin:{p}"))
                ),
                key.php,
                cb.php
            ),
            Ty::Promise(Box::new(classes::wire_type(&ret))),
        ))
    }

    fn enqueue_call(&mut self, job: &str, job_span: Span, args: &[ArrEl], span: Span) -> Result<Out> {
        // Inside a plugin, `jobs.<name>` names the plugin's own job.
        let registered = match &self.plugin {
            Some((_, plugin)) => format!("{plugin}_{job}"),
            None => job.to_string(),
        };
        let job = registered.as_str();
        self.uses("jobs", job, job_span);
        let Some(spec) = self.schema.jobs.get(job) else {
            let mut d = Diag::new(job_span, format!("unknown job `{job}`"));
            if let Some(s) = suggest(job, self.schema.jobs.keys().map(String::as_str)) {
                d = d.help(format!("did you mean `{s}`?"));
            } else {
                d = d.help("declare it in defineConfig({ jobs: { ... } })");
            }
            return Err(d);
        };
        if self.fx().no_db {
            return err_help(span, "access rules can't enqueue jobs", "enqueue from a hook or action");
        }
        if args.is_empty() || args.len() > 2 {
            return err_help(
                span,
                "enqueue() takes the job input and optional options",
                "write jobs.x.enqueue({ ... })",
            );
        }
        let want = types::columns_insert(&spec.input, &types::json_ty);
        let input_expr = self.arg(args, 0, span, "the job input")?;
        let input = self.expr(input_expr)?;
        let input = self.finish_value(input, input_expr.span)?;
        if !assignable(&input.ty, &want) {
            if let (Ty::Obj(have), Ty::Obj(need)) = (&input.ty, &want) {
                if let Some(k) = have.keys().find(|k| !need.contains_key(*k)) {
                    return Err(self.no_field(&want, k, input_expr.span));
                }
                if let Some((k, _)) = need
                    .iter()
                    .find(|(k, (_, optional))| !optional && !have.contains_key(*k))
                {
                    return err(input_expr.span, format!("missing required field `{k}`"));
                }
            }
            return err(
                input_expr.span,
                format!("{} doesn't fit job `{job}` ({})", input.ty.describe(), want.describe()),
            );
        }
        let opts = match args.get(1) {
            None => "null".to_string(),
            Some(_) => {
                let e = self.arg(args, 1, span, "options")?;
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                let mut fields = IndexMap::new();
                fields.insert("delay".to_string(), (Ty::Num, true));
                fields.insert("key".to_string(), (Ty::Str, true));
                let want = Ty::Obj(fields);
                if !assignable(&v.ty, &want) {
                    return err_help(
                        e.span,
                        format!("enqueue options are {}, not {}", want.describe(), v.ty.describe()),
                        "use { delay: seconds, key: \"dedupe-key\" }",
                    );
                }
                v.php
            }
        };
        let mut result = IndexMap::new();
        result.insert("id".to_string(), (Ty::Str, false));
        Ok(Out::atom(
            format!("\\Zero\\Seam::enqueue({}, {}, {opts})", php_str(job), input.php),
            Ty::Promise(Box::new(Ty::Obj(result))),
        ))
    }

    fn call_value(&mut self, f: Out, args: &[ArrEl], span: Span, callee_span: Span) -> Result<Out> {
        if f.ty == Ty::Dynamic {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicCall({}, {argv})", f.php),
                Ty::Dynamic,
            ));
        }
        let Ty::Fn(params, ret) = f.ty.clone() else {
            return err(callee_span, format!("{} isn't a function", f.ty.describe()));
        };
        if params.iter().skip(args.len()).any(|ty| !accepts_omission(ty)) {
            return err_help(
                span,
                "missing required function arguments",
                "pass every required argument, or declare the parameter optional",
            );
        }
        let mut argv = Vec::new();
        for (i, a) in args.iter().enumerate() {
            let ArrEl::Item(x) = a else {
                return err(span, "spread arguments aren't supported in calls");
            };
            let want = callable_argument(&params, i);
            let v = match (&x.kind, &want) {
                (ExprKind::Func(_), Some(Ty::Fn(ps, _))) => self.callback(x, ps)?,
                _ => {
                    let v = self.expr(x)?;
                    self.finish_value(v, x.span)?
                }
            };
            if let Some(w) = &want
                && !assignable(&v.ty, w)
                && (v.ty.is_nullable() && !w.is_nullable()
                    || !self.argument_constraint(x).is_some_and(|t| assignable(&t, w)))
            {
                return err(
                    x.span,
                    format!(
                        "this argument is {}, but the function expects {}",
                        v.ty.describe(),
                        w.describe()
                    ),
                );
            }
            if want.is_none() {
                return err(x.span, "too many arguments");
            }
            argv.push(v.php);
        }
        let argv = argv.join(", ");
        let php = match &f.helper {
            Some(name) => format!("{name}({argv})"),
            None => format!("({})({argv})", f.php),
        };
        Ok(Out::new(
            php,
            match *ret {
                Ty::Predicate(..) => Ty::Bool,
                ret => ret,
            },
        ))
    }

    fn builtin_args(&self, surface: &str, name: &str, args: &[ArrEl], span: Span) -> Result<()> {
        if matches!(surface, "global" | "constructor") && name == "RegExp" {
            crate::builtins::check_regex_literals(args, span)?;
        }
        if args.iter().any(|arg| matches!(arg, ArrEl::Spread(_))) {
            if matches!(surface, "string" | "number" | "array" | "date" | "regex") {
                return err_help(
                    span,
                    "spread arguments aren't supported in this call",
                    "pass the supported arguments directly",
                );
            }
            return Ok(());
        }
        let phase = if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            crate::builtins::Phase::Runtime
        } else {
            crate::builtins::Phase::Emitter
        };
        crate::builtins::check(surface, name, args.len(), phase, span)
    }

    /// `typeof Symbol`, `typeof globalThis.X`: decided at compile time for globals.
    /// None when `x` is not a global name or `globalThis` member.
    fn global_type_of(&mut self, x: &Expr) -> Result<Option<&'static str>> {
        let global = |this: &mut Self, e: &Expr| match &e.kind {
            ExprKind::Ident(n) => match this.resolve(n, e.span) {
                Ok(Resolved::Global(g)) => Some(g),
                _ => None,
            },
            _ => None,
        };
        let name = match &x.kind {
            ExprKind::Member {
                obj, prop, prop_span, ..
            } if global(self, obj) == Some("globalThis") => match crate::eval::global_member(prop) {
                crate::eval::GlobalMember::Provided(g) => g,
                crate::eval::GlobalMember::Absent => return Ok(Some("undefined")),
                crate::eval::GlobalMember::Refused => return Err(crate::eval::refused_global_member(prop, *prop_span)),
            },
            _ => match global(self, x) {
                Some(g) => g,
                None => return Ok(None),
            },
        };
        if matches!(
            name,
            "process" | "require" | "crypto" | "fetch" | "setTimeout" | "Promise" | "eval" | "Function"
        ) {
            return err_help(
                x.span,
                format!("typeof `{name}` depends on the host and isn't supported"),
                "use supported JavaScript values instead of host-global detection",
            );
        }
        Ok(Some(crate::eval::global_type_of(name)))
    }

    /// Symbols are values of imported JavaScript. Authored TypeScript has no symbol type yet.
    fn require_javascript_symbols(&self, span: Span) -> Result<()> {
        if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            return Ok(());
        }
        err_help(
            span,
            "symbols aren't supported in TypeScript code yet",
            "use a string key, or keep the symbol in imported JavaScript",
        )
    }

    fn global_call(&mut self, g: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if g == "Symbol" {
            self.require_javascript_symbols(span)?;
        }
        self.builtin_args("global", g, args, span)?;
        if g == "parseInt" {
            crate::builtins::check_parse_int_literals(args, span)?;
        }
        if matches!(g, "Error" | "TypeError" | "RangeError") {
            return self.error_constructor(g, args);
        }
        if matches!(g, "parseInt" | "parseFloat") && self.javascript_module(self.fx.as_ref().expect("fn").module) {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicStaticCall('Number', {}, {argv})", php_str(g)),
                Ty::Num,
            ));
        }
        if g == "RegExp" {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(format!("\\Zero\\Js::dynamicRegExpCall({argv})"), Ty::Dynamic));
        }
        if matches!(g, "setTimeout" | "clearTimeout") {
            return self.timer_call(g, args, span);
        }
        if g == "Symbol" {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(format!("\\Zero\\JsSymbol::create({argv})"), Ty::Dynamic));
        }
        if g == "globalThis" {
            return err_help(span, "`globalThis` isn't a function", "remove the call");
        }
        let argv = self.plain_args(args)?;
        let a0 = argv.first();
        Ok(match (g, a0) {
            ("String", None) => Out::atom("''", Ty::Str),
            ("Number", None) => Out::atom("0", Ty::Num),
            ("Boolean", None) | ("isFinite", None) => Out::atom("false", Ty::Bool),
            ("isNaN", None) => Out::atom("true", Ty::Bool),
            ("String", Some(v)) if v.ty == Ty::Dynamic => {
                Out::atom(format!("\\Zero\\Js::stringOf({})", v.php), Ty::Str)
            }
            ("String", Some(v)) => Out::new(self.to_string_php(v, span)?, Ty::Str),
            ("structuredClone", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::clone({})", v.php), classes::wire_type(&v.ty))
            }
            ("BigInt", Some(v)) => Out::atom(format!("\\Zero\\JsBigInt::of({})", v.php), Ty::BigInt),
            ("Number", Some(v)) if v.ty == Ty::BigInt => Out::atom(format!("({})->toNumber()", v.php), Ty::Num),
            ("Number", Some(v)) => {
                if v.ty.is_num() {
                    v.clone()
                } else {
                    // Number(value): ToNumeric, then a BigInt becomes its nearest double.
                    Out::atom(format!("\\Zero\\Js::number({})", v.php), Ty::Num)
                }
            }
            ("Boolean", Some(v)) => Out::new(self.truthy(v), Ty::Bool),
            ("encodeURI" | "encodeURIComponent", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::{g}({})", v.php), Ty::Str).checked_str(v, span)?
            }
            ("parseInt", Some(v)) => {
                let radix = argv
                    .get(1)
                    .map(|a| format!(", \\Zero\\Js::toNumber({})", a.php))
                    .unwrap_or_default();
                Out::atom(format!("\\Zero\\Js::parseInt({}{radix})", v.php), Ty::Num).checked_str(v, span)?
            }
            ("parseFloat", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::parseFloat({})", v.php), Ty::Num).checked_str(v, span)?
            }
            ("isNaN", Some(v)) => Out::atom(format!("is_nan((float) \\Zero\\Js::toNumber({}))", v.php), Ty::Bool),
            ("isFinite", Some(v)) => Out::atom(
                format!("\\Zero\\Js::isFinite(\\Zero\\Js::toNumber({}))", v.php),
                Ty::Bool,
            ),
            ("process" | "crypto" | "Buffer", _) => {
                return err_help(
                    span,
                    format!("`{g}` can't be called"),
                    "use `process.env.NAME`, `crypto.randomUUID()` or \"node:crypto\"",
                );
            }
            ("Headers" | "URLSearchParams", _) => {
                return err_help(span, format!("`{g}` needs `new`"), format!("write `new {g}(...)`"));
            }
            (
                "eval" | "Function" | "globalThis" | "Promise" | "URL" | "Request" | "Response" | "FormData" | "Blob",
                _,
            ) => {
                return err_help(
                    span,
                    format!("`{g}` isn't available in Zero code"),
                    "use supported server capabilities in a hook or action",
                );
            }
            _ => return err(span, format!("`{g}(...)` isn't supported")),
        })
    }

    /// `setTimeout(callback, delay?, ...args)` / `clearTimeout(id)`: a `Zero\\Timers` entry that runs
    /// only while the request waits. The site names the call in the handler-end refusal.
    fn timer_call(&mut self, g: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if g == "clearTimeout" {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Timers::clear(({argv})->a[0] ?? \\Zero\\Js::undefined())"),
                Ty::Undefined,
            ));
        }
        self.uses("timers", "setTimeout", span);
        let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
        let callback = self.arg(args, 0, span, "a callback")?;
        let cb = if javascript {
            let v = self.expr(callback)?;
            format!("\\Zero\\Js::timerCallback({})", v.php)
        } else {
            self.callback(callback, &[])?.php
        };
        let mut rest = Vec::new();
        for a in args.iter().skip(1) {
            let ArrEl::Item(x) = a else {
                return err(span, "spread arguments aren't supported here");
            };
            let v = self.expr(x)?;
            rest.push(self.finish_value(v, x.span)?);
        }
        if !javascript {
            if let Some(d) = rest.first()
                && !d.ty.is_num()
            {
                return err_help(
                    span,
                    "setTimeout's delay must be a number",
                    "pass the delay in milliseconds",
                );
            }
            if rest.len() > 1 {
                return err_help(
                    span,
                    "extra setTimeout arguments aren't supported in typed code",
                    "capture them in the callback",
                );
            }
        }
        let delay = rest.first().map_or_else(|| "0".to_string(), |d| d.php.clone());
        let extra: Vec<String> = rest.iter().skip(1).map(|v| v.php.clone()).collect();
        let site = self.site(span);
        let set = format!(
            "\\Zero\\Timers::set({cb}, {delay}, [{}], {})",
            extra.join(", "),
            php_str(&site)
        );
        // Node returns a Timeout object, so typed code gets the same opaque handle: only clearTimeout uses it.
        Ok(Out::atom(
            format!("\\Zero\\Timers::handle({set})"),
            if javascript { Ty::Dynamic } else { Ty::Unknown },
        ))
    }

    fn site(&self, span: Span) -> String {
        crate::diag::site(span)
    }

    /// `URL.parse`/`URL.canParse` and `AbortSignal.abort`/`any`/`timeout` in typed code.
    fn typed_standard_static(&mut self, g: &str, m: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        let mut argv = Vec::new();
        for a in args {
            let ArrEl::Item(x) = a else {
                return err(span, "spread arguments aren't supported here");
            };
            let v = self.expr(x)?;
            argv.push((self.finish_value(v, x.span)?, x.span));
        }
        let need = |ok: bool, at: Span, what: &str| -> Result<()> {
            if ok {
                Ok(())
            } else {
                err_help(at, format!("`{g}.{m}` needs {what}"), format!("pass {what}"))
            }
        };
        Ok(match (g, m) {
            ("URL", "parse" | "canParse") => {
                for (i, (v, at)) in argv.iter().enumerate() {
                    need(
                        v.ty.is_str() || v.ty == Ty::Url || (i == 1 && v.ty == Ty::Undefined),
                        *at,
                        "a string or URL",
                    )?;
                }
                let list: Vec<&str> = argv.iter().map(|(v, _)| v.php.as_str()).collect();
                let call = format!("\\Zero\\JsUrl::{}({})", m, list.join(", "));
                if m == "parse" {
                    Out::atom(call, Ty::Url.nullable())
                } else {
                    Out::atom(call, Ty::Bool)
                }
            }
            ("AbortSignal", "abort") => {
                let reason = argv
                    .first()
                    .map_or_else(|| "\\Zero\\Js::undefined()".into(), |(v, _)| v.php.clone());
                Out::atom(format!("\\Zero\\JsAbortSignal::aborted({reason})"), Ty::AbortSignal)
            }
            ("AbortSignal", "timeout") => {
                let (ms, at) = &argv[0];
                need(ms.ty.is_num(), *at, "a number of milliseconds")?;
                self.uses("timers", "AbortSignal.timeout", span);
                Out::atom(format!("\\Zero\\JsAbortSignal::timeout({})", ms.php), Ty::AbortSignal)
            }
            ("AbortSignal", "any") => {
                let (signals, at) = &argv[0];
                let ok = self
                    .iterable_element(&signals.ty)
                    .is_some_and(|el| el.all(|t| *t == Ty::AbortSignal));
                need(ok, *at, "an array of AbortSignals")?;
                Out::atom(
                    format!("\\Zero\\JsAbortSignal::any(\\Zero\\Js::iter({}))", signals.php),
                    Ty::AbortSignal,
                )
            }
            _ => return err(span, format!("`{g}.{m}` isn't supported")),
        })
    }

    fn static_call(&mut self, g: &str, m: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if (g, m) == ("Error", "captureStackTrace") {
            // Zero records no frames; the target gets Error's header-only stack.
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\JsClass::captureStackTrace((({argv})->a)[0] ?? \\Zero\\Js::undefined())"),
                Ty::Undefined,
            ));
        }
        if g == "globalThis" {
            return match crate::eval::global_member(m) {
                crate::eval::GlobalMember::Provided(g) => self.global_call(g, args, span),
                crate::eval::GlobalMember::Absent => err_help(
                    span,
                    format!("`globalThis.{m}` is undefined in Zero, so this call always throws"),
                    format!("check `typeof globalThis.{m}` first, or remove the call"),
                ),
                crate::eval::GlobalMember::Refused => Err(crate::eval::refused_global_member(m, span)),
            };
        }
        if g == "Symbol" {
            self.require_javascript_symbols(span)?;
        }
        self.builtin_args(g, m, args, span)?;
        if !self.javascript_module(self.fx.as_ref().expect("fn").module) && matches!(g, "URL" | "AbortSignal") {
            return self.typed_standard_static(g, m, args, span);
        }
        if g == "Symbol" && matches!(m, "for" | "keyFor") {
            let argv = self.javascript_args(args)?;
            let method = if m == "for" { "for" } else { "keyFor" };
            return Ok(Out::atom(
                format!("\\Zero\\JsSymbol::{method}(...({argv})->a)"),
                Ty::Dynamic,
            ));
        }
        if g == "Promise" && self.javascript_module(self.fx.as_ref().expect("fn").module) {
            let argv = self.javascript_args(args)?;
            self.uses_jobs = true;
            return Ok(Out::atom(
                format!("\\Zero\\JsPromise::staticCall({}, ({argv})->a)", php_str(m)),
                Ty::Dynamic,
            ));
        }
        if (g, m) == ("Math", "pow") {
            crate::builtins::check_power_literals(args, span)?;
        }
        if g == "BigInt" {
            let argv = self.plain_args(args)?;
            return Ok(Out::atom(
                format!(
                    "\\Zero\\JsBigInt::asIntN({}, {}, {})",
                    argv[0].php,
                    argv[1].php,
                    if m == "asIntN" { "true" } else { "false" }
                ),
                Ty::BigInt,
            ));
        }
        if g == "Math" && matches!(m, "log" | "exp") {
            crate::builtins::check_transcendental_literals(m, args, span)?;
        }
        if (g, m) == ("Number", "parseInt") {
            crate::builtins::check_parse_int_literals(args, span)?;
        }
        if (g, m) == ("String", "raw") {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(format!("\\Zero\\Js::stringRaw(...({argv})->a)"), Ty::Str));
        }
        if (self.javascript_module(self.fx.as_ref().expect("fn").module)
            || args.iter().any(|a| matches!(a, ArrEl::Spread(_))))
            && matches!(
                g,
                "Math" | "Array" | "Object" | "Number" | "JSON" | "URL" | "AbortSignal"
            )
        {
            if (g, m) == ("AbortSignal", "timeout") {
                self.uses("timers", "AbortSignal.timeout", span);
            }
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicStaticCall({}, {}, {argv})", php_str(g), php_str(m)),
                Ty::Dynamic,
            ));
        }
        if g == "Number" && matches!(m, "parseInt" | "parseFloat") {
            return self.global_call(m, args, span);
        }
        if (g, m) == ("Array", "from") {
            let src = self.arg(args, 0, span, "an argument")?;
            if let ExprKind::Object(props) = &src.kind
                && let [Prop::KV(PropKey::Static(k), len)] = props.as_slice()
                && k == "length"
            {
                let n = self.expr(len)?;
                self.need_num(&n, len.span, "`length`")?;
                let f = self.arg(args, 1, span, "a function")?;
                let cb = self.callback(f, &[Ty::Undefined, Ty::Num])?;
                let Ty::Fn(_, ret) = &cb.ty else { unreachable!() };
                let ret = (**ret).clone();
                return Ok(Out::atom(
                    format!("\\Zero\\Js::range({}, {})", n.php, cb.php),
                    Ty::Array(Box::new(ret)),
                ));
            }
            let v = self.expr(src)?;
            let nonempty = array_proven_nonempty(&v.ty);
            if let t @ (Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_) | Ty::UrlParams) = &v.ty {
                let el = self.iterable_element(t).expect("collection element");
                if let Some(ArrEl::Item(f)) = args.get(1) {
                    let cb = self.callback(f, &[el, Ty::Num])?;
                    let Ty::Fn(_, ret) = &cb.ty else { unreachable!() };
                    return Ok(Out::atom(
                        format!("\\Zero\\Js::iterArray({}, {})", v.php, cb.php),
                        Ty::Array(ret.clone()),
                    ));
                }
                return Ok(Out::atom(
                    format!("\\Zero\\Js::iterArray({})", v.php),
                    Ty::Array(Box::new(el)),
                ));
            }
            if matches!(v.ty, Ty::Dynamic | Ty::Arguments) {
                let mut element = if v.ty == Ty::Arguments {
                    Ty::Unknown
                } else {
                    Ty::Dynamic
                };
                let mut argv = vec![v.php];
                if let Some(ArrEl::Item(callback)) = args.get(1) {
                    let mapper = self.callback(callback, &[element.clone(), Ty::Num])?;
                    if let Ty::Fn(_, result) = &mapper.ty {
                        element = (**result).clone();
                    }
                    argv.push(mapper.php);
                }
                return Ok(Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicStaticCall('Array', 'from', new \\Zero\\JsArray([{}]))",
                        argv.join(", ")
                    ),
                    Ty::Array(Box::new(element)),
                ));
            }
            let el = match v.ty.non_null() {
                Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts),
                t if t.is_str() => Ty::Str,
                Ty::Union(parts)
                    if parts.iter().all(|part| {
                        part.is_str()
                            || matches!(
                                part,
                                Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::ArrayLiteral(_) | Ty::Tuple(_)
                            )
                    }) =>
                {
                    Ty::union(parts.into_iter().map(|part| match part {
                        Ty::Array(el) | Ty::NonEmptyArray(el) => *el,
                        Ty::ArrayLiteral(items) | Ty::Tuple(items) => Ty::union(items),
                        _ => Ty::Str,
                    }))
                }
                _ => return err(src.span, "Array.from needs an array, string or `{ length: n }`"),
            };
            if let Some(ArrEl::Item(f)) = args.get(1) {
                let cb = self.callback(f, &[el.clone(), Ty::Num])?;
                let Ty::Fn(_, ret) = &cb.ty else { unreachable!() };
                return Ok(Out::atom(
                    format!("\\Zero\\Js::from({}, {})", v.php, cb.php),
                    Ty::Array(ret.clone()),
                ));
            }
            return Ok(Out::atom(
                format!("\\Zero\\Js::from({})", v.php),
                if nonempty {
                    Ty::NonEmptyArray(Box::new(el))
                } else {
                    Ty::Array(Box::new(el))
                },
            ));
        }
        let argv = self.plain_args(args)?;
        if argv.iter().any(|v| matches!(v.ty, Ty::Dynamic | Ty::Arguments))
            && matches!(g, "Math" | "Array" | "Object" | "Number" | "JSON")
        {
            let ty = match (g, m) {
                ("Object", "keys") => Ty::Array(Box::new(Ty::Str)),
                ("Array", "isArray") | ("Number", "isFinite") => Ty::Bool,
                ("Math", _) => Ty::Num,
                _ => Ty::Dynamic,
            };
            return Ok(Out::atom(
                format!(
                    "\\Zero\\Js::dynamicStaticCall({}, {}, new \\Zero\\JsArray([{}]))",
                    php_str(g),
                    php_str(m),
                    argv.iter().map(|v| v.php.clone()).collect::<Vec<_>>().join(", ")
                ),
                ty,
            ));
        }
        let php_args = || argv.iter().map(|a| a.php.clone()).collect::<Vec<_>>().join(", ");
        let all_num = |what: &str| -> Result<()> {
            for (i, a) in argv.iter().enumerate() {
                if !a.ty.is_num() {
                    return err(
                        span,
                        format!("argument {} of {what} must be a number, not {}", i + 1, a.ty.describe()),
                    );
                }
            }
            Ok(())
        };
        let a0 = argv.first();
        Ok(match (g, m) {
            ("Math", "max" | "min") => {
                all_num(&format!("Math.{m}"))?;
                Out::atom(format!("\\Zero\\Js::{m}({})", php_args()), Ty::Num)
            }
            ("Math", "floor" | "ceil" | "abs" | "sqrt" | "trunc" | "round" | "sign" | "pow" | "log" | "exp") => {
                all_num(&format!("Math.{m}"))?;
                let x = php_args();
                let php = match m {
                    "floor" => format!("floor({x})"),
                    "ceil" => format!("ceil({x})"),
                    "abs" => format!("abs({x})"),
                    "sqrt" => format!("sqrt({x})"),
                    "trunc" => format!("\\Zero\\Js::trunc({x})"),
                    "round" => format!("\\Zero\\Js::round({x})"),
                    "sign" => format!("\\Zero\\Js::sign({x})"),
                    "pow" => format!(
                        "\\Zero\\Js::pow({}, {})",
                        argv[0].php,
                        argv.get(1).map(|a| a.php.as_str()).unwrap_or("NAN")
                    ),
                    "log" | "exp" => format!("\\Zero\\Js::mathTranscendental('{}', {x})", m),
                    _ => unreachable!(),
                };
                Out::atom(php, Ty::Num)
            }
            ("Math", "random") => Out::atom("\\Zero\\Js::random()", Ty::Num),
            ("Date", "now") => Out::atom("\\Zero\\Js::now()", Ty::Num),
            ("Object", "keys") => Out::atom(
                format!("\\Zero\\Js::keys({})", self.obj_arg(a0, span)?),
                Ty::Array(Box::new(Ty::Str)),
            ),
            ("Object", "values") => {
                let t = obj_values_ty(a0.map(|a| &a.ty));
                Out::atom(
                    format!("\\Zero\\Js::values({})", self.obj_arg(a0, span)?),
                    Ty::Array(Box::new(t)),
                )
            }
            ("Object", "entries") => {
                let t = obj_values_ty(a0.map(|a| &a.ty));
                Out::atom(
                    format!("\\Zero\\Js::entries({})", self.obj_arg(a0, span)?),
                    Ty::Array(Box::new(Ty::Tuple(vec![Ty::Str, t]))),
                )
            }
            ("Object", "fromEntries") => {
                let t = match a0.map(|a| a.ty.non_null()) {
                    Some(Ty::Array(el) | Ty::NonEmptyArray(el)) => match *el {
                        Ty::Tuple(ts) | Ty::ArrayLiteral(ts) if ts.len() == 2 => ts[1].clone(),
                        _ => Ty::Unknown,
                    },
                    _ => return err(span, "Object.fromEntries needs an array of [key, value] pairs"),
                };
                Out::atom(format!("\\Zero\\Js::fromEntries({})", php_args()), Ty::Map(Box::new(t)))
            }
            ("Object", "hasOwn") => {
                if a0.is_some_and(|value| value.ty.all(|ty| matches!(ty, Ty::Fn(..) | Ty::Class(_)))) {
                    return err_help(
                        span,
                        "Object.hasOwn on functions isn't supported",
                        "inspect a plain object, array or primitive value",
                    );
                }
                Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicStaticCall('Object', 'hasOwn', new \\Zero\\JsArray([{}]))",
                        php_args()
                    ),
                    Ty::Bool,
                )
            }
            ("Array", "isArray") => Out::atom(
                format!(
                    "({0} instanceof \\Zero\\JsArray)",
                    a0.map(|a| a.php.clone()).unwrap_or_default()
                ),
                Ty::Bool,
            ),
            ("Number", "isInteger") => Out::atom(format!("\\Zero\\Js::isInteger({})", php_args()), Ty::Bool),
            ("Number", "isFinite") => Out::atom(format!("\\Zero\\Js::isFinite({})", php_args()), Ty::Bool),
            ("Number", "parseInt") => Out::atom(format!("\\Zero\\Js::parseInt({})", php_args()), Ty::Num),
            ("Number", "parseFloat") => Out::atom(format!("\\Zero\\Js::parseFloat({})", php_args()), Ty::Num),
            ("JSON", "stringify") => {
                if let Some(value) = a0 {
                    crate::builtins::check_json_serialization_type(&value.ty, span)?;
                }
                let t = if a0.is_some_and(|value| {
                    value
                        .ty
                        .all(|ty| !matches!(ty, Ty::Undefined | Ty::Unknown | Ty::Dynamic | Ty::Fn(..)))
                }) {
                    Ty::Str
                } else {
                    Ty::union([Ty::Str, Ty::Undefined])
                };
                if argv
                    .get(1)
                    .is_some_and(|value| !matches!(value.ty, Ty::Null | Ty::Undefined))
                {
                    return err(span, "JSON.stringify replacers aren't supported");
                }
                Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicStaticCall('JSON', 'stringify', new \\Zero\\JsArray([{}]))",
                        php_args()
                    ),
                    t,
                )
            }
            ("JSON", "parse") => {
                if argv
                    .get(1)
                    .is_some_and(|value| !matches!(value.ty, Ty::Null | Ty::Undefined))
                {
                    return err(span, "JSON.parse revivers aren't supported");
                }
                Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicStaticCall('JSON', 'parse', new \\Zero\\JsArray([{}]))",
                        php_args()
                    ),
                    Ty::Unknown,
                )
            }
            ("console", "log" | "info" | "warn" | "error" | "debug") => Out::atom(
                format!("\\Zero\\Js::log({}, {})", php_str(m), php_args()),
                Ty::Undefined,
            ),
            _ => return err(span, format!("`{g}.{m}` isn't supported in Zero code")),
        })
    }

    fn obj_arg(&self, a: Option<&Out>, span: Span) -> Result<String> {
        match a {
            Some(v) if v.ty.all(|t| matches!(t, Ty::Obj(_) | Ty::Map(_) | Ty::Instance(..))) && !v.ty.is_nullable() => {
                Ok(v.php.clone())
            }
            Some(v) => err(span, format!("this needs an object, not {}", v.ty.describe())),
            None => err(span, "missing argument"),
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn method_call(
        &mut self,
        recv: Out,
        recv_expr: &Expr,
        m: &str,
        optional: bool,
        m_span: Span,
        args: &[ArrEl],
        span: Span,
    ) -> Result<Out> {
        if matches!(recv.ty.non_null(), Ty::Instance(..) | Ty::Class(_)) {
            if optional {
                return err(span, "optional class calls are unsupported; check the receiver first");
            }
            return self.class_method(recv, m, args, span);
        }
        let recv_span = recv_expr.span;
        match recv.ty.clone() {
            Ty::QueryColl(table) => return self.query_call(&table, m, args, span, m_span),
            Ty::Db => return self.db_call(m, args, span, m_span),
            Ty::Builder(b) => return self.builder_call(*b, m, args, span, m_span),
            Ty::DbQuery => return err(m_span, format!("use `db.query.<collection>.{m}(...)`")),
            Ty::DbGlobals => return err(m_span, format!("use `db.globals.<global>.{m}(...)`")),
            Ty::DbGlobal(name) => return self.db_global_call(&name, m, args, m_span),
            _ => {}
        }
        if recv.ty == Ty::BigInt {
            self.builtin_args("bigint", m, args, span)?;
            let argv = self.plain_args(args)?;
            return match m {
                "toString" => Ok(Out::atom(
                    match argv.first() {
                        Some(radix) => format!("({})->toRadixArg({})", recv.php, radix.php),
                        None => format!("(string) ({})", recv.php),
                    },
                    Ty::Str,
                )),
                _ => err(m_span, format!("bigint has no method `{m}` in Zero code")),
            };
        }
        let family = match recv.ty.non_null() {
            Ty::Date => Some("date"),
            t if t.is_num() => Some("number"),
            t if t.is_str() => Some("string"),
            Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_) => Some("array"),
            Ty::Regex => Some("regex"),
            Ty::MapOf(..) => Some("map"),
            Ty::SetOf(_) => Some("set"),
            Ty::Iter(_) => Some("iterator"),
            Ty::AbortController => Some("abortcontroller"),
            Ty::AbortSignal => Some("abortsignal"),
            Ty::Url => Some("url"),
            Ty::UrlParams => Some("urlsearchparams"),
            _ => None,
        };
        if let Some(family) = family {
            self.builtin_args(family, m, args, span)?;
        }
        if (family == Some("array") || recv.ty == Ty::Dynamic) && matches!(m, "sort" | "toSorted") {
            crate::builtins::check_sort_callback(args)?;
        }
        let mut recv = self.finish_value(recv, recv_span)?;
        let javascript_instance = self.javascript_module(self.fx.as_ref().expect("fn").module) && family.is_some();
        if recv.ty == Ty::Dynamic || javascript_instance {
            let argv = self.javascript_args(args)?;
            let receiver = if optional { self.tmp_var() } else { recv.php.clone() };
            let call = format!(
                "(\\Zero\\Js::dynamicMethod({receiver}, {}))(...({argv})->a)",
                php_str(m)
            );
            return Ok(Out::atom(
                if optional {
                    format!(
                        "(\\Zero\\Js::isNullish({receiver} = {}) ? \\Zero\\Js::undefined() : {call})",
                        recv.php
                    )
                } else {
                    call
                },
                Ty::Dynamic,
            ));
        }
        if recv.ty.is_nullable() && !optional {
            let base = recv.ty.non_null();
            if base.is_str()
                || matches!(
                    base,
                    Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_)
                )
            {
                recv.php = format!("\\Zero\\Js::requirePresent({})", recv.php);
            } else {
                return err_help(
                    recv_span,
                    format!("can't call `{m}`: this might be null ({})", recv.ty.describe()),
                    "check it first, or use `?.`",
                );
            }
        }
        let nullish_guard = if optional && recv.ty.is_nullable() {
            let receiver = self.tmp_var();
            let source = std::mem::replace(&mut recv.php, receiver.clone());
            recv.atomic = true;
            Some((receiver, source))
        } else {
            None
        };
        let base = recv.ty.non_null();
        let r = match &base {
            t if t.is_str() => self.string_method(&recv, m, args, span, m_span)?,
            Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_) => {
                let constraint = self.argument_constraint(recv_expr);
                self.array_method(&recv, constraint.as_ref(), m, args, span, m_span)?
            }
            t if t.all(|t| matches!(t, Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_))) => match m {
                "toFixed" => {
                    let argv = self.plain_args(args)?;
                    let d = argv.first().map(|a| a.php.clone()).unwrap_or_else(|| "0".into());
                    Out::atom(format!("\\Zero\\Js::toFixed({}, {d})", recv.php), Ty::Str)
                }
                "toString" => Out::atom(format!("\\Zero\\Js::numStr({})", recv.php), Ty::Str),
                _ => return err(m_span, format!("numbers have no method `{m}`")),
            },
            Ty::Date => match m {
                "toISOString" => Out::atom(format!("\\Zero\\Js::iso(({})->milliseconds)", recv.php), Ty::Str),
                "toJSON" => Out::atom(
                    format!("\\Zero\\Js::dateJSON(({})->milliseconds)", recv.php),
                    Ty::Str.nullable(),
                ),
                "getTime" | "valueOf" => Out::atom(format!("({})->milliseconds", recv.php), Ty::Num),
                _ => {
                    return err_help(
                        m_span,
                        format!("`Date.{m}` isn't supported"),
                        "use getTime() or toISOString()",
                    );
                }
            },
            Ty::Host(h) => self.host_method(h, &recv, m, args, m_span, span)?,
            Ty::Regex => match m {
                "test" => {
                    let argv = self.plain_args(args)?;
                    let s = argv.first().ok_or_else(|| Diag::new(span, "test() needs a string"))?;
                    if !s.ty.is_str() {
                        return err(span, format!("test() needs a string, not {}", s.ty.describe()));
                    }
                    Out::atom(format!("{}->test({})", recv.wrapped(), s.php), Ty::Bool)
                }
                _ => return err(m_span, format!("regular expressions have no method `{m}`")),
            },
            Ty::MapOf(..) | Ty::SetOf(_) | Ty::Iter(_) => self.collection_method(&recv, m, args, span, m_span)?,
            Ty::AbortController | Ty::AbortSignal => self.abort_method(&recv, m, args, span, m_span)?,
            Ty::Url | Ty::UrlParams => self.url_method(&recv, m, args, span, m_span)?,
            Ty::Map(_) | Ty::Obj(_) => {
                // Calling a function stored in an object field.
                let mut f = self.member_of_out(&recv, m, m_span)?;
                f.php = format!("\\Zero\\Js::dataMethod({}, {})", recv.php, php_str(m));
                self.call_value(f, args, span, m_span)?
            }
            other => return err(m_span, format!("{} has no method `{m}`", other.describe())),
        };
        if let Some((receiver, source)) = nullish_guard {
            return Ok(Out::new(
                format!(
                    "(\\Zero\\Js::isNullish({receiver} = {source}) ? \\Zero\\Js::undefined() : {})",
                    r.php
                ),
                Ty::union([r.ty, Ty::Undefined]),
            ));
        }
        Ok(r)
    }

    fn member_of_out(&self, o: &Out, prop: &str, span: Span) -> Result<Out> {
        let Some((t, optional)) = o.ty.non_null().field(prop) else {
            return Err(self.no_field(&o.ty, prop, span));
        };
        let t = if optional || matches!(o.ty.non_null(), Ty::Map(_)) {
            Ty::union([t, Ty::Undefined])
        } else {
            t
        };
        Ok(Out::atom(
            format!("\\Zero\\Js::dataGet({}, {})", o.php, php_str(prop)),
            t,
        ))
    }

    fn string_method(&mut self, s: &Out, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        self.builtin_args("string", m, args, span)?;
        if matches!(m, "replace" | "replaceAll") {
            let pat_e = self.arg(args, 0, span, "a pattern")?;
            let pat = self.expr(pat_e)?;
            let rep_e = self.arg(args, 1, span, "a replacement")?;
            if !(pat.ty.is_str() || pat.ty == Ty::Regex) {
                return err(
                    span,
                    format!("{m}() needs a string or regex pattern, not {}", pat.ty.describe()),
                );
            }
            let pattern = match &pat_e.kind {
                ExprKind::Regex { pattern, .. } => Some(pattern.clone()),
                ExprKind::Ident(name) => match self.resolve(name, pat_e.span)? {
                    Resolved::Value(Value::Regex(pattern, _), _) => Some(pattern.to_string()),
                    _ => None,
                },
                _ => None,
            };
            let parameters = |count| replacement_parameters(&pat.ty, pattern.as_deref(), count);
            let rep = if let ExprKind::Func(f) = &rep_e.kind {
                self.callback(rep_e, &parameters(f.params.len()))?
            } else {
                let rep = self.expr(rep_e)?;
                if let Ty::Fn(declared, _) = &rep.ty {
                    check_callback_parameters(&parameters(declared.len()), declared, rep_e.span)?;
                    Out::atom(format!("\\Closure::fromCallable({})", rep.php), rep.ty)
                } else if !rep.ty.is_str() {
                    return err(rep_e.span, "a replacement must be a string or a function");
                } else {
                    rep
                }
            };
            return Ok(Out::atom(
                format!("\\Zero\\Js::{m}({}, {}, {})", s.php, pat.php, rep.php),
                Ty::Str,
            ));
        }
        // Only String.prototype.lastIndexOf reads an `undefined` position as omitted; everywhere
        // else (and for arrays) null and undefined are both 0, which the runtime does.
        let argv = match m {
            "lastIndexOf" => self.position_args(args, 1, m)?,
            _ => self.plain_args(args)?,
        };
        let a = |i: usize| argv.get(i).map(|x| x.php.clone());
        let need_str = |i: usize, c: &Self| -> Result<String> {
            match argv.get(i) {
                Some(x) if x.ty.is_str() => Ok(x.php.clone()),
                Some(x) => err(span, format!("{m}() needs a string, not {}", x.ty.describe())),
                None => {
                    let _ = c;
                    err(span, format!("{m}() needs an argument"))
                }
            }
        };
        let js = |f: &str, extra: &[Option<String>]| {
            let mut parts = vec![s.php.clone()];
            parts.extend(extra.iter().flatten().cloned());
            Out::atom(format!("\\Zero\\Js::{f}({})", parts.join(", ")), Ty::Str)
        };
        let dispatch = || {
            format!(
                "\\Zero\\Js::dynamicCallMethod({}, {}, new \\Zero\\JsArray([{}]))",
                s.php,
                php_str(m),
                argv.iter()
                    .map(|value| value.php.clone())
                    .collect::<Vec<_>>()
                    .join(", ")
            )
        };
        Ok(match m {
            "concat" => {
                for value in &argv {
                    crate::builtins::check_string_coercion_type(&value.ty, span)?;
                }
                Out::atom(
                    format!(
                        "\\Zero\\Js::dynamicCallMethod({}, 'concat', new \\Zero\\JsArray([{}]))",
                        s.php,
                        argv.iter().map(|v| v.php.clone()).collect::<Vec<_>>().join(", ")
                    ),
                    Ty::Str,
                )
            }
            "toLowerCase" => Out::atom(format!("mb_strtolower({}, 'UTF-8')", s.php), Ty::Str),
            "toUpperCase" => Out::atom(format!("mb_strtoupper({}, 'UTF-8')", s.php), Ty::Str),
            "trim" | "trimStart" | "trimEnd" => js(m, &[]),
            "includes" | "startsWith" | "endsWith" if argv.len() > 1 => {
                return err_help(
                    span,
                    format!("`{m}()` with a position isn't supported"),
                    "slice the string first, for example `s.slice(start).startsWith(x)`",
                );
            }
            "includes" | "startsWith" | "endsWith" => {
                need_str(0, self)?;
                Out::atom(dispatch(), Ty::Bool)
            }
            "indexOf" | "lastIndexOf" => {
                need_str(0, self)?;
                Out {
                    ty: Ty::Num,
                    ..js(m, &[a(0), a(1)])
                }
            }
            "slice" | "substring" | "padStart" | "padEnd" | "repeat" | "charAt" => Out::atom(dispatch(), Ty::Str),
            "at" => Out::atom(
                format!(
                    "\\Zero\\Js::strAt({}, \\Zero\\Js::toNumber({}))",
                    s.php,
                    a(0).unwrap_or("0".into())
                ),
                Ty::union([Ty::Str, Ty::Undefined]),
            ),
            "split" => {
                if let Some(separator) = argv.first()
                    && !(separator.ty.is_str() || separator.ty == Ty::Regex)
                {
                    return err_help(
                        span,
                        "split() needs a string or regex separator",
                        "omit the separator for a one-element array, or pass a string",
                    );
                }
                if argv.is_empty() {
                    Out::atom(
                        format!("new \\Zero\\JsArray([{}])", s.php),
                        Ty::Array(Box::new(Ty::Str)),
                    )
                } else {
                    Out::atom(
                        dispatch(),
                        Ty::Array(Box::new(if argv.first().is_some_and(|v| v.ty == Ty::Regex) {
                            Ty::union([Ty::Str, Ty::Undefined])
                        } else {
                            Ty::Str
                        })),
                    )
                }
            }
            "localeCompare" => {
                return err_help(
                    span,
                    "localeCompare() isn't supported",
                    "use < or > for deterministic UTF-16 ordering; locale collation depends on the host",
                );
            }
            "match" => {
                if argv.first().map(|x| &x.ty) != Some(&Ty::Regex) {
                    return err(span, "match() needs a regular expression");
                }
                Out {
                    ty: Ty::Array(Box::new(Ty::union([Ty::Str, Ty::Undefined]))).nullable(),
                    ..js("strMatch", &[a(0)])
                }
            }
            "toString" => s.clone(),
            "length" => return err(m_span, "`length` is a property: write `s.length`"),
            _ => return err(m_span, format!("strings have no method `{m}` in Zero code")),
        })
    }

    fn array_method(
        &mut self,
        a: &Out,
        constraint: Option<&Ty>,
        m: &str,
        args: &[ArrEl],
        span: Span,
        m_span: Span,
    ) -> Result<Out> {
        self.builtin_args("array", m, args, span)?;
        let a = Out {
            php: format!("\\Zero\\Js::nativeArrayReceiver({}, {})", a.php, php_str(m)),
            ..a.clone()
        };
        let el = match a.ty.non_null() {
            Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts),
            _ => unreachable!(),
        };
        let element_constraint = match constraint.unwrap_or(&a.ty).non_null() {
            Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts),
            _ => el.clone(),
        };
        let check_insert = |items: &[Out]| -> Result<()> {
            for item in items {
                if !assignable(&item.ty, &element_constraint) && element_constraint != Ty::Never {
                    return err(
                        span,
                        format!(
                            "can't insert {} into an array of {}",
                            item.ty.describe(),
                            element_constraint.describe()
                        ),
                    );
                }
            }
            Ok(())
        };
        let cb = |c: &mut Self, extra: &[Ty]| -> Result<Out> {
            let f = c.arg(args, 0, span, "a function")?;
            let mut ps = vec![el.clone(), Ty::Num, a.ty.clone()];
            ps.splice(0..0, extra.iter().cloned());
            c.callback(f, &ps)
        };
        let ret_of = |f: &Out| match &f.ty {
            Ty::Fn(_, r) if matches!(**r, Ty::Predicate(..)) => Ty::Bool,
            Ty::Fn(_, r) => (**r).clone(),
            _ => Ty::Unknown,
        };
        Ok(match m {
            "map" => {
                let f = cb(self, &[])?;
                let r = ret_of(&f);
                Out::atom(
                    format!("\\Zero\\Js::map({}, {})", a.php, f.php),
                    if array_proven_nonempty(&a.ty) {
                        Ty::NonEmptyArray(Box::new(r))
                    } else {
                        Ty::Array(Box::new(r))
                    },
                )
            }
            "filter" => {
                let f = cb(self, &[])?;
                let narrowed = match &f.ty {
                    Ty::Fn(_, result) if matches!(**result, Ty::Predicate(0, _)) => {
                        let Ty::Predicate(_, target) = result.as_ref() else {
                            unreachable!()
                        };
                        (**target).clone()
                    }
                    _ => el,
                };
                Out::atom(
                    format!("\\Zero\\Js::filter({}, {})", a.php, f.php),
                    Ty::Array(Box::new(narrowed)),
                )
            }
            "find" | "findLast" => {
                let f = cb(self, &[])?;
                let narrowed = match &f.ty {
                    Ty::Fn(_, result) if matches!(**result, Ty::Predicate(0, _)) => {
                        let Ty::Predicate(_, target) = result.as_ref() else {
                            unreachable!()
                        };
                        (**target).clone()
                    }
                    _ => el,
                };
                Out::atom(
                    format!("\\Zero\\Js::{m}({}, {})", a.php, f.php),
                    Ty::union([narrowed, Ty::Undefined]),
                )
            }
            "findIndex" | "findLastIndex" => {
                let f = cb(self, &[])?;
                Out::atom(format!("\\Zero\\Js::{m}({}, {})", a.php, f.php), Ty::Num)
            }
            "some" | "every" => {
                let f = cb(self, &[])?;
                Out::atom(format!("\\Zero\\Js::{m}({}, {})", a.php, f.php), Ty::Bool)
            }
            "flatMap" => {
                let f = cb(self, &[])?;
                let flatten = |t| match t {
                    Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts),
                    t => t,
                };
                let r = match ret_of(&f) {
                    Ty::Union(ts) => Ty::union(ts.into_iter().map(flatten)),
                    t => flatten(t),
                };
                Out::atom(
                    format!("\\Zero\\Js::flatMap({}, {})", a.php, f.php),
                    Ty::Array(Box::new(r)),
                )
            }
            "forEach" => {
                let f = cb(self, &[])?;
                Out::atom(format!("\\Zero\\Js::forEach({}, {})", a.php, f.php), Ty::Undefined)
            }
            "reduce" => {
                let init = match args.get(1) {
                    Some(ArrEl::Item(x)) => Some(self.expr(x)?),
                    Some(ArrEl::Spread(x)) => return err(x.span, "spread arguments aren't supported here"),
                    None => None,
                };
                let acc_ty = init
                    .as_ref()
                    .map(|i| widen_fresh_literals(&i.ty))
                    .unwrap_or_else(|| widen_fresh_literals(&el));
                let f = self.arg(args, 0, span, "a function")?;
                let f = self.callback(f, &[acc_ty.clone(), el.clone(), Ty::Num, a.ty.clone()])?;
                let r = ret_of(&f);
                if !assignable(&r, &acc_ty) {
                    return err(
                        span,
                        format!(
                            "the reducer returns {}, but the accumulator is {}",
                            r.describe(),
                            acc_ty.describe()
                        ),
                    );
                }
                let init_php = init.map(|i| format!(", {}", i.php)).unwrap_or_default();
                Out::atom(format!("\\Zero\\Js::reduce({}, {}{init_php})", a.php, f.php), acc_ty)
            }
            "sort" | "toSorted" => {
                let f = match args.first() {
                    Some(ArrEl::Item(x)) => Some(self.callback(x, &[el.clone(), el.clone()])?),
                    _ => None,
                };
                if let Some(f) = &f
                    && !ret_of(f).is_num()
                {
                    return err(span, "the comparator must return a number");
                }
                let fp = f.map(|f| format!(", {}", f.php)).unwrap_or_default();
                Out::atom(format!("\\Zero\\Js::{m}({}{fp})", a.php), Ty::Array(Box::new(el)))
            }
            "push" | "unshift" => {
                let p = &a.php;
                let argv = self.plain_args(args)?;
                check_insert(&argv)?;
                let items: Vec<String> = argv.iter().map(|x| x.php.clone()).collect();
                Out::atom(
                    format!(
                        "\\Zero\\Js::{m}({p}{})",
                        if items.is_empty() {
                            String::new()
                        } else {
                            format!(", {}", items.join(", "))
                        }
                    ),
                    Ty::Num,
                )
            }
            "pop" => Out::atom(format!("\\Zero\\Js::pop({})", a.php), Ty::union([el, Ty::Undefined])),
            "shift" => Out::atom(format!("\\Zero\\Js::shift({})", a.php), Ty::union([el, Ty::Undefined])),
            "reverse" | "toReversed" => Out::atom(format!("\\Zero\\Js::{m}({})", a.php), Ty::Array(Box::new(el))),
            "splice" => {
                let argv = self.plain_args(args)?;
                check_insert(&argv[argv.len().min(2)..])?;
                let extra: Vec<String> = argv.iter().map(|x| x.php.clone()).collect();
                Out::atom(
                    format!("(\\Zero\\Js::dynamicMethod({}, 'splice'))({})", a.php, extra.join(", ")),
                    Ty::Array(Box::new(el)),
                )
            }
            "includes" | "indexOf" | "lastIndexOf" | "join" | "slice" | "at" | "concat" | "flat" | "fill" => {
                let argv = self.plain_args(args)?;
                let extra: Vec<String> = argv.iter().map(|x| x.php.clone()).collect();
                let all = std::iter::once(a.php.clone())
                    .chain(extra.iter().cloned())
                    .collect::<Vec<_>>()
                    .join(", ");
                let dispatch = || {
                    format!(
                        "(\\Zero\\Js::dynamicMethod({}, {}))({})",
                        a.php,
                        php_str(m),
                        extra.join(", ")
                    )
                };
                match m {
                    "includes" => Out::atom(format!("\\Zero\\Js::includes({all})"), Ty::Bool),
                    "indexOf" | "lastIndexOf" => Out::atom(format!("\\Zero\\Js::{m}({all})"), Ty::Num),
                    "join" => {
                        crate::builtins::check_string_coercion_type(&a.ty, span)?;
                        if let Some(sep) = argv.first()
                            && !sep.ty.is_str()
                        {
                            return err(span, "join() needs a string separator");
                        }
                        Out::atom(format!("\\Zero\\Js::join({all})"), Ty::Str)
                    }
                    "slice" => Out::atom(dispatch(), Ty::Array(Box::new(el))),
                    "at" => Out::atom(format!("\\Zero\\Js::arrAt({all})"), Ty::union([el, Ty::Undefined])),
                    "concat" => {
                        let mut t = vec![el];
                        let mut parts = vec![a.php.clone()];
                        for x in &argv {
                            let flatten = |ty| match ty {
                                Ty::Array(e) | Ty::NonEmptyArray(e) => *e,
                                Ty::Tuple(items) | Ty::ArrayLiteral(items) => Ty::union(items),
                                other => other,
                            };
                            t.push(match x.ty.clone() {
                                Ty::Union(items) => Ty::union(items.into_iter().map(flatten)),
                                ty => flatten(ty),
                            });
                            parts.push(x.php.clone());
                        }
                        Out::atom(
                            format!("\\Zero\\Js::concat({})", parts.join(", ")),
                            Ty::Array(Box::new(Ty::union(t))),
                        )
                    }
                    "flat" => {
                        let depth = match argv.first().map(|v| &v.ty) {
                            None | Some(Ty::Undefined) => 1,
                            Some(Ty::Null) => 0,
                            Some(Ty::NumLit(n) | Ty::FreshNumLit(n)) if *n == 0.0 => 0,
                            Some(Ty::NumLit(n) | Ty::FreshNumLit(n)) if *n == 1.0 => 1,
                            _ => {
                                return err_help(
                                    span,
                                    "typed flat() supports only depths 0 and 1",
                                    "use a literal depth of 0 or 1, or flatten explicitly",
                                );
                            }
                        };
                        let flatten = |t| match t {
                            Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
                            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Ty::union(ts),
                            t => t,
                        };
                        let inner = if depth == 0 {
                            el
                        } else {
                            match el {
                                Ty::Union(ts) => Ty::union(ts.into_iter().map(flatten)),
                                t => flatten(t),
                            }
                        };
                        Out::atom(dispatch(), Ty::Array(Box::new(inner)))
                    }
                    _ => {
                        check_insert(&argv[..argv.len().min(1)])?;
                        Out::atom(dispatch(), Ty::Array(Box::new(el)))
                    }
                }
            }
            _ => return err(m_span, format!("arrays have no method `{m}` in Zero code")),
        })
    }

    // ---- database ---------------------------------------------------------------

    fn query_call(&mut self, table: &str, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        let key = self.schema.collections[table].key.clone();
        self.uses("read", key, m_span);
        if !matches!(m, "findMany" | "findFirst") {
            return err_help(
                m_span,
                format!("`db.query.<collection>` has no method `{m}`"),
                "use findMany or findFirst",
            );
        }
        if args.len() > 1 {
            return err_help(
                span,
                format!("db.query.{table}.{m} accepts at most one options argument"),
                "pass filters and ordering inside one options object",
            );
        }
        let (opts, row_ty) = match args.first() {
            None => ("[]".to_string(), types::row(self.schema, table)),
            Some(ArrEl::Item(o)) => self.query_opts(table, o, m == "findMany")?,
            Some(ArrEl::Spread(x)) => return err(x.span, "spread arguments aren't supported here"),
        };
        let _ = span;
        let (php, ty) = if m == "findMany" {
            (
                format!("\\Zero\\Seam::findMany({}, {opts})", php_str(table)),
                Ty::Array(Box::new(row_ty)),
            )
        } else {
            (
                format!("\\Zero\\Seam::findFirst({}, {opts})", php_str(table)),
                row_ty.nullable(),
            )
        };
        Ok(Out::atom(php, Ty::Promise(Box::new(ty))))
    }

    /// Compiles a query options literal, checking names against the schema and computing the result type.
    fn query_opts(&mut self, table: &str, e: &Expr, many: bool) -> Result<(String, Ty)> {
        let ExprKind::Object(props) = &e.kind else {
            return err_help(
                e.span,
                "query options must be an object literal",
                "write them inline: `findMany({ where: { ... } })`",
            );
        };
        let mut row = types::row(self.schema, table);
        let mut parts = Vec::new();
        let mut columns_pick: Option<Vec<String>> = None;
        let mut with_rels = Vec::new();
        for p in props {
            let Prop::KV(PropKey::Static(k), v) = p else {
                return err(e.span, "query options can't be spread or computed");
            };
            let php = match k.as_str() {
                "where" => self.filter(table, v, true)?,
                "orderBy" => self.order_by(table, v)?,
                "limit" | "offset" => {
                    if k == "limit" && !many {
                        return err(v.span, "findFirst always returns one row; drop `limit`");
                    }
                    let n = self.expr(v)?;
                    self.need_num(&n, v.span, &format!("`{k}`"))?;
                    format!("(int) {}", n.wrapped())
                }
                "columns" => {
                    let ExprKind::Object(cols) = &v.kind else {
                        return err(v.span, "`columns` must be an object like `{ id: true }`");
                    };
                    let mut picked = Vec::new();
                    let mut out = Vec::new();
                    for c in cols {
                        let Prop::KV(PropKey::Static(name), flag) = c else {
                            return err(v.span, "`columns` keys must be plain names");
                        };
                        self.check_column(table, name, flag.span)?;
                        let ExprKind::Bool(b) = flag.kind else {
                            return err(flag.span, "`columns` values must be true or false");
                        };
                        if b {
                            picked.push(name.clone());
                        }
                        out.push(format!("{} => {b}", php_str(name)));
                    }
                    columns_pick = Some(picked);
                    format!("[{}]", out.join(", "))
                }
                "with" => {
                    let (php, rels) = self.with_opts(table, v)?;
                    with_rels = rels;
                    php
                }
                // §7.3: a locale of the config or "*" (checked again at runtime).
                "locale" if self.schema.collections[table].localized => {
                    let l = self.expr(v)?;
                    if !assignable(&l.ty, &Ty::Str) {
                        return err(v.span, "`locale` must be a configured locale or \"*\"");
                    }
                    l.wrapped()
                }
                other => {
                    let mut d = Diag::new(v.span, format!("query options have no `{other}`"));
                    if let Some(s) = suggest(
                        other,
                        ["where", "orderBy", "limit", "offset", "columns", "with", "locale"],
                    ) {
                        d = d.help(format!("did you mean `{s}`?"));
                    }
                    return Err(d);
                }
            };
            parts.push(format!("{} => {php}", php_str(k)));
        }
        if let (Some(picked), Ty::Obj(fields)) = (&columns_pick, &row)
            && !picked.is_empty()
        {
            row = Ty::Obj(
                fields
                    .iter()
                    .filter(|(k, _)| picked.contains(k))
                    .map(|(k, v)| (k.clone(), v.clone()))
                    .collect(),
            );
        }
        for (rel, nested) in with_rels {
            row = match rel.strip_prefix('\u{0}') {
                // A media column populated in place.
                Some(col) => match row {
                    Ty::Obj(mut f) => {
                        f.insert(col.to_string(), (nested, false));
                        Ty::Obj(f)
                    }
                    other => other,
                },
                None => types::with_relations(self.schema, table, row, &rel, nested),
            };
        }
        Ok((format!("[{}]", parts.join(", ")), row))
    }

    fn with_opts(&mut self, table: &str, e: &Expr) -> Result<(String, Vec<(String, Ty)>)> {
        let ExprKind::Object(props) = &e.kind else {
            return err(e.span, "`with` must be an object like `{ location: true }`");
        };
        let mut parts = Vec::new();
        let mut rels = Vec::new();
        for p in props {
            let Prop::KV(PropKey::Static(rel), v) = p else {
                return err(e.span, "`with` keys must be relation names");
            };
            if let Some(col) = self.schema.collections[table].columns.get(rel)
                && matches!(col.ty, ColType::Media | ColType::MediaList)
            {
                if !matches!(v.kind, ExprKind::Bool(true)) {
                    return err(v.span, format!("`with.{rel}` loads the media row; use `true`"));
                }
                parts.push(format!("{} => true", php_str(rel)));
                let m = types::media_row();
                let t = if col.ty == ColType::MediaList {
                    Ty::Array(Box::new(m))
                } else {
                    m
                };
                rels.push((format!("\u{0}{rel}"), if col.not_null { t } else { t.nullable() }));
                continue;
            }
            let r = self.check_relation(table, rel, v.span)?.clone();
            let target = self.schema.collections[&r.collection].key.clone();
            self.uses("read", target, v.span);
            match &v.kind {
                ExprKind::Bool(true) => {
                    parts.push(format!("{} => true", php_str(rel)));
                    rels.push((rel.clone(), types::row(self.schema, &r.collection)));
                }
                ExprKind::Object(_) => {
                    let (php, t) = self.query_opts(&r.collection, v, r.kind == RelKind::Many)?;
                    parts.push(format!("{} => {php}", php_str(rel)));
                    rels.push((rel.clone(), t));
                }
                _ => return err(v.span, "`with` values must be `true` or nested options"),
            }
        }
        Ok((format!("[{}]", parts.join(", ")), rels))
    }

    fn order_by(&mut self, table: &str, e: &Expr) -> Result<String> {
        let ExprKind::Object(props) = &e.kind else {
            return err(e.span, "`orderBy` must be an object like `{ name: \"asc\" }`");
        };
        let mut parts = Vec::new();
        for p in props {
            let Prop::KV(PropKey::Static(col), dir) = p else {
                return err(e.span, "`orderBy` keys must be column names");
            };
            let c = self.check_column(table, col, dir.span)?;
            if c.ty.is_json_kind() {
                return err(dir.span, "`orderBy` isn't supported on JSON columns");
            }
            if c.ty.is_list_kind() {
                return err(dir.span, "`orderBy` isn't supported on list columns");
            }
            let coll = &self.schema.collections[table];
            if coll.editor && coll.content.as_deref() == Some(col.as_str()) {
                return err(
                    dir.span,
                    format!("`orderBy` on `{col}` isn't supported on editor collections"),
                );
            }
            match &dir.kind {
                ExprKind::Str(d) if d == "asc" || d == "desc" => {
                    parts.push(format!("{} => {}", php_str(col), php_str(d)))
                }
                _ => return err(dir.span, "sort direction must be \"asc\" or \"desc\""),
            }
        }
        Ok(format!("[{}]", parts.join(", ")))
    }

    fn check_column(&self, table: &str, col: &str, span: Span) -> Result<&crate::schema::Column> {
        let c = &self.schema.collections[table];
        c.columns.get(col).ok_or_else(|| {
            let mut d = Diag::new(span, format!("\"{table}\" has no column `{col}`"));
            if let Some(s) = suggest(col, c.columns.keys().map(String::as_str)) {
                d = d.help(format!("did you mean `{s}`?"));
            }
            d
        })
    }

    fn check_relation(&self, table: &str, rel: &str, span: Span) -> Result<&crate::schema::Relation> {
        let c = &self.schema.collections[table];
        c.relations.get(rel).ok_or_else(|| {
            let mut d = Diag::new(span, format!("\"{table}\" has no relation `{rel}`"));
            if let Some(s) = suggest(rel, c.relations.keys().map(String::as_str)) {
                d = d.help(format!("did you mean `{s}`?"));
            } else {
                d = d.help("define it in defineRelations()");
            }
            d
        })
    }

    /// A `where` filter. Literal objects are checked against the schema; other expressions must be typed objects.
    fn filter(&mut self, table: &str, e: &Expr, allow_relations: bool) -> Result<String> {
        let ExprKind::Object(props) = &e.kind else {
            let v = self.expr(e)?;
            if !matches!(v.ty.non_null(), Ty::Obj(_) | Ty::Map(_)) {
                return err(e.span, format!("a filter must be an object, not {}", v.ty.describe()));
            }
            return Ok(v.php);
        };
        let mut parts = Vec::new();
        for p in props {
            let Prop::KV(PropKey::Static(k), v) = p else {
                return err(e.span, "filter keys must be plain names");
            };
            let php = match k.as_str() {
                "OR" | "AND" => {
                    let ExprKind::Array(items) = &v.kind else {
                        return err(v.span, format!("`{k}` takes an array of filters"));
                    };
                    let mut subs = Vec::new();
                    for it in items {
                        let ArrEl::Item(x) = it else {
                            return err(v.span, "spread isn't supported here");
                        };
                        subs.push(self.filter(table, x, allow_relations)?);
                    }
                    format!("[{}]", subs.join(", "))
                }
                "NOT" => self.filter(table, v, allow_relations)?,
                name => {
                    let c = &self.schema.collections[table];
                    if let Some(col) = c.columns.get(name) {
                        let col = col.clone();
                        let editor = c.editor;
                        self.field_filter(&col, v, editor)?
                    } else if c.relations.contains_key(name) {
                        if !allow_relations {
                            return err(v.span, "relation filters only work in findMany/findFirst");
                        }
                        let target = c.relations[name].collection.clone();
                        let key = self.schema.collections[&target].key.clone();
                        self.uses("read", key, v.span);
                        match &v.kind {
                            ExprKind::Bool(true) => "true".to_string(),
                            _ => self.filter(&target, v, true)?,
                        }
                    } else {
                        let mut d = Diag::new(v.span, format!("\"{table}\" has no column or relation `{name}`"));
                        let names: Vec<&str> = c.columns.keys().chain(c.relations.keys()).map(String::as_str).collect();
                        if let Some(s) = suggest(name, names) {
                            d = d.help(format!("did you mean `{s}`?"));
                        }
                        return Err(d);
                    }
                }
            };
            parts.push(format!("{} => {php}", php_str(k)));
        }
        Ok(format!("[{}]", parts.join(", ")))
    }

    /// `editor`: the collection is post-backed on WordPress (§3.5.1 limits what can be filtered).
    fn field_filter(&mut self, col: &crate::schema::Column, e: &Expr, editor: bool) -> Result<String> {
        let spread_meta = |op: &str, span: Span| -> Result<()> {
            if editor && col.ty.is_structured() {
                return err_help(
                    span,
                    format!(
                        "`{op}` on `{}` isn't supported on editor collections; filter on a scalar column",
                        col.name
                    ),
                    "on WordPress this value is spread over many postmeta rows; use isNull/isNotNull, or `editor: false`",
                );
            }
            Ok(())
        };
        let col_ty = col_value_ty(col);
        let is_json = col.ty.is_json_kind();
        let is_list = col.ty.is_list_kind();
        // `{ tags: ["a"] }` / `{ seo: {...} }`: a whole value, compared with `eq`.
        let whole_value = match &e.kind {
            // `{}` on a json() column is the empty object to compare with, not an empty operator map.
            ExprKind::Object(props) => {
                is_json
                    && (props.is_empty()
                        || !props
                            .iter()
                            .all(|p| matches!(p, Prop::KV(PropKey::Static(k), _) if FILTER_OPS.contains(&k.as_str()))))
            }
            ExprKind::Array(_) => is_list || is_json,
            _ => true,
        };
        if whole_value {
            let v = self.expr(e)?;
            let v = self.finish_value(v, e.span)?;
            if !assignable(&v.ty, &col_ty.clone().nullable()) {
                return err(
                    e.span,
                    format!(
                        "`{}` is {}, but this is {}",
                        col.name,
                        col_ty.describe(),
                        v.ty.describe()
                    ),
                );
            }
            if v.ty != Ty::Null {
                spread_meta("eq", e.span)?;
            }
            if is_list && !matches!(&e.kind, ExprKind::Array(a) if a.is_empty()) && v.ty != Ty::Null {
                return err_help(
                    e.span,
                    "`eq` isn't supported on list columns; use `has`",
                    format!("write `{{ {}: {{ has: ... }} }}`", col.name),
                );
            }
            return Ok(self.filter_value(col, &v));
        }
        let ExprKind::Object(props) = &e.kind else {
            unreachable!("non-objects are whole values")
        };
        let mut parts = Vec::new();
        for p in props {
            let Prop::KV(PropKey::Static(op), v) = p else {
                return err(e.span, "filter operators must be plain names");
            };
            if (is_json || is_list) && !matches!(op.as_str(), "isNull" | "isNotNull" | "NOT" | "OR" | "AND") {
                let allowed = if is_json {
                    matches!(op.as_str(), "eq" | "ne")
                } else {
                    op == "has" || (op == "eq" && matches!(&v.kind, ExprKind::Array(a) if a.is_empty()))
                };
                if !allowed && FILTER_OPS.contains(&op.as_str()) {
                    return Err(if is_json {
                        Diag::new(v.span, format!("`{op}` isn't supported on JSON columns"))
                    } else {
                        Diag::new(v.span, format!("`{op}` isn't supported on list columns; use `has`"))
                    });
                }
            }
            if matches!(op.as_str(), "eq" | "ne") && !matches!(v.kind, ExprKind::Null | ExprKind::Undefined) {
                spread_meta(op, v.span)?;
            }
            let php = match op.as_str() {
                "eq" | "ne" | "gt" | "gte" | "lt" | "lte" => {
                    let x = self.expr(v)?;
                    let x = self.finish_value(x, v.span)?;
                    let want = if matches!(col.ty, ColType::Timestamp | ColType::Date) {
                        Ty::union([Ty::Str, Ty::Num, Ty::Date])
                    } else {
                        col_ty.clone()
                    };
                    let want = if matches!(op.as_str(), "eq" | "ne") {
                        want.nullable()
                    } else {
                        want
                    };
                    if !assignable(&x.ty, &want) {
                        return err(
                            v.span,
                            format!(
                                "`{}` is {}, but this is {}",
                                col.name,
                                col_ty.describe(),
                                x.ty.describe()
                            ),
                        );
                    }
                    self.filter_value(col, &x)
                }
                "has" => {
                    let Ty::Array(el) = &col_ty else {
                        return err(v.span, "`has` is only supported on list columns");
                    };
                    let x = self.expr(v)?;
                    let x = self.finish_value(x, v.span)?;
                    if !assignable(&x.ty, el) {
                        return err(
                            v.span,
                            format!(
                                "`{}` holds {}, but this is {}",
                                col.name,
                                el.describe(),
                                x.ty.describe()
                            ),
                        );
                    }
                    x.php
                }
                "in" | "notIn" => {
                    let x = self.expr(v)?;
                    if !matches!(
                        x.ty.non_null(),
                        Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_)
                    ) {
                        return err(v.span, format!("`{op}` takes an array"));
                    }
                    x.php
                }
                "like" | "ilike" | "notLike" | "notIlike" => {
                    let x = self.expr(v)?;
                    if !x.ty.is_str() {
                        return err(v.span, format!("`{op}` takes a string pattern"));
                    }
                    x.php
                }
                "isNull" | "isNotNull" => match v.kind {
                    ExprKind::Bool(true) => "true".into(),
                    _ => return err(v.span, format!("`{op}` takes `true`")),
                },
                "NOT" => self.field_filter(col, v, editor)?,
                "OR" | "AND" => {
                    let ExprKind::Array(items) = &v.kind else {
                        return err(v.span, format!("`{op}` takes an array"));
                    };
                    let mut subs = Vec::new();
                    for it in items {
                        let ArrEl::Item(x) = it else {
                            return err(v.span, "spread isn't supported here");
                        };
                        subs.push(self.field_filter(col, x, editor)?);
                    }
                    format!("[{}]", subs.join(", "))
                }
                other => {
                    let mut d = Diag::new(v.span, format!("unknown filter operator `{other}`"));
                    if let Some(s) = suggest(other, FILTER_OPS.iter().copied()) {
                        d = d.help(format!("did you mean `{s}`?"));
                    }
                    return Err(d);
                }
            };
            parts.push(format!("{} => {php}", php_str(op)));
        }
        Ok(format!("[{}]", parts.join(", ")))
    }

    /// Filter values for timestamp/date columns accept Dates (epoch ms): convert to the stored form.
    fn filter_value(&self, col: &crate::schema::Column, v: &Out) -> String {
        match (col.ty, &v.ty) {
            (ColType::Timestamp, Ty::Date) => format!("\\Zero\\Js::iso(({})->milliseconds)", v.php),
            (ColType::Date, Ty::Date) => format!("substr(\\Zero\\Js::iso(({})->milliseconds), 0, 10)", v.php),
            (ColType::Timestamp, Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_)) => {
                format!("\\Zero\\Js::iso({})", v.php)
            }
            (ColType::Date, Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_)) => {
                format!("substr(\\Zero\\Js::iso({}), 0, 10)", v.php)
            }
            _ => v.php.clone(),
        }
    }

    /// `db.globals.<key>.get()` / `.update(patch)`: trusted, like `db.query`.
    fn db_global_call(&mut self, name: &str, m: &str, args: &[ArrEl], m_span: Span) -> Result<Out> {
        self.uses(
            if m == "update" { "write" } else { "read" },
            format!("globals.{name}"),
            m_span,
        );
        // Typed like collection rows: `json().$type<T>()` fields read and write as T.
        let row = types::columns_row(&self.schema.globals[name].fields, &types::json_ty);
        let php = match (m, args) {
            ("get", []) => format!("\\Zero\\Seam::globalGet({})", php_str(name)),
            ("update", [ArrEl::Item(patch)]) => {
                let v = self.expr(patch)?;
                let v = self.finish_value(v, patch.span)?;
                let want = types::storage_input(types::partial(row.clone()));
                if !assignable(&v.ty, &want) {
                    if let (Ty::Obj(have), Ty::Obj(need)) = (&v.ty, &want) {
                        for (k, (t, _)) in have {
                            match need.get(k) {
                                None => return Err(self.no_field(&want, k, patch.span)),
                                Some((nt, _)) if !assignable(t, nt) => {
                                    return err(
                                        patch.span,
                                        format!("`{k}` must be {}, not {}", nt.describe(), t.describe()),
                                    );
                                }
                                _ => {}
                            }
                        }
                    }
                    return err(
                        patch.span,
                        format!(
                            "{} doesn't fit global \"{name}\" ({})",
                            v.ty.describe(),
                            want.describe()
                        ),
                    );
                }
                format!("\\Zero\\Seam::globalUpdate({}, {})", php_str(name), v.php)
            }
            ("get", _) => return err(m_span, "`get()` takes no arguments"),
            ("update", _) => return err(m_span, "`update()` takes one patch object"),
            _ => {
                return err_help(
                    m_span,
                    format!("`db.globals.{name}` has no method `{m}`"),
                    "use get() or update(patch)",
                );
            }
        };
        Ok(Out::new(php, Ty::Promise(Box::new(row))))
    }

    fn db_call(&mut self, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        let kind = match m {
            "insert" => BuilderKind::Insert,
            "update" => BuilderKind::Update,
            "delete" => BuilderKind::Delete,
            "query" => {
                return err(
                    m_span,
                    "`db.query` isn't a function: use `db.query.<collection>.findMany(...)`",
                );
            }
            other => {
                return err_help(
                    m_span,
                    format!("`db` has no method `{other}`"),
                    "use db.query, db.insert, db.update, or db.delete",
                );
            }
        };
        crate::builtins::check("db", m, args.len(), crate::builtins::Phase::Emitter, span)?;
        let t = self.arg(args, 0, span, "a collection")?;
        let v = self.expr(t)?;
        let Ty::Collection(table) = v.ty else {
            return err(t.span, format!("db.{m}() needs a collection, not {}", v.ty.describe()));
        };
        if kind == BuilderKind::Delete {
            let key = self.schema.collections[&table].key.clone();
            self.uses("delete", key, t.span);
        }
        if v.php != php_str(&table) {
            return err_help(
                t.span,
                "unsupported database collection expression",
                "pass a static collection reference; evaluate side effects in a preceding statement",
            );
        }
        Ok(Out::atom(
            "",
            Ty::Builder(Box::new(Builder {
                kind,
                table,
                values: None,
                set: None,
                filter: None,
                returning: false,
            })),
        ))
    }

    fn builder_call(&mut self, mut b: Builder, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        crate::builtins::check("db.builder", m, args.len(), crate::builtins::Phase::Emitter, span)?;
        match (b.kind.clone(), m) {
            (BuilderKind::Insert, "values") if b.values.is_none() => {
                let x = self.arg(args, 0, span, "values")?;
                b.values = Some(self.write_values(&b.table, x, true)?);
            }
            (BuilderKind::Update, "set") if b.set.is_none() => {
                let x = self.arg(args, 0, span, "the changes")?;
                b.set = Some(self.write_values(&b.table, x, false)?);
            }
            (BuilderKind::Update | BuilderKind::Delete, "where") if b.filter.is_none() => {
                let key = self.schema.collections[&b.table].key.clone();
                self.uses("read", key, m_span);
                if b.kind == BuilderKind::Update && b.set.is_none() {
                    return err_help(
                        span,
                        "unsupported update builder order",
                        "call set(...) before where(...) so argument effects retain their order",
                    );
                }
                let x = self.arg(args, 0, span, "a filter")?;
                b.filter = Some(self.filter(&b.table, x, false)?);
            }
            (_, "returning") if !b.returning => b.returning = true,
            (kind, other) => {
                let valid = match kind {
                    BuilderKind::Insert => "values(...), returning()",
                    BuilderKind::Update => "set(...), where(...), returning()",
                    BuilderKind::Delete => "where(...), returning()",
                };
                return err_help(
                    m_span,
                    format!("`{other}` isn't valid here"),
                    format!("this call takes {valid}"),
                );
            }
        }
        if b.kind == BuilderKind::Insert && b.values.is_none() && m == "returning" {
            return err(span, "call values(...) before returning()");
        }
        Ok(Out::atom("", Ty::Builder(Box::new(b))))
    }

    /// Insert values / update patch: literal objects are checked field by field.
    fn write_values(&mut self, table: &str, e: &Expr, insert: bool) -> Result<String> {
        if let ExprKind::Array(items) = &e.kind
            && insert
        {
            let mut rows = Vec::new();
            for it in items {
                let ArrEl::Item(x) = it else {
                    return err(e.span, "spread isn't supported here");
                };
                rows.push(self.write_values(table, x, true)?);
            }
            return Ok(format!("[{}]", rows.join(", ")));
        }
        let v = self.expr(e)?;
        let v = self.finish_value(v, e.span)?;
        let key = self.schema.collections[table].key.clone();
        let fields: Vec<String> = match v.ty.non_null() {
            Ty::Obj(f) => f.keys().cloned().collect(),
            Ty::Array(el) | Ty::NonEmptyArray(el) => match *el {
                Ty::Obj(f) => f.keys().cloned().collect(),
                _ => vec!["*".into()],
            },
            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => ts
                .iter()
                .flat_map(|t| match t {
                    Ty::Obj(f) => f.keys().cloned().collect(),
                    _ => vec!["*".to_string()],
                })
                .collect(),
            _ => vec!["*".into()],
        };
        for field in fields {
            self.uses("write", format!("{key}.{field}"), e.span);
        }
        let want = if insert {
            types::insert(self.schema, table)
        } else {
            types::partial(types::insert(self.schema, table))
        };
        let want = types::storage_input(want);
        let ok = match (&v.ty, insert) {
            (Ty::Array(el) | Ty::NonEmptyArray(el), true) => assignable(el, &want),
            (t, _) => assignable(t, &want),
        };
        if !ok {
            // Point at the exact field when we can.
            if let (Ty::Obj(have), Ty::Obj(need)) = (&v.ty, &want) {
                for (k, (t, _)) in have {
                    match need.get(k) {
                        None => return Err(self.no_field(&want, k, e.span)),
                        Some((nt, _)) if !assignable(t, nt) => {
                            return err(e.span, format!("`{k}` must be {}, not {}", nt.describe(), t.describe()));
                        }
                        _ => {}
                    }
                }
                for (k, (_, optional)) in need {
                    if !optional && !have.contains_key(k) {
                        return err(e.span, format!("missing required field `{k}`"));
                    }
                }
            }
            return err(
                e.span,
                format!("{} doesn't fit \"{table}\" ({})", v.ty.describe(), want.describe()),
            );
        }
        Ok(v.php)
    }
}

/// The host of a URL whose `https://host/` prefix is written in the source: a string, a template
/// or a `+` chain starting with one. Anything else is built at runtime.
pub(super) fn static_host(e: &Expr) -> Option<String> {
    let complete = matches!(e.kind, ExprKind::Str(_));
    let prefix = match &e.kind {
        ExprKind::Str(s) => s.clone(),
        ExprKind::Template(quasis, _) => quasis.first()?.clone(),
        ExprKind::Binary(BinOp::Add, left, _) => {
            let mut left = left;
            while let ExprKind::Binary(BinOp::Add, l, _) = &left.kind {
                left = l;
            }
            match &left.kind {
                ExprKind::Str(s) => s.clone(),
                ExprKind::Template(quasis, _) => quasis.first()?.clone(),
                _ => return None,
            }
        }
        ExprKind::As(inner, _) | ExprKind::NonNull(inner) => return static_host(inner),
        _ => return None,
    };
    let rest = prefix
        .get(..8)
        .filter(|p| p.eq_ignore_ascii_case("https://"))
        .map(|_| &prefix[8..])?;
    let end = rest.find(['/', '?', '#']).or(complete.then_some(rest.len()))?;
    let authority = &rest[..end];
    if authority.is_empty() || authority.contains('@') {
        return None;
    }
    // A bracketed IPv6 literal holds colons of its own; only a port follows the bracket.
    let host = match authority.strip_prefix('[') {
        Some(rest) => rest.split_once(']')?.0,
        None => authority
            .rsplit_once(':')
            .filter(|(_, port)| port.chars().all(|c| c.is_ascii_digit()))
            .map_or(authority, |(h, _)| h),
    };
    // The same normalization `Zero\Fetch::target` applies before comparing hosts.
    Some(host.trim_end_matches('.').to_ascii_lowercase())
}

#[cfg(test)]
mod tests {
    use super::static_host;
    use crate::diag::Span;
    use crate::ir::{Expr, ExprKind};

    #[test]
    fn locked_hosts_normalize_like_the_runtime_fetch_check() {
        let host = |url: &str| {
            static_host(&Expr {
                kind: ExprKind::Str(url.to_string()),
                span: Span::NONE,
            })
        };
        assert_eq!(
            host("https://[2606:4700:4700::1111]/").as_deref(),
            Some("2606:4700:4700::1111")
        );
        assert_eq!(
            host("https://[2606:4700::1111]:8443/x").as_deref(),
            Some("2606:4700::1111")
        );
        assert_eq!(
            host("https://API.Example.com.:8443/v1").as_deref(),
            Some("api.example.com")
        );
        assert_eq!(host("https://api.example.com").as_deref(), Some("api.example.com"));
        assert_eq!(host("https://user@api.example.com/"), None);
    }
}

const FILTER_OPS: &[&str] = &[
    "eq",
    "ne",
    "gt",
    "gte",
    "lt",
    "lte",
    "in",
    "notIn",
    "like",
    "ilike",
    "notLike",
    "notIlike",
    "isNull",
    "isNotNull",
    "has",
    "NOT",
    "OR",
    "AND",
];

fn col_value_ty(c: &crate::schema::Column) -> Ty {
    match c.ty {
        ColType::Json => Ty::Unknown,
        _ => types::col_base(c, &|_| Ty::Unknown, false),
    }
}

fn obj_values_ty(t: Option<&Ty>) -> Ty {
    match t.map(Ty::non_null) {
        Some(Ty::Obj(f)) => Ty::union(f.values().map(|(t, _)| t.clone())),
        Some(Ty::Map(t)) => *t,
        _ => Ty::Unknown,
    }
}

/// `.` binds tighter than `+`/`-`... in PHP 8 they're equal-precedence-ish; parenthesize non-trivial parts.
fn pad(n: usize) -> String {
    "    ".repeat(n)
}

fn require_initialized_local(name: &str, local: &Local, span: Span) -> Result<()> {
    if !local.initialized {
        return err_help(
            span,
            format!("`{name}` is referenced before its lexical declaration"),
            "initialize the lexical binding before reading it or creating a closure that captures it",
        );
    }
    Ok(())
}

fn php_local_base(name: &str) -> String {
    if name == "this" {
        return "__zero_this".into();
    }
    let reserved = matches!(
        name,
        "GLOBALS" | "_SERVER" | "_GET" | "_POST" | "_FILES" | "_COOKIE" | "_SESSION" | "_REQUEST" | "_ENV"
    );
    let valid_start = name
        .bytes()
        .next()
        .is_some_and(|b| b.is_ascii_alphabetic() || b == b'_');
    if !reserved && valid_start && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_') {
        return name.to_string();
    }
    let encoded: String = name.bytes().map(|b| format!("{b:02x}")).collect();
    format!("__zero_local_{encoded}")
}

fn php_hint(t: &Ty) -> &'static str {
    match t {
        Ty::Instance(..) => "\\Zero\\JsObject ",
        Ty::Class(_) => "\\Zero\\JsClass ",
        Ty::Obj(_) | Ty::Map(_) => "\\Zero\\JsObject ",
        Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_) => "\\Zero\\JsArray ",
        t if t.is_nullable() && matches!(t.non_null(), Ty::Obj(_) | Ty::Map(_)) => {
            "\\Zero\\JsObject|\\Zero\\JsUndefined|null "
        }
        t if t.is_nullable()
            && matches!(
                t.non_null(),
                Ty::Array(_) | Ty::NonEmptyArray(_) | Ty::Tuple(_) | Ty::ArrayLiteral(_)
            ) =>
        {
            "\\Zero\\JsArray|\\Zero\\JsUndefined|null "
        }
        t if t.is_str() => "string ",
        t if t.is_bool() => "bool ",
        Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_) => "int|float ",
        _ => "",
    }
}

fn ty_of_data(value: &Value) -> Ty {
    match value {
        Value::Null => Ty::Null,
        Value::Undef => Ty::Undefined,
        Value::Bool(b) => Ty::FreshBoolLit(*b),
        Value::Num(n) => Ty::FreshNumLit(*n),
        Value::Str(s) => Ty::FreshStrLit(s.to_string()),
        Value::Array(a) => Ty::Array(Box::new(Ty::union(a.borrow().iter().map(ty_of_data)))),
        Value::Object(o) => Ty::Obj(
            o.borrow()
                .iter()
                .map(|(k, v)| (k.clone(), (ty_of_data(v), false)))
                .collect(),
        ),
        _ => unreachable!("validated captured data"),
    }
}

fn inferred_binding_type(t: &Ty, mutable: bool) -> Ty {
    match t {
        Ty::FreshNumLit(_) | Ty::FreshStrLit(_) | Ty::FreshBoolLit(_) if !mutable => t.clone(),
        Ty::Union(ts) => Ty::union(ts.iter().map(|t| inferred_binding_type(t, mutable))),
        _ => widen_fresh_literals(t),
    }
}

fn array_proven_nonempty(ty: &Ty) -> bool {
    match ty {
        Ty::NonEmptyArray(_) => true,
        Ty::Tuple(items) | Ty::ArrayLiteral(items) => !items.is_empty(),
        Ty::Union(parts) => !parts.is_empty() && parts.iter().all(array_proven_nonempty),
        _ => false,
    }
}

fn logical_result_type(op: LogOp, left: &Ty, right: &Ty) -> Ty {
    if op == LogOp::Nullish {
        return Ty::union([left.non_null(), right.clone()]);
    }
    let retain_truthy = op == LogOp::Or;
    let retained = truthiness_part(left, retain_truthy);
    if truthiness_part(left, !retain_truthy) == Ty::Never {
        retained
    } else {
        Ty::union([retained, right.clone()])
    }
}

fn number_is_finite_function() -> Out {
    Out::atom(
        "\\Zero\\Js::numberIsFiniteFunction()",
        Ty::Fn(vec![Ty::Unknown], Box::new(Ty::Bool)),
    )
}

fn scalar_typeof_part(t: &Ty, target: &Ty, matches: bool) -> Ty {
    match t {
        Ty::Union(items) => Ty::union(items.iter().map(|t| scalar_typeof_part(t, target, matches))),
        Ty::Unknown | Ty::Dynamic if matches => target.clone(),
        Ty::Unknown | Ty::Dynamic => t.clone(),
        _ => {
            let hit = match target {
                Ty::Num => t.is_num(),
                Ty::Str => t.is_str(),
                Ty::Bool => t.is_bool(),
                _ => unreachable!("scalar typeof target"),
            };
            if hit == matches { t.clone() } else { Ty::Never }
        }
    }
}

fn truthiness_part(t: &Ty, truthy: bool) -> Ty {
    match t {
        Ty::Union(ts) => Ty::union(ts.iter().map(|t| truthiness_part(t, truthy))),
        Ty::Bool => Ty::BoolLit(truthy),
        Ty::Predicate(..) => Ty::BoolLit(truthy),
        Ty::BoolLit(value) | Ty::FreshBoolLit(value) if *value == truthy => t.clone(),
        Ty::BoolLit(_) | Ty::FreshBoolLit(_) => Ty::Never,
        Ty::NumLit(n) | Ty::FreshNumLit(n) if (*n != 0.0 && !n.is_nan()) != truthy => Ty::Never,
        Ty::NumLit(_) | Ty::FreshNumLit(_) => t.clone(),
        Ty::Null | Ty::Undefined | Ty::Void if truthy => Ty::Never,
        Ty::Null | Ty::Undefined | Ty::Void => t.clone(),
        Ty::Str if !truthy => Ty::StrLit(String::new()),
        Ty::StrLit(value) | Ty::FreshStrLit(value) if value.is_empty() == truthy => Ty::Never,
        // `0n` is falsy, so a bigint stays on both branches, like a number.
        Ty::Str | Ty::StrLit(_) | Ty::FreshStrLit(_) | Ty::Num | Ty::BigInt | Ty::Unknown | Ty::Dynamic | Ty::Never => {
            t.clone()
        }
        Ty::Arguments
        | Ty::Array(_)
        | Ty::NonEmptyArray(_)
        | Ty::Instance(..)
        | Ty::Class(_)
        | Ty::Tuple(_)
        | Ty::ArrayLiteral(_)
        | Ty::Obj(_)
        | Ty::Map(_)
        | Ty::MapOf(..)
        | Ty::SetOf(_)
        | Ty::Iter(_)
        | Ty::AbortController
        | Ty::AbortSignal
        | Ty::Url
        | Ty::UrlParams
        | Ty::Fn(..)
        | Ty::Rest(_)
        | Ty::Promise(_)
        | Ty::Date
        | Ty::Regex
        | Ty::Host(_)
        | Ty::Error
        | Ty::Collection(_)
        | Ty::Db
        | Ty::DbQuery
        | Ty::QueryColl(_)
        | Ty::DbGlobals
        | Ty::DbGlobal(_)
        | Ty::Builder(_) => {
            if truthy {
                t.clone()
            } else {
                Ty::Never
            }
        }
    }
}

fn defined_type(ty: &Ty) -> Ty {
    match ty {
        Ty::Union(items) => Ty::union(
            items
                .iter()
                .filter(|item| !matches!(item, Ty::Undefined | Ty::Void))
                .cloned(),
        ),
        Ty::Undefined | Ty::Void => Ty::Never,
        _ => ty.clone(),
    }
}

fn default_binding_type(ty: &Ty, default: &Ty, mutable: bool) -> Ty {
    let present = defined_type(ty);
    if assignable(default, &present) {
        present
    } else {
        Ty::union([present, inferred_binding_type(default, mutable)])
    }
}

fn replacement_parameters(ty: &Ty, pattern: Option<&str>, count: usize) -> Vec<Ty> {
    let mut params = vec![Ty::Str];
    if !ty.is_str() {
        let Some(pattern) = pattern else {
            // A runtime regex can supply any number of captures before the offset.
            params.resize(count.max(1), Ty::Unknown);
            return params;
        };
        let mut chars = pattern.chars().peekable();
        let mut in_class = false;
        while let Some(c) = chars.next() {
            match c {
                '\\' => {
                    chars.next();
                }
                '[' => in_class = true,
                ']' => in_class = false,
                '(' if !in_class => {
                    let mut tail = chars.clone();
                    let captures = tail.next() != Some('?')
                        || (tail.next() == Some('<') && !matches!(tail.next(), Some('=' | '!')));
                    if captures {
                        params.push(Ty::union([Ty::Str, Ty::Undefined]));
                    }
                }
                _ => {}
            }
        }
    }
    params.extend([Ty::Num, Ty::Str]);
    params
}

fn check_parameter_argument(given: &Ty, declared: &Ty, span: Span) -> Result<()> {
    let given = widen_fresh_literals(given);
    if !assignable(&given, declared) {
        return err_help(
            span,
            format!(
                "this parameter is declared as {}, but it receives {}",
                declared.describe(),
                given.describe()
            ),
            "accept the supplied argument type, or change the values supplied to the function",
        );
    }
    Ok(())
}

fn check_callback_parameters(given: &[Ty], declared: &[Ty], span: Span) -> Result<()> {
    for (i, declared) in declared.iter().enumerate() {
        if let Ty::Rest(container) = declared {
            let Ty::Array(element) = container.as_ref() else {
                return err(span, "rest callback parameters need an array type");
            };
            for given in given.iter().skip(i) {
                check_parameter_argument(given, element, span)?;
            }
            break;
        }
        if given.get(i).is_none() && !accepts_omission(declared) {
            return err_help(
                span,
                format!("this callback never supplies required parameter {}", i + 1),
                "remove the parameter, make it optional, or accept undefined",
            );
        }
        check_parameter_argument(given.get(i).unwrap_or(&Ty::Undefined), declared, span)?;
    }
    Ok(())
}

fn callable_parameters(function: &Func, parameters: &[Ty]) -> Vec<Ty> {
    function
        .params
        .iter()
        .zip(parameters)
        .map(|(parameter, ty)| {
            if parameter.rest {
                Ty::Rest(Box::new(ty.clone()))
            } else {
                ty.clone()
            }
        })
        .collect()
}

fn callable_argument(parameters: &[Ty], index: usize) -> Option<Ty> {
    if let Some((rest_index, Ty::Rest(container))) = parameters.iter().enumerate().next_back()
        && index >= rest_index
    {
        return match container.as_ref() {
            Ty::Array(element) | Ty::NonEmptyArray(element) => Some((**element).clone()),
            Ty::Tuple(elements) | Ty::ArrayLiteral(elements) => elements.get(index - rest_index).cloned(),
            _ => None,
        };
    }
    parameters.get(index).cloned()
}

fn accepts_omission(ty: &Ty) -> bool {
    match ty {
        Ty::Unknown | Ty::Dynamic | Ty::Undefined | Ty::Void | Ty::Rest(_) => true,
        Ty::Union(items) => items.iter().any(accepts_omission),
        _ => false,
    }
}

fn parameter_default_suffix(parameters: &[Param], types: &[Ty]) -> &'static str {
    if parameters
        .iter()
        .zip(types)
        .all(|(parameter, ty)| parameter.rest || accepts_omission(ty))
    {
        " = \\Zero\\JsUndefined::Value"
    } else {
        ""
    }
}

/// The inner function of a lowered named function expression: an immediately called
/// parameterless arrow whose body declares one named function and returns it.
fn named_function_expression(e: &Expr) -> Option<&Rc<Func>> {
    let ExprKind::Call { callee, args, .. } = &e.kind else {
        return None;
    };
    let ExprKind::Func(wrapper) = &callee.kind else {
        return None;
    };
    let FnBody::Block(body) = &wrapper.body else {
        return None;
    };
    match (
        args.is_empty() && wrapper.is_arrow && wrapper.params.is_empty(),
        body.as_slice(),
    ) {
        (
            true,
            [
                Stmt::Func(inner),
                Stmt::Return(
                    Some(Expr {
                        kind: ExprKind::Ident(name),
                        ..
                    }),
                    _,
                ),
            ],
        ) if inner.name.as_deref() == Some(name.as_str()) => Some(inner),
        _ => None,
    }
}

fn parameter_input_type(parameter: &Param, ty: Ty) -> Ty {
    if parameter.optional || parameter.default.is_some() {
        Ty::union([ty, Ty::Undefined])
    } else {
        ty
    }
}

/// The type a typed `for await` element settles to under the blocking model: a Zero promise is
/// its value, a plain value is itself. `None` for values only the job queue can settle (anything
/// from imported JavaScript, unknown values, builders that were never awaited).
fn awaited_element(t: &Ty) -> Option<Ty> {
    match t {
        Ty::Promise(inner) => awaited_element(inner),
        Ty::Union(items) => items
            .iter()
            .map(awaited_element)
            .collect::<Option<Vec<_>>>()
            .map(Ty::union),
        Ty::Dynamic | Ty::Unknown | Ty::Builder(_) => None,
        // PromiseResolve adopts an object with a callable (or possibly callable) `then`, so a
        // thenable element binds its settled value in JavaScript; only the job queue can settle it.
        Ty::Obj(_) | Ty::Map(_) if thenable(t) => None,
        // A class instance's `then` may be a method, which its field type doesn't show.
        Ty::Instance(..) | Ty::Class(_) => None,
        other => Some(other.clone()),
    }
}

fn thenable(t: &Ty) -> bool {
    t.field("then").is_some_and(|(then, _)| {
        then.non_null()
            .all(|t| matches!(t, Ty::Fn(..) | Ty::Unknown | Ty::Dynamic))
    })
}

fn widen_fresh_literals(t: &Ty) -> Ty {
    match t {
        Ty::Instance(id, fields) => Ty::Instance(id.clone(), Box::new(widen_fresh_literals(fields))),
        Ty::Class(_) => t.clone(),
        Ty::Predicate(..) => t.clone(),
        Ty::FreshNumLit(_) => Ty::Num,
        Ty::FreshStrLit(_) => Ty::Str,
        Ty::FreshBoolLit(_) => Ty::Bool,
        Ty::ArrayLiteral(ts) => Ty::Array(Box::new(Ty::union(ts.iter().map(widen_fresh_literals)))),
        Ty::Tuple(ts) => Ty::Tuple(ts.iter().map(widen_fresh_literals).collect()),
        Ty::Array(t) => Ty::Array(Box::new(widen_fresh_literals(t))),
        Ty::NonEmptyArray(t) => Ty::NonEmptyArray(Box::new(widen_fresh_literals(t))),
        Ty::Rest(t) => Ty::Rest(Box::new(widen_fresh_literals(t))),
        Ty::Union(ts) => Ty::union(ts.iter().map(widen_fresh_literals)),
        Ty::Obj(f) => Ty::Obj(
            f.iter()
                .map(|(k, (t, o))| (k.clone(), (widen_fresh_literals(t), *o)))
                .collect(),
        ),
        Ty::Map(t) => Ty::Map(Box::new(widen_fresh_literals(t))),
        // Map and Set type arguments are invariant handles; they never carry fresh literals.
        Ty::MapOf(..)
        | Ty::SetOf(_)
        | Ty::Iter(_)
        | Ty::AbortController
        | Ty::AbortSignal
        | Ty::Url
        | Ty::UrlParams => t.clone(),
        Ty::Fn(params, ret) => Ty::Fn(
            params.iter().map(widen_fresh_literals).collect(),
            Box::new(widen_fresh_literals(ret)),
        ),
        Ty::Promise(t) => Ty::Promise(Box::new(widen_fresh_literals(t))),
        Ty::BigInt
        | Ty::Num
        | Ty::NumLit(_)
        | Ty::Str
        | Ty::StrLit(_)
        | Ty::Bool
        | Ty::BoolLit(_)
        | Ty::Null
        | Ty::Undefined
        | Ty::Void
        | Ty::Unknown
        | Ty::Dynamic
        | Ty::Arguments
        | Ty::Never
        | Ty::Date
        | Ty::Regex
        | Ty::Host(_)
        | Ty::Error
        | Ty::Collection(_)
        | Ty::Db
        | Ty::DbQuery
        | Ty::QueryColl(_)
        | Ty::DbGlobals
        | Ty::DbGlobal(_)
        | Ty::Builder(_) => t.clone(),
    }
}

/// Is a value of type `a` usable where `b` is expected?
pub fn assignable(a: &Ty, b: &Ty) -> bool {
    if a == b || *b == Ty::Unknown || *b == Ty::Dynamic || *a == Ty::Never {
        return true;
    }
    match (a, b) {
        (Ty::Instance(id, fields), Ty::Instance(other, want)) => {
            (id.id == other.id || id.ancestors.contains(&other.id))
                && match (&**fields, &**want) {
                    (Ty::Obj(have), Ty::Obj(need)) => need.iter().all(|(key, (ty, optional))| {
                        have.get(key).map_or(*optional, |(value, _)| assignable(value, ty))
                    }),
                    _ => false,
                }
        }
        (Ty::Instance(_, fields), Ty::Obj(_)) => assignable(fields, b),
        (Ty::NumLit(_) | Ty::FreshNumLit(_), Ty::Num) => true,
        (Ty::NumLit(x), Ty::FreshNumLit(y)) | (Ty::FreshNumLit(x), Ty::NumLit(y)) => x == y,
        (Ty::StrLit(_) | Ty::FreshStrLit(_), Ty::Str) => true,
        (Ty::BoolLit(_) | Ty::FreshBoolLit(_), Ty::Bool) => true,
        (Ty::Predicate(..), Ty::Bool) => true,
        (Ty::BoolLit(x), Ty::FreshBoolLit(y)) | (Ty::FreshBoolLit(x), Ty::BoolLit(y)) => x == y,
        (Ty::StrLit(x), Ty::FreshStrLit(y)) | (Ty::FreshStrLit(x), Ty::StrLit(y)) => x == y,
        (Ty::Union(xs), _) => xs.iter().all(|x| assignable(x, b)),
        (_, Ty::Union(ys)) => ys.iter().any(|y| assignable(a, y)),
        (Ty::Array(x), Ty::Array(y)) => **x == Ty::Never || assignable(x, y),
        (Ty::NonEmptyArray(x), Ty::Array(y) | Ty::NonEmptyArray(y)) => assignable(x, y),
        (Ty::Tuple(xs) | Ty::ArrayLiteral(xs), Ty::NonEmptyArray(y)) => {
            !xs.is_empty() && xs.iter().all(|x| assignable(x, y))
        }
        (Ty::Tuple(xs) | Ty::ArrayLiteral(xs), Ty::Array(y)) => xs.iter().all(|x| assignable(x, y)),
        (Ty::Tuple(xs) | Ty::ArrayLiteral(xs), Ty::Tuple(ys) | Ty::ArrayLiteral(ys)) => {
            xs.len() == ys.len() && xs.iter().zip(ys).all(|(x, y)| assignable(x, y))
        }
        (Ty::Obj(have), Ty::Obj(need)) => {
            need.iter().all(|(k, (t, optional))| match have.get(k) {
                Some((ht, have_optional)) => {
                    (!have_optional || *optional)
                        && assignable(
                            ht,
                            &if *optional {
                                Ty::union([t.clone(), Ty::Undefined])
                            } else {
                                t.clone()
                            },
                        )
                }
                None => *optional,
            }) && have.keys().all(|k| need.contains_key(k))
        }
        (Ty::Obj(have), Ty::Map(v)) => have.values().all(|(t, _)| assignable(t, v)),
        (Ty::Map(x), Ty::Map(y)) => assignable(x, y),
        (Ty::Rest(x), Ty::Rest(y)) => assignable(x, y),
        (Ty::Promise(x), Ty::Promise(y)) => assignable(x, y),
        // Mutable handles: TypeScript checks these covariantly, which is unsound for writes.
        // Zero requires the same element types (ReadonlyMap/ReadonlySet are the same handle).
        (Ty::MapOf(ka, va), Ty::MapOf(kb, vb)) => same_type(ka, kb) && same_type(va, vb),
        (Ty::SetOf(a), Ty::SetOf(b)) => same_type(a, b),
        (Ty::Iter(a), Ty::Iter(b)) => assignable(a, b),
        (Ty::Fn(pa, ra), Ty::Fn(pb, rb)) => {
            pa.len() <= pb.len() && pa.iter().zip(pb).all(|(x, y)| assignable(y, x)) && assignable(ra, rb)
        }
        _ => false,
    }
}

/// URL's writable accessors (`origin` and `searchParams` are read-only).
const URL_SETTERS: &[&str] = &[
    "href", "protocol", "username", "password", "host", "hostname", "port", "pathname", "search", "hash",
];

/// URL's string-valued accessors.
const URL_PROPERTIES: &[&str] = &[
    "href", "origin", "protocol", "username", "password", "host", "hostname", "port", "pathname", "search", "hash",
];

fn same_type(a: &Ty, b: &Ty) -> bool {
    assignable(a, b) && assignable(b, a)
}

fn stmt_span(s: &Stmt) -> Span {
    match s {
        Stmt::Decl { span, .. } | Stmt::Return(_, span) | Stmt::Break(_, span) | Stmt::Continue(_, span) => *span,
        Stmt::Expr(e) | Stmt::Throw(e) | Stmt::If(e, ..) | Stmt::While(e, _) | Stmt::DoWhile(_, e) => e.span,
        Stmt::Labeled(_, _, span) => *span,
        Stmt::For { span, .. } | Stmt::ForOf { span, .. } | Stmt::Switch { span, .. } => *span,
        Stmt::Func(f) => f.span,
        _ => Span::NONE,
    }
}

fn ends_with_exit(stmts: &[Stmt]) -> bool {
    matches!(
        stmts.last(),
        Some(Stmt::Return(..) | Stmt::Throw(_) | Stmt::Continue(..) | Stmt::Break(..))
    )
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum JumpKind {
    Loop,
    Switch,
    Block,
}

#[derive(Debug, Clone)]
struct JumpTarget {
    kind: JumpKind,
    labels: Vec<String>,
}

#[derive(Clone, Copy, PartialEq, Eq, Hash)]
enum Completion {
    Normal,
    Return,
    Throw,
    Break,
    /// `break label`: only the labeled statement it names completes normally on it.
    BreakLabel,
    Continue,
}

fn followed_by(mut first: HashSet<Completion>, second: HashSet<Completion>) -> HashSet<Completion> {
    if first.remove(&Completion::Normal) {
        first.extend(second);
    }
    first
}

fn switch_is_exhaustive(ty: &Ty, case_types: &[Ty]) -> bool {
    match ty {
        Ty::Bool => {
            case_types.iter().any(|ty| ty.bool_literal() == Some(true))
                && case_types.iter().any(|ty| ty.bool_literal() == Some(false))
        }
        Ty::BoolLit(value) | Ty::FreshBoolLit(value) => case_types.iter().any(|ty| ty.bool_literal() == Some(*value)),
        Ty::StrLit(value) | Ty::FreshStrLit(value) => case_types
            .iter()
            .any(|ty| matches!(ty, Ty::StrLit(case) | Ty::FreshStrLit(case) if case == value)),
        Ty::NumLit(value) | Ty::FreshNumLit(value) => case_types
            .iter()
            .any(|ty| matches!(ty, Ty::NumLit(case) | Ty::FreshNumLit(case) if case == value)),
        Ty::Null => case_types.contains(&Ty::Null),
        Ty::Union(items) => items.iter().all(|ty| switch_is_exhaustive(ty, case_types)),
        Ty::Never => true,
        _ => false,
    }
}

fn block_completions(
    stmts: &[Stmt],
    diverging: &HashSet<Span>,
    exhaustive_switches: &HashSet<Span>,
) -> HashSet<Completion> {
    stmts.iter().fold(HashSet::from([Completion::Normal]), |flow, stmt| {
        followed_by(flow, stmt_completions(stmt, diverging, exhaustive_switches))
    })
}

fn stmt_completions(
    stmt: &Stmt,
    diverging: &HashSet<Span>,
    exhaustive_switches: &HashSet<Span>,
) -> HashSet<Completion> {
    use Completion::*;
    match stmt {
        Stmt::Expr(expr)
        | Stmt::Decl { init: Some(expr), .. }
        | Stmt::If(expr, ..)
        | Stmt::While(expr, _)
        | Stmt::Switch { disc: expr, .. }
            if diverging.contains(&expr.span) =>
        {
            HashSet::from([Throw])
        }
        Stmt::Return(..) => HashSet::from([Return]),
        Stmt::Throw(_) => HashSet::from([Throw]),
        Stmt::Break(None, _) => HashSet::from([Break]),
        Stmt::Break(Some(_), _) => HashSet::from([BreakLabel]),
        Stmt::Continue(..) => HashSet::from([Continue]),
        Stmt::Block(body) => block_completions(body, diverging, exhaustive_switches),
        Stmt::If(test, yes, no) => {
            let yes = block_completions(yes, diverging, exhaustive_switches);
            let no = no
                .as_deref()
                .map(|body| block_completions(body, diverging, exhaustive_switches))
                .unwrap_or_else(|| HashSet::from([Normal]));
            match test.kind {
                ExprKind::Bool(true) => yes,
                ExprKind::Bool(false) => no,
                _ => yes.union(&no).copied().collect(),
            }
        }
        Stmt::Try {
            block,
            handler,
            finalizer,
            ..
        } => {
            let mut flow = block_completions(block, diverging, exhaustive_switches);
            if let Some(handler) = handler {
                flow.remove(&Throw);
                flow.extend(block_completions(handler, diverging, exhaustive_switches));
            }
            if let Some(finalizer) = finalizer {
                let mut final_flow = block_completions(finalizer, diverging, exhaustive_switches);
                if final_flow.remove(&Normal) {
                    final_flow.extend(flow);
                }
                flow = final_flow;
            }
            flow
        }
        Stmt::Switch { cases, span, .. } => {
            let mut suffix = HashSet::from([Normal]);
            let mut flow = HashSet::new();
            for (_, body) in cases.iter().rev() {
                suffix = followed_by(block_completions(body, diverging, exhaustive_switches), suffix);
                flow.extend(&suffix);
            }
            let breaks = flow.remove(&Break);
            if !(cases.iter().any(|(test, _)| test.is_none()) || exhaustive_switches.contains(span)) || breaks {
                flow.insert(Normal);
            }
            flow
        }
        Stmt::While(test, body)
        | Stmt::For {
            test: Some(test), body, ..
        } => {
            let mut flow = block_completions(body, diverging, exhaustive_switches);
            let breaks = flow.remove(&Break);
            flow.remove(&Continue);
            flow.remove(&Normal);
            if !matches!(test.kind, ExprKind::Bool(true)) || breaks {
                flow.insert(Normal);
            }
            flow
        }
        Stmt::For { test: None, body, .. } => {
            let mut flow = block_completions(body, diverging, exhaustive_switches);
            if flow.remove(&Break) {
                flow.insert(Normal);
            } else {
                flow.remove(&Normal);
            }
            flow.remove(&Continue);
            flow
        }
        Stmt::ForOf { body, .. } => {
            let mut flow = block_completions(body, diverging, exhaustive_switches);
            flow.remove(&Break);
            flow.remove(&Continue);
            flow.insert(Normal);
            flow
        }
        // The test runs only after the body completes normally or continues.
        Stmt::DoWhile(body, test) => {
            let mut flow = block_completions(body, diverging, exhaustive_switches);
            let breaks = flow.remove(&Break);
            let tested = flow.remove(&Normal) | flow.remove(&Continue);
            if tested && diverging.contains(&test.span) {
                flow.insert(Throw);
            }
            if breaks || tested && !matches!(test.kind, ExprKind::Bool(true)) && !diverging.contains(&test.span) {
                flow.insert(Normal);
            }
            flow
        }
        // `break label` completes the labeled statement normally. Labels are not tracked here,
        // so any break in the body is read as one (which only adds reachable paths).
        Stmt::Labeled(_, body, _) => {
            let mut flow = stmt_completions(body, diverging, exhaustive_switches);
            if flow.remove(&BreakLabel) {
                flow.insert(Normal);
            }
            flow
        }
        _ => HashSet::from([Normal]),
    }
}

fn collect_aliases(
    body: &[Stmt],
    module: usize,
    out: &mut HashMap<(usize, String), (Vec<TypeParam>, TyAnn)>,
    duplicates: &mut HashSet<(usize, String)>,
) {
    for s in body {
        match s {
            Stmt::TypeAlias { name, params, ty } => {
                let key = (module, name.clone());
                if out.insert(key.clone(), (params.clone(), ty.clone())).is_some() {
                    duplicates.insert(key);
                }
            }
            Stmt::Exported(inner) => collect_aliases(std::slice::from_ref(inner.as_ref()), module, out, duplicates),
            _ => {}
        }
    }
}

fn paren(s: &str) -> String {
    format!("({s})")
}

fn check_optional_chain(e: &Expr) -> Result<bool> {
    let (receiver, optional) = match &e.kind {
        ExprKind::Member { obj, optional, .. } | ExprKind::Index { obj, optional, .. } => (obj, *optional),
        ExprKind::Call { callee, optional, .. } => match &callee.kind {
            ExprKind::Member { .. } | ExprKind::Index { .. } => return check_optional_chain(callee),
            _ => (callee, *optional),
        },
        ExprKind::As(inner, _) | ExprKind::NonNull(inner) => return check_optional_chain(inner),
        _ => return Ok(false),
    };
    let short = check_optional_chain(receiver)?;
    if short && !optional {
        return err_help(
            e.span,
            "non-optional continuation of an optional chain isn't supported",
            "guard each nullable step explicitly before continuing the access or call",
        );
    }
    Ok(short || optional)
}

fn pattern_default_effects(pat: &Pat) -> bool {
    match pat {
        Pat::Default(inner, value) => !pure_read(value) || pattern_default_effects(inner),
        Pat::Obj { props, .. } => props.iter().any(|(_, pat)| pattern_default_effects(pat)),
        Pat::Arr { elems, .. } => elems.iter().flatten().any(pattern_default_effects),
        Pat::Id(..) => false,
    }
}

/// Direct PHP comparisons are safe only when evaluating the right side cannot replace the left.
fn pure_read(e: &Expr) -> bool {
    match &e.kind {
        ExprKind::Ident(_)
        | ExprKind::Num(_)
        | ExprKind::Str(_)
        | ExprKind::Bool(_)
        | ExprKind::Null
        | ExprKind::Undefined => true,
        ExprKind::Member { obj, .. } => pure_read(obj),
        ExprKind::Index { obj, index, .. } => pure_read(obj) && pure_read(index),
        ExprKind::NonNull(e) | ExprKind::As(e, _) | ExprKind::Chain(e) => pure_read(e),
        _ => false,
    }
}

/// Numeric literals may have signs and erased type annotations, but no runtime computation.
fn literal_position(e: &Expr) -> bool {
    match &e.kind {
        ExprKind::Num(_) => true,
        ExprKind::As(x, _) | ExprKind::NonNull(x) | ExprKind::Unary(UnOp::Plus | UnOp::Neg, x) => literal_position(x),
        _ => false,
    }
}
