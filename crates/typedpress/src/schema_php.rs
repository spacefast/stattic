//! Emits `tp/app/schema.php` (version 5, phase-2 §3.1: the runtime's whole view
//! of the app, WordPress identifiers included) and `tp/_generated/db.d.ts`
//! (registers the schema with the `"first"` types).

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
    if c.commerce_meta {
        parts.push("'commerceMeta' => true".into());
    }
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
    if c.omit {
        parts.push("'omit' => true".into());
    }
    if c.required {
        parts.push("'required' => true".into());
    }
    if let Some(shape) = &c.shape {
        parts.push(format!("'shape' => {}", php_json(shape)));
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
        if let Some(join) = &r.join {
            rp.push(format!("'join' => {}", php_str(join)));
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
    if let Some(col) = &c.storage_name
        && col != &c.name
    {
        parts.push(format!("'column' => {}", php_str(col)));
    }
    if !c.aliases.is_empty() {
        parts.push(format!(
            "'aliases' => [{}]",
            c.aliases.iter().map(|a| php_str(a)).collect::<Vec<_>>().join(", ")
        ));
    }
    if let Some(on) = c.searchable
        && top
    {
        parts.push(format!("'searchable' => {on}"));
    }
    if c.derived {
        parts.push("'derived' => true".into());
    }
    if c.hidden {
        parts.push("'hidden' => true".into());
    }
    if c.read_only {
        parts.push("'readOnly' => true".into());
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

fn retention_php(r: &crate::schema::Retention) -> String {
    let deleted = r.deleted_seconds.map_or_else(|| "null".to_string(), |s| s.to_string());
    let revisions = match &r.revisions {
        None => "null".to_string(),
        Some(rr) => format!(
            "['keepLast' => {}, 'maxAgeSeconds' => {}]",
            rr.keep_last.map_or_else(|| "null".to_string(), |n| n.to_string()),
            rr.max_age_seconds.map_or_else(|| "null".to_string(), |s| s.to_string())
        ),
    };
    format!("['deletedSeconds' => {deleted}, 'revisions' => {revisions}]")
}

fn collection(out: &mut String, c: &Collection) {
    let pk = c.primary_key().map(|p| p.name.as_str()).unwrap_or("id");
    let _ = writeln!(out, "        {} => [", php_str(&c.table));
    let _ = writeln!(out, "            'key' => {},", php_str(&c.key));
    let _ = writeln!(out, "            'label' => {},", php_str(&c.label));
    let _ = writeln!(out, "            'editor' => {},", c.editor);
    let _ = writeln!(out, "            'drafts' => {},", c.drafts);
    let _ = writeln!(out, "            'revisions' => {},", c.revisions);
    if c.soft_delete {
        out.push_str("            'softDelete' => true,\n");
    }
    if let Some(r) = &c.retention {
        let _ = writeln!(out, "            'retention' => {},", retention_php(r));
    }
    if c.editor && !c.staged {
        // Staged versions are on for every collection; only the opt-out is written.
        let _ = writeln!(out, "            'staged' => false,");
    }
    let _ = writeln!(out, "            'comments' => {},", c.comments);
    if let Some(p) = &c.comment_policy {
        // spec §2.3, defaults filled; the comment enforcement reads it through Schema::commentPolicy().
        let _ = writeln!(
            out,
            "            'commentPolicy' => ['guests' => {}, 'moderation' => '{}', 'threaded' => {}, 'closeAfterDays' => {}, 'types' => [{}]],",
            p.guests,
            p.moderation,
            p.threaded,
            p.close_after_days.map_or_else(|| "null".to_string(), |d| d.to_string()),
            p.types.iter().map(|t| format!("'{t}'")).collect::<Vec<_>>().join(", ")
        );
    }
    let _ = writeln!(out, "            'uid' => {},", c.uid);
    let _ = writeln!(
        out,
        "            'sync' => {},",
        c.sync.as_ref().map(php_json).unwrap_or_else(|| "null".into())
    );
    let _ = writeln!(out, "            'auth' => {},", c.auth);
    let _ = writeln!(out, "            'agents' => {},", c.agents);
    let _ = writeln!(out, "            'localized' => {},", c.localized);
    if c.hierarchical {
        out.push_str("            'hierarchical' => true,\n");
    }
    if let Some(kind) = &c.commerce {
        let _ = writeln!(out, "            'commerce' => {},", php_str(kind));
    }
    if c.taxonomy.is_some() {
        out.push_str("            'taxonomy' => true,\n");
    }
    if let Some(ui) = &c.ui {
        let _ = writeln!(out, "            'ui' => {},", php_json(ui));
    }
    let _ = writeln!(out, "            'title' => {},", opt(&c.title));
    let _ = writeln!(out, "            'content' => {},", opt(&c.content));
    let _ = writeln!(out, "            'slug' => {},", opt(&c.slug));
    if c.excerpt.is_some() {
        let _ = writeln!(out, "            'excerpt' => {},", opt(&c.excerpt));
    }
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
        let through = r
            .through
            .as_ref()
            .map(|(table, from, to)| {
                format!(
                    ", 'through' => ['table' => {}, 'from' => {}, 'to' => {}]",
                    php_str(table),
                    php_str(from),
                    php_str(to)
                )
            })
            .unwrap_or_default();
        let _ = writeln!(
            out,
            "                {} => ['kind' => '{kind}', 'collection' => {}, 'from' => {}, 'to' => {}, 'optional' => {}{through}],",
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
    if let Some(url) = &c.url {
        let _ = writeln!(out, "            'url' => {},", php_str(url));
        // A kind at the site root leaves WordPress's and TypedPress's own first segments alone.
        if url == "/:path" {
            let _ = writeln!(
                out,
                "            'reserved' => [{}],",
                crate::kind_url::RESERVED_TP_SEGMENTS
                    .iter()
                    .map(|r| php_str(r))
                    .collect::<Vec<_>>()
                    .join(", ")
            );
        }
    }
    if let Some(kind) = &c.commerce {
        if let Some(tax) = &c.taxonomy {
            let taxonomy = if kind == "productCategory" {
                "product_cat"
            } else {
                "product_tag"
            };
            let on = tax.on.iter().map(|k| php_str(k)).collect::<Vec<_>>().join(", ");
            let _ = writeln!(
                out,
                "            'wp' => ['taxonomy' => '{taxonomy}', 'metaPrefix' => 'tp_', 'on' => [{on}]],\n            'table' => null,"
            );
        } else {
            let post_type = if kind == "product" { "product" } else { "shop_order" };
            let _ = writeln!(
                out,
                "            'wp' => ['postType' => '{post_type}', 'metaPrefix' => 'tp_'],\n            'table' => 'tp_{}',",
                c.table
            );
        }
    } else if let Some(tax) = &c.taxonomy {
        // A taxonomy's terms are core's (ruling 25): `wp_terms`, `wp_term_taxonomy`, term meta.
        let t = &c.table;
        let on: Vec<String> = tax.on.iter().map(|k| php_str(k)).collect();
        let _ = writeln!(
            out,
            "            'wp' => ['taxonomy' => 'tp_{t}', 'groupKey' => 'group_tp_{t}', 'metaPrefix' => 'tp_', 'fieldKeyPrefix' => 'field_tp_{t}_', 'on' => [{}]],",
            on.join(", ")
        );
        let _ = writeln!(out, "            'table' => null,");
    } else if c.editor {
        // WordPress's own posts keep core's `post` type; their field group is named for the built-in.
        let t = if c.native {
            "wp_posts".to_string()
        } else {
            c.table.clone()
        };
        let _ = writeln!(
            out,
            "            'wp' => ['postType' => {}, 'groupKey' => 'group_tp_{t}', 'metaPrefix' => 'tp_', 'fieldKeyPrefix' => 'field_tp_{t}_'],",
            php_str(&c.post_type())
        );
        if c.native {
            let _ = writeln!(out, "            'native' => true,");
        }
        let _ = writeln!(out, "            'table' => null,");
    } else {
        let _ = writeln!(out, "            'wp' => null,");
        if let Some(name) = &c.existing_table {
            let _ = writeln!(out, "            'existingTable' => {},", php_str(name));
        }
        let _ = writeln!(out, "            'table' => {},", php_str(&format!("tp_{}", c.table)));
    }
    let _ = writeln!(out, "        ],");
}

pub fn render(schema: &Schema) -> String {
    let mut out = String::from(
        "<?php\n\n// Generated by `typedpress build`. Do not edit.\n\ndeclare(strict_types=1);\n\nreturn [\n",
    );
    out.push_str("    'version' => 5,\n");
    let _ = writeln!(out, "    'runtime' => '{}',", crate::platform::SERIES);
    let _ = writeln!(out, "    'name' => {},", php_str(&schema.name));
    let _ = writeln!(out, "    'admin' => {},", schema.admin);
    // WordPress's and TypedPress's own first path segments: the `public/` folder at the site root
    // (TypedPress\Wp\PublicFiles) never answers for them, whatever it holds.
    let _ = writeln!(
        out,
        "    'reserved' => [{}],",
        crate::kind_url::RESERVED_TP_SEGMENTS
            .iter()
            .map(|r| php_str(r))
            .collect::<Vec<_>>()
            .join(", ")
    );
    // The complete allow-list (docs/sealed.md), the same one the build's manifests carry: the runtime
    // reads it here and decides nothing itself.
    if let Some(slugs) = crate::bundle::sealed_plugins(schema, crate::app_plugin::tp_plugins(schema)) {
        let slugs: Vec<String> = slugs.iter().map(|slug| php_str(slug)).collect();
        let _ = writeln!(out, "    'sealed' => ['plugins' => [{}]],", slugs.join(", "));
    }
    if schema.public_authors {
        out.push_str("    'publicAuthors' => true,\n");
    }
    // Written only when the config turns them off: absent is WordPress's own behaviour (spec §6).
    if !schema.xmlrpc {
        out.push_str("    'xmlrpc' => false,\n");
    }
    if !schema.pings {
        out.push_str("    'pings' => false,\n");
    }
    if let Some(r) = &schema.retention {
        let _ = writeln!(out, "    'retention' => {},", retention_php(r));
    }
    if !schema.migration_providers.is_empty() {
        out.push_str("    'migrationProviders' => [\n");
        for mp in &schema.migration_providers {
            let _ = writeln!(
                out,
                "        {} => ['id' => {}, 'cliOnly' => {}, 'requireArchive' => {}, 'allowRawSql' => {}],",
                php_str(&mp.id),
                php_str(&mp.id),
                mp.cli_only,
                mp.require_archive,
                mp.allow_raw_sql,
            );
        }
        out.push_str("    ],\n");
    }
    match &schema.frontend {
        None => {}
        Some(crate::kind_url::Frontend::Off) => out.push_str("    'frontend' => false,\n"),
        Some(crate::kind_url::Frontend::External(url)) => {
            let _ = writeln!(out, "    'frontend' => {},", php_str(url));
        }
    }
    if let Some(runtime) = &schema.runtime_schema {
        let _ = writeln!(
            out,
            "    'runtimeSchema' => ['version' => {}, 'hash' => {}],",
            runtime.version,
            php_str(&runtime.hash)
        );
    }
    out.push_str("    'site' => ['uidPrefix' => 'zr_'],\n");
    let _ = writeln!(
        out,
        "    'locales' => {},",
        schema.locales.as_ref().map(php_json).unwrap_or_else(|| "null".into())
    );
    if let Some(store) = &schema.store {
        let _ = writeln!(out, "    'store' => {},", php_json(store));
    }
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
    if !schema.components.is_empty() {
        out.push_str("    ],\n    'components' => [\n");
        for (name, function) in &schema.components {
            let _ = writeln!(out, "        {} => {},", php_str(name), php_str(function));
        }
    }
    if !schema.blocks.is_empty() {
        out.push_str(&crate::content_blocks::schema_php(&schema.blocks));
    }
    if !schema.islands.is_empty() {
        out.push_str("    ],\n    'islands' => [\n");
        for (namespace, island) in &schema.islands {
            let assets = if island.assets.is_empty() {
                String::new()
            } else {
                let entries: Vec<String> = island
                    .assets
                    .iter()
                    .map(|(name, asset)| match asset {
                        crate::schema::IslandAsset::Url(file) => {
                            format!("{} => {}", php_str(name), php_str(file))
                        }
                        crate::schema::IslandAsset::Meta {
                            file,
                            width,
                            height,
                            format: fmt,
                        } => format!(
                            "{} => ['file' => {}, 'width' => {width}, 'height' => {height}, 'format' => {}]",
                            php_str(name),
                            php_str(file),
                            php_str(fmt)
                        ),
                    })
                    .collect();
                format!(", 'assets' => [{}]", entries.join(", "))
            };
            let _ = writeln!(
                out,
                "        {} => ['file' => {}, 'actions' => [{}]{assets}{}],",
                php_str(namespace),
                php_str(&island.file),
                island.actions.iter().map(|a| php_str(a)).collect::<Vec<_>>().join(", "),
                if island.live { ", 'live' => true" } else { "" }
            );
        }
    }
    if !schema.assets_meta.is_empty() {
        out.push_str("    ],\n    'assetsMeta' => [\n");
        for (file, meta) in &schema.assets_meta {
            let _ = writeln!(
                out,
                "        {} => ['width' => {}, 'height' => {}, 'format' => {}],",
                php_str(file),
                meta.width,
                meta.height,
                php_str(&meta.format)
            );
        }
    }
    if let Some(app) = &schema.app {
        out.push_str("    ],\n    'app' => [\n");
        let opt = |v: &Option<String>| v.as_deref().map(php_str).unwrap_or_else(|| "null".into());
        let route = |r: &crate::schema::AppRoute| {
            let kind = match &r.kind {
                Some((kind, bound)) => format!(
                    "['name' => {}, 'params' => [{}]]",
                    php_str(kind),
                    bound
                        .iter()
                        .map(|(field, param)| format!("{} => {}", php_str(field), php_str(param)))
                        .collect::<Vec<_>>()
                        .join(", ")
                ),
                None => "null".into(),
            };
            // A preceding callback can redirect or stop the request before a role guard runs.
            // Infer sign-in only if the first guard that could refuse a guest requires a role.
            let files = r.layouts.iter().chain(r.page.iter());
            let mut guard_files = Vec::new();
            let mut requires_login = None;
            let mut template = "theme";
            for path in files {
                let Some(file) = app.files.get(path) else { continue };
                if let Some(guard) = &file.guard {
                    guard_files.push(php_str(path));
                    if requires_login.is_none() {
                        requires_login = match guard {
                            Access::Role(role) if role == "anyone" => None,
                            Access::Allow(true) => None,
                            Access::Role(_) => Some(true),
                            _ => Some(false),
                        };
                    }
                }
                if let Some(choice) = file.template.as_deref() {
                    template = choice;
                }
            }
            let navigation = format!(
                ", 'guards' => [{}], 'template' => {}{}",
                guard_files.join(", "),
                php_str(template),
                if requires_login == Some(true) {
                    ", 'loginRequired' => true"
                } else {
                    ""
                }
            );
            let preloads = if r.preloads.is_empty() {
                String::new()
            } else {
                let items: Vec<String> = r
                    .preloads
                    .iter()
                    .map(|p| format!("['file' => {}, 'type' => {}]", php_str(&p.file), php_str(p.mime)))
                    .collect();
                format!(", 'preloads' => [{}]", items.join(", "))
            };
            let plan = r.plan.as_ref().map_or_else(String::new, |plan| {
                format!(", 'plan' => {}", php_json(&serde_json::json!(plan)))
            });
            format!(
                "['id' => {}, 'pattern' => {}, 'page' => {}, 'handler' => {}, 'layouts' => [{}], 'error' => {}, 'styles' => [{}]{preloads}, 'kind' => {kind}{navigation}{plan}{}]",
                php_str(&r.id),
                php_str(&r.pattern),
                opt(&r.page),
                opt(&r.handler),
                r.layouts.iter().map(|l| php_str(l)).collect::<Vec<_>>().join(", "),
                opt(&r.error),
                r.styles.iter().map(|l| php_str(l)).collect::<Vec<_>>().join(", "),
                if r.prerender { ", 'prerender' => true" } else { "" }
            )
        };
        if schema.output == crate::schema::Output::Static {
            out.push_str("        'output' => 'static',\n");
        }
        let _ = writeln!(
            out,
            "        'hasPrebuilt' => {},",
            app.routes.iter().any(|r| r.prerender)
        );
        out.push_str("        'routes' => [\n");
        for r in &app.routes {
            let _ = writeln!(out, "            {},", route(r));
        }
        out.push_str("        ],\n");
        let _ = writeln!(
            out,
            "        'notFound' => {},",
            app.not_found.as_ref().map(route).unwrap_or_else(|| "null".into())
        );
        // App routes have unique literal paths with precedence over parameter matches.
        let mut literal = Vec::new();
        let mut dynamic = Vec::new();
        for (position, route) in app.routes.iter().enumerate() {
            if route.pattern.split('/').any(|segment| segment.starts_with([':', '*'])) {
                dynamic.push(position.to_string());
            } else {
                literal.push(format!("{} => {position}", php_str(&route.pattern)));
            }
        }
        let _ = writeln!(
            out,
            "        'routeIndex' => ['literal' => [{}], 'dynamic' => [{}]],",
            literal.join(", "),
            dynamic.join(", ")
        );
        let mut purge_routes = std::collections::BTreeMap::<String, Vec<&str>>::new();
        let mut option_names = std::collections::BTreeSet::new();
        for route in &app.routes {
            let Some(plan) = &route.plan else { continue };
            if matches!(plan.class, crate::schema::CacheClass::RequestPrivate) {
                continue;
            }
            for dep in &plan.dependencies {
                if let crate::schema::RouteDependency::Option { name } = dep {
                    option_names.insert(name);
                }
                if route.pattern.split('/').all(|part| !part.starts_with([':', '*']))
                    && let Some(key) = dep.purge_key()
                {
                    let routes = purge_routes.entry(key).or_default();
                    if !routes.contains(&route.pattern.as_str()) {
                        routes.push(&route.pattern);
                    }
                }
            }
        }
        if !purge_routes.is_empty() {
            let _ = writeln!(
                out,
                "        'purgeRoutes' => {},",
                php_json(&serde_json::json!(purge_routes))
            );
        }
        if !option_names.is_empty() {
            let _ = writeln!(
                out,
                "        'purgeOptions' => {},",
                php_json(&serde_json::json!(option_names))
            );
        }
        if let Some(login) = &app.login {
            let _ = writeln!(out, "        'login' => {},", route(login));
        }
        let _ = writeln!(
            out,
            "        'reserved' => [{}],",
            crate::app::RESERVED_TP
                .iter()
                .map(|r| php_str(r))
                .collect::<Vec<_>>()
                .join(", ")
        );
        if !app.content.is_empty() {
            out.push_str(&crate::content_page::schema_php(app));
        }
        out.push_str("        'files' => [\n");
        for (path, f) in &app.files {
            let methods = f
                .methods
                .iter()
                .map(|(m, h)| format!("{} => {}", php_str(m), php_str(h)))
                .collect::<Vec<_>>()
                .join(", ");
            let _ = writeln!(
                out,
                "            {} => ['component' => {}, 'metadata' => {}, 'guard' => {}{}, 'signature' => {}, 'search' => {}, 'methods' => [{methods}]{}],",
                php_str(path),
                opt(&f.component),
                f.metadata.as_ref().map(php_json).unwrap_or_else(|| "null".into()),
                f.guard.as_ref().map(access).unwrap_or_else(|| "null".into()),
                f.handler_guard
                    .as_ref()
                    .map(|rule| format!(", 'handlerGuard' => {}", access(rule)))
                    .unwrap_or_default(),
                f.signature.as_ref().map(php_json).unwrap_or_else(|| "null".into()),
                f.search
                    .as_ref()
                    .map(|s| fields_block(&s.fields, 3))
                    .unwrap_or_else(|| "null".into()),
                f.static_params
                    .as_ref()
                    .map(|n| format!(", 'staticParams' => {}", php_str(n)))
                    .unwrap_or_default()
                    + &f.template
                        .as_ref()
                        .map(|t| format!(", 'template' => {}", php_str(t)))
                        .unwrap_or_default(),
            );
        }
        out.push_str("        ],\n");
    }
    // The fields each WordPress class view exposes (hook arguments, function results): the
    // catalog's, so the runtime and the types agree. Only apps that touch WordPress carry them.
    if schema.wp_views {
        out.push_str("    ],\n    'wpViews' => [\n");
        for class in ["WP_Post", "WP_User"] {
            let fields = crate::wordpress::class_fields(class);
            let _ = writeln!(
                out,
                "        {} => [{}],",
                php_str(class),
                fields.iter().map(|f| php_str(f)).collect::<Vec<_>>().join(", ")
            );
        }
    }
    if !schema.wp_hooks.is_empty() {
        out.push_str("    ],\n    'wpHooks' => [\n");
        for h in &schema.wp_hooks {
            let _ = writeln!(
                out,
                "        ['hook' => {}, 'filter' => {}, 'priority' => {}, 'args' => {}, 'handler' => {}],",
                php_str(&h.hook),
                h.filter,
                h.priority,
                h.args,
                php_str(&h.handler)
            );
        }
    }
    out.push_str("    ],\n    'jobs' => [\n");
    for (name, job) in &schema.jobs {
        let schedule = match &job.schedule {
            None => String::new(),
            Some(crate::schema::Schedule::Every(seconds)) => format!(", 'schedule' => ['every' => {seconds}]"),
            Some(crate::schema::Schedule::Cron(expr)) => format!(", 'schedule' => ['cron' => {}]", php_str(expr)),
        };
        let _ = writeln!(
            out,
            "        {} => ['input' => {}, 'handler' => {}, 'retries' => {}, 'agents' => {}{schedule}],",
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
    for c in schema.collections.values().filter(|c| c.taxonomy.is_none()) {
        collection(&mut out, c);
    }
    // Taxonomies (docs/decisions/2026-11-11-authoring-surface.md, ruling 25): the same kind shape,
    // read and written by the term store; the runtime finds them by name like any kind.
    if schema.collections.values().any(|c| c.taxonomy.is_some()) {
        out.push_str("    ],\n    'taxonomies' => [\n");
        for c in schema.collections.values().filter(|c| c.taxonomy.is_some()) {
            collection(&mut out, c);
        }
    }
    out.push_str("    ],\n    'globals' => [\n");
    for g in schema.globals.values() {
        let n = &g.name;
        let _ = writeln!(out, "        {} => [", php_str(n));
        let _ = writeln!(out, "            'label' => {},", php_str(&g.label));
        if let Some(ui) = &g.ui {
            let _ = writeln!(out, "            'ui' => {},", php_json(ui));
        }
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
            "            'wp' => ['postId' => 'tp_global_{n}', 'menuSlug' => 'typedpress-global-{n}', 'groupKey' => 'group_tp_global_{n}', 'fieldKeyPrefix' => 'field_tp_global_{n}_', 'optionPrefix' => 'tp_global_{n}_'],"
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
        let _ = writeln!(out, "            'readonly' => {},", a.readonly);
        let _ = writeln!(out, "            'destructive' => {},", a.destructive);
        if a.personal {
            out.push_str("            'personal' => true,\n");
        }
        let _ = writeln!(
            out,
            "            'output' => {},",
            a.output.as_ref().map(php_json).unwrap_or_else(|| "null".into())
        );
        let _ = writeln!(
            out,
            "            'signature' => {},",
            a.signature.as_ref().map(php_json).unwrap_or_else(|| "null".into())
        );
        if let Some(r) = &a.rate_limit {
            let _ = writeln!(
                out,
                "            'rateLimit' => ['count' => {}, 'window' => {}],",
                r.count, r.window
            );
        }
        let _ = writeln!(out, "        ],");
    }
    out.push_str("    ],\n");
    // `adminScreen()` declarations, as tp_admin_register_screen() takes them (TypedPress\Wp\Plugin
    // registers them on `tp_admin_screens` when TypedPress Admin is active).
    if !schema.screens.is_empty() {
        out.push_str("    'screens' => [\n");
        for (id, screen) in &schema.screens {
            let _ = writeln!(out, "        {} => {},", php_str(id), php_json(screen));
        }
        out.push_str("    ],\n");
    }
    if !schema.widgets.is_empty() {
        out.push_str("    'widgets' => [\n");
        for (id, widget) in &schema.widgets {
            let _ = writeln!(out, "        {} => {},", php_str(id), php_json(widget));
        }
        out.push_str("    ],\n");
    }
    if !schema.dashboards.is_empty() {
        out.push_str("    'dashboards' => [\n");
        for (id, dashboard) in &schema.dashboards {
            let _ = writeln!(out, "        {} => {},", php_str(id), php_json(dashboard));
        }
        out.push_str("    ],\n");
    }
    out.push_str("];\n");
    out
}

/// Register a structural schema rather than importing authored exports. This also
/// breaks the config → db.query → config inference cycle for backend-only apps.
pub fn gen_ts(schema: &Schema) -> String {
    use std::fmt::Write as _;
    let mut out = String::from(
        "// Generated by `typedpress build`. Do not edit.\nimport type { Collection, ColumnBuilder, Relations, One, Many, Global, Job, Id } from \"typedpress\";\n",
    );
    // One field as its builder type: kind, value, required, defaulted, translated, key.
    let builder = |col: &Column, value: String| {
        let kind = if col.auto_increment {
            "id"
        } else {
            col.ty.builder_name()
        };
        format!(
            "ColumnBuilder<{}, {}, {}, {}, {}, {}>",
            php_str(kind),
            value,
            col.authored_not_null(),
            col.authored_has_default() || col.read_only,
            col.localized,
            col.primary_key
        )
    };
    out.push_str("type Entities = {\n");
    for c in schema.collections.values() {
        let storage = if c.auth {
            "userProjection"
        } else if c.taxonomy.is_some() {
            "taxonomy"
        } else if let Some(kind) = &c.commerce {
            kind.as_str()
        } else if c.editor {
            "collection"
        } else {
            "table"
        };
        let _ = writeln!(out, "  {}: Collection<{}, {{", php_str(&c.key), php_str(&c.table));
        for col in c.columns.values().filter(|col| !col.hidden) {
            let translations = c.localized && col.name == "translations" && col.system;
            let value = if translations {
                format!(
                    "Partial<Record<GeneratedLocale, {}>>",
                    crate::client::registry_value(c.primary_key().expect("primary key"))
                )
            } else if col.primary_key && col.ty == crate::schema::ColType::Integer {
                format!("Id<{}>", php_str(&c.key))
            } else if let Some(r) = &col.references
                && let Some(target) = schema.collections.get(&r.collection)
                && col.ty == crate::schema::ColType::Integer
            {
                format!("Id<{}>", php_str(&target.key))
            } else if let Some(r) = &col.references
                && let Some(target) = schema.collections.get(&r.collection)
                && col.ty == crate::schema::ColType::RefList
            {
                format!("Id<{}>[]", php_str(&target.key))
            } else {
                crate::client::registry_value(col)
            };
            let _ = writeln!(out, "    {}: {};", php_str(&col.name), builder(col, value));
        }
        let _ = writeln!(out, "  }}, {}, {}>;", c.auth, php_str(storage));
    }
    out.push_str("};\n");
    // Globals register by export like kinds (docs/spec/spec.md §1.1), so they are typed the same
    // way: structurally, with no import of the sources that export them. A job is typed by its own
    // export (`notifyLowStock.enqueue(...)`).
    let has_globals = !schema.globals.is_empty();
    if has_globals {
        out.push_str("type Globals = {\n");
        for (name, g) in &schema.globals {
            let _ = writeln!(out, "  {}: Global<{}, {{", php_str(name), php_str(name));
            for col in g.fields.values().filter(|col| !col.hidden) {
                let value = crate::client::registry_value(col);
                let _ = writeln!(out, "    {}: {};", php_str(&col.name), builder(col, value));
            }
            out.push_str("  }>;\n");
        }
        out.push_str("};\n");
    }
    out.push_str("type Graph = {\n");
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
    out.push_str("declare module \"typedpress\" { interface Register { relations: Relations<Entities, Graph>");
    if has_globals {
        out.push_str("; globals: Globals");
    }
    if locales.is_some() {
        out.push_str("; locales: GeneratedLocale");
    }
    out.push_str(" } }\n");
    out
}
