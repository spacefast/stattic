//! A list whose only use is one inline map can project the fields read by its callback.
//! Other shapes keep the author's query and row type, including escapes to opaque code.

use crate::diag::Span;
use crate::ir::*;
use std::collections::BTreeSet;

pub(super) fn list_fields(f: &Func) -> Option<(Span, BTreeSet<String>)> {
    let FnBody::Block(stmts) = &f.body else { return None };
    let [
        before @ ..,
        Stmt::Decl {
            kind: DeclKind::Const,
            pat: Pat::Id(list, _),
            init: Some(Expr {
                kind: ExprKind::Await(query),
                ..
            }),
            ..
        },
        Stmt::Return(Some(rendered), _),
    ] = stmts.as_slice()
    else {
        return None;
    };
    let ExprKind::Call { callee, .. } = &query.kind else {
        return None;
    };
    let ExprKind::Member { prop, .. } = &callee.kind else {
        return None;
    };
    if !matches!(prop.as_str(), "list" | "findMany") {
        return None;
    }
    // In particular, no closure created before the query may capture the array.
    for stmt in before {
        let Stmt::Decl {
            kind: DeclKind::Const,
            pat: Pat::Id(_, _),
            init: Some(init),
            ..
        } = stmt
        else {
            return None;
        };
        Reads::new(list).expr(init)?;
    }
    let mut reads = Reads::new(list);
    reads.expr(rendered)?;
    if reads.maps != 1 || reads.fields.is_empty() {
        return None;
    }
    Some((query.span, reads.fields))
}

struct Reads<'a> {
    list: &'a str,
    item: Option<&'a str>,
    maps: usize,
    fields: BTreeSet<String>,
}

impl<'a> Reads<'a> {
    fn new(list: &'a str) -> Self {
        Self {
            list,
            item: None,
            maps: 0,
            fields: BTreeSet::new(),
        }
    }

    fn args(&mut self, args: &'a [ArrEl]) -> Option<()> {
        for arg in args {
            let ArrEl::Item(expr) = arg else { return None };
            self.expr(expr)?;
        }
        Some(())
    }

    fn expr(&mut self, expr: &'a Expr) -> Option<()> {
        match &expr.kind {
            ExprKind::Ident(name) if name == self.list || self.item == Some(name) => return None,
            ExprKind::Ident(_)
            | ExprKind::Str(_)
            | ExprKind::Num(_)
            | ExprKind::Bool(_)
            | ExprKind::Null
            | ExprKind::Undefined => {}
            ExprKind::Member { obj, prop, .. } if matches!(&obj.kind, ExprKind::Ident(name) if self.item == Some(name)) =>
            {
                self.fields.insert(prop.clone());
            }
            ExprKind::Member { obj, .. }
            | ExprKind::Await(obj)
            | ExprKind::NonNull(obj)
            | ExprKind::As(obj, _)
            | ExprKind::IsNullish(obj, _)
            | ExprKind::Unary(_, obj) => self.expr(obj)?,
            ExprKind::Binary(_, a, b) | ExprKind::Logical(_, a, b) => {
                self.expr(a)?;
                self.expr(b)?;
            }
            ExprKind::Cond(test, yes, no) => {
                self.expr(test)?;
                self.expr(yes)?;
                self.expr(no)?;
            }
            ExprKind::Array(args) => self.args(args)?,
            ExprKind::Template(_, exprs) => {
                for expr in exprs {
                    self.expr(expr)?;
                }
            }
            ExprKind::Object(props) => {
                for prop in props {
                    let Prop::KV(PropKey::Static(_), value) = prop else {
                        return None;
                    };
                    self.expr(value)?;
                }
            }
            ExprKind::Jsx(jsx) => {
                for expr in jsx.exprs() {
                    self.expr(expr)?;
                }
            }
            ExprKind::Call {
                callee,
                args,
                optional: false,
                ..
            } if self.item.is_none()
                && matches!(&callee.kind,
                    ExprKind::Member { obj, prop, optional: false, .. }
                        if prop == "map" && matches!(&obj.kind, ExprKind::Ident(name) if name == self.list)) =>
            {
                let [
                    ArrEl::Item(Expr {
                        kind: ExprKind::Func(callback),
                        ..
                    }),
                ] = args.as_slice()
                else {
                    return None;
                };
                let [param] = callback.params.as_slice() else {
                    return None;
                };
                let Pat::Id(item, _) = &param.pat else { return None };
                if !callback.is_arrow || param.default.is_some() || param.rest || item == self.list {
                    return None;
                }
                let rendered = match &callback.body {
                    FnBody::Expr(expr) => expr,
                    FnBody::Block(stmts) => match stmts.as_slice() {
                        [Stmt::Return(Some(expr), _)] => expr,
                        _ => return None,
                    },
                };
                self.maps += 1;
                self.item = Some(item);
                self.expr(rendered)?;
                self.item = None;
            }
            ExprKind::Call { callee, args, .. } => {
                self.expr(callee)?;
                self.args(args)?;
            }
            _ => return None,
        }
        Some(())
    }
}
