//! JSON Schemas of the app's values, from the same columns that type the client (`client.rs`) and
//! that the runtime publishes as Ability schemas (`runtime/SpacePress/Wp/Abilities/JsonSchema.php`).
//! Each function here is that class's function of the same name, so the OpenAPI document
//! (`openapi.rs`) describes a row, a create input and a patch exactly as the Abilities do;
//! `openapi_schemas_are_the_registered_ability_schemas` in tests/compiler_contracts.rs runs both
//! and compares them.

use crate::schema::{ColType, Collection, Column};
use indexmap::IndexMap;
use serde_json::{Map, Value, json};

/// Every JSON type: a `json()` value is anything.
const ANY: [&str; 7] = ["object", "array", "string", "number", "integer", "boolean", "null"];

/// `Schema::isRequired`: a create must receive the column (no default, not generated).
pub fn required(c: &Column) -> bool {
    !c.optional_on_insert()
}

fn number(n: f64) -> Value {
    if n.fract() == 0.0 && n.abs() < 9.007_199_254_740_992e15 {
        json!(n as i64)
    } else {
        json!(n)
    }
}

/// A value of one column. `output` widens media references, which `with` may populate, and adds
/// the formats a response carries; an input has none, because SpacePress validates it itself.
pub fn column(c: &Column, output: bool) -> Value {
    let media = || {
        if output {
            json!({ "type": ["integer", "object"] })
        } else {
            json!({ "type": "integer" })
        }
    };
    let mut s = match c.ty {
        // A `ref()` column, as the runtime's ability output: `with` loads the related row in its place.
        ColType::Integer if output && c.references.is_some() => json!({ "type": ["integer", "string", "object"] }),
        ColType::Integer => json!({ "type": "integer" }),
        ColType::Real => json!({ "type": "number" }),
        ColType::Text | ColType::RichText | ColType::Email => json!({ "type": "string" }),
        ColType::Url if output => json!({ "type": "string", "format": "uri" }),
        ColType::Url => json!({ "type": "string" }),
        ColType::Slug => json!({ "type": "string", "pattern": "^[a-z0-9]+(?:-[a-z0-9]+)*$", "maxLength": 200 }),
        ColType::Boolean => json!({ "type": "boolean" }),
        ColType::Timestamp if output => json!({ "type": "string", "format": "date-time" }),
        // The API also accepts epoch milliseconds and any date string SpacePress parses.
        ColType::Timestamp => json!({ "type": ["string", "integer"] }),
        // No `format: date`, as the runtime's ability schemas: `@wordpress/abilities` validates with
        // ajv, which knows date-time, email, hostname, ipv4, ipv6, uri and uuid, and refuses others.
        ColType::Date => json!({ "type": "string" }),
        ColType::Json => c.shape.clone().unwrap_or_else(|| json!({ "type": ANY })),
        ColType::Media | ColType::File => media(),
        ColType::MediaList => json!({ "type": "array", "items": media() }),
        // `ref(kind).many()`: target ids; a `with` load puts the rows in their place.
        ColType::RefList => json!({ "type": "array", "items": media(), "uniqueItems": true }),
        ColType::Select => json!({
            "type": "array",
            "items": { "type": "string", "enum": c.enum_values.clone().unwrap_or_default() },
            "uniqueItems": true,
        }),
        ColType::Group => object(c.fields.as_ref(), output, false),
        ColType::Repeater => json!({ "type": "array", "items": object(c.fields.as_ref(), output, !output) }),
        ColType::Blocks => json!({ "type": "array", "items": blocks(c.layouts.as_ref(), output) }),
    };
    if c.ty != ColType::Select
        && let Some(values) = &c.enum_values
    {
        s["enum"] = json!(values);
    }
    let bounds = match c.ty {
        ColType::Text | ColType::RichText | ColType::Email | ColType::Url | ColType::Slug => {
            Some(("minLength", "maxLength"))
        }
        ColType::MediaList | ColType::RefList | ColType::Select | ColType::Repeater | ColType::Blocks => {
            Some(("minItems", "maxItems"))
        }
        ColType::Integer | ColType::Real => Some(("minimum", "maximum")),
        _ => None,
    };
    if let Some((min, max)) = bounds {
        if let Some(n) = c.rules.min {
            s[min] = number(n);
        }
        if let Some(n) = c.rules.max {
            s[max] = number(n);
        }
    }
    // A `pattern` rule is a JavaScript regular expression, which a JSON Schema validator may read
    // differently; SpacePress checks it.
    if c.not_null { s } else { nullable(s) }
}

/// `s`, or null.
pub fn nullable(mut s: Value) -> Value {
    let mut types = match &s["type"] {
        Value::Array(types) => types.clone(),
        one => vec![one.clone()],
    };
    if !types.iter().any(|t| t == "null") {
        types.push(json!("null"));
    }
    s["type"] = Value::Array(types);
    if let Some(values) = s.get_mut("enum").and_then(Value::as_array_mut) {
        values.push(Value::Null);
    }
    s
}

/// A row as a response returns it. Hidden columns never leave the runtime; a field the caller may
/// not read is absent, so nothing is required.
pub fn row(c: &Collection) -> Value {
    let props: Map<String, Value> = c
        .columns
        .values()
        .filter(|col| !col.hidden)
        .map(|col| {
            let mut schema = column(col, true);
            if c.editor && c.slug.as_deref() == Some(col.name.as_str()) {
                // A native post name follows WordPress's rules, including empty draft slugs.
                let fields = schema.as_object_mut().expect("column returns a schema object");
                fields.remove("pattern");
                fields.remove("minLength");
            }
            (col.name.clone(), schema)
        })
        .collect();
    let mut row = json!({ "type": "object", "properties": props });
    // A kind with `_rev` optimistic concurrency (post kinds with staged versions, or SQL tables with
    // revisions): every row the API and abilities give out carries its `_rev`, matching `Wp\Abilities\Register`.
    let has_table = !c.editor && !c.auth && c.taxonomy.is_none();
    if (c.editor && c.revisions && c.staged) || (has_table && c.revisions) {
        row["properties"]["_rev"] = json!({ "type": "string" });
    }
    row
}

/// The columns a write may set: on an update, not the primary key or the generated `uid`.
fn writable(c: &Collection, create: bool) -> impl Iterator<Item = &Column> {
    c.columns.values().filter(move |col| {
        !(col.hidden
            || col.derived
            || col.read_only
            || col.auto_increment
            || !create && (col.primary_key || col.name == "uid" && col.system))
    })
}

/// A create's input: the writable columns and the virtual inputs other features read (a
/// translation's `translationOf`, a member's `password`). Required is what the write path requires
/// before hooks run: not a column a `beforeCreate` hook fills, and not the generated slug.
pub fn insert(c: &Collection) -> Value {
    let mut props = Map::new();
    let mut required_names = Vec::new();
    for col in writable(c, true) {
        props.insert(col.name.clone(), column(col, false));
        if required(col) && !c.before_create_fills.contains(&col.name) && c.slug.as_deref() != Some(col.name.as_str()) {
            required_names.push(col.name.clone());
        }
    }
    if c.localized && !props.contains_key("translationOf") {
        props.insert("translationOf".into(), primary_key(c));
    }
    if c.auth && !props.contains_key("password") {
        props.insert("password".into(), json!({ "type": "string" }));
    }
    input(props, required_names)
}

/// An update's patch: every writable column, all optional. A users kind's patch also takes a new
/// `password` (`Auth\Module::password` handles it on every write but a delete).
pub fn patch(c: &Collection) -> Value {
    let mut props: Map<String, Value> = writable(c, false)
        .map(|col| (col.name.clone(), column(col, false)))
        .collect();
    if c.auth && !props.contains_key("password") {
        props.insert("password".into(), json!({ "type": "string" }));
    }
    json!({ "type": "object", "properties": props })
}

/// The type of a kind's primary key.
pub fn primary_key(c: &Collection) -> Value {
    match c.primary_key().map(|p| p.ty) {
        Some(ColType::Integer) | None => json!({ "type": "integer" }),
        Some(_) => json!({ "type": "string" }),
    }
}

/// An object of fields: a global's values (`output`), or what an action takes.
pub fn fields(fields: &IndexMap<String, Column>, output: bool) -> Value {
    object(Some(fields), output, !output)
}

/// An action's input: every field, required as `required` says.
pub fn action_input(fields: &IndexMap<String, Column>) -> Value {
    let props = fields.values().map(|f| (f.name.clone(), column(f, false))).collect();
    let required_names = fields
        .values()
        .filter(|f| required(f))
        .map(|f| f.name.clone())
        .collect();
    input(props, required_names)
}

/// A global's patch: every field, all optional.
pub fn global_patch(fields: &IndexMap<String, Column>) -> Value {
    input(
        fields.values().map(|f| (f.name.clone(), column(f, false))).collect(),
        Vec::new(),
    )
}

/// A top-level input object. `properties` and `required` are left out when empty.
fn input(props: Map<String, Value>, required_names: Vec<String>) -> Value {
    let mut s = json!({ "type": "object" });
    if !props.is_empty() {
        s["properties"] = Value::Object(props);
    }
    if !required_names.is_empty() {
        s["required"] = json!(required_names);
    }
    s
}

fn object(fields: Option<&IndexMap<String, Column>>, output: bool, with_required: bool) -> Value {
    let Some(fields) = fields else {
        return json!({ "type": "object" });
    };
    input(
        fields.values().map(|f| (f.name.clone(), column(f, output))).collect(),
        fields
            .values()
            .filter(|f| with_required && required(f))
            .map(|f| f.name.clone())
            .collect(),
    )
}

/// One variant per layout, told apart by its `layout`.
fn blocks(layouts: Option<&IndexMap<String, IndexMap<String, Column>>>, output: bool) -> Value {
    let variants: Vec<Value> = layouts
        .into_iter()
        .flatten()
        .map(|(layout, fields)| {
            let v = object(Some(fields), output, !output);
            let mut props = Map::new();
            props.insert("layout".into(), json!({ "type": "string", "enum": [layout] }));
            for (name, schema) in v.get("properties").and_then(Value::as_object).into_iter().flatten() {
                props.entry(name.clone()).or_insert_with(|| schema.clone());
            }
            let mut required_names = vec![json!("layout")];
            for name in v.get("required").and_then(Value::as_array).into_iter().flatten() {
                if name != "layout" {
                    required_names.push(name.clone());
                }
            }
            json!({ "type": "object", "properties": props, "required": required_names })
        })
        .collect();
    if variants.is_empty() {
        json!({ "type": "object" })
    } else {
        json!({ "type": "object", "oneOf": variants })
    }
}

/// The fields `search` matches (`Search\Index::fields`). `.searchable(false)` leaves a field out,
/// even the title or content; `.searchable()` puts one in. When no field of the kind is marked
/// `.searchable()`, the title, the content and every `text` or `richText` field without an `enum` are
/// matched; once one is, the title, the content and the marked fields are. Never a hidden field, nor
/// another system field.
pub fn searchable(c: &Collection) -> Vec<&str> {
    let chosen = c.columns.values().any(|col| col.searchable == Some(true));
    c.columns
        .values()
        .filter(|col| {
            let designated =
                c.title.as_deref() == Some(col.name.as_str()) || c.content.as_deref() == Some(col.name.as_str());
            !col.hidden
                && match col.searchable {
                    Some(on) => on,
                    None => {
                        designated
                            || !chosen
                                && !col.system
                                && matches!(col.ty, ColType::Text | ColType::RichText)
                                && col.enum_values.is_none()
                    }
                }
        })
        .map(|col| col.name.as_str())
        .collect()
}
