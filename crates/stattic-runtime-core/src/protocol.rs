//! Finalizer protocol limits and markers shared across config, transform, and
//! policy modules, plus the generated TypeScript/PHP protocol sources emitted
//! by `cargo run -p stattic-runtime-compiler --bin protocol-codegen`.

use serde::Serialize;
use serde_json::Value;

pub const CONFIG_FILE_MAX_BYTES: usize = 256 * 1024;
pub const CONFIG_OVERLAY_MAX_BYTES: usize = 16 * 1024;
pub const CONFIG_TEMPLATE_LIMIT: usize = 100;
pub const CONFIG_META_TITLE_MAX_CHARS: usize = 300;
pub const CONFIG_META_DESCRIPTION_MAX_CHARS: usize = 1_000;
pub const CONFIG_META_IMAGE_MAX_CHARS: usize = 2_000;
pub const CONFIG_INJECT_SNIPPET_MAX_BYTES: usize = 8 * 1024;
pub const CONFIG_INJECT_SNIPPET_LIMIT: usize = 16;
pub const CONFIG_ACCESS_RULE_LIMIT: usize = 100;
pub const CONFIG_PAGES_COLOR_MAX_CHARS: usize = 64;
pub const CONFIG_PAGES_LOGO_MAX_CHARS: usize = 2_000;
pub const CONFIG_PAGES_NAME_MAX_CHARS: usize = 120;
pub const CONFIG_PAGES_FONT_FAMILY_MAX_CHARS: usize = 256;
pub const CONFIG_BUILD_TIMEOUT_MIN_SECONDS: u64 = 60;
pub const CONFIG_BUILD_TIMEOUT_MAX_SECONDS: u64 = 7_200;
pub const CONFIG_FALLBACK_STATUS_MIN: u64 = 100;
pub const CONFIG_FALLBACK_STATUS_MAX: u64 = 599;
pub const CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS: usize = 64;
pub const CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS: usize = 128;
pub const CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS: usize = 160;
pub const CONFIG_SUPERPOWERS_OVERRIDE_LIMIT: usize = 128;
pub const CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS: usize = 500;
pub const CONFIG_CANONICAL_FILE: &str = "spacefast.jsonc";
pub const CONFIG_ALIAS_FILES: &[&str] = &[
    "sf.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];
pub const CONFIG_ACCEPTED_FILES: &[&str] = &[
    CONFIG_CANONICAL_FILE,
    "sf.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];

pub const TEMPLATE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const TEMPLATE_VARIANT_FILE_LIMIT: usize = 100;
pub const TEMPLATE_VARIANT_ROUTE_LIMIT: usize = 8;
pub const TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS: usize = 128;
pub const PAGE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const GATE_THEME_FILE_MAX_BYTES: usize = 64 * 1024;
pub const GATE_THEME_VARS_MAX_BYTES: usize = 8 * 1024;
pub const SPACE_THEME_CSS_MAX_BYTES: usize = 16 * 1024;
pub const SPACE_THEME_PRESET_LIMIT: usize = 64;
pub const SPACE_THEME_SLUG_MAX_CHARS: usize = 64;
pub const SPACE_THEME_VALUE_MAX_CHARS: usize = 128;
pub const SPACE_THEME_NAME_MAX_CHARS: usize = 128;
pub const SPACE_THEME_SIZE_MAX_CHARS: usize = 64;
pub const SPACE_THEME_FONT_FAMILY_MAX_CHARS: usize = 256;
pub const THEME_JSON_FONT_FAMILY_LIMIT: usize = 32;

pub const ZERO_BUNDLE_MAX_BYTES: usize = 786_432;
pub const ZERO_BUNDLE_LIMIT: usize = 8;
pub const ZERO_STATIC_FILE_LIMIT: usize = 16;
pub const ZERO_ENTRY_LIMIT: usize = 128;
pub const ZERO_SOURCE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const ZERO_ID_MAX_CHARS: usize = 256;
pub const ZERO_ROUTE_PATH_MAX_CHARS: usize = 2_048;
pub const ZERO_ENTRY_NAME_MAX_CHARS: usize = 128;
pub const ZERO_CONTENT_TYPE_MAX_CHARS: usize = 255;

pub const PAGE_PROTOCOL_FORMAT: &str = "spacefast.page-protocol.v1";

pub const CHALLENGE_MARKER: &str = "<!--spacefast:slot:challenge:v1-->";
pub const DENY_MARKER: &str = "<!--spacefast:slot:deny:v1-->";
pub const LAYOUT_MARKER: &str = "<!--spacefast:slot:layout-content:v1-->";
pub const BADGE_MARKER: &str = "<!--spacefast:slot:badge:v1-->";
pub const CHALLENGE_FILE: &str = "401.html";
pub const DENY_FILE: &str = "403.html";
pub const LAYOUT_FILE: &str = ".spacefast/templates/layout.html";

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerProtocolMetadata {
    pub config: ConfigProtocolMetadata,
    pub space_config_schema: Value,
    pub limits: FinalizerLimitMetadata,
    pub markers: FinalizerMarkerMetadata,
    pub paths: FinalizerPathMetadata,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ConfigProtocolMetadata {
    pub current: ConfigFilePolicyMetadata,
    pub strict_v1: ConfigFilePolicyMetadata,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ConfigFilePolicyMetadata {
    pub canonical_file: &'static str,
    pub alias_files: &'static [&'static str],
    pub accepted_files: &'static [&'static str],
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerLimitMetadata {
    pub config_file_max_bytes: usize,
    pub config_overlay_max_bytes: usize,
    pub config_template_limit: usize,
    pub config_meta_title_max_chars: usize,
    pub config_meta_description_max_chars: usize,
    pub config_meta_image_max_chars: usize,
    pub config_inject_snippet_max_bytes: usize,
    pub config_inject_snippet_limit: usize,
    pub config_access_rule_limit: usize,
    pub config_pages_color_max_chars: usize,
    pub config_pages_logo_max_chars: usize,
    pub config_pages_name_max_chars: usize,
    pub config_pages_font_family_max_chars: usize,
    pub config_build_timeout_min_seconds: u64,
    pub config_build_timeout_max_seconds: u64,
    pub config_fallback_status_min: u64,
    pub config_fallback_status_max: u64,
    pub config_superpowers_integration_id_max_chars: usize,
    pub config_superpowers_tag_id_max_chars: usize,
    pub config_superpowers_tag_name_max_chars: usize,
    pub config_superpowers_override_limit: usize,
    pub config_superpowers_override_reason_max_chars: usize,
    pub template_max_bytes: usize,
    pub template_variant_file_limit: usize,
    pub template_variant_route_limit: usize,
    pub template_variant_route_name_max_chars: usize,
    pub page_max_bytes: usize,
    pub gate_theme_file_max_bytes: usize,
    pub gate_theme_vars_max_bytes: usize,
    pub space_theme_css_max_bytes: usize,
    pub space_theme_preset_limit: usize,
    pub space_theme_slug_max_chars: usize,
    pub space_theme_value_max_chars: usize,
    pub space_theme_name_max_chars: usize,
    pub space_theme_size_max_chars: usize,
    pub space_theme_font_family_max_chars: usize,
    pub theme_json_font_family_limit: usize,
    pub zero_bundle_max_bytes: usize,
    pub zero_bundle_limit: usize,
    pub zero_static_file_limit: usize,
    pub zero_entry_limit: usize,
    pub zero_source_max_bytes: usize,
    pub zero_id_max_chars: usize,
    pub zero_route_path_max_chars: usize,
    pub zero_entry_name_max_chars: usize,
    pub zero_content_type_max_chars: usize,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerMarkerMetadata {
    pub challenge: &'static str,
    pub deny: &'static str,
    pub layout: &'static str,
    pub badge: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerPathMetadata {
    pub challenge: &'static str,
    pub deny: &'static str,
    pub layout: &'static str,
}

pub fn metadata() -> FinalizerProtocolMetadata {
    let policy = || ConfigFilePolicyMetadata {
        canonical_file: CONFIG_CANONICAL_FILE,
        alias_files: CONFIG_ALIAS_FILES,
        accepted_files: CONFIG_ACCEPTED_FILES,
    };
    FinalizerProtocolMetadata {
        config: ConfigProtocolMetadata {
            current: policy(),
            strict_v1: policy(),
        },
        space_config_schema: crate::config::current::public_json_schema(),
        limits: FinalizerLimitMetadata {
            config_file_max_bytes: CONFIG_FILE_MAX_BYTES,
            config_overlay_max_bytes: CONFIG_OVERLAY_MAX_BYTES,
            config_template_limit: CONFIG_TEMPLATE_LIMIT,
            config_meta_title_max_chars: CONFIG_META_TITLE_MAX_CHARS,
            config_meta_description_max_chars: CONFIG_META_DESCRIPTION_MAX_CHARS,
            config_meta_image_max_chars: CONFIG_META_IMAGE_MAX_CHARS,
            config_inject_snippet_max_bytes: CONFIG_INJECT_SNIPPET_MAX_BYTES,
            config_inject_snippet_limit: CONFIG_INJECT_SNIPPET_LIMIT,
            config_access_rule_limit: CONFIG_ACCESS_RULE_LIMIT,
            config_pages_color_max_chars: CONFIG_PAGES_COLOR_MAX_CHARS,
            config_pages_logo_max_chars: CONFIG_PAGES_LOGO_MAX_CHARS,
            config_pages_name_max_chars: CONFIG_PAGES_NAME_MAX_CHARS,
            config_pages_font_family_max_chars: CONFIG_PAGES_FONT_FAMILY_MAX_CHARS,
            config_build_timeout_min_seconds: CONFIG_BUILD_TIMEOUT_MIN_SECONDS,
            config_build_timeout_max_seconds: CONFIG_BUILD_TIMEOUT_MAX_SECONDS,
            config_fallback_status_min: CONFIG_FALLBACK_STATUS_MIN,
            config_fallback_status_max: CONFIG_FALLBACK_STATUS_MAX,
            config_superpowers_integration_id_max_chars:
                CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS,
            config_superpowers_tag_id_max_chars: CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS,
            config_superpowers_tag_name_max_chars: CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS,
            config_superpowers_override_limit: CONFIG_SUPERPOWERS_OVERRIDE_LIMIT,
            config_superpowers_override_reason_max_chars:
                CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS,
            template_max_bytes: TEMPLATE_MAX_BYTES,
            template_variant_file_limit: TEMPLATE_VARIANT_FILE_LIMIT,
            template_variant_route_limit: TEMPLATE_VARIANT_ROUTE_LIMIT,
            template_variant_route_name_max_chars: TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS,
            page_max_bytes: PAGE_MAX_BYTES,
            gate_theme_file_max_bytes: GATE_THEME_FILE_MAX_BYTES,
            gate_theme_vars_max_bytes: GATE_THEME_VARS_MAX_BYTES,
            space_theme_css_max_bytes: SPACE_THEME_CSS_MAX_BYTES,
            space_theme_preset_limit: SPACE_THEME_PRESET_LIMIT,
            space_theme_slug_max_chars: SPACE_THEME_SLUG_MAX_CHARS,
            space_theme_value_max_chars: SPACE_THEME_VALUE_MAX_CHARS,
            space_theme_name_max_chars: SPACE_THEME_NAME_MAX_CHARS,
            space_theme_size_max_chars: SPACE_THEME_SIZE_MAX_CHARS,
            space_theme_font_family_max_chars: SPACE_THEME_FONT_FAMILY_MAX_CHARS,
            theme_json_font_family_limit: THEME_JSON_FONT_FAMILY_LIMIT,
            zero_bundle_max_bytes: ZERO_BUNDLE_MAX_BYTES,
            zero_bundle_limit: ZERO_BUNDLE_LIMIT,
            zero_static_file_limit: ZERO_STATIC_FILE_LIMIT,
            zero_entry_limit: ZERO_ENTRY_LIMIT,
            zero_source_max_bytes: ZERO_SOURCE_MAX_BYTES,
            zero_id_max_chars: ZERO_ID_MAX_CHARS,
            zero_route_path_max_chars: ZERO_ROUTE_PATH_MAX_CHARS,
            zero_entry_name_max_chars: ZERO_ENTRY_NAME_MAX_CHARS,
            zero_content_type_max_chars: ZERO_CONTENT_TYPE_MAX_CHARS,
        },
        markers: FinalizerMarkerMetadata {
            challenge: CHALLENGE_MARKER,
            deny: DENY_MARKER,
            layout: LAYOUT_MARKER,
            badge: BADGE_MARKER,
        },
        paths: FinalizerPathMetadata {
            challenge: CHALLENGE_FILE,
            deny: DENY_FILE,
            layout: LAYOUT_FILE,
        },
    }
}

#[must_use]
pub fn typescript_source() -> String {
    let json = serde_json::to_string_pretty(&metadata()).expect("protocol metadata serializes");
    format!(
        "// @generated by `cargo run -p stattic-runtime-compiler --bin protocol-codegen`.\n// Rust `protocol.rs` and `config/current.rs` are the only editable authorities.\n\nexport const FINALIZER_PROTOCOL = {json} as const;\n\nexport type FinalizerProtocolMetadata = typeof FINALIZER_PROTOCOL;\n{}",
        crate::config::current::TYPESCRIPT_CONFIG_TYPES
    )
}

/// PHP protocol constants for the serving engine. Native-only because the
/// Zero runner ABI identifiers come from the native `stattic-zero-runner`
/// crate.
#[cfg(not(target_family = "wasm"))]
#[must_use]
pub fn php_source() -> String {
    format!(
        "<?php\ndeclare(strict_types=1);\n\n// @generated by `cargo run -p stattic-runtime-compiler --bin protocol-codegen -- --php`.\n// Rust protocol and Zero runner constants are the only editable authorities.\n\nconst STATTIC_RUNTIME_ZERO_RUNNER_ABI = '{}';\nconst STATTIC_RUNTIME_ZERO_QUICKJS_ABI = '{}';\nconst STATTIC_RUNTIME_ZERO_BUNDLE_MAX_BYTES = {};\nconst STATTIC_RUNTIME_ZERO_BUNDLE_LIMIT = {};\n",
        stattic_zero_runner::RUNNER_ABI,
        stattic_zero_runner::QUICKJS_ABI,
        ZERO_BUNDLE_MAX_BYTES,
        ZERO_BUNDLE_LIMIT,
    )
}

#[cfg(test)]
mod tests {
    // The checked-in TypeScript protocol is regenerated and formatter-cleaned
    // by `scripts/check-finalizer-protocol.mjs`; it cannot be byte-compared
    // here because the repo formatter rewrites the emitted JSON literal. The
    // PHP output is byte-exact against the Rust emitter.
    #[test]
    fn checked_in_php_protocol_is_current() {
        assert_eq!(
            super::php_source(),
            include_str!(concat!(
                env!("CARGO_MANIFEST_DIR"),
                "/../../runtime/engine/shared/finalizer-protocol.generated.php"
            ))
        );
    }
}
