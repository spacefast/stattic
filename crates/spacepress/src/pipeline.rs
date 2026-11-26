//! The compiler in memory: a loaded program and the previous snapshot in, every generated text out.
//! Nothing here reads or writes a project; `commands::build` does that around it for the CLI and
//! `wasm` hands it sources from a browser.

mod app;
mod exports;
mod route_plan;
use crate::diag::{Diag, Span};
use crate::extract::Slot;
use crate::schema::{Access, DefaultKind, Owner};
use crate::{client, compile, diag, eval, extract, loader, migrate, schema, schema_php, wp_plugins};
use std::path::Path;

type HookFunctions = indexmap::IndexMap<(String, schema::HookKind), Vec<(Option<usize>, String)>>;

pub struct Built {
    pub plugins: Option<wp_plugins::Config>,
    /// Every file under `sp/_generated/client/`.
    pub client_files: Vec<(String, Vec<u8>)>,
    /// `sp/_generated/openapi.json`: the app's HTTP API, from the same schema as the client.
    pub openapi: serde_json::Value,
    pub schema: schema::Schema,
    pub functions_php: String,
    pub snapshot: serde_json::Value,
    /// New migration folders (`<id>/{ops.json,migration.sql}`); empty when the SQL schema did not change.
    pub migrations: Vec<migrate::MigrationEntry>,
    pub gen_ts: String,
    /// `sp/_generated/routes.d.ts`, when the app has `sp/app/` routes.
    pub routes_ts: Option<String>,
    /// Per-route `./$types.d.ts` declarations under `<dir>/_generated/types/`.
    pub route_type_files: Vec<(String, String)>,
    /// Zero-server-code `<dir>/_generated/api.js` runtime bridge.
    pub api_js: String,
    /// Strongly typed `<dir>/_generated/api.d.ts` declarations matching `api_js`.
    pub api_d_ts: String,
    /// Destructive steps in the new migrations.
    pub warnings: Vec<String>,
    /// Compile-time OXC AST lint warnings (e.g. `capital_P_dangit`), with auto-fix spans.
    pub lint_warnings: Vec<crate::lint::Warning>,
    /// Declared WordPress plugins outside `wp_plugins/supported.json`; they still install.
    pub unsupported_plugins: Vec<String>,
    pub hints: Vec<String>,
    /// `sp/_generated/plugins.lock.json` as this build settled it (None without plugins or a lock).
    pub plugin_lock: Option<serde_json::Value>,
    /// What changed in the lock, one line each, for the command line.
    pub plugin_changes: Vec<String>,
    /// Each plugin's locked and inferred grant and read→send flows, for `spacepress check --json`.
    pub plugin_report: serde_json::Value,
    /// Abilities (and later hooks and functions) the compiled code uses from WordPress.
    pub wordpress_uses: Vec<compile::WordPressUse>,
    /// With `runtimeSchema`: which fields the project's source names (`sp/app/references.php`).
    pub references: Option<serde_json::Value>,
    /// `sp/theme.json`, when the project has one: shipped as the app plugin's `sp/app/theme.json`.
    pub theme: Option<serde_json::Value>,
    /// `sp/_generated/theme.css`: Tailwind v4 tokens on WordPress's preset
    /// variables ([`crate::theme`]).
    pub theme_css: String,
    /// Resolved project directory layout (`defineConfig({ dir })`, default `"sp"`).
    pub layout: crate::layout::Layout,
}

/// The schema ledger, committed with the project: `snapshot.json` and the numbered SQL that
/// `Wp\Migrator` applies. Lives with the other generated files (spec §1).
pub const LEDGER: &str = "sp/_generated/migrations";
/// Where the ledger lived before `_generated/`. A project that still has it is refused (see
/// [`moved_ledger`]) rather than built as a first build, which would plan every table again.
pub const OLD_LEDGER: &str = "sp/migrations";

/// The refusal for a project whose ledger is still at [`OLD_LEDGER`].
pub fn moved_ledger() -> Diag {
    moved_ledger_for(&crate::layout::Layout::default())
}

pub fn moved_ledger_for(layout: &crate::layout::Layout) -> Diag {
    let old = layout.old_ledger();
    let ledger = layout.migrations();
    let generated = layout.generated();
    Diag::new(Span::NONE, format!("{old}/ moved to {ledger}/")).help(format!(
        "move it with `mkdir -p {generated} && rmdir {ledger} 2>/dev/null; git mv {old} {ledger}`, then build again"
    ))
}

/// The previous migrations in the ledger, folded into the baseline schema state.
#[derive(Debug)]
pub struct Previous {
    /// Existing migration IDs from `<ledger>/<id>/ops.json` in sorted order.
    pub ids: Vec<String>,
    /// Folded schema state produced by replaying every committed `ops.json`.
    pub folded: migrate::FoldedState,
    /// Snapshot view of the folded state.
    pub snapshot: serde_json::Value,
    /// Refuse any plugin grant `sp/_generated/plugins.lock.json` doesn't already hold (CI, `--frozen`).
    pub frozen: bool,
}

impl Previous {
    /// A first build: empty baseline, no prior migrations.
    pub fn none() -> Self {
        let folded = migrate::FoldedState::empty();
        let snapshot = folded.to_snapshot();
        Previous {
            ids: Vec::new(),
            folded,
            snapshot,
            frozen: false,
        }
    }

    /// Reconstructs the baseline by folding every `(id, ops_json)` folder entry in sorted ID order.
    /// Incompatible parallel-branch edits fail here with a diagnostic naming both migration IDs.
    pub fn from_entries(entries: Vec<(String, serde_json::Value)>) -> Result<Self, Diag> {
        let folded = migrate::fold(&entries)?;
        let snapshot = folded.to_snapshot();
        let ids = folded.history.clone();
        Ok(Previous {
            ids,
            folded,
            snapshot,
            frozen: false,
        })
    }
}

/// Compiles a loaded program. `project` names the app (its directory name becomes the plugin's);
/// `previous` is asked for the last build once the config has compiled, so a config error is reported
/// before a snapshot problem; `runtime` looks up embedded `runtime/` files for the client (none: the
/// generated `types.ts` and `schema.ts` only).
pub fn build(
    project: &Path,
    program: &loader::Program,
    previous: impl FnOnce() -> Result<Previous, Diag>,
    runtime: impl Fn(&str) -> Option<&'static [u8]>,
) -> Result<Built, Diag> {
    // A checkout from before `sp/_generated/` would have its locks ignored (and replaced) and an old
    // client loaded as app code.
    let root = program.modules[program.entry]
        .path
        .parent()
        .expect("the entry has a directory");
    if let Some((message, help)) = crate::layout::old_in_checkout_for(|p| program.fs.exists(p), root, &program.layout) {
        return Err(Diag::new(Span::NONE, message).help(help).phase(diag::Code::Schema));
    }
    let mut ev = eval::Evaluator::new(program);
    let config = ev.run().map_err(|d| d.phase(diag::Code::Evaluation))?;
    let config_span = Span {
        file: program.modules[program.entry].file,
        start: 0,
        end: 0,
    };
    exports::register(program, &mut ev, &config).map_err(|d| d.phase(diag::Code::Schema))?;
    let runtime_lock = crate::runtime_schema::declare(program, &mut ev, &config)?;
    let dir_name = project
        .file_name()
        .map(|n| extract::slugify_name(&n.to_string_lossy()))
        .unwrap_or_else(|| "app".into());
    let mut ext =
        extract::extract(&config, &mut ev, config_span, &dir_name).map_err(|d| d.phase(diag::Code::Schema))?;
    // Hosts adapting an old capture record the original root as an alias. Resolve sync
    // directories after evaluation, including patterns supplied through constants or helpers.
    if loader::legacy_capture(&*program.fs, root) {
        for collection in ext.schema.collections.values_mut() {
            if let Some(sync) = &mut collection.sync
                && let Some(dir) = sync["dir"].as_str()
            {
                if dir == "tp" {
                    sync["dir"] = crate::layout::ROOT.into();
                } else if let Some(rest) = dir.strip_prefix("tp/") {
                    sync["dir"] = format!("{}/{rest}", crate::layout::ROOT).into();
                }
            }
        }
    }
    wp_hooks(program, &mut ev, &mut ext).map_err(|d| d.phase(diag::Code::Schema))?;
    let blocks = components(program, &mut ev, &mut ext).map_err(|d| d.phase(diag::Code::Schema))?;
    app::register(program, &mut ev, &mut ext).map_err(|d| d.phase(diag::Code::Schema))?;
    crate::content_page::register(program, &mut ext).map_err(|d| d.phase(diag::Code::Schema))?;
    crate::commerce::pages(&ext.schema, config_span).map_err(|d| d.phase(diag::Code::Schema))?;
    crate::runtime_schema::apply(runtime_lock.as_ref(), &mut ev, &mut ext)?;
    // A query's, mutation's or job's input is its handler's parameter type: read before anything
    // reads inputs (admin screens, the compiled handlers, the client).
    let inputs = {
        let schema = ext.schema.clone();
        let mut c = compile::Compiler::new(&schema, &mut ev, &ext, program);
        c.infer_inputs(&ext.roots).map_err(|d| d.phase(diag::Code::Compile))?
    };
    compile::apply_inputs(&mut ext.schema, inputs);
    extract::screens(&config, &mut ev, config_span, &mut ext).map_err(|d| d.phase(diag::Code::Schema))?;
    let mut schema = ext.schema.clone();
    let roots = std::mem::take(&mut ext.roots);
    let action_results;
    let component_props;
    let islands;
    let wordpress_uses;
    let queried;
    let route_effects;
    let route_calls;
    let catalog = crate::wordpress::abilities::Catalog::new(&schema, &read_lock(program)?);
    let theme = read_theme(program)?;
    // Each collection hook kind: its compiled functions, by owning plugin (None: the app).
    let mut hooks: HookFunctions = indexmap::IndexMap::new();
    let plugin_lock_rel = program.layout.plugins_lock();
    let plugin_lock = read_plugin_lock(program, &project.join(&plugin_lock_rel), &plugin_lock_rel)?;
    let previous_value;
    let mut personal_actions = Vec::new();
    let (functions, slots, settled) = {
        let mut c = compile::Compiler::new(&schema, &mut ev, &ext, program);
        c.abilities = catalog.clone();
        c.resolve_json_types().map_err(|d| d.phase(diag::Code::Compile))?;
        let slots = c.compile_roots(&roots).map_err(|d| d.phase(diag::Code::Compile))?;
        action_results = std::mem::take(&mut c.action_results);
        component_props = std::mem::take(&mut c.component_props);
        islands = std::mem::take(&mut c.islands);
        wordpress_uses = std::mem::take(&mut c.wordpress_uses);
        queried = std::mem::take(&mut c.queried);
        for ((slot, name), root) in slots.iter().zip(&roots) {
            if let Slot::Hook { table, kind } = slot {
                hooks
                    .entry((table.clone(), *kind))
                    .or_default()
                    .push((root.plugin, name.clone()));
            }
        }
        // Plugins first, in `use` order, then the app's own hook.
        for ((table, kind), list) in &mut hooks {
            list.sort_by_key(|(plugin, _)| plugin.unwrap_or(usize::MAX));
            if list.len() > 1 {
                let names: Vec<String> = list.iter().map(|(_, n)| n.clone()).collect();
                let chain = c.chain(table, *kind, &names);
                *list = vec![(None, chain)];
            }
        }
        // The lock settles before anything is emitted, so a refused plugin generates nothing.
        let reach = |name: &String| {
            let mut reached = std::collections::BTreeSet::from([name.clone()]);
            let mut stack = vec![name.clone()];
            while let Some(f) = stack.pop() {
                for callee in c.calls.get(&f).into_iter().flatten() {
                    if reached.insert(callee.clone()) {
                        stack.push(callee.clone());
                    }
                }
            }
            reached
        };
        let mut handlers = Vec::new();
        // Each action: its ability's name, and every function its handler reaches.
        let app = crate::wordpress::abilities::kebab(&schema.name);
        let mut actions = Vec::new();
        for ((slot, name), root) in slots.iter().zip(&roots) {
            if let Some(plugin) = root.plugin {
                handlers.push((plugin, name.clone(), reach(name)));
            }
            if let Slot::ActionHandler { action } = slot {
                let ability = format!("{app}/{}", crate::wordpress::abilities::kebab(action));
                actions.push((action.clone(), ability, reach(name)));
            }
        }
        // An action is personal when its code reads orders or calls an ability that needs the grant,
        // or calls an action that is. Repeated until nothing changes, so a chain of actions calling
        // actions is followed to its end.
        let mut personal_abilities = std::collections::BTreeSet::new();
        loop {
            let found: Vec<_> = actions
                .iter()
                .filter(|(_, ability, reached)| {
                    !personal_abilities.contains(ability)
                        && reached.iter().any(|f| {
                            c.personal.contains(f)
                                || c.ability_calls
                                    .get(f)
                                    .is_some_and(|called| called.iter().any(|a| personal_abilities.contains(a)))
                        })
                })
                .map(|(action, ability, _)| (action.clone(), ability.clone()))
                .collect();
            if found.is_empty() {
                break;
            }
            for (action, ability) in found {
                personal_abilities.insert(ability);
                personal_actions.push(action);
            }
        }
        let prev = previous()?;
        let settled = crate::plugins::settle(&ext.uses, &c.uses, &handlers, plugin_lock.as_ref(), prev.frozen)
            .map_err(|d| d.phase(diag::Code::Compile))?;
        previous_value = prev;
        for ((_, hosts), function) in settled.hosts.iter().zip(&c.host_functions) {
            let value = match hosts {
                None => "null".to_string(),
                Some(hosts) => format!(
                    "[{}]",
                    hosts.iter().map(|h| compile::php_str(h)).collect::<Vec<_>>().join(", ")
                ),
            };
            c.output
                .push_str(&format!("/**\n * Return the plugin's permitted outbound HTTP hosts.\n *\n * @return string[]|null\n */\nfunction {function}(): ?array {{\n\treturn {value};\n}}\n\n"));
        }
        route_effects = std::mem::take(&mut c.route_effects);
        route_calls = std::mem::take(&mut c.calls);
        (std::mem::take(&mut c.output), slots, settled)
    };
    for ((table, kind), list) in hooks {
        let (_, name) = list.into_iter().next().expect("one hook per kind");
        schema
            .collections
            .get_mut(&table)
            .expect("collection")
            .hooks
            .insert(kind, name);
    }
    for action in personal_actions {
        schema.actions.get_mut(&action).expect("action").personal = true;
    }
    for (slot, name) in slots {
        match slot {
            Slot::Hook { .. } => {}
            Slot::Default { at } => schema.column_mut(&at).default = Some(DefaultKind::Fn(name)),
            Slot::OnUpdate { at } => schema.column_mut(&at).on_update = Some(DefaultKind::Fn(name)),
            Slot::Validate { at } => schema.column_mut(&at).rules.validate = Some(name),
            Slot::FieldAccess { at, op } => {
                schema.column_mut(&at).access.insert(op, Access::Fn(name));
            }
            Slot::JobHandler { job } => schema.jobs.get_mut(&job).expect("job").handler = name,
            Slot::WpHook { index } => schema.wp_hooks[index].handler = name,
            Slot::Component { name: component } => {
                schema.components.insert(component, name);
            }
            Slot::Email { file } => {
                schema.components.insert(file, name);
            }
            Slot::AppPage { .. }
            | Slot::AppMetadata { .. }
            | Slot::AppGuard { .. }
            | Slot::AppAccess { .. }
            | Slot::AppStaticParams { .. }
            | Slot::AppHandler { .. } => {
                app::fill(&mut schema, &slot, &name);
            }
            Slot::ActionHandler { action } => schema.actions.get_mut(&action).expect("action").handler = name,
            Slot::Access { owner, op } => {
                let rule = Access::Fn(name);
                match owner {
                    Owner::Collection(t) => {
                        schema
                            .collections
                            .get_mut(&t)
                            .expect("collection")
                            .access
                            .insert(op, rule);
                    }
                    Owner::Global(g) => {
                        schema.globals.get_mut(&g).expect("global").access.insert(op, rule);
                    }
                    Owner::Action(a) => schema.actions.get_mut(&a).expect("action").access = rule,
                    Owner::FileBucket(f) => {
                        schema.files.get_mut(&f).expect("bucket").access.insert(op, rule);
                    }
                    Owner::Job(_) => unreachable!("jobs have no access rules"),
                }
            }
        }
    }
    // Generator B: each `"use client"` component's store module, content-hashed.
    for analysis in islands {
        let source = analysis.store().map_err(|d| d.phase(diag::Code::Compile))?;
        // Named by the bytes the app plugin serves, which are immutable under that name.
        let shipped = crate::minify::module(&source);
        let hash: String = {
            use sha2::{Digest, Sha256};
            crate::hex(&Sha256::digest(shipped.as_bytes())[..6])
        };
        let stem = analysis
            .namespace
            .rsplit('/')
            .next()
            .unwrap_or("island")
            .to_ascii_lowercase();
        schema.islands.insert(
            analysis.namespace.clone(),
            schema::Island {
                file: format!("{stem}.{hash}.js"),
                source,
                shipped,
                actions: analysis.server_actions.iter().cloned().collect(),
                uploads: analysis.file_uploads.iter().cloned().collect(),
                assets: analysis.assets.clone(),
                live: !analysis.live.is_empty(),
            },
        );
    }
    // Components content may use as blocks, with their props as the block's attributes.
    for (component, span) in blocks {
        let props = component_props
            .get(&component)
            .cloned()
            .unwrap_or(crate::types::Ty::Obj(Default::default()));
        let block = crate::content_blocks::block(&schema.name, &component, &props, span)
            .map_err(|d| d.phase(diag::Code::Compile))?;
        schema.blocks.push(block);
    }
    if let Some(app) = &schema.app {
        self::app::viewer_calls(program, app, &wordpress_uses).map_err(|d| d.phase(diag::Code::Compile))?;
    }
    route_plan::build(&mut schema, &route_effects, &route_calls);
    schema.wp_views =
        !wordpress_uses.is_empty() && wordpress_uses.iter().any(|u| u.kind != compile::WordPressKind::Ability);
    // Hooks run in a fixed order per collection; sort for stable output.
    for c in schema.collections.values_mut() {
        c.hooks
            .sort_by_key(|k, _| schema::HookKind::ALL.iter().position(|h| h == k));
    }

    let functions_php = format!(
        "<?php\n/**\n * Backend functions compiled from TypeScript sources.\n *\n * Generated by `spacepress build` from spacepress.config.ts. Do not edit.\n *\n * @package SpacePress\n */\n\ndeclare(strict_types=1);\n\nnamespace SpacePressApp;\n\n{}",
        functions + &crate::content_page::functions_php(&schema)
    );
    let functions_php = crate::php_layout::format(&functions_php);
    let previous = previous_value;
    let snapshot = migrate::snapshot_with(&schema, &queried);
    for (stored, prev_def) in &previous.folded.tables {
        let successor = snapshot["tables"].get(stored);
        if let Some(next) = successor
            && prev_def["existingTable"] != next["existingTable"]
        {
            return Err(Diag::new(
                config_span,
                format!("existingTable binding for `{stored}` cannot change after the first migration; move data explicitly before changing storage"),
            )
            .phase(diag::Code::Schema));
        }
        if let Some(next) = successor
            && prev_def["editor"] == false
            && prev_def["auth"] != true
            && next["editor"] == true
        {
            let kind = ext
                .collections
                .iter()
                .find(|c| snapshot["tables"].get(c.table()) == Some(next));
            let key = kind.map_or(stored.as_str(), |c| schema.collections[c.table()].key.as_str());
            return Err(Diag::new(
                kind.map_or(config_span, |c| c.span),
                format!("`{key}` is stored as a SQL table, and collection() is always WordPress posts"),
            )
            .help("declare it with table() again: its rows stay where they are and no migration is written")
            .phase(diag::Code::Schema));
        }
        if let Some(commerce) = schema.collections.get(stored).and_then(|c| c.commerce.as_deref()) {
            let kind = ext.collections.iter().find(|c| c.table() == stored);
            return Err(Diag::new(
                kind.map_or(config_span, |c| c.span),
                format!("`{stored}` is stored as a SQL table, and {commerce}() is always WooCommerce storage"),
            )
            .help("give the commerce kind another name, or retire the table in one deploy and add the commerce kind in the next")
            .phase(diag::Code::Schema));
        }
    }
    let mut migrations = Vec::new();
    let mut warnings = Vec::new();
    if let Some((entry, notes)) = migrate::plan_forward_only(&previous.folded, &snapshot, &previous.ids)? {
        migrations.push(entry);
        warnings.extend(notes);
    }
    // Each action's Ability publishes its result type as an output schema (spec §3a).
    for (name, ty) in &action_results {
        if let Some(action) = schema.actions.get_mut(name) {
            action.output = crate::wordpress::abilities::ty_schema(ty);
        }
    }
    let client_files = client::files(&schema, &action_results, runtime);
    let api_js = client::api_js(&schema);
    let api_d_ts = client::api_d_ts(&schema);
    let openapi = crate::openapi::document(&schema);
    let unsupported_plugins = ext.plugins.as_ref().map(wp_plugins::unsupported).unwrap_or_default();
    // Only a project that imports "php/wordpress" maps it in tsconfig, so only then is it augmented.
    let uses_wordpress = program.modules.iter().any(|m| {
        m.body
            .iter()
            .any(|s| matches!(s, crate::ir::Stmt::Import { source, .. } if source == loader::WORDPRESS_MODULE))
    });
    let mut gen_ts = schema_php::gen_ts(&schema);
    if uses_wordpress {
        gen_ts += &crate::wordpress::abilities::gen_ts(&catalog);
    }
    let routes_ts = crate::app::routes_d_ts(
        &program.app,
        &schema
            .collections
            .values()
            .filter_map(|c| c.url.clone().map(|u| (c.key.clone(), u)))
            .collect::<Vec<_>>(),
    );
    let route_type_files = crate::app::route_type_files(&program.app, &program.layout);
    let references = schema
        .runtime_schema
        .is_some()
        .then(|| crate::runtime_schema::references(&schema, &program.root));
    let theme_css = crate::theme::tokens(theme.as_ref().unwrap_or(&serde_json::Value::Null));
    let lint_warnings = crate::lint::check_project_with_layout(root, &program.layout);
    Ok(Built {
        theme,
        theme_css,
        layout: program.layout.clone(),
        references,
        plugins: ext.plugins,
        unsupported_plugins,
        client_files,
        api_js,
        api_d_ts,
        openapi,
        schema,
        functions_php,
        snapshot,
        migrations,
        gen_ts,
        routes_ts,
        route_type_files,
        warnings,
        lint_warnings,
        hints: ext.hints,
        plugin_lock: settled.lock,
        plugin_changes: settled.changes,
        plugin_report: settled.report,
        wordpress_uses,
    })
}

/// Each `sp/wp-hooks/` file becomes a root: its default export is the handler, an optional
/// `export const priority = <integer>` its priority (default 10, so a hook's files run in file order).
/// The hook must be in the catalog; the handler is typed from it when it compiles.
fn wp_hooks(program: &loader::Program, ev: &mut eval::Evaluator, ext: &mut extract::Extracted) -> Result<(), Diag> {
    use crate::value::Value;
    for file in &program.wp_hooks {
        let module = &program.modules[file.module];
        let span = Span {
            file: module.file,
            start: 0,
            end: 0,
        };
        let Some(hook) = crate::wordpress::hook(&file.hook) else {
            let mut d = Diag::new(
                span,
                format!("`{}` is not a WordPress hook SpacePress types", file.hook),
            );
            d = match diag::suggest(&file.hook, crate::wordpress::hook_names()) {
                Some(s) => d.help(format!(
                    "did you mean `{s}`? Rename the file or directory under sp/wp-hooks/"
                )),
                None => d.help(format!(
                    "sp/wp-hooks/ supports: {}",
                    crate::wordpress::hook_names().collect::<Vec<_>>().join(", ")
                )),
            };
            return Err(d);
        };
        let exports = ev.run_module(file.module)?;
        let Some(Value::Closure(closure)) = exports.get("default") else {
            return Err(
                Diag::new(span, "a first/wp-hooks/ file needs a default-exported handler function").help(format!(
                    "write `export default function ({}) {{ ... }}`",
                    hook.params
                        .iter()
                        .map(|(n, _)| n.as_str())
                        .collect::<Vec<_>>()
                        .join(", ")
                )),
            );
        };
        let priority = match exports.get("priority") {
            None => 10,
            Some(Value::Num(n)) if n.fract() == 0.0 && n.abs() < 1e9 => *n as i64,
            Some(_) => {
                return Err(Diag::new(span, "`priority` must be an integer")
                    .help("WordPress runs lower priorities first; the default is 10"));
            }
        };
        for name in exports.keys() {
            if name != "default" && name != "priority" {
                return Err(Diag::new(
                    span,
                    format!("a first/wp-hooks/ file exports only `default` and `priority`, not `{name}`"),
                )
                .help("move shared code into a module both files import"));
            }
        }
        let index = ext.schema.wp_hooks.len();
        ext.schema.wp_hooks.push(crate::schema::WpHook {
            hook: file.hook.clone(),
            filter: hook.filter,
            priority,
            args: hook.params.len(),
            handler: String::new(),
        });
        ext.roots.push(extract::Root {
            closure: closure.clone(),
            slot: Slot::WpHook { index },
            name: format!("wp_hook__{}_{}", file.hook, file.order),
            plugin: None,
        });
    }
    Ok(())
}

/// Every function exported from a `sp/components/` file under a capitalized name becomes a
/// compiled component, called with one props object (docs/decisions/2026-11-08-tsx-to-php.md).
/// The capital is React's own rule for what a JSX tag can name; other exports are helpers and are
/// ignored, as in any module.
///
/// A file may also say which of its components content can use as blocks:
/// `export const blocks = ["Callout"]` (`crate::content_blocks`).
fn components(
    program: &loader::Program,
    ev: &mut eval::Evaluator,
    ext: &mut extract::Extracted,
) -> Result<Vec<(String, Span)>, Diag> {
    use crate::value::Value;
    let mut blocks = Vec::new();
    // `"use client"` components compile first: a server component that renders one, in a file
    // that sorts before it, then calls its compiled body instead of compiling it as a plain helper.
    let mut client_roots = Vec::new();
    let mut server_roots = Vec::new();
    for (path, module) in &program.components {
        let exports = ev.run_module(*module)?;
        let span = Span {
            file: program.modules[*module].file,
            start: 0,
            end: 0,
        };
        if let Some(listed) = exports.get("blocks") {
            let names = match listed {
                Value::Array(a) => a
                    .borrow()
                    .iter()
                    .map(|v| match v {
                        Value::Str(s) => Ok(s.to_string()),
                        _ => Err(()),
                    })
                    .collect::<Result<Vec<_>, _>>()
                    .ok(),
                _ => None,
            };
            let Some(names) = names else {
                return Err(Diag::new(span, "`blocks` is a list of this file's component names")
                    .help("write `export const blocks = [\"Callout\"]`"));
            };
            for name in names {
                if !matches!(exports.get(&name), Some(Value::Closure(_)))
                    || !name.starts_with(|c: char| c.is_ascii_uppercase())
                {
                    return Err(Diag::new(
                        span,
                        format!("`blocks` names `{name}`, which {path} doesn't export as a component"),
                    )
                    .help("list components this file exports, by their capitalized name"));
                }
                blocks.push((format!("{path}#{name}"), span));
            }
        }
        for (export, value) in &exports {
            let Value::Closure(closure) = value else { continue };
            if !export.starts_with(|c: char| c.is_ascii_uppercase()) {
                continue;
            }
            let stem = path.trim_end_matches(".tsx");
            let roots = if program.modules[*module].use_client {
                &mut client_roots
            } else {
                &mut server_roots
            };
            roots.push(extract::Root {
                closure: closure.clone(),
                slot: Slot::Component {
                    name: format!("{path}#{export}"),
                },
                name: format!("component__{}__{export}", extract::slugify_name(stem).replace('-', "_")),
                plugin: None,
            });
        }
    }
    // Templates compile before the handlers that render them, so `mail.render()` finds their props.
    let mut email_roots = Vec::new();
    for (path, module) in &program.emails {
        let exports = ev.run_module(*module)?;
        let span = Span {
            file: program.modules[*module].file,
            start: 0,
            end: 0,
        };
        let Some(Value::Closure(closure)) = exports.get("default") else {
            return Err(Diag::new(span, format!("{path} needs a default component"))
                .help("write `export default function Welcome(props: WelcomeProps) { ... }`"));
        };
        if closure.func.is_async || closure.func.params.len() > 1 {
            return Err(Diag::new(
                span,
                "an email template is a synchronous server component with one props object",
            ));
        }
        let stem = path.trim_end_matches(".tsx");
        email_roots.push(extract::Root {
            closure: closure.clone(),
            slot: Slot::Email { file: path.clone() },
            name: extract::slugify_name(stem).replace('-', "_"),
            plugin: None,
        });
    }
    ext.roots.splice(0..0, email_roots);
    ext.roots.extend(client_roots);
    ext.roots.extend(server_roots);
    Ok(blocks)
}

/// The committed plugin grant, read like the other compiler inputs; absent until the first build.
fn read_plugin_lock(program: &loader::Program, path: &Path, lock_rel: &str) -> Result<Option<serde_json::Value>, Diag> {
    if !program.fs.exists(path) {
        return Ok(None);
    }
    let bad = |e: String| Diag::new(Span::NONE, format!("{lock_rel}: {e}")).code(diag::Code::PluginCapability);
    let text = program.fs.read_to_string(path).map_err(|e| bad(e.to_string()))?;
    serde_json::from_str(&text).map(Some).map_err(|e| bad(e.to_string()))
}

/// `<dir>/_generated/abilities.json` beside the program's `spacepress.config.ts`, through the program's file system,
/// so a hosted source bundle (rooted at its own module directory, whatever `project` names) and the
/// browser read the same lock the command line does. Absent means no locked abilities.
fn read_lock(program: &loader::Program) -> Result<Vec<crate::wordpress::abilities::Locked>, Diag> {
    let entry = &program.modules[program.entry].path;
    let lock_rel = program.layout.abilities_lock();
    let path = entry.parent().expect("the entry has a directory").join(&lock_rel);
    let path = path.as_path();
    if !program.fs.exists(path) {
        return Ok(Vec::new());
    }
    let text = program
        .fs
        .read_to_string(path)
        .map_err(|e| Diag::new(Span::NONE, format!("can't read {lock_rel}: {e}")))?;
    crate::wordpress::abilities::parse_lock(&text).map_err(|m| Diag::new(Span::NONE, m).phase(diag::Code::Import))
}

/// `<dir>/theme.json` beside the program's `spacepress.config.ts`, read like the abilities lock so the
/// command line, the browser and a hosted source bundle see the same file. Absent means no styles of
/// the project's own.
fn read_theme(program: &loader::Program) -> Result<Option<serde_json::Value>, Diag> {
    let entry = &program.modules[program.entry].path;
    let theme_rel = program.layout.theme_file();
    let path = entry.parent().expect("the entry has a directory").join(&theme_rel);
    if !program.fs.exists(&path) {
        return Ok(None);
    }
    let text = program
        .fs
        .read_to_string(&path)
        .map_err(|e| Diag::new(Span::NONE, format!("can't read {theme_rel}: {e}")).phase(diag::Code::Import))?;
    crate::theme::parse(&text)
        .map(Some)
        .map_err(|m| Diag::new(Span::NONE, m).phase(diag::Code::Schema))
}

impl Built {
    /// One line for the command line: what was built.
    pub fn summary(&self) -> String {
        let s = &self.schema;
        format!(
            "{} collections, {} tables, {} user projections, {}{}{} globals, {} hooks, {} actions",
            s.collections.values().filter(|c| c.editor).count(),
            s.collections
                .values()
                .filter(|c| !c.editor && !c.auth && c.taxonomy.is_none() && c.commerce.is_none())
                .count(),
            s.collections.values().filter(|c| c.auth).count(),
            match s.collections.values().filter(|c| c.taxonomy.is_some()).count() {
                0 => String::new(),
                n => format!("{n} taxonomies, "),
            },
            match s
                .collections
                .values()
                .filter(|c| c.commerce.is_some() && c.taxonomy.is_none())
                .count()
            {
                0 => String::new(),
                n => format!("{n} commerce kinds, "),
            },
            s.globals.len(),
            s.collections.values().map(|c| c.hooks.len()).sum::<usize>(),
            s.actions.len()
        )
    }
}

#[cfg(test)]
mod tests {
    use super::Previous;
    use serde_json::json;

    #[test]
    fn folds_committed_ops_entries_in_sorted_id_order() {
        let m1 = (
            "20261121180000_7k9m2x_init".to_string(),
            json!({
                "version": 1,
                "id": "20261121180000_7k9m2x_init",
                "ops": [{
                    "op": "createTable",
                    "table": "notes",
                    "def": { "editor": false, "drafts": false, "columns": {
                        "id": { "type": "integer", "notNull": true, "primaryKey": true, "autoIncrement": true, "unique": false, "max": null, "default": null, "references": null }
                    }}
                }]
            }),
        );
        let m2 = (
            "20261121180100_3p4q5r_add_notes_body".to_string(),
            json!({
                "version": 1,
                "id": "20261121180100_3p4q5r_add_notes_body",
                "ops": [{
                    "op": "addColumn",
                    "table": "notes",
                    "column": "body",
                    "def": { "type": "text", "notNull": false, "primaryKey": false, "autoIncrement": false, "unique": false, "max": null, "default": null, "references": null }
                }]
            }),
        );
        // Out-of-order input still folds chronologically by sortable ID.
        let prev = Previous::from_entries(vec![m2, m1]).unwrap();
        assert_eq!(
            prev.ids,
            vec!["20261121180000_7k9m2x_init", "20261121180100_3p4q5r_add_notes_body"]
        );
        assert!(prev.folded.tables["notes"]["columns"]["body"].is_object());
    }

    #[test]
    fn unsupported_ops_version_is_refused() {
        let err = Previous::from_entries(vec![(
            "20261121180000_7k9m2x_init".to_string(),
            json!({ "version": 99, "ops": [] }),
        )])
        .unwrap_err();
        assert!(err.message.contains("unsupported version 99"), "{}", err.message);
    }
}
