use crate::finalize::{invalid_error, FinalizeError};
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
        return Err(invalid_error(
            "finalizer_input_format_invalid",
            "Unsupported finalizer input format.",
        ));
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

/// The site's disk or quota is exhausted (`ENOSPC`, `EDQUOT`). Only freeing
/// space or raising the quota clears it, so it gets its own code instead of
/// reading as a finalizer fault.
pub fn is_storage_full(error: &std::io::Error) -> bool {
    matches!(
        error.kind(),
        std::io::ErrorKind::StorageFull | std::io::ErrorKind::QuotaExceeded
    )
}

/// The stable v2 failure envelope consumed by the PHP native adapter.
pub fn site_finalize_error_envelope(error: &FinalizeError) -> serde_json::Value {
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
        FinalizeError::Io { path, source } if is_storage_full(source) => (
            "runtime_storage_full",
            "The site's disk is full.".to_string(),
            serde_json::json!({"path":path.to_string_lossy(),"error":source.to_string()}),
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
    serde_json::json!({
        "format": "stattic.runtime.finalize.error.v2",
        "code": code,
        "message": message,
        "details": details,
    })
}

/// Writes the failure envelope to `path`.
pub fn write_site_finalize_error(error: &FinalizeError, path: &Path) -> Result<(), FinalizeError> {
    let bytes = serde_json::to_vec(&site_finalize_error_envelope(error)).map_err(|source| {
        FinalizeError::Json {
            path: path.to_path_buf(),
            source,
        }
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
        let value: Value = serde_json::from_slice(&fs::read(&path).unwrap()).unwrap();
        assert_eq!(
            value,
            json!({
                "format":"stattic.runtime.finalize.error.v2",
                "code":"version_upload_incomplete",
                "message":"Version upload has missing files: index.html",
                "details":{"missingPaths":["index.html"],"missingCount":1}
            })
        );

        // A full disk (ENOSPC) or exhausted quota (EDQUOT) is the site's
        // storage, not the finalizer: it gets its own code.
        for errno in [libc::ENOSPC, libc::EDQUOT] {
            let error = FinalizeError::Io {
                path: PathBuf::from("/srv/htdocs/stage/index.html"),
                source: std::io::Error::from_raw_os_error(errno),
            };
            write_site_finalize_error(&error, &path).unwrap();
            let value: Value = serde_json::from_slice(&fs::read(&path).unwrap()).unwrap();
            assert_eq!(value["code"], "runtime_storage_full", "errno {errno}");
            assert_eq!(value["message"], "The site's disk is full.");
            assert_eq!(value["details"]["path"], "/srv/htdocs/stage/index.html");
        }
        let other = FinalizeError::Io {
            path: PathBuf::from("/srv/htdocs/stage/index.html"),
            source: std::io::Error::from(std::io::ErrorKind::PermissionDenied),
        };
        write_site_finalize_error(&other, &path).unwrap();
        let value: Value = serde_json::from_slice(&fs::read(&path).unwrap()).unwrap();
        assert_eq!(value["code"], "runtime_finalizer_io_error");
    }
}
