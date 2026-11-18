//! Native host integration without downloads, archives, or subprocesses.
use crate::{bundle, diag::Diag};
use std::path::Path;

pub use crate::store::{BuildManifest, StoredBuild, compile_to_store, store_gc};
pub use bundle::compile as compile_sources;

/// Capture relative TypeScript sources on a host without an npm installation. A raw project from a
/// client released before the rename (`zero.config.ts` at its root, no capture) is captured as the
/// `zero.source-bundle.v1` it stands for (`crate::zero_bundle::write_raw`).
pub fn sources(project: &Path, destination: &Path) -> Result<bundle::Manifest, Diag> {
    if crate::zero_bundle::is_raw(project)? {
        return crate::zero_bundle::write_raw(project, destination);
    }
    bundle::write_sources(project, destination, false)
}
