//! Class dispatch uses the existing object handles; PHP's object model is not JavaScript's.
use super::*;

#[derive(Clone)]
pub(super) struct CompiledClass {
    _value: Rc<ClassValue>,
    name: String,
    pub(super) instance: Ty,
    pub(super) fields_ready: bool,
    statics: Ty,
    constructor: Ty,
    methods: HashMap<(bool, String), (String, Ty)>,
    fields: String,
    _static_data: Value,
}

fn key(class: &Rc<ClassValue>) -> usize {
    Rc::as_ptr(class) as usize
}

impl<'a, 'p> Compiler<'a, 'p> {
    pub(super) fn class_shape(&self, id: usize) -> Ty {
        self.classes[&id].statics.clone()
    }
    pub(super) fn constructor_receiver_read(&self, ty: &Ty) -> Ty {
        let Some(fx) = &self.fx else { return ty.clone() };
        let Some(Value::Class(class)) = fx.env.lookup(":class").map(|b| b.value) else {
            return ty.clone();
        };
        let Some(ctor) = &class.class.constructor else {
            return ty.clone();
        };
        if ctor.span != fx.function {
            return ty.clone();
        }
        let Ty::Instance(identity, fields) = ty else {
            return ty.clone();
        };
        let Ty::Obj(fields) = &**fields else { unreachable!() };
        let mut fields = fields.clone();
        for p in ctor.params.iter().skip(1) {
            if class.class.fields.iter().any(|field| field.span == p.span)
                && let Pat::Id(name, _) = &p.pat
                && let Some((ty, _)) = fields.get_mut(name)
            {
                *ty = Ty::union([ty.clone(), Ty::Undefined]);
            }
        }
        Ty::Instance(identity.clone(), Box::new(Ty::Obj(fields)))
    }
    pub(super) fn class_value(&mut self, class: &Rc<ClassValue>) -> Result<Out> {
        let id = self.ensure_class(class)?;
        Ok(Out::atom(
            format!("\\ZeroApp\\{}()", self.classes[&id].name),
            Ty::Class(id),
        ))
    }

    fn class_parameter(&mut self, p: &Param, module: usize) -> Result<Ty> {
        let ty = match &p.ann {
            Some(a) => self.resolve_runtime_ann(a, module)?,
            None => match p.default.as_ref().map(|e| &e.kind) {
                Some(ExprKind::Num(_)) => Ty::Num,
                Some(ExprKind::Str(_)) => Ty::Str,
                Some(ExprKind::Bool(_)) => Ty::Bool,
                _ => {
                    return err_help(
                        p.span,
                        "class parameters need a type or a primitive default",
                        "annotate the parameter",
                    );
                }
            },
        };
        Ok(parameter_input_type(p, ty))
    }

    fn class_signature(&mut self, f: &Func) -> Result<Ty> {
        let params = f
            .params
            .iter()
            .skip(1)
            .map(|p| {
                self.class_parameter(p, f.module)
                    .map(|ty| if p.rest { Ty::Rest(Box::new(ty)) } else { ty })
            })
            .collect::<Result<Vec<_>>>()?;
        let ret = match &f.ret {
            Some(a) => self.resolve_runtime_ann(a, f.module)?,
            None => Ty::Unknown,
        };
        Ok(Ty::Fn(
            params,
            Box::new(if f.is_async { Ty::Promise(Box::new(ret)) } else { ret }),
        ))
    }

    fn class_environment(&self, class: &Rc<ClassValue>) -> Rc<Scope> {
        let env = Scope::new(Some(class.env.clone()), class.env.module);
        env.vars.borrow_mut().insert(
            ":class".into(),
            Binding {
                initialized: true,
                value: Value::Class(class.clone()),
                mutable: false,
                module_level: false,
                ann: None,
            },
        );
        env
    }

    pub(super) fn ensure_class(&mut self, class: &Rc<ClassValue>) -> Result<usize> {
        let id = key(class);
        if self.classes.contains_key(&id) {
            return Ok(id);
        }
        let parent = class.base.as_ref().map(|c| self.ensure_class(c)).transpose()?;
        let c = &class.class;
        let name = self.unique_name(&format!("{}_class", c.name));
        let fields_name = self.unique_name(&format!("{}_fields", c.name));
        let mut fields = match parent.map(|id| self.classes[&id].instance.clone()) {
            Some(Ty::Instance(_, fields)) => match *fields {
                Ty::Obj(f) => f,
                _ => unreachable!(),
            },
            _ => IndexMap::new(),
        };
        let mut statics = match parent.map(|id| self.classes[&id].statics.clone()) {
            Some(Ty::Obj(f)) => f,
            _ => IndexMap::new(),
        };
        // Insert the identity before resolving annotations, including references to this class.
        let static_data = Value::object(class.statics.clone());
        let ancestors = match parent.map(|id| self.classes[&id].instance.clone()) {
            Some(Ty::Instance(identity, _)) => std::iter::once(identity.id).chain(identity.ancestors).collect(),
            _ => Vec::new(),
        };
        let identity = types::ClassIdentity { id, ancestors };
        self.classes.insert(
            id,
            CompiledClass {
                _value: class.clone(),
                name: name.clone(),
                instance: Ty::Instance(identity.clone(), Box::new(Ty::Obj(fields.clone()))),
                fields_ready: false,
                statics: Ty::Obj(statics.clone()),
                constructor: Ty::Fn(vec![], Box::new(Ty::Null)),
                methods: HashMap::new(),
                fields: fields_name.clone(),
                _static_data: static_data.clone(),
            },
        );
        for field in &c.fields {
            if parent.is_some_and(|p| {
                self.classes[&p]
                    .methods
                    .contains_key(&(field.is_static, field.name.clone()))
            }) {
                return err(field.span, "fields cannot shadow inherited methods");
            }
            let ty = match &field.ann {
                Some(a) => self.resolve_runtime_ann(a, class.env.module)?,
                None => {
                    let Some(init) = &field.init else {
                        return err(field.span, "uninitialized class fields need a type");
                    };
                    let inference_error = || {
                        Diag::new(
                            field.span,
                            "this field needs a type annotation; only plain data initializers infer a field type",
                        )
                    };
                    // Static initializers already ran exactly once during module evaluation.
                    // Instance initializers must never run effects merely to infer a type.
                    let value = if field.is_static {
                        class.statics[&field.name].clone()
                    } else {
                        if !data_initializer(init) {
                            return Err(inference_error());
                        }
                        self.ev.eval(init, &class.env).map_err(|_| inference_error())?
                    };
                    validate_captured_data(&value, field.span, &mut HashSet::new())?;
                    widen_fresh_literals(&ty_of_data(&value))
                }
            };
            let parameter_property = c
                .constructor
                .as_ref()
                .is_some_and(|f| f.params.iter().skip(1).any(|p| p.span == field.span));
            let ty = if field.init.is_none() && !parameter_property {
                Ty::union([ty, Ty::Undefined])
            } else {
                ty
            };
            if field.is_static {
                let value = &class.statics[&field.name];
                validate_captured_data(value, field.span, &mut HashSet::new())?;
                if !assignable(&ty_of_data(value), &ty) {
                    return err(field.span, "static field initializer does not match its declared type");
                }
            }
            let target = if field.is_static { &mut statics } else { &mut fields };
            if let Some((previous, _)) = target.get(&field.name)
                && (!assignable(&ty, previous) || !assignable(previous, &ty))
            {
                return err(field.span, "an overridden field must retain its inherited type");
            }
            target.insert(field.name.clone(), (ty, false));
        }
        let instance = Ty::Instance(identity, Box::new(Ty::Obj(fields)));
        let statics = Ty::Obj(statics);
        self.classes.get_mut(&id).unwrap().instance = instance.clone();
        self.classes.get_mut(&id).unwrap().fields_ready = true;
        self.classes.get_mut(&id).unwrap().statics = statics.clone();
        let constructor = if let Some(f) = &c.constructor {
            self.class_signature(f)?
        } else if let Some(parent) = parent {
            self.classes[&parent].constructor.clone()
        } else {
            Ty::Fn(vec![], Box::new(Ty::Null))
        };
        self.classes.get_mut(&id).unwrap().constructor = constructor;
        let mut methods = parent.map(|id| self.classes[&id].methods.clone()).unwrap_or_default();
        for (member, is_static, f) in &c.methods {
            let shape = if *is_static { &statics } else { &instance };
            if shape.field(member).is_some() {
                return err(f.span, "methods cannot shadow inherited fields");
            }
            let signature = self.class_signature(f)?;
            if let Some((_, previous)) = methods.get(&(*is_static, member.clone()))
                && !assignable(&signature, previous)
            {
                return err(
                    f.span,
                    "an overridden method must retain its inherited signature; annotate return types",
                );
            }
            let helper = self.unique_name(&format!("{}_{member}", c.name));
            methods.insert((*is_static, member.clone()), (helper, signature));
        }
        self.classes.get_mut(&id).unwrap().methods = methods;
        let env = self.class_environment(class);
        // Helpers are shared across callers; class_access_guard rejects execution in access rules.
        let no_db = false;
        let constructing_env = Scope::new(Some(env.clone()), env.module);
        constructing_env.vars.borrow_mut().insert(
            ":constructing".into(),
            Binding {
                initialized: true,
                value: Value::Bool(true),
                mutable: false,
                module_level: false,
                ann: None,
            },
        );
        // Access rules may encounter a class compiled earlier by an action. Validate each use below too.
        let mut field_body = String::new();
        for field in c.fields.iter().filter(|f| !f.is_static) {
            let value = match &field.init {
                Some(_) => {
                    let helper = self.unique_name(&format!("{}_init_{}", c.name, field.name));
                    let f = field.initializer(class.env.module).expect("initialized field");
                    let closure = Rc::new(Closure {
                        func: f,
                        env: constructing_env.clone(),
                    });
                    let initializing = initializing_instance(&instance);
                    self.compile_function(&closure, &helper, Some(vec![initializing]), None, no_db)?;
                    let Ty::Fn(_, result) = &self.helper_types[&closure_key(&closure)] else {
                        unreachable!()
                    };
                    let (want, _) = instance.field(&field.name).expect("class field");
                    if !assignable(result, &want) {
                        return err(
                            field.span,
                            "field initializer does not match its inferred type; annotate the field",
                        );
                    }
                    format!("\\ZeroApp\\{helper}($__zero_this)")
                }
                None => "\\Zero\\Js::undefined()".into(),
            };
            field_body.push_str(&format!("    $__zero_this->p[{}] = {value};\n", php_str(&field.name)));
        }
        writeln!(
            self.output,
            "function {fields_name}(\\Zero\\JsObject $__zero_this)\n{{\n{field_body}}}\n"
        )
        .unwrap();
        let ctor = if let Some(f) = &c.constructor {
            let helper = self.unique_name(&format!("{}_constructor", c.name));
            let Ty::Fn(params, _) = self.classes[&id].constructor.clone() else {
                unreachable!()
            };
            let closure = Rc::new(Closure {
                func: f.clone(),
                env: constructing_env.clone(),
            });
            self.compile_function(
                &closure,
                &helper,
                Some(std::iter::once(instance.clone()).chain(params).collect()),
                None,
                no_db,
            )?;
            format!("\\Closure::fromCallable({})", php_str(&format!("\\ZeroApp\\{helper}")))
        } else {
            "null".into()
        };
        let mut own_methods = Vec::new();
        let mut static_methods = Vec::new();
        for (member, is_static, f) in &c.methods {
            let (helper, signature) = self.classes[&id].methods[&(*is_static, member.clone())].clone();
            let Ty::Fn(params, _) = signature else { unreachable!() };
            let receiver = if *is_static { Ty::Class(id) } else { instance.clone() };
            let closure = Rc::new(Closure {
                func: f.clone(),
                env: env.clone(),
            });
            self.compile_function(
                &closure,
                &helper,
                Some(std::iter::once(receiver).chain(params).collect()),
                None,
                no_db,
            )?;
            let Ty::Fn(mut params, ret) = self.helper_types[&closure_key(&closure)].clone() else {
                unreachable!()
            };
            params.remove(0);
            self.classes
                .get_mut(&id)
                .unwrap()
                .methods
                .insert((*is_static, member.clone()), (helper.clone(), Ty::Fn(params, ret)));
            let entry = format!(
                "{} => \\Closure::fromCallable({})",
                php_str(member),
                php_str(&format!("\\ZeroApp\\{helper}"))
            );
            if *is_static {
                static_methods.push(entry);
            } else {
                own_methods.push(entry);
            }
        }
        validate_captured_data(&static_data, c.span, &mut HashSet::new())?;
        let data = self.captured_data(&static_data)?;
        let parent_php = parent
            .map(|id| format!("\\ZeroApp\\{}()", self.classes[&id].name))
            .unwrap_or("null".into());
        writeln!(self.output, "function {name}(): \\Zero\\JsClass\n{{\n    static $class;\n    return $class ??= new \\Zero\\JsClass({parent_php}, {ctor}, \\Closure::fromCallable({}), [{}], [{}], {data});\n}}\n", php_str(&format!("\\ZeroApp\\{fields_name}")), own_methods.join(", "), static_methods.join(", ")).unwrap();
        Ok(id)
    }

    pub(super) fn construct_class(&mut self, class: &Rc<ClassValue>, args: &[ArrEl], span: Span) -> Result<Out> {
        let id = self.ensure_class(class)?;
        let info = self.classes[&id].clone();
        let arguments = self.checked_class_args(&info.constructor, args, span)?;
        self.class_access_guard(span)?;
        Ok(Out::atom(
            format!("\\ZeroApp\\{}()->construct({})", info.name, arguments.join(", ")),
            info.instance,
        ))
    }

    fn checked_class_args(&mut self, signature: &Ty, args: &[ArrEl], span: Span) -> Result<Vec<String>> {
        let Ty::Fn(params, _) = signature else { unreachable!() };
        let argv = self.plain_args(args)?;
        if argv.len() > params.len() && !matches!(params.last(), Some(Ty::Rest(_)))
            || params.iter().skip(argv.len()).any(|t| !accepts_omission(t))
        {
            return err(span, "wrong number of class arguments");
        }
        for (i, (arg, syntax)) in argv.iter().zip(args).enumerate() {
            let Some(want) = callable_argument(params, i) else {
                return err(span, "wrong number of class arguments");
            };
            let ArrEl::Item(expression) = syntax else {
                unreachable!("plain_args rejects spreads")
            };
            // Match ordinary calls: mutation effects can broaden a read view
            // without changing the binding's authored argument contract.
            if !assignable(&arg.ty, &want)
                && (arg.ty.is_nullable() && !want.is_nullable()
                    || !self
                        .argument_constraint(expression)
                        .is_some_and(|ty| assignable(&ty, &want)))
            {
                return err(
                    span,
                    format!("class argument is {}, expected {}", arg.ty.describe(), want.describe()),
                );
            }
        }
        Ok(argv.into_iter().map(|o| o.php).collect())
    }

    pub(super) fn class_member(&mut self, receiver: Out, prop: &str, span: Span) -> Result<Out> {
        if receiver.ty.is_nullable() {
            return err(span, "check the instance before reading its fields");
        }
        let (id, is_static) = match receiver.ty.non_null() {
            Ty::Instance(identity, _) => (identity.id, false),
            Ty::Class(id) => (id, true),
            _ => unreachable!(),
        };
        let info = self.classes[&id].clone();
        if info.methods.contains_key(&(is_static, prop.into())) {
            return err_help(
                span,
                "extracting class methods is unsupported",
                "call instance.method(...) directly, or wrap it in an arrow to capture the receiver",
            );
        }
        let shape = if is_static {
            info.statics
        } else {
            receiver.ty.non_null()
        };
        let Some((ty, _)) = shape.field(prop) else {
            return Err(self.no_field(&shape, prop, span));
        };
        let php = if is_static {
            format!("{}->get({})", receiver.wrapped(), php_str(prop))
        } else {
            format!("\\Zero\\Js::dataGet({}, {})", receiver.php, php_str(prop))
        };
        Ok(Out::atom(
            php,
            if is_static { effects::class_static_read(&ty) } else { ty },
        ))
    }

    pub(super) fn class_method(&mut self, receiver: Out, member: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if self.constructing_class() {
            return err(
                span,
                "class method calls during construction are unsupported; call methods after new returns",
            );
        }
        let (id, is_static) = match receiver.ty.non_null() {
            Ty::Instance(identity, _) => (identity.id, false),
            Ty::Class(id) => (id, true),
            _ => unreachable!(),
        };
        if receiver.ty.is_nullable() {
            return err(span, "check the instance before calling its method");
        }
        let info = self.classes[&id].clone();
        let Some((_, signature)) = info.methods.get(&(is_static, member.into())) else {
            let f = self.class_member(receiver, member, span)?;
            return self.call_value(f, args, span, span);
        };
        let argv = self.checked_class_args(signature, args, span)?;
        self.class_access_guard(span)?;
        let Ty::Fn(_, ret) = signature else { unreachable!() };
        let php = format!(
            "\\Zero\\JsClass::invoke({}, {}, [{}], {})",
            receiver.php,
            php_str(member),
            argv.join(", "),
            if is_static { "true" } else { "false" }
        );
        Ok(Out::atom(php, (**ret).clone()))
    }

    fn class_access_guard(&self, span: Span) -> Result<()> {
        if self.fx.as_ref().is_some_and(|f| f.no_db) {
            // Until method effects participate in access-rule authority, refuse the seam entirely.
            return err_help(
                span,
                "class construction and method calls are unsupported in access rules",
                "return a filter from a plain function; use classes in actions and hooks",
            );
        }
        Ok(())
    }

    pub(super) fn class_internal_call(&mut self, name: &str, args: &[ArrEl], span: Span) -> Result<Option<Out>> {
        if !matches!(name, "super" | ":fields") {
            return Ok(None);
        }
        let Some(Value::Class(class)) = self.fx().env.lookup(":class").map(|b| b.value) else {
            return err(span, "super requires a class constructor");
        };
        let id = self.ensure_class(&class)?;
        let receiver = self.ident("this", span)?;
        if name == ":fields" {
            return Ok(Some(Out::atom(
                format!("\\ZeroApp\\{}({})", self.classes[&id].fields, receiver.php),
                Ty::Null,
            )));
        }
        let Some(base) = &class.base else {
            return err(span, "super requires a base class");
        };
        let base_id = self.ensure_class(base)?;
        let info = self.classes[&base_id].clone();
        let argv = self.checked_class_args(&info.constructor, args, span)?;
        Ok(Some(Out::atom(
            format!(
                "\\ZeroApp\\{}()->initialize({}, [{}])",
                info.name,
                receiver.php,
                argv.join(", ")
            ),
            Ty::Null,
        )))
    }

    pub(super) fn super_method(&mut self, member: &str, args: &[ArrEl], span: Span) -> Result<Out> {
        if self.constructing_class() {
            return err(
                span,
                "super methods during construction are unsupported; call methods after new returns",
            );
        }
        let Some(Value::Class(class)) = self.fx().env.lookup(":class").map(|b| b.value) else {
            return err(span, "super requires a class method");
        };
        let Some(base) = &class.base else {
            return err(span, "super requires a base class");
        };
        let id = self.ensure_class(base)?;
        let recv = self.ident("this", span)?;
        let is_static = matches!(recv.ty, Ty::Class(_));
        let Some((_, signature)) = self.classes[&id].methods.get(&(is_static, member.into())).cloned() else {
            return err(span, "unknown super method");
        };
        let argv = self.checked_class_args(&signature, args, span)?;
        let Ty::Fn(_, ret) = signature else { unreachable!() };
        Ok(Out::atom(
            format!(
                "\\ZeroApp\\{}()->invokeOn({}, {}, [{}], {})",
                self.classes[&id].name,
                recv.php,
                php_str(member),
                argv.join(", "),
                is_static
            ),
            *ret,
        ))
    }

    pub(super) fn constructing_class(&self) -> bool {
        self.fx
            .as_ref()
            .is_some_and(|f| f.env.lookup(":constructing").is_some())
    }
}

pub(super) fn wire_type(ty: &Ty) -> Ty {
    match ty {
        Ty::Instance(_, fields) => wire_type(fields),
        Ty::Obj(fields) => Ty::Obj(
            fields
                .iter()
                .map(|(k, (t, opt))| (k.clone(), (wire_type(t), *opt)))
                .collect(),
        ),
        Ty::Array(t) => Ty::Array(Box::new(wire_type(t))),
        Ty::NonEmptyArray(t) => Ty::NonEmptyArray(Box::new(wire_type(t))),
        Ty::ArrayLiteral(ts) => Ty::ArrayLiteral(ts.iter().map(wire_type).collect()),
        Ty::Tuple(ts) => Ty::Tuple(ts.iter().map(wire_type).collect()),
        Ty::Map(t) => Ty::Map(Box::new(wire_type(t))),
        Ty::Union(ts) => Ty::union(ts.iter().map(wire_type)),
        t => t.clone(),
    }
}

fn data_initializer(expr: &Expr) -> bool {
    match &expr.kind {
        ExprKind::Num(_)
        | ExprKind::Str(_)
        | ExprKind::Bool(_)
        | ExprKind::Null
        | ExprKind::Undefined
        | ExprKind::Ident(_) => true,
        ExprKind::Array(items) => items.iter().all(|item| match item {
            ArrEl::Item(e) | ArrEl::Spread(e) => data_initializer(e),
        }),
        ExprKind::Object(props) => props.iter().all(|prop| match prop {
            Prop::KV(PropKey::Static(_), e) | Prop::Spread(e) => data_initializer(e),
            Prop::KV(PropKey::Computed(k), e) => data_initializer(k) && data_initializer(e),
        }),
        ExprKind::Unary(_, e) | ExprKind::As(e, _) | ExprKind::NonNull(e) | ExprKind::Chain(e) => data_initializer(e),
        ExprKind::Member { obj, .. } => data_initializer(obj),
        ExprKind::Index { obj, index, .. } | ExprKind::Binary(_, obj, index) | ExprKind::Logical(_, obj, index) => {
            data_initializer(obj) && data_initializer(index)
        }
        ExprKind::Cond(a, b, c) => data_initializer(a) && data_initializer(b) && data_initializer(c),
        ExprKind::Template(_, es) => es.iter().all(data_initializer),
        _ => false,
    }
}

fn initializing_instance(instance: &Ty) -> Ty {
    let Ty::Instance(id, fields) = instance else {
        unreachable!()
    };
    let Ty::Obj(fields) = &**fields else { unreachable!() };
    Ty::Instance(
        id.clone(),
        Box::new(Ty::Obj(
            fields
                .iter()
                .map(|(k, (t, _))| (k.clone(), (Ty::union([t.clone(), Ty::Undefined]), true)))
                .collect(),
        )),
    )
}
