use crate::finalize::FinalizeError;
use crate::{SiteFinalizeInput, SiteFinalizeOutput, SITE_FINALIZE_INPUT_FORMAT};
use std::fs;
use std::path::{Path, PathBuf};

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

/// Writes the v2 finalize output to `output_path`, or to stdout when no path is given.
pub fn write_site_finalize_output(
    output: SiteFinalizeOutput,
    output_path: Option<&Path>,
) -> Result<(), FinalizeError> {
    if let Some(path) = output_path {
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::{json, Value};
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
