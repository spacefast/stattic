use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, BTreeSet};

use crate::routing::escape_regex_literal;

const CSP_HEADER_NAME: &str = "content-security-policy";
const CSP_PLATFORM_DIRECTIVES: [&str; 4] =
    ["script-src", "script-src-elem", "connect-src", "img-src"];

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PreviewLoaderCspInput {
    pub headers: String,
    pub origins: PreviewLoaderCspOrigins,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PreviewLoaderCspOrigins {
    pub api_origin: String,
    #[serde(default)]
    pub cast_origins: Vec<String>,
    #[serde(default)]
    pub screenshot_origins: Vec<String>,
}

#[derive(Debug, Deserialize)]
pub struct RuntimeConventionsInput {
    #[serde(default)]
    pub redirects: Vec<Value>,
    #[serde(default)]
    pub headers: Vec<Value>,
}

#[derive(Debug, Default, Serialize)]
pub struct RuntimeConventions {
    pub headers_exact: Map<String, Value>,
    pub headers_pattern: Vec<Value>,
    pub redirects_exact: Map<String, Value>,
    pub redirects_pattern: Vec<Value>,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingPlanInput {
    #[serde(default)]
    pub redirects: Vec<Value>,
    #[serde(default)]
    pub headers: Vec<Value>,
    pub policy: RoutingPlanPolicy,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingPlanPolicy {
    pub external_proxy: bool,
    pub routing_rules: Value,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingPlanOutput {
    pub redirects: Vec<Value>,
    pub pooled_rule_count: usize,
    pub diagnostics: Vec<Value>,
}

pub fn merge_preview_loader_csp(input: PreviewLoaderCspInput) -> String {
    let mut merged_any = false;
    let lines = input
        .headers
        .split('\n')
        .map(|raw_line| {
            let line = raw_line.strip_suffix('\r').unwrap_or(raw_line);
            let trimmed = line.trim();
            if trimmed.is_empty() || trimmed.starts_with('#') || !line.starts_with([' ', '\t']) {
                return line.to_string();
            }
            let Some(separator) = trimmed.find(':') else {
                return line.to_string();
            };
            let name = trimmed[..separator].trim();
            if !name.eq_ignore_ascii_case(CSP_HEADER_NAME) {
                return line.to_string();
            }
            let indent_len = line.len() - line.trim_start().len();
            let value = trimmed[separator + 1..].trim();
            merged_any = true;
            format!(
                "{}{}: {}",
                &line[..indent_len],
                name,
                merge_csp_value(value, &input.origins)
            )
        })
        .collect::<Vec<_>>();
    if merged_any {
        lines.join("\n")
    } else {
        input.headers
    }
}

pub fn lower_runtime_conventions(input: RuntimeConventionsInput) -> RuntimeConventions {
    let mut output = RuntimeConventions::default();
    for (order, rule) in input.redirects.into_iter().enumerate() {
        let mut indexed = rule;
        indexed["order"] = json!(order);
        if indexed.get("match").and_then(Value::as_str) == Some("exact") {
            if let Some(source) = indexed.get("source").and_then(Value::as_str) {
                output
                    .redirects_exact
                    .entry(source.to_string())
                    .or_insert_with(|| Value::Array(Vec::new()))
                    .as_array_mut()
                    .expect("redirect bucket is an array")
                    .push(indexed);
            } else {
                output.redirects_pattern.push(indexed);
            }
        } else {
            output.redirects_pattern.push(indexed);
        }
    }
    for (order, rule) in input.headers.into_iter().enumerate() {
        let mut indexed = rule;
        indexed["order"] = json!(order);
        let path = indexed.get("path").and_then(Value::as_str);
        let regex = indexed.get("regex").and_then(Value::as_str);
        let hostless = indexed.get("host").is_none_or(Value::is_null);
        let exact = path
            .zip(regex)
            .is_some_and(|(path, regex)| regex == format!("^{}$", escape_regex_literal(path)));
        if exact && hostless {
            let path = path.expect("exact header path exists").to_string();
            output
                .headers_exact
                .entry(path)
                .or_insert_with(|| Value::Array(Vec::new()))
                .as_array_mut()
                .expect("header bucket is an array")
                .push(indexed);
        } else {
            output.headers_pattern.push(indexed);
        }
    }
    output
}

pub fn apply_routing_plan_policy(mut input: RoutingPlanInput) -> RoutingPlanOutput {
    let mut diagnostics = Vec::new();
    for rule in &mut input.redirects {
        if rule.get("action").and_then(Value::as_str) != Some("proxy") {
            continue;
        }
        rule["planGated"] = json!("external_proxy");
        if !input.policy.external_proxy {
            let source = rule.get("source").and_then(Value::as_str).unwrap_or("");
            let destination = rule
                .get("destination")
                .and_then(Value::as_str)
                .unwrap_or("");
            diagnostics.push(json!({
                "file": "_redirects",
                "line": 0,
                "severity": "warning",
                "code": "free_external_proxy_disabled",
                "message": format!("External proxying requires a paid plan; \"{source} {destination} 200\" publishes but serves the plan-restriction page until upgraded."),
                "source": format!("{source} {destination}"),
            }));
        }
    }

    let pooled_rule_count = input.redirects.len() + input.headers.len();
    if let Some(limit) = input.policy.routing_rules.as_u64() {
        if pooled_rule_count as u64 > limit {
            diagnostics.push(json!({
                "file": "_redirects",
                "line": 0,
                "severity": "warning",
                "code": "routing_rules_over_plan",
                "message": format!("This version compiles {pooled_rule_count} routing rules; the plan includes {limit}. Over-limit rules still serve during the soft-limit phase."),
                "source": format!("{pooled_rule_count} rules / plan limit {limit}"),
            }));
        }
    }

    RoutingPlanOutput {
        redirects: input.redirects,
        pooled_rule_count,
        diagnostics,
    }
}

fn merge_csp_value(value: &str, origins: &PreviewLoaderCspOrigins) -> String {
    let mut order = Vec::new();
    let mut directives = BTreeMap::<String, (String, Vec<String>)>::new();
    for part in value
        .split(';')
        .map(str::trim)
        .filter(|part| !part.is_empty())
    {
        let mut fields = part.split_whitespace();
        let Some(name) = fields.next() else {
            continue;
        };
        let key = name.to_ascii_lowercase();
        if directives.contains_key(&key) {
            continue;
        }
        order.push(key.clone());
        directives.insert(
            key,
            (name.to_string(), fields.map(str::to_string).collect()),
        );
    }

    let cast_origins = unique_tokens(input_origins(&origins.cast_origins));
    let screenshot_origins = unique_tokens(
        std::iter::once(origins.api_origin.clone())
            .chain(input_origins(&origins.screenshot_origins))
            .collect(),
    );
    let connect_origins = unique_tokens(
        std::iter::once(origins.api_origin.clone())
            .chain(cast_origins.iter().cloned())
            .chain(
                cast_origins
                    .iter()
                    .filter_map(|origin| websocket_origin(origin)),
            )
            .collect(),
    );
    let additions = BTreeMap::from([
        (
            "script-src",
            std::iter::once(origins.api_origin.clone())
                .chain(cast_origins.iter().cloned())
                .collect::<Vec<_>>(),
        ),
        (
            "script-src-elem",
            std::iter::once(origins.api_origin.clone())
                .chain(cast_origins.iter().cloned())
                .collect::<Vec<_>>(),
        ),
        ("connect-src", connect_origins),
        ("img-src", screenshot_origins),
    ]);

    let default_tokens = directives
        .get("default-src")
        .map(|(_, tokens)| without_none(tokens))
        .unwrap_or_default();
    for name in CSP_PLATFORM_DIRECTIVES {
        if !directives.contains_key(name) {
            order.push(name.to_string());
        }
        let base = directives
            .get(name)
            .map(|(_, tokens)| without_none(tokens))
            .unwrap_or_else(|| default_tokens.clone());
        let tokens = unique_tokens(
            base.into_iter()
                .chain(additions.get(name).into_iter().flatten().cloned())
                .collect(),
        );
        let display_name = directives
            .get(name)
            .map(|(display, _)| display.clone())
            .unwrap_or_else(|| name.to_string());
        directives.insert(name.to_string(), (display_name, tokens));
    }

    order
        .into_iter()
        .filter_map(|key| directives.get(&key))
        .map(|(name, tokens)| {
            std::iter::once(name.as_str())
                .chain(tokens.iter().map(String::as_str))
                .collect::<Vec<_>>()
                .join(" ")
        })
        .collect::<Vec<_>>()
        .join("; ")
}

fn input_origins(origins: &[String]) -> Vec<String> {
    origins.to_vec()
}

fn without_none(tokens: &[String]) -> Vec<String> {
    tokens
        .iter()
        .filter(|token| !token.eq_ignore_ascii_case("'none'"))
        .cloned()
        .collect()
}

fn unique_tokens(tokens: Vec<String>) -> Vec<String> {
    let mut seen = BTreeSet::new();
    tokens
        .into_iter()
        .filter(|token| !token.is_empty() && seen.insert(token.to_ascii_lowercase()))
        .collect()
}

fn websocket_origin(origin: &str) -> Option<String> {
    let mut url = url::Url::parse(origin).ok()?;
    match url.scheme() {
        "https" => url.set_scheme("wss").ok()?,
        "http" => url.set_scheme("ws").ok()?,
        _ => {}
    }
    Some(url.origin().ascii_serialization())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn host_qualified_exact_headers_remain_request_time_patterns() {
        let output = lower_runtime_conventions(RuntimeConventionsInput {
            redirects: Vec::new(),
            headers: vec![json!({
                "path": "/docs",
                "host": "docs.example.test",
                "regex": "^/docs$",
                "operations": [],
                "headers": {},
            })],
        });
        assert!(output.headers_exact.is_empty());
        assert_eq!(output.headers_pattern.len(), 1);
    }

    #[test]
    fn csp_merge_preserves_visible_header_text_and_normalizes_line_endings() {
        let output = merge_preview_loader_csp(PreviewLoaderCspInput {
            headers: "/*\r\n  Content-Security-Policy: default-src 'none'\r\n  X-Test: a:b\r\n"
                .into(),
            origins: PreviewLoaderCspOrigins {
                api_origin: "https://api.example.test".into(),
                cast_origins: vec!["https://cast.example.test".into()],
                screenshot_origins: Vec::new(),
            },
        });
        assert!(output.contains("connect-src https://api.example.test https://cast.example.test wss://cast.example.test"));
        assert!(output.ends_with("  X-Test: a:b\n"));
    }

    #[test]
    fn csp_merge_preserves_first_duplicate_directive_authority() {
        let output = merge_preview_loader_csp(PreviewLoaderCspInput {
            headers:
                "/*\n  Content-Security-Policy: default-src 'self'; script-src 'none'; script-src *"
                    .into(),
            origins: PreviewLoaderCspOrigins {
                api_origin: "https://api.example.test".into(),
                cast_origins: vec!["https://cast.example.test".into()],
                screenshot_origins: Vec::new(),
            },
        });

        assert!(output.contains("script-src https://api.example.test https://cast.example.test"));
        assert!(!output.contains("script-src *"));
    }

    #[test]
    fn plan_policy_marks_only_proxy_rules_with_same_source_and_destination() {
        let output = apply_routing_plan_policy(RoutingPlanInput {
            redirects: vec![
                json!({"source":"/same","destination":"https://api.example.test","action":"proxy"}),
                json!({"source":"/same","destination":"https://api.example.test","action":"redirect"}),
            ],
            headers: Vec::new(),
            policy: RoutingPlanPolicy {
                external_proxy: false,
                routing_rules: json!(10),
            },
        });
        assert_eq!(output.redirects[0]["planGated"], json!("external_proxy"));
        assert!(output.redirects[1].get("planGated").is_none());
        assert_eq!(output.diagnostics.len(), 1);
    }
}
