//! `spacepress pull [dir] [--site <url>] [--check]`: write a site's runtime schema versions into the
//! project (docs/spec/spec.md §2.10).
//!
//! A site whose app set `runtimeSchema: true` can change its content schema while it runs. Those
//! changes live in the site's database until this command writes them to the committed lock
//! `sp/_generated/runtime-schema.json`, which `spacepress build` reads. After a pull, the project is
//! compiled once here with the new lock, so a lock the compiler would refuse is reported before
//! anything is written, and the generated types and typed client are refreshed to match.
//!
//! It signs in as every remote command does (`remote::sign_in`); the site answers an administrator
//! only.

use crate::sync::client::Client;
use serde_json::Value;
use std::path::PathBuf;
use std::process::ExitCode;

const USAGE: &str = "usage: spacepress pull [project-dir] [--site <url>] [--check]   (as an administrator)";

pub fn cli(args: &[String]) -> ExitCode {
    match run(args) {
        Ok(code) => code,
        Err(e) => {
            eprintln!("{e}");
            ExitCode::from(1)
        }
    }
}

/// The lock as the project commits it: stable bytes for the same site version.
fn render(lock: &Value) -> String {
    serde_json::to_string_pretty(lock).expect("JSON") + "\n"
}

fn run(args: &[String]) -> Result<ExitCode, String> {
    let (mut project, mut site, mut check) = (PathBuf::from("."), None, false);
    let mut it = args.iter();
    while let Some(a) = it.next() {
        match a.as_str() {
            "--site" => site = Some(it.next().ok_or(format!("--site needs a URL\n{USAGE}"))?.clone()),
            "--check" => check = true,
            other if other.starts_with('-') => return Err(format!("unknown option {other}\n{USAGE}")),
            other => project = PathBuf::from(other),
        }
    }
    let site = match site {
        Some(site) => site,
        None => super::dev::url(&project)?,
    };
    let credential = super::remote::require(&site)
        .map_err(|e| format!("{e}\n{USAGE}\n{}", super::remote::SIGN_IN))?
        .credential;
    let project = project
        .canonicalize()
        .map_err(|e| format!("{}: {e}", project.display()))?;
    let client = Client::new(&site, credential)?;
    let answer = client.get("/schema-versions/lock").map_err(|problem| match problem.status {
        404 => format!(
            "{site}: this site has no runtime schema to pull ({}). Its app must be built with `runtimeSchema: true`",
            problem.message()
        ),
        _ => format!("{site}: {}", problem.message()),
    })?;
    let lock = &answer["lock"];
    let (Some(version), Some(format)) = (lock["version"].as_u64(), lock["format"].as_u64()) else {
        return Err(format!("{site}: the answer is not a runtime schema lock"));
    };
    if format != crate::runtime_schema::FORMAT {
        return Err(format!(
            "{site}: the site's runtime schema is in format {format}; this `spacepress` reads format {}. Use the SpacePress version the site runs",
            crate::runtime_schema::FORMAT
        ));
    }
    let layout = crate::layout::Layout::from_project(&project).map_err(|d| d.message)?;
    let lock_rel = layout.runtime_schema_lock();
    let path = project.join(&lock_rel);
    let text = render(lock);
    let current = std::fs::read_to_string(&path).ok();
    if current.as_deref() == Some(text.as_str()) {
        println!("{lock_rel} is at the site's schema version {version}");
        return Ok(ExitCode::SUCCESS);
    }
    let before = current
        .as_deref()
        .and_then(|text| serde_json::from_str::<Value>(text).ok())
        .and_then(|lock| lock["version"].as_u64());
    let was = before.map_or("no lock".to_string(), |v| format!("version {v}"));
    if check {
        eprintln!("{lock_rel} holds {was}; the site is at version {version}. Run `spacepress pull` to update it");
        return Ok(ExitCode::from(1));
    }
    // Compile with the new lock before it replaces the old one: a lock the compiler refuses (the
    // project declares one of its names differently, say) leaves the project as it was.
    crate::sync::write_atomic(&path, text.as_bytes())?;
    if let Err(diagnostic) = super::build::refresh(&project) {
        match &current {
            Some(old) => crate::sync::write_atomic(&path, old.as_bytes())?,
            None => std::fs::remove_file(&path).map_err(|e| format!("{}: {e}", path.display()))?,
        }
        return Err(format!(
            "the site's schema version {version} does not compile with this project; {lock_rel} was left as it was\n{diagnostic}"
        ));
    }
    println!("pulled schema version {version} from {site} -> {lock_rel} (was {was})");
    for name in answer["deploy"]["kept"].as_array().into_iter().flatten() {
        if let Some(name) = name.as_str() {
            println!("  {name}: on the site, now also in the project");
        }
    }
    println!("commit {lock_rel} and {}/, then build and deploy", layout.generated());
    Ok(ExitCode::SUCCESS)
}
