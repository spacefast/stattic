//! Host-neutral Stattic deployment bundles.
//!
//! The existing site finalizer remains the single semantic compiler. This
//! adapter gives it an isolated, synthetic storage root and publishes the
//! resulting immutable version as a portable directory bundle. Space and
//! version coordinates live in the descriptor, but never participate in the
//! content or deployment digests.

use serde::{Deserialize, Serialize};
use serde_json::json;
use sha2::{Digest, Sha256};
use std::fs;
use std::io;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};

use crate::finalize::{sha256, validate_id, validate_relative_path, FinalizeError};
use crate::model::{SiteFinalizeInput, SITE_FINALIZE_INPUT_FORMAT};
use crate::site_finalize::finalize_site;

pub const BUNDLE_FORMAT: &str = "stattic.bundle.v1";
pub const PORTABLE_STATIC_PROFILE: &str = "portable-static";
const PORTABLE_COORDINATE: &str = "portable";
const GENERATED_AT: &str = "1970-01-01T00:00:00Z";
const FILE_LIMIT: usize = 100_000;
const TOTAL_BYTES_LIMIT: u64 = 2 * 1024 * 1024 * 1024;
static TEMPORARY_PATH_SEQUENCE: AtomicU64 = AtomicU64::new(0);

#[derive(Debug)]
pub enum BundleError {
    Invalid {
        code: &'static str,
        message: String,
    },
    Io {
        path: PathBuf,
        source: io::Error,
    },
    Json {
        path: PathBuf,
        source: serde_json::Error,
    },
    Finalize(FinalizeError),
}

impl std::fmt::Display for BundleError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Invalid { code, message } => write!(formatter, "{code}:{message}"),
            Self::Io { path, source } => write!(formatter, "{}: {source}", path.display()),
            Self::Json { path, source } => write!(formatter, "{}: {source}", path.display()),
            Self::Finalize(error) => error.fmt(formatter),
        }
    }
}

impl std::error::Error for BundleError {}

impl From<FinalizeError> for BundleError {
    fn from(value: FinalizeError) -> Self {
        Self::Finalize(value)
    }
}

pub type Result<T> = std::result::Result<T, BundleError>;

#[derive(Debug, Clone)]
pub struct BuildBundleInput {
    pub source_root: PathBuf,
    pub output_root: PathBuf,
    pub space_id: String,
    pub version_id: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct BundleDescriptor {
    pub format: String,
    pub profile: String,
    pub space_id: String,
    pub version_id: String,
    pub content_digest: String,
    pub binding_digest: Option<String>,
    pub deployment_digest: String,
    pub builder: BundleBuilder,
    pub requirements: BundleRequirements,
    pub artifacts: Vec<BundleArtifact>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct BundleBuilder {
    pub abi: String,
    pub version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct BundleRequirements {
    pub runtime_abi: String,
    pub server_build: bool,
    pub rust_finalizer: bool,
    pub zero_runner: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct BundleArtifact {
    pub path: String,
    pub size: u64,
    pub sha256: String,
}

/// Compiles a source directory into a deterministic, compiler-free bundle.
///
/// `output_root` must be outside `source_root`; this prevents a previous build
/// from silently becoming a source input. Replacement is staged beside the
/// destination and published with a rename.
pub fn build_bundle(input: BuildBundleInput) -> Result<BundleDescriptor> {
    validate_coordinate(&input.space_id, "space_id")?;
    validate_coordinate(&input.version_id, "version_id")?;

    let source_root = fs::canonicalize(&input.source_root).map_err(|source| BundleError::Io {
        path: input.source_root.clone(),
        source,
    })?;
    if !source_root.is_dir() {
        return invalid(
            "bundle_source_invalid",
            "The Stattic build source must be a directory.",
        );
    }
    let output_root = absolute_path(&input.output_root)?;
    if output_root.starts_with(&source_root) || source_root.starts_with(&output_root) {
        return invalid(
            "bundle_output_overlaps_source",
            "The bundle output must be outside the source directory.",
        );
    }
    let output_parent = output_root.parent().ok_or_else(|| BundleError::Invalid {
        code: "bundle_output_invalid",
        message: "The bundle output has no parent directory.".into(),
    })?;
    fs::create_dir_all(output_parent).map_err(|source| BundleError::Io {
        path: output_parent.to_path_buf(),
        source,
    })?;

    let output_name = output_root
        .file_name()
        .and_then(|name| name.to_str())
        .unwrap_or("site.stattic");
    let stage_root = reserve_stage_directory(output_parent, output_name)?;

    let built = build_in_stage(
        &source_root,
        &stage_root,
        &input.space_id,
        &input.version_id,
    );
    let descriptor = match built {
        Ok(descriptor) => descriptor,
        Err(error) => {
            let _ = remove_any(&stage_root);
            return Err(error);
        }
    };

    replace_atomically(&stage_root, &output_root)?;
    Ok(descriptor)
}

fn build_in_stage(
    source_root: &Path,
    stage_root: &Path,
    space_id: &str,
    version_id: &str,
) -> Result<BundleDescriptor> {
    let private_root = stage_root.join(".builder/storage");
    let upload_root = private_root.join("runtime/uploads/source/files");
    fs::create_dir_all(&upload_root).map_err(|source| BundleError::Io {
        path: upload_root.clone(),
        source,
    })?;
    copy_source(source_root, &upload_root)?;

    let output = finalize_site(SiteFinalizeInput {
        format: SITE_FINALIZE_INPUT_FORMAT.into(),
        version_root: private_root.to_string_lossy().into_owned(),
        space_id: PORTABLE_COORDINATE.into(),
        version_id: PORTABLE_COORDINATE.into(),
        upload_id: Some("source".into()),
        previous_pack: None,
        generated_at: GENERATED_AT.into(),
        ready_at: 0,
        session: json!({
            "mode": "open",
            "metadata": {
                "mode": "website"
            }
        }),
        body: json!({
            "serving": {
                "config": {
                    "experimental_gutenberg": true
                }
            }
        }),
        zero_endpoints: Vec::new(),
        zero_runs: Vec::new(),
    })?;
    if output
        .diagnostics
        .iter()
        .any(|diagnostic| diagnostic.severity == crate::model::RuntimeDiagnosticSeverity::Error)
    {
        return invalid(
            "bundle_build_failed",
            "The Stattic Builder reported an error diagnostic.",
        );
    }

    let version_root = private_root
        .join("spaces")
        .join(PORTABLE_COORDINATE)
        .join("versions")
        .join(PORTABLE_COORDINATE);
    let payload_root = stage_root.join("payload");
    fs::rename(&version_root, &payload_root).map_err(|source| BundleError::Io {
        path: payload_root.clone(),
        source,
    })?;
    remove_any(&stage_root.join(".builder"))?;

    let artifacts = collect_artifacts(&payload_root)?;
    let content_digest = digest_artifacts(&artifacts);
    let deployment_digest = deployment_digest(PORTABLE_STATIC_PROFILE, &content_digest, None);
    let descriptor = BundleDescriptor {
        format: BUNDLE_FORMAT.into(),
        profile: PORTABLE_STATIC_PROFILE.into(),
        space_id: space_id.into(),
        version_id: version_id.into(),
        content_digest,
        binding_digest: None,
        deployment_digest,
        builder: BundleBuilder {
            abi: "stattic.builder.v1".into(),
            version: env!("CARGO_PKG_VERSION").into(),
        },
        requirements: BundleRequirements {
            runtime_abi: "static-runtime-v2".into(),
            server_build: false,
            rust_finalizer: false,
            zero_runner: false,
        },
        artifacts,
    };
    write_descriptor(&stage_root.join("bundle.json"), &descriptor)?;
    Ok(descriptor)
}

/// Validates a bundle without executing its content or invoking a compiler.
pub fn inspect_bundle(root: impl AsRef<Path>) -> Result<BundleDescriptor> {
    let root = root.as_ref();
    let descriptor_path = root.join("bundle.json");
    let bytes = fs::read(&descriptor_path).map_err(|source| BundleError::Io {
        path: descriptor_path.clone(),
        source,
    })?;
    let descriptor: BundleDescriptor =
        serde_json::from_slice(&bytes).map_err(|source| BundleError::Json {
            path: descriptor_path,
            source,
        })?;
    if descriptor.format != BUNDLE_FORMAT {
        return invalid(
            "bundle_format_invalid",
            "Unsupported Stattic bundle format.",
        );
    }
    if descriptor.profile != PORTABLE_STATIC_PROFILE {
        return invalid(
            "bundle_profile_invalid",
            "Unsupported Stattic bundle profile.",
        );
    }
    if descriptor.binding_digest.is_some()
        || descriptor.requirements.server_build
        || descriptor.requirements.rust_finalizer
        || descriptor.requirements.zero_runner
    {
        return invalid(
            "bundle_requirements_invalid",
            "A portable-static bundle must be compiler-free and unbound.",
        );
    }
    validate_coordinate(&descriptor.space_id, "space_id")?;
    validate_coordinate(&descriptor.version_id, "version_id")?;

    let actual = collect_artifacts(&root.join("payload"))?;
    if actual != descriptor.artifacts {
        return invalid(
            "bundle_artifact_manifest_mismatch",
            "Bundle payload entries do not match bundle.json.",
        );
    }
    let content_digest = digest_artifacts(&actual);
    if content_digest != descriptor.content_digest {
        return invalid(
            "bundle_content_digest_mismatch",
            "Bundle content digest does not match its payload.",
        );
    }
    let deployment = deployment_digest(
        &descriptor.profile,
        &descriptor.content_digest,
        descriptor.binding_digest.as_deref(),
    );
    if deployment != descriptor.deployment_digest {
        return invalid(
            "bundle_deployment_digest_mismatch",
            "Bundle deployment digest is invalid.",
        );
    }
    Ok(descriptor)
}

fn copy_source(source_root: &Path, target_root: &Path) -> Result<()> {
    let mut count = 0_usize;
    let mut total = 0_u64;
    for entry in walkdir::WalkDir::new(source_root).follow_links(false) {
        let entry = entry.map_err(|source| BundleError::Io {
            path: source_root.to_path_buf(),
            source: io::Error::other(source),
        })?;
        let relative = entry
            .path()
            .strip_prefix(source_root)
            .unwrap_or(entry.path());
        if relative.as_os_str().is_empty() {
            continue;
        }
        let portable = relative.to_string_lossy().replace('\\', "/");
        validate_relative_path(&portable).map_err(BundleError::Finalize)?;
        if entry.file_type().is_symlink() {
            return invalid(
                "bundle_source_symlink_unsupported",
                format!("Symlinks are not supported in portable bundles: {portable}."),
            );
        }
        if entry.file_type().is_dir() {
            continue;
        }
        if !entry.file_type().is_file() {
            return invalid(
                "bundle_source_entry_unsupported",
                format!("Unsupported source entry: {portable}."),
            );
        }
        count += 1;
        let size = entry
            .metadata()
            .map_err(|source| BundleError::Io {
                path: entry.path().to_path_buf(),
                source: io::Error::other(source),
            })?
            .len();
        total = total.saturating_add(size);
        if count > FILE_LIMIT || total > TOTAL_BYTES_LIMIT {
            return invalid(
                "bundle_source_limits_exceeded",
                "The source exceeds the portable bundle file or byte limit.",
            );
        }
        let target = target_root.join(relative);
        if let Some(parent) = target.parent() {
            fs::create_dir_all(parent).map_err(|source| BundleError::Io {
                path: parent.to_path_buf(),
                source,
            })?;
        }
        fs::copy(entry.path(), &target).map_err(|source| BundleError::Io {
            path: target,
            source,
        })?;
    }
    if count == 0 {
        return invalid("bundle_source_empty", "The source directory is empty.");
    }
    Ok(())
}

fn collect_artifacts(payload_root: &Path) -> Result<Vec<BundleArtifact>> {
    if !payload_root.is_dir() {
        return invalid("bundle_payload_missing", "Bundle payload is missing.");
    }
    let mut artifacts = Vec::new();
    for entry in walkdir::WalkDir::new(payload_root).follow_links(false) {
        let entry = entry.map_err(|source| BundleError::Io {
            path: payload_root.to_path_buf(),
            source: io::Error::other(source),
        })?;
        if entry.file_type().is_symlink() {
            return invalid(
                "bundle_payload_symlink_unsupported",
                "Bundle payload contains a symlink.",
            );
        }
        if !entry.file_type().is_file() {
            continue;
        }
        let relative = entry
            .path()
            .strip_prefix(payload_root)
            .unwrap_or(entry.path())
            .to_string_lossy()
            .replace('\\', "/");
        validate_relative_path(&relative).map_err(BundleError::Finalize)?;
        let bytes = fs::read(entry.path()).map_err(|source| BundleError::Io {
            path: entry.path().to_path_buf(),
            source,
        })?;
        artifacts.push(BundleArtifact {
            path: relative,
            size: bytes.len() as u64,
            sha256: sha256(&bytes),
        });
    }
    artifacts.sort_by(|left, right| left.path.cmp(&right.path));
    Ok(artifacts)
}

fn digest_artifacts(artifacts: &[BundleArtifact]) -> String {
    let mut digest = Sha256::new();
    digest.update(b"stattic.bundle.content.v1\0");
    for artifact in artifacts {
        digest.update(artifact.path.as_bytes());
        digest.update(b"\0");
        digest.update(artifact.size.to_string().as_bytes());
        digest.update(b"\0");
        digest.update(artifact.sha256.as_bytes());
        digest.update(b"\0");
    }
    format!("sha256:{:x}", digest.finalize())
}

fn deployment_digest(profile: &str, content_digest: &str, binding: Option<&str>) -> String {
    let mut digest = Sha256::new();
    digest.update(b"stattic.bundle.deployment.v1\0");
    digest.update(profile.as_bytes());
    digest.update(b"\0");
    digest.update(content_digest.as_bytes());
    digest.update(b"\0");
    digest.update(binding.unwrap_or("").as_bytes());
    format!("sha256:{:x}", digest.finalize())
}

fn write_descriptor(path: &Path, descriptor: &BundleDescriptor) -> Result<()> {
    let mut bytes = serde_json::to_vec_pretty(descriptor).map_err(|source| BundleError::Json {
        path: path.to_path_buf(),
        source,
    })?;
    bytes.push(b'\n');
    fs::write(path, bytes).map_err(|source| BundleError::Io {
        path: path.to_path_buf(),
        source,
    })
}

fn replace_atomically(stage: &Path, output: &Path) -> Result<()> {
    let output_parent = output.parent().ok_or_else(|| BundleError::Invalid {
        code: "bundle_output_invalid",
        message: "The bundle output has no parent directory.".into(),
    })?;
    let output_name = output
        .file_name()
        .and_then(|value| value.to_str())
        .unwrap_or("site.stattic");
    let backup = unused_sibling(output_parent, output_name, "previous")?;
    let had_output = output.exists();
    if had_output {
        fs::rename(output, &backup).map_err(|source| BundleError::Io {
            path: output.to_path_buf(),
            source,
        })?;
    }
    if let Err(source) = fs::rename(stage, output) {
        if had_output {
            let _ = fs::rename(&backup, output);
        }
        return Err(BundleError::Io {
            path: output.to_path_buf(),
            source,
        });
    }
    if had_output {
        remove_any(&backup)?;
    }
    Ok(())
}

fn reserve_stage_directory(parent: &Path, output_name: &str) -> Result<PathBuf> {
    for _ in 0..1_000 {
        let candidate = temporary_sibling(parent, output_name, "building");
        match fs::create_dir(&candidate) {
            Ok(()) => return Ok(candidate),
            Err(source) if source.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(source) => {
                return Err(BundleError::Io {
                    path: candidate,
                    source,
                });
            }
        }
    }
    invalid(
        "bundle_temporary_path_exhausted",
        "Could not reserve a temporary bundle directory.",
    )
}

fn unused_sibling(parent: &Path, output_name: &str, purpose: &str) -> Result<PathBuf> {
    for _ in 0..1_000 {
        let candidate = temporary_sibling(parent, output_name, purpose);
        match fs::symlink_metadata(&candidate) {
            Ok(_) => continue,
            Err(source) if source.kind() == io::ErrorKind::NotFound => return Ok(candidate),
            Err(source) => {
                return Err(BundleError::Io {
                    path: candidate,
                    source,
                });
            }
        }
    }
    invalid(
        "bundle_temporary_path_exhausted",
        "Could not reserve a temporary bundle path.",
    )
}

fn temporary_sibling(parent: &Path, output_name: &str, purpose: &str) -> PathBuf {
    let sequence = TEMPORARY_PATH_SEQUENCE.fetch_add(1, Ordering::Relaxed);
    parent.join(format!(
        ".{output_name}.{purpose}-{}-{sequence}",
        std::process::id()
    ))
}

fn remove_any(path: &Path) -> Result<()> {
    if path.is_dir() {
        fs::remove_dir_all(path).map_err(|source| BundleError::Io {
            path: path.to_path_buf(),
            source,
        })?;
    } else if path.exists() {
        fs::remove_file(path).map_err(|source| BundleError::Io {
            path: path.to_path_buf(),
            source,
        })?;
    }
    Ok(())
}

fn absolute_path(path: &Path) -> Result<PathBuf> {
    let absolute = if path.is_absolute() {
        path.to_path_buf()
    } else {
        std::env::current_dir()
            .map(|cwd| cwd.join(path))
            .map_err(|source| BundleError::Io {
                path: path.to_path_buf(),
                source,
            })?
    };

    let mut existing = absolute.as_path();
    let mut missing = Vec::new();
    while !existing.exists() {
        let name = existing.file_name().ok_or_else(|| BundleError::Invalid {
            code: "bundle_output_invalid",
            message: "The bundle output has no existing ancestor.".into(),
        })?;
        missing.push(name.to_os_string());
        existing = existing.parent().ok_or_else(|| BundleError::Invalid {
            code: "bundle_output_invalid",
            message: "The bundle output has no existing ancestor.".into(),
        })?;
    }
    let mut normalized = fs::canonicalize(existing).map_err(|source| BundleError::Io {
        path: existing.to_path_buf(),
        source,
    })?;
    for component in missing.iter().rev() {
        normalized.push(component);
    }
    Ok(normalized)
}

fn validate_coordinate(value: &str, field: &'static str) -> Result<()> {
    validate_id(value, field).map_err(BundleError::Finalize)
}

fn invalid<T>(code: &'static str, message: impl Into<String>) -> Result<T> {
    Err(BundleError::Invalid {
        code,
        message: message.into(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::tempdir;

    fn source(root: &Path) -> PathBuf {
        let source = root.join("source");
        fs::create_dir_all(&source).unwrap();
        fs::write(
            source.join("index.md"),
            "---\ntitle: Hello\n---\n\n# Hello\n\nBuilt by **Stattic**.\n",
        )
        .unwrap();
        fs::write(
            source.join("_layout.html"),
            "<!doctype html><html><head><title>{{ page.title }}</title></head><body>{{ content }}</body></html>",
        )
        .unwrap();
        source
    }

    #[test]
    fn portable_identity_excludes_space_and_version_coordinates() {
        let temp = tempdir().unwrap();
        let source = source(temp.path());
        let first = build_bundle(BuildBundleInput {
            source_root: source.clone(),
            output_root: temp.path().join("first.stattic"),
            space_id: "space_one".into(),
            version_id: "version_one".into(),
        })
        .unwrap();
        let second = build_bundle(BuildBundleInput {
            source_root: source,
            output_root: temp.path().join("second.stattic"),
            space_id: "space_two".into(),
            version_id: "version_two".into(),
        })
        .unwrap();
        assert_eq!(first.content_digest, second.content_digest);
        assert_eq!(first.deployment_digest, second.deployment_digest);
        assert_ne!(first.space_id, second.space_id);
        let html =
            fs::read_to_string(temp.path().join("first.stattic/payload/files/.html")).unwrap();
        assert!(html.contains("<h1 class=\"wp-block-heading\" id=\"hello\">Hello</h1>"));
        assert!(html.contains("Built by <b>Stattic</b>."));
    }

    #[test]
    fn inspection_rejects_payload_tampering() {
        let temp = tempdir().unwrap();
        let source = source(temp.path());
        let output = temp.path().join("site.stattic");
        build_bundle(BuildBundleInput {
            source_root: source,
            output_root: output.clone(),
            space_id: "space".into(),
            version_id: "version".into(),
        })
        .unwrap();
        inspect_bundle(&output).unwrap();
        fs::write(output.join("payload/files/index.html"), "tampered").unwrap();
        assert!(matches!(
            inspect_bundle(&output),
            Err(BundleError::Invalid {
                code: "bundle_artifact_manifest_mismatch",
                ..
            })
        ));
    }

    #[test]
    fn output_cannot_overlap_source() {
        let temp = tempdir().unwrap();
        let source = source(temp.path());
        let result = build_bundle(BuildBundleInput {
            output_root: source.join("dist/site.stattic"),
            source_root: source,
            space_id: "space".into(),
            version_id: "version".into(),
        });
        match result {
            Err(BundleError::Invalid {
                code: "bundle_output_overlaps_source",
                ..
            }) => {}
            other => panic!("unexpected overlap result: {other:?}"),
        }
    }
}
