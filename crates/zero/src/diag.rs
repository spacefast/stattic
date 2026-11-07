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
    #[serde(rename = "zero_source_needs_local_capture")]
    SourceNeedsLocalCapture,
    #[serde(rename = "Z0001")]
    General,
    #[serde(rename = "Z1001")]
    Syntax,
    #[serde(rename = "Z1002")]
    Unsupported,
    #[serde(rename = "Z1003")]
    Import,
    #[serde(rename = "Z1101")]
    Classes,
    #[serde(rename = "Z1102")]
    NpmImport,
    #[serde(rename = "Z2001")]
    Evaluation,
    #[serde(rename = "Z2101")]
    BuildTimeClock,
    #[serde(rename = "Z3001")]
    Schema,
    #[serde(rename = "Z3101")]
    FieldUi,
    #[serde(rename = "Z4001")]
    Compile,
    #[serde(rename = "Z4101")]
    AccessQuery,
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
    let mut out = String::new();
    let _ = writeln!(out, "error: {}", diag.message);
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
}

/// Both successful and failing checks write exactly one versioned envelope.
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
        schema: "zero-diagnostics",
        version: 1,
        compiler_version: env!("CARGO_PKG_VERSION"),
        ok: diagnostics.is_empty(),
        offset_encoding: "utf-8",
        position_encoding: "utf-16",
        diagnostics: sites,
        groups: groups.into_values().collect(),
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
