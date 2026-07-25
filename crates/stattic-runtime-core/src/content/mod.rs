//! The content pipeline: markdown and Gutenberg documents rendered to HTML,
//! the `_layout.html` cascade, `theme.json` compilation, and structural HTML
//! decoration (meta tags, injected snippets) for finalized versions.

mod frontmatter;
mod gutenberg;
mod markdown;
mod support;
mod theme_css;

use lol_html::{doc_comments, element, end_tag, rewrite_str, text, RewriteStrSettings};
use regex::Regex;
use serde_json::{json, Map, Value};
use std::cell::{Cell, RefCell};
use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;
use std::rc::Rc;
use std::sync::OnceLock;

use crate::finalize::{invalid, read_bounded, write_generated, FileMeta, FinalizeError, Result};
use gutenberg::{block_page, gutenberg_document_shell, render_files_mode_gutenberg};
use markdown::markdown_page;
use support::{
    first_heading, inject_snippets, marked_block, path_dir, resolve_layout_override, string_in,
    string_in_opt, title_from_path,
};

pub(crate) use support::{escape_attr, escape_html};
use theme_css::compile_theme;

pub(crate) const PIPELINE_SOURCE_MAX_BYTES: usize = 2 * 1024 * 1024;

/// A pipeline-rendered page and the metadata its layout and decoration need.
#[derive(Debug)]
pub(crate) struct Page {
    pub(crate) source_path: String,
    pub(crate) output_path: String,
    pub(crate) content: String,
    pub(crate) title: String,
    pub(crate) description: Option<String>,
    pub(crate) image: Option<String>,
    pub(crate) date: Option<String>,
    pub(crate) layout: Option<String>,
    pub(crate) draft: bool,
    pub(crate) layout_rendered: bool,
}

/// Runs the content pipeline over the committed files, writing generated
/// output through `files`, and returns the source paths that must stay
/// private.
pub fn materialize_html_pipeline(
    files_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
    serving: &Map<String, Value>,
    metadata: &Map<String, Value>,
    viewer: &Map<String, Value>,
    diagnostics: &mut Vec<Value>,
) -> Result<BTreeSet<String>> {
    let config = serving
        .get("config")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let enabled = config
        .get("experimental_gutenberg")
        .and_then(Value::as_bool)
        .unwrap_or(false);
    let platform_meta = config
        .get("platform_meta")
        .and_then(Value::as_bool)
        .unwrap_or(false);
    let inject = config.get("inject").and_then(Value::as_object);
    let has_inject = inject.is_some_and(|value| {
        value
            .values()
            .any(|v| v.as_array().is_some_and(|a| !a.is_empty()))
    });
    let files_mode_gutenberg = metadata.get("mode").and_then(Value::as_str) == Some("files")
        && [
            metadata
                .get("content")
                .and_then(Value::as_object)
                .and_then(|content| content.get("format"))
                .and_then(Value::as_str),
            metadata.get("content_format").and_then(Value::as_str),
            metadata.get("template").and_then(Value::as_str),
        ]
        .into_iter()
        .flatten()
        .any(|value| value == "gutenberg-blocks");
    if !enabled && !platform_meta && !has_inject && !files_mode_gutenberg {
        return Ok(BTreeSet::new());
    }

    let mut private = BTreeSet::new();
    let mut pages = Vec::new();
    let site_title = config
        .get("meta")
        .and_then(Value::as_object)
        .and_then(|meta| meta.get("title"))
        .and_then(Value::as_str)
        .or_else(|| metadata.get("title").and_then(Value::as_str))
        .unwrap_or("");
    if files_mode_gutenberg {
        let targets: Vec<String> = files
            .keys()
            .filter(|path| {
                let lower = path.to_ascii_lowercase();
                !path.starts_with("__spacefast_generated/")
                    && (lower.ends_with(".html") || lower.ends_with(".htm"))
            })
            .cloned()
            .collect();
        for path in targets {
            if files
                .get(&path)
                .is_some_and(|meta| meta.size > PIPELINE_SOURCE_MAX_BYTES as u64)
            {
                return invalid(
                    "gutenberg_source_too_large",
                    format!("Files-mode Gutenberg document {path} exceeds 2 MiB."),
                );
            }
            let Some(source) = pipeline_text(files_root, &path, diagnostics)? else {
                return invalid(
                    "gutenberg_source_missing",
                    format!("Files-mode Gutenberg document {path} could not be read."),
                );
            };
            let body = render_files_mode_gutenberg(&path, &source)?;
            let title = viewer
                .get("title")
                .and_then(Value::as_str)
                .or_else(|| metadata.get("title").and_then(Value::as_str))
                .map(str::trim)
                .filter(|title| !title.is_empty())
                .map(str::to_string)
                .or_else(|| first_heading(&body))
                .unwrap_or_else(|| title_from_path(&path));
            let document = gutenberg_document_shell(&title, &body);
            write_generated(
                files_root,
                files,
                &path,
                document.as_bytes(),
                Some("text/html; charset=utf-8"),
            )?;
        }
    }
    if enabled {
        let paths: Vec<String> = files.keys().cloned().collect();
        for path in &paths {
            let lower = path.to_ascii_lowercase();
            let basename = path.rsplit('/').next().unwrap_or(path);
            if basename.starts_with('_')
                || basename.starts_with('.')
                || lower.ends_with(".md")
                || lower.ends_with(".markdown")
                || path == "theme.json"
            {
                private.insert(path.clone());
            }
            if lower.ends_with(".md") || lower.ends_with(".markdown") {
                let Some(source) = pipeline_text(files_root, path, diagnostics)? else {
                    continue;
                };
                match markdown_page(path, &source, config.get("meta").and_then(Value::as_object), metadata, diagnostics) {
                    Ok(page) if page.draft => diagnostics.push(json!({"code":"page_draft_skipped","severity":"info","message":"A draft page was skipped.","path":path})),
                    Ok(page) => pages.push(page),
                    Err(message) => diagnostics.push(json!({"code":"markdown_render_failed","severity":"warning","message":message,"path":path})),
                }
            } else if (lower.ends_with(".html") || lower.ends_with(".htm"))
                && !basename.starts_with('_')
            {
                let Some(source) = pipeline_text(files_root, path, diagnostics)? else {
                    continue;
                };
                if source.contains("<!-- wp:") {
                    pages.push(block_page(
                        path,
                        &source,
                        config.get("meta").and_then(Value::as_object),
                        metadata,
                        diagnostics,
                    ));
                }
            }
        }

        for page in &mut pages {
            if files.contains_key(&page.output_path) && page.output_path != page.source_path {
                diagnostics.push(json!({"code":"page_output_conflict","severity":"warning","message":"A generated page would overwrite an uploaded file and was skipped.","path":page.output_path}));
                continue;
            }
            let document = apply_layouts(page, files_root, files, site_title, diagnostics)?;
            page.layout_rendered = true;
            write_generated(
                files_root,
                files,
                &page.output_path,
                document.as_bytes(),
                Some("text/html; charset=utf-8"),
            )?;
        }
        compile_theme(files_root, files, diagnostics)?;
    }

    let page_by_output: BTreeMap<String, &Page> = pages
        .iter()
        .map(|page| (page.output_path.clone(), page))
        .collect();
    let targets: Vec<String> = files
        .keys()
        .filter(|path| {
            let lower = path.to_ascii_lowercase();
            (lower.ends_with(".html") || lower.ends_with(".htm"))
                && !path.starts_with("__spacefast_generated/")
                && !private.contains(*path)
                && !path.rsplit('/').next().unwrap_or(path).starts_with('_')
        })
        .cloned()
        .collect();
    for path in targets {
        let Some(source) = pipeline_text(files_root, &path, diagnostics)? else {
            continue;
        };
        let decorated = decorate_html(
            &source,
            HtmlDecorationContext {
                page: page_by_output.get(&path).copied(),
                config: &config,
                viewer,
                files,
                meta_tags: enabled || platform_meta,
                theme_available: files.contains_key("__spacefast_generated/theme.css"),
                path: &path,
            },
            diagnostics,
        )?;
        if decorated != source {
            write_generated(
                files_root,
                files,
                &path,
                decorated.as_bytes(),
                Some("text/html; charset=utf-8"),
            )?;
        }
    }
    Ok(private)
}

fn apply_layouts(
    page: &Page,
    files_root: &Path,
    files: &BTreeMap<String, FileMeta>,
    site_title: &str,
    diagnostics: &mut Vec<Value>,
) -> Result<String> {
    let mut layouts = layout_chain(page, files);
    if let Some(override_path) = &page.layout {
        let resolved = resolve_layout_override(&page.source_path, override_path);
        if files.contains_key(&resolved) {
            layouts = ancestor_layouts_for_override(&resolved, files);
            layouts.push(resolved);
        } else {
            diagnostics.push(json!({"code":"layout_missing","severity":"warning","message":"The requested layout was not found; the normal layout cascade was used.","path":page.source_path}));
        }
    }
    let mut document = page.content.clone();
    if layouts.is_empty() {
        return Ok(format!("<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{}</title></head><body><main>{}</main></body></html>", escape_html(&page.title), document));
    }
    for layout in layouts.into_iter().rev() {
        let Some(template) = pipeline_text(files_root, &layout, diagnostics)? else {
            continue;
        };
        document = render_layout(&template, page, &document, site_title, diagnostics, &layout);
    }
    Ok(document)
}

fn ancestor_layouts_for_override(
    resolved: &str,
    files: &BTreeMap<String, FileMeta>,
) -> Vec<String> {
    let mut directories = Vec::new();
    let mut current = path_dir(path_dir(resolved)).to_string();
    loop {
        directories.push(current.clone());
        if current.is_empty() {
            break;
        }
        current = path_dir(&current).to_string();
    }
    directories.reverse();
    directories
        .into_iter()
        .map(|directory| {
            if directory.is_empty() {
                "_layout.html".into()
            } else {
                format!("{directory}/_layout.html")
            }
        })
        .filter(|path| path != resolved && files.contains_key(path))
        .collect()
}

fn layout_chain(page: &Page, files: &BTreeMap<String, FileMeta>) -> Vec<String> {
    let mut dirs = Vec::new();
    let mut current = path_dir(&page.source_path).to_string();
    loop {
        dirs.push(current.clone());
        if current.is_empty() {
            break;
        }
        current = path_dir(&current).to_string();
    }
    dirs.reverse();
    dirs.into_iter()
        .map(|dir| {
            if dir.is_empty() {
                "_layout.html".into()
            } else {
                format!("{dir}/_layout.html")
            }
        })
        .filter(|path| files.contains_key(path))
        .collect()
}

fn render_layout(
    template: &str,
    page: &Page,
    content: &str,
    site_title: &str,
    diagnostics: &mut Vec<Value>,
    path: &str,
) -> String {
    let slots = BTreeMap::from([
        ("content", content.to_string()),
        ("page.title", page.title.clone()),
        (
            "page.description",
            page.description.clone().unwrap_or_default(),
        ),
        ("page.date", page.date.clone().unwrap_or_default()),
        ("site.title", site_title.to_string()),
    ]);
    let mut unresolved = BTreeSet::new();
    layout_slot_regex()
        .replace_all(template, |captures: &regex::Captures<'_>| {
            let raw = captures.get(1).is_some();
            let name = captures
                .get(1)
                .or_else(|| captures.get(2))
                .expect("layout slot name")
                .as_str();
            let Some(value) = slots.get(name) else {
                if unresolved.insert(name.to_string()) {
                    diagnostics.push(json!({"code":"layout_unresolved_slot","severity":"warning","message":"A layout slot could not be resolved and was left as-is.","path":path,"details":{"slot":name}}));
                }
                return captures[0].to_string();
            };
            if raw || name == "content" {
                value.clone()
            } else {
                escape_html(value)
            }
        })
        .into_owned()
}

fn layout_slot_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| {
        Regex::new(r"\{\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}\}|\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}")
            .expect("layout slot regex compiles")
    })
}

fn pipeline_text(
    files_root: &Path,
    path: &str,
    diagnostics: &mut Vec<Value>,
) -> Result<Option<String>> {
    match read_bounded(&files_root.join(path), PIPELINE_SOURCE_MAX_BYTES) {
        Ok(bytes) => Ok(Some(String::from_utf8_lossy(&bytes).into_owned())),
        Err(FinalizeError::Invalid {
            code: "source_too_large",
            ..
        }) => {
            diagnostics.push(json!({
                "code":"pipeline_source_too_large",
                "severity":"warning",
                "message":"A content-pipeline source exceeds the 2 MiB limit and was skipped.",
                "path":path
            }));
            Ok(None)
        }
        Err(error) => Err(error),
    }
}

struct HtmlDecorationContext<'a> {
    page: Option<&'a Page>,
    config: &'a Map<String, Value>,
    viewer: &'a Map<String, Value>,
    files: &'a BTreeMap<String, FileMeta>,
    meta_tags: bool,
    theme_available: bool,
    path: &'a str,
}

fn cache_busted_local_image(image: &str, files: &BTreeMap<String, FileMeta>) -> String {
    if image.contains('?') || image.contains('#') {
        return image.to_string();
    }
    let Some(path) = image.strip_prefix('/') else {
        return image.to_string();
    };
    let Some(file) = files.get(path) else {
        return image.to_string();
    };
    let digest = file.sha256.strip_prefix("sha256:").unwrap_or(&file.sha256);
    let Some(short) = digest.get(..12) else {
        return image.to_string();
    };
    format!("{image}?v={short}")
}

fn decorate_html(
    source: &str,
    context: HtmlDecorationContext<'_>,
    diagnostics: &mut Vec<Value>,
) -> Result<String> {
    let HtmlDecorationContext {
        page,
        config,
        viewer,
        files,
        meta_tags,
        theme_available,
        path,
    } = context;
    let html = if source.contains("<!-- spacefast:") {
        strip_generated_html_blocks(source)
    } else {
        source.to_string()
    };
    let has_head = Rc::new(Cell::new(false));
    let has_body = Rc::new(Cell::new(false));
    let has_title = Rc::new(Cell::new(false));
    let title_text = Rc::new(RefCell::new(String::new()));
    let meta_names = Rc::new(RefCell::new(BTreeSet::<String>::new()));
    let meta_properties = Rc::new(RefCell::new(BTreeSet::<String>::new()));
    let head_end = Rc::new(Cell::new(None::<usize>));
    let body_start_offset = Rc::new(Cell::new(None::<usize>));
    let body_end_offset = Rc::new(Cell::new(None::<usize>));
    let analysis = rewrite_str(
        &html,
        RewriteStrSettings::new()
            .append_element_content_handler(element!("head", {
                let has_head = Rc::clone(&has_head);
                let head_end = Rc::clone(&head_end);
                move |element| {
                    if has_head.replace(true) {
                        return Ok(());
                    }
                    let head_end = Rc::clone(&head_end);
                    element.on_end_tag(end_tag!(move |end| {
                        head_end.set(Some(end.source_location().bytes().start));
                        Ok(())
                    }))?;
                    Ok(())
                }
            }))
            .append_element_content_handler(element!("body", {
                let has_body = Rc::clone(&has_body);
                let body_start_offset = Rc::clone(&body_start_offset);
                let body_end_offset = Rc::clone(&body_end_offset);
                move |element| {
                    if has_body.replace(true) {
                        return Ok(());
                    }
                    body_start_offset.set(Some(element.source_location().bytes().end));
                    let body_end_offset = Rc::clone(&body_end_offset);
                    element.on_end_tag(end_tag!(move |end| {
                        body_end_offset.set(Some(end.source_location().bytes().start));
                        Ok(())
                    }))?;
                    Ok(())
                }
            }))
            .append_element_content_handler(element!("head title", {
                let has_title = Rc::clone(&has_title);
                move |_| {
                    has_title.set(true);
                    Ok(())
                }
            }))
            .append_element_content_handler(text!("head title", {
                let title_text = Rc::clone(&title_text);
                move |chunk| {
                    title_text.borrow_mut().push_str(chunk.as_str());
                    Ok(())
                }
            }))
            .append_element_content_handler(element!("head meta", {
                let meta_names = Rc::clone(&meta_names);
                let meta_properties = Rc::clone(&meta_properties);
                move |element| {
                    if let Some(name) = element.get_attribute("name") {
                        meta_names
                            .borrow_mut()
                            .insert(name.trim().to_ascii_lowercase());
                    }
                    if let Some(property) = element.get_attribute("property") {
                        meta_properties
                            .borrow_mut()
                            .insert(property.to_ascii_lowercase());
                    }
                    Ok(())
                }
            })),
    );
    if analysis.is_err() {
        diagnostics.push(json!({"code":"html_parse_failed","severity":"warning","message":"An HTML file could not be parsed; decoration was skipped.","path":path}));
        return Ok(source.to_string());
    }
    let existing_title = has_title
        .get()
        .then(|| title_text.borrow().trim().to_string())
        .filter(|value| !value.is_empty());
    let meta = config.get("meta").and_then(Value::as_object);
    let title = page
        .map(|p| p.title.clone())
        .or(existing_title.clone())
        .or_else(|| string_in_opt(meta, "title"))
        .or_else(|| string_in(viewer, "title"));
    let description = page
        .and_then(|p| p.description.clone())
        .or_else(|| string_in_opt(meta, "description"))
        .or_else(|| string_in(viewer, "description"));
    let image = page
        .and_then(|p| p.image.clone())
        .or_else(|| string_in_opt(meta, "image"))
        .or_else(|| string_in(viewer, "og_image_path"))
        .map(|image| cache_busted_local_image(&image, files));
    let mut head = Vec::new();
    if meta_tags {
        if !has_title.get() {
            if let Some(value) = &title {
                head.push(format!("<title>{}</title>", escape_html(value)));
            }
        }
        if !meta_properties.borrow().contains("og:title") {
            if let Some(value) = &title {
                head.push(format!(
                    "<meta property=\"og:title\" content=\"{}\">",
                    escape_attr(value)
                ));
            }
        }
        if !meta_names.borrow().contains("twitter:title") {
            if let Some(value) = &title {
                head.push(format!(
                    "<meta name=\"twitter:title\" content=\"{}\">",
                    escape_attr(value)
                ));
            }
        }
        if !meta_names.borrow().contains("description") {
            if let Some(value) = &description {
                head.push(format!(
                    "<meta name=\"description\" content=\"{}\">",
                    escape_attr(value)
                ));
            }
        }
        if !meta_properties.borrow().contains("og:description") {
            if let Some(value) = &description {
                head.push(format!(
                    "<meta property=\"og:description\" content=\"{}\">",
                    escape_attr(value)
                ));
            }
        }
        if !meta_properties.borrow().contains("og:image") {
            if let Some(value) = &image {
                head.push(format!(
                    "<meta property=\"og:image\" content=\"{}\">",
                    escape_attr(value)
                ));
            }
        }
        if !meta_names.borrow().contains("twitter:card") && (title.is_some() || image.is_some()) {
            head.push(format!(
                "<meta name=\"twitter:card\" content=\"{}\">",
                if image.is_some() {
                    "summary_large_image"
                } else {
                    "summary"
                }
            ));
        }
        if theme_available && page.is_some_and(|p| p.layout_rendered) {
            head.push("<link rel=\"stylesheet\" href=\"/__spacefast_generated/theme.css\">".into());
        }
    }
    head.extend(inject_snippets(config, "head"));
    let head_block = if head.is_empty() {
        String::new()
    } else if has_head.get() {
        marked_block("head", &head)
    } else {
        diagnostics.push(json!({"code":"html_no_head","severity":"warning","message":"An HTML file has no head element; head decoration was skipped.","path":path}));
        String::new()
    };
    let mut body_start = Vec::new();
    let noscript = inject_snippets(config, "noscript");
    if !noscript.is_empty() {
        body_start.push(marked_block("noscript", &noscript));
    }
    let start = inject_snippets(config, "bodyStart");
    if !start.is_empty() {
        body_start.push(marked_block("body-start", &start));
    }
    let body_start = body_start.concat();
    let end = inject_snippets(config, "bodyEnd");
    let body_end = if end.is_empty() {
        String::new()
    } else {
        marked_block("body-end", &end)
    };
    if (!body_start.is_empty() || !body_end.is_empty()) && !has_body.get() {
        diagnostics.push(json!({"code":"html_no_body","severity":"warning","message":"An HTML file has no body element; body decoration was skipped.","path":path}));
    }
    let mut insertions = Vec::<(usize, String)>::new();
    if !head_block.is_empty() {
        if let Some(offset) = head_end.get() {
            insertions.push((offset, head_block));
        }
    }
    if !body_start.is_empty() {
        if let Some(offset) = body_start_offset.get() {
            insertions.push((offset, body_start));
        }
    }
    if !body_end.is_empty() {
        if let Some(offset) = body_end_offset.get() {
            insertions.push((offset, body_end));
        }
    }
    insertions.sort_by_key(|(offset, _)| std::cmp::Reverse(*offset));
    let mut output = html;
    for (offset, block) in insertions {
        output.insert_str(offset, &block);
    }
    Ok(output)
}

fn strip_generated_html_blocks(source: &str) -> String {
    let open = Rc::new(RefCell::new(BTreeMap::<String, usize>::new()));
    let ranges = Rc::new(RefCell::new(Vec::<std::ops::Range<usize>>::new()));
    let open_for_handler = Rc::clone(&open);
    let ranges_for_handler = Rc::clone(&ranges);
    let _ = rewrite_str(
        source,
        RewriteStrSettings::new().append_document_content_handler(doc_comments!(move |comment| {
            let marker = comment.text().trim().to_string();
            let location = comment.source_location().bytes();
            if let Some(name) = marker.strip_prefix("spacefast:") {
                if matches!(name, "head" | "body-start" | "noscript" | "body-end") {
                    open_for_handler
                        .borrow_mut()
                        .entry(name.to_string())
                        .or_insert(location.start);
                }
            } else if let Some(name) = marker.strip_prefix("/spacefast:") {
                if let Some(start) = open_for_handler.borrow_mut().remove(name) {
                    let mut end = location.end;
                    if source.as_bytes().get(end) == Some(&b'\n') {
                        end += 1;
                    }
                    ranges_for_handler.borrow_mut().push(start..end);
                }
            }
            Ok(())
        })),
    );
    let mut output = source.to_string();
    let mut ranges = ranges.borrow().clone();
    ranges.sort_by_key(|range| std::cmp::Reverse(range.start));
    for range in ranges {
        output.replace_range(range, "");
    }
    output
}

// Tests adapted from the donor finalizer's `finalize_site` suite: fixtures
// call `materialize_html_pipeline` directly instead of running the (not yet
// ported) finalize orchestration, and assert against the working files root.
#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::file_meta;
    use std::fs;
    use tempfile::{tempdir, TempDir};

    struct PipelineRun {
        _temp: TempDir,
        files_root: std::path::PathBuf,
        files: BTreeMap<String, FileMeta>,
        result: Result<BTreeSet<String>>,
        diagnostics: Vec<Value>,
    }

    fn run_pipeline(files: &[(&str, &[u8])], metadata: Value, serving: Value) -> PipelineRun {
        let temp = tempdir().unwrap();
        let files_root = temp.path().join("files");
        let mut map = BTreeMap::new();
        for (path, bytes) in files {
            let target = files_root.join(path);
            fs::create_dir_all(target.parent().unwrap()).unwrap();
            fs::write(&target, bytes).unwrap();
            map.insert((*path).to_string(), file_meta(path, bytes, None));
        }
        let serving = serving.as_object().cloned().unwrap_or_default();
        let metadata = metadata.as_object().cloned().unwrap_or_default();
        let viewer = Map::new();
        let mut diagnostics = Vec::new();
        let result = materialize_html_pipeline(
            &files_root,
            &mut map,
            &serving,
            &metadata,
            &viewer,
            &mut diagnostics,
        );
        PipelineRun {
            _temp: temp,
            files_root,
            files: map,
            result,
            diagnostics,
        }
    }

    fn read(run: &PipelineRun, path: &str) -> String {
        fs::read_to_string(run.files_root.join(path)).unwrap()
    }

    fn has_diagnostic(run: &PipelineRun, code: &str) -> bool {
        run.diagnostics
            .iter()
            .any(|diagnostic| diagnostic.get("code") == Some(&json!(code)))
    }

    #[test]
    fn html_decoration_uses_document_structure_and_preserves_user_markup() {
        let source = br#"<!DOCTYPE html><HTML><head data-x='1'><META CONTENT='kept' NAME=description><meta name='twitter:title' content='mine'></head><body class = "original"><svg><title>Not the document title</title></svg><script>const fake = '</head><title>wrong</title><!-- spacefast:head -->';</script><p>Body</p></body></HTML>"#;
        let cover = b"cover-image";
        let run = run_pipeline(
            &[("index.html", source), ("cover.png", cover)],
            json!({"mode":"website"}),
            json!({"config":{
                "platform_meta":true,
                "meta":{"title":"Real title","description":"replacement forbidden","image":"/cover.png"},
                "inject":{"head":["<meta name=\"owned\" content=\"yes\">"],"bodyStart":["<aside>start</aside>"],"bodyEnd":["<aside>end</aside>"]}
            }}),
        );
        run.result.as_ref().unwrap();
        let html = read(&run, "index.html");
        assert!(html.contains("<title>Real title</title>"));
        assert_eq!(html.matches("name=\"description\"").count(), 0);
        assert!(html.contains("<META CONTENT='kept' NAME=description>"));
        assert!(html.contains("const fake = '</head><title>wrong</title><!-- spacefast:head -->'"));
        assert!(html.contains("<body class = \"original\"><!-- spacefast:body-start -->"));
        assert!(html.contains("<!-- spacefast:body-end -->"));
        assert_eq!(html.matches("twitter:title").count(), 1);
        assert!(html.contains("name=\"twitter:card\" content=\"summary_large_image\""));
        assert!(html.contains(&format!(
            "property=\"og:image\" content=\"/cover.png?v={}\"",
            &crate::finalize::sha256(cover)[..12]
        )));
        assert!(!html.contains("/__spacefast_generated/theme.css"));
    }

    #[test]
    fn theme_json_requires_version_three_and_links_only_committed_css() {
        let valid_theme = br##"{"version":3,"settings":{"color":{"palette":[{"slug":"accent","color":"#f00"}]},"typography":{"fontFamilies":[{"slug":"body","fontFamily":"Inter, sans-serif"}]}},"styles":{"typography":{"fontFamily":"var(--wp--preset--font-family--body)"}}}"##;
        let run = run_pipeline(
            &[
                ("page.md", b"---\ntitle: Themed\n---\n\n# Hello"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", valid_theme),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let css = read(&run, "__spacefast_generated/theme.css");
        assert!(css.contains("--wp--preset--color--accent: #f00"));
        assert!(css.contains("--wp--preset--font-family--body: Inter, sans-serif"));
        assert!(css.contains("body{font-family:var(--wp--preset--font-family--body)}"));
        let page = read(&run, "page/index.html");
        assert!(page.contains("/__spacefast_generated/theme.css"));

        let run = run_pipeline(
            &[
                (
                    "index.html",
                    b"<html><head></head><body>plain</body></html>",
                ),
                ("theme.json", br#"{"version":2}"#),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        assert!(has_diagnostic(&run, "theme_json_invalid"));
        assert!(!run
            .files_root
            .join("__spacefast_generated/theme.css")
            .exists());
        assert!(!run.files.contains_key("__spacefast_generated/theme.css"));
        let page = read(&run, "index.html");
        assert!(!page.contains("/__spacefast_generated/theme.css"));
    }

    #[test]
    fn theme_json_compiles_presets_scoped_elements_states_and_common_styles() {
        let theme = br##"{
            "version":3,
            "settings":{
                "color":{
                    "palette":[{"slug":"accent","color":"#c00"}],
                    "gradients":[{"slug":"sunset","gradient":"linear-gradient(#c00,#fc0)"}],
                    "duotone":[{"slug":"ink","colors":["#000","#fff"]}]
                },
                "border":{"radiusSizes":[{"slug":"round","size":"8px"}]},
                "dimensions":{"aspectRatios":[{"slug":"wide","ratio":"16/9"}],"dimensionSizes":[{"slug":"content","size":"42rem"}]},
                "typography":{
                    "fluid":{"minViewportWidth":"20em","maxViewportWidth":"100em","minFontSize":"0.875em"},
                    "fontFamilies":[{"slug":"display","fontFamily":"Georgia, serif"}],
                    "fontSizes":[{"slug":"large","size":"2rem"},{"slug":"fluid","size":"3rem","fluid":{"min":"1.5rem","max":"3rem"}},{"slug":"derived-em","size":"2em"}]
                },
                "spacing":{"spacingSizes":[{"slug":"40","size":"1rem"}]},
                "shadow":{"presets":[{"slug":"raised","shadow":"0 2px 8px #0003"}]},
                "custom":{"lineHeight":{"tight":"1.1"},"hero":{"url":"/media/referenced.jpg","id":99},"referencedColor":"#0a0"}
            },
            "styles":{
                "color":{"background":"var:preset|color|accent"},
                "css":"& .site-note { opacity:.8; }",
                "spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"2rem"}},
                "position":{"type":"sticky","top":"0"},
                "typography":{"fontFamily":"var:preset|font-family|display","fontVariant":"small-caps","fontVariationSettings":"'wght' 700","textShadow":"1px 1px #000"},
                "elements":{
                    "link":{
                        "color":{"text":"var:preset|color|accent"},
                        ":any-link":{"typography":{"fontWeight":"600"}},
                        ":hover":{"typography":{"textDecoration":"none"}},
                        ":focus-visible":{"outline":{"width":"2px","style":"solid"}}
                    },
                    "button":{"border":{"radius":"4px"}},
                    "cite":{"typography":{"fontStyle":"italic"}},
                    "textInput":{"color":{"text":"#123"}},
                    "select":{"border":{"width":"1px"}}
                },
                "blocks":{
                    "core/quote":{
                        "border":{"left":{"color":"var:preset|color|accent","style":"solid","width":"3px"}},
                        "spacing":{"margin":{"top":"1rem"}},
                        "elements":{"link":{":visited":{"color":{"text":"#606"}}}},
                        "variations":{"pull":{"shadow":"var:preset|shadow|raised"}}
                    },
                    "core/button":{"color":{"text":"#fff"},":hover":{"color":{"text":"#eee"}},":focus-visible":{"outline":{"style":"solid","width":"3px"}},"css":"&:focus-visible { outline:2px solid red; }","variations":{"outline":{":active":{"color":{"text":"#111"}}}}},
                    "core/group":{"background":{"backgroundImage":{"url":"/media/hero.jpg","id":42},"backgroundAttachment":"fixed"},"dimensions":{"height":"20rem","width":"100%"},"typography":{"textColumns":"2"}},
                    "core/cover":{"background":{"backgroundImage":{"url":"/media/cover.jpg","id":7},"backgroundSize":"contain"},"dimensions":{"aspectRatio":"16/9"}},
                    "core/image":{"background":{"backgroundImage":{"ref":"settings.custom.hero"}}},
                    "core/media-text":{"background":{"backgroundImage":{"url":"/media/direct.jpg","ref":"settings.custom.hero"}}},
                    "core/gallery":{"background":{"backgroundImage":{"id":9}}},
                    "core/columns":{"color":{"text":{"ref":"settings.custom.referencedColor"}},"spacing":{"blockGap":{"top":"2rem","left":"1rem"}}}
                }
            }
        }"##;
        let run = run_pipeline(
            &[
                ("page.md", b"# Theme corpus"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", theme),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let css = read(&run, "__spacefast_generated/theme.css");
        assert!(css.contains("--wp--preset--gradient--sunset: linear-gradient(#c00,#fc0)"));
        assert!(css.contains("--wp--preset--spacing--40: 1rem"));
        assert!(css.contains("--wp--preset--shadow--raised: 0 2px 8px #0003"));
        assert!(css.contains("--wp--preset--border-radius--round: 8px"));
        assert!(css.contains("--wp--preset--aspect-ratio--wide: 16/9"));
        assert!(css.contains("--wp--preset--dimension--content: 42rem"));
        assert!(!css.contains("--wp--preset--duotone"));
        assert!(!css.contains(".has-raised-box-shadow"));
        assert!(!css.contains(".has-wide-aspect-ratio"));
        assert!(css.contains(
            "--wp--preset--font-size--fluid: clamp(1.5rem, 1.5rem + ((1vw - 0.2rem) * 1.875), 3rem)"
        ));
        assert!(css.contains("--wp--preset--font-size--derived-em: clamp(1.25em, 1.25rem + ((1vw - 0.2em) * 0.938), 2em)"));
        assert!(css.contains("--wp--custom--line-height--tight: 1.1"));
        assert!(css.contains(".has-sunset-gradient-background{background:var(--wp--preset--gradient--sunset)!important}"));
        assert!(css.contains(
            ".has-large-font-size{font-size:var(--wp--preset--font-size--large)!important}"
        ));
        assert!(css.contains("body{background-color:var(--wp--preset--color--accent);padding-top:var(--wp--preset--spacing--40);padding-bottom:2rem;font-family:var(--wp--preset--font-family--display)}"));
        assert!(css
            .contains("a:where(:not(.wp-element-button)){color:var(--wp--preset--color--accent)}"));
        assert!(css.contains("a:where(:not(.wp-element-button)):hover{text-decoration:none}"));
        assert!(css.contains("a:where(:not(.wp-element-button)):any-link{font-weight:600}"));
        assert!(css.contains("a:where(:not(.wp-element-button)):focus-visible{outline-style:solid;outline-width:2px}"));
        assert!(css.contains(
            ".has-accent-border-color{border-color:var(--wp--preset--color--accent)!important}"
        ));
        assert!(css.contains(".wp-element-button,.wp-block-button__link{border-radius:4px}"));
        assert!(css.contains("cite{font-style:italic}"));
        assert!(css.contains("textarea,input:where([type=email],[type=number],[type=password],[type=search],[type=text],[type=tel],[type=url]){color:#123}"));
        assert!(css.contains("select{border-width:1px}"));
        assert!(css.contains(".wp-block-quote{border-left-color:var(--wp--preset--color--accent);border-left-style:solid;border-left-width:3px;margin-top:1rem}"));
        assert!(
            css.contains(".wp-block-quote a:where(:not(.wp-element-button)):visited{color:#606}")
        );
        assert!(css.contains(
            ".wp-block-quote.is-style-pull{box-shadow:var(--wp--preset--shadow--raised)}"
        ));
        assert!(css.contains("body .site-note { opacity:.8; }"));
        assert!(css.contains(".wp-block-button .wp-block-button__link{color:#fff}"));
        assert!(css.contains(".wp-block-button .wp-block-button__link:hover{color:#eee}"));
        assert!(css.contains(".wp-block-button .wp-block-button__link:focus-visible{outline-style:solid;outline-width:3px}"));
        assert!(css.contains(
            ".wp-block-button.is-style-outline .wp-block-button__link:active{color:#111}"
        ));
        assert!(css.contains(
            ".wp-block-button .wp-block-button__link:focus-visible { outline:2px solid red; }"
        ));
        assert!(css.contains(".wp-block-group{background-image:url('/media/hero.jpg');background-attachment:fixed;background-size:cover;height:20rem;width:100%;column-count:2}"));
        assert!(css.contains(".wp-block-cover{background-image:url('/media/cover.jpg');background-position:50% 50%;background-size:contain;min-height:unset;aspect-ratio:16/9}"));
        assert!(css.contains(".wp-block-image{background-image:url('/media/referenced.jpg')}"));
        assert!(css.contains(".wp-block-media-text{background-image:url('/media/direct.jpg')}"));
        assert!(css.contains(".wp-block-gallery{background-size:cover}"));
        assert!(css.contains(".wp-block-columns{color:#0a0;column-gap:1rem;row-gap:2rem}"));
        for unsupported in [
            "position:sticky",
            "top:0",
            "font-variant:small-caps",
            "font-variation-settings",
            "text-shadow",
        ] {
            assert!(
                !css.contains(unsupported),
                "unexpected unsupported WP7 style: {unsupported}"
            );
        }
    }

    #[test]
    fn theme_json_fluid_typography_honors_wp7_global_and_local_controls() {
        let globally_fluid = br##"{
            "version":3,
            "settings":{
                "layout":{"wideSize":"80em"},
                "typography":{
                    "fluid":{"minViewportWidth":"0em","minFontSize":"1em"},
                    "fontSizes":[
                        {"slug":"derived","size":"2em"},
                        {"slug":"disabled","size":"2em","fluid":false}
                    ]
                }
            },
            "styles":{"typography":{"fontSize":"2em"}}
        }"##;
        let run = run_pipeline(
            &[
                ("page.md", b"# Fluid"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", globally_fluid),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let css = read(&run, "__spacefast_generated/theme.css");
        let derived = "clamp(1.25em, 1.25rem + ((1vw - 0em) * 0.938), 2em)";
        assert!(css.contains(&format!("--wp--preset--font-size--derived: {derived}")));
        assert!(css.contains("--wp--preset--font-size--disabled: 2em"));
        assert!(css.contains(&format!("body{{font-size:{derived}}}")));

        let locally_fluid = br##"{
            "version":3,
            "settings":{"typography":{"fluid":false,"fontSizes":[
                {"slug":"local","size":"2em","fluid":{"min":"1em","max":"2em"}},
                {"slug":"zero-bound","size":"2rem","fluid":{"min":"0rem","max":"2rem"}},
                {"slug":"zero-size","size":"0","fluid":{"min":"0rem","max":"2rem"}},
                {"slug":"static","size":"2em"}
            ]}}
        }"##;
        let run = run_pipeline(
            &[
                ("page.md", b"# Local fluid"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", locally_fluid),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let css = read(&run, "__spacefast_generated/theme.css");
        assert!(css.contains(
            "--wp--preset--font-size--local: clamp(1em, 1rem + ((1vw - 0.2em) * 1.25), 2em)"
        ));
        assert!(css.contains(
            "--wp--preset--font-size--zero-bound: clamp(0rem, 0rem + ((1vw - 0.2rem) * 2.5), 2rem)"
        ));
        assert!(css.contains("--wp--preset--font-size--zero-size: 0"));
        assert!(css.contains("--wp--preset--font-size--static: 2em"));
    }

    #[test]
    fn theme_json_applies_wp7_root_background_and_padding_rules() {
        let theme = br##"{
            "version":3,
            "settings":{"useRootPaddingAwareAlignments":true},
            "styles":{
                "background":{"backgroundImage":{"url":"/media/root.jpg","id":12}},
                "spacing":{"padding":"3rem"}
            }
        }"##;
        let run = run_pipeline(
            &[
                ("page.md", b"# Root styles"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", theme),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let css = read(&run, "__spacefast_generated/theme.css");
        assert!(
            css.contains("html{min-height:calc(100% - var(--wp-admin--admin-bar--height, 0px))}")
        );
        assert!(css.contains("body{background-image:url('/media/root.jpg')}"));
        assert!(!css.contains("padding:3rem"));
        assert!(!css.contains("background-size:cover"));
    }

    #[test]
    fn pipeline_sources_and_drafts_are_private() {
        let run = run_pipeline(
            &[
                ("page.md", b"# Published"),
                ("draft.md", b"---\ndraft: true\n---\n# Secret draft"),
                (
                    "_layout.html",
                    b"<html><head></head><body>{{ content }}</body></html>",
                ),
                ("theme.json", br#"{"version":3}"#),
                ("download.txt", b"public"),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true,"listing":true,"viewer":false}}),
        );
        let private = run.result.as_ref().unwrap();
        assert!(has_diagnostic(&run, "page_draft_skipped"));
        for private_path in ["page.md", "draft.md", "_layout.html", "theme.json"] {
            assert!(private.contains(private_path), "{private_path} not private");
        }
        assert!(!private.contains("download.txt"));
        assert!(run.files.contains_key("page/index.html"));
        assert!(!run.files.contains_key("draft/index.html"));
    }

    #[test]
    fn oversized_pipeline_sources_warn_and_are_skipped() {
        let oversized_markdown = vec![b'm'; PIPELINE_SOURCE_MAX_BYTES + 1];
        let oversized_layout = vec![b'l'; PIPELINE_SOURCE_MAX_BYTES + 1];
        let oversized_theme = vec![b't'; PIPELINE_SOURCE_MAX_BYTES + 1];
        let files: Vec<(&str, &[u8])> = vec![
            ("oversized.md", &oversized_markdown),
            ("page.md", b"# Kept page"),
            ("_layout.html", &oversized_layout),
            ("theme.json", &oversized_theme),
        ];
        let run = run_pipeline(
            &files,
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let oversized_paths: BTreeSet<_> = run
            .diagnostics
            .iter()
            .filter(|diagnostic| {
                diagnostic.get("code") == Some(&json!("pipeline_source_too_large"))
            })
            .filter_map(|diagnostic| diagnostic.get("path").and_then(Value::as_str))
            .collect();
        assert_eq!(
            oversized_paths,
            BTreeSet::from(["oversized.md", "_layout.html", "theme.json"])
        );
        assert!(run.files.contains_key("page/index.html"));
        assert!(!run.files.contains_key("oversized/index.html"));
        assert!(!run.files.contains_key("__spacefast_generated/theme.css"));
    }

    #[test]
    fn malformed_frontmatter_warns_recovers_scalars_and_renders() {
        let source =
            b"---\ntitle: Recovered title\ndate: 2026-07-13\nbroken: [unterminated\n---\n# Body";
        let run = run_pipeline(
            &[
                ("post.md", source),
                (
                    "_layout.html",
                    b"<html><head><title>{{ page.title }}</title></head><body><time>{{ page.date }}</time>{{ content }}</body></html>",
                ),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        assert!(run.diagnostics.iter().any(|diagnostic| {
            diagnostic.get("code") == Some(&json!("markdown_frontmatter_invalid"))
                && diagnostic.get("path") == Some(&json!("post.md"))
        }));
        let page = read(&run, "post/index.html");
        assert!(page.contains("<title>Recovered title</title>"));
        assert!(page.contains("<time>2026-07-13</time>"));
        assert!(page.contains("<h1 class=\"wp-block-heading\" id=\"body\">Body</h1>"));
    }

    #[test]
    fn files_mode_gutenberg_is_strict_and_generates_the_document_shell() {
        let source = b"<!-- wp:heading --><h2>Hello</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->";
        let run = run_pipeline(
            &[("page.html", source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"config":{"listing":true,"viewer":true}}),
        );
        run.result.as_ref().unwrap();
        let html = read(&run, "page.html");
        assert!(html.contains("stattic-block-document"));
        assert!(html.contains("<title>Hello</title>"));

        let run = run_pipeline(
            &[("page.html", b"<p>raw html</p>")],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"config":{}}),
        );
        assert!(matches!(
            run.result,
            Err(FinalizeError::Invalid {
                code: "gutenberg_blocks_required",
                ..
            })
        ));
    }

    #[test]
    fn shared_gutenberg_walker_renders_self_closing_and_reports_unknown_blocks() {
        let source = b"<!-- wp:group --><div><!-- wp:separator /--><!-- wp:vendor/card --><p>Fallback</p><!-- /wp:vendor/card --></div><!-- /wp:group -->";
        let run = run_pipeline(
            &[("page.html", source)],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        assert!(run.diagnostics.iter().any(|diagnostic| {
            diagnostic.get("code") == Some(&json!("block_unsupported"))
                && diagnostic.pointer("/details/block") == Some(&json!("vendor/card"))
        }));
        let html = read(&run, "page.html");
        assert!(html.contains("<hr class=\"wp-block-separator\">"));
        assert!(html.contains("<p>Fallback</p>"));
        assert!(!html.contains("<!-- wp:"));
    }

    #[test]
    fn oversized_files_mode_gutenberg_fails_instead_of_publishing_raw_source() {
        let mut source = b"<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->".to_vec();
        source.resize(PIPELINE_SOURCE_MAX_BYTES + 1, b' ');
        let run = run_pipeline(
            &[("page.html", &source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"config":{}}),
        );
        assert!(matches!(
            run.result,
            Err(FinalizeError::Invalid {
                code: "gutenberg_source_too_large",
                ..
            })
        ));
    }

    #[test]
    fn files_mode_gutenberg_rejects_top_level_non_block_comments() {
        let source = b"<!-- arbitrary --><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->";
        let run = run_pipeline(
            &[("page.html", source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"config":{}}),
        );
        assert!(matches!(
            run.result,
            Err(FinalizeError::Invalid {
                code: "gutenberg_freeform_html_unsupported",
                ..
            })
        ));
    }

    #[test]
    fn markdown_preserves_static_gutenberg_block_semantics() {
        let source = b"# Heading 0\n\n**Strong**\n\n| A | B |\n| - | - |\n| 1 | 2 |\n\n> Quote\n\n```txt\ncode\n```\n\n![Alt](/image.png)\n\nBefore ![Inline](/inline.png) after.\n\n<table id=\"raw\"><tr><td>Raw</td></tr></table>";
        let run = run_pipeline(
            &[("page.md", source)],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let html = read(&run, "page/index.html");
        assert!(html.contains("<h1 class=\"wp-block-heading\" id=\"heading-0\">Heading 0</h1>"));
        assert!(html.contains("<p><b>Strong</b></p>"));
        assert!(html.contains("<figure class=\"wp-block-table\"><table>"));
        assert!(html.contains("<blockquote class=\"wp-block-quote\">"));
        assert!(html.contains("<pre class=\"wp-block-code\"><code"));
        assert!(html.contains("<figure class=\"wp-block-image\"><img"));
        assert_eq!(html.matches("<figure class=\"wp-block-image\">").count(), 1);
        assert!(html.contains("<p>Before <img"));
        assert!(html.contains("after.</p>"));
        assert!(html.contains("<table id=\"raw\"><tr><td>Raw</td></tr></table>"));
        assert!(!html.contains("wp-block-table\"><table id=\"raw\""));
    }

    #[test]
    fn layout_slots_accept_arbitrary_whitespace_and_diagnose_each_unknown_once() {
        let layout = b"<html><head><title>{{\t page.title  }}</title></head><body>{{{\n content \n}}}<p>{{ missing.slot }}</p><i>{{missing.slot}}</i></body></html>";
        let run = run_pipeline(
            &[
                ("page.md", b"---\ntitle: A & B\n---\nBody"),
                ("_layout.html", layout),
            ],
            json!({"mode":"website"}),
            json!({"config":{"experimental_gutenberg":true}}),
        );
        run.result.as_ref().unwrap();
        let unresolved: Vec<_> = run
            .diagnostics
            .iter()
            .filter(|diagnostic| diagnostic.get("code") == Some(&json!("layout_unresolved_slot")))
            .collect();
        assert_eq!(unresolved.len(), 1);
        assert_eq!(
            unresolved[0].pointer("/details/slot"),
            Some(&json!("missing.slot"))
        );
        let html = read(&run, "page/index.html");
        assert!(html.contains("<title>A &amp; B</title>"));
        assert!(html.contains("<p>Body</p>"));
        assert_eq!(html.matches("{{ missing.slot }}").count(), 1);
        assert_eq!(html.matches("{{missing.slot}}").count(), 1);
    }

    #[test]
    fn generated_files_preserve_the_uploaded_original() {
        let source = b"<!-- wp:heading --><h2>Kept</h2><!-- /wp:heading -->";
        let run = run_pipeline(
            &[("page.html", source)],
            json!({"mode":"files","content":{"format":"gutenberg-blocks"}}),
            json!({"config":{}}),
        );
        run.result.as_ref().unwrap();
        let original = run
            .files_root
            .parent()
            .unwrap()
            .join("files-original/page.html");
        assert_eq!(fs::read(original).unwrap(), source);
    }
}
