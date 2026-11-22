//! Schema snapshots and migration planning (MySQL dialect; the SQLite Database
//! Integration plugin translates it on SQLite sites).
//!
//! Each build compares the evaluated schema with the last snapshot
//! (`tp/_generated/migrations/snapshot.json`, committed with the project) and
//! writes the next numbered migration beside it.
//!
//! Only `table()` definitions get a table, `{prefix}tp_<table>`, plus
//! the `{prefix}tp__revisions` system table. Post-backed collections live in
//! `wp_posts`/`wp_postmeta` and get no table. Changes are `ALTER TABLE`
//! (add/modify/drop), never rebuilds. Foreign keys exist only when both sides
//! are custom tables (§4.2). `{prefix}` is the WordPress table prefix,
//! substituted by `Wp\Migrator`.

use crate::schema::{Collection, Column, DefaultKind, Schema};
use serde_json::{Value as Json, json};

pub const BREAKPOINT: &str = "--> statement-breakpoint";
/// First line of a statement the deploy checks against live rows before running any of the
/// migration (`Wp\Migrator::check`): `-- typedpress-check {json}`. Spec §2.9.
pub const CHECK: &str = "-- typedpress-check ";
/// Snapshot key of the revisions system table.
const REVISIONS: &str = "tp__revisions";

fn q(ident: &str) -> String {
    format!("`{}`", ident.replace('`', "``"))
}

fn table(t: &str, tables: &Map) -> String {
    if let Some(name) = tables.get(t).and_then(|d| d["existingTable"].as_str()) {
        return q(name);
    }
    if t == REVISIONS {
        format!("`{{prefix}}{t}`")
    } else {
        format!("`{{prefix}}tp_{t}`")
    }
}

/// The physical table name, unquoted, as the deploy check counts it.
fn physical(t: &str, tables: &Map) -> String {
    table(t, tables).trim_matches('`').replace("``", "`")
}

/// Whether Zero didn't create this table (`existingTable`, e.g. adopted from Zero 0.8). Such a table
/// is never dropped, and no column of it is dropped, renamed or retyped, without an explicit
/// `--drop` naming it, even when empty. Columns and indexes may still be added.
fn adopted(def: &Json) -> bool {
    def["existingTable"].is_string()
}

fn checked(check: Json, sql: String) -> String {
    format!("{CHECK}{check}\n{sql}")
}

fn string(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "''"))
}

/// The persisted shape of the schema: only what affects SQL.
pub fn snapshot(schema: &Schema) -> Json {
    snapshot_with(schema, &std::collections::BTreeSet::new())
}

/// The snapshot with `queried`, the `(table, column)` pairs compiled `where`/`orderBy` name, as each
/// SQL table's `queried` list: the columns that get an inferred index (spec §2.9).
pub fn snapshot_with(schema: &Schema, queried: &std::collections::BTreeSet<(String, String)>) -> Json {
    let mut tables = serde_json::Map::new();
    // A taxonomy lives in core's term tables: nothing about it enters the snapshot (ruling 34).
    for c in schema
        .collections
        .values()
        .filter(|c| c.taxonomy.is_none() && c.commerce.is_none())
    {
        let mut t = table_snapshot(c, schema);
        let cols: Vec<String> = queried
            .iter()
            .filter(|(table, col)| table == &c.table && c.columns.contains_key(col))
            .map(|(_, col)| col.clone())
            .collect();
        if !cols.is_empty() {
            t["queried"] = json!(cols);
        }
        if !c.indexes.is_empty() {
            t["indexes"] = c
                .indexes
                .iter()
                .map(|(fields, unique)| json!({ "columns": fields, "unique": unique }))
                .collect();
        }
        tables.insert(c.table.clone(), t);
    }
    for c in schema.collections.values().filter(|c| c.taxonomy.is_none()) {
        for col in c.columns.values() {
            if let Some(r) = &col.references
                && let Some(join) = &r.join
                && !crate::schema::is_term_join(join)
            {
                tables.insert(join.clone(), join_snapshot(c, r, schema));
            }
        }
    }
    json!({ "version": 2, "tables": tables })
}

/// A column a plain MySQL index can cover: not LONGTEXT (that needs a prefix, which isn't a lookup
/// index) and not a list or JSON value.
fn indexable(c: &Json) -> bool {
    let max = c["max"].as_f64();
    match c["type"].as_str().unwrap_or("text") {
        "integer" | "media" | "file" | "real" | "boolean" | "timestamp" | "date" | "slug" => true,
        "text" | "email" | "url" => {
            c["unique"].as_bool() == Some(true) || max.is_some_and(|m| m <= 191.0) || c["enum"].is_array()
        }
        _ => false,
    }
}

/// A `ref().many()` field's join table (docs/decisions/2026-11-11-authoring-surface.md, ruling 3):
/// one row per link, `(owner_id, target_id)` the primary key, `position` the 0-based write order.
/// Each id references its side's table, so when both sides are custom tables the foreign keys cascade
/// a delete; with a post-backed side the pipeline deletes the links.
fn join_snapshot(owner: &Collection, r: &crate::schema::Reference, schema: &Schema) -> Json {
    let side = |table: &str| {
        json!({ "type": "integer", "notNull": true, "primaryKey": false, "autoIncrement": false, "unique": false,
            "max": null, "default": null,
            "references": { "table": table, "column": "id", "onDelete": "cascade", "onUpdate": null,
                "unsigned": schema.collections[table].columns.get("id").is_some_and(|c| c.auto_increment) } })
    };
    let mut position = side(&owner.table);
    position["references"] = Json::Null;
    json!({
        "editor": false,
        "drafts": false,
        "join": { "owner": owner.table, "target": r.collection },
        "columns": { "owner_id": side(&owner.table), "target_id": side(&r.collection), "position": position },
    })
}

fn table_snapshot(c: &Collection, schema: &Schema) -> Json {
    let mut cols = serde_json::Map::new();
    // Derived columns (a localized collection's `translations`) are computed on read, never stored.
    for col in c
        .columns
        .values()
        .filter(|col| !(col.derived || col.system && col.name == "translationGroup"))
        .filter(|col| col.ty != crate::schema::ColType::RefList)
    {
        cols.insert(col.name.clone(), column_snapshot(col, schema));
    }
    let mut table = json!({ "editor": c.editor, "drafts": c.drafts, "columns": cols });
    if let Some(name) = &c.existing_table {
        table["existingTable"] = name.clone().into();
    }
    if let Some(old) = &c.was {
        table["was"] = old.clone().into();
    }
    // Only when it isn't the stored name: without `adoptedAs` the table was adopted under that.
    if let Some(name) = c.adopted_as.as_ref().filter(|name| **name != c.table) {
        table["adoptedAs"] = name.clone().into();
    }
    if c.auth {
        // WordPress keeps auth rows in wp_users/wp_usermeta (§6.2), so MySQL gets no table.
        table["auth"] = true.into();
    }
    if c.localized {
        // §7.3: a SQL table links a translation group with this column (post-backed
        // groups live in Polylang, not in this column).
        let mut group = crate::schema::Column::new("translationGroup", crate::schema::ColType::Text);
        group.system = true;
        let mut snap = column_snapshot(&group, schema);
        snap["max"] = json!(36); // a UUID; MySQL needs a bounded VARCHAR for the unique pair
        cols_insert(&mut table, "translationGroup", snap);
        table["localized"] = json!(true);
    }
    table
}

fn column_snapshot(col: &Column, schema: &Schema) -> Json {
    let references = col.references.as_ref().map(|r| {
        let target = &schema.collections[&r.collection];
        let unsigned = target.columns.get(&r.column).is_some_and(|t| t.auto_increment);
        json!({
            "table": r.collection,
            "column": r.column,
            "onDelete": r.on_delete.map(|a| a.sql()),
            "onUpdate": r.on_update.map(|a| a.sql()),
            "unsigned": unsigned,
        })
    });
    let mut snap = json!({
        "type": col.ty.name(),
        "notNull": col.not_null,
        "primaryKey": col.primary_key,
        "autoIncrement": col.auto_increment,
        "unique": col.unique,
        "max": col.rules.max.filter(|_| col.ty.is_stringy()),
        "enum": col.enum_values.as_ref().filter(|_| col.ty == crate::schema::ColType::Text),
        "default": sql_default(col),
        "references": references,
    });
    if let Some(old) = &col.was {
        snap["was"] = old.clone().into();
    }
    snap
}

/// Constant defaults are mirrored in SQL so rows written outside the runtime (and
/// ADD COLUMN on existing rows) get them too. Stored as JSON, rendered as literals.
fn sql_default(col: &Column) -> Json {
    match &col.default {
        Some(DefaultKind::Value(v)) if !v.is_null() => v.clone(),
        _ => Json::Null,
    }
}

fn cols_insert(table: &mut Json, name: &str, col: Json) {
    if let Some(cols) = table["columns"].as_object_mut() {
        cols.insert(name.into(), col);
    }
}

type Map = serde_json::Map<String, Json>;

/// The tables a snapshot holds: custom tables, plus the revisions table once there is any collection.
fn tables(snap: &Json) -> Map {
    let mut out = Map::new();
    let empty = Map::new();
    let tables = snap["tables"].as_object().unwrap_or(&empty);
    for (t, def) in tables {
        if def["editor"].as_bool() != Some(false) || def["auth"].as_bool() == Some(true) {
            continue;
        }
        out.insert(t.clone(), def.clone());
    }
    if !tables.is_empty() {
        let mut cols = Map::new();
        let c = |ty: &str, not_null: bool| json!({ "type": ty, "notNull": not_null, "primaryKey": false, "autoIncrement": false, "unique": false, "max": null, "default": null, "references": null });
        cols.insert(
            "id".into(),
            json!({ "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null }),
        );
        let mut collection = c("text", true);
        collection["max"] = json!(64);
        cols.insert("collection".into(), collection);
        let mut pk = c("text", true);
        pk["max"] = json!(64);
        cols.insert("pk".into(), pk);
        cols.insert("at".into(), c("timestamp", true));
        cols.insert("userId".into(), c("integer", false));
        cols.insert("row".into(), c("json", true));
        out.insert(
            REVISIONS.into(),
            json!({ "editor": false, "drafts": false, "system": true, "columns": cols }),
        );
    }
    out
}

fn unsigned_integer(c: &Json) -> bool {
    (c["primaryKey"] == true && c["autoIncrement"] == true) || c["references"]["unsigned"] == true
}

/// A custom table's column as DDL (foreign keys are rendered separately, as constraints).
fn column_sql(name: &str, c: &Json) -> String {
    let ty = c["type"].as_str().unwrap_or("text");
    let max = c["max"].as_f64();
    let unique = c["unique"].as_bool() == Some(true);
    let pk = c["primaryKey"].as_bool() == Some(true);
    let auto = c["autoIncrement"].as_bool() == Some(true);
    let not_null = c["notNull"].as_bool() == Some(true) || pk;
    let sql_ty = match ty {
        "integer" | "media" | "file" if unsigned_integer(c) => "BIGINT UNSIGNED".to_string(),
        "integer" | "media" | "file" => "BIGINT".into(),
        "real" => "DOUBLE".into(),
        "boolean" => "TINYINT(1)".into(),
        "timestamp" => "CHAR(24)".into(),
        "date" => "CHAR(10)".into(),
        "slug" => format!("VARCHAR({})", max.map_or(200, |m| m.min(200.0) as u32)),
        "text" | "email" | "url" if unique || max.is_some_and(|m| m <= 191.0) => {
            format!("VARCHAR({})", max.map_or(191, |m| m as u32))
        }
        "text" if c["enum"].is_array() => "VARCHAR(191)".into(),
        _ => "LONGTEXT".into(),
    };
    let mut sql = format!("{} {sql_ty}", q(name));
    if pk {
        sql.push_str(if auto { " NOT NULL AUTO_INCREMENT" } else { " NOT NULL" });
    }
    if !c["default"].is_null() {
        let lit = default_literal(ty, &c["default"]);
        // MySQL only accepts literal defaults on TEXT columns as expressions.
        sql.push_str(&if sql_ty == "LONGTEXT" {
            format!(" DEFAULT ({lit})")
        } else {
            format!(" DEFAULT {lit}")
        });
    }
    if not_null && !pk {
        sql.push_str(" NOT NULL");
    }
    sql
}

/// Foreign key constraint for a column, when both sides are custom tables.
fn fk_sql(t: &str, name: &str, c: &Json, tables: &Map) -> Option<String> {
    let r = c["references"].as_object()?;
    let target = r["table"].as_str()?;
    if !tables.contains_key(target) {
        return None; // post-backed target: the pipeline checks existence and emulates the action
    }
    // A join table's links get constraints only when both sides are custom tables; otherwise the
    // pipeline deletes them with either side.
    if let Some(join) = tables.get(t).and_then(|d| d["join"].as_object())
        && !["owner", "target"]
            .iter()
            .all(|k| join[*k].as_str().is_some_and(|s| tables.contains_key(s)))
    {
        return None;
    }
    let mut sql = format!(
        "CONSTRAINT `{}` FOREIGN KEY ({}) REFERENCES {} ({})",
        fk_name(t, name),
        q(name),
        table(target, tables),
        q(r["column"].as_str().unwrap_or("id"))
    );
    if let Some(a) = r["onDelete"].as_str() {
        sql.push_str(&format!(" ON DELETE {}", a.to_uppercase()));
    }
    if let Some(a) = r["onUpdate"].as_str() {
        sql.push_str(&format!(" ON UPDATE {}", a.to_uppercase()));
    }
    Some(sql)
}

fn fk_name(t: &str, col: &str) -> String {
    // MySQL identifiers are limited to 64 characters.
    let n = format!("tp_{t}_{col}_fk");
    n.chars().take(64).collect()
}

/// Every index the schema's SQL tables get, and why it exists, for `typedpress check --json`
/// (spec §2.9). Post-backed kinds have none of their own: WordPress indexes `wp_posts` and its meta.
pub fn index_report(snapshot: &Json) -> Json {
    let tables = tables(snapshot);
    let mut out = Vec::new();
    for (t, def) in &tables {
        if def["system"].as_bool() == Some(true) {
            continue;
        }
        let empty = Map::new();
        let cols = def["columns"].as_object().unwrap_or(&empty);
        if let Some((pk, _)) = cols.iter().find(|(_, c)| c["primaryKey"].as_bool() == Some(true)) {
            out.push(
                json!({ "table": t, "name": "PRIMARY", "unique": true, "columns": [pk], "reason": "primary key" }),
            );
        }
        for (name, unique, ix_cols) in indexes(t, def) {
            let declared = def["indexes"].as_array().into_iter().flatten().any(|ix| {
                ix["columns"]
                    .as_array()
                    .is_some_and(|c| c.iter().filter_map(Json::as_str).eq(ix_cols.iter().map(String::as_str)))
                    && (ix["unique"].as_bool() == Some(true)) == unique
            });
            let reason = if declared {
                format!(
                    "declared with .index([{}]{})",
                    ix_cols
                        .iter()
                        .map(|c| format!("\"{c}\""))
                        .collect::<Vec<_>>()
                        .join(", "),
                    if unique { ", { unique: true }" } else { "" }
                )
            } else if name.ends_with("_unique") {
                format!("`{}` is .unique()", ix_cols[0])
            } else if name == "status_publishedAt" {
                "drafts: published rows are filtered by status and ordered by publishedAt".to_string()
            } else if name == "translationGroup_locale" {
                "localized: one row per locale in a translation group".to_string()
            } else if name.ends_with("_queried") {
                format!("`{}` is in a compiled `where` or `orderBy`", ix_cols[0])
            } else {
                "a system table's lookup".to_string()
            };
            out.push(json!({ "table": t, "name": name, "unique": unique, "columns": ix_cols, "reason": reason }));
        }
        for (name, c) in cols {
            if fk_sql(t, name, c, &tables).is_some() {
                let target = c["references"]["table"].as_str().unwrap_or_default();
                out.push(
                    json!({ "table": t, "name": fk_name(t, name), "unique": false, "columns": [name],
                    "reason": format!("`{name}` references {target}: MySQL indexes a foreign key") }),
                );
            }
        }
    }
    Json::Array(out)
}

/// (index name, unique, columns) for a table.
fn indexes(t: &str, def: &Json) -> Vec<(String, bool, Vec<String>)> {
    let mut out = Vec::new();
    let empty = Map::new();
    let cols = def["columns"].as_object().unwrap_or(&empty);
    for (name, c) in cols {
        if c["unique"].as_bool() == Some(true) && c["primaryKey"].as_bool() != Some(true) {
            out.push((format!("{name}_unique"), true, vec![name.clone()]));
        }
    }
    if def["drafts"].as_bool() == Some(true) && cols.contains_key("status") && cols.contains_key("publishedAt") {
        out.push((
            "status_publishedAt".to_string(),
            false,
            vec!["status".into(), "publishedAt".into()],
        ));
    }
    // §7.3: one row per locale in a translation group.
    if def["localized"].as_bool() == Some(true) && cols.contains_key("translationGroup") && cols.contains_key("locale")
    {
        out.push((
            "translationGroup_locale".to_string(),
            true,
            vec!["translationGroup".into(), "locale".into()],
        ));
    }
    if def["join"].is_object() {
        out.push(("target_id".into(), false, vec!["target_id".into()]));
    }
    if def["system"].as_bool() == Some(true) && t == REVISIONS {
        out.push(("row".into(), false, vec!["collection".into(), "pk".into()]));
    }
    // `.index(fields, { unique? })`: compound indexes the author declared, named by their columns.
    for ix in def["indexes"].as_array().into_iter().flatten() {
        let fields: Vec<String> = ix["columns"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|c| c.as_str().map(str::to_owned))
            .collect();
        if !fields.is_empty() && fields.iter().all(|f| cols.get(f).is_some_and(indexable)) {
            let unique = ix["unique"].as_bool() == Some(true);
            let name = format!("idx_{}", fields.join("_"));
            if !out.iter().any(|(n, _, _)| n == &name) {
                out.push((name, unique, fields));
            }
        }
    }
    // A column a compiled `where` or `orderBy` names (spec §2.9), unless an index already starts with
    // it: the primary key, `.unique()`, a reference (MySQL indexes a foreign key), or one above.
    let pk = cols
        .iter()
        .find(|(_, c)| c["primaryKey"].as_bool() == Some(true))
        .map(|(n, _)| n.clone());
    let referenced = |n: &str| cols.get(n).is_some_and(|c| c["references"].is_object());
    // A table TypedPress didn't create (`existingTable`) keeps the indexes it had: adoption checks them.
    let queried = if adopted(def) { None } else { def["queried"].as_array() };
    for col in queried.into_iter().flatten().filter_map(Json::as_str) {
        let Some(c) = cols.get(col) else { continue };
        let covered = pk.as_deref() == Some(col)
            || referenced(col)
            || out.iter().any(|(_, _, ix)| ix.first().map(String::as_str) == Some(col));
        if !covered && indexable(c) {
            out.push((format!("{col}_queried"), false, vec![col.to_string()]));
        }
    }
    out
}

fn create_index(t: &str, (name, unique, cols): &(String, bool, Vec<String>), tables: &Map) -> String {
    let cols: Vec<String> = cols.iter().map(|c| q(c)).collect();
    format!(
        "ALTER TABLE {} ADD {}{} {} ({});",
        table(t, tables),
        if *unique { "UNIQUE " } else { "" },
        if *unique { "KEY" } else { "INDEX" },
        q(name),
        cols.join(", ")
    )
}

fn drop_index(t: &str, name: &str, tables: &Map) -> String {
    format!("ALTER TABLE {} DROP INDEX {};", table(t, tables), q(name))
}

fn create_table(t: &str, def: &Json, tables: &Map) -> String {
    let empty = Map::new();
    let cols = def["columns"].as_object().unwrap_or(&empty);
    if def["existingTable"].is_string() {
        let integer_columns: Vec<Json> = cols
            .iter()
            .filter(|(_, c)| matches!(c["type"].as_str(), Some("integer" | "media" | "file")))
            .map(|(name, c)| json!({ "name": name, "unsigned": unsigned_integer(c) }))
            .collect();
        let mut expected_indexes: Vec<Json> = indexes(t, def)
            .into_iter()
            .map(|(name, unique, columns)| json!({ "name": name, "unique": unique, "columns": columns }))
            .collect();
        if let Some((pk, _)) = cols.iter().find(|(_, c)| c["primaryKey"] == true) {
            expected_indexes.push(json!({ "name": "PRIMARY", "unique": true, "columns": [pk] }));
        }
        let foreign_keys: Vec<Json> = cols
            .iter()
            .filter_map(|(name, c)| {
                fk_sql(t, name, c, tables)?;
                let r = &c["references"];
                let target = r["table"].as_str()?;
                let target_name = tables[target]["existingTable"]
                    .as_str()
                    .map(str::to_owned)
                    .unwrap_or_else(|| format!("{{prefix}}tp_{target}"));
                Some(json!({ "name": fk_name(t, name), "column": name, "table": target_name,
                "target": r["column"], "onDelete": r["onDelete"].as_str().unwrap_or("restrict").to_uppercase(),
                "onUpdate": r["onUpdate"].as_str().unwrap_or("restrict").to_uppercase() }))
            })
            .collect();
        // `name`, the name it is adopted under, lets the runtime check this record on its own, even
        // once a later migration drops the table's collection (a Zero 0.8 table's digest is over it).
        let name = def["adoptedAs"].as_str().unwrap_or(t);
        let adoption = json!({ "table": def["existingTable"], "name": name, "indexes": expected_indexes,
            "foreignKeys": foreign_keys, "integerColumns": integer_columns });
        return format!(
            "-- typedpress-adopt {adoption}\nSELECT {} FROM {} LIMIT 0;",
            cols.keys().map(|n| q(n)).collect::<Vec<_>>().join(", "),
            table(t, tables)
        );
    }
    let mut body: Vec<String> = cols.iter().map(|(n, c)| column_sql(n, c)).collect();
    if let Some((pk, _)) = cols.iter().find(|(_, c)| c["primaryKey"].as_bool() == Some(true)) {
        body.push(format!("PRIMARY KEY ({})", q(pk)));
    }
    if def["join"].is_object() {
        body.push("PRIMARY KEY (`owner_id`, `target_id`)".into());
    }
    for (name, unique, ix_cols) in indexes(t, def) {
        let cols: Vec<String> = ix_cols.iter().map(|c| q(c)).collect();
        body.push(format!(
            "{}KEY {} ({})",
            if unique { "UNIQUE " } else { "" },
            q(&name),
            cols.join(", ")
        ));
    }
    for (n, c) in cols {
        if let Some(fk) = fk_sql(t, n, c, tables) {
            body.push(fk);
        }
    }
    format!(
        "CREATE TABLE {} (\n\t{}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        table(t, tables),
        body.join(",\n\t")
    )
}

/// Renames `next` declares against `prev` (`was` on a table, `.was()` on a field). A rename applies
/// when the old name is stored, the new one isn't, and `next` doesn't keep the old name too.
#[derive(Default)]
struct Renames {
    /// (new, old) table names.
    tables: Vec<(String, String)>,
    /// (table as named in `next`, new column, old column).
    columns: Vec<(String, String, String)>,
}

impl Renames {
    fn of(prev: &Json, next: &Json) -> Renames {
        let empty = Map::new();
        let prev_t = prev["tables"].as_object().unwrap_or(&empty);
        let next_t = next["tables"].as_object().unwrap_or(&empty);
        let mut out = Renames::default();
        for (t, def) in next_t {
            let stored = match def["was"].as_str() {
                Some(old) if !prev_t.contains_key(t) && prev_t.contains_key(old) && !next_t.contains_key(old) => {
                    out.tables.push((t.clone(), old.to_string()));
                    old
                }
                _ => t.as_str(),
            };
            let Some(prev_cols) = prev_t.get(stored).and_then(|d| d["columns"].as_object()) else {
                continue;
            };
            let cols = def["columns"].as_object().unwrap_or(&empty);
            for (c, col) in cols {
                if let Some(old) = col["was"].as_str()
                    && !prev_cols.contains_key(c)
                    && prev_cols.contains_key(old)
                    && !cols.contains_key(old)
                {
                    out.columns.push((t.clone(), c.clone(), old.to_string()));
                }
            }
        }
        out
    }

    /// `prev` with every rename applied, so the rest of the diff sees one name per table and column.
    fn apply(&self, prev: &Json) -> Json {
        let mut out = prev.clone();
        let Some(tables) = out["tables"].as_object_mut() else {
            return out;
        };
        for (new, old) in &self.tables {
            if let Some(def) = tables.shift_remove(old) {
                tables.insert(new.clone(), def);
            }
        }
        for (t, new, old) in &self.columns {
            if let Some(cols) = tables.get_mut(t).and_then(|d| d["columns"].as_object_mut())
                && let Some(col) = cols.shift_remove(old)
            {
                cols.insert(new.clone(), col);
            }
        }
        out
    }

    fn stored_table<'a>(&'a self, t: &'a str) -> &'a str {
        self.tables.iter().find(|(new, _)| new == t).map_or(t, |(_, old)| old)
    }

    fn stored_column<'a>(&'a self, t: &str, c: &'a str) -> &'a str {
        self.columns
            .iter()
            .find(|(table, new, _)| table == t && new == c)
            .map_or(c, |(_, _, old)| old)
    }
}

/// For a column whose stored type changes from `before` to `after`, the SQL condition (over `{col}`)
/// a value must meet to survive the change, or None when every value of `before` fits `after`.
/// `"false"` means no value survives: any non-null value refuses.
///
/// Text is always compared as text, byte for byte: MySQL compares a string with a number as a number
/// (`'yes' IN (0, 1)` holds, `'yes'` is 0), and the column's accent-insensitive collation equates
/// `'１'` (fullwidth) or `'١'` (Arabic-Indic) with `'1'`. Patterns match bytes for the same reason.
/// Integers are checked against their type's range as well as their shape, since out-of-range text
/// is clamped. No condition may overflow: a count that errors refuses (SchemaCheck::count), and a
/// condition that can't be computed for some row must not read as passing.
fn fits(before: &Json, after: &Json) -> Option<String> {
    let render = |c: &Json| column_sql("{col}", c);
    let ty = |c: &Json| -> &'static str {
        let sql = render(c);
        let sql = sql.strip_prefix("`{col}` ").unwrap_or(&sql);
        match sql.split(['(', ' ']).next().unwrap_or("") {
            "BIGINT" if sql.starts_with("BIGINT UNSIGNED") => "unsigned",
            "BIGINT" => "bigint",
            "DOUBLE" => "double",
            "TINYINT" => "boolean",
            "CHAR" | "VARCHAR" | "LONGTEXT" => "text",
            _ => "other",
        }
    };
    let width = |c: &Json| -> Option<u32> {
        let sql = render(c);
        let open = sql.find('(')?;
        sql[open + 1..].split(')').next()?.parse().ok()
    };
    let (from, to) = (ty(before), ty(after));
    let col = "{col}";
    // An integer converts exactly when the value MySQL would store, printed back, is the same text,
    // byte for byte: that refuses out-of-range text (clamped), `-0`, leading zeros, spaces, a
    // trailing newline and anything else the conversion would rewrite, in one rule.
    let round_trip = |cast: &str| format!("BINARY CAST(CAST({col} AS {cast}) AS CHAR) = BINARY {col}");
    // A CHAR column drops trailing spaces when it's read back, so text with them would change.
    let char_target = render(after).contains("` CHAR(");
    let fits_width = |b: u32| {
        let mut sql = format!("CHAR_LENGTH({col}) <= {b}");
        if char_target {
            sql.push_str(&format!(" AND BINARY {col} NOT LIKE '% '"));
        }
        sql
    };
    match (from, to) {
        (a, b) if a == b && a != "text" => None,
        ("text", "text") => match (width(before), width(after)) {
            (_, None) => None,
            (Some(a), Some(b)) if a <= b && !char_target => None,
            (_, Some(b)) => Some(fits_width(b)),
        },
        (_, "text") => width(after).map(fits_width),
        ("unsigned", "bigint") => Some(format!("{col} <= 9223372036854775807")),
        ("bigint", "unsigned") => Some(format!("{col} >= 0")),
        // TINYINT's -128..127 fits a signed BIGINT and a DOUBLE; a negative one would become 0 unsigned.
        ("boolean", "unsigned") => Some(format!("{col} >= 0")),
        ("boolean", "bigint" | "double") => None,
        // Not ABS(): it overflows on BIGINT's minimum, and an erroring count must never pass.
        ("bigint" | "unsigned", "double") => Some(format!("{col} BETWEEN -9007199254740992 AND 9007199254740992")),
        // A DOUBLE above 2^53 isn't an exact integer (`9.2233720368547e18` becomes `…700032`), so a
        // real converts to an integer only within ±2^53, and only when it is whole.
        ("double", "bigint") => Some(format!(
            "{col} = FLOOR({col}) AND {col} BETWEEN -9007199254740992 AND 9007199254740992"
        )),
        ("double", "unsigned") => Some(format!("{col} = FLOOR({col}) AND {col} BETWEEN 0 AND 9007199254740992")),
        ("text", "boolean") => Some(format!("BINARY {col} IN ('0', '1')")),
        (_, "boolean") => Some(format!("{col} IN (0, 1)")),
        ("text", "bigint") => Some(round_trip("SIGNED")),
        // MariaDB's ALTER clamps text past BIGINT's signed maximum to it even into BIGINT UNSIGNED
        // (an INSERT would store it exactly), so unsigned text is capped at 2^63 - 1 too.
        ("text", "unsigned") => Some(format!(
            "{} AND CAST({col} AS UNSIGNED) <= 9223372036854775807",
            round_trip("UNSIGNED")
        )),
        // The rule integers use: the value converts only when it reads back as the same text. A
        // decimal in canonical form, at most 15 significant digits (what a DOUBLE holds exactly), no
        // exponent (it could overflow the conversion), no `-0` (the pattern already refuses `-0.0`,
        // which has a trailing first), no trailing zeros after the point (`1.50` reads back as `1.5`). `[.]`, not `\.`: MariaDB's string literal drops the backslash,
        // leaving `.`, which matches any character. PCRE's `$` also matches before a final newline,
        // so newlines are refused separately.
        ("text", "double") => Some(format!(
            "{col} REGEXP BINARY '^-?(0|[1-9][0-9]*)([.][0-9]*[1-9])?$' AND BINARY {col} <> '-0' AND LOCATE(CHAR(10), {col}) = 0 AND CHAR_LENGTH(REPLACE(REPLACE(TRIM(LEADING '-' FROM {col}), '.', ''), '-', '')) <= 15"
        )),
        _ => Some("false".into()),
    }
}

/// The SQL literal of a column's constant default.
fn default_literal(ty: &str, v: &Json) -> String {
    match (ty, v) {
        ("boolean", Json::Bool(b)) => (if *b { "1" } else { "0" }).to_string(),
        (_, Json::Number(n)) => n.to_string(),
        (_, Json::String(s)) => string(s),
        (_, v) => string(&v.to_string()),
    }
}

/// Statements that turn `prev` into `next`. Empty when nothing changed.
///
/// Statements that can lose data or leave rows without a required value carry a
/// `-- typedpress-check` line ([`CHECK`]): before the migration runs, `Wp\Migrator` counts the live
/// rows each one affects and refuses unless the counts allow it (spec §2.9).
pub fn plan(prev: &Json, next: &Json) -> Vec<String> {
    let renames = Renames::of(prev, next);
    let stored_t = tables(prev);
    let prev_t = tables(&renames.apply(prev));
    let next_t = tables(next);
    let empty = Map::new();
    let mut out = Vec::new();
    // Constraints that point at a table about to be renamed are dropped before the rename, and added
    // back under the new name below. On SQLite the rename is a copy, and a constraint still naming
    // the old table would cascade when it's dropped.
    let mut dropped_early: Vec<(String, String)> = Vec::new();
    for (stored, def) in &stored_t {
        for (k, c) in def["columns"].as_object().unwrap_or(&empty) {
            let Some(target) = c["references"]["table"].as_str() else {
                continue;
            };
            if renames.tables.iter().any(|(_, old)| old == target) && fk_sql(stored, k, c, &stored_t).is_some() {
                out.push(format!(
                    "ALTER TABLE {} DROP FOREIGN KEY `{}`;",
                    table(stored, &stored_t),
                    fk_name(stored, k)
                ));
                dropped_early.push((stored.clone(), k.clone()));
            }
        }
    }
    // Renamed tables move next, so everything after sees the new names. A table bound with
    // `existingTable` keeps its physical name; only its key (and its revisions) move.
    for (new, old) in &renames.tables {
        let (Some(before), Some(after)) = (stored_t.get(old), next_t.get(new)) else {
            continue;
        };
        if before["existingTable"].is_null() && after["existingTable"].is_null() {
            // The check line tells the deploy check the table's name changes here, so checks of later
            // statements (in this file or the next) count rows under the new name.
            out.push(checked(
                json!({ "check": "rename", "table": physical(old, &stored_t), "to": physical(new, &next_t) }),
                format!("RENAME TABLE {} TO {};", table(old, &stored_t), table(new, &next_t)),
            ));
        }
        if stored_t.contains_key(REVISIONS) {
            out.push(format!(
                "UPDATE `{{prefix}}{REVISIONS}` SET `collection` = {} WHERE `collection` = {};",
                string(new),
                string(old)
            ));
        }
    }
    // New tables, in dependency order (referenced tables first).
    let mut created: Vec<&String> = Vec::new();
    let mut pending: Vec<&String> = next_t.keys().filter(|t| !prev_t.contains_key(*t)).collect();
    while !pending.is_empty() {
        let before = pending.len();
        pending.retain(|t| {
            let deps_ready = next_t[*t]["columns"].as_object().unwrap_or(&empty).values().all(|c| {
                c["references"]["table"].as_str().is_none_or(|dep| {
                    dep == t.as_str()
                        || !next_t.contains_key(dep)
                        || prev_t.contains_key(dep)
                        || created.iter().any(|x| x.as_str() == dep)
                })
            });
            if deps_ready {
                created.push(t);
            }
            !deps_ready
        });
        if pending.len() == before {
            // Reference cycle: emit the rest as-is (the pipeline checks cross-kind references anyway).
            created.append(&mut pending);
        }
    }
    for t in created {
        out.push(create_table(t, &next_t[t], &next_t));
    }
    for (t, next_def) in &next_t {
        let Some(prev_def) = prev_t.get(t) else { continue };
        let stored = renames.stored_table(t);
        let stored_def = &stored_t[stored];
        let sc = stored_def["columns"].as_object().unwrap_or(&empty);
        let pc = prev_def["columns"].as_object().unwrap_or(&empty);
        let nc = next_def["columns"].as_object().unwrap_or(&empty);
        let render =
            |m: &Map| -> Vec<(String, String)> { m.iter().map(|(k, c)| (k.clone(), column_sql(k, c))).collect() };
        let pr = render(pc);
        let nr = render(nc);
        let p_ix = indexes(stored, stored_def);
        let n_ix = indexes(t, next_def);
        let table = table(t, &next_t);
        // Checks run before any statement of the migration, so they name the table as stored now.
        let physical = physical(stored, &stored_t);
        let adopted = adopted(next_def);
        // Constraints that go away (or change, or follow a rename) are dropped before columns change.
        for (k, c) in sc {
            let before = fk_sql(stored, k, c, &stored_t);
            let renamed = renames
                .columns
                .iter()
                .find(|(table, _, old)| table == t && old == k)
                .map_or(k.as_str(), |(_, new, _)| new.as_str());
            let after = nc.get(renamed).and_then(|nc| fk_sql(t, renamed, nc, &next_t));
            let early = dropped_early.iter().any(|(s, c)| s == stored && c == k);
            if before.is_some() && before != after && !early {
                out.push(format!(
                    "ALTER TABLE {table} DROP FOREIGN KEY `{}`;",
                    fk_name(stored, k)
                ));
            }
        }
        for ix in &p_ix {
            if !n_ix.contains(ix) {
                out.push(drop_index(t, &ix.0, &next_t));
            }
        }
        for (rt, new, old) in &renames.columns {
            if rt == t {
                // `definition` is for SQLite, where the deploy runs this as a CHANGE COLUMN (the SQLite
                // Database Integration driver loses the values on RENAME COLUMN). RENAME keeps the
                // stored type on MySQL, which matters for a table TypedPress didn't create.
                out.push(checked(
                    json!({ "check": "rename", "name": format!("{t}.{old}"), "table": physical,
                        "column": old, "to": new, "definition": column_sql(new, &pc[new]), "adopted": adopted }),
                    format!("ALTER TABLE {table} RENAME COLUMN {} TO {};", q(old), q(new)),
                ));
            }
        }
        for (k, sql) in &nr {
            let c = &nc[k];
            let required = c["notNull"] == true && c["primaryKey"] != true;
            let name = format!("{t}.{k}");
            match pr.iter().find(|(pk, _)| pk == k) {
                None => {
                    let add = format!("ALTER TABLE {table} ADD COLUMN {sql};");
                    if required && c["default"].is_null() {
                        // Existing rows would get MySQL's implicit empty value: refuse unless there are none.
                        out.push(checked(
                            json!({ "check": "required", "name": name, "table": physical }),
                            add,
                        ));
                    } else {
                        out.push(add);
                    }
                }
                Some((_, psql)) if psql != sql => {
                    let modify = format!("ALTER TABLE {table} MODIFY COLUMN {sql};");
                    let column = renames.stored_column(t, k);
                    // A narrower type or length: MySQL without strict mode (WordPress's) would
                    // truncate or coerce the values that don't fit, so they're counted first.
                    let fits = fits(&pc[k], c);
                    let tightens = required && pc[k]["notNull"] != true;
                    // Empty rows get the declared default, then the column becomes required. The
                    // backfill runs after every check has been measured (checks are counted before any
                    // statement of the plan), so a narrowing check still sees the values as they are.
                    let backfill = (tightens && !c["default"].is_null()).then(|| {
                        let ty = c["type"].as_str().unwrap_or("text");
                        format!(
                            "UPDATE {table} SET {} = {} WHERE {} IS NULL;",
                            q(k),
                            default_literal(ty, &c["default"]),
                            q(k)
                        )
                    });
                    // A table TypedPress didn't create may store the column under another type than the snapshot
                    // says (adoption checks names, not types), so any change to one is confirmed first.
                    let mut check = match (tightens && c["default"].is_null(), &fits) {
                        (true, _) => json!({ "check": "required", "name": name, "table": physical, "column": column }),
                        (false, Some(_)) => {
                            json!({ "check": "narrow", "name": name, "table": physical, "column": column })
                        }
                        (false, None) if adopted => {
                            json!({ "check": "alter", "name": name, "table": physical, "column": column })
                        }
                        (false, None) => Json::Null,
                    };
                    if let Some(fits) = fits {
                        check["fits"] = fits.into();
                    }
                    if !check.is_null() {
                        check["adopted"] = adopted.into();
                    }
                    // The check goes on the first statement that touches the column, so it is the one
                    // a resumed migration counts before; the backfill and the MODIFY run after it.
                    let mut statements: Vec<String> = backfill.into_iter().chain([modify]).collect();
                    if !check.is_null() {
                        statements[0] = checked(check, statements[0].clone());
                    }
                    out.extend(statements);
                }
                _ => {}
            }
        }
        for (k, _) in &pr {
            if !nc.contains_key(k) {
                out.push(checked(
                    json!({ "check": "drop", "name": format!("{t}.{k}"), "table": physical,
                        "column": renames.stored_column(t, k), "adopted": adopted }),
                    format!("ALTER TABLE {table} DROP COLUMN {};", q(k)),
                ));
            }
        }
        for ix in &n_ix {
            if !p_ix.contains(ix) {
                out.push(create_index(t, ix, &next_t));
            }
        }
        for (k, c) in nc {
            let stored_k = renames.stored_column(t, k);
            let early = dropped_early.iter().any(|(s, c)| s == stored && c == stored_k);
            let before = sc.get(stored_k).and_then(|sc| fk_sql(stored, stored_k, sc, &stored_t));
            if let Some(after) = fk_sql(t, k, c, &next_t)
                && (early || before.as_ref() != Some(&after))
            {
                out.push(format!("ALTER TABLE {table} ADD {after};"));
            }
        }
    }
    for t in prev_t.keys().filter(|t| !next_t.contains_key(*t)) {
        let mut check =
            json!({ "check": "drop", "name": t, "table": physical(t, &prev_t), "adopted": adopted(&prev_t[t]) });
        // The name a dropped binding was adopted under: the runtime checks the adoption again first.
        if prev_t[t]["adoptedAs"].is_string() {
            check["adoptedAs"] = prev_t[t]["adoptedAs"].clone();
        }
        out.push(checked(check, format!("DROP TABLE {};", table(t, &prev_t))));
    }
    out
}

/// Human-readable warnings for changes that can lose data.
pub fn destructive(prev: &Json, next: &Json) -> Vec<String> {
    let prev = &Renames::of(prev, next).apply(prev);
    let empty = Map::new();
    let prev_t = prev["tables"].as_object().unwrap_or(&empty);
    let next_t = next["tables"].as_object().unwrap_or(&empty);
    let mut out = Vec::new();
    for (t, def) in prev_t {
        match next_t.get(t) {
            None => out.push(format!("drops table \"{t}\" and all its rows")),
            Some(n) => {
                if def["existingTable"] != n["existingTable"] {
                    out.push(format!(
                        "changes the existing SQL table binding for \"{t}\"; rows are not moved"
                    ));
                }
                let wp_table = |d: &Json| d["editor"].as_bool() == Some(false) && d["auth"].as_bool() != Some(true);
                // A change of storage drops no column of one store in favour of the other: the
                // warnings below say what happens to the rows.
                if wp_table(def) == wp_table(n) {
                    for k in def["columns"].as_object().unwrap_or(&empty).keys() {
                        if !n["columns"].as_object().unwrap_or(&empty).contains_key(k) {
                            out.push(format!("drops column \"{t}\".\"{k}\""));
                        }
                    }
                }
                if wp_table(def) && !wp_table(n) {
                    let to = if n["auth"].as_bool() == Some(true) {
                        "WordPress users"
                    } else {
                        "post-backed"
                    };
                    out.push(format!(
                        "drops the WordPress table for \"{t}\" (it becomes {to}; existing rows aren't moved)"
                    ));
                }
                // The other way: rows held as posts (or users) stay where they are, and the new table
                // starts empty. Nothing is dropped, so no deploy check stops it.
                if !wp_table(def) && wp_table(n) {
                    let from = if def["auth"].as_bool() == Some(true) {
                        "WordPress users"
                    } else {
                        "WordPress posts"
                    };
                    out.push(format!(
                        "creates an empty SQL table for \"{t}\" (its rows stay in {from}, and the app no longer reads them)"
                    ));
                }
            }
        }
    }
    out
}

/// A field's `max` changed in a way a development update refuses. On a SQL table it is the column's
/// type, so any change counts; on a post kind it is only a rule on writes, and dropping or raising it
/// refuses no stored value.
fn max_changes(editor: bool, column: &Json, replacement: &Json) -> bool {
    let (old, new) = (column["max"].as_f64(), replacement["max"].as_f64());
    if editor {
        new.is_some_and(|new| old.is_none_or(|old| new < old))
    } else {
        old != new
    }
}

/// Conservative development updates: existing values and constraints must keep their meanings.
/// This is stricter than production's warning list; deliberate migrations use the normal build.
pub fn preservation_problems(prev: &Json, next: &Json) -> Vec<String> {
    if prev["version"] != 2 || !prev["tables"].is_object() {
        return vec!["invalid preservation snapshot".into()];
    }
    let mut problems = destructive(prev, next);
    let prev = &Renames::of(prev, next).apply(prev);
    for (name, table) in prev["tables"].as_object().expect("validated snapshot") {
        let candidate = &next["tables"][name];
        if candidate.is_null() {
            continue;
        }
        for key in ["editor", "auth", "localized"] {
            if table[key] != candidate[key] {
                problems.push(format!("changes storage for {name}"));
            }
        }
        if let Some(columns) = table["columns"].as_object() {
            for (field, column) in columns {
                let replacement = &candidate["columns"][field];
                if replacement.is_null() {
                    continue;
                }
                // On a post kind `unique` is a check on writes, and dropping it refuses no stored value.
                let unique_dropped =
                    table["editor"] == true && column["unique"] == true && replacement["unique"] == false;
                if [
                    "type",
                    "notNull",
                    "primaryKey",
                    "autoIncrement",
                    "unique",
                    "enum",
                    "references",
                ]
                .iter()
                .any(|key| column[*key] != replacement[*key] && !(*key == "unique" && unique_dropped))
                    || max_changes(table["editor"] == true, column, replacement)
                {
                    problems.push(format!("changes field {name}.{field}"));
                }
            }
        }
        if let Some(columns) = candidate["columns"].as_object() {
            for (field, column) in columns {
                if table["columns"].get(field).is_none() && column["notNull"] == true && column["default"].is_null() {
                    problems.push(format!("adds required field {name}.{field} without a default"));
                }
            }
        }
    }
    problems
}

pub fn render(statements: &[String]) -> String {
    let mut s = statements.join(&format!("\n{BREAKPOINT}\n"));
    s.push('\n');
    s
}

#[cfg(test)]
mod tests {
    use super::*;

    fn snap(tables: Json) -> Json {
        json!({ "version": 2, "tables": tables })
    }

    fn col(ty: &str) -> Json {
        json!({ "type": ty, "notNull": false, "primaryKey": false, "autoIncrement": false, "unique": false, "max": null, "default": null, "references": null })
    }

    #[test]
    fn creates_referenced_tables_first_then_alters_columns() {
        let id = json!({ "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null });
        let mut fk = col("integer");
        fk["references"] =
            json!({ "table": "locations", "column": "id", "onDelete": "set null", "onUpdate": null, "unsigned": true });
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({
            "items": table(json!({ "id": id, "locationId": fk })),
            "locations": table(json!({ "id": id })),
        }));
        // Prefixed tables, the referenced table before the one referencing it, an unsigned FK column,
        // a real constraint; the revisions system table comes with the first collection.
        let first = plan(&snap(json!({})), &v1);
        let at = |table: &str| {
            first
                .iter()
                .position(|s| s.starts_with(&format!("CREATE TABLE `{{prefix}}{table}`")))
                .unwrap_or_else(|| panic!("no table {table} in {first:?}"))
        };
        assert!(at("tp_locations") < at("tp_items"), "{first:?}");
        assert_eq!(first.len(), 3, "{first:?}");
        at("tp__revisions");
        let items = &first[at("tp_items")];
        assert!(items.contains("`locationId` BIGINT UNSIGNED"), "{items}");
        assert!(
            items.contains("FOREIGN KEY (`locationId`) REFERENCES `{prefix}tp_locations` (`id`) ON DELETE SET NULL"),
            "{items}"
        );

        // Adding a nullable column is an ADD; making it NOT NULL is a MODIFY.
        let mut v2 = v1.clone();
        v2["tables"]["items"]["columns"]["note"] = col("text");
        assert_eq!(
            plan(&v1, &v2),
            vec!["ALTER TABLE `{prefix}tp_items` ADD COLUMN `note` LONGTEXT;".to_string()]
        );
        assert!(preservation_problems(&v1, &v2).is_empty());
        let mut v3 = v2.clone();
        v3["tables"]["items"]["columns"]["note"]["notNull"] = json!(true);
        // Making it required is checked against live rows first: rows without a value refuse.
        assert_eq!(
            plan(&v2, &v3),
            vec![
                "-- typedpress-check {\"check\":\"required\",\"name\":\"items.note\",\"table\":\"{prefix}tp_items\",\"column\":\"note\",\"adopted\":false}\nALTER TABLE `{prefix}tp_items` MODIFY COLUMN `note` LONGTEXT NOT NULL;".to_string()
            ]
        );
        assert_eq!(preservation_problems(&v2, &v3), vec!["changes field items.note"]);
        assert_eq!(
            preservation_problems(&v1, &v3),
            vec!["adds required field items.note without a default"]
        );
        let mut defaulted = v3.clone();
        defaulted["tables"]["items"]["columns"]["note"]["default"] = json!("Ready");
        assert!(preservation_problems(&v1, &defaulted).is_empty());
        let mut bounded = v3.clone();
        bounded["tables"]["items"]["columns"]["note"]["max"] = json!(160.0);
        // Studio's JSON roundtrip writes an unchanged integral bound without the decimal.
        let mut accepted = bounded.clone();
        accepted["tables"]["items"]["columns"]["note"]["max"] = json!(160);
        assert!(preservation_problems(&accepted, &bounded).is_empty());
        accepted["tables"]["items"]["columns"]["note"]["max"] = json!(161);
        assert_eq!(
            preservation_problems(&bounded, &accepted),
            vec!["changes field items.note"]
        );
        accepted["tables"]["items"]["columns"]["note"]["max"] = Json::Null;
        assert_eq!(
            preservation_problems(&bounded, &accepted),
            vec!["changes field items.note"]
        );
        accepted["tables"]["items"]["columns"]["note"]
            .as_object_mut()
            .unwrap()
            .remove("max");
        assert_eq!(
            preservation_problems(&bounded, &accepted),
            vec!["changes field items.note"]
        );
        assert!(plan(&v3, &v3).is_empty());
        // A dropped column and a dropped table.
        let mut v4 = v3.clone();
        v4["tables"]["items"]["columns"].as_object_mut().unwrap().remove("note");
        v4["tables"].as_object_mut().unwrap().remove("locations");
        assert_eq!(
            plan(&v3, &v4),
            vec![
                "ALTER TABLE `{prefix}tp_items` DROP FOREIGN KEY `tp_items_locationId_fk`;".to_string(),
                "-- typedpress-check {\"check\":\"drop\",\"name\":\"items.note\",\"table\":\"{prefix}tp_items\",\"column\":\"note\",\"adopted\":false}\nALTER TABLE `{prefix}tp_items` DROP COLUMN `note`;".to_string(),
                "-- typedpress-check {\"check\":\"drop\",\"name\":\"locations\",\"table\":\"{prefix}tp_locations\",\"adopted\":false}\nDROP TABLE `{prefix}tp_locations`;".to_string(),
            ]
        );
        assert_eq!(
            preservation_problems(&v3, &v4),
            vec![
                "drops column \"items\".\"note\"",
                "drops table \"locations\" and all its rows"
            ]
        );
    }

    #[test]
    fn warns_when_a_wordpress_table_stops_holding_the_rows() {
        let table = json!({ "editor": false, "drafts": false, "columns": { "id": col("integer") } });
        let v1 = snap(json!({ "members": table }));
        assert!(destructive(&v1, &v1).is_empty());
        // Becoming an auth collection moves the rows to wp_users: the custom table is dropped.
        let mut users = v1.clone();
        users["tables"]["members"]["auth"] = json!(true);
        assert_eq!(
            destructive(&v1, &users),
            vec!["drops the WordPress table for \"members\" (it becomes WordPress users; existing rows aren't moved)"]
        );
        assert!(
            destructive(&users, &users).is_empty(),
            "an auth collection never had a table to drop"
        );
        let mut posts = v1.clone();
        posts["tables"]["members"]["editor"] = json!(true);
        assert_eq!(
            destructive(&v1, &posts)[0],
            "drops the WordPress table for \"members\" (it becomes post-backed; existing rows aren't moved)"
        );
        // And back: posts or users declared as a table() leave their rows where they are and read an
        // empty table. The columns the old store had are not reported as drops: nothing drops them.
        let mut posts_fields = posts.clone();
        posts_fields["tables"]["members"]["columns"]["status"] = col("text");
        assert_eq!(
            destructive(&posts_fields, &v1),
            vec![
                "creates an empty SQL table for \"members\" (its rows stay in WordPress posts, and the app no longer reads them)"
            ]
        );
        assert_eq!(
            destructive(&users, &v1),
            vec![
                "creates an empty SQL table for \"members\" (its rows stay in WordPress users, and the app no longer reads them)"
            ]
        );
    }

    #[test]
    fn localizing_a_table_adds_locale_and_group_with_a_unique_pair() {
        let id = json!({ "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null });
        let v1 = snap(
            json!({ "pages": { "editor": false, "drafts": false, "columns": { "id": id, "title": col("text") } } }),
        );
        let mut locale = col("text");
        locale["notNull"] = json!(true);
        locale["enum"] = json!(["en", "de"]);
        locale["default"] = json!("en");
        let mut v2 = v1.clone();
        v2["tables"]["pages"]["localized"] = json!(true);
        v2["tables"]["pages"]["columns"]["locale"] = locale;
        let mut group = col("text");
        group["max"] = json!(36);
        v2["tables"]["pages"]["columns"]["translationGroup"] = group;
        assert_eq!(
            plan(&v1, &v2),
            vec![
                "ALTER TABLE `{prefix}tp_pages` ADD COLUMN `locale` VARCHAR(191) DEFAULT 'en' NOT NULL;".to_string(),
                "ALTER TABLE `{prefix}tp_pages` ADD COLUMN `translationGroup` VARCHAR(36);".to_string(),
                "ALTER TABLE `{prefix}tp_pages` ADD UNIQUE KEY `translationGroup_locale` (`translationGroup`, `locale`);".to_string(),
            ]
        );
    }
    #[test]
    fn adopts_exact_table_and_keeps_later_column_changes_on_that_table() {
        let def = json!({"editor":false,"drafts":false,"existingTable":"sf_space_tasks_hash","columns":{"id":col("integer")}});
        let previous = snap(json!({"tasks":def}));
        let initial = plan(&snap(json!({})), &previous);
        assert!(initial[0].ends_with("\nSELECT `id` FROM `sf_space_tasks_hash` LIMIT 0;"));
        let mut next = previous.clone();
        next["tables"]["tasks"]["columns"]["note"] = col("text");
        assert_eq!(
            plan(&previous, &next),
            ["ALTER TABLE `sf_space_tasks_hash` ADD COLUMN `note` LONGTEXT;"]
        );
    }

    fn id() -> Json {
        json!({ "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null })
    }

    fn check_of(statement: &str) -> Option<Json> {
        let line = statement.lines().next()?.strip_prefix(CHECK)?;
        Some(serde_json::from_str(line).unwrap())
    }

    #[test]
    fn additive_changes_carry_no_check() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "likes": table(json!({ "id": id() })) }));
        let mut v2 = v1.clone();
        v2["tables"]["likes"]["columns"]["note"] = col("text");
        let mut required = col("text");
        required["notNull"] = json!(true);
        required["default"] = json!("ok");
        v2["tables"]["likes"]["columns"]["state"] = required;
        v2["tables"]["tags"] = table(json!({ "id": id() }));
        let statements = plan(&v1, &v2);
        assert!(statements.iter().all(|s| check_of(s).is_none()), "{statements:?}");
    }

    #[test]
    fn a_required_field_with_a_default_backfills_instead_of_refusing() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "likes": table(json!({ "id": id(), "state": col("text") })) }));
        let mut v2 = v1.clone();
        v2["tables"]["likes"]["columns"]["state"]["notNull"] = json!(true);
        v2["tables"]["likes"]["columns"]["state"]["default"] = json!("open");
        assert_eq!(
            plan(&v1, &v2),
            [
                "UPDATE `{prefix}tp_likes` SET `state` = 'open' WHERE `state` IS NULL;",
                "ALTER TABLE `{prefix}tp_likes` MODIFY COLUMN `state` LONGTEXT DEFAULT ('open') NOT NULL;",
            ]
        );
    }

    #[test]
    fn was_renames_tables_and_columns_instead_of_dropping_them() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "likes": table(json!({ "id": id(), "body": col("text") })) }));
        let mut v2 = snap(json!({ "reactions": table(json!({ "id": id(), "text": col("text") })) }));
        // Undeclared, this is a drop and a create.
        assert!(plan(&v1, &v2).iter().any(|s| s.contains("DROP TABLE")));
        v2["tables"]["reactions"]["was"] = json!("likes");
        v2["tables"]["reactions"]["columns"]["text"]["was"] = json!("body");
        let statements = plan(&v1, &v2);
        let sql: Vec<&str> = statements.iter().map(|s| s.lines().last().unwrap()).collect();
        assert_eq!(
            sql,
            [
                "RENAME TABLE `{prefix}tp_likes` TO `{prefix}tp_reactions`;",
                "UPDATE `{prefix}tp__revisions` SET `collection` = 'reactions' WHERE `collection` = 'likes';",
                "ALTER TABLE `{prefix}tp_reactions` RENAME COLUMN `body` TO `text`;",
            ]
        );
        // Every check names the table as stored before the plan runs; the deploy follows the rename.
        let checks: Vec<Json> = statements.iter().filter_map(|s| check_of(s)).collect();
        assert_eq!(
            checks,
            [
                json!({ "check": "rename", "table": "{prefix}tp_likes", "to": "{prefix}tp_reactions" }),
                json!({ "check": "rename", "name": "reactions.body", "table": "{prefix}tp_likes", "column": "body",
                    "to": "text", "definition": "`text` LONGTEXT", "adopted": false }),
            ]
        );
        assert!(destructive(&v1, &v2).is_empty());
        assert!(preservation_problems(&v1, &v2).is_empty());
        // Once applied, the declaration is inert: the next build plans nothing.
        assert!(plan(&v2, &v2).is_empty());
    }

    #[test]
    fn checks_name_the_stored_table_and_mark_adopted_tables() {
        let adopted = json!({ "editor": false, "drafts": false, "existingTable": "sf_space_tasks_hash",
            "columns": { "id": id(), "note": col("text") } });
        let v1 = snap(json!({ "tasks": adopted }));
        let mut v2 = v1.clone();
        v2["tables"]["tasks"]["columns"].as_object_mut().unwrap().remove("note");
        let checks: Vec<Json> = plan(&v1, &v2).iter().filter_map(|s| check_of(s)).collect();
        assert_eq!(
            checks,
            [
                json!({ "check": "drop", "name": "tasks.note", "table": "sf_space_tasks_hash", "column": "note", "adopted": true })
            ]
        );
        let removed = plan(
            &v1,
            &snap(json!({ "other": { "editor": false, "drafts": false, "columns": { "id": id() } } })),
        );
        let checks: Vec<Json> = removed.iter().filter_map(|s| check_of(s)).collect();
        assert_eq!(
            checks,
            [json!({ "check": "drop", "name": "tasks", "table": "sf_space_tasks_hash", "adopted": true })]
        );
    }

    #[test]
    fn narrowing_a_column_counts_the_values_that_would_not_fit() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(
            json!({ "posts": table(json!({ "id": id(), "title": col("text"), "views": col("integer"), "slug": col("text") })) }),
        );
        let mut v2 = v1.clone();
        v2["tables"]["posts"]["columns"]["title"]["max"] = json!(100);
        v2["tables"]["posts"]["columns"]["views"]["type"] = json!("boolean");
        v2["tables"]["posts"]["columns"]["slug"]["unique"] = json!(true);
        let checks: Vec<Json> = plan(&v1, &v2).iter().filter_map(|s| check_of(s)).collect();
        // A boolean becoming an unsigned reference would turn a stored -1 into 0.
        let mut flagged = v1.clone();
        flagged["tables"]["posts"]["columns"]["views"]["type"] = json!("boolean");
        let mut referenced = flagged.clone();
        referenced["tables"]["posts"]["columns"]["views"]["type"] = json!("integer");
        referenced["tables"]["posts"]["columns"]["views"]["references"] =
            json!({ "table": "posts", "column": "id", "onDelete": null, "onUpdate": null, "unsigned": true });
        let to_unsigned: Vec<Json> = plan(&flagged, &referenced).iter().filter_map(|s| check_of(s)).collect();
        assert!(
            to_unsigned
                .iter()
                .any(|c| c["name"] == "posts.views" && c["fits"] == "{col} >= 0"),
            "{to_unsigned:?}"
        );
        let fits: Vec<(&str, &str)> = checks
            .iter()
            .map(|c| (c["name"].as_str().unwrap(), c["fits"].as_str().unwrap()))
            .collect();
        assert_eq!(
            fits,
            [
                ("posts.title", "CHAR_LENGTH({col}) <= 100"),
                ("posts.views", "{col} IN (0, 1)"),
                ("posts.slug", "CHAR_LENGTH({col}) <= 191"),
            ]
        );
        // Widening back needs no check.
        assert!(
            plan(&v2, &v1)
                .iter()
                .all(|s| check_of(s).is_none_or(|c| c["check"] != "narrow"))
        );
    }

    #[test]
    fn a_constraint_on_a_renamed_table_is_dropped_before_the_rename_and_added_after() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let mut fk = col("integer");
        fk["references"] =
            json!({ "table": "notes", "column": "id", "onDelete": "set null", "onUpdate": null, "unsigned": true });
        let v1 = snap(
            json!({ "notes": table(json!({ "id": id() })), "kids": table(json!({ "id": id(), "noteId": fk.clone() })) }),
        );
        let mut fk2 = fk;
        fk2["references"]["table"] = json!("memos");
        let mut v2 =
            snap(json!({ "memos": table(json!({ "id": id() })), "kids": table(json!({ "id": id(), "noteId": fk2 })) }));
        v2["tables"]["memos"]["was"] = json!("notes");
        let sql: Vec<String> = plan(&v1, &v2)
            .iter()
            .map(|s| s.lines().last().unwrap().to_string())
            .collect();
        let at = |prefix: &str| {
            sql.iter()
                .position(|s| s.starts_with(prefix))
                .unwrap_or_else(|| panic!("{prefix} in {sql:?}"))
        };
        assert!(
            at("ALTER TABLE `{prefix}tp_kids` DROP FOREIGN KEY") < at("RENAME TABLE"),
            "{sql:?}"
        );
        assert!(
            at("RENAME TABLE") < at("ALTER TABLE `{prefix}tp_kids` ADD CONSTRAINT"),
            "{sql:?}"
        );
        assert_eq!(
            sql.iter().filter(|s| s.contains("DROP FOREIGN KEY")).count(),
            1,
            "{sql:?}"
        );
    }

    #[test]
    fn a_field_made_required_and_narrower_checks_both_before_the_backfill() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "notes": table(json!({ "id": id(), "body": col("text"), "tag": col("text") })) }));
        let mut v2 = v1.clone();
        for (k, default) in [("body", Json::Null), ("tag", json!("x"))] {
            v2["tables"]["notes"]["columns"][k]["notNull"] = json!(true);
            v2["tables"]["notes"]["columns"][k]["max"] = json!(5);
            v2["tables"]["notes"]["columns"][k]["default"] = default;
        }
        let statements = plan(&v1, &v2);
        let checks: Vec<Json> = statements.iter().filter_map(|s| check_of(s)).collect();
        assert_eq!(
            checks
                .iter()
                .map(|c| (
                    c["check"].as_str().unwrap(),
                    c["name"].as_str().unwrap(),
                    c["fits"].as_str().unwrap()
                ))
                .collect::<Vec<_>>(),
            [
                ("required", "notes.body", "CHAR_LENGTH({col}) <= 5"),
                ("narrow", "notes.tag", "CHAR_LENGTH({col}) <= 5")
            ]
        );
        // The backfill of the defaulted field carries the check, so it's measured before the UPDATE runs.
        let backfill = statements
            .iter()
            .find(|s| s.contains("UPDATE `{prefix}tp_notes` SET `tag`"))
            .unwrap();
        assert!(check_of(backfill).is_some(), "{backfill}");
    }

    #[test]
    fn text_becoming_a_number_or_boolean_is_compared_as_text_and_range_checked() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let fits_for = |ty: &str| {
            let v1 = snap(json!({ "t": table(json!({ "id": id(), "v": col("text") })) }));
            let mut v2 = v1.clone();
            v2["tables"]["t"]["columns"]["v"]["type"] = json!(ty);
            plan(&v1, &v2).iter().find_map(|s| check_of(s)).unwrap()["fits"]
                .as_str()
                .unwrap()
                .to_string()
        };
        // `'yes' IN (0, 1)` holds in MySQL ('yes' is 0); compared as text it doesn't.
        assert_eq!(fits_for("boolean"), "BINARY {col} IN ('0', '1')");
        // Integers round-trip byte for byte: out of range, `-0` and a trailing newline all differ.
        assert_eq!(
            fits_for("integer"),
            "BINARY CAST(CAST({col} AS SIGNED) AS CHAR) = BINARY {col}"
        );
        let real = fits_for("real");
        assert!(
            real.contains("([.][0-9]*[1-9])?$'") && !real.contains("\\."),
            "a backslash-dot is any character in MariaDB: {real}"
        );
        assert!(real.contains("LOCATE(CHAR(10), {col}) = 0"), "{real}");
        // MariaDB's ALTER clamps text past 2^63 - 1 even into BIGINT UNSIGNED.
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "t": table(json!({ "id": id(), "v": col("text") })) }));
        let mut v2 = v1.clone();
        v2["tables"]["t"]["columns"]["v"]["type"] = json!("integer");
        v2["tables"]["t"]["columns"]["v"]["references"] =
            json!({ "table": "t", "column": "id", "onDelete": null, "onUpdate": null, "unsigned": true });
        let unsigned = plan(&v1, &v2).iter().find_map(|s| check_of(s)).unwrap()["fits"]
            .as_str()
            .unwrap()
            .to_string();
        assert!(
            unsigned.contains("CAST({col} AS UNSIGNED) <= 9223372036854775807"),
            "{unsigned}"
        );
    }
}
