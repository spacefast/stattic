//! A one-time source transformation. No legacy runtime is installed in the target.
mod endpoints;
mod manifest;
mod pages;
mod spacefast;
mod transform;

use super::{Entry, Failure, fail};
use crate::loader::Fs;
use crate::{diag, loader, pipeline};
use serde::Serialize;
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};
#[cfg(feature = "cli")]
use std::process::ExitCode;
use std::rc::Rc;

#[derive(Serialize)]
pub struct Report {
    version: u32,
    source: &'static str,
    status: &'static str,
    entries: Vec<Entry>,
    errors: Vec<Failure>,
    adoption_sql: Option<String>,
    connector_roles: serde_json::Value,
}

#[cfg(feature = "cli")]
const USAGE: &str = "usage: zero migrate app --from zero-0.8 [--manifest <finalize.json>] [<capsule-dir>] --out <new-dir> [--space <space-id>] [--users] [--dry-run] [--json]";

#[cfg(feature = "cli")]
struct Args {
    input: Option<PathBuf>,
    manifest: Option<PathBuf>,
    out: PathBuf,
    space: Option<String>,
    dry_run: bool,
    users: bool,
}

#[cfg(feature = "cli")]
fn args(raw: &[String]) -> Result<Args, String> {
    let mut from = None;
    let mut input = None;
    let mut manifest = None;
    let mut out = None;
    let mut space = None;
    let mut dry_run = false;
    let mut users = false;
    let mut json = false;
    let mut it = raw.iter();
    while let Some(arg) = it.next() {
        match arg.as_str() {
            "--from" if from.is_none() => from = Some(it.next().ok_or(USAGE)?.as_str()),
            "--manifest" if manifest.is_none() => manifest = Some(PathBuf::from(it.next().ok_or(USAGE)?)),
            "--out" if out.is_none() => out = Some(PathBuf::from(it.next().ok_or(USAGE)?)),
            "--space" if space.is_none() => space = Some(it.next().ok_or(USAGE)?.clone()),
            "--users" if !users => users = true,
            "--dry-run" if !dry_run => dry_run = true,
            "--json" if !json => json = true,
            s if s.starts_with('-') => return Err(format!("unknown or repeated flag {s}\n{USAGE}")),
            _ if input.is_none() => input = Some(PathBuf::from(arg)),
            _ => return Err(USAGE.into()),
        }
    }
    if from != Some("zero-0.8") {
        return Err(USAGE.into());
    }
    if input.is_none() && manifest.is_none() {
        return Err(USAGE.into());
    }
    Ok(Args {
        input,
        manifest,
        out: out.ok_or(USAGE)?,
        space,
        dry_run,
        users,
    })
}

#[cfg(feature = "cli")]
pub(crate) fn cli(raw: &[String]) -> ExitCode {
    let args = match args(raw) {
        Ok(args) => args,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(2);
        }
    };
    let mut candidate = match &args.manifest {
        None => migrate(
            Rc::new(loader::Disk),
            args.input.as_deref().expect("input"),
            args.space.as_deref(),
            args.users,
        ),
        Some(path) => match std::fs::read_to_string(path) {
            Ok(text) => migrate_manifest(
                Rc::new(loader::Disk),
                &text,
                args.input.as_deref(),
                args.space.as_deref(),
                args.users,
            ),
            Err(e) => Candidate::refused(fail("", format!("cannot read finalize manifest: {e}"))),
        },
    };
    if std::fs::symlink_metadata(&args.out).is_ok() {
        candidate.report.errors.push(fail(
            "",
            "output already exists; choose a new directory; no files are overwritten",
        ));
    }
    if candidate.report.errors.is_empty() {
        if args.dry_run {
            candidate.report.status = "dry-run";
        } else if let Err(e) = write_candidate(&args.out, &candidate.files) {
            candidate.report.errors.push(e);
        } else {
            candidate.report.status = "written";
        }
    }
    if !candidate.report.errors.is_empty() {
        candidate.report.status = "refused";
    }
    println!(
        "{}",
        serde_json::to_string_pretty(&candidate.report).expect("report JSON")
    );
    if candidate.report.errors.is_empty() {
        ExitCode::SUCCESS
    } else {
        ExitCode::from(1)
    }
}

#[derive(Serialize)]
pub struct Candidate {
    pub report: Report,
    pub files: BTreeMap<String, String>,
}

impl Candidate {
    fn refused(error: Failure) -> Candidate {
        let mut candidate = Candidate {
            report: Report::new(),
            files: BTreeMap::new(),
        };
        candidate.report.errors.push(error);
        candidate
    }
}

impl Report {
    fn new() -> Report {
        Report {
            version: 1,
            source: "zero-0.8",
            status: "refused",
            entries: vec![],
            errors: vec![],
            adoption_sql: None,
            connector_roles: serde_json::json!({}),
        }
    }
}

/// Converts from a stored 0.8 finalize manifest (`{ artifact, endpointSources }`). With `source`,
/// the original files must match the manifest's hashes and then convert as a source run does.
/// Without it, the manifest's own refusals are reported, and the app is refused at `/source`:
/// bundled endpoint JavaScript has no types, so it cannot become authored TypeScript.
pub fn migrate_manifest(
    fs: Rc<dyn Fs>,
    text: &str,
    source: Option<&Path>,
    space: Option<&str>,
    users: bool,
) -> Candidate {
    let manifest = match manifest::parse(text) {
        Ok(m) => m,
        Err(e) => return Candidate::refused(e),
    };
    let Some(root) = source else {
        let mut candidate = Candidate::refused(fail(
            "/source",
            format!(
                "{}: the finalize manifest keeps bundled JavaScript, not the authored TypeScript; supply the source at the published commit",
                manifest::NOT_RECOVERABLE
            ),
        ));
        let report = &mut candidate.report;
        manifest.check(&mut report.entries, &mut report.errors);
        // The source refusal stays last, so an app's own blockers rank first.
        report.errors.rotate_left(1);
        return candidate;
    };
    let verified = match fs
        .canonicalize(root)
        .map_err(io)
        .and_then(|root| manifest.verify(fs.as_ref(), &root))
    {
        Ok(files) => files,
        Err(e) => {
            let mut candidate = Candidate::refused(e);
            manifest.check(&mut candidate.report.entries, &mut candidate.report.errors);
            return candidate;
        }
    };
    // Convert from the verified files alone: a page, config or import the publish did not record
    // is simply absent, so nothing added after the publish can reach the candidate.
    let published = PathBuf::from("/published");
    let memory: Rc<dyn Fs> = Rc::new(crate::wasm::Memory::new(
        verified
            .iter()
            .map(|(path, text)| (published.join(path), text.clone()))
            .collect(),
    ));
    // The source must also declare the entry the publish compiled.
    if let Some(expected) = manifest.server_entry()
        && let Ok(entry) = server_entry(memory.as_ref(), &published, &mut Report::new())
        && entry != Path::new(expected.trim_start_matches("./"))
    {
        return Candidate::refused(fail(
            "/source",
            format!(
                "source differs from the published version: its server entry is {}, the publish compiled {expected}",
                entry.display()
            ),
        ));
    }
    migrate(memory, &published, space, users)
}

pub fn migrate(fs: Rc<dyn Fs>, input: &Path, space: Option<&str>, users: bool) -> Candidate {
    let mut report = Report {
        version: 1,
        source: "zero-0.8",
        status: "refused",
        entries: vec![],
        errors: vec![],
        adoption_sql: None,
        connector_roles: serde_json::json!({}),
    };
    let mut files = BTreeMap::new();
    if let Err(e) = convert(fs, input, space, users, &mut report, &mut files) {
        report.errors.push(e);
    }
    if !report.errors.is_empty() {
        files.clear();
    } else {
        report.status = "candidate";
    }
    Candidate { report, files }
}

fn io(e: std::io::Error) -> Failure {
    fail("", e.to_string())
}

fn convert(
    fs: Rc<dyn Fs>,
    input: &Path,
    space: Option<&str>,
    users: bool,
    report: &mut Report,
    files: &mut BTreeMap<String, String>,
) -> Result<(), Failure> {
    let root = fs.canonicalize(input).map_err(io)?;
    let entry = server_entry(fs.as_ref(), &root, report)?;
    let mut sources = BTreeMap::new();
    collect_sources(&*fs, &root, &entry, &mut sources, &mut BTreeSet::new())?;
    for dir in ["islands", "layouts", "_pages"] {
        if fs.exists(&root.join(dir)) {
            refuse_pages(&*fs, &root.join(dir), &format!("/{dir}"), &mut report.errors)?;
        }
    }
    let converted = convert_pages(&*fs, &root, report, &mut sources)?;
    let transformed = transform::app(
        &entry,
        sources
            .get(&entry)
            .ok_or_else(|| fail("", "entry source must resolve to server/index.ts inside the capsule"))?,
        space,
        users,
        fs.is_dir(&root.join("content/posts")),
        &mut report.entries,
        &mut report.errors,
    )?;
    report.connector_roles = transformed.connector_roles;
    if sources.contains_key(Path::new("zero.config.ts")) || sources.contains_key(Path::new("server/__z08-content.ts")) {
        return Err(fail("", "input imports a reserved generated config path"));
    }
    let canonical = PathBuf::from("server/index.ts");
    if entry != canonical {
        if sources.contains_key(&canonical) {
            return Err(fail(
                "",
                "server/index.ts exists beside the configured server entry; rename one",
            ));
        }
        sources.remove(&entry);
    }
    sources.insert(canonical, transformed.config);
    if let Some(content) = transformed.content_config {
        sources.insert(PathBuf::from("server/__z08-content.ts"), content);
    }
    sources.insert(
        PathBuf::from("zero.config.ts"),
        "export { default } from \"./server/index\";\n".into(),
    );
    let page_files = converted;
    // Both dry runs and writes compile the same candidate, before creating a destination.
    if !report.errors.is_empty() {
        return Ok(());
    }
    let checked_root = PathBuf::from("/candidate");
    let memory = crate::wasm::Memory::new(
        sources
            .iter()
            .map(|(path, text)| (checked_root.join(path), text.clone()))
            .collect(),
    );
    let program = loader::load_from(Rc::new(memory), &checked_root.join("zero.config.ts"))
        .map_err(|d| fail("", diag::render(&d[0], &checked_root)))?;
    let built = pipeline::build(&checked_root, &program, || Ok(pipeline::Previous::none()), |_| None).map_err(|d| {
        fail(
            "",
            format!(
                "generated application refused by v2 compiler: {}",
                diag::render(&d, &checked_root)
            ),
        )
    })?;
    let sql = built
        .migrations
        .iter()
        .map(|(_, sql)| sql.as_str())
        .collect::<Vec<_>>()
        .join("\n");
    report.adoption_sql = Some(sql.clone());
    for (path, text) in sources {
        files.insert(path.to_string_lossy().replace('\\', "/"), text);
    }
    for (path, text) in page_files {
        files.insert(path, text);
    }
    files.insert("zero/migrations/0001_adopt.sql".into(), sql);
    files.insert(
        "zero/migrations/snapshot.json".into(),
        serde_json::to_string_pretty(&built.snapshot).unwrap(),
    );
    files.insert(
        "connector-roles.json".into(),
        serde_json::to_string_pretty(&report.connector_roles).unwrap(),
    );
    Ok(())
}

#[cfg(feature = "cli")]
fn write_candidate(out: &Path, files: &BTreeMap<String, String>) -> Result<(), Failure> {
    use std::io::Write;
    let parent = out
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    std::fs::create_dir_all(parent).map_err(io)?;
    std::fs::create_dir(out).map_err(io)?;
    let result = (|| {
        for (path, source) in files {
            let target = out.join(path);
            std::fs::create_dir_all(target.parent().expect("file parent")).map_err(io)?;
            let mut file = std::fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(target)
                .map_err(io)?;
            file.write_all(source.as_bytes()).map_err(io)?;
        }
        Ok(())
    })();
    if result.is_err() {
        let _ = std::fs::remove_dir_all(out);
    }
    result
}

fn collect_sources(
    fs: &dyn Fs,
    root: &Path,
    path: &Path,
    out: &mut BTreeMap<PathBuf, String>,
    seen: &mut BTreeSet<PathBuf>,
) -> Result<(), Failure> {
    let absolute = fs.canonicalize(&root.join(path)).map_err(|e| {
        fail(
            "",
            format!(
                "cannot read capsule source {}: {e}; compiled finalize payloads are not capsule source",
                path.display()
            ),
        )
    })?;
    if !absolute.starts_with(root) {
        return Err(fail("", "source import escapes the capsule directory"));
    }
    let relative = absolute.strip_prefix(root).expect("inside root").to_path_buf();
    if !seen.insert(relative.clone()) {
        return Ok(());
    }
    let source = fs.read_to_string(&absolute).map_err(io)?;
    let body = transform::parse(&relative, &source)?;
    for import in transform::imports(&body) {
        if import.starts_with('.') {
            let base = relative.parent().expect("source parent").join(import);
            let target = if fs.is_file(&root.join(&base)) {
                base
            } else {
                base.with_extension("ts")
            };
            collect_sources(fs, root, &target, out, seen)?;
        } else if import != "@spacefast/zero/server" {
            return Err(fail(
                "",
                format!("package {import:?} needs a manual port; only local TypeScript helpers are copied"),
            ));
        }
    }
    out.insert(relative, source);
    Ok(())
}

/// 0.8 config files in the finalizer's precedence order (`SPACE_CONFIG_PRECEDENCE`).
const CONFIG_FILES: &[&str] = &[
    "sf.jsonc",
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];

/// The capsule's server module: `runtime.server` of the selected 0.8 config, else
/// `server/index.ts`. It is renamed to `server/index.ts` in the candidate.
fn server_entry(fs: &dyn Fs, root: &Path, report: &mut Report) -> Result<PathBuf, Failure> {
    if fs.is_file(&root.join("spacefast.config.ts")) {
        return Err(fail(
            "/spacefast.config.ts",
            "an executable Spacefast config needs a manual port; write its runtime as sf.jsonc",
        ));
    }
    let Some(name) = CONFIG_FILES.iter().find(|f| fs.is_file(&root.join(f))) else {
        return Ok(PathBuf::from("server/index.ts"));
    };
    let text = fs.read_to_string(&root.join(name)).map_err(io)?;
    let config: serde_json::Value = serde_json::from_str(&strip_jsonc(&text))
        .map_err(|e| fail(&format!("/{name}"), format!("cannot read {name}: {e}")))?;
    let Some(server) = config.pointer("/runtime/server").and_then(serde_json::Value::as_str) else {
        return Ok(PathBuf::from("server/index.ts"));
    };
    let server = server.trim_start_matches("./");
    if server != "server/index.ts" {
        report.entries.push(super::entry(
            &format!("/{name}/runtime/server"),
            "mapped",
            format!("server entry {server} becomes server/index.ts"),
        ));
    }
    // Renaming keeps relative imports valid only for a module directly under server/.
    let direct = server.strip_prefix("server/").is_some_and(|rest| !rest.contains('/'));
    if !direct || !(server.ends_with(".ts") || server.ends_with(".tsx")) {
        return Err(fail(
            &format!("/{name}/runtime/server"),
            "the server entry must be a module directly under server/ (server/<name>.ts)",
        ));
    }
    Ok(PathBuf::from(server))
}

/// JSONC → JSON: comments and trailing commas outside strings.
fn strip_jsonc(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut chars = text.chars().peekable();
    let mut string = false;
    while let Some(c) = chars.next() {
        if string {
            out.push(c);
            if c == '\\' {
                out.extend(chars.next());
            } else if c == '"' {
                string = false;
            }
            continue;
        }
        match (c, chars.peek()) {
            ('"', _) => {
                string = true;
                out.push(c);
            }
            ('/', Some('/')) => {
                for c in chars.by_ref() {
                    if c == '\n' {
                        out.push('\n');
                        break;
                    }
                }
            }
            ('/', Some('*')) => {
                chars.next();
                let mut last = ' ';
                for c in chars.by_ref() {
                    if last == '*' && c == '/' {
                        break;
                    }
                    last = c;
                }
            }
            _ => out.push(c),
        }
    }
    // Trailing commas: a comma followed only by whitespace before } or ].
    let re = regex::Regex::new(r",(\s*[}\]])").expect("regex");
    re.replace_all(&out, "$1").into_owned()
}

/// Static 0.8 pages and the client `Layout` become v2 TSX pages. Interactive ones are refused.
fn convert_pages(
    fs: &dyn Fs,
    root: &Path,
    report: &mut Report,
    sources: &mut BTreeMap<PathBuf, String>,
) -> Result<BTreeMap<String, String>, Failure> {
    let mut out = BTreeMap::new();
    let pages_dir = root.join("pages");
    if !fs.exists(&pages_dir) {
        return Ok(out);
    }
    let mut stack = vec![pages_dir];
    let mut files = vec![];
    while let Some(dir) = stack.pop() {
        if !fs.is_dir(&dir) || fs.is_symlink(&dir) {
            report.errors.push(fail(
                &pages::at(dir.strip_prefix(root).unwrap_or(&dir)),
                "pages must be a directory of files",
            ));
            continue;
        }
        let mut entries = fs.read_dir(&dir).map_err(io)?;
        entries.sort();
        for path in entries {
            if fs.is_dir(&path) && !fs.is_symlink(&path) {
                stack.push(path);
            } else {
                files.push(path);
            }
        }
    }
    files.sort();
    for path in files {
        let relative = path.strip_prefix(root).expect("inside root").to_path_buf();
        let at = pages::at(&relative);
        if fs.is_symlink(&path) {
            report.errors.push(fail(&at, "symlinked pages need a manual port"));
            continue;
        }
        // 0.8 routed only .tsx, .md and .html under pages/; other files are modules pages import.
        if !relative
            .extension()
            .is_some_and(|e| e == "tsx" || e == "md" || e == "html")
        {
            continue;
        }
        let text = fs.read_to_string(&path).map_err(io)?;
        if relative.extension().is_some_and(|e| e == "md" || e == "html") {
            report.errors.push(fail(&at, "content-owned 0.8 pages have no v2 counterpart yet (docs/pages.md, Not in this release); move the text into a TSX page or a collection"));
            continue;
        }
        match pages::page(&relative, &text, &at) {
            Ok(page) => {
                // Data modules a page imports are copied like the server's helpers.
                for import in &page.imports {
                    collect_sources(fs, root, Path::new(import), sources, &mut BTreeSet::new())?;
                }
                report.entries.push(super::entry(
                    &at,
                    "mapped",
                    format!(
                        "static page becomes {}; run zero-pages build and commit zero/pages.json before publishing",
                        page.file
                    ),
                ));
                if out.insert(page.file.clone(), page.source).is_some() {
                    report
                        .errors
                        .push(fail(&at, format!("two 0.8 pages map to {}", page.file)));
                }
            }
            Err(e) => report.errors.push(e),
        }
    }
    let client = root.join("client/index.tsx");
    if !out.is_empty() && fs.is_file(&client) {
        let text = fs.read_to_string(&client).map_err(io)?;
        match pages::layout(&text, "/client/index.tsx") {
            Ok(Some(layout)) => {
                report.entries.push(super::entry(
                    "/client/index.tsx",
                    "mapped",
                    "Layout becomes pages/layout.tsx",
                ));
                out.insert("pages/layout.tsx".into(), layout);
            }
            Ok(None) => {}
            Err(e) => report.errors.push(e),
        }
    }
    Ok(out)
}

fn refuse_pages(fs: &dyn Fs, path: &Path, at: &str, errors: &mut Vec<Failure>) -> Result<(), Failure> {
    if !fs.is_dir(path) || fs.is_symlink(path) {
        errors.push(fail(at, "page, layout and island compilation needs an explicit port"));
        return Ok(());
    }
    let mut entries = fs.read_dir(path).map_err(io)?;
    entries.sort();
    for path in entries {
        let at = super::pointer(at, &path.file_name().unwrap_or_default().to_string_lossy());
        if fs.is_dir(&path) && !fs.is_symlink(&path) {
            refuse_pages(fs, &path, &at, errors)?;
        } else {
            let text = fs.read_to_string(&path).unwrap_or_default();
            errors.push(fail(&at, pages::reason(&text, "a 0.8 page, layout or island file")));
        }
    }
    Ok(())
}
