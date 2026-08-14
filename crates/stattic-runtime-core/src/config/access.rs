use std::collections::BTreeSet;

use serde_json::Value;
use unicode_normalization::UnicodeNormalization;

use crate::protocol::{
    CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT, CONFIG_ACCESS_RESOURCE_PATTERN_MAX_CHARS,
};

pub const CONFIG_ACCESS_PUBLIC_SOURCE_REFERENCE: &str = "sf.jsonc#$.access.public";

fn unsafe_character(character: char) -> bool {
    character <= '\u{1f}' || character == '\u{7f}' || matches!(character, '\\' | '?' | '#')
}

fn hex_value(byte: u8) -> Option<u8> {
    match byte {
        b'0'..=b'9' => Some(byte - b'0'),
        b'a'..=b'f' => Some(byte - b'a' + 10),
        b'A'..=b'F' => Some(byte - b'A' + 10),
        _ => None,
    }
}

fn percent_decode(input: &str) -> Result<String, &'static str> {
    let bytes = input.as_bytes();
    let mut decoded = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] != b'%' {
            decoded.push(bytes[index]);
            index += 1;
            continue;
        }
        if index + 2 >= bytes.len() {
            return Err("resource pattern contains malformed percent encoding");
        }
        let high = hex_value(bytes[index + 1])
            .ok_or("resource pattern contains malformed percent encoding")?;
        let low = hex_value(bytes[index + 2])
            .ok_or("resource pattern contains malformed percent encoding")?;
        decoded.push((high << 4) | low);
        index += 3;
    }
    String::from_utf8(decoded).map_err(|_| "resource pattern contains malformed percent encoding")
}

pub fn normalize_resource_pattern(input: &str) -> Result<String, &'static str> {
    if input.chars().count() > CONFIG_ACCESS_RESOURCE_PATTERN_MAX_CHARS {
        return Err("resource pattern exceeds the maximum length");
    }
    if !input.starts_with('/') || input.starts_with("//") || input.chars().any(unsafe_character) {
        return Err("resource pattern must be a plain absolute URL path pattern");
    }
    let lower = input.to_ascii_lowercase();
    if lower.contains("%2f") || lower.contains("%5c") || lower.contains("%2a") {
        return Err("resource pattern contains an ambiguous encoded separator or wildcard");
    }

    let decoded = percent_decode(input)?;
    if decoded.chars().any(unsafe_character) {
        return Err("resource pattern contains an unsafe character");
    }

    let mut segments = Vec::new();
    for raw_segment in decoded.split('/') {
        let segment = raw_segment.nfc().collect::<String>();
        if segment.is_empty() || segment == "." {
            continue;
        }
        if segment == ".." {
            if segments.pop().is_none() {
                return Err("resource pattern escapes the URL root");
            }
            continue;
        }
        if segment.contains('*') && segment != "*" && segment != "**" {
            return Err("resource wildcards must occupy a whole path segment");
        }
        segments.push(segment);
    }
    if segments
        .last()
        .is_some_and(|segment| segment == "index.html")
    {
        segments.pop();
    }
    Ok(if segments.is_empty() {
        "/".into()
    } else {
        format!("/{}", segments.join("/"))
    })
}

const ACCESS_SHAPE_MESSAGE: &str =
    "access must be an object with a public array, or the shorthand \"public\".";

pub fn normalized_public_patterns(access: &Value) -> Result<Vec<String>, (String, String)> {
    // `"access": "public"` is the shorthand for `{ "public": ["/**"] }`: the
    // whole live space, nothing excluded.
    if let Some(shorthand) = access.as_str() {
        return if shorthand == "public" {
            Ok(vec!["/**".into()])
        } else {
            Err(("access".into(), ACCESS_SHAPE_MESSAGE.into()))
        };
    }
    let object = access
        .as_object()
        .ok_or_else(|| ("access".into(), ACCESS_SHAPE_MESSAGE.into()))?;
    if object.keys().any(|key| key != "public") {
        return Err((
            "access".into(),
            "access supports only the public file declaration.".into(),
        ));
    }
    let public = object
        .get("public")
        .ok_or_else(|| {
            (
                "access".into(),
                "access.public is required when access is present.".into(),
            )
        })?
        .as_array()
        .ok_or_else(|| {
            (
                "access.public".into(),
                "access.public must be an array of strings.".into(),
            )
        })?;
    if public.len() > CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT {
        return Err((
            "access.public".into(),
            format!("access.public supports up to {CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT} patterns."),
        ));
    }

    let mut normalized = Vec::with_capacity(public.len());
    let mut seen = BTreeSet::new();
    let mut inclusion_count = 0;
    for (index, candidate) in public.iter().enumerate() {
        let raw = candidate.as_str().ok_or_else(|| {
            (
                format!("access.public.{index}"),
                "access.public entries must be strings.".into(),
            )
        })?;
        let excluded = raw.starts_with('!');
        let pattern = if excluded { &raw[1..] } else { raw };
        let pattern = normalize_resource_pattern(pattern)
            .map_err(|message| (format!("access.public.{index}"), message.to_string()))?;
        let identity = format!("{}:{pattern}", if excluded { "exclude" } else { "include" });
        if !seen.insert(identity) {
            return Err((
                format!("access.public.{index}"),
                "access.public patterns must be unique.".into(),
            ));
        }
        if !excluded {
            inclusion_count += 1;
            normalized.push(pattern);
        } else {
            normalized.push(format!("!{pattern}"));
        }
    }
    if !public.is_empty() && inclusion_count == 0 {
        return Err((
            "access.public".into(),
            "access.public requires at least one inclusion pattern.".into(),
        ));
    }
    Ok(normalized)
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{normalize_resource_pattern, normalized_public_patterns};

    #[test]
    fn normalizes_segment_patterns_without_accepting_ambiguous_paths() {
        assert_eq!(
            normalize_resource_pattern("/docs/./guides/../**"),
            Ok("/docs/**".into())
        );
        assert_eq!(normalize_resource_pattern("/index.html"), Ok("/".into()));
        for invalid in [
            "/docs/*.html",
            "/docs/%2f/private",
            "/%aé/**",
            "../docs",
            "/../../docs",
        ] {
            assert!(normalize_resource_pattern(invalid).is_err(), "{invalid}");
        }
    }

    #[test]
    fn validates_one_bounded_public_declaration() {
        assert_eq!(
            normalized_public_patterns(&json!({
                "public": ["/assets//**", "!/assets/private/**"]
            })),
            Ok(vec!["/assets/**".into(), "!/assets/private/**".into()])
        );
        assert!(normalized_public_patterns(&json!({"public": ["!/private/**"]})).is_err());
        assert!(normalized_public_patterns(&json!({"public": ["/docs/**", "/docs/**"]})).is_err());
    }

    #[test]
    fn expands_the_public_shorthand_to_everything() {
        assert_eq!(
            normalized_public_patterns(&json!("public")),
            Ok(vec!["/**".into()])
        );
        assert_eq!(
            normalized_public_patterns(&json!("public")),
            normalized_public_patterns(&json!({ "public": ["/**"] }))
        );
        assert!(normalized_public_patterns(&json!("private")).is_err());
        assert!(normalized_public_patterns(&json!([])).is_err());
    }
}
