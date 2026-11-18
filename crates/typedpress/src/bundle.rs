//! Source-only publishing for hosts that own their compiler and WordPress platform.
//! No package installation, plugin downloads, user PHP, or subprocesses.

use crate::{
    diag::{Diag, Span},
    loader::{self, Fs},
    pipeline,
};
use serde::{Deserialize, Serialize};
use std::{
    cell::RefCell,
    collections::{BTreeMap, BTreeSet},
    path::{Path, PathBuf},
    rc::Rc,
};

pub const VERSION: &str = env!("CARGO_PKG_VERSION");
pub const APP_FILE: &str = "app.typedpress.json";
pub const VENDOR_FILE: &str = "vendor.typedpress.json";
pub const MANIFEST_FILE: &str = "manifest.json";

/// Original compiler inputs, not JavaScript bundler output. Paths retain module identity.
#[derive(Default, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SourceBundle {
    pub files: BTreeMap<String, String>,
    pub canonical: BTreeMap<String, String>,
}

#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Manifest {
    pub format: String,
    pub name: String,
    pub app_sha256: String,
    pub vendor_sha256: String,
}

fn hash(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    crate::hex(Sha256::digest(bytes))
}

fn failure(message: impl Into<String>) -> Diag {
    Diag::new(Span::NONE, message)
}
fn io(error: std::io::Error) -> Diag {
    failure(error.to_string())
}

pub(crate) fn relative(root: &Path, path: &Path) -> std::io::Result<String> {
    let mut normalized = PathBuf::new();
    for component in path.components() {
        match component {
            std::path::Component::CurDir => {}
            std::path::Component::ParentDir => {
                normalized.pop();
            }
            other => normalized.push(other.as_os_str()),
        }
    }
    let path = normalized
        .strip_prefix(root)
        .map_err(|_| std::io::Error::other("compiler input escapes project"))?;
    Ok(path.to_string_lossy().replace('\\', "/"))
}

struct Recording {
    root: PathBuf,
    files: RefCell<BTreeSet<String>>,
    canonical: RefCell<BTreeMap<String, String>>,
    fs: Rc<dyn Fs>,
    packages: bool,
}

impl Fs for Recording {
    fn allow_packages(&self) -> bool {
        self.packages
    }
    // How the sources are read is the wrapped tree's: a raw 0.3 project (`crate::zero_bundle`) is
    // captured with the reading its capture is compiled with.
    fn frozen_capture(&self) -> bool {
        self.fs.frozen_capture()
    }
    fn module_alias(&self) -> Option<&'static str> {
        self.fs.module_alias()
    }
    fn legacy_fields(&self) -> bool {
        self.fs.legacy_fields()
    }
    fn read_dir(&self, path: &Path) -> std::io::Result<Vec<PathBuf>> {
        self.fs.read_dir(path)
    }
    fn read_to_string(&self, path: &Path) -> std::io::Result<String> {
        let real = self.canonicalize(path)?;
        let text = self.fs.read_to_string(&real)?;
        self.files.borrow_mut().insert(relative(&self.root, path)?);
        self.files.borrow_mut().insert(relative(&self.root, &real)?);
        Ok(text)
    }
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf> {
        let real = self.fs.canonicalize(path)?;
        self.canonical
            .borrow_mut()
            .insert(relative(&self.root, path)?, relative(&self.root, &real)?);
        Ok(real)
    }
    fn is_file(&self, path: &Path) -> bool {
        self.canonicalize(path).is_ok_and(|p| self.fs.is_file(&p))
    }
    fn is_dir(&self, path: &Path) -> bool {
        self.canonicalize(path).is_ok_and(|p| self.fs.is_dir(&p))
    }
    fn exists(&self, path: &Path) -> bool {
        self.canonicalize(path).is_ok()
    }
}

/// The deterministic bundle bytes and relative public files to copy separately.
#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Capture {
    pub app: String,
    pub vendor: String,
    pub manifest: String,
    pub public_files: Vec<String>,
}

/// Capture exactly the files read by loading and compiling, through the caller's filesystem.
pub fn capture(fs: Rc<dyn Fs>, project: &Path, packages: bool) -> Result<Capture, Diag> {
    let project = fs.canonicalize(project).map_err(io)?;
    let recording = Rc::new(Recording {
        root: project.clone(),
        files: RefCell::new(BTreeSet::new()),
        canonical: RefCell::new(BTreeMap::new()),
        fs: fs.clone(),
        packages,
    });
    let program =
        loader::load_from(recording.clone(), &project.join("typedpress.config.ts")).map_err(|mut d| d.remove(0))?;
    let built = pipeline::build(&project, &program, || Ok(pipeline::Previous::none()), |_| None)?;
    if built.plugins.as_ref().is_some_and(|p| !p.plugins.is_empty()) {
        return Err(failure(
            "hosted apps cannot install WordPress plugins; the host provides the platform",
        ));
    }
    let mut app = SourceBundle::default();
    let mut vendor = SourceBundle::default();
    if fs.exists(&project.join(crate::pipeline::OLD_LEDGER)) {
        return Err(crate::pipeline::moved_ledger());
    }
    let migration_dir = project.join(crate::pipeline::LEDGER);
    if fs.is_dir(&migration_dir) {
        for path in fs.read_dir(&migration_dir).map_err(io)? {
            safe_migration_name(&path.file_name().unwrap_or_default().to_string_lossy())?;
            recording.read_to_string(&path).map_err(io)?;
        }
    }
    let mut sync_bytes = 0usize;
    for collection in built.schema.collections.values() {
        let Some(sync) = &collection.sync else { continue };
        for path in sync_files(&*fs, &project, sync)? {
            let text = recording.read_to_string(&path).map_err(io)?;
            sync_bytes += text.len();
            if sync_bytes > 8 * 1024 * 1024 {
                return Err(failure("synced files exceed the 8 MiB source bundle limit")
                    .code(crate::diag::Code::SyncFilesTooLarge));
            }
        }
    }
    for path in recording.files.borrow().iter() {
        safe_input(path)?;
        let bundle = if is_vendor(path) { &mut vendor } else { &mut app };
        bundle
            .files
            .insert(path.clone(), fs.read_to_string(&project.join(path)).map_err(io)?);
    }
    for (path, canonical) in recording.canonical.borrow().iter() {
        safe_input(path)?;
        safe_input(canonical)?;
        let bundle = if is_vendor(path) { &mut vendor } else { &mut app };
        bundle.canonical.insert(path.clone(), canonical.clone());
    }
    let app = serde_json::to_string(&app).map_err(|e| failure(e.to_string()))?;
    let vendor = serde_json::to_string(&vendor).map_err(|e| failure(e.to_string()))?;
    let manifest = manifest_for("typedpress.source-bundle.v1", &built.schema.name, &app, &vendor);
    let mut public_files = Vec::new();
    capture_assets(&*fs, &project.join("public"), &project, &mut public_files)?;
    public_files.sort();
    Ok(Capture {
        app,
        vendor,
        manifest,
        public_files,
    })
}

/// Sync globs are a single project-relative directory and extension, validated by extraction.
fn sync_files(fs: &dyn Fs, root: &Path, sync: &serde_json::Value) -> Result<Vec<PathBuf>, Diag> {
    let directory = root.join(sync["dir"].as_str().unwrap());
    if !fs.exists(&directory) {
        return Ok(Vec::new());
    }
    let mut paths = Vec::new();
    // `content/docs/**/*.md` walks the folders below too (`crate::sync::tree`), never a symlink.
    let mut pending = vec![directory];
    while let Some(dir) = pending.pop() {
        for path in fs.read_dir(&dir).map_err(io)? {
            if sync["nested"] == true && !fs.is_symlink(&path) && fs.is_dir(&path) {
                pending.push(path);
            } else if path.extension().and_then(|e| e.to_str()) == sync["format"].as_str() {
                paths.push(path);
            }
        }
    }
    paths.sort();
    for path in &paths {
        safe_input(&relative(root, path).map_err(io)?)?;
        safe_input(&relative(root, &fs.canonicalize(path).map_err(io)?).map_err(io)?)?;
        if !fs.is_file(path) {
            return Err(failure(format!("sync input must be a file: {}", path.display())));
        }
    }
    Ok(paths)
}

fn capture_assets(fs: &dyn Fs, from: &Path, root: &Path, files: &mut Vec<String>) -> Result<(), Diag> {
    if fs.is_symlink(from) {
        return Err(failure("public assets cannot contain symlinks or special files"));
    }
    if !fs.exists(from) {
        return Ok(());
    }
    if !fs.is_dir(from) {
        return Err(failure("public assets root must be a real directory"));
    }
    for path in fs.read_dir(from).map_err(io)? {
        safe_input(&path.file_name().unwrap_or_default().to_string_lossy())?;
        if fs.is_symlink(&path) {
            return Err(failure("public assets cannot contain symlinks or special files"));
        }
        if fs.is_dir(&path) {
            capture_assets(fs, &path, root, files)?;
        } else if fs.is_file(&path) {
            files.push(relative(root, &path).map_err(io)?);
        } else {
            return Err(failure("public assets cannot contain symlinks or special files"));
        }
    }
    Ok(())
}

/// Capture installed sources for the native CLI. The destination must be new.
#[cfg(feature = "host")]
pub fn sources(project: &Path, destination: &Path) -> Result<Manifest, Diag> {
    write_sources(project, destination, true)
}

#[cfg(feature = "host")]
pub(crate) fn write_sources(project: &Path, destination: &Path, packages: bool) -> Result<Manifest, Diag> {
    let captured = capture(Rc::new(loader::Disk), project, packages)?;
    write_capture(
        project,
        destination,
        [(APP_FILE, &captured.app), (VENDOR_FILE, &captured.vendor)],
        &captured.manifest,
    )
}

/// A capture's two bundles and manifest, and the project's `public/`, into a new directory.
#[cfg(feature = "host")]
pub(crate) fn write_capture(
    project: &Path,
    destination: &Path,
    bundles: [(&str, &String); 2],
    manifest: &str,
) -> Result<Manifest, Diag> {
    std::fs::create_dir(destination).map_err(io)?;
    for (name, text) in bundles {
        std::fs::write(destination.join(name), text).map_err(io)?;
    }
    std::fs::write(destination.join(MANIFEST_FILE), manifest).map_err(io)?;
    public_assets(&project.join("public"), Some(&destination.join("public")))?;
    serde_json::from_str(manifest).map_err(|e| failure(e.to_string()))
}

/// The manifest of a capture whose bundles are `app` and `vendor`.
pub(crate) fn manifest_for(format: &str, name: &str, app: &str, vendor: &str) -> String {
    serde_json::to_string(&Manifest {
        format: format.into(),
        name: name.into(),
        app_sha256: hash(app.as_bytes()),
        vendor_sha256: hash(vendor.as_bytes()),
    })
    .unwrap()
}

fn is_vendor(path: &str) -> bool {
    path.split('/').any(|part| part == "node_modules")
}

fn safe_input(path: &str) -> Result<(), Diag> {
    if path.is_empty()
        || path.contains('\\')
        || path.split('/').any(|s| s.is_empty() || s == "." || s == "..")
        || path.to_ascii_lowercase().ends_with(".php")
        || Path::new(path).has_root()
    {
        return Err(failure(format!("invalid hosted input: {path}")));
    }
    Ok(())
}

fn safe_migration_name(name: &str) -> Result<(), Diag> {
    safe_input(name)?;
    if name == "snapshot.json"
        || (name.len() > 9
            && name.as_bytes()[..4].iter().all(u8::is_ascii_digit)
            && name.as_bytes()[4] == b'_'
            && name.ends_with(".sql")
            && !name.contains('/'))
    {
        return Ok(());
    }
    Err(failure(format!("invalid hosted migration metadata: {name}")))
}

#[cfg(feature = "host")]
fn public_assets(from: &Path, to: Option<&Path>) -> Result<(), Diag> {
    match std::fs::symlink_metadata(from) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(error) => return Err(io(error)),
        Ok(metadata) if !metadata.is_dir() => return Err(failure("public assets root must be a real directory")),
        Ok(_) => {}
    }
    if let Some(to) = to {
        std::fs::create_dir_all(to).map_err(io)?;
    }
    for entry in std::fs::read_dir(from).map_err(io)? {
        let entry = entry.map_err(io)?;
        let name = entry.file_name();
        safe_input(&name.to_string_lossy())?;
        let kind = entry.file_type().map_err(io)?;
        if kind.is_dir() {
            public_assets(&entry.path(), to.map(|to| to.join(&name)).as_deref())?;
        } else if kind.is_file() {
            if let Some(to) = to {
                std::fs::copy(entry.path(), to.join(name)).map_err(io)?;
            }
        } else {
            return Err(failure("public assets cannot contain symlinks or special files"));
        }
    }
    Ok(())
}

#[cfg(feature = "host")]
struct CapturedFs {
    root: PathBuf,
    sources: SourceBundle,
    /// The name a pre-rename capture imports TypedPress by (`crate::zero_bundle`).
    module_alias: Option<&'static str>,
}
#[cfg(feature = "host")]
impl CapturedFs {
    fn key(&self, path: &Path) -> Option<String> {
        relative(&self.root, path).ok()
    }
}
#[cfg(feature = "host")]
impl Fs for CapturedFs {
    fn frozen_capture(&self) -> bool {
        true
    }
    fn module_alias(&self) -> Option<&'static str> {
        self.module_alias
    }
    fn legacy_fields(&self) -> bool {
        self.module_alias.is_some()
    }
    fn read_dir(&self, path: &Path) -> std::io::Result<Vec<PathBuf>> {
        let prefix = self
            .key(path)
            .ok_or_else(|| std::io::Error::other("input escapes source bundle"))?;
        let prefix = if prefix.is_empty() { prefix } else { prefix + "/" };
        let children: BTreeSet<_> = self
            .sources
            .files
            .keys()
            .chain(self.sources.canonical.keys())
            .filter_map(|p| p.strip_prefix(&prefix))
            .filter_map(|p| p.split('/').next())
            .map(|p| path.join(p))
            .collect();
        Ok(children.into_iter().collect())
    }

    fn read_to_string(&self, path: &Path) -> std::io::Result<String> {
        let real = self.canonicalize(path)?;
        self.sources
            .files
            .get(&self.key(&real).unwrap())
            .cloned()
            .ok_or_else(|| std::io::Error::new(std::io::ErrorKind::NotFound, "module not captured"))
    }
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf> {
        let key = self
            .key(path)
            .ok_or_else(|| std::io::Error::other("input escapes source bundle"))?;
        let canonical = self.sources.canonical.get(&key).unwrap_or(&key);
        if !self.sources.files.contains_key(canonical) && !self.is_dir(&self.root.join(canonical)) {
            return Err(std::io::Error::new(std::io::ErrorKind::NotFound, key));
        }
        Ok(self.root.join(canonical))
    }
    fn is_file(&self, path: &Path) -> bool {
        self.key(path).is_some_and(|p| self.sources.files.contains_key(&p))
    }
    fn is_dir(&self, path: &Path) -> bool {
        self.key(path)
            .is_some_and(|p| self.sources.files.keys().any(|f| f.starts_with(&(p.clone() + "/"))))
    }
    fn exists(&self, path: &Path) -> bool {
        self.is_file(path) || self.is_dir(path)
    }
}

/// Compile a captured source tree with a host-owned previous compiled tree as the migration baseline.
/// Executable output is compiler-generated. Uploaded migration metadata is archived separately.
/// The output directory must not exist yet.
#[cfg(feature = "host")]
pub fn compile(
    source: &Path,
    output: &Path,
    assets: &Path,
    previous: Option<&Path>,
) -> Result<serde_json::Value, Diag> {
    compile_tree(source, output, assets, previous, true).map(|compiled| compiled.manifest)
}

/// The app and vendor bundles of a captured source tree, each checked against its manifest hash on
/// the bytes as captured, with every path validated and module ownership (app or vendor) checked.
#[cfg(feature = "host")]
pub(crate) fn read_bundles(source: &Path, files: [(&str, &String); 2]) -> Result<SourceBundle, Diag> {
    let [(app, app_hash), (vendor, vendor_hash)] = files;
    check_bundles([
        (app, std::fs::read(source.join(app)).map_err(io)?, app_hash),
        (vendor, std::fs::read(source.join(vendor)).map_err(io)?, vendor_hash),
    ])
}

/// [`read_bundles`] on bytes already read: (file name, bytes, expected hash) for app, then vendor.
#[cfg(feature = "host")]
pub(crate) fn check_bundles(files: [(&str, Vec<u8>, &String); 2]) -> Result<SourceBundle, Diag> {
    let mut sources = SourceBundle::default();
    for ((file, bytes, expected), vendor) in files.into_iter().zip([false, true]) {
        if hash(&bytes) != *expected {
            return Err(failure(format!("source bundle hash mismatch: {file}")));
        }
        let bundle: SourceBundle = serde_json::from_slice(&bytes).map_err(|e| failure(e.to_string()))?;
        for path in bundle
            .files
            .keys()
            .chain(bundle.canonical.keys())
            .chain(bundle.canonical.values())
        {
            safe_input(path)?;
        }
        if bundle
            .files
            .keys()
            .chain(bundle.canonical.keys())
            .any(|p| is_vendor(p) != vendor)
        {
            return Err(failure("app and vendor module ownership mismatch"));
        }
        sources.files.extend(bundle.files);
        sources.canonical.extend(bundle.canonical);
    }
    Ok(sources)
}

/// What a build store records beside the compiled tree.
#[cfg(feature = "host")]
pub(crate) struct Compiled {
    pub manifest: serde_json::Value,
    pub name: String,
    /// The app has an auth collection, so it mounts `typedpress-identity`.
    pub identity: bool,
    pub admin: bool,
    /// `sealed: true`: the plugin slugs a host lets load, TypedPress's own and the platform's.
    pub sealed: Option<Vec<String>>,
    /// The names of the jobs the app declares, sorted.
    pub jobs: Vec<String>,
}

/// Each synced kind's files as seed records (`wp typedpress seed --records`), as a publish seeds a Space:
/// one `{collection, format, records}` document per kind, by kind name, parents before children.
#[cfg(feature = "host")]
pub(crate) fn seed_records(
    fs: &dyn Fs,
    root: &Path,
    schema: &crate::schema::Schema,
) -> Result<Vec<(String, serde_json::Value)>, Diag> {
    let mut seeds = Vec::new();
    for (name, collection) in &schema.collections {
        let Some(sync) = &collection.sync else { continue };
        let mut records = Vec::new();
        let mut uids = BTreeSet::new();
        let nested = sync["nested"] == true;
        let sync_dir = root.join(sync["dir"].as_str().unwrap_or("."));
        // A folder tree: (record index, the file holding its parent), resolved once every file is read.
        let mut parents: Vec<(usize, Option<String>)> = Vec::new();
        let mut by_path: BTreeMap<String, String> = BTreeMap::new();
        for path in sync_files(fs, root, sync)? {
            let text = fs.read_to_string(&path).map_err(io)?;
            let parsed = if sync["format"] == "md" {
                crate::sync::files::md_record(&text)
            } else {
                crate::sync::files::json_record(&text, collection.content.is_some())
            };
            let diagnostic = |line: usize, message: String| {
                let file = crate::diag::register_file(&path, &text);
                let start = text
                    .split_inclusive('\n')
                    .take(line.saturating_sub(1))
                    .map(str::len)
                    .sum::<usize>() as u32;
                Diag::new(
                    Span {
                        file,
                        start,
                        end: start,
                    },
                    message,
                )
            };
            let (mut fields, body) = parsed.map_err(|(line, message)| diagnostic(line, message))?;
            // Below the sync folder: what a folder-tree file is named by, and its stable identity.
            let below = relative(&sync_dir, &path).map_err(io)?;
            let place = if nested {
                let (slug, parent) = crate::sync::files::tree_place(&below, sync["format"].as_str().unwrap_or("md"))
                    .map_err(|message| diagnostic(1, message))?;
                let slug_col = collection.slug.clone().expect("a folder-tree sync has a slug");
                for (key, what) in [(slug_col.as_str(), "slug"), ("parent", "parent")] {
                    if fields.contains_key(key) {
                        return Err(diagnostic(
                            1,
                            format!("`{key}` comes from where the file is: its {what} is set by its path ({below})"),
                        ));
                    }
                }
                fields.insert(slug_col, serde_json::Value::String(slug));
                Some(parent)
            } else {
                None
            };
            if place.is_some() {
                // Slugs are unique in a kind, and a file without a uid is identified by its slug.
                let slug = fields[collection.slug.as_deref().unwrap_or("slug")]
                    .as_str()
                    .unwrap_or_default()
                    .to_string();
                if let Some((other, _)) = by_path.iter().find(|(p, _)| {
                    crate::sync::files::tree_place(p, sync["format"].as_str().unwrap_or("md"))
                        .is_ok_and(|(s, _)| s == slug)
                }) {
                    return Err(diagnostic(
                        1,
                        format!(
                            "{below} and {other} both have the slug `{slug}`; a kind's slugs are unique, so rename one"
                        ),
                    ));
                }
            }
            let uid = match fields.shift_remove("uid") {
                None if nested => crate::sync::uid::for_tree_file(
                    name,
                    fields[collection.slug.as_deref().unwrap_or("slug")]
                        .as_str()
                        .unwrap_or_default(),
                ),
                None => crate::sync::uid::for_file(name, &path.file_name().unwrap_or_default().to_string_lossy()),
                Some(serde_json::Value::String(uid)) if crate::sync::uid::valid(&uid) => uid,
                Some(_) => {
                    return Err(diagnostic(
                        1,
                        "uid must be zr_ followed by 20 lowercase hex characters".into(),
                    ));
                }
            };
            if !uids.insert(uid.clone()) {
                return Err(diagnostic(1, format!("duplicate sync uid {uid}")));
            }
            if let Some(parent) = place {
                parents.push((records.len(), parent));
                by_path.insert(below, uid.clone());
            }
            records.push(
                serde_json::json!({"uid":uid,"fields":fields,"body":body,"path":relative(root,&path).map_err(io)?}),
            );
        }
        if nested {
            // Each record's parent is its folder's index file, by uid; parents are seeded before
            // their children, so every reference names a row that already exists.
            let mut depth = vec![0usize; records.len()];
            for (index, parent) in &parents {
                if let Some(parent) = parent {
                    let Some(uid) = by_path.get(parent) else {
                        return Err(failure(format!(
                            "{}: its folder has no {}, the page the folder stands for",
                            records[*index]["path"].as_str().unwrap_or_default(),
                            parent
                        )));
                    };
                    records[*index]["fields"]["parent"] = serde_json::Value::String(uid.clone());
                    depth[*index] = parent.matches('/').count();
                }
            }
            let mut ordered: Vec<(usize, serde_json::Value)> = depth.into_iter().zip(records).collect();
            ordered.sort_by(|a, b| {
                a.0.cmp(&b.0)
                    .then_with(|| a.1["path"].as_str().cmp(&b.1["path"].as_str()))
            });
            records = ordered.into_iter().map(|(_, r)| r).collect();
        }
        seeds.push((
            name.clone(),
            serde_json::json!({"collection":name,"format":sync["format"],"records":records}),
        ));
    }
    Ok(seeds)
}

/// `compile`, optionally without the schema-independent runtime plugins (a build store keeps
/// those once per revision).
#[cfg(feature = "host")]
pub(crate) fn compile_tree(
    source: &Path,
    output: &Path,
    assets: &Path,
    previous: Option<&Path>,
    runtime: bool,
) -> Result<Compiled, Diag> {
    // A raw project from a client released before the rename is captured here first, exactly as
    // `host::sources` captures it, and read as that capture (`crate::zero_bundle::capture_raw`).
    let (bytes, raw) = if crate::zero_bundle::is_raw(source)? {
        let [app, vendor, manifest] = crate::zero_bundle::capture_raw(source)?;
        (manifest.into_bytes(), Some([app, vendor]))
    } else {
        (std::fs::read(source.join(MANIFEST_FILE)).map_err(io)?, None)
    };
    let manifest: Manifest = serde_json::from_slice(&bytes).map_err(|e| failure(e.to_string()))?;
    let legacy = manifest.format == crate::zero_bundle::FORMAT;
    if !(legacy || manifest.format == "typedpress.source-bundle.v1")
        || manifest.name.is_empty()
        || !manifest
            .name
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
    {
        return Err(failure("invalid source bundle format or app name"));
    }
    let sources = if legacy {
        crate::zero_bundle::read(source, &manifest, raw)?
    } else {
        let captured = read_bundles(
            source,
            [(APP_FILE, &manifest.app_sha256), (VENDOR_FILE, &manifest.vendor_sha256)],
        )?;
        // A bundle captured before `tp/_generated/` names its locks and ledger at their old paths.
        // The hashes cover the bytes as captured; only the paths are read on this layout.
        SourceBundle {
            files: captured
                .files
                .into_iter()
                .map(|(path, text)| (crate::layout::current(&path), text))
                .collect(),
            canonical: captured
                .canonical
                .into_iter()
                .map(|(path, real)| (crate::layout::current(&path), crate::layout::current(&real)))
                .collect(),
        }
    };
    // A capture from before `tp/app/` may hold TSX pages for the old renderer (`pages/`, with
    // their `tp/pages.json` artifact). There is no renderer for them any more, and compiling the
    // rest would publish the site without its pages.
    if let Some(path) = sources
        .files
        .keys()
        .find(|p| *p == "tp/pages.json" || p.starts_with("pages/"))
    {
        return Err(failure(format!(
            "this bundle has TSX pages from the old pages renderer ({path}), which TypedPress no longer has"
        ))
        .help("move the pages to tp/app/ (docs/pages.md), then publish again with `typedpress sources`"));
    }
    let name = manifest.name;
    let root = source.join("modules");
    let source_migrations = sources
        .files
        .iter()
        // A capture is frozen: one taken by an older compiler keeps its ledger at the old path, and
        // its archive must survive a recompile (docs/spacefast-deploy.md).
        .filter_map(|(path, text)| {
            [crate::pipeline::LEDGER, crate::pipeline::OLD_LEDGER]
                .iter()
                .find_map(|ledger| path.strip_prefix(ledger)?.strip_prefix('/'))
                .map(|name| (name, text))
        })
        .map(|(name, text)| {
            safe_migration_name(name)?;
            Ok((name.to_owned(), text.clone()))
        })
        .collect::<Result<Vec<_>, Diag>>()?;
    let fs = Rc::new(CapturedFs {
        root: root.clone(),
        sources,
        module_alias: legacy.then_some(crate::zero_bundle::MODULE),
    });
    let program = loader::load_from(fs.clone(), &root.join("typedpress.config.ts")).map_err(|mut d| d.remove(0))?;
    let snapshot = previous
        .map(|p| {
            std::fs::read(p.join("snapshot.json"))
                .map_err(io)
                .and_then(|b| serde_json::from_slice(&b).map_err(|e| failure(e.to_string())))
        })
        .transpose()?;
    let previous_migrations = previous.map(|p| p.join("migrations"));
    let names: Vec<String> = match &previous_migrations {
        Some(p) => std::fs::read_dir(p)
            .map_err(io)?
            .map(|e| e.map(|e| e.file_name().to_string_lossy().into_owned()))
            .collect::<Result<_, _>>()
            .map_err(io)?,
        None => Vec::new(),
    };
    let built = pipeline::build(
        Path::new(&name),
        &program,
        || pipeline::Previous::from_project(snapshot, names.iter().map(String::as_str)),
        crate::emit::runtime_file,
    )?;
    if built.plugins.as_ref().is_some_and(|p| !p.plugins.is_empty()) {
        return Err(failure("hosted apps cannot install WordPress plugins"));
    }
    let seeds = seed_records(&*fs, &root, &built.schema)?;
    public_assets(&assets.join("public"), None)?;
    std::fs::create_dir(output).map_err(io)?;
    if !source_migrations.is_empty() {
        let archive = output.join("source-migrations");
        std::fs::create_dir(&archive).map_err(io)?;
        for (name, text) in source_migrations {
            std::fs::write(archive.join(name), text).map_err(io)?;
        }
    }
    if !seeds.is_empty() {
        std::fs::create_dir(output.join("seed")).map_err(io)?;
        for (name, records) in seeds {
            std::fs::write(
                output.join("seed").join(format!("{name}.json")),
                serde_json::to_vec(&records).unwrap(),
            )
            .map_err(io)?;
        }
    }
    let migrations = output.join("migrations");
    std::fs::create_dir(&migrations).map_err(io)?;
    if let Some(previous) = previous_migrations {
        for name in &names {
            std::fs::copy(previous.join(name), migrations.join(name)).map_err(io)?;
        }
    }
    for (name, sql) in &built.migrations {
        std::fs::write(migrations.join(name), sql).map_err(io)?;
    }
    std::fs::write(
        output.join("snapshot.json"),
        serde_json::to_vec(&built.snapshot).map_err(|e| failure(e.to_string()))?,
    )
    .map_err(io)?;
    std::fs::write(output.join(crate::storage::FILE), crate::storage::bytes(&built.schema)).map_err(io)?;
    crate::emit::emit_generated(assets, &migrations, &output.join("plugins"), &built, runtime).map_err(io)?;
    let client = output.join("client");
    for (name, bytes) in &built.client_files {
        std::fs::create_dir_all(&client).map_err(io)?;
        std::fs::write(client.join(name), bytes).map_err(io)?;
    }
    let requirements: BTreeMap<_, _> = crate::platform::plugins(built.schema.locales.is_some())
        .into_iter()
        .map(|p| (p.slug, p.version))
        .collect();
    // The app's `tp/app/` routes and pages, so the host can tell which of its URLs outside
    // /wp-json to hand to WordPress without booting it to ask. Identity's /identity and the app
    // plugin's own files are not listed. Both lists use the route path grammar: a
    // leading `/`, no trailing slash except `/`, literal segments, `:name` for one segment and a
    // final `*name` for one or more. A host matching against them should:
    // - trim one trailing `/` from the request path first (pages canonicalize `/about/` to `/about`);
    // - treat HEAD as GET, as TypedPress\App::routeFor does;
    // - hand over any request whose path matches an entry, whatever its method, so a known path with
    //   the wrong method gets the runtime's 405 rather than the host's 404.
    // `pagePaths` are GET and HEAD only. The runtime still picks the most specific route.
    let mut app_routes: Vec<(String, String)> = Vec::new();
    let mut page_paths: Vec<String> = Vec::new();
    // `tp/app/` pages are GET paths; `route.ts` handlers and pages with handlers take any
    // method. A zero-or-more catch-all (`**rest`) is its folder plus `*rest`, in the host grammar.
    if let Some(app) = &built.schema.app {
        for route in &app.routes {
            let paths = match route.pattern.rsplit_once("/**") {
                Some((base, name)) => vec![
                    if base.is_empty() {
                        "/".to_string()
                    } else {
                        base.to_string()
                    },
                    format!("{base}/*{name}"),
                ],
                None => vec![route.pattern.clone()],
            };
            let file = route
                .handler
                .as_ref()
                .or(route.page.as_ref())
                .and_then(|f| app.files.get(f));
            for path in paths {
                if route.page.is_some() {
                    page_paths.push(path.clone());
                }
                for method in file
                    .map(|f| f.methods.keys().cloned().collect::<Vec<_>>())
                    .unwrap_or_default()
                {
                    app_routes.push((path.clone(), method));
                }
            }
        }
    }
    let mut routes: Vec<(&str, &str)> = app_routes.iter().map(|(p, m)| (p.as_str(), m.as_str())).collect();
    routes.sort();
    routes.dedup();
    let routes: Vec<_> = routes
        .iter()
        .map(|(path, method)| serde_json::json!({"method": method, "path": path}))
        .collect();
    page_paths.sort();
    page_paths.dedup();
    // What a host shows and routes without reading TypedPress's generated files (docs/host-contract.md).
    let schema = &built.schema;
    // Names as the REST API spells them (`/wp-json/tp/v1/<name>`).
    let mut tables: Vec<&str> = schema
        .collections
        .values()
        .filter(|c| !c.editor && !c.auth && c.taxonomy.is_none())
        .map(|c| c.table.as_str())
        .collect();
    tables.sort();
    let mut collections: Vec<&str> = schema.collections.keys().map(String::as_str).collect();
    collections.sort();
    let mut actions: Vec<&str> = schema.actions.keys().map(String::as_str).collect();
    actions.sort();
    let summary = serde_json::json!({"name": schema.name, "collections": collections, "tables": tables, "actions": actions,
        "identity": schema.collections.values().any(|c| c.auth), "admin": schema.admin});
    let mut manifest = serde_json::json!({"format":"typedpress.hosted.v1", "hostContract":crate::host_contract::HOST_CONTRACT, "compiler":VERSION, "app":built.schema.name,
        "wordpress":crate::platform::WORDPRESS, "php":crate::platform::PHP, "requires":requirements, "routes":routes, "pagePaths":page_paths,
        "summary":summary, "host":crate::host_contract::manifest_host(), "appSourceSha256":manifest.app_sha256, "vendorSourceSha256":manifest.vendor_sha256});
    let sealed = sealed_plugins(&built.schema, hosted_first(&built.schema));
    // No key when the app isn't sealed, so an unsealed app's tree hashes as it did before.
    if let Some(sealed) = &sealed {
        manifest["sealed"] = sealed.clone().into();
    }
    std::fs::write(
        output.join("manifest.json"),
        serde_json::to_vec_pretty(&manifest).unwrap(),
    )
    .map_err(io)?;
    Ok(Compiled {
        manifest,
        identity: built.schema.collections.values().any(|c| c.auth),
        admin: built.schema.admin,
        sealed,
        jobs: {
            let mut jobs: Vec<String> = built.schema.jobs.keys().cloned().collect();
            jobs.sort();
            jobs
        },
        name: built.schema.name,
    })
}

/// The TypedPress plugins a host mounts for this build (the build store loader's order).
#[cfg(feature = "host")]
fn hosted_first(schema: &crate::schema::Schema) -> Vec<String> {
    [
        "typedpress-runtime".to_string(),
        format!("typedpress-app-{}", schema.name),
    ]
    .into_iter()
    .chain(
        schema
            .collections
            .values()
            .any(|c| c.auth)
            .then(|| "typedpress-identity".to_string()),
    )
    .chain(schema.admin.then(|| "typedpress-admin".to_string()))
    .collect()
}

/// Plugins a sealed site never runs, declared or not: each serves stored content around TypedPress's
/// read rules. The Block Data API answers a post's raw `post_content` after an item-level check
/// only, which shows a rich text field that a field rule hides; the decoupled bundle ships that
/// API and WPGraphQL. Kept in step with `Sealed::DENIED` in the runtime.
pub(crate) const SEALED_DENIED: [&str; 2] = ["vip-block-data-api", "vip-decoupled-bundle"];

/// A sealed app's plugin allow-list (docs/sealed.md), by directory slug: `typedpress` (the TypedPress plugins
/// of this target, which its caller names), TypedPress's admin, the platform pins this app needs, and
/// the WordPress plugins the config declares. `None` when the app isn't sealed. Plugin packages (`use`) are compiled into the app
/// plugin, so they need no entry.
pub(crate) fn sealed_plugins(
    schema: &crate::schema::Schema,
    first: impl IntoIterator<Item = String>,
) -> Option<Vec<String>> {
    let declared = schema.sealed.as_ref()?;
    let mut slugs: Vec<String> = first
        .into_iter()
        // TypedPress's admin is TypedPress's own plugin: listed whether or not this build ships it
        // (`admin: true`), so a sealed site never refuses it however it is installed. So is the
        // TypedPress plugin (`typedpress`, runtime/typedpress-plugin), which a site deployed to with `typedpress push`
        // runs its builds through.
        .chain(["typedpress".to_string(), "typedpress-admin".to_string()])
        .chain(
            crate::platform::plugins(schema.locales.is_some())
                .into_iter()
                .map(|p| p.slug),
        )
        .chain(declared.iter().cloned())
        .collect();
    slugs.sort();
    slugs.dedup();
    Some(slugs)
}

#[cfg(feature = "cli")]
pub(crate) fn sources_cli(args: &[String]) -> std::process::ExitCode {
    let result = match args {
        [project, flag, output] if flag == "--out" => sources(Path::new(project), Path::new(output)).map(|_| ()),
        _ => Err(failure("usage: typedpress sources <project> --out <new-directory>")),
    };
    match result {
        Ok(()) => std::process::ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{}", crate::diag::render(&e, Path::new(".")));
            std::process::ExitCode::FAILURE
        }
    }
}

/// `typedpress compile-to-store <sources> <store> <assets> [previous-build]`: prints the build receipt.
#[cfg(feature = "cli")]
pub(crate) fn compile_to_store_cli(args: &[String]) -> std::process::ExitCode {
    let result = match args {
        [source, root, assets] => {
            crate::store::compile_to_store(Path::new(source), Path::new(root), Path::new(assets), None)
        }
        [source, root, assets, previous] => {
            crate::store::compile_to_store(Path::new(source), Path::new(root), Path::new(assets), Some(previous))
        }
        _ => Err(failure(
            "usage: typedpress compile-to-store <sources> <store> <assets> [previous-build]",
        )),
    };
    match result {
        Ok(build) => {
            println!(
                "{}",
                serde_json::json!({"build": build.build_id, "runtime": build.runtime_revision, "app": build.app_hash})
            );
            std::process::ExitCode::SUCCESS
        }
        Err(e) => {
            eprintln!("{}", crate::diag::render(&e, Path::new(".")));
            std::process::ExitCode::FAILURE
        }
    }
}

/// Native hosts pass private input/output directories, never author PHP.
#[cfg(feature = "cli")]
pub(crate) fn compile_cli(args: &[String]) -> std::process::ExitCode {
    let result = match args {
        [source, output, assets] => compile(Path::new(source), Path::new(output), Path::new(assets), None),
        [source, output, assets, previous] => compile(
            Path::new(source),
            Path::new(output),
            Path::new(assets),
            Some(Path::new(previous)),
        ),
        _ => Err(failure(
            "usage: typedpress compile-sources <sources> <new-output> <assets> [previous-output]",
        )),
    };
    match result {
        Ok(_) => std::process::ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{}", crate::diag::render(&e, Path::new(".")));
            std::process::ExitCode::FAILURE
        }
    }
}
