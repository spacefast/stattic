//! `spacepress sync` (design §5.4).
//!
//! ```text
//! spacepress sync [dir] [--site <url>] [--only c1,c2] [--check] [--no-commit] [--json]
//! spacepress sync --pr [dir] [--site <url>] [--repo owner/name] [--base branch]
//! spacepress sync status [dir] --site …     what a sync would do; writes nothing
//! spacepress sync resolve <uid> [dir] --site …
//! ```
//!
//! Without `--site`, the project's running dev site. The credential is the one `spacepress login`
//! stored for the site, or `SP_APP_PASSWORD` / `SP_TOKEN` in the environment (`remote::sign_in`).
//!
//! `--pr` runs on a fresh worktree of the default branch and opens or updates one pull request with
//! the result (see `sync::pr`); it needs `GITHUB_TOKEN`.
//!
//! Exit codes: 0 ok, 1 failed, 2 changes pending or conflicts (`--check`), 3 usage, 4 auth.

use crate::sync::apply::{self, Options};
use crate::sync::client::Client;
use crate::sync::{Report, git, pr};
use serde_json::json;
use std::path::PathBuf;
use std::process::ExitCode;

const USAGE: &str = "usage: spacepress sync [status | resolve <uid>] [dir] [--site <url>] [--only c1,c2] [--check] [--no-commit] [--json]\n       spacepress sync --pr [dir] [--site <url>] [--repo owner/name] [--base branch] [--json]";

struct Flags {
    mode: Mode,
    project: PathBuf,
    site: Option<String>,
    only: Option<Vec<String>>,
    check: bool,
    commit: bool,
    json: bool,
    pr: bool,
    repo: Option<String>,
    base: Option<String>,
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
        only: None,
        check: false,
        commit: true,
        json: false,
        pr: false,
        repo: None,
        base: None,
    };
    let mut positional = Vec::new();
    let mut it = args.iter();
    while let Some(a) = it.next() {
        let mut value = |name: &str| it.next().cloned().ok_or(format!("{name} needs a value"));
        match a.as_str() {
            "--site" => flags.site = Some(value("--site")?),
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
            "--pr" => flags.pr = true,
            "--repo" => flags.repo = Some(value("--repo")?),
            "--base" => flags.base = Some(value("--base")?),
            "--watch" => {
                return Err("--watch is not available yet; run spacepress sync again, or from a scheduler".into());
            }
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
        return Err("resolve writes both sides; it has no --check (spacepress sync --check shows the conflict)".into());
    }
    if flags.pr && (flags.check || !flags.commit || !matches!(flags.mode, Mode::Sync)) {
        return Err(
            "--pr runs a sync and opens a pull request; it takes no --check, --no-commit, status or resolve".into(),
        );
    }
    if flags.pr && flags.only.is_some() {
        // The pull request is rebuilt from every synced kind; with some left out, their changes would
        // drop out of it.
        return Err("--pr syncs every kind; it takes no --only".into());
    }
    if !flags.pr && (flags.repo.is_some() || flags.base.is_some()) {
        return Err("--repo and --base go with --pr".into());
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
    let site = match flags.site.clone() {
        Some(site) => site,
        None => match super::dev::url(&flags.project) {
            Ok(url) => url,
            Err(e) => {
                eprintln!("{e}");
                return ExitCode::from(3);
            }
        },
    };
    let credential = match super::remote::require(&site) {
        Ok(signed) => signed.credential,
        Err(e) => {
            eprintln!("{e}\n{USAGE}\n{}", super::remote::SIGN_IN);
            return ExitCode::from(3);
        }
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
    let check = flags.check || matches!(flags.mode, Mode::Status);
    let options = Options {
        only: flags.only.clone(),
        check,
    };
    let mut pr = None;
    let (mut report, result) = if flags.pr {
        let settings = pr::Settings {
            repo: flags.repo.clone(),
            base: flags.base.clone(),
            site: site.clone(),
        };
        match pr::run(&project, &settings, &mut |dir, report| {
            apply::run(dir, &client, &options, report)
        }) {
            Ok((report, result, outcome)) => {
                pr = outcome;
                (report, result)
            }
            Err(e) => {
                if flags.json {
                    println!("{}", json!({"error": {"detail": e}}));
                } else {
                    eprintln!("✗ {e}");
                }
                return ExitCode::from(1);
            }
        }
    } else {
        let mut report = Report::new(&project);
        let result = match &flags.mode {
            Mode::Resolve(uid) => apply::resolve(&project, &client, uid, &mut report),
            _ => apply::run(&project, &client, &options, &mut report),
        };
        (report, result)
    };
    let mut commit = None;
    if result.is_ok() && !check && flags.commit && !flags.pr && !report.written.is_empty() {
        let paths = report.written.clone();
        let message = format!(
            "spacepress sync: {} applied, {} conflicts",
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
        let mut body = body;
        if flags.pr {
            body["pr"] = pr.as_ref().map_or(serde_json::Value::Null, |o| {
                json!({
                    "action": o.action,
                    "number": o.pull.as_ref().map(|p| p.number),
                    "url": o.pull.as_ref().map(|p| p.url.clone()),
                    "branch": o.pull.as_ref().map(|p| p.head.clone()),
                    "changes": o.changes,
                })
            });
        }
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
        if let Some(o) = &pr {
            match (&o.pull, o.action) {
                (_, "none") => println!("no changes for a pull request"),
                (_, "base") => println!("only the sync base changed: pushed to the default branch"),
                (Some(p), action) => println!("pull request {action}: {}", p.url),
                (None, action) => println!("pull request {action}"),
            }
        }
    }
    ExitCode::from(code)
}
