//! Strict `sf.jsonc` v1 compiler. This is the same pure parser exposed to the
//! CLI through WASM; filesystem containment remains a CLI responsibility.

use super::jsonc::parse_document;
use crate::protocol::{CONFIG_FILE_MAX_BYTES, CONFIG_TEMPLATE_LIMIT};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

const FORMAT: &str = "spacefast.config.strict-v1.input.v1";
const SCHEMA: &str = "https://spacefast.com/schemas/sf.v1.json";

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

#[derive(Debug, Clone, Serialize)]
pub struct Location {
    pub line: usize,
    pub column: usize,
}

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
    let document = match parse_document(&input.source) {
        Ok(document) => document,
        Err(error) => {
            let loc = locate(&input.source, error.offset);
            return invalid(&error.message, loc.line, loc.column);
        }
    };
    let mut locations = document
        .key_offsets
        .iter()
        .map(|(path, offset)| (path.clone(), locate(&input.source, *offset)))
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
    validate_templates(root, &input.template_sources, &locations, &mut issues);
    validate_artifacts(
        root,
        input.artifact_paths.as_deref(),
        &locations,
        &mut issues,
    );
    if !issues.is_empty() {
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
        "metadata",
        "substitute",
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
        "access",
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
            if n.fract() != 0.0 || !(60.0..=7200.0).contains(&n) {
                push_issue(
                    issues,
                    locations,
                    "config_invalid",
                    "$.build.timeoutSeconds",
                    "build.timeoutSeconds must be an integer from 60 to 7200.",
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
                    "substitute.files supports up to 100 entries.",
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
    if let Some(meta) = root.get("metadata").and_then(Value::as_object) {
        for (key, cap) in [("title", 300), ("description", 1000), ("image", 2000)] {
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
    for key in ["metadata", "substitute"] {
        if let Some(v) = config.get(key) {
            out.insert(key.into(), v.clone());
        }
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
    !v.is_empty()
        && !is_absolute_or_escaping(v)
        && !regex::Regex::new(r"^[A-Za-z][A-Za-z0-9+.-]*:")
            .unwrap()
            .is_match(v)
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
fn locate(source: &str, offset: usize) -> Location {
    let prefix = &source[..offset.min(source.len())];
    let line = prefix.bytes().filter(|b| *b == b'\n').count() + 1;
    let start = prefix.rfind('\n').map_or(0, |i| i + 1);
    Location {
        line,
        column: source[start..offset.min(source.len())]
            .encode_utf16()
            .count()
            + 1,
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
