//! `_headers` block grammar: matcher lines, set/remove operations, CSP and
//! platform-managed header policy (first-wins semantics are enforced by the
//! shared `crate::policy::platform_managed_response_header` list), and the
//! recognition of `Basic-Auth` operations for later lowering.

use crate::policy::platform_managed_response_header;

use super::{
    basic_auth_regex, canonical_header_name, cdn_headers, compile_pattern, diagnostic,
    escape_regex_literal, header_name_regex, normalize_hostname, parse_absolute_url,
    HeaderOperation, HeaderRule, RoutingDiagnostic, HEADER_LINE_LIMIT,
};

#[derive(Debug)]
struct HeaderSourceOperation {
    operation: HeaderOperation,
    line: usize,
    source: String,
}

type HeaderMatcher = (String, Option<String>, Option<String>, Option<String>);

pub(super) fn compile_headers(
    content: &str,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Vec<HeaderRule> {
    let mut rules = Vec::new();
    let mut current: Option<(String, usize, String)> = None;
    let mut operations: Vec<HeaderSourceOperation> = Vec::new();
    let flush = |current: &mut Option<(String, usize, String)>,
                 operations: &mut Vec<HeaderSourceOperation>,
                 rules: &mut Vec<HeaderRule>,
                 diagnostics: &mut Vec<RoutingDiagnostic>| {
        let Some((path, line, source)) = current.take() else {
            operations.clear();
            return;
        };
        let matcher = compile_header_matcher(&path, line, &source, diagnostics);
        let normalized: Vec<HeaderOperation> = operations
            .drain(..)
            .filter_map(|item| normalize_header_operation(item, diagnostics))
            .collect();
        if let Some((path, host, regex, host_regex)) = matcher.filter(|_| !normalized.is_empty()) {
            let headers = normalized
                .iter()
                .filter(|op| op.kind == "set")
                .map(|op| (op.name.clone(), op.value.clone().unwrap_or_default()))
                .collect();
            rules.push(HeaderRule {
                path,
                host,
                regex,
                host_regex,
                operations: normalized,
                headers,
            });
        }
    };
    for (index, raw_line) in content.lines().enumerate() {
        let line = index + 1;
        let source_line = raw_line.trim_end_matches('\r');
        let trimmed = source_line.trim();
        if source_line.len() > HEADER_LINE_LIMIT {
            if !source_line.starts_with([' ', '\t']) {
                flush(&mut current, &mut operations, &mut rules, diagnostics);
            }
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_line_too_long",
                "This header line is too long.",
                trimmed,
            );
            continue;
        }
        if trimmed.is_empty() || trimmed.starts_with('#') {
            continue;
        }
        if !source_line.starts_with([' ', '\t']) {
            flush(&mut current, &mut operations, &mut rules, diagnostics);
            current = Some((trimmed.to_string(), line, trimmed.to_string()));
            continue;
        }
        if current.is_none() {
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_operation_without_matcher",
                "Add a path or URL before this header.",
                trimmed,
            );
            continue;
        }
        if let Some(name) = trimmed.strip_prefix('!') {
            if name.trim().is_empty() {
                diagnostic(
                    diagnostics,
                    "_headers",
                    line,
                    "error",
                    "header_remove_invalid",
                    "Choose a header to remove.",
                    trimmed,
                );
            } else {
                operations.push(HeaderSourceOperation {
                    operation: HeaderOperation {
                        kind: "remove",
                        name: name.trim().to_string(),
                        value: None,
                        line: None,
                        source: None,
                    },
                    line,
                    source: trimmed.to_string(),
                });
            }
            continue;
        }
        let Some((name, value)) = trimmed.split_once(':') else {
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_operation_invalid",
                "Header operations must use Name: value.",
                trimmed,
            );
            continue;
        };
        if name.trim().is_empty() {
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_name_missing",
                "Add a header name.",
                trimmed,
            );
            continue;
        }
        operations.push(HeaderSourceOperation {
            operation: HeaderOperation {
                kind: "set",
                name: name.trim().to_string(),
                value: Some(value.trim().to_string()),
                line: None,
                source: None,
            },
            line,
            source: trimmed.to_string(),
        });
    }
    flush(&mut current, &mut operations, &mut rules, diagnostics);
    rules
}

fn compile_header_matcher(
    path: &str,
    line: usize,
    source: &str,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Option<HeaderMatcher> {
    if let Some(url) = parse_absolute_url(path) {
        if !url.port.is_empty() {
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_port_unsupported",
                "Header URL matchers cannot include ports.",
                source,
            );
            return None;
        }
        let host = normalize_hostname(&url.host);
        let host_compiled = compile_pattern(&host, '.', true, false);
        let path_compiled = compile_pattern(&url.path, '/', true, false);
        if host_compiled.error.is_some() || path_compiled.error.is_some() {
            diagnostic(
                diagnostics,
                "_headers",
                line,
                "error",
                "header_pattern_invalid",
                host_compiled
                    .error
                    .as_deref()
                    .or(path_compiled.error.as_deref())
                    .unwrap_or("This header matcher is not valid."),
                source,
            );
            return None;
        }
        return Some((
            url.path.clone(),
            Some(host.clone()),
            Some(
                path_compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&url.path))),
            ),
            Some(
                host_compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&host))),
            ),
        ));
    }
    if !path.starts_with('/') {
        diagnostic(
            diagnostics,
            "_headers",
            line,
            "error",
            "header_path_invalid",
            "The header matcher must start with / or use an absolute URL.",
            source,
        );
        return None;
    }
    let compiled = compile_pattern(path, '/', false, false);
    if let Some(error) = compiled.error {
        diagnostic(
            diagnostics,
            "_headers",
            line,
            "error",
            "header_pattern_invalid",
            &error,
            source,
        );
        return None;
    }
    Some((
        path.to_string(),
        None,
        Some(
            compiled
                .regex
                .unwrap_or_else(|| format!("^{}$", escape_regex_literal(path))),
        ),
        None,
    ))
}

fn normalize_header_operation(
    item: HeaderSourceOperation,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Option<HeaderOperation> {
    let mut operation = item.operation;
    let lower = operation.name.trim().to_ascii_lowercase();
    if lower == "basic-auth" {
        let value = operation.value.as_deref().unwrap_or("").trim();
        if operation.kind != "set"
            || value.is_empty()
            || value
                .split_whitespace()
                .any(|credential| !basic_auth_regex().is_match(credential))
        {
            diagnostic(
                diagnostics,
                "_headers",
                item.line,
                "error",
                "header_basic_auth_invalid",
                "Basic-Auth credentials must use username:password.",
                "[redacted]",
            );
            return None;
        }
        diagnostic(
            diagnostics,
            "_headers",
            item.line,
            "warning",
            "header_basic_auth_never_emitted",
            "Basic-Auth protects matching paths and is not sent as a response header.",
            "[redacted]",
        );
        return Some(HeaderOperation {
            kind: "basicAuth",
            name: "Basic-Auth".into(),
            value: Some(value.to_string()),
            line: None,
            source: None,
        });
    }
    if !header_name_regex().is_match(operation.name.trim()) {
        diagnostic(
            diagnostics,
            "_headers",
            item.line,
            "error",
            "header_name_invalid",
            "This header name is not valid.",
            &item.source,
        );
        return None;
    }
    let canonical = canonical_header_name(&operation.name);
    if platform_managed_response_header(&lower) {
        diagnostic(
            diagnostics,
            "_headers",
            item.line,
            "error",
            if cdn_headers().contains(lower.as_str()) {
                "header_cdn_cache_unsupported"
            } else {
                "header_name_unsupported"
            },
            &if cdn_headers().contains(lower.as_str()) {
                format!("The \"{canonical}\" header does not have Spacefast semantics.")
            } else {
                format!(
                    "The \"{canonical}\" header is managed by the platform and cannot be changed."
                )
            },
            &item.source,
        );
        return None;
    }
    if lower == "cache-control" {
        diagnostic(diagnostics, "_headers", item.line, "warning", "header_cache_control_platform_managed", "Cache-Control applies to browser responses; platform edge caching is managed by Spacefast.", &item.source);
    }
    operation.name = canonical;
    operation.line = Some(item.line);
    operation.source = Some(item.source);
    Some(operation)
}
