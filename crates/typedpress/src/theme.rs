//! The project's styles as WordPress holds them (docs/decisions/2026-11-15-styles-from-wordpress.md).
//!
//! `tp/theme.json` is a theme.json document the project commits: written by hand, or by
//! `typedpress styles pull` from what was designed in a site's Styles screen. The build ships it in the
//! app plugin (`tp/app/theme.json`), where the runtime merges it over the active theme's
//! theme.json, below the site owner's Global Styles (`TypedPress\Wp\ThemeStyles`).
//!
//! The build always writes `tp/_generated/theme.css`: Tailwind v4 `@theme` tokens for the
//! active theme's semantic roles and the presets the build knows (`typedpress-default`, with
//! the project's file over it). Their values are the `--tp--*` runtime aliases and the
//! `--wp--preset--*` variables WordPress prints on every page. A
//! Tailwind utility such as `bg-primary` then reads the site's current color, including the
//! owner's edits. TypedPress itself runs no Tailwind: the project runs Tailwind's CLI (docs/pages.md).

use serde_json::Value;

/// The committed file, beside `typedpress.config.ts`.
pub const FILE: &str = "tp/theme.json";
/// The generated token sheet.
pub const TOKENS: &str = "tp/_generated/theme.css";
/// Where the app plugin carries the file, below its root.
pub const SHIPPED: &str = "tp/app/theme.json";

/// TypedPress's minimal block theme, which renders when no installed theme is active (`Hardening::blockTheme`).
const DEFAULT_THEME: &str = include_str!("../runtime/TypedPress/theme/typedpress-default/theme.json");

/// The keys `tp/theme.json` may hold. Templates, template parts and patterns are a theme's files,
/// not styles; a project that wants them ships a theme.
const KEYS: &[&str] = &["$schema", "version", "title", "description", "settings", "styles"];

/// One preset kind: its path under `settings`, the key holding its value, WordPress's variable
/// name and the Tailwind v4 theme namespace it maps to.
struct Preset {
    path: [&'static str; 2],
    wp: &'static str,
    /// The token's name before the slug: Tailwind's namespace, and for spacing a `wp-` so WordPress's
    /// numeric slugs (`20`…`80`) don't replace Tailwind's own `p-4`-style scale.
    tailwind: &'static str,
}

/// In the order WordPress lists them (`WP_Theme_JSON::PRESETS_METADATA`), the kinds Tailwind v4 has a
/// namespace for. Gradients, duotone, aspect ratios and dimensions have none and are left out.
const PRESETS: &[Preset] = &[
    Preset {
        path: ["color", "palette"],
        wp: "color",
        tailwind: "color",
    },
    Preset {
        path: ["typography", "fontSizes"],
        wp: "font-size",
        tailwind: "text",
    },
    Preset {
        path: ["typography", "fontFamilies"],
        wp: "font-family",
        tailwind: "font",
    },
    Preset {
        path: ["spacing", "spacingSizes"],
        wp: "spacing",
        tailwind: "spacing-wp",
    },
    Preset {
        path: ["shadow", "presets"],
        wp: "shadow",
        tailwind: "shadow",
    },
    Preset {
        path: ["border", "radiusSizes"],
        wp: "border-radius",
        tailwind: "radius",
    },
];

/// Stable roles of the active theme, resolved by WordPress at request time, not baked into a build.
const RUNTIME_TOKENS: &[(&str, &str)] = &[
    ("color-wp-background", "color--background"),
    ("color-wp-foreground", "color--foreground"),
    ("color-wp-link", "color--link"),
    ("font-wp-body", "font-family--body"),
    ("text-wp-body", "font-size--body"),
    ("leading-wp-body", "line-height--body"),
    ("spacing-wp-block", "spacing--block"),
    ("spacing-wp-page", "spacing--page"),
    ("container-wp-content", "width--content"),
    ("container-wp-wide", "width--wide"),
];

/// Checks `tp/theme.json`'s text: a theme.json object with only settings and styles.
pub fn parse(text: &str) -> Result<Value, String> {
    let value: Value = serde_json::from_str(text).map_err(|e| format!("{FILE} isn't valid JSON: {e}"))?;
    let Some(object) = value.as_object() else {
        return Err(format!("{FILE} must be a theme.json object"));
    };
    if !object.get("version").is_some_and(Value::is_u64) {
        return Err(format!("{FILE} needs a theme.json \"version\" (3)"));
    }
    if let Some(key) = object.keys().find(|k| !KEYS.contains(&k.as_str())) {
        return Err(format!(
            "{FILE} holds \"{key}\"; it carries settings and styles only (templates, parts and patterns belong to a theme)"
        ));
    }
    for key in ["settings", "styles"] {
        if object.get(key).is_some_and(|v| !v.is_object()) {
            return Err(format!("{FILE}: \"{key}\" must be an object"));
        }
    }
    Ok(value)
}

/// A preset slug as WordPress names its variable (`_wp_to_kebab_case`): words are runs of
/// lowercase letters (after at most one capital), capitals, or digits, joined by `-`. `xLarge` is
/// `x-large`, `2xl` is `2-xl`. Characters outside ASCII letters and digits only separate words.
pub fn kebab(slug: &str) -> String {
    let chars: Vec<char> = slug.chars().collect();
    let mut words: Vec<String> = Vec::new();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if c.is_ascii_digit() {
            let start = i;
            while i < chars.len() && chars[i].is_ascii_digit() {
                i += 1;
            }
            words.push(chars[start..i].iter().collect());
        } else if c.is_ascii_uppercase() {
            let start = i;
            while i < chars.len() && chars[i].is_ascii_uppercase() {
                i += 1;
            }
            // `XMLHttp`: the last capital starts the next word when lowercase follows it.
            if i < chars.len() && chars[i].is_ascii_lowercase() && i - start > 1 {
                i -= 1;
                words.push(chars[start..i].iter().collect());
                continue;
            }
            while i < chars.len() && chars[i].is_ascii_lowercase() {
                i += 1;
            }
            words.push(chars[start..i].iter().collect());
        } else if c.is_ascii_lowercase() {
            let start = i;
            while i < chars.len() && chars[i].is_ascii_lowercase() {
                i += 1;
            }
            words.push(chars[start..i].iter().collect());
        } else {
            i += 1;
        }
    }
    words.join("-").to_ascii_lowercase()
}

/// The presets of one kind the theme layer holds: the project's list replaces the default theme's
/// list, as WordPress merges one origin's presets (`WP_Theme_JSON::merge`).
fn presets<'a>(project: Option<&'a Value>, default: &'a Value, preset: &Preset) -> Vec<&'a Value> {
    let at = |doc: &'a Value| -> Option<&'a Vec<Value>> { doc["settings"][preset.path[0]][preset.path[1]].as_array() };
    project
        .and_then(at)
        .or_else(|| at(default))
        .map(|list| list.iter().collect())
        .unwrap_or_default()
}

/// `tp/_generated/theme.css` for the project's `tp/theme.json` (parsed by [`parse`]).
pub fn tokens(project: &Value) -> String {
    let default: Value = serde_json::from_str(DEFAULT_THEME).expect("typedpress-default's theme.json is JSON");
    let mut out = [
        "/* Generated by `typedpress build`: active-theme roles and project preset names. Do not edit.",
        " * Tailwind v4 theme tokens whose values are the variables WordPress prints on every page, so a",
        " * utility such as `bg-<color>` follows the site's Global Styles. In the stylesheet Tailwind's CLI reads:",
        " *   @import \"tailwindcss/theme.css\" layer(theme);",
        " *   @import \"./tp/_generated/theme.css\";",
        " *   @import \"tailwindcss/utilities.css\";",
        " * Without Tailwind, write the WordPress variables themselves: var(--wp--preset--color--<slug>). */",
        "@theme inline {",
        "",
    ]
    .join("\n");
    for (name, property) in RUNTIME_TOKENS {
        out.push_str(&format!("  --{name}: var(--tp--{property});\n"));
    }
    for preset in PRESETS {
        let mut seen = std::collections::BTreeSet::new();
        for item in presets(Some(project), &default, preset) {
            let Some(slug) = item["slug"].as_str().map(kebab).filter(|s| !s.is_empty()) else {
                continue;
            };
            // Two slugs with one variable name: WordPress prints the later; one token is enough.
            let name = format!("{}-{slug}", preset.tailwind);
            if seen.insert(slug.clone()) && !RUNTIME_TOKENS.iter().any(|(token, _)| *token == name) {
                out.push_str(&format!(
                    "  --{}-{slug}: var(--wp--preset--{}--{slug});\n",
                    preset.tailwind, preset.wp
                ));
            }
        }
    }
    out.push_str("}\n");
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn slugs_are_named_as_wordpress_names_their_variables() {
        for (slug, name) in [
            ("primary", "primary"),
            ("vivid-red", "vivid-red"),
            ("xLarge", "x-large"),
            ("x-large", "x-large"),
            ("2xl", "2-xl"),
            ("40", "40"),
            ("XMLHttp", "xml-http"),
            ("Brand Blue", "brand-blue"),
            ("accent_2", "accent-2"),
            ("", ""),
        ] {
            assert_eq!(kebab(slug), name, "{slug}");
        }
    }

    #[test]
    fn tokens_map_each_preset_to_its_wordpress_variable() {
        let project = json!({
            "version": 3,
            "settings": {
                "color": { "palette": [
                    { "slug": "primary", "color": "#123456", "name": "Primary" },
                    { "slug": "accentTwo", "color": "#abcdef", "name": "Accent" },
                    { "slug": "accent-two", "color": "#000", "name": "Duplicate" },
                    { "color": "#fff", "name": "No slug" }
                ], "gradients": [{ "slug": "dusk", "gradient": "linear-gradient(#000,#fff)" }] },
                "typography": { "fontSizes": [{ "slug": "small", "size": "0.875rem" }, { "slug": "2xl", "size": "2rem" }] },
                "spacing": { "spacingSizes": [{ "slug": "40", "size": "1rem" }] },
                "shadow": { "presets": [{ "slug": "soft", "shadow": "0 1px 2px #0003" }] },
                "border": { "radiusSizes": [{ "slug": "md", "size": "6px" }] }
            }
        });
        let css = tokens(&project);
        let body: Vec<&str> = css
            .split("@theme inline {\n")
            .nth(1)
            .unwrap()
            .lines()
            .filter(|l| *l != "}")
            .collect();
        assert_eq!(
            body,
            [
                "  --color-wp-background: var(--tp--color--background);",
                "  --color-wp-foreground: var(--tp--color--foreground);",
                "  --color-wp-link: var(--tp--color--link);",
                "  --font-wp-body: var(--tp--font-family--body);",
                "  --text-wp-body: var(--tp--font-size--body);",
                "  --leading-wp-body: var(--tp--line-height--body);",
                "  --spacing-wp-block: var(--tp--spacing--block);",
                "  --spacing-wp-page: var(--tp--spacing--page);",
                "  --container-wp-content: var(--tp--width--content);",
                "  --container-wp-wide: var(--tp--width--wide);",
                "  --color-primary: var(--wp--preset--color--primary);",
                "  --color-accent-two: var(--wp--preset--color--accent-two);",
                "  --text-small: var(--wp--preset--font-size--small);",
                "  --text-2-xl: var(--wp--preset--font-size--2-xl);",
                // The project sets no font families: TypedPress's default theme's stay.
                "  --font-system: var(--wp--preset--font-family--system);",
                "  --spacing-wp-40: var(--wp--preset--spacing--40);",
                "  --shadow-soft: var(--wp--preset--shadow--soft);",
                "  --radius-md: var(--wp--preset--border-radius--md);",
            ]
        );
        assert!(css.starts_with("/* Generated by `typedpress build`"));
        assert!(css.ends_with("}\n"));
    }

    #[test]
    fn only_a_theme_json_object_of_settings_and_styles_is_accepted() {
        assert!(parse(r#"{"version":3,"settings":{},"styles":{"css":"a{}"}}"#).is_ok());
        assert!(parse(r#"{"$schema":"https://schemas.wp.org/wp/7.2/theme.json","version":3}"#).is_ok());
        for (text, says) in [
            ("[]", "must be a theme.json object"),
            ("{", "isn't valid JSON"),
            (r#"{"settings":{}}"#, "needs a theme.json \"version\""),
            (r#"{"version":3,"templateParts":[]}"#, "holds \"templateParts\""),
            (r#"{"version":3,"styles":[]}"#, "\"styles\" must be an object"),
        ] {
            let error = parse(text).unwrap_err();
            assert!(error.contains(says), "{text}: {error}");
        }
    }

    #[test]
    fn runtime_roles_exist_without_a_project_theme_and_keep_their_meaning() {
        let defaults = tokens(&Value::Null);
        for (name, property) in RUNTIME_TOKENS {
            assert!(defaults.contains(&format!("--{name}: var(--tp--{property});")));
        }
        let project = json!({"version": 3, "settings": {"color": {"palette": [
            {"slug": "wp-background", "color": "#123456", "name": "Reserved role"}
        ]}}});
        let css = tokens(&project);
        assert_eq!(css.matches("--color-wp-background:").count(), 1);
        assert!(css.contains("--color-wp-background: var(--tp--color--background);"));
    }
}
