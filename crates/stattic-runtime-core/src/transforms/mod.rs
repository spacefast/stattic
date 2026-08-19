//! Deterministic finalization transforms shared by native and WASM callers.
//!
//! Callers gather external state (files, entitlements, variables) and pass it
//! through the versioned ABI. Product parsing and byte transforms live here.

mod conventions;
mod effective;
pub(crate) mod html;
mod theme;

pub use conventions::{lower_runtime_conventions, RuntimeConventionsInput};
pub use effective::{
    build_runtime_payload, resolve_effective_config, ResolveEffectiveInput, RuntimePayloadInput,
};
pub use html::{compile_page, PageCompileInput};
pub use theme::{validate_theme_json, ValidateThemeJsonInput};
