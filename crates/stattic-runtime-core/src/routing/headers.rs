//! `_headers` block grammar: matcher lines, set/remove operations, CSP and
//! platform-managed header policy (first-wins semantics are enforced by the
//! shared `crate::policy::platform_managed_response_header` list).

use crate::policy::{never_removable_response_header, platform_managed_response_header};

use super::{
    canonical_header_name, cdn_headers, compile_pattern, declaration_length, diagnostic,
    escape_regex_literal, header_name_regex, normalize_hostname, normalize_path,
    parse_absolute_url, routing_trim, HeaderOperation, HeaderRule, RoutingDiagnostic, RuleField,
    RuleIssue, HEADER_LINE_LIMIT,
};

#[derive(Debug)]
struct HeaderSourceOperation {
    operation: HeaderOperation,
    line: usize,
    source: String,
}

pub(super) type HeaderMatcher = (String, Option<String>, Option<String>, Option<String>);

/// What the shared header-name policy decided. Callers attach the location: a
/// `_headers` line, or a `sf.jsonc` key path.
pub(super) enum HeaderNameVerdict {
    Accepted {
        canonical: String,
        advisory: Option<HeaderNameNote>,
    },
    Rejected(HeaderNameNote),
}

pub(super) struct HeaderNameNote {
    pub code: &'static str,
    pub message: String,
}

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
        let matcher = match compile_header_matcher(&path) {
            Ok(matcher) => Some(matcher),
            Err(issue) => {
                diagnostic(
                    diagnostics,
                    "_headers",
                    line,
                    issue.severity,
                    issue.code,
                    &issue.message,
                    &source,
                );
                None
            }
        };
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
                origin: "file",
            });
        }
    };
    for (index, raw_line) in content.lines().enumerate() {
        let line = index + 1;
        // One trailing CR, the way `/\r$/` strips it — not every trailing CR.
        let source_line = raw_line.strip_suffix('\r').unwrap_or(raw_line);
        let trimmed = routing_trim(source_line);
        if declaration_length(source_line) > HEADER_LINE_LIMIT {
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
            if routing_trim(name).is_empty() {
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
                        name: routing_trim(name).to_string(),
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
        if routing_trim(name).is_empty() {
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
                name: routing_trim(name).to_string(),
                value: Some(routing_trim(value).to_string()),
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

/// The header matcher grammar shared by `_headers` blocks and `sf.jsonc`
/// header rules: one matcher syntax, one set of diagnostic codes.
pub(super) fn compile_header_matcher(path: &str) -> Result<HeaderMatcher, RuleIssue> {
    if let Some(url) = parse_absolute_url(path) {
        if !url.port.is_empty() {
            return Err(RuleIssue::error(
                RuleField::Source,
                "header_port_unsupported",
                "Header URL matchers cannot include ports.",
            ));
        }
        let host = normalize_hostname(&url.host);
        let normalized_path = normalize_path(&url.path);
        let host_compiled = compile_pattern(&host, '.', true, false);
        let path_compiled = compile_pattern(&normalized_path, '/', true, false);
        if host_compiled.error.is_some() || path_compiled.error.is_some() {
            return Err(RuleIssue::error(
                RuleField::Source,
                "header_pattern_invalid",
                host_compiled
                    .error
                    .as_deref()
                    .or(path_compiled.error.as_deref())
                    .unwrap_or("This header matcher is not valid."),
            ));
        }
        return Ok((
            normalized_path.clone(),
            Some(host.clone()),
            Some(
                path_compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&normalized_path))),
            ),
            Some(
                host_compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&host))),
            ),
        ));
    }
    if !path.starts_with('/') {
        return Err(RuleIssue::error(
            RuleField::Source,
            "header_path_invalid",
            "The header matcher must start with / or use an absolute URL.",
        ));
    }
    let normalized_path = normalize_path(path);
    let compiled = compile_pattern(&normalized_path, '/', false, false);
    if let Some(error) = compiled.error {
        return Err(RuleIssue::error(
            RuleField::Source,
            "header_pattern_invalid",
            error,
        ));
    }
    Ok((
        normalized_path.clone(),
        None,
        Some(
            compiled
                .regex
                .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&normalized_path))),
        ),
        None,
    ))
}

/// Which header names a rule may set, shared by both grammars: Basic-Auth is
/// never a response header, names are syntax-checked, platform-managed headers
/// stay ours, and Cache-Control only advises.
pub(super) fn check_header_name(name: &str) -> HeaderNameVerdict {
    let trimmed = routing_trim(name);
    let lower = trimmed.to_lowercase();
    if lower == "basic-auth" {
        return HeaderNameVerdict::Rejected(HeaderNameNote {
            code: "header_basic_auth_unsupported",
            message:
                "Basic-Auth is not a response header. Use Spacefast sharing to control access."
                    .into(),
        });
    }
    if !header_name_regex().is_match(trimmed) {
        return HeaderNameVerdict::Rejected(HeaderNameNote {
            code: "header_name_invalid",
            message: "This header name is not valid.".into(),
        });
    }
    let canonical = canonical_header_name(trimmed);
    if platform_managed_response_header(&lower) {
        return HeaderNameVerdict::Rejected(if cdn_headers().contains(lower.as_str()) {
            HeaderNameNote {
                code: "header_cdn_cache_unsupported",
                message: format!("The \"{canonical}\" header does not have Spacefast semantics."),
            }
        } else {
            HeaderNameNote {
                code: "header_name_unsupported",
                message: format!(
                    "The \"{canonical}\" header is managed by the platform and cannot be changed."
                ),
            }
        });
    }
    HeaderNameVerdict::Accepted {
        canonical,
        advisory: (lower == "cache-control").then(|| HeaderNameNote {
            code: "header_cache_control_platform_managed",
            message: "Cache-Control applies to browser responses; platform edge caching is managed by Spacefast.".into(),
        }),
    }
}

fn normalize_header_operation(
    item: HeaderSourceOperation,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Option<HeaderOperation> {
    let mut operation = item.operation;
    match check_header_name(&operation.name) {
        HeaderNameVerdict::Rejected(note) => {
            diagnostic(
                diagnostics,
                "_headers",
                item.line,
                "error",
                note.code,
                &note.message,
                // A rejected Basic-Auth line carries credentials; echo the
                // line back for every other name, never for that one.
                if note.code == "header_basic_auth_unsupported" {
                    "[redacted]"
                } else {
                    &item.source
                },
            );
            None
        }
        HeaderNameVerdict::Accepted {
            canonical,
            advisory,
        } => {
            // Setting one is publisher metadata; deleting the platform's own is
            // not a rule we can honor (see `never_removable_response_header`).
            if operation.kind == "remove" && never_removable_response_header(&canonical) {
                diagnostic(
                    diagnostics,
                    "_headers",
                    item.line,
                    "error",
                    "header_name_unsupported",
                    &format!(
                        "The \"{canonical}\" header is set by Spacefast for every response and cannot be removed."
                    ),
                    &item.source,
                );
                return None;
            }
            if let Some(note) = advisory {
                diagnostic(
                    diagnostics,
                    "_headers",
                    item.line,
                    "warning",
                    note.code,
                    &note.message,
                    &item.source,
                );
            }
            operation.name = canonical;
            operation.line = Some(item.line);
            operation.source = Some(item.source);
            Some(operation)
        }
    }
}
