//! The input of a `query()`, `mutation()`, `job(options, handler)` or `cron()` is its handler's first
//! parameter type: `async ({ id, limit = 10 }: { id: number; limit?: number }) => ...`. This module
//! reads that type before anything uses the input, and turns it into the input fields the rest of
//! TypedPress already knows (the runtime's validation, the client, the Ability and OpenAPI schemas,
//! admin screens), so a typed input is validated exactly as the old `input: { ... }` columns were.

use super::*;
use crate::extract::Slot;

/// A handler's inferred input: the slot it belongs to, its fields, and its parameter type.
pub type Inferred = (Slot, IndexMap<String, Column>, Ty);

/// A field of an inferred input, in the order the type lists them.
struct Field {
    name: String,
    ty: Ty,
    /// `limit?: number`: the caller may leave it out, and the handler's destructuring default
    /// (`{ limit = 10 }`) applies.
    optional: bool,
}

impl Compiler<'_, '_> {
    /// The input fields and parameter type of every action and job whose input is its handler's
    /// parameter type, by slot. Runs before roots compile and before admin screens read inputs;
    /// [`apply_inputs`] stores them in the schema.
    pub fn infer_inputs(&mut self, roots: &[Root]) -> Result<Vec<Inferred>> {
        let mut out = Vec::new();
        for root in roots {
            let (what, typed, scheduled) = match &root.slot {
                Slot::ActionHandler { action } => ("query or mutation", self.schema.actions[action].typed_input, false),
                Slot::JobHandler { job } => {
                    let j = &self.schema.jobs[job];
                    ("job", j.typed_input, j.schedule.is_some())
                }
                _ => continue,
            };
            if !typed {
                continue;
            }
            let (input, ty) = self.inferred_input(&root.closure.func, what)?;
            if scheduled && input.values().any(|c| !c.optional_on_insert()) {
                return err_help(
                    root.closure.func.span,
                    "a job on a schedule runs with no input, so its handler can't require one",
                    "drop the parameter, or make every field optional with a default: `async ({ days = 7 }: { days?: number }) => ...`",
                );
            }
            out.push((root.slot.clone(), input, ty));
        }
        Ok(out)
    }

    /// The input fields of one handler, and the type its first parameter has inside the handler.
    fn inferred_input(&mut self, f: &Func, what: &str) -> Result<(IndexMap<String, Column>, Ty)> {
        // A function that reads `this` takes it as a hidden first parameter, never an input.
        let Some(p) = f.params.get(usize::from(receives_this(f))) else {
            return Ok((IndexMap::new(), Ty::Obj(IndexMap::new())));
        };
        let help = "type it: `async ({ id, limit = 10 }: { id: number; limit?: number }) => ...`";
        if p.rest {
            return err_help(
                p.span,
                format!("a {what} takes one input object, not rest parameters"),
                help,
            );
        }
        // Installed package TypeScript is type-erased; its declared parameter type still names the input.
        let Some(ann) = p.ann.as_ref().or(p.declared.as_ref()) else {
            return err_help(
                p.span,
                format!("the input of a {what} is its parameter's type, and this parameter has none"),
                help,
            );
        };
        let ty = self.resolve_runtime_ann(ann, f.module)?;
        let Ty::Obj(props) = &ty else {
            return err_help(
                p.span,
                format!("the input of a {what} is an object type, not {}", ty.describe()),
                help,
            );
        };
        let mut fields = Vec::new();
        for (name, (t, optional)) in props {
            if !valid_input_name(name) {
                return err_help(
                    p.span,
                    format!("`{name}` can't be an input field name"),
                    "use a plain identifier (letters, digits and underscores)",
                );
            }
            // An optional property's undefined is presence, not a value the caller sends.
            let t = if *optional { strip_undefined(t) } else { t.clone() };
            fields.push(Field {
                name: name.clone(),
                ty: t,
                optional: *optional,
            });
        }
        let mut input = IndexMap::new();
        for field in fields {
            let col = self.input_column(&field, p.span)?;
            input.insert(field.name, col);
        }
        Ok((input, ty))
    }

    /// One field: a column the runtime validates, typed as the parameter type says.
    fn input_column(&mut self, field: &Field, span: Span) -> Result<Column> {
        let nullable = field.ty.is_nullable() && field.ty != Ty::Unknown;
        let base = field.ty.non_null();
        let mut col = match &base {
            Ty::Num => Column::new(&field.name, ColType::Real),
            Ty::Str => Column::new(&field.name, ColType::Text),
            Ty::Bool => Column::new(&field.name, ColType::Boolean),
            t if string_literals(t).is_some() => {
                let mut c = Column::new(&field.name, ColType::Text);
                c.enum_values = string_literals(t);
                c
            }
            Ty::Date | Ty::BigInt | Ty::Fn(..) | Ty::MapOf(..) | Ty::SetOf(_) | Ty::Url | Ty::UrlParams => {
                return err_help(
                    span,
                    format!(
                        "input field `{}` is {}, which JSON can't carry",
                        field.name,
                        base.describe()
                    ),
                    "use string, number, boolean, or arrays and objects of those; a date is an ISO string",
                );
            }
            Ty::Instance(..) | Ty::Class(_) => {
                return err_help(
                    span,
                    format!(
                        "input field `{}` is a class instance, which JSON can't carry",
                        field.name
                    ),
                    "describe the fields it receives with an object type",
                );
            }
            _ => {
                // As for `json<T>()`: what JSON brings back must still be a T.
                if !assignable(&types::json_wire_type(&base), &base) {
                    return err_help(
                        span,
                        format!(
                            "input field `{}` is {}, which JSON can't carry exactly",
                            field.name,
                            base.describe()
                        ),
                        "use string, number, boolean, null, or arrays and objects of those",
                    );
                }
                // `unknown` is any JSON value; every other type is checked against its JSON Schema.
                let shape = if base == Ty::Unknown {
                    None
                } else {
                    let Some(shape) = crate::wordpress::abilities::input_schema(&base) else {
                        return err_help(
                            span,
                            format!(
                                "input field `{}` is {}, which has no JSON shape the site can check",
                                field.name,
                                base.describe()
                            ),
                            "use string, number, boolean, null, arrays, objects, a union of scalars, or `unknown` for any JSON; for a union of objects, take one object type with a `kind` field",
                        );
                    };
                    Some(shape)
                };
                let mut c = Column::new(&field.name, ColType::Json);
                c.inferred = Some(base.clone());
                c.shape = shape;
                c
            }
        };
        // Null only where the type says so; left out only where the property is optional. A field
        // the caller leaves out stays out, so the handler reads `undefined` and its destructuring
        // default applies.
        col.not_null = !nullable;
        col.omit = field.optional;
        // `name: string | null` must be sent, as null or a string; only `name?:` may be left out.
        col.required = nullable && !field.optional;
        Ok(col)
    }
}

/// `string` literals of a union like `"asc" | "desc"`, or None.
fn string_literals(t: &Ty) -> Option<Vec<String>> {
    let items: Vec<&Ty> = match t {
        Ty::Union(items) => items.iter().collect(),
        other => vec![other],
    };
    items
        .into_iter()
        .map(|t| match t {
            Ty::StrLit(s) | Ty::FreshStrLit(s) => Some(s.clone()),
            _ => None,
        })
        .collect()
}

fn strip_undefined(t: &Ty) -> Ty {
    match t {
        Ty::Union(items) => Ty::union(items.iter().filter(|t| **t != Ty::Undefined).cloned()),
        other => other.clone(),
    }
}

fn valid_input_name(s: &str) -> bool {
    let mut chars = s.chars();
    chars.next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
        && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
}

/// Stores what [`Compiler::infer_inputs`] found.
pub fn apply_inputs(schema: &mut Schema, inputs: Vec<Inferred>) {
    for (slot, input, ty) in inputs {
        match slot {
            Slot::ActionHandler { action } => {
                let a = schema.actions.get_mut(&action).expect("action");
                a.input = input;
                a.input_ty = Some(ty);
            }
            Slot::JobHandler { job } => {
                let j = schema.jobs.get_mut(&job).expect("job");
                j.input = input;
                j.input_ty = Some(ty);
            }
            _ => unreachable!("only handlers have inferred inputs"),
        }
    }
}
