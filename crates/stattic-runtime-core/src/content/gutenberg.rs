//! Serialized Gutenberg block rendering: the shared comment-token walker, the
//! static-renderer allowlist, and the files-mode document shell.

use serde_json::{json, Map, Value};

use super::support::{escape_html, first_heading, string_in_opt, title_from_path};
use super::Page;
use crate::finalize::{invalid, Result};

pub(super) fn block_page(
    path: &str,
    source: &str,
    site_meta: Option<&Map<String, Value>>,
    _metadata: &Map<String, Value>,
    diagnostics: &mut Vec<Value>,
) -> Page {
    let content = render_serialized_blocks(source, path, false, diagnostics)
        .expect("lenient Gutenberg rendering cannot reject a document");
    let title = first_heading(&content).unwrap_or_else(|| title_from_path(path));
    Page {
        source_path: path.into(),
        output_path: path.into(),
        content,
        title,
        description: string_in_opt(site_meta, "description"),
        image: string_in_opt(site_meta, "image"),
        date: None,
        layout: None,
        draft: false,
        layout_rendered: false,
    }
}

pub(super) fn render_files_mode_gutenberg(path: &str, source: &str) -> Result<String> {
    render_serialized_blocks(source, path, true, &mut Vec::new())
}

fn render_serialized_blocks(
    source: &str,
    path: &str,
    strict: bool,
    diagnostics: &mut Vec<Value>,
) -> Result<String> {
    let mut output = String::new();
    let mut stack = Vec::<String>::new();
    let mut cursor = 0;
    let mut named = false;
    while let Some(relative_start) = source[cursor..].find("<!--") {
        let start = cursor + relative_start;
        let Some(relative_end) = source[start + 4..].find("-->") else {
            break;
        };
        let end = start + 4 + relative_end + 3;
        let token_body = source[start + 4..end - 3].trim();
        let Some(token) = parse_gutenberg_token(token_body) else {
            if strict && stack.is_empty() && !source[cursor..end].trim().is_empty() {
                return invalid(
                    "gutenberg_freeform_html_unsupported",
                    format!("Files-mode Gutenberg document {path} contains freeform HTML."),
                );
            }
            output.push_str(&source[cursor..end]);
            cursor = end;
            continue;
        };
        let between = &source[cursor..start];
        if strict && stack.is_empty() && !between.trim().is_empty() {
            return invalid(
                "gutenberg_freeform_html_unsupported",
                format!("Files-mode Gutenberg document {path} contains freeform HTML."),
            );
        }
        output.push_str(between);
        let name = if token.name.contains('/') {
            token.name.to_string()
        } else {
            format!("core/{}", token.name)
        };
        if token.closing {
            if stack.pop().as_deref() != Some(name.as_str()) {
                if strict {
                    return invalid(
                        "gutenberg_blocks_required",
                        format!("Files-mode Gutenberg document {path} has unbalanced blocks."),
                    );
                }
                diagnostics.push(json!({"code":"block_parse_invalid","severity":"warning","message":"Serialized Gutenberg blocks are unbalanced; inner content was rendered best-effort.","path":path,"details":{"block":name}}));
            }
        } else {
            named = true;
            if (strict && name == "core/html") || !gutenberg_block_allowed(&name) {
                if strict {
                    return invalid(
                        "gutenberg_block_unsupported",
                        format!(
                            "Files-mode Gutenberg document {path} uses unsupported block {name}."
                        ),
                    );
                }
                diagnostics.push(json!({"code":"block_unsupported","severity":"warning","message":"A Gutenberg block has no static renderer; its inner content was rendered best-effort.","path":path,"details":{"block":name}}));
            }
            if token.self_closing {
                match name.as_str() {
                    "core/separator" => output.push_str("<hr class=\"wp-block-separator\">"),
                    "core/spacer" => output
                        .push_str("<div class=\"wp-block-spacer\" aria-hidden=\"true\"></div>"),
                    _ => {}
                }
            } else {
                stack.push(name);
            }
        }
        cursor = end;
    }
    let tail = &source[cursor..];
    if strict && stack.is_empty() && !tail.trim().is_empty() {
        if !named {
            return invalid(
                "gutenberg_blocks_required",
                format!("Files-mode Gutenberg document {path} requires named blocks."),
            );
        }
        return invalid(
            "gutenberg_freeform_html_unsupported",
            format!("Files-mode Gutenberg document {path} contains freeform HTML."),
        );
    }
    output.push_str(tail);
    if strict && (!named || !stack.is_empty()) {
        return invalid(
            "gutenberg_blocks_required",
            format!("Files-mode Gutenberg document {path} requires balanced named blocks."),
        );
    }
    if !strict && !stack.is_empty() {
        diagnostics.push(json!({"code":"block_parse_invalid","severity":"warning","message":"Serialized Gutenberg blocks are unbalanced; inner content was rendered best-effort.","path":path}));
    }
    Ok(output)
}

struct GutenbergToken<'a> {
    name: &'a str,
    closing: bool,
    self_closing: bool,
}

fn parse_gutenberg_token(value: &str) -> Option<GutenbergToken<'_>> {
    let (closing, value) = value
        .strip_prefix("/wp:")
        .map(|value| (true, value))
        .or_else(|| value.strip_prefix("wp:").map(|value| (false, value)))?;
    let value = value.trim();
    let self_closing = !closing && value.ends_with('/');
    let value = value.strip_suffix('/').unwrap_or(value).trim_end();
    let name_end = value.find(char::is_whitespace).unwrap_or(value.len());
    let name = &value[..name_end];
    (!name.is_empty()
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'/')))
    .then_some(GutenbergToken {
        name,
        closing,
        self_closing,
    })
}

fn gutenberg_block_allowed(name: &str) -> bool {
    matches!(
        name,
        "core/buttons"
            | "core/button"
            | "core/code"
            | "core/column"
            | "core/columns"
            | "core/details"
            | "core/gallery"
            | "core/group"
            | "core/heading"
            | "core/html"
            | "core/image"
            | "core/list"
            | "core/list-item"
            | "core/paragraph"
            | "core/preformatted"
            | "core/pullquote"
            | "core/quote"
            | "core/separator"
            | "core/spacer"
            | "core/table"
            | "core/verse"
    )
}

pub(super) fn gutenberg_document_shell(title: &str, body: &str) -> String {
    format!(
        "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{}</title><style>body{{margin:0;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:#111;background:#fff;line-height:1.6}}.wp-site-blocks{{max-width:760px;margin:0 auto;padding:48px 20px}}.alignwide{{max-width:1040px;margin-left:calc(50% - 520px);margin-right:calc(50% - 520px)}}img,video{{max-width:100%;height:auto}}.wp-block-columns{{display:flex;gap:24px}}.wp-block-column{{flex:1}}.wp-block-button__link{{display:inline-block;padding:10px 16px;border-radius:4px;background:#111;color:#fff;text-decoration:none}}@media(max-width:720px){{.wp-block-columns{{display:block}}.alignwide{{max-width:100%;margin-left:0;margin-right:0}}}}</style></head><body><main class=\"wp-site-blocks\"><article class=\"stattic-block-document\">{body}</article></main></body></html>",
        escape_html(title)
    )
}
