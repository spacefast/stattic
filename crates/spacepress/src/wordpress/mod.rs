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
    /// The handler may be `async`: SpacePress fires this hook itself and awaits nothing WordPress wouldn't.
    pub async_handler: bool,
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
        async_handler: h["async"] == true,
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

/// What app and plugin code may not do through "php/wordpress": the list `SpacePress\Wp\Guard` enforces
/// at runtime, read here so a literal option name is refused at build time with the same reason.
const GUARD: &str = include_str!("../../runtime/SpacePress/Wp/guard.json");

fn guard() -> &'static Value {
    static PARSED: OnceLock<Value> = OnceLock::new();
    PARSED.get_or_init(|| serde_json::from_str(GUARD).expect("runtime/SpacePress/Wp/guard.json is JSON"))
}

/// `"read"` or `"write"` for a catalog function that takes an option name first, else None.
pub fn option_access(function: &str) -> Option<&'static str> {
    guard()["functions"][function]
        .as_str()
        .filter(|k| matches!(*k, "read" | "write"))
}

/// Why `name` is a protected option, and whether it may still be read; None for an ordinary
/// option. Compared as `SpacePress\Wp\Guard::protects` does: trimmed, lowercased, printable ASCII only.
pub fn protected_option(name: &str) -> Option<(&'static str, bool)> {
    let name = name.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b']);
    if name.bytes().any(|b| !(0x20..=0x7e).contains(&b)) {
        return Some((
            "an option name outside printable ASCII can match a protected one in MySQL",
            false,
        ));
    }
    let name = name.to_ascii_lowercase();
    let hits: Vec<&Value> = guard()["options"]
        .as_array()
        .expect("options")
        .iter()
        .filter(|rule| {
            let want = rule["name"].as_str().expect("name");
            match rule["match"].as_str().expect("match") {
                "exact" => name == want,
                "prefix" => name.starts_with(want),
                "suffix" => name.ends_with(want),
                "contains" => name.contains(want),
                other => panic!("guard.json match `{other}`"),
            }
        })
        .collect();
    // The first rule gives the reason; any secret rule among them makes the option unreadable.
    let first = hits.first()?;
    let secret = hits.iter().find(|rule| rule["read"] != true);
    Some(match secret {
        Some(rule) if first["read"] == true => (rule["why"].as_str().expect("why"), false),
        _ => (first["why"].as_str().expect("why"), first["read"] == true),
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
        literal if literal.len() > 1 && literal.starts_with('"') && literal.ends_with('"') => {
            Ty::StrLit(literal[1..literal.len() - 1].into())
        }
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

    #[test]
    fn spacepress_own_hooks_are_catalog_entries() {
        // In the catalog, so a misspelt file name is offered it ("did you mean").
        let names: Vec<&str> = hook_names().collect();
        assert_eq!(
            crate::diag::suggest("spacepress_woo_order_stok", names.iter().copied()).as_deref(),
            Some("spacepress_woo_order_stock")
        );
        let stock = hook("spacepress_woo_order_stock").unwrap();
        assert!(!stock.filter && stock.async_handler);
        assert_eq!(
            stock.params[3],
            (
                "reason".to_string(),
                Ty::union([Ty::StrLit("sale".into()), Ty::StrLit("return".into())])
            )
        );
        // WordPress calls its own hooks synchronously, so none of core's admits an async handler.
        assert!(
            names
                .iter()
                .all(|n| *n == "spacepress_woo_order_stock" || !hook(n).unwrap().async_handler)
        );
    }

    #[test]
    fn the_guard_covers_every_option_function_and_no_refused_one_is_admitted() {
        for name in function_names() {
            let takes_option = function(name)
                .unwrap()
                .params
                .first()
                .is_some_and(|(p, _)| p == "option");
            assert_eq!(takes_option, option_access(name).is_some(), "{name}");
        }
        for name in guard()["neverAdmitted"].as_array().unwrap() {
            assert!(
                function(name.as_str().unwrap()).is_none(),
                "{name} is in the catalog: guard it first"
            );
        }
        assert!(protected_option("active_plugins").is_some_and(|(_, read)| read));
        assert!(protected_option(" Active_Plugins ").is_some());
        assert!(protected_option("wp_user_roles").is_some() && protected_option("sfz_0123_user_roles").is_some());
        assert!(protected_option("jetpack_private_options").is_some_and(|(_, read)| !read));
        assert!(protected_option("sp_runtime_secret").is_some_and(|(_, read)| !read));
        assert!(protected_option("activ\u{e9}_plugins").is_some());
        assert_eq!(protected_option("fns_last_note"), None);
        assert_eq!(protected_option("blogname"), None);
    }
}
