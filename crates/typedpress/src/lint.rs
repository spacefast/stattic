//! Compile-time OXC AST lint checks with deterministic auto-fix support.
//!
//! Warnings are non-fatal: they are reported during `typedpress check`, `typedpress build`,
//! and in the browser WASM compiler (`Output.lintWarnings`), and can be auto-fixed via
//! `typedpress check --fix` or the one-click Auto-fix button in browser sandboxes.

use oxc_allocator::Allocator;
use oxc_ast::ast::{
    ExportAllDeclaration, ExportFromDeclaration, ImportDeclaration, JSXAttribute, JSXAttributeName, JSXText,
    ObjectProperty, StringLiteral, TemplateElement,
};
use oxc_ast_visit::{Visit, walk};
use oxc_parser::Parser;
use oxc_span::SourceType;
use serde::Serialize;
use std::collections::{BTreeMap, HashSet};
use std::path::{Path, PathBuf};

pub const CAPITAL_P_DANGIT: &str = "capital_P_dangit";

const TARGETS: &[(&str, &str)] = &[("Wordpress", "WordPress"), ("Spacepress", "SpacePress")];

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Warning {
    pub rule: &'static str,
    pub file: String,
    #[serde(skip_serializing)]
    pub path: PathBuf,
    pub line: usize,
    pub column: usize,
    pub start: usize,
    pub end: usize,
    pub utf16_start: usize,
    pub utf16_end: usize,
    pub found: String,
    pub replacement: &'static str,
    pub message: String,
}

/// Runs all compile-time lint rules over every project source file registered in this run.
pub fn check_project(root: &Path) -> Vec<Warning> {
    let mut warnings = Vec::new();
    let mut seen = HashSet::new();
    for (path, source) in crate::diag::registered_files() {
        if !seen.insert(path.clone()) {
            continue;
        }
        let Ok(rel) = path.strip_prefix(root) else {
            continue;
        };
        if !should_lint(rel) {
            continue;
        }
        let rel_str = rel.to_string_lossy().replace('\\', "/");
        check_source(&path, &rel_str, &source, &mut warnings);
    }
    warnings.sort_by(|a, b| a.file.cmp(&b.file).then_with(|| a.start.cmp(&b.start)));
    warnings
}

fn should_lint(rel: &Path) -> bool {
    let Some(ext) = rel.extension().and_then(|e| e.to_str()) else {
        return false;
    };
    if !matches!(ext, "ts" | "tsx" | "js" | "jsx" | "mts" | "cts") {
        return false;
    }
    let normalized = rel.to_string_lossy().replace('\\', "/");
    if normalized.starts_with("node_modules/")
        || normalized.contains("/node_modules/")
        || normalized.starts_with("tp/_generated/")
        || normalized.contains("/tp/_generated/")
    {
        return false;
    }
    true
}

/// Lints one TypeScript/TSX source file using the OXC AST parser and visitor.
pub fn check_source(path: &Path, display_file: &str, source: &str, out: &mut Vec<Warning>) {
    let allocator = Allocator::default();
    let source_type = SourceType::from_path(path).unwrap_or_else(|_| SourceType::tsx());
    let parsed = Parser::new(&allocator, source, source_type).parse();
    let mut visitor = CapitalPDangitVisitor {
        path,
        display_file,
        source,
        warnings: out,
    };
    visitor.visit_program(&parsed.program);
    out.sort_by(|a, b| a.file.cmp(&b.file).then_with(|| a.start.cmp(&b.start)));
}

struct CapitalPDangitVisitor<'a> {
    path: &'a Path,
    display_file: &'a str,
    source: &'a str,
    warnings: &'a mut Vec<Warning>,
}

impl<'a> CapitalPDangitVisitor<'a> {
    fn scan_span(&mut self, start: usize, end: usize, skip_url_like: bool) {
        if start >= end || end > self.source.len() {
            return;
        }
        let Some(slice) = self.source.get(start..end) else {
            return;
        };
        if skip_url_like && is_url_or_path(slice) {
            return;
        }
        for &(needle, replacement) in TARGETS {
            let mut cursor = 0;
            while let Some(pos) = slice[cursor..].find(needle) {
                let rel_start = cursor + pos;
                let rel_end = rel_start + needle.len();
                cursor = rel_end;
                if !has_clean_boundaries(slice, rel_start, rel_end) {
                    continue;
                }
                let abs_start = start + rel_start;
                let abs_end = start + rel_end;
                let (line, column) = line_col_utf16(self.source, abs_start);
                let utf16_start = self.source[..abs_start].encode_utf16().count();
                let utf16_end = utf16_start + needle.encode_utf16().count();
                self.warnings.push(Warning {
                    rule: CAPITAL_P_DANGIT,
                    file: self.display_file.to_string(),
                    path: self.path.to_path_buf(),
                    line,
                    column,
                    start: abs_start,
                    end: abs_end,
                    utf16_start,
                    utf16_end,
                    found: needle.to_string(),
                    replacement,
                    message: format!(
                        "{replacement} should be spelled with a capital P, dangit (`{needle}` -> `{replacement}`)."
                    ),
                });
            }
        }
    }
}

impl<'a> Visit<'a> for CapitalPDangitVisitor<'_> {
    fn visit_import_declaration(&mut self, _it: &ImportDeclaration<'a>) {
        // Module specifiers are paths/package identifiers, never user-facing copy.
    }

    fn visit_export_all_declaration(&mut self, _it: &ExportAllDeclaration<'a>) {
        // Re-export module specifiers are paths, never user-facing copy.
    }

    fn visit_export_from_declaration(&mut self, _it: &ExportFromDeclaration<'a>) {
        // Re-export module specifiers are paths, never user-facing copy.
    }

    fn visit_object_property(&mut self, it: &ObjectProperty<'a>) {
        if it.computed {
            self.visit_property_key(&it.key);
        }
        self.visit_expression(&it.value);
    }

    fn visit_jsx_attribute(&mut self, it: &JSXAttribute<'a>) {
        if let JSXAttributeName::Identifier(ident) = &it.name {
            let name = ident.name.as_str();
            if is_technical_jsx_attr(name) {
                return;
            }
        }
        walk::walk_jsx_attribute(self, it);
    }

    fn visit_jsx_text(&mut self, it: &JSXText<'a>) {
        self.scan_span(it.span.start as usize, it.span.end as usize, false);
    }

    fn visit_string_literal(&mut self, it: &StringLiteral<'a>) {
        let start = it.span.start as usize;
        let end = it.span.end as usize;
        if end >= start + 2 {
            self.scan_span(start + 1, end - 1, true);
        }
    }

    fn visit_template_element(&mut self, it: &TemplateElement<'a>) {
        self.scan_span(it.span.start as usize, it.span.end as usize, false);
    }
}

fn is_technical_jsx_attr(name: &str) -> bool {
    matches!(
        name,
        "href"
            | "src"
            | "action"
            | "id"
            | "name"
            | "className"
            | "class"
            | "key"
            | "rel"
            | "target"
            | "type"
            | "role"
            | "htmlFor"
            | "for"
            | "method"
            | "lang"
            | "file"
            | "output"
            | "example"
            | "playExample"
            | "defaultTab"
            | "aria-controls"
            | "aria-labelledby"
            | "aria-describedby"
    ) || name.starts_with("data-")
}

fn is_url_or_path(text: &str) -> bool {
    let trimmed = text.trim();
    trimmed.starts_with("http://")
        || trimmed.starts_with("https://")
        || trimmed.starts_with("mailto:")
        || trimmed.starts_with("ftp://")
        || trimmed.starts_with('/')
        || trimmed.starts_with("./")
        || trimmed.starts_with("../")
}

fn has_clean_boundaries(slice: &str, start: usize, end: usize) -> bool {
    if let Some(prev) = slice[..start].chars().next_back()
        && (prev.is_alphanumeric() || matches!(prev, '_' | '-' | '/' | '.' | '@' | '$'))
    {
        return false;
    }
    let after = &slice[end..];
    if let Some(next) = after.chars().next() {
        if next.is_alphanumeric() || matches!(next, '_' | '-' | '/') {
            return false;
        }
        // Skip domain/member access like `Wordpress.org` or `Wordpress.com`,
        // while still catching sentence-ending punctuation like `Built on Wordpress.`
        if next == '.'
            && after[next.len_utf8()..]
                .chars()
                .next()
                .is_some_and(|c| c.is_ascii_alphanumeric())
        {
            return false;
        }
    }
    true
}

fn line_col_utf16(src: &str, offset: usize) -> (usize, usize) {
    let before = &src[..offset.min(src.len())];
    let line = before.bytes().filter(|b| *b == b'\n').count() + 1;
    let line_start = before.rfind('\n').map_or(0, |at| at + 1);
    let col = src[line_start..offset].encode_utf16().count() + 1;
    (line, col)
}

/// Applies auto-fixes for `warnings` in place on disk. Returns `(fixes_applied, files_modified)`.
pub fn apply_fixes(warnings: &[Warning]) -> std::io::Result<(usize, usize)> {
    let mut by_file: BTreeMap<&Path, Vec<&Warning>> = BTreeMap::new();
    for w in warnings {
        by_file.entry(w.path.as_path()).or_default().push(w);
    }
    let mut total_fixes = 0;
    let mut files_modified = 0;
    for (path, mut list) in by_file {
        let mut source = std::fs::read_to_string(path)?;
        list.sort_by_key(|a| std::cmp::Reverse(a.start));
        let mut last_start = usize::MAX;
        let mut file_fixes = 0;
        for w in list {
            if w.end > source.len() || w.start > w.end || w.end > last_start {
                continue;
            }
            if source.get(w.start..w.end) != Some(w.found.as_str()) {
                continue;
            }
            source.replace_range(w.start..w.end, w.replacement);
            last_start = w.start;
            file_fixes += 1;
        }
        if file_fixes > 0 {
            std::fs::write(path, source)?;
            total_fixes += file_fixes;
            files_modified += 1;
        }
    }
    Ok((total_fixes, files_modified))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn lint_tsx(src: &str) -> Vec<Warning> {
        let mut out = Vec::new();
        check_source(Path::new("/app/tp/app/page.tsx"), "tp/app/page.tsx", src, &mut out);
        out
    }

    #[test]
    fn flags_misspelled_wordpress_and_spacepress_in_strings_jsx_and_templates() {
        let src = r#"
import { x } from "./Wordpress";
export default function Page() {
  const a = "Built on Wordpress.";
  const b = `Powered by Spacepress and Wordpress's engine`;
  return (
    <main className="Wordpress-shell" href="https://Wordpress.org/about">
      <h1>Welcome to Wordpress</h1>
      <p title="Try Spacepress today">Visit Wordpress.org or /Wordpress/docs</p>
    </main>
  );
}
"#;
        let warnings = lint_tsx(src);
        let found: Vec<(&str, &str)> = warnings.iter().map(|w| (w.found.as_str(), w.replacement)).collect();
        assert_eq!(
            found,
            vec![
                ("Wordpress", "WordPress"),
                ("Spacepress", "SpacePress"),
                ("Wordpress", "WordPress"),
                ("Wordpress", "WordPress"),
                ("Spacepress", "SpacePress"),
            ],
            "unexpected warnings: {warnings:#?}"
        );
        for w in &warnings {
            assert_eq!(w.rule, CAPITAL_P_DANGIT);
            assert_eq!(&src[w.start..w.end], w.found);
        }
    }

    #[test]
    fn ignores_urls_imports_property_keys_and_lowercase_slugs() {
        let src = r#"
import { defineConfig } from "typedpress";
export * from "./Wordpress";
const cfg = {
  Wordpress: "https://Wordpress.org",
  slug: "wordpress",
  other: "spacepress",
  pkg: "my-Wordpress-plugin",
  ok: "WordPress and SpacePress",
};
export default defineConfig({ name: "demo" });
"#;
        let warnings = lint_tsx(src);
        assert!(warnings.is_empty(), "expected no warnings, got: {warnings:#?}");
    }

    #[test]
    fn auto_fix_rewrites_files_in_place() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("page.tsx");
        let original = "export const title = \"Hello Wordpress and Spacepress!\";\nexport default function P() { return <p>Built for Wordpress.</p>; }\n";
        std::fs::write(&file, original).unwrap();
        let mut warnings = Vec::new();
        check_source(&file, "page.tsx", original, &mut warnings);
        assert_eq!(warnings.len(), 3);
        let (fixes, files) = apply_fixes(&warnings).unwrap();
        assert_eq!((fixes, files), (3, 1));
        let updated = std::fs::read_to_string(&file).unwrap();
        assert_eq!(
            updated,
            "export const title = \"Hello WordPress and SpacePress!\";\nexport default function P() { return <p>Built for WordPress.</p>; }\n"
        );
        let mut after = Vec::new();
        check_source(&file, "page.tsx", &updated, &mut after);
        assert!(after.is_empty());
    }
}
