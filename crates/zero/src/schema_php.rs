//! Emits `zero/app/schema.php` (version 3, phase-2 §3.1: the runtime's whole view
//! of the app, WordPress identifiers included) and `zero.gen.ts` (registers the
//! schema with the `"zero"` types).

use crate::compile::{php_json, php_str};
use crate::num::php_number;
use crate::schema::{Access, Collection, Column, DefaultKind, RelKind, Schema};
use indexmap::IndexMap;
use std::fmt::Write as _;

/// Where a column appears: keys that only make sense at the top level are dropped elsewhere.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Ctx {
    Column,
    /// Sub-fields, global fields, action inputs.
    Nested,
}

fn generator(d: &DefaultKind) -> String {
    match d {
        // Container literals carry JSON text so schema.php never erases their object/list shape.
        DefaultKind::Value(v) if v.is_object() || v.is_array() => format!(
            "['value' => {}, 'json' => {}]",
            php_json(v),
            php_str(&serde_json::to_string(v).expect("JSON"))
        ),
        DefaultKind::Value(v) => format!("['value' => {}]", php_json(v)),
        DefaultKind::Now => "['now' => true]".to_string(),
        DefaultKind::Fn(name) => format!("['fn' => {}]", php_str(name)),
    }
}

fn num(n: f64) -> String {
    if n.fract() == 0.0 && n.abs() < 9.007_199_254_740_992e15 {
        format!("{}", n as i64)
    } else {
        php_number(n)
    }
}

fn column(c: &Column, ctx: Ctx, indent: usize) -> String {
    let top = ctx == Ctx::Column;
    let mut parts = vec![format!("'type' => {}", php_str(c.ty.name()))];
    if c.primary_key && top {
        parts.push("'primaryKey' => true".into());
    }
    if c.auto_increment && top {
        parts.push("'autoIncrement' => true".into());
    }
    let enum_php = c.enum_values.as_ref().map(|e| {
        format!(
            "'enum' => [{}]",
            e.iter().map(|v| php_str(v)).collect::<Vec<_>>().join(", ")
        )
    });
    // Kind options come right after the type (the order §2's example uses).
    let multiple = c.ty == crate::schema::ColType::Select;
    if multiple {
        parts.push("'multiple' => true".into());
        parts.extend(enum_php.clone());
    }
    if c.multiline {
        parts.push("'multiline' => true".into());
    }
    if let Some(a) = &c.accept {
        parts.push(format!("'accept' => {}", php_str(a)));
    }
    if c.not_null {
        parts.push("'notNull' => true".into());
    }
    if c.unique && top {
        parts.push("'unique' => true".into());
    }
    if !multiple {
        parts.extend(enum_php);
    }
    if let Some(s) = &c.source {
        parts.push(format!("'source' => {}", php_str(s)));
    }
    if let Some(d) = &c.default {
        parts.push(format!("'default' => {}", generator(d)));
    }
    if let Some(d) = &c.on_update
        && top
    {
        parts.push(format!("'onUpdate' => {}", generator(d)));
    }
    if let Some(r) = &c.references
        && top
    {
        let mut rp = vec![
            format!("'collection' => {}", php_str(&r.collection)),
            format!("'column' => {}", php_str(&r.column)),
        ];
        if let Some(a) = r.on_delete {
            rp.push(format!("'onDelete' => {}", php_str(a.sql())));
        }
        parts.push(format!("'references' => [{}]", rp.join(", ")));
    }
    if !c.rules.is_empty() {
        let mut rp = Vec::new();
        if let Some(n) = c.rules.min {
            rp.push(format!("'min' => {}", num(n)));
        }
        if let Some(n) = c.rules.max {
            rp.push(format!("'max' => {}", num(n)));
        }
        if let Some((source, flags)) = &c.rules.pattern {
            rp.push(format!(
                "'pattern' => ['source' => {}, 'flags' => {}]",
                php_str(source),
                php_str(flags)
            ));
        }
        if let Some(v) = &c.rules.validate {
            rp.push(format!("'validate' => {}", php_str(v)));
        }
        parts.push(format!("'rules' => [{}]", rp.join(", ")));
    }
    if let Some(fields) = &c.fields {
        parts.push(format!("'fields' => {}", fields_block(fields, indent + 1)));
    }
    if let Some(layouts) = &c.layouts {
        let pad = "    ".repeat(indent + 1);
        let mut s = String::from("[\n");
        for (name, fields) in layouts {
            let _ = writeln!(s, "{pad}    {} => {},", php_str(name), fields_block(fields, indent + 2));
        }
        let _ = write!(s, "{pad}]");
        parts.push(format!("'layouts' => {s}"));
    }
    if c.localized {
        parts.push("'localized' => true".into());
    }
    if !c.access.is_empty() {
        parts.push(format!("'access' => {}", access_map(&c.access)));
    }
    if let Some(ui) = &c.ui {
        parts.push(format!(
            "'ui' => {}",
            php_json(&serde_json::to_value(ui).expect("field UI serializes"))
        ));
    }
    if let Some(f) = &c.show_if {
        parts.push(format!("'showIf' => {}", php_json(f)));
    }
    if let Some(b) = &c.bucket {
        parts.push(format!("'bucket' => {}", php_str(b)));
    }
    if c.derived {
        parts.push("'derived' => true".into());
    }
    if c.hidden {
        parts.push("'hidden' => true".into());
    }
    if c.system && top {
        parts.push("'system' => true".into());
    }
    format!("[{}]", parts.join(", "))
}

fn fields_block(fields: &IndexMap<String, Column>, indent: usize) -> String {
    let pad = "    ".repeat(indent);
    let mut s = String::from("[\n");
    for f in fields.values() {
        let _ = writeln!(
            s,
            "{pad}    {} => {},",
            php_str(&f.name),
            column(f, Ctx::Nested, indent + 1)
        );
    }
    let _ = write!(s, "{pad}]");
    s
}

fn access(a: &Access) -> String {
    match a {
        Access::Self_ => "['self' => true]".into(),
        Access::Owner => "['owner' => true]".into(),
        Access::Allow(b) => format!("['allow' => {b}]"),
        Access::Role(r) => format!("['role' => {}]", php_str(r)),
        Access::Fn(f) => format!("['fn' => {}]", php_str(f)),
    }
}

fn access_map(rules: &IndexMap<String, Access>) -> String {
    format!(
        "[{}]",
        rules
            .iter()
            .map(|(op, r)| format!("{} => {}", php_str(op), access(r)))
            .collect::<Vec<_>>()
            .join(", ")
    )
}

fn opt(s: &Option<String>) -> String {
    s.as_deref().map(php_str).unwrap_or_else(|| "null".into())
}

fn collection(out: &mut String, c: &Collection) {
    let pk = c.primary_key().map(|p| p.name.as_str()).unwrap_or("id");
    let _ = writeln!(out, "        {} => [", php_str(&c.table));
    let _ = writeln!(out, "            'key' => {},", php_str(&c.key));
    let _ = writeln!(out, "            'label' => {},", php_str(&c.label));
    let _ = writeln!(out, "            'editor' => {},", c.editor);
    let _ = writeln!(out, "            'drafts' => {},", c.drafts);
    let _ = writeln!(out, "            'revisions' => {},", c.revisions);
    let _ = writeln!(out, "            'uid' => {},", c.uid);
    let _ = writeln!(
        out,
        "            'sync' => {},",
        c.sync.as_ref().map(php_json).unwrap_or_else(|| "null".into())
    );
    let _ = writeln!(out, "            'auth' => {},", c.auth);
    let _ = writeln!(out, "            'agents' => {},", c.agents);
    let _ = writeln!(out, "            'localized' => {},", c.localized);
    let _ = writeln!(out, "            'title' => {},", opt(&c.title));
    let _ = writeln!(out, "            'content' => {},", opt(&c.content));
    let _ = writeln!(out, "            'slug' => {},", opt(&c.slug));
    let _ = writeln!(out, "            'primaryKey' => {},", php_str(pk));
    let _ = writeln!(out, "            'columns' => [");
    for col in c.columns.values() {
        let _ = writeln!(
            out,
            "                {} => {},",
            php_str(&col.name),
            column(col, Ctx::Column, 4)
        );
    }
    let _ = writeln!(out, "            ],");
    let _ = writeln!(out, "            'relations' => [");
    for r in c.relations.values() {
        let kind = if r.kind == RelKind::One { "one" } else { "many" };
        let _ = writeln!(
            out,
            "                {} => ['kind' => '{kind}', 'collection' => {}, 'from' => {}, 'to' => {}, 'optional' => {}],",
            php_str(&r.name),
            php_str(&r.collection),
            php_str(&r.from),
            php_str(&r.to),
            r.optional
        );
    }
    let _ = writeln!(out, "            ],");
    let hooks: Vec<String> = c
        .hooks
        .iter()
        .map(|(k, f)| format!("{} => {}", php_str(k.name()), php_str(f)))
        .collect();
    let _ = writeln!(out, "            'hooks' => [{}],", hooks.join(", "));
    let fills: Vec<String> = c.before_create_fills.iter().map(|f| php_str(f)).collect();
    let _ = writeln!(out, "            'beforeCreateFills' => [{}],", fills.join(", "));
    let _ = writeln!(out, "            'access' => [");
    for (op, rule) in &c.access {
        let _ = writeln!(out, "                {} => {},", php_str(op), access(rule));
    }
    let _ = writeln!(out, "            ],");
    let _ = writeln!(
        out,
        "            'api' => ['list' => {}, 'get' => {}, 'create' => {}, 'update' => {}, 'delete' => {}],",
        c.api.list, c.api.get, c.api.create, c.api.update, c.api.delete
    );
    if c.editor {
        let t = &c.table;
        let _ = writeln!(
            out,
            "            'wp' => ['postType' => 'zero_{t}', 'groupKey' => 'group_zero_{t}', 'metaPrefix' => 'zero_', 'fieldKeyPrefix' => 'field_zero_{t}_'],"
        );
        let _ = writeln!(out, "            'table' => null,");
    } else {
        let _ = writeln!(out, "            'wp' => null,");
        if let Some(name) = &c.existing_table {
            let _ = writeln!(out, "            'existingTable' => {},", php_str(name));
        }
        let _ = writeln!(out, "            'table' => {},", php_str(&format!("zero_{}", c.table)));
    }
    let _ = writeln!(out, "        ],");
}

pub fn render(schema: &Schema) -> String {
    let mut out =
        String::from("<?php\n\n// Generated by `zero build`. Do not edit.\n\ndeclare(strict_types=1);\n\nreturn [\n");
    let _ = writeln!(out, "    'version' => 3,");
    let _ = writeln!(out, "    'runtime' => '{}',", crate::platform::SERIES);
    let _ = writeln!(out, "    'name' => {},", php_str(&schema.name));
    let _ = writeln!(out, "    'admin' => {},", schema.admin);
    out.push_str("    'site' => ['uidPrefix' => 'zr_'],\n");
    let _ = writeln!(
        out,
        "    'locales' => {},",
        schema.locales.as_ref().map(php_json).unwrap_or_else(|| "null".into())
    );
    let _ = writeln!(out, "    'media' => {},", php_json(&schema.media));
    if let Some(uploads) = &schema.uploads {
        let _ = writeln!(out, "    'uploads' => {},", php_json(uploads));
    }
    let auth_collection = schema.collections.values().find(|c| c.auth).map(|c| c.table.clone());
    let _ = writeln!(
        out,
        "    'auth' => ['collection' => {}, 'guests' => true, 'accessTtl' => 300, 'refreshTtl' => 2592000],",
        opt(&auth_collection)
    );
    out.push_str("    'files' => [\n");
    for (name, bucket) in &schema.files {
        let _ = writeln!(
            out,
            "        {} => ['accept' => [{}], 'maxBytes' => {}, 'access' => {}],",
            php_str(name),
            bucket.accept.iter().map(|s| php_str(s)).collect::<Vec<_>>().join(", "),
            bucket.max_bytes,
            access_map(&bucket.access)
        );
    }
    if !schema.routes.is_empty() {
        out.push_str("    ],\n    'routes' => [\n");
    }
    for (name, route) in &schema.routes {
        let _ = writeln!(
            out,
            "        {} => ['method' => {}, 'path' => {}, 'handler' => {}, 'access' => {}, 'signature' => {}],",
            php_str(name),
            php_str(&route.method),
            php_str(&route.path),
            php_str(&route.handler),
            access(&route.access),
            route.signature.as_ref().map(php_json).unwrap_or_else(|| "null".into())
        );
    }
    out.push_str("    ],\n    'jobs' => [\n");
    for (name, job) in &schema.jobs {
        let _ = writeln!(
            out,
            "        {} => ['input' => {}, 'handler' => {}, 'retries' => {}, 'agents' => {}],",
            php_str(name),
            fields_block(&job.input, 2),
            php_str(&job.handler),
            job.retries,
            job.agents
        );
    }
    let _ = writeln!(
        out,
        "    ],\n    'webhooks' => [{}],",
        schema.webhooks.iter().map(php_json).collect::<Vec<_>>().join(", ")
    );
    out.push_str("    'collections' => [\n");
    for c in schema.collections.values() {
        collection(&mut out, c);
    }
    out.push_str("    ],\n    'globals' => [\n");
    for g in schema.globals.values() {
        let n = &g.name;
        let _ = writeln!(out, "        {} => [", php_str(n));
        let _ = writeln!(out, "            'label' => {},", php_str(&g.label));
        let _ = writeln!(out, "            'agents' => {},", g.agents);
        let _ = writeln!(out, "            'fields' => {},", fields_block(&g.fields, 3));
        let rules: Vec<String> = g
            .access
            .iter()
            .map(|(op, r)| format!("{} => {}", php_str(op), access(r)))
            .collect();
        let _ = writeln!(out, "            'access' => [{}],", rules.join(", "));
        let _ = writeln!(
            out,
            "            'wp' => ['postId' => 'zero_global_{n}', 'menuSlug' => 'zero-global-{n}', 'groupKey' => 'group_zero_global_{n}', 'fieldKeyPrefix' => 'field_zero_global_{n}_', 'optionPrefix' => 'zero_global_{n}_'],"
        );
        let _ = writeln!(out, "        ],");
    }
    out.push_str("    ],\n    'actions' => [\n");
    for a in schema.actions.values() {
        let _ = writeln!(out, "        {} => [", php_str(&a.name));
        let _ = writeln!(out, "            'input' => {},", fields_block(&a.input, 3));
        let _ = writeln!(out, "            'handler' => {},", php_str(&a.handler));
        let _ = writeln!(out, "            'access' => {},", access(&a.access));
        let _ = writeln!(out, "            'agents' => {},", a.agents);
        let _ = writeln!(out, "            'idempotent' => {},", a.idempotent);
        let _ = writeln!(
            out,
            "            'signature' => {},",
            a.signature.as_ref().map(php_json).unwrap_or_else(|| "null".into())
        );
        let _ = writeln!(out, "        ],");
    }
    out.push_str("    ],\n];\n");
    out
}

/// Register a structural schema rather than importing authored exports. This also
/// breaks the config → db.query → config inference cycle for backend-only apps.
pub fn gen_ts(schema: &Schema) -> String {
    use std::fmt::Write as _;
    let mut out = String::from(
        "// Generated by `zero build`. Do not edit.\nimport type { Collection, ColumnBuilder, Relations, One, Many } from \"zero\";\n",
    );
    let has_config = !schema.globals.is_empty() || !schema.jobs.is_empty();
    if has_config {
        out.push_str("import type config from \"./zero.config\";\n");
    }
    out.push_str("type Entities = {\n");
    for c in schema.collections.values() {
        let storage = if c.auth {
            "userProjection"
        } else if c.editor {
            "collection"
        } else {
            "table"
        };
        let _ = writeln!(out, "  {}: Collection<{}, {{", php_str(&c.key), php_str(&c.table));
        for col in c.columns.values().filter(|col| !col.hidden) {
            let kind = if col.auto_increment { "id" } else { col.ty.name() };
            let translations = c.localized && col.name == "translations" && col.system;
            let value = if translations {
                format!(
                    "Partial<Record<GeneratedLocale, {}>>",
                    crate::client::registry_value(c.primary_key().expect("primary key"))
                )
            } else {
                crate::client::registry_value(col)
            };
            let _ = writeln!(
                out,
                "    {}: ColumnBuilder<{}, {}, {}, {}, {}, {}>;",
                php_str(&col.name),
                php_str(kind),
                value,
                col.authored_not_null(),
                col.authored_has_default(),
                col.localized,
                col.primary_key
            );
        }
        let _ = writeln!(out, "  }}, {}, {}>;", c.auth, php_str(storage));
    }
    out.push_str("};\ntype Graph = {\n");
    for c in schema.collections.values() {
        let _ = writeln!(out, "  {}: {{", php_str(&c.key));
        for r in c.relations.values() {
            let target = &schema.collections[&r.collection].key;
            let ty = match r.kind {
                RelKind::One => format!("One<{}, {}>", php_str(target), r.optional),
                RelKind::Many => format!("Many<{}>", php_str(target)),
            };
            let _ = writeln!(out, "    {}: {ty};", php_str(&r.name));
        }
        out.push_str("  };\n");
    }
    out.push_str("};\n");
    let locales = schema.locales.as_ref().map(|v| {
        v["all"]
            .as_array()
            .unwrap()
            .iter()
            .map(|l| l.to_string())
            .collect::<Vec<_>>()
            .join(" | ")
    });
    if let Some(locales) = &locales {
        let _ = writeln!(out, "type GeneratedLocale = {locales};");
    }
    out.push_str("declare module \"zero\" { interface Register { relations: Relations<Entities, Graph>");
    if has_config {
        out.push_str("; config: typeof config");
    }
    if locales.is_some() {
        out.push_str("; locales: GeneratedLocale");
    }
    out.push_str(" } }\n");
    out
}
