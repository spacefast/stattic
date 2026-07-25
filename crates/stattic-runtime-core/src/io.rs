use crate::finalize::FinalizeError;
use crate::import_rebind::{ImportRebindInput, ImportRebindOutput};
use crate::zero::{zero_endpoint_artifact_path, zero_run_artifact_path};
use crate::{
    RuntimeBuildInput, RuntimeCompileError, RuntimeCompileOutput, RuntimeCompileResult,
    RuntimeFinalizeInput, SiteFinalizeInput, SiteFinalizeOutput, BUILD_INPUT_FORMAT,
    FINALIZE_INPUT_FORMAT, SITE_FINALIZE_INPUT_FORMAT,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::fs;
use std::path::{Path, PathBuf};

pub fn read_build_input(path: impl AsRef<Path>) -> RuntimeCompileResult<RuntimeBuildInput> {
    let path = path.as_ref();
    let input = read_json::<RuntimeBuildInput>(path)?;
    if input.format != BUILD_INPUT_FORMAT {
        return Err(RuntimeCompileError::InvalidFormat {
            expected: BUILD_INPUT_FORMAT,
            actual: input.format,
        });
    }
    Ok(input)
}

pub fn read_finalize_input(path: impl AsRef<Path>) -> RuntimeCompileResult<RuntimeFinalizeInput> {
    let path = path.as_ref();
    let input = read_json::<RuntimeFinalizeInput>(path)?;
    if input.format != FINALIZE_INPUT_FORMAT {
        return Err(RuntimeCompileError::InvalidFormat {
            expected: FINALIZE_INPUT_FORMAT,
            actual: input.format,
        });
    }
    Ok(input)
}

/// Reads only the `format` discriminator of an input file so callers can
/// dispatch between the v1 and v2 finalize wire formats.
pub fn read_input_format(path: impl AsRef<Path>) -> RuntimeCompileResult<String> {
    let path = path.as_ref();
    let value = read_json::<Value>(path)?;
    Ok(value
        .get("format")
        .and_then(Value::as_str)
        .unwrap_or_default()
        .to_string())
}

pub fn read_site_finalize_input(
    path: impl AsRef<Path>,
) -> Result<SiteFinalizeInput, FinalizeError> {
    let path = path.as_ref();
    let bytes = fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })?;
    let input: SiteFinalizeInput =
        serde_json::from_slice(&bytes).map_err(|source| FinalizeError::Json {
            path: path.to_path_buf(),
            source,
        })?;
    if input.format != SITE_FINALIZE_INPUT_FORMAT {
        return Err(FinalizeError::Invalid {
            code: "finalizer_input_format_invalid",
            message: "Unsupported finalizer input format.".into(),
            details: None,
        });
    }
    crate::finalize::validate_id(&input.space_id, "space_id")?;
    crate::finalize::validate_id(&input.version_id, "version_id")?;
    if let Some(upload_id) = &input.upload_id {
        crate::finalize::validate_id(upload_id, "upload_id")?;
    }
    Ok(input)
}

/// Reads a `spacefast.finalizer.import-rebind.input.v1` file. The format
/// discriminator is validated inside `rebind_imported_version`.
pub fn read_import_rebind_input(
    path: impl AsRef<Path>,
) -> Result<ImportRebindInput, FinalizeError> {
    let path = path.as_ref();
    serde_json::from_slice(&fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })?)
    .map_err(|source| FinalizeError::Json {
        path: path.to_path_buf(),
        source,
    })
}

/// Writes the `spacefast.finalizer.import-rebind.output.v1` envelope.
pub fn write_import_rebind_output(
    output: &ImportRebindOutput,
    path: &Path,
) -> Result<(), FinalizeError> {
    let mut bytes = serde_json::to_vec_pretty(output).map_err(|source| FinalizeError::Json {
        path: path.to_path_buf(),
        source,
    })?;
    bytes.push(b'\n');
    fs::write(path, bytes).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })
}

/// Writes the v2 finalize output to `output_path` (spilling any open-session
/// manifest into a `.manifest.json` side-car next to it), or to stdout when no
/// path is given.
pub fn write_site_finalize_output(
    mut output: SiteFinalizeOutput,
    output_path: Option<&Path>,
) -> Result<(), FinalizeError> {
    if let Some(path) = output_path {
        if let Some(manifest) = output.manifest.take() {
            let manifest_path = PathBuf::from(format!("{}.manifest.json", path.display()));
            let file = fs::File::create(&manifest_path).map_err(|source| FinalizeError::Io {
                path: manifest_path.clone(),
                source,
            })?;
            serde_json::to_writer(file, &manifest).map_err(|source| FinalizeError::Json {
                path: manifest_path.clone(),
                source,
            })?;
            output.manifest_path = Some(manifest_path.to_string_lossy().into_owned());
        }
        let json = serde_json::to_vec(&output).map_err(|source| FinalizeError::Json {
            path: path.to_path_buf(),
            source,
        })?;
        fs::write(path, json).map_err(|source| FinalizeError::Io {
            path: path.to_path_buf(),
            source,
        })?;
    } else {
        println!(
            "{}",
            serde_json::to_string(&output).map_err(|source| FinalizeError::Json {
                path: PathBuf::from("<stdout>"),
                source,
            })?
        );
    }
    Ok(())
}

/// Writes the stable v2 failure envelope consumed by the PHP native adapter.
pub fn write_site_finalize_error(error: &FinalizeError, path: &Path) -> Result<(), FinalizeError> {
    let (code, message, details) = match error {
        FinalizeError::Invalid {
            code,
            message,
            details,
        } => (
            *code,
            message.clone(),
            details.clone().unwrap_or_else(|| serde_json::json!({})),
        ),
        FinalizeError::Io { path, source } => (
            "runtime_finalizer_io_error",
            source.to_string(),
            serde_json::json!({"path":path.to_string_lossy()}),
        ),
        FinalizeError::Json { path, source } => (
            "runtime_finalizer_json_invalid",
            source.to_string(),
            serde_json::json!({"path":path.to_string_lossy()}),
        ),
    };
    let output = serde_json::json!({
        "format": "stattic.runtime.finalize.error.v2",
        "code": code,
        "message": message,
        "details": details,
    });
    let bytes = serde_json::to_vec(&output).map_err(|source| FinalizeError::Json {
        path: path.to_path_buf(),
        source,
    })?;
    fs::write(path, bytes).map_err(|source| FinalizeError::Io {
        path: path.to_path_buf(),
        source,
    })
}

pub fn write_output(
    out_dir: impl AsRef<Path>,
    output: &RuntimeCompileOutput,
) -> RuntimeCompileResult<()> {
    let out_dir = out_dir.as_ref();
    fs::create_dir_all(out_dir).map_err(|source| RuntimeCompileError::Io {
        path: out_dir.to_path_buf(),
        source,
    })?;
    write_json(out_dir.join("runtime-compile-output.json"), output)?;
    write_json(out_dir.join("debug.json"), &output.artifacts.debug_json)?;
    write_json(
        out_dir.join("php-manifest.json"),
        &output.artifacts.php_manifest,
    )?;
    write_php_array(
        out_dir.join("php-manifest.php"),
        &output.artifacts.php_manifest,
    )?;
    write_json(out_dir.join("active.json"), &output.artifacts.active)?;
    write_php_array(out_dir.join("active.php"), &output.artifacts.active)?;
    if let Some(header_artifact) = &output.artifacts.header_artifact {
        write_json(out_dir.join("headers.json"), header_artifact)?;
        write_php_array(out_dir.join("headers.php"), header_artifact)?;
    }
    if let Some(redirect_artifact) = &output.artifacts.redirect_artifact {
        write_json(out_dir.join("redirects.json"), redirect_artifact)?;
        write_php_array(out_dir.join("redirects.php"), redirect_artifact)?;
    }
    if let Some(zero_routes) = &output.artifacts.zero_routes {
        write_json(out_dir.join("zero/routes.json"), zero_routes)?;
        write_php_array(out_dir.join("zero/routes.php"), zero_routes)?;
    }
    if let Some(zero_migrations) = &output.artifacts.zero_migrations {
        write_json(out_dir.join("zero/migrations.json"), zero_migrations)?;
        write_php_array(out_dir.join("zero/migrations.php"), zero_migrations)?;
    }
    if let Some(zero_endpoint_index) = &output.artifacts.zero_endpoint_index {
        write_json(
            out_dir.join("zero/endpoints-index.json"),
            zero_endpoint_index,
        )?;
        write_php_array(
            out_dir.join("zero/endpoints-index.php"),
            zero_endpoint_index,
        )?;
    }
    if let Some(zero_run_index) = &output.artifacts.zero_run_index {
        write_json(out_dir.join("zero/runs-index.json"), zero_run_index)?;
        write_php_array(out_dir.join("zero/runs-index.php"), zero_run_index)?;
    }
    for artifact in &output.artifacts.zero_endpoint_artifacts {
        write_json(
            out_dir.join(zero_endpoint_artifact_path(artifact)),
            artifact,
        )?;
    }
    for artifact in &output.artifacts.zero_run_artifacts {
        write_json(out_dir.join(zero_run_artifact_path(artifact)), artifact)?;
    }
    for generated in &output.artifacts.generated_zero_files {
        write_bytes(out_dir.join(&generated.path), &generated.bytes)?;
    }
    Ok(())
}

fn read_json<T: for<'de> Deserialize<'de>>(path: &Path) -> RuntimeCompileResult<T> {
    let bytes = fs::read(path).map_err(|source| RuntimeCompileError::Io {
        path: path.to_path_buf(),
        source,
    })?;
    serde_json::from_slice(&bytes).map_err(|source| RuntimeCompileError::Json {
        path: path.to_path_buf(),
        source,
    })
}

fn write_json<T: Serialize>(path: PathBuf, value: &T) -> RuntimeCompileResult<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|source| RuntimeCompileError::Io {
            path: parent.to_path_buf(),
            source,
        })?;
    }
    let bytes = serde_json::to_vec_pretty(value).map_err(|source| RuntimeCompileError::Json {
        path: path.clone(),
        source,
    })?;
    fs::write(&path, [bytes, b"\n".to_vec()].concat())
        .map_err(|source| RuntimeCompileError::Io { path, source })
}

fn write_bytes(path: PathBuf, bytes: &[u8]) -> RuntimeCompileResult<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|source| RuntimeCompileError::Io {
            path: parent.to_path_buf(),
            source,
        })?;
    }
    fs::write(&path, bytes).map_err(|source| RuntimeCompileError::Io { path, source })
}

fn write_php_array<T: Serialize>(path: PathBuf, value: &T) -> RuntimeCompileResult<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|source| RuntimeCompileError::Io {
            path: parent.to_path_buf(),
            source,
        })?;
    }
    let value = serde_json::to_value(value).map_err(|source| RuntimeCompileError::Json {
        path: path.clone(),
        source,
    })?;
    let php = format!("<?php\nreturn {};\n", php_export(&value, 0));
    fs::write(&path, php).map_err(|source| RuntimeCompileError::Io { path, source })
}

fn php_export(value: &Value, indent: usize) -> String {
    match value {
        Value::Null => "null".to_string(),
        Value::Bool(value) => {
            if *value {
                "true".to_string()
            } else {
                "false".to_string()
            }
        }
        Value::Number(value) => value.to_string(),
        Value::String(value) => php_string(value),
        Value::Array(items) => {
            if items.is_empty() {
                return "[]".to_string();
            }
            let next_indent = indent + 4;
            let mut output = String::from("[\n");
            for item in items {
                output.push_str(&" ".repeat(next_indent));
                output.push_str(&php_export(item, next_indent));
                output.push_str(",\n");
            }
            output.push_str(&" ".repeat(indent));
            output.push(']');
            output
        }
        Value::Object(entries) => {
            if entries.is_empty() {
                return "[]".to_string();
            }
            let next_indent = indent + 4;
            let mut output = String::from("[\n");
            for (key, item) in entries {
                output.push_str(&" ".repeat(next_indent));
                output.push_str(&php_string(key));
                output.push_str(" => ");
                output.push_str(&php_export(item, next_indent));
                output.push_str(",\n");
            }
            output.push_str(&" ".repeat(indent));
            output.push(']');
            output
        }
    }
}

fn php_string(value: &str) -> String {
    let escaped = value
        .replace('\\', "\\\\")
        .replace('\'', "\\'")
        .replace('\n', "\\n")
        .replace('\r', "\\r");
    format!("'{escaped}'")
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    use tempfile::tempdir;

    #[test]
    fn finalize_error_writer_preserves_structured_details() {
        let temp = tempdir().unwrap();
        let path = temp.path().join("output.json");
        let error = FinalizeError::Invalid {
            code: "version_upload_incomplete",
            message: "Version upload has missing files: index.html".into(),
            details: Some(json!({"missingPaths":["index.html"],"missingCount":1})),
        };
        write_site_finalize_error(&error, &path).unwrap();
        let value: Value = serde_json::from_slice(&fs::read(path).unwrap()).unwrap();
        assert_eq!(
            value,
            json!({
                "format":"stattic.runtime.finalize.error.v2",
                "code":"version_upload_incomplete",
                "message":"Version upload has missing files: index.html",
                "details":{"missingPaths":["index.html"],"missingCount":1}
            })
        );
    }
}
