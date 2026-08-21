//! Which committed paths are privately held configuration versus publicly
//! served content. Shared by the transform resolvers and finalize policy.

const EXACT_PRIVATE_PATHS: &[&str] = &[
    "_redirects",
    "_headers",
    "_config.json",
    "_routes.json",
    ".well-known/spacefast-runtime",
    ".well-known/stattic-runtime",
    "zero",
    // The Zero compiler uploads this runtime-owned receipt beside public
    // files. The PHP front door already keeps the __spacefast namespace
    // private; classify it the same way here so finalize never selects the
    // receipt as a public edge-readiness target.
    "__spacefast/zero/deploy.json",
    // The compiled cron schedule the serving engine reads. Generated beside
    // public files and never content.
    "__spacefast/crons.json",
];

const CASE_INSENSITIVE_CONFIG_PATHS: &[&str] = &[
    "sf.jsonc",
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
    ".stattic/routes.json",
    // A compile input for the generated theme stylesheet, not content.
    "theme.json",
];

pub(crate) fn is_private_serving_path(path: &str) -> bool {
    if EXACT_PRIVATE_PATHS.contains(&path)
        || CASE_INSENSITIVE_CONFIG_PATHS
            .iter()
            .any(|config| config.eq_ignore_ascii_case(path))
        || path.starts_with("zero/")
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

pub(crate) fn is_public_serving_path(path: &str, source_exists: impl FnOnce(&str) -> bool) -> bool {
    !is_private_serving_path(path) && !precompressed_source(path).is_some_and(source_exists)
}

fn precompressed_source(path: &str) -> Option<&str> {
    (ends_with_ignore_ascii_case(path, ".br") || ends_with_ignore_ascii_case(path, ".gz"))
        .then(|| &path[..path.len() - 3])
}

fn ends_with_ignore_ascii_case(path: &str, suffix: &str) -> bool {
    let (path, suffix) = (path.as_bytes(), suffix.as_bytes());
    path.len() >= suffix.len() && path[path.len() - suffix.len()..].eq_ignore_ascii_case(suffix)
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
    fn serving_visibility_matches_control_zero_and_sidecar_policy() {
        assert!(is_private_serving_path("SF.JSONC"));
        assert!(is_private_serving_path("zero/endpoints-index.json"));
        assert!(is_private_serving_path(
            "__spacefast/functions/bundles/worker/bundle.json"
        ));
        assert!(is_private_serving_path("SF.JSONC.GZ"));
        assert!(!is_private_serving_path("_HEADERS"));
        assert!(!is_private_serving_path("_REDIRECTS"));
        assert!(!is_public_serving_path("docs/page.html.gz", |source| {
            source == "docs/page.html"
        }));
        assert!(is_public_serving_path("standalone.gz", |_| false));
    }
}
