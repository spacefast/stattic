//! `_redirects` line grammar: status/force parsing, query capture tokens,
//! host-qualified sources, loop detection, and request conditions.

use std::collections::{BTreeMap, BTreeSet};

use super::{
    absolute_url_regex, compile_pattern, diagnostic, escape_regex_literal, has_host_pattern,
    normalize_hostname, parse_absolute_url, pattern_matches_value, query_token_regex, status_regex,
    strip_trailing_slash, RedirectCondition, RedirectRule, RoutingDiagnostic, RoutingInput,
    SourceMatcher, REDIRECT_LINE_LIMIT, REDIRECT_STATUSES,
};

pub(super) fn compile_redirects(
    input: &RoutingInput,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Vec<RedirectRule> {
    let mut rules = Vec::new();
    for (index, raw_line) in input.redirects.lines().enumerate() {
        let line_number = index + 1;
        let source_line = raw_line.trim();
        if source_line.is_empty() || source_line.starts_with('#') {
            continue;
        }
        if source_line.len() > REDIRECT_LINE_LIMIT {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_line_too_long",
                "This redirect rule is too long.",
                source_line,
            );
            continue;
        }
        let tokens: Vec<&str> = source_line.split_whitespace().collect();
        let Some(raw_source) = tokens.first().copied() else {
            continue;
        };
        let mut query_tokens = Vec::new();
        let mut destination_index = 1;
        while destination_index < tokens.len()
            && query_token_regex().is_match(tokens[destination_index])
        {
            query_tokens.push(tokens[destination_index]);
            destination_index += 1;
        }
        let Some(destination) = tokens.get(destination_index).copied() else {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_destination_missing",
                "Add a destination for this redirect rule.",
                source_line,
            );
            continue;
        };
        let status_token = tokens.get(destination_index + 1).copied();
        let status_capture = status_token.and_then(|token| status_regex().captures(token));
        let status = status_capture
            .as_ref()
            .and_then(|capture| capture.get(1))
            .and_then(|value| value.as_str().parse::<u16>().ok())
            .unwrap_or(302);
        let force = status_capture
            .as_ref()
            .and_then(|capture| capture.get(2))
            .is_some();
        if status_token.is_some_and(|token| {
            status_capture.is_none() && token.starts_with(|ch: char| ch.is_ascii_digit())
        }) {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_status_invalid",
                "Use a supported status code, optionally followed by !.",
                source_line,
            );
            continue;
        }
        if !REDIRECT_STATUSES.contains(&status) {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_status_unsupported",
                &format!("Status {status} is not supported."),
                source_line,
            );
            continue;
        }
        let Some(source) =
            normalize_source(raw_source, input, line_number, source_line, diagnostics)
        else {
            continue;
        };
        if redirect_loop(&source, destination) {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_loop_invalid",
                "This rule points back to the same path and could create a loop.",
                source_line,
            );
            continue;
        }
        let compiled = compile_pattern(&source.path, '/', false, true);
        if let Some(error) = compiled.error {
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "error",
                "redirect_pattern_invalid",
                &error,
                source_line,
            );
            continue;
        }
        let query = if query_tokens.is_empty() {
            None
        } else {
            let mut values = BTreeMap::new();
            for token in &query_tokens {
                let Some((name, capture)) = token.split_once("=:") else {
                    continue;
                };
                values.insert(name.to_string(), capture.to_string());
            }
            Some(values)
        };
        if (status == 200
            && !destination.starts_with('/')
            && !absolute_url_regex().is_match(destination))
            || (status == 404 && !destination.starts_with('/'))
        {
            diagnostic(diagnostics, "_redirects", line_number, "error", "redirect_rewrite_destination_invalid", "A rewrite destination must be a path or an absolute URL. A custom 404 destination must be a path.", source_line);
            continue;
        }
        let condition_start = destination_index + if status_capture.is_some() { 2 } else { 1 };
        let conditions = parse_conditions(
            &tokens[condition_start..],
            line_number,
            source_line,
            diagnostics,
        );
        let action = if status == 404 {
            "notFound"
        } else if status == 200 && absolute_url_regex().is_match(destination) {
            "proxy"
        } else if status == 200 {
            "rewrite"
        } else {
            "redirect"
        };
        rules.push(RedirectRule {
            source: source.path,
            destination: destination.to_string(),
            action,
            status,
            match_kind: compiled.match_kind,
            regex: compiled.regex,
            host: source.host,
            host_regex: source.host_regex,
            force,
            query,
            conditions,
        });
    }
    rules
}

fn normalize_source(
    raw: &str,
    input: &RoutingInput,
    line: usize,
    source_line: &str,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Option<SourceMatcher> {
    if let Some(url) = parse_absolute_url(raw) {
        let host = normalize_hostname(&url.host);
        let compiled = compile_pattern(&host, '.', false, false);
        if let Some(error) = compiled.error {
            diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_host_invalid",
                &error,
                source_line,
            );
            return None;
        }
        let assigned: BTreeSet<String> = input
            .assigned_hostnames
            .iter()
            .map(|host| normalize_hostname(host))
            .collect();
        if !assigned.is_empty() && !has_host_pattern(&host) && !assigned.contains(&host) {
            diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_hostname_unassigned",
                &format!("The hostname \"{host}\" is not assigned to this space."),
                source_line,
            );
            return None;
        }
        return Some(SourceMatcher {
            path: strip_trailing_slash(&url.path),
            host_regex: Some(
                compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&host))),
            ),
            host: Some(host),
        });
    }
    if !raw.starts_with('/') {
        diagnostic(
            diagnostics,
            "_redirects",
            line,
            "error",
            "redirect_source_invalid",
            "The source must start with / or use an absolute URL.",
            source_line,
        );
        return None;
    }
    Some(SourceMatcher {
        path: strip_trailing_slash(raw),
        host: None,
        host_regex: None,
    })
}

fn redirect_loop(source: &SourceMatcher, destination: &str) -> bool {
    let parsed = parse_absolute_url(destination);
    if let Some(destination_url) = &parsed {
        let destination_host = normalize_hostname(&destination_url.host);
        let Some(source_host) = &source.host else {
            return false;
        };
        if has_host_pattern(source_host) {
            if !pattern_matches_value(source_host, '.', &destination_host) {
                return false;
            }
        } else if normalize_hostname(source_host) != destination_host {
            return false;
        }
    }
    let destination_path = parsed
        .as_ref()
        .map(|url| url.path.as_str())
        .unwrap_or_else(|| destination.split('?').next().unwrap_or(destination));
    strip_trailing_slash(&source.path) == strip_trailing_slash(destination_path)
}

fn parse_conditions(
    tokens: &[&str],
    line: usize,
    source: &str,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Vec<RedirectCondition> {
    let mut conditions = Vec::new();
    for token in tokens {
        let Some((raw_name, raw_value)) = token.split_once('=') else {
            diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_condition_invalid",
                "This condition is not valid. Check the name and values.",
                source,
            );
            continue;
        };
        let name = raw_name.to_ascii_lowercase();
        let values: Vec<String> = raw_value
            .split(',')
            .filter(|value| !value.is_empty())
            .map(str::to_string)
            .collect();
        if values.is_empty() {
            diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_condition_invalid",
                "This condition is not valid. Check the name and values.",
                source,
            );
            continue;
        }
        match name.as_str() {
            "country"
                if values.iter().all(|value| {
                    value.len() == 2 && value.chars().all(|ch| ch.is_ascii_alphabetic())
                }) =>
            {
                conditions.push(RedirectCondition {
                    kind: name,
                    values: values
                        .into_iter()
                        .map(|value| value.to_ascii_lowercase())
                        .collect(),
                })
            }
            "country" => diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_country_invalid",
                "Country values must use two-letter country codes.",
                source,
            ),
            "language" => conditions.push(RedirectCondition {
                kind: name,
                values: values
                    .into_iter()
                    .map(|value| value.to_ascii_lowercase())
                    .collect(),
            }),
            "cookie" => conditions.push(RedirectCondition { kind: name, values }),
            "agent"
                if values.iter().all(|value| {
                    matches!(value.to_ascii_lowercase().as_str(), "1" | "true" | "yes")
                }) =>
            {
                conditions.push(RedirectCondition {
                    kind: name,
                    values: values
                        .into_iter()
                        .map(|value| value.to_ascii_lowercase())
                        .collect(),
                })
            }
            "agent" => diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_agent_condition_invalid",
                "Agent conditions must use \"true\", \"yes\", or \"1\".",
                source,
            ),
            "role" => diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_role_unsupported",
                "Role-based routing is not supported. Remove this condition before publishing.",
                source,
            ),
            _ => diagnostic(
                diagnostics,
                "_redirects",
                line,
                "error",
                "redirect_condition_unsupported",
                &format!("The \"{raw_name}\" condition is not supported."),
                source,
            ),
        }
    }
    conditions
}
