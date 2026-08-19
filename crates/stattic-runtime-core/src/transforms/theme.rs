use crate::protocol::{
    GATE_THEME_FILE_MAX_BYTES, GATE_THEME_VARS_MAX_BYTES, SPACE_THEME_FONT_FAMILY_MAX_CHARS,
    SPACE_THEME_NAME_MAX_CHARS, SPACE_THEME_PRESET_LIMIT, SPACE_THEME_SLUG_MAX_CHARS,
    SPACE_THEME_VALUE_MAX_CHARS, THEME_JSON_FONT_FAMILY_LIMIT,
};
use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::sync::OnceLock;

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

pub fn validate_theme_json(input: ValidateThemeJsonInput) -> ValidateThemeJsonOutput {
    let diagnostics = parse_theme_json(&input.source);
    let valid = diagnostics.iter().all(|diagnostic| {
        !matches!(
            diagnostic.code,
            "theme_json_invalid" | "theme_json_too_large"
        )
    });
    ValidateThemeJsonOutput { valid, diagnostics }
}

/// Walks theme.json exactly as the gate-page compiler does and reports what it
/// would have to drop. The compiled tokens themselves have no consumer; the
/// diagnostics are the product.
fn parse_theme_json(raw: &str) -> Vec<ThemeDiagnostic> {
    let empty = Vec::new;
    if raw.len() > GATE_THEME_FILE_MAX_BYTES {
        let mut output = empty();
        output.push(gate_diagnostic(
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
            output.push(gate_diagnostic(
                "theme_json_invalid",
                format!("theme.json is not valid JSON: {error}."),
                "theme.json",
            ));
            return output;
        }
    };
    if !valid_theme_json_shape(&value) {
        let mut output = empty();
        output.push(gate_diagnostic(
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
                output.push(gate_diagnostic(
                    "theme_json_value_invalid",
                    format!("theme.json settings.color.palette[slug={slug}] is not a safe color and was dropped."),
                    format!("theme.json:settings.color.palette.{slug}"),
                ));
                continue;
            }
            declarations.push(format!("--wp--preset--color--{slug}:{}", color.trim()));
        }
    }
    if let Some(entries) = value
        .pointer("/settings/typography/fontFamilies")
        .and_then(Value::as_array)
    {
        for entry in entries {
            let slug = entry
                .get("slug")
                .and_then(Value::as_str)
                .expect("validated slug");
            let family = entry
                .get("fontFamily")
                .and_then(Value::as_str)
                .expect("validated family");
            if !safe_font_family(family) {
                output.push(gate_diagnostic(
                    "theme_json_value_invalid",
                    format!("theme.json settings.typography.fontFamilies[slug={slug}] is not a safe font-family stack and was dropped."),
                    format!("theme.json:settings.typography.fontFamilies.{slug}"),
                ));
                continue;
            }
            declarations.push(format!(
                "--wp--preset--font-family--{slug}:{}",
                family.trim()
            ));
        }
    }
    let mut vars = declarations.join(";");
    while vars.len() > GATE_THEME_VARS_MAX_BYTES && !declarations.is_empty() {
        declarations.pop();
        output.push(gate_diagnostic(
            "theme_json_value_invalid",
            format!("theme.json compiled CSS exceeds {GATE_THEME_VARS_MAX_BYTES} bytes; trailing tokens were dropped."),
            "theme.json",
        ));
        vars = declarations.join(";");
    }
    output
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

fn safe_value(value: &str, allowed: &Regex) -> bool {
    let trimmed = value.trim();
    !trimmed.is_empty() && !forbidden_pattern().is_match(trimmed) && allowed.is_match(trimmed)
}

fn safe_color(value: &str) -> bool {
    safe_value(value, color_pattern())
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
fn font_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r#"^[A-Za-z0-9 ,'"-]+$"#).expect("valid font regex"))
}
fn slug_pattern() -> &'static Regex {
    static VALUE: OnceLock<Regex> = OnceLock::new();
    VALUE.get_or_init(|| Regex::new(r"^[a-z0-9-]+$").expect("valid slug regex"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

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
