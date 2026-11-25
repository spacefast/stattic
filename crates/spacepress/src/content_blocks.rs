//! Components in Markdown (docs/decisions/2026-11-13-components-in-markdown.md).
//!
//! A server component in `sp/components/` whose file says `export const blocks = ["Callout"]`
//! is one content may use. Each one becomes a dynamic block, `<app>/<kebab-name>`: the editor
//! inserts it, a Markdown file writes it as `<Callout type="warning">…</Callout>`, and the site
//! renders it through the component. Its attributes are the component's props, typed from the
//! component's own annotation; `children` is the block's inner content.
//!
//! Only props a block attribute can hold are allowed: text (or a union of text literals, which
//! becomes an `enum`), numbers and booleans, each optional or required. Anything else (an object,
//! a function, an array) can't be written in a Markdown tag and is a build error at the component.

use crate::diag::{Diag, Span};
use crate::types::Ty;
use serde_json::{Map, Value, json};

/// One block a Markdown page may use.
#[derive(Debug, Clone)]
pub struct Block {
    /// `<app>/<kebab-name>`.
    pub name: String,
    /// The tag a Markdown file writes: the component's export name.
    pub tag: String,
    /// `sp/components/x.tsx#Name`, the key of `schema.components`.
    pub component: String,
    /// The block's attributes, as `register_block_type` takes them.
    pub attributes: Map<String, Value>,
    /// Props a tag must give.
    pub required: Vec<String>,
    /// Whether the component places `children`: a block with inner content.
    pub children: bool,
}

/// `Callout` → `callout`, `CodeGroup` → `code-group`.
pub fn kebab(name: &str) -> String {
    let mut out = String::new();
    for (i, c) in name.chars().enumerate() {
        if c.is_ascii_uppercase() {
            if i > 0 {
                out.push('-');
            }
            out.push(c.to_ascii_lowercase());
        } else {
            out.push(c);
        }
    }
    out
}

/// The block for a component, from its compiled props type; an error names the prop a block can't
/// hold.
pub fn block(app: &str, component: &str, props: &Ty, span: Span) -> Result<Block, Diag> {
    let tag = component.rsplit('#').next().unwrap_or(component).to_string();
    let fields = match props {
        Ty::Obj(fields) => fields.clone(),
        _ => Default::default(),
    };
    let mut attributes = Map::new();
    let mut required = Vec::new();
    let mut children = false;
    for (name, (ty, optional)) in fields {
        if name == "children" {
            children = true;
            continue;
        }
        let (base, nullable) = split_nullable(&ty);
        let attribute = match &base {
            Ty::Str => json!({ "type": "string" }),
            Ty::Num => json!({ "type": "number" }),
            Ty::Bool => json!({ "type": "boolean" }),
            Ty::Union(items) if items.iter().all(|t| matches!(t, Ty::StrLit(_) | Ty::FreshStrLit(_))) => {
                let values: Vec<Value> = items
                    .iter()
                    .filter_map(|t| match t {
                        Ty::StrLit(s) | Ty::FreshStrLit(s) => Some(Value::String(s.clone())),
                        _ => None,
                    })
                    .collect();
                json!({ "type": "string", "enum": values })
            }
            Ty::StrLit(s) | Ty::FreshStrLit(s) => json!({ "type": "string", "enum": [s] }),
            other => {
                return Err(Diag::new(
                    span,
                    format!(
                        "<{tag}>'s prop `{name}` is {}, which a Markdown tag can't write",
                        other.describe()
                    ),
                )
                .help("a block's props are text, a choice of text literals, numbers and booleans; pass anything else from the page"));
            }
        };
        if !optional && !nullable {
            required.push(name.clone());
        }
        attributes.insert(name, attribute);
    }
    Ok(Block {
        name: format!("{app}/{}", kebab(&tag)),
        tag,
        component: component.to_string(),
        attributes,
        required,
        children,
    })
}

/// `T | null | undefined` → (T, true).
fn split_nullable(ty: &Ty) -> (Ty, bool) {
    match ty {
        Ty::Union(items) => {
            let rest: Vec<Ty> = items
                .iter()
                .filter(|t| !matches!(t, Ty::Null | Ty::Undefined))
                .cloned()
                .collect();
            let nullable = rest.len() != items.len();
            let base = if rest.len() == 1 {
                rest[0].clone()
            } else {
                Ty::Union(rest)
            };
            (base, nullable)
        }
        other => (other.clone(), false),
    }
}

/// The `blocks` entry of `schema.php`.
pub fn schema_php(blocks: &[Block]) -> String {
    use crate::compile::{php_json, php_str};
    let mut out = String::from("    ],\n    'blocks' => [\n");
    for b in blocks {
        out.push_str(&format!(
            "        {} => ['tag' => {}, 'component' => {}, 'attributes' => {}, 'required' => {}, 'children' => {}],\n",
            php_str(&b.name),
            php_str(&b.tag),
            php_str(&b.component),
            php_json(&Value::Object(b.attributes.clone())),
            php_json(&json!(b.required)),
            b.children
        ));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_tag_is_its_kebab_case_block_name() {
        assert_eq!(kebab("Callout"), "callout");
        assert_eq!(kebab("CodeGroup"), "code-group");
    }
}
