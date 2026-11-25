//! Where a project's files live (docs/spec/spec.md §1). Paths are relative to the directory that
//! holds `spacepress.config.ts`; every reader and writer takes them from here.
//!
//! - `sp/_generated/` is written by SpacePress and committed: the migration ledger, the type
//!   declarations, the typed client and the locks.
//! - `sp/dist/` is written by `spacepress build` and ignored by git: the deployable output.

/// The config at the project root: the entry every module is reached from.
pub const CONFIG: &str = "spacepress.config.ts";
/// The SpacePress root.
pub const ROOT: &str = "sp";
/// Written by SpacePress, committed.
pub const GENERATED: &str = "sp/_generated";
/// The migration ledger lives at [`crate::pipeline::LEDGER`], beside these.
/// The type declarations that register the app's schema with `"spacepress"`.
pub const DB_TYPES: &str = "sp/_generated/db.d.ts";
/// Typed links and params for `sp/app/` routes.
pub const ROUTES_TYPES: &str = "sp/_generated/routes.d.ts";
/// The typed client.
pub const CLIENT: &str = "sp/_generated/client";
/// The app's HTTP API as an OpenAPI 3.1 document.
pub const OPENAPI: &str = "sp/_generated/openapi.json";
/// The pinned WordPress plugin dependencies `spacepress build` installs.
pub const WP_PLUGINS_LOCK: &str = "sp/_generated/wp-plugins.lock.json";
/// Written by `spacepress build`, ignored by git.
pub const DIST: &str = "sp/dist";
/// `spacepress build`'s default output: a `wp-content` directory.
pub const WP_CONTENT: &str = "sp/dist/wp-content";

/// Committed files a live checkout may still have at their place before `sp/_generated/`: (old,
/// new, what goes wrong if it is left there). Refuse these paths before loading sources so stale generated files cannot be used.
const OLD_IN_CHECKOUT: &[(&str, &str, &str)] = &[
    (
        "sp/plugins.lock.json",
        "sp/_generated/plugins.lock.json",
        "its grants would be ignored and a new lock written that grants everything the plugins use",
    ),
    (
        "sp/wp-plugins.lock.json",
        "sp/_generated/wp-plugins.lock.json",
        "the plugins it pins would be resolved again",
    ),
    (
        "sp/abilities.json",
        "sp/_generated/abilities.json",
        "the abilities it locks would be unknown",
    ),
    (
        "sp/client",
        "sp/_generated/client",
        "its files would load as app code from sp/",
    ),
    (
        "spacepress.gen.ts",
        "sp/_generated/db.d.ts",
        "editors would read the old registration",
    ),
];

/// Every committed file a checkout still keeps at its place from before `sp/_generated/`, in one
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
            "{} files are from before sp/_generated/:\n  - {}",
            many.len(),
            many.join("\n  - ")
        ),
    };
    let mut parents: Vec<String> = found
        .iter()
        .map(|(_, new, _)| {
            std::path::Path::new(new)
                .parent()
                .expect("under sp/_generated")
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
