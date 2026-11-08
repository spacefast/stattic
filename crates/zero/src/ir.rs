//! Zero's own intermediate form. oxc's arena AST is lowered into this once per
//! module (see `lower.rs`); both backends read it: the build-time evaluator
//! (`eval.rs`) and the PHP compiler (`compile.rs`). Anything outside the
//! supported subset is rejected during lowering, so neither backend has to
//! handle it.

use crate::diag::Span;
use std::rc::Rc;

pub type P<T> = Box<T>;

#[derive(Debug)]
pub struct Module {
    pub id: usize,
    pub file: u32,
    pub path: std::path::PathBuf,
    pub body: Vec<Stmt>,
}

#[derive(Debug)]
pub struct Func {
    pub name: Option<String>,
    /// Arrows inherit `arguments` from their defining function.
    pub is_arrow: bool,
    pub params: Vec<Param>,
    pub body: FnBody,
    pub is_async: bool,
    pub ret: Option<TyAnn>,
    pub type_params: Vec<String>,
    pub span: Span,
    /// Module the function was declared in, for resolving types.
    pub module: usize,
}

#[derive(Debug)]
pub enum FnBody {
    Expr(Expr),
    Block(Vec<Stmt>),
}

#[derive(Debug)]
pub struct Param {
    pub pat: Pat,
    pub ann: Option<TyAnn>,
    pub default: Option<Expr>,
    pub optional: bool,
    pub rest: bool,
    pub span: Span,
}

#[derive(Debug)]
pub struct Class {
    pub name: String,
    pub base: Option<String>,
    pub fields: Vec<ClassField>,
    pub methods: Vec<(String, bool, Rc<Func>)>,
    pub constructor: Option<Rc<Func>>,
    pub span: Span,
}

#[derive(Debug)]
pub struct ClassField {
    pub name: String,
    pub is_static: bool,
    pub ann: Option<TyAnn>,
    pub init: Option<Expr>,
    pub span: Span,
}

impl ClassField {
    pub fn initializer(&self, module: usize) -> Option<Rc<Func>> {
        Some(Rc::new(Func {
            name: None,
            is_arrow: true,
            params: vec![Param {
                pat: Pat::Id(
                    "this".into(),
                    Span {
                        end: self.span.start,
                        ..self.span
                    },
                ),
                ann: None,
                default: None,
                optional: false,
                rest: false,
                span: self.span,
            }],
            body: FnBody::Expr(self.init.as_ref()?.clone()),
            is_async: false,
            ret: self.ann.clone(),
            type_params: vec![],
            span: self.span,
            module,
        }))
    }
}

#[derive(Debug, Clone)]
pub enum Pat {
    Id(String, Span),
    Obj {
        props: Vec<(String, Pat)>,
        rest: Option<(String, Span)>,
        span: Span,
    },
    Arr {
        elems: Vec<Option<Pat>>,
        rest: Option<(String, Span)>,
        span: Span,
    },
    Default(P<Pat>, Rc<Expr>),
}

impl Pat {
    pub fn span(&self) -> Span {
        match self {
            Pat::Id(_, s) | Pat::Obj { span: s, .. } | Pat::Arr { span: s, .. } => *s,
            Pat::Default(p, _) => p.span(),
        }
    }

    /// Binding names and their source spans, in declaration order.
    pub fn bindings<'a>(&'a self, out: &mut Vec<(&'a str, Span)>) {
        match self {
            Pat::Id(name, span) => out.push((name, *span)),
            Pat::Obj { props, rest, .. } => {
                for (_, pat) in props {
                    pat.bindings(out);
                }
                if let Some((name, span)) = rest {
                    out.push((name, *span));
                }
            }
            Pat::Arr { elems, rest, .. } => {
                for pat in elems.iter().flatten() {
                    pat.bindings(out);
                }
                if let Some((name, span)) = rest {
                    out.push((name, *span));
                }
            }
            Pat::Default(pat, _) => pat.bindings(out),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeclKind {
    Const,
    Let,
    Var,
}

#[derive(Debug)]
pub enum ImportName {
    Default,
    Namespace,
    Named(String),
}

#[derive(Debug)]
pub struct ImportSpec {
    pub imported: ImportName,
    pub local: String,
    pub type_only: bool,
    pub span: Span,
}

#[derive(Debug)]
pub enum Stmt {
    Decl {
        kind: DeclKind,
        pat: Pat,
        ann: Option<TyAnn>,
        init: Option<Expr>,
        span: Span,
    },
    Func(Rc<Func>),
    Expr(Expr),
    Return(Option<Expr>, Span),
    If(Expr, Vec<Stmt>, Option<Vec<Stmt>>),
    For {
        init: Option<P<Stmt>>,
        test: Option<Expr>,
        update: Option<Expr>,
        body: Vec<Stmt>,
        span: Span,
    },
    ForOf {
        kind: DeclKind,
        pat: Pat,
        iter: Expr,
        body: Vec<Stmt>,
        span: Span,
    },
    ForIn {
        /// `None` assigns an existing target; a declaration binds an identifier.
        kind: Option<DeclKind>,
        target: Expr,
        iter: Expr,
        body: Vec<Stmt>,
        span: Span,
    },
    While(Expr, Vec<Stmt>),
    Break(Span),
    Continue(Span),
    Throw(Expr),
    Try {
        block: Vec<Stmt>,
        param: Option<(String, Span)>,
        handler: Option<Vec<Stmt>>,
        finalizer: Option<Vec<Stmt>>,
    },
    Switch {
        disc: Expr,
        cases: Vec<(Option<Expr>, Vec<Stmt>)>,
        span: Span,
    },
    Block(Vec<Stmt>),
    TypeAlias {
        name: String,
        params: Vec<TypeParam>,
        ty: TyAnn,
    },
    Import {
        source: String,
        specs: Vec<ImportSpec>,
        type_only: bool,
        span: Span,
    },
    /// `export { a as b }` / `export { a } from "x"` — re-exports.
    ExportNames {
        names: Vec<(String, String)>,
        from: Option<String>,
        span: Span,
    },
    /// `export * from "x"`
    ExportAll {
        from: String,
        span: Span,
    },
    ExportDefault(Expr),
    /// Marks the following declaration's names as exported.
    Exported(P<Stmt>),
}

#[derive(Debug, Clone)]
pub struct Expr {
    pub kind: ExprKind,
    pub span: Span,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum UnOp {
    Neg,
    Plus,
    Not,
    TypeOf,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BinOp {
    Add,
    Sub,
    Mul,
    Div,
    Rem,
    Exp,
    Lt,
    Le,
    Gt,
    Ge,
    /// `===`, and `==` against a null/undefined literal (lowered to `IsNullish`).
    Eq,
    Ne,
    LooseEq,
    LooseNe,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LogOp {
    And,
    Or,
    Nullish,
}

#[derive(Debug, Clone)]
pub enum ArrEl {
    Item(Expr),
    Spread(Expr),
}

#[derive(Debug, Clone)]
pub enum PropKey {
    Static(String),
    Computed(Expr),
}

#[derive(Debug, Clone)]
pub enum Prop {
    KV(PropKey, Expr),
    Spread(Expr),
}

#[derive(Debug, Clone)]
pub enum ExprKind {
    Class(Rc<Class>),
    Instanceof(P<Expr>, P<Expr>),
    Num(f64),
    Str(String),
    Bool(bool),
    Null,
    Undefined,
    Template(Vec<String>, Vec<Expr>),
    Regex {
        pattern: String,
        flags: String,
    },
    Ident(String),
    Array(Vec<ArrEl>),
    Object(Vec<Prop>),
    Func(Rc<Func>),
    Unary(UnOp, P<Expr>),
    Binary(BinOp, P<Expr>, P<Expr>),
    /// `x == null` / `x != null`: nullish check (true when `negated` is false and x is nullish).
    IsNullish(P<Expr>, bool),
    Logical(LogOp, P<Expr>, P<Expr>),
    Cond(P<Expr>, P<Expr>, P<Expr>),
    Sequence(Vec<Expr>),
    /// `op` is `None` for plain `=`.
    Assign {
        op: Option<AssignOp>,
        target: P<Expr>,
        value: P<Expr>,
    },
    Update {
        inc: bool,
        prefix: bool,
        target: P<Expr>,
    },
    Call {
        callee: P<Expr>,
        args: Vec<ArrEl>,
        optional: bool,
        type_args: Vec<TyAnn>,
    },
    New {
        callee: P<Expr>,
        args: Vec<ArrEl>,
    },
    Member {
        obj: P<Expr>,
        prop: String,
        optional: bool,
        prop_span: Span,
    },
    Index {
        obj: P<Expr>,
        index: P<Expr>,
        optional: bool,
    },
    /// Optional-chain boundary: a nullish `?.` inside short-circuits to undefined here.
    Chain(P<Expr>),
    Await(P<Expr>),
    /// `e as T` / `<T>e` / `e satisfies T`. `as const` is dropped during lowering.
    As(P<Expr>, TyAnn),
    NonNull(P<Expr>),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AssignOp {
    Bin(BinOp),
    Log(LogOp),
}

#[derive(Debug, Clone)]
pub struct TypeParam {
    pub name: String,
    pub constraint: Option<TyAnn>,
    pub default: Option<TyAnn>,
    pub span: Span,
}

/// TypeScript type annotations, kept syntactic until PHP compilation.
#[derive(Debug, Clone)]
pub enum TyAnn {
    Num,
    NumLit(f64),
    Str,
    StrLit(String),
    Bool,
    BoolLit(bool),
    Null,
    Undefined,
    Void,
    Any(Span),
    Unknown,
    Never,
    Array(P<TyAnn>),
    Tuple(Vec<TyAnn>),
    Obj(Vec<(String, TyAnn, bool)>),
    /// `Record<string, T>` and `{ [k: string]: T }`.
    Map(P<TyAnn>),
    Union(Vec<TyAnn>),
    Intersection(Vec<TyAnn>, Span),
    Fn(Vec<(TyAnn, bool)>, P<TyAnn>),
    Predicate(String, P<TyAnn>, Span),
    /// Named reference with type arguments, e.g. `Item`, `Promise<T>`, `InferSelect<typeof items>`.
    Ref(String, Vec<TyAnn>, Span),
    /// `typeof a.b.c`
    Query(Vec<String>, Span),
    KeyOf(P<TyAnn>, Span),
    Indexed(P<TyAnn>, P<TyAnn>, Span),
    Mapped {
        key: String,
        keys: P<TyAnn>,
        value: P<TyAnn>,
        optional: Option<bool>,
        span: Span,
    },
    Conditional {
        check: P<TyAnn>,
        extends: P<TyAnn>,
        yes: P<TyAnn>,
        no: P<TyAnn>,
        span: Span,
    },
}
