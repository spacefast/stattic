//! Lowers oxc's TypeScript AST into Zero's IR, enforcing the supported subset.
//! Every construct outside the subset is a build error that names the construct
//! and says what to write instead.

use crate::diag::{Diag, Result, Span};
use crate::ir::*;
use oxc_ast::ast as a;
use oxc_syntax::operator::{
    AssignmentOperator as AO, BinaryOperator as BO, LogicalOperator as LO, UnaryOperator as UO, UpdateOperator,
};
use std::rc::Rc;

pub struct Lowerer {
    pub file: u32,
    pub module: usize,
    pub class_context: std::cell::Cell<bool>,
    pub super_call: std::cell::Cell<bool>,
    /// Installed package TypeScript compiles like JavaScript: types are erased,
    /// not modelled. Syntax with runtime semantics is still lowered or refused.
    pub erase_types: bool,
    /// Names of the type declarations erased so far.
    pub erased_types: std::cell::RefCell<std::collections::HashSet<String>>,
}

impl Lowerer {
    fn sp(&self, s: oxc_span::Span) -> Span {
        Span {
            file: self.file,
            start: s.start,
            end: s.end,
        }
    }

    fn unsupported<T>(&self, s: oxc_span::Span, what: &str, help: &str) -> Result<T> {
        let mut d = Diag::new(self.sp(s), format!("Zero code doesn't support {what}")).code(
            if what.contains("class") || self.class_context.get() {
                crate::diag::Code::Classes
            } else {
                crate::diag::Code::Unsupported
            },
        );
        if !help.is_empty() {
            d = d.help(help);
        }
        Err(d)
    }

    /// A source annotation, or nothing when this module's types are erased.
    fn annotation(&self, t: Option<&oxc_allocator::Box<'_, a::TSTypeAnnotation<'_>>>) -> Result<Option<TyAnn>> {
        if self.erase_types {
            return Ok(None);
        }
        t.map(|t| self.ty(&t.type_annotation)).transpose()
    }

    fn binding_name(&self, name: &str, span: oxc_span::Span) -> Result<String> {
        if name == "undefined" {
            return self.unsupported(
                span,
                "bindings named `undefined`",
                "choose a different name; undefined is the built-in missing value",
            );
        }
        Ok(name.to_string())
    }

    pub fn program(&self, p: &a::Program<'_>) -> Result<Vec<Stmt>> {
        let mut out = Vec::new();
        for s in &p.body {
            self.top_stmt(s, &mut out)?;
        }
        Ok(out)
    }

    /// Recovery is for diagnostics only. Callers must reject the whole program
    /// if any declaration failed; an incomplete declaration is never retained.
    pub fn program_diagnostics(&self, p: &a::Program<'_>) -> (Vec<Stmt>, Vec<Diag>) {
        let mut out = Vec::new();
        let mut diagnostics = Vec::new();
        for statement in &p.body {
            let mut declaration = Vec::new();
            match self.top_stmt(statement, &mut declaration) {
                Ok(()) => out.extend(declaration),
                Err(diag) => diagnostics.push(diag.phase(crate::diag::Code::Unsupported)),
            }
        }
        (out, diagnostics)
    }

    fn top_stmt(&self, s: &a::Statement<'_>, out: &mut Vec<Stmt>) -> Result<()> {
        match s {
            a::Statement::ImportDeclaration(d) => {
                let type_only = d.import_kind == a::ImportOrExportKind::Type;
                let mut specs = Vec::new();
                for spec in d.specifiers.iter().flatten() {
                    match spec {
                        a::ImportDeclarationSpecifier::ImportSpecifier(s) => {
                            let imported = match &s.imported {
                                a::ModuleExportName::IdentifierName(n) => n.name.to_string(),
                                a::ModuleExportName::IdentifierReference(n) => n.name.to_string(),
                                a::ModuleExportName::StringLiteral(l) => l.value.to_string(),
                            };
                            let imported = if imported == "default" {
                                ImportName::Default
                            } else {
                                ImportName::Named(imported)
                            };
                            specs.push(ImportSpec {
                                imported,
                                local: self.binding_name(s.local.name.as_str(), s.local.span)?,
                                type_only: type_only || s.import_kind == a::ImportOrExportKind::Type,
                                span: self.sp(s.span),
                            });
                        }
                        a::ImportDeclarationSpecifier::ImportDefaultSpecifier(s) => specs.push(ImportSpec {
                            imported: ImportName::Default,
                            local: self.binding_name(s.local.name.as_str(), s.local.span)?,
                            type_only,
                            span: self.sp(s.span),
                        }),
                        a::ImportDeclarationSpecifier::ImportNamespaceSpecifier(s) => specs.push(ImportSpec {
                            imported: ImportName::Namespace,
                            local: self.binding_name(s.local.name.as_str(), s.local.span)?,
                            type_only,
                            span: self.sp(s.span),
                        }),
                    }
                }
                out.push(Stmt::Import {
                    source: d.source.value.to_string(),
                    specs,
                    type_only,
                    span: self.sp(d.span),
                });
            }
            a::Statement::ExportNamedDeclaration(d) => {
                if d.export_kind == a::ImportOrExportKind::Type {
                    return Ok(());
                }
                let mut names = Vec::new();
                for s in &d.specifiers {
                    if s.export_kind == a::ImportOrExportKind::Type {
                        continue;
                    }
                    names.push((self.export_name(&s.local), self.export_name(&s.exported)));
                }
                out.push(Stmt::ExportNames {
                    names,
                    from: None,
                    span: self.sp(d.span),
                });
            }
            a::Statement::ExportFromDeclaration(d) => {
                if d.export_kind == a::ImportOrExportKind::Type {
                    return Ok(());
                }
                let mut names = Vec::new();
                for s in &d.specifiers {
                    if s.export_kind == a::ImportOrExportKind::Type {
                        continue;
                    }
                    names.push((self.export_name(&s.local), self.export_name(&s.exported)));
                }
                out.push(Stmt::ExportNames {
                    names,
                    from: Some(d.source.value.to_string()),
                    span: self.sp(d.span),
                });
            }
            a::Statement::ExportAllDeclaration(d) => {
                if d.exported.is_some() {
                    return self.unsupported(
                        d.span,
                        "`export * as name`",
                        "import the module and re-export the names you need",
                    );
                }
                out.push(Stmt::ExportAll {
                    from: d.source.value.to_string(),
                    span: self.sp(d.span),
                });
            }
            a::Statement::ExportDeclaration(d) => {
                let mut inner = Vec::new();
                self.declaration(&d.declaration, &mut inner)?;
                out.extend(inner.into_iter().map(|s| Stmt::Exported(Box::new(s))));
            }
            a::Statement::ExportDefaultDeclaration(d) => {
                let e = match &d.declaration {
                    a::ExportDefaultDeclarationKind::FunctionDeclaration(f) => Expr {
                        span: self.sp(f.span),
                        kind: ExprKind::Func(self.function(f)?),
                    },
                    a::ExportDefaultDeclarationKind::ClassDeclaration(c) => {
                        return self.unsupported(
                            c.span,
                            "default-export class declarations",
                            "use a named module-level class and a named export",
                        );
                    }
                    a::ExportDefaultDeclarationKind::TSInterfaceDeclaration(_) => return Ok(()),
                    other => self.expr(other.to_expression())?,
                };
                out.push(Stmt::ExportDefault(e));
            }
            a::Statement::TSExportAssignment(d) => {
                return self.unsupported(d.span, "`export =`", "use `export default`");
            }
            other => self.inner_stmt(other, out)?,
        }
        Ok(())
    }

    fn export_name(&self, n: &a::ModuleExportName<'_>) -> String {
        match n {
            a::ModuleExportName::IdentifierName(n) => n.name.to_string(),
            a::ModuleExportName::IdentifierReference(n) => n.name.to_string(),
            a::ModuleExportName::StringLiteral(l) => l.value.to_string(),
        }
    }

    fn declaration(&self, d: &a::Declaration<'_>, out: &mut Vec<Stmt>) -> Result<()> {
        match d {
            a::Declaration::VariableDeclaration(v) => self.var_decl(v, out),
            a::Declaration::FunctionDeclaration(f) => {
                if f.body.is_none() {
                    return Ok(()); // overload signature
                }
                out.push(Stmt::Func(self.function(f)?));
                Ok(())
            }
            a::Declaration::ClassDeclaration(c) => {
                let class = self.class(c)?;
                out.push(Stmt::Decl {
                    kind: DeclKind::Const,
                    pat: Pat::Id(class.name.clone(), self.sp(c.span)),
                    ann: None,
                    init: Some(self.mk(c.span, ExprKind::Class(class))),
                    span: self.sp(c.span),
                });
                Ok(())
            }
            a::Declaration::TSTypeAliasDeclaration(t) if self.erase_types => {
                self.erased_types.borrow_mut().insert(t.id.name.to_string());
                Ok(())
            }
            a::Declaration::TSInterfaceDeclaration(i) if self.erase_types => {
                self.erased_types.borrow_mut().insert(i.id.name.to_string());
                Ok(())
            }
            a::Declaration::TSTypeAliasDeclaration(t) => {
                out.push(Stmt::TypeAlias {
                    name: t.id.name.to_string(),
                    params: self.alias_params(t.type_parameters.as_deref())?,
                    ty: self.ty(&t.type_annotation)?,
                });
                Ok(())
            }
            a::Declaration::TSInterfaceDeclaration(i) => {
                let mut parts = Vec::new();
                for base in &i.extends {
                    let args = base
                        .type_arguments
                        .as_ref()
                        .map(|args| args.params.iter().map(|t| self.ty(t)).collect::<Result<Vec<_>>>())
                        .transpose()?
                        .unwrap_or_default();
                    parts.push(TyAnn::Ref(self.type_name(&base.type_name), args, self.sp(base.span)));
                }
                parts.push(self.members(&i.body.body)?);
                out.push(Stmt::TypeAlias {
                    name: i.id.name.to_string(),
                    params: self.alias_params(i.type_parameters.as_deref())?,
                    ty: TyAnn::Intersection(parts, self.sp(i.span)),
                });
                Ok(())
            }
            a::Declaration::TSEnumDeclaration(e) => self.unsupported(
                e.span,
                "`enum`",
                "use a union of string literals: `type Unit = \"pcs\" | \"g\"`",
            ),
            a::Declaration::TSExternalModuleDeclaration(m) => {
                if m.declare {
                    Ok(())
                } else {
                    self.unsupported(m.span, "module declarations", "")
                }
            }
            a::Declaration::TSNamespaceDeclaration(m) => {
                if m.declare {
                    Ok(())
                } else {
                    self.unsupported(m.span, "namespaces", "use modules")
                }
            }
            a::Declaration::TSGlobalDeclaration(_) => Ok(()),
            a::Declaration::TSImportEqualsDeclaration(i) => self.unsupported(i.span, "`import x =`", "use `import`"),
        }
    }

    fn var_decl(&self, v: &a::VariableDeclaration<'_>, out: &mut Vec<Stmt>) -> Result<()> {
        if v.declare {
            return Ok(());
        }
        let kind = match v.kind {
            a::VariableDeclarationKind::Const => DeclKind::Const,
            a::VariableDeclarationKind::Let => DeclKind::Let,
            a::VariableDeclarationKind::Var => DeclKind::Var,
            _ => return self.unsupported(v.span, "`using` declarations", ""),
        };
        for d in &v.declarations {
            out.push(Stmt::Decl {
                kind,
                pat: self.pattern(&d.id)?,
                ann: self.annotation(d.type_annotation.as_ref())?,
                init: d.init.as_ref().map(|e| self.expr(e)).transpose()?,
                span: self.sp(d.span),
            });
        }
        Ok(())
    }

    fn alias_params(&self, t: Option<&a::TSTypeParameterDeclaration<'_>>) -> Result<Vec<TypeParam>> {
        t.map(|t| {
            t.params
                .iter()
                .map(|p| {
                    if p.r#in || p.out || p.r#const {
                        return self.unsupported(
                            p.span,
                            "variance or const type parameters",
                            "use an ordinary type parameter",
                        );
                    }
                    Ok(TypeParam {
                        name: p.name.name.to_string(),
                        constraint: p.constraint.as_ref().map(|t| self.ty(t)).transpose()?,
                        default: p.default.as_ref().map(|t| self.ty(t)).transpose()?,
                        span: self.sp(p.span),
                    })
                })
                .collect()
        })
        .unwrap_or_else(|| Ok(Vec::new()))
    }

    fn type_params(&self, t: Option<&a::TSTypeParameterDeclaration<'_>>) -> Vec<String> {
        if self.erase_types {
            return Vec::new();
        }
        t.map(|t| t.params.iter().map(|p| p.name.name.to_string()).collect())
            .unwrap_or_default()
    }

    fn block(&self, stmts: &[a::Statement<'_>]) -> Result<Vec<Stmt>> {
        let mut out = Vec::new();
        for s in stmts {
            self.inner_stmt(s, &mut out)?;
        }
        Ok(out)
    }

    fn inner_stmt(&self, s: &a::Statement<'_>, out: &mut Vec<Stmt>) -> Result<()> {
        match s {
            a::Statement::VariableDeclaration(v) => self.var_decl(v, out),
            a::Statement::FunctionDeclaration(f) => {
                if f.body.is_some() {
                    out.push(Stmt::Func(self.function(f)?));
                }
                Ok(())
            }
            a::Statement::TSTypeAliasDeclaration(_) | a::Statement::TSInterfaceDeclaration(_) => {
                self.declaration(s.as_declaration().expect("declaration"), out)
            }
            a::Statement::EmptyStatement(_) => Ok(()),
            other => {
                if let Some(d) = other.as_declaration() {
                    return self.declaration(d, out);
                }
                out.push(self.stmt(other)?);
                Ok(())
            }
        }
    }

    fn body_of(&self, s: &a::Statement<'_>) -> Result<Vec<Stmt>> {
        match s {
            a::Statement::BlockStatement(b) => self.block(&b.body),
            other => {
                let mut out = Vec::new();
                self.inner_stmt(other, &mut out)?;
                Ok(out)
            }
        }
    }

    fn stmt(&self, s: &a::Statement<'_>) -> Result<Stmt> {
        Ok(match s {
            a::Statement::BlockStatement(b) => Stmt::Block(self.block(&b.body)?),
            a::Statement::ExpressionStatement(e) => Stmt::Expr(self.expr(&e.expression)?),
            a::Statement::ReturnStatement(r) => {
                Stmt::Return(r.argument.as_ref().map(|e| self.expr(e)).transpose()?, self.sp(r.span))
            }
            a::Statement::IfStatement(i) => Stmt::If(
                self.expr(&i.test)?,
                self.body_of(&i.consequent)?,
                i.alternate.as_ref().map(|s| self.body_of(s)).transpose()?,
            ),
            a::Statement::ForStatement(f) => {
                let init = match &f.init {
                    None => None,
                    Some(a::ForStatementInit::VariableDeclaration(v)) => {
                        let mut decls = Vec::new();
                        self.var_decl(v, &mut decls)?;
                        if decls.len() > 1 && v.kind != a::VariableDeclarationKind::Var {
                            return self.unsupported(
                                v.span,
                                "several declarations in a `for` header",
                                "declare one loop variable",
                            );
                        }
                        Some(Box::new(if decls.len() == 1 {
                            decls.pop().expect("one decl")
                        } else {
                            Stmt::Block(decls)
                        }))
                    }
                    Some(e) => Some(Box::new(Stmt::Expr(self.expr(e.to_expression())?))),
                };
                Stmt::For {
                    init,
                    test: f.test.as_ref().map(|e| self.expr(e)).transpose()?,
                    update: f.update.as_ref().map(|e| self.expr(e)).transpose()?,
                    body: self.body_of(&f.body)?,
                    span: self.sp(f.span),
                }
            }
            a::Statement::ForOfStatement(f) => {
                let a::ForStatementLeft::VariableDeclaration(v) = &f.left else {
                    return self.unsupported(
                        f.span,
                        "assigning to an existing variable in `for ... of`",
                        "declare it: `for (const x of xs)`",
                    );
                };
                let kind = match v.kind {
                    a::VariableDeclarationKind::Const => DeclKind::Const,
                    a::VariableDeclarationKind::Let => DeclKind::Let,
                    a::VariableDeclarationKind::Var => DeclKind::Var,
                    _ => return self.unsupported(v.span, "this loop declaration", "use `const`"),
                };
                Stmt::ForOf {
                    is_await: f.r#await,
                    kind,
                    pat: self.pattern(&v.declarations[0].id)?,
                    iter: self.expr(&f.right)?,
                    body: self.body_of(&f.body)?,
                    span: self.sp(f.span),
                }
            }
            a::Statement::ForInStatement(f) => {
                let (kind, target) = match &f.left {
                    a::ForStatementLeft::VariableDeclaration(v) => {
                        let kind = match v.kind {
                            a::VariableDeclarationKind::Const => DeclKind::Const,
                            a::VariableDeclarationKind::Let => DeclKind::Let,
                            a::VariableDeclarationKind::Var => DeclKind::Var,
                            _ => return self.unsupported(v.span, "this loop declaration", "use `const`"),
                        };
                        let a::BindingPattern::BindingIdentifier(id) = &v.declarations[0].id else {
                            return self.unsupported(
                                v.span,
                                "destructuring in `for ... in`",
                                "declare a key identifier",
                            );
                        };
                        (
                            Some(kind),
                            self.mk(id.span, ExprKind::Ident(self.binding_name(id.name.as_str(), id.span)?)),
                        )
                    }
                    target => {
                        let Some(target) = target.as_simple_assignment_target() else {
                            return self.unsupported(
                                f.span,
                                "destructuring in `for ... in`",
                                "assign a key identifier",
                            );
                        };
                        (None, self.simple_target(target)?)
                    }
                };
                Stmt::ForIn {
                    kind,
                    target,
                    iter: self.expr(&f.right)?,
                    body: self.body_of(&f.body)?,
                    span: self.sp(f.span),
                }
            }
            a::Statement::WhileStatement(w) => Stmt::While(self.expr(&w.test)?, self.body_of(&w.body)?),
            a::Statement::DoWhileStatement(d) => Stmt::DoWhile(self.body_of(&d.body)?, self.expr(&d.test)?),
            a::Statement::BreakStatement(b) => {
                Stmt::Break(b.label.as_ref().map(|l| l.name.to_string()), self.sp(b.span))
            }
            a::Statement::ContinueStatement(c) => {
                Stmt::Continue(c.label.as_ref().map(|l| l.name.to_string()), self.sp(c.span))
            }
            a::Statement::ThrowStatement(t) => Stmt::Throw(self.expr(&t.argument)?),
            a::Statement::TryStatement(t) => {
                let (param, handler) = match &t.handler {
                    None => (None, None),
                    Some(h) => {
                        let param = match &h.param {
                            None => None,
                            Some(p) => match &p.pattern {
                                a::BindingPattern::BindingIdentifier(id) => {
                                    Some((self.binding_name(id.name.as_str(), id.span)?, self.sp(id.span)))
                                }
                                _ => {
                                    return self.unsupported(
                                        p.span,
                                        "destructuring a caught error",
                                        "catch `e` and read `e.message`",
                                    );
                                }
                            },
                        };
                        (param, Some(self.block(&h.body.body)?))
                    }
                };
                Stmt::Try {
                    block: self.block(&t.block.body)?,
                    param,
                    handler,
                    finalizer: t.finalizer.as_ref().map(|f| self.block(&f.body)).transpose()?,
                }
            }
            a::Statement::SwitchStatement(s) => {
                let mut cases = Vec::new();
                for c in &s.cases {
                    let body = self.block(&c.consequent)?;
                    if body.iter().any(|stmt| {
                        matches!(stmt, Stmt::Decl { kind, .. } if *kind != DeclKind::Var)
                            || matches!(stmt, Stmt::Func(_))
                    }) {
                        return self.unsupported(
                            c.span,
                            "lexical declarations directly under switch cases",
                            "wrap the case body in braces; switch cases otherwise share one lexical scope",
                        );
                    }
                    cases.push((c.test.as_ref().map(|e| self.expr(e)).transpose()?, body));
                }
                Stmt::Switch {
                    disc: self.expr(&s.discriminant)?,
                    cases,
                    span: self.sp(s.span),
                }
            }
            a::Statement::LabeledStatement(l) => {
                if matches!(l.body, a::Statement::FunctionDeclaration(_)) {
                    return self.unsupported(l.span, "labeled function declarations", "remove the label");
                }
                let mut body = Vec::new();
                self.inner_stmt(&l.body, &mut body)?;
                let body = if body.len() == 1 {
                    body.pop().expect("one statement")
                } else {
                    Stmt::Block(body)
                };
                Stmt::Labeled(l.label.name.to_string(), Box::new(body), self.sp(l.span))
            }
            a::Statement::WithStatement(w) => return self.unsupported(w.span, "`with`", ""),
            a::Statement::DebuggerStatement(d) => return self.unsupported(d.span, "`debugger`", ""),
            a::Statement::EmptyStatement(_) => Stmt::Block(vec![]),
            other => {
                let mut out = Vec::new();
                if let Some(d) = other.as_declaration() {
                    self.declaration(d, &mut out)?;
                    return Ok(Stmt::Block(out));
                }
                return self.unsupported(other.span(), "this statement", "");
            }
        })
    }

    fn function(&self, f: &a::Function<'_>) -> Result<Rc<Func>> {
        let saved = self.class_context.replace(false);
        let super_call = self.super_call.replace(false);
        let result = self.function_inner(f);
        self.class_context.set(saved);
        self.super_call.set(super_call);
        result
    }

    fn class(&self, c: &a::Class<'_>) -> Result<Rc<Class>> {
        let type_only = |present: bool| present && !self.erase_types;
        if !c.decorators.is_empty() || type_only(c.r#abstract) || c.declare || type_only(c.type_parameters.is_some()) {
            return self.unsupported(
                c.span,
                "decorated, abstract, ambient or generic classes",
                "use a concrete named class",
            );
        }
        let Some(id) = &c.id else {
            return self.unsupported(c.span, "anonymous classes", "name the class");
        };
        self.binding_name(id.name.as_str(), id.span)?;
        let base = match &c.heritage {
            None => None,
            Some(h) => match &h.expression {
                a::Expression::Identifier(i) if !type_only(h.type_arguments.is_some()) => Some(i.name.to_string()),
                _ => return self.unsupported(c.span, "dynamic class inheritance", "extend a named concrete class"),
            },
        };
        let saved = self.class_context.replace(true);
        let result = (|| {
            let mut fields = Vec::new();
            let mut methods = Vec::new();
            let mut constructor = None;
            let mut names = std::collections::HashSet::new();
            let mut accessors = Vec::new();
            let mut accessor_names = std::collections::HashSet::new();
            for element in &c.body.body {
                match element {
                    // Erased members have no runtime form: abstract members,
                    // ambient fields, overload signatures and index signatures.
                    a::ClassElement::PropertyDefinition(p)
                        if self.erase_types
                            && (p.declare || p.r#type == a::PropertyDefinitionType::TSAbstractPropertyDefinition) => {}
                    a::ClassElement::MethodDefinition(m)
                        if self.erase_types
                            && (m.value.body.is_none()
                                || m.r#type == a::MethodDefinitionType::TSAbstractMethodDefinition) => {}
                    a::ClassElement::TSIndexSignature(_) if self.erase_types => {}
                    a::ClassElement::PropertyDefinition(p) => {
                        let Some(name) = class_member_name(&p.key) else {
                            return self.unsupported(p.span, "computed class fields", "use named fields");
                        };
                        if name.starts_with('#') != p.key.is_private_identifier() {
                            return self.unsupported(
                                p.span,
                                "quoted class member names starting with #",
                                "rename the member",
                            );
                        }
                        if p.computed
                            || !p.decorators.is_empty()
                            || p.declare
                            || type_only(p.accessibility.is_some_and(|a| a != a::TSAccessibility::Public))
                        {
                            return self.unsupported(
                                p.span,
                                "computed, decorated, ambient or private class fields",
                                "use public named fields",
                            );
                        }
                        if matches!(
                            name.as_str(),
                            "__proto__" | "prototype" | "constructor" | "toJSON" | "toString" | "valueOf"
                        ) || accessor_names
                            .iter()
                            .any(|(s, _, n): &(bool, bool, String)| *s == p.r#static && *n == name)
                            || !names.insert((p.r#static, name.to_string()))
                        {
                            return self.unsupported(
                                p.span,
                                "duplicate or protocol class members",
                                "use distinct ordinary member names",
                            );
                        }
                        fields.push(ClassField {
                            name: name.to_string(),
                            is_static: p.r#static,
                            ann: self.annotation(p.type_annotation.as_ref())?,
                            init: p.value.as_ref().map(|e| self.expr(e)).transpose()?,
                            span: self.sp(p.span),
                        });
                    }
                    a::ClassElement::MethodDefinition(m) => {
                        let Some(name) = class_member_name(&m.key) else {
                            return self.unsupported(m.span, "computed class methods", "use named methods");
                        };
                        if name.starts_with('#') != m.key.is_private_identifier() {
                            return self.unsupported(
                                m.span,
                                "quoted class member names starting with #",
                                "rename the member",
                            );
                        }
                        if m.computed
                            || !m.decorators.is_empty()
                            || type_only(m.accessibility.is_some_and(|a| a != a::TSAccessibility::Public))
                        {
                            return self.unsupported(
                                m.span,
                                "computed, decorated or private class methods",
                                "use public named methods",
                            );
                        }
                        let accessor = matches!(m.kind, a::MethodDefinitionKind::Get | a::MethodDefinitionKind::Set);
                        if type_only(m.value.type_parameters.is_some()) || m.value.params.rest.is_some() {
                            return self.unsupported(
                                m.span,
                                "generic or rest class methods",
                                "use explicitly typed ordinary parameters",
                            );
                        }
                        self.super_call
                            .set(m.kind == a::MethodDefinitionKind::Constructor && base.is_some());
                        let mut f = self.function_inner(&m.value)?;
                        self.super_call.set(false);
                        let f_mut = Rc::get_mut(&mut f).expect("new function");
                        let is_ctor = m.kind == a::MethodDefinitionKind::Constructor;
                        for p in &m.value.params.items {
                            if !p.decorators.is_empty()
                                || type_only(p.accessibility.is_some_and(|a| a != a::TSAccessibility::Public))
                            {
                                return self.unsupported(
                                    p.span,
                                    "decorated or private parameter properties",
                                    "use public parameter properties",
                                );
                            }
                            if p.accessibility.is_some() || p.readonly {
                                if !is_ctor {
                                    return self.unsupported(p.span, "parameter properties outside constructors", "");
                                }
                                let a::BindingPattern::BindingIdentifier(i) = &p.pattern else {
                                    return self.unsupported(
                                        p.span,
                                        "destructured parameter properties",
                                        "use a named parameter",
                                    );
                                };
                                if matches!(
                                    i.name.as_str(),
                                    "__proto__" | "prototype" | "constructor" | "toJSON" | "toString" | "valueOf"
                                ) || !names.insert((false, i.name.to_string()))
                                {
                                    return self.unsupported(
                                        p.span,
                                        "duplicate or protocol class members",
                                        "use distinct ordinary member names",
                                    );
                                }
                                fields.push(ClassField {
                                    name: i.name.to_string(),
                                    is_static: false,
                                    ann: self.annotation(p.type_annotation.as_ref())?,
                                    init: None,
                                    span: self.sp(p.span),
                                });
                            }
                        }
                        f_mut.params.insert(
                            0,
                            Param {
                                pat: Pat::Id(
                                    "this".into(),
                                    Span {
                                        end: m.span.start,
                                        ..self.sp(m.span)
                                    },
                                ),
                                ann: None,
                                default: None,
                                optional: false,
                                rest: false,
                                span: self.sp(m.span),
                            },
                        );
                        if is_ctor {
                            let FnBody::Block(body) = &mut f_mut.body else {
                                unreachable!()
                            };
                            if contains_constructor_return(body) {
                                return self.unsupported(m.span, "constructor return values", "mutate this instead");
                            }
                            let at = if base.is_some() {
                                if !body.first().is_some_and(|s| matches!(s, Stmt::Expr(Expr { kind: ExprKind::Call { callee, .. }, .. }) if matches!(&callee.kind, ExprKind::Ident(n) if n == "super"))) {
                                    return self.unsupported(m.span, "derived constructors without a first-statement super(...) call", "call super(...) first");
                                }
                                1
                            } else {
                                0
                            };
                            let mut at = at;
                            if base.is_some() {
                                let initialize = self.mk(
                                    m.span,
                                    ExprKind::Call {
                                        callee: Box::new(self.mk(m.span, ExprKind::Ident(":fields".into()))),
                                        args: vec![],
                                        optional: false,
                                        type_args: vec![],
                                    },
                                );
                                body.insert(at, Stmt::Expr(initialize));
                                at += 1;
                            }
                            for p in &m.value.params.items {
                                if p.accessibility.is_some() || p.readonly {
                                    let a::BindingPattern::BindingIdentifier(i) = &p.pattern else {
                                        unreachable!()
                                    };
                                    body.insert(
                                        at,
                                        Stmt::Expr(self.mk(
                                            p.span,
                                            ExprKind::Assign {
                                                op: None,
                                                target: Box::new(self.mk(
                                                    p.span,
                                                    ExprKind::Member {
                                                        obj: Box::new(self.mk(p.span, ExprKind::Ident("this".into()))),
                                                        prop: i.name.to_string(),
                                                        optional: false,
                                                        prop_span: self.sp(p.span),
                                                    },
                                                )),
                                                value: Box::new(self.mk(p.span, ExprKind::Ident(i.name.to_string()))),
                                            },
                                        )),
                                    );
                                    at += 1;
                                }
                            }
                            constructor = Some(f);
                        } else if accessor {
                            let setter = m.kind == a::MethodDefinitionKind::Set;
                            if matches!(name.as_str(), "__proto__" | "prototype" | "constructor")
                                || names.contains(&(m.r#static, name.clone()))
                                || !accessor_names.insert((m.r#static, setter, name.clone()))
                            {
                                return self.unsupported(
                                    m.span,
                                    "duplicate or protocol class members",
                                    "use distinct ordinary member names",
                                );
                            }
                            accessors.push(ClassAccessor {
                                name: name.clone(),
                                is_static: m.r#static,
                                setter,
                                func: f,
                            });
                        } else {
                            if accessor_names.iter().any(|(s, _, n)| *s == m.r#static && *n == name) {
                                return self.unsupported(
                                    m.span,
                                    "duplicate or protocol class members",
                                    "use distinct ordinary member names",
                                );
                            }
                            if matches!(
                                name.as_str(),
                                "__proto__" | "prototype" | "constructor" | "toString" | "valueOf"
                            ) || !names.insert((m.r#static, name.to_string()))
                            {
                                return self.unsupported(
                                    m.span,
                                    "duplicate or protocol class members",
                                    "use distinct ordinary member names",
                                );
                            }
                            methods.push((name.to_string(), m.r#static, f));
                        }
                    }
                    _ => {
                        return self.unsupported(
                            c.span,
                            "class static blocks, accessors or index signatures",
                            "use public fields and methods",
                        );
                    }
                }
            }
            fields.sort_by_key(|field| {
                !constructor
                    .as_ref()
                    .is_some_and(|f| f.params.iter().skip(1).any(|p| p.span == field.span))
            });
            Ok(Rc::new(Class {
                name: id.name.to_string(),
                base,
                fields,
                methods,
                constructor,
                accessors,
                span: self.sp(c.span),
            }))
        })();
        self.class_context.set(saved);
        result
    }

    fn function_inner(&self, f: &a::Function<'_>) -> Result<Rc<Func>> {
        if let Some(id) = &f.id {
            self.binding_name(id.name.as_str(), id.span)?;
        }
        let Some(body) = &f.body else {
            return self.unsupported(f.span, "a function without a body", "");
        };
        let context = self.class_context.get();
        if self.super_call.get() {
            self.class_context.set(false);
        }
        let params = self.params(&f.params, context);
        self.class_context.set(context);
        let params = params?;
        let body = FnBody::Block(self.block(&body.statements)?);
        check_parameter_defaults(&params, &body)?;
        Ok(Rc::new(Func {
            name: f.id.as_ref().map(|i| i.name.to_string()),
            is_arrow: false,
            params,
            body,
            is_async: f.r#async,
            is_generator: f.generator,
            ret: self.annotation(f.return_type.as_ref())?,
            type_params: self.type_params(f.type_parameters.as_deref()),
            span: self.sp(f.span),
            module: self.module,
        }))
    }

    fn arrow(&self, f: &a::ArrowFunctionExpression<'_>) -> Result<Rc<Func>> {
        let body = match f.get_expression() {
            Some(e) => FnBody::Expr(self.expr(e)?),
            None => FnBody::Block(self.block(&f.get_function_body().expect("arrow body").statements)?),
        };
        let params = self.params(&f.params, false)?;
        check_parameter_defaults(&params, &body)?;
        Ok(Rc::new(Func {
            name: None,
            is_arrow: true,
            params,
            body,
            is_async: f.r#async,
            is_generator: false,
            ret: self.annotation(f.return_type.as_ref())?,
            type_params: self.type_params(f.type_parameters.as_deref()),
            span: self.sp(f.span),
            module: self.module,
        }))
    }

    fn params(&self, p: &a::FormalParameters<'_>, parameter_properties: bool) -> Result<Vec<Param>> {
        let mut out = Vec::new();
        for item in &p.items {
            if (item.accessibility.is_some() || item.readonly) && !parameter_properties {
                return self.unsupported(item.span, "parameter properties", "");
            }
            out.push(Param {
                pat: self.pattern(&item.pattern)?,
                ann: self.annotation(item.type_annotation.as_ref())?,
                default: item.initializer.as_ref().map(|e| self.expr(e)).transpose()?,
                optional: item.optional,
                rest: false,
                span: self.sp(item.span),
            });
        }
        if let Some(r) = &p.rest {
            out.push(Param {
                pat: self.pattern(&r.rest.argument)?,
                ann: self.annotation(r.type_annotation.as_ref())?,
                default: None,
                optional: false,
                rest: true,
                span: self.sp(r.span),
            });
        }
        Ok(out)
    }

    fn pattern(&self, p: &a::BindingPattern<'_>) -> Result<Pat> {
        Ok(match p {
            a::BindingPattern::BindingIdentifier(id) => {
                Pat::Id(self.binding_name(id.name.as_str(), id.span)?, self.sp(id.span))
            }
            a::BindingPattern::ObjectPattern(o) => {
                let mut props = Vec::new();
                for prop in &o.properties {
                    let Some(key) = prop.key.static_name() else {
                        return self.unsupported(prop.span, "computed keys in destructuring", "");
                    };
                    let key = key.to_string();
                    props.push((key, self.pattern(&prop.value)?));
                }
                let rest = match &o.rest {
                    None => None,
                    Some(r) => match &r.argument {
                        a::BindingPattern::BindingIdentifier(id) => {
                            Some((self.binding_name(id.name.as_str(), id.span)?, self.sp(id.span)))
                        }
                        _ => return self.unsupported(r.span, "nested rest patterns", ""),
                    },
                };
                Pat::Obj {
                    props,
                    rest,
                    span: self.sp(o.span),
                }
            }
            a::BindingPattern::ArrayPattern(arr) => {
                let mut elems = Vec::new();
                for e in &arr.elements {
                    elems.push(e.as_ref().map(|e| self.pattern(e)).transpose()?);
                }
                let rest = match &arr.rest {
                    None => None,
                    Some(r) => match &r.argument {
                        a::BindingPattern::BindingIdentifier(id) => {
                            Some((self.binding_name(id.name.as_str(), id.span)?, self.sp(id.span)))
                        }
                        _ => return self.unsupported(r.span, "nested rest patterns", ""),
                    },
                };
                Pat::Arr {
                    elems,
                    rest,
                    span: self.sp(arr.span),
                }
            }
            a::BindingPattern::AssignmentPattern(ap) => {
                Pat::Default(Box::new(self.pattern(&ap.left)?), Rc::new(self.expr(&ap.right)?))
            }
        })
    }

    fn args(&self, args: &[a::Argument<'_>]) -> Result<Vec<ArrEl>> {
        args.iter()
            .map(|arg| match arg {
                a::Argument::SpreadElement(s) => Ok(ArrEl::Spread(self.expr(&s.argument)?)),
                other => Ok(ArrEl::Item(self.expr(other.to_expression())?)),
            })
            .collect()
    }

    fn mk(&self, s: oxc_span::Span, kind: ExprKind) -> Expr {
        Expr { kind, span: self.sp(s) }
    }

    pub fn expr(&self, e: &a::Expression<'_>) -> Result<Expr> {
        use a::Expression as E;
        let span = e.span();
        Ok(match e {
            E::NumericLiteral(n) => self.mk(span, ExprKind::Num(n.value)),
            E::StringLiteral(s) => {
                if s.lone_surrogates {
                    return self.unsupported(s.span, "strings with lone UTF-16 surrogates", "");
                }
                self.mk(span, ExprKind::Str(s.value.to_string()))
            }
            E::BooleanLiteral(b) => self.mk(span, ExprKind::Bool(b.value)),
            E::NullLiteral(_) => self.mk(span, ExprKind::Null),
            E::BigIntLiteral(b) => self.mk(span, ExprKind::BigInt(b.value.to_string())),
            E::RegExpLiteral(r) => self.mk(
                span,
                ExprKind::Regex {
                    pattern: r.regex.pattern.text.to_string(),
                    flags: r.regex.flags.to_string(),
                },
            ),
            E::TemplateLiteral(t) => {
                let mut quasis = Vec::new();
                for q in &t.quasis {
                    let Some(cooked) = q.value.cooked else {
                        return self.unsupported(q.span, "invalid escape sequences in templates", "");
                    };
                    quasis.push(cooked.to_string());
                }
                let exprs = t.expressions.iter().map(|e| self.expr(e)).collect::<Result<_>>()?;
                self.mk(span, ExprKind::Template(quasis, exprs))
            }
            E::TaggedTemplateExpression(t) => {
                // tag`a${x}b` calls tag(strings, x), where strings is the site's array of cooked
                // strings with a `raw` property (ECMAScript 13.3.11).
                let strings = self.mk(
                    t.quasi.span,
                    ExprKind::TemplateStrings {
                        cooked: t
                            .quasi
                            .quasis
                            .iter()
                            .map(|q| q.value.cooked.map(|c| c.to_string()))
                            .collect(),
                        raw: t.quasi.quasis.iter().map(|q| q.value.raw.to_string()).collect(),
                    },
                );
                let mut args = vec![ArrEl::Item(strings)];
                for e in &t.quasi.expressions {
                    args.push(ArrEl::Item(self.expr(e)?));
                }
                let type_args = match &t.type_arguments {
                    Some(ta) => ta.params.iter().map(|t| self.ty(t)).collect::<Result<_>>()?,
                    None => vec![],
                };
                self.mk(
                    span,
                    ExprKind::Call {
                        callee: Box::new(self.expr(&t.tag)?),
                        args,
                        optional: false,
                        type_args,
                    },
                )
            }
            E::Identifier(id) => {
                if id.name == "undefined" {
                    self.mk(span, ExprKind::Undefined)
                } else {
                    self.mk(span, ExprKind::Ident(id.name.to_string()))
                }
            }
            E::ArrayExpression(arr) => {
                let mut els = Vec::new();
                for el in &arr.elements {
                    els.push(match el {
                        a::ArrayExpressionElement::SpreadElement(s) => ArrEl::Spread(self.expr(&s.argument)?),
                        a::ArrayExpressionElement::Elision(e) => {
                            return self.unsupported(e.span, "array holes", "write `undefined` explicitly");
                        }
                        other => ArrEl::Item(self.expr(other.to_expression())?),
                    });
                }
                self.mk(span, ExprKind::Array(els))
            }
            E::ObjectExpression(o) => {
                let mut props = Vec::new();
                for p in &o.properties {
                    match p {
                        a::ObjectPropertyKind::SpreadProperty(s) => props.push(Prop::Spread(self.expr(&s.argument)?)),
                        a::ObjectPropertyKind::ObjectProperty(p) => {
                            if p.kind != a::PropertyKind::Init {
                                return self.unsupported(
                                    p.span,
                                    "getters and setters",
                                    "use a plain property or a function",
                                );
                            }
                            if !p.computed
                                && !p.shorthand
                                && !p.method
                                && p.key.static_name().as_deref() == Some("__proto__")
                            {
                                return self.unsupported(p.span, "prototype setter syntax", "use a computed ['__proto__'] key for an own data property; custom prototypes aren't supported");
                            }
                            let key = if p.computed {
                                PropKey::Computed(self.expr(p.key.to_expression())?)
                            } else {
                                match p.key.static_name() {
                                    Some(n) => PropKey::Static(n.to_string()),
                                    None => return self.unsupported(p.span, "this property key", ""),
                                }
                            };
                            let value = if p.method {
                                let a::Expression::FunctionExpression(f) = &p.value else {
                                    return self.unsupported(p.span, "this method", "");
                                };
                                let func = self.function(f)?;
                                Expr {
                                    span: func.span,
                                    kind: ExprKind::Func(func),
                                }
                            } else {
                                self.expr(&p.value)?
                            };
                            props.push(Prop::KV(key, value));
                        }
                    }
                }
                self.mk(span, ExprKind::Object(props))
            }
            E::ArrowFunctionExpression(f) => self.mk(span, ExprKind::Func(self.arrow(f)?)),
            E::FunctionExpression(f) => {
                let func = self.function(f)?;
                let Some(name) = &func.name else {
                    return Ok(self.mk(span, ExprKind::Func(func)));
                };
                // `function name() {}` as an expression binds `name` only inside itself, to itself,
                // and read-only. That is a function declaration in a scope of its own, returned:
                // `(() => { function name() {} return name; })()`. Declarations are hoisted and bound
                // to their own closure, and the body's parameters and `var`s shadow the name as in JS.
                if func_assigns_own_name(&func, name) {
                    return self.unsupported(
                        f.span,
                        "assigning to a named function expression's own name",
                        "the name is read-only inside the function; assign to a local variable instead",
                    );
                }
                let name = name.clone();
                let wrapper = Rc::new(Func {
                    name: None,
                    is_arrow: true,
                    params: vec![],
                    body: FnBody::Block(vec![
                        Stmt::Func(func.clone()),
                        Stmt::Return(Some(self.mk(f.span, ExprKind::Ident(name))), self.sp(f.span)),
                    ]),
                    is_async: false,
                    is_generator: false,
                    ret: None,
                    type_params: vec![],
                    span: self.sp(f.span),
                    module: self.module,
                });
                self.mk(
                    span,
                    ExprKind::Call {
                        callee: Box::new(self.mk(f.span, ExprKind::Func(wrapper))),
                        args: vec![],
                        optional: false,
                        type_args: vec![],
                    },
                )
            }
            E::ParenthesizedExpression(p) => self.expr(&p.expression)?,
            E::UnaryExpression(u) => {
                let op = match u.operator {
                    UO::UnaryNegation => UnOp::Neg,
                    UO::UnaryPlus => UnOp::Plus,
                    UO::LogicalNot => UnOp::Not,
                    UO::Typeof => UnOp::TypeOf,
                    UO::Void => {
                        return Ok(self.mk(
                            u.span,
                            ExprKind::Sequence(vec![self.expr(&u.argument)?, self.mk(u.span, ExprKind::Undefined)]),
                        ));
                    }
                    UO::Delete => {
                        let mut target = u.argument.get_inner_expression();
                        while let a::Expression::TSNonNullExpression(x) = target {
                            target = x.expression.get_inner_expression();
                        }
                        let target = match target {
                            a::Expression::StaticMemberExpression(_) | a::Expression::ComputedMemberExpression(_) => {
                                self.member(target.as_member_expression().expect("member"))?
                            }
                            a::Expression::ChainExpression(c) => match &c.expression {
                                a::ChainElement::StaticMemberExpression(_)
                                | a::ChainElement::ComputedMemberExpression(_) => self.mk(
                                    c.span,
                                    ExprKind::Chain(Box::new(
                                        self.member(c.expression.as_member_expression().expect("member"))?,
                                    )),
                                ),
                                _ => {
                                    return self.unsupported(
                                        u.span,
                                        "`delete` of a call result",
                                        "delete an object property",
                                    );
                                }
                            },
                            a::Expression::PrivateFieldExpression(_) => {
                                return self.unsupported(u.span, "private fields", "");
                            }
                            _ => {
                                return self.unsupported(
                                    u.span,
                                    "`delete` of a variable or value",
                                    "`delete` removes an object property: `delete obj.key`",
                                );
                            }
                        };
                        return Ok(self.mk(u.span, ExprKind::Delete(Box::new(target))));
                    }
                    UO::BitwiseNot => UnOp::BitNot,
                };
                self.mk(span, ExprKind::Unary(op, Box::new(self.expr(&u.argument)?)))
            }
            E::BinaryExpression(b) => {
                let op = match b.operator {
                    BO::Addition => BinOp::Add,
                    BO::Subtraction => BinOp::Sub,
                    BO::Multiplication => BinOp::Mul,
                    BO::Division => BinOp::Div,
                    BO::Remainder => BinOp::Rem,
                    BO::Exponential => BinOp::Exp,
                    BO::LessThan => BinOp::Lt,
                    BO::LessEqualThan => BinOp::Le,
                    BO::GreaterThan => BinOp::Gt,
                    BO::GreaterEqualThan => BinOp::Ge,
                    BO::StrictEquality => BinOp::Eq,
                    BO::StrictInequality => BinOp::Ne,
                    BO::Equality | BO::Inequality => {
                        let negated = b.operator == BO::Inequality;
                        let nullish = |e: &a::Expression<'_>| {
                            matches!(e.get_inner_expression(), a::Expression::NullLiteral(_))
                                || e.get_inner_expression().is_specific_id("undefined")
                        };
                        let target = if nullish(&b.right) {
                            Some(&b.left)
                        } else if nullish(&b.left) {
                            Some(&b.right)
                        } else {
                            None
                        };
                        if let Some(target) = target {
                            return Ok(self.mk(span, ExprKind::IsNullish(Box::new(self.expr(target)?), negated)));
                        }
                        if negated { BinOp::LooseNe } else { BinOp::LooseEq }
                    }
                    BO::In => {
                        if matches!(b.left, a::Expression::PrivateInExpression(_)) {
                            return self.unsupported(b.span, "`#field in`", "use public fields");
                        }
                        return Ok(self.mk(
                            b.span,
                            ExprKind::In(Box::new(self.expr(&b.left)?), Box::new(self.expr(&b.right)?)),
                        ));
                    }
                    BO::Instanceof => {
                        return Ok(self.mk(
                            b.span,
                            ExprKind::Instanceof(Box::new(self.expr(&b.left)?), Box::new(self.expr(&b.right)?)),
                        ));
                    }
                    BO::BitwiseAnd => BinOp::BitAnd,
                    BO::BitwiseOR => BinOp::BitOr,
                    BO::BitwiseXOR => BinOp::BitXor,
                    BO::ShiftLeft => BinOp::Shl,
                    BO::ShiftRight => BinOp::Shr,
                    BO::ShiftRightZeroFill => BinOp::UShr,
                };
                self.mk(
                    span,
                    ExprKind::Binary(op, Box::new(self.expr(&b.left)?), Box::new(self.expr(&b.right)?)),
                )
            }
            E::LogicalExpression(l) => {
                let op = match l.operator {
                    LO::And => LogOp::And,
                    LO::Or => LogOp::Or,
                    LO::Coalesce => LogOp::Nullish,
                };
                self.mk(
                    span,
                    ExprKind::Logical(op, Box::new(self.expr(&l.left)?), Box::new(self.expr(&l.right)?)),
                )
            }
            E::ConditionalExpression(c) => self.mk(
                span,
                ExprKind::Cond(
                    Box::new(self.expr(&c.test)?),
                    Box::new(self.expr(&c.consequent)?),
                    Box::new(self.expr(&c.alternate)?),
                ),
            ),
            E::AssignmentExpression(asg) => {
                let op = match asg.operator {
                    AO::Assign => None,
                    AO::Addition => Some(AssignOp::Bin(BinOp::Add)),
                    AO::Subtraction => Some(AssignOp::Bin(BinOp::Sub)),
                    AO::Multiplication => Some(AssignOp::Bin(BinOp::Mul)),
                    AO::Division => Some(AssignOp::Bin(BinOp::Div)),
                    AO::Remainder => Some(AssignOp::Bin(BinOp::Rem)),
                    AO::Exponential => Some(AssignOp::Bin(BinOp::Exp)),
                    AO::LogicalOr => Some(AssignOp::Log(LogOp::Or)),
                    AO::LogicalAnd => Some(AssignOp::Log(LogOp::And)),
                    AO::LogicalNullish => Some(AssignOp::Log(LogOp::Nullish)),
                    AO::BitwiseAnd => Some(AssignOp::Bin(BinOp::BitAnd)),
                    AO::BitwiseOR => Some(AssignOp::Bin(BinOp::BitOr)),
                    AO::BitwiseXOR => Some(AssignOp::Bin(BinOp::BitXor)),
                    AO::ShiftLeft => Some(AssignOp::Bin(BinOp::Shl)),
                    AO::ShiftRight => Some(AssignOp::Bin(BinOp::Shr)),
                    AO::ShiftRightZeroFill => Some(AssignOp::Bin(BinOp::UShr)),
                };
                let target = match asg.left.as_simple_assignment_target() {
                    Some(t) => self.simple_target(t)?,
                    None => {
                        if op.is_some() {
                            return self.unsupported(asg.span, "compound destructuring assignment", "");
                        }
                        let pattern = self.assign_pattern(&asg.left)?;
                        return Ok(self.mk(
                            span,
                            ExprKind::AssignPattern(Box::new(pattern), Box::new(self.expr(&asg.right)?)),
                        ));
                    }
                };
                self.mk(
                    span,
                    ExprKind::Assign {
                        op,
                        target: Box::new(target),
                        value: Box::new(self.expr(&asg.right)?),
                    },
                )
            }
            E::UpdateExpression(u) => self.mk(
                span,
                ExprKind::Update {
                    inc: u.operator == UpdateOperator::Increment,
                    prefix: u.prefix,
                    target: Box::new(self.simple_target(&u.argument)?),
                },
            ),
            E::CallExpression(c) => self.call(c)?,
            E::NewExpression(n) => self.mk(
                span,
                ExprKind::New {
                    callee: Box::new(self.expr(&n.callee)?),
                    args: self.args(&n.arguments)?,
                    type_args: match &n.type_arguments {
                        Some(t) => t.params.iter().map(|t| self.ty(t)).collect::<Result<_>>()?,
                        None => vec![],
                    },
                },
            ),
            E::ChainExpression(c) => {
                let inner = match &c.expression {
                    a::ChainElement::CallExpression(call) => self.call(call)?,
                    a::ChainElement::TSNonNullExpression(n) => self.expr(&n.expression)?,
                    other => self.member(other.as_member_expression().expect("member"))?,
                };
                self.mk(span, ExprKind::Chain(Box::new(inner)))
            }
            E::StaticMemberExpression(_) | E::ComputedMemberExpression(_) | E::PrivateFieldExpression(_) => {
                self.member(e.as_member_expression().expect("member"))?
            }
            E::AwaitExpression(aw) => self.mk(span, ExprKind::Await(Box::new(self.expr(&aw.argument)?))),
            E::TSAsExpression(x) => self.cast(span, &x.expression, &x.type_annotation)?,
            E::TSSatisfiesExpression(x) => self.expr(&x.expression)?,
            E::TSTypeAssertion(x) => self.cast(span, &x.expression, &x.type_annotation)?,
            E::TSNonNullExpression(x) => self.mk(span, ExprKind::NonNull(Box::new(self.expr(&x.expression)?))),
            E::TSInstantiationExpression(x) => self.expr(&x.expression)?,
            E::SequenceExpression(s) => self.mk(
                s.span,
                ExprKind::Sequence(s.expressions.iter().map(|e| self.expr(e)).collect::<Result<_>>()?),
            ),
            E::ThisExpression(t) if self.class_context.get() => self.mk(t.span, ExprKind::Ident("this".into())),
            E::ThisExpression(t) => {
                return self.unsupported(
                    t.span,
                    "`this` outside a class or its arrows",
                    "pass values as parameters",
                );
            }
            E::ClassExpression(c) => {
                return self.unsupported(
                    c.span,
                    "class expressions",
                    "use a named module-level class declaration",
                );
            }
            E::PrivateInExpression(p) => self.mk(
                p.span,
                ExprKind::Call {
                    callee: Box::new(self.mk(p.span, ExprKind::Ident(":private_in".into()))),
                    args: vec![
                        ArrEl::Item(self.mk(p.left.span, ExprKind::Str(format!("#{}", p.left.name)))),
                        ArrEl::Item(self.expr(&p.right)?),
                    ],
                    optional: false,
                    type_args: vec![],
                },
            ),
            E::YieldExpression(y) => self.mk(
                span,
                ExprKind::Yield {
                    arg: y.argument.as_ref().map(|a| self.expr(a).map(Box::new)).transpose()?,
                    delegate: y.delegate,
                },
            ),
            E::ImportExpression(i) => return self.unsupported(i.span, "dynamic `import()`", "use a static import"),
            E::Super(s) if self.class_context.get() => self.mk(s.span, ExprKind::Ident("super".into())),
            E::Super(s) => return self.unsupported(s.span, "`super` outside a class", ""),
            E::JSXElement(j) => return self.unsupported(j.span, "JSX", ""),
            E::JSXFragment(j) => return self.unsupported(j.span, "JSX", ""),
            other => return self.unsupported(other.span(), "this expression", ""),
        })
    }

    fn cast(&self, span: oxc_span::Span, e: &a::Expression<'_>, ty: &a::TSType<'_>) -> Result<Expr> {
        if self.erase_types {
            return self.expr(e);
        }
        if let a::TSType::TSTypeReference(r) = ty
            && let a::TSTypeName::IdentifierReference(id) = &r.type_name
            && id.name == "const"
        {
            return self.expr(e);
        }
        Ok(self.mk(span, ExprKind::As(Box::new(self.expr(e)?), self.ty(ty)?)))
    }

    fn call(&self, c: &a::CallExpression<'_>) -> Result<Expr> {
        if matches!(c.callee, a::Expression::Super(_)) {
            if !self.super_call.replace(false) {
                return self.unsupported(
                    c.span,
                    "super() outside the first derived constructor statement",
                    "call super(...) exactly once, first",
                );
            }
            let saved = self.class_context.replace(false);
            let args = self.args(&c.arguments);
            self.class_context.set(saved);
            return Ok(self.mk(
                c.span,
                ExprKind::Call {
                    callee: Box::new(self.mk(c.span, ExprKind::Ident("super".into()))),
                    args: args?,
                    optional: false,
                    type_args: vec![],
                },
            ));
        }
        let type_args = match &c.type_arguments {
            Some(t) if !self.erase_types => t.params.iter().map(|t| self.ty(t)).collect::<Result<_>>()?,
            _ => vec![],
        };
        Ok(self.mk(
            c.span,
            ExprKind::Call {
                callee: Box::new(self.expr(&c.callee)?),
                args: self.args(&c.arguments)?,
                optional: c.optional,
                type_args,
            },
        ))
    }

    fn member(&self, m: &a::MemberExpression<'_>) -> Result<Expr> {
        Ok(match m {
            a::MemberExpression::StaticMemberExpression(s) => self.mk(
                s.span,
                ExprKind::Member {
                    obj: Box::new(self.expr(&s.object)?),
                    prop: s.property.name.to_string(),
                    optional: s.optional,
                    prop_span: self.sp(s.property.span),
                },
            ),
            a::MemberExpression::ComputedMemberExpression(c) => self.mk(
                c.span,
                ExprKind::Index {
                    obj: Box::new(self.expr(&c.object)?),
                    index: Box::new(self.expr(&c.expression)?),
                    optional: c.optional,
                },
            ),
            a::MemberExpression::PrivateFieldExpression(p) => self.mk(
                p.span,
                ExprKind::Member {
                    obj: Box::new(self.expr(&p.object)?),
                    prop: format!("#{}", p.field.name),
                    optional: p.optional,
                    prop_span: self.sp(p.field.span),
                },
            ),
        })
    }

    fn assign_pattern(&self, t: &a::AssignmentTarget<'_>) -> Result<AssignPat> {
        if let Some(simple) = t.as_simple_assignment_target() {
            return Ok(AssignPat::Target(self.simple_target(simple)?));
        }
        let rest = |r: &Option<oxc_allocator::Box<'_, a::AssignmentTargetRest<'_>>>| -> Result<Option<Box<AssignPat>>> {
            r.as_ref()
                .map(|r| self.assign_pattern(&r.target).map(Box::new))
                .transpose()
        };
        match t.as_assignment_target_pattern().expect("pattern") {
            a::AssignmentTargetPattern::ArrayAssignmentTarget(arr) => {
                let mut elems = Vec::new();
                for e in &arr.elements {
                    elems.push(e.as_ref().map(|e| self.assign_maybe_default(e)).transpose()?);
                }
                Ok(AssignPat::Arr {
                    elems,
                    rest: rest(&arr.rest)?,
                    span: self.sp(arr.span),
                })
            }
            a::AssignmentTargetPattern::ObjectAssignmentTarget(obj) => {
                let mut props = Vec::new();
                for p in &obj.properties {
                    match p {
                        a::AssignmentTargetProperty::AssignmentTargetPropertyIdentifier(id) => {
                            let target = AssignPat::Target(self.mk(
                                id.binding.span,
                                ExprKind::Ident(self.binding_name(id.binding.name.as_str(), id.binding.span)?),
                            ));
                            let pattern = match &id.init {
                                Some(init) => AssignPat::Default(Box::new(target), self.expr(init)?),
                                None => target,
                            };
                            props.push((PropKey::Static(id.binding.name.to_string()), pattern));
                        }
                        a::AssignmentTargetProperty::AssignmentTargetPropertyProperty(pp) => {
                            let key = if pp.computed {
                                PropKey::Computed(self.expr(pp.name.to_expression())?)
                            } else {
                                match pp.name.static_name() {
                                    Some(n) => PropKey::Static(n.to_string()),
                                    None => return self.unsupported(pp.span, "this property key", ""),
                                }
                            };
                            props.push((key, self.assign_maybe_default(&pp.binding)?));
                        }
                    }
                }
                Ok(AssignPat::Obj {
                    props,
                    rest: rest(&obj.rest)?,
                    span: self.sp(obj.span),
                })
            }
        }
    }

    fn assign_maybe_default(&self, t: &a::AssignmentTargetMaybeDefault<'_>) -> Result<AssignPat> {
        match t {
            a::AssignmentTargetMaybeDefault::AssignmentTargetWithDefault(d) => Ok(AssignPat::Default(
                Box::new(self.assign_pattern(&d.binding)?),
                self.expr(&d.init)?,
            )),
            other => self.assign_pattern(other.to_assignment_target()),
        }
    }

    fn simple_target(&self, t: &a::SimpleAssignmentTarget<'_>) -> Result<Expr> {
        match t {
            a::SimpleAssignmentTarget::AssignmentTargetIdentifier(id) => {
                Ok(self.mk(id.span, ExprKind::Ident(id.name.to_string())))
            }
            a::SimpleAssignmentTarget::TSNonNullExpression(n) => {
                Ok(self.mk(n.span, ExprKind::NonNull(Box::new(self.expr(&n.expression)?))))
            }
            a::SimpleAssignmentTarget::TSAsExpression(x) => self.expr(&x.expression),
            a::SimpleAssignmentTarget::TSSatisfiesExpression(x) => self.expr(&x.expression),
            a::SimpleAssignmentTarget::TSTypeAssertion(x) => self.expr(&x.expression),
            other => self.member(other.as_member_expression().expect("member target")),
        }
    }

    pub fn ty(&self, t: &a::TSType<'_>) -> Result<TyAnn> {
        use a::TSType as T;
        Ok(match t {
            T::TSNumberKeyword(_) => TyAnn::Num,
            T::TSBigIntKeyword(_) => TyAnn::BigInt,
            T::TSStringKeyword(_) => TyAnn::Str,
            T::TSBooleanKeyword(_) => TyAnn::Bool,
            T::TSNullKeyword(_) => TyAnn::Null,
            T::TSUndefinedKeyword(_) => TyAnn::Undefined,
            T::TSVoidKeyword(_) => TyAnn::Void,
            T::TSAnyKeyword(k) => TyAnn::Any(self.sp(k.span)),
            T::TSUnknownKeyword(_) => TyAnn::Unknown,
            T::TSNeverKeyword(_) => TyAnn::Never,
            T::TSTypePredicate(p) => {
                let a::TSTypePredicateName::Identifier(name) = &p.parameter_name else {
                    return self.unsupported(p.span, "this type predicates", "refine a named parameter");
                };
                let Some(target) = &p.type_annotation else {
                    return self.unsupported(p.span, "untyped assertions", "return a boolean type predicate");
                };
                if p.asserts {
                    return self.unsupported(p.span, "assertion functions", "return a boolean type predicate");
                }
                TyAnn::Predicate(
                    name.name.to_string(),
                    Box::new(self.ty(&target.type_annotation)?),
                    self.sp(p.span),
                )
            }
            T::TSObjectKeyword(_) => TyAnn::Map(Box::new(TyAnn::Unknown)),
            T::TSArrayType(arr) => TyAnn::Array(Box::new(self.ty(&arr.element_type)?)),
            T::TSTupleType(tup) => {
                let mut items = Vec::new();
                for el in &tup.element_types {
                    match el.as_ts_type() {
                        Some(t) => items.push(self.ty(t)?),
                        None => {
                            return self.unsupported(tup.span, "optional or rest tuple elements", "use an array type");
                        }
                    }
                }
                TyAnn::Tuple(items)
            }
            T::TSUnionType(u) => TyAnn::Union(u.types.iter().map(|t| self.ty(t)).collect::<Result<_>>()?),
            T::TSIntersectionType(i) => TyAnn::Intersection(
                i.types.iter().map(|t| self.ty(t)).collect::<Result<_>>()?,
                self.sp(i.span),
            ),
            T::TSParenthesizedType(p) => self.ty(&p.type_annotation)?,
            T::TSLiteralType(l) => match &l.literal {
                a::TSLiteral::StringLiteral(s) => TyAnn::StrLit(s.value.to_string()),
                a::TSLiteral::TemplateLiteral(t) if t.expressions.is_empty() => {
                    let q = &t.quasis[0];
                    let Some(cooked) = q.value.cooked else {
                        return self.unsupported(q.span, "invalid escape sequences in templates", "");
                    };
                    TyAnn::StrLit(cooked.to_string())
                }
                a::TSLiteral::TemplateLiteral(t) => {
                    return self.unsupported(
                        t.span,
                        "interpolated template literal types",
                        "use a finite union of string literals",
                    );
                }
                a::TSLiteral::NumericLiteral(n) => TyAnn::NumLit(n.value),
                a::TSLiteral::BooleanLiteral(value) => TyAnn::BoolLit(value.value),
                a::TSLiteral::UnaryExpression(u) if matches!(u.argument, a::Expression::NumericLiteral(_)) => {
                    let a::Expression::NumericLiteral(n) = &u.argument else {
                        unreachable!()
                    };
                    TyAnn::NumLit(if u.operator == UO::UnaryNegation {
                        -n.value
                    } else {
                        n.value
                    })
                }
                _ => return self.unsupported(l.span, "this literal type", ""),
            },
            T::TSTypeLiteral(lit) => self.members(&lit.members)?,
            T::TSFunctionType(f) => {
                if f.type_parameters.is_some() || f.params.rest.is_some() || f.params.items.iter().any(|p| p.optional) {
                    return self.unsupported(
                        f.span,
                        "generic, optional or rest parameters in function types",
                        "use a concrete function type with required parameters",
                    );
                }
                let mut params = Vec::new();
                for p in &f.params.items {
                    let ann = self.annotation(p.type_annotation.as_ref())?;
                    params.push((ann.unwrap_or(TyAnn::Any(self.sp(p.span))), p.optional));
                }
                TyAnn::Fn(params, Box::new(self.ty(&f.return_type.type_annotation)?))
            }
            T::TSTypeReference(r) => {
                let name = self.type_name(&r.type_name);
                let args = match &r.type_arguments {
                    Some(a) => a.params.iter().map(|t| self.ty(t)).collect::<Result<_>>()?,
                    None => vec![],
                };
                TyAnn::Ref(name, args, self.sp(r.span))
            }
            T::TSTypeQuery(q) => match &q.expr_name {
                a::TSTypeQueryExprName::TSImportType(i) => {
                    return self.unsupported(i.span, "`typeof import()`", "import the value");
                }
                other => {
                    let name = self.type_name(other.to_ts_type_name());
                    TyAnn::Query(name.split('.').map(str::to_string).collect(), self.sp(q.span))
                }
            },
            T::TSIndexedAccessType(i) => TyAnn::Indexed(
                Box::new(self.ty(&i.object_type)?),
                Box::new(self.ty(&i.index_type)?),
                self.sp(i.span),
            ),
            T::TSMappedType(m) => {
                if m.name_type.is_some() {
                    return self.unsupported(
                        m.span,
                        "mapped type key remapping",
                        "use a finite mapped type without `as`",
                    );
                }
                let Some(value) = &m.type_annotation else {
                    return self.unsupported(m.span, "untyped mapped types", "provide a value type");
                };
                TyAnn::Mapped {
                    key: m.key.name.to_string(),
                    keys: Box::new(self.ty(&m.constraint)?),
                    value: Box::new(self.ty(value)?),
                    optional: m.optional.map(|o| !matches!(o, a::TSMappedTypeModifierOperator::Minus)),
                    span: self.sp(m.span),
                }
            }
            T::TSConditionalType(c) => TyAnn::Conditional {
                check: Box::new(self.ty(&c.check_type)?),
                extends: Box::new(self.ty(&c.extends_type)?),
                yes: Box::new(self.ty(&c.true_type)?),
                no: Box::new(self.ty(&c.false_type)?),
                span: self.sp(c.span),
            },
            T::TSTypeOperatorType(o) => match o.operator {
                a::TSTypeOperatorOperator::Keyof => {
                    TyAnn::KeyOf(Box::new(self.ty(&o.type_annotation)?), self.sp(o.span))
                }
                a::TSTypeOperatorOperator::Readonly => self.ty(&o.type_annotation)?,
                a::TSTypeOperatorOperator::Unique => return self.unsupported(o.span, "`unique symbol`", ""),
            },
            other => return self.unsupported(other.span(), "this type", "use a simpler type"),
        })
    }

    fn type_name(&self, n: &a::TSTypeName<'_>) -> String {
        match n {
            a::TSTypeName::IdentifierReference(id) => id.name.to_string(),
            a::TSTypeName::QualifiedName(q) => format!("{}.{}", self.type_name(&q.left), q.right.name),
            a::TSTypeName::ThisExpression(_) => "this".into(),
        }
    }

    fn members(&self, members: &[a::TSSignature<'_>]) -> Result<TyAnn> {
        let mut fields = Vec::new();
        for m in members {
            match m {
                a::TSSignature::TSPropertySignature(p) => {
                    if matches!(p.key, a::PropertyKey::NumericLiteral(_)) {
                        return self.unsupported(
                            p.span,
                            "numeric property names in types",
                            "use string property names",
                        );
                    }
                    let Some(key) = p.key.static_name() else {
                        return self.unsupported(p.span, "computed keys in types", "");
                    };
                    let ty = match &p.type_annotation {
                        Some(t) => self.ty(&t.type_annotation)?,
                        None => TyAnn::Any(self.sp(p.span)),
                    };
                    fields.push((key.to_string(), ty, p.optional));
                }
                a::TSSignature::TSIndexSignature(i) => {
                    if !matches!(
                        i.parameter.type_annotation.type_annotation,
                        a::TSType::TSStringKeyword(_)
                    ) {
                        return self.unsupported(
                            i.span,
                            "non-string index signatures",
                            "use a finite Record of string keys",
                        );
                    }
                    if !fields.is_empty() || members.len() > 1 {
                        return self.unsupported(
                            i.span,
                            "index signatures mixed with named fields",
                            "use `Record<string, T>`",
                        );
                    }
                    return Ok(TyAnn::Map(Box::new(self.ty(&i.type_annotation.type_annotation)?)));
                }
                a::TSSignature::TSMethodSignature(ms) => {
                    if matches!(ms.key, a::PropertyKey::NumericLiteral(_)) {
                        return self.unsupported(
                            ms.span,
                            "numeric property names in types",
                            "use string property names",
                        );
                    }
                    if ms.type_parameters.is_some()
                        || ms.params.rest.is_some()
                        || ms.params.items.iter().any(|p| p.optional)
                    {
                        return self.unsupported(
                            ms.span,
                            "generic, optional or rest parameters in method types",
                            "use concrete required parameters",
                        );
                    }
                    let Some(key) = ms.key.static_name() else {
                        return self.unsupported(ms.span, "computed keys in types", "");
                    };
                    let mut params = Vec::new();
                    for p in &ms.params.items {
                        let ann = self.annotation(p.type_annotation.as_ref())?;
                        params.push((ann.unwrap_or(TyAnn::Any(self.sp(p.span))), p.optional));
                    }
                    let ret = match &ms.return_type {
                        Some(t) => self.ty(&t.type_annotation)?,
                        None => TyAnn::Any(self.sp(ms.span)),
                    };
                    fields.push((key.to_string(), TyAnn::Fn(params, Box::new(ret)), ms.optional));
                }
                other => return self.unsupported(other.span(), "call and construct signatures", ""),
            }
        }
        Ok(TyAnn::Obj(fields))
    }
}

use oxc_span::GetSpan;

fn contains_constructor_return(body: &[Stmt]) -> bool {
    body.iter().any(|s| match s {
        Stmt::Return(Some(_), _) => true,
        Stmt::Block(b)
        | Stmt::While(_, b)
        | Stmt::DoWhile(b, _)
        | Stmt::For { body: b, .. }
        | Stmt::ForOf { body: b, .. }
        | Stmt::ForIn { body: b, .. } => contains_constructor_return(b),
        Stmt::If(_, a, b) => {
            contains_constructor_return(a) || b.as_ref().is_some_and(|b| contains_constructor_return(b))
        }
        Stmt::Try {
            block,
            handler,
            finalizer,
            ..
        } => {
            contains_constructor_return(block)
                || handler.as_ref().is_some_and(|b| contains_constructor_return(b))
                || finalizer.as_ref().is_some_and(|b| contains_constructor_return(b))
        }
        Stmt::Switch { cases, .. } => cases.iter().any(|(_, b)| contains_constructor_return(b)),
        Stmt::Labeled(_, body, _) => contains_constructor_return(std::slice::from_ref(body)),
        _ => false,
    })
}

fn check_parameter_pattern(pattern: &Pat, uninitialized: &[(&str, Span)], allow_functions: bool) -> Result<()> {
    match pattern {
        Pat::Default(inner, value) => {
            check_parameter_default(value, uninitialized, allow_functions)?;
            check_parameter_pattern(inner, uninitialized, allow_functions)?;
        }
        // Elements bind left to right, so a later element's default may read an earlier sibling:
        // `({ a, b = a })` and `([a, b = a])` are valid JavaScript.
        Pat::Obj { props, .. } => {
            let mut pending = uninitialized.to_vec();
            for (_, value) in props {
                check_parameter_pattern(value, &pending, allow_functions)?;
                initialized(value, &mut pending);
            }
        }
        Pat::Arr { elems, .. } => {
            let mut pending = uninitialized.to_vec();
            for value in elems.iter().flatten() {
                check_parameter_pattern(value, &pending, allow_functions)?;
                initialized(value, &mut pending);
            }
        }
        Pat::Id(..) => {}
    }
    Ok(())
}

/// Removes a visited pattern element's bindings from the not-yet-initialized set.
fn initialized(pattern: &Pat, pending: &mut Vec<(&str, Span)>) {
    let mut bound = Vec::new();
    pattern.bindings(&mut bound);
    pending.retain(|(name, span)| !bound.iter().any(|(b, s)| b == name && s == span));
}

/// Whether `value` reads the identifier `name` (not a string or a property key with that text).
pub fn reads_identifier(value: &Expr, name: &str) -> bool {
    check_parameter_default(value, &[(name, value.span)], true).is_err_and(|d| d.message.contains("current or a later"))
}

fn check_parameter_default(value: &Expr, uninitialized: &[(&str, Span)], allow_functions: bool) -> Result<()> {
    let children: Vec<&Expr> = match &value.kind {
        ExprKind::Func(function) if allow_functions => {
            for parameter in &function.params {
                if let Some(default) = &parameter.default {
                    check_parameter_default(default, uninitialized, false)?;
                }
                check_parameter_pattern(&parameter.pat, uninitialized, false)?;
            }
            match &function.body {
                FnBody::Expr(value) => check_parameter_default(value, uninitialized, false)?,
                FnBody::Block(body) => check_parameter_statements(body, uninitialized)?,
            }
            return Ok(());
        }
        ExprKind::Func(_) | ExprKind::Class(_) => {
            return Err(Diag::new(
                value.span,
                "parameter defaults that create functions with body bindings or classes aren't supported",
            )
            .help("define the function or class outside the parameter list and pass its name"));
        }
        ExprKind::Ident(name) if uninitialized.iter().any(|(binding, _)| *binding == name) => {
            return Err(Diag::new(
                value.span,
                "parameter defaults can't reference the current or a later parameter",
            )
            .help("initialize that value in the function body or pass it explicitly"));
        }
        ExprKind::Array(values) => values
            .iter()
            .map(|v| match v {
                ArrEl::Item(v) | ArrEl::Spread(v) => v,
            })
            .collect(),
        ExprKind::Object(properties) => {
            let mut children = Vec::new();
            for property in properties {
                match property {
                    Prop::KV(key, value) => {
                        if let PropKey::Computed(key) = key {
                            children.push(key);
                        }
                        children.push(value);
                    }
                    Prop::Spread(value) => children.push(value),
                }
            }
            children
        }
        ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args, .. } => std::iter::once(callee.as_ref())
            .chain(args.iter().map(|v| match v {
                ArrEl::Item(v) | ArrEl::Spread(v) => v,
            }))
            .collect(),
        ExprKind::Yield { arg: None, .. } => vec![],
        ExprKind::Yield { arg: Some(value), .. }
        | ExprKind::Unary(_, value)
        | ExprKind::Delete(value)
        | ExprKind::IsNullish(value, _)
        | ExprKind::Chain(value)
        | ExprKind::Await(value)
        | ExprKind::As(value, _)
        | ExprKind::NonNull(value)
        | ExprKind::Member { obj: value, .. }
        | ExprKind::Update { target: value, .. } => vec![value],
        ExprKind::Binary(_, a, b)
        | ExprKind::Logical(_, a, b)
        | ExprKind::Instanceof(a, b)
        | ExprKind::In(a, b)
        | ExprKind::Index { obj: a, index: b, .. }
        | ExprKind::Assign {
            target: a, value: b, ..
        } => vec![a, b],
        ExprKind::Cond(a, b, c) => vec![a, b, c],
        ExprKind::AssignPattern(pattern, value) => pattern.exprs().into_iter().chain([&**value]).collect(),
        ExprKind::Template(_, values) | ExprKind::Sequence(values) => values.iter().collect(),
        ExprKind::Ident(_)
        | ExprKind::Num(_)
        | ExprKind::Str(_)
        | ExprKind::Bool(_)
        | ExprKind::Null
        | ExprKind::Undefined
        | ExprKind::Regex { .. }
        | ExprKind::BigInt(_)
        | ExprKind::TemplateStrings { .. } => Vec::new(),
    };
    for child in children {
        check_parameter_default(child, uninitialized, allow_functions)?;
    }
    Ok(())
}

fn parameter_body_has_bindings(body: &FnBody) -> bool {
    fn statements(body: &[Stmt]) -> bool {
        body.iter().any(|statement| match statement {
            Stmt::Decl { .. } | Stmt::Func(_) | Stmt::ForOf { .. } | Stmt::ForIn { .. } => true,
            Stmt::Block(body) | Stmt::While(_, body) | Stmt::DoWhile(body, _) => statements(body),
            Stmt::Labeled(_, body, _) => statements(std::slice::from_ref(body)),
            Stmt::If(_, yes, no) => statements(yes) || no.as_ref().is_some_and(|body| statements(body)),
            Stmt::For { init, body, .. } => {
                init.as_ref().is_some_and(|init| statements(std::slice::from_ref(init))) || statements(body)
            }
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                param.is_some()
                    || statements(block)
                    || handler.as_ref().is_some_and(|body| statements(body))
                    || finalizer.as_ref().is_some_and(|body| statements(body))
            }
            Stmt::Switch { cases, .. } => cases.iter().any(|(_, body)| statements(body)),
            Stmt::Exported(statement) => statements(std::slice::from_ref(statement)),
            _ => false,
        })
    }
    match body {
        FnBody::Expr(_) => false,
        FnBody::Block(body) => statements(body),
    }
}

fn check_parameter_statements(body: &[Stmt], uninitialized: &[(&str, Span)]) -> Result<()> {
    for statement in body {
        match statement {
            Stmt::Decl { pat, init, .. } => {
                check_parameter_pattern(pat, uninitialized, false)?;
                if let Some(value) = init {
                    check_parameter_default(value, uninitialized, false)?;
                }
            }
            Stmt::Func(function) => {
                return Err(Diag::new(
                    function.span,
                    "parameter defaults with nested functions aren't supported",
                )
                .help("define the function outside the parameter list and pass its name"));
            }
            Stmt::Expr(value) | Stmt::Throw(value) | Stmt::ExportDefault(value) | Stmt::Return(Some(value), _) => {
                check_parameter_default(value, uninitialized, false)?
            }
            Stmt::Block(body) => check_parameter_statements(body, uninitialized)?,
            Stmt::While(value, body) | Stmt::DoWhile(body, value) => {
                check_parameter_default(value, uninitialized, false)?;
                check_parameter_statements(body, uninitialized)?;
            }
            Stmt::Labeled(_, body, _) => check_parameter_statements(std::slice::from_ref(body), uninitialized)?,
            Stmt::If(value, yes, no) => {
                check_parameter_default(value, uninitialized, false)?;
                check_parameter_statements(yes, uninitialized)?;
                if let Some(body) = no {
                    check_parameter_statements(body, uninitialized)?;
                }
            }
            Stmt::For {
                init,
                test,
                update,
                body,
                ..
            } => {
                if let Some(init) = init {
                    check_parameter_statements(std::slice::from_ref(init), uninitialized)?;
                }
                for value in [test, update].into_iter().flatten() {
                    check_parameter_default(value, uninitialized, false)?;
                }
                check_parameter_statements(body, uninitialized)?;
            }
            Stmt::ForOf { pat, iter, body, .. } => {
                check_parameter_pattern(pat, uninitialized, false)?;
                check_parameter_default(iter, uninitialized, false)?;
                check_parameter_statements(body, uninitialized)?;
            }
            Stmt::ForIn { target, iter, body, .. } => {
                check_parameter_default(target, uninitialized, false)?;
                check_parameter_default(iter, uninitialized, false)?;
                check_parameter_statements(body, uninitialized)?;
            }
            Stmt::Try {
                block,
                handler,
                finalizer,
                ..
            } => {
                check_parameter_statements(block, uninitialized)?;
                if let Some(body) = handler {
                    check_parameter_statements(body, uninitialized)?;
                }
                if let Some(body) = finalizer {
                    check_parameter_statements(body, uninitialized)?;
                }
            }
            Stmt::Switch { disc, cases, .. } => {
                check_parameter_default(disc, uninitialized, false)?;
                for (test, body) in cases {
                    if let Some(test) = test {
                        check_parameter_default(test, uninitialized, false)?;
                    }
                    check_parameter_statements(body, uninitialized)?;
                }
            }
            Stmt::Exported(statement) => check_parameter_statements(std::slice::from_ref(statement), uninitialized)?,
            Stmt::Return(None, _)
            | Stmt::Break(..)
            | Stmt::Continue(..)
            | Stmt::TypeAlias { .. }
            | Stmt::Import { .. }
            | Stmt::ExportNames { .. }
            | Stmt::ExportAll { .. } => {}
        }
    }
    Ok(())
}

fn check_parameter_defaults(parameters: &[Param], body: &FnBody) -> Result<()> {
    let allow_functions = !parameter_body_has_bindings(body);
    for (index, parameter) in parameters.iter().enumerate() {
        let mut uninitialized = Vec::new();
        for later in &parameters[index..] {
            later.pat.bindings(&mut uninitialized);
        }
        if let Some(value) = &parameter.default {
            check_parameter_default(value, &uninitialized, allow_functions)?;
        }
        check_parameter_pattern(&parameter.pat, &uninitialized, allow_functions)?;
    }
    Ok(())
}

/// Class member names; private names keep their `#` so they can never collide with public keys.
fn class_member_name(key: &a::PropertyKey<'_>) -> Option<String> {
    match key {
        a::PropertyKey::PrivateIdentifier(p) => Some(format!("#{}", p.name)),
        other => other.static_name().map(|n| n.to_string()),
    }
}

/// Whether a function's own body assigns its name (not shadowed by a parameter, a `var` or a
/// nested binding). A named function expression's name is immutable there: writing it throws.
fn func_assigns_own_name(func: &Func, name: &str) -> bool {
    let mut params = Vec::new();
    for p in &func.params {
        p.pat.bindings(&mut params);
    }
    if params.iter().any(|(n, _)| *n == name) {
        return false;
    }
    // Parameter defaults run in the function's scope, where the name is still the function.
    let mut defaults = Vec::new();
    for p in &func.params {
        if let Some(d) = &p.default {
            defaults.push(d);
        }
        pattern_defaults(&p.pat, &mut defaults);
    }
    if defaults.iter().any(|d| expr_assigns(d, name)) {
        return true;
    }
    match &func.body {
        FnBody::Expr(e) => expr_assigns(e, name),
        // A `var` anywhere in the body (not inside a nested function) is hoisted to the
        // function and shadows the name everywhere in it.
        FnBody::Block(body) => !declares(body, name) && !hoists_var(body, name) && stmts_assign(body, name),
    }
}

/// The default expressions inside a binding pattern.
fn pattern_defaults<'a>(pattern: &'a Pat, out: &mut Vec<&'a Expr>) {
    match pattern {
        Pat::Id(..) => {}
        Pat::Default(inner, value) => {
            out.push(value);
            pattern_defaults(inner, out);
        }
        Pat::Obj { props, .. } => props.iter().for_each(|(_, p)| pattern_defaults(p, out)),
        Pat::Arr { elems, .. } => elems.iter().flatten().for_each(|p| pattern_defaults(p, out)),
    }
}

/// Whether `name` is a `var` declared anywhere in a function body, outside nested functions.
fn hoists_var(body: &[Stmt], name: &str) -> bool {
    body.iter().any(|s| match s {
        Stmt::Decl {
            kind: DeclKind::Var,
            pat,
            ..
        } => {
            let mut names = Vec::new();
            pat.bindings(&mut names);
            names.iter().any(|(n, _)| *n == name)
        }
        Stmt::For { init, body, .. } => {
            init.as_deref()
                .is_some_and(|i| hoists_var(std::slice::from_ref(i), name))
                || hoists_var(body, name)
        }
        Stmt::ForOf {
            kind: DeclKind::Var,
            pat,
            body,
            ..
        } => {
            let mut names = Vec::new();
            pat.bindings(&mut names);
            names.iter().any(|(n, _)| *n == name) || hoists_var(body, name)
        }
        Stmt::ForIn {
            kind: Some(DeclKind::Var),
            target,
            body,
            ..
        } => matches!(&target.kind, ExprKind::Ident(n) if n == name) || hoists_var(body, name),
        Stmt::ForOf { body, .. }
        | Stmt::ForIn { body, .. }
        | Stmt::While(_, body)
        | Stmt::DoWhile(body, _)
        | Stmt::Block(body) => hoists_var(body, name),
        Stmt::If(_, yes, no) => hoists_var(yes, name) || no.as_deref().is_some_and(|b| hoists_var(b, name)),
        Stmt::Labeled(_, inner, _) | Stmt::Exported(inner) => hoists_var(std::slice::from_ref(inner), name),
        Stmt::Try {
            block,
            handler,
            finalizer,
            ..
        } => {
            hoists_var(block, name)
                || handler.as_deref().is_some_and(|b| hoists_var(b, name))
                || finalizer.as_deref().is_some_and(|b| hoists_var(b, name))
        }
        Stmt::Switch { cases, .. } => cases.iter().any(|(_, b)| hoists_var(b, name)),
        _ => false,
    })
}

/// Whether a block declares `name` itself (a `var`, `let`, `const` or function), which shadows it.
fn declares(body: &[Stmt], name: &str) -> bool {
    body.iter().any(|s| match s {
        Stmt::Decl { pat, .. } => {
            let mut names = Vec::new();
            pat.bindings(&mut names);
            names.iter().any(|(n, _)| *n == name)
        }
        Stmt::Func(f) => f.name.as_deref() == Some(name),
        _ => false,
    })
}

fn stmts_assign(body: &[Stmt], name: &str) -> bool {
    body.iter().any(|s| stmt_assigns(s, name))
}

fn stmt_assigns(s: &Stmt, name: &str) -> bool {
    let e = |x: &Expr| expr_assigns(x, name);
    let b = |xs: &[Stmt]| !declares(xs, name) && stmts_assign(xs, name);
    match s {
        Stmt::Decl { init, .. } => init.as_ref().is_some_and(e),
        Stmt::Func(f) => f.name.as_deref().is_none_or(|n| n != name) && func_assigns_own_name(f, name),
        Stmt::Expr(x) | Stmt::Throw(x) | Stmt::ExportDefault(x) => e(x),
        Stmt::Return(x, _) => x.as_ref().is_some_and(e),
        Stmt::If(t, yes, no) => e(t) || b(yes) || no.as_deref().is_some_and(b),
        Stmt::For {
            init,
            test,
            update,
            body,
            ..
        } => {
            init.as_deref().is_some_and(|i| stmt_assigns(i, name))
                || test.as_ref().is_some_and(e)
                || update.as_ref().is_some_and(e)
                || b(body)
        }
        Stmt::ForOf { iter, body, .. } => e(iter) || b(body),
        Stmt::ForIn { target, iter, body, .. } => {
            matches!(&target.kind, ExprKind::Ident(n) if n == name) || e(iter) || b(body)
        }
        Stmt::While(t, body) | Stmt::DoWhile(body, t) => e(t) || b(body),
        Stmt::Labeled(_, body, _) => stmt_assigns(body, name),
        Stmt::Try {
            block,
            handler,
            finalizer,
            ..
        } => b(block) || handler.as_deref().is_some_and(b) || finalizer.as_deref().is_some_and(b),
        Stmt::Switch { disc, cases, .. } => {
            e(disc) || cases.iter().any(|(t, body)| t.as_ref().is_some_and(e) || b(body))
        }
        Stmt::Block(body) => b(body),
        Stmt::Exported(inner) => stmt_assigns(inner, name),
        _ => false,
    }
}

fn expr_assigns(x: &Expr, name: &str) -> bool {
    let target_is_name = |t: &Expr| matches!(&t.kind, ExprKind::Ident(n) if n == name);
    match &x.kind {
        ExprKind::Assign { target, value, .. } => {
            target_is_name(target) || expr_assigns(target, name) || expr_assigns(value, name)
        }
        ExprKind::Update { target, .. } => target_is_name(target),
        ExprKind::AssignPattern(pattern, value) => {
            pattern.targets().iter().any(|t| target_is_name(t)) || expr_assigns(value, name)
        }
        ExprKind::Func(f) => func_assigns_own_name(f, name),
        _ => {
            let mut children = Vec::new();
            expr_children(x, &mut children);
            children.into_iter().any(|c| expr_assigns(c, name))
        }
    }
}

/// The direct sub-expressions of an expression (not inside nested functions).
fn expr_children<'a>(x: &'a Expr, out: &mut Vec<&'a Expr>) {
    let item = |a: &'a ArrEl| match a {
        ArrEl::Item(v) | ArrEl::Spread(v) => v,
    };
    match &x.kind {
        ExprKind::Array(items) => out.extend(items.iter().map(item)),
        ExprKind::Object(props) => {
            for p in props {
                match p {
                    Prop::KV(key, value) => {
                        if let PropKey::Computed(k) = key {
                            out.push(k);
                        }
                        out.push(value);
                    }
                    Prop::Spread(v) => out.push(v),
                }
            }
        }
        ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args, .. } => {
            out.push(callee);
            out.extend(args.iter().map(item));
        }
        ExprKind::Unary(_, v)
        | ExprKind::Delete(v)
        | ExprKind::IsNullish(v, _)
        | ExprKind::Chain(v)
        | ExprKind::Await(v)
        | ExprKind::As(v, _)
        | ExprKind::NonNull(v)
        | ExprKind::Member { obj: v, .. } => out.push(v),
        ExprKind::Binary(_, a, b)
        | ExprKind::Logical(_, a, b)
        | ExprKind::Instanceof(a, b)
        | ExprKind::In(a, b)
        | ExprKind::Index { obj: a, index: b, .. } => {
            out.push(a);
            out.push(b);
        }
        ExprKind::Cond(a, b, c) => out.extend([&**a, &**b, &**c]),
        ExprKind::Template(_, values) | ExprKind::Sequence(values) => out.extend(values.iter()),
        _ => {}
    }
}
