//! Deterministic finalization transforms shared by native and WASM callers.
//!
//! Callers gather external state (files, entitlements, variables) and pass it
//! through the versioned ABI. Product parsing and byte transforms live here.

mod conventions;
mod effective;
pub(crate) mod html;
mod theme;

pub use conventions::{
    apply_routing_plan_policy, lower_runtime_conventions, merge_preview_loader_csp,
    PreviewLoaderCspInput, RoutingPlanInput, RuntimeConventionsInput,
};
pub use effective::{
    build_runtime_payload, resolve_effective_config, ResolveEffectiveInput, RuntimePayloadInput,
};
pub use html::{compile_page, transform_html, HtmlTransformInput, PageCompileInput};
pub use theme::{
    compile_space_theme, merge_gate_theme, validate_theme_json, CompileSpaceThemeInput,
    MergeGateThemeInput, ValidateThemeJsonInput,
};
