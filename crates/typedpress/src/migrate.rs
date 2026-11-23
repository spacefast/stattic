//! Forward-only schema snapshots, sortable folder migrations (`ops.json` + `migration.sql`),
//! fold-based baseline reconstruction, and parallel-branch conflict detection.
//!
//! Each migration lives in its own immutable directory under `tp/_generated/migrations/`:
//!   `<YYYYMMDDHHMMSS>_<6char-base32>_<slug>/ops.json`
//!   `<YYYYMMDDHHMMSS>_<6char-base32>_<slug>/migration.sql`
//!
//! There is no global `snapshot.json` and no `_journal.json`. Two parallel branches never edit the
//! same migration file; the compiler folds every `ops.json` in sorted ID order to compute the
//! baseline state and catches incompatible parallel edits at build time.
//!
//! Core migrations are strictly forward-only:
//! - Only `table()` definitions get a table, `{prefix}tp_<table>`, plus `{prefix}tp__join_<join>`
//!   for `ref().many()` join tables and `{prefix}tp__revisions` for revisions.
//! - Removing a column or table retires it (relaxing NOT NULL / dropping FKs & indexes) instead of
//!   emitting `DROP COLUMN` or `DROP TABLE`.
//! - Lossy type narrowing and adding/tightening required columns without a literal default are
//!   refused at build time.
//! - Explicit destructive cleanup lives behind the opt-in `typedpress/danger` subpath.

use crate::diag::{Code, Diag, Span};
use crate::schema::{Collection, Column, DefaultKind, Schema};
use serde_json::{Value as Json, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

pub const BREAKPOINT: &str = "--> statement-breakpoint";
/// First line of a statement carrying deploy metadata: `-- typedpress-check {json}`.
pub const CHECK: &str = "-- typedpress-check ";
/// Snapshot key of the revisions system table.
pub const REVISIONS: &str = "tp__revisions";

type Map = serde_json::Map<String, Json>;

/// One generated migration folder: immutable `id`, structured `ops.json`, and SQL DDL.
#[derive(Debug, Clone, PartialEq)]
pub struct MigrationEntry {
    pub id: String,
    pub ops: Json,
    pub sql: String,
}

/// Baseline schema reconstructed by folding every `<id>/ops.json` in sorted ID order.
#[derive(Debug, Clone, Default)]
pub struct FoldedState {
    /// Active tables (SQL and metadata), keyed by storage key `t`.
    pub tables: Map,
    /// Retired SQL tables kept intact on disk for zero-downtime / two-phase cleanup.
    pub retired_tables: Map,
    /// Retired SQL columns per active table: `table -> { physical_col -> relaxed_col_def }`.
    pub retired_columns: BTreeMap<String, Map>,
    /// Applied migration IDs in sorted order.
    pub history: Vec<String>,
}

impl FoldedState {
    pub fn empty() -> Self {
        Self::default()
    }

    /// Snapshot view of the active folded tables (for `index_report`, `preservation_problems`, etc.).
    pub fn to_snapshot(&self) -> Json {
        json!({ "version": 2, "tables": self.tables })
    }
}

fn q(ident: &str) -> String {
    format!("`{}`", ident.replace('`', "``"))
}

fn table(t: &str, tables: &Map) -> String {
    if let Some(name) = tables.get(t).and_then(|d| d["existingTable"].as_str()) {
        return q(name);
    }
    if t == REVISIONS {
        format!("`{{prefix}}{t}`")
    } else if tables.get(t).is_some_and(|d| d["join"].is_object()) {
        format!("`{{prefix}}tp__join_{t}`")
    } else {
        format!("`{{prefix}}tp_{t}`")
    }
}

/// The physical table name, unquoted.
pub fn physical_table_name(t: &str, tables: &Map) -> String {
    table(t, tables).trim_matches('`').replace("``", "`")
}

fn adopted(def: &Json) -> bool {
    def["existingTable"].is_string()
}

fn string(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "''"))
}

/// Validates a sortable folder migration ID: `<14-digits>_<6-crockford-base32>_<slug>`.
pub fn is_migration_id(id: &str) -> bool {
    if id.is_empty() || id.len() > 128 || id.contains('/') || id.contains('\\') {
        return false;
    }
    let bytes = id.as_bytes();
    if bytes.len() < 23 || bytes[14] != b'_' || bytes[21] != b'_' {
        return false;
    }
    if !bytes[..14].iter().all(u8::is_ascii_digit) {
        return false;
    }
    let month: u32 = id[4..6].parse().unwrap_or(0);
    let day: u32 = id[6..8].parse().unwrap_or(0);
    let hour: u32 = id[8..10].parse().unwrap_or(99);
    let minute: u32 = id[10..12].parse().unwrap_or(99);
    let second: u32 = id[12..14].parse().unwrap_or(99);
    let ts_ok = (1..=12).contains(&month) && (1..=31).contains(&day) && hour <= 23 && minute <= 59 && second <= 59;
    let hash_ok = bytes[15..21].iter().all(|b| CROCKFORD.contains(b));
    let slug_ok = bytes[22..]
        .iter()
        .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || *b == b'_');
    ts_ok && hash_ok && slug_ok
}

const CROCKFORD: &[u8; 32] = b"0123456789abcdefghjkmnpqrstvwxyz";

fn hash6(ops: &[Json]) -> String {
    let bytes = serde_json::to_vec(ops).expect("ops serialize");
    let digest = Sha256::digest(&bytes);
    // Take first 30 bits -> 6 × 5-bit Crockford base32 symbols.
    let mut bits: u64 = 0;
    for &b in &digest[..4] {
        bits = (bits << 8) | u64::from(b);
    }
    bits >>= 2;
    let mut out = String::with_capacity(6);
    for shift in (0..6).rev() {
        let idx = ((bits >> (shift * 5)) & 0x1f) as usize;
        out.push(CROCKFORD[idx] as char);
    }
    out
}

fn sanitize_slug(raw: &str) -> String {
    let mut out = String::new();
    let mut prev_us = false;
    for ch in raw.chars() {
        let lower = ch.to_ascii_lowercase();
        if lower.is_ascii_lowercase() || lower.is_ascii_digit() {
            out.push(lower);
            prev_us = false;
        } else if !prev_us && !out.is_empty() {
            out.push('_');
            prev_us = true;
        }
        if out.len() >= 32 {
            break;
        }
    }
    let trimmed = out.trim_end_matches('_');
    if trimmed.is_empty() {
        "update".to_string()
    } else {
        trimmed.to_string()
    }
}

/// Convert Unix epoch seconds to UTC `(year, month, day, hour, minute, second)` using Howard
/// Hinnant's civil-from-days algorithm (zero external crate dependencies).
fn utc_from_epoch(secs: u64) -> (i64, u32, u32, u32, u32, u32) {
    let days = (secs / 86_400) as i64;
    let rem = secs % 86_400;
    let hour = (rem / 3600) as u32;
    let minute = ((rem % 3600) / 60) as u32;
    let second = (rem % 60) as u32;
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = (z - era * 146_097) as u64;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = (yoe as i64) + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = if m <= 2 { y + 1 } else { y };
    (year, m as u32, d as u32, hour, minute, second)
}

fn days_from_civil(year: i64, month: u32, day: u32) -> i64 {
    let y = if month <= 2 { year - 1 } else { year };
    let era = y.div_euclid(400);
    let yoe = (y - era * 400) as u64;
    let m = month as u64;
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + (day as u64) - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + (doe as i64) - 719_468
}

fn format_ts(secs: u64) -> String {
    let (y, mo, d, h, mi, s) = utc_from_epoch(secs);
    format!("{y:04}{mo:02}{d:02}{h:02}{mi:02}{s:02}")
}

fn parse_ts_epoch(ts: &str) -> Option<u64> {
    if ts.len() < 14 || !ts.as_bytes()[..14].iter().all(u8::is_ascii_digit) {
        return None;
    }
    let y: i64 = ts[0..4].parse().ok()?;
    let mo: u32 = ts[4..6].parse().ok()?;
    let d: u32 = ts[6..8].parse().ok()?;
    let h: u64 = ts[8..10].parse().ok()?;
    let mi: u64 = ts[10..12].parse().ok()?;
    let s: u64 = ts[12..14].parse().ok()?;
    let days = days_from_civil(y, mo, d);
    if days < 0 {
        return None;
    }
    Some((days as u64) * 86_400 + h * 3600 + mi * 60 + s)
}

fn now_epoch() -> u64 {
    if let Ok(raw) = std::env::var("TYPEDPRESS_MIGRATION_TIME")
        && let Some(secs) = parse_ts_epoch(&raw)
    {
        return secs;
    }
    #[cfg(not(target_arch = "wasm32"))]
    {
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(1_767_225_600) // 2026-01-01T00:00:00Z
    }
    #[cfg(target_arch = "wasm32")]
    {
        1_767_225_600
    }
}

/// Generates a sortable, collision-free migration ID guaranteed to sort strictly after every ID in
/// `existing_ids`.
pub fn next_id(existing_ids: &[String], ops: &[Json], slug: &str) -> String {
    let max_existing = existing_ids.iter().filter_map(|id| parse_ts_epoch(id)).max();
    let mut ts_secs = now_epoch();
    if let Some(prev) = max_existing
        && ts_secs <= prev
    {
        ts_secs = prev + 1;
    }
    let ts = format_ts(ts_secs);
    let h = hash6(ops);
    let slug = sanitize_slug(slug);
    format!("{ts}_{h}_{slug}")
}

/// Derives a concise, human-readable slug from the planned ops.
fn derive_slug(ops: &[Json], first: bool) -> String {
    if first {
        return "init".to_string();
    }
    let significant: Vec<&Json> = ops
        .iter()
        .filter(|op| op["op"].as_str() != Some("updateTableMeta"))
        .collect();
    if significant.len() == 1 {
        let op = significant[0];
        let tbl = op["table"].as_str().unwrap_or("");
        let col = op["column"].as_str().unwrap_or("");
        return match op["op"].as_str().unwrap_or("") {
            "createTable" => sanitize_slug(&format!("create_{tbl}")),
            "addColumn" => sanitize_slug(&format!("add_{tbl}_{col}")),
            "modifyColumn" => sanitize_slug(&format!("alter_{tbl}_{col}")),
            "retireColumn" => sanitize_slug(&format!("retire_{tbl}_{col}")),
            "unretireColumn" => sanitize_slug(&format!("restore_{tbl}_{col}")),
            "retireTable" => sanitize_slug(&format!("retire_{tbl}")),
            "addIndex" | "dropIndex" => sanitize_slug(&format!("index_{tbl}")),
            "addForeignKey" | "dropForeignKey" => sanitize_slug(&format!("fk_{tbl}")),
            _ => "update".to_string(),
        };
    }
    if !significant.is_empty()
        && significant
            .iter()
            .all(|op| matches!(op["op"].as_str(), Some("addIndex" | "dropIndex")))
        && let Some(tbl) = significant[0]["table"].as_str()
        && significant.iter().all(|op| op["table"].as_str() == Some(tbl))
    {
        return sanitize_slug(&format!("index_{tbl}"));
    }
    "update".to_string()
}

/// The persisted shape of the schema: only what affects SQL.
pub fn snapshot(schema: &Schema) -> Json {
    snapshot_with(schema, &std::collections::BTreeSet::new())
}

/// The snapshot with `queried`, the `(table, column)` pairs compiled `where`/`orderBy` name, as each
/// SQL table's `queried` list: the columns that get an inferred index.
pub fn snapshot_with(schema: &Schema, queried: &std::collections::BTreeSet<(String, String)>) -> Json {
    let mut tables = serde_json::Map::new();
    // A taxonomy lives in core's term tables: nothing about it enters the snapshot.
    for c in schema
        .collections
        .values()
        .filter(|c| c.taxonomy.is_none() && c.commerce.is_none())
    {
        let mut t = table_snapshot(c, schema);
        let mut cols: Vec<String> = queried
            .iter()
            .filter(|(table, col)| table == &c.table && c.columns.contains_key(col))
            .map(|(_, col)| c.columns[col].physical_name().to_string())
            .collect();
        cols.sort();
        cols.dedup();
        if !cols.is_empty() {
            t["queried"] = json!(cols);
        }
        if !c.indexes.is_empty() {
            t["indexes"] = c
                .indexes
                .iter()
                .map(|(fields, unique)| {
                    let physical_fields: Vec<String> = fields
                        .iter()
                        .map(|f| {
                            c.columns
                                .get(f)
                                .map_or_else(|| f.clone(), |col| col.physical_name().to_string())
                        })
                        .collect();
                    json!({ "columns": physical_fields, "unique": unique })
                })
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

/// A `ref().many()` field's join table: `{prefix}tp__join_<owner>_<field>`.
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
        // Key by physical SQL column name so `.column("old_name")` produces zero DDL!
        cols.insert(col.physical_name().to_string(), column_snapshot(col, schema));
    }
    let mut table = json!({ "editor": c.editor, "drafts": c.drafts, "columns": cols });
    if c.soft_delete {
        table["softDelete"] = true.into();
    }
    if let Some(name) = &c.existing_table {
        table["existingTable"] = name.clone().into();
    }
    if c.auth {
        table["auth"] = true.into();
    }
    if c.localized {
        let mut group = crate::schema::Column::new("translationGroup", crate::schema::ColType::Text);
        group.system = true;
        let mut snap = column_snapshot(&group, schema);
        snap["max"] = json!(36);
        cols_insert(&mut table, "translationGroup", snap);
        table["localized"] = json!(true);
    }
    table
}

fn column_snapshot(col: &Column, schema: &Schema) -> Json {
    let references = col.references.as_ref().map(|r| {
        let target = &schema.collections[&r.collection];
        let target_col = target.columns.get(&r.column);
        let unsigned = target_col.is_some_and(|t| t.auto_increment);
        let physical_target_col = target_col.map_or(r.column.as_str(), Column::physical_name);
        json!({
            "table": r.collection,
            "column": physical_target_col,
            "onDelete": r.on_delete.map(|a| a.sql()),
            "onUpdate": r.on_update.map(|a| a.sql()),
            "unsigned": unsigned,
        })
    });
    json!({
        "type": col.ty.name(),
        "notNull": col.not_null,
        "primaryKey": col.primary_key,
        "autoIncrement": col.auto_increment,
        "unique": col.unique,
        "max": col.rules.max.filter(|_| col.ty.is_stringy()),
        "enum": col.enum_values.as_ref().filter(|_| col.ty == crate::schema::ColType::Text),
        "default": sql_default(col),
        "references": references,
    })
}

fn sql_default(col: &Column) -> Json {
    match &col.default {
        Some(DefaultKind::Value(v)) if !v.is_null() => v.clone(),
        // `defaultNow()` timestamps are populated by the runtime on every insert; giving the SQL column
        // a valid ISO-8601 fallback lets forward-only migrations add required timestamp columns safely.
        Some(DefaultKind::Now) => Json::String("1970-01-01T00:00:00.000Z".into()),
        _ => Json::Null,
    }
}

fn cols_insert(table: &mut Json, name: &str, col: Json) {
    if let Some(cols) = table["columns"].as_object_mut() {
        cols.insert(name.into(), col);
    }
}

/// The enriched revisions system table definition (ADR 2026-11-21 §5).
fn revisions_table_def() -> Json {
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
    pk["max"] = json!(191);
    cols.insert("pk".into(), pk);
    let mut version = c("integer", true);
    version["default"] = json!(1);
    cols.insert("version".into(), version);
    let mut op = c("text", true);
    op["max"] = json!(16);
    op["default"] = json!("update");
    cols.insert("op".into(), op);
    cols.insert("at".into(), c("timestamp", true));
    cols.insert("userId".into(), c("integer", false));
    let mut schema_migration = c("text", false);
    schema_migration["max"] = json!(191);
    cols.insert("schemaMigration".into(), schema_migration);
    cols.insert("row".into(), c("json", true));
    cols.insert("changed".into(), c("json", false));
    json!({ "editor": false, "drafts": false, "system": true, "columns": cols })
}

/// The SQL tables a snapshot holds: custom tables, join tables, plus `{prefix}tp__revisions` once
/// there is any collection.
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
    if !tables.is_empty() && !out.contains_key(REVISIONS) {
        out.insert(REVISIONS.into(), revisions_table_def());
    }
    out
}

/// SQL tables already recorded inside a `FoldedState` (does not synthesize `tp__revisions` unless
/// a prior migration actually created it).
fn folded_sql_tables(state: &FoldedState) -> Map {
    let mut out = Map::new();
    for (t, def) in &state.tables {
        if def["editor"].as_bool() == Some(false) && def["auth"].as_bool() != Some(true) {
            out.insert(t.clone(), def.clone());
        }
    }
    out
}

fn unsigned_integer(c: &Json) -> bool {
    (c["primaryKey"] == true && c["autoIncrement"] == true) || c["references"]["unsigned"] == true
}

/// A custom table's column as DDL (foreign keys are rendered separately, as constraints).
pub fn column_sql(name: &str, c: &Json) -> String {
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
        return None;
    }
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
    let n = format!("tp_{t}_{col}_fk");
    n.chars().take(64).collect()
}

/// Every index the schema's SQL tables get, and why it exists, for `typedpress check --json`.
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
            let declared_cols: Vec<&str> = ix_cols.iter().map(String::as_str).filter(|c| *c != "_live").collect();
            let declared = def["indexes"].as_array().into_iter().flatten().any(|ix| {
                ix["columns"]
                    .as_array()
                    .is_some_and(|c| c.iter().filter_map(Json::as_str).eq(declared_cols.iter().copied()))
                    && (ix["unique"].as_bool() == Some(true)) == unique
            });
            let reason = if declared {
                format!(
                    "declared with .index([{}]{})",
                    declared_cols
                        .iter()
                        .map(|c| format!("\"{c}\""))
                        .collect::<Vec<_>>()
                        .join(", "),
                    if unique { ", { unique: true }" } else { "" }
                )
            } else if name == "deletedAt_idx" {
                "softDelete: retention sweep and deleted queries".to_string()
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
    let soft_delete = def["softDelete"].as_bool() == Some(true) && cols.contains_key("_live");
    for (name, c) in cols {
        if c["unique"].as_bool() == Some(true) && c["primaryKey"].as_bool() != Some(true) {
            // Soft-deletable tables scope unique constraints to active rows via `_live` (NULL on soft-delete),
            // except sync `uid` which stays globally unique across trashed rows so restore/sync never collide.
            let ix_cols = if soft_delete && name != "uid" {
                vec![name.clone(), "_live".into()]
            } else {
                vec![name.clone()]
            };
            out.push((format!("{name}_unique"), true, ix_cols));
        }
    }
    if soft_delete && cols.contains_key("deletedAt") {
        out.push(("deletedAt_idx".to_string(), false, vec!["deletedAt".into()]));
    }
    if def["drafts"].as_bool() == Some(true) && cols.contains_key("status") && cols.contains_key("publishedAt") {
        out.push((
            "status_publishedAt".to_string(),
            false,
            vec!["status".into(), "publishedAt".into()],
        ));
    }
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
        if cols.contains_key("version") {
            out.push((
                "row_version".into(),
                true,
                vec!["collection".into(), "pk".into(), "version".into()],
            ));
        }
    }
    for ix in def["indexes"].as_array().into_iter().flatten() {
        let mut fields: Vec<String> = ix["columns"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|c| c.as_str().map(str::to_owned))
            .collect();
        if !fields.is_empty() && fields.iter().all(|f| cols.get(f).is_some_and(indexable)) {
            let unique = ix["unique"].as_bool() == Some(true);
            let name = format!("idx_{}", fields.join("_"));
            if unique && soft_delete && !fields.iter().any(|f| f == "_live") {
                fields.push("_live".into());
            }
            if !out.iter().any(|(n, _, _)| n == &name) {
                out.push((name, unique, fields));
            }
        }
    }
    let pk = cols
        .iter()
        .find(|(_, c)| c["primaryKey"].as_bool() == Some(true))
        .map(|(n, _)| n.clone());
    let referenced = |n: &str| cols.get(n).is_some_and(|c| c["references"].is_object());
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
    create_table_with_fks(t, def, tables, None)
}

fn create_table_with_fks(
    t: &str,
    def: &Json,
    tables: &Map,
    deferred_targets: Option<&std::collections::HashSet<&str>>,
) -> String {
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
        let adoption = json!({ "table": def["existingTable"], "indexes": expected_indexes,
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
        if let Some(deferred) = deferred_targets
            && let Some(target) = c["references"]["table"].as_str()
            && target != t
            && deferred.contains(target)
        {
            continue;
        }
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

/// For a column whose stored type changes from `before` to `after`, the SQL condition (over `{col}`)
/// a value must meet to survive the change, or `None` when every value of `before` fits `after`
/// (a lossless widening).
pub fn fits(before: &Json, after: &Json) -> Option<String> {
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
    let round_trip = |cast: &str| format!("BINARY CAST(CAST({col} AS {cast}) AS CHAR) = BINARY {col}");
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
            (Some(a), Some(b)) if a <= b && (!char_target || render(before).contains("` CHAR(")) => None,
            (_, Some(b)) => Some(fits_width(b)),
        },
        (_, "text") => width(after).map(fits_width),
        ("unsigned", "bigint") => Some(format!("{col} <= 9223372036854775807")),
        ("bigint", "unsigned") => Some(format!("{col} >= 0")),
        ("boolean", "unsigned") => Some(format!("{col} >= 0")),
        ("boolean", "bigint" | "double") => None,
        ("bigint" | "unsigned", "double") => Some(format!("{col} BETWEEN -9007199254740992 AND 9007199254740992")),
        ("double", "bigint") => Some(format!(
            "{col} = FLOOR({col}) AND {col} BETWEEN -9007199254740992 AND 9007199254740992"
        )),
        ("double", "unsigned") => Some(format!("{col} = FLOOR({col}) AND {col} BETWEEN 0 AND 9007199254740992")),
        ("text", "boolean") => Some(format!("BINARY {col} IN ('0', '1')")),
        (_, "boolean") => Some(format!("{col} IN (0, 1)")),
        ("text", "bigint") => Some(round_trip("SIGNED")),
        ("text", "unsigned") => Some(format!(
            "{} AND CAST({col} AS UNSIGNED) <= 9223372036854775807",
            round_trip("UNSIGNED")
        )),
        ("text", "double") => Some(format!(
            "{col} REGEXP BINARY '^-?(0|[1-9][0-9]*)([.][0-9]*[1-9])?$' AND BINARY {col} <> '-0' AND LOCATE(CHAR(10), {col}) = 0 AND CHAR_LENGTH(REPLACE(REPLACE(TRIM(LEADING '-' FROM {col}), '.', ''), '-', '')) <= 15"
        )),
        _ => Some("false".into()),
    }
}

/// The SQL literal of a column's constant default.
pub fn default_literal(ty: &str, v: &Json) -> String {
    match (ty, v) {
        ("boolean", Json::Bool(b)) => (if *b { "1" } else { "0" }).to_string(),
        (_, Json::Number(n)) => n.to_string(),
        (_, Json::String(s)) => string(s),
        (_, v) => string(&v.to_string()),
    }
}

/// Folds a set of `(migration_id, ops_json)` entries in sorted ID order into the baseline
/// [`FoldedState`], detecting incompatible parallel-branch edits at build time.
pub fn fold(entries: &[(String, Json)]) -> Result<FoldedState, Diag> {
    let mut sorted: Vec<&(String, Json)> = entries.iter().collect();
    sorted.sort_by(|a, b| a.0.cmp(&b.0));

    let mut state = FoldedState::empty();
    let mut table_origin: BTreeMap<String, String> = BTreeMap::new();
    let mut retired_table_origin: BTreeMap<String, String> = BTreeMap::new();
    let mut col_origin: BTreeMap<(String, String), String> = BTreeMap::new();

    for (id, doc) in sorted {
        if doc["version"] != 1 {
            return Err(Diag::new(
                Span::NONE,
                format!(
                    "migration `{id}/ops.json` has unsupported version {}; expected 1",
                    doc["version"]
                ),
            )
            .code(Code::Schema));
        }
        let Some(ops) = doc["ops"].as_array() else {
            return Err(Diag::new(
                Span::NONE,
                format!("migration `{id}/ops.json` is missing an `ops` array"),
            )
            .code(Code::Schema));
        };
        for op in ops {
            let kind = op["op"].as_str().unwrap_or("");
            match kind {
                "createTable" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    let def = op["def"].clone();
                    if let Some(prev_id) = table_origin.get(&t)
                        && state.tables.contains_key(&t)
                    {
                        return Err(Diag::new(
                            Span::NONE,
                            format!("conflicting parallel migrations: `{prev_id}` and `{id}` both create table `{t}`"),
                        )
                        .help(
                            "resolve the schema conflict by removing or reconciling one of the conflicting migrations",
                        )
                        .code(Code::Schema));
                    }
                    if let Some(prev_id) = retired_table_origin.get(&t)
                        && state.retired_tables.contains_key(&t)
                    {
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{prev_id}` retired table `{t}` while `{id}` creates it"
                            ),
                        )
                        .help("choose a distinct `storageName`, or purge the retired table with `typedpress/danger` (`wp typedpress danger drop-table`) first")
                        .code(Code::Schema));
                    }
                    if let Some(cols) = def["columns"].as_object() {
                        for col in cols.keys() {
                            col_origin.insert((t.clone(), col.clone()), id.clone());
                        }
                    }
                    state.tables.insert(t.clone(), def);
                    table_origin.insert(t, id.clone());
                }
                "addColumn" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    let col = op["column"].as_str().unwrap_or("").to_string();
                    let def = op["def"].clone();
                    if let Some(ret_id) = retired_table_origin.get(&t)
                        && state.retired_tables.contains_key(&t)
                    {
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{ret_id}` retired table `{t}` while `{id}` adds column `{col}` to it"
                            ),
                        )
                        .help("reconcile the merged branches before building")
                        .code(Code::Schema));
                    }
                    let Some(table_def) = state.tables.get_mut(&t) else {
                        return Err(Diag::new(
                            Span::NONE,
                            format!("migration `{id}` adds column `{t}.{col}`, but table `{t}` does not exist"),
                        )
                        .code(Code::Schema));
                    };
                    let cols = table_def["columns"].as_object_mut().expect("table columns object");
                    if cols.contains_key(&col) {
                        let prev_id = col_origin
                            .get(&(t.clone(), col.clone()))
                            .map(String::as_str)
                            .unwrap_or("an earlier migration");
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{prev_id}` and `{id}` both add column `{t}.{col}`"
                            ),
                        )
                        .help("rename one column with `.column(...)` or reconcile the two parallel migrations")
                        .code(Code::Schema));
                    }
                    cols.insert(col.clone(), def);
                    col_origin.insert((t, col), id.clone());
                }
                "modifyColumn" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    let col = op["column"].as_str().unwrap_or("").to_string();
                    let to = op["to"].clone();
                    if let Some(ret_id) = retired_table_origin.get(&t)
                        && state.retired_tables.contains_key(&t)
                    {
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{ret_id}` retired table `{t}` while `{id}` alters `{col}`"
                            ),
                        )
                        .code(Code::Schema));
                    }
                    let Some(table_def) = state.tables.get_mut(&t) else {
                        return Err(Diag::new(
                            Span::NONE,
                            format!("migration `{id}` modifies `{t}.{col}`, but table `{t}` does not exist"),
                        )
                        .code(Code::Schema));
                    };
                    let cols = table_def["columns"].as_object_mut().expect("table columns object");
                    let Some(current) = cols.get(&col) else {
                        let prev_id = col_origin
                            .get(&(t.clone(), col.clone()))
                            .map(String::as_str)
                            .unwrap_or("an earlier migration");
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{prev_id}` retired `{t}.{col}` while `{id}` alters it"
                            ),
                        )
                        .code(Code::Schema));
                    };
                    if !op["from"].is_null() && column_sql(&col, current) != column_sql(&col, &op["from"]) {
                        let prev_id = col_origin
                            .get(&(t.clone(), col.clone()))
                            .map(String::as_str)
                            .unwrap_or("an earlier migration");
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "conflicting parallel migrations: `{prev_id}` and `{id}` both modified `{t}.{col}` incompatibly"
                            ),
                        )
                        .help("reconcile the conflicting column change between the two merged branches")
                        .code(Code::Schema));
                    }
                    cols.insert(col.clone(), to);
                    col_origin.insert((t, col), id.clone());
                }
                "retireColumn" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    let col = op["column"].as_str().unwrap_or("").to_string();
                    if let Some(table_def) = state.tables.get_mut(&t)
                        && let Some(cols) = table_def["columns"].as_object_mut()
                        && let Some(mut old_def) = cols.shift_remove(&col)
                    {
                        // Match the actual DDL emitted on retirement: NOT NULL is relaxed only
                        // when the column had no literal default (columns with a default keep
                        // their NOT NULL constraint in the live database).
                        if old_def["notNull"] == true && old_def["primaryKey"] != true && old_def["default"].is_null() {
                            old_def["notNull"] = json!(false);
                        }
                        old_def["unique"] = json!(false);
                        old_def["references"] = Json::Null;
                        state
                            .retired_columns
                            .entry(t.clone())
                            .or_default()
                            .insert(col.clone(), old_def);
                        col_origin.insert((t, col), id.clone());
                    }
                }
                "unretireColumn" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    let col = op["column"].as_str().unwrap_or("").to_string();
                    let to = op["to"].clone();
                    if let Some(retired) = state.retired_columns.get_mut(&t) {
                        retired.shift_remove(&col);
                    }
                    if let Some(table_def) = state.tables.get_mut(&t)
                        && let Some(cols) = table_def["columns"].as_object_mut()
                    {
                        cols.insert(col.clone(), to);
                        col_origin.insert((t, col), id.clone());
                    }
                }
                "retireTable" => {
                    let t = op["table"].as_str().unwrap_or("").to_string();
                    if let Some(def) = state.tables.shift_remove(&t) {
                        state.retired_tables.insert(t.clone(), def);
                        retired_table_origin.insert(t, id.clone());
                    }
                }
                "purgeRetiredColumn" => {
                    let t = op["table"].as_str().unwrap_or("");
                    let col = op["column"].as_str().unwrap_or("");
                    if let Some(retired) = state.retired_columns.get_mut(t) {
                        retired.shift_remove(col);
                    }
                }
                "purgeRetiredTable" => {
                    let t = op["table"].as_str().unwrap_or("");
                    state.retired_tables.shift_remove(t);
                    state.retired_columns.remove(t);
                }
                "updateTableMeta" => {
                    let t = op["table"].as_str().unwrap_or("");
                    if let Some(table_def) = state.tables.get_mut(t)
                        && let Some(obj) = table_def.as_object_mut()
                        && let Some(meta) = op["meta"].as_object()
                    {
                        for (k, v) in meta {
                            match k.as_str() {
                                "addIndexes" => {
                                    let mut arr =
                                        obj.get("indexes").and_then(Json::as_array).cloned().unwrap_or_default();
                                    for item in v.as_array().into_iter().flatten() {
                                        if !arr.contains(item) {
                                            arr.push(item.clone());
                                        }
                                    }
                                    obj.insert("indexes".into(), Json::Array(arr));
                                }
                                "removeIndexes" => {
                                    let mut arr =
                                        obj.get("indexes").and_then(Json::as_array).cloned().unwrap_or_default();
                                    if let Some(rm) = v.as_array() {
                                        arr.retain(|item| !rm.contains(item));
                                    }
                                    if arr.is_empty() {
                                        obj.shift_remove("indexes");
                                    } else {
                                        obj.insert("indexes".into(), Json::Array(arr));
                                    }
                                }
                                "addQueried" => {
                                    let mut arr =
                                        obj.get("queried").and_then(Json::as_array).cloned().unwrap_or_default();
                                    for item in v.as_array().into_iter().flatten() {
                                        if !arr.contains(item) {
                                            arr.push(item.clone());
                                        }
                                    }
                                    obj.insert("queried".into(), Json::Array(arr));
                                }
                                "removeQueried" => {
                                    let mut arr =
                                        obj.get("queried").and_then(Json::as_array).cloned().unwrap_or_default();
                                    if let Some(rm) = v.as_array() {
                                        arr.retain(|item| !rm.contains(item));
                                    }
                                    if arr.is_empty() {
                                        obj.shift_remove("queried");
                                    } else {
                                        obj.insert("queried".into(), Json::Array(arr));
                                    }
                                }
                                _ => {
                                    if v.is_null() {
                                        obj.shift_remove(k);
                                    } else {
                                        obj.insert(k.clone(), v.clone());
                                    }
                                }
                            }
                        }
                    }
                }
                "addIndex" => {
                    let t = op["table"].as_str().unwrap_or("");
                    if let Some(retired) = state.retired_columns.get(t)
                        && let Some(ix_cols) = op["index"]["columns"].as_array()
                    {
                        for c in ix_cols.iter().filter_map(Json::as_str) {
                            if retired.contains_key(c) {
                                let prev_id = col_origin
                                    .get(&(t.to_string(), c.to_string()))
                                    .map(String::as_str)
                                    .unwrap_or("an earlier migration");
                                return Err(Diag::new(
                                    Span::NONE,
                                    format!(
                                        "conflicting parallel migrations: `{prev_id}` retired `{t}.{c}` while `{id}` adds an index on it"
                                    ),
                                )
                                .help("remove the index or restore the column before building")
                                .code(Code::Schema));
                            }
                        }
                    }
                }
                "dropIndex" | "addForeignKey" | "dropForeignKey" => {
                    // Audited in ops.json; state is carried by columns + updateTableMeta.
                }
                other => {
                    return Err(Diag::new(
                        Span::NONE,
                        format!("migration `{id}/ops.json` contains unknown op `{other}`"),
                    )
                    .code(Code::Schema));
                }
            }
        }
        state.history.push(id.clone());
    }

    Ok(state)
}

/// Plans a strictly forward-only migration from the folded baseline `prev` to `next`.
///
/// Returns `Ok(None)` when no SQL schema changes are needed, `Ok(Some((entry, warnings)))` when a
/// new migration folder should be emitted, or `Err(Diag)` when the requested schema change violates
/// forward-only safety (e.g. adding a required column without a literal default, or lossy type
/// narrowing).
pub fn plan_forward_only(
    prev: &FoldedState,
    next: &Json,
    existing_ids: &[String],
) -> Result<Option<(MigrationEntry, Vec<String>)>, Diag> {
    let prev_t = folded_sql_tables(prev);
    let next_t = tables(next);
    let empty = Map::new();
    let mut ops: Vec<Json> = Vec::new();
    let mut statements: Vec<String> = Vec::new();
    let mut warnings: Vec<String> = Vec::new();

    // 1. New SQL tables, in FK dependency order (referenced tables first).
    let mut created: Vec<&String> = Vec::new();
    let mut pending: Vec<&String> = next_t.keys().filter(|t| !prev_t.contains_key(*t)).collect();
    for t in &pending {
        if prev.retired_tables.contains_key(*t) {
            return Err(Diag::new(
                Span::NONE,
                format!("table `{t}` was retired in an earlier migration and still exists in the database"),
            )
            .help(
                "give the new table a distinct `storageName`, or purge the retired table first with `typedpress/danger`"
                    .to_string(),
            )
            .code(Code::Schema));
        }
    }
    let mut cyclic: Vec<&String> = Vec::new();
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
            cyclic.append(&mut pending);
            break;
        }
    }
    for t in &created {
        ops.push(json!({
            "op": "createTable",
            "table": t,
            "physicalTable": physical_table_name(t, &next_t),
            "def": next_t[*t],
        }));
        statements.push(create_table(t, &next_t[*t], &next_t));
    }
    if !cyclic.is_empty() {
        let cyclic_set: std::collections::HashSet<&str> = cyclic.iter().map(|s| s.as_str()).collect();
        for t in &cyclic {
            ops.push(json!({
                "op": "createTable",
                "table": t,
                "physicalTable": physical_table_name(t, &next_t),
                "def": next_t[*t],
            }));
            statements.push(create_table_with_fks(t, &next_t[*t], &next_t, Some(&cyclic_set)));
        }
        for t in &cyclic {
            let tbl_sql = table(t, &next_t);
            for (k, c) in next_t[*t]["columns"].as_object().unwrap_or(&empty) {
                if let Some(target) = c["references"]["table"].as_str()
                    && target != t.as_str()
                    && cyclic_set.contains(target)
                    && let Some(fk) = fk_sql(t, k, c, &next_t)
                {
                    ops.push(json!({ "op": "addForeignKey", "table": t, "column": k, "name": fk_name(t, k) }));
                    statements.push(format!("ALTER TABLE {tbl_sql} ADD {fk};"));
                }
            }
        }
    }

    // 2. Existing SQL tables: strictly forward-only alterations + retire removed columns.
    for (t, next_def) in &next_t {
        let Some(prev_def) = prev_t.get(t) else { continue };
        let pc = prev_def["columns"].as_object().unwrap_or(&empty);
        let nc = next_def["columns"].as_object().unwrap_or(&empty);
        let p_ix = indexes(t, prev_def);
        let n_ix = indexes(t, next_def);
        let tbl_sql = table(t, &next_t);
        let retired_for_table = prev.retired_columns.get(t);

        // Drop foreign keys that changed or whose column is being retired.
        for (k, c) in pc {
            let before = fk_sql(t, k, c, &prev_t);
            let after = nc.get(k).and_then(|nc| fk_sql(t, k, nc, &next_t));
            if before.is_some() && before != after {
                let name = fk_name(t, k);
                ops.push(json!({ "op": "dropForeignKey", "table": t, "column": k, "name": name }));
                statements.push(format!("ALTER TABLE {tbl_sql} DROP FOREIGN KEY `{name}`;"));
            }
        }

        // Drop indexes no longer declared/queried (or referencing retired columns).
        for ix in &p_ix {
            if !n_ix.contains(ix) {
                ops.push(json!({ "op": "dropIndex", "table": t, "name": ix.0 }));
                statements.push(drop_index(t, &ix.0, &next_t));
            }
        }

        // Added or modified columns.
        for (k, c) in nc {
            let sql = column_sql(k, c);
            let required = c["notNull"] == true && c["primaryKey"] != true;
            match pc.get(k) {
                None => {
                    // Was this physical column retired earlier?
                    if let Some(old_col) = retired_for_table.and_then(|m| m.get(k)) {
                        if fits(old_col, c).is_some() {
                            return Err(Diag::new(
                                Span::NONE,
                                format!(
                                    "column `{t}.{k}` was retired in an earlier migration with an incompatible type ({} -> {})",
                                    column_sql(k, old_col),
                                    sql
                                ),
                            )
                            .help(format!(
                                "choose a new physical column name with `.column(\"{k}_v2\")`, or purge the retired column with `typedpress/danger` first"
                            ))
                            .code(Code::Schema));
                        }
                        if required && c["default"].is_null() {
                            return Err(Diag::new(
                                Span::NONE,
                                format!(
                                    "cannot restore retired column `{t}.{k}` as required without a literal default"
                                ),
                            )
                            .help("make it `.optional()` or add a literal `.default(...)` so existing NULL rows are filled")
                            .code(Code::Schema));
                        }
                        if required && !c["default"].is_null() {
                            let ty = c["type"].as_str().unwrap_or("text");
                            statements.push(format!(
                                "UPDATE {tbl_sql} SET {} = {} WHERE {} IS NULL;",
                                q(k),
                                default_literal(ty, &c["default"]),
                                q(k)
                            ));
                        }
                        if column_sql(k, old_col) != sql {
                            statements.push(format!("ALTER TABLE {tbl_sql} MODIFY COLUMN {sql};"));
                        }
                        ops.push(json!({ "op": "unretireColumn", "table": t, "column": k, "to": c }));
                        continue;
                    }
                    if required && c["default"].is_null() {
                        return Err(Diag::new(
                            Span::NONE,
                            format!(
                                "cannot add required column `{t}.{k}` without a literal default in a forward-only migration"
                            ),
                        )
                        .help(format!(
                            "make `{k}` optional with `.optional()`, add a literal `.default(...)`, or add it nullable, backfill existing rows, then tighten"
                        ))
                        .code(Code::Schema));
                    }
                    ops.push(json!({ "op": "addColumn", "table": t, "column": k, "def": c }));
                    statements.push(format!("ALTER TABLE {tbl_sql} ADD COLUMN {sql};"));
                }
                Some(prev_col) => {
                    let psql = column_sql(k, prev_col);
                    if psql != sql {
                        if fits(prev_col, c).is_some() {
                            return Err(Diag::new(
                                Span::NONE,
                                format!(
                                    "narrowing `{t}.{k}` from `{psql}` to `{sql}` can lose or corrupt existing data and is refused by forward-only migrations"
                                ),
                            )
                            .help("add a new column and backfill via a data migration, or opt into `typedpress/danger` for an explicit narrowing migration")
                            .code(Code::Schema));
                        }
                        let tightens = required && prev_col["notNull"] != true;
                        if tightens && c["default"].is_null() {
                            return Err(Diag::new(
                                Span::NONE,
                                format!(
                                    "making `{t}.{k}` required without a literal default is refused by forward-only migrations"
                                ),
                            )
                            .help("add a literal `.default(...)` so existing NULL rows are backfilled safely, or keep it `.optional()`")
                            .code(Code::Schema));
                        }
                        if tightens && !c["default"].is_null() {
                            let ty = c["type"].as_str().unwrap_or("text");
                            statements.push(format!(
                                "UPDATE {tbl_sql} SET {} = {} WHERE {} IS NULL;",
                                q(k),
                                default_literal(ty, &c["default"]),
                                q(k)
                            ));
                        }
                        statements.push(format!("ALTER TABLE {tbl_sql} MODIFY COLUMN {sql};"));
                        ops.push(json!({ "op": "modifyColumn", "table": t, "column": k, "from": prev_col, "to": c }));
                    } else if prev_col != c {
                        // Non-DDL column metadata changed (e.g., unique/references/enum): sync in ops.json.
                        ops.push(json!({ "op": "modifyColumn", "table": t, "column": k, "from": prev_col, "to": c }));
                    }
                }
            }
        }

        // Retired columns: NEVER drop! Relax NOT NULL without default so future inserts succeed.
        for (k, prev_col) in pc {
            if !nc.contains_key(k) {
                let needs_relax =
                    prev_col["notNull"] == true && prev_col["primaryKey"] != true && prev_col["default"].is_null();
                if needs_relax {
                    let mut relaxed = prev_col.clone();
                    relaxed["notNull"] = json!(false);
                    relaxed["unique"] = json!(false);
                    let relaxed_sql = column_sql(k, &relaxed);
                    statements.push(format!("ALTER TABLE {tbl_sql} MODIFY COLUMN {relaxed_sql};"));
                }
                ops.push(json!({ "op": "retireColumn", "table": t, "column": k }));
                warnings.push(format!(
                    "retiring column \"{t}\".\"{k}\" (data preserved; purge later with typedpress/danger)"
                ));
            }
        }

        // Added indexes.
        for ix in &n_ix {
            if !p_ix.contains(ix) {
                ops.push(
                    json!({ "op": "addIndex", "table": t, "index": { "name": ix.0, "unique": ix.1, "columns": ix.2 } }),
                );
                statements.push(create_index(t, ix, &next_t));
            }
        }

        // Added or updated foreign keys.
        for (k, c) in nc {
            let before = pc.get(k).and_then(|pc| fk_sql(t, k, pc, &prev_t));
            if let Some(after) = fk_sql(t, k, c, &next_t)
                && before.as_ref() != Some(&after)
            {
                ops.push(json!({ "op": "addForeignKey", "table": t, "column": k, "name": fk_name(t, k) }));
                statements.push(format!("ALTER TABLE {tbl_sql} ADD {after};"));
            }
        }

        // Sync table-level non-column metadata (`drafts`, `localized`, `softDelete`, and set deltas
        // for `indexes` / `queried` so parallel branch additions fold without overwriting each other).
        let mut meta_diff = Map::new();
        for key in ["drafts", "localized", "softDelete"] {
            let prev_v = prev_def.get(key).cloned().unwrap_or(Json::Null);
            let next_v = next_def.get(key).cloned().unwrap_or(Json::Null);
            if prev_v != next_v {
                meta_diff.insert(key.into(), next_v);
            }
        }
        let prev_idx = prev_def.get("indexes").and_then(Json::as_array);
        let next_idx = next_def.get("indexes").and_then(Json::as_array);
        let add_idx: Vec<Json> = next_idx
            .into_iter()
            .flatten()
            .filter(|ix| prev_idx.is_none_or(|p| !p.contains(ix)))
            .cloned()
            .collect();
        let rm_idx: Vec<Json> = prev_idx
            .into_iter()
            .flatten()
            .filter(|ix| next_idx.is_none_or(|n| !n.contains(ix)))
            .cloned()
            .collect();
        if !add_idx.is_empty() {
            meta_diff.insert("addIndexes".into(), Json::Array(add_idx));
        }
        if !rm_idx.is_empty() {
            meta_diff.insert("removeIndexes".into(), Json::Array(rm_idx));
        }
        let prev_q = prev_def.get("queried").and_then(Json::as_array);
        let next_q = next_def.get("queried").and_then(Json::as_array);
        let add_q: Vec<Json> = next_q
            .into_iter()
            .flatten()
            .filter(|q| prev_q.is_none_or(|p| !p.contains(q)))
            .cloned()
            .collect();
        let rm_q: Vec<Json> = prev_q
            .into_iter()
            .flatten()
            .filter(|q| next_q.is_none_or(|n| !n.contains(q)))
            .cloned()
            .collect();
        if !add_q.is_empty() {
            meta_diff.insert("addQueried".into(), Json::Array(add_q));
        }
        if !rm_q.is_empty() {
            meta_diff.insert("removeQueried".into(), Json::Array(rm_q));
        }
        if !meta_diff.is_empty() {
            ops.push(json!({ "op": "updateTableMeta", "table": t, "meta": meta_diff }));
        }
    }

    // 3. Retired SQL tables: NEVER emit DROP TABLE!
    for t in prev_t.keys().filter(|t| !next_t.contains_key(*t)) {
        ops.push(json!({
            "op": "retireTable",
            "table": t,
            "physicalTable": physical_table_name(t, &prev_t),
        }));
        warnings.push(format!(
            "retiring table \"{t}\" (data preserved; purge later with typedpress/danger)"
        ));
    }

    if ops.is_empty() {
        return Ok(None);
    }

    let first = existing_ids.is_empty() && prev.history.is_empty();
    let slug = derive_slug(&ops, first);
    let id = next_id(existing_ids, &ops, &slug);
    let sql = if statements.is_empty() {
        format!("-- Forward-only schema metadata migration ({id}); no SQL DDL required.\n")
    } else {
        render(&statements)
    };
    let ops_doc = json!({
        "version": 1,
        "id": id,
        "ops": ops,
    });
    Ok(Some((MigrationEntry { id, ops: ops_doc, sql }, warnings)))
}

/// A field's `max` changed in a way a development update refuses.
fn max_changes(editor: bool, column: &Json, replacement: &Json) -> bool {
    let (old, new) = (column["max"].as_f64(), replacement["max"].as_f64());
    if editor {
        new.is_some_and(|new| old.is_none_or(|old| new < old))
    } else {
        old != new
    }
}

/// Human-readable warnings for changes that can lose data or drop/retire storage.
pub fn destructive(prev: &Json, next: &Json) -> Vec<String> {
    let empty = Map::new();
    let prev_t = prev["tables"].as_object().unwrap_or(&empty);
    let next_t = next["tables"].as_object().unwrap_or(&empty);
    let mut out = Vec::new();
    for (t, def) in prev_t {
        match next_t.get(t) {
            None => out.push(format!("retires table \"{t}\"")),
            Some(n) => {
                if def["existingTable"] != n["existingTable"] {
                    out.push(format!(
                        "changes the existing SQL table binding for \"{t}\"; rows are not moved"
                    ));
                }
                let wp_table = |d: &Json| d["editor"].as_bool() == Some(false) && d["auth"].as_bool() != Some(true);
                if wp_table(def) == wp_table(n) {
                    for k in def["columns"].as_object().unwrap_or(&empty).keys() {
                        if !n["columns"].as_object().unwrap_or(&empty).contains_key(k) {
                            out.push(format!("retires column \"{t}\".\"{k}\""));
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
                        "retires the WordPress table for \"{t}\" (it becomes {to}; existing rows aren't moved)"
                    ));
                }
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

/// Conservative development updates (`--preserve-data`): existing values and constraints must keep
/// their meanings.
pub fn preservation_problems(prev: &Json, next: &Json) -> Vec<String> {
    if prev["version"] != 2 || !prev["tables"].is_object() {
        return vec!["invalid preservation snapshot".into()];
    }
    let mut problems = destructive(prev, next);
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

    fn id() -> Json {
        json!({ "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null })
    }

    fn apply_step(
        history: &mut Vec<(String, Json)>,
        next: &Json,
    ) -> Result<Option<(MigrationEntry, Vec<String>)>, Diag> {
        let folded = fold(history)?;
        let ids: Vec<String> = history.iter().map(|(id, _)| id.clone()).collect();
        let planned = plan_forward_only(&folded, next, &ids)?;
        if let Some((entry, _)) = &planned {
            history.push((entry.id.clone(), entry.ops.clone()));
        }
        Ok(planned)
    }

    #[test]
    fn forward_only_creates_tables_widens_and_retires_without_dropping() {
        let mut fk = col("integer");
        fk["references"] =
            json!({ "table": "locations", "column": "id", "onDelete": "set null", "onUpdate": null, "unsigned": true });
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({
            "items": table(json!({ "id": id(), "locationId": fk })),
            "locations": table(json!({ "id": id() })),
        }));
        let mut history = Vec::new();
        let (m1, _) = apply_step(&mut history, &v1).unwrap().unwrap();
        assert!(is_migration_id(&m1.id), "{}", m1.id);
        assert!(m1.id.ends_with("_init"), "{}", m1.id);
        let loc_pos = m1.sql.find("CREATE TABLE `{prefix}tp_locations`").unwrap();
        let item_pos = m1.sql.find("CREATE TABLE `{prefix}tp_items`").unwrap();
        assert!(loc_pos < item_pos, "{}", m1.sql);
        assert!(m1.sql.contains("CREATE TABLE `{prefix}tp__revisions`"), "{}", m1.sql);

        // Rebuild with no changes produces nothing.
        assert!(apply_step(&mut history, &v1).unwrap().is_none());

        // Adding a nullable column succeeds.
        let mut v2 = v1.clone();
        v2["tables"]["items"]["columns"]["note"] = col("text");
        let (m2, _) = apply_step(&mut history, &v2).unwrap().unwrap();
        assert!(m2.id > m1.id, "{} > {}", m2.id, m1.id);
        assert!(m2.sql.contains("ADD COLUMN `note` LONGTEXT;"), "{}", m2.sql);

        // Tightening to NOT NULL without a default is refused at build time.
        let mut v3_bad = v2.clone();
        v3_bad["tables"]["items"]["columns"]["note"]["notNull"] = json!(true);
        let err = apply_step(&mut history, &v3_bad).unwrap_err();
        assert!(
            err.message.contains("required without a literal default"),
            "{}",
            err.message
        );

        // Tightening with a literal default backfills and modifies safely.
        let mut v3 = v3_bad.clone();
        v3["tables"]["items"]["columns"]["note"]["default"] = json!("ready");
        let (m3, _) = apply_step(&mut history, &v3).unwrap().unwrap();
        assert!(
            m3.sql
                .contains("UPDATE `{prefix}tp_items` SET `note` = 'ready' WHERE `note` IS NULL;"),
            "{}",
            m3.sql
        );
        assert!(
            m3.sql
                .contains("MODIFY COLUMN `note` LONGTEXT DEFAULT ('ready') NOT NULL;"),
            "{}",
            m3.sql
        );

        // Removing a column and a table RETIRES them (zero DROP COLUMN / DROP TABLE).
        let mut v4 = v3.clone();
        v4["tables"]["items"]["columns"].as_object_mut().unwrap().remove("note");
        v4["tables"].as_object_mut().unwrap().remove("locations");
        let (m4, warnings) = apply_step(&mut history, &v4).unwrap().unwrap();
        assert!(!m4.sql.contains("DROP COLUMN"), "{}", m4.sql);
        assert!(!m4.sql.contains("DROP TABLE"), "{}", m4.sql);
        assert!(
            m4.sql.contains("DROP FOREIGN KEY `tp_items_locationId_fk`"),
            "{}",
            m4.sql
        );
        assert!(
            warnings
                .iter()
                .any(|w| w.contains("retiring column \"items\".\"note\"")),
            "{warnings:?}"
        );
        assert!(
            warnings.iter().any(|w| w.contains("retiring table \"locations\"")),
            "{warnings:?}"
        );

        // Next build after retire is a no-op!
        assert!(apply_step(&mut history, &v4).unwrap().is_none());

        // Folding tracks retired column and table.
        let folded = fold(&history).unwrap();
        assert!(folded.retired_tables.contains_key("locations"));
        assert!(folded.retired_columns["items"].contains_key("note"));
    }

    #[test]
    fn refuses_required_column_without_default_and_lossy_narrowing() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let v1 = snap(json!({ "posts": table(json!({ "id": id(), "title": col("text"), "views": col("integer") })) }));
        let mut history = Vec::new();
        apply_step(&mut history, &v1).unwrap().unwrap();

        let mut add_req = v1.clone();
        let mut req = col("text");
        req["notNull"] = json!(true);
        add_req["tables"]["posts"]["columns"]["code"] = req;
        let err = apply_step(&mut history, &add_req).unwrap_err();
        assert!(
            err.message
                .contains("cannot add required column `posts.code` without a literal default"),
            "{}",
            err.message
        );

        let mut narrow = v1.clone();
        narrow["tables"]["posts"]["columns"]["title"]["max"] = json!(100);
        let err = apply_step(&mut history, &narrow).unwrap_err();
        assert!(err.message.contains("narrowing `posts.title`"), "{}", err.message);

        let mut narrow_ty = v1.clone();
        narrow_ty["tables"]["posts"]["columns"]["views"]["type"] = json!("boolean");
        let err = apply_step(&mut history, &narrow_ty).unwrap_err();
        assert!(err.message.contains("narrowing `posts.views`"), "{}", err.message);
    }

    #[test]
    fn parallel_branch_migrations_fold_cleanly_or_report_exact_conflict() {
        let table = |cols: Json| json!({ "editor": false, "drafts": false, "columns": cols });
        let base = snap(json!({ "orders": table(json!({ "id": id() })) }));
        let mut base_history = Vec::new();
        let (init, _) = apply_step(&mut base_history, &base).unwrap().unwrap();

        // Branch A adds `notes`, Branch B adds `priority` — completely conflict-free!
        let mut branch_a = base.clone();
        branch_a["tables"]["orders"]["columns"]["notes"] = col("text");
        let mut hist_a = base_history.clone();
        let (ma, _) = apply_step(&mut hist_a, &branch_a).unwrap().unwrap();

        let mut branch_b = base.clone();
        branch_b["tables"]["orders"]["columns"]["priority"] = col("integer");
        let mut hist_b = base_history.clone();
        let (mb, _) = apply_step(&mut hist_b, &branch_b).unwrap().unwrap();

        assert_ne!(ma.id, mb.id);
        let merged = vec![
            (init.id.clone(), init.ops.clone()),
            (ma.id.clone(), ma.ops.clone()),
            (mb.id.clone(), mb.ops.clone()),
        ];
        let folded = fold(&merged).unwrap();
        let cols = folded.tables["orders"]["columns"].as_object().unwrap();
        assert!(cols.contains_key("notes") && cols.contains_key("priority"));

        // Branch C ALSO adds `notes` — fold catches the duplicate physical column conflict!
        let mut branch_c = base.clone();
        let mut notes_int = col("integer");
        notes_int["default"] = json!(0);
        branch_c["tables"]["orders"]["columns"]["notes"] = notes_int;
        let mut hist_c = base_history.clone();
        let (mc, _) = apply_step(&mut hist_c, &branch_c).unwrap().unwrap();

        let conflict = fold(&[(init.id, init.ops), (ma.id.clone(), ma.ops), (mc.id.clone(), mc.ops)]).unwrap_err();
        assert!(
            conflict.message.contains("conflicting parallel migrations"),
            "{}",
            conflict.message
        );
        assert!(conflict.message.contains(&ma.id), "{}", conflict.message);
        assert!(conflict.message.contains(&mc.id), "{}", conflict.message);
    }

    #[test]
    fn join_tables_use_dedicated_tp_join_prefix() {
        let mut tables = Map::new();
        tables.insert(
            "posts_tags".into(),
            json!({
                "editor": false,
                "drafts": false,
                "join": { "owner": "posts", "target": "tags" },
                "columns": { "owner_id": col("integer"), "target_id": col("integer"), "position": col("integer") }
            }),
        );
        assert_eq!(
            physical_table_name("posts_tags", &tables),
            "{prefix}tp__join_posts_tags"
        );
    }

    #[test]
    fn text_becoming_number_or_boolean_is_detected_as_lossy() {
        let text = col("text");
        assert_eq!(
            fits(&text, &col("boolean")).as_deref(),
            Some("BINARY {col} IN ('0', '1')")
        );
        assert_eq!(
            fits(&text, &col("integer")).as_deref(),
            Some("BINARY CAST(CAST({col} AS SIGNED) AS CHAR) = BINARY {col}")
        );
        assert!(
            fits(&text, &col("real"))
                .unwrap()
                .contains("LOCATE(CHAR(10), {col}) = 0")
        );
        // Widening VARCHAR(50) -> VARCHAR(191) -> LONGTEXT is lossless.
        let mut v50 = col("text");
        v50["max"] = json!(50);
        let mut v191 = col("text");
        v191["max"] = json!(191);
        assert!(fits(&v50, &v191).is_none());
        assert!(fits(&v191, &text).is_none());
        assert!(fits(&v191, &v50).is_some());
    }

    #[test]
    fn parallel_independent_indexes_fold_without_duplicate_ddl() {
        let mut a = col("text");
        a["max"] = json!(64);
        let mut b = col("text");
        b["max"] = json!(64);
        let base = snap(json!({
            "t": { "editor": false, "drafts": false, "columns": { "id": id(), "a": a, "b": b } }
        }));
        let mut base_hist = Vec::new();
        let (init, _) = apply_step(&mut base_hist, &base).unwrap().unwrap();

        let mut br_a = base.clone();
        br_a["tables"]["t"]["indexes"] = json!([{ "columns": ["a"], "unique": false }]);
        let mut hist_a = base_hist.clone();
        let (ma, _) = apply_step(&mut hist_a, &br_a).unwrap().unwrap();

        let mut br_b = base.clone();
        br_b["tables"]["t"]["indexes"] = json!([{ "columns": ["b"], "unique": false }]);
        let mut hist_b = base_hist.clone();
        let (mb, _) = apply_step(&mut hist_b, &br_b).unwrap().unwrap();

        let mut merged_snap = base.clone();
        merged_snap["tables"]["t"]["indexes"] = json!([
            { "columns": ["a"], "unique": false },
            { "columns": ["b"], "unique": false }
        ]);
        let mut merged_hist = vec![(init.id, init.ops), (ma.id, ma.ops), (mb.id, mb.ops)];
        // Both indexes already exist in the folded state, so the merged build emits ZERO DDL.
        assert!(apply_step(&mut merged_hist, &merged_snap).unwrap().is_none());
    }

    #[test]
    fn enabling_soft_delete_persists_flag_and_next_build_is_noop() {
        let mut slug = col("text");
        slug["max"] = json!(64);
        slug["unique"] = json!(true);
        let v1 = snap(json!({
            "items": { "editor": false, "drafts": false, "softDelete": false, "columns": { "id": id(), "slug": slug } }
        }));
        let mut history = Vec::new();
        apply_step(&mut history, &v1).unwrap().unwrap();

        let mut v2 = v1.clone();
        v2["tables"]["items"]["softDelete"] = json!(true);
        v2["tables"]["items"]["columns"]["deletedAt"] = col("timestamp");
        let mut live = col("boolean");
        live["default"] = json!(true);
        v2["tables"]["items"]["columns"]["_live"] = live;
        let (m2, _) = apply_step(&mut history, &v2).unwrap().unwrap();
        assert!(m2.sql.contains("deletedAt_idx"), "{}", m2.sql);
        // Second unchanged build MUST emit nothing (softDelete folded cleanly).
        assert!(apply_step(&mut history, &v2).unwrap().is_none());
    }

    #[test]
    fn retiring_required_column_with_default_then_restoring_optional_emits_modify() {
        let mut count = col("integer");
        count["notNull"] = json!(true);
        count["default"] = json!(42);
        let v1 = snap(json!({
            "items": { "editor": false, "drafts": false, "columns": { "id": id(), "count": count } }
        }));
        let mut history = Vec::new();
        apply_step(&mut history, &v1).unwrap().unwrap();

        let mut v2 = v1.clone();
        v2["tables"]["items"]["columns"]
            .as_object_mut()
            .unwrap()
            .remove("count");
        let (m2, _) = apply_step(&mut history, &v2).unwrap().unwrap();
        assert!(!m2.sql.contains("MODIFY COLUMN"), "{}", m2.sql);

        // Restore as optional with same default: DB still has NOT NULL, so MODIFY COLUMN MUST run.
        let mut v3 = v1.clone();
        v3["tables"]["items"]["columns"]["count"]["notNull"] = json!(false);
        let (m3, _) = apply_step(&mut history, &v3).unwrap().unwrap();
        assert!(
            m3.sql.contains("MODIFY COLUMN `count` BIGINT DEFAULT 42;"),
            "{}",
            m3.sql
        );
    }

    #[test]
    fn cyclic_foreign_keys_emit_tables_before_alter_table_constraints() {
        let mut fk_b = col("integer");
        fk_b["references"] =
            json!({ "table": "b", "column": "id", "onDelete": "set null", "onUpdate": null, "unsigned": true });
        let mut fk_a = col("integer");
        fk_a["references"] =
            json!({ "table": "a", "column": "id", "onDelete": "set null", "onUpdate": null, "unsigned": true });
        let v1 = snap(json!({
            "a": { "editor": false, "drafts": false, "columns": { "id": id(), "bId": fk_b } },
            "b": { "editor": false, "drafts": false, "columns": { "id": id(), "aId": fk_a } },
        }));
        let mut history = Vec::new();
        let (m1, _) = apply_step(&mut history, &v1).unwrap().unwrap();
        let ca = m1.sql.find("CREATE TABLE `{prefix}tp_a`").unwrap();
        let cb = m1.sql.find("CREATE TABLE `{prefix}tp_b`").unwrap();
        let alt_a = m1.sql.find("ALTER TABLE `{prefix}tp_a` ADD CONSTRAINT").unwrap();
        let alt_b = m1.sql.find("ALTER TABLE `{prefix}tp_b` ADD CONSTRAINT").unwrap();
        assert!(ca < alt_a && cb < alt_a && ca < alt_b && cb < alt_b, "{}", m1.sql);
    }
}
