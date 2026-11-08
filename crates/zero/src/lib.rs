//! The Zero compiler: `zero.config.ts` in, WordPress plugins out.
//!
//! The core (parse, lower, evaluate, extract, compile, migrate, client, schema.php) does no I/O of
//! its own: sources arrive through [`loader::Fs`] and the previous snapshot is a value
//! ([`pipeline`]). The `cli` feature adds the commands, which read and write the project, download
//! plugin dependencies and carry the embedded PHP runtime. [`wasm`] exports the core to a browser.

pub mod builtins;
pub mod client;
pub mod compile;
pub mod diag;
pub mod eval;
pub mod extract;
pub mod ir;
pub mod loader;
pub mod lower;
pub mod migrate;
pub mod num;
pub mod pages;
pub mod pipeline;
pub mod platform;
pub mod plugins;
pub mod schema;
pub mod schema_php;
pub mod services_config;
pub mod types;
pub mod uri;
pub mod value;
pub mod wasm;
pub mod wordpress;
pub mod wp_plugins;
pub mod wpml_config;

#[cfg(feature = "cli")]
pub mod cli;
#[cfg(feature = "cli")]
mod commands;
pub mod config_migrate;
#[cfg(feature = "host")]
mod emit;
#[cfg(feature = "host")]
pub mod host;
#[cfg(feature = "host")]
pub mod store;
#[cfg(any(feature = "cli", feature = "host"))]
pub mod sync;
#[cfg(target_arch = "wasm32")]
mod wasm_host_fs;
#[cfg(feature = "host")]
mod runtime_files {
    include!(concat!(env!("OUT_DIR"), "/runtime_files.rs"));
}

pub mod bundle;
