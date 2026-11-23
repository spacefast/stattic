//! Build diagnostics: every rule the compiler enforces fails with a file, a
//! line, a column, and a reason. Nothing is silently ignored.

use serde::Serialize;
use sha2::{Digest, Sha256};
use std::cell::RefCell;
use std::fmt::Write as _;
use std::path::{Path, PathBuf};

/// Byte range inside one source file. `file == NO_FILE` marks synthetic spans.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Default)]
pub struct Span {
    pub file: u32,
    pub start: u32,
    pub end: u32,
}

pub const NO_FILE: u32 = u32::MAX;

impl Span {
    pub const NONE: Span = Span {
        file: NO_FILE,
        start: 0,
        end: 0,
    };
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Serialize)]
pub enum Code {
    #[serde(rename = "sync_files_too_large")]
    SyncFilesTooLarge,
    #[serde(rename = "tp_source_needs_local_capture")]
    SourceNeedsLocalCapture,
    #[serde(rename = "F0001")]
    General,
    #[serde(rename = "F1001")]
    Syntax,
    #[serde(rename = "F1002")]
    Unsupported,
    #[serde(rename = "F1003")]
    Import,
    #[serde(rename = "F1101")]
    Classes,
    #[serde(rename = "F1102")]
    NpmImport,
    #[serde(rename = "F2001")]
    Evaluation,
    #[serde(rename = "F2101")]
    BuildTimeClock,
    #[serde(rename = "F3001")]
    Schema,
    #[serde(rename = "F3101")]
    FieldUi,
    #[serde(rename = "F4001")]
    Compile,
    #[serde(rename = "F4101")]
    AccessQuery,
    /// A plugin package imports the app's modules or another plugin's package.
    #[serde(rename = "F1201")]
    PluginIsolation,
    /// App source imports a file outside `tp/` (docs/spec/spec.md §1).
    #[serde(rename = "F1210")]
    ImportFence,
    /// Two exports register the same name, or one kind, action or job under two (§1.1).
    #[serde(rename = "F1221")]
    DuplicateExport,
    /// A kind, global, bucket, action or job built in app source but not exported, so it has no name.
    #[serde(rename = "F1222")]
    Unexported,
    /// A plugin's compiled code uses a capability its `tp/_generated/plugins.lock.json` entry doesn't grant.
    #[serde(rename = "F4201")]
    PluginCapability,
    /// App code names a WordPress option the runtime guard protects (runtime/TypedPress/Wp/guard.json).
    #[serde(rename = "F4301")]
    ProtectedWordPress,
}

impl Code {
    fn category(self) -> &'static str {
        match self {
            Self::Unsupported | Self::Classes | Self::NpmImport | Self::BuildTimeClock | Self::AccessQuery => {
                "unsupported"
            }
            Self::Import | Self::SourceNeedsLocalCapture => "environment",
            _ => "invalid-source",
        }
    }
}

#[derive(Debug, Clone)]
pub struct Diag {
    pub code: Code,
    pub span: Span,
    pub message: String,
    pub help: Option<String>,
}

impl Diag {
    pub fn new(span: Span, message: impl Into<String>) -> Self {
        Diag {
            code: Code::General,
            span,
            message: message.into(),
            help: None,
        }
    }

    pub fn code(mut self, code: Code) -> Self {
        self.code = code;
        self
    }

    pub fn phase(self, code: Code) -> Self {
        if self.code == Code::General {
            self.code(code)
        } else {
            self
        }
    }

    pub fn help(mut self, help: impl Into<String>) -> Self {
        self.help = Some(help.into());
        self
    }
}

pub type Result<T> = std::result::Result<T, Diag>;

thread_local! {
    static FILES: RefCell<Vec<(PathBuf, String)>> = const { RefCell::new(Vec::new()) };
}

/// Runs `f` with an empty source registry and restores the caller's afterwards. Spans only mean
/// something within the run that registered them; a long-lived process (the browser build checks on
/// every edit) gives each run its own, and anything the caller holds keeps rendering correctly.
pub fn isolated<T>(f: impl FnOnce() -> T) -> T {
    let outer = FILES.with(|files| std::mem::take(&mut *files.borrow_mut()));
    let result = f();
    FILES.with(|files| *files.borrow_mut() = outer);
    result
}

/// Registers a source file so spans can be rendered later. Returns its id.
pub fn register_file(path: &Path, source: &str) -> u32 {
    FILES.with(|files| {
        let mut files = files.borrow_mut();
        files.push((path.to_path_buf(), source.to_string()));
        (files.len() - 1) as u32
    })
}

pub(crate) fn source_text(span: Span) -> Option<String> {
    FILES.with(|files| {
        let files = files.borrow();
        let (_, source) = files.get(span.file as usize)?;
        source.get(span.start as usize..span.end as usize).map(str::to_owned)
    })
}

/// `file:line:column` of a span's start, relative to the file name only, for runtime messages.
pub fn site(span: Span) -> String {
    FILES.with(|files| {
        let files = files.borrow();
        let Some((path, src)) = files.get(span.file as usize) else {
            return String::new();
        };
        let (line, col) = line_col(src, span.start as usize);
        let name = path
            .file_name()
            .map(|n| n.to_string_lossy().into_owned())
            .unwrap_or_default();
        format!("{name}:{line}:{col}")
    })
}

/// The property name `key` written just before `value` (`key: value`), so an error about a key
/// points at the key. A shorthand property (`{ tables }`) is its own key; anything else keeps `value`.
pub fn key_of(value: Span, key: &str) -> Span {
    FILES.with(|files| {
        let files = files.borrow();
        let Some((_, src)) = files.get(value.file as usize) else {
            return value;
        };
        let start = boundary(src, value.start as usize);
        let before = src[..start].trim_end();
        let Some(before) = before.strip_suffix(':') else {
            return value;
        };
        let before = before.trim_end();
        let quoted = [format!("\"{key}\""), format!("'{key}'"), key.to_string()];
        for name in quoted {
            if let Some(head) = before.strip_suffix(name.as_str())
                && !head.ends_with(|c: char| c.is_alphanumeric() || c == '_' || c == '$')
            {
                return Span {
                    file: value.file,
                    start: head.len() as u32,
                    end: before.len() as u32,
                };
            }
        }
        value
    })
}

fn line_col(src: &str, offset: usize) -> (usize, usize) {
    let offset = boundary(src, offset);
    let before = &src[..offset];
    let line = before.matches('\n').count() + 1;
    let line_start = before.rfind('\n').map_or(0, |i| i + 1);
    let col = src[line_start..offset].chars().count() + 1;
    (line, col)
}

/// Renders a diagnostic with a code frame, rustc-style.
pub fn render(diag: &Diag, root: &Path) -> String {
    render_headed(diag, root, "error")
}

/// [`render`] with the diagnostic's code in the heading (`error[F1001]: …`), the form `typedpress build`
/// prints and the dev overlay links to the reference. Everything else that renders a diagnostic
/// keeps the plain `error: …` heading other tools match on.
pub fn render_with_code(diag: &Diag, root: &Path) -> String {
    let code = serde_json::to_string(&diag.code).expect("code serializes");
    render_headed(diag, root, &format!("error[{}]", code.trim_matches('"')))
}

fn render_headed(diag: &Diag, root: &Path, heading: &str) -> String {
    let mut out = String::new();
    let _ = writeln!(out, "{heading}: {}", diag.message);
    FILES.with(|files| {
        let files = files.borrow();
        if let Some((path, src)) = files.get(diag.span.file as usize) {
            let start = boundary(src, diag.span.start as usize);
            let (line, col) = line_col(src, start);
            let shown = path.strip_prefix(root).unwrap_or(path);
            let _ = writeln!(out, "  --> {}:{}:{}", shown.display(), line, col);
            let line_text = src.lines().nth(line - 1).unwrap_or("");
            let gutter = line.to_string().len();
            let end = boundary(src, diag.span.end as usize).max(start);
            let line_end = start + src[start..].find('\n').unwrap_or(src.len() - start);
            let width = src[start..end.min(line_end).max(start)].chars().count().max(1);
            let _ = writeln!(out, "{:gutter$} |", "");
            let _ = writeln!(out, "{line} | {line_text}");
            let _ = writeln!(out, "{:gutter$} | {}{}", "", " ".repeat(col - 1), "^".repeat(width));
        }
    });
    if let Some(help) = &diag.help {
        let _ = writeln!(out, "  = help: {help}");
    }
    out
}

fn boundary(src: &str, offset: usize) -> usize {
    let mut offset = offset.min(src.len());
    while !src.is_char_boundary(offset) {
        offset -= 1;
    }
    offset
}

#[derive(Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Location {
    file: String,
    start: usize,
    end: usize,
    line: usize,
    column: usize,
    end_line: usize,
    end_column: usize,
}

fn location(span: Span, root: &Path) -> Option<Location> {
    FILES.with(|files| {
        let files = files.borrow();
        let (path, src) = files.get(span.file as usize)?;
        let start = boundary(src, span.start as usize);
        let end = boundary(src, span.end as usize).max(start);
        let position = |offset: usize| {
            let before = &src[..offset];
            let line = before.bytes().filter(|b| *b == b'\n').count() + 1;
            let line_start = before.rfind('\n').map_or(0, |at| at + 1);
            (line, src[line_start..offset].encode_utf16().count() + 1)
        };
        let (line, column) = position(start);
        let (end_line, end_column) = position(end);
        Some(Location {
            file: path.strip_prefix(root).unwrap_or(path).to_string_lossy().into_owned(),
            start,
            end,
            line,
            column,
            end_line,
            end_column,
        })
    })
}

/// A span's file (relative to `root`), 1-based line and UTF-16 column, as `typedpress check --json` reports them.
pub fn position(span: Span, root: &Path) -> Option<(String, usize, usize)> {
    location(span, root).map(|l| (l.file, l.line, l.column))
}

#[derive(Serialize)]
pub struct LocatedDiagnostic<'a> {
    code: Code,
    category: &'static str,
    severity: &'static str,
    message: &'a str,
    help: Option<&'a str>,
    location: Option<Location>,
    group: String,
}

#[derive(Serialize)]
pub struct DiagnosticGroup<'a> {
    id: String,
    code: Code,
    message: &'a str,
    sites: Vec<Option<Location>>,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Envelope<'a> {
    schema: &'static str,
    version: u8,
    compiler_version: &'static str,
    ok: bool,
    offset_encoding: &'static str,
    position_encoding: &'static str,
    diagnostics: Vec<LocatedDiagnostic<'a>>,
    groups: Vec<DiagnosticGroup<'a>>,
    /// Each SQL index the deploy keeps and why it exists (spec §2.9), when the check succeeded.
    #[serde(skip_serializing_if = "Option::is_none")]
    indexes: Option<serde_json::Value>,
}

impl Envelope<'_> {
    /// The successful check's index list, from the schema snapshot it built.
    pub fn with_indexes(mut self, snapshot: &serde_json::Value) -> Self {
        let report = crate::migrate::index_report(snapshot);
        self.indexes = report.as_array().is_some_and(|i| !i.is_empty()).then_some(report);
        self
    }
}

/// Both successful and failing checks write exactly one versioned envelope, and so does
/// `typedpress build --diagnostics-file` for the compile it ran.
/// Offsets are UTF-8 bytes; lines/UTF-16 columns are one-based, ends exclusive.
pub fn envelope<'a>(diagnostics: &'a [Diag], root: &Path) -> Envelope<'a> {
    let mut groups = indexmap::IndexMap::<String, DiagnosticGroup<'a>>::new();
    let mut sites = Vec::new();
    for diag in diagnostics {
        let mut hash = Sha256::new();
        hash.update(serde_json::to_vec(&diag.code).expect("code serializes"));
        hash.update([0]);
        hash.update(
            diag.message
                .replace(root.to_string_lossy().as_ref(), "<project>")
                .as_bytes(),
        );
        let id: String = hash.finalize().iter().map(|byte| format!("{byte:02x}")).collect();
        let loc = location(diag.span, root);
        let group = groups.entry(id.clone()).or_insert_with(|| DiagnosticGroup {
            id: id.clone(),
            code: diag.code,
            message: &diag.message,
            sites: Vec::new(),
        });
        if group.sites.contains(&loc) {
            continue;
        }
        group.sites.push(loc.clone());
        sites.push(LocatedDiagnostic {
            code: diag.code,
            category: diag.code.category(),
            severity: "error",
            message: &diag.message,
            help: diag.help.as_deref(),
            location: loc,
            group: id,
        });
    }
    Envelope {
        schema: "typedpress-diagnostics",
        version: 1,
        compiler_version: env!("CARGO_PKG_VERSION"),
        ok: diagnostics.is_empty(),
        offset_encoding: "utf-8",
        position_encoding: "utf-16",
        diagnostics: sites,
        groups: groups.into_values().collect(),
        indexes: None,
    }
}

/// Closest candidate by edit distance, for "did you mean" hints.
pub fn suggest<'a>(name: &str, candidates: impl IntoIterator<Item = &'a str>) -> Option<String> {
    let mut best: Option<(usize, &str)> = None;
    for candidate in candidates {
        let distance = edit_distance(&name.to_lowercase(), &candidate.to_lowercase());
        let limit = (name.len().max(candidate.len()) / 3).max(1);
        if distance <= limit && best.is_none_or(|(d, _)| distance < d) {
            best = Some((distance, candidate));
        }
    }
    best.map(|(_, c)| c.to_string())
}

fn edit_distance(a: &str, b: &str) -> usize {
    let a: Vec<char> = a.chars().collect();
    let b: Vec<char> = b.chars().collect();
    let mut prev: Vec<usize> = (0..=b.len()).collect();
    let mut prev2: Vec<usize> = prev.clone();
    for i in 1..=a.len() {
        let mut cur = vec![i; b.len() + 1];
        for j in 1..=b.len() {
            let cost = usize::from(a[i - 1] != b[j - 1]);
            cur[j] = (prev[j] + 1).min(cur[j - 1] + 1).min(prev[j - 1] + cost);
            // Adjacent transposition ("nmae" → "name") counts as one edit.
            if i > 1 && j > 1 && a[i - 1] == b[j - 2] && a[i - 2] == b[j - 1] {
                cur[j] = cur[j].min(prev2[j - 2] + 1);
            }
        }
        prev2 = std::mem::replace(&mut prev, cur);
    }
    prev[b.len()]
}

/// The text of every source file registered in this run whose path satisfies `keep`.
pub fn sources(keep: impl Fn(&Path) -> bool) -> Vec<String> {
    FILES.with(|files| {
        files
            .borrow()
            .iter()
            .filter(|(path, _)| keep(path))
            .map(|(_, source)| source.clone())
            .collect()
    })
}

/// Every `(path, source)` registered in this run, in load order.
pub fn registered_files() -> Vec<(PathBuf, String)> {
    FILES.with(|files| files.borrow().clone())
}
