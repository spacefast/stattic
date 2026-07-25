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
];

pub(crate) fn is_private_serving_path(path: &str) -> bool {
    let lower = path.to_ascii_lowercase();
    if EXACT_PRIVATE_PATHS.contains(&path)
        || CASE_INSENSITIVE_CONFIG_PATHS.contains(&lower.as_str())
        || path.starts_with("zero/")
    {
        return true;
    }
    if let Some(source) = precompressed_source(path) {
        if is_private_serving_path(source) {
            return true;
        }
    }
    lower.split('/').enumerate().any(|(index, segment)| {
        !(index == 0 && segment == ".well-known") && segment.starts_with('.')
    })
}

pub(crate) fn is_public_serving_path(path: &str, source_exists: impl FnOnce(&str) -> bool) -> bool {
    !is_private_serving_path(path) && !precompressed_source(path).is_some_and(source_exists)
}

fn precompressed_source(path: &str) -> Option<&str> {
    let lower = path.to_ascii_lowercase();
    (lower.ends_with(".br") || lower.ends_with(".gz")).then(|| &path[..path.len() - 3])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn serving_visibility_matches_control_zero_and_sidecar_policy() {
        assert!(is_private_serving_path("SF.JSONC"));
        assert!(is_private_serving_path("zero/endpoints-index.json"));
        assert!(is_private_serving_path("SF.JSONC.GZ"));
        assert!(!is_private_serving_path("_HEADERS"));
        assert!(!is_private_serving_path("_REDIRECTS"));
        assert!(!is_public_serving_path("docs/page.html.gz", |source| {
            source == "docs/page.html"
        }));
        assert!(is_public_serving_path("standalone.gz", |_| false));
    }
}
