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
use regex::bytes::{Regex, RegexBuilder};
use std::collections::{BTreeSet, HashSet};
#[cfg(test)]
use std::fs::File;
#[cfg(test)]
use std::io::{self, Read};
use std::ops::ControlFlow;
use std::path::Path;

use serde_json::json;

use crate::catalog::{FileCatalog, ObjectIdentity};
use crate::decompression::CompressionEncoding;
#[cfg(test)]
use crate::decompression::MAX_DECOMPRESSED_BYTES as MAX_DECOMPRESSED;
#[cfg(test)]
use crate::finalize::FinalizeError;
use crate::finalize::{invalid_with_details, Result};
use crate::hash::stable_json_sha256;
#[cfg(test)]
use crate::storage::blob_path;

#[path = "html_scripts.rs"]
mod html_scripts;
pub(crate) use html_scripts::is_javascript_mime_type;

/// Bump whenever cookie-write detection semantics change so previously scanned
/// catalogs fail closed and rescan under the new rules.
pub(crate) const COOKIE_SCAN_REVISION: u32 = 1;

const MAX_TOKEN: usize = 8192;
const MAX_SCRIPT: usize = 1024 * 1024;
const MAX_PARSE_DEPTH: usize = 128;
const MAX_PARSE_STEPS: usize = 10_000;

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
    thread_local! {
        static JS_PARSER: std::cell::RefCell<tree_sitter::Parser> = {
            let mut parser = tree_sitter::Parser::new();
            parser
                .set_language(&tree_sitter_javascript::LANGUAGE.into())
                .expect("bundled JS grammar matches parser");
            std::cell::RefCell::new(parser)
        };
        static TS_PARSER: std::cell::RefCell<tree_sitter::Parser> = {
            let mut parser = tree_sitter::Parser::new();
            parser
                .set_language(&tree_sitter_typescript::LANGUAGE_TYPESCRIPT.into())
                .expect("bundled TS grammar matches parser");
            std::cell::RefCell::new(parser)
        };
        static TSX_PARSER: std::cell::RefCell<tree_sitter::Parser> = {
            let mut parser = tree_sitter::Parser::new();
            parser
                .set_language(&tree_sitter_typescript::LANGUAGE_TSX.into())
                .expect("bundled TSX grammar matches parser");
            std::cell::RefCell::new(parser)
        };
    }
    let parser_key = if source_type.is_typescript() {
        if source_type.is_jsx() {
            &TSX_PARSER
        } else {
            &TS_PARSER
        }
    } else {
        &JS_PARSER
    };
    parser_key.with(|cell| {
        let mut parser = cell.borrow_mut();
        parser.reset();
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
    })
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

// The AST visitor only accepts an unescaped literal domain, compared with
// ASCII case folding. A byte search can rule out a match before parsing.
struct CookieScan {
    apexes: Vec<String>,
    literal_domain: Option<Regex>,
}

impl CookieScan {
    fn new(apexes: &[String]) -> Self {
        let pattern = apexes
            .iter()
            .map(|apex| regex::escape(apex))
            .collect::<Vec<_>>()
            .join("|");
        Self {
            apexes: apexes.to_vec(),
            literal_domain: RegexBuilder::new(&pattern)
                .unicode(false)
                .case_insensitive(true)
                .build()
                .ok(),
        }
    }

    fn may_name_domain(&self, source: &str) -> bool {
        self.literal_domain
            .as_ref()
            .is_none_or(|pattern| pattern.is_match(source.as_bytes()))
    }
}

#[derive(Debug, PartialEq, Eq)]
enum ScanVerdict {
    Clean,
    /// Size/parser/HTML budget exit, parse error, or invalid UTF-8: publish
    /// stays allowed (best-effort), but the catalog must not stamp reusable
    /// cookie-scan evidence for future publishes.
    Inconclusive,
    Found(String, usize),
}

fn normalized_apexes(apexes: &[String]) -> Vec<String> {
    apexes
        .iter()
        .map(|value| {
            let trimmed = value.trim();
            trimmed
                .strip_prefix('.')
                .unwrap_or(trimmed)
                .to_ascii_lowercase()
        })
        .filter(|value| !value.is_empty())
        .collect::<BTreeSet<_>>()
        .into_iter()
        .collect()
}

fn detect_script(source: &str, source_type: SourceType, policy: &CookieScan) -> ScanVerdict {
    if source.len() > MAX_SCRIPT {
        return ScanVerdict::Inconclusive;
    }
    if !policy.may_name_domain(source) {
        return ScanVerdict::Clean;
    }
    if !within_parse_budget(source, source_type) {
        return ScanVerdict::Inconclusive;
    }
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, source_type).parse();
    if parsed.fatal_error || parsed.diagnostics.errors().next().is_some() {
        return ScanVerdict::Inconclusive;
    }
    let mut visitor = CookieVisitor {
        source,
        apexes: &policy.apexes,
        finding: None,
    };
    visitor.visit_program(&parsed.program);
    match visitor.finding {
        Some((domain, offset)) => ScanVerdict::Found(domain, offset),
        None => ScanVerdict::Clean,
    }
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
#[cfg(test)]
fn detect_as(
    reader: impl Read,
    html: bool,
    source_type: SourceType,
    policy: &CookieScan,
) -> io::Result<ScanVerdict> {
    let mut bytes = Vec::new();
    let max_decompressed = crate::decompression::MAX_DECOMPRESSED_BYTES;
    reader.take(max_decompressed + 1).read_to_end(&mut bytes)?;
    let truncated = bytes.len() as u64 > max_decompressed;
    if truncated {
        bytes.truncate(max_decompressed as usize);
    }
    Ok(detect_slice(&bytes, truncated, html, source_type, policy))
}

/// Zero-copy slice entry point used by the unified single-pass catalog scanner.
fn detect_slice(
    bytes: &[u8],
    truncated: bool,
    html: bool,
    source_type: SourceType,
    policy: &CookieScan,
) -> ScanVerdict {
    let Ok(source) = std::str::from_utf8(bytes) else {
        return ScanVerdict::Inconclusive;
    };
    let finish = |verdict: ScanVerdict| match verdict {
        ScanVerdict::Clean if truncated => ScanVerdict::Inconclusive,
        other => other,
    };
    if !html {
        return finish(match detect_script(source, source_type, policy) {
            ScanVerdict::Found(domain, offset) => {
                ScanVerdict::Found(domain, 1 + line_breaks(&source[..offset]))
            }
            other => other,
        });
    }
    // HTML script data is raw. SVG script text can assemble a domain from
    // character references and separate text nodes, so it needs the tree parser.
    if !crate::content::contains_html_tag_start(source, "<script")
        || (!policy.may_name_domain(source)
            && !crate::content::contains_html_tag_start(source, "<svg"))
    {
        return finish(ScanVerdict::Clean);
    }
    let extracted = html_scripts::scripts(source);
    let mut saw_inconclusive = truncated || !extracted.complete;
    for script in extracted.scripts {
        match detect_script(script.text(source), script.kind, policy) {
            ScanVerdict::Found(domain, offset) => {
                return ScanVerdict::Found(
                    domain,
                    1 + line_breaks(&source[..script.source_offset(offset)]),
                );
            }
            ScanVerdict::Inconclusive => saw_inconclusive = true,
            ScanVerdict::Clean => {}
        }
    }
    if saw_inconclusive {
        ScanVerdict::Inconclusive
    } else {
        ScanVerdict::Clean
    }
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
    let normalized = normalized_apexes(apexes);
    if normalized.is_empty() {
        return Ok(());
    }
    let policy = CookieScan::new(&normalized);
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
                if let ScanVerdict::Found(domain, line) = detect_slice(
                    text.as_bytes(),
                    false,
                    true,
                    SourceType::unambiguous(),
                    &policy,
                ) {
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

/// Digest over the scanner revision and normalized shared apexes. Returns
/// `None` when no managed apex is configured, so a catalog built without an
/// active cookie policy never claims prior validation.
#[cfg(test)]
fn policy_digest(apexes: &[String]) -> Option<String> {
    policy_digest_for_normalized(&normalized_apexes(apexes))
}

fn policy_digest_for_normalized(normalized: &[String]) -> Option<String> {
    if normalized.is_empty() {
        return None;
    }
    Some(stable_json_sha256(&json!({
        "revision": COOKIE_SCAN_REVISION,
        "apexes": normalized,
    })))
}

/// Script grammar inferred from a non-HTML file extension.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub(crate) enum ScriptSyntax {
    Js,
    Mjs,
    Cjs,
    Jsx,
    Ts,
    Tsx,
}

impl ScriptSyntax {
    fn from_path(logical_path: &str) -> Self {
        let Some(ext) = Path::new(logical_path)
            .extension()
            .and_then(|value| value.to_str())
        else {
            return Self::Js;
        };
        if ext.eq_ignore_ascii_case("mjs") {
            Self::Mjs
        } else if ext.eq_ignore_ascii_case("cjs") {
            Self::Cjs
        } else if ext.eq_ignore_ascii_case("jsx") {
            Self::Jsx
        } else if ext.eq_ignore_ascii_case("ts") {
            Self::Ts
        } else if ext.eq_ignore_ascii_case("tsx") {
            Self::Tsx
        } else {
            Self::Js
        }
    }

    fn source_type(self) -> SourceType {
        match self {
            Self::Mjs => SourceType::mjs(),
            Self::Cjs => SourceType::cjs(),
            Self::Jsx => SourceType::jsx().with_unambiguous(true),
            Self::Ts => SourceType::ts().with_unambiguous(true),
            Self::Tsx => SourceType::tsx().with_unambiguous(true),
            Self::Js => SourceType::unambiguous(),
        }
    }
}

/// Descriptor for one script or HTML object that needs cookie-write inspection.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub(crate) enum CookieFileTarget {
    Html,
    Script(ScriptSyntax),
}

impl CookieFileTarget {
    /// Maximum decoded bytes the cookie scanner needs to inspect this target.
    pub(crate) const fn max_buffer_bytes(self) -> usize {
        match self {
            // Standalone scripts above MAX_SCRIPT are inconclusive anyway; keep
            // 1 byte over MAX_SCRIPT so oversize scripts report Inconclusive.
            Self::Script(_) => MAX_SCRIPT + 1,
            // Match the full decoded document ceiling so large HTML files keep
            // complete shared-apex cookie-write coverage.
            Self::Html => crate::decompression::MAX_DECOMPRESSED_BYTES as usize,
        }
    }
}

pub(crate) fn is_cookie_script_or_html(logical_path: &str, content_type: &str) -> bool {
    classify_cookie_target(logical_path, content_type).is_some()
}

fn classify_cookie_target(logical_path: &str, content_type: &str) -> Option<CookieFileTarget> {
    let html = script_kind(logical_path, content_type)?;
    Some(if html {
        CookieFileTarget::Html
    } else {
        CookieFileTarget::Script(ScriptSyntax::from_path(logical_path))
    })
}

type CookieScanKey<'a> = (&'a str, CookieFileTarget, CompressionEncoding);

fn catalog_scan_entries<'a>(
    catalog: &'a FileCatalog,
) -> impl Iterator<Item = (&'a str, &'a ObjectIdentity, CookieScanKey<'a>)> {
    catalog.iter_objects().filter_map(|(path, object)| {
        let (logical_path, encoding) = CompressionEncoding::from_path(path);
        let target = classify_cookie_target(logical_path, &object.content_type)?;
        Some((path, object, (object.sha256.as_str(), target, encoding)))
    })
}

/// Active state of an incremental cookie-write scan session.
pub(crate) struct ActiveCookieScan<'a> {
    clean_digest: Option<String>,
    policy: CookieScan,
    scanned: HashSet<CookieScanKey<'a>>,
}

/// Stateful incremental cookie-write scanner that consumes already-decoded
/// in-memory bytes from the unified single-pass catalog scanner.
pub(crate) enum CookieScanSession<'a> {
    Disabled,
    Active(ActiveCookieScan<'a>),
}

impl<'a> CookieScanSession<'a> {
    pub(crate) fn new(previous_catalog: Option<&'a FileCatalog>, apexes: &[String]) -> Self {
        let normalized = normalized_apexes(apexes);
        let Some(digest) = policy_digest_for_normalized(&normalized) else {
            return Self::Disabled;
        };
        let policy = CookieScan::new(&normalized);
        let mut scanned = HashSet::new();
        if let Some(previous) = previous_catalog
            .filter(|prev| prev.cookie_scan_policy_digest.as_deref() == Some(digest.as_str()))
        {
            for (_, _, key) in catalog_scan_entries(previous) {
                scanned.insert(key);
            }
        }
        Self::Active(ActiveCookieScan {
            clean_digest: Some(digest),
            policy,
            scanned,
        })
    }

    /// Returns `Some(target)` when `(logical_path, encoding, object)` is a
    /// script/HTML artifact not yet validated under the active cookie-write policy.
    pub(crate) fn target_for(
        &mut self,
        logical_path: &str,
        encoding: CompressionEncoding,
        object: &'a ObjectIdentity,
    ) -> Option<CookieFileTarget> {
        let Self::Active(active) = self else {
            return None;
        };
        let target = classify_cookie_target(logical_path, &object.content_type)?;
        if !active
            .scanned
            .insert((object.sha256.as_str(), target, encoding))
        {
            return None;
        }
        Some(target)
    }

    /// Scans already-read/decompressed bytes in memory with zero extra disk I/O.
    pub(crate) fn scan_bytes(
        &mut self,
        target: CookieFileTarget,
        path: &str,
        sha256: &str,
        bytes: &[u8],
        truncated: bool,
    ) -> Result<()> {
        let Self::Active(active) = self else {
            return Ok(());
        };
        let (html, source_type) = match target {
            CookieFileTarget::Html => (true, SourceType::unambiguous()),
            CookieFileTarget::Script(syntax) => (false, syntax.source_type()),
        };
        match detect_slice(bytes, truncated, html, source_type, &active.policy) {
            ScanVerdict::Found(domain, line) => refuse(path, sha256, &domain, line)?,
            ScanVerdict::Inconclusive => active.clean_digest = None,
            ScanVerdict::Clean => {}
        }
        Ok(())
    }

    pub(crate) fn finish(self) -> Option<String> {
        match self {
            Self::Disabled => None,
            Self::Active(active) => active.clean_digest,
        }
    }
}

#[cfg(test)]
pub(crate) fn validate_cookie_writes(
    blobs: &Path,
    catalog: &FileCatalog,
    previous_catalog: Option<&FileCatalog>,
    apexes: &[String],
) -> Result<Option<String>> {
    let mut session = CookieScanSession::new(previous_catalog, apexes);
    for (path, object) in catalog.iter_objects() {
        let (logical_path, encoding) = CompressionEncoding::from_path(path);
        let Some(target) = session.target_for(logical_path, encoding, object) else {
            continue;
        };
        let full_path = blob_path(blobs, &object.sha256);
        let file = File::open(&full_path).map_err(|error| FinalizeError::Invalid {
            code: "artifact_cookie_scan_failed",
            message: format!("Could not inspect script text in {path}: {error}"),
            details: Some(json!({"path":path,"pointer":"/files"})),
        })?;
        let reader: Box<dyn Read> = match encoding {
            CompressionEncoding::Gzip => Box::new(flate2::read::MultiGzDecoder::new(file)),
            CompressionEncoding::Brotli => Box::new(brotli::Decompressor::new(file, 8192)),
            CompressionEncoding::Identity => Box::new(file),
        };
        let (html, source_type) = match target {
            CookieFileTarget::Html => (true, SourceType::unambiguous()),
            CookieFileTarget::Script(syntax) => (false, syntax.source_type()),
        };
        let CookieScanSession::Active(active) = &mut session else {
            continue;
        };
        let verdict = detect_as(reader, html, source_type, &active.policy).map_err(|error| {
            FinalizeError::Invalid {
                code: "artifact_cookie_scan_failed",
                message: format!("Could not inspect script text in {path}: {error}"),
                details: Some(json!({"path":path,"pointer":"/files"})),
            }
        })?;
        match verdict {
            ScanVerdict::Found(domain, line) => refuse(path, &object.sha256, &domain, line)?,
            ScanVerdict::Inconclusive => active.clean_digest = None,
            ScanVerdict::Clean => {}
        }
    }
    Ok(session.finish())
}

#[cfg(test)]
mod tests {
    use super::*;
    fn scan(source: &str, html: bool) -> Option<(String, usize)> {
        match detect_as(
            source.as_bytes(),
            html,
            SourceType::unambiguous(),
            &CookieScan::new(&[
                "space.fast".into(),
                "view.fast".into(),
                "partner.test".into(),
            ]),
        )
        .unwrap()
        {
            ScanVerdict::Found(domain, line) => Some((domain, line)),
            ScanVerdict::Clean | ScanVerdict::Inconclusive => None,
        }
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
                    &CookieScan::new(&["space.fast".into()])
                )
                .unwrap(),
                ScanVerdict::Found("space.fast".into(), 1)
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

    #[test]
    fn validate_cookie_writes_reuses_matching_policy_digest_and_fails_closed_otherwise() {
        use crate::catalog::{CatalogPath, ObjectIdentity, FILE_CATALOG_FORMAT};
        use std::collections::BTreeMap;

        let temp = tempfile::tempdir().unwrap();
        let blobs = temp.path();
        let apexes = vec!["view.fast".to_string(), "space.fast".to_string()];
        let same_policy_reordered = vec![" .SPACE.FAST ".to_string(), "VIEW.FAST".to_string()];
        let digest = policy_digest(&apexes).expect("non-empty apexes produce a policy digest");
        assert_eq!(policy_digest(&same_policy_reordered), Some(digest.clone()));
        assert_eq!(policy_digest(&[]), None);

        let clean_sha = "1".repeat(64);
        let obj = ObjectIdentity {
            sha256: clean_sha.clone(),
            size: 10,
            content_type: "text/javascript".into(),
        };
        let make_catalog = |path: &str, policy: Option<String>| FileCatalog {
            format: FILE_CATALOG_FORMAT.into(),
            space_id: "s".into(),
            version_id: "v".into(),
            paths: BTreeMap::from([(
                path.to_string(),
                CatalogPath {
                    source: obj.clone(),
                    served: Some(obj.clone()),
                    public: true,
                },
            )]),
            variants: BTreeMap::new(),
            serving_digest: "d".into(),
            template_paths: Vec::new(),
            generated_at: "2026-11-21T00:00:00Z".into(),
            pipeline_context_digest: None,
            cookie_scan_policy_digest: policy,
            secret_scan_policy_digest: None,
            hygiene_scan_policy_digest: None,
            decompression_scan_policy_digest: None,
        };

        // The blob is absent from disk: succeeding proves `validate_cookie_writes`
        // skipped opening it when the previous catalog carries the matching digest.
        let previous = make_catalog("app.js", Some(digest.clone()));
        let next = make_catalog("app.js", None);
        assert_eq!(
            validate_cookie_writes(blobs, &next, Some(&previous), &same_policy_reordered).unwrap(),
            Some(digest.clone())
        );

        // Changing syntax (.tsx vs .js), compression (.gz), policy apexes, or
        // omitting the previous digest must fail closed and attempt to scan.
        for (candidate, prev, check_apexes) in [
            (make_catalog("app.tsx", None), Some(&previous), &apexes),
            (make_catalog("app.js.gz", None), Some(&previous), &apexes),
            (
                make_catalog("app.js", None),
                Some(&previous),
                &vec!["view.fast".to_string(), "extra.fast".to_string()],
            ),
        ] {
            let err = validate_cookie_writes(blobs, &candidate, prev, check_apexes).unwrap_err();
            assert!(
                matches!(
                    err,
                    FinalizeError::Invalid {
                        code: "artifact_cookie_scan_failed",
                        ..
                    }
                ),
                "expected fail-closed blob scan attempt, got {err:?}"
            );
        }
        let legacy_previous = make_catalog("app.js", None);
        let err =
            validate_cookie_writes(blobs, &next, Some(&legacy_previous), &apexes).unwrap_err();
        assert!(matches!(
            err,
            FinalizeError::Invalid {
                code: "artifact_cookie_scan_failed",
                ..
            }
        ));

        // Unnormalized apex spelling must feed the scanner identically to the digest.
        let bad_bytes = b"document.cookie = 'x=y; domain=view.fast';";
        let bad_sha = crate::finalize::sha256(bad_bytes);
        crate::storage::put_blob(blobs, bad_bytes).unwrap();
        let bad_obj = ObjectIdentity {
            sha256: bad_sha,
            size: bad_bytes.len() as u64,
            content_type: "text/javascript".into(),
        };
        let bad_catalog = FileCatalog {
            paths: BTreeMap::from([(
                "bad.js".to_string(),
                CatalogPath {
                    source: bad_obj.clone(),
                    served: Some(bad_obj),
                    public: true,
                },
            )]),
            ..make_catalog("app.js", None)
        };
        assert!(matches!(
            validate_cookie_writes(blobs, &bad_catalog, None, &same_policy_reordered),
            Err(FinalizeError::Invalid {
                code: "shared_apex_cookie_write",
                ..
            })
        ));

        // An inconclusive scan (parse error or invalid UTF-8) stays publishable
        // (best-effort) but withholds `cookie_scan_policy_digest` so future
        // finalizes do not skip re-scanning it.
        for inconclusive_bytes in [
            b"document.cookie = 'x=y; domain=view.fast'; const broken =".as_slice(),
            &[0xff, 0xfe, 0xfd],
        ] {
            let inc_sha = crate::finalize::sha256(inconclusive_bytes);
            crate::storage::put_blob(blobs, inconclusive_bytes).unwrap();
            let inc_obj = ObjectIdentity {
                sha256: inc_sha,
                size: inconclusive_bytes.len() as u64,
                content_type: "text/javascript".into(),
            };
            let inc_catalog = FileCatalog {
                paths: BTreeMap::from([(
                    "inc.js".to_string(),
                    CatalogPath {
                        source: inc_obj.clone(),
                        served: Some(inc_obj),
                        public: true,
                    },
                )]),
                ..make_catalog("app.js", None)
            };
            assert_eq!(
                validate_cookie_writes(blobs, &inc_catalog, None, &apexes).unwrap(),
                None
            );
        }

        // Oversized HTML still refuses a violation in the inspected prefix,
        // and withholds reusable evidence when the prefix is clean.
        let oversized_bad = format!(
            "<script>document.cookie = 'x=y; domain=view.fast';</script>{}",
            " ".repeat(MAX_DECOMPRESSED as usize)
        );
        assert_eq!(
            detect_as(
                oversized_bad.as_bytes(),
                true,
                SourceType::unambiguous(),
                &CookieScan::new(&["view.fast".into()])
            )
            .unwrap(),
            ScanVerdict::Found("view.fast".into(), 1)
        );
        let oversized_clean = vec![b' '; MAX_DECOMPRESSED as usize + 16];
        assert_eq!(
            detect_as(
                oversized_clean.as_slice(),
                true,
                SourceType::unambiguous(),
                &CookieScan::new(&["view.fast".into()])
            )
            .unwrap(),
            ScanVerdict::Inconclusive
        );
    }
}
