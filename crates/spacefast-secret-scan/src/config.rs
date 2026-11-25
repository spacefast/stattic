//! TOML configuration model compatible with upstream `gitleaks.toml`.

use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;

use crate::tiers::Severity;

/// How multiple criteria inside one allowlist block combine.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "UPPERCASE")]
pub enum AllowlistCondition {
    #[default]
    Or,
    And,
}

/// Which string an allowlist regex runs against.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum RegexTarget {
    #[default]
    Secret,
    Match,
    Line,
}

/// Global or per-rule allowlist block matching `gitleaks.toml`.
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AllowlistConfig {
    #[serde(default)]
    pub description: Option<String>,
    #[serde(default)]
    pub condition: AllowlistCondition,
    #[serde(default)]
    pub regex_target: RegexTarget,
    #[serde(default)]
    pub paths: Vec<String>,
    #[serde(default)]
    pub regexes: Vec<String>,
    #[serde(default)]
    pub stopwords: Vec<String>,
}

/// One detection rule matching `[[rules]]` in `gitleaks.toml`.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RuleConfig {
    pub id: String,
    #[serde(default)]
    pub description: String,
    #[serde(default)]
    pub regex: Option<String>,
    #[serde(default)]
    pub path: Option<String>,
    #[serde(default)]
    pub secret_group: Option<usize>,
    #[serde(default)]
    pub entropy: Option<f64>,
    #[serde(default)]
    pub keywords: Vec<String>,
    #[serde(default)]
    pub allowlist: Option<AllowlistConfig>,
    #[serde(default)]
    pub allowlists: Vec<AllowlistConfig>,
    /// Optional explicit enforcement tier (overrides tier map when set).
    #[serde(default)]
    pub severity: Option<Severity>,
}

/// Complete configuration parsed from `gitleaks.toml` or constructed in code.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ScanConfig {
    #[serde(default)]
    pub title: Option<String>,
    #[serde(default)]
    pub min_version: Option<String>,
    #[serde(default)]
    pub allowlist: Option<AllowlistConfig>,
    #[serde(default)]
    pub allowlists: Vec<AllowlistConfig>,
    #[serde(default)]
    pub rules: Vec<RuleConfig>,
    /// Optional per-rule severity overrides (`rule_id -> Severity`).
    #[serde(default)]
    pub tiers: BTreeMap<String, Severity>,
    /// Rule IDs explicitly disabled.
    #[serde(default)]
    pub disabled_rules: Vec<String>,
}

impl RuleConfig {
    /// Builds a custom rule that matches an exact literal string (such as an
    /// organization-specific token prefix or internal secret marker).
    #[must_use]
    pub fn literal(
        id: impl Into<String>,
        description: impl Into<String>,
        literal: impl Into<String>,
        severity: Severity,
    ) -> Self {
        let literal = literal.into();
        let escaped = regex::escape(&literal);
        Self {
            id: id.into(),
            description: description.into(),
            regex: Some(escaped),
            path: None,
            secret_group: None,
            entropy: None,
            keywords: vec![literal.to_ascii_lowercase()],
            allowlist: None,
            allowlists: Vec::new(),
            severity: Some(severity),
        }
    }
}

impl ScanConfig {
    /// Parses a `gitleaks.toml` document.
    pub fn from_toml(source: &str) -> Result<Self, toml::de::Error> {
        toml::from_str(source)
    }

    /// Loads the embedded upstream `gitleaks.toml` rule set with default tiers.
    #[cfg(feature = "default-rules")]
    #[must_use]
    pub fn default_gitleaks() -> Self {
        let mut config = Self::from_toml(crate::DEFAULT_GITLEAKS_TOML)
            .expect("vendored gitleaks.toml must be valid TOML");
        config.tiers = crate::tiers::default_rule_tiers();
        config
    }

    /// Appends additional custom rules (for example, organization-specific
    /// token prefixes or DLP patterns).
    pub fn with_rules(mut self, rules: impl IntoIterator<Item = RuleConfig>) -> Self {
        self.rules.extend(rules);
        self
    }

    /// Appends exact-literal custom rules as `(id, description, literal, severity)`.
    pub fn with_literal_rules(
        mut self,
        literals: impl IntoIterator<Item = (String, String, String, Severity)>,
    ) -> Self {
        for (id, description, literal, severity) in literals {
            if !literal.is_empty() {
                self.rules
                    .push(RuleConfig::literal(id, description, literal, severity));
            }
        }
        self
    }

    /// Overrides the severity of one rule ID.
    pub fn with_tier(mut self, rule_id: impl Into<String>, severity: Severity) -> Self {
        self.tiers.insert(rule_id.into(), severity);
        self
    }

    /// Disables one or more rule IDs.
    pub fn with_disabled_rules(mut self, ids: impl IntoIterator<Item = impl Into<String>>) -> Self {
        self.disabled_rules.extend(ids.into_iter().map(Into::into));
        self
    }
}
