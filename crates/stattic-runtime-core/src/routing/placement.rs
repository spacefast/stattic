//! Placement: which merged routing rules the WP Cloud edge can run, and why
//! the rest stay at the origin.
//!
//! The runtime and the provider are not equally expressive. A `_redirects`
//! line can capture a path segment, read `Accept-Language`, or hold back
//! behind a published file; the provider's managed rules compare fixed fields
//! and answer with fixed targets. Placement is the pass that decides, rule by
//! rule, whether the edge can carry a rule faithfully — same requests matched,
//! same answer — and stamps a reason code on every rule it has to leave behind
//! so diagnostics can say why instead of shrugging.
//!
//! One rule governs every predicate below: **the edge may only match exactly
//! what the runtime would have matched.** Not more (the edge answers before the
//! origin is ever asked, so a wider match is a request the publisher's rules
//! never claimed) and not less (a production host's rule table omits what was
//! placed, so a narrower match is a rule that silently stopped existing). When
//! the two cannot be shown equal, the rule stays at the origin with a reason.
//!
//! The predicates are spec §5. Their order inside a rule is this module's: the
//! code a rule reports is the FIRST predicate that refuses it, so they run
//! most-specific first — what kind of rule it is, then what it says, then how
//! it sits among its neighbours.
//!
//! **Phase 1 places one shape of rule and one only:** an unconditional redirect
//! from an exact, lowercase, same-origin path to an exact, same-origin target,
//! and a header rule whose matcher is that same shape. Everything the grammar
//! can additionally express — a capture, a query match, a `Country=` or cookie
//! condition, a rewrite, an off-origin target — reads differently at the two
//! ends, and the rule that governs this module is that the edge may only match
//! exactly what the runtime would have matched. So each of those is a reason
//! code rather than a compilation. The codes are the value of this pass: an
//! author who wrote a rule that did not move can be told which property of it
//! kept it home.

use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

use regex::Regex;
use serde::Serialize;
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};

use super::{
    escape_regex_literal, has_variable_marker, strip_trailing_slash, HeaderRule, MergedRouting,
    RedirectRule,
};
use crate::policy::{never_removable_response_header, platform_managed_response_header};

/// Statuses the provider's `managed.redirect` answers with. 303 has no
/// provider spelling, which is the whole of `edge_ineligible_status`.
const EDGE_REDIRECT_STATUSES: &[u16] = &[301, 302, 307, 308];

/// How many provider rows one version's placed rules may take.
///
/// The provider allows 20 managed rules per site, and that budget is SHARED
/// with the firewall and cache rules a site may also carry — so routing cannot
/// spend all of it. Ten is the half this pass claims; the reconciler demotes
/// anything beyond what the site can actually hold. Counted in provider rows,
/// not in authored rules, because that is what the quota counts: a `_headers`
/// rule setting three headers is three rows.
///
/// It bounds the `\0rules` residue too. A placed rule stays in the ordered
/// residue so a production host can skip it per host, so without a cap a large
/// generated catalog would grow the one `\0rules` entry instead of shrinking
/// it.
const EDGE_PLACEMENT_LIMIT: usize = 10;

/// Where a rule runs, and — when it is the origin — why it could not move.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Placement {
    /// `"edge"` or `"origin"`.
    pub at: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub reason: Option<&'static str>,
}

impl Placement {
    fn edge() -> Self {
        Self {
            at: "edge",
            reason: None,
        }
    }

    fn origin(reason: &'static str) -> Self {
        Self {
            at: "origin",
            reason: Some(reason),
        }
    }
}

/// One provider rule, ready for the reconciler. This is the whole of what
/// placement hands forward: the reconciler adds the site it writes to and the
/// description it shows, both of which are its own.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EdgeRuleSpec {
    /// `sf-<kind prefix>-<12 hex>`, a digest of the definition. Identical
    /// rules collapse onto one provider row by construction, which is also
    /// what sidesteps the provider's 409 on duplicate configuration.
    pub rule_name: String,
    pub rule_key: &'static str,
    pub definition: Value,
    /// The rule's index in the merged, first-match-ordered list it came from.
    pub rule_order: u32,
    /// The authoring lane the rule came from: `file`, `config`, `overlay`.
    pub source: String,
    /// `redirect` or `header`.
    pub kind: &'static str,
}

/// One rule's verdict, in the shape the control plane stores on the version
/// row and the API reports back.
///
/// This is the whole record for a rule that STAYED at the origin: it has no
/// provider row, so nothing else downstream would ever know it existed, and
/// "your splat redirect could not move, here is the code that says why" is the
/// diagnostic the author actually needs. An edge-placed rule appears here too,
/// carrying the provider name that joins it to its `EdgeRuleSpec`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PlacementReportEntry {
    /// `redirect`, `rewrite` or `header`. See [`report_kind`] for why the
    /// merged redirect list reports two of those.
    pub kind: &'static str,
    /// The authoring lane: `file` (`_redirects` / `_headers`), `config`
    /// (`sf.jsonc`), or `overlay` (the Space's dashboard-written rules).
    pub origin: String,
    /// The rule's index in the merged, first-match-ordered list it came from,
    /// the same number its [`EdgeRuleSpec`]s carry. This is what joins a rule
    /// to its provider rules: a header rule compiles to one provider rule per
    /// operation and they share this index but not a name, so joining on
    /// `rule_name` alone would leave every operation after the first without
    /// the rule it belongs to.
    pub rule_order: u32,
    /// `edge` or `origin`.
    pub at: &'static str,
    /// The reason code, present only for a rule that stayed at the origin.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub reason: Option<&'static str>,
    /// The provider rule name, present only for an edge-placed rule, and the
    /// FIRST one when a header rule compiled to several. Read it as "this rule
    /// is at the edge, here is one of its names"; `rule_order` is what joins
    /// the rule to ALL of them.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub rule_name: Option<String>,
    /// What the rule matches: the redirect's source pattern, or the header
    /// rule's path matcher. Named `source` because that is what both grammars
    /// call the left-hand side.
    pub source: String,
    /// A redirect's destination.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub destination: Option<String>,
    /// A redirect's status.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub status: Option<u16>,
    /// The response header names a header rule sets or removes.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub headers: Option<Vec<String>>,
    /// Whether a redirect answers over a published file that could serve its
    /// source, instead of yielding to it. Absent on a header rule.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub force: Option<bool>,
    /// A redirect's query match — parameter name to capture name — when it
    /// states one. Absent means the rule matches on the path alone.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub query: Option<BTreeMap<String, String>>,
    /// `Some(true)` when the rule matches on a request fact the grammar can
    /// state but no editor can write back: a country, a language, a cookie, or
    /// the user agent. Absent otherwise. A reader copying such a rule into the
    /// dashboard would silently drop the condition and widen what it answers,
    /// so the copy has to be refused and this is what says so.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub conditional: Option<bool>,
}

/// What a merged redirect-list rule IS, in the kinds the API speaks.
///
/// That list holds four actions and only one of them redirects: a rewrite
/// serves other bytes under the requested URL, a proxy fetches them from
/// another origin, and a `notFound` answers 404. Reporting all four as
/// `redirect` is what let a reader be offered a 302 "override" of a rewrite —
/// which would have shadowed it with a browser redirect.
fn report_kind(action: &str) -> &'static str {
    if action == "redirect" {
        "redirect"
    } else {
        "rewrite"
    }
}

/// Every rule this version has, and where it runs.
///
/// EVERY rule, including on a build that placed nothing: a routing rule that
/// appears in no verdict appears nowhere at all downstream — the control plane
/// reports a Space its rules from this list — so a Space with placement off
/// would read as having no redirects and no headers. A rule `place` never
/// judged is reported as the origin's with reason [`PLACEMENT_OFF`], which is
/// the truth about it: nothing moved, and nothing about the rule stopped it.
///
/// This is a REPORT, not the rule. The rule structs are untouched by the
/// no-verdict case, so the compiled artifact a placement-off build serves is
/// byte-identical to one from before placement existed.
#[must_use]
pub fn placement_report(
    routing: &MergedRouting,
    specs: &[EdgeRuleSpec],
) -> Vec<PlacementReportEntry> {
    let name_of = |kind: &str, index: usize| -> Option<String> {
        specs
            .iter()
            .find(|spec| spec.kind == kind && spec.rule_order as usize == index)
            .map(|spec| spec.rule_name.clone())
    };
    let mut entries = Vec::new();
    for (index, rule) in routing.redirects.iter().enumerate() {
        let verdict = unjudged_is_placement_off(rule.placement.as_ref());
        entries.push(PlacementReportEntry {
            kind: report_kind(rule.action),
            origin: rule.origin.to_string(),
            rule_order: u32::try_from(index).unwrap_or(u32::MAX),
            at: verdict.0,
            reason: verdict.1,
            // Every provider rule this list compiled to is a `redirect` spec:
            // a rewrite, a proxy and a 404 are all refused before they could
            // have one, so a name only ever joins a rule this kind names too.
            rule_name: name_of("redirect", index),
            source: rule.source.clone(),
            destination: Some(rule.destination.clone()),
            status: Some(rule.status),
            headers: None,
            force: Some(rule.force),
            query: rule.query.clone(),
            conditional: (!rule.conditions.is_empty()).then_some(true),
        });
    }
    for (index, rule) in routing.headers.iter().enumerate() {
        let verdict = unjudged_is_placement_off(rule.placement.as_ref());
        entries.push(PlacementReportEntry {
            kind: "header",
            origin: rule.origin.to_string(),
            rule_order: u32::try_from(index).unwrap_or(u32::MAX),
            at: verdict.0,
            reason: verdict.1,
            rule_name: name_of("header", index),
            source: rule.path.clone(),
            destination: None,
            status: None,
            headers: Some(
                rule.operations
                    .iter()
                    .map(|operation| operation.name.clone())
                    .collect(),
            ),
            // A header rule has none of the three: it cannot be forced, it
            // cannot match a query, and the grammar gives it no conditions.
            force: None,
            query: None,
            conditional: None,
        });
    }
    entries
}

/// A rule's verdict as `(at, reason)`, reading an absent one as the origin's.
///
/// `place` stamps every rule or none of them: it short-circuits whole when the
/// switch is off and when the Space has no production hostname. So an absent
/// verdict is never "this rule was skipped" — it is "this build placed
/// nothing".
fn unjudged_is_placement_off(
    placement: Option<&Placement>,
) -> (&'static str, Option<&'static str>) {
    match placement {
        Some(placement) => (placement.at, placement.reason),
        None => ("origin", Some(PLACEMENT_OFF)),
    }
}

/// Why a rule stayed at the origin on a build that judged nothing. Not an
/// `edge_ineligible_*` code: those say what about the RULE refused the edge,
/// and this one says the question was never asked.
pub const PLACEMENT_OFF: &str = "placement_off";

/// What placement needs to know about the version beyond the rules themselves.
pub struct PlacementInput<'a> {
    /// Published file paths in this version's manifest, manifest-relative and
    /// without a leading slash (`index.html`, `docs/guide.html`) — the same
    /// spelling `transforms::effective` reads.
    pub manifest_paths: &'a BTreeSet<String>,
    /// **Production hostnames only**: the Space's default hostname plus its
    /// attached custom hostnames (apex and www). Not the assigned-hostname set
    /// the compiler validates rule sources against — that one also carries
    /// version, immutable and branch hostnames, which serve other content and
    /// must keep the full runtime ruleset so previews stay faithful (spec §4).
    ///
    /// Two things read this list: it becomes the `http.host in [...]` scope on
    /// every placed rule that names no host of its own, and a rule that DOES
    /// name a host is only placed when that host is in here.
    pub hostnames: &'a [String],
    /// The feature flag. False places nothing: every rule keeps the placement
    /// it already had (none), and no provider rule is emitted, so the compiled
    /// artifact is byte-identical to a build from before placement existed.
    pub enabled: bool,
}

/// Decide placement for every merged rule and return the provider rules the
/// edge-placed ones compile to.
///
/// Rules are judged in merged order because the ordering predicate depends on
/// the verdicts of everything before them: the provider evaluates a terminal
/// phase lowest-order-first, so a rule can only move to the edge when nothing
/// ahead of it in the runtime's first-match list could have answered first.
pub fn place(routing: &mut MergedRouting, input: &PlacementInput) -> Vec<EdgeRuleSpec> {
    // A Space with no production hostname has no edge to place onto: every
    // hostname it answers is a preview, version or branch host, and those keep
    // the full runtime ruleset. Same short-circuit as the flag being off.
    if !input.enabled || input.hostnames.is_empty() {
        return Vec::new();
    }
    let host_scope = host_scope_condition(input.hostnames);
    // Each rule's matcher, compiled once. A pattern that fails to compile
    // reads as `None`, and every predicate treats `None` as "could match" —
    // an unreadable matcher is doubt, and doubt refuses.
    let matchers: Vec<Option<Regex>> = routing
        .redirects
        .iter()
        .map(|rule| {
            rule.regex
                .as_deref()
                .and_then(|pattern| Regex::new(pattern).ok())
        })
        .collect();
    // Every request path this version can answer from a published file, built
    // once. Placed sources are exact literals, so shadowing is set membership.
    let servable = servable_request_paths(input.manifest_paths);
    let mut specs = Vec::new();
    // Rows still unspent, walked down in publisher order. The budget is the
    // LAST thing asked, after every predicate that judges the rule itself: a
    // rule the edge could never have carried must report why, not blame a
    // quota it was never eligible for.
    let mut budget = EDGE_PLACEMENT_LIMIT;

    for index in 0..routing.redirects.len() {
        let placement =
            match redirect_refusal(&routing.redirects, &matchers, index, &servable, input) {
                Some(reason) => Placement::origin(reason),
                None if budget == 0 => Placement::origin("edge_ineligible_quota"),
                None => {
                    budget -= 1;
                    let rule = &routing.redirects[index];
                    specs.push(spec(
                        "managed.redirect",
                        "redirect",
                        "rd",
                        redirect_definition(rule, &host_scope),
                        index,
                        rule.origin,
                    ));
                    Placement::edge()
                }
            };
        routing.redirects[index].placement = Some(placement);
    }

    let folded = folded_header_names(&routing.headers);
    for index in 0..routing.headers.len() {
        let placement = match header_refusal(&routing.headers[index], &folded, input) {
            Some(reason) => Placement::origin(reason),
            None => {
                // A header rule moves whole or not at all, so its whole row
                // count has to fit: half a rule at the edge would leave the
                // other half applying on top of it.
                let definitions = header_definitions(&routing.headers[index], &host_scope);
                if definitions.len() > budget {
                    Placement::origin("edge_ineligible_quota")
                } else {
                    budget -= definitions.len();
                    for definition in definitions {
                        specs.push(spec(
                            "managed.response_header",
                            "header",
                            "hd",
                            definition,
                            index,
                            routing.headers[index].origin,
                        ));
                    }
                    Placement::edge()
                }
            }
        };
        routing.headers[index].placement = Some(placement);
    }

    specs
}

fn spec(
    rule_key: &'static str,
    kind: &'static str,
    prefix: &str,
    definition: Value,
    index: usize,
    origin: &str,
) -> EdgeRuleSpec {
    EdgeRuleSpec {
        rule_name: provider_rule_name(prefix, &definition),
        rule_key,
        definition,
        // A merged list that overflowed `u32` would have failed the rule
        // budget long before placement; saturating keeps the cast honest
        // without a panic path.
        rule_order: u32::try_from(index).unwrap_or(u32::MAX),
        source: origin.to_string(),
        kind,
    }
}

// ---------------------------------------------------------------------------
// Provider identity
// ---------------------------------------------------------------------------

/// `providerRuleName` in `packages/common/src/traffic-rules/compile.ts`, in
/// Rust: `sf-<prefix>-<first 12 hex of sha256(canonicalJson(definition))>`.
/// The two lanes write rules for the same provider rows, so the name a rule
/// gets has to be the same byte string on both sides —
/// `packages/routing/fixtures/provider-rule-name.json` is where they are held
/// to it.
#[must_use]
pub fn provider_rule_name(prefix: &str, definition: &Value) -> String {
    let digest = format!(
        "{:x}",
        Sha256::digest(canonical_json(definition).as_bytes())
    );
    format!("sf-{prefix}-{}", &digest[..12])
}

/// `canonicalJson` (`compile.ts:75`): `JSON.stringify` with every object's
/// keys sorted and no whitespace. Arrays keep their order; objects nested
/// inside them are sorted too, exactly as the JS replacer does.
///
/// Key order is byte order here and UTF-16 code-unit order in JS. The two
/// disagree only above the BMP, and a provider definition's keys are the
/// schema's own ASCII names, so there is nothing for them to disagree about.
#[must_use]
pub fn canonical_json(value: &Value) -> String {
    let mut output = String::new();
    write_canonical(value, &mut output);
    output
}

fn write_canonical(value: &Value, output: &mut String) {
    match value {
        Value::Object(map) => {
            let mut keys: Vec<&String> = map.keys().collect();
            keys.sort_unstable();
            output.push('{');
            for (position, key) in keys.into_iter().enumerate() {
                if position > 0 {
                    output.push(',');
                }
                // `serde_json` escapes a string the way `JSON.stringify` does:
                // quote, backslash, and the C0 controls, with `\b \f \n \r \t`
                // spelled short and everything else as `\u00xx`.
                output.push_str(&Value::String(key.clone()).to_string());
                output.push(':');
                write_canonical(&map[key], output);
            }
            output.push('}');
        }
        Value::Array(items) => {
            output.push('[');
            for (position, item) in items.iter().enumerate() {
                if position > 0 {
                    output.push(',');
                }
                write_canonical(item, output);
            }
            output.push(']');
        }
        other => output.push_str(&other.to_string()),
    }
}

// ---------------------------------------------------------------------------
// Redirects
// ---------------------------------------------------------------------------

/// The first predicate that refuses this rule, or `None` when the edge can
/// carry it. Spec §5, "Redirects" and "Rewrites".
fn redirect_refusal(
    rules: &[RedirectRule],
    matchers: &[Option<Regex>],
    index: usize,
    servable: &BTreeSet<String>,
    input: &PlacementInput,
) -> Option<&'static str> {
    let rule = &rules[index];
    // What kind of rule it is. A `notFound` serves a body under the requested
    // URL with a 404 and a proxy fetches another origin; the provider's
    // redirect phase does neither.
    if rule.action == "notFound" {
        return Some("edge_ineligible_not_found");
    }
    if rule.action == "proxy" {
        return Some("edge_ineligible_proxy");
    }
    // A rewrite is terminal in the runtime: it picks the file to serve and the
    // matching stops. At the edge it only changes the request, which then
    // reaches the origin and is matched all over again — against a rule table
    // that no longer contains this rule but does contain every rule the
    // rewritten path happens to hit. Phase 1 does not place rewrites at all.
    if rule.action == "rewrite" {
        return Some("edge_ineligible_reentry");
    }
    // A whole-host redirect sends every path on one hostname to another host,
    // and the provider's redirect target is a fixed string — it cannot carry
    // the request path across. Spec §5: "Host redirects: always runtime
    // today." This is the single predicate to flip when the provider adds
    // path preservation.
    if is_host_redirect(rule) {
        return Some("edge_ineligible_path_preservation");
    }
    if !EDGE_REDIRECT_STATUSES.contains(&rule.status) {
        return Some("edge_ineligible_status");
    }
    if !is_literal_source(rule) || !is_literal_target(&rule.destination) {
        return Some("edge_ineligible_capture");
    }
    // An off-origin destination answers from the edge without the origin ever
    // being asked, and the origin is what mints the durable Link token onto a
    // redirect it answers itself — it deliberately strips that token from an
    // off-origin target, and the edge, which knows nothing about tokens, would
    // forward the request as-is. Same URL, different credential.
    if is_off_origin_destination(&rule.destination) {
        return Some("edge_ineligible_destination");
    }
    if !matches_the_same_requests(rule, input.hostnames) {
        return Some("edge_ineligible_condition");
    }
    // Phase 1 places UNCONDITIONAL rules only. Both conditions the grammar can
    // write read differently at the two ends:
    //
    // - `Country=` is the origin reading the `nf_country` request cookie a CDN
    //   put there, which a visitor can set; the provider's `geo.country` is its
    //   own geo lookup. They disagree for exactly the requests that matter.
    // - a cookie condition names a cookie the origin looks up case-folded
    //   (`_stattic_request_cookies` lowercases) while the provider's
    //   `http.cookie` key is compared as written.
    //
    // Neither is a difference a reader could predict from their `_redirects`
    // line, so both stay where they already work.
    if !rule.conditions.is_empty() {
        return Some("edge_ineligible_condition");
    }
    // The runtime resolves an unforced rule against the version's files first
    // and only then routes. An edge-placed copy answers before the origin is
    // asked at all, so a rule whose source a published file can satisfy has to
    // stay where the file is.
    if !rule.force && servable.contains(&rule.source) {
        return Some("edge_ineligible_shadowed");
    }
    // First-match order. A rule can only be lifted out of the runtime's list
    // when nothing left behind ahead of it could have answered first.
    if (0..index).any(|earlier| {
        !placed_at_edge(&rules[earlier])
            && !provably_disjoint(
                (&rules[earlier], matchers[earlier].as_ref()),
                (rule, matchers[index].as_ref()),
            )
    }) {
        return Some("edge_ineligible_order");
    }
    None
}

fn placed_at_edge(rule: &RedirectRule) -> bool {
    rule.placement.as_ref().is_some_and(|p| p.at == "edge")
}

/// A rule bound to one hostname whose destination points at another host: the
/// shape a domain attachment's whole-host redirect takes, and the shape the
/// provider's fixed target cannot reproduce.
fn is_host_redirect(rule: &RedirectRule) -> bool {
    rule.host.is_some() && is_off_origin_destination(&rule.destination)
}

/// Whether the destination leaves this origin. An absolute URL does.
fn is_off_origin_destination(destination: &str) -> bool {
    super::absolute_url_regex().is_match(destination)
}

/// Whether the source matcher is a literal path the provider can compare
/// against. Phase 1 places exact sources only.
///
/// A trailing `*` is refused with everything else: `/docs/*` compiles to
/// `(?P<splat>.*)` after `/docs/`, so the runtime matches `/docs/` and
/// `/docs/a` but NOT the bare `/docs`, while the provider's `wildcard` is a
/// glob whose `*` also accepts the empty rest — a different set of requests,
/// on the one path shape publishers use most.
fn is_literal_source(rule: &RedirectRule) -> bool {
    rule.match_kind == "exact" && !has_variable_marker(&rule.source)
}

/// The provider answers with a fixed string: no capture expansion, no splat,
/// no variable the finalizer has not already substituted — and no fragment.
///
/// A fragment is refused because the runtime appends the visitor's query
/// BEFORE the fragment (`appendIncomingQuery` splits on `#`), and the
/// provider's `preserve_query` appends to the end of `target_url`:
/// `/new#top` would answer `/new#top?a=1`, which sends the query to the
/// browser as part of the fragment.
fn is_literal_target(target: &str) -> bool {
    !capture_regex().is_match(target) && !has_variable_marker(target) && !target.contains('#')
}

/// `:name` / `:splat` as the runtime expands them (`expand` in
/// packages/routing/src/match.ts).
fn capture_regex() -> &'static Regex {
    static CELL: OnceLock<Regex> = OnceLock::new();
    CELL.get_or_init(|| Regex::new(r":[A-Za-z][A-Za-z0-9_]*").unwrap())
}

/// The comparisons where the provider is broader than the runtime, and a
/// literal source alone is not enough to show the two match the same requests.
fn matches_the_same_requests(rule: &RedirectRule, hostnames: &[String]) -> bool {
    // The provider compares `http.path` and `http.host` case-insensitively;
    // the runtime compares paths byte for byte. The two agree only where the
    // source has no case to fold.
    if rule.source != rule.source.to_lowercase() {
        return false;
    }
    // A query match states "these parameters and no others" — `queryMatches`
    // in packages/routing/src/match.ts compares the request's distinct
    // parameter count against the rule's. No condition list can say "and
    // nothing else", so an edge copy would fire on requests carrying an extra
    // parameter that the runtime skips.
    if rule.query.is_some() {
        return false;
    }
    // A host-scoped rule is placed only onto a production hostname. Anywhere
    // else the host names a preview, version or branch host, which serves
    // other content and keeps the full runtime ruleset.
    match &rule.host {
        None => true,
        Some(host) => hostnames.iter().any(|production| production == host),
    }
}

/// Every request path this version can answer from a published file, in the
/// spelling a rule source carries (leading slash, no trailing slash).
///
/// Generous on purpose: a false positive costs offload, a false negative lets
/// the edge answer a request the origin would have served from a file. So a
/// manifest entry is listed under every request path that reaches it — the
/// path itself, its clean-URL form, and the directory it indexes.
fn servable_request_paths(manifest_paths: &BTreeSet<String>) -> BTreeSet<String> {
    let mut servable = BTreeSet::new();
    for path in manifest_paths {
        servable.insert(strip_trailing_slash(&format!("/{path}")));
        if let Some(directory) = path.strip_suffix("index.html") {
            servable.insert(strip_trailing_slash(&format!("/{directory}")));
        }
        if let Some(clean) = path.strip_suffix(".html") {
            servable.insert(strip_trailing_slash(&format!("/{clean}")));
        }
    }
    servable
}

/// Whether two rules can never match the same request. Spec §5: two exact
/// paths that differ, or an exact path a pattern does not match. Pattern
/// versus pattern is treated as overlapping, and so is a pattern whose regex
/// would not compile.
fn provably_disjoint(
    (left, left_matcher): (&RedirectRule, Option<&Regex>),
    (right, right_matcher): (&RedirectRule, Option<&Regex>),
) -> bool {
    // Different literal hostnames can never both be the request's host. A rule
    // with no host answers every hostname, so it overlaps with all of them.
    if let (Some(left_host), Some(right_host)) = (&left.host, &right.host) {
        if !super::has_host_pattern(left_host)
            && !super::has_host_pattern(right_host)
            && left_host != right_host
        {
            return true;
        }
    }
    match (left.match_kind, right.match_kind) {
        ("exact", "exact") => left.source != right.source,
        ("exact", _) => right_matcher.is_some_and(|regex| !regex.is_match(&left.source)),
        (_, "exact") => left_matcher.is_some_and(|regex| !regex.is_match(&right.source)),
        _ => false,
    }
}

fn redirect_definition(rule: &RedirectRule, host_scope: &Value) -> Value {
    // Host and path, and nothing else: a placed redirect is unconditional by
    // construction (see `redirect_refusal`).
    let conditions = vec![
        host_condition(rule.host.as_deref(), host_scope),
        // The runtime strips trailing slashes off the request path before it
        // compares (`stripTrailingSlash` in packages/routing/src/match.ts,
        // `_stattic_redirect_match_path` in runtime/engine/runtime/rules.php),
        // so an exact source answers both spellings and the edge has to say
        // both out loud.
        path_condition_with_trailing_slash(&rule.source),
    ];
    json!({
        "version": 1,
        "input": {
            "target_url": rule.destination,
            "status_code": rule.status,
            // The runtime appends the visitor's query unless the target
            // already carries one (`appendIncomingQuery`).
            "preserve_query": !rule.destination.contains('?'),
            "condition_logic": "all",
            "conditions": conditions,
        }
    })
}

// ---------------------------------------------------------------------------
// Headers
// ---------------------------------------------------------------------------

/// Header names more than one matching-capable operation touches. The runtime
/// folds those — every matching rule applies in order, and repeats within one
/// rule accumulate into a multi-value header — while the provider replaces, so
/// a folded name has to stay where the folding happens (spec §5).
fn folded_header_names(rules: &[HeaderRule]) -> BTreeSet<String> {
    let mut folded = BTreeSet::new();
    for (index, rule) in rules.iter().enumerate() {
        // Twice inside one rule is already a fold: two `set` operations on one
        // name are what keeps repeated `Set-Cookie` declarations working.
        let mut seen = BTreeSet::new();
        for operation in &rule.operations {
            if !seen.insert(operation.name.to_ascii_lowercase()) {
                folded.insert(operation.name.to_ascii_lowercase());
            }
        }
        for other in &rules[index + 1..] {
            if header_rules_disjoint(rule, other) {
                continue;
            }
            for name in rule.operations.iter().map(|operation| &operation.name) {
                if other
                    .operations
                    .iter()
                    .any(|operation| operation.name.eq_ignore_ascii_case(name))
                {
                    folded.insert(name.to_ascii_lowercase());
                }
            }
        }
    }
    folded
}

/// Two header rules that can never match the same request. Exact matchers with
/// different paths, or different literal hosts; anything with a pattern is
/// treated as overlapping.
fn header_rules_disjoint(left: &HeaderRule, right: &HeaderRule) -> bool {
    if let (Some(left_host), Some(right_host)) = (&left.host, &right.host) {
        if !super::has_host_pattern(left_host)
            && !super::has_host_pattern(right_host)
            && left_host != right_host
        {
            return true;
        }
    }
    is_exact_header_matcher(left) && is_exact_header_matcher(right) && left.path != right.path
}

/// A header matcher compiles to a regex even when it is exact, so "exact" is
/// read back the way the matcher does it (`isExactHeaderRule` in
/// packages/routing/src/match.ts): the regex is the escaped path, nothing else.
fn is_exact_header_matcher(rule: &HeaderRule) -> bool {
    rule.regex
        .as_deref()
        .is_some_and(|regex| regex == format!("^{}$", escape_regex_literal(&rule.path)))
}

/// A header rule moves whole or not at all: the runtime folds a rule's
/// operations together for one request, so placing half of them would change
/// what the other half sees. The first predicate that refuses any operation
/// refuses the rule.
fn header_refusal(
    rule: &HeaderRule,
    folded: &BTreeSet<String>,
    input: &PlacementInput,
) -> Option<&'static str> {
    if !is_exact_header_matcher(rule) || has_variable_marker(&rule.path) {
        return Some("edge_ineligible_capture");
    }
    // Same two broadenings a redirect source is held to: the provider folds
    // case, and a host matcher must name a production hostname.
    if rule.path != rule.path.to_lowercase()
        || rule
            .host
            .as_ref()
            .is_some_and(|host| !input.hostnames.iter().any(|production| production == host))
    {
        return Some("edge_ineligible_condition");
    }
    for operation in &rule.operations {
        // The refusal list is the platform's own headers. The compiler already
        // turns these away at authoring time; placement refuses them again
        // because an overlay rule reaches the merged list without passing it.
        if platform_managed_response_header(&operation.name.to_ascii_lowercase())
            || (operation.kind == "remove" && never_removable_response_header(&operation.name))
        {
            return Some("edge_ineligible_platform_header");
        }
        // A `set` needs a literal value. The runtime expands `:name` in every
        // header value it applies (`expand` in packages/routing/src/match.ts,
        // which writes an empty string for a name the matcher did not
        // capture), so a value carrying one is not the string the edge would
        // send.
        if operation.kind == "set" && !operation.value.as_deref().is_some_and(is_literal_target) {
            return Some("edge_ineligible_capture");
        }
    }
    if rule
        .operations
        .iter()
        .any(|operation| folded.contains(&operation.name.to_ascii_lowercase()))
    {
        return Some("edge_ineligible_header_folds");
    }
    None
}

fn header_definitions(rule: &HeaderRule, host_scope: &Value) -> Vec<Value> {
    let conditions = vec![
        host_condition(rule.host.as_deref(), host_scope),
        // Headers are the one place the runtime does NOT strip the trailing
        // slash: it compares the raw request path
        // (`headersForRequest` in packages/routing/src/match.ts,
        // `_stattic_collect_response_headers` in
        // runtime/engine/runtime/headers.php), so the edge says one path, not
        // two.
        json!({ "field": "http.path", "op": "eq", "value": rule.path }),
    ];
    rule.operations
        .iter()
        .map(|operation| {
            let mut input = Map::new();
            input.insert("operation".into(), json!(operation.kind));
            input.insert("header".into(), json!(operation.name));
            if operation.kind == "set" {
                input.insert(
                    "value".into(),
                    json!(operation.value.clone().unwrap_or_default()),
                );
            }
            input.insert("condition_logic".into(), json!("all"));
            input.insert("conditions".into(), Value::Array(conditions.clone()));
            json!({ "version": 1, "input": Value::Object(input) })
        })
        .collect()
}

// ---------------------------------------------------------------------------
// Conditions
// ---------------------------------------------------------------------------

/// The hostnames a rule is held to: the one it names, or the production set.
fn host_condition(host: Option<&str>, host_scope: &Value) -> Value {
    match host {
        Some(host) => json!({ "field": "http.host", "op": "eq", "value": host }),
        None => host_scope.clone(),
    }
}

/// An exact source, in both spellings the runtime answers it under.
fn path_condition_with_trailing_slash(source: &str) -> Value {
    // `/` is already its own trailing-slash form; `//` is a different path.
    let values = if source == "/" {
        vec![source.to_string()]
    } else {
        vec![source.to_string(), format!("{source}/")]
    };
    json!({ "field": "http.path", "op": "in", "values": values })
}

/// `hostScope` in `packages/common/src/traffic-rules/compile.ts`: the
/// production hostnames a rule that names no host of its own is held to.
fn host_scope_condition(hostnames: &[String]) -> Value {
    let values: BTreeSet<&String> = hostnames.iter().collect();
    json!({
        "field": "http.host",
        "op": "in",
        "values": values.into_iter().collect::<Vec<_>>(),
    })
}
