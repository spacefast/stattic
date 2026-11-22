//! Stylesheets imported by components and pages, parsed with `oxc-css-parser`.
//!
//! Walking the CSS AST handles four things accurately (including CSS Nesting, `:is()`/`:where()`/
//! `:not()`/`:has()`, `image-set()`, comments, and strings):
//! - collects `@import` rules so the loader bundles local relative stylesheets inline;
//! - collects class selectors in CSS modules (`*.module.css`) so they can be renamed to
//!   `<stem>_<class>`, while refusing `:global`, `:local`, and `composes:`;
//! - collects relative `url(...)` references (and tags `@font-face` font URLs for preloading);
//! - minifies the emitted CSS (stripping comments, collapsing whitespace safely outside strings,
//!   URLs, and `calc()`, dropping redundant semicolons, and shortening 6-digit hex colors).

use oxc_css_parser::{Allocator, Parser, Syntax, ast};

/// A refusal at a byte offset of the stylesheet.
#[derive(Debug, PartialEq, Eq)]
pub struct Refusal {
    pub at: usize,
    pub message: String,
    pub help: &'static str,
}

/// An `@import` rule inside a stylesheet.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CssImport {
    /// Byte offset of `@import` (for diagnostics).
    pub at: usize,
    /// Full byte range of the `@import ...;` statement (including trailing `;` if present).
    pub start: usize,
    pub end: usize,
    /// The imported specifier string (unquoted).
    pub href: String,
    /// Optional `layer` / `layer(name)` modifier (`Some("")` for anonymous `layer`).
    pub layer: Option<String>,
    /// Optional `supports(...)` condition source text (inside `supports(...)`).
    pub supports: Option<String>,
    /// Optional media query list source text.
    pub media: Option<String>,
}

/// A relative `url(...)` reference inside a stylesheet, to be bundled alongside it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RelativeUrl {
    /// Byte offset of `url(` in the stylesheet (for diagnostics).
    pub at: usize,
    /// Byte range of the inner target (excluding quotes and outer whitespace).
    pub start: usize,
    pub end: usize,
    /// The unquoted target string (may include `?query` or `#fragment`).
    pub target: String,
    /// If inside an `@font-face` block, the block's ordinal index (for picking the best preload format).
    pub font_face: Option<usize>,
}

/// What an AST scan found: `@import` rules, CSS-module class selectors (byte range without `.`),
/// and relative `url(...)` references.
#[derive(Debug, Default)]
pub struct Scanned {
    pub imports: Vec<CssImport>,
    pub classes: Vec<(usize, usize, String)>,
    pub urls: Vec<RelativeUrl>,
}

/// Scans `text` with `oxc-css-parser`. `module` collects class selectors and refuses `:global`,
/// `:local`, and `composes:`.
pub fn scan(text: &str, module: bool) -> Result<Scanned, Refusal> {
    let allocator = Allocator::default();
    let mut parser = Parser::new(&allocator, text, Syntax::Css);
    let sheet = parser.parse::<ast::Stylesheet>().map_err(|err| Refusal {
        at: err.span.start as usize,
        message: format!("invalid CSS: {}", err.kind),
        help: "fix the CSS syntax error in this stylesheet",
    })?;
    let mut out = Scanned::default();
    let mut font_faces = 0usize;
    walk_statements(text, &sheet.statements, module, None, &mut font_faces, &mut out)?;
    // Sort edits by start offset so downstream splices are always strictly ascending.
    out.classes.sort_by_key(|(s, _, _)| *s);
    out.urls.sort_by_key(|u| u.start);
    out.imports.sort_by_key(|i| i.start);
    Ok(out)
}

fn walk_statements(
    text: &str,
    stmts: &[ast::Statement<'_>],
    module: bool,
    font_face: Option<usize>,
    font_faces: &mut usize,
    out: &mut Scanned,
) -> Result<(), Refusal> {
    for stmt in stmts {
        match stmt {
            ast::Statement::AtRule(at) => {
                let name = at.name.name;
                if name.eq_ignore_ascii_case("import") {
                    if let Some(ast::AtRulePrelude::Import(prelude)) = &at.prelude
                        && let Some(href) = import_href(&prelude.href)
                    {
                        let layer = prelude.layer.as_ref().map(|l| match l {
                            ast::ImportPreludeLayer::Empty(_) => String::new(),
                            ast::ImportPreludeLayer::WithName(f) => {
                                let raw = &text[f.span.start as usize..f.span.end as usize];
                                raw.strip_prefix("layer(")
                                    .or_else(|| raw.strip_prefix("LAYER("))
                                    .and_then(|s| s.strip_suffix(')'))
                                    .unwrap_or(raw)
                                    .trim()
                                    .to_string()
                            }
                        });
                        let supports = prelude.supports.as_ref().map(|s| {
                            let raw = &text[s.span.start as usize..s.span.end as usize];
                            raw.strip_prefix("supports(")
                                .or_else(|| raw.strip_prefix("SUPPORTS("))
                                .and_then(|r| r.strip_suffix(')'))
                                .unwrap_or(raw)
                                .trim()
                                .to_string()
                        });
                        let media = prelude
                            .media
                            .as_ref()
                            .map(|m| text[m.span.start as usize..m.span.end as usize].trim().to_string());
                        let mut end = at.span.end as usize;
                        if text.as_bytes().get(end) == Some(&b';') {
                            end += 1;
                        }
                        out.imports.push(CssImport {
                            at: at.span.start as usize,
                            start: at.span.start as usize,
                            end,
                            href,
                            layer,
                            supports,
                            media,
                        });
                    }
                    continue;
                }
                let next_font_face = if name.eq_ignore_ascii_case("font-face") {
                    let id = *font_faces;
                    *font_faces += 1;
                    Some(id)
                } else {
                    font_face
                };
                // Walk selector preludes on `@scope` / `@nest` for CSS module classes.
                if module {
                    match &at.prelude {
                        Some(ast::AtRulePrelude::Nest(list)) => walk_selector_list(list, out)?,
                        Some(ast::AtRulePrelude::Scope(scope)) => match &**scope {
                            ast::ScopePrelude::StartOnly(s) => {
                                if let Some(sel) = &s.selector {
                                    walk_selector_list(sel, out)?;
                                }
                            }
                            ast::ScopePrelude::EndOnly(e) => {
                                if let Some(sel) = &e.selector {
                                    walk_selector_list(sel, out)?;
                                }
                            }
                            ast::ScopePrelude::Both(b) => {
                                if let Some(sel) = &b.start.selector {
                                    walk_selector_list(sel, out)?;
                                }
                                if let Some(sel) = &b.end.selector {
                                    walk_selector_list(sel, out)?;
                                }
                            }
                        },
                        _ => {}
                    }
                }
                if let Some(block) = &at.block {
                    walk_statements(text, &block.statements, module, next_font_face, font_faces, out)?;
                }
            }
            ast::Statement::QualifiedRule(rule) => {
                if module {
                    walk_selector_list(&rule.selector, out)?;
                }
                walk_statements(text, &rule.block.statements, module, font_face, font_faces, out)?;
            }
            ast::Statement::Declaration(decl) => {
                if module
                    && let ast::InterpolableIdent::Literal(ident) = &decl.name
                    && ident.name.eq_ignore_ascii_case("composes")
                {
                    return Err(Refusal {
                        at: decl.span.start as usize,
                        message: "`composes` isn't supported in CSS modules".into(),
                        help: "list both classes on the element instead: `className={`${styles.a} ${styles.b}`}`",
                    });
                }
                walk_values(&decl.value, font_face, out);
            }
            ast::Statement::KeyframeBlock(kf) => {
                walk_statements(text, &kf.block.statements, module, font_face, font_faces, out)?;
            }
            _ => {}
        }
    }
    Ok(())
}

fn import_href(href: &ast::ImportPreludeHref<'_>) -> Option<String> {
    match href {
        ast::ImportPreludeHref::Str(ast::InterpolableStr::Literal(s)) => Some(s.value.to_string()),
        ast::ImportPreludeHref::Url(u) => match u.value.as_ref()? {
            ast::UrlValue::Raw(raw) => Some(raw.value.to_string()),
            ast::UrlValue::Str(ast::InterpolableStr::Literal(s)) => Some(s.value.to_string()),
            _ => None,
        },
        _ => None,
    }
}

fn walk_selector_list(list: &ast::SelectorList<'_>, out: &mut Scanned) -> Result<(), Refusal> {
    for complex in &list.selectors {
        walk_complex_selector(complex, out)?;
    }
    Ok(())
}

fn walk_complex_selector(complex: &ast::ComplexSelector<'_>, out: &mut Scanned) -> Result<(), Refusal> {
    for child in &complex.children {
        if let ast::ComplexSelectorChild::CompoundSelector(compound) = child {
            walk_compound_selector(compound, out)?;
        }
    }
    Ok(())
}

fn walk_compound_selector(compound: &ast::CompoundSelector<'_>, out: &mut Scanned) -> Result<(), Refusal> {
    for simple in &compound.children {
        match simple {
            ast::SimpleSelector::Class(cls) => {
                if let ast::InterpolableIdent::Literal(ident) = &cls.name {
                    out.classes.push((
                        ident.span.start as usize,
                        ident.span.end as usize,
                        ident.name.to_string(),
                    ));
                }
            }
            ast::SimpleSelector::PseudoClass(pc) => {
                if let ast::InterpolableIdent::Literal(ident) = &pc.name
                    && (ident.name.eq_ignore_ascii_case("global") || ident.name.eq_ignore_ascii_case("local"))
                {
                    return Err(Refusal {
                        at: pc.span.start as usize,
                        message: format!("`:{}` isn't supported in CSS modules", ident.name),
                        help: "put selectors that mustn't be renamed in a plain stylesheet (`x.css`) and import it beside the module",
                    });
                }
                if let Some(arg) = &pc.arg {
                    match &arg.kind {
                        ast::PseudoClassSelectorArgKind::SelectorList(list) => walk_selector_list(list, out)?,
                        ast::PseudoClassSelectorArgKind::CompoundSelectorList(list) => {
                            for compound in &list.selectors {
                                walk_compound_selector(compound, out)?;
                            }
                        }
                        ast::PseudoClassSelectorArgKind::RelativeSelectorList(list) => {
                            for rel in &list.selectors {
                                walk_complex_selector(&rel.complex_selector, out)?;
                            }
                        }
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    Ok(())
}

fn walk_values(values: &[ast::ComponentValue<'_>], font_face: Option<usize>, out: &mut Scanned) {
    for value in values {
        match value {
            ast::ComponentValue::Url(u) => record_url(u, font_face, out),
            ast::ComponentValue::Function(f) => walk_values(&f.args, font_face, out),
            ast::ComponentValue::BracketBlock(b) => walk_values(&b.value, font_face, out),
            _ => {}
        }
    }
}

fn record_url(u: &ast::Url<'_>, font_face: Option<usize>, out: &mut Scanned) {
    let Some(val) = &u.value else { return };
    let (inner_start, inner_end, target) = match val {
        ast::UrlValue::Raw(raw) => (raw.span.start as usize, raw.span.end as usize, raw.value),
        ast::UrlValue::Str(ast::InterpolableStr::Literal(s)) if s.span.end >= s.span.start + 2 => {
            (s.span.start as usize + 1, s.span.end as usize - 1, s.value)
        }
        _ => return,
    };
    let lower = target.to_ascii_lowercase();
    let absolute = target.is_empty()
        || target.starts_with('/')
        || target.starts_with('#')
        || target.contains("://")
        || lower.starts_with("data:")
        || lower.starts_with("blob:")
        || lower.starts_with("about:");
    if !absolute {
        out.urls.push(RelativeUrl {
            at: u.span.start as usize,
            start: inner_start,
            end: inner_end,
            target: target.to_string(),
            font_face,
        });
    }
}

/// `text` with each scanned class renamed by `name`.
pub fn rename(text: &str, scanned: &Scanned, name: impl Fn(&str) -> String) -> String {
    let mut out = String::with_capacity(text.len() + scanned.classes.len() * 8);
    let mut at = 0;
    for (start, end, class) in &scanned.classes {
        out.push_str(&text[at..*start]);
        out.push_str(&name(class));
        at = *end;
    }
    out.push_str(&text[at..]);
    out
}

/// Splices non-overlapping `(start, end, replacement)` byte edits (in ascending order) into `text`.
pub fn rewrite_ranges(text: &str, replacements: &[(usize, usize, String)]) -> String {
    if replacements.is_empty() {
        return text.to_string();
    }
    let mut out = String::with_capacity(text.len());
    let mut at = 0;
    for (start, end, replacement) in replacements {
        out.push_str(&text[at..*start]);
        out.push_str(replacement);
        at = *end;
    }
    out.push_str(&text[at..]);
    out
}

/// Minifies CSS safely: strips comments, collapses whitespace outside strings/`url()`/`calc()`/
/// custom properties, drops redundant semicolons before `}`, and shortens 6-digit hex colors.
pub fn minify(text: &str) -> String {
    let b = text.as_bytes();
    let mut out: Vec<u8> = Vec::with_capacity(b.len());
    let mut i = 0;
    let mut paren_depth: usize = 0;
    let mut calc_depths: Vec<usize> = Vec::new();
    let mut in_custom_prop = false;
    let mut pending_space = false;

    let trim_space_before = |c: u8, in_calc: bool| -> bool {
        match c {
            b'{' | b'}' | b';' | b',' | b'>' | b'~' => true,
            b'+' if !in_calc => true,
            b')' => true,
            _ => false,
        }
    };
    let trim_space_after = |c: u8, in_calc: bool| -> bool {
        match c {
            b'{' | b'}' | b';' | b',' | b'>' | b'~' | b'(' | b':' => true,
            b'+' if !in_calc => true,
            _ => false,
        }
    };

    while i < b.len() {
        let c = b[i];
        // Strip /* ... */ comments.
        if c == b'/' && b.get(i + 1) == Some(&b'*') {
            i = text[i + 2..].find("*/").map_or(b.len(), |e| i + 2 + e + 2);
            continue;
        }
        // Preserve strings verbatim.
        if c == b'"' || c == b'\'' {
            if pending_space {
                if let Some(prev) = out.last().copied()
                    && !trim_space_after(prev, !calc_depths.is_empty())
                {
                    out.push(b' ');
                }
                pending_space = false;
            }
            let end = string_end(b, i);
            out.extend_from_slice(&b[i..end]);
            i = end;
            continue;
        }
        // Preserve url(...) bodies (trimming outer whitespace around the target).
        if (c == b'u' || c == b'U')
            && b.get(i..i + 4).is_some_and(|s| s.eq_ignore_ascii_case(b"url("))
            && (i == 0 || !is_ident_char(b[i - 1]))
        {
            if pending_space {
                if let Some(prev) = out.last().copied()
                    && !trim_space_after(prev, !calc_depths.is_empty())
                {
                    out.push(b' ');
                }
                pending_space = false;
            }
            let (end, body) = raw_url_body(text, i + 4);
            out.extend_from_slice(b"url(");
            out.extend_from_slice(body.trim().as_bytes());
            out.push(b')');
            i = end;
            continue;
        }
        // Collapse whitespace runs.
        if c.is_ascii_whitespace() {
            while i < b.len() && b[i].is_ascii_whitespace() {
                i += 1;
            }
            if !out.is_empty() {
                pending_space = true;
            }
            continue;
        }
        // Track custom property declarations (`--foo: ...;`) so spaces after `:` aren't stripped if needed.
        if c == b'-' && b.get(i + 1) == Some(&b'-') && matches!(out.last(), None | Some(b'{') | Some(b';')) {
            in_custom_prop = true;
        } else if c == b';' || c == b'}' {
            in_custom_prop = false;
        }
        // Track calc(...) / min(...) / max(...) / clamp(...) so spaces around `+` / `-` survive.
        if c == b'(' {
            paren_depth += 1;
            let tail_start = out.len().saturating_sub(5);
            let tail = &out[tail_start..];
            if ends_with_ascii_ci(tail, b"calc")
                || ends_with_ascii_ci(tail, b"min")
                || ends_with_ascii_ci(tail, b"max")
                || ends_with_ascii_ci(tail, b"clamp")
            {
                calc_depths.push(paren_depth);
            }
        } else if c == b')' {
            if calc_depths.last() == Some(&paren_depth) {
                calc_depths.pop();
            }
            paren_depth = paren_depth.saturating_sub(1);
        }
        // Drop redundant `;` right before `}`.
        if c == b'}' && out.last() == Some(&b';') {
            out.pop();
        }
        let in_calc = !calc_depths.is_empty();
        // Flush pending space unless safely removable.
        if pending_space {
            pending_space = false;
            let prev = out.last().copied();
            let keep = match prev {
                None => false,
                Some(b':') if in_custom_prop => false,
                // Keep space before `:` in selectors (`div :hover` vs `div:hover`) when previous is ident/wildcard/bracket/paren!
                Some(p) if c == b':' => is_ident_char(p) || p == b')' || p == b']' || p == b'*',
                Some(p) if trim_space_before(c, in_calc) || trim_space_after(p, in_calc) => false,
                Some(_) => true,
            };
            if keep {
                out.push(b' ');
            }
        }
        // Shorten 6-digit hex colors `#rrggbb` -> `#rgb` in declaration values only (never in ID selectors).
        if c == b'#'
            && i + 7 <= b.len()
            && b[i + 1..i + 7].iter().all(|x| x.is_ascii_hexdigit())
            && !b.get(i + 7).is_some_and(|x| is_ident_char(*x))
            && b[i + 1].eq_ignore_ascii_case(&b[i + 2])
            && b[i + 3].eq_ignore_ascii_case(&b[i + 4])
            && b[i + 5].eq_ignore_ascii_case(&b[i + 6])
            && in_decl_value(b, i + 7)
        {
            out.push(b'#');
            out.push(b[i + 1].to_ascii_lowercase());
            out.push(b[i + 3].to_ascii_lowercase());
            out.push(b[i + 5].to_ascii_lowercase());
            i += 7;
            continue;
        }
        // Preserve backslash escapes as a complete UTF-8 sequence.
        if c == b'\\' && i + 1 < b.len() {
            let ch_len = text[i + 1..].chars().next().map_or(0, char::len_utf8);
            out.extend_from_slice(&b[i..i + 1 + ch_len]);
            i += 1 + ch_len;
            continue;
        }
        // Copy multi-byte UTF-8 characters intact.
        if c >= 0x80 {
            let ch_len = text[i..].chars().next().map_or(1, char::len_utf8);
            out.extend_from_slice(&b[i..i + ch_len]);
            i += ch_len;
            continue;
        }
        out.push(c);
        i += 1;
    }
    String::from_utf8(out).expect("minifier preserves UTF-8 boundaries")
}

fn ends_with_ascii_ci(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.len() >= needle.len() && haystack[haystack.len() - needle.len()..].eq_ignore_ascii_case(needle)
}

/// Whether byte position `pos` sits inside a declaration value rather than a selector: scanning
/// forward past strings and comments, a declaration value ends at `;` or `}` before any `{`.
fn in_decl_value(b: &[u8], mut i: usize) -> bool {
    while i < b.len() {
        match b[i] {
            b'"' | b'\'' => i = string_end(b, i),
            b'/' if b.get(i + 1) == Some(&b'*') => {
                i += 2;
                while i + 1 < b.len() && !(b[i] == b'*' && b[i + 1] == b'/') {
                    i += 1;
                }
                i = (i + 2).min(b.len());
            }
            b'\\' => i = (i + 2).min(b.len()),
            b'{' => return false,
            b';' | b'}' => return true,
            _ => i += 1,
        }
    }
    false
}

fn is_ident_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_' || c == b'-' || c >= 0x80
}

fn string_end(b: &[u8], start: usize) -> usize {
    let quote = b[start];
    let mut i = start + 1;
    while i < b.len() {
        match b[i] {
            b'\\' => i += 2,
            c if c == quote => return i + 1,
            b'\n' => return i,
            _ => i += 1,
        }
    }
    b.len()
}

fn raw_url_body(text: &str, start: usize) -> (usize, &str) {
    let b = text.as_bytes();
    let mut i = start;
    while i < b.len() && b[i].is_ascii_whitespace() {
        i += 1;
    }
    if i < b.len() && (b[i] == b'"' || b[i] == b'\'') {
        let end = string_end(b, i);
        let close = text[end..].find(')').map_or(b.len(), |e| end + e + 1);
        return (close, &text[start..close.saturating_sub(1).max(start)]);
    }
    let close = text[start..].find(')').map_or(b.len(), |e| start + e);
    (close.saturating_add(1).min(b.len()), &text[start..close])
}

#[cfg(test)]
mod tests {
    use super::*;

    fn classes(text: &str) -> Vec<String> {
        scan(text, true)
            .unwrap()
            .classes
            .into_iter()
            .map(|(_, _, c)| c)
            .collect()
    }

    #[test]
    fn class_selectors_are_found_outside_declarations_strings_and_urls() {
        assert_eq!(
            classes(".note { max-width: 42rem; } .back a.dek:hover, .x-y > .a_b { color: red; }"),
            ["note", "back", "dek", "x-y", "a_b"]
        );
        // Numbers, strings, comments and url() bodies hold no classes.
        assert_eq!(
            classes("/* .c */ .a { margin: .5rem; content: \".s\"; background: url(/x.y/z.png); }"),
            ["a"]
        );
        // At-rule preludes are not selectors; rules inside @media and :is()/:not() are.
        assert_eq!(classes("@media (min-width: .5em) { .a { x: 1 } }"), ["a"]);
        assert_eq!(classes("@font-face { src: url(/f.woff2); } .b {}"), ["b"]);
        assert_eq!(
            classes(".card:is(.active, :not(.muted)) { &.inner {} }"),
            ["card", "active", "muted", "inner"]
        );
    }

    #[test]
    fn renaming_keeps_everything_else_byte_for_byte() {
        let text = ".note{max-width:42rem}\n.back .note { color: #47524c }\n";
        let scanned = scan(text, true).unwrap();
        assert_eq!(
            rename(text, &scanned, |c| format!("note_{c}")),
            ".note_note{max-width:42rem}\n.note_back .note_note { color: #47524c }\n"
        );
    }

    #[test]
    fn refusals_and_imports_are_parsed_from_ast() {
        let refused = |t: &str, module: bool| scan(t, module).unwrap_err().message;
        assert!(refused(":global(.a) {}", true).contains(":global"));
        assert!(refused(".a { composes: b; }", true).contains("composes"));
        // A plain stylesheet keeps its selectors as written, :global included.
        assert!(scan(":global(.a) { background: url(data:image/png;base64,AA) }", false).is_ok());
        assert!(scan(".a { background: url(\"https://x.test/a.png\") }", false).is_ok());
        // @import rules capture href, layer, supports, and media.
        let sheet = scan(
            "@import \"./reset.css\";\n@import url(\"./print.css\") layer(base) screen and (min-width: 40rem);\n.a {}\n",
            false,
        )
        .unwrap();
        assert_eq!(sheet.imports.len(), 2);
        assert_eq!(sheet.imports[0].href, "./reset.css");
        assert_eq!(sheet.imports[1].href, "./print.css");
        assert_eq!(sheet.imports[1].layer.as_deref(), Some("base"));
        assert_eq!(sheet.imports[1].media.as_deref(), Some("screen and (min-width: 40rem)"));
    }

    #[test]
    fn relative_urls_and_font_faces_are_collected() {
        let css = r#"/* url(./ignored.png) */
@font-face { font-family: Inter; src: url("../fonts/inter.woff2?v=1#f") format("woff2"), url("../fonts/inter.ttf") format("truetype"); }
.a { content: "url(./ignored.png)"; background: url(./hero.png); }
.c { background: image-set(url('img/logo.svg#mark') 1x, url(/public.png) 2x); }
.e { background: url(https://cdn.example/hero.png); }
"#;
        let scanned = scan(css, false).unwrap();
        let targets: Vec<(&str, Option<usize>)> =
            scanned.urls.iter().map(|u| (u.target.as_str(), u.font_face)).collect();
        assert_eq!(
            targets,
            [
                ("../fonts/inter.woff2?v=1#f", Some(0)),
                ("../fonts/inter.ttf", Some(0)),
                ("./hero.png", None),
                ("img/logo.svg#mark", None),
            ]
        );
    }

    #[test]
    fn minifier_strips_comments_and_whitespace_safely() {
        let css = "/* header */\n:root { --ink: #112233; }\nbody { margin: 0; width: calc(100% - 2rem); color: #ffffff; }\n.a > .b, .c .d { content: \"a  b\"; background: url( ./hero.png ); }\n.parent :hover { color: red; }\n#aabbcc { color: #aabbcc; }\n.café, .ux界 { color: red; }\n";
        assert_eq!(
            minify(css),
            ":root{--ink:#123}body{margin:0;width:calc(100% - 2rem);color:#fff}.a>.b,.c .d{content:\"a  b\";background:url(./hero.png)}.parent :hover{color:red}#aabbcc{color:#abc}.café,.ux界{color:red}"
        );
    }
}
