//! Strict `sf.jsonc` v1 compiler. This is the same pure parser exposed to the
//! CLI through WASM; filesystem containment remains a CLI responsibility.

use super::access::{normalized_public_patterns, CONFIG_ACCESS_PUBLIC_SOURCE_REFERENCE};
use super::jsonc::parse_document;
use crate::protocol::{
    CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT, CONFIG_ACCESS_RESOURCE_PATTERN_MAX_CHARS,
    CONFIG_BUILD_TIMEOUT_MAX_SECONDS, CONFIG_BUILD_TIMEOUT_MIN_SECONDS, CONFIG_FILE_MAX_BYTES,
    CONFIG_META_DESCRIPTION_MAX_CHARS, CONFIG_META_IMAGE_MAX_CHARS, CONFIG_META_TITLE_MAX_CHARS,
    CONFIG_TEMPLATE_LIMIT,
};
use crate::routing::{
    compile_config_header, compile_config_redirect, compile_config_rewrite, has_variable_marker,
    ConfigHeaderEntry, ConfigHeaderRule, ConfigRedirect, ConfigRewrite, ConfigRouting, RuleIssue,
    RuleLocation, CONFIG_REDIRECT_DEFAULT_STATUS, CONFIG_REDIRECT_STATUSES,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

pub const FORMAT: &str = "spacefast.config.strict-v1.input.v1";
const SCHEMA: &str = "https://spacefast.com/schemas/sf.v1.json";

#[must_use]
pub fn public_json_schema() -> Value {
    let closed = |properties: Value| {
        json!({
            "type": "object",
            "properties": properties,
            "additionalProperties": false
        })
    };
    // The `!` and `name=:capture` of a `_redirects` line, shared by both rule
    // sections so the two grammars stay one grammar.
    let force = json!({
        "type": "boolean",
        "default": false,
        "description": "Answer even when the request path resolves to a committed file — the ! of a _redirects rule."
    });
    let query = json!({
        "type": "object",
        "description": "Query parameters the request must carry, as parameter name to capture name: { \"id\": \"id\" } is the name=:capture token id=:id.",
        "propertyNames": { "pattern": "^[A-Za-z][A-Za-z0-9_-]*$" },
        "additionalProperties": { "type": "string", "pattern": "^[A-Za-z][A-Za-z0-9_]*$" }
    });
    json!({
        "$schema": "http://json-schema.org/draft-07/schema#",
        "$id": SCHEMA,
        "title": "Spacefast strict configuration v1 (sf.jsonc)",
        "type": "object",
        "required": ["version"],
        "properties": {
            "$schema": { "const": SCHEMA },
            "version": { "const": 1 },
            "build": closed(json!({
                "installRoot": { "type": "string" },
                "installCommand": { "type": "string" },
                "command": { "type": "string" },
                "output": { "type": "string" },
                "timeoutSeconds": {
                    "type": "integer",
                    "minimum": CONFIG_BUILD_TIMEOUT_MIN_SECONDS,
                    "maximum": CONFIG_BUILD_TIMEOUT_MAX_SECONDS
                }
            })),
            "serve": closed(json!({
                "index": { "oneOf": [{ "type": "string" }, { "const": false }] },
                "spaFallback": { "type": "string" },
                "cleanUrls": { "type": "boolean" },
                "directoryListing": { "type": "boolean" }
            })),
            "redirects": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "source": {
                            "type": "string",
                            "minLength": 1,
                            "description": "Request path to match. Same grammar as _redirects: * splat, :placeholder segments."
                        },
                        "destination": {
                            "type": "string",
                            "minLength": 1,
                            "description": "Path or absolute URL. :splat and :placeholder from the source are substituted."
                        },
                        "status": {
                            "enum": CONFIG_REDIRECT_STATUSES,
                            "default": CONFIG_REDIRECT_DEFAULT_STATUS,
                            "description": "Redirect status. To serve other content under this URL, use rewrites."
                        },
                        "force": force,
                        "query": query
                    },
                    "required": ["source", "destination"],
                    "additionalProperties": false
                },
                "description": "Browser redirect rules. _redirects rules match first."
            },
            "rewrites": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "source": {
                            "type": "string",
                            "minLength": 1,
                            "description": "Request path to match. Same grammar as _redirects: * splat, :placeholder segments."
                        },
                        "destination": {
                            "type": "string",
                            "minLength": 1,
                            "description": "Path to serve under this URL, or an absolute URL to proxy. :splat and :placeholder from the source are substituted."
                        },
                        "cache": {
                            "const": "shared",
                            "description": "Let shared caches store the proxy response. Only valid when the destination is an absolute URL."
                        },
                        "force": force,
                        "query": query,
                        "notFound": {
                            "type": "boolean",
                            "default": false,
                            "description": "Serve the destination with status 404 — the 404 of a _redirects rule. The destination must be a path."
                        }
                    },
                    "required": ["source", "destination"],
                    "additionalProperties": false
                },
                "description": "Rewrite and proxy rules: the URL stays, the content comes from the destination. _redirects rules match first, then redirects."
            },
            "headers": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "source": {
                            "type": "string",
                            "minLength": 1,
                            "description": "Request path to match. Same grammar as _headers."
                        },
                        "headers": {
                            "type": "array",
                            "minItems": 1,
                            "items": {
                                "oneOf": [
                                    {
                                        "type": "object",
                                        "properties": {
                                            "key": { "type": "string", "minLength": 1 },
                                            "value": { "type": "string" }
                                        },
                                        "required": ["key", "value"],
                                        "additionalProperties": false
                                    },
                                    {
                                        "type": "object",
                                        "properties": {
                                            "key": { "type": "string", "minLength": 1 },
                                            "remove": {
                                                "const": true,
                                                "description": "Remove this header instead of setting it — the !Name line of a _headers block."
                                            }
                                        },
                                        "required": ["key", "remove"],
                                        "additionalProperties": false
                                    }
                                ]
                            }
                        }
                    },
                    "required": ["source", "headers"],
                    "additionalProperties": false
                },
                "description": "Response headers to set or remove on matching requests. A _headers rule wins on the same header name."
            },
            "metadata": closed(json!({
                "title": { "type": "string", "maxLength": CONFIG_META_TITLE_MAX_CHARS },
                "description": {
                    "type": "string",
                    "maxLength": CONFIG_META_DESCRIPTION_MAX_CHARS
                },
                "image": { "type": "string", "maxLength": CONFIG_META_IMAGE_MAX_CHARS }
            })),
            "substitute": {
                "type": "object",
                "properties": {
                    "files": {
                        "type": "array",
                        "maxItems": CONFIG_TEMPLATE_LIMIT,
                        "items": { "type": "string", "minLength": 1 }
                    }
                },
                "required": ["files"],
                "additionalProperties": false
            },
            "access": {
                "anyOf": [
                    {
                        "const": "public",
                        "description": "Shorthand for { \"public\": [\"/**\"] }: the whole live space is public."
                    },
                    {
                        "type": "object",
                        "properties": {
                            "public": {
                                "type": "array",
                                "maxItems": CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT,
                                "uniqueItems": true,
                                "items": {
                                    "type": "string",
                                    "minLength": 1,
                                    "maxLength": CONFIG_ACCESS_RESOURCE_PATTERN_MAX_CHARS + 1,
                                    "description": "Absolute path pattern; prefix an exclusion with !. Wildcards are whole * or ** path segments."
                                }
                            }
                        },
                        "required": ["public"],
                        "additionalProperties": false
                    }
                ],
                "description": "File-only live public paths. Config access remains inactive until the Space is claimed."
            }
        },
        "additionalProperties": false
    })
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Input {
    pub format: String,
    pub source: String,
    pub artifact_paths: Option<Vec<String>>,
    #[serde(default)]
    pub template_sources: Vec<TemplateSource>,
}

#[derive(Debug, Deserialize)]
pub struct TemplateSource {
    pub path: String,
    pub source: String,
}

/// Where a key is written. Shared with the routing merge, which addresses its
/// `sf.jsonc` diagnostics through the same map this compiler publishes.
pub type Location = RuleLocation;

#[derive(Debug, Serialize)]
pub struct Issue {
    pub code: String,
    pub severity: &'static str,
    pub path: String,
    pub line: usize,
    pub column: usize,
    pub message: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub suggestion: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Output {
    pub success: bool,
    pub config: Option<Value>,
    pub effective: Option<Value>,
    pub issues: Vec<Issue>,
    pub locations: BTreeMap<String, Location>,
}

pub fn compile(input: Input) -> Output {
    if input.format != FORMAT {
        return invalid("Unsupported strict config input format.", 1, 1);
    }
    if input.source.len() > CONFIG_FILE_MAX_BYTES {
        return invalid(
            &format!("Config supports up to {CONFIG_FILE_MAX_BYTES} bytes."),
            1,
            1,
        );
    }
    let newlines = newline_offsets(&input.source);
    let document = match parse_document(&input.source) {
        Ok(document) => document,
        Err(error) => {
            let loc = locate(&input.source, &newlines, error.offset);
            return invalid(&error.message, loc.line, loc.column);
        }
    };
    let mut locations = document
        .key_offsets
        .iter()
        .map(|(path, offset)| (path.clone(), locate(&input.source, &newlines, *offset)))
        .collect::<BTreeMap<_, _>>();
    locations.insert("$".into(), Location { line: 1, column: 1 });
    let Some(root) = document.value.as_object() else {
        return Output {
            success: false,
            config: None,
            effective: None,
            issues: vec![make_issue(
                &locations,
                "config_invalid",
                "$",
                "Config must be an object.",
                None,
            )],
            locations,
        };
    };
    let mut issues = Vec::new();
    validate_keys(root, &document.object_keys, &locations, &mut issues);
    if root.get("version") != Some(&json!(1)) {
        push_issue(
            &mut issues,
            &locations,
            "config_invalid",
            "$.version",
            "version must be 1.",
            None,
        );
    }
    if root.get("$schema").is_some_and(|value| value != SCHEMA) {
        push_issue(
            &mut issues,
            &locations,
            "config_invalid",
            "$.$schema",
            "$schema must identify the sf.jsonc v1 schema.",
            None,
        );
    }
    validate_shape(root, &locations, &mut issues);
    validate_routing(root, &document.object_keys, &locations, &mut issues);
    validate_templates(root, &input.template_sources, &locations, &mut issues);
    validate_artifacts(
        root,
        input.artifact_paths.as_deref(),
        &locations,
        &mut issues,
    );
    if failed(&issues) {
        return Output {
            success: false,
            config: None,
            effective: None,
            issues,
            locations,
        };
    }
    let config = project(root);
    let artifacts = input.artifact_paths.unwrap_or_default();
    let root_html = artifacts
        .iter()
        .filter(|p| !p.contains('/') && p.ends_with(".html"))
        .collect::<Vec<_>>();
    let explicit = config.pointer("/serve/index").cloned();
    let index = if let Some(value) = explicit {
        value
    } else if artifacts.iter().any(|p| p == "index.html") {
        json!("index.html")
    } else if root_html.len() == 1 {
        json!(root_html[0])
    } else if root_html.len() > 1 {
        push_issue(
            &mut issues,
            &locations,
            "serve_index_required",
            "$.serve.index",
            "Multiple root HTML files require serve.index.",
            None,
        );
        return Output {
            success: false,
            config: None,
            effective: None,
            issues,
            locations,
        };
    } else {
        Value::Bool(false)
    };
    let effective = effective(&config, index);
    Output {
        success: true,
        config: Some(config),
        effective: Some(effective),
        issues,
        locations,
    }
}

fn validate_keys(
    root: &Map<String, Value>,
    order: &BTreeMap<String, Vec<String>>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    let root_keys = [
        "$schema",
        "version",
        "build",
        "serve",
        "redirects",
        "rewrites",
        "headers",
        "metadata",
        "substitute",
        "access",
    ];
    let renamed = [
        ("index", "serve.index"),
        ("fallback", "serve.spaFallback"),
        ("cleanUrls", "serve.cleanUrls"),
        ("meta", "metadata"),
    ];
    let removed = [
        "listing",
        "superpowers",
        "templates",
        "theme",
        "pages",
        "placement",
        "markdownNegotiation",
        "inject",
    ];
    for key in order.get("$").into_iter().flatten() {
        if root_keys.contains(&key.as_str()) {
            continue;
        }
        let path = format!("$.{key}");
        if key == "space" {
            push_issue(
                issues,
                locations,
                "config_identity_moved",
                &path,
                "Space identity belongs in .spacefast/space.json.",
                None,
            );
        } else if removed.contains(&key.as_str()) {
            push_issue(
                issues,
                locations,
                "config_key_removed",
                &path,
                &format!("{key} is not part of sf.jsonc v1."),
                None,
            );
        } else if let Some((_, suggestion)) = renamed.iter().find(|(old, _)| old == key) {
            push_issue(
                issues,
                locations,
                "config_unknown_key",
                &path,
                &format!("Unknown config key {key}."),
                Some(suggestion),
            );
        } else {
            let suggestion = nearest(key, &root_keys);
            push_issue(
                issues,
                locations,
                "config_unknown_key",
                &path,
                &format!("Unknown config key {key}."),
                suggestion.as_deref(),
            );
        }
    }
    for (section, fields) in fields() {
        let Some(value) = root.get(section) else {
            continue;
        };
        // The "public" shorthand carries no sub-keys, so the object
        // requirement must not fire for it; validate_shape validates the
        // string itself.
        if section == "access" && value.is_string() {
            continue;
        }
        let Some(_) = value.as_object() else {
            push_issue(
                issues,
                locations,
                "config_invalid",
                &format!("$.{section}"),
                &format!("{section} must be an object."),
                None,
            );
            continue;
        };
        let parent = format!("$.{section}");
        for key in order.get(&parent).into_iter().flatten() {
            if fields.iter().any(|(name, _)| name == key) {
                continue;
            }
            let names = fields.iter().map(|(name, _)| *name).collect::<Vec<_>>();
            let suggestion = nearest(key, &names);
            push_issue(
                issues,
                locations,
                "config_unknown_key",
                &format!("{parent}.{key}"),
                &format!("Unknown config key {key}."),
                suggestion.as_deref(),
            );
        }
    }
}

fn fields() -> Vec<(&'static str, Vec<(&'static str, &'static str)>)> {
    vec![
        (
            "build",
            vec![
                ("installRoot", "string"),
                ("installCommand", "string"),
                ("command", "string"),
                ("output", "string"),
                ("timeoutSeconds", "number"),
            ],
        ),
        (
            "serve",
            vec![
                ("index", "string-or-false"),
                ("spaFallback", "string"),
                ("cleanUrls", "boolean"),
                ("directoryListing", "boolean"),
            ],
        ),
        (
            "metadata",
            vec![
                ("title", "string"),
                ("description", "string"),
                ("image", "string"),
            ],
        ),
        ("substitute", vec![("files", "string-array")]),
        ("access", vec![("public", "string-array")]),
    ]
}

fn validate_shape(
    root: &Map<String, Value>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    for (section, fields) in fields() {
        if let Some(object) = root.get(section).and_then(Value::as_object) {
            for (key, kind) in fields {
                if let Some(value) = object.get(key) {
                    if !matches_kind(value, kind) {
                        push_issue(
                            issues,
                            locations,
                            "config_invalid",
                            &format!("$.{section}.{key}"),
                            &type_message(section, key, kind),
                            None,
                        );
                    }
                }
            }
        }
    }
    if let Some(build) = root.get("build").and_then(Value::as_object) {
        if let Some(n) = build.get("timeoutSeconds").and_then(Value::as_f64) {
            if n.fract() != 0.0
                || !((CONFIG_BUILD_TIMEOUT_MIN_SECONDS as f64)
                    ..=(CONFIG_BUILD_TIMEOUT_MAX_SECONDS as f64))
                    .contains(&n)
            {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    "$.build.timeoutSeconds",
                    &format!("build.timeoutSeconds must be an integer from {CONFIG_BUILD_TIMEOUT_MIN_SECONDS} to {CONFIG_BUILD_TIMEOUT_MAX_SECONDS}."),
                    None,
                );
            }
        }
        if build
            .get("installRoot")
            .and_then(Value::as_str)
            .is_some_and(is_absolute)
        {
            push_issue(
                issues,
                locations,
                "config_invalid",
                "$.build.installRoot",
                "build.installRoot must be a relative path.",
                None,
            );
        }
        if build
            .get("output")
            .and_then(Value::as_str)
            .is_some_and(is_absolute_or_escaping)
        {
            push_issue(
                issues,
                locations,
                "config_invalid",
                "$.build.output",
                "build.output must be a relative path that does not escape the project root.",
                None,
            );
        }
    }
    if let Some(serve) = root.get("serve").and_then(Value::as_object) {
        for key in ["index", "spaFallback"] {
            if serve
                .get(key)
                .and_then(Value::as_str)
                .is_some_and(|v| !committed(v))
            {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("$.serve.{key}"),
                    &format!("serve.{key} must be a non-empty committed relative path."),
                    None,
                );
            }
        }
    }
    if let Some(substitute) = root.get("substitute").and_then(Value::as_object) {
        if !substitute.contains_key("files") {
            push_issue(
                issues,
                locations,
                "config_invalid",
                "$.substitute",
                "substitute.files is required when substitute is present.",
                None,
            );
        }
        if let Some(files) = substitute.get("files").and_then(Value::as_array) {
            if files.len() > CONFIG_TEMPLATE_LIMIT {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    "$.substitute.files",
                    &format!("substitute.files supports up to {CONFIG_TEMPLATE_LIMIT} entries."),
                    None,
                );
            }
            for (i, value) in files.iter().enumerate() {
                if value.as_str().is_some_and(|v| !committed(v)) {
                    push_issue(
                        issues,
                        locations,
                        "config_invalid",
                        &format!("$.substitute.files[{i}]"),
                        "substitute.files entries must be non-empty committed relative paths.",
                        None,
                    );
                }
            }
        }
    }
    if let Some(access) = root.get("access") {
        match normalized_public_patterns(access) {
            Ok(_) => {}
            Err((path, message)) => {
                let json_path = if let Some(index) = path.strip_prefix("access.public.") {
                    format!("$.access.public[{index}]")
                } else {
                    format!("$.{path}")
                };
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &json_path,
                    &message,
                    None,
                );
            }
        }
    }
    if let Some(meta) = root.get("metadata").and_then(Value::as_object) {
        for (key, cap) in [
            ("title", CONFIG_META_TITLE_MAX_CHARS),
            ("description", CONFIG_META_DESCRIPTION_MAX_CHARS),
            ("image", CONFIG_META_IMAGE_MAX_CHARS),
        ] {
            if meta
                .get(key)
                .and_then(Value::as_str)
                .is_some_and(|v| v.encode_utf16().count() > cap)
            {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("$.metadata.{key}"),
                    &format!("metadata.{key} supports up to {cap} characters."),
                    None,
                );
            }
        }
        if meta
            .get("image")
            .and_then(Value::as_str)
            .is_some_and(|v| !image_path(v) && !v.starts_with("https://"))
        {
            push_issue(
                issues,
                locations,
                "config_invalid",
                "$.metadata.image",
                "metadata.image must be a committed root-relative path or https: URL.",
                None,
            );
        }
    }
}

/// `redirects`, `rewrites` and `headers` entries are rules, not settings: the
/// structure is checked here and the rule itself goes through the same compiler
/// a `_redirects` or `_headers` line does, so both grammars agree on what a
/// valid rule is and what to call the problem when it isn't. The strict lane
/// reports rule problems against the exact key (`$.redirects[2].source`, with
/// line and column); the merge reports them again as version routing
/// diagnostics for the publish that is actually happening.
///
/// A rule whose strings still carry a `{{ vars.NAME }}` marker is NOT judged
/// here. This is an authoring-time lane with no variable values: the server
/// substitutes before it compiles, so the string this compiler can see is not
/// the string that will route. The `_redirects` lane is not judged here at all,
/// and the JSON lane must not be stricter than the file it mirrors.
fn validate_routing(
    root: &Map<String, Value>,
    order: &BTreeMap<String, Vec<String>>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    for entry in collect_config_redirects(root, order, locations, issues) {
        if pending_substitution(rule_strings(
            &entry.source,
            &entry.destination,
            &entry.query,
        )) {
            continue;
        }
        for issue in compile_config_redirect(&entry, &[]).issues {
            push_rule_issue(issues, locations, &entry.path, &issue);
        }
    }
    for entry in collect_config_rewrites(root, order, locations, issues) {
        if pending_substitution(rule_strings(
            &entry.source,
            &entry.destination,
            &entry.query,
        )) {
            continue;
        }
        for issue in compile_config_rewrite(&entry, &[]).issues {
            push_rule_issue(issues, locations, &entry.path, &issue);
        }
    }
    for entry in collect_config_headers(root, order, locations, issues) {
        if pending_substitution(
            std::iter::once(&entry.source).chain(
                entry
                    .headers
                    .iter()
                    .flat_map(|header| std::iter::once(&header.key).chain(header.value.as_ref())),
            ),
        ) {
            continue;
        }
        for issue in compile_config_header(&entry.source, &entry.headers).issues {
            push_rule_issue(issues, locations, &entry.path, &issue);
        }
    }
}

/// Every string a redirect or rewrite entry carries: the substitution pass may
/// write into any of them, so a marker in any one defers the authoring verdict.
fn rule_strings<'a>(
    source: &'a String,
    destination: &'a String,
    query: &'a Option<BTreeMap<String, String>>,
) -> impl Iterator<Item = &'a String> {
    [source, destination].into_iter().chain(
        query
            .iter()
            .flat_map(|query| query.iter().flat_map(|(name, capture)| [name, capture])),
    )
}

fn pending_substitution<'a>(values: impl IntoIterator<Item = &'a String>) -> bool {
    values.into_iter().any(|value| has_variable_marker(value))
}

/// The routing sections of a `sf.jsonc`, read on their own terms.
///
/// This is deliberately NOT gated on the rest of the file compiling: `redirects`,
/// `rewrites` and `headers` are the routing compiler's keys, and a publisher who writes a
/// redirect gets that redirect whether or not some unrelated key elsewhere in
/// the config is one the strict schema has not learned yet. Only the shape is
/// checked here — what a rule MEANS is settled once, in the merge, so the two
/// never drift.
///
/// A document that does not parse states no routing rules and reports nothing:
/// the serving-config lane reads the same file and already says so, and one
/// broken-JSON message per publish is enough.
pub fn routing_sections(source: &str) -> (ConfigRouting, Vec<Issue>) {
    let mut issues = Vec::new();
    let Ok(document) = parse_document(source) else {
        return (ConfigRouting::default(), issues);
    };
    let Some(root) = document.value.as_object() else {
        return (ConfigRouting::default(), issues);
    };
    let newlines = newline_offsets(source);
    let mut locations = document
        .key_offsets
        .iter()
        .map(|(path, offset)| (path.clone(), locate(source, &newlines, *offset)))
        .collect::<BTreeMap<_, _>>();
    locations.insert("$".into(), Location { line: 1, column: 1 });
    let redirects = collect_config_redirects(root, &document.object_keys, &locations, &mut issues);
    let rewrites = collect_config_rewrites(root, &document.object_keys, &locations, &mut issues);
    let headers = collect_config_headers(root, &document.object_keys, &locations, &mut issues);
    (
        ConfigRouting {
            redirects,
            rewrites,
            headers,
            locations,
        },
        issues,
    )
}

/// The byte ranges of every string literal inside the routing sections. The
/// variable substitution pass writes into exactly these — a `{{ vars.NAME }}`
/// marker anywhere else in the document is not a routing rule's business, and
/// substituting the whole file would let a value's quote or backslash rewrite
/// the JSON around it.
#[must_use]
pub fn routing_string_spans(source: &str) -> Vec<(usize, usize)> {
    let Ok(document) = parse_document(source) else {
        return Vec::new();
    };
    document
        .string_spans
        .iter()
        .filter(|(path, _)| {
            ROUTING_SECTIONS
                .iter()
                .any(|section| path.starts_with(&format!("$.{section}[")))
        })
        .map(|(_, span)| *span)
        .collect()
}

const ROUTING_SECTIONS: &[&str] = &["redirects", "rewrites", "headers"];

fn collect_config_redirects(
    root: &Map<String, Value>,
    order: &BTreeMap<String, Vec<String>>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Vec<ConfigRedirect> {
    let mut collected = Vec::new();
    let Some(entries) = section_entries(root, "redirects", locations, issues) else {
        return collected;
    };
    for (index, entry) in entries.iter().enumerate() {
        let path = format!("$.redirects[{index}]");
        let Some(object) = entry_object(entry, &path, "redirects", locations, issues) else {
            continue;
        };
        validate_entry_keys(
            order,
            &path,
            &["source", "destination", "status", "force", "query"],
            locations,
            issues,
        );
        let source = required_string(object, &path, "redirects", "source", locations, issues);
        let destination =
            required_string(object, &path, "redirects", "destination", locations, issues);
        let status = match object.get("status") {
            None => None,
            Some(value) => match value.as_u64() {
                Some(status) => Some(status),
                None => {
                    push_issue(
                        issues,
                        locations,
                        "config_invalid",
                        &format!("{path}.status"),
                        "redirects[].status must be a number.",
                        None,
                    );
                    continue;
                }
            },
        };
        let Some(force) = optional_bool(object, &path, "redirects", "force", locations, issues)
        else {
            continue;
        };
        let Some(query) = optional_query(object, &path, "redirects", locations, issues) else {
            continue;
        };
        let (Some(source), Some(destination)) = (source, destination) else {
            continue;
        };
        collected.push(ConfigRedirect {
            path,
            source: source.to_string(),
            destination: destination.to_string(),
            status,
            force,
            query,
        });
    }
    collected
}

/// A boolean rule flag, absent meaning `false`. `None` means the key was
/// present with the wrong type and the entry has already been reported.
fn optional_bool(
    object: &Map<String, Value>,
    path: &str,
    section: &str,
    key: &str,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Option<bool> {
    match object.get(key) {
        None => Some(false),
        Some(value) => match value.as_bool() {
            Some(flag) => Some(flag),
            None => {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("{path}.{key}"),
                    &format!("{section}[].{key} must be boolean."),
                    None,
                );
                None
            }
        },
    }
}

/// The `query` object of a redirect or rewrite entry: parameter name to
/// capture name, every value a string. `None` means the key was present with
/// the wrong shape and the entry has already been reported; whether the names
/// spell a valid `name=:capture` pair is the rule compiler's verdict.
fn optional_query(
    object: &Map<String, Value>,
    path: &str,
    section: &str,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Option<Option<BTreeMap<String, String>>> {
    match object.get("query") {
        None => Some(None),
        Some(value) => match value
            .as_object()
            .filter(|entries| entries.values().all(Value::is_string))
        {
            Some(entries) => Some(Some(
                entries
                    .iter()
                    .map(|(name, capture)| {
                        (
                            name.clone(),
                            capture.as_str().unwrap_or_default().to_string(),
                        )
                    })
                    .collect(),
            )),
            None => {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("{path}.query"),
                    &format!(
                        "{section}[].query must be an object mapping parameter names to capture names."
                    ),
                    None,
                );
                None
            }
        },
    }
}

fn collect_config_rewrites(
    root: &Map<String, Value>,
    order: &BTreeMap<String, Vec<String>>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Vec<ConfigRewrite> {
    let mut collected = Vec::new();
    let Some(entries) = section_entries(root, "rewrites", locations, issues) else {
        return collected;
    };
    for (index, entry) in entries.iter().enumerate() {
        let path = format!("$.rewrites[{index}]");
        let Some(object) = entry_object(entry, &path, "rewrites", locations, issues) else {
            continue;
        };
        validate_entry_keys(
            order,
            &path,
            &[
                "source",
                "destination",
                "cache",
                "force",
                "query",
                "notFound",
            ],
            locations,
            issues,
        );
        let source = required_string(object, &path, "rewrites", "source", locations, issues);
        let destination =
            required_string(object, &path, "rewrites", "destination", locations, issues);
        let cache = match object.get("cache") {
            None => None,
            Some(value) => match value.as_str() {
                Some(cache) => Some(cache),
                None => {
                    push_issue(
                        issues,
                        locations,
                        "config_invalid",
                        &format!("{path}.cache"),
                        "rewrites[].cache must be a string.",
                        None,
                    );
                    continue;
                }
            },
        };
        let Some(force) = optional_bool(object, &path, "rewrites", "force", locations, issues)
        else {
            continue;
        };
        let Some(not_found) =
            optional_bool(object, &path, "rewrites", "notFound", locations, issues)
        else {
            continue;
        };
        let Some(query) = optional_query(object, &path, "rewrites", locations, issues) else {
            continue;
        };
        let (Some(source), Some(destination)) = (source, destination) else {
            continue;
        };
        collected.push(ConfigRewrite {
            path,
            source: source.to_string(),
            destination: destination.to_string(),
            cache: cache.map(str::to_string),
            force,
            query,
            not_found,
        });
    }
    collected
}

fn collect_config_headers(
    root: &Map<String, Value>,
    order: &BTreeMap<String, Vec<String>>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Vec<ConfigHeaderRule> {
    let mut collected = Vec::new();
    let Some(entries) = section_entries(root, "headers", locations, issues) else {
        return collected;
    };
    for (index, entry) in entries.iter().enumerate() {
        let path = format!("$.headers[{index}]");
        let Some(object) = entry_object(entry, &path, "headers", locations, issues) else {
            continue;
        };
        validate_entry_keys(order, &path, &["source", "headers"], locations, issues);
        let source = required_string(object, &path, "headers", "source", locations, issues);
        let Some(values) = object.get("headers") else {
            push_issue(
                issues,
                locations,
                "config_invalid",
                &path,
                "headers[].headers is required.",
                None,
            );
            continue;
        };
        let Some(values) = values.as_array().filter(|values| !values.is_empty()) else {
            push_issue(
                issues,
                locations,
                "config_invalid",
                &format!("{path}.headers"),
                "headers[].headers must be an array with at least one header.",
                None,
            );
            continue;
        };
        let mut pairs = Vec::new();
        let mut malformed = false;
        for (position, value) in values.iter().enumerate() {
            let entry_path = format!("{path}.headers[{position}]");
            let pair = value.as_object().and_then(|value| {
                validate_entry_keys(
                    order,
                    &entry_path,
                    &["key", "value", "remove"],
                    locations,
                    issues,
                );
                let key = value.get("key")?.as_str()?;
                let remove = match value.get("remove") {
                    None => false,
                    Some(flag) => flag.as_bool()?,
                };
                let set_value = match value.get("value") {
                    None => None,
                    Some(value) => Some(value.as_str()?),
                };
                // The shape is what tells the two operations apart: a set entry
                // needs its value, a remove entry must not carry one.
                match (remove, set_value) {
                    (false, Some(set_value)) => Some(ConfigHeaderEntry {
                        key: key.to_string(),
                        value: Some(set_value.to_string()),
                        remove: false,
                    }),
                    (true, None) => Some(ConfigHeaderEntry {
                        key: key.to_string(),
                        value: None,
                        remove: true,
                    }),
                    _ => None,
                }
            });
            match pair {
                Some(pair) => pairs.push(pair),
                None => {
                    malformed = true;
                    push_issue(
                        issues,
                        locations,
                        "config_invalid",
                        &entry_path,
                        "Header entries must be objects with a string key and value, or a string key with \"remove\": true.",
                        None,
                    );
                }
            }
        }
        let Some(source) = source.filter(|_| !malformed) else {
            continue;
        };
        collected.push(ConfigHeaderRule {
            path,
            source: source.to_string(),
            headers: pairs,
        });
    }
    collected
}

fn section_entries<'a>(
    root: &'a Map<String, Value>,
    section: &str,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Option<&'a Vec<Value>> {
    let value = root.get(section)?;
    match value.as_array() {
        Some(entries) => Some(entries),
        None => {
            push_issue(
                issues,
                locations,
                "config_invalid",
                &format!("$.{section}"),
                &format!("{section} must be an array."),
                None,
            );
            None
        }
    }
}

fn entry_object<'a>(
    entry: &'a Value,
    path: &str,
    section: &str,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Option<&'a Map<String, Value>> {
    match entry.as_object() {
        Some(object) => Some(object),
        None => {
            push_issue(
                issues,
                locations,
                "config_invalid",
                path,
                &format!("{section} entries must be objects."),
                None,
            );
            None
        }
    }
}

fn validate_entry_keys(
    order: &BTreeMap<String, Vec<String>>,
    path: &str,
    keys: &[&str],
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    for key in order.get(path).into_iter().flatten() {
        if keys.contains(&key.as_str()) {
            continue;
        }
        let suggestion = nearest(key, keys);
        push_issue(
            issues,
            locations,
            "config_unknown_key",
            &format!("{path}.{key}"),
            &format!("Unknown config key {key}."),
            suggestion.as_deref(),
        );
    }
}

fn required_string<'a>(
    object: &'a Map<String, Value>,
    path: &str,
    section: &str,
    key: &str,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) -> Option<&'a str> {
    match object.get(key) {
        None => {
            push_issue(
                issues,
                locations,
                "config_invalid",
                path,
                &format!("{section}[].{key} is required."),
                None,
            );
            None
        }
        Some(value) => match value.as_str() {
            Some(value) => Some(value),
            None => {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("{path}.{key}"),
                    &format!("{section}[].{key} must be a string."),
                    None,
                );
                None
            }
        },
    }
}

fn push_rule_issue(
    issues: &mut Vec<Issue>,
    locations: &BTreeMap<String, Location>,
    path: &str,
    issue: &RuleIssue,
) {
    let path = issue.field.json_path(path);
    issues.push(Issue {
        severity: issue.severity,
        ..make_issue(locations, issue.code, &path, &issue.message, None)
    });
}

fn validate_templates(
    root: &Map<String, Value>,
    templates: &[TemplateSource],
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    let files = root
        .get("substitute")
        .and_then(Value::as_object)
        .and_then(|v| v.get("files"))
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    for template in templates {
        if let Some(i) = files
            .iter()
            .position(|v| v.as_str() == Some(&template.path))
        {
            if retired_template_pattern().is_match(&template.source) {
                push_issue(
                    issues,
                    locations,
                    "template_syntax_retired",
                    &format!("$.substitute.files[{i}]"),
                    &format!(
                        "Use {{{{ env.NAME }}}} instead of {{{{ vars.NAME }}}} in {}.",
                        template.path
                    ),
                    None,
                );
            }
        }
    }
}
fn validate_artifacts(
    root: &Map<String, Value>,
    artifacts: Option<&[String]>,
    locations: &BTreeMap<String, Location>,
    issues: &mut Vec<Issue>,
) {
    let Some(artifacts) = artifacts else { return };
    let set = artifacts
        .iter()
        .map(String::as_str)
        .collect::<BTreeSet<_>>();
    if let Some(serve) = root.get("serve").and_then(Value::as_object) {
        for key in ["index", "spaFallback"] {
            if let Some(v) = serve
                .get(key)
                .and_then(Value::as_str)
                .filter(|v| committed(v) && !set.contains(*v))
            {
                let _ = v;
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("$.serve.{key}"),
                    &format!("serve.{key} must reference a committed artifact."),
                    None,
                );
            }
        }
    }
    if let Some(files) = root
        .get("substitute")
        .and_then(Value::as_object)
        .and_then(|v| v.get("files"))
        .and_then(Value::as_array)
    {
        for (i, v) in files.iter().enumerate() {
            if v.as_str().is_some_and(|v| committed(v) && !set.contains(v)) {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    &format!("$.substitute.files[{i}]"),
                    "substitute.files entries must reference committed artifacts.",
                    None,
                );
            }
        }
    }
    if let Some(image) = root
        .get("metadata")
        .and_then(Value::as_object)
        .and_then(|v| v.get("image"))
        .and_then(Value::as_str)
    {
        if image_path(image) && !set.contains(&image[1..]) {
            push_issue(
                issues,
                locations,
                "config_invalid",
                "$.metadata.image",
                "metadata.image must reference a committed artifact.",
                None,
            );
        }
    }
}

fn project(root: &Map<String, Value>) -> Value {
    let mut out = Map::new();
    out.insert("version".into(), json!(1));
    if root.contains_key("$schema") {
        out.insert("$schema".into(), json!(SCHEMA));
    }
    for (section, fields) in fields() {
        if let Some(source) = root.get(section).and_then(Value::as_object) {
            let mut projected = Map::new();
            for (key, _) in fields {
                if let Some(v) = source.get(key) {
                    projected.insert(key.into(), v.clone());
                }
            }
            out.insert(section.into(), Value::Object(projected));
        }
    }
    for section in ["redirects", "rewrites", "headers"] {
        if let Some(entries) = root.get(section).and_then(Value::as_array) {
            out.insert(section.into(), json!(entries));
        }
    }
    if let Some(access) = root.get("access") {
        if let Ok(patterns) = normalized_public_patterns(access) {
            out.insert("access".into(), json!({ "public": patterns }));
        }
    }
    Value::Object(out)
}
fn effective(config: &Value, index: Value) -> Value {
    let mut out = Map::new();
    out.insert("version".into(), json!(1));
    if let Some(build) = config.get("build").and_then(Value::as_object) {
        let mut b = build.clone();
        b.entry("installRoot").or_insert(json!("."));
        b.entry("timeoutSeconds").or_insert(json!(900));
        out.insert("build".into(), Value::Object(b));
    }
    let serve = config.get("serve").and_then(Value::as_object);
    let mut s = serve.cloned().unwrap_or_default();
    s.insert("index".into(), index.clone());
    s.entry("cleanUrls").or_insert(json!(true));
    s.entry("directoryListing")
        .or_insert(Value::Bool(index == Value::Bool(false)));
    out.insert("serve".into(), Value::Object(s));
    // Config redirects carry the file lane's default status when they don't
    // name one, so every effective rule states what it does.
    if let Some(entries) = config.get("redirects").and_then(Value::as_array) {
        let redirects = entries
            .iter()
            .map(|entry| {
                let mut rule = entry.as_object().cloned().unwrap_or_default();
                rule.entry("status")
                    .or_insert(json!(CONFIG_REDIRECT_DEFAULT_STATUS));
                Value::Object(rule)
            })
            .collect::<Vec<_>>();
        out.insert("redirects".into(), Value::Array(redirects));
    }
    for key in ["rewrites", "headers", "metadata", "substitute"] {
        if let Some(v) = config.get(key) {
            out.insert(key.into(), v.clone());
        }
    }
    if let Some(patterns) = config
        .pointer("/access/public")
        .and_then(Value::as_array)
        .filter(|patterns| {
            patterns.iter().any(|pattern| {
                pattern
                    .as_str()
                    .is_some_and(|pattern| !pattern.starts_with('!'))
            })
        })
    {
        let include = patterns
            .iter()
            .filter_map(Value::as_str)
            .filter(|pattern| !pattern.starts_with('!'))
            .collect::<Vec<_>>();
        let exclude = patterns
            .iter()
            .filter_map(Value::as_str)
            .filter_map(|pattern| pattern.strip_prefix('!'))
            .collect::<Vec<_>>();
        out.insert(
            "grants".into(),
            json!([{
                "id": "config:access.public",
                "audience": { "kind": "public" },
                "resources": { "include": include, "exclude": exclude },
                "capabilities": ["page.view"],
                "constraints": {},
                "target": { "kind": "live" },
                "source": {
                    "kind": "config",
                    "reference": CONFIG_ACCESS_PUBLIC_SOURCE_REFERENCE
                }
            }]),
        );
    }
    Value::Object(out)
}
fn matches_kind(v: &Value, k: &str) -> bool {
    match k {
        "string" => v.is_string(),
        "number" => v.is_number(),
        "boolean" => v.is_boolean(),
        "string-or-false" => v.is_string() || v == &Value::Bool(false),
        "string-array" => v.as_array().is_some_and(|a| a.iter().all(Value::is_string)),
        _ => false,
    }
}
fn type_message(s: &str, k: &str, t: &str) -> String {
    match t {
        "string-array" => format!("{s}.{k} must be an array of strings."),
        "string-or-false" => format!("{s}.{k} must be a string or false."),
        "boolean" => format!("{s}.{k} must be boolean."),
        _ => format!("{s}.{k} must be a {t}."),
    }
}
fn is_absolute(v: &str) -> bool {
    v.starts_with(['/', '\\'])
        || (v.len() > 2
            && v.as_bytes()[0].is_ascii_alphabetic()
            && v.as_bytes()[1] == b':'
            && matches!(v.as_bytes()[2], b'/' | b'\\'))
}
fn is_absolute_or_escaping(v: &str) -> bool {
    is_absolute(v) || v.split(['/', '\\']).any(|p| p == "..")
}
fn committed(v: &str) -> bool {
    !v.is_empty() && !is_absolute_or_escaping(v) && !scheme_prefix_pattern().is_match(v)
}
fn scheme_prefix_pattern() -> &'static regex::Regex {
    static PATTERN: OnceLock<regex::Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        regex::Regex::new(r"^[A-Za-z][A-Za-z0-9+.-]*:").expect("static regex is valid")
    })
}
fn retired_template_pattern() -> &'static regex::Regex {
    static PATTERN: OnceLock<regex::Regex> = OnceLock::new();
    PATTERN.get_or_init(|| regex::Regex::new(r"\{\{\s*vars\.").expect("static regex is valid"))
}
fn image_path(v: &str) -> bool {
    v.starts_with('/') && !v.starts_with("//") && !v.split(['/', '\\']).any(|p| p == "..")
}
fn nearest(candidate: &str, keys: &[&str]) -> Option<String> {
    keys.iter()
        .map(|k| (*k, edit(candidate, k)))
        .filter(|(_, d)| *d <= 2)
        .min_by_key(|(_, d)| *d)
        .map(|(k, _)| k.into())
}
fn edit(a: &str, b: &str) -> usize {
    let mut prev = (0..=b.len()).collect::<Vec<_>>();
    for (i, ca) in a.chars().enumerate() {
        let mut cur = vec![i + 1];
        for (j, cb) in b.chars().enumerate() {
            cur.push(
                (cur[j] + 1)
                    .min(prev[j + 1] + 1)
                    .min(prev[j] + usize::from(ca != cb)),
            );
        }
        prev = cur;
    }
    *prev.last().unwrap_or(&b.len())
}
fn newline_offsets(source: &str) -> Vec<usize> {
    source
        .bytes()
        .enumerate()
        .filter(|(_, byte)| *byte == b'\n')
        .map(|(index, _)| index)
        .collect()
}
fn locate(source: &str, newlines: &[usize], offset: usize) -> Location {
    let offset = offset.min(source.len());
    let line = newlines.partition_point(|index| *index < offset) + 1;
    let start = if line > 1 { newlines[line - 2] + 1 } else { 0 };
    Location {
        line,
        column: source[start..offset].encode_utf16().count() + 1,
    }
}
fn make_issue(
    loc: &BTreeMap<String, Location>,
    code: &str,
    path: &str,
    message: &str,
    suggestion: Option<&str>,
) -> Issue {
    let l = loc
        .get(path)
        .cloned()
        .unwrap_or(Location { line: 1, column: 1 });
    Issue {
        code: code.into(),
        severity: "error",
        path: path.into(),
        line: l.line,
        column: l.column,
        message: message.into(),
        suggestion: suggestion.map(Into::into),
    }
}
fn push_issue(
    out: &mut Vec<Issue>,
    loc: &BTreeMap<String, Location>,
    code: &str,
    path: &str,
    message: &str,
    suggestion: Option<&str>,
) {
    out.push(make_issue(loc, code, path, message, suggestion));
}
/// Warnings travel with a successful config; only errors reject it.
fn failed(issues: &[Issue]) -> bool {
    issues.iter().any(|issue| issue.severity == "error")
}
fn invalid(message: &str, line: usize, column: usize) -> Output {
    let locations = BTreeMap::from([("$".into(), Location { line, column })]);
    Output {
        success: false,
        config: None,
        effective: None,
        issues: vec![make_issue(&locations, "config_invalid", "$", message, None)],
        locations,
    }
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{compile, public_json_schema, Input, SCHEMA};

    fn compile_source(source: &str) -> super::Output {
        compile(Input {
            format: "spacefast.config.strict-v1.input.v1".into(),
            source: source.into(),
            artifact_paths: Some(vec!["index.html".into()]),
            template_sources: Vec::new(),
        })
    }

    #[test]
    fn published_schema_describes_the_compiler_owned_access_contract() {
        let schema = public_json_schema();
        assert_eq!(schema.pointer("/$id"), Some(&json!(SCHEMA)));
        assert_eq!(
            schema.pointer("/properties/access/anyOf/0/const"),
            Some(&json!("public"))
        );
        assert_eq!(
            schema.pointer("/properties/access/anyOf/1/properties/public/maxItems"),
            Some(&json!(100))
        );
        assert_eq!(
            schema.pointer("/properties/access/anyOf/1/properties/public/items/maxLength"),
            Some(&json!(1025))
        );
    }

    #[test]
    fn compiles_public_paths_into_one_live_config_grant() {
        let output = compile_source(
            r#"{
              "$schema": "https://spacefast.com/schemas/sf.v1.json",
              "version": 1,
              "access": {
                "public": ["/assets//**", "!/assets/private/**"]
              }
            }"#,
        );

        assert!(output.success, "{:?}", output.issues);
        assert_eq!(
            output
                .effective
                .and_then(|effective| effective.pointer("/grants/0").cloned()),
            Some(json!({
                "id": "config:access.public",
                "audience": { "kind": "public" },
                "resources": {
                    "include": ["/assets/**"],
                    "exclude": ["/assets/private/**"]
                },
                "capabilities": ["page.view"],
                "constraints": {},
                "target": { "kind": "live" },
                "source": {
                    "kind": "config",
                    "reference": "sf.jsonc#$.access.public"
                }
            }))
        );
    }

    #[test]
    fn compiles_the_public_shorthand_into_the_same_grant_as_the_explicit_form() {
        let shorthand = compile_source(
            r#"{
              "$schema": "https://spacefast.com/schemas/sf.v1.json",
              "version": 1,
              "access": "public"
            }"#,
        );
        let explicit = compile_source(
            r#"{
              "$schema": "https://spacefast.com/schemas/sf.v1.json",
              "version": 1,
              "access": { "public": ["/**"] }
            }"#,
        );

        assert!(shorthand.success, "{:?}", shorthand.issues);
        assert_eq!(
            shorthand
                .config
                .and_then(|config| config.pointer("/access").cloned()),
            Some(json!({ "public": ["/**"] }))
        );
        assert_eq!(shorthand.effective, explicit.effective);
    }

    #[test]
    fn rejects_an_unknown_access_string_at_its_source_path() {
        let output = compile_source(r#"{"version":1,"access":"private"}"#);
        assert!(!output.success);
        assert!(
            output
                .issues
                .iter()
                .any(|issue| issue.path == "$.access" && issue.message.contains("shorthand")),
            "{:?}",
            output.issues
        );
    }

    #[test]
    fn rejects_ambiguous_public_path_declarations_at_their_source_path() {
        for (source, path) in [
            (
                r#"{"version":1,"access":{"public":["!/private/**"]}}"#,
                "$.access.public",
            ),
            (
                r#"{"version":1,"access":{"public":["/docs/*.html"]}}"#,
                "$.access.public[0]",
            ),
            (
                r#"{"version":1,"access":{"public":["/docs/**","/docs/**"]}}"#,
                "$.access.public[1]",
            ),
        ] {
            let output = compile_source(source);
            assert!(!output.success);
            assert!(
                output.issues.iter().any(|issue| issue.path == path),
                "{path}"
            );
        }
    }

    #[test]
    fn published_schema_describes_the_routing_lane() {
        let schema = public_json_schema();
        assert_eq!(
            schema.pointer("/properties/redirects/items/properties/status/enum"),
            Some(&json!([301, 302, 303, 307, 308]))
        );
        assert_eq!(
            schema.pointer("/properties/redirects/items/properties/status/default"),
            Some(&json!(302))
        );
        assert_eq!(
            schema.pointer("/properties/redirects/items/required"),
            Some(&json!(["source", "destination"]))
        );
        // A rewrite has no status and a redirect has no cache: the two shapes
        // are what tells a publisher which one they are writing. `notFound` is
        // the one 404 spelling, so it lives on rewrites alone.
        assert_eq!(
            schema.pointer("/properties/redirects/items/properties/cache"),
            None
        );
        assert_eq!(
            schema.pointer("/properties/rewrites/items/properties/status"),
            None
        );
        assert_eq!(
            schema.pointer("/properties/redirects/items/properties/notFound"),
            None
        );
        assert_eq!(
            schema.pointer("/properties/rewrites/items/properties/cache/const"),
            Some(&json!("shared"))
        );
        assert_eq!(
            schema.pointer("/properties/rewrites/items/properties/notFound/type"),
            Some(&json!("boolean"))
        );
        // Force and query are the file grammar's `!` and `name=:capture`, and
        // both rule sections spell them the same way.
        for section in ["redirects", "rewrites"] {
            assert_eq!(
                schema.pointer(&format!(
                    "/properties/{section}/items/properties/force/type"
                )),
                Some(&json!("boolean")),
                "{section}"
            );
            assert_eq!(
                schema.pointer(&format!(
                    "/properties/{section}/items/properties/query/additionalProperties/pattern"
                )),
                Some(&json!("^[A-Za-z][A-Za-z0-9_]*$")),
                "{section}"
            );
        }
        assert_eq!(
            schema.pointer("/properties/rewrites/items/required"),
            Some(&json!(["source", "destination"]))
        );
        // A header entry is a set or a remove, and the required keys are what
        // tell the two shapes apart.
        assert_eq!(
            schema.pointer("/properties/headers/items/properties/headers/items/oneOf/0/required"),
            Some(&json!(["key", "value"]))
        );
        assert_eq!(
            schema.pointer("/properties/headers/items/properties/headers/items/oneOf/1/required"),
            Some(&json!(["key", "remove"]))
        );
    }

    #[test]
    fn compiles_routing_rules_and_defaults_a_missing_status_to_302() {
        let output = compile_source(
            r#"{
              "version": 1,
              "redirects": [
                { "source": "/old-blog/*", "destination": "/blog/:splat", "status": 301 },
                { "source": "/moved", "destination": "/here" }
              ],
              "rewrites": [
                { "source": "/app/*", "destination": "/app/index.html" },
                {
                  "source": "/api/*",
                  "destination": "https://api.example.com/:splat",
                  "cache": "shared"
                }
              ],
              "headers": [
                { "source": "/*", "headers": [{ "key": "X-Frame-Options", "value": "DENY" }] }
              ]
            }"#,
        );

        assert!(output.success, "{:?}", output.issues);
        assert_eq!(output.issues.len(), 0);
        let config = output.config.expect("config");
        assert_eq!(config.pointer("/redirects/1/status"), None);
        let effective = output.effective.expect("effective config");
        assert_eq!(
            effective.pointer("/redirects").cloned(),
            Some(json!([
                { "source": "/old-blog/*", "destination": "/blog/:splat", "status": 301 },
                { "source": "/moved", "destination": "/here", "status": 302 }
            ]))
        );
        // Rewrites carry no status to default: what they do is settled by the
        // destination, and the effective config repeats them as written.
        assert_eq!(
            effective.pointer("/rewrites").cloned(),
            Some(json!([
                { "source": "/app/*", "destination": "/app/index.html" },
                {
                    "source": "/api/*",
                    "destination": "https://api.example.com/:splat",
                    "cache": "shared"
                }
            ]))
        );
        assert_eq!(
            effective.pointer("/headers").cloned(),
            Some(json!([
                { "source": "/*", "headers": [{ "key": "X-Frame-Options", "value": "DENY" }] }
            ]))
        );
    }

    #[test]
    fn rejects_routing_rules_the_file_lane_would_reject() {
        for (source, code, path) in [
            (
                r#"{"version":1,"redirects":[{"source":"old","destination":"/new"}]}"#,
                "redirect_source_invalid",
                "$.redirects[0].source",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a/*b","destination":"/new"}]}"#,
                "redirect_pattern_invalid",
                "$.redirects[0].source",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/loop","destination":"/loop"}]}"#,
                "redirect_loop_invalid",
                "$.redirects[0].destination",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/caf\u00e9","destination":"/cafe\u0301"}]}"#,
                "redirect_loop_invalid",
                "$.redirects[0].destination",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"next"}]}"#,
                "redirect_rewrite_destination_invalid",
                "$.rewrites[0].destination",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/loop","destination":"/loop"}]}"#,
                "redirect_loop_invalid",
                "$.rewrites[0].destination",
            ),
            // 200 is what `_redirects` spells a rewrite with, so it is the one
            // status a publisher will try here. Point at the section that owns
            // it instead of listing codes.
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","status":200}]}"#,
                "redirect_status_use_rewrites",
                "$.redirects[0].status",
            ),
            // Same story as 200: 404 is how `_redirects` spells serve-this-
            // content-as-not-found, and the JSON home for that is a rewrite
            // with `notFound`.
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/404.html","status":404}]}"#,
                "redirect_status_use_rewrites",
                "$.redirects[0].status",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","query":{"1bad":"id"}}]}"#,
                "redirect_query_match_invalid",
                "$.redirects[0].query",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"/b","query":{"id":"1bad"}}]}"#,
                "redirect_query_match_invalid",
                "$.rewrites[0].query",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/gone","destination":"https://example.com/404.html","notFound":true}]}"#,
                "redirect_rewrite_destination_invalid",
                "$.rewrites[0].destination",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"Content-Type","remove":true}]}]}"#,
                "header_name_unsupported",
                "$.headers[0].headers[0].key",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"https://api.example.com/b","cache":"shared"}]}"#,
                "config_unknown_key",
                "$.redirects[0].cache",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"https://ex*ample.com/a","destination":"/b"}]}"#,
                "redirect_host_invalid",
                "$.redirects[0].source",
            ),
            (
                r#"{"version":1,"headers":[{"source":"docs","headers":[{"key":"X-A","value":"b"}]}]}"#,
                "header_path_invalid",
                "$.headers[0].source",
            ),
            (
                r#"{"version":1,"headers":[{"source":"https://example.com:8080/*","headers":[{"key":"X-A","value":"b"}]}]}"#,
                "header_port_unsupported",
                "$.headers[0].source",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X Frame","value":"b"}]}]}"#,
                "header_name_invalid",
                "$.headers[0].headers[0].key",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X-A","value":"b"},{"key":"Content-Length","value":"7"}]}]}"#,
                "header_name_unsupported",
                "$.headers[0].headers[1].key",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"CDN-Cache-Control","value":"max-age=1"}]}]}"#,
                "header_cdn_cache_unsupported",
                "$.headers[0].headers[0].key",
            ),
        ] {
            let output = compile_source(source);
            assert!(!output.success, "{source}");
            assert_eq!(
                output
                    .issues
                    .iter()
                    .map(|issue| (issue.code.as_str(), issue.path.as_str()))
                    .collect::<Vec<_>>(),
                vec![(code, path)],
                "{source}"
            );
        }
    }

    // The typed spellings of the file grammar's extras: `force` is the `!`,
    // `query` the `name=:capture` tokens, `notFound` the 404 status, and a
    // `remove` header entry the `!Name` line. They compile clean and survive
    // into the config and effective documents as written.
    #[test]
    fn compiles_the_full_file_grammar_superset() {
        let output = compile_source(
            r#"{
              "version": 1,
              "redirects": [
                { "source": "/store", "destination": "/blog/:id", "status": 301, "force": true, "query": { "id": "id" } }
              ],
              "rewrites": [
                { "source": "/gone/*", "destination": "/404.html", "notFound": true },
                { "source": "/app/*", "destination": "/app/index.html", "force": true, "query": { "tab": "tab" } }
              ],
              "headers": [
                {
                  "source": "/keep/*",
                  "headers": [
                    { "key": "X-Frame-Options", "remove": true },
                    { "key": "X-Ok", "value": "1" }
                  ]
                }
              ]
            }"#,
        );

        assert!(output.success, "{:?}", output.issues);
        assert_eq!(output.issues.len(), 0);
        let effective = output.effective.expect("effective config");
        assert_eq!(
            effective.pointer("/redirects").cloned(),
            Some(json!([
                {
                    "source": "/store",
                    "destination": "/blog/:id",
                    "status": 301,
                    "force": true,
                    "query": { "id": "id" }
                }
            ]))
        );
        assert_eq!(
            effective.pointer("/rewrites").cloned(),
            Some(json!([
                { "source": "/gone/*", "destination": "/404.html", "notFound": true },
                {
                    "source": "/app/*",
                    "destination": "/app/index.html",
                    "force": true,
                    "query": { "tab": "tab" }
                }
            ]))
        );
        assert_eq!(
            effective.pointer("/headers/0/headers").cloned(),
            Some(json!([
                { "key": "X-Frame-Options", "remove": true },
                { "key": "X-Ok", "value": "1" }
            ]))
        );
    }

    // The strict lane is authoring-time and has no variable values. A rule
    // string the server has not filled in yet cannot be judged here — and the
    // `_redirects` lane it mirrors is not judged locally at all, so judging this
    // one would make the JSON spelling the stricter of the two.
    #[test]
    fn rules_awaiting_substitution_are_not_judged_at_authoring_time() {
        for source in [
            r#"{"version":1,"rewrites":[{"source":"/api/*","destination":"{{ vars.API_HOST }}/:splat"}]}"#,
            r#"{"version":1,"redirects":[{"source":"/a","destination":"{{ vars.TARGET }}"}]}"#,
            r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X-Env","value":"{{ vars.ENV }}"}]}]}"#,
        ] {
            let output = compile_source(source);
            assert!(output.success, "{source} {:?}", output.issues);
            assert_eq!(output.issues.len(), 0, "{source}");
        }

        // Structure is still structure: an unknown key inside the entry is a
        // shape problem, not a value the server will fill in.
        let malformed = compile_source(
            r#"{"version":1,"rewrites":[{"source":"/a","destination":"{{ vars.X }}","permanent":true}]}"#,
        );
        assert!(!malformed.success);
        assert_eq!(
            malformed
                .issues
                .iter()
                .map(|issue| (issue.code.as_str(), issue.path.as_str()))
                .collect::<Vec<_>>(),
            vec![("config_unknown_key", "$.rewrites[0].permanent")]
        );
    }

    #[test]
    fn allows_shared_caching_only_on_proxy_rules() {
        let proxy = compile_source(
            r#"{"version":1,"rewrites":[{"source":"/api/*","destination":"https://api.example.com/:splat","cache":"shared"}]}"#,
        );
        assert!(proxy.success, "{:?}", proxy.issues);

        // Same verdict the `cache=shared` directive gets in `_redirects`: a
        // warning that drops the caching and keeps the route. A misplaced cache
        // mode is not worth the rule.
        for source in [
            r#"{"version":1,"rewrites":[{"source":"/a/*","destination":"/b/:splat","cache":"shared"}]}"#,
            r#"{"version":1,"rewrites":[{"source":"/app/*","destination":"/app/index.html","cache":"shared"}]}"#,
        ] {
            let output = compile_source(source);
            assert!(output.success, "{source}");
            assert_eq!(
                output
                    .issues
                    .iter()
                    .map(|issue| (issue.code.as_str(), issue.severity, issue.path.as_str()))
                    .collect::<Vec<_>>(),
                vec![("redirect_cache_not_proxy", "warning", "$.rewrites[0].cache")],
                "{source}"
            );
        }

        let invalid_mode = compile_source(
            r#"{"version":1,"rewrites":[{"source":"/api/*","destination":"https://api.example.com/:splat","cache":"public"}]}"#,
        );
        assert!(!invalid_mode.success);
        assert_eq!(
            invalid_mode
                .issues
                .iter()
                .map(|issue| (issue.code.as_str(), issue.path.as_str()))
                .collect::<Vec<_>>(),
            vec![("redirect_cache_directive_invalid", "$.rewrites[0].cache")]
        );
    }

    #[test]
    fn rejects_malformed_routing_entries_with_config_codes() {
        for (source, code, path) in [
            (
                r#"{"version":1,"redirects":{}}"#,
                "config_invalid",
                "$.redirects",
            ),
            (
                r#"{"version":1,"redirects":["/a /b"]}"#,
                "config_invalid",
                "$.redirects[0]",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a"}]}"#,
                "config_invalid",
                "$.redirects[0]",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":7}]}"#,
                "config_invalid",
                "$.redirects[0].destination",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","status":"301"}]}"#,
                "config_invalid",
                "$.redirects[0].status",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","permanent":true}]}"#,
                "config_unknown_key",
                "$.redirects[0].permanent",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"/b","status":200}]}"#,
                "config_unknown_key",
                "$.rewrites[0].status",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"/b","cache":7}]}"#,
                "config_invalid",
                "$.rewrites[0].cache",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*"}]}"#,
                "config_invalid",
                "$.headers[0]",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[]}]}"#,
                "config_invalid",
                "$.headers[0].headers",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X-A"}]}]}"#,
                "config_invalid",
                "$.headers[0].headers[0]",
            ),
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X-A","value":"b","force":true}]}]}"#,
                "config_unknown_key",
                "$.headers[0].headers[0].force",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","force":"yes"}]}"#,
                "config_invalid",
                "$.redirects[0].force",
            ),
            (
                r#"{"version":1,"redirects":[{"source":"/a","destination":"/b","query":["id"]}]}"#,
                "config_invalid",
                "$.redirects[0].query",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"/b","query":{"id":7}}]}"#,
                "config_invalid",
                "$.rewrites[0].query",
            ),
            (
                r#"{"version":1,"rewrites":[{"source":"/a","destination":"/b","notFound":"yes"}]}"#,
                "config_invalid",
                "$.rewrites[0].notFound",
            ),
            // A remove entry must not carry a value and a set entry must have
            // one: an entry that is neither shape is not either operation.
            (
                r#"{"version":1,"headers":[{"source":"/*","headers":[{"key":"X-A","value":"b","remove":true}]}]}"#,
                "config_invalid",
                "$.headers[0].headers[0]",
            ),
        ] {
            let output = compile_source(source);
            assert!(!output.success, "{source}");
            assert!(
                output
                    .issues
                    .iter()
                    .any(|issue| issue.code == code && issue.path == path),
                "{source} {:?}",
                output.issues
            );
        }
    }

    #[test]
    fn keeps_a_cache_control_advisory_without_rejecting_the_config() {
        let output = compile_source(
            r#"{"version":1,"headers":[{"source":"/assets/*","headers":[{"key":"Cache-Control","value":"max-age=60"}]}]}"#,
        );

        assert!(output.success, "{:?}", output.issues);
        assert_eq!(
            output
                .issues
                .iter()
                .map(|issue| (issue.severity, issue.code.as_str(), issue.path.as_str()))
                .collect::<Vec<_>>(),
            vec![(
                "warning",
                "header_cache_control_platform_managed",
                "$.headers[0].headers[0].key"
            )]
        );
    }

    #[test]
    fn reports_rule_problems_at_the_key_that_caused_them() {
        let output = compile_source(
            "{\n  \"version\": 1,\n  \"metadata\": { \"title\": \"café 😀\", \"description\": \"x\" },\n  \"redirects\": [\n    { \"source\": \"/ok\", \"destination\": \"/fine\" },\n    { \"source\": \"bad\", \"destination\": \"/new\" }\n  ]\n}",
        );

        assert!(!output.success);
        let issue = output.issues.first().expect("issue");
        assert_eq!(issue.path, "$.redirects[1].source");
        assert_eq!((issue.line, issue.column), (6, 7));
        assert_eq!(
            output
                .locations
                .get("$.metadata.description")
                .map(|location| (location.line, location.column)),
            Some((3, 37))
        );
    }

    #[test]
    fn rejects_basic_auth_headers_without_echoing_credentials() {
        let output = compile_source(
            r#"{"version":1,"headers":[{"source":"/private","headers":[{"key":"Basic-Auth","value":"alice:supersecret"}]}]}"#,
        );

        assert!(!output.success);
        assert_eq!(
            output
                .issues
                .iter()
                .map(|issue| (issue.code.as_str(), issue.path.as_str()))
                .collect::<Vec<_>>(),
            vec![(
                "header_basic_auth_unsupported",
                "$.headers[0].headers[0].key"
            )]
        );
        assert!(output
            .issues
            .iter()
            .all(|issue| !issue.message.contains("supersecret")));
    }
}
