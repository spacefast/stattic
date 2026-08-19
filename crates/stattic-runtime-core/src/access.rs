//! Convention-file compilation into runtime redirect and response-header
//! buckets. Authorization is configured through Spacefast sharing, never
//! through uploaded convention files.

use serde::Serialize;
use serde_json::{json, Map, Value};

use crate::csp::{merge_platform_csp_value, PlatformCspSources};
use crate::finalize::Result;
use crate::protocol::{PLATFORM_OWNED_HEADERS, PLATFORM_OWNED_HEADER_PREFIXES};
use crate::routing::{compile_routing_files, HeaderRule, RoutingInput};
use crate::transforms::{lower_runtime_conventions, RuntimeConventionsInput};

const CSP_HEADER_NAME: &str = "content-security-policy";

#[derive(Default)]
pub struct CompiledConventions {
    pub redirects_exact: Option<Map<String, Value>>,
    pub redirects_pattern: Option<Vec<Value>>,
    pub headers_exact: Option<Map<String, Value>>,
    pub headers_pattern: Option<Vec<Value>>,
    pub metadata_convention_files: Option<Value>,
    pub routing: ConventionRoutingSummary,
}

/// What the version's routing compiled TO, counted before the rules are lowered
/// into serving buckets. The control plane stores these counts on the version
/// row and overlays its plan on `proxy_rules` — the compiled artifact itself
/// stays plan-agnostic, so this is a report, never a verdict.
#[derive(Debug, Clone, Default, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ConventionRoutingSummary {
    pub redirect_rule_count: usize,
    pub header_rule_count: usize,
    pub proxy_rule_count: usize,
    pub proxy_rules: Vec<ProxyRuleSummary>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ProxyRuleSummary {
    pub source: String,
    pub destination: String,
}

/// Everything one conventions compile needs beyond the staged bytes it reads
/// for itself.
#[derive(Default)]
pub struct ConventionCompileInput {
    /// This space's hostnames, which tell an internal redirect from an external
    /// proxy. Only the control plane knows the assignment.
    pub assigned_hostnames: Vec<String>,
    /// Origins the platform's own browser code must be allowed to reach,
    /// resolved and audience-checked by the control plane. Merged into every
    /// CSP this version sets, from `_headers` and config rules alike — a policy
    /// that works in one grammar and blocks the platform overlay in the other
    /// is a trap, not a feature.
    pub platform_csp_sources: PlatformCspSources,
}

pub fn compile_conventions(
    raw: &Value,
    config_source: Option<String>,
    config_path: Option<String>,
    input: &ConventionCompileInput,
    diagnostics: &mut Vec<Value>,
) -> Result<CompiledConventions> {
    let Some(raw) = raw.as_object() else {
        return Ok(CompiledConventions::default());
    };
    let has_redirects = raw.get("redirects").and_then(Value::as_str).is_some();
    let has_headers = raw.get("headers").and_then(Value::as_str).is_some();
    if !has_redirects && !has_headers && config_source.is_none() {
        return Ok(CompiledConventions::default());
    }
    let mut compilation = compile_routing_files(&RoutingInput {
        redirects: raw
            .get("redirects")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string(),
        headers: raw
            .get("headers")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string(),
        assigned_hostnames: input.assigned_hostnames.clone(),
        config_source,
        config_path,
    });
    merge_platform_csp_sources(&mut compilation.headers, &input.platform_csp_sources);
    // `has_headers` stays the FILE flag: the sanitized text written back below
    // is the `_headers` file, and config rules are not part of it.
    let serves_redirects = has_redirects || !compilation.redirects.is_empty();
    let serves_headers = has_headers || !compilation.headers.is_empty();
    diagnostics.extend(
        compilation
            .diagnostics
            .iter()
            .filter_map(|item| serde_json::to_value(item).ok()),
    );
    let mut metadata_convention_files = raw.clone();
    if has_headers {
        metadata_convention_files.insert(
            "headers".into(),
            Value::String(compilation.sanitized_headers.clone().unwrap_or_default()),
        );
    }
    let lowered = lower_runtime_conventions(RuntimeConventionsInput {
        redirects: compilation
            .redirects
            .iter()
            .filter_map(|rule| serde_json::to_value(rule).ok())
            .collect(),
        headers: compilation
            .headers
            .iter()
            .filter_map(|rule| serde_json::to_value(rule).ok())
            .collect(),
    });
    Ok(CompiledConventions {
        redirects_exact: serves_redirects.then_some(lowered.redirects_exact),
        redirects_pattern: serves_redirects.then_some(lowered.redirects_pattern),
        headers_exact: serves_headers.then_some(lowered.headers_exact),
        headers_pattern: serves_headers.then_some(lowered.headers_pattern),
        metadata_convention_files: Some(Value::Object(metadata_convention_files)),
        routing: ConventionRoutingSummary {
            redirect_rule_count: compilation.stats.redirect_rule_count,
            header_rule_count: compilation.stats.header_rule_count,
            proxy_rule_count: compilation.stats.proxy_rule_count,
            proxy_rules: compilation
                .redirects
                .iter()
                .filter(|rule| rule.action == "proxy")
                .map(|rule| ProxyRuleSummary {
                    source: rule.source.clone(),
                    destination: rule.destination.clone(),
                })
                .collect(),
        },
    })
}

/// Adds the platform's sources to every CSP this version sets, before the rules
/// are lowered into the buckets the origin serves from. Nothing is created
/// where the publisher set no policy: an absent CSP is already permissive.
fn merge_platform_csp_sources(rules: &mut [HeaderRule], sources: &PlatformCspSources) {
    if sources.is_empty() {
        return;
    }
    for rule in rules {
        for operation in &mut rule.operations {
            if operation.kind != "set" || !operation.name.eq_ignore_ascii_case(CSP_HEADER_NAME) {
                continue;
            }
            let Some(value) = operation.value.as_ref() else {
                continue;
            };
            let merged = merge_platform_csp_value(value, sources);
            rule.headers.insert(operation.name.clone(), merged.clone());
            operation.value = Some(merged);
        }
    }
}

pub fn retain_response_header_operations(exact: &mut Map<String, Value>, pattern: &mut Vec<Value>) {
    exact.retain(|_, bucket| {
        let Some(rules) = bucket.as_array_mut() else {
            return false;
        };
        rules.retain_mut(retain_response_headers_in_rule);
        !rules.is_empty()
    });
    pattern.retain_mut(retain_response_headers_in_rule);
}

/// A response header only the platform may set (`A8C-*`, `x-ac`, `x-sc`,
/// `x-nc`). Setting one from `_headers` would let a publisher opt private bytes
/// into the shared edge cache, or forge the edge's own cache verdict; removing
/// one would let them opt out of a purge-backed policy. Neither is theirs.
fn platform_owned_header(name: &str) -> bool {
    let name = name.trim().to_ascii_lowercase();
    PLATFORM_OWNED_HEADER_PREFIXES
        .iter()
        .any(|prefix| name.starts_with(prefix))
        || PLATFORM_OWNED_HEADERS.contains(&name.as_str())
}

fn retain_response_headers_in_rule(rule: &mut Value) -> bool {
    let Some(rule) = rule.as_object_mut() else {
        return false;
    };
    if let Some(operations) = rule.get_mut("operations").and_then(Value::as_array_mut) {
        operations.retain(|operation| {
            if operation
                .get("name")
                .and_then(Value::as_str)
                .is_some_and(platform_owned_header)
            {
                return false;
            }
            matches!(
                operation.get("kind").and_then(Value::as_str),
                Some("set" | "remove")
            )
        });
    }
    if let Some(headers) = rule.get_mut("headers").and_then(Value::as_object_mut) {
        headers.retain(|name, _| {
            !name.eq_ignore_ascii_case("basic-auth") && !platform_owned_header(name)
        });
    }
    rule.get("operations")
        .and_then(Value::as_array)
        .is_some_and(|operations| !operations.is_empty())
        || rule
            .get("headers")
            .and_then(Value::as_object)
            .is_some_and(|headers| !headers.is_empty())
}

/// Whether a lowered rule field carries anything at all.
pub(crate) fn rule_value_is_set(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::Bool(value)) => *value,
        Some(Value::Number(value)) => value.as_i64() != Some(0),
        Some(Value::String(value)) => !value.is_empty(),
        Some(Value::Array(value)) => !value.is_empty(),
        Some(Value::Object(value)) => !value.is_empty(),
    }
}

/// Whether a lowered redirect rule can decline a request that its path matcher
/// claims — a host qualifier, a query requirement or a condition. Such a rule
/// can never be answered at compile time, in either the response-table compiler
/// or the readiness projection.
pub(crate) fn rule_is_request_dependent(rule: &Value) -> bool {
    rule_value_is_set(rule.get("hostRegex"))
        || rule_value_is_set(rule.get("host"))
        || rule_value_is_set(rule.get("query"))
        || rule_value_is_set(rule.get("conditions"))
}

pub(crate) fn bucket_pattern_rules(rules: &[Value], field: &str) -> Value {
    if rules.is_empty() {
        return Value::Array(Vec::new());
    }
    let mut fallback = Vec::new();
    let mut by_first_segment = Map::new();
    for rule in rules {
        let segment = rule
            .get(field)
            .and_then(Value::as_str)
            .filter(|path| path.starts_with('/'))
            .and_then(|path| path.trim_start_matches('/').split('/').next())
            .filter(|segment| !segment.is_empty() && !segment.contains([':', '*']));
        if let Some(segment) = segment {
            by_first_segment
                .entry(segment)
                .or_insert_with(|| Value::Array(Vec::new()))
                .as_array_mut()
                .expect("pattern bucket")
                .push(rule.clone());
        } else {
            fallback.push(rule.clone());
        }
    }
    json!({"fallback": fallback, "by_first_segment": by_first_segment})
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn non_response_operations_never_enter_response_header_artifacts() {
        let mut exact = Map::from_iter([(
            "/protected".into(),
            json!([{
                "path": "/protected",
                "operations": [
                    {"kind": "basicAuth", "name": "Basic-Auth", "value": "secret"},
                    {"kind": "set", "name": "x-safe", "value": "yes"}
                ],
                "headers": {"Basic-Auth": "", "x-safe": "yes"}
            }]),
        )]);

        retain_response_header_operations(&mut exact, &mut Vec::new());

        let rule = &exact["/protected"][0];
        assert_eq!(rule["operations"].as_array().map(Vec::len), Some(1));
        assert_eq!(rule["operations"][0]["kind"], "set");
        assert!(rule["headers"].get("Basic-Auth").is_none());
        assert_eq!(rule["headers"]["x-safe"], "yes");
    }

    #[test]
    fn authorization_directives_are_rejected_while_response_headers_compile() {
        let mut diagnostics = Vec::new();
        let compiled = compile_conventions(
            &json!({"headers":"/private\n  Basic-Auth: user:pass\n  X-Frame-Options: DENY"}),
            None,
            None,
            &ConventionCompileInput::default(),
            &mut diagnostics,
        )
        .unwrap();
        let serialized = serde_json::to_string(&compiled.metadata_convention_files).unwrap();
        assert!(!serialized.contains("user:pass"));
        assert!(serialized.contains("X-Frame-Options"));
        assert!(diagnostics.iter().any(|diagnostic| {
            diagnostic.get("code").and_then(Value::as_str) == Some("header_basic_auth_unsupported")
        }));
    }

    /// One policy pass over both grammars: a CSP written in `_headers` and a CSP
    /// written as a config rule both end up able to reach the platform origins,
    /// and a proxy rule carries the entitlement key the origin checks at serve
    /// time no matter which grammar declared it.
    #[test]
    fn platform_sources_and_plan_gating_reach_both_routing_grammars() {
        let mut diagnostics = Vec::new();
        let compiled = compile_conventions(
            &json!({
                "redirects": "/file-api https://upstream.test/ 200",
                "headers": "/file\n  Content-Security-Policy: default-src 'self'"
            }),
            Some(
                r#"{
                  "version": 1,
                  "rewrites": [
                    { "source": "/config-api", "destination": "https://other.test/" }
                  ],
                  "headers": [
                    {
                      "source": "/config",
                      "headers": [
                        { "key": "Content-Security-Policy", "value": "default-src 'self'" }
                      ]
                    }
                  ]
                }"#
                .into(),
            ),
            Some("sf.jsonc".into()),
            &ConventionCompileInput {
                assigned_hostnames: Vec::new(),
                platform_csp_sources: PlatformCspSources::from([(
                    "connect-src".to_string(),
                    vec!["https://api.spacefast.test".to_string()],
                )]),
            },
            &mut diagnostics,
        )
        .unwrap();

        let headers = compiled.headers_exact.expect("header rules");
        for path in ["/file", "/config"] {
            let policy = headers[path][0]["headers"]["Content-Security-Policy"]
                .as_str()
                .expect("a set policy");
            assert_eq!(
                policy, "default-src 'self'; connect-src 'self' https://api.spacefast.test",
                "{path} keeps its own policy and gains the platform source"
            );
        }
        let redirects = serde_json::to_string(&compiled.redirects_exact).unwrap();
        assert_eq!(
            redirects.matches(r#""planGated":"external_proxy""#).count(),
            2,
            "both proxies are gated at serve time, not at compile time: {redirects}"
        );
    }
}
