use crate::config::jsonc::parse as parse_jsonc;
use crate::protocol::{
    CONFIG_PAGES_COLOR_MAX_CHARS, CONFIG_PAGES_FONT_FAMILY_MAX_CHARS, CONFIG_PAGES_LOGO_MAX_CHARS,
    CONFIG_PAGES_NAME_MAX_CHARS, GATE_THEME_FILE_MAX_BYTES, GATE_THEME_VARS_MAX_BYTES,
    SPACE_THEME_CSS_MAX_BYTES, SPACE_THEME_FONT_FAMILY_MAX_CHARS, SPACE_THEME_NAME_MAX_CHARS,
    SPACE_THEME_PRESET_LIMIT, SPACE_THEME_SLUG_MAX_CHARS, SPACE_THEME_VALUE_MAX_CHARS,
    THEME_JSON_FONT_FAMILY_LIMIT,
};
use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::sync::OnceLock;

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CompileSpaceThemeInput {
    pub theme: Option<Value>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CompileSpaceThemeOutput {
    pub css: Option<String>,
    pub warnings: Vec<ThemeDiagnostic>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ThemeDiagnostic {
    pub severity: &'static str,
    pub code: &'static str,
    pub message: String,
    pub path: String,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ValidateThemeJsonInput {
    pub source: String,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ValidateThemeJsonOutput {
    pub valid: bool,
    pub diagnostics: Vec<ThemeDiagnostic>,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct MergeGateThemeInput {
    #[serde(default)]
    pub base: Option<Map<String, Value>>,
    #[serde(default)]
    pub theme_json_raw: Option<String>,
    #[serde(default)]
    pub sf_theme_jsonc_raw: Option<String>,
    pub advanced_theming_entitled: bool,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct MergeGateThemeOutput {
    pub theme: Map<String, Value>,
    pub theme_vars: Option<String>,
    pub diagnostics: Vec<ThemeDiagnostic>,
}

pub fn compile_space_theme(input: CompileSpaceThemeInput) -> CompileSpaceThemeOutput {
    let Some(theme) = input.theme.and_then(|value| value.as_object().cloned()) else {
        return CompileSpaceThemeOutput {
            css: None,
            warnings: Vec::new(),
        };
    };
    let mut declarations = Vec::new();
    let mut warnings = Vec::new();

    if let Some(entries) = theme
        .get("color")
        .and_then(Value::as_object)
        .and_then(|value| value.get("palette"))
        .and_then(Value::as_array)
    {
        for (index, entry) in entries.iter().enumerate() {
            let slug = entry.get("slug").and_then(Value::as_str);
            let value = entry.get("color").and_then(Value::as_str);
            match (slug, value) {
                (Some(slug), Some(value)) if safe_color(value) => {
                    declarations.push(format!("--wp--preset--color--{slug}:{}", value.trim()))
                }
                _ => warnings.push(invalid_value(format!("theme.color.palette[{index}].color"))),
            }
        }
    }
    if let Some(typography) = theme.get("typography").and_then(Value::as_object) {
        if let Some(entries) = typography.get("fontSizes").and_then(Value::as_array) {
            for (index, entry) in entries.iter().enumerate() {
                let slug = entry.get("slug").and_then(Value::as_str);
                let value = entry.get("size").and_then(Value::as_str);
                match (slug, value) {
                    (Some(slug), Some(value)) if safe_size(value) => declarations
                        .push(format!("--wp--preset--font-size--{slug}:{}", value.trim())),
                    _ => warnings.push(invalid_value(format!(
                        "theme.typography.fontSizes[{index}].size"
                    ))),
                }
            }
        }
        if let Some(value) = typography.get("fontFamily").and_then(Value::as_str) {
            if safe_font_family(value) {
                declarations.push(format!("--sf--font-family:{}", value.trim()));
            } else {
                warnings.push(invalid_value("theme.typography.fontFamily".into()));
            }
        }
    }
    if let Some(entries) = theme
        .get("spacing")
        .and_then(Value::as_object)
        .and_then(|value| value.get("spacingScale"))
        .and_then(Value::as_array)
    {
        for (index, entry) in entries.iter().enumerate() {
            let slug = entry.get("slug").and_then(Value::as_str);
            let value = entry.get("size").and_then(Value::as_str);
            match (slug, value) {
                (Some(slug), Some(value)) if safe_size(value) => {
                    declarations.push(format!("--wp--preset--spacing--{slug}:{}", value.trim()))
                }
                _ => warnings.push(invalid_value(format!(
                    "theme.spacing.spacingScale[{index}].size"
                ))),
            }
        }
    }

    let mut css = declarations.join(";");
    while css.len() > SPACE_THEME_CSS_MAX_BYTES && !declarations.is_empty() {
        declarations.pop();
        warnings.push(ThemeDiagnostic {
            severity: "warning",
            code: "theme_value_invalid",
            message: format!(
                "Compiled theme exceeds {SPACE_THEME_CSS_MAX_BYTES} bytes; trailing tokens were dropped."
            ),
            path: "theme".into(),
        });
        css = declarations.join(";");
    }
    CompileSpaceThemeOutput {
        css: (!css.is_empty()).then_some(css),
        warnings,
    }
}

pub fn merge_gate_theme(input: MergeGateThemeInput) -> MergeGateThemeOutput {
    let mut theme = Map::new();
    let mut diagnostics = Vec::new();
    if let Some(base) = input.base {
        pick_theme(&mut theme, &base);
    }
    let mut theme_vars = None;
    if let Some(raw) = input.theme_json_raw {
        let parsed = parse_theme_json(&raw);
        diagnostics.extend(parsed.diagnostics);
        pick_theme(&mut theme, &parsed.theme);
        theme_vars = parsed.theme_vars;
    }
    if let Some(raw) = input.sf_theme_jsonc_raw {
        if !input.advanced_theming_entitled {
            diagnostics.push(ThemeDiagnostic {
                severity: "info",
                code: "sf_theme_jsonc_not_entitled",
                message: "sf.theme.jsonc is a paid (advanced theming) tier; this plan is not entitled, so it was ignored.".into(),
                path: "sf.theme.jsonc".into(),
            });
        } else {
            match parse_sf_theme(&raw) {
                Ok(parsed) => pick_theme(&mut theme, &parsed),
                Err(diagnostic) => diagnostics.push(diagnostic),
            }
        }
    }
    MergeGateThemeOutput {
        theme,
        theme_vars,
        diagnostics,
    }
}

pub fn validate_theme_json(input: ValidateThemeJsonInput) -> ValidateThemeJsonOutput {
    let parsed = parse_theme_json(&input.source);
    let valid = parsed.diagnostics.iter().all(|diagnostic| {
        !matches!(
            diagnostic.code,
            "theme_json_invalid" | "theme_json_too_large"
        )
    });
    ValidateThemeJsonOutput {
        valid,
        diagnostics: parsed.diagnostics,
    }
}

struct ParsedThemeJson {
    theme: Map<String, Value>,
    theme_vars: Option<String>,
    diagnostics: Vec<ThemeDiagnostic>,
}

fn parse_theme_json(raw: &str) -> ParsedThemeJson {
    let empty = || ParsedThemeJson {
        theme: Map::new(),
        theme_vars: None,
        diagnostics: Vec::new(),
    };
    if raw.len() > GATE_THEME_FILE_MAX_BYTES {
        let mut output = empty();
        output.diagnostics.push(gate_diagnostic(
            "theme_json_too_large",
            format!(
                "theme.json exceeds the {GATE_THEME_FILE_MAX_BYTES} byte limit and was ignored."
            ),
            "theme.json",
        ));
        return output;
    }
    let value: Value = match serde_json::from_str(raw) {
        Ok(value) => value,
        Err(error) => {
            let mut output = empty();
            output.diagnostics.push(gate_diagnostic(
                "theme_json_invalid",
                format!("theme.json is not valid JSON: {error}."),
                "theme.json",
            ));
            return output;
        }
    };
    if !valid_theme_json_shape(&value) {
        let mut output = empty();
        output.diagnostics.push(gate_diagnostic(
            "theme_json_invalid",
            "theme.json does not match the supported settings.color.palette/settings.typography.fontFamilies shape.".into(),
            "theme.json",
        ));
        return output;
    }
    let mut output = empty();
    let mut declarations = Vec::new();
    if let Some(entries) = value
        .pointer("/settings/color/palette")
        .and_then(Value::as_array)
    {
        for entry in entries {
            let slug = entry
                .get("slug")
                .and_then(Value::as_str)
                .expect("validated slug");
            let color = entry
                .get("color")
                .and_then(Value::as_str)
                .expect("validated color");
            if !safe_color(color) {
                output.diagnostics.push(gate_diagnostic(
                    "theme_json_value_invalid",
                    format!("theme.json settings.color.palette[slug={slug}] is not a safe color and was dropped."),
                    format!("theme.json:settings.color.palette.{slug}"),
                ));
                continue;
            }
            let trimmed = color.trim();
            declarations.push(format!("--wp--preset--color--{slug}:{trimmed}"));
            if (slug == "accent" || slug == "primary") && !output.theme.contains_key("accent") {
                output
                    .theme
                    .insert("accent".into(), Value::String(trimmed.into()));
            }
            if slug == "background" {
                output
                    .theme
                    .insert("background".into(), Value::String(trimmed.into()));
            }
        }
    }
    if let Some(entries) = value
        .pointer("/settings/typography/fontFamilies")
        .and_then(Value::as_array)
    {
        for (index, entry) in entries.iter().enumerate() {
            let slug = entry
                .get("slug")
                .and_then(Value::as_str)
                .expect("validated slug");
            let family = entry
                .get("fontFamily")
                .and_then(Value::as_str)
                .expect("validated family");
            if !safe_font_family(family) {
                output.diagnostics.push(gate_diagnostic(
                    "theme_json_value_invalid",
                    format!("theme.json settings.typography.fontFamilies[slug={slug}] is not a safe font-family stack and was dropped."),
                    format!("theme.json:settings.typography.fontFamilies.{slug}"),
                ));
                continue;
            }
            let trimmed = family.trim();
            declarations.push(format!("--wp--preset--font-family--{slug}:{trimmed}"));
            if index == 0 {
                output
                    .theme
                    .entry("fontFamily")
                    .or_insert_with(|| Value::String(trimmed.into()));
            }
        }
    }
    let mut vars = declarations.join(";");
    while vars.len() > GATE_THEME_VARS_MAX_BYTES && !declarations.is_empty() {
        declarations.pop();
        output.diagnostics.push(gate_diagnostic(
            "theme_json_value_invalid",
            format!("theme.json compiled CSS exceeds {GATE_THEME_VARS_MAX_BYTES} bytes; trailing tokens were dropped."),
            "theme.json",
        ));
        vars = declarations.join(";");
    }
    output.theme_vars = (!vars.is_empty()).then_some(vars);
    output
}

fn parse_sf_theme(raw: &str) -> Result<Map<String, Value>, ThemeDiagnostic> {
    if raw.len() > GATE_THEME_FILE_MAX_BYTES {
        return Err(gate_diagnostic(
            "sf_theme_jsonc_too_large",
            format!("sf.theme.jsonc exceeds the {GATE_THEME_FILE_MAX_BYTES} byte limit and was ignored."),
            "sf.theme.jsonc",
        ));
    }
    let value = parse_jsonc(raw).map_err(|error| {
        gate_diagnostic(
            "sf_theme_jsonc_invalid",
            format!("sf.theme.jsonc is not valid JSONC: {error}."),
            "sf.theme.jsonc",
        )
    })?;
    let Some(object) = value.as_object() else {
        return Err(invalid_sf_theme());
    };
    if !valid_pages_theme(object) {
        return Err(invalid_sf_theme());
    }
    Ok(object.clone())
}

fn valid_theme_json_shape(value: &Value) -> bool {
    let Some(root) = value.as_object() else {
        return false;
    };
    let Some(settings_value) = root.get("settings") else {
        return true;
    };
    let Some(settings) = settings_value.as_object() else {
        return false;
    };
    if let Some(color_value) = settings.get("color") {
        let Some(color) = color_value.as_object() else {
            return false;
        };
        if let Some(palette_value) = color.get("palette") {
            let Some(palette) = palette_value.as_array() else {
                return false;
            };
            if palette.len() > SPACE_THEME_PRESET_LIMIT || !palette.iter().all(valid_palette_entry)
            {
                return false;
            }
        }
    }
    if let Some(typography_value) = settings.get("typography") {
        let Some(typography) = typography_value.as_object() else {
            return false;
        };
        if let Some(families_value) = typography.get("fontFamilies") {
            let Some(families) = families_value.as_array() else {
                return false;
            };
            if families.len() > THEME_JSON_FONT_FAMILY_LIMIT
                || !families.iter().all(valid_family_entry)
            {
                return false;
            }
        }
    }
    true
}

fn valid_palette_entry(value: &Value) -> bool {
    let Some(entry) = value.as_object() else {
        return false;
    };
    valid_slug(entry.get("slug"))
        && bounded_string(entry.get("color"), 1, SPACE_THEME_VALUE_MAX_CHARS)
        && optional_bounded_string(entry.get("name"), 1, SPACE_THEME_NAME_MAX_CHARS)
}

fn valid_family_entry(value: &Value) -> bool {
    let Some(entry) = value.as_object() else {
        return false;
    };
    valid_slug(entry.get("slug"))
        && bounded_string(
            entry.get("fontFamily"),
            1,
            SPACE_THEME_FONT_FAMILY_MAX_CHARS,
        )
        && optional_bounded_string(entry.get("name"), 1, SPACE_THEME_NAME_MAX_CHARS)
}

fn valid_pages_theme(object: &Map<String, Value>) -> bool {
    if object
        .keys()
        .any(|key| !["accent", "background", "logo", "name", "fontFamily"].contains(&key.as_str()))
    {
        return false;
    }
    object.iter().all(|(key, value)| {
        let Some(value) = value.as_str() else {
            return false;
        };
        match key.as_str() {
            "accent" | "background" => {
                value.chars().count() <= CONFIG_PAGES_COLOR_MAX_CHARS && safe_color(value)
            }
            "logo" => {
                value.chars().count() <= CONFIG_PAGES_LOGO_MAX_CHARS
                    && logo_pattern().is_match(value.trim())
            }
            "name" => {
                !value.trim().is_empty()
                    && value.trim().chars().count() <= CONFIG_PAGES_NAME_MAX_CHARS
            }
            "fontFamily" => {
                value.chars().count() <= CONFIG_PAGES_FONT_FAMILY_MAX_CHARS
                    && safe_font_family(value)
            }
            _ => false,
        }
    })
}

fn pick_theme(target: &mut Map<String, Value>, source: &Map<String, Value>) {
    for key in ["accent", "background", "logo", "name", "fontFamily"] {
        if let Some(value) = source.get(key) {
            target.insert(key.into(), value.clone());
        }
    }
}

fn invalid_value(path: String) -> ThemeDiagnostic {
    ThemeDiagnostic {
        severity: "warning",
        code: "theme_value_invalid",
        message: format!("Theme value at {path} is not a safe preset token and was dropped."),
        path,
    }
}

fn gate_diagnostic(
    code: &'static str,
    message: String,
    path: impl Into<String>,
) -> ThemeDiagnostic {
    ThemeDiagnostic {
        severity: "warning",
        code,
        message,
        path: path.into(),
    }
}

fn invalid_sf_theme() -> ThemeDiagnostic {
    gate_diagnostic(
        "sf_theme_jsonc_invalid",
        "sf.theme.jsonc does not match the gate-page theme token shape.".into(),
        "sf.theme.jsonc",
    )
}

fn safe_value(value: &str, allowed: &Regex) -> bool {
    let trimmed = value.trim();
    !trimmed.is_empty() && !forbidden_pattern().is_match(trimmed) && allowed.is_match(trimmed)
}

fn safe_color(value: &str) -> bool {
    safe_value(value, color_pattern())
}
fn safe_size(value: &str) -> bool {
    safe_value(value, size_pattern())
}
fn safe_font_family(value: &str) -> bool {
    value.chars().count() <= SPACE_THEME_FONT_FAMILY_MAX_CHARS && safe_value(value, font_pattern())
}

fn valid_slug(value: Option<&Value>) -> bool {
    value.and_then(Value::as_str).is_some_and(|value| {
        value.chars().count() <= SPACE_THEME_SLUG_MAX_CHARS && slug_pattern().is_match(value)
    })
}
fn bounded_string(value: Option<&Value>, min: usize, max: usize) -> bool {
    value
        .and_then(Value::as_str)
        .is_some_and(|value| (min..=max).contains(&value.chars().count()))
}
fn optional_bounded_string(value: Option<&Value>, min: usize, max: usize) -> bool {
    value.is_none() || bounded_string(value, min, max)
}

fn forbidden_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| {
        Regex::new(r"(?i)url\s*\(|@import|expression\s*\(|data:|[<>{};\\]|/\*|\*/")
            .expect("valid forbidden regex")
    })
}
fn color_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r"(?i)^(?:#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})|rgba?\([0-9.,\s%/]{1,64}\)|hsla?\([0-9.,\s%deg/]{1,64}\)|[a-z]{3,30}|var\(--wp--preset--color--[a-z0-9-]{1,64}\))$").expect("valid color regex"))
}
fn size_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r"^-?\d{1,5}(?:\.\d{1,4})?(?:px|rem|em|%|vw|vh|vmin|vmax|pt|ch|ex)?$|^var\(--wp--preset--(?:font-size|spacing)--[a-z0-9-]{1,64}\)$").expect("valid size regex"))
}
fn font_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r#"^[A-Za-z0-9 ,'"-]+$"#).expect("valid font regex"))
}
fn slug_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r"^[a-z0-9-]+$").expect("valid slug regex"))
}
fn logo_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| {
        Regex::new(r#"^(?:https://[^\s"'<>\\]+|/[^\s"'<>\\]*)$"#).expect("valid logo regex")
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn compiles_safe_tokens_and_drops_unsafe_values() {
        let output = compile_space_theme(CompileSpaceThemeInput {
            theme: Some(json!({
                "color": {"palette": [
                    {"slug": "brand", "color": "#123456"},
                    {"slug": "bad", "color": "url(https://example.test/x)"}
                ]}
            })),
        });
        assert_eq!(
            output.css.as_deref(),
            Some("--wp--preset--color--brand:#123456")
        );
        assert_eq!(output.warnings.len(), 1);
    }

    #[test]
    fn sf_theme_uses_shared_jsonc_parser() {
        let output = merge_gate_theme(MergeGateThemeInput {
            base: None,
            theme_json_raw: None,
            sf_theme_jsonc_raw: Some("{/* ok */ \"accent\": \"red\",}".into()),
            advanced_theming_entitled: true,
        });
        assert_eq!(
            output.theme.get("accent"),
            Some(&Value::String("red".into()))
        );
    }

    #[test]
    fn validates_theme_json_shape_without_interpreting_decoy_fields() {
        let valid = validate_theme_json(ValidateThemeJsonInput {
            source: json!({
                "settings": {
                    "color": {"palette": [{"slug": "accent", "color": "#123456"}]}
                },
                "decoy": {
                    "settings": {"color": "not-the-consumed-branch"},
                    "markup": "<script>const fake = '{not json}';</script>"
                }
            })
            .to_string(),
        });
        assert!(valid.valid);
        assert!(valid.diagnostics.is_empty());

        let malformed = validate_theme_json(ValidateThemeJsonInput {
            source: r#"{"settings":{"color":"red"}}"#.into(),
        });
        assert!(!malformed.valid);
        assert_eq!(malformed.diagnostics[0].code, "theme_json_invalid");

        let jsonc = validate_theme_json(ValidateThemeJsonInput {
            source: r#"{/* comments are not valid theme.json */ "settings": {}}"#.into(),
        });
        assert!(!jsonc.valid);
        assert_eq!(jsonc.diagnostics[0].code, "theme_json_invalid");
    }
}
