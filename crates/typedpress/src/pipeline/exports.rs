//! Registers what the app exports (docs/spec/spec.md §1.1). Every exported kind, global, file
//! bucket, action, job and admin screen in `typedpress.config.ts` or a module under `tp/` registers under its export
//! name, whatever the file is called and whether or not anything imports it. Other exports are
//! ignored, so helper modules need no special place.
//!
//! Registration fills the config's internal registries (`collections`, `tables`, `users`, `taxonomies`,
//! `globals`, `files`, `actions`, `jobs`, `screens`), so everything after it reads one shape. `defineConfig`
//! itself no longer takes those keys (docs/decisions/2026-11-11-authoring-surface.md, ruling 36);
//! only a 0.3.0 capture still writes them (ruling 41), and its registration is the one it had.

use crate::diag::{Code, Diag, Result, Span};
use crate::eval::Evaluator;
use crate::loader::Program;
use crate::value::{StorageKind, Value};
use indexmap::IndexMap;
use std::path::Path;
use std::rc::Rc;

/// Where an export name is taken, for the error that names both places.
struct Taken {
    value: Value,
    span: Span,
}

/// The registry an exported value belongs in, or `None` for an export TypedPress ignores.
fn registry(value: &Value) -> Option<&'static str> {
    Some(match value {
        Value::Collection(c) => match c.kind {
            StorageKind::Collection => "collections",
            StorageKind::Table => "tables",
            StorageKind::UserProjection => "users",
            StorageKind::Taxonomy => "taxonomies",
        },
        Value::Global(_) => "globals",
        Value::FileBucket(_) => "files",
        Value::Action(_) => "actions",
        Value::Job(_) => "jobs",
        Value::Screen(_) => "screens",
        _ => return None,
    })
}

/// Whether two registry values are the same definition (one allocation, exported and listed).
fn same(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Collection(a), Value::Collection(b)) => Rc::ptr_eq(a, b),
        (Value::Global(a), Value::Global(b)) => Rc::ptr_eq(a, b),
        (Value::FileBucket(a), Value::FileBucket(b)) => Rc::ptr_eq(a, b),
        (Value::Action(a), Value::Action(b))
        | (Value::Job(a), Value::Job(b))
        | (Value::Screen(a), Value::Screen(b)) => Rc::ptr_eq(a, b),
        _ => false,
    }
}

fn span_of(value: &Value) -> Span {
    match value {
        Value::Collection(c) => c.span,
        Value::Global(g) => g.span,
        Value::FileBucket(b) => b.span,
        Value::Action(a) | Value::Job(a) | Value::Screen(a) => a.span,
        _ => Span::NONE,
    }
}

fn duplicate(root: &Path, name: &str, first: Span, second: Span) -> Diag {
    let at =
        crate::diag::position(first, root).map_or_else(|| "the config".to_string(), |(f, l, _)| format!("{f}:{l}"));
    Diag::new(second, format!("`{name}` names two things: at {at} and here"))
        .code(Code::DuplicateExport)
        .help("export names are the API (first.<name>(), its route and its Ability); rename one")
}

/// The `defineConfig` keys that registered named things before exports did (ruling 36): each is
/// refused where it's written, with the export form that replaces it. A 0.3.0 capture still
/// writes them, and is read as it was (ruling 41).
const REMOVED: &[(&str, &str, &str)] = &[
    ("collections", "a kind registers by its export", "collection"),
    ("tables", "a table registers by its export", "table"),
    ("users", "the users kind registers by its export", "userProjection"),
    ("taxonomies", "a taxonomy registers by its export", "taxonomy"),
    ("globals", "a global registers by its export", "global"),
    ("files", "a file bucket registers by its export", "files"),
    ("actions", "an action registers by its export", "action"),
    ("jobs", "a job registers by its export", "job"),
    ("hooks", "hooks are declared on the kind", ""),
];

/// The error for a removed registry key, located at the key. Its fix names the entry's own key,
/// so a port keeps every API and stored name: `productVariants: variants` becomes an export named
/// `productVariants`.
fn removed_key(ev: &Evaluator<'_>, program: &Program, config: &Value, key: &str) -> Option<Diag> {
    let &(_, what, builder) = REMOVED.iter().find(|(k, ..)| *k == key)?;
    let Value::Object(cfg) = config else { return None };
    let span = ev.key_span(config, key).map_or(
        Span {
            file: program.modules[program.entry].file,
            start: 0,
            end: 0,
        },
        |value| crate::diag::key_of(value, key),
    );
    let entries: Vec<(String, Value)> = match cfg.borrow().get(key) {
        Some(Value::Object(o)) => o.borrow().iter().map(|(k, v)| (k.clone(), v.clone())).collect(),
        _ => Vec::new(),
    };
    let d = Diag::new(span, format!("`{key}` is gone: {what}"));
    if key == "hooks" {
        let kind = entries.first().map_or("posts", |(k, _)| k.as_str());
        return Some(d.help(format!(
            "declare them where the kind is exported: `export const {kind} = collection({{ ... }}).on({{ beforeCreate(data) {{ ... }} }})`; handler functions may come from anywhere"
        )));
    }
    // An entry listed under another name than its binding's: the fix keeps the entry's key.
    let scope = ev.module_scope(program.entry);
    let bound = |value: &Value| -> Option<String> {
        let scope = scope.as_ref()?;
        let vars = scope.vars.borrow();
        let mut names: Vec<&String> = vars
            .iter()
            .filter(|(_, b)| same(&b.value, value))
            .map(|(n, _)| n)
            .collect();
        names.sort();
        names.first().map(|n| n.to_string())
    };
    if let Some((listed, local)) = entries
        .iter()
        .find_map(|(k, v)| bound(v).filter(|local| local != k).map(|local| (k, local)))
    {
        return Some(d.help(format!(
            "export it as `{listed}`, its name here: `export {{ {local} as {listed} }}` or `export const {listed} = {builder}(...)`, so its API name and stored name don't change"
        )));
    }
    let name = entries.first().map_or_else(
        || match key {
            "collections" => "posts",
            "tables" => "likes",
            "users" => "members",
            "globals" => "settings",
            "files" => "decks",
            "actions" => "like",
            _ => "digest",
        },
        |(k, _)| k.as_str(),
    );
    let example = match key {
        "actions" | "jobs" => format!("export const {name} = {builder}(...)"),
        _ => format!("export const {name} = {builder}({{ ... }})"),
    };
    let tail = match key {
        "files" => format!("; `file({name})` takes the export"),
        "actions" => format!("; the client call is `first.{name}()`"),
        _ => String::new(),
    };
    Some(d.help(format!(
        "write `{example}` in typedpress.config.ts or any file under tp/; the export name is its API name{tail}"
    )))
}

/// Whether `module` is app source: `typedpress.config.ts` or a module under `tp/`, not an installed
/// package (a plugin builds its own values, ruling 38).
fn app_source(program: &Program, file: u32) -> bool {
    let Some(module) = program.modules.iter().position(|m| m.file == file) else {
        return false;
    };
    let path = &program.modules[module].path;
    if module == program.entry {
        return true;
    }
    path.starts_with(program.root.join(crate::layout::ROOT))
        && !path.components().any(|c| c.as_os_str() == "node_modules")
}

/// What a built value is, for the error that asks to export it.
fn noun(value: &Value) -> &'static str {
    match value {
        Value::Collection(c) => match c.kind {
            StorageKind::Collection => "a collection",
            StorageKind::Table => "a table",
            StorageKind::UserProjection => "a user projection",
            StorageKind::Taxonomy => "a taxonomy",
        },
        Value::Global(_) => "a global",
        Value::FileBucket(_) => "a file bucket",
        Value::Action(_) => "an action",
        Value::Screen(_) => "an admin screen",
        _ => "a job",
    }
}

/// The builder that made a value, for the fix.
fn builder(value: &Value) -> &'static str {
    match value {
        Value::Collection(c) => c.kind.builder(),
        Value::Global(_) => "global",
        Value::FileBucket(_) => "files",
        Value::Action(_) => "action",
        Value::Screen(_) => "adminScreen",
        _ => "job",
    }
}

/// Evaluates every app module, then adds the exports of `typedpress.config.ts` and of every module under
/// `tp/` to `config`'s registries in place, so the spans the evaluator recorded still locate errors.
pub(super) fn register(program: &Program, ev: &mut Evaluator<'_>, config: &Value) -> Result<()> {
    let Value::Object(cfg) = config else {
        return ev.bind_db(&IndexMap::new());
    };
    if !ev.legacy() {
        for key in cfg.borrow().keys() {
            if let Some(d) = removed_key(ev, program, config, key) {
                return Err(d);
            }
            // `screens` is the registry exports fill, never a config option.
            if key == "screens" {
                let span = ev.key_span(config, key).map_or(
                    Span {
                        file: program.modules[program.entry].file,
                        start: 0,
                        end: 0,
                    },
                    |value| crate::diag::key_of(value, key),
                );
                return Err(Diag::new(span, "defineConfig() has no option `screens`: a screen registers by its export")
                    .help("write `export const pendingOrders = adminScreen({ ... })` in typedpress.config.ts or any file under tp/; the export name is its id"));
            }
        }
    }
    let mut taken: IndexMap<String, Taken> = IndexMap::new();
    let mut found: Vec<(&'static str, String, Value)> = Vec::new();
    // A 0.3.0 capture registered its config file's values through the config's keys only, so its
    // exports there register nothing new (ruling 41).
    let entry = (!ev.legacy()).then_some(program.entry);
    for module in entry.into_iter().chain(program.app_modules.iter().copied()) {
        let exports = ev.run_module(module).map_err(|d| d.phase(Code::Evaluation))?;
        for (name, value) in exports {
            let Some(registry) = registry(&value) else { continue };
            let span = span_of(&value);
            // The export name is the registered name; a default export has none.
            if name == "default" {
                return Err(Diag::new(span, "a kind, action or job can't be a default export")
                    .code(Code::DuplicateExport)
                    .help("export it by name, e.g. `export const notes = table({ ... })`; the name is its API"));
            }
            if let Some(other) = taken.get(&name) {
                // Re-exported from another module: the same value under the same name.
                if same(&other.value, &value) {
                    continue;
                }
                return Err(duplicate(&program.root, &name, other.span, span));
            }
            if let Some((other, _)) = taken.iter().find(|(_, t)| same(&t.value, &value)) {
                return Err(
                    Diag::new(span, format!("`{name}` and `{other}` export the same definition"))
                        .code(Code::DuplicateExport)
                        .help("export each kind, action or job under one name"),
                );
            }
            taken.insert(
                name.clone(),
                Taken {
                    value: value.clone(),
                    span,
                },
            );
            found.push((registry, name, value));
        }
    }
    // A value built in app source registers only by its export (ruling 37): one that no export
    // names would have no name, so it would silently not exist. A 0.3.0 capture registered values
    // by its config keys, and keeps that reading.
    if !ev.legacy() {
        for value in &ev.built {
            let span = span_of(value);
            if !app_source(program, span.file) || taken.values().any(|t| same(&t.value, value)) {
                continue;
            }
            let local = program
                .modules
                .iter()
                .position(|m| m.file == span.file)
                .and_then(|module| ev.module_scope(module))
                .and_then(|scope| {
                    let vars = scope.vars.borrow();
                    let mut names: Vec<String> = vars
                        .iter()
                        .filter(|(_, b)| same(&b.value, value))
                        .map(|(n, _)| n.clone())
                        .collect();
                    names.sort();
                    names.into_iter().next()
                });
            let (subject, name) = match &local {
                Some(local) => (format!("`{local}` is {}", noun(value)), local.as_str()),
                None => (format!("this is {}", noun(value)), "name"),
            };
            let args = if matches!(value, Value::Action(_) | Value::Job(_)) {
                "..."
            } else {
                "{ ... }"
            };
            return Err(Diag::new(span, format!("{subject} but isn't exported, so it has no name"))
                .code(Code::Unexported)
                .help(format!(
                    "write `export const {name} = {}({args})`; to share fields between kinds, share the fields object instead",
                    builder(value)
                )));
        }
    }
    for (registry, name, value) in found {
        let listed = cfg.borrow().get(registry).cloned();
        let entries = match listed {
            Some(entries @ Value::Object(_)) => entries,
            // No such registry yet (or an invalid one, which extraction reports): start one.
            Some(Value::Undef) | None => {
                let entries = Value::object(IndexMap::new());
                cfg.borrow_mut().insert(registry.to_string(), entries.clone());
                entries
            }
            Some(_) => continue,
        };
        let Value::Object(map) = &entries else { unreachable!() };
        // Listed in a 0.3.0 capture's config under any key already: one registration, the config's.
        if map.borrow().values().any(|v| same(v, &value)) {
            continue;
        }
        if let Some(listed) = map.borrow().get(&name) {
            return Err(duplicate(&program.root, &name, span_of(listed), span_of(&value)));
        }
        map.borrow_mut().insert(name, value);
    }
    // An export name is unique across the app, not per registry (docs/spec/spec.md §1.1): in a 0.3.0
    // capture it may not also name something its config lists, such as an action listed under an
    // exported table's name. Names only listed in such a config keep their per-registry
    // namespaces, as 0.3.0 read them (ruling 41).
    for (name, taken) in &taken {
        for registry in [
            "collections",
            "tables",
            "users",
            "taxonomies",
            "globals",
            "files",
            "actions",
            "jobs",
            "screens",
        ] {
            let Some(Value::Object(entries)) = cfg.borrow().get(registry).cloned() else {
                continue;
            };
            if let Some(listed) = entries.borrow().get(name)
                && !same(listed, &taken.value)
            {
                return Err(duplicate(&program.root, name, span_of(listed), taken.span));
            }
        }
    }
    // `"typedpress:db"` resolves to every registered kind, by its registry key.
    let mut kinds = IndexMap::new();
    for registry in ["collections", "tables", "users", "taxonomies"] {
        if let Some(Value::Object(entries)) = cfg.borrow().get(registry) {
            for (name, value) in entries.borrow().iter() {
                kinds.insert(name.clone(), value.clone());
            }
        }
    }
    ev.bind_db(&kinds)?;
    // `tp/wp-hooks/` modules declare no kinds, so they run once every kind is registered: their
    // `"typedpress:db"` imports bind as each module evaluates.
    for file in &program.wp_hooks {
        ev.run_module(file.module).map_err(|d| d.phase(Code::Evaluation))?;
    }
    Ok(())
}
