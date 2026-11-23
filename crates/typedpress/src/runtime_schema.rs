//! The runtime schema lock (`tp/_generated/runtime-schema.json`, docs/spec/spec.md §2.10).
//!
//! A site with `runtimeSchema: true` can add fields to its collections while it runs; `typedpress pull`
//! writes the site's active version here. This module declares those fields in the build, as if
//! they were authored, so a build made after a pull contains them.
//!
//! The lock holds field definitions in the compiled vocabulary (the column array of `schema.php`).
//! Each one is turned into the builder call an author would write (`text().optional().max(80)`)
//! and goes through the evaluator's and the extractor's own rules, so a lock field is checked by
//! the code that checks an authored one and nothing here restates a rule. The same definitions are
//! checked on the site by `TypedPress\RuntimeSchema\Validator`; `tests/runtime-schema/corpus.json` runs
//! against both.

use crate::diag::{Code, Diag, Span};
use crate::eval::Evaluator;
use crate::extract::Extracted;
use crate::loader::Program;
use crate::schema::{ColType, Column, DefaultKind, RuntimeSchema};
use crate::value::Value;
use indexmap::IndexMap;
use serde_json::{Map, Value as Json};
use std::path::Path;

/// Relative to the directory that holds `typedpress.config.ts`.
pub const LOCK: &str = "tp/_generated/runtime-schema.json";
/// The stored format this compiler reads and the runtime writes.
pub const FORMAT: u64 = 1;

/// Keys of a stored field definition, in the order `schema.php` writes them.
const FIELD_KEYS: &[&str] = &[
    "type",
    "multiple",
    "multiline",
    "accept",
    "notNull",
    "enum",
    "default",
    "rules",
    "fields",
    "layouts",
    "ui",
];

fn refuse(at: &str, message: impl std::fmt::Display) -> Diag {
    Diag::new(Span::NONE, format!("{LOCK}: {at}: {message}")).code(Code::Schema)
}

fn located(at: &str, diag: Diag, phase: Code) -> Diag {
    Diag {
        message: format!("{LOCK}: {at}: {}", diag.message),
        span: Span::NONE,
        ..diag
    }
    .phase(phase)
}

/// The lock, read once per build.
pub struct Lock {
    version: u64,
    hash: String,
    /// Kinds the lock adds fields to, whether or not it lists any: (is a global, kind).
    kinds: Vec<(bool, String)>,
    /// Fields added to kinds: (is a global, kind, field, definition).
    fields: Vec<(bool, String, String, Json)>,
    /// Labels and field presentation that replace the declared ones: (is a global, kind, override).
    overrides: Vec<(bool, String, Map<String, Json>)>,
    /// Kinds the lock adds that source doesn't declare: (is a global, kind).
    added: Vec<(bool, String)>,
}

fn object<'a>(value: Option<&'a Json>, at: &str, what: &str) -> Result<Option<&'a Map<String, Json>>, Diag> {
    match value {
        None => Ok(None),
        Some(Json::Object(map)) => Ok(Some(map)),
        // PHP writes an empty map as `[]`.
        Some(Json::Array(items)) if items.is_empty() => Ok(None),
        Some(_) => Err(refuse(at, format!("must be {what}"))),
    }
}

fn keys(map: &Map<String, Json>, allowed: &[&str], at: &str) -> Result<(), Diag> {
    match map.keys().find(|key| !allowed.contains(&key.as_str())) {
        Some(key) => Err(refuse(at, format!("has no key `{key}`"))),
        None => Ok(()),
    }
}

/// Reads the lock and registers the kinds it adds (new collections and globals) in the config's
/// registries, before extraction, so they are extracted and checked as authored ones are. Does
/// nothing, and reads nothing, unless the project set `runtimeSchema: true`.
pub fn declare(program: &Program, ev: &mut Evaluator<'_>, config: &Value) -> Result<Option<Lock>, Diag> {
    let Value::Object(cfg) = config else { return Ok(None) };
    if !matches!(cfg.borrow().get("runtimeSchema"), Some(Value::Bool(true))) {
        return Ok(None);
    }
    let path = program.root.join(LOCK);
    if !program.fs.exists(&path) {
        return Ok(None);
    }
    let text = program
        .fs
        .read_to_string(&path)
        .map_err(|e| Diag::new(Span::NONE, format!("can't read {LOCK}: {e}")).code(Code::Import))?;
    let lock: Json = serde_json::from_str(&text)
        .map_err(|e| Diag::new(Span::NONE, format!("{LOCK} isn't valid JSON: {e}")).code(Code::Schema))?;
    let top = lock
        .as_object()
        .ok_or_else(|| refuse("lock", "must be a JSON object"))?;
    if top.get("format").and_then(Json::as_u64) != Some(FORMAT) {
        return Err(refuse(
            "format",
            format!(
                "this compiler reads format {FORMAT}, not {}",
                top.get("format").unwrap_or(&Json::Null)
            ),
        )
        .help("run `typedpress pull` with this TypedPress version to rewrite the lock"));
    }
    keys(
        top,
        &[
            "format",
            "version",
            "hash",
            "collections",
            "globals",
            "added",
            "overrides",
        ],
        "lock",
    )?;
    let version = top
        .get("version")
        .and_then(Json::as_u64)
        .ok_or_else(|| refuse("version", "must be the site's schema version number"))?;
    let hash = top
        .get("hash")
        .and_then(Json::as_str)
        .ok_or_else(|| refuse("hash", "must be the site's schema version hash"))?;
    let mut out = Lock {
        version,
        hash: hash.to_string(),
        added: Vec::new(),
        kinds: Vec::new(),
        fields: Vec::new(),
        overrides: Vec::new(),
    };
    // Fields added to kinds the project declares.
    for (global, section, inner) in [(false, "collections", "columns"), (true, "globals", "fields")] {
        for (kind, entry) in object(top.get(section), section, "an object of kinds")?
            .into_iter()
            .flatten()
        {
            let fields = entry
                .as_object()
                .filter(|entry| entry.keys().all(|key| key == inner))
                .map(|entry| object(entry.get(inner), kind, "an object of fields"))
                .ok_or_else(|| refuse(kind, format!("must be `{{ \"{inner}\": {{ ... }} }}`")))??;
            out.kinds.push((global, kind.clone()));
            for (name, definition) in fields.into_iter().flatten() {
                out.fields
                    .push((global, kind.clone(), name.clone(), definition.clone()));
            }
        }
    }
    // Kinds the site added.
    let added = object(
        top.get("added"),
        "added",
        "`{ \"collections\": ..., \"globals\": ... }`",
    )?;
    if let Some(added) = added {
        keys(added, &["collections", "globals"], "added")?;
    }
    for (global, section) in [(false, "collections"), (true, "globals")] {
        let kinds = object(added.and_then(|a| a.get(section)), section, "an object of kinds")?;
        for (name, definition) in kinds.into_iter().flatten() {
            let def = definition
                .as_object()
                .ok_or_else(|| refuse(name, "a kind is `{ \"label\", \"access\", \"fields\" }`"))?;
            keys(def, &["label", "access", "fields"], name)?;
            let fields = object(def.get("fields"), name, "an object of fields")?
                .filter(|fields| !fields.is_empty())
                .ok_or_else(|| refuse(name, "a kind needs at least one field"))?;
            let registry = if global { "globals" } else { "collections" };
            // A name is one thing across the app (spec §1.1), whoever declared it.
            for other in [
                "collections",
                "tables",
                "users",
                "taxonomies",
                "globals",
                "actions",
                "jobs",
            ] {
                let taken = match cfg.borrow().get(other) {
                    Some(Value::Object(entries)) => entries.borrow().contains_key(name),
                    _ => false,
                };
                if taken && other != registry {
                    return Err(
                        refuse(name, format!("`{name}` already names one of the project's {other}"))
                            .code(Code::DuplicateExport),
                    );
                }
            }
            let listed = cfg.borrow().get(registry).cloned();
            let declared = match &listed {
                Some(Value::Object(entries)) => entries.borrow().contains_key(name),
                _ => false,
            };
            // Declared in source since the pull: the source's kind is the kind, and the lock's
            // fields are fields added to it.
            if declared {
                out.kinds.push((global, name.clone()));
                for (field, definition) in fields {
                    // A collection's title and body are its post's own columns: source declares them
                    // now, under whatever field it chose, so they are not fields to add.
                    if !global && (field == "title" || field == "content") {
                        continue;
                    }
                    out.fields
                        .push((global, name.clone(), field.clone(), definition.clone()));
                }
                continue;
            }
            out.added.push((global, name.clone()));
            let value = kind(ev, global, name, def, fields)?;
            let entries = match listed {
                Some(entries @ Value::Object(_)) => entries,
                _ => {
                    let entries = Value::object(IndexMap::new());
                    cfg.borrow_mut().insert(registry.to_string(), entries.clone());
                    entries
                }
            };
            let Value::Object(map) = &entries else { unreachable!() };
            map.borrow_mut().insert(name.clone(), value);
        }
    }
    let overrides = object(
        top.get("overrides"),
        "overrides",
        "`{ \"collections\": ..., \"globals\": ... }`",
    )?;
    if let Some(overrides) = overrides {
        keys(overrides, &["collections", "globals"], "overrides")?;
    }
    for (global, section) in [(false, "collections"), (true, "globals")] {
        let kinds = object(overrides.and_then(|o| o.get(section)), section, "an object of kinds")?;
        for (name, entry) in kinds.into_iter().flatten() {
            let entry = entry
                .as_object()
                .ok_or_else(|| refuse(name, "an override is `{ \"label\", \"ui\" }`"))?;
            keys(entry, &["label", "ui"], name)?;
            out.overrides.push((global, name.clone(), entry.clone()));
        }
    }
    Ok(Some(out))
}

/// A `collection()` or `global()` value for a kind the lock adds. The lock holds what `typedpress pull`
/// wrote, so a few shapes are fixed here rather than inferred: a collection's `title` is its
/// required text field and `content`, when present, is its body.
fn kind(
    ev: &mut Evaluator<'_>,
    global: bool,
    name: &str,
    def: &Map<String, Json>,
    fields: &Map<String, Json>,
) -> Result<Value, Diag> {
    if !global {
        // Lower case, so the kind's key is its storage name and its route, as the site stored it.
        if !name.starts_with(|c: char| c.is_ascii_lowercase())
            || !name
                .chars()
                .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_')
        {
            return Err(refuse(
                name,
                "a kind the lock adds has a lower-case name of letters, digits and underscores",
            ));
        }
        let title = fields.get("title").and_then(Json::as_object);
        if !title.is_some_and(|t| {
            t.get("type") == Some(&Json::from("text"))
                && t.get("notNull") == Some(&Json::Bool(true))
                && !t.contains_key("enum")
        }) {
            return Err(refuse(name, "a collection needs `title`: a required text field"));
        }
        if fields
            .get("content")
            .is_some_and(|c| c.get("type") != Some(&Json::from("richText")))
        {
            return Err(refuse(name, "`content` is the body of a collection: a richText field"));
        }
        if fields.contains_key("slug") {
            return Err(refuse(name, "`slug` is built in: every collection item has one"));
        }
    }
    let mut columns = IndexMap::new();
    for (field, definition) in fields {
        columns.insert(
            field.clone(),
            Value::Column(builder(ev, definition, &format!("{name}.{field}"))?),
        );
    }
    let mut options = IndexMap::new();
    match def.get("label") {
        None => {}
        Some(Json::String(label)) if !label.is_empty() => {
            options.insert("label".to_string(), Value::str(label));
        }
        Some(_) => return Err(refuse(name, "`label` must be a string that isn't empty")),
    }
    if let Some(access) = def.get("access") {
        let rules = access
            .as_object()
            .filter(|rules| !rules.is_empty() && rules.values().all(|rule| rule.is_string() || rule.is_boolean()))
            .ok_or_else(|| {
                refuse(
                    name,
                    "`access` maps operations to a role, \"anyone\", \"authenticated\", true or false",
                )
            })?;
        options.insert("access".to_string(), value(&Json::Object(rules.clone())));
    }
    // A global is named by the registry key it is listed under, as an exported one is by its export.
    let args = vec![Value::object(columns), Value::object(options)];
    ev.kind_from_data(global, args)
        .map_err(|d| located(name, d, Code::Evaluation))
}

/// Declares the lock's fields on the kinds `extract` produced, applies its labels and field
/// presentation, and records the version the build contains.
pub fn apply(lock: Option<&Lock>, ev: &mut Evaluator<'_>, ext: &mut Extracted) -> Result<(), Diag> {
    let Some(lock) = lock else { return Ok(()) };
    if ext.schema.runtime_schema.is_none() {
        return Ok(());
    }
    for (global, kind) in &lock.kinds {
        let exists = match global {
            true => ext.schema.globals.contains_key(kind),
            false => ext.schema.collections.contains_key(kind),
        };
        if !exists {
            let what = if *global { "global" } else { "collection" };
            return Err(
                refuse(kind, format!("this project declares no {what} with that name")).help(format!(
                    "declare `{kind}` in the project's schema, or run `typedpress pull` again if the site no longer has it"
                )),
            );
        }
        if !global && !ext.schema.collections[kind].editor {
            return Err(refuse(
                kind,
                "runtime fields belong to collection() kinds; this one stores its rows elsewhere",
            ));
        }
    }
    for (global, kind, name, definition) in &lock.fields {
        let at = format!("{kind}.{name}");
        let column = field(ev, ext, *global, kind, name, definition, &at)?;
        let (declared, relation) = match global {
            true => (ext.schema.globals[kind].fields.get(name), false),
            false => {
                let c = &ext.schema.collections[kind];
                (c.columns.get(name), c.relations.contains_key(name))
            }
        };
        match declared {
            // Declared in source as well, with the same stored definition: one field.
            Some(authored) if definition_of(authored).as_ref() == Some(definition) => {}
            Some(_) => {
                return Err(
                    refuse(&at, format!("`{name}` is already a field of `{kind}`")).help(format!(
                        "the project's source declares `{name}` differently; keep one definition, then run `typedpress pull`"
                    )),
                );
            }
            None if relation => {
                return Err(refuse(&at, format!("`{name}` is already a relation of `{kind}`")));
            }
            None if *global => {
                // `global()` checks this for the fields it is given; a field added afterwards gets the same rule.
                if column.not_null && column.default.is_none() {
                    return Err(refuse(
                        &at,
                        format!("a global field is required unless it has a default (`{name}`)"),
                    ));
                }
                ext.schema
                    .globals
                    .get_mut(kind)
                    .expect("global")
                    .fields
                    .insert(name.clone(), column);
            }
            None => {
                let collection = ext.schema.collections.get_mut(kind).expect("collection");
                collection.columns.insert(name.clone(), column);
            }
        }
    }
    for (global, kind, entry) in &lock.overrides {
        let exists = match global {
            true => ext.schema.globals.contains_key(kind),
            false => ext.schema.collections.contains_key(kind),
        };
        if !exists {
            return Err(refuse(kind, "an override names a kind this project doesn't declare")
                .help("run `typedpress pull` again, or remove the entry from the lock"));
        }
        if !global && !ext.schema.collections[kind].editor {
            return Err(refuse(
                kind,
                format!("`{kind}` is a table(), and tables change only in the project's schema file"),
            ));
        }
        match entry.get("label") {
            None => {}
            Some(Json::String(label)) if !label.is_empty() => match global {
                true => ext.schema.globals.get_mut(kind).expect("global").label = label.clone(),
                false => ext.schema.collections.get_mut(kind).expect("collection").label = label.clone(),
            },
            Some(_) => return Err(refuse(kind, "`label` must be a string that isn't empty")),
        }
        for (name, ui) in object(entry.get("ui"), kind, "an object of fields")?
            .into_iter()
            .flatten()
        {
            let at = format!("{kind}.{name}");
            // Checked as `.ui()` on a field is, by building one that carries it.
            let carrier = serde_json::json!({ "type": "text", "ui": ui });
            let checked = field(ev, ext, *global, kind, "ui", &carrier, &at)?.ui;
            let column = match global {
                true => ext.schema.globals.get_mut(kind).expect("global").fields.get_mut(name),
                false => ext
                    .schema
                    .collections
                    .get_mut(kind)
                    .expect("collection")
                    .columns
                    .get_mut(name),
            };
            match column {
                Some(column) if !column.system && !column.primary_key => column.ui = checked,
                _ => return Err(refuse(&at, format!("`{kind}` has no field `{name}` to present"))),
            }
        }
    }
    storage_collisions(lock, ext)?;
    ext.schema.runtime_schema = Some(RuntimeSchema {
        version: lock.version,
        hash: lock.hash.clone(),
    });
    Ok(())
}

/// A key pattern: literal text, and `None` for one row number (one or more digits).
type KeyPattern = Vec<Option<String>>;

/// Where a field's values are stored (`StorageKeys::of` in the PHP runtime, the same rule): Secure
/// Custom Fields keeps a field `seo` under `seo`, a group's sub-field `title` under `seo_title`, a
/// repeater or blocks field's rows under `<field>_<n>_<sub>` for every row number n, and a blocks
/// field's record of its layouts under `_<field>_layout_meta`.
fn storage_keys(name: &str, column: &Column) -> Vec<KeyPattern> {
    let mut out = vec![vec![Some(name.to_string())]];
    if matches!(column.ty, ColType::Blocks) {
        out.push(vec![Some(format!("{name}_layout_meta"))]);
    }
    let rows = matches!(column.ty, ColType::Repeater | ColType::Blocks);
    let mut nested: Vec<(&String, &Column)> = column.fields.iter().flatten().collect();
    for layout in column.layouts.iter().flat_map(|l| l.values()) {
        nested.extend(layout.iter());
    }
    for (sub, definition) in nested {
        for pattern in storage_keys(sub, definition) {
            let mut key: KeyPattern = Vec::new();
            let head = pattern[0].clone().expect("a key starts with text");
            if rows {
                key.extend([Some(format!("{name}_")), None, Some(format!("_{head}"))]);
            } else {
                key.push(Some(format!("{name}_{head}")));
            }
            key.extend(pattern.into_iter().skip(1));
            out.push(joined(key));
        }
    }
    out
}

fn joined(pattern: KeyPattern) -> KeyPattern {
    let mut out: KeyPattern = Vec::new();
    for part in pattern {
        match (part, out.last_mut()) {
            (Some(text), Some(Some(last))) => last.push_str(&text),
            (part, _) => out.push(part),
        }
    }
    out
}

/// A pattern as lower-case characters, `None` for a row number: MySQL compares meta keys and
/// option names with WordPress's case-insensitive collation, so `Rating` and `rating` are one row.
fn chars(pattern: &KeyPattern) -> Vec<Option<char>> {
    pattern
        .iter()
        .flat_map(|part| match part {
            Some(text) => text.chars().map(|c| Some(c.to_ascii_lowercase())).collect::<Vec<_>>(),
            None => vec![None],
        })
        .collect()
}

/// One key both character patterns produce, or `None`. A row number matches one or more digits.
fn overlap(a: &[Option<char>], b: &[Option<char>], so: &str) -> Option<String> {
    match (a.first(), b.first()) {
        (None, None) => Some(so.to_string()),
        (None, _) | (_, None) => None,
        (Some(None), Some(None)) => overlap(&a[1..], &b[1..], &format!("{so}0")),
        (Some(None), Some(Some(_))) | (Some(Some(_)), Some(None)) => {
            let number_first = a[0].is_none();
            let (number, text) = if number_first { (a, b) } else { (b, a) };
            let mut i = 0;
            while i < text.len() && text[i].is_some_and(|c| c.is_ascii_digit()) {
                let digits: String = text[..=i].iter().map(|c| c.expect("a digit")).collect();
                let found = if number_first {
                    overlap(&number[1..], &text[i + 1..], &format!("{so}{digits}"))
                } else {
                    overlap(&text[i + 1..], &number[1..], &format!("{so}{digits}"))
                };
                if found.is_some() {
                    return found;
                }
                i += 1;
            }
            None
        }
        (Some(Some(x)), Some(Some(y))) if x == y => overlap(&a[1..], &b[1..], &format!("{so}{x}")),
        _ => None,
    }
}

fn shared(a: &[KeyPattern], b: &[KeyPattern]) -> Option<String> {
    a.iter()
        .flat_map(|left| b.iter().map(move |right| (left, right)))
        .find_map(|(left, right)| overlap(&chars(left), &chars(right), ""))
}

fn prefixed(prefix: &str, keys: Vec<KeyPattern>) -> Vec<KeyPattern> {
    keys.into_iter()
        .map(|mut key| {
            match key.first_mut() {
                Some(Some(head)) => *head = format!("{prefix}{head}"),
                _ => key.insert(0, Some(prefix.to_string())),
            }
            key
        })
        .collect()
}

/// A field the lock adds stores its values under keys no other field of its storage uses: a
/// collection's meta keys, and the one option name space every global shares
/// (`tp_global_<global>_<field>`). Fields declared in source are checked against each other by
/// nothing here: this rule is about what a runtime change may add.
fn storage_collisions(lock: &Lock, ext: &Extracted) -> Result<(), Diag> {
    let added = |global: bool, kind: &str, name: &str| {
        lock.added.iter().any(|(g, k)| *g == global && k == kind)
            || lock
                .fields
                .iter()
                .any(|(g, k, f, _)| *g == global && k == kind && f == name)
    };
    let mut keys: Vec<(bool, String, String, Vec<KeyPattern>)> = Vec::new();
    for (kind, c) in &ext.schema.collections {
        for (name, column) in c.columns.iter().filter(|(name, _)| !c.is_core_column(name)) {
            keys.push((false, kind.clone(), name.clone(), storage_keys(name, column)));
        }
    }
    for (kind, g) in &ext.schema.globals {
        for (name, column) in &g.fields {
            keys.push((
                true,
                kind.clone(),
                name.clone(),
                prefixed(&format!("{kind}_"), storage_keys(name, column)),
            ));
        }
    }
    for (i, (global, kind, name, own)) in keys.iter().enumerate() {
        if !added(*global, kind, name) {
            continue;
        }
        for (j, (other_global, other_kind, other_name, theirs)) in keys.iter().enumerate() {
            let same_storage = global == other_global && (*global || kind == other_kind);
            // Each pair once; and two fields that both came from source can't collide here.
            if i == j || !same_storage || (j < i && added(*other_global, other_kind, other_name)) {
                continue;
            }
            if let Some(key) = shared(own, theirs) {
                let place = if *global {
                    format!("the option tp_global_{key}")
                } else {
                    format!("the post meta key tp_{key}")
                };
                return Err(refuse(
                    &format!("{kind}.{name}"),
                    format!(
                        "`{name}` would store values under {place}, which {other_kind}.{other_name} already uses; give the field another name"
                    ),
                ));
            }
        }
    }
    Ok(())
}

/// One top-level lock field as a compiled column.
fn field(
    ev: &mut Evaluator<'_>,
    ext: &mut Extracted,
    global: bool,
    kind: &str,
    name: &str,
    definition: &Json,
    at: &str,
) -> Result<Column, Diag> {
    // The body, the title and the slug are designations a build infers from source; a lock field
    // with one of these names would change meaning the day it moved into source.
    if !global && ["title", "content", "slug"].contains(&name) {
        return Err(refuse(
            at,
            format!("`{name}` names a designated field; declare it in source"),
        ));
    }
    let builder = builder(ev, definition, at)?;
    crate::extract::lock_column(name, &builder, global, kind, ev, ext).map_err(|d| located(at, d, Code::Schema))
}

/// The builder an author would have written for a stored definition. Checks the definition's shape
/// only; every rule about what a field may be is the evaluator's and the extractor's.
fn builder(ev: &Evaluator<'_>, definition: &Json, at: &str) -> Result<std::rc::Rc<crate::value::ColBuilder>, Diag> {
    let def = definition
        .as_object()
        .ok_or_else(|| refuse(at, "a field definition must be an object"))?;
    for key in def.keys() {
        if !FIELD_KEYS.contains(&key.as_str()) {
            return Err(refuse(at, format!("a stored field has no key `{key}`")));
        }
    }
    let ty = def
        .get("type")
        .and_then(Json::as_str)
        .ok_or_else(|| refuse(at, "a field definition needs a `type`"))?;
    let flag = |key: &str| match def.get(key) {
        None => Ok(false),
        Some(Json::Bool(on)) => Ok(*on),
        Some(_) => Err(refuse(at, format!("`{key}` must be true or false"))),
    };
    let only = |key: &str, types: &[&str]| {
        if def.contains_key(key) && !types.contains(&ty) {
            return Err(refuse(at, format!("`{key}` doesn't apply to a `{ty}` field")));
        }
        Ok(())
    };
    only("multiline", &["text"])?;
    only("accept", &["media", "mediaList"])?;
    only("enum", &["text", "select"])?;
    only("fields", &["group", "repeater"])?;
    only("layouts", &["blocks"])?;
    if flag("multiple")? != (ty == "select") {
        return Err(refuse(
            at,
            "`multiple` is true on a `select` field and absent elsewhere",
        ));
    }
    let options = match def.get("enum") {
        None if ty == "select" => return Err(refuse(at, "a `select` field needs `enum`")),
        None => None,
        Some(Json::Array(items)) if items.iter().all(Json::is_string) => {
            Some(Value::array(items.iter().map(value).collect()))
        }
        // PHP reads `{}` and `[]` as the same empty array, so both are the empty list here too.
        Some(Json::Object(items)) if items.is_empty() => Some(Value::array(Vec::new())),
        Some(_) => return Err(refuse(at, "`enum` must be a list of strings")),
    };
    if options.is_some() && def.contains_key("multiline") {
        return Err(refuse(at, "`multiline` doesn't apply to a field with `enum`"));
    }
    let fields = |fields: Option<&Json>, at: &str| -> Result<Value, Diag> {
        let fields = fields
            .and_then(Json::as_object)
            .filter(|fields| !fields.is_empty())
            .ok_or_else(|| refuse(at, "needs at least one field"))?;
        let mut out = IndexMap::new();
        for (name, sub) in fields {
            out.insert(name.clone(), Value::Column(builder(ev, sub, &format!("{at}.{name}"))?));
        }
        Ok(Value::object(out))
    };
    let mut many = false;
    let (name, args): (&'static str, Vec<Value>) = match ty {
        "text" if options.is_some() => ("select", vec![options.expect("options")]),
        "text" if flag("multiline")? => (
            "text",
            vec![Value::object(IndexMap::from([(
                "multiline".to_string(),
                Value::Bool(true),
            )]))],
        ),
        "text" => ("text", vec![]),
        "select" => {
            many = true;
            ("select", vec![options.expect("options")])
        }
        "integer" => ("integer", vec![]),
        "real" => ("number", vec![]),
        "boolean" => ("boolean", vec![]),
        "date" => ("date", vec![]),
        "timestamp" => ("timestamp", vec![]),
        "json" => ("json", vec![]),
        "email" => ("email", vec![]),
        "url" => ("url", vec![]),
        "richText" => ("richText", vec![]),
        "media" | "mediaList" => {
            many = ty == "mediaList";
            match def.get("accept") {
                None => ("media", vec![]),
                Some(accept @ Json::String(_)) => (
                    "media",
                    vec![Value::object(IndexMap::from([("accept".to_string(), value(accept))]))],
                ),
                Some(_) => return Err(refuse(at, "`accept` is \"image\" or \"any\"")),
            }
        }
        "group" => ("group", vec![fields(def.get("fields"), at)?]),
        "repeater" => ("repeater", vec![fields(def.get("fields"), at)?]),
        "blocks" => {
            let Some(layouts) = def.get("layouts").and_then(Json::as_object).filter(|l| !l.is_empty()) else {
                return Err(refuse(at, "a `blocks` field needs at least one layout in `layouts`"));
            };
            let mut out = IndexMap::new();
            for (layout, sub) in layouts {
                out.insert(layout.clone(), fields(Some(sub), &format!("{at}.{layout}"))?);
            }
            ("blocks", vec![Value::object(out)])
        }
        other => {
            return Err(refuse(
                at,
                format!("`{other}` fields can't be stored in the lock; declare it in source"),
            ));
        }
    };
    let mut modifiers: Vec<(&str, Vec<Value>)> = Vec::new();
    if many {
        modifiers.push(("many", vec![]));
    }
    if !flag("notNull")? {
        modifiers.push(("optional", vec![]));
    }
    if let Some(rules) = def.get("rules") {
        let rules = rules
            .as_object()
            .filter(|rules| !rules.is_empty() && rules.keys().all(|key| key == "min" || key == "max"))
            .ok_or_else(|| refuse(at, "`rules` holds `min` and `max` only"))?;
        for key in ["min", "max"] {
            match rules.get(key) {
                None => {}
                Some(n @ Json::Number(_)) => modifiers.push((key, vec![value(n)])),
                Some(_) => return Err(refuse(at, format!("`rules.{key}` must be a number"))),
            }
        }
    }
    if let Some(default) = def.get("default") {
        let default = default
            .as_object()
            .filter(|default| default.len() == 1)
            .ok_or_else(|| refuse(at, "`default` is `{ \"value\": ... }` or `{ \"now\": true }`"))?;
        match (default.get("value"), default.get("now")) {
            // One value: `schema.php` writes a list or object default in a second form as well, which
            // a stored definition doesn't carry. Those are declared in source.
            (Some(literal), None) if literal.is_boolean() || literal.is_number() || literal.is_string() => {
                modifiers.push(("default", vec![value(literal)]));
            }
            (None, Some(Json::Bool(true))) => modifiers.push(("defaultNow", vec![])),
            _ => {
                return Err(refuse(
                    at,
                    "`default` is `{ \"value\": ... }` with one string, number or boolean, or `{ \"now\": true }`",
                ));
            }
        }
    }
    // An empty `ui` is no `ui` (PHP can't tell `{}` from `[]`).
    let empty = |v: &Json| v.as_object().is_some_and(Map::is_empty) || v.as_array().is_some_and(Vec::is_empty);
    if let Some(ui) = def.get("ui").filter(|ui| !empty(ui)) {
        modifiers.push(("ui", vec![value(ui)]));
    }
    ev.field_from_data(name, &args, &modifiers, Span::NONE)
        .map_err(|d| located(at, d, Code::Evaluation))
}

fn value(json: &Json) -> Value {
    match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => Value::Num(n.as_f64().unwrap_or(f64::NAN)),
        Json::String(s) => Value::str(s),
        Json::Array(items) => Value::array(items.iter().map(value).collect()),
        Json::Object(map) => Value::object(map.iter().map(|(k, v)| (k.clone(), value(v))).collect()),
    }
}

/// Which of the build's fields its compiled code can name: `tp/app/references.php`.
///
/// The compiler keeps no record of which row fields a hook, action, job or page reads, so this is
/// the safe approximation: a field is "referenced" when its name occurs as a word anywhere in a
/// source file the build loaded: the project's own and every plugin package it imports. Generated
/// files and the lock don't count. A name that occurs nowhere can't be read by compiled code except
/// through a key computed at run time, and that reads "no value", which an optional field's type
/// already allows.
///
/// So a field of the build may be hidden on the running site only when it is optional and not in
/// this list; in practice, a field that entered the build through the lock and that no code uses
/// yet. The site reads the file when a change is proposed and when a removed name is declared by
/// the build it runs, never otherwise.
pub fn references(schema: &crate::schema::Schema, root: &Path) -> Json {
    let lock = root.join(LOCK);
    let generated = root.join("tp/_generated");
    let sources = crate::diag::sources(|path| !path.starts_with(&generated) && path != lock);
    let mut words = std::collections::HashSet::new();
    for source in &sources {
        let mut word = String::new();
        for c in source.chars().chain([' ']) {
            if c.is_ascii_alphanumeric() || c == '_' || c == '$' {
                word.push(c);
            } else if !word.is_empty() {
                words.insert(std::mem::take(&mut word));
            }
        }
    }
    let named = |fields: Vec<&String>| -> Json {
        let mut names: Vec<&String> = fields.into_iter().filter(|name| words.contains(*name)).collect();
        names.sort();
        Json::from(names.into_iter().cloned().collect::<Vec<_>>())
    };
    let mut collections = Map::new();
    for (name, c) in &schema.collections {
        collections.insert(name.clone(), named(c.columns.keys().collect()));
    }
    let mut globals = Map::new();
    for (name, g) in &schema.globals {
        globals.insert(name.clone(), named(g.fields.keys().collect()));
    }
    let mut kinds: Vec<&String> = schema
        .collections
        .iter()
        .map(|(name, c)| (name, &c.key))
        .filter(|(name, key)| words.contains(*name) || words.contains(*key))
        .map(|(name, _)| name)
        .chain(schema.globals.keys().filter(|name| words.contains(*name)))
        .collect();
    kinds.sort();
    serde_json::json!({ "format": FORMAT, "kinds": kinds, "collections": collections, "globals": globals })
}

/// A compiled column as the lock stores it: the array `schema.php` holds for it, as JSON. `None`
/// for a column the lock can't express (a function, a reference, an access rule, ...), which is
/// therefore never equal to a stored definition.
pub fn definition_of(column: &Column) -> Option<Json> {
    if column.primary_key
        || column.auto_increment
        || column.unique
        || column.system
        || column.localized
        || column.derived
        || column.hidden
        || column.source.is_some()
        || column.on_update.is_some()
        || column.references.is_some()
        || column.rules.pattern.is_some()
        || column.rules.validate.is_some()
        || column.show_if.is_some()
        || column.searchable.is_some()
        || column.bucket.is_some()
        || column.was.is_some()
        || !column.access.is_empty()
        || matches!(column.ty, ColType::Slug | ColType::File)
    {
        return None;
    }
    let number = |n: f64| {
        if crate::num::is_safe_int(n) {
            Json::from(n as i64)
        } else {
            Json::from(n)
        }
    };
    let fields = |fields: &IndexMap<String, Column>| -> Option<Json> {
        let mut out = Map::new();
        for (name, sub) in fields {
            out.insert(name.clone(), definition_of(sub)?);
        }
        Some(Json::Object(out))
    };
    let options = column.enum_values.as_ref().map(|options| Json::from(options.clone()));
    let mut out = Map::new();
    out.insert("type".into(), column.ty.name().into());
    if column.ty == ColType::Select {
        out.insert("multiple".into(), true.into());
        out.insert("enum".into(), options.clone()?);
    }
    if column.multiline {
        out.insert("multiline".into(), true.into());
    }
    if let Some(accept) = &column.accept {
        out.insert("accept".into(), accept.clone().into());
    }
    if column.not_null {
        out.insert("notNull".into(), true.into());
    }
    if column.ty != ColType::Select
        && let Some(options) = options
    {
        out.insert("enum".into(), options);
    }
    match &column.default {
        None => {}
        Some(DefaultKind::Now) => {
            out.insert("default".into(), serde_json::json!({ "now": true }));
        }
        Some(DefaultKind::Value(literal)) if literal.is_boolean() || literal.is_number() || literal.is_string() => {
            out.insert("default".into(), serde_json::json!({ "value": literal }));
        }
        Some(_) => return None,
    }
    if !column.rules.is_empty() {
        let mut rules = Map::new();
        if let Some(min) = column.rules.min {
            rules.insert("min".into(), number(min));
        }
        if let Some(max) = column.rules.max {
            rules.insert("max".into(), number(max));
        }
        out.insert("rules".into(), Json::Object(rules));
    }
    if let Some(sub) = &column.fields {
        out.insert("fields".into(), fields(sub)?);
    }
    if let Some(layouts) = &column.layouts {
        let mut map = Map::new();
        for (layout, sub) in layouts {
            map.insert(layout.clone(), fields(sub)?);
        }
        out.insert("layouts".into(), Json::Object(map));
    }
    if let Some(ui) = column
        .ui
        .as_ref()
        .filter(|ui| **ui != crate::schema::FieldUi::default())
    {
        out.insert("ui".into(), serde_json::to_value(ui).expect("field UI serializes"));
    }
    Some(Json::Object(out))
}

/// A compiled collection or global as a stored kind (`{ label?, access?, fields }`), given the
/// label and access the definition asked for: what the site keeps for a kind it added.
#[cfg(test)]
fn stored_kind(schema: &crate::schema::Schema, global: bool, name: &str, asked: &Json) -> Option<Json> {
    let mut out = Map::new();
    if let Some(label) = asked.get("label") {
        out.insert("label".into(), label.clone());
    }
    let (fields, access): (Vec<&Column>, &IndexMap<String, crate::schema::Access>) = if global {
        let g = &schema.globals[name];
        (g.fields.values().collect(), &g.access)
    } else {
        let c = &schema.collections[name];
        (
            c.columns
                .values()
                .filter(|col| !col.system && !col.primary_key)
                .collect(),
            &c.access,
        )
    };
    if let Some(asked) = asked.get("access").and_then(Json::as_object) {
        let mut rules = Map::new();
        for (op, rule) in access {
            if asked.contains_key(op) {
                rules.insert(
                    op.clone(),
                    match rule {
                        crate::schema::Access::Allow(allow) => Json::Bool(*allow),
                        crate::schema::Access::Role(role) => Json::from(role.clone()),
                        _ => return None,
                    },
                );
            }
        }
        out.insert("access".into(), Json::Object(rules));
    }
    let mut map = Map::new();
    for column in fields {
        map.insert(column.name.clone(), definition_of(column)?);
    }
    out.insert("fields".into(), Json::Object(map));
    Some(Json::Object(out))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::pipeline::{self, Previous};
    use std::collections::BTreeMap;
    use std::path::{Path, PathBuf};
    use std::rc::Rc;

    const CORPUS: &str = include_str!("../tests/runtime-schema/corpus.json");
    const CONFIG: &str = include_str!("../tests/runtime-schema/base/typedpress.config.ts");
    const SCHEMA: &str = include_str!("../tests/runtime-schema/base/tp/schema.ts");

    /// Compiles the corpus base project with `lock` (None: no lock file).
    fn build(lock: Option<&Json>) -> Result<crate::schema::Schema, Diag> {
        built(lock).map(|built| built.schema)
    }

    fn built(lock: Option<&Json>) -> Result<pipeline::Built, Diag> {
        let project = Path::new("/rtschema");
        let mut files = BTreeMap::from([
            (project.join("typedpress.config.ts"), CONFIG.to_string()),
            (project.join("tp/schema.ts"), SCHEMA.to_string()),
        ]);
        if let Some(lock) = lock {
            files.insert(project.join(LOCK), lock.to_string());
        }
        crate::diag::isolated(|| {
            let fs = Rc::new(crate::wasm::Memory::new(files));
            let program = crate::loader::load_from(fs, &project.join("typedpress.config.ts"))
                .map_err(|diagnostics| diagnostics.into_iter().next().expect("a diagnostic"))?;
            pipeline::build(project, &program, || Ok(Previous::none()), |_| None)
        })
    }

    fn lock_of(kind: &str, field: &str, definition: &Json) -> Json {
        serde_json::json!({ "format": FORMAT, "version": 1, "hash": "h", "collections": { kind: { "columns": { field: definition } } } })
    }

    fn code(diag: &Diag) -> String {
        serde_json::to_value(diag.code)
            .expect("code")
            .as_str()
            .expect("code")
            .to_string()
    }

    /// The compiler's half of the shared corpus: every case it can express gets the verdict and the
    /// diagnostic code the corpus records, and an accepted field compiles to exactly its `stored`
    /// definition. `tests/php/runtimeschema_corpus.php` holds the runtime to the same file.
    #[test]
    fn the_shared_corpus_gets_the_recorded_verdicts_from_the_compiler() {
        let corpus: Json = serde_json::from_str(CORPUS).expect("corpus.json");
        let mut checked = 0;
        for case in corpus["cases"].as_array().expect("cases") {
            let name = case["name"].as_str().expect("name");
            let (kind, field) = (
                case["kind"].as_str().expect("kind"),
                case["field"].as_str().expect("field"),
            );
            // No `compiler`: a change only the running site judges (a field the lock can't hold,
            // which the site refuses as build-only). `runtime` records its verdict.
            let Some(expected) = case.get("compiler") else {
                assert!(case.get("runtime").is_some(), "{name}: needs `compiler` or `runtime`");
                continue;
            };
            checked += 1;
            let result = build(Some(&lock_of(kind, field, &case["definition"])));
            match (expected.as_str(), result) {
                (Some("accept"), Ok(schema)) => {
                    let column = &schema.collections[kind].columns[field];
                    assert_eq!(
                        definition_of(column).as_ref(),
                        Some(&case["stored"]),
                        "{name}: compiled to another definition than the one stored"
                    );
                }
                (Some("accept"), Err(diag)) => panic!("{name}: refused: {} {}", code(&diag), diag.message),
                (Some(expected), Err(diag)) => assert_eq!(code(&diag), expected, "{name}: {}", diag.message),
                (Some(expected), Ok(_)) => panic!("{name}: accepted, expected {expected}"),
                (None, _) => panic!("{name}: `compiler` must be \"accept\" or a diagnostic code"),
            }
        }
        assert!(checked >= 40, "the corpus lost its cases: {checked}");
    }

    fn lock_adding(global: bool, name: &str, definition: &Json) -> Json {
        let section = if global { "globals" } else { "collections" };
        serde_json::json!({ "format": FORMAT, "version": 1, "hash": "h", "added": { section: { name: definition } } })
    }

    /// The same, for whole collections and globals the site adds.
    #[test]
    fn the_corpus_of_added_kinds_gets_the_recorded_verdicts_from_the_compiler() {
        let corpus: Json = serde_json::from_str(CORPUS).expect("corpus.json");
        let mut checked = 0;
        for case in corpus["kinds"].as_array().expect("kinds") {
            let name = case["name"].as_str().expect("name");
            let global = case["op"] == "addGlobal";
            let kind = case["name_"].as_str().expect("name_");
            checked += 1;
            let result = build(Some(&lock_adding(global, kind, &case["definition"])));
            match (case["compiler"].as_str().expect("compiler"), result) {
                ("accept", Ok(schema)) => {
                    if let Some(stored) = case.get("stored") {
                        assert_eq!(
                            stored_kind(&schema, global, kind, stored).as_ref(),
                            Some(stored),
                            "{name}: compiled to another kind than the one stored"
                        );
                    }
                }
                ("accept", Err(diag)) => panic!("{name}: refused: {} {}", code(&diag), diag.message),
                (expected, Err(diag)) => assert_eq!(code(&diag), expected, "{name}: {}", diag.message),
                (expected, Ok(_)) => panic!("{name}: accepted, expected {expected}"),
            }
        }
        assert!(checked >= 40, "the corpus lost its kinds: {checked}");
    }

    /// The runtime builds the `schema.php` array of a kind it adds itself (`Kinds::expandCollection`
    /// and `expandGlobal` in the PHP runtime). This is what the compiler emits for the same kinds;
    /// tests/php/runtimeschema_corpus.php compares the two.
    #[test]
    fn the_schema_the_runtime_expands_added_kinds_into_is_the_compilers() {
        let lock: Json = serde_json::from_str(include_str!("../tests/runtime-schema/kinds.lock.json")).expect("lock");
        let schema = build(Some(&lock)).unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(
            crate::schema_php::render(&schema),
            include_str!("../tests/runtime-schema/kinds.schema.php"),
            "tests/runtime-schema/kinds.schema.php drifted: copy tests/runtime-schema/kinds.lock.json to \
             tp/_generated/runtime-schema.json in a copy of tests/runtime-schema/base, run `typedpress build` there, \
             and copy tp/dist/wp-content/plugins/typedpress-app-rtschema/tp/app/schema.php over it"
        );
    }

    #[test]
    fn the_runtimes_copy_of_the_reserved_global_names_is_the_compilers() {
        let php = include_str!("../runtime/TypedPress/RuntimeSchema/ReservedNames.php");
        let listed: Vec<&str> = php
            .split_once("CLIENT_TYPES = [")
            .and_then(|(_, rest)| rest.split_once("];"))
            .expect("the CLIENT_TYPES list")
            .0
            .split(',')
            .map(|name| name.trim().trim_matches('\''))
            .filter(|name| !name.is_empty())
            .collect();
        assert_eq!(listed, crate::client::RESERVED_TYPE_NAMES);
    }

    #[test]
    fn a_kind_the_lock_adds_that_source_now_declares_is_the_sources_kind() {
        // `articles` is authored; the lock still lists it as added, with one field source lacks.
        let lock = lock_adding(
            false,
            "articles",
            // What `typedpress pull` writes for a collection the site added: its title, and here its body.
            &serde_json::json!({ "fields": {
                "title": { "type": "text", "notNull": true },
                "body": { "type": "richText" },
                "rating": { "type": "integer" },
                "subtitle": { "type": "text" }
            } }),
        );
        let schema = build(Some(&lock)).unwrap_or_else(|d| panic!("{}", d.message));
        let columns: Vec<&str> = schema.collections["articles"]
            .columns
            .keys()
            .map(String::as_str)
            .collect();
        assert_eq!(
            columns,
            [
                "id",
                "title",
                "body",
                "rating",
                "seo",
                "links",
                "page",
                "status",
                "publishedAt",
                "author",
                "createdAt",
                "updatedAt",
                "deletedAt",
                "deletedBy",
                "deletedGroup",
                "subtitle"
            ]
        );
    }

    #[test]
    fn a_lock_field_is_refused_where_a_source_field_would_write_its_keys() {
        // The site stores `seo_title` and `page_layout_meta` as fields of their own; source declares
        // a group `seo` and a blocks field `page`, which write `tp_seo_title` and
        // `_tp_page_layout_meta`. Keys differ only in case on MySQL's collation too.
        for field in ["seo_title", "page_layout_meta", "SEO_TITLE", "Rating"] {
            let lock = serde_json::json!({ "format": FORMAT, "version": 1, "hash": "h", "collections": {
                "articles": { "columns": { field: { "type": "integer" } } } } });
            let refused = build(Some(&lock)).expect_err(field);
            assert!(
                refused.message.contains("would store values under"),
                "{field}: {}",
                refused.message
            );
        }
    }

    #[test]
    fn labels_and_presentation_from_the_lock_replace_the_declared_ones() {
        let lock = serde_json::json!({ "format": FORMAT, "version": 3, "hash": "h", "overrides": { "collections": {
            "articles": { "label": "Stories", "ui": { "rating": { "label": "Stars", "width": 50 } } } } } });
        let schema = build(Some(&lock)).unwrap_or_else(|d| panic!("{}", d.message));
        let articles = &schema.collections["articles"];
        assert_eq!(articles.label, "Stories");
        assert_eq!(
            serde_json::to_value(&articles.columns["rating"].ui).unwrap(),
            serde_json::json!({ "label": "Stars", "width": 50 })
        );
        let table = serde_json::json!({ "format": FORMAT, "version": 3, "hash": "h", "overrides": { "collections": {
            "votes": { "label": "Ballots" } } } });
        let refused = build(Some(&table)).expect_err("a table's label is build-only");
        assert!(refused.message.contains("`votes` is a table()"), "{}", refused.message);
        for (ui, expected) in [
            (serde_json::json!({ "rating": { "width": 500 } }), "F3101"),
            (serde_json::json!({ "rating": { "colour": "red" } }), "F3101"),
            (serde_json::json!({ "nope": { "label": "x" } }), "F3001"),
            (serde_json::json!({ "id": { "label": "x" } }), "F3001"),
        ] {
            let lock = serde_json::json!({ "format": FORMAT, "version": 3, "hash": "h", "overrides": { "collections": { "articles": { "ui": ui } } } });
            assert_eq!(code(&build(Some(&lock)).expect_err("a bad override")), expected, "{ui}");
        }
    }

    #[test]
    fn a_stored_definition_is_what_the_site_stored_so_the_canonical_form_round_trips() {
        let corpus: Json = serde_json::from_str(CORPUS).expect("corpus.json");
        for case in corpus["cases"].as_array().expect("cases") {
            if case.get("compiler").and_then(Json::as_str) != Some("accept") {
                continue;
            }
            let (kind, field) = (
                case["kind"].as_str().expect("kind"),
                case["field"].as_str().expect("field"),
            );
            let schema =
                build(Some(&lock_of(kind, field, &case["stored"]))).unwrap_or_else(|d| panic!("{}", d.message));
            assert_eq!(
                definition_of(&schema.collections[kind].columns[field]).as_ref(),
                Some(&case["stored"]),
                "{}",
                case["name"]
            );
        }
    }

    /// The runtime's half of the corpus runs on this file (tests/php/runtimeschema_corpus.php and
    /// runtimeschema_test.php), so it must be what the compiler emits for the base project today.
    #[test]
    fn the_compiled_schema_the_runtime_suites_use_is_the_base_projects() {
        let schema = build(None).unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(
            crate::schema_php::render(&schema),
            include_str!("../tests/runtime-schema/base.schema.php"),
            "tests/runtime-schema/base.schema.php drifted: run `typedpress build` on a copy of tests/runtime-schema/base \
             and copy tp/dist/wp-content/plugins/typedpress-app-rtschema/tp/app/schema.php over it"
        );
    }

    #[test]
    fn without_the_flag_the_lock_is_not_read() {
        let project = PathBuf::from("/rtschema");
        let files = BTreeMap::from([
            (
                project.join("typedpress.config.ts"),
                CONFIG.replace("runtimeSchema: true", "runtimeSchema: false"),
            ),
            (project.join("tp/schema.ts"), SCHEMA.to_string()),
            (project.join(LOCK), "not json".to_string()),
        ]);
        let schema = crate::diag::isolated(|| {
            let program = crate::loader::load_from(
                Rc::new(crate::wasm::Memory::new(files)),
                &project.join("typedpress.config.ts"),
            )
            .map_err(|d| d.into_iter().next().expect("a diagnostic"))?;
            pipeline::build(&project, &program, || Ok(Previous::none()), |_| None).map(|built| built.schema)
        })
        .unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(schema.runtime_schema, None);
        assert!(!crate::schema_php::render(&schema).contains("runtimeSchema"));
    }

    #[test]
    fn the_build_records_the_version_it_contains() {
        let schema = build(None).unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(schema.runtime_schema, Some(RuntimeSchema::default()));
        let lock = serde_json::json!({ "format": FORMAT, "version": 7, "hash": "abc", "collections": {
            "articles": { "columns": { "subtitle": { "type": "text", "rules": { "max": 120 } } } } } });
        let schema = build(Some(&lock)).unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(
            schema.runtime_schema,
            Some(RuntimeSchema {
                version: 7,
                hash: "abc".into()
            })
        );
        let php = crate::schema_php::render(&schema);
        assert!(
            php.contains("'runtimeSchema' => ['version' => 7, 'hash' => 'abc'],"),
            "{php}"
        );
        assert!(
            php.contains("'subtitle' => ['type' => 'text', 'rules' => ['max' => 120]],"),
            "{php}"
        );
    }

    /// What an editor's types and the typed client are generated from: a lock field is a field.
    #[test]
    fn a_lock_field_reaches_the_generated_types_and_plans_no_migration() {
        let before = built(None).unwrap_or_else(|d| panic!("{}", d.message));
        let lock = lock_of("articles", "subtitle", &serde_json::json!({ "type": "text" }));
        let after = built(Some(&lock)).unwrap_or_else(|d| panic!("{}", d.message));
        assert!(!before.gen_ts.contains("subtitle"), "{}", before.gen_ts);
        assert!(
            after
                .gen_ts
                .contains("'subtitle': ColumnBuilder<'text', string, false, false, false, false>;"),
            "{}",
            after.gen_ts
        );
        // A collection's field is post meta: the SQL and operations the two builds plan are the same
        // (migration IDs carry wall-clock timestamps, so compare SQL + ops payloads).
        assert_eq!(before.migrations.len(), after.migrations.len());
        for (a, b) in before.migrations.iter().zip(after.migrations.iter()) {
            assert_eq!(a.sql, b.sql);
            assert_eq!(a.ops["ops"], b.ops["ops"]);
        }
    }

    #[test]
    fn a_field_declared_in_source_and_in_the_lock_is_one_field_when_both_agree() {
        // `rating` is `integer().optional()` in the base project.
        let same = lock_of("articles", "rating", &serde_json::json!({ "type": "integer" }));
        let schema = build(Some(&same)).unwrap_or_else(|d| panic!("{}", d.message));
        assert_eq!(
            schema.collections["articles"]
                .columns
                .keys()
                .filter(|k| *k == "rating")
                .count(),
            1
        );
        let other = lock_of("articles", "rating", &serde_json::json!({ "type": "text" }));
        let refused = build(Some(&other)).expect_err("a different definition is refused");
        assert_eq!(code(&refused), "F3001");
        assert!(
            refused.message.contains("`rating` is already a field of `articles`"),
            "{}",
            refused.message
        );
    }

    #[test]
    fn a_lock_field_named_like_a_relation_of_its_kind_is_refused() {
        let project = PathBuf::from("/rtschema");
        // `ref(articles)` on a table gives `articles` the reverse relation `votes`.
        let schema = "import { collection, table, text, ref } from \"typedpress\";\n\
            export const articles = collection({ title: text() });\n\
            export const votes = table({ article: ref(articles) });\n";
        let config = "import { defineConfig } from \"typedpress\";\n\
            export default defineConfig({ name: \"rtschema\", runtimeSchema: true });\n";
        let lock = lock_of("articles", "votes", &serde_json::json!({ "type": "text" }));
        let files = BTreeMap::from([
            (project.join("typedpress.config.ts"), config.to_string()),
            (project.join("tp/schema.ts"), schema.to_string()),
            (project.join(LOCK), lock.to_string()),
        ]);
        let refused = crate::diag::isolated(|| {
            let program = crate::loader::load_from(
                Rc::new(crate::wasm::Memory::new(files)),
                &project.join("typedpress.config.ts"),
            )
            .map_err(|d| d.into_iter().next().expect("a diagnostic"))?;
            pipeline::build(&project, &program, || Ok(Previous::none()), |_| None).map(|built| built.schema)
        })
        .expect_err("a field named like a relation");
        assert_eq!(code(&refused), "F3001", "{}", refused.message);
        assert!(
            refused.message.contains("`votes` is already a relation of `articles`"),
            "{}",
            refused.message
        );
    }

    #[test]
    fn a_lock_this_compiler_cannot_read_is_refused_with_the_command_that_rewrites_it() {
        for (lock, expected) in [
            (
                serde_json::json!({ "format": 2, "version": 1, "hash": "h" }),
                "reads format 1, not 2",
            ),
            (
                serde_json::json!({ "format": 1, "hash": "h" }),
                "version: must be the site's schema version number",
            ),
            (
                serde_json::json!({ "format": 1, "version": 1, "hash": "h", "collections": { "missing": { "columns": {} } } }),
                "missing: this project declares no collection with that name",
            ),
            (
                serde_json::json!({ "format": 1, "version": 1, "hash": "h", "collections": { "votes": { "columns": {} } } }),
                "votes: runtime fields belong to collection() kinds",
            ),
            (
                serde_json::json!({ "format": 1, "version": 1, "hash": "h", "extra": 1 }),
                "has no key `extra`",
            ),
        ] {
            let refused = build(Some(&lock)).expect_err(expected);
            assert_eq!(code(&refused), "F3001", "{}", refused.message);
            assert!(refused.message.contains(expected), "{}", refused.message);
        }
    }
}
