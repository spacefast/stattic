//! Shared primitives for the native site finalizer: the finalize error type,
//! per-file serving metadata, and the bounded filesystem helpers the content
//! pipeline writes generated files through. The finalize orchestration itself
//! (`finalize_site`) arrives in a later stage.

use serde::Serialize;
use serde_json::{Map, Value};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::io;
use std::path::{Component, Path, PathBuf};

pub(crate) const ARTIFACT_SCHEMA: &str = "static-runtime-v2";
pub(crate) const ENGINE_VERSION: &str = "static-runtime-v2";

#[derive(Debug)]
pub enum FinalizeError {
    Io {
        path: PathBuf,
        source: io::Error,
    },
    Json {
        path: PathBuf,
        source: serde_json::Error,
    },
    Invalid {
        code: &'static str,
        message: String,
        details: Option<Value>,
    },
}

impl std::fmt::Display for FinalizeError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Io { path, source } => write!(f, "{}: {source}", path.display()),
            Self::Json { path, source } => write!(f, "{}: {source}", path.display()),
            Self::Invalid { code, message, .. } => write!(f, "{code}:{message}"),
        }
    }
}

impl std::error::Error for FinalizeError {}

pub type Result<T> = std::result::Result<T, FinalizeError>;

pub fn invalid<T>(code: &'static str, message: impl Into<String>) -> Result<T> {
    Err(FinalizeError::Invalid {
        code,
        message: message.into(),
        details: None,
    })
}

pub fn invalid_with_details<T>(
    code: &'static str,
    message: impl Into<String>,
    details: Value,
) -> Result<T> {
    Err(FinalizeError::Invalid {
        code,
        message: message.into(),
        details: Some(details),
    })
}

/// Serving metadata for one committed file.
#[derive(Debug, Clone, Serialize)]
pub struct FileMeta {
    pub disk_path: String,
    pub size: u64,
    pub mime: String,
    pub sha256: String,
    pub mtime: u64,
    pub last_modified: String,
    pub methods: Vec<&'static str>,
    pub executable: bool,
    pub forced_download_or_text: bool,
    pub local: bool,
    pub tier_class: &'static str,
    pub headers: BTreeMap<String, String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub compressed: Option<BTreeMap<String, CompressedMeta>>,
}

/// Serving metadata for a precompressed sidecar of a committed file.
#[derive(Debug, Clone, Serialize)]
pub struct CompressedMeta {
    pub disk_path: String,
    pub size: u64,
    pub sha256: String,
    pub headers: BTreeMap<String, String>,
    pub local: bool,
    pub tier_class: &'static str,
}

pub fn file_meta(path: &str, bytes: &[u8], declared_mime: Option<&str>) -> FileMeta {
    let hash = sha256(bytes);
    file_meta_from_parts(path, bytes.len() as u64, hash, bytes, declared_mime)
}

pub fn file_meta_from_parts(
    path: &str,
    size: u64,
    hash: String,
    prefix: &[u8],
    declared_mime: Option<&str>,
) -> FileMeta {
    let mtime = hash
        .get(..12)
        .and_then(|prefix| u64::from_str_radix(prefix, 16).ok())
        .unwrap_or(0)
        % 1_450_000_000;
    let mut mime = mime_for_path(path, declared_mime);
    if mime == "application/octet-stream" && prefix.starts_with(&[0x1f, 0x8b, 0x08]) {
        mime = "application/gzip".into();
    }
    let forced = php_like(path);
    let immutable = immutable_path(path);
    let last_modified = http_date(mtime);
    let edge = if immutable {
        "public, max-age=31536000, immutable"
    } else {
        "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60"
    };
    let mut headers = BTreeMap::new();
    headers.insert(
        "Content-Type".into(),
        if forced {
            if path.to_ascii_lowercase().ends_with(".phar") {
                "application/octet-stream".into()
            } else {
                "text/plain; charset=utf-8".into()
            }
        } else {
            mime.clone()
        },
    );
    headers.insert("ETag".into(), format!("\"{hash}\""));
    headers.insert("X-Content-Type-Options".into(), "nosniff".into());
    headers.insert(
        "Cache-Control".into(),
        if immutable {
            "public, max-age=31536000, immutable".into()
        } else {
            "public, max-age=0, must-revalidate".into()
        },
    );
    headers.insert("CDN-Cache-Control".into(), edge.into());
    headers.insert("Surrogate-Control".into(), edge.into());
    headers.insert("Last-Modified".into(), last_modified.clone());
    FileMeta {
        disk_path: path.into(),
        size,
        mime,
        sha256: hash,
        mtime,
        last_modified,
        methods: vec!["GET", "HEAD"],
        executable: false,
        forced_download_or_text: forced,
        local: true,
        tier_class: if size < tier_min_demote_bytes() as u64 {
            "small"
        } else {
            "eligible"
        },
        headers,
        compressed: None,
    }
}

fn tier_min_demote_bytes() -> usize {
    std::env::var("SPACEFAST_TIER_MIN_DEMOTE_BYTES")
        .ok()
        .and_then(|value| value.parse::<usize>().ok())
        .unwrap_or(32_768)
}

/// Attaches `.br`/`.gz` sidecar metadata to their base files so the runtime
/// can negotiate precompressed responses without a second lookup.
pub fn attach_precompressed_metadata(files: &mut BTreeMap<String, FileMeta>) {
    let paths: Vec<String> = files.keys().cloned().collect();
    for path in paths {
        if path.ends_with(".br") || path.ends_with(".gz") {
            continue;
        }
        let Some(base) = files.get(&path).cloned() else {
            continue;
        };
        if base.forced_download_or_text {
            continue;
        }
        let mut compressed = BTreeMap::new();
        for (encoding, suffix) in [("br", ".br"), ("gzip", ".gz")] {
            let sidecar_path = format!("{path}{suffix}");
            let Some(sidecar) = files.get(&sidecar_path) else {
                continue;
            };
            let mut headers = base.headers.clone();
            headers.insert("Content-Encoding".into(), encoding.into());
            headers.insert("ETag".into(), format!("\"{}\"", sidecar.sha256));
            headers.insert("Vary".into(), "Accept-Encoding".into());
            compressed.insert(
                encoding.into(),
                CompressedMeta {
                    disk_path: sidecar_path,
                    size: sidecar.size,
                    sha256: sidecar.sha256.clone(),
                    headers,
                    local: true,
                    tier_class: if sidecar.size < tier_min_demote_bytes() as u64 {
                        "small"
                    } else {
                        "eligible"
                    },
                },
            );
        }
        if !compressed.is_empty() {
            if let Some(meta) = files.get_mut(&path) {
                meta.headers.insert("Vary".into(), "Accept-Encoding".into());
                meta.compressed = Some(compressed);
            }
        }
    }
}

/// Writes a pipeline-generated file, preserving the uploaded original beside
/// the version the first time a committed path is overwritten.
pub(crate) fn write_generated(
    root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
    path: &str,
    bytes: &[u8],
    mime: Option<&str>,
) -> Result<()> {
    validate_relative_path(path)?;
    let target = root.join(path);
    if files.contains_key(path) {
        let originals = root
            .parent()
            .unwrap_or(root)
            .join("files-original")
            .join(path);
        if !originals.exists() {
            if let Some(parent) = originals.parent() {
                create_dir_all(parent)?;
            }
            fs::copy(&target, &originals).map_err(|source| FinalizeError::Io {
                path: originals,
                source,
            })?;
        }
    }
    write_bytes(&target, bytes)?;
    files.insert(path.into(), file_meta(path, bytes, mime));
    Ok(())
}

pub(crate) fn write_bytes(path: &Path, bytes: &[u8]) -> Result<()> {
    if let Some(parent) = path.parent() {
        create_dir_all(parent)?;
    }
    let suffix = path
        .extension()
        .and_then(|extension| extension.to_str())
        .map(|extension| format!("{extension}.tmp-generated"))
        .unwrap_or_else(|| "tmp-generated".into());
    let temporary = path.with_extension(suffix);
    fs::write(&temporary, bytes).map_err(|source| FinalizeError::Io {
        path: temporary.clone(),
        source,
    })?;
    fs::rename(&temporary, path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })
}

pub(crate) fn validate_id(value: &str, name: &str) -> Result<()> {
    if value.is_empty()
        || value.len() > 256
        || !value
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.'))
    {
        return invalid("invalid_id", format!("Invalid {name}."));
    }
    Ok(())
}

pub(crate) fn validate_relative_path(value: &str) -> Result<()> {
    let path = Path::new(value);
    if value.is_empty()
        || value.contains('\0')
        || value.contains('\\')
        || path.is_absolute()
        || path.components().any(|c| {
            matches!(
                c,
                Component::ParentDir | Component::RootDir | Component::Prefix(_)
            )
        })
    {
        return invalid("invalid_file_path", format!("Invalid path {value}."));
    }
    Ok(())
}

pub(crate) fn create_dir_all(path: &Path) -> Result<()> {
    fs::create_dir_all(path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })
}

pub(crate) fn remove_any(path: &Path) -> Result<()> {
    if !path.exists() {
        return Ok(());
    }
    if path.is_dir() {
        fs::remove_dir_all(path)
    } else {
        fs::remove_file(path)
    }
    .map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })
}

/// Writes pretty-printed JSON through the same atomic temp-then-rename path
/// as every other generated artifact.
pub fn write_json(path: &Path, value: &Value) -> Result<()> {
    let mut bytes = serde_json::to_vec_pretty(value).map_err(|source| FinalizeError::Json {
        path: path.into(),
        source,
    })?;
    bytes.push(b'\n');
    write_bytes(path, &bytes)
}

/// Writes a `<?php return ...;` artifact the PHP serving layer can `include`.
pub fn write_php(path: &Path, value: &Value) -> Result<()> {
    write_bytes(
        path,
        format!("<?php\nreturn {};\n", php_export(value, 0)).as_bytes(),
    )
}

fn php_export(value: &Value, indent: usize) -> String {
    match value {
        Value::Null => "null".into(),
        Value::Bool(v) => {
            if *v {
                "true".into()
            } else {
                "false".into()
            }
        }
        Value::Number(v) => v.to_string(),
        Value::String(v) => format!("'{}'", v.replace('\\', "\\\\").replace('\'', "\\'")),
        Value::Array(items) => {
            if items.is_empty() {
                return "[]".into();
            }
            let n = indent + 4;
            let mut s = String::from("[\n");
            for v in items {
                s.push_str(&" ".repeat(n));
                s.push_str(&php_export(v, n));
                s.push_str(",\n");
            }
            s.push_str(&" ".repeat(indent));
            s.push(']');
            s
        }
        Value::Object(map) => {
            if map.is_empty() {
                return "[]".into();
            }
            let n = indent + 4;
            let mut s = String::from("[\n");
            for (k, v) in map {
                s.push_str(&" ".repeat(n));
                s.push_str(&php_export(&Value::String(k.clone()), n));
                s.push_str(" => ");
                s.push_str(&php_export(v, n));
                s.push_str(",\n");
            }
            s.push_str(&" ".repeat(indent));
            s.push(']');
            s
        }
    }
}

/// The shared schema/engine/timestamp preamble every runtime artifact carries.
pub fn artifact_metadata(generated_at: &str) -> Map<String, Value> {
    Map::from_iter([
        ("runtime_schema".into(), serde_json::json!(ARTIFACT_SCHEMA)),
        (
            "runtime_engine_version".into(),
            serde_json::json!(ENGINE_VERSION),
        ),
        ("generated_at".into(), serde_json::json!(generated_at)),
    ])
}

pub(crate) fn read_bounded(path: &Path, max: usize) -> Result<Vec<u8>> {
    let size = fs::metadata(path)
        .map_err(|source| FinalizeError::Io {
            path: path.into(),
            source,
        })?
        .len();
    if size > max as u64 {
        return invalid(
            "source_too_large",
            format!("{} exceeds {max} bytes.", path.display()),
        );
    }
    let bytes = fs::read(path).map_err(|source| FinalizeError::Io {
        path: path.into(),
        source,
    })?;
    if bytes.len() > max {
        return invalid(
            "source_too_large",
            format!("{} exceeds {max} bytes.", path.display()),
        );
    }
    Ok(bytes)
}

pub(crate) fn sha256(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}

pub(crate) fn mime_for_path(path: &str, declared: Option<&str>) -> String {
    if let Some(v) = declared.filter(|v| !v.trim().is_empty()) {
        return v.into();
    }
    match path
        .rsplit('.')
        .next()
        .unwrap_or("")
        .to_ascii_lowercase()
        .as_str()
    {
        "html" | "htm" => "text/html; charset=utf-8".into(),
        "css" => "text/css; charset=utf-8".into(),
        "js" | "mjs" => "text/javascript; charset=utf-8".into(),
        "json" | "map" => "application/json; charset=utf-8".into(),
        "svg" => "image/svg+xml".into(),
        "txt" => "text/plain; charset=utf-8".into(),
        "md" | "markdown" => "text/markdown; charset=utf-8".into(),
        _ => mime_guess::from_path(path)
            .first_raw()
            .unwrap_or("application/octet-stream")
            .into(),
    }
}

pub(crate) fn php_like(path: &str) -> bool {
    matches!(
        path.rsplit('.')
            .next()
            .unwrap_or("")
            .to_ascii_lowercase()
            .as_str(),
        "php" | "phtml" | "phar" | "php3" | "php4" | "php5" | "php7" | "php8"
    )
}

fn immutable_path(path: &str) -> bool {
    let normalized = path.trim_start_matches('/');
    !matches!(
        path.rsplit('.')
            .next()
            .unwrap_or("")
            .to_ascii_lowercase()
            .as_str(),
        "html" | "htm" | "php" | "txt" | "xml"
    ) && ["_next/static/", "_app/immutable/", "_nuxt/", "_astro/"]
        .iter()
        .any(|prefix| normalized.starts_with(prefix))
}

fn http_date(timestamp: u64) -> String {
    const WEEK: [&str; 7] = ["Thu", "Fri", "Sat", "Sun", "Mon", "Tue", "Wed"];
    let days = timestamp / 86400;
    let secs = timestamp % 86400;
    let (year, month, day) = civil_from_days(days as i64);
    format!(
        "{}, {:02} {} {:04} {:02}:{:02}:{:02} GMT",
        WEEK[(days % 7) as usize],
        day,
        ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
            [(month - 1) as usize],
        year,
        secs / 3600,
        (secs % 3600) / 60,
        secs % 60
    )
}

pub(crate) fn civil_from_days(days: i64) -> (i64, u64, u64) {
    let z = days + 719468;
    let era = if z >= 0 { z } else { z - 146096 } / 146097;
    let doe = z - era * 146097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    let mut y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = mp + if mp < 10 { 3 } else { -9 };
    y += if m <= 2 { 1 } else { 0 };
    (y, m as u64, d as u64)
}

#[cfg(test)]
mod tests {
    use super::file_meta_from_parts;

    #[test]
    fn malformed_hashes_fall_back_without_panicking() {
        for hash in ["", "abc", "éééééé", "not-hex-data"] {
            let meta = file_meta_from_parts("index.html", 0, hash.into(), b"", None);
            assert_eq!(meta.mtime, 0);
        }
    }
}
