//! Non-zero serving artifacts derived from the committed file set: generated
//! directory listings and single-file viewers, the resolved serving config,
//! the exact-path lookup map, and the PHP route manifest. Zero artifact
//! emission is excluded: trunk `zero.rs` is authoritative for that output.

use serde_json::{json, Map, Value};
use std::cmp::Ordering;
use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use crate::content::{escape_attr, escape_html};
use crate::finalize::{invalid, php_like, sha256, write_generated, FileMeta, Result};
use crate::serving_paths::{is_private_serving_path as is_private, is_public_serving_path};
use crate::transforms::{resolve_effective_config, ResolveEffectiveInput};

pub(crate) const DEFAULT_EDGE_CACHE_CONTROL: &str =
    "public, max-age=0, s-maxage=60, must-revalidate";

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

pub fn generate_listing_artifacts(
    files_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
    config: &Map<String, Value>,
    metadata: &Map<String, Value>,
    viewer: &Map<String, Value>,
    serving: &Map<String, Value>,
    private: &BTreeSet<String>,
) -> Result<BTreeSet<String>> {
    if !config
        .get("listing")
        .and_then(Value::as_bool)
        .unwrap_or(false)
    {
        return Ok(BTreeSet::new());
    }
    let public = public_files(files, private);
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
    let mut generated = BTreeSet::new();
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
        let has_compiled_listing = config
            .get("pages")
            .and_then(Value::as_object)
            .and_then(|pages| pages.get("routes"))
            .and_then(Value::as_object)
            .and_then(|routes| routes.get(&route_path))
            .and_then(Value::as_object)
            .is_some_and(|route| route.get("index").and_then(Value::as_str).is_some());
        if files.contains_key(&index) || has_compiled_listing {
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
                write_generated(
                    files_root,
                    files,
                    &index,
                    html.as_bytes(),
                    Some("text/html; charset=utf-8"),
                )?;
                generated.insert(index);
                continue;
            }
        }
        let mut items = String::new();
        let mut dirs: Vec<_> = dirs.into_iter().collect();
        dirs.sort_by(|left, right| natural_case_cmp(left, right));
        for name in dirs {
            items.push_str(&format!(
                "<li><a href=\"/{}{}/\">{}/</a></li>",
                escape_attr(&prefix),
                escape_attr(&name),
                escape_html(&name)
            ));
        }
        let mut names: Vec<_> = names.into_iter().collect();
        names.sort_by(|left, right| natural_case_cmp(left, right));
        for name in names {
            if name != "index.html" {
                items.push_str(&format!(
                    "<li><a href=\"/{}{}\">{}</a></li>",
                    escape_attr(&prefix),
                    escape_attr(&name),
                    escape_html(&name)
                ));
            }
        }
        let html = format!("<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{}</title><style>:root{{{}}}body{{font-family:system-ui;margin:2rem;line-height:1.5}}</style></head><body><main><h1>{}</h1><ul>{}</ul></main></body></html>", escape_html(heading), theme, escape_html(heading), items);
        write_generated(
            files_root,
            files,
            &index,
            html.as_bytes(),
            Some("text/html; charset=utf-8"),
        )?;
        generated.insert(index);
    }
    Ok(generated)
}

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

fn trim_zeroes(digits: &[u8]) -> &[u8] {
    let first_nonzero = digits
        .iter()
        .position(|digit| *digit != b'0')
        .unwrap_or(digits.len().saturating_sub(1));
    &digits[first_nonzero..]
}

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

pub fn resolve_serving_config(
    config: &Map<String, Value>,
    files: &BTreeMap<String, FileMeta>,
    private: &BTreeSet<String>,
    metadata: &Map<String, Value>,
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
    private: &BTreeSet<String>,
    index: &Value,
    generated: &BTreeSet<String>,
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
        let effective = if generated.contains(path) {
            Some("index.html")
        } else {
            index_name
        };
        if let Some(name) = effective {
            if let Some(dir) = path.strip_suffix(&format!("/{name}")) {
                lookup.insert(dir.into(), action.clone());
                lookup.insert(format!("{dir}/"), action.clone());
            }
        }
    }
    for (request_path, rules) in exact_redirects {
        let Some(action) = rules
            .as_array()
            .and_then(|rules| first_exact_redirect_action(rules))
        else {
            continue;
        };
        lookup.insert(request_path.trim_start_matches('/').into(), action);
    }
    for path in private {
        lookup.insert(path.clone(), not_found_action());
    }
    lookup
}

/// Builds the PHP route manifest from the sorted lookup map, followed by the
/// dynamic routes that cannot be represented by exact lookup keys.
pub fn php_manifest(
    version_id: &str,
    lookup: &Map<String, Value>,
    compiled_routes: &[Value],
) -> Value {
    let mut routes = Vec::new();
    let mut keys: Vec<_> = lookup.keys().collect();
    keys.sort();
    for key in keys {
        let action = &lookup[key];
        let pattern = if key.is_empty() {
            "/".into()
        } else {
            format!("/{}", key.trim_start_matches('/'))
        };
        if let Some("file") = action.get("action").and_then(Value::as_str) {
            routes.push(json!({"action":"serve_static","pattern":pattern,"file":action.get("path").cloned().unwrap_or(Value::Null)}));
        } else if let Some("redirect") = action.get("action").and_then(Value::as_str) {
            routes.push(json!({
                "action": "redirect",
                "pattern": pattern,
                "destination": action.get("destination").cloned().unwrap_or(Value::Null),
                "status": action.get("status").cloned().unwrap_or(Value::Null),
                "cacheControl": action.get("cache_control").cloned().unwrap_or(Value::Null),
            }));
        } else if let Some("invoke_zero") = action.get("action").and_then(Value::as_str) {
            let Some(method) = action.get("methods").and_then(manifest_zero_method) else {
                continue;
            };
            if let Some(operation) = action.get("operation").and_then(Value::as_str) {
                routes.push(json!({
                    "action": "invoke_zero",
                    "pattern": pattern,
                    "method": method,
                    "operation": operation,
                }));
            } else if let (Some(endpoint_id), Some(zero_artifact)) = (
                action.get("endpoint_id").and_then(Value::as_str),
                action.get("zero_artifact").and_then(Value::as_str),
            ) {
                let mut route = json!({
                    "action": "invoke_zero",
                    "pattern": pattern,
                    "method": method,
                    "endpointId": endpoint_id,
                    "zeroArtifact": zero_artifact,
                    "capabilities": action.get("capabilities").cloned().unwrap_or_else(|| json!({
                        "db": true,
                        "fetch": true,
                        "auth": true,
                        "env": true,
                        "realtime": true,
                        "logging": true,
                    })),
                });
                if let Some(schema_hash) = action.get("schema_hash").and_then(Value::as_str) {
                    route["schemaHash"] = json!(schema_hash);
                }
                routes.push(route);
            }
        }
    }
    for route in compiled_routes {
        let pattern = route.get("pattern").and_then(Value::as_str);
        if pattern.is_some_and(|pattern| {
            routes
                .iter()
                .any(|existing| existing.get("pattern").and_then(Value::as_str) == Some(pattern))
        }) {
            continue;
        }
        routes.push(route.clone());
    }
    json!({"format":"stattic.php.manifest.v1","versionId":version_id,"routes":routes})
}

fn manifest_zero_method(methods: &Value) -> Option<&str> {
    methods
        .as_array()?
        .iter()
        .filter_map(Value::as_str)
        .find(|method| *method != "HEAD")
}

fn first_exact_redirect_action(rules: &[Value]) -> Option<Value> {
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
    Some(json!({
        "action": "redirect",
        "destination": destination,
        "status": status,
        "cache_control": redirect_cache_control(status),
        "methods": ["GET", "HEAD"],
    }))
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

fn redirect_cache_control(status: u64) -> &'static str {
    if matches!(status, 301 | 308) {
        "public, max-age=31536000, immutable"
    } else {
        DEFAULT_EDGE_CACHE_CONTROL
    }
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
    use std::fs;
    use tempfile::tempdir;

    fn listing_fixture(
        entries: &[(&str, &[u8])],
        config: Value,
        viewer: Value,
    ) -> (tempfile::TempDir, String) {
        let temp = tempdir().unwrap();
        let mut files: BTreeMap<String, FileMeta> = entries
            .iter()
            .map(|(path, bytes)| ((*path).to_string(), file_meta(path, bytes, None)))
            .collect();
        generate_listing_artifacts(
            temp.path(),
            &mut files,
            config.as_object().unwrap(),
            &Map::new(),
            viewer.as_object().unwrap(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();
        let html = fs::read_to_string(temp.path().join("index.html")).unwrap();
        (temp, html)
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
        let mut files = BTreeMap::from([(
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
        let generated = generate_listing_artifacts(
            temp.path(),
            &mut files,
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &BTreeSet::new(),
        )
        .unwrap();

        assert!(generated.is_empty());
        assert!(!files.contains_key("index.html"));
        assert!(!temp.path().join("index.html").exists());
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
        let mut files: BTreeMap<String, FileMeta> = entries
            .iter()
            .map(|(path, bytes)| ((*path).to_string(), file_meta(path, bytes, None)))
            .collect();
        let private = BTreeSet::new();
        let config = json!({"listing":true,"viewer":false});
        generate_listing_artifacts(
            temp.path(),
            &mut files,
            config.as_object().unwrap(),
            &Map::new(),
            &Map::new(),
            &Map::new(),
            &private,
        )
        .unwrap();
        let listing = [
            "index.html",
            ".well-known/index.html",
            ".well-known/acme-challenge/index.html",
        ]
        .into_iter()
        .filter_map(|path| fs::read_to_string(temp.path().join(path)).ok())
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
            ),
            Err(FinalizeError::Invalid {
                code: "invalid_serving_config",
                ..
            })
        ));
    }

    #[test]
    fn native_serving_defaults_use_the_shared_effective_resolver() {
        for (paths, private, expected_index, expected_listing) in [
            (
                vec!["docs/page.html"],
                vec![],
                json!("docs/page.html"),
                false,
            ),
            (vec!["asset.txt"], vec![], json!(false), true),
            (vec!["index.html"], vec![], json!("index.html"), false),
            (
                vec!["_layout.html", "docs/page/index.html"],
                vec!["_layout.html"],
                json!("docs/page/index.html"),
                false,
            ),
        ] {
            let files = paths
                .iter()
                .map(|path| ((*path).to_string(), file_meta(path, b"content", None)))
                .collect::<BTreeMap<_, _>>();
            let private = private.into_iter().map(str::to_string).collect();
            let resolved =
                resolve_serving_config(&Map::new(), &files, &private, &Map::new()).unwrap();
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
        let lookup = build_lookup_map(
            paths.iter(),
            &Map::new(),
            &private,
            &json!(false),
            &BTreeSet::new(),
        );

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
    fn exact_redirects_override_files_and_manifest_precedes_dynamic_routes() {
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
            &BTreeSet::new(),
            &json!("index.html"),
            &BTreeSet::new(),
        );
        assert_eq!(lookup["old.html"]["action"], json!("redirect"));
        assert_eq!(
            lookup["old.html"]["cache_control"],
            json!(DEFAULT_EDGE_CACHE_CONTROL)
        );

        let manifest = php_manifest(
            "v",
            &lookup,
            &[json!({
                "action": "invoke_zero",
                "pattern": "/api/:id",
                "method": "GET",
            })],
        );
        assert_eq!(manifest["routes"][2]["action"], json!("redirect"));
        assert_eq!(manifest["routes"][3]["action"], json!("invoke_zero"));
    }

    #[test]
    fn zero_lookup_records_emit_in_sorted_lookup_order_before_zero_routes() {
        let lookup = Map::from_iter([
            (
                "__spacefast/zero/run".into(),
                json!({"action":"invoke_zero","operation":"run","methods":["POST"]}),
            ),
            (
                "api/status".into(),
                json!({
                    "action":"invoke_zero",
                    "endpoint_id":"GET /api/status",
                    "zero_artifact":"zero/endpoints/status.json",
                    "methods":["GET","HEAD"],
                    "capabilities": {"db":false,"fetch":false,"auth":false,"env":false,"realtime":false,"logging":false},
                }),
            ),
        ]);
        let manifest = php_manifest(
            "v",
            &lookup,
            &[
                json!({
                    "action":"invoke_zero",
                    "pattern":"/api/status",
                    "method":"GET",
                    "endpointId":"GET /api/status",
                    "zeroArtifact":"zero/endpoints/status.json"
                }),
                json!({"action":"invoke_zero","pattern":"/api/:id","method":"GET"}),
            ],
        );
        assert_eq!(manifest["routes"][0]["operation"], json!("run"));
        assert_eq!(
            manifest["routes"][1]["endpointId"],
            json!("GET /api/status")
        );
        assert_eq!(manifest["routes"][2]["pattern"], json!("/api/:id"));
        assert_eq!(manifest["routes"].as_array().map(Vec::len), Some(3));
    }
}
