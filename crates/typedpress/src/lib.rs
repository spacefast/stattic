//! The TypedPress compiler: `typedpress.config.ts` in, WordPress plugins out.
//!
//! The core (parse, lower, evaluate, extract, compile, migrate, client, schema.php) does no I/O of
//! its own: sources arrive through [`loader::Fs`] and the previous snapshot is a value
//! ([`pipeline`]). The `cli` feature adds the commands, which read and write the project, download
//! plugin dependencies and carry the embedded PHP runtime. [`wasm`] exports the core to a browser.

pub mod admin_dashboard;
pub mod admin_screen;
pub mod app;
mod app_plugin;
pub mod assets;
pub mod astro_content;
pub mod builtins;
pub mod client;
pub mod client_component;
pub mod commerce;
pub mod compile;
pub mod content_blocks;
pub mod content_page;
pub mod css;
pub mod diag;
pub mod eval;
pub mod extract;
pub mod ir;
pub mod json_schema;
pub mod kind_url;
pub mod layout;
pub mod lint;
pub mod loader;
pub mod lower;
pub mod migrate;
mod minify;
pub mod num;
pub mod openapi;
mod php_layout;
pub mod pipeline;
pub mod platform;
pub mod plugins;
pub mod runtime_schema;
pub mod schema;
pub mod schema_php;
pub mod services_config;
pub mod sort;
pub mod storage;
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
#[cfg(feature = "cli")]
mod frontend;
#[cfg(feature = "host")]
pub mod host;
pub mod host_contract;
#[cfg(feature = "host")]
pub mod store;
// The front matter reader is part of the core: `page.md` routes parse with it in every build.
pub mod sync;
pub mod theme;
#[cfg(target_arch = "wasm32")]
mod wasm_host_fs;
#[cfg(feature = "host")]
mod runtime_files {
    include!(concat!(env!("OUT_DIR"), "/runtime_files.rs"));
}

#[cfg(feature = "cli")]
mod docs_files {
    include!(concat!(env!("OUT_DIR"), "/docs_files.rs"));
    include!(concat!(env!("OUT_DIR"), "/skills_files.rs"));
}

/// Lowercase hexadecimal of `bytes`.
pub(crate) fn hex(bytes: impl AsRef<[u8]>) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let bytes = bytes.as_ref();
    let mut out = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        out.push(DIGITS[usize::from(b >> 4)] as char);
        out.push(DIGITS[usize::from(b & 15)] as char);
    }
    out
}

#[cfg(test)]
#[test]
fn hex_is_lowercase_two_digits_a_byte() {
    assert_eq!(hex([0x00, 0x0f, 0xa0, 0xff]), "000fa0ff");
    assert_eq!(hex(b""), "");
}

pub mod bundle;
#[cfg(feature = "host")]
mod zero_bundle;
