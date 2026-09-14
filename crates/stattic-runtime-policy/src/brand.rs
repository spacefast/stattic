//! The platform brand document: one flat, host-owned record of every string a
//! deployment puts its own name on.
//!
//! There is exactly one document per site and the Spacefast values are compiled
//! in as its defaults, so a bare Spacefast site configures nothing and answers
//! byte-for-byte as it always has. A white-label host sets the document once and
//! every branded surface follows: the problem-document `type` base, the built-in
//! error/gate pages (wordmark, help link, powered-by line, font preloads), the
//! access badge, and the outbound mail Message-ID domain.
//!
//! It lives in this leaf crate for the same reason the header lists do: the two
//! Rust consumers sit on opposite sides of the `stattic-runtime-core` /
//! `stattic-zero-runner` dependency diamond, so neither can host it for the
//! other. The PHP serving engine mirrors this shape in
//! `runtime/engine/shared/brand.php` — change one, change the other.
//!
//! NOT in this document: `$schema` URLs and manifest format tags
//! (`spacefast.zero.deploy.v1`, `spacefast:slot:badge:v1`, the
//! `X-Spacefast-*` response headers). Those are format identifiers that tooling
//! matches on; they never vary per site and re-branding them would break the
//! wire.

use serde::{Deserialize, Deserializer};

/// The configuration key carrying an override document, as JSON. Read through
/// the standard config lane — a wp-config constant, a process environment
/// variable, or the Atomic persistent-data blob — so it resolves identically on
/// a box, in the runner subprocess, and in tests. Unset means the defaults.
pub const BRAND_CONFIG_KEY: &str = "SPACEFAST_BRAND_JSON";

/// One downloadable face on the built-in pages. Emission order is CSS order.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct BrandFont {
    pub url: String,
    pub family: String,
    pub weight: String,
    /// Preloaded ahead of the inline stylesheet. Only the weight the pages
    /// actually render should be.
    #[serde(default)]
    pub preload: bool,
}

/// The resolved document. Every field is populated: an override supplies the
/// members it wants and the rest stay Spacefast's.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Brand {
    /// Product name. Also the wordmark's accessible label and the noun in
    /// "Protected by …" / "… could not serve this page".
    pub name: String,
    /// Primary marketing URL — the powered-by lockup and the badge link.
    pub url: String,
    /// Where a stuck visitor is sent from a built-in page.
    pub help_url: String,
    /// The one line of copy under the powered-by wordmark.
    pub tagline: String,
    /// Base for RFC 9457 problem-document `type` URIs: `{base}/{code}`.
    pub problem_docs_base_url: String,
    /// Right-hand side of the outbound `Message-ID` addr-spec.
    pub mail_domain: String,
    /// Image URL replacing the compiled-in wordmark. Empty keeps the built-in
    /// SVG, which is the only reason the default path stays byte-stable.
    pub wordmark_url: String,
    /// Faces the built-in pages embed and preload. Empty is legitimate: a host
    /// whose pages use system stacks downloads nothing.
    pub fonts: Vec<BrandFont>,
}

impl Default for Brand {
    fn default() -> Self {
        Self {
            name: "Spacefast".into(),
            url: "https://spacefast.com".into(),
            help_url: "https://spacefast.com/help".into(),
            tagline: "Best way to share what your agent made".into(),
            problem_docs_base_url: "https://spacefast.com/docs/errors".into(),
            mail_domain: "mail.spacefast.com".into(),
            wordmark_url: String::new(),
            // Recoleta is the only downloaded face — body and mono are system
            // stacks. It is commercial, never shipped in the engine, and loads
            // from wordpress.com's font CDN so every space hostname reuses one
            // warm browser-cache entry.
            fonts: ["300", "400", "500", "600", "700"]
                .into_iter()
                .map(|weight| BrandFont {
                    url: format!("https://wordpress.com/i/fonts/recoleta/{weight}.woff2"),
                    family: "Recoleta".into(),
                    weight: weight.into(),
                    preload: weight == "400",
                })
                .collect(),
        }
    }
}

impl Brand {
    /// The document a JSON override resolves to. Malformed JSON, a non-object,
    /// or an empty string all resolve to the defaults: a broken brand override
    /// must never take a site's error pages down with it.
    #[must_use]
    pub fn from_json(document: &str) -> Self {
        serde_json::from_str::<Self>(document).unwrap_or_default()
    }

    fn from_value(value: &serde_json::Value) -> Self {
        let Some(overrides) = value.as_object() else {
            return Self::default();
        };
        let mut brand = Self::default();
        for (field, key) in [
            (&mut brand.name, "name"),
            (&mut brand.url, "url"),
            (&mut brand.help_url, "helpUrl"),
            (&mut brand.tagline, "tagline"),
            (&mut brand.problem_docs_base_url, "problemDocsBaseUrl"),
            (&mut brand.mail_domain, "mailDomain"),
            (&mut brand.wordmark_url, "wordmarkUrl"),
        ] {
            if let Some(value) = overrides
                .get(key)
                .and_then(serde_json::Value::as_str)
                .map(str::trim)
                .filter(|value| !value.is_empty())
            {
                *field = value.to_string();
            }
        }
        // PHP treats an array as an explicit font override, then skips invalid
        // entries one by one. Keep that recovery rule identical here.
        if let Some(fonts) = overrides.get("fonts").and_then(serde_json::Value::as_array) {
            brand.fonts = fonts.iter().filter_map(BrandFont::from_value).collect();
        }
        brand
    }

    /// The document for this process, read once from [`BRAND_CONFIG_KEY`].
    #[must_use]
    pub fn from_env() -> Self {
        std::env::var(BRAND_CONFIG_KEY)
            .ok()
            .map_or_else(Self::default, |document| Self::from_json(&document))
    }

    /// The RFC 9457 `type` URI for a problem code.
    #[must_use]
    pub fn problem_type_url(&self, code: &str) -> String {
        format!(
            "{}/{code}",
            self.problem_docs_base_url.trim_end_matches('/')
        )
    }
}

/// Deserializing a brand document IS the merge: a partial object layers over the
/// compiled-in defaults, so every embedding contract (the page-compile input,
/// the config blob) gets the same resolution rule for free.
impl<'de> Deserialize<'de> for Brand {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        let value = serde_json::Value::deserialize(deserializer)?;
        Ok(Self::from_value(&value))
    }
}

impl BrandFont {
    fn from_value(value: &serde_json::Value) -> Option<Self> {
        let entry = value.as_object()?;
        let required = |key| {
            entry
                .get(key)
                .and_then(serde_json::Value::as_str)
                .map(str::trim)
                .filter(|value| !value.is_empty())
                .map(str::to_string)
        };
        Some(Self {
            url: required("url")?,
            family: required("family")?,
            weight: required("weight")?,
            preload: entry.get("preload").and_then(serde_json::Value::as_bool) == Some(true),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_compiled_in_document_is_spacefast() {
        let brand = Brand::default();
        assert_eq!(brand.name, "Spacefast");
        assert_eq!(
            brand.problem_type_url("version_not_found"),
            "https://spacefast.com/docs/errors/version_not_found"
        );
        assert_eq!(brand.mail_domain, "mail.spacefast.com");
        assert_eq!(brand.wordmark_url, "");
        assert_eq!(
            brand
                .fonts
                .iter()
                .filter(|font| font.preload)
                .map(|font| font.url.as_str())
                .collect::<Vec<_>>(),
            ["https://wordpress.com/i/fonts/recoleta/400.woff2"]
        );
    }

    #[test]
    fn an_override_replaces_only_the_members_it_names() {
        let brand = Brand::from_json(
            r#"{"name":"Partner Cloud","problemDocsBaseUrl":"https://partner.example/errors/","fonts":[]}"#,
        );
        assert_eq!(brand.name, "Partner Cloud");
        assert_eq!(
            brand.problem_type_url("space_not_found"),
            "https://partner.example/errors/space_not_found"
        );
        assert!(brand.fonts.is_empty());
        // Untouched members stay compiled-in.
        assert_eq!(brand.help_url, Brand::default().help_url);
        assert_eq!(brand.mail_domain, "mail.spacefast.com");
    }

    #[test]
    fn a_broken_or_blanked_document_falls_back_instead_of_unbranding_the_pages() {
        for document in [
            "",
            "not json",
            "[]",
            "null",
            r#"{"name":null,"url":"   ","helpUrl":""}"#,
        ] {
            assert_eq!(Brand::from_json(document), Brand::default(), "{document}");
        }
    }

    #[test]
    fn malformed_members_do_not_discard_valid_brand_overrides() {
        let brand = Brand::from_json(
            r#"{
                "name":" Partner Cloud ",
                "url":42,
                "fonts":[
                    {"url":"https://partner.example/body.woff2","family":" Partner Sans ","weight":" 500 ","preload":true},
                    {"url":"https://partner.example/broken.woff2","family":"Partner Sans"},
                    "not a font",
                    {"url":"https://partner.example/regular.woff2","family":"Partner Sans","weight":"400","preload":"yes"}
                ]
            }"#,
        );
        assert_eq!(brand.name, "Partner Cloud");
        assert_eq!(brand.url, Brand::default().url);
        assert_eq!(
            brand.fonts,
            [
                BrandFont {
                    url: "https://partner.example/body.woff2".into(),
                    family: "Partner Sans".into(),
                    weight: "500".into(),
                    preload: true,
                },
                BrandFont {
                    url: "https://partner.example/regular.woff2".into(),
                    family: "Partner Sans".into(),
                    weight: "400".into(),
                    preload: false,
                },
            ]
        );
    }
}
