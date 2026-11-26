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
use std::collections::HashMap;
use std::rc::Rc;

/// Where a compiled function's PHP name goes once it's known.
#[derive(Debug, Clone)]
pub enum Slot {
    Hook {
        table: String,
        kind: HookKind,
    },
    Default {
        at: ColPath,
    },
    OnUpdate {
        at: ColPath,
    },
    Validate {
        at: ColPath,
    },
    ActionHandler {
        action: String,
    },
    Access {
        owner: Owner,
        op: String,
    },
    FieldAccess {
        at: ColPath,
        op: String,
    },
    JobHandler {
        job: String,
    },
    /// A `wp-hooks/` handler; `index` is its place in `Schema::wp_hooks`.
    WpHook {
        index: usize,
    },
    /// A server component exported from `sp/components/`; `name` keys `Schema::components`.
    Component {
        name: String,
    },
    /// The default export of an `emails/` template, by its app-relative path.
    Email {
        file: String,
    },
    /// The default export of a `sp/app/` route file (page, layout, not-found, error): a component
    /// whose props the router supplies, typed from the route.
    AppPage {
        file: String,
    },
    /// `export const metadata = (props) => ({ … })` in a `sp/app/` page or layout.
    AppMetadata {
        file: String,
    },
    /// `export const guard = (ctx) => boolean` for a `sp/app/` file's HTTP handlers.
    AppAccess {
        file: String,
    },
    /// `export const guard = (ctx) => boolean` in a `sp/app/` layout.
    AppGuard {
        file: String,
    },
    /// `export async function staticParams()` in a dynamic `sp/app/` page: the params to prebuild.
    AppStaticParams {
        file: String,
    },
    /// `export async function POST(req, ctx)` in a `sp/app/` page or `route.ts`.
    AppHandler {
        file: String,
        method: String,
    },
}

#[derive(Debug, Clone)]
pub struct Root {
    pub closure: Rc<Closure>,
    pub slot: Slot,
    /// Suggested PHP function name.
    pub name: String,
    /// Index into `Extracted::plugins` when a plugin registered this function.
    pub plugin: Option<usize>,
}

pub struct Extracted {
    pub schema: Schema,
    pub roots: Vec<Root>,
    /// Schema-key → collection value, for resolving captured collections.
    pub collections: Vec<Rc<CollectionVal>>,
    /// Non-fatal advice printed by `spacepress build` (§3.5.1 postmeta hint).
    pub hints: Vec<String>,
    /// Each registered action's value (its allocation) → its registered name, so a page's
    /// `<form action={subscribe}>` and `useFormState(subscribe)` name the action they were given.
    pub action_values: HashMap<usize, String>,
    /// Each registered job's value → its registered name, so `notifyLowStock.enqueue(...)` names it.
    pub job_values: HashMap<usize, String>,
    /// Each registered `adminWidget()`'s value (its allocation) → its kebab-case widget id.
    pub widget_values: HashMap<usize, String>,
    pub plugins: Option<crate::wp_plugins::Config>,
    /// `defineConfig({ use })`: plugin packages, in registration order.
    pub uses: Vec<crate::plugins::Plugin>,
    /// `ref()` fields, for their relations once every kind is extracted.
    refs: Vec<RefField>,
}

/// One `ref()` field: its kind (storage name), name, target, nullability, the reverse name it asks
/// for, and where it was written.
struct RefField {
    table: String,
    field: String,
    target: String,
    optional: bool,
    reverse: Option<String>,
    /// `.many()`: a list through the field's join table.
    many: bool,
    /// `terms()`: the list is core's term relationships, not a join table.
    terms: bool,
    span: Span,
}

/// What `defineConfig` takes. None of these registers a named thing: kinds, globals, file buckets,
/// actions and jobs register by their exports (ruling 36).
const CONFIG_KEYS: &[&str] = &[
    "name",
    "dir",
    "relations",
    "webhooks",
    "media",
    "uploads",
    "locales",
    "plugins",
    "admin",
    "publicAuthors",
    "xmlrpc",
    "pings",
    "sealed",
    "use",
    "frontend",
    "output",
    "runtimeSchema",
    "seed",
    "retention",
];
/// `wp.posts.extend(fields, options)`: what may change about WordPress's own posts.
const POST_OPTIONS: &[&str] = &[
    "url",
    "comments",
    "access",
    "label",
    "ui",
    "sync",
    "uid",
    "agents",
    "api",
    "staged",
    "softDelete",
    "retention",
];
/// `wp.posts.clone(fields, options)`: the same, and its own stored name.
const POST_CLONE_OPTIONS: &[&str] = &[
    "url",
    "comments",
    "access",
    "label",
    "ui",
    "sync",
    "uid",
    "agents",
    "api",
    "staged",
    "storageName",
    "softDelete",
    "retention",
];

fn post_call(post: PostKind) -> &'static str {
    match post {
        PostKind::Native => "wp.posts.extend()",
        PostKind::Clone => "wp.posts.clone()",
    }
}

const COLLECTION_OPTIONS: &[&str] = &[
    "storageName",
    "existingTable",
    "was",
    "label",
    "ui",
    "drafts",
    "revisions",
    "softDelete",
    "retention",
    "staged",
    "comments",
    "title",
    "content",
    "access",
    "api",
    "sync",
    "uid",
    "agents",
    "url",
    "hierarchical",
];
/// Registries populated by the app's named exports.
const REGISTRIES: &[&str] = &[
    "stores",
    "products",
    "productCategories",
    "productTags",
    "orders",
    "collections",
    "tables",
    "users",
    "taxonomies",
    "globals",
    "files",
    "actions",
    "jobs",
    "screens",
    "widgets",
    "dashboards",
];
/// Collection names that would shadow a built-in route (§4.8).
const RESERVED_COLLECTIONS: &[&str] = &[
    "media", "files", "globals", "actions", "me", "_schema", "auth", "services", "search",
];
/// WordPress limits post types to 20 characters; SpacePress prefixes `sp_`.
const MAX_POST_TYPE_TABLE: usize = 20 - "sp_".len();
/// WordPress limits taxonomy names to 32 characters; SpacePress prefixes `sp_`.
const MAX_TAXONOMY_TABLE: usize = 32 - "sp_".len();
/// `taxonomy()` options (docs/decisions/2026-11-11-authoring-surface.md, rulings 25-34).
const TAXONOMY_OPTIONS: &[&str] = &[
    "storageName",
    "label",
    "access",
    "hierarchical",
    "url",
    "on",
    "translations",
    "sync",
    "agents",
];
/// A term row's own fields (ruling 27), and the names no authored term field may take.
const TERM_FIELDS: &[&str] = &[
    "id",
    "name",
    "slug",
    "description",
    "parent",
    "count",
    "url",
    "author",
    "createdAt",
    "updatedAt",
];
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
        if !CONFIG_KEYS.contains(&key.as_str()) && !REGISTRIES.contains(&key.as_str()) {
            let mut d = Diag::new(
                span_of(ev, config, key, config_span),
                format!("defineConfig() has no option `{key}`"),
            );
            if key == "routes" {
                d = d.help(
                    "an HTTP route is a file now: sp/app/<path>/route.ts exporting `guard` (or `signature`) and GET, POST, … handlers",
                );
            } else if let Some(s) = suggest(key, CONFIG_KEYS.iter().copied()) {
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
        action_values: HashMap::new(),
        job_values: HashMap::new(),
        widget_values: HashMap::new(),
        refs: Vec::new(),
        plugins: None,
        uses: Vec::new(),
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
            .help("it becomes the WordPress plugin slug `spacepress-<name>`"));
        }
    };
    if let Some(dir_val) = cfg_map.get("dir")
        && !matches!(dir_val, Value::Undef)
    {
        let dir_span = span_of(ev, config, "dir", config_span);
        match dir_val {
            Value::Str(s) => {
                crate::layout::validate_dir(s, dir_span)?;
            }
            other => {
                return Err(Diag::new(
                    dir_span,
                    format!(
                        "`dir` must be a relative directory string like \"sp\" or \"cms\", not {}",
                        other.describe()
                    ),
                ));
            }
        }
    }
    out.schema.seed = bool_option(&cfg_map, "seed", false, span_of(ev, config, "seed", config_span))?;
    out.schema.admin = bool_option(&cfg_map, "admin", true, span_of(ev, config, "admin", config_span))?;
    out.schema.public_authors = bool_option(
        &cfg_map,
        "publicAuthors",
        false,
        span_of(ev, config, "publicAuthors", config_span),
    )?;
    out.schema.xmlrpc = bool_option(&cfg_map, "xmlrpc", true, span_of(ev, config, "xmlrpc", config_span))?;
    out.schema.pings = bool_option(&cfg_map, "pings", true, span_of(ev, config, "pings", config_span))?;
    out.schema.retention = extract_retention(
        cfg_map.get("retention"),
        ev,
        span_of(ev, config, "retention", config_span),
    )?;
    let runtime_schema = span_of(ev, config, "runtimeSchema", config_span);
    out.schema.runtime_schema = bool_option(&cfg_map, "runtimeSchema", false, runtime_schema)?.then(Default::default);
    out.schema.frontend = match cfg_map.get("frontend") {
        None | Some(Value::Undef) => None,
        Some(Value::Bool(false)) => Some(crate::kind_url::Frontend::Off),
        Some(Value::Str(url)) => Some(crate::kind_url::Frontend::External(
            crate::kind_url::frontend_url(url)
                .map_err(|e| Diag::new(span_of(ev, config, "frontend", config_span), e.message).help(e.help))?,
        )),
        Some(other) => {
            return Err(Diag::new(
                span_of(ev, config, "frontend", config_span),
                format!(
                    "`frontend` is `false` or the external site's URL, not {}",
                    other.describe()
                ),
            )
            .help("leave it out for the default: the theme, or sp/app when it exists"));
        }
    };
    out.schema.output = match cfg_map.get("output") {
        None | Some(Value::Undef) => crate::schema::Output::Server,
        Some(Value::Str(s)) if s.as_ref() == "server" => crate::schema::Output::Server,
        Some(Value::Str(s)) if s.as_ref() == "static" => crate::schema::Output::Static,
        Some(other) => {
            return Err(Diag::new(
                span_of(ev, config, "output", config_span),
                format!("`output` is \"server\" or \"static\", not {}", other.describe()),
            )
            .help("leave it out for \"server\": pages render on WordPress, and `export const prerender = true` prebuilds one; \"static\" prebuilds every page for `spacepress export`"));
        }
    };
    if out.schema.output == crate::schema::Output::Static && out.schema.frontend.is_some() {
        return Err(Diag::new(
            span_of(ev, config, "output", config_span),
            "`output: \"static\"` renders the site from sp/app/, and `frontend` hands it to another site",
        )
        .help("keep one: remove `frontend` to export sp/app/, or remove `output` to keep the other frontend"));
    }
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
    // After `plugins`: a sealed site runs the plugins it declares, by their directory slug.
    if bool_option(&cfg_map, "sealed", false, span_of(ev, config, "sealed", config_span))? {
        let mut declared: Vec<String> = out
            .plugins
            .iter()
            .flat_map(|config| config.plugins.iter().map(|plugin| plugin.slug.clone()))
            .collect();
        declared.sort();
        if let Some(denied) = declared
            .iter()
            .find(|slug| crate::bundle::SEALED_DENIED.contains(&slug.as_str()))
        {
            return Err(Diag::new(
                span_of(ev, config, "plugins", config_span),
                format!(
                    "a sealed site can't run {denied}: it serves stored content around SpacePress's read rules. Remove it from plugins, or turn sealed mode off."
                ),
            ));
        }
        out.schema.sealed = Some(declared);
    }

    for registry in ["collections", "tables", "users", "taxonomies"] {
        if let Some((_, span)) = ev.duplicate_key(config, Some(registry)) {
            return Err(Diag::new(span, format!("duplicate `{registry}` registry")));
        }
    }

    // Bind every identity before evaluating columns or reference closures. The shared schema
    // remains the runtime/migration model, so relations do not depend on storage kind.
    for kind in [
        StorageKind::Collection,
        StorageKind::Table,
        StorageKind::UserProjection,
        StorageKind::Taxonomy,
        StorageKind::Product,
        StorageKind::ProductCategory,
        StorageKind::ProductTag,
        StorageKind::Order,
    ] {
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
                    format!("\"{canonical}\" is reserved: SpacePress serves a built-in route with that name"),
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
                            .help("use the existing physical name without the WordPress or sp_ prefix"),
                    );
                }
            };
            if RESERVED_COLLECTIONS
                .iter()
                .any(|reserved| reserved.eq_ignore_ascii_case(&table))
            {
                return Err(Diag::new(
                    c.span,
                    format!("\"{table}\" is reserved: SpacePress serves a built-in route with that name"),
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
            if kind == StorageKind::Taxonomy && table.len() > MAX_TAXONOMY_TABLE {
                return Err(Diag::new(
                    c.span,
                    format!(
                        "\"{table}\" is too long for a taxonomy: WordPress limits taxonomy names to 32 characters and SpacePress prefixes `sp_`"
                    ),
                )
                .help(format!(
                    "use a name of at most {MAX_TAXONOMY_TABLE} characters, or give it a shorter `storageName`"
                )));
            }
            // WordPress's posts are one kind, and its name is theirs.
            if c.post == Some(PostKind::Native) && key != "posts" {
                return Err(Diag::new(c.span, format!("WordPress's posts are exported as `posts`, not `{key}`"))
                    .help("write `export const posts = wp.posts.extend({ ... }, { url })`; for another kind like posts, use `wp.posts.clone(...)`"));
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
                    was: None,
                    label: title_case(c.table()),
                    editor: true,
                    drafts: true,
                    revisions: true,
                    soft_delete: false,
                    retention: None,
                    staged: true,
                    comments: false,
                    comment_policy: None,
                    title: None,
                    content: None,
                    slug: None,
                    excerpt: None,
                    native: false,
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
                    url: None,
                    hierarchical: false,
                    taxonomy: kind.term().then(|| Taxonomy { on: Vec::new() }),
                    commerce: kind.commerce().then(|| kind.builder().to_string()),
                    indexes: Vec::new(),
                    ui: None,
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
    crate::commerce::store(&cfg_map, &mut out.schema, config_span)?;
    let mut url_shapes: Vec<(String, Vec<crate::kind_url::Segment>, Span)> = Vec::new();
    let attached = taxonomy_on(ev, &out)?;
    for c in out.collections.clone() {
        if c.kind.commerce() {
            crate::commerce::kind(&c, &mut out.schema)?;
            if c.kind == StorageKind::Product {
                for (name, builder) in c.columns.iter().filter(|(name, _)| name.as_str() != "id") {
                    if out.schema.collections[c.table()].columns.contains_key(name)
                        || name == "stockQuantity"
                        || matches!(name.as_str(), "categories" | "tags")
                    {
                        return Err(Diag::new(builder.span, format!("product() already defines `{name}`")));
                    }
                    let mut col = lock_column(name, builder, false, c.table(), ev, &mut out)?;
                    if !matches!(
                        col.ty,
                        ColType::Text
                            | ColType::Integer
                            | ColType::Real
                            | ColType::Boolean
                            | ColType::Date
                            | ColType::Email
                            | ColType::Url
                    ) || col.not_null
                        || col.unique
                        || col.references.is_some()
                        || col.was.is_some()
                        || col.localized
                        || col.on_update.is_some()
                        || col.default.is_some()
                    {
                        return Err(Diag::new(
                            builder.span,
                            "product() fields must be optional scalar metadata without defaults, references, or storage modifiers",
                        ));
                    }
                    col.commerce_meta = true;
                    out.schema
                        .collections
                        .get_mut(c.table())
                        .unwrap()
                        .columns
                        .insert(name.clone(), col);
                }
            }
            let kind = &out.schema.collections[c.table()];
            if let Some(pattern) = &kind.url {
                let shape = crate::kind_url::check(
                    pattern,
                    &crate::kind_url::UrlKind {
                        columns: &kind.columns,
                        dated: false,
                        hierarchical: kind.hierarchical,
                        public_slug: &|_| false,
                    },
                )
                .map_err(|e| Diag::new(c.span, e.message))?;
                let root = |s: &[crate::kind_url::Segment]| matches!(s, [crate::kind_url::Segment::Path]);
                if let Some((other, ..)) = url_shapes
                    .iter()
                    .find(|(_, s, _)| crate::kind_url::overlaps(s, &shape) && root(s) == root(&shape))
                {
                    return Err(Diag::new(
                        c.span,
                        format!("\"{pattern}\" can match the same URLs as {other}'s url"),
                    ));
                }
                url_shapes.push((format!("`{}`", kind.key), shape, c.span));
            }
            continue;
        }
        if c.kind == StorageKind::Taxonomy {
            taxonomy_kind(&c, ev, &mut out, &mut url_shapes)?;
            continue;
        }
        let owner = Owner::Collection(c.table().to_string());
        let mut columns = IndexMap::new();
        for (name, b) in &c.columns {
            let col = column(name, b, &owner, &[], Place::Top, ev, &mut out)?;
            if b.terms && c.kind != StorageKind::Collection {
                return Err(Diag::new(
                    b.span,
                    format!("`terms()` isn't available on a {}() kind (`{name}`)", c.kind.builder()),
                )
                .help("core's term relationships carry no object type, so terms attach to collection() kinds only; keep a plain field or a table of links instead"));
            }
            if b.terms
                && let Some((other, _)) = columns.iter().find(|(_, o): &(&String, &Column)| {
                    o.ty == ColType::RefList
                        && o.references.as_ref().map(|r| &r.join) == col.references.as_ref().map(|r| &r.join)
                })
            {
                return Err(Diag::new(
                    b.span,
                    format!("`{name}` and `{other}` are both `terms()` of the same taxonomy"),
                )
                .help("WordPress keeps one term set per item and taxonomy; keep one of the two fields"));
            }
            if col.storage_name.is_some() && c.kind != StorageKind::Table {
                return Err(Diag::new(
                    b.span_of("column"),
                    "`.column()` maps a physical SQL column on a table()",
                )
                .help("remove `.column()` on non-table kinds, or use `.alias()` for API field aliasing"));
            }
            if let Some((other, _)) = columns
                .iter()
                .find(|(_, o): &(&String, &Column)| o.physical_name() == col.physical_name())
            {
                return Err(Diag::new(
                    b.span_of("column"),
                    format!(
                        "`{name}` and `{other}` both map to physical column `{}`",
                        col.physical_name()
                    ),
                )
                .help("give each field a distinct physical `.column(...)` name"));
            }
            for alias in &col.aliases {
                if columns.contains_key(alias) || columns.values().any(|o| o.aliases.iter().any(|a| a == alias)) {
                    return Err(Diag::new(
                        b.span_of("alias"),
                        format!("alias `{alias}` on `{name}` collides with another field or alias"),
                    )
                    .help("choose a unique alias name"));
                }
            }
            if columns.values().any(|o| o.aliases.iter().any(|a| a == name)) {
                return Err(Diag::new(
                    b.span,
                    format!("field `{name}` collides with an `.alias(\"{name}\")` on another field"),
                )
                .help("rename the field or remove the conflicting alias"));
            }
            columns.insert(name.clone(), col);
        }
        // A taxonomy's `on` adds a terms field named after it, unless the kind declares `terms()` for it.
        for (field, taxonomy, span) in attached.get(c.table()).into_iter().flatten() {
            let join = term_join(taxonomy);
            if columns
                .values()
                .any(|o| o.references.as_ref().and_then(|r| r.join.as_ref()) == Some(&join))
            {
                continue;
            }
            if columns.contains_key(field) {
                return Err(Diag::new(
                    *span,
                    format!(
                        "`{field}`'s `on` gives `{}` a terms field named `{field}`, which is already a field of `{}`",
                        c.table(),
                        c.table()
                    ),
                )
                .help(format!(
                    "rename the field, or declare the terms field yourself under another name: `{}: terms({field})`",
                    crate::schema::physical_name(field)
                )));
            }
            if c.kind != StorageKind::Collection {
                unreachable!("taxonomy_on admits collections only");
            }
            columns.insert(field.clone(), terms_column(field, taxonomy));
            out.refs.push(RefField {
                table: c.table().to_string(),
                field: field.clone(),
                target: taxonomy.clone(),
                optional: false,
                reverse: None,
                many: true,
                terms: true,
                span: *span,
            });
        }
        let opts = collection_options(&c, &columns, ev, &mut out)?;
        let coll = out.schema.collections.get_mut(c.table()).expect("collection");
        coll.columns = columns;
        coll.existing_table = opts.existing_table;
        coll.was = opts.was;
        coll.label = opts.label;
        coll.ui = opts.ui;
        coll.editor = opts.editor;
        coll.drafts = opts.drafts;
        coll.revisions = opts.revisions;
        coll.soft_delete = opts.soft_delete;
        coll.retention = opts.retention;
        coll.staged = opts.staged;
        coll.comments = opts.comment_policy.is_some();
        coll.comment_policy = opts.comment_policy;
        coll.title = opts.title;
        coll.content = opts.content;
        coll.slug = opts.slug;
        coll.excerpt = c.post.map(|_| "excerpt".to_string());
        coll.native = c.post == Some(PostKind::Native);
        coll.access = opts.access;
        coll.api = opts.api;
        coll.uid = opts.uid;
        coll.sync = opts.sync;
        coll.hierarchical = opts.hierarchical;
        coll.indexes = c.indexes.borrow().iter().map(|(f, u, _)| (f.clone(), *u)).collect();
        coll.auth = opts.auth;
        coll.agents = opts.agents;
        let key = coll.key.clone();
        if let Some((pattern, shape, span)) = opts.url {
            // A kind at the site root (`/:path`) takes only the URLs no other pattern matches, so it
            // overlaps no other kind; two of them would.
            let root = |s: &[crate::kind_url::Segment]| matches!(s, [crate::kind_url::Segment::Path]);
            if let Some((other, ..)) = url_shapes
                .iter()
                .find(|(_, s, _)| crate::kind_url::overlaps(s, &shape) && root(s) == root(&shape))
            {
                return Err(
                    Diag::new(span, format!("\"{pattern}\" can match the same URLs as {other}'s url"))
                        .help("give one of them a different literal segment, e.g. \"/blog/:slug\" and \"/docs/:slug\""),
                );
            }
            url_shapes.push((format!("`{key}`"), shape, span));
            coll.url = Some(pattern);
        }
        coll.localized = coll.columns.values().any(has_localized);
        if coll.hierarchical {
            // A row's parent is another row of the same kind (or none); its order sorts siblings.
            let mut parent = Column::new("parent", ColType::Integer);
            parent.references = Some(crate::schema::Reference {
                collection: coll.table.clone(),
                column: "id".into(),
                on_delete: Some(crate::schema::RefAction::SetNull),
                on_update: None,
                join: None,
            });
            parent.system = true;
            let mut order = Column::new("order", ColType::Integer);
            order.not_null = true;
            order.default = Some(DefaultKind::Value(Json::from(0)));
            order.system = true;
            coll.columns.insert("parent".into(), parent);
            coll.columns.insert("order".into(), order);
            coll.relations.insert(
                "parent".into(),
                crate::schema::Relation {
                    name: "parent".into(),
                    kind: crate::schema::RelKind::One,
                    collection: coll.table.clone(),
                    from: "parent".into(),
                    to: "id".into(),
                    optional: true,
                    through: None,
                },
            );
            coll.relations.insert(
                "children".into(),
                crate::schema::Relation {
                    name: "children".into(),
                    kind: crate::schema::RelKind::Many,
                    collection: coll.table.clone(),
                    from: "id".into(),
                    to: "parent".into(),
                    optional: false,
                    through: None,
                },
            );
        }
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
        // Automatic system fields on every collection() and table() (except adopted existingTable bindings).
        if !coll.auth && coll.existing_table.is_none() {
            if !coll.columns.contains_key("author") {
                let mut author = Column::new("author", ColType::Integer);
                author.system = true;
                author.read_only = true;
                coll.columns.insert("author".into(), author);
            }
            if !coll.columns.contains_key("createdAt") {
                let mut created_at = Column::new("createdAt", ColType::Timestamp);
                created_at.not_null = true;
                created_at.default = Some(DefaultKind::Now);
                created_at.system = true;
                created_at.read_only = true;
                coll.columns.insert("createdAt".into(), created_at);
            }
            if !coll.columns.contains_key("updatedAt") {
                let mut updated_at = Column::new("updatedAt", ColType::Timestamp);
                updated_at.not_null = true;
                updated_at.default = Some(DefaultKind::Now);
                updated_at.on_update = Some(DefaultKind::Now);
                updated_at.system = true;
                updated_at.read_only = true;
                coll.columns.insert("updatedAt".into(), updated_at);
            }
        }
        if coll.soft_delete {
            let mut deleted_at = Column::new("deletedAt", ColType::Timestamp);
            deleted_at.system = true;
            deleted_at.read_only = true;
            let mut deleted_by = Column::new("deletedBy", ColType::Integer);
            deleted_by.system = true;
            deleted_by.read_only = true;
            let mut deleted_group = Column::new("deletedGroup", ColType::Text);
            deleted_group.rules.max = Some(36.0);
            deleted_group.system = true;
            deleted_group.read_only = true;
            deleted_group.hidden = true;
            coll.columns.insert("deletedAt".into(), deleted_at);
            coll.columns.insert("deletedBy".into(), deleted_by);
            coll.columns.insert("deletedGroup".into(), deleted_group);
            if !coll.editor {
                let mut live = Column::new("_live", ColType::Boolean);
                live.default = Some(DefaultKind::Value(Json::Bool(true)));
                live.system = true;
                live.read_only = true;
                live.hidden = true;
                coll.columns.insert("_live".into(), live);
            }
        }
        // Validate resolved physical column names (ASCII case-insensitively) after system fields
        // are added so user `.column(...)` overrides and case-differing fields (`meta`/`META`)
        // cannot silently overwrite system columns or emit duplicate SQL columns.
        let mut seen_phys: std::collections::HashMap<String, (String, bool)> = std::collections::HashMap::new();
        for (col_name, col) in &coll.columns {
            let phys = col.physical_name();
            let lower = phys.to_ascii_lowercase();
            if let Some((other, other_sys)) = seen_phys.get(&lower) {
                let msg = if *other_sys || col.system {
                    format!(
                        "`{}`.{col_name} maps to physical column `{phys}`, which collides with system column `{other}`",
                        coll.table
                    )
                } else {
                    format!(
                        "`{}`.{col_name} and `{}`.{other} both map to SQL column `{phys}` (SQL column names are case-insensitive)",
                        coll.table, coll.table
                    )
                };
                return Err(Diag::new(Span::NONE, msg)
                    .help("give the field a distinct name or `.column(...)` override")
                    .code(crate::diag::Code::Schema));
            }
            seen_phys.insert(lower, (col_name.clone(), col.system));
        }
    }
    // Validate resolved SQL physical table names: forbid reserved system table namespaces (`sp__*`)
    // and case-insensitive collisions across collections.
    let mut seen_tables: std::collections::HashMap<String, String> = std::collections::HashMap::new();
    for coll in out.schema.collections.values() {
        if coll.editor || coll.taxonomy.is_some() || coll.auth || coll.existing_table.is_some() {
            continue;
        }
        let storage = coll.table.as_str();
        let lower_storage = storage.to_ascii_lowercase();
        if lower_storage == "sp__revisions" || lower_storage == "sp__migrations" || lower_storage.starts_with("sp__") {
            return Err(Diag::new(
                Span::NONE,
                format!("`{}` uses reserved system table name `{storage}`", coll.key),
            )
            .help("choose a `storageName` outside the `sp__*` system namespace")
            .code(crate::diag::Code::Schema));
        }
        if let Some(other) = seen_tables.get(&lower_storage) {
            return Err(Diag::new(
                Span::NONE,
                format!(
                    "`{}` and `{other}` both map to SQL table `sp_{storage}` (table names are case-insensitive)",
                    coll.key
                ),
            )
            .help("give each table a distinct name or `storageName`")
            .code(crate::diag::Code::Schema));
        }
        seen_tables.insert(lower_storage, coll.key.clone());
    }
    crate::commerce::relations(&mut out.schema);
    // Each taxonomy registers for the union of its `on` and every kind with a `terms()` field for it.
    let attachments: Vec<(String, String)> = out
        .schema
        .collections
        .values()
        .flat_map(|c| {
            c.columns.values().filter_map(|col| {
                let r = col.references.as_ref()?;
                is_term_join(r.join.as_deref()?).then(|| (r.collection.clone(), c.table.clone()))
            })
        })
        .collect();
    for (taxonomy, kind) in attachments {
        let on = &mut out
            .schema
            .collections
            .get_mut(&taxonomy)
            .expect("taxonomy")
            .taxonomy
            .as_mut()
            .expect("taxonomy")
            .on;
        if !on.contains(&kind) {
            on.push(kind);
        }
    }
    phase2_collections(ev, &mut out)?;
    postmeta_hints(&mut out);

    // Each `ref()` field and its reverse list.
    ref_relations(&mut out)?;
    if cfg_map.contains_key("relations") {
        let span = span_of(ev, config, "relations", config_span);
        return Err(Diag::new(span, "`relations` is gone: a reference is a field").help(
                "delete `relations` and declare `location: ref(locations)` on the referencing kind; `with: { location: true }` loads the row, and the target gets the reverse list named after the referencing kind",
            ));
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

    // `.on({ ... })` on each registered kind, in call order (spec §2.8, ruling 18).
    for c in out.collections.clone() {
        let calls = c.hooks.borrow().clone();
        for (handlers, span) in calls {
            if c.kind == StorageKind::Taxonomy {
                let key = out
                    .schema
                    .collections
                    .values()
                    .find(|k| k.table == c.table())
                    .map_or_else(|| c.table().to_string(), |k| k.key.clone());
                return Err(
                    Diag::new(span, format!("hooks on the taxonomy `{key}` aren't built yet"))
                        .help("run the logic from a hook of the kind its terms attach to, or from an action"),
                );
            }
            kind_hooks(c.table(), &handlers, span, ev, &mut out, None)?;
        }
    }
    if let Some(actions_val) = cfg_map.get("actions") {
        actions(
            actions_val,
            span_of(ev, config, "actions", config_span),
            ev,
            &mut out,
            None,
        )?;
    }
    phase2_jobs(&cfg_map, ev, config_span, &mut out)?;
    if let Some(v) = cfg_map.get("use").filter(|v| !matches!(v, Value::Undef)) {
        uses(v, span_of(ev, config, "use", config_span), ev, &mut out)?;
    }
    validate_phase2(&cfg_map, ev, config_span, &mut out)?;
    Ok(out)
}

/// Admin screens, widgets and dashboards. They name actions, the app's and its plugins', and read
/// their inputs, so they are read once those inputs are known (`Compiler::infer_inputs`).
pub fn screens(config: &Value, ev: &mut Evaluator<'_>, config_span: Span, out: &mut Extracted) -> Result<()> {
    let Value::Object(cfg) = config else { return Ok(()) };
    let v = cfg.borrow().get("screens").cloned();
    if let Some(v) = v.filter(|v| !matches!(v, Value::Undef)) {
        crate::admin_screen::screens(&v, ev.key_span(config, "screens").unwrap_or(config_span), ev, out)?;
    }
    let w = cfg.borrow().get("widgets").cloned();
    if let Some(w) = w.filter(|v| !matches!(v, Value::Undef)) {
        crate::admin_dashboard::widgets(&w, ev.key_span(config, "widgets").unwrap_or(config_span), ev, out)?;
    }
    let d = cfg.borrow().get("dashboards").cloned();
    if let Some(d) = d.filter(|v| !matches!(v, Value::Undef)) {
        crate::admin_dashboard::dashboards(&d, ev.key_span(config, "dashboards").unwrap_or(config_span), ev, out)?;
    }
    Ok(())
}

/// `hooks.<collectionKey>.<hook>`, from the app or from a plugin (`plugin`: index and name).
fn hooks(
    hooks_val: &Value,
    hspan: Span,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    let Value::Object(hooks) = hooks_val else {
        return Err(Diag::new(hspan, "`hooks` must be an object keyed by collection"));
    };
    for (key, per) in hooks.borrow().iter() {
        let kspan = ev.key_span(hooks_val, key).unwrap_or(hspan);
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
        if coll.taxonomy.is_some() {
            return Err(
                Diag::new(kspan, format!("hooks on the taxonomy `{key}` aren't built yet"))
                    .help("run the logic from a hook of the kind its terms attach to, or from an action"),
            );
        }
        let table = coll.table.clone();
        let Value::Object(_) = per else {
            return Err(Diag::new(
                kspan,
                format!("`hooks.{key}` must be an object of hook functions"),
            ));
        };
        kind_hooks(&table, per, kspan, ev, out, plugin)?;
    }
    Ok(())
}

/// One kind's hooks, from `hooks.<kind>` or a `.on({ ... })` call: each handler becomes a hook root,
/// and several for one event run in registration order (plugins first, then the app).
fn kind_hooks(
    table: &str,
    per: &Value,
    kspan: Span,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    let table = table.to_string();
    let Value::Object(per_map) = per else { unreachable!() };
    {
        for (hname, f) in per_map.borrow().iter() {
            let fspan = ev.key_span(per, hname).unwrap_or(kspan);
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
            if kind == HookKind::AfterPublish && !out.schema.collections[&table].drafts {
                return Err(Diag::new(
                    fspan,
                    "`afterPublish` is for collections: a table() row is never published",
                )
                .help("use afterCreate or afterUpdate on a table()"));
            }
            if kind == HookKind::BeforeCreate {
                let coll = out.schema.collections.get_mut(&table).expect("collection");
                // Plugin hooks run before the app's; any of them may fill a required column.
                for k in assigned_keys(f) {
                    if !coll.before_create_fills.contains(&k)
                        && coll
                            .columns
                            .get(&k)
                            .is_some_and(|c| c.not_null && c.default.is_none() && !c.auto_increment)
                    {
                        coll.before_create_fills.push(k);
                    }
                }
            }
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::Hook {
                    table: table.clone(),
                    kind,
                },
                name: format!("{table}__{hname}"),
                plugin: plugin.map(|(i, _)| i),
            });
        }
    }
    Ok(())
}

/// `actions.<name>`; a plugin's are registered as `<plugin>_<name>`.
fn actions(
    actions_val: &Value,
    aspan: Span,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    let Value::Object(actions) = actions_val else {
        return Err(Diag::new(
            aspan,
            "`actions` must be an object of query(...) and mutation(...) values",
        ));
    };
    for (name, a) in actions.borrow().iter() {
        let nspan = ev.key_span(actions_val, name).unwrap_or(aspan);
        if !valid_ident(name) {
            return Err(Diag::new(nspan, format!("\"{name}\" isn't a valid action name")));
        }
        let name = &registered(plugin, name);
        if out.schema.actions.contains_key(name) {
            return Err(Diag::new(nspan, format!("two actions are both registered as `{name}`"))
                .help("rename one; plugin actions are registered as <plugin>_<name>"));
        }
        let Value::Action(a) = a else {
            return Err(Diag::new(
                nspan,
                format!("`{name}` must be a query(...) or a mutation(...)"),
            ));
        };
        if a.form != FnForm::Object {
            typed_action(name, a, ev, out, plugin)?;
            continue;
        }
        let Value::Object(cfg) = &a.config else { unreachable!() };
        let cfg = cfg.borrow().clone();
        for k in cfg.keys() {
            if !matches!(
                k.as_str(),
                "input" | "handler" | "access" | "agents" | "idempotent" | "readonly" | "destructive" | "signature"
            ) {
                return Err(Diag::new(
                    ev.key_span(&a.config, k).unwrap_or(a.span),
                    format!("action() has no option `{k}`"),
                )
                .help("use input, handler, access, agents, idempotent, readonly, destructive, signature"));
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
                let col = column(field, b, &owner, &[], Place::Input, ev, out)?;
                input.insert(field.clone(), col);
            }
        }
        let Some(Value::Closure(handler)) = cfg.get("handler") else {
            return Err(Diag::new(a.span, format!("action `{name}` needs a `handler` function")));
        };
        let agents = bool_option(&cfg, "agents", true, a.span)?;
        let idempotent = bool_option(&cfg, "idempotent", false, a.span)?;
        let readonly = bool_option(&cfg, "readonly", false, a.span)?;
        let destructive = bool_option(&cfg, "destructive", false, a.span)?;
        if readonly && destructive {
            return Err(Diag::new(
                a.span,
                format!("action `{name}` can't be both readonly and destructive"),
            )
            .help("a readonly action only reads; drop one of the two"));
        }
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
                access_rule(v, sp, &owner, "access", format!("action__{name}__access"), out)?
            }
        };
        out.action_values.insert(Rc::as_ptr(a) as usize, name.clone());
        out.roots.push(Root {
            closure: handler.clone(),
            slot: Slot::ActionHandler { action: name.clone() },
            name: format!("action__{name}"),
            plugin: plugin.map(|(i, _)| i),
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
                readonly,
                destructive,
                signature,
                output: None,
                personal: false,
                input_ty: None,
                query: false,
                rate_limit: None,
                typed_input: false,
            },
        );
    }
    Ok(())
}

/// `query(options, handler)` / `mutation(options, handler)`. The input is the handler's first
/// parameter type, which the compiler reads before anything uses it (`Compiler::infer_inputs`).
fn typed_action(
    name: &str,
    a: &Rc<ActionVal>,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    let query = a.form == FnForm::Query;
    let what = if query { "query()" } else { "mutation()" };
    let Value::Object(cfg) = &a.config else {
        unreachable!("checked by the evaluator")
    };
    let cfg = cfg.borrow().clone();
    let at = |k: &str| ev.key_span(&a.config, k).unwrap_or(a.span);
    let allowed: &[&str] = if query {
        &["access", "agents", "rateLimit"]
    } else {
        &[
            "access",
            "agents",
            "idempotent",
            "destructive",
            "signature",
            "rateLimit",
        ]
    };
    for k in cfg.keys() {
        if allowed.contains(&k.as_str()) {
            continue;
        }
        let d = Diag::new(at(k), format!("{what} has no option `{k}`"));
        return Err(match k.as_str() {
            "input" => d.help(
                "the input is the handler's parameter type: `async ({ id }: { id: number }) => ...`; defaults go in the destructuring, `({ limit = 10 }: { limit?: number })`",
            ),
            "handler" => d.help(format!("pass the handler after the options: `{}({{ ... }}, async (input) => ...)`", &what[..what.len() - 2])),
            "readonly" if !query => d.help("a function that only reads is a query(): it is marked read-only for agents and may not write"),
            "readonly" => d.help("a query is always read-only; remove the option"),
            "idempotent" | "destructive" | "signature" if query => {
                d.help("a query only reads, so it is idempotent and not destructive; a signed request is a mutation()")
            }
            _ => d.help(format!("use {}", allowed.join(", "))),
        });
    }
    let Value::Closure(handler) = &a.handler else {
        unreachable!("checked by the evaluator")
    };
    let owner = Owner::Action(name.to_string());
    let agents = bool_option(&cfg, "agents", true, at("agents"))?;
    let idempotent = bool_option(&cfg, "idempotent", false, at("idempotent"))?;
    let destructive = bool_option(&cfg, "destructive", false, at("destructive"))?;
    let signature = match cfg.get("signature") {
        None | Some(Value::Undef) => None,
        Some(v) => {
            if cfg.contains_key("access") {
                return Err(Diag::new(at("signature"), "signature cannot be combined with access")
                    .help("remove access; signature authenticates the caller"));
            }
            Some(signature_config(v, at("signature"))?)
        }
    };
    let anyone = matches!(cfg.get("access"), Some(Value::Str(s)) if s.as_ref() == "anyone");
    let access = match cfg.get("access") {
        None | Some(Value::Undef) => Access::editor(),
        Some(v) => access_rule(
            v,
            at("access"),
            &owner,
            "access",
            format!("action__{name}__access"),
            out,
        )?,
    };
    let rate_limit = match cfg.get("rateLimit") {
        // A mutation anyone may call is limited unless it says otherwise (a signed one is not
        // called by visitors).
        None | Some(Value::Undef) if !query && anyone && signature.is_none() => Some(DEFAULT_RATE_LIMIT),
        None | Some(Value::Undef) | Some(Value::Bool(false)) => None,
        Some(Value::Str(spec)) => Some(rate_limit(spec, at("rateLimit"))?),
        Some(other) => {
            return Err(Diag::new(
                at("rateLimit"),
                format!("rateLimit is a string like \"10/min\", not {}", other.describe()),
            )
            .help("write `rateLimit: \"10/min\"` (per second, min, hour or day), or `rateLimit: false` for none"));
        }
    };
    out.action_values.insert(Rc::as_ptr(a) as usize, name.to_string());
    out.roots.push(Root {
        closure: handler.clone(),
        slot: Slot::ActionHandler {
            action: name.to_string(),
        },
        name: format!("action__{name}"),
        plugin: plugin.map(|(i, _)| i),
    });
    out.schema.actions.insert(
        name.to_string(),
        Action {
            name: name.to_string(),
            input: IndexMap::new(),
            handler: String::new(),
            access,
            agents,
            idempotent,
            readonly: query,
            destructive,
            signature,
            output: None,
            personal: false,
            input_ty: None,
            query,
            rate_limit,
            typed_input: true,
        },
    );
    Ok(())
}

/// What a mutation open to `"anyone"` is limited to when it names no `rateLimit`.
pub const DEFAULT_RATE_LIMIT: RateLimit = RateLimit { count: 30, window: 60 };

/// `"10/min"`: a count per second, minute, hour or day.
fn rate_limit(spec: &str, span: Span) -> Result<RateLimit> {
    let bad = || {
        Diag::new(span, format!("rateLimit \"{spec}\" isn't a count per period"))
            .help("write `rateLimit: \"10/min\"`: a whole number, a slash, then sec, min, hour or day")
    };
    let (count, unit) = spec.split_once('/').ok_or_else(bad)?;
    let count: u32 = count.trim().parse().map_err(|_| bad())?;
    let window = match unit.trim() {
        "s" | "sec" | "second" => 1,
        "m" | "min" | "minute" => 60,
        "h" | "hour" => 3600,
        "d" | "day" => 86_400,
        _ => return Err(bad()),
    };
    if count == 0 {
        return Err(Diag::new(span, "rateLimit allows at least one call per period")
            .help("to refuse every call, use access; to remove the limit, `rateLimit: false`"));
    }
    Ok(RateLimit { count, window })
}

/// `every: "1h"`: seconds, at least a minute (Action Scheduler runs once a minute at most).
fn every(spec: &str, span: Span) -> Result<u64> {
    let bad = || {
        Diag::new(span, format!("every \"{spec}\" isn't a duration"))
            .help("write a whole number and a unit: \"30m\", \"1h\", \"1d\" (s, m, h, d or w)")
    };
    let digits = spec.trim_end_matches(|c: char| c.is_ascii_alphabetic());
    let n: u64 = digits.parse().map_err(|_| bad())?;
    let seconds = match &spec[digits.len()..] {
        "s" => n,
        "m" => n * 60,
        "h" => n * 3600,
        "d" => n * 86_400,
        "w" => n * 604_800,
        _ => return Err(bad()),
    };
    if seconds < 60 {
        return Err(Diag::new(span, format!("every \"{spec}\" is under a minute"))
            .help("Action Scheduler runs due work about once a minute; use \"1m\" or longer"));
    }
    Ok(seconds)
}

/// A five-field cron expression (minute hour day-of-month month day-of-week), or `@daily` and its
/// siblings, as Action Scheduler's `as_schedule_cron_action` reads it. Times are UTC.
fn cron_schedule(spec: &str, span: Span) -> Result<String> {
    let spec = spec.trim();
    if matches!(
        spec,
        "@yearly" | "@annually" | "@monthly" | "@weekly" | "@daily" | "@hourly"
    ) {
        return Ok(spec.to_string());
    }
    let fields: Vec<&str> = spec.split_whitespace().collect();
    let bad = || {
        Diag::new(span, format!("\"{spec}\" isn't a cron schedule"))
            .help("write five fields, minute hour day month weekday, in UTC: \"0 3 * * *\" runs at 03:00 every day; each is `*`, a number, a range `1-5`, a list `1,15` or a step `*/10`")
    };
    if fields.len() != 5 {
        return Err(bad());
    }
    // minute, hour, day of month, month, day of week (0 and 7 are Sunday), with the names Action
    // Scheduler's cron parser reads.
    const RANGES: [(u32, u32); 5] = [(0, 59), (0, 23), (1, 31), (1, 12), (0, 7)];
    const MONTHS: [&str; 12] = [
        "jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec",
    ];
    const DAYS: [&str; 7] = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];
    for (i, field) in fields.iter().enumerate() {
        let (lo, hi) = RANGES[i];
        let value = |v: &str| -> Option<u32> {
            let lower = v.to_ascii_lowercase();
            let named = match i {
                3 => MONTHS.iter().position(|m| *m == lower).map(|p| p as u32 + 1),
                4 => DAYS.iter().position(|d| *d == lower).map(|p| p as u32),
                _ => None,
            };
            named.or_else(|| v.parse().ok()).filter(|n| (lo..=hi).contains(n))
        };
        for part in field.split(',') {
            let (range, step) = match part.split_once('/') {
                Some((r, s)) => (r, Some(s)),
                None => (part, None),
            };
            if let Some(step) = step
                && !step.parse::<u32>().is_ok_and(|s| (1..=hi.max(1)).contains(&s))
            {
                return Err(bad());
            }
            let ok = range == "*"
                || match range.split_once('-') {
                    Some((a, b)) => matches!((value(a), value(b)), (Some(a), Some(b)) if a <= b),
                    None => value(range).is_some(),
                };
            if !ok {
                return Err(bad());
            }
        }
    }
    Ok(fields.join(" "))
}

/// The registered name of a plugin's action, job or route.
fn registered(plugin: Option<(usize, &str)>, name: &str) -> String {
    plugin.map_or_else(|| name.to_string(), |(_, p)| format!("{p}_{name}"))
}

/// `defineConfig({ use: [plugin(), ...] })`: each `definePlugin` value, its package, and everything
/// it registers. Every root extracted for a plugin (access rules and input defaults included)
/// belongs to it.
fn uses(v: &Value, span: Span, ev: &mut Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    let Value::Array(items) = v else {
        return Err(Diag::new(span, "`use` must be a list of plugins").help("write use: [seo(), forms()]"));
    };
    for item in items.borrow().iter() {
        let Value::Plugin(p) = item else {
            return Err(Diag::new(span, format!("`use` lists plugins, not {}", item.describe()))
                .help("call the package's export: use: [seo({ ... })]"));
        };
        let o = object(&p.config, p.span, "definePlugin")?;
        keys(
            &o,
            &["name", "hooks", "actions", "jobs", "migrationProvider"],
            p.span,
            "definePlugin",
        )?;
        let name = string(o.get("name"), p.span, "plugin name")?;
        if !(valid_ident(&name)
            && name
                .chars()
                .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_'))
        {
            return Err(
                Diag::new(p.span, format!("plugin name \"{name}\" must be a lowercase identifier"))
                    .help("use letters, digits and underscores, like \"seo\""),
            );
        }
        let is_builtin = p.form == FnForm::BuiltinPlugin;
        let first = out.roots.len();
        let owner = if is_builtin {
            None
        } else {
            if out.uses.iter().any(|u| u.name == name) {
                return Err(Diag::new(p.span, format!("two plugins are both named \"{name}\""))
                    .help("each plugin is registered once"));
            }
            let plugin = crate::plugins::identify(ev.program(), &name, p.span)?;
            let index = out.uses.len();
            out.uses.push(plugin);
            Some((index, name.as_str()))
        };
        if let Some(h) = o.get("hooks").filter(|v| !matches!(v, Value::Undef)) {
            hooks(h, ev.key_span(&p.config, "hooks").unwrap_or(p.span), ev, out, owner)?;
        }
        if let Some(a) = o.get("actions").filter(|v| !matches!(v, Value::Undef)) {
            actions(a, ev.key_span(&p.config, "actions").unwrap_or(p.span), ev, out, owner)?;
        }
        if let Some(j) = o.get("jobs").filter(|v| !matches!(v, Value::Undef)) {
            jobs(j, ev, p.span, out, owner)?;
        }
        if let Some(mp) = o.get("migrationProvider").filter(|v| !matches!(v, Value::Undef)) {
            let mp_span = ev.key_span(&p.config, "migrationProvider").unwrap_or(p.span);
            let mpo = object(mp, mp_span, "migrationProvider")?;
            keys(
                &mpo,
                &["id", "cliOnly", "requireArchive", "allowRawSql"],
                mp_span,
                "migrationProvider",
            )?;
            let id = string(mpo.get("id"), mp_span, "migrationProvider.id")?;
            let cli_only = match mpo.get("cliOnly") {
                Some(Value::Bool(b)) => *b,
                None | Some(Value::Undef) => true,
                Some(other) => {
                    return Err(Diag::new(
                        mp_span,
                        format!("migrationProvider.cliOnly must be boolean, not {}", other.describe()),
                    ));
                }
            };
            let require_archive = match mpo.get("requireArchive") {
                Some(Value::Bool(b)) => *b,
                None | Some(Value::Undef) => false,
                Some(other) => {
                    return Err(Diag::new(
                        mp_span,
                        format!(
                            "migrationProvider.requireArchive must be boolean, not {}",
                            other.describe()
                        ),
                    ));
                }
            };
            let allow_raw_sql = match mpo.get("allowRawSql") {
                Some(Value::Bool(b)) => *b,
                None | Some(Value::Undef) => false,
                Some(other) => {
                    return Err(Diag::new(
                        mp_span,
                        format!(
                            "migrationProvider.allowRawSql must be boolean, not {}",
                            other.describe()
                        ),
                    ));
                }
            };
            if out.schema.migration_providers.iter().any(|existing| existing.id == id) {
                return Err(Diag::new(
                    mp_span,
                    format!("migrationProvider \"{id}\" is already registered"),
                ));
            }
            out.schema
                .migration_providers
                .push(crate::schema::MigrationProviderConfig {
                    id,
                    cli_only,
                    require_archive,
                    allow_raw_sql,
                });
        }
        if let Some((index, _)) = owner {
            for root in &mut out.roots[first..] {
                root.plugin = Some(index);
            }
        }
    }
    crate::plugins::isolate(ev.program(), &mut out.uses)
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
/// One input field (an action's `input`, a page's typed `search`), checked as action inputs are.
pub fn input_column(name: &str, b: &ColBuilder, ev: &mut Evaluator<'_>, out: &mut Extracted) -> Result<Column> {
    column(name, b, &Owner::Action(String::new()), &[], Place::Input, ev, out)
}

/// A collection's or a global's own field read from the runtime schema lock
/// (`crate::runtime_schema`), checked as an authored one is.
pub fn lock_column(
    name: &str,
    b: &ColBuilder,
    global: bool,
    kind: &str,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
) -> Result<Column> {
    if global {
        return column(name, b, &Owner::Global(kind.to_string()), &[], Place::Global, ev, out);
    }
    column(name, b, &Owner::Collection(kind.to_string()), &[], Place::Top, ev, out)
}

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
    was: Option<String>,
    label: String,
    ui: Option<Json>,
    editor: bool,
    drafts: bool,
    revisions: bool,
    soft_delete: bool,
    retention: Option<Retention>,
    staged: bool,
    comment_policy: Option<crate::schema::CommentPolicy>,
    title: Option<String>,
    content: Option<String>,
    slug: Option<String>,
    access: IndexMap<String, Access>,
    api: Api,
    uid: bool,
    sync: Option<Json>,
    auth: bool,
    agents: bool,
    /// The pattern, its segments (`kind_url::check`) and where it was written.
    url: Option<(String, Vec<crate::kind_url::Segment>, Span)>,
    hierarchical: bool,
}

/// The terms fields `on` gives each kind: kind storage name → (field name, taxonomy storage name,
/// where `on` lists it).
type Attached = IndexMap<String, Vec<(String, String, Span)>>;

/// Each taxonomy's `on` (ruling 26). The field is named after the taxonomy's export.
fn taxonomy_on(ev: &Evaluator<'_>, out: &Extracted) -> Result<Attached> {
    let mut attached: Attached = IndexMap::new();
    for t in out.collections.iter().filter(|c| c.kind == StorageKind::Taxonomy) {
        let Some(opts @ Value::Object(o)) = t.options.as_ref() else {
            continue;
        };
        let Some(on) = o.borrow().get("on").cloned().filter(|v| !matches!(v, Value::Undef)) else {
            continue;
        };
        let span = ev.key_span(opts, "on").unwrap_or(t.span);
        let Value::Array(items) = &on else {
            return Err(Diag::new(
                span,
                format!("`on` must be a list of collections, not {}", on.describe()),
            )
            .help("write `on: [posts]`"));
        };
        let field = out.schema.collections[t.table()].key.clone();
        for item in items.borrow().iter() {
            let Value::Collection(kind) = item else {
                return Err(
                    Diag::new(span, format!("`on` lists {}, not a collection", item.describe()))
                        .help("list the app's collection() exports; core's posts wait for the wp.* built-ins"),
                );
            };
            if !out.collections.iter().any(|c| Rc::ptr_eq(c, kind)) {
                return Err(Diag::new(span, "`on` lists a kind that isn't exported")
                    .help("export the kind, e.g. `export const posts = collection({ ... })`"));
            }
            match kind.kind {
                StorageKind::Collection => {}
                StorageKind::Table => {
                    return Err(Diag::new(
                        span,
                        format!("`on` lists the table `{}`; terms attach to collection() kinds only", kind.table()),
                    )
                    .help("core's term relationships carry no object type, so a table row and a post with the same id would share terms; keep a plain field or a table of links instead"));
                }
                other => {
                    return Err(Diag::new(
                        span,
                        format!(
                            "`on` lists a {}() kind; terms attach to collection() kinds only",
                            other.builder()
                        ),
                    )
                    .help("list the app's collection() exports"));
                }
            }
            let entry = attached.entry(kind.table().to_string()).or_default();
            if !entry.iter().any(|(_, tax, _)| tax == t.table()) {
                entry.push((field.clone(), t.table().to_string(), span));
            }
        }
    }
    Ok(attached)
}

/// The terms field `on` adds to a kind (ruling 26): the item's term ids, `[]` when none.
fn terms_column(field: &str, taxonomy: &str) -> Column {
    let mut col = Column::new(field, ColType::RefList);
    col.not_null = true;
    col.default = Some(DefaultKind::Value(Json::Array(Vec::new())));
    col.references = Some(Reference {
        collection: taxonomy.to_string(),
        column: "id".into(),
        on_delete: Some(RefAction::Cascade),
        on_update: None,
        join: Some(term_join(taxonomy)),
    });
    col
}

/// A `taxonomy()` kind (rulings 25-34): its options, the term row's own fields and the authored
/// term meta fields.
fn taxonomy_kind(
    c: &Rc<CollectionVal>,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    url_shapes: &mut Vec<(String, Vec<crate::kind_url::Segment>, Span)>,
) -> Result<()> {
    let table = c.table().to_string();
    let key = out.schema.collections[&table].key.clone();
    let empty = Value::object(IndexMap::new());
    let opts_val = c.options.clone().unwrap_or(empty);
    let Value::Object(o) = &opts_val else { unreachable!() };
    let o = o.borrow().clone();
    let spans: IndexMap<String, Span> = o
        .keys()
        .map(|k| (k.clone(), ev.key_span(&opts_val, k).unwrap_or(c.span)))
        .collect();
    let access_val = o.get("access").cloned();
    let access_spans: IndexMap<String, Span> = match &access_val {
        Some(av @ Value::Object(am)) => am
            .borrow()
            .keys()
            .filter_map(|k| Some((k.clone(), ev.key_span(av, k)?)))
            .collect(),
        _ => IndexMap::new(),
    };
    let span = |k: &str| spans.get(k).copied().unwrap_or(c.span);
    for k in o.keys() {
        if TAXONOMY_OPTIONS.contains(&k.as_str()) {
            continue;
        }
        let (message, help) = match k.as_str() {
            "was" => (
                "`was` on a taxonomy isn't built yet: renaming one has to move its terms".to_string(),
                "keep the stored name under a new export with `storageName: \"<old name>\"`, which moves nothing"
                    .to_string(),
            ),
            "abilities" | "search" => (
                format!("`{k}` on a taxonomy isn't built yet"),
                "remove the option".to_string(),
            ),
            _ => (
                format!("taxonomy() has no option `{k}`"),
                suggest(k, TAXONOMY_OPTIONS.iter().copied()).map_or_else(
                    || format!("use one of {}", TAXONOMY_OPTIONS.join(", ")),
                    |s| format!("did you mean `{s}`?"),
                ),
            ),
        };
        return Err(Diag::new(span(k), message).help(help));
    }
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
    let hierarchical = flag("hierarchical")?.unwrap_or(false);
    match flag("translations")? {
        Some(true) => {
            return Err(
                Diag::new(span("translations"), "taxonomy translations aren't built yet")
                    .help("write `translations: false`: one vocabulary shared by every locale"),
            );
        }
        None if out.schema.locales.is_some() => {
            return Err(Diag::new(
                c.span,
                "taxonomy translations aren't built yet; add `translations: false`",
            )
            .help("with `locales` set, a taxonomy says `translations: false`: one vocabulary shared by every locale"));
        }
        _ => {}
    }
    let label = match o.get("label") {
        None | Some(Value::Undef) => title_case(&table),
        Some(Value::Str(s)) => s.to_string(),
        Some(other) => {
            return Err(Diag::new(
                span("label"),
                format!("`label` must be a string, not {}", other.describe()),
            ));
        }
    };

    // The term row's own fields (ruling 27).
    let owner = Owner::Collection(table.clone());
    let mut columns: IndexMap<String, Column> = IndexMap::new();
    let id = column("id", &c.columns["id"], &owner, &[], Place::Top, ev, out)?;
    columns.insert("id".into(), id);
    let mut name = Column::new("name", ColType::Text);
    name.not_null = true;
    columns.insert("name".into(), name);
    // Always set: core derives it from the name when a create leaves it out, unique within the
    // taxonomy, as a collection's slug derives from its `source`.
    let mut slug = Column::new("slug", ColType::Slug);
    slug.not_null = true;
    slug.source = Some("name".into());
    columns.insert("slug".into(), slug);
    let mut description = Column::new("description", ColType::Text);
    description.not_null = true;
    description.multiline = true;
    description.default = Some(DefaultKind::Value(Json::String(String::new())));
    columns.insert("description".into(), description);
    if hierarchical {
        // A term of this taxonomy, or null; core checks it and moves children up on delete.
        columns.insert("parent".into(), Column::new("parent", ColType::Integer));
    }
    let mut count = Column::new("count", ColType::Integer);
    count.not_null = true;
    count.read_only = true;
    columns.insert("count".into(), count);
    let mut url = Column::new("url", ColType::Text);
    url.read_only = true;
    columns.insert("url".into(), url);

    for (field, b) in c.columns.iter().filter(|(n, _)| *n != "id") {
        if TERM_FIELDS.contains(&field.as_str()) {
            return Err(Diag::new(b.span, format!("`{field}` is built into every term; delete this field"))
                .help("a term has id, name, slug, description, parent (hierarchical), count and url, and no author or timestamps"));
        }
        let refused = match b.ty {
            ColType::RichText => Some("richText()"),
            ColType::File => Some("file()"),
            ColType::Group => Some("group()"),
            ColType::Repeater => Some("repeater()"),
            ColType::Blocks => Some("blocks()"),
            ColType::Slug => Some("slug()"),
            ColType::MediaList => Some("media().many()"),
            ColType::RefList if b.terms => Some("terms()"),
            ColType::RefList => Some("ref().many()"),
            _ => None,
        };
        if let Some(what) = refused {
            return Err(Diag::new(b.span, format!("{what} fields on a taxonomy aren't built yet (`{field}`)"))
                .help("a term field is text, number, integer, boolean, date, timestamp, select, email, url, json, media or a single ref"));
        }
        if b.unique {
            return Err(Diag::new(
                b.span_of("unique"),
                format!("`.unique()` on a term field isn't built yet (`{field}`)"),
            )
            .help("remove `.unique()`; a term's slug is already unique within its taxonomy"));
        }
        if b.localized {
            return Err(Diag::new(
                b.span_of("localized"),
                format!("term fields aren't translated yet (`{field}`)"),
            )
            .help("remove `.localized()`; a taxonomy is one vocabulary shared by every locale"));
        }
        if b.was.is_some() {
            return Err(Diag::new(
                b.span_of("was"),
                "`.was()` renames a column of a table(); this kind stores its fields elsewhere",
            )
            .help("rename the field without `.was()` and move its values yourself, or keep the old name"));
        }
        if b.searchable.is_some() {
            return Err(Diag::new(
                b.span_of("searchable"),
                "`.searchable()` marks a field of a collection; a taxonomy's terms aren't searched",
            )
            .help("remove `.searchable()`"));
        }
        let col = column(field, b, &owner, &[], Place::Top, ev, out)?;
        columns.insert(field.clone(), col);
    }

    // url (ruling 30): `:slug`, `:id` and, hierarchical, `:path`; one rewrite rule per pattern.
    let url = match o.get("url") {
        None | Some(Value::Undef) => None,
        Some(Value::Str(pattern)) => {
            if let Some(bad) = pattern
                .split('/')
                .filter_map(|s| s.strip_prefix(':'))
                .find(|p| !matches!(*p, "slug" | "id" | "path"))
            {
                return Err(Diag::new(
                    span("url"),
                    format!("`:{bad}` in \"{pattern}\" isn't a term placeholder"),
                )
                .help("a term URL takes `:slug`, `:id` and, on a hierarchical taxonomy, `:path`"));
            }
            let never = |_: &str| false;
            let kind = crate::kind_url::UrlKind {
                columns: &columns,
                dated: false,
                hierarchical,
                public_slug: &never,
            };
            let shape =
                crate::kind_url::check(pattern, &kind).map_err(|e| Diag::new(span("url"), e.message).help(e.help))?;
            // A kind at the site root (`/:path`) takes only the URLs no other pattern matches, so a
            // taxonomy's URL never overlaps it.
            let root = |s: &[crate::kind_url::Segment]| matches!(s, [crate::kind_url::Segment::Path]);
            if let Some((other, ..)) = url_shapes
                .iter()
                .find(|(_, s, _)| !root(s) && crate::kind_url::overlaps(s, &shape))
            {
                return Err(Diag::new(
                    span("url"),
                    format!("\"{pattern}\" can match the same URLs as {other}'s url"),
                )
                .help("give one of them a different literal segment, e.g. \"/topics/:slug\" and \"/blog/:slug\""));
            }
            url_shapes.push((format!("`{key}`"), shape, span("url")));
            Some(pattern.to_string())
        }
        Some(other) => {
            return Err(Diag::new(
                span("url"),
                format!(
                    "`url` must be a pattern like \"/topics/:slug\", not {}",
                    other.describe()
                ),
            ));
        }
    };

    // access (ruling 29): the spec's defaults, the same keys as every kind.
    const TAXONOMY_ACCESS_OPS: [&str; 4] = ["read", "create", "update", "delete"];
    let mut access: IndexMap<String, Access> = TAXONOMY_ACCESS_OPS
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
            let sp = access_spans.get(op).copied().unwrap_or(span("access"));
            if !TAXONOMY_ACCESS_OPS.contains(&op.as_str()) {
                let mut d = Diag::new(sp, format!("`access` has no operation `{op}`"));
                d = match suggest(op, TAXONOMY_ACCESS_OPS.iter().copied()) {
                    Some(s) => d.help(format!("did you mean `{s}`?")),
                    None => d.help("use read, create, update, delete"),
                };
                return Err(d);
            }
            let fn_name = format!("{table}__access__{op}");
            access.insert(op.clone(), access_rule(rule, sp, &owner, op, fn_name, out)?);
        }
    }

    let coll = out.schema.collections.get_mut(&table).expect("taxonomy");
    coll.columns = columns;
    coll.label = label;
    coll.editor = false;
    coll.drafts = false;
    coll.revisions = false;
    coll.staged = false;
    coll.agents = flag("agents")?.unwrap_or(false);
    let sync = match o.get("sync") {
        None | Some(Value::Undef) => None,
        Some(Value::Str(pattern)) => Some(sync_config(pattern, span("sync"))?),
        _ => {
            return Err(Diag::new(
                span("sync"),
                "sync must be a project-relative *.md or *.json pattern",
            ));
        }
    };
    if let Some(sync) = sync {
        coll.sync = Some(sync);
        coll.uid = true;
        coll.content = Some("description".into());
        let mut uid = system_column("uid", ColType::Text);
        uid.unique = true;
        uid.rules.max = Some(23.0);
        coll.columns.insert("uid".into(), uid);
    }
    coll.access = access;
    coll.hierarchical = hierarchical;
    coll.url = url;
    Ok(())
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
    // `wp.posts.extend()` and `.clone()`: posts' own fields are built in, and storage is posts'.
    if let Some(post) = c.post {
        let call = post_call(post);
        let allowed: &[&str] = match post {
            PostKind::Native => POST_OPTIONS,
            PostKind::Clone => POST_CLONE_OPTIONS,
        };
        for k in o.keys() {
            if !allowed.contains(&k.as_str()) {
                let mut d = Diag::new(span(k), format!("{call} has no option `{k}`"));
                d = match k.as_str() {
                    "title" | "content" | "drafts" | "revisions" | "hierarchical" => {
                        d.help("posts always have their title, content, drafts and revisions, and don't nest")
                    }
                    "storageName" | "existingTable" | "was" => {
                        d.help("WordPress's posts are stored as core's `post` type; use `wp.posts.clone()` for a kind of your own")
                    }
                    _ => match suggest(k, allowed.iter().copied()) {
                        Some(s) => d.help(format!("did you mean `{s}`?")),
                        None => d.help(format!("the options are {}", allowed.join(", "))),
                    },
                };
                return Err(d);
            }
        }
        if post == PostKind::Native && matches!(o.get("url"), None | Some(Value::Undef)) {
            return Err(Diag::new(c.span, "`wp.posts.extend()` needs a `url`")
                .help("say where a post lives: `wp.posts.extend({}, { url: \"/blog/:slug\" })`"));
        }
    }
    for k in o.keys() {
        if c.post.is_none() && !COLLECTION_OPTIONS.contains(&k.as_str()) {
            let mut d = Diag::new(span(k), format!("{}() has no option `{k}`", c.kind.builder()));
            if k == "editor" {
                d = d.help(match c.kind {
                    StorageKind::Collection => {
                        "storage follows the builder: collection() is always WordPress posts; use table() for a SQL table"
                    }
                    StorageKind::Table => "storage follows the builder: table() is always a SQL table; remove the option",
                    StorageKind::UserProjection => {
                        "storage follows the builder: userProjection() is always WordPress users; remove the option"
                    }
                    _ => unreachable!("other kinds have their own options"),
                });
            } else if let Some(s) = suggest(k, COLLECTION_OPTIONS.iter().copied()) {
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
    let ui = extract_kind_ui(o.get("ui"), span("ui"), columns, ev)?;
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
    if o.get("was").is_some_and(|value| !matches!(value, Value::Undef)) {
        return Err(Diag::new(
            span("was"),
            "`was` is gone: forward-only migrations never rename SQL tables in place",
        )
        .help("keep the existing physical table under a new TypeScript export with `storageName: \"<old_name>\"`, or opt into `spacepress/danger` for an explicit physical rename"));
    }
    let was = None;
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
        Some(r) => r,
        None => !auth,
    };
    let soft_delete = match flag("softDelete")? {
        Some(true) if auth => {
            return Err(
                Diag::new(span("softDelete"), "userProjection() cannot enable softDelete")
                    .help("WordPress owns user records; remove `softDelete: true`"),
            );
        }
        Some(true) if existing_table.is_some() => {
            return Err(Diag::new(
                span("softDelete"),
                "`softDelete: true` isn't available on `existingTable` tables",
            )
            .help("remove `softDelete: true`; SpacePress does not alter adopted tables"));
        }
        Some(sd) => sd,
        None => !auth && existing_table.is_none(),
    };
    if soft_delete {
        for reserved in ["deletedAt", "deletedBy", "deletedGroup", "_live"] {
            if let Some(b) = c.columns.get(reserved) {
                return Err(Diag::new(
                    b.span,
                    format!(
                        "`{reserved}` is reserved: softDelete adds these (deletedAt, deletedBy, deletedGroup, _live)"
                    ),
                )
                .help("remove the column, or opt out with `{ softDelete: false }`"));
            }
        }
    }
    let retention = extract_retention(o.get("retention"), ev, span("retention"))?;
    // Staged versions are WordPress revisions of a post: on for collections unless turned off.
    let staged = match flag("staged")? {
        Some(true) if !editor => {
            return Err(
                Diag::new(span("staged"), "only collection() kinds have staged versions").help(
                    "a staged version is a WordPress revision of a post; a table() row has no editorial lifecycle",
                ),
            );
        }
        Some(true) if !revisions => {
            return Err(Diag::new(span("staged"), "staged versions require `revisions: true`")
                .help("remove `revisions: false`, or set `staged: false`"));
        }
        Some(on) => on,
        None => editor && revisions,
    };
    // spec.md §2.3: `comments: true | { guests?, moderation?, threaded?, closeAfterDays?, types? }`;
    // comments are off on every kind that doesn't turn them on.
    // Posts take comments unless `comments: false` (WordPress's own posts do).
    let comment_policy = match o.get("comments") {
        None | Some(Value::Undef) if c.post.is_some() => Some(crate::schema::CommentPolicy::default()),
        given => comment_policy(given, ev, span("comments"), editor)?,
    };

    if editor && c.post != Some(PostKind::Native) && c.table().len() > MAX_POST_TYPE_TABLE {
        return Err(Diag::new(
            c.span,
            format!(
                "\"{}\" is too long for an editor collection: WordPress post types are limited to 20 characters and SpacePress prefixes `sp_`",
                c.table()
            ),
        )
        .help(format!(
            "use a name of at most {MAX_POST_TYPE_TABLE} characters, or use table() for SQL data"
        )));
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
                        "the title column `{t}` must be a required text(), but it's {}",
                        describe_col(col)
                    ),
                )
                .help(format!("declare it as `{t}: text()`, without `.optional()`")));
            }
            _ => {}
        }
    } else if editor {
        return Err(
            Diag::new(c.span, format!("editor collection \"{}\" has no title", c.table()))
                .help("add a `title: text()` field or the `title:` option, or use table() for SQL data"),
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

    // hierarchical (spec §2.6): a collection whose items nest, like WordPress Pages. The compiler
    // adds `parent` and `order`; they are the post's own `post_parent` and `menu_order`.
    let hierarchical = flag("hierarchical")?.unwrap_or(false);
    if hierarchical {
        if !editor {
            return Err(
                Diag::new(span("hierarchical"), "only collection() kinds can be hierarchical")
                    .help("a table() nests through an ordinary `parent: ref(() => kind).optional()` field"),
            );
        }
        for reserved in ["parent", "order"] {
            if let Some(b) = c.columns.get(reserved) {
                return Err(Diag::new(
                    b.span,
                    format!("`{reserved}` is reserved: hierarchical kinds add `parent` and `order`"),
                )
                .help("remove the field; the kind's items already have a parent and an order"));
            }
        }
    }
    // A folder tree of files: each folder is the item its `index` file holds, the parent of the
    // files beside it. Only a hierarchical kind has parents, and only a slug names a folder.
    if sync.as_ref().is_some_and(|s| s["nested"] == true) {
        if !hierarchical {
            return Err(Diag::new(span("sync"), "a `**` sync pattern needs a hierarchical kind")
                .help("add `hierarchical: true`: each folder's index file becomes the parent of the files beside it"));
        }
        if !columns.values().any(|c| c.ty == ColType::Slug) {
            return Err(Diag::new(span("sync"), "a folder tree of files needs a slug field")
                .help("add `slug: slug({ source: \"title\" })`: a file's name is its slug, and a folder's name its index file's"));
        }
    }

    // url (spec §2.7): post kinds only; tables have no public pages.
    let url = match o.get("url") {
        None | Some(Value::Undef) => None,
        Some(Value::Str(pattern)) if editor => {
            // A collection's read rule defaults to "anyone"; only that default or the literal keeps
            // its items' slugs public. (Drafts never have a permalink, so they never reach a URL.)
            let public_slug = |table: &str| {
                out.collections.iter().any(|c| {
                    c.table() == table
                        && matches!(c.kind, StorageKind::Collection | StorageKind::Taxonomy)
                        && match c.options.as_ref() {
                            Some(Value::Object(o)) => match o.borrow().get("access") {
                                None | Some(Value::Undef) => true,
                                Some(Value::Object(a)) => {
                                    matches!(a.borrow().get("read"), None | Some(Value::Undef))
                                        || matches!(a.borrow().get("read"), Some(Value::Str(r)) if &**r == "anyone")
                                }
                                Some(_) => false,
                            },
                            _ => true,
                        }
                })
            };
            let kind = crate::kind_url::UrlKind {
                columns,
                dated: drafts,
                hierarchical,
                public_slug: &public_slug,
            };
            let shape =
                crate::kind_url::check(pattern, &kind).map_err(|e| Diag::new(span("url"), e.message).help(e.help))?;
            Some((pattern.to_string(), shape, span("url")))
        }
        Some(Value::Str(_)) => {
            return Err(Diag::new(span("url"), "only collection() kinds have URLs")
                .help("a table() has no public pages; serve its rows from an `app/` route instead"));
        }
        Some(other) => {
            return Err(Diag::new(
                span("url"),
                format!("`url` must be a pattern like \"/blog/:slug\", not {}", other.describe()),
            ));
        }
    };

    // access. WordPress's own posts default to what core already allows (authors write, core's
    // capabilities decide whose posts): the rules narrow core's capabilities, never widen them.
    let owner = Owner::Collection(c.table().to_string());
    let native = c.post == Some(PostKind::Native);
    let mut access: IndexMap<String, Access> = ["read", "create", "update", "delete"]
        .iter()
        .map(|op| {
            (
                op.to_string(),
                if *op == "read" {
                    Access::anyone()
                } else if native {
                    Access::Role("author".into())
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
                    d = d.help("use read, create, update, delete, restore, purge");
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
        was,
        label,
        ui,
        editor,
        drafts,
        revisions,
        soft_delete,
        retention,
        staged,
        comment_policy,
        title,
        content,
        slug,
        access,
        api,
        uid,
        sync,
        auth,
        agents,
        url,
        hierarchical,
    })
}

/// Parses a retention duration string: `"forever"` -> `None`, or `"<n><s|m|h|d|w|y>"` -> `Some(seconds)`.
fn parse_retention_duration(spec: &str, span: Span) -> Result<Option<u64>> {
    if spec == "forever" {
        return Ok(None);
    }
    let bad = || {
        Diag::new(span, format!("retention duration \"{spec}\" isn't valid"))
            .help("use \"forever\" or a positive duration like \"30d\", \"90d\", \"24h\", \"1y\" (s, m, h, d, w, y)")
    };
    let digits = spec.trim_end_matches(|c: char| c.is_ascii_alphabetic());
    let n: u64 = digits.parse().map_err(|_| bad())?;
    if n == 0 {
        return Err(bad());
    }
    let seconds = match &spec[digits.len()..] {
        "s" => n,
        "m" => n.saturating_mul(60),
        "h" => n.saturating_mul(3600),
        "d" => n.saturating_mul(86_400),
        "w" => n.saturating_mul(604_800),
        "y" => n.saturating_mul(365 * 86_400),
        _ => return Err(bad()),
    };
    Ok(Some(seconds))
}

/// Parses `retention: "forever" | { deleted?: ..., revisions?: ... }`.
fn extract_retention(v: Option<&Value>, ev: &Evaluator<'_>, span: Span) -> Result<Option<Retention>> {
    let Some(v) = v.filter(|v| !matches!(v, Value::Undef)) else {
        return Ok(None);
    };
    if let Value::Str(s) = v {
        if s.as_ref() == "forever" {
            return Ok(Some(Retention::default()));
        }
        return Err(
            Diag::new(span, format!("`retention` must be \"forever\" or an object, not \"{s}\""))
                .help("write `retention: \"forever\"` or `retention: { deleted: \"90d\", revisions: { keepLast: 50, maxAge: \"365d\" } }`"),
        );
    }
    let Value::Object(map) = v else {
        return Err(Diag::new(
            span,
            format!("`retention` must be \"forever\" or an object, not {}", v.describe()),
        )
        .help("write `retention: { deleted: \"90d\", revisions: \"forever\" }`"));
    };
    let map = map.borrow();
    let mut ret = Retention::default();
    for (k, val) in map.iter() {
        let kspan = ev.key_span(v, k).unwrap_or(span);
        match k.as_str() {
            "deleted" => match val {
                Value::Undef => {}
                Value::Str(s) => ret.deleted_seconds = parse_retention_duration(s, kspan)?,
                other => {
                    return Err(Diag::new(
                        kspan,
                        format!(
                            "`retention.deleted` must be \"forever\" or a duration string, not {}",
                            other.describe()
                        ),
                    ));
                }
            },
            "revisions" => match val {
                Value::Undef => {}
                Value::Str(s) if s.as_ref() == "forever" => ret.revisions = None,
                Value::Object(rm) => {
                    let rm = rm.borrow();
                    let mut rr = RevisionRetention::default();
                    for (rk, rv) in rm.iter() {
                        let rspan = ev.key_span(val, rk).unwrap_or(kspan);
                        match rk.as_str() {
                            "keepLast" => match rv {
                                Value::Undef => {}
                                Value::Num(n) if n.fract() == 0.0 && *n >= 1.0 && *n <= u32::MAX as f64 => {
                                    rr.keep_last = Some(*n as u32);
                                }
                                other => {
                                    return Err(Diag::new(
                                        rspan,
                                        format!(
                                            "`retention.revisions.keepLast` must be a positive integer, not {}",
                                            other.describe()
                                        ),
                                    ));
                                }
                            },
                            "maxAge" => match rv {
                                Value::Undef => {}
                                Value::Str(s) => rr.max_age_seconds = parse_retention_duration(s, rspan)?,
                                other => {
                                    return Err(Diag::new(
                                        rspan,
                                        format!(
                                            "`retention.revisions.maxAge` must be \"forever\" or a duration string, not {}",
                                            other.describe()
                                        ),
                                    ));
                                }
                            },
                            other => {
                                return Err(
                                    Diag::new(rspan, format!("`retention.revisions` has no option `{other}`"))
                                        .help("use `keepLast` and/or `maxAge`"),
                                );
                            }
                        }
                    }
                    if rr.keep_last.is_some() || rr.max_age_seconds.is_some() {
                        ret.revisions = Some(rr);
                    }
                }
                other => {
                    return Err(Diag::new(
                        kspan,
                        format!(
                            "`retention.revisions` must be \"forever\" or `{{ keepLast?, maxAge? }}`, not {}",
                            other.describe()
                        ),
                    ));
                }
            },
            other => {
                return Err(Diag::new(kspan, format!("`retention` has no option `{other}`"))
                    .help("use `deleted` and/or `revisions`"));
            }
        }
    }
    Ok(Some(ret))
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
        s = format!("an optional {}", &s[2..]);
    }
    s
}

/// One rule (§1.6): boolean, "anyone", "authenticated", a role, or a function.
fn access_rule(v: &Value, span: Span, owner: &Owner, op: &str, fn_name: String, out: &mut Extracted) -> Result<Access> {
    Ok(match v {
        Value::Bool(b) => Access::Allow(*b),
        Value::Str(s) if &**s == "system" => Access::Allow(false),
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
                plugin: None,
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
    if g.name.set(key.to_string()).is_err() && g.name.get().map(String::as_str) != Some(key) {
        return Err(Diag::new(span, "a global can only be registered once"));
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
                format!("a global field is required unless it has a default (`{name}`)"),
            )
            .help("add `.default(...)`, or make it `.optional()`"));
        }
        fields.insert(name.clone(), col);
    }
    let mut label = title_case(key);
    let mut ui: Option<Json> = None;
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
                ("ui", Value::Undef) => {}
                ("ui", _) => ui = extract_global_ui(Some(v), sp, ev)?,
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
                    return Err(
                        Diag::new(sp, format!("global() has no option `{k}`")).help("use label, ui, access, agents")
                    );
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
            ui,
        },
    );
    Ok(())
}

fn extract_kind_ui(
    v: Option<&Value>,
    span: Span,
    columns: &IndexMap<String, Column>,
    ev: &Evaluator<'_>,
) -> Result<Option<Json>> {
    let Some(v) = v.filter(|v| !matches!(v, Value::Undef)) else {
        return Ok(None);
    };
    let Value::Object(o) = v else {
        return Err(Diag::new(span, format!("`ui` must be an object, not {}", v.describe())));
    };
    const ALLOWED: &[&str] = &[
        "icon",
        "singularLabel",
        "description",
        "group",
        "defaultColumns",
        "hidden",
    ];
    let mut map = serde_json::Map::new();
    for (k, val) in o.borrow().iter() {
        if matches!(val, Value::Undef) {
            continue;
        }
        let kspan = ev.key_span(v, k).unwrap_or(span);
        if !ALLOWED.contains(&k.as_str()) {
            return Err(
                Diag::new(kspan, format!("`ui` has no option `{k}`")).help(format!("use {}", ALLOWED.join(", ")))
            );
        }
        match (k.as_str(), val) {
            ("icon" | "singularLabel" | "description" | "group", Value::Str(s)) => {
                map.insert(k.clone(), serde_json::json!(s.as_ref()));
            }
            ("hidden", Value::Bool(b)) => {
                map.insert(k.clone(), serde_json::json!(*b));
            }
            ("defaultColumns", Value::Array(items)) => {
                let mut cols = Vec::new();
                for item in items.borrow().iter() {
                    let Value::Str(col) = item else {
                        return Err(Diag::new(kspan, "`ui.defaultColumns` must be a list of column names"));
                    };
                    if !columns.contains_key(col.as_ref()) {
                        return Err(no_column("collection", col, columns, kspan, "`ui.defaultColumns`"));
                    }
                    cols.push(serde_json::json!(col.as_ref()));
                }
                map.insert(k.clone(), Json::Array(cols));
            }
            _ => {
                return Err(Diag::new(
                    kspan,
                    format!("`ui.{k}` has invalid value {}", val.describe()),
                ));
            }
        }
    }
    Ok((!map.is_empty()).then_some(Json::Object(map)))
}

fn extract_global_ui(v: Option<&Value>, span: Span, ev: &Evaluator<'_>) -> Result<Option<Json>> {
    let Some(v) = v.filter(|v| !matches!(v, Value::Undef)) else {
        return Ok(None);
    };
    let Value::Object(o) = v else {
        return Err(Diag::new(span, format!("`ui` must be an object, not {}", v.describe())));
    };
    const ALLOWED: &[&str] = &["icon", "description", "group", "hidden"];
    let mut map = serde_json::Map::new();
    for (k, val) in o.borrow().iter() {
        if matches!(val, Value::Undef) {
            continue;
        }
        let kspan = ev.key_span(v, k).unwrap_or(span);
        if !ALLOWED.contains(&k.as_str()) {
            return Err(
                Diag::new(kspan, format!("`ui` has no option `{k}`")).help(format!("use {}", ALLOWED.join(", ")))
            );
        }
        match (k.as_str(), val) {
            ("icon" | "description" | "group", Value::Str(s)) => {
                map.insert(k.clone(), serde_json::json!(s.as_ref()));
            }
            ("hidden", Value::Bool(b)) => {
                map.insert(k.clone(), serde_json::json!(*b));
            }
            _ => {
                return Err(Diag::new(
                    kspan,
                    format!("`ui.{k}` has invalid value {}", val.describe()),
                ));
            }
        }
    }
    Ok((!map.is_empty()).then_some(Json::Object(map)))
}

/// §3.5.1: a hint per post-backed column that only postmeta can filter.
fn postmeta_hints(out: &mut Extracted) {
    let mut hints = Vec::new();
    for c in out.schema.collections.values() {
        for col in c.columns.values() {
            if !c.editor || c.is_core_column(&col.name) {
                continue;
            }
            if col.unique || col.references.is_some() && col.ty != ColType::RefList {
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
    if b.was.is_some() {
        return Err(Diag::new(
            b.span_of("was"),
            "`.was()` is gone: forward-only migrations never rename columns in place",
        )
        .help("use `.column(\"<old_name>\")` to map this field to the existing SQL column with zero DDL (and `.alias(\"<old_name>\")` to keep accepting the old API name), or opt into `spacepress/danger` for a physical rename"));
    }
    if let Some(col) = &b.storage_name {
        if place != Place::Top {
            return Err(Diag::new(
                b.span_of("column"),
                "`.column()` maps a stored SQL column; only a kind's own top-level fields have one",
            )
            .help("remove `.column()` here"));
        }
        if !valid_ident(col) || col.len() > 64 {
            return Err(Diag::new(
                b.span_of("column"),
                format!("`{col}` isn't a valid physical SQL column name"),
            )
            .help("use an ASCII identifier of at most 64 characters, starting with a letter"));
        }
    }
    for alias in &b.aliases {
        if place != Place::Top {
            return Err(Diag::new(
                b.span_of("alias"),
                "`.alias()` is only allowed on a kind's top-level fields",
            )
            .help("remove `.alias()` here"));
        }
        if !valid_ident(alias) {
            return Err(
                Diag::new(b.span_of("alias"), format!("`{alias}` isn't a valid field alias"))
                    .help("use letters, digits, and underscores, starting with a letter"),
            );
        }
        if alias == name {
            return Err(Diag::new(
                b.span_of("alias"),
                format!("`.alias(\"{alias}\")` duplicates the field's own name"),
            )
            .help("remove the redundant alias"));
        }
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
            "access/showIf is not allowed on `id` or system columns",
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
    let bucket_name = match &b.bucket {
        None => None,
        Some(bucket) => Some(bucket.name.get().cloned().ok_or_else(|| {
            Diag::new(b.span, "file() refers to a files() bucket that isn't exported")
                .help("export it by name: `export const decks = files({ ... })`")
        })?),
    };
    if let Some(bucket) = &bucket_name
        && !out.schema.files.contains_key(bucket)
    {
        return Err(Diag::new(b.span, format!("unknown file bucket {bucket:?}"))
            .help("export your bucket by name with files()"));
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
    } else if b.searchable.is_some() {
        Some(("`.searchable()`", b.span_of("searchable")))
    } else if b.terms {
        Some(("`terms()`", b.span))
    } else if b.references.is_some() {
        Some(("`ref()`", b.span))
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
                plugin: None,
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
                plugin: None,
            });
            Some(DefaultKind::Fn(String::new()))
        }
        Some(DefaultVal::Value(_)) => unreachable!("$onUpdate always takes a function"),
    };
    let many_ref = b.ty == ColType::RefList;
    if many_ref {
        // A many-ref is a set of links in SpacePress's join table, not a value: nothing to make unique,
        // default, translate or regenerate (docs/decisions/2026-11-11-authoring-surface.md, ruling 3).
        let bad = [
            ("unique", b.unique),
            ("default", b.default.is_some()),
            ("$onUpdate", b.on_update.is_some()),
            ("was", b.was.is_some()),
        ];
        if let Some((m, _)) = bad.iter().find(|(_, on)| *on) {
            return Err(Diag::new(
                b.span_of(m),
                format!("`.{m}()` isn't valid on a `ref().many()` field (`{name}`)"),
            )
            .help("a many-ref is a list of links SpacePress keeps in its own table; remove the modifier"));
        }
    }
    let default = if b.terms {
        // An item with no terms holds `[]`.
        Some(DefaultKind::Value(Json::Array(Vec::new())))
    } else {
        default
    };
    let references = match &b.references {
        None => None,
        Some((target, on_delete, on_update, span)) => {
            let target_kind = match target {
                RefTarget::Kind(v) => v.clone(),
                RefTarget::Thunk(f) => ev.call_closure_public(f, vec![], *span)?,
            };
            let Value::Collection(kind) = target_kind else {
                return Err(
                    Diag::new(*span, format!("ref() takes a kind, not {}", target_kind.describe())).help(
                        "write `ref(authors)`, or `ref(() => authors)` for a kind declared later or the kind itself",
                    ),
                );
            };
            if !out.collections.iter().any(|c| Rc::ptr_eq(c, &kind)) {
                return Err(
                    Diag::new(*span, format!("`{name}` refers to a kind that isn't exported"))
                        .help("export the kind it refers to, e.g. `export const authors = collection({ ... })`"),
                );
            }
            if b.terms && !kind.kind.term() {
                return Err(Diag::new(*span, format!("terms() takes a taxonomy() export, not the {}() `{}`", kind.kind.builder(), kind.table()))
                    .help("declare `export const topics = taxonomy({}, { on: [posts] })` and write `terms(topics)`; core's categories and tags wait for the wp.* built-ins"));
            }
            if kind.kind.term() && !b.terms {
                return Err(Diag::new(
                    *span,
                    format!("`{name}` refers to a taxonomy with `ref()`, which isn't built yet"),
                )
                .help(format!(
                    "attach terms with `{name}: terms({})` or the taxonomy's `on` option",
                    kind.table()
                )));
            }
            let on_delete_span = b
                .mods
                .iter()
                .rev()
                .find(|(m, _)| *m == "onDelete")
                .map_or(*span, |(_, s)| *s);
            if many_ref && on_delete.is_some_and(|a| !matches!(a, RefAction::Cascade | RefAction::Restrict)) {
                return Err(Diag::new(
                    on_delete_span,
                    format!("`.onDelete()` on the `ref().many()` field `{name}` takes \"cascade\" or \"restrict\""),
                )
                .help("\"cascade\" (the default) removes the link when a linked row is deleted; \"restrict\" refuses the delete while links exist"));
            }
            // A `ref()` defaults to `restrict` when required and `setNull` when optional (spec §2.5);
            // a many-ref's links go with either side.
            let on_delete = on_delete.or(Some(if many_ref {
                RefAction::Cascade
            } else if b.optional {
                RefAction::SetNull
            } else {
                RefAction::Restrict
            }));
            if on_delete == Some(RefAction::SetNull) && !b.optional {
                return Err(Diag::new(
                    on_delete_span,
                    format!("`{name}` is required, so deleting what it refers to can't set it to null"),
                )
                .help("make it `.optional()`, or use `.onDelete(\"cascade\")` or `.onDelete(\"restrict\")`"));
            }
            if let Owner::Collection(table) = owner {
                out.refs.push(RefField {
                    table: table.clone(),
                    field: name.to_string(),
                    target: kind.table().to_string(),
                    optional: b.optional,
                    reverse: b.reverse.clone(),
                    many: many_ref,
                    terms: b.terms,
                    span: *span,
                });
            }
            let join = match owner {
                Owner::Collection(_) if b.terms => Some(term_join(kind.table())),
                Owner::Collection(table) if many_ref => Some(join_key(table, name)),
                _ => None,
            };
            Some(Reference {
                collection: kind.table().to_string(),
                column: "id".into(),
                on_delete,
                on_update: *on_update,
                join,
            })
        }
    };
    let validate = match &b.validate {
        Some(f) => {
            out.roots.push(Root {
                closure: f.clone(),
                slot: Slot::Validate { at: at.clone() },
                name: fn_name(owner, "validate", &path),
                plugin: None,
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
        not_null: !b.optional,
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
        read_only: false,
        commerce_meta: false,
        localized: b.localized,
        access,
        show_if,
        ui,
        bucket: b.bucket.as_ref().and_then(|r| r.name.get().cloned()),
        derived: false,
        hidden: false,
        ts_type: b.ts_type.clone(),
        was: b.was.clone(),
        storage_name: b.storage_name.clone(),
        aliases: b.aliases.clone(),
        searchable: b.searchable,
        omit: false,
        inferred: None,
        shape: None,
        required: false,
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
        if !b.optional {
            return bad("required (add `.optional()` to allow null)");
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
        (ColType::RefList, _) => return bad("a many-ref (it takes no default)"),
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

/// Each `ref()` field is a one relation under its own name, and gives its target a many relation
/// named after the referencing kind (or `.reverse("name")`) (docs/decisions/2026-11-11-authoring-surface.md,
/// rulings 1 and 2). Two refs from one kind to the same target give no unnamed reverse: neither would
/// say which field it follows.
fn ref_relations(out: &mut Extracted) -> Result<()> {
    let refs = std::mem::take(&mut out.refs);
    let mut joins: std::collections::HashSet<String> = std::collections::HashSet::new();
    for r in refs.iter().filter(|r| r.many && !r.terms) {
        let key = join_key(&r.table, &r.field);
        if out.schema.collections.values().any(|c| c.table == key) || joins.contains(&key) {
            return Err(Diag::new(
                r.span,
                format!(
                    "`{}`'s join table would be named \"{key}\", which another kind or many-ref already uses",
                    r.field
                ),
            )
            .help("rename the field, or give the other kind another `storageName`"));
        }
        joins.insert(key);
    }
    for r in &refs {
        let coll = out.schema.collections.get_mut(&r.table).expect("collection");
        coll.relations.insert(
            r.field.clone(),
            if r.many {
                // `posts.tags` → the tags linked from this post, in position order.
                Relation {
                    name: r.field.clone(),
                    kind: RelKind::Many,
                    collection: r.target.clone(),
                    from: "id".into(),
                    to: "id".into(),
                    optional: false,
                    through: Some((link_key(r), "owner_id".into(), "target_id".into())),
                }
            } else {
                Relation {
                    name: r.field.clone(),
                    kind: RelKind::One,
                    collection: r.target.clone(),
                    from: r.field.clone(),
                    to: "id".into(),
                    optional: r.optional,
                    through: None,
                }
            },
        );
    }
    for r in &refs {
        let key = out.schema.collections[&r.table].key.clone();
        let siblings = refs
            .iter()
            .filter(|o| o.table == r.table && o.target == r.target && o.reverse.is_none())
            .count();
        let name = match &r.reverse {
            Some(name) => name.clone(),
            None if siblings > 1 => continue,
            None => key,
        };
        if !valid_ident(&name) {
            return Err(Diag::new(r.span, format!("\"{name}\" isn't a valid reverse name"))
                .help("use letters, digits and underscores, starting with a letter"));
        }
        let target = out.schema.collections.get_mut(&r.target).expect("target");
        if target.columns.contains_key(&name) || target.relations.contains_key(&name) {
            return Err(Diag::new(
                r.span,
                format!(
                    "`{}` gives \"{}\" a reverse list named `{name}`, which \"{}\" already has",
                    r.field, target.key, target.key
                ),
            )
            .help(format!(
                "name the reverse list: `{}: ref(...).reverse(\"otherName\")`",
                r.field
            )));
        }
        target.relations.insert(
            name.clone(),
            Relation {
                name,
                kind: RelKind::Many,
                collection: r.table.clone(),
                from: "id".into(),
                to: if r.many { "id".into() } else { r.field.clone() },
                optional: false,
                through: r.many.then(|| (link_key(r), "target_id".into(), "owner_id".into())),
            },
        );
    }
    Ok(())
}

/// Where a list field's links live: its join table, or its taxonomy's term relationships.
fn link_key(r: &RefField) -> String {
    if r.terms {
        term_join(&r.target)
    } else {
        join_key(&r.table, &r.field)
    }
}

/// A many-ref's join table key (`posts` + `relatedPosts` → `posts_related_posts`), stored as
/// `{prefix}sp_<key>`.
pub fn join_key(table: &str, field: &str) -> String {
    format!("{table}_{}", crate::schema::physical_name(field))
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
            Stmt::Block(b) | Stmt::While(_, b) | Stmt::DoWhile(b, _) => scan_stmts(b, param, out),
            Stmt::Labeled(_, s, _) => scan_stmts(std::slice::from_ref(s), param, out),
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
        ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args, .. } => {
            r(callee)
                || args
                    .iter()
                    .any(|a| matches!(a, ArrEl::Item(x) | ArrEl::Spread(x) if r(x)))
        }
        ExprKind::Binary(_, a, b) | ExprKind::Logical(_, a, b) => r(a) || r(b),
        ExprKind::Cond(a, b, c) => r(a) || r(b) || r(c),
        ExprKind::Unary(_, x)
        | ExprKind::Await(x)
        | ExprKind::Yield { arg: Some(x), .. }
        | ExprKind::Chain(x)
        | ExprKind::As(x, _)
        | ExprKind::NonNull(x)
        | ExprKind::IsNullish(x, _) => r(x),
        ExprKind::Index { obj, index, .. } => r(obj) || r(index),
        ExprKind::Template(_, xs) | ExprKind::Sequence(xs) => xs.iter().any(r),
        ExprKind::Jsx(jsx) => jsx.exprs().into_iter().any(r),
        ExprKind::Array(items) => items
            .iter()
            .any(|a| matches!(a, ArrEl::Item(x) | ArrEl::Spread(x) if r(x))),
        ExprKind::Object(props) => props.iter().any(|p| match p {
            Prop::KV(_, x) | Prop::Spread(x) => r(x),
            Prop::NullPrototype => false,
        }),
        _ => false,
    }
}

// Phase-2 config is normalized once here. Feature modules consume this metadata;
// they do not reinterpret builders or apply policy during schema loading.
/// A kind's `comments` option (spec §2.3): `true` is the defaults, an object overrides them.
fn comment_policy(
    v: Option<&Value>,
    ev: &Evaluator<'_>,
    span: Span,
    editor: bool,
) -> Result<Option<crate::schema::CommentPolicy>> {
    let mut policy = crate::schema::CommentPolicy::default();
    let fields = match v {
        None | Some(Value::Undef) | Some(Value::Bool(false)) => return Ok(None),
        Some(Value::Bool(true)) => None,
        Some(o @ Value::Object(_)) => Some(o),
        Some(other) => {
            return Err(Diag::new(
                span,
                format!(
                    "`comments` is true, false or an options object, not {}",
                    other.describe()
                ),
            )
            .help("write `comments: true`, or `comments: { guests: true, moderation: \"all\" }`"));
        }
    };
    if !editor {
        return Err(Diag::new(span, "only collection() kinds take comments")
            .help("comments are WordPress comments on a post; use a table() with a ref for other threads"));
    }
    let Some(fields) = fields else {
        return Ok(Some(policy));
    };
    let Value::Object(o) = fields else { unreachable!() };
    for (k, val) in o.borrow().iter() {
        let at = ev.key_span(fields, k).unwrap_or(span);
        match (k.as_str(), val) {
            (_, Value::Undef) => {}
            ("guests", Value::Bool(b)) => policy.guests = *b,
            ("guests", other) => {
                return Err(Diag::new(
                    at,
                    format!("`comments.guests` is true or false, not {}", other.describe()),
                ));
            }
            ("moderation", Value::Str(m)) => {
                policy.moderation = match &**m {
                    "none" => "none",
                    "first-time" => "first-time",
                    "all" => "all",
                    other => {
                        return Err(Diag::new(at, format!("`comments.moderation` can't be {other:?}"))
                            .help("use \"none\" (approve at once), \"first-time\" (hold a commenter's first comment) or \"all\""));
                    }
                };
            }
            ("moderation", other) => {
                return Err(Diag::new(
                    at,
                    format!("`comments.moderation` is a string, not {}", other.describe()),
                )
                .help("use \"none\", \"first-time\" or \"all\""));
            }
            ("threaded", Value::Bool(b)) => policy.threaded = if *b { 3 } else { 1 },
            ("threaded", Value::Num(n)) if n.fract() == 0.0 && (1.0..=10.0).contains(n) => policy.threaded = *n as u8,
            ("threaded", other) => {
                return Err(
                    Diag::new(at, format!("`comments.threaded` can't be {}", other.describe()))
                        .help("use true (3 levels), false (flat) or a depth from 1 to 10"),
                );
            }
            ("closeAfterDays", Value::Null) => policy.close_after_days = None,
            ("closeAfterDays", Value::Num(n)) if n.fract() == 0.0 && *n >= 1.0 && *n <= 36_500.0 => {
                policy.close_after_days = Some(*n as u32);
            }
            ("closeAfterDays", other) => {
                return Err(
                    Diag::new(at, format!("`comments.closeAfterDays` can't be {}", other.describe()))
                        .help("use a whole number of days from 1, or leave it out to never close"),
                );
            }
            ("types", Value::Array(items)) if items.borrow().is_empty() => {}
            ("types", _) => {
                return Err(Diag::new(at, "comment types aren't built yet")
                    .help("leave `types` out; `wp.comments.type()` adds them"));
            }
            (other, _) => {
                let mut d = Diag::new(at, format!("`comments` has no option `{other}`"));
                if let Some(s) = suggest(other, ["guests", "moderation", "threaded", "closeAfterDays", "types"]) {
                    d = d.help(format!("did you mean `{s}`?"));
                } else {
                    d = d.help("the options are guests, moderation, threaded, closeAfterDays and types");
                }
                return Err(d);
            }
        }
    }
    Ok(Some(policy))
}

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

pub(crate) fn signature_config(v: &Value, span: Span) -> Result<Json> {
    let o = object(v, span, "signature")?;
    keys(&o, &["secret", "header", "scheme"], span, "signature")?;
    let secret = env_value(o.get("secret"), span)?;
    let header = string(o.get("header"), span, "signature.header")?;
    if !regex::Regex::new(r"^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")
        .unwrap()
        .is_match(&header)
    {
        return Err(Diag::new(span, "signature.header must be an HTTP header name")
            .help("use stripe-signature or spacepress-signature"));
    }
    let scheme = string(o.get("scheme"), span, "signature.scheme")?;
    if scheme != "spacepress" && scheme != "hmac-sha256" {
        return Err(Diag::new(span, "signature.scheme must be spacepress or hmac-sha256")
            .help("choose a supported HMAC scheme"));
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
        // Locales are turned off until they come back; SPACEPRESS_LOCALES=1 keeps them for the test suite.
        if std::env::var("SPACEPRESS_LOCALES").as_deref() != Ok("1") {
            return Err(Diag::new(span, "locales are turned off for now").help("remove locales from defineConfig"));
        }
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
                return Err(Diag::new(span, "files entries must use files()").help(
                    "write `export const decks = files({ accept: [\"application/pdf\"], maxBytes: 20000000 })`",
                ));
            };
            let sp = bucket.span;
            if !valid_ident(&name) || name == "media" {
                return Err(Diag::new(sp, "file bucket name must be an identifier other than media")
                    .help("use a distinct bucket name, such as decks"));
            }
            let _ = bucket.name.set(name.clone());
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

pub(crate) fn sync_config(pattern: &str, span: Span) -> Result<Json> {
    // `content/docs/**/*.md`: every file in the folder and the folders below it; a hierarchical kind
    // takes each folder as the parent of the files in it.
    let (pattern, nested) = match pattern.rsplit_once("/**/") {
        Some((dir, file)) => (format!("{dir}/{file}"), true),
        None => (pattern.to_string(), false),
    };
    let pattern = pattern.as_str();
    let (dir, format) = if let Some(d) = pattern.strip_suffix("/*.md") {
        (d, "md")
    } else if let Some(d) = pattern.strip_suffix("/*.json") {
        (d, "json")
    } else {
        return Err(Diag::new(span, "sync must end in /*.md or /*.json")
            .help("use content/posts/*.md, or content/docs/**/*.md for a folder tree"));
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
    let mut out = serde_json::json!({"dir": if normalized.is_empty() { "." } else { &normalized }, "format": format});
    if nested {
        out["nested"] = Json::Bool(true);
    }
    Ok(out)
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
            if !c
                .columns
                .get("email")
                .is_some_and(|c| c.ty == ColType::Email && c.not_null && c.unique)
            {
                return Err(
                    Diag::new(b.span, "userProjection() needs `email: email().unique().max(191)`")
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
        for col in c.columns.values() {
            let builder = out.collections.iter().find(|b| b.table() == c.table).unwrap();
            if let Some(join) = col.references.as_ref().and_then(|r| r.join.as_ref())
                && is_term_join(join)
            {
                if out.schema.collections[&col.references.as_ref().unwrap().collection].uid {
                    continue;
                }
                // Declared with `terms()`, or added by the taxonomy's `on`.
                let span = builder.columns.get(&col.name).map_or(builder.span, |b| b.span);
                return Err(Diag::new(
                    span,
                    format!("`{}` holds terms, which sync can't carry yet", col.name),
                )
                .help("leave the taxonomy off synced kinds (its `on` and `terms()` fields), or turn sync off for this kind"));
            }
            if col.ty == ColType::RefList {
                return Err(Diag::new(
                    builder.columns[&col.name].span,
                    format!("`{}` is a `ref().many()` list, which sync can't carry yet", col.name),
                )
                .help("leave the list off synced kinds, or turn sync off for this kind"));
            }
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
    match cfg.get("jobs").filter(|v| !matches!(v, Value::Undef)) {
        Some(v) => jobs(v, ev, span, out, None),
        None => Ok(()),
    }
}

/// Each registered job; a plugin's are registered as `<plugin>_<name>`.
fn jobs(
    v: &Value,
    ev: &mut Evaluator<'_>,
    span: Span,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    for (name, value) in object(v, span, "jobs")? {
        let Value::Job(j) = value else {
            return Err(Diag::new(span, "jobs entries must use job()")
                .help("write job({ retries: 3 }, async ({ id }: { id: number }) => { ... })"));
        };
        if !valid_ident(&name) {
            return Err(Diag::new(j.span, "invalid job name").help("use an identifier"));
        }
        let name = registered(plugin, &name);
        if out.schema.jobs.contains_key(&name) {
            return Err(Diag::new(j.span, format!("two jobs are both registered as `{name}`"))
                .help("rename one; plugin jobs are registered as <plugin>_<name>"));
        }
        out.job_values.insert(Rc::as_ptr(&j) as usize, name.clone());
        if j.form != FnForm::Object {
            typed_job(name, &j, ev, out, plugin)?;
            continue;
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
            plugin: plugin.map(|(i, _)| i),
        });
        out.schema.jobs.insert(
            name,
            Job {
                input,
                handler: String::new(),
                retries,
                agents,
                schedule: None,
                typed_input: false,
                input_ty: None,
            },
        );
    }
    Ok(())
}

/// `job(options, handler)` and `cron(schedule, handler)`: the input is the handler's first
/// parameter type, as for a query or mutation.
fn typed_job(
    name: String,
    j: &Rc<ActionVal>,
    ev: &mut Evaluator<'_>,
    out: &mut Extracted,
    plugin: Option<(usize, &str)>,
) -> Result<()> {
    let Value::Closure(handler) = &j.handler else {
        unreachable!("checked by the evaluator")
    };
    let (retries, agents, schedule) = if j.form == FnForm::Cron {
        let Value::Str(spec) = &j.config else {
            unreachable!("checked by the evaluator")
        };
        (3, false, Some(Schedule::Cron(cron_schedule(spec, j.span)?)))
    } else {
        let o = object(&j.config, j.span, "job")?;
        let at = |k: &str| ev.key_span(&j.config, k).unwrap_or(j.span);
        for k in o.keys() {
            if !matches!(k.as_str(), "retries" | "agents" | "every") {
                let d = Diag::new(at(k), format!("job() has no option `{k}`"));
                return Err(match k.as_str() {
                    "input" => d.help("the input is the handler's parameter type: `job({ retries: 3 }, async ({ postId }: { postId: number }) => ...)`"),
                    "handler" => d.help("pass the handler after the options: `job({ retries: 3 }, async (input) => ...)`"),
                    "cron" => d.help("a job on a cron schedule is `cron(\"0 3 * * *\", async () => ...)`"),
                    _ => d.help("use retries, agents, every"),
                });
            }
        }
        let retries = match o.get("retries") {
            None | Some(Value::Undef) => 3,
            Some(Value::Num(n)) if *n >= 0.0 && *n <= 20.0 && n.fract() == 0.0 => *n as u32,
            _ => {
                return Err(
                    Diag::new(at("retries"), "job retries must be a whole number from 0 to 20").help("use retries: 3"),
                );
            }
        };
        let agents = bool_option(&o, "agents", false, at("agents"))?;
        let schedule = match o.get("every") {
            None | Some(Value::Undef) => None,
            Some(Value::Str(spec)) => Some(Schedule::Every(every(spec, at("every"))?)),
            Some(other) => {
                return Err(Diag::new(
                    at("every"),
                    format!("every is a duration like \"1h\", not {}", other.describe()),
                )
                .help("write `every: \"1h\"` (s, m, h, d or w)"));
            }
        };
        (retries, agents, schedule)
    };
    out.roots.push(Root {
        closure: handler.clone(),
        slot: Slot::JobHandler { job: name.clone() },
        name: format!("job__{name}"),
        plugin: plugin.map(|(i, _)| i),
    });
    out.schema.jobs.insert(
        name,
        Job {
            input: IndexMap::new(),
            handler: String::new(),
            retries,
            agents,
            schedule,
            typed_input: true,
            input_ty: None,
        },
    );
    Ok(())
}

use crate::wordpress::abilities::kebab;

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
    // The store's own abilities are registered beside the app's, under the app's namespace. An app
    // name that produced the same ability would replace the WooCommerce operation at runtime while
    // `abilities.call` stayed typed as that operation.
    let mut commerce: Vec<(String, &str)> = crate::wordpress::abilities::commerce_operations(&out.schema)
        .into_iter()
        .map(|(name, _)| (name, "product({ inventory: true })"))
        .collect();
    if out.schema.store.is_some() {
        commerce.push(("store-get".into(), "store()"));
    }
    let app = kebab(&out.schema.name);
    let mut abilities = std::collections::HashSet::new();
    let mut add = |name: String| -> Result<()> {
        if let Some((_, owner)) = commerce.iter().find(|(taken, _)| *taken == name) {
            return Err(Diag::new(
                span,
                format!("the ability `{app}/{name}` is declared twice: {owner} adds it, and so does this app"),
            )
            .help(format!(
                "rename the action, kind, global or job that becomes `{name}`; `{app}/{name}` stays the WooCommerce operation"
            )));
        }
        if !abilities.insert(name.clone()) {
            return Err(Diag::new(span, format!("ability kebab collision: {name}"))
                .help("rename the colliding collection, action, global, or job"));
        }
        Ok(())
    };
    // Taxonomies too: `Register::build` registers the same operations for every kind.
    for c in out.schema.collections.values() {
        for op in ["list", "get", "create", "update", "delete", "revisions"] {
            add(format!("{}-{op}", kebab(&c.table)))?;
        }
        if c.editor && c.revisions && c.staged && c.api.update {
            for op in [
                "stage",
                "staged-get",
                "staged-compare",
                "staged-promote",
                "staged-discard",
                "staged-preview",
            ] {
                add(format!("{}-{op}", kebab(&c.table)))?;
            }
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
