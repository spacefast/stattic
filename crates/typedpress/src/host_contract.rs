//! What a host (Spacefast, or any platform that compiles and mounts TypedPress apps itself) relies on,
//! stated once. The hosted manifest carries it (`bundle::compile_tree`), and `www/content/docs/reference/host-contract.md`
//! is generated from it (`tests/host_contract.rs` fails when the doc is stale).
//!
//! Bump [`HOST_CONTRACT`] only when something here changes incompatibly for a host: a manifest field
//! it reads changes meaning, a path or name below moves, the mount entry or the jobs command changes
//! shape, or a hook changes its arguments. Additions are not bumps. A host refuses compiled trees
//! whose `hostContract` it doesn't know.

use serde_json::{Value, json};

/// The host contract version, in every hosted manifest as `hostContract`.
pub const HOST_CONTRACT: u32 = 2;

/// Paths below the site root that TypedPress answers itself; a host routes them to WordPress and keeps
/// its own paths out of them.
pub const RESERVED_PATHS: &[(&str, &str)] = &[
    (
        "/wp-json/typedpress/v1/*",
        "TypedPress's HTTP API: kinds, globals, actions, files, `/me`, one route on WordPress's REST server. Every answer is `Cache-Control: private, no-store` with `Vary: Origin, Cookie, Authorization`, except file streams and `/openapi.json`",
    ),
    (
        "/identity/*",
        "TypedPress Identity's issuer and pages, when the app mounts identity",
    ),
    ("/wp-json/typedpress-identity/v1/*", "TypedPress Identity's REST API"),
    (
        "/wp-admin/new/*",
        "TypedPress Admin, unless the app sets `admin: false`: served by wp-admin's `admin.php?page=typedpress-admin`. Apache applies the plugin's `.htaccess` rule; any other server must send a path that is not a file to WordPress's `index.php`, which redirects it to `admin.php?page=typedpress-admin&p=<route>`",
    ),
];

/// The app API (`TypedPress\Wp\Facade::PREFIX`), and the path prefix of actions under it.
pub const API_PREFIX: &str = "/wp-json/typedpress/v1";
pub const ACTION_PREFIX: &str = "/wp-json/typedpress/v1/actions/";

/// Live updates: what a host's transport and publisher deal in.
pub const LIVE_UPDATES: &[(&str, &str)] = &[
    (
        "tp_realtime_changes",
        "WordPress action fired once per committed transaction with its change notice `{id, at, changes: [{collection, ops, ids}]}`",
    ),
    (
        "TypedPress\\Realtime\\Publisher",
        "PHP interface a host implements to send each notice to its realtime transport (`TypedPress\\Realtime\\Module::publish`)",
    ),
    (
        "TypedPress\\Realtime\\Module::shared($notice)",
        "the notice with the ids of collections not every visitor can list set to null, for a room every visitor shares",
    ),
    (
        "__tpRealtime",
        "the page-wide registry on `globalThis` that every generated client on a page shares; a host script calls `registerRealtimeTransport` from the client to set it",
    ),
];

/// Filters and actions a host implements or listens to. Every one is called by the runtime.
pub const HOOKS: &[(&str, &str, &str)] = &[
    (
        "tp_mail_transport",
        "filter",
        "optional: delivers `mail.send` messages itself by returning `true`, refuses one for a retry with `false` or a throw, or returns `null` to leave it to WordPress's `wp_mail()` (before this, `null` was a refusal and managed builds refused mail without a transport)",
    ),
    (
        "tp_purge_urls",
        "action",
        "the root-relative URLs a committed content write changed, for a host's page cache, each with the query string a page was cached under (only parameters the page declares as `search`; a request with any other parameter is answered `private, no-store` and never named here); `/*` after a new build goes live",
    ),
    (
        "tp_private_probe_url",
        "filter",
        "the URL the private-files probe fetches, when the public URL is not reachable from the server",
    ),
    (
        "tp_prebuild_url",
        "filter",
        "the URL a prebuilt page is rendered from, when the site cannot reach its own address (`output: \"server\"` with `prerender`)",
    ),
    (
        "tp_identity_providers",
        "filter",
        "OpenID Connect sign-in providers TypedPress Identity offers",
    ),
    (
        "tp_identity_rest_cookie_auth",
        "filter",
        "lets the identity session cookie authenticate the app's REST API (a host serving its own frontend)",
    ),
    (
        "tp_fetch_allow_address",
        "filter",
        "allows an outgoing `fetch` to an address TypedPress denies by default",
    ),
    (
        "tp_runtime_loaded",
        "action",
        "the runtime has booted; register commands and seams after it",
    ),
    (
        "sp_connectors_call",
        "filter",
        "invokes a host-managed connector with `(null, $role, $tool, $args, $context)`: now for `connectors.call(role, tool, args?)` (`$context['mode']` is `call`), or after commit from the built-in `typedpress.connector` job for `connectors.enqueue(role, tool, args?, { key?, delay? })` (`mode` is `queued`, with `jobId` and `attempt`; a `WP_Error`, a throw or `null` retries with backoff; a queued call runs at least once and may repeat, so de-duplicate by `jobId`); register with 5 accepted args to receive `$context`; credentials remain owned by the host",
    ),
    (
        "sp_identity_callback_url",
        "filter",
        "the redirect URI an OpenID provider is sent back to, with `($url, $provider, $pathUrl)`: the default `<prefix>/provider/callback?provider=<name>`, or `$pathUrl`, `<prefix>/callback/<name>`; both are served",
    ),
    (
        "sp_auth_prefix",
        "filter",
        "overrides the OIDC issuer and callback route prefix (default `/identity`, or `SP_AUTH_PREFIX` constant); browser sign-in stays on `wp-login.php`",
    ),
    (
        "sp_api_namespace",
        "filter",
        "overrides the app REST API namespace under `/wp-json/` (default `typedpress/v1`, or `SP_API_NAMESPACE` constant)",
    ),
    (
        "sp_identity_api_namespace",
        "filter",
        "overrides the identity REST API namespace under `/wp-json/` (default `typedpress-identity/v1`, or `SP_IDENTITY_API_NAMESPACE` constant)",
    ),
];

/// The one site command a host's worker runs, and what it prints.
pub const JOBS_COMMAND: &str = "wp typedpress jobs tick [--limit=<1..100>] [--budget-ms=<ms>]";

/// The PHP entry points a host calls, in order.
pub const MOUNT: &[(&str, &str)] = &[
    (
        "TypedPress\\Loader\\Loader::load($root, $build, $options)",
        "before WordPress initializes, with the build the host selected for this request. `preview: true` marks a preview binding, one that runs on a database of its own and seeds it again for each version: the seed's writes queue no webhook deliveries and no `mail.send` mail (`mail.send` checks the message and resolves to `{ id: \"\" }`); after the seed a preview sends webhooks and mail like any site. Off by default, which is production",
    ),
    (
        "TypedPress\\Loader\\Loader::initialize($root, $build)",
        "once per build per site, before the host exposes it: storage upgrades, migrations, identity maintenance, the content seed",
    ),
    (
        "TypedPress\\Loader\\Loader::inspect($root, $build)",
        "after mounting with load(..., ['inspect' => true]) and WordPress init, without initialization: returns steps and refusals; a steps entry with id commerce-shipping means the build's declared shipping policy does not match the site's recorded source and current shipping rows",
    ),
    (
        "TypedPress\\Loader\\Loader::sealed($root, $build)",
        "a sealed build's plugin allow-list, or null; with `Loader::allowedPlugins` and `Loader::keepUnlisted` in the host's own early code, before WordPress reads its active plugins",
    ),
    (
        "TypedPress\\Loader\\Store::gc($root, $keep)",
        "after the host deletes a version: keeps exactly the builds it still serves",
    ),
];

/// Sealed mode (`sealed: true`, /docs/operations/cli-and-deploy): what a host does with the allow-list.
pub const SEALED: &str = "When the app sets `sealed: true`, the hosted manifest and the build manifest carry `sealed`: the plugin directory names that may load (TypedPress's own, the platform pins, and the plugins the config declares). The key is absent when the app isn't sealed. TypedPress itself only refuses new activations and reports what else is loaded, because WordPress loads drop-ins, must-use plugins and active plugins before TypedPress's app plugin. A host that wants the list enforced does it in its own early code (a must-use plugin of the host's, or `sunrise.php`): it filters `option_active_plugins` with `TypedPress\\Loader\\Loader::allowedPlugins($active, $sealed)`, keeps the hidden entries stored with `TypedPress\\Loader\\Loader::keepUnlisted($new, $stored, $sealed)` on `pre_update_option_active_plugins`, ships no must-use plugin or drop-in beyond its own, and defines `TP_SEALED_ENFORCED` so the app reports `enforced: \"host\"`. `allowedPlugins` never passes `vip-block-data-api` or `vip-decoupled-bundle`, whatever list it is given: each serves stored content around the app's read rules. Nothing changes for sites that don't serve a TypedPress app. /docs/operations/cli-and-deploy has the example.";

/// Prebuilt pages (`prerender = true` with `output: "server"`; docs/decisions/2026-11-13-static-output.md):
/// who serves their files. Serving them before PHP is deferred to the engine work; the decision
/// record lists what that design must meet.
pub const PREBUILT: &str = "A build with prebuilt pages writes their HTML and Markdown below `wp-content/cache/typedpress-prebuilt/` (moved by the `tp_prebuilt_dir` filter), and PHP serves them: `TypedPress\\Render\\Prebuilt::serve()` answers at `plugins_loaded` when the request, its cookies, the site's visibility and the file's age allow it, and lets WordPress render otherwise. A host must not serve these files directly, from its web server or any cache of its own, and must not expose the folder over HTTP: deny requests to it as it does for other private paths. Whether a file may be served is decided per request, and only `serve()` decides it. A host's page cache stores and purges these answers as it does any other page. The rebuild runs as the `tp_prebuild` Action Scheduler action, on the host's jobs worker like any other.";

/// The table-prefix rule.
pub const TABLE_PREFIX: &str = "TypedPress puts every table under WordPress's `$wpdb->prefix` and never changes it. App tables are `{prefix}tp_<table>`, system tables `{prefix}tp__<name>`, identity tables `{prefix}tp_identity_<name>`. The host owns the prefix: a host that binds several apps to one database gives each its own prefix before WordPress loads. An app's `existingTable` must be under that prefix, and not WordPress's, TypedPress's, Action Scheduler's, identity's or WooCommerce's own table, nor under another site's longer prefix, which need not end in `_`.";

/// The longest system name TypedPress adds after the prefix: `tp_identity_recovery_codes` (29). An app table adds `tp_` to a name the app chose, so a
/// longer table name is the app's choice and is refused at deploy by MySQL's 64-character limit.
pub const MAX_SUFFIX: usize = 29;

/// The `host` object of the hosted manifest.
pub fn manifest_host() -> Value {
    json!({
        "reservedPaths": RESERVED_PATHS.iter().map(|(p, _)| *p).collect::<Vec<_>>(),
        "apiPrefix": API_PREFIX,
        "actionPrefix": ACTION_PREFIX,
        "liveUpdates": {"action": "tp_realtime_changes", "publisher": "TypedPress\\Realtime\\Publisher", "registry": "__tpRealtime"},
        "hooks": HOOKS.iter().map(|(name, _, _)| *name).collect::<Vec<_>>(),
        "mount": "TypedPress\\Loader\\Loader::load",
        "jobsCommand": "wp typedpress jobs tick",
        "maxTablePrefix": 64 - MAX_SUFFIX,
    })
}

/// The generated part of www/content/docs/reference/host-contract.md (between its markers).
pub fn render_doc() -> String {
    let mut out = String::new();
    out.push_str(&format!(
        "Host contract version: **{HOST_CONTRACT}** (`hostContract` in the manifest).\n\n"
    ));
    out.push_str("### Reserved paths\n\n| Path | What |\n|---|---|\n");
    for (path, what) in RESERVED_PATHS {
        out.push_str(&format!("| `{path}` | {what} |\n"));
    }
    out.push_str(&format!("\nThe app API is `{API_PREFIX}`; actions are `POST {ACTION_PREFIX}<name>`. The generated client is a file of the compiled tree (`client/client.js`); the host chooses the URL it serves it at.\n\n"));
    out.push_str("### Mount and lifecycle\n\n| Call | When |\n|---|---|\n");
    for (call, when) in MOUNT {
        out.push_str(&format!("| `{call}` | {when} |\n"));
    }
    out.push_str("\n### Hooks a host implements or listens to\n\n| Hook | Kind | What |\n|---|---|---|\n");
    for (name, kind, what) in HOOKS {
        out.push_str(&format!("| `{name}` | {kind} | {what} |\n"));
    }
    out.push_str("\n### Live updates\n\n| Name | What |\n|---|---|\n");
    for (name, what) in LIVE_UPDATES {
        out.push_str(&format!("| `{name}` | {what} |\n"));
    }
    out.push_str(&format!("\n### Background work\n\n`{JOBS_COMMAND}`\n\n"));
    out.push_str(&format!("### Sealed mode\n\n{SEALED}\n\n"));
    out.push_str(&format!("### Prebuilt pages\n\n{PREBUILT}\n\n"));
    out.push_str(&format!("### Table prefix\n\n{TABLE_PREFIX} The longest name TypedPress adds after it is {MAX_SUFFIX} characters, so a prefix may be at most {} characters.\n\n", 64 - MAX_SUFFIX));
    out.push_str("### Manifest `host` object\n\nFor a build with `store()`, both the hosted manifest's `maxTablePrefix` and\n`host.maxTablePrefix` are **20**, because Woo's longest table suffix is 44 characters.\nThe loader's early commerce policy enforces this before Woo initializes. Other builds use\nthe ordinary 35-character limit shown below.\n\n```json\n");
    out.push_str(&serde_json::to_string_pretty(&manifest_host()).unwrap());
    out.push_str("\n```\n");
    out
}
