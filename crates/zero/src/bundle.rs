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
pub const APP_FILE: &str = "app.zero.json";
pub const VENDOR_FILE: &str = "vendor.zero.json";
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
    Sha256::digest(bytes).iter().map(|b| format!("{b:02x}")).collect()
}

fn failure(message: impl Into<String>) -> Diag {
    Diag::new(Span::NONE, message)
}
fn io(error: std::io::Error) -> Diag {
    failure(error.to_string())
}

fn relative(root: &Path, path: &Path) -> std::io::Result<String> {
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
    let program = loader::load_from(recording.clone(), &project.join("zero.config.ts")).map_err(|mut d| d.remove(0))?;
    let built = pipeline::build(&project, &program, || Ok(pipeline::Previous::none()), |_| None)?;
    if built.plugins.as_ref().is_some_and(|p| !p.plugins.is_empty()) {
        return Err(failure(
            "hosted apps cannot install WordPress plugins; the host provides the platform",
        ));
    }
    // TSX pages travel as their render artifact plus the inputs it was rendered from.
    crate::pages::check(&*recording, &project, &built)?;
    let mut app = SourceBundle::default();
    let mut vendor = SourceBundle::default();
    let migration_dir = project.join("zero/migrations");
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
    let manifest = Manifest {
        format: "zero.source-bundle.v1".into(),
        name: built.schema.name.clone(),
        app_sha256: hash(app.as_bytes()),
        vendor_sha256: hash(vendor.as_bytes()),
    };
    let mut public_files = Vec::new();
    capture_assets(&*fs, &project.join("public"), &project, &mut public_files)?;
    public_files.sort();
    Ok(Capture {
        app,
        vendor,
        manifest: serde_json::to_string(&manifest).unwrap(),
        public_files,
    })
}

/// Sync globs are a single project-relative directory and extension, validated by extraction.
fn sync_files(fs: &dyn Fs, root: &Path, sync: &serde_json::Value) -> Result<Vec<PathBuf>, Diag> {
    let directory = root.join(sync["dir"].as_str().unwrap());
    if !fs.exists(&directory) {
        return Ok(Vec::new());
    }
    let mut paths = fs.read_dir(&directory).map_err(io)?;
    paths.retain(|p| p.extension().and_then(|e| e.to_str()) == sync["format"].as_str());
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
    std::fs::create_dir(destination).map_err(io)?;
    std::fs::write(destination.join(APP_FILE), &captured.app).map_err(io)?;
    std::fs::write(destination.join(VENDOR_FILE), &captured.vendor).map_err(io)?;
    std::fs::write(destination.join(MANIFEST_FILE), &captured.manifest).map_err(io)?;
    public_assets(&project.join("public"), Some(&destination.join("public")))?;
    serde_json::from_str(&captured.manifest).map_err(|e| failure(e.to_string()))
}

fn is_vendor(path: &str) -> bool {
    path.split('/').any(|part| part == "node_modules")
}

pub(crate) fn check_input(path: &str) -> Result<(), Diag> {
    safe_input(path)
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
}
#[cfg(feature = "host")]
impl CapturedFs {
    fn key(&self, path: &Path) -> Option<String> {
        relative(&self.root, path).ok()
    }
}
#[cfg(feature = "host")]
impl Fs for CapturedFs {
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

/// What a build store records beside the compiled tree.
#[cfg(feature = "host")]
pub(crate) struct Compiled {
    pub manifest: serde_json::Value,
    pub name: String,
    /// The app has an auth collection, so it mounts `zero-identity`.
    pub identity: bool,
    pub admin: bool,
    pub pages: Option<String>,
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
    let bytes = std::fs::read(source.join(MANIFEST_FILE)).map_err(io)?;
    let manifest: Manifest = serde_json::from_slice(&bytes).map_err(|e| failure(e.to_string()))?;
    if manifest.format != "zero.source-bundle.v1"
        || manifest.name.is_empty()
        || !manifest
            .name
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
    {
        return Err(failure("invalid source bundle format or app name"));
    }
    let mut sources = SourceBundle::default();
    for (file, expected, vendor) in [
        (APP_FILE, &manifest.app_sha256, false),
        (VENDOR_FILE, &manifest.vendor_sha256, true),
    ] {
        let bytes = std::fs::read(source.join(file)).map_err(io)?;
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
    let name = manifest.name;
    let root = source.join("modules");
    let source_migrations = sources
        .files
        .iter()
        .filter_map(|(path, text)| path.strip_prefix("zero/migrations/").map(|name| (name, text)))
        .map(|(name, text)| {
            safe_migration_name(name)?;
            Ok((name.to_owned(), text.clone()))
        })
        .collect::<Result<Vec<_>, Diag>>()?;
    let fs = Rc::new(CapturedFs {
        root: root.clone(),
        sources,
    });
    let program = loader::load_from(fs.clone(), &root.join("zero.config.ts")).map_err(|mut d| d.remove(0))?;
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
    let pages = crate::pages::check(&*fs, &root, &built)?;
    let mut seeds = Vec::new();
    for (name, collection) in &built.schema.collections {
        let Some(sync) = &collection.sync else { continue };
        let mut records = Vec::new();
        let mut uids = BTreeSet::new();
        for path in sync_files(&*fs, &root, sync)? {
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
            let uid = match fields.shift_remove("uid") {
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
            records.push(
                serde_json::json!({"uid":uid,"fields":fields,"body":body,"path":relative(&root,&path).map_err(io)?}),
            );
        }
        seeds.push((
            name,
            serde_json::json!({"collection":name,"format":sync["format"],"records":records}),
        ));
    }
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
    crate::emit::emit_generated(assets, &migrations, &output.join("plugins"), &built, runtime).map_err(io)?;
    if let Some(pages) = &pages {
        crate::pages::emit(&output.join("plugins"), pages).map_err(io)?;
    }
    let client = output.join("client");
    for (name, bytes) in &built.client_files {
        std::fs::create_dir_all(&client).map_err(io)?;
        std::fs::write(client.join(name), bytes).map_err(io)?;
    }
    let requirements: BTreeMap<_, _> = crate::platform::plugins(built.schema.locales.is_some())
        .into_iter()
        .map(|p| (p.slug, p.version))
        .collect();
    let manifest = serde_json::json!({"format":"zero.hosted.v1", "compiler":VERSION, "app":built.schema.name,
        "wordpress":crate::platform::WORDPRESS, "php":crate::platform::PHP, "requires":requirements, "pages":pages.as_ref().map(|p| format!("zero-pages-{}", p.app)), "appSourceSha256":manifest.app_sha256, "vendorSourceSha256":manifest.vendor_sha256});
    std::fs::write(
        output.join("manifest.json"),
        serde_json::to_vec_pretty(&manifest).unwrap(),
    )
    .map_err(io)?;
    Ok(Compiled {
        manifest,
        identity: built.schema.collections.values().any(|c| c.auth),
        admin: built.schema.admin,
        pages: pages.as_ref().map(|p| format!("zero-pages-{}", p.app)),
        name: built.schema.name,
    })
}

#[cfg(feature = "cli")]
pub(crate) fn sources_cli(args: &[String]) -> std::process::ExitCode {
    let result = match args {
        [project, flag, output] if flag == "--out" => sources(Path::new(project), Path::new(output)).map(|_| ()),
        _ => Err(failure("usage: zero sources <project> --out <new-directory>")),
    };
    match result {
        Ok(()) => std::process::ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{}", crate::diag::render(&e, Path::new(".")));
            std::process::ExitCode::FAILURE
        }
    }
}

/// `zero compile-to-store <sources> <store> <assets> [previous-build]`: prints the build receipt.
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
            "usage: zero compile-to-store <sources> <store> <assets> [previous-build]",
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
            "usage: zero compile-sources <sources> <new-output> <assets> [previous-output]",
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
