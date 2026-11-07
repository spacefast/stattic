use crate::model::PhpActionRecord;
use crate::route_inventory::{methods_for, RouteKind, RouteRecord, RouteRecordInput, RouteRuntime};

/// The Zero control routes a version with a Zero runtime always answers, as
/// `(request path, method, operation)`. Inventory, response-table actions and
/// the publisher-collision guard iterate this one list, so no projection can
/// invent a control route or reserve a path the others do not.
///
/// `/__zero/*` is canonical. The `/__spacefast/zero/*` spellings are permanent
/// aliases: frozen capsule clients baked them at build time and a republish
/// cannot be assumed, so both prefixes answer with the same operations.
///
/// No row carries an execution mode, and the two spellings of an operation are
/// therefore identical on that axis by construction. A control route is not a
/// capsule invocation: `config`, `auth/*` and `realtime/events` run no compiled
/// handler at all, and `run` is bimodal — a query run declares `read` and a
/// mutation run declares `write` for that one request, from the mode baked into
/// the run artifact it names. Stamping either mode on the route would be a lie
/// about half its traffic.
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

/// Whether a compiled Zero endpoint would collide with a generated control
/// route, on either spelling.
pub(crate) fn is_zero_control_path(path: &str) -> bool {
    ZERO_CONTROL_ROUTES
        .iter()
        .any(|(control_path, _, _)| *control_path == path)
}

pub(crate) fn append_routes(
    routes: &mut Vec<RouteRecord>,
    has_zero: bool,
    zero_routes: &[PhpActionRecord],
) {
    if has_zero {
        for (path, method, operation) in ZERO_CONTROL_ROUTES {
            routes.push(RouteRecord::from_input(RouteRecordInput {
                kind: RouteKind::ZeroControl,
                source: format!("runtime:{operation}"),
                runtime: RouteRuntime::Php,
                host: "*".to_string(),
                path: (*path).to_string(),
                methods: methods_for(method),
                execution_mode: None,
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
            execution_mode,
            endpoint_id,
            ..
        } = route;
        routes.push(RouteRecord::from_input(RouteRecordInput {
            kind: RouteKind::ZeroEndpoint,
            source: endpoint_id.clone(),
            runtime: RouteRuntime::QuickJs,
            host: "*".to_string(),
            path: pattern.clone(),
            methods: methods_for(method),
            execution_mode: Some(*execution_mode),
            cache: None,
            destination: None,
            status: None,
        }));
    }
}
