//! 0.8 pages that are static markup become v2 TSX pages at the same URL.
//!
//! v2 pages render once at capture and WordPress serves the result per request, so only a page
//! whose output never depends on the browser maps mechanically: no hooks, no `@spacefast/zero/*`
//! component or client import, no event handlers, and no imports other than relative TypeScript
//! data modules. Everything else is refused with what it uses and where to port it.
use super::super::{Failure, fail, pointer};
use oxc_allocator::Allocator;
use oxc_ast::ast::{Declaration, ImportDeclarationSpecifier, ImportOrExportKind, Statement};
use oxc_parser::Parser;
use oxc_span::SourceType;
use std::path::Path;

/// A converted page: its v2 file and source, and the app modules it imports (capsule-relative).
pub(super) struct Page {
    pub file: String,
    pub source: String,
    pub imports: Vec<String>,
}

/// Maps `pages/<path>.tsx` to `pages/<path>/page.tsx`, or explains the refusal.
pub(super) fn page(relative: &Path, text: &str, at: &str) -> Result<Page, Failure> {
    let name = relative.to_string_lossy().replace('\\', "/");
    let Some(stem) = name.strip_prefix("pages/").and_then(|n| n.strip_suffix(".tsx")) else {
        return Err(fail(at, reason(text, "a 0.8 page file")));
    };
    let mut segments: Vec<&str> = stem.split('/').collect();
    if segments.last() == Some(&"index") {
        segments.pop();
    }
    if segments
        .iter()
        .any(|s| s.starts_with('[') || s.starts_with('_') || s.starts_with('('))
    {
        return Err(fail(
            at,
            "a parameterized 0.8 page route has no static v2 page; port it to collectionPage() (docs/pages.md)",
        ));
    }
    let imports = static_markup(text, at)?;
    let base = relative.parent().unwrap_or(Path::new(""));
    let imports = imports
        .iter()
        .map(|i| normalize(&base.join(i)))
        .collect::<Option<Vec<_>>>()
        .ok_or_else(|| fail(at, "a page import leaves the capsule"))?;
    let dir = if segments.is_empty() {
        "pages".to_string()
    } else {
        format!("pages/{}", segments.join("/"))
    };
    // `pages/a/index.tsx` stays in its directory; `pages/a/b.tsx` moves one level down.
    let moved = usize::from(relative.file_stem().is_some_and(|s| s != "index"));
    Ok(Page {
        file: format!("{dir}/page.tsx"),
        source: relocate(&strip_directive(text), moved),
        imports,
    })
}

/// `client/index.tsx`'s `Layout` becomes `pages/layout.tsx` when it is static markup.
pub(super) fn layout(text: &str, at: &str) -> Result<Option<String>, Failure> {
    let arena = Allocator::default();
    let parsed = Parser::new(&arena, text, SourceType::tsx()).parse();
    if parsed.diagnostics.errors().next().is_some() {
        return Err(fail(at, "invalid TSX"));
    }
    let is_layout = |s: &Statement| {
        matches!(s, Statement::ExportDeclaration(e)
            if matches!(&e.declaration, Declaration::FunctionDeclaration(f) if f.id.as_ref().is_some_and(|id| id.name == "Layout")))
    };
    if !parsed.program.body.iter().any(is_layout) {
        return Ok(None);
    }
    if parsed.program.body.iter().any(|s| {
        (matches!(s, Statement::ExportDeclaration(_)) && !is_layout(s))
            || matches!(
                s,
                Statement::ExportNamedDeclaration(_)
                    | Statement::ExportDefaultDeclaration(_)
                    | Statement::ExportAllDeclaration(_)
            )
    }) {
        return Err(fail(at, reason(text, "a 0.8 client entry with more than a Layout")));
    }
    if !static_markup(text, at)?.is_empty() {
        return Err(fail(at, "a layout that imports app modules needs a manual port"));
    }
    let body = strip_directive(text)
        .replace("export function Layout(", "export default function Layout(")
        .replace(
            "import type { ComponentChildren } from \"preact\";",
            "import type { LayoutProps } from \"@spacefast/zero-pages\";",
        )
        .replace(
            "{ children }: { children: ComponentChildren }",
            "{ children }: LayoutProps",
        );
    if body.contains("ComponentChildren") || body.contains("preact") {
        return Err(fail(at, reason(text, "a 0.8 layout with Preact types")));
    }
    Ok(Some(relocate(&body, 0)))
}

/// Resolves `a/../b` without touching the filesystem; `None` when it climbs out of the capsule.
fn normalize(path: &Path) -> Option<String> {
    let mut out: Vec<String> = vec![];
    for c in path.components() {
        match c {
            std::path::Component::ParentDir => {
                out.pop()?;
            }
            std::path::Component::Normal(p) => out.push(p.to_string_lossy().into_owned()),
            std::path::Component::CurDir => {}
            _ => return None,
        }
    }
    let joined = out.join("/");
    Some(if joined.ends_with(".ts") {
        joined
    } else {
        format!("{joined}.ts")
    })
}

/// Refuses anything whose output could depend on the browser or the 0.8 runtime; returns the
/// relative data modules the page imports.
fn static_markup(text: &str, at: &str) -> Result<Vec<String>, Failure> {
    let arena = Allocator::default();
    let parsed = Parser::new(&arena, text, SourceType::tsx()).parse();
    if parsed.diagnostics.errors().next().is_some() {
        return Err(fail(at, "invalid TSX"));
    }
    let mut imports = vec![];
    for s in &parsed.program.body {
        if let Statement::ImportDeclaration(i) = s {
            let source = i.source.value.as_str();
            let type_only = i.import_kind == ImportOrExportKind::Type
                || i.specifiers.as_ref().is_some_and(|ss| {
                    !ss.is_empty()
                        && ss.iter().all(|s| matches!(s, ImportDeclarationSpecifier::ImportSpecifier(x) if x.import_kind == ImportOrExportKind::Type))
                });
            let local_data = source.starts_with("../shared/") || source.starts_with("./") && !source.ends_with(".tsx");
            if !type_only && !local_data {
                return Err(fail(at, reason(text, "a 0.8 page")));
            }
            if !type_only {
                imports.push(source.to_string());
            }
        }
    }
    // A "use client" page hydrated in 0.8, but one with no handlers, hooks or browser reads
    // hydrates to the markup it already rendered, so it moves like a document page.
    if handler_attribute(text) {
        return Err(fail(at, reason(text, "a 0.8 page")));
    }
    // Hooks and browser globals decide output after render.
    for needle in [
        "use(",
        "useState",
        "useEffect",
        "useQuery",
        "useMutation",
        "useAction",
        "useAuth",
        "usePaginatedQuery",
        "useMemo",
        "useRef",
        "useCallback",
        "onClick",
        "onSubmit",
        "onChange",
        "onInput",
        "window.",
        "document.",
        "localStorage",
        "fetch(",
        "dangerouslySetInnerHTML",
    ] {
        if text.contains(needle) {
            return Err(fail(at, reason(text, "a 0.8 page")));
        }
    }
    Ok(imports)
}

/// Any JSX event attribute (`onClick=`, `onKeyDown=`, ...): an `on` + capital letter followed by `=`.
fn handler_attribute(text: &str) -> bool {
    let bytes = text.as_bytes();
    (0..bytes.len().saturating_sub(3)).any(|i| {
        (i == 0 || !bytes[i - 1].is_ascii_alphanumeric())
            && bytes[i] == b'o'
            && bytes[i + 1] == b'n'
            && bytes[i + 2].is_ascii_uppercase()
            && bytes[i + 3..].iter().find(|b| !b.is_ascii_alphanumeric()) == Some(&b'=')
    })
}

fn strip_directive(text: &str) -> String {
    let trimmed = text.trim_start();
    for d in ["\"use client\";", "'use client';", "\"use client\"", "'use client'"] {
        if let Some(rest) = trimmed.strip_prefix(d) {
            return rest.trim_start_matches(['\n', '\r']).to_string();
        }
    }
    text.to_string()
}

/// A page moves one directory deeper (`pages/about.tsx` → `pages/about/page.tsx`); relative
/// imports of the app's own modules gain the same depth.
fn relocate(text: &str, extra: usize) -> String {
    if extra == 0 {
        return text.to_string();
    }
    let up = "../".repeat(extra);
    // Both quote styles: `from '../shared/plans'` is as valid as `from "../shared/plans"`.
    let mut out = text.to_string();
    for quote in ['"', '\''] {
        out = out
            .replace(&format!("from {quote}../"), &format!("from {quote}{up}../"))
            .replace(&format!("from {quote}./"), &format!("from {quote}{up}"));
    }
    out
}

/// Why a page needs a manual port: what it uses, and what replaces it on v2.
pub(super) fn reason(text: &str, kind: &str) -> String {
    let client = text.trim_start().starts_with("\"use client\"") || text.trim_start().starts_with("'use client'");
    let mut uses = vec![];
    for (needle, what) in [
        ("useQuery", "live queries (read them in load() or an island action)"),
        ("usePaginatedQuery", "paginated queries"),
        ("useMutation", "mutations (call the action from an island)"),
        ("useAction", "actions (call them from an island)"),
        (
            "useAuth",
            "useAuth (v2 pages render as the visitor; branch with <Show>)",
        ),
        ("useState", "component state (an island's Interactivity store)"),
        ("useEffect", "effects"),
        ("onClick", "event handlers (an island)"),
        ("onSubmit", "event handlers (an island)"),
        ("@spacefast/zero/kit", "the 0.8 kit components (plain markup and CSS)"),
        ("@spacefast/zero/client", "the 0.8 client router and hooks"),
        ("@spacefast/zero/context", "the context editor"),
        ("@spacefast/zero/content", "0.8 content blocks"),
        ("@spacefast/zero/charts", "0.8 charts"),
        ("fetch(", "browser fetch (an island)"),
    ] {
        if text.contains(needle) && !uses.contains(&what) {
            uses.push(what);
        }
    }
    let kind = if client {
        format!("{kind} (\"use client\")")
    } else {
        kind.to_string()
    };
    if uses.is_empty() {
        format!("{kind} imports modules a v2 page cannot render; port it to a TSX page (docs/pages.md)")
    } else {
        format!(
            "{kind} using {}; port it to a TSX page (docs/pages.md)",
            uses.join(", ")
        )
    }
}

/// The pointer for a page file.
pub(super) fn at(relative: &Path) -> String {
    let mut at = String::new();
    for part in relative.iter() {
        at = pointer(&at, &part.to_string_lossy());
    }
    at
}
