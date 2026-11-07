//! `zero sync` (design §5.4).
//!
//! ```text
//! zero sync [dir] --site <url> (--app-password <user:pass> | --bearer <jwt>) [--only c1,c2] [--check] [--no-commit] [--json]
//! zero sync status [dir] --site …     what a sync would do; writes nothing
//! zero sync resolve <uid> [dir] --site …
//! ```
//!
//! Exit codes: 0 ok, 1 failed, 2 changes pending or conflicts (`--check`), 3 usage, 4 auth.

use crate::sync::apply::{self, Options};
use crate::sync::client::{Client, Credential};
use crate::sync::{Report, git};
use serde_json::json;
use std::path::PathBuf;
use std::process::ExitCode;

const USAGE: &str = "usage: zero sync [status | resolve <uid>] [dir] --site <url> (--app-password <user:pass> | --bearer <jwt>) [--only c1,c2] [--check] [--no-commit] [--json]";

struct Flags {
    mode: Mode,
    project: PathBuf,
    site: Option<String>,
    credential: Option<Credential>,
    only: Option<Vec<String>>,
    check: bool,
    commit: bool,
    json: bool,
}

enum Mode {
    Sync,
    Status,
    Resolve(String),
}

fn parse(args: &[String]) -> Result<Flags, String> {
    let mut flags = Flags {
        mode: Mode::Sync,
        project: PathBuf::from("."),
        site: None,
        credential: None,
        only: None,
        check: false,
        commit: true,
        json: false,
    };
    let mut positional = Vec::new();
    let mut it = args.iter();
    while let Some(a) = it.next() {
        let mut value = |name: &str| it.next().cloned().ok_or(format!("{name} needs a value"));
        match a.as_str() {
            "--site" => flags.site = Some(value("--site")?),
            "--app-password" => flags.credential = Some(Credential::AppPassword(value("--app-password")?)),
            "--bearer" => flags.credential = Some(Credential::Bearer(value("--bearer")?)),
            "--only" => {
                flags.only = Some(
                    value("--only")?
                        .split(',')
                        .map(|s| s.trim().to_string())
                        .filter(|s| !s.is_empty())
                        .collect(),
                )
            }
            "--check" => flags.check = true,
            "--no-commit" => flags.commit = false,
            "--json" => flags.json = true,
            "--watch" => return Err("--watch is not available yet; run zero sync again, or from a scheduler".into()),
            other if other.starts_with("--") => return Err(format!("unknown flag {other}")),
            other => positional.push(other.to_string()),
        }
    }
    let mut positional = positional.into_iter();
    match positional.next() {
        Some(p) if p == "status" => flags.mode = Mode::Status,
        Some(p) if p == "resolve" => {
            flags.mode = Mode::Resolve(positional.next().ok_or("resolve needs the uid of the conflict")?)
        }
        Some(p) => flags.project = PathBuf::from(p),
        None => {}
    }
    if let Some(p) = positional.next() {
        flags.project = PathBuf::from(p);
    }
    if positional.next().is_some() {
        return Err("too many arguments".into());
    }
    if flags.check && matches!(flags.mode, Mode::Resolve(_)) {
        return Err("resolve writes both sides; it has no --check (zero sync --check shows the conflict)".into());
    }
    flags.site = flags
        .site
        .or_else(|| std::env::var("ZERO_SITE").ok().filter(|s| !s.is_empty()));
    if flags.credential.is_none() {
        flags.credential = std::env::var("ZERO_APP_PASSWORD")
            .ok()
            .filter(|s| !s.is_empty())
            .map(Credential::AppPassword);
    }
    Ok(flags)
}

pub fn run(args: &[String]) -> ExitCode {
    let flags = match parse(args) {
        Ok(f) => f,
        Err(e) => {
            eprintln!("{e}\n{USAGE}");
            return ExitCode::from(3);
        }
    };
    let (Some(site), Some(credential)) = (flags.site.clone(), flags.credential) else {
        eprintln!(
            "zero sync needs --site (or ZERO_SITE) and a credential: --app-password or --bearer (or ZERO_APP_PASSWORD)\n{USAGE}"
        );
        return ExitCode::from(3);
    };
    let project = flags.project.canonicalize().unwrap_or(flags.project.clone());
    let client = match Client::new(&site, credential) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(3);
        }
    };
    // Fail on credentials before touching any file; an anonymous answer from /me is a wrong credential.
    match client.get("/me") {
        Err(p) if matches!(p.status, 401 | 403) => {
            eprintln!("{site}: {}", p.message());
            return ExitCode::from(4);
        }
        Ok(me) if me.is_null() => {
            eprintln!("{site}: the credential signs in as nobody");
            return ExitCode::from(4);
        }
        _ => {}
    }
    let mut report = Report::new(&project);
    let check = flags.check || matches!(flags.mode, Mode::Status);
    let result = match &flags.mode {
        Mode::Resolve(uid) => apply::resolve(&project, &client, uid, &mut report),
        _ => apply::run(
            &project,
            &client,
            &Options {
                only: flags.only.clone(),
                check,
            },
            &mut report,
        ),
    };
    let mut commit = None;
    if result.is_ok() && !check && flags.commit && !report.written.is_empty() {
        let paths = report.written.clone();
        let message = format!(
            "zero sync: {} applied, {} conflicts",
            report.applied,
            report.conflicts.len()
        );
        match git::commit(&project, &paths, &message) {
            Ok(done) => commit = done.then_some(message),
            Err(e) => report.fail(e),
        }
    }
    let code = match (&result, check) {
        _ if report.denied => 4,
        (Err(_), _) => 1,
        _ if !report.failures.is_empty() => 1,
        (_, true)
            if matches!(flags.mode, Mode::Sync) && (!report.pending.is_empty() || !report.conflicts.is_empty()) =>
        {
            2
        }
        _ => 0,
    };
    if flags.json {
        let rel: Vec<String> = report.written.iter().map(|p| report.rel(p)).collect();
        let body = json!({
            "applied": report.applied, "pending": report.pending, "conflicts": report.conflicts, "refused": report.refused,
            "failed": report.failures, "notes": report.notes, "written": rel, "commit": commit,
        });
        match &result {
            Ok(()) if code != 1 => println!("{}", json!({"data": body})),
            _ => println!(
                "{}",
                json!({"error": {"detail": result.as_ref().err().cloned().unwrap_or_else(|| report.failures.join("; ")), "data": body}})
            ),
        }
    } else {
        for n in &report.notes {
            println!("· {n}");
        }
        for p in &report.pending {
            println!("~ {p}");
        }
        for r in &report.refused {
            println!("! refused: {r}");
        }
        for c in &report.conflicts {
            println!("✗ conflict: {c}");
        }
        for f in &report.failures {
            eprintln!("✗ {f}");
        }
        if let Err(e) = &result {
            eprintln!("✗ {e}");
        }
        let verb = if check { "pending" } else { "applied" };
        let count = if check { report.pending.len() } else { report.applied };
        println!(
            "{count} {verb}, {} conflicts{}",
            report.conflicts.len(),
            commit.map(|_| ", committed").unwrap_or_default()
        );
    }
    ExitCode::from(code)
}
