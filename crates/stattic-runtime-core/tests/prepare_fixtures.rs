use serde_json::{json, Value};
use stattic_runtime_core::config::diagnostics::DiagnosticSeverity;
use stattic_runtime_core::prepare::{
    analyze, prepare, AnalyzeInput, AnalyzeInputFields, Channel, ConventionFiles, PrepareInput,
    ScopedVariable, VariableScope, ANALYZE_INPUT_FORMAT, PREPARE_INPUT_FORMAT,
};
use stattic_runtime_core::prepare_abi::{dispatch_json, REQUEST_FORMAT, RESPONSE_FORMAT};
use std::collections::BTreeMap;

fn map<const N: usize>(values: [(&str, &str); N]) -> BTreeMap<String, String> {
    values
        .into_iter()
        .map(|(key, value)| (key.to_string(), value.to_string()))
        .collect()
}

#[test]
fn page_protocol_format_is_explicitly_versioned() {
    assert!(stattic_runtime_core::protocol::PAGE_PROTOCOL_FORMAT
        .starts_with("spacefast.page-protocol.v"));
}

#[test]
fn config_selection_and_jsonc_parsing_are_canonical() {
    let output = analyze(AnalyzeInput {
        format: ANALYZE_INPUT_FORMAT.into(),
        config_candidates: map([
            (
                "sf.jsonc",
                r#"{
                  // comments and trailing commas are JSONC, not JSON5
                  "templates": ["app.js",],
                  "meta": {"title": "https://example.test//literal",},
                  "unknown": true,
                }"#,
            ),
            (
                "spacefast.jsonc",
                r#"{"templates":["legacy.js"],"listing":true}"#,
            ),
        ]),
        convention_files: ConventionFiles::default(),
        routing_config: None,
        template_sources: map([("app.js", "const value = '{{ vars.NAME }}';")]),
    });

    assert_eq!(output.selected_config_path.as_deref(), Some("sf.jsonc"));
    assert_eq!(output.template_paths, ["app.js"]);
    assert_eq!(
        output
            .config
            .as_ref()
            .and_then(|value| value.get("unknown")),
        None
    );
    assert_eq!(
        output
            .config
            .as_ref()
            .and_then(|value| value.pointer("/meta/title")),
        Some(&json!("https://example.test//literal"))
    );
    assert!(output.diagnostics.iter().any(|item| {
        item.code == "config_file_ignored" && item.path.as_deref() == Some("spacefast.jsonc")
    }));
    assert!(output.diagnostics.iter().any(|item| {
        item.code == "config_invalid"
            && item.severity == DiagnosticSeverity::Warning
            && item.path.as_deref() == Some("unknown")
    }));
}

#[test]
fn integral_decimal_json_numbers_match_the_published_integer_contract() {
    let output = analyze(AnalyzeInput {
        format: ANALYZE_INPUT_FORMAT.into(),
        config_candidates: map([(
            "spacefast.jsonc",
            r#"{
              "fallback":{"path":"404.html","status":404.0},
              "build":{"timeoutSeconds":60.0}
            }"#,
        )]),
        convention_files: ConventionFiles::default(),
        routing_config: None,
        template_sources: BTreeMap::new(),
    });

    assert!(output.config.is_some(), "{:?}", output.diagnostics);
    assert!(!output
        .diagnostics
        .iter()
        .any(|item| item.severity == DiagnosticSeverity::Error));
}

#[test]
fn analysis_returns_only_references_from_selected_inputs() {
    let output = analyze(AnalyzeInput {
        format: ANALYZE_INPUT_FORMAT.into(),
        config_candidates: map([("spacefast.jsonc", r#"{"templates":["app.js"]}"#)]),
        convention_files: ConventionFiles {
            redirects: Some("/old /{{ vars.SLUG }} 301".into()),
            headers: Some("/*\n  X-Site: {{ vars.SPACEFAST_HOST }}".into()),
        },
        routing_config: None,
        template_sources: map([
            ("app.js", "{{vars.NAME}} {{ vars.SLUG }}"),
            ("undeclared.js", "{{ vars.MUST_NOT_LEAK }}"),
        ]),
    });
    let requirements = output
        .variable_requirements
        .iter()
        .map(|item| (item.name.as_str(), item.system))
        .collect::<Vec<_>>();
    assert_eq!(
        requirements,
        vec![("NAME", false), ("SLUG", false), ("SPACEFAST_HOST", true)]
    );
}

#[test]
fn preparation_substitutes_conventions_templates_and_channel_variants_once() {
    let output = prepare(PrepareInput {
        format: PREPARE_INPUT_FORMAT.into(),
        analysis: AnalyzeInputFields {
            config_candidates: map([("spacefast.jsonc", r#"{"templates":["app.js"]}"#)]),
            convention_files: ConventionFiles {
                redirects: Some("/old /{{ vars.SLUG }} 301".into()),
                headers: Some(
                    "/*\n  Basic-Auth: admin:{{ vars.PASSWORD }}\n  X-Host: {{ vars.SPACEFAST_HOST }}\n/private\n  Basic-Auth: owner:{{ vars.PASSWORD }}"
                        .into(),
                ),
            },
            routing_config: None,
            template_sources: map([(
                "app.js",
                "export const name='{{ vars.NAME }}'; export const slug='{{ vars.SLUG }}';",
            )]),
        },
        variable_scopes: vec![VariableScope {
            kind: "space".into(),
            values: BTreeMap::from([
                (
                    "NAME".into(),
                    ScopedVariable {
                        value: Some("Base".into()),
                        secret: false,
                        channel_values: BTreeMap::from([("preview".into(), "Preview".into())]),
                    },
                ),
                (
                    "SLUG".into(),
                    ScopedVariable {
                        value: Some("docs".into()),
                        secret: false,
                        channel_values: BTreeMap::new(),
                    },
                ),
                (
                    "PASSWORD".into(),
                    ScopedVariable {
                        value: Some("correct-horse".into()),
                        secret: false,
                        channel_values: BTreeMap::new(),
                    },
                ),
            ]),
        }],
        system_variables: map([("SPACEFAST_HOST", "site.example")]),
        channels: vec![Channel {
            name: "preview".into(),
            route_name: "preview-route".into(),
        }],
    });

    assert_eq!(
        output.convention_files,
        Some(ConventionFiles {
            redirects: Some("/old /docs 301".into()),
            headers: Some(
                "/*\n  Basic-Auth: admin:correct-horse\n  X-Host: site.example\n/private\n  Basic-Auth: owner:correct-horse"
                    .into(),
            ),
        })
    );
    assert_eq!(
        output.runtime_convention_files,
        Some(ConventionFiles {
            redirects: Some("/old /docs 301".into()),
            headers: Some("/*\n  X-Host: site.example\n".into()),
        })
    );
    let runtime_boundary = serde_json::to_string(&output.runtime_convention_files).unwrap();
    assert!(!runtime_boundary.contains("correct-horse"));
    assert!(!runtime_boundary.to_ascii_lowercase().contains("basic-auth"));
    assert_eq!(
        output.template_files.get("app.js").map(String::as_str),
        Some("export const name='Base'; export const slug='docs';")
    );
    assert_eq!(
        output
            .template_variants
            .get("preview-route")
            .and_then(|files| files.get("app.js"))
            .map(String::as_str),
        Some("export const name='Preview'; export const slug='docs';")
    );
    assert_eq!(output.substituted_paths, ["app.js"]);
    assert!(output.dependencies.contains_key("NAME"));
    assert!(output.dependencies.contains_key("NAME@preview"));
    assert!(output.dependencies.contains_key("SLUG@preview"));
    assert_eq!(output.system_dependencies, ["SPACEFAST_HOST"]);
    assert!(!output
        .diagnostics
        .iter()
        .any(|item| item.severity == DiagnosticSeverity::Error));
}

#[test]
fn secret_and_unresolved_values_fail_closed_without_partial_substitution() {
    let output = prepare(PrepareInput {
        format: PREPARE_INPUT_FORMAT.into(),
        analysis: AnalyzeInputFields {
            config_candidates: map([("spacefast.jsonc", r#"{"templates":["app.js"]}"#)]),
            convention_files: ConventionFiles {
                redirects: Some("/old /{{ vars.MISSING }} 301".into()),
                headers: None,
            },
            routing_config: None,
            template_sources: map([("app.js", "before {{ vars.SECRET }} after")]),
        },
        variable_scopes: vec![VariableScope {
            kind: "space".into(),
            values: BTreeMap::from([(
                "SECRET".into(),
                ScopedVariable {
                    value: None,
                    secret: true,
                    channel_values: BTreeMap::new(),
                },
            )]),
        }],
        system_variables: BTreeMap::new(),
        channels: vec![Channel {
            name: "preview".into(),
            route_name: "preview".into(),
        }],
    });
    assert!(output.template_files.is_empty());
    assert!(output.template_variants.is_empty());
    assert_eq!(
        output
            .convention_files
            .as_ref()
            .and_then(|files| files.redirects.as_deref()),
        Some("/old /{{ vars.MISSING }} 301")
    );
    assert!(output
        .diagnostics
        .iter()
        .any(|item| item.code == "secret_variable_in_template"));
    assert!(output
        .diagnostics
        .iter()
        .any(|item| item.code == "template_variable_unresolved"));
}

#[test]
fn versioned_json_adapter_matches_direct_behavior() {
    let input = json!({
        "format": REQUEST_FORMAT,
        "operation": "analyze",
        "input": {
            "format": ANALYZE_INPUT_FORMAT,
            "configCandidates": {
                "spacefast.jsonc": "{\"templates\":[\"app.js\"]}"
            },
            "conventionFiles": {},
            "templateSources": {"app.js": "{{ vars.NAME }}"}
        }
    });
    let decoded: Value =
        serde_json::from_slice(&dispatch_json(&serde_json::to_vec(&input).unwrap()))
            .expect("ABI response is JSON");
    assert_eq!(decoded.get("format"), Some(&json!(RESPONSE_FORMAT)));
    assert_eq!(decoded.get("ok"), Some(&json!(true)));
    assert_eq!(
        decoded.pointer("/output/variableRequirements/0/name"),
        Some(&json!("NAME"))
    );

    let invalid: Value = serde_json::from_slice(&dispatch_json(
        format!(
            r#"{{"format":"wrong","operation":"analyze","input":{{"format":"{ANALYZE_INPUT_FORMAT}"}}}}"#
        )
        .as_bytes(),
    ))
        .expect("ABI error is JSON");
    assert_eq!(invalid.get("ok"), Some(&json!(false)));
    assert_eq!(
        invalid.pointer("/error/code"),
        Some(&json!("finalizer_prepare_request_format_invalid"))
    );
}
