//! Read declarations without loading a CMS package or executing JavaScript.
use crate::diag;
use crate::ir::{ArrEl, DeclKind, Expr, ExprKind, FnBody, ImportName, Pat, Prop, PropKey, Stmt, UnOp};
use crate::lower::Lowerer;
use oxc_allocator::Allocator;
use oxc_parser::Parser;
use oxc_span::SourceType;
use serde::de::{self, Deserialize, Deserializer, MapAccess, SeqAccess, Visitor};
use serde_json::{Map, Value};
use std::collections::HashMap;
use std::path::Path;

use super::{Failure, Source, fail, pointer};

pub(super) fn parse(path: &Path, text: &str, source: Source) -> Result<Value, Failure> {
    if path.extension().is_some_and(|ext| ext == "json") {
        return serde_json::from_str::<UniqueJson>(text)
            .map(|v| v.0)
            .map_err(|e| fail("", format!("invalid JSON: {e}")));
    }
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, text, SourceType::ts()).parse();
    if let Some(e) = parsed.diagnostics.errors().next() {
        return Err(fail("", format!("invalid TypeScript: {}", e.message)));
    }
    let file = diag::register_file(path, text);
    let body = Lowerer {
        file,
        module: 0,
        class_context: std::cell::Cell::new(false),
        super_call: std::cell::Cell::new(false),
        erase_types: false,
        erased_types: Default::default(),
    }
    .program(&parsed.program)
    .map_err(|d| fail("", diag::render(&d, path.parent().unwrap_or(Path::new(".")))))?;
    let mut reader = Reader {
        source,
        values: HashMap::new(),
        wrappers: HashMap::new(),
    };
    let mut result = None;
    for stmt in &body {
        let stmt = match stmt {
            Stmt::Exported(inner) => inner.as_ref(),
            other => other,
        };
        match stmt {
            Stmt::Import {
                source: package,
                specs,
                type_only,
                ..
            } => {
                if *type_only {
                    continue;
                }
                if specs.is_empty() {
                    return Err(fail("", "side-effect imports cannot be migrated"));
                }
                for spec in specs {
                    if spec.type_only {
                        continue;
                    }
                    let ImportName::Named(name) = &spec.imported else {
                        return Err(fail(
                            "",
                            "only named imports of declarative CMS helpers are supported; use a JSON schema export for other module layouts",
                        ));
                    };
                    let allowed = match source {
                        Source::Payload => package == "payload" && name == "buildConfig",
                        Source::Sanity => {
                            package == "sanity"
                                && matches!(
                                    name.as_str(),
                                    "defineConfig" | "defineType" | "defineField" | "defineArrayMember"
                                )
                        }
                        Source::Zero => package == "@spacefast/zero/server" && name == "capsule",
                        Source::Emdash => false,
                    };
                    if !allowed {
                        return Err(fail(
                            "",
                            format!(
                                "import {name} from {package:?} needs a manual port or declarative JSON export; source modules are never executed"
                            ),
                        ));
                    }
                    if reader.wrappers.insert(spec.local.clone(), name.clone()).is_some() {
                        return Err(fail("", "duplicate import binding"));
                    }
                }
            }
            Stmt::Decl {
                kind: DeclKind::Const,
                pat: Pat::Id(name, _),
                init: Some(expr),
                ..
            } => {
                // A declaration can be referenced at several export paths. Keep
                // failures relative to its value until the use supplies a path.
                let value = reader.value(expr, "", 0);
                if reader.wrappers.contains_key(name) || reader.values.insert(name.clone(), value).is_some() {
                    return Err(fail("", format!("duplicate binding {name}")));
                }
            }
            Stmt::ExportDefault(expr) if result.is_none() => result = Some(reader.value(expr, "", 0)?),
            Stmt::TypeAlias { .. } => {}
            _ => {
                return Err(fail(
                    "",
                    "only imports, const declarations and one default export are supported; executable statements need a manual port",
                ));
            }
        }
    }
    let result = result.ok_or_else(|| fail("", "expected a default export (or supply a JSON schema export)"))?;
    // Unused declarations must still satisfy the declarative-only boundary.
    // They have no path in the export, so point at its root and name the binding.
    for (name, value) in &reader.values {
        if let Err(e) = value {
            return Err(fail("", format!("in const {name} at {:?}: {}", e.pointer, e.reason)));
        }
    }
    Ok(result)
}

// JSON exports must not silently overwrite declarations with duplicate keys.
struct UniqueJson(Value);
impl<'de> Deserialize<'de> for UniqueJson {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct UniqueVisitor;
        impl<'de> Visitor<'de> for UniqueVisitor {
            type Value = UniqueJson;
            fn expecting(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
                f.write_str("JSON with unique object keys")
            }
            fn visit_unit<E: de::Error>(self) -> Result<UniqueJson, E> {
                Ok(UniqueJson(Value::Null))
            }
            fn visit_bool<E: de::Error>(self, v: bool) -> Result<UniqueJson, E> {
                Ok(UniqueJson(Value::Bool(v)))
            }
            fn visit_i64<E: de::Error>(self, v: i64) -> Result<UniqueJson, E> {
                Ok(UniqueJson(v.into()))
            }
            fn visit_u64<E: de::Error>(self, v: u64) -> Result<UniqueJson, E> {
                Ok(UniqueJson(v.into()))
            }
            fn visit_f64<E: de::Error>(self, v: f64) -> Result<UniqueJson, E> {
                serde_json::Number::from_f64(v)
                    .map(|n| UniqueJson(n.into()))
                    .ok_or_else(|| E::custom("number must be finite"))
            }
            fn visit_str<E: de::Error>(self, v: &str) -> Result<UniqueJson, E> {
                Ok(UniqueJson(v.into()))
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut seq: A) -> Result<UniqueJson, A::Error> {
                let mut out = Vec::new();
                while let Some(UniqueJson(v)) = seq.next_element()? {
                    out.push(v);
                }
                Ok(UniqueJson(Value::Array(out)))
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<UniqueJson, A::Error> {
                let mut out = Map::new();
                while let Some((key, UniqueJson(value))) = map.next_entry::<String, UniqueJson>()? {
                    if out.insert(key.clone(), value).is_some() {
                        return Err(de::Error::custom(format!("duplicate key {key:?}")));
                    }
                }
                Ok(UniqueJson(Value::Object(out)))
            }
        }
        deserializer.deserialize_any(UniqueVisitor)
    }
}

struct Reader {
    source: Source,
    values: HashMap<String, Result<Value, Failure>>,
    wrappers: HashMap<String, String>,
}

impl Reader {
    fn sanity_validation(&self, expr: &Expr, at: &str) -> Result<Value, Failure> {
        let ExprKind::Func(f) = &expr.kind else {
            return Err(fail(at, "only Rule => Rule.required() validation is supported"));
        };
        let [param] = f.params.as_slice() else {
            return Err(fail(at, "expected one Sanity Rule parameter"));
        };
        let Pat::Id(rule, _) = &param.pat else {
            return Err(fail(at, "expected a named Rule parameter"));
        };
        if param.default.is_some() || param.rest || f.is_async {
            return Err(fail(at, "unsupported Rule callback"));
        }
        let FnBody::Expr(body) = &f.body else {
            return Err(fail(at, "only Rule => Rule.required() validation is supported"));
        };
        let ExprKind::Call {
            callee,
            args,
            optional: false,
            ..
        } = &body.kind
        else {
            return Err(fail(
                at,
                "only required() is supported; port other validation explicitly",
            ));
        };
        let ExprKind::Member {
            obj,
            prop,
            optional: false,
            ..
        } = &callee.kind
        else {
            return Err(fail(at, "expected Rule.required()"));
        };
        if !matches!(&obj.kind, ExprKind::Ident(name) if name == rule) || prop != "required" || !args.is_empty() {
            return Err(fail(
                at,
                "only required() is supported; port other validation explicitly",
            ));
        }
        Ok(serde_json::json!({"required":true}))
    }
    fn value(&self, expr: &Expr, at: &str, depth: usize) -> Result<Value, Failure> {
        if depth > 64 {
            return Err(fail(at, "declaration nesting exceeds 64 levels"));
        }
        Ok(match &expr.kind {
            ExprKind::Str(s) => Value::String(s.clone()),
            ExprKind::Bool(b) => Value::Bool(*b),
            ExprKind::Num(n) => serde_json::Number::from_f64(*n)
                .map(Value::Number)
                .ok_or_else(|| fail(at, "number must be finite"))?,
            ExprKind::Null => Value::Null,
            ExprKind::Unary(UnOp::Neg, n) => {
                let n = self
                    .value(n, at, depth + 1)?
                    .as_f64()
                    .ok_or_else(|| fail(at, "expected numeric literal"))?;
                serde_json::json!(-n)
            }
            ExprKind::As(inner, _) | ExprKind::NonNull(inner) => self.value(inner, at, depth + 1)?,
            ExprKind::Ident(name) => match self.values.get(name) {
                Some(Ok(value)) => value.clone(),
                Some(Err(e)) => {
                    return Err(fail(
                        &format!("{at}{}", e.pointer),
                        format!("in const {name}: {}", e.reason),
                    ));
                }
                None => {
                    return Err(fail(
                        at,
                        format!(
                            "unresolved declaration {name}; imports and dynamic expressions require a declarative export"
                        ),
                    ));
                }
            },
            ExprKind::Array(items) => Value::Array(
                items
                    .iter()
                    .enumerate()
                    .map(|(i, item)| match item {
                        ArrEl::Item(e) => self.value(e, &pointer(at, &i.to_string()), depth + 1),
                        ArrEl::Spread(_) => Err(fail(at, "array spreads require a declarative export")),
                    })
                    .collect::<Result<_, _>>()?,
            ),
            ExprKind::Object(props) => {
                let mut map = Map::new();
                for prop in props {
                    let Prop::KV(PropKey::Static(key), value) = prop else {
                        return Err(fail(at, "computed keys and spreads require a declarative export"));
                    };
                    let path = pointer(at, key);
                    if map.contains_key(key) {
                        return Err(fail(&path, "duplicate key"));
                    }
                    let value = if matches!(self.source, Source::Sanity) && key == "validation" {
                        self.sanity_validation(value, &path)?
                    } else {
                        self.value(value, &path, depth + 1)?
                    };
                    map.insert(key.clone(), value);
                }
                Value::Object(map)
            }
            ExprKind::Call {
                callee,
                args,
                optional: false,
                ..
            } => {
                let ExprKind::Ident(name) = &callee.kind else {
                    return Err(fail(at, "method calls need a manual port"));
                };
                if !self.wrappers.contains_key(name) {
                    return Err(fail(
                        at,
                        format!("call to {name} is not a supported declarative CMS helper"),
                    ));
                }
                let [ArrEl::Item(arg)] = args.as_slice() else {
                    return Err(fail(at, "declarative CMS helpers require one argument"));
                };
                self.value(arg, at, depth + 1)?
            }
            ExprKind::Func(_) => {
                return Err(fail(
                    at,
                    "callback requires a manual PHP-compatible port; access rules, hooks and validators are never discarded",
                ));
            }
            _ => {
                return Err(fail(
                    at,
                    "dynamic expression requires a declarative export or manual port; no source code is executed",
                ));
            }
        })
    }
}
