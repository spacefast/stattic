//! A one-time source transformation. No legacy runtime is installed in the target.
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
const USAGE: &str = "usage: zero migrate app --from zero-0.8 <capsule-dir> --out <new-dir> [--space <space-id>] [--users] [--dry-run] [--json]";

#[cfg(feature = "cli")]
struct Args {
    input: PathBuf,
    out: PathBuf,
    space: Option<String>,
    dry_run: bool,
    users: bool,
}

#[cfg(feature = "cli")]
fn args(raw: &[String]) -> Result<Args, String> {
    let mut from = None;
    let mut input = None;
    let mut out = None;
    let mut space = None;
    let mut dry_run = false;
    let mut users = false;
    let mut json = false;
    let mut it = raw.iter();
    while let Some(arg) = it.next() {
        match arg.as_str() {
            "--from" if from.is_none() => from = Some(it.next().ok_or(USAGE)?.as_str()),
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
    Ok(Args {
        input: input.ok_or(USAGE)?,
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
    let mut candidate = migrate(Rc::new(loader::Disk), &args.input, args.space.as_deref(), args.users);
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
    let entry = PathBuf::from("server/index.ts");
    let mut sources = BTreeMap::new();
    collect_sources(&*fs, &root, &entry, &mut sources, &mut BTreeSet::new())?;
    for dir in ["pages", "islands", "layouts"] {
        if fs.exists(&root.join(dir)) {
            refuse_pages(&*fs, &root.join(dir), &format!("/{dir}"), &mut report.errors)?;
        }
    }
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
    sources.insert(entry, transformed.config);
    if let Some(content) = transformed.content_config {
        sources.insert(PathBuf::from("server/__z08-content.ts"), content);
    }
    sources.insert(
        PathBuf::from("zero.config.ts"),
        "export { default } from \"./server/index\";\n".into(),
    );
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
            errors.push(fail(&at, "page, layout and island compilation needs an explicit port"));
        }
    }
    Ok(())
}
