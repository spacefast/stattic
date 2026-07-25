//! Compiles a WordPress `theme.json` (version 3) into the generated theme
//! stylesheet: presets, custom properties, element/block styles, pseudo
//! states, style variations, and WP7 fluid typography.

use serde_json::Value;
use std::collections::BTreeMap;
use std::path::Path;

use super::pipeline_text;
use crate::finalize::{write_generated, FileMeta, Result};

pub(super) fn compile_theme(
    files_root: &Path,
    files: &mut BTreeMap<String, FileMeta>,
    diagnostics: &mut Vec<Value>,
) -> Result<()> {
    if !files.contains_key("theme.json") {
        return Ok(());
    }
    let Some(source) = pipeline_text(files_root, "theme.json", diagnostics)? else {
        return Ok(());
    };
    let theme: Value = match serde_json::from_str(&source) {
        Ok(value) => value,
        Err(_) => {
            diagnostics.push(serde_json::json!({"code":"theme_json_invalid","severity":"warning","message":"theme.json is not valid JSON.","path":"theme.json"}));
            return Ok(());
        }
    };
    let Some(theme) = theme.as_object() else {
        diagnostics.push(serde_json::json!({"code":"theme_json_invalid","severity":"warning","message":"theme.json must be an object with version 3; no theme stylesheet was generated.","path":"theme.json"}));
        return Ok(());
    };
    if theme.get("version").and_then(Value::as_u64) != Some(3) {
        diagnostics.push(serde_json::json!({"code":"theme_json_invalid","severity":"warning","message":"theme.json must be an object with version 3; no theme stylesheet was generated.","path":"theme.json"}));
        return Ok(());
    }
    let theme = Value::Object(theme.clone());
    let mut css = String::from(":root{\n  --wp--style--global--content-size:42rem;\n  --wp--style--global--wide-size:64rem;\n");
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/color/palette",
        "color",
        "color",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/color/gradients",
        "gradient",
        "gradient",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/typography/fontFamilies",
        "font-family",
        "fontFamily",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/typography/fontSizes",
        "font-size",
        "size",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/spacing/spacingSizes",
        "spacing",
        "size",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/shadow/presets",
        "shadow",
        "shadow",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/dimensions/aspectRatios",
        "aspect-ratio",
        "ratio",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/border/radiusSizes",
        "border-radius",
        "size",
    );
    append_theme_presets(
        &mut css,
        &theme,
        "/settings/dimensions/dimensionSizes",
        "dimension",
        "size",
    );
    append_theme_custom_properties(&mut css, &theme);
    for (pointer, variable) in [
        (
            "/settings/layout/contentSize",
            "--wp--style--global--content-size",
        ),
        (
            "/settings/layout/wideSize",
            "--wp--style--global--wide-size",
        ),
    ] {
        if let Some(value) = theme
            .pointer(pointer)
            .and_then(Value::as_str)
            .filter(|v| safe_css_value(v))
        {
            css.push_str(&format!("  {variable}:{};\n", value.trim()));
        }
    }
    css.push_str("}\n");
    if let Some(palette) = theme
        .pointer("/settings/color/palette")
        .and_then(Value::as_array)
    {
        for color in palette {
            if let Some(slug) = color
                .get("slug")
                .and_then(Value::as_str)
                .filter(|v| safe_css_slug(v))
            {
                css.push_str(&format!(".has-{slug}-color{{color:var(--wp--preset--color--{slug})!important}}\n.has-{slug}-background-color{{background-color:var(--wp--preset--color--{slug})!important}}\n.has-{slug}-border-color{{border-color:var(--wp--preset--color--{slug})!important}}\n"));
            }
        }
    }
    append_theme_preset_classes(&mut css, &theme);
    css.push_str("body{font-family:system-ui,-apple-system,\"Segoe UI\",Roboto,\"Helvetica Neue\",Arial,\"Noto Sans\",sans-serif;line-height:1.6}\n");
    append_theme_style_rule(&mut css, "body", theme.get("styles"), &theme);
    append_theme_elements(&mut css, "", theme.pointer("/styles/elements"), &theme);
    if let Some(blocks) = theme.pointer("/styles/blocks").and_then(Value::as_object) {
        for (name, styles) in blocks {
            let selector = core_block_selector(name);
            if let Some(selector) = selector {
                append_theme_style_rule(&mut css, &selector, Some(styles), &theme);
                append_theme_elements(
                    &mut css,
                    &format!("{selector} "),
                    styles.get("elements"),
                    &theme,
                );
                append_theme_block_pseudo_states(&mut css, name, &selector, styles, &theme);
                append_theme_block_variations(
                    &mut css,
                    name,
                    &selector,
                    styles.get("variations"),
                    &theme,
                );
            }
        }
    }
    write_generated(
        files_root,
        files,
        "__spacefast_generated/theme.css",
        css.as_bytes(),
        Some("text/css; charset=utf-8"),
    )
}

fn append_theme_presets(
    css: &mut String,
    theme: &Value,
    pointer: &str,
    category: &str,
    value_key: &str,
) {
    let Some(entries) = theme.pointer(pointer).and_then(Value::as_array) else {
        return;
    };
    for entry in entries {
        let Some(slug) = entry
            .get("slug")
            .and_then(Value::as_str)
            .filter(|v| safe_css_slug(v))
        else {
            continue;
        };
        let Some(value) = theme_preset_value(entry, category, value_key, theme) else {
            continue;
        };
        css.push_str(&format!(
            "  --wp--preset--{category}--{slug}: {};\n",
            value.trim()
        ));
    }
}

fn theme_preset_value(
    entry: &Value,
    category: &str,
    value_key: &str,
    theme: &Value,
) -> Option<String> {
    let value = entry
        .get(value_key)
        .and_then(Value::as_str)
        .filter(|value| safe_css_value(value))?;
    if category == "font-size" {
        return Some(theme_font_size_value(value, entry.get("fluid"), theme));
    }
    Some(value.to_string())
}

fn theme_font_size_value(size: &str, local_fluid: Option<&Value>, theme: &Value) -> String {
    if local_fluid == Some(&Value::Bool(false)) {
        return size.to_string();
    }
    let global_fluid = theme.pointer("/settings/typography/fluid");
    if !json_truthy(global_fluid) && !json_truthy(local_fluid) {
        return size.to_string();
    }
    fluid_font_size(size, local_fluid, global_fluid, theme).unwrap_or_else(|| size.to_string())
}

fn json_truthy(value: Option<&Value>) -> bool {
    match value {
        Some(Value::Bool(value)) => *value,
        Some(Value::Object(value)) => !value.is_empty(),
        _ => false,
    }
}

#[derive(Clone, Copy)]
struct CssLength {
    value: f64,
    unit: &'static str,
}

fn parse_fluid_length(value: &str) -> Option<CssLength> {
    let value = value.trim();
    let (number, unit) = if let Some(number) = value.strip_suffix("rem") {
        (number, "rem")
    } else if let Some(number) = value.strip_suffix("em") {
        (number, "em")
    } else if let Some(number) = value.strip_suffix("px") {
        (number, "px")
    } else {
        return None;
    };
    let number = number.trim().parse::<f64>().ok()?;
    (number.is_finite() && number >= 0.0).then_some(CssLength {
        value: round_three(number),
        unit,
    })
}

fn coerce_length(length: CssLength, unit: &'static str) -> CssLength {
    let value = match (length.unit, unit) {
        ("px", "rem" | "em") => length.value / 16.0,
        ("rem" | "em", "px") => length.value * 16.0,
        _ => length.value,
    };
    CssLength {
        value: round_three(value),
        unit,
    }
}

fn fluid_font_size(
    size: &str,
    local_fluid: Option<&Value>,
    global_fluid: Option<&Value>,
    theme: &Value,
) -> Option<String> {
    let preferred = parse_fluid_length(size)?;
    let local = local_fluid.and_then(Value::as_object);
    let global = global_fluid.and_then(Value::as_object);
    let explicit_min = local
        .and_then(|value| value.get("min"))
        .and_then(Value::as_str);
    let explicit_max = local
        .and_then(|value| value.get("max"))
        .and_then(Value::as_str);

    let configured_minimum_limit = global
        .and_then(|value| value.get("minFontSize"))
        .and_then(Value::as_str)
        .and_then(parse_fluid_length);
    let minimum_limit = configured_minimum_limit
        .or_else(|| parse_fluid_length("14px"))
        .map(|value| coerce_length(value, preferred.unit));
    if explicit_min.is_none()
        && explicit_max.is_none()
        && minimum_limit.is_some_and(|limit| preferred.value <= limit.value)
    {
        return None;
    }

    let maximum_raw = explicit_max.unwrap_or(size).trim().to_string();
    let maximum = coerce_length(parse_fluid_length(&maximum_raw)?, preferred.unit);
    let minimum_raw = if let Some(minimum) = explicit_min {
        minimum.trim().to_string()
    } else {
        let preferred_px = coerce_length(preferred, "px").value;
        let factor = (1.0 - 0.075 * preferred_px.log2()).clamp(0.25, 0.75);
        let calculated = round_three(preferred.value * factor);
        let minimum = minimum_limit
            .filter(|limit| calculated <= limit.value)
            .map_or(calculated, |limit| limit.value);
        format!("{}{}", format_decimal(minimum), preferred.unit)
    };
    let minimum = parse_fluid_length(&minimum_raw)?;
    let maximum = coerce_length(maximum, minimum.unit);

    let minimum_viewport_raw = global
        .and_then(|value| value.get("minViewportWidth"))
        .and_then(Value::as_str)
        .unwrap_or("320px");
    let configured_maximum_viewport = global
        .and_then(|value| value.get("maxViewportWidth"))
        .and_then(Value::as_str)
        .map(str::to_string);
    let layout_wide = theme
        .pointer("/settings/layout/wideSize")
        .and_then(Value::as_str)
        .filter(|value| parse_fluid_length(value).is_some());
    let maximum_viewport_raw = configured_maximum_viewport
        .as_deref()
        .or(layout_wide)
        .unwrap_or("1600px");
    let minimum_viewport = coerce_length(parse_fluid_length(minimum_viewport_raw)?, minimum.unit);
    let maximum_viewport = coerce_length(parse_fluid_length(maximum_viewport_raw)?, minimum.unit);
    let denominator = maximum_viewport.value - minimum_viewport.value;
    if denominator == 0.0 {
        return None;
    }
    let offset = round_three(minimum_viewport.value / 100.0);
    let linear = round_three(100.0 * (maximum.value - minimum.value) / denominator);
    let linear = if linear == 0.0 { 1.0 } else { linear };
    let minimum_rem = coerce_length(minimum, "rem");
    Some(format!(
        "clamp({minimum_raw}, {}rem + ((1vw - {}{}) * {}), {maximum_raw})",
        format_decimal(minimum_rem.value),
        format_decimal(offset),
        minimum.unit,
        format_decimal(linear),
    ))
}

fn round_three(value: f64) -> f64 {
    (value * 1000.0).round() / 1000.0
}

fn format_decimal(value: f64) -> String {
    let value = format!("{value:.4}");
    value
        .trim_end_matches('0')
        .trim_end_matches('.')
        .to_string()
}

fn append_theme_custom_properties(css: &mut String, theme: &Value) {
    let Some(custom) = theme.pointer("/settings/custom") else {
        return;
    };
    append_custom_property(css, &mut Vec::new(), custom);
}

fn append_custom_property(css: &mut String, path: &mut Vec<String>, value: &Value) {
    if let Some(entries) = value.as_object() {
        for (name, value) in entries {
            let slug = camel_to_kebab(name);
            if !safe_css_slug(&slug) {
                continue;
            }
            path.push(slug);
            append_custom_property(css, path, value);
            path.pop();
        }
        return;
    }
    let value = value
        .as_str()
        .map(str::to_string)
        .or_else(|| value.as_i64().map(|value| value.to_string()))
        .or_else(|| value.as_u64().map(|value| value.to_string()))
        .or_else(|| value.as_f64().map(|value| value.to_string()));
    if let Some(value) = value.filter(|value| safe_css_value(value)) {
        css.push_str(&format!(
            "  --wp--custom--{}: {};\n",
            path.join("--"),
            resolve_theme_value(&value)
        ));
    }
}

fn append_theme_preset_classes(css: &mut String, theme: &Value) {
    let presets = [
        (
            "/settings/color/gradients",
            "gradient",
            "gradient",
            "background",
        ),
        (
            "/settings/typography/fontSizes",
            "font-size",
            "size",
            "font-size",
        ),
        (
            "/settings/typography/fontFamilies",
            "font-family",
            "fontFamily",
            "font-family",
        ),
    ];
    for (pointer, category, value_key, property) in presets {
        let Some(entries) = theme.pointer(pointer).and_then(Value::as_array) else {
            continue;
        };
        for entry in entries {
            let Some(slug) = entry
                .get("slug")
                .and_then(Value::as_str)
                .filter(|slug| safe_css_slug(slug))
            else {
                continue;
            };
            if theme_preset_value(entry, category, value_key, theme).is_none() {
                continue;
            }
            let class = match category {
                "gradient" => format!(".has-{slug}-gradient-background"),
                "font-size" => format!(".has-{slug}-font-size"),
                "font-family" => format!(".has-{slug}-font-family"),
                _ => continue,
            };
            css.push_str(&format!(
                "{class}{{{property}:var(--wp--preset--{category}--{slug})!important}}\n"
            ));
        }
    }
}

fn append_theme_style_rule(
    css: &mut String,
    selector: &str,
    styles: Option<&Value>,
    theme: &Value,
) {
    let Some(styles) = styles.and_then(Value::as_object) else {
        return;
    };
    let mappings = [
        ("/color/background", "background-color"),
        ("/color/gradient", "background"),
        ("/color/text", "color"),
        ("/background/backgroundImage", "background-image"),
        ("/background/backgroundAttachment", "background-attachment"),
        ("/background/backgroundPosition", "background-position"),
        ("/background/backgroundRepeat", "background-repeat"),
        ("/background/backgroundSize", "background-size"),
        ("/border/color", "border-color"),
        ("/border/radius", "border-radius"),
        ("/border/radius/topLeft", "border-top-left-radius"),
        ("/border/radius/topRight", "border-top-right-radius"),
        ("/border/radius/bottomRight", "border-bottom-right-radius"),
        ("/border/radius/bottomLeft", "border-bottom-left-radius"),
        ("/border/style", "border-style"),
        ("/border/width", "border-width"),
        ("/border/top/color", "border-top-color"),
        ("/border/top/style", "border-top-style"),
        ("/border/top/width", "border-top-width"),
        ("/border/right/color", "border-right-color"),
        ("/border/right/style", "border-right-style"),
        ("/border/right/width", "border-right-width"),
        ("/border/bottom/color", "border-bottom-color"),
        ("/border/bottom/style", "border-bottom-style"),
        ("/border/bottom/width", "border-bottom-width"),
        ("/border/left/color", "border-left-color"),
        ("/border/left/style", "border-left-style"),
        ("/border/left/width", "border-left-width"),
        ("/dimensions/aspectRatio", "aspect-ratio"),
        ("/dimensions/height", "height"),
        ("/dimensions/minHeight", "min-height"),
        ("/dimensions/width", "width"),
        ("/filter/duotone", "filter"),
        ("/outline/color", "outline-color"),
        ("/outline/offset", "outline-offset"),
        ("/outline/style", "outline-style"),
        ("/outline/width", "outline-width"),
        ("/shadow", "box-shadow"),
        ("/spacing/blockGap", "gap"),
        ("/spacing/blockGap/left", "column-gap"),
        ("/spacing/blockGap/top", "row-gap"),
        ("/spacing/margin", "margin"),
        ("/spacing/margin/top", "margin-top"),
        ("/spacing/margin/right", "margin-right"),
        ("/spacing/margin/bottom", "margin-bottom"),
        ("/spacing/margin/left", "margin-left"),
        ("/spacing/padding", "padding"),
        ("/spacing/padding/top", "padding-top"),
        ("/spacing/padding/right", "padding-right"),
        ("/spacing/padding/bottom", "padding-bottom"),
        ("/spacing/padding/left", "padding-left"),
        ("/typography/fontFamily", "font-family"),
        ("/typography/fontSize", "font-size"),
        ("/typography/fontStyle", "font-style"),
        ("/typography/fontWeight", "font-weight"),
        ("/typography/letterSpacing", "letter-spacing"),
        ("/typography/lineHeight", "line-height"),
        ("/typography/textDecoration", "text-decoration"),
        ("/typography/textAlign", "text-align"),
        ("/typography/textColumns", "column-count"),
        ("/typography/textIndent", "text-indent"),
        ("/typography/textTransform", "text-transform"),
        ("/typography/writingMode", "writing-mode"),
    ];
    let styles = Value::Object(styles.clone());
    let has_uploaded_background = styles
        .pointer("/background/backgroundImage/id")
        .is_some_and(json_value_truthy);
    let declarations: Vec<String> = mappings
        .into_iter()
        .flat_map(|(pointer, property)| {
            let value = match property {
                "background-image" => theme_background_image_value(&styles, theme),
                "background-size" if has_uploaded_background && selector != "body" => {
                    theme_style_value(&styles, pointer, theme)
                        .and_then(Value::as_str)
                        .filter(|value| !value.is_empty())
                        .map(str::to_string)
                        .or_else(|| Some("cover".to_string()))
                }
                "background-position" if has_uploaded_background && selector != "body" => {
                    theme_style_value(&styles, pointer, theme)
                        .and_then(Value::as_str)
                        .filter(|value| !value.is_empty())
                        .map(str::to_string)
                        .or_else(|| {
                            (styles
                                .pointer("/background/backgroundSize")
                                .and_then(Value::as_str)
                                == Some("contain"))
                            .then(|| "50% 50%".to_string())
                        })
                }
                _ => theme_style_value(&styles, pointer, theme)
                    .and_then(Value::as_str)
                    .map(str::to_string),
            };
            let Some(value) = value.filter(|value| safe_css_value(value)) else {
                return Vec::new();
            };
            if selector == "body"
                && property == "padding"
                && theme.pointer("/settings/useRootPaddingAwareAlignments")
                    == Some(&Value::Bool(true))
            {
                return Vec::new();
            }
            let value = if property == "font-size" {
                theme_font_size_value(value.trim(), None, theme)
            } else {
                value.trim().to_string()
            };
            let mut declarations = Vec::with_capacity(2);
            if property == "aspect-ratio" {
                declarations.push("min-height:unset".to_string());
            }
            declarations.push(format!("{property}:{}", resolve_theme_value(&value)));
            declarations
        })
        .collect();
    if !declarations.is_empty() {
        if selector == "body"
            && declarations.iter().any(|declaration| {
                declaration.starts_with("background:")
                    || declaration.starts_with("background-image:")
            })
        {
            css.push_str("html{min-height:calc(100% - var(--wp-admin--admin-bar--height, 0px))}\n");
        }
        css.push_str(&format!("{selector}{{{}}}\n", declarations.join(";")));
    }
    append_theme_nested_css(css, selector, styles.get("css"));
}

fn theme_style_value<'a>(styles: &'a Value, pointer: &str, theme: &'a Value) -> Option<&'a Value> {
    let value = styles.pointer(pointer)?;
    let Some(reference) = value.get("ref").and_then(Value::as_str) else {
        return Some(value);
    };
    reference
        .split('.')
        .try_fold(theme, |value, segment| value.get(segment))
        .or(Some(value))
}

fn json_value_truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(value) => *value,
        Value::Number(value) => value.as_f64() != Some(0.0),
        Value::String(value) => !value.is_empty() && value != "0",
        Value::Array(value) => !value.is_empty(),
        Value::Object(value) => !value.is_empty(),
    }
}

fn theme_background_image_value(styles: &Value, theme: &Value) -> Option<String> {
    let value = styles.pointer("/background/backgroundImage")?;
    if let Some(value) = value.as_str() {
        return Some(value.to_string());
    }
    let value = value.as_object()?;
    if let Some(url) = value.get("url").and_then(Value::as_str) {
        return css_url(url);
    }
    let reference = value.get("ref").and_then(Value::as_str)?;
    let referenced = reference
        .split('.')
        .try_fold(theme, |value, segment| value.get(segment))?;
    if let Some(url) = referenced.get("url").and_then(Value::as_str) {
        return css_url(url);
    }
    referenced.as_str().map(str::to_string)
}

fn css_url(value: &str) -> Option<String> {
    safe_css_value(value).then(|| {
        let value = value.replace('\\', "\\\\").replace('\'', "\\'");
        format!("url('{value}')")
    })
}

fn append_theme_nested_css(css: &mut String, selector: &str, value: Option<&Value>) {
    let Some(value) = value
        .and_then(Value::as_str)
        .filter(|value| safe_nested_css(value))
    else {
        return;
    };
    if value.contains('&') {
        for scoped_selector in selector.split(',') {
            css.push_str(&value.replace('&', scoped_selector.trim()));
            if !value.ends_with('\n') {
                css.push('\n');
            }
        }
    } else if !value.contains(['{', '}']) {
        css.push_str(&format!("{selector}{{{value}}}\n"));
    }
}

fn safe_nested_css(value: &str) -> bool {
    if value.trim().is_empty() || value.len() > 16_384 || value.contains(['<', '>']) {
        return false;
    }
    let lower = value.to_ascii_lowercase();
    if ["@import", "expression(", "javascript:", "data:"]
        .iter()
        .any(|token| lower.contains(token))
    {
        return false;
    }
    let mut depth = 0_i32;
    for byte in value.bytes() {
        match byte {
            b'{' => depth += 1,
            b'}' => {
                depth -= 1;
                if depth < 0 {
                    return false;
                }
            }
            _ => {}
        }
    }
    depth == 0
}

fn core_block_selector(name: &str) -> Option<String> {
    let slug = name.strip_prefix("core/")?;
    if !safe_css_slug(slug) {
        return None;
    }
    Some(match slug {
        "button" => ".wp-block-button .wp-block-button__link".into(),
        "buttons" => ".wp-block-buttons".into(),
        "heading" => ".wp-block-heading,h1,h2,h3,h4,h5,h6".into(),
        "list-item" => ".wp-block-list li".into(),
        _ => format!(".wp-block-{slug}"),
    })
}

fn append_theme_elements(css: &mut String, prefix: &str, elements: Option<&Value>, theme: &Value) {
    let Some(elements) = elements.and_then(Value::as_object) else {
        return;
    };
    for (name, styles) in elements {
        let Some(base_selector) = theme_element_selector(name) else {
            continue;
        };
        let selector = base_selector
            .split(',')
            .map(|selector| format!("{prefix}{}", selector.trim()))
            .collect::<Vec<_>>()
            .join(",");
        append_theme_style_rule(css, &selector, Some(styles), theme);
        for state in [
            ":link",
            ":any-link",
            ":visited",
            ":hover",
            ":focus",
            ":focus-visible",
            ":active",
        ] {
            if let Some(state_styles) = styles.get(state) {
                let state_selector = selector
                    .split(',')
                    .map(|selector| format!("{}{state}", selector.trim()))
                    .collect::<Vec<_>>()
                    .join(",");
                append_theme_style_rule(css, &state_selector, Some(state_styles), theme);
            }
        }
    }
}

fn append_theme_block_pseudo_states(
    css: &mut String,
    block_name: &str,
    selector: &str,
    styles: &Value,
    theme: &Value,
) {
    if block_name != "core/button" {
        return;
    }
    for state in [":hover", ":focus", ":focus-visible", ":active"] {
        if let Some(state_styles) = styles.get(state) {
            let selector = selector
                .split(',')
                .map(|selector| format!("{}{state}", selector.trim()))
                .collect::<Vec<_>>()
                .join(",");
            append_theme_style_rule(css, &selector, Some(state_styles), theme);
        }
    }
}

fn append_theme_block_variations(
    css: &mut String,
    block_name: &str,
    selector: &str,
    variations: Option<&Value>,
    theme: &Value,
) {
    let Some(variations) = variations.and_then(Value::as_object) else {
        return;
    };
    for (name, styles) in variations {
        if !safe_css_slug(name) {
            continue;
        }
        let selector = if block_name == "core/button" {
            format!(".wp-block-button.is-style-{name} .wp-block-button__link")
        } else {
            format!("{selector}.is-style-{name}")
        };
        append_theme_style_rule(css, &selector, Some(styles), theme);
        append_theme_elements(css, &format!("{selector} "), styles.get("elements"), theme);
        append_theme_block_pseudo_states(css, block_name, &selector, styles, theme);
    }
}

fn theme_element_selector(name: &str) -> Option<&'static str> {
    match name {
        "link" => Some("a:where(:not(.wp-element-button))"),
        "button" => Some(".wp-element-button,.wp-block-button__link"),
        "caption" => Some(".wp-element-caption,.wp-block-audio figcaption,.wp-block-embed figcaption,.wp-block-gallery figcaption,.wp-block-image figcaption,.wp-block-table figcaption,.wp-block-video figcaption"),
        "cite" => Some("cite"),
        "textInput" => Some("textarea,input:where([type=email],[type=number],[type=password],[type=search],[type=text],[type=tel],[type=url])"),
        "select" => Some("select"),
        "heading" => Some("h1,h2,h3,h4,h5,h6"),
        "h1" => Some("h1"),
        "h2" => Some("h2"),
        "h3" => Some("h3"),
        "h4" => Some("h4"),
        "h5" => Some("h5"),
        "h6" => Some("h6"),
        _ => None,
    }
}

fn resolve_theme_value(value: &str) -> String {
    let mut output = String::with_capacity(value.len());
    let mut remaining = value;
    while let Some(start) = remaining.find("var:preset|") {
        output.push_str(&remaining[..start]);
        let reference = &remaining[start + "var:preset|".len()..];
        let end = reference
            .find(|character: char| {
                !(character.is_ascii_alphanumeric() || matches!(character, '|' | '-' | '_'))
            })
            .unwrap_or(reference.len());
        let parts: Vec<_> = reference[..end].split('|').collect();
        if parts.len() >= 2 && parts.iter().all(|part| safe_css_slug(part)) {
            output.push_str("var(--wp--preset--");
            output.push_str(&parts.join("--"));
            output.push(')');
            remaining = &reference[end..];
        } else {
            output.push_str("var:preset|");
            remaining = reference;
        }
    }
    output.push_str(remaining);
    output
}

fn camel_to_kebab(value: &str) -> String {
    let mut output = String::with_capacity(value.len());
    for character in value.chars() {
        if character.is_ascii_uppercase() {
            if !output.is_empty() {
                output.push('-');
            }
            output.push(character.to_ascii_lowercase());
        } else {
            output.push(character);
        }
    }
    output
}

fn safe_css_slug(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'))
}

fn safe_css_value(value: &str) -> bool {
    !value.trim().is_empty()
        && value.len() <= 512
        && !value
            .bytes()
            .any(|byte| matches!(byte, b'{' | b'}' | b';' | b'<' | b'>' | b'\r' | b'\n'))
}

#[cfg(test)]
mod tests {
    use serde_json::Map;

    // Direct references keep the shared JSON helpers exercised even though
    // this module's behavior is covered end to end from `content::tests`.
    #[test]
    fn json_value_truthy_matches_php_semantics() {
        use serde_json::json;
        assert!(!super::json_value_truthy(&json!(null)));
        assert!(!super::json_value_truthy(&json!("0")));
        assert!(!super::json_value_truthy(&json!(0)));
        assert!(!super::json_value_truthy(&json!(Map::new())));
        assert!(super::json_value_truthy(&json!("value")));
        assert!(super::json_value_truthy(&json!(7)));
    }
}
