//! `tp/app/`: pages, layouts and HTTP routes by file convention (docs/spec/rendering.md §1–§3).
//!
//! Discovery turns the folder tree into a route table. Each route is a URL pattern plus the files
//! that render it: `page.tsx` with the `layout.tsx` files above it, or `route.ts` for an HTTP
//! handler with no page. `not-found.tsx` and `error.tsx` render the 404 and the render-error page;
//! `login.tsx` renders wp-login.php's sign-in view (docs/decisions/2026-11-15-one-sign-in-page.md).
//! `page.md` is a page too, with the same layouts; [`crate::content_page`] reads it.
//! The pipeline compiles the exports these files name (`default`, `metadata`, `guard`, `search`,
//! HTTP method handlers) through the same compiler as every other root; the runtime router
//! (`runtime/TypedPress/Wp/AppRouter.php`) matches requests against the table in `schema.php`.
//!
//! Folder names:
//! - `about` is a literal segment;
//! - `[slug]` binds one segment, `[...rest]` one or more, `[[...rest]]` zero or more (last only);
//! - `(group)` groups files without adding a segment;
//! - `_private` and dot folders are never routed: they hold colocated modules.

use crate::diag::{Code, Diag, Span};
use crate::loader::Fs;
use std::path::{Path, PathBuf};

/// The routing folder, under the TypedPress root.
pub const APP_DIR: &str = "app";

/// One URL segment of a route pattern.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Segment {
    Literal(String),
    /// `[name]`
    Param(String),
    /// `[...name]`: one or more segments.
    CatchAll(String),
    /// `[[...name]]`: zero or more segments.
    OptionalCatchAll(String),
}

/// What a route serves.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum RouteKind {
    /// `page.tsx`, optionally with HTTP handlers exported beside its default component.
    Page,
    /// `route.ts`: HTTP handlers only.
    Handler,
}

/// One discovered route.
#[derive(Debug, Clone)]
pub struct Route {
    pub segments: Vec<Segment>,
    pub kind: RouteKind,
    /// The route file, relative to `tp/` (`app/blog/[slug]/page.tsx`).
    pub file: String,
    /// Layouts from the root down, relative to `tp/`.
    pub layouts: Vec<String>,
    /// The nearest `error.tsx` at or above this route, relative to `tp/`.
    pub error: Option<String>,
}

impl Route {
    /// The pattern in the route grammar the runtime and the host read: `/blog/:slug`,
    /// `/docs/*rest` (one or more), `/docs/**rest` (zero or more). `/` for the root.
    pub fn pattern(&self) -> String {
        pattern(&self.segments)
    }

    /// A stable id from the file path: `blog/[slug]` for `app/blog/[slug]/page.tsx`. It names the
    /// route in diagnostics, typed links and `typedpress check --json`.
    pub fn id(&self) -> String {
        let dir = Path::new(&self.file)
            .parent()
            .and_then(|p| p.strip_prefix(APP_DIR).ok())
            .map(|p| p.to_string_lossy().replace('\\', "/"))
            .unwrap_or_default();
        if dir.is_empty() { "/".into() } else { dir }
    }
}

pub fn pattern(segments: &[Segment]) -> String {
    if segments.is_empty() {
        return "/".into();
    }
    let mut out = String::new();
    for s in segments {
        out.push('/');
        match s {
            Segment::Literal(l) => out.push_str(l),
            Segment::Param(n) => {
                out.push(':');
                out.push_str(n);
            }
            Segment::CatchAll(n) => {
                out.push('*');
                out.push_str(n);
            }
            Segment::OptionalCatchAll(n) => {
                out.push_str("**");
                out.push_str(n);
            }
        }
    }
    out
}

/// The route table of one app.
#[derive(Debug, Clone, Default)]
pub struct Table {
    pub routes: Vec<Route>,
    /// `app/not-found.tsx`, with the root layout(s) that wrap it.
    pub not_found: Option<Route>,
    /// `app/login.tsx`, with the root layout(s) that wrap it: wp-login.php's sign-in view.
    pub login: Option<Route>,
    /// Every `.tsx`/`.ts` route file the pipeline compiles (pages, layouts, not-found, error,
    /// handlers), relative to `tp/`, sorted.
    pub files: Vec<String>,
}

/// TypedPress URL segments WordPress or TypedPress own: the same list kind `url` patterns can't start
/// with (spec §5: wp-admin, login, REST and media keep working in every mode).
pub use crate::kind_url::RESERVED_TP_SEGMENTS as RESERVED_TP;

/// Names that look like a route file but aren't one, with the fix.
const MISNAMED: &[(&str, &str)] = &[
    ("page.ts", "a page renders JSX: name it page.tsx"),
    ("page.jsx", "TypedPress compiles TypeScript: name it page.tsx"),
    ("page.mdx", "TypedPress reads Markdown: name it page.md"),
    ("page.js", "TypedPress compiles TypeScript: name it page.tsx"),
    ("layout.ts", "a layout renders JSX: name it layout.tsx"),
    ("layout.jsx", "TypedPress compiles TypeScript: name it layout.tsx"),
    (
        "route.tsx",
        "an HTTP route returns a Response, not JSX: name it route.ts",
    ),
    ("route.js", "TypedPress compiles TypeScript: name it route.ts"),
    ("template.tsx", "put shared structure in layout.tsx"),
    (
        "loading.tsx",
        "TypedPress renders on the server and has no Suspense; the page arrives when its data is in",
    ),
    (
        "default.tsx",
        "parallel routes aren't supported; render both parts from one page",
    ),
    (
        "middleware.ts",
        "protect a section with `export const guard` in its layout.tsx",
    ),
    ("not-found.ts", "name it not-found.tsx"),
    ("error.ts", "name it error.tsx"),
];

fn located(fs: &dyn Fs, path: &Path, message: impl Into<String>, help: impl Into<String>) -> Diag {
    let source = fs.read_to_string(path).unwrap_or_default();
    let file = crate::diag::register_file(path, &source);
    Diag::new(Span { file, start: 0, end: 0 }, message)
        .help(help)
        .code(Code::Import)
}

/// Reads `tp/app/` (absolute `dir`) into a route table. `first` is the TypedPress root, which the
/// relative paths are taken against.
pub fn discover(fs: &dyn Fs, first: &Path) -> std::result::Result<Table, Vec<Diag>> {
    let dir = first.join(APP_DIR);
    let mut table = Table::default();
    if !fs.is_dir(&dir) {
        return Ok(table);
    }
    let mut diags = Vec::new();
    walk(
        fs,
        first,
        &dir,
        &mut Vec::new(),
        &mut Vec::new(),
        None,
        true,
        &mut table,
        &mut diags,
    );
    table.files.sort();
    table.files.dedup();
    // A layout, guard or error page that no route sits under never runs: say so instead of
    // building a file that does nothing.
    for file in &table.files {
        let used = table
            .routes
            .iter()
            .chain(table.not_found.iter())
            .chain(table.login.iter())
            .any(|r| r.layouts.contains(file) || r.error.as_ref() == Some(file) || &r.file == file);
        if !used {
            diags.push(located(
                fs,
                &first.join(file),
                format!("{file} wraps no page"),
                "add a page.tsx in its folder or below it, or remove the file",
            ));
        }
    }
    // Two files claiming one URL: `(a)/x/page.tsx` and `(b)/x/page.tsx`, or a page and a handler.
    // Param names don't matter for matching, so `/blog/[id]` and `/blog/[slug]` collide too.
    let mut shapes: Vec<(String, &Route)> = Vec::new();
    for route in &table.routes {
        let shape = shape(&route.segments);
        if let Some((_, other)) = shapes.iter().find(|(s, _)| *s == shape) {
            diags.push(located(
                fs,
                &first.join(&route.file),
                format!("{} and {} both serve {}", route.file, other.file, route.pattern()),
                "give each URL one page.tsx or route.ts; route groups `(name)` don't add a segment",
            ));
        } else {
            shapes.push((shape, route));
        }
    }
    // `[[...x]]` at /docs also matches /docs itself, which a sibling page would claim.
    for route in &table.routes {
        if let Some(Segment::OptionalCatchAll(_)) = route.segments.last() {
            let parent = &route.segments[..route.segments.len() - 1];
            if let Some(other) = table.routes.iter().find(|r| shape(&r.segments) == shape(parent)) {
                diags.push(located(
                    fs,
                    &first.join(&route.file),
                    format!(
                        "{} also matches {}, which {} serves",
                        route.file,
                        pattern(parent),
                        other.file
                    ),
                    "use `[...name]` (one or more segments), or remove the other page",
                ));
            }
        }
    }
    table.routes.sort_by(|a, b| {
        specificity(&a.segments)
            .cmp(&specificity(&b.segments))
            .then(a.file.cmp(&b.file))
    });
    if diags.is_empty() { Ok(table) } else { Err(diags) }
}

fn shape(segments: &[Segment]) -> String {
    segments
        .iter()
        .map(|s| match s {
            Segment::Literal(l) => format!("/{l}"),
            Segment::Param(_) => "/:".into(),
            Segment::CatchAll(_) => "/*".into(),
            Segment::OptionalCatchAll(_) => "/**".into(),
        })
        .collect()
}

/// Left to right, literal before param before catch-all; the runtime walks routes in this order and
/// takes the first match, so `/notes/featured` wins over `/notes/[slug]`.
fn specificity(segments: &[Segment]) -> Vec<u8> {
    let mut out: Vec<u8> = segments
        .iter()
        .map(|s| match s {
            Segment::Literal(_) => 0,
            Segment::Param(_) => 1,
            Segment::CatchAll(_) => 2,
            Segment::OptionalCatchAll(_) => 3,
        })
        .collect();
    // A route ends after its last segment: shorter routes sort after longer ones that share
    // their prefix, which only matters between a catch-all and its siblings.
    out.push(4);
    out
}

#[allow(clippy::too_many_arguments)]
fn walk(
    fs: &dyn Fs,
    first: &Path,
    dir: &Path,
    segments: &mut Vec<Segment>,
    layouts: &mut Vec<String>,
    error: Option<String>,
    root: bool,
    table: &mut Table,
    diags: &mut Vec<Diag>,
) {
    let mut entries = match fs.read_dir(dir) {
        Ok(e) => e,
        Err(e) => {
            diags.push(Diag::new(Span::NONE, format!("can't read {}: {e}", dir.display())));
            return;
        }
    };
    entries.sort();
    let rel = |p: &Path| p.strip_prefix(first).unwrap_or(p).to_string_lossy().replace('\\', "/");
    let names: Vec<String> = entries
        .iter()
        .filter(|p| fs.is_file(p))
        .map(|p| p.file_name().unwrap_or_default().to_string_lossy().into_owned())
        .collect();
    for (bad, help) in MISNAMED {
        if names.iter().any(|n| n == bad) {
            diags.push(located(fs, &dir.join(bad), format!("{bad} isn't a route file"), *help));
        }
    }
    let has = |n: &str| names.iter().any(|x| x == n);
    let pushed_layout = has("layout.tsx");
    if pushed_layout {
        layouts.push(rel(&dir.join("layout.tsx")));
        table.files.push(rel(&dir.join("layout.tsx")));
    }
    let error = if has("error.tsx") {
        let e = rel(&dir.join("error.tsx"));
        table.files.push(e.clone());
        Some(e)
    } else {
        error
    };
    if has("not-found.tsx") {
        let file = rel(&dir.join("not-found.tsx"));
        if !root {
            diags.push(located(
                fs,
                &dir.join("not-found.tsx"),
                "not-found.tsx works only at the root of tp/app/",
                "move it to tp/app/not-found.tsx; call notFound() in a page to show it",
            ));
        } else {
            table.files.push(file.clone());
            table.not_found = Some(Route {
                segments: vec![],
                kind: RouteKind::Page,
                file,
                layouts: layouts.clone(),
                error: error.clone(),
            });
        }
    }
    if has("login.tsx") {
        let file = rel(&dir.join("login.tsx"));
        if !root {
            diags.push(located(
                fs,
                &dir.join("login.tsx"),
                "login.tsx works only at the root of tp/app/",
                "move it to tp/app/login.tsx: it renders wp-login.php's sign-in page, which has one URL",
            ));
        } else {
            table.files.push(file.clone());
            table.login = Some(Route {
                segments: vec![],
                kind: RouteKind::Page,
                file,
                layouts: layouts.clone(),
                error: error.clone(),
            });
        }
    }
    match (has("page.tsx"), has("route.ts")) {
        (true, true) => diags.push(located(
            fs,
            &dir.join("route.ts"),
            format!("{} has both page.tsx and route.ts", rel(dir)),
            "export the HTTP handlers (`export async function POST`) from page.tsx instead",
        )),
        (true, false) | (false, true) => {
            let (name, kind) = if has("page.tsx") {
                ("page.tsx", RouteKind::Page)
            } else {
                ("route.ts", RouteKind::Handler)
            };
            let file = rel(&dir.join(name));
            table.files.push(file.clone());
            table.routes.push(Route {
                segments: segments.clone(),
                kind,
                file,
                layouts: layouts.clone(),
                error: error.clone(),
            });
        }
        (false, false) => {}
    }
    // `page.md`: a page whose content a WordPress Page holds. No module is loaded for it, so it
    // is a route without an entry in `table.files`.
    if has(crate::content_page::FILE) {
        let file = dir.join(crate::content_page::FILE);
        match ["page.tsx", "route.ts"].into_iter().find(|n| has(n)) {
            Some(other) => diags.push(located(
                fs,
                &file,
                format!("{} has both {other} and page.md", rel(dir)),
                "a URL has one owner: keep one of the two files",
            )),
            None => table.routes.push(Route {
                segments: segments.clone(),
                kind: RouteKind::Page,
                file: rel(&file),
                layouts: layouts.clone(),
                error: error.clone(),
            }),
        }
    }
    for path in entries {
        if !fs.is_dir(&path) || fs.is_symlink(&path) {
            continue;
        }
        let name = path.file_name().unwrap_or_default().to_string_lossy().into_owned();
        if name.starts_with('.') || name.starts_with('_') || name == "node_modules" {
            continue;
        }
        let segment = match parse_segment(&name) {
            Ok(s) => s,
            Err((message, help)) => {
                diags.push(located(fs, &path, message, help));
                continue;
            }
        };
        if root
            && segments.is_empty()
            && let Some(Segment::Literal(l)) = &segment
            && RESERVED_TP.contains(&l.as_str())
        {
            diags.push(located(
                fs,
                &path,
                format!("tp/app/{name}/ would shadow /{name}, which WordPress or TypedPress serves"),
                "rename the folder; wp-admin, login, REST, feeds and TypedPress's own paths stay reachable in every mode",
            ));
            continue;
        }
        if matches!(
            segments.last(),
            Some(Segment::CatchAll(_) | Segment::OptionalCatchAll(_))
        ) {
            diags.push(located(
                fs,
                &path,
                format!("{name}/ is below a catch-all segment, which takes every remaining segment"),
                "make the catch-all folder the last one in its path",
            ));
            continue;
        }
        let pushed = segment.is_some();
        if let Some(s) = segment {
            if let Some(name) = param_name(&s)
                && segments.iter().filter_map(param_name).any(|n| n == name)
            {
                diags.push(located(
                    fs,
                    &path,
                    format!("the parameter `{name}` appears twice in this path"),
                    "give each dynamic segment its own name",
                ));
                continue;
            }
            segments.push(s);
        }
        walk(fs, first, &path, segments, layouts, error.clone(), false, table, diags);
        if pushed {
            segments.pop();
        }
    }
    if pushed_layout {
        layouts.pop();
    }
}

fn param_name(s: &Segment) -> Option<&str> {
    match s {
        Segment::Param(n) | Segment::CatchAll(n) | Segment::OptionalCatchAll(n) => Some(n),
        Segment::Literal(_) => None,
    }
}

type Refusal = (String, &'static str);

/// One folder name: a segment, or `None` for a route group.
pub fn parse_segment(name: &str) -> std::result::Result<Option<Segment>, Refusal> {
    let ident = |s: &str| {
        let mut chars = s.chars();
        chars.next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
            && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
    };
    if let Some(inner) = name.strip_prefix('(').and_then(|s| s.strip_suffix(')')) {
        if inner.is_empty() || inner.starts_with('.') {
            return Err((
                format!("`{name}` isn't a route group"),
                "a group is `(name)`; intercepting routes `(.)x` aren't supported",
            ));
        }
        return Ok(None);
    }
    if name.starts_with('@') {
        return Err((
            format!("`{name}` is a parallel route slot, which TypedPress doesn't support"),
            "render both parts from one page, as components",
        ));
    }
    if let Some(inner) = name.strip_prefix("[[...").and_then(|s| s.strip_suffix("]]")) {
        return if ident(inner) {
            Ok(Some(Segment::OptionalCatchAll(inner.into())))
        } else {
            Err((
                format!("`{name}` needs a name like `[[...slug]]`"),
                "use letters, digits and `_`",
            ))
        };
    }
    if let Some(inner) = name.strip_prefix("[...").and_then(|s| s.strip_suffix(']')) {
        return if ident(inner) {
            Ok(Some(Segment::CatchAll(inner.into())))
        } else {
            Err((
                format!("`{name}` needs a name like `[...slug]`"),
                "use letters, digits and `_`",
            ))
        };
    }
    if let Some(inner) = name.strip_prefix('[').and_then(|s| s.strip_suffix(']')) {
        return if ident(inner) {
            Ok(Some(Segment::Param(inner.into())))
        } else {
            Err((
                format!("`{name}` needs a name like `[slug]`"),
                "use letters, digits and `_`",
            ))
        };
    }
    if name.contains(['[', ']']) {
        return Err((
            format!("`{name}` mixes text and a parameter"),
            "a dynamic segment is a whole folder name: `[slug]`, not `post-[slug]`",
        ));
    }
    let literal = !name.is_empty()
        && name
            .chars()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || matches!(c, '-' | '.' | '_' | '~'))
        && !name.ends_with('.');
    if !literal {
        return Err((
            format!("`{name}` isn't a URL segment TypedPress routes"),
            "use lowercase letters, digits, `-`, `.`, `_` and `~` in folder names",
        ));
    }
    Ok(Some(Segment::Literal(name.into())))
}

/// The exports a route file may have, and what each is for. Any other export is an error, so a
/// typo (`export const metdata`) never silently does nothing. Helpers belong in `_private` folders
/// or `tp/components/`.
pub fn allowed_exports(file: &str) -> &'static [&'static str] {
    let name = Path::new(file).file_name().and_then(|n| n.to_str()).unwrap_or_default();
    match name {
        "page.tsx" => &[
            "default",
            "route",
            "metadata",
            "template",
            "search",
            "prerender",
            "staticParams",
            "access",
            "signature",
            "GET",
            "POST",
            "PUT",
            "PATCH",
            "DELETE",
        ],
        "layout.tsx" => &["default", "metadata", "guard", "template"],
        "not-found.tsx" | "error.tsx" | "login.tsx" => &["default", "metadata"],
        "route.ts" => &["access", "signature", "GET", "POST", "PUT", "PATCH", "DELETE"],
        _ => &[],
    }
}

pub const METHODS: &[&str] = &["GET", "POST", "PUT", "PATCH", "DELETE"];

/// Values `"typedpress/app"` exports (docs/spec/rendering.md §3).
pub const EXPORTS: &[&str] = &[
    "notFound",
    "redirect",
    "useFormState",
    "Comments",
    "Content",
    "Html",
    "Link",
    "Live",
    "LoginForm",
    "Pagination",
    "Search",
    "SearchResults",
    "TemplatePart",
];
/// Type-only exports of `"typedpress/app"`: importable, erased.
pub const TYPES: &[&str] = &[
    "PageProps",
    "LayoutProps",
    "Metadata",
    "RouteRequest",
    "RouteResponse",
    "Guard",
    "Template",
    "SearchParams",
    "FormState",
    "StaticParams",
    "LoginProps",
];

pub fn export(name: &str) -> Option<&'static str> {
    EXPORTS.iter().copied().find(|e| *e == name)
}

/// `tp/_generated/routes.d.ts`: every page and handler route, so `<Link href>` and `PageProps<…>`
/// are checked against the app's own URLs. Each route's `path` is a template literal type: a
/// `[slug]` folder is any segment, a catch-all any rest of the path.
pub fn routes_d_ts(table: &Table, kinds: &[(String, String)]) -> Option<String> {
    if table.routes.is_empty() {
        return None;
    }
    let mut out = String::from(
        "// Generated by `typedpress build` from tp/app/. Do not edit.\n\nexport {};\n\ndeclare module \"typedpress/app\" {\n  interface Routes {\n",
    );
    for route in &table.routes {
        let mut path = String::new();
        let mut params = Vec::new();
        for s in &route.segments {
            match s {
                Segment::Literal(l) => {
                    path.push('/');
                    path.push_str(l);
                }
                Segment::Param(n) => {
                    path.push_str("/${string}");
                    params.push(format!("{n}: string"));
                }
                Segment::CatchAll(n) => {
                    path.push_str("/${string}");
                    params.push(format!("{n}: string[]"));
                }
                Segment::OptionalCatchAll(n) => {
                    path.push_str("${\"\" | `/${string}`}");
                    params.push(format!("{n}: string[]"));
                }
            }
        }
        if path.is_empty() {
            path.push('/');
        }
        let id = serde_json::to_string(&route.id()).expect("JSON string");
        // A page serving a kind's URL also gets that kind's item (spec §2.7).
        let item = match kinds
            .iter()
            .find(|(_, url)| route.kind == RouteKind::Page && serves_kind_url(route, url).is_some())
        {
            Some((kind, _)) => format!(
                "; item: import(\"typedpress\").RegisteredKinds extends {{ {kind}: {{ $inferSelect: infer R }} }} ? R : Record<string, unknown>"
            ),
            None => String::new(),
        };
        out.push_str(&format!(
            "    {id}: {{ path: `{path}`; params: {{ {} }}{item} }};\n",
            params.join("; ")
        ));
    }
    out.push_str("  }\n}\n");
    Some(out)
}

/// Whether a page's folders serve a kind's `url` pattern (spec §2.7: "an app/ route whose path
/// matches the pattern renders with TypedPress"): the same literals in the same places, a `[x]` folder
/// for each placeholder, and `[...x]` for `:path`. Returns the folder parameter for each pattern
/// placeholder, in order.
pub fn serves_kind_url(route: &Route, pattern: &str) -> Option<Vec<(String, String)>> {
    let parts: Vec<&str> = pattern.trim_matches('/').split('/').collect();
    if parts.len() != route.segments.len() {
        return None;
    }
    let mut bound = Vec::new();
    for (part, segment) in parts.iter().zip(&route.segments) {
        match (part.strip_prefix(':'), segment) {
            (None, Segment::Literal(l)) if l == part => {}
            // `[[...path]]` too: its empty match (`/docs`) finds no item and is a 404, so the
            // landing page stays its own `tp/app/docs/page.tsx`.
            (Some("path"), Segment::CatchAll(p) | Segment::OptionalCatchAll(p)) => {
                bound.push(("path".to_string(), p.clone()))
            }
            (Some(field), Segment::Param(p)) if field != "path" => bound.push((field.to_string(), p.clone())),
            _ => return None,
        }
    }
    Some(bound)
}

/// The parameters a route file's folders bind, outermost first: (name, catch-all).
pub fn params_of(file: &str) -> Vec<(String, bool)> {
    let mut out = Vec::new();
    let dir = Path::new(file).parent().unwrap_or(Path::new(""));
    for part in dir.components().skip(1) {
        match parse_segment(&part.as_os_str().to_string_lossy()) {
            Ok(Some(Segment::Param(n))) => out.push((n, false)),
            Ok(Some(Segment::CatchAll(n) | Segment::OptionalCatchAll(n))) => out.push((n, true)),
            _ => {}
        }
    }
    out
}

/// The pipeline's view of a route file it should load: path below `tp/`, and its absolute path.
pub fn route_paths(first: &Path, table: &Table) -> Vec<(String, PathBuf)> {
    table.files.iter().map(|f| (f.clone(), first.join(f))).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn folder_names_are_segments_groups_or_refusals() {
        assert_eq!(parse_segment("about").unwrap(), Some(Segment::Literal("about".into())));
        assert_eq!(
            parse_segment("feed.xml").unwrap(),
            Some(Segment::Literal("feed.xml".into()))
        );
        assert_eq!(parse_segment("[slug]").unwrap(), Some(Segment::Param("slug".into())));
        assert_eq!(
            parse_segment("[...rest]").unwrap(),
            Some(Segment::CatchAll("rest".into()))
        );
        assert_eq!(
            parse_segment("[[...rest]]").unwrap(),
            Some(Segment::OptionalCatchAll("rest".into()))
        );
        assert_eq!(parse_segment("(marketing)").unwrap(), None);
        for bad in ["post-[slug]", "@modal", "(.)photo", "About", "[]", "[a-b]", "x."] {
            assert!(parse_segment(bad).is_err(), "{bad} should be refused");
        }
    }

    #[test]
    fn patterns_spell_each_segment_kind() {
        let route = |segments| Route {
            segments,
            kind: RouteKind::Page,
            file: "app/x/page.tsx".into(),
            layouts: vec![],
            error: None,
        };
        assert_eq!(route(vec![]).pattern(), "/");
        assert_eq!(
            route(vec![
                Segment::Literal("blog".into()),
                Segment::Param("year".into()),
                Segment::CatchAll("rest".into())
            ])
            .pattern(),
            "/blog/:year/*rest"
        );
        assert_eq!(route(vec![Segment::OptionalCatchAll("p".into())]).pattern(), "/**p");
    }

    #[test]
    fn literal_routes_sort_before_dynamic_ones() {
        let mut v = [
            vec![Segment::Literal("notes".into()), Segment::Param("slug".into())],
            vec![Segment::Literal("notes".into()), Segment::Literal("featured".into())],
            vec![Segment::Literal("notes".into())],
        ];
        v.sort_by_key(|s| specificity(s));
        let order: Vec<String> = v.iter().map(|s| pattern(s)).collect();
        let at = |p: &str| order.iter().position(|x| x == p).unwrap();
        assert!(at("/notes/featured") < at("/notes/:slug"));
    }
}
