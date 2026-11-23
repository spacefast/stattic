//! `typedpress danger`: local inspection of retired columns/tables from `tp/_generated/migrations/*/ops.json`
//! (`typedpress danger plan [dir] [--json]`), plus forwarding live destructive commands (`status`,
//! `drop-column`, `drop-table`, `purge-deleted`, `raw-sql`) to `wp typedpress danger`.

use crate::commands::site;
use crate::migrate;
use serde_json::{Value as Json, json};
use std::path::{Path, PathBuf};
use std::process::ExitCode;

pub(crate) fn cli(rest: &[String]) -> ExitCode {
    let mut args = rest.to_vec();
    let has_site = args.iter().any(|a| a == "--site" || a.starts_with("--site="));
    let sub = args.first().map(String::as_str).unwrap_or("plan");
    if sub == "plan" && !has_site {
        return local_plan(&args[1..]);
    }
    match site::take_site(&mut args) {
        Ok(target) => {
            let mut forwarded = vec!["typedpress".to_string(), "danger".to_string()];
            forwarded.extend(args);
            site::forward(target.as_deref(), &forwarded)
        }
        Err(err) => {
            eprintln!("{err}");
            ExitCode::from(2)
        }
    }
}

/// Inspects `tp/_generated/migrations/*/ops.json` locally and reports whether the app opted into
/// `typedpress/danger`, along with retired columns and tables eligible for phase-2 cleanup.
pub(crate) fn local_plan(rest: &[String]) -> ExitCode {
    let mut project = PathBuf::from(".");
    let mut as_json = false;
    for a in rest {
        match a.as_str() {
            "--json" => as_json = true,
            other if other.starts_with('-') => {
                eprintln!("unknown option {other} for typedpress danger plan");
                return ExitCode::from(2);
            }
            other => project = PathBuf::from(other),
        }
    }

    match inspect_project(&project) {
        Ok(report) => {
            if as_json {
                println!("{}", serde_json::to_string_pretty(&report).unwrap());
            } else {
                let opted_in = report["enabled"].as_bool().unwrap_or(false);
                if !opted_in {
                    println!(
                        "WARNING: typedpress/danger (destructiveMigrations) is NOT enabled in this project.\n\
                         Import `destructiveMigrations` from \"typedpress/danger\" and add `use: [destructiveMigrations()]` to typedpress.config.ts before running destructive CLI commands."
                    );
                } else {
                    println!("Destructive migrations provider: ENABLED (typedpress/danger, CLI-only)");
                }
                let cols = report["retiredColumns"].as_array().map(Vec::as_slice).unwrap_or(&[]);
                let tbls = report["retiredTables"].as_array().map(Vec::as_slice).unwrap_or(&[]);
                println!(
                    "\nRetired columns eligible for `wp typedpress danger drop-column`: {}",
                    cols.len()
                );
                for c in cols {
                    println!(
                        "  - {}.{} (retired in {})",
                        c["table"].as_str().unwrap_or(""),
                        c["column"].as_str().unwrap_or(""),
                        c["retiredIn"].as_str().unwrap_or("")
                    );
                }
                println!(
                    "\nRetired tables eligible for `wp typedpress danger drop-table`: {}",
                    tbls.len()
                );
                for t in tbls {
                    println!(
                        "  - {} (retired in {})",
                        t["table"].as_str().unwrap_or(""),
                        t["retiredIn"].as_str().unwrap_or("")
                    );
                }
            }
            ExitCode::SUCCESS
        }
        Err(err) => {
            eprintln!("{err}");
            ExitCode::from(1)
        }
    }
}

pub(crate) fn inspect_project(project: &Path) -> Result<Json, String> {
    let mig_dir = project.join("tp/_generated/migrations");
    let mut folders: Vec<(String, PathBuf)> = Vec::new();
    if mig_dir.is_dir() {
        for entry in std::fs::read_dir(&mig_dir).map_err(|e| format!("cannot read {}: {e}", mig_dir.display()))? {
            let entry = entry.map_err(|e| e.to_string())?;
            if entry.file_type().map_err(|e| e.to_string())?.is_dir() {
                let ops_path = entry.path().join("ops.json");
                if ops_path.is_file() {
                    let id = entry.file_name().to_string_lossy().to_string();
                    folders.push((id, ops_path));
                }
            }
        }
    }
    folders.sort_by(|a, b| a.0.cmp(&b.0));

    let mut ops_files = Vec::new();
    for (id, path) in folders {
        let text = std::fs::read_to_string(&path).map_err(|e| format!("cannot read {}: {e}", path.display()))?;
        let val: Json = serde_json::from_str(&text).map_err(|e| format!("invalid JSON in {}: {e}", path.display()))?;
        ops_files.push((id, val));
    }

    let folded = migrate::fold(&ops_files).map_err(|d| d.message)?;

    let mut retired_columns = Vec::new();
    for (table, cols) in &folded.retired_columns {
        for column in cols.keys() {
            let retired_in = ops_files
                .iter()
                .rev()
                .find(|(_, doc)| {
                    doc["ops"].as_array().is_some_and(|ops| {
                        ops.iter().any(|op| {
                            op["op"] == "retireColumn"
                                && op["table"] == table.as_str()
                                && op["column"] == column.as_str()
                        })
                    })
                })
                .map(|(id, _)| id.clone())
                .unwrap_or_default();
            retired_columns.push(json!({
                "table": table,
                "column": column,
                "retiredIn": retired_in,
            }));
        }
    }
    let mut retired_tables = Vec::new();
    for table in folded.retired_tables.keys() {
        let retired_in = ops_files
            .iter()
            .rev()
            .find(|(_, doc)| {
                doc["ops"].as_array().is_some_and(|ops| {
                    ops.iter()
                        .any(|op| op["op"] == "retireTable" && op["table"] == table.as_str())
                })
            })
            .map(|(id, _)| id.clone())
            .unwrap_or_default();
        retired_tables.push(json!({
            "table": table,
            "retiredIn": retired_in,
        }));
    }

    // Check whether the project's typedpress.config.ts opted into destructive-migrations.
    let enabled = compile_project(project)
        .map(|built| {
            built
                .schema
                .migration_providers
                .iter()
                .any(|p| p.id == "destructive-migrations")
        })
        .unwrap_or(false);

    Ok(json!({
        "enabled": enabled,
        "migrations": ops_files.len(),
        "retiredColumns": retired_columns,
        "retiredTables": retired_tables,
    }))
}

fn compile_project(project: &Path) -> Result<crate::pipeline::Built, String> {
    let program = crate::loader::load(&project.join("typedpress.config.ts")).map_err(|d| d.message)?;
    crate::pipeline::build(project, &program, || Ok(crate::pipeline::Previous::none()), |_| None).map_err(|d| d.message)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn destructive_migrations_plugin_registers_provider_in_schema_and_php() {
        let dir = tempfile::tempdir().unwrap();
        let root = dir.path();
        std::fs::write(
            root.join("typedpress.config.ts"),
            r#"
import { defineConfig, table, text } from "typedpress";
import { destructiveMigrations } from "typedpress/danger";

export const notes = table({
  body: text(),
});

export default defineConfig({
  name: "danger-demo",
  use: [destructiveMigrations({ requireArchive: true, allowRawSql: false })],
});
"#,
        )
        .unwrap();

        let built = compile_project(root).expect("compile with destructiveMigrations()");
        assert_eq!(built.schema.migration_providers.len(), 1);
        let mp = &built.schema.migration_providers[0];
        assert_eq!(mp.id, "destructive-migrations");
        assert!(mp.cli_only);
        assert!(mp.require_archive);
        assert!(!mp.allow_raw_sql);

        let php = crate::schema_php::render(&built.schema);
        assert!(
            php.contains("'migrationProviders' => [")
                && php.contains("'destructive-migrations' => ['id' => 'destructive-migrations', 'cliOnly' => true, 'requireArchive' => true, 'allowRawSql' => false]"),
            "{php}"
        );

        // Local plan reports enabled=true and lists retired columns from ops.json.
        let m1 = root.join("tp/_generated/migrations/20261121180000_aaaaaa_init");
        let m2 = root.join("tp/_generated/migrations/20261121180001_bbbbbb_retire_old");
        std::fs::create_dir_all(&m1).unwrap();
        std::fs::create_dir_all(&m2).unwrap();
        std::fs::write(
            m1.join("ops.json"),
            r#"{"version":1,"id":"20261121180000_aaaaaa_init","ops":[{"op":"createTable","table":"notes","def":{"editor":false,"drafts":false,"columns":{"id":{"type":"integer","notNull":true,"primaryKey":true,"autoIncrement":true,"unique":false,"max":null,"enum":null,"default":null,"references":null},"body":{"type":"text","notNull":true,"primaryKey":false,"autoIncrement":false,"unique":false,"max":null,"enum":null,"default":null,"references":null},"legacy":{"type":"text","notNull":false,"primaryKey":false,"autoIncrement":false,"unique":false,"max":null,"enum":null,"default":null,"references":null}}}}]}"#,
        )
        .unwrap();
        std::fs::write(
            m2.join("ops.json"),
            r#"{"version":1,"id":"20261121180001_bbbbbb_retire_old","ops":[{"op":"retireColumn","table":"notes","column":"legacy","was":{"type":"text","notNull":false,"primaryKey":false,"autoIncrement":false,"unique":false,"max":null,"enum":null,"default":null,"references":null}}]}"#,
        )
        .unwrap();

        let report = inspect_project(root).expect("inspect_project");
        assert_eq!(report["enabled"], true);
        assert_eq!(
            report["retiredColumns"],
            json!([{ "table": "notes", "column": "legacy", "retiredIn": "20261121180001_bbbbbb_retire_old" }])
        );
    }
}
