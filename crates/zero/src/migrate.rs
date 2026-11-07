//! Schema snapshots and migration planning (MySQL dialect; the SQLite Database
//! Integration plugin translates it on SQLite sites).
//!
//! Each build compares the evaluated schema with the last snapshot
//! (`zero/migrations/snapshot.json`, committed with the project) and writes the
//! next numbered migration into `zero/migrations/`.
//!
//! Only `table()` definitions get a table, `{prefix}zero_<table>`, plus
//! the `{prefix}zero__revisions` system table. Post-backed collections live in
//! `wp_posts`/`wp_postmeta` and get no table. Changes are `ALTER TABLE`
//! (add/modify/drop), never rebuilds. Foreign keys exist only when both sides
//! are custom tables (§4.2). `{prefix}` is the WordPress table prefix,
//! substituted by `Wp\Migrator`.

use crate::schema::{Collection, Column, DefaultKind, Schema};
use serde_json::{Value as Json, json};

pub const BREAKPOINT: &str = "--> statement-breakpoint";
/// Snapshot key of the revisions system table.
const REVISIONS: &str = "zero__revisions";

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
        format!("`{{prefix}}zero_{t}`")
    }
}

fn string(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "''"))
}

/// The persisted shape of the schema: only what affects SQL.
pub fn snapshot(schema: &Schema) -> Json {
    let mut tables = serde_json::Map::new();
    for c in schema.collections.values() {
        tables.insert(c.table.clone(), table_snapshot(c, schema));
    }
    json!({ "version": 2, "tables": tables })
}

fn table_snapshot(c: &Collection, schema: &Schema) -> Json {
    let mut cols = serde_json::Map::new();
    // Derived columns (a localized collection's `translations`) are computed on read, never stored.
    for col in c
        .columns
        .values()
        .filter(|col| !(col.derived || col.system && col.name == "translationGroup"))
    {
        cols.insert(col.name.clone(), column_snapshot(col, schema));
    }
    let mut table = json!({ "editor": c.editor, "drafts": c.drafts, "columns": cols });
    if let Some(name) = &c.existing_table {
        table["existingTable"] = name.clone().into();
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
        let v = &c["default"];
        let lit = match (ty, v) {
            ("boolean", Json::Bool(b)) => (if *b { "1" } else { "0" }).to_string(),
            (_, Json::Number(n)) => n.to_string(),
            (_, Json::String(s)) => string(s),
            (_, v) => string(&v.to_string()),
        };
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
    let n = format!("zero_{t}_{col}_fk");
    n.chars().take(64).collect()
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
    if def["system"].as_bool() == Some(true) && t == REVISIONS {
        out.push(("row".into(), false, vec!["collection".into(), "pk".into()]));
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
                    .unwrap_or_else(|| format!("{{prefix}}zero_{target}"));
                Some(json!({ "name": fk_name(t, name), "column": name, "table": target_name,
                "target": r["column"], "onDelete": r["onDelete"].as_str().unwrap_or("restrict").to_uppercase(),
                "onUpdate": r["onUpdate"].as_str().unwrap_or("restrict").to_uppercase() }))
            })
            .collect();
        let adoption = json!({ "table": def["existingTable"], "indexes": expected_indexes,
            "foreignKeys": foreign_keys, "integerColumns": integer_columns });
        return format!(
            "-- zero-adopt {adoption}\nSELECT {} FROM {} LIMIT 0;",
            cols.keys().map(|n| q(n)).collect::<Vec<_>>().join(", "),
            table(t, tables)
        );
    }
    let mut body: Vec<String> = cols.iter().map(|(n, c)| column_sql(n, c)).collect();
    if let Some((pk, _)) = cols.iter().find(|(_, c)| c["primaryKey"].as_bool() == Some(true)) {
        body.push(format!("PRIMARY KEY ({})", q(pk)));
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

/// Statements that turn `prev` into `next`. Empty when nothing changed.
pub fn plan(prev: &Json, next: &Json) -> Vec<String> {
    let prev_t = tables(prev);
    let next_t = tables(next);
    let empty = Map::new();
    let mut out = Vec::new();
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
        let pc = prev_def["columns"].as_object().unwrap_or(&empty);
        let nc = next_def["columns"].as_object().unwrap_or(&empty);
        let render =
            |m: &Map| -> Vec<(String, String)> { m.iter().map(|(k, c)| (k.clone(), column_sql(k, c))).collect() };
        let pr = render(pc);
        let nr = render(nc);
        let p_ix = indexes(t, prev_def);
        let n_ix = indexes(t, next_def);
        let table = table(t, &next_t);
        // Constraints that go away (or change) are dropped before columns change.
        for (k, c) in pc {
            let before = fk_sql(t, k, c, &prev_t);
            let after = nc.get(k).and_then(|nc| fk_sql(t, k, nc, &next_t));
            if before.is_some() && before != after {
                out.push(format!("ALTER TABLE {table} DROP FOREIGN KEY `{}`;", fk_name(t, k)));
            }
        }
        for ix in &p_ix {
            if !n_ix.contains(ix) {
                out.push(drop_index(t, &ix.0, &next_t));
            }
        }
        for (k, sql) in &nr {
            match pr.iter().find(|(pk, _)| pk == k) {
                None => out.push(format!("ALTER TABLE {table} ADD COLUMN {sql};")),
                Some((_, psql)) if psql != sql => out.push(format!("ALTER TABLE {table} MODIFY COLUMN {sql};")),
                _ => {}
            }
        }
        for (k, _) in &pr {
            if !nc.contains_key(k) {
                out.push(format!("ALTER TABLE {table} DROP COLUMN {};", q(k)));
            }
        }
        for ix in &n_ix {
            if !p_ix.contains(ix) {
                out.push(create_index(t, ix, &next_t));
            }
        }
        for (k, c) in nc {
            let before = pc.get(k).and_then(|pc| fk_sql(t, k, pc, &prev_t));
            if let Some(after) = fk_sql(t, k, c, &next_t)
                && before.as_ref() != Some(&after)
            {
                out.push(format!("ALTER TABLE {table} ADD {after};"));
            }
        }
    }
    for t in prev_t.keys().filter(|t| !next_t.contains_key(*t)) {
        out.push(format!("DROP TABLE {};", table(t, &prev_t)));
    }
    out
}

/// Human-readable warnings for changes that can lose data.
pub fn destructive(prev: &Json, next: &Json) -> Vec<String> {
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
                for k in def["columns"].as_object().unwrap_or(&empty).keys() {
                    if !n["columns"].as_object().unwrap_or(&empty).contains_key(k) {
                        out.push(format!("drops column \"{t}\".\"{k}\""));
                    }
                }
                let wp_table = |d: &Json| d["editor"].as_bool() == Some(false) && d["auth"].as_bool() != Some(true);
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
            }
        }
    }
    out
}

/// Conservative development updates: existing values and constraints must keep their meanings.
/// This is stricter than production's warning list; deliberate migrations use the normal build.
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
                .any(|key| column[*key] != replacement[*key])
                    || column["max"].as_f64() != replacement["max"].as_f64()
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
        assert!(at("zero_locations") < at("zero_items"), "{first:?}");
        assert_eq!(first.len(), 3, "{first:?}");
        at("zero__revisions");
        let items = &first[at("zero_items")];
        assert!(items.contains("`locationId` BIGINT UNSIGNED"), "{items}");
        assert!(
            items.contains("FOREIGN KEY (`locationId`) REFERENCES `{prefix}zero_locations` (`id`) ON DELETE SET NULL"),
            "{items}"
        );

        // Adding a nullable column is an ADD; making it NOT NULL is a MODIFY.
        let mut v2 = v1.clone();
        v2["tables"]["items"]["columns"]["note"] = col("text");
        assert_eq!(
            plan(&v1, &v2),
            vec!["ALTER TABLE `{prefix}zero_items` ADD COLUMN `note` LONGTEXT;".to_string()]
        );
        assert!(preservation_problems(&v1, &v2).is_empty());
        let mut v3 = v2.clone();
        v3["tables"]["items"]["columns"]["note"]["notNull"] = json!(true);
        assert_eq!(
            plan(&v2, &v3),
            vec!["ALTER TABLE `{prefix}zero_items` MODIFY COLUMN `note` LONGTEXT NOT NULL;".to_string()]
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
                "ALTER TABLE `{prefix}zero_items` DROP FOREIGN KEY `zero_items_locationId_fk`;".to_string(),
                "ALTER TABLE `{prefix}zero_items` DROP COLUMN `note`;".to_string(),
                "DROP TABLE `{prefix}zero_locations`;".to_string(),
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
                "ALTER TABLE `{prefix}zero_pages` ADD COLUMN `locale` VARCHAR(191) DEFAULT 'en' NOT NULL;".to_string(),
                "ALTER TABLE `{prefix}zero_pages` ADD COLUMN `translationGroup` VARCHAR(36);".to_string(),
                "ALTER TABLE `{prefix}zero_pages` ADD UNIQUE KEY `translationGroup_locale` (`translationGroup`, `locale`);".to_string(),
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
}
