//! Build-time values. `zero.config.ts` evaluates to these; the compiler then
//! reads the schema out of them and compiles the functions they hold.

use crate::diag::Span;
use crate::ir::{Func, TyAnn};
use crate::num::js_number_to_string;
use crate::schema::{ColType, RefAction, RelKind};
use indexmap::IndexMap;
use std::cell::{OnceCell, RefCell};
use std::collections::HashMap;
use std::rc::Rc;

pub type Obj = Rc<RefCell<IndexMap<String, Value>>>;
pub type Arr = Rc<RefCell<Vec<Value>>>;

#[derive(Clone, Debug)]
pub enum Value {
    Class(Rc<ClassValue>),
    Undef,
    Null,
    Bool(bool),
    Num(f64),
    Str(Rc<str>),
    Array(Arr),
    /// Strict arguments have enumerable index properties and a nonenumerable,
    /// independently writable `length`; they are not arrays.
    Arguments(Obj),
    Object(Obj),
    Closure(Rc<Closure>),
    Builtin(Builtin),
    /// A method looked up on a receiver, waiting to be called.
    Bound(Rc<Value>, Rc<str>),
    Column(Rc<ColBuilder>),
    Collection(Rc<CollectionVal>),
    /// `locations.id` / `r.locations.id`
    ColRef(Rc<ColRefVal>),
    Relation(Rc<RelVal>),
    Action(Rc<ActionVal>),
    Job(Rc<ActionVal>),
    FileBucket(Rc<FileBucketVal>),
    Env(Rc<str>),
    Global(Rc<GlobalVal>),
    Regex(Rc<str>, Rc<str>),
}

#[derive(Debug)]
pub struct ClassValue {
    pub class: Rc<crate::ir::Class>,
    pub base: Option<Rc<ClassValue>>,
    pub env: Rc<Scope>,
    pub statics: IndexMap<String, Value>,
}

#[derive(Debug)]
pub struct Closure {
    pub func: Rc<Func>,
    pub env: Rc<Scope>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Builtin {
    // "zero" exports
    Collection(StorageKind),
    Column(ColType),
    Id,
    /// email, url, slug, richText, media, select, group, repeater, blocks
    Field(&'static str),
    /// `global(name, fields, options)` from "zero".
    DefineGlobal,
    DefineRelations,
    DefineConfig,
    Action,
    Job,
    Files,
    Env,
    Once,
    Jobs,
    /// Runtime-only values: usable in compiled code, an error at build time.
    Db,
    HttpError,
    /// `r.one.<key>` / `r.many.<key>` inside defineRelations.
    RelFn(RelKind, Rc<str>),
    // JavaScript globals
    Global(&'static str),
    /// A static function on a global, e.g. `Math.max`.
    Static(&'static str, Rc<str>),
}

#[derive(Debug, Clone)]
pub struct ColBuilder {
    pub ty: ColType,
    pub not_null: bool,
    pub primary_key: bool,
    pub auto_increment: bool,
    pub unique: bool,
    pub enum_values: Option<Vec<String>>,
    pub multiline: bool,
    pub accept: Option<String>,
    pub source: Option<String>,
    pub min: Option<f64>,
    pub max: Option<f64>,
    pub pattern: Option<(String, String)>,
    pub validate: Option<Rc<Closure>>,
    /// group / repeater sub-fields.
    pub fields: Option<IndexMap<String, Rc<ColBuilder>>>,
    /// blocks layouts.
    pub layouts: Option<IndexMap<String, IndexMap<String, Rc<ColBuilder>>>>,
    pub default: Option<DefaultVal>,
    pub on_update: Option<DefaultVal>,
    pub references: Option<RefBuilder>,
    pub ts_type: Option<(TyAnn, usize)>,
    pub access: Option<Value>,
    pub show_if: Option<Value>,
    pub ui: Option<Value>,
    pub localized: bool,
    pub bucket: Option<String>,
    pub span: Span,
    /// Where each modifier was applied (`unique`, `references`, ...), for precise errors.
    pub mods: Vec<(&'static str, Span)>,
}

impl ColBuilder {
    /// Span of the modifier call, or of the builder when it wasn't called explicitly.
    pub fn span_of(&self, m: &str) -> Span {
        self.mods
            .iter()
            .rev()
            .find(|(k, _)| *k == m)
            .map(|(_, s)| *s)
            .unwrap_or(self.span)
    }
}

#[derive(Debug)]
pub struct GlobalVal {
    pub name: String,
    pub fields: IndexMap<String, Rc<ColBuilder>>,
    pub options: Option<Value>,
    pub span: Span,
}

/// `references(() => target, { onDelete, onUpdate })`, resolved after evaluation.
pub type RefBuilder = (Rc<Closure>, Option<RefAction>, Option<RefAction>, Span);

#[derive(Debug, Clone)]
pub enum DefaultVal {
    Value(Value),
    Now,
    Fn(Rc<Closure>),
}

/// Authored storage is explicit; user projections retain WordPress's identity store.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum StorageKind {
    Collection,
    Table,
    UserProjection,
}

impl StorageKind {
    pub fn builder(self) -> &'static str {
        match self {
            Self::Collection => "collection",
            Self::Table => "table",
            Self::UserProjection => "userProjection",
        }
    }

    pub fn registry(self) -> &'static str {
        match self {
            Self::Collection => "collections",
            Self::Table => "tables",
            Self::UserProjection => "users",
        }
    }
}

#[derive(Debug)]
pub struct CollectionVal {
    pub kind: StorageKind,
    /// Bound exactly once from the registry key, before references are extracted.
    pub table: OnceCell<String>,
    pub columns: IndexMap<String, Rc<ColBuilder>>,
    pub options: Option<Value>,
    pub span: Span,
}

impl CollectionVal {
    pub fn table(&self) -> &str {
        self.table.get().map(String::as_str).unwrap_or("<unregistered>")
    }
}

#[derive(Debug)]
pub struct ColRefVal {
    pub collection: Rc<CollectionVal>,
    pub column: String,
}

#[derive(Debug)]
pub struct RelVal {
    pub kind: RelKind,
    pub target: Rc<str>,
    pub config: Option<Value>,
}

#[derive(Debug)]
pub struct FileBucketVal {
    pub name: String,
    pub options: Value,
    pub span: Span,
}

#[derive(Debug)]
pub struct ActionVal {
    pub config: Value,
    pub span: Span,
}

/// Lexical scope. Function values keep their defining scope alive, so the
/// compiler can resolve what a hook captured (constants, helpers, collections).
#[derive(Debug)]
pub struct Scope {
    pub vars: RefCell<HashMap<String, Binding>>,
    pub parent: Option<Rc<Scope>>,
    pub module: usize,
}

#[derive(Debug, Clone)]
pub struct Binding {
    pub value: Value,
    pub mutable: bool,
    /// Declared at module top level (vs. inside a build-time function call).
    pub module_level: bool,
    /// `const x: T = ...`: the declared type, used when compiled code reads `x`.
    pub ann: Option<(crate::ir::TyAnn, usize)>,
}

impl Scope {
    pub fn new(parent: Option<Rc<Scope>>, module: usize) -> Rc<Scope> {
        Rc::new(Scope {
            vars: RefCell::new(HashMap::new()),
            parent,
            module,
        })
    }

    pub fn lookup(&self, name: &str) -> Option<Binding> {
        if let Some(b) = self.vars.borrow().get(name) {
            return Some(b.clone());
        }
        self.parent.as_ref().and_then(|p| p.lookup(name))
    }

    pub fn is_module_scope(&self) -> bool {
        self.parent.is_none()
    }
}

impl Value {
    pub fn str(s: &str) -> Value {
        Value::Str(Rc::from(s))
    }

    pub fn object(map: IndexMap<String, Value>) -> Value {
        Value::Object(Rc::new(RefCell::new(map)))
    }

    pub fn array(items: Vec<Value>) -> Value {
        Value::Array(Rc::new(RefCell::new(items)))
    }

    pub fn truthy(&self) -> bool {
        match self {
            Value::Undef | Value::Null => false,
            Value::Bool(b) => *b,
            Value::Num(n) => *n != 0.0 && !n.is_nan(),
            Value::Str(s) => !s.is_empty(),
            _ => true,
        }
    }

    pub fn type_of(&self) -> &'static str {
        match self {
            Value::Undef => "undefined",
            Value::Null => "object",
            Value::Bool(_) => "boolean",
            Value::Num(_) => "number",
            Value::Str(_) => "string",
            Value::Class(_) | Value::Closure(_) | Value::Builtin(_) | Value::Bound(..) => "function",
            _ => "object",
        }
    }

    /// A short description for error messages.
    pub fn describe(&self) -> String {
        match self {
            Value::Class(c) => format!("class {}", c.class.name),
            Value::Undef => "undefined".into(),
            Value::Null => "null".into(),
            Value::Bool(b) => b.to_string(),
            Value::Num(n) => js_number_to_string(*n),
            Value::Str(s) => format!("{s:?}"),
            Value::Array(_) => "an array".into(),
            Value::Arguments(_) => "an arguments object".into(),
            Value::Object(_) => "an object".into(),
            Value::Closure(_) | Value::Bound(..) => "a function".into(),
            Value::Builtin(b) => format!("{b:?}"),
            Value::Column(c) => format!("a {} column", c.ty.name()),
            Value::Collection(c) => format!("collection \"{}\"", c.table()),
            Value::ColRef(r) => format!("column {}.{}", r.collection.table(), r.column),
            Value::Relation(_) => "a relation".into(),
            Value::Action(_) => "an action".into(),
            Value::Job(_) => "a job".into(),
            Value::FileBucket(_) => "a file bucket".into(),
            Value::Env(n) => format!("env({n:?})"),
            Value::Global(g) => format!("global \"{}\"", g.name),
            Value::Regex(p, f) => format!("/{p}/{f}"),
        }
    }

    /// `String(v)` for primitives.
    pub fn to_js_string(&self) -> Option<String> {
        Some(match self {
            Value::Undef => "undefined".into(),
            Value::Null => "null".into(),
            Value::Bool(b) => b.to_string(),
            Value::Num(n) => js_number_to_string(*n),
            Value::Str(s) => s.to_string(),
            Value::Array(a) => {
                let parts: Option<Vec<String>> = a
                    .borrow()
                    .iter()
                    .map(|v| {
                        if matches!(v, Value::Undef | Value::Null) {
                            Some(String::new())
                        } else {
                            v.to_js_string()
                        }
                    })
                    .collect();
                parts?.join(",")
            }
            Value::Object(_) => "[object Object]".into(),
            Value::Arguments(_) => "[object Arguments]".into(),
            Value::Regex(p, f) => format!("/{p}/{f}"),
            _ => return None,
        })
    }

    pub fn strict_eq(&self, other: &Value) -> bool {
        match (self, other) {
            (Value::Class(a), Value::Class(b)) => Rc::ptr_eq(a, b),
            (Value::Undef, Value::Undef) | (Value::Null, Value::Null) => true,
            (Value::Bool(a), Value::Bool(b)) => a == b,
            (Value::Num(a), Value::Num(b)) => a == b,
            (Value::Str(a), Value::Str(b)) => a == b,
            (Value::Array(a), Value::Array(b)) => Rc::ptr_eq(a, b),
            (Value::Arguments(a), Value::Arguments(b)) => Rc::ptr_eq(a, b),
            (Value::Object(a), Value::Object(b)) => Rc::ptr_eq(a, b),
            (Value::Closure(a), Value::Closure(b)) => Rc::ptr_eq(a, b),
            (Value::Regex(a, af), Value::Regex(b, bf)) => Rc::ptr_eq(a, b) && Rc::ptr_eq(af, bf),
            (Value::Collection(a), Value::Collection(b)) => Rc::ptr_eq(a, b),
            (Value::Builtin(a), Value::Builtin(b)) => a == b,
            _ => false,
        }
    }

    pub fn same_value_zero(&self, other: &Value) -> bool {
        match (self, other) {
            (Value::Num(a), Value::Num(b)) if a.is_nan() && b.is_nan() => true,
            _ => self.strict_eq(other),
        }
    }
}
