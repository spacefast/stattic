//! Generator B's server half (docs/decisions/2026-11-08-tsx-to-php.md): the first render of a
//! `"use client"` component is ordinary server HTML from generator A, with the Interactivity
//! directives added.
//!
//! The component's body is rewritten before it compiles: each `useState(x)` becomes `const v = x`,
//! setters, handlers, refs and effects are dropped (they run only in the browser), and derived
//! values stay as they are. The returned element then gets `data-wp-interactive`,
//! `data-wp-context` (state and props as JSON) and, wherever markup reads something the browser
//! can recompute, `data-wp-text` / `data-wp-bind--*` / `data-wp-class--*` / `data-wp-style--*`;
//! event attributes become `data-wp-on--*`. Generator A still prints every value, so the first
//! render matches React byte for byte once the `data-wp-*` attributes are set aside.
use super::*;
use crate::client_component::{Analysis, Binding, refuse};

/// The name a `useLive` field's server answer is read through: `<prefix><field>` resolves to the
/// seeded `{ data, error, loading }` ([`Compiler::client_live_seed`]).
pub(super) const LIVE_SEED: &str = "__tp_live_";

/// The client component being compiled: its analysis, and whether the root element is still to come.
pub(super) struct ClientCtx {
    pub analysis: Analysis,
    pub root_pending: bool,
    /// Inside a `<template>` a reactive list or conditional renders from: values the browser
    /// computes are left out (their directives fill them), and nothing reads a list item in PHP.
    pub template: bool,
}

impl Compiler<'_, '_> {
    /// The server body of a client component: `useState` initial values as consts; everything
    /// that only runs in the browser left out.
    pub(super) fn client_server_body(stmts: &[Stmt], analysis: &Analysis) -> Vec<Stmt> {
        let mut out = Vec::new();
        for s in stmts {
            match s {
                Stmt::Decl {
                    pat: Pat::Arr { elems, .. },
                    init: Some(init),
                    span,
                    ..
                } if matches!(&init.kind, ExprKind::Call { .. })
                    && matches!(elems.first(), Some(Some(Pat::Id(..)))) =>
                {
                    let Some(Some(Pat::Id(name, nspan))) = elems.first() else {
                        unreachable!()
                    };
                    if let Some((_, initial)) = analysis.state.iter().find(|(n, _)| n == name) {
                        out.push(Stmt::Decl {
                            kind: DeclKind::Const,
                            pat: Pat::Id(name.clone(), *nspan),
                            ann: None,
                            init: Some(initial.clone()),
                            span: *span,
                        });
                    }
                }
                // `useLive`: the server answers the read now, as the visitor, and the browser
                // starts from that answer (a marker call `useLive(field)` compiles it).
                Stmt::Decl {
                    pat: Pat::Id(name, nspan),
                    span,
                    ..
                } if analysis.live.iter().any(|(f, ..)| f == name) => {
                    out.push(Stmt::Decl {
                        kind: DeclKind::Const,
                        pat: Pat::Id(name.clone(), *nspan),
                        ann: None,
                        init: Some(Expr {
                            kind: ExprKind::Ident(format!("{LIVE_SEED}{name}")),
                            span: *span,
                        }),
                        span: *span,
                    });
                }
                Stmt::Decl {
                    pat: Pat::Id(name, _), ..
                } if matches!(analysis.bindings.get(name), Some(Binding::Action | Binding::Ref)) => {}
                Stmt::Func(_) | Stmt::Expr(_) => {}
                other => out.push(clone_stmt(other)),
            }
        }
        out
    }

    /// Directive attributes for one intrinsic element of a client component: `entries` already
    /// holds the element's PHP attributes; `jsx` is its source. Returns extra PHP entries.
    pub(super) fn client_directives(&mut self, jsx: &Jsx) -> Result<Vec<String>> {
        let Some(ctx) = self.client.as_mut() else {
            return Ok(Vec::new());
        };
        let mut extra = Vec::new();
        if std::mem::take(&mut ctx.root_pending) {
            extra.push(format!("'data-wp-interactive' => {}", php_str(&ctx.analysis.namespace)));
            // The context: each useState value, each `useLive` answer and each prop, as the browser
            // starts from them.
            let mut fields = Vec::new();
            for (name, _) in &ctx.analysis.state {
                fields.push(name.clone());
            }
            for (name, ..) in &ctx.analysis.live {
                fields.push(name.clone());
            }
            for name in &ctx.analysis.props {
                if !fields.contains(name) {
                    fields.push(name.clone());
                }
            }
            let mut parts = Vec::new();
            for name in fields {
                let local = match self.resolve(&name, Span::NONE)? {
                    Resolved::Local(l) => l.php,
                    _ => continue,
                };
                parts.push(format!("{} => {local}", php_str(&name)));
            }
            extra.push(format!(
                "'data-wp-context' => \\TypedPress\\Islands::context({}, [{}])",
                php_str(&self.client.as_ref().expect("client").analysis.namespace),
                parts.join(", ")
            ));
            let ctx = self.client.as_ref().expect("client");
            for (target, event, action) in &ctx.analysis.events {
                extra.push(format!(
                    "{} => {}",
                    php_str(&format!("data-wp-on-{target}--{event}")),
                    php_str(&format!("actions.{action}"))
                ));
            }
            for (name, _) in &ctx.analysis.watch {
                extra.push(format!(
                    "{} => {}",
                    php_str(&format!("data-wp-watch---{name}")),
                    php_str(&format!("callbacks.{name}"))
                ));
            }
            for (name, _) in &ctx.analysis.init {
                extra.push(format!(
                    "{} => {}",
                    php_str(&format!("data-wp-init---{name}")),
                    php_str(&format!("callbacks.{name}"))
                ));
            }
            for (field, ..) in &ctx.analysis.live {
                extra.push(format!(
                    "{} => {}",
                    php_str(&format!("data-wp-init---live-{field}")),
                    php_str(&format!("callbacks.live_{field}"))
                ));
            }
        }
        for attr in &jsx.attrs {
            let JsxAttr::Named { name, value, span } = attr else {
                continue;
            };
            let folded = name.to_ascii_lowercase();
            let ctx = self.client.as_mut().expect("client");
            if folded.starts_with("on") && folded.len() > 2 {
                let event = folded[2..].to_string();
                let action = ctx.analysis.handler(value, &event)?;
                extra.push(format!(
                    "{} => {}",
                    php_str(&format!("data-wp-on--{event}")),
                    php_str(&format!("actions.{action}"))
                ));
                continue;
            }
            // `ref={box}` names the element to its `useRef()`: the store finds it by this
            // attribute, inside the component's own root.
            if name == "ref" {
                let attached = match &value.kind {
                    ExprKind::Ident(n) if ctx.analysis.bindings.get(n) == Some(&Binding::Ref) => n.clone(),
                    _ => {
                        return Err(refuse(
                            *span,
                            "`ref` takes a `useRef()` of this component",
                            "write `const box = useRef()` and `<div ref={box}>`",
                        ));
                    }
                };
                let place = (span.start, span.end);
                if *ctx.analysis.refs.entry(attached.clone()).or_insert(place) != place {
                    return Err(refuse(
                        *span,
                        format!("`{attached}` is already attached to an element"),
                        "give each element its own `useRef()`",
                    ));
                }
                extra.push(format!("'data-tp-ref' => {}", php_str(&attached)));
                continue;
            }
            // `key` keys React's reconciliation and never reaches the HTML; a list's key goes to
            // its template's `data-wp-each-key`.
            if name == "key" {
                continue;
            }
            if !ctx.analysis.reactive(value) {
                continue;
            }
            let reference = self.client_reference(value)?;
            match name.as_str() {
                "className" if matches!(value.kind, ExprKind::Object(_)) => {
                    let ExprKind::Object(props) = &value.kind else {
                        unreachable!()
                    };
                    for p in props {
                        if let Prop::KV(PropKey::Static(class), v) = p {
                            let r = self.client_reference(v)?;
                            extra.push(format!(
                                "{} => {}",
                                php_str(&format!("data-wp-class--{class}")),
                                php_str(&r)
                            ));
                        }
                    }
                }
                "style" if matches!(value.kind, ExprKind::Object(_)) => {
                    let ExprKind::Object(props) = &value.kind else {
                        unreachable!()
                    };
                    for p in props {
                        if let Prop::KV(PropKey::Static(property), v) = p
                            && self.client.as_ref().expect("client").analysis.reactive(v)
                        {
                            let css: String = property
                                .chars()
                                .flat_map(|c| {
                                    if c.is_ascii_uppercase() {
                                        vec!['-', c.to_ascii_lowercase()]
                                    } else {
                                        vec![c]
                                    }
                                })
                                .collect();
                            let r = self.client_reference(v)?;
                            extra.push(format!(
                                "{} => {}",
                                php_str(&format!("data-wp-style--{css}")),
                                php_str(&r)
                            ));
                        }
                    }
                }
                _ => {
                    let attribute = match name.as_str() {
                        "className" => "class".to_string(),
                        "htmlFor" => "for".to_string(),
                        other => other.to_string(),
                    };
                    let _ = span;
                    extra.push(format!(
                        "{} => {}",
                        php_str(&format!("data-wp-bind--{attribute}")),
                        php_str(&reference)
                    ));
                }
            }
        }
        Ok(extra)
    }

    /// A reactive markup expression as one directive reference: a bare state/prop/derived name
    /// reads directly; anything else becomes a generated derived getter.
    pub(super) fn client_reference(&mut self, value: &Expr) -> Result<String> {
        let ctx = self.client.as_mut().expect("client");
        if let ExprKind::Ident(n) = &value.kind
            && let Some(r) = ctx.analysis.reference(n)
        {
            return Ok(r);
        }
        let name = format!("value{}", ctx.analysis.derived.len() + 1);
        ctx.analysis.derived.push((name.clone(), value.clone()));
        Ok(format!("state.{name}"))
    }

    /// A child expression of a client element: dynamic text gets `data-wp-text`. When it shares
    /// its parent with other children, it is wrapped in a `<span>` that carries only the directive
    /// (the record's rule), so the browser can replace exactly that text.
    pub(super) fn client_text(&mut self, child: &Expr, php: String, alone: bool) -> Result<(String, Option<String>)> {
        let Some(ctx) = self.client.as_ref() else {
            return Ok((php, None));
        };
        if !ctx.analysis.reactive(child) {
            return Ok((php, None));
        }
        let php = if ctx.template { "''".to_string() } else { php };
        let r = self.client_reference(child)?;
        if alone {
            return Ok((php, Some(r)));
        }
        // `data-tp-text` marks the span as the compiler's, not the author's.
        Ok((
            format!(
                "\\TypedPress\\Html::element('span', ['data-wp-text' => {}, 'data-tp-text' => ''], {php})",
                php_str(&r)
            ),
            None,
        ))
    }
}

fn clone_stmt(s: &Stmt) -> Stmt {
    match s {
        Stmt::Decl {
            kind,
            pat,
            ann,
            init,
            span,
        } => Stmt::Decl {
            kind: *kind,
            pat: pat.clone(),
            ann: ann.clone(),
            init: init.clone(),
            span: *span,
        },
        Stmt::Return(e, span) => Stmt::Return(e.clone(), *span),
        Stmt::TypeAlias { name, params, ty } => Stmt::TypeAlias {
            name: name.clone(),
            params: params.clone(),
            ty: ty.clone(),
        },
        _ => unreachable!("analysis admits only declarations, hooks and the return"),
    }
}

/// A list or conditional child of a client element that the browser re-renders when the state it
/// reads changes (rendering.md §4.1): `items.map((item) => <li>…</li>)`, `cond && <el/>`,
/// `cond ? <a/> : <b/>`.
pub(super) enum Reactive<'e> {
    /// `list.map((name) => <el/>)`: one `<el>` per item, keyed by its `key` attribute.
    List {
        list: &'e Expr,
        item: &'e str,
        item_span: Span,
        body: &'e Expr,
    },
    /// `cond && <el/>`, `cond ? <a/> : <b/>` (an absent branch renders nothing).
    Cond {
        test: &'e Expr,
        yes: Option<&'e Expr>,
        no: Option<&'e Expr>,
    },
}

/// The element a branch or a list item renders: one intrinsic JSX element, else None.
fn element(e: &Expr) -> Option<&Jsx> {
    match &e.kind {
        ExprKind::Jsx(j) if matches!(j.kind, JsxKind::Intrinsic(_)) => Some(j),
        ExprKind::As(inner, _) | ExprKind::NonNull(inner) => element(inner),
        _ => None,
    }
}

/// Whether an expression holds JSX anywhere.
fn has_jsx(e: &Expr) -> bool {
    let mut found = false;
    crate::client_component::visit(e, &mut |x| found |= matches!(x.kind, ExprKind::Jsx(_)));
    found
}

/// A branch that renders nothing: `null`, `undefined` or `false`.
fn nothing(e: &Expr) -> bool {
    matches!(e.kind, ExprKind::Null | ExprKind::Undefined | ExprKind::Bool(false))
}

impl Compiler<'_, '_> {
    /// Whether a child of a client element is a list or conditional the browser must re-render,
    /// and its parts. Lists and conditionals that read nothing reactive render once, on the server.
    pub(super) fn client_reactive<'e>(&self, child: &'e Expr) -> Result<Option<Reactive<'e>>> {
        let Some(ctx) = self.client.as_ref() else {
            return Ok(None);
        };
        let a = &ctx.analysis;
        match &child.kind {
            ExprKind::Call {
                callee,
                args,
                optional: false,
                ..
            } => {
                let ExprKind::Member {
                    obj,
                    prop,
                    optional: false,
                    ..
                } = &callee.kind
                else {
                    return Ok(None);
                };
                if prop != "map" || !a.reactive(obj) {
                    return Ok(None);
                }
                let [ArrEl::Item(f)] = args.as_slice() else {
                    return Err(refuse(
                        child.span,
                        "a list's `map` takes one callback",
                        "write `items.map((item) => <li key={item.id}>…</li>)`",
                    ));
                };
                let ExprKind::Func(f) = &f.kind else {
                    return Err(refuse(
                        f.span,
                        "a list's `map` callback is an arrow written in place",
                        "write `items.map((item) => <li key={item.id}>…</li>)`",
                    ));
                };
                let (item, item_span) = match f.params.as_slice() {
                    [p] if matches!(&p.pat, Pat::Id(..)) && p.default.is_none() && !p.rest => {
                        let Pat::Id(n, span) = &p.pat else { unreachable!() };
                        (n.as_str(), *span)
                    }
                    _ => {
                        return Err(refuse(
                            f.span,
                            "a reactive list's callback takes one item",
                            "write `items.map((item) => …)`; the browser re-renders it without the index",
                        ));
                    }
                };
                let FnBody::Expr(body) = &f.body else {
                    return Err(refuse(
                        f.span,
                        "a reactive list's callback returns its element directly",
                        "write `(item) => <li key={item.id}>{item.title}</li>`",
                    ));
                };
                if element(body).is_none() {
                    return Err(refuse(
                        body.span,
                        "each item of a reactive list is one element",
                        "return one element per item, e.g. `<li key={item.id}>…</li>`",
                    ));
                }
                // Another list's item of the same name is fine: each template binds its own.
                if a.bindings.get(item).is_some_and(|b| !matches!(b, Binding::Item)) {
                    return Err(refuse(
                        f.span,
                        format!("the item `{item}` hides a value of the component"),
                        "name the item differently",
                    ));
                }
                Ok(Some(Reactive::List {
                    list: obj,
                    item,
                    item_span,
                    body,
                }))
            }
            // Only markup makes a conditional structural; `{on ? "on" : "off"}` is reactive text.
            ExprKind::Logical(LogOp::And, test, yes) if a.reactive(test) && has_jsx(yes) => {
                if element(yes).is_none() {
                    return Err(refuse(
                        yes.span,
                        "a reactive `&&` renders one element",
                        "write `{open && <p>…</p>}`, or make the condition server-only",
                    ));
                }
                Ok(Some(Reactive::Cond {
                    test,
                    yes: Some(yes),
                    no: None,
                }))
            }
            ExprKind::Cond(test, yes, no) if a.reactive(test) && (has_jsx(yes) || has_jsx(no)) => {
                let branch = |b: &'e Expr| -> Result<Option<&'e Expr>> {
                    if nothing(b) {
                        return Ok(None);
                    }
                    if element(b).is_none() {
                        return Err(refuse(
                            b.span,
                            "each branch of a reactive `?:` is one element or `null`",
                            "write `{open ? <b>open</b> : <i>closed</i>}`",
                        ));
                    }
                    Ok(Some(b))
                };
                Ok(Some(Reactive::Cond {
                    test,
                    yes: branch(yes)?,
                    no: branch(no)?,
                }))
            }
            _ => Ok(None),
        }
    }

    /// The PHP for a reactive list or conditional: what the server renders now, each top-level
    /// element marked `data-wp-each-child`, followed by the `<template>` the browser renders from.
    ///
    /// A conditional is a list of zero or one items: `state.valueN` is `[1]` while it shows and
    /// `[]` while it doesn't, so one directive covers both. A `?:` is two such lists.
    pub(super) fn client_reactive_php(&mut self, child: &Expr, reactive: Reactive<'_>) -> Result<String> {
        match reactive {
            Reactive::List {
                list,
                item,
                item_span,
                body,
            } => {
                let jsx = element(body).expect("checked");
                let key = jsx.attrs.iter().find_map(|a| match a {
                    JsxAttr::Named { name, value, .. } if name == "key" => Some(value),
                    _ => None,
                });
                let Some(key) = key else {
                    return Err(refuse(
                        body.span,
                        "each item of a reactive list needs a `key`",
                        "write `<li key={item.id}>`: the browser keeps each item's element by its key",
                    ));
                };
                let path = self.client_reference(list)?;
                // The key, as the browser evaluates it: an expression on `context.<item>`.
                // The item stays bound for the rest of the component: derived getters that read it
                // (`state.value3` for `book.title`) are written into the store after the markup.
                let ctx = self.client.as_mut().expect("client");
                ctx.analysis.bindings.insert(item.to_string(), Binding::Item);
                let key_ref = self.client_reference(key)?;
                self.client_each(child, (&path, &key_ref), (list, item, item_span, body))
            }
            Reactive::Cond { test, yes, no } => {
                let mut out = Vec::new();
                for (branch, negate) in [(yes, false), (no, true)] {
                    let Some(branch) = branch else { continue };
                    let shown = Expr {
                        kind: ExprKind::Cond(
                            Box::new(if negate {
                                Expr {
                                    kind: ExprKind::Unary(UnOp::Not, Box::new(test.clone())),
                                    span: test.span,
                                }
                            } else {
                                test.clone()
                            }),
                            Box::new(Expr {
                                kind: ExprKind::Array(vec![ArrEl::Item(Expr {
                                    kind: ExprKind::Num(1.0),
                                    span: test.span,
                                })]),
                                span: test.span,
                            }),
                            Box::new(Expr {
                                kind: ExprKind::Array(vec![]),
                                span: test.span,
                            }),
                        ),
                        span: test.span,
                    };
                    let path = self.client_reference(&shown)?;
                    // The server renders the branch the condition picks now, as the expression
                    // would; inside another template the browser picks it.
                    let inner = self.client.as_ref().expect("client").template;
                    let php_test = if inner {
                        "false".to_string()
                    } else {
                        let v = self.expr(test)?;
                        let v = self.finish_value(v, test.span)?;
                        let t = self.truthy(&v);
                        if negate { format!("!({t})") } else { t }
                    };
                    let rendered = if inner {
                        "null".to_string()
                    } else {
                        let v = self.expr(branch)?;
                        self.finish_value(v, branch.span)?.php
                    };
                    let template = self.client_template(branch, None)?;
                    out.push(format!(
                        "\\TypedPress\\Html::children(({php_test}) ? \\TypedPress\\Islands::eachChild({}, {}) : null, \\TypedPress\\Islands::template({}, {}, {template}, 'context.firstShown'))",
                        rendered,
                        php_str(&format!("{}::{}", self.client.as_ref().expect("client").analysis.namespace, path)),
                        php_str("data-wp-each--tp-shown"),
                        php_str(&path),
                    ));
                }
                Ok(format!("\\TypedPress\\Html::children({})", out.join(", ")))
            }
        }
    }

    /// The `<template>` content for a list item or a branch: the element compiled with every value
    /// the browser computes left to its directive. `item` is the list item's name, bound for the
    /// directives (`context.<item>`) while the template compiles.
    fn client_template(&mut self, body: &Expr, item: Option<(&str, Ty, Span)>) -> Result<String> {
        let ctx = self.client.as_mut().expect("client");
        let was = std::mem::replace(&mut ctx.template, true);
        if let Some((name, _, _)) = &item {
            ctx.analysis.bindings.insert(name.to_string(), Binding::Item);
        }
        // The item is a PHP local of the list's element type, never read: every value that reads
        // it is the browser's, so the template leaves it empty.
        self.fx().scopes.push(HashMap::new());
        let prelude = match &item {
            Some((name, ty, span)) => format!(
                "(static fn ({}) => ",
                self.declare_local(name, ty.clone(), false, *span)
            ),
            None => String::new(),
        };
        let result = self.expr(body).and_then(|v| self.finish_value(v, body.span));
        self.fx().scopes.pop();
        let ctx = self.client.as_mut().expect("client");
        ctx.template = was;
        let body = result?.wrapped();
        Ok(if prelude.is_empty() {
            body
        } else {
            format!("{prelude}{body})(null)")
        })
    }

    /// A reactive list: the server renders every item now, marked as the template's children,
    /// then the template.
    fn client_each(
        &mut self,
        child: &Expr,
        (path, key): (&str, &str),
        (list, item, item_span, body): (&Expr, &str, Span, &Expr),
    ) -> Result<String> {
        // Inside another template (a list in a list item) the browser renders the items too.
        let inner = self.client.as_ref().expect("client").template;
        let rendered = if inner {
            "null".to_string()
        } else {
            let v = self.expr(child)?;
            self.finish_value(v, child.span)?.php
        };
        let l = self.expr(list)?;
        let l = self.finish_value(l, list.span)?;
        let item_ty = match l.ty.non_null() {
            Ty::Array(inner) | Ty::NonEmptyArray(inner) => (*inner).clone(),
            Ty::Tuple(items) | Ty::ArrayLiteral(items) => Ty::union(items.clone()),
            other => {
                return Err(refuse(
                    list.span,
                    format!("a reactive list maps an array, not {}", other.describe()),
                    "keep the list in state or a prop typed as an array",
                ));
            }
        };
        let template = self.client_template(body, Some((item, item_ty, item_span)))?;
        let namespace = self.client.as_ref().expect("client").analysis.namespace.clone();
        let item_attr = item
            .chars()
            .flat_map(|c| {
                if c.is_ascii_uppercase() {
                    vec!['-', c.to_ascii_lowercase()]
                } else {
                    vec![c]
                }
            })
            .collect::<String>();
        Ok(format!(
            "\\TypedPress\\Html::children(\\TypedPress\\Islands::eachChild({}, {}), \\TypedPress\\Islands::template({}, {}, {template}, {}))",
            rendered,
            php_str(&format!("{namespace}::{path}")),
            php_str(&format!("data-wp-each--{item_attr}")),
            php_str(path),
            php_str(key),
        ))
    }

    /// A `useLive` field's first value: the read answered now, as the visitor, through the same
    /// evaluation the subscription endpoint runs (`TypedPress\Live\Module::evaluate`), with the read's
    /// descriptor and token beside it for the browser's subscription. Typed `{ data, error,
    /// loading }`, with `data` as the kind query API types the read.
    pub(super) fn client_live_seed(&mut self, field: &str, span: Span) -> Result<Out> {
        let (kind, query, enabled) = {
            let ctx = self.client.as_ref().expect("client");
            let (_, kind, query, enabled) = ctx.analysis.live.iter().find(|(f, ..)| f == field).expect("live field");
            (kind.clone(), query.clone(), enabled.clone())
        };
        let Some((collection, _)) = self
            .schema
            .collections
            .iter()
            .find(|(name, c)| *name == &kind || c.key == kind)
        else {
            return err_help(
                span,
                format!("`useLive` reads a kind; there is no `{kind}`"),
                "name a collection or table the app exports",
            );
        };
        let row = types::row(self.schema, collection);
        let q = self.expr(&query)?;
        let q = self.finish_value(q, query.span)?;
        let on = self.expr(&enabled)?;
        let on = self.finish_value(on, enabled.span)?;
        let on = self.truthy(&on);
        let mut error = IndexMap::new();
        error.insert("code".to_string(), (Ty::Str, false));
        error.insert("message".to_string(), (Ty::Str, false));
        let mut state = IndexMap::new();
        state.insert(
            "data".to_string(),
            (Ty::union([Ty::Array(Box::new(row)), Ty::Undefined]), false),
        );
        state.insert("error".to_string(), (Ty::union([Ty::Obj(error), Ty::Undefined]), false));
        state.insert("loading".to_string(), (Ty::Bool, false));
        Ok(Out::atom(
            format!("\\TypedPress\\Islands::live({}, {}, {on})", php_str(&kind), q.php),
            Ty::Obj(state),
        ))
    }
}
