//! Deterministic front matter (design §5.2): the same record always renders as the same bytes, keys in
//! the order given (schema order), `null` written out, strings quoted only when a plain scalar would read
//! back as something else. front_matter.rs parses everything this writes.

use crate::sync::{canon, front_matter};
use serde_json::{Map, Value};

/// A Markdown sync file: `uid` first, then the fields, then the body (None writes no body).
pub fn markdown(uid: &str, fields: &Map<String, Value>, body: Option<&str>) -> String {
    let mut out = String::from("---\n");
    out.push_str(&format!("uid: {}\n", scalar(&Value::String(uid.into()))));
    for (key, value) in fields {
        entry(&mut out, 0, key, value);
    }
    out.push_str("---\n");
    if let Some(body) = body {
        out.push_str(body);
    }
    out
}

fn entry(out: &mut String, indent: usize, key: &str, value: &Value) {
    let pad = " ".repeat(indent);
    let key = key_text(key);
    match value {
        Value::Object(map) if !map.is_empty() => {
            out.push_str(&format!("{pad}{key}:\n"));
            for (k, v) in map {
                entry(out, indent + 2, k, v);
            }
        }
        Value::Array(items) if items.iter().any(|v| v.is_object() || v.is_array()) => {
            out.push_str(&format!("{pad}{key}:\n"));
            for item in items {
                list_item(out, indent + 2, item);
            }
        }
        Value::String(s) if s.contains('\n') && block_safe(s) => {
            out.push_str(&format!("{pad}{key}: {}\n", block_header(s)));
            block_body(out, indent + 2, s);
        }
        _ => out.push_str(&format!("{pad}{key}: {}\n", inline(value))),
    }
}

fn list_item(out: &mut String, indent: usize, value: &Value) {
    let pad = " ".repeat(indent);
    match value {
        Value::Object(map) if !map.is_empty() => {
            let mut first = true;
            for (k, v) in map {
                let mut nested = String::new();
                entry(&mut nested, indent + 2, k, v);
                if first {
                    out.push_str(&format!("{pad}- {}", &nested[indent + 2..]));
                    first = false;
                } else {
                    out.push_str(&nested);
                }
            }
        }
        Value::Array(items) if items.iter().any(|v| v.is_object() || v.is_array()) => {
            out.push_str(&format!("{pad}-\n"));
            for item in items {
                list_item(out, indent + 2, item);
            }
        }
        Value::String(s) if s.contains('\n') && block_safe(s) => {
            out.push_str(&format!("{pad}- {}\n", block_header(s)));
            block_body(out, indent + 2, s);
        }
        _ => out.push_str(&format!("{pad}- {}\n", inline(value))),
    }
}

/// `|` keeps one final newline, `|-` none, `|+` several.
fn block_header(s: &str) -> &'static str {
    let trailing = s.len() - s.trim_end_matches('\n').len();
    match trailing {
        0 => "|-",
        1 => "|",
        _ => "|+",
    }
}

fn block_body(out: &mut String, indent: usize, s: &str) {
    let pad = " ".repeat(indent);
    let content = s.trim_end_matches('\n');
    for line in content.split('\n') {
        if line.is_empty() {
            out.push('\n');
        } else {
            // Whitespace-only lines are written indented too, so their spaces survive.
            out.push_str(&format!("{pad}{line}\n"));
        }
    }
    let trailing = s.len() - content.len();
    if trailing > 1 {
        out.push_str(&"\n".repeat(trailing - 1));
    }
}

/// A block scalar can carry the string: no leading space on the first line (it would set the
/// indentation), no carriage returns or tabs at line starts, and text before the trailing newlines.
fn block_safe(s: &str) -> bool {
    let content = s.trim_end_matches('\n');
    // The first non-blank line sets the block's indentation, so it can't start with a space.
    let first = content.split('\n').find(|l| !l.is_empty());
    !content.is_empty()
        && first.is_some_and(|l| !l.starts_with([' ', '\t']))
        && !s.contains('\r')
        && !content.split('\n').any(|l| l.starts_with('\t'))
        // A last line of only spaces would read back as a trailing blank line.
        && content.rsplit('\n').next().is_some_and(|l| !l.trim_start_matches(' ').is_empty())
        && s.chars().all(|c| c == '\n' || c == '\t' || !c.is_control())
}

fn inline(value: &Value) -> String {
    match value {
        Value::Array(items) => format!("[{}]", items.iter().map(inline).collect::<Vec<_>>().join(", ")),
        Value::Object(map) if map.is_empty() => "{}".into(),
        Value::Object(map) => format!(
            "{{{}}}",
            map.iter()
                .map(|(k, v)| format!("{}: {}", quote(k), inline(v)))
                .collect::<Vec<_>>()
                .join(", ")
        ),
        _ => scalar(value),
    }
}

fn scalar(value: &Value) -> String {
    match value {
        Value::Null => "null".into(),
        Value::Bool(b) => b.to_string(),
        // Integers the reader keeps exact (i64) as digits; anything larger in the float spelling it reads back.
        Value::Number(n) if n.as_i64().is_some() => canon::json(value),
        Value::Number(n) => {
            let f = n.as_f64().unwrap_or(0.0);
            let text = canon::number(f);
            if text.contains(['.', 'e']) {
                text
            } else {
                format!("{text}.0")
            }
        }
        Value::String(s) if plain_safe(s) => s.clone(),
        Value::String(s) => quote(s),
        _ => inline(value),
    }
}

fn key_text(key: &str) -> String {
    if plain_safe(key) && !key.contains(['[', ']', '{', '}', ',']) {
        key.to_string()
    } else {
        quote(key)
    }
}

/// A JSON string literal is a valid double-quoted YAML scalar.
fn quote(s: &str) -> String {
    Value::String(s.into()).to_string()
}

/// A string reads back as itself when written plain.
fn plain_safe(s: &str) -> bool {
    !s.is_empty()
        && s.trim() == s
        && !s.starts_with([
            '-', '?', ':', ',', '[', ']', '{', '}', '#', '&', '*', '!', '|', '>', '\'', '"', '%', '@', '`', '~',
        ])
        && !s.contains(": ")
        && !s.contains(" #")
        && !s.ends_with(':')
        && !s.contains([',', '[', ']', '{', '}'])
        && !s.chars().any(|c| c.is_control())
        && front_matter::resolve(s, 0).ok() == Some(Value::String(s.into()))
}
