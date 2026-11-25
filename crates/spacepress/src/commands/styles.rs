//! `spacepress styles pull [dir] [--site <dir | @alias>] [--check]`: what was designed in a site's Styles
//! screen or Site Editor becomes the project's `sp/theme.json`
//! (docs/decisions/2026-11-15-styles-from-wordpress.md).
//!
//! The site answers `wp spacepress styles export`: the project's shipped file with the owner's Global
//! Styles merged over it. This writes that document to `sp/theme.json` and compiles the project
//! once, so `sp/_generated/theme.css` follows. Without `--site` it reads the project's dev site.
//! It never runs on its own: the owner's edits reach code only when the author pulls and commits them.

use super::site::Site;
use crate::theme::FILE;
use serde_json::Value;
use std::path::PathBuf;
use std::process::ExitCode;

const USAGE: &str = "usage: spacepress styles pull [project-dir] [--site <dir | @alias>] [--check]";

pub fn cli(args: &[String]) -> ExitCode {
    match run(args) {
        Ok(code) => code,
        Err(e) => {
            eprintln!("{e}");
            ExitCode::from(1)
        }
    }
}

/// The file as the project commits it: tabs, as WordPress's own theme.json files, and a newline.
pub(crate) fn render(theme: &Value) -> String {
    let mut out = Vec::new();
    let mut writer =
        serde_json::Serializer::with_formatter(&mut out, serde_json::ser::PrettyFormatter::with_indent(b"\t"));
    serde::Serialize::serialize(theme, &mut writer).expect("JSON");
    String::from_utf8(out).expect("UTF-8") + "\n"
}

fn run(args: &[String]) -> Result<ExitCode, String> {
    let mut it = args.iter();
    if it.next().map(String::as_str) != Some("pull") {
        return Err(USAGE.into());
    }
    let (mut project, mut site, mut check) = (PathBuf::from("."), None, false);
    while let Some(a) = it.next() {
        match a.as_str() {
            "--site" => {
                site = Some(
                    it.next()
                        .ok_or(format!("--site needs a project directory or @alias\n{USAGE}"))?
                        .clone(),
                )
            }
            "--check" => check = true,
            other if other.starts_with('-') => return Err(format!("unknown option {other}\n{USAGE}")),
            other => project = PathBuf::from(other),
        }
    }
    let site = Site::resolve(site.as_deref(), &project);
    let output = site.wp_output(&["spacepress".into(), "styles".into(), "export".into()])?;
    let stdout = String::from_utf8_lossy(&output.stdout);
    // The answer is the last line that is a JSON object: a PHP notice or a Studio banner may come first.
    let answer = stdout
        .lines()
        .rev()
        .find_map(|line| {
            serde_json::from_str::<Value>(line.trim())
                .ok()
                .filter(|v| v.get("data").is_some() || v.get("error").is_some())
        })
        .ok_or_else(|| {
            format!(
                "the site did not answer `wp spacepress styles export` (exit {}):\n{}{}",
                output.status.code().map_or("signal".into(), |c| c.to_string()),
                stdout.trim(),
                String::from_utf8_lossy(&output.stderr).trim()
            )
        })?;
    if let Some(error) = answer.get("error") {
        return Err(format!(
            "the site refused `wp spacepress styles export`: {}",
            error["message"].as_str().unwrap_or(&error.to_string())
        ));
    }
    let text = render(&answer["data"]);
    crate::theme::parse(&text).map_err(|e| format!("the site's styles aren't a theme.json this build reads: {e}"))?;
    let path = project.join(FILE);
    let current = std::fs::read_to_string(&path).ok();
    if current.as_deref() == Some(text.as_str()) {
        println!("{FILE} already holds the site's styles");
        return Ok(ExitCode::SUCCESS);
    }
    if check {
        eprintln!("{FILE} differs from the site's styles. Run `spacepress styles pull` to update it");
        return Ok(ExitCode::from(1));
    }
    crate::sync::write_atomic(&path, text.as_bytes())?;
    // Compile once with the new file, so sp/_generated/theme.css follows; a project that does not
    // compile keeps its old file.
    if let Err(diagnostic) = super::build::refresh(&project) {
        match &current {
            Some(old) => crate::sync::write_atomic(&path, old.as_bytes())?,
            None => std::fs::remove_file(&path).map_err(|e| format!("{}: {e}", path.display()))?,
        }
        return Err(format!(
            "the project does not compile; {FILE} was left as it was\n{diagnostic}"
        ));
    }
    println!(
        "pulled the site's styles -> {FILE} and {}; commit both, then build and deploy",
        crate::theme::TOKENS
    );
    Ok(ExitCode::SUCCESS)
}

#[cfg(test)]
mod tests {
    #[test]
    fn the_file_is_written_with_tabs_and_a_final_newline() {
        let text = super::render(&serde_json::json!({ "version": 3, "settings": { "color": {} } }));
        assert_eq!(
            text,
            "{\n\t\"version\": 3,\n\t\"settings\": {\n\t\t\"color\": {}\n\t}\n}\n"
        );
    }
}
