// Native-only modules: everything that touches the filesystem or the native
// Zero runner (QuickJS/rlimit) is compiled out of the wasm32-wasip1 build.
// The wasm surface is the pure prepare/transform dispatch in `prepare_abi`.
#[cfg(not(target_family = "wasm"))]
pub mod bundle;
#[cfg(not(target_family = "wasm"))]
mod compiler;
mod hash;
#[cfg(not(target_family = "wasm"))]
mod import_rebind;
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
pub mod config;
pub mod content;
pub mod finalize;
pub mod policy;
pub mod prepare;
pub mod prepare_abi;
pub mod protocol;
pub mod routing;
mod serving_paths;
#[cfg(not(target_family = "wasm"))]
pub mod storage;
pub mod transforms;
pub mod version_files;

#[cfg(not(target_family = "wasm"))]
pub use bundle::{
    build_bundle, inspect_bundle, BuildBundleInput, BundleDescriptor, BundleError, BUNDLE_FORMAT,
};
#[cfg(not(target_family = "wasm"))]
pub use compiler::{compile_build, compile_finalize};
pub use finalize::FinalizeError;
pub use hash::stable_json_sha256;
#[cfg(not(target_family = "wasm"))]
pub use import_rebind::{
    rebind_imported_version, ImportRebindInput, ImportRebindOutput, IMPORT_REBIND_INPUT_FORMAT,
    IMPORT_REBIND_OUTPUT_FORMAT,
};
#[cfg(not(target_family = "wasm"))]
pub use io::{
    read_build_input, read_finalize_input, read_import_rebind_input, read_input_format,
    read_site_finalize_input, write_import_rebind_output, write_output, write_site_finalize_error,
    write_site_finalize_output,
};
pub use model::*;
#[cfg(not(target_family = "wasm"))]
pub use site_finalize::{finalize_site, finalize_site_dry_run};
