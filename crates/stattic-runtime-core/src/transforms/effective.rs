use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::collections::BTreeSet;
use unicode_normalization::UnicodeNormalization;

use crate::serving_paths::is_public_serving_path;

const CONFIG_KEYS: &[&str] = &[
    "index",
    "fallback",
    "cleanUrls",
    "listing",
    "meta",
    "superpowers",
    "templates",
    "theme",
    "pages",
    "build",
    "placement",
    "markdownNegotiation",
    "experimental_gutenberg",
    "inject",
    "access",
];

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ResolveEffectiveInput {
    #[serde(default)]
    pub manifest_paths: Vec<String>,
    #[serde(default)]
    pub file_config: Option<Map<String, Value>>,
    #[serde(default)]
    pub overlay: Option<Map<String, Value>>,
    #[serde(default)]
    pub template_entries: Vec<String>,
    /// Whether the published tree carries a Functions worker. Derived from the
    /// version's compiled metadata, never from a declared kind — and
    /// independent of whether the same tree also carries a capsule.
    #[serde(default)]
    pub has_worker: bool,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResolveEffectiveOutput {
    pub config: Map<String, Value>,
    pub serving: Value,
    pub runtime_payload_base: Value,
    pub issues: Vec<EffectiveIssue>,
    pub templates: Vec<String>,
    pub template_issues: Vec<EffectiveIssue>,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RuntimePayloadInput {
    pub serving: Value,
    #[serde(default)]
    pub options: Map<String, Value>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EffectiveIssue {
    pub severity: &'static str,
    pub code: &'static str,
    pub message: String,
    pub path: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub details: Option<Value>,
}

pub fn resolve_effective_config(input: ResolveEffectiveInput) -> ResolveEffectiveOutput {
    let manifest = input.manifest_paths.into_iter().collect::<BTreeSet<_>>();
    let (templates, template_issues) = resolve_templates(&input.template_entries, &manifest);
    let public_manifest = manifest
        .iter()
        .filter(|path| is_public_serving_path(path, |source| manifest.contains(source)))
        .cloned()
        .collect::<BTreeSet<_>>();
    let public_html = public_manifest
        .iter()
        .filter(|path| html_path(path))
        .cloned()
        .collect::<Vec<_>>();
    let root_html = public_html
        .iter()
        .filter(|path| !path.contains('/'))
        .cloned()
        .collect::<Vec<_>>();
    let has_root_index = public_manifest.contains("index.html");
    let single_public_file_html = (public_manifest.len() == 1)
        .then(|| public_manifest.first().cloned())
        .flatten()
        .filter(|path| html_path(path));
    let inferred_index = if has_root_index {
        Some("index.html".to_string())
    } else if single_public_file_html.is_some() {
        single_public_file_html
    } else if root_html.len() == 1 {
        root_html.first().cloned()
    } else {
        None
    };
    let explicit_serving_shape = input
        .file_config
        .as_ref()
        .is_some_and(has_explicit_serving_shape)
        || input
            .overlay
            .as_ref()
            .is_some_and(has_explicit_serving_shape);

    // Content-inferred defaults. A Functions version is an application even
    // when it carries no root HTML: unresolved requests, `/` included, belong
    // to its worker, so it never falls back to a directory listing. Static
    // versions keep the content inference — a root index.html means website
    // mode, any other single root HTML becomes the homepage rewrite, and no
    // inferable index means files mode.
    let listing_default = inferred_index.is_none() && !input.has_worker;
    let mut config = Map::new();
    config.insert(
        "index".into(),
        inferred_index
            .clone()
            .map_or(Value::Bool(false), Value::String),
    );
    // `listing` is defaulted AFTER the merge (below), because the inferred
    // default depends on the merged `fallback`.
    for layer in [input.file_config.as_ref(), input.overlay.as_ref()]
        .into_iter()
        .flatten()
    {
        for key in CONFIG_KEYS {
            if let Some(value) = layer.get(*key) {
                config.insert((*key).into(), value.clone());
            }
        }
    }

    let fallback = normalize_fallback(config.get("fallback"));
    let mut issues = Vec::new();
    if let Some(path) = fallback
        .as_ref()
        .and_then(|value| value.get("path"))
        .and_then(Value::as_str)
    {
        if !manifest.contains(path) {
            issues.push(EffectiveIssue {
                severity: "error",
                code: "config_invalid",
                message: format!(
                    "fallback path \"/{path}\" is not a committed file in this version."
                ),
                path: "fallback.path",
                details: Some(json!({ "path": path })),
            });
        }
    }
    // A 200 fallback is a homepage declaration: it names the shell that owns
    // every unresolved application route, `/` first among them. Inferring a
    // directory listing there would compile a real `/` entry that shadows the
    // shell and turns the app into a file browser. A 404 fallback is a custom
    // not-found page, not SPA routing (same reading as the cleanUrls default
    // below), so it leaves files mode alone. An explicit `listing` still wins —
    // this only changes the inferred default.
    let spa_fallback = fallback
        .as_ref()
        .and_then(|value| value.get("status"))
        .and_then(Value::as_u64)
        == Some(200);
    let listing = config
        .get("listing")
        .and_then(Value::as_bool)
        .unwrap_or(listing_default && !spa_fallback);
    config
        .entry("listing")
        .or_insert_with(|| Value::Bool(listing));
    let index = config.get("index").cloned().unwrap_or_else(|| {
        inferred_index
            .clone()
            .map_or(Value::Bool(false), Value::String)
    });
    let clean_urls = config
        .get("cleanUrls")
        .and_then(Value::as_bool)
        .unwrap_or_else(|| {
            fallback
                .as_ref()
                .and_then(|value| value.get("status"))
                .and_then(Value::as_u64)
                != Some(200)
        });
    let pages_theme = config
        .get("theme")
        .and_then(Value::as_object)
        .filter(|value| !value.is_empty())
        .cloned()
        .map(Value::Object);
    let experimental = config
        .get("experimental_gutenberg")
        .and_then(Value::as_bool)
        == Some(true);
    let inject = config
        .get("inject")
        .cloned()
        .filter(|value| !value.is_null());
    let meta = config.get("meta").cloned().filter(|value| !value.is_null());

    if inferred_index
        .as_deref()
        .is_some_and(|value| value != "index.html")
        && index.as_str() == inferred_index.as_deref()
    {
        let selected = inferred_index.as_deref().expect("checked inferred index");
        issues.push(EffectiveIssue {
            severity: "info",
            code: "single_html_index",
            message: format!("{selected} is the root HTML file selected for this version, so it serves as the homepage: / rewrites to /{selected}."),
            path: "index",
            details: Some(json!({
                "path": selected,
                "publicFileCount": public_manifest.len(),
                "publicHtmlCount": public_html.len(),
                "rootHtmlCount": root_html.len()
            })),
        });
    }
    if inferred_index.is_none() && index == Value::Bool(false) && listing && !explicit_serving_shape
    {
        let (message, details) = if public_html.is_empty() {
            (
                "No HTML files were found in the root of this version, so it serves in files mode with directory listing enabled.".into(),
                json!({ "publicHtmlCount": 0, "rootHtmlCount": 0 }),
            )
        } else {
            (
                format!(
                    "{} HTML files are possible homepage candidates, so the version serves in files mode until an index is selected.",
                    public_html.len()
                ),
                json!({ "publicHtmlCount": public_html.len(), "rootHtmlCount": root_html.len() }),
            )
        };
        issues.push(EffectiveIssue {
            severity: "info",
            code: "files_mode_inferred",
            message,
            path: "listing",
            details: Some(details),
        });
    }
    // The inferred default above never produces this pair any more, so reaching
    // here means the publisher asked for both. They still contradict each other:
    // a listing compiles a real `/` entry, and the runtime answers an exact key
    // before it ever consults the fallback — so the shell that was named as the
    // homepage silently never serves as one. Say so rather than let a homepage
    // quietly become a file browser.
    if listing && spa_fallback {
        let path = fallback
            .as_ref()
            .and_then(|value| value.get("path"))
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_string();
        issues.push(EffectiveIssue {
            severity: "warning",
            code: "listing_shadows_fallback",
            message: format!(
                "listing is on and fallback \"/{path}\" is configured, so the directory listing answers / and the fallback never serves as the homepage. Turn listing off to make /{path} the homepage, or drop the fallback to keep the file listing."
            ),
            path: "listing",
            details: Some(json!({ "fallbackPath": path })),
        });
    }

    let serving = json!({
        "index": index,
        "fallback": fallback,
        "cleanUrls": clean_urls,
        "listing": listing,
        "viewer": listing,
        "meta": meta,
        "pagesTheme": pages_theme,
        "experimentalGutenberg": experimental,
        "inject": inject,
    });
    let mut runtime = Map::new();
    runtime.insert("index".into(), serving["index"].clone());
    runtime.insert("fallback".into(), serving["fallback"].clone());
    runtime.insert("clean_urls".into(), Value::Bool(clean_urls));
    runtime.insert("listing".into(), Value::Bool(listing));
    runtime.insert("viewer".into(), Value::Bool(listing));
    runtime.insert("meta".into(), serving["meta"].clone());
    if experimental {
        runtime.insert("experimental_gutenberg".into(), Value::Bool(true));
        if let Some(inject) = inject {
            runtime.insert("inject".into(), inject);
        }
    }

    ResolveEffectiveOutput {
        config,
        serving,
        runtime_payload_base: Value::Object(runtime),
        issues,
        templates,
        template_issues,
    }
}

pub fn build_runtime_payload(input: RuntimePayloadInput) -> Value {
    let serving = input.serving.as_object().cloned().unwrap_or_default();
    let experimental = serving
        .get("experimentalGutenberg")
        .and_then(Value::as_bool)
        == Some(true);
    let user_inject = experimental
        .then(|| serving.get("inject").and_then(Value::as_object))
        .flatten();
    let platform_inject = input
        .options
        .get("platformInject")
        .and_then(Value::as_object);
    let mut inject = Map::new();
    for key in ["head", "bodyStart", "bodyEnd", "noscript"] {
        let values = user_inject
            .and_then(|source| source.get(key))
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .chain(
                platform_inject
                    .and_then(|source| source.get(key))
                    .and_then(Value::as_array)
                    .into_iter()
                    .flatten(),
            )
            .cloned()
            .collect::<Vec<_>>();
        if !values.is_empty() {
            inject.insert(key.into(), Value::Array(values));
        }
    }

    let mut payload = Map::new();
    for (source, target) in [
        ("index", "index"),
        ("fallback", "fallback"),
        ("cleanUrls", "clean_urls"),
        ("listing", "listing"),
        ("viewer", "viewer"),
        ("meta", "meta"),
    ] {
        payload.insert(
            target.into(),
            serving.get(source).cloned().unwrap_or(Value::Null),
        );
    }
    if experimental {
        payload.insert("experimental_gutenberg".into(), Value::Bool(true));
    }
    if !inject.is_empty() {
        payload.insert("inject".into(), Value::Object(inject));
    }
    if input.options.get("platformMeta").and_then(Value::as_bool) == Some(true) {
        payload.insert("platform_meta".into(), Value::Bool(true));
    }
    if let Some(sdk) = input.options.get("spacefastSdk").and_then(Value::as_object) {
        let mut lowered = Map::new();
        if let Some(value) = trimmed(sdk.get("castApiBase")) {
            let normalized = value.trim_end_matches('/');
            if !normalized.is_empty() {
                lowered.insert("cast_api_base".into(), Value::String(normalized.into()));
            }
        }
        if let Some(value) = trimmed(sdk.get("castWsUrl")) {
            lowered.insert("cast_ws_url".into(), Value::String(value.into()));
        }
        if let Some(value) = trimmed(sdk.get("castResourceKey")) {
            lowered.insert("cast_resource_key".into(), Value::String(value.into()));
        }
        if let Some(value) = sdk.get("publishedComments").and_then(Value::as_bool) {
            lowered.insert("published_comments".into(), Value::Bool(value));
        }
        if !lowered.is_empty() {
            payload.insert("spacefast_sdk".into(), Value::Object(lowered));
        }
    }
    if let Some(page_pointers) = input
        .options
        .get("pagePointers")
        .filter(|value| !value.is_null())
    {
        payload.insert("pages".into(), page_pointers.clone());
    } else {
        let pages_theme = if input.options.contains_key("pagesThemeOverride") {
            input.options.get("pagesThemeOverride")
        } else {
            serving.get("pagesTheme")
        }
        .filter(|value| !value.is_null());
        let theme_vars = input
            .options
            .get("pagesThemeVars")
            .and_then(Value::as_str)
            .filter(|value| !value.is_empty());
        let white_label = input
            .options
            .get("pagesWhiteLabel")
            .and_then(Value::as_bool)
            == Some(true);
        if pages_theme.is_some() || theme_vars.is_some() || white_label {
            let mut pages = Map::new();
            if let Some(theme) = pages_theme {
                pages.insert("theme".into(), theme.clone());
            }
            if let Some(vars) = theme_vars {
                pages.insert("theme_vars".into(), Value::String(vars.into()));
            }
            if white_label {
                pages.insert("white_label".into(), Value::Bool(true));
            }
            payload.insert("pages".into(), Value::Object(pages));
        }
    }
    Value::Object(payload)
}

fn trimmed(value: Option<&Value>) -> Option<&str> {
    value
        .and_then(Value::as_str)
        .map(str::trim)
        .filter(|value| !value.is_empty())
}

fn resolve_templates(
    entries: &[String],
    manifest: &BTreeSet<String>,
) -> (Vec<String>, Vec<EffectiveIssue>) {
    let mut templates = Vec::new();
    let mut issues = Vec::new();
    for entry in entries {
        let canonical = match normalize_version_path(entry) {
            Some(path) => path,
            None => {
                issues.push(EffectiveIssue {
                    severity: "error",
                    code: "config_invalid",
                    message: format!("templates entry {entry:?} is not a valid path."),
                    path: "templates",
                    details: None,
                });
                continue;
            }
        };
        if let Some((code, message)) = static_path_violation(&canonical) {
            issues.push(EffectiveIssue {
                severity: "error",
                code,
                message: format!("templates entry {canonical:?}: {message}"),
                path: "templates",
                details: None,
            });
            continue;
        }
        if !manifest.contains(&canonical) {
            issues.push(EffectiveIssue {
                severity: "warning",
                code: "template_not_in_version",
                message: format!("templates entry {canonical:?} is not a committed file in this version and was skipped."),
                path: "templates",
                details: Some(json!({ "path": canonical })),
            });
            continue;
        }
        templates.push(canonical);
    }
    (templates, issues)
}

fn normalize_version_path(input: &str) -> Option<String> {
    let normalized = input
        .replace('\\', "/")
        .trim_start_matches('/')
        .nfc()
        .collect::<String>();
    let mut segments = Vec::new();
    for segment in normalized.split('/') {
        if segment.is_empty() || segment == "." {
            continue;
        }
        if segment == ".." {
            return None;
        }
        segments.push(segment);
    }
    (!segments.is_empty()).then(|| segments.join("/"))
}

fn static_path_violation(path: &str) -> Option<(&'static str, &'static str)> {
    if path.len() > 1024 {
        return Some((
            "path_too_long",
            "File paths support up to 1024 bytes in canonical form.",
        ));
    }
    let lower = path.to_ascii_lowercase();
    let segments = path.split('/').collect::<Vec<_>>();
    if segments
        .iter()
        .any(|segment| segment.ends_with('.') || segment.ends_with(' '))
    {
        return Some(("invalid_file_path", "File path is invalid."));
    }
    let basename = segments.last().expect("normalized path has a segment");
    if [".htaccess", ".user.ini"].contains(&basename.to_ascii_lowercase().as_str()) {
        return Some((
            "static_control_file_not_supported",
            "Static deploys cannot upload execution-control files.",
        ));
    }
    if segments.iter().any(|segment| {
        let segment = segment.to_ascii_lowercase();
        segment.starts_with("__spacefast") || segment.starts_with("__stattic")
    }) || [
        ".spacefast/storage",
        ".spacefast/engine",
        ".stattic/storage",
        ".stattic/engine",
    ]
    .iter()
    .any(|prefix| lower == *prefix || lower.starts_with(&format!("{prefix}/")))
        || [
            ".well-known/spacefast-runtime",
            ".well-known/stattic-runtime",
        ]
        .contains(&lower.as_str())
    {
        return Some((
            "static_runtime_control_path_not_supported",
            "Static deploys cannot upload runtime control paths.",
        ));
    }
    None
}

fn normalize_fallback(value: Option<&Value>) -> Option<Value> {
    match value {
        Some(Value::String(path)) => Some(json!({
            "path": path.trim_start_matches('/'),
            "status": 200,
        })),
        Some(Value::Object(value)) => {
            let path = value.get("path")?.as_str()?.trim_start_matches('/');
            let status = if value.get("status").and_then(Value::as_u64) == Some(404) {
                404
            } else {
                200
            };
            Some(json!({ "path": path, "status": status }))
        }
        _ => None,
    }
}

fn has_explicit_serving_shape(config: &Map<String, Value>) -> bool {
    config.contains_key("index") || config.contains_key("listing")
}

fn html_path(path: &str) -> bool {
    !path.is_empty() && {
        let lower = path.to_ascii_lowercase();
        lower.ends_with(".html") || lower.ends_with(".htm")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn infers_single_html_and_builds_wire_payload() {
        let output = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: vec!["landing.html".into()],
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(output.serving["index"], "landing.html");
        assert_eq!(output.runtime_payload_base["clean_urls"], true);
        assert_eq!(output.issues[0].code, "single_html_index");
    }

    #[test]
    fn runtime_payload_preserves_compiled_page_pointers() {
        let page_pointers = json!({
            "routes": {
                "/": {
                    "pages": {
                        "not-found": "page-not-found"
                    },
                    "index": "page-index"
                }
            },
            "previews": {
                "index.html": "preview-index"
            }
        });
        let output = build_runtime_payload(RuntimePayloadInput {
            serving: json!({
                "index": "index.html",
                "fallback": null,
                "cleanUrls": true,
                "listing": false,
                "viewer": false,
                "meta": null
            }),
            options: Map::from_iter([("pagePointers".into(), page_pointers.clone())]),
        });
        assert_eq!(output["pages"], page_pointers);
    }

    #[test]
    fn spa_fallback_disables_clean_urls_by_default() {
        let output = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: vec!["index.html".into()],
            file_config: Some(Map::from_iter([(
                "fallback".into(),
                Value::String("/index.html".into()),
            )])),
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(output.serving["cleanUrls"], false);
    }

    /// Two root HTML files and no `index.html` leave no inferable homepage, so
    /// the version serves in files mode — UNLESS the publisher already declared
    /// one. A 200 fallback names the shell that owns `/`, and inferring a
    /// listing over it compiles a real `/` entry that shadows the shell and
    /// turns an app into a file browser. A 404 fallback is a custom not-found
    /// page, not SPA routing, so a genuine file drop that ships one still lists.
    #[test]
    fn multiple_root_html_files_without_index_use_files_mode() {
        let manifest = || {
            vec![
                "home.html".to_string(),
                "about.html".to_string(),
                "404.html".to_string(),
            ]
        };
        let resolve = |file_config: Option<Map<String, Value>>| {
            resolve_effective_config(ResolveEffectiveInput {
                manifest_paths: manifest(),
                file_config,
                overlay: None,
                template_entries: Vec::new(),
                has_worker: false,
            })
        };

        let inferred = resolve(None);
        assert_eq!(inferred.serving["index"], false);
        assert_eq!(inferred.serving["listing"], true);
        assert!(inferred
            .issues
            .iter()
            .any(|issue| issue.code == "files_mode_inferred"));

        let spa = resolve(Some(Map::from_iter([(
            "fallback".into(),
            Value::String("/home.html".into()),
        )])));
        assert_eq!(spa.serving["listing"], false);
        assert_eq!(spa.config["listing"], false);
        assert!(!spa
            .issues
            .iter()
            .any(|issue| issue.code == "files_mode_inferred"));

        let custom_not_found = resolve(Some(Map::from_iter([(
            "fallback".into(),
            json!({ "path": "/404.html", "status": 404 }),
        )])));
        assert_eq!(custom_not_found.serving["listing"], true);

        // An explicit `listing` still wins — the fallback only moves the
        // inferred default. The publisher gets a warning instead, because the
        // listing they asked for will shadow the shell at `/`.
        let explicit = resolve(Some(Map::from_iter([
            ("fallback".into(), Value::String("/home.html".into())),
            ("listing".into(), Value::Bool(true)),
        ])));
        assert_eq!(explicit.serving["listing"], true);
        let warning = explicit
            .issues
            .iter()
            .find(|issue| issue.code == "listing_shadows_fallback")
            .expect("listing/fallback contradiction is reported");
        assert_eq!(warning.severity, "warning");
    }

    #[test]
    fn private_files_do_not_change_single_public_html_inference() {
        let output = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: vec!["page.html".into(), "_headers".into(), ".hidden.html".into()],
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(output.serving["index"], "page.html");
        assert_eq!(output.serving["listing"], false);
    }

    #[test]
    fn one_root_html_with_public_assets_is_the_inferred_index() {
        let output = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: vec!["page.html".into(), "app.js".into()],
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(output.serving["index"], "page.html");
        assert_eq!(output.serving["listing"], false);
    }

    /// A Functions version is an application: its worker owns every unresolved
    /// request, `/` included, so a tree with no inferable root HTML must serve
    /// neither an index nor a directory listing. The same tree without a worker
    /// keeps files mode.
    #[test]
    fn a_functions_version_without_root_html_serves_no_index_and_no_listing() {
        let manifest = || vec!["assets/app.js".to_string(), "data/items.json".to_string()];

        let worker = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: manifest(),
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: true,
        });
        assert_eq!(worker.serving["index"], false);
        assert_eq!(worker.serving["listing"], false);
        assert_eq!(worker.serving["viewer"], false);
        assert_eq!(worker.config["listing"], false);
        assert!(
            !worker
                .issues
                .iter()
                .any(|issue| issue.code == "files_mode_inferred"),
            "a worker version never falls back to files mode"
        );

        let static_version = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: manifest(),
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(static_version.serving["index"], false);
        assert_eq!(static_version.serving["listing"], true);
        assert_eq!(static_version.issues[0].code, "files_mode_inferred");
    }

    #[test]
    fn hidden_control_zero_and_sidecar_paths_do_not_change_single_html_inference() {
        for companion in ["SF.JSONC", "zero/endpoints-index.json", "docs/page.html.gz"] {
            let output = resolve_effective_config(ResolveEffectiveInput {
                manifest_paths: vec!["docs/page.html".into(), companion.into()],
                file_config: None,
                overlay: None,
                template_entries: Vec::new(),
                has_worker: false,
            });
            assert_eq!(output.serving["index"], "docs/page.html", "{companion}");
            assert_eq!(output.serving["listing"], false, "{companion}");
        }

        let uppercase_convention = resolve_effective_config(ResolveEffectiveInput {
            manifest_paths: vec!["docs/page.html".into(), "_HEADERS".into()],
            file_config: None,
            overlay: None,
            template_entries: Vec::new(),
            has_worker: false,
        });
        assert_eq!(uppercase_convention.serving["index"], false);
        assert_eq!(uppercase_convention.serving["listing"], true);
    }
}
