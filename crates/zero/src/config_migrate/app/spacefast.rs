//! Native WordPress posts become a post-backed collection with their original IDs.
use super::super::{Entry, Failure, entry, fail, pointer};
use serde_json::Value;

pub(super) fn posts(value: &Value, entries: &mut Vec<Entry>) -> Result<String, Failure> {
    let at = "/collections/posts";
    let options = value
        .as_object()
        .ok_or_else(|| fail(at, "expected native posts options"))?;
    for key in options.keys() {
        if !["label", "publicRead", "fields", "sync"].contains(&key.as_str()) {
            return Err(fail(&pointer(at, key), "native posts option needs an explicit port"));
        }
    }
    let mut title = "__z08Text().notNull()".to_string();
    if let Some(fields) = options.get("fields") {
        let fields = fields
            .as_object()
            .ok_or_else(|| fail(&pointer(at, "fields"), "expected native fields"))?;
        for (name, field) in fields {
            let ptr = pointer(&pointer(at, "fields"), name);
            let field = field
                .as_object()
                .ok_or_else(|| fail(&ptr, "expected field declaration"))?;
            for key in field.keys() {
                if !["kind", "required", "maxLength", "label", "multiline"].contains(&key.as_str()) {
                    return Err(fail(&pointer(&ptr, key), "native field option needs an explicit port"));
                }
            }
            match (name.as_str(), field.get("kind").and_then(Value::as_str)) {
                ("title", Some("text")) => {
                    if let Some(max) = field.get("maxLength") {
                        let max = max
                            .as_u64()
                            .filter(|max| *max > 0)
                            .ok_or_else(|| fail(&pointer(&ptr, "maxLength"), "expected positive text bound"))?;
                        title.push_str(&format!(".max({max})"));
                    }
                }
                ("content", Some("richText" | "blocks" | "text")) => {}
                _ => {
                    return Err(fail(
                        &ptr,
                        "native posts currently adopt title and content; port other fields explicitly",
                    ));
                }
            }
        }
    }
    let public = match options.get("publicRead") {
        None => true,
        Some(Value::Bool(value)) => *value,
        _ => return Err(fail(&pointer(at, "publicRead"), "expected boolean")),
    };
    entries.push(entry(at,"mapped","Adopt native posts into the posts collection, keeping IDs, authors, body, dates and revisions. Spacefast copies fenced rows into its scoped prefix before activation."));
    if options.contains_key("sync") {
        entries.push(entry(
            &pointer(at, "sync"),
            "review",
            "Legacy content sync is dropped. Use v2 collection operations; hosted static pages are not rebuilt.",
        ));
    }
    Ok(format!(
        "const __z08Posts = __z08Collection({{title: {title}, content: __z08RichText()}}, {{title: \"title\", content: \"content\", access: {{read: {public}, create: false, update: false, delete: false}}}});\n"
    ))
}
