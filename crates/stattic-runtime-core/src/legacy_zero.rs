// Removable Zero 0.8 model and native compiler/finalizer boundary.
#[path = "legacy/zero-0.8/model.rs"]
mod model;
pub use model::*;

#[cfg(not(target_family = "wasm"))]
#[path = "legacy/zero-0.8/compile.rs"]
mod compile;
#[cfg(not(target_family = "wasm"))]
#[path = "legacy/zero-0.8/finalize.rs"]
mod finalize;
#[cfg(not(target_family = "wasm"))]
#[path = "legacy/zero-0.8/inventory.rs"]
mod inventory;
#[cfg(all(test, not(target_family = "wasm")))]
pub(crate) use compile::compile_zero_endpoints;
#[cfg(not(target_family = "wasm"))]
pub(crate) use compile::zero_pack_sha256;
#[cfg(not(target_family = "wasm"))]
pub(crate) use finalize::{
    compile_trunk_zero, validate_zero_artifacts, write_zero_artifacts, zero_response_actions,
    zero_route_can_own_root,
};
#[cfg(not(target_family = "wasm"))]
pub(crate) use inventory::append_routes;

#[path = "legacy/zero-0.8/config.rs"]
mod config;
pub(crate) use config::{normalize_runtime, runtime_schema};
