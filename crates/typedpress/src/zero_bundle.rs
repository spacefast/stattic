//! Source bundles captured before the rename to TypedPress: format `zero.source-bundle.v1`.
//!
//! Released clients that predate the rename (the `spacefast` CLI and `@spacefast/mcp` 0.9.0) still
//! upload what the 0.3.0 compiler captured: `app.zero.json` and `vendor.zero.json` beside the
//! manifest, project paths on the 0.3.0 layout (`zero.config.ts`, `zero/migrations/`, `wp-hooks/`),
//! and sources that import `"zero"`. A host compiles such an upload as the TypedPress bundle it stands
//! for: the hashes are checked on the bytes as captured, then the paths move onto the current layout
//! here and the loader reads `"zero"` as `"first"` (see [`MODULE`]). Nothing else in the compiler
//! knows the old names.
//!
//! Its sources were written against the 0.3.0 field forms, so they are evaluated with that reading
//! (`loader::Fs::legacy_fields`, `eval::legacy`; docs/decisions/2026-11-11-authoring-surface.md, ruling 21).
//!
//! One released client, the hosted MCP server 0.9.0, uploads the raw project instead of a capture:
//! `zero.config.ts` at the root, the relative modules it imports and `public/`. The host captures it
//! ([`write_raw`], called by `host::sources`) the way 0.3.0's host capture did: npm imports refused,
//! the 0.3.0 paths read on the current layout, `"zero"` read as `"first"` and the 0.3.0 field
//! reading on. What it writes is the `zero.source-bundle.v1` capture of that project, compiled by
//! the same [`read`] as any other (docs/decisions/2026-11-11-authoring-surface.md, ruling 24).
//!
//! Delete this module, its calls in `bundle::compile_tree` and `host::sources`, the `zero` arm of
//! `loader::Fs::renamed_module`, `Fs::legacy_fields` and `eval::legacy` once no released client
//! uploads this format and no host stores one.

use crate::{
    bundle::{self, Manifest, SourceBundle},
    diag::Diag,
    loader::Fs,
};
use std::{
    path::{Path, PathBuf},
    rc::Rc,
};

pub const FORMAT: &str = "zero.source-bundle.v1";
const APP_FILE: &str = "app.zero.json";
const VENDOR_FILE: &str = "vendor.zero.json";
/// The module name these sources import, read as `"first"`.
pub const MODULE: &str = "zero";

/// Old path → current path. A path under `zero/` that is not listed is refused: 0.3.0 captured
/// nothing else there, so an unknown one means the bundle is not what this module was written for.
const MOVED: &[(&str, &str)] = &[
    ("zero.config.ts", "typedpress.config.ts"),
    ("zero/migrations/", "tp/_generated/migrations/"),
    ("zero/plugins.lock.json", "tp/_generated/plugins.lock.json"),
    ("zero/abilities.json", "tp/_generated/abilities.json"),
    ("zero/wp-plugins.lock.json", "tp/_generated/wp-plugins.lock.json"),
    ("wp-hooks/", "tp/wp-hooks/"),
];

fn failure(message: impl Into<String>) -> Diag {
    Diag::new(crate::diag::Span::NONE, message.into())
}

/// The bundle's sources on the current layout, after checking both files against the manifest.
/// `raw` holds the bundles [`capture_raw`] made when `source` is a raw project; otherwise they are
/// read from `source`.
pub(crate) fn read(source: &Path, manifest: &Manifest, raw: Option<[String; 2]>) -> Result<SourceBundle, Diag> {
    for name in [bundle::APP_FILE, bundle::VENDOR_FILE] {
        if source.join(name).exists() {
            return Err(failure(format!(
                "a {FORMAT} bundle holds {APP_FILE} and {VENDOR_FILE}, but this one also has {name}"
            ))
            .help(
                "upload one capture: either the files `typedpress sources` writes, or the ones the 0.3.0 compiler wrote",
            ));
        }
    }
    let [app, vendor] = match raw {
        Some(bundles) => bundles.map(String::into_bytes),
        None => [
            std::fs::read(source.join(APP_FILE)).map_err(|e| failure(e.to_string()))?,
            std::fs::read(source.join(VENDOR_FILE)).map_err(|e| failure(e.to_string()))?,
        ],
    };
    let captured = bundle::check_bundles([
        (APP_FILE, app, &manifest.app_sha256),
        (VENDOR_FILE, vendor, &manifest.vendor_sha256),
    ])?;
    let mut sources = SourceBundle::default();
    for (path, text) in captured.files {
        sources.files.insert(current(&path)?, text);
    }
    for (path, real) in captured.canonical {
        sources.canonical.insert(current(&path)?, current(&real)?);
    }
    Ok(sources)
}

// 0.3.0 rendered TSX pages ahead of time (`pages/` and their `zero/pages.json` artifact). TypedPress has
// no such renderer: pages are `tp/app/` sources the compiler turns into PHP. A bundle or raw
// project that carries either is refused rather than compiled into a site with no pages.
fn pages_refusal() -> Diag {
    failure("this bundle has TSX pages (pages/) rendered by the 0.3.0 compiler, which TypedPress can't serve").help(
        "move each page to tp/app/<path>/page.tsx, then publish again with a client that captures TypedPress sources (`typedpress sources`)",
    )
}

fn current(path: &str) -> Result<String, Diag> {
    if path == "zero/pages.json" || path.starts_with("pages/") {
        return Err(pages_refusal());
    }
    for (old, new) in MOVED {
        if let Some(rest) = path.strip_prefix(old) {
            return Ok(format!("{new}{rest}"));
        }
    }
    if path.starts_with("zero/") || path.starts_with("tp/") || path == "typedpress.config.ts" {
        return Err(failure(format!("{path} has no place in a {FORMAT} bundle"))
            .help("publish again with a client that captures TypedPress sources (`typedpress sources`)"));
    }
    Ok(path.to_owned())
}

/// The capture files a host may find in an upload, besides the raw project.
const CAPTURES: &[&str] = &[
    ".zero-source",
    ".typedpress-source",
    ".spacepress-source",
    APP_FILE,
    VENDOR_FILE,
    bundle::APP_FILE,
    bundle::VENDOR_FILE,
    bundle::SPACEPRESS_APP_FILE,
    bundle::SPACEPRESS_VENDOR_FILE,
];

/// Whether `project` is a raw 0.3.0 project (`zero.config.ts` at its root) for [`write_raw`]. A tree
/// that also holds `spacepress.config.ts`, `typedpress.config.ts`, or a capture is refused: which one
/// the owner meant to publish is not the host's to guess.
pub(crate) fn is_raw(project: &Path) -> Result<bool, Diag> {
    if std::fs::symlink_metadata(project.join(OLD_CONFIG)).is_err() {
        return Ok(false);
    }
    for modern in [crate::layout::SPACEPRESS_CONFIG, crate::layout::CONFIG] {
        if std::fs::symlink_metadata(project.join(modern)).is_ok() {
            let label = if modern == crate::layout::SPACEPRESS_CONFIG {
                "SpacePress"
            } else {
                "TypedPress"
            };
            return Err(failure(format!(
                "this upload has both {OLD_CONFIG} (from before the rename to {label}) and {modern}"
            ))
            .help(format!(
                "keep one: delete {OLD_CONFIG} to publish the {label} project, or publish from a copy that holds only the 0.3 project"
            )));
        }
    }
    if let Some(capture) = CAPTURES
        .iter()
        .find(|name| std::fs::symlink_metadata(project.join(name)).is_ok())
    {
        return Err(failure(format!(
            "this upload has both a raw {OLD_CONFIG} project and a captured bundle ({capture})"
        ))
        .help("upload one of them: the project's sources, or the capture a client wrote from them"));
    }
    Ok(true)
}

const OLD_CONFIG: &str = "zero.config.ts";

/// A raw 0.3.0 project captured as the `zero.source-bundle.v1` the 0.3.0 host capture made of it:
/// the files loading and compiling read, on their 0.3.0 paths. Returns (app, vendor, manifest).
/// Only reached through [`is_raw`]: from `host::sources` ([`write_raw`]) and `bundle::compile_tree`.
pub(crate) fn capture_raw(project: &Path) -> Result<[String; 3], Diag> {
    let root = project.canonicalize().map_err(|e| failure(e.to_string()))?;
    let view = Rc::new(Raw { root: root.clone() });
    if view.is_dir(&root.join("pages")) || std::fs::symlink_metadata(root.join("zero/pages.json")).is_ok() {
        return Err(pages_refusal());
    }
    let captured = bundle::capture(view, &root, false)?;
    let name = serde_json::from_str::<Manifest>(&captured.manifest)
        .map_err(|e| failure(e.to_string()))?
        .name;
    let mut written = Vec::new();
    for text in [&captured.app, &captured.vendor] {
        let current: SourceBundle = serde_json::from_str(text).map_err(|e| failure(e.to_string()))?;
        // Paths that exist only on the current layout (the `tp/` and `tp/_generated/` folders
        // the moved files sit in) have no 0.3.0 name, and 0.3.0 recorded nothing for them.
        let mut old = SourceBundle::default();
        for (path, text) in current.files {
            old.files.insert(old_path(&path).ok_or_else(|| unplaced(&path))?, text);
        }
        for (path, real) in current.canonical {
            if let (Some(path), Some(real)) = (old_path(&path), old_path(&real)) {
                old.canonical.insert(path, real);
            }
        }
        written.push(serde_json::to_string(&old).map_err(|e| failure(e.to_string()))?);
    }
    let manifest = bundle::manifest_for(FORMAT, &name, &written[0], &written[1]);
    let vendor = written.pop().unwrap();
    let app = written.pop().unwrap();
    Ok([app, vendor, manifest])
}

/// [`capture_raw`] into `destination` (new), with `public/`, as `host::sources` writes any capture.
pub(crate) fn write_raw(project: &Path, destination: &Path) -> Result<Manifest, Diag> {
    let [app, vendor, manifest] = capture_raw(project)?;
    bundle::write_capture(
        project,
        destination,
        [(APP_FILE, &app), (VENDOR_FILE, &vendor)],
        &manifest,
    )
}

fn unplaced(path: &str) -> Diag {
    failure(format!("{path} has no place in a 0.3 project"))
}

/// Where a raw 0.3.0 project's file is seen on the current layout.
enum Place {
    /// At this path of the project on disk.
    Disk(String),
    /// A folder of the current layout the moved files sit in (`tp/`, `tp/_generated/`).
    Folder,
    /// Not part of what 0.3.0 read: an old name seen at its new place, or a path 0.3.0 never read.
    Hidden,
}

/// A path of the current layout → its 0.3.0 path ([`MOVED`] backwards). `None` for the folders
/// that only the current layout has.
fn old_path(path: &str) -> Option<String> {
    match place(path) {
        Place::Disk(old) => Some(old),
        Place::Folder | Place::Hidden => None,
    }
}

fn place(path: &str) -> Place {
    for (old, new) in MOVED {
        if let Some(rest) = path.strip_prefix(new) {
            return Place::Disk(format!("{old}{rest}"));
        }
        if new.ends_with('/') && path == new.trim_end_matches('/') {
            return Place::Disk(old.trim_end_matches('/').to_owned());
        }
    }
    if path == crate::layout::ROOT || path == crate::layout::GENERATED {
        return Place::Folder;
    }
    if new_path(path).as_deref() != Some(path) {
        return Place::Hidden;
    }
    Place::Disk(path.to_owned())
}

/// A 0.3.0 path → where the current layout sees it ([`MOVED`]); `None` for what it doesn't see: the
/// 0.3.0 `zero/` folder's other files and a `tp/` folder 0.3.0 never read.
fn new_path(old: &str) -> Option<String> {
    for (from, to) in MOVED {
        if let Some(rest) = old.strip_prefix(from) {
            return Some(format!("{to}{rest}"));
        }
        if from.ends_with('/') && old == from.trim_end_matches('/') {
            return Some(to.trim_end_matches('/').to_owned());
        }
    }
    let top = old.split('/').next().unwrap_or_default();
    if top == "zero" || top == crate::layout::ROOT || old == crate::layout::CONFIG {
        return None;
    }
    Some(old.to_owned())
}

/// A raw 0.3.0 project on disk, seen on the current layout and read the way its capture is: frozen,
/// `"zero"` as `"first"`, the 0.3.0 field reading, and no npm packages (a host has none installed).
struct Raw {
    root: PathBuf,
}

impl Raw {
    fn place(&self, path: &Path) -> Place {
        match bundle::relative(&self.root, path) {
            Ok(relative) => place(&relative),
            Err(_) => Place::Hidden,
        }
    }
    fn disk(&self, path: &Path) -> Option<PathBuf> {
        match self.place(path) {
            Place::Disk(old) => Some(self.root.join(old)),
            _ => None,
        }
    }
    /// What a current-layout folder holds directly: the place of each moved file that exists, and
    /// the folders on the way to it.
    fn folder(&self, path: &Path) -> Vec<PathBuf> {
        let Ok(folder) = bundle::relative(&self.root, path) else {
            return Vec::new();
        };
        let mut out = std::collections::BTreeSet::new();
        for (old, new) in MOVED {
            if std::fs::symlink_metadata(self.root.join(old.trim_end_matches('/'))).is_err() {
                continue;
            }
            let mut place = Path::new(new.trim_end_matches('/'));
            while let Some(parent) = place.parent() {
                if parent == Path::new(&folder) {
                    out.insert(self.root.join(place));
                }
                place = parent;
            }
        }
        out.into_iter().collect()
    }
}

impl Fs for Raw {
    fn allow_packages(&self) -> bool {
        false
    }
    fn frozen_capture(&self) -> bool {
        true
    }
    fn module_alias(&self) -> Option<&'static str> {
        Some(MODULE)
    }
    fn legacy_fields(&self) -> bool {
        true
    }
    fn read_dir(&self, path: &Path) -> std::io::Result<Vec<PathBuf>> {
        let mut entries = self.folder(path);
        if let Some(disk) = self.disk(path) {
            let folder = bundle::relative(&self.root, path)?;
            for entry in std::fs::read_dir(disk)? {
                let old = bundle::relative(&self.root, &entry?.path())?;
                if let Some(new) = new_path(&old)
                    && Path::new(&new).parent() == Some(Path::new(&folder))
                {
                    entries.push(self.root.join(new));
                }
            }
        }
        Ok(entries)
    }
    fn is_symlink(&self, path: &Path) -> bool {
        self.disk(path)
            .is_some_and(|p| std::fs::symlink_metadata(p).is_ok_and(|m| m.is_symlink()))
    }
    fn read_to_string(&self, path: &Path) -> std::io::Result<String> {
        match self.disk(path) {
            Some(disk) => std::fs::read_to_string(disk),
            None => Err(std::io::Error::new(std::io::ErrorKind::NotFound, "not in the project")),
        }
    }
    fn read(&self, path: &Path) -> std::io::Result<Vec<u8>> {
        match self.disk(path) {
            Some(disk) => std::fs::read(disk),
            None => Err(std::io::Error::new(std::io::ErrorKind::NotFound, "not in the project")),
        }
    }
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf> {
        let missing = || std::io::Error::new(std::io::ErrorKind::NotFound, "not in the project");
        match self.place(path) {
            Place::Folder if !self.folder(path).is_empty() => Ok(path.to_path_buf()),
            Place::Folder | Place::Hidden => Err(missing()),
            Place::Disk(old) => {
                let real = self.root.join(old).canonicalize()?;
                let real = bundle::relative(&self.root, &real)?;
                new_path(&real).map(|new| self.root.join(new)).ok_or_else(missing)
            }
        }
    }
    fn is_file(&self, path: &Path) -> bool {
        self.disk(path).is_some_and(|p| p.is_file())
    }
    fn is_dir(&self, path: &Path) -> bool {
        match self.place(path) {
            Place::Folder => !self.folder(path).is_empty(),
            Place::Disk(old) => self.root.join(old).is_dir(),
            Place::Hidden => false,
        }
    }
    fn exists(&self, path: &Path) -> bool {
        match self.place(path) {
            Place::Folder => !self.folder(path).is_empty(),
            Place::Disk(old) => std::fs::symlink_metadata(self.root.join(old)).is_ok(),
            Place::Hidden => false,
        }
    }
}
