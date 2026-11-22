//! Build-time values. `typedpress.config.ts` evaluates to these; the compiler then
//! reads the schema out of them and compiles the functions they hold.

use crate::diag::Span;
use crate::ir::{Func, TyAnn};
use crate::num::js_number_to_string;
use crate::schema::{ColType, RefAction, RelKind};
use indexmap::IndexMap;
use std::cell::{OnceCell, RefCell};
use std::collections::{HashMap, HashSet};
use std::rc::Rc;

pub type Obj = Rc<ObjCell>;
pub type Arr = Rc<RefCell<Vec<Value>>>;

/// A plain object's own properties, and whether it was created with `__proto__: null`.
/// A null-prototype object inherits nothing: reads of `Object.prototype` names it doesn't own
/// are `undefined`, `in` sees own keys only, and it has no default string conversion.
/// This mirrors `JsObject::$nullPrototype` in the PHP runtime.
#[derive(Debug, Default)]
pub struct ObjCell {
    /// Own string-keyed properties in creation order. An accessor property holds
    /// `Value::Accessor`; every other value is a data property.
    map: RefCell<IndexMap<String, Value>>,
    null_prototype: bool,
    /// Attributes of own properties that aren't ordinary (writable, enumerable, configurable),
    /// set by `Object.defineProperty`. An accessor property's value in `map` is
    /// `Value::Accessor`; its `writable` here is unused.
    attrs: RefCell<HashMap<String, Attrs>>,
}

/// Property attributes, as `Object.defineProperty` sets them. An absent entry is an ordinary
/// property: writable, enumerable and configurable.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Attrs {
    pub enumerable: bool,
    pub writable: bool,
    pub configurable: bool,
}

impl Attrs {
    pub const ORDINARY: Attrs = Attrs {
        enumerable: true,
        writable: true,
        configurable: true,
    };
}

/// An accessor property: `get`/`set` are functions or `undefined`.
#[derive(Debug, Clone)]
pub struct AccessorVal {
    pub get: Value,
    pub set: Value,
}

impl ObjCell {
    pub fn borrow(&self) -> std::cell::Ref<'_, IndexMap<String, Value>> {
        self.map.borrow()
    }

    pub fn borrow_mut(&self) -> std::cell::RefMut<'_, IndexMap<String, Value>> {
        self.map.borrow_mut()
    }

    pub fn null_prototype(&self) -> bool {
        self.null_prototype
    }

    pub fn attrs(&self, key: &str) -> Attrs {
        self.attrs.borrow().get(key).copied().unwrap_or(Attrs::ORDINARY)
    }

    pub fn set_attrs(&self, key: &str, attrs: Attrs) {
        if attrs == Attrs::ORDINARY {
            self.attrs.borrow_mut().remove(key);
        } else {
            self.attrs.borrow_mut().insert(key.to_string(), attrs);
        }
    }

    /// Removes an own property and its attributes.
    pub fn remove(&self, key: &str) {
        self.map.borrow_mut().shift_remove(key);
        self.attrs.borrow_mut().remove(key);
    }

    /// Whether some own property is non-enumerable, read-only, non-configurable or an accessor.
    pub fn has_attributes(&self) -> bool {
        !self.attrs.borrow().is_empty() || self.has_accessors()
    }

    pub fn has_accessors(&self) -> bool {
        self.map.borrow().values().any(|v| matches!(v, Value::Accessor(_)))
    }

    /// Own string keys in insertion order, enumerable or not (callers order integer keys).
    pub fn all_keys(&self) -> Vec<String> {
        self.map.borrow().keys().cloned().collect()
    }

    /// Own enumerable string keys in insertion order (callers order integer keys).
    pub fn enumerable_keys(&self) -> Vec<String> {
        let attrs = self.attrs.borrow();
        self.map
            .borrow()
            .keys()
            .filter(|k| attrs.get(*k).is_none_or(|a| a.enumerable))
            .cloned()
            .collect()
    }
}
#[derive(Clone, Debug)]
pub enum Value {
    Class(Rc<ClassValue>),
    /// `C.prototype` of a class declared in this program.
    Prototype(Rc<ClassValue>),
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
    Store(Rc<ActionVal>),
    /// `locations.id` / `r.locations.id`
    ColRef(Rc<ColRefVal>),
    Relation(Rc<RelVal>),
    Action(Rc<ActionVal>),
    Job(Rc<ActionVal>),
    /// `route({ method, path, handler })`: read only from a 0.3.0 capture (`eval::legacy`).
    Route(Rc<ActionVal>),
    /// `definePlugin({ name, hooks, actions, jobs })`, registered in `defineConfig({ use })`.
    Plugin(Rc<ActionVal>),
    /// `adminScreen({ ... })`: a TypedPress Admin screen, registered by its export (docs/admin-screens.md).
    Screen(Rc<ActionVal>),
    /// `adminWidget({ ... })`: a TypedPress Admin dashboard widget, registered by its export.
    Widget(Rc<ActionVal>),
    /// `adminDashboard({ ... })`: a TypedPress Admin custom dashboard, registered by its export.
    Dashboard(Rc<ActionVal>),
    FileBucket(Rc<FileBucketVal>),
    Env(Rc<str>),
    /// An imported file's URL (`import logo from "./compass.svg"`): its name below the app
    /// plugin's assets folder. The site's URL is known only at request time.
    Asset(Rc<str>),
    Global(Rc<GlobalVal>),
    Regex(Rc<str>, Rc<str>),
    /// A module-level `new Map(entries)` / `new Set(values)`: build-time contents in insertion
    /// order. Configuration reads them; compiled code captures one shared runtime collection.
    Coll(Rc<CollVal>),
    /// A JavaScript symbol created while the config is evaluated.
    Symbol(Rc<SymbolVal>),
    /// The slot of an accessor property inside an object's map (see `ObjCell`). Property reads
    /// call its getter; it is never a value of its own.
    Accessor(Rc<AccessorVal>),
    /// An empty `WeakMap` (`set == false`) or `WeakSet` created at module level.
    /// Each request gets its own; entries can only be added in compiled code.
    Weak(Rc<WeakVal>),
    /// A byte object built at module scope (`new TextEncoder()`, `Uint32Array.of(...)`). It only
    /// exists in PHP: compiled code rebuilds it once per request from plain-data arguments.
    Native(Rc<NativeVal>),
}

#[derive(Debug)]
pub struct NativeVal {
    /// The constructor, e.g. `Uint8Array`.
    pub constructor: &'static str,
    /// A static factory (`from`, `of`), or `None` for `new`.
    pub method: Option<Rc<str>>,
    pub args: Vec<Value>,
}

#[derive(Debug)]
pub struct CollVal {
    pub set: bool,
    /// (key, value); a Set stores each value as its key.
    pub entries: Vec<(Value, Value)>,
}

/// Registered and well-known symbols are identified by name; others by allocation.
#[derive(Debug, PartialEq, Eq)]
pub enum SymbolVal {
    Registered(Rc<str>),
    WellKnown(&'static str),
    Unique(Option<Rc<str>>),
}

/// The well-known symbols compiled code can read from `Symbol`.
pub const WELL_KNOWN_SYMBOLS: &[&str] = &["asyncIterator", "hasInstance", "iterator", "toStringTag"];

impl SymbolVal {
    pub fn description(&self) -> Option<String> {
        match self {
            SymbolVal::Registered(key) => Some(key.to_string()),
            SymbolVal::WellKnown(name) => Some(format!("Symbol.{name}")),
            SymbolVal::Unique(description) => description.as_ref().map(|d| d.to_string()),
        }
    }
}

#[derive(Debug)]
pub struct WeakVal {
    pub set: bool,
}

#[derive(Debug)]
pub struct ClassValue {
    pub class: Rc<crate::ir::Class>,
    pub base: Option<Rc<ClassValue>>,
    /// `extends Error` (or another `ERROR_CONSTRUCTORS` entry): the built-in root when there is no class base.
    pub builtin_base: Option<&'static str>,
    pub env: Rc<Scope>,
    pub statics: IndexMap<String, Value>,
    /// The evaluated key of each `class.symbol_methods` entry, in the same order.
    pub symbol_keys: Vec<Rc<SymbolVal>>,
    /// `Object.defineProperty(C.prototype, symbol, { value })` run while the module was
    /// evaluated: (key, value, enumerable, writable, configurable), last definition wins.
    pub prototype_symbols: std::cell::RefCell<Vec<PrototypeSymbol>>,
}

pub type PrototypeSymbol = (Rc<SymbolVal>, Value, bool, bool, bool);

#[derive(Debug)]
pub struct Closure {
    pub func: Rc<Func>,
    pub env: Rc<Scope>,
    /// Own properties written at build time (`f.x = 1`, `Object.assign(f, ...)`,
    /// `Object.defineProperty(f, ...)`), with their attributes. Compiled code reads them through
    /// static member chains; PHP closures can't carry them.
    pub props: ObjCell,
}

impl Closure {
    pub fn new(func: Rc<Func>, env: Rc<Scope>) -> Closure {
        Closure {
            func,
            env,
            props: ObjCell::default(),
        }
    }

    pub fn has_props(&self) -> bool {
        !self.props.borrow().is_empty()
    }
}

/// The `Object.prototype` methods TypedPress models: read as `Object.prototype.<name>` (a function
/// value, `Builtin::ObjectPrototypeMethod`) and called through `.call`/`.apply`.
pub const OBJECT_PROTOTYPE_METHODS: &[&str] = &["hasOwnProperty", "propertyIsEnumerable", "toString"];

/// Function properties TypedPress doesn't model: built-in own properties and `Function.prototype`.
pub const FUNCTION_BUILTIN_PROPERTIES: &[&str] = &[
    "name",
    "length",
    "prototype",
    "arguments",
    "caller",
    "call",
    "apply",
    "bind",
    "toString",
    "valueOf",
    "__proto__",
];

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Builtin {
    // "typedpress" exports
    Collection(StorageKind),
    Column(ColType),
    Id,
    /// email, url, slug, richText, media, select, group, repeater, blocks
    Field(&'static str),
    /// `global(name, fields, options)` from "typedpress".
    DefineGlobal,
    Store,
    DefineRelations,
    DefineConfig,
    DefinePlugin,
    /// `adminScreen({ ... })` from "typedpress".
    AdminScreen,
    /// `adminWidget({ ... })` from "typedpress".
    AdminWidget,
    /// `adminDashboard({ ... })` from "typedpress".
    AdminDashboard,
    /// `item(path)` from "typedpress": the `{item.<path>}` placeholder of a screen action's input.
    Item,
    Action,
    Job,
    /// `route()`, exported only to a 0.3.0 capture (`eval::legacy`).
    Route,
    Files,
    Env,
    Once,
    Jobs,
    /// `"node:crypto"`: the module namespace (`None`) or one of its supported functions.
    NodeCrypto(Option<&'static str>),
    /// `mail.send(message)` from "typedpress" / "spacepress", compiled to `TypedPress\Mail::send`.
    Mail,
    /// `connectors.call(role, tool, args?)` from "spacepress" / "typedpress", compiled to `TypedPress\Connectors::call`.
    Connectors,
    /// `abilities.call(name, input)` from "php/wordpress", compiled to `TypedPress\Wp\Abilities\Call::run`.
    Abilities,
    /// A catalog WordPress function from "php/wordpress", compiled to `TypedPress\Wp\Functions::call`.
    WpFunction(Rc<str>),
    /// Runtime-only values: usable in compiled code, an error at build time.
    Db,
    HttpError,
    /// `ref(kind)` from "typedpress".
    Ref,
    /// `terms(taxonomy)` from "typedpress": an item's terms of one taxonomy.
    Terms,
    /// `r.one.<key>` / `r.many.<key>` inside defineRelations.
    RelFn(RelKind, Rc<str>),
    /// `wp` from "typedpress": WordPress's own kinds.
    Wp,
    /// `wp.posts`: core's posts, whose `.extend()` and `.clone()` make kinds.
    WpPosts,
    // JavaScript globals
    Global(&'static str),
    /// A static function on a global, e.g. `Math.max`.
    Static(&'static str, Rc<str>),
    /// `require` in the CommonJS module with this id; it only runs while the config is built.
    Require(usize),
    /// `Object.prototype`, as an object value: compared, passed to `Object.create`, and read for
    /// its modeled methods.
    ObjectPrototype,
    /// `Object.prototype.<name>` for a name in `OBJECT_PROTOTYPE_METHODS`: a function value
    /// called through `.call`/`.apply` with its receiver.
    ObjectPrototypeMethod(&'static str),
    // "typedpress/app": page helpers, compiled by the app router; never run while the config builds.
    /// `notFound()`, `redirect(url, status?)`, `<Content>`, `<Html>`, `<Link>`: by export name.
    App(&'static str),
    // "typedpress/client": hooks for "use client" components, compiled to the Interactivity API.
    Client(&'static str),
    // "typedpress/content": `nav`, `pager`, `headings` for a hierarchical kind's pages.
    Content(&'static str),
}

#[derive(Debug, Clone)]
pub struct ColBuilder {
    pub ty: ColType,
    /// `.optional()`: the value may be null or omitted. Fields are required otherwise.
    pub optional: bool,
    /// Only the implicit `id`: authored fields never are primary keys.
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
    /// `.reverse("name")` on a `ref()`: the name of the many relation on the target.
    pub reverse: Option<String>,
    /// `terms(taxonomy)`: the item's terms of that taxonomy, kept in core's term relationships.
    pub terms: bool,
    pub ts_type: Option<(TyAnn, usize, Span)>,
    pub access: Option<Value>,
    pub show_if: Option<Value>,
    pub ui: Option<Value>,
    pub localized: bool,
    /// `file(decks)`: the bucket, by value; a 0.3.0 capture names it (`file({ bucket: "decks" })`).
    pub bucket: Option<BucketRef>,
    /// `.was("old")`: the stored name this field had before a rename.
    pub was: Option<String>,
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
    /// Bound once from the export name (or the `globals` key); a 0.3.0 capture names it in the call.
    pub name: OnceCell<String>,
    pub fields: IndexMap<String, Rc<ColBuilder>>,
    pub options: Option<Value>,
    pub span: Span,
}

/// What a reference field points at, resolved after evaluation, with its delete and update actions
/// and where it was written.
pub type RefBuilder = (RefTarget, Option<RefAction>, Option<RefAction>, Span);

#[derive(Debug, Clone)]
pub enum RefTarget {
    /// `ref(kind)`: the kind's `id`.
    Kind(Value),
    /// `ref(() => kind)`, for a kind declared later or the kind itself.
    Thunk(Rc<Closure>),
    /// `.references(() => kind.column)`: only the 0.3.0 reading (`eval::legacy`).
    Column(Rc<Closure>),
}

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
    /// `taxonomy()`: terms in core's term tables (docs/decisions/2026-11-11-authoring-surface.md, ruling 25).
    Taxonomy,
    Product,
    ProductCategory,
    ProductTag,
    Order,
}

impl StorageKind {
    pub fn commerce(self) -> bool {
        matches!(
            self,
            Self::Product | Self::ProductCategory | Self::ProductTag | Self::Order
        )
    }
    pub fn term(self) -> bool {
        matches!(self, Self::Taxonomy | Self::ProductCategory | Self::ProductTag)
    }
    pub fn builder(self) -> &'static str {
        match self {
            Self::Collection => "collection",
            Self::Table => "table",
            Self::UserProjection => "userProjection",
            Self::Taxonomy => "taxonomy",
            Self::Product => "product",
            Self::ProductCategory => "productCategory",
            Self::ProductTag => "productTag",
            Self::Order => "order",
        }
    }

    pub fn registry(self) -> &'static str {
        match self {
            Self::Collection => "collections",
            Self::Table => "tables",
            Self::UserProjection => "users",
            Self::Taxonomy => "taxonomies",
            Self::Product => "products",
            Self::ProductCategory => "productCategories",
            Self::ProductTag => "productTags",
            Self::Order => "orders",
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
    /// `.on({ ... })` calls in order: (the handlers object, where the call is).
    pub hooks: RefCell<Vec<(Value, Span)>>,
    /// `.index(fields, { unique? })` calls: compound indexes the author declared (spec §2.9).
    pub indexes: std::cell::RefCell<Vec<(Vec<String>, bool, Span)>>,
    /// Made by `wp.posts.extend()` (WordPress's own posts) or `wp.posts.clone()` (a kind like them).
    pub post: Option<PostKind>,
}

/// A kind with WordPress posts' fields: title, slug, content and excerpt.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PostKind {
    /// `wp.posts.extend(fields, options)`: core's `post` type, with the fields as post meta.
    Native,
    /// `wp.posts.clone(fields, options)`: its own post type, `tp_<name>`, shaped like posts.
    Clone,
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

/// The bucket a `file()` field holds ids from.
#[derive(Debug, Clone)]
pub enum BucketRef {
    Value(Rc<FileBucketVal>),
    Name(String),
}

impl BucketRef {
    /// The registered bucket name, once registration has named it.
    pub fn name(&self) -> Option<&str> {
        match self {
            BucketRef::Value(b) => b.name.get().map(String::as_str),
            BucketRef::Name(n) => Some(n),
        }
    }
}

#[derive(Debug)]
pub struct FileBucketVal {
    /// Bound once from the export name (or the `files` key); a 0.3.0 capture names it in the call.
    pub name: OnceCell<String>,
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
    pub exported: RefCell<HashSet<String>>,
    pub vars: RefCell<HashMap<String, Binding>>,
    pub parent: Option<Rc<Scope>>,
    pub module: usize,
}

#[derive(Debug, Clone)]
pub struct Binding {
    pub initialized: bool,
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
            exported: RefCell::new(HashSet::new()),
            vars: RefCell::new(HashMap::new()),
            parent,
            module,
        })
    }

    pub fn fork_bindings(&self) -> Rc<Scope> {
        Rc::new(Self {
            exported: RefCell::new(self.exported.borrow().clone()),
            vars: RefCell::new(self.vars.borrow().clone()),
            parent: self.parent.clone(),
            module: self.module,
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
        Value::Object(Rc::new(ObjCell {
            map: RefCell::new(map),
            null_prototype: false,
            attrs: RefCell::default(),
        }))
    }

    /// An object created by a literal with `__proto__: null`.
    pub fn null_prototype_object(map: IndexMap<String, Value>) -> Value {
        Value::Object(Rc::new(ObjCell {
            map: RefCell::new(map),
            null_prototype: true,
            attrs: RefCell::default(),
        }))
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
            Value::Builtin(
                Builtin::Db
                | Builtin::Jobs
                | Builtin::Global("Math" | "JSON" | "console" | "globalThis" | "navigator" | "process")
                | Builtin::ObjectPrototype,
            ) => "object",
            Value::Symbol(_) => "symbol",
            Value::Class(_) | Value::Closure(_) | Value::Builtin(_) | Value::Bound(..) => "function",
            _ => "object",
        }
    }

    /// A short description for error messages.
    pub fn describe(&self) -> String {
        match self {
            Value::Class(c) => format!("class {}", c.class.name),
            Value::Prototype(c) => format!("{}.prototype", c.class.name),
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
            Value::Route(_) => "a route".into(),
            Value::Plugin(_) => "a plugin".into(),
            Value::Screen(_) => "an admin screen".into(),
            Value::Widget(_) => "an admin widget".into(),
            Value::Dashboard(_) => "an admin dashboard".into(),
            Value::FileBucket(_) => "a file bucket".into(),
            Value::Env(n) => format!("env({n:?})"),
            Value::Asset(file) => format!("the URL of {file}"),
            Value::Store(_) => "a store".into(),
            Value::Global(g) => match g.name.get() {
                Some(name) => format!("global \"{name}\""),
                None => "a global".into(),
            },
            Value::Regex(p, f) => format!("/{}/{f}", crate::builtins::regex_source(p)),
            Value::Coll(c) => (if c.set { "a Set" } else { "a Map" }).into(),
            Value::Symbol(s) => format!("Symbol({})", s.description().unwrap_or_default()),
            Value::Weak(w) => if w.set { "a WeakSet" } else { "a WeakMap" }.into(),
            Value::Accessor(_) => "an accessor property".into(),
            Value::Native(n) => format!("a {}", n.constructor),
        }
    }

    /// JavaScript string conversion of supported configuration data.
    pub fn to_js_string(&self) -> Option<String> {
        self.string_with_arrays(&mut HashSet::new())
    }

    fn string_with_arrays(&self, active: &mut HashSet<usize>) -> Option<String> {
        Some(match self {
            Value::Undef => "undefined".into(),
            Value::Null => "null".into(),
            Value::Bool(b) => b.to_string(),
            Value::Num(n) => js_number_to_string(*n),
            Value::Str(s) => s.to_string(),
            Value::Array(a) => Self::join_array(a, ",", active)?,
            // No inherited toString: JavaScript throws converting it.
            Value::Object(o) if o.null_prototype() => return None,
            Value::Object(_) => "[object Object]".into(),
            Value::Arguments(_) => "[object Arguments]".into(),
            Value::Regex(p, f) => format!("/{}/{f}", crate::builtins::regex_source(p)),
            _ => return None,
        })
    }

    pub fn array_join(a: &Arr, separator: &str) -> Option<String> {
        Self::join_array(a, separator, &mut HashSet::new())
    }

    fn join_array(a: &Arr, separator: &str, active: &mut HashSet<usize>) -> Option<String> {
        let key = Rc::as_ptr(a) as usize;
        if !active.insert(key) {
            return Some(String::new());
        }
        let parts: Option<Vec<String>> = a
            .borrow()
            .iter()
            .map(|value| {
                if matches!(value, Value::Undef | Value::Null) {
                    Some(String::new())
                } else {
                    value.string_with_arrays(active)
                }
            })
            .collect();
        active.remove(&key);
        Some(parts?.join(separator))
    }

    pub fn strict_eq(&self, other: &Value) -> bool {
        match (self, other) {
            (Value::Class(a), Value::Class(b)) | (Value::Prototype(a), Value::Prototype(b)) => Rc::ptr_eq(a, b),
            (Value::Undef, Value::Undef) | (Value::Null, Value::Null) => true,
            (Value::Bool(a), Value::Bool(b)) => a == b,
            (Value::Num(a), Value::Num(b)) => a == b,
            (Value::Str(a), Value::Str(b)) => a == b,
            (Value::Array(a), Value::Array(b)) => Rc::ptr_eq(a, b),
            (Value::Arguments(a), Value::Arguments(b)) => Rc::ptr_eq(a, b),
            (Value::Object(a), Value::Object(b)) => Rc::ptr_eq(a, b),
            (Value::Closure(a), Value::Closure(b)) => Rc::ptr_eq(a, b),
            (Value::Regex(a, af), Value::Regex(b, bf)) => Rc::ptr_eq(a, b) && Rc::ptr_eq(af, bf),
            (Value::Symbol(a), Value::Symbol(b)) => match (&**a, &**b) {
                (SymbolVal::Unique(_), SymbolVal::Unique(_)) => Rc::ptr_eq(a, b),
                (a, b) => a == b,
            },
            (Value::Weak(a), Value::Weak(b)) => Rc::ptr_eq(a, b),
            (Value::Native(a), Value::Native(b)) => Rc::ptr_eq(a, b),
            (Value::Collection(a), Value::Collection(b)) => Rc::ptr_eq(a, b),
            (Value::Coll(a), Value::Coll(b)) => Rc::ptr_eq(a, b),
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
