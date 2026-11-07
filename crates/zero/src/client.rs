//! The typed client (§7): `zero/client/types.ts` (generated interfaces),
//! `zero/client/schema.ts` (the `SCHEMA` descriptor the runtime reads), and
//! the handwritten runtime from `runtime/client/` (`index.ts`, `README.md`).

use crate::schema::{Access, ColType, Collection, Column, RelKind, Schema};
use crate::types::Ty;
use indexmap::IndexMap;
use std::collections::{HashMap, HashSet};
use std::fmt::Write as _;

/// "posts" → "Post", "shopping_list" → "ShoppingListItem"? No: singularize the last word only.
pub fn type_name(name: &str) -> String {
    let words: Vec<String> = name
        .split(['_', '-'])
        .filter(|w| !w.is_empty())
        .flat_map(split_camel)
        .collect();
    let n = words.len();
    let mut out = String::new();
    for (i, w) in words.iter().enumerate() {
        let w = if i + 1 == n { singular(w) } else { w.clone() };
        let mut c = w.chars();
        if let Some(f) = c.next() {
            out.extend(f.to_uppercase());
            out.push_str(c.as_str());
        }
    }
    if out.is_empty() { "Row".into() } else { out }
}

fn split_camel(w: &str) -> Vec<String> {
    let mut out = vec![String::new()];
    for c in w.chars() {
        if c.is_ascii_uppercase() && !out.last().expect("word").is_empty() {
            out.push(String::new());
        }
        out.last_mut().expect("word").push(c);
    }
    out
}

fn singular(w: &str) -> String {
    let lower = w.to_ascii_lowercase();
    if lower.ends_with("ies") && w.len() > 3 {
        format!("{}y", &w[..w.len() - 3])
    } else if lower.ends_with("sses") || lower.ends_with("xes") || lower.ends_with("ches") || lower.ends_with("shes") {
        w[..w.len() - 2].to_string()
    } else if lower.ends_with('s') && !lower.ends_with("ss") && w.len() > 1 {
        w[..w.len() - 1].to_string()
    } else {
        w.to_string()
    }
}

fn lit(s: &str) -> String {
    serde_json::to_string(s).expect("string")
}

/// A safe TS property key.
fn key(k: &str) -> String {
    let ident = k
        .chars()
        .next()
        .is_some_and(|c| c.is_ascii_alphabetic() || c == '_' || c == '$')
        && k.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$');
    if ident { k.to_string() } else { lit(k) }
}

/// Which shape a type describes: a row as read, an insert, or a patch (§4.3: in a patched group,
/// sub-fields the viewer can't read or can't set keep their stored values, so they may be omitted).
#[derive(Clone, Copy, PartialEq, Eq)]
enum Mode {
    Read,
    Insert,
    Patch,
}

impl From<bool> for Mode {
    fn from(insert: bool) -> Self {
        if insert { Mode::Insert } else { Mode::Read }
    }
}

/// The value type, before nullability. Sub-field objects follow the mode's optionality.
fn value_ts(c: &Column, mode: impl Into<Mode>) -> String {
    value_ts_with(c, mode, &crate::types::json_ty)
}

fn value_ts_with(c: &Column, mode: impl Into<Mode>, json_type: &dyn Fn(&Column) -> Ty) -> String {
    let insert = mode.into();
    let enum_union = |e: &Vec<String>| e.iter().map(|v| lit(v)).collect::<Vec<_>>().join(" | ");
    match c.ty {
        ColType::Integer | ColType::Real | ColType::Media | ColType::File => "number".into(),
        ColType::Boolean => "boolean".into(),
        ColType::Text => match &c.enum_values {
            Some(e) => enum_union(e),
            None => "string".into(),
        },
        ColType::Email | ColType::Url | ColType::Slug | ColType::RichText | ColType::Timestamp | ColType::Date => {
            "string".into()
        }
        ColType::Json => ty_ts(&json_type(c), &[]),
        ColType::MediaList => "number[]".into(),
        ColType::Select => format!(
            "({})[]",
            c.enum_values.as_ref().map(enum_union).unwrap_or("string".into())
        ),
        ColType::Group => fields_ts_with(c.fields.as_ref().expect("fields"), insert, json_type),
        ColType::Repeater => format!(
            "{}[]",
            fields_ts_with(c.fields.as_ref().expect("fields"), insert, json_type)
        ),
        ColType::Blocks => {
            let items: Vec<String> = c
                .layouts
                .as_ref()
                .expect("layouts")
                .iter()
                .map(|(name, fields)| {
                    let inner = fields_ts_with(fields, insert, json_type);
                    let body = inner
                        .strip_prefix("{ ")
                        .and_then(|s| s.strip_suffix(" }"))
                        .unwrap_or("");
                    if body.trim().is_empty() || inner == "{}" {
                        format!("{{ layout: {} }}", lit(name))
                    } else {
                        format!("{{ layout: {}; {} }}", lit(name), body.trim())
                    }
                })
                .collect();
            format!("({})[]", items.join(" | "))
        }
    }
}

fn col_ts(c: &Column, mode: impl Into<Mode>) -> String {
    col_ts_with(c, mode, &crate::types::json_ty)
}

fn col_ts_with(c: &Column, mode: impl Into<Mode>, json_type: &dyn Fn(&Column) -> Ty) -> String {
    let v = value_ts_with(c, mode, json_type);
    if c.not_null { v } else { format!("{v} | null") }
}

/// A read rule other than "anyone" can hide the column from a viewer, and hidden keys are removed
/// from rows (phase 2 §4.3), so read types make it optional.
fn may_hide(c: &Column) -> bool {
    c.access
        .get("read")
        .is_some_and(|r| !matches!(r, Access::Allow(true)) && !matches!(r, Access::Role(role) if role == "anyone"))
}

/// An update rule (or a read rule) can make the viewer's patch leave a sub-field out; so can one
/// anywhere below it, since the runtime then keeps the whole structured value (§4.3).
fn may_keep(c: &Column) -> bool {
    may_hide(c)
        || c.access.contains_key("update")
        || c.fields.as_ref().is_some_and(|f| f.values().any(may_keep))
        || c.layouts
            .as_ref()
            .is_some_and(|l| l.values().any(|f| f.values().any(may_keep)))
}

fn fields_ts(fields: &IndexMap<String, Column>, mode: impl Into<Mode>) -> String {
    fields_ts_with(fields, mode, &crate::types::json_ty)
}

fn fields_ts_with(
    fields: &IndexMap<String, Column>,
    mode: impl Into<Mode>,
    json_type: &dyn Fn(&Column) -> Ty,
) -> String {
    let insert = mode.into();
    if fields.is_empty() {
        return "{}".into();
    }
    let parts: Vec<String> = fields
        .values()
        .map(|f| {
            let optional = match insert {
                Mode::Insert => f.optional_on_insert(),
                Mode::Patch => f.optional_on_insert() || may_keep(f),
                Mode::Read => may_hide(f),
            };
            let opt = if optional { "?" } else { "" };
            format!("{}{opt}: {}", key(&f.name), col_ts_with(f, insert, json_type))
        })
        .collect();
    format!("{{ {} }}", parts.join("; "))
}

/// Which rows a filter value can compare against: the operators per column kind (§3.5).
fn filter_ts(c: &Column) -> String {
    let v = value_ts(c, false);
    if c.ty.is_json_kind() {
        return format!("{v} | null | {{ eq?: {v} | null; ne?: {v} | null; isNull?: true; isNotNull?: true }}");
    }
    if c.ty.is_list_kind() {
        let el = v
            .trim_end_matches("[]")
            .trim_start_matches('(')
            .trim_end_matches(')')
            .to_string();
        return format!("[] | null | {{ has?: {el}; eq?: []; isNull?: true; isNotNull?: true }}");
    }
    let cmp = match c.ty {
        ColType::Timestamp | ColType::Date => "string".to_string(),
        _ => v.clone(),
    };
    let strings = matches!(
        c.ty,
        ColType::Text | ColType::Email | ColType::Url | ColType::Slug | ColType::RichText
    );
    let like = if strings {
        "; like?: string; ilike?: string; notLike?: string; notIlike?: string"
    } else {
        ""
    };
    let list = if v.contains('|') {
        format!("({v})[]")
    } else {
        format!("{v}[]")
    };
    format!(
        "{v} | null | {{ eq?: {v} | null; ne?: {v} | null; gt?: {cmp}; gte?: {cmp}; lt?: {cmp}; lte?: {cmp}; in?: {list}; notIn?: {list}{like}; isNull?: true; isNotNull?: true }}"
    )
}

pub(crate) fn reserved_type_name(name: &str) -> bool {
    matches!(
        upper_first(name).as_str(),
        "Role"
            | "User"
            | "Media"
            | "PrivateFile"
            | "RevisionSummary"
            | "Status"
            | "Locale"
            | "Collections"
            | "Globals"
            | "Actions"
            | "Record"
            | "Partial"
            | "Pick"
            | "Omit"
            | "Extract"
            | "Exclude"
            | "PropertyKey"
            | "Promise"
            | "Error"
            | "Blob"
            | "URLSearchParams"
            | "FormData"
            | "Uint8Array"
            | "Headers"
            | "Request"
            | "RequestInit"
            | "Response"
            | "SchemaDescriptor"
            | "Descriptor"
            | "CollectionDescriptors"
            | "CollectionKey"
            | "Row"
            | "Rels"
            | "IdOf"
            | "Requested"
            | "NestedWith"
            | "RelationValue"
            | "WithValue"
            | "RowWith"
            | "OptionalKeys"
            | "ExactWith"
            | "StatusQuery"
            | "LocaleQuery"
            | "ListQuery"
            | "CollectionClient"
            | "MediaQuery"
            | "MediaClient"
            | "FileBucketClient"
            | "FilesClient"
            | "ActionsClient"
            | "GlobalsClient"
            | "Session"
            | "AuthClient"
            | "Client"
            | "ClientOptions"
            | "AuthOptions"
            | "Problem"
            | "ZeroError"
            | "Json"
            | "Send"
            | "Held"
            | "Issued"
            | "OidcTokens"
            | "BrowserGlobals"
    )
}

struct ClientNames {
    collections: IndexMap<String, String>,
    globals: IndexMap<String, String>,
    actions: IndexMap<String, String>,
}

fn claim_names(mut name: String, suffixes: &[&str], used: &mut HashSet<String>) -> String {
    while suffixes.iter().any(|suffix| {
        let candidate = format!("{name}{suffix}");
        reserved_type_name(&candidate) || used.contains(&candidate)
    }) {
        name.push_str("Row");
    }
    used.extend(suffixes.iter().map(|suffix| format!("{name}{suffix}")));
    name
}

fn names(schema: &Schema) -> ClientNames {
    let mut out = ClientNames {
        collections: IndexMap::new(),
        globals: IndexMap::new(),
        actions: IndexMap::new(),
    };
    let mut used = HashSet::new();
    for c in schema.collections.values() {
        let suffixes: &[&str] = if c.localized {
            &["", "Insert", "Patch", "With", "Filter", "Order", "Fields"]
        } else {
            &["", "Insert", "Patch", "With", "Filter", "Order"]
        };
        out.collections
            .insert(c.table.clone(), claim_names(type_name(&c.key), suffixes, &mut used));
    }
    for g in schema.globals.values() {
        out.globals.insert(
            g.name.clone(),
            claim_names(upper_first(&g.name), &["", "Patch"], &mut used),
        );
    }
    for a in schema.actions.values() {
        out.actions.insert(
            a.name.clone(),
            claim_names(action_type(&a.name), &["Input", "Output"], &mut used),
        );
    }
    out
}

/// A compiler type as TypeScript, for action results. Row-shaped results are named when they match.
fn ty_ts(t: &Ty, rows: &[(String, Ty)]) -> String {
    if let Some((name, _)) = rows.iter().find(|(_, r)| r == t) {
        return name.clone();
    }
    match t {
        Ty::Instance(_, fields) | Ty::Rest(fields) => ty_ts(fields, rows),
        Ty::Num | Ty::FreshNumLit(_) | Ty::Date => "number".into(),
        Ty::NumLit(value) => value.to_string(),
        Ty::Str => "string".into(),
        Ty::StrLit(s) | Ty::FreshStrLit(s) => serde_json::to_string(s).expect("string"),
        Ty::Bool | Ty::FreshBoolLit(_) => "boolean".into(),
        Ty::BoolLit(value) => value.to_string(),
        Ty::Null => "null".into(),
        Ty::Undefined => "undefined".into(),
        Ty::Void => "void".into(),
        Ty::Never => "never".into(),
        Ty::NonEmptyArray(el) => {
            let inner = ty_ts(el, rows);
            format!("[{inner}, ...({inner})[]]")
        }
        Ty::Array(el) => {
            let inner = ty_ts(el, rows);
            if inner.contains(' ') {
                format!("({inner})[]")
            } else {
                format!("{inner}[]")
            }
        }
        Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => {
            format!("[{}]", ts.iter().map(|t| ty_ts(t, rows)).collect::<Vec<_>>().join(", "))
        }
        Ty::Obj(f) if f.is_empty() => "{}".into(),
        Ty::Obj(f) => format!(
            "{{ {} }}",
            f.iter()
                .map(|(k, (t, o))| format!("{}{}: {}", key(k), if *o { "?" } else { "" }, ty_ts(t, rows)))
                .collect::<Vec<_>>()
                .join("; ")
        ),
        Ty::Map(v) => format!("Record<string, {}>", ty_ts(v, rows)),
        Ty::Union(ts) => ts.iter().map(|t| ty_ts(t, rows)).collect::<Vec<_>>().join(" | "),
        _ => "unknown".into(),
    }
}

pub fn types_ts(schema: &Schema, results: &HashMap<String, Ty>) -> String {
    let names = names(schema);
    let mut s = String::from(
        "// Generated by `zero build` from zero.config.ts. Do not edit.\n// Interfaces for the typed client (zero/client/index.ts).\n\n",
    );
    s.push_str("export type Role = \"guest\" | \"subscriber\" | \"author\" | \"editor\" | \"admin\";\n");
    match schema.collections.values().find(|c| c.auth) {
        Some(c) => {
            let _ = writeln!(
                s,
                "export interface User {{ id: number; name: string; role: Role; member?: {} | null }}",
                names.collections[&c.table]
            );
        }
        None => s.push_str("export interface User { id: number; name: string; role: Role }\n"),
    }
    s.push_str("export interface Media { id: number; url: string; mime: string; size: number; width: number | null; height: number | null; alt: string | null; filename: string; createdAt: string; sizes: Record<string, { url: string; width: number; height: number }>; focal: { x: number; y: number } | null }\n");
    s.push_str("export interface PrivateFile { id: number; bucket: string; filename: string; mime: string; size: number; ownerId: number | null; createdAt: string; url: string }\n");
    s.push_str(
        "export interface RevisionSummary { id: number; at: string; user: { id: number; name: string } | null }\n",
    );
    s.push_str("export type Status = \"draft\" | \"published\" | \"scheduled\";\n");
    let _ = writeln!(s, "export type Locale = {};\n", locale_ts(schema));

    for c in schema.collections.values() {
        collection_types(&mut s, c, &names.collections, schema);
    }

    for g in schema.globals.values() {
        let full = &names.globals[&g.name];
        let _ = writeln!(s, "export interface {full} {}", fields_ts(&g.fields, false));
        let patch: Vec<String> = g
            .fields
            .values()
            .map(|f| format!("{}?: {}", key(&f.name), col_ts(f, Mode::Patch)))
            .collect();
        let _ = writeln!(s, "export interface {full}Patch {{ {} }}\n", patch.join("; "));
    }

    let rows: Vec<(String, Ty)> = schema
        .collections
        .values()
        .map(|c| (names.collections[&c.table].clone(), crate::types::row_of(c)))
        .collect();
    for a in schema.actions.values() {
        let n = &names.actions[&a.name];
        let input: Vec<String> = a
            .input
            .values()
            .map(|f| {
                let opt = if f.optional_on_insert() { "?" } else { "" };
                format!("{}{opt}: {}", key(&f.name), col_ts(f, true))
            })
            .collect();
        if input.is_empty() {
            let _ = writeln!(s, "export type {n}Input = Record<string, never>;");
        } else {
            let _ = writeln!(s, "export interface {n}Input {{ {} }}", input.join("; "));
        }
        let out = results
            .get(&a.name)
            .map(|t| ty_ts(t, &rows))
            .unwrap_or_else(|| "unknown".into());
        let _ = writeln!(s, "export type {n}Output = {out};\n");
    }

    s.push_str("export interface Collections {\n");
    for c in schema.collections.values() {
        let n = &names.collections[&c.table];
        let _ = writeln!(
            s,
            "  {}: {{ row: {n}; insert: {n}Insert; patch: {n}Patch; with: {n}With; filter: {n}Filter; order: {n}Order }};",
            key(&c.key)
        );
    }
    s.push_str("}\n\nexport interface Globals {\n");
    for g in schema.globals.values() {
        let full = &names.globals[&g.name];
        let _ = writeln!(s, "  {}: {{ row: {full}; patch: {full}Patch }};", key(&g.name));
    }
    s.push_str("}\n\nexport interface Actions {\n");
    for a in schema.actions.values() {
        let n = &names.actions[&a.name];
        let _ = writeln!(s, "  {}: {{ input: {n}Input; output: {n}Output }};", key(&a.name));
    }
    s.push_str("}\n");
    s
}

/// The TS type of a collection's primary key (row ids in `translations` and `translationOf`).
fn pk_ts(c: &Collection) -> String {
    c.primary_key()
        .map(|p| value_ts(p, false))
        .unwrap_or_else(|| "number".into())
}

/// The configured locales as a TS union (`never` without `locales`).
fn locale_ts(schema: &Schema) -> String {
    let all: Vec<String> = schema
        .locales
        .as_ref()
        .and_then(|l| l["all"].as_array())
        .map(|a| a.iter().filter_map(|v| v.as_str()).map(lit).collect())
        .unwrap_or_default();
    if all.is_empty() {
        "never".into()
    } else {
        all.join(" | ")
    }
}

fn upper_first(s: &str) -> String {
    let mut c = s.chars();
    match c.next() {
        Some(f) => f.to_uppercase().collect::<String>() + c.as_str(),
        None => String::new(),
    }
}

fn action_type(name: &str) -> String {
    upper_first(name)
}

fn collection_types(s: &mut String, c: &Collection, names: &IndexMap<String, String>, schema: &Schema) {
    let n = &names[&c.table];
    // Hidden columns (auth's passwordHash, ...) never cross the API, so no client type names them.
    let columns = || c.columns.values().filter(|col| !col.hidden);
    let row: Vec<String> = columns()
        .map(|col| match col.name.as_str() {
            // §7.3: locale → id of every row in the translation group.
            "translations" if c.localized && col.system => {
                format!("translations: Partial<Record<{}, {}>>", locale_ts(schema), pk_ts(c))
            }
            _ => format!(
                "{}{}: {}",
                key(&col.name),
                if may_hide(col) { "?" } else { "" },
                col_ts(col, false)
            ),
        })
        .collect();
    let _ = writeln!(s, "export interface {n} {{ {} }}", row.join("; "));
    let mut insert: Vec<String> = columns()
        .filter(|col| !col.auto_increment && !col.derived)
        .map(|col| {
            // Slugs derive from their source when omitted.
            let opt = col.optional_on_insert() || col.ty == ColType::Slug && col.source.is_some();
            format!(
                "{}{}: {}",
                key(&col.name),
                if opt { "?" } else { "" },
                col_ts(col, true)
            )
        })
        .collect();
    if c.auth {
        // Write-only: accepted on create/update, never returned.
        insert.push("password?: string".into());
    }
    if c.localized {
        // §7.3: a new row, or a translation joining another row's group. A translation copies every
        // synced (non-localized) column from its group, so those may be omitted there.
        insert.push("translationOf?: undefined".into());
        let _ = writeln!(s, "export interface {n}Fields {{ {} }}", insert.join("; "));
        let synced: Vec<String> = columns()
            .filter(|col| !col.translated() && !col.system && !col.primary_key && Some(&col.name) != c.slug.as_ref())
            .map(|col| lit(&col.name))
            .collect();
        let synced = if synced.is_empty() {
            "never".to_string()
        } else {
            synced.join(" | ")
        };
        let _ = writeln!(
            s,
            "export type {n}Insert = {n}Fields | (Omit<{n}Fields, {synced} | \"translationOf\"> & Partial<Pick<{n}Fields, {synced}>> & {{ translationOf: {} }});",
            pk_ts(c)
        );
    } else {
        let _ = writeln!(s, "export interface {n}Insert {{ {} }}", insert.join("; "));
    }
    // `locale` is fixed per row and `translationOf` only applies on create, so neither is patchable.
    let mut patch: Vec<String> = columns()
        .filter(|col| !(col.auto_increment || col.derived || c.localized && col.system && col.name == "locale"))
        .map(|col| format!("{}?: {}", key(&col.name), col_ts(col, Mode::Patch)))
        .collect();
    if c.auth {
        patch.push("password?: string".into());
    }
    let _ = writeln!(s, "export interface {n}Patch {{ {} }}", patch.join("; "));

    // With: relations (true or nested options) and media columns (true).
    let mut with = Vec::new();
    for r in c.relations.values() {
        let t = &names[&r.collection];
        let _ = t;
        with.push(format!(
            "{}?: true | {{ where?: {tn}Filter; orderBy?: {tn}Order; limit?: number; offset?: number; with?: {tn}With }}",
            key(&r.name),
            tn = names[&r.collection]
        ));
    }
    for col in columns() {
        if matches!(col.ty, ColType::Media | ColType::MediaList | ColType::File) {
            with.push(format!("{}?: true", key(&col.name)));
        }
    }
    let _ = writeln!(s, "export interface {n}With {{ {} }}", with.join("; "));

    let mut filter: Vec<String> = columns()
        .filter(|col| !col.derived)
        .map(|col| format!("{}?: {}", key(&col.name), filter_ts(col)))
        .collect();
    for r in c.relations.values() {
        filter.push(format!("{}?: true | {}Filter", key(&r.name), names[&r.collection]));
    }
    filter.push(format!("AND?: {n}Filter[]"));
    filter.push(format!("OR?: {n}Filter[]"));
    filter.push(format!("NOT?: {n}Filter"));
    let _ = writeln!(s, "export interface {n}Filter {{ {} }}", filter.join("; "));

    let order: Vec<String> = columns()
        .filter(|col| !col.ty.is_json_kind() && !col.ty.is_list_kind())
        .map(|col| lit(&col.name))
        .collect();
    let _ = writeln!(
        s,
        "export type {n}Order = Partial<Record<{}, \"asc\" | \"desc\">> | string;",
        if order.is_empty() {
            "never".to_string()
        } else {
            order.join(" | ")
        }
    );
    let _ = schema;
    s.push('\n');
}

/// Column → its `showIf` filter; sub-field conditions nest under `fields` (and `layouts`).
fn show_if(columns: &IndexMap<String, Column>) -> serde_json::Value {
    let mut out = serde_json::Map::new();
    for c in columns.values() {
        let mut entry = serde_json::Map::new();
        if let Some(f) = &c.show_if {
            entry.insert("when".into(), f.clone());
        }
        if let Some(fs) = c
            .fields
            .as_ref()
            .map(show_if)
            .filter(|v| v.as_object().is_some_and(|o| !o.is_empty()))
        {
            entry.insert("fields".into(), fs);
        }
        let layouts: serde_json::Map<String, serde_json::Value> = c
            .layouts
            .iter()
            .flatten()
            .map(|(name, fs)| (name.clone(), show_if(fs)))
            .filter(|(_, v)| v.as_object().is_some_and(|o| !o.is_empty()))
            .collect();
        if !layouts.is_empty() {
            entry.insert("layouts".into(), layouts.into());
        }
        if !entry.is_empty() {
            out.insert(c.name.clone(), entry.into());
        }
    }
    out.into()
}

/// `SCHEMA`: collection → relations/media columns, plus globals and actions (§7).
pub fn schema_ts(schema: &Schema) -> String {
    let mut collections = serde_json::Map::new();
    for c in schema.collections.values() {
        let mut rels = serde_json::Map::new();
        for r in c.relations.values() {
            rels.insert(
                r.name.clone(),
                serde_json::json!({
                    "kind": if r.kind == RelKind::One { "one" } else { "many" },
                    "collection": schema.collections[&r.collection].key,
                    "optional": r.optional,
                }),
            );
        }
        let media: Vec<serde_json::Value> = c
            .columns
            .values()
            .filter(|col| matches!(col.ty, ColType::Media | ColType::MediaList))
            .map(|col| serde_json::json!(col.name))
            .collect();
        let media_multiple: Vec<serde_json::Value> = c
            .columns
            .values()
            .filter(|col| col.ty == ColType::MediaList)
            .map(|col| serde_json::json!(col.name))
            .collect();
        let files: Vec<serde_json::Value> = c
            .columns
            .values()
            .filter(|col| col.ty == ColType::File)
            .map(|col| serde_json::json!(col.name))
            .collect();
        let mut entry = serde_json::json!({
            "path": c.table,
            "primaryKey": c.primary_key().map(|p| p.name.clone()).unwrap_or_else(|| "id".into()),
            "drafts": c.drafts,
            "revisions": c.revisions,
            "localized": c.localized,
            "relations": rels,
            "media": media,
            "mediaList": media_multiple,
            "files": files,
        });
        // Editor conditions (§4.3), so a frontend form can mirror the editor; only when present.
        let conditions = show_if(&c.columns);
        if conditions.as_object().is_some_and(|o| !o.is_empty()) {
            entry["showIf"] = conditions;
        }
        collections.insert(c.key.clone(), entry);
    }
    let globals: Vec<serde_json::Value> = schema.globals.keys().map(|g| serde_json::json!(g)).collect();
    let actions: Vec<serde_json::Value> = schema.actions.keys().map(|a| serde_json::json!(a)).collect();
    let descriptor = serde_json::json!({
        "name": schema.name,
        "collections": collections,
        "globals": globals,
        "actions": actions,
        "buckets": schema.files.keys().collect::<Vec<_>>(),
    });
    format!(
        "// Generated by `zero build` from zero.config.ts. Do not edit.\n// What the client runtime needs to know about this app (no types, no function names).\n\nexport const SCHEMA = {} as const;\n\nexport type SchemaDescriptor = typeof SCHEMA;\n",
        serde_json::to_string_pretty(&descriptor).expect("json")
    )
}

/// Every file under `zero/client/`. `runtime` looks up embedded `runtime/` files by path.
pub fn files(
    schema: &Schema,
    results: &HashMap<String, Ty>,
    runtime: impl Fn(&str) -> Option<&'static [u8]>,
) -> Vec<(String, Vec<u8>)> {
    let mut out = vec![
        ("types.ts".to_string(), types_ts(schema, results).into_bytes()),
        ("schema.ts".to_string(), schema_ts(schema).into_bytes()),
    ];
    #[cfg(feature = "host")]
    if let Some(bytes) = runtime("client/index.ts") {
        let runtime = String::from_utf8_lossy(bytes);
        let body = runtime
            .lines()
            .filter(|line| {
                !line.starts_with("import ") && !line.starts_with("export type *") && *line != "export { SCHEMA };"
            })
            .collect::<Vec<_>>()
            .join("\n");
        let source = format!("{}\n{}\n{}", types_ts(schema, results), schema_ts(schema), body);
        let (javascript, declarations) = browser_artifacts(&source);
        out.push(("client.js".into(), javascript.into_bytes()));
        out.push(("client.d.ts".into(), declarations.into_bytes()));
    }
    // The copied runtime half of the client is MIT; its notice travels with it.
    for f in ["index.ts", "README.md", "LICENSE"] {
        if let Some(bytes) = runtime(&format!("client/{f}")) {
            let text = String::from_utf8_lossy(bytes).replace("{{name}}", &schema.name);
            out.push((f.to_string(), text.into_bytes()));
        }
    }
    out
}

/// The value type used by the backend registry, independently of config exports.
pub(crate) fn registry_value(c: &Column) -> String {
    value_ts_with(c, false, &crate::types::json_authored_ty)
}

/// Emit a dependency-free browser module and matching declarations from the canonical client.
/// This strips client TypeScript only; compiler input bundles retain their original sources.
#[cfg(feature = "host")]
fn browser_artifacts(source: &str) -> (String, String) {
    use oxc_allocator::Allocator;
    use oxc_codegen::Codegen;
    use oxc_isolated_declarations::{IsolatedDeclarations, IsolatedDeclarationsOptions};
    use oxc_parser::Parser;
    use oxc_semantic::SemanticBuilder;
    use oxc_span::SourceType;
    use oxc_transformer::{TransformOptions, Transformer};
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, SourceType::ts()).parse();
    assert!(
        parsed.diagnostics.is_empty(),
        "invalid generated client: {:?}",
        parsed.diagnostics
    );
    let mut program = parsed.program;
    let declarations =
        IsolatedDeclarations::new(&allocator, IsolatedDeclarationsOptions { strip_internal: true }).build(&program);
    assert!(
        declarations.diagnostics.is_empty(),
        "invalid generated declarations: {:?}",
        declarations.diagnostics
    );
    let declarations = Codegen::new().build(&declarations.program).code;
    let semantic = SemanticBuilder::new().with_excess_capacity(2.0).build(&program);
    assert!(
        semantic.diagnostics.is_empty(),
        "invalid client semantics: {:?}",
        semantic.diagnostics
    );
    let transformed = Transformer::new(
        &allocator,
        std::path::Path::new("client.ts"),
        &TransformOptions::default(),
    )
    .build_with_scoping(semantic.semantic.into_scoping(), &mut program);
    assert!(
        transformed.diagnostics.is_empty(),
        "invalid browser client: {:?}",
        transformed.diagnostics
    );
    (Codegen::new().build(&program).code, declarations)
}
