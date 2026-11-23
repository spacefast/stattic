//! Where a project's files live (docs/spec/spec.md §1). Paths are relative to the directory that
//! holds `typedpress.config.ts`; every reader and writer takes them from here.
//!
//! - `tp/_generated/` is written by TypedPress and committed: the migration ledger, the type
//!   declarations, the typed client and the locks.
//! - `tp/dist/` is written by `typedpress build` and ignored by git: the deployable output.

/// The SpacePress config at the project root (preferred).
pub const SPACEPRESS_CONFIG: &str = "spacepress.config.ts";
/// Legacy config filename supported for existing workspaces.
pub const CONFIG: &str = "typedpress.config.ts";

/// Resolve the project's config entry file: prefers `spacepress.config.ts`, else `typedpress.config.ts`.
pub fn project_config(exists: impl Fn(&std::path::Path) -> bool, project: &std::path::Path) -> std::path::PathBuf {
    let sp = project.join(SPACEPRESS_CONFIG);
    if exists(&sp) {
        return sp;
    }
    project.join(CONFIG)
}
/// The TypedPress root.
pub const ROOT: &str = "tp";
/// Written by TypedPress, committed.
pub const GENERATED: &str = "tp/_generated";
/// The migration ledger lives at [`crate::pipeline::LEDGER`], beside these.
/// The type declarations that register the app's schema with `"typedpress"`.
pub const DB_TYPES: &str = "tp/_generated/db.d.ts";
/// Typed links and params for `tp/app/` routes.
pub const ROUTES_TYPES: &str = "tp/_generated/routes.d.ts";
/// The typed client.
pub const CLIENT: &str = "tp/_generated/client";
/// The app's HTTP API as an OpenAPI 3.1 document.
pub const OPENAPI: &str = "tp/_generated/openapi.json";
/// The pinned WordPress plugin dependencies `typedpress build` installs.
pub const WP_PLUGINS_LOCK: &str = "tp/_generated/wp-plugins.lock.json";
/// Written by `typedpress build`, ignored by git.
pub const DIST: &str = "tp/dist";
/// `typedpress build`'s default output: a `wp-content` directory.
pub const WP_CONTENT: &str = "tp/dist/wp-content";

/// Committed files a live checkout may still have at their place before `tp/_generated/`: (old,
/// new, what goes wrong if it is left there). Refuse these paths before loading sources so stale generated files cannot be used.
const OLD_IN_CHECKOUT: &[(&str, &str, &str)] = &[
    (
        "tp/plugins.lock.json",
        "tp/_generated/plugins.lock.json",
        "its grants would be ignored and a new lock written that grants everything the plugins use",
    ),
    (
        "tp/wp-plugins.lock.json",
        "tp/_generated/wp-plugins.lock.json",
        "the plugins it pins would be resolved again",
    ),
    (
        "tp/abilities.json",
        "tp/_generated/abilities.json",
        "the abilities it locks would be unknown",
    ),
    (
        "tp/client",
        "tp/_generated/client",
        "its files would load as app code from tp/",
    ),
    (
        "typedpress.gen.ts",
        "tp/_generated/db.d.ts",
        "editors would read the old registration",
    ),
];

/// Every committed file a checkout still keeps at its place from before `tp/_generated/`, in one
/// (message, help): the message names each file and what goes wrong if it stays, and the help is
/// one command that moves them all. Callers refuse to build: see [`OLD_IN_CHECKOUT`].
pub fn old_in_checkout(
    exists: impl Fn(&std::path::Path) -> bool,
    project: &std::path::Path,
) -> Option<(String, String)> {
    let found: Vec<_> = OLD_IN_CHECKOUT
        .iter()
        .filter(|(old, ..)| exists(&project.join(old)))
        .collect();
    if found.is_empty() {
        return None;
    }
    let lines: Vec<String> = found
        .iter()
        .map(|(old, new, why)| format!("{old} moved to {new}; left where it is, {why}"))
        .collect();
    let message = match lines.as_slice() {
        [one] => one.clone(),
        many => format!(
            "{} files are from before tp/_generated/:\n  - {}",
            many.len(),
            many.join("\n  - ")
        ),
    };
    let mut parents: Vec<String> = found
        .iter()
        .map(|(_, new, _)| {
            std::path::Path::new(new)
                .parent()
                .expect("under tp/_generated")
                .display()
                .to_string()
        })
        .collect();
    parents.dedup();
    let moves: Vec<String> = found
        .iter()
        .map(|(old, new, _)| format!("git mv {old} {new}"))
        .collect();
    let help = format!(
        "move {} with `mkdir -p {} && {}`, then build again",
        if found.len() == 1 { "it" } else { "them" },
        parents.join(" "),
        moves.join(" && ")
    );
    Some((message, help))
}
