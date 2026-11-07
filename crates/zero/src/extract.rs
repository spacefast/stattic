//! Reads the schema out of the evaluated config value, enforces every config
//! rule that doesn't need the PHP compiler (§1 of the design), and lists the
//! functions (hooks, actions, access rules, validators, `$default`/`$onUpdate`
//! callbacks) the compiler must emit.

use crate::diag::{Diag, Result, Span, suggest};
use crate::eval::{Evaluator, kind_phrase, to_json};
use crate::ir::{ExprKind, FnBody, Pat, Stmt};
use crate::schema::*;
use crate::value::*;
use indexmap::IndexMap;
use serde_json::Value as Json;
use std::rc::Rc;

/// Where a compiled function's PHP name goes once it's known.
#[derive(Debug, Clone)]
pub enum Slot {
    Hook { table: String, kind: HookKind },
    Default { at: ColPath },
    OnUpdate { at: ColPath },
    Validate { at: ColPath },
    ActionHandler { action: String },
    Access { owner: Owner, op: String },
    FieldAccess { at: ColPath, op: String },
    JobHandler { job: String },
}

#[derive(Debug, Clone)]
pub struct Root {
    pub closure: Rc<Closure>,
    pub slot: Slot,
    /// Suggested PHP function name.
    pub name: String,
}

pub struct Extracted {
    pub schema: Schema,
    pub roots: Vec<Root>,
    /// Schema-key → collection value, for resolving captured collections.
    pub collections: Vec<Rc<CollectionVal>>,
    /// Non-fatal advice printed by `zero build` (§3.5.1 postmeta hint).
    pub hints: Vec<String>,
    pub plugins: Option<crate::wp_plugins::Config>,
}

const CONFIG_KEYS: &[&str] = &[
    "name",
    "collections",
    "tables",
    "users",
    "relations",
    "globals",
    "hooks",
    "actions",
    "jobs",
    "webhooks",
    "files",
    "media",
    "uploads",
    "locales",
    "plugins",
    "admin",
    "services",
];
const COLLECTION_OPTIONS: &[&str] = &[
    "storageName",
    "existingTable",
    "label",
    "drafts",
    "revisions",
    "title",
    "content",
    "access",
    "api",
    "sync",
    "uid",
    "agents",
];
/// Collection names that would shadow a built-in route (§4.8).
const RESERVED_COLLECTIONS: &[&str] = &[
    "media", "files", "globals", "actions", "me", "_schema", "auth", "services",
];
/// WordPress limits post types to 20 characters; Zero prefixes `zero_`.
const MAX_POST_TYPE_TABLE: usize = 15;
/// MySQL utf8mb4 index prefix limit.
const MAX_UNIQUE_TEXT: f64 = 191.0;
const MAX_NESTING: usize = 3;

/// Where a column is declared; decides which builders and modifiers it may use.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Place {
    /// A collection column.
    Top,
    /// Inside group/repeater/blocks; depth counts the top column as 1.
    Sub(usize),
    Global,
    Input,
}

impl Place {
    fn depth(self) -> usize {
        match self {
            Place::Sub(d) => d,
            _ => 1,
        }
    }

    fn where_(self) -> &'static str {
        match self {
            Place::Top => "on a collection column",
            Place::Sub(_) => "inside a group, repeater, or blocks field",
            Place::Global => "on a global field",
            Place::Input => "on an action input",
        }
    }
}

pub fn extract(config: &Value, ev: &mut Evaluator<'_>, config_span: Span, default_name: &str) -> Result<Extracted> {
    let Value::Object(cfg) = config else {
        return Err(Diag::new(
            config_span,
            format!(
                "the default export must be defineConfig({{ ... }}), not {}",
                config.describe()
            ),
        ));
    };
    let cfg_map = cfg.borrow().clone();
    let span_of =
        |ev: &Evaluator<'_>, obj: &Value, key: &str, fallback: Span| ev.key_span(obj, key).unwrap_or(fallback);
    for key in cfg_map.keys() {
        if !CONFIG_KEYS.contains(&key.as_str()) {
            let mut d = Diag::new(
                span_of(ev, config, key, config_span),
                format!("defineConfig() has no option `{key}`"),
            );
            if let Some(s) = suggest(key, CONFIG_KEYS.iter().copied()) {
                d = d.help(format!("did you mean `{s}`?"));
            }
            return Err(d);
        }
    }

    let mut out = Extracted {
        schema: Schema::default(),
        roots: Vec::new(),
        collections: Vec::new(),
        hints: Vec::new(),
        plugins: None,
    };

    out.schema.name = match cfg_map.get("name") {
        None | Some(Value::Undef) => default_name.to_string(),
        Some(Value::Str(s)) if is_slug(s) => s.to_string(),
        Some(other) => {
            return Err(Diag::new(
                span_of(ev, config, "name", config_span),
                format!(
                    "`name` must be a lowercase slug like \"blog\", not {}",
                    other.describe()
                ),
            )
            .help("it becomes the WordPress plugin slug `zero-<name>`"));
        }
    };
    out.schema.admin = bool_option(&cfg_map, "admin", false, span_of(ev, config, "admin", config_span))?;
    phase2_config(&cfg_map, ev, config_span, &mut out)?;
    if let Some((_, span)) = ev.duplicate_key(config, Some("plugins")) {
        return Err(Diag::new(span, "duplicate plugins declaration"));
    }
    if let Some(value) = cfg_map.get("plugins") {
        let span = span_of(ev, config, "plugins", config_span);
        plugin_declarations(value, ev, span, &mut std::collections::HashSet::new())?;
        let json =
            to_json(value).ok_or_else(|| Diag::new(span, "plugins must contain declarative plugin data only"))?;
        let plugins = crate::wp_plugins::parse(json).map_err(|e| Diag::new(span, e.to_string()))?;
        crate::wp_plugins::resolve(&plugins, &crate::platform::plugins(out.schema.locales.is_some()))
            .map_err(|e| Diag::new(span, format!("plugins: {e}")))?;
        out.plugins = Some(plugins);
    }

    for registry in ["collections", "tables", "users"] {
        if let Some((_, span)) = ev.duplicate_key(config, Some(registry)) {
            return Err(Diag::new(span, format!("duplicate `{registry}` registry")));
        }
    }

    // Bind every identity before evaluating columns or reference closures. The shared schema
    // remains the runtime/migration model, so relations do not depend on storage kind.
    for kind in [StorageKind::Collection, StorageKind::Table, StorageKind::UserProjection] {
        let registry = kind.registry();
        let Some(registry_val) = cfg_map.get(registry) else {
            continue;
        };
        let Value::Object(entries) = registry_val else {
            return Err(Diag::new(
                span_of(ev, config, registry, config_span),
                format!("`{registry}` must be an object of {}() values", kind.builder()),
            ));
        };
        if let Some((key, span)) = ev.duplicate_key(registry_val, None) {
            return Err(Diag::new(span, format!("duplicate registration `{registry}.{key}`")));
        }
        for (key, v) in entries.borrow().iter() {
            let span = span_of(ev, registry_val, key, config_span);
            let Value::Collection(c) = v else {
                return Err(Diag::new(
                    span,
                    format!("`{registry}.{key}` must be {}(), not {}", kind.builder(), v.describe()),
                ));
            };
            if c.kind != kind {
                return Err(Diag::new(
                    span,
                    format!(
                        "{}() belongs in `{}`, not `{registry}`",
                        c.kind.builder(),
                        c.kind.registry()
                    ),
                ));
            }
            if !valid_ident(key) {
                return Err(Diag::new(span, format!("`{key}` isn't a valid registry key"))
                    .help("use letters, digits, and underscores, starting with a letter"));
            }
            let canonical = physical_name(key);
            if RESERVED_COLLECTIONS.contains(&canonical.as_str()) {
                return Err(Diag::new(
                    c.span,
                    format!("\"{canonical}\" is reserved: Zero serves a built-in route with that name"),
                ));
            }
            let table = match c.options.as_ref().and_then(|options| {
                let Value::Object(options) = options else {
                    unreachable!()
                };
                options.borrow().get("storageName").cloned()
            }) {
                None | Some(Value::Undef) => canonical,
                Some(Value::Str(name)) if valid_ident(&name) => name.to_string(),
                Some(_) => {
                    return Err(
                        Diag::new(span, "`storageName` must be an identifier starting with a letter")
                            .help("use the existing physical name without the WordPress or zero_ prefix"),
                    );
                }
            };
            if RESERVED_COLLECTIONS
                .iter()
                .any(|reserved| reserved.eq_ignore_ascii_case(&table))
            {
                return Err(Diag::new(
                    c.span,
                    format!("\"{table}\" is reserved: Zero serves a built-in route with that name"),
                ));
            }
            if let Some(other) = out.schema.collections.get(&table) {
                return Err(Diag::new(
                    span,
                    format!("`{key}` and `{}` share the physical name `{table}`", other.key),
                ));
            }
            if out.schema.collections.values().any(|other| other.key == *key) {
                return Err(Diag::new(span, format!("duplicate logical registration `{key}`")));
            }
            if c.table.set(table.clone()).is_err() {
                return Err(Diag::new(span, "a definition can only be registered once"));
            }
            out.collections.push(c.clone());
            out.schema.collections.insert(
                c.table().to_string(),
                Collection {
                    key: key.clone(),
                    table: c.table().to_string(),
                    existing_table: None,
                    label: title_case(c.table()),
                    editor: true,
                    drafts: true,
                    revisions: true,
                    title: None,
                    content: None,
                    slug: None,
                    columns: IndexMap::new(),
                    relations: IndexMap::new(),
                    hooks: IndexMap::new(),
                    before_create_fills: Vec::new(),
                    access: IndexMap::new(),
                    uid: false,
                    sync: None,
                    auth: false,
                    agents: true,
                    localized: false,
                    api: Api {
                        list: true,
                        get: true,
                        create: true,
                        update: true,
                        delete: true,
                    },
                },
            );
        }
    }
    for c in out.collections.clone() {
        let owner = Owner::Collection(c.table().to_string());
        let mut columns = IndexMap::new();
        for (name, b) in &c.columns {
            let col = column(name, b, &owner, &[], Place::Top, ev, &mut out)?;
            columns.insert(name.clone(), col);
        }
        let pks: Vec<&Column> = columns.values().filter(|c| c.primary_key).collect();
        if pks.is_empty() {
            return Err(
                Diag::new(c.span, format!("collection \"{}\" has no primary key", c.table())).help("add `id: id()`"),
            );
        }
        if pks.len() > 1 {
            return Err(Diag::new(
                c.span,
                format!("collection \"{}\" has more than one primary key", c.table()),
            ));
        }
        let opts = collection_options(&c, &columns, ev, &mut out)?;
        let coll = out.schema.collections.get_mut(c.table()).expect("collection");
        coll.columns = columns;
        coll.existing_table = opts.existing_table;
        coll.label = opts.label;
        coll.editor = opts.editor;
        coll.drafts = opts.drafts;
        coll.revisions = opts.revisions;
        coll.title = opts.title;
        coll.content = opts.content;
        coll.slug = opts.slug;
        coll.access = opts.access;
        coll.api = opts.api;
        coll.uid = opts.uid;
        coll.sync = opts.sync;
        coll.auth = opts.auth;
        coll.agents = opts.agents;
        coll.localized = coll.columns.values().any(has_localized);
        if coll.drafts {
            let mut status = Column::new("status", ColType::Text);
            status.not_null = true;
            status.enum_values = Some(vec!["draft".into(), "published".into(), "scheduled".into()]);
            status.default = Some(DefaultKind::Value(Json::String("published".into())));
            status.system = true;
            let mut published_at = Column::new("publishedAt", ColType::Timestamp);
            published_at.system = true;
            coll.columns.insert("status".into(), status);
            coll.columns.insert("publishedAt".into(), published_at);
        }
    }
    phase2_collections(ev, &mut out)?;
    postmeta_hints(&mut out);

    // Relations
    if let Some(rel) = cfg_map.get("relations") {
        relations(rel, ev, &mut out, span_of(ev, config, "relations", config_span))?;
    }

    // Globals
    if let Some(globals_val) = cfg_map.get("globals") {
        let gspan = span_of(ev, config, "globals", config_span);
        let Value::Object(globals) = globals_val else {
            return Err(Diag::new(gspan, "`globals` must be an object of global(...) values"));
        };
        for (key, g) in globals.borrow().iter() {
            let kspan = span_of(ev, globals_val, key, gspan);
            let Value::Global(g) = g else {
                return Err(Diag::new(
                    kspan,
                    format!("`globals.{key}` is {}, not global(...)", g.describe()),
                ));
            };
            global(key, g, kspan, ev, &mut out)?;
        }
    }

    // Hooks
    if let Some(hooks_val) = cfg_map.get("hooks") {
        let hspan = span_of(ev, config, "hooks", config_span);
        let Value::Object(hooks) = hooks_val else {
            return Err(Diag::new(hspan, "`hooks` must be an object keyed by collection"));
        };
        for (key, per) in hooks.borrow().iter() {
            let kspan = span_of(ev, hooks_val, key, hspan);
            let Some(coll) = out.schema.by_key(key) else {
                let mut d = Diag::new(
                    kspan,
                    format!("`hooks.{key}` doesn't match a registered collection, table, or user projection"),
                );
                let keys: Vec<&str> = out.schema.collections.values().map(|c| c.key.as_str()).collect();
                if let Some(s) = suggest(key, keys) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                return Err(d);
            };
            let table = coll.table.clone();
            let Value::Object(per_map) = per else {
                return Err(Diag::new(
                    kspan,
                    format!("`hooks.{key}` must be an object of hook functions"),
                ));
            };
            for (hname, f) in per_map.borrow().iter() {
                let fspan = span_of(ev, per, hname, kspan);
                let Some(kind) = HookKind::parse(hname) else {
                    let mut d = Diag::new(fspan, format!("there's no hook called `{hname}`"));
                    if let Some(s) = suggest(hname, HookKind::ALL.iter().map(|h| h.name())) {
                        d = d.help(format!("did you mean `{s}`?"));
                    }
                    return Err(d);
                };
                let Value::Closure(f) = f else {
                    return Err(Diag::new(
                        fspan,
                        format!("`{hname}` must be a function, not {}", f.describe()),
                    ));
                };
                if kind == HookKind::BeforeCreate {
                    let coll = out.schema.collections.get_mut(&table).expect("collection");
                    coll.before_create_fills = assigned_keys(f)
                        .into_iter()
                        .filter(|k| {
                            coll.columns
                                .get(k)
                                .is_some_and(|c| c.not_null && c.default.is_none() && !c.auto_increment)
                        })
                        .collect();
                }
                out.roots.push(Root {
                    closure: f.clone(),
                    slot: Slot::Hook {
                        table: table.clone(),
                        kind,
                    },
                    name: format!("{table}__{hname}"),
                });
            }
        }
    }

    // Actions
    if let Some(actions_val) = cfg_map.get("actions") {
        let aspan = span_of(ev, config, "actions", config_span);
        let Value::Object(actions) = actions_val else {
            return Err(Diag::new(aspan, "`actions` must be an object of action(...) values"));
        };
        for (name, a) in actions.borrow().iter() {
            let nspan = span_of(ev, actions_val, name, aspan);
            if !valid_ident(name) {
                return Err(Diag::new(nspan, format!("\"{name}\" isn't a valid action name")));
            }
            let Value::Action(a) = a else {
                return Err(Diag::new(
                    nspan,
                    format!("action `{name}` must be wrapped in action({{ input, handler }})"),
                ));
            };
            let Value::Object(cfg) = &a.config else { unreachable!() };
            let cfg = cfg.borrow().clone();
            for k in cfg.keys() {
                if !matches!(
                    k.as_str(),
                    "input" | "handler" | "access" | "agents" | "idempotent" | "signature"
                ) {
                    return Err(Diag::new(
                        ev.key_span(&a.config, k).unwrap_or(a.span),
                        format!("action() has no option `{k}`"),
                    )
                    .help("use input, handler, access"));
                }
            }
            let owner = Owner::Action(name.clone());
            let mut input = IndexMap::new();
            if let Some(iv) = cfg.get("input") {
                let Value::Object(fields) = iv else {
                    return Err(Diag::new(a.span, "`input` must be an object of columns"));
                };
                for (field, fv) in fields.borrow().iter() {
                    let fspan = ev.key_span(iv, field).unwrap_or(a.span);
                    let Value::Column(b) = fv else {
                        return Err(
                            Diag::new(fspan, format!("input `{field}` is {}, not a column", fv.describe()))
                                .help("describe inputs with integer(), text(), ..."),
                        );
                    };
                    let col = column(field, b, &owner, &[], Place::Input, ev, &mut out)?;
                    input.insert(field.clone(), col);
                }
            }
            let Some(Value::Closure(handler)) = cfg.get("handler") else {
                return Err(Diag::new(a.span, format!("action `{name}` needs a `handler` function")));
            };
            let agents = bool_option(&cfg, "agents", true, a.span)?;
            let idempotent = bool_option(&cfg, "idempotent", false, a.span)?;
            let signature = match cfg.get("signature") {
                None | Some(Value::Undef) => None,
                Some(v) => {
                    if cfg.contains_key("access") {
                        return Err(Diag::new(a.span, "signature cannot be combined with access")
                            .help("remove access; signature authenticates the caller"));
                    }
                    Some(signature_config(v, a.span)?)
                }
            };
            let access = match cfg.get("access") {
                None | Some(Value::Undef) => Access::editor(),
                Some(v) => {
                    let sp = ev.key_span(&a.config, "access").unwrap_or(a.span);
                    access_rule(v, sp, &owner, "access", format!("action__{name}__access"), &mut out)?
                }
            };
            out.roots.push(Root {
                closure: handler.clone(),
                slot: Slot::ActionHandler { action: name.clone() },
                name: format!("action__{name}"),
            });
            out.schema.actions.insert(
                name.clone(),
                Action {
                    name: name.clone(),
                    input,
                    handler: String::new(),
                    access,
                    agents,
                    idempotent,
                    signature,
                },
            );
        }
    }
    phase2_jobs(&cfg_map, ev, config_span, &mut out)?;
    validate_phase2(&cfg_map, ev, config_span, &mut out)?;
    Ok(out)
}

/// Package pins cannot inherit JavaScript's last-property-wins behavior.
fn plugin_declarations(
    value: &Value,
    ev: &Evaluator<'_>,
    span: Span,
    active: &mut std::collections::HashSet<usize>,
) -> Result<()> {
    let Some(identity) = crate::eval::ptr_of(value) else {
        return Ok(());
    };
    if !active.insert(identity) {
        return Err(Diag::new(span, "plugins must be acyclic declarative data"));
    }
    if let Some((key, span)) = ev.duplicate_key(value, None) {
        return Err(Diag::new(
            span,
            format!("duplicate plugin declaration/property `{key}`"),
        ));
    }
    match value {
        Value::Object(map) => {
            for value in map.borrow().values() {
                plugin_declarations(value, ev, span, active)?;
            }
        }
        Value::Array(items) => {
            for value in items.borrow().iter() {
                plugin_declarations(value, ev, span, active)?;
            }
        }
        _ => {}
    }
    active.remove(&identity);
    Ok(())
}

fn valid_ident(s: &str) -> bool {
    let mut chars = s.chars();
    chars.next().is_some_and(|c| c.is_ascii_alphabetic()) && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
}

fn is_slug(s: &str) -> bool {
    !s.is_empty()
        && s.split('-')
            .all(|p| !p.is_empty() && p.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit()))
}

/// A project directory name as a plugin slug: "My Blog_2" → "my-blog-2".
pub fn slugify_name(s: &str) -> String {
    let mut out = String::new();
    for c in s.chars() {
        if c.is_ascii_alphanumeric() {
            out.push(c.to_ascii_lowercase());
        } else if !out.ends_with('-') && !out.is_empty() {
            out.push('-');
        }
    }
    let out = out.trim_end_matches('-').to_string();
    if out.is_empty() { "app".into() } else { out }
}

struct CollOpts {
    existing_table: Option<String>,
    label: String,
    editor: bool,
    drafts: bool,
    revisions: bool,
    title: Option<String>,
    content: Option<String>,
    slug: Option<String>,
    access: IndexMap<String, Access>,
    api: Api,
    uid: bool,
    sync: Option<Json>,
    auth: bool,
    agents: bool,
}

fn collection_options(
    c: &CollectionVal,
    columns: &IndexMap<String, Column>,
    ev: &Evaluator<'_>,
    out: &mut Extracted,
) -> Result<CollOpts> {
    let mut api = Api {
        list: true,
        get: true,
        create: true,
        update: true,
        delete: true,
    };
    let empty = Value::object(IndexMap::new());
    let opts_val = c.options.clone().unwrap_or(empty);
    let Value::Object(o) = &opts_val else { unreachable!() };
    let o = o.borrow().clone();
    let span = |k: &str| ev.key_span(&opts_val, k).unwrap_or(c.span);
    let flag = |k: &str| -> Result<Option<bool>> {
        match o.get(k) {
            None | Some(Value::Undef) => Ok(None),
            Some(Value::Bool(b)) => Ok(Some(*b)),
            Some(other) => Err(Diag::new(
                span(k),
                format!("`{k}` must be true or false, not {}", other.describe()),
            )),
        }
    };
    let name_opt = |k: &str| -> Result<Option<String>> {
        match o.get(k) {
            None | Some(Value::Undef) => Ok(None),
            Some(Value::Str(s)) => Ok(Some(s.to_string())),
            Some(other) => Err(Diag::new(
                span(k),
                format!("`{k}` must be a column name, not {}", other.describe()),
            )),
        }
    };
    for k in o.keys() {
        if !COLLECTION_OPTIONS.contains(&k.as_str()) {
            let mut d = Diag::new(span(k), format!("collection() has no option `{k}`"));
            if let Some(s) = suggest(k, COLLECTION_OPTIONS.iter().copied()) {
                d = d.help(format!("did you mean `{s}`?"));
            }
            return Err(d);
        }
    }
    let label = match o.get("label") {
        None | Some(Value::Undef) => title_case(c.table()),
        Some(Value::Str(s)) => s.to_string(),
        Some(other) => {
            return Err(Diag::new(
                span("label"),
                format!("`label` must be a string, not {}", other.describe()),
            ));
        }
    };
    let existing_table = match o.get("existingTable") {
        None | Some(Value::Undef) => None,
        Some(Value::Str(name)) if c.kind == StorageKind::Table && valid_ident(name) && name.len() <= 64 => {
            Some(name.to_string())
        }
        _ => {
            return Err(Diag::new(
                span("existingTable"),
                "existingTable requires table() and an exact SQL identifier of at most 64 ASCII characters",
            ));
        }
    };
    if let Some(name) = &existing_table
        && out
            .schema
            .collections
            .values()
            .any(|other| other.existing_table.as_ref() == Some(name))
    {
        return Err(Diag::new(
            span("existingTable"),
            "two tables cannot bind the same existingTable",
        ));
    }
    let auth = c.kind == StorageKind::UserProjection;
    let editor = c.kind == StorageKind::Collection;
    if auth {
        for key in ["drafts", "revisions"] {
            if flag(key)? == Some(true) {
                return Err(Diag::new(span(key), format!("userProjection() requires {key}: false"))
                    .help("remove this option; WordPress owns user history"));
            }
        }
    }
    let drafts = match flag("drafts")? {
        Some(false) if editor => {
            return Err(Diag::new(span("drafts"), "collections always have native draft status"));
        }
        Some(d) => d,
        None => editor,
    };
    let revisions = match flag("revisions")? {
        Some(false) if editor => {
            return Err(Diag::new(span("revisions"), "collections always have native revisions")
                .help("remove this option; use table() for application data"));
        }
        Some(r) => r,
        None => editor,
    };

    if editor && c.table().len() > MAX_POST_TYPE_TABLE {
        return Err(Diag::new(
            c.span,
            format!(
                "\"{}\" is too long for an editor collection: WordPress post types are limited to 20 characters and Zero prefixes `zero_`",
                c.table()
            ),
        )
        .help(format!(
            "use a name of at most {MAX_POST_TYPE_TABLE} characters, or use table() for SQL data"
        )));
    }
    if editor
        && let Some(pk) = columns.values().find(|c| c.primary_key)
        && !(pk.name == "id" && pk.ty == ColType::Integer && pk.auto_increment)
    {
        return Err(Diag::new(
            c.columns[&pk.name].span,
            format!(
                "\"{}\" is an editor collection, so its primary key is the WordPress post ID",
                c.table()
            ),
        )
        .help("use `id: id()`, or use table() for SQL data"));
    }

    let sync = match o.get("sync") {
        None | Some(Value::Undef) => None,
        Some(Value::Str(s)) => Some(sync_config(s, span("sync"))?),
        _ => {
            return Err(
                Diag::new(span("sync"), "sync must be a project-relative *.md or *.json pattern")
                    .help("use content/posts/*.md"),
            );
        }
    };
    let uid = flag("uid")?.unwrap_or(sync.is_some());
    if sync.is_some() && !uid {
        return Err(Diag::new(span("uid"), "uid: false cannot be combined with sync").help("remove uid: false"));
    }
    let agents = flag("agents")?.unwrap_or(true);

    // title
    let explicit_title = name_opt("title")?;
    let title = match &explicit_title {
        Some(t) => Some(t.clone()),
        None => columns
            .get("title")
            .filter(|col| col.ty == ColType::Text && editor)
            .map(|col| col.name.clone()),
    };
    if let Some(t) = &title {
        let sp = if explicit_title.is_some() {
            span("title")
        } else {
            c.columns[t].span
        };
        match columns.get(t) {
            None => return Err(no_column(c.table(), t, columns, sp, "title")),
            Some(col) if !(col.ty == ColType::Text && col.not_null) => {
                return Err(Diag::new(
                    sp,
                    format!(
                        "the title column `{t}` must be a notNull text(), but it's {}",
                        describe_col(col)
                    ),
                )
                .help(format!("declare it as `{t}: text().notNull()`")));
            }
            _ => {}
        }
    } else if editor {
        return Err(
            Diag::new(c.span, format!("editor collection \"{}\" has no title", c.table()))
                .help("add a `title: text().notNull()` column or `title:` option, or use table() for SQL data"),
        );
    }

    // content
    let explicit_content = name_opt("content")?;
    let content = match &explicit_content {
        Some(t) => Some(t.clone()),
        None => columns
            .get("content")
            .filter(|col| col.ty == ColType::RichText)
            .map(|col| col.name.clone()),
    };
    if let Some(t) = &content {
        let sp = span("content");
        match columns.get(t) {
            None => return Err(no_column(c.table(), t, columns, sp, "content")),
            Some(col) if col.ty != ColType::RichText => {
                return Err(Diag::new(
                    sp,
                    format!(
                        "the content column `{t}` must be richText(), but it's {}",
                        describe_col(col)
                    ),
                ));
            }
            _ => {}
        }
    }

    // slug
    let slugs: Vec<&Column> = columns.values().filter(|c| c.ty == ColType::Slug).collect();
    if slugs.len() > 1 {
        return Err(Diag::new(
            c.columns[&slugs[1].name].span,
            format!("\"{}\" has more than one slug() column", c.table()),
        )
        .help("a collection has at most one slug; use text() for the others"));
    }
    let slug = slugs.first().map(|s| s.name.clone());
    if let Some(s) = slugs.first()
        && let Some(src) = &s.source
    {
        let sp = c.columns[&s.name].span;
        match columns.get(src) {
            None => return Err(no_column(c.table(), src, columns, sp, "slug source")),
            Some(col) if col.ty != ColType::Text => {
                return Err(Diag::new(
                    sp,
                    format!(
                        "slug source `{src}` must be a text() column, but it's {}",
                        describe_col(col)
                    ),
                ));
            }
            _ => {}
        }
    }

    // drafts system columns
    if drafts {
        for reserved in ["status", "publishedAt"] {
            if let Some(b) = c.columns.get(reserved) {
                return Err(Diag::new(
                    b.span,
                    format!("`{reserved}` is reserved: drafts add these (status, publishedAt)"),
                )
                .help("rename the column; tables may also set `drafts: false`"));
            }
        }
    }

    // access
    let owner = Owner::Collection(c.table().to_string());
    let mut access: IndexMap<String, Access> = ACCESS_OPS
        .iter()
        .map(|op| {
            (
                op.to_string(),
                if *op == "read" {
                    Access::anyone()
                } else {
                    Access::editor()
                },
            )
        })
        .collect();
    if let Some(av) = o.get("access").filter(|v| !matches!(v, Value::Undef)) {
        let Value::Object(am) = av else {
            return Err(Diag::new(
                span("access"),
                "`access` must be an object like `{ read: \"anyone\", create: \"editor\" }`",
            ));
        };
        for (op, rule) in am.borrow().iter() {
            let sp = ev.key_span(av, op).unwrap_or(span("access"));
            if !ACCESS_OPS.contains(&op.as_str()) {
                let mut d = Diag::new(sp, format!("`access` has no operation `{op}`"));
                if let Some(s) = suggest(op, ACCESS_OPS.iter().copied()) {
                    d = d.help(format!("did you mean `{s}`?"));
                } else {
                    d = d.help("use read, create, update, delete");
                }
                return Err(d);
            }
            let name = format!("{}__access__{op}", c.table());
            access.insert(op.clone(), access_rule(rule, sp, &owner, op, name, out)?);
        }
    }

    // api
    if let Some(v) = o.get("api").filter(|v| !matches!(v, Value::Undef)) {
        let Value::Object(flags) = v else {
            return Err(Diag::new(
                span("api"),
                "`api` must be an object like `{ delete: false }`",
            ));
        };
        for (fk, fv) in flags.borrow().iter() {
            let Value::Bool(b) = fv else {
                return Err(Diag::new(span("api"), format!("`api.{fk}` must be true or false")));
            };
            match fk.as_str() {
                "list" => api.list = *b,
                "get" => api.get = *b,
                "create" => api.create = *b,
                "update" => api.update = *b,
                "delete" => api.delete = *b,
                other => {
                    return Err(Diag::new(span("api"), format!("`api` has no flag `{other}`"))
                        .help("use list, get, create, update, delete"));
                }
            }
        }
    }
    Ok(CollOpts {
        existing_table,
        label,
        editor,
        drafts,
        revisions,
        title,
        content,
        slug,
        access,
        api,
        uid,
        sync,
        auth,
        agents,
    })
}

fn no_column(table: &str, name: &str, columns: &IndexMap<String, Column>, span: Span, what: &str) -> Diag {
    let mut d = Diag::new(span, format!("{what} `{name}` isn't a column of \"{table}\""));
    if let Some(s) = suggest(name, columns.keys().map(String::as_str)) {
        d = d.help(format!("did you mean `{s}`?"));
    }
    d
}

fn describe_col(c: &Column) -> String {
    let mut s = kind_phrase(c.ty);
    if !c.not_null && c.ty == ColType::Text {
        s = format!("a nullable {}", &s[2..]);
    }
    s
}

/// One rule (§1.6): boolean, "anyone", "authenticated", a role, or a function.
fn access_rule(v: &Value, span: Span, owner: &Owner, op: &str, fn_name: String, out: &mut Extracted) -> Result<Access> {
    Ok(match v {
        Value::Bool(b) => Access::Allow(*b),
        Value::Str(s) if &**s == "anyone" || &**s == "authenticated" || ROLES.contains(&&**s) => {
            Access::Role(s.to_string())
        }
        Value::Str(s) if &**s == "self" => {
            let auth = match owner {
                Owner::Collection(t) => out
                    .collections
                    .iter()
                    .any(|c| c.table() == t && c.kind == StorageKind::UserProjection),
                _ => false,
            };
            if !auth || op == "create" {
                return Err(
                    Diag::new(span, "self is only allowed on user projection read/update/delete rules")
                        .help("use a role or a boolean create rule"),
                );
            }
            Access::Self_
        }
        Value::Str(s) if &**s == "owner" => {
            if !matches!(owner, Owner::FileBucket(_)) {
                return Err(Diag::new(span, "owner is only allowed in files access")
                    .help("use self on user projections or a role"));
            }
            Access::Owner
        }
        Value::Closure(f) => {
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::Access {
                    owner: owner.clone(),
                    op: op.to_string(),
                },
                name: fn_name,
            });
            Access::Fn(String::new())
        }
        other => {
            let mut d = Diag::new(span, format!("{} isn't an access rule", other.describe()));
            let names = ["anyone", "authenticated", "subscriber", "author", "editor", "admin"];
            match other {
                Value::Str(s) if suggest(s, names).is_some() => {
                    d = d.help(format!("did you mean \"{}\"?", suggest(s, names).expect("suggestion")));
                }
                _ => {
                    d = d.help(
                        "use true/false, \"anyone\", \"authenticated\", a role (\"subscriber\", \"author\", \"editor\", \"admin\"), or (ctx) => ...",
                    )
                }
            }
            return Err(d);
        }
    })
}

fn global(key: &str, g: &GlobalVal, span: Span, ev: &mut Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    if g.name != key {
        return Err(Diag::new(
            span,
            format!(
                "`globals.{key}` holds global(\"{}\"); the key and the name must match",
                g.name
            ),
        )
        .help(format!("write `{0}: global(\"{0}\", ...)`", key)));
    }
    if !valid_ident(key) {
        return Err(Diag::new(g.span, format!("\"{key}\" isn't a valid global name")));
    }
    if crate::client::reserved_type_name(key) {
        return Err(
            Diag::new(g.span, format!("\"{key}\" is reserved by generated client types"))
                .help("choose a global name that does not conflict with a built-in client type"),
        );
    }
    let owner = Owner::Global(key.to_string());
    let mut fields = IndexMap::new();
    for (name, b) in &g.fields {
        let col = column(name, b, &owner, &[], Place::Global, ev, out)?;
        if col.not_null && col.default.is_none() {
            return Err(Diag::new(
                b.span,
                format!("global fields that are notNull need a default (`{name}`)"),
            )
            .help("add `.default(...)`, or drop `.notNull()`"));
        }
        fields.insert(name.clone(), col);
    }
    let mut label = title_case(key);
    let mut agents = true;
    let mut access: IndexMap<String, Access> = [
        ("read".to_string(), Access::anyone()),
        ("update".to_string(), Access::editor()),
    ]
    .into_iter()
    .collect();
    if let Some(opts) = &g.options {
        let Value::Object(o) = opts else { unreachable!() };
        for (k, v) in o.borrow().iter() {
            let sp = ev.key_span(opts, k).unwrap_or(g.span);
            match (k.as_str(), v) {
                ("label", Value::Str(s)) => label = s.to_string(),
                ("agents", Value::Bool(b)) => agents = *b,
                ("agents", Value::Undef) => {}
                ("access", Value::Object(am)) => {
                    for (op, rule) in am.borrow().iter() {
                        let osp = ev.key_span(v, op).unwrap_or(sp);
                        if op != "read" && op != "update" {
                            return Err(Diag::new(osp, format!("global access has no operation `{op}`"))
                                .help("globals have read and update"));
                        }
                        let rule = access_rule(rule, osp, &owner, op, format!("global__{key}__access__{op}"), out)?;
                        access.insert(op.clone(), rule);
                    }
                }
                ("label" | "access", other) => {
                    return Err(Diag::new(sp, format!("`{k}` can't be {}", other.describe())));
                }
                _ => {
                    return Err(Diag::new(sp, format!("global() has no option `{k}`")).help("use label, access"));
                }
            }
        }
    }
    out.schema.globals.insert(
        key.to_string(),
        Global {
            name: key.to_string(),
            label,
            fields,
            access,
            agents,
        },
    );
    Ok(())
}

/// §3.5.1: a hint per post-backed column that only postmeta can filter.
fn postmeta_hints(out: &mut Extracted) {
    let mut hints = Vec::new();
    for c in out.schema.collections.values() {
        for col in c.columns.values() {
            if !c.editor || c.is_core_column(&col.name) {
                continue;
            }
            if col.unique || col.references.is_some() {
                hints.push((c.table.clone(), col.name.clone()));
            }
        }
        for col in c.columns.values() {
            if let Some(r) = &col.references
                && let Some(t) = out.schema.collections.get(&r.collection)
                && t.editor
                && !t.is_core_column(&r.column)
            {
                hints.push((t.table.clone(), r.column.clone()));
            }
        }
    }
    for (t, col) in hints {
        let h = format!(
            "\"{t}.{col}\" is filtered through postmeta on WordPress; use table() for SQL data if this list grows large"
        );
        if !out.hints.contains(&h) {
            out.hints.push(h);
        }
    }
}

fn column(
    name: &str,
    b: &ColBuilder,
    owner: &Owner,
    parent: &[String],
    place: Place,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
) -> Result<Column> {
    if !valid_ident(name) {
        return Err(Diag::new(b.span, format!("\"{name}\" isn't a valid column name"))
            .help("use letters, digits, and underscores, starting with a letter"));
    }
    let mut path = parent.to_vec();
    path.push(name.to_string());
    let at = ColPath {
        owner: owner.clone(),
        path: path.clone(),
    };
    if b.localized {
        if !matches!(
            b.ty,
            ColType::Text
                | ColType::RichText
                | ColType::Json
                | ColType::Select
                | ColType::Group
                | ColType::Repeater
                | ColType::Blocks
        ) || b.primary_key
        {
            return Err(Diag::new(
                b.span_of("localized"),
                "translate text, not values; put it in a localized group if it must vary",
            )
            .help("remove .localized() from this column"));
        }
        if b.unique {
            return Err(
                Diag::new(b.span_of("unique"), "unique is not allowed on a localized column")
                    .help("add a second column or use slug()"),
            );
        }
        if out.schema.locales.is_none() {
            return Err(Diag::new(b.span_of("localized"), ".localized() requires locales")
                .help("configure locales: { default: \"en\", all: [\"en\"] }"));
        }
        if !matches!(owner, Owner::Collection(_)) {
            return Err(Diag::new(b.span_of("localized"), ".localized() needs a collection")
                .help("move the translated field into a collection"));
        }
    }
    if b.primary_key && (b.access.is_some() || b.show_if.is_some()) {
        return Err(Diag::new(
            b.span_of(if b.access.is_some() { "access" } else { "showIf" }),
            "access/showIf is not allowed on id() or system columns",
        )
        .help("remove the modifier"));
    }
    let mut access = IndexMap::new();
    if b.access.is_some() && !matches!(owner, Owner::Collection(_)) {
        return Err(
            Diag::new(b.span_of("access"), "field access applies to collection columns")
                .help("guard the whole global or action with its access rule instead"),
        );
    }
    if let Some(v) = &b.access {
        let rules = object(v, b.span_of("access"), "field access")?;
        for (op, rule) in rules {
            if !["read", "create", "update"].contains(&op.as_str()) {
                return Err(
                    Diag::new(b.span_of("access"), format!("field access has no operation {op}"))
                        .help("use read, create, update"),
                );
            }
            let parsed = access_rule(
                &rule,
                b.span_of("access"),
                owner,
                &op,
                format!("{}__{op}", fn_name(owner, "field", &path)),
                out,
            )?;
            if matches!(parsed, Access::Fn(_)) {
                out.roots.last_mut().unwrap().slot = Slot::FieldAccess {
                    at: at.clone(),
                    op: op.clone(),
                };
            }
            access.insert(op, parsed);
        }
    }
    let show_if = b
        .show_if
        .as_ref()
        .map(|v| plain_json(v, b.span_of("showIf"), "showIf"))
        .transpose()?;
    if let Some(bucket) = &b.bucket
        && !out.schema.files.contains_key(bucket)
    {
        return Err(Diag::new(b.span, format!("unknown file bucket {bucket:?}"))
            .help("declare it in config.files with files()"));
    }
    if b.bucket.is_some() && matches!(owner, Owner::Global(_)) {
        return Err(Diag::new(b.span, "file() columns belong to collections, not globals")
            .help("keep the file id on a collection row, or use media() for a public asset"));
    }
    let outer = place != Place::Top;
    // Builders and modifiers that only make sense on a top-level collection column.
    let forbidden: Option<(&str, Span)> = if !outer {
        None
    } else if b.primary_key {
        Some(("id() / .primaryKey()", b.span_of("primaryKey")))
    } else if b.unique && b.ty != ColType::Slug {
        Some(("`.unique()`", b.span_of("unique")))
    } else if b.references.is_some() {
        Some(("`.references()`", b.span_of("references")))
    } else if b.on_update.is_some() {
        Some(("`.$onUpdate()`", b.span_of("$onUpdate")))
    } else if b.ty == ColType::Slug {
        Some(("slug()", b.span))
    } else if b.ty == ColType::Blocks {
        Some(("blocks()", b.span))
    } else {
        None
    };
    if let Some((what, sp)) = forbidden {
        return Err(Diag::new(sp, format!("{what} isn't allowed {} (`{name}`)", place.where_()))
            .help(match place {
                Place::Sub(_) => "sub-fields hold plain values; keep ids, uniqueness, references, slugs, and blocks on collection columns",
                Place::Global => "globals are one record; keep ids, uniqueness, references, slugs, and blocks on collections",
                _ => "describe inputs with plain builders: integer(), text(), group(), ...",
            }));
    }
    if b.ty.is_structured() && place.depth() > MAX_NESTING {
        return Err(Diag::new(
            b.span,
            format!("`{name}` nests too deep: group and repeater fields go at most {MAX_NESTING} levels deep"),
        )
        .help("flatten the structure, or move it to its own collection"));
    }
    if b.unique && b.ty != ColType::Slug && b.ty.is_stringy() && b.max.is_none_or(|m| m > MAX_UNIQUE_TEXT) {
        return Err(Diag::new(
            b.span_of("unique"),
            format!("unique text columns are limited to 191 characters on MySQL (`{name}`); add `.max(191)`"),
        )
        .help("add `.max(191)` (or a smaller limit)"));
    }
    if let (Some(min), Some(max)) = (b.min, b.max)
        && min > max
    {
        return Err(Diag::new(
            b.span_of("min"),
            format!("`{name}` has `.min({min})` larger than `.max({max})`"),
        ));
    }

    let default = match &b.default {
        None => None,
        Some(DefaultVal::Now) => Some(DefaultKind::Now),
        Some(DefaultVal::Fn(f)) => {
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::Default { at: at.clone() },
                name: fn_name(owner, "default", &path),
            });
            Some(DefaultKind::Fn(String::new()))
        }
        Some(DefaultVal::Value(v)) => Some(DefaultKind::Value(check_value(name, b, v)?)),
    };
    let on_update = match &b.on_update {
        None => None,
        Some(DefaultVal::Now) => Some(DefaultKind::Now),
        Some(DefaultVal::Fn(f)) => {
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::OnUpdate { at: at.clone() },
                name: fn_name(owner, "onUpdate", &path),
            });
            Some(DefaultKind::Fn(String::new()))
        }
        Some(DefaultVal::Value(_)) => unreachable!("$onUpdate always takes a function"),
    };
    let references = match &b.references {
        None => None,
        Some((f, on_delete, on_update, span)) => {
            let target = ev.call_closure_public(f, vec![], *span)?;
            let Value::ColRef(r) = target else {
                return Err(Diag::new(
                    *span,
                    format!(
                        "`references(() => ...)` must return a column like `locations.id`, not {}",
                        target.describe()
                    ),
                ));
            };
            if !out.collections.iter().any(|c| Rc::ptr_eq(c, &r.collection)) {
                return Err(Diag::new(
                    *span,
                    format!(
                        "`{name}` references collection \"{}\", which is not registered",
                        r.collection.table()
                    ),
                ));
            }
            let target_col = &r.collection.columns[&r.column];
            if target_col.ty != b.ty {
                return Err(Diag::new(
                    *span,
                    format!(
                        "`{name}` is {}() but references {}.{}, which is {}()",
                        b.ty.name(),
                        r.collection.table(),
                        r.column,
                        target_col.ty.name()
                    ),
                ));
            }
            if !target_col.primary_key && !target_col.unique {
                return Err(Diag::new(
                    *span,
                    format!(
                        "{}.{} must be a primary key or unique to be referenced",
                        r.collection.table(),
                        r.column
                    ),
                ));
            }
            if *on_delete == Some(RefAction::SetNull) && b.not_null {
                return Err(Diag::new(
                    *span,
                    format!("`{name}` is notNull(), so `onDelete: \"set null\"` can't work"),
                ));
            }
            Some(Reference {
                collection: r.collection.table().to_string(),
                column: r.column.clone(),
                on_delete: *on_delete,
                on_update: *on_update,
            })
        }
    };
    let validate = match &b.validate {
        Some(f) => {
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::Validate { at: at.clone() },
                name: fn_name(owner, "validate", &path),
            });
            Some(String::new())
        }
        None => None,
    };
    let sub_place = Place::Sub(place.depth() + 1);
    let fields = match &b.fields {
        None => None,
        Some(fs) => {
            let mut m = IndexMap::new();
            for (k, fb) in fs {
                m.insert(k.clone(), column(k, fb, owner, &path, sub_place, ev, out)?);
            }
            Some(m)
        }
    };
    let layouts = match &b.layouts {
        None => None,
        Some(ls) => {
            let mut m = IndexMap::new();
            for (lname, fs) in ls {
                if !valid_ident(lname) {
                    return Err(Diag::new(b.span, format!("\"{lname}\" isn't a valid layout name")));
                }
                let mut lpath = path.clone();
                lpath.push(lname.clone());
                let mut fm = IndexMap::new();
                for (k, fb) in fs {
                    if k == "layout" {
                        return Err(Diag::new(
                            fb.span,
                            format!("`layout` is reserved in blocks items (layout `{lname}`)"),
                        )
                        .help("each item's `layout` key names its layout; rename the field"));
                    }
                    fm.insert(k.clone(), column(k, fb, owner, &lpath, sub_place, ev, out)?);
                }
                m.insert(lname.clone(), fm);
            }
            Some(m)
        }
    };
    let ui =
        b.ui.as_ref()
            .map(|value| {
                let span = b.span_of("ui");
                let mut options = object(value, span, "ui")?;
                keys(&options, &["label", "help", "placeholder", "width"], span, "ui")?;
                options.retain(|_, value| !matches!(value, Value::Undef));
                let value = plain_json(&Value::object(options), span, "ui")?;
                if !value.is_object() || value.as_object().is_some_and(|obj| obj.values().any(|v| v.is_null())) {
                    return Err(Diag::new(
                        span,
                        ".ui() needs an object with string label/help/placeholder and an integer width from 1 to 100",
                    ));
                }
                let ui: crate::schema::FieldUi = serde_json::from_value(value)
                    .map_err(|e| Diag::new(span, format!("invalid .ui() metadata: {e}")))?;
                if ui.width.is_some_and(|width| !(1..=100).contains(&width)) {
                    return Err(Diag::new(span, ".ui() width must be an integer from 1 to 100"));
                }
                Ok(ui)
            })
            .transpose()
            .map_err(|d: Diag| d.code(crate::diag::Code::FieldUi))?;
    Ok(Column {
        name: name.to_string(),
        ty: b.ty,
        not_null: b.not_null,
        primary_key: b.primary_key,
        auto_increment: b.auto_increment,
        unique: b.unique,
        enum_values: b.enum_values.clone(),
        multiline: b.multiline,
        accept: b.accept.clone(),
        source: b.source.clone(),
        default,
        on_update,
        references,
        rules: Rules {
            min: b.min,
            max: b.max,
            pattern: b.pattern.clone(),
            validate,
        },
        fields,
        layouts,
        system: false,
        localized: b.localized,
        access,
        show_if,
        ui,
        bucket: b.bucket.clone(),
        derived: false,
        hidden: false,
        ts_type: b.ts_type.clone(),
    })
}

/// `posts__validate__seo__metaTitle`, `global__settings__default__x`, `action__a__default__x`.
fn fn_name(owner: &Owner, what: &str, path: &[String]) -> String {
    let base = match owner {
        Owner::Collection(t) => t.clone(),
        Owner::Global(g) => format!("global__{g}"),
        Owner::Action(a) => format!("action__{a}"),
        Owner::Job(j) => format!("job__{j}"),
        Owner::FileBucket(f) => format!("files__{f}"),
    };
    format!("{base}__{what}__{}", path.join("__"))
}

/// Validates a constant default against its column and returns it as JSON.
/// True when a JSON object nested in `v` can't survive PHP: `json_decode(.., true)` turns an object
/// into an array, and one whose keys are exactly `"0".."n-1"` (in any order; the store sorts integer
/// A timestamp/date sub-field inside a group/repeater/blocks default that the runtime codec would
/// reject when the default is applied. Returns the sub-field's name.
fn bad_date_leaf(b: &ColBuilder, v: &Json) -> Option<String> {
    let fields = |fields: &IndexMap<String, Rc<ColBuilder>>, item: &Json| {
        fields.iter().find_map(|(name, f)| {
            let x = item.get(name)?;
            match (f.ty, x) {
                (ColType::Timestamp, Json::String(s)) if is_iso_timestamp(s) => None,
                // Epoch ms whose ISO form keeps a four-digit year (0000-01-01 .. 9999-12-31), as the runtime.
                (ColType::Timestamp, Json::Number(n))
                    if n.as_f64().is_some_and(|n| {
                        n.fract() == 0.0 && (-62_167_219_200_000.0..=253_402_300_799_999.0).contains(&n)
                    }) =>
                {
                    None
                }
                (ColType::Date, Json::String(s)) if is_date(s) => None,
                (ColType::Timestamp | ColType::Date, Json::Null) => None,
                (ColType::Timestamp | ColType::Date, _) => Some(name.clone()),
                _ => bad_date_leaf(f, x),
            }
        })
    };
    match (b.ty, v) {
        (ColType::Group, item) => b.fields.as_ref().and_then(|f| fields(f, item)),
        (ColType::Repeater, Json::Array(items)) => {
            b.fields.as_ref().and_then(|f| items.iter().find_map(|i| fields(f, i)))
        }
        (ColType::Blocks, Json::Array(items)) => b.layouts.as_ref().and_then(|layouts| {
            items.iter().find_map(|i| {
                let f = layouts.get(i.get("layout")?.as_str()?)?;
                fields(f, i)
            })
        }),
        _ => None,
    }
}

fn check_value(name: &str, b: &ColBuilder, v: &Value) -> Result<Json> {
    let span = b.span_of("default");
    let json = plain_json(v, span, "default")?;
    if let Some(field) = bad_date_leaf(b, &json) {
        return Err(Diag::new(
            span,
            format!("`{name}` defaults to a `{field}` value that isn't a real ISO 8601 date or timestamp"),
        )
        .help("use \"YYYY-MM-DD\" for dates and an ISO string like \"2026-01-31T09:00:00Z\" for timestamps"));
    }
    let bad = |what: &str| -> Result<Json> {
        Err(Diag::new(
            span,
            format!("`{name}` defaults to {}, but it's {what}", v.describe()),
        ))
    };
    if matches!(v, Value::Null | Value::Undef) {
        if b.not_null {
            return bad("notNull()");
        }
        return Ok(Json::Null);
    }
    let in_enum = |s: &str| b.enum_values.as_ref().is_none_or(|e| e.iter().any(|x| x == s));
    match (b.ty, v) {
        (ColType::Integer, Value::Num(n)) if n.fract() == 0.0 && n.is_finite() => {}
        (ColType::Integer, _) => return bad("an integer column"),
        (ColType::Real, Value::Num(n)) if n.is_finite() => {}
        (ColType::Real, _) => return bad("a real column"),
        (ColType::Boolean, Value::Bool(_)) => {}
        (ColType::Boolean, _) => return bad("a boolean column"),
        (ColType::Text, Value::Str(s)) => {
            if !in_enum(s) {
                return Err(Diag::new(
                    span,
                    format!(
                        "`{name}` defaults to \"{s}\", which isn't one of {}",
                        b.enum_values.as_ref().expect("enum").join(", ")
                    ),
                ));
            }
        }
        (ColType::Email | ColType::Url | ColType::Slug | ColType::RichText, Value::Str(_)) => {}
        (ColType::Text | ColType::Email | ColType::Url | ColType::Slug | ColType::RichText, _) => {
            return bad(&kind_phrase(b.ty));
        }
        (ColType::Date, Value::Str(s)) if is_date(s) => {}
        (ColType::Date, _) => return bad("a date column (use \"YYYY-MM-DD\" or .defaultNow())"),
        (ColType::Timestamp, Value::Str(s)) if is_iso_timestamp(s) => {}
        (ColType::Timestamp, _) => {
            return bad("a timestamp column (use an ISO string like \"2026-01-31T09:00:00Z\" or .defaultNow())");
        }
        (ColType::Json, _) => {}
        (ColType::Media, _) => return bad("a media column"),
        (ColType::File, Value::Num(n)) if n.fract() == 0.0 && n.is_finite() => {}
        (ColType::File, _) => return bad("a file id column"),
        (ColType::MediaList, Value::Array(a)) => {
            if !a
                .borrow()
                .iter()
                .all(|x| matches!(x, Value::Num(n) if n.fract() == 0.0))
            {
                return bad("a list of media ids");
            }
        }
        (ColType::Select, Value::Array(a)) => {
            for x in a.borrow().iter() {
                match x {
                    Value::Str(s) if in_enum(s) => {}
                    _ => {
                        return Err(Diag::new(
                            span,
                            format!(
                                "`{name}` defaults to {}, but every item must be one of {}",
                                v.describe(),
                                b.enum_values.as_ref().map(|e| e.join(", ")).unwrap_or_default()
                            ),
                        ));
                    }
                }
            }
        }
        (ColType::Repeater | ColType::Blocks, Value::Array(_)) => {}
        (ColType::Group, Value::Object(_)) => {}
        (ColType::MediaList | ColType::Select | ColType::Repeater | ColType::Blocks, _) => {
            return bad("a list column (use an array)");
        }
        (ColType::Group, _) => return bad("a group column (use an object)"),
    }
    to_json(v).ok_or_else(|| {
        Diag::new(
            span,
            format!("`{name}` defaults to {}, which isn't plain data", v.describe()),
        )
    })
}

/// `YYYY-MM-DD` naming a real calendar day (the runtime's date codec).
fn is_date(s: &str) -> bool {
    let b = s.as_bytes();
    if !s.is_ascii() || b.len() != 10 || b[4] != b'-' || b[7] != b'-' {
        return false;
    }
    let (Some(y), Some(m), Some(d)) = (digits(&s[0..4]), digits(&s[5..7]), digits(&s[8..10])) else {
        return false;
    };
    let leap = y % 4 == 0 && (y % 100 != 0 || y % 400 == 0);
    let days = match m {
        2 if leap => 29,
        2 => 28,
        4 | 6 | 9 | 11 => 30,
        1..=12 => 31,
        _ => 0,
    };
    (1..=days).contains(&d)
}

/// The runtime's timestamp codec for strings: a date, or a date-time with `Z`/`±HH:MM`,
/// every component in range (`YYYY-MM-DD[THH:mm[:ss[.fff]](Z|±HH:MM)]`).
fn is_iso_timestamp(s: &str) -> bool {
    if !s.is_ascii() || s.len() < 10 {
        return false;
    }
    let (date, rest) = s.split_at(10);
    if !is_date(date) {
        return false;
    }
    let Some(time) = rest.strip_prefix('T') else {
        return rest.is_empty();
    };
    let (clock, zone) = match time.find(['Z', '+', '-']) {
        Some(i) => time.split_at(i),
        None => return false,
    };
    let zone_ok = zone == "Z"
        || (zone.len() == 6
            && (zone.starts_with('+') || zone.starts_with('-'))
            && &zone[3..4] == ":"
            && digits(&zone[1..3]).is_some_and(|h| h <= 23)
            && digits(&zone[4..6]).is_some_and(|m| m <= 59));
    let (hms, frac) = clock.split_once('.').map_or((clock, None), |(a, b)| (a, Some(b)));
    let parts: Vec<&str> = hms.split(':').collect();
    let limits = [23, 59, 59];
    // Like the runtime codec: the UTC-normalized value must keep a four-digit year.
    let shifts_out = zone_ok
        && match zone.as_bytes().first() {
            Some(b'+') => date.starts_with("0000-01-01") && clock < &zone[1..],
            Some(b'-') => date.starts_with("9999-12-31") && *clock >= *rev_offset(&zone[1..]),
            _ => false,
        };
    zone_ok
        && !shifts_out
        && (2..=3).contains(&parts.len())
        && frac.is_none_or(|f| parts.len() == 3 && !f.is_empty() && f.bytes().all(|c| c.is_ascii_digit()))
        && parts
            .iter()
            .zip(limits)
            .all(|(p, max)| p.len() == 2 && digits(p).is_some_and(|n| n <= max))
}

/// The earliest local time on 9999-12-31 that a negative offset `HH:MM` pushes into year 10000.
fn rev_offset(offset: &str) -> String {
    let (h, m) = (digits(&offset[0..2]).unwrap_or(0), digits(&offset[3..5]).unwrap_or(0));
    let minutes = 24 * 60 - (h * 60 + m);
    format!("{:02}:{:02}", minutes / 60, minutes % 60)
}

fn digits(s: &str) -> Option<u32> {
    if s.is_empty() || !s.bytes().all(|c| c.is_ascii_digit()) {
        return None;
    }
    s.parse().ok()
}

fn relations(rel: &Value, ev: &Evaluator<'_>, out: &mut Extracted, span: Span) -> Result<()> {
    let Value::Object(o) = rel else {
        return Err(Diag::new(
            span,
            "`relations` must come from defineRelations(schema, (r) => ({ ... }))",
        ));
    };
    let o = o.borrow();
    if !matches!(o.get("$zeroRelations"), Some(Value::Bool(true))) {
        return Err(Diag::new(
            span,
            "`relations` must come from defineRelations(schema, (r) => ({ ... }))",
        ));
    }
    let defs_val = o.get("relations").cloned().unwrap_or(Value::Undef);
    let Value::Object(defs) = &defs_val else {
        return Err(Diag::new(
            span,
            "the defineRelations() callback must return an object keyed by collection",
        ));
    };
    let col_ref = |v: Option<&Value>, what: &str, rspan: Span| -> Result<Option<(String, String)>> {
        match v {
            None | Some(Value::Undef) => Ok(None),
            Some(Value::ColRef(r)) => Ok(Some((r.collection.table().to_string(), r.column.clone()))),
            Some(other) => Err(Diag::new(
                rspan,
                format!(
                    "`{what}` must be a column like `r.items.locationId`, not {}",
                    other.describe()
                ),
            )),
        }
    };
    let mut pending_many = Vec::new();
    for (key, rels) in defs.borrow().iter() {
        let kspan = ev.key_span(&defs_val, key).unwrap_or(span);
        let Some(coll) = out.schema.by_key(key) else {
            return Err(Diag::new(
                kspan,
                format!("relations for `{key}`, which is not registered"),
            ));
        };
        let table = coll.table.clone();
        let Value::Object(rels_map) = rels else {
            return Err(Diag::new(kspan, format!("relations for `{key}` must be an object")));
        };
        for (rname, rv) in rels_map.borrow().iter() {
            let rspan = ev.key_span(rels, rname).unwrap_or(kspan);
            let Value::Relation(r) = rv else {
                return Err(Diag::new(
                    rspan,
                    format!("`{rname}` must be r.one.<collection>(...) or r.many.<collection>(...)"),
                ));
            };
            if out.schema.collections[&table].columns.contains_key(rname) {
                return Err(Diag::new(
                    rspan,
                    format!("relation `{rname}` has the same name as a column of \"{table}\""),
                ));
            }
            let target = out
                .schema
                .by_key(&r.target)
                .expect("relation targets come from schema keys")
                .table
                .clone();
            let (mut from, mut to, mut optional) = (None, None, true);
            if let Some(cfg_val) = &r.config {
                let Value::Object(cfg) = cfg_val else { unreachable!() };
                for (k, v) in cfg.borrow().iter() {
                    match k.as_str() {
                        "from" => from = col_ref(Some(v), "from", rspan)?,
                        "to" => to = col_ref(Some(v), "to", rspan)?,
                        "optional" => match v {
                            Value::Bool(b) => optional = *b,
                            _ => return Err(Diag::new(rspan, "`optional` must be true or false")),
                        },
                        other => {
                            return Err(Diag::new(rspan, format!("relations have no option `{other}`"))
                                .help("use from, to, optional"));
                        }
                    }
                }
            }
            match (r.kind, from, to) {
                (RelKind::One, Some((ft, fc)), Some((tt, tc))) => {
                    if ft != table || tt != target {
                        return Err(Diag::new(
                            rspan,
                            format!("`{rname}` must go from a column of \"{table}\" to a column of \"{target}\""),
                        ));
                    }
                    out.schema
                        .collections
                        .get_mut(&table)
                        .expect("collection")
                        .relations
                        .insert(
                            rname.clone(),
                            Relation {
                                name: rname.clone(),
                                kind: RelKind::One,
                                collection: target,
                                from: fc,
                                to: tc,
                                optional,
                            },
                        );
                }
                (RelKind::One, _, _) => {
                    return Err(
                        Diag::new(rspan, format!("`{rname}` needs `from` and `to`")).help(format!(
                            "r.one.{}({{ from: r.{key}.someId, to: r.{}.id }})",
                            r.target, r.target
                        )),
                    );
                }
                (RelKind::Many, Some((ft, fc)), Some((tt, tc))) => {
                    if ft != table || tt != target {
                        return Err(Diag::new(
                            rspan,
                            format!("`{rname}` must go from a column of \"{table}\" to a column of \"{target}\""),
                        ));
                    }
                    out.schema
                        .collections
                        .get_mut(&table)
                        .expect("collection")
                        .relations
                        .insert(
                            rname.clone(),
                            Relation {
                                name: rname.clone(),
                                kind: RelKind::Many,
                                collection: target,
                                from: fc,
                                to: tc,
                                optional: false,
                            },
                        );
                }
                (RelKind::Many, None, None) => pending_many.push((table.clone(), rname.clone(), target, rspan)),
                (RelKind::Many, _, _) => {
                    return Err(Diag::new(
                        rspan,
                        format!("`{rname}` needs both `from` and `to`, or neither"),
                    ));
                }
            }
        }
    }
    // `r.many.x()` with no config mirrors the one `r.one` pointing back.
    for (table, rname, target, rspan) in pending_many {
        let back: Vec<&Relation> = out.schema.collections[&target]
            .relations
            .values()
            .filter(|r| r.kind == RelKind::One && r.collection == table)
            .collect();
        let (from, to) = match back.as_slice() {
            [one] => (one.to.clone(), one.from.clone()),
            [] => {
                return Err(Diag::new(
                    rspan,
                    format!("can't infer `{rname}`: \"{target}\" has no r.one relation pointing at \"{table}\""),
                )
                .help("add `from` and `to`"));
            }
            _ => {
                return Err(Diag::new(
                    rspan,
                    format!("can't infer `{rname}`: \"{target}\" has several relations pointing at \"{table}\""),
                )
                .help("add `from` and `to`"));
            }
        };
        out.schema
            .collections
            .get_mut(&table)
            .expect("collection")
            .relations
            .insert(
                rname.clone(),
                Relation {
                    name: rname,
                    kind: RelKind::Many,
                    collection: target,
                    from,
                    to,
                    optional: false,
                },
            );
    }
    Ok(())
}

/// Keys the function assigns on its first parameter (`data.slug = ...`).
pub fn assigned_keys(f: &Closure) -> Vec<String> {
    let Some(Pat::Id(param, _)) = f.func.params.first().map(|p| &p.pat) else {
        return vec![];
    };
    let mut out = Vec::new();
    match &f.func.body {
        FnBody::Expr(e) => scan_expr(e, param, &mut out),
        FnBody::Block(b) => scan_stmts(b, param, &mut out),
    }
    out
}

fn scan_stmts(stmts: &[Stmt], param: &str, out: &mut Vec<String>) {
    for s in stmts {
        match s {
            Stmt::Expr(e) | Stmt::Throw(e) => scan_expr(e, param, out),
            Stmt::Return(Some(e), _) => scan_expr(e, param, out),
            Stmt::Decl { init: Some(e), .. } => scan_expr(e, param, out),
            Stmt::If(t, a, b) => {
                scan_expr(t, param, out);
                scan_stmts(a, param, out);
                if let Some(b) = b {
                    scan_stmts(b, param, out);
                }
            }
            Stmt::Block(b) | Stmt::While(_, b) => scan_stmts(b, param, out),
            Stmt::For { body, .. } | Stmt::ForOf { body, .. } | Stmt::ForIn { body, .. } => {
                scan_stmts(body, param, out)
            }
            Stmt::Try {
                block,
                handler,
                finalizer,
                ..
            } => {
                scan_stmts(block, param, out);
                if let Some(h) = handler {
                    scan_stmts(h, param, out);
                }
                if let Some(f) = finalizer {
                    scan_stmts(f, param, out);
                }
            }
            Stmt::Switch { cases, .. } => {
                for (_, b) in cases {
                    scan_stmts(b, param, out);
                }
            }
            _ => {}
        }
    }
}

/// `data.slug = slugify(data.name)` fills `slug`; `data.name = data.name.trim()`
/// only rewrites `name`, which the writer must still provide.
fn scan_expr(e: &crate::ir::Expr, param: &str, out: &mut Vec<String>) {
    if let ExprKind::Sequence(items) = &e.kind {
        for item in items {
            scan_expr(item, param, out);
        }
    }
    if let ExprKind::Assign {
        op: None,
        target,
        value,
    } = &e.kind
        && let ExprKind::Member { obj, prop, .. } = &target.kind
        && matches!(&obj.kind, ExprKind::Ident(n) if n == param)
        && !reads_key(value, param, prop)
        && !out.contains(prop)
    {
        out.push(prop.clone());
    }
}

fn reads_key(e: &crate::ir::Expr, param: &str, key: &str) -> bool {
    use crate::ir::{ArrEl, Prop};
    let r = |x: &crate::ir::Expr| reads_key(x, param, key);
    match &e.kind {
        ExprKind::Member { obj, prop, .. } => match &obj.kind {
            ExprKind::Ident(n) if n == param => prop == key,
            _ => r(obj),
        },
        ExprKind::Ident(n) => n == param,
        ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args } => {
            r(callee)
                || args
                    .iter()
                    .any(|a| matches!(a, ArrEl::Item(x) | ArrEl::Spread(x) if r(x)))
        }
        ExprKind::Binary(_, a, b) | ExprKind::Logical(_, a, b) => r(a) || r(b),
        ExprKind::Cond(a, b, c) => r(a) || r(b) || r(c),
        ExprKind::Unary(_, x)
        | ExprKind::Await(x)
        | ExprKind::Chain(x)
        | ExprKind::As(x, _)
        | ExprKind::NonNull(x)
        | ExprKind::IsNullish(x, _) => r(x),
        ExprKind::Index { obj, index, .. } => r(obj) || r(index),
        ExprKind::Template(_, xs) | ExprKind::Sequence(xs) => xs.iter().any(r),
        ExprKind::Array(items) => items
            .iter()
            .any(|a| matches!(a, ArrEl::Item(x) | ArrEl::Spread(x) if r(x))),
        ExprKind::Object(props) => props.iter().any(|p| match p {
            Prop::KV(_, x) | Prop::Spread(x) => r(x),
        }),
        _ => false,
    }
}

// Phase-2 config is normalized once here. Feature modules consume this metadata;
// they do not reinterpret builders or apply policy during schema loading.
fn object(v: &Value, span: Span, what: &str) -> Result<IndexMap<String, Value>> {
    match v {
        Value::Object(o) => Ok(o.borrow().clone()),
        _ => {
            Err(Diag::new(span, format!("{what} must be an object")).help("use an object with the documented options"))
        }
    }
}

fn bool_option(o: &IndexMap<String, Value>, key: &str, default: bool, span: Span) -> Result<bool> {
    match o.get(key) {
        None | Some(Value::Undef) => Ok(default),
        Some(Value::Bool(b)) => Ok(*b),
        _ => Err(Diag::new(span, format!("{key} must be true or false")).help("use a boolean")),
    }
}

fn plain_json(v: &Value, span: Span, what: &str) -> Result<Json> {
    fn validate(v: &Value, span: Span, what: &str) -> Result<()> {
        match v {
            Value::Null | Value::Undef | Value::Bool(_) | Value::Num(_) | Value::Str(_) => Ok(()),
            Value::Array(a) => a.borrow().iter().try_for_each(|v| validate(v, span, what)),
            Value::Object(o) => o.borrow().values().try_for_each(|v| validate(v, span, what)),
            Value::Env(_) => Err(
                Diag::new(span, "env() is only allowed in webhooks/signature or uploads")
                    .help("move the environment value to a webhook URL/secret or action signature"),
            ),
            _ => {
                Err(Diag::new(span, format!("{what} must be static data")).help("remove functions and runtime values"))
            }
        }
    }
    validate(v, span, what)?;
    to_json(v).ok_or_else(|| {
        Diag::new(span, format!("{what} must be static data")).help("remove functions and runtime values")
    })
}

fn keys(o: &IndexMap<String, Value>, allowed: &[&str], span: Span, what: &str) -> Result<()> {
    for k in o.keys() {
        if !allowed.contains(&k.as_str()) {
            return Err(
                Diag::new(span, format!("{what} has no option `{k}`")).help(format!("use {}", allowed.join(", ")))
            );
        }
    }
    Ok(())
}

fn string(v: Option<&Value>, span: Span, what: &str) -> Result<String> {
    match v {
        Some(Value::Str(s)) if !s.is_empty() => Ok(s.to_string()),
        _ => Err(Diag::new(span, format!("{what} must be a nonempty string")).help("supply a string value")),
    }
}

fn positive_int(v: Option<&Value>, span: Span, what: &str) -> Result<u64> {
    match v {
        Some(Value::Num(n)) if n.is_finite() && *n > 0.0 && n.fract() == 0.0 && *n <= 9_007_199_254_740_991.0 => {
            Ok(*n as u64)
        }
        _ => Err(Diag::new(span, format!("{what} must be a positive integer"))
            .help("supply a whole number greater than zero")),
    }
}

fn env_value(v: Option<&Value>, span: Span) -> Result<Json> {
    match v {
        Some(Value::Env(n)) => Ok(serde_json::json!({"env": n.as_ref()})),
        _ => Err(Diag::new(span, "secret must be env(\"NAME\")").help("keep secrets in the runtime environment")),
    }
}

fn signature_config(v: &Value, span: Span) -> Result<Json> {
    let o = object(v, span, "signature")?;
    keys(&o, &["secret", "header", "scheme"], span, "signature")?;
    let secret = env_value(o.get("secret"), span)?;
    let header = string(o.get("header"), span, "signature.header")?;
    if !regex::Regex::new(r"^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")
        .unwrap()
        .is_match(&header)
    {
        return Err(Diag::new(span, "signature.header must be an HTTP header name")
            .help("use stripe-signature or zero-signature"));
    }
    let scheme = string(o.get("scheme"), span, "signature.scheme")?;
    if scheme != "zero" && scheme != "hmac-sha256" {
        return Err(
            Diag::new(span, "signature.scheme must be zero or hmac-sha256").help("choose a supported HMAC scheme")
        );
    }
    Ok(serde_json::json!({"secret": secret, "header": header, "scheme": scheme}))
}

fn phase2_config(cfg: &IndexMap<String, Value>, ev: &Evaluator<'_>, span: Span, out: &mut Extracted) -> Result<()> {
    if let Some(v) = cfg.get("uploads").filter(|v| !matches!(v, Value::Undef)) {
        let o = object(v, span, "uploads")?;
        let backend = string(o.get("backend"), span, "uploads.backend")?;
        let mut config = serde_json::Map::new();
        config.insert("backend".into(), backend.clone().into());
        match backend.as_str() {
            "local" => keys(&o, &["backend"], span, "local uploads")?,
            "s3" => {
                keys(
                    &o,
                    &[
                        "backend",
                        "bucket",
                        "region",
                        "endpoint",
                        "pathStyle",
                        "prefix",
                        "credentials",
                    ],
                    span,
                    "S3 uploads",
                )?;
                for key in ["bucket", "region", "endpoint", "prefix"] {
                    if let Some(v) = o.get(key) {
                        let value = match v {
                            Value::Env(name) => serde_json::json!({"env": name.as_ref()}),
                            _ => string(Some(v), span, &format!("uploads.{key}"))?.into(),
                        };
                        config.insert(key.into(), value);
                    } else if key == "bucket" {
                        return Err(Diag::new(span, "S3 uploads need a bucket")
                            .help("configure the physical provider bucket once"));
                    }
                }
                config.insert("pathStyle".into(), bool_option(&o, "pathStyle", false, span)?.into());
                let creds = o.get("credentials").ok_or_else(|| Diag::new(span, "S3 uploads need runtime credentials").help("use credentials: { accessKeyId: env(\"S3_ACCESS_KEY_ID\"), secretAccessKey: env(\"S3_SECRET_ACCESS_KEY\") }"))?;
                let creds = object(creds, span, "uploads.credentials")?;
                keys(
                    &creds,
                    &["accessKeyId", "secretAccessKey", "sessionToken"],
                    span,
                    "uploads.credentials",
                )?;
                let mut credentials = serde_json::Map::new();
                for key in ["accessKeyId", "secretAccessKey", "sessionToken"] {
                    if key != "sessionToken" || creds.contains_key(key) {
                        credentials.insert(key.into(), env_value(creds.get(key), span)?);
                    }
                }
                config.insert("credentials".into(), credentials.into());
            }
            _ => {
                return Err(Diag::new(span, "uploads.backend must be local or s3")
                    .help("choose the durable upload backend explicitly"));
            }
        }
        out.schema.uploads = Some(config.into());
    }
    if let Some(v) = cfg.get("locales").filter(|v| !matches!(v, Value::Undef)) {
        let o = object(v, span, "locales")?;
        keys(&o, &["default", "all", "fallback"], span, "locales")?;
        let default = string(o.get("default"), span, "locales.default")?;
        let Some(Value::Array(all)) = o.get("all") else {
            return Err(Diag::new(span, "locales.all must be an array").help("list the supported locales"));
        };
        let mut locales = Vec::new();
        let valid = regex::Regex::new(r"^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$").unwrap();
        for l in all.borrow().iter() {
            let l = string(Some(l), span, "locale")?;
            if !valid.is_match(&l) || locales.contains(&l) {
                return Err(Diag::new(span, format!("invalid or duplicate locale {l:?}"))
                    .help("use distinct locale tags such as en, de, or pt-BR"));
            }
            locales.push(l);
        }
        if !locales.contains(&default) {
            return Err(
                Diag::new(span, "locales.default must appear in all").help("add the default locale to locales.all")
            );
        }
        out.schema.locales = Some(
            serde_json::json!({"default": default, "all": locales, "fallback": bool_option(&o, "fallback", true, span)?}),
        );
    }
    if let Some(v) = cfg.get("media").filter(|v| !matches!(v, Value::Undef)) {
        let o = object(v, span, "media")?;
        keys(&o, &["sizes"], span, "media")?;
        let mut sizes = serde_json::Map::new();
        if let Some(v) = o.get("sizes").filter(|v| !matches!(v, Value::Undef)) {
            let definitions = object(v, span, "media.sizes")?;
            if definitions.len() > 12 {
                return Err(Diag::new(span, "media supports at most 12 sizes").help("remove unused sizes"));
            }
            let valid_name = regex::Regex::new(r"^[a-z][a-z0-9_]*$").unwrap();
            for (name, v) in definitions {
                if !valid_name.is_match(&name) {
                    return Err(Diag::new(span, format!("invalid media size name {name:?}"))
                        .help("use lowercase letters, digits, and underscores, starting with a letter"));
                }
                let s = object(&v, span, "media size")?;
                keys(&s, &["width", "height", "fit"], span, "media size")?;
                let width = positive_int(s.get("width"), span, "size.width")?;
                let fit = match s.get("fit") {
                    None | Some(Value::Undef) => "inside".into(),
                    v => string(v, span, "size.fit")?,
                };
                if fit != "inside" && fit != "cover" {
                    return Err(Diag::new(span, "fit must be cover or inside").help("choose a supported fit"));
                }
                let height = match s.get("height") {
                    None | Some(Value::Undef) if fit == "inside" => None,
                    None | Some(Value::Undef) => {
                        return Err(Diag::new(span, "fit: cover requires height").help("set height or use fit: inside"));
                    }
                    v => Some(positive_int(v, span, "size.height")?),
                };
                sizes.insert(name, serde_json::json!({"width": width, "height": height, "fit": fit}));
            }
        }
        out.schema.media = serde_json::json!({"sizes": sizes});
    }
    if let Some(v) = cfg.get("files").filter(|v| !matches!(v, Value::Undef)) {
        let valid_mime = regex::Regex::new(r"^[A-Za-z0-9!#$&^_.+-]+/(\*|[A-Za-z0-9!#$&^_.+-]+)$").unwrap();
        for (name, value) in object(v, span, "files")? {
            let Value::FileBucket(bucket) = value else {
                return Err(Diag::new(span, "files entries must use files()")
                    .help("write files(\"decks\", { accept: [\"application/pdf\"], maxBytes: 20000000 })"));
            };
            let sp = bucket.span;
            if name != bucket.name || !valid_ident(&name) || name == "media" {
                return Err(Diag::new(sp, "file bucket name must match its key and cannot be media")
                    .help("use a distinct bucket name, such as decks"));
            }
            let o = object(&bucket.options, sp, "files options")?;
            keys(&o, &["accept", "maxBytes", "access"], sp, "files")?;
            let Some(Value::Array(accept)) = o.get("accept") else {
                return Err(Diag::new(sp, "files.accept must be a MIME type array").help("use [\"application/pdf\"]"));
            };
            let mut mime = Vec::new();
            for v in accept.borrow().iter() {
                let s = string(Some(v), sp, "accept entry")?;
                if !valid_mime.is_match(&s) {
                    return Err(Diag::new(sp, "accept entries must be type/subtype or type/*")
                        .help("use application/pdf or image/*"));
                }
                mime.push(s);
            }
            if mime.is_empty() {
                return Err(Diag::new(sp, "files.accept needs at least one MIME type").help("list accepted types"));
            }
            let max_bytes = positive_int(o.get("maxBytes"), sp, "files.maxBytes")?;
            let mut access = IndexMap::from([
                ("read".into(), Access::Owner),
                ("create".into(), Access::Role("authenticated".into())),
                ("delete".into(), Access::Owner),
            ]);
            if let Some(v) = o.get("access").filter(|v| !matches!(v, Value::Undef)) {
                for (op, rule) in object(v, sp, "files.access")? {
                    if !["read", "create", "delete"].contains(&op.as_str()) {
                        return Err(Diag::new(sp, "files access supports read/create/delete")
                            .help("remove the unsupported operation"));
                    }
                    access.insert(
                        op.clone(),
                        access_rule(
                            &rule,
                            ev.key_span(v, &op).unwrap_or(sp),
                            &Owner::FileBucket(name.clone()),
                            &op,
                            format!("files__{name}__access__{op}"),
                            out,
                        )?,
                    );
                }
            }
            out.schema.files.insert(
                name,
                FileBucket {
                    accept: mime,
                    max_bytes,
                    access,
                },
            );
        }
    }
    Ok(())
}

fn sync_config(pattern: &str, span: Span) -> Result<Json> {
    let (dir, format) = if let Some(d) = pattern.strip_suffix("/*.md") {
        (d, "md")
    } else if let Some(d) = pattern.strip_suffix("/*.json") {
        (d, "json")
    } else {
        return Err(Diag::new(span, "sync must end in /*.md or /*.json").help("use content/posts/*.md"));
    };
    if dir.is_empty()
        || dir.starts_with('/')
        || dir.contains(['\\', ':', '*'])
        || dir.split('/').any(|p| p == ".." || p.is_empty())
    {
        return Err(Diag::new(span, "sync directory must stay inside the project")
            .help("use a project-relative directory without .. or wildcards"));
    }
    let normalized = dir.split('/').filter(|p| *p != ".").collect::<Vec<_>>().join("/");
    Ok(serde_json::json!({"dir": if normalized.is_empty() { "." } else { &normalized }, "format": format}))
}

fn has_localized(c: &Column) -> bool {
    c.translated()
}

/// `nested`: a sub-field or global condition, which only SCF's browser-side evaluation can settle; SCF has no
/// equality test for date, timestamp, rich text or media fields.
fn check_show_if(filter: &Json, siblings: &IndexMap<String, Column>, nested: bool, span: Span) -> Result<()> {
    let invalid = |message: String| {
        Diag::new(span, message)
            .help("compare a scalar sibling with eq/ne/in/isNull/isNotNull; combine filters with top-level OR")
    };
    let Some(map) = filter.as_object() else {
        return Err(invalid("showIf must be a sibling filter object".into()));
    };
    for (name, test) in map {
        if name == "OR" {
            let Some(items) = test.as_array().filter(|v| !v.is_empty()) else {
                return Err(invalid("showIf OR must be a nonempty filter array".into()));
            };
            for f in items {
                check_show_if(f, siblings, nested, span)?;
            }
            continue;
        }
        let Some(col) = siblings.get(name) else {
            return Err(invalid(format!("showIf names non-sibling {name:?}")));
        };
        if col.ty.is_json_kind() || col.ty.is_list_kind() {
            return Err(invalid("compare a scalar sibling".into()));
        }
        if let Some(ops) = test.as_object() {
            if ops.is_empty() {
                return Err(invalid("showIf operator object cannot be empty".into()));
            }
            for (op, value) in ops {
                let valid = match op.as_str() {
                    "eq" | "ne" => value.is_null() || value.is_string() || value.is_boolean() || value.is_number(),
                    "in" => value.as_array().is_some_and(|a| {
                        !a.is_empty()
                            && a.iter()
                                .all(|v| v.is_null() || v.is_string() || v.is_boolean() || v.is_number())
                    }),
                    "isNull" | "isNotNull" => value == &Json::Bool(true),
                    _ => false,
                };
                if !valid {
                    return Err(invalid(format!("invalid showIf operator or operand {op:?}")));
                }
            }
        } else if test.is_array() {
            return Err(invalid("showIf needs a scalar or operator object".into()));
        }
        let tests_null = match test.as_object() {
            Some(ops) => ops.iter().any(|(op, v)| {
                matches!(op.as_str(), "isNull" | "isNotNull")
                    || v.is_null()
                    || (op == "in" && v.as_array().is_some_and(|a| a.iter().any(Json::is_null)))
            }),
            None => test.is_null(),
        };
        if col.ty == ColType::Boolean && tests_null {
            return Err(invalid(format!(
                "the editor shows {name:?} as a checkbox, which is never empty; compare it with true or false"
            )));
        }
        let compares_value = match test.as_object() {
            Some(ops) => ops
                .iter()
                .any(|(op, v)| matches!(op.as_str(), "eq" | "ne" | "in") && !v.is_null()),
            None => !test.is_null(),
        };
        if nested
            && compares_value
            && matches!(
                col.ty,
                ColType::Date | ColType::Timestamp | ColType::RichText | ColType::Media
            )
        {
            return Err(invalid(format!(
                "the editor can't compare the value of {name:?} inside a group, repeater or blocks; test isNull/isNotNull"
            )));
        }
    }
    Ok(())
}

fn field_conditions(
    fields: &IndexMap<String, Column>,
    builders: &IndexMap<String, Rc<ColBuilder>>,
    nested: bool,
) -> Result<()> {
    for name in builders.keys() {
        let col = &fields[name];
        if let Some(f) = &col.show_if {
            check_show_if(f, fields, nested, builders[name].span_of("showIf"))?;
        }
        if let Some(fs) = &col.fields {
            field_conditions(fs, builders[name].fields.as_ref().unwrap(), true)?;
        }
        if let Some(ls) = &col.layouts {
            for (layout, fs) in ls {
                field_conditions(fs, &builders[name].layouts.as_ref().unwrap()[layout], true)?;
            }
        }
    }
    Ok(())
}

fn system_column(name: &str, ty: ColType) -> Column {
    let mut c = Column::new(name, ty);
    c.system = true;
    c
}

fn phase2_collections(ev: &Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    let mut auth_owner = None;
    let mut dirs = std::collections::HashSet::new();
    for b in &out.collections {
        let c = out.schema.collections.get_mut(b.table()).unwrap();
        for name in ["locale", "translations", "translationOf", "translationGroup"] {
            if let Some(col) = b.columns.get(name) {
                return Err(
                    Diag::new(col.span, format!("{name} is reserved for localization")).help("rename the column")
                );
            }
        }
        if let Some(t) = &c.title
            && (!c.columns[t].access.is_empty() || c.columns[t].show_if.is_some())
        {
            return Err(Diag::new(
                b.columns[t].span_of(if c.columns[t].show_if.is_some() {
                    "showIf"
                } else {
                    "access"
                }),
                "title is always readable",
            )
            .help("remove access/showIf from the title designation"));
        }
        if let Some(sync) = &c.sync {
            if c.auth {
                return Err(Diag::new(b.span, "user projections cannot sync").help("remove sync"));
            }
            if sync["format"] == "md" && c.content.is_none() {
                return Err(Diag::new(b.span, "Markdown sync needs a content designation")
                    .help("add content: richText() or use *.json"));
            }
            let fs = &ev.program().fs;
            let project = ev.program().modules[ev.program().entry].path.parent().unwrap();
            let mut resolved = project.to_path_buf();
            for component in sync["dir"].as_str().unwrap().split('/') {
                resolved.push(component);
                if fs.exists(&resolved) {
                    resolved = fs.canonicalize(&resolved).map_err(|_| {
                        Diag::new(b.span, "sync directory cannot be resolved")
                            .help("repair the directory or its symlink")
                    })?;
                    if !resolved.starts_with(project) || !fs.is_dir(&resolved) {
                        return Err(Diag::new(b.span, "sync directory must stay inside the project")
                            .help("point sync at a directory inside this project"));
                    }
                }
            }
            if !dirs.insert(resolved) {
                return Err(Diag::new(b.span, "two collections sync into the same directory")
                    .help("use one directory per collection"));
            }
        }
        if c.auth {
            if auth_owner.replace(c.table.clone()).is_some() {
                return Err(
                    Diag::new(b.span, "at most one userProjection() is allowed").help("keep one members collection")
                );
            }
            if c.primary_key()
                .is_none_or(|pk| pk.name != "id" || pk.ty != ColType::Integer)
            {
                return Err(Diag::new(b.span, "userProjection() needs the primary key id: id()")
                    .help("members are users: their row id is the user id"));
            }
            if !c
                .columns
                .get("email")
                .is_some_and(|c| c.ty == ColType::Email && c.not_null && c.unique)
            {
                return Err(
                    Diag::new(b.span, "userProjection() needs email: email().notNull().unique()")
                        .help("add the required unique email column with .max(191)"),
                );
            }
            if c.slug.is_some() || c.localized {
                return Err(
                    Diag::new(b.span, "user projections cannot have slug() or localized fields")
                        .help("keep translated content in a separate collection"),
                );
            }
            for name in ["password", "role", "passwordHash", "suspendedAt", "guest"] {
                if b.columns.contains_key(name) {
                    return Err(
                        Diag::new(b.columns[name].span, format!("{name} is reserved on user projections"))
                            .help("remove this column; auth owns it"),
                    );
                }
            }
            let mut role = system_column("role", ColType::Text);
            role.enum_values = Some(ROLES.iter().map(|r| (*r).into()).collect());
            role.not_null = true;
            role.default = Some(DefaultKind::Value(Json::String("subscriber".into())));
            role.access.insert("update".into(), Access::Role("admin".into()));
            c.columns.insert("role".into(), role);
            for (name, ty) in [
                ("passwordHash", ColType::Text),
                ("suspendedAt", ColType::Timestamp),
                ("guest", ColType::Boolean),
            ] {
                let mut col = system_column(name, ty);
                col.hidden = true;
                c.columns.insert(name.into(), col);
            }
        }
        if c.uid {
            if b.columns.contains_key("uid") {
                return Err(
                    Diag::new(b.columns["uid"].span, "uid is a system column").help("remove the authored uid column")
                );
            }
            let mut uid = system_column("uid", ColType::Text);
            uid.unique = true;
            uid.rules.pattern = Some(("^zr_[0-9a-f]{20}$".into(), "".into()));
            c.columns.insert("uid".into(), uid);
        }
        if c.localized {
            let locales = out.schema.locales.as_ref().unwrap();
            let mut locale = system_column("locale", ColType::Text);
            locale.not_null = true;
            locale.enum_values = Some(
                locales["all"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|v| v.as_str().unwrap().into())
                    .collect(),
            );
            locale.default = Some(DefaultKind::Value(locales["default"].clone()));
            c.columns.insert("locale".into(), locale);
            let mut translations = system_column("translations", ColType::Json);
            translations.derived = true;
            c.columns.insert("translations".into(), translations);
            if !c.editor {
                let mut group = system_column("translationGroup", ColType::Text);
                group.hidden = true;
                c.columns.insert("translationGroup".into(), group);
            }
        }
    }
    for b in &out.collections {
        field_conditions(&out.schema.collections[b.table()].columns, &b.columns, false)?;
    }
    for c in out.schema.collections.values().filter(|c| c.sync.is_some()) {
        // Sync pages records by an increasing integer key and creates rows without one (§5.3).
        if let Some(pk) = c.primary_key()
            && !(pk.name == "id" && pk.ty == ColType::Integer && pk.auto_increment)
        {
            return Err(Diag::new(
                out.collections.iter().find(|b| b.table() == c.table).unwrap().columns[&pk.name].span,
                format!("\"{}\" syncs with files, so its primary key must be id()", c.table),
            )
            .help("use `id: id()`; the uid is the record's identity across sites and files"));
        }
        for col in c.columns.values() {
            if let Some(r) = &col.references
                && !out.schema.collections[&r.collection].uid
            {
                return Err(Diag::new(
                    out.collections.iter().find(|b| b.table() == c.table).unwrap().columns[&col.name]
                        .span_of("references"),
                    format!("sync references need uid: true on {}", r.collection),
                )
                .help(format!("add uid: true to {} so references sync by uid", r.collection)));
            }
        }
    }
    Ok(())
}

fn phase2_jobs(cfg: &IndexMap<String, Value>, ev: &mut Evaluator<'_>, span: Span, out: &mut Extracted) -> Result<()> {
    let Some(v) = cfg.get("jobs").filter(|v| !matches!(v, Value::Undef)) else {
        return Ok(());
    };
    for (name, value) in object(v, span, "jobs")? {
        let Value::Job(j) = value else {
            return Err(Diag::new(span, "jobs entries must use job()").help("write job({ input, handler })"));
        };
        if !valid_ident(&name) {
            return Err(Diag::new(j.span, "invalid job name").help("use an identifier"));
        }
        let o = object(&j.config, j.span, "job")?;
        keys(&o, &["input", "handler", "retries", "agents"], j.span, "job")?;
        let retries = match o.get("retries") {
            None | Some(Value::Undef) => 3,
            Some(Value::Num(n)) if *n >= 0.0 && *n <= 20.0 && n.fract() == 0.0 => *n as u32,
            _ => {
                return Err(Diag::new(j.span, "job retries must be a whole number from 0 to 20").help("use retries: 3"));
            }
        };
        let agents = bool_option(&o, "agents", false, j.span)?;
        let Some(Value::Closure(handler)) = o.get("handler") else {
            return Err(
                Diag::new(j.span, "job needs a handler function").help("add handler: async (input, ctx) => { ... }")
            );
        };
        let input_val = o
            .get("input")
            .ok_or_else(|| Diag::new(j.span, "job needs input").help("use input: {} when there are no inputs"))?;
        let mut input = IndexMap::new();
        for (field, value) in object(input_val, j.span, "job input")? {
            let Value::Column(b) = value else {
                return Err(Diag::new(j.span, "job input needs columns").help("use text(), integer(), group(), ..."));
            };
            input.insert(
                field.clone(),
                column(&field, &b, &Owner::Job(name.clone()), &[], Place::Input, ev, out)?,
            );
        }
        out.roots.push(Root {
            closure: handler.clone(),
            slot: Slot::JobHandler { job: name.clone() },
            name: format!("job__{name}"),
        });
        out.schema.jobs.insert(
            name,
            Job {
                input,
                handler: String::new(),
                retries,
                agents,
            },
        );
    }
    Ok(())
}

fn kebab(name: &str) -> String {
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

fn validate_phase2(cfg: &IndexMap<String, Value>, ev: &Evaluator<'_>, span: Span, out: &mut Extracted) -> Result<()> {
    if let Some(v) = cfg.get("globals") {
        for (name, value) in object(v, span, "globals")? {
            if let Value::Global(g) = value {
                field_conditions(&out.schema.globals[&name].fields, &g.fields, true)?;
            }
        }
    }
    if let Some(v) = cfg.get("webhooks").filter(|v| !matches!(v, Value::Undef)) {
        let Value::Array(items) = v else {
            return Err(Diag::new(span, "webhooks must be an array").help("use [{ url, secret, events }]"));
        };
        for v in items.borrow().iter() {
            let o = object(v, span, "webhook")?;
            keys(&o, &["url", "secret", "events"], span, "webhook")?;
            let secret = env_value(o.get("secret"), span)?;
            let url = match o.get("url") {
                Some(Value::Env(n)) => serde_json::json!({"env": n.as_ref()}),
                Some(Value::Str(s)) if s.starts_with("https://") || s.starts_with("http://") => {
                    Json::String(s.to_string())
                }
                _ => {
                    return Err(
                        Diag::new(span, "webhook.url must be an HTTP(S) URL or env()").help("use env(\"HOOK_URL\")")
                    );
                }
            };
            let Some(Value::Array(events)) = o.get("events") else {
                return Err(Diag::new(span, "webhook.events must be an array").help("use [\"posts.created\"]"));
            };
            let mut normalized = Vec::new();
            for event in events.borrow().iter() {
                let event = string(Some(event), span, "webhook event")?;
                if event != "*" {
                    let valid = event.rsplit_once('.').is_some_and(|(c, op)| {
                        out.schema.collections.contains_key(c) && ["created", "updated", "deleted"].contains(&op)
                    });
                    if !valid {
                        return Err(Diag::new(
                            ev.key_span(v, "events").unwrap_or(span),
                            format!("webhook event names an unknown collection or event: {event}"),
                        )
                        .help("use <collection>.created/updated/deleted or *"));
                    }
                }
                normalized.push(event);
            }
            out.schema
                .webhooks
                .push(serde_json::json!({"url": url, "secret": secret, "events": normalized}));
        }
    }
    let mut abilities = std::collections::HashSet::new();
    let mut add = |name: String| -> Result<()> {
        if !abilities.insert(name.clone()) {
            return Err(Diag::new(span, format!("ability kebab collision: {name}"))
                .help("rename the colliding collection, action, global, or job"));
        }
        Ok(())
    };
    for c in out.schema.collections.values() {
        for op in ["list", "get", "create", "update", "delete", "revisions"] {
            add(format!("{}-{op}", kebab(&c.table)))?;
        }
        if c.sync.is_some() {
            for op in ["read", "apply", "trash"] {
                add(format!("{}-sync-{op}", kebab(&c.table)))?;
            }
        }
    }
    for a in out.schema.actions.keys() {
        add(kebab(a))?;
    }
    for g in out.schema.globals.keys() {
        for op in ["get", "update"] {
            add(format!("global-{}-{op}", kebab(g)))?;
        }
    }
    for j in out.schema.jobs.keys() {
        add(format!("job-{}", kebab(j)))?;
    }
    for op in ["list", "get", "delete"] {
        add(format!("media-{op}"))?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::{is_date, is_iso_timestamp};

    #[test]
    fn literal_dates_and_timestamps_follow_the_runtime_codec() {
        assert!(is_date("2024-02-29"));
        assert!(!is_date("2023-02-29"));
        for ok in [
            "2024-02-29T23:59:59.123456789012Z",
            "2026-01-31T09:00+05:30",
            "2026-01-31",
            "0000-02-29T00:00:00Z",
            "2026-01-31T09:00:00-00:30",
            "9999-12-31T23:29:59-00:30",
            "0000-01-01T00:30:00+00:30",
        ] {
            assert!(is_iso_timestamp(ok), "{ok}");
        }
        for bad in [
            "now",
            "+1 week",
            "10/30/2026",
            "2026-02-30T00:00:00Z",
            "2026-01-01T24:00:00Z",
            "2026-01-01T10:00:00",
            "2026-01-01T10:00:00+0100",
            "2026-01-01T00:00Z00:00",
            "9999-12-31T23:59:59-00:30",
            "0000-01-01T00:00:00+00:30",
            "9999-12-31T00:00-",
            "2026-01-01T00:00+05",
        ] {
            assert!(!is_iso_timestamp(bad), "{bad}");
        }
    }
}
