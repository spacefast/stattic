use serde_json::{json, Value};
use stattic_zero_runner::{compile_endpoint_program, ZeroEndpointCapabilities};
use std::collections::BTreeMap;
use unicode_normalization::UnicodeNormalization;

use crate::hash::{sha256_hex, sha256_prefixed, stable_json_sha256};
use crate::metadata::artifact_metadata_fields;
use crate::model::*;

pub(crate) struct CompiledZeroEndpoints {
    pub(crate) php_routes: Vec<PhpActionRecord>,
    pub(crate) zero_routes: Option<ZeroRoutesArtifact>,
    pub(crate) zero_migrations: Option<ZeroMigrationsArtifact>,
    pub(crate) zero_endpoint_index: Option<ZeroEndpointIndexArtifact>,
    pub(crate) endpoint_artifacts: Vec<ZeroEndpointArtifact>,
    pub(crate) zero_run_index: Option<ZeroRunIndexArtifact>,
    pub(crate) run_artifacts: Vec<ZeroRunArtifact>,
    pub(crate) generated_files: Vec<GeneratedRuntimeFile>,
}

pub(crate) fn zero_pack_sha256(compiled_zero: &CompiledZeroEndpoints) -> Option<String> {
    if compiled_zero.zero_routes.is_none()
        && compiled_zero.zero_migrations.is_none()
        && compiled_zero.zero_endpoint_index.is_none()
        && compiled_zero.zero_run_index.is_none()
        && compiled_zero.endpoint_artifacts.is_empty()
        && compiled_zero.run_artifacts.is_empty()
        && compiled_zero.generated_files.is_empty()
    {
        return None;
    }

    let generated_files: Vec<Value> = compiled_zero
        .generated_files
        .iter()
        .map(|file| {
            json!({
                "path": file.path,
                "sha256": sha256_hex(&file.bytes),
            })
        })
        .collect();

    Some(stable_json_sha256(&json!({
        "format": "stattic.zero.pack.v1",
        "routes": compiled_zero.zero_routes,
        "migrations": compiled_zero.zero_migrations,
        "endpointIndex": compiled_zero.zero_endpoint_index,
        "endpointArtifacts": compiled_zero.endpoint_artifacts,
        "runIndex": compiled_zero.zero_run_index,
        "runArtifacts": compiled_zero.run_artifacts,
        "generatedFiles": generated_files,
    })))
}

pub(crate) fn compile_zero_endpoints(
    artifact_metadata: Option<&Value>,
    endpoints: &[RuntimeZeroEndpoint],
    runs: &[RuntimeZeroRun],
    diagnostics: &mut Vec<RuntimeDiagnostic>,
) -> CompiledZeroEndpoints {
    let mut compiled = CompiledZeroEndpoints {
        php_routes: Vec::new(),
        zero_routes: None,
        zero_migrations: None,
        zero_endpoint_index: None,
        endpoint_artifacts: Vec::new(),
        zero_run_index: None,
        run_artifacts: Vec::new(),
        generated_files: Vec::new(),
    };
    if endpoints.is_empty() && runs.is_empty() {
        return compiled;
    }
    if endpoints.len() > 128 {
        diagnostics.push(RuntimeDiagnostic {
            severity: RuntimeDiagnosticSeverity::Error,
            code: "zero_endpoints_too_many".to_string(),
            message: "Zero endpoints support up to 128 entries.".to_string(),
            path: None,
            details: None,
        });
        return compiled;
    }
    if runs.len() > 128 {
        diagnostics.push(RuntimeDiagnostic {
            severity: RuntimeDiagnosticSeverity::Error,
            code: "zero_runs_too_many".to_string(),
            message: "Zero run handlers support up to 128 entries.".to_string(),
            path: None,
            details: None,
        });
        return compiled;
    }

    let mut exact = Vec::new();
    let mut by_first_segment: BTreeMap<String, Vec<ZeroRouteEntry>> = BTreeMap::new();
    let mut fallback = Vec::new();
    let mut migration_statements = BTreeMap::new();
    let mut endpoint_index = BTreeMap::new();
    let mut run_index = BTreeMap::new();
    let mut seen_route_patterns: Vec<(String, String)> = Vec::new();
    let mut seen_endpoint_ids = BTreeMap::new();

    for (index, endpoint) in endpoints.iter().enumerate() {
        let method = endpoint.method.to_ascii_uppercase();
        let path = endpoint.path.clone();
        let endpoint_id = endpoint
            .endpoint_id
            .as_ref()
            .filter(|value| !value.is_empty())
            .cloned()
            .unwrap_or_else(|| zero_endpoint_id(&method, &path));
        if ZERO_CONTROL_PATHS.contains(&path.as_str()) {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_endpoint_conflict".to_string(),
                message: format!("Zero endpoint {path} conflicts with a generated control route."),
                path: Some(endpoint.path.clone()),
                details: None,
            });
            continue;
        }
        if !zero_method_valid(&method)
            || !zero_route_path_valid(&path)
            || endpoint.source.is_empty()
            || endpoint.source.len() > 2 * 1024 * 1024
            || endpoint_id.len() > 256
        {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_endpoint_invalid".to_string(),
                message: "Zero endpoint entry is invalid.".to_string(),
                path: Some(endpoint.path.clone()),
                details: None,
            });
            continue;
        }
        let route_ambiguous = seen_route_patterns.iter().any(|(seen_method, seen_path)| {
            zero_route_patterns_ambiguous(&method, &path, seen_method, seen_path)
        });
        if route_ambiguous {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_endpoint_duplicate".to_string(),
                message: "Zero endpoint routes must not have equal-priority overlapping matches."
                    .to_string(),
                path: Some(path.clone()),
                details: None,
            });
            continue;
        }
        seen_route_patterns.push((method.clone(), path.clone()));
        if seen_endpoint_ids
            .insert(endpoint_id.clone(), index)
            .is_some()
        {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_endpoint_id_duplicate".to_string(),
                message: "Zero endpoint ids must be unique.".to_string(),
                path: Some(path.clone()),
                details: None,
            });
            continue;
        }

        let slug = zero_endpoint_slug(&method, &path, index);
        let base_path = format!("zero/endpoints/{slug}");
        let source_path = format!("{base_path}.source.js");
        let bytecode_path = format!("{base_path}.bytecode");
        let artifact_path = format!("{base_path}.json");
        let runner_capabilities = runner_capabilities(&endpoint.capabilities);
        let compiled_program =
            match compile_endpoint_program(&endpoint.source, &source_path, &runner_capabilities) {
                Ok(compiled_program) => compiled_program,
                Err(error) => {
                    diagnostics.push(RuntimeDiagnostic {
                        severity: RuntimeDiagnosticSeverity::Error,
                        code: "zero_endpoint_compile_failed".to_string(),
                        message: format!("Zero endpoint bytecode compilation failed: {error}"),
                        path: Some(path.clone()),
                        details: None,
                    });
                    continue;
                }
            };
        let db = zero_endpoint_db_metadata(endpoint.db.as_ref(), endpoint.schema_hash.as_ref());
        let artifact = ZeroEndpointArtifact {
            format: "stattic.zero.endpoint.v1".to_string(),
            endpoint_id: endpoint_id.clone(),
            kind: "endpoint".to_string(),
            method: method.clone(),
            path: path.clone(),
            source_path: source_path.clone(),
            bytecode_path: bytecode_path.clone(),
            source_sha256: sha256_prefixed(compiled_program.generated_source.as_bytes()),
            bytecode_sha256: sha256_prefixed(&compiled_program.bytecode),
            runner_abi: "stattic-zero-runner-abi-v2".to_string(),
            quickjs_abi: "rquickjs-0.12".to_string(),
            capabilities: endpoint.capabilities.clone(),
            db,
        };
        let schema_hash = artifact
            .db
            .get("schemaHash")
            .and_then(Value::as_str)
            .map(str::to_string);
        for statement in zero_db_migration_statements(&artifact.db) {
            migration_statements.insert(zero_migration_statement_sort_key(&statement), statement);
        }
        endpoint_index.insert(endpoint_id.clone(), artifact_path.clone());
        compiled.php_routes.push(PhpActionRecord::InvokeZero {
            pattern: path.clone(),
            method: method.clone(),
            endpoint_id: endpoint_id.clone(),
            zero_artifact: artifact_path.clone(),
            schema_hash: schema_hash.clone(),
            capabilities: endpoint.capabilities.clone(),
        });
        let route_entry = ZeroRouteEntry {
            method,
            pattern: path.clone(),
            endpoint_id,
            artifact: artifact_path.clone(),
            capabilities: endpoint.capabilities.clone(),
            schema_hash,
        };
        if path.contains(':') {
            if let Some(first) = zero_route_first_segment(&path) {
                by_first_segment.entry(first).or_default().push(route_entry);
            } else {
                fallback.push(route_entry);
            }
        } else {
            exact.push(route_entry);
        }
        compiled.generated_files.push(GeneratedRuntimeFile {
            path: source_path,
            bytes: compiled_program.generated_source.into_bytes(),
        });
        compiled.generated_files.push(GeneratedRuntimeFile {
            path: bytecode_path,
            bytes: compiled_program.bytecode,
        });
        compiled.endpoint_artifacts.push(artifact);
    }

    for (index, run) in runs.iter().enumerate() {
        let run_id = run.run_id.trim();
        if run_id.is_empty()
            || run_id.len() > 256
            || run.source.is_empty()
            || run.source.len() > 2 * 1024 * 1024
        {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_run_invalid".to_string(),
                message: "Zero run handler entry is invalid.".to_string(),
                path: Some(run.run_id.clone()),
                details: None,
            });
            continue;
        }
        if run_index.contains_key(run_id) {
            diagnostics.push(RuntimeDiagnostic {
                severity: RuntimeDiagnosticSeverity::Error,
                code: "zero_run_duplicate".to_string(),
                message: "Zero run handler ids must be unique.".to_string(),
                path: Some(run_id.to_string()),
                details: None,
            });
            continue;
        }

        let slug = zero_run_slug(run_id, index);
        let base_path = format!("zero/runs/{slug}");
        let source_path = format!("{base_path}.source.js");
        let bytecode_path = format!("{base_path}.bytecode");
        let artifact_path = format!("{base_path}.json");
        let runner_capabilities = runner_capabilities(&run.capabilities);
        let compiled_program =
            match compile_endpoint_program(&run.source, &source_path, &runner_capabilities) {
                Ok(compiled_program) => compiled_program,
                Err(error) => {
                    diagnostics.push(RuntimeDiagnostic {
                        severity: RuntimeDiagnosticSeverity::Error,
                        code: "zero_run_compile_failed".to_string(),
                        message: format!("Zero run handler bytecode compilation failed: {error}"),
                        path: Some(run_id.to_string()),
                        details: None,
                    });
                    continue;
                }
            };
        let db = zero_endpoint_db_metadata(run.db.as_ref(), run.schema_hash.as_ref());
        for statement in zero_db_migration_statements(&db) {
            migration_statements.insert(zero_migration_statement_sort_key(&statement), statement);
        }
        run_index.insert(run_id.to_string(), artifact_path.clone());
        let artifact = ZeroRunArtifact {
            format: "stattic.zero.run.v1".to_string(),
            run_id: run_id.to_string(),
            kind: "run".to_string(),
            source_path: source_path.clone(),
            bytecode_path: bytecode_path.clone(),
            source_sha256: sha256_prefixed(compiled_program.generated_source.as_bytes()),
            bytecode_sha256: sha256_prefixed(&compiled_program.bytecode),
            runner_abi: "stattic-zero-runner-abi-v2".to_string(),
            quickjs_abi: "rquickjs-0.12".to_string(),
            capabilities: run.capabilities.clone(),
            db,
        };
        compiled.generated_files.push(GeneratedRuntimeFile {
            path: source_path,
            bytes: compiled_program.generated_source.into_bytes(),
        });
        compiled.generated_files.push(GeneratedRuntimeFile {
            path: bytecode_path,
            bytes: compiled_program.bytecode,
        });
        compiled.run_artifacts.push(artifact);
    }

    if !exact.is_empty() || !by_first_segment.is_empty() || !fallback.is_empty() {
        let metadata = artifact_metadata_fields(artifact_metadata);
        compiled.zero_routes = Some(ZeroRoutesArtifact {
            runtime_schema: metadata.runtime_schema,
            runtime_engine_version: metadata.runtime_engine_version,
            generated_at: metadata.generated_at,
            format: "stattic.zero.routes.v1".to_string(),
            artifact_kind: "zero_routes".to_string(),
            exact,
            by_first_segment,
            fallback,
        });
    }
    if !migration_statements.is_empty() {
        let metadata = artifact_metadata_fields(artifact_metadata);
        compiled.zero_migrations = Some(ZeroMigrationsArtifact {
            runtime_schema: metadata.runtime_schema,
            runtime_engine_version: metadata.runtime_engine_version,
            generated_at: metadata.generated_at,
            format: "stattic.zero.migrations.v1".to_string(),
            artifact_kind: "zero_migrations".to_string(),
            statements: migration_statements.into_values().collect(),
        });
    }
    if !endpoint_index.is_empty() {
        let metadata = artifact_metadata_fields(artifact_metadata);
        compiled.zero_endpoint_index = Some(ZeroEndpointIndexArtifact {
            runtime_schema: metadata.runtime_schema,
            runtime_engine_version: metadata.runtime_engine_version,
            generated_at: metadata.generated_at,
            format: "stattic.zero.endpoints-index.v1".to_string(),
            artifact_kind: "zero_endpoints_index".to_string(),
            endpoints: endpoint_index,
        });
    }
    if !run_index.is_empty() {
        let metadata = artifact_metadata_fields(artifact_metadata);
        compiled.zero_run_index = Some(ZeroRunIndexArtifact {
            runtime_schema: metadata.runtime_schema,
            runtime_engine_version: metadata.runtime_engine_version,
            generated_at: metadata.generated_at,
            format: "stattic.zero.runs-index.v1".to_string(),
            artifact_kind: "zero_runs_index".to_string(),
            runs: run_index,
        });
    }
    compiled
}

fn zero_method_valid(method: &str) -> bool {
    matches!(
        method,
        "GET" | "HEAD" | "POST" | "PUT" | "PATCH" | "DELETE" | "OPTIONS"
    )
}

const ZERO_CONTROL_PATHS: &[&str] = &[
    "/__spacefast/zero/config",
    "/__spacefast/zero/run",
    "/__spacefast/zero/auth/gravatar/start",
    "/__spacefast/zero/auth/sign-out",
    "/__spacefast/zero/realtime/events",
];

fn zero_route_path_valid(path: &str) -> bool {
    if path.is_empty()
        || path.encode_utf16().count() > 2048
        || !path.starts_with('/')
        || path.contains("//")
        || path.contains(['\\', '*', '?', '#'])
        || path.chars().any(|character| character.is_ascii_control())
        || !path.nfc().eq(path.chars())
        || path.ends_with('/')
    {
        return false;
    }

    let segments: Vec<&str> = path[1..].split('/').collect();
    if segments.iter().enumerate().any(|(index, segment)| {
        segment.is_empty()
            || *segment == "."
            || *segment == ".."
            || *segment == ":"
            || (*segment == ":splat" && index != segments.len() - 1)
    }) {
        return false;
    }

    !matches!(
        path,
        "/" | "/index.html" | "/client.js" | "/auth/callback" | "/__spacefast" | "/__span"
    ) && !path.starts_with("/auth/")
        && !path.starts_with("/__spacefast/")
        && !path.starts_with("/__span/")
        && path != "/__stattic"
        && !path.starts_with("/__stattic/")
}

fn zero_endpoint_id(method: &str, path: &str) -> String {
    let readable = format!("{method} {path}");
    if readable.len() <= 256 {
        return readable;
    }
    let digest = sha256_hex(format!("{method}\0{path}").as_bytes());
    format!("endpoint_{}_{}", method.to_ascii_lowercase(), digest)
}

fn zero_route_patterns_ambiguous(
    left_method: &str,
    left_pattern: &str,
    right_method: &str,
    right_pattern: &str,
) -> bool {
    if !(left_method == right_method
        || (left_method == "GET" && right_method == "HEAD")
        || (left_method == "HEAD" && right_method == "GET"))
    {
        return false;
    }

    let (left_segments, left_splat, left_score) = zero_route_match_shape(left_pattern);
    let (right_segments, right_splat, right_score) = zero_route_match_shape(right_pattern);
    if left_score != right_score {
        return false;
    }
    if !left_splat && left_segments.len() < right_segments.len() {
        return false;
    }
    if !right_splat && right_segments.len() < left_segments.len() {
        return false;
    }

    left_segments
        .iter()
        .zip(right_segments.iter())
        .all(|(left, right)| match (left, right) {
            (Some(left), Some(right)) => left == right,
            _ => true,
        })
}

fn zero_route_match_shape(pattern: &str) -> (Vec<Option<&str>>, bool, usize) {
    let mut segments = Vec::new();
    let mut score = 0;
    let mut splat = false;
    let trimmed = pattern.trim_matches('/');
    let pattern_segments: Vec<&str> = if trimmed.is_empty() {
        Vec::new()
    } else {
        trimmed.split('/').collect()
    };
    for segment in pattern_segments {
        if segment == ":splat" {
            score += 1;
            splat = true;
            break;
        }
        if segment.starts_with(':') {
            segments.push(None);
            score += 2;
        } else {
            segments.push(Some(segment));
            score += 10;
        }
    }
    (segments, splat, score)
}

fn zero_endpoint_slug(method: &str, path: &str, index: usize) -> String {
    let mut base = format!("{}_{}", method, path.trim_matches('/')).to_ascii_lowercase();
    let mut collapsed = String::with_capacity(base.len());
    let mut last_was_separator = false;
    for character in base.chars() {
        if character.is_ascii_alphanumeric() {
            collapsed.push(character);
            last_was_separator = false;
        } else if !last_was_separator {
            collapsed.push('_');
            last_was_separator = true;
        }
    }
    base = collapsed.trim_matches('_').to_string();
    if base.is_empty() {
        base = "endpoint".to_string();
    }
    let prefix = if base.len() > 64 { &base[..64] } else { &base };
    let digest = sha256_hex(format!("{method}\n{path}\n{index}").as_bytes());
    format!("{prefix}_{}", &digest[..12])
}

fn zero_run_slug(run_id: &str, index: usize) -> String {
    let base = sanitize_slug(run_id);
    let digest = sha256_hex(format!("{run_id}\n{index}").as_bytes());
    format!("{base}_{}", &digest[..12])
}

fn sanitize_slug(input: &str) -> String {
    let mut collapsed = String::with_capacity(input.len());
    let mut last_was_separator = false;
    for character in input.to_ascii_lowercase().chars() {
        if character.is_ascii_alphanumeric() {
            collapsed.push(character);
            last_was_separator = false;
        } else if !last_was_separator {
            collapsed.push('_');
            last_was_separator = true;
        }
    }
    let base = collapsed.trim_matches('_');
    let base = if base.is_empty() { "run" } else { base };
    if base.len() > 64 {
        base[..64].to_string()
    } else {
        base.to_string()
    }
}

fn zero_route_first_segment(path: &str) -> Option<String> {
    let first = path
        .trim_matches('/')
        .split('/')
        .find(|segment| !segment.is_empty())?;
    if first.starts_with(':') {
        None
    } else {
        Some(first.to_string())
    }
}

fn runner_capabilities(capabilities: &ZeroCapabilities) -> ZeroEndpointCapabilities {
    ZeroEndpointCapabilities {
        db: capabilities.db,
        fetch: capabilities.fetch,
        auth: capabilities.auth,
        env: capabilities.env,
        realtime: capabilities.realtime,
        logging: capabilities.logging,
        gravatar: capabilities.gravatar,
        spam: capabilities.spam,
        email: capabilities.email,
    }
}

fn zero_endpoint_db_metadata(input: Option<&Value>, schema_hash: Option<&String>) -> Value {
    let mut tables = serde_json::Map::new();
    if let Some(raw_tables) = input
        .and_then(|value| value.get("tables"))
        .and_then(Value::as_object)
    {
        for (name, raw_table) in raw_tables {
            if let Some(table) = raw_table.as_object() {
                let physical_name = table
                    .get("physicalName")
                    .and_then(Value::as_str)
                    .unwrap_or(name);
                let mut columns = serde_json::Map::new();
                if let Some(raw_columns) = table.get("columns").and_then(Value::as_object) {
                    for (column_name, raw_column) in raw_columns {
                        let physical_column = match raw_column {
                            Value::String(value) => value.as_str(),
                            Value::Object(object) => object
                                .get("physicalName")
                                .and_then(Value::as_str)
                                .unwrap_or(column_name),
                            _ => column_name,
                        };
                        let column_type = raw_column
                            .as_object()
                            .and_then(|object| object.get("type"))
                            .and_then(Value::as_str)
                            .unwrap_or(if column_name == "id" { "id" } else { "string" });
                        columns.insert(
                            column_name.clone(),
                            json!({
                                "name": column_name,
                                "physicalName": physical_column,
                                "quotedName": quote_mysql_identifier(physical_column),
                                "type": column_type,
                            }),
                        );
                    }
                }
                let mut indexes = serde_json::Map::new();
                if let Some(raw_indexes) = table.get("indexes").and_then(Value::as_object) {
                    for (index_name, raw_index) in raw_indexes {
                        let fields = raw_index
                            .get("fields")
                            .and_then(Value::as_array)
                            .cloned()
                            .unwrap_or_default();
                        indexes.insert(index_name.clone(), json!({ "fields": fields }));
                    }
                }
                tables.insert(
                    name.clone(),
                    json!({
                        "name": name,
                        "physicalName": physical_name,
                        "quotedName": quote_mysql_identifier(physical_name),
                        "primaryKey": table.get("primaryKey").and_then(Value::as_str).unwrap_or("id"),
                        "columns": Value::Object(columns),
                        "indexes": Value::Object(indexes),
                    }),
                );
            }
        }
    }
    let resolved_schema_hash = input
        .and_then(|value| value.get("schemaHash"))
        .and_then(Value::as_str)
        .map(str::to_string)
        .or_else(|| schema_hash.cloned());
    json!({
        "schemaHash": resolved_schema_hash,
        "migrationOperations": input
            .and_then(|value| value.get("migrationOperations"))
            .cloned()
            .unwrap_or_else(|| json!([])),
        "tables": Value::Object(tables),
    })
}

fn zero_db_migration_statements(db: &Value) -> Vec<String> {
    let Some(tables) = db.get("tables").and_then(Value::as_object) else {
        return Vec::new();
    };
    let mut statements = Vec::new();
    for table in tables.values() {
        let Some(table) = table.as_object() else {
            continue;
        };
        let Some(physical_name) = table.get("physicalName").and_then(Value::as_str) else {
            continue;
        };
        let primary_key = table
            .get("primaryKey")
            .and_then(Value::as_str)
            .filter(|value| !value.is_empty())
            .unwrap_or("id");
        let columns = table.get("columns").and_then(Value::as_object);
        let primary_physical = columns
            .and_then(|columns| columns.get(primary_key))
            .and_then(|column| column.get("physicalName"))
            .and_then(Value::as_str)
            .unwrap_or(primary_key);
        let mut column_definitions = Vec::new();
        if let Some(columns) = columns {
            for (name, column) in columns {
                let Some(column_physical) = column.get("physicalName").and_then(Value::as_str)
                else {
                    continue;
                };
                let definition = if name == primary_key || column_physical == primary_physical {
                    format!(
                        "{} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                        quote_mysql_identifier(column_physical)
                    )
                } else {
                    format!("{} TEXT NULL", quote_mysql_identifier(column_physical))
                };
                column_definitions.push(definition);
            }
        }
        if !column_definitions.iter().any(|definition| {
            definition.starts_with(&format!("{} ", quote_mysql_identifier(primary_physical)))
        }) {
            column_definitions.insert(
                0,
                format!(
                    "{} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    quote_mysql_identifier(primary_physical)
                ),
            );
        }
        column_definitions.push(format!(
            "PRIMARY KEY ({})",
            quote_mysql_identifier(primary_physical)
        ));
        statements.push(format!(
            "CREATE TABLE IF NOT EXISTS {} ({}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            quote_mysql_identifier(physical_name),
            column_definitions.join(", ")
        ));
    }
    if let Some(operations) = db.get("migrationOperations").and_then(Value::as_array) {
        for operation in operations {
            let Some(op) = operation.get("op").and_then(Value::as_str) else {
                continue;
            };
            let Some(table_name) = operation.get("table").and_then(Value::as_str) else {
                continue;
            };
            let Some(table) = tables.get(table_name).and_then(Value::as_object) else {
                continue;
            };
            let Some(physical_name) = table.get("physicalName").and_then(Value::as_str) else {
                continue;
            };
            if op == "add_column" {
                // `CREATE TABLE IF NOT EXISTS` is a no-op once the table exists, so a
                // schema that gains a field only ever reaches the database through this
                // ALTER. The primary key is part of the CREATE and is never added here.
                let primary_key = table
                    .get("primaryKey")
                    .and_then(Value::as_str)
                    .filter(|value| !value.is_empty())
                    .unwrap_or("id");
                let columns = table.get("columns").and_then(Value::as_object);
                let primary_physical = columns
                    .and_then(|columns| columns.get(primary_key))
                    .and_then(|column| column.get("physicalName"))
                    .and_then(Value::as_str)
                    .unwrap_or(primary_key);
                let Some(column_name) = operation
                    .get("column")
                    .and_then(|column| column.get("name"))
                    .and_then(Value::as_str)
                else {
                    continue;
                };
                let Some(column_physical) = columns
                    .and_then(|columns| columns.get(column_name))
                    .and_then(|column| column.get("physicalName"))
                    .and_then(Value::as_str)
                else {
                    continue;
                };
                if column_name == primary_key || column_physical == primary_physical {
                    continue;
                }
                statements.push(format!(
                    "ALTER TABLE {} ADD COLUMN {} TEXT NULL",
                    quote_mysql_identifier(physical_name),
                    quote_mysql_identifier(column_physical)
                ));
                continue;
            }
            let Some(index_name) = operation.get("name").and_then(Value::as_str) else {
                continue;
            };
            if op == "drop_index" {
                statements.push(format!(
                    "DROP INDEX {} ON {}",
                    quote_mysql_identifier(index_name),
                    quote_mysql_identifier(physical_name)
                ));
                continue;
            }
            if op != "add_index" {
                continue;
            }
            let Some(column_names) = operation.get("columns").and_then(Value::as_array) else {
                continue;
            };
            let columns = table.get("columns").and_then(Value::as_object);
            let mut traversal_names: Vec<&str> =
                column_names.iter().filter_map(Value::as_str).collect();
            for managed in ["createdAt", "id"] {
                if columns.is_some_and(|columns| columns.contains_key(managed))
                    && !traversal_names.contains(&managed)
                {
                    traversal_names.push(managed);
                }
            }
            let physical_columns: Vec<String> = traversal_names
                .iter()
                .filter_map(|name| {
                    let physical = columns
                        .and_then(|columns| columns.get(*name))
                        .and_then(|column| column.get("physicalName"))
                        .and_then(Value::as_str)?;
                    Some(if *name == "id" {
                        quote_mysql_identifier(physical)
                    } else {
                        format!("{}(191)", quote_mysql_identifier(physical))
                    })
                })
                .collect();
            if physical_columns.len() != traversal_names.len() || physical_columns.is_empty() {
                continue;
            }
            statements.push(format!(
                "CREATE INDEX {} ON {} ({})",
                quote_mysql_identifier(index_name),
                quote_mysql_identifier(physical_name),
                physical_columns.join(", ")
            ));
        }
    }
    statements
}

fn zero_migration_statement_sort_key(statement: &str) -> String {
    // Tables exist before their columns; columns exist before any index over them.
    let priority = if statement.starts_with("CREATE TABLE ") {
        0
    } else if statement.starts_with("ALTER TABLE ") {
        1
    } else if statement.starts_with("DROP INDEX ") {
        2
    } else if statement.starts_with("CREATE INDEX ") {
        3
    } else {
        4
    };
    format!("{priority}:{statement}")
}

fn quote_mysql_identifier(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

pub(crate) fn zero_endpoint_artifact_path(artifact: &ZeroEndpointArtifact) -> String {
    artifact
        .source_path
        .strip_suffix(".source.js")
        .map(|base| format!("{base}.json"))
        .unwrap_or_else(|| format!("{}.json", artifact.source_path))
}

pub(crate) fn zero_run_artifact_path(artifact: &ZeroRunArtifact) -> String {
    artifact
        .source_path
        .strip_suffix(".source.js")
        .map(|base| format!("{base}.json"))
        .unwrap_or_else(|| format!("{}.json", artifact.source_path))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn nested_db_schema_hash_has_v1_precedence() {
        let fallback = "sha256:fallback".to_string();
        let metadata =
            zero_endpoint_db_metadata(Some(&json!({"schemaHash":"sha256:db"})), Some(&fallback));
        assert_eq!(metadata["schemaHash"], json!("sha256:db"));
    }
}
