//! Non-zero serving artifacts derived from the committed file set: generated
//! directory listings and single-file viewers, the resolved serving config,
//! the exact-path lookup map, and the PHP route manifest. Zero artifact
//! emission is excluded: trunk `zero.rs` is authoritative for that output.

use regex::Regex;
use serde_json::{json, Map, Value};
#[cfg(not(target_family = "wasm"))]
use std::cmp::Ordering;
use std::collections::{BTreeMap, BTreeSet};
#[cfg(not(target_family = "wasm"))]
use std::path::Path;

#[cfg(not(target_family = "wasm"))]
use crate::content::{escape_attr, escape_html};
#[cfg(not(target_family = "wasm"))]
use crate::finalize::read_bounded;
use crate::finalize::{invalid, php_like, sha256, FileMeta, Result};
#[cfg(not(target_family = "wasm"))]
use crate::protocol::{LISTING_ROWS_MARKER, PAGE_MAX_BYTES};
use crate::serving_paths::{is_private_serving_path as is_private, is_public_serving_path};
#[cfg(not(target_family = "wasm"))]
use crate::storage::put_blob;
use crate::transforms::{resolve_effective_config, ResolveEffectiveInput};

pub(crate) const DEFAULT_EDGE_CACHE_CONTROL: &str =
    "public, max-age=0, s-maxage=600, stale-while-revalidate=60";

/// The committed paths that are publicly served: not convention/config files,
/// not dotfiles, and not precompressed sidecars of another committed file.
pub fn public_files(files: &BTreeMap<String, FileMeta>, private: &BTreeSet<String>) -> Vec<String> {
    files
        .keys()
        .filter(|p| {
            !private.contains(*p) && is_public_serving_path(p, |source| files.contains_key(source))
        })
        .cloned()
        .collect()
}

/// One compiled directory listing.
///
/// A listing is a page shell plus one row fragment per entry. The public case
/// is joined here and stored as a single blob, so it costs the same as any
/// static file. A protected case is joined per request from the rows the
/// visitor is admitted to — which needs no compile, no variant store, and no
/// audience class in a cache key.
#[cfg(not(target_family = "wasm"))]
#[derive(Debug, Clone)]
pub struct CompiledListing {
    /// `""` for the root listing.
    pub directory: String,
    pub shell_sha: String,
    pub rows: Vec<ListingRow>,
    pub page_sha: String,
    pub page_length: u64,
}

/// One row of a listing. `path` is the committed path (or the subdirectory with
/// a trailing slash) — the key the access check needs, not the display name,
/// which is already inside the fragment.
#[cfg(not(target_family = "wasm"))]
#[derive(Debug, Clone)]
pub struct ListingRow {
    pub path: String,
    pub sha: String,
}

#[cfg(not(target_family = "wasm"))]
#[allow(clippy::too_many_arguments)] // Compilation needs each immutable input explicitly.
pub fn compile_listings(
    blob_root: &Path,
    page_artifacts_root: &Path,
    files: &BTreeMap<String, FileMeta>,
    config: &Map<String, Value>,
    metadata: &Map<String, Value>,
    viewer: &Map<String, Value>,
    serving: &Map<String, Value>,
    private: &BTreeSet<String>,
) -> Result<Vec<CompiledListing>> {
    if !config
        .get("listing")
        .and_then(Value::as_bool)
        .unwrap_or(false)
    {
        return Ok(Vec::new());
    }
    let public = public_files(files, private);
    if public.is_empty() {
        return Ok(Vec::new());
    }
    let mut directories = BTreeMap::<String, (BTreeSet<String>, BTreeSet<String>)>::new();
    directories.entry(String::new()).or_default();
    for path in &public {
        let parts: Vec<&str> = path.split('/').collect();
        let mut current = String::new();
        for part in &parts[..parts.len().saturating_sub(1)] {
            let parent = current.clone();
            current = if current.is_empty() {
                (*part).into()
            } else {
                format!("{current}/{part}")
            };
            directories
                .entry(parent)
                .or_default()
                .0
                .insert((*part).into());
            directories.entry(current.clone()).or_default();
        }
        if let Some(name) = parts.last() {
            directories
                .entry(current)
                .or_default()
                .1
                .insert((*name).into());
        }
    }
    let mut compiled = Vec::new();
    let single_viewer_path = (public.len() == 1
        && config
            .get("viewer")
            .and_then(Value::as_bool)
            .unwrap_or(false))
    .then(|| public[0].clone());
    for (directory, (dirs, names)) in directories {
        let index = if directory.is_empty() {
            "index.html".into()
        } else {
            format!("{directory}/index.html")
        };
        let route_path = if directory.is_empty() {
            "/".to_string()
        } else {
            format!("/{directory}/")
        };
        let compiled_listing = config
            .get("pages")
            .and_then(Value::as_object)
            .and_then(|pages| pages.get("routes"))
            .and_then(Value::as_object)
            .and_then(|routes| routes.get(&route_path))
            .and_then(Value::as_object)
            .and_then(|route| route.get("index"))
            .and_then(Value::as_str);
        if files.contains_key(&index) {
            continue;
        }
        if let Some(artifact) = compiled_listing {
            let page = read_bounded(
                &page_artifacts_root.join(format!("{artifact}.html")),
                PAGE_MAX_BYTES,
            )?;
            let sha = put_blob(blob_root, &page)?;
            compiled.push(CompiledListing {
                directory,
                shell_sha: sha.clone(),
                rows: Vec::new(),
                page_sha: sha,
                page_length: page.len() as u64,
            });
            continue;
        }
        let heading = if directory.is_empty() {
            viewer
                .get("title")
                .or_else(|| metadata.get("title"))
                .and_then(Value::as_str)
                .unwrap_or("Files")
        } else {
            &directory
        };
        let prefix = if directory.is_empty() {
            String::new()
        } else {
            format!("{directory}/")
        };
        let theme = validated_theme_css(serving.get("theme_css"))
            .and_then(|v| v.as_str().map(str::to_string))
            .unwrap_or_default();
        if directory.is_empty() {
            if let Some(path) = single_viewer_path.as_deref() {
                let html = render_file_viewer(
                    path,
                    files.get(path).expect("single viewer metadata"),
                    heading,
                    viewer,
                    &theme,
                );
                let sha = put_blob(blob_root, html.as_bytes())?;
                compiled.push(CompiledListing {
                    directory,
                    shell_sha: sha.clone(),
                    rows: Vec::new(),
                    page_sha: sha,
                    page_length: html.len() as u64,
                });
                continue;
            }
        }
        let mut rows = Vec::new();
        let mut dirs: Vec<_> = dirs.into_iter().collect();
        dirs.sort_by(|left, right| natural_case_cmp(left, right));
        for name in dirs {
            let fragment = format!(
                "<li><a href=\"/{}{}/\">{}/</a></li>",
                escape_attr(&prefix),
                escape_attr(&name),
                escape_html(&name)
            );
            rows.push(ListingRow {
                path: format!("{prefix}{name}/"),
                sha: put_blob(blob_root, fragment.as_bytes())?,
            });
        }
        let mut names: Vec<_> = names.into_iter().collect();
        names.sort_by(|left, right| natural_case_cmp(left, right));
        for name in names {
            if name == "index.html" {
                continue;
            }
            let fragment = format!(
                "<li><a href=\"/{}{}\">{}</a></li>",
                escape_attr(&prefix),
                escape_attr(&name),
                escape_html(&name)
            );
            rows.push(ListingRow {
                path: format!("{prefix}{name}"),
                sha: put_blob(blob_root, fragment.as_bytes())?,
            });
        }
        let shell = format!("<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{}</title><style>:root{{{}}}body{{font-family:system-ui;margin:2rem;line-height:1.5}}</style></head><body><main><h1>{}</h1><ul>{LISTING_ROWS_MARKER}</ul></main></body></html>", escape_html(heading), theme, escape_html(heading));
        let joined = rows
            .iter()
            .map(|row| fragment_source(blob_root, &row.sha))
            .collect::<Result<Vec<_>>>()?
            .join("");
        let page = shell.replace(LISTING_ROWS_MARKER, &joined);
        compiled.push(CompiledListing {
            directory,
            shell_sha: put_blob(blob_root, shell.as_bytes())?,
            rows,
            page_length: page.len() as u64,
            page_sha: put_blob(blob_root, page.as_bytes())?,
        });
    }
    Ok(compiled)
}

/// A row fragment read back from the CAS it was just written to. Cheap: the
/// blob is a few dozen bytes and was written moments ago.
#[cfg(not(target_family = "wasm"))]
fn fragment_source(blob_root: &Path, sha: &str) -> Result<String> {
    let path = crate::storage::blob_path(blob_root, sha);
    std::fs::read_to_string(&path)
        .map_err(|source| crate::finalize::FinalizeError::Io { path, source })
}

#[cfg(not(target_family = "wasm"))]
fn natural_case_cmp(left: &str, right: &str) -> Ordering {
    let left = left.as_bytes();
    let right = right.as_bytes();
    let (mut left_index, mut right_index) = (0, 0);
    while left_index < left.len() && right_index < right.len() {
        if left[left_index].is_ascii_digit() && right[right_index].is_ascii_digit() {
            let left_start = left_index;
            let right_start = right_index;
            while left_index < left.len() && left[left_index].is_ascii_digit() {
                left_index += 1;
            }
            while right_index < right.len() && right[right_index].is_ascii_digit() {
                right_index += 1;
            }
            let left_digits = &left[left_start..left_index];
            let right_digits = &right[right_start..right_index];
            let left_significant = trim_zeroes(left_digits);
            let right_significant = trim_zeroes(right_digits);
            let order = left_significant
                .len()
                .cmp(&right_significant.len())
                .then_with(|| left_significant.cmp(right_significant))
                .then_with(|| left_digits.len().cmp(&right_digits.len()));
            if order != Ordering::Equal {
                return order;
            }
            continue;
        }
        let order = left[left_index]
            .to_ascii_lowercase()
            .cmp(&right[right_index].to_ascii_lowercase());
        if order != Ordering::Equal {
            return order;
        }
        left_index += 1;
        right_index += 1;
    }
    left.len().cmp(&right.len()).then_with(|| left.cmp(right))
}

#[cfg(not(target_family = "wasm"))]
fn trim_zeroes(digits: &[u8]) -> &[u8] {
    let first_nonzero = digits
        .iter()
        .position(|digit| *digit != b'0')
        .unwrap_or(digits.len().saturating_sub(1));
    &digits[first_nonzero..]
}

#[cfg(not(target_family = "wasm"))]
fn render_file_viewer(
    path: &str,
    meta: &FileMeta,
    heading: &str,
    viewer: &Map<String, Value>,
    theme: &str,
) -> String {
    let href = format!("/{}", path.trim_start_matches('/'));
    let escaped_href = escape_attr(&href);
    let name = escape_html(path.rsplit('/').next().unwrap_or(path));
    let body = if meta.mime.starts_with("image/") {
        format!("<figure><img src=\"{escaped_href}\" alt=\"{name}\"></figure>")
    } else if meta.mime == "application/pdf" {
        format!("<iframe src=\"{escaped_href}\" title=\"{name}\"></iframe>")
    } else if meta.mime.starts_with("video/") {
        format!("<video controls src=\"{escaped_href}\"></video>")
    } else {
        format!("<p><a href=\"{escaped_href}\">Open {name}</a></p>")
    };
    let description = viewer
        .get("description")
        .and_then(Value::as_str)
        .map(|description| format!("<p>{}</p>", escape_html(description)))
        .unwrap_or_default();
    format!(
        "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{}</title><style>body{{font-family:system-ui,sans-serif;margin:2rem;line-height:1.5}}main{{max-width:880px}}img,video,iframe{{max-width:100%;width:100%;border:0}}iframe{{min-height:70vh}}ul{{padding-left:1.25rem}}</style><style>:root{{{theme}}}</style></head><body><main><h1>{}</h1>{description}{body}</main></body></html>",
        escape_html(heading),
        escape_html(heading)
    )
}

/// `has_worker` is the version's Functions signal: a worker owns every
/// unresolved request, `/` included, so a Functions version with no inferable
/// root HTML serves neither an index nor a directory listing.
pub fn resolve_serving_config(
    config: &Map<String, Value>,
    files: &BTreeMap<String, FileMeta>,
    private: &BTreeSet<String>,
    metadata: &Map<String, Value>,
    has_worker: bool,
) -> Result<Map<String, Value>> {
    if config.get("index").is_some_and(|index| match index {
        Value::String(path) => path.trim().trim_start_matches('/').is_empty(),
        Value::Bool(false) => false,
        _ => true,
    }) {
        return invalid(
            "invalid_serving_config",
            "index must be a non-empty string or false.",
        );
    }
    if let Some(fallback) = config.get("fallback") {
        let valid = fallback.is_null()
            || fallback.as_object().is_some_and(|fallback| {
                fallback.len() == 2
                    && fallback
                        .get("path")
                        .and_then(Value::as_str)
                        .is_some_and(|path| !path.trim().trim_start_matches('/').is_empty())
                    && matches!(
                        fallback.get("status").and_then(Value::as_u64),
                        Some(200 | 404)
                    )
            });
        if !valid {
            return invalid(
                "invalid_serving_config",
                "fallback must be null or { path: non-empty string, status: 200 | 404 }.",
            );
        }
    }
    for field in ["clean_urls", "listing", "viewer"] {
        if config.get(field).is_some_and(|value| !value.is_boolean()) {
            return invalid(
                "invalid_serving_config",
                format!("{field} must be a boolean."),
            );
        }
    }
    let mut file_config = config.clone();
    if !file_config.contains_key("fallback")
        && metadata.get("spa").and_then(Value::as_bool) == Some(true)
        && files.contains_key("index.html")
    {
        file_config.insert(
            "fallback".into(),
            json!({"path": "index.html", "status": 200}),
        );
    }
    if let Some(clean_urls) = file_config.remove("clean_urls") {
        file_config.insert("cleanUrls".into(), clean_urls);
    }
    file_config.remove("viewer");
    file_config.remove("spacefast_sdk");
    let effective = resolve_effective_config(ResolveEffectiveInput {
        manifest_paths: files
            .keys()
            .filter(|path| !private.contains(*path) && !is_private(path))
            .cloned()
            .collect(),
        file_config: Some(file_config),
        overlay: None,
        template_entries: Vec::new(),
        has_worker,
    });
    if effective
        .issues
        .iter()
        .any(|issue| issue.severity == "error")
    {
        return invalid(
            "invalid_serving_config",
            effective
                .issues
                .into_iter()
                .find(|issue| issue.severity == "error")
                .map(|issue| issue.message)
                .unwrap_or_else(|| "Serving config is invalid.".into()),
        );
    }
    let mut result = effective
        .runtime_payload_base
        .as_object()
        .cloned()
        .unwrap_or_default();
    result.retain(|key, _| {
        matches!(
            key.as_str(),
            "index" | "fallback" | "clean_urls" | "listing" | "viewer"
        )
    });
    if let Some(viewer) = config.get("viewer") {
        result.insert("viewer".into(), viewer.clone());
    }
    for key in ["spacefast_sdk", "pages"] {
        if let Some(value) = config.get(key) {
            result.insert(key.into(), value.clone());
        }
    }
    Ok(result)
}

pub fn build_lookup_map<'a>(
    paths: impl Iterator<Item = &'a String>,
    exact_redirects: &Map<String, Value>,
    pattern_redirects: &[Value],
    private: &BTreeSet<String>,
    index: &Value,
) -> Map<String, Value> {
    let mut lookup = Map::new();
    let index_name = index
        .as_str()
        .or_else(|| (index.as_bool() == Some(false)).then_some("index.html"));
    for path in paths {
        if private.contains(path) || is_private(path) {
            lookup.insert(path.clone(), not_found_action());
            continue;
        }
        let action = static_lookup_action("file", path, 200);
        lookup.insert(path.clone(), action.clone());
        if path == "index.html" {
            lookup.insert(String::new(), action.clone());
        }
        if let Some(name) = index_name {
            if let Some(dir) = path.strip_suffix(&format!("/{name}")) {
                lookup.insert(dir.into(), action.clone());
                lookup.insert(format!("{dir}/"), action.clone());
            }
        }
    }
    // Compiling a regex costs orders of magnitude more than matching one, and
    // every exact key would otherwise recompile the whole pattern list: 100
    // dynamic rules against 2,000 static ones is 200,000 compilations for one
    // version. Compile the pattern lane once and match it 2,000 times.
    let compiled_patterns = compile_pattern_matchers(pattern_redirects);
    for (request_path, rules) in exact_redirects {
        let Some((action, order)) = rules
            .as_array()
            .and_then(|rules| first_exact_redirect_action(rules))
        else {
            continue;
        };
        // Redirects are first-match-wins over ONE ordered list. This map answers
        // before that list is ever walked, so it may only carry a rule nothing
        // earlier could have claimed: an earlier pattern rule that matches this
        // path keeps its turn, and the request falls through to the ordered walk
        // that resolves it correctly. Without this, `/docs/:slug` in `_redirects`
        // would lose to a later exact `/docs/intro` — from the file or, since
        // sf.jsonc routes too, from the config that is supposed to be additive.
        if pattern_shadows_path(&compiled_patterns, request_path, order) {
            continue;
        }
        lookup.insert(request_path.trim_start_matches('/').into(), action);
    }
    for path in private {
        lookup.insert(path.clone(), not_found_action());
    }
    lookup
}

/// The pattern lane's matchers, compiled once, each paired with the rule's
/// position in the ordered redirect list. Rules without a usable regex drop out
/// here: they can never claim a request path, so they can never shadow one.
fn compile_pattern_matchers(pattern_redirects: &[Value]) -> Vec<(i64, Regex)> {
    pattern_redirects
        .iter()
        .filter_map(|rule| {
            let regex = rule
                .get("regex")
                .and_then(Value::as_str)
                .filter(|regex| !regex.is_empty())
                .and_then(|regex| Regex::new(regex).ok())?;
            let order = rule
                .get("order")
                .and_then(Value::as_i64)
                .unwrap_or(i64::MAX);
            Some((order, regex))
        })
        .collect()
}

/// Whether an earlier pattern rule can claim this request path. Host-qualified
/// and conditional rules count as "can": whether they actually fire depends on
/// the request, which is exactly why that decision belongs to the ordered walk
/// and not to a precomputed answer.
fn pattern_shadows_path(patterns: &[(i64, Regex)], request_path: &str, order: i64) -> bool {
    patterns
        .iter()
        .any(|(pattern_order, regex)| *pattern_order < order && regex.is_match(request_path))
}

fn first_exact_redirect_action(rules: &[Value]) -> Option<(Value, i64)> {
    let rule = rules.iter().min_by_key(|rule| {
        rule.get("order")
            .and_then(Value::as_i64)
            .unwrap_or(i64::MAX)
    })?;
    if rule
        .get("action")
        .and_then(Value::as_str)
        .unwrap_or("redirect")
        != "redirect"
    {
        return None;
    }
    let destination = rule.get("destination").and_then(Value::as_str)?;
    if destination.is_empty()
        || rule
            .get("hostRegex")
            .and_then(Value::as_str)
            .is_some_and(|value| !value.is_empty())
        || rule.get("query").is_some_and(value_is_nonempty)
        || rule.get("conditions").is_some_and(value_is_nonempty)
    {
        return None;
    }
    let status = rule.get("status").and_then(Value::as_u64).unwrap_or(302);
    Some((
        json!({
            "action": "redirect",
            "destination": destination,
            "status": status,
            "cache_control": redirect_cache_control(status),
            "methods": ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
        }),
        rule.get("order")
            .and_then(Value::as_i64)
            .unwrap_or(i64::MAX),
    ))
}

fn value_is_nonempty(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(value) => *value,
        Value::Number(value) => value.as_i64() != Some(0),
        Value::String(value) => !value.is_empty(),
        Value::Array(value) => !value.is_empty(),
        Value::Object(value) => !value.is_empty(),
    }
}

fn redirect_cache_control(_status: u64) -> &'static str {
    DEFAULT_EDGE_CACHE_CONTROL
}

pub(crate) fn static_lookup_action(action: &str, path: &str, status: u64) -> Value {
    json!({
        "action": action,
        "path": path,
        "file_shard": &sha256(path.as_bytes())[..2],
        "status": status,
        "methods": ["GET", "HEAD"],
        "forced_download_or_text": php_like(path),
    })
}

pub(crate) fn not_found_action() -> Value {
    json!({
        "action": "not_found",
        "status": 404,
        "cache_control": DEFAULT_EDGE_CACHE_CONTROL,
        "methods": ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    })
}

#[cfg(not(target_family = "wasm"))]
pub(crate) fn validated_theme_css(value: Option<&Value>) -> Option<Value> {
    value
        .and_then(Value::as_str)
        .filter(|s| {
            s.len() <= 16384
                && !s.contains('<')
                && !s.contains('>')
                && ![
                    "url(",
                    "url (",
                    "@import",
                    "expression(",
                    "expression (",
                    "data:",
                ]
                .iter()
                .any(|token| s.to_ascii_lowercase().contains(token))
        })
        .map(|s| json!(s.trim()))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::{file_meta, FinalizeError};
    use tempfile::tempdir;

    /// Compiles listings into a throwaway CAS and returns the joined public
    /// body of the root listing — the bytes a visitor of an open space gets.
    fn listing_fixture(
        entries: &[(&str, &[u8])],
        config: Value,
        viewer: Value,
    ) -> (tempfile::TempDir, String) {
        let temp = tempdir().unwrap();
        let files: BTreeMap<String, FileMeta> = entries
            .iter()
            .map(|(path, bytes)| ((*path).to_string(), file_meta(path, bytes, None)))
            .collect();
        let listings = compile_listings(
            temp.path(),
            temp.path(),
            &files,
            config.as_object().unwrap(),
            &Map::new(),
            viewer.as_object().unwrap(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();
        let root = listings
            .iter()
            .find(|listing| listing.directory.is_empty())
            .expect("root listing");
        let html = fragment_source(temp.path(), &root.page_sha).unwrap();
        (temp, html)
    }

    #[test]
    fn empty_file_set_does_not_generate_a_listing() {
        let temp = tempdir().unwrap();
        let config = json!({"listing":true,"viewer":true});

        let listings = compile_listings(
            temp.path(),
            temp.path(),
            &BTreeMap::new(),
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();

        assert!(listings.is_empty());
    }

    /// A listing publishes both halves: the joined public page AND the shell
    /// plus per-row fragments a protected space joins at request time. The rows
    /// carry the committed path, because that is what the access check needs.
    #[test]
    fn a_listing_publishes_a_shell_and_one_fragment_for_each_row() {
        let temp = tempdir().unwrap();
        let files: BTreeMap<String, FileMeta> = [
            ("notes.txt", &b"notes"[..]),
            ("docs/guide.txt", &b"guide"[..]),
        ]
        .into_iter()
        .map(|(path, bytes)| (path.to_string(), file_meta(path, bytes, None)))
        .collect();
        let config = json!({"listing":true,"viewer":false});

        let listings = compile_listings(
            temp.path(),
            temp.path(),
            &files,
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();

        let root = listings
            .iter()
            .find(|listing| listing.directory.is_empty())
            .expect("root listing");
        assert_eq!(
            root.rows
                .iter()
                .map(|row| row.path.as_str())
                .collect::<Vec<_>>(),
            vec!["docs/", "notes.txt"]
        );
        let shell = fragment_source(temp.path(), &root.shell_sha).unwrap();
        assert!(shell.contains(LISTING_ROWS_MARKER));
        let joined: String = root
            .rows
            .iter()
            .map(|row| fragment_source(temp.path(), &row.sha).unwrap())
            .collect();
        assert_eq!(
            shell.replace(LISTING_ROWS_MARKER, &joined),
            fragment_source(temp.path(), &root.page_sha).unwrap()
        );
        assert!(listings.iter().any(|listing| listing.directory == "docs"));
    }

    // Adapted from the donor's finalize-level
    // `listings_use_natural_case_insensitive_order`: the listing generator is
    // exercised directly; the ordering assertions are donor-verbatim.
    #[test]
    fn listings_use_natural_case_insensitive_order() {
        let (_temp, html) = listing_fixture(
            &[
                ("file10.txt", b"ten"),
                ("File2.txt", b"two"),
                ("file1.txt", b"one"),
                ("file02.txt", b"zero two"),
            ],
            json!({"listing":true,"viewer":false}),
            json!({}),
        );
        let positions: Vec<_> = ["file1.txt", "File2.txt", "file02.txt", "file10.txt"]
            .into_iter()
            .map(|name| html.find(&format!(">{name}</a>")).unwrap())
            .collect();
        assert!(positions.windows(2).all(|pair| pair[0] < pair[1]));
    }

    // Adapted from the donor's finalize-level
    // `files_mode_single_asset_uses_the_mime_aware_viewer`.
    #[test]
    fn files_mode_single_asset_uses_the_mime_aware_viewer() {
        let (_temp, html) = listing_fixture(
            &[("photo.png", b"not-a-real-image")],
            json!({"listing":true,"viewer":true}),
            json!({"title":"Photos"}),
        );
        assert!(html.contains("<img src=\"/photo.png\""));
        assert!(html.contains("<h1>Photos</h1>"));
    }

    #[test]
    fn compiled_pages_listing_takes_precedence_over_a_generated_index() {
        let temp = tempdir().unwrap();
        let files = BTreeMap::from([(
            "report.txt".to_string(),
            file_meta("report.txt", b"report", None),
        )]);
        let config = json!({
            "listing": true,
            "viewer": true,
            "pages": {
                "routes": {
                    "/": {"index": "page-index"}
                },
                "previews": {}
            }
        });
        std::fs::write(
            temp.path().join("page-index.html"),
            "<h1>Compiled listing</h1>",
        )
        .unwrap();
        let listings = compile_listings(
            temp.path(),
            temp.path(),
            &files,
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();

        let root = listings
            .iter()
            .find(|listing| listing.directory.is_empty())
            .expect("compiled root listing");
        assert_eq!(
            fragment_source(temp.path(), &root.page_sha).unwrap(),
            "<h1>Compiled listing</h1>"
        );
    }

    // Adapted from the donor's finalize-level
    // `private_path_grammar_is_enforced_across_publication_and_routing`: the
    // listing generator, `public_files`, and the serving-config resolver are
    // exercised directly. The donor's rewrite-destination rejection is covered
    // by the Stage-1 `policy.rs` tests.
    #[test]
    fn private_path_grammar_is_enforced_across_publication_and_listing() {
        let entries: &[(&str, &[u8])] = &[
            (".env", b"secret"),
            ("assets/.secret.txt", b"secret"),
            ("assets/.secret.txt.GZ.BR", b"compressed secret"),
            (".well-known/spacefast-runtime", b"private pointer"),
            (".well-known/stattic-runtime", b"private pointer"),
            (".well-known/.internal/token", b"secret"),
            ("zero/endpoint.wasm", b"private endpoint"),
            ("_headers.GZ", b"compressed convention"),
            (".well-known/acme-challenge/token", b"public token"),
            ("public.txt", b"public"),
        ];
        let temp = tempdir().unwrap();
        let files: BTreeMap<String, FileMeta> = entries
            .iter()
            .map(|(path, bytes)| ((*path).to_string(), file_meta(path, bytes, None)))
            .collect();
        let private = BTreeSet::new();
        let config = json!({"listing":true,"viewer":false});
        let listing = compile_listings(
            temp.path(),
            temp.path(),
            &files,
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &private,
        )
        .unwrap()
        .iter()
        .map(|compiled| fragment_source(temp.path(), &compiled.page_sha).unwrap())
        .collect::<String>();
        let public = public_files(&files, &private);
        for private_path in [
            ".env",
            "assets/.secret.txt",
            "assets/.secret.txt.GZ.BR",
            ".well-known/spacefast-runtime",
            ".well-known/stattic-runtime",
            ".well-known/.internal/token",
            "zero/endpoint.wasm",
            "_headers.GZ",
        ] {
            assert!(!listing.contains(private_path));
            assert!(!public.contains(&private_path.to_string()));
        }
        for private_name in [
            ".env",
            ".secret.txt",
            "spacefast-runtime",
            "stattic-runtime",
            ".internal",
            "endpoint.wasm",
            "_headers.GZ",
        ] {
            assert!(!listing.contains(private_name));
        }
        for public_path in [".well-known/acme-challenge/token", "public.txt"] {
            assert!(public.contains(&public_path.to_string()));
        }
        assert!(listing.contains("acme-challenge/"));
        assert!(listing.contains("token"));
        assert!(listing.contains("public.txt"));

        let fallback_config = json!({"fallback":{"path":"assets/.secret.txt.GZ.BR","status":200}});
        assert!(matches!(
            resolve_serving_config(
                fallback_config.as_object().unwrap(),
                &files,
                &private,
                &Map::new(),
                false,
            ),
            Err(FinalizeError::Invalid {
                code: "invalid_serving_config",
                ..
            })
        ));
    }

    #[test]
    fn native_serving_defaults_use_the_shared_effective_resolver() {
        for (paths, private, has_worker, expected_index, expected_listing) in [
            (
                vec!["docs/page.html"],
                vec![],
                false,
                json!("docs/page.html"),
                false,
            ),
            (vec!["asset.txt"], vec![], false, json!(false), true),
            (
                vec!["index.html"],
                vec![],
                false,
                json!("index.html"),
                false,
            ),
            (
                vec!["_layout.html", "docs/page/index.html"],
                vec!["_layout.html"],
                false,
                json!("docs/page/index.html"),
                false,
            ),
            // The worker signal has to survive the hop into the resolver: the
            // same tree that lists as files for a static version answers
            // nothing but the worker for a Functions one.
            (vec!["asset.txt"], vec![], true, json!(false), false),
        ] {
            let files = paths
                .iter()
                .map(|path| ((*path).to_string(), file_meta(path, b"content", None)))
                .collect::<BTreeMap<_, _>>();
            let private = private.into_iter().map(str::to_string).collect();
            let resolved =
                resolve_serving_config(&Map::new(), &files, &private, &Map::new(), has_worker)
                    .unwrap();
            assert_eq!(resolved.get("index"), Some(&expected_index));
            assert_eq!(resolved.get("listing"), Some(&json!(expected_listing)));
            assert_eq!(resolved.get("fallback"), Some(&Value::Null));
        }

        let files = ["index.html", "page.html"]
            .into_iter()
            .map(|path| (path.to_string(), file_meta(path, b"content", None)))
            .collect::<BTreeMap<_, _>>();
        let resolved = resolve_serving_config(
            &Map::new(),
            &files,
            &BTreeSet::new(),
            &Map::from_iter([("spa".into(), Value::Bool(true))]),
            false,
        )
        .unwrap();
        assert_eq!(
            resolved.get("fallback"),
            Some(&json!({"path": "index.html", "status": 200}))
        );
        assert_eq!(resolved.get("clean_urls"), Some(&Value::Bool(false)));
    }

    #[test]
    fn lookup_conceals_absent_private_paths_and_maps_directory_indexes() {
        let paths = ["index.html".to_string(), "docs/index.html".to_string()];
        let private = BTreeSet::from(["_headers".to_string()]);
        let lookup = build_lookup_map(paths.iter(), &Map::new(), &[], &private, &json!(false));

        assert_eq!(
            lookup
                .get("_headers")
                .and_then(|action| action.get("action")),
            Some(&json!("not_found"))
        );
        assert_eq!(
            lookup
                .get("_headers")
                .and_then(|action| action.get("cache_control")),
            Some(&json!(DEFAULT_EDGE_CACHE_CONTROL))
        );
        assert_eq!(
            lookup.get("docs").and_then(|action| action.get("path")),
            Some(&json!("docs/index.html"))
        );
    }

    #[test]
    fn exact_redirects_override_files_in_the_lookup_map() {
        let paths = ["index.html".to_string(), "old.html".to_string()];
        let redirects = Map::from_iter([(
            "/old.html".into(),
            json!([{
                "action": "redirect",
                "destination": "/new.html",
                "status": 302,
                "order": 0,
            }]),
        )]);
        let lookup = build_lookup_map(
            paths.iter(),
            &redirects,
            &[],
            &BTreeSet::new(),
            &json!("index.html"),
        );
        assert_eq!(lookup["old.html"]["action"], json!("redirect"));
        assert_eq!(
            lookup["old.html"]["cache_control"],
            json!(DEFAULT_EDGE_CACHE_CONTROL)
        );
        assert_eq!(
            lookup["old.html"]["methods"],
            json!(["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"])
        );
    }

    #[test]
    fn an_earlier_pattern_rule_keeps_an_exact_redirect_out_of_the_lookup_map() {
        // The lookup answers before the ordered rule walk runs, so it may only
        // carry a rule nothing earlier could claim. `/docs/:slug` at order 0
        // owns `/docs/intro`; folding the order-1 exact rule in would let it
        // answer first and quietly invert first-match-wins — including for a
        // sf.jsonc rule that is supposed to be purely additive.
        let redirects = Map::from_iter([
            (
                "/docs/intro".into(),
                json!([{"action":"redirect","destination":"/start","status":301,"order":1}]),
            ),
            (
                "/other".into(),
                json!([{"action":"redirect","destination":"/elsewhere","status":301,"order":2}]),
            ),
        ]);
        let patterns = [json!({
            "action": "redirect",
            "destination": "/guides/:slug",
            "regex": "^/docs/(?P<slug>[^/]+)/?$",
            "status": 301,
            "order": 0,
        })];
        let lookup = build_lookup_map(
            [].iter(),
            &redirects,
            &patterns,
            &BTreeSet::new(),
            &json!("index.html"),
        );

        assert!(!lookup.contains_key("docs/intro"));
        // A path no earlier pattern claims still gets the fast path.
        assert_eq!(lookup["other"]["destination"], json!("/elsewhere"));
    }
}
