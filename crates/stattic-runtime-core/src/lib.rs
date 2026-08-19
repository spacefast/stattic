// Native-only modules: everything that touches the filesystem or the native
// Zero runner (QuickJS/rlimit) is compiled out of the wasm32-wasip1 build.
// The wasm surface is the pure prepare/transform dispatch in `prepare_abi`.
mod hash;
#[cfg(not(target_family = "wasm"))]
mod io;
#[cfg(not(target_family = "wasm"))]
mod metadata;
mod model;
#[cfg(not(target_family = "wasm"))]
mod site_finalize;
#[cfg(not(target_family = "wasm"))]
mod zero;

pub mod access;
pub mod artifacts;
#[cfg(not(target_family = "wasm"))]
pub mod catalog;
pub mod config;
pub mod content;
pub mod csp;
pub mod egress;
pub mod finalize;
pub mod policy;
pub mod prepare;
pub mod prepare_abi;
pub mod protocol;
#[cfg(not(target_family = "wasm"))]
pub(crate) mod responses;
pub mod routing;
mod serving_paths;
#[cfg(not(target_family = "wasm"))]
pub mod storage;
pub mod transforms;
// Version-root artifacts: filesystem-native, reached only from the native
// finalizer, and now naming the catalog file the native catalog module owns.
#[cfg(not(target_family = "wasm"))]
pub mod version_files;

#[cfg(not(target_family = "wasm"))]
pub use catalog::{
    catalog_from_metadata, read_version_catalog, transform_private_root, transform_version,
    CatalogDelta, CatalogDigests, FileCatalog, TransformOutcome, TransformReport,
    TRANSFORM_REPORT_FORMAT,
};
pub use finalize::FinalizeError;
pub use hash::stable_json_sha256;
#[cfg(not(target_family = "wasm"))]
pub use io::{read_site_finalize_input, write_site_finalize_error, write_site_finalize_output};
pub use model::*;
#[cfg(not(target_family = "wasm"))]
pub use site_finalize::finalize_site;
