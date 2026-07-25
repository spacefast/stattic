//! Committing uploaded and retained bytes into the per-space CAS and staging
//! a version's `files/` tree from it, plus template-file overwrites that
//! preserve the uploaded originals.
//!
//! The donor's `SiteFinalizeInput` orchestration struct is not ported;
//! entry points take the narrow identifiers the finalize orchestration
//! (a later stage) will supply.

use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::fs::File;
use std::io;
use std::io::{BufReader, BufWriter, Read, Write};
use std::path::Path;

use crate::finalize::{
    create_dir_all, file_meta_from_parts, invalid, invalid_with_details, mime_for_path,
    validate_id, validate_relative_path, write_generated, FileMeta, FinalizeError, Result,
};
use crate::protocol::{TEMPLATE_MAX_BYTES, TEMPLATE_VARIANT_FILE_LIMIT};

/// Commits every session file (retained CAS objects first, then uploads)
/// into the stage root, recording serving metadata per committed path.
pub fn commit_session_files(
    space_id: &str,
    upload_id: &str,
    session: &Value,
    private_root: &Path,
    stage_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<()> {
    let upload_root = private_root
        .join("runtime/uploads")
        .join(upload_id)
        .join("files");
    let reusable = session.get("reusable_version_id").and_then(Value::as_str);
    let retained = session
        .get("retained_files")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    for entry in retained {
        let path = file_entry_path(&entry)?;
        let reusable = reusable.ok_or_else(|| FinalizeError::Invalid {
            code: "reusable_version_required",
            message: "Retained files require a reusable version.".into(),
            details: None,
        })?;
        validate_id(reusable, "reusable_version_id")?;
        let sha = entry
            .get("sha256")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_ascii_lowercase();
        if sha.len() != 64 {
            return invalid(
                "version_reusable_file_missing",
                format!("A retained file has no content hash: {path}."),
            );
        }
        // The PHP storage boundary hydrates every retained byte into the CAS
        // before entering WASI. Rust owns all interpretation from this stable,
        // content-addressed input onward.
        let source = private_root
            .join("spaces")
            .join(space_id)
            .join("blobs")
            .join(&sha[..2])
            .join(&sha);
        if !source.is_file() {
            return invalid(
                "version_reusable_file_missing",
                format!("A retained CAS object is missing: {path}."),
            );
        }
        commit_file(
            private_root,
            space_id,
            stage_root,
            &path,
            &source,
            &entry,
            files,
        )?;
    }

    let session_mode = session
        .get("mode")
        .or_else(|| session.get("session_mode"))
        .and_then(Value::as_str)
        .unwrap_or("declared");
    let entries = if session_mode == "open" {
        uploaded_files(&upload_root)?
    } else {
        session
            .get("files")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
    };
    let mut missing_paths = Vec::new();
    for entry in &entries {
        let path = file_entry_path(entry)?;
        if !upload_root.join(&path).is_file() {
            missing_paths.push(path);
        }
    }
    if !missing_paths.is_empty() {
        let missing_count = missing_paths.len();
        let summary = missing_paths.iter().take(20).cloned().collect::<Vec<_>>();
        let suffix = if missing_count > 20 { ", ..." } else { "" };
        return invalid_with_details(
            "version_upload_incomplete",
            format!(
                "Version upload has missing files: {}{suffix}",
                summary.join(", ")
            ),
            json!({
                "missingPaths": missing_paths.into_iter().take(100).collect::<Vec<_>>(),
                "missingCount": missing_count,
            }),
        );
    }
    for entry in entries {
        let path = file_entry_path(&entry)?;
        let source = upload_root.join(&path);
        commit_file(
            private_root,
            space_id,
            stage_root,
            &path,
            &source,
            &entry,
            files,
        )?;
    }
    Ok(())
}

fn file_entry_path(entry: &Value) -> Result<String> {
    let path = entry
        .get("path")
        .and_then(Value::as_str)
        .ok_or_else(|| FinalizeError::Invalid {
            code: "invalid_file",
            message: "File path is missing.".into(),
            details: None,
        })?;
    validate_relative_path(path)?;
    Ok(path.into())
}

fn uploaded_files(root: &Path) -> Result<Vec<Value>> {
    if !root.is_dir() {
        return Ok(Vec::new());
    }
    let mut entries = Vec::new();
    for item in walkdir::WalkDir::new(root).follow_links(false) {
        let item = item.map_err(|source| FinalizeError::Io {
            path: root.to_path_buf(),
            source: io::Error::other(source),
        })?;
        if !item.file_type().is_file() {
            continue;
        }
        let relative = item.path().strip_prefix(root).unwrap_or(item.path());
        let path = relative.to_string_lossy().replace('\\', "/");
        validate_relative_path(&path)?;
        let (size, hash, _) = inspect_file(item.path(), None)?;
        entries.push(json!({
            "path": path,
            "size": size,
            "sha256": hash,
            "contentType": mime_for_path(&path, None),
        }));
    }
    entries.sort_by(|a, b| a["path"].as_str().cmp(&b["path"].as_str()));
    Ok(entries)
}

pub(crate) fn commit_file(
    private_root: &Path,
    space_id: &str,
    stage_root: &Path,
    path: &str,
    source: &Path,
    entry: &Value,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<()> {
    if !source.is_file() {
        return invalid_with_details(
            "version_upload_incomplete",
            format!("Missing file {path}."),
            json!({"missingPaths":[path],"missingCount":1}),
        );
    }
    let blob_root = private_root.join("spaces").join(space_id).join("blobs");
    create_dir_all(&blob_root)?;
    let temporary = blob_root.join(".tmp-finalize");
    let (size, actual, prefix) = match inspect_file(source, Some(&temporary)) {
        Ok(inspected) => inspected,
        Err(error) => {
            let _ = fs::remove_file(&temporary);
            return Err(error);
        }
    };
    let expected = entry
        .get("sha256")
        .and_then(Value::as_str)
        .unwrap_or(&actual);
    if !actual.eq_ignore_ascii_case(expected) {
        let _ = fs::remove_file(&temporary);
        return invalid(
            "version_file_hash_mismatch",
            format!("Hash mismatch for {path}."),
        );
    }
    if let Some(expected_size) = entry.get("size").and_then(Value::as_u64) {
        if expected_size != size {
            let _ = fs::remove_file(&temporary);
            return invalid(
                "version_file_size_mismatch",
                format!("Size mismatch for {path}."),
            );
        }
    }
    let blob = blob_root.join(&actual[..2]).join(&actual);
    if !blob.is_file() {
        create_dir_all(blob.parent().unwrap_or(private_root))?;
        match fs::rename(&temporary, &blob) {
            Ok(()) => {}
            Err(_) if blob.is_file() => {
                let _ = fs::remove_file(&temporary);
            }
            Err(source) => return Err(FinalizeError::Io { path: blob, source }),
        }
    } else {
        let existing = inspect_file(&blob, None)?;
        if existing.0 == size && existing.1 == actual {
            let _ = fs::remove_file(&temporary);
        } else {
            fs::rename(&temporary, &blob).map_err(|source| FinalizeError::Io {
                path: blob.clone(),
                source,
            })?;
        }
    }
    let target = stage_root.join("files").join(path);
    create_dir_all(target.parent().unwrap_or(stage_root))?;
    if fs::hard_link(&blob, &target).is_err() {
        fs::copy(&blob, &target).map_err(|source| FinalizeError::Io {
            path: target.clone(),
            source,
        })?;
    }
    let declared_mime = entry
        .get("contentType")
        .or_else(|| entry.get("content_type"))
        .and_then(Value::as_str);
    files.insert(
        path.to_string(),
        file_meta_from_parts(path, size, actual, &prefix, declared_mime),
    );
    Ok(())
}

fn inspect_file(source: &Path, copy_to: Option<&Path>) -> Result<(u64, String, Vec<u8>)> {
    let file = File::open(source).map_err(|source_error| FinalizeError::Io {
        path: source.to_path_buf(),
        source: source_error,
    })?;
    let mut reader = BufReader::new(file);
    let mut writer = match copy_to {
        Some(path) => Some(BufWriter::new(File::create(path).map_err(
            |source_error| FinalizeError::Io {
                path: path.to_path_buf(),
                source: source_error,
            },
        )?)),
        None => None,
    };
    let mut digest = Sha256::new();
    let mut size = 0_u64;
    let mut prefix = Vec::with_capacity(3);
    let mut buffer = [0_u8; 64 * 1024];
    loop {
        let read = reader
            .read(&mut buffer)
            .map_err(|source_error| FinalizeError::Io {
                path: source.to_path_buf(),
                source: source_error,
            })?;
        if read == 0 {
            break;
        }
        let chunk = &buffer[..read];
        if prefix.len() < 3 {
            prefix.extend_from_slice(&chunk[..chunk.len().min(3 - prefix.len())]);
        }
        digest.update(chunk);
        size += read as u64;
        if let Some(writer) = writer.as_mut() {
            writer
                .write_all(chunk)
                .map_err(|source_error| FinalizeError::Io {
                    path: copy_to.unwrap_or(source).to_path_buf(),
                    source: source_error,
                })?;
        }
    }
    if let Some(mut writer) = writer {
        writer.flush().map_err(|source_error| FinalizeError::Io {
            path: copy_to.unwrap_or(source).to_path_buf(),
            source: source_error,
        })?;
    }
    Ok((size, format!("{:x}", digest.finalize()), prefix))
}

/// Overwrites committed files with caller-supplied template contents,
/// preserving the uploaded original beside the version.
pub fn apply_templates(
    body: &Value,
    stage_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
) -> Result<()> {
    let Some(templates) = body.get("template_files").and_then(Value::as_object) else {
        return Ok(());
    };
    if templates.len() > TEMPLATE_VARIANT_FILE_LIMIT {
        return invalid(
            "invalid_template_files",
            "template_files supports up to 100 entries.",
        );
    }
    for (path, content) in templates {
        validate_relative_path(path)?;
        let content = content.as_str().ok_or_else(|| FinalizeError::Invalid {
            code: "invalid_template_files",
            message: "Template contents must be strings.".into(),
            details: None,
        })?;
        if content.len() > TEMPLATE_MAX_BYTES || !files.contains_key(path) {
            return invalid(
                "invalid_template_files",
                format!("Invalid template file {path}."),
            );
        }
        let target = stage_root.join("files").join(path);
        let original = stage_root.join("files-original").join(path);
        create_dir_all(original.parent().unwrap_or(stage_root))?;
        fs::copy(&target, &original).map_err(|source| FinalizeError::Io {
            path: original,
            source,
        })?;
        write_generated(
            &stage_root.join("files"),
            files,
            path,
            content.as_bytes(),
            None,
        )?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::sha256;
    use serde_json::json;
    use tempfile::tempdir;

    #[test]
    fn corrupt_existing_cas_object_is_replaced_before_linking() {
        let temp = tempdir().unwrap();
        let private = temp.path().join("storage");
        let source = private.join("runtime/uploads/u/files/index.html");
        fs::create_dir_all(source.parent().unwrap()).unwrap();
        fs::write(&source, b"correct").unwrap();
        let hash = sha256(b"correct");
        let blob = private.join(format!("spaces/s/blobs/{}/{hash}", &hash[..2]));
        fs::create_dir_all(blob.parent().unwrap()).unwrap();
        fs::write(&blob, b"corrupt").unwrap();
        let stage = private.join("spaces/s/versions/.v.rust-finalizing");
        fs::create_dir_all(stage.join("files")).unwrap();
        let mut files = BTreeMap::new();
        commit_file(
            &private,
            "s",
            &stage,
            "index.html",
            &source,
            &json!({"path":"index.html","size":7,"sha256":hash}),
            &mut files,
        )
        .unwrap();
        assert_eq!(fs::read(&blob).unwrap(), b"correct");
        assert_eq!(
            fs::read(stage.join("files/index.html")).unwrap(),
            b"correct"
        );
    }

    #[test]
    fn declared_missing_files_report_structured_upload_details() {
        let temp = tempdir().unwrap();
        let private = temp.path().join("storage");
        let stage = private.join("spaces/s/versions/.v.rust-finalizing");
        let error = commit_session_files(
            "s",
            "u",
            &json!({
                "mode":"declared",
                "files":[
                    {"path":"index.html","size":1,"sha256":"00"},
                    {"path":"docs/page.html","size":1,"sha256":"00"}
                ]
            }),
            &private,
            &stage,
            &mut BTreeMap::new(),
        )
        .unwrap_err();
        match error {
            FinalizeError::Invalid { code, details, .. } => {
                assert_eq!(code, "version_upload_incomplete");
                assert_eq!(
                    details,
                    Some(json!({
                        "missingPaths":["index.html","docs/page.html"],
                        "missingCount":2
                    }))
                );
            }
            other => panic!("unexpected error: {other}"),
        }
    }

    // Adapted from the donor's finalize-level
    // `transforms_never_mutate_content_addressed_source_bytes`: the commit +
    // template layers are exercised directly (the `finalize_site`
    // orchestration arrives in a later stage); the assertions on CAS blob,
    // preserved original, and resolved output are donor-verbatim.
    #[test]
    fn transforms_never_mutate_content_addressed_source_bytes() {
        let original: &[u8] = b"<html><body>{{ value }}</body></html>";
        let temp = tempdir().unwrap();
        let private = temp.path().join(".stattic/storage");
        let upload = private.join("runtime/uploads/u/files");
        fs::create_dir_all(&upload).unwrap();
        fs::write(upload.join("index.html"), original).unwrap();
        let hash = sha256(original);
        let stage = private.join("spaces/s/versions/.v.rust-finalizing");
        fs::create_dir_all(stage.join("files")).unwrap();
        let mut files = BTreeMap::new();
        commit_session_files(
            "s",
            "u",
            &json!({
                "mode":"declared",
                "files":[{"path":"index.html","size":original.len(),"sha256":hash}],
            }),
            &private,
            &stage,
            &mut files,
        )
        .unwrap();
        apply_templates(
            &json!({"template_files":{"index.html":"<html><body>resolved</body></html>"}}),
            &stage,
            &mut files,
        )
        .unwrap();
        assert_eq!(
            fs::read(private.join(format!("spaces/s/blobs/{}/{hash}", &hash[..2]))).unwrap(),
            original
        );
        assert_eq!(
            fs::read(stage.join("files-original/index.html")).unwrap(),
            original
        );
        assert_eq!(
            fs::read_to_string(stage.join("files/index.html")).unwrap(),
            "<html><body>resolved</body></html>"
        );
    }
}
