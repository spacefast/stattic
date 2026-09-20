//! Placement, one case per verdict.
//!
//! The rules are built by compiling the grammar a publisher actually writes,
//! so a case says what someone could ship rather than what a struct literal
//! could hold. The one rule no grammar can express — a platform-managed header
//! name — is built directly, because the compiler refuses it at authoring time
//! and only an overlay can put it in front of placement.

use std::collections::{BTreeMap, BTreeSet};

use serde_json::{json, Value};

use super::placement::{
    canonical_json, place, placement_report, provider_rule_name, EdgeRuleSpec, PlacementInput,
};
use super::{
    compile_headers, compile_redirects, HeaderOperation, HeaderRule, MergedRouting, RoutingInput,
};

/// The Space's production hostnames: what `PlacementInput.hostnames` means.
const PRODUCTION: [&str; 2] = ["example.com", "www.example.com"];

fn production() -> Vec<String> {
    PRODUCTION.iter().map(|host| (*host).to_string()).collect()
}

fn owned(values: &[&str]) -> Vec<String> {
    values.iter().map(|value| (*value).to_string()).collect()
}

/// The merged routing a `_redirects` / `_headers` pair compiles to, with no
/// diagnostics: a case that cannot compile is a case about the compiler, not
/// about placement. `assigned` is the compiler's hostname set, which is a
/// superset of the production set placement is given.
fn merged_for(assigned: &[String], redirects: &str, headers: &str) -> MergedRouting {
    let mut diagnostics = Vec::new();
    let input = RoutingInput {
        redirects: redirects.into(),
        headers: headers.into(),
        assigned_hostnames: assigned.to_vec(),
        ..RoutingInput::default()
    };
    let redirects = compile_redirects(&input, &mut diagnostics);
    let headers = compile_headers(&input.headers, &mut diagnostics);
    assert!(
        diagnostics.is_empty(),
        "the fixture should compile cleanly: {:?}",
        diagnostics
            .iter()
            .map(|entry| entry.code)
            .collect::<Vec<_>>()
    );
    MergedRouting {
        redirects,
        headers,
        diagnostics: Vec::new(),
    }
}

fn merged(redirects: &str, headers: &str) -> MergedRouting {
    merged_for(&production(), redirects, headers)
}

fn manifest(paths: &[&str]) -> BTreeSet<String> {
    paths.iter().map(|path| (*path).to_string()).collect()
}

fn placed(routing: &mut MergedRouting, manifest_paths: &BTreeSet<String>) -> Vec<EdgeRuleSpec> {
    let hostnames = production();
    place(
        routing,
        &PlacementInput {
            manifest_paths,
            hostnames: &hostnames,
            enabled: true,
        },
    )
}

/// Every rule's verdict as `at:reason`, redirects then headers.
fn verdicts(routing: &MergedRouting) -> Vec<String> {
    routing
        .redirects
        .iter()
        .map(|rule| rule.placement.as_ref())
        .chain(routing.headers.iter().map(|rule| rule.placement.as_ref()))
        .map(|placement| match placement {
            None => "unplaced".to_string(),
            Some(placement) => match placement.reason {
                None => placement.at.to_string(),
                Some(reason) => format!("{}:{reason}", placement.at),
            },
        })
        .collect()
}

fn verdict(redirects: &str, headers: &str, manifest_paths: &[&str]) -> Vec<String> {
    let mut routing = merged(redirects, headers);
    placed(&mut routing, &manifest(manifest_paths));
    verdicts(&routing)
}

#[test]
fn reports_why_each_rule_stays_at_the_origin() {
    // One row per predicate, each asserting the whole verdict vector. Every
    // rule in a row is otherwise edge-expressible, so a predicate that stopped
    // refusing would move exactly that row's rule and nothing else.
    let cases: [(&str, &str, &[&str], &[&str]); 20] = [
        // (_redirects, _headers, manifest, expected verdicts)
        ("/old /new 303", "", &[], &["origin:edge_ineligible_status"]),
        (
            "/post/:slug /article/:slug 301",
            "",
            &[],
            &["origin:edge_ineligible_capture"],
        ),
        // A trailing `*` source: the runtime's splat does not match the bare
        // `/docs`, the provider's wildcard glob does.
        (
            "/docs/* /guide 301",
            "",
            &[],
            &["origin:edge_ineligible_capture"],
        ),
        // The runtime appends the visitor's query before the fragment; the
        // provider appends to the end of the target.
        (
            "/old /new#top 301",
            "",
            &[],
            &["origin:edge_ineligible_capture"],
        ),
        (
            "/home /intl 302 Language=fr",
            "",
            &[],
            &["origin:edge_ineligible_condition"],
        ),
        // `Country=` is the ORIGIN reading an `nf_country` request cookie a CDN
        // wrote, which a visitor can forge; the provider's `geo.country` is its
        // own lookup. They disagree for exactly the requests the rule is about.
        (
            "/old /de 302 Country=DE",
            "",
            &[],
            &["origin:edge_ineligible_condition"],
        ),
        // The origin looks a cookie name up case-folded; the provider compares
        // the key as written.
        (
            "/old /new 302 Cookie=Session",
            "",
            &[],
            &["origin:edge_ineligible_condition"],
        ),
        // An off-origin target answers without the origin ever being asked, and
        // the origin is what strips the durable Link token from such a target —
        // the edge would forward it.
        (
            "/old https://elsewhere.example/new 301",
            "",
            &[],
            &["origin:edge_ineligible_destination"],
        ),
        // `http.path eq` folds case at the provider; the runtime does not.
        (
            "/Docs /docs-v2 301",
            "",
            &[],
            &["origin:edge_ineligible_condition"],
        ),
        // A query match means "these parameters and no others", which no
        // condition list can state.
        (
            "/store id=:id /shop 301",
            "",
            &[],
            &["origin:edge_ineligible_condition"],
        ),
        // An unforced rule whose source a published file satisfies: the
        // runtime serves the file, so the edge must not answer first. Forced,
        // the same rule answers regardless, which is what the edge promises.
        (
            "/about /about-v2 301",
            "",
            &["about.html"],
            &["origin:edge_ineligible_shadowed"],
        ),
        ("/about /about-v2 301!", "", &["about.html"], &["edge"]),
        // The pattern ahead is not placed and it matches the exact path below
        // it, so the exact rule cannot be lifted out of the list either.
        (
            "/docs/* /guide 301\n/docs/intro /guide/intro 301",
            "",
            &[],
            &[
                "origin:edge_ineligible_capture",
                "origin:edge_ineligible_order",
            ],
        ),
        (
            "/api/* https://upstream.example/api 200",
            "",
            &[],
            &["origin:edge_ineligible_proxy"],
        ),
        (
            "/gone /404.html 404",
            "",
            &[],
            &["origin:edge_ineligible_not_found"],
        ),
        // A rewrite is terminal in the runtime; at the edge the rewritten path
        // re-enters the origin's rule table.
        (
            "/app /shell.html 200!",
            "",
            &[],
            &["origin:edge_ineligible_reentry"],
        ),
        // A whole-host redirect. The provider's target is a fixed string, so
        // the path cannot cross with it.
        (
            "https://www.example.com/* https://example.com/moved 301",
            "",
            &[],
            &["origin:edge_ineligible_path_preservation"],
        ),
        // Two rules set one name on the same path; the runtime folds them into
        // a multi-value header and the provider would replace.
        (
            "",
            "/docs/guide\n  X-Trace: outer\n/docs/guide\n  X-Trace: inner",
            &[],
            &[
                "origin:edge_ineligible_header_folds",
                "origin:edge_ineligible_header_folds",
            ],
        ),
        // A header value carrying `:name` is not the string the edge sends:
        // the runtime expands it, writing an empty string for a name the
        // matcher never captured.
        (
            "",
            "/docs/guide\n  X-Trace: run:id",
            &[],
            &["origin:edge_ineligible_capture"],
        ),
        // Eleven rules the edge could otherwise carry, against a ten-row
        // budget. The budget is asked last, so the eleventh reports the quota
        // rather than a predicate it satisfied.
        (
            "/r1 /t1 301\n/r2 /t2 301\n/r3 /t3 301\n/r4 /t4 301\n/r5 /t5 301\n\
             /r6 /t6 301\n/r7 /t7 301\n/r8 /t8 301\n/r9 /t9 301\n/r10 /t10 301\n\
             /r11 /t11 301",
            "",
            &[],
            &[
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "edge",
                "origin:edge_ineligible_quota",
            ],
        ),
    ];

    for (redirects, headers, manifest_paths, expected) in cases {
        assert_eq!(
            verdict(redirects, headers, manifest_paths),
            expected,
            "for {redirects:?} / {headers:?}"
        );
    }
}

#[test]
fn refuses_a_host_matcher_outside_the_production_hostnames() {
    // `preview.example.com` is assigned to the Space, so the compiler accepts
    // the rule — but it serves other content and keeps the full runtime
    // ruleset, so nothing is placed onto it.
    let assigned = owned(&["example.com", "www.example.com", "preview.example.com"]);
    let mut routing = merged_for(
        &assigned,
        "https://preview.example.com/old /new 301\nhttps://www.example.com/old /new 301",
        "",
    );
    let specs = placed(&mut routing, &manifest(&[]));

    assert_eq!(
        verdicts(&routing),
        ["origin:edge_ineligible_condition", "edge"]
    );
    assert_eq!(
        specs[0].definition["input"]["conditions"][0],
        json!({ "field": "http.host", "op": "eq", "value": "www.example.com" })
    );
}

#[test]
fn refuses_a_platform_managed_header_name_an_overlay_smuggled_in() {
    // `check_header_name` turns this away at authoring time, so no grammar can
    // produce it — but an overlay rule reaches the merged list without passing
    // that check, and placement is the last thing between it and the provider.
    let mut routing = MergedRouting {
        redirects: Vec::new(),
        headers: vec![HeaderRule {
            path: "/secret".into(),
            host: None,
            regex: Some("^/secret$".into()),
            host_regex: None,
            operations: vec![HeaderOperation {
                kind: "set",
                name: "Set-Cookie".into(),
                value: Some("session=1".into()),
                line: None,
                source: None,
            }],
            headers: BTreeMap::new(),
            origin: "overlay",
            placement: None,
        }],
        diagnostics: Vec::new(),
    };
    let specs = placed(&mut routing, &manifest(&[]));

    assert_eq!(
        verdicts(&routing),
        ["origin:edge_ineligible_platform_header"]
    );
    assert!(specs.is_empty());
}

#[test]
fn places_one_representative_of_each_kind_at_the_edge() {
    let mut routing = merged(
        "/old /new 301",
        "/assets/app.css\n  X-Robots-Tag: noindex\n  !X-Powered-By",
    );
    let specs = placed(&mut routing, &manifest(&["index.html"]));

    assert_eq!(verdicts(&routing), ["edge", "edge"]);
    assert_eq!(
        specs
            .iter()
            .map(|spec| (
                spec.kind,
                spec.rule_key,
                spec.rule_order,
                spec.source.clone()
            ))
            .collect::<Vec<_>>(),
        [
            ("redirect", "managed.redirect", 0, "file".to_string()),
            // One provider rule per operation, both at the header rule's own
            // order in the merged list.
            ("header", "managed.response_header", 0, "file".to_string()),
            ("header", "managed.response_header", 0, "file".to_string()),
        ]
    );

    // The redirect is the shared identity fixture: the same definition
    // packages/routing/src/traffic-rules-contract.test.ts names through
    // `providerRuleName`, asserted here against what placement actually
    // produced rather than against a copy of it.
    let fixture = provider_rule_name_fixture();
    assert_eq!(specs[0].definition, fixture["definition"]);
    assert_eq!(specs[0].rule_name, fixture["ruleName"].as_str().unwrap());

    // A header rule is the one place the runtime compares the raw request
    // path, so it states one path rather than both spellings.
    assert_eq!(
        specs[2].definition["input"],
        json!({
            "operation": "remove",
            "header": "X-Powered-By",
            "condition_logic": "all",
            "conditions": [
                { "field": "http.host", "op": "in", "values": ["example.com", "www.example.com"] },
                { "field": "http.path", "op": "eq", "value": "/assets/app.css" },
            ],
        })
    );
}

#[test]
fn keeps_a_later_exact_rule_behind_an_earlier_rule_that_stays() {
    // The rule ahead reads `Accept-Language`, which the edge cannot, so it
    // stays in the runtime and answers `/docs/intro` first. A sibling it does
    // not match is provably disjoint and still moves.
    let mut routing = merged(
        "/docs/intro /guide 301 Language=fr\n/docs/intro /guide/intro 301\n/pricing /plans 301",
        "",
    );
    placed(&mut routing, &manifest(&[]));

    assert_eq!(
        verdicts(&routing),
        [
            "origin:edge_ineligible_condition",
            "origin:edge_ineligible_order",
            "edge",
        ]
    );
}

#[test]
fn reads_a_manifest_entry_under_every_path_that_reaches_it() {
    // `/docs` is served by `docs/index.html` and `/about` by `about.html`, so
    // an unforced rule at either source is shadowed even though neither path
    // is a manifest key.
    assert_eq!(
        verdict(
            "/docs /docs-v2 301\n/about /about-v2 301",
            "",
            &["docs/index.html", "about.html"],
        ),
        [
            "origin:edge_ineligible_shadowed",
            "origin:edge_ineligible_shadowed",
        ]
    );
}

#[test]
fn places_nothing_while_the_flag_is_off() {
    let mut routing = merged("/old /new 301", "/assets/app.css\n  X-Trace: yes");
    let hostnames = production();
    let specs = place(
        &mut routing,
        &PlacementInput {
            manifest_paths: &manifest(&[]),
            hostnames: &hostnames,
            enabled: false,
        },
    );

    assert!(specs.is_empty());
    // No verdict at all, not an origin verdict: the artifact a disabled build
    // writes is the one it wrote before placement existed.
    assert_eq!(verdicts(&routing), ["unplaced", "unplaced"]);
    // The REPORT still names both rules, because it is the only record of them
    // the control plane gets — a Space with placement off must still be able to
    // list its own redirects and headers.
    assert_eq!(
        placement_report(&routing, &specs)
            .iter()
            .map(|entry| (entry.kind, entry.source.clone(), entry.at, entry.reason))
            .collect::<Vec<_>>(),
        [
            (
                "redirect",
                "/old".to_string(),
                "origin",
                Some("placement_off")
            ),
            (
                "header",
                "/assets/app.css".to_string(),
                "origin",
                Some("placement_off")
            ),
        ]
    );
}

/// What the report says a rule IS, past where it runs.
///
/// These fields are the whole of what a reader downstream knows about a rule
/// it offers to copy into the Space's own list, and each is a property the
/// copy would otherwise drop. A rewrite, a proxy and a custom 404 all live in
/// the redirect list and none of them redirects — copying one as a 302 would
/// shadow it with a browser redirect — so they report their own kind. A forced
/// rule copied unforced yields to a published file instead of answering over
/// it; a query match or a `Country=` condition copied away answers requests
/// the original never claimed.
#[test]
fn the_report_carries_what_a_copy_of_the_rule_would_need() {
    let mut routing = merged(
        &[
            "/old /new 301!",
            "/search id=:id /post/:id 301",
            "/nl /dutch 302 Country=nl",
            "/app/* /app/index.html 200",
            "/api/* https://api.example.com/:splat 200",
            "/gone /404.html 404",
        ]
        .join("\n"),
        "/assets/app.css\n  X-Trace: yes",
    );
    let specs = placed(&mut routing, &manifest(&[]));

    assert_eq!(
        placement_report(&routing, &specs)
            .iter()
            .map(|entry| (
                entry.kind,
                entry.source.as_str(),
                entry.force,
                entry.query.clone(),
                entry.conditional,
            ))
            .collect::<Vec<_>>(),
        [
            ("redirect", "/old", Some(true), None, None),
            (
                "redirect",
                "/search",
                Some(false),
                Some(BTreeMap::from([("id".to_string(), "id".to_string())])),
                None,
            ),
            ("redirect", "/nl", Some(false), None, Some(true)),
            ("rewrite", "/app/*", Some(false), None, None),
            ("rewrite", "/api/*", Some(false), None, None),
            ("rewrite", "/gone", Some(false), None, None),
            // A header rule can be none of the three, so it says nothing.
            ("header", "/assets/app.css", None, None, None),
        ]
    );
}

#[test]
fn places_nothing_for_a_space_with_no_production_hostname() {
    let mut routing = merged("/old /new 301", "");
    let specs = place(
        &mut routing,
        &PlacementInput {
            manifest_paths: &manifest(&[]),
            hostnames: &[],
            enabled: true,
        },
    );

    assert!(specs.is_empty());
    assert_eq!(verdicts(&routing), ["unplaced"]);
}

#[test]
fn names_a_provider_rule_the_way_the_typescript_lane_does() {
    // The encoding itself, pinned: canonical JSON and the digest that names a
    // provider row. packages/routing/src/traffic-rules-contract.test.ts
    // replays the same file through providerRuleName, so the two lanes cannot
    // drift on how a rule is addressed.
    let fixture = provider_rule_name_fixture();
    let definition = &fixture["definition"];

    assert_eq!(
        canonical_json(definition),
        fixture["canonicalJson"].as_str().unwrap()
    );
    assert_eq!(
        provider_rule_name(fixture["prefix"].as_str().unwrap(), definition),
        fixture["ruleName"].as_str().unwrap()
    );
}

fn provider_rule_name_fixture() -> Value {
    serde_json::from_str(include_str!(
        "../../../../packages/routing/fixtures/provider-rule-name.json"
    ))
    .expect("the provider-rule-name fixture must be valid JSON")
}
