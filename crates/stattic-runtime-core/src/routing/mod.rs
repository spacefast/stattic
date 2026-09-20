//! The `_redirects` / `_headers` convention compiler shared by native and
//! WASM callers: pattern grammar, limits, and diagnostics.

use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::ops::Range;
use std::sync::OnceLock;
use unicode_normalization::UnicodeNormalization;

mod headers;
pub mod placement;
#[cfg(test)]
mod placement_tests;
mod redirects;

use headers::compile_headers;
pub use placement::{
    place, placement_report, EdgeRuleSpec, Placement, PlacementInput, PlacementReportEntry,
};
use redirects::compile_redirects;

const REDIRECT_STATUSES: &[u16] = &[200, 301, 302, 303, 307, 308, 404];
/// Statuses a `sf.jsonc` redirect may spell. The rules that serve content
/// instead of answering with a Location live under `rewrites`: 200 is a plain
/// rewrite entry, 404 is a rewrite entry with `notFound`.
pub const CONFIG_REDIRECT_STATUSES: &[u16] = &[301, 302, 303, 307, 308];
/// The status a rule gets when it does not name one.
pub const CONFIG_REDIRECT_DEFAULT_STATUS: u16 = 302;
/// The status every plain `sf.jsonc` rewrite compiles to.
pub const CONFIG_REWRITE_STATUS: u16 = 200;
/// The status a `"notFound": true` rewrite compiles to — the `404` of a
/// `_redirects` line, spelled as the serve-other-content rule it is.
pub const CONFIG_NOT_FOUND_STATUS: u16 = 404;
const REDIRECT_LINE_LIMIT: usize = 1_000;
const HEADER_LINE_LIMIT: usize = 2_000;
const HEADER_RULE_LIMIT: usize = 100;
const REDIRECT_TOTAL_LIMIT: usize = 2_100;
const REDIRECT_STATIC_LIMIT: usize = 2_000;
const REDIRECT_DYNAMIC_LIMIT: usize = 100;

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingInput {
    #[serde(default)]
    pub redirects: String,
    #[serde(default)]
    pub headers: String,
    #[serde(default)]
    pub assigned_hostnames: Vec<String>,
    /// The `sf.jsonc` source, whole. The routing lane reads its own two keys out
    /// of it and ignores everything else.
    #[serde(default)]
    pub config_source: Option<String>,
    /// The committed path the config was read from, so a diagnostic names the
    /// file the publisher actually has. Absent means the canonical `sf.jsonc`.
    #[serde(default)]
    pub config_path: Option<String>,
    /// The Space overlay's own routing sections — what the dashboard wrote,
    /// never the file's rules and never the two already merged. They compile
    /// ahead of every file rule, which is what makes a dashboard rule an
    /// override rather than a suggestion.
    #[serde(default)]
    pub overlay_routing: Option<OverlayRouting>,
    /// The placement feature flag, off unless the finalize asks for it. False
    /// places nothing, so the compiled artifact is what it was before
    /// placement existed. See [`PlacementInput::enabled`].
    #[serde(default)]
    pub placement_enabled: bool,
    /// **Production hostnames only**, for [`PlacementInput::hostnames`]. Never
    /// `assigned_hostnames`: that list also carries version, immutable and
    /// branch hostnames, which serve other content and keep the full runtime
    /// ruleset. Empty places nothing.
    #[serde(default)]
    pub production_hostnames: Vec<String>,
    /// The version's published file paths, for [`PlacementInput::manifest_paths`]:
    /// manifest-relative, no leading slash.
    #[serde(default)]
    pub manifest_paths: BTreeSet<String>,
}

/// Where a `sf.jsonc` key is written. Merge diagnostics carry the line the
/// publisher can open, not line 0.
#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
pub struct RuleLocation {
    pub line: usize,
    pub column: usize,
}

/// The routing rules a `sf.jsonc` declares, in the config's own vocabulary —
/// the `redirects` / `rewrites` / `headers` sections as written, read out of the
/// document by `config::strict::routing_sections`.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct ConfigRouting {
    #[serde(default)]
    pub redirects: Vec<ConfigRedirect>,
    #[serde(default)]
    pub rewrites: Vec<ConfigRewrite>,
    #[serde(default)]
    pub headers: Vec<ConfigHeaderRule>,
    /// `$.redirects[1].destination` -> where it is written. The merge addresses
    /// its diagnostics through this map, so a rule problem reported against
    /// `sf.jsonc` points at the rule instead of at the top of the file.
    #[serde(default)]
    pub locations: BTreeMap<String, RuleLocation>,
}

/// The Space overlay's three routing sections, exactly as the overlay states
/// them.
///
/// Entries stay `Value` rather than being typed here: the strict grammar is
/// what judges a rule, so a malformed overlay entry earns the same diagnostic
/// a malformed `sf.jsonc` entry does instead of being silently dropped by
/// serde before anyone can report it.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct OverlayRouting {
    #[serde(default)]
    pub redirects: Option<Value>,
    #[serde(default)]
    pub rewrites: Option<Value>,
    #[serde(default)]
    pub headers: Option<Value>,
}

impl OverlayRouting {
    /// Whether the overlay states any routing at all. An overlay that declares
    /// none is the common case and costs the compile nothing.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.redirects.is_none() && self.rewrites.is_none() && self.headers.is_none()
    }

    /// The sections as the section collectors read them: one object carrying
    /// only the keys the overlay actually declared.
    #[must_use]
    pub fn sections(&self) -> Map<String, Value> {
        let mut root = Map::new();
        for (key, value) in [
            ("redirects", self.redirects.as_ref()),
            ("rewrites", self.rewrites.as_ref()),
            ("headers", self.headers.as_ref()),
        ] {
            if let Some(value) = value {
                root.insert(key.into(), value.clone());
            }
        }
        root
    }
}

#[derive(Debug, Clone, Deserialize)]
pub struct ConfigRedirect {
    #[serde(default)]
    pub path: String,
    pub source: String,
    pub destination: String,
    #[serde(default)]
    pub status: Option<u64>,
    /// `"force": true` is the `!` of a `_redirects` status token: answer even
    /// when the request path resolves to a committed file.
    #[serde(default)]
    pub force: bool,
    /// Query parameters the request must carry, `name -> capture` — the
    /// `name=:capture` tokens of a `_redirects` line, already split the way the
    /// compiled rule (and the PHP matcher) carry them.
    #[serde(default)]
    pub query: Option<BTreeMap<String, String>>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ConfigRewrite {
    #[serde(default)]
    pub path: String,
    pub source: String,
    pub destination: String,
    #[serde(default)]
    pub cache: Option<String>,
    /// The `!` of a `200!` / `404!` file rule: rewrite even when the request
    /// path resolves to a committed file.
    #[serde(default)]
    pub force: bool,
    /// Query parameters the request must carry, `name -> capture`, exactly as
    /// on [`ConfigRedirect::query`].
    #[serde(default)]
    pub query: Option<BTreeMap<String, String>>,
    /// `"notFound": true` serves the destination with status 404 — the
    /// `/gone /404.html 404` rule of `_redirects`, which is a rewrite in every
    /// way except the status it answers with.
    #[serde(default, rename = "notFound")]
    pub not_found: bool,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ConfigHeaderRule {
    #[serde(default)]
    pub path: String,
    pub source: String,
    #[serde(default)]
    pub headers: Vec<ConfigHeaderEntry>,
}

/// One header operation: `{ key, value }` sets, `{ key, remove: true }` is the
/// `!Name` line of a `_headers` block. The shape is what tells the two apart,
/// so a remove entry carries no value.
#[derive(Debug, Clone, Deserialize)]
pub struct ConfigHeaderEntry {
    pub key: String,
    #[serde(default)]
    pub value: Option<String>,
    #[serde(default)]
    pub remove: bool,
}

/// What the merge produced: the rules a version serves, plus what the merge
/// itself had to say about the config rules.
#[derive(Debug, Default)]
pub struct MergedRouting {
    pub redirects: Vec<RedirectRule>,
    pub headers: Vec<HeaderRule>,
    pub diagnostics: Vec<RoutingDiagnostic>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingCompilation {
    pub redirects: Vec<RedirectRule>,
    pub headers: Vec<HeaderRule>,
    pub diagnostics: Vec<RoutingDiagnostic>,
    pub stats: RoutingStats,
    pub sanitized_headers: Option<String>,
    /// The provider rules the edge-placed rules compile to. Empty — and
    /// omitted from the artifact — whenever placement is off, which is every
    /// compile that does not ask for it.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub edge_rules: Vec<EdgeRuleSpec>,
    /// Every judged rule's verdict — where it runs and, at the origin, why it
    /// could not move. Empty, and omitted, whenever placement is off: an
    /// unjudged rule has no verdict to report.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub placement: Vec<PlacementReportEntry>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RoutingStats {
    pub redirect_rule_count: usize,
    pub header_rule_count: usize,
    pub static_redirect_count: usize,
    pub dynamic_redirect_count: usize,
    pub proxy_rule_count: usize,
}

#[derive(Debug, Clone, Serialize)]
pub struct RoutingDiagnostic {
    /// The committed file to open. `_redirects` / `_headers` for the convention
    /// lane; for the config lane the path the config was actually read from,
    /// which is the canonical `sf.jsonc` unless the version ships an alias.
    pub file: String,
    pub line: usize,
    pub severity: &'static str,
    pub code: &'static str,
    pub message: String,
    pub source: String,
    #[serde(rename = "deferredUntilSubstitution", skip_serializing_if = "is_false")]
    pub deferred_until_substitution: bool,
}

fn is_false(value: &bool) -> bool {
    !value
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RedirectRule {
    pub source: String,
    pub destination: String,
    pub action: &'static str,
    pub status: u16,
    #[serde(rename = "match")]
    pub match_kind: &'static str,
    pub regex: Option<String>,
    pub host: Option<String>,
    pub host_regex: Option<String>,
    pub force: bool,
    pub query: Option<BTreeMap<String, String>>,
    pub conditions: Vec<RedirectCondition>,
    /// Explicit proxy response cache mode. Absent/default rules never enter
    /// shared caches; `shared` is only ever set on absolute-URL 200 proxies.
    pub cache: Option<&'static str>,
    /// The entitlement the ORIGIN checks against live serving state before it
    /// runs this rule. Stamped on every proxy rule regardless of the publishing
    /// team's plan, so an upgrade or downgrade takes effect without a
    /// republish; the compiled artifact never bakes a plan verdict.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub plan_gated: Option<&'static str>,
    /// Where this rule runs, once the placement pass has judged it, and the
    /// reason code when the edge could not carry it. Absent means placement
    /// never ran for this artifact, which is the origin serving everything.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub placement: Option<Placement>,
    /// Which authoring lane declared this rule. This is finalizer provenance,
    /// not part of the serving artifact: both lanes compile to the same
    /// canonical redirect shape and the runtime must not branch on authorship.
    #[serde(skip)]
    pub(crate) origin: &'static str,
}

#[derive(Debug, Clone, Serialize)]
pub struct RedirectCondition {
    pub kind: String,
    pub values: Vec<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct HeaderRule {
    pub path: String,
    pub host: Option<String>,
    pub regex: Option<String>,
    pub host_regex: Option<String>,
    pub operations: Vec<HeaderOperation>,
    pub headers: BTreeMap<String, String>,
    /// Which grammar wrote this rule: when both set the same header name for
    /// one request, the file's value is the one that ships.
    #[serde(skip_serializing_if = "is_file_origin")]
    pub origin: &'static str,
    /// Where this rule runs; see [`RedirectRule::placement`].
    #[serde(skip_serializing_if = "Option::is_none")]
    pub placement: Option<Placement>,
}

fn is_file_origin(origin: &&'static str) -> bool {
    *origin == "file"
}

#[derive(Debug, Clone, Serialize)]
pub struct HeaderOperation {
    pub kind: &'static str,
    pub name: String,
    pub value: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub line: Option<usize>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub source: Option<String>,
}

/// The `sf.jsonc` key a rule diagnostic belongs to, so the config lane can
/// point at the exact field instead of the whole entry.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RuleField {
    Source,
    Destination,
    Status,
    Cache,
    /// The whole `query` object: a bad pair rejects the map, not one key.
    Query,
    /// The `key` of the header entry at this position.
    HeaderKey(usize),
    /// The `value` of the header entry at this position.
    HeaderValue(usize),
}

impl RuleField {
    /// The JSON path of the offending key, under the entry that owns it. Both
    /// lanes address a rule problem the same way: the strict compiler reports
    /// `$.redirects[2].source` at authoring time, the merge reports it again for
    /// the publish that is actually happening.
    #[must_use]
    pub fn json_path(self, entry: &str) -> String {
        match self {
            RuleField::Source => format!("{entry}.source"),
            RuleField::Destination => format!("{entry}.destination"),
            RuleField::Status => format!("{entry}.status"),
            RuleField::Cache => format!("{entry}.cache"),
            RuleField::Query => format!("{entry}.query"),
            RuleField::HeaderKey(index) => format!("{entry}.headers[{index}].key"),
            RuleField::HeaderValue(index) => format!("{entry}.headers[{index}].value"),
        }
    }
}

/// One problem with a structured routing rule. The codes are the `_redirects`
/// and `_headers` diagnostic codes: one grammar, one set of names for what is
/// wrong with a rule.
#[derive(Debug, Clone)]
pub struct RuleIssue {
    pub field: RuleField,
    pub code: &'static str,
    pub severity: &'static str,
    pub message: String,
}

impl RuleIssue {
    pub(crate) fn error(field: RuleField, code: &'static str, message: impl Into<String>) -> Self {
        Self {
            field,
            code,
            severity: "error",
            message: message.into(),
        }
    }

    pub(crate) fn warning(
        field: RuleField,
        code: &'static str,
        message: impl Into<String>,
    ) -> Self {
        Self {
            field,
            code,
            severity: "warning",
            message: message.into(),
        }
    }
}

/// A single `sf.jsonc` rule compiled into the canonical routing model. The rule
/// is absent when the entry has an error; warnings keep it.
#[derive(Debug)]
pub struct ConfigRuleCompilation<T> {
    pub rule: Option<T>,
    pub issues: Vec<RuleIssue>,
}

impl<T> ConfigRuleCompilation<T> {
    fn rejected(issue: RuleIssue) -> Self {
        Self {
            rule: None,
            issues: vec![issue],
        }
    }
}

/// Compile one `sf.jsonc` redirect entry into the canonical routing rule. The
/// matcher grammar, the loop check and the diagnostic codes are the ones a
/// `_redirects` line gets — the JSON lane only spells them differently.
pub fn compile_config_redirect(
    entry: &ConfigRedirect,
    assigned_hostnames: &[String],
) -> ConfigRuleCompilation<RedirectRule> {
    let status = match entry.status {
        None => CONFIG_REDIRECT_DEFAULT_STATUS,
        Some(status) if status == u64::from(CONFIG_REWRITE_STATUS) => {
            return ConfigRuleCompilation::rejected(RuleIssue::error(
                RuleField::Status,
                "redirect_status_use_rewrites",
                "Status 200 serves other content under this URL. Move the rule to \"rewrites\"; a rewrite with an absolute-URL destination is a proxy.",
            ));
        }
        Some(status) if status == u64::from(CONFIG_NOT_FOUND_STATUS) => {
            return ConfigRuleCompilation::rejected(RuleIssue::error(
                RuleField::Status,
                "redirect_status_use_rewrites",
                "Status 404 serves a not-found page under this URL. Move the rule to \"rewrites\" and set \"notFound\": true.",
            ));
        }
        Some(value) => {
            let Some(supported) = u16::try_from(value)
                .ok()
                .filter(|status| CONFIG_REDIRECT_STATUSES.contains(status))
            else {
                return ConfigRuleCompilation::rejected(RuleIssue::error(
                    RuleField::Status,
                    "redirect_status_unsupported",
                    format!("Status {value} is not supported. Use 301, 302, 303, 307, or 308."),
                ));
            };
            supported
        }
    };
    compile_config_rule(
        &entry.source,
        &entry.destination,
        status,
        entry.force,
        entry.query.as_ref(),
        None,
        assigned_hostnames,
    )
}

/// Compile one `sf.jsonc` rewrite entry. A rewrite states no status: a path
/// destination serves that path under the requested URL, an absolute URL
/// destination proxies it, and `notFound` answers 404 instead of 200.
pub fn compile_config_rewrite(
    entry: &ConfigRewrite,
    assigned_hostnames: &[String],
) -> ConfigRuleCompilation<RedirectRule> {
    compile_config_rule(
        &entry.source,
        &entry.destination,
        if entry.not_found {
            CONFIG_NOT_FOUND_STATUS
        } else {
            CONFIG_REWRITE_STATUS
        },
        entry.force,
        entry.query.as_ref(),
        entry.cache.as_deref(),
        assigned_hostnames,
    )
}

/// The half both config sections share: string hygiene, the rule core, the
/// query matcher, the force flag, and the cache directive.
fn compile_config_rule(
    source: &str,
    destination: &str,
    status: u16,
    force: bool,
    query: Option<&BTreeMap<String, String>>,
    cache: Option<&str>,
    assigned_hostnames: &[String],
) -> ConfigRuleCompilation<RedirectRule> {
    // A `_redirects` line is whitespace-delimited, so no file rule can carry a
    // space, a tab or a control character inside its source or destination. JSON
    // strings can, and a `Location:` value with a CR in it is a response-splitting
    // attempt. The grammar is the same one either way, so neither lane accepts them.
    if source.chars().any(is_routing_space) || has_control_characters(source) {
        return ConfigRuleCompilation::rejected(RuleIssue::error(
            RuleField::Source,
            "redirect_source_invalid",
            "The source cannot contain whitespace or control characters.",
        ));
    }
    if routing_trim(destination).is_empty() {
        return ConfigRuleCompilation::rejected(RuleIssue::error(
            RuleField::Destination,
            "redirect_destination_missing",
            "Add a destination for this rule.",
        ));
    }
    if destination.chars().any(is_routing_space) || has_control_characters(destination) {
        return ConfigRuleCompilation::rejected(RuleIssue::error(
            RuleField::Destination,
            "redirect_destination_invalid",
            "The destination cannot contain whitespace or control characters.",
        ));
    }
    let mut rule =
        match redirects::compile_redirect_rule(source, destination, status, assigned_hostnames) {
            Ok(rule) => rule,
            Err(issue) => return ConfigRuleCompilation::rejected(issue),
        };
    rule.origin = "config";
    // The query grammar is checked by re-spelling each pair as the token a
    // `_redirects` line would carry — one regex, one answer. Neither half can
    // contain `=` or `:`, so the re-spelling cannot be gamed into matching.
    if let Some(query) = query {
        for (name, capture) in query {
            if !query_token_regex().is_match(&format!("{name}=:{capture}")) {
                return ConfigRuleCompilation::rejected(RuleIssue::error(
                    RuleField::Query,
                    "redirect_query_match_invalid",
                    "Query matches must map a parameter name to a capture name, the way { \"id\": \"id\" } spells id=:id.",
                ));
            }
        }
        // An empty object states no query requirement, which is what the
        // compiled rule spells as no query at all.
        if !query.is_empty() {
            rule.query = Some(query.clone());
        }
    }
    rule.force = force;
    let mut issues = Vec::new();
    // A misplaced cache directive costs the caching, never the route.
    match cache {
        None => {}
        Some("shared") if rule.action == "proxy" => rule.cache = Some("shared"),
        Some("shared") => issues.push(RuleIssue::warning(
            RuleField::Cache,
            "redirect_cache_not_proxy",
            "The \"cache\": \"shared\" directive only applies to rewrites with an absolute-URL destination.",
        )),
        Some(_) => issues.push(RuleIssue::error(
            RuleField::Cache,
            "redirect_cache_directive_invalid",
            "Proxy cache directives must use \"cache\": \"shared\".",
        )),
    }
    ConfigRuleCompilation {
        rule: Some(rule),
        issues,
    }
}

/// Compile one `sf.jsonc` header entry into the canonical routing rule. A
/// `{ key, value }` entry is a `Name: value` line, a `{ key, remove: true }`
/// entry is a `!Name` line: the same two operations the file grammar has.
pub fn compile_config_header(
    source: &str,
    entries: &[ConfigHeaderEntry],
) -> ConfigRuleCompilation<HeaderRule> {
    if has_control_characters(source) {
        return ConfigRuleCompilation::rejected(RuleIssue::error(
            RuleField::Source,
            "header_path_invalid",
            "The header matcher cannot contain control characters.",
        ));
    }
    let matcher = match headers::compile_header_matcher(source) {
        Ok(matcher) => matcher,
        Err(issue) => return ConfigRuleCompilation::rejected(issue),
    };
    let mut issues = Vec::new();
    let mut operations = Vec::new();
    for (index, entry) in entries.iter().enumerate() {
        // A `_headers` value is the rest of one line, so CR/LF can never appear
        // in it. In JSON they can, and that is a response-splitting attempt.
        if entry.value.as_deref().is_some_and(has_control_characters) {
            issues.push(RuleIssue::error(
                RuleField::HeaderValue(index),
                "header_value_invalid",
                "This header value cannot contain control characters.",
            ));
            continue;
        }
        match headers::check_header_name(&entry.key) {
            headers::HeaderNameVerdict::Accepted {
                canonical,
                advisory,
            } => {
                // Overriding a platform default is publisher metadata; deleting
                // the platform's own is not a rule we can honor — the same
                // verdict `normalize_header_operation` gives a `!Name` line.
                if entry.remove && crate::policy::never_removable_response_header(&canonical) {
                    issues.push(RuleIssue::error(
                        RuleField::HeaderKey(index),
                        "header_name_unsupported",
                        format!(
                            "The \"{canonical}\" header is set by Spacefast for every response and cannot be removed."
                        ),
                    ));
                    continue;
                }
                if let Some(note) = advisory {
                    issues.push(RuleIssue::warning(
                        RuleField::HeaderKey(index),
                        note.code,
                        note.message,
                    ));
                }
                operations.push(if entry.remove {
                    HeaderOperation {
                        kind: "remove",
                        name: canonical,
                        value: None,
                        line: None,
                        source: None,
                    }
                } else {
                    HeaderOperation {
                        kind: "set",
                        name: canonical,
                        value: Some(entry.value.clone().unwrap_or_default()),
                        line: None,
                        source: None,
                    }
                });
            }
            headers::HeaderNameVerdict::Rejected(note) => issues.push(RuleIssue::error(
                RuleField::HeaderKey(index),
                note.code,
                note.message,
            )),
        }
    }
    // A rule with nothing to set or remove is not a rule.
    if operations.is_empty() {
        return ConfigRuleCompilation { rule: None, issues };
    }
    let (path, host, regex, host_regex) = matcher;
    let rule = HeaderRule {
        path,
        host,
        regex,
        host_regex,
        // The flattened map is the simple-case convenience and carries sets
        // only, the same way the file lane's flush builds it.
        headers: operations
            .iter()
            .filter(|operation| operation.kind == "set")
            .map(|operation| {
                (
                    operation.name.clone(),
                    operation.value.clone().unwrap_or_default(),
                )
            })
            .collect(),
        operations,
        origin: "config",
        placement: None,
    };
    // A rejected key costs that key, not the rule.
    ConfigRuleCompilation {
        rule: Some(rule),
        issues,
    }
}

/// The merge: overlay rules first, then `_redirects` / `_headers`, then
/// `sf.jsonc`.
///
/// The merged redirect list is every Space-overlay rule, then every
/// `_redirects` rule in file order, then every `sf.jsonc` `redirects` entry,
/// then every `sf.jsonc` `rewrites` entry. Redirects are first-match-wins, so
/// the order IS the precedence: the dashboard's rule answers ahead of anything
/// the version committed — that is what makes it an override — while the two
/// committed lanes keep the order they have always had, `_redirects` asked
/// first and `sf.jsonc` adding behavior where the files are silent.
///
/// Headers accumulate — every matching rule applies — so each rule carries the
/// lane that wrote it, and both consumers
/// (`_stattic_apply_header_operations` in runtime/engine/runtime/headers.php,
/// `headersForRequest` in packages/routing/src/match.ts) resolve a clash on one
/// header name by lane precedence: overlay beats file beats config. Repeats
/// within a lane still accumulate, which is what keeps repeated `Set-Cookie`
/// entries working.
pub fn merge_config_routing(
    file_redirects: Vec<RedirectRule>,
    file_headers: Vec<HeaderRule>,
    overlay: &ConfigRouting,
    config: &ConfigRouting,
    config_file: &str,
    assigned_hostnames: &[String],
) -> MergedRouting {
    let mut merged = MergedRouting::default();
    // The overlay lane compiles into an empty list: nothing precedes it, so it
    // is never shadowed and its rules are asked first.
    push_config_lane(
        &mut merged,
        overlay,
        ConfigAddress {
            file: crate::config::strict::OVERLAY_ROUTING_FILE,
            locations: &overlay.locations,
        },
        "overlay",
        assigned_hostnames,
        0..0,
    );
    let file_redirects_from = merged.redirects.len();
    merged.redirects.extend(file_redirects);
    let file_redirect_range = file_redirects_from..merged.redirects.len();
    merged.headers.extend(file_headers);
    push_config_lane(
        &mut merged,
        config,
        ConfigAddress {
            file: config_file,
            locations: &config.locations,
        },
        "config",
        assigned_hostnames,
        file_redirect_range,
    );
    merged
}

/// One authoring lane's rules, compiled and appended in the order they were
/// written. `file_redirects` is where the `_redirects` rules already sit in the
/// merged list, which is the only slice a shadowing warning may look at.
fn push_config_lane(
    merged: &mut MergedRouting,
    lane: &ConfigRouting,
    address: ConfigAddress<'_>,
    origin: &'static str,
    assigned_hostnames: &[String],
    file_redirects: Range<usize>,
) {
    let push_redirect = |merged: &mut MergedRouting,
                         entry_path: &str,
                         deferred: bool,
                         compiled: ConfigRuleCompilation<RedirectRule>| {
        for issue in &compiled.issues {
            address.diagnostic(
                &mut merged.diagnostics,
                &issue.field.json_path(entry_path),
                issue.severity,
                issue.code,
                &issue.message,
                deferred,
            );
        }
        let Some(mut rule) = compiled.rule else {
            return;
        };
        rule.origin = origin;
        // Shadowing is exact-equality only: same compiled source matcher, no
        // glob-overlap guessing, and a conditional file rule is not a shadow —
        // warning about a rule that does run is worse than not warning.
        let shadowed = merged.redirects[file_redirects.clone()]
            .iter()
            .any(|file_rule| {
                file_rule.source == rule.source
                    && file_rule.host == rule.host
                    && file_rule.conditions.is_empty()
                    && file_rule.query.is_none()
                    && consumes_every_request(file_rule)
            });
        if shadowed {
            address.diagnostic(
                &mut merged.diagnostics,
                &RuleField::Source.json_path(entry_path),
                "warning",
                "redirect_shadowed_by_file",
                &format!(
                    "_redirects already routes \"{}\", so this rule never runs.",
                    rule.source
                ),
                false,
            );
        }
        merged.redirects.push(rule);
    };
    // Nothing substitutes an overlay rule: it is stored JSON, not staged file
    // text, so a `{{ vars.NAME }}` in it is a literal the publisher can see and
    // fix now rather than a value the server still owes them.
    let substituted = origin != "overlay";
    for entry in &lane.redirects {
        let deferred = substituted && has_pending_variable_destination(&entry.destination);
        let compiled = compile_config_redirect(entry, assigned_hostnames);
        push_redirect(merged, &entry.path, deferred, compiled);
    }
    for entry in &lane.rewrites {
        let deferred = substituted && has_pending_variable_destination(&entry.destination);
        let compiled = compile_config_rewrite(entry, assigned_hostnames);
        push_redirect(merged, &entry.path, deferred, compiled);
    }
    for entry in &lane.headers {
        let compiled = compile_config_header(&entry.source, &entry.headers);
        for issue in &compiled.issues {
            address.diagnostic(
                &mut merged.diagnostics,
                &issue.field.json_path(&entry.path),
                issue.severity,
                issue.code,
                &issue.message,
                false,
            );
        }
        if let Some(mut rule) = compiled.rule {
            rule.origin = origin;
            merged.headers.push(rule);
        }
    }
}

/// Whether a matching file rule ends the request, or merely gets first refusal.
/// An unforced rewrite (and its `notFound` sibling) is a fallback: the engine
/// resolves the request path against the version's files first and only rewrites
/// when nothing is there (`_stattic_apply_redirects` in
/// runtime/engine/runtime/redirects.php), so a config rule at the same source is
/// still reachable.
fn consumes_every_request(rule: &RedirectRule) -> bool {
    rule.force || !matches!(rule.action, "rewrite" | "notFound")
}

/// The shape codes the config collector can emit, narrowed to `'static` for the
/// diagnostic stream. Anything else would be a rule code, and rule codes reach
/// this stream through the merge instead.
fn config_issue_code(code: &str) -> &'static str {
    match code {
        "config_unknown_key" => "config_unknown_key",
        _ => "config_invalid",
    }
}

/// Where the config lane addresses its diagnostics: the config file the version
/// actually ships, plus that document's rule locations.
#[derive(Clone, Copy)]
struct ConfigAddress<'a> {
    file: &'a str,
    locations: &'a BTreeMap<String, RuleLocation>,
}

impl ConfigAddress<'_> {
    /// A merge diagnostic points at the config file, at the line the rule is
    /// written on, and names the offending key in `source`
    /// (`$.redirects[2].status`). A rule the document never located falls back
    /// to line 0.
    fn diagnostic(
        self,
        diagnostics: &mut Vec<RoutingDiagnostic>,
        path: &str,
        severity: &'static str,
        code: &'static str,
        message: &str,
        deferred_until_substitution: bool,
    ) {
        let line = self
            .locations
            .get(path)
            .or_else(|| self.locations.get(entry_path(path)))
            .map_or(0, |location| location.line);
        diagnostic(diagnostics, self.file, line, severity, code, message, path);
        if deferred_until_substitution {
            if let Some(item) = diagnostics.last_mut() {
                item.deferred_until_substitution = true;
            }
        }
    }
}

/// `$.redirects[2].status` -> `$.redirects[2]`: the entry a key belongs to,
/// which the document always has a location for even when the key itself is
/// absent (a defaulted status, a missing destination).
pub(crate) fn entry_path(path: &str) -> &str {
    path.rfind(']')
        .map(|end| &path[..=end])
        .filter(|entry| entry.len() < path.len())
        .unwrap_or(path)
}

/// Whether a rule string still carries a `{{ vars.NAME }}` marker
/// (`prepare::variable_pattern`): the authoring lanes cannot judge a rule whose
/// value the server has not filled in yet.
#[must_use]
pub fn has_variable_marker(value: &str) -> bool {
    crate::prepare::variable_pattern().is_match(value)
}

/// A destination the publisher expects server-side substitution to complete.
fn has_pending_variable_destination(destination: &str) -> bool {
    let value = routing_trim(destination);
    let Some(marker) = crate::prepare::variable_pattern().find(value) else {
        return false;
    };
    marker.start() == 0
        && value[marker.end()..]
            .chars()
            .next()
            .is_none_or(|ch| matches!(ch, '/' | '?' | '#'))
}

#[derive(Debug)]
struct PatternCompilation {
    error: Option<String>,
    regex: Option<String>,
    match_kind: &'static str,
}

#[derive(Debug)]
struct AbsoluteUrl {
    host: String,
    path: String,
    port: String,
}

#[derive(Debug)]
struct SourceMatcher {
    path: String,
    host: Option<String>,
    host_regex: Option<String>,
}

pub fn compile_routing_files(input: &RoutingInput) -> RoutingCompilation {
    let mut diagnostics = Vec::new();
    let (config, config_issues) = match &input.config_source {
        None => (ConfigRouting::default(), Vec::new()),
        Some(source) => crate::config::strict::routing_sections(source),
    };
    let (overlay, overlay_issues) = match input
        .overlay_routing
        .as_ref()
        .filter(|overlay| !overlay.is_empty())
    {
        None => (ConfigRouting::default(), Vec::new()),
        Some(overlay) => crate::config::strict::overlay_routing_sections(overlay),
    };
    let file_redirects = compile_redirects(input, &mut diagnostics);
    let file_headers = compile_headers(&input.headers, &mut diagnostics);
    // The sanitized `_headers` file is the file lane written back. A config
    // rule rendered into it would republish as file text and merge on top of
    // itself at the next finalize.
    let sanitized_headers = render_sanitized_headers(&file_headers);
    let file_counts = (file_redirects.len(), file_headers.len());
    let config_file = input
        .config_path
        .as_deref()
        .filter(|path| !path.is_empty())
        .unwrap_or(crate::protocol::CONFIG_CANONICAL_FILE);
    for (file, issues) in [
        (crate::config::strict::OVERLAY_ROUTING_FILE, overlay_issues),
        (config_file, config_issues),
    ] {
        for issue in issues {
            diagnostic(
                &mut diagnostics,
                file,
                issue.line,
                issue.severity,
                config_issue_code(&issue.code),
                &issue.message,
                &issue.path,
            );
        }
    }
    let mut merged = merge_config_routing(
        file_redirects,
        file_headers,
        &overlay,
        &config,
        config_file,
        &input.assigned_hostnames,
    );
    // Plan gating is an artifact-level fact, not a per-publish verdict: the
    // origin resolves the team's live entitlement at request time.
    for rule in &mut merged.redirects {
        if rule.action == "proxy" {
            rule.plan_gated = Some("external_proxy");
        }
    }
    // Placement runs after the merge, on the first-match-ordered list it
    // produced. All three facts come from the caller, and every one of them can
    // turn placement off by itself: the flag, the version's manifest, and the
    // PRODUCTION hostname set. That last one is never `assigned_hostnames` —
    // that list is what a rule source may legally name, version, immutable and
    // branch hostnames included, and those serve other content, so scoping edge
    // rules onto them would take rules away from preview hosts (spec §4).
    let edge_rules = place(
        &mut merged,
        &PlacementInput {
            manifest_paths: &input.manifest_paths,
            hostnames: &input.production_hostnames,
            enabled: input.placement_enabled,
        },
    );
    let placement = placement_report(&merged, &edge_rules);
    let (redirects, headers) = (merged.redirects, merged.headers);
    diagnostics.extend(merged.diagnostics);
    // Limits count what the version actually serves, so they run after the merge.
    add_limit_diagnostics(
        &redirects,
        &headers,
        file_counts,
        config_file,
        &mut diagnostics,
    );
    let static_redirect_count = redirects
        .iter()
        .filter(|rule| is_static_redirect(rule))
        .count();
    RoutingCompilation {
        stats: RoutingStats {
            redirect_rule_count: redirects.len(),
            header_rule_count: headers.len(),
            static_redirect_count,
            dynamic_redirect_count: redirects.len() - static_redirect_count,
            proxy_rule_count: redirects
                .iter()
                .filter(|rule| rule.action == "proxy")
                .count(),
        },
        redirects,
        headers,
        sanitized_headers,
        diagnostics,
        edge_rules,
        placement,
    }
}

fn render_sanitized_headers(headers: &[HeaderRule]) -> Option<String> {
    let mut lines = Vec::new();
    for rule in headers.iter().filter(|rule| !rule.operations.is_empty()) {
        lines.push(match &rule.host {
            Some(host) => format!("https://{host}{}", rule.path),
            None => rule.path.clone(),
        });
        for operation in &rule.operations {
            match operation.kind {
                "set" => lines.push(format!(
                    "  {}: {}",
                    operation.name,
                    operation.value.as_deref().unwrap_or_default()
                )),
                "remove" => lines.push(format!("  ! {}", operation.name)),
                _ => {}
            }
        }
    }
    (!lines.is_empty()).then(|| format!("{}\n", lines.join("\n")))
}

fn compile_pattern(
    pattern: &str,
    delimiter: char,
    allow_wildcard_prefix: bool,
    optional_trailing_slash: bool,
) -> PatternCompilation {
    for segment in pattern.split(delimiter) {
        if segment.contains('*')
            && segment != "*"
            && !segment.ends_with('*')
            && !(allow_wildcard_prefix && segment.starts_with('*'))
        {
            return pattern_error("Wildcards must appear at the end of a path segment.");
        }
        if segment.contains('*') && segment.contains(':') {
            return pattern_error("Wildcards and placeholders cannot be used in the same segment.");
        }
    }
    let mut seen_splat = false;
    let mut names = BTreeSet::new();
    let mut output = String::from("^");
    let chars: Vec<char> = pattern.chars().collect();
    let mut index = 0;
    while index < chars.len() {
        match chars[index] {
            '*' => {
                if seen_splat {
                    return pattern_error("Only one wildcard is supported.");
                }
                seen_splat = true;
                output.push_str("(?P<splat>.*)");
                index += 1;
            }
            ':' => {
                let start = index + 1;
                let mut end = start;
                while end < chars.len() && (chars[end].is_ascii_alphanumeric() || chars[end] == '_')
                {
                    end += 1;
                }
                let name: String = chars[start..end].iter().collect();
                if name.is_empty() || !name.starts_with(|ch: char| ch.is_ascii_alphabetic()) {
                    return pattern_error("Placeholders must start with a letter.");
                }
                if !names.insert(name.clone()) {
                    return pattern_error(&format!(
                        "Placeholder \":{name}\" can only be captured once."
                    ));
                }
                output.push_str(&format!(
                    "(?P<{name}>{})",
                    if delimiter == '/' { "[^/]+" } else { "[^.]+" }
                ));
                index = end;
            }
            ch => {
                push_escaped_regex_literal(&mut output, ch);
                index += 1;
            }
        }
    }
    if !seen_splat && names.is_empty() {
        return PatternCompilation {
            error: None,
            regex: None,
            match_kind: "exact",
        };
    }
    if optional_trailing_slash {
        output.push_str("/?");
    }
    output.push('$');
    PatternCompilation {
        error: None,
        regex: Some(output),
        match_kind: if seen_splat && names.is_empty() {
            "splat"
        } else {
            "pattern"
        },
    }
}

fn pattern_error(message: &str) -> PatternCompilation {
    PatternCompilation {
        error: Some(message.to_string()),
        regex: None,
        match_kind: "pattern",
    }
}

fn parse_absolute_url(value: &str) -> Option<AbsoluteUrl> {
    let captures = absolute_parse_regex().captures(value)?;
    let authority = captures.get(2)?.as_str();
    if authority.contains('@') {
        return None;
    }
    let port_capture = port_regex().captures(authority);
    let port = port_capture
        .as_ref()
        .and_then(|capture| capture.get(1))
        .map(|value| value.as_str())
        .unwrap_or("");
    let host = port_capture
        .as_ref()
        .and_then(|capture| capture.get(0))
        .map(|value| &authority[..authority.len() - value.as_str().len()])
        .unwrap_or(authority)
        .trim_end_matches('.');
    if host.is_empty() {
        return None;
    }
    Some(AbsoluteUrl {
        host: host.to_string(),
        path: captures
            .get(3)
            .map(|value| value.as_str())
            .filter(|value| !value.is_empty())
            .unwrap_or("/")
            .to_string(),
        port: port.to_string(),
    })
}

fn pattern_matches_value(pattern: &str, delimiter: char, value: &str) -> bool {
    let compiled = compile_pattern(pattern, delimiter, false, false);
    compiled
        .regex
        .as_deref()
        .map_or(pattern == value, |source| {
            Regex::new(source).is_ok_and(|regex| regex.is_match(value))
        })
}

/// The character set the grammar treats as whitespace. It is JavaScript's —
/// `String.prototype.trim` and `\s` — not Rust's `char::is_whitespace`, because
/// the two disagree in both directions: JS trims U+FEFF (a byte-order mark at
/// the head of a `_redirects` file is invisible padding, not part of the first
/// rule's source) and JS does NOT treat U+0085 as a separator. One grammar, one
/// answer, so the Rust lane spells out JS's set instead of inheriting Unicode's.
pub(crate) fn is_routing_space(ch: char) -> bool {
    matches!(
        ch,
        '\u{9}'..='\u{D}'
            | '\u{20}'
            | '\u{A0}'
            | '\u{1680}'
            | '\u{2000}'..='\u{200A}'
            | '\u{2028}'
            | '\u{2029}'
            | '\u{202F}'
            | '\u{205F}'
            | '\u{3000}'
            | '\u{FEFF}'
    )
}

/// `String.prototype.trim`, character for character.
pub(crate) fn routing_trim(value: &str) -> &str {
    value.trim_matches(is_routing_space)
}

/// `String.prototype.split(/\s+/)` on an already-trimmed line.
pub(crate) fn routing_tokens(value: &str) -> Vec<&str> {
    value
        .split(is_routing_space)
        .filter(|token| !token.is_empty())
        .collect()
}

/// Declaration length in UTF-16 code units, which is what `String.length`
/// counts. Counting UTF-8 bytes instead would reject non-ASCII rules the
/// TypeScript lane accepts, at a quarter of the documented limit.
pub(crate) fn declaration_length(value: &str) -> usize {
    value.chars().map(char::len_utf16).sum()
}

/// Characters no `_redirects` line or `_headers` block can contain, because the
/// file grammar is line- and whitespace-delimited. A JSON rule is not, so the
/// config lane checks explicitly: a `Location` or header value carrying CR/LF
/// would be a response-splitting attempt, and a NUL truncates in C-land.
pub(crate) fn has_control_characters(value: &str) -> bool {
    value.chars().any(|ch| ch.is_control() && ch != '\t')
}

fn normalize_path(value: &str) -> String {
    value.nfc().collect()
}

fn strip_trailing_slash(value: &str) -> String {
    if value == "/" {
        "/".into()
    } else {
        value.trim_end_matches('/').to_string()
    }
}
fn normalize_hostname(value: &str) -> String {
    routing_trim(value).trim_end_matches('.').to_lowercase()
}
fn push_escaped_regex_literal(output: &mut String, ch: char) {
    if matches!(
        ch,
        '|' | '\\' | '{' | '}' | '(' | ')' | '[' | ']' | '^' | '$' | '+' | '?' | '.'
    ) {
        output.push('\\');
    }
    output.push(ch);
}
pub(crate) fn escape_regex_literal(value: &str) -> String {
    let mut output = String::with_capacity(value.len());
    for ch in value.chars() {
        push_escaped_regex_literal(&mut output, ch);
    }
    output
}
fn has_host_pattern(value: &str) -> bool {
    value.contains('*') || value.contains(':')
}
fn is_static_redirect(rule: &RedirectRule) -> bool {
    rule.match_kind == "exact" && rule.host.is_none()
}
fn canonical_header_name(value: &str) -> String {
    routing_trim(value)
        .split('-')
        .map(|part| {
            let mut chars = part.chars();
            chars
                .next()
                .map(|first| {
                    first.to_uppercase().collect::<String>() + &chars.as_str().to_lowercase()
                })
                .unwrap_or_default()
        })
        .collect::<Vec<_>>()
        .join("-")
}

/// Rule budgets are counted over the merged rule list, so the diagnostic has to
/// name whichever source actually overflowed it: `sf.jsonc` when the files alone
/// stayed inside the budget, the convention file when they did not. Pointing a
/// publisher at a `_redirects` they never wrote is a dead end.
fn add_limit_diagnostics(
    redirects: &[RedirectRule],
    headers: &[HeaderRule],
    file_counts: (usize, usize),
    config_file: &str,
    diagnostics: &mut Vec<RoutingDiagnostic>,
) {
    let (file_redirect_count, file_header_count) = file_counts;
    let file_redirects = &redirects[..file_redirect_count];
    let static_of =
        |rules: &[RedirectRule]| rules.iter().filter(|rule| is_static_redirect(rule)).count();
    let static_count = static_of(redirects);
    let dynamic_count = redirects.len() - static_count;
    let overflowed_by_config = |total: usize, file_total: usize, limit: usize| {
        if total > limit && file_total <= limit {
            config_file
        } else {
            "_redirects"
        }
    };
    if redirects.len() > REDIRECT_TOTAL_LIMIT {
        diagnostic(
            diagnostics,
            overflowed_by_config(redirects.len(), file_redirect_count, REDIRECT_TOTAL_LIMIT),
            0,
            "error",
            "redirect_limit_exceeded",
            "Use 2100 or fewer redirect rules.",
            "",
        );
    }
    if static_count > REDIRECT_STATIC_LIMIT {
        diagnostic(
            diagnostics,
            overflowed_by_config(
                static_count,
                static_of(file_redirects),
                REDIRECT_STATIC_LIMIT,
            ),
            0,
            "error",
            "redirect_static_limit_exceeded",
            "Use 2000 or fewer static redirect rules.",
            "",
        );
    }
    if dynamic_count > REDIRECT_DYNAMIC_LIMIT {
        diagnostic(
            diagnostics,
            overflowed_by_config(
                dynamic_count,
                file_redirect_count - static_of(file_redirects),
                REDIRECT_DYNAMIC_LIMIT,
            ),
            0,
            "error",
            "redirect_dynamic_limit_exceeded",
            "Use 100 or fewer dynamic redirect rules.",
            "",
        );
    }
    if headers.len() > HEADER_RULE_LIMIT {
        diagnostic(
            diagnostics,
            if file_header_count <= HEADER_RULE_LIMIT {
                config_file
            } else {
                "_headers"
            },
            0,
            "error",
            "header_limit_exceeded",
            "Use 100 or fewer header rules.",
            "",
        );
    }
}

fn diagnostic(
    diagnostics: &mut Vec<RoutingDiagnostic>,
    file: &str,
    line: usize,
    severity: &'static str,
    code: &'static str,
    message: &str,
    source: &str,
) {
    diagnostics.push(RoutingDiagnostic {
        file: file.to_string(),
        line,
        severity,
        code,
        message: message.to_string(),
        source: source.to_string(),
        deferred_until_substitution: file == "_redirects"
            && matches!(
                code,
                "redirect_rewrite_destination_invalid" | "redirect_condition_invalid"
            )
            && server_resolved_variable_redirect_regex().is_match(source),
    });
}

fn server_resolved_variable_redirect_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| {
        Regex::new(
            r"(?i)^\s*\S+\s+(?:[A-Za-z_][A-Za-z0-9_-]*=:[A-Za-z][A-Za-z0-9_]*\s+)*\{\{\s*vars\.[A-Za-z_][A-Za-z0-9_]*\s*\}\}(?:[/?#][^\s]*)?\s+(?:200|404)!?(?:\s+(?:Country=[A-Za-z]{2}(?:,[A-Za-z]{2})*|Language=[^=\s,]+(?:,[^=\s,]+)*|Cookie=[^=\s,]+(?:,[^=\s,]+)*|Agent=(?:1|true|yes)(?:,(?:1|true|yes))*))*\s*$",
        )
        .expect("server-resolved redirect regex compiles")
    })
}

fn absolute_url_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"(?i)^https?://").unwrap())
}
fn absolute_parse_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| {
        Regex::new(r"(?i)^(https?)://([^/?#]+)([^?#]*)(?:\?[^#]*)?(?:#.*)?$").unwrap()
    })
}
// The parameter name is whatever the framework puts on the wire, and leading
// underscores are ordinary there (Next.js asks for its RSC payload with
// `?_rsc`). The capture name stays letter-leading: it is substituted into the
// destination through the `:placeholder` grammar, which is letter-leading too.
fn query_token_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^[A-Za-z_][A-Za-z0-9_-]*=:[A-Za-z][A-Za-z0-9_]*$").unwrap())
}
fn status_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    // `(?-u:\d)` is ASCII digits only. The `regex` crate's `\d` is Unicode-aware
    // and would read Arabic-Indic `٣٠١` as a status token; JavaScript's `\d`
    // never does, and one grammar means one answer.
    CELL.get_or_init(|| Regex::new(r"^((?-u:\d){3})(!)?$").unwrap())
}
fn port_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r":((?-u:\d)+)$").unwrap())
}
fn header_name_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r"^[A-Za-z0-9-]+$").unwrap())
}
fn cdn_headers() -> &'static BTreeSet<&'static str> {
    static CELL: OnceLock<BTreeSet<&'static str>> = OnceLock::new();
    CELL.get_or_init(|| {
        [
            "cdn-cache-control",
            "cloudflare-cdn-cache-control",
            "netlify-cdn-cache-control",
            "surrogate-control",
        ]
        .into_iter()
        .collect()
    })
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    #[test]
    fn compiles_full_redirect_and_header_shapes() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/old /new 301\n/docs/:slug /page/:slug 200! Country=NL Agent=true".into(),
            headers: "/docs/*\n  X-Test: yes\n  !X-Remove".into(),
            ..RoutingInput::default()
        });
        assert_eq!(result.redirects.len(), 2);
        assert_eq!(result.redirects[1].match_kind, "pattern");
        assert_eq!(result.redirects[1].conditions.len(), 2);
        assert_eq!(result.headers[0].operations.len(), 2);
        assert!(result.diagnostics.is_empty());
    }

    /// A publisher may override `Content-Type` — that is metadata — but not
    /// delete it: the compiler derives it per file and pairs it with `nosniff`,
    /// and a response with neither is the sniffing surface both exist to close.
    #[test]
    fn content_type_can_be_set_but_never_removed() {
        let result = compile_routing_files(&RoutingInput {
            headers: "/feed\n  Content-Type: application/rss+xml\n  ! Content-Type".into(),
            ..RoutingInput::default()
        });
        assert_eq!(
            result.headers[0]
                .operations
                .iter()
                .map(|operation| (operation.kind, operation.name.as_str()))
                .collect::<Vec<_>>(),
            vec![("set", "Content-Type")]
        );
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.severity, item.code, item.line))
                .collect::<Vec<_>>(),
            vec![("error", "header_name_unsupported", 3)]
        );
    }

    #[test]
    fn unicode_matchers_use_the_same_nfc_identity_as_browser_requests() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/cafe\u{301}-agent /target 302\n".into(),
            headers: "/cafe\u{301}.html\n  X-Test: yes".into(),
            ..RoutingInput::default()
        });

        assert_eq!(result.redirects[0].source, "/café-agent");
        assert_eq!(result.headers[0].path, "/café.html");
        assert_eq!(result.headers[0].regex.as_deref(), Some("^/café\\.html$"));
    }

    #[test]
    fn exact_header_regex_preserves_the_existing_wire_format() {
        let result = compile_routing_files(&RoutingInput {
            redirects: String::new(),
            headers: "/exact-path\n  X-Test: yes".into(),
            ..RoutingInput::default()
        });

        assert_eq!(result.headers.len(), 1);
        assert_eq!(result.headers[0].path, "/exact-path");
        assert_eq!(result.headers[0].regex.as_deref(), Some("^/exact-path$"));
        assert_eq!(result.headers[0].operations[0].line, Some(2));
        assert_eq!(
            result.headers[0].operations[0].source.as_deref(),
            Some("X-Test: yes")
        );
    }

    #[test]
    fn cache_shared_is_a_proxy_directive_not_a_condition() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/api/* https://api.example.com/:splat 200 cache=shared Country=nl\n/old /new 301 cache=shared\n/bad https://api.example.com/x 200 cache=public".into(),
            ..RoutingInput::default()
        });

        assert_eq!(result.redirects[0].cache, Some("shared"));
        assert_eq!(result.redirects[0].conditions.len(), 1);
        assert_eq!(result.redirects[1].cache, None);
        assert_eq!(result.redirects[2].cache, None);
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.severity, item.code))
                .collect::<Vec<_>>(),
            vec![
                ("warning", "redirect_cache_not_proxy"),
                ("error", "redirect_cache_directive_invalid"),
            ]
        );
    }

    #[test]
    fn classifies_only_complete_variable_redirects_as_deferred() {
        let valid = compile_routing_files(&RoutingInput {
            redirects: "/api/* _id=:id {{ vars.API_HOST }}/:id 200! Country=NL Agent=true".into(),
            ..RoutingInput::default()
        });
        assert!(valid.diagnostics.iter().any(|item| {
            item.deferred_until_substitution
                && matches!(
                    item.code,
                    "redirect_rewrite_destination_invalid" | "redirect_condition_invalid"
                )
        }));

        for decoy in [
            "/api/* {{vars.API_HOST}}bad 200",
            "/api/* id=id {{vars.API_HOST}}/:id 200",
            "/api/* {{vars.API_HOST}}/:splat 200 Role=admin",
            "/api/* {{vars.API_HOST}} 200 # comment",
        ] {
            let result = compile_routing_files(&RoutingInput {
                redirects: decoy.into(),
                ..RoutingInput::default()
            });
            assert!(result
                .diagnostics
                .iter()
                .all(|item| !item.deferred_until_substitution));
        }
    }

    #[test]
    fn config_rules_merge_redirects_then_rewrites_behind_the_files() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/old /new 301\n".into(),
            config_source: Some(
                r#"{
  "version": 1,
  "redirects": [
    { "source": "/old", "destination": "/config-loses" },
    { "source": "/legacy/*", "destination": "/archive/:splat", "status": 301 }
  ],
  "rewrites": [
    { "source": "/app/*", "destination": "/app/index.html" },
    { "source": "/api/*", "destination": "https://api.example.com/:splat", "cache": "shared" }
  ]
}
"#
                .into(),
            ),
            ..RoutingInput::default()
        });

        assert_eq!(
            result
                .redirects
                .iter()
                .map(|rule| (rule.source.as_str(), rule.action, rule.status, rule.cache))
                .collect::<Vec<_>>(),
            vec![
                ("/old", "redirect", 301, None),
                ("/old", "redirect", 302, None),
                ("/legacy/*", "redirect", 301, None),
                ("/app/*", "rewrite", 200, None),
                ("/api/*", "proxy", 200, Some("shared")),
            ]
        );
        // The shadow warning addresses the entry the publisher wrote: the line
        // it is on, and the key that collides.
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (
                    item.file.as_str(),
                    item.line,
                    item.code,
                    item.source.as_str()
                ))
                .collect::<Vec<_>>(),
            vec![(
                "sf.jsonc",
                4,
                "redirect_shadowed_by_file",
                "$.redirects[0].source"
            )]
        );
    }

    /// The Space's own rules answer first. Redirects are first-match-wins, so
    /// the order IS the override: a dashboard rule at a source the version also
    /// claims has to be asked before either file lane, or saving it in the
    /// dashboard would change nothing. It is never shadowed for the same
    /// reason, and it carries its lane all the way through placement — which is
    /// how the API tells the row apart from the ones the version shipped.
    #[test]
    fn overlay_rules_run_ahead_of_both_file_lanes_and_stay_named_overlay() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/old /file 301\n".into(),
            config_source: Some(
                r#"{ "redirects": [{ "source": "/old", "destination": "/config" }] }"#.into(),
            ),
            overlay_routing: Some(OverlayRouting {
                redirects: Some(
                    json!([{ "source": "/old", "destination": "/dashboard", "status": 307 }]),
                ),
                ..OverlayRouting::default()
            }),
            placement_enabled: true,
            production_hostnames: vec!["example.com".into()],
            ..RoutingInput::default()
        });

        assert_eq!(
            result
                .redirects
                .iter()
                .map(|rule| (rule.destination.as_str(), rule.status, rule.origin))
                .collect::<Vec<_>>(),
            vec![
                ("/dashboard", 307, "overlay"),
                ("/file", 301, "file"),
                ("/config", 302, "config"),
            ]
        );
        // Placement judges the merged list in that same order, and the edge
        // rows it produces carry the lane the control plane reports.
        assert_eq!(
            result
                .placement
                .iter()
                .map(|entry| entry.origin.as_str())
                .collect::<Vec<_>>(),
            vec!["overlay", "file", "config"]
        );
        assert_eq!(
            result.edge_rules.first().map(|spec| spec.source.as_str()),
            Some("overlay")
        );
        // Only the `sf.jsonc` rule is shadowed. The overlay rule is the one
        // doing the shadowing, so warning about it would be backwards.
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.file.as_str(), item.code))
                .collect::<Vec<_>>(),
            vec![("sf.jsonc", "redirect_shadowed_by_file")]
        );
    }

    /// An overlay rule has no file and no byte offset, so it is addressed by
    /// the row it is: `sf.overlay`, the 1-based position in its own list, and
    /// the same JSON path the file lane uses under an `overlay.` root. The
    /// codes are the file lane's — a rule refused in `sf.jsonc` is refused in
    /// the dashboard, with the same words — and one bad rule costs that rule,
    /// not the list.
    ///
    /// Every class of problem lands on the ROW, including the two that are
    /// reported about a key rather than a rule: a key the grammar does not
    /// know, and a key with the wrong type. Neither has a position of its own,
    /// and "line 1" would send the dashboard to the wrong row.
    #[test]
    fn an_invalid_overlay_rule_is_addressed_at_the_row_the_dashboard_owns() {
        let result = compile_routing_files(&RoutingInput {
            overlay_routing: Some(OverlayRouting {
                redirects: Some(json!([
                    { "source": "/keep", "destination": "/kept" },
                    { "source": "/moved", "destination": "/elsewhere.html", "status": 200 },
                    { "source": "/typo", "destination": "/fine", "stat": 301, "force": "yes" },
                ])),
                ..OverlayRouting::default()
            }),
            ..RoutingInput::default()
        });

        assert_eq!(
            result
                .redirects
                .iter()
                .map(|rule| rule.source.as_str())
                .collect::<Vec<_>>(),
            vec!["/keep"]
        );
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (
                    item.file.as_str(),
                    item.line,
                    item.code,
                    item.source.as_str()
                ))
                .collect::<Vec<_>>(),
            vec![
                (
                    "sf.overlay",
                    3,
                    "config_unknown_key",
                    "overlay.redirects[2].stat"
                ),
                (
                    "sf.overlay",
                    3,
                    "config_invalid",
                    "overlay.redirects[2].force"
                ),
                (
                    "sf.overlay",
                    2,
                    "redirect_status_use_rewrites",
                    "overlay.redirects[1].status"
                ),
            ]
        );
    }

    #[test]
    fn an_unforced_file_rewrite_does_not_shadow_the_config_rule_at_the_same_source() {
        // `_stattic_apply_redirects` (runtime/engine/runtime/redirects.php) skips
        // an unforced rewrite whose request path resolves to a committed file and
        // keeps walking, so the config rule at the same source is reachable.
        // Forcing the file rule is what makes the claim unconditional.
        let config_source = r#"{
  "rewrites": [
    { "source": "/app/*", "destination": "/config-spa.html" }
  ]
}
"#;
        let fallthrough = compile_routing_files(&RoutingInput {
            redirects: "/app/* /app/index.html 200\n".into(),
            config_source: Some(config_source.into()),
            ..RoutingInput::default()
        });
        assert_eq!(
            fallthrough
                .diagnostics
                .iter()
                .map(|item| item.code)
                .collect::<Vec<_>>(),
            Vec::<&str>::new()
        );
        assert_eq!(fallthrough.redirects.len(), 2);

        let forced = compile_routing_files(&RoutingInput {
            redirects: "/app/* /app/index.html 200!\n".into(),
            config_source: Some(config_source.into()),
            ..RoutingInput::default()
        });
        assert_eq!(
            forced
                .diagnostics
                .iter()
                .map(|item| (item.code, item.source.as_str()))
                .collect::<Vec<_>>(),
            vec![("redirect_shadowed_by_file", "$.rewrites[0].source")]
        );

        // A browser redirect answers and exits on match, forced or not.
        let terminal = compile_routing_files(&RoutingInput {
            redirects: "/app/* /elsewhere 301\n".into(),
            config_source: Some(config_source.into()),
            ..RoutingInput::default()
        });
        assert_eq!(
            terminal
                .diagnostics
                .iter()
                .map(|item| item.code)
                .collect::<Vec<_>>(),
            vec!["redirect_shadowed_by_file"]
        );
    }

    #[test]
    fn a_control_character_in_a_config_header_value_is_addressed_at_the_value_key() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                "{\n  \"headers\": [\n    { \"source\": \"/*\", \"headers\": [{ \"key\": \"X-Evil\", \"value\": \"a\\r\\nX-Smuggled: 1\" }] }\n  ]\n}\n".into(),
            ),
            ..RoutingInput::default()
        });

        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.code, item.source.as_str()))
                .collect::<Vec<_>>(),
            vec![("header_value_invalid", "$.headers[0].headers[0].value")]
        );
    }

    #[test]
    fn config_diagnostics_name_the_config_file_the_version_actually_ships() {
        let result = compile_routing_files(&RoutingInput {
            redirects: "/old /new 301\n".into(),
            config_source: Some(
                "{\n  \"redirects\": [\n    { \"source\": \"/old\", \"destination\": \"/config-loses\" }\n  ]\n}\n".into(),
            ),
            config_path: Some("spacefast.jsonc".into()),
            ..RoutingInput::default()
        });

        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.file.as_str(), item.line, item.code))
                .collect::<Vec<_>>(),
            vec![("spacefast.jsonc", 3, "redirect_shadowed_by_file")]
        );
    }

    #[test]
    fn a_config_rewrite_written_as_a_status_200_redirect_is_sent_to_rewrites() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                "{\n  \"redirects\": [\n    { \"source\": \"/app/*\", \"destination\": \"/app/index.html\", \"status\": 200 }\n  ]\n}\n".into(),
            ),
            ..RoutingInput::default()
        });

        assert!(result.redirects.is_empty());
        let diagnostic = result.diagnostics.first().expect("a diagnostic");
        assert_eq!(
            (
                diagnostic.file.as_str(),
                diagnostic.line,
                diagnostic.severity,
                diagnostic.code,
                diagnostic.source.as_str()
            ),
            (
                "sf.jsonc",
                3,
                "error",
                "redirect_status_use_rewrites",
                "$.redirects[0].status"
            )
        );
        assert!(diagnostic.message.contains("rewrites"));
    }

    /// The typed grammar is a superset spelled field by field: a config rule
    /// with `force`, `query` and `notFound` compiles to byte-for-byte the same
    /// canonical rule as the `_redirects` line it mirrors.
    #[test]
    fn config_force_query_and_not_found_compile_exactly_like_the_file_lines() {
        let file = compile_routing_files(&RoutingInput {
            redirects:
                "/store id=:id /blog/:id 301!\n/gone/* /404.html 404\n/app/* /app/index.html 200!"
                    .into(),
            ..RoutingInput::default()
        });
        let config = compile_routing_files(&RoutingInput {
            config_source: Some(
                r#"{
  "redirects": [
    { "source": "/store", "destination": "/blog/:id", "status": 301, "force": true, "query": { "id": "id" } }
  ],
  "rewrites": [
    { "source": "/gone/*", "destination": "/404.html", "notFound": true },
    { "source": "/app/*", "destination": "/app/index.html", "force": true }
  ]
}
"#
                .into(),
            ),
            ..RoutingInput::default()
        });

        assert!(file.diagnostics.is_empty(), "{:?}", file.diagnostics);
        assert!(config.diagnostics.is_empty(), "{:?}", config.diagnostics);
        assert_eq!(
            serde_json::to_value(&config.redirects).expect("config rules serialize"),
            serde_json::to_value(&file.redirects).expect("file rules serialize"),
        );
        assert_eq!(
            config
                .redirects
                .iter()
                .map(|rule| (rule.action, rule.status, rule.force))
                .collect::<Vec<_>>(),
            vec![
                ("redirect", 301, true),
                ("notFound", 404, false),
                ("rewrite", 200, true),
            ]
        );
    }

    #[test]
    fn a_config_query_the_token_grammar_cannot_spell_is_rejected_at_the_query_key() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                "{\n  \"redirects\": [\n    { \"source\": \"/a\", \"destination\": \"/b\", \"query\": { \"utm source\": \"u\" } }\n  ]\n}\n".into(),
            ),
            ..RoutingInput::default()
        });

        assert!(result.redirects.is_empty());
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.severity, item.code, item.source.as_str()))
                .collect::<Vec<_>>(),
            vec![(
                "error",
                "redirect_query_match_invalid",
                "$.redirects[0].query"
            )]
        );
    }

    /// 404 gets the same referral 200 does: the `redirects` section answers
    /// with a Location, and the rules that serve content live in `rewrites`.
    #[test]
    fn a_config_redirect_written_as_a_404_is_sent_to_rewrites_not_found() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                "{\n  \"redirects\": [\n    { \"source\": \"/gone\", \"destination\": \"/404.html\", \"status\": 404 }\n  ]\n}\n".into(),
            ),
            ..RoutingInput::default()
        });

        assert!(result.redirects.is_empty());
        let diagnostic = result.diagnostics.first().expect("a diagnostic");
        assert_eq!(
            (
                diagnostic.severity,
                diagnostic.code,
                diagnostic.source.as_str()
            ),
            (
                "error",
                "redirect_status_use_rewrites",
                "$.redirects[0].status"
            )
        );
        assert!(diagnostic.message.contains("notFound"));
    }

    /// A `{ key, remove: true }` entry is the `!Name` line: it compiles to the
    /// same remove operation, stays out of the flattened set map, and a
    /// never-removable name costs that entry with the file lane's verdict.
    #[test]
    fn config_header_removes_mirror_the_files_bang_lines() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                "{\n  \"headers\": [\n    { \"source\": \"/keep\", \"headers\": [\n      { \"key\": \"x-frame-options\", \"remove\": true },\n      { \"key\": \"Content-Type\", \"remove\": true },\n      { \"key\": \"X-Ok\", \"value\": \"1\" }\n    ] }\n  ]\n}\n".into(),
            ),
            ..RoutingInput::default()
        });

        assert_eq!(result.headers.len(), 1);
        assert_eq!(
            result.headers[0]
                .operations
                .iter()
                .map(|operation| (operation.kind, operation.name.as_str()))
                .collect::<Vec<_>>(),
            vec![("remove", "X-Frame-Options"), ("set", "X-Ok")]
        );
        assert_eq!(
            result.headers[0]
                .headers
                .iter()
                .map(|(name, value)| (name.as_str(), value.as_str()))
                .collect::<Vec<_>>(),
            vec![("X-Ok", "1")]
        );
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.severity, item.code, item.source.as_str()))
                .collect::<Vec<_>>(),
            vec![(
                "error",
                "header_name_unsupported",
                "$.headers[0].headers[1].key"
            )]
        );
    }

    #[test]
    fn config_rules_awaiting_substitution_are_deferred_like_the_file_lane() {
        let result = compile_routing_files(&RoutingInput {
            config_source: Some(
                r#"{
  "rewrites": [
    { "source": "/api/*", "destination": "{{ vars.API_HOST }}/:splat" },
    { "source": "/app/*", "destination": "later{{ vars.API_HOST }}" }
  ]
}
"#
                .into(),
            ),
            ..RoutingInput::default()
        });

        assert!(result.redirects.is_empty());
        assert_eq!(
            result
                .diagnostics
                .iter()
                .map(|item| (item.line, item.code, item.deferred_until_substitution))
                .collect::<Vec<_>>(),
            vec![
                (3, "redirect_destination_invalid", true),
                (4, "redirect_destination_invalid", false),
            ]
        );
    }

    #[test]
    fn basic_auth_directive_is_rejected_without_leaking_credentials() {
        let result = compile_routing_files(&RoutingInput {
            headers: "/private\n  Basic-Auth: alice:supersecret".into(),
            ..RoutingInput::default()
        });
        assert!(result.headers.is_empty());
        assert!(result.diagnostics.iter().any(|item| {
            item.code == "header_basic_auth_unsupported" && item.source == "[redacted]"
        }));
        assert!(result
            .diagnostics
            .iter()
            .all(|item| !item.source.contains("supersecret")));
    }
}
