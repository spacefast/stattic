//! Shared primitives for the native site finalizer: the finalize error type,
//! per-file serving metadata, and the bounded filesystem helpers the content
//! pipeline writes generated files through. The orchestration that uses them
//! lives in `site_finalize`.

use serde::Serialize;
use serde_json::{Map, Value};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::fs;
use std::io;
use std::path::{Component, Path, PathBuf};

pub(crate) const ENGINE_VERSION: &str = "static-runtime-v2";

const TIER_MIN_DEMOTE_BYTES: u64 = 32_768;

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
}

/// The previous version's recorded identities for one path, offered to the
/// decoration pass: when the staged source still matches `source_sha256` (and
/// the pipeline context digest matched — the caller's precondition), the pass
/// adopts the served identity verbatim instead of re-reading, re-parsing and
/// re-writing the document.
#[derive(Debug, Clone)]
pub struct AdoptablePath {
    pub source_sha256: String,
    pub served_sha256: String,
    pub served_size: u64,
    pub served_content_type: String,
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
    let mtime = content_mtime(&hash);
    let mut mime = mime_for_path(path, declared_mime);
    if mime == "application/octet-stream" && prefix.starts_with(&[0x1f, 0x8b, 0x08]) {
        mime = "application/gzip".into();
    }
    let forced = php_like(path);
    let immutable = immutable_path(path);
    let last_modified = http_date(mtime);
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
            // max-age=0 already makes the browser revalidate before every reuse;
            // must-revalidate on top of it only forbade the one thing worth
            // having, serving the stale copy while the revalidation is in
            // flight. The shared TTL is added at serve time.
            "public, max-age=0".into()
        },
    );
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
        tier_class: if size < TIER_MIN_DEMOTE_BYTES {
            "small"
        } else {
            "eligible"
        },
        headers,
    }
}

/// The modification time a placed file carries, derived from its content hash.
///
/// It is not a clock reading. An accelerated response is served by nginx, which
/// stamps `ETag: "<hex mtime>-<hex size>"` from the file itself and never sees
/// our sha256 validator. A wall-clock mtime makes that ETag collide for two
/// same-size versions of one path published inside the same second — and HTML
/// revalidates on every navigation, so the visitor is handed the superseded
/// body with a 304. Deriving the stamp from the hash makes nginx's validator
/// content-addressed too — a republish no longer collides just by being fast —
/// and `Last-Modified` reads identically whether PHP or nginx answered.
///
/// The stamp is NOT as strong as the sha256 prefix it comes from. A `Last-
/// Modified` has to be a plausible past date, and seconds-since-epoch only
/// reaches ~1.78e9 today, so the modulus below caps the stamp at about 30.4
/// bits however many hash bits are fed in. Two chosen equal-size files can
/// therefore be ground into one ETag in ~2^15 tries. That is accepted, not
/// overlooked: the collision must land on the same path in the same space, so
/// the only actor who can aim it is the publisher, against their own visitors'
/// caches — an outcome they can already get by not publishing. What the stamp
/// has to prevent is the ACCIDENTAL same-second collision, and against that
/// 30 bits is decisive. Widening it would mean future-dating `Last-Modified`,
/// which buys bits nobody needs and hands every intermediary a date that has
/// not happened yet.
pub(crate) fn content_mtime(hash: &str) -> u64 {
    hash.get(..12)
        .and_then(|prefix| u64::from_str_radix(prefix, 16).ok())
        .unwrap_or(0)
        % 1_450_000_000
}

/// Applies [`content_mtime`] to a file already in place. Hardlinks share inode
/// metadata, so stamping the version-tree path stamps the CAS blob behind it;
/// the value is a pure function of the content, so every version referencing
/// that blob agrees and re-stamping is a no-op.
///
/// Times are set by path, not through an open handle. `File::set_times` would
/// need the file opened for writing, and a CAS blob is not necessarily
/// writable — a retained file is staged with `stat` and `link` alone precisely
/// so finalize never needs read or write access to its bytes, and taking that
/// access here would undo it. `utimensat` needs only ownership.
#[cfg(unix)]
pub(crate) fn stamp_content_mtime(path: &Path, hash: &str) -> Result<()> {
    use std::ffi::CString;
    use std::os::unix::ffi::OsStrExt;

    let seconds = content_mtime(hash) as libc::time_t;
    let target = CString::new(path.as_os_str().as_bytes()).map_err(|_| FinalizeError::Io {
        path: path.to_path_buf(),
        source: io::Error::new(io::ErrorKind::InvalidInput, "path contains an interior nul"),
    })?;
    let times = [
        libc::timespec {
            tv_sec: seconds,
            tv_nsec: 0,
        },
        libc::timespec {
            tv_sec: seconds,
            tv_nsec: 0,
        },
    ];
    // SAFETY: `target` is a valid nul-terminated path for the duration of the
    // call and `times` is a two-element array, exactly what utimensat reads.
    let result = unsafe { libc::utimensat(libc::AT_FDCWD, target.as_ptr(), times.as_ptr(), 0) };
    if result != 0 {
        return Err(FinalizeError::Io {
            path: path.to_path_buf(),
            source: io::Error::last_os_error(),
        });
    }
    Ok(())
}

#[cfg(not(unix))]
pub(crate) fn stamp_content_mtime(path: &Path, hash: &str) -> Result<()> {
    use std::fs::{File, FileTimes};
    use std::time::{Duration, SystemTime};

    let when = SystemTime::UNIX_EPOCH + Duration::from_secs(content_mtime(hash));
    let file = File::options()
        .write(true)
        .open(path)
        .map_err(|source| FinalizeError::Io {
            path: path.to_path_buf(),
            source,
        })?;
    file.set_times(FileTimes::new().set_accessed(when).set_modified(when))
        .map_err(|source| FinalizeError::Io {
            path: path.to_path_buf(),
            source,
        })
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
    let meta = file_meta(path, bytes, mime);
    stamp_content_mtime(&target, &meta.sha256)?;
    files.insert(path.into(), meta);
    Ok(())
}

pub(crate) fn write_bytes(path: &Path, bytes: &[u8]) -> Result<()> {
    if let Some(parent) = path.parent() {
        create_dir_all(parent)?;
    }
    write_bytes_in_place(path, bytes)
}

/// Writes atomically WITHOUT creating the directories leading to the file.
///
/// For a rewrite-in-place the missing parent is not a gap to fill: it means the
/// thing that owned the file was deleted while we held its contents in memory,
/// and creating the tree back would resurrect it.
pub(crate) fn write_bytes_in_place(path: &Path, bytes: &[u8]) -> Result<()> {
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

/// Rewrites an existing JSON document, compact and atomically, without creating
/// the directories leading to it.
///
/// **Compact** because the runtime is the only reader and a version may commit
/// 100,000 paths: indentation roughly doubles a worst-case document that the PHP
/// reader refuses outright above a byte ceiling
/// (`STATTIC_RUNTIME_VERSION_CATALOG_MAX_BYTES`), which would let a version
/// commit and then 500 on every read. Artifacts a human opens — `debug.json`,
/// the zero documents — keep their indentation.
///
/// **In place** because the caller reads the document before rewriting it: if
/// the parent has gone by the time we write, the version was deleted underneath
/// us and the rename must fail rather than resurrect the tree.
pub fn rewrite_json_compact(path: &Path, value: &Value) -> Result<()> {
    let mut bytes = serde_json::to_vec(value).map_err(|source| FinalizeError::Json {
        path: path.into(),
        source,
    })?;
    bytes.push(b'\n');
    write_bytes_in_place(path, &bytes)
}

/// Writes a `<?php return ...;` artifact the PHP serving layer can `include`.
pub fn write_php(path: &Path, value: &Value) -> Result<()> {
    write_bytes(
        path,
        format!("<?php\nreturn {};\n", php_export(value, 0)).as_bytes(),
    )
}

/// The PHP literal for one exported value, at the top level.
pub(crate) fn php_export_value(value: &Value) -> String {
    php_export(value, 0)
}

/// The PHP literal for one exported value nested at `indent` columns.
pub(crate) fn php_export_value_at(value: &Value, indent: usize) -> String {
    php_export(value, indent)
}

/// A PHP array key literal.
///
/// The compiled specials (`\0spa`, `\0404`, …) are keyed on NUL precisely
/// because a request path can never contain one — but PHP only interprets that
/// escape inside double quotes, and a single-quoted `'\0spa'` is the literal
/// four characters backslash-zero-s-p-a. Any key carrying a control byte is
/// therefore emitted double-quoted.
pub(crate) fn php_key_literal(key: &str) -> String {
    if !key.bytes().any(|byte| byte < 0x20 || byte == 0x7f) {
        return format!("'{}'", key.replace('\\', "\\\\").replace('\'', "\\'"));
    }
    let mut out = String::from("\"");
    for byte in key.bytes() {
        match byte {
            b'"' => out.push_str("\\\""),
            b'\\' => out.push_str("\\\\"),
            b'$' => out.push_str("\\$"),
            0x20..=0x7e => out.push(byte as char),
            other => out.push_str(&format!("\\x{other:02x}")),
        }
    }
    out.push('"');
    out
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
                s.push_str(&php_key_literal(k));
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
        (
            "runtime_schema".into(),
            serde_json::json!(crate::protocol::ARTIFACT_SCHEMA_NAME),
        ),
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

/// Build outputs whose URL already carries the version, so a year-long
/// `immutable` is honest and re-publishing never has to invalidate them.
///
/// The framework prefixes are conventions we recognise. `_spacefast/platform/`
/// is ours: the Zero compiler serves the platform's client bytes — the SDK, the
/// kit, the chart layer, preact — from a content-addressed directory under it,
/// and the shell's import map names that exact directory. The digest changes
/// when the bytes do, which is the whole invalidation story; without immutable
/// caching here the browser re-validates platform code on every navigation and
/// the point of hosting it separately is lost. Kept in step with
/// `ZERO_PLATFORM_ASSET_ROOT` in `packages/common/src/contracts/zero.ts`.
pub(crate) fn immutable_path(path: &str) -> bool {
    let normalized = path.trim_start_matches('/');
    !matches!(
        path.rsplit('.')
            .next()
            .unwrap_or("")
            .to_ascii_lowercase()
            .as_str(),
        "html" | "htm" | "php" | "txt" | "xml"
    ) && [
        "_next/static/",
        "_app/immutable/",
        "_nuxt/",
        "_astro/",
        "_spacefast/platform/",
    ]
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
    use super::{file_meta_from_parts, rewrite_json_compact};

    /// The document a version's `metadata.json` carries can reach six figures of
    /// paths, and the runtime is its only reader: it goes out compact, and it
    /// never rebuilds the directory it lives in — a missing parent means the
    /// version was deleted while we held its contents.
    #[test]
    fn the_in_place_json_writer_stays_compact_and_never_creates_its_directory() {
        let temp = tempfile::tempdir().unwrap();
        let document = serde_json::json!({"catalog": {"paths": ["a", "b"]}});

        let path = temp.path().join("metadata.json");
        rewrite_json_compact(&path, &document).unwrap();
        assert_eq!(
            std::fs::read_to_string(&path).unwrap(),
            "{\"catalog\":{\"paths\":[\"a\",\"b\"]}}\n"
        );

        let deleted = temp.path().join("gone/metadata.json");
        rewrite_json_compact(&deleted, &document).unwrap_err();
        assert!(!deleted.parent().unwrap().exists());
    }

    #[test]
    fn malformed_hashes_fall_back_without_panicking() {
        for hash in ["", "abc", "éééééé", "not-hex-data"] {
            let meta = file_meta_from_parts("index.html", 0, hash.into(), b"", None);
            assert_eq!(meta.mtime, 0);
        }
    }

    // The platform build is content-addressed, so the browser may keep it
    // forever — that cache is the entire reason capsules stopped shipping their
    // own copy of preact. The digest directory earns the immutable policy; a
    // path outside it, and markup anywhere, still revalidates.
    #[test]
    fn platform_assets_are_immutable_and_nothing_else_becomes_so() {
        const IMMUTABLE: &str = "public, max-age=31536000, immutable";
        let cache_control = |path: &str| {
            file_meta_from_parts(path, 0, "0".repeat(64), b"", None)
                .headers
                .get("Cache-Control")
                .cloned()
                .unwrap_or_default()
        };

        assert_eq!(
            cache_control("_spacefast/platform/abc123/preact.js"),
            IMMUTABLE
        );
        // The private `__spacefast` namespace is a different thing entirely:
        // it is never served, and must not be mistaken for the public one.
        assert_eq!(
            cache_control("__spacefast/zero/config.json"),
            "public, max-age=0"
        );
        assert_eq!(cache_control("client.js"), "public, max-age=0");
        assert_eq!(
            cache_control("_spacefast/platform/abc123/index.html"),
            "public, max-age=0"
        );
    }
}
