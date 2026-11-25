//! `storage.json`: what a build stores and how, and nothing else.
//!
//! Per kind and field: its stored type, its choices in order, its nested shape, whether it is
//! required, its SQL default, and where it lives (a table and column, a post type and meta key, a
//! user and meta key, or an option). Plus each SQL table's declared indexes, unique constraints and
//! foreign keys; indexes inferred from queries are left out, they follow the code. Access rules,
//! UI, hooks, actions, jobs and pages are left out: two builds with the same `storage.json` read
//! and write the same stored values the same way.
//!
//! Object keys are sorted (serde_json's `Value` map is ordered by insertion, so this module sorts
//! them itself); lists whose order means something (choices, an index's columns) keep it. The
//! deployment design compares two of these to decide whether a rollback crosses a storage change.

use crate::schema::{ColType, Collection, Column, DefaultKind, Schema};
use indexmap::IndexMap;
use serde_json::{Map, Value as Json, json};

pub const FORMAT: &str = "spacepress.storage.v1";
pub const FILE: &str = "storage.json";

/// The canonical bytes: sorted keys, no whitespace, a trailing newline.
pub fn bytes(schema: &Schema) -> Vec<u8> {
    let mut out = serde_json::to_vec(&sorted(describe(schema))).expect("storage serializes");
    out.push(b'\n');
    out
}

pub fn describe(schema: &Schema) -> Json {
    // `migrate::snapshot`, not the build's `snapshot_with`: without the columns compiled queries name,
    // so indexes the compiler infers from queries (`<field>_queried`) are not storage. They follow the
    // code, like the page read index, and code that changes only its queries stores the same.
    let snapshot = crate::migrate::snapshot(schema);
    let mut kinds = Map::new();
    for c in schema.collections.values() {
        kinds.insert(c.table.clone(), kind(c, &snapshot));
    }
    let mut globals = Map::new();
    for g in schema.globals.values() {
        let prefix = format!("sp_global_{}_", g.name);
        let fields = g
            .fields
            .iter()
            .map(|(name, col)| (name.clone(), field(col, json!({ "option": format!("{prefix}{name}") }))))
            .collect();
        globals.insert(g.name.clone(), json!({ "fields": Json::Object(fields) }));
    }
    let joins = snapshot["tables"]
        .as_object()
        .into_iter()
        .flatten()
        .filter(|(_, def)| def["join"].is_object())
        .map(|(key, def)| {
            (
                key.clone(),
                json!({ "table": format!("sp__join_{key}"), "owner": def["join"]["owner"], "target": def["join"]["target"],
                    "columns": ["owner_id", "target_id", "position"], "indexes": indexes(key, &snapshot) }),
            )
        })
        .collect::<Map<_, _>>();
    json!({ "format": FORMAT, "kinds": kinds, "globals": globals, "joins": joins })
}

fn kind(c: &Collection, snapshot: &Json) -> Json {
    if let Some(resource) = &c.commerce {
        let fields = c
            .columns
            .iter()
            .filter(|(_, col)| !col.read_only)
            .map(|(name, col)| (name.clone(), field(col, json!({ "woocommerce": name }))))
            .collect::<Map<_, _>>();
        return json!({ "in":"woocommerce", "resource":resource, "fields":fields });
    }
    let place = if c.auth {
        "users"
    } else if c.editor {
        "posts"
    } else {
        "table"
    };
    let table = c.existing_table.clone().unwrap_or_else(|| format!("sp_{}", c.table));
    let mut fields = Map::new();
    for (name, col) in &c.columns {
        // Computed on read, never stored.
        if col.derived {
            continue;
        }
        fields.insert(name.clone(), field(col, at(c, place, &table, name, col)));
    }
    let mut out = json!({ "in": place, "fields": fields });
    match place {
        "posts" => {
            out["postType"] = c.post_type().into();
            out["hierarchical"] = c.hierarchical.into();
            out["localized"] = c.localized.into();
        }
        "table" => {
            out["table"] = table.into();
            out["adopted"] = c.existing_table.is_some().into();
            out["localized"] = c.localized.into();
            out["indexes"] = indexes(&c.table, snapshot);
        }
        _ => {}
    }
    out
}

/// Where one top-level field's value lives.
fn at(c: &Collection, place: &str, table: &str, name: &str, col: &Column) -> Json {
    match place {
        "table" if col.ty == ColType::RefList => {
            json!({ "join": col.references.as_ref().and_then(|r| r.join.clone()) })
        }
        "table" => json!({ "table": table, "column": col.physical_name() }),
        "posts" => {
            let core = |field: &str| json!({ "post": field });
            if col.ty == ColType::RefList {
                json!({ "join": col.references.as_ref().and_then(|r| r.join.clone()) })
            } else if col.primary_key {
                core("ID")
            } else if c.title.as_deref() == Some(name) {
                core("post_title")
            } else if c.content.as_deref() == Some(name) {
                core("post_content")
            } else if c.excerpt.as_deref() == Some(name) {
                core("post_excerpt")
            } else if c.slug.as_deref() == Some(name) {
                core("post_name")
            } else if col.system && c.hierarchical && name == "parent" {
                core("post_parent")
            } else if col.system && c.hierarchical && name == "order" {
                core("menu_order")
            } else if col.system && name == "status" {
                core("post_status")
            } else if col.system && name == "publishedAt" {
                core("post_date_gmt")
            } else if col.system && name == "uid" {
                json!({ "meta": "_sp_uid" })
            } else if col.system && matches!(name, "locale" | "translationGroup") {
                json!({ "polylang": name })
            } else {
                json!({ "meta": format!("sp_{name}") })
            }
        }
        _ => {
            let core = |field: &str| json!({ "user": field });
            if col.primary_key {
                core("ID")
            } else if name == "email" {
                core("user_email")
            } else if name == "name" {
                core("display_name")
            } else if name == "role" {
                json!({ "meta": "sp_role" })
            } else if name == "guest" {
                json!({ "capabilities": "sp_guest" })
            } else if name == "passwordHash" {
                json!({ "identity": "password" })
            } else if name == "suspendedAt" {
                json!({ "identity": "status" })
            } else {
                json!({ "meta": format!("sp_{name}") })
            }
        }
    }
}

/// A field's stored shape: never its rules beyond what storage enforces, its UI or its access.
fn field(col: &Column, at: Json) -> Json {
    let mut out = shape(col);
    out["at"] = at;
    if col.primary_key {
        out["primaryKey"] = true.into();
    }
    if col.auto_increment {
        out["autoIncrement"] = true.into();
    }
    if col.unique {
        out["unique"] = true.into();
    }
    if let Some(r) = &col.references {
        out["references"] = json!({ "kind": r.collection, "column": r.column,
            "onDelete": r.on_delete.map(|a| a.sql()), "onUpdate": r.on_update.map(|a| a.sql()) });
    }
    if let Some(was) = &col.was {
        out["was"] = was.clone().into();
    }
    out
}

/// Type, required, choices, SQL default, the length storage keeps, and sub-fields.
fn shape(col: &Column) -> Json {
    let mut out = json!({ "type": col.ty.name(), "required": col.not_null });
    if let Some(choices) = &col.enum_values {
        out["choices"] = json!(choices);
    }
    if let Some(DefaultKind::Value(v)) = &col.default
        && !v.is_null()
    {
        out["default"] = v.clone();
    }
    if col.ty.is_stringy()
        && let Some(max) = col.rules.max
    {
        out["max"] = json!(max);
    }
    if let Some(bucket) = &col.bucket {
        out["bucket"] = bucket.clone().into();
    }
    if col.localized {
        out["localized"] = true.into();
    }
    if let Some(fields) = &col.fields {
        out["fields"] = sub(fields);
    }
    if let Some(layouts) = &col.layouts {
        out["layouts"] = layouts
            .iter()
            .map(|(name, fields)| (name.clone(), sub(fields)))
            .collect::<Map<_, _>>()
            .into();
    }
    out
}

fn sub(fields: &IndexMap<String, Column>) -> Json {
    fields
        .iter()
        .map(|(name, col)| (name.clone(), shape(col)))
        .collect::<Map<_, _>>()
        .into()
}

/// A SQL table's indexes and foreign keys, as the migrations create them.
fn indexes(key: &str, snapshot: &Json) -> Json {
    let report = crate::migrate::index_report(snapshot);
    Json::Array(
        report
            .as_array()
            .into_iter()
            .flatten()
            .filter(|ix| ix["table"] == key)
            .map(|ix| json!({ "name": ix["name"], "unique": ix["unique"], "columns": ix["columns"] }))
            .collect(),
    )
}

/// Every object's keys sorted, at every depth; arrays keep their order.
fn sorted(value: Json) -> Json {
    match value {
        Json::Object(map) => {
            let mut entries: Vec<_> = map.into_iter().collect();
            entries.sort_by(|a, b| a.0.cmp(&b.0));
            Json::Object(entries.into_iter().map(|(k, v)| (k, sorted(v))).collect())
        }
        Json::Array(items) => Json::Array(items.into_iter().map(sorted).collect()),
        other => other,
    }
}
