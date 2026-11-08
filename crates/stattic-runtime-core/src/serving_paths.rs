//! Which committed paths are privately held configuration versus publicly
//! served content. Shared by the transform resolvers and finalize policy.

use std::sync::LazyLock;

use regex::Regex;

use crate::protocol::{COMPILE_SIDECAR_FILES, CONFIG_ACCEPTED_FILES, CONFIG_TYPESCRIPT_FILE};

/// PHP-like file extensions. This is a serving-path classification, so it lives
/// here with the other path predicates and stays available to every build
/// target, including the wasm finalizer.
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

/// The route a `functions/<name>.php` handler answers, or `None` when the path
/// is not a routable function module (so any other PHP-like file is private).
pub(crate) fn php_function_route(path: &str) -> Option<String> {
    let stem = path.strip_prefix("functions/")?.strip_suffix(".php")?;
    let mut segments: Vec<&str> = stem.split('/').collect();
    if segments.last() == Some(&"index") {
        segments.pop();
    }
    if segments.iter().any(|segment| {
        segment.is_empty()
            || segment.contains('[')
            || segment.contains(']')
            || segment.starts_with(':')
            || segment.starts_with('.')
    }) {
        return None;
    }
    Some(if segments.is_empty() {
        "/".into()
    } else {
        format!("/{}", segments.join("/"))
    })
}

/// The document a directory URL serves: `/docs/` answers `docs/index.html`.
/// Fixed, not configurable: `index` picks the homepage, and every static
/// generator and prerenderer writes this name for nested pages.
pub(crate) const DIRECTORY_INDEX_SUFFIX: &str = "/index.html";

const EXACT_PRIVATE_PATHS: &[&str] = &[
    ".well-known/spacefast-runtime",
    ".well-known/stattic-runtime",
    // The Zero compiler uploads this runtime-owned receipt beside public
    // files. The PHP front door already keeps the __spacefast namespace
    // private; classify it the same way here so finalize never selects the
    // receipt as a public edge-readiness target.
    "__spacefast/zero/deploy.json",
    // The compiled cron schedule the serving engine reads. Generated beside
    // public files and never content.
    "__spacefast/crons.json",
];

/// Root directories whose script modules are server or worker source: Zero's
/// `server/` and the `functions/` file router. Their other files (HTML, images)
/// stay ordinary content.
const SOURCE_ROOT_DIRECTORIES: &[&str] = &["server/", "functions/"];
const SOURCE_MODULE_EXTENSIONS: &[&str] =
    &[".ts", ".tsx", ".mts", ".cts", ".js", ".jsx", ".mjs", ".cjs"];

/// Beyond [`CONFIG_ACCEPTED_FILES`], which are matched the same way.
const CASE_INSENSITIVE_CONFIG_PATHS: &[&str] = &[
    CONFIG_TYPESCRIPT_FILE,
    ".stattic/routes.json",
    // A compile input for the generated theme stylesheet, not content.
    "theme.json",
];

pub(crate) fn is_private_serving_path(path: &str) -> bool {
    let name = path.rsplit('/').next().unwrap_or(path);
    if COMPILE_SIDECAR_FILES.contains(&path)
        || EXACT_PRIVATE_PATHS.contains(&path)
        || CONFIG_ACCEPTED_FILES
            .iter()
            .chain(CASE_INSENSITIVE_CONFIG_PATHS)
            .any(|config| config.eq_ignore_ascii_case(path))
        // A config file or sidecar is a compile input wherever it sits: a
        // project published one level too high ships `public/sf.jsonc` or a
        // folder's own `_headers`, and neither is content.
        || COMPILE_SIDECAR_FILES.contains(&name)
        || CONFIG_ACCEPTED_FILES
            .iter()
            .copied()
            .chain([CONFIG_TYPESCRIPT_FILE])
            .any(|config| config.eq_ignore_ascii_case(name))
        || is_server_source(path)
        // PHP is never served as text. A routable `functions/<name>.php` runs
        // at its route; any other PHP-like file answers like a missing one.
        || (php_like(path) && php_function_route(path).is_none())
        || path.starts_with("__spacefast/functions/bundles/")
        || path.starts_with("__spacefast/functions/seeds/")
        // Page templates and the layout cascade are compile inputs too: the
        // reserved `_pages` directory anywhere in the tree, and `_layout.html`
        // at the root or as a trailing segment.
        || path
            .split('/')
            .any(|segment| segment.eq_ignore_ascii_case("_pages"))
        || path.eq_ignore_ascii_case("_layout.html")
        || ends_with_ignore_ascii_case(path, "/_layout.html")
    {
        return true;
    }
    if let Some(source) = precompressed_source(path) {
        if is_private_serving_path(source) {
            return true;
        }
    }
    path.split('/').enumerate().any(|(index, segment)| {
        !(index == 0 && segment.eq_ignore_ascii_case(".well-known")) && segment.starts_with('.')
    })
}

fn is_server_source(path: &str) -> bool {
    SOURCE_ROOT_DIRECTORIES
        .iter()
        .any(|directory| path.starts_with(directory))
        && SOURCE_MODULE_EXTENSIONS
            .iter()
            .any(|extension| ends_with_ignore_ascii_case(path, extension))
}

pub(crate) fn precompressed_source(path: &str) -> Option<&str> {
    (ends_with_ignore_ascii_case(path, ".br") || ends_with_ignore_ascii_case(path, ".gz"))
        .then(|| &path[..path.len() - 3])
}

fn ends_with_ignore_ascii_case(path: &str, suffix: &str) -> bool {
    let (path, suffix) = (path.as_bytes(), suffix.as_bytes());
    path.len() >= suffix.len() && path[path.len() - suffix.len()..].eq_ignore_ascii_case(suffix)
}

static HEX_DIGEST_NAME: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)^.*[.\-_]([0-9a-f]{8,32})\.[a-z0-9]{1,8}$").expect("hex digest name regex")
});

/// `app.3f9c2d1a.js`, `styles.abc123def456.css`: a hex digest needs both a
/// letter and a digit, or it is a date or a counter.
pub(crate) fn carries_hex_digest(name: &str) -> bool {
    HEX_DIGEST_NAME
        .captures(name)
        .and_then(|hit| hit.get(1))
        .is_some_and(|digest| {
            let digest = digest.as_str();
            digest.bytes().any(|byte| byte.is_ascii_alphabetic())
                && digest.bytes().any(|byte| byte.is_ascii_digit())
        })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Page templates, layouts and `theme.json` are compile inputs: the
    /// compiled document serves, the source never does. Before this was held
    /// here, `GET /_pages/404.html` handed back the raw template.
    #[test]
    fn compile_inputs_never_serve_as_content() {
        for path in [
            "_pages/404.html",
            "_pages/collab.html",
            "docs/_pages/404.html",
            "_PAGES/404.html",
            "_layout.html",
            "docs/_layout.html",
            "_LAYOUT.HTML",
            "theme.json",
            "_pages/404.html.gz",
        ] {
            assert!(is_private_serving_path(path), "{path} must stay private");
        }
        // A `_pages` *segment*, not a prefix or a substring: the reserved
        // directory is what is held back, not every path that mentions it.
        for path in [
            "_pagespeed/report.html",
            "docs/theme.json",
            "my_pages/a.html",
        ] {
            assert!(!is_private_serving_path(path), "{path} must stay public");
        }
    }

    #[test]
    fn serving_visibility_matches_control_and_sidecar_policy() {
        assert!(is_private_serving_path("SF.JSONC"));
        assert!(is_private_serving_path("SPACEFAST.CONFIG.TS"));
        assert!(is_private_serving_path("spacefast.config.ts.gz"));
        assert!(!is_private_serving_path("zero/index.html"));
        assert!(is_private_serving_path(
            "__spacefast/functions/bundles/worker/bundle.json"
        ));
        assert!(is_private_serving_path("SF.JSONC.GZ"));
        assert!(!is_private_serving_path("_HEADERS"));
        assert!(!is_private_serving_path("_REDIRECTS"));
        assert!(is_private_serving_path("SF.JSONC.GZ.BR"));
        assert!(!is_private_serving_path("docs/page.html.gz"));
        assert!(!is_private_serving_path("standalone.gz"));
    }

    /// Config, sidecars, server and function source, and stray PHP are
    /// private at any depth, while a folder's ordinary content still serves.
    #[test]
    fn config_and_source_stay_private_below_the_root() {
        for path in [
            "public/sf.jsonc",
            "docs/sf.jsonc",
            "docs/SpaceFast.JSON",
            "app/spacefast.config.ts",
            "docs/_headers",
            "blog/_redirects",
            "nested/_routes.json",
            "server/index.ts",
            "server/lib/db.mjs",
            "functions/hello.ts",
            "functions/api/[id].js",
            "uploaded.php",
            "docs/legacy.PHTML",
            "functions/[id].php",
            "public/sf.jsonc.gz",
        ] {
            assert!(is_private_serving_path(path), "{path} must stay private");
        }
        for path in [
            "docs/index.html",
            "docs/guide.json",
            "server/index.html",
            "server/logo.png",
            "functions/readme.html",
            "assets/app.js",
            "assets/server/index.js",
            "headers.css",
            "docs/sf.jsonc.html",
            // A routable handler is not content either, but it executes at its
            // route, so the serving path, not this predicate, hides its source.
            "functions/hello.php",
        ] {
            assert!(!is_private_serving_path(path), "{path} must stay public");
        }
    }
}
