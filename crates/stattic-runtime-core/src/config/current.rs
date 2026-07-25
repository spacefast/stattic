//! Validation and normalization for the current Spacefast config schema.

use super::diagnostics::{diagnostic, DiagnosticSeverity, PrepareDiagnostic};
use super::jsonc::parse as parse_jsonc;
use crate::protocol::{
    CONFIG_ACCESS_RULE_LIMIT, CONFIG_BUILD_TIMEOUT_MAX_SECONDS, CONFIG_BUILD_TIMEOUT_MIN_SECONDS,
    CONFIG_FALLBACK_STATUS_MAX, CONFIG_FALLBACK_STATUS_MIN, CONFIG_FILE_MAX_BYTES,
    CONFIG_INJECT_SNIPPET_LIMIT, CONFIG_INJECT_SNIPPET_MAX_BYTES,
    CONFIG_META_DESCRIPTION_MAX_CHARS, CONFIG_META_IMAGE_MAX_CHARS, CONFIG_META_TITLE_MAX_CHARS,
    CONFIG_OVERLAY_MAX_BYTES, CONFIG_PAGES_COLOR_MAX_CHARS, CONFIG_PAGES_FONT_FAMILY_MAX_CHARS,
    CONFIG_PAGES_LOGO_MAX_CHARS, CONFIG_PAGES_NAME_MAX_CHARS,
    CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS, CONFIG_SUPERPOWERS_OVERRIDE_LIMIT,
    CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS, CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS,
    CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS, CONFIG_TEMPLATE_LIMIT,
};
use regex::Regex;
use serde_json::{json, Map, Value};
use std::sync::OnceLock;

const KNOWN_CONFIG_KEYS: &[&str] = &[
    "$schema",
    "access",
    "build",
    "cleanUrls",
    "experimental_gutenberg",
    "fallback",
    "index",
    "inject",
    "listing",
    "markdownNegotiation",
    "meta",
    "placement",
    "space",
    "superpowers",
    "templates",
    "theme",
];

const ROUTING_CONFIG_KEYS: &[&str] = &[
    "headers",
    "proxies",
    "proxy",
    "redirects",
    "rewrites",
    "routes",
];

pub fn normalize_overlay(config: Value) -> (Option<Value>, Vec<PrepareDiagnostic>) {
    let mut diagnostics = Vec::new();
    let raw = match serde_json::to_string(&config) {
        Ok(raw) => raw,
        Err(error) => {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Error,
                "config_invalid",
                format!("config could not be encoded: {error}"),
                Some("config".into()),
            ));
            return (None, diagnostics);
        }
    };
    if raw.len() > CONFIG_OVERLAY_MAX_BYTES {
        let mut item = diagnostic(
            DiagnosticSeverity::Error,
            "config_file_too_large",
            format!("Space config overlays support up to {CONFIG_OVERLAY_MAX_BYTES} bytes."),
            Some("config".into()),
        );
        item.details.insert("size".into(), Value::from(raw.len()));
        item.details
            .insert("limit".into(), Value::from(CONFIG_OVERLAY_MAX_BYTES));
        diagnostics.push(item);
        return (None, diagnostics);
    }
    if let Some(object) = config.as_object() {
        for key in ["$schema", "space", "access"] {
            if object.contains_key(key) {
                diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "config_invalid",
                    format!("{key} is only valid in the committed config file."),
                    Some(key.into()),
                ));
            }
        }
    }
    let normalized = parse_config(&raw, "config", &mut diagnostics);
    if diagnostics
        .iter()
        .any(|item| item.severity == DiagnosticSeverity::Error)
    {
        (None, diagnostics)
    } else {
        (normalized, diagnostics)
    }
}

pub fn parse_config(
    raw: &str,
    path: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) -> Option<Value> {
    if raw.len() > CONFIG_FILE_MAX_BYTES {
        let mut item = diagnostic(
            DiagnosticSeverity::Error,
            "config_file_too_large",
            format!("{path} supports up to {CONFIG_FILE_MAX_BYTES} bytes."),
            Some(path.into()),
        );
        item.details.insert("size".into(), Value::from(raw.len()));
        item.details
            .insert("limit".into(), Value::from(CONFIG_FILE_MAX_BYTES));
        diagnostics.push(item);
        return None;
    }
    let parsed = match parse_jsonc(raw) {
        Ok(value) => value,
        Err(error) => {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Error,
                "config_invalid",
                format!("{path} is not valid JSONC: {error}"),
                Some(path.into()),
            ));
            return None;
        }
    };
    let Value::Object(mut object) = parsed else {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_invalid",
            format!("{path} must contain a JSON object."),
            Some(path.into()),
        ));
        return None;
    };

    let keys = object.keys().cloned().collect::<Vec<_>>();
    for key in keys {
        if KNOWN_CONFIG_KEYS.contains(&key.as_str()) {
            continue;
        }
        let (severity, message) = if ROUTING_CONFIG_KEYS.contains(&key.as_str()) {
            (
                DiagnosticSeverity::Error,
                format!("Routing never lives in {path}; move \"{key}\" into _redirects/_headers."),
            )
        } else {
            (
                DiagnosticSeverity::Warning,
                format!("Unknown {path} key \"{key}\" was ignored."),
            )
        };
        diagnostics.push(diagnostic(
            severity,
            "config_invalid",
            message,
            Some(key.clone()),
        ));
        object.remove(&key);
    }
    validate_config_caps(&object, diagnostics);
    validate_config_shape(&mut object, path, diagnostics);
    if diagnostics
        .iter()
        .any(|item| item.severity == DiagnosticSeverity::Error)
    {
        None
    } else {
        Some(Value::Object(object))
    }
}

fn validate_config_caps(object: &Map<String, Value>, diagnostics: &mut Vec<PrepareDiagnostic>) {
    if let Some(templates) = object.get("templates").and_then(Value::as_array) {
        if templates.len() > CONFIG_TEMPLATE_LIMIT {
            let mut item = diagnostic(
                DiagnosticSeverity::Error,
                "config_templates_over_limit",
                format!("templates supports up to {CONFIG_TEMPLATE_LIMIT} entries."),
                Some("templates".into()),
            );
            item.details
                .insert("count".into(), Value::from(templates.len()));
            item.details
                .insert("limit".into(), Value::from(CONFIG_TEMPLATE_LIMIT));
            diagnostics.push(item);
        }
    }
    if let Some(meta) = object.get("meta").and_then(Value::as_object) {
        for (key, limit) in [
            ("title", CONFIG_META_TITLE_MAX_CHARS),
            ("description", CONFIG_META_DESCRIPTION_MAX_CHARS),
            ("image", CONFIG_META_IMAGE_MAX_CHARS),
        ] {
            if let Some(value) = meta.get(key).and_then(Value::as_str) {
                // Match JavaScript String.length used by the existing public
                // contract: Unicode scalar values outside the BMP occupy two
                // UTF-16 code units.
                let length = value.encode_utf16().count();
                if length > limit {
                    let mut item = diagnostic(
                        DiagnosticSeverity::Error,
                        "config_meta_too_long",
                        format!("meta.{key} supports up to {limit} characters."),
                        Some(format!("meta.{key}")),
                    );
                    item.details.insert("length".into(), Value::from(length));
                    item.details.insert("limit".into(), Value::from(limit));
                    diagnostics.push(item);
                }
            }
        }
    }
    let Some(inject) = object.get("inject") else {
        return;
    };
    let Some(inject) = inject.as_object() else {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "inject_invalid",
            "inject must be an object with static-placement snippet arrays.".into(),
            Some("inject".into()),
        ));
        return;
    };
    for key in ["head", "bodyStart", "bodyEnd", "noscript"] {
        let Some(value) = inject.get(key) else {
            continue;
        };
        let Some(snippets) = value.as_array() else {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Error,
                "inject_invalid",
                format!("inject.{key} must be an array of snippet strings."),
                Some(format!("inject.{key}")),
            ));
            continue;
        };
        if snippets.len() > CONFIG_INJECT_SNIPPET_LIMIT {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Error,
                "inject_invalid",
                format!("inject.{key} supports up to {CONFIG_INJECT_SNIPPET_LIMIT} snippets."),
                Some(format!("inject.{key}")),
            ));
        }
        for (index, snippet) in snippets.iter().enumerate() {
            let Some(snippet) = snippet.as_str() else {
                diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "inject_invalid",
                    format!("inject.{key}[{index}] must be a string."),
                    Some(format!("inject.{key}.{index}")),
                ));
                continue;
            };
            if snippet.len() > CONFIG_INJECT_SNIPPET_MAX_BYTES {
                diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "inject_snippet_too_large",
                    format!(
                        "inject.{key}[{index}] supports up to {CONFIG_INJECT_SNIPPET_MAX_BYTES} bytes."
                    ),
                    Some(format!("inject.{key}.{index}")),
                ));
            }
        }
    }
}

fn validate_config_shape(
    object: &mut Map<String, Value>,
    config_path: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let invalid = |diagnostics: &mut Vec<PrepareDiagnostic>, path: &str, expected: &str| {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_invalid",
            format!("Expected {expected}"),
            Some(if path.is_empty() {
                config_path.to_string()
            } else {
                path.to_string()
            }),
        ));
    };
    if let Some(value) = object.get("index") {
        if !(value.as_str().is_some_and(|value| !value.is_empty()) || value == &Value::Bool(false))
        {
            invalid(diagnostics, "index", "a non-empty string or false");
        }
    }
    if let Some(value) = object.get("fallback") {
        let valid = value.as_str().is_some_and(|value| !value.is_empty())
            || value.as_object().is_some_and(|fallback| {
                fallback
                    .get("path")
                    .and_then(Value::as_str)
                    .is_some_and(|path| !path.is_empty())
                    && fallback
                        .get("status")
                        .and_then(nonnegative_integer)
                        .is_some_and(|status| {
                            (CONFIG_FALLBACK_STATUS_MIN..=CONFIG_FALLBACK_STATUS_MAX)
                                .contains(&status)
                        })
            });
        if !valid {
            invalid(
                diagnostics,
                "fallback",
                "a non-empty string or { path, status } object",
            );
        }
    }
    if let Some(fallback) = object.get_mut("fallback").and_then(Value::as_object_mut) {
        fallback.retain(|key, _| matches!(key.as_str(), "path" | "status"));
    }
    for key in ["$schema", "space"] {
        if object.get(key).is_some_and(|value| {
            !value
                .as_str()
                .is_some_and(|value| key == "$schema" || !value.is_empty())
        }) {
            invalid(diagnostics, key, "a string");
        }
    }
    for key in [
        "cleanUrls",
        "listing",
        "markdownNegotiation",
        "experimental_gutenberg",
    ] {
        if object.get(key).is_some_and(|value| !value.is_boolean()) {
            invalid(diagnostics, key, "a boolean");
        }
    }
    if let Some(values) = object.get("templates") {
        if !values.as_array().is_some_and(|values| {
            values
                .iter()
                .all(|value| value.as_str().is_some_and(|value| !value.is_empty()))
        }) {
            invalid(diagnostics, "templates", "an array of non-empty strings");
        }
    }
    if let Some(meta) = object.get_mut("meta") {
        if let Some(meta) = meta.as_object_mut() {
            meta.retain(|key, _| matches!(key.as_str(), "title" | "description" | "image"));
            for key in ["title", "description", "image"] {
                if meta.get(key).is_some_and(|value| {
                    value
                        .as_str()
                        .is_none_or(|value| key == "title" && value.is_empty())
                }) {
                    invalid(diagnostics, &format!("meta.{key}"), "a string");
                }
            }
        } else {
            invalid(diagnostics, "meta", "an object");
        }
    }
    validate_build_config(object, diagnostics);
    validate_placement_config(object, diagnostics);
    validate_superpowers_config(object, diagnostics);
    validate_space_theme(object, diagnostics);
    validate_access_config(object, diagnostics);
    if let Some(inject) = object.get_mut("inject").and_then(Value::as_object_mut) {
        inject
            .retain(|key, _| matches!(key.as_str(), "head" | "bodyStart" | "bodyEnd" | "noscript"));
    }
}

fn validate_build_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(build) = object.get_mut("build") else {
        return;
    };
    let Some(build) = build.as_object_mut() else {
        push_shape_error(diagnostics, "build", "an object");
        return;
    };
    const STRING_KEYS: [&str; 8] = [
        "rootDirectory",
        "installDirectory",
        "installCommand",
        "buildCommand",
        "outputDirectory",
        "ignoredBuildCommand",
        "frameworkPreset",
        "platformPreset",
    ];
    const KNOWN_KEYS: [&str; 10] = [
        "rootDirectory",
        "installDirectory",
        "installCommand",
        "buildCommand",
        "outputDirectory",
        "ignoredBuildCommand",
        "frameworkPreset",
        "platformPreset",
        "allowUnsupportedPlatformFeatures",
        "timeoutSeconds",
    ];
    for key in build
        .keys()
        .filter(|key| !KNOWN_KEYS.contains(&key.as_str()))
        .cloned()
        .collect::<Vec<_>>()
    {
        push_shape_error(
            diagnostics,
            &format!("build.{key}"),
            "a supported build setting",
        );
    }
    for key in STRING_KEYS {
        if build.get(key).is_some_and(|value| {
            value != &Value::Null && value.as_str().is_none_or(|value| value.trim().is_empty())
        }) {
            push_shape_error(
                diagnostics,
                &format!("build.{key}"),
                "a non-empty string or null",
            );
        }
        if let Some(value) = build
            .get(key)
            .and_then(Value::as_str)
            .map(|value| value.trim().to_string())
        {
            build.insert(key.into(), Value::String(value));
        }
    }
    if build
        .get("allowUnsupportedPlatformFeatures")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(
            diagnostics,
            "build.allowUnsupportedPlatformFeatures",
            "a boolean",
        );
    }
    if build.get("timeoutSeconds").is_some_and(|value| {
        !nonnegative_integer(value).is_some_and(|value| {
            (CONFIG_BUILD_TIMEOUT_MIN_SECONDS..=CONFIG_BUILD_TIMEOUT_MAX_SECONDS).contains(&value)
        })
    }) {
        push_shape_error(
            diagnostics,
            "build.timeoutSeconds",
            "an integer from 60 to 7200",
        );
    }
}

fn validate_placement_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(placement) = object.get_mut("placement") else {
        return;
    };
    let Some(placement) = placement.as_object_mut() else {
        push_shape_error(diagnostics, "placement", "an object");
        return;
    };
    for key in placement
        .keys()
        .filter(|key| !matches!(key.as_str(), "region" | "mode" | "burstable"))
        .cloned()
        .collect::<Vec<_>>()
    {
        push_shape_error(
            diagnostics,
            &format!("placement.{key}"),
            "a supported placement setting",
        );
    }
    if placement
        .get("region")
        .is_some_and(|value| value.as_str().is_none_or(|value| value.trim().is_empty()))
    {
        push_shape_error(diagnostics, "placement.region", "a non-empty string");
    }
    if let Some(region) = placement
        .get("region")
        .and_then(Value::as_str)
        .map(|value| value.trim().to_string())
    {
        placement.insert("region".into(), Value::String(region));
    }
    if placement
        .get("mode")
        .is_some_and(|value| !matches!(value.as_str(), Some("shared" | "dedicated")))
    {
        push_shape_error(diagnostics, "placement.mode", "shared or dedicated");
    }
    if placement
        .get("burstable")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(diagnostics, "placement.burstable", "a boolean");
    }
}

fn validate_superpowers_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(value) = object.get_mut("superpowers") else {
        return;
    };
    let Some(superpowers) = value.as_object_mut() else {
        push_shape_error(diagnostics, "superpowers", "an object");
        return;
    };
    superpowers.retain(|key, _| {
        matches!(
            key.as_str(),
            "enabled"
                | "disable_all_injection"
                | "googleAnalytics"
                | "googleTagManager"
                | "javascriptTags"
                | "tags"
        )
    });
    for key in ["enabled", "disable_all_injection"] {
        if superpowers
            .get(key)
            .is_some_and(|value| !value.is_boolean())
        {
            push_shape_error(diagnostics, &format!("superpowers.{key}"), "a boolean");
        }
    }
    for (key, id) in [
        ("googleAnalytics", "measurementId"),
        ("googleTagManager", "containerId"),
    ] {
        let Some(value) = superpowers.get_mut(key) else {
            continue;
        };
        let Some(integration) = value.as_object_mut() else {
            push_shape_error(diagnostics, &format!("superpowers.{key}"), "an object");
            continue;
        };
        integration.retain(|field, _| field == "enabled" || field == id);
        if integration
            .get("enabled")
            .is_some_and(|value| !value.is_boolean())
        {
            push_shape_error(
                diagnostics,
                &format!("superpowers.{key}.enabled"),
                "a boolean",
            );
        }
        trim_optional_string(
            integration,
            id,
            CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS,
            false,
            &format!("superpowers.{key}.{id}"),
            diagnostics,
        );
    }

    if let Some(value) = superpowers.get_mut("javascriptTags") {
        let Some(tags) = value.as_array_mut() else {
            push_shape_error(diagnostics, "superpowers.javascriptTags", "an array");
            return;
        };
        if tags.len() > CONFIG_INJECT_SNIPPET_LIMIT {
            push_shape_error(
                diagnostics,
                "superpowers.javascriptTags",
                "at most 16 entries",
            );
        }
        for (index, value) in tags.iter_mut().enumerate() {
            let path = format!("superpowers.javascriptTags.{index}");
            let Some(tag) = value.as_object_mut() else {
                push_shape_error(diagnostics, &path, "an object");
                continue;
            };
            tag.retain(|key, _| matches!(key.as_str(), "id" | "name" | "enabled" | "code"));
            trim_optional_string(
                tag,
                "id",
                CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS,
                false,
                &format!("{path}.id"),
                diagnostics,
            );
            trim_optional_string(
                tag,
                "name",
                CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS,
                false,
                &format!("{path}.name"),
                diagnostics,
            );
            if tag.get("enabled").is_some_and(|value| !value.is_boolean()) {
                push_shape_error(diagnostics, &format!("{path}.enabled"), "a boolean");
            }
            if tag
                .get("code")
                .and_then(Value::as_str)
                .is_none_or(|value| js_len(value) > CONFIG_INJECT_SNIPPET_MAX_BYTES)
            {
                push_shape_error(
                    diagnostics,
                    &format!("{path}.code"),
                    "a string up to 8192 bytes",
                );
            }
        }
    }

    let Some(value) = superpowers.get_mut("tags") else {
        return;
    };
    let Some(tags) = value.as_object_mut() else {
        push_shape_error(diagnostics, "superpowers.tags", "an object");
        return;
    };
    tags.retain(|key, _| matches!(key.as_str(), "inheritance" | "requireReview"));
    if tags
        .get("requireReview")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(diagnostics, "superpowers.tags.requireReview", "a boolean");
    }
    let Some(value) = tags.get_mut("inheritance") else {
        return;
    };
    let Some(inheritance) = value.as_object_mut() else {
        push_shape_error(diagnostics, "superpowers.tags.inheritance", "an object");
        return;
    };
    inheritance.retain(|key, _| matches!(key.as_str(), "inherited" | "overrides"));
    if inheritance
        .get("inherited")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(
            diagnostics,
            "superpowers.tags.inheritance.inherited",
            "a boolean",
        );
    }
    let Some(value) = inheritance.get_mut("overrides") else {
        return;
    };
    let Some(overrides) = value.as_array_mut() else {
        push_shape_error(
            diagnostics,
            "superpowers.tags.inheritance.overrides",
            "an array",
        );
        return;
    };
    if overrides.len() > CONFIG_SUPERPOWERS_OVERRIDE_LIMIT {
        push_shape_error(
            diagnostics,
            "superpowers.tags.inheritance.overrides",
            "at most 128 entries",
        );
    }
    for (index, value) in overrides.iter_mut().enumerate() {
        let path = format!("superpowers.tags.inheritance.overrides.{index}");
        let Some(override_value) = value.as_object_mut() else {
            push_shape_error(diagnostics, &path, "an object");
            continue;
        };
        override_value.retain(|key, _| matches!(key.as_str(), "tagId" | "disabled" | "reason"));
        trim_required_string(
            override_value,
            "tagId",
            usize::MAX,
            &format!("{path}.tagId"),
            diagnostics,
        );
        if !override_value.contains_key("disabled") {
            override_value.insert("disabled".into(), Value::Bool(false));
        } else if !override_value["disabled"].is_boolean() {
            push_shape_error(diagnostics, &format!("{path}.disabled"), "a boolean");
        }
        if override_value
            .get("reason")
            .is_some_and(|value| !value.is_null())
        {
            trim_optional_string(
                override_value,
                "reason",
                CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS,
                false,
                &format!("{path}.reason"),
                diagnostics,
            );
        }
    }
}

fn trim_optional_string(
    object: &mut Map<String, Value>,
    key: &str,
    limit: usize,
    require_nonempty: bool,
    path: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(value) = object.get(key) else {
        return;
    };
    let Some(raw) = value.as_str() else {
        push_shape_error(diagnostics, path, "a string");
        return;
    };
    let trimmed = raw.trim();
    if js_len(trimmed) > limit || (require_nonempty && trimmed.is_empty()) {
        push_shape_error(diagnostics, path, "a valid bounded string");
    } else {
        object.insert(key.into(), Value::String(trimmed.into()));
    }
}

fn trim_required_string(
    object: &mut Map<String, Value>,
    key: &str,
    limit: usize,
    path: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    if !object.contains_key(key) {
        push_shape_error(diagnostics, path, "a non-empty string");
        return;
    }
    trim_optional_string(object, key, limit, true, path, diagnostics);
}

fn validate_space_theme(object: &mut Map<String, Value>, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(value) = object.get_mut("theme") else {
        return;
    };
    let Some(theme) = value.as_object_mut() else {
        push_shape_error(diagnostics, "theme", "an object");
        return;
    };
    theme.retain(|key, _| {
        matches!(
            key.as_str(),
            "accent" | "background" | "logo" | "name" | "font" | "hideSpacefastBranding"
        )
    });
    for key in ["accent", "background"] {
        if let Some(value) = theme.get_mut(key) {
            let Some(raw) = value.as_str() else {
                push_shape_error(diagnostics, &format!("theme.{key}"), "a CSS color string");
                continue;
            };
            let trimmed = raw.trim();
            if js_len(trimmed) > CONFIG_PAGES_COLOR_MAX_CHARS
                || !pages_color_pattern().is_match(trimmed)
            {
                push_shape_error(diagnostics, &format!("theme.{key}"), "a safe CSS color");
            } else {
                *value = Value::String(trimmed.into());
            }
        }
    }
    if let Some(value) = theme.get_mut("logo") {
        let Some(raw) = value.as_str() else {
            push_shape_error(
                diagnostics,
                "theme.logo",
                "an https URL or root-relative path",
            );
            return;
        };
        let trimmed = raw.trim();
        if js_len(trimmed) > CONFIG_PAGES_LOGO_MAX_CHARS || !pages_logo_pattern().is_match(trimmed)
        {
            push_shape_error(
                diagnostics,
                "theme.logo",
                "an https URL or root-relative path",
            );
        } else {
            *value = Value::String(trimmed.into());
        }
    }
    validate_trimmed_page_theme_string(
        theme,
        "name",
        CONFIG_PAGES_NAME_MAX_CHARS,
        pages_name_pattern(),
        diagnostics,
    );
    validate_trimmed_page_theme_string(
        theme,
        "font",
        CONFIG_PAGES_FONT_FAMILY_MAX_CHARS,
        pages_font_pattern(),
        diagnostics,
    );
    if theme
        .get("hideSpacefastBranding")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(diagnostics, "theme.hideSpacefastBranding", "a boolean");
    }
}

fn validate_trimmed_page_theme_string(
    theme: &mut Map<String, Value>,
    key: &str,
    limit: usize,
    pattern: &Regex,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(value) = theme.get_mut(key) else {
        return;
    };
    let Some(raw) = value.as_str() else {
        push_shape_error(diagnostics, &format!("theme.{key}"), "a string");
        return;
    };
    let trimmed = raw.trim();
    if trimmed.is_empty() || js_len(trimmed) > limit || !pattern.is_match(trimmed) {
        push_shape_error(
            diagnostics,
            &format!("theme.{key}"),
            "a safe display string",
        );
    } else {
        *value = Value::String(trimmed.into());
    }
}

fn pages_color_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"^(?:#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\([0-9.,\s%/]{1,64}\)|hsla?\([0-9.,\s%deg/]{1,64}\)|[a-zA-Z]{3,30})$")
            .expect("pages color regex compiles")
    })
}

fn pages_logo_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r#"^(?:https://[^\s\"'<>\\]+|/[^\s\"'<>\\]*)$"#)
            .expect("pages logo regex compiles")
    })
}

fn pages_name_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| Regex::new(r"(?s)^.+$").expect("pages name regex compiles"))
}

fn pages_font_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN
        .get_or_init(|| Regex::new(r#"^[A-Za-z0-9 ,'\"-]+$"#).expect("pages font regex compiles"))
}

fn validate_access_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(access) = object.get_mut("access") else {
        return;
    };
    let Some(access) = access.as_object_mut() else {
        push_shape_error(diagnostics, "access", "an object");
        return;
    };
    access.retain(|key, _| key == "rules");
    if !access.contains_key("rules") {
        access.insert("rules".into(), Value::Array(Vec::new()));
    }
    let Some(rules) = access.get_mut("rules").and_then(Value::as_array_mut) else {
        push_shape_error(diagnostics, "access.rules", "an array");
        return;
    };
    if rules.len() > CONFIG_ACCESS_RULE_LIMIT {
        push_shape_error(diagnostics, "access.rules", "at most 100 rules");
    }
    for (index, rule) in rules.iter_mut().enumerate() {
        validate_access_rule(rule, &format!("access.rules.{index}"), diagnostics);
    }
}

fn validate_access_rule(value: &mut Value, path: &str, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(rule) = value.as_object_mut() else {
        push_shape_error(diagnostics, path, "an object");
        return;
    };
    rule.retain(|key, _| {
        matches!(
            key.as_str(),
            "id" | "match"
                | "effect"
                | "auth"
                | "managedBy"
                | "expiresAt"
                | "reasonCode"
                | "message"
        )
    });
    if !rule.contains_key("match") {
        rule.insert("match".into(), Value::Object(Map::new()));
    }
    if !matches!(
        rule.get("effect").and_then(Value::as_str),
        Some("allow" | "deny" | "challenge")
    ) {
        push_shape_error(
            diagnostics,
            &format!("{path}.effect"),
            "allow, deny, or challenge",
        );
    }
    if let Some(value) = rule.get_mut("match") {
        validate_rule_match(value, &format!("{path}.match"), diagnostics);
    }
    if let Some(value) = rule.get_mut("auth") {
        validate_rule_auth(value, &format!("{path}.auth"), diagnostics);
    }
    validate_optional_nonempty(rule, path, "id", diagnostics);
    validate_optional_nonempty(rule, path, "reasonCode", diagnostics);
    validate_optional_nonempty(rule, path, "message", diagnostics);
    if rule.get("managedBy").is_some_and(|value| {
        !matches!(
            value.as_str(),
            Some("sharing" | "firewall" | "file_share" | "cast_reviewer" | "team_default")
        )
    }) {
        push_shape_error(diagnostics, &format!("{path}.managedBy"), "a managed owner");
    }
    if rule
        .get("expiresAt")
        .is_some_and(|value| nonnegative_integer(value).is_none())
    {
        push_shape_error(
            diagnostics,
            &format!("{path}.expiresAt"),
            "a non-negative integer",
        );
    }
}

fn validate_rule_match(value: &mut Value, path: &str, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(object) = value.as_object_mut() else {
        push_shape_error(diagnostics, path, "an object");
        return;
    };
    object.retain(|key, _| {
        matches!(
            key.as_str(),
            "host"
                | "hostPattern"
                | "hostTemplate"
                | "pathPattern"
                | "channel"
                | "ipCidrs"
                | "agent"
                | "country"
                | "header"
        )
    });
    for key in [
        "host",
        "hostPattern",
        "hostTemplate",
        "pathPattern",
        "channel",
        "agent",
    ] {
        validate_optional_nonempty(object, path, key, diagnostics);
    }
    if object
        .get("country")
        .is_some_and(|value| value.as_str().is_none_or(|value| js_len(value) != 2))
    {
        push_shape_error(
            diagnostics,
            &format!("{path}.country"),
            "a two-character string",
        );
    }
    if object.get("ipCidrs").is_some_and(|value| {
        !value
            .as_array()
            .is_some_and(|values| values.len() <= 50 && values.iter().all(nonempty_string))
    }) {
        push_shape_error(
            diagnostics,
            &format!("{path}.ipCidrs"),
            "up to 50 non-empty strings",
        );
    }
    if let Some(value) = object.get_mut("header") {
        let Some(header) = value.as_object_mut() else {
            push_shape_error(
                diagnostics,
                &format!("{path}.header"),
                "a name and string value",
            );
            return;
        };
        header.retain(|key, _| matches!(key.as_str(), "name" | "value"));
        if !header.get("name").is_some_and(nonempty_string)
            || !header.get("value").is_some_and(Value::is_string)
        {
            push_shape_error(
                diagnostics,
                &format!("{path}.header"),
                "a name and string value",
            );
        }
    }
}

fn validate_rule_auth(value: &mut Value, path: &str, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(object) = value.as_object_mut() else {
        push_shape_error(diagnostics, path, "an object");
        return;
    };
    object.retain(|key, _| matches!(key.as_str(), "requiredGrants" | "issuers" | "acquire"));
    let grants_valid = object
        .get("requiredGrants")
        .and_then(Value::as_array)
        .is_some_and(|values| {
            !values.is_empty()
                && values.iter().all(|value| {
                    value
                        .as_str()
                        .is_some_and(|value| !value.is_empty() && js_len(value) <= 255)
                })
        });
    if !grants_valid {
        push_shape_error(
            diagnostics,
            &format!("{path}.requiredGrants"),
            "one or more grants",
        );
    }
    if let Some(value) = object.get_mut("issuers") {
        validate_issuers(value, &format!("{path}.issuers"), diagnostics);
    }
    let Some(value) = object.get_mut("acquire") else {
        return;
    };
    let Some(values) = value.as_array_mut() else {
        push_shape_error(
            diagnostics,
            &format!("{path}.acquire"),
            "valid acquire entries",
        );
        return;
    };
    for (index, value) in values.iter_mut().enumerate() {
        let entry_path = format!("{path}.acquire.{index}");
        let Some(acquire) = value.as_object_mut() else {
            push_shape_error(diagnostics, &entry_path, "an object");
            continue;
        };
        match acquire.get("type").and_then(Value::as_str) {
            Some("password") => {
                acquire.retain(|key, _| {
                    matches!(key.as_str(), "type" | "ref" | "transport" | "username")
                });
                if !acquire.get("ref").is_some_and(nonempty_string) {
                    push_shape_error(
                        diagnostics,
                        &format!("{entry_path}.ref"),
                        "a non-empty string",
                    );
                }
                if !matches!(
                    acquire.get("transport").and_then(Value::as_str),
                    Some("basic" | "form")
                ) {
                    push_shape_error(
                        diagnostics,
                        &format!("{entry_path}.transport"),
                        "basic or form",
                    );
                }
                validate_optional_nonempty(acquire, &entry_path, "username", diagnostics);
            }
            Some("login") => {
                acquire.retain(|key, _| matches!(key.as_str(), "type" | "url" | "label"));
                if !acquire.get("url").is_some_and(nonempty_string) {
                    push_shape_error(
                        diagnostics,
                        &format!("{entry_path}.url"),
                        "a non-empty string",
                    );
                }
                validate_optional_nonempty(acquire, &entry_path, "label", diagnostics);
            }
            _ => push_shape_error(
                diagnostics,
                &format!("{entry_path}.type"),
                "password or login",
            ),
        }
    }
}

fn validate_issuers(value: &mut Value, path: &str, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(issuers) = value.as_array_mut() else {
        push_shape_error(diagnostics, path, "an array");
        return;
    };
    for (index, value) in issuers.iter_mut().enumerate() {
        let issuer_path = format!("{path}.{index}");
        let Some(issuer) = value.as_object_mut() else {
            push_shape_error(diagnostics, &issuer_path, "an object");
            continue;
        };
        issuer.retain(|key, _| {
            matches!(
                key.as_str(),
                "kid" | "alg" | "publicKey" | "grantNamespaces"
            )
        });
        for key in ["kid", "publicKey"] {
            if !issuer.get(key).is_some_and(nonempty_string) {
                push_shape_error(
                    diagnostics,
                    &format!("{issuer_path}.{key}"),
                    "a non-empty string",
                );
            }
        }
        if issuer.get("alg").and_then(Value::as_str) != Some("EdDSA") {
            push_shape_error(diagnostics, &format!("{issuer_path}.alg"), "EdDSA");
        }
        if !issuer
            .get("grantNamespaces")
            .and_then(Value::as_array)
            .is_some_and(|values| !values.is_empty() && values.iter().all(nonempty_string))
        {
            push_shape_error(
                diagnostics,
                &format!("{issuer_path}.grantNamespaces"),
                "one or more non-empty strings",
            );
        }
    }
}

fn validate_optional_nonempty(
    object: &Map<String, Value>,
    path: &str,
    key: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    if object.get(key).is_some_and(|value| !nonempty_string(value)) {
        push_shape_error(diagnostics, &format!("{path}.{key}"), "a non-empty string");
    }
}

fn nonempty_string(value: &Value) -> bool {
    value.as_str().is_some_and(|value| !value.is_empty())
}

fn nonnegative_integer(value: &Value) -> Option<u64> {
    if let Some(value) = value.as_u64() {
        return Some(value);
    }
    let value = value.as_f64()?;
    (value.is_finite() && value >= 0.0 && value.fract() == 0.0 && value <= u64::MAX as f64)
        .then_some(value as u64)
}

fn js_len(value: &str) -> usize {
    value.encode_utf16().count()
}

/// Public editor schema emitted through the generated routing protocol.
/// Validation still runs through `parse_config`; this artifact only describes
/// that Rust-owned contract for autocomplete and OpenAPI consumers.
pub fn public_json_schema() -> Value {
    let closed_object = |properties: Value| {
        json!({
            "type": "object",
            "properties": properties,
            "additionalProperties": false
        })
    };
    let nullable_build_string = || {
        json!({
            "oneOf": [
                { "type": "string", "pattern": "\\S" },
                { "type": "null" }
            ]
        })
    };
    let integration = |id: &str| {
        closed_object(json!({
            "enabled": { "type": "boolean" },
            (id): {
                "type": "string",
                "maxLength": CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS
            }
        }))
    };
    let superpowers = closed_object(json!({
        "enabled": { "type": "boolean" },
        "disable_all_injection": { "type": "boolean" },
        "googleAnalytics": integration("measurementId"),
        "googleTagManager": integration("containerId"),
        "javascriptTags": {
            "type": "array",
            "maxItems": CONFIG_INJECT_SNIPPET_LIMIT,
            "items": {
                "type": "object",
                "required": ["code"],
                "properties": {
                    "id": {
                        "type": "string",
                        "maxLength": CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS
                    },
                    "name": {
                        "type": "string",
                        "maxLength": CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS
                    },
                    "enabled": { "type": "boolean" },
                    "code": {
                        "type": "string",
                        "maxLength": CONFIG_INJECT_SNIPPET_MAX_BYTES
                    }
                },
                "additionalProperties": false
            }
        },
        "tags": closed_object(json!({
            "inheritance": closed_object(json!({
                "inherited": { "type": "boolean" },
                "overrides": {
                    "type": "array",
                    "maxItems": CONFIG_SUPERPOWERS_OVERRIDE_LIMIT,
                    "items": {
                        "type": "object",
                        "required": ["tagId"],
                        "properties": {
                            "tagId": { "type": "string", "minLength": 1 },
                            "disabled": { "type": "boolean" },
                            "reason": {
                                "oneOf": [
                                    {
                                        "type": "string",
                                        "maxLength": CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS
                                    },
                                    { "type": "null" }
                                ]
                            }
                        },
                        "additionalProperties": false
                    }
                }
            })),
            "requireReview": { "type": "boolean" }
        }))
    }));

    let theme = closed_object(json!({
        "accent": {
            "type": "string",
            "maxLength": CONFIG_PAGES_COLOR_MAX_CHARS,
            "pattern": "^(?:#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\\([0-9.,\\s%/]{1,64}\\)|hsla?\\([0-9.,\\s%deg/]{1,64}\\)|[a-zA-Z]{3,30})$"
        },
        "background": {
            "type": "string",
            "maxLength": CONFIG_PAGES_COLOR_MAX_CHARS,
            "pattern": "^(?:#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\\([0-9.,\\s%/]{1,64}\\)|hsla?\\([0-9.,\\s%deg/]{1,64}\\)|[a-zA-Z]{3,30})$"
        },
        "logo": {
            "type": "string",
            "maxLength": CONFIG_PAGES_LOGO_MAX_CHARS,
            "pattern": "^(?:https://[^\\s\\\"'<>\\\\]+|/[^\\s\\\"'<>\\\\]*)$"
        },
        "name": {
            "type": "string",
            "minLength": 1,
            "maxLength": CONFIG_PAGES_NAME_MAX_CHARS
        },
        "font": {
            "type": "string",
            "minLength": 1,
            "maxLength": CONFIG_PAGES_FONT_FAMILY_MAX_CHARS,
            "pattern": "^[A-Za-z0-9 ,'\\\"-]+$"
        },
        "hideSpacefastBranding": { "type": "boolean" }
    }));

    let build = closed_object(json!({
        "rootDirectory": nullable_build_string(),
        "installDirectory": nullable_build_string(),
        "installCommand": nullable_build_string(),
        "buildCommand": nullable_build_string(),
        "outputDirectory": nullable_build_string(),
        "ignoredBuildCommand": nullable_build_string(),
        "frameworkPreset": nullable_build_string(),
        "platformPreset": nullable_build_string(),
        "allowUnsupportedPlatformFeatures": { "type": "boolean" },
        "timeoutSeconds": {
            "type": "integer",
            "minimum": CONFIG_BUILD_TIMEOUT_MIN_SECONDS,
            "maximum": CONFIG_BUILD_TIMEOUT_MAX_SECONDS
        }
    }));

    let placement = closed_object(json!({
        "region": { "type": "string", "pattern": "\\S" },
        "mode": { "enum": ["shared", "dedicated"] },
        "burstable": { "type": "boolean" }
    }));

    let rule_match = closed_object(json!({
        "host": { "type": "string", "minLength": 1 },
        "hostPattern": { "type": "string", "minLength": 1 },
        "hostTemplate": { "type": "string", "minLength": 1 },
        "pathPattern": { "type": "string", "minLength": 1 },
        "channel": { "type": "string", "minLength": 1 },
        "ipCidrs": {
            "type": "array",
            "maxItems": 50,
            "items": { "type": "string", "minLength": 1 }
        },
        "agent": { "type": "string", "minLength": 1 },
        "country": { "type": "string", "minLength": 2, "maxLength": 2 },
        "header": {
            "type": "object",
            "required": ["name", "value"],
            "properties": {
                "name": { "type": "string", "minLength": 1 },
                "value": { "type": "string" }
            },
            "additionalProperties": false
        }
    }));
    let mut rule_auth = closed_object(json!({
        "requiredGrants": {
            "type": "array",
            "minItems": 1,
            "items": { "type": "string", "minLength": 1, "maxLength": 255 }
        },
        "issuers": {
            "type": "array",
            "items": {
                "type": "object",
                "required": ["kid", "alg", "publicKey", "grantNamespaces"],
                "properties": {
                    "kid": { "type": "string", "minLength": 1 },
                    "alg": { "const": "EdDSA" },
                    "publicKey": { "type": "string", "minLength": 1 },
                    "grantNamespaces": {
                        "type": "array",
                        "minItems": 1,
                        "items": { "type": "string", "minLength": 1 }
                    }
                },
                "additionalProperties": false
            }
        },
        "acquire": {
            "type": "array",
            "items": {
                "oneOf": [
                    {
                        "type": "object",
                        "required": ["type", "ref", "transport"],
                        "properties": {
                            "type": { "const": "password" },
                            "ref": { "type": "string", "minLength": 1 },
                            "transport": { "enum": ["basic", "form"] },
                            "username": { "type": "string", "minLength": 1 }
                        },
                        "additionalProperties": false
                    },
                    {
                        "type": "object",
                        "required": ["type", "url"],
                        "properties": {
                            "type": { "const": "login" },
                            "url": { "type": "string", "minLength": 1 },
                            "label": { "type": "string", "minLength": 1 }
                        },
                        "additionalProperties": false
                    }
                ]
            }
        }
    }));
    if let Some(rule_auth) = rule_auth.as_object_mut() {
        rule_auth.insert("required".into(), json!(["requiredGrants"]));
    }
    let access = closed_object(json!({
        "rules": {
            "type": "array",
            "maxItems": CONFIG_ACCESS_RULE_LIMIT,
            "items": {
                "type": "object",
                "required": ["effect"],
                "properties": {
                    "id": { "type": "string", "minLength": 1 },
                    "match": rule_match,
                    "effect": { "enum": ["allow", "deny", "challenge"] },
                    "auth": rule_auth,
                    "managedBy": {
                        "enum": ["sharing", "firewall", "file_share", "cast_reviewer", "team_default"]
                    },
                    "expiresAt": { "type": "integer", "minimum": 0 },
                    "reasonCode": { "type": "string", "minLength": 1 },
                    "message": { "type": "string", "minLength": 1 }
                },
                "additionalProperties": false
            }
        }
    }));

    json!({
        "$schema": "http://json-schema.org/draft-07/schema#",
        "$id": "https://spacefast.com/schemas/sf.json",
        "title": "Spacefast space configuration (sf.jsonc)",
        "type": "object",
        "properties": {
            "$schema": { "type": "string" },
            "space": { "type": "string", "minLength": 1 },
            "index": { "oneOf": [{ "type": "string", "minLength": 1 }, { "const": false }] },
            "fallback": {
                "oneOf": [
                    { "type": "string", "minLength": 1 },
                    {
                        "type": "object",
                        "required": ["path", "status"],
                        "properties": {
                            "path": { "type": "string", "minLength": 1 },
                            "status": {
                                "type": "integer",
                                "minimum": CONFIG_FALLBACK_STATUS_MIN,
                                "maximum": CONFIG_FALLBACK_STATUS_MAX
                            }
                        },
                        "additionalProperties": false
                    }
                ]
            },
            "cleanUrls": { "type": "boolean" },
            "listing": { "type": "boolean" },
            "markdownNegotiation": { "type": "boolean" },
            "meta": {
                "type": "object",
                "properties": {
                    "title": { "type": "string", "minLength": 1, "maxLength": CONFIG_META_TITLE_MAX_CHARS },
                    "description": { "type": "string", "maxLength": CONFIG_META_DESCRIPTION_MAX_CHARS },
                    "image": { "type": "string", "maxLength": CONFIG_META_IMAGE_MAX_CHARS }
                },
                "additionalProperties": false
            },
            "templates": {
                "type": "array",
                "maxItems": CONFIG_TEMPLATE_LIMIT,
                "items": { "type": "string", "minLength": 1 }
            },
            "superpowers": superpowers,
            "theme": theme,
            "build": build,
            "placement": placement,
            "access": access
        },
        "additionalProperties": true
    })
}

/// Generated TypeScript structures for the normalized config returned by the
/// Rust ABI. These are declarations only; no TypeScript parser or validator is
/// generated alongside them.
pub const TYPESCRIPT_CONFIG_TYPES: &str = r#"
export type SpaceTheme = {
  accent?: string;
  background?: string;
  logo?: string;
  name?: string;
  font?: string;
  hideSpacefastBranding?: boolean;
};

export type SpaceBuildSettings = {
  rootDirectory?: string | null;
  installDirectory?: string | null;
  installCommand?: string | null;
  buildCommand?: string | null;
  outputDirectory?: string | null;
  ignoredBuildCommand?: string | null;
  frameworkPreset?: string | null;
  platformPreset?: string | null;
  allowUnsupportedPlatformFeatures?: boolean;
  timeoutSeconds?: number;
};

export type SpacePlacementConfig = {
  region?: string;
  mode?: "shared" | "dedicated";
  burstable?: boolean;
};

export type SpaceConfig = {
  index?: string | false;
  fallback?: string | { path: string; status: number };
  cleanUrls?: boolean;
  listing?: boolean;
  meta?: { title?: string; description?: string; image?: string };
  superpowers?: {
    enabled?: boolean;
    disable_all_injection?: boolean;
    googleAnalytics?: { enabled?: boolean; measurementId?: string };
    googleTagManager?: { enabled?: boolean; containerId?: string };
    javascriptTags?: Array<{ id?: string; name?: string; enabled?: boolean; code: string }>;
    tags?: {
      inheritance?: {
        inherited?: boolean;
        overrides?: Array<{ tagId: string; disabled?: boolean; reason?: string | null }>;
      };
      requireReview?: boolean;
    };
  };
  templates?: string[];
  theme?: SpaceTheme;
  build?: SpaceBuildSettings;
  placement?: SpacePlacementConfig;
  markdownNegotiation?: boolean;
  experimental_gutenberg?: boolean;
  inject?: { head?: string[]; bodyStart?: string[]; bodyEnd?: string[]; noscript?: string[] };
};

export type SpaceConfigFile = SpaceConfig & {
  $schema?: string;
  space?: string;
  access?: { rules?: unknown[] };
};
"#;

fn push_shape_error(diagnostics: &mut Vec<PrepareDiagnostic>, path: &str, expected: &str) {
    diagnostics.push(diagnostic(
        DiagnosticSeverity::Error,
        "config_invalid",
        format!("Expected {expected}"),
        Some(path.into()),
    ));
}

#[cfg(test)]
mod tests {
    use super::public_json_schema;
    use crate::protocol::{CONFIG_BUILD_TIMEOUT_MAX_SECONDS, CONFIG_SUPERPOWERS_OVERRIDE_LIMIT};
    use serde_json::json;

    #[test]
    fn public_schema_describes_nested_config_features_and_limits() {
        let schema = public_json_schema();

        assert_eq!(
            schema.pointer("/properties/build/properties/timeoutSeconds/maximum"),
            Some(&json!(CONFIG_BUILD_TIMEOUT_MAX_SECONDS))
        );
        assert_eq!(
            schema.pointer(
                "/properties/superpowers/properties/tags/properties/inheritance/properties/overrides/maxItems"
            ),
            Some(&json!(CONFIG_SUPERPOWERS_OVERRIDE_LIMIT))
        );
        assert_eq!(
            schema.pointer("/properties/theme/properties/hideSpacefastBranding/type"),
            Some(&json!("boolean"))
        );
        assert_eq!(
            schema.pointer(
                "/properties/access/properties/rules/items/properties/auth/properties/acquire/items/oneOf/0/properties/transport/enum"
            ),
            Some(&json!(["basic", "form"]))
        );
        assert_eq!(
            schema.pointer("/properties/theme/properties/logo/type"),
            Some(&json!("string"))
        );
    }

    #[test]
    fn public_schema_keeps_hidden_runtime_config_undocumented() {
        let schema = public_json_schema();
        let properties = schema["properties"]
            .as_object()
            .expect("schema properties are an object");

        assert!(!properties.contains_key("experimental_gutenberg"));
        assert!(!properties.contains_key("inject"));
        assert_eq!(schema["additionalProperties"], json!(true));
    }
}
