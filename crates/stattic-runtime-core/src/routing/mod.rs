//! The `_redirects` / `_headers` convention compiler shared by native and
//! WASM callers: pattern grammar, limits, diagnostics, and the lowering of
//! `Basic-Auth` header operations into unified access plans with bcrypt
//! verifier hashes (plaintext never leaves this module).

use regex::Regex;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

mod headers;
mod redirects;

use headers::compile_headers;
use redirects::compile_redirects;

const REDIRECT_STATUSES: &[u16] = &[200, 301, 302, 303, 307, 308, 404];
const REDIRECT_LINE_LIMIT: usize = 1_000;
const HEADER_LINE_LIMIT: usize = 2_000;
const HEADER_RULE_LIMIT: usize = 100;
const REDIRECT_TOTAL_LIMIT: usize = 2_100;
const REDIRECT_STATIC_LIMIT: usize = 2_000;
const REDIRECT_DYNAMIC_LIMIT: usize = 100;
const BASIC_AUTH_BCRYPT_COST: u32 = 10;
const BASIC_AUTH_SALT_DOMAIN: &[u8] = b"spacefast.headers-basic-auth.v1";

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingInput {
    #[serde(default)]
    pub redirects: String,
    #[serde(default)]
    pub headers: String,
    #[serde(default)]
    pub assigned_hostnames: Vec<String>,
    #[serde(default)]
    pub basic_auth_allowed: Option<bool>,
    /// Stable, version-scoped identity used to derive unique bcrypt salts.
    /// Native finalization supplies `space_id/version_id`; preview callers
    /// that omit it receive a deterministic source-scoped fallback.
    #[serde(default)]
    pub basic_auth_salt_context: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingCompilation {
    pub redirects: Vec<RedirectRule>,
    pub headers: Vec<HeaderRule>,
    pub diagnostics: Vec<RoutingDiagnostic>,
    pub stats: RoutingStats,
    pub basic_auth: Vec<BasicAuthPlan>,
    pub sanitized_headers: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct BasicAuthPlan {
    pub rule: BasicAuthRulePlan,
    pub secret_values: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct BasicAuthRulePlan {
    pub id: String,
    #[serde(rename = "match")]
    pub match_rule: BasicAuthMatchPlan,
    pub effect: &'static str,
    pub auth: BasicAuthRequirementPlan,
    pub reason_code: &'static str,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct BasicAuthMatchPlan {
    pub path_pattern: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub host: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub host_pattern: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub host_template: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct BasicAuthRequirementPlan {
    pub required_grants: Vec<String>,
    pub acquire: Vec<BasicAuthAcquirePlan>,
}

#[derive(Debug, Clone, Serialize)]
pub struct BasicAuthAcquirePlan {
    #[serde(rename = "type")]
    pub acquire_type: &'static str,
    #[serde(rename = "ref")]
    pub secret_ref: String,
    pub transport: &'static str,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub username: String,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingStats {
    pub redirect_rule_count: usize,
    pub header_rule_count: usize,
    pub static_redirect_count: usize,
    pub dynamic_redirect_count: usize,
    pub basic_auth_rule_count: usize,
    pub proxy_rule_count: usize,
}

#[derive(Debug, Clone, Serialize)]
pub struct RoutingDiagnostic {
    pub file: &'static str,
    pub line: usize,
    pub severity: &'static str,
    pub code: &'static str,
    pub message: String,
    pub source: String,
    #[serde(rename = "deferredUntilSubstitution", skip_serializing_if = "is_false")]
    pub deferred_until_substitution: bool,
}

fn is_false(value: &bool) -> bool {
    !value
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RedirectRule {
    pub source: String,
    pub destination: String,
    pub action: &'static str,
    pub status: u16,
    #[serde(rename = "match")]
    pub match_kind: &'static str,
    pub regex: Option<String>,
    pub host: Option<String>,
    pub host_regex: Option<String>,
    pub force: bool,
    pub query: Option<BTreeMap<String, String>>,
    pub conditions: Vec<RedirectCondition>,
}

#[derive(Debug, Clone, Serialize)]
pub struct RedirectCondition {
    pub kind: String,
    pub values: Vec<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct HeaderRule {
    pub path: String,
    pub host: Option<String>,
    pub regex: Option<String>,
    pub host_regex: Option<String>,
    pub operations: Vec<HeaderOperation>,
    pub headers: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct HeaderOperation {
    pub kind: &'static str,
    pub name: String,
    pub value: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub line: Option<usize>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub source: Option<String>,
}

#[derive(Debug)]
struct PatternCompilation {
    error: Option<String>,
    regex: Option<String>,
    match_kind: &'static str,
}

#[derive(Debug)]
struct AbsoluteUrl {
    host: String,
    path: String,
    port: String,
}

#[derive(Debug)]
struct SourceMatcher {
    path: String,
    host: Option<String>,
    host_regex: Option<String>,
}

pub fn compile_routing_files(input: &RoutingInput) -> RoutingCompilation {
    let mut diagnostics = Vec::new();
    let redirects = compile_redirects(input, &mut diagnostics);
    let raw_headers = compile_headers(&input.headers, &mut diagnostics);
    add_limit_diagnostics(&redirects, &raw_headers, &mut diagnostics);
    let static_redirect_count = redirects
        .iter()
        .filter(|rule| is_static_redirect(rule))
        .count();
    let basic_auth_rule_count = raw_headers
        .iter()
        .filter(|rule| rule.operations.iter().any(|op| op.kind == "basicAuth"))
        .count();
    let salt_context = input
        .basic_auth_salt_context
        .clone()
        .unwrap_or_else(|| fallback_basic_auth_salt_context(input));
    let (headers, mut basic_auth) = lower_basic_auth(raw_headers, &salt_context);
    if input.basic_auth_allowed == Some(false) && !basic_auth.is_empty() {
        let count = basic_auth.len();
        basic_auth.clear();
        diagnostics.push(RoutingDiagnostic {
            file: "_headers",
            line: 0,
            severity: "warning",
            code: "free_headers_basic_auth_disabled",
            message: "Basic-Auth from _headers requires a paid plan; the Basic-Auth entries were not applied. Custom response headers still apply.".into(),
            source: format!("{count} dropped Basic-Auth operation(s)"),
            deferred_until_substitution: false,
        });
    }
    let sanitized_headers = render_sanitized_headers(&headers);
    RoutingCompilation {
        stats: RoutingStats {
            redirect_rule_count: redirects.len(),
            header_rule_count: headers.len(),
            static_redirect_count,
            dynamic_redirect_count: redirects.len() - static_redirect_count,
            basic_auth_rule_count,
            proxy_rule_count: redirects
                .iter()
                .filter(|rule| rule.action == "proxy")
                .count(),
        },
        redirects,
        headers,
        basic_auth,
        sanitized_headers,
        diagnostics,
    }
}

fn render_sanitized_headers(headers: &[HeaderRule]) -> Option<String> {
    let mut lines = Vec::new();
    for rule in headers.iter().filter(|rule| !rule.operations.is_empty()) {
        lines.push(match &rule.host {
            Some(host) => format!("https://{host}{}", rule.path),
            None => rule.path.clone(),
        });
        for operation in &rule.operations {
            match operation.kind {
                "set" => lines.push(format!(
                    "  {}: {}",
                    operation.name,
                    operation.value.as_deref().unwrap_or_default()
                )),
                "remove" => lines.push(format!("  ! {}", operation.name)),
                _ => {}
            }
        }
    }
    (!lines.is_empty()).then(|| format!("{}\n", lines.join("\n")))
}

fn lower_basic_auth(
    headers: Vec<HeaderRule>,
    salt_context: &str,
) -> (Vec<HeaderRule>, Vec<BasicAuthPlan>) {
    let mut sanitized = Vec::with_capacity(headers.len());
    let mut plans = Vec::new();
    for mut rule in headers {
        let operations = std::mem::take(&mut rule.operations);
        let mut retained = Vec::new();
        for operation in operations {
            if operation.kind != "basicAuth" {
                retained.push(operation);
                continue;
            }
            let credentials = operation
                .value
                .as_deref()
                .unwrap_or_default()
                .split_whitespace()
                .filter_map(|credential| credential.split_once(':'))
                .map(|(username, password)| (username.to_owned(), password.to_owned()))
                .collect::<Vec<_>>();
            if credentials.is_empty() {
                continue;
            }
            let (host, host_pattern, host_template) = match (&rule.host, &rule.host_regex) {
                (None, _) => (None, None, None),
                (Some(host), None) => (Some(host.clone()), None, None),
                (Some(host), Some(_)) if host.contains(':') => (None, None, Some(host.clone())),
                (Some(host), Some(_)) => (None, Some(host.clone()), None),
            };
            let rule_id = format!("headers-basic-auth-{}", plans.len() + 1);
            let mut secret_values = BTreeMap::new();
            let mut acquire = Vec::with_capacity(credentials.len());
            for (index, (username, password)) in credentials.into_iter().enumerate() {
                let secret_name = if index == 0 && operation_credential_count(&operation) == 1 {
                    rule_id.clone()
                } else {
                    format!("{rule_id}-{}", index + 1)
                };
                let salt = basic_auth_salt(salt_context, &rule_id, &secret_name);
                let hash = bcrypt::hash_with_salt(&password, BASIC_AUTH_BCRYPT_COST, salt)
                    .expect("validated Basic-Auth password hashes")
                    .format_for_version(bcrypt::Version::TwoB);
                secret_values.insert(secret_name.clone(), hash);
                acquire.push(BasicAuthAcquirePlan {
                    acquire_type: "password",
                    secret_ref: format!("secret:{secret_name}"),
                    transport: "basic",
                    username,
                });
            }
            plans.push(BasicAuthPlan {
                rule: BasicAuthRulePlan {
                    id: rule_id.clone(),
                    match_rule: BasicAuthMatchPlan {
                        path_pattern: rule
                            .path
                            .split('/')
                            .map(|segment| {
                                if segment.starts_with(':') {
                                    "*".into()
                                } else {
                                    segment.replace('*', "**")
                                }
                            })
                            .collect::<Vec<String>>()
                            .join("/"),
                        host,
                        host_pattern,
                        host_template,
                    },
                    effect: "challenge",
                    auth: BasicAuthRequirementPlan {
                        required_grants: vec![format!("pw:{rule_id}")],
                        acquire,
                    },
                    reason_code: "headers_basic_auth",
                },
                secret_values,
            });
        }
        rule.operations = retained;
        sanitized.push(rule);
    }
    (sanitized, plans)
}

fn fallback_basic_auth_salt_context(input: &RoutingInput) -> String {
    let mut digest = Sha256::new();
    digest.update(BASIC_AUTH_SALT_DOMAIN);
    digest.update([0]);
    digest.update(input.redirects.as_bytes());
    digest.update([0]);
    digest.update(input.headers.as_bytes());
    for hostname in &input.assigned_hostnames {
        digest.update([0]);
        digest.update(hostname.as_bytes());
    }
    format!("preview:{:x}", digest.finalize())
}

fn basic_auth_salt(context: &str, rule_id: &str, secret_name: &str) -> [u8; 16] {
    let mut digest = Sha256::new();
    digest.update(BASIC_AUTH_SALT_DOMAIN);
    for value in [context, rule_id, secret_name] {
        digest.update([0]);
        digest.update(value.as_bytes());
    }
    let digest = digest.finalize();
    let mut salt = [0_u8; 16];
    salt.copy_from_slice(&digest[..16]);
    salt
}

fn operation_credential_count(operation: &HeaderOperation) -> usize {
    operation
        .value
        .as_deref()
        .unwrap_or_default()
        .split_whitespace()
        .filter(|credential| credential.contains(':'))
        .count()
}

fn compile_pattern(
    pattern: &str,
    delimiter: char,
    allow_wildcard_prefix: bool,
    optional_trailing_slash: bool,
) -> PatternCompilation {
    for segment in pattern.split(delimiter) {
        if segment.contains('*')
            && segment != "*"
            && !segment.ends_with('*')
            && !(allow_wildcard_prefix && segment.starts_with('*'))
        {
            return pattern_error("Wildcards must appear at the end of a path segment.");
        }
        if segment.contains('*') && segment.contains(':') {
            return pattern_error("Wildcards and placeholders cannot be used in the same segment.");
        }
    }
    let mut seen_splat = false;
    let mut names = BTreeSet::new();
    let mut output = String::from("^");
    let chars: Vec<char> = pattern.chars().collect();
    let mut index = 0;
    while index < chars.len() {
        match chars[index] {
            '*' => {
                if seen_splat {
                    return pattern_error("Only one wildcard is supported.");
                }
                seen_splat = true;
                output.push_str("(?P<splat>.*)");
                index += 1;
            }
            ':' => {
                let start = index + 1;
                let mut end = start;
                while end < chars.len() && (chars[end].is_ascii_alphanumeric() || chars[end] == '_')
                {
                    end += 1;
                }
                let name: String = chars[start..end].iter().collect();
                if name.is_empty() || !name.starts_with(|ch: char| ch.is_ascii_alphabetic()) {
                    return pattern_error("Placeholders must start with a letter.");
                }
                if !names.insert(name.clone()) {
                    return pattern_error(&format!(
                        "Placeholder \":{name}\" can only be captured once."
                    ));
                }
                output.push_str(&format!(
                    "(?P<{name}>{})",
                    if delimiter == '/' { "[^/]+" } else { "[^.]+" }
                ));
                index = end;
            }
            ch => {
                output.push_str(&escape_regex_literal(&ch.to_string()));
                index += 1;
            }
        }
    }
    if !seen_splat && names.is_empty() {
        return PatternCompilation {
            error: None,
            regex: None,
            match_kind: "exact",
        };
    }
    if optional_trailing_slash {
        output.push_str("/?");
    }
    output.push('$');
    PatternCompilation {
        error: None,
        regex: Some(output),
        match_kind: if seen_splat && names.is_empty() {
            "splat"
        } else {
            "pattern"
        },
    }
}

fn pattern_error(message: &str) -> PatternCompilation {
    PatternCompilation {
        error: Some(message.to_string()),
        regex: None,
        match_kind: "pattern",
    }
}

fn parse_absolute_url(value: &str) -> Option<AbsoluteUrl> {
    let captures = absolute_parse_regex().captures(value)?;
    let authority = captures.get(2)?.as_str();
    if authority.contains('@') {
        return None;
    }
    let port_capture = Regex::new(r":(\d+)$")
        .expect("port regex")
        .captures(authority);
    let port = port_capture
        .as_ref()
        .and_then(|capture| capture.get(1))
        .map(|value| value.as_str())
        .unwrap_or("");
    let host = port_capture
        .as_ref()
        .and_then(|capture| capture.get(0))
        .map(|value| &authority[..authority.len() - value.as_str().len()])
        .unwrap_or(authority)
        .trim_end_matches('.');
    if host.is_empty() {
        return None;
    }
    Some(AbsoluteUrl {
        host: host.to_string(),
        path: captures
            .get(3)
            .map(|value| value.as_str())
            .filter(|value| !value.is_empty())
            .unwrap_or("/")
            .to_string(),
        port: port.to_string(),
    })
}

fn pattern_matches_value(pattern: &str, delimiter: char, value: &str) -> bool {
    let compiled = compile_pattern(pattern, delimiter, false, false);
    compiled
        .regex
        .as_deref()
        .map_or(pattern == value, |source| {
            Regex::new(source).is_ok_and(|regex| regex.is_match(value))
        })
}

fn strip_trailing_slash(value: &str) -> String {
    if value == "/" {
        "/".into()
    } else {
        value.trim_end_matches('/').to_string()
    }
}
fn normalize_hostname(value: &str) -> String {
    value.trim().trim_end_matches('.').to_ascii_lowercase()
}
pub(crate) fn escape_regex_literal(value: &str) -> String {
    value
        .chars()
        .flat_map(|ch| {
            if matches!(
                ch,
                '|' | '\\' | '{' | '}' | '(' | ')' | '[' | ']' | '^' | '$' | '+' | '?' | '.'
            ) {
                vec!['\\', ch]
            } else {
                vec![ch]
            }
        })
        .collect()
}
fn has_host_pattern(value: &str) -> bool {
    value.contains('*') || value.contains(':')
}
fn is_static_redirect(rule: &RedirectRule) -> bool {
    rule.match_kind == "exact" && rule.host.is_none()
}
fn canonical_header_name(value: &str) -> String {
    value
        .trim()
        .split('-')
        .map(|part| {
            let mut chars = part.chars();
            chars
                .next()
                .map(|first| {
                    first.to_uppercase().collect::<String>() + &chars.as_str().to_ascii_lowercase()
                })
                .unwrap_or_default()
        })
        .collect::<Vec<_>>()
        .join("-")
}

fn add_limit_diagnostics(
    redirects: &[RedirectRule],
    headers: &[HeaderRule],
    diagnostics: &mut Vec<RoutingDiagnostic>,
) {
    let static_count = redirects
        .iter()
        .filter(|rule| is_static_redirect(rule))
        .count();
    let dynamic_count = redirects.len() - static_count;
    if redirects.len() > REDIRECT_TOTAL_LIMIT {
        diagnostic(
            diagnostics,
            "_redirects",
            0,
            "error",
            "redirect_limit_exceeded",
            "Use 2100 or fewer redirect rules.",
            "",
        );
    }
    if static_count > REDIRECT_STATIC_LIMIT {
        diagnostic(
            diagnostics,
            "_redirects",
            0,
            "error",
            "redirect_static_limit_exceeded",
            "Use 2000 or fewer static redirect rules.",
            "",
        );
    }
    if dynamic_count > REDIRECT_DYNAMIC_LIMIT {
        diagnostic(
            diagnostics,
            "_redirects",
            0,
            "error",
            "redirect_dynamic_limit_exceeded",
            "Use 100 or fewer dynamic redirect rules.",
            "",
        );
    }
    if headers.len() > HEADER_RULE_LIMIT {
        diagnostic(
            diagnostics,
            "_headers",
            0,
            "error",
            "header_limit_exceeded",
            "Use 100 or fewer header rules.",
            "",
        );
    }
}

fn diagnostic(
    diagnostics: &mut Vec<RoutingDiagnostic>,
    file: &'static str,
    line: usize,
    severity: &'static str,
    code: &'static str,
    message: &str,
    source: &str,
) {
    diagnostics.push(RoutingDiagnostic {
        file,
        line,
        severity,
        code,
        message: message.to_string(),
        source: source.to_string(),
        deferred_until_substitution: file == "_redirects"
            && matches!(
                code,
                "redirect_rewrite_destination_invalid" | "redirect_condition_invalid"
            )
            && server_resolved_variable_redirect_regex().is_match(source),
    });
}

fn server_resolved_variable_redirect_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| {
        Regex::new(
            r"(?i)^\s*\S+\s+(?:[A-Za-z][A-Za-z0-9_-]*=:[A-Za-z][A-Za-z0-9_]*\s+)*\{\{\s*vars\.[A-Za-z_][A-Za-z0-9_]*\s*\}\}(?:[/?#][^\s]*)?\s+(?:200|404)!?(?:\s+(?:Country=[A-Za-z]{2}(?:,[A-Za-z]{2})*|Language=[^=\s,]+(?:,[^=\s,]+)*|Cookie=[^=\s,]+(?:,[^=\s,]+)*|Agent=(?:1|true|yes)(?:,(?:1|true|yes))*))*\s*$",
        )
        .expect("server-resolved redirect regex compiles")
    })
}

fn absolute_url_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"(?i)^https?://").unwrap())
}
fn absolute_parse_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| {
        Regex::new(r"(?i)^(https?)://([^/?#]+)([^?#]*)(?:\?[^#]*)?(?:#.*)?$").unwrap()
    })
}
fn query_token_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^[A-Za-z][A-Za-z0-9_-]*=:[A-Za-z][A-Za-z0-9_]*$").unwrap())
}
fn status_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^(\d{3})(!)?$").unwrap())
}
fn basic_auth_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^[^:\s]+:[^\s]+$").unwrap())
}
fn header_name_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^[A-Za-z0-9-]+$").unwrap())
}
fn cdn_headers() -> &'static BTreeSet<&'static str> {
    static CELL: OnceLock<BTreeSet<&'static str>> = OnceLock::new();
    CELL.get_or_init(|| {
        [
            "cdn-cache-control",
            "cloudflare-cdn-cache-control",
            "netlify-cdn-cache-control",
            "surrogate-control",
        ]
        .into_iter()
        .collect()
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn compiles_full_redirect_and_header_shapes() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/old /new 301\n/docs/:slug /page/:slug 200! Country=NL Agent=true".into(),
            headers: "/docs/*\n  X-Test: yes\n  !X-Remove".into(),
            assigned_hostnames: Vec::new(),
            basic_auth_allowed: None,
            basic_auth_salt_context: None,
        });
        assert_eq!(result.redirects.len(), 2);
        assert_eq!(result.redirects[1].match_kind, "pattern");
        assert_eq!(result.redirects[1].conditions.len(), 2);
        assert_eq!(result.headers[0].operations.len(), 2);
        assert!(result.diagnostics.is_empty());
    }

    #[test]
    fn exact_header_regex_preserves_the_existing_wire_format() {
        let result = compile_routing_files(&RoutingInput {
            redirects: String::new(),
            headers: "/exact-path\n  X-Test: yes".into(),
            assigned_hostnames: Vec::new(),
            basic_auth_allowed: None,
            basic_auth_salt_context: None,
        });

        assert_eq!(result.headers.len(), 1);
        assert_eq!(result.headers[0].path, "/exact-path");
        assert_eq!(result.headers[0].regex.as_deref(), Some("^/exact-path$"));
        assert_eq!(result.headers[0].operations[0].line, Some(2));
        assert_eq!(
            result.headers[0].operations[0].source.as_deref(),
            Some("X-Test: yes")
        );
    }

    #[test]
    fn classifies_only_complete_variable_redirects_as_deferred() {
        let valid = compile_routing_files(&RoutingInput {
            redirects: "/api/* id=:id {{ vars.API_HOST }}/:id 200! Country=NL Agent=true".into(),
            ..RoutingInput::default()
        });
        assert!(valid.diagnostics.iter().any(|item| {
            item.deferred_until_substitution
                && matches!(
                    item.code,
                    "redirect_rewrite_destination_invalid" | "redirect_condition_invalid"
                )
        }));

        for decoy in [
            "/api/* {{vars.API_HOST}}bad 200",
            "/api/* id=id {{vars.API_HOST}}/:id 200",
            "/api/* {{vars.API_HOST}}/:splat 200 Role=admin",
            "/api/* {{vars.API_HOST}} 200 # comment",
        ] {
            let result = compile_routing_files(&RoutingInput {
                redirects: decoy.into(),
                ..RoutingInput::default()
            });
            assert!(result
                .diagnostics
                .iter()
                .all(|item| !item.deferred_until_substitution));
        }
    }

    #[test]
    fn basic_auth_diagnostics_are_redacted_and_entitlement_policy_is_rust_owned() {
        let allowed = compile_routing_files(&RoutingInput {
            headers: "/private\n  Basic-Auth: alice:supersecret".into(),
            basic_auth_allowed: Some(true),
            ..RoutingInput::default()
        });
        assert_eq!(allowed.basic_auth.len(), 1);
        assert!(allowed
            .diagnostics
            .iter()
            .filter(|item| item.code.starts_with("header_basic_auth_"))
            .all(|item| item.source == "[redacted]"));

        let denied = compile_routing_files(&RoutingInput {
            headers: "/private\n  Basic-Auth: alice:supersecret".into(),
            basic_auth_allowed: Some(false),
            ..RoutingInput::default()
        });
        assert!(denied.basic_auth.is_empty());
        assert!(denied.diagnostics.iter().any(|item| {
            item.code == "free_headers_basic_auth_disabled"
                && item.source == "1 dropped Basic-Auth operation(s)"
        }));
        assert!(denied
            .diagnostics
            .iter()
            .all(|item| !item.source.contains("supersecret")));
    }

    #[test]
    fn basic_auth_hashes_are_deterministic_per_version_and_hide_plaintext() {
        let compile = |context: &str| {
            compile_routing_files(&RoutingInput {
                headers: "/*\n  Basic-Auth: alice:supersecret".into(),
                basic_auth_salt_context: Some(context.into()),
                ..RoutingInput::default()
            })
        };
        let first = compile("space-a/version-1");
        let repeated = compile("space-a/version-1");
        let next_version = compile("space-a/version-2");
        let hash = first.basic_auth[0].secret_values["headers-basic-auth-1"].clone();
        assert_eq!(
            repeated.basic_auth[0].secret_values["headers-basic-auth-1"],
            hash
        );
        assert_ne!(
            next_version.basic_auth[0].secret_values["headers-basic-auth-1"],
            hash
        );
        assert!(bcrypt::verify("supersecret", &hash).unwrap());
        assert!(!serde_json::to_string(&first.basic_auth)
            .unwrap()
            .contains("supersecret"));
    }
}
