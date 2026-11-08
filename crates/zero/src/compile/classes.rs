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
    /// Kept alive with the class: captured-data helpers are cached by allocation address.
    _private_static_data: Option<Value>,
    /// Private field types by (static, `#name`); private state never joins the instance shape.
    privates: HashMap<(bool, String), Ty>,
    /// Classes from imported JavaScript use checked dynamic receivers, arguments and fields.
    pub(super) javascript: bool,
    /// Prototype accessors by (static, name), including inherited ones, with their value type.
    accessors: HashMap<(bool, String), Accessor>,
}

#[derive(Clone)]
struct Accessor {
    ty: Ty,
    getter: bool,
    setter: bool,
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
        let ty = if self.classes[&id].javascript {
            Ty::Dynamic
        } else {
            Ty::Class(id)
        };
        Ok(Out::atom(format!("\\ZeroApp\\{}()", self.classes[&id].name), ty))
    }

    fn class_parameter(&mut self, p: &Param, module: usize) -> Result<Ty> {
        let ty = match &p.ann {
            Some(a) => self.resolve_runtime_ann(a, module)?,
            None if self.javascript_module(module) => Ty::Dynamic,
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
            None if self.javascript_module(f.module) => Ty::Dynamic,
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
        // Classes carry static state, so each plugin compiles its own copy: the id is interned
        // per (owner, class), never derived from the pointer's bits.
        let next = self.class_ids.len();
        let id = *self
            .class_ids
            .entry((self.owner(), Rc::as_ptr(class) as usize))
            .or_insert(next);
        if self.classes.contains_key(&id) {
            return Ok(id);
        }
        let parent = class.base.as_ref().map(|c| self.ensure_class(c)).transpose()?;
        let c = &class.class;
        let javascript = self.javascript_module(class.env.module);
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
        // Private statics live on the descriptor, never in the public statics object.
        let (private_static_data, public_static_data): (IndexMap<_, _>, IndexMap<_, _>) = class
            .statics
            .iter()
            .map(|(k, v)| (k.clone(), v.clone()))
            .partition(|(k, _)| k.starts_with('#'));
        let static_data = if private_static_data.is_empty() {
            Value::object(class.statics.clone())
        } else {
            Value::object(public_static_data)
        };
        let private_static_data = (!private_static_data.is_empty()).then(|| Value::object(private_static_data));
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
                _private_static_data: private_static_data.clone(),
                privates: HashMap::new(),
                javascript,
                accessors: HashMap::new(),
            },
        );
        let mut privates = HashMap::new();
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
                None if javascript => Ty::Dynamic,
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
            let ty = if field.init.is_none() && !parameter_property && ty != Ty::Dynamic {
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
            if field.name.starts_with('#') {
                privates.insert((field.is_static, field.name.clone()), ty);
                continue;
            }
            let target = if field.is_static { &mut statics } else { &mut fields };
            if let Some((previous, _)) = target.get(&field.name)
                && !javascript
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
        self.classes.get_mut(&id).unwrap().privates = privates;
        let constructor = if let Some(f) = &c.constructor {
            self.class_signature(f)?
        } else if let Some(parent) = parent {
            self.classes[&parent].constructor.clone()
        } else {
            Ty::Fn(vec![], Box::new(Ty::Null))
        };
        self.classes.get_mut(&id).unwrap().constructor = constructor;
        // Private names are lexical to their declaring class, so subclasses never inherit them.
        let mut methods: HashMap<_, _> = parent
            .map(|id| self.classes[&id].methods.clone())
            .unwrap_or_default()
            .into_iter()
            .filter(|((_, name), _)| !name.starts_with('#'))
            .collect();
        for (member, is_static, f) in &c.methods {
            let shape = if *is_static { &statics } else { &instance };
            if !member.starts_with('#') && shape.field(member).is_some() {
                return err(f.span, "methods cannot shadow inherited fields");
            }
            let signature = self.class_signature(f)?;
            if let Some((_, previous)) = methods.get(&(*is_static, member.clone()))
                && !javascript
                && !assignable(&signature, previous)
            {
                return err(
                    f.span,
                    "an overridden method must retain its inherited signature; annotate return types",
                );
            }
            let helper = self.unique_name(&format!("{}_{}", c.name, member.replace('#', "private_")));
            methods.insert((*is_static, member.clone()), (helper, signature));
        }
        self.classes.get_mut(&id).unwrap().methods = methods;
        let mut accessors = parent.map(|id| self.classes[&id].accessors.clone()).unwrap_or_default();
        for accessor in &c.accessors {
            if accessor.name.starts_with('#') {
                return err_help(
                    accessor.func.span,
                    "private accessors aren't supported",
                    "use a private field with a private method",
                );
            }
            let key = (accessor.is_static, accessor.name.clone());
            let shape = if accessor.is_static { &statics } else { &instance };
            if shape.field(&accessor.name).is_some() || self.classes[&id].methods.contains_key(&key) {
                return err(
                    accessor.func.span,
                    "accessors cannot shadow inherited fields or methods",
                );
            }
        }
        let mut own_keys: Vec<(bool, String)> = c.accessors.iter().map(|a| (a.is_static, a.name.clone())).collect();
        own_keys.dedup();
        for key in own_keys {
            let pair: Vec<_> = c
                .accessors
                .iter()
                .filter(|a| (a.is_static, a.name.clone()) == key)
                .collect();
            let getter = pair.iter().find(|a| !a.setter);
            let setter = pair.iter().find(|a| a.setter);
            let annotated = getter
                .and_then(|g| g.func.ret.as_ref())
                .or_else(|| setter.and_then(|s| s.func.params.get(1).and_then(|p| p.ann.as_ref())));
            let ty = match (annotated, accessors.get(&key)) {
                (Some(a), _) => self.resolve_runtime_ann(a, class.env.module)?,
                (None, Some(inherited)) => inherited.ty.clone(),
                (None, None) if javascript => Ty::Dynamic,
                // Inferred from the getter body when it compiles, before any method reads it.
                (None, None) if getter.is_some() => Ty::Unknown,
                (None, None) => {
                    return err_help(
                        pair[0].func.span,
                        "setters without a getter need a parameter type",
                        "annotate set value(v: T)",
                    );
                }
            };
            // Redefining either half replaces the whole inherited pair, as in JavaScript.
            accessors.insert(
                key,
                Accessor {
                    ty,
                    getter: getter.is_some(),
                    setter: setter.is_some(),
                },
            );
        }
        self.classes.get_mut(&id).unwrap().accessors = accessors;
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
        if c.fields.iter().any(|f| !f.is_static && f.name.starts_with('#'))
            || c.methods
                .iter()
                .any(|(name, is_static, _)| !is_static && name.starts_with('#'))
        {
            field_body.push_str("    $__zero_class->brand($__zero_this);\n");
        }
        let receiver_ty = |instance: &Ty| if javascript { Ty::Dynamic } else { instance.clone() };
        for field in c.fields.iter().filter(|f| !f.is_static) {
            let value = match &field.init {
                Some(_) => {
                    let helper = self.unique_name(&format!("{}_init_{}", c.name, field.name));
                    let f = field.initializer(class.env.module).expect("initialized field");
                    let closure = Rc::new(Closure::new(f, constructing_env.clone()));
                    let initializing = if javascript {
                        Ty::Dynamic
                    } else {
                        initializing_instance(&instance)
                    };
                    self.compile_function(&closure, &helper, Some(vec![initializing]), None, no_db)?;
                    let Ty::Fn(_, result) = &self.helper_types[&closure_key(&closure)] else {
                        unreachable!()
                    };
                    let want = match self.classes[&id].privates.get(&(false, field.name.clone())) {
                        Some(ty) => ty.clone(),
                        None => instance.field(&field.name).expect("class field").0,
                    };
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
            if field.name.starts_with('#') {
                field_body.push_str(&format!(
                    "    $__zero_class->privateDefine($__zero_this, {}, {value});\n",
                    php_str(&field.name)
                ));
            } else {
                field_body.push_str(&format!("    $__zero_this->p[{}] = {value};\n", php_str(&field.name)));
            }
        }
        writeln!(
            self.output,
            "function {fields_name}(\\Zero\\JsObject $__zero_this, \\Zero\\JsClass $__zero_class)\n{{\n{field_body}}}\n"
        )
        .unwrap();
        let ctor = if let Some(f) = &c.constructor {
            let helper = self.unique_name(&format!("{}_constructor", c.name));
            let Ty::Fn(params, _) = self.classes[&id].constructor.clone() else {
                unreachable!()
            };
            let closure = Rc::new(Closure::new(f.clone(), constructing_env.clone()));
            self.compile_function(
                &closure,
                &helper,
                Some(std::iter::once(receiver_ty(&instance)).chain(params).collect()),
                None,
                no_db,
            )?;
            format!("\\Closure::fromCallable({})", php_str(&format!("\\ZeroApp\\{helper}")))
        } else {
            "null".into()
        };
        let mut accessor_entries: [IndexMap<String, (String, String)>; 2] = [IndexMap::new(), IndexMap::new()];
        let mut ordered: Vec<_> = c.accessors.iter().collect();
        ordered.sort_by_key(|a| a.setter);
        for accessor in ordered {
            let helper = self.unique_name(&format!(
                "{}_{}_{}",
                c.name,
                if accessor.setter { "set" } else { "get" },
                accessor.name
            ));
            let receiver = if javascript {
                Ty::Dynamic
            } else if accessor.is_static {
                Ty::Class(id)
            } else {
                instance.clone()
            };
            let ty = self.classes[&id].accessors[&(accessor.is_static, accessor.name.clone())]
                .ty
                .clone();
            let params = if accessor.setter {
                vec![receiver, ty.clone()]
            } else {
                vec![receiver]
            };
            let closure = Rc::new(Closure::new(accessor.func.clone(), env.clone()));
            self.compile_function(&closure, &helper, Some(params), None, no_db)?;
            if !accessor.setter && !javascript {
                let Ty::Fn(_, ret) = self.helper_types[&closure_key(&closure)].clone() else {
                    unreachable!()
                };
                let ret = widen_fresh_literals(&ret);
                if ty == Ty::Unknown {
                    self.classes
                        .get_mut(&id)
                        .unwrap()
                        .accessors
                        .get_mut(&(accessor.is_static, accessor.name.clone()))
                        .unwrap()
                        .ty = ret.clone();
                } else if !assignable(&ret, &ty) {
                    return err(
                        accessor.func.span,
                        format!("getter returns {}, expected {}", ret.describe(), ty.describe()),
                    );
                }
            }
            let callable = format!("\\Closure::fromCallable({})", php_str(&format!("\\ZeroApp\\{helper}")));
            let slot = accessor_entries[accessor.is_static as usize]
                .entry(accessor.name.clone())
                .or_insert(("null".into(), "null".into()));
            if accessor.setter {
                slot.1 = callable;
            } else {
                slot.0 = callable;
            }
        }
        let [instance_accessors, static_accessors] = accessor_entries.map(|entries| {
            entries
                .iter()
                .map(|(name, (get, set))| format!("{} => [{get}, {set}]", php_str(name)))
                .collect::<Vec<_>>()
                .join(", ")
        });
        let mut own_methods = Vec::new();
        let mut static_methods = Vec::new();
        let mut private_methods = Vec::new();
        let mut static_private_methods = Vec::new();
        for (member, is_static, f) in &c.methods {
            let (helper, signature) = self.classes[&id].methods[&(*is_static, member.clone())].clone();
            let Ty::Fn(params, _) = signature else { unreachable!() };
            let receiver = if javascript {
                Ty::Dynamic
            } else if *is_static {
                Ty::Class(id)
            } else {
                instance.clone()
            };
            let closure = Rc::new(Closure::new(f.clone(), env.clone()));
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
            match (*is_static, member.starts_with('#')) {
                (true, false) => static_methods.push(entry),
                (false, false) => own_methods.push(entry),
                (true, true) => static_private_methods.push(entry),
                (false, true) => private_methods.push(entry),
            }
        }
        validate_captured_data(&static_data, c.span, &mut HashSet::new())?;
        let data = self.captured_data(&static_data)?;
        let private_statics = match &private_static_data {
            None => "null".to_string(),
            Some(value) => {
                validate_captured_data(value, c.span, &mut HashSet::new())?;
                self.captured_data(value)?
            }
        };
        let parent_php = match (parent, class.builtin_base) {
            (Some(id), _) => format!("\\ZeroApp\\{}()", self.classes[&id].name),
            (None, Some(builtin)) => format!("\\Zero\\JsClass::builtin({})", php_str(builtin)),
            (None, None) => "null".into(),
        };
        writeln!(self.output, "function {name}(): \\Zero\\JsClass\n{{\n    static $class;\n    return $class ??= new \\Zero\\JsClass({parent_php}, {ctor}, \\Closure::fromCallable({}), [{}], [{}], {data}, [{}], [{}], {private_statics}, [{instance_accessors}], [{static_accessors}]);\n}}\n", php_str(&format!("\\ZeroApp\\{fields_name}")), own_methods.join(", "), static_methods.join(", "), private_methods.join(", "), static_private_methods.join(", ")).unwrap();
        Ok(id)
    }

    pub(super) fn construct_class(&mut self, class: &Rc<ClassValue>, args: &[ArrEl], span: Span) -> Result<Out> {
        let id = self.ensure_class(class)?;
        let info = self.classes[&id].clone();
        if info.javascript {
            self.class_access_guard(span)?;
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!("\\ZeroApp\\{}()->construct(...({argv})->a)", info.name),
                Ty::Dynamic,
            ));
        }
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
        if let Some(accessor) = info.accessors.get(&(is_static, prop.to_string())) {
            if !accessor.getter {
                return err(
                    span,
                    format!("`{prop}` has only a setter; reading it returns undefined"),
                );
            }
            return Ok(Out::atom(
                if is_static {
                    format!("{}->get({})", receiver.wrapped(), php_str(prop))
                } else {
                    format!("\\Zero\\Js::dynamicGet({}, {})", receiver.php, php_str(prop))
                },
                accessor.ty.clone(),
            ));
        }
        if !is_static && let Some(ty) = self.error_member(&receiver.ty, prop) {
            return Ok(Out::atom(
                format!("\\Zero\\Js::dynamicGet({}, {})", receiver.php, php_str(prop)),
                ty,
            ));
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
        if name == ":private_in" {
            let [ArrEl::Item(key), ArrEl::Item(value)] = args else {
                unreachable!("lowered private brand check")
            };
            let ExprKind::Str(key) = &key.kind else {
                unreachable!("lowered private name")
            };
            let (class, _) = self.private_member(key, span)?;
            let value = self.private_receiver(value)?;
            return Ok(Some(Out::atom(
                format!("{class}->privateIn({}, {})", value.php, php_str(key)),
                Ty::Bool,
            )));
        }
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
                format!(
                    "\\ZeroApp\\{}({}, \\ZeroApp\\{}())",
                    self.classes[&id].fields, receiver.php, self.classes[&id].name
                ),
                Ty::Null,
            )));
        }
        if let Some(builtin) = class.builtin_base {
            let argv = self.javascript_args(args)?;
            return Ok(Some(Out::atom(
                format!(
                    "\\Zero\\JsClass::builtin({})->initialize({}, ({argv})->a)",
                    php_str(builtin),
                    receiver.php
                ),
                Ty::Null,
            )));
        }
        let Some(base) = &class.base else {
            return err(span, "super requires a base class");
        };
        let base_id = self.ensure_class(base)?;
        let info = self.classes[&base_id].clone();
        if self.classes[&id].javascript {
            let argv = self.javascript_args(args)?;
            return Ok(Some(Out::atom(
                format!("\\ZeroApp\\{}()->initialize({}, ({argv})->a)", info.name, receiver.php),
                Ty::Null,
            )));
        }
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
        let is_static = matches!(recv.ty, Ty::Class(_))
            || recv.ty == Ty::Dynamic
                && class
                    .class
                    .methods
                    .iter()
                    .any(|(_, is_static, f)| *is_static && f.span == self.fx().function);
        if self.classes[&id].javascript {
            let argv = self.javascript_args(args)?;
            return Ok(Out::atom(
                format!(
                    "\\ZeroApp\\{}()->invokeOn({}, {}, ({argv})->a, {})",
                    self.classes[&id].name,
                    recv.php,
                    php_str(member),
                    is_static
                ),
                Ty::Dynamic,
            ));
        }
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

    /// The innermost lexical class declaring `#name`, as its descriptor expression and value type.
    pub(super) fn private_member(&mut self, name: &str, span: Span) -> Result<(String, Ty)> {
        self.private_owner(name, span).map(|(class, ty, _)| (class, ty))
    }

    fn private_owner(&mut self, name: &str, span: Span) -> Result<(String, Ty, usize)> {
        let mut scope = Some(self.fx().env.clone());
        while let Some(current) = scope {
            if let Some(Value::Class(class)) = current.vars.borrow().get(":class").map(|b| b.value.clone()) {
                let declares = class.class.fields.iter().any(|f| f.name == name)
                    || class.class.methods.iter().any(|(m, _, _)| m == name);
                if declares {
                    let id = self.ensure_class(&class)?;
                    let info = &self.classes[&id];
                    let ty = [false, true]
                        .iter()
                        .find_map(|s| info.privates.get(&(*s, name.to_string())).cloned())
                        .unwrap_or(Ty::Dynamic);
                    return Ok((format!("\\ZeroApp\\{}()", info.name), ty, id));
                }
            }
            scope = current.parent.clone();
        }
        err(
            span,
            format!("private name `{name}` must be declared in an enclosing class"),
        )
    }

    pub(super) fn private_read(&mut self, obj: &Expr, name: &str, optional: bool, span: Span) -> Result<Out> {
        let (class, ty) = self.private_member(name, span)?;
        let receiver = self.private_receiver(obj)?;
        let is_method = self.private_is_method(name);
        let read = |r: &str| {
            if is_method {
                format!(
                    "(static fn (...$a) => {class}->privateCall({r}, {}, $a))",
                    php_str(name)
                )
            } else {
                format!("{class}->privateGet({r}, {})", php_str(name))
            }
        };
        if optional {
            let tmp = self.tmp_var();
            return Ok(Out::atom(
                format!(
                    "(\\Zero\\Js::isNullish({tmp} = {}) ? \\Zero\\Js::undefined() : {})",
                    receiver.php,
                    read(&tmp)
                ),
                Ty::union([ty, Ty::Undefined]),
            ));
        }
        Ok(Out::atom(read(&receiver.php), if is_method { Ty::Dynamic } else { ty }))
    }

    /// Class values are valid private receivers (static private members).
    pub(super) fn private_receiver(&mut self, obj: &Expr) -> Result<Out> {
        let receiver = self.expr(obj)?;
        if matches!(receiver.ty, Ty::Class(_)) {
            return Ok(receiver);
        }
        self.finish_value(receiver, obj.span)
    }

    fn private_is_method(&self, name: &str) -> bool {
        let mut scope = self.fx.as_ref().map(|f| f.env.clone());
        while let Some(current) = scope {
            if let Some(Value::Class(class)) = current.vars.borrow().get(":class").map(|b| b.value.clone()) {
                if class.class.fields.iter().any(|f| f.name == name) {
                    return false;
                }
                if class.class.methods.iter().any(|(m, _, _)| m == name) {
                    return true;
                }
            }
            scope = current.parent.clone();
        }
        false
    }

    pub(super) fn private_call(
        &mut self,
        obj: &Expr,
        name: &str,
        optional: bool,
        span: Span,
        args: &[ArrEl],
        whole: Span,
    ) -> Result<Out> {
        let (class, _, id) = self.private_owner(name, span)?;
        let javascript = self.classes[&id].javascript;
        if self.constructing_class() && !javascript {
            return err(
                whole,
                "class method calls during construction are unsupported; call methods after new returns",
            );
        }
        let receiver = self.private_receiver(obj)?;
        let is_static = matches!(receiver.ty.non_null(), Ty::Class(_));
        let typed = (!javascript)
            .then(|| self.classes[&id].methods.get(&(is_static, name.to_string())).cloned())
            .flatten();
        let (argv, ret) = match &typed {
            Some((_, signature)) => {
                let argv = self.checked_class_args(signature, args, whole)?;
                self.class_access_guard(whole)?;
                let Ty::Fn(_, ret) = signature else { unreachable!() };
                (format!("[{}]", argv.join(", ")), (**ret).clone())
            }
            None => (format!("({})->a", self.javascript_args(args)?), Ty::Dynamic),
        };
        let call = |r: &str| format!("{class}->privateCall({r}, {}, {argv})", php_str(name));
        if optional {
            let tmp = self.tmp_var();
            return Ok(Out::atom(
                format!(
                    "(\\Zero\\Js::isNullish({tmp} = {}) ? \\Zero\\Js::undefined() : {})",
                    receiver.php,
                    call(&tmp)
                ),
                Ty::union([ret, Ty::Undefined]),
            ));
        }
        Ok(Out::atom(call(&receiver.php), ret))
    }

    /// `super.name` reads: the parent's accessor runs with the current receiver; methods become values.
    pub(super) fn super_member(&mut self, member: &str, span: Span) -> Result<Out> {
        let Some(Value::Class(class)) = self.fx().env.lookup(":class").map(|b| b.value) else {
            return err(span, "super requires a class method");
        };
        let Some(base) = &class.base else {
            return err(span, "super requires a base class");
        };
        let id = self.ensure_class(base)?;
        let recv = self.ident("this", span)?;
        let is_static = matches!(recv.ty, Ty::Class(_))
            || class
                .class
                .methods
                .iter()
                .map(|(_, s, f)| (*s, f.span))
                .chain(class.class.accessors.iter().map(|a| (a.is_static, a.func.span)))
                .any(|(s, f)| s && f == self.fx().function);
        let info = self.classes[&id].clone();
        let ty = match info.accessors.get(&(is_static, member.to_string())) {
            Some(accessor) if accessor.getter => accessor.ty.clone(),
            Some(_) => Ty::Undefined,
            None if info.javascript => Ty::Dynamic,
            None => {
                return err_help(
                    span,
                    "super property reads are limited to getters",
                    "call super.method(...) directly",
                );
            }
        };
        let found = format!(
            "{}()->readInherited({}, {}, {is_static})",
            info.name,
            recv.php,
            php_str(member)
        );
        let tmp = self.tmp_var();
        Ok(Out::atom(
            format!("(({tmp} = \\ZeroApp\\{found}) === \\Zero\\JsClass::absent() ? \\Zero\\Js::undefined() : {tmp})"),
            ty,
        ))
    }

    /// Error members inherited from the built-in root: read and written through JavaScript lookup.
    pub(super) fn error_member(&self, ty: &Ty, prop: &str) -> Option<Ty> {
        if !self.error_instance(ty) || ty.non_null().field(prop).is_some() {
            return None;
        }
        match prop {
            "name" | "message" | "stack" => Some(Ty::Str),
            "cause" => Some(Ty::Unknown),
            _ => None,
        }
    }

    /// Instances of classes whose chain reaches `extends Error` (or TypeError/RangeError).
    pub(super) fn error_instance(&self, ty: &Ty) -> bool {
        let Ty::Instance(identity, _) = ty.non_null() else {
            return false;
        };
        std::iter::once(identity.id)
            .chain(identity.ancestors.iter().copied())
            .any(|id| self.classes[&id]._value.builtin_base.is_some())
    }

    /// An accessor write target: `Some(value type)` for a setter, an error for a getter-only accessor.
    pub(super) fn class_accessor_write(&self, receiver: &Ty, prop: &str, span: Span) -> Result<Option<Ty>> {
        if let Some(ty) = self.error_member(receiver, prop) {
            return Ok(Some(ty));
        }
        let (id, is_static) = match receiver.non_null() {
            Ty::Instance(identity, _) => (identity.id, false),
            Ty::Class(id) => (id, true),
            _ => return Ok(None),
        };
        let Some(accessor) = self.classes[&id].accessors.get(&(is_static, prop.to_string())) else {
            return Ok(None);
        };
        if !accessor.setter {
            return err(
                span,
                format!("`{prop}` is a getter without a setter; assigning it throws TypeError"),
            );
        }
        Ok(Some(accessor.ty.clone()))
    }

    pub(super) fn constructing_class(&self) -> bool {
        self.fx
            .as_ref()
            .is_some_and(|f| f.env.lookup(":constructing").is_some())
    }
}

impl Compiler<'_, '_> {
    /// Generated clients describe instances by their own fields; toJSON or getters would change that shape.
    pub(super) fn check_class_wire_type(&self, ty: &Ty, span: Span) -> Result<()> {
        fn custom(classes: &HashMap<usize, CompiledClass>, ty: &Ty) -> bool {
            match ty {
                Ty::Instance(identity, fields) => {
                    classes[&identity.id].methods.contains_key(&(false, "toJSON".into())) || custom(classes, fields)
                }
                Ty::Obj(fields) => fields.values().any(|(t, _)| custom(classes, t)),
                Ty::Array(t) | Ty::NonEmptyArray(t) | Ty::Map(t) => custom(classes, t),
                Ty::Tuple(items) | Ty::ArrayLiteral(items) | Ty::Union(items) => {
                    items.iter().any(|t| custom(classes, t))
                }
                _ => false,
            }
        }
        if custom(&self.classes, ty) {
            return err_help(
                span,
                "actions can't return class instances with a toJSON method; their JSON differs from their fields",
                "return the toJSON() result, or build the response object explicitly",
            );
        }
        Ok(())
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
