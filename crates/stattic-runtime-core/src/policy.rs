//! Fail-closed validation of the serving policy surface: theme CSS bounds,
//! fallback targets, redirect/rewrite/proxy destinations, and response header
//! operations.

use serde_json::{Map, Value};
use std::borrow::Cow;
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

use crate::finalize::{invalid, FileMeta, Result};
use crate::protocol::SPACE_THEME_CSS_MAX_BYTES;
use crate::serving_paths::is_private_serving_path as is_private;

const RUNTIME_ENGINE_MANIFEST: &str = include_str!(concat!(
    env!("CARGO_MANIFEST_DIR"),
    "/../../runtime/engine-manifest.json"
));

pub struct FinalizePolicyContext<'a> {
    pub config: &'a Map<String, Value>,
    pub files: &'a BTreeMap<String, FileMeta>,
    pub redirects_exact: &'a Map<String, Value>,
    pub redirects_pattern: &'a [Value],
    pub headers_exact: &'a Map<String, Value>,
    pub headers_pattern: &'a [Value],
    pub body: &'a Value,
    pub private: &'a BTreeSet<String>,
}

pub fn validate_finalize_policy(context: FinalizePolicyContext<'_>) -> Result<()> {
    let FinalizePolicyContext {
        config,
        files,
        redirects_exact,
        redirects_pattern,
        headers_exact,
        headers_pattern,
        body,
        private,
    } = context;
    if let Some(theme_css) = body
        .get("serving")
        .and_then(Value::as_object)
        .and_then(|serving| serving.get("theme_css"))
        .and_then(Value::as_str)
        .map(str::trim)
        .filter(|value| !value.is_empty())
    {
        if theme_css.len() > SPACE_THEME_CSS_MAX_BYTES || style_value_forbidden(theme_css) {
            return invalid(
                "invalid_serving_config",
                "serving.theme_css must be a bounded CSS custom-property block.",
            );
        }
    }
    if let Some(fallback) = config.get("fallback").and_then(Value::as_object) {
        if let Some(path) = fallback.get("path").and_then(Value::as_str) {
            let path = path.trim_start_matches('/');
            if private.contains(path) || is_private(path) || !files.contains_key(path) {
                return invalid(
                    "invalid_serving_config",
                    "serving.config.fallback must target a public committed file.",
                );
            }
        }
    }
    if let Some(pages) = config.get("pages").and_then(Value::as_object) {
        if let Some(vars) = pages.get("theme_vars").and_then(Value::as_str) {
            validate_style_value(vars, "serving.config.pages.theme_vars")?;
        }
        if let Some(theme) = pages.get("theme").and_then(Value::as_object) {
            for key in ["accent", "background", "fontFamily"] {
                if let Some(value) = theme.get(key).and_then(Value::as_str) {
                    validate_style_value(value, "serving.config.pages.theme")?;
                }
            }
        }
    }
    for rule in redirects_exact
        .values()
        .flat_map(|value| value.as_array().into_iter().flatten())
        .chain(redirects_pattern.iter())
    {
        let destination = rule
            .get("destination")
            .and_then(Value::as_str)
            .unwrap_or("");
        let action = rule.get("action").and_then(Value::as_str).unwrap_or("");
        let destination_path = redirect_destination_path(destination);
        let absolute_target = destination_path
            .as_deref()
            .filter(|target| target.starts_with('/'))
            .map(|target| target.trim_start_matches('/'))
            .filter(|target| !target.is_empty());
        if absolute_target.is_some_and(runtime_control_target) {
            return invalid(
                "runtime_artifact_validation_failed",
                "redirects: route destinations cannot target runtime control paths.",
            );
        }
        if matches!(action, "rewrite" | "notFound") {
            let target = destination_path
                .as_deref()
                .unwrap_or(destination)
                .trim_start_matches('/');
            if private.contains(target) || is_private(target) {
                return invalid(
                    "runtime_artifact_validation_failed",
                    "A rewrite cannot target a private configuration file.",
                );
            }
        }
        if let Some(cache) = rule.get("cache") {
            let cache_mode = cache.as_str();
            if (!cache.is_null() && cache_mode != Some("shared"))
                || (cache_mode == Some("shared") && action != "proxy")
            {
                return invalid(
                    "runtime_artifact_validation_failed",
                    "Redirect artifact contains an invalid proxy cache mode.",
                );
            }
        }
        if action == "proxy" && !public_proxy_destination(destination) {
            return invalid(
                "runtime_artifact_validation_failed",
                "Proxy destinations must use a public HTTP or HTTPS upstream.",
            );
        }
    }
    for rule in headers_exact
        .values()
        .flat_map(|value| value.as_array().into_iter().flatten())
        .chain(headers_pattern.iter())
    {
        for operation in rule
            .get("operations")
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
        {
            let kind = operation.get("kind").and_then(Value::as_str).unwrap_or("");
            let name = operation
                .get("name")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_ascii_lowercase();
            let value = operation.get("value");
            let valid_value = match kind {
                "set" | "basicAuth" => value
                    .and_then(Value::as_str)
                    .is_some_and(|value| !value.contains(['\r', '\n', '\0'])),
                "remove" => value.is_none_or(Value::is_null),
                _ => false,
            };
            if !valid_header_name(&name) || !valid_value {
                return invalid(
                    "runtime_artifact_validation_failed",
                    "headers: response header operations must have valid kinds, names, and values.",
                );
            }
            if platform_managed_response_header(&name) {
                return invalid(
                    "runtime_artifact_validation_failed",
                    format!("The {name} header is managed by the platform."),
                );
            }
        }
        if let Some(headers) = rule.get("headers").and_then(Value::as_object) {
            for (name, value) in headers {
                if !valid_header_name(name)
                    || value
                        .as_str()
                        .is_none_or(|value| value.contains(['\r', '\n', '\0']))
                {
                    return invalid(
                        "runtime_artifact_validation_failed",
                        "headers: response header maps must contain valid string names and values.",
                    );
                }
            }
        }
    }
    Ok(())
}

/// The single Rust authority for response headers controlled by the platform.
///
/// The finalizer uses this policy when compiling and validating `_headers`;
/// the Zero runner applies the same list to endpoint responses. Ported from
/// the donor Zero compiler so this stage stays self-contained.
#[must_use]
pub fn platform_managed_response_header(name: &str) -> bool {
    let name = name.trim().to_ascii_lowercase();
    name.starts_with("x-spacefast-")
        || name.starts_with("x-stattic-")
        || matches!(
            name.as_str(),
            "accept-ranges"
                | "age"
                | "allow"
                | "alt-svc"
                | "cdn-cache-control"
                | "cloudflare-cdn-cache-control"
                | "connection"
                | "content-encoding"
                | "content-length"
                | "content-range"
                | "cookie"
                | "date"
                | "host"
                | "keep-alive"
                | "location"
                | "netlify-cdn-cache-control"
                | "proxy-authenticate"
                | "proxy-authorization"
                | "server"
                | "set-cookie"
                | "strict-transport-security"
                | "surrogate-control"
                | "te"
                | "trailer"
                | "transfer-encoding"
                | "upgrade"
                | "vary"
                | "x-accel-buffering"
                | "x-accel-redirect"
                | "x-lighttpd-send-file"
                | "x-sendfile"
        )
}

fn redirect_destination_path(destination: &str) -> Option<Cow<'_, str>> {
    if let Ok(url) = url::Url::parse(destination) {
        if matches!(url.scheme(), "http" | "https") {
            return Some(Cow::Owned(url.path().to_string()));
        }
    }
    Some(Cow::Borrowed(
        destination.split(['?', '#']).next().unwrap_or(""),
    ))
}

fn runtime_control_target(path: &str) -> bool {
    let canonical = path.trim_end_matches('/');
    let lower = canonical.to_ascii_lowercase();
    let segments = lower.split('/').collect::<Vec<_>>();
    if canonical.is_empty()
        || path.contains(['\0', '\\'])
        || path.contains("//")
        || segments.iter().any(|segment| {
            segment.is_empty()
                || matches!(*segment, "." | "..")
                || segment.ends_with(['.', ' '])
                || segment.starts_with("__spacefast")
                || segment.starts_with("__stattic")
        })
    {
        return true;
    }
    let name = segments.last().copied().unwrap_or("");
    matches!(name, ".htaccess" | ".user.ini")
        || (segments.len() == 1 && runtime_engine_root_names().contains(name))
        || [
            ".spacefast/storage",
            ".spacefast/engine",
            ".stattic/storage",
            ".stattic/engine",
            ".well-known/spacefast-runtime",
            ".well-known/stattic-runtime",
        ]
        .iter()
        .any(|prefix| lower == *prefix || lower.starts_with(&format!("{prefix}/")))
}

fn runtime_engine_root_names() -> &'static BTreeSet<String> {
    static NAMES: OnceLock<BTreeSet<String>> = OnceLock::new();
    NAMES.get_or_init(|| {
        let manifest = serde_json::from_str::<Value>(RUNTIME_ENGINE_MANIFEST)
            .expect("the embedded runtime engine manifest must be valid JSON");
        let mut names = BTreeSet::from(["installer.php".to_string()]);
        for path in manifest
            .get("files")
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(Value::as_str)
            .chain(
                manifest
                    .get("aliases")
                    .and_then(Value::as_array)
                    .into_iter()
                    .flatten()
                    .filter_map(Value::as_object)
                    .flat_map(|alias| [alias.get("source"), alias.get("path")])
                    .flatten()
                    .filter_map(Value::as_str),
            )
        {
            if let Some(name) = path.rsplit('/').next() {
                let name = name.to_ascii_lowercase();
                if name != "index.php" {
                    names.insert(name);
                }
            }
        }
        names
    })
}

fn valid_header_name(name: &str) -> bool {
    !name.is_empty()
        && name.bytes().all(|byte| {
            byte.is_ascii_alphanumeric()
                || matches!(
                    byte,
                    b'!' | b'#'
                        | b'$'
                        | b'%'
                        | b'&'
                        | b'\''
                        | b'*'
                        | b'+'
                        | b'-'
                        | b'.'
                        | b'^'
                        | b'_'
                        | b'`'
                        | b'|'
                        | b'~'
                )
        })
}

fn validate_style_value(value: &str, field: &str) -> Result<()> {
    if style_value_forbidden(value) || value.contains("/*") {
        return invalid(
            "invalid_serving_config",
            format!("{field} contains a forbidden style token."),
        );
    }
    Ok(())
}

fn style_value_forbidden(value: &str) -> bool {
    let lower = value.to_ascii_lowercase();
    value.contains(['<', '>'])
        || lower.contains("url(")
        || lower.contains("url (")
        || lower.contains("@import")
        || lower.contains("expression(")
        || lower.contains("expression (")
        || lower.contains("data:")
}

fn public_proxy_destination(destination: &str) -> bool {
    let test_allowlist = std::env::var("SPACEFAST_EGRESS_TEST_ALLOWLIST").ok();
    if proxy_destination_test_allowlisted(destination, test_allowlist.as_deref()) {
        return true;
    }
    let Some(rest) = destination
        .strip_prefix("https://")
        .or_else(|| destination.strip_prefix("http://"))
    else {
        return false;
    };
    let authority = rest.split('/').next().unwrap_or("");
    let host = authority
        .rsplit('@')
        .next()
        .unwrap_or("")
        .trim_matches(['[', ']'])
        .split(':')
        .next()
        .unwrap_or("")
        .to_ascii_lowercase();
    if host.is_empty()
        || host == "localhost"
        || host.ends_with(".localhost")
        || host == "api.spacefast.com"
        || host.ends_with(".view.fast")
    {
        return false;
    }
    match host.parse::<std::net::IpAddr>() {
        Ok(address) => {
            !address.is_loopback()
                && !address.is_unspecified()
                && !address.is_multicast()
                && match address {
                    std::net::IpAddr::V4(value) => {
                        !value.is_private() && !value.is_link_local() && !value.is_broadcast()
                    }
                    std::net::IpAddr::V6(value) => !value.is_unique_local(),
                }
        }
        Err(_) => true,
    }
}

fn proxy_destination_test_allowlisted(destination: &str, allowlist: Option<&str>) -> bool {
    let Some(allowlist) = allowlist.filter(|value| !value.trim().is_empty()) else {
        return false;
    };
    let Some(rest) = destination
        .strip_prefix("https://")
        .or_else(|| destination.strip_prefix("http://"))
    else {
        return false;
    };
    let authority = rest
        .split('/')
        .next()
        .unwrap_or("")
        .rsplit('@')
        .next()
        .unwrap_or("");
    let Some((host, port)) = (if let Some(bracketed) = authority.strip_prefix('[') {
        bracketed.split_once("]:")
    } else {
        authority.rsplit_once(':')
    }) else {
        return false;
    };
    let Ok(port) = port.parse::<u16>() else {
        return false;
    };
    if host.is_empty() || port == 0 {
        return false;
    }
    let target = format!("{}:{port}", host.to_ascii_lowercase());
    allowlist
        .split(',')
        .any(|entry| entry.trim().eq_ignore_ascii_case(&target))
}

// Tests adapted from the donor finalizer's `finalize_site` suite: policy
// rejections are asserted through `validate_finalize_policy` directly.
#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::{file_meta, FinalizeError};
    use serde_json::json;

    fn validate(
        config: Map<String, Value>,
        files: BTreeMap<String, FileMeta>,
        private: BTreeSet<String>,
        body: Value,
    ) -> Result<()> {
        validate_finalize_policy(FinalizePolicyContext {
            config: &config,
            files: &files,
            redirects_exact: &Map::new(),
            redirects_pattern: &[],
            headers_exact: &Map::new(),
            headers_pattern: &[],
            body: &body,
            private: &private,
        })
    }

    fn assert_invalid(result: Result<()>, expected: &str) {
        match result {
            Err(FinalizeError::Invalid { code, .. }) => assert_eq!(code, expected),
            other => panic!("expected {expected}, got {other:?}"),
        }
    }

    #[test]
    fn unsafe_or_oversized_theme_css_is_rejected() {
        for theme_css in [
            "--accent:url(https://example.com/x)".to_string(),
            format!("--accent:{}", "x".repeat(16_384)),
        ] {
            assert_invalid(
                validate(
                    Map::new(),
                    BTreeMap::new(),
                    BTreeSet::new(),
                    json!({"serving":{"theme_css":theme_css}}),
                ),
                "invalid_serving_config",
            );
        }
    }

    #[test]
    fn fallback_must_target_a_public_committed_file() {
        let mut files = BTreeMap::new();
        files.insert("page.md".to_string(), file_meta("page.md", b"# Page", None));
        files.insert(
            "index.html".to_string(),
            file_meta("index.html", b"home", None),
        );
        let private = BTreeSet::from(["page.md".to_string()]);
        for fallback in ["page.md", "sf.jsonc", "missing.html"] {
            let mut config = Map::new();
            config.insert("fallback".into(), json!({"path":fallback,"status":200}));
            assert_invalid(
                validate(
                    config,
                    files.clone(),
                    private.clone(),
                    json!({"serving":{}}),
                ),
                "invalid_serving_config",
            );
        }
        let mut config = Map::new();
        config.insert("fallback".into(), json!({"path":"index.html","status":200}));
        validate(config, files, private, json!({"serving":{}})).unwrap();
    }

    #[test]
    fn platform_managed_headers_and_invalid_operations_are_rejected() {
        let headers_pattern = [json!({
            "operations":[{"kind":"set","name":"Set-Cookie","value":"a=b"}]
        })];
        assert_invalid(
            validate_finalize_policy(FinalizePolicyContext {
                config: &Map::new(),
                files: &BTreeMap::new(),
                redirects_exact: &Map::new(),
                redirects_pattern: &[],
                headers_exact: &Map::new(),
                headers_pattern: &headers_pattern,
                body: &json!({}),
                private: &BTreeSet::new(),
            }),
            "runtime_artifact_validation_failed",
        );
        let headers_pattern = [json!({
            "operations":[{"kind":"set","name":"X-Custom","value":"bad\r\nvalue"}]
        })];
        assert_invalid(
            validate_finalize_policy(FinalizePolicyContext {
                config: &Map::new(),
                files: &BTreeMap::new(),
                redirects_exact: &Map::new(),
                redirects_pattern: &[],
                headers_exact: &Map::new(),
                headers_pattern: &headers_pattern,
                body: &json!({}),
                private: &BTreeSet::new(),
            }),
            "runtime_artifact_validation_failed",
        );
    }

    #[test]
    fn rewrites_and_proxies_cannot_target_private_or_internal_destinations() {
        let redirects_pattern = [json!({"action":"rewrite","destination":"/sf.jsonc"})];
        assert_invalid(
            validate_finalize_policy(FinalizePolicyContext {
                config: &Map::new(),
                files: &BTreeMap::new(),
                redirects_exact: &Map::new(),
                redirects_pattern: &redirects_pattern,
                headers_exact: &Map::new(),
                headers_pattern: &[],
                body: &json!({}),
                private: &BTreeSet::new(),
            }),
            "runtime_artifact_validation_failed",
        );
        for destination in [
            "http://localhost/api",
            "https://api.spacefast.com/internal",
            "http://10.0.0.8/private",
            "ftp://example.com/files",
        ] {
            let redirects_pattern = [json!({"action":"proxy","destination":destination})];
            assert_invalid(
                validate_finalize_policy(FinalizePolicyContext {
                    config: &Map::new(),
                    files: &BTreeMap::new(),
                    redirects_exact: &Map::new(),
                    redirects_pattern: &redirects_pattern,
                    headers_exact: &Map::new(),
                    headers_pattern: &[],
                    body: &json!({}),
                    private: &BTreeSet::new(),
                }),
                "runtime_artifact_validation_failed",
            );
        }
        let redirects_pattern = [json!({
            "action":"proxy",
            "destination":"https://example.com/x",
            "cache":true
        })];
        assert_invalid(
            validate_finalize_policy(FinalizePolicyContext {
                config: &Map::new(),
                files: &BTreeMap::new(),
                redirects_exact: &Map::new(),
                redirects_pattern: &redirects_pattern,
                headers_exact: &Map::new(),
                headers_pattern: &[],
                body: &json!({}),
                private: &BTreeSet::new(),
            }),
            "runtime_artifact_validation_failed",
        );
        let redirects_pattern = [json!({"action":"proxy","destination":"https://example.com/x"})];
        validate_finalize_policy(FinalizePolicyContext {
            config: &Map::new(),
            files: &BTreeMap::new(),
            redirects_exact: &Map::new(),
            redirects_pattern: &redirects_pattern,
            headers_exact: &Map::new(),
            headers_pattern: &[],
            body: &json!({}),
            private: &BTreeSet::new(),
        })
        .unwrap();
    }

    #[test]
    fn proxy_test_allowlist_requires_an_exact_host_and_port() {
        let allowlist = Some("127.0.0.1:4321, ::1:9876");
        assert!(proxy_destination_test_allowlisted(
            "http://127.0.0.1:4321/path",
            allowlist,
        ));
        assert!(proxy_destination_test_allowlisted(
            "http://[::1]:9876/path",
            allowlist,
        ));
        assert!(!proxy_destination_test_allowlisted(
            "http://127.0.0.1:4322/path",
            allowlist,
        ));
        assert!(!proxy_destination_test_allowlisted(
            "https://127.0.0.1:4321/path",
            None,
        ));
    }
}
