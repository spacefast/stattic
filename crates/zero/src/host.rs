//! Native host integration without downloads, archives, or subprocesses.
use crate::{bundle, diag::Diag};
use std::path::Path;

pub use bundle::compile as compile_sources;

/// Capture relative TypeScript sources on a host without an npm installation.
pub fn sources(project: &Path, destination: &Path) -> Result<bundle::Manifest, Diag> {
    bundle::write_sources(project, destination, false)
}
