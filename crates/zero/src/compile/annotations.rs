//! Type-only normalization. OXC syntax and spans survive until every argument is bound.
use super::*;

#[derive(Clone)]
pub(super) struct BoundType {
    ty: Ty,
    mapped_source: MappedSource,
}

#[derive(Clone)]
enum MappedSource {
    None,
    Object(Ty),
    Unresolved,
}

impl BoundType {
    fn plain(ty: Ty) -> Self {
        Self {
            ty,
            mapped_source: MappedSource::None,
        }
    }
}

impl Compiler<'_, '_> {
    pub(super) fn resolve_ann(
        &mut self,
        a: &TyAnn,
        module: usize,
        generics: &HashMap<String, BoundType>,
    ) -> Result<Ty> {
        if self.annotation_depth == 0 {
            self.annotation_steps = 0;
        }
        type_step(&mut self.annotation_steps, annotation_span(a))?;
        if self.annotation_depth >= 96 {
            return err(
                annotation_span(a),
                "type expansion exceeds Zero's 96-level limit (recursive types aren't supported)",
            );
        }
        self.annotation_depth += 1;
        let result = self
            .resolve_ann_inner(a, module, generics)
            .map(normalize_union)
            .map_err(|mut d| {
                if d.span == Span::NONE {
                    d.span = annotation_span(a);
                }
                d
            });
        self.annotation_depth -= 1;
        result
    }

    /// PHP compilation and cached class shapes need runtime types even when class
    /// dispatch reenters compilation from inside an alias normalization.
    pub(super) fn resolve_runtime_ann(&mut self, a: &TyAnn, module: usize) -> Result<Ty> {
        self.resolve_annotation_root(a, module).map(runtime_type)
    }

    /// Resolve the authored domain before PHP/wire null normalization.
    pub(super) fn resolve_annotation_root(&mut self, a: &TyAnn, module: usize) -> Result<Ty> {
        // Each annotation is a new root, even when class dispatch reaches
        // it while another annotation is being normalized. Restore both budgets
        // before propagating errors; class readiness guards remain independent.
        let saved = (self.annotation_depth, self.annotation_steps);
        (self.annotation_depth, self.annotation_steps) = (0, 0);
        let result = self.resolve_ann(a, module, &HashMap::new());
        (self.annotation_depth, self.annotation_steps) = saved;
        result
    }

    fn resolve_ann_inner(&mut self, a: &TyAnn, module: usize, generics: &HashMap<String, BoundType>) -> Result<Ty> {
        Ok(match a {
            TyAnn::Num => Ty::Num,
            TyAnn::NumLit(n) => Ty::NumLit(*n),
            TyAnn::Str => Ty::Str,
            TyAnn::StrLit(s) => Ty::StrLit(s.clone()),
            TyAnn::Bool => Ty::Bool,
            TyAnn::BoolLit(value) => Ty::BoolLit(*value),
            TyAnn::Predicate(_, _, span) => {
                return err(*span, "type predicates belong on runtime function return annotations");
            }
            TyAnn::Null => Ty::Null,
            TyAnn::Undefined => Ty::Undefined,
            TyAnn::Void => Ty::Void,
            TyAnn::Unknown => Ty::Unknown,
            TyAnn::Never => Ty::Never,
            TyAnn::Any => {
                return err_help(
                    Span::NONE,
                    "`any` isn't allowed in Zero code",
                    "use a precise type, or `unknown` and narrow it",
                );
            }
            TyAnn::Array(t) => Ty::Array(Box::new(self.resolve_ann(t, module, generics)?)),
            TyAnn::Tuple(ts) => Ty::Tuple(
                ts.iter()
                    .map(|t| self.resolve_ann(t, module, generics))
                    .collect::<Result<_>>()?,
            ),
            TyAnn::Obj(fields) => {
                let mut out = IndexMap::new();
                for (k, t, opt) in fields {
                    let t = self.resolve_ann(t, module, generics)?;
                    out.insert(k.clone(), (t, *opt));
                }
                Ty::Obj(out)
            }
            TyAnn::Map(t) => Ty::Map(Box::new(self.resolve_ann(t, module, generics)?)),
            TyAnn::Union(ts) => Ty::union(
                ts.iter()
                    .map(|t| self.resolve_ann(t, module, generics))
                    .collect::<Result<Vec<_>>>()?,
            ),
            TyAnn::Fn(params, ret) => Ty::Fn(
                params
                    .iter()
                    .map(|(t, _)| self.resolve_ann(t, module, generics))
                    .collect::<Result<_>>()?,
                Box::new(self.resolve_ann(ret, module, generics)?),
            ),
            TyAnn::Ref(name, args, span) => {
                if let Some(t) = generics.get(name) {
                    if !args.is_empty() {
                        return err(*span, "a bound type parameter doesn't accept type arguments");
                    }
                    return Ok(t.ty.clone());
                }
                if args.is_empty()
                    && let Some(Value::Class(class)) = self
                        .ev
                        .module_scope(module)
                        .and_then(|s| s.lookup(name))
                        .map(|b| b.value)
                {
                    let id = self.ensure_class(&class)?;
                    if !self.classes[&id].fields_ready {
                        return err(
                            *span,
                            "recursive class field types are unsupported; use a plain data type",
                        );
                    }
                    return Ok(self.classes[&id].instance.clone());
                }
                if args.is_empty() {
                    for stmt in &self.ev_program().modules[module].body {
                        if let Stmt::Import { source, specs, .. } = stmt
                            && source == crate::loader::ZERO_MODULE
                        {
                            for spec in specs {
                                if spec.local == *name
                                    && let ImportName::Named(imported) = &spec.imported
                                {
                                    match imported.as_str() {
                                        "FieldAccessCtx" => return Ok(types::field_access_ctx()),
                                        "JobCtx" => return Ok(types::job_ctx()),
                                        _ => {}
                                    }
                                }
                            }
                        }
                    }
                }
                let alias = if self.type_aliases.contains_key(&(module, name.clone())) {
                    Some((module, name.clone()))
                } else {
                    self.imported_type(module, name)
                };
                if let Some((owner, local)) = alias {
                    return self.instantiate_alias(name, (owner, &local), args, *span, module, generics);
                }
                match (name.as_str(), args.as_slice()) {
                    ("Array" | "ReadonlyArray", [t]) => Ty::Array(Box::new(self.resolve_ann(t, module, generics)?)),
                    ("TemplateStringsArray", []) => Ty::NonEmptyArray(Box::new(Ty::Str)),
                    ("Promise", [t]) => Ty::Promise(Box::new(self.resolve_ann(t, module, generics)?)),
                    ("Date", []) => Ty::Date,
                    ("RegExp", []) => Ty::Regex,
                    ("Error", []) => Ty::Error,
                    ("Record", [k, v]) => {
                        let keys = self.resolve_ann(k, module, generics)?;
                        let value = self.resolve_ann(v, module, generics)?;
                        if keys == Ty::Str {
                            Ty::Map(Box::new(value))
                        } else {
                            Ty::Obj(
                                finite_keys(&keys, *span)?
                                    .into_iter()
                                    .map(|k| (k, (value.clone(), false)))
                                    .collect(),
                            )
                        }
                    }
                    ("Partial" | "Required" | "Readonly", [t]) => {
                        let t = self.resolve_ann(t, module, generics)?;
                        map_objects(t, *span, &|fields| {
                            Ok(fields
                                .into_iter()
                                .map(|(k, (t, optional))| {
                                    (
                                        k,
                                        (
                                            if name == "Required" && optional {
                                                without_undefined(t)
                                            } else {
                                                t
                                            },
                                            match name.as_str() {
                                                "Partial" => true,
                                                "Required" => false,
                                                _ => optional,
                                            },
                                        ),
                                    )
                                })
                                .collect())
                        })?
                    }
                    ("NonNullable", [t]) => {
                        let t = self.resolve_ann(t, module, generics)?;
                        if union_parts(t.clone())
                            .iter()
                            .any(|t| matches!(t, Ty::Unknown | Ty::Void))
                        {
                            return err(*span, "NonNullable needs a concrete type without unknown or void");
                        }
                        t.non_null()
                    }
                    ("Pick" | "Omit", [t, k]) => {
                        let t = self.resolve_ann(t, module, generics)?;
                        let keys = finite_keys(&self.resolve_ann(k, module, generics)?, *span)?;
                        let mut fields = if let Ty::Obj(fields) = t {
                            fields
                        } else {
                            // Pick/Omit map over keyof T, which is the common key
                            // set for unions, rather than distributing the utility.
                            type_copy(&t, *span, &mut self.annotation_steps)?;
                            finite_keys(&keys_of(&t, *span)?, *span)?
                                .into_iter()
                                .map(|key| {
                                    let field = t.field(&key).expect("common object key");
                                    (key, field)
                                })
                                .collect()
                        };
                        if name == "Pick" {
                            for key in &keys {
                                if !fields.contains_key(key) {
                                    return err(*span, format!("Pick key `{key}` isn't in the object"));
                                }
                            }
                        }
                        fields.retain(|k, _| keys.contains(k) == (name == "Pick"));
                        Ty::Obj(fields)
                    }
                    ("Exclude" | "Extract", [t, u]) => {
                        let t = self.resolve_ann(t, module, generics)?;
                        let u = self.resolve_ann(u, module, generics)?;
                        let mut parts = Vec::new();
                        for part in union_parts(t) {
                            if type_extends(&part, &u, *span)? == (name == "Extract") {
                                parts.push(part);
                            }
                        }
                        Ty::union(parts)
                    }
                    ("Awaited", [t]) => {
                        let mut t = self.resolve_ann(t, module, generics)?;
                        while let Ty::Promise(inner) = t {
                            t = *inner;
                        }
                        if matches!(t, Ty::Obj(_) | Ty::Union(_) | Ty::Unknown) {
                            return err(*span, "Awaited supports nested Promise and concrete scalar types");
                        }
                        t
                    }
                    ("ReturnType" | "Parameters", [t]) => {
                        let Ty::Fn(params, ret) = self.resolve_ann(t, module, generics)? else {
                            return err(*span, "ReturnType/Parameters need a non-generic function type");
                        };
                        if name == "ReturnType" { *ret } else { Ty::Tuple(params) }
                    }
                    ("InferSelectModel" | "InferInsertModel", [TyAnn::Query(path, qspan)]) => {
                        let table = self.collection_from_query(path, *qspan, module)?;
                        self.resolve_json_columns(&self.schema.collections[&table].columns)?;
                        model_work(
                            &self.schema.collections[&table].columns,
                            *qspan,
                            &mut self.annotation_steps,
                        )?;
                        let collection = &self.schema.collections[&table];
                        if name == "InferInsertModel" && collection.localized {
                            // Both authored translation alternatives copy their column domains.
                            model_work(&collection.columns, *qspan, &mut self.annotation_steps)?;
                        } else if collection.localized {
                            // The finite translations map repeats the primary-key domain.
                            let primary = collection.primary_key().expect("primary key");
                            for _ in collection.columns["locale"].enum_values.as_ref().expect("locales") {
                                model_column_work(primary, *qspan, &mut self.annotation_steps)?;
                            }
                        }
                        types::authored_model(collection, name == "InferInsertModel")
                    }
                    _ => return err(*span, format!("unknown type `{name}`")),
                }
            }
            TyAnn::Query(_, span) => {
                return err_help(
                    *span,
                    "`typeof` types aren't supported here",
                    "use InferSelectModel<typeof items> for rows",
                );
            }
            TyAnn::Intersection(parts, span) => {
                let mut out = Ty::Unknown;
                for part in parts {
                    let part = self.resolve_ann(part, module, generics)?;
                    out = intersect(out, part, *span, &mut self.annotation_steps)?;
                }
                out
            }
            TyAnn::KeyOf(t, span) => keys_of(&self.resolve_ann(t, module, generics)?, *span)?,
            TyAnn::Indexed(t, key, span) => {
                let t = self.resolve_ann(t, module, generics)?;
                let key = self.resolve_ann(key, module, generics)?;
                indexed(&t, &key, *span)?
            }
            TyAnn::Mapped {
                key,
                keys,
                value,
                optional,
                span,
            } => {
                let keys_ty = self.resolve_ann(keys, module, generics)?;
                let names = finite_keys(&keys_ty, *span)?;
                let source = if let TyAnn::KeyOf(t, _) = keys.as_ref() {
                    match self.resolve_ann(t, module, generics)? {
                        Ty::Obj(fields) => Some(fields),
                        _ => {
                            return err(
                                *span,
                                "homomorphic mapped types need one finite object; union, array and open-map mapping isn't supported",
                            );
                        }
                    }
                } else if let TyAnn::Ref(name, args, _) = keys.as_ref() {
                    match generics.get(name).filter(|_| args.is_empty()).map(|t| &t.mapped_source) {
                        Some(MappedSource::Object(Ty::Obj(fields))) => Some(fields.clone()),
                        Some(MappedSource::Object(_)) | Some(MappedSource::Unresolved) => {
                            return err(*span, "mapped key constraints need a direct keyof of one finite object");
                        }
                        Some(MappedSource::None) => None,
                        None => {
                            return err(
                                *span,
                                "mapped keys need direct keyof, a direct constrained parameter or literal keys; aliased key expressions can change optional presence",
                            );
                        }
                    }
                } else if literal_key_domain(keys) {
                    None
                } else {
                    return err(
                        *span,
                        "mapped keys need direct keyof, a direct constrained parameter or literal keys; reduced key expressions can change optional presence",
                    );
                };
                let mut fields = IndexMap::new();
                for name in names {
                    let mut g = generics.clone();
                    g.insert(key.clone(), BoundType::plain(Ty::StrLit(name.clone())));
                    // Homomorphic identity maps preserve the declared property type separately
                    // from its presence, including explicit null in a Required mapping.
                    let identity = match (keys.as_ref(), value.as_ref()) {
                        (_, TyAnn::Indexed(obj, index, _)) if source.is_some() => {
                            matches!(index.as_ref(), TyAnn::Ref(n, args, _) if n == key && args.is_empty())
                                && Some(self.resolve_ann(obj, module, &g)?) == source.clone().map(Ty::Obj)
                        }
                        _ => false,
                    };
                    if *optional == Some(false) && !identity {
                        return err(*span, "`-?` requires a homomorphic identity mapping; use Required<T>");
                    }
                    let old = source.as_ref().and_then(|f| f.get(&name));
                    let t = if identity {
                        match old {
                            Some((t, _)) => t.clone(),
                            None => self.resolve_ann(value, module, &g)?,
                        }
                    } else {
                        self.resolve_ann(value, module, &g)?
                    };
                    let opt = optional.unwrap_or_else(|| old.is_some_and(|(_, o)| *o));
                    let t = if *optional == Some(false) && old.is_some_and(|(_, o)| *o) {
                        without_undefined(t)
                    } else {
                        t
                    };
                    fields.insert(name, (t, opt));
                }
                Ty::Obj(fields)
            }
            TyAnn::Conditional {
                check,
                extends,
                yes,
                no,
                span,
            } => {
                // Preserve the distinction between a naked parameter and structural wrappers.
                // Alias/union/index expressions can reduce to a parameter before instantiation
                // in TypeScript. Refuse those check positions until that provenance is modeled.
                let parameter = match check.as_ref() {
                    TyAnn::Ref(name, args, _) if args.is_empty() && generics.contains_key(name) => Some(name),
                    _ => None,
                };
                if parameter.is_none()
                    && !matches!(
                        check.as_ref(),
                        TyAnn::Num
                            | TyAnn::NumLit(_)
                            | TyAnn::Str
                            | TyAnn::StrLit(_)
                            | TyAnn::Bool
                            | TyAnn::BoolLit(_)
                            | TyAnn::Null
                            | TyAnn::Undefined
                            | TyAnn::Never
                            | TyAnn::Array(_)
                            | TyAnn::Tuple(_)
                            | TyAnn::Obj(_)
                    )
                {
                    return err(
                        *span,
                        "conditional check expressions need a direct type parameter or a scalar/object/array/tuple wrapper; wrap aliases and type operations in a tuple to avoid ambiguous distributivity",
                    );
                }
                let checked = self.resolve_ann(check, module, generics)?;
                let parts = if parameter.is_some() {
                    union_parts(checked)
                } else {
                    vec![checked]
                };
                let mut results = Vec::new();
                for part in parts {
                    let mut g = generics.clone();
                    if let Some(name) = parameter {
                        let mut bound = generics[name].clone();
                        bound.ty = part.clone();
                        g.insert(name.clone(), bound);
                    }
                    let target = self.resolve_ann(extends, module, &g)?;
                    let branch = if type_extends(&part, &target, *span)? { yes } else { no };
                    results.push(self.resolve_ann(branch, module, &g)?);
                }
                Ty::union(results)
            }
        })
    }

    fn instantiate_alias(
        &mut self,
        name: &str,
        alias: (usize, &str),
        args: &[TyAnn],
        span: Span,
        module: usize,
        generics: &HashMap<String, BoundType>,
    ) -> Result<Ty> {
        let (owner, local) = alias;
        if self.duplicate_type_aliases.contains(&(owner, local.to_string())) {
            return err(
                span,
                "type declaration merging isn't supported; combine the declarations explicitly",
            );
        }
        let resolved = args
            .iter()
            .map(|a| self.resolve_ann(a, module, generics))
            .collect::<Result<Vec<_>>>()?;
        if let Some((params, body)) = self.type_aliases.get(&(owner, local.to_string())).cloned() {
            if resolved.len() > params.len() {
                return err(span, format!("`{name}` takes at most {} type arguments", params.len()));
            }
            let mut g = HashMap::new();
            for (i, p) in params.iter().enumerate() {
                let t = match resolved.get(i) {
                    Some(t) => t.clone(),
                    None => match &p.default {
                        Some(default) => self.resolve_ann(default, owner, &g)?,
                        None => {
                            return err(span, format!("`{name}` needs type argument `{}`", p.name));
                        }
                    },
                };
                if let Some(constraint) = &p.constraint {
                    let constraint = self.resolve_ann(constraint, owner, &g)?;
                    if !structural_extends(&t, &constraint) {
                        return err(
                            p.span,
                            format!(
                                "{} doesn't satisfy constraint {} for `{}`",
                                t.describe(),
                                constraint.describe(),
                                p.name
                            ),
                        );
                    }
                }
                let mapped_source = match &p.constraint {
                    Some(TyAnn::KeyOf(source, _)) => MappedSource::Object(self.resolve_ann(source, owner, &g)?),
                    Some(constraint) if matches!(constraint, TyAnn::Str) || literal_key_domain(constraint) => {
                        MappedSource::None
                    }
                    _ => MappedSource::Unresolved,
                };
                g.insert(p.name.clone(), BoundType { ty: t, mapped_source });
            }
            return self.resolve_ann(&body, owner, &g);
        }
        err(span, format!("unknown type `{name}`"))
    }

    fn imported_type(&self, module: usize, name: &str) -> Option<(usize, String)> {
        let program_modules = self.ev_program();
        let m = &program_modules.modules[module];
        for s in &m.body {
            if let Stmt::Import { source, specs, .. } = s {
                for spec in specs {
                    if spec.local == name {
                        let target = *program_modules.resolved.get(&(module, source.clone()))?;
                        let src = match &spec.imported {
                            ImportName::Named(n) => n.clone(),
                            _ => return None,
                        };
                        return Some((target, src));
                    }
                }
            }
        }
        None
    }
}

pub(super) fn annotation_span(a: &TyAnn) -> Span {
    match a {
        TyAnn::Ref(_, _, s)
        | TyAnn::Query(_, s)
        | TyAnn::KeyOf(_, s)
        | TyAnn::Indexed(_, _, s)
        | TyAnn::Intersection(_, s)
        | TyAnn::Mapped { span: s, .. }
        | TyAnn::Conditional { span: s, .. } => *s,
        TyAnn::Array(t) | TyAnn::Map(t) => annotation_span(t),
        TyAnn::Obj(fields) => fields
            .iter()
            .map(|(_, t, _)| annotation_span(t))
            .find(|s| *s != Span::NONE)
            .unwrap_or(Span::NONE),
        TyAnn::Tuple(ts) | TyAnn::Union(ts) => ts
            .iter()
            .map(annotation_span)
            .find(|s| *s != Span::NONE)
            .unwrap_or(Span::NONE),
        _ => Span::NONE,
    }
}

fn union_parts(t: Ty) -> Vec<Ty> {
    match t {
        Ty::Union(parts) => parts.into_iter().flat_map(union_parts).collect(),
        Ty::Bool => vec![Ty::BoolLit(true), Ty::BoolLit(false)],
        Ty::Never => vec![],
        t => vec![t],
    }
}

fn finite_keys(t: &Ty, span: Span) -> Result<Vec<String>> {
    let mut keys = Vec::new();
    for part in union_parts(t.clone()) {
        let Ty::StrLit(key) = part else {
            return err(span, "this type operation needs a finite union of string keys");
        };
        keys.push(key);
    }
    if keys.len() > 512 {
        return err(span, "type operation exceeds Zero's 512-key limit");
    }
    Ok(keys)
}

fn keys_of(t: &Ty, span: Span) -> Result<Ty> {
    match t {
        Ty::Obj(fields) => Ok(Ty::union(fields.keys().map(|k| Ty::StrLit(k.clone())))),
        Ty::Union(parts) => {
            let mut keys: Option<Vec<String>> = None;
            for t in parts {
                let next = finite_keys(&keys_of(t, span)?, span)?;
                keys = Some(match keys {
                    None => next,
                    Some(mut keys) => {
                        keys.retain(|key| next.contains(key));
                        keys
                    }
                });
            }
            Ok(Ty::union(keys.unwrap_or_default().into_iter().map(Ty::StrLit)))
        }
        _ => err(
            span,
            "keyof supports finite object types; open maps, arrays and primitives need an explicit key type",
        ),
    }
}

fn indexed(t: &Ty, key: &Ty, span: Span) -> Result<Ty> {
    if let Ty::Union(parts) = key {
        return Ok(Ty::union(
            parts.iter().map(|k| indexed(t, k, span)).collect::<Result<Vec<_>>>()?,
        ));
    }
    if let Ty::Union(parts) = t {
        return Ok(Ty::union(
            parts
                .iter()
                .map(|t| indexed(t, key, span))
                .collect::<Result<Vec<_>>>()?,
        ));
    }
    match (t, key) {
        (_, Ty::Never) | (Ty::Never, _) => Ok(Ty::Never),
        (Ty::Obj(fields), Ty::StrLit(k)) => match fields.get(k) {
            Some((t, optional)) => Ok(if *optional {
                Ty::union([t.clone(), Ty::Undefined])
            } else {
                t.clone()
            }),
            None => err(span, format!("{} has no field `{k}`", t.describe())),
        },
        (Ty::Map(t), Ty::Str | Ty::StrLit(_)) | (Ty::Array(t) | Ty::NonEmptyArray(t), Ty::Num | Ty::NumLit(_)) => {
            Ok((**t).clone())
        }
        (Ty::Tuple(ts), Ty::Num) => Ok(Ty::union(ts.clone())),
        (Ty::Tuple(ts), Ty::NumLit(n)) if n.fract() == 0.0 && *n >= 0.0 => ts
            .get(*n as usize)
            .cloned()
            .ok_or_else(|| Diag::new(span, "tuple type index is out of bounds")),
        _ => err(
            span,
            format!("can't index type {} with {}", t.describe(), key.describe()),
        ),
    }
}

fn map_objects(
    t: Ty,
    span: Span,
    f: &impl Fn(IndexMap<String, (Ty, bool)>) -> Result<IndexMap<String, (Ty, bool)>>,
) -> Result<Ty> {
    match t {
        Ty::Obj(fields) => Ok(Ty::Obj(f(fields)?)),
        Ty::Union(parts) => Ok(Ty::union(
            parts
                .into_iter()
                .map(|t| map_objects(t, span, f))
                .collect::<Result<Vec<_>>>()?,
        )),
        _ => err(span, "this utility supports finite object types"),
    }
}

/// Unlike runtime assignment (which protects exact mutable shapes), type-level extends
/// is structural and permits extra fields. It never supplies an access/completion proof.
pub(super) fn structural_extends(a: &Ty, b: &Ty) -> bool {
    if a == b || *a == Ty::Never || *b == Ty::Unknown {
        return true;
    }
    if matches!(b, Ty::Obj(fields) if fields.is_empty()) {
        return !a.is_nullable();
    }
    match (a, b) {
        (Ty::Undefined, Ty::Void) => true,
        (Ty::Union(xs), _) => xs.iter().all(|a| structural_extends(a, b)),
        (_, Ty::Union(ys)) => ys.iter().any(|b| structural_extends(a, b)),
        (Ty::Obj(have), Ty::Obj(need)) => {
            let weak_unrelated = !have.is_empty()
                && !need.is_empty()
                && need.values().all(|(_, optional)| *optional)
                && !need.keys().any(|key| have.contains_key(key));
            !weak_unrelated
                && need.iter().all(|(k, (t, optional))| match have.get(k) {
                    Some((ht, ho)) => {
                        (!ho || *optional)
                            && structural_extends(
                                ht,
                                &if *optional {
                                    Ty::union([t.clone(), Ty::Undefined])
                                } else {
                                    t.clone()
                                },
                            )
                    }
                    None => *optional,
                })
        }
        (Ty::Array(a) | Ty::NonEmptyArray(a), Ty::Array(b)) | (Ty::NonEmptyArray(a), Ty::NonEmptyArray(b)) => {
            structural_extends(a, b)
        }
        (Ty::Tuple(xs), Ty::NonEmptyArray(b)) => !xs.is_empty() && xs.iter().all(|a| structural_extends(a, b)),
        (Ty::Tuple(ts), Ty::Array(b)) => ts.iter().all(|a| structural_extends(a, b)),
        (Ty::Tuple(xs), Ty::Tuple(ys)) => {
            xs.len() == ys.len() && xs.iter().zip(ys).all(|(a, b)| structural_extends(a, b))
        }
        (Ty::NumLit(_), Ty::Num) | (Ty::StrLit(_), Ty::Str) | (Ty::BoolLit(_), Ty::Bool) => true,
        // Runtime assignment includes PHP representation conversions (Date to number).
        // Those conversions must never decide a TypeScript constraint or conditional.
        _ => false,
    }
}

fn predicate_type(t: &Ty) -> bool {
    match t {
        Ty::Num
        | Ty::NumLit(_)
        | Ty::Str
        | Ty::StrLit(_)
        | Ty::Bool
        | Ty::BoolLit(_)
        | Ty::Never
        | Ty::Null
        | Ty::Undefined => true,
        Ty::Obj(fields) => fields.values().all(|(t, _)| predicate_type(t)),
        Ty::Array(t) | Ty::NonEmptyArray(t) => predicate_type(t),
        Ty::Tuple(ts) | Ty::Union(ts) => ts.iter().all(predicate_type),
        _ => false,
    }
}

fn type_extends(a: &Ty, b: &Ty, span: Span) -> Result<bool> {
    if !predicate_type(a) || !predicate_type(b) {
        return err(
            span,
            "conditional predicates support concrete scalar, array and finite object types; unknown, void, functions and runtime types need an explicit type",
        );
    }
    check_structural_predicate(a, b, span)?;
    Ok(structural_extends(a, b))
}

fn check_structural_predicate(a: &Ty, b: &Ty, span: Span) -> Result<()> {
    match (a, b) {
        (Ty::Union(xs), _) => {
            for a in xs {
                check_structural_predicate(a, b, span)?;
            }
        }
        (_, Ty::Union(ys)) => {
            for b in ys {
                check_structural_predicate(a, b, span)?;
            }
        }
        (Ty::Obj(have), Ty::Obj(need)) => {
            for (k, (b, _)) in need {
                if let Some((a, _)) = have.get(k) {
                    check_structural_predicate(a, b, span)?;
                }
            }
        }
        (_, Ty::Obj(fields))
            if !fields.is_empty() && !matches!(a, Ty::Obj(_) | Ty::Never | Ty::Null | Ty::Undefined) =>
        {
            return err(
                span,
                "conditional structural matching of primitives or arrays against object properties isn't supported",
            );
        }
        (Ty::Array(a) | Ty::NonEmptyArray(a), Ty::Array(b) | Ty::NonEmptyArray(b)) => {
            check_structural_predicate(a, b, span)?
        }
        (Ty::Tuple(xs), Ty::Array(b)) => {
            for a in xs {
                check_structural_predicate(a, b, span)?;
            }
        }
        (Ty::Tuple(xs), Ty::Tuple(ys)) => {
            for (a, b) in xs.iter().zip(ys) {
                check_structural_predicate(a, b, span)?;
            }
        }
        _ => {}
    }
    Ok(())
}

// Restrict fresh mapped key sets to syntax whose reduction cannot hide keyof
// provenance. In particular, `keyof T | never` is not a literal union here.
fn literal_key_domain(a: &TyAnn) -> bool {
    match a {
        TyAnn::StrLit(_) | TyAnn::Never => true,
        TyAnn::Union(parts) => parts.iter().all(literal_key_domain),
        _ => false,
    }
}

fn type_step(steps: &mut usize, span: Span) -> Result<()> {
    *steps += 1;
    if *steps > 16_384 {
        return err(span, "type expansion exceeds Zero's 16384-step limit");
    }
    Ok(())
}

fn type_copy(t: &Ty, span: Span, steps: &mut usize) -> Result<()> {
    type_step(steps, span)?;
    match t {
        Ty::Array(t) | Ty::NonEmptyArray(t) | Ty::Rest(t) | Ty::Map(t) | Ty::Promise(t) | Ty::Instance(_, t) => {
            type_copy(t, span, steps)?
        }
        Ty::Tuple(parts) | Ty::ArrayLiteral(parts) | Ty::Union(parts) => {
            for t in parts {
                type_copy(t, span, steps)?;
            }
        }
        Ty::Obj(fields) => {
            for (t, _) in fields.values() {
                type_copy(t, span, steps)?;
            }
        }
        Ty::Fn(params, ret) => {
            for t in params {
                type_copy(t, span, steps)?;
            }
            type_copy(ret, span, steps)?;
        }
        _ => {}
    }
    Ok(())
}

fn model_work(columns: &IndexMap<String, Column>, span: Span, steps: &mut usize) -> Result<()> {
    for col in columns.values() {
        model_column_work(col, span, steps)?;
    }
    Ok(())
}

fn model_column_work(col: &Column, span: Span, steps: &mut usize) -> Result<()> {
    type_step(steps, span)?;
    if let Some(values) = &col.enum_values {
        // Finite values and the localized translation map are materialized too.
        for _ in values {
            type_step(steps, span)?;
            type_step(steps, span)?;
        }
    }
    JSON_TYPES.with(|m| {
        if let Some(t) = m.borrow().get(&(col as *const Column as usize)) {
            type_copy(&t.authored, span, steps)?;
        }
        Ok(())
    })?;
    if let Some(fields) = &col.fields {
        model_work(fields, span, steps)?;
    }
    if let Some(layouts) = &col.layouts {
        for fields in layouts.values() {
            model_work(fields, span, steps)?;
        }
    }
    Ok(())
}

fn intersect(a: Ty, b: Ty, span: Span, steps: &mut usize) -> Result<Ty> {
    type_step(steps, span)?;
    if a == b || b == Ty::Unknown {
        return Ok(a);
    }
    if a == Ty::Unknown {
        return Ok(b);
    }
    if a == Ty::Never || b == Ty::Never {
        return Ok(Ty::Never);
    }
    // Bound candidates before cloning or distributing either operand. Even
    // distinct small unions can otherwise create an exponential number of
    // objects while visiting only a handful of source annotations.
    let alternatives = |t: &Ty| match t {
        Ty::Union(parts) => parts.len(),
        _ => 1,
    };
    if alternatives(&a).saturating_mul(alternatives(&b)) > 512 {
        return err(span, "intersection expansion exceeds Zero's 512-alternative limit");
    }
    match (a, b) {
        (Ty::Union(xs), b) => Ok(Ty::union(
            xs.into_iter()
                .map(|a| {
                    type_copy(&b, span, steps)?;
                    intersect(a, b.clone(), span, steps)
                })
                .collect::<Result<Vec<_>>>()?,
        )),
        (a, Ty::Union(ys)) => Ok(Ty::union(
            ys.into_iter()
                .map(|b| {
                    type_copy(&a, span, steps)?;
                    intersect(a.clone(), b, span, steps)
                })
                .collect::<Result<Vec<_>>>()?,
        )),
        (Ty::Null | Ty::Undefined, Ty::Obj(_) | Ty::Array(_) | Ty::Tuple(_))
        | (Ty::Obj(_) | Ty::Array(_) | Ty::Tuple(_), Ty::Null | Ty::Undefined) => Ok(Ty::Never),
        (Ty::Obj(mut fields), Ty::Obj(other)) => {
            for (k, (t, optional)) in other {
                type_step(steps, span)?;
                let value = match fields.shift_remove(&k) {
                    Some((old, old_opt)) => {
                        // Without exactOptionalPropertyTypes, optional members
                        // admit undefined even when their annotation omits it.
                        // Intersect those value domains before combining presence.
                        let old = if old_opt { Ty::union([old, Ty::Undefined]) } else { old };
                        let t = if optional { Ty::union([t, Ty::Undefined]) } else { t };
                        let t = intersect(old, t, span, steps)?;
                        if t == Ty::Never && !(optional && old_opt) {
                            return err(
                                span,
                                format!(
                                    "incompatible intersection property `{k}`; impossible object intersections aren't supported"
                                ),
                            );
                        }
                        (t, optional && old_opt)
                    }
                    None => (t, optional),
                };
                fields.insert(k, value);
            }
            Ok(Ty::Obj(fields))
        }
        (a, b)
            if predicate_type(&a)
                && predicate_type(&b)
                && !matches!(a, Ty::Obj(_) | Ty::Array(_) | Ty::Tuple(_))
                && !matches!(b, Ty::Obj(_) | Ty::Array(_) | Ty::Tuple(_)) =>
        {
            Ok(if structural_extends(&a, &b) {
                a
            } else if structural_extends(&b, &a) {
                b
            } else {
                Ty::Never
            })
        }
        _ => err(span, "intersections support finite objects and scalar literal types"),
    }
}

fn without_undefined(t: Ty) -> Ty {
    Ty::union(union_parts(t).into_iter().filter(|t| *t != Ty::Undefined))
}

/// Annotation-only distinctions end here. PHP's null/undefined representation is
/// unchanged, including nested array elements, properties and callback results.
pub(super) fn runtime_type(t: Ty) -> Ty {
    match t {
        Ty::Undefined | Ty::Void => Ty::Null,
        Ty::Array(t) => Ty::Array(Box::new(runtime_type(*t))),
        Ty::NonEmptyArray(t) => Ty::NonEmptyArray(Box::new(runtime_type(*t))),
        Ty::Rest(t) => Ty::Rest(Box::new(runtime_type(*t))),
        Ty::Tuple(ts) => Ty::Tuple(ts.into_iter().map(runtime_type).collect()),
        Ty::Obj(fields) => Ty::Obj(
            fields
                .into_iter()
                .map(|(k, (t, opt))| {
                    // Optional presence also admits a present undefined value.
                    // Keep this lift at the runtime boundary so Required can
                    // remove optional undefined during annotation normalization.
                    let t = if opt { Ty::union([t, Ty::Undefined]) } else { t };
                    (k, (runtime_type(t), opt))
                })
                .collect(),
        ),
        Ty::Map(t) => Ty::Map(Box::new(runtime_type(*t))),
        Ty::Instance(identity, fields) => Ty::Instance(identity, Box::new(runtime_type(*fields))),
        Ty::Union(ts) => Ty::union(ts.into_iter().map(runtime_type)),
        Ty::Fn(ps, ret) => Ty::Fn(ps.into_iter().map(runtime_type).collect(), Box::new(runtime_type(*ret))),
        Ty::Promise(t) => Ty::Promise(Box::new(runtime_type(*t))),
        t => t,
    }
}

fn normalize_union(t: Ty) -> Ty {
    let Ty::Union(mut parts) = t else { return t };
    if parts.contains(&Ty::Unknown) {
        return Ty::Unknown;
    }
    let string = parts.contains(&Ty::Str);
    let number = parts.contains(&Ty::Num);
    parts.retain(|t| !(string && matches!(t, Ty::StrLit(_)) || number && matches!(t, Ty::NumLit(_))));
    Ty::union(parts)
}
