//! A compiled project as plain JSON for a reader that is not the runtime: its kinds, fields, access
//! rules, actions, jobs and routes, under the names an author writes. `typedpress mcp`'s `schema` tool
//! answers with it. It reads the schema the pipeline built (the one `typedpress check` compiles) and adds
//! nothing to it.

use crate::schema::{Access, App, ColType, Collection, Column, DefaultKind, RelKind, Schema};
use crate::wordpress::abilities::{kebab, own_inputs};
use indexmap::IndexMap;
use serde_json::{Map, Value, json};

/// The app's HTTP API below the site root (`TypedPress\Wp\Facade::PREFIX`).
pub(super) const API: &str = crate::sync::client::API;

fn access(rule: &Access) -> Value {
    match rule {
        Access::Allow(allowed) => json!(allowed),
        Access::Self_ => json!("self"),
        Access::Owner => json!("owner"),
        Access::Role(role) => json!(role),
        // A rule written as a function compiles to PHP; its body is in the project's source.
        Access::Fn(_) => json!("function"),
    }
}

fn access_map(rules: &IndexMap<String, Access>) -> Value {
    Value::Object(rules.iter().map(|(op, rule)| (op.clone(), access(rule))).collect())
}

fn fields(columns: &IndexMap<String, Column>, schema: &Schema) -> Value {
    Value::Object(
        columns
            .values()
            .filter(|c| !c.hidden)
            .map(|c| (c.name.clone(), field(c, schema)))
            .collect(),
    )
}

/// One field. Only what differs from a plain optional field is listed, so a large schema stays readable.
fn field(c: &Column, schema: &Schema) -> Value {
    let mut out = Map::new();
    // A single `select([...])` is stored as text with its values; an author knows it as a select.
    let single_select = c.ty == ColType::Text && c.enum_values.is_some();
    // A `ref(kind)` is stored as the target's id; an author knows it as a ref.
    let authored = if single_select {
        "select"
    } else if c.references.is_some() {
        "ref"
    } else if c.ty == ColType::MediaList {
        // `media().many()`; the list is the `many` flag below.
        "media"
    } else {
        c.ty.builder_name()
    };
    out.insert("type".into(), json!(authored));
    // TypedPress fills it in: the id, the status and timestamps, a slug derived from another field.
    let generated = c.system || c.derived || c.auto_increment || c.ty == ColType::Slug && c.source.is_some();
    // A write must supply it: required, and nothing fills it in.
    if !c.optional_on_insert() && !generated {
        out.insert("required".into(), json!(true));
    }
    let mut flag = |name: &str, on: bool| {
        if on {
            out.insert(name.into(), json!(true));
        }
    };
    flag("primaryKey", c.primary_key);
    flag("generated", generated);
    // `select([...]).many()` and `media().many()` hold a list.
    flag("many", c.ty.is_list_kind());
    flag("unique", c.unique && !c.primary_key);
    flag("multiline", c.multiline);
    flag("localized", c.localized);
    match &c.default {
        Some(DefaultKind::Value(value)) => out.insert("default".into(), value.clone()),
        Some(DefaultKind::Now) => out.insert("default".into(), json!("now")),
        Some(DefaultKind::Fn(_)) => out.insert("default".into(), json!("function")),
        None => None,
    };
    if let Some(values) = &c.enum_values {
        out.insert("values".into(), json!(values));
    }
    if let Some(source) = &c.source {
        out.insert("source".into(), json!(source));
    }
    if let Some(reference) = &c.references {
        let kind = schema
            .collections
            .get(&reference.collection)
            .map_or(reference.collection.as_str(), |target| target.key.as_str());
        out.insert("to".into(), json!(kind));
        if let Some(action) = reference.on_delete {
            out.insert("onDelete".into(), json!(action.sql()));
        }
    }
    for (name, limit) in [("min", c.rules.min), ("max", c.rules.max)] {
        if let Some(limit) = limit {
            // A whole number reads as one: `160`, not `160.0`.
            let whole = limit.fract() == 0.0 && limit.abs() < 9e15;
            out.insert(name.into(), if whole { json!(limit as i64) } else { json!(limit) });
        }
    }
    if let Some((source, flags)) = &c.rules.pattern {
        out.insert("pattern".into(), json!(format!("/{source}/{flags}")));
    }
    if let Some(accept) = &c.accept {
        out.insert("accept".into(), json!(accept));
    }
    if let Some(bucket) = &c.bucket {
        out.insert("bucket".into(), json!(bucket));
    }
    if !c.access.is_empty() {
        out.insert("access".into(), access_map(&c.access));
    }
    if let Some(nested) = &c.fields {
        out.insert("fields".into(), fields(nested, schema));
    }
    if let Some(layouts) = &c.layouts {
        out.insert(
            "layouts".into(),
            Value::Object(
                layouts
                    .iter()
                    .map(|(name, nested)| (name.clone(), fields(nested, schema)))
                    .collect(),
            ),
        );
    }
    Value::Object(out)
}

fn kind(c: &Collection, schema: &Schema) -> Value {
    let operations: Vec<&str> = [
        ("list", c.api.list),
        ("get", c.api.get),
        ("create", c.api.create),
        ("update", c.api.update),
        ("delete", c.api.delete),
    ]
    .into_iter()
    .filter_map(|(op, on)| on.then_some(op))
    .collect();
    let mut out = json!({
        "type": if c.auth { "users" } else if c.editor { "collection" } else if c.taxonomy.is_some() { "taxonomy" } else { "table" },
        "label": c.label,
        // The path segment below the API prefix, and the name the site commands take.
        "path": c.table,
        "fields": fields(&c.columns, schema),
        "access": access_map(&c.access),
        "operations": operations,
        // Whether the site's MCP endpoint lists this kind's operations as tools.
        "agents": c.agents,
    });
    if !c.relations.is_empty() {
        out["relations"] = Value::Object(
            c.relations
                .values()
                .map(|r| {
                    let to = schema
                        .collections
                        .get(&r.collection)
                        .map_or(r.collection.as_str(), |target| target.key.as_str());
                    (
                        r.name.clone(),
                        json!({ "kind": if r.kind == RelKind::One { "one" } else { "many" }, "to": to }),
                    )
                })
                .collect(),
        );
    }
    if !c.hooks.is_empty() {
        out["hooks"] = json!(c.hooks.keys().map(|hook| hook.name()).collect::<Vec<_>>());
    }
    for (name, on) in [
        ("drafts", c.drafts),
        ("revisions", c.revisions),
        ("comments", c.comments),
        ("localized", c.localized),
    ] {
        if on {
            out[name] = json!(true);
        }
    }
    if let Some(url) = &c.url {
        out["url"] = json!(url);
    }
    if let Some(sync) = &c.sync {
        out["sync"] = sync.clone();
    }
    out
}

/// `tp/app/`: each page and HTTP route by its URL pattern, most specific first.
fn routes(app: &App, schema: &Schema) -> Vec<Value> {
    app.routes
        .iter()
        .chain(&app.not_found)
        .chain(&app.login)
        .map(|route| {
            let file = route.page.as_ref().or(route.handler.as_ref());
            let compiled = file.and_then(|file| app.files.get(file));
            let mut out = json!({
                "pattern": route.pattern,
                "type": if route.page.is_some() { "page" } else { "route" },
            });
            if let Some(file) = file {
                out["file"] = json!(format!("tp/{file}"));
            }
            if let Some(compiled) = compiled {
                if !compiled.methods.is_empty() {
                    out["methods"] = json!(compiled.methods.keys().collect::<Vec<_>>());
                }
                match (&compiled.guard, &compiled.handler_guard) {
                    (Some(page), Some(handlers)) if page != handlers => {
                        out["guard"] = json!({ "page": access(page), "handlers": access(handlers) });
                    }
                    (None, Some(handlers)) if route.page.is_some() => {
                        out["guard"] = json!({ "handlers": access(handlers) });
                    }
                    (Some(rule), _) | (None, Some(rule)) => out["guard"] = access(rule),
                    (None, None) => {}
                }
                if compiled.signature.is_some() {
                    out["signed"] = json!(true);
                }
            }
            if !route.layouts.is_empty() {
                out["layouts"] = json!(
                    route
                        .layouts
                        .iter()
                        .map(|layout| format!("tp/{layout}"))
                        .collect::<Vec<_>>()
                );
            }
            // The kind whose `url` this page serves, by the name `kinds` lists it under.
            if let Some((kind, _)) = &route.kind {
                out["kind"] = json!(schema.collections.get(kind).map_or(kind, |c| &c.key));
            }
            out
        })
        .collect()
}

/// The whole project. `kinds` are keyed by the name the author exported or registered.
pub(super) fn project(schema: &Schema) -> Value {
    let app = kebab(&schema.name);
    let kinds: Map<String, Value> = schema
        .collections
        .values()
        .map(|c| (c.key.clone(), kind(c, schema)))
        .collect();
    let globals: Map<String, Value> = schema
        .globals
        .values()
        .map(|g| {
            (
                g.name.clone(),
                json!({ "label": g.label, "fields": fields(&g.fields, schema), "access": access_map(&g.access), "agents": g.agents }),
            )
        })
        .collect();
    let actions: Map<String, Value> = schema
        .actions
        .values()
        .map(|a| {
            let mut out = json!({
                "input": fields(&a.input, schema),
                "access": access(&a.access),
                "endpoint": format!("{} {API}/actions/{}", if a.readonly { "GET" } else { "POST" }, a.name),
                "ability": format!("{app}/{}", kebab(&a.name)),
                "agents": a.agents,
            });
            for (name, on) in [
                ("readonly", a.readonly),
                ("destructive", a.destructive),
                ("idempotent", a.idempotent),
                ("signed", a.signature.is_some()),
            ] {
                if on {
                    out[name] = json!(true);
                }
            }
            if let Some(output) = &a.output {
                out["output"] = output.clone();
            }
            (a.name.clone(), out)
        })
        .collect();
    let jobs: Map<String, Value> = schema
        .jobs
        .iter()
        .map(|(name, job)| {
            (
                name.clone(),
                json!({ "input": fields(&job.input, schema), "retries": job.retries, "agents": job.agents }),
            )
        })
        .collect();
    let files: Map<String, Value> = schema
        .files
        .iter()
        .map(|(name, bucket)| {
            (
                name.clone(),
                json!({ "accept": bucket.accept, "maxBytes": bucket.max_bytes, "access": access_map(&bucket.access) }),
            )
        })
        .collect();
    let mut out = json!({
        "name": schema.name,
        "kinds": kinds,
        "globals": globals,
        "actions": actions,
        "jobs": jobs,
        "routes": schema.app.as_ref().map(|app| routes(app, schema)).unwrap_or_default(),
        "files": files,
        // Lowest to highest: an access rule naming a role admits that role and every one after it.
        "roles": crate::schema::ROLES,
        // Every Ability the site registers for this app; the site's MCP endpoint names each tool
        // with the slash replaced by a hyphen.
        "abilities": own_inputs(schema).into_keys().collect::<Vec<_>>(),
        "endpoints": {
            "api": API,
            "mcp": format!("/wp-json/mcp/typedpress-{app}"),
            "abilities": "/wp-json/wp-abilities/v1/abilities",
        },
    });
    if let Some(locales) = &schema.locales {
        out["locales"] = locales.clone();
    }
    // Both admins: TypedPress Admin (unless `admin: false`) and classic wp-admin, below the site URL.
    out["admin"] = if schema.admin {
        json!({ "default": "/wp-admin/new/", "classic": "/wp-admin/" })
    } else {
        json!({ "default": "/wp-admin/", "classic": "/wp-admin/" })
    };
    out
}
