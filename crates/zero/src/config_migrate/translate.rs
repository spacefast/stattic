use serde_json::{Map, Value, json};
use std::collections::{HashMap, HashSet};

use super::{Entry, Failure, Source, entry, fail, pointer};

type Object = Map<String, Value>;

/// JavaScript's Number.MAX_SAFE_INTEGER.
const MAX_SAFE_INTEGER: u64 = 9_007_199_254_740_991;
/// Payload's `defaultMaxTextLength` default; the importer accepts no root override.
const PAYLOAD_DEFAULT_MAX_TEXT_LENGTH: u32 = 40000;
/// What timestamp() accepts (runtime/Zero/Schema.php `isIsoTimestamp`), stated once for reports.
const TIMESTAMP_GRAMMAR: &str = "Zero timestamps accept exactly YYYY-MM-DD, or YYYY-MM-DDTHH:MM[:SS[.fraction]] with Z or ±HH:MM, with real calendar and clock values (UTC years 0000-9999), or epoch milliseconds; nothing else.";
/// How timestamp() keeps a value, unlike sources that stored the given text.
const TIMESTAMP_STORAGE: &str = "Values are stored as UTC with millisecond precision (finer fractions are truncated); on WordPress post-backed collections they keep whole seconds.";
/// Payload's email validator (`fields/validations.ts`, pinned commit in docs/zero-compatibility.md).
/// Zero's email() accepts quoted local parts and address literals that Payload rejects.
const PAYLOAD_EMAIL: &str = r"/^(?!.*\.\.)[\w!#$%&'*+/=?^`{|}~-](?:[\w!#$%&'*+/=?^`{|}~.-]*[\w!#$%&'*+/=?^`{|}~-])?@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/i";

fn object<'a>(v: &'a Value, at: &str) -> Result<&'a Object, Failure> {
    v.as_object().ok_or_else(|| fail(at, "expected an object"))
}
fn array<'a>(v: &'a Value, at: &str) -> Result<&'a Vec<Value>, Failure> {
    v.as_array().ok_or_else(|| fail(at, "expected an array"))
}
fn string<'a>(o: &'a Object, key: &str, at: &str) -> Result<&'a str, Failure> {
    o.get(key)
        .and_then(Value::as_str)
        .ok_or_else(|| fail(&pointer(at, key), "expected a string"))
}
fn boolean(o: &Object, key: &str, at: &str) -> Result<Option<bool>, Failure> {
    o.get(key)
        .map(|v| v.as_bool().ok_or_else(|| fail(&pointer(at, key), "expected a boolean")))
        .transpose()
}
fn keys(o: &Object, allowed: &[&str], at: &str) -> Result<(), Failure> {
    for k in o.keys() {
        if !allowed.contains(&k.as_str()) {
            return Err(fail(
                &pointer(at, k),
                "unsupported option; port its behavior explicitly before migrating (nothing is discarded)",
            ));
        }
    }
    Ok(())
}
fn q(s: &str) -> String {
    serde_json::to_string(s).expect("JSON string")
}
fn ident(s: &str, at: &str) -> Result<(), Failure> {
    if s.is_empty()
        || !s.bytes().next().is_some_and(|c| c.is_ascii_alphabetic())
        || !s.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'_')
    {
        return Err(fail(
            at,
            "Zero names must start with an ASCII letter and contain only letters, numbers and underscores; rename explicitly to avoid collisions",
        ));
    }
    Ok(())
}

pub(super) fn config(input: Value, source: Source, report: &mut Vec<Entry>) -> Result<String, Failure> {
    let root = object(&input, "")?;
    let mut collections = Vec::new();
    let mut name = "imported";
    match source {
        Source::Zero => {
            keys(root, &["name", "collections"], "")?;
            if let Some(n) = root.get("name") {
                name = n.as_str().ok_or_else(|| fail("/name", "expected a string"))?;
            }
            let defs = root.get("collections").ok_or_else(|| fail("/collections", "expected original Zero capsule collections; schema tables and executable capsule features require a runtime port"))?;
            for (slug, def) in object(defs, "/collections")? {
                collections.push((slug.to_string(), def.clone(), pointer("/collections", slug)));
            }
        }
        Source::Emdash => {
            keys(root, &["$schema", "version", "meta", "collections"], "")?;
            if root.get("version").and_then(Value::as_str) != Some("1") {
                return Err(fail("/version", "expected EmDash seed version \"1\""));
            }
            metadata(root, &["$schema", "version", "meta"], "", report);
            for (i, def) in array(
                root.get("collections")
                    .ok_or_else(|| fail("/collections", "expected EmDash seed collections"))?,
                "/collections",
            )?
            .iter()
            .enumerate()
            {
                let at = pointer("/collections", &i.to_string());
                collections.push((string(object(def, &at)?, "slug", &at)?.into(), def.clone(), at));
            }
        }
        Source::Payload => {
            keys(root, &["collections", "serverURL", "telemetry"], "")?;
            metadata(root, &["serverURL", "telemetry"], "", report);
            for (i, def) in array(
                root.get("collections")
                    .ok_or_else(|| fail("/collections", "expected Payload collections"))?,
                "/collections",
            )?
            .iter()
            .enumerate()
            {
                let at = pointer("/collections", &i.to_string());
                collections.push((string(object(def, &at)?, "slug", &at)?.into(), def.clone(), at));
            }
        }
        Source::Sanity => {
            keys(root, &["name", "title", "projectId", "dataset", "schema"], "")?;
            metadata(root, &["name", "title", "projectId", "dataset"], "", report);
            let schema = object(
                root.get("schema")
                    .ok_or_else(|| fail("/schema", "expected Sanity schema.types"))?,
                "/schema",
            )?;
            keys(schema, &["types"], "/schema")?;
            for (i, def) in array(
                schema
                    .get("types")
                    .ok_or_else(|| fail("/schema/types", "expected Sanity schema types"))?,
                "/schema/types",
            )?
            .iter()
            .enumerate()
            {
                let at = pointer("/schema/types", &i.to_string());
                collections.push((string(object(def, &at)?, "name", &at)?.into(), def.clone(), at));
            }
        }
    }
    let mut names = HashSet::new();
    let mut code = String::from(
        "import { collection, table, defineConfig, defineRelations, text, integer, real, boolean, timestamp, url, email, json, select, media, type Column, type ColumnKind } from \"zero\";\n\n",
    );
    let mut content = Vec::new();
    let mut tables = Vec::new();
    let mut relations = Vec::new();
    // Reference targets may be declared after the field that names them.
    let targets: HashMap<&str, usize> = collections
        .iter()
        .enumerate()
        .map(|(i, (slug, _, _))| (slug.as_str(), i))
        .collect();
    for (i, (slug, def, at)) in collections.iter().enumerate() {
        ident(slug, at)?;
        if !names.insert(slug) {
            return Err(fail(at, "duplicate collection identity"));
        }
        if matches!(source, Source::Zero) && ["posts", "pages", "media"].contains(&slug.as_str()) {
            return Err(fail(
                at,
                "native WordPress posts/pages/media adoption needs an explicit storage migration; a new custom post type would change ownership",
            ));
        }
        let (fields, mut options, links) = collection(def, source, at, report, slug, &targets)?;
        if !links.is_empty() {
            relations.push(format!("    {}: {{ {} }},\n", q(slug), links.join(", ")));
        }
        let editor = options
            .as_object_mut()
            .unwrap()
            .remove("editor")
            .unwrap()
            .as_bool()
            .unwrap();
        let builder = if editor { "collection" } else { "table" };
        code.push_str(&format!(
            "const imported{i} = {builder}({{\n{fields}}}, {});\n\n",
            serde_json::to_string(&options).expect("options JSON")
        ));
        (if editor { &mut content } else { &mut tables }).push(format!("{}: imported{i}", q(slug)));
        report.push(entry(
            at,
            "mapped",
            format!("collection {slug}; new data requires a separate import and ID mapping"),
        ));
    }
    code.push_str(&format!(
        "const collections = {{ {} }};\nconst tables = {{ {} }};\n\n",
        content.join(", "),
        tables.join(", ")
    ));
    if !relations.is_empty() {
        code.push_str(&format!(
            "export const relations = defineRelations({{ ...collections, ...tables }}, (r) => ({{\n{}}}));\n\n",
            relations.concat()
        ));
    }
    code.push_str(&format!(
        "export default defineConfig({{\n  name: {},\n  collections,\n  tables,\n{}}});\n",
        q(name),
        if relations.is_empty() { "" } else { "  relations,\n" }
    ));
    report.push(entry("", "review", "Config import only: documents, IDs, routes, clients, deployments and source runtime behavior are not migrated. Review access and WordPress storage before deployment."));
    Ok(code)
}

fn metadata(o: &Object, keys: &[&str], at: &str, report: &mut Vec<Entry>) {
    for key in keys {
        if o.contains_key(*key) {
            report.push(entry(
                &pointer(at, key),
                "metadata",
                "source metadata is not a Zero runtime declaration",
            ));
        }
    }
}

fn collection(
    v: &Value,
    source: Source,
    at: &str,
    report: &mut Vec<Entry>,
    slug: &str,
    targets: &HashMap<&str, usize>,
) -> Result<(String, Value, Vec<String>), Failure> {
    let o = object(v, at)?;
    let (name_key, field_key) = match source {
        Source::Zero => {
            keys(o, &["fields", "label", "publicRead"], at)?;
            (None, "kind")
        }
        Source::Emdash => {
            keys(o, &["slug", "label", "labelSingular", "description", "fields"], at)?;
            metadata(o, &["labelSingular", "description"], at, report);
            (Some("slug"), "type")
        }
        Source::Payload => {
            keys(o, &["slug", "fields", "labels", "access", "timestamps"], at)?;
            (Some("slug"), "type")
        }
        Source::Sanity => {
            keys(o, &["name", "type", "title", "description", "fields"], at)?;
            if string(o, "type", at)? != "document" {
                return Err(fail(
                    &pointer(at, "type"),
                    "only Sanity document types are supported; named object types must be inlined manually",
                ));
            }
            metadata(o, &["description"], at, report);
            (Some("name"), "type")
        }
    };
    if let Some(key) = name_key {
        string(o, key, at)?;
    }
    let fields_at = pointer(at, "fields");
    let input = o.get("fields").ok_or_else(|| fail(&fields_at, "expected fields"))?;
    let mut defs = Vec::new();
    if matches!(source, Source::Zero) {
        for (name, def) in object(input, &fields_at)? {
            defs.push((name.clone(), def, pointer(&fields_at, name)));
        }
    } else {
        for (i, def) in array(input, &fields_at)?.iter().enumerate() {
            let p = pointer(&fields_at, &i.to_string());
            let key = if matches!(source, Source::Emdash) {
                "slug"
            } else {
                "name"
            };
            defs.push((string(object(def, &p)?, key, &p)?.into(), def, p));
        }
    }
    let mut fields = String::new();
    let mut names = HashSet::new();
    let mut links = Vec::new();
    let mut editor = false;
    for (name, def, path) in &defs {
        ident(name, path)?;
        if ["id", "status", "publishedAt"].contains(&name.as_str()) || !names.insert(name.clone()) {
            return Err(fail(
                path,
                "duplicate or reserved generated field identity (id/status/publishedAt)",
            ));
        }
        if matches!(source, Source::Zero) && def.get("kind").and_then(Value::as_str) == Some("reference") {
            let (column, builder, link) = reference(def, path, name, slug, targets, report)?;
            fields.push_str(&format!("  {}: {builder},\n", q(&column)));
            links.push(link);
            continue;
        }
        let (builder, title) = field(def, source, field_key, path, report)?;
        editor |= name == "title" && title;
        fields.push_str(&format!("  {}: {builder},\n", q(name)));
    }
    // A reference column is named `<field>Id`; it must not shadow a declared field.
    for (name, def, path) in &defs {
        if def.get("kind").and_then(Value::as_str) == Some("reference") && !names.insert(format!("{name}Id")) {
            return Err(fail(
                path,
                format!(
                    "the generated reference column {name}Id collides with a declared field; rename one explicitly"
                ),
            ));
        }
    }
    let mut options = json!({"editor":editor,"access":{"read":false,"create":false,"update":false,"delete":false}});
    let label_key = if matches!(source, Source::Sanity) {
        "title"
    } else {
        "label"
    };
    if let Some(label) = o.get(label_key) {
        if !label.is_string() {
            return Err(fail(&pointer(at, label_key), "expected label string"));
        }
        options["label"] = label.clone();
    }
    if let Some(labels) = o.get("labels") {
        let labels = object(labels, &pointer(at, "labels"))?;
        keys(labels, &["singular", "plural"], &pointer(at, "labels"))?;
        for key in ["singular", "plural"] {
            if let Some(v) = labels.get(key)
                && !v.is_string()
            {
                return Err(fail(at, "labels must be strings"));
            }
        }
        if let Some(label) = labels.get("plural") {
            options["label"] = label.clone();
        }
        report.push(entry(
            &pointer(at, "labels"),
            "review",
            "Zero uses one collection label; plural is carried, singular uses the target's default",
        ));
    }
    if let Some(public) = boolean(o, "publicRead", at)? {
        options["access"]["read"] = Value::Bool(public);
    }
    if let Some(access) = o.get("access") {
        let p = pointer(at, "access");
        let access = object(access, &p)?;
        keys(access, &["read", "create", "update", "delete"], &p)?;
        for key in access.keys() {
            options["access"][key] = Value::Bool(boolean(access, key, &p)?.expect("present boolean"));
        }
    }
    if matches!(source, Source::Payload) && boolean(o, "timestamps", at)?.unwrap_or(true) {
        if names.contains("createdAt") || names.contains("updatedAt") {
            return Err(fail(at, "timestamp fields collide with Payload automatic timestamps"));
        }
        fields.push_str("  createdAt: timestamp().notNull().defaultNow(),\n  updatedAt: timestamp().notNull().defaultNow().$onUpdate(() => new Date()),\n");
        report.push(entry(
            &pointer(at, "timestamps"),
            "mapped",
            "Payload automatic createdAt/updatedAt timestamps",
        ));
    }
    report.push(entry(at, "review", if editor {
        "WordPress custom post type with drafts/revisions; source publishing and status behavior needs review. Unspecified REST permissions deny access."
    } else {
        "Custom table (no required text field named title); WordPress post editing is unavailable until title/storage are configured. Unspecified REST permissions deny access."
    }));
    Ok((fields, options, links))
}

/**
 * Original Zero stored a single reference as the target post id and validated only membership on
 * write; deleting the target left the id dangling. Zero has no dangling references: an optional
 * one becomes `set null`, a required one `restrict`. Each returns (column, builder, relation).
 */
fn reference(
    v: &Value,
    at: &str,
    name: &str,
    slug: &str,
    targets: &HashMap<&str, usize>,
    report: &mut Vec<Entry>,
) -> Result<(String, String, String), Failure> {
    let o = object(v, at)?;
    keys(o, &["kind", "label", "required", "to", "multiple"], at)?;
    if boolean(o, "multiple", at)?.unwrap_or(false) {
        return Err(fail(
            &pointer(at, "multiple"),
            "multiple references have no Zero column; model them as a join collection and port the data explicitly",
        ));
    }
    let to = string(o, "to", at)?;
    let target = targets.get(to).ok_or_else(|| {
        fail(
            &pointer(at, "to"),
            format!("reference target {to:?} is not an imported collection"),
        )
    })?;
    let required = boolean(o, "required", at)?.unwrap_or(false);
    let column = format!("{name}Id");
    ident(&column, at)?;
    let mut builder = if required {
        format!(
            "integer().notNull().references((): Column<ColumnKind, number> => imported{target}.id, {{ onDelete: \"restrict\" }})"
        )
    } else {
        format!(
            "integer().references((): Column<ColumnKind, number> => imported{target}.id, {{ onDelete: \"set null\" }})"
        )
    };
    if let Some(label) = o.get("label") {
        if !label.is_string() {
            return Err(fail(&pointer(at, "label"), "expected editor text"));
        }
        builder.push_str(&format!(".ui({{ label: {label} }})"));
        report.push(entry(
            &pointer(at, "label"),
            "mapped",
            "Reference presentation label -> .ui()",
        ));
    }
    report.push(entry(
        at,
        "mapped",
        format!("reference -> {column} {builder} with relation {name}"),
    ));
    report.push(entry(
        at,
        "review",
        if required {
            "Original Zero left a deleted target's id dangling; this required reference now refuses deleting a referenced row."
        } else {
            "Original Zero left a deleted target's id dangling; this reference is now cleared when its target is deleted."
        },
    ));
    let link = format!("{}: r.one.{to}({{ from: r.{slug}.{column}, to: r.{to}.id }})", q(name));
    Ok((column, builder, link))
}

fn field(
    v: &Value,
    source: Source,
    kind_key: &str,
    at: &str,
    report: &mut Vec<Entry>,
) -> Result<(String, bool), Failure> {
    let o = object(v, at)?;
    let kind = string(o, kind_key, at)?;
    if matches!(source, Source::Sanity) && kind == "url" {
        return Err(fail(
            &pointer(at, kind_key),
            "Sanity URI scheme, credential and empty-value validation cannot be preserved by Zero url(); port the URI rule explicitly",
        ));
    }
    if matches!(source, Source::Emdash) && kind == "url" {
        return Err(fail(
            &pointer(at, kind_key),
            "EmDash permits relative, mailto and tel URLs that Zero url() rejects; port the URL rule explicitly",
        ));
    }
    let supported = match source {
        Source::Zero => matches!(kind, "text" | "number" | "boolean" | "datetime" | "enum" | "media"),
        Source::Emdash => matches!(
            kind,
            "string" | "text" | "number" | "integer" | "boolean" | "datetime" | "json"
        ),
        Source::Payload => matches!(
            kind,
            "text" | "textarea" | "email" | "number" | "checkbox" | "date" | "json" | "select"
        ),
        Source::Sanity => matches!(kind, "string" | "text" | "number" | "boolean" | "datetime"),
    };
    if !supported {
        return Err(fail(
            &pointer(at, kind_key),
            format!(
                "unsupported field type {kind:?}; rich text, media, references, nested/custom schemas require a format/data/runtime port"
            ),
        ));
    }
    let base = match source {
        Source::Zero => vec!["kind", "label", "required"],
        Source::Emdash => vec!["slug", "type", "label", "required", "defaultValue", "unique"],
        Source::Payload => vec!["name", "type", "label", "admin", "required", "defaultValue", "unique"],
        Source::Sanity => vec!["name", "type", "title", "description", "initialValue", "validation"],
    };
    let mut allowed = base;
    let mut builder = match kind {
        "string" | "text" => {
            allowed.extend(["maxLength", "multiline"]);
            let multi = boolean(o, "multiline", at)?.unwrap_or(false)
                || (kind == "text" && matches!(source, Source::Sanity | Source::Emdash));
            if multi { "text({ multiline: true })" } else { "text()" }.to_string()
        }
        "textarea" if matches!(source, Source::Payload) => {
            allowed.push("maxLength");
            "text({ multiline: true })".into()
        }
        "number" | "integer" => {
            allowed.extend(["min", "max"]);
            if kind == "integer" { "integer()" } else { "real()" }.into()
        }
        "boolean" | "checkbox" => "boolean()".into(),
        "datetime" | "date" => "timestamp()".into(),
        "email" if matches!(source, Source::Payload) => format!("email().pattern({PAYLOAD_EMAIL})"),
        "email" => "email()".into(),
        "json" => "json()".into(),
        // Original Zero media fields hold attachment ids with no type restriction.
        "media" if matches!(source, Source::Zero) => {
            allowed.push("multiple");
            if boolean(o, "multiple", at)?.unwrap_or(false) {
                "media({ multiple: true })"
            } else {
                "media()"
            }
            .into()
        }
        "enum" if matches!(source, Source::Zero) => {
            allowed.extend(["values", "multiple"]);
            let values = array(
                o.get("values").ok_or_else(|| fail(at, "expected enum values"))?,
                &pointer(at, "values"),
            )?;
            select(values, boolean(o, "multiple", at)?.unwrap_or(false), at)?
        }
        "select" if matches!(source, Source::Payload) => {
            allowed.extend(["options", "hasMany"]);
            let values = array(
                o.get("options").ok_or_else(|| fail(at, "expected select options"))?,
                &pointer(at, "options"),
            )?;
            select(values, boolean(o, "hasMany", at)?.unwrap_or(false), at)?
        }
        _ => {
            return Err(fail(
                &pointer(at, kind_key),
                format!(
                    "unsupported field type {kind:?}; rich text, media, references, nested/custom schemas require a format/data/runtime port"
                ),
            ));
        }
    };
    keys(o, &allowed, at)?;
    let validation = o
        .get("validation")
        .map(|v| object(v, &pointer(at, "validation")))
        .transpose()?;
    if let Some(v) = validation {
        keys(v, &["required"], &pointer(at, "validation"))?;
    }
    let required = boolean(o, "required", at)?.unwrap_or(false)
        || validation
            .map(|v| boolean(v, "required", &pointer(at, "validation")))
            .transpose()?
            .flatten()
            .unwrap_or(false);
    if required {
        builder.push_str(".notNull()");
        if (matches!(source, Source::Payload)
            && (matches!(kind, "text" | "textarea")
                || (kind == "select" && boolean(o, "hasMany", at)?.unwrap_or(false))))
            || (matches!(source, Source::Sanity | Source::Emdash) && matches!(kind, "string" | "text"))
            // A required SCF gallery rejects an empty selection.
            || (matches!(source, Source::Zero) && kind == "media" && boolean(o, "multiple", at)?.unwrap_or(false))
        {
            builder.push_str(".min(1)");
        }
        if matches!(source, Source::Payload) && kind == "json" {
            builder.push_str(".validate(value => !!value || \"Required\")");
        }
        // EmDash rejects "" for every required field, whatever its type.
        if matches!(source, Source::Emdash) && kind == "json" {
            builder.push_str(".validate(value => value !== \"\" || \"Required\")");
        }
    }
    if boolean(o, "unique", at)?.unwrap_or(false) {
        builder.push_str(".unique()");
    }
    // Payload's sanitized config sets `defaultMaxTextLength: 40000`, and its text
    // and textarea validators use it whenever a field declares no maxLength.
    let implicit =
        (matches!(source, Source::Payload) && matches!(kind, "text" | "textarea") && !o.contains_key("maxLength"))
            .then(|| json!(PAYLOAD_DEFAULT_MAX_TEXT_LENGTH));
    if implicit.is_some() {
        report.push(entry(
            at,
            "mapped",
            format!("Payload defaultMaxTextLength ({PAYLOAD_DEFAULT_MAX_TEXT_LENGTH}) applies without maxLength"),
        ));
    }
    // Zod's int() (EmDash integers) admits only safe integers, whatever bounds are declared; Zero's
    // integer() admits any PHP int. A later .min()/.max() replaces an earlier one, so each bound is
    // emitted once, as the intersection.
    let safe = matches!(source, Source::Emdash) && kind == "integer";
    for key in ["maxLength", "min", "max"] {
        if safe && key != "maxLength" {
            let limit = MAX_SAFE_INTEGER as f64;
            let declared = match o.get(key) {
                Some(n) => n.as_f64().ok_or_else(|| fail(&pointer(at, key), "expected a number"))?,
                None if key == "min" => -limit,
                None => limit,
            };
            let bound = if key == "min" {
                declared.max(-limit)
            } else {
                declared.min(limit)
            };
            builder.push_str(&format!(".{key}({})", json!(bound)));
            continue;
        }
        if let Some(n) = o.get(key).or(implicit.as_ref().filter(|_| key == "maxLength")) {
            if !n.is_number() {
                return Err(fail(&pointer(at, key), "expected a number"));
            }
            if key == "maxLength" && matches!(source, Source::Payload) {
                // Payload measures JavaScript string length, whereas the text
                // builder's max rule measures Unicode characters.
                if kind != "textarea" || n.as_f64() != Some(0.0) {
                    // UTF-16 bounds also imply this looser character bound;
                    // keep it for storage sizing and unique-text validation.
                    if n.as_f64().is_some_and(|n| n >= 0.0) {
                        builder.push_str(&format!(".max({n})"));
                    }
                    let empty = if kind == "textarea" { "value === \"\" || " } else { "" };
                    builder.push_str(&format!(
                        ".validate(value => value === null || {empty}value.length <= {n} || {})",
                        q(&format!("Must have at most {n} UTF-16 code units"))
                    ));
                }
            } else {
                let modifier = if key == "maxLength" { "max" } else { key };
                builder.push_str(&format!(".{modifier}({n})"));
            }
        }
    }
    let default_key = if matches!(source, Source::Sanity) {
        "initialValue"
    } else {
        "defaultValue"
    };
    if let Some(value) = o.get(default_key) {
        builder.push_str(&format!(".default({value})"));
    } else if matches!(source, Source::Payload) && kind == "checkbox" && required {
        builder.push_str(".default(false)");
    }
    if matches!(source, Source::Emdash) && kind == "boolean" {
        report.push(entry(at, "review", "EmDash stores booleans as 0/1 and accepts 0/1 back; Zero's boolean() accepts only true/false. Send booleans from clients that round-tripped EmDash's stored values."));
    }
    // timestamp() takes strict ISO 8601 with real calendar dates (no 02-30), or epoch milliseconds,
    // and stores normalized UTC. Each source's own rule differs at the edges; say where.
    if matches!(source, Source::Emdash) && kind == "datetime" {
        report.push(entry(at, "review", format!("{TIMESTAMP_GRAMMAR} Unlike EmDash, a date alone and epoch milliseconds are accepted, and values whose UTC time falls outside years 0000-9999 are refused. {TIMESTAMP_STORAGE}")));
    }
    if matches!(source, Source::Sanity) && kind == "datetime" {
        report.push(entry(at, "review", format!("{TIMESTAMP_GRAMMAR} Sanity accepted many other ISO 8601 spellings, all now refused: for example week (2026-W05-3), ordinal (2026-032), reduced (2026-01) and compact (20260101, T103000) forms, expanded years (+002026), impossible dates (02-30), 24:00, no timezone, space separators, comma or fractional-minute fractions, and offsets without a colon. It did not accept epoch milliseconds, which Zero does. {TIMESTAMP_STORAGE}")));
    }
    if matches!(source, Source::Payload) && kind == "date" {
        report.push(entry(at, "review", "Zero timestamps accept ISO 8601 dates and epoch milliseconds; Payload's Date.parse also accepted free-form dates such as \"Jan 1 2026\". Normalize such inputs before writing."));
    }
    if matches!(source, Source::Payload) && kind == "email" {
        report.push(entry(at, "review", "Zero's email() also applies PHP's address check, so addresses Payload accepted with a local part over 64 characters or a label over 63 are rejected."));
    }
    if matches!(source, Source::Payload) && kind == "email" && !required {
        report.push(entry(at, "review", "Zero email validation rejects optional empty strings that Payload accepts; use null/absence or port the empty-value behavior before deployment."));
    }
    let mut ui = Map::new();
    for key in ["label", "title", "description"] {
        if let Some(value) = o.get(key) {
            if !value.is_string() {
                return Err(fail(&pointer(at, key), "expected editor text"));
            }
            ui.insert(
                if key == "description" { "help" } else { "label" }.into(),
                value.clone(),
            );
            report.push(entry(
                &pointer(at, key),
                "mapped",
                "Field presentation metadata -> .ui()",
            ));
        }
    }
    if let Some(admin) = o.get("admin") {
        let admin_at = pointer(at, "admin");
        let admin = object(admin, &admin_at)?;
        keys(admin, &["description", "placeholder", "width"], &admin_at)?;
        for key in ["description", "placeholder", "width"] {
            if let Some(value) = admin.get(key) {
                let value = value
                    .as_str()
                    .ok_or_else(|| fail(&pointer(&admin_at, key), "expected a string"))?;
                let target = match key {
                    "description" => "help",
                    "width" => "width",
                    _ => "placeholder",
                };
                let value = if key == "width" {
                    let width = value
                        .strip_suffix('%')
                        .and_then(|v| v.parse::<u8>().ok())
                        .filter(|n| (1..=100).contains(n))
                        .ok_or_else(|| {
                            fail(
                                &pointer(&admin_at, key),
                                "width must be an integer percentage from 1% to 100%",
                            )
                        })?;
                    json!(width)
                } else {
                    json!(value)
                };
                ui.insert(target.into(), value);
                report.push(entry(
                    &pointer(&admin_at, key),
                    "mapped",
                    "Field presentation metadata -> .ui()",
                ));
            }
        }
    }
    if !ui.is_empty() {
        builder.push_str(&format!(".ui({})", Value::Object(ui)));
    }
    report.push(entry(at, "mapped", format!("{kind} -> {builder}")));
    Ok((builder, required && matches!(kind, "string" | "text" | "textarea")))
}

fn select(values: &[Value], multiple: bool, at: &str) -> Result<String, Failure> {
    let mut out = Vec::new();
    for value in values {
        let Some(value) = value.as_str() else {
            return Err(fail(
                at,
                "only string select options are supported; labeled options need an explicit editor mapping",
            ));
        };
        if out.contains(&value) {
            return Err(fail(at, "duplicate select value"));
        }
        out.push(value);
    }
    if out.is_empty() {
        return Err(fail(at, "select options cannot be empty"));
    }
    Ok(format!(
        "select({{ options: {}, multiple: {multiple} }})",
        serde_json::to_string(&out).expect("options JSON")
    ))
}
