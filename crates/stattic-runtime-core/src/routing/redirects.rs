//! `_redirects` line grammar: status/force parsing, query capture tokens,
//! host-qualified sources, loop detection, and request conditions.

use std::collections::{BTreeMap, BTreeSet};

use super::{
    absolute_url_regex, compile_pattern, declaration_length, diagnostic, escape_regex_literal,
    has_host_pattern, normalize_hostname, normalize_path, parse_absolute_url,
    pattern_matches_value, query_token_regex, routing_tokens, routing_trim, status_regex,
    strip_trailing_slash, RedirectCondition, RedirectRule, RoutingDiagnostic, RoutingInput,
    RuleField, RuleIssue, SourceMatcher, REDIRECT_LINE_LIMIT, REDIRECT_STATUSES,
};

pub(super) fn compile_redirects(
    input: &RoutingInput,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) -> Vec<RedirectRule> {
    let mut rules = Vec::new();
    for (index, raw_line) in input.redirects.lines().enumerate() {
        let line_number = index + 1;
        let source_line = routing_trim(raw_line);
        if source_line.is_empty() || source_line.starts_with('#') {
            continue;
        }
        if declaration_length(source_line) > REDIRECT_LINE_LIMIT {
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
        let tokens: Vec<&str> = routing_tokens(source_line);
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
        let mut rule =
            match compile_redirect_rule(raw_source, destination, status, &input.assigned_hostnames)
            {
                Ok(rule) => rule,
                Err(issue) => {
                    diagnostic(
                        diagnostics,
                        "_redirects",
                        line_number,
                        issue.severity,
                        issue.code,
                        &issue.message,
                        source_line,
                    );
                    continue;
                }
            };
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
        let directive_start = destination_index + if status_capture.is_some() { 2 } else { 1 };
        let mut condition_tokens: Vec<&str> = Vec::new();
        let mut cache: Option<&'static str> = None;
        let mut invalid_cache_directive = false;
        for token in tokens[directive_start..].iter().copied() {
            let separator = token.find('=');
            let name = separator.map_or(token, |index| &token[..index]);
            if name.to_lowercase() != "cache" {
                condition_tokens.push(token);
                continue;
            }
            let value = separator.map_or("", |index| &token[index + 1..]);
            if value != "shared" {
                invalid_cache_directive = true;
                diagnostic(
                    diagnostics,
                    "_redirects",
                    line_number,
                    "error",
                    "redirect_cache_directive_invalid",
                    "Proxy cache directives must use \"cache=shared\".",
                    source_line,
                );
                continue;
            }
            cache = Some("shared");
        }
        if invalid_cache_directive {
            cache = None;
        }
        if cache == Some("shared") && rule.action != "proxy" {
            cache = None;
            diagnostic(
                diagnostics,
                "_redirects",
                line_number,
                "warning",
                "redirect_cache_not_proxy",
                "The \"cache=shared\" directive only applies to absolute-URL 200 proxy rules.",
                source_line,
            );
        }
        rule.force = force;
        rule.query = query;
        rule.cache = cache;
        rule.conditions =
            parse_conditions(&condition_tokens, line_number, source_line, diagnostics);
        rules.push(rule);
    }
    rules
}

/// The rule core both grammars share: source normalization, matcher
/// compilation, loop detection and the destination rules. A `_redirects` line
/// and a `sf.jsonc` entry differ only in how they spell a rule, so they compile
/// through here and report the same diagnostic codes. Callers attach the extras
/// their own grammar carries (force, query captures, conditions, cache).
pub(super) fn compile_redirect_rule(
    raw_source: &str,
    destination: &str,
    status: u16,
    assigned_hostnames: &[String],
) -> Result<RedirectRule, RuleIssue> {
    let source = normalize_source(raw_source, assigned_hostnames)?;
    if redirect_loop(&source, destination) {
        return Err(RuleIssue::error(
            RuleField::Destination,
            "redirect_loop_invalid",
            "This rule points back to the same path and could create a loop.",
        ));
    }
    let compiled = compile_pattern(&source.path, '/', false, true);
    if let Some(error) = compiled.error {
        return Err(RuleIssue::error(
            RuleField::Source,
            "redirect_pattern_invalid",
            error,
        ));
    }
    if (status == 200
        && !destination.starts_with('/')
        && !absolute_url_regex().is_match(destination))
        || (status == 404 && !destination.starts_with('/'))
    {
        return Err(RuleIssue::error(
            RuleField::Destination,
            "redirect_rewrite_destination_invalid",
            "A rewrite destination must be a path or an absolute URL. A custom 404 destination must be a path.",
        ));
    }
    Ok(RedirectRule {
        source: source.path,
        destination: destination.to_string(),
        action: if status == 404 {
            "notFound"
        } else if status == 200 && absolute_url_regex().is_match(destination) {
            "proxy"
        } else if status == 200 {
            "rewrite"
        } else {
            "redirect"
        },
        status,
        match_kind: compiled.match_kind,
        regex: compiled.regex,
        host: source.host,
        host_regex: source.host_regex,
        force: false,
        query: None,
        conditions: Vec::new(),
        cache: None,
        plan_gated: None,
    })
}

fn normalize_source(raw: &str, assigned_hostnames: &[String]) -> Result<SourceMatcher, RuleIssue> {
    if let Some(url) = parse_absolute_url(raw) {
        let host = normalize_hostname(&url.host);
        let compiled = compile_pattern(&host, '.', false, false);
        if let Some(error) = compiled.error {
            return Err(RuleIssue::error(
                RuleField::Source,
                "redirect_host_invalid",
                error,
            ));
        }
        let assigned: BTreeSet<String> = assigned_hostnames
            .iter()
            .map(|host| normalize_hostname(host))
            .collect();
        if !assigned.is_empty() && !has_host_pattern(&host) && !assigned.contains(&host) {
            return Err(RuleIssue::error(
                RuleField::Source,
                "redirect_hostname_unassigned",
                format!("The hostname \"{host}\" is not assigned to this space."),
            ));
        }
        return Ok(SourceMatcher {
            path: strip_trailing_slash(&normalize_path(&url.path)),
            host_regex: Some(
                compiled
                    .regex
                    .unwrap_or_else(|| format!("^{}$", escape_regex_literal(&host))),
            ),
            host: Some(host),
        });
    }
    if !raw.starts_with('/') {
        return Err(RuleIssue::error(
            RuleField::Source,
            "redirect_source_invalid",
            "The source must start with / or use an absolute URL.",
        ));
    }
    Ok(SourceMatcher {
        path: strip_trailing_slash(&normalize_path(raw)),
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
    strip_trailing_slash(&source.path) == strip_trailing_slash(&normalize_path(destination_path))
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
        let name = raw_name.to_lowercase();
        // Only the segment between the first and second `=` is the value list: a
        // `Cookie=nf_ab=alpha` token names the cookie `nf_ab`, which is all the
        // matcher ever compares.
        let values: Vec<String> = raw_value
            .split('=')
            .next()
            .unwrap_or_default()
            .split(',')
            .filter(|value| !value.is_empty())
            .map(str::to_string)
            .collect();
        if name.is_empty() || values.is_empty() {
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
                        .map(|value| value.to_lowercase())
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
                    .map(|value| value.to_lowercase())
                    .collect(),
            }),
            "cookie" => conditions.push(RedirectCondition { kind: name, values }),
            "agent"
                if values
                    .iter()
                    .all(|value| matches!(value.to_lowercase().as_str(), "1" | "true" | "yes")) =>
            {
                conditions.push(RedirectCondition {
                    kind: name,
                    values: values
                        .into_iter()
                        .map(|value| value.to_lowercase())
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
