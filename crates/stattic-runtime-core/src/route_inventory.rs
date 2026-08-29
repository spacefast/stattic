//! Finalizer-owned inventory of programmable and routing declarations.
//!
//! The response table remains the serving authority. This inventory gives the
//! finalizer one normalized graph for provenance and for contradictions that
//! precedence would otherwise hide.

use serde::Serialize;
use serde_json::{json, Value};
use std::collections::{BTreeMap, BTreeSet};

use crate::finalize::{invalid_with_details, FileMeta, Result};
use crate::hash::stable_json_sha256;
use crate::model::PhpActionRecord;
use crate::responses::php_function_route;
use crate::routing::RedirectRule;
use crate::serving_paths::is_private_serving_path;

pub(crate) const ROUTE_INVENTORY_FORMAT: &str = "stattic.route-inventory.v1";

const ALL_METHODS: &[&str] = &["*"];

/// The Zero control routes a version with a Zero runtime always answers, as
/// `(request path, method, operation)`. Inventory and response-table actions
/// iterate this one list so neither projection can invent a route alone.
///
/// `/__zero/*` is canonical. The `/__spacefast/zero/*` spellings are permanent
/// aliases: frozen capsule clients baked them at build time and a republish
/// cannot be assumed, so both prefixes answer with the same operations.
pub(crate) const ZERO_CONTROL_ROUTES: &[(&str, &str, &str)] = &[
    ("/__zero/config", "GET", "config"),
    ("/__zero/run", "POST", "run"),
    ("/__zero/auth/start", "GET", "auth_start"),
    ("/__zero/auth/sign-out", "GET", "auth_sign_out"),
    ("/__zero/realtime/events", "GET", "realtime_events"),
    ("/__spacefast/zero/config", "GET", "config"),
    ("/__spacefast/zero/run", "POST", "run"),
    ("/__spacefast/zero/auth/gravatar/start", "GET", "auth_start"),
    ("/__spacefast/zero/auth/sign-out", "GET", "auth_sign_out"),
    (
        "/__spacefast/zero/realtime/events",
        "GET",
        "realtime_events",
    ),
];

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "kebab-case")]
enum RouteKind {
    Redirect,
    Rewrite,
    Proxy,
    NotFound,
    Functions,
    PhpFunction,
    ZeroControl,
    ZeroEndpoint,
}

#[derive(Debug, Clone, Copy, Serialize)]
#[serde(rename_all = "kebab-case")]
enum RouteRuntime {
    Routing,
    CloudflareWorker,
    Php,
    QuickJs,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct RouteRecord {
    pub id: String,
    kind: RouteKind,
    /// The committed declaration or generated runtime operation that owns the
    /// record. `kind` says what that source means.
    source: String,
    runtime: RouteRuntime,
    /// A normalized hostname or `*` for every host assigned to the Space.
    host: String,
    path: String,
    methods: Vec<String>,
    /// Access policy is mutable route state, so immutable versions can only
    /// record that the active Space policy is inherited.
    auth: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    cache: Option<&'static str>,
    #[serde(skip_serializing_if = "Option::is_none")]
    destination: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    status: Option<u16>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub(crate) struct RouteInventory {
    pub format: &'static str,
    pub routes: Vec<RouteRecord>,
}

struct RouteRecordInput {
    kind: RouteKind,
    source: String,
    runtime: RouteRuntime,
    host: String,
    path: String,
    methods: Vec<String>,
    cache: Option<&'static str>,
    destination: Option<String>,
    status: Option<u16>,
}

impl RouteRecord {
    fn from_input(input: RouteRecordInput) -> Self {
        let id = stable_json_sha256(&json!({
            "kind": input.kind,
            "source": input.source,
            "runtime": input.runtime,
            "host": input.host,
            "path": input.path,
            "methods": input.methods,
            "auth": "inherit",
            "cache": input.cache,
            "destination": input.destination,
            "status": input.status,
        }));
        Self {
            id,
            kind: input.kind,
            source: input.source,
            runtime: input.runtime,
            host: input.host,
            path: input.path,
            methods: input.methods,
            auth: "inherit",
            cache: input.cache,
            destination: input.destination,
            status: input.status,
        }
    }

    fn owns_exact_path(&self) -> bool {
        matches!(
            self.kind,
            RouteKind::Functions
                | RouteKind::PhpFunction
                | RouteKind::ZeroControl
                | RouteKind::ZeroEndpoint
        ) && !self.path.contains(':')
    }
}

pub(crate) struct RouteInventoryInput<'a> {
    pub files: &'a BTreeMap<String, FileMeta>,
    pub private: &'a BTreeSet<String>,
    pub redirects: &'a [RedirectRule],
    pub config_path: Option<&'a str>,
    pub functions: Option<&'a Value>,
    pub zero_routes: &'a [PhpActionRecord],
    pub has_zero: bool,
    pub assigned_hostnames: &'a [String],
    pub exact_response_paths: &'a BTreeSet<String>,
}

pub(crate) fn compile_route_inventory(input: RouteInventoryInput<'_>) -> Result<RouteInventory> {
    let RouteInventoryInput {
        files,
        private,
        redirects,
        config_path,
        functions,
        zero_routes,
        has_zero,
        assigned_hostnames,
        exact_response_paths,
    } = input;
    let mut routes = Vec::new();
    let mut redirect_route_ids = Vec::with_capacity(redirects.len());

    for rule in redirects {
        let kind = match rule.action {
            "redirect" => RouteKind::Redirect,
            "rewrite" => RouteKind::Rewrite,
            "proxy" => RouteKind::Proxy,
            "notFound" => RouteKind::NotFound,
            _ => {
                redirect_route_ids.push(None);
                continue;
            }
        };
        let record = RouteRecord::from_input(RouteRecordInput {
            kind,
            source: if rule.origin == "config" {
                config_path.unwrap_or("sf.jsonc").to_string()
            } else {
                "_redirects".to_string()
            },
            runtime: RouteRuntime::Routing,
            host: rule.host.clone().unwrap_or_else(|| "*".to_string()),
            path: rule.source.clone(),
            methods: all_methods(),
            cache: rule.cache,
            destination: Some(rule.destination.clone()),
            status: Some(rule.status),
        });
        redirect_route_ids.push(Some(record.id.clone()));
        routes.push(record);
    }

    if let Some(functions) = functions.and_then(Value::as_object) {
        let artifact = functions.get("artifact").and_then(Value::as_object);
        let source = artifact
            .and_then(|artifact| artifact.get("entry"))
            .and_then(Value::as_str)
            .unwrap_or("functions worker");
        for declaration in artifact
            .and_then(|artifact| artifact.get("routes"))
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
        {
            let Some(path) = declaration.get("path").and_then(Value::as_str) else {
                continue;
            };
            let methods = declaration
                .get("method")
                .and_then(Value::as_str)
                .map_or_else(all_methods, methods_for);
            push_functions_route(&mut routes, source, path, methods.clone());
            if declaration
                .get("subtree")
                .and_then(Value::as_bool)
                .unwrap_or(false)
            {
                let pattern = if path == "/" {
                    "/:splat".to_string()
                } else {
                    format!("{}/:splat", path.trim_end_matches('/'))
                };
                push_functions_route(&mut routes, source, &pattern, methods);
            }
        }
    }

    for path in files.keys() {
        if private.contains(path) || is_private_serving_path(path) {
            continue;
        }
        let Some(route_path) = php_function_route(path) else {
            continue;
        };
        routes.push(RouteRecord::from_input(RouteRecordInput {
            kind: RouteKind::PhpFunction,
            source: path.clone(),
            runtime: RouteRuntime::Php,
            host: "*".to_string(),
            path: route_path,
            methods: all_methods(),
            cache: None,
            destination: None,
            status: None,
        }));
    }

    if has_zero {
        for (path, method, operation) in ZERO_CONTROL_ROUTES {
            routes.push(RouteRecord::from_input(RouteRecordInput {
                kind: RouteKind::ZeroControl,
                source: format!("runtime:{operation}"),
                runtime: RouteRuntime::Php,
                host: "*".to_string(),
                path: (*path).to_string(),
                methods: methods_for(method),
                cache: None,
                destination: None,
                status: None,
            }));
        }
    }

    for route in zero_routes {
        let PhpActionRecord::InvokeZero {
            pattern,
            method,
            endpoint_id,
            ..
        } = route
        else {
            continue;
        };
        routes.push(RouteRecord::from_input(RouteRecordInput {
            kind: RouteKind::ZeroEndpoint,
            source: endpoint_id.clone(),
            runtime: RouteRuntime::QuickJs,
            host: "*".to_string(),
            path: pattern.clone(),
            methods: methods_for(method),
            cache: None,
            destination: None,
            status: None,
        }));
    }

    disambiguate_duplicate_ids(&mut routes);
    // Duplicate disambiguation can rewrite a redirect id. Refresh the aligned
    // list from the still-leading redirect records before graph diagnostics use
    // it; serving order remains the compiler's original order.
    let mut route_records = routes.iter();
    for route_id in &mut redirect_route_ids {
        if route_id.is_some() {
            *route_id = route_records.next().map(|record| record.id.clone());
        }
    }
    validate_exact_ownership(&routes)?;
    validate_redirect_cycles(
        redirects,
        &redirect_route_ids,
        assigned_hostnames,
        exact_response_paths,
    )?;

    Ok(RouteInventory {
        format: ROUTE_INVENTORY_FORMAT,
        routes,
    })
}

fn methods_for(method: &str) -> Vec<String> {
    if method == "GET" {
        vec!["GET".to_string(), "HEAD".to_string()]
    } else {
        vec![method.to_string()]
    }
}

fn all_methods() -> Vec<String> {
    ALL_METHODS
        .iter()
        .map(|method| (*method).to_string())
        .collect()
}

fn push_functions_route(
    routes: &mut Vec<RouteRecord>,
    source: &str,
    path: &str,
    methods: Vec<String>,
) {
    routes.push(RouteRecord::from_input(RouteRecordInput {
        kind: RouteKind::Functions,
        source: source.to_string(),
        runtime: RouteRuntime::CloudflareWorker,
        host: "*".to_string(),
        path: path.to_string(),
        methods,
        cache: None,
        destination: None,
        status: None,
    }));
}

fn methods_overlap(left: &[String], right: &[String]) -> bool {
    left.iter().any(|method| method == "*")
        || right.iter().any(|method| method == "*")
        || left.iter().any(|method| right.contains(method))
}

fn disambiguate_duplicate_ids(routes: &mut [RouteRecord]) {
    let mut occurrences = BTreeMap::<String, usize>::new();
    for route in routes {
        let occurrence = occurrences.entry(route.id.clone()).or_default();
        if *occurrence > 0 {
            route.id = stable_json_sha256(&json!({
                "route": route.id,
                "occurrence": *occurrence,
            }));
        }
        *occurrence += 1;
    }
}

fn validate_exact_ownership(routes: &[RouteRecord]) -> Result<()> {
    let mut owners = BTreeMap::<(String, String), Vec<&RouteRecord>>::new();
    let mut collisions = Vec::new();
    for route in routes.iter().filter(|route| route.owns_exact_path()) {
        let key = (route.host.clone(), route.path.clone());
        let same_path = owners.entry(key).or_default();
        for prior in same_path.iter().copied() {
            if (prior.kind == route.kind && prior.source == route.source)
                || !methods_overlap(&prior.methods, &route.methods)
            {
                continue;
            }
            collisions.push(json!({
                "host": route.host,
                "path": route.path,
                "left": {"id": prior.id, "kind": prior.kind, "source": prior.source, "methods": prior.methods},
                "right": {"id": route.id, "kind": route.kind, "source": route.source, "methods": route.methods},
            }));
        }
        same_path.push(route);
    }
    if collisions.is_empty() {
        return Ok(());
    }
    invalid_with_details(
        "route_ownership_conflict",
        "Multiple programmable routes own the same exact host, path, and method.",
        json!({"collisions": collisions}),
    )
}

#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord)]
struct RouteNode {
    host: String,
    path: String,
}

struct RedirectEdge {
    target: RouteNode,
    route_id: String,
}

fn validate_redirect_cycles(
    redirects: &[RedirectRule],
    redirect_route_ids: &[Option<String>],
    assigned_hostnames: &[String],
    exact_response_paths: &BTreeSet<String>,
) -> Result<()> {
    let host_universe = redirect_host_universe(redirects, assigned_hostnames);
    let mut candidates = BTreeSet::<RouteNode>::new();
    for rule in redirects.iter().filter(|rule| {
        rule.action == "redirect"
            && rule.match_kind == "exact"
            && rule.conditions.is_empty()
            && rule.query.is_none()
    }) {
        for host in concrete_rule_hosts(rule, &host_universe) {
            candidates.insert(RouteNode {
                host,
                path: normalize_route_path(&rule.source),
            });
        }
    }

    let mut edges = BTreeMap::<RouteNode, RedirectEdge>::new();
    for source in candidates {
        if let Some(edge) =
            effective_redirect_edge(&source, redirects, redirect_route_ids, exact_response_paths)
        {
            edges.insert(source, edge);
        }
    }

    let mut seen_cycles = BTreeSet::new();
    let mut cycles = Vec::new();
    for start in edges.keys() {
        let mut positions = BTreeMap::<RouteNode, usize>::new();
        let mut traversed = Vec::<(RouteNode, String)>::new();
        let mut current = start.clone();
        while let Some(edge) = edges.get(&current) {
            if let Some(position) = positions.get(&current).copied() {
                let cycle = &traversed[position..];
                if cycle.len() > 1 {
                    let mut signature: Vec<String> =
                        cycle.iter().map(|(_, route_id)| route_id.clone()).collect();
                    signature.sort();
                    if seen_cycles.insert(signature.join("\0")) {
                        let nodes = cycle
                            .iter()
                            .map(|(node, _)| json!({"host": node.host, "path": node.path}))
                            .collect::<Vec<_>>();
                        let same_host = cycle.iter().all(|(node, _)| node.host == current.host);
                        cycles.push(json!({
                            "host": same_host.then_some(current.host.clone()),
                            "paths": cycle.iter().map(|(node, _)| node.path.clone()).collect::<Vec<_>>(),
                            "nodes": nodes,
                            "routeIds": cycle.iter().map(|(_, route_id)| route_id.clone()).collect::<Vec<_>>(),
                        }));
                    }
                }
                break;
            }
            positions.insert(current.clone(), traversed.len());
            traversed.push((current, edge.route_id.clone()));
            current = edge.target.clone();
        }
    }
    if cycles.is_empty() {
        return Ok(());
    }
    invalid_with_details(
        "redirect_cycle_invalid",
        "Unconditional exact redirects must not form a multi-hop cycle.",
        json!({"cycles": cycles}),
    )
}

fn redirect_host_universe(
    redirects: &[RedirectRule],
    assigned_hostnames: &[String],
) -> BTreeSet<String> {
    assigned_hostnames
        .iter()
        .map(|host| normalize_route_host(host))
        .chain(redirects.iter().filter_map(|rule| {
            rule.host
                .as_deref()
                .filter(|host| !host.contains('*') && !host.contains(':'))
                .map(normalize_route_host)
        }))
        .filter(|host| !host.is_empty())
        .collect()
}

fn concrete_rule_hosts(rule: &RedirectRule, universe: &BTreeSet<String>) -> Vec<String> {
    if rule.host.is_none() {
        return if universe.is_empty() {
            vec!["*".to_string()]
        } else {
            universe.iter().cloned().collect()
        };
    }
    universe
        .iter()
        .filter(|host| redirect_rule_matches_host(rule, host))
        .cloned()
        .collect()
}

fn effective_redirect_edge(
    source: &RouteNode,
    redirects: &[RedirectRule],
    redirect_route_ids: &[Option<String>],
    exact_response_paths: &BTreeSet<String>,
) -> Option<RedirectEdge> {
    for (index, rule) in redirects.iter().enumerate() {
        if !rule.conditions.is_empty() || rule.query.is_some() {
            continue;
        }
        let Some(captures) = redirect_rule_captures(rule, source) else {
            continue;
        };
        match rule.action {
            "redirect" => {
                let destination = expand_redirect_destination(&rule.destination, &captures);
                return Some(RedirectEdge {
                    target: redirect_target(source, &destination)?,
                    route_id: redirect_route_ids.get(index)?.clone()?,
                });
            }
            "proxy" => return None,
            "rewrite" | "notFound"
                if rule.force || !exact_response_paths.contains(&source.path) =>
            {
                return None
            }
            "rewrite" | "notFound" => {}
            _ => {}
        }
    }
    None
}

fn redirect_rule_captures(
    rule: &RedirectRule,
    source: &RouteNode,
) -> Option<BTreeMap<String, String>> {
    if !redirect_rule_matches_host(rule, &source.host) {
        return None;
    }
    let Some(regex) = rule.regex.as_deref() else {
        return (normalize_route_path(&rule.source) == source.path).then(BTreeMap::new);
    };
    let regex = regex::Regex::new(regex).ok()?;
    let captures = regex.captures(&source.path)?;
    Some(
        regex
            .capture_names()
            .flatten()
            .filter_map(|name| {
                captures
                    .name(name)
                    .map(|value| (name.to_string(), value.as_str().to_string()))
            })
            .collect(),
    )
}

fn redirect_rule_matches_host(rule: &RedirectRule, host: &str) -> bool {
    let Some(regex) = rule.host_regex.as_deref() else {
        return rule.host.is_none();
    };
    host != "*" && regex::Regex::new(regex).is_ok_and(|regex| regex.is_match(host))
}

fn expand_redirect_destination(destination: &str, captures: &BTreeMap<String, String>) -> String {
    static PLACEHOLDER: std::sync::LazyLock<regex::Regex> =
        std::sync::LazyLock::new(|| regex::Regex::new(r":([A-Za-z][A-Za-z0-9_]*)").unwrap());
    PLACEHOLDER
        .replace_all(destination, |matched: &regex::Captures<'_>| {
            captures.get(&matched[1]).map(String::as_str).unwrap_or("")
        })
        .into_owned()
}

fn redirect_target(source: &RouteNode, destination: &str) -> Option<RouteNode> {
    if destination.starts_with('/') && !destination.starts_with("//") {
        let path = destination
            .split(['?', '#'])
            .next()
            .filter(|path| !path.is_empty())?;
        return Some(RouteNode {
            host: source.host.clone(),
            path: normalize_route_path(path),
        });
    }
    let parsed = url::Url::parse(destination).ok()?;
    Some(RouteNode {
        host: normalize_route_host(parsed.host_str()?),
        path: normalize_route_path(parsed.path()),
    })
}

fn normalize_route_host(host: &str) -> String {
    host.trim().trim_end_matches('.').to_ascii_lowercase()
}

fn normalize_route_path(path: &str) -> String {
    if path == "/" {
        "/".to_string()
    } else {
        path.trim_end_matches('/').to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn function_record() -> RouteRecord {
        RouteRecord::from_input(RouteRecordInput {
            kind: RouteKind::PhpFunction,
            source: "functions/ping.php".to_string(),
            runtime: RouteRuntime::Php,
            host: "*".to_string(),
            path: "/ping".to_string(),
            methods: vec!["*".to_string()],
            cache: None,
            destination: None,
            status: None,
        })
    }

    #[test]
    fn route_ids_are_deterministic_and_duplicate_safe() {
        let first = function_record();
        let same = function_record();
        assert_eq!(first.id, same.id);

        let original_id = first.id.clone();
        let mut duplicates = vec![first, same];
        disambiguate_duplicate_ids(&mut duplicates);
        assert_eq!(duplicates[0].id, original_id);
        assert_ne!(duplicates[0].id, duplicates[1].id);
    }

    #[test]
    fn functions_subtrees_inventory_the_exact_and_pattern_routes() {
        let functions = json!({
            "artifact": {
                "entry": "worker.ts",
                "routes": [{"method": "GET", "path": "/app", "subtree": true}]
            }
        });
        let inventory = compile_route_inventory(RouteInventoryInput {
            files: &BTreeMap::new(),
            private: &BTreeSet::new(),
            redirects: &[],
            config_path: None,
            functions: Some(&functions),
            zero_routes: &[],
            has_zero: false,
            assigned_hostnames: &[],
            exact_response_paths: &BTreeSet::new(),
        })
        .unwrap();
        assert_eq!(inventory.routes.len(), 2);
        assert_eq!(inventory.routes[0].path, "/app");
        assert_eq!(inventory.routes[1].path, "/app/:splat");
        assert_eq!(inventory.routes[0].methods, ["GET", "HEAD"]);
        assert!(inventory
            .routes
            .iter()
            .all(|route| matches!(route.runtime, RouteRuntime::CloudflareWorker)));
    }
}
