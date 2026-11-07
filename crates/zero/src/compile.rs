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
            "capture plain data instead",
        ),
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
    array: bool,
    dynamic: bool,
}

enum CapturedPlace {
    Binding(String),
    Object { receiver: String, key: String },
    Array { receiver: String, key: String },
    Dynamic { receiver: String, key: String },
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
    /// Parameter names that need `&` because the function mutates them.
    /// Locals declared so far in this PHP function (for closure `use` lists).
    declared: Vec<String>,
    /// Inside a nested closure: names from the enclosing function it uses.
    captures: Option<Vec<(String, bool)>>,
    outer: Option<Box<FnCtx>>,
    tmp: usize,
    /// Switches between the current statement and the nearest loop (PHP counts them for `continue`).
    switch_depth: usize,
    /// Access rules can't query (§1.6): `db` is rejected inside them and their closures.
    no_db: bool,
    /// Set while compiling an access rule's own body: (collection table, op). Its returns are
    /// `true`/`false` or a filter literal checked against that collection.
    access: Option<(Option<String>, String)>,
}

pub struct Compiler<'a, 'p> {
    classes: HashMap<usize, classes::CompiledClass>,
    pub schema: &'a Schema,
    ev: &'a mut Evaluator<'p>,
    collections: &'a [Rc<CollectionVal>],
    type_aliases: HashMap<(usize, String), (Vec<TypeParam>, TyAnn)>,
    duplicate_type_aliases: HashSet<(usize, String)>,
    annotation_depth: usize,
    annotation_steps: usize,
    resolving_json_types: HashSet<usize>,
    /// Compiled module-level helpers, separated by whether database access is forbidden.
    helpers: HashMap<(usize, bool), String>,
    helper_values: HashMap<usize, String>,
    helper_types: HashMap<usize, Ty>,
    data_helpers: HashMap<(u8, usize), String>,
    effects: effects::Effects,
    used_names: HashSet<String>,
    pending: Vec<(Rc<Closure>, String, Option<Vec<Ty>>)>,
    pub output: String,
    fx: Option<FnCtx>,
    /// Inferred result type of each action handler (for the typed client).
    pub action_results: HashMap<String, Ty>,
    /// Set for the one callback `once()` runs in place, which may be async (lane D).
    async_callback: bool,
}

fn closure_key(c: &Rc<Closure>) -> usize {
    Rc::as_ptr(&c.func) as *const () as usize
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
            schema,
            ev,
            collections: &ext.collections,
            type_aliases,
            duplicate_type_aliases,
            annotation_depth: 0,
            annotation_steps: 0,
            resolving_json_types: HashSet::new(),
            async_callback: false,
            helpers: HashMap::new(),
            helper_values: HashMap::new(),
            helper_types: HashMap::new(),
            data_helpers: HashMap::new(),
            effects: effects::Effects::default(),
            used_names: HashSet::new(),
            pending: Vec::new(),
            output: String::new(),
            fx: None,
            action_results: HashMap::new(),
        }
    }

    fn fx(&mut self) -> &mut FnCtx {
        self.fx.as_mut().expect("inside a function")
    }

    fn unique_name(&mut self, base: &str) -> String {
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
        let Some((ann, module)) = &col.ts_type else {
            return Ok(());
        };
        let key = col as *const Column as usize;
        if JSON_TYPES.with(|m| m.borrow().contains_key(&key)) {
            return Ok(());
        }
        if self.resolving_json_types.len() >= 96 {
            return err(
                annotations::annotation_span(ann),
                "JSON model expansion exceeds Zero's 96-level limit",
            );
        }
        if !self.resolving_json_types.insert(key) {
            return err(
                annotations::annotation_span(ann),
                "recursive JSON model annotations aren't supported",
            );
        }
        let result = (|| {
            let authored = self.resolve_annotation_root(ann, *module)?;
            let ty = annotations::runtime_type(authored.clone());
            if classes::wire_type(&ty) != ty {
                return err_help(
                    annotations::annotation_span(ann),
                    "JSON schemas cannot use class instance types",
                    "describe the stored own fields with an object type; JSON does not restore methods or instanceof identity",
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
        for root in roots {
            let params = self.root_params(&root.slot);
            let name = self.unique_name(&root.name);
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
                self.action_results.insert(action.clone(), ret);
            }
            self.helpers.entry((key, no_db)).or_insert_with(|| name.clone());
            out.push((root.slot.clone(), name));
        }
        while let Some((closure, name, params)) = self.pending.pop() {
            self.compile_function(&closure, &name, params, None, false)?;
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
                    Owner::FileBucket(_) => unreachable!("file buckets have no validator fields"),
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
        let f = closure.func.clone();
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
            ret: None,
            returns: Vec::new(),
            diverging: HashSet::new(),
            exhaustive_switches: HashSet::new(),
            loop_bindings: HashSet::new(),
            env: closure.env.clone(),
            module: f.module,
            declared: Vec::new(),
            captures: None,
            outer: None,
            tmp: 0,
            switch_depth: 0,
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
        let result = self.compile_function_body(&f, name, &param_tys, slot);
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
        let _ = writeln!(self.output, "function {name}({params_php})\n{{\n{body}}}\n");
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
        let mut params_php = Vec::new();
        let mut prologue = String::new();
        for (i, p) in f.params.iter().enumerate() {
            let t = param_tys[i].clone();
            if p.rest {
                let Pat::Id(name, _) = &p.pat else {
                    return err(p.span, "rest parameters need a plain name");
                };
                let php = self.declare_local(name, t, true, p.pat.span());
                params_php.push(format!("...$__rest{i}"));
                let _ = writeln!(prologue, "    {php} = new \\Zero\\JsArray($__rest{i});");
                continue;
            }
            match &p.pat {
                Pat::Id(_, _) => {
                    let php = self.declare_parameter(p, &t, &mut prologue, 1)?;
                    let default = if self.javascript_module(f.module) {
                        " = \\Zero\\JsUndefined::Value"
                    } else {
                        parameter_default_suffix(p)
                    };
                    let hint = php_hint(&t);
                    params_php.push(format!("{hint}{php}{default}"));
                }
                pat => {
                    let tmp = format!("$__arg{i}");
                    params_php.push(format!("{}{tmp}{}", php_hint(&t), parameter_default_suffix(p)));
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
        let mut body = self.javascript_prologue(f, 1)?;
        body.push_str(&prologue);
        match &f.body {
            FnBody::Expr(e) => {
                let v = self.return_value(e)?;
                self.note_return(&v.ty, e.span)?;
                let _ = writeln!(body, "    return {};", v.php);
            }
            FnBody::Block(stmts) => {
                body.push_str(&self.block(stmts, 1)?);
                self.note_fallthrough(stmts, f.span)?;
                if self.javascript_module(f.module) {
                    body.push_str("    return \\Zero\\Js::undefined();\n");
                }
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
            self.note_return(&Ty::Null, span)?;
        }
        Ok(())
    }

    // ---- scopes -------------------------------------------------------------

    fn declare_local(&mut self, name: &str, ty: Ty, mutable: bool, binding: Span) -> String {
        let read_ty = self.effects.parameter_type(binding, &ty);
        let fx = self.fx();
        let shadow = fx.scopes.iter().any(|s| s.contains_key(name)) || fx.declared.iter().any(|d| d == name);
        let php = if shadow && !fx.scopes.last().expect("scope").contains_key(name) {
            fx.tmp += 1;
            format!("${}_{}", name, fx.tmp)
        } else {
            if name == "this" {
                "$__zero_this".into()
            } else {
                format!("${name}")
            }
        };
        fx.declared.push(php.trim_start_matches('$').to_string());
        fx.scopes.last_mut().expect("scope").insert(
            name.to_string(),
            Local {
                php: php.clone(),
                constraint: ty.clone(),
                ty: read_ty,
                mutable,
                binding,
                view: Vec::new(),
            },
        );
        php
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
                return Ok(Resolved::Local(l.clone()));
            }
        }
        let mut functions = vec![fx.function];
        let mut outer = fx.outer.as_deref();
        let mut found = None;
        while let Some(o) = outer {
            if let Some(l) = o.scopes.iter().rev().find_map(|s| s.get(name)) {
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
        // Local function declarations are hoisted: compile them first.
        for s in stmts {
            if let Stmt::Func(f) = s {
                let name = f.name.clone().expect("named");
                let php = self.declare_local(&name, Ty::Unknown, false, f.span);
                let c = self.closure_expr(f)?;
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
                Stmt::Return(..) | Stmt::Throw(_) | Stmt::Break(_) | Stmt::Continue(_)
            );
        }
        Ok(out)
    }

    fn stmt(&mut self, s: &Stmt, indent: usize) -> Result<String> {
        let p = pad(indent);
        let mut out = String::new();
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
                            let ty = widen_fresh_literals(&value.ty);
                            for scope in self.fx().scopes.iter_mut().rev() {
                                if let Some(local) = scope.get_mut(name) {
                                    if local.constraint == Ty::Unknown {
                                        local.constraint = ty.clone();
                                    }
                                    local.ty = ty;
                                    break;
                                }
                            }
                        }
                    }
                    return Ok(out);
                }
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
                            return err(
                                *span,
                                format!("{} isn't assignable to {}", v.ty.describe(), d.describe()),
                            );
                        }
                        d.clone()
                    }
                    (Some(d), None) => d.clone().nullable(),
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
                        let v = value
                            .map(|v| v.php)
                            .unwrap_or_else(|| if javascript { "\\Zero\\Js::undefined()" } else { "null" }.into());
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
                    let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                    self.note_return(&if javascript { Ty::Dynamic } else { Ty::Null }, *span)?;
                    let _ = writeln!(
                        out,
                        "{p}return {};",
                        if javascript { "\\Zero\\Js::undefined()" } else { "null" }
                    );
                }
            },
            Stmt::If(test, then, otherwise) => {
                let narrow = self.narrowing(test);
                let t = self.cond(test)?;
                let _ = writeln!(out, "{p}if ({t}) {{");
                self.fx().scopes.push(HashMap::new());
                self.apply_narrowing(&narrow, true);
                out.push_str(&self.stmts(then, indent + 1)?);
                self.fx().scopes.pop();
                let then_exits = ends_with_exit(then);
                if let Some(o) = otherwise {
                    let _ = writeln!(out, "{p}}} else {{");
                    self.fx().scopes.push(HashMap::new());
                    self.apply_narrowing(&narrow, false);
                    out.push_str(&self.stmts(o, indent + 1)?);
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
                out.push_str(&self.loop_body(body, indent + 1)?);
                let _ = writeln!(out, "{p}}}");
                self.fx().scopes.pop();
            }
            Stmt::ForOf {
                kind,
                pat,
                iter,
                body,
                span,
            } => {
                let it = self.expr(iter)?;
                let it = self.finish_value(it, iter.span)?;
                let (el, src) = match &it.ty.non_null() {
                    Ty::Dynamic => (Ty::Dynamic, format!("\\Zero\\Js::dynamicIterable({})", it.php)),
                    Ty::Array(t) | Ty::NonEmptyArray(t) => ((**t).clone(), it.php.clone()),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => (Ty::union(ts.clone()), it.php.clone()),
                    t if t.is_str() => (Ty::Str, format!("\\Zero\\Js::from({})", it.php)),
                    other => return err(iter.span, format!("can't loop over {}", other.describe())),
                };
                if it.ty.is_nullable() {
                    return err_help(
                        iter.span,
                        format!("this might be null ({})", it.ty.describe()),
                        "check it first, or use `?? []`",
                    );
                }
                self.fx().scopes.push(HashMap::new());
                let el = widen_fresh_literals(&el);
                let mutable = *kind != DeclKind::Const;
                let source = self.tmp_var();
                let index = self.tmp_var();
                let value = self.tmp_var();
                let _ = writeln!(
                    out,
                    "{p}{source} = {src};\n{p}for ({index} = 0; {index} < count({source}->a); {index}++) {{"
                );
                let _ = writeln!(out, "{}{value} = {source}->a[{index}];", pad(indent + 1));
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
                out.push_str(&self.loop_body(body, indent + 1)?);
                let _ = writeln!(out, "{p}}}");
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
                let value = self.expr(iter)?;
                let source = self.tmp_var();
                let index = self.tmp_var();
                self.fx().scopes.push(HashMap::new());
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
                    "{p}{source} = \\Zero\\Js::dynamicKeys({});\n{p}foreach ({source}->a as {index}) {{",
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
                out.push_str(&self.loop_body(body, indent + 1)?);
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
                out.push_str(&self.loop_body(body, indent + 1)?);
                let _ = writeln!(out, "{p}}}");
            }
            Stmt::Break(_) => {
                let _ = writeln!(out, "{p}break;");
            }
            Stmt::Continue(_) => {
                // In PHP a `switch` counts as a loop level for `continue`.
                match self.fx().switch_depth {
                    0 => writeln!(out, "{p}continue;"),
                    n => writeln!(out, "{p}continue {};", n + 1),
                }
                .ok();
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
                out.push_str(&self.block(block, indent + 1)?);
                if let Some(h) = handler {
                    self.fx().scopes.push(HashMap::new());
                    let var = match param {
                        Some((n, span)) => self.declare_local(n, Ty::Error, false, *span),
                        None => "$__e".to_string(),
                    };
                    // Database and validation errors propagate: they must roll back the request.
                    let _ = writeln!(out, "{p}}} catch (\\Zero\\JsError {var}) {{");
                    out.push_str(&self.stmts(h, indent + 1)?);
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
                    self.fx().switch_depth += 1;
                    let inner = self.block(body, indent + 2);
                    self.fx().switch_depth -= 1;
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

    fn loop_body(&mut self, body: &[Stmt], indent: usize) -> Result<String> {
        let saved = std::mem::take(&mut self.fx().switch_depth);
        let saved_bindings = self.fx().loop_bindings.clone();
        let bindings: Vec<_> = self
            .fx()
            .scopes
            .iter()
            .flat_map(|scope| scope.values().map(|l| l.binding))
            .collect();
        self.fx().loop_bindings.extend(bindings);
        let r = self.block(body, indent);
        self.fx().loop_bindings = saved_bindings;
        self.fx().switch_depth = saved;
        r
    }

    fn tmp_var(&mut self) -> String {
        let fx = self.fx();
        fx.tmp += 1;
        let name = format!("__t{}", fx.tmp);
        fx.declared.push(name.clone());
        format!("${name}")
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
        if let ExprKind::New { callee, args } = &e.kind {
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
                let missing = if self.javascript_module(self.fx.as_ref().expect("fn").module) {
                    format!("({assigned}) === \\Zero\\Js::undefined()")
                } else {
                    format!("\\Zero\\Js::isNullish({assigned})")
                };
                let _ = writeln!(out, "{p}{tmp} = ({missing} ? {} : {tmp});", d.php);
                self.bind_pattern_types(inner, &tmp, types, mutable, out, indent)?;
            }
            Pat::Obj { props, rest, span } => {
                let source = self.tmp_var();
                let _ = writeln!(out, "{p}{source} = {src};");
                let src = &source;
                let t = ty.non_null();
                if ty.is_nullable() {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
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
                    let access = if optional || matches!(t, Ty::Map(_)) {
                        format!("\\Zero\\Js::dynamicGet({src}, {})", php_str(key))
                    } else {
                        format!("{src}->p[{}]", php_str(key))
                    };
                    let read = if optional || matches!(t, Ty::Map(_)) {
                        Ty::union([ft, Ty::Undefined])
                    } else {
                        ft
                    };
                    let original = constraint.non_null();
                    let (ct, optional) = original.field(key).expect("authored field");
                    let constraint = if optional || matches!(original, Ty::Map(_)) {
                        ct.nullable()
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
                let _ = writeln!(out, "{p}{source} = {src};");
                let src = &source;
                if ty.is_nullable() {
                    return err(*span, format!("can't destructure {}: it might be null", ty.describe()));
                }
                let element = |ty: &Ty, i: usize| match ty.non_null() {
                    Ty::Array(t) | Ty::NonEmptyArray(t) => Some((*t).nullable()),
                    Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => Some(ts.get(i).cloned().unwrap_or(Ty::Null)),
                    _ => None,
                };
                if element(&ty, 0).is_none() {
                    return err(*span, format!("can't destructure {} as an array", ty.describe()));
                }
                let done = self.tmp_var();
                let _ = writeln!(out, "{p}{done} = false;");
                for (i, e) in elems.iter().enumerate() {
                    let _ = writeln!(out, "{p}{done} = {done} || {i} >= count({src}->a);");
                    if let Some(e) = e {
                        let access = format!("({done} ? null : \\Zero\\Js::get({src}, {i}))");
                        let types = BindingTypes {
                            read: element(&ty, i).expect("array read"),
                            constraint: element(&constraint, i).expect("authored array"),
                        };
                        self.bind_pattern_types(e, &access, types, mutable, out, indent)?;
                    }
                }
                if let Some((name, span)) = rest {
                    let tail = |ty: &Ty| match ty.non_null() {
                        Ty::Array(t) | Ty::NonEmptyArray(t) => Ty::Array(t),
                        Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => {
                            Ty::Array(Box::new(Ty::union(ts.into_iter().skip(elems.len()))))
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
            ExprKind::IsNullish(inner, negated) => {
                if let Some((n, t)) = self.path_ty(inner) {
                    // `x == null` true → null branch; false → non-null.
                    let is_null_branch = *negated == negate;
                    let (yes, no) = if is_null_branch {
                        (Ty::Null, t.non_null())
                    } else {
                        (t.non_null(), Ty::Null)
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
                let left = self.expr(left)?;
                let right = self.expr(right)?;
                if !matches!(right.ty, Ty::Class(_)) {
                    return err(span, "instanceof requires a named Zero class");
                }
                Out::atom(
                    format!("\\Zero\\JsClass::isInstance({}, {})", left.php, right.php),
                    Ty::Bool,
                )
            }
            ExprKind::Num(n) => Out::atom(php_number(*n), Ty::FreshNumLit(*n)),
            ExprKind::Str(s) => Out::atom(php_str(s), Ty::FreshStrLit(s.clone())),
            ExprKind::Bool(b) => Out::atom(b.to_string(), Ty::FreshBoolLit(*b)),
            ExprKind::Undefined => Out::atom("\\Zero\\Js::undefined()", Ty::Undefined),
            ExprKind::Null => Out::atom("null", Ty::Null),
            ExprKind::Regex { pattern, flags } => {
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
                    Out::new(parts.join(" . "), Ty::Str)
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
            ExprKind::Object(props) => {
                let mut parts = Vec::new();
                let mut fields: IndexMap<String, (Ty, bool)> = IndexMap::new();
                let mut is_map = false;
                for p in props {
                    match p {
                        Prop::KV(key, value) => {
                            let v = self.expr(value)?;
                            let v = self.finish_value(v, value.span)?;
                            match key {
                                PropKey::Static(k) => {
                                    fields.shift_remove(k);
                                    fields.insert(k.clone(), (v.ty.clone(), false));
                                    parts.push(format!("[{} => {}]", php_str(k), v.php));
                                }
                                PropKey::Computed(kx) => {
                                    let k = self.expr(kx)?;
                                    if !k.ty.all(|t| t.is_str() || t.is_num() || t.is_bool() || *t == Ty::Null) {
                                        return err(
                                            kx.span,
                                            format!("computed key type is not supported: {}", k.ty.describe()),
                                        );
                                    }
                                    is_map = true;
                                    parts.push(format!("[\\Zero\\Js::key({}) => {}]", k.php, v.php));
                                }
                            }
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
                                Ty::Map(_) => is_map = true,
                                other => {
                                    return err(x.span, format!("can't spread {} into an object", other.describe()));
                                }
                            }
                            parts.push(format!("({})->p ?? []", v.wrapped()));
                        }
                    }
                }
                let ty = if is_map {
                    Ty::Map(Box::new(Ty::union(fields.values().map(|(t, _)| t.clone()))))
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
                    UnOp::Neg => {
                        if v.ty != Ty::Dynamic {
                            self.need_num(&v, x.span, "`-`")?;
                        }
                        match v.ty {
                            Ty::NumLit(n) | Ty::FreshNumLit(n) => {
                                Out::new(format!("-{}", v.wrapped()), Ty::FreshNumLit(-n))
                            }
                            // Js::neg: the operand as a JS number, like the binary operators.
                            _ => Out::atom(format!("\\Zero\\Js::neg({})", v.php), Ty::Num),
                        }
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
            ExprKind::New { callee, args } => self.new_expr(callee, args, span)?,
            ExprKind::Member {
                obj,
                prop,
                optional,
                prop_span,
            } => self.member(obj, prop, *optional, *prop_span, span)?,
            ExprKind::Index { obj, index, optional } => self.index(obj, index, *optional, span)?,
            ExprKind::Chain(inner) => self.expr(inner)?,
            ExprKind::Await(x) => self.await_expr(x, span)?,
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
                if !(assignable(&v.ty, &t) || assignable(&t, &v.ty) || v.ty == Ty::Unknown) {
                    return err(span, format!("can't treat {} as {}", v.ty.describe(), t.describe()));
                }
                Out { ty: t, ..v }
            }
            ExprKind::NonNull(x) => {
                let v = self.expr(x)?;
                let t = v.ty.non_null();
                Out { ty: t, ..v }
            }
        };
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
        Ok(match &v.ty {
            t if t.is_str() => v.wrapped(),
            Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_) | Ty::Date => format!("\\Zero\\Js::numStr({})", v.php),
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
        if a.ty == Ty::Dynamic || b.ty == Ty::Dynamic || matches!(op, BinOp::LooseEq | BinOp::LooseNe) {
            return self.javascript_binary(op, a, b, span);
        }
        Ok(match op {
            BinOp::Add => {
                if a.ty.is_num() && b.ty.is_num() {
                    // Js::add: operands and result as JS numbers (exact ints past ±2^53 round, as in JS).
                    Out::atom(format!("\\Zero\\Js::add({}, {})", a.php, b.php), Ty::Num)
                } else if a.ty.is_str() || b.ty.is_str() {
                    let (x, y) = (self.to_string_php(&a, span)?, self.to_string_php(&b, span)?);
                    Out::new(format!("{x} . {y}"), Ty::Str)
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
                    Out::new(format!("{} {sym} {}", a.wrapped(), b.wrapped()), Ty::Bool)
                } else if a.ty.is_str() && b.ty.is_str() {
                    // JS compares strings by UTF-16 code units; for UTF-8 that matches byte order
                    // except across surrogate pairs, which strcmp handles consistently enough.
                    Out::new(format!("strcmp({}, {}) {sym} 0", a.php, b.php), Ty::Bool)
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
        })
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
            Resolved::Global(g) => err(
                span,
                format!("`{g}` can't be used as a value; call one of its functions"),
            ),
        }
    }

    /// A name captured from the config's build-time scope.
    fn captured(&mut self, n: &str, v: Value, b: &Binding, span: Span) -> Result<Out> {
        if b.mutable && b.module_level && !self.javascript_module(self.fx.as_ref().expect("fn").module) {
            return err_help(
                span,
                format!("hooks can't read `{n}`: it's a module-level `let`"),
                "make it a `const`; each request starts fresh in PHP",
            );
        }
        match &v {
            Value::Class(class) => self.class_value(class),
            Value::Closure(c) => {
                let key = closure_key(c);
                let helper_key = (key, self.fx().no_db);
                let name = match self.helpers.get(&helper_key) {
                    Some(n) => n.clone(),
                    None => {
                        let name = self.unique_name(c.func.name.as_deref().unwrap_or(n));
                        self.helpers.insert(helper_key, name.clone());
                        self.compile_helper_now(c, &name)?;
                        name
                    }
                };
                let Some(ty) = self.helper_types.get(&key).cloned() else {
                    return err_help(
                        span,
                        format!("`{n}` calls itself, so it needs a return type"),
                        format!("annotate it: `function {n}(...): number`"),
                    );
                };
                let wrapper = match self.helper_values.get(&key) {
                    Some(wrapper) => wrapper.clone(),
                    None => {
                        let wrapper = self.unique_name(&format!("{name}_value"));
                        self.helper_values.insert(key, wrapper.clone());
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
            Value::Env(name) => Ok(Out::atom(
                format!("\\Zero\\Env::get({})", php_str(name)),
                Ty::Str.nullable(),
            )),
            Value::Builtin(Builtin::Env | Builtin::Once) => err_help(
                span,
                format!("`{n}` can't be used as a value"),
                format!("call it directly: `{n}(...)`"),
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
            | Value::FileBucket(_)
            | Value::Bound(..) => err(
                span,
                format!("`{n}` is {}, which only exists while the config is built", v.describe()),
            ),
            Value::Regex(p, f) if self.javascript_module(self.fx.as_ref().expect("fn").module) => {
                let key = (2, Rc::as_ptr(p) as *const () as usize);
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
                Ok(Out::atom(format!("\\ZeroApp\\{name}()"), Ty::Dynamic))
            }
            Value::Regex(p, f) => Ok(Out::atom(
                format!("\\Zero\\Js::re({}, {})", php_str(p), php_str(f)),
                Ty::Regex,
            )),
            data => {
                validate_captured_data(data, span, &mut HashSet::new())?;
                let javascript = self.javascript_module(self.fx.as_ref().expect("fn").module);
                let ty = match &b.ann {
                    _ if javascript => Ty::Dynamic,
                    Some((ann, module)) => self.resolve_runtime_ann(ann, *module)?,
                    None => inferred_binding_type(&ty_of_data(data), false),
                };
                let ty = self.effects.captured_data_type(data, &ty);
                Ok(Out::atom(self.captured_data(data, javascript)?, ty))
            }
        }
    }

    fn captured_data(&mut self, value: &Value, javascript: bool) -> Result<String> {
        let (key, array) = match value {
            Value::Array(a) => ((if javascript { 3 } else { 0 }, Rc::as_ptr(a) as usize), true),
            Value::Object(o) => ((if javascript { 4 } else { 1 }, Rc::as_ptr(o) as usize), false),
            _ => {
                return Ok(match value {
                    Value::Undef if javascript => "\\Zero\\Js::undefined()".into(),
                    Value::Null | Value::Undef => "null".into(),
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
            let value = self.captured_data(&value, javascript)?;
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
        let present = read.non_null();
        let initialized = if assignable(&value.ty, &present) {
            present
        } else {
            Ty::union([present, value.ty])
        };
        let initialization = if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            format!(
                "{}if ({php} === \\Zero\\JsUndefined::Value) {{ {php} = {}; }}\n",
                pad(indent),
                value.php
            )
        } else {
            format!(
                "{}if (\\Zero\\Js::isNullish({php})) {{ {php} = {}; }}\n",
                pad(indent),
                value.php
            )
        };
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
        let mut params = Vec::new();
        let mut body = String::new();
        for (i, p) in f.params.iter().enumerate() {
            if p.rest {
                let Pat::Id(name, _) = &p.pat else {
                    return err(p.span, "rest parameters need a plain name");
                };
                let php = self.declare_local(name, param_tys[i].clone(), true, p.pat.span());
                params.push(format!("...$__rest{i}"));
                let _ = writeln!(body, "        {php} = new \\Zero\\JsArray($__rest{i});");
                continue;
            }
            match &p.pat {
                Pat::Id(_, _) => {
                    let php = self.declare_parameter(p, &param_tys[i], &mut body, 2)?;
                    let default = if self.javascript_module(f.module) {
                        " = \\Zero\\JsUndefined::Value"
                    } else {
                        parameter_default_suffix(p)
                    };
                    params.push(format!("{php}{default}"));
                }
                pat => {
                    let tmp = format!("$__arg{i}");
                    params.push(format!("{tmp}{}", parameter_default_suffix(p)));
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
        body = self.javascript_prologue(f, 2)? + &body;
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
                if self.javascript_module(f.module) {
                    body.push_str("        return \\Zero\\Js::undefined();\n");
                }
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
        let v = self.expr(e)?;
        let Ty::Fn(declared, _) = &v.ty else {
            return err(e.span, format!("expected a function, got {}", v.ty.describe()));
        };
        check_callback_parameters(params, declared, e.span)?;
        Ok(v)
    }

    fn closure_with_types(&mut self, f: &Rc<Func>, params: Option<&[Ty]>) -> Result<Out> {
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
            ret: None,
            returns: Vec::new(),
            diverging: HashSet::new(),
            exhaustive_switches: HashSet::new(),
            loop_bindings: HashSet::new(),
            env: self.fx().env.clone(),
            module,
            declared: Vec::new(),
            captures: Some(Vec::new()),
            outer: None,
            tmp: 0,
            switch_depth: 0,
            no_db: self.fx().no_db,
            access: None,
        };
        if f.is_async && !std::mem::take(&mut self.async_callback) {
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
        let place = self.place(target)?;
        if op.is_none()
            && !statement
            && let Some(target) = place.target
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
                    if target.dynamic {
                        "dynamicSet"
                    } else if target.array {
                        "setAt"
                    } else {
                        "set"
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
                    CapturedPlace::Binding(_) | CapturedPlace::Dynamic { .. } => None,
                    CapturedPlace::Array { receiver, key } | CapturedPlace::Object { receiver, key } => {
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
                    if let Some(present) = &present {
                        left.ty = place.constraint.non_null();
                        if place.constraint.non_null().is_num() {
                            left = Out::atom(format!("({present} ? \\Zero\\Js::toNumber({old}) : NAN)"), Ty::Num);
                        }
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
                let read = if target.dynamic {
                    format!("\\Zero\\Js::dynamicGet({receiver}, {key})")
                } else if target.array {
                    format!("\\Zero\\Js::get({receiver}, {key})")
                } else {
                    format!("\\Zero\\Js::prop({receiver}, {key})")
                };
                let write = if target.dynamic {
                    CapturedPlace::Dynamic { receiver, key }
                } else if target.array {
                    CapturedPlace::Array { receiver, key }
                } else {
                    CapturedPlace::Object { receiver, key }
                };
                (steps, read, write)
            }
        }
    }

    fn write_place(write: &CapturedPlace, value: &str) -> String {
        match write {
            CapturedPlace::Binding(binding) => format!("({binding} = {value})"),
            CapturedPlace::Object { receiver, key } => format!("\\Zero\\Js::set({receiver}, {key}, {value})"),
            CapturedPlace::Array { receiver, key } => format!("\\Zero\\Js::setAt({receiver}, {key}, {value})"),
            CapturedPlace::Dynamic { receiver, key } => format!("\\Zero\\Js::dynamicSet({receiver}, {key}, {value})"),
        }
    }

    fn update(&mut self, target: &Expr, inc: bool, prefix: bool) -> Result<Out> {
        let place = self.place(target)?;
        let property_number = place.target.is_some() && place.constraint.non_null().is_num();
        if !property_number && place.read.ty != Ty::Dynamic {
            self.need_num(&place.read, target.span, if inc { "`++`" } else { "`--`" })?;
        }
        let (mut steps, read, write) = self.capture_place(place.target, &place.read);
        let old = self.tmp_var();
        steps.push(format!("{old} = {read}"));
        if property_number {
            let (CapturedPlace::Array { receiver, key } | CapturedPlace::Object { receiver, key }) = &write else {
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
                    .map(|(t, optional)| if optional { t.nullable() } else { t })
                    .unwrap_or(ft.clone());
                let read_ty = self
                    .path_of(target)
                    .and_then(|path| self.narrowed_path(&path))
                    .unwrap_or_else(|| if optional { ft.nullable() } else { ft });
                let read = Out::atom(format!("({}->p[{}] ?? null)", o.wrapped(), php_str(prop)), read_ty);
                Ok(AssignmentPlace {
                    constraint,
                    read,
                    target: Some(WriteTarget {
                        receiver: o.php,
                        key: php_str(prop),
                        array: false,
                        dynamic: false,
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
                let read = Out::atom(
                    format!(
                        "\\Zero\\Js::{}({}, {})",
                        if array { "get" } else { "prop" },
                        o.php,
                        i.php
                    ),
                    ty.nullable(),
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
                        array,
                        dynamic: false,
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
                _ => err(span, format!("`{g}.{prop}` isn't supported")),
            };
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
        // `x?.length` on a nullable value: evaluate once, short-circuit to null.
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
            (Ty::Error, "message") => Out::atom(format!("{}->getMessage()", o.wrapped()), Ty::Str),
            (Ty::Error, "name") => Out::atom(format!("{}->jsName", o.wrapped()), Ty::Str),
            (Ty::Regex, "source" | "flags") => Out::atom(format!("{}->{prop}", o.wrapped()), Ty::Str),
            (Ty::Obj(_) | Ty::Map(_) | Ty::Union(_), _) => {
                let Some((ft, opt_field)) = base.field(prop) else {
                    if matches!(base, Ty::Union(_)) {
                        return err(prop_span, format!("{} has no property `{prop}`", base.describe()));
                    }
                    return Err(self.no_field(&o.ty, prop, prop_span));
                };
                let map = matches!(base, Ty::Map(_));
                let method = if map || opt_field { "dynamicGet" } else { "prop" };
                let php = guarded(self, &|x| format!("\\Zero\\Js::{method}({x}, {})", php_str(prop)));
                let ft = if map || opt_field {
                    Ty::union([ft, Ty::Undefined])
                } else {
                    ft
                };
                let place = o.place.as_ref().map(|p| format!("{p}->p[{}]", php_str(prop)));
                Out {
                    php,
                    ty: suffix_null(ft),
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
                format!("\\Zero\\Js::get({}, {})", o.php, i.php),
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
                format!("\\Zero\\Js::get({}, {})", o.php, i.php),
                wrap((**t).clone().nullable()),
            ),
            (Ty::Tuple(ts) | Ty::ArrayLiteral(ts), _) => match &index.kind {
                ExprKind::Num(n) if n.fract() == 0.0 && *n >= 0.0 => {
                    let t = ts.get(*n as usize).cloned().unwrap_or(Ty::Null);
                    let place = o.place.as_ref().map(|p| format!("{p}->a[{}]", *n as usize));
                    Out {
                        php: format!("\\Zero\\Js::get({}, {})", o.php, *n as usize),
                        ty: wrap(t.nullable()),
                        place,
                        atomic: true,
                        helper: None,
                    }
                }
                _ if i.ty.is_num() => Out::atom(
                    format!("\\Zero\\Js::get({}, {})", o.php, i.php),
                    wrap(Ty::union(ts.clone()).nullable()),
                ),
                _ => return err(index.span, "tuples are indexed by number"),
            },
            (Ty::Map(t), key_ty) if key_ty.is_str() => Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
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
                    format!("\\Zero\\Js::dynamicGet({}, {})", o.php, i.php),
                    wrap(Ty::union(values)),
                )
            }
            (s, it) if s.is_str() && it.is_num() => Out::atom(
                format!("\\Zero\\Js::strIndex({}, {})", o.php, i.php),
                wrap(Ty::Str.nullable()),
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
        Ok(match v.ty {
            Ty::Promise(t) => Out { ty: *t, ..v },
            Ty::Builder(b) => Out::new(b.php(), b.result(self.schema)),
            other => {
                return err_help(
                    span,
                    format!("awaiting {} does nothing", other.describe()),
                    "only await Zero database calls and your own async functions",
                );
            }
        })
    }

    fn new_expr(&mut self, callee: &Expr, args: &[ArrEl], span: Span) -> Result<Out> {
        if let ExprKind::Ident(name) = &callee.kind
            && name == "RegExp"
            && matches!(self.resolve(name, callee.span)?, Resolved::Global("RegExp"))
            && !self.javascript_module(self.fx.as_ref().expect("fn").module)
        {
            let argv = self.plain_args(args)?;
            let pattern = argv.first().map(|v| v.php.clone()).unwrap_or_else(|| "''".into());
            let flags = argv.get(1).map(|v| v.php.clone()).unwrap_or_else(|| "''".into());
            return Ok(Out::atom(format!("\\Zero\\Js::re({pattern}, {flags})"), Ty::Regex));
        }
        if self.javascript_module(self.fx.as_ref().expect("fn").module) {
            if let ExprKind::Ident(name) = &callee.kind
                && matches!(name.as_str(), "Set" | "Map" | "Date" | "RegExp")
                && matches!(self.resolve(name, callee.span)?, Resolved::Global(_))
            {
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
                let argv = self.plain_args(args)?;
                Ok(match argv.as_slice() {
                    [] => Out::atom("\\Zero\\Js::now()", Ty::Date),
                    [a] => Out::atom(format!("\\Zero\\Js::date({})", a.php), Ty::Date),
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
            _ => err(span, format!("`new {n}` isn't supported")),
        }
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
            ExprKind::Member { obj, prop, .. } => self
                .argument_constraint(obj)?
                .non_null()
                .field(prop)
                .map(|(ty, optional)| if optional { ty.nullable() } else { ty }),
            ExprKind::Index { obj, index, .. } => match self.argument_constraint(obj)?.non_null() {
                Ty::Array(ty) | Ty::NonEmptyArray(ty) | Ty::Map(ty) => Some((*ty).nullable()),
                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => match &index.kind {
                    ExprKind::Num(n) if *n >= 0.0 && n.fract() == 0.0 => {
                        Some(ts.get(*n as usize).cloned().unwrap_or(Ty::Null).nullable())
                    }
                    _ => Some(Ty::union(ts).nullable()),
                },
                Ty::Obj(fields) => match &index.kind {
                    ExprKind::Str(key) => fields.get(key).map(|(t, _)| t.clone()),
                    _ => None,
                },
                _ => None,
            },
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
            let value = self.arg(args, 0, span, "a receiver")?;
            let value = self.expr(value)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicObjectTag({})", value.php),
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
                // Static functions on globals: Math.max, Object.keys, ...
                if let ExprKind::Ident(n) = &obj.kind
                    && let Ok(Resolved::Global(g)) = self.resolve(n, obj.span)
                {
                    return self.static_call(g, prop, args, span);
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
                let f = self.expr(callee)?;
                self.call_value(f, args, span, callee.span)
            }
            ExprKind::Ident(n) => {
                if let Resolved::Global(g) = self.resolve(n, callee.span)? {
                    return self.global_call(g, args, span);
                }
                let f = self.ident(n, callee.span)?;
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
                Resolved::Value(Value::Builtin(b @ (Builtin::Once | Builtin::Jobs | Builtin::Env)), _) => Some(b),
                _ => None,
            })
        };
        match &callee.kind {
            ExprKind::Ident(_) => match builtin(self, callee)? {
                Some(Builtin::Once) => self.once_call(args, span).map(Some),
                Some(Builtin::Env) => {
                    let name = self.arg(args, 0, span, "an environment variable name")?;
                    match &name.kind {
                        ExprKind::Str(s) if is_env_name(s) && args.len() == 1 => Ok(Some(Out::atom(
                            format!("\\Zero\\Env::get({})", php_str(s)),
                            Ty::Str.nullable(),
                        ))),
                        _ => err_help(
                            name.span,
                            "env() needs an environment variable name",
                            "use env(\"HOOK_SECRET\")",
                        ),
                    }
                }
                _ => Ok(None),
            },
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
            format!("\\Zero\\Jobs\\Receipts::once('fn', {}, {})", key.php, cb.php),
            Ty::Promise(Box::new(classes::wire_type(&ret))),
        ))
    }

    fn enqueue_call(&mut self, job: &str, job_span: Span, args: &[ArrEl], span: Span) -> Result<Out> {
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

    fn global_call(&mut self, g: &str, args: &[ArrEl], span: Span) -> Result<Out> {
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
        let argv = self.plain_args(args)?;
        if matches!(g, "encodeURI" | "encodeURIComponent") && argv.len() != 1 {
            return err(span, format!("`{g}` needs exactly one string argument"));
        }
        if g == "isFinite" && argv.len() > 1 {
            return err(span, "isFinite() accepts at most one argument in Zero code");
        }
        let a0 = argv.first();
        Ok(match (g, a0) {
            ("String", Some(v)) => Out::new(self.to_string_php(v, span)?, Ty::Str),
            ("structuredClone", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::clone({})", v.php), classes::wire_type(&v.ty))
            }
            ("Number", Some(v)) => {
                if v.ty.is_num() {
                    v.clone()
                } else {
                    Out::atom(format!("\\Zero\\Js::toNumber({})", v.php), Ty::Num)
                }
            }
            ("Boolean", Some(v)) => Out::new(self.truthy(v), Ty::Bool),
            ("encodeURI" | "encodeURIComponent", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::{g}({})", v.php), Ty::Str).checked_str(v, span)?
            }
            ("parseInt", Some(v)) => Out::atom(
                format!(
                    "\\Zero\\Js::parseInt({})",
                    argv.iter().map(|a| a.php.clone()).collect::<Vec<_>>().join(", ")
                ),
                Ty::Num,
            )
            .checked_str(v, span)?,
            ("parseFloat", Some(v)) => {
                Out::atom(format!("\\Zero\\Js::parseFloat({})", v.php), Ty::Num).checked_str(v, span)?
            }
            ("isNaN", Some(v)) => Out::atom(format!("is_nan((float) \\Zero\\Js::toNumber({}))", v.php), Ty::Bool),
            ("isFinite", Some(v)) => Out::atom(
                format!("\\Zero\\Js::isFinite(\\Zero\\Js::toNumber({}))", v.php),
                Ty::Bool,
            ),
            ("isFinite", None) => Out::atom("false", Ty::Bool),
            (
                "fetch" | "process" | "require" | "eval" | "Function" | "setTimeout" | "crypto" | "globalThis"
                | "Promise",
                _,
            ) => {
                return err(span, format!("`{g}` isn't available in Zero code"));
            }
            _ => return err(span, format!("`{g}(...)` isn't supported")),
        })
    }

    fn static_call(&mut self, g: &str, m: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if (self.javascript_module(self.fx.as_ref().expect("fn").module)
            || args.iter().any(|a| matches!(a, ArrEl::Spread(_))))
            && matches!(g, "Math" | "Array" | "Object" | "Number" | "JSON")
        {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicStaticCall({}, {}, {argv})", php_str(g), php_str(m)),
                Ty::Dynamic,
            ));
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
                let cb = self.callback(f, &[Ty::Null, Ty::Num])?;
                let Ty::Fn(_, ret) = &cb.ty else { unreachable!() };
                let ret = (**ret).clone();
                return Ok(Out::atom(
                    format!("\\Zero\\Js::range((int) {}, {})", n.wrapped(), cb.php),
                    Ty::Array(Box::new(ret)),
                ));
            }
            let v = self.expr(src)?;
            let nonempty = array_proven_nonempty(&v.ty);
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
                    "trunc" => format!("(({x}) < 0 ? ceil({x}) : floor({x}))"),
                    "round" => format!("floor(({x}) + 0.5)"),
                    "sign" => format!("(({x}) <=> 0)"),
                    "pow" => format!(
                        "\\Zero\\Js::pow({}, {})",
                        argv[0].php,
                        argv.get(1).map(|a| a.php.as_str()).unwrap_or("NAN")
                    ),
                    "log" => format!("log({x})"),
                    _ => format!("exp({x})"),
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
            ("Object", "hasOwn") => Out::atom(format!("\\Zero\\Js::hasOwn({})", php_args()), Ty::Bool),
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
                let t = Ty::Str;
                if argv
                    .get(1)
                    .is_some_and(|value| !matches!(value.ty, Ty::Null | Ty::Undefined))
                {
                    return err(span, "JSON.stringify replacers aren't supported");
                }
                if argv.len() > 1 {
                    let indent = argv.get(2).map(|a| a.php.clone()).unwrap_or_else(|| "null".into());
                    Out::atom(format!("\\Zero\\Js::jsonStringify({}, {indent})", argv[0].php), t)
                } else {
                    Out::atom(format!("\\Zero\\Js::jsonStringify({})", php_args()), t)
                }
            }
            ("JSON", "parse") => {
                if argv
                    .get(1)
                    .is_some_and(|value| !matches!(value.ty, Ty::Null | Ty::Undefined))
                {
                    return err(span, "JSON.parse revivers aren't supported");
                }
                let input = a0
                    .map(|value| value.php.as_str())
                    .unwrap_or("\\Zero\\JsUndefined::Value");
                Out::atom(format!("\\Zero\\Js::jsonParse({input})"), Ty::Unknown)
            }
            ("console", "log" | "info" | "warn" | "error" | "debug") => {
                Out::atom(format!("\\Zero\\Js::log({}, {})", php_str(m), php_args()), Ty::Null)
            }
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
        let mut recv = self.finish_value(recv, recv_span)?;
        let javascript_string_replace = self.javascript_module(self.fx.as_ref().expect("fn").module)
            && recv.ty.non_null().is_str()
            && matches!(m, "replace" | "replaceAll");
        if recv.ty == Ty::Dynamic || javascript_string_replace {
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
                if javascript_string_replace {
                    if optional && recv.ty.is_nullable() {
                        Ty::Str.nullable()
                    } else {
                        Ty::Str
                    }
                } else {
                    Ty::Dynamic
                },
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
                    let d = argv
                        .first()
                        .map(|a| format!("(int) {}", a.wrapped()))
                        .unwrap_or_else(|| "0".into());
                    Out::atom(format!("\\Zero\\Js::toFixed({}, {d})", recv.php), Ty::Str)
                }
                "toString" => Out::atom(format!("\\Zero\\Js::numStr({})", recv.php), Ty::Str),
                _ => return err(m_span, format!("numbers have no method `{m}`")),
            },
            Ty::Date => match m {
                "toISOString" | "toJSON" => Out::atom(format!("\\Zero\\Js::iso({})", recv.php), Ty::Str),
                "getTime" | "valueOf" => Out {
                    ty: Ty::Num,
                    ..recv.clone()
                },
                _ => {
                    return err_help(
                        m_span,
                        format!("`Date.{m}` isn't supported"),
                        "use getTime() or toISOString()",
                    );
                }
            },
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
            Ty::Map(_) | Ty::Obj(_) => {
                // Calling a function stored in an object field.
                let f = self.member_of_out(&recv, m, m_span)?;
                return self.call_value(f, args, span, m_span);
            }
            other => return err(m_span, format!("{} has no method `{m}`", other.describe())),
        };
        if optional && recv.ty.is_nullable() {
            let tmp = self.tmp_var();
            let php = r.php.replacen(&recv.php, &tmp, 1);
            return Ok(Out::new(
                format!(
                    "(\\Zero\\Js::isNullish({tmp} = {}) ? \\Zero\\Js::undefined() : {php})",
                    recv.php
                ),
                r.ty.nullable(),
            ));
        }
        Ok(r)
    }

    fn member_of_out(&self, o: &Out, prop: &str, span: Span) -> Result<Out> {
        let Some((t, optional)) = o.ty.non_null().field(prop) else {
            return Err(self.no_field(&o.ty, prop, span));
        };
        let t = if optional || matches!(o.ty.non_null(), Ty::Map(_)) {
            t.nullable()
        } else {
            t
        };
        Ok(Out::atom(format!("\\Zero\\Js::prop({}, {})", o.php, php_str(prop)), t))
    }

    fn string_method(&mut self, s: &Out, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
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
        Ok(match m {
            "concat" => Out::atom(
                format!(
                    "\\Zero\\Js::dynamicCallMethod({}, 'concat', new \\Zero\\JsArray([{}]))",
                    s.php,
                    argv.iter().map(|v| v.php.clone()).collect::<Vec<_>>().join(", ")
                ),
                Ty::Str,
            ),
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
            "includes" => Out::atom(format!("str_contains({}, {})", s.php, need_str(0, self)?), Ty::Bool),
            "startsWith" => Out::atom(format!("str_starts_with({}, {})", s.php, need_str(0, self)?), Ty::Bool),
            "endsWith" => Out::atom(format!("str_ends_with({}, {})", s.php, need_str(0, self)?), Ty::Bool),
            "indexOf" | "lastIndexOf" => {
                need_str(0, self)?;
                Out {
                    ty: Ty::Num,
                    ..js(m, &[a(0), a(1)])
                }
            }
            "slice" | "substring" | "padStart" | "padEnd" | "repeat" | "charAt" => js(m, &[a(0), a(1)]),
            "at" => Out {
                ty: Ty::Str.nullable(),
                ..js("strAt", &[a(0)])
            },
            "split" => Out {
                ty: Ty::Array(Box::new(Ty::Str)),
                ..js("split", &[a(0), a(1)])
            },
            "localeCompare" => Out {
                ty: Ty::Num,
                ..js("localeCompare", &[Some(need_str(0, self)?)])
            },
            "match" => {
                if argv.first().map(|x| &x.ty) != Some(&Ty::Regex) {
                    return err(span, "match() needs a regular expression");
                }
                Out {
                    ty: Ty::Array(Box::new(Ty::Str.nullable())).nullable(),
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
        // In-place mutators need an assignable variable.
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
                Out::atom(format!("\\Zero\\Js::{m}({}, {})", a.php, f.php), narrowed.nullable())
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
                Out::atom(format!("\\Zero\\Js::forEach({}, {})", a.php, f.php), Ty::Null)
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
            "pop" => Out::atom(format!("\\Zero\\Js::pop({})", a.php), el.nullable()),
            "shift" => Out::atom(format!("\\Zero\\Js::shift({})", a.php), el.nullable()),
            "reverse" | "toReversed" => Out::atom(format!("\\Zero\\Js::{m}({})", a.php), Ty::Array(Box::new(el))),
            "splice" => {
                let argv = self.plain_args(args)?;
                check_insert(&argv[argv.len().min(2)..])?;
                let extra: Vec<String> = argv.iter().map(|x| x.php.clone()).collect();
                Out::atom(
                    format!("\\Zero\\Js::splice({}, {})", a.php, extra.join(", ")),
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
                match m {
                    "includes" => Out::atom(format!("\\Zero\\Js::includes({all})"), Ty::Bool),
                    "indexOf" | "lastIndexOf" => Out::atom(format!("\\Zero\\Js::{m}({all})"), Ty::Num),
                    "join" => {
                        if let Some(sep) = argv.first()
                            && !sep.ty.is_str()
                        {
                            return err(span, "join() needs a string separator");
                        }
                        Out::atom(format!("\\Zero\\Js::join({all})"), Ty::Str)
                    }
                    "slice" => Out::atom(format!("\\Zero\\Js::arrSlice({all})"), Ty::Array(Box::new(el))),
                    "at" => Out::atom(format!("\\Zero\\Js::arrAt({all})"), el.nullable()),
                    "concat" => {
                        let mut t = vec![el];
                        let mut parts = vec![a.php.clone()];
                        for x in &argv {
                            match x.ty.non_null() {
                                Ty::Array(e) | Ty::NonEmptyArray(e) => t.push(*e),
                                other => {
                                    t.push(other);
                                    parts.push(x.php.clone());
                                    continue;
                                }
                            }
                            parts.push(x.php.clone());
                        }
                        Out::atom(
                            format!("\\Zero\\Js::concat({})", parts.join(", ")),
                            Ty::Array(Box::new(Ty::union(t))),
                        )
                    }
                    "flat" => {
                        let inner = match el {
                            Ty::Array(t) | Ty::NonEmptyArray(t) => *t,
                            t => t,
                        };
                        Out::atom(format!("\\Zero\\Js::flat({all})"), Ty::Array(Box::new(inner)))
                    }
                    _ => {
                        check_insert(&argv[..argv.len().min(1)])?;
                        let p = &a.php;
                        Out::atom(
                            format!("\\Zero\\Js::fill({p}, {})", extra.join(", ")),
                            Ty::Array(Box::new(el)),
                        )
                    }
                }
            }
            _ => return err(m_span, format!("arrays have no method `{m}` in Zero code")),
        })
    }

    // ---- database ---------------------------------------------------------------

    fn query_call(&mut self, table: &str, m: &str, args: &[ArrEl], span: Span, m_span: Span) -> Result<Out> {
        if !matches!(m, "findMany" | "findFirst") {
            return err_help(
                m_span,
                format!("`db.query.<collection>` has no method `{m}`"),
                "use findMany or findFirst",
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
            (ColType::Timestamp, Ty::Date | Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_)) => {
                format!("\\Zero\\Js::iso({})", v.php)
            }
            (ColType::Date, Ty::Date | Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_)) => {
                format!("substr(\\Zero\\Js::iso({}), 0, 10)", v.php)
            }
            _ => v.php.clone(),
        }
    }

    /// `db.globals.<key>.get()` / `.update(patch)`: trusted, like `db.query`.
    fn db_global_call(&mut self, name: &str, m: &str, args: &[ArrEl], m_span: Span) -> Result<Out> {
        // Typed like collection rows: `json().$type<T>()` fields read and write as T.
        let row = types::columns_row(&self.schema.globals[name].fields, &types::json_ty);
        let php = match (m, args) {
            ("get", []) => format!("\\Zero\\Seam::globalGet({})", php_str(name)),
            ("update", [ArrEl::Item(patch)]) => {
                let v = self.expr(patch)?;
                let v = self.finish_value(v, patch.span)?;
                let want = types::partial(row.clone());
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
        let t = self.arg(args, 0, span, "a collection")?;
        let v = self.expr(t)?;
        let Ty::Collection(table) = v.ty else {
            return err(t.span, format!("db.{m}() needs a collection, not {}", v.ty.describe()));
        };
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
        let want = if insert {
            types::insert(self.schema, table)
        } else {
            types::partial(types::insert(self.schema, table))
        };
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
        Value::Null | Value::Undef => Ty::Null,
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
        Ty::Str | Ty::StrLit(_) | Ty::FreshStrLit(_) | Ty::Num | Ty::Unknown | Ty::Dynamic | Ty::Never => t.clone(),
        Ty::Arguments
        | Ty::Array(_)
        | Ty::NonEmptyArray(_)
        | Ty::Instance(..)
        | Ty::Class(_)
        | Ty::Tuple(_)
        | Ty::ArrayLiteral(_)
        | Ty::Obj(_)
        | Ty::Map(_)
        | Ty::Fn(..)
        | Ty::Rest(_)
        | Ty::Promise(_)
        | Ty::Date
        | Ty::Regex
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

fn default_binding_type(ty: &Ty, default: &Ty, mutable: bool) -> Ty {
    let present = ty.non_null();
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
                        params.push(Ty::Str.nullable());
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
        check_parameter_argument(given.get(i).unwrap_or(&Ty::Null), declared, span)?;
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

fn parameter_default_suffix(parameter: &Param) -> &'static str {
    if parameter.optional || parameter.default.is_some() {
        " = null"
    } else {
        ""
    }
}

fn parameter_input_type(parameter: &Param, ty: Ty) -> Ty {
    if parameter.optional || parameter.default.is_some() {
        Ty::union([ty.nullable(), Ty::Undefined])
    } else {
        ty
    }
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
        Ty::Fn(params, ret) => Ty::Fn(
            params.iter().map(widen_fresh_literals).collect(),
            Box::new(widen_fresh_literals(ret)),
        ),
        Ty::Promise(t) => Ty::Promise(Box::new(widen_fresh_literals(t))),
        Ty::Num
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
        (Ty::Date, Ty::Num) => true,
        (Ty::Undefined, Ty::Null) => true,
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
                        && assignable(ht, &if *optional { t.clone().nullable() } else { t.clone() })
                }
                None => *optional,
            }) && have.keys().all(|k| need.contains_key(k))
        }
        (Ty::Obj(have), Ty::Map(v)) => have.values().all(|(t, _)| assignable(t, v)),
        (Ty::Map(x), Ty::Map(y)) => assignable(x, y),
        (Ty::Rest(x), Ty::Rest(y)) => assignable(x, y),
        (Ty::Promise(x), Ty::Promise(y)) => assignable(x, y),
        (Ty::Fn(pa, ra), Ty::Fn(pb, rb)) => {
            pa.len() <= pb.len() && pa.iter().zip(pb).all(|(x, y)| assignable(y, x)) && assignable(ra, rb)
        }
        _ => false,
    }
}

fn stmt_span(s: &Stmt) -> Span {
    match s {
        Stmt::Decl { span, .. } | Stmt::Return(_, span) | Stmt::Break(span) | Stmt::Continue(span) => *span,
        Stmt::Expr(e) | Stmt::Throw(e) | Stmt::If(e, ..) | Stmt::While(e, _) => e.span,
        Stmt::For { span, .. } | Stmt::ForOf { span, .. } | Stmt::Switch { span, .. } => *span,
        Stmt::Func(f) => f.span,
        _ => Span::NONE,
    }
}

fn ends_with_exit(stmts: &[Stmt]) -> bool {
    matches!(
        stmts.last(),
        Some(Stmt::Return(..) | Stmt::Throw(_) | Stmt::Continue(_) | Stmt::Break(_))
    )
}

#[derive(Clone, Copy, PartialEq, Eq, Hash)]
enum Completion {
    Normal,
    Return,
    Throw,
    Break,
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
        Stmt::Break(_) => HashSet::from([Break]),
        Stmt::Continue(_) => HashSet::from([Continue]),
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
