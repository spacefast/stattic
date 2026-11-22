//! `typedpress init --github`: `.github/workflows/typedpress-sync.yml`, the GitHub Actions workflow that runs
//! `typedpress sync --pr` (see `www/content/docs/integrations/markdown-sync.md`).
//!
//! One job serves both directions, because `typedpress sync --pr` is the two-way sync: a run on a
//! schedule or on the site's `repository_dispatch` brings site edits back as a pull request, and a
//! run on a push to the default branch that touches a synced folder sends those files to the site.
//!
//! The file is the compiler's while its first line is the header [`header()`] writes, which names the
//! project it was written for (its folder in the repository). For that project, `typedpress init` writes
//! it back when the synced folders change, and `typedpress check` says when it is out of date. Other
//! projects in the same repository leave it alone. Delete that line to keep your own edits; the
//! file is then never touched again.

use std::path::{Path, PathBuf};
use std::process::Command;

const HEADER: &str = "# Written by `typedpress init --github`";
const KEEP: &str = "; delete this line to keep your own edits.";

/// The header line for the project at `rel` in its repository (`.` at the root).
fn header(rel: &str) -> String {
    format!("{HEADER} for {}{KEEP}", if rel.is_empty() { "." } else { rel })
}

/// The project a workflow's first line says it was written for, if it is one `typedpress init` wrote.
fn owner(text: &str) -> Option<&str> {
    text.lines()
        .next()?
        .strip_prefix(HEADER)?
        .strip_prefix(" for ")?
        .strip_suffix(KEEP)
}
/// The `repository_dispatch` event type the site sends when a synced record is saved.
pub const DISPATCH_EVENT: &str = "typedpress-content-changed";
/// Actions pinned by commit; the comment names the release.
const CHECKOUT: &str = "actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09 # v5.1.0";
const SETUP_NODE: &str = "actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6.5.0";

fn git(dir: &Path, args: &[&str]) -> Option<String> {
    let out = Command::new("git").arg("-C").arg(dir).args(args).output().ok()?;
    out.status
        .success()
        .then(|| String::from_utf8_lossy(&out.stdout).trim().to_string())
        .filter(|s| !s.is_empty())
}

/// The project's path below its repository's root (empty at the root), or None outside a repository.
fn in_repository(project: &Path) -> Option<String> {
    let root = git(project, &["rev-parse", "--show-toplevel"]).map(PathBuf::from)?;
    let canonical = |p: &Path| p.canonicalize().unwrap_or_else(|_| p.to_path_buf());
    canonical(project)
        .strip_prefix(canonical(&root))
        .ok()
        .map(|p| p.to_string_lossy().replace('\\', "/"))
}

/// A YAML double-quoted scalar (JSON's string syntax is one).
fn quoted(s: &str) -> String {
    serde_json::Value::String(s.to_string()).to_string()
}

/// The workflow's path relative to the project, and its text. `dirs` are the synced folders,
/// relative to the project. None outside a git repository: GitHub reads workflows only at a
/// repository's root, and without one there is no root to put it in.
pub(super) fn workflow(project: &Path, dirs: &[String]) -> Option<(PathBuf, String)> {
    let rel = in_repository(project)?;
    let in_repo = |p: &str| {
        if rel.is_empty() {
            p.to_string()
        } else {
            format!("{rel}/{p}")
        }
    };
    let mut paths: Vec<String> = dirs
        .iter()
        .map(|d| {
            if d == "." {
                in_repo("**")
            } else {
                in_repo(&format!("{d}/**"))
            }
        })
        .collect();
    paths.sort();
    paths.dedup();
    let install = if project.join("package-lock.json").is_file() {
        "npm ci"
    } else {
        "npm install --no-audit --no-fund"
    };
    let working = if rel.is_empty() {
        String::new()
    } else {
        format!(
            "    defaults:\n      run:\n        working-directory: {}\n",
            quoted(&rel)
        )
    };
    // No kind syncs yet: nothing in git goes to the site, so pushes start no run.
    let mut push = String::new();
    if !paths.is_empty() {
        // Branches only: tags have no paths to filter on, and would each start a skipped run.
        push.push_str("  push:\n    branches: [\"**\"]\n    paths:\n");
        for p in &paths {
            push.push_str(&format!("      - {}\n", quoted(p)));
        }
    }
    let header = header(&rel);
    let text = format!(
        "{header}
#
# Content in git (https://spacepress.dev/docs/integrations/markdown-sync):
# on a schedule, and on a {DISPATCH_EVENT} dispatch (a save on the site can send one), the site's
# edits come back as one pull request; on a push to the default branch that changes synced files, those files go
# to the site. Both are `typedpress sync --pr`.
#
# Settings > Secrets and variables > Actions:
#   variable TP_SITE_URL       the site's URL
#   secret   TP_APP_PASSWORD   user:application-password of a user who may update the synced kinds
#                                 (typedpress identity agent add <user> --name=sync --write); or TP_TOKEN
name: TypedPress content sync

on:
  schedule:
    - cron: \"17 * * * *\"
  repository_dispatch:
    types: [{DISPATCH_EVENT}]
  workflow_dispatch:
{push}
permissions:
  contents: write
  pull-requests: write

concurrency:
  group: typedpress-sync
  cancel-in-progress: false

jobs:
  sync:
    # Pushes count only on the default branch: that is the branch the site's content comes from.
    if: github.event_name != 'push' || github.ref == format('refs/heads/{{0}}', github.event.repository.default_branch)
    runs-on: ubuntu-24.04
    timeout-minutes: 30
{working}    steps:
      - uses: {CHECKOUT}
        with:
          fetch-depth: 0
      - uses: {SETUP_NODE}
        with:
          node-version: \"22\"
      - run: {install}
      - name: typedpress sync --pr
        env:
          TP_SITE_URL: ${{{{ vars.TP_SITE_URL }}}}
          TP_APP_PASSWORD: ${{{{ secrets.TP_APP_PASSWORD }}}}
          TP_TOKEN: ${{{{ secrets.TP_TOKEN }}}}
          GITHUB_TOKEN: ${{{{ secrets.GITHUB_TOKEN }}}}
        run: npx --no-install typedpress sync --pr --site \"$TP_SITE_URL\"
"
    );
    // From the project up to the repository's root, where GitHub reads workflows.
    let up = rel
        .split('/')
        .filter(|s| !s.is_empty())
        .fold(PathBuf::new(), |p, _| p.join(".."));
    Some((up.join(".github/workflows/typedpress-sync.yml"), text))
}

/// Whether the project's repository has the workflow `typedpress init --github` wrote for this project,
/// unedited.
pub(super) fn written(project: &Path) -> bool {
    let Some((path, text)) = workflow(project, &[]) else {
        return false;
    };
    std::fs::read_to_string(project.join(path))
        .is_ok_and(|current| owner(&current).is_some() && owner(&current) == owner(&text))
}

/// What `typedpress init` does with the workflow, given the file there (`current`) and the one it would
/// write (`next`).
pub(super) enum Plan {
    /// Write `next`: asked for and absent, or this project's and changed.
    Write,
    /// Leave it: not asked for, the author's own, or up to date.
    Keep,
    /// `--github` asked for it, but the file there is another project's or the author's.
    Refuse(String),
}

pub(super) fn plan(current: Option<&str>, next: &str, asked: bool) -> Plan {
    match current {
        None if asked => Plan::Write,
        None => Plan::Keep,
        Some(text) if text == next => Plan::Keep,
        Some(text) if owner(text).is_some() && owner(text) == owner(next) => Plan::Write,
        Some(_) if !asked => Plan::Keep,
        Some(text) => Plan::Refuse(match owner(text) {
            Some(other) => format!(
                ".github/workflows/typedpress-sync.yml was written for the project at {other}; one repository has one sync workflow. Run typedpress init --github there, or delete the file first"
            ),
            None => ".github/workflows/typedpress-sync.yml is your own (its first line isn't typedpress init's); delete it first to have typedpress init write one".into(),
        }),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_workflow_belongs_to_the_project_its_header_names() {
        let a = format!("{}\nname: x\n", header("a"));
        let b = format!("{}\nname: y\n", header("b"));
        assert_eq!(owner(&a), Some("a"));
        assert_eq!(owner(&format!("{}\n", header(""))), Some("."));
        assert_eq!(owner("# mine\n"), None);
        assert!(matches!(plan(None, &a, true), Plan::Write));
        assert!(matches!(plan(None, &a, false), Plan::Keep));
        assert!(matches!(plan(Some(&a), &a, true), Plan::Keep));
        assert!(matches!(plan(Some(&a.replace('x', "z")), &a, false), Plan::Write));
        assert!(
            matches!(plan(Some(&b), &a, false), Plan::Keep),
            "another project's, not asked for"
        );
        assert!(
            matches!(plan(Some(&b), &a, true), Plan::Refuse(_)),
            "another project's, asked for"
        );
        assert!(matches!(plan(Some("# mine\n"), &a, true), Plan::Refuse(_)));
        assert!(matches!(plan(Some("# mine\n"), &a, false), Plan::Keep));
    }
}
