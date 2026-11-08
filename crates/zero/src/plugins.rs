//! Plugin packages: who a plugin is, what its compiled code uses, and the committed lock that grants
//! it. The compiler is the boundary: every capability-bearing call in a plugin's compiled code is
//! recorded here as a [`Use`], and `zero/plugins.lock.json` decides which of them are granted.
//! See docs/decisions/2026-11-07-plugin-capabilities.md.

use crate::diag::{Code, Diag, Result, Span};
use crate::loader::Program;
use serde_json::{Value as Json, json};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet, HashSet};
use std::path::{Path, PathBuf};

/// Capability kinds, in lock order. Each value is a sorted set of strings, so diffs and narrowing
/// treat every kind alike. A new kind is a row here and the place that records it.
pub const KINDS: &[(&str, &str)] = &[
    ("read", "reads"),
    ("write", "writes"),
    ("delete", "deletes from"),
    ("hooks", "hooks"),
    ("actions", "defines the action"),
    ("routes", "serves"),
    ("http", "fetches from"),
    ("env", "reads the environment variable"),
    ("mail", "sends mail with"),
    ("jobs", "enqueues the job"),
    // `setTimeout` and `AbortSignal.timeout`: request-scoped `Zero\Timers` entries that wait.
    ("timers", "sets timers with"),
    // WordPress from TypeScript (`Compiler::use_wordpress`): `abilities.call` from "php/wordpress",
    // and handlers registered on WordPress actions and filters.
    ("abilities", "calls the ability"),
    ("wpHooks", "registers the WordPress hook"),
    // PHP functions imported from "php/wordpress" (later "php/<slug>" or "php"), recorded as
    // `<source>:<function>`.
    ("php", "calls the PHP function"),
];

pub const LOCK_PATH: &str = "zero/plugins.lock.json";

/// One registered plugin package.
#[derive(Debug, Clone)]
pub struct Plugin {
    pub name: String,
    /// The `definePlugin(...)` call.
    pub span: Span,
    pub root: PathBuf,
    pub package: String,
    pub version: String,
    /// `sha256-<hex>` over every module the plugin's package reaches, by project-relative path.
    pub integrity: String,
}

/// A capability one compiled function of a plugin uses.
#[derive(Debug, Clone)]
pub struct Use {
    pub plugin: usize,
    /// The PHP function compiling when it was seen. Empty for what registration alone implies
    /// (the row a hook receives): granted, but no handler's read for the flow report.
    pub function: String,
    pub kind: &'static str,
    pub value: String,
    pub span: Span,
}

pub type Grant = BTreeMap<&'static str, BTreeSet<String>>;

pub fn kind(name: &str) -> Option<&'static str> {
    KINDS.iter().find(|(k, _)| *k == name).map(|(k, _)| *k)
}

fn verb(kind: &str) -> &'static str {
    KINDS.iter().find(|(k, _)| *k == kind).map_or("uses", |(_, v)| v)
}

pub fn grant_of(uses: &[Use], plugin: usize) -> Grant {
    let mut grant: Grant = KINDS.iter().map(|(k, _)| (*k, BTreeSet::new())).collect();
    for u in uses.iter().filter(|u| u.plugin == plugin) {
        grant.entry(u.kind).or_default().insert(u.value.clone());
    }
    grant
}

pub fn grant_json(grant: &Grant) -> Json {
    Json::Object(
        KINDS
            .iter()
            .map(|(k, _)| (k.to_string(), json!(grant.get(k).cloned().unwrap_or_default())))
            .collect(),
    )
}

/// Whether the locked grant covers one inferred use. `write: ["posts.*"]` covers every field, and a
/// runtime-built fetch URL (`http: "*"`) is allowed at build time under any host list: the generated
/// PHP checks each request against the locked hosts.
fn covers(locked: &Grant, kind: &str, value: &str) -> bool {
    let Some(set) = locked.get(kind) else { return false };
    set.contains(value)
        || (kind == "http" && value == "*")
        || (kind == "write"
            && value
                .split_once('.')
                .is_some_and(|(c, _)| set.contains(&format!("{c}.*"))))
}

/// The nearest enclosing package: a directory with a named package.json, or any package directly
/// under `node_modules`. Symlinked local packages canonicalize outside `node_modules`, so the name
/// test matters as much as the directory test.
pub fn package_root(program: &Program, file: &Path) -> Option<PathBuf> {
    let mut dir = file.parent();
    while let Some(d) = dir {
        let manifest = d.join("package.json");
        if program.fs.is_file(&manifest) {
            let in_node_modules = d.parent().is_some_and(|p| {
                p.file_name().is_some_and(|n| n == "node_modules")
                    || (p.file_name().is_some_and(|n| n.to_string_lossy().starts_with('@'))
                        && p.parent()
                            .and_then(Path::file_name)
                            .is_some_and(|n| n == "node_modules"))
            });
            let named = program
                .fs
                .read_to_string(&manifest)
                .ok()
                .and_then(|s| serde_json::from_str::<Json>(&s).ok())
                .is_some_and(|m| m.get("name").and_then(Json::as_str).is_some());
            if in_node_modules || named {
                return Some(d.to_path_buf());
            }
        }
        dir = d.parent();
    }
    None
}

fn import_spans(program: &Program, module: usize) -> Vec<(String, Span)> {
    use crate::ir::Stmt;
    program.modules[module]
        .body
        .iter()
        .filter_map(|s| match s {
            Stmt::Import { source, span, .. } => Some((source.clone(), *span)),
            Stmt::ExportNames {
                from: Some(from), span, ..
            }
            | Stmt::ExportAll { from, span } => Some((from.clone(), *span)),
            _ => None,
        })
        .collect()
}

/// Identifies the package that called `definePlugin` at `span`. The call must come from an
/// installed package, not from the app's own sources.
pub fn identify(program: &Program, name: &str, span: Span) -> Result<Plugin> {
    let module = program
        .modules
        .iter()
        .find(|m| m.file == span.file)
        .ok_or_else(|| Diag::new(span, "definePlugin() must be called from source"))?;
    let app = package_root(program, &program.modules[program.entry].path);
    let root = package_root(program, &module.path)
        .filter(|root| Some(root) != app.as_ref())
        .ok_or_else(|| {
            Diag::new(
                span,
                format!("plugin \"{name}\" is defined in the app, not in an installed package"),
            )
            .code(Code::PluginIsolation)
            .help(
                "publish the plugin as a package and `npm install` it, or register the app's own hooks in defineConfig",
            )
        })?;
    let manifest: Json = program
        .fs
        .read_to_string(&root.join("package.json"))
        .ok()
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or(Json::Null);
    let field = |key: &str| manifest.get(key).and_then(Json::as_str).unwrap_or("").to_string();
    Ok(Plugin {
        name: name.to_string(),
        span,
        package: field("name"),
        version: field("version"),
        root,
        integrity: String::new(),
    })
}

/// Enforces package isolation and fills each plugin's integrity. From a plugin package, imports
/// may reach its own modules and ordinary packages, never the app's sources or another plugin.
pub fn isolate(program: &Program, plugins: &mut [Plugin]) -> Result<()> {
    let project = program.modules[program.entry]
        .path
        .parent()
        .map(Path::to_path_buf)
        .unwrap_or_default();
    let app = package_root(program, &program.modules[program.entry].path);
    let roots: Vec<Option<PathBuf>> = program.modules.iter().map(|m| package_root(program, &m.path)).collect();
    for i in 0..plugins.len() {
        let own = plugins[i].root.clone();
        let mut seen: HashSet<usize> = program
            .modules
            .iter()
            .filter(|m| m.path.starts_with(&own))
            .map(|m| m.id)
            .collect();
        let mut queue: Vec<usize> = seen.iter().copied().collect();
        while let Some(m) = queue.pop() {
            for (spec, span) in import_spans(program, m) {
                let Some(&target) = program.resolved.get(&(m, spec.clone())) else {
                    continue;
                };
                let path = &program.modules[target].path;
                let other = plugins
                    .iter()
                    .enumerate()
                    .find(|(j, p)| *j != i && path.starts_with(&p.root) && !p.root.starts_with(&own));
                let reason = if let Some((_, p)) = other {
                    Some(format!("it belongs to plugin \"{}\" ({})", p.name, p.package))
                } else if !path.starts_with(&own) && roots[target] == app {
                    Some("it is one of the app's own modules".to_string())
                } else {
                    None
                };
                if let Some(reason) = reason {
                    return Err(Diag::new(
                        span,
                        format!("plugin \"{}\" can't import \"{spec}\": {reason}", plugins[i].name),
                    )
                    .code(Code::PluginIsolation)
                    .help("a plugin imports its own modules, \"zero\" and ordinary npm packages; take app values as plugin options"));
                }
                if seen.insert(target) {
                    queue.push(target);
                }
            }
        }
        let mut files: Vec<(String, &Path)> = seen
            .iter()
            .map(|&m| {
                let path = &program.modules[m].path;
                (
                    path.strip_prefix(&project)
                        .unwrap_or(path)
                        .to_string_lossy()
                        .replace('\\', "/"),
                    path.as_path(),
                )
            })
            .collect();
        files.sort();
        let mut hash = Sha256::new();
        for (name, path) in files {
            hash.update(name.as_bytes());
            hash.update([0]);
            hash.update(program.fs.read_to_string(path).unwrap_or_default().as_bytes());
            hash.update([0]);
        }
        plugins[i].integrity = format!(
            "sha256-{}",
            hash.finalize().iter().map(|b| format!("{b:02x}")).collect::<String>()
        );
    }
    Ok(())
}

/// What the build decided about the plugins.
pub struct Settled {
    /// The lock to write (None when there are no plugins and no lock).
    pub lock: Option<Json>,
    /// Human-readable lock changes, one line each.
    pub changes: Vec<String>,
    /// The `plugins` section of `zero check --json`.
    pub report: Json,
    /// Plugin name → hosts its fetches may reach; `None` for any public host.
    pub hosts: Vec<(String, Option<Vec<String>>)>,
}

fn parse_lock(lock: &Json) -> Result<BTreeMap<String, (String, Grant)>> {
    let bad = |m: String| Diag::new(Span::NONE, format!("{LOCK_PATH}: {m}")).code(Code::PluginCapability);
    if lock.get("version") != Some(&json!(1)) {
        return Err(bad("expected version 1".into()));
    }
    let mut out = BTreeMap::new();
    for (name, entry) in lock.get("plugins").and_then(Json::as_object).into_iter().flatten() {
        let mut grant: Grant = KINDS.iter().map(|(k, _)| (*k, BTreeSet::new())).collect();
        for (k, values) in entry.get("grant").and_then(Json::as_object).into_iter().flatten() {
            let k = kind(k).ok_or_else(|| bad(format!("plugins.{name}.grant has no capability kind `{k}`")))?;
            let values = values
                .as_array()
                .ok_or_else(|| bad(format!("plugins.{name}.grant.{k} must be a list of strings")))?;
            for v in values {
                let v = v
                    .as_str()
                    .ok_or_else(|| bad(format!("plugins.{name}.grant.{k} must be a list of strings")))?;
                grant.get_mut(k).expect("every kind").insert(v.to_string());
            }
        }
        let integrity = entry.get("integrity").and_then(Json::as_str).unwrap_or("").to_string();
        out.insert(name.clone(), (integrity, grant));
    }
    Ok(out)
}

fn diff(before: &Grant, after: &Grant) -> Vec<String> {
    let mut lines = Vec::new();
    for (k, _) in KINDS {
        let (b, a) = (&before[k], &after[k]);
        lines.extend(a.difference(b).map(|v| format!("+ {k}: {v}")));
        lines.extend(b.difference(a).map(|v| format!("- {k}: {v}")));
    }
    lines
}

/// Handlers that both read data and send something out: a report, never a refusal.
fn flows(uses: &[Use], plugin: usize, functions: &[(String, BTreeSet<String>)]) -> Json {
    let mut out = Vec::new();
    for (handler, reached) in functions {
        let mine = |kinds: &[&str]| -> BTreeSet<String> {
            uses.iter()
                .filter(|u| u.plugin == plugin && reached.contains(&u.function) && kinds.contains(&u.kind))
                .map(|u| format!("{}: {}", u.kind, u.value))
                .collect()
        };
        let (reads, sends) = (mine(&["read", "env"]), mine(&["http", "mail"]));
        if !reads.is_empty() && !sends.is_empty() {
            out.push(json!({ "handler": handler, "reads": reads, "sends": sends }));
        }
    }
    Json::Array(out)
}

/// Compares each plugin's inferred grant with its lock entry. `frozen` (CI): any growth or new
/// plugin is refused. Otherwise a changed package source updates its entry; an unchanged source
/// that exceeds its entry means the owner narrowed it, and the excess is refused.
/// `handlers` lists each plugin root function with the compiled functions it reaches.
pub fn settle(
    plugins: &[Plugin],
    uses: &[Use],
    handlers: &[(usize, String, BTreeSet<String>)],
    lock: Option<&Json>,
    frozen: bool,
) -> Result<Settled> {
    let locked = match lock {
        Some(lock) => parse_lock(lock)?,
        None => BTreeMap::new(),
    };
    let mut entries = serde_json::Map::new();
    let mut changes = Vec::new();
    let mut report = Vec::new();
    let mut hosts = Vec::new();
    for (i, p) in plugins.iter().enumerate() {
        let inferred = grant_of(uses, i);
        let excess = |granted: &Grant| -> Option<&Use> {
            uses.iter()
                .find(|u| u.plugin == i && !covers(granted, u.kind, &u.value))
        };
        let entry = locked.get(&p.name);
        let (status, approved) = match entry {
            None if frozen => {
                return Err(Diag::new(
                    p.span,
                    format!(
                        "plugin \"{}\" ({}@{}) isn't in {LOCK_PATH}",
                        p.name, p.package, p.version
                    ),
                )
                .code(Code::PluginCapability)
                .help("run `zero build` without --frozen, review the plugin's grant, and commit the lock"));
            }
            None => {
                changes.push(format!(
                    "plugin \"{}\" ({}@{}) is new in {LOCK_PATH}:",
                    p.name, p.package, p.version
                ));
                changes.extend(diff(&grant_of(&[], 0), &inferred).into_iter().map(|l| format!("  {l}")));
                ("added", inferred.clone())
            }
            Some((integrity, granted)) => {
                let excess = excess(granted);
                match excess {
                    None if !frozen && *integrity != p.integrity => {
                        changes.push(format!(
                            "plugin \"{}\" ({}@{}): new package source, grant unchanged",
                            p.name, p.package, p.version
                        ));
                        ("locked", granted.clone())
                    }
                    None => ("locked", granted.clone()),
                    Some(u) if frozen || *integrity == p.integrity => {
                        let allowed: Vec<_> = granted[u.kind].iter().cloned().collect();
                        let only = if allowed.is_empty() {
                            format!("grants it no `{}`", u.kind)
                        } else {
                            format!("grants it only {}", allowed.join(", "))
                        };
                        return Err(Diag::new(
                            u.span,
                            format!("plugin \"{}\" {} {}; {LOCK_PATH} {only}", p.name, verb(u.kind), u.value),
                        )
                        .code(Code::PluginCapability)
                        .help(if frozen {
                            "run `zero build` without --frozen to update the lock, review the diff, and commit it"
                        } else {
                            "the owner narrowed this plugin: remove the call, or add the value to its lock entry"
                        }));
                    }
                    Some(_) => {
                        // A new package source: the entry becomes what it uses now, shown as a
                        // diff. A host list the owner wrote in place of `*` stays.
                        let mut next = inferred.clone();
                        if !granted["http"].contains("*") && !granted["http"].is_empty() {
                            next.get_mut("http").expect("every kind").remove("*");
                        }
                        changes.push(format!(
                            "plugin \"{}\" ({}@{}) changed its grant:",
                            p.name, p.package, p.version
                        ));
                        changes.extend(diff(granted, &next).into_iter().map(|l| format!("  {l}")));
                        ("updated", next)
                    }
                }
            }
        };
        let any_host = approved["http"].contains("*");
        hosts.push((
            p.name.clone(),
            (!any_host).then(|| approved["http"].iter().cloned().collect()),
        ));
        // A narrowed entry stays as the owner wrote it; a changed source records what it now is.
        let integrity = match (status, entry) {
            ("locked", Some((integrity, ..))) if frozen => integrity.clone(),
            _ => p.integrity.clone(),
        };
        entries.insert(
            p.name.clone(),
            json!({ "package": p.package, "version": p.version, "integrity": integrity, "grant": grant_json(&approved) }),
        );
        let functions: Vec<(String, BTreeSet<String>)> = handlers
            .iter()
            .filter(|(plugin, ..)| *plugin == i)
            .map(|(_, name, reached)| (name.clone(), reached.clone()))
            .collect();
        report.push(json!({
            "name": p.name,
            "package": p.package,
            "version": p.version,
            "integrity": p.integrity,
            "status": status,
            "locked": entry.map(|(_, g)| grant_json(g)),
            "inferred": grant_json(&inferred),
            "flows": flows(uses, i, &functions),
        }));
    }
    for name in locked.keys().filter(|n| !plugins.iter().any(|p| &p.name == *n)) {
        changes.push(format!("plugin \"{name}\" leaves {LOCK_PATH}"));
    }
    let lock = (!plugins.is_empty() || lock.is_some()).then(|| json!({ "version": 1, "plugins": entries }));
    Ok(Settled {
        lock,
        changes,
        report: Json::Array(report),
        hosts,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn grant(pairs: &[(&'static str, &str)]) -> Grant {
        let mut g: Grant = KINDS.iter().map(|(k, _)| (*k, BTreeSet::new())).collect();
        for (k, v) in pairs {
            g.get_mut(k).unwrap().insert(v.to_string());
        }
        g
    }

    #[test]
    fn a_hooks_own_row_is_granted_but_not_reported_as_a_read_before_a_send() {
        let at = |function: &str, kind: &'static str, value: &str| Use {
            plugin: 0,
            function: function.to_string(),
            kind,
            value: value.to_string(),
            span: Span::NONE,
        };
        let handler = vec![("hook".to_string(), BTreeSet::from(["hook".to_string()]))];
        // Registration's implicit read (no function) plus a constant send: nothing to report.
        let uses = vec![at("", "read", "posts"), at("hook", "http", "api.example.com")];
        assert_eq!(flows(&uses, 0, &handler), json!([]));
        assert!(grant_of(&uses, 0)["read"].contains("posts"));
        // A read the handler makes itself is reported.
        let uses = vec![at("hook", "read", "users"), at("hook", "http", "api.example.com")];
        assert_eq!(
            flows(&uses, 0, &handler),
            json!([{ "handler": "hook", "reads": ["read: users"], "sends": ["http: api.example.com"] }])
        );
    }

    #[test]
    fn a_collection_wildcard_covers_fields_and_runtime_urls_defer_to_the_host_check() {
        let locked = grant(&[("write", "posts.*"), ("http", "api.example.com")]);
        assert!(covers(&locked, "write", "posts.body"));
        assert!(!covers(&locked, "write", "pages.body"));
        assert!(covers(&locked, "http", "*"));
        assert!(!covers(&locked, "http", "other.example.com"));
    }
}
