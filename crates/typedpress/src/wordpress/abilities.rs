//! WordPress abilities from `"php/wordpress"`: the committed lock `tp/_generated/abilities.json`, its JSON
//! Schemas as compiler types and TypeScript, and the app's own abilities typed from the schema.
//!
//! The lock is written by `typedpress abilities pull` (src/commands/abilities.rs). Building reads only the
//! lock; nothing here touches the network.

use crate::schema::{ColType, Schema};
use crate::types::{self, Ty};
use indexmap::IndexMap;
use serde_json::Value;
use std::collections::{BTreeMap, BTreeSet};

/// The app's own abilities as data: name → (input properties, the required ones). For the drift
/// test against `Register::operations()`; inputs that are not objects list nothing.
pub fn own_inputs(schema: &Schema) -> BTreeMap<String, (Vec<String>, Vec<String>)> {
    own(schema)
        .abilities
        .into_iter()
        .map(|(name, (input, _))| {
            let (mut keys, mut required) = (Vec::new(), Vec::new());
            if let Ty::Obj(fields) = input {
                for (k, (_, optional)) in fields {
                    if !optional {
                        required.push(k.clone());
                    }
                    keys.push(k);
                }
            }
            keys.sort();
            required.sort();
            (name, (keys, required))
        })
        .collect()
}

/// Where the lock lives in a project.
pub const LOCK: &str = "tp/_generated/abilities.json";

/// One locked ability: what `GET /wp-abilities/v1/abilities` returned for it.
#[derive(Debug, Clone)]
pub struct Locked {
    pub name: String,
    pub input: Value,
    pub output: Value,
}

/// Every ability a call can name: the app's own and the lock's. Own abilities win a name clash: the
/// app registers them, so the lock's copy is a stale pull of the same thing.
#[derive(Debug, Default, Clone)]
pub struct Catalog {
    pub abilities: BTreeMap<String, (Ty, Ty)>,
    /// The app's own abilities a scoped credential runs only with its `personal` grant
    /// (`AgentScope::ability`): every ability of an `order()` kind, and `complete-order`.
    pub personal: BTreeSet<String>,
}

impl Catalog {
    pub fn new(schema: &Schema, locked: &[Locked]) -> Self {
        let mut abilities = BTreeMap::new();
        for a in locked {
            abilities.insert(
                a.name.clone(),
                (
                    schema_ty(&a.input, &mut Vec::new(), ""),
                    schema_ty(&a.output, &mut Vec::new(), ""),
                ),
            );
        }
        let own = own(schema);
        abilities.extend(own.abilities);
        Catalog {
            abilities,
            personal: own.personal,
        }
    }
}

/// Parses the lock. A malformed lock is an error, never an empty catalog: a call that typed
/// yesterday would otherwise fail as "unknown ability" with no hint that the file is the problem.
pub fn parse_lock(text: &str) -> Result<Vec<Locked>, String> {
    let lock: Value = serde_json::from_str(text).map_err(|e| format!("{LOCK} is not valid JSON: {e}"))?;
    if lock["version"] != 1 {
        return Err(format!(
            "{LOCK} has version {}; this compiler reads version 1",
            lock["version"]
        ));
    }
    let Some(items) = lock["abilities"].as_array() else {
        return Err(format!("{LOCK} has no `abilities` list"));
    };
    items
        .iter()
        .map(|a| {
            let name = a["name"]
                .as_str()
                .filter(|n| valid_name(n))
                .ok_or_else(|| format!("{LOCK} has an ability without a valid `name` (vendor/name)"))?;
            Ok(Locked {
                name: name.to_string(),
                input: a["input_schema"].clone(),
                output: a["output_schema"].clone(),
            })
        })
        .collect()
}

/// Core's rule for ability names: `namespace/name`, lowercase letters, digits and dashes
/// (`WP_Abilities_Registry::register`).
pub fn valid_name(name: &str) -> bool {
    let mut parts = name.split('/');
    let ok = |p: Option<&str>| {
        p.is_some_and(|p| {
            !p.is_empty()
                && p.chars()
                    .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-')
        })
    };
    ok(parts.next()) && ok(parts.next()) && parts.next().is_none()
}

/// A JSON Schema (draft-04, as WordPress publishes it) as a compiler type. Unrepresentable parts
/// become `unknown` and push `pointer: reason` onto `warnings`.
pub fn schema_ty(s: &Value, warnings: &mut Vec<String>, at: &str) -> Ty {
    let Some(o) = s.as_object() else {
        // `true`, `{}` or a missing schema accept anything.
        return Ty::Unknown;
    };
    if o.is_empty() {
        return Ty::Unknown;
    }
    for k in ["allOf", "$ref", "not", "patternProperties", "if"] {
        if o.contains_key(k) {
            warnings.push(format!("{}: `{k}` is not represented; typed as unknown", pointer(at)));
            return Ty::Unknown;
        }
    }
    if let Some(v) = o.get("const") {
        return literal(v).unwrap_or_else(|| {
            warnings.push(format!("{}: a non-scalar `const` is typed as unknown", pointer(at)));
            Ty::Unknown
        });
    }
    if let Some(values) = o.get("enum").and_then(Value::as_array) {
        let lits: Option<Vec<Ty>> = values.iter().map(literal).collect();
        return match lits {
            Some(lits) => Ty::union(lits),
            None => {
                warnings.push(format!("{}: a non-scalar `enum` is typed as unknown", pointer(at)));
                Ty::Unknown
            }
        };
    }
    for k in ["oneOf", "anyOf"] {
        if let Some(options) = o.get(k).and_then(Value::as_array) {
            return Ty::union(
                options
                    .iter()
                    .enumerate()
                    .map(|(i, option)| schema_ty(option, warnings, &format!("{at}/{k}/{i}"))),
            );
        }
    }
    let types: Vec<&str> = match o.get("type") {
        Some(Value::String(t)) => vec![t.as_str()],
        Some(Value::Array(ts)) => ts.iter().filter_map(Value::as_str).collect(),
        // Properties without a type still describe an object.
        None if o.contains_key("properties") => vec!["object"],
        None => return Ty::Unknown,
        Some(_) => {
            warnings.push(format!("{}: `type` is not a string or list", pointer(at)));
            return Ty::Unknown;
        }
    };
    Ty::union(types.into_iter().map(|t| match t {
        "string" => Ty::Str,
        "integer" | "number" => Ty::Num,
        "boolean" => Ty::Bool,
        "null" => Ty::Null,
        "array" => match o.get("items") {
            Some(items @ Value::Object(_)) => Ty::Array(Box::new(schema_ty(items, warnings, &format!("{at}/items")))),
            None => Ty::Array(Box::new(Ty::Unknown)),
            Some(_) => {
                warnings.push(format!("{}: tuple `items` is typed as unknown[]", pointer(at)));
                Ty::Array(Box::new(Ty::Unknown))
            }
        },
        "object" => object(o, warnings, at),
        other => {
            warnings.push(format!("{}: type `{other}` is typed as unknown", pointer(at)));
            Ty::Unknown
        }
    }))
}

/// An action result type as the JSON Schema its Ability publishes (`output_schema`), the inverse of
/// `schema_ty` for what results can be. `None` for a value with no JSON shape the schema could state
/// (`unknown`, functions, unions of objects): the Ability then publishes no output schema, so core
/// never refuses a result the type system allowed. Every node keeps the `type` core's validator needs.
pub fn ty_schema(t: &Ty) -> Option<Value> {
    use serde_json::json;
    const MAX_SAFE: f64 = 9_007_199_254_740_992.0; // 2^53
    let t = types::json_wire_type(t);
    Some(match &t {
        Ty::Instance(_, fields) => return ty_schema(fields),
        // A computed number may be NaN or ±Infinity, which the wire encodes as null.
        Ty::Num | Ty::FreshNumLit(_) => json!({ "type": ["number", "null"] }),
        // An integer literal is an `integer` only where the cast is exact (|n| <= 2^53); beyond that
        // the literal itself stays in the enum, as the number the wire carries.
        Ty::NumLit(n) if n.fract() == 0.0 && n.abs() <= MAX_SAFE => json!({ "type": "integer", "enum": [*n as i64] }),
        Ty::NumLit(n) => json!({ "type": "number", "enum": [n] }),
        Ty::Str => json!({ "type": "string" }),
        Ty::StrLit(v) | Ty::FreshStrLit(v) => json!({ "type": "string", "enum": [v] }),
        Ty::Bool | Ty::FreshBoolLit(_) => json!({ "type": "boolean" }),
        Ty::BoolLit(b) => json!({ "type": "boolean", "enum": [b] }),
        Ty::Null => json!({ "type": "null" }),
        Ty::Array(el) | Ty::NonEmptyArray(el) => json!({ "type": "array", "items": ty_schema(el)? }),
        Ty::Tuple(items) | Ty::ArrayLiteral(items) => {
            let items = items.iter().map(ty_schema).collect::<Option<Vec<_>>>()?;
            json!({ "type": "array", "items": items, "minItems": items.len(), "maxItems": items.len() })
        }
        Ty::Obj(fields) => {
            let mut props = serde_json::Map::new();
            let mut required = Vec::new();
            for (k, (ft, optional)) in fields {
                props.insert(k.clone(), ty_schema(ft)?);
                if !optional {
                    required.push(Value::String(k.clone()));
                }
            }
            let mut o = json!({ "type": "object" });
            if !props.is_empty() {
                o["properties"] = Value::Object(props);
            }
            if !required.is_empty() {
                o["required"] = Value::Array(required);
            }
            o
        }
        Ty::Map(v) => json!({ "type": "object", "additionalProperties": ty_schema(v)? }),
        Ty::Union(items) => {
            // Scalars (and null) merge into one `type` list; anything structured has no single shape.
            let mut kinds: Vec<Value> = Vec::new();
            let mut values: Vec<Value> = Vec::new();
            let mut literal_only = true;
            for item in items {
                let s = ty_schema(item)?;
                let item_kinds: Vec<String> = match s.get("type")? {
                    Value::String(k) => vec![k.clone()],
                    Value::Array(ks) => ks
                        .iter()
                        .map(|k| k.as_str().map(str::to_string))
                        .collect::<Option<_>>()?,
                    _ => return None,
                };
                if item_kinds.iter().any(|k| matches!(k.as_str(), "object" | "array")) {
                    return None;
                }
                match s.get("enum") {
                    Some(Value::Array(e)) => values.extend(e.iter().cloned()),
                    _ if item_kinds == ["null"] => values.push(Value::Null),
                    _ => literal_only = false,
                }
                for kind in item_kinds {
                    if !kinds.contains(&Value::String(kind.clone())) {
                        kinds.push(Value::String(kind));
                    }
                }
            }
            // `integer` is a subset of `number`: listed beside it, it adds nothing.
            if kinds.contains(&Value::String("number".into())) {
                kinds.retain(|k| k != "integer");
            }
            let mut o = json!({ "type": kinds });
            if literal_only {
                o["enum"] = Value::Array(values);
            }
            o
        }
        _ => return None,
    })
}

fn object(o: &serde_json::Map<String, Value>, warnings: &mut Vec<String>, at: &str) -> Ty {
    let props = o.get("properties").and_then(Value::as_object);
    if props.is_none_or(|p| p.is_empty()) {
        // No listed properties: a record of whatever additionalProperties allows.
        return Ty::Map(Box::new(match o.get("additionalProperties") {
            Some(extra @ Value::Object(_)) => schema_ty(extra, warnings, &format!("{at}/additionalProperties")),
            _ => Ty::Unknown,
        }));
    }
    let mut required: Vec<&str> = o
        .get("required")
        .and_then(Value::as_array)
        .map(|r| r.iter().filter_map(Value::as_str).collect())
        .unwrap_or_default();
    let props = props.expect("properties");
    // Draft-03 `required: true` on a property, which core still accepts.
    required.extend(
        props
            .iter()
            .filter(|(_, p)| p["required"] == Value::Bool(true))
            .map(|(k, _)| k.as_str()),
    );
    Ty::Obj(
        props
            .iter()
            .map(|(k, p)| {
                let t = schema_ty(p, warnings, &format!("{at}/properties/{}", escape(k)));
                (k.clone(), (t, !required.contains(&k.as_str())))
            })
            .collect::<IndexMap<_, _>>(),
    )
}

fn literal(v: &Value) -> Option<Ty> {
    match v {
        Value::String(s) => Some(Ty::StrLit(s.clone())),
        Value::Number(n) => n.as_f64().map(Ty::NumLit),
        Value::Bool(b) => Some(Ty::BoolLit(*b)),
        Value::Null => Some(Ty::Null),
        _ => None,
    }
}

fn pointer(at: &str) -> String {
    if at.is_empty() { "/".into() } else { at.into() }
}

fn escape(key: &str) -> String {
    key.replace('~', "~0").replace('/', "~1")
}

/// The app's own abilities, typed from the schema: one entry per operation
/// `runtime/TypedPress/Wp/Abilities/Register::build` registers, with the same names and input properties
/// (`wordpress_own_abilities_match_the_registered_ones` in tests/compiler_contracts.rs runs both and
/// compares them). Results the runtime publishes no output schema for are `unknown`.
fn own(schema: &Schema) -> Own {
    let app = kebab(&schema.name);
    let mut out: Vec<(String, (Ty, Ty))> = Vec::new();
    let mut personal = BTreeSet::new();
    let opt = |fields: &[(&str, Ty, bool)]| {
        Ty::Obj(
            fields
                .iter()
                .map(|(k, t, o)| (k.to_string(), (t.clone(), *o)))
                .collect(),
        )
    };
    let paging = |extra: &[(&str, Ty, bool)]| {
        let mut fields = vec![
            ("where", Ty::Unknown, true),
            ("orderBy", Ty::Unknown, true),
            ("limit", Ty::Num, true),
            ("offset", Ty::Num, true),
        ];
        fields.extend(extra.iter().cloned());
        opt(&fields)
    };
    // Taxonomies have no abilities yet (docs/decisions/2026-11-11-authoring-surface.md, deferrals).
    for c in schema.collections.values().filter(|c| c.taxonomy.is_none()) {
        let first = out.len();
        // As Register::collection: `with` when there is something to load, `locale` when localized.
        let mut extra = Vec::new();
        if !c.relations.is_empty()
            || c.columns
                .values()
                .any(|col| matches!(col.ty, ColType::Media | ColType::MediaList))
        {
            extra.push(("with", Ty::Unknown, true));
        }
        if c.localized {
            extra.push(("locale", Ty::Str, true));
        }
        let k = kebab(&c.table);
        let row = types::row_of(c);
        let pk = c
            .primary_key()
            .map(|p| types::col_base(p, &types::json_ty, false))
            .unwrap_or(Ty::Num);
        let rows = opt(&[("rows", Ty::Array(Box::new(row.clone())), false)]);
        let id = || opt(&[("id", pk.clone(), false)]);
        if c.api.list {
            out.push((format!("{app}/{k}-list"), (paging(&extra), rows)));
        }
        if c.api.get {
            let mut get = vec![("id", pk.clone(), false)];
            get.extend(extra.iter().cloned());
            out.push((format!("{app}/{k}-get"), (opt(&get), row.clone())));
        }
        if c.api.create {
            out.push((format!("{app}/{k}-create"), (create_input(schema, c), row.clone())));
        }
        // As Register::collection: a kind with staged versions takes `_rev` and `takeover` on its
        // update and delete (docs/staged.md).
        let staged = c.editor && c.revisions && c.staged;
        let conditions = |mut fields: Vec<(&'static str, Ty, bool)>| {
            if staged {
                fields.extend([("_rev", Ty::Str, true), ("takeover", Ty::Bool, true)]);
            }
            opt(&fields)
        };
        if c.api.update {
            let patch = types::partial(types::insert_with(schema, &c.table, &types::json_ty));
            out.push((
                format!("{app}/{k}-update"),
                (
                    conditions(vec![("id", pk.clone(), false), ("patch", patch, false)]),
                    row.clone(),
                ),
            ));
        }
        if c.api.delete {
            out.push((
                format!("{app}/{k}-delete"),
                (conditions(vec![("id", pk.clone(), false)]), row.clone()),
            ));
        }
        if c.revisions {
            let user = opt(&[("id", Ty::Num, false), ("name", Ty::Str, false)]).nullable();
            let revision = opt(&[("id", Ty::Num, false), ("at", Ty::Str, false), ("user", user, false)]);
            out.push((
                format!("{app}/{k}-revisions"),
                (id(), opt(&[("revisions", Ty::Array(Box::new(revision)), false)])),
            ));
        }
        // As Register::staged: one ability per step of a post kind's staged version.
        if c.editor && c.revisions && c.staged && c.api.update {
            let patch = types::partial(types::insert_with(schema, &c.table, &types::json_ty));
            let user = opt(&[("id", Ty::Num, false), ("name", Ty::Str, false)]).nullable();
            let record = [
                ("id", Ty::Num, false),
                ("by", user, false),
                ("at", Ty::Str, false),
                ("promoteAt", Ty::Str.nullable(), false),
                ("_rev", Ty::Str, false),
            ];
            let mut version = vec![
                ("row", row.clone(), false),
                ("fields", Ty::Array(Box::new(Ty::Str)), false),
            ];
            version.extend(record.iter().cloned());
            let version = opt(&version);
            let mut comparison = vec![("changes", Ty::Array(Box::new(Ty::Map(Box::new(Ty::Unknown)))), false)];
            comparison.extend(record.iter().cloned());
            let preview = opt(&[
                ("token", Ty::Str, false),
                ("expiresAt", Ty::Str, false),
                ("api", Ty::Str, false),
                ("url", Ty::Str.nullable(), false),
            ]);
            out.push((
                format!("{app}/{k}-stage"),
                (
                    conditions(vec![("id", pk.clone(), false), ("patch", patch, false)]),
                    version.clone(),
                ),
            ));
            out.push((format!("{app}/{k}-staged-get"), (id(), version)));
            out.push((format!("{app}/{k}-staged-compare"), (id(), opt(&comparison))));
            // The promoted row, or the staged version when `at` schedules or cancels.
            out.push((
                format!("{app}/{k}-staged-promote"),
                (
                    conditions(vec![("id", pk.clone(), false), ("at", Ty::Str.nullable(), true)]),
                    Ty::Unknown,
                ),
            ));
            out.push((
                format!("{app}/{k}-staged-discard"),
                (
                    conditions(vec![("id", pk.clone(), false)]),
                    opt(&[("revision", Ty::Num, false)]),
                ),
            ));
            out.push((
                format!("{app}/{k}-staged-preview"),
                (opt(&[("id", pk.clone(), false), ("ttl", Ty::Num, true)]), preview),
            ));
        }
        if c.sync.is_some() {
            out.push((
                format!("{app}/{k}-sync-read"),
                (
                    opt(&[("after", pk.clone(), true), ("first", Ty::Num, true)]),
                    Ty::Unknown,
                ),
            ));
            out.push((
                format!("{app}/{k}-sync-apply"),
                (
                    opt(&[
                        ("record", Ty::Map(Box::new(Ty::Unknown)), false),
                        ("expect", Ty::Str, false),
                    ]),
                    Ty::Unknown,
                ),
            ));
            out.push((
                format!("{app}/{k}-sync-trash"),
                (opt(&[("uid", Ty::Str, false), ("expect", Ty::Str, false)]), Ty::Unknown),
            ));
        }
        if c.commerce.as_deref() == Some("order") {
            personal.extend(out[first..].iter().map(|(name, _)| name.clone()));
        }
    }
    for a in schema.actions.values() {
        out.push((
            format!("{app}/{}", kebab(&a.name)),
            (fields_input(&a.input), Ty::Unknown),
        ));
    }
    for (name, g) in &schema.globals {
        let k = kebab(name);
        let values = types::global_row(g);
        out.push((format!("{app}/global-{k}-get"), (opt(&[]), values.clone())));
        out.push((
            format!("{app}/global-{k}-update"),
            (types::partial(values.clone()), values),
        ));
    }
    let media = Ty::Map(Box::new(Ty::Unknown));
    let media_id = opt(&[("id", Ty::Num, false)]);
    out.push((
        format!("{app}/media-list"),
        (paging(&[]), opt(&[("rows", Ty::Array(Box::new(media.clone())), false)])),
    ));
    out.push((format!("{app}/media-get"), (media_id.clone(), media.clone())));
    out.push((format!("{app}/media-delete"), (media_id, media)));
    for (name, j) in &schema.jobs {
        if j.agents {
            out.push((
                format!("{app}/job-{}", kebab(name)),
                (
                    fields_input(&j.input),
                    opt(&[("id", Ty::union([Ty::Num, Ty::Str]), false)]),
                ),
            ));
        }
    }
    for (name, shape) in commerce_operations(schema) {
        if name == "complete-order" {
            personal.insert(format!("{app}/{name}"));
        }
        out.push((
            format!("{app}/{name}"),
            (
                schema_ty(&shape["input"], &mut Vec::new(), ""),
                schema_ty(&shape["output"], &mut Vec::new(), ""),
            ),
        ));
    }
    Own {
        abilities: out,
        personal,
    }
}

/// The app's own abilities: each one's name, input and output, and the names that need the
/// `personal` grant ([`Catalog::personal`]).
struct Own {
    abilities: Vec<(String, (Ty, Ty))>,
    personal: BTreeSet<String>,
}

/// The stock and fulfilment operations `TypedPress\Commerce\Operations` registers for this schema,
/// with their schemas: none without `product({ inventory: true })`, and `complete-order` only
/// with an `order()` beside it.
pub fn commerce_operations(schema: &Schema) -> Vec<(String, Value)> {
    let has = |kind: &str| schema.collections.values().any(|c| c.commerce.as_deref() == Some(kind));
    if !schema
        .collections
        .values()
        .any(|c| c.commerce.as_deref() == Some("product") && c.columns.contains_key("stockQuantity"))
    {
        return Vec::new();
    }
    let shapes: Value = serde_json::from_str(include_str!("../../runtime/TypedPress/Commerce/operations.json"))
        .expect("commerce operation schemas");
    shapes
        .as_object()
        .expect("commerce operations")
        .iter()
        .filter(|(name, _)| *name != "complete-order" || has("order"))
        .map(|(name, shape)| (name.clone(), shape.clone()))
        .collect()
}

/// `JsonSchema::isRequired`: a NOT NULL column without a default or auto-increment.
fn required(col: &crate::schema::Column) -> bool {
    col.not_null && col.default.is_none() && !col.auto_increment
}

/// An action's or job's input as `JsonSchema::input` publishes it: every field, required ones as
/// `Schema::isRequired` says, optional ones nullable.
fn fields_input(fields: &indexmap::IndexMap<String, crate::schema::Column>) -> Ty {
    let Ty::Obj(types) = types::action_input(fields, &types::json_ty) else {
        unreachable!()
    };
    Ty::Obj(
        types
            .into_iter()
            .map(|(k, (t, _))| {
                let optional = !required(&fields[&k]);
                (k, (t, optional))
            })
            .collect(),
    )
}

/// The create input `JsonSchema::insert` publishes: writable columns (not hidden, derived or
/// auto-increment), required as the pipeline requires them before hooks (not filled by a
/// before-create hook, not the generated slug), plus Register::collection's virtual inputs.
fn create_input(schema: &Schema, c: &crate::schema::Collection) -> Ty {
    let Ty::Obj(mut all) = types::insert_with(schema, &c.table, &types::json_ty) else {
        unreachable!()
    };
    let mut fields = IndexMap::new();
    for col in c.columns.values() {
        if col.hidden || col.derived || col.auto_increment {
            continue;
        }
        let Some((t, _)) = all.shift_remove(&col.name) else {
            continue;
        };
        let optional =
            !required(col) || c.before_create_fills.contains(&col.name) || c.slug.as_deref() == Some(col.name.as_str());
        fields.insert(col.name.clone(), (t, optional));
    }
    // translationOf (localized) is already in the insert type; keep it.
    if let Some(t) = all.shift_remove("translationOf") {
        fields.insert("translationOf".into(), t);
    }
    if c.auth {
        fields.insert("password".into(), (Ty::Str, true));
    }
    Ty::Obj(fields)
}

/// The runtime's kebab-case (`Register::kebab`, `extract::kebab`).
pub fn kebab(name: &str) -> String {
    let mut s = String::new();
    let chars: Vec<char> = name.chars().collect();
    for (i, c) in chars.iter().enumerate() {
        if *c == '_' || *c == '-' {
            if !s.ends_with('-') {
                s.push('-');
            }
        } else {
            if c.is_uppercase()
                && i > 0
                && !s.ends_with('-')
                && (chars[i - 1].is_lowercase()
                    || chars[i - 1].is_ascii_digit()
                    || chars.get(i + 1).is_some_and(|c| c.is_lowercase()))
            {
                s.push('-');
            }
            s.extend(c.to_lowercase());
        }
    }
    s
}

/// The `tp/_generated/db.d.ts` block that types `abilities.call` for every catalog entry.
pub fn gen_ts(catalog: &Catalog) -> String {
    if catalog.abilities.is_empty() {
        return String::new();
    }
    let mut out = String::from("declare module \"php/wordpress\" {\n  interface Abilities {\n");
    for (name, (input, output)) in &catalog.abilities {
        out.push_str(&format!(
            "    {}: {{ input: {}; output: {} }};\n",
            serde_json::to_string(name).expect("string"),
            crate::client::ty_ts_plain(input),
            crate::client::ty_ts_plain(output)
        ));
    }
    out.push_str("  }\n}\n");
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn action_results_become_output_schemas_core_can_validate() {
        let obj = |fields: &[(&str, Ty, bool)]| {
            Ty::Obj(
                fields
                    .iter()
                    .map(|(k, t, o)| (k.to_string(), (t.clone(), *o)))
                    .collect(),
            )
        };
        let row = obj(&[("id", Ty::Num, false), ("note", Ty::Str.nullable(), true)]);
        assert_eq!(
            ty_schema(&Ty::Promise(Box::new(row.clone()))).or_else(|| ty_schema(&row)),
            Some(json!({
                "type": "object",
                // A computed number may be NaN or Infinity, which the wire carries as null.
                "properties": { "id": { "type": ["number", "null"] }, "note": { "type": ["string", "null"] } },
                "required": ["id"],
            }))
        );
        assert_eq!(
            ty_schema(&Ty::union([Ty::Num, Ty::Str])),
            Some(json!({ "type": ["number", "null", "string"] }))
        );
        // Integer literals: exact up to 2^53; beyond it the literal number itself, never a saturated i64.
        assert_eq!(
            ty_schema(&Ty::NumLit(42.0)),
            Some(json!({ "type": "integer", "enum": [42] }))
        );
        assert_eq!(
            ty_schema(&Ty::NumLit(1e20)),
            Some(json!({ "type": "number", "enum": [1e20] }))
        );
        assert_eq!(
            ty_schema(&Ty::union([Ty::NumLit(1.0), Ty::NumLit(-1e300)])),
            Some(json!({ "type": ["number"], "enum": [1, -1e300] }))
        );
        assert_eq!(
            ty_schema(&Ty::Array(Box::new(Ty::Bool))),
            Some(json!({ "type": "array", "items": { "type": "boolean" } }))
        );
        assert_eq!(
            ty_schema(&Ty::union([Ty::StrLit("a".into()), Ty::StrLit("b".into())])),
            Some(json!({ "type": ["string"], "enum": ["a", "b"] }))
        );
        assert_eq!(
            ty_schema(&Ty::Map(Box::new(Ty::Num))),
            Some(json!({ "type": "object", "additionalProperties": { "type": ["number", "null"] } }))
        );
        // Undefined and void results are null on the wire.
        assert_eq!(ty_schema(&Ty::Void), Some(json!({ "type": "null" })));
        // No single JSON shape: no output schema, so core never refuses what the types allow.
        assert_eq!(ty_schema(&Ty::Unknown), None);
        assert_eq!(ty_schema(&Ty::union([row, Ty::Num])), None);
    }

    #[test]
    fn json_schema_maps_what_abilities_use_and_warns_on_the_rest() {
        let mut warnings = Vec::new();
        let ty = schema_ty(
            &json!({
                "type": "object",
                "properties": {
                    "id": { "type": "integer" },
                    "status": { "enum": ["draft", "publish"] },
                    "tags": { "type": "array", "items": { "type": "string" } },
                    "note": { "type": ["string", "null"] },
                    "meta": { "type": "object", "additionalProperties": { "type": "boolean" } },
                    "pick": { "oneOf": [{ "type": "string" }, { "type": "number" }] },
                    "legacy": { "type": "string", "required": true },
                    "mixed": { "allOf": [{ "type": "string" }] }
                },
                "required": ["id"]
            }),
            &mut warnings,
            "",
        );
        let Ty::Obj(f) = ty else { panic!("{ty:?}") };
        assert_eq!(f["id"], (Ty::Num, false));
        assert_eq!(
            f["status"].0,
            Ty::union([Ty::StrLit("draft".into()), Ty::StrLit("publish".into())])
        );
        assert_eq!(f["tags"].0, Ty::Array(Box::new(Ty::Str)));
        assert_eq!(f["note"].0, Ty::Str.nullable());
        assert_eq!(f["meta"].0, Ty::Map(Box::new(Ty::Bool)));
        assert_eq!(f["pick"].0, Ty::union([Ty::Str, Ty::Num]));
        assert_eq!(f["legacy"], (Ty::Str, false));
        assert_eq!(f["mixed"], (Ty::Unknown, true));
        assert_eq!(
            warnings,
            ["/properties/mixed: `allOf` is not represented; typed as unknown"]
        );
    }

    #[test]
    fn ability_names_follow_core() {
        assert!(valid_name("woocommerce/create-order"));
        assert!(!valid_name("WooCommerce/create-order"));
        assert!(!valid_name("a/b/c"));
        assert!(!valid_name("nameonly"));
    }
}
