//! Validation and normalization for the current Spacefast config schema.

use super::access::normalized_public_patterns;
use super::diagnostics::{diagnostic, DiagnosticSeverity, PrepareDiagnostic};
use super::jsonc::parse as parse_jsonc;
use crate::protocol::{
    CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT, CONFIG_BUILD_TIMEOUT_MAX_SECONDS,
    CONFIG_BUILD_TIMEOUT_MIN_SECONDS, CONFIG_FALLBACK_STATUS_MAX, CONFIG_FALLBACK_STATUS_MIN,
    CONFIG_FILE_MAX_BYTES, CONFIG_INJECT_SNIPPET_LIMIT, CONFIG_INJECT_SNIPPET_MAX_BYTES,
    CONFIG_META_DESCRIPTION_MAX_CHARS, CONFIG_META_IMAGE_MAX_CHARS, CONFIG_META_TITLE_MAX_CHARS,
    CONFIG_PAGES_COLOR_MAX_CHARS, CONFIG_PAGES_FONT_FAMILY_MAX_CHARS, CONFIG_PAGES_LOGO_MAX_CHARS,
    CONFIG_PAGES_NAME_MAX_CHARS, CONFIG_SPACE_NAME_MAX_CHARS,
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
    // Routing rules in the same grammar the `_redirects` / `_headers` files
    // use. They pass through this lane untouched: the strict v1 compiler
    // validates them and the routing compiler merges them behind the files.
    "headers",
    "index",
    "inject",
    "listing",
    "markdownNegotiation",
    "meta",
    "name",
    "placement",
    "redirects",
    "rewrites",
    "runtime",
    "space",
    "superpowers",
    "templates",
    "theme",
];

const RUNTIME_KINDS: &[&str] = &["zero", "functions"];

// Replaced by the `runtime` declaration; never silently ignored like other
// unknown keys, because it is the one key whose loss changes what gets served.
const REMOVED_FUNCTIONS_KEY: &str = "functions";

/// Other platforms' routing vocabulary. We have one matcher grammar; naming it
/// three more ways would be three more things to keep in sync.
const ROUTING_CONFIG_KEYS: &[&str] = &["proxies", "proxy", "routes"];

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
        let (severity, code, message) = if key == REMOVED_FUNCTIONS_KEY {
            // The runtime declaration replaced this key outright: warning-and-
            // strip would publish static files under a config that asked for a
            // worker, so it blocks with its own code instead.
            (
                DiagnosticSeverity::Error,
                "config_functions_key_removed",
                format!(
                    "The \"{REMOVED_FUNCTIONS_KEY}\" key was removed from {path}; declare runtime.kind \"functions\" instead."
                ),
            )
        } else if ROUTING_CONFIG_KEYS.contains(&key.as_str()) {
            (
                DiagnosticSeverity::Error,
                "config_invalid",
                format!("Routing never lives in {path}; move \"{key}\" into _redirects/_headers."),
            )
        } else {
            (
                DiagnosticSeverity::Warning,
                "config_invalid",
                format!("Unknown {path} key \"{key}\" was ignored."),
            )
        };
        diagnostics.push(diagnostic(severity, code, message, Some(key.clone())));
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
    if let Some(name) = object.get("name").and_then(Value::as_str) {
        // JavaScript String.length over the trimmed value, matching the stored
        // column and the shared contract's cap.
        let length = js_len(name.trim());
        if length > CONFIG_SPACE_NAME_MAX_CHARS {
            let mut item = diagnostic(
                DiagnosticSeverity::Error,
                "config_name_too_long",
                format!("name supports up to {CONFIG_SPACE_NAME_MAX_CHARS} characters."),
                Some("name".into()),
            );
            item.details.insert("length".into(), Value::from(length));
            item.details
                .insert("limit".into(), Value::from(CONFIG_SPACE_NAME_MAX_CHARS));
            diagnostics.push(item);
        }
    }
    if let Some(meta) = object.get("meta").and_then(Value::as_object) {
        for (key, limit) in [
            ("title", CONFIG_META_TITLE_MAX_CHARS),
            ("description", CONFIG_META_DESCRIPTION_MAX_CHARS),
            ("image", CONFIG_META_IMAGE_MAX_CHARS),
            ("favicon", CONFIG_META_IMAGE_MAX_CHARS),
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
            meta.retain(|key, _| {
                matches!(key.as_str(), "title" | "description" | "image" | "favicon")
            });
            for key in ["title", "description", "image", "favicon"] {
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
    validate_space_name(object, diagnostics);
    validate_runtime_config(object, diagnostics);
    validate_build_config(object, diagnostics);
    validate_access_config(object, diagnostics);
    validate_placement_config(object, diagnostics);
    validate_superpowers_config(object, diagnostics);
    validate_space_theme(object, diagnostics);
    if let Some(inject) = object.get_mut("inject").and_then(Value::as_object_mut) {
        inject
            .retain(|key, _| matches!(key.as_str(), "head" | "bodyStart" | "bodyEnd" | "noscript"));
    }
}

/// File-owned space display name. The cap itself is reported by
/// `validate_config_caps` with the stable `config_name_too_long` code; this
/// only rejects shapes that can never name a space and stores the trimmed
/// value so the compiled config carries exactly what gets stamped.
fn validate_space_name(object: &mut Map<String, Value>, diagnostics: &mut Vec<PrepareDiagnostic>) {
    let Some(value) = object.get_mut("name") else {
        return;
    };
    let Some(raw) = value.as_str() else {
        push_shape_error(diagnostics, "name", "a non-empty string");
        return;
    };
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        push_shape_error(diagnostics, "name", "a non-empty string");
        return;
    }
    *value = Value::String(trimmed.into());
}

/// The one runtime declaration. Blocking on purpose: once a version's config
/// declares a runtime, publishing static files under it would be a silent lie,
/// so an unusable declaration fails the publish instead of degrading.
fn validate_runtime_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(value) = object.get_mut("runtime") else {
        return;
    };
    let Some(runtime) = value.as_object_mut() else {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_runtime_invalid_kind",
            "runtime must be an object declaring a kind of \"zero\" or \"functions\".".into(),
            Some("runtime".into()),
        ));
        return;
    };
    let declared = runtime.get("kind").and_then(Value::as_str);
    let Some(kind) = declared
        .filter(|kind| RUNTIME_KINDS.contains(kind))
        .map(str::to_owned)
    else {
        let mut item = diagnostic(
            DiagnosticSeverity::Error,
            "config_runtime_invalid_kind",
            "runtime.kind must be \"zero\" or \"functions\".".into(),
            Some("runtime.kind".into()),
        );
        if let Some(declared) = declared {
            item.details.insert("kind".into(), Value::from(declared));
        }
        diagnostics.push(item);
        return;
    };

    if kind == "zero" {
        runtime.retain(|key, _| matches!(key.as_str(), "kind" | "server" | "client"));
        // Zero is never inferred, so a declaration that names neither entry has
        // nothing to compile.
        for key in ["server", "client"] {
            let trimmed = runtime
                .get(key)
                .and_then(Value::as_str)
                .map(str::trim)
                .filter(|entry| !entry.is_empty())
                .map(str::to_owned);
            match trimmed {
                Some(entry) => {
                    runtime.insert(key.into(), Value::String(entry));
                }
                None => diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "config_runtime_entry_missing",
                    format!(
                        "runtime.{key} is required and must name a module when runtime.kind is \"zero\"."
                    ),
                    Some(format!("runtime.{key}")),
                )),
            }
        }
        return;
    }

    runtime.retain(|key, _| {
        matches!(
            key.as_str(),
            "kind" | "entry" | "database" | "compatibilityDate"
        )
    });
    // Functions keeps zero-config detection: `entry` is optional, but naming one
    // that cannot be a module is an error rather than a silent static publish.
    if runtime
        .get("entry")
        .is_some_and(|value| value.as_str().is_none_or(|entry| entry.trim().is_empty()))
    {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_runtime_entry_missing",
            "runtime.entry must name a module when present; omit it to detect the worker instead."
                .into(),
            Some("runtime.entry".into()),
        ));
    }
    if runtime
        .get("database")
        .is_some_and(|value| !value.is_boolean())
    {
        push_shape_error(diagnostics, "runtime.database", "a boolean");
    }
    if runtime.get("compatibilityDate").is_some_and(|value| {
        value
            .as_str()
            .is_none_or(|date| !compatibility_date_pattern().is_match(date))
    }) {
        push_shape_error(
            diagnostics,
            "runtime.compatibilityDate",
            "a YYYY-MM-DD date",
        );
    }
}

fn validate_access_config(
    object: &mut Map<String, Value>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) {
    let Some(access) = object.get_mut("access") else {
        return;
    };
    match normalized_public_patterns(access) {
        Ok(patterns) => {
            *access = json!({ "public": patterns });
        }
        Err((path, message)) => diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_invalid",
            message,
            Some(path),
        )),
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

fn compatibility_date_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"^\d{4}-\d{2}-\d{2}$").expect("compatibility date regex compiles")
    })
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

    // Runtime is a documented public key, not a hidden one: the published docs
    // teach `sf.jsonc#runtime`, so editors and agents get it too.
    let runtime = json!({
        "oneOf": [
            {
                "type": "object",
                "required": ["kind", "server", "client"],
                "properties": {
                    "kind": { "const": "zero" },
                    "server": { "type": "string", "minLength": 1 },
                    "client": { "type": "string", "minLength": 1 }
                },
                "additionalProperties": false
            },
            {
                "type": "object",
                "required": ["kind"],
                "properties": {
                    "kind": { "const": "functions" },
                    "entry": { "type": "string", "minLength": 1 },
                    "database": { "type": "boolean" },
                    "compatibilityDate": { "type": "string", "pattern": "^\\d{4}-\\d{2}-\\d{2}$" }
                },
                "additionalProperties": false
            }
        ]
    });

    json!({
        "$schema": "http://json-schema.org/draft-07/schema#",
        "$id": "https://spacefast.com/schemas/sf.json",
        "title": "Spacefast space configuration (sf.jsonc)",
        "type": "object",
        "properties": {
            "$schema": { "type": "string" },
            "space": { "type": "string", "minLength": 1 },
            "name": {
                "type": "string",
                "minLength": 1,
                "maxLength": CONFIG_SPACE_NAME_MAX_CHARS
            },
            "runtime": runtime,
            "access": {
                "anyOf": [
                    {
                        "const": "public",
                        "description": "Shorthand for { \"public\": [\"/**\"] }: the whole live space is public."
                    },
                    {
                        "type": "object",
                        "required": ["public"],
                        "properties": {
                            "public": {
                                "type": "array",
                                "maxItems": CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT,
                                "items": { "type": "string", "minLength": 1, "maxLength": 1025 }
                            }
                        },
                        "additionalProperties": false
                    }
                ]
            },
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
                    "image": { "type": "string", "maxLength": CONFIG_META_IMAGE_MAX_CHARS },
                    "favicon": { "type": "string", "maxLength": CONFIG_META_IMAGE_MAX_CHARS }
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
            "placement": placement
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
  meta?: { title?: string; description?: string; image?: string; favicon?: string };
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

export type SpaceRuntimeZeroConfig = {
  kind: "zero";
  server: string;
  client: string;
};

export type SpaceRuntimeFunctionsConfig = {
  kind: "functions";
  entry?: string;
  database?: boolean;
  compatibilityDate?: string;
};

export type SpaceRuntimeConfig = SpaceRuntimeZeroConfig | SpaceRuntimeFunctionsConfig;

export type SpaceConfigFile = SpaceConfig & {
  $schema?: string;
  space?: string;
  name?: string;
  runtime?: SpaceRuntimeConfig;
  access?: { public: string[] };
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
    use super::DiagnosticSeverity;
    use super::{parse_config, public_json_schema};
    use crate::protocol::{
        CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT, CONFIG_BUILD_TIMEOUT_MAX_SECONDS,
        CONFIG_SPACE_NAME_MAX_CHARS, CONFIG_SUPERPOWERS_OVERRIDE_LIMIT,
    };
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
            schema.pointer("/properties/theme/properties/logo/type"),
            Some(&json!("string"))
        );
        assert_eq!(
            schema.pointer("/properties/access/anyOf/0/const"),
            Some(&json!("public"))
        );
        assert_eq!(
            schema.pointer("/properties/access/anyOf/1/properties/public/maxItems"),
            Some(&json!(CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT))
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

    #[test]
    fn public_schema_documents_file_owned_name_and_runtime() {
        let schema = public_json_schema();

        assert_eq!(
            schema.pointer("/properties/name/maxLength"),
            Some(&json!(CONFIG_SPACE_NAME_MAX_CHARS))
        );
        assert_eq!(
            schema.pointer("/properties/runtime/oneOf/0/properties/kind/const"),
            Some(&json!("zero"))
        );
        assert_eq!(
            schema.pointer("/properties/runtime/oneOf/0/required"),
            Some(&json!(["kind", "server", "client"]))
        );
        assert_eq!(
            schema.pointer("/properties/runtime/oneOf/1/properties/kind/const"),
            Some(&json!("functions"))
        );
        assert_eq!(
            schema.pointer("/properties/runtime/oneOf/1/required"),
            Some(&json!(["kind"]))
        );
    }

    #[test]
    fn committed_config_keeps_name_and_runtime_declarations() {
        let mut diagnostics = Vec::new();
        let config = parse_config(
            r#"{
                "name": "  guestbook  ",
                "runtime": { "kind": "zero", "server": " server/index.ts ", "client": "client/index.tsx" },
                "meta": { "title": "Guestbook", "favicon": "/favicon.svg" },
                "listing": true
            }"#,
            "sf.jsonc",
            &mut diagnostics,
        );

        assert_eq!(diagnostics, Vec::new());
        assert_eq!(
            config,
            Some(json!({
                "name": "guestbook",
                "runtime": {
                    "kind": "zero",
                    "server": "server/index.ts",
                    "client": "client/index.tsx"
                },
                "meta": { "title": "Guestbook", "favicon": "/favicon.svg" },
                "listing": true
            }))
        );
    }

    #[test]
    fn functions_runtime_keeps_optional_entries_and_drops_unknown_fields() {
        let mut diagnostics = Vec::new();
        let config = parse_config(
            r#"{"runtime":{"kind":"functions","entry":"handler.ts","database":true,"compatibilityDate":"2026-07-01","bogus":1}}"#,
            "sf.jsonc",
            &mut diagnostics,
        );

        assert_eq!(diagnostics, Vec::new());
        assert_eq!(
            config.and_then(|value| value.pointer("/runtime").cloned()),
            Some(json!({
                "kind": "functions",
                "entry": "handler.ts",
                "database": true,
                "compatibilityDate": "2026-07-01"
            }))
        );

        let mut bare_diagnostics = Vec::new();
        let bare = parse_config(
            r#"{"runtime":{"kind":"functions"}}"#,
            "sf.jsonc",
            &mut bare_diagnostics,
        );
        assert_eq!(bare_diagnostics, Vec::new());
        assert_eq!(
            bare.and_then(|value| value.pointer("/runtime").cloned()),
            Some(json!({ "kind": "functions" }))
        );
    }

    #[test]
    fn invalid_runtime_and_name_declarations_block_the_publish() {
        let cases = [
            (
                r#"{"runtime":{"kind":"zero"}}"#,
                "config_runtime_entry_missing",
            ),
            (
                r#"{"runtime":{"kind":"zero","server":"server.ts","client":"   "}}"#,
                "config_runtime_entry_missing",
            ),
            (
                r#"{"runtime":{"kind":"functions","entry":"  "}}"#,
                "config_runtime_entry_missing",
            ),
            (
                r#"{"runtime":{"kind":"wordpress"}}"#,
                "config_runtime_invalid_kind",
            ),
            (r#"{"runtime":{}}"#, "config_runtime_invalid_kind"),
            (r#"{"runtime":"functions"}"#, "config_runtime_invalid_kind"),
            (
                r#"{"runtime":["functions"]}"#,
                "config_runtime_invalid_kind",
            ),
            (
                r#"{"functions":{"entry":"handler.ts"}}"#,
                "config_functions_key_removed",
            ),
        ];
        for (raw, code) in cases {
            let mut diagnostics = Vec::new();
            assert_eq!(
                parse_config(raw, "sf.jsonc", &mut diagnostics),
                None,
                "{raw}"
            );
            assert!(
                diagnostics
                    .iter()
                    .any(|item| item.severity == DiagnosticSeverity::Error && item.code == code),
                "{raw} -> {diagnostics:?}"
            );
        }

        let long_name = "n".repeat(CONFIG_SPACE_NAME_MAX_CHARS + 1);
        let mut diagnostics = Vec::new();
        assert_eq!(
            parse_config(
                &format!(r#"{{"name":"{long_name}"}}"#),
                "sf.jsonc",
                &mut diagnostics
            ),
            None
        );
        let issue = diagnostics
            .iter()
            .find(|item| item.code == "config_name_too_long")
            .expect("name cap reports config_name_too_long");
        assert_eq!(issue.severity, DiagnosticSeverity::Error);
        assert_eq!(
            issue.details.get("limit"),
            Some(&json!(CONFIG_SPACE_NAME_MAX_CHARS))
        );

        for raw in [r#"{"name":"   "}"#, r#"{"name":42}"#] {
            let mut diagnostics = Vec::new();
            assert_eq!(
                parse_config(raw, "sf.jsonc", &mut diagnostics),
                None,
                "{raw}"
            );
            assert!(diagnostics
                .iter()
                .any(|item| item.severity == DiagnosticSeverity::Error
                    && item.path.as_deref() == Some("name")));
        }
    }

    #[test]
    fn the_removed_functions_key_blocks_while_other_unknown_keys_still_warn() {
        let mut diagnostics = Vec::new();
        assert_eq!(
            parse_config(
                r#"{"functions":{"entry":"handler.ts"},"somethingElse":1}"#,
                "sf.jsonc",
                &mut diagnostics
            ),
            None
        );

        let removed = diagnostics
            .iter()
            .find(|item| item.path.as_deref() == Some("functions"))
            .expect("the removed functions key is diagnosed");
        assert_eq!(removed.severity, DiagnosticSeverity::Error);
        assert_eq!(removed.code, "config_functions_key_removed");
        assert_eq!(
            removed.message,
            "The \"functions\" key was removed from sf.jsonc; declare runtime.kind \"functions\" instead."
        );

        let unknown = diagnostics
            .iter()
            .find(|item| item.path.as_deref() == Some("somethingElse"))
            .expect("unrelated unknown keys are still diagnosed");
        assert_eq!(unknown.severity, DiagnosticSeverity::Warning);
        assert_eq!(unknown.code, "config_invalid");
    }

    #[test]
    fn runtime_functions_rejects_malformed_optional_fields() {
        for raw in [
            r#"{"runtime":{"kind":"functions","database":"yes"}}"#,
            r#"{"runtime":{"kind":"functions","compatibilityDate":"2026-7-1"}}"#,
            r#"{"runtime":{"kind":"functions","compatibilityDate":true}}"#,
        ] {
            let mut diagnostics = Vec::new();
            assert_eq!(
                parse_config(raw, "sf.jsonc", &mut diagnostics),
                None,
                "{raw}"
            );
            assert!(
                diagnostics
                    .iter()
                    .any(|item| item.severity == DiagnosticSeverity::Error),
                "{raw}"
            );
        }
    }

    #[test]
    fn committed_config_preserves_normalized_public_access_patterns() {
        let mut diagnostics = Vec::new();
        let config = parse_config(
            r#"{"access":{"public":["/assets//**","!/assets/private/**"]}}"#,
            "sf.jsonc",
            &mut diagnostics,
        );

        assert!(diagnostics.is_empty());
        assert_eq!(
            config.and_then(|value| value.pointer("/access/public").cloned()),
            Some(json!(["/assets/**", "!/assets/private/**"]))
        );
    }

    #[test]
    fn committed_config_normalizes_the_public_shorthand_to_everything() {
        let mut diagnostics = Vec::new();
        let config = parse_config(r#"{"access":"public"}"#, "sf.jsonc", &mut diagnostics);

        assert!(diagnostics.is_empty());
        assert_eq!(
            config.and_then(|value| value.pointer("/access/public").cloned()),
            Some(json!(["/**"]))
        );

        let mut diagnostics = Vec::new();
        assert_eq!(
            parse_config(r#"{"access":"private"}"#, "sf.jsonc", &mut diagnostics),
            None
        );
        assert!(diagnostics
            .iter()
            .any(|item| item.severity == DiagnosticSeverity::Error
                && item.message.contains("shorthand")));
    }

    #[test]
    fn public_access_rejects_ambiguous_patterns() {
        for raw in [
            r#"{"access":{"public":["!/private/**"]}}"#,
            r#"{"access":{"public":["/docs/*.html"]}}"#,
            r#"{"access":{"public":["/docs/**","/docs/**"]}}"#,
        ] {
            let mut diagnostics = Vec::new();
            assert_eq!(parse_config(raw, "sf.jsonc", &mut diagnostics), None);
            assert!(diagnostics
                .iter()
                .any(|item| item.severity == DiagnosticSeverity::Error));
        }
    }
}
