//! Best-effort literal cookie-write detection, not a JavaScript security boundary.
//! Parsing and decompression are bounded; ordinary prose is not code.
use oxc_allocator::Allocator;
use oxc_ast::ast::{
    AssignmentExpression, AssignmentTarget, CallExpression, Expression, ObjectPropertyKind,
    PropertyKind,
};
use oxc_ast_visit::{walk, Visit};
use oxc_parser::Parser;
use oxc_span::{GetSpan, SourceType};
use std::collections::BTreeSet;
use std::fs::File;
use std::io::{self, Read};
use std::ops::ControlFlow;
use std::path::Path;

use serde_json::json;

use crate::catalog::FileCatalog;
use crate::finalize::{invalid_with_details, FinalizeError, Result};
use crate::storage::blob_path;

#[path = "html_scripts.rs"]
mod html_scripts;

const MAX_TOKEN: usize = 8192;
const MAX_SCRIPT: usize = 1024 * 1024;
const MAX_PARSE_DEPTH: usize = 128;
const MAX_PARSE_STEPS: usize = 10_000;
const MAX_DECOMPRESSED: u64 = 64 * 1024 * 1024;

fn apex<'a>(domain: &str, apexes: &'a [String]) -> Option<&'a str> {
    let domain = domain.trim().strip_prefix('.').unwrap_or(domain.trim());
    apexes
        .iter()
        .find(|apex| domain.eq_ignore_ascii_case(apex))
        .map(String::as_str)
}

/// Oxc has no recursion limit. Tree-sitter uses an explicit parser stack and
/// bounded work callbacks; measure its concrete syntax tree before entering Oxc.
/// Count anonymous nodes too, including parentheses and punctuation.
fn within_parse_budget(source: &str, source_type: SourceType) -> bool {
    let language = if source_type.is_typescript() {
        if source_type.is_jsx() {
            tree_sitter_typescript::LANGUAGE_TSX
        } else {
            tree_sitter_typescript::LANGUAGE_TYPESCRIPT
        }
    } else {
        tree_sitter_javascript::LANGUAGE
    };
    let mut parser = tree_sitter::Parser::new();
    parser
        .set_language(&language.into())
        .expect("bundled grammar matches parser");
    let mut steps = 0;
    let mut progress = |_state: &tree_sitter::ParseState| {
        steps += 1;
        if steps > MAX_PARSE_STEPS {
            ControlFlow::Break(())
        } else {
            ControlFlow::Continue(())
        }
    };
    let options = tree_sitter::ParseOptions::new().progress_callback(&mut progress);
    let Some(tree) = parser.parse_with_options(
        &mut |offset, _| &source.as_bytes()[offset..],
        None,
        Some(options),
    ) else {
        return false;
    };
    let mut cursor = tree.walk();
    let mut depth = 0;
    let mut recovery_bytes = 0;
    loop {
        let node = cursor.node();
        // Tree-sitter accepts a different set of contextual identifiers than
        // Oxc. Charge recovery regions conservatively instead of using them
        // as either syntax evidence or a way to hide recursive expressions.
        if node.is_error() || node.is_missing() {
            recovery_bytes += node.byte_range().len().max(1);
        }
        if depth + recovery_bytes > MAX_PARSE_DEPTH {
            return false;
        }
        if cursor.goto_first_child() {
            depth += 1;
            if depth > MAX_PARSE_DEPTH {
                return false;
            }
            continue;
        }
        while !cursor.goto_next_sibling() {
            if !cursor.goto_parent() {
                return true;
            }
            depth -= 1;
        }
    }
}

struct CookieVisitor<'s> {
    source: &'s str,
    apexes: &'s [String],
    finding: Option<(String, usize)>,
}

impl CookieVisitor<'_> {
    fn literal<'s>(&'s self, expression: &Expression<'_>) -> Option<(&'s str, usize)> {
        let expression = expression.get_inner_expression();
        if !matches!(
            expression,
            Expression::StringLiteral(_) | Expression::TemplateLiteral(_)
        ) {
            return None;
        }
        if matches!(expression, Expression::TemplateLiteral(template) if !template.expressions.is_empty())
        {
            return None;
        }
        let span = expression.span();
        let raw = &self.source[span.start as usize..span.end as usize];
        let value = raw.get(1..raw.len().checked_sub(1)?)?;
        (value.len() <= MAX_TOKEN && !value.contains('\\')).then_some((value, span.start as usize))
    }

    fn record(&mut self, domain: &str, offset: usize) {
        // AST traversal order can differ from source order for nested assignments.
        if self
            .finding
            .as_ref()
            .is_none_or(|(_, previous)| offset < *previous)
        {
            self.finding = Some((domain.into(), offset));
        }
    }
}

impl<'a> Visit<'a> for CookieVisitor<'_> {
    fn visit_assignment_expression(&mut self, assignment: &AssignmentExpression<'a>) {
        let cookie = match &assignment.left {
            AssignmentTarget::StaticMemberExpression(member) => {
                member.object.is_specific_id("document") && member.property.name == "cookie"
            }
            AssignmentTarget::ComputedMemberExpression(member) => {
                member.object.is_specific_id("document")
                    && self
                        .literal(&member.expression)
                        .is_some_and(|(key, _)| key == "cookie")
            }
            _ => false,
        };
        if cookie && assignment.operator.is_assign() {
            if let Some((value, offset)) = self.literal(&assignment.right) {
                let mut domain = None;
                for attribute in value.split(';').skip(1) {
                    let Some((name, value)) = attribute.split_once('=') else {
                        continue;
                    };
                    if name.trim().eq_ignore_ascii_case("domain") {
                        domain = apex(value, self.apexes);
                    }
                }
                if let Some(domain) = domain {
                    self.record(domain, offset);
                }
            }
        }
        walk::walk_assignment_expression(self, assignment);
    }

    fn visit_call_expression(&mut self, call: &CallExpression<'a>) {
        let options_index = match call.callee.get_inner_expression() {
            Expression::Identifier(name) => matches!(
                name.name.as_str(),
                "setCookie" | "serializeCookie" | "cookie"
            )
            .then_some(2),
            Expression::StaticMemberExpression(member) => {
                let method = member.property.name.as_str();
                let object = match member.object.get_inner_expression() {
                    Expression::Identifier(name) => Some(name.name.as_str()),
                    Expression::StaticMemberExpression(object) => {
                        Some(object.property.name.as_str())
                    }
                    _ => None,
                };
                if matches!(method, "setCookie" | "serializeCookie" | "cookie") {
                    Some(2)
                } else if object == Some("cookieStore") {
                    (method == "set" && call.arguments.len() == 1).then_some(0)
                } else if object.is_some_and(|name| name.to_ascii_lowercase().contains("cookie")) {
                    match method {
                        "set" | "serialize" => Some(2),
                        "remove" => Some(1),
                        _ => None,
                    }
                } else {
                    None
                }
            }
            _ => None,
        };
        if let Some(Expression::ObjectExpression(options)) = options_index
            .and_then(|index| call.arguments.get(index))
            .and_then(|argument| argument.as_expression())
            .map(Expression::get_inner_expression)
        {
            // Only direct options count. Spreads and duplicate/computed keys
            // can replace an earlier domain, so do not infer through them.
            let mut domain = None;
            for property in &options.properties {
                match property {
                    ObjectPropertyKind::SpreadProperty(_) => domain = None,
                    ObjectPropertyKind::ObjectProperty(property) => {
                        if property.computed {
                            domain = None;
                        } else if property.key.is_specific_static_name("domain") {
                            domain = if property.kind == PropertyKind::Init {
                                self.literal(&property.value).and_then(|(value, offset)| {
                                    apex(value, self.apexes)
                                        .map(|domain| (domain.to_owned(), offset))
                                })
                            } else {
                                None
                            };
                        }
                    }
                }
            }
            if let Some((domain, offset)) = domain {
                self.record(&domain, offset);
            }
        }
        walk::walk_call_expression(self, call);
    }
}

fn detect_script(
    source: &str,
    source_type: SourceType,
    apexes: &[String],
) -> Option<(String, usize)> {
    if source.len() > MAX_SCRIPT || !within_parse_budget(source, source_type) {
        return None;
    }
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, source_type).parse();
    if parsed.fatal_error || parsed.diagnostics.errors().next().is_some() {
        return None;
    }
    let mut visitor = CookieVisitor {
        source,
        apexes,
        finding: None,
    };
    visitor.visit_program(&parsed.program);
    visitor.finding
}

fn line_breaks(source: &str) -> usize {
    let mut previous_cr = false;
    source
        .chars()
        .filter(|c| {
            let newline =
                matches!(c, '\r' | '\u{2028}' | '\u{2029}') || (*c == '\n' && !previous_cr);
            previous_cr = *c == '\r';
            newline
        })
        .count()
}

/// Returns only domain and line, never the cookie name/value or source snippet.
fn detect(reader: impl Read, html: bool, apexes: &[String]) -> io::Result<Option<(String, usize)>> {
    detect_as(reader, html, SourceType::unambiguous(), apexes)
}

fn detect_as(
    reader: impl Read,
    html: bool,
    source_type: SourceType,
    apexes: &[String],
) -> io::Result<Option<(String, usize)>> {
    let mut bytes = Vec::new();
    reader.take(MAX_DECOMPRESSED).read_to_end(&mut bytes)?;
    let Ok(source) = std::str::from_utf8(&bytes) else {
        return Ok(None);
    };
    if !html {
        return Ok(detect_script(source, source_type, apexes)
            .map(|(domain, offset)| (domain, 1 + line_breaks(&source[..offset]))));
    }
    for script in html_scripts::scripts(source) {
        if let Some((domain, offset)) = detect_script(script.text(source), script.kind, apexes) {
            return Ok(Some((
                domain,
                1 + line_breaks(&source[..script.source_offset(offset)]),
            )));
        }
    }
    Ok(None)
}

fn script_kind(path: &str, mime: &str) -> Option<bool> {
    let extension = Path::new(path)
        .extension()
        .and_then(|value| value.to_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    match extension.as_str() {
        "js" | "mjs" | "cjs" | "jsx" | "ts" | "tsx" => Some(false),
        "html" | "htm" | "svg" | "xhtml" => Some(true),
        _ => match mime.split(';').next().unwrap_or("").trim() {
            "application/javascript"
            | "text/javascript"
            | "application/ecmascript"
            | "text/ecmascript" => Some(false),
            "text/html" | "application/xhtml+xml" | "image/svg+xml" => Some(true),
            _ => None,
        },
    }
}

fn refuse(path: &str, sha256: &str, domain: &str, line: usize) -> Result<()> {
    invalid_with_details(
        "shared_apex_cookie_write",
        format!(
            "{path}:{line} writes a cookie for shared domain {domain}. Use a host-only cookie or this Space's full hostname."
        ),
        json!({"path":path,"line":line,"domain":domain,"sha256":sha256,"pointer":"/files"}),
    )
}

pub(crate) fn validate_embedded_html(body: &serde_json::Value, apexes: &[String]) -> Result<()> {
    if apexes.is_empty() {
        return Ok(());
    }
    for field in ["page_artifacts", "access_pages", "layout_template"] {
        let Some(value) = body.get(field) else {
            continue;
        };
        let entries = match value.as_object() {
            Some(values) => values
                .iter()
                .map(|(key, value)| (format!("{field}/{key}.html"), value))
                .collect::<Vec<_>>(),
            None => vec![(format!("{field}.html"), value)],
        };
        for (path, value) in entries {
            if let Some(text) = value.as_str() {
                if let Some((domain, line)) =
                    detect(text.as_bytes(), true, apexes).expect("in-memory text read cannot fail")
                {
                    refuse(
                        &path,
                        &crate::finalize::sha256(text.as_bytes()),
                        &domain,
                        line,
                    )?;
                }
            }
        }
    }
    Ok(())
}

pub(crate) fn validate_cookie_writes(
    blobs: &Path,
    catalog: &FileCatalog,
    apexes: &[String],
) -> Result<()> {
    if apexes.is_empty() {
        return Ok(());
    }
    let mut scanned = BTreeSet::new();
    let objects = catalog
        .paths
        .iter()
        .flat_map(|(path, entry)| {
            std::iter::once((path, &entry.source))
                .chain(entry.served.iter().map(move |object| (path, object)))
        })
        .chain(catalog.variants.values().flat_map(|paths| paths.iter()));
    for (path, object) in objects {
        let source = crate::serving_paths::precompressed_source(path).unwrap_or(path);
        let encoding = if source.len() == path.len() {
            ""
        } else if path[path.len() - 3..].eq_ignore_ascii_case(".gz") {
            "gz"
        } else {
            "br"
        };
        let Some(html) = script_kind(source, &object.content_type) else {
            continue;
        };
        if !scanned.insert((&object.sha256, source, html, encoding)) {
            continue;
        }
        let full_path = blob_path(blobs, &object.sha256);
        let scan = || -> io::Result<Option<(String, usize)>> {
            let file = File::open(&full_path)?;
            let reader: Box<dyn Read> = match encoding {
                "gz" => Box::new(flate2::read::MultiGzDecoder::new(file)),
                "br" => Box::new(brotli::Decompressor::new(file, 8192)),
                _ => Box::new(file),
            };
            // Reaching the inspection budget is not evidence against an artifact.
            // Decode errors within the inspected prefix still propagate.
            detect_as(
                reader,
                html,
                match Path::new(source).extension().and_then(|v| v.to_str()) {
                    Some("mjs") => SourceType::mjs(),
                    Some("cjs") => SourceType::cjs(),
                    Some("jsx") => SourceType::jsx().with_unambiguous(true),
                    Some("ts") => SourceType::ts().with_unambiguous(true),
                    Some("tsx") => SourceType::tsx().with_unambiguous(true),
                    _ => SourceType::unambiguous(),
                },
                apexes,
            )
        };
        let finding = scan().map_err(|error| FinalizeError::Invalid {
            code: "artifact_cookie_scan_failed",
            message: format!("Could not inspect script text in {path}: {error}"),
            details: Some(json!({"path":path,"pointer":"/files"})),
        })?;
        if let Some((domain, line)) = finding {
            refuse(path, &object.sha256, &domain, line)?;
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    fn scan(source: &str, html: bool) -> Option<(String, usize)> {
        detect(
            source.as_bytes(),
            html,
            &[
                "space.fast".into(),
                "view.fast".into(),
                "partner.test".into(),
            ],
        )
        .unwrap()
    }
    #[test]
    fn literal_writes_require_cookie_context_and_exact_apex() {
        for (source, source_type) in [
            (
                "const value: number = 1; document.cookie = 'x=y; domain=space.fast';",
                SourceType::ts(),
            ),
            (
                "const element = <div/>; document.cookie = 'x=y; domain=space.fast';",
                SourceType::jsx(),
            ),
            (
                "const element: unknown = <div/>; document.cookie = 'x=y; domain=space.fast';",
                SourceType::tsx(),
            ),
        ] {
            assert_eq!(
                detect_as(
                    source.as_bytes(),
                    false,
                    source_type,
                    &["space.fast".into()]
                )
                .unwrap(),
                Some(("space.fast".into(), 1))
            );
        }
        assert_eq!(
            scan(
                "\ndocument.cookie = 'session=x; Domain= .SpAcE.FaSt ; Path=/'",
                false
            ),
            Some(("space.fast".into(), 2))
        );
        assert_eq!(
            scan(
                "window.Cookies.set('session', value, { domain: ' .VIEW.FAST ' })",
                false
            ),
            Some(("view.fast".into(), 1))
        );
        assert_eq!(
            scan(
                "cookieStore.set({name: 'x', value, 'domain': 'partner.test'})",
                false
            ),
            Some(("partner.test".into(), 1))
        );
        assert_eq!(
            scan("document['cookie'] = `x=y; domain=view.fast`", false),
            Some(("view.fast".into(), 1))
        );
        assert_eq!(
            scan("document.cookie = ('x=y; domain=space.fast');", false),
            Some(("space.fast".into(), 1))
        );
        for source in [
            "Cookies.set('prefs', {domain: 'space.fast'});",
            "Cookies.set('prefs', {domain: 'space.fast'}, {domain: 'own.space.fast'});",
            "const cookie = 'title'; document[cookie] = 'x=y; domain=space.fast';",
            "document.cookie = 'x=y; domain=space.fast' + '.example';",
            "document.cookie = 'x=y; domain=space.fast' + suffix;",
            "Cookies.set('x', 'y', {domain: 'own.space.fast', config: {domain: 'space.fast'}});",
            "Cookies.set('x', 'y', {domain: 'space.fast' + '.example'});",
            "Cookies.set('x', 'y', {domain: 'space.fast', ...options});",
            "Cookies.set('x', 'y', {domain: 'space.fast', domain: 'own.space.fast'});",
        ] {
            assert_eq!(scan(source, false), None, "{source}");
        }
        assert_eq!(
            scan(
                r#"const pattern = /document.cookie = 'x=y; domain=space.fast'/"#,
                false
            ),
            None
        );
        for source in [
            r#"function f() {} function g() {} /document.cookie = 'x=y; domain=space.fast'/;"#,
            r#"{} {} /document.cookie = 'x=y; domain=space.fast'/;"#,
            r#"async function f() { for await (const x of []) /document.cookie = 'x=y; domain=space.fast'/; }"#,
            r#"if ((true)) /document.cookie = 'x=y; domain=space.fast'/.test('text');"#,
            r#"if (false) {} else /document.cookie = 'x=y; domain=space.fast'/.test('text');"#,
            r#"{ if (true) {} } /document.cookie = 'x=y; domain=space.fast'/.test('text');"#,
            r#"for (;false;) /[/'"]document.cookie = 'x=y; domain=space.fast'/.test('text');"#,
            r#"function f() {} /https?:\/\/document.cookie = 'x=y; domain=space.fast'/.test('text');"#,
        ] {
            assert_eq!(scan(source, false), None, "{source}");
        }
        // Each review class is independent: ASI statements, spread expressions,
        // contextual identifiers, and all ECMAScript line terminators.
        for source in [
            "debugger\n/document.cookie = 'x=y; domain=space.fast'/;",
            "while(true) { break\n/document.cookie = 'x=y; domain=space.fast'/; }",
            "outer: while(true) { break outer\n/document.cookie = 'x=y; domain=space.fast'/; }",
            "for(let i=0;i<1;i++) { continue\n/document.cookie = 'x=y; domain=space.fast'/; }",
        ] {
            assert_eq!(scan(source, false), None, "{source}");
        }
        for separator in ["\r", "\r\n", "\u{2028}", "\u{2029}"] {
            let source = format!(
                "function f(){{return{separator}function g(){{}} /document.cookie = 'x=y; domain=space.fast'/;}} f();"
            );
            assert_eq!(scan(&source, false), None, "{source}");
            let write = format!("// comment{separator}document.cookie = 'x=y; domain=space.fast';");
            assert_eq!(
                scan(&write, false),
                Some(("space.fast".into(), 2)),
                "{write}"
            );
        }
        for source in [
            "const obj={...function(){} / (document.cookie = 'x=y; domain=space.fast') / 2};",
            "const obj={...class {} / (document.cookie = 'x=y; domain=space.fast') / 2};",
            "const of=1; of / (document.cookie = 'x=y; domain=space.fast') / 2;",
            "const await=1; await / (document.cookie = 'x=y; domain=space.fast') / 2;",
            "const yield=1; yield / (document.cookie = 'x=y; domain=space.fast') / 2;",
        ] {
            assert_eq!(
                scan(source, false),
                Some(("space.fast".into(), 1)),
                "{source}"
            );
        }
        assert_eq!(
            scan(
                r#"var r=/[&<>"']/g;var doc="Avoid document.cookie = 'a=b; domain=space.fast' here";"#,
                false
            ),
            None
        );
        assert_eq!(
            scan(
                r#"var r=/https?:\/\//;document.cookie = 'x=y; domain=view.fast'"#,
                false
            ),
            Some(("view.fast".into(), 1))
        );
        for source in [
            "const ratio = count / total; document.cookie = 'x=y; domain=view.fast'",
            "call() / (document.cookie = 'x=y; domain=view.fast') / 2",
            "const ratio = {} / (document.cookie = 'x=y; domain=view.fast') / 2",
            "const ratio = false ? 0 : async function() {} / (document.cookie = 'x=y; domain=view.fast') / 2",
            "const ratio = async function() {} / (document.cookie = 'x=y; domain=view.fast') / 2",
            "const ratio = function() {} / (document.cookie = 'x=y; domain=view.fast') / 2",
            "const ratio = (() => {}) / (document.cookie = 'x=y; domain=view.fast') / 2",
            "count++ / (document.cookie = 'x=y; domain=view.fast') / 2",
            "obj.if(true) / (document.cookie = 'x=y; domain=view.fast') / 2",
            "if (true) /document.cookie = 'x=y; domain=space.fast'/; document.cookie = 'x=y; domain=view.fast'",
        ] {
            assert_eq!(
                scan(source, false),
                Some(("view.fast".into(), 1)),
                "{source}"
            );
        }
        assert_eq!(
            scan(
                r#"// document.cookie = 'x=y; domain=space.fast'
/* Cookies.set('x', 'y', {domain:'view.fast'}) */
const docs = "document.cookie = 'x=y; domain=space.fast'";
const config = {domain: 'space.fast'};
document.cookie = 'x=y; domain=one.space.fast';
Cookies.set('x', 'y', {domain: 'space.fast.example'});
fetch('https://space.fast');
document.cookie = 'domain=space.fast';"#,
                false
            ),
            None
        );
    }
    #[test]
    fn html_scans_scripts_but_leaves_prose_and_comments_alone() {
        assert_eq!(
            scan(
                "<template><math></template>\r\n<script>document.cookie='x=y; domain=space.fast';</script>",
                true
            ),
            Some(("space.fast".into(), 2))
        );
        assert_eq!(
            scan(
                "<svg><script language='plain'>document.cookie='x=y; domain=space.fast';</script></svg>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        for source in [
            "<p><script>document.cookie='x=y; domain=space.fast';</script></p><frameset>",
            "<x-!><template shadowrootmode=open><script>document.cookie='x=y; domain=space.fast';</script></template></x-!>",
            "<script for=' WINDOW ' event=' ONLOAD() '>document.cookie='x=y; domain=space.fast';</script>",
            "<script type=module for=x event=onload>document.cookie='x=y; domain=space.fast';</script>",
            "<script nomodule>document.cookie='x=y; domain=space.fast';</script>",
            "<div><template shadowrootmode='open'><script>document.cookie='x=y; domain=space.fast';</script></template></div>",
            "<div><template shadowrootmode='closed'><script>document.cookie='x=y; domain=space.fast';</script></template></div>",
            "<svg><script><![CDATA[document.cookie='x=y; domain=space.fast';]]></script></svg>",
            "<svg><script><!-- inert -->document.cookie='x=y; domain=space.fast';</script></svg>",
            "<svg><script>document.<g></g>cookie='x=y; domain=space.fast';</script></svg>",
            "<x-example><template shadowrootmode='OPEN'><script>document.cookie='x=y; domain=space.fast';</script></template></x-example>",
        ] {
            assert_eq!(
                scan(source, true),
                Some(("space.fast".into(), 1)),
                "{source}"
            );
        }
        for source in [
            "<template><script>document.cookie='x=y; domain=space.fast';</script></template>",
            "<script for=x event=onload>document.cookie='x=y; domain=space.fast';</script>",
            "<div><template shadowrootmode='invalid'><script>document.cookie='x=y; domain=space.fast';</script></template></div>",
            "<a><template shadowrootmode='open'><script>document.cookie='x=y; domain=space.fast';</script></template></a>",
            "<div><template shadowrootmode='open'></template><template shadowrootmode='closed'><script>document.cookie='x=y; domain=space.fast';</script></template></div>",
            "<template><div><template shadowrootmode='open'><script>document.cookie='x=y; domain=space.fast';</script></template></div></template>",
            "<svg><script><g>document.cookie='x=y; domain=space.fast';</g></script></svg>",
            "<math><script>document.cookie='x=y; domain=space.fast';</script></math>",
            "<svg><script href='/safe.js'>document.cookie='x=y; domain=space.fast';</script></svg>",
            "<svg><script xlink:href='/safe.js'>document.cookie='x=y; domain=space.fast';</script></svg>",
        ] {
            assert_eq!(scan(source, true), None, "{source}");
        }
        assert_eq!(
            scan(
                "<template><template></template><script>document.cookie='x=y; domain=space.fast';</script></template><script>document.cookie='x=y; domain=view.fast';</script>",
                true
            ),
            Some(("view.fast".into(), 1))
        );
        assert_eq!(
            scan(
                "<svg><script src='/safe.js'>document.cookie='x=y; domain=space.fast';</script></svg>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        assert_eq!(
            scan(
                "<svg><script><script>const text = 0;</script></script></svg>",
                true
            ),
            None
        );
        assert_eq!(
            scan(
                r#"<script>if (true) /document.cookie = 'x=y; domain=space.fast'/;</script><script>{} /document.cookie = 'x=y; domain=space.fast'/;</script>"#,
                true,
            ),
            None
        );
        assert_eq!(
            scan(
                "<script>const await=1; await / (document.cookie = 'x=y; domain=space.fast') / 2;</script>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        assert_eq!(
            scan(
                "<script type=module>await /document.cookie = 'x=y; domain=space.fast'/;</script>",
                true
            ),
            None
        );
        assert_eq!(
            scan(
                "<script>document.cookie =</script><script>'x=y; domain=space.fast';</script>",
                true
            ),
            None
        );
        assert_eq!(
            scan(
                "<script type=module>export {}; document.cookie = 'x=y; domain=space.fast';</script>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        assert_eq!(
            scan(
                r#"<textarea><script>document.cookie = 'x=y; domain=space.fast'</script></textarea><style>x{content:"<script>document.cookie = 'x=y; domain=space.fast'</script>"}</style><a title="<script>document.cookie = 'x=y; domain=space.fast'</script>">docs</a>"#,
                true
            ),
            None
        );
        assert_eq!(
            scan(
                r#"<script type="text/plain">document.cookie = 'x=y; domain=space.fast'</script>"#,
                true
            ),
            None
        );
        for attributes in [
            r#"data-type="text/plain""#,
            r#"title="type='text/plain'""#,
            r#"type="text&#47;javascript""#,
            r#"language="javascript""#,
            r#"language="javascript1.5""#,
            r#"type="text/jscript""#,
            "type=' \tTEXT/JAVASCRIPT\t '",
            "type='text/javascript\u{b}'",
        ] {
            let html = format!(
                "<script {attributes}>document.cookie = 'x=y; domain=space.fast';</script>"
            );
            assert_eq!(scan(&html, true), Some(("space.fast".into(), 1)), "{html}");
        }
        assert_eq!(
            scan(
                "<script>const text = '</scriptx>'; document.cookie = 'x=y; domain=space.fast';</script>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        for attributes in [
            "src='/safe.js'",
            "src=''",
            "language='plain'",
            "type='text/javascript\u{a0}'",
            "type=' '",
            "type=' module '",
            "type='text/javascript; charset=utf-8'",
        ] {
            assert_eq!(
                scan(
                    &format!(
                        "<script {attributes}>document.cookie = 'x=y; domain=space.fast';</script>"
                    ),
                    true
                ),
                None
            );
        }
        assert_eq!(
            scan(
                "<p>document.cookie = 'x=y; domain=space.fast'</p><!-- <script>document.cookie = 'x=y; domain=space.fast'</script> -->",
                true
            ),
            None
        );
        assert_eq!(
            scan(
                "<p>space.fast</p>\n<SCRIPT>document.cookie = 'x=y; domain=space.fast'</SCRIPT>",
                true
            ),
            Some(("space.fast".into(), 2))
        );
    }
    #[test]
    fn script_reader_preserves_lines_and_skips_incomplete_or_over_budget_programs() {
        let source = format!(
            "{}\ndocument /* gap */ . cookie = 'x=y; domain=view.fast'",
            " ".repeat(100_000)
        );
        assert_eq!(scan(&source, false), Some(("view.fast".into(), 2)));
        for prefix in [
            "function f(a) { return a + 1; }\n".repeat(200),
            "h(\"div\", { class: \"btn\" });\n".repeat(200),
            "var x = 1;\n".repeat(1000),
        ] {
            let source = format!("{prefix}document.cookie = 'x=y; domain=space.fast';");
            assert_eq!(
                scan(&source, false),
                Some(("space.fast".into(), prefix.lines().count() + 1))
            );
            assert_eq!(
                scan(&format!("<script>{source}</script>"), true),
                Some(("space.fast".into(), prefix.lines().count() + 1))
            );
        }
        assert_eq!(
            scan(
                "<p>é\r\n😀</p><svg><script><![CDATA[// é\r\n]]><!-- \n -->document.cookie='x=y; domain=space.fast';</script></svg>",
                true
            ),
            Some(("space.fast".into(), 4))
        );
        assert_eq!(
            scan(
                "<svg><script>// é&#10;document.cookie=&apos;x=y; domain=space.fast&apos;;</script></svg>",
                true
            ),
            Some(("space.fast".into(), 1))
        );
        // Never treat a parsed prefix or recovered tokens as a whole program.
        for source in [
            format!(
                "document.cookie = 'x=y; domain=space.fast';{}",
                " ".repeat(MAX_SCRIPT)
            ),
            format!(
                "{}document.cookie = 'x=y; domain=space.fast';{}",
                "(".repeat(129),
                ")".repeat(129)
            ),
            format!(
                "{}document.cookie = 'x=y; domain=space.fast';",
                "!".repeat(129)
            ),
            format!(
                "{}document.cookie = 'x=y; domain=space.fast';",
                "new ".repeat(129)
            ),
            "document.cookie = 'x=y; domain=space.fast'; const broken =".into(),
        ] {
            assert_eq!(scan(&source, false), None);
        }
        // Strings, comments and nested conditional arms cannot reset recursion.
        for source in [
            format!("{}0;", "true ? (0, 1) : ".repeat(10_000)),
            format!("{}0;", "!/* ;,)} */".repeat(10_000)),
            format!("{}0;", "' ;,)} ' + ".repeat(10_000)),
            format!("{}0;", "true ? ' ;,)} ' : ".repeat(10_000)),
            format!("type T = {}number;", "T extends T ? T : ".repeat(10_000)),
        ] {
            let source_type = if source.starts_with("type ") {
                SourceType::ts()
            } else {
                SourceType::script()
            };
            assert!(!within_parse_budget(&source, source_type));
        }
        // Refusal resumes with the next complete HTML script after a skipped unit.
        let html = format!(
            "<script>{}</script>\r\n<script>document.cookie = 'x=y; domain=space.fast';</script>",
            " ".repeat(MAX_SCRIPT + 1)
        );
        assert_eq!(scan(&html, true), Some(("space.fast".into(), 2)));
        let svg = format!(
            "<svg><script>{}</script></svg>\n<script>document.cookie='x=y; domain=space.fast';</script>",
            " ".repeat(MAX_SCRIPT + 1)
        );
        assert_eq!(scan(&svg, true), Some(("space.fast".into(), 2)));
        for (bytes, expected) in [(65_536, Some(("space.fast".into(), 1))), (65_537, None)] {
            let html = format!(
                "<script data-x='{}'>document.cookie='x=y; domain=space.fast';</script>",
                "a".repeat(bytes - 18)
            );
            assert_eq!(scan(&html, true), expected);
        }
        let tokens = format!(
            "<script>document.cookie='x=y; domain=space.fast';</script>{}",
            "</unknown>".repeat(1_000_001)
        );
        assert_eq!(scan(&tokens, true), None);
        // HTML budgets bound tree construction without limiting ordinary flat pages.
        let flat = format!(
            "{}\n<script>document.cookie='x=y; domain=space.fast';</script>",
            "<p>text</p>".repeat(10_000)
        );
        assert_eq!(scan(&flat, true), Some(("space.fast".into(), 2)));
        for element in ["div", "template"] {
            let deep = format!(
                "{}<script>document.cookie='x=y; domain=space.fast';</script>{}",
                format!("<{element}>").repeat(600),
                format!("</{element}>").repeat(600)
            );
            assert_eq!(scan(&deep, true), None);
        }
        let many = format!(
            "{}<script>document.cookie='x=y; domain=space.fast';</script>",
            "<br>".repeat(100_001)
        );
        assert_eq!(scan(&many, true), None);
    }
}
