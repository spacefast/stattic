//! `spacepress sync --pr`: the site's changes as one pull request instead of a commit on the current branch.
//!
//! Each run starts from the default branch (fetched from `origin`) in a temporary worktree, so the
//! checkout it is started from is never touched. The sync there is the ordinary two-way sync: file
//! changes on the default branch go to the site, site changes are written to files. The resulting
//! tree, compared with the default branch, is what the pull request carries:
//!
//! - nothing: no pull request, and an open sync pull request is closed, because the default branch
//!   already has what it carried;
//! - only the sync base (a file change reached the site, nothing came back) and no pull request open:
//!   that commit is pushed to the default branch as a fast-forward, or opened as a pull request when
//!   the push is refused;
//! - the same changes the open sync pull request already carries: nothing is pushed;
//! - otherwise the open sync pull request's branch gets one commit with that tree, whose parents are
//!   the branch and the default branch, so the push is a fast-forward and never rewrites history; or a
//!   new branch and pull request when none is open.
//!
//! Conflict files (`.sp/conflicts/<uid>.md`, ignored by git) are added to the pull request and
//! listed in a comment whenever the set changes. A sync branch carrying a commit that `spacepress sync`
//! didn't make (not a merge, and its subject doesn't start with `spacepress sync`) is never rebuilt: the
//! run stops and says so.

use super::Report;
use super::github::{GitHub, Pull, repo_from_remote};
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::process::Command;

/// Where the pull request goes; each is optional and found from the environment otherwise.
pub struct Settings {
    /// `owner/name`; `GITHUB_REPOSITORY`, then `origin`'s URL.
    pub repo: Option<String>,
    /// The branch the pull request targets; the repository's default branch.
    pub base: Option<String>,
    /// The site's URL: names the branch, so each site has its own pull request.
    pub site: String,
}

/// What happened to the pull request.
pub struct Outcome {
    /// `opened`, `updated`, `unchanged`, `closed`, `none`, or `base` (only the sync base changed, and
    /// it was pushed to the default branch).
    pub action: &'static str,
    pub pull: Option<Pull>,
    pub changes: Vec<String>,
}

const BASE_FILE: &str = ".sp/sync-base.json";
const CONFLICTS: &str = ".sp/conflicts";

fn git(dir: &Path, args: &[&str]) -> Result<String, String> {
    git_env(dir, args, &[])
}

fn git_env(dir: &Path, args: &[&str], env: &[(&str, &str)]) -> Result<String, String> {
    let out = Command::new("git")
        .arg("-C")
        .arg(dir)
        .args(args)
        .envs(env.iter().copied())
        .output()
        .map_err(|e| format!("git: {e}"))?;
    if !out.status.success() {
        return Err(format!(
            "git {}: {}",
            args.first().copied().unwrap_or_default(),
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(String::from_utf8_lossy(&out.stdout).trim_end_matches('\n').to_string())
}

/// A path in the repository: the project's relative directory joined with `path`.
fn in_repo(rel: &str, path: &str) -> String {
    if rel.is_empty() {
        path.to_string()
    } else {
        format!("{rel}/{path}")
    }
}

/// The branch name segment for a site: its host and port, and for a site below a path
/// (`https://example.test/blog`) the path as well, so two sites on one host never share a branch.
fn site_segment(site: &str) -> String {
    let path = url::Url::parse(site)
        .ok()
        .map(|u| u.path().trim_matches('/').to_string())
        .unwrap_or_default();
    let host = host_segment(site);
    if path.is_empty() {
        return host;
    }
    let path: String = path
        .chars()
        .map(|c| if c.is_ascii_alphanumeric() || c == '-' { c } else { '-' })
        .collect();
    format!("{host}/{}", path.trim_matches('-'))
}

/// The site's host as a branch name segment: `docs.example.test`, `127.0.0.1-8080`.
fn host_segment(site: &str) -> String {
    let url = url::Url::parse(site).ok();
    let host = url.as_ref().and_then(|u| u.host_str()).unwrap_or("site");
    let port = url
        .as_ref()
        .and_then(|u| u.port())
        .map(|p| format!("-{p}"))
        .unwrap_or_default();
    format!("{host}{port}")
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric() || c == '.' || c == '-' {
                c
            } else {
                '-'
            }
        })
        .collect::<String>()
        .trim_matches('.')
        .to_string()
}

/// The temporary worktree, removed however the run ends.
struct Worktree {
    repo: PathBuf,
    path: PathBuf,
    _dir: tempfile::TempDir,
}

impl Drop for Worktree {
    fn drop(&mut self) {
        let _ = Command::new("git")
            .arg("-C")
            .arg(&self.repo)
            .args(["worktree", "remove", "--force"])
            .arg(&self.path)
            .output();
    }
}

/// path → blob id (None: deleted) for every file that differs between two trees.
fn changes(repo: &Path, from: &str, to: &str) -> Result<BTreeMap<String, Option<String>>, String> {
    let raw = git(repo, &["diff-tree", "-r", "--no-renames", "-z", from, to])?;
    let mut out = BTreeMap::new();
    let mut fields = raw.split('\0').filter(|s| !s.is_empty());
    while let (Some(meta), Some(path)) = (fields.next(), fields.next()) {
        // `:100644 100644 <old> <new> M`
        let new = meta.split(' ').nth(3).unwrap_or_default();
        let gone = new.bytes().all(|b| b == b'0');
        out.insert(path.to_string(), (!gone).then(|| new.to_string()));
    }
    Ok(out)
}

fn conflict_files(repo: &Path, tree: &str, rel: &str) -> Result<Vec<String>, String> {
    let listed = git(
        repo,
        &["ls-tree", "-r", "--name-only", tree, "--", &in_repo(rel, CONFLICTS)],
    )?;
    Ok(listed.lines().map(String::from).collect())
}

/// A run: the sync's report and result, and what happened to the pull request (None: nothing was
/// pushed, because the sync failed or the push did).
pub type Run = (Report, Result<(), String>, Option<Outcome>);

/// Run `sync` on a fresh worktree of the default branch and carry the result to the sync pull
/// request. Err: nothing ran (git or GitHub refused before the sync). Ok((report, result, outcome)):
/// the sync ran; `outcome` is None when it failed, so nothing was pushed.
pub fn run(
    project: &Path,
    settings: &Settings,
    sync: &mut dyn FnMut(&Path, &mut Report) -> Result<(), String>,
) -> Result<Run, String> {
    let env = |name: &str| std::env::var(name).ok().filter(|s| !s.is_empty());
    let root = PathBuf::from(
        git(project, &["rev-parse", "--show-toplevel"]).map_err(|_| "--pr needs the project in a git repository")?,
    );
    let canonical = |p: &Path| p.canonicalize().unwrap_or_else(|_| p.to_path_buf());
    let rel = canonical(project)
        .strip_prefix(canonical(&root))
        .map_err(|_| "the project is outside its git repository")?
        .to_string_lossy()
        .replace('\\', "/");
    let token =
        env("GITHUB_TOKEN").ok_or("--pr needs GITHUB_TOKEN: a token with contents: write and pull-requests: write")?;
    let remote = git(&root, &["remote", "get-url", "origin"])
        .map_err(|_| "--pr pushes to the origin remote, and there is none")?;
    let repo = settings
        .repo
        .clone()
        .or_else(|| env("GITHUB_REPOSITORY"))
        .or_else(|| repo_from_remote(&remote))
        .ok_or("--pr needs the GitHub repository: --repo owner/name, or GITHUB_REPOSITORY")?;
    // The push goes to origin with git's own credentials; the pull request names the repository. A
    // GitHub origin that is another repository (a fork) would push where no pull request can come from.
    if let Some(pushed) = repo_from_remote(&remote)
        && !pushed.eq_ignore_ascii_case(&repo)
    {
        return Err(format!(
            "origin is {pushed}, but the pull request would be opened on {repo}; run in a clone of {repo}, or pass --repo {pushed}"
        ));
    }
    let api = env("GITHUB_API_URL").unwrap_or_else(|| "https://api.github.com".into());
    let gh = GitHub::new(&api, &repo, &token)?;
    let base = match &settings.base {
        Some(b) => b.clone(),
        None => gh.default_branch()?,
    };
    // The timestamp follows the last `/`: `spacepress-sync/<host>[/<path>]/<unix time>`.
    let prefix = format!("spacepress-sync/{}/", site_segment(&settings.site));
    let open = gh.open_pull(&base, &prefix)?;

    // Into refs of this command's own, never FETCH_HEAD, which the checkout's own fetches share.
    let fetch = |branch: &str, name: &str| -> Result<String, String> {
        let local = format!("refs/spacepress-sync/{name}");
        git(
            &root,
            &[
                "fetch",
                "-q",
                "--no-write-fetch-head",
                "origin",
                &format!("+refs/heads/{branch}:{local}"),
            ],
        )?;
        git(&root, &["rev-parse", &format!("{local}^{{commit}}")])
    };
    let base_sha = fetch(&base, "base")?;
    let head_sha = match &open {
        Some(pull) => Some(fetch(&pull.head, "head")?),
        None => None,
    };
    if let (Some(pull), Some(head)) = (&open, &head_sha) {
        // Commits of someone else's on the branch are theirs: rebuilding the branch would drop them.
        // Merges (GitHub's "Update branch", and this command's own) only bring the default branch in.
        let foreign = git(
            &root,
            &["log", "--no-merges", "--format=%h %s", head, "--not", &base_sha],
        )?;
        if let Some(commit) = foreign
            .lines()
            .find(|l| !l.split_once(' ').is_some_and(|(_, s)| s.starts_with("spacepress sync")))
        {
            return Err(format!(
                "{}: its branch has a commit spacepress sync didn't make ({commit}); merge or close that pull request, and the next run opens a new one",
                pull.url
            ));
        }
    }

    let dir = tempfile::tempdir().map_err(|e| format!("a temporary directory: {e}"))?;
    let worktree = Worktree {
        repo: root.clone(),
        path: dir.path().join("w"),
        _dir: dir,
    };
    let wt = worktree.path.to_string_lossy().to_string();
    git(&root, &["worktree", "add", "-q", "--detach", &wt, &base_sha])?;
    let wt_project = if rel.is_empty() {
        worktree.path.clone()
    } else {
        worktree.path.join(&rel)
    };
    // Conflict files are rewritten from this run: a tracked one from an earlier merge is stale.
    git(
        &worktree.path,
        &["rm", "-q", "-r", "--ignore-unmatch", "--", &in_repo(&rel, CONFLICTS)],
    )?;

    let mut report = Report::new(&wt_project);
    let result = sync(&wt_project, &mut report);
    if result.is_err() || !report.failures.is_empty() || report.denied {
        return Ok((report, result, None));
    }
    let outcome = match publish(
        &gh,
        &worktree.path,
        &root,
        &rel,
        &base,
        &base_sha,
        &prefix,
        open,
        head_sha,
        &settings.site,
        &report,
    ) {
        Ok(outcome) => Some(outcome),
        Err(e) => {
            report.fail(e);
            None
        }
    };
    Ok((report, result, outcome))
}

#[allow(clippy::too_many_arguments)]
fn publish(
    gh: &GitHub,
    wt: &Path,
    root: &Path,
    rel: &str,
    base: &str,
    base_sha: &str,
    prefix: &str,
    open: Option<Pull>,
    head_sha: Option<String>,
    site: &str,
    report: &Report,
) -> Result<Outcome, String> {
    let scope = if rel.is_empty() {
        ".".to_string()
    } else {
        rel.to_string()
    };
    git(wt, &["add", "-A", "--", &scope])?;
    // A file the sync wrote that .gitignore (or a global excludes file) hides would be left out of
    // the commit while the base says both sides have it: the next run would read that as a deletion.
    let ignored: Vec<String> = report
        .written
        .iter()
        .filter(|p| p.exists())
        .filter_map(|p| p.strip_prefix(wt).ok().map(|r| r.to_string_lossy().replace('\\', "/")))
        .filter(|p| !p.contains("/.sp/") && !p.starts_with(".sp/"))
        .filter(|p| git(wt, &["check-ignore", "-q", "--no-index", "--", p]).is_ok())
        .collect();
    if !ignored.is_empty() {
        return Err(format!(
            "{} ignored by git, so the pull request can't carry it; stop ignoring the synced folders",
            ignored.join(", ")
        ));
    }
    // Both are under the ignored `.sp/`: the base is committed, and conflicts go in the pull request.
    for path in [BASE_FILE, CONFLICTS] {
        if wt.join(in_repo(rel, path)).exists() {
            git(wt, &["add", "-A", "-f", "--", &in_repo(rel, path)])?;
        }
    }
    let tree = git(wt, &["write-tree"])?;
    // The sync base counts as a change even alone: it records what both sides agreed on, and without it
    // a later edit of the same field in git and on the site would read as a conflict.
    let diff = changes(wt, base_sha, &tree)?;
    let listed: Vec<String> = diff
        .iter()
        .map(|(p, blob)| format!("{} {p}", if blob.is_some() { "M" } else { "D" }))
        .collect();
    let conflicts = conflict_files(wt, &tree, rel)?;

    if diff.is_empty() {
        let Some(pull) = open else {
            return Ok(Outcome {
                action: "none",
                pull: None,
                changes: listed,
            });
        };
        gh.comment(
            pull.number,
            &format!("`{base}` and the site agree now, so this pull request has nothing left to carry. Closed by `spacepress sync --pr`."),
        )?;
        gh.close_pull(pull.number)?;
        return Ok(Outcome {
            action: "closed",
            pull: Some(pull),
            changes: listed,
        });
    }

    let base_path = in_repo(rel, BASE_FILE);
    let bookkeeping = open.is_none() && diff.keys().all(|p| *p == base_path);
    let mut previous_conflicts = Vec::new();
    if let (Some(pull), Some(head)) = (&open, &head_sha) {
        let fork = git(wt, &["merge-base", head, base_sha])?;
        if changes(wt, &fork, head)? == diff {
            // Nothing to push. A run that pushed and then failed to describe it left the description or
            // the conflict comment behind: write them now.
            let body = description(site, base, &listed, report, &conflicts);
            if pull.body != body {
                gh.update_pull(pull.number, &body)?;
            }
            let comment = conflict_comment(&conflicts);
            if !conflicts.is_empty() && !gh.has_comment(pull.number, &comment)? {
                gh.comment(pull.number, &comment)?;
            }
            return Ok(Outcome {
                action: "unchanged",
                pull: Some(pull.clone()),
                changes: listed,
            });
        }
        previous_conflicts = conflict_files(wt, head, rel)?;
    }

    let message = format!(
        "spacepress sync: {} applied, {} conflicts, from {site}",
        report.applied,
        report.conflicts.len()
    );
    let mut parents: Vec<&str> = Vec::new();
    if let Some(head) = &head_sha {
        parents.push(head);
        if git(wt, &["merge-base", "--is-ancestor", base_sha, head]).is_err() {
            parents.push(base_sha);
        }
    } else {
        parents.push(base_sha);
    }
    let mut args = vec!["commit-tree", tree.as_str(), "-m", message.as_str()];
    for p in &parents {
        args.extend(["-p", p]);
    }
    // In GitHub Actions, without an identity configured: the Actions bot's.
    let identity =
        git(wt, &["config", "user.email"]).is_err() && std::env::var("GITHUB_ACTIONS").as_deref() == Ok("true");
    let bot = [
        ("GIT_AUTHOR_NAME", "github-actions[bot]"),
        (
            "GIT_AUTHOR_EMAIL",
            "41898282+github-actions[bot]@users.noreply.github.com",
        ),
        ("GIT_COMMITTER_NAME", "github-actions[bot]"),
        (
            "GIT_COMMITTER_EMAIL",
            "41898282+github-actions[bot]@users.noreply.github.com",
        ),
    ];
    let commit = git_env(wt, &args, if identity { &bot } else { &[] })?;

    if bookkeeping {
        // Only the sync base moved (a file change reached the site): nothing to review, and leaving it
        // behind would turn the next edit of those fields on both sides into a conflict. A fast-forward
        // of the default branch; where that is refused (a protected branch, or it moved), a pull request.
        if git(root, &["push", "-q", "origin", &format!("{commit}:refs/heads/{base}")]).is_ok() {
            return Ok(Outcome {
                action: "base",
                pull: None,
                changes: listed,
            });
        }
    }
    let branch = match &open {
        Some(pull) => pull.head.clone(),
        None => {
            let now = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_secs())
                .unwrap_or_default();
            format!("{prefix}{now}")
        }
    };
    // A fast-forward, never forced: a branch that moved since it was fetched refuses the push.
    git(
        root,
        &["push", "-q", "origin", &format!("{commit}:refs/heads/{branch}")],
    )?;

    let body = description(site, base, &listed, report, &conflicts);
    let (action, pull) = match open {
        Some(pull) => {
            gh.update_pull(pull.number, &body)?;
            ("updated", pull)
        }
        None => {
            let created = gh.create_pull(
                &branch,
                base,
                &format!("Content changes from {}", site_segment(site)),
                &body,
            );
            match created {
                Ok(pull) => ("opened", pull),
                Err(e) => {
                    // No pull request holds the branch, so no later run finds it: take it away again
                    // rather than leave one more behind on every run.
                    let cleanup = gh
                        .delete_branch(&branch)
                        .err()
                        .map(|d| format!("; and the branch {branch} is left behind: {d}"));
                    return Err(format!("{e}{}", cleanup.unwrap_or_default()));
                }
            }
        }
    };
    if conflicts != previous_conflicts && !(conflicts.is_empty() && previous_conflicts.is_empty()) {
        gh.comment(pull.number, &conflict_comment(&conflicts))?;
    }
    Ok(Outcome {
        action,
        pull: Some(pull),
        changes: listed,
    })
}

/// The pull request's description: what changed, the conflicts, and how the branch is kept.
fn description(site: &str, base: &str, listed: &[String], report: &Report, conflicts: &[String]) -> String {
    let mut s = format!("Content from {site} that `{base}` doesn't have yet, written by `spacepress sync --pr`.\n\n");
    s.push_str("| Change | File |\n|---|---|\n");
    for line in listed.iter().take(200) {
        let (kind, path) = line.split_once(' ').unwrap_or(("M", line));
        let kind = if kind == "D" { "deleted" } else { "written" };
        s.push_str(&format!("| {kind} | `{path}` |\n"));
    }
    if listed.len() > 200 {
        s.push_str(&format!("\n…and {} more files.\n", listed.len() - 200));
    }
    if !conflicts.is_empty() {
        s.push_str(&format!(
            "\n**{} conflicts**: the same field changed in git and on the site. Nothing was written to either side for them; resolve them before merging (see the comment below).\n",
            report.conflicts.len().max(conflicts.len())
        ));
    }
    s.push_str(&format!(
        "\nEach run of `spacepress sync --pr` builds this branch again from `{base}` and the site and adds one commit, so \
         edit content on `{base}` or on the site, not here. A commit on this branch that `spacepress sync` didn't make stops \
         the next runs until this pull request is merged or closed.\n"
    ));
    s
}

/// The comment listing the conflicts: the same text for the same set, so a run can tell whether it
/// was posted.
fn conflict_comment(files: &[String]) -> String {
    if files.is_empty() {
        return "**No conflicts left.** Every record this pull request carries agrees with the site now.".into();
    }
    let mut s = String::from(
        "**Sync conflicts.** The same fields of these records changed in git and on the site since they last agreed. \
         Each is a conflict file on this branch, with both versions:\n\n",
    );
    for f in files {
        s.push_str(&format!("- `{f}`\n"));
    }
    s.push_str(
        "\nNothing was written to either side for them. To resolve one, make the record the same on both sides: edit it \
         on the site, or edit its file on the default branch, into the version to keep. The next run of \
         `spacepress sync --pr` finds them equal, records that, and drops the conflict file from this pull request.\n",
    );
    s
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_sites_host_port_and_path_name_its_branch() {
        assert_eq!(site_segment("https://docs.example.test/"), "docs.example.test");
        assert_eq!(site_segment("https://example.test/blog"), "example.test/blog");
        assert_eq!(site_segment("https://example.test/a/b.c/"), "example.test/a-b-c");
        assert_ne!(
            site_segment("https://example.test/blog"),
            site_segment("https://example.test/docs")
        );
        assert_eq!(host_segment("https://docs.example.test/"), "docs.example.test");
        assert_eq!(host_segment("http://127.0.0.1:8080/blog"), "127.0.0.1-8080");
        assert_eq!(host_segment("not a url"), "site");
    }

    #[test]
    fn paths_are_joined_below_the_projects_directory() {
        assert_eq!(in_repo("", BASE_FILE), ".sp/sync-base.json");
        assert_eq!(in_repo("site", CONFLICTS), "site/.sp/conflicts");
    }
}
