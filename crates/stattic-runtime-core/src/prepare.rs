//! Pure preparation for site finalization.
//!
//! This module deliberately has no filesystem, database, network, or process
//! dependencies. Native finalization calls it with external values already
//! resolved by the control plane; CLI and control-plane previews call the same
//! code through the JSON/WASM adapter in `prepare_abi.rs`.

use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;
use unicode_normalization::UnicodeNormalization;

use crate::config::diagnostics::{diagnostic, DiagnosticSeverity, PrepareDiagnostic};
use crate::protocol::{
    CONFIG_ACCEPTED_FILES, TEMPLATE_MAX_BYTES, TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS,
};
use crate::routing::{compile_routing_files, RoutingInput};

pub const ANALYZE_INPUT_FORMAT: &str = "spacefast.finalizer.analyze.input.v1";
pub const PREPARE_INPUT_FORMAT: &str = "spacefast.finalizer.prepare.input.v1";

const SYSTEM_VARIABLE_PREFIX: &str = "SPACEFAST_";

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ConventionFiles {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub redirects: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub headers: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct AnalyzeInput {
    pub format: String,
    /// Raw contents keyed by candidate path. Presence in this map is presence
    /// in the version; the compiler, not the caller, chooses the winner.
    #[serde(default)]
    pub config_candidates: BTreeMap<String, String>,
    #[serde(default)]
    pub convention_files: ConventionFiles,
    /// The `sf.jsonc` this version publishes. Its routing sections state rules
    /// in the same grammar the convention files do, so they resolve the same
    /// `{{ vars.NAME }}` references at the same stage.
    #[serde(default)]
    pub routing_config: Option<RoutingConfig>,
    /// Source bytes keyed by committed path. Only paths declared by the
    /// selected config's `templates` array participate.
    #[serde(default)]
    pub template_sources: BTreeMap<String, String>,
}

/// A `sf.jsonc` as staged: the committed path it was read from (so diagnostics
/// name the file the publisher wrote) and its bytes.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RoutingConfig {
    pub path: String,
    pub source: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct VariableRequirement {
    pub name: String,
    pub system: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct AnalyzeOutput {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub selected_config_path: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub config: Option<Value>,
    pub template_paths: Vec<String>,
    pub variable_requirements: Vec<VariableRequirement>,
    pub diagnostics: Vec<PrepareDiagnostic>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ScopedVariable {
    #[serde(default)]
    pub value: Option<String>,
    #[serde(default)]
    pub secret: bool,
    #[serde(default)]
    pub channel_values: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct VariableScope {
    pub kind: String,
    #[serde(default)]
    pub values: BTreeMap<String, ScopedVariable>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct Channel {
    /// Product channel name used to select a channel value.
    pub name: String,
    /// Runtime route name under which variant bytes are stored.
    // The runtime HTTP contract is snake_case; the in-process/WASI prepare
    // protocol historically used camelCase. Accept both at this shared type
    // without asking either boundary to translate the other one's wire.
    #[serde(rename = "route_name", alias = "routeName")]
    pub route_name: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct PrepareInput {
    pub format: String,
    #[serde(flatten)]
    pub analysis: AnalyzeInputFields,
    /// Ordered narrow resolution set returned by the external-state layer.
    /// Secret values must be represented with `secret: true` and no plaintext.
    #[serde(default)]
    pub variable_scopes: Vec<VariableScope>,
    #[serde(default)]
    pub system_variables: BTreeMap<String, String>,
    #[serde(default)]
    pub channels: Vec<Channel>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct AnalyzeInputFields {
    #[serde(default)]
    pub config_candidates: BTreeMap<String, String>,
    #[serde(default)]
    pub convention_files: ConventionFiles,
    #[serde(default)]
    pub routing_config: Option<RoutingConfig>,
    #[serde(default)]
    pub template_sources: BTreeMap<String, String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct PrepareOutput {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub selected_config_path: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub config: Option<Value>,
    pub template_paths: Vec<String>,
    pub variable_requirements: Vec<VariableRequirement>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub convention_files: Option<ConventionFiles>,
    /// Convention text safe to cross the native finalizer boundary. It is
    /// identical to `convention_files` except that Basic-Auth credential
    /// operations are removed after substitution. The shared Rust routing
    /// compiler produces the unified access verifier hashes; plaintext never
    /// crosses the native finalizer boundary.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub runtime_convention_files: Option<ConventionFiles>,
    /// The `sf.jsonc` source with its routing rules substituted, ready for the
    /// routing compiler. Absent when the caller handed over no config.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub routing_config_source: Option<String>,
    /// Base substituted bytes, present only when different from source.
    pub template_files: BTreeMap<String, String>,
    /// Runtime route name -> path -> substituted bytes.
    pub template_variants: BTreeMap<String, BTreeMap<String, String>>,
    pub substituted_paths: Vec<String>,
    /// Dependency name (`NAME` or `NAME@channel`) -> lowercase SHA-256 hex.
    pub dependencies: BTreeMap<String, String>,
    pub system_dependencies: Vec<String>,
    pub diagnostics: Vec<PrepareDiagnostic>,
}

#[derive(Debug, Clone)]
struct AnalysisState {
    output: AnalyzeOutput,
    sources: Vec<(String, String)>,
}

pub fn analyze(input: AnalyzeInput) -> AnalyzeOutput {
    if input.format != ANALYZE_INPUT_FORMAT {
        return invalid_format("analyze", &input.format);
    }
    analyze_fields(AnalyzeInputFields {
        config_candidates: input.config_candidates,
        convention_files: input.convention_files,
        routing_config: input.routing_config,
        template_sources: input.template_sources,
    })
    .output
}

pub fn prepare(input: PrepareInput) -> PrepareOutput {
    if input.format != PREPARE_INPUT_FORMAT {
        let analysis = invalid_format("prepare", &input.format);
        return PrepareOutput {
            diagnostics: analysis.diagnostics.clone(),
            selected_config_path: analysis.selected_config_path,
            config: analysis.config,
            template_paths: analysis.template_paths,
            variable_requirements: analysis.variable_requirements,
            convention_files: None,
            runtime_convention_files: None,
            routing_config_source: None,
            template_files: BTreeMap::new(),
            template_variants: BTreeMap::new(),
            substituted_paths: Vec::new(),
            dependencies: BTreeMap::new(),
            system_dependencies: Vec::new(),
        };
    }

    let state = analyze_fields(input.analysis.clone());
    let mut diagnostics = state.output.diagnostics.clone();
    let mut sink = SubstitutionSink::default();
    let convention_files = substitute_conventions(
        &input.analysis.convention_files,
        &input.variable_scopes,
        &input.system_variables,
        &mut sink,
    );
    let runtime_convention_files = convention_files.as_ref().and_then(runtime_safe_conventions);
    let routing_config_source = input.analysis.routing_config.as_ref().map(|config| {
        substitute_routing_config(
            &config.source,
            &config.path,
            &input.variable_scopes,
            &input.system_variables,
            &mut sink,
        )
        .unwrap_or_else(|| config.source.clone())
    });
    let mut template_files = BTreeMap::new();
    let mut template_variants: BTreeMap<String, BTreeMap<String, String>> = BTreeMap::new();
    let mut substituted_paths = BTreeSet::new();

    for (path, source) in state.sources {
        if source.len() > TEMPLATE_MAX_BYTES {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Error,
                "template_file_too_large",
                format!(
                    "Template {path} exceeds the {TEMPLATE_MAX_BYTES} byte substitution limit."
                ),
                Some(path),
            ));
            continue;
        }
        let base = substitute_text(
            &source,
            &path,
            None,
            &input.variable_scopes,
            &input.system_variables,
            &mut sink,
        );
        let Some(base) = base else {
            continue;
        };
        if base != source {
            template_files.insert(path.clone(), base.clone());
            substituted_paths.insert(path.clone());
        }
        for channel in &input.channels {
            if !valid_route_name(&channel.route_name) {
                diagnostics.push(diagnostic(
                    DiagnosticSeverity::Error,
                    "template_channel_invalid",
                    format!("Runtime route name {} is invalid.", channel.route_name),
                    Some(path.clone()),
                ));
                continue;
            }
            let variant = substitute_text(
                &source,
                &path,
                Some(&channel.name),
                &input.variable_scopes,
                &input.system_variables,
                &mut sink,
            );
            if let Some(variant) = variant.filter(|variant| variant != &base) {
                template_variants
                    .entry(channel.route_name.clone())
                    .or_default()
                    .insert(path.clone(), variant);
                substituted_paths.insert(path.clone());
            }
        }
    }

    diagnostics.append(&mut sink.diagnostics);
    PrepareOutput {
        selected_config_path: state.output.selected_config_path,
        config: state.output.config,
        template_paths: state.output.template_paths,
        variable_requirements: state.output.variable_requirements,
        convention_files,
        runtime_convention_files,
        routing_config_source,
        template_files,
        template_variants,
        substituted_paths: substituted_paths.into_iter().collect(),
        dependencies: sink.dependencies,
        system_dependencies: sink.system_dependencies.into_iter().collect(),
        diagnostics,
    }
}

pub fn resolve_dependency_digest(scopes: &[VariableScope], dependency: &str) -> Option<String> {
    let (name, channel) = dependency
        .split_once('@')
        .map_or((dependency, None), |(name, channel)| (name, Some(channel)));
    for scope in scopes {
        let Some(variable) = scope.values.get(name) else {
            continue;
        };
        if variable.secret {
            return None;
        }
        let value = channel
            .and_then(|channel| variable.channel_values.get(channel))
            .or(variable.value.as_ref())?;
        return Some(sha256(value));
    }
    None
}

fn analyze_fields(input: AnalyzeInputFields) -> AnalysisState {
    let mut diagnostics = Vec::new();
    let (selected_config_path, config) =
        select_and_parse_config(&input.config_candidates, &mut diagnostics);
    let template_paths = config
        .as_ref()
        .and_then(|value| value.get("templates"))
        .and_then(Value::as_array)
        .map(|values| {
            values
                .iter()
                .filter_map(Value::as_str)
                .filter_map(|path| canonical_template_path(path, &mut diagnostics))
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();

    let mut sources = Vec::new();
    let mut requirements = BTreeSet::new();
    for (path, value) in [
        ("_redirects", input.convention_files.redirects.as_ref()),
        ("_headers", input.convention_files.headers.as_ref()),
    ] {
        if let Some(value) = value {
            collect_requirements(value, &mut requirements);
            sources.push((path.to_string(), value.clone()));
        }
    }
    // A `sf.jsonc` routing rule or cron path needs the same variable a
    // `_redirects` line needs. Markers elsewhere in the document are not
    // substituted, so they are not requirements either.
    if let Some(config) = &input.routing_config {
        for (start, end) in crate::config::strict::routing_string_spans(&config.source) {
            collect_requirements(&config.source[start..end], &mut requirements);
        }
    }
    let mut template_sources = Vec::new();
    for path in &template_paths {
        let Some(source) = input.template_sources.get(path) else {
            diagnostics.push(diagnostic(
                DiagnosticSeverity::Warning,
                "template_not_in_version",
                format!(
                    "templates entry \"{path}\" is not a committed file in this version and was skipped."
                ),
                Some("templates".into()),
            ));
            continue;
        };
        collect_requirements(source, &mut requirements);
        template_sources.push((path.clone(), source.clone()));
    }

    let variable_requirements = requirements
        .into_iter()
        .map(|name| VariableRequirement {
            system: name
                .to_ascii_uppercase()
                .starts_with(SYSTEM_VARIABLE_PREFIX),
            name,
        })
        .collect();
    AnalysisState {
        output: AnalyzeOutput {
            selected_config_path,
            config,
            template_paths,
            variable_requirements,
            diagnostics,
        },
        sources: template_sources,
    }
}

fn select_and_parse_config(
    candidates: &BTreeMap<String, String>,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) -> (Option<String>, Option<Value>) {
    let present = CONFIG_ACCEPTED_FILES
        .iter()
        .copied()
        .filter(|path| candidates.contains_key(*path))
        .collect::<Vec<_>>();
    let Some(selected) = present.first().copied() else {
        return (None, None);
    };
    if selected != CONFIG_ACCEPTED_FILES[0] {
        let mut item = diagnostic(
            DiagnosticSeverity::Info,
            "config_alias_used",
            format!(
                "{selected} was accepted as a Spacefast config alias. Use {} as the canonical config file.",
                CONFIG_ACCEPTED_FILES[0]
            ),
            Some(selected.into()),
        );
        item.details.insert(
            "canonical".into(),
            Value::String(CONFIG_ACCEPTED_FILES[0].into()),
        );
        item.details
            .insert("selected".into(), Value::String(selected.into()));
        diagnostics.push(item);
    }
    for ignored in present.into_iter().skip(1) {
        let mut item = diagnostic(
            DiagnosticSeverity::Warning,
            "config_file_ignored",
            format!(
                "{ignored} was ignored because {selected} is the selected Spacefast config file. Use {}.",
                CONFIG_ACCEPTED_FILES[0]
            ),
            Some(ignored.into()),
        );
        item.details.insert(
            "canonical".into(),
            Value::String(CONFIG_ACCEPTED_FILES[0].into()),
        );
        item.details
            .insert("selected".into(), Value::String(selected.into()));
        item.details
            .insert("ignored".into(), Value::String(ignored.into()));
        diagnostics.push(item);
    }
    let config = candidates
        .get(selected)
        .and_then(|raw| crate::config::current::parse_config(raw, selected, diagnostics));
    (Some(selected.into()), config)
}

fn canonical_template_path(
    value: &str,
    diagnostics: &mut Vec<PrepareDiagnostic>,
) -> Option<String> {
    let normalized = value
        .replace('\\', "/")
        .trim_start_matches('/')
        .nfc()
        .collect::<String>();
    let mut segments = Vec::new();
    let mut invalid = value.is_empty() || value.contains('\0');
    for segment in normalized.split('/') {
        if segment.is_empty() || segment == "." {
            continue;
        }
        if segment == ".." {
            invalid = true;
            break;
        }
        segments.push(segment);
    }
    let path = segments.join("/");
    if invalid || path.is_empty() {
        diagnostics.push(diagnostic(
            DiagnosticSeverity::Error,
            "config_invalid",
            format!("templates entry \"{value}\" is not a valid path."),
            Some("templates".into()),
        ));
        None
    } else {
        Some(path)
    }
}

fn collect_requirements(content: &str, requirements: &mut BTreeSet<String>) {
    for captures in variable_pattern().captures_iter(content) {
        if let Some(name) = captures.get(1) {
            requirements.insert(name.as_str().to_string());
        }
    }
}

#[derive(Default)]
struct SubstitutionSink {
    dependencies: BTreeMap<String, String>,
    system_dependencies: BTreeSet<String>,
    diagnostics: Vec<PrepareDiagnostic>,
}

fn substitute_conventions(
    input: &ConventionFiles,
    scopes: &[VariableScope],
    system: &BTreeMap<String, String>,
    sink: &mut SubstitutionSink,
) -> Option<ConventionFiles> {
    let redirects = input.redirects.as_ref().map(|content| {
        substitute_text(content, "_redirects", None, scopes, system, sink)
            .unwrap_or_else(|| content.clone())
    });
    let headers = input.headers.as_ref().map(|content| {
        substitute_text(content, "_headers", None, scopes, system, sink)
            .unwrap_or_else(|| content.clone())
    });
    (redirects.is_some() || headers.is_some()).then_some(ConventionFiles { redirects, headers })
}

fn runtime_safe_conventions(input: &ConventionFiles) -> Option<ConventionFiles> {
    let redirects = input.redirects.clone();
    let headers = input.headers.as_ref().and_then(|headers| {
        compile_routing_files(&RoutingInput {
            headers: headers.clone(),
            ..RoutingInput::default()
        })
        .sanitized_headers
    });
    (redirects.is_some() || headers.is_some()).then_some(ConventionFiles { redirects, headers })
}

fn substitute_text(
    content: &str,
    path: &str,
    channel: Option<&str>,
    scopes: &[VariableScope],
    system: &BTreeMap<String, String>,
    sink: &mut SubstitutionSink,
) -> Option<String> {
    let mut failed = false;
    let replaced = variable_pattern().replace_all(content, |captures: &regex::Captures<'_>| {
        let whole = captures.get(0).expect("whole variable match");
        let name = captures.get(1).expect("variable name").as_str();
        match resolve_variable(
            name,
            path,
            channel,
            content,
            whole.start(),
            scopes,
            system,
            sink,
        ) {
            Some(value) => value,
            None => {
                failed = true;
                whole.as_str().to_string()
            }
        }
    });
    (!failed).then(|| replaced.into_owned())
}

/// One `{{ vars.NAME }}` reference, resolved. Scope walk, secret refusal, system
/// prefix, dependency recording and the failure diagnostics all live here so
/// that every surface that substitutes — declared templates, `_redirects` /
/// `_headers` text, and the `sf.jsonc` routing rules that spell the same
/// grammar in JSON — resolves a name the same way.
#[allow(clippy::too_many_arguments)]
fn resolve_variable(
    name: &str,
    path: &str,
    channel: Option<&str>,
    content: &str,
    offset: usize,
    scopes: &[VariableScope],
    system: &BTreeMap<String, String>,
    sink: &mut SubstitutionSink,
) -> Option<String> {
    if name
        .to_ascii_uppercase()
        .starts_with(SYSTEM_VARIABLE_PREFIX)
    {
        let Some(value) = system.get(name) else {
            sink.diagnostics.push(variable_diagnostic(
                "template_variable_unresolved",
                format!(
                    "{path} references {{{{ vars.{name} }}}}, which is not a platform-provided value here."
                ),
                path,
                name,
                content,
                offset,
                channel,
            ));
            return None;
        };
        sink.system_dependencies.insert(name.to_string());
        return Some(value.clone());
    }
    for scope in scopes {
        let Some(variable) = scope.values.get(name) else {
            continue;
        };
        if variable.secret || variable.value.is_none() {
            sink.diagnostics.push(variable_diagnostic(
                "secret_variable_in_template",
                format!(
                    "{path} references secret variable {name}; secret values never reach served bytes."
                ),
                path,
                name,
                content,
                offset,
                channel,
            ));
            return None;
        }
        let value = channel
            .and_then(|channel| variable.channel_values.get(channel))
            .or(variable.value.as_ref())
            .expect("non-secret variable has a value");
        let dependency = channel
            .map(|channel| format!("{name}@{channel}"))
            .unwrap_or_else(|| name.to_string());
        sink.dependencies.insert(dependency, sha256(value));
        return Some(value.clone());
    }
    sink.diagnostics.push(variable_diagnostic(
        "template_variable_unresolved",
        format!("{path} references {{{{ vars.{name} }}}}, which is not defined at any scope."),
        path,
        name,
        content,
        offset,
        channel,
    ));
    None
}

/// The substitutable `sf.jsonc` strings, resolved the way `_redirects` text is.
///
/// The file lane substitutes the whole convention file because every byte of it
/// is rule text. A config file is not: it is a JSON document whose routing
/// sections and cron paths happen to hold template strings, and a value carrying
/// a quote or a backslash would rewrite the document around it. So the pass
/// writes into those string literals and nothing else, JSON-escaping each
/// resolved value on the way in. The result is still `sf.jsonc` text on the same
/// lines, which is what keeps rule diagnostics addressable after substitution.
fn substitute_routing_config(
    source: &str,
    path: &str,
    scopes: &[VariableScope],
    system: &BTreeMap<String, String>,
    sink: &mut SubstitutionSink,
) -> Option<String> {
    let spans = crate::config::strict::routing_string_spans(source);
    if spans.is_empty() {
        return Some(source.to_string());
    }
    let mut failed = false;
    let mut output = String::with_capacity(source.len());
    let mut cursor = 0;
    for captures in variable_pattern().captures_iter(source) {
        let whole = captures.get(0).expect("whole variable match");
        if !spans
            .iter()
            .any(|(start, end)| whole.start() >= *start && whole.end() <= *end)
        {
            continue;
        }
        let name = captures.get(1).expect("variable name").as_str();
        let Some(value) = resolve_variable(
            name,
            path,
            None,
            source,
            whole.start(),
            scopes,
            system,
            sink,
        ) else {
            failed = true;
            continue;
        };
        output.push_str(&source[cursor..whole.start()]);
        output.push_str(&json_string_body(&value));
        cursor = whole.end();
    }
    output.push_str(&source[cursor..]);
    (!failed).then_some(output)
}

/// A resolved value as it appears INSIDE a JSON string literal: what
/// `serde_json` would write, minus the quotes it wraps around it.
fn json_string_body(value: &str) -> String {
    let encoded = serde_json::to_string(value).expect("string encodes as JSON");
    encoded[1..encoded.len() - 1].to_string()
}

fn variable_diagnostic(
    code: &str,
    message: String,
    path: &str,
    name: &str,
    content: &str,
    offset: usize,
    channel: Option<&str>,
) -> PrepareDiagnostic {
    let mut item = diagnostic(DiagnosticSeverity::Error, code, message, Some(path.into()));
    item.details
        .insert("variable".into(), Value::String(name.into()));
    item.details
        .insert("template".into(), Value::String(path.into()));
    item.details.insert(
        "line".into(),
        Value::from(
            content.as_bytes()[..offset]
                .iter()
                .filter(|byte| **byte == b'\n')
                .count()
                + 1,
        ),
    );
    if let Some(channel) = channel {
        item.details
            .insert("channel".into(), Value::String(channel.into()));
    }
    item
}

fn valid_route_name(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
}

/// The ONE `{{ vars.NAME }}` syntax. Everything that substitutes, collects a
/// requirement, or decides that a value is not knowable yet reads it from here.
pub(crate) fn variable_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"\{\{\s*vars\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}")
            .expect("variable reference regex compiles")
    })
}

fn sha256(value: &str) -> String {
    format!("{:x}", Sha256::digest(value.as_bytes()))
}

fn invalid_format(operation: &str, actual: &str) -> AnalyzeOutput {
    AnalyzeOutput {
        selected_config_path: None,
        config: None,
        template_paths: Vec::new(),
        variable_requirements: Vec::new(),
        diagnostics: vec![diagnostic(
            DiagnosticSeverity::Error,
            "finalizer_prepare_input_format_invalid",
            format!("Unsupported {operation} input format {actual:?}."),
            Some("format".into()),
        )],
    }
}

#[cfg(test)]
mod tests {
    use super::{
        runtime_safe_conventions, substitute_routing_config, ConventionFiles, ScopedVariable,
        SubstitutionSink, VariableScope,
    };
    use std::collections::BTreeMap;

    fn space_scope(name: &str, value: &str) -> Vec<VariableScope> {
        vec![VariableScope {
            kind: "space".into(),
            values: BTreeMap::from([(
                name.into(),
                ScopedVariable {
                    value: Some(value.into()),
                    secret: false,
                    channel_values: BTreeMap::new(),
                },
            )]),
        }]
    }

    // The routing sections get values written into them; the rest of the
    // document is not a template and keeps its markers. The substituted text is
    // still `sf.jsonc` on the same lines, which is what lets a rule diagnostic
    // reported after substitution point at the rule the publisher wrote.
    #[test]
    fn substitution_writes_into_routing_strings_only_and_escapes_them_as_json() {
        let source = r#"{
  "version": 1,
  "metadata": { "title": "{{ vars.HOST }}" },
  "rewrites": [
    { "source": "/api/*", "destination": "https://{{ vars.HOST }}/:splat" }
  ],
  "crons": [
    { "path": "/jobs/{{ vars.HOST }}", "schedule": "daily" }
  ]
}
"#;
        let mut sink = SubstitutionSink::default();
        let output = substitute_routing_config(
            source,
            "sf.jsonc",
            &space_scope("HOST", r#"api."x".test\"#),
            &BTreeMap::new(),
            &mut sink,
        )
        .expect("substitution succeeds");

        assert!(output.contains(r#""destination": "https://api.\"x\".test\\/:splat""#));
        assert!(output.contains(r#""path": "/jobs/api.\"x\".test\\""#));
        assert!(output.contains(r#""title": "{{ vars.HOST }}""#));
        assert_eq!(output.lines().count(), source.lines().count());
        assert!(sink.diagnostics.is_empty());
        assert_eq!(sink.dependencies.keys().collect::<Vec<_>>(), vec!["HOST"]);
    }

    // Same fail-closed rule the convention files get: an unresolved name fails
    // the operation instead of publishing a rule with a literal marker in it.
    #[test]
    fn an_unresolved_routing_variable_fails_the_pass() {
        let mut sink = SubstitutionSink::default();
        let output = substitute_routing_config(
            r#"{"redirects":[{"source":"/a","destination":"{{ vars.MISSING }}/b"}]}"#,
            "sf.jsonc",
            &[],
            &BTreeMap::new(),
            &mut sink,
        );

        assert!(output.is_none());
        assert_eq!(
            sink.diagnostics
                .iter()
                .map(|item| item.code.as_str())
                .collect::<Vec<_>>(),
            vec!["template_variable_unresolved"]
        );
    }

    #[test]
    fn runtime_headers_strip_valid_and_malformed_basic_auth_source_lines() {
        let secrets = ["valid-secret", "malformed-secret", "dropped-secret"];
        let input = ConventionFiles {
            redirects: None,
            headers: Some(format!(
                "/*\n  Basic-Auth: admin:{}\n  basic-auth malformed:{}\n  ! Basic-Auth: dropped:{}\n  X-Safe: yes\n",
                secrets[0], secrets[1], secrets[2]
            )),
        };

        let safe = runtime_safe_conventions(&input).expect("headers remain");
        let headers = safe.headers.expect("sanitized headers");
        assert_eq!(headers, "/*\n  X-Safe: yes\n");
        for secret in secrets {
            assert!(!headers.contains(secret));
        }
    }
}
