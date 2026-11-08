//! The build store: immutable, content-addressed compiled builds on disk.
//!
//! ```text
//! <root>/runtime/<revision>/   zero-loader.php, zero-runtime/, zero-identity/, zero-admin/
//! <root>/app/<hash>/           the app tree compile_sources writes, minus the runtime plugins
//! <root>/build/<id>.json       BuildManifest; <id> is the SHA-256 of these bytes
//! <root>/ready/<id>.json       written by the loader once the build initialized on this site
//! ```
//!
//! Everything is written below `<root>/.tmp/` and renamed into place, then never edited, so file
//! paths stay valid for the opcode cache. Writers and the collector hold `flock(<root>/.lock)`,
//! the same lock `Zero\Loader\Store` takes in PHP.
use crate::{
    bundle,
    diag::{Diag, Span},
};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeSet,
    fs,
    path::{Path, PathBuf},
};

pub const FORMAT: &str = "zero.build.v1";

/// One build: which runtime tree and which app tree a request mounts.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BuildManifest {
    pub format: String,
    /// The app name (`zero-app-<name>`).
    pub name: String,
    /// The compiler's Cargo version.
    pub compiler: String,
    /// `runtime/<revision>/`
    pub runtime: String,
    /// `app/<hash>/`
    pub app: String,
    /// Mount `zero-identity` (the app has an auth collection).
    pub identity: bool,
    /// Mount `zero-admin`.
    pub admin: bool,
    /// `zero-pages-<name>` when the app has pages.
    pub pages: Option<String>,
    /// The app's schema snapshot, relative to the store root.
    pub snapshot: String,
    /// The generated client directory, relative to the store root, when the app has one.
    pub client: Option<String>,
}

#[derive(Clone, Debug)]
pub struct StoredBuild {
    pub build_id: String,
    pub runtime_revision: String,
    pub app_hash: String,
    pub manifest: BuildManifest,
}

fn failure(message: impl Into<String>) -> Diag {
    Diag::new(Span::NONE, message)
}

fn io(context: &str) -> impl Fn(std::io::Error) -> Diag + '_ {
    move |error| failure(format!("build store: {context}: {error}"))
}

fn hex(digest: impl AsRef<[u8]>) -> String {
    digest.as_ref().iter().map(|b| format!("{b:02x}")).collect()
}

/// Build IDs, revisions and app hashes are lowercase SHA-256 hex.
pub fn valid_id(id: &str) -> bool {
    id.len() == 64 && id.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// Paths and bytes, in path order: the same digest for the same tree wherever it is written.
fn digest<'a>(files: impl IntoIterator<Item = (&'a str, &'a [u8])>) -> String {
    let mut sorted: Vec<_> = files.into_iter().collect();
    sorted.sort_by(|a, b| a.0.cmp(b.0));
    let mut hash = Sha256::new();
    for (path, bytes) in sorted {
        hash.update(path.as_bytes());
        hash.update([0]);
        hash.update((bytes.len() as u64).to_be_bytes());
        hash.update(bytes);
    }
    hex(hash.finalize())
}

fn tree_files(root: &Path) -> std::io::Result<Vec<(String, Vec<u8>)>> {
    fn walk(root: &Path, dir: &Path, out: &mut Vec<(String, Vec<u8>)>) -> std::io::Result<()> {
        for entry in fs::read_dir(dir)? {
            let entry = entry?;
            let kind = entry.file_type()?;
            let path = entry.path();
            if kind.is_dir() {
                walk(root, &path, out)?;
            } else if kind.is_file() {
                let rel = path
                    .strip_prefix(root)
                    .expect("below root")
                    .to_string_lossy()
                    .replace('\\', "/");
                out.push((rel, fs::read(&path)?));
            } else {
                return Err(std::io::Error::other(format!("{} is not a file", path.display())));
            }
        }
        Ok(())
    }
    let mut out = Vec::new();
    walk(root, root, &mut out)?;
    Ok(out)
}

fn tree_digest(root: &Path) -> std::io::Result<String> {
    let files = tree_files(root)?;
    Ok(digest(files.iter().map(|(p, b)| (p.as_str(), b.as_slice()))))
}

/// The revision of the runtime this compiler embeds.
pub fn runtime_revision() -> String {
    let tree = crate::emit::runtime_tree();
    digest(tree.iter().map(|(p, b)| (p.as_str(), b.as_ref())))
}

struct Store {
    root: PathBuf,
    _lock: fs::File,
}

impl Store {
    fn open(root: &Path) -> Result<Self, Diag> {
        for dir in ["runtime", "app", "build", "ready", ".tmp"] {
            fs::create_dir_all(root.join(dir)).map_err(io("create"))?;
        }
        let lock = fs::OpenOptions::new()
            .create(true)
            .truncate(false)
            .write(true)
            .open(root.join(".lock"))
            .map_err(io("open lock"))?;
        lock.lock().map_err(io("lock"))?;
        Ok(Self {
            root: root.to_path_buf(),
            _lock: lock,
        })
    }

    /// A fresh private directory name below `.tmp/`.
    fn staging(&self, purpose: &str) -> PathBuf {
        use std::sync::atomic::{AtomicU64, Ordering};
        static COUNTER: AtomicU64 = AtomicU64::new(0);
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or_default();
        self.root.join(".tmp").join(format!(
            "{purpose}-{}-{nanos}-{}",
            std::process::id(),
            COUNTER.fetch_add(1, Ordering::Relaxed)
        ))
    }

    /// Rename `staged` to `target` unless `target` exists; a staged duplicate is removed.
    fn publish(&self, staged: &Path, target: &Path) -> Result<(), Diag> {
        if target.exists() {
            remove(staged).map_err(io("remove duplicate"))?;
        } else {
            fs::rename(staged, target).map_err(io("publish"))?;
        }
        Ok(())
    }

    fn manifest(&self, id: &str) -> Result<BuildManifest, Diag> {
        if !valid_id(id) {
            return Err(failure(format!("build store: invalid build id {id:?}")));
        }
        let bytes = fs::read(self.root.join("build").join(format!("{id}.json")))
            .map_err(|e| failure(format!("build store: unknown build {id}: {e}")))?;
        if hex(Sha256::digest(&bytes)) != id {
            return Err(failure(format!("build store: build {id} does not match its id")));
        }
        serde_json::from_slice(&bytes).map_err(|e| failure(format!("build store: build {id}: {e}")))
    }

    fn ids(&self, dir: &str) -> Result<Vec<String>, Diag> {
        let mut ids = Vec::new();
        for entry in fs::read_dir(self.root.join(dir)).map_err(io("list"))? {
            let name = entry.map_err(io("list"))?.file_name().to_string_lossy().into_owned();
            let id = name.strip_suffix(".json").unwrap_or(&name);
            if valid_id(id) {
                ids.push(name);
            }
        }
        ids.sort();
        Ok(ids)
    }
}

fn remove(path: &Path) -> std::io::Result<()> {
    if fs::symlink_metadata(path)?.is_dir() {
        fs::remove_dir_all(path)
    } else {
        fs::remove_file(path)
    }
}

fn write_runtime(store: &Store) -> Result<String, Diag> {
    let tree = crate::emit::runtime_tree();
    let revision = digest(tree.iter().map(|(p, b)| (p.as_str(), b.as_ref())));
    let target = store.root.join("runtime").join(&revision);
    if target.is_dir() {
        return Ok(revision);
    }
    let staged = store.staging("runtime");
    for (rel, bytes) in &tree {
        crate::emit::write(&staged.join(rel), bytes).map_err(io("write runtime"))?;
    }
    store.publish(&staged, &target)?;
    Ok(revision)
}

/// Compile captured sources into the store at `root` and record one build.
///
/// `previous` is the build ID whose schema snapshot and migrations are the baseline. The runtime
/// tree is written only when its revision is new, and the app tree only when its hash is new.
pub fn compile_to_store(
    sources: &Path,
    root: &Path,
    assets: &Path,
    previous: Option<&str>,
) -> Result<StoredBuild, Diag> {
    let store = Store::open(root)?;
    let baseline = previous
        .map(|id| store.manifest(id).map(|m| root.join("app").join(m.app)))
        .transpose()?;
    let staged = store.staging("app");
    let compiled = match bundle::compile_tree(sources, &staged, assets, baseline.as_deref(), false) {
        Ok(compiled) => compiled,
        Err(error) => {
            let _ = remove(&staged);
            return Err(error);
        }
    };
    let app_hash = tree_digest(&staged).map_err(io("hash app"))?;
    let client = staged.join("client").is_dir().then(|| format!("app/{app_hash}/client"));
    store.publish(&staged, &root.join("app").join(&app_hash))?;
    let runtime_revision = write_runtime(&store)?;
    let manifest = BuildManifest {
        format: FORMAT.into(),
        name: compiled.name,
        compiler: bundle::VERSION.into(),
        runtime: runtime_revision.clone(),
        app: app_hash.clone(),
        identity: compiled.identity,
        admin: compiled.admin,
        pages: compiled.pages,
        snapshot: format!("app/{app_hash}/snapshot.json"),
        client,
    };
    let bytes = serde_json::to_vec_pretty(&manifest).expect("manifest serializes");
    let build_id = hex(Sha256::digest(&bytes));
    let target = root.join("build").join(format!("{build_id}.json"));
    if !target.exists() {
        let staged = store.staging("build");
        fs::write(&staged, &bytes).map_err(io("write build"))?;
        store.publish(&staged, &target)?;
    }
    Ok(StoredBuild {
        build_id,
        runtime_revision,
        app_hash,
        manifest,
    })
}

/// Keep exactly the builds in `keep`; remove every other build manifest and ready marker, and every
/// runtime and app directory no kept build references. Returns the removed paths. With `dry_run`,
/// removes nothing.
///
/// The store never decides what to keep. The host passes the builds of every version it still
/// serves (on a host with immutable versions: every version not deleted), including a candidate it
/// is finalizing, and calls this only after a version is deleted.
pub fn store_gc(root: &Path, keep: &[&str], dry_run: bool) -> Result<Vec<PathBuf>, Diag> {
    let store = Store::open(root)?;
    let kept: BTreeSet<String> = keep.iter().map(|id| (*id).to_owned()).collect();
    let mut runtimes = BTreeSet::new();
    let mut apps = BTreeSet::new();
    for id in &kept {
        // An unknown kept ID is a caller mistake; collecting around it could delete a live build.
        let manifest = store.manifest(id)?;
        runtimes.insert(manifest.runtime);
        apps.insert(manifest.app);
    }
    let mut doomed = Vec::new();
    for (dir, live) in [
        ("build", &kept),
        ("ready", &kept),
        ("runtime", &runtimes),
        ("app", &apps),
    ] {
        for name in store.ids(dir)? {
            if !live.contains(name.strip_suffix(".json").unwrap_or(&name)) {
                doomed.push(root.join(dir).join(name));
            }
        }
    }
    if !dry_run {
        for path in &doomed {
            // Rename out of the store first: a reader sees the whole directory or none of it.
            let trash = store.staging("gc");
            fs::rename(path, &trash).map_err(io("collect"))?;
            remove(&trash).map_err(io("collect"))?;
        }
        // Staging left by an interrupted writer; every live writer holds the lock we hold.
        for entry in fs::read_dir(root.join(".tmp")).map_err(io("list staging"))? {
            remove(&entry.map_err(io("list staging"))?.path()).map_err(io("clear staging"))?;
        }
    }
    Ok(doomed)
}
