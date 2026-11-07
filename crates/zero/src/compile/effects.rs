//! Flow-insensitive mutation effects, computed before return/capture inference.
//! Binding spans distinguish shadowed locals; call edges carry parameter paths
//! back to arguments without treating scalar parameter assignment as a write.

use super::*;

#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub(super) enum Step {
    Field(String),
    Index,
    /// Array mutators may remove every element, invalidating a lower bound.
    Resize,
    /// A raw JavaScript write can replace the value's entire static domain.
    DynamicValue,
    /// A bounded JavaScript write can replace any value below this prefix.
    DynamicDescendants,
    /// Recursive calls can reach descendants beyond the program's explicit paths.
    Descendants,
    Result,
}

#[derive(Clone, Debug, PartialEq, Eq, Hash)]
struct Place {
    binding: Span,
    path: Vec<Step>,
}

impl Place {
    fn new(binding: Span) -> Self {
        Self {
            binding,
            path: Vec::new(),
        }
    }
    fn at(&self, step: Step) -> Self {
        let mut place = self.clone();
        place.path.push(step);
        place
    }
}

/// An indexed read can select a named object property as well as an array
/// element. Keep the remainder so container operations preserve nested values.
fn projected_suffix<'a>(path: &'a [Step], prefix: &[Step]) -> Option<&'a [Step]> {
    for (i, step) in prefix.iter().enumerate() {
        if matches!(step, Step::Descendants) {
            return Some(&[]);
        }
        let actual = path.get(i)?;
        if actual == &Step::DynamicDescendants {
            return Some(&path[i..]);
        }
        if actual != step
            && !matches!(
                (actual, step),
                (Step::Field(_), Step::Index) | (Step::Index, Step::Field(_))
            )
        {
            return None;
        }
    }
    path.get(prefix.len()..)
}

fn callable_at(value: &Place, callee: &Place) -> bool {
    value.binding == callee.binding && projected_suffix(&value.path, &callee.path).is_some_and(<[Step]>::is_empty)
}

#[derive(Default)]
pub(super) struct Effects {
    captured: HashSet<Span>,
    captured_data: HashMap<(u8, usize), Place>,
    numeric: HashMap<Span, HashSet<Vec<Step>>>,
    incoming: HashMap<Span, HashSet<Vec<Step>>>,
    incoming_writes: HashMap<Span, HashSet<Vec<Step>>>,
    capture_writes: HashMap<Span, HashSet<Place>>,
    events: HashMap<Span, HashSet<Place>>,
    write_events: HashMap<Span, HashSet<Place>>,
    expression_writes: HashMap<Span, HashSet<Place>>,
    loop_writes: HashMap<Span, HashSet<Place>>,
}

impl Effects {
    pub fn captured(&self, binding: Span) -> bool {
        self.captured.contains(&binding)
    }

    pub fn analyze(roots: &[Root], schema: &Schema, program: &crate::loader::Program) -> Self {
        let javascript_modules = program
            .modules
            .iter()
            .filter(|module| {
                module
                    .path
                    .extension()
                    .and_then(|extension| extension.to_str())
                    .is_some_and(|extension| matches!(extension, "js" | "mjs" | "cjs"))
            })
            .map(|module| module.id)
            .collect();
        let mut graph = Graph {
            javascript_modules,
            ..Graph::default()
        };
        for root in roots {
            graph.function(&root.closure.func, &[], &root.closure.env);
            graph.reachable.insert(root.closure.func.span);
            graph.roots.insert(root.closure.func.span);
            let f = root.closure.func.span;
            let params = graph.functions[&f].clone();
            match &root.slot {
                Slot::Validate { at } if params.len() > 1 => {
                    let mut field = params[1].at(Step::Field("data".into()));
                    let mut column_path = at.clone();
                    column_path.path.truncate(1);
                    field = field.at(Step::Field(at.path[0].clone()));
                    let mut rest = &at.path[1..];
                    while !rest.is_empty() {
                        let col = schema.column_at(&column_path);
                        let key = if col.ty == ColType::Blocks {
                            field = field.at(Step::Index);
                            column_path.path.push(rest[0].clone());
                            rest = &rest[1..];
                            &rest[0]
                        } else {
                            if col.ty == ColType::Repeater {
                                field = field.at(Step::Index);
                            }
                            &rest[0]
                        };
                        field = field.at(Step::Field(key.clone()));
                        column_path.path.push(key.clone());
                        rest = &rest[1..];
                    }
                    graph.copies.insert((field, params[0].clone(), f));
                }
                Slot::Hook {
                    kind: HookKind::BeforeCreate | HookKind::BeforeUpdate,
                    ..
                } if params.len() > 1 => {
                    let ctx = params.last().unwrap().at(Step::Field("data".into()));
                    graph.copies.insert((ctx, params[0].clone(), f));
                }
                _ => {}
            }
        }
        graph.solve()
    }

    pub fn captured_data_type(&self, value: &Value, ty: &Ty) -> Ty {
        let key = match value {
            Value::Array(a) => (0, Rc::as_ptr(a) as usize),
            Value::Object(o) => (1, Rc::as_ptr(o) as usize),
            _ => return ty.clone(),
        };
        self.captured_data
            .get(&key)
            .map_or_else(|| ty.clone(), |p| self.read_view_type(p.binding, &p.path, ty))
    }

    pub fn read_view_type(&self, binding: Span, view: &[Step], ty: &Ty) -> Ty {
        self.numeric.get(&binding).map_or_else(
            || ty.clone(),
            |paths| {
                paths
                    .iter()
                    .filter(|path| path.starts_with(view))
                    .fold(ty.clone(), |ty, path| widen(&ty, &path[view.len()..]))
            },
        )
    }

    /// Keep authored parameter constraints separate from numeric values and
    /// tuple positions changed by the caller before invoking us.
    pub fn parameter_type(&self, binding: Span, ty: &Ty) -> Ty {
        let ty = self.incoming_writes.get(&binding).map_or_else(
            || ty.clone(),
            |paths| paths.iter().fold(ty.clone(), |ty, path| restore(&ty, &ty, path)),
        );
        self.incoming.get(&binding).map_or_else(
            || ty.clone(),
            |paths| paths.iter().fold(ty.clone(), |ty, path| widen(&ty, path)),
        )
    }

    /// A write invalidates refinements of that location and its descendants.
    /// Sibling refinements and the authored assignment constraint remain intact.
    pub fn invalidate(&self, event: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        self.write_events.get(&event).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        )
    }

    pub fn invalidate_loop(&self, event: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        self.loop_writes.get(&event).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        )
    }

    pub fn changes_refinement(&self, expression: Span, binding: Span, view: &[Step]) -> bool {
        self.expression_writes.get(&expression).is_some_and(|writes| {
            writes.iter().any(|p| {
                p.binding == binding
                    && (projected_suffix(&p.path, view).is_some() || projected_suffix(view, &p.path).is_some())
            })
        })
    }

    pub fn capture_type(&self, function: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        let ty = self.capture_writes.get(&function).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        );
        self.read_view_type(binding, view, &ty)
    }

    pub fn write_type(&self, event: Span, binding: Span, view: &[Step], ty: &Ty) -> Ty {
        self.events.get(&event).map_or_else(
            || ty.clone(),
            |paths| {
                paths
                    .iter()
                    .filter(|p| p.binding == binding && p.path.starts_with(view))
                    .fold(ty.clone(), |ty, p| widen(&ty, &p.path[view.len()..]))
            },
        )
    }
}

fn invalidate_paths(paths: &HashSet<Place>, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
    paths.iter().filter(|p| p.binding == binding).fold(ty.clone(), |ty, p| {
        if let Some(path) = projected_suffix(&p.path, view) {
            restore(&ty, constraint, path)
        } else if projected_suffix(view, &p.path).is_some() {
            restore_constraint(&ty, constraint)
        } else {
            ty
        }
    })
}

// Dropping a narrowing must not undo a tuple/number view widened by a prior mutation.
fn restore_constraint(ty: &Ty, constraint: &Ty) -> Ty {
    match (ty, constraint) {
        (Ty::Dynamic, _) => Ty::Dynamic,
        (Ty::Instance(id, fields), Ty::Instance(_, original)) => {
            Ty::Instance(id.clone(), Box::new(restore_constraint(fields, original)))
        }
        (Ty::Num, t) if t.is_num() => Ty::Num,
        (Ty::Array(t), Ty::NonEmptyArray(c)) => Ty::Array(Box::new(restore_constraint(t, c))),
        (Ty::NonEmptyArray(t), Ty::NonEmptyArray(c)) => Ty::NonEmptyArray(Box::new(restore_constraint(t, c))),
        (Ty::Array(t), Ty::Tuple(ts) | Ty::ArrayLiteral(ts)) => Ty::Array(Box::new(Ty::union(
            std::iter::once((**t).clone()).chain(ts.iter().cloned()),
        ))),
        (Ty::Array(t), Ty::Array(c)) => Ty::Array(Box::new(restore_constraint(t, c))),
        (Ty::Obj(fields), Ty::Obj(original)) => Ty::Obj(
            original
                .iter()
                .map(|(k, (c, opt))| {
                    (
                        k.clone(),
                        (
                            fields
                                .get(k)
                                .map_or_else(|| c.clone(), |(t, _)| restore_constraint(t, c)),
                            *opt,
                        ),
                    )
                })
                .collect(),
        ),
        _ => constraint.clone(),
    }
}

fn restore(ty: &Ty, constraint: &Ty, path: &[Step]) -> Ty {
    if let (Ty::Instance(id, fields), Ty::Instance(_, original)) = (ty, constraint) {
        return Ty::Instance(id.clone(), Box::new(restore(fields, original, path)));
    }
    let Some((step, rest)) = path.split_first() else {
        return restore_constraint(ty, constraint);
    };
    match (ty, step) {
        (_, Step::DynamicValue | Step::DynamicDescendants) => Ty::Dynamic,
        (Ty::NonEmptyArray(t), Step::Resize | Step::Descendants) => Ty::Array(t.clone()),
        (Ty::NonEmptyArray(t), Step::Index) => {
            let original = match constraint {
                Ty::NonEmptyArray(c) | Ty::Array(c) => c.as_ref(),
                _ => t.as_ref(),
            };
            Ty::NonEmptyArray(Box::new(restore(t, original, rest)))
        }
        (Ty::Union(ts), _) => Ty::union(ts.iter().map(|t| restore(t, constraint, path))),
        (_, Step::Descendants) => {
            // A member write can replace descendants, never the receiver binding.
            let constraint = if ty.is_nullable() {
                constraint.clone()
            } else {
                constraint.non_null()
            };
            restore_constraint(ty, &constraint)
        }
        (Ty::Obj(fields), Step::Field(field)) => {
            let mut fields = fields.clone();
            if let Some((ty, _)) = fields.get_mut(field)
                && let Some((original, _)) = constraint.non_null().field(field)
            {
                *ty = restore(ty, &original, rest);
            }
            Ty::Obj(fields)
        }
        (Ty::Obj(fields), Step::Index) => Ty::Obj(
            fields
                .iter()
                .map(|(key, (ty, optional))| {
                    let original = constraint
                        .non_null()
                        .field(key)
                        .map(|(t, _)| t)
                        .unwrap_or_else(|| ty.clone());
                    (key.clone(), (restore(ty, &original, rest), *optional))
                })
                .collect(),
        ),
        (Ty::Array(t), Step::Index) => match constraint.non_null() {
            Ty::Array(original) => Ty::Array(Box::new(restore(t, &original, rest))),
            _ => ty.clone(),
        },
        (Ty::Map(t), _) => match constraint.non_null() {
            Ty::Map(original) => Ty::Map(Box::new(restore(t, &original, rest))),
            _ => ty.clone(),
        },
        (Ty::Tuple(ts), Step::Index) | (Ty::ArrayLiteral(ts), Step::Index) => {
            let original = match constraint.non_null() {
                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => ts,
                _ => return ty.clone(),
            };
            if rest.is_empty() {
                // Mutators can reorder, replace, grow or shrink a tuple. Its
                // read view becomes an array; authored constraints stay fixed.
                return Ty::Array(Box::new(Ty::union(ts.iter().chain(&original).cloned())));
            }
            let values = ts.iter().zip(&original).map(|(t, c)| restore(t, c, rest)).collect();
            if matches!(ty, Ty::Tuple(_)) {
                Ty::Tuple(values)
            } else {
                Ty::ArrayLiteral(values)
            }
        }
        _ => ty.clone(),
    }
}

fn widen(ty: &Ty, path: &[Step]) -> Ty {
    if let Ty::Instance(id, fields) = ty {
        return Ty::Instance(id.clone(), Box::new(widen(fields, path)));
    }
    let Some((step, rest)) = path.split_first() else {
        return match ty {
            Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_) => Ty::Num,
            Ty::Union(ts) => Ty::union(ts.iter().map(|t| widen(t, path))),
            _ => ty.clone(),
        };
    };
    match (ty, step) {
        (_, Step::DynamicValue | Step::DynamicDescendants) => Ty::Dynamic,
        (Ty::NonEmptyArray(t), Step::Resize | Step::Descendants) => Ty::Array(Box::new(widen(t, rest))),
        (Ty::NonEmptyArray(t), Step::Index) => Ty::NonEmptyArray(Box::new(widen(t, rest))),
        (Ty::Union(ts), _) => Ty::union(ts.iter().map(|t| widen(t, path))),
        (_, Step::Descendants) => match ty {
            Ty::Obj(fields) => Ty::Obj(
                fields
                    .iter()
                    .map(|(k, (t, opt))| (k.clone(), (widen(t, path), *opt)))
                    .collect(),
            ),
            Ty::Array(t) => Ty::Array(Box::new(widen(t, path))),
            Ty::Map(t) => Ty::Map(Box::new(widen(t, path))),
            Ty::Tuple(ts) => Ty::Tuple(ts.iter().map(|t| widen(t, path)).collect()),
            Ty::ArrayLiteral(ts) => Ty::ArrayLiteral(ts.iter().map(|t| widen(t, path)).collect()),
            _ => widen(ty, &[]),
        },
        (Ty::Obj(fields), Step::Field(field)) => {
            let mut fields = fields.clone();
            if let Some((ty, _)) = fields.get_mut(field) {
                *ty = widen(ty, rest);
            }
            Ty::Obj(fields)
        }
        (Ty::Obj(fields), Step::Index) => Ty::Obj(
            fields
                .iter()
                .map(|(k, (t, opt))| (k.clone(), (widen(t, rest), *opt)))
                .collect(),
        ),
        (Ty::Array(t), Step::Index) => Ty::Array(Box::new(widen(t, rest))),
        (Ty::Tuple(ts), Step::Index) => Ty::Tuple(ts.iter().map(|t| widen(t, rest)).collect()),
        (Ty::ArrayLiteral(ts), Step::Index) => Ty::ArrayLiteral(ts.iter().map(|t| widen(t, rest)).collect()),
        (Ty::Map(t), _) => Ty::Map(Box::new(widen(t, rest))),
        _ => ty.clone(),
    }
}

pub(super) fn class_static_read(ty: &Ty) -> Ty {
    widen(ty, &[Step::Descendants])
}

struct Call {
    receiver: Option<Place>,
    callee: Place,
    args: Vec<Option<Place>>,
    spreads: HashSet<usize>,
    result: Place,
    owner: Span,
    span: Span,
    prior: HashSet<Span>,
}

impl Call {
    fn arguments(&self, method: bool) -> Vec<(Option<Place>, bool)> {
        let args = self
            .args
            .iter()
            .enumerate()
            .map(|(index, value)| (value.clone(), self.spreads.contains(&index)));
        if method {
            std::iter::once((self.receiver.clone(), false)).chain(args).collect()
        } else {
            args.collect()
        }
    }
}

#[derive(Default)]
struct Graph {
    javascript_modules: HashSet<usize>,
    javascript_functions: HashSet<Span>,
    scalar_functions: HashSet<Span>,
    classes: HashSet<usize>,
    methods: HashSet<Span>,
    captured: HashSet<Span>,
    roots: HashSet<Span>,
    reachable: HashSet<Span>,
    incoming: HashSet<Place>,
    incoming_writes: HashSet<Place>,
    history: HashMap<Span, HashSet<Span>>,
    created_prior: HashMap<Span, HashSet<Span>>,
    created_by: HashMap<Span, Span>,
    expression_events: HashMap<Span, HashSet<Span>>,
    loop_events: HashMap<Span, HashSet<Span>>,
    functions: HashMap<Span, Vec<Place>>,
    argument_values: HashMap<Span, Place>,
    argument_reads: HashSet<Span>,
    rest_parameters: HashMap<Span, usize>,
    values: HashSet<(Place, Span)>,
    slice_methods: HashSet<Place>,
    borrowed_slices: Vec<(Place, Place, Place, Span)>,
    copies: HashSet<(Place, Place, Span)>,
    captured_data: HashMap<(u8, usize), Place>,
    external: HashSet<Span>,
    escaped: HashSet<Place>,
    call_events: HashMap<Span, Span>,
    event_owners: HashMap<Span, Span>,
    calls: Vec<Call>,
    numeric: HashSet<Place>,
    writes: HashSet<Place>,
    max_depth: usize,
    bindings: HashMap<Span, Span>,
    parameter_views: HashMap<Span, Place>,
    function_numeric: HashMap<Span, HashSet<Place>>,
    function_writes: HashMap<Span, HashSet<Place>>,
    events: HashMap<Span, HashSet<Place>>,
    write_events: HashMap<Span, HashSet<Place>>,
}

type Names = Vec<HashMap<String, Place>>;

impl Graph {
    fn function(&mut self, f: &Rc<Func>, outer: &[HashMap<String, Place>], env: &Rc<Scope>) -> Place {
        let owner = f.span;
        if self.javascript_modules.contains(&f.module) {
            self.javascript_functions.insert(owner);
        }
        if f.ret.as_ref().is_some_and(scalar_return_annotation) {
            self.scalar_functions.insert(owner);
        }
        let value = Place::new(f.span);
        self.values.insert((value.clone(), f.span));
        if self.functions.contains_key(&f.span) {
            return value;
        }
        self.functions.insert(f.span, Vec::new());
        if let Some(index) = f.params.iter().position(|parameter| parameter.rest) {
            self.rest_parameters.insert(f.span, index);
        }
        let mut names = outer.to_vec();
        names.push(HashMap::new());
        if let Some(name) = &f.name {
            names.last_mut().unwrap().insert(name.clone(), value.clone());
        }
        let params: Vec<Place> = f
            .params
            .iter()
            .map(|p| {
                self.bind(&p.pat, &mut names, f.span);
                let param = Place::new(p.pat.span());
                self.bindings.insert(param.binding, owner);
                self.external.insert(param.binding);
                self.bind_value(&p.pat, Some(param.clone()), owner);
                self.parameter_pattern(&p.pat, &param);
                param
            })
            .collect();
        if !f.is_arrow && !names.last().expect("function scope").contains_key("arguments") {
            let binding = javascript::arguments_binding(f);
            let arguments = Place::new(binding);
            names
                .last_mut()
                .expect("function scope")
                .insert("arguments".into(), arguments.clone());
            self.bindings.insert(binding, owner);
            self.argument_values.insert(owner, arguments.clone());
        }
        self.functions.insert(f.span, params);
        if let FnBody::Block(body) = &f.body {
            let mut vars = Vec::new();
            javascript::collect_var_declarations(body, &mut vars);
            for javascript::VarBinding { name, span, .. } in vars {
                if !names.last().expect("function scope").contains_key(name) {
                    names
                        .last_mut()
                        .expect("function scope")
                        .insert(name.to_string(), Place::new(span));
                    self.bindings.insert(span, owner);
                }
            }
        }
        for p in &f.params {
            self.defaults(&p.pat, &mut names, env, f.span);
            if let Some(e) = &p.default {
                let default = self.expr(e, &mut names, env, f.span);
                self.bind_value(&p.pat, default, owner);
            }
        }
        match &f.body {
            FnBody::Expr(e) => {
                let result = self.expr(e, &mut names, env, f.span);
                if !self.scalar_functions.contains(&owner) {
                    self.copy(result, Some(value.at(Step::Result)), owner);
                }
            }
            FnBody::Block(body) => self.block(body, &mut names, env, f.span),
        }
        value
    }

    fn bind(&mut self, pat: &Pat, names: &mut Names, owner: Span) {
        let mut bindings = Vec::new();
        fn collect(p: &Pat, out: &mut Vec<(String, Span)>) {
            match p {
                Pat::Id(name, span) => out.push((name.clone(), *span)),
                Pat::Default(p, _) => collect(p, out),
                Pat::Obj { props, rest, .. } => {
                    for (_, p) in props {
                        collect(p, out);
                    }
                    if let Some(p) = rest {
                        out.push(p.clone());
                    }
                }
                Pat::Arr { elems, rest, .. } => {
                    for p in elems.iter().flatten() {
                        collect(p, out);
                    }
                    if let Some(p) = rest {
                        out.push(p.clone());
                    }
                }
            }
        }
        collect(pat, &mut bindings);
        for (name, span) in bindings {
            names.last_mut().unwrap().insert(name, Place::new(span));
            self.bindings.insert(span, owner);
        }
    }

    fn defaults(&mut self, pat: &Pat, names: &mut Names, env: &Rc<Scope>, owner: Span) {
        match pat {
            Pat::Default(p, e) => {
                let value = self.expr(e, names, env, owner);
                self.bind_value(p, value, owner);
                self.defaults(p, names, env, owner);
            }
            Pat::Obj { props, .. } => {
                for (_, p) in props {
                    self.defaults(p, names, env, owner);
                }
            }
            Pat::Arr { elems, .. } => {
                for p in elems.iter().flatten() {
                    self.defaults(p, names, env, owner);
                }
            }
            Pat::Id(..) => {}
        }
    }

    fn bind_value(&mut self, pat: &Pat, src: Option<Place>, owner: Span) {
        match pat {
            Pat::Id(_, span) => self.copy(src, Some(Place::new(*span)), owner),
            Pat::Default(p, _) => self.bind_value(p, src, owner),
            Pat::Obj { props, rest, .. } => {
                for (key, p) in props {
                    self.bind_value(p, src.as_ref().map(|p| p.at(Step::Field(key.clone()))), owner);
                }
                if let Some((_, span)) = rest {
                    self.copy(src, Some(Place::new(*span)), owner);
                }
            }
            Pat::Arr { elems, rest, .. } => {
                for p in elems.iter().flatten() {
                    self.bind_value(p, src.as_ref().map(|p| p.at(Step::Index)), owner);
                }
                if let Some((_, span)) = rest {
                    self.copy(src, Some(Place::new(*span)), owner);
                }
            }
        }
    }

    fn parameter_pattern(&mut self, pat: &Pat, src: &Place) {
        match pat {
            Pat::Id(_, span) => {
                if *span != src.binding {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
            Pat::Default(p, _) => self.parameter_pattern(p, src),
            Pat::Obj { props, rest, .. } => {
                for (key, p) in props {
                    self.parameter_pattern(p, &src.at(Step::Field(key.clone())));
                }
                if let Some((_, span)) = rest {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
            Pat::Arr { elems, rest, .. } => {
                for p in elems.iter().flatten() {
                    self.parameter_pattern(p, &src.at(Step::Index));
                }
                if let Some((_, span)) = rest {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
        }
    }

    fn copy(&mut self, src: Option<Place>, dest: Option<Place>, owner: Span) {
        if let (Some(src), Some(dest)) = (src, dest) {
            if dest.path.contains(&Step::Result) || !dest.path.is_empty() && self.external.contains(&dest.binding) {
                self.escaped.insert(src.clone());
            }
            self.copies.insert((src, dest, owner));
        }
    }

    fn data_place(&mut self, value: &Value) -> Option<Place> {
        let identity = match value {
            Value::Array(a) => (0, Rc::as_ptr(a) as usize),
            Value::Object(o) => (1, Rc::as_ptr(o) as usize),
            _ => return None,
        };
        if let Some(place) = self.captured_data.get(&identity) {
            return Some(place.clone());
        }
        let next = self.captured_data.len() as u32 + 1;
        let place = Place::new(Span {
            file: crate::diag::NO_FILE,
            start: next,
            end: next,
        });
        self.captured_data.insert(identity, place.clone());
        self.external.insert(place.binding);
        self.reachable.insert(Span::NONE);
        let children: Vec<_> = match value {
            Value::Array(a) => a.borrow().iter().map(|v| (Step::Index, v.clone())).collect(),
            Value::Object(o) => o
                .borrow()
                .iter()
                .map(|(k, v)| (Step::Field(k.clone()), v.clone()))
                .collect(),
            _ => unreachable!(),
        };
        for (step, value) in children {
            if let Some(child) = self.data_place(&value) {
                self.copies.insert((child, place.at(step), Span::NONE));
            }
        }
        Some(place)
    }

    fn ident(&mut self, name: &str, names: &Names, env: &Rc<Scope>) -> Option<Place> {
        if let Some(p) = names.iter().rev().find_map(|s| s.get(name)) {
            if name == "arguments" {
                self.argument_reads.insert(p.binding);
            }
            return Some(p.clone());
        }
        match env.lookup(name)?.value {
            Value::Class(c) => Some(self.class_place(&c)),
            Value::Closure(c) => Some(self.function(&c.func, &[], &c.env)),
            value @ (Value::Array(_) | Value::Object(_)) => self.data_place(&value),
            _ => None,
        }
    }

    fn class_place(&mut self, class: &Rc<ClassValue>) -> Place {
        let place = Place::new(class.class.span);
        if !self.classes.insert(Rc::as_ptr(class) as usize) {
            return place;
        }
        self.external.insert(place.binding);
        self.reachable.insert(Span::NONE);
        for (name, value) in &class.statics {
            let data = self.data_place(value);
            self.copy(data, Some(place.at(Step::Field(name.clone()))), Span::NONE);
        }
        if let Some(base) = &class.base {
            let parent = self.class_place(base);
            self.copy(Some(parent), Some(place.clone()), Span::NONE);
        }
        let env = Scope::new(Some(class.env.clone()), class.env.module);
        env.vars.borrow_mut().insert(
            ":class".into(),
            Binding {
                value: Value::Class(class.clone()),
                mutable: false,
                module_level: false,
                ann: None,
            },
        );
        for (name, is_static, f) in &class.class.methods {
            self.methods.insert(f.span);
            let method_env = Scope::new(Some(env.clone()), env.module);
            method_env.vars.borrow_mut().insert(
                ":static".into(),
                Binding {
                    value: Value::Bool(*is_static),
                    mutable: false,
                    module_level: false,
                    ann: None,
                },
            );
            let function = self.function(f, &[], &method_env);
            let receiver = if *is_static {
                place.clone()
            } else {
                place.at(Step::Field(":prototype".into()))
            };
            self.copy(Some(function), Some(receiver.at(Step::Field(name.clone()))), Span::NONE);
        }
        if let Some(f) = &class.class.constructor {
            self.methods.insert(f.span);
            let function = self.function(f, &[], &env);
            self.copy(
                Some(function),
                Some(place.at(Step::Field(":constructor".into()))),
                Span::NONE,
            );
        }
        for field in class.class.fields.iter().filter(|f| !f.is_static) {
            if let Some(f) = field.initializer(class.env.module) {
                self.methods.insert(f.span);
                let function = self.function(&f, &[], &env);
                self.copy(
                    Some(function),
                    Some(
                        place
                            .at(Step::Field(":fields".into()))
                            .at(Step::Field(field.name.clone())),
                    ),
                    Span::NONE,
                );
            }
        }
        place
    }

    fn expr(&mut self, e: &Expr, names: &mut Names, env: &Rc<Scope>, owner: Span) -> Option<Place> {
        let before = self.history.get(&owner).cloned().unwrap_or_default();
        let result = self.expr_inner(e, names, env, owner);
        let events = self
            .history
            .get(&owner)
            .into_iter()
            .flat_map(|events| events.difference(&before).copied())
            .collect();
        self.expression_events.insert(e.span, events);
        result
    }

    fn expr_inner(&mut self, e: &Expr, names: &mut Names, env: &Rc<Scope>, owner: Span) -> Option<Place> {
        let value = Place::new(e.span);
        self.bindings.entry(value.binding).or_insert(owner);
        if matches!(
            e.kind,
            ExprKind::Update { .. } | ExprKind::Assign { .. } | ExprKind::Call { .. } | ExprKind::New { .. }
        ) {
            self.event_owners.insert(e.span, owner);
        }
        match &e.kind {
            ExprKind::Sequence(items) => {
                let mut last = None;
                for item in items {
                    last = self.expr(item, names, env, owner);
                }
                return last;
            }
            ExprKind::Ident(name) => {
                let place = self.ident(name, names, env)?;
                if self
                    .bindings
                    .get(&place.binding)
                    .is_some_and(|binding_owner| *binding_owner != owner)
                {
                    self.captured.insert(place.binding);
                    self.escaped.insert(place.clone());
                }
                return Some(place);
            }
            ExprKind::Func(f) => {
                self.created_by.insert(f.span, owner);
                self.created_prior
                    .entry(f.span)
                    .or_insert_with(|| self.history.get(&owner).cloned().unwrap_or_default());
                return Some(self.function(f, names, env));
            }
            ExprKind::Member { obj, prop, .. } => {
                if matches!(&obj.kind, ExprKind::Ident(n) if n == "super")
                    && let Some(Value::Class(class)) = env.lookup(":class").map(|b| b.value)
                    && let Some(base) = &class.base
                {
                    let base = self.class_place(base);
                    let is_static = matches!(env.lookup(":static").map(|b| b.value), Some(Value::Bool(true)));
                    return Some(
                        if is_static {
                            base
                        } else {
                            base.at(Step::Field(":prototype".into()))
                        }
                        .at(Step::Field(prop.clone())),
                    );
                }
                let member = self
                    .expr(obj, names, env, owner)
                    .map(|p| p.at(Step::Field(prop.clone())));
                if prop == "slice"
                    && let Some(member) = &member
                {
                    self.slice_methods.insert(member.clone());
                }
                return member;
            }
            ExprKind::Index { obj, index, .. } => {
                let obj = self.expr(obj, names, env, owner);
                self.expr(index, names, env, owner);
                let step = match &index.kind {
                    ExprKind::Str(key) => Step::Field(key.clone()),
                    _ => Step::Index,
                };
                let member = obj.map(|p| p.at(step));
                if matches!(&index.kind, ExprKind::Str(key) if key == "slice")
                    && let Some(member) = &member
                {
                    self.slice_methods.insert(member.clone());
                }
                return member;
            }
            ExprKind::NonNull(e) | ExprKind::As(e, _) | ExprKind::Chain(e) | ExprKind::Await(e) => {
                return self.expr(e, names, env, owner);
            }
            ExprKind::Update { target, .. } => {
                if let Some(p) = self.expr(target, names, env, owner) {
                    self.write_events.entry(e.span).or_default().insert(p.clone());
                    self.numeric.insert(p.clone());
                    self.events.entry(e.span).or_default().insert(p.clone());
                    self.function_numeric.entry(owner).or_default().insert(p.clone());
                    self.function_writes.entry(owner).or_default().insert(p);
                }
                self.history.entry(owner).or_default().insert(e.span);
            }
            ExprKind::Assign { target, value, op } => {
                let target = self.expr(target, names, env, owner);
                let src = self.expr(value, names, env, owner);
                if let Some(p) = &target {
                    self.write_events.entry(e.span).or_default().insert(p.clone());
                    self.function_writes.entry(owner).or_default().insert(p.clone());
                    if self.javascript_functions.contains(&owner) && !p.path.is_empty() {
                        let dynamic = p.at(Step::DynamicValue);
                        self.write_events.entry(e.span).or_default().insert(dynamic.clone());
                        self.function_writes.entry(owner).or_default().insert(dynamic.clone());
                        self.events.entry(e.span).or_default().insert(dynamic.clone());
                        self.function_numeric.entry(owner).or_default().insert(dynamic);
                    }
                    if matches!(op, Some(AssignOp::Bin(_))) {
                        self.numeric.insert(p.clone());
                        self.events.entry(e.span).or_default().insert(p.clone());
                        self.function_numeric.entry(owner).or_default().insert(p.clone());
                    }
                }
                self.copy(src, target.clone(), owner);
                self.history.entry(owner).or_default().insert(e.span);
                return target;
            }
            ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args } => {
                let callee_value = self.expr(callee, names, env, owner);
                let argv: Vec<_> = args
                    .iter()
                    .map(|arg| match arg {
                        ArrEl::Item(expr) => self.expr(expr, names, env, owner),
                        ArrEl::Spread(expr) => self.expr(expr, names, env, owner).map(|value| value.at(Step::Index)),
                    })
                    .collect();
                let spreads: HashSet<_> = args
                    .iter()
                    .enumerate()
                    .filter_map(|(index, arg)| matches!(arg, ArrEl::Spread(_)).then_some(index))
                    .collect();
                if matches!(&callee.kind, ExprKind::Ident(n) if n == "super")
                    && let Some(Value::Class(class)) = env.lookup(":class").map(|b| b.value)
                    && let Some(base) = &class.base
                {
                    let root = self.class_place(base);
                    let receiver = self.ident("this", names, env);
                    self.calls.push(Call {
                        receiver,
                        callee: root.at(Step::Field(":constructor".into())),
                        args: argv,
                        spreads,
                        result: value.clone(),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                    self.history.entry(owner).or_default().insert(e.span);
                    return Some(value);
                }
                if matches!(e.kind, ExprKind::New { .. })
                    && let ExprKind::Ident(name) = &callee.kind
                    && let Some(Value::Class(class)) = env.lookup(name).map(|b| b.value)
                {
                    let class_place = self.class_place(&class);
                    self.copy(
                        Some(class_place.at(Step::Field(":prototype".into()))),
                        Some(value.clone()),
                        owner,
                    );
                    self.calls.push(Call {
                        receiver: Some(value.clone()),
                        callee: class_place.at(Step::Field(":constructor".into())),
                        args: argv,
                        spreads,
                        result: Place::new(e.span).at(Step::Result),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                    let mut ancestor = Some(class);
                    while let Some(class) = ancestor {
                        let root = self.class_place(&class);
                        for field in class.class.fields.iter().filter(|f| !f.is_static && f.init.is_some()) {
                            self.calls.push(Call {
                                receiver: Some(value.clone()),
                                callee: root
                                    .at(Step::Field(":fields".into()))
                                    .at(Step::Field(field.name.clone())),
                                args: vec![],
                                spreads: HashSet::new(),
                                result: value.at(Step::Field(field.name.clone())),
                                owner,
                                span: e.span,
                                prior: self.history.get(&owner).cloned().unwrap_or_default(),
                            });
                        }
                        ancestor = class.base.clone();
                    }
                    self.history.entry(owner).or_default().insert(e.span);
                    return Some(value);
                }
                // Known scalar/container builtins do not retain their arguments or
                // execute user code. Callback methods still form a call boundary.
                let builtin = matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(),
                    "indexOf" | "lastIndexOf" | "includes" | "join" | "slice" | "at" | "concat" | "flat"
                    | "push" | "pop" | "shift" | "unshift" | "splice" | "reverse" | "toReversed" | "fill"
                    | "with" | "keys" | "values" | "entries" | "fromEntries" | "hasOwn" | "isArray"
                    | "trim" | "trimStart" | "trimEnd" | "toLowerCase" | "toUpperCase" | "startsWith" | "endsWith"
                    | "substring" | "substr" | "charAt" | "charCodeAt" | "codePointAt" | "split" | "repeat" | "padStart" | "padEnd"
                    | "abs" | "ceil" | "floor" | "round" | "trunc" | "min" | "max" | "pow" | "sqrt" | "sign"
                    | "isFinite" | "isInteger" | "isNaN" | "parseInt" | "parseFloat" | "parse" | "stringify"));
                let known_callback = match &callee.kind {
                    ExprKind::Member { prop, .. } => {
                        let index = match prop.as_str() {
                            "map" | "flatMap" | "forEach" | "filter" | "some" | "every" | "find" | "findLast"
                            | "findIndex" | "findLastIndex" | "reduce" | "sort" | "toSorted" => Some(0),
                            "replace" | "replaceAll" | "from" => Some(1),
                            _ => None,
                        };
                        index
                            .and_then(|index| argv.get(index))
                            .and_then(Option::as_ref)
                            .is_some_and(|callback| self.values.iter().any(|(value, _)| callable_at(value, callback)))
                    }
                    _ => false,
                };
                let builtin = builtin
                    || known_callback
                    || matches!(&callee.kind, ExprKind::Ident(name) if name == "RegExp" && self.ident(name, names, env).is_none())
                    || matches!(&callee.kind, ExprKind::Member { prop, .. } if prop == "match")
                    || matches!(&callee.kind, ExprKind::Member { obj, prop, .. } if prop == "from" && argv.len() == 1 && matches!(&obj.kind, ExprKind::Ident(n) if n == "Array"))
                    || matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(), "replace" | "replaceAll") && matches!(args.get(1), Some(ArrEl::Item(Expr { kind: ExprKind::Str(_), .. }))));
                if !builtin {
                    self.call_events.insert(e.span, owner);
                    self.escaped.extend(argv.iter().flatten().cloned());
                }
                let fresh_result = matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(), "map" | "filter" | "reduce" | "concat" | "slice" | "flat" | "flatMap" | "toReversed" | "toSorted" | "split" | "match" | "keys" | "values" | "entries" | "from" | "fromEntries" | "replace" | "replaceAll" | "trim" | "trimStart" | "trimEnd" | "toUpperCase" | "toLowerCase"));
                if !fresh_result {
                    self.external.insert(value.binding);
                }
                let borrowed_call = match &callee.kind {
                    ExprKind::Member { prop, .. } => matches!(prop.as_str(), "call" | "apply"),
                    ExprKind::Index { .. } => true,
                    _ => false,
                };
                if borrowed_call
                    && let Some(method) = callee_value.as_ref().and_then(|callee| {
                        let mut method = callee.clone();
                        method.path.pop()?;
                        Some(method)
                    })
                    && let Some(Some(receiver)) = argv.first()
                {
                    self.borrowed_slices
                        .push((method, receiver.clone(), value.clone(), owner));
                }
                if let ExprKind::Member { prop, .. } = &callee.kind {
                    // The receiver has already been visited above. Resolve its place without replaying effects.
                    let recv = callee_value.as_ref().and_then(|p| {
                        let mut p = p.clone();
                        p.path.pop()?;
                        Some(p)
                    });
                    let mutation = crate::builtins::array_mutation(prop);
                    if mutation.is_some()
                        && let Some(p) = &recv
                    {
                        let target = p.at(Step::Index);
                        if self.javascript_functions.contains(&owner) {
                            let dynamic = target.at(Step::DynamicValue);
                            self.write_events.entry(e.span).or_default().insert(dynamic.clone());
                            self.function_writes.entry(owner).or_default().insert(dynamic.clone());
                            self.events.entry(e.span).or_default().insert(dynamic.clone());
                            self.function_numeric.entry(owner).or_default().insert(dynamic);
                        }
                        self.write_events.entry(e.span).or_default().insert(target.clone());
                        self.function_writes.entry(owner).or_default().insert(target);
                        let resized = p.at(Step::Resize);
                        self.write_events.entry(e.span).or_default().insert(resized.clone());
                        self.function_writes.entry(owner).or_default().insert(resized);
                    }
                    if let Some(mutation) = mutation
                        && let Some(recv) = &recv
                    {
                        let inserted = mutation.inserted(&argv);
                        for src in inserted {
                            self.copy(src.clone(), Some(recv.at(Step::Index)), owner);
                        }
                    }
                    let element = recv.as_ref().map(|p| p.at(Step::Index));
                    let callback = match prop.as_str() {
                        "map" | "flatMap" => {
                            Some((0, vec![element.clone(), None, recv.clone()], value.at(Step::Index)))
                        }
                        "forEach" | "filter" | "some" | "every" | "find" | "findLast" | "findIndex"
                        | "findLastIndex" => {
                            Some((0, vec![element.clone(), None, recv.clone()], value.at(Step::Result)))
                        }
                        "reduce" => {
                            let acc = value.clone();
                            let initial = argv.get(1).cloned().flatten().or(element.clone());
                            self.copy(initial.clone(), Some(acc.clone()), owner);
                            Some((0, vec![initial, element.clone(), None, recv.clone()], acc))
                        }
                        "sort" | "toSorted" => {
                            Some((0, vec![element.clone(), element.clone()], value.at(Step::Result)))
                        }
                        "replace" | "replaceAll" => Some((1, vec![None; 5], value.at(Step::Result))),
                        "from"
                            if matches!(&callee.kind, ExprKind::Member { obj, .. }
                            if matches!(&obj.kind, ExprKind::Ident(n) if n == "Array")
                                && self.ident("Array", names, env).is_none()) =>
                        {
                            Some((
                                1,
                                vec![argv.first().cloned().flatten().map(|p| p.at(Step::Index)), None],
                                value.at(Step::Index),
                            ))
                        }
                        _ => None,
                    };
                    if let Some((index, args, result)) = callback
                        && let Some(Some(callback)) = argv.get(index)
                    {
                        if prop == "reduce" {
                            self.calls.push(Call {
                                receiver: None,
                                callee: callback.clone(),
                                args: vec![Some(result.clone()), element.clone(), None, recv.clone()],
                                spreads: HashSet::new(),
                                result: result.clone(),
                                owner,
                                span: e.span,
                                prior: self
                                    .history
                                    .get(&owner)
                                    .into_iter()
                                    .flatten()
                                    .copied()
                                    .chain(std::iter::once(e.span))
                                    .collect(),
                            });
                        }
                        self.calls.push(Call {
                            receiver: None,
                            callee: callback.clone(),
                            args,
                            spreads: HashSet::new(),
                            result: result.clone(),
                            owner,
                            span: e.span,
                            prior: self
                                .history
                                .get(&owner)
                                .into_iter()
                                .flatten()
                                .copied()
                                .chain(std::iter::once(e.span))
                                .collect(),
                        });
                    }
                    match prop.as_str() {
                        "flatMap" => self.copy(
                            Some(value.at(Step::Index).at(Step::Index)),
                            Some(value.at(Step::Index)),
                            owner,
                        ),
                        "filter" | "sort" | "toSorted" | "reverse" | "toReversed" | "slice" | "splice" | "fill" => {
                            self.copy(element, Some(value.at(Step::Index)), owner)
                        }
                        "find" | "findLast" | "at" | "pop" | "shift" => self.copy(element, Some(value.clone()), owner),
                        "concat" => {
                            self.copy(element, Some(value.at(Step::Index)), owner);
                            for arg in &argv {
                                self.copy(arg.clone(), Some(value.at(Step::Index)), owner);
                                self.copy(
                                    arg.as_ref().map(|p| p.at(Step::Index)),
                                    Some(value.at(Step::Index)),
                                    owner,
                                );
                            }
                        }
                        "flat" => {
                            self.copy(element, Some(value.at(Step::Index)), owner);
                            let depth = match args.first() {
                                None => Some(1),
                                Some(ArrEl::Item(Expr {
                                    kind: ExprKind::Num(n), ..
                                })) => Some(n.max(0.0) as usize),
                                _ => None,
                            };
                            if let Some(mut src) = recv.clone() {
                                if let Some(depth @ 0..=1) = depth {
                                    for _ in 0..=depth {
                                        src = src.at(Step::Index);
                                    }
                                } else {
                                    src = src.at(Step::Descendants);
                                }
                                self.copy(Some(src), Some(value.at(Step::Index)), owner);
                            }
                        }
                        _ => {}
                    }
                    if let ExprKind::Member { obj, .. } = &callee.kind
                        && let ExprKind::Ident(global) = &obj.kind
                        && self.ident(global, names, env).is_none()
                    {
                        let source = argv.first().cloned().flatten();
                        match (global.as_str(), prop.as_str()) {
                            ("Array", "from") if argv.len() == 1 => {
                                self.copy(source.map(|p| p.at(Step::Index)), Some(value.at(Step::Index)), owner)
                            }
                            ("Object", "values") => {
                                self.copy(source.map(|p| p.at(Step::Index)), Some(value.at(Step::Index)), owner)
                            }
                            ("Object", "entries") => self.copy(
                                source.map(|p| p.at(Step::Index)),
                                Some(value.at(Step::Index).at(Step::Index)),
                                owner,
                            ),
                            ("Object", "fromEntries") => self.copy(
                                source.map(|p| p.at(Step::Index).at(Step::Index)),
                                Some(value.at(Step::Index)),
                                owner,
                            ),
                            _ => {}
                        }
                    }
                }
                if let Some(callee) = callee_value {
                    let receiver = if matches!(&e.kind, ExprKind::Call { callee, .. } if matches!(&callee.kind, ExprKind::Member { obj, .. } if matches!(&obj.kind, ExprKind::Ident(n) if n == "super")))
                    {
                        self.ident("this", names, env)
                    } else if matches!(&e.kind, ExprKind::Call { callee, .. } if matches!(callee.kind, ExprKind::Member { .. }))
                    {
                        let mut p = callee.clone();
                        p.path.pop().map(|_| p)
                    } else {
                        None
                    };
                    self.calls.push(Call {
                        receiver,
                        callee,
                        args: argv,
                        spreads,
                        result: value.clone(),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                }
                self.history.entry(owner).or_default().insert(e.span);
            }
            ExprKind::Array(items) => {
                for item in items {
                    let (ArrEl::Item(e) | ArrEl::Spread(e)) = item;
                    let src = self.expr(e, names, env, owner);
                    let src = if matches!(item, ArrEl::Spread(_)) {
                        src.map(|p| p.at(Step::Index))
                    } else {
                        src
                    };
                    self.copy(src, Some(value.at(Step::Index)), owner);
                }
            }
            ExprKind::Object(props) => {
                for prop in props {
                    match prop {
                        Prop::KV(key, e) => {
                            let src = self.expr(e, names, env, owner);
                            let step = match key {
                                PropKey::Static(k) => Step::Field(k.clone()),
                                PropKey::Computed(e) => {
                                    self.expr(e, names, env, owner);
                                    Step::Index
                                }
                            };
                            self.copy(src, Some(value.at(step)), owner);
                        }
                        Prop::Spread(e) => {
                            let src = self.expr(e, names, env, owner);
                            self.copy(src, Some(value.clone()), owner);
                        }
                    }
                }
            }
            ExprKind::Cond(test, a, b) => {
                self.expr(test, names, env, owner);
                for e in [a, b] {
                    let src = self.expr(e, names, env, owner);
                    self.copy(src, Some(value.clone()), owner);
                }
            }
            ExprKind::Logical(_, a, b) => {
                for e in [a, b] {
                    let src = self.expr(e, names, env, owner);
                    self.copy(src, Some(value.clone()), owner);
                }
            }
            ExprKind::Binary(_, a, b) | ExprKind::Instanceof(a, b) => {
                self.expr(a, names, env, owner);
                self.expr(b, names, env, owner);
                return None;
            }
            ExprKind::Unary(_, e) | ExprKind::IsNullish(e, _) => {
                self.expr(e, names, env, owner);
                return None;
            }
            ExprKind::Template(_, exprs) => {
                for e in exprs {
                    self.expr(e, names, env, owner);
                }
                return None;
            }
            ExprKind::Num(_) | ExprKind::Str(_) | ExprKind::Bool(_) | ExprKind::Null | ExprKind::Undefined => {
                return None;
            }
            ExprKind::Class(_) | ExprKind::Regex { .. } => {}
        }
        Some(value)
    }

    fn block(&mut self, body: &[Stmt], names: &mut Names, env: &Rc<Scope>, owner: Span) {
        names.push(HashMap::new());
        for s in body {
            match s {
                Stmt::Decl { kind, pat, .. } if *kind != DeclKind::Var => self.bind(pat, names, owner),
                Stmt::Func(f) => {
                    names
                        .last_mut()
                        .unwrap()
                        .insert(f.name.clone().unwrap(), Place::new(f.span));
                }
                _ => {}
            }
        }
        for s in body {
            self.stmt(s, names, env, owner);
        }
        names.pop();
    }

    fn stmt(&mut self, s: &Stmt, names: &mut Names, env: &Rc<Scope>, owner: Span) {
        match s {
            Stmt::Decl {
                kind, pat, init, span, ..
            } => {
                let src = init.as_ref().and_then(|e| self.expr(e, names, env, owner));
                if *kind == DeclKind::Var
                    && let Pat::Id(name, _) = pat
                {
                    let target = self.ident(name, names, env);
                    if init.is_some()
                        && let Some(target) = &target
                    {
                        self.write_events.entry(*span).or_default().insert(target.clone());
                        self.function_writes.entry(owner).or_default().insert(target.clone());
                        self.history.entry(owner).or_default().insert(*span);
                    }
                    self.copy(src, target, owner);
                } else {
                    self.bind_value(pat, src, owner);
                }
                self.defaults(pat, names, env, owner);
            }
            Stmt::Func(f) => {
                self.created_by.insert(f.span, owner);
                self.created_prior
                    .entry(f.span)
                    .or_insert_with(|| self.history.get(&owner).cloned().unwrap_or_default());
                self.function(f, names, env);
            }
            Stmt::Expr(e) | Stmt::Throw(e) | Stmt::ExportDefault(e) => {
                self.expr(e, names, env, owner);
            }
            Stmt::Return(Some(e), _) => {
                let src = self.expr(e, names, env, owner);
                if !self.scalar_functions.contains(&owner) {
                    self.copy(src, Some(Place::new(owner).at(Step::Result)), owner);
                }
            }
            Stmt::If(e, a, b) => {
                self.expr(e, names, env, owner);
                self.block(a, names, env, owner);
                if let Some(b) = b {
                    self.block(b, names, env, owner);
                }
            }
            Stmt::Block(b) => self.block(b, names, env, owner),
            Stmt::While(e, b) => {
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                self.expr(e, names, env, owner);
                self.block(b, names, env, owner);
                self.loop_history(owner, first_call, &before, e.span);
            }
            Stmt::For {
                init,
                test,
                update,
                body,
                span,
            } => {
                names.push(HashMap::new());
                if let Some(init) = init {
                    if let Stmt::Decl { kind, pat, .. } = init.as_ref()
                        && *kind != DeclKind::Var
                    {
                        self.bind(pat, names, owner);
                    }
                    self.stmt(init, names, env, owner);
                }
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                for e in [test, update].into_iter().flatten() {
                    self.expr(e, names, env, owner);
                }
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::ForOf {
                kind,
                pat,
                iter,
                body,
                span,
                ..
            } => {
                let src = self.expr(iter, names, env, owner).map(|p| p.at(Step::Index));
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                names.push(HashMap::new());
                if *kind != DeclKind::Var {
                    self.bind(pat, names, owner);
                }
                if *kind == DeclKind::Var
                    && let Pat::Id(name, _) = pat
                {
                    let target = self.ident(name, names, env);
                    self.copy(src, target, owner);
                } else {
                    self.bind_value(pat, src, owner);
                }
                self.defaults(pat, names, env, owner);
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::ForIn {
                kind,
                target,
                iter,
                body,
                span,
            } => {
                self.expr(iter, names, env, owner);
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                names.push(HashMap::new());
                if matches!(kind, Some(DeclKind::Let | DeclKind::Const))
                    && let ExprKind::Ident(name) = &target.kind
                {
                    names
                        .last_mut()
                        .expect("scope")
                        .insert(name.clone(), Place::new(target.span));
                    self.bindings.insert(target.span, owner);
                } else if let Some(target) = self.expr(target, names, env, owner) {
                    self.write_events.entry(*span).or_default().insert(target.clone());
                    self.function_writes.entry(owner).or_default().insert(target);
                    self.history.entry(owner).or_default().insert(*span);
                }
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                self.block(block, names, env, owner);
                if let Some(body) = handler {
                    names.push(HashMap::new());
                    if let Some((name, span)) = param {
                        names.last_mut().unwrap().insert(name.clone(), Place::new(*span));
                    }
                    self.block(body, names, env, owner);
                    names.pop();
                }
                if let Some(body) = finalizer {
                    self.block(body, names, env, owner);
                }
            }
            Stmt::Switch { disc, cases, .. } => {
                self.expr(disc, names, env, owner);
                for (e, body) in cases {
                    if let Some(e) = e {
                        self.expr(e, names, env, owner);
                    }
                    self.block(body, names, env, owner);
                }
            }
            Stmt::Exported(s) => self.stmt(s, names, env, owner),
            Stmt::Return(None, _)
            | Stmt::Break(_)
            | Stmt::Continue(_)
            | Stmt::TypeAlias { .. }
            | Stmt::Import { .. }
            | Stmt::ExportNames { .. }
            | Stmt::ExportAll { .. } => {}
        }
    }

    fn bounded(&self, mut p: Place) -> Place {
        if p.path.len() > self.max_depth {
            let terminal = if p
                .path
                .iter()
                .any(|step| matches!(step, Step::DynamicValue | Step::DynamicDescendants))
            {
                Step::DynamicDescendants
            } else {
                Step::Descendants
            };
            p.path.truncate(self.max_depth);
            p.path.push(terminal);
        }
        p
    }

    fn call_bindings(&self, call: &Call, function: Span) -> Vec<(Option<Place>, Place)> {
        let parameters = &self.functions[&function];
        let rest = self.rest_parameters.get(&function).copied();
        let mut bindings = Vec::new();
        let mut minimum = 0;
        let mut shifted = false;
        for (argument, spread) in call.arguments(self.methods.contains(&function)) {
            shifted |= spread;
            let end = if shifted {
                parameters.len().max(minimum + 1)
            } else {
                minimum + 1
            };
            for index in minimum..end {
                let parameter = if let Some(rest) = rest.filter(|rest| index >= *rest) {
                    parameters[rest].at(Step::Index)
                } else if let Some(parameter) = parameters.get(index) {
                    parameter.clone()
                } else {
                    continue;
                };
                bindings.push((argument.clone(), parameter));
            }
            if !spread {
                minimum += 1;
            }
        }
        bindings
    }

    fn caller_effects(&self, function: Span, bindings: &[(Option<Place>, Place)], effect: Place) -> Vec<Place> {
        if self.functions[&function]
            .iter()
            .any(|param| param.binding == effect.binding)
        {
            bindings
                .iter()
                .filter_map(|(argument, parameter)| {
                    if parameter.binding != effect.binding {
                        return None;
                    }
                    let suffix = projected_suffix(&effect.path, &parameter.path)?;
                    // Assigning a parameter or a fresh rest slot cannot replace
                    // the caller's binding.
                    if suffix.is_empty() {
                        return None;
                    }
                    let mut target = argument.as_ref()?.clone();
                    target.path.extend_from_slice(suffix);
                    Some(self.bounded(target))
                })
                .collect()
        } else if self.bindings.get(&effect.binding) != Some(&function) {
            vec![effect]
        } else {
            Vec::new()
        }
    }

    fn loop_history(&mut self, owner: Span, first_call: usize, before: &HashSet<Span>, key: Span) {
        let events: HashSet<_> = self
            .history
            .get(&owner)
            .into_iter()
            .flat_map(|events| events.difference(before).copied())
            .collect();
        self.loop_events.insert(key, events.clone());
        for call in &mut self.calls[first_call..] {
            if call.owner == owner {
                call.prior.extend(&events);
            }
        }
    }

    fn expand_aliases(paths: &mut HashSet<Place>, copies: &HashMap<Span, Vec<(Place, Place)>>, max_depth: usize) {
        let mut pending: Vec<_> = paths.iter().cloned().collect();
        while let Some(place) = pending.pop() {
            for (source, target) in copies.get(&place.binding).into_iter().flatten() {
                let Some(suffix) = projected_suffix(&place.path, &source.path) else {
                    continue;
                };
                // Copying a slot retains its value's aliases, not future replacements of that slot.
                if suffix.is_empty() || suffix == [Step::DynamicValue] {
                    continue;
                }
                let mut alias = target.clone();
                alias.path.extend_from_slice(suffix);
                if alias.path.len() > max_depth {
                    let terminal = if alias
                        .path
                        .iter()
                        .any(|step| matches!(step, Step::DynamicValue | Step::DynamicDescendants))
                    {
                        Step::DynamicDescendants
                    } else {
                        Step::Descendants
                    };
                    alias.path.truncate(max_depth);
                    alias.path.push(terminal);
                }
                if paths.insert(alias.clone()) {
                    pending.push(alias);
                }
            }
        }
    }

    fn solve(mut self) -> Effects {
        self.max_depth = self
            .copies
            .iter()
            .flat_map(|(a, b, _)| [a.path.len(), b.path.len()])
            .chain(self.numeric.iter().map(|p| p.path.len()))
            .chain(self.function_writes.values().flatten().map(|p| p.path.len()))
            .max()
            .unwrap_or(0)
            + 1;
        loop {
            let before = (
                self.reachable.len(),
                self.incoming.len(),
                self.incoming_writes.len(),
                (self.values.len(), self.slice_methods.len()),
                self.copies.len(),
                self.numeric.len(),
                self.writes.len(),
                self.function_numeric.values().map(HashSet::len).sum::<usize>(),
                self.function_writes.values().map(HashSet::len).sum::<usize>(),
                self.events.values().map(HashSet::len).sum::<usize>(),
                self.write_events.values().map(HashSet::len).sum::<usize>(),
                self.escaped.len(),
            );
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    self.reachable.insert(*function);
                    for (arg, param) in self.call_bindings(call, *function) {
                        if let Some(arg) = arg {
                            self.copies.insert((arg, param, call.owner));
                        }
                    }
                    if let Some(arguments) = self
                        .argument_values
                        .get(function)
                        .filter(|arguments| self.argument_reads.contains(&arguments.binding))
                    {
                        for argument in call.args.iter().flatten() {
                            self.copies
                                .insert((argument.clone(), arguments.at(Step::Index), call.owner));
                        }
                    }
                    self.copies
                        .insert((Place::new(*function).at(Step::Result), call.result.clone(), call.owner));
                }
            }
            let values: Vec<_> = self.values.iter().cloned().collect();
            let slice_methods: Vec<_> = self.slice_methods.iter().cloned().collect();
            for (src, dest, owner) in &self.copies {
                if !self.reachable.contains(owner) {
                    continue;
                }
                for (value, function) in &values {
                    if value.binding == src.binding
                        && let Some(suffix) = projected_suffix(&value.path, &src.path)
                    {
                        let mut target = dest.clone();
                        target.path.extend_from_slice(suffix);
                        self.values.insert((self.bounded(target), *function));
                    }
                }
                for method in &slice_methods {
                    if method.binding == src.binding
                        && let Some(suffix) = projected_suffix(&method.path, &src.path)
                    {
                        let mut target = dest.clone();
                        target.path.extend_from_slice(suffix);
                        self.slice_methods.insert(self.bounded(target));
                    }
                }
            }
            for (method, receiver, result, owner) in &self.borrowed_slices {
                if self.reachable.contains(owner) && self.slice_methods.iter().any(|value| callable_at(value, method)) {
                    self.copies
                        .insert((receiver.at(Step::Index), result.at(Step::Index), *owner));
                }
            }
            for incoming in [&mut self.incoming, &mut self.incoming_writes] {
                let mut projected = Vec::new();
                for (binding, view) in &self.parameter_views {
                    for p in incoming.iter() {
                        if p.binding == view.binding
                            && let Some(path) = projected_suffix(&p.path, &view.path)
                        {
                            projected.push(Place {
                                binding: *binding,
                                path: path.to_vec(),
                            });
                        }
                    }
                }
                incoming.extend(projected);
            }
            self.numeric = self
                .function_numeric
                .iter()
                .filter(|(owner, _)| self.reachable.contains(owner))
                .flat_map(|(_, paths)| paths.iter().cloned())
                .chain(self.incoming.iter().cloned())
                .collect();
            self.writes = self
                .function_writes
                .iter()
                .filter(|(owner, _)| self.reachable.contains(owner))
                .flat_map(|(_, paths)| paths.iter().cloned())
                .collect();
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    let bindings = self.call_bindings(call, *function);
                    for (arg, param) in &bindings {
                        let Some(arg) = arg else {
                            continue;
                        };
                        // Use writes that can precede this call, rather than
                        // later writes elsewhere in the caller. Captures keep
                        // their stable view because closures can run later.
                        let captures = self.bindings.get(&arg.binding) != Some(&call.owner);
                        let prior = call.prior.iter().filter_map(|event| self.events.get(event)).flatten();
                        let inputs = self
                            .incoming
                            .iter()
                            .chain(prior)
                            .chain(self.numeric.iter().filter(|_| captures));
                        let mut incoming = Vec::new();
                        for p in inputs {
                            if p.binding == arg.binding
                                && let Some(path) = projected_suffix(&p.path, &arg.path)
                            {
                                let mut target = param.clone();
                                target.path.extend_from_slice(path);
                                incoming.push(self.bounded(target));
                            }
                        }
                        self.incoming.extend(incoming);
                        let prior = call
                            .prior
                            .iter()
                            .filter_map(|event| self.write_events.get(event))
                            .flatten();
                        let inputs = self
                            .incoming_writes
                            .iter()
                            .chain(prior)
                            .chain(self.writes.iter().filter(|_| captures));
                        let mut incoming = Vec::new();
                        for p in inputs {
                            if p.binding == arg.binding
                                && let Some(path) = projected_suffix(&p.path, &arg.path)
                            {
                                let mut target = param.clone();
                                target.path.extend_from_slice(path);
                                incoming.push(self.bounded(target));
                            }
                        }
                        self.incoming_writes.extend(incoming);
                    }
                    let summary = self.function_numeric.get(function).cloned().unwrap_or_default();
                    for p in summary {
                        for target in self.caller_effects(*function, &bindings, p) {
                            self.events.entry(call.span).or_default().insert(target.clone());
                            self.function_numeric
                                .entry(call.owner)
                                .or_default()
                                .insert(target.clone());
                            self.numeric.insert(target);
                        }
                    }
                    let summary = self.function_writes.get(function).cloned().unwrap_or_default();
                    for p in summary {
                        for target in self.caller_effects(*function, &bindings, p) {
                            self.write_events.entry(call.span).or_default().insert(target.clone());
                            self.function_writes.entry(call.owner).or_default().insert(target);
                        }
                    }
                }
            }
            let mut escaped = Vec::new();
            for (src, dest, owner) in &self.copies {
                if self.reachable.contains(owner)
                    && self
                        .escaped
                        .iter()
                        .any(|e| e.binding == dest.binding && projected_suffix(&dest.path, &e.path).is_some())
                {
                    escaped.push(src.clone());
                }
            }
            self.escaped.extend(escaped);
            for (event, owner) in &self.call_events {
                let invalidated = self
                    .escaped
                    .iter()
                    .filter(|p| {
                        self.bindings
                            .get(&p.binding)
                            .is_none_or(|binding_owner| binding_owner == owner)
                    })
                    .map(|p| p.at(Step::Descendants));
                self.write_events.entry(*event).or_default().extend(invalidated);
            }
            for (event, paths) in &mut self.write_events {
                let owner = self.event_owners.get(event);
                if paths.iter().any(|p| {
                    !p.path.is_empty()
                        && (self.external.contains(&p.binding)
                            || self
                                .escaped
                                .iter()
                                .any(|e| e.binding == p.binding && projected_suffix(&p.path, &e.path).is_some()))
                }) {
                    let external = self
                        .external
                        .iter()
                        .filter(|binding| owner.is_some() && self.bindings.get(binding) == owner)
                        .map(|binding| Place::new(*binding).at(Step::Descendants));
                    paths.extend(external);
                }
            }
            let mut aliases: HashMap<Span, Vec<(Place, Place)>> = HashMap::new();
            for (a, b, owner) in &self.copies {
                if self.reachable.contains(owner) {
                    aliases.entry(a.binding).or_default().push((a.clone(), b.clone()));
                    aliases.entry(b.binding).or_default().push((b.clone(), a.clone()));
                }
            }
            for map in [
                &mut self.function_numeric,
                &mut self.function_writes,
                &mut self.events,
                &mut self.write_events,
            ] {
                for paths in map.values_mut() {
                    Self::expand_aliases(paths, &aliases, self.max_depth);
                }
            }
            // Entry views only flow from a caller's prior events into that
            // invocation. Expanding them back through callback aliases would
            // move writes performed inside a function before its own entry.
            if before
                == (
                    self.reachable.len(),
                    self.incoming.len(),
                    self.incoming_writes.len(),
                    (self.values.len(), self.slice_methods.len()),
                    self.copies.len(),
                    self.numeric.len(),
                    self.writes.len(),
                    self.function_numeric.values().map(HashSet::len).sum::<usize>(),
                    self.function_writes.values().map(HashSet::len).sum::<usize>(),
                    self.events.values().map(HashSet::len).sum::<usize>(),
                    self.write_events.values().map(HashSet::len).sum::<usize>(),
                    self.escaped.len(),
                )
            {
                break;
            }
        }
        let mut entry_history: HashMap<Span, HashSet<Span>> = HashMap::new();
        loop {
            let before = entry_history.values().map(HashSet::len).sum::<usize>();
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                let mut prior = entry_history.get(&call.owner).cloned().unwrap_or_default();
                prior.extend(&call.prior);
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    entry_history.entry(*function).or_default().extend(&prior);
                }
            }
            if before == entry_history.values().map(HashSet::len).sum::<usize>() {
                break;
            }
        }
        // Possible entry events invalidate reads. Only events preceding every
        // entry may be discounted at creation: two calls can create different
        // instances of the same lexical closure. Loop events may repeat after
        // creation, so they are never creation predecessors.
        let repeating: HashSet<_> = self.loop_events.values().flatten().copied().collect();
        let mut creation_entries = entry_history.clone();
        for events in creation_entries.values_mut() {
            events.retain(|event| !repeating.contains(event));
        }
        for root in &self.roots {
            creation_entries.insert(*root, HashSet::new());
        }
        loop {
            let mut next: HashMap<Span, HashSet<Span>> =
                self.roots.iter().map(|root| (*root, HashSet::new())).collect();
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                let mut prior = creation_entries.get(&call.owner).cloned().unwrap_or_default();
                prior.extend(call.prior.difference(&repeating));
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    next.entry(*function)
                        .and_modify(|events| events.retain(|event| prior.contains(event)))
                        .or_insert_with(|| prior.clone());
                }
            }
            if next == creation_entries {
                break;
            }
            creation_entries = next;
        }
        let mut capture_writes = self.function_writes.clone();
        for (function, prior) in &entry_history {
            let mut created = self.created_prior.get(function).cloned().unwrap_or_default();
            created.retain(|event| !repeating.contains(event));
            if let Some(owner) = self.created_by.get(function) {
                created.extend(creation_entries.get(owner).into_iter().flatten());
            }
            for event in prior.difference(&created) {
                capture_writes
                    .entry(*function)
                    .or_default()
                    .extend(self.write_events.get(event).into_iter().flatten().cloned());
            }
        }
        let summaries = |histories: &HashMap<Span, HashSet<Span>>| {
            histories
                .iter()
                .map(|(span, events)| {
                    (
                        *span,
                        events
                            .iter()
                            .flat_map(|event| self.write_events.get(event).into_iter().flatten().cloned())
                            .collect(),
                    )
                })
                .collect()
        };
        let expression_writes = summaries(&self.expression_events);
        let loop_writes = summaries(&self.loop_events);
        let mut effects = Effects {
            captured: self.captured,
            captured_data: self.captured_data,
            numeric: HashMap::new(),
            incoming: HashMap::new(),
            incoming_writes: HashMap::new(),
            capture_writes,
            events: self.events,
            write_events: self.write_events,
            expression_writes,
            loop_writes,
        };
        for p in self.numeric {
            effects.numeric.entry(p.binding).or_default().insert(p.path);
        }
        for p in self.incoming {
            effects.incoming.entry(p.binding).or_default().insert(p.path);
        }
        for p in self.incoming_writes {
            effects.incoming_writes.entry(p.binding).or_default().insert(p.path);
        }
        effects
    }
}

fn scalar_return_annotation(annotation: &TyAnn) -> bool {
    match annotation {
        TyAnn::Num
        | TyAnn::NumLit(_)
        | TyAnn::Str
        | TyAnn::StrLit(_)
        | TyAnn::Bool
        | TyAnn::BoolLit(_)
        | TyAnn::Null
        | TyAnn::Undefined
        | TyAnn::Void
        | TyAnn::Never => true,
        TyAnn::Union(parts) => parts.iter().all(scalar_return_annotation),
        _ => false,
    }
}
