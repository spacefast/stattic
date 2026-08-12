use crate::protocol::{
    BADGE_MARKER, CHALLENGE_FILE, CHALLENGE_MARKER, DENY_FILE, DENY_MARKER, LAYOUT_FILE,
    LAYOUT_MARKER, PAGE_MAX_BYTES,
};
use lol_html::{
    doc_comments, element, end_tag, html_content::ContentType, rewrite_str, RewriteStrSettings,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::cell::{Cell, RefCell};
use std::collections::BTreeSet;
use std::rc::Rc;

fn badge_fragment() -> String {
    format!(
        concat!(
            "<div data-sf=\"badge\" style=\"margin:12px 0 0;padding:0;color:#646b67;font-size:12px;",
            "font-weight:700;text-transform:uppercase;text-align:center\">{}",
            "<a href=\"https://spacefast.com\" rel=\"noopener\" style=\"color:inherit;text-decoration:none\">",
            "Protected by Spacefast</a></div>"
        ),
        BADGE_MARKER
    )
}

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum AccessSlot {
    Challenge,
    Deny,
}

impl AccessSlot {
    fn file(&self) -> &'static str {
        match self {
            Self::Challenge => CHALLENGE_FILE,
            Self::Deny => DENY_FILE,
        }
    }

    fn name(&self) -> &'static str {
        match self {
            Self::Challenge => "challenge",
            Self::Deny => "deny",
        }
    }

    fn marker(&self) -> &'static str {
        match self {
            Self::Challenge => CHALLENGE_MARKER,
            Self::Deny => DENY_MARKER,
        }
    }
}

#[derive(Debug, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case")]
pub enum PageCompileInput {
    Access {
        html: String,
        slot: AccessSlot,
        #[serde(rename = "whiteLabel")]
        white_label: bool,
    },
    Layout {
        html: String,
    },
    PagesLayout {
        html: String,
    },
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PageCompileOutput {
    pub html: Option<String>,
    pub diagnostics: Vec<PageCompileDiagnostic>,
}

#[derive(Debug, Serialize)]
pub struct PageCompileDiagnostic {
    pub severity: &'static str,
    pub code: &'static str,
    pub message: String,
    pub path: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub details: Option<Value>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum InstalledPageKind {
    Access(AccessSlot),
    Layout,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum InstalledPageError {
    TooLarge,
    MissingMarker,
}

/// Owns the complete deterministic gate-page/layout compilation policy. File
/// discovery and reads stay with the caller; byte caps, diagnostics, badge
/// selection, baking, and the install decision are identical in native and
/// WASM builds.
pub fn compile_page(input: PageCompileInput) -> PageCompileOutput {
    match input {
        PageCompileInput::Access {
            html,
            slot,
            white_label,
        } => compile_access_page(html, slot, white_label),
        PageCompileInput::Layout { html } => compile_layout_page(html),
        PageCompileInput::PagesLayout { html } => validate_pages_layout(html),
    }
}

pub fn validate_installed_page(
    html: &str,
    kind: InstalledPageKind,
) -> Result<(), InstalledPageError> {
    if html.len() > PAGE_MAX_BYTES {
        return Err(InstalledPageError::TooLarge);
    }
    let marker = match kind {
        InstalledPageKind::Access(slot) => slot.marker(),
        InstalledPageKind::Layout => LAYOUT_MARKER,
    };
    if !html.contains(marker) {
        return Err(InstalledPageError::MissingMarker);
    }
    Ok(())
}

fn compile_access_page(html: String, slot: AccessSlot, white_label: bool) -> PageCompileOutput {
    let file = slot.file();
    if html.len() > PAGE_MAX_BYTES {
        return page_failure(access_too_large(file, slot, false));
    }
    let marker = slot.marker();
    let mut output = match bake_slot(&html, slot.name(), marker) {
        Ok(baked) => baked,
        Err(reason) => return page_failure(access_bake_diagnostic(file, slot, reason)),
    };
    if !white_label {
        output = inject_badge(&output);
    }
    if output.len() > PAGE_MAX_BYTES {
        return page_failure(access_too_large(file, slot, true));
    }
    debug_assert_eq!(
        validate_installed_page(&output, InstalledPageKind::Access(slot)),
        Ok(())
    );
    page_success(output)
}

fn compile_layout_page(html: String) -> PageCompileOutput {
    const FILE: &str = LAYOUT_FILE;
    if html.len() > PAGE_MAX_BYTES {
        return page_failure(PageCompileDiagnostic {
            severity: "warning",
            code: "layout_template_too_large",
            message: format!(
                "{FILE} exceeds the {PAGE_MAX_BYTES} byte limit; gate pages render without custom chrome."
            ),
            path: FILE,
            details: Some(json!({"limit": PAGE_MAX_BYTES})),
        });
    }
    let output = match bake_slot(&html, "content", LAYOUT_MARKER) {
        Ok(baked) => baked,
        Err(reason) => return page_failure(layout_bake_diagnostic(reason)),
    };
    debug_assert_eq!(
        validate_installed_page(&output, InstalledPageKind::Layout),
        Ok(())
    );
    page_success(output)
}

fn validate_pages_layout(html: String) -> PageCompileOutput {
    if html.len() > PAGE_MAX_BYTES {
        return page_failure(PageCompileDiagnostic {
            severity: "error",
            code: "page_source_too_large",
            message: format!("_layout.html exceeds the {PAGE_MAX_BYTES} byte page-source limit."),
            path: "_layout.html",
            details: Some(json!({"limit": PAGE_MAX_BYTES})),
        });
    }
    let found = Rc::new(Cell::new(false));
    let marker = Rc::clone(&found);
    let parsed = rewrite_str(
        &html,
        RewriteStrSettings::new().append_element_content_handler(element!(
            "sf-content",
            move |_| {
                marker.set(true);
                Ok(())
            }
        )),
    );
    if parsed.is_err() || !found.get() {
        return page_failure(PageCompileDiagnostic {
            severity: "error",
            code: "page_layout_slot_missing",
            message: "_layout.html must contain an <sf-content> element.".into(),
            path: "_layout.html",
            details: None,
        });
    }
    page_success(html)
}

fn access_too_large(file: &'static str, slot: AccessSlot, baked: bool) -> PageCompileDiagnostic {
    PageCompileDiagnostic {
        severity: "warning",
        code: "access_page_too_large",
        message: format!(
            "{file} exceeds the {PAGE_MAX_BYTES} byte gate-page limit{}; the default {} page will render.",
            if baked { " once baked" } else { "" },
            slot.name()
        ),
        path: file,
        details: Some(json!({"slot": slot.name(), "limit": PAGE_MAX_BYTES})),
    }
}

fn access_bake_diagnostic(
    file: &'static str,
    slot: AccessSlot,
    reason: &'static str,
) -> PageCompileDiagnostic {
    let name = slot.name();
    let (code, message) = match reason {
        "slot_missing" => (
            "access_page_slot_missing",
            format!(
                "{file} has no <div data-sf=\"{name}\"> slot element; the default {name} page will render."
            ),
        ),
        "slot_unclosed" => (
            "access_page_slot_unclosed",
            format!(
                "{file}'s <div data-sf=\"{name}\"> element never closes; the default {name} page will render."
            ),
        ),
        "slot_in_form" => (
            "access_page_slot_in_form",
            format!(
                "{file}'s <div data-sf=\"{name}\"> slot sits inside a <form> element; the default {name} page will render."
            ),
        ),
        "marker_conflict" => (
            "access_page_marker_conflict",
            format!(
                "{file} already contains the {name} slot marker text; the default {name} page will render."
            ),
        ),
        _ => unreachable!("bake_slot returns a closed reason set"),
    };
    PageCompileDiagnostic {
        severity: "warning",
        code,
        message,
        path: file,
        details: Some(json!({"slot": name})),
    }
}

fn layout_bake_diagnostic(reason: &'static str) -> PageCompileDiagnostic {
    const FILE: &str = LAYOUT_FILE;
    let (code, message) = match reason {
        "slot_missing" => (
            "layout_template_slot_missing",
            format!(
                "{FILE} has no <div data-sf=\"content\"> slot element; gate pages render without custom chrome."
            ),
        ),
        "slot_unclosed" => (
            "layout_template_slot_unclosed",
            format!(
                "{FILE}'s <div data-sf=\"content\"> element never closes; gate pages render without custom chrome."
            ),
        ),
        "slot_in_form" => (
            "layout_template_slot_in_form",
            format!(
                "{FILE}'s <div data-sf=\"content\"> slot sits inside a <form> element; gate pages render without custom chrome."
            ),
        ),
        "marker_conflict" => (
            "layout_template_marker_conflict",
            format!(
                "{FILE} already contains the content slot marker text; gate pages render without custom chrome."
            ),
        ),
        _ => unreachable!("bake_slot returns a closed reason set"),
    };
    PageCompileDiagnostic {
        severity: "warning",
        code,
        message,
        path: FILE,
        details: None,
    }
}

fn page_success(html: String) -> PageCompileOutput {
    PageCompileOutput {
        html: Some(html),
        diagnostics: Vec::new(),
    }
}

fn page_failure(diagnostic: PageCompileDiagnostic) -> PageCompileOutput {
    PageCompileOutput {
        html: None,
        diagnostics: vec![diagnostic],
    }
}

/// Replaces the first author slot's contents with the runtime marker. The
/// error is the closed reason set the caller turns into a diagnostic.
fn bake_slot(
    html: &str,
    slot_name: &'static str,
    marker: &'static str,
) -> Result<String, &'static str> {
    let source = Rc::new(html.to_string());
    let first_slot = Rc::new(Cell::new(None::<usize>));
    let form_slots = Rc::new(RefCell::new(BTreeSet::<usize>::new()));
    let closed_slots = Rc::new(RefCell::new(BTreeSet::<usize>::new()));
    let marker_conflict = Rc::new(Cell::new(false));

    let analysis = rewrite_str(
        html,
        RewriteStrSettings::new()
            .append_document_content_handler(doc_comments!({
                let marker_conflict = Rc::clone(&marker_conflict);
                move |comment| {
                    if comment.text().trim() == marker_comment_text(marker) {
                        marker_conflict.set(true);
                    }
                    Ok(())
                }
            }))
            .append_element_content_handler(element!("form div", {
                let form_slots = Rc::clone(&form_slots);
                move |element| {
                    if element.get_attribute("data-sf").as_deref() == Some(slot_name) {
                        form_slots
                            .borrow_mut()
                            .insert(element.source_location().bytes().start);
                    }
                    Ok(())
                }
            }))
            .append_element_content_handler(element!("div", {
                let first_slot = Rc::clone(&first_slot);
                let closed_slots = Rc::clone(&closed_slots);
                let source = Rc::clone(&source);
                move |element| {
                    if element.get_attribute("data-sf").as_deref() != Some(slot_name) {
                        return Ok(());
                    }
                    let start = element.source_location().bytes().start;
                    if first_slot.get().is_none() {
                        first_slot.set(Some(start));
                    }
                    let closed_slots = Rc::clone(&closed_slots);
                    let source = Rc::clone(&source);
                    element.on_end_tag(end_tag!(move |end_tag| {
                        let range = end_tag.source_location().bytes();
                        let explicitly_closed = source.get(range).is_some_and(|raw| {
                            raw.trim_start().to_ascii_lowercase().starts_with("</div")
                        });
                        if explicitly_closed {
                            closed_slots.borrow_mut().insert(start);
                        }
                        Ok(())
                    }))?;
                    Ok(())
                }
            })),
    );
    if analysis.is_err() {
        return Err("slot_unclosed");
    }
    if marker_conflict.get() {
        return Err("marker_conflict");
    }
    let Some(target) = first_slot.get() else {
        return Err("slot_missing");
    };
    if form_slots.borrow().contains(&target) {
        return Err("slot_in_form");
    }
    if !closed_slots.borrow().contains(&target) {
        return Err("slot_unclosed");
    }

    let rewritten = rewrite_str(
        html,
        RewriteStrSettings::new().append_element_content_handler(element!("div", move |element| {
            if element.source_location().bytes().start == target
                && element.get_attribute("data-sf").as_deref() == Some(slot_name)
            {
                element.set_inner_content(marker, ContentType::Html);
            }
            Ok(())
        })),
    );
    rewritten.map_err(|_| "slot_unclosed")
}

fn marker_comment_text(marker: &str) -> &str {
    marker
        .strip_prefix("<!--")
        .and_then(|value| value.strip_suffix("-->"))
        .unwrap_or(marker)
}

fn inject_badge(html: &str) -> String {
    let inserted = Rc::new(Cell::new(false));
    let fragment = Rc::new(badge_fragment());
    let rewritten = rewrite_str(
        html,
        RewriteStrSettings::new().append_element_content_handler(element!("body", {
            let inserted = Rc::clone(&inserted);
            let fragment = Rc::clone(&fragment);
            move |element| {
                if !inserted.replace(true) {
                    element.append(&fragment, ContentType::Html);
                }
                Ok(())
            }
        })),
    );
    match rewritten {
        Ok(output) if inserted.get() => output,
        _ => format!("{html}{fragment}"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn access_slot_and_badge_are_baked() {
        let compiled = compile_page(PageCompileInput::Access {
            html: "<body><div data-sf='challenge'><div>old</div></div></body>".into(),
            slot: AccessSlot::Challenge,
            white_label: false,
        });
        assert!(compiled.diagnostics.is_empty());
        let html = compiled.html.expect("access page compiles");
        assert!(html.contains("<div data-sf='challenge'><!--spacefast:slot:challenge:v1--></div>"));
        assert!(html.contains("<!--spacefast:slot:badge:v1-->"));
        assert!(html.ends_with("</body>"));
    }

    #[test]
    fn nested_author_form_is_rejected() {
        let output = compile_page(PageCompileInput::Layout {
            html: "<form><div data-sf=content></div></form>".into(),
        });
        assert!(output.html.is_none());
        assert_eq!(output.diagnostics[0].code, "layout_template_slot_in_form");
    }

    #[test]
    fn raw_text_and_comment_decoys_do_not_capture_the_real_slot() {
        let html = concat!(
            "<html><body>",
            "<!-- <div data-sf=\"challenge\">comment</div> -->",
            "<style>.x::after{content:'<div data-sf=\"challenge\">style</div>'}</style>",
            "<script>const x='<div data-sf=\"challenge\">script</div>';</script>",
            "<main><div data-sf=\"challenge\">real</div></main>",
            "</body></html>"
        );
        let output = compile_page(PageCompileInput::Access {
            html: html.into(),
            slot: AccessSlot::Challenge,
            white_label: true,
        });
        let output = output.html.expect("real slot compiles");
        assert!(
            output.contains("<div data-sf=\"challenge\"><!--spacefast:slot:challenge:v1--></div>")
        );
        assert!(output.contains("<div data-sf=\"challenge\">script</div>"));
        assert!(output.contains("<div data-sf=\"challenge\">style</div>"));
        assert!(output.contains("<div data-sf=\"challenge\">comment</div>"));
    }

    #[test]
    fn pages_layout_requires_a_real_sf_content_element() {
        let valid = compile_page(PageCompileInput::PagesLayout {
            html: "<html><body><sf-content></sf-content></body></html>".into(),
        });
        assert_eq!(
            valid.html.as_deref(),
            Some("<html><body><sf-content></sf-content></body></html>")
        );

        for decoy in [
            "<!-- <sf-content></sf-content> -->",
            "<script>const slot = '<sf-content></sf-content>';</script>",
            "<style>sf-content { display: block }</style>",
        ] {
            let invalid = compile_page(PageCompileInput::PagesLayout { html: decoy.into() });
            assert!(invalid.html.is_none(), "{decoy}");
            assert_eq!(
                invalid.diagnostics[0].code, "page_layout_slot_missing",
                "{decoy}"
            );
        }
    }

    #[test]
    fn marker_text_in_raw_text_is_not_a_conflict_and_unclosed_slot_fails() {
        let raw_text = compile_page(PageCompileInput::Layout {
            html: format!(
                "<script>const marker='{}';</script><div data-sf=content>real</div>",
                LAYOUT_MARKER
            ),
        });
        assert!(raw_text.diagnostics.is_empty());
        assert!(raw_text
            .html
            .expect("layout compiles")
            .ends_with(&format!("<div data-sf=content>{LAYOUT_MARKER}</div>")));

        for unclosed in [
            "<div data-sf=content>",
            "<html><body><div data-sf=content><p>never closes</body></html>",
        ] {
            let output = compile_page(PageCompileInput::Layout {
                html: unclosed.into(),
            });
            assert!(output.html.is_none(), "{unclosed}");
            assert_eq!(
                output.diagnostics[0].code, "layout_template_slot_unclosed",
                "{unclosed}"
            );
        }
    }
}
