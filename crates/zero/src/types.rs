//! Types the PHP compiler tracks. They decide which PHP to emit: native
//! operators when the types make it safe, `Js::` helpers where JavaScript and
//! PHP genuinely differ, and a build error where neither is sound.

use crate::schema::{ColType, Collection, Column, ROLES, RelKind, Schema};
use indexmap::IndexMap;

#[derive(Debug, Clone, PartialEq)]
pub struct ClassIdentity {
    pub id: usize,
    pub ancestors: Vec<usize>,
}

#[derive(Debug, Clone, PartialEq)]
pub enum Ty {
    /// Instance own fields, with a nominal dispatch identity. Methods are not JSON fields.
    Instance(ClassIdentity, Box<Ty>),
    Class(usize),
    Num,
    NumLit(f64),
    FreshNumLit(f64),
    Str,
    /// A finite string discriminant, retained for switch exhaustiveness.
    StrLit(String),
    /// An inferred literal that widens when stored in a mutable position.
    FreshStrLit(String),
    Bool,
    BoolLit(bool),
    FreshBoolLit(bool),
    /// Null, including normalized missing database and typed optional values.
    Null,
    /// Explicit runtime undefined uses a sentinel. Annotation normalization still
    /// maps nullable storage and wire domains onto Null.
    Undefined,
    /// Type-only void; it is not equivalent to undefined in conditional types.
    Void,
    /// Values whose shape isn't known (untyped `json()` columns, `JSON.parse`).
    Unknown,
    /// Unannotated JavaScript values. Operations use checked JavaScript runtime
    /// dispatch; authored TypeScript `unknown` remains statically restricted.
    Dynamic,
    /// TypeScript's implicit arguments object. Indexed values remain unknown,
    /// so this runtime escape cannot mutate statically typed parameter objects.
    Arguments,
    Never,
    Array(Box<Ty>),
    /// An array proven to retain at least one element. Mutations which can
    /// shrink it erase the bound from the read view.
    NonEmptyArray(Box<Ty>),
    Tuple(Vec<Ty>),
    /// An inferred array literal whose elements widen at binding boundaries.
    ArrayLiteral(Vec<Ty>),
    /// Object with known fields: (type, optional).
    Obj(IndexMap<String, (Ty, bool)>),
    /// `Record<string, T>`
    Map(Box<Ty>),
    Union(Vec<Ty>),
    Fn(Vec<Ty>, Box<Ty>),
    /// Callable parameter metadata; inner type is the rest binding's array.
    Rest(Box<Ty>),
    /// Boolean return carrying a declared parameter refinement in a function signature.
    Predicate(usize, Box<Ty>),
    Promise(Box<Ty>),
    /// Epoch milliseconds (a PHP number) that remembers it came from `Date`.
    Date,
    Regex,
    /// A caught exception.
    Error,
    // Zero runtime values
    Collection(String),
    Db,
    DbQuery,
    QueryColl(String),
    /// `db.globals` and `db.globals.<key>`.
    DbGlobals,
    DbGlobal(String),
    Builder(Box<Builder>),
}

#[derive(Debug, Clone, PartialEq)]
pub enum BuilderKind {
    Insert,
    Update,
    Delete,
}

/// `db.insert(t).values(v).returning()` and friends, assembled into one runtime call when awaited.
#[derive(Debug, Clone, PartialEq)]
pub struct Builder {
    pub kind: BuilderKind,
    pub table: String,
    pub values: Option<String>,
    pub set: Option<String>,
    pub filter: Option<String>,
    pub returning: bool,
}

impl Builder {
    pub fn php(&self) -> String {
        let t = crate::compile::php_str(&self.table);
        let r = if self.returning { ", true" } else { "" };
        match self.kind {
            BuilderKind::Insert => format!(
                "\\Zero\\Seam::insert({t}, {}{r})",
                self.values.as_deref().unwrap_or("[]")
            ),
            BuilderKind::Update => format!(
                "\\Zero\\Seam::update({t}, {}, {}{r})",
                self.set.as_deref().unwrap_or("[]"),
                self.filter.as_deref().unwrap_or("[]")
            ),
            BuilderKind::Delete => format!(
                "\\Zero\\Seam::delete({t}, {}{r})",
                self.filter.as_deref().unwrap_or("[]")
            ),
        }
    }

    pub fn result(&self, schema: &Schema) -> Ty {
        if self.returning {
            Ty::Array(Box::new(row(schema, &self.table)))
        } else {
            Ty::Null
        }
    }
}

impl Ty {
    pub fn union(items: impl IntoIterator<Item = Ty>) -> Ty {
        let mut out: Vec<Ty> = Vec::new();
        for t in items {
            let parts = match t {
                Ty::Union(inner) => inner,
                Ty::Never => continue,
                other => vec![other],
            };
            for p in parts {
                // Merge `T[]` with `never[]` (from `[]` literals).
                if let Ty::Array(el) = &p
                    && let Some(existing) = out.iter_mut().find(|x| matches!(x, Ty::Array(_)))
                {
                    let Ty::Array(cur) = existing else { unreachable!() };
                    if **cur == Ty::Never {
                        *existing = Ty::Array(el.clone());
                        continue;
                    }
                    if **el == Ty::Never || cur == el {
                        continue;
                    }
                }
                if !out.contains(&p) {
                    out.push(p);
                }
            }
        }
        if out.contains(&Ty::Dynamic) {
            return Ty::Dynamic;
        }
        if out.iter().any(|t| t.bool_literal().is_some())
            && (out.contains(&Ty::Bool)
                || out.iter().any(|t| t.bool_literal() == Some(true))
                    && out.iter().any(|t| t.bool_literal() == Some(false)))
        {
            let first = out.iter().position(Ty::is_bool).expect("boolean member");
            out.retain(|t| !t.is_bool());
            out.insert(first, Ty::Bool);
        }
        match out.len() {
            0 => Ty::Never,
            1 => out.pop().expect("one"),
            _ => Ty::Union(out),
        }
    }

    pub fn nullable(self) -> Ty {
        Ty::union([self, Ty::Null])
    }

    pub fn non_null(&self) -> Ty {
        match self {
            Ty::Union(items) => Ty::union(
                items
                    .iter()
                    .filter(|t| **t != Ty::Null && **t != Ty::Undefined)
                    .cloned(),
            ),
            Ty::Null | Ty::Undefined => Ty::Never,
            other => other.clone(),
        }
    }

    pub fn nullish(&self) -> Ty {
        match self {
            Ty::Union(items) => Ty::union(items.iter().map(Ty::nullish)),
            Ty::Null | Ty::Undefined | Ty::Void => self.clone(),
            Ty::Unknown | Ty::Dynamic => Ty::union([Ty::Null, Ty::Undefined]),
            _ => Ty::Never,
        }
    }

    pub fn is_nullable(&self) -> bool {
        match self {
            Ty::Null | Ty::Undefined | Ty::Void | Ty::Unknown => true,
            Ty::Union(items) => {
                items.contains(&Ty::Null) || items.contains(&Ty::Undefined) || items.contains(&Ty::Void)
            }
            _ => false,
        }
    }

    /// True when every member satisfies `pred`.
    pub fn all(&self, pred: impl Fn(&Ty) -> bool) -> bool {
        match self {
            Ty::Union(items) => items.iter().all(pred),
            other => pred(other),
        }
    }

    pub fn is_num(&self) -> bool {
        self.all(|t| matches!(t, Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_)))
    }

    pub fn is_str(&self) -> bool {
        self.all(|t| matches!(t, Ty::Str | Ty::StrLit(_) | Ty::FreshStrLit(_)))
    }

    pub fn is_bool(&self) -> bool {
        self.all(|t| matches!(t, Ty::Bool | Ty::BoolLit(_) | Ty::FreshBoolLit(_)))
    }

    pub fn bool_literal(&self) -> Option<bool> {
        match self {
            Ty::BoolLit(value) | Ty::FreshBoolLit(value) => Some(*value),
            _ => None,
        }
    }

    pub fn describe(&self) -> String {
        match self {
            Ty::Instance(_, fields) => format!("an instance with {}", fields.describe()),
            Ty::Class(_) => "a class".into(),
            Ty::Num | Ty::FreshNumLit(_) => "number".into(),
            Ty::NumLit(value) => value.to_string(),
            Ty::Str => "string".into(),
            Ty::StrLit(s) | Ty::FreshStrLit(s) => format!("{s:?}"),
            Ty::Bool => "boolean".into(),
            Ty::BoolLit(value) | Ty::FreshBoolLit(value) => value.to_string(),
            Ty::Null => "null".into(),
            Ty::Undefined => "undefined".into(),
            Ty::Void => "void".into(),
            Ty::Unknown => "unknown".into(),
            Ty::Dynamic => "a JavaScript value".into(),
            Ty::Arguments => "IArguments".into(),
            Ty::Never => "never".into(),
            Ty::Array(t) => format!("{}[]", t.describe()),
            Ty::NonEmptyArray(t) => format!("[{}, ...{}[]]", t.describe(), t.describe()),
            Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => {
                format!("[{}]", ts.iter().map(Ty::describe).collect::<Vec<_>>().join(", "))
            }
            Ty::Obj(fields) => {
                let f: Vec<String> = fields
                    .iter()
                    .filter(|(k, _)| !k.starts_with('\u{0}'))
                    .take(4)
                    .map(|(k, (t, o))| format!("{k}{}: {}", if *o { "?" } else { "" }, t.describe()))
                    .collect();
                format!("{{ {}{} }}", f.join("; "), if fields.len() > 4 { "; ..." } else { "" })
            }
            Ty::Map(t) => format!("Record<string, {}>", t.describe()),
            Ty::Union(ts) => ts.iter().map(Ty::describe).collect::<Vec<_>>().join(" | "),
            Ty::Fn(..) => "a function".into(),
            Ty::Rest(t) => format!("...{}", t.describe()),
            Ty::Predicate(_, target) => format!("a type predicate for {}", target.describe()),
            Ty::Promise(t) => format!("Promise<{}>", t.describe()),
            Ty::Date => "Date".into(),
            Ty::Regex => "RegExp".into(),
            Ty::Error => "Error".into(),
            Ty::Collection(t) => format!("collection \"{t}\""),
            Ty::Db => "db".into(),
            Ty::DbQuery => "db.query".into(),
            Ty::QueryColl(t) => format!("db.query for \"{t}\""),
            Ty::DbGlobals => "db.globals".into(),
            Ty::DbGlobal(g) => format!("db.globals.{g}"),
            Ty::Builder(b) => format!("an unfinished db.{:?} for \"{}\"", b.kind, b.table).to_lowercase(),
        }
    }

    pub fn field(&self, name: &str) -> Option<(Ty, bool)> {
        match self {
            Ty::Instance(_, fields) => fields.field(name),
            Ty::Obj(fields) => fields.get(name).cloned(),
            Ty::Map(t) => Some(((**t).clone(), false)),
            // A union of objects (blocks items): fields every member has.
            Ty::Union(items) if items.iter().all(|t| matches!(t, Ty::Obj(_))) => {
                let mut tys = Vec::new();
                let mut optional = false;
                let mut found = false;
                for t in items {
                    match t.field(name) {
                        Some((ft, o)) => {
                            found = true;
                            tys.push(ft);
                            optional |= o;
                        }
                        None => optional = true,
                    }
                }
                found.then(|| (Ty::union(tys), optional))
            }
            _ => None,
        }
    }
}

/// The value type a column holds, before nullability.
pub fn col_base(c: &Column, json_ty: &dyn Fn(&Column) -> Ty, insert: bool) -> Ty {
    match c.ty {
        ColType::Integer | ColType::Real | ColType::Media | ColType::File => Ty::Num,
        ColType::Text if c.enum_values.is_some() => {
            Ty::union(c.enum_values.as_ref().expect("enum").iter().cloned().map(Ty::StrLit))
        }
        ColType::Text
        | ColType::Timestamp
        | ColType::Date
        | ColType::Email
        | ColType::Url
        | ColType::Slug
        | ColType::RichText => Ty::Str,
        ColType::Boolean => Ty::Bool,
        ColType::Json => json_ty(c),
        ColType::MediaList => Ty::Array(Box::new(Ty::Num)),
        ColType::Select => Ty::Array(Box::new(
            c.enum_values
                .as_ref()
                .map(|values| Ty::union(values.iter().cloned().map(Ty::StrLit)))
                .unwrap_or(Ty::Str),
        )),
        ColType::Group => fields_obj(c.fields.as_ref().expect("group fields"), json_ty, insert),
        ColType::Repeater => Ty::Array(Box::new(fields_obj(
            c.fields.as_ref().expect("repeater fields"),
            json_ty,
            insert,
        ))),
        ColType::Blocks => Ty::Array(Box::new(Ty::union(c.layouts.as_ref().expect("layouts").iter().map(
            |(name, fields)| {
                let Ty::Obj(mut f) = fields_obj(fields, json_ty, insert) else {
                    unreachable!()
                };
                f.shift_insert(0, "layout".into(), (Ty::StrLit(name.clone()), false));
                // Not a real field: names the layout so `item.layout === "hero"` can narrow.
                f.insert(layout_marker(name), (Ty::Never, true));
                Ty::Obj(f)
            },
        )))),
    }
}

/// The hidden key that tags a blocks item type with its layout.
pub fn layout_marker(name: &str) -> String {
    format!("\u{0}layout:{name}")
}

/// The layout a blocks item type stands for.
pub fn layout_of(t: &Ty) -> Option<&str> {
    let Ty::Obj(f) = t else { return None };
    f.keys().find_map(|k| k.strip_prefix("\u{0}layout:"))
}

/// Sub-fields as an object type: every key present on read; optional ones may be omitted on write.
fn fields_obj(fields: &IndexMap<String, Column>, json_ty: &dyn Fn(&Column) -> Ty, insert: bool) -> Ty {
    Ty::Obj(
        fields
            .values()
            .map(|f| {
                let t = col_select(f, json_ty, insert);
                (f.name.clone(), (t, insert && f.optional_on_insert()))
            })
            .collect(),
    )
}

fn col_select(c: &Column, json_ty: &dyn Fn(&Column) -> Ty, insert: bool) -> Ty {
    let base = col_base(c, json_ty, insert);
    if c.authored_not_null() { base } else { base.nullable() }
}

pub struct JsonType {
    pub authored: Ty,
    pub runtime: Ty,
}

thread_local! {
    /// Resolved domains keyed by schema column identity, reset for each compilation.
    pub static JSON_TYPES: std::cell::RefCell<std::collections::HashMap<usize, JsonType>> = Default::default();
}

pub fn json_ty(c: &Column) -> Ty {
    JSON_TYPES.with(|m| {
        m.borrow()
            .get(&(c as *const Column as usize))
            .map(|t| t.runtime.clone())
            .unwrap_or(Ty::Unknown)
    })
}

pub fn json_authored_ty(c: &Column) -> Ty {
    JSON_TYPES.with(|m| {
        m.borrow()
            .get(&(c as *const Column as usize))
            .map(|t| t.authored.clone())
            .unwrap_or(Ty::Unknown)
    })
}

/// The type of a collection's primary key: row ids in `translations` and `translationOf` (§7.3).
fn pk_ty(c: &Collection) -> Ty {
    c.primary_key().map(|p| col_base(p, &json_ty, false)).unwrap_or(Ty::Num)
}

pub fn row_of(c: &Collection) -> Ty {
    row_of_with(c, &json_ty)
}

pub fn row_of_with(c: &Collection, json_ty: &dyn Fn(&Column) -> Ty) -> Ty {
    let mut row = columns_row(&c.columns, json_ty);
    if let Ty::Obj(fields) = &mut row {
        fields.retain(|name, _| !c.columns[name].hidden);
    }
    if c.localized
        && let Ty::Obj(fields) = &mut row
        && let Some(t) = fields.get_mut("translations")
    {
        // §7.3: derived locale → id of every row in the translation group.
        t.0 = Ty::Obj(
            c.columns["locale"]
                .enum_values
                .as_ref()
                .expect("locales")
                .iter()
                .map(|locale| (locale.clone(), (pk_ty(c), true)))
                .collect(),
        );
    }
    row
}

pub fn columns_row(columns: &IndexMap<String, Column>, json_ty: &dyn Fn(&Column) -> Ty) -> Ty {
    Ty::Obj(
        columns
            .values()
            .map(|col| (col.name.clone(), (col_select(col, json_ty, false), false)))
            .collect(),
    )
}

pub fn row(schema: &Schema, table: &str) -> Ty {
    row_of(&schema.collections[table])
}

/// Storage validation normalizes undefined to null only within nullable fields.
pub fn storage_input(ty: Ty) -> Ty {
    match ty {
        Ty::Null => Ty::union([Ty::Null, Ty::Undefined]),
        Ty::Union(items) => Ty::union(items.into_iter().map(storage_input)),
        Ty::Obj(fields) => Ty::Obj(
            fields
                .into_iter()
                .map(|(key, (ty, optional))| (key, (storage_input(ty), optional)))
                .collect(),
        ),
        Ty::Array(ty) => Ty::Array(Box::new(storage_input(*ty))),
        Ty::NonEmptyArray(ty) => Ty::NonEmptyArray(Box::new(storage_input(*ty))),
        Ty::Tuple(items) => Ty::Tuple(items.into_iter().map(storage_input).collect()),
        other => other,
    }
}

pub fn insert(schema: &Schema, table: &str) -> Ty {
    insert_with(schema, table, &json_ty)
}

pub fn insert_with(schema: &Schema, table: &str, json_ty: &dyn Fn(&Column) -> Ty) -> Ty {
    let c = &schema.collections[table];
    let mut t = columns_insert(&c.columns, json_ty);
    if c.localized
        && let Ty::Obj(fields) = &mut t
    {
        // §7.3: system columns aren't written; `translationOf` joins another row's group on create.
        fields.shift_remove("translations");
        fields.shift_remove("translationGroup");
        fields.insert("translationOf".into(), (pk_ty(c).nullable(), true));
        // A translation copies synced columns from its group, so hooks may omit them; a fresh row
        // missing a required one is still refused by the pipeline (422 at the column).
        for col in c
            .columns
            .values()
            .filter(|col| !col.translated() && !col.system && !col.primary_key)
        {
            if let Some(f) = fields.get_mut(&col.name) {
                f.1 = true;
            }
        }
    }
    t
}

/// Authored model annotations follow the declaration contract, before PHP's
/// broader row/insert approximation or wire null normalization is applied.
pub fn authored_model(c: &Collection, insert: bool) -> Ty {
    let primary_key = || {
        c.primary_key()
            .map(|col| authored_column(col, false))
            .unwrap_or(Ty::Num)
    };
    let fields = || {
        c.columns
            .values()
            .filter(|col| !(col.hidden || insert && col.system && col.name == "translations"))
            .map(|col| {
                let ty = if col.system && col.name == "translations" && c.localized {
                    Ty::Obj(
                        c.columns["locale"]
                            .enum_values
                            .as_ref()
                            .expect("locales")
                            .iter()
                            .map(|locale| (locale.clone(), (primary_key(), true)))
                            .collect(),
                    )
                } else {
                    authored_column(col, insert)
                };
                (
                    col.name.clone(),
                    (ty, insert && (!col.authored_not_null() || col.authored_has_default())),
                )
            })
            .chain((insert && c.auth).then(|| ("password".into(), (Ty::Str, true))))
            .collect::<IndexMap<_, _>>()
    };
    let mut fresh = fields();
    if !insert || !c.localized {
        return Ty::Obj(fresh);
    }
    fresh.insert("translationOf".into(), (Ty::Undefined, true));
    let mut translation = fields();
    for col in c
        .columns
        .values()
        .filter(|col| !col.translated() && !col.primary_key && col.ty != ColType::Slug && col.name != "locale")
    {
        if let Some(field) = translation.get_mut(&col.name) {
            field.1 = true;
        }
    }
    translation.insert("translationOf".into(), (primary_key(), false));
    Ty::union([Ty::Obj(fresh), Ty::Obj(translation)])
}

fn authored_column(col: &Column, insert: bool) -> Ty {
    let fields = |fields: &IndexMap<String, Column>| {
        Ty::Obj(
            fields
                .values()
                .filter(|f| !f.hidden)
                .map(|f| (f.name.clone(), (authored_column(f, false), false)))
                .collect(),
        )
    };
    let base = match col.ty {
        ColType::Group => fields(col.fields.as_ref().expect("fields")),
        ColType::Repeater => Ty::Array(Box::new(fields(col.fields.as_ref().expect("fields")))),
        ColType::Blocks => Ty::Array(Box::new(Ty::union(col.layouts.as_ref().expect("layouts").iter().map(
            |(layout, columns)| {
                let Ty::Obj(mut values) = fields(columns) else {
                    unreachable!()
                };
                values.shift_insert(0, "layout".into(), (Ty::StrLit(layout.clone()), false));
                Ty::Obj(values)
            },
        )))),
        _ => col_base(col, &json_authored_ty, false),
    };
    let ty = if col.authored_not_null() { base } else { base.nullable() };
    if insert && matches!(col.ty, ColType::Group | ColType::Repeater | ColType::Blocks) {
        authored_structured_write(ty)
    } else {
        ty
    }
}

/// Declaration WriteStructured: nullable members may be omitted, including
/// nested JSON members; NOT NULL members with defaults remain required.
fn authored_structured_write(ty: Ty) -> Ty {
    match ty {
        Ty::Obj(fields) => Ty::Obj(
            fields
                .into_iter()
                .map(|(key, (ty, optional))| {
                    let nullable = ty == Ty::Null || matches!(&ty, Ty::Union(parts) if parts.contains(&Ty::Null));
                    (key, (authored_structured_write(ty), optional || nullable))
                })
                .collect(),
        ),
        Ty::Array(inner) => Ty::Array(Box::new(authored_structured_write(*inner))),
        Ty::Tuple(parts) => Ty::Array(Box::new(Ty::union(parts.into_iter().map(authored_structured_write)))),
        Ty::Map(inner) => Ty::Map(Box::new(authored_structured_write(*inner))),
        Ty::Union(parts) => Ty::union(parts.into_iter().map(authored_structured_write)),
        other => other,
    }
}

pub fn columns_insert(columns: &IndexMap<String, Column>, json_ty: &dyn Fn(&Column) -> Ty) -> Ty {
    Ty::Obj(
        columns
            .values()
            .map(|col| {
                (
                    col.name.clone(),
                    (col_select(col, json_ty, true), col.optional_on_insert()),
                )
            })
            .collect(),
    )
}

/// A global's values: always complete (unset fields read as their defaults or null).
pub fn global_row(g: &crate::schema::Global) -> Ty {
    columns_row(&g.fields, &|_| Ty::Unknown)
}

pub fn partial(t: Ty) -> Ty {
    match t {
        Ty::Obj(fields) => Ty::Obj(fields.into_iter().map(|(k, (t, _))| (k, (t, true))).collect()),
        other => other,
    }
}

/// Action input as the handler sees it, after defaults and validation.
pub fn action_input(columns: &IndexMap<String, Column>, json_ty: &dyn Fn(&Column) -> Ty) -> Ty {
    Ty::Obj(
        columns
            .values()
            .map(|c| {
                let base = col_select(c, json_ty, false);
                let required = c.not_null || c.default.is_some();
                let t = if c.not_null || c.default.is_some() {
                    base.non_null()
                } else {
                    base
                };
                let t = if required { t } else { t.nullable() };
                (c.name.clone(), (t, false))
            })
            .collect(),
    )
}

pub fn obj_pub(fields: &[(&str, Ty)]) -> Ty {
    obj(fields)
}

fn obj(fields: &[(&str, Ty)]) -> Ty {
    Ty::Obj(
        fields
            .iter()
            .map(|(k, t)| (k.to_string(), (t.clone(), false)))
            .collect(),
    )
}

/// `{ id, name, role }` (§5.1).
pub fn user() -> Ty {
    obj(&[
        ("id", Ty::Num),
        ("name", Ty::Str),
        ("role", Ty::union(ROLES.map(|role| Ty::StrLit(role.into())))),
    ])
}

/// `ctx` for hooks and actions (§1.7).
/// What `await fetch(...)` returns: the parts of a WHATWG Response a handler reads.
pub fn fetch_response() -> Ty {
    obj(&[
        ("status", Ty::Num),
        ("ok", Ty::Bool),
        ("url", Ty::Str),
        ("redirected", Ty::Bool),
        (
            "headers",
            obj(&[
                ("get", Ty::Fn(vec![Ty::Str], Box::new(Ty::Str.nullable()))),
                ("has", Ty::Fn(vec![Ty::Str], Box::new(Ty::Bool))),
            ]),
        ),
        // Promises, as in JavaScript; PHP reads the body before the call returns.
        ("text", Ty::Fn(vec![], Box::new(Ty::Promise(Box::new(Ty::Str))))),
        ("json", Ty::Fn(vec![], Box::new(Ty::Promise(Box::new(Ty::Unknown))))),
    ])
}

pub fn hook_ctx() -> Ty {
    obj(&[("user", user().nullable())])
}

/// A route handler's first argument: the request as received, the body as raw text.
pub fn route_request() -> Ty {
    obj(&[
        ("method", Ty::Str),
        ("path", Ty::Str),
        ("params", Ty::Map(Box::new(Ty::Str))),
        ("query", route_query()),
        ("headers", Ty::Map(Box::new(Ty::Str))),
        ("body", Ty::Str),
    ])
}

/// `req.query`: URLSearchParams' read methods over the raw query string, names byte-exact and every
/// value kept in order (runtime/Zero/QueryString.php).
pub fn route_query() -> Ty {
    obj(&[
        ("get", Ty::Fn(vec![Ty::Str], Box::new(Ty::Str.nullable()))),
        ("getAll", Ty::Fn(vec![Ty::Str], Box::new(Ty::Array(Box::new(Ty::Str))))),
        ("has", Ty::Fn(vec![Ty::Str], Box::new(Ty::Bool))),
        (
            "entries",
            Ty::Fn(vec![], Box::new(Ty::Array(Box::new(Ty::Tuple(vec![Ty::Str, Ty::Str]))))),
        ),
    ])
}

/// What a route handler returns: `{ status?, headers?, body? }`.
pub fn route_response() -> Ty {
    Ty::Obj(
        [
            ("status".to_string(), (Ty::Num, true)),
            ("headers".to_string(), (Ty::Map(Box::new(Ty::Str)), true)),
            ("body".to_string(), (Ty::Str, true)),
        ]
        .into_iter()
        .collect(),
    )
}

pub fn job_ctx() -> Ty {
    let Ty::Obj(mut props) = hook_ctx() else { unreachable!() };
    props.insert("attempt".into(), (Ty::Num, false));
    props.insert("jobId".into(), (Ty::Str, false));
    Ty::Obj(props)
}

pub fn field_access_ctx() -> Ty {
    let Ty::Obj(mut props) = access_ctx(Ty::Map(Box::new(Ty::Unknown))) else {
        unreachable!()
    };
    props.insert("data".into(), (Ty::Map(Box::new(Ty::Unknown)), false));
    props.insert(
        "op".into(),
        (
            Ty::union(["read", "create", "update"].map(|op| Ty::StrLit(op.into()))),
            false,
        ),
    );
    Ty::Obj(props)
}

/// `ctx` for access rules (§1.6). `data` is the incoming row (create) or the patch (update).
pub fn access_ctx(data: Ty) -> Ty {
    let Ty::Obj(mut f) = obj(&[("user", user().nullable()), ("op", Ty::Str)]) else {
        unreachable!()
    };
    f.insert("data".into(), (data.nullable(), true));
    Ty::Obj(f)
}

/// `ctx` for `.validate` (§1.2): the whole row being written.
pub fn data_ctx(data: Ty) -> Ty {
    obj(&[("data", data), ("user", user().nullable())])
}

/// A media row (§4.6), what `with: { cover: true }` puts in place of the id.
pub fn media_row() -> Ty {
    obj(&[
        ("id", Ty::Num),
        ("url", Ty::Str),
        ("mime", Ty::Str),
        ("size", Ty::Num),
        ("width", Ty::Num.nullable()),
        ("height", Ty::Num.nullable()),
        ("alt", Ty::Str.nullable()),
        ("filename", Ty::Str),
        ("createdAt", Ty::Str),
    ])
}

/// A row with relations loaded by `with`.
pub fn with_relations(schema: &Schema, table: &str, base: Ty, rel: &str, nested: Ty) -> Ty {
    let Ty::Obj(mut fields) = base else { return base };
    let r = &schema.collections[table].relations[rel];
    let t = match r.kind {
        RelKind::One if r.optional => nested.nullable(),
        RelKind::One => nested,
        RelKind::Many => Ty::Array(Box::new(nested)),
    };
    fields.insert(rel.to_string(), (t, false));
    Ty::Obj(fields)
}
fn wire_omits(t: &Ty) -> bool {
    match t {
        Ty::Undefined | Ty::Void | Ty::Fn(..) | Ty::Unknown | Ty::Dynamic => true,
        Ty::Union(items) => items.iter().any(wire_omits),
        _ => false,
    }
}

fn wire_present(t: &Ty) -> Ty {
    match t {
        Ty::Undefined | Ty::Void | Ty::Fn(..) => Ty::Never,
        Ty::Union(items) => Ty::union(items.iter().map(wire_present)),
        _ => t.clone(),
    }
}

/// JSON nulls array slots and omits object properties without serializable values.
pub fn json_wire_type(t: &Ty) -> Ty {
    match t {
        Ty::Undefined | Ty::Void | Ty::Fn(..) => Ty::Null,
        Ty::Date => Ty::Str.nullable(),
        Ty::NumLit(n) | Ty::FreshNumLit(n) if !n.is_finite() => Ty::Null,
        Ty::Array(t) => Ty::Array(Box::new(json_wire_type(t))),
        Ty::NonEmptyArray(t) => Ty::NonEmptyArray(Box::new(json_wire_type(t))),
        Ty::Tuple(items) => Ty::Tuple(items.iter().map(json_wire_type).collect()),
        Ty::ArrayLiteral(items) => Ty::ArrayLiteral(items.iter().map(json_wire_type).collect()),
        Ty::Obj(fields) => Ty::Obj(
            fields
                .iter()
                .map(|(key, (ty, optional))| {
                    (
                        key.clone(),
                        (json_wire_type(&wire_present(ty)), *optional || wire_omits(ty)),
                    )
                })
                .collect(),
        ),
        Ty::Map(t) => Ty::Map(Box::new(json_wire_type(&wire_present(t)))),
        Ty::Union(items) => Ty::union(items.iter().map(json_wire_type)),
        Ty::Instance(id, fields) => Ty::Instance(id.clone(), Box::new(json_wire_type(fields))),
        _ => t.clone(),
    }
}
