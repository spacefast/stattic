//! `sp/_generated/openapi.json`: the app's HTTP API (`/wp-json/spacepress/v1`, docs/spec/spec.md §3a) as an
//! OpenAPI 3.1 document. It is built from the evaluated [`Schema`], the data that also produces
//! schema.php (which the runtime turns into Ability schemas) and the typed client:
//!
//! - a kind's row, create input and patch are [`crate::json_schema`]'s, the same shapes the
//!   Abilities publish;
//! - component names are the typed client's interface names (`Post`, `PostInsert`, `PostPatch`,
//!   `PostFilter`), and an operation id names it as the client's call for the request does
//!   (`posts.list`), where the client has one;
//! - an operation exists exactly when the facade serves it: a kind's `api` flags, `revisions`,
//!   `sync`, a `readonly` action's `GET`.
//!
//! The running site serves the document at `/wp-json/spacepress/v1/openapi.json` (`SpacePress\OpenApi`).

use crate::json_schema as js;
use crate::schema::{Access, Action, ColType, Collection, Column, FileBucket, Global, RelKind, Schema};
use indexmap::IndexMap;
use serde_json::{Map, Value, json};
use std::collections::HashSet;

/// Where the facade serves the API, below the site's home URL.
pub const PREFIX: &str = "/wp-json/spacepress/v1";
/// The document's path below [`PREFIX`].
pub const ROUTE: &str = "/openapi.json";
/// A list's default and largest page (`Wp\Facade::LIMIT`, `MAX_LIMIT`).
const LIMIT: u32 = 50;
const MAX_LIMIT: u32 = 100;

/// Every way a request can sign in (spec §3a: what WordPress core accepts).
const SCHEMES: [&[&str]; 3] = [&["cookie", "nonce"], &["applicationPassword"], &["bearer"]];

/// Reads one `POST /_live` takes: `Live\Module::MAX_QUERIES` in the runtime. The OpenAPI test
/// sends that many and one more.
const LIVE_MAX_QUERIES: usize = 20;

fn reference(component: &str) -> Value {
    json!({ "$ref": format!("#/components/schemas/{component}") })
}

fn problem() -> Value {
    json!({ "$ref": "#/components/responses/Problem" })
}

/// `{ data: <schema> }`, the envelope of every JSON answer.
fn envelope(description: &str, schema: Value) -> Value {
    json!({
        "description": description,
        "content": { "application/json": { "schema": {
            "type": "object", "properties": { "data": schema }, "required": ["data"],
        } } },
    })
}

fn body(description: &str, schema: Value) -> Value {
    json!({ "required": true, "description": description, "content": { "application/json": { "schema": schema } } })
}

fn upload(description: &str, extra: Value) -> Value {
    let mut properties = json!({ "file": { "type": "string", "format": "binary" } });
    for (name, schema) in extra.as_object().into_iter().flatten() {
        properties[name] = schema.clone();
    }
    json!({
        "required": true,
        "description": description,
        "content": { "multipart/form-data": { "schema": { "type": "object", "properties": properties, "required": ["file"] } } },
    })
}

fn path_param(name: &str, description: &str, schema: Value) -> Value {
    json!({ "name": name, "in": "path", "required": true, "description": description, "schema": schema })
}

fn query(name: &str, description: &str, schema: Value) -> Value {
    json!({ "name": name, "in": "query", "description": description, "schema": schema })
}

/// A query argument that carries JSON (`where={"status":"published"}`).
fn json_query(name: &str, description: &str, schema: Value) -> Value {
    json!({ "name": name, "in": "query", "description": description, "content": { "application/json": { "schema": schema } } })
}

/// One of the paging parameters every list takes (`components.parameters`).
fn paging(name: &str) -> Value {
    json!({ "$ref": format!("#/components/parameters/{name}") })
}

fn paging_parameters() -> Value {
    json!({
        "limit": query("limit", &format!("How many rows to return: {LIMIT} unless given, and never more than {MAX_LIMIT}."), json!({ "type": "integer", "minimum": 1, "default": LIMIT })),
        "offset": query("offset", "How many rows to skip.", json!({ "type": "integer", "minimum": 0 })),
        "cursor": query("cursor", "The `cursor` of the previous page, to read the next one. It replaces `offset`.", json!({ "type": "string" })),
    })
}

/// `{ data: rows, cursor }`: one page of a list.
fn page(row: Value) -> Value {
    json!({
        "description": "One page of rows.",
        "content": { "application/json": { "schema": {
            "type": "object",
            "properties": { "data": { "type": "array", "items": row }, "cursor": { "type": ["string", "null"] } },
            "required": ["data", "cursor"],
        } } },
    })
}

fn idempotency_key(required: bool) -> Value {
    json!({
        "name": "Idempotency-Key", "in": "header", "required": required,
        "description": "A key of 1 to 64 characters. A repeat of the request with the same key and body answers with the first result instead of running again; the same key with another body is refused.",
        "schema": { "type": "string", "minLength": 1, "maxLength": 64 },
    })
}

/// How an access rule reads in the document: the short name the app's schema uses
/// (`Schema::accessSummary`) and a sentence for people.
fn access(rule: &Access) -> (Value, String) {
    match rule {
        Access::Allow(false) => (json!(false), "Nobody may do this over the API.".into()),
        Access::Allow(true) => (json!("anyone"), "Anyone may do this, signed in or not.".into()),
        Access::Role(role) if role == "anyone" => (json!("anyone"), "Anyone may do this, signed in or not.".into()),
        Access::Role(role) if role == "authenticated" => {
            (json!("authenticated"), "Any signed-in user may do this.".into())
        }
        Access::Role(role) => (
            json!(role),
            format!("Users with the `{role}` role or a higher one may do this."),
        ),
        Access::Owner => (json!("owner"), "Only the user who owns the row may do this.".into()),
        Access::Self_ => (
            json!("self"),
            "A signed-in user may do this to their own row only.".into(),
        ),
        Access::Fn(_) => (
            json!("rule"),
            "A rule in the app's code decides who may do this, and for which rows.".into(),
        ),
    }
}

/// Whether the rule lets a request through without signing in. A coded rule may, so it counts.
fn open(rule: &Access) -> bool {
    match rule {
        Access::Allow(allowed) => *allowed,
        Access::Role(role) => role == "anyone",
        Access::Fn(_) => true,
        Access::Owner | Access::Self_ => false,
    }
}

fn security(anonymous: bool) -> Value {
    let mut out: Vec<Value> = Vec::new();
    if anonymous {
        out.push(json!({}));
    }
    for schemes in SCHEMES {
        let requirement: Map<String, Value> = schemes.iter().map(|s| ((*s).to_string(), json!([]))).collect();
        out.push(Value::Object(requirement));
    }
    Value::Array(out)
}

/// One operation. `statuses` are the error statuses worth naming besides `default`.
struct Op {
    tag: String,
    id: String,
    summary: String,
    description: String,
    parameters: Vec<Value>,
    body: Option<Value>,
    /// The success status and its response.
    ok: (u16, Value),
    /// A second success status: 201 when the request created what it names.
    created: Option<Value>,
    statuses: Vec<u16>,
    /// The rule that decides the request, or `None` when WordPress core's own rules do.
    rule: Option<Access>,
    /// Extra sentences after the access note.
    notes: Vec<String>,
    /// No sign-in is read at all (a signed webhook, a signed download link).
    unauthenticated: bool,
}

impl Op {
    fn new(tag: &str, id: impl Into<String>, summary: impl Into<String>, description: impl Into<String>) -> Op {
        Op {
            tag: tag.to_string(),
            id: id.into(),
            summary: summary.into(),
            description: description.into(),
            parameters: Vec::new(),
            body: None,
            ok: (200, envelope("The result.", json!({}))),
            created: None,
            statuses: Vec::new(),
            rule: None,
            notes: Vec::new(),
            unauthenticated: false,
        }
    }

    fn params(mut self, parameters: impl IntoIterator<Item = Value>) -> Op {
        self.parameters.extend(parameters);
        self
    }

    fn body(mut self, body: Value) -> Op {
        self.body = Some(body);
        self
    }

    fn ok(mut self, status: u16, response: Value) -> Op {
        self.ok = (status, response);
        self
    }

    fn created(mut self, response: Value) -> Op {
        self.created = Some(response);
        self
    }

    fn rule(mut self, rule: &Access) -> Op {
        self.rule = Some(rule.clone());
        self
    }

    fn note(mut self, note: impl Into<String>) -> Op {
        self.notes.push(note.into());
        self
    }

    fn errors(mut self, statuses: &[u16]) -> Op {
        self.statuses.extend(statuses);
        self
    }

    fn unauthenticated(mut self) -> Op {
        self.unauthenticated = true;
        self
    }

    fn render(self) -> Value {
        let mut description = vec![self.description];
        let mut out = json!({ "tags": [self.tag], "operationId": self.id, "summary": self.summary });
        let mut statuses = self.statuses;
        match &self.rule {
            Some(rule) => {
                let (name, sentence) = access(rule);
                description.push(format!("Access: {sentence}"));
                out["x-spacepress-access"] = name;
                if !matches!(rule, Access::Allow(true)) && !matches!(rule, Access::Role(r) if r == "anyone") {
                    statuses.extend([401, 403]);
                }
                if open(rule) {
                    out["security"] = security(true);
                }
            }
            None if self.unauthenticated => out["security"] = json!([]),
            None => {
                description.push("Access: WordPress core's own rules for this route decide.".into());
                out["security"] = security(true);
            }
        }
        description.extend(self.notes);
        out["description"] = json!(description.join("\n\n"));
        if !self.parameters.is_empty() {
            out["parameters"] = Value::Array(self.parameters);
        }
        if let Some(body) = self.body {
            out["requestBody"] = body;
            statuses.extend([400, 422]);
        }
        let mut responses = Map::new();
        responses.insert(self.ok.0.to_string(), self.ok.1);
        if let Some(created) = self.created {
            responses.insert("201".into(), created);
        }
        statuses.sort_unstable();
        statuses.dedup();
        for status in statuses {
            responses.insert(status.to_string(), problem());
        }
        responses.insert("default".into(), problem());
        out["responses"] = Value::Object(responses);
        out
    }
}

/// The paths of the document, in the order they are added. A path holds its operations by method.
#[derive(Default)]
struct Paths(IndexMap<String, Map<String, Value>>);

impl Paths {
    fn add(&mut self, path: &str, method: &str, op: Op) {
        self.0
            .entry(path.to_string())
            .or_default()
            .insert(method.to_string(), op.render());
    }
}

/// A path segment as the client sends it (`encodeURIComponent`).
fn segment(name: &str) -> String {
    name.bytes()
        .map(|b| match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => (b as char).to_string(),
            other => format!("%{other:02X}"),
        })
        .collect()
}

/// A JSON Schema as core's validator reads it, in the 2020-12 dialect OpenAPI 3.1 uses: a tuple's
/// `items` list becomes `prefixItems`.
fn dialect(schema: &Value) -> Value {
    match schema {
        Value::Object(map) => {
            let mut out = Map::new();
            for (key, value) in map {
                match (key.as_str(), value) {
                    ("items", Value::Array(items)) => {
                        out.insert("prefixItems".into(), Value::Array(items.iter().map(dialect).collect()));
                        out.insert("items".into(), json!(false));
                    }
                    ("enum" | "required" | "default" | "const", _) => {
                        out.insert(key.clone(), value.clone());
                    }
                    _ => {
                        out.insert(key.clone(), dialect(value));
                    }
                }
            }
            Value::Object(out)
        }
        Value::Array(items) => Value::Array(items.iter().map(dialect).collect()),
        other => other.clone(),
    }
}

/// The values or operators a filter takes for one column (`{ title: "x" }`, `{ likes: { gt: 3 } }`),
/// under the name every column of that type shares, or `None` for a column that only compares as a
/// whole value.
fn filter(c: &Column) -> Option<(&'static str, Value)> {
    let flag = || json!({ "type": "boolean" });
    // `{ id: { NOT: { in: [1] } } }`: the operators again, one level down.
    let logic = |mut ops: Value| {
        let any = json!({ "type": "object" });
        ops["NOT"] = any.clone();
        ops["OR"] = json!({ "type": "array", "items": any });
        ops["AND"] = json!({ "type": "array", "items": any });
        ops
    };
    if c.ty.is_list_kind() {
        let item = if c.ty == ColType::Select {
            json!({ "type": "string" })
        } else {
            json!({ "type": "integer" })
        };
        let list = json!({ "type": ["array", "null"], "items": item });
        let name = if c.ty == ColType::Select {
            "StringListFilter"
        } else {
            "IntegerListFilter"
        };
        return Some((
            name,
            json!({
                "type": ["array", "object", "null"],
                "items": item,
                "properties": logic(json!({ "has": item, "eq": list, "ne": list, "isNull": flag(), "isNotNull": flag() })),
            }),
        ));
    }
    let scalar = match c.ty {
        ColType::Text
        | ColType::RichText
        | ColType::Email
        | ColType::Url
        | ColType::Slug
        | ColType::Timestamp
        | ColType::Date => "string",
        ColType::Real => "number",
        ColType::Integer | ColType::Media | ColType::File => "integer",
        ColType::Boolean => "boolean",
        _ => return None,
    };
    let value = json!({ "type": [scalar, "null"] });
    let mut ops = json!({ "eq": value, "ne": value, "isNull": flag(), "isNotNull": flag() });
    if scalar != "boolean" {
        for op in ["gt", "gte", "lt", "lte"] {
            ops[op] = json!({ "type": scalar });
        }
        for op in ["in", "notIn"] {
            ops[op] = json!({ "type": "array", "items": { "type": scalar } });
        }
    }
    if scalar == "string" {
        for op in ["like", "notLike", "ilike", "notIlike"] {
            ops[op] = json!({ "type": "string" });
        }
    }
    let name = match scalar {
        "string" => "StringFilter",
        "number" => "NumberFilter",
        "integer" => "IntegerFilter",
        _ => "BooleanFilter",
    };
    Some((
        name,
        json!({ "type": [scalar, "object", "null"], "properties": logic(ops) }),
    ))
}

/// A WordPress user as core shows them to the caller (`Wp\Kinds::userRow`): fields core leaves out
/// for this caller are absent.
fn wordpress_user() -> Value {
    let text = json!({ "type": ["string", "null"] });
    json!({ "type": "object", "properties": {
        "id": { "type": "integer" }, "email": { "type": "string" }, "name": text, "username": { "type": "string" },
        "roles": { "type": "array", "items": { "type": "string" } }, "avatar": text, "url": text,
        "locale": { "type": "string" }, "createdAt": { "type": "string", "format": "date-time" },
    } })
}

struct Builder<'a> {
    schema: &'a Schema,
    names: crate::client::ClientNames,
    /// Every component name the app's own types take.
    taken: HashSet<String>,
    paths: Paths,
    components: Map<String, Value>,
    tags: Vec<Value>,
}

impl Builder<'_> {
    /// The component name of one of the document's own schemas: `name`, unless the app has a type
    /// of that name (a kind called `menuItems` is `MenuItem`), and then with `Schema` after it.
    fn own(&self, name: &str) -> String {
        let mut name = name.to_string();
        while self.taken.contains(&name) {
            name.push_str("Schema");
        }
        name
    }

    fn own_ref(&self, name: &str) -> Value {
        reference(&self.own(name))
    }

    fn define(&mut self, name: &str, schema: Value) {
        let name = self.own(name);
        self.components.insert(name, schema);
    }

    fn tag(&mut self, name: &str, description: &str) {
        if !self.tags.iter().any(|t| t["name"] == name) {
            self.tags.push(json!({ "name": name, "description": description }));
        }
    }

    fn rule<'r>(rules: &'r IndexMap<String, Access>, op: &str, default: &'r Access) -> &'r Access {
        rules.get(op).unwrap_or(default)
    }

    /// A kind's row for a response: the Ability's row schema, with each field's access rule noted
    /// and the relations `with` can attach.
    fn row_component(&self, c: &Collection) -> Value {
        let mut row = js::row(c);
        for col in c.columns.values().filter(|col| !col.hidden) {
            let property = &mut row["properties"][&col.name];
            if !col.access.is_empty() {
                let notes: Map<String, Value> = col
                    .access
                    .iter()
                    .map(|(op, rule)| (op.clone(), access(rule).0))
                    .collect();
                property["x-spacepress-access"] = Value::Object(notes);
            }
            if let Some(rule) = col.access.get("read")
                && !matches!(rule, Access::Allow(true))
                && !matches!(rule, Access::Role(role) if role == "anyone")
            {
                property["description"] = json!(format!(
                    "Absent for callers who may not read it. {}",
                    access(rule).1.replace("do this", "read this field")
                ));
            }
        }
        for relation in c.relations.values() {
            let target = reference(&self.names.collections[&relation.collection]);
            let schema = match relation.kind {
                RelKind::One => json!({ "anyOf": [target, { "type": "null" }] }),
                RelKind::Many => json!({ "type": "array", "items": target }),
            };
            // A relation may share its name with the column it follows; `with` then replaces the id.
            let property = &mut row["properties"][&relation.name];
            *property = if property.is_null() {
                let mut schema = schema;
                schema["description"] = json!("Present when the request asks for it with `with`.");
                schema
            } else {
                json!({ "anyOf": [property.clone(), schema] })
            };
        }
        row
    }

    fn filter_component(&mut self, c: &Collection, name: &str) -> Value {
        let mut properties = Map::new();
        for col in c.columns.values().filter(|col| !col.hidden) {
            if let Some((shared, schema)) = filter(col) {
                self.define(shared, schema);
                properties.insert(col.name.clone(), self.own_ref(shared));
            }
        }
        for relation in c.relations.keys() {
            properties
                .entry(relation.clone())
                .or_insert_with(|| json!({ "type": "object", "description": "A filter on the related rows." }));
        }
        let nested = reference(name);
        properties.insert("AND".into(), json!({ "type": "array", "items": nested }));
        properties.insert("OR".into(), json!({ "type": "array", "items": nested }));
        properties.insert("NOT".into(), nested);
        json!({ "type": "object", "properties": properties })
    }

    /** A post kind's staged version (docs/staged.md): `/{id}/staged[/compare|/promote|/preview]`. */
    #[allow(clippy::too_many_arguments)]
    fn staged(
        &mut self,
        tag: &str,
        key: &str,
        table: &str,
        item: &str,
        id: &Value,
        row: &Value,
        patch: &str,
        update: &Access,
    ) {
        let needs = "Needs the kind's update rule on the row; anyone else gets the 404 of a missing row, \
            whether or not something is staged.";
        let user = json!({ "type": ["object", "null"], "properties": { "id": { "type": "integer" }, "name": { "type": "string" } } });
        let record = json!({
            "id": { "type": "integer", "description": "The revision that holds the staged version." },
            "by": user,
            "at": { "type": "string", "format": "date-time" },
            "promoteAt": { "type": ["string", "null"], "format": "date-time", "description": "When a scheduled promote runs." },
            "_rev": { "type": "string", "description": "The staged version's token, for the next stage, promote or discard." },
        });
        let mut version = json!({ "type": "object", "properties": {
            "row": row, "fields": { "type": "array", "items": { "type": "string" }, "description": "The staged fields." },
        }, "required": ["id", "row", "fields", "by", "at", "promoteAt", "_rev"] });
        let mut comparison = json!({ "type": "object", "properties": {
            "changes": { "type": "array", "items": { "type": "object", "properties": {
                "field": { "type": "string" }, "live": {}, "staged": {},
            }, "required": ["field", "live", "staged"] } },
        }, "required": ["id", "changes", "by", "at", "promoteAt", "_rev"] });
        for schema in [&mut version, &mut comparison] {
            if let (Some(props), Some(extra)) = (schema["properties"].as_object_mut(), record.as_object()) {
                props.extend(extra.clone());
            }
        }
        let staged = format!("{item}/staged");
        let op = |id_: &str, summary: String, description: &str| {
            Op::new(tag, format!("{key}.{id_}"), summary, description)
                .rule(update)
                .note(needs)
                .params([id.clone()])
        };
        self.paths.add(
            &staged,
            "get",
            op("staged", format!("Staged version of {table}"), "The row as it would be once promoted, the fields that are staged, and who staged last. With `preview=<token>` it answers without signing in, through an anonymous visitor's field masks.")
                .params([query("preview", "A preview token from the preview route.", json!({ "type": "string" }))])
                .ok(200, envelope("The staged version.", version.clone()))
                .errors(&[404]),
        );
        self.paths.add(
            &staged,
            "patch",
            op("stage", format!("Stage a change to {table}"), "Saves the fields sent into the row's staged version. The live row stays as it is until the staged version is promoted. Checked as an update would be; no hook runs.")
                .body(body("The fields to stage.", reference(patch)))
                .ok(200, envelope("The staged version.", version.clone()))
                .errors(&[403, 404, 409, 422]),
        );
        self.paths.add(
            &staged,
            "delete",
            op("discard", format!("Discard the staged version of {table}"), "Ends the staged version. The live row is untouched; the discarded version stays in the row's revisions.")
                .params([
                    query("_rev", "The staged version's `_rev`: the discard is refused with `409 stale_revision` if it changed since.", json!({ "type": "string" })),
                    query("takeover", "`1` takes over another user's edit lock.", json!({ "type": "string", "enum": ["1"] })),
                ])
                .ok(200, envelope("The revision that held it.", json!({ "type": "object", "properties": { "revision": { "type": "integer" } }, "required": ["revision"] })))
                .errors(&[404, 409]),
        );
        self.paths.add(
            &format!("{staged}/compare"),
            "get",
            op("compare", format!("Compare staged and live {table}"), "The staged fields whose value differs from the live row, with both values, as the caller may read each side.")
                .ok(200, envelope("The changes.", comparison))
                .errors(&[404]),
        );
        self.paths.add(
            &format!("{staged}/promote"),
            "post",
            op("promote", format!("Promote the staged version of {table}"), "Makes the staged version live, as an update of the row by the caller: its rules, hooks, unique checks and webhooks run. With `at` (a future time) the promote is scheduled instead, and `at: null` cancels a scheduled one; both answer with the staged version.")
                .body(body("Empty, or `at`; `_rev` and `takeover` as on a stage.", json!({ "type": "object", "properties": {
                    "at": { "type": ["string", "null"], "format": "date-time" },
                    "_rev": { "type": "string" }, "takeover": { "type": "boolean" },
                } })))
                .ok(200, envelope("The row after the promote, or the staged version when `at` was sent.", json!({ "oneOf": [row, version] })))
                .errors(&[403, 404, 409, 422]),
        );
        self.paths.add(
            &format!("{staged}/preview"),
            "post",
            op("previewLink", format!("Preview link for staged {table}"), "A signed link to the staged version that works without signing in until it expires (an hour by default, a day at most) or the staged version is promoted or discarded.")
                .body(body("Empty, or `ttl` in seconds.", json!({ "type": "object", "properties": { "ttl": { "type": "integer", "minimum": 60, "maximum": 86400 } } })))
                .ok(200, envelope("The link.", json!({ "type": "object", "properties": {
                    "token": { "type": "string" }, "expiresAt": { "type": "string", "format": "date-time" },
                    "api": { "type": "string", "description": "Reads the staged version without signing in." },
                    "url": { "type": ["string", "null"], "description": "The row's own page showing the staged version, when the theme renders it." },
                }, "required": ["token", "expiresAt", "api", "url"] })))
                .errors(&[404]),
        );
    }

    fn kind(&mut self, c: &Collection) {
        let name = self.names.collections[&c.table].clone();
        let (insert, patch, filter_name) = (format!("{name}Insert"), format!("{name}Patch"), format!("{name}Filter"));
        let mut row_component = self.row_component(c);
        let mut patch_component = js::patch(c);
        // Post kinds with staged versions carry `_rev` and `takeover`; SQL tables with revisions carry `_rev`.
        let staged = c.editor && c.revisions && c.staged;
        let has_table = !c.editor && !c.auth && c.taxonomy.is_none();
        let has_rev = staged || (has_table && c.revisions);
        if has_rev {
            row_component["properties"]["_rev"]["description"] = json!(
                "A token of the row as read: send it back with a write to refuse the write if the row changed since."
            );
            patch_component["properties"]["_rev"] = json!({ "type": "string", "description": "The `_rev` of your read: the write is refused with `409 stale_revision` if the row changed since." });
        }
        if staged {
            patch_component["properties"]["takeover"] = json!({ "type": "boolean", "description": "Take over another user's edit lock instead of being refused with `409 locked`." });
        }
        self.components.insert(name.clone(), row_component);
        self.components.insert(insert.clone(), js::insert(c));
        self.components.insert(patch.clone(), patch_component);
        let filter = self.filter_component(c, &filter_name);
        self.components.insert(filter_name.clone(), filter);

        let storage = if c.auth {
            "The site's users: WordPress's own users, with these fields. The same routes also answer at `/users`."
        } else if c.editor {
            "A collection: WordPress posts of their own type, with the block editor."
        } else if c.taxonomy.is_some() {
            "A taxonomy: WordPress terms of their own taxonomy, attached to collections. Assigning terms is a write to the item."
        } else {
            "A table of the app's own."
        };
        let tag = c.label.clone();
        self.tag(&tag, storage);

        let (anyone, editor) = (Access::anyone(), Access::editor());
        let read = Self::rule(&c.access, "read", &anyone).clone();
        let create = Self::rule(&c.access, "create", &editor).clone();
        let update = Self::rule(&c.access, "update", &editor).clone();
        let delete = Self::rule(&c.access, "delete", &editor).clone();
        // Draft visibility (a plain rule on a kind with drafts): only editors read unpublished rows.
        let drafts = (c.drafts && !matches!(read, Access::Fn(_)))
            .then_some("Rows that are not published are returned to editors and administrators only.");

        let table = &c.table;
        let key = &c.key;
        let base = format!("/{}", segment(table));
        let item = format!("{base}/{{id}}");
        let pk = js::primary_key(c);
        let id = if c.editor && c.slug.is_some() {
            path_param(
                "id",
                "The row's id, or `slug:<slug>` for the row with that slug.",
                json!({ "anyOf": [pk, { "type": "string", "pattern": "^slug:." }] }),
            )
        } else {
            path_param("id", "The row's id.", pk)
        };
        let loadable: Vec<&str> = c
            .relations
            .keys()
            .map(String::as_str)
            .chain(
                c.columns
                    .values()
                    .filter(|col| matches!(col.ty, ColType::Media | ColType::MediaList | ColType::File))
                    .map(|col| col.name.as_str()),
            )
            .collect();
        let with = (!loadable.is_empty()).then(|| {
            query(
                "with",
                &format!(
                    "What to load with each row, comma-separated or as a JSON object: {}. A relation holds only rows the caller may read.",
                    loadable.iter().map(|n| format!("`{n}`")).collect::<Vec<_>>().join(", ")
                ),
                json!({ "type": "string" }),
            )
        });
        let locale = c.localized.then(|| {
            let locales = self.schema.locales.as_ref().map(|l| l["all"].clone()).unwrap_or(json!([]));
            let mut values = locales.as_array().cloned().unwrap_or_default();
            values.push(json!("*"));
            query(
                "locale",
                "The rows of one locale, with each translation group's fallback where it has none; `*` for every row. Without it, the default locale.",
                json!({ "type": "string", "enum": values }),
            )
        });
        let row = reference(&name);

        if c.api.list {
            let columns: Vec<String> = c
                .columns
                .values()
                .filter(|col| !col.hidden)
                .map(|col| format!("`{}`", col.name))
                .collect();
            let mut op = Op::new(&tag, format!("{key}.list"), format!("List {table}"), format!(
                "The {table} rows the caller may read, one page at a time. `limit` is {LIMIT} unless given, and at most {MAX_LIMIT}; `cursor` is null on the last page."
            ))
            .rule(&read)
            .params([
                json_query("where", "A filter, as JSON: a value or operators per field, with `AND`, `OR` and `NOT`. A filter on a field the caller may not read is refused.", reference(&filter_name)),
                query("orderBy", &format!("Fields to sort by, comma-separated; `-` before a field sorts it descending (`-createdAt,title`). Fields: {}.", columns.join(", ")), json!({ "type": "string" })),
                paging("limit"),
                paging("offset"),
                paging("cursor"),
            ])
            .ok(200, page(row.clone()))
            .errors(&[400]);
            if !js::searchable(c).is_empty() {
                op = op.params([query(
                    "search",
                    "Words to find, each at the start of a word, in the title, the content and every text field the caller may read. Only rows the caller may read are found, in the list's own order.",
                    json!({ "type": "string", "maxLength": 200 }),
                )]);
            }
            if c.drafts {
                let statuses = c
                    .columns
                    .get("status")
                    .and_then(|col| col.enum_values.clone())
                    .unwrap_or_default();
                op = op.params([query(
                    "status",
                    "Only rows with this status; shorthand for a filter on `status`.",
                    json!({ "type": "string", "enum": statuses }),
                )]);
            }
            if c.soft_delete {
                op = op.params([query(
                    "deleted",
                    "Soft-deleted rows filter: `exclude` (default), `include`, or `only`.",
                    json!({ "type": "string", "enum": ["exclude", "include", "only"] }),
                )]);
            }
            op = op.params(with.clone()).params(locale.clone());
            if let Some(note) = drafts {
                op = op.note(note);
            }
            self.paths.add(&base, "get", op);
        }
        if c.api.create {
            let mut op = Op::new(
                &tag,
                format!("{key}.create"),
                format!("Create in {table}"),
                format!("Creates one {table} row and returns it."),
            )
            .rule(&create);
            if c.auth {
                op = op.note("A new user is a WordPress user, so WordPress core's rule applies as well: the caller must be allowed to create users.");
            }
            // Only a table's create runs through SpacePress's receipts (`TableController`); a post kind's
            // or the users kind's create is core's insert, which takes no key.
            if !c.editor && !c.auth && c.taxonomy.is_none() {
                op = op.params([idempotency_key(false)]);
            }
            self.paths.add(
                &base,
                "post",
                op.body(body("The new row's fields.", reference(&insert)))
                    .ok(201, envelope("The new row.", row.clone()))
                    .errors(&[401, 403, 409]),
            );
        }
        if c.api.get {
            let mut op = Op::new(
                &tag,
                format!("{key}.get"),
                format!("Get one of {table}"),
                format!("One {table} row."),
            )
            .rule(&read)
            .params([id.clone()])
            .params(with.clone())
            .params(locale.clone())
            .errors(&[404]);
            if c.editor {
                op = op.params([query(
                    "format",
                    "`md` answers with the row as Markdown (`text/markdown`) instead of JSON.",
                    json!({ "type": "string", "enum": ["md"] }),
                )]);
                let mut response = envelope("The row.", row.clone());
                response["content"]["text/markdown"] = json!({ "schema": { "type": "string" } });
                op = op.ok(200, response);
            } else {
                op = op.ok(200, envelope("The row.", row.clone()));
            }
            if let Some(note) = drafts {
                op = op.note(note);
            }
            self.paths.add(&item, "get", op);
        }
        if c.api.update {
            self.paths.add(
                &item,
                "patch",
                Op::new(
                    &tag,
                    format!("{key}.update"),
                    format!("Update in {table}"),
                    format!("Changes the fields sent on one {table} row and returns the row."),
                )
                .rule(&update)
                .params([id.clone()])
                .body(body("The fields to change.", reference(&patch)))
                .ok(200, envelope("The row after the change.", row.clone()))
                .errors(&[401, 403, 404, 409]),
            );
        }
        if c.api.delete {
            self.paths.add(
                &item,
                "delete",
                Op::new(
                    &tag,
                    format!("{key}.delete"),
                    format!("Delete from {table}"),
                    format!("Deletes one {table} row for good and returns the row as it was."),
                )
                .rule(&delete)
                .params([id.clone()])
                .params(if staged {
                    vec![
                        query("_rev", "The `_rev` of your read: the delete is refused with `409 stale_revision` if the row changed since.", json!({ "type": "string" })),
                        query("takeover", "`1` takes over another user's edit lock.", json!({ "type": "string", "enum": ["1"] })),
                    ]
                } else if has_rev {
                    vec![
                        query("_rev", "The `_rev` of your read: the delete is refused with `409 stale_revision` if the row changed since.", json!({ "type": "string" })),
                    ]
                } else {
                    vec![]
                })
                .ok(200, envelope("The deleted row.", row.clone()))
                .errors(&[404, 409]),
            );
            if c.soft_delete {
                let restore = Self::rule(&c.access, "restore", &delete).clone();
                self.paths.add(
                    &format!("{item}/restore"),
                    "post",
                    Op::new(
                        &tag,
                        format!("{key}.restore"),
                        format!("Restore a soft-deleted {table}"),
                        format!("Restores a soft-deleted {table} row and returns it."),
                    )
                    .rule(&restore)
                    .params([id.clone()])
                    .ok(200, envelope("The restored row.", row.clone()))
                    .errors(&[404, 409]),
                );
                if let Some(purge) = c.access.get("purge").filter(|r| !matches!(r, Access::Allow(false))) {
                    self.paths.add(
                        &format!("{item}/purge"),
                        "delete",
                        Op::new(
                            &tag,
                            format!("{key}.purge"),
                            format!("Purge a {table} row"),
                            format!(
                                "Permanently deletes one {table} row (bypasses soft delete) and returns it as it was."
                            ),
                        )
                        .rule(purge)
                        .params([id.clone()])
                        .ok(200, envelope("The purged row.", row.clone()))
                        .errors(&[404, 409]),
                    );
                }
            }
        }
        // Revisions need the update rule, and the API serves them only while updates are on.
        if c.revisions && c.api.update {
            let revision = path_param(
                "revision",
                "The revision's id or version.",
                json!({ "type": "integer" }),
            );
            let needs = "Needs the kind's update rule on the row; a row outside it is a 404.";
            self.paths.add(
                &format!("{item}/revisions"),
                "get",
                Op::new(
                    &tag,
                    format!("{key}.revisions.list"),
                    format!("Revisions of {table}"),
                    format!("Each saved version of the row, newest first, with its version, op, time, author, schemaMigration, and changed fields, one page at a time. `limit` is {LIMIT} unless given, and at most {MAX_LIMIT}; `cursor` is null on the last page."),
                )
                .rule(&update)
                .note(needs)
                .params([id.clone(), paging("limit"), paging("offset"), paging("cursor")])
                .ok(200, page(self.own_ref("RevisionSummary")))
                .errors(&[400, 404]),
            );
            self.paths.add(
                &format!("{item}/revisions/diff"),
                "get",
                Op::new(
                    &tag,
                    format!("{key}.revisions.diff"),
                    format!("Diff two revisions of {table}"),
                    "Field-by-field before/after diff between two revision ids or versions.",
                )
                .rule(&update)
                .note(needs)
                .params([
                    id.clone(),
                    query(
                        "from",
                        "The starting revision id or version (optional).",
                        json!({ "type": "integer" }),
                    ),
                    query("to", "The target revision id or version.", json!({ "type": "integer" })),
                ])
                .ok(200, envelope("The changed fields.", self.own_ref("RevisionDiff")))
                .errors(&[400, 404]),
            );
            self.paths.add(
                &format!("{item}/revisions/{{revision}}"),
                "get",
                Op::new(
                    &tag,
                    format!("{key}.revisions.get"),
                    format!("Get a revision of {table}"),
                    "The row as this revision saved it.",
                )
                .rule(&update)
                .note(needs)
                .params([id.clone(), revision.clone()])
                .ok(200, envelope("The row as it was.", row.clone()))
                .errors(&[404]),
            );
            self.paths.add(
                &format!("{item}/revisions/{{revision}}/restore"),
                "post",
                Op::new(
                    &tag,
                    format!("{key}.revisions.restore"),
                    format!("Restore a revision of {table}"),
                    "Makes the revision the current row, as an update of the row.",
                )
                .rule(&update)
                .note(needs)
                .params([id.clone(), revision])
                .ok(200, envelope("The row after the restore.", row.clone()))
                .errors(&[404, 409, 422]),
            );
        }
        if c.editor && c.revisions && c.staged && c.api.update {
            self.staged(&tag, key, table, &item, &id, &row, &patch, &update);
        }
        if c.sync.is_some() {
            self.sync(c, &update, &delete);
        }
        // The users kind also answers at `/users` (`Wp\Kinds::all`), the spec's name for it.
        if c.auth
            && table != "users"
            && !self.schema.collections.contains_key("users")
            && !self.schema.globals.contains_key("users")
        {
            let prefix = format!("{base}/");
            let aliases: Vec<(String, Map<String, Value>)> = self
                .paths
                .0
                .iter()
                .filter(|(path, _)| *path == &base || path.starts_with(&prefix))
                .map(|(path, ops)| {
                    let mut ops = ops.clone();
                    for op in ops.values_mut() {
                        if let Some(id) = op["operationId"].as_str() {
                            op["operationId"] =
                                json!(format!("users.{}", id.strip_prefix(&format!("{key}.")).unwrap_or(id)));
                        }
                    }
                    (format!("/users{}", &path[base.len()..]), ops)
                })
                .collect();
            self.paths.0.extend(aliases);
        }
    }

    /// The routes `spacepress sync` uses to keep a kind's content files and its rows in step.
    fn sync(&mut self, c: &Collection, update: &Access, delete: &Access) {
        self.tag(
            "Sync",
            "What `spacepress sync` calls to keep content files and rows in step, for kinds with `sync`.",
        );
        let table = &c.table;
        let base = format!("/sync/{}", segment(table));
        let any = || json!({ "type": "object" });
        self.paths.add(&base, "get", Op::new("Sync", format!("sync.{table}.read"), format!("Read {table} for sync"), "Rows as sync records with their digests, in pages by id.")
            .rule(update)
            .params([
                query("after", "Only rows with an id above this one.", json!({ "type": "integer", "minimum": 0 })),
                query("first", "How many records to return, at most 100.", json!({ "type": "integer", "minimum": 1, "maximum": 100 })),
            ])
            .ok(200, envelope("One page of records, and the id to pass as `after` for the next page, or null.", json!({
                "type": "object",
                "properties": { "records": { "type": "array", "items": { "type": "object" } }, "next": { "type": ["integer", "null"] } },
                "required": ["records", "next"],
            }))));
        // What apply and trash answer (`Sync\Routes`): the uid, what happened, and the new digest.
        let outcome = json!({ "type": "object", "properties": {
            "uid": { "type": "string" },
            "status": { "type": "string", "enum": ["applied", "normalized", "unchanged"] },
            "digest": { "type": "string" },
            "record": { "type": "object", "description": "The record as stored, when the site normalized what was sent." },
        }, "required": ["uid", "status"] });
        let expect = json!({ "type": "string", "pattern": "^(absent|sha256:[0-9a-f]{64})$", "description": "The digest the caller last saw, or `absent` for a row it believes does not exist." });
        let uid = json!({ "type": "string", "pattern": "^zr_[0-9a-f]{20}$" });
        self.paths.add(&base, "post", Op::new("Sync", format!("sync.{table}.apply"), format!("Apply a {table} sync record"), "Creates or updates one row from a content file, when the row still matches `expect`. A row that changed since answers 409 `sync_stale`.")
            .rule(update)
            .body(body("The record and the digest it expects.", json!({
                "type": "object", "properties": { "record": { "type": "object" }, "expect": expect }, "required": ["record", "expect"],
            })))
            .ok(200, envelope("The outcome: the row was updated, or was already as sent.", outcome.clone()))
            .created(envelope("The outcome: the record created a row.", outcome.clone()))
            .errors(&[409]));
        self.paths.add(
            &format!("{base}/trash"),
            "post",
            Op::new(
                "Sync",
                format!("sync.{table}.trash"),
                format!("Trash a synced {table} row"),
                "Moves the row of a deleted content file to the trash, when the row still matches `expect`.",
            )
            .rule(delete)
            .body(body(
                "The row's uid and the digest it expects.",
                json!({
                    "type": "object", "properties": { "uid": uid, "expect": expect }, "required": ["uid", "expect"],
                }),
            ))
            .ok(200, envelope("The outcome.", any()))
            .errors(&[409]),
        );
        let text = json!({ "type": "string" });
        let batch = |one: Value| json!({ "anyOf": [one, { "type": "object", "properties": { "records": { "type": "array", "items": one } }, "required": ["records"] }] });
        self.paths.add(&format!("{base}/convert"), "post", Op::new("Sync", format!("sync.{table}.convert"), format!("Convert {table} content between formats"), "Converts a body between block markup, Markdown and HTML, as the site's own converters do. One item, or up to a batch under `records`.")
            .rule(update)
            .body(body("The text and the direction.", batch(json!({
                "type": "object",
                "properties": { "text": text, "direction": { "type": "string", "enum": ["toMarkdown", "toBlocks", "toHtml", "fromHtml"] } },
                "required": ["text", "direction"],
            }))))
            .ok(200, envelope("The converted text, or one result per item under `records`.", any())));
        self.paths.add(&format!("{base}/merge"), "post", Op::new("Sync", format!("sync.{table}.merge"), format!("Three-way merge {table} content"), "Merges two versions of a body over their common base. One item, or up to a batch under `records`.")
            .rule(update)
            .body(body("The base and the two versions.", batch(json!({
                "type": "object", "properties": { "base": text, "ours": text, "theirs": text }, "required": ["base", "ours", "theirs"],
            }))))
            .ok(200, envelope("The merged text and whether it has conflicts, or one result per item under `records`.", any())));
    }

    fn global(&mut self, g: &Global) {
        self.tag("Globals", "Single records, such as site-wide settings.");
        let name = self.names.globals[&g.name].clone();
        let patch = format!("{name}Patch");
        self.components.insert(name.clone(), js::fields(&g.fields, true));
        self.components.insert(patch.clone(), js::global_patch(&g.fields));
        let path = format!("/{}", segment(&g.name));
        let (anyone, editor) = (Access::anyone(), Access::editor());
        self.paths.add(
            &path,
            "get",
            Op::new(
                "Globals",
                format!("globals.{}.get", g.name),
                format!("Read {}", g.label),
                "The global's values. A field never set reads as its default.",
            )
            .rule(Self::rule(&g.access, "read", &anyone))
            .ok(200, envelope("The values.", reference(&name))),
        );
        self.paths.add(
            &path,
            "patch",
            Op::new(
                "Globals",
                format!("globals.{}.update", g.name),
                format!("Update {}", g.label),
                "Changes the fields sent and returns every value.",
            )
            .rule(Self::rule(&g.access, "update", &editor))
            .body(body("The fields to change.", reference(&patch)))
            .ok(200, envelope("The values after the change.", reference(&name))),
        );
    }

    fn action(&mut self, a: &Action) {
        self.tag("Actions", "The app's own server functions.");
        let path = format!("/actions/{}", segment(&a.name));
        let input = js::action_input(&a.input);
        let output = a.output.as_ref().map(dialect).unwrap_or(json!({}));
        let result = envelope("The action's result.", output);
        if a.signature.is_some() {
            // A signed webhook is for the one service that holds its secret, and the app's public
            // schema (`Kinds::describe`) leaves it out: so does this document.
            return;
        }
        let mut post = Op::new(
            "Actions",
            format!("actions.{}", a.name),
            format!("Call {}", a.name),
            format!(
                "Runs the `{}` action with the input sent and returns its result.",
                a.name
            ),
        )
        .rule(&a.access)
        .body(body("The action's input.", input.clone()))
        .ok(200, result.clone());
        if a.idempotent {
            post = post.params([idempotency_key(false)]);
        }
        self.paths.add(&path, "post", post);
        if a.readonly {
            self.paths.add(&path, "get", Op::new("Actions", format!("actions.{}.read", a.name), format!("Call {} with GET", a.name), format!(
                "Runs the read-only `{}` action. The input is `?input=<json>`; without it, the other query arguments are the input, as strings.", a.name
            ))
            .rule(&a.access)
            .params([json_query("input", "The action's input, as JSON.", input)])
            .ok(200, result)
            .errors(&[400, 422]));
        }
    }

    fn bucket(&mut self, name: &str, bucket: &FileBucket) {
        self.tag("Files", "Private files, by bucket.");
        let base = format!("/files/{}", segment(name));
        let id = path_param("id", "The file's id.", json!({ "type": "integer" }));
        let file = self.own_ref("PrivateFile");
        let owner = Access::Owner;
        let signed_in = Access::Role("authenticated".into());
        let accept = if bucket.accept.is_empty() {
            String::new()
        } else {
            format!(
                " Accepts {}.",
                bucket
                    .accept
                    .iter()
                    .map(|a| format!("`{a}`"))
                    .collect::<Vec<_>>()
                    .join(", ")
            )
        };
        self.paths.add(
            &base,
            "post",
            Op::new(
                "Files",
                format!("files.{name}.upload"),
                format!("Upload to {name}"),
                format!(
                    "Stores one file of at most {} bytes in the `{name}` bucket.{accept}",
                    bucket.max_bytes
                ),
            )
            .rule(Self::rule(&bucket.access, "create", &signed_in))
            .body(upload("The file, as the multipart field `file`.", json!({})))
            .ok(201, envelope("The stored file, with a signed link.", file.clone()))
            .errors(&[413]),
        );
        self.paths.add(
            &format!("{base}/{{id}}"),
            "get",
            Op::new(
                "Files",
                format!("files.{name}.get"),
                format!("Get a {name} file"),
                "The file's details, with a fresh signed link in `url`.",
            )
            .rule(Self::rule(&bucket.access, "read", &owner))
            .params([id.clone()])
            .ok(200, envelope("The file.", file.clone()))
            .errors(&[404]),
        );
        self.paths.add(
            &format!("{base}/{{id}}"),
            "delete",
            Op::new(
                "Files",
                format!("files.{name}.delete"),
                format!("Delete a {name} file"),
                "Deletes the file. Fields that held it become null.",
            )
            .rule(Self::rule(&bucket.access, "delete", &owner))
            .params([id.clone()])
            .ok(200, envelope("The deleted file.", file))
            .errors(&[404]),
        );
        self.paths.add(&format!("{base}/{{id}}/download"), "get", Op::new("Files", format!("files.{name}.download"), format!("Download a {name} file"), "The file's bytes. The link is the permission: `exp` and `sig` come from the file's `url`, and signing in does not replace them.")
            .unauthenticated()
            .params([
                id,
                json!({ "name": "exp", "in": "query", "required": true, "description": "When the link expires, in Unix seconds.", "schema": { "type": "string" } }),
                json!({ "name": "sig", "in": "query", "required": true, "description": "The link's signature.", "schema": { "type": "string" } }),
            ])
            .ok(200, json!({ "description": "The file.", "content": { "*/*": { "schema": { "type": "string", "format": "binary" } } } }))
            .errors(&[403, 404]));
    }

    /// The WordPress kinds every site has (spec §2.1), unless the app has a kind of that name.
    fn wordpress(&mut self) {
        // The app's own kind of that name answers instead (`Wp\Facade::route`). A global of that
        // name takes only the one-segment path; the item paths still reach the built-in kind.
        let has = |name: &str| self.schema.collections.contains_key(name);
        let global = |name: &str| self.schema.globals.contains_key(name);
        let description = "WordPress's own content, in SpacePress's field names. WordPress core decides access, as it does for every other client of these routes.";
        let paged = || [paging("limit"), paging("offset"), paging("cursor")];
        let search = || {
            query(
                "search",
                "Text to find, as WordPress core searches this route.",
                json!({ "type": "string" }),
            )
        };
        let integer = || json!({ "type": "integer" });
        let optional_integer = || json!({ "type": ["integer", "null"] });
        let text = || json!({ "type": ["string", "null"] });
        let time = || json!({ "type": ["string", "null"], "format": "date-time" });
        let post = |extra: Value| {
            let mut properties = json!({
                "id": integer(), "title": text(), "content": text(), "slug": text(),
                "status": { "type": "string", "enum": ["draft", "pending", "scheduled", "published", "private"] },
                "publishedAt": time(), "url": text(), "author": optional_integer(), "createdAt": time(), "updatedAt": time(),
            });
            for (name, schema) in extra.as_object().into_iter().flatten() {
                properties[name] = schema.clone();
            }
            json!({ "type": "object", "properties": properties })
        };
        let ids = || json!({ "type": "array", "items": { "type": "integer" } });
        let term_row = || {
            json!({ "type": "object", "properties": {
                "id": integer(), "name": text(), "slug": text(), "description": { "type": "string" },
                "parent": optional_integer(), "count": integer(), "url": text(),
            } })
        };
        // What a write to a post or a page may set (`Wp\Kinds::input`); anything else is refused.
        let post_input = json!({ "type": "object", "properties": {
            "title": { "type": "string" }, "content": { "type": "string" }, "slug": { "type": "string" }, "excerpt": { "type": "string" },
            "status": { "type": "string", "enum": ["draft", "pending", "scheduled", "published", "private"] },
            "publishedAt": { "type": "string", "format": "date-time" }, "author": integer(),
            "categories": ids(), "tags": ids(), "featuredImage": integer(), "sticky": { "type": "boolean" },
            "parent": integer(), "order": integer(), "template": { "type": "string" },
        } });
        let term_input = json!({ "type": "object", "properties": {
            "name": { "type": "string" }, "slug": { "type": "string" }, "description": { "type": "string" }, "parent": integer(),
        } });
        // Each built-in: its name, component, row, input, and whether a row can be read by slug.
        let kinds: [(&str, &str, Value, Value, bool); 5] = [
            (
                "posts",
                "WordPressPost",
                post(
                    json!({ "excerpt": text(), "categories": ids(), "tags": ids(), "featuredImage": optional_integer(), "sticky": { "type": "boolean" } }),
                ),
                post_input.clone(),
                true,
            ),
            (
                "pages",
                "WordPressPage",
                post(json!({ "parent": optional_integer(), "order": integer(), "template": { "type": "string" } })),
                post_input,
                true,
            ),
            (
                "comments",
                "WordPressComment",
                json!({ "type": "object", "properties": {
                "id": integer(), "on": optional_integer(), "parent": optional_integer(), "author": optional_integer(),
                "authorName": text(), "authorEmail": text(), "content": text(), "status": text(), "type": { "type": "string" }, "createdAt": time(),
            } }),
                json!({ "type": "object", "properties": {
                "on": integer(), "parent": integer(), "content": { "type": "string" }, "authorName": { "type": "string" },
                "authorEmail": { "type": "string" }, "type": { "type": "string" }, "status": { "type": "string" },
            } }),
                false,
            ),
            ("categories", "WordPressTerm", term_row(), term_input.clone(), true),
            ("tags", "WordPressTerm", term_row(), term_input, true),
        ];
        for (name, component, row, input, by_slug) in kinds {
            if has(name) {
                continue;
            }
            self.tag("WordPress", description);
            let component = &self.own(component);
            self.components.entry(component.to_string()).or_insert(row);
            let id = if by_slug {
                path_param(
                    "id",
                    "The row's id, or `slug:<slug>`.",
                    json!({ "anyOf": [integer(), { "type": "string", "pattern": "^slug:." }] }),
                )
            } else {
                path_param("id", "The row's id.", integer())
            };
            let base = format!("/{name}");
            let item = format!("{base}/{{id}}");
            let one = envelope("The row.", reference(component));
            let fields = body("The fields to set. Any other field is refused.", input);
            if !global(name) {
                self.paths.add(&base, "get", Op::new("WordPress", format!("{name}.list"), format!("List {name}"), format!("WordPress's {name}, one page at a time. The route's other WordPress filters (`status`, `author`, `parent`, ...) pass through."))
                    .params(paged())
                    .params([search()])
                    .ok(200, page(reference(component)))
                    .errors(&[400, 401, 403]));
                self.paths.add(
                    &base,
                    "post",
                    Op::new(
                        "WordPress",
                        format!("{name}.create"),
                        format!("Create in {name}"),
                        format!("Creates one of WordPress's {name} and returns it."),
                    )
                    .body(fields.clone())
                    .ok(201, one.clone())
                    .errors(&[401, 403]),
                );
            }
            let mut get = Op::new(
                "WordPress",
                format!("{name}.get"),
                format!("Get one of {name}"),
                format!("One of WordPress's {name}."),
            )
            .params([id.clone()])
            .errors(&[401, 403, 404]);
            if matches!(name, "posts" | "pages") {
                let mut response = one.clone();
                response["content"]["text/markdown"] = json!({ "schema": { "type": "string" } });
                get = get
                    .params([query(
                        "format",
                        "`md` answers with the row as Markdown (`text/markdown`).",
                        json!({ "type": "string", "enum": ["md"] }),
                    )])
                    .ok(200, response);
            } else {
                get = get.ok(200, one.clone());
            }
            self.paths.add(&item, "get", get);
            self.paths.add(
                &item,
                "patch",
                Op::new(
                    "WordPress",
                    format!("{name}.update"),
                    format!("Update one of {name}"),
                    "Changes the fields sent and returns the row.",
                )
                .params([id.clone()])
                .body(fields)
                .ok(200, one.clone())
                .errors(&[401, 403, 404]),
            );
            self.paths.add(
                &item,
                "delete",
                Op::new(
                    "WordPress",
                    format!("{name}.delete"),
                    format!("Delete one of {name}"),
                    "Deletes the row for good and returns it as it was.",
                )
                .params([id])
                .ok(200, one)
                .errors(&[401, 403, 404]),
            );
        }

        if !has("media") {
            self.tag("Media", "The WordPress media library.");
            let media = self.own_ref("Media");
            let id = path_param("id", "The media item's id.", integer());
            let (author, editor) = (Access::Role("author".into()), Access::editor());
            self.paths.add(
                "/media",
                "get",
                Op::new(
                    "Media",
                    "media.list",
                    "List media",
                    "Media library items, one page at a time.",
                )
                .params(paged())
                .params([search()])
                .ok(200, page(self.own_ref("Media")))
                .errors(&[400]),
            );
            self.paths.add(
                "/media",
                "post",
                Op::new(
                    "Media",
                    "media.upload",
                    "Upload media",
                    "Uploads one file to the media library.",
                )
                .rule(&author)
                .body(upload(
                    "The file, as the multipart field `file`, with optional alt text.",
                    json!({ "alt": { "type": "string" } }),
                ))
                .ok(201, envelope("The new media item.", media.clone()))
                .errors(&[413]),
            );
            self.paths.add(
                "/media/{id}",
                "get",
                Op::new("Media", "media.get", "Get a media item", "One media item.")
                    .params([id.clone()])
                    .ok(200, envelope("The media item.", media.clone()))
                    .errors(&[404]),
            );
            self.paths.add("/media/{id}", "patch", Op::new("Media", "media.update", "Update a media item", "Changes the alt text, the caption or the focal point. Any other field is refused.")
                .rule(&editor)
                .params([id.clone()])
                .body(body("The fields to change.", json!({ "type": "object", "properties": {
                    "alt": { "type": ["string", "null"] }, "caption": { "type": "string" },
                    "focal": { "type": ["object", "null"], "properties": { "x": { "type": "number" }, "y": { "type": "number" } } },
                } })))
                .ok(200, envelope("The media item after the change.", media.clone()))
                .errors(&[404]));
            self.paths.add(
                "/media/{id}",
                "delete",
                Op::new(
                    "Media",
                    "media.delete",
                    "Delete a media item",
                    "Deletes the item and its files. Fields that pointed at it become null.",
                )
                .rule(&editor)
                .params([id.clone()])
                .ok(200, envelope("The deleted media item.", media))
                .errors(&[404]),
            );
            // Where an upload kept off the web root (`uploads: { backend: "s3" }`) is streamed from: a
            // media item's `url` and its sizes' URLs point here. Without that backend it is a 404.
            let offsite = self.schema.uploads.as_ref().is_some_and(|u| u["backend"] == "s3");
            if offsite {
                self.paths.add(
                "/media/{id}/file/{hash}/{path}",
                "get",
                Op::new(
                    "Media",
                    "media.file",
                    "Read an uploaded file",
                    "The bytes of one file of a media item, such as an image size, when the site keeps uploads off its web root. Take the URL from the item's `url` or `sizes`.",
                )
                .unauthenticated()
                .params([
                    id,
                    path_param("hash", "The SHA-256 of the file's path, from the media item's URL.", json!({ "type": "string" })),
                    path_param("path", "The file's path within the item.", json!({ "type": "string" })),
                ])
                .ok(200, json!({ "description": "The file.", "content": { "*/*": { "schema": { "type": "string", "format": "binary" } } } }))
                .errors(&[404]),
            );
            }
        }

        if !has("users") && !self.schema.collections.values().any(|c| c.auth) {
            self.tag("WordPress", description);
            self.define("WordPressUser", wordpress_user());
            if !global("users") {
                self.paths.add("/users", "get", Op::new("WordPress", "users.list", "List users", "WordPress's users, as WordPress shows them to the caller: authors of public content to everyone, every user to those who may list users.")
                .params(paged())
                .params([search()])
                .ok(200, page(self.own_ref("WordPressUser")))
                .errors(&[400, 401, 403]));
            }
            self.paths.add(
                "/users/{id}",
                "get",
                Op::new(
                    "WordPress",
                    "users.get",
                    "Get a user",
                    "One WordPress user, as WordPress shows them to the caller.",
                )
                .params([path_param("id", "The user's id.", integer())])
                .ok(200, envelope("The user.", self.own_ref("WordPressUser")))
                .errors(&[401, 403, 404]),
            );
        }

        if !has("menus") {
            self.tag("WordPress", description);
            self.define(
                "MenuItem",
                json!({ "type": "object", "properties": {
                "label": text(), "url": text(),
                "item": { "type": ["object", "null"], "properties": { "kind": { "type": "string" }, "id": integer() } },
                "children": { "type": "array", "items": self.own_ref("MenuItem") },
            } }),
            );
            self.define(
                "Menu",
                json!({ "type": "object", "properties": {
                "location": { "type": "string" }, "items": { "type": "array", "items": self.own_ref("MenuItem") },
            }, "required": ["location", "items"] }),
            );
            let anyone = Access::anyone();
            if !global("menus") {
                self.paths.add(
                    "/menus",
                    "get",
                    Op::new(
                        "WordPress",
                        "menus.list",
                        "List menus",
                        "The menu in each theme location, as a tree.",
                    )
                    .rule(&anyone)
                    .ok(
                        200,
                        envelope("The menus.", json!({ "type": "array", "items": self.own_ref("Menu") })),
                    ),
                );
            }
            self.paths.add(
                "/menus/{location}",
                "get",
                Op::new(
                    "WordPress",
                    "menus.get",
                    "Get a menu",
                    "The menu in one theme location.",
                )
                .rule(&anyone)
                .params([path_param(
                    "location",
                    "The theme location, for example `primary`.",
                    json!({ "type": "string" }),
                )])
                .ok(200, envelope("The menu.", self.own_ref("Menu")))
                .errors(&[404]),
            );
        }

        if !has("settings") && !global("settings") {
            self.tag("WordPress", description);
            self.define("SiteSettings", json!({ "type": "object", "properties": {
                "title": { "type": "string" }, "tagline": { "type": "string" }, "timezone": { "type": "string" },
                "locale": { "type": "string" }, "icon": optional_integer(), "logo": optional_integer(), "frontPage": optional_integer(),
            }, "required": ["title", "tagline", "timezone", "locale", "icon", "logo", "frontPage"] }));
            let settings = envelope("The settings.", self.own_ref("SiteSettings"));
            self.paths.add(
                "/settings",
                "get",
                Op::new(
                    "WordPress",
                    "settings.get",
                    "Read site settings",
                    "The site's title, tagline, timezone, locale, icon, logo and front page.",
                )
                .rule(&Access::anyone())
                .ok(200, settings.clone()),
            );
            self.paths.add("/settings", "patch", Op::new("WordPress", "settings.update", "Update site settings", "Changes the fields sent. Any other field is refused.")
                .rule(&Access::Role("admin".into()))
                .body(body("The fields to change.", json!({ "type": "object", "properties": {
                    "title": { "type": "string" }, "tagline": { "type": "string" }, "timezone": { "type": "string" },
                    "locale": { "type": "string" }, "icon": optional_integer(), "logo": optional_integer(), "frontPage": optional_integer(),
                } })))
                .ok(200, settings));
        }
    }

    /// `GET /search`: the same search on every kind with a searchable field and a list.
    fn search(&mut self) {
        let kinds: Vec<&Collection> = self
            .schema
            .collections
            .values()
            .filter(|c| c.api.list && !js::searchable(c).is_empty())
            .collect();
        if kinds.is_empty() {
            return;
        }
        let names: Vec<&str> = kinds.iter().map(|c| c.table.as_str()).collect();
        let rows: Vec<Value> = kinds
            .iter()
            .map(|c| reference(&self.names.collections[&c.table]))
            .collect();
        self.tag("Search", "One search over every kind with a searchable field.");
        self.paths.add("/search", "get", Op::new("Search", "search", "Search the site", "Runs the kinds' `search` on every kind with a searchable field, as the caller. A kind the caller may not read is left out, and so is a `kinds` name that is not a searchable kind.")
            .rule(&Access::anyone())
            .params([
                json!({ "name": "q", "in": "query", "required": true, "description": "The words to find.", "schema": { "type": "string", "maxLength": 200 } }),
                query("kinds", &format!("Only these kinds, comma-separated: {}.", names.iter().map(|n| format!("`{n}`")).collect::<Vec<_>>().join(", ")), json!({ "type": "string" })),
                query("limit", "Rows per kind: 10 unless given, and never more than 50.", json!({ "type": "integer", "minimum": 1, "default": 10 })),
            ])
            .ok(200, envelope("The matching rows, by kind; a kind with none is absent.", json!({ "type": "object", "properties": {
                "q": { "type": "string" },
                "results": { "type": "array", "items": { "type": "object", "properties": {
                    "kind": { "type": "string", "enum": names },
                    "rows": { "type": "array", "items": { "anyOf": rows } },
                }, "required": ["kind", "rows"] } },
            }, "required": ["q", "results"] })))
            .errors(&[400]));
    }

    /// Who is asking, and the sign-in routes SpacePress Identity adds.
    fn account(&mut self) {
        self.tag("Account", "The signed-in user and their session.");
        let role = json!({ "type": "string", "enum": crate::schema::ROLES });
        let basics = json!({ "id": { "type": "integer" }, "name": { "type": "string" }, "role": role });
        let members = self.schema.collections.values().find(|c| c.auth);
        // A user as sign-in returns them: with a users kind, their own row under `member`.
        let mut user = json!({ "type": "object", "properties": basics, "required": ["id", "name", "role"] });
        if let Some(members) = members {
            user["properties"]["member"] =
                json!({ "anyOf": [reference(&self.names.collections[&members.table]), { "type": "null" }] });
        }
        self.define("User", user);
        // `/me` is the caller's own row as they may read it, with `id`, `name` and SpacePress's `role`
        // over it: a users kind's row, or WordPress's own fields without one.
        let mut properties = match members {
            Some(members) => js::row(members)["properties"].clone(),
            None => wordpress_user()["properties"].clone(),
        };
        for (name, schema) in basics.as_object().into_iter().flatten() {
            properties[name] = schema.clone();
        }
        self.define(
            "CurrentUser",
            json!({ "type": "object", "properties": properties, "required": ["id", "name", "role"] }),
        );
        let me = json!({ "anyOf": [self.own_ref("CurrentUser"), { "type": "null" }] });
        self.paths.add(
            "/me",
            "get",
            Op::new(
                "Account",
                "auth.me",
                "The signed-in user",
                "The user making the request, with their role; null when nobody is signed in.",
            )
            .rule(&Access::anyone())
            .ok(200, envelope("The user, or null.", me)),
        );
        self.paths.add(
            "/auth/config",
            "get",
            Op::new(
                "Account",
                "auth.config",
                "Sign-in settings",
                "Where SpacePress Identity signs users in (null without it), and whether guests are allowed.",
            )
            .rule(&Access::anyone())
            .ok(
                200,
                envelope(
                    "The settings.",
                    json!({ "type": "object", "properties": {
                "issuer": { "type": ["string", "null"] }, "guests": { "type": "boolean" },
            }, "required": ["issuer", "guests"] }),
                ),
            ),
        );
        self.paths.add("/auth/guest", "post", Op::new("Account", "auth.guest", "Start a guest session", "Creates a guest user and signs them in. A 404 when SpacePress Identity is not installed or guests are off.")
            .rule(&Access::anyone())
            .ok(201, envelope("The guest's tokens and user.", json!({ "type": "object", "properties": {
                "accessToken": { "type": "string" }, "refreshToken": { "type": "string" }, "expiresIn": { "type": "integer" },
                "clientId": { "type": "string" }, "user": self.own_ref("User"),
            }, "required": ["accessToken", "refreshToken", "expiresIn", "clientId", "user"] })))
            .errors(&[404]));
        self.paths.add("/auth/session", "delete", Op::new("Account", "auth.signOut", "Sign out", "Ends the SpacePress Identity session the request's access token belongs to. A cookie or application password signs out through WordPress instead, and is refused here.")
            .rule(&Access::Role("authenticated".into()))
            .ok(200, envelope("True.", json!({ "type": "boolean" })))
            .errors(&[404]));
    }

    /// `POST /_live` (`runtime/SpacePress/Live/Module.php`): the reads a page is subscribed to, each
    /// answered "not modified" or with its fresh result, as the caller.
    fn live(&mut self) {
        self.tag(
            "Live",
            "Reads a page is subscribed to, kept current without a push transport.",
        );
        let kinds: Vec<Value> = self.schema.collections.values().map(|c| json!(c.key)).collect();
        let query = json!({ "type": "object", "properties": {
            "kind": { "type": "string", "enum": kinds, "description": "The kind, by the name the typed client uses." },
            "op": { "type": "string", "enum": ["list", "paginate", "get", "count"] },
            "args": { "type": "object", "description": "The arguments of that call on the kind: `where`, `orderBy`, `limit`, `offset` or `cursor`, `with`, `search`, `locale`." },
            "id": { "type": ["integer", "string"], "description": "The row of a `get`; or give `args.where`." },
            "token": { "type": "string", "description": "What the previous answer to the same read returned. Opaque." },
        }, "required": ["kind", "op"] });
        let result = json!({ "type": "object", "description": "`{ changed: true, token, data }` with the read's result as the caller reads it; `{ changed: false, token }` when what the caller holds is current; `{ error }` with the read's problem; `{ pending: true }` when the request ran out of time (ask again).", "properties": {
            "changed": { "type": "boolean" }, "token": { "type": "string" }, "data": {}, "error": self.own_ref("Problem"), "pending": { "type": "boolean" },
        } });
        let max = LIVE_MAX_QUERIES;
        self.paths.add("/_live", "post", Op::new("Live", "live.refresh", "Refresh subscribed reads", format!("Takes up to {max} reads, each with the `token` of the answer the caller last got, and answers each one as the caller, with every access rule and field rule applied. The typed client's `subscribe` calls it. An answer never says that something changed which the caller may not read."))
            .rule(&Access::anyone())
            .body(body("The subscribed reads.", json!({ "type": "object", "properties": { "queries": { "type": "array", "items": query, "minItems": 1, "maxItems": max } }, "required": ["queries"] })))
            .ok(200, envelope("One result per read, in order, and the wait the client keeps between two requests.", json!({ "type": "object", "properties": {
                "results": { "type": "array", "items": result },
                "poll": { "type": "object", "properties": { "minMs": { "type": "integer" }, "maxMs": { "type": "integer" } }, "required": ["minMs", "maxMs"] },
            }, "required": ["results", "poll"] })))
            .errors(&[405, 413]));
    }
}

/// The app's API as an OpenAPI 3.1 document.
pub fn document(schema: &Schema) -> Value {
    let label = crate::schema::title_case(&schema.name);
    let names = crate::client::names(schema);
    let mut taken = HashSet::new();
    for name in names.collections.values() {
        taken.extend(["", "Insert", "Patch", "Filter"].map(|suffix| format!("{name}{suffix}")));
    }
    for name in names.globals.values() {
        taken.extend(["", "Patch"].map(|suffix| format!("{name}{suffix}")));
    }
    let mut b = Builder {
        schema,
        names,
        taken,
        paths: Paths::default(),
        components: Map::new(),
        tags: Vec::new(),
    };
    b.define("Problem", json!({
        "type": "object",
        "description": "An RFC 9457 problem document.",
        "properties": {
            "type": { "type": "string", "description": "Always `about:blank`: `code` names the problem." },
            "title": { "type": "string", "description": "The problem in a few words." },
            "status": { "type": "integer", "description": "The HTTP status." },
            "code": { "type": "string", "description": "A stable name for the problem, such as `unauthenticated`, `forbidden`, `not_found`, `invalid_input`, `invalid_query`, `invalid_json`, `method_not_allowed`, `unique_violation`, `reference_violation`, `stale_revision` or `locked`." },
            "detail": { "type": "string", "description": "What went wrong, for a person." },
            "pointer": { "type": "string", "description": "A JSON pointer to the field of the request at fault, when there is one." },
            "_rev": { "type": "string", "description": "`stale_revision`: the row's current token." },
            "changed": { "type": "object", "description": "`stale_revision`: what changed since your read.", "properties": {
                "fields": { "type": ["array", "null"], "items": { "type": "string" }, "description": "The fields that differ, as you may read them; null when there is no revision to compare with." },
                "staged": { "type": "boolean", "description": "Whether someone else changed the staged version (a staged version's token only)." },
            } },
            "holder": { "type": "object", "description": "`locked`: who holds the edit lock.", "properties": { "id": { "type": "integer" }, "name": { "type": "string" } } },
        },
        "required": ["type", "title", "status", "code", "detail"],
    }));
    b.define("RevisionSummary", json!({ "type": "object", "properties": {
        "id": { "type": "integer" },
        "version": { "type": "integer" },
        "op": { "type": "string", "enum": ["create", "update", "delete", "restore", "purge"] },
        "at": { "type": "string", "format": "date-time" },
        "user": { "type": ["object", "null"], "properties": { "id": { "type": "integer" }, "name": { "type": "string" } } },
        "schemaMigration": { "type": ["string", "null"] },
        "changed": { "type": ["array", "null"], "items": { "type": "string" } },
    }, "required": ["id", "version", "op", "at", "user", "schemaMigration", "changed"] }));
    b.define(
        "RevisionDiff",
        json!({ "type": "object", "properties": {
        "from": { "type": ["integer", "null"] },
        "to": { "type": "integer" },
        "changes": { "type": "array", "items": {
            "type": "object",
            "properties": { "field": { "type": "string" }, "from": {}, "to": {} },
            "required": ["field", "from", "to"],
        } },
    }, "required": ["from", "to", "changes"] }),
    );
    let size = json!({ "type": "object", "properties": {
        "url": { "type": "string" }, "width": { "type": "integer" }, "height": { "type": "integer" },
    } });
    b.define("Media", json!({ "type": "object", "properties": {
        "id": { "type": "integer" }, "url": { "type": "string" }, "mime": { "type": "string" }, "size": { "type": "integer" },
        "width": { "type": ["integer", "null"] }, "height": { "type": ["integer", "null"] }, "alt": { "type": ["string", "null"] },
        "filename": { "type": "string" }, "createdAt": { "type": "string", "format": "date-time" },
        "sizes": { "type": "object", "additionalProperties": size },
        "focal": { "type": ["object", "null"], "properties": { "x": { "type": "number" }, "y": { "type": "number" } } },
        "author": { "type": ["integer", "null"] }, "caption": { "type": ["string", "null"] },
    }, "required": ["id", "url", "mime"] }));
    if !schema.files.is_empty() {
        b.define("PrivateFile", json!({ "type": "object", "properties": {
            "id": { "type": "integer" }, "bucket": { "type": "string" }, "filename": { "type": "string" }, "mime": { "type": "string" },
            "size": { "type": "integer" }, "ownerId": { "type": ["integer", "null"] }, "createdAt": { "type": "string", "format": "date-time" },
            "url": { "type": "string", "description": "A signed link to the file's bytes, valid for a short time." },
        }, "required": ["id", "bucket", "filename", "mime", "size", "ownerId", "createdAt", "url"] }));
    }

    for c in schema.collections.values() {
        b.kind(c);
    }
    for g in schema.globals.values() {
        b.global(g);
    }
    for a in schema.actions.values() {
        b.action(a);
    }
    for (name, bucket) in &schema.files {
        b.bucket(name, bucket);
    }
    b.wordpress();
    b.account();
    b.live();
    b.search();
    b.tag("Schema", "The app described to its clients.");
    b.paths.add("/", "get", Op::new("Schema", "schema", "Describe the app", "The app's kinds with the WordPress route behind each, their fields and access rules, its globals and its actions. `spacepress sync` and `spacepress doctor` read it.")
        .rule(&Access::anyone())
        .ok(200, envelope("The app's schema.", json!({ "type": "object", "properties": {
            "name": { "type": "string" },
            "runtime": { "type": "object", "description": "The SpacePress runtime series and the WordPress, PHP, database and plugin versions under it." },
            "site": { "type": "object", "description": "The site's id, for `spacepress sync`." },
            "locales": { "type": ["object", "null"] }, "media": { "type": "object" }, "files": { "type": "object" }, "auth": { "type": "object" },
            "kinds": { "type": "object", "description": "Each kind by name: the WordPress route behind it, its fields, relations and access rules." },
            "globals": { "type": "object" }, "actions": { "type": "object" },
        }, "required": ["name", "kinds", "globals", "actions"] }))));
    if schema.store.is_some() {
        b.paths.add("/store", "get", Op::new("Store", "store-get", "Store information", "Currency, precision, origin and checkout URL. No shopper state.")
            .rule(&Access::anyone()).ok(200, envelope("Store information.", json!({"type":"object","properties":{"currency":{"type":"string"},"precision":{"type":"integer"},"country":{"type":"string"},"checkout":{"type":"string"},"cart":{"type":"string"}},"required":["currency","precision","country","checkout","cart"]}))));
    }
    b.paths.add(ROUTE, "get", Op::new("Schema", "openapi", "This document", "The app's API as an OpenAPI 3.1 document: what `spacepress build` wrote to `sp/_generated/openapi.json`, with this site's address as its server.")
        .rule(&Access::anyone())
        .ok(200, json!({ "description": "The OpenAPI document.", "content": { "application/json": { "schema": { "type": "object" } } } })));

    let description = [
                format!("The HTTP API of the `{}` app, built with SpacePress and served under `{PREFIX}` on its WordPress site.", schema.name),
                "Each request is dispatched to the WordPress REST route behind it, which enforces the app's access rules, field rules, validation and hooks; this API gives the requests and answers one plain shape.".into(),
                "A successful answer is JSON with the result under `data`; a list also has `cursor`, the next page's cursor or null. An error is an RFC 9457 problem document, `application/problem+json`, with `type`, `title`, `status`, `code` and `detail`, and `pointer` when one field is at fault.".into(),
                "Requests sign in as WordPress core accepts: the logged-in cookie with an `X-WP-Nonce` header, an application password, or a SpacePress Identity access token. Each operation says who may call it. A field the caller may not read is left out of rows, and can't be filtered or sorted on.".into(),
                "`spacepress build` writes this document from the app's schema, with the typed client in `sp/_generated/client/` and the app's WordPress Abilities; a component here has the name of the client's type for the same value.".into(),
            ].join("\n\n");
    let problem_schema = b.own_ref("Problem");
    let paths: Map<String, Value> = b
        .paths
        .0
        .into_iter()
        .map(|(path, ops)| (path, Value::Object(ops)))
        .collect();
    json!({
        "openapi": "3.1.0",
        "info": {
            "title": format!("{label} API"),
            "version": "v1",
            "description": description,
        },
        "servers": [{ "url": PREFIX, "description": "The app's WordPress site." }],
        // An operation that also answers without signing in, or that reads no sign-in at all, says so itself.
        "security": security(false),
        "tags": b.tags,
        "paths": paths,
        "components": {
            "schemas": b.components,
            "parameters": paging_parameters(),
            "responses": { "Problem": {
                "description": "An error, as an RFC 9457 problem document.",
                "content": { "application/problem+json": { "schema": problem_schema } },
            } },
            "securitySchemes": {
                "cookie": { "type": "apiKey", "in": "cookie", "name": "wordpress_logged_in_{hash}", "description": "WordPress's logged-in cookie, as a browser holds it after signing in. A request with it must also carry `X-WP-Nonce`." },
                "nonce": { "type": "apiKey", "in": "header", "name": "X-WP-Nonce", "description": "A WordPress REST nonce (`wp_create_nonce('wp_rest')`) for the cookie's session." },
                "applicationPassword": { "type": "http", "scheme": "basic", "description": "A WordPress user name and one of the user's application passwords." },
                "bearer": { "type": "http", "scheme": "bearer", "bearerFormat": "JWT", "description": "A SpacePress Identity access token." },
            },
        },
    })
}

/// The document as `spacepress build` writes it: two-space indented, with a list of plain values or a
/// small object of them on one line (`"type": ["string", "null"]`, `{ "$ref": "…" }`), so the
/// committed file is short enough to read in a diff.
pub fn render(document: &Value) -> String {
    /// The widest value written on one line.
    const WIDTH: usize = 100;
    fn flat(value: &Value) -> bool {
        match value {
            Value::Object(_) => false,
            Value::Array(items) => items.iter().all(|item| !item.is_object() && !item.is_array()),
            _ => true,
        }
    }
    fn inline(value: &Value) -> Option<String> {
        let text = match value {
            Value::Object(map) if map.is_empty() => "{}".to_string(),
            Value::Object(map) if map.values().all(flat) => {
                let fields: Vec<String> = map
                    .iter()
                    .map(|(key, child)| Some(format!("{}: {}", Value::String(key.clone()), inline(child)?)))
                    .collect::<Option<_>>()?;
                format!("{{ {} }}", fields.join(", "))
            }
            Value::Object(_) => return None,
            Value::Array(items) if items.iter().all(|item| !item.is_object() && !item.is_array()) => {
                let items: Vec<String> = items.iter().map(Value::to_string).collect();
                format!("[{}]", items.join(", "))
            }
            Value::Array(_) => return None,
            other => return Some(other.to_string()),
        };
        (text.len() <= WIDTH).then_some(text)
    }
    fn write(out: &mut String, value: &Value, depth: usize) {
        if let Some(text) = inline(value) {
            out.push_str(&text);
            return;
        }
        let pad = |out: &mut String, depth: usize| out.push_str(&"  ".repeat(depth));
        let (open, close) = if value.is_object() { ('{', '}') } else { ('[', ']') };
        out.push(open);
        out.push('\n');
        let entry = |out: &mut String, key: Option<&String>, child: &Value, last: bool| {
            pad(out, depth + 1);
            if let Some(key) = key {
                out.push_str(&Value::String(key.clone()).to_string());
                out.push_str(": ");
            }
            write(out, child, depth + 1);
            out.push_str(if last { "\n" } else { ",\n" });
        };
        match value {
            Value::Object(map) => {
                for (i, (key, child)) in map.iter().enumerate() {
                    entry(out, Some(key), child, i + 1 == map.len());
                }
            }
            Value::Array(items) => {
                for (i, child) in items.iter().enumerate() {
                    entry(out, None, child, i + 1 == items.len());
                }
            }
            _ => unreachable!("a scalar is written on one line"),
        }
        pad(out, depth);
        out.push(close);
    }
    let mut out = String::new();
    write(&mut out, document, 0);
    out.push('\n');
    out
}

#[cfg(test)]
mod tests {
    use serde_json::{Value, json};

    /// The document for an app written as `spacepress.config.ts` plus `sp/schema.ts`.
    fn document(schema_ts: &str) -> Value {
        let files = [
            (
                "/app/spacepress.config.ts",
                "import { defineConfig } from \"spacepress\";\nexport default defineConfig({ name: \"app\" });\n",
            ),
            ("/app/sp/schema.ts", schema_ts),
        ]
        .into_iter()
        .map(|(path, source)| (std::path::PathBuf::from(path), source.to_string()))
        .collect();
        let program = crate::loader::load_from(
            std::rc::Rc::new(crate::wasm::Memory::new(files)),
            std::path::Path::new("/app/spacepress.config.ts"),
        )
        .unwrap_or_else(|d| panic!("{d:?}"));
        crate::pipeline::build(
            std::path::Path::new("/app"),
            &program,
            || Ok(crate::pipeline::Previous::none()),
            |_| None,
        )
        .unwrap_or_else(|d| panic!("{}", d.message))
        .openapi
    }

    /// Every `$ref` in the document names something the document defines.
    fn dangling(document: &Value) -> Vec<String> {
        fn walk(value: &Value, document: &Value, out: &mut Vec<String>) {
            match value {
                Value::Object(map) => {
                    if let Some(reference) = map.get("$ref").and_then(Value::as_str)
                        && document.pointer(reference.trim_start_matches('#')).is_none()
                    {
                        out.push(reference.to_string());
                    }
                    map.values().for_each(|v| walk(v, document, out));
                }
                Value::Array(items) => items.iter().for_each(|v| walk(v, document, out)),
                _ => {}
            }
        }
        let mut out = Vec::new();
        walk(document, document, &mut out);
        out
    }

    /// A kind or global named like one of WordPress's own kinds takes its place where the API gives
    /// it that place, and every reference still resolves: a `categories` kind leaves `tags` its own
    /// term schema, and a global named `posts` takes only `GET /posts`.
    #[test]
    fn names_shared_with_wordpress_kinds_leave_no_dangling_reference() {
        let doc = document(
            "import { collection, global, text } from \"spacepress\";\n\
             export const categories = collection({ title: text() }, { title: \"title\" });\n\
             export const posts = global({ motto: text().default(\"x\") });\n",
        );
        assert_eq!(dangling(&doc), Vec::<String>::new());
        assert_eq!(doc["paths"]["/tags"]["get"]["operationId"], "tags.list");
        assert_eq!(doc["paths"]["/categories"]["get"]["operationId"], "categories.list");
        assert_eq!(doc["paths"]["/posts"]["get"]["operationId"], "globals.posts.get");
        assert!(
            doc["paths"]["/posts"].get("post").is_none(),
            "{}",
            doc["paths"]["/posts"]
        );
        assert_eq!(doc["paths"]["/posts/{id}"]["get"]["operationId"], "posts.get");
    }

    #[test]
    fn the_written_document_parses_back_to_the_same_value() {
        let document = json!({
            "empty": {}, "none": [], "types": ["string", "null"], "ref": { "$ref": "#/components/schemas/A \"quoted\"" },
            "nested": { "list": [{ "a": 1 }, [1, 2], "x"], "deep": { "wide": "x".repeat(120), "n": 1.5, "t": true, "z": null } },
        });
        let text = super::render(&document);
        assert_eq!(serde_json::from_str::<serde_json::Value>(&text).unwrap(), document);
        assert!(text.contains("\"types\": [\"string\", \"null\"]"), "{text}");
        assert!(text.ends_with("}\n"));
    }
}
