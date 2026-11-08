//! 0.8 `endpoints` become v2 `routes` at the same method and path.
//!
//! The request is rewritten call by call (`await req.text()` → `req.body`) in the handler pass, and
//! every return passes through `__z08EndpointValue`, which applies 0.8's result rules: a
//! `json`/`text`/`empty`/`redirect` response keeps its status and headers, `null` answers 204, a
//! scalar answers text and anything else JSON. Anything else is refused with a pointer.
use super::super::{Failure, fail, pointer};
use crate::diag::Span;
use crate::ir::{FnBody, Func, Pat, Stmt};

/// First path segments WordPress or Zero owns; v2 refuses routes under them (#129).
const RESERVED: &[&str] = &[
    "wp-admin",
    "wp-json",
    "wp-content",
    "wp-includes",
    "wp-login.php",
    "wp-signup.php",
    "wp-cron.php",
    "xmlrpc.php",
    "wp-sitemap.xml",
    "feed",
    "comments",
    "search",
    "author",
    "category",
    "tag",
    "page",
    "embed",
    "trackback",
    "robots.txt",
    "favicon.ico",
    "zero-admin",
    "auth",
    ".well-known",
    "__spacefast",
    "__zero",
    "identity",
    "index.php",
    "wp-config.php",
];

pub(super) struct Route {
    pub method: String,
    pub path: String,
    pub writes: bool,
    /// For a `:splat` route, the bare prefix 0.8 also answered (with an empty splat).
    pub prefix: Option<String>,
}

/// Checks the literal route options; returns the v2 method, path and write mode.
pub(super) fn route(method: &str, path: &str, read_only: Option<bool>, at: &str) -> Result<Route, Failure> {
    let method = method.to_ascii_uppercase();
    if !["GET", "POST", "PUT", "PATCH", "DELETE"].contains(&method.as_str()) {
        return Err(fail(
            &pointer(at, "method"),
            format!("{method} endpoints have no v2 route; GET also answers HEAD, other methods need a manual port"),
        ));
    }
    let segments: Vec<&str> = path.trim_matches('/').split('/').filter(|s| !s.is_empty()).collect();
    if !path.starts_with('/') || segments.is_empty() {
        return Err(fail(
            &pointer(at, "path"),
            "the site root needs a TSX page, not a route",
        ));
    }
    let ident = |n: &str| {
        n.chars().next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
            && n.chars().all(|c| c.is_ascii_alphanumeric() || c == '_')
    };
    let mut v2 = Vec::with_capacity(segments.len());
    for (i, s) in segments.iter().enumerate() {
        // 0.8's catch-all is a final `:splat`, which takes the rest of the path as `params.splat`.
        // It also matches no segment at all (`/files` for `/files/:splat`). v2's `*splat` takes one
        // or more, so the bare prefix needs its own route.
        if *s == ":splat" {
            if i + 1 != segments.len() || i == 0 {
                return Err(fail(
                    &pointer(at, "path"),
                    "a :splat must be the last segment and not the first; a catch-all at the root needs a manual port",
                ));
            }
            v2.push("*splat".to_string());
            continue;
        }
        let ok = if let Some(name) = s.strip_prefix(':') {
            ident(name)
        } else {
            s.chars().all(|c| c.is_ascii_alphanumeric() || "._~-".contains(c))
        };
        if !ok {
            return Err(fail(
                &pointer(at, "path"),
                format!("segment {s:?} has no v2 route form; encoded and wildcard segments need a manual port"),
            ));
        }
        v2.push((*s).to_string());
    }
    if RESERVED.contains(&segments[0].to_ascii_lowercase().as_str()) {
        return Err(fail(
            &pointer(at, "path"),
            format!(
                "/{} belongs to WordPress or Zero on v2; move the endpoint and its callers",
                segments[0]
            ),
        ));
    }
    // 0.8 derived the mode the same way: readOnly wins, otherwise GET and HEAD read.
    let writes = match read_only {
        Some(r) => !r,
        None => method != "GET",
    };
    if writes && method == "GET" {
        return Err(fail(
            at,
            "a writing GET endpoint needs a manual port: v2 authenticates GET routes by cookie without a nonce, so a cross-site link could write as the visitor",
        ));
    }
    let prefix =
        (v2.last().map(String::as_str) == Some("*splat")).then(|| format!("/{}", v2[..v2.len() - 1].join("/")));
    Ok(Route {
        method,
        path: format!("/{}", v2.join("/")),
        writes,
        prefix,
    })
}

/// The handler's request parameter name, if any; destructuring is refused.
pub(super) fn request_name<'a>(f: &'a Func, at: &str) -> Result<Option<&'a str>, Failure> {
    match f.params.get(1) {
        None => Ok(None),
        Some(p) => match &p.pat {
            Pat::Id(n, _) if !p.rest && p.default.is_none() => Ok(Some(n.as_str())),
            _ => Err(fail(
                &pointer(at, "request"),
                "destructured request parameters need a manual port",
            )),
        },
    }
}

/// The handler's own returns (not nested functions'): each `return <e>`'s value span, each bare
/// `return`'s statement span, and an expression body.
pub(super) fn returns(f: &Func) -> Vec<Span> {
    fn walk(stmts: &[Stmt], out: &mut Vec<Span>) {
        for s in stmts {
            match s {
                Stmt::Return(Some(e), _) => out.push(e.span),
                Stmt::Return(None, span) => out.push(*span),
                Stmt::If(_, a, b) => {
                    walk(a, out);
                    if let Some(b) = b {
                        walk(b, out);
                    }
                }
                Stmt::Block(ss) | Stmt::While(_, ss) | Stmt::DoWhile(ss, _) => walk(ss, out),
                Stmt::Labeled(_, s, _) => walk(std::slice::from_ref(s), out),
                Stmt::For { body, .. } | Stmt::ForOf { body, .. } | Stmt::ForIn { body, .. } => walk(body, out),
                Stmt::Try {
                    block,
                    handler,
                    finalizer,
                    ..
                } => {
                    walk(block, out);
                    for ss in [handler.as_ref(), finalizer.as_ref()].into_iter().flatten() {
                        walk(ss, out);
                    }
                }
                Stmt::Switch { cases, .. } => {
                    for (_, ss) in cases {
                        walk(ss, out);
                    }
                }
                _ => {}
            }
        }
    }
    let mut out = vec![];
    match &f.body {
        FnBody::Expr(e) => out.push(e.span),
        FnBody::Block(ss) => walk(ss, &mut out),
    }
    out
}

/// The 0.8 response helpers and result rules, in the v2 subset. `__z08EndpointValue` applies
/// 0.8's own test (`result.kind === "response"`), so helper-built responses keep their status.
pub(super) const PRELUDE: &str = r#"type __z08EndpointResponse = { kind: "response"; status: number; headers: Record<string, string>; body: string };
type __z08ResponseOptions = { status?: number; headers?: Record<string, string> };
type __z08RouteResult = { status: number; headers: Record<string, string>; body: string };
function __z08Response(status: number, defaults: Record<string, string>, headers: Record<string, string> | null | undefined, body: string): __z08EndpointResponse {
  const out: Record<string, string> = {};
  for (const key of Object.keys(defaults)) out[key.toLowerCase()] = defaults[key] ?? "";
  if (headers != null) { const extra: Record<string, string> = headers; for (const key of Object.keys(extra)) out[key.toLowerCase()] = extra[key] ?? ""; }
  return { kind: "response", status, headers: out, body };
}
function __z08JsonResponse(value: unknown, options?: __z08ResponseOptions): __z08EndpointResponse {
  return __z08Response(options?.status ?? 200, { "content-type": "application/json; charset=utf-8" }, options?.headers, JSON.stringify(value ?? null) ?? "null");
}
function __z08TextResponse(value: unknown, options?: __z08ResponseOptions): __z08EndpointResponse {
  return __z08Response(options?.status ?? 200, { "content-type": "text/plain; charset=utf-8" }, options?.headers, value === null || value === undefined ? "" : String(value));
}
function __z08EmptyResponse(options?: __z08ResponseOptions): __z08EndpointResponse {
  return __z08Response(options?.status ?? 204, {}, options?.headers, "");
}
function __z08RedirectResponse(url: string, options?: __z08ResponseOptions): __z08EndpointResponse {
  return __z08Response(options?.status ?? 302, { location: url }, options?.headers, "");
}
function __z08EndpointValue(value: unknown): __z08RouteResult {
  if (value === null || value === undefined) return { status: 204, headers: {}, body: "" };
  if (typeof value === "string") return { status: 200, headers: { "content-type": "text/plain; charset=utf-8" }, body: value };
  if (typeof value === "number" || typeof value === "boolean") return { status: 200, headers: { "content-type": "text/plain; charset=utf-8" }, body: String(value) };
  const response = value as { kind?: unknown; status?: unknown; headers?: unknown; body?: unknown };
  if (response.kind === "response") {
    const built = value as __z08EndpointResponse;
    return { status: built.status, headers: built.headers, body: built.body };
  }
  return { status: 200, headers: { "content-type": "application/json; charset=utf-8" }, body: JSON.stringify(value) ?? "null" };
}
"#;

/// The 0.8 response helper each server import names, as a v2 prelude function.
pub(super) fn helper(local: &str, imported: &str) -> Option<String> {
    Some(match imported {
        "json" => format!(
            "function {local}(value: unknown, options?: __z08ResponseOptions): __z08EndpointResponse {{ return __z08JsonResponse(value, options); }}\n"
        ),
        "text" => format!(
            "function {local}(value: unknown, options?: __z08ResponseOptions): __z08EndpointResponse {{ return __z08TextResponse(value, options); }}\n"
        ),
        "empty" => format!(
            "function {local}(options?: __z08ResponseOptions): __z08EndpointResponse {{ return __z08EmptyResponse(options); }}\n"
        ),
        "redirect" => format!(
            "function {local}(url: string, options?: __z08ResponseOptions): __z08EndpointResponse {{ return __z08RedirectResponse(url, options); }}\n"
        ),
        _ => return None,
    })
}
