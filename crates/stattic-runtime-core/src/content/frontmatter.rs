//! YAML frontmatter extraction with lenient recovery for malformed documents.

use serde_json::{json, Map, Value};

pub(super) fn split_frontmatter<'a>(
    source: &'a str,
    path: &str,
    diagnostics: &mut Vec<Value>,
) -> (Map<String, Value>, &'a str) {
    let source = source.strip_prefix('\u{feff}').unwrap_or(source);
    if !source.starts_with("---") {
        return (Map::new(), source);
    }
    let rest = &source[3..];
    let Some(end) = rest.find("\n---") else {
        return (Map::new(), source);
    };
    let yaml = rest[..end].trim_start_matches(['\r', '\n']);
    let body = &rest[end + 4..].trim_start_matches(['\r', '\n']);
    match serde_yaml::from_str::<Value>(yaml) {
        Ok(value) => (value.as_object().cloned().unwrap_or_default(), body),
        Err(error) => {
            diagnostics.push(json!({
                "code":"markdown_frontmatter_invalid",
                "severity":"warning",
                "message":"Markdown frontmatter could not be parsed as YAML; flat key: value scalars were recovered.",
                "path":path,
                "details":{"reason":error.to_string()}
            }));
            (recover_flat_frontmatter(yaml), body)
        }
    }
}

fn recover_flat_frontmatter(source: &str) -> Map<String, Value> {
    let mut recovered = Map::new();
    for line in source.lines() {
        if line.trim().is_empty() || line.trim_start().starts_with('#') {
            continue;
        }
        let Some((key, raw)) = line.split_once(':') else {
            continue;
        };
        if key.is_empty()
            || !key.bytes().enumerate().all(|(index, byte)| {
                byte.is_ascii_alphanumeric()
                    || byte == b'_'
                    || (index > 0 && matches!(byte, b'.' | b'-'))
            })
        {
            continue;
        }
        let mut value = raw.trim();
        if value.is_empty() {
            continue;
        }
        if value.len() >= 2
            && ((value.starts_with('"') && value.ends_with('"'))
                || (value.starts_with('\'') && value.ends_with('\'')))
        {
            value = &value[1..value.len() - 1];
        }
        recovered.insert(key.to_string(), Value::String(value.to_string()));
    }
    recovered
}
