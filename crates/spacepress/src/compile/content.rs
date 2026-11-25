//! `"spacepress/content"`: navigation for a hierarchical kind's pages (docs/decisions/2026-11-13-docs-kind.md).
//!
//! - `nav(kind, current?)`: the kind's published items as a tree, siblings by `order` then title,
//!   each with its URL and whether it is, or holds, the current item;
//! - `pager(kind, current)`: the items before and after `current` in that tree, read top to bottom;
//! - `headings(item)`: the `##`–`###` headings of an item's content, with the anchor ids the page
//!   renders them with.
//!
//! The three run in PHP (`SpacePress\Content\Nav`), and read as the visitor like every query: a draft
//! or an item the visitor may not read is in no tree, pager or link.
//!
//! - `apiReference(source?)`: an OpenAPI document (the app's own, or a JSON file in `public/`) as
//!   tags, operations and flat field lists a page maps over (`SpacePress\Content\ApiReference`).
use super::*;

pub const CONTENT_EXPORTS: &[&str] = &["nav", "pager", "headings", "apiReference"];
/// Type-only exports of `"spacepress/content"`: importable, erased.
pub const CONTENT_TYPES: &[&str] = &[
    "NavItem",
    "PagerLink",
    "Heading",
    "ApiReference",
    "ApiTag",
    "ApiOperation",
    "ApiField",
    "ApiResponse",
];

/// The levels of tree `nav()` returns: the type system has no recursive types, so the tree is as
/// deep as this, and `nav()` stops there.
pub const NAV_DEPTH: usize = 4;

impl Compiler<'_, '_> {
    /// The `"spacepress/content"` export an identifier names, if it does.
    pub(super) fn content_builtin(&mut self, e: &Expr) -> Result<Option<&'static str>> {
        let ExprKind::Ident(n) = &e.kind else { return Ok(None) };
        Ok(match self.resolve(n, e.span)? {
            Resolved::Value(Value::Builtin(Builtin::Content(name)), _) => Some(name),
            _ => None,
        })
    }

    /// A kind passed to `nav` or `pager`: a hierarchical collection with a url, named directly.
    fn content_kind(&mut self, args: &[ArrEl], span: Span, name: &str) -> Result<String> {
        let k = self.arg(args, 0, span, "a hierarchical kind")?;
        let v = self.expr(k)?;
        let Ty::Collection(table) = v.ty else {
            return err_help(
                k.span,
                format!("`{name}()` needs a kind, not {}", v.ty.describe()),
                "import the kind from \"spacepress:db\" and pass it: `nav(docs)`",
            );
        };
        let c = &self.schema.collections[&table];
        if !c.hierarchical || c.url.is_none() {
            return err_help(
                k.span,
                format!("`{name}()` needs a hierarchical kind with a url; `{}` isn't one", c.key),
                "declare it with `hierarchical: true` and `url: \"/docs/:path\"`",
            );
        }
        let key = c.key.clone();
        self.uses("read", key, k.span);
        Ok(table)
    }

    /// The current item's id, from a number or a row (`item`, `item.id`).
    fn content_current(&mut self, e: &Expr, table: &str, name: &str) -> Result<String> {
        let v = self.expr(e)?;
        let v = self.finish_value(v, e.span)?;
        let row = types::row(self.schema, table);
        let nullable = Ty::union([Ty::Num, Ty::Null, Ty::Undefined]);
        if assignable(&v.ty, &nullable) {
            return Ok(v.php);
        }
        if assignable(&v.ty, &row) || assignable(&v.ty, &row.clone().nullable()) {
            return Ok(format!("\\SpacePress\\Content\\Nav::id({})", v.php));
        }
        err_help(
            e.span,
            format!("`{name}()` takes the current item or its id, not {}", v.ty.describe()),
            "pass the page's `item`, or `item.id`",
        )
    }

    pub(super) fn content_call(&mut self, name: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        for a in args {
            if let ArrEl::Spread(x) = a {
                return err(x.span, "spread arguments aren't supported here");
            }
        }
        match name {
            "nav" => {
                if args.is_empty() || args.len() > 2 {
                    return err_help(
                        span,
                        "`nav()` takes a kind and the current item",
                        "call `nav(docs, item)`",
                    );
                }
                let table = self.content_kind(args, span, "nav")?;
                let current = match args.get(1) {
                    Some(ArrEl::Item(e)) => self.content_current(e, &table, "nav")?,
                    _ => "null".into(),
                };
                Ok(Out::atom(
                    format!(
                        "\\SpacePress\\Content\\Nav::tree({}, {current}, {NAV_DEPTH})",
                        php_str(&table)
                    ),
                    Ty::Promise(Box::new(Ty::Array(Box::new(nav_item(NAV_DEPTH))))),
                ))
            }
            "pager" => {
                if args.len() != 2 {
                    return err_help(
                        span,
                        "`pager()` takes a kind and the current item",
                        "call `pager(docs, item)`",
                    );
                }
                let table = self.content_kind(args, span, "pager")?;
                let ArrEl::Item(e) = &args[1] else {
                    unreachable!("spreads refused above")
                };
                let current = self.content_current(e, &table, "pager")?;
                let mut out = IndexMap::new();
                out.insert("prev".to_string(), (pager_link().nullable(), false));
                out.insert("next".to_string(), (pager_link().nullable(), false));
                Ok(Out::atom(
                    format!("\\SpacePress\\Content\\Nav::pager({}, {current})", php_str(&table)),
                    Ty::Promise(Box::new(Ty::Obj(out))),
                ))
            }
            "headings" => {
                if args.len() != 1 {
                    return err_help(span, "`headings()` takes the item", "call `headings(item)`");
                }
                let e = self.arg(args, 0, span, "an item")?;
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                let content = self
                    .schema
                    .collections
                    .values()
                    .filter(|c| c.hierarchical)
                    .find_map(|c| {
                        let row = types::row(self.schema, &c.table);
                        (assignable(&v.ty, &row) || assignable(&v.ty, &row.nullable())).then(|| c.content.clone())
                    });
                let Some(Some(column)) = content else {
                    return err_help(
                        e.span,
                        format!(
                            "`headings()` takes an item of a hierarchical kind with content, not {}",
                            v.ty.describe()
                        ),
                        "pass the page's `item`",
                    );
                };
                Ok(Out::atom(
                    format!("\\SpacePress\\Content\\Nav::headings({}, {})", v.php, php_str(&column)),
                    Ty::Array(Box::new(heading())),
                ))
            }
            "apiReference" => {
                let source = match args {
                    [] => "null".to_string(),
                    [ArrEl::Item(e)] => match &e.kind {
                        ExprKind::Str(path) if path.ends_with(".json") && !path.contains("..") => php_str(path),
                        _ => {
                            return err_help(
                                e.span,
                                "`apiReference()` takes the path of a JSON file in `public/`, as a string literal",
                                "write `apiReference()` for the app's own API, or `apiReference(\"partner-api.json\")`",
                            );
                        }
                    },
                    _ => {
                        return err_help(
                            span,
                            "`apiReference()` takes at most one argument",
                            "write `apiReference()` for the app's own API, or `apiReference(\"partner-api.json\")`",
                        );
                    }
                };
                Ok(Out::atom(
                    format!("\\SpacePress\\Content\\ApiReference::build({source})"),
                    api_reference(),
                ))
            }
            other => unreachable!("spacepress/content has no export {other}"),
        }
    }
}

fn field(fields: &mut IndexMap<String, (Ty, bool)>, name: &str, ty: Ty) {
    fields.insert(name.to_string(), (ty, false));
}

/// A type `"spacepress/content"` exports, by name: `NavItem` is the tree's top level.
pub(super) fn content_type(name: &str) -> Option<Ty> {
    match name {
        "NavItem" => Some(nav_item(NAV_DEPTH)),
        "PagerLink" => Some(pager_link()),
        "Heading" => Some(heading()),
        "ApiReference" => Some(api_reference()),
        "ApiTag" => Some(api_tag()),
        "ApiOperation" => Some(api_operation()),
        "ApiField" => Some(api_field()),
        "ApiResponse" => Some(api_response()),
        _ => None,
    }
}

/// `{ id, title, href, current, open, children }`, `depth` levels deep.
fn nav_item(depth: usize) -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "id", Ty::Num);
    field(&mut fields, "title", Ty::Str);
    field(&mut fields, "href", Ty::Str);
    field(&mut fields, "current", Ty::Bool);
    field(&mut fields, "open", Ty::Bool);
    // Every level has `children`, as `NavItem` declares; below the last level it is always empty.
    let below = if depth > 1 { nav_item(depth - 1) } else { Ty::Never };
    field(&mut fields, "children", Ty::Array(Box::new(below)));
    Ty::Obj(fields)
}

/// `{ id, title, href }`.
fn pager_link() -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "id", Ty::Num);
    field(&mut fields, "title", Ty::Str);
    field(&mut fields, "href", Ty::Str);
    Ty::Obj(fields)
}

/// `{ depth, text, id }`.
fn heading() -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "depth", Ty::Num);
    field(&mut fields, "text", Ty::Str);
    field(&mut fields, "id", Ty::Str);
    Ty::Obj(fields)
}

/// `{ name, depth, type, required, description, constraints, in }`: one parameter or property.
fn api_field() -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "name", Ty::Str);
    field(&mut fields, "depth", Ty::Num);
    field(&mut fields, "type", Ty::Str);
    field(&mut fields, "required", Ty::Bool);
    field(&mut fields, "description", Ty::Str);
    field(&mut fields, "constraints", Ty::Array(Box::new(Ty::Str)));
    field(&mut fields, "in", Ty::Str);
    Ty::Obj(fields)
}

/// `{ status, description, type, fields }`.
fn api_response() -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "status", Ty::Str);
    field(&mut fields, "description", Ty::Str);
    field(&mut fields, "type", Ty::Str);
    field(&mut fields, "fields", Ty::Array(Box::new(api_field())));
    Ty::Obj(fields)
}

/// One operation: its method and path, what it takes and answers, and request samples.
fn api_operation() -> Ty {
    let mut fields = IndexMap::new();
    for name in ["id", "slug", "method", "path", "summary", "description", "access"] {
        field(&mut fields, name, Ty::Str);
    }
    field(&mut fields, "params", Ty::Array(Box::new(api_field())));
    field(&mut fields, "bodyType", Ty::Str);
    field(&mut fields, "body", Ty::Array(Box::new(api_field())));
    field(&mut fields, "responses", Ty::Array(Box::new(api_response())));
    field(&mut fields, "curl", Ty::Str);
    field(&mut fields, "fetch", Ty::Str);
    Ty::Obj(fields)
}

/// `{ name, slug, description, operations }`.
fn api_tag() -> Ty {
    let mut fields = IndexMap::new();
    field(&mut fields, "name", Ty::Str);
    field(&mut fields, "slug", Ty::Str);
    field(&mut fields, "description", Ty::Str);
    field(&mut fields, "operations", Ty::Array(Box::new(api_operation())));
    Ty::Obj(fields)
}

/// `{ title, version, description, server, tags }`.
fn api_reference() -> Ty {
    let mut fields = IndexMap::new();
    for name in ["title", "version", "description", "server"] {
        field(&mut fields, name, Ty::Str);
    }
    field(&mut fields, "tags", Ty::Array(Box::new(api_tag())));
    Ty::Obj(fields)
}
