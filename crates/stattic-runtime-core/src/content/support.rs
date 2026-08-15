//! Small string, HTML, and config-map helpers shared across the content
//! pipeline.

use regex::Regex;
use serde_json::{Map, Value};
use std::sync::OnceLock;

pub(super) fn title_from_path(path: &str) -> String {
    let name = path
        .rsplit('/')
        .next()
        .unwrap_or(path)
        .split('.')
        .next()
        .unwrap_or(path)
        .replace(['-', '_'], " ");
    let mut chars = name.chars();
    chars
        .next()
        .map(|c| c.to_uppercase().collect::<String>() + chars.as_str())
        .unwrap_or_default()
}

pub(super) fn first_heading(html: &str) -> Option<String> {
    heading_regex()
        .captures(html)
        .and_then(|c| c.get(1))
        .map(|m| strip_tags(m.as_str()).trim().to_string())
        .filter(|v| !v.is_empty())
}

pub(super) fn strip_tags(value: &str) -> String {
    tags_regex().replace_all(value, "").into_owned()
}

pub(super) fn string_in(map: &Map<String, Value>, key: &str) -> Option<String> {
    map.get(key)
        .and_then(Value::as_str)
        .map(str::trim)
        .filter(|v| !v.is_empty())
        .map(str::to_string)
}

pub(super) fn string_in_opt(map: Option<&Map<String, Value>>, key: &str) -> Option<String> {
    map.and_then(|m| string_in(m, key))
}

pub(super) fn path_dir(path: &str) -> &str {
    path.rsplit_once('/').map(|(d, _)| d).unwrap_or("")
}

pub(super) fn resolve_layout_override(source: &str, value: &str) -> String {
    if value.starts_with('/') {
        value.trim_start_matches('/').into()
    } else {
        let dir = path_dir(source);
        if dir.is_empty() {
            value.into()
        } else {
            format!("{dir}/{value}")
        }
    }
}

pub(crate) fn escape_html(v: &str) -> String {
    v.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
}

pub(crate) fn escape_attr(v: &str) -> String {
    escape_html(v)
        .replace('"', "&quot;")
        .replace('\'', "&#039;")
}

pub(super) fn inject_snippets(config: &Map<String, Value>, key: &str) -> Vec<String> {
    config
        .get("inject")
        .and_then(Value::as_object)
        .and_then(|v| v.get(key))
        .and_then(Value::as_array)
        .map(|v| {
            v.iter()
                .filter_map(Value::as_str)
                .map(str::to_string)
                .collect()
        })
        .unwrap_or_default()
}

pub(super) fn marked_block(name: &str, lines: &[String]) -> String {
    format!(
        "<!-- spacefast:{name} -->\n{}\n<!-- /spacefast:{name} -->\n",
        lines.join("\n")
    )
}

fn regex_cell(cell: &'static OnceLock<Regex>, pattern: &str) -> &'static Regex {
    cell.get_or_init(|| Regex::new(pattern).expect("static regex must compile"))
}

fn heading_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    regex_cell(&CELL, r"(?is)<h[1-6](?:\s[^>]*)?>(.*?)</h[1-6]>")
}

fn tags_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    regex_cell(&CELL, r"(?is)<[^>]*>")
}
