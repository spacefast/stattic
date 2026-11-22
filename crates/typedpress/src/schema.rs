//! The evaluated schema: plain data produced by running `typedpress.config.ts` at
//! build time. Migrations, schema.php, the typed client, and generated types
//! all derive from it.

use indexmap::IndexMap;
use serde_json::Value as Json;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ColType {
    Integer,
    Real,
    Text,
    Boolean,
    Timestamp,
    Date,
    Json,
    Email,
    Url,
    Slug,
    RichText,
    Media,
    File,
    MediaList,
    /// `select([...]).many()`. A single select is `Text` with `enum`.
    Select,
    /// `ref(kind).many()`: the target ids in write order. No column of its own: the rows live in a
    /// TypedPress-owned join table (see [`Column::join_table`]).
    RefList,
    Group,
    Repeater,
    Blocks,
}

impl ColType {
    pub fn name(self) -> &'static str {
        match self {
            ColType::Integer => "integer",
            ColType::Real => "real",
            ColType::Text => "text",
            ColType::Boolean => "boolean",
            ColType::Timestamp => "timestamp",
            ColType::Date => "date",
            ColType::Json => "json",
            ColType::Email => "email",
            ColType::Url => "url",
            ColType::Slug => "slug",
            ColType::RichText => "richText",
            ColType::Media => "media",
            ColType::File => "file",
            ColType::MediaList => "mediaList",
            ColType::Select => "select",
            ColType::RefList => "refList",
            ColType::Group => "group",
            ColType::Repeater => "repeater",
            ColType::Blocks => "blocks",
        }
    }

    /// The authoring name: the builder (`number()`) and its kind in the `"first"` types. `name()` is
    /// the stored name, which the snapshot and schema.php keep.
    pub fn builder_name(self) -> &'static str {
        match self {
            ColType::Real => "number",
            other => other.name(),
        }
    }

    /// Strings validated by length/pattern.
    pub fn is_stringy(self) -> bool {
        matches!(self, ColType::Text | ColType::Email | ColType::Url | ColType::Slug)
    }

    /// Stored as JSON text and filtered as a whole value (§3.5 `json` kind).
    pub fn is_json_kind(self) -> bool {
        matches!(
            self,
            ColType::Json | ColType::Group | ColType::Repeater | ColType::Blocks
        )
    }

    /// Lists filtered with `has` (§3.5 `serialized-list` kind).
    pub fn is_list_kind(self) -> bool {
        matches!(self, ColType::MediaList | ColType::Select | ColType::RefList)
    }

    /// Group/repeater/blocks: their meta is spread over many rows on WordPress.
    pub fn is_structured(self) -> bool {
        matches!(self, ColType::Group | ColType::Repeater | ColType::Blocks)
    }
}

/// How a column gets a value when the writer leaves it out.
#[derive(Debug, Clone, PartialEq)]
pub enum DefaultKind {
    /// A constant, already in the column's JSON input shape.
    Value(Json),
    /// Current UTC timestamp (or today's date for `date`).
    Now,
    /// A compiled PHP function in functions.php (name without namespace).
    Fn(String),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RefAction {
    Cascade,
    Restrict,
    SetNull,
    NoAction,
}

impl RefAction {
    pub fn sql(self) -> &'static str {
        match self {
            RefAction::Cascade => "cascade",
            RefAction::Restrict => "restrict",
            RefAction::SetNull => "set null",
            RefAction::NoAction => "no action",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Reference {
    /// Target collection's table name.
    pub collection: String,
    pub column: String,
    pub on_delete: Option<RefAction>,
    pub on_update: Option<RefAction>,
    /// A many-ref's join table: its snapshot key, `<owner storage name>_<field snake_case>`, stored as
    /// `{prefix}tp_<key>` with `owner_id`, `target_id` and `position`. A `terms()` field's links
    /// are core's term relationships instead: `term:<taxonomy storage name>` ([`term_join`]).
    pub join: Option<String>,
}

/// The link key of a `terms()` field: the taxonomy's term relationships, read and written by the
/// runtime's term store. The `:` keeps it apart from every join table name.
pub fn term_join(taxonomy: &str) -> String {
    format!("term:{taxonomy}")
}

/// Whether a link key names a taxonomy's term relationships rather than a TypedPress join table.
pub fn is_term_join(join: &str) -> bool {
    join.starts_with("term:")
}

/// Value rules checked by the runtime after coercion (§1.2).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Rules {
    pub min: Option<f64>,
    pub max: Option<f64>,
    /// JavaScript source and flags of a RegExp literal.
    pub pattern: Option<(String, String)>,
    /// Compiled validator (function name in functions.php).
    pub validate: Option<String>,
}

impl Rules {
    pub fn is_empty(&self) -> bool {
        self.min.is_none() && self.max.is_none() && self.pattern.is_none() && self.validate.is_none()
    }
}

/// Presentation only: never part of storage, validation or authorization.
#[derive(Debug, Clone, Default, PartialEq, serde::Serialize, serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FieldUi {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub label: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub help: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub placeholder: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub width: Option<u8>,
}

#[derive(Debug, Clone)]
pub struct Column {
    pub name: String,
    pub ty: ColType,
    pub not_null: bool,
    pub primary_key: bool,
    pub auto_increment: bool,
    pub unique: bool,
    pub enum_values: Option<Vec<String>>,
    pub multiline: bool,
    /// `media({ accept })`: "image" | "any".
    pub accept: Option<String>,
    /// `slug({ source })`.
    pub source: Option<String>,
    pub default: Option<DefaultKind>,
    pub on_update: Option<DefaultKind>,
    pub references: Option<Reference>,
    pub rules: Rules,
    /// Sub-fields of a group or repeater.
    pub fields: Option<IndexMap<String, Column>>,
    /// Layouts of a blocks column: layout name → sub-fields.
    pub layouts: Option<IndexMap<String, IndexMap<String, Column>>>,
    /// Added by the compiler (drafts `status` / `publishedAt`).
    pub system: bool,
    pub localized: bool,
    pub access: IndexMap<String, Access>,
    pub show_if: Option<Json>,
    pub ui: Option<FieldUi>,
    pub bucket: Option<String>,
    pub derived: bool,
    pub hidden: bool,
    /// Computed by storage and never written: a term's `count` and `url`.
    pub read_only: bool,
    /// An authored Woo product field, stored via Woo's product metadata API.
    pub commerce_meta: bool,
    /// `json<T>()` annotation, module and call position.
    pub ts_type: Option<(crate::ir::TyAnn, usize, crate::diag::Span)>,
    /// `.was("old")`: the stored name before a rename (spec §2.5).
    pub was: Option<String>,
}

impl Column {
    pub fn new(name: &str, ty: ColType) -> Column {
        Column {
            name: name.to_string(),
            ty,
            not_null: false,
            primary_key: false,
            auto_increment: false,
            unique: false,
            enum_values: None,
            multiline: false,
            accept: None,
            source: None,
            default: None,
            on_update: None,
            references: None,
            rules: Rules::default(),
            fields: None,
            layouts: None,
            system: false,
            localized: false,
            access: IndexMap::new(),
            show_if: None,
            ui: None,
            bucket: None,
            derived: false,
            hidden: false,
            read_only: false,
            commerce_meta: false,
            ts_type: None,
            was: None,
        }
    }

    /// §7.3: translated per locale when it or any sub-field is `.localized()`; otherwise synced.
    pub fn translated(&self) -> bool {
        self.localized
            || self
                .fields
                .as_ref()
                .is_some_and(|fs| fs.values().any(Column::translated))
            || self
                .layouts
                .as_ref()
                .is_some_and(|ls| ls.values().any(|fs| fs.values().any(Column::translated)))
    }

    /// The declaration model describes generated values, not nullable backing storage.
    pub fn authored_not_null(&self) -> bool {
        self.not_null || self.derived || self.system && self.name == "uid"
    }

    pub fn authored_has_default(&self) -> bool {
        self.default.is_some()
            || self.auto_increment
            || self.derived
            || self.system && self.name == "uid"
            || self.ty == ColType::Slug && self.source.is_some()
    }

    /// Writers may omit it on insert.
    pub fn optional_on_insert(&self) -> bool {
        self.read_only
            || !self.not_null
            || self.default.is_some()
            || self.auto_increment
            || self.ty == ColType::Slug && self.source.is_some()
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RelKind {
    One,
    Many,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Relation {
    pub name: String,
    pub kind: RelKind,
    /// Target table name.
    pub collection: String,
    pub from: String,
    pub to: String,
    pub optional: bool,
    /// A many-ref's relation goes through its join table: (snapshot key, column naming this side's
    /// row, column naming the related row). `from`/`to` are then both primary keys.
    pub through: Option<(String, String, String)>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum HookKind {
    BeforeCreate,
    AfterCreate,
    BeforeUpdate,
    AfterUpdate,
    BeforeDelete,
    AfterDelete,
    /// A post kind's item became published (created published, updated into it, or a scheduled
    /// item reached its time). Spec §2.8.
    AfterPublish,
}

impl HookKind {
    pub const ALL: [HookKind; 7] = [
        HookKind::BeforeCreate,
        HookKind::AfterCreate,
        HookKind::BeforeUpdate,
        HookKind::AfterUpdate,
        HookKind::BeforeDelete,
        HookKind::AfterDelete,
        HookKind::AfterPublish,
    ];

    pub fn name(self) -> &'static str {
        match self {
            HookKind::BeforeCreate => "beforeCreate",
            HookKind::AfterCreate => "afterCreate",
            HookKind::BeforeUpdate => "beforeUpdate",
            HookKind::AfterUpdate => "afterUpdate",
            HookKind::BeforeDelete => "beforeDelete",
            HookKind::AfterDelete => "afterDelete",
            HookKind::AfterPublish => "afterPublish",
        }
    }

    pub fn parse(s: &str) -> Option<HookKind> {
        HookKind::ALL.into_iter().find(|h| h.name() == s)
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Api {
    pub list: bool,
    pub get: bool,
    pub create: bool,
    pub update: bool,
    pub delete: bool,
}

pub const ROLES: [&str; 5] = ["guest", "subscriber", "author", "editor", "admin"];

/// One access rule (§1.6), as schema.php stores it.
#[derive(Debug, Clone, PartialEq)]
pub enum Access {
    Allow(bool),
    Self_,
    Owner,
    /// "anyone" | "authenticated" | a role.
    Role(String),
    /// Compiled function name.
    Fn(String),
}

impl Access {
    pub fn editor() -> Access {
        Access::Role("editor".into())
    }
    pub fn anyone() -> Access {
        Access::Role("anyone".into())
    }
}

/// Operations a collection's access config covers, in schema.php order.
pub const ACCESS_OPS: [&str; 4] = ["read", "create", "update", "delete"];

/// How comments behave on a kind (spec §2.3). `comments: true` is these defaults.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CommentPolicy {
    /// Anonymous visitors may comment (name and email required).
    pub guests: bool,
    /// `"none"`, `"first-time"` or `"all"`.
    pub moderation: &'static str,
    /// Reply depth, 1 (flat) to 10.
    pub threaded: u8,
    /// Comments close this many days after the item was published; `None` never closes.
    pub close_after_days: Option<u32>,
    /// Comment types allowed on this kind besides normal comments.
    pub types: Vec<String>,
}

impl Default for CommentPolicy {
    fn default() -> Self {
        CommentPolicy {
            guests: false,
            moderation: "first-time",
            threaded: 3,
            close_after_days: None,
            types: Vec::new(),
        }
    }
}

#[derive(Debug, Clone)]
pub struct Collection {
    /// Logical registry key in the config (`shoppingList`).
    pub key: String,
    /// Derived physical name and REST path segment (`shopping_list`).
    pub table: String,
    /// Exact SQL table binding, without the WordPress prefix. Must already exist.
    pub existing_table: Option<String>,
    /// `was: "old"`: the stored name before a rename (spec §2.6).
    pub was: Option<String>,
    /// The registry key an `existingTable` binding was first built under, kept across `was` renames
    /// (the snapshot's `adoptedAs`). The converter names a Zero 0.8 table's collection after it, so
    /// this is the name the 0.8 table's digest is over (`TypedPress\Wp\Guard`). Set by the pipeline.
    pub adopted_as: Option<String>,
    pub label: String,
    pub editor: bool,
    pub drafts: bool,
    pub revisions: bool,
    /// `staged: false` turns staged versions off for a collection (they need its revisions).
    pub staged: bool,
    /// `comments: true`: the post type supports WordPress comments; every other kind has them off.
    pub comments: bool,
    /// What `comments: true | { ... }` asks of comments on this kind (spec §2.3), defaults filled.
    /// `Some` exactly when `comments` is on.
    pub comment_policy: Option<CommentPolicy>,
    pub title: Option<String>,
    pub content: Option<String>,
    pub slug: Option<String>,
    /// `wp.posts` kinds: the field stored as the post's own excerpt (`post_excerpt`).
    pub excerpt: Option<String>,
    /// `wp.posts.extend()`: WordPress's own `post` type, not a post type of TypedPress's.
    pub native: bool,
    pub columns: IndexMap<String, Column>,
    pub relations: IndexMap<String, Relation>,
    /// Hook → compiled PHP function name.
    pub hooks: IndexMap<HookKind, String>,
    /// Required columns the beforeCreate hook assigns (checked after the hook).
    pub before_create_fills: Vec<String>,
    /// op ("read" | "create" | "update" | "delete") → rule.
    pub access: IndexMap<String, Access>,
    pub api: Api,
    pub uid: bool,
    pub sync: Option<Json>,
    pub auth: bool,
    pub agents: bool,
    pub localized: bool,
    /// `url: "/blog/:slug"`: the pattern as written (spec §2.7). Checked by [`crate::kind_url`].
    pub url: Option<String>,
    /// `hierarchical: true` (spec §2.6): items nest under a `parent` and sort by `order`, stored as
    /// the post's own `post_parent` and `menu_order`; `:path` in the url is the parent chain. On a
    /// taxonomy it adds the term's `parent`.
    pub hierarchical: bool,
    /// `taxonomy()`: terms in core's term tables, attached to these kinds
    /// (docs/decisions/2026-11-11-authoring-surface.md, rulings 25-34).
    pub taxonomy: Option<Taxonomy>,
    /// Native Woo resource; no TypedPress storage is registered.
    pub commerce: Option<String>,
    /// `.index(fields, { unique? })`: compound indexes the author declared, as (fields, unique).
    pub indexes: Vec<(Vec<String>, bool)>,
    /// Optional admin presentation metadata (`ui: { icon, singularLabel, description, group, defaultColumns, hidden }`).
    pub ui: Option<Json>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Taxonomy {
    /// The kinds (storage names) with a `terms` field for it, through `on` or `terms()`.
    pub on: Vec<String>,
}

/// A taxonomy's WordPress name: `tp_<storage name>`.
pub fn taxonomy_name(table: &str) -> String {
    format!("tp_{table}")
}

impl Collection {
    pub fn primary_key(&self) -> Option<&Column> {
        self.columns.values().find(|c| c.primary_key)
    }

    /// Is this column a WordPress core post column (not postmeta) on WP?
    pub fn is_core_column(&self, col: &str) -> bool {
        self.columns.get(col).is_some_and(|c| c.primary_key || c.system)
            || (self.hierarchical && matches!(col, "parent" | "order"))
            || self.title.as_deref() == Some(col)
            || self.content.as_deref() == Some(col)
            || self.slug.as_deref() == Some(col)
            || self.excerpt.as_deref() == Some(col)
    }

    /// The WordPress post type an editor kind's items are: `post` for `wp.posts.extend()`, else `tp_<name>`.
    pub fn post_type(&self) -> String {
        if self.native {
            "post".into()
        } else {
            format!("tp_{}", self.table)
        }
    }
}

#[derive(Debug, Clone)]
pub struct Action {
    pub name: String,
    pub input: IndexMap<String, Column>,
    pub handler: String,
    pub access: Access,
    pub agents: bool,
    pub idempotent: bool,
    /// Reads only: the Ability runs over GET, and the facade serves `GET /actions/<name>`.
    pub readonly: bool,
    /// Removes or overwrites data an agent should confirm first (the Ability's `destructive`).
    pub destructive: bool,
    pub signature: Option<Json>,
    /// The handler's result as a JSON Schema, from its TypeScript return type (filled after compile).
    pub output: Option<Json>,
    /// The handler's code reads a WooCommerce order kind, calls an ability that needs a credential's
    /// `personal` grant (an order kind's, `complete-order`), or calls an action that is itself
    /// personal (filled after compile, in `pipeline.rs`). Marked from the code on every branch, not
    /// from one call: a credential without the grant is not shown the action, and a call is refused
    /// where it reaches that read or ability.
    pub personal: bool,
}

/// One `wp-hooks/` handler, registered with `add_filter`/`add_action`.
#[derive(Debug, Clone)]
pub struct WpHook {
    pub hook: String,
    pub filter: bool,
    pub priority: i64,
    pub args: usize,
    pub handler: String,
}

#[derive(Debug, Clone)]
pub struct Job {
    pub input: IndexMap<String, Column>,
    pub handler: String,
    pub retries: u32,
    pub agents: bool,
}

#[derive(Debug, Clone)]
pub struct FileBucket {
    pub accept: Vec<String>,
    pub max_bytes: u64,
    pub access: IndexMap<String, Access>,
}

#[derive(Debug, Clone)]
pub struct Global {
    pub name: String,
    pub label: String,
    pub agents: bool,
    pub fields: IndexMap<String, Column>,
    /// "read" / "update" → rule.
    pub access: IndexMap<String, Access>,
    /// Optional admin presentation metadata (`ui: { icon, description, group, hidden }`).
    pub ui: Option<Json>,
}

/// Where a column lives, for attaching compiled functions after evaluation.
#[derive(Debug, Clone, PartialEq)]
pub enum Owner {
    Collection(String),
    Global(String),
    Action(String),
    Job(String),
    FileBucket(String),
}

/// A column (or a sub-field of one): `[col]`, `[col, sub]`, `[col, layout, sub]`, ...
#[derive(Debug, Clone, PartialEq)]
pub struct ColPath {
    pub owner: Owner,
    pub path: Vec<String>,
}

/// The whole app, keyed by table name.
#[derive(Debug, Clone)]
pub struct Schema {
    pub name: String,
    pub collections: IndexMap<String, Collection>,
    pub globals: IndexMap<String, Global>,
    pub actions: IndexMap<String, Action>,
    pub jobs: IndexMap<String, Job>,
    /// `adminScreen()` exports by screen id (the export name in kebab case): each one the
    /// declaration `tp_admin_register_screen()` takes (`crate::admin_screen`).
    pub screens: IndexMap<String, Json>,
    /// `adminWidget()` exports by widget id (kebab case).
    pub widgets: IndexMap<String, Json>,
    /// `adminDashboard()` exports by dashboard id (kebab case).
    pub dashboards: IndexMap<String, Json>,
    /// `wp-hooks/` handlers, in registration order.
    pub wp_hooks: Vec<WpHook>,
    /// Server components (`tp/components/<file>.tsx#<export>`) → their compiled PHP function.
    pub components: IndexMap<String, String>,
    /// `"use client"` components (generator B): namespace → its store module.
    pub islands: IndexMap<String, Island>,
    /// Components content may use as blocks (`export const blocks` in a component file).
    pub blocks: Vec<crate::content_blocks::Block>,
    /// Imported stylesheets and static assets (`crate::assets`), by output file name below
    /// `tp/app/assets/`.
    pub assets: IndexMap<String, Vec<u8>>,
    /// Intrinsic dimensions and format for imported image assets, by output file name.
    pub assets_meta: IndexMap<String, crate::assets::ImageMeta>,
    /// `tp/app/`: the route table and each route file's compiled exports (docs/spec/rendering.md).
    pub app: Option<App>,
    /// Hooks or WordPress functions hand `WP_Post`/`WP_User` views to compiled code.
    pub wp_views: bool,
    pub files: IndexMap<String, FileBucket>,
    pub webhooks: Vec<Json>,
    pub media: Json,
    pub uploads: Option<Json>,
    pub locales: Option<Json>,
    pub store: Option<Json>,
    /// Compile synced records into the standalone plugin for its WordPress install.
    pub seed: bool,
    /// The build ships the `typedpress-admin` plugin (the default); `defineConfig({ admin: false })` leaves it out.
    pub admin: bool,
    /// `defineConfig({ frontend })` (spec §5); `None` is the default: the theme, or `tp/app`.
    pub frontend: Option<crate::kind_url::Frontend>,
    /// `defineConfig({ output })` (docs/decisions/2026-11-13-static-output.md): `Server` renders
    /// pages per request and prebuilds the ones that say `prerender = true`; `Static` prebuilds them all.
    pub output: Output,
    /// `defineConfig({ publicAuthors: true })`: core's author archives, author routes and user
    /// listings stay public, as WordPress ships them (the users kind's read rule can also allow it).
    pub public_authors: bool,
    /// `defineConfig({ xmlrpc: false })`: XML-RPC answers nothing but Jetpack's connection. `true`
    /// (the default) leaves it as WordPress ships it.
    pub xmlrpc: bool,
    /// `defineConfig({ pings: false })`: pingbacks and trackbacks closed in and out. `true` (the
    /// default) leaves them to WordPress and the site's Discussion settings.
    pub pings: bool,
    /// `defineConfig({ sealed: true })`: the slugs of the config's `plugins`, which run as plugins with
    /// TypedPress's own and the platform's (spec §6; docs/sealed.md). `None` when the site isn't sealed.
    pub sealed: Option<Vec<String>>,
    /// `defineConfig({ runtimeSchema: true })` (spec §2.10): the site's content schema may change
    /// while it runs. Holds what the build read from the lock `typedpress pull` writes.
    pub runtime_schema: Option<RuntimeSchema>,
}

/// `output` in `defineConfig`, Astro's word for it.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub enum Output {
    /// Pages render on WordPress per request; a page that exports `prerender = true` is prebuilt.
    #[default]
    Server,
    /// Every page is prebuilt; `typedpress export` writes a site with no WordPress at runtime.
    Static,
}

/// The site schema version a build contains: zero and no hash before any `typedpress pull`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct RuntimeSchema {
    pub version: u64,
    pub hash: String,
}

impl Schema {
    /// The app plugin carries the project's synced files as its seed: `seed: true`, or a store,
    /// whose products are seeded from their files.
    pub fn ships_seed(&self) -> bool {
        self.seed || self.store.is_some()
    }
}

impl Default for Schema {
    fn default() -> Self {
        Schema {
            name: String::new(),
            collections: IndexMap::new(),
            globals: IndexMap::new(),
            actions: IndexMap::new(),
            jobs: IndexMap::new(),
            screens: IndexMap::new(),
            widgets: IndexMap::new(),
            dashboards: IndexMap::new(),
            wp_hooks: Vec::new(),
            components: IndexMap::new(),
            app: None,
            islands: IndexMap::new(),
            blocks: Vec::new(),
            assets: IndexMap::new(),
            assets_meta: IndexMap::new(),
            wp_views: false,
            files: IndexMap::new(),
            webhooks: Vec::new(),
            media: serde_json::json!({"sizes": {}}),
            uploads: None,
            locales: None,
            store: None,
            seed: false,
            admin: true,
            frontend: None,
            output: Output::Server,
            public_authors: false,
            xmlrpc: true,
            pings: true,
            sealed: None,
            runtime_schema: None,
        }
    }
}

impl Schema {
    pub fn by_key(&self, key: &str) -> Option<&Collection> {
        self.collections.values().find(|c| c.key == key)
    }

    /// The column a path names. Paths come from the extractor, so they always resolve.
    pub fn column_at(&self, at: &ColPath) -> &Column {
        let top = match &at.owner {
            Owner::Collection(t) => &self.collections[t].columns,
            Owner::Global(g) => &self.globals[g].fields,
            Owner::Action(a) => &self.actions[a].input,
            Owner::Job(j) => &self.jobs[j].input,
            Owner::FileBucket(_) => unreachable!("buckets have no columns"),
        };
        let mut col = &top[&at.path[0]];
        let mut rest = &at.path[1..];
        while !rest.is_empty() {
            if let Some(layouts) = &col.layouts {
                col = &layouts[&rest[0]][&rest[1]];
                rest = &rest[2..];
            } else {
                col = &col.fields.as_ref().expect("sub-fields")[&rest[0]];
                rest = &rest[1..];
            }
        }
        col
    }

    pub fn column_mut(&mut self, at: &ColPath) -> &mut Column {
        let top = match &at.owner {
            Owner::Collection(t) => &mut self.collections.get_mut(t).expect("collection").columns,
            Owner::Global(g) => &mut self.globals.get_mut(g).expect("global").fields,
            Owner::Action(a) => &mut self.actions.get_mut(a).expect("action").input,
            Owner::Job(j) => &mut self.jobs.get_mut(j).expect("job").input,
            Owner::FileBucket(_) => unreachable!("buckets have no columns"),
        };
        let mut col = top.get_mut(&at.path[0]).expect("column");
        let mut rest = &at.path[1..];
        while !rest.is_empty() {
            if let Some(layouts) = col.layouts.as_mut() {
                col = layouts
                    .get_mut(&rest[0])
                    .expect("layout")
                    .get_mut(&rest[1])
                    .expect("layout field");
                rest = &rest[2..];
            } else {
                col = col
                    .fields
                    .as_mut()
                    .expect("sub-fields")
                    .get_mut(&rest[0])
                    .expect("sub-field");
                rest = &rest[1..];
            }
        }
        col
    }
}

/// "shopping_list" → "Shopping List".
pub fn title_case(name: &str) -> String {
    name.split(['_', '-'])
        .filter(|w| !w.is_empty())
        .map(|w| {
            let mut c = w.chars();
            match c.next() {
                Some(f) => f.to_uppercase().collect::<String>() + c.as_str(),
                None => String::new(),
            }
        })
        .collect::<Vec<_>>()
        .join(" ")
}

/// A registry key has one deterministic storage/route identity. Split camel case,
/// retaining existing snake-case names and acronym boundaries.
pub fn physical_name(key: &str) -> String {
    let chars: Vec<char> = key.chars().collect();
    let mut out = String::new();
    for (i, c) in chars.iter().enumerate() {
        if c.is_ascii_uppercase()
            && i > 0
            && chars[i - 1] != '_'
            && (chars[i - 1].is_ascii_lowercase()
                || chars[i - 1].is_ascii_digit()
                || chars.get(i + 1).is_some_and(char::is_ascii_lowercase))
        {
            out.push('_');
        }
        out.push(c.to_ascii_lowercase());
    }
    out
}

/// `tp/app/` as the runtime router reads it.
#[derive(Debug, Clone, Default)]
pub struct App {
    /// Most specific first: the router takes the first match.
    pub routes: Vec<AppRoute>,
    pub not_found: Option<AppRoute>,
    /// `login.tsx`: wp-login.php's sign-in view, rendered in the root layout.
    pub login: Option<AppRoute>,
    /// Route file (relative to `tp/`) → its compiled exports.
    pub files: IndexMap<String, AppFile>,
    /// Content-owned pages (`page.md`), by URL path: what the site stores as a WordPress Page.
    pub content: IndexMap<String, crate::content_page::Page>,
    /// The table is a 0.3.0 capture's `routes` config (`extract::legacy::routes`), served as 0.3.0
    /// served it: matched per method, reads trusted, the front end left to the theme.
    pub legacy_routes: bool,
}

#[derive(Debug, Clone)]
pub struct AppRoute {
    /// The folder below `app/`, `/` for the root, or the page's own `route.id`: stable across builds.
    pub id: String,
    /// Where the page set its id itself (`export const route = { id }`, or `id:` in a `page.md`).
    pub explicit_id: Option<crate::diag::Span>,
    /// `/blog/:slug`, `/docs/*rest` (one or more), `/docs/**rest` (zero or more).
    pub pattern: String,
    pub page: Option<String>,
    pub handler: Option<String>,
    pub layouts: Vec<String>,
    pub error: Option<String>,
    /// The stylesheets the page and its layouts import, in link order (`assets::stylesheets`).
    pub styles: Vec<String>,
    /// `@font-face` fonts referenced by this route's stylesheets, emitted as `<link rel="preload">`.
    pub preloads: Vec<crate::assets::FontPreload>,
    /// The page is prebuilt: its file says `prerender = true`, or the config says `output: "static"`.
    pub prerender: bool,
    /// The kind whose `url` this page serves (spec §2.7), with each pattern placeholder's folder
    /// parameter: the page receives that kind's `item`, and a missing one is a 404.
    pub kind: Option<(String, Vec<(String, String)>)>,
}

#[derive(Debug, Clone, Default)]
pub struct AppFile {
    /// The compiled default component.
    pub component: Option<String>,
    /// A literal metadata object, or `{ "fn": name }` for a function.
    pub metadata: Option<Json>,
    /// Read access for a page or layout (`export const guard`).
    pub guard: Option<Access>,
    pub search: Option<AppSearch>,
    /// HTTP method → compiled handler.
    pub methods: IndexMap<String, String>,
    /// Who may call the handlers (`guard`), or the signature authenticating a raw signed webhook.
    /// Exactly one when there are handlers.
    pub handler_guard: Option<Access>,
    pub signature: Option<Json>,
    /// `export const prerender = true | false` (pages): `None` when the page says nothing, so it
    /// follows the config's `output`.
    pub prerender: Option<bool>,
    /// `export async function staticParams()`: the compiled function listing a dynamic page's params.
    pub static_params: Option<String>,
    /// The block template the page renders in (`export const template = "blank"`, or a template
    /// slug), on a page or layout. A layout that imports `<TemplatePart>` places the theme's parts
    /// itself, so it is `"blank"` unless it says otherwise (docs/pages.md "Block templates").
    pub template: Option<String>,
}

#[derive(Debug, Clone)]
pub struct AppSearch {
    pub fields: IndexMap<String, Column>,
}

/// An imported static asset exposed to a `"use client"` store through `getConfig(ns).assets`.
#[derive(Debug, Clone)]
pub enum IslandAsset {
    Url(String),
    Meta {
        file: String,
        width: u32,
        height: u32,
        format: String,
    },
}

/// One compiled `"use client"` component: its store module and the server actions it calls.
#[derive(Debug, Clone)]
pub struct Island {
    /// File name under the app plugin's `tp/app/client/`: `<stem>.<12 hex>.js`, the hex being the
    /// start of the SHA-256 of [`Island::shipped`], the exact bytes served under that name.
    pub file: String,
    /// The generated store module, readable.
    pub source: String,
    /// What the app plugin ships: `source` minified (`crate::minify`).
    pub shipped: String,
    pub actions: Vec<String>,
    /// Static assets imported by this client component, passed via `wp_interactivity_config`.
    pub assets: std::collections::BTreeMap<String, IslandAsset>,
    /// The store keeps `useLive` reads current: the page also loads `@typedpress/live`.
    pub live: bool,
}
