//! `typedpress init [dir] [--check]`: what a coding agent needs to work in a project, written into it.
//!
//! - A section of `AGENTS.md` between two markers. Everything outside the markers is the author's
//!   and is never touched; a rerun replaces the section only.
//! - A `typedpress` server in `.mcp.json` that starts `typedpress mcp`. An entry already named `typedpress` is
//!   the author's and is left as it is.
//! - The skills this binary carries (`skills/` in the repository), under each directory in
//!   [`SKILL_DIRS`].
//! - With `--github`, `.github/workflows/typedpress-sync.yml` at the repository's root: the workflow
//!   that runs `typedpress sync --pr` ([`super::init_github`]). Once written, reruns keep it in step.
//!
//! The section is generated from the compiled project (its kinds, actions, jobs, routes and
//! endpoints, as `typedpress mcp`'s `schema` tool describes them) and from the command table, so it goes
//! out of date when the config or the compiler changes. `typedpress check` and `typedpress build` say so in one
//! line, and `typedpress init --check` exits 1 for CI.

use super::{build, describe};
use crate::docs_files::SKILLS;
use serde_json::{Value, json};
use std::fmt::Write as _;
use std::path::{Path, PathBuf};
use std::process::ExitCode;

const BEGIN: &str = "<!-- typedpress:begin -->";
const END: &str = "<!-- typedpress:end -->";
/// Where agents look for a project's skills: `.agents/skills` (Codex and the shared convention)
/// and `.claude/skills` (Claude Code).
const SKILL_DIRS: [&str; 2] = [".agents/skills", ".claude/skills"];
const USAGE: &str = "usage: typedpress init [dir] [--check] [--github]";

/// The `typedpress` entry `.mcp.json` gets. `--no-install` runs the project's own `typedpress` and never
/// downloads a package by that name.
fn mcp_server() -> Value {
    json!({ "command": "npx", "args": ["--no-install", "typedpress", "mcp"] })
}

/// A skill's `name` and `description` from its front matter.
fn front_matter(text: &str) -> (Option<&str>, Option<&str>) {
    let Some(end) = text.strip_prefix("---\n").and_then(|rest| rest.find("\n---\n")) else {
        return (None, None);
    };
    let value = |key: &str| {
        text[4..4 + end]
            .lines()
            .find_map(|line| line.strip_prefix(key)?.strip_prefix(':'))
            .map(|v| v.trim().trim_matches('"'))
    };
    (value("name"), value("description"))
}

fn rule(value: &Value) -> String {
    match value {
        Value::String(rule) => rule.clone(),
        Value::Null => "-".into(),
        other => other.to_string(),
    }
}

/// `title: text, body?: richText, author: ref → authors`: the fields an author declared.
fn field_list(fields: &Value) -> String {
    fields
        .as_object()
        .into_iter()
        .flatten()
        .filter(|(_, field)| field["generated"] != true)
        .map(|(name, field)| {
            let optional = field["required"] != true;
            let target = field["to"].as_str().map(|to| format!(" → {to}")).unwrap_or_default();
            format!(
                "`{name}{}`: {}{target}",
                if optional { "?" } else { "" },
                field["type"].as_str().unwrap_or("unknown")
            )
        })
        .collect::<Vec<_>>()
        .join(", ")
}

/// The managed section of `AGENTS.md`, markers included, for a described project.
fn section(project: &Value) -> String {
    let api = describe::API;
    let name = project["name"].as_str().unwrap_or_default();
    let mut s = format!(
        "{BEGIN}\n## TypedPress\n\n\
         Written by `typedpress init` from this project's config; edit outside this section, and run `typedpress init` again \
         after the config changes.\n\n\
         This project is the TypedPress app `{name}`. TypedPress compiles `typedpress.config.ts` and the `tp/` folder into WordPress \
         plugins. Server code is TypeScript compiled to PHP: a documented subset, not Node.js.\n\n\
         ### Rules\n\n\
         - Server TypeScript compiles to PHP — never import Node.js built-ins (`fs`, `path`, `crypto`, `process`) or arbitrary npm packages in `typedpress.config.ts`, `tp/app/`, actions, hooks, or jobs.\n\
         - Keep import boundaries exact: schema & server builders (`collection`, `table`, `global`, `wp`, `query`, `mutation`, `job`, `cron`, `db`, `HttpError`, `defineConfig`) come from `\"typedpress\"`; visitor-scoped kind queries come from `\"typedpress:db\"`; server page helpers (`Link`, `Content`, `useFormState`) come from `\"typedpress/app\"`; interactive hooks (`useState`, `useLive`) come from `\"typedpress/client\"` inside `\"use client\"` components only.\n\
         - Headful WordPress is the default: pages in `tp/app/` compile to server-rendered PHP and `\"use client\"` components compile to WordPress Interactivity API islands. Only reach for `tp/_generated/client/` or `@typedpress/astro` when building a separate external frontend.\n\
         - Pick `collection()` for editorial content (Gutenberg, drafts, revisions, comments, `sync: \"content/**/*.md\"`) and `table()` for relational app data in SQL tables.\n\
         - Use `.column(\"old_name\")` when renaming a field and `storageName: \"old_table\"` when renaming a table export to keep its physical SQL name.\n\
         - Set explicit `access` rules on kinds and server functions and a `guard` on routes. Use `typedpress:db` for user-facing reads and `destructive: true` on mutations that perform destructive work.\n\
         - Run `typedpress check` after every edit to `typedpress.config.ts` or `tp/` (`typedpress check --fix` applies lint fixes such as `capital_P_dangit`).\n\
         - Never edit `tp/_generated/` or `tp/dist/`: `typedpress build` writes them. Commit `tp/_generated/`.\n\
         - Look an API up before using it: the `docs_search` and `docs_read` tools of the `typedpress` MCP server \
         (`.mcp.json`) answer from the documentation this compiler was built with.\n\
         - `typedpress dev` runs the project on a local WordPress site; site commands such as `typedpress find <storage name>` read it (the `API` column below gives each kind's).\n\n\
         ### Layout\n\n\
         - `typedpress.config.ts`: `export default defineConfig({{ ... }})`, the app's name and options. It may export kinds, actions and jobs too.\n\
         - `tp/`: kinds, actions and jobs, in any file. Each registers under its export name (`export const posts = collection({{ ... }})`); `defineConfig` doesn't list them, and a kind's hooks are `.on({{ ... }})` on its declaration.\n\
         - `tp/app/`: pages (`page.tsx`, `layout.tsx`) and HTTP routes (`route.ts`), by folder.\n\
         - `tp/_generated/`: migrations, types and the typed client. Written by `typedpress build`.\n\
         - `tp/dist/` and `.tp/`: build output and the local dev site. Not committed.\n\n"
    );
    let kinds = project["kinds"].as_object().filter(|kinds| !kinds.is_empty());
    s.push_str("### Kinds\n\n");
    match kinds {
        None => s.push_str("None yet.\n\n"),
        Some(kinds) => {
            s.push_str("| Kind | Stored as | Read | Create | Update | Delete | API |\n|---|---|---|---|---|---|---|\n");
            for (kind, k) in kinds {
                let access = &k["access"];
                let _ = writeln!(
                    s,
                    "| `{kind}` | {} | {} | {} | {} | {} | `{api}/{}` |",
                    k["type"].as_str().unwrap_or_default(),
                    rule(&access["read"]),
                    rule(&access["create"]),
                    rule(&access["update"]),
                    rule(&access["delete"]),
                    k["path"].as_str().unwrap_or_default(),
                );
            }
            s.push('\n');
            for (kind, k) in kinds {
                let _ = writeln!(s, "- `{kind}`: {}", field_list(&k["fields"]));
            }
            s.push_str("\nA `?` marks an optional field. Every kind also has `id`.\n\n");
        }
    }
    if let Some(globals) = project["globals"].as_object().filter(|g| !g.is_empty()) {
        s.push_str("### Globals\n\n");
        for (global, g) in globals {
            let _ = writeln!(s, "- `{global}`: {}", field_list(&g["fields"]));
        }
        s.push('\n');
    }
    if let Some(actions) = project["actions"].as_object().filter(|a| !a.is_empty()) {
        s.push_str("### Actions\n\n| Action | Input | Access | Endpoint |\n|---|---|---|---|\n");
        for (action, a) in actions {
            let input = field_list(&a["input"]);
            let _ = writeln!(
                s,
                "| `{action}` | {} | {} | `{}` |",
                if input.is_empty() { "none" } else { &input },
                rule(&a["access"]),
                a["endpoint"].as_str().unwrap_or_default(),
            );
        }
        s.push('\n');
    }
    if let Some(jobs) = project["jobs"].as_object().filter(|j| !j.is_empty()) {
        s.push_str("### Jobs\n\n");
        for (job, j) in jobs {
            let input = field_list(&j["input"]);
            let _ = writeln!(
                s,
                "- `{job}`: input {}; retried up to {} times",
                if input.is_empty() { "none" } else { &input },
                j["retries"]
            );
        }
        s.push('\n');
    }
    if let Some(routes) = project["routes"].as_array().filter(|r| !r.is_empty()) {
        s.push_str("### Pages and routes\n\n| URL | Kind | File |\n|---|---|---|\n");
        for route in routes {
            let methods: Vec<&str> = route["methods"]
                .as_array()
                .into_iter()
                .flatten()
                .filter_map(Value::as_str)
                .collect();
            let _ = writeln!(
                s,
                "| `{}` | {}{} | `{}` |",
                route["pattern"].as_str().unwrap_or_default(),
                route["type"].as_str().unwrap_or_default(),
                if methods.is_empty() {
                    String::new()
                } else {
                    format!(" ({})", methods.join(", "))
                },
                route["file"].as_str().unwrap_or_default(),
            );
        }
        s.push('\n');
    }
    let roles: Vec<&str> = project["roles"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
        .collect();
    let _ = write!(
        s,
        "### Roles\n\n{}. An access rule that names a role admits that role and every higher one; `anyone` needs no \
         sign-in.\n\n\
         ### Endpoints on a running site\n\n\
         - API: `{api}` (`GET {api}` describes the app).\n\
         - MCP, one tool per kind operation and action: `{}`.\n\
         - Abilities: `{}`.\n\n",
        roles.join(" < "),
        project["endpoints"]["mcp"].as_str().unwrap_or_default(),
        project["endpoints"]["abilities"].as_str().unwrap_or_default(),
    );
    let table: Value =
        serde_json::from_str(crate::cli::COMMANDS).expect("runtime/TypedPress/Cli/commands.json is JSON");
    for (group, title) in [
        ("build", "### Commands on the project's files"),
        (
            "site",
            "### Commands on a site\n\nThese run inside WordPress, on the local dev site unless `--site <dir | workspace | @alias>` names another.",
        ),
    ] {
        let _ = writeln!(s, "{title}\n");
        for row in table[group].as_array().into_iter().flatten() {
            let usage = format!(
                "typedpress {} {}",
                row["name"].as_str().unwrap_or_default(),
                row["args"].as_str().unwrap_or_default()
            );
            let _ = writeln!(
                s,
                "- `{}`: {}",
                usage.trim_end(),
                row["summary"].as_str().unwrap_or_default()
            );
        }
        s.push('\n');
    }
    s.push_str("### Skills\n\n");
    for (path, text) in SKILLS.iter().filter(|(path, _)| path.ends_with("/SKILL.md")) {
        if let (Some(name), Some(description)) = front_matter(text) {
            let _ = writeln!(s, "- `{name}` (`{}/{path}`): {description}", SKILL_DIRS[0]);
        }
    }
    let _ = writeln!(s, "{END}");
    s
}

/// The byte offset of the line that is exactly `marker`, if there is one. A marker quoted inside the
/// author's prose ("do not edit below `<!-- typedpress:begin -->`") is text, not a marker.
fn marker_line(text: &str, marker: &str) -> Option<usize> {
    let mut at = 0;
    for line in text.split_inclusive('\n') {
        if line.trim_end_matches(['\n', '\r']) == marker {
            return Some(at);
        }
        at += line.len();
    }
    None
}

/// `AGENTS.md` with the section put in: in place of the one it has, or at the end.
fn with_section(current: Option<&str>, section: &str) -> Result<String, String> {
    let Some(current) = current else {
        return Ok(section.to_string());
    };
    match (marker_line(current, BEGIN), marker_line(current, END)) {
        (Some(begin), Some(end)) if begin < end => {
            let after = &current[end + END.len()..];
            let after = after
                .strip_prefix("\r\n")
                .or_else(|| after.strip_prefix('\n'))
                .unwrap_or(after);
            Ok(format!("{}{section}{after}", &current[..begin]))
        }
        (None, None) => {
            let gap = if current.is_empty() || current.ends_with("\n\n") {
                ""
            } else if current.ends_with('\n') {
                "\n"
            } else {
                "\n\n"
            };
            Ok(format!("{current}{gap}{section}"))
        }
        _ => Err(format!(
            "AGENTS.md has one of the marker lines {BEGIN} and {END} without the other, or in the wrong order; restore the pair or delete both, then run typedpress init again"
        )),
    }
}

/// `.mcp.json` with a `typedpress` server, or `None` when it already has one. The file is the author's:
/// the entry is inserted into its text, so numbers, key order, indentation and every other server
/// stay exactly as written. Only a missing file, or one with no `mcpServers` object, is written in
/// full.
fn with_server(current: Option<&str>) -> Result<Option<String>, String> {
    let Some(text) = current else {
        let config = json!({ "mcpServers": { "typedpress": mcp_server() } });
        return Ok(Some(serde_json::to_string_pretty(&config).expect("json") + "\n"));
    };
    let config: Value = serde_json::from_str(text)
        .map_err(|e| format!(".mcp.json is not valid JSON ({e}); fix it, then run typedpress init again"))?;
    let config = config.as_object().ok_or(".mcp.json is not a JSON object")?;
    let entry = serde_json::to_string(&mcp_server()).expect("json");
    match config.get("mcpServers") {
        Some(Value::Object(servers)) if servers.contains_key("typedpress") => Ok(None),
        Some(Value::Object(servers)) => {
            // After the `{` that opens the top-level `mcpServers` value.
            let open = json_value_start(text, "mcpServers").ok_or(".mcp.json: cannot find mcpServers")?;
            let comma = if servers.is_empty() { "" } else { "," };
            Ok(Some(format!(
                "{}\"typedpress\": {entry}{comma}{}",
                &text[..=open],
                &text[open + 1..]
            )))
        }
        Some(_) => Err(".mcp.json: mcpServers is not an object".into()),
        None => {
            // A new member at the start of the top-level object.
            let open = text.find('{').expect("a JSON object starts with {");
            let comma = if config.is_empty() { "" } else { "," };
            Ok(Some(format!(
                "{}\"mcpServers\": {{\"typedpress\": {entry}}}{comma}{}",
                &text[..=open],
                &text[open + 1..]
            )))
        }
    }
}

/// The offset of the `{` that opens the value of `key` in the top-level object of `text` (valid
/// JSON). Scans tokens so a key inside a string or a nested object is never matched.
fn json_value_start(text: &str, key: &str) -> Option<usize> {
    let bytes = text.as_bytes();
    let (mut depth, mut at) = (0usize, 0usize);
    let mut last_string: Option<(usize, usize)> = None;
    while at < bytes.len() {
        match bytes[at] {
            b'"' => {
                let start = at;
                at += 1;
                while at < bytes.len() && bytes[at] != b'"' {
                    at += if bytes[at] == b'\\' { 2 } else { 1 };
                }
                last_string = Some((start, at));
            }
            b'{' | b'[' => {
                if depth == 1 && bytes[at] == b'{' {
                    // A value that follows `"key"` and a colon at the top level.
                    if let Some((start, end)) = last_string {
                        let between = &text[end + 1..at];
                        let name: Option<String> = serde_json::from_str(&text[start..=end]).ok();
                        if between.trim() == ":" && name.as_deref() == Some(key) {
                            return Some(at);
                        }
                    }
                }
                depth += 1;
            }
            b'}' | b']' => depth = depth.saturating_sub(1),
            _ => {}
        }
        at += 1;
    }
    None
}

/// Every file `typedpress init` would write that differs from what is there: (path relative to the
/// project, its new contents).
fn pending(project: &Path, described: &Value, github: bool) -> Result<Vec<(PathBuf, String)>, String> {
    let read = |rel: &Path| match std::fs::read_to_string(project.join(rel)) {
        Ok(text) => Ok(Some(text)),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(e) => Err(format!("{}: {e}", rel.display())),
    };
    let mut out = Vec::new();
    let agents = PathBuf::from("AGENTS.md");
    let current = read(&agents)?;
    let next = with_section(current.as_deref(), &section(described))?;
    if current.as_deref() != Some(next.as_str()) {
        out.push((agents, next));
    }
    let mcp = PathBuf::from(".mcp.json");
    if let Some(next) = with_server(read(&mcp)?.as_deref())? {
        out.push((mcp, next));
    }
    // A skills directory the author removed (they use one agent, not the other) stays removed; a
    // project that has neither gets both.
    let used: Vec<&str> = SKILL_DIRS
        .iter()
        .copied()
        .filter(|dir| project.join(dir).exists())
        .collect();
    let dirs = if used.is_empty() || !installed(project) {
        SKILL_DIRS.to_vec()
    } else {
        used
    };
    for dir in dirs {
        for (path, text) in SKILLS {
            let rel = Path::new(dir).join(path);
            if read(&rel)?.as_deref() != Some(*text) {
                out.push((rel, text.to_string()));
            }
        }
    }
    let dirs: Vec<String> = described["kinds"]
        .as_object()
        .into_iter()
        .flatten()
        .filter_map(|(_, kind)| kind["sync"]["dir"].as_str().map(String::from))
        .collect();
    match super::init_github::workflow(project, &dirs) {
        None if github => {
            return Err(
                "typedpress init --github writes .github/workflows/ at the root of the project's git repository, and this project is in none; run git init (or clone it) first".into(),
            );
        }
        None => {}
        Some((rel, text)) => match super::init_github::plan(read(&rel)?.as_deref(), &text, github) {
            super::init_github::Plan::Write => out.push((rel, text)),
            super::init_github::Plan::Keep => {}
            super::init_github::Plan::Refuse(why) => return Err(why),
        },
    }
    Ok(out)
}

/// Writes `rel` (a path `typedpress init` owns) below `project`, or for the sync workflow below the
/// project's repository root, and nowhere else.
///
/// - A directory on the way that is a symbolic link is refused: `.claude` pointing elsewhere would
///   put the skills outside the project.
/// - A file that is a symbolic link (`AGENTS.md -> CLAUDE.md`) is written through, to its target,
///   and stays a link, as long as the target is inside the project.
/// - The file keeps the permissions it had; a new one gets the usual ones for the user's umask.
fn write(project: &Path, rel: &Path, text: &str) -> Result<(), String> {
    let path = target(project, rel)?;
    let permissions = std::fs::metadata(&path).ok().map(|meta| meta.permissions());
    let dir = path.parent().expect("a file has a directory");
    std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", rel.display()))?;
    let mut file = tempfile::NamedTempFile::new_in(dir).map_err(|e| format!("{}: {e}", rel.display()))?;
    std::io::Write::write_all(&mut file, text.as_bytes()).map_err(|e| format!("{}: {e}", rel.display()))?;
    // A temporary file is private (0600); the file it replaces is whatever the author made it, and a
    // new one is what the user's umask gives any new file.
    let permissions = permissions.unwrap_or_else(new_file_permissions);
    file.as_file()
        .set_permissions(permissions)
        .map_err(|e| format!("{}: {e}", rel.display()))?;
    file.persist(&path)
        .map_err(|e| format!("{}: {}", rel.display(), e.error))?;
    Ok(())
}

/// Where `rel` is written: below `project`, through no linked directory, and through a linked file
/// only to a target inside the project.
fn target(project: &Path, rel: &Path) -> Result<PathBuf, String> {
    let mut dir = project.to_path_buf();
    for part in rel.parent().into_iter().flat_map(Path::components) {
        dir.push(part);
        if std::fs::symlink_metadata(&dir).is_ok_and(|meta| meta.file_type().is_symlink()) {
            return Err(format!(
                "{} is a symbolic link; typedpress init writes only inside the project. Replace it with a directory, or run without it",
                dir.strip_prefix(project).unwrap_or(&dir).display()
            ));
        }
    }
    let mut path = project.join(rel);
    if std::fs::symlink_metadata(&path).is_ok_and(|meta| meta.file_type().is_symlink()) {
        let target = path.canonicalize().map_err(|e| format!("{}: {e}", rel.display()))?;
        if !target.starts_with(project) {
            return Err(format!(
                "{} links outside the project; typedpress init writes only inside it",
                rel.display()
            ));
        }
        path = target;
    }
    Ok(path)
}

/// The permissions a newly created file gets under this process's umask.
fn new_file_permissions() -> std::fs::Permissions {
    let probe = tempfile::tempdir().and_then(|dir| {
        let path = dir.path().join("probe");
        std::fs::File::create(&path)?;
        std::fs::metadata(&path).map(|meta| meta.permissions())
    });
    probe.unwrap_or_else(|_| {
        #[cfg(unix)]
        {
            std::os::unix::fs::PermissionsExt::from_mode(0o644)
        }
        #[cfg(not(unix))]
        {
            std::fs::metadata(".").expect("current directory").permissions()
        }
    })
}

/// A project that ran `typedpress init` before: its `AGENTS.md` has the section.
fn installed(project: &Path) -> bool {
    std::fs::read_to_string(project.join("AGENTS.md")).is_ok_and(|text| marker_line(&text, BEGIN).is_some())
}

/// The one line `typedpress check` and `typedpress build` print when a project's agent files no longer match
/// its config or this compiler. Nothing for a project that never ran `typedpress init`.
pub(super) fn stale_hint(project: &Path, schema: &crate::schema::Schema) -> Option<String> {
    if !installed(project) && !super::init_github::written(project) {
        return None;
    }
    let pending = pending(project, &describe::project(schema), false).ok()?;
    let workflow = |(path, _): &(PathBuf, String)| path.ends_with(".github/workflows/typedpress-sync.yml");
    let agents = installed(project);
    let mut stale = Vec::new();
    if agents && pending.iter().any(|p| !workflow(p)) {
        stale.push("AGENTS.md and the agent skills no longer match this project or this compiler");
    }
    if pending.iter().any(workflow) {
        stale.push(".github/workflows/typedpress-sync.yml is out of date");
    }
    (!stale.is_empty()).then(|| {
        format!(
            "{}; run typedpress init to update {}",
            stale.join(", and "),
            if stale.len() == 1 && pending.iter().all(workflow) {
                "it"
            } else {
                "them"
            }
        )
    })
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    let (mut project, mut check, mut github) = (PathBuf::from("."), false, false);
    for arg in args {
        match arg.as_str() {
            "--check" => check = true,
            "--github" => github = true,
            other if other.starts_with('-') => {
                eprintln!("unknown option {other} for typedpress init\n{USAGE}");
                return ExitCode::from(2);
            }
            other => project = PathBuf::from(other),
        }
    }
    let project = match project.canonicalize() {
        Ok(project) => project,
        Err(error) => {
            eprintln!("{}: {error}\n{USAGE}", project.display());
            return ExitCode::from(2);
        }
    };
    if !project.join("typedpress.config.ts").is_file() {
        eprintln!(
            "no typedpress.config.ts in {}; typedpress init adds the agent files to an existing project (npm create typedpress <dir> creates one)",
            project.display()
        );
        return ExitCode::from(1);
    }
    // The section describes the compiled project, so a project that does not compile has none.
    let built = match build::build(&project, build::frozen(false)) {
        Ok(built) => built,
        Err(diagnostic) => {
            eprint!("{}", crate::diag::render(&diagnostic, &project));
            eprintln!("typedpress init describes the compiled project; fix this, then run it again");
            return ExitCode::from(1);
        }
    };
    let pending = match pending(&project, &describe::project(&built.schema), github) {
        Ok(pending) => pending,
        Err(error) => {
            eprintln!("error: {error}");
            return ExitCode::from(1);
        }
    };
    if check {
        if pending.is_empty() {
            println!(
                "ok: AGENTS.md, .mcp.json{} the agent skills{} match this project",
                if super::init_github::written(&project) {
                    ","
                } else {
                    " and"
                },
                if super::init_github::written(&project) {
                    " and the sync workflow"
                } else {
                    ""
                }
            );
            return ExitCode::SUCCESS;
        }
        for (path, _) in &pending {
            eprintln!("out of date: {}", path.display());
        }
        eprintln!("run typedpress init to update them");
        return ExitCode::from(1);
    }
    // Every path is checked before any is written, so a refusal leaves the project as it was.
    if let Some(error) = pending.iter().find_map(|(path, _)| target(&project, path).err()) {
        eprintln!("error: {error}");
        return ExitCode::from(1);
    }
    for (path, text) in &pending {
        if let Err(error) = write(&project, path, text) {
            eprintln!("error: {error}");
            return ExitCode::from(1);
        }
        println!("wrote {}", path.display());
    }
    if pending.is_empty() {
        let workflow = super::init_github::written(&project);
        println!(
            "AGENTS.md, .mcp.json{} the agent skills{} are up to date",
            if workflow { "," } else { " and" },
            if workflow { " and the sync workflow" } else { "" }
        );
    }
    // Claude Code reads AGENTS.md only when the project has no CLAUDE.md of its own.
    if std::fs::read_to_string(project.join("CLAUDE.md")).is_ok_and(|text| !text.contains("AGENTS.md")) {
        println!("note: CLAUDE.md does not mention AGENTS.md; add the line @AGENTS.md to it so Claude Code reads both");
    }
    ExitCode::SUCCESS
}

#[cfg(test)]
mod tests {
    use super::{BEGIN, END, with_section, with_server};

    #[test]
    fn the_section_replaces_only_itself_and_is_appended_once() {
        let section = format!("{BEGIN}\nnew\n{END}\n");
        assert_eq!(with_section(None, &section).unwrap(), section);
        let appended = with_section(Some("# Mine\n\nKeep this."), &section).unwrap();
        assert_eq!(appended, format!("# Mine\n\nKeep this.\n\n{section}"));
        let replaced = with_section(Some(&format!("# Mine\n\n{BEGIN}\nold\n{END}\n\nAfter.\n")), &section).unwrap();
        assert_eq!(replaced, format!("# Mine\n\n{section}\nAfter.\n"));
        // Applying it again changes nothing.
        assert_eq!(with_section(Some(&replaced), &section).unwrap(), replaced);
        assert!(with_section(Some(&format!("{BEGIN}\nno end\n")), &section).is_err());
        assert!(with_section(Some(&format!("{END}\n{BEGIN}\n")), &section).is_err());
    }

    #[test]
    fn an_existing_first_server_and_every_other_server_are_kept() {
        let added = with_server(Some(r#"{"mcpServers":{"other":{"command":"x"}},"extra":1}"#))
            .unwrap()
            .unwrap();
        let config: serde_json::Value = serde_json::from_str(&added).unwrap();
        assert_eq!(config["mcpServers"]["other"]["command"], "x");
        assert_eq!(config["extra"], 1);
        assert_eq!(config["mcpServers"]["typedpress"]["args"][2], "mcp");
        assert_eq!(
            with_server(Some(r#"{"mcpServers":{"typedpress":{"command":"mine"}}}"#)).unwrap(),
            None
        );
        assert!(with_server(Some("{ not json")).is_err());
        assert!(with_server(Some("[]")).is_err());
    }
}
