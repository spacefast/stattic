//! Untyped JavaScript uses the same value model with checked runtime dispatch.
//! This domain is distinct from authored TypeScript `unknown`.
use super::*;

impl Compiler<'_, '_> {
    pub(super) fn error_constructor(&mut self, name: &str, args: &[ArrEl]) -> Result<Out> {
        let argv = self.javascript_args(args)?;
        Ok(Out::atom(
            format!("\\Zero\\Js::dynamicConstruct({}, {argv})", php_str(name)),
            Ty::Error,
        ))
    }

    pub(super) fn set_var_iteration_type(&mut self, name: &str, ty: &Ty, span: Span) -> Result<()> {
        for scope in self.fx().scopes.iter_mut().rev() {
            if let Some(local) = scope.get_mut(name) {
                if local.constraint == Ty::Unknown {
                    local.constraint = ty.clone();
                } else if !assignable(ty, &local.constraint) {
                    return err(
                        span,
                        format!(
                            "loop values are {}, but the variable expects {}",
                            ty.describe(),
                            local.constraint.describe()
                        ),
                    );
                }
                if local.constraint != Ty::Dynamic {
                    local.ty = ty.clone();
                }
                break;
            }
        }
        Ok(())
    }
    pub(super) fn javascript_module(&self, module: usize) -> bool {
        self.ev_program().modules[module]
            .path
            .extension()
            .and_then(|s| s.to_str())
            .is_some_and(|s| matches!(s, "js" | "mjs" | "cjs"))
    }

    pub(super) fn javascript_binary(&self, op: BinOp, a: Out, b: Out, _span: Span) -> Result<Out> {
        let (operator, ty) = match op {
            BinOp::Add => ("+", Ty::Dynamic),
            BinOp::Sub => ("-", Ty::Num),
            BinOp::Mul => ("*", Ty::Num),
            BinOp::Div => ("/", Ty::Num),
            BinOp::Rem => ("%", Ty::Num),
            BinOp::Exp => ("**", Ty::Num),
            BinOp::Lt => ("<", Ty::Bool),
            BinOp::Le => ("<=", Ty::Bool),
            BinOp::Gt => (">", Ty::Bool),
            BinOp::Ge => (">=", Ty::Bool),
            BinOp::Eq => ("===", Ty::Bool),
            BinOp::Ne => ("!==", Ty::Bool),
            BinOp::LooseEq => ("==", Ty::Bool),
            BinOp::LooseNe => ("!=", Ty::Bool),
        };
        Ok(Out::atom(
            format!("\\Zero\\Js::dynamicBinary({}, {}, {})", php_str(operator), a.php, b.php),
            ty,
        ))
    }

    pub(super) fn javascript_get(&mut self, receiver: Out, key: Out, optional: bool) -> Out {
        let source = if optional {
            let tmp = self.tmp_var();
            format!(
                "(\\Zero\\Js::isNullish({tmp} = {}) ? \\Zero\\Js::undefined() : \\Zero\\Js::dynamicGet({tmp}, {}))",
                receiver.php, key.php
            )
        } else {
            format!("\\Zero\\Js::dynamicGet({}, {})", receiver.php, key.php)
        };
        Out::atom(source, Ty::Dynamic)
    }

    pub(super) fn javascript_place(&self, receiver: Out, key: Out) -> AssignmentPlace {
        AssignmentPlace {
            read: Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", receiver.php, key.php),
                Ty::Dynamic,
            ),
            constraint: Ty::Dynamic,
            target: Some(WriteTarget {
                receiver: receiver.php,
                key: key.php,
                kind: WriteKind::Dynamic,
            }),
        }
    }

    pub(super) fn javascript_args(&mut self, args: &[ArrEl]) -> Result<String> {
        let mut parts = Vec::new();
        for arg in args {
            match arg {
                ArrEl::Item(expr) => {
                    let value = if matches!(expr.kind, ExprKind::Undefined) {
                        Out::atom("\\Zero\\Js::undefined()", Ty::Dynamic)
                    } else {
                        self.expr(expr)?
                    };
                    let value = self.finish_value(value, expr.span)?;
                    parts.push(value.php);
                }
                ArrEl::Spread(expr) => {
                    let value = self.expr(expr)?;
                    parts.push(format!("...(\\Zero\\Js::dynamicIterable({}))->a", value.php));
                }
            }
        }
        Ok(format!("new \\Zero\\JsArray([{}])", parts.join(", ")))
    }

    pub(super) fn javascript_arguments(&mut self, f: &Func, indent: usize) -> Result<String> {
        let mut source = String::new();
        let mut bindings = Vec::new();
        for parameter in &f.params {
            parameter.pat.bindings(&mut bindings);
        }
        if !f.is_arrow
            && !bindings.iter().any(|(name, _)| *name == "arguments")
            && !self.fx().scopes[0].contains_key("arguments")
        {
            let ty = if self.javascript_module(f.module) {
                Ty::Dynamic
            } else {
                Ty::Arguments
            };
            let php = self.declare_local("arguments", ty, true, arguments_binding(f));
            let args = if f
                .params
                .first()
                .is_some_and(|param| matches!(&param.pat, Pat::Id(name, _) if name == "this"))
            {
                "array_slice(func_get_args(), 1)"
            } else {
                "func_get_args()"
            };
            let _ = writeln!(source, "{}{php} = \\Zero\\Js::arguments({args});", pad(indent));
        }
        Ok(source)
    }

    pub(super) fn javascript_prologue(&mut self, f: &Func, indent: usize) -> Result<String> {
        let mut source = self.javascript_arguments(f, indent)?;
        if let FnBody::Block(body) = &f.body {
            let mut declarations = Vec::new();
            collect_var_declarations(body, &mut declarations);
            let mut annotations = HashMap::new();
            for declaration in &declarations {
                if let Some(annotation) = declaration.annotation {
                    annotations.entry(declaration.name).or_insert(annotation);
                }
            }
            for VarBinding { name, span, .. } in declarations {
                if self.fx().scopes[0].contains_key(name) {
                    continue;
                }
                let dynamic = self.javascript_module(f.module);
                let annotation = annotations.get(name);
                let constraint = if dynamic {
                    Ty::Dynamic
                } else if let Some(annotation) = annotation {
                    self.resolve_runtime_ann(annotation, f.module)?
                } else {
                    Ty::Unknown
                };
                let php = self.declare_local(name, constraint, true, span);
                let value = "\\Zero\\Js::undefined()";
                let _ = writeln!(source, "{}{php} = {value};", pad(indent));
                if !dynamic {
                    self.set_local_ty(name, Ty::Undefined);
                }
            }
        }
        Ok(source)
    }
}

pub(super) struct VarBinding<'a> {
    pub name: &'a str,
    pub span: Span,
    annotation: Option<&'a TyAnn>,
}

fn var_pattern<'a>(pat: &'a Pat, annotation: Option<&'a TyAnn>, out: &mut Vec<VarBinding<'a>>) {
    let mut bindings = Vec::new();
    pat.bindings(&mut bindings);
    out.extend(
        bindings
            .into_iter()
            .map(|(name, span)| VarBinding { name, span, annotation }),
    );
}

pub(super) fn collect_var_declarations<'a>(stmts: &'a [Stmt], out: &mut Vec<VarBinding<'a>>) {
    for stmt in stmts {
        match stmt {
            Stmt::Decl {
                kind: DeclKind::Var,
                pat,
                ann,
                ..
            } => var_pattern(pat, if matches!(pat, Pat::Id(..)) { ann.as_ref() } else { None }, out),
            Stmt::Block(body) | Stmt::While(_, body) => collect_var_declarations(body, out),
            Stmt::If(_, yes, no) => {
                collect_var_declarations(yes, out);
                if let Some(no) = no {
                    collect_var_declarations(no, out);
                }
            }
            Stmt::For { init, body, .. } => {
                if let Some(init) = init {
                    collect_var_declarations(std::slice::from_ref(init.as_ref()), out);
                }
                collect_var_declarations(body, out);
            }
            Stmt::ForOf { kind, pat, body, .. } => {
                if *kind == DeclKind::Var {
                    var_pattern(pat, None, out);
                }
                collect_var_declarations(body, out);
            }
            Stmt::ForIn { kind, target, body, .. } => {
                if *kind == Some(DeclKind::Var)
                    && let ExprKind::Ident(name) = &target.kind
                {
                    out.push(VarBinding {
                        name,
                        span: target.span,
                        annotation: None,
                    });
                }
                collect_var_declarations(body, out);
            }
            Stmt::Try {
                block,
                handler,
                finalizer,
                ..
            } => {
                collect_var_declarations(block, out);
                if let Some(body) = handler {
                    collect_var_declarations(body, out);
                }
                if let Some(body) = finalizer {
                    collect_var_declarations(body, out);
                }
            }
            Stmt::Switch { cases, .. } => {
                for (_, body) in cases {
                    collect_var_declarations(body, out);
                }
            }
            _ => {}
        }
    }
}

/// An implicit local has no source token; keep it distinct from the function value.
pub(super) fn arguments_binding(f: &Func) -> Span {
    Span {
        start: f.span.end,
        ..f.span
    }
}
