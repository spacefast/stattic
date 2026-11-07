//! `zero sync` (design §5.4): files ⇄ records against a running site. Conversion of bodies and body
//! merges run on the server (`/sync/{c}/convert`, `/sync/{c}/merge`); this side reads and writes
//! files, keeps bases, merges fields and writes conflict files.

#[cfg(feature = "cli")]
pub mod apply;
#[cfg(feature = "cli")]
pub mod base;
pub mod canon;
#[cfg(feature = "cli")]
pub mod client;
#[cfg(feature = "cli")]
pub mod conflict;
pub mod files;
pub mod front_matter;
#[cfg(feature = "cli")]
pub mod git;
#[cfg(feature = "cli")]
pub mod merge;
#[cfg(feature = "cli")]
pub mod plan;
pub mod uid;
#[cfg(any(feature = "cli", test))]
pub mod yaml_out;

#[cfg(feature = "cli")]
use sha2::{Digest, Sha256};
#[cfg(feature = "cli")]
use std::path::{Path, PathBuf};

/// A push waiting for a reference to land.
#[cfg(feature = "cli")]
#[derive(Clone)]
pub struct Deferred {
    pub collection: String,
    pub uid: String,
    /// The record the plan produced (merged, if it was).
    pub record: serde_json::Value,
    /// The site digest the record was planned against, or the one its identity push created.
    pub expect: String,
    /// The digest of the file it was planned from (None: no file); a change means plan again.
    pub local: Option<String>,
    /// The identity-only record this run created for it, if any: provisional, never a base. Its
    /// digest is `expect` while it lasts.
    pub identity: bool,
}

/// What a run did, for the summary, the exit code and the commit.
#[cfg(feature = "cli")]
pub struct Report {
    project: PathBuf,
    pub written: Vec<PathBuf>,
    pub applied: usize,
    pub conflicts: Vec<String>,
    pub pending: Vec<String>,
    pub failures: Vec<String>,
    pub refused: Vec<String>,
    pub notes: Vec<String>,
    /// A call was refused for lack of access (401/403): the run exits 4.
    pub denied: bool,
    /// Pushes refused for a reference not on the site yet, sent again in a later pass.
    pub deferred: Vec<Deferred>,
    /// Identities created this pass: progress even when no record completes.
    pub identities: usize,
}

#[cfg(feature = "cli")]
impl Report {
    pub fn new(project: &Path) -> Report {
        Report {
            project: project.to_path_buf(),
            written: Vec::new(),
            applied: 0,
            conflicts: Vec::new(),
            pending: Vec::new(),
            failures: Vec::new(),
            refused: Vec::new(),
            notes: Vec::new(),
            denied: false,
            deferred: Vec::new(),
            identities: 0,
        }
    }

    pub fn project(&self) -> &Path {
        &self.project
    }

    pub fn rel(&self, path: &Path) -> String {
        path.strip_prefix(&self.project).unwrap_or(path).display().to_string()
    }

    pub fn written(&mut self, path: &Path) {
        if !self.written.iter().any(|p| p == path) {
            self.written.push(path.to_path_buf());
        }
    }

    pub fn applied(&mut self) {
        self.applied += 1;
    }

    pub fn conflict(&mut self, what: String) {
        self.conflicts.push(what);
    }

    pub fn pending(&mut self, what: String) {
        self.pending.push(what);
    }

    pub fn fail(&mut self, what: String) {
        self.failures.push(what);
    }

    pub fn defer(&mut self, deferred: Deferred) {
        self.deferred.push(deferred);
    }

    /// A failed site call; auth refusals also mark the run as denied.
    pub fn problem(&mut self, what: &str, p: &client::Problem) -> String {
        self.denied |= p.is_auth();
        format!("{what}: {}", p.message())
    }

    pub fn refused(&mut self, what: String) {
        self.refused.push(what);
    }

    pub fn note(&mut self, what: String) {
        self.notes.push(what);
    }
}

/// A fresh uid, the same shape the runtime mints: `zr_` + 20 hex of a random sha256.
#[cfg(feature = "cli")]
pub fn uid_mint() -> String {
    let mut seed = [0u8; 16];
    getrandom(&mut seed);
    let hash = Sha256::digest(seed);
    let hex: String = hash.iter().map(|b| format!("{b:02x}")).collect();
    format!("zr_{}", &hex[..20])
}

#[cfg(feature = "cli")]
fn getrandom(buf: &mut [u8]) {
    use std::io::Read;
    if std::fs::File::open("/dev/urandom")
        .and_then(|mut f| f.read_exact(buf))
        .is_ok()
    {
        return;
    }
    // Without /dev/urandom: hash the clock, the process and an address; uniqueness, not secrecy, is needed.
    let entropy = format!(
        "{:?}{}{:p}",
        std::time::SystemTime::now(),
        std::process::id(),
        buf.as_ptr()
    );
    let hash = Sha256::digest(entropy.as_bytes());
    for (b, h) in buf.iter_mut().zip(hash.iter()) {
        *b = *h;
    }
}

/// Write through a temporary file in the same directory and rename it into place.
#[cfg(feature = "cli")]
pub fn write_atomic(path: &Path, bytes: &[u8]) -> Result<(), String> {
    let dir = path.parent().ok_or("a file needs a directory")?;
    std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    let mut tmp = tempfile::NamedTempFile::new_in(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    std::io::Write::write_all(&mut tmp, bytes).map_err(|e| e.to_string())?;
    tmp.persist(path)
        .map_err(|e| format!("{}: {}", path.display(), e.error))?;
    Ok(())
}
