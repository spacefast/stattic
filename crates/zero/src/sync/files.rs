//! Shared parsing for synced files in source bundles and the CLI.
use super::front_matter;
use serde_json::{Map, Value};

type Parsed = Result<(Map<String, Value>, Option<String>), (usize, String)>;

pub fn md_record(text: &str) -> Parsed {
    let doc = front_matter::parse_document(text).map_err(|e| (e.line, e.message))?;
    Ok((doc.front, (!doc.body.is_empty()).then_some(doc.body)))
}

pub fn json_record(text: &str, content: bool) -> Parsed {
    let value: Value = serde_json::from_str(text).map_err(|e| (e.line(), e.to_string()))?;
    let Value::Object(mut map) = value else {
        return Err((1, "a sync file holds one JSON object".into()));
    };
    // Without a content column, `body` is an ordinary field and stays among the fields.
    if !content {
        return Ok((map, None));
    }
    let body = match map.shift_remove("body") {
        Some(Value::String(s)) if !s.is_empty() => Some(s),
        None | Some(Value::Null) | Some(Value::String(_)) => None,
        Some(_) => return Err((1, "body must be a string or null".into())),
    };
    Ok((map, body))
}
