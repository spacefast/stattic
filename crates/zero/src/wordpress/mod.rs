//! WordPress from TypeScript (`"php/wordpress"`): abilities, and the curated hook catalog that
//! `wp-hooks/` handlers are typed against. docs/decisions/2026-11-07-wordpress-from-typescript.md.

pub mod abilities;

use crate::types::Ty;
use indexmap::IndexMap;
use serde_json::Value;
use std::sync::OnceLock;

/// The pinned, generated catalog (scripts/wordpress-catalog.py).
const CATALOG: &str = include_str!("catalog.json");

/// One catalog hook: an action passes its arguments, a filter must return its first one's type.
#[derive(Debug, Clone)]
pub struct Hook {
    pub filter: bool,
    pub params: Vec<(String, Ty)>,
    /// What a filter returns: its first parameter's type.
    pub returns: Ty,
}

fn catalog() -> &'static Value {
    static PARSED: OnceLock<Value> = OnceLock::new();
    PARSED.get_or_init(|| serde_json::from_str(CATALOG).expect("catalog.json is generated JSON"))
}

/// The catalog's hook names, in catalog order.
pub fn hook_names() -> impl Iterator<Item = &'static str> {
    catalog()["hooks"]
        .as_object()
        .expect("hooks")
        .keys()
        .map(String::as_str)
}

pub fn hook(name: &str) -> Option<Hook> {
    let h = catalog()["hooks"].get(name)?;
    let params: Vec<(String, Ty)> = h["params"]
        .as_array()
        .expect("params")
        .iter()
        .map(|p| {
            (
                p["name"].as_str().expect("name").to_string(),
                ts(p["type"].as_str().expect("type")),
            )
        })
        .collect();
    let filter = h["kind"] == "filter";
    Some(Hook {
        filter,
        returns: if filter { params[0].1.clone() } else { Ty::Void },
        params,
    })
}

/// One admitted WordPress function: what the author passes, what it returns, and the arguments the
/// catalog fixes after them (`wp_insert_post`'s `wp_error: true`).
#[derive(Debug, Clone)]
pub struct Function {
    pub params: Vec<(String, Ty)>,
    pub returns: Ty,
    pub fixed: Vec<Value>,
}

pub fn function_names() -> impl Iterator<Item = &'static str> {
    catalog()["functions"]
        .as_object()
        .expect("functions")
        .keys()
        .map(String::as_str)
}

pub fn function(name: &str) -> Option<Function> {
    let f = catalog()["functions"].get(name)?;
    Some(Function {
        params: f["params"]
            .as_array()
            .expect("params")
            .iter()
            .map(|p| {
                (
                    p["name"].as_str().expect("name").to_string(),
                    ts(p["type"].as_str().expect("type")),
                )
            })
            .collect(),
        returns: ts(f["returns"].as_str().expect("returns")),
        fixed: f["fixed"].as_object().expect("fixed").values().cloned().collect(),
    })
}

/// The admitted public properties of a catalog class, for the runtime's read-only views.
pub fn class_fields(name: &str) -> Vec<&'static str> {
    catalog()["classes"][name]
        .as_object()
        .map(|f| f.keys().filter(|k| !k.starts_with('$')).map(String::as_str).collect())
        .unwrap_or_default()
}

/// The read-only view of a catalog class (`WP_Post`, `WP_User`) or `WP_Query`'s methods.
pub fn class(name: &str) -> Ty {
    let fields = &catalog()["classes"][name];
    if let Some(methods) = fields.get("$methods").and_then(Value::as_object) {
        return Ty::Obj(
            methods
                .iter()
                .map(|(m, sig)| {
                    let params = sig["params"]
                        .as_array()
                        .expect("params")
                        .iter()
                        .map(|p| ts(p["type"].as_str().expect("type")))
                        .collect();
                    (
                        m.clone(),
                        (
                            Ty::Fn(params, Box::new(ts(sig["returns"].as_str().expect("returns")))),
                            false,
                        ),
                    )
                })
                .collect(),
        );
    }
    Ty::Obj(
        fields
            .as_object()
            .expect("class fields")
            .iter()
            .map(|(k, t)| (k.clone(), (ts(t.as_str().expect("type")), false)))
            .collect::<IndexMap<_, _>>(),
    )
}

/// The catalog's TypeScript type strings (a closed grammar the generator writes) as compiler types:
/// primitives, `T[]`, unions, `Record<string, unknown>`, flat object literals and catalog classes.
fn ts(text: &str) -> Ty {
    let text = text.trim();
    if let Some(body) = text.strip_prefix('{').and_then(|t| t.strip_suffix('}')) {
        return Ty::Obj(
            split(body, ';')
                .into_iter()
                .filter(|f| !f.trim().is_empty())
                .map(|f| {
                    let (k, t) = f.split_once(':').expect("field");
                    let k = k.trim();
                    match k.strip_suffix('?') {
                        Some(k) => (k.to_string(), (ts(t), true)),
                        None => (k.to_string(), (ts(t), false)),
                    }
                })
                .collect(),
        );
    }
    let parts = split(text, '|');
    if parts.len() > 1 {
        return Ty::union(parts.into_iter().map(ts));
    }
    if let Some(el) = text.strip_suffix("[]") {
        return Ty::Array(Box::new(ts(el)));
    }
    match text {
        "string" => Ty::Str,
        "number" => Ty::Num,
        "boolean" => Ty::Bool,
        "false" => Ty::BoolLit(false),
        "null" => Ty::Null,
        "void" => Ty::Void,
        "unknown" => Ty::Unknown,
        "Record<string, unknown>" => Ty::Map(Box::new(Ty::Unknown)),
        "WP_Post" | "WP_User" | "WP_Query" => class(text),
        other => panic!("catalog type `{other}` is outside the generator's grammar"),
    }
}

/// Split on `sep` outside braces.
fn split(text: &str, sep: char) -> Vec<&str> {
    let (mut depth, mut start, mut out) = (0, 0, Vec::new());
    for (i, c) in text.char_indices() {
        match c {
            '{' => depth += 1,
            '}' => depth -= 1,
            c if c == sep && depth == 0 => {
                out.push(&text[start..i]);
                start = i + 1;
            }
            _ => {}
        }
    }
    out.push(&text[start..]);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_catalog_hook_and_class_maps_to_a_compiler_type() {
        for name in hook_names() {
            let h = hook(name).unwrap();
            assert!(!h.filter || !h.params.is_empty(), "{name}: a filter passes a value");
        }
        let save = hook("save_post").unwrap();
        assert!(!save.filter);
        assert!(matches!(&save.params[1].1, Ty::Obj(f) if f["post_title"].0 == Ty::Str));
        let insert = function("wp_insert_post").unwrap();
        assert!(matches!(&insert.params[0].1, Ty::Obj(f) if f["post_title"] == (Ty::Str, true)));
        let content = hook("the_content").unwrap();
        assert_eq!(content.returns, Ty::Str);
        assert!(matches!(&hook("pre_get_posts").unwrap().params[0].1, Ty::Obj(f) if f.contains_key("set")));
    }
}
