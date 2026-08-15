//! Additive platform-source merging for a version's Content-Security-Policy.
//!
//! The control plane decides WHICH origins the platform needs in a published
//! page (its own API origin, the Cast endpoints, avatar hosts) — those come
//! from its environment and are validated against the host the version will
//! actually serve from. This module owns only the CSP mechanics: which
//! directives platform sources belong in, which directive a missing one
//! inherits from, and how tokens merge.
//!
//! The merge is strictly additive. A directive that is absent AND has no
//! browser fallback is already unrestricted for that resource type, so nothing
//! is created — inventing a platform-only directive there would NARROW the
//! publisher's policy.

use std::collections::BTreeMap;

/// Directive names platform sources may be merged into, in the order a newly
/// created directive is appended to the policy.
const PLATFORM_DIRECTIVES: [&str; 4] = ["script-src", "script-src-elem", "connect-src", "img-src"];

/// Directives a missing one inherits from, highest precedence first — the same
/// walk a browser performs.
fn directive_fallbacks(name: &str) -> &'static [&'static str] {
    match name {
        "script-src-elem" => &["script-src", "default-src"],
        _ => &["default-src"],
    }
}

/// Platform sources to merge, keyed by directive name. Unknown directive names
/// are ignored: this side owns the directive vocabulary.
pub type PlatformCspSources = BTreeMap<String, Vec<String>>;

struct Directive {
    /// The publisher's own spelling, so an existing directive keeps its case.
    name: String,
    tokens: Vec<String>,
}

fn unique_tokens(tokens: impl IntoIterator<Item = String>) -> Vec<String> {
    let mut seen = Vec::new();
    let mut out = Vec::new();
    for token in tokens {
        if token.is_empty() {
            continue;
        }
        let key = token.to_ascii_lowercase();
        if seen.contains(&key) {
            continue;
        }
        seen.push(key);
        out.push(token);
    }
    out
}

fn tokens_without_none(tokens: &[String]) -> Vec<String> {
    tokens
        .iter()
        .filter(|token| !token.eq_ignore_ascii_case("'none'"))
        .cloned()
        .collect()
}

fn base_tokens(directives: &BTreeMap<String, Directive>, name: &str) -> Option<Vec<String>> {
    for fallback in directive_fallbacks(name) {
        if let Some(directive) = directives.get(*fallback) {
            return Some(tokens_without_none(&directive.tokens));
        }
    }
    None
}

/// Merges the platform's sources into one policy value, preserving directive
/// order, spelling, and every token the publisher wrote.
pub fn merge_platform_csp_value(value: &str, sources: &PlatformCspSources) -> String {
    let mut order: Vec<String> = Vec::new();
    let mut directives: BTreeMap<String, Directive> = BTreeMap::new();
    for part in value.split(';') {
        let part = part.trim();
        if part.is_empty() {
            continue;
        }
        let mut fields = part.split_whitespace();
        let Some(raw_name) = fields.next() else {
            continue;
        };
        let key = raw_name.to_ascii_lowercase();
        if !directives.contains_key(&key) {
            order.push(key.clone());
        }
        directives.insert(
            key,
            Directive {
                name: raw_name.to_string(),
                tokens: fields.map(str::to_string).collect(),
            },
        );
    }

    for name in PLATFORM_DIRECTIVES {
        let Some(additions) = sources.get(name).filter(|additions| !additions.is_empty()) else {
            continue;
        };
        let existed = directives.contains_key(name);
        let base = match directives.get(name) {
            Some(directive) => Some(tokens_without_none(&directive.tokens)),
            None => base_tokens(&directives, name),
        };
        let Some(base) = base else {
            continue;
        };
        let spelling = directives
            .get(name)
            .map_or_else(|| name.to_string(), |directive| directive.name.clone());
        directives.insert(
            name.to_string(),
            Directive {
                name: spelling,
                tokens: unique_tokens(base.into_iter().chain(additions.iter().cloned())),
            },
        );
        if !existed {
            order.push(name.to_string());
        }
    }

    order
        .iter()
        .filter_map(|key| directives.get(key))
        .map(|directive| {
            let mut rendered = directive.name.clone();
            for token in &directive.tokens {
                rendered.push(' ');
                rendered.push_str(token);
            }
            rendered
        })
        .collect::<Vec<_>>()
        .join("; ")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sources() -> PlatformCspSources {
        PlatformCspSources::from([
            (
                "script-src".to_string(),
                vec!["https://api.test".to_string()],
            ),
            (
                "connect-src".to_string(),
                vec![
                    "https://api.test".to_string(),
                    "wss://cast.test".to_string(),
                ],
            ),
            (
                "img-src".to_string(),
                vec!["https://gravatar.com".to_string()],
            ),
        ])
    }

    #[test]
    fn platform_sources_extend_declared_directives_and_inherit_a_fallback() {
        let merged = merge_platform_csp_value(
            "default-src 'self'; script-src 'self' 'unsafe-inline'",
            &sources(),
        );
        assert_eq!(
            merged,
            "default-src 'self'; script-src 'self' 'unsafe-inline' https://api.test; \
connect-src 'self' https://api.test wss://cast.test; img-src 'self' https://gravatar.com"
        );
    }

    #[test]
    fn an_unrestricted_resource_type_is_never_narrowed_and_none_never_survives() {
        // No `default-src`, no `img-src`: images are unrestricted, and creating
        // a platform-only `img-src` would be the first restriction on them.
        let merged = merge_platform_csp_value("script-src 'none'", &sources());
        assert_eq!(
            merged, "script-src https://api.test",
            "'none' cannot coexist with the sources the platform needs"
        );
    }

    #[test]
    fn declared_spelling_and_duplicate_sources_survive_the_merge() {
        let merged = merge_platform_csp_value(
            "Script-Src 'self' https://API.test; default-src 'self'",
            &PlatformCspSources::from([(
                "script-src".to_string(),
                vec!["https://api.test".to_string()],
            )]),
        );
        assert_eq!(
            merged,
            "Script-Src 'self' https://API.test; default-src 'self'"
        );
    }
}
