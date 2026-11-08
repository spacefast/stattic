//! Hosted TSX pages: the `zero/pages.json` render artifact.
//!
//! `zero-pages build` (Node) runs page modules once and serializes them with WordPress's own block
//! code; a hosting box has no JavaScript, so that render cannot happen there. The build therefore
//! writes its result as data, `zero/pages.json`: the plugin manifest, every template, body and
//! asset, the compiled schema the pages were rendered against, and a SHA-256 of each authored text
//! input. It holds no PHP.
//!
//! Capture ([`check`] from [`crate::bundle::capture`]) takes the artifact and every input it names
//! into the app bundle, refusing it when an input, a route file or the schema changed after the
//! render. The host compiler runs the same check over the captured bundle and then [`emit`]s
//! `zero-pages-<app>/`: the artifact's files plus the pages runtime embedded from `runtime/pages/`,
//! byte for byte what `zero-pages build` installs.

use crate::{
    diag::{Code, Diag, Span},
    loader::Fs,
    pipeline::Built,
};
use serde::Deserialize;
use serde_json::{Value, json};
use std::{
    collections::BTreeMap,
    path::{Path, PathBuf},
};

pub const ARTIFACT: &str = "zero/pages.json";
const FORMAT: &str = "zero.pages.v1";
/// Decoded plugin files; the source bundle as a whole is bounded separately by the host.
const MAX_BYTES: usize = 16 * 1024 * 1024;
const ASSET_EXTENSIONS: &[&str] = &[
    "css", "js", "svg", "png", "jpg", "jpeg", "gif", "webp", "avif", "woff", "woff2",
];
/// First URL segments WordPress or Zero own (`packages/pages/src/routes.ts`).
pub const RESERVED_FIRST: &[&str] = &[
    "wp-admin",
    "wp-content",
    "wp-includes",
    "wp-json",
    "wp-login.php",
    "wp-signup.php",
    "wp-cron.php",
    "xmlrpc.php",
    "wp-sitemap.xml",
    "feed",
    "comments",
    "search",
    "author",
    "category",
    "tag",
    "page",
    "embed",
    "trackback",
    "robots.txt",
    "favicon.ico",
    "zero-admin",
    "auth",
];
const ROUTE_FILES: &[&str] = &["page.tsx", "layout.tsx", "not-found.tsx"];

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Artifact {
    format: String,
    app: String,
    schema: Value,
    inputs: BTreeMap<String, String>,
    manifest: String,
    files: BTreeMap<String, String>,
}

/// A checked artifact: the manifest text, decoded files, and the static URL patterns it serves.
pub struct Pages {
    pub app: String,
    pub manifest: String,
    pub files: BTreeMap<String, Vec<u8>>,
    /// Every route pattern (`/notes/[]` for an item route), for conflicts with HTTP routes.
    pub patterns: Vec<String>,
}

fn stale(message: impl Into<String>) -> Diag {
    Diag::new(Span::NONE, message)
        .code(Code::SourceNeedsLocalCapture)
        .help("run `zero build` and then `zero-pages build` in the project, then capture or publish again")
}

fn invalid(message: impl Into<String>) -> Diag {
    Diag::new(Span::NONE, format!("invalid zero/pages.json: {}", message.into()))
}

fn sha256(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(bytes).iter().map(|b| format!("{b:02x}")).collect()
}

/// What `zero-pages build` reads from `zero build` output (`loadSchema` in packages/pages/src/build.ts),
/// derived from the compiled schema instead: the pages must have been rendered against exactly this.
pub fn expected_schema(built: &Built) -> Value {
    let mut collections = serde_json::Map::new();
    let mut post_types = serde_json::Map::new();
    for c in built.schema.collections.values() {
        let Some(table) = built.snapshot["tables"].get(&c.key) else {
            // A renamed storage binding: the pages build refuses it, so no artifact can match.
            collections.insert(c.key.clone(), Value::Null);
            continue;
        };
        let mut columns = serde_json::Map::new();
        let mut slug = Value::Null;
        for (name, column) in table["columns"].as_object().into_iter().flatten() {
            columns.insert(name.clone(), json!({ "type": column["type"] }));
            if column["type"] == "slug" {
                slug = Value::String(name.clone());
            }
        }
        let editor = table["editor"] == true;
        collections.insert(
            c.key.clone(),
            json!({ "editor": editor, "slug": slug, "columns": columns }),
        );
        if editor {
            post_types.insert(c.key.clone(), Value::String(format!("zero_{}", c.key)));
        }
    }
    json!({
        "app": built.schema.name,
        "schema": collections,
        "postTypes": post_types,
        "actions": built.schema.actions.keys().collect::<Vec<_>>(),
    })
}

/// Route files below `pages/`, as the pages build discovers them (dot, `_private` and
/// node_modules directories never route).
fn route_files(fs: &dyn Fs, root: &Path, dir: &Path, out: &mut Vec<String>) -> Result<(), Diag> {
    let mut entries = fs.read_dir(dir).map_err(|e| Diag::new(Span::NONE, e.to_string()))?;
    entries.sort();
    for path in entries {
        let name = path.file_name().unwrap_or_default().to_string_lossy().into_owned();
        if fs.is_dir(&path) {
            if !(name.starts_with('.') || name.starts_with('_') || name == "node_modules") {
                route_files(fs, root, &path, out)?;
            }
        } else if ROUTE_FILES.contains(&name.as_str()) {
            out.push(relative(root, &path));
        }
    }
    Ok(())
}

fn relative(root: &Path, path: &Path) -> String {
    path.strip_prefix(root)
        .unwrap_or(path)
        .to_string_lossy()
        .replace('\\', "/")
}

/// The project's checked pages, or `None` without a `pages/` directory. Every file it reads goes
/// through `fs`, so a recording filesystem captures the artifact and its inputs.
pub fn check(fs: &dyn Fs, root: &Path, built: &Built) -> Result<Option<Pages>, Diag> {
    let pages_dir = root.join("pages");
    if !fs.is_dir(&pages_dir) {
        return Ok(None);
    }
    let path: PathBuf = root.join(ARTIFACT);
    if !fs.is_file(&path) {
        return Err(stale(
            "pages/ has no render artifact (zero/pages.json); the host cannot run JavaScript to render TSX pages",
        ));
    }
    let text = fs.read_to_string(&path).map_err(|e| invalid(e.to_string()))?;
    let artifact: Artifact = serde_json::from_str(&text).map_err(|e| invalid(e.to_string()))?;
    if artifact.format != FORMAT {
        return Err(invalid(format!("format must be {FORMAT}")));
    }
    if artifact.app != built.schema.name {
        return Err(stale(format!(
            "zero/pages.json was rendered for app \"{}\", not \"{}\"",
            artifact.app, built.schema.name
        )));
    }
    if artifact.schema != expected_schema(built) {
        return Err(stale(
            "zero/pages.json was rendered against a different schema than zero.config.ts compiles to",
        ));
    }
    for (input, hash) in &artifact.inputs {
        crate::bundle::check_input(input)?;
        let source = fs
            .read_to_string(&root.join(input))
            .map_err(|_| stale(format!("zero/pages.json is stale: its input {input} is missing")))?;
        if sha256(source.as_bytes()) != *hash {
            return Err(stale(format!(
                "zero/pages.json is stale: {input} changed after `zero-pages build`"
            )));
        }
    }
    let mut routes = Vec::new();
    route_files(fs, root, &pages_dir, &mut routes)?;
    if let Some(missing) = routes.iter().find(|file| !artifact.inputs.contains_key(*file)) {
        return Err(stale(format!(
            "zero/pages.json is stale: {missing} was added after `zero-pages build`"
        )));
    }
    let pages = validate(artifact)?;
    // A page owns its URL for every method: a route that could match any URL a page serves is refused.
    for route in built.schema.routes.values() {
        let segments = |p: &str| -> Vec<String> { p.split('/').filter(|s| !s.is_empty()).map(str::to_owned).collect() };
        let route_segments = segments(&route.path);
        let rest = route_segments.last().is_some_and(|s| s.starts_with('*'));
        let overlaps = |page: &String| {
            let page_segments = segments(page);
            let fixed = if rest {
                route_segments.len() - 1
            } else {
                route_segments.len()
            };
            // A `*rest` route can match a page with one or more segments after its fixed prefix.
            (if rest {
                page_segments.len() > fixed
            } else {
                page_segments.len() == fixed
            }) && page_segments
                .iter()
                .zip(&route_segments[..fixed])
                .all(|(p, r)| p == "[]" || r.starts_with(':') || p == r)
        };
        if let Some(page) = pages.patterns.iter().find(|p| overlaps(p)) {
            return Err(Diag::new(
                Span::NONE,
                format!(
                    "route {} {} can match a URL the page {page} serves",
                    route.method, route.path
                ),
            )
            .help("move the route to a path no page can serve, such as /api/..."));
        }
    }
    Ok(Some(pages))
}

fn validate(artifact: Artifact) -> Result<Pages, Diag> {
    let mut files = BTreeMap::new();
    let mut total = 0usize;
    for (name, encoded) in &artifact.files {
        if !plugin_file(name) {
            return Err(invalid(format!("{name} is not a template, body or asset path")));
        }
        let bytes = base64_decode(encoded).ok_or_else(|| invalid(format!("{name} is not base64")))?;
        total += bytes.len();
        if total > MAX_BYTES {
            return Err(invalid("rendered pages exceed 16 MiB"));
        }
        files.insert(name.clone(), bytes);
    }
    let manifest: Value = serde_json::from_str(&artifact.manifest).map_err(|e| invalid(e.to_string()))?;
    if manifest["version"] != 1 || manifest["app"] != artifact.app.as_str() {
        return Err(invalid("manifest version or app does not match"));
    }
    if !manifest["digest"]
        .as_str()
        .is_some_and(|d| d.len() == 64 && d.bytes().all(|b| b.is_ascii_hexdigit()))
    {
        return Err(invalid("manifest digest"));
    }
    let schema = &artifact.schema["schema"];
    let islands = manifest["islands"]
        .as_object()
        .ok_or_else(|| invalid("manifest islands"))?;
    for (namespace, island) in islands {
        let file = island["file"].as_str().unwrap_or_default();
        if island["namespace"] != namespace.as_str() || !files.contains_key(file) || !file.ends_with(".js") {
            return Err(invalid(format!("island {namespace}")));
        }
        for action in island["actions"].as_array().ok_or_else(|| invalid("island actions"))? {
            if !artifact.schema["actions"]
                .as_array()
                .is_some_and(|known| known.contains(action))
            {
                return Err(invalid(format!("island {namespace} calls an unknown action")));
            }
        }
    }
    // Only the `notFound` entry is exempt from URL checks; it is never matched against a path.
    let mut routes: Vec<(&Value, bool)> = manifest["routes"]
        .as_array()
        .ok_or_else(|| invalid("manifest routes"))?
        .iter()
        .map(|route| (route, false))
        .collect();
    if routes.is_empty() {
        return Err(invalid("no routes"));
    }
    match &manifest["notFound"] {
        Value::Null => {}
        route @ Value::Object(_) => routes.push((route, true)),
        _ => return Err(invalid("manifest notFound")),
    }
    let mut patterns = Vec::new();
    for (route, not_found) in routes {
        let pattern = route["pattern"].as_str().unwrap_or_default();
        if not_found != (route["id"] == "zero:not-found")
            || (not_found && (pattern != "/404" || !route["param"].is_null()))
        {
            return Err(invalid(format!(
                "route {pattern} uses the not-found identity outside notFound"
            )));
        }
        if !not_found {
            check_pattern(pattern, route["param"].is_string())?;
            patterns.push(pattern.to_owned());
        }
        let file = |key: &str| route[key].as_str().filter(|f| files.contains_key(*f));
        let template = file("template").filter(|f| f.starts_with("templates/"));
        let body_ok = route["body"].is_null() || file("body").is_some_and(|f| f.starts_with("content/"));
        if template.is_none() || !body_ok {
            return Err(invalid(format!("route {pattern} names a missing template or body")));
        }
        for css in route["css"].as_array().ok_or_else(|| invalid("route css"))? {
            if !css
                .as_str()
                .is_some_and(|f| files.contains_key(f) && f.ends_with(".css"))
            {
                return Err(invalid(format!("route {pattern} names a missing stylesheet")));
            }
        }
        for module in route["modules"].as_array().ok_or_else(|| invalid("route modules"))? {
            if !module.as_str().is_some_and(|m| islands.contains_key(m)) {
                return Err(invalid(format!("route {pattern} names an unknown island")));
            }
        }
        if let Some(collection) = route["collection"].as_str()
            && (!schema[collection].is_object()
                || route["postType"] != artifact.schema["postTypes"][collection]
                || route["slugColumn"] != schema[collection]["slug"])
        {
            return Err(invalid(format!(
                "route {pattern} serves {collection} inconsistently with the schema"
            )));
        }
        for (_, query) in route["queries"].as_object().ok_or_else(|| invalid("route queries"))? {
            if !query["collection"].as_str().is_some_and(|c| schema[c].is_object()) {
                return Err(invalid(format!("route {pattern} queries an unknown collection")));
            }
        }
    }
    Ok(Pages {
        app: artifact.app,
        manifest: artifact.manifest,
        files,
        patterns,
    })
}

/// `/`, or literal segments with one optional `[]` last: what the route discovery produces.
fn check_pattern(pattern: &str, item: bool) -> Result<(), Diag> {
    let segments: Vec<&str> = pattern
        .strip_prefix('/')
        .unwrap_or("!")
        .split('/')
        .filter(|s| !s.is_empty())
        .collect();
    let literal = |s: &str| {
        !s.is_empty()
            && s.bytes()
                .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b"._~-".contains(&b))
            && !s.starts_with('.')
    };
    let ok = pattern.starts_with('/')
        && (pattern == "/" || !pattern.ends_with('/'))
        && segments.iter().enumerate().all(|(i, s)| {
            if *s == "[]" {
                item && i == segments.len() - 1
            } else {
                literal(s)
            }
        })
        && (segments.last() == Some(&"[]")) == item
        && !segments.first().is_some_and(|s| RESERVED_FIRST.contains(s));
    if ok {
        Ok(())
    } else {
        Err(invalid(format!("route pattern {pattern}")))
    }
}

fn plugin_file(name: &str) -> bool {
    let hashed = |prefix: &str| {
        name.strip_prefix(prefix)
            .and_then(|rest| rest.strip_suffix(".html"))
            .is_some_and(|key| key.len() == 64 && key.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)))
    };
    if hashed("templates/r") || hashed("content/r") {
        return true;
    }
    let Some(asset) = name.strip_prefix("assets/") else {
        return false;
    };
    !asset.is_empty()
        && !asset.starts_with('.')
        && asset.bytes().all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b))
        && asset
            .rsplit_once('.')
            .is_some_and(|(_, ext)| ASSET_EXTENSIONS.contains(&ext.to_ascii_lowercase().as_str()))
}

fn base64_decode(text: &str) -> Option<Vec<u8>> {
    let value = |c: u8| -> Option<u32> {
        Some(match c {
            b'A'..=b'Z' => c - b'A',
            b'a'..=b'z' => c - b'a' + 26,
            b'0'..=b'9' => c - b'0' + 52,
            b'+' => 62,
            b'/' => 63,
            _ => return None,
        } as u32)
    };
    let bytes = text.as_bytes();
    if !bytes.len().is_multiple_of(4) {
        return None;
    }
    let mut out = Vec::with_capacity(bytes.len() / 4 * 3);
    for (index, chunk) in bytes.chunks(4).enumerate() {
        let last = index == bytes.len() / 4 - 1;
        let pad = chunk.iter().rev().take_while(|b| **b == b'=').count();
        if pad > 2 || (pad > 0 && !last) {
            return None;
        }
        let mut n = 0u32;
        for (i, b) in chunk.iter().enumerate() {
            let v = if i >= 4 - pad { 0 } else { value(*b)? };
            n |= v << (18 - 6 * i);
        }
        out.extend_from_slice(&n.to_be_bytes()[1..4 - pad]);
    }
    Some(out)
}

/// The plugin header `zero-pages build` writes (`bootstrap` in packages/pages/src/build.ts).
#[cfg(feature = "host")]
fn bootstrap(app: &str) -> String {
    format!(
        "<?php\n/**\n * Plugin Name: {app} pages (Zero)\n * Description: TSX pages compiled by @spacefast/zero-pages: routes, layouts and regions rendered by WordPress.\n * Requires at least: 7.1\n * Requires PHP: 8.2\n * Requires Plugins: zero-runtime, zero-app-{app}\n * License: GPL-2.0-or-later\n */\ndeclare(strict_types=1);\ndefined('ABSPATH') || exit;\nrequire_once __DIR__ . '/zero-pages/bootstrap.php';\n\\Zero\\Pages\\Plugin::boot(__FILE__, '{app}');\n"
    )
}

/// Write `plugins/zero-pages-<app>/`; the directory must not exist yet.
#[cfg(feature = "host")]
pub fn emit(plugins: &Path, pages: &Pages) -> std::io::Result<PathBuf> {
    let slug = format!("zero-pages-{}", pages.app);
    let directory = plugins.join(&slug);
    std::fs::create_dir(&directory)?;
    for sub in ["templates", "content", "assets", "zero-pages"] {
        std::fs::create_dir(directory.join(sub))?;
    }
    let mut runtime = 0;
    for (rel, bytes) in crate::runtime_files::FILES {
        if let Some(rest) = rel.strip_prefix("pages/") {
            crate::emit::write(&directory.join("zero-pages").join(rest), bytes)?;
            runtime += 1;
        }
    }
    assert!(runtime > 0, "the pages runtime is embedded");
    for (name, bytes) in &pages.files {
        std::fs::write(directory.join(name), bytes)?;
    }
    std::fs::write(directory.join("manifest.json"), &pages.manifest)?;
    std::fs::write(directory.join(format!("{slug}.php")), bootstrap(&pages.app))?;
    Ok(directory)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn base64_round_trips_every_padding() {
        for (text, bytes) in [
            ("", &b""[..]),
            ("Zg==", b"f"),
            ("Zm8=", b"fo"),
            ("Zm9v", b"foo"),
            ("Zm9vYg==", b"foob"),
        ] {
            assert_eq!(base64_decode(text).as_deref(), Some(bytes));
        }
        for bad in ["Zg=", "Z===", "Zg==Zm9v", "Zm9v!A=="] {
            assert_eq!(base64_decode(bad), None, "{bad}");
        }
    }

    #[test]
    fn plugin_paths_are_templates_bodies_or_plain_assets() {
        let key = "a".repeat(64);
        for good in [
            format!("templates/r{key}.html"),
            format!("content/r{key}.html"),
            "assets/island-1.js".into(),
        ] {
            assert!(plugin_file(&good), "{good}");
        }
        for bad in [
            "assets/x.php",
            "assets/../x.css",
            "assets/.htaccess",
            "zero-pages/Plugin.php",
            "templates/x.html",
            "manifest.json",
            "assets/a/b.css",
        ] {
            assert!(!plugin_file(bad), "{bad}");
        }
    }

    #[test]
    fn patterns_match_route_discovery() {
        for (pattern, item) in [
            ("/", false),
            ("/about", false),
            ("/notes/[]", true),
            ("/a.b/c~d", false),
        ] {
            assert!(check_pattern(pattern, item).is_ok(), "{pattern}");
        }
        for (pattern, item) in [
            ("about", false),
            ("/about/", false),
            ("/[]/x", true),
            ("/wp-json/x", false),
            ("/notes/[]", false),
            ("/Up", false),
            ("/.x", false),
        ] {
            assert!(check_pattern(pattern, item).is_err(), "{pattern}");
        }
    }
}
