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

/// Highest-precedence first. This is the product contract, not filesystem
/// discovery order.
pub const CONFIG_PRECEDENCE: &[&str] = CONFIG_ACCEPTED_FILES;

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
    /// Source bytes keyed by committed path. Only paths declared by the
    /// selected config's `templates` array participate.
    #[serde(default)]
    pub template_sources: BTreeMap<String, String>,
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
    let present = CONFIG_PRECEDENCE
        .iter()
        .copied()
        .filter(|path| candidates.contains_key(*path))
        .collect::<Vec<_>>();
    let Some(selected) = present.first().copied() else {
        return (None, None);
    };
    if selected != CONFIG_PRECEDENCE[0] {
        let mut item = diagnostic(
            DiagnosticSeverity::Info,
            "config_alias_used",
            format!(
                "{selected} was accepted as a Spacefast config alias. Use {} as the canonical config file.",
                CONFIG_PRECEDENCE[0]
            ),
            Some(selected.into()),
        );
        item.details.insert(
            "canonical".into(),
            Value::String(CONFIG_PRECEDENCE[0].into()),
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
                CONFIG_PRECEDENCE[0]
            ),
            Some(ignored.into()),
        );
        item.details.insert(
            "canonical".into(),
            Value::String(CONFIG_PRECEDENCE[0].into()),
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
        if name.to_ascii_uppercase().starts_with(SYSTEM_VARIABLE_PREFIX) {
            let Some(value) = system.get(name) else {
                failed = true;
                sink.diagnostics.push(variable_diagnostic(
                    "template_variable_unresolved",
                    format!(
                        "{path} references {{{{ vars.{name} }}}}, which is not a platform-provided value here."
                    ),
                    path,
                    name,
                    content,
                    whole.start(),
                    channel,
                ));
                return whole.as_str().to_string();
            };
            sink.system_dependencies.insert(name.to_string());
            return value.clone();
        }
        for scope in scopes {
            let Some(variable) = scope.values.get(name) else {
                continue;
            };
            if variable.secret || variable.value.is_none() {
                failed = true;
                sink.diagnostics.push(variable_diagnostic(
                    "secret_variable_in_template",
                    format!(
                        "{path} references secret variable {name}; secret values never reach served bytes."
                    ),
                    path,
                    name,
                    content,
                    whole.start(),
                    channel,
                ));
                return whole.as_str().to_string();
            }
            let value = channel
                .and_then(|channel| variable.channel_values.get(channel))
                .or(variable.value.as_ref())
                .expect("non-secret variable has a value");
            let dependency = channel
                .map(|channel| format!("{name}@{channel}"))
                .unwrap_or_else(|| name.to_string());
            sink.dependencies.insert(dependency, sha256(value));
            return value.clone();
        }
        failed = true;
        sink.diagnostics.push(variable_diagnostic(
            "template_variable_unresolved",
            format!(
                "{path} references {{{{ vars.{name} }}}}, which is not defined at any scope."
            ),
            path,
            name,
            content,
            whole.start(),
            channel,
        ));
        whole.as_str().to_string()
    });
    (!failed).then(|| replaced.into_owned())
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

fn variable_pattern() -> &'static Regex {
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
    use super::{runtime_safe_conventions, ConventionFiles};

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
