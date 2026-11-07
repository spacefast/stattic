//! Commit what a sync wrote (design §5.4 step 6): only those paths, only in a git repository, nothing
//! when nothing changed.

use std::path::{Path, PathBuf};
use std::process::Command;

/// Commit `paths` (absolute, inside `project`). Ok(false) when there was nothing to commit or the
/// project isn't in a git repository.
pub fn commit(project: &Path, paths: &[PathBuf], message: &str) -> Result<bool, String> {
    let inside = Command::new("git")
        .arg("-C")
        .arg(project)
        .args(["rev-parse", "--is-inside-work-tree"])
        .output();
    // Conflict files are the user's working files under the ignored .zero/; only the bases are committed.
    let conflicts = project.join(".zero/conflicts");
    let paths: Vec<PathBuf> = paths.iter().filter(|p| !p.starts_with(&conflicts)).cloned().collect();
    // A path that's gone and was never tracked (written and moved within the run) isn't a change.
    let tracked = |p: &PathBuf| {
        Command::new("git")
            .arg("-C")
            .arg(project)
            .args(["ls-files", "--error-unmatch", "--"])
            .arg(p)
            .output()
            .is_ok_and(|o| o.status.success())
    };
    let paths: Vec<PathBuf> = paths.into_iter().filter(|p| p.exists() || tracked(p)).collect();
    let paths = paths.as_slice();
    if !inside.is_ok_and(|o| o.status.success()) || paths.is_empty() {
        return Ok(false);
    }
    let git = |args: &[&str], paths: &[PathBuf]| {
        Command::new("git")
            .arg("-C")
            .arg(project)
            .args(args)
            .arg("--")
            .args(paths)
            .output()
            .map_err(|e| e.to_string())
    };
    // `-A` stages deletions and renames too; ignored paths (the conflict files) are skipped by git.
    let added = git(&["add", "-A"], paths)?;
    if !added.status.success() {
        return Err(format!("git add: {}", String::from_utf8_lossy(&added.stderr).trim()));
    }
    let staged = git(&["diff", "--cached", "--quiet"], paths)?;
    if staged.status.success() {
        return Ok(false);
    }
    let done = git(&["commit", "-q", "-m", message], paths)?;
    if !done.status.success() {
        return Err(format!("git commit: {}", String::from_utf8_lossy(&done.stderr).trim()));
    }
    Ok(true)
}

/// `.zero/*` ignored except the bases, added to `.gitignore` when absent (design §5.4 step 6).
/// Ok(true) when it changed the file.
pub fn ignore_build_output(project: &Path) -> Result<bool, String> {
    let path = project.join(".gitignore");
    let current = std::fs::read_to_string(&path).unwrap_or_default();
    if current.lines().any(|l| l.trim() == "!.zero/sync-base.json") {
        return Ok(false);
    }
    let mut next = current
        .lines()
        .filter(|l| !matches!(l.trim(), ".zero" | ".zero/" | "/.zero" | "/.zero/"))
        .collect::<Vec<_>>()
        .join("\n");
    if !next.is_empty() {
        next.push('\n');
    }
    next.push_str(".zero/*\n!.zero/sync-base.json\n");
    std::fs::write(&path, next).map_err(|e| format!("{}: {e}", path.display()))?;
    Ok(true)
}
