//! The diagnostic contract of `zero migrate app --json`: every refusal carries a stable `code`, the
//! 0.8 source position (`file`, 1-based `line`, UTF-16 `column`), the 0.8 `feature` it names, its
//! `v2` equivalent and a one-line `fix`. A publish response shows these to the agent that ports the
//! app, so codes never change meaning; a new refusal kind gets a new code. Every code is listed in
//! `tests/compatibility/registry.json` under `zero08`.
use super::super::Failure;
use super::client;
use serde::Serialize;

#[derive(Debug, Serialize)]
pub struct Contract {
    pub code: &'static str,
    pub file: String,
    pub line: usize,
    pub column: usize,
    pub feature: String,
    pub v2: &'static str,
    pub fix: &'static str,
}

/// One refusal kind. `feature` may name `{service}` (the `ctx` service in the pointer) or
/// `{construct}` (the construct the v2 compiler named). `example` is a pointer and reason this rule
/// claims, checked by the tests.
pub struct Rule {
    pub code: &'static str,
    pub feature: &'static str,
    pub v2: &'static str,
    pub fix: &'static str,
    test: fn(&str, &str) -> bool,
    #[cfg_attr(not(test), allow(dead_code))]
    pub example: (&'static str, &'static str),
}

const LANGUAGE: &str = "error: Zero code doesn't support ";

/// `Z08_SCHEMA` when the candidate creates new tables: nothing is adopted, so the fix names the
/// v2 declaration to write, not the 0.8 table to match.
pub static SCHEMA_FRESH: Rule = Rule {
    code: "Z08_SCHEMA_FRESH",
    feature: "schema table or field, for a new v2 table",
    v2: "table() with text, real, boolean and json columns",
    fix: "Declare the table with literal field types and literal defaults; it is created empty.",
    test: |p, _| p.starts_with("/schema"),
    example: ("/schema/tasks/title", "default must be a literal scalar"),
};

/// First match wins, so specific rules come before the general ones they overlap.
pub static RULES: &[Rule] = &[
    // A client app without pages (app/client.rs): each tag is a fixed reason prefix.
    Rule {
        code: "Z08_CLIENT_STORAGE",
        feature: "0.8 client storage uploads",
        v2: "v2 file buckets (files in zero.config.ts)",
        fix: "Declare a file bucket and upload with client.files.<bucket>.upload from the generated v2 client.",
        test: |_, r| r.starts_with(client::TAG_STORAGE),
        example: ("/client/index.tsx", "0.8 client storage uploads: x"),
    },
    Rule {
        code: "Z08_CLIENT_FETCH",
        feature: "0.8 authenticatedFetch",
        v2: "a v2 action or route",
        fix: "Call a v2 action or route instead; the bridge sends the session's bearer token.",
        test: |_, r| r.starts_with(client::TAG_FETCH),
        example: ("/client/index.tsx", "0.8 authenticatedFetch: x"),
    },
    Rule {
        code: "Z08_CLIENT_CONNECTORS",
        feature: "0.8 client connector state",
        v2: "nothing yet: connector calls are not in v2",
        fix: "Remove the connector state from the client until v2 has connectors.",
        test: |_, r| r.starts_with(client::TAG_CONNECTORS),
        example: ("/client/index.tsx", "0.8 client connector state: x"),
    },
    Rule {
        code: "Z08_CLIENT_CONTENT",
        feature: "0.8 content reads from the client",
        v2: "a v2 collection read (client.<collection>.list)",
        fix: "Read the collection with the generated v2 client instead of contentQueryOptions.",
        test: |_, r| r.starts_with(client::TAG_CONTENT),
        example: ("/client/index.tsx", "0.8 client content reads: x"),
    },
    Rule {
        code: "Z08_CLIENT_SIGN_IN",
        feature: "0.8 client sign-in",
        v2: "zero-identity sign-in (client.auth.signIn())",
        fix: "Replace the 0.8 sign-in calls with client.auth.signIn() from the generated v2 client.",
        test: |_, r| r.starts_with(client::TAG_SIGN_IN),
        example: ("/client/index.tsx", "0.8 client sign-in: x"),
    },
    Rule {
        code: "Z08_CLIENT_API",
        feature: "0.8 client API without a v2 bridge",
        v2: "the generated v2 client in zero/client/",
        fix: "Port the call to the generated v2 client; the bridge covers the 0.8 hooks, useAuth, signOut and the router.",
        test: |_, r| r.starts_with(client::TAG_API),
        example: ("/client/index.tsx", "0.8 client API without a v2 bridge: x"),
    },
    Rule {
        code: "Z08_CLIENT_KIT",
        feature: "0.8 kit components in a client app",
        v2: "plain markup and CSS",
        fix: "Replace the kit components with your own markup and styles.",
        test: |_, r| r.starts_with(client::TAG_KIT),
        example: ("/client/index.tsx", "0.8 kit components in the client: x"),
    },
    Rule {
        code: "Z08_CLIENT_CHARTS",
        feature: "0.8 charts in a client app",
        v2: "nothing yet: charts are not in v2",
        fix: "Draw the chart with your own SVG, or vendor a chart library into public/.",
        test: |_, r| r.starts_with(client::TAG_CHARTS),
        example: ("/client/index.tsx", "0.8 charts in the client: x"),
    },
    Rule {
        code: "Z08_CLIENT_PACKAGE",
        feature: "npm package in a client app",
        v2: "a prebuilt browser bundle in public/",
        fix: "Build the client with its packages (for example Vite into public/) and publish that bundle.",
        test: |_, r| r.starts_with(client::TAG_PACKAGE),
        example: ("/client/index.tsx", "npm package in the client: x"),
    },
    Rule {
        code: "Z08_CLIENT_IMPORT",
        feature: "client import outside the capsule",
        v2: "modules inside the capsule",
        fix: "Add the module to the capsule or remove the import.",
        test: |_, r| r.starts_with(client::TAG_IMPORT),
        example: ("/client/index.tsx", "client import outside the capsule: x"),
    },
    Rule {
        code: "Z08_CLIENT_DYNAMIC_IMPORT",
        feature: "dynamic import() in a client app",
        v2: "static imports",
        fix: "Import the module statically.",
        test: |_, r| r.starts_with(client::TAG_DYNAMIC),
        example: ("/client/index.tsx", "dynamic import in the client: x"),
    },
    Rule {
        code: "Z08_CLIENT_ASSET",
        feature: "client import of a non-script file",
        v2: "static files in public/",
        fix: "Move the file into public/ and reference it by URL.",
        test: |_, r| r.starts_with(client::TAG_ASSET),
        example: ("/client/index.tsx", "client import of a non-script file: x"),
    },
    Rule {
        code: "Z08_CLIENT_SYNTAX",
        feature: "client source the browser build cannot compile",
        v2: "a browser module compiled from TypeScript/TSX",
        fix: "Fix the reported line in the 0.8 client so it parses as TypeScript/TSX.",
        test: |_, r| r.starts_with(client::TAG_SYNTAX),
        example: ("/client/index.tsx", "client source the browser build cannot compile: x"),
    },
    Rule {
        code: "Z08_CLIENT_NATIVE",
        feature: "client app converted by the browser compiler",
        v2: "zero migrate app from the native CLI",
        fix: "Run zero migrate app --from zero-0.8 with the native CLI; it compiles the client into public/.",
        test: |_, r| r.starts_with(client::TAG_NATIVE),
        example: ("/client/index.tsx", "client app conversion outside the native CLI: x"),
    },
    Rule {
        code: "Z08_CLIENT_PUBLIC",
        feature: "client app with its own public/ directory",
        v2: "public/, which holds the converted client",
        fix: "Move the files in public/ into the client, or out of the capsule, and convert again.",
        test: |_, r| r.starts_with(client::TAG_PUBLIC),
        example: ("/public", "client app over an existing public/: x"),
    },
    Rule {
        code: "Z08_CLIENT_UNREADABLE",
        feature: "client module that cannot be read",
        v2: "a UTF-8 TypeScript, JavaScript or CSS file in the capsule",
        fix: "Make the module a readable UTF-8 file in the capsule, or remove the import.",
        test: |_, r| r.starts_with(client::TAG_UNREADABLE),
        example: ("/client/Logo.tsx", "client module that cannot be read: x"),
    },
    Rule {
        code: "Z08_SPACE_REQUIRED",
        feature: "schema tables without a table mode",
        v2: "new v2 tables (--fresh) or the Space's adopted 0.8 tables (--space)",
        fix: "Pass --fresh for a Space with no 0.8 tables, or --space <id> to adopt existing ones.",
        test: |_, r| r.starts_with("the schema needs a table mode"),
        example: (
            "/schema/tasks",
            "the schema needs a table mode: pass --fresh for a Space with no 0.8 tables",
        ),
    },
    Rule {
        code: "Z08_SOURCE_NOT_RECOVERABLE",
        feature: "a published 0.8 version without its authored source",
        v2: "the authored TypeScript at the published commit",
        fix: "Supply the source the version was published from; its file hashes must match the manifest.",
        test: |_, r| r.starts_with("source not recoverable"),
        example: (
            "/source",
            "source not recoverable: the finalize manifest keeps bundled JavaScript",
        ),
    },
    Rule {
        code: "Z08_SOURCE_CHANGED",
        feature: "source edited after the publish",
        v2: "the authored TypeScript at the published commit",
        fix: "Check out the commit that was published, or publish the current source first.",
        test: |_, r| {
            r.starts_with("source differs from the published version") || r.starts_with("manifest source path")
        },
        example: (
            "/source",
            "source differs from the published version: server/index.ts (changed)",
        ),
    },
    Rule {
        code: "Z08_PAGE_DOCUMENT",
        feature: "Markdown or HTML document page",
        v2: "a TSX page (docs/pages.md)",
        fix: "Move the document's text into a TSX page or a collection the page lists.",
        test: |p, r| {
            (p.starts_with("/pages/") && (p.ends_with(".md") || p.ends_with(".html")))
                || r.starts_with("document page ")
                || r.starts_with("content-owned 0.8 pages")
        },
        example: ("/pages/about.md", "content-owned 0.8 pages have no v2 counterpart yet"),
    },
    Rule {
        code: "Z08_PAGE_ROUTE_PARAM",
        feature: "parameterized 0.8 page route",
        v2: "collectionPage() (docs/pages.md)",
        fix: "Port the page to a collectionPage() over the collection its parameter names.",
        test: |_, r| r.starts_with("a parameterized 0.8 page route"),
        example: (
            "/pages/notes/[id].tsx",
            "a parameterized 0.8 page route has no static v2 page",
        ),
    },
    Rule {
        code: "Z08_PAGE_INTERACTIVE",
        feature: "interactive 0.8 page, layout or island",
        v2: "a TSX page with an Island (docs/pages.md)",
        fix: "Port the page to a TSX page; move browser state and events into an Island.",
        test: |p, r| {
            (p.starts_with("/pages")
                || p.starts_with("/layouts")
                || p.starts_with("/islands")
                || p.starts_with("/_pages"))
                && (r.contains("port it to a TSX page")
                    || r.contains("needs an explicit port")
                    || r.contains("manual port")
                    || r == "invalid TSX")
        },
        example: (
            "/pages/live.tsx",
            "a 0.8 page using live queries; port it to a TSX page (docs/pages.md)",
        ),
    },
    Rule {
        code: "Z08_ENDPOINT_METHOD",
        feature: "endpoint method without a v2 route (OPTIONS and others)",
        v2: "route() with GET, POST, PUT, PATCH or DELETE",
        fix: "Serve the endpoint with a supported method; GET also answers HEAD.",
        test: |p, r| p.starts_with("/endpoints/") && r.contains("endpoints have no v2 route"),
        example: (
            "/endpoints/pre/method",
            "OPTIONS endpoints have no v2 route; GET also answers HEAD",
        ),
    },
    Rule {
        code: "Z08_ENDPOINT_WRITING_GET",
        feature: "writing GET endpoint",
        v2: "a POST route",
        fix: "Make the endpoint POST (or another unsafe method) and update its callers.",
        test: |_, r| r.contains("writing GET"),
        example: ("/endpoints/hit", "a writing GET endpoint needs a manual port"),
    },
    Rule {
        code: "Z08_ENDPOINT_RESERVED_PATH",
        feature: "endpoint path owned by WordPress or Zero",
        v2: "a route under a path of the app's own",
        fix: "Move the endpoint to a path WordPress and Zero don't own, and update its callers.",
        test: |_, r| r.contains("belongs to WordPress or Zero"),
        example: (
            "/endpoints/login/path",
            "/wp-login.php belongs to WordPress or Zero on v2",
        ),
    },
    Rule {
        code: "Z08_ENDPOINT_PATH",
        feature: "endpoint path without a v2 route form",
        v2: "route() paths with literal, :param and a final *splat segment",
        fix: "Use literal and :param segments, with at most one final catch-all.",
        test: |p, r| {
            p.starts_with("/endpoints/")
                && (r.contains("has no v2 route form")
                    || r.starts_with("a :splat must")
                    || r.starts_with("the site root needs"))
        },
        example: ("/endpoints/any/path", "segment \"%41\" has no v2 route form"),
    },
    Rule {
        code: "Z08_ENDPOINT_REQUEST_API",
        feature: "endpoint request API (url, bytes, headers.entries)",
        v2: "RouteRequest: method, path, params, query, headers and body",
        fix: "Read the request through req.path, req.query, req.headers and req.body.",
        test: |_, r| r.contains("request API has no v2 route equivalent"),
        example: (
            "/endpoints/up/request",
            "this request API has no v2 route equivalent; port it explicitly",
        ),
    },
    Rule {
        code: "Z08_ENDPOINT_NAME",
        feature: "endpoint named like a query, mutation or action",
        v2: "distinct route and action names",
        fix: "Rename the endpoint or the handler it shares a name with.",
        test: |_, r| r.contains("endpoint name collides"),
        example: (
            "/endpoints/list",
            "endpoint name collides with a query, mutation or action",
        ),
    },
    Rule {
        code: "Z08_ENDPOINT_SHAPE",
        feature: "endpoint declaration or handler shape",
        v2: "route({ method, path, handler(req, ctx) })",
        fix: "Declare endpoint({ method, path }, (ctx, request) => ...) with literal options.",
        test: |p, r| {
            p.starts_with("/endpoints")
                && (r.starts_with("endpoint handlers take")
                    || r.starts_with("destructured request parameters")
                    || r.starts_with("expected endpoint(")
                    || r.starts_with("two endpoints share")
                    || r.starts_with("endpoint needs a literal")
                    || r == "unknown endpoint option"
                    || r.starts_with("readOnly must be"))
        },
        example: ("/endpoints/x", "expected endpoint({ method, path }, handler)"),
    },
    Rule {
        code: "Z08_CTX_AUTH_PROFILE",
        feature: "ctx.auth profile fields and methods",
        v2: "ctx.user ({ id, name, role })",
        fix: "Read ctx.user; keep other profile fields in a members collection.",
        test: |p, r| p.contains("/ctx/auth/") && r.starts_with("legacy profiles and auth methods"),
        example: (
            "/queries/me/ctx/auth/email",
            "legacy profiles and auth methods require an explicit identity mapping",
        ),
    },
    Rule {
        code: "Z08_CTX_AUTH_USERS",
        feature: "ctx.auth.userId without Space Users",
        v2: "ctx.user.id",
        fix: "Migrate with --users when the Space uses Space Users; otherwise key rows by ctx.user.id.",
        test: |_, r| r.starts_with("ctx.auth.userId maps only for Space Users"),
        example: (
            "/queries/me/ctx/auth/userId",
            "ctx.auth.userId maps only for Space Users (pass --users)",
        ),
    },
    Rule {
        code: "Z08_CTX_EMAIL_READ",
        feature: "ctx.email in a query or read-only endpoint",
        v2: "mail.send in an action",
        fix: "Move the send into a mutation, action or writing endpoint.",
        test: |_, r| r.contains("could not send email in 0.8 either"),
        example: (
            "/queries/email/ctx/email",
            "reads (queries, readOnly endpoints) could not send email in 0.8 either",
        ),
    },
    Rule {
        code: "Z08_CTX_DYNAMIC",
        feature: "ctx passed on or read dynamically",
        v2: "a HookCtx value passed to typed helpers",
        fix: "Read ctx with static keys; pass it only to helpers in the same module.",
        test: |_, r| {
            r.starts_with("unsupported context service, alias or dynamic use")
                || r.starts_with("context destructuring")
                || r.starts_with("shadowed context")
        },
        example: (
            "/queries/q/ctx",
            "unsupported context service, alias or dynamic use; port its behavior explicitly",
        ),
    },
    Rule {
        code: "Z08_CTX_DB",
        feature: "ctx.db handle use",
        v2: "db from \"zero\"",
        fix: "Call ctx.db.<table> methods directly in the handler.",
        test: |p, r| p.contains("/ctx/db") && r.starts_with("unsupported context service"),
        example: (
            "/queries/q/ctx/db",
            "unsupported context service; port its authority and behavior explicitly",
        ),
    },
    Rule {
        code: "Z08_CTX_SERVICE",
        feature: "ctx.{service}",
        v2: "the matching \"zero\" export (db, mail, env, fetch) or a WordPress API",
        fix: "Port the service call to its v2 export, or drop it if v2 does the work.",
        test: |p, r| {
            p.contains("/ctx/")
                && (r.starts_with("unsupported context service")
                    || r.contains("has no v2 counterpart")
                    || r.contains("ctx.email"))
        },
        example: (
            "/queries/q/ctx/spam",
            "unsupported context service; port its authority and behavior explicitly",
        ),
    },
    Rule {
        code: "Z08_CTX_INVALIDATE_READ",
        feature: "ctx.invalidate in a read",
        v2: "change notices on every committed write",
        fix: "Remove the call; v2 announces every committed write.",
        test: |_, r| r.starts_with("invalidate is only available"),
        example: (
            "/queries/q/ctx/invalidate",
            "invalidate is only available in legacy mutations",
        ),
    },
    Rule {
        code: "Z08_DB_WRITE_IN_READ",
        feature: "database write outside a mutation",
        v2: "db writes in an action",
        fix: "Move the write into a mutation or a writing endpoint.",
        test: |_, r| r.contains("writes are only allowed in legacy mutations"),
        example: ("/queries/q/ctx/db/tasks", "writes are only allowed in legacy mutations"),
    },
    Rule {
        code: "Z08_DB_OPTIONAL_RANGE",
        feature: "index range or pagination over an optional field",
        v2: "db.query findMany with where and orderBy",
        fix: "Range over required fields, or make the field required.",
        test: |_, r| r.contains("optional-field range") || r.starts_with("pagination over optional field"),
        example: (
            "/queries/q/ctx/db/tasks",
            "optional-field range ordering requires a manual port",
        ),
    },
    Rule {
        code: "Z08_DB_SPREAD",
        feature: "spread database arguments",
        v2: "db calls with literal arguments",
        fix: "Pass database arguments directly instead of spreading them.",
        test: |_, r| r.starts_with("spread database arguments"),
        example: ("/queries/q", "spread database arguments require a manual port"),
    },
    Rule {
        code: "Z08_DB_ESCAPE",
        feature: "database handle stored or returned",
        v2: "db from \"zero\", called where it is used",
        fix: "Call the table's methods where you use them instead of storing ctx.db.<table>.",
        test: |_, r| r.starts_with("database handles cannot escape"),
        example: ("/queries/q", "database handles cannot escape a handler"),
    },
    Rule {
        code: "Z08_DB_OPERATION",
        feature: "database operation without a v2 mapping",
        v2: "db.query findMany/findFirst, db.insert, db.update, db.delete",
        fix: "Use get, insert, update, delete, or withIndex(...).order().collect/first/take/paginate.",
        test: |_, r| {
            r.starts_with("unsupported database operation")
                || r.starts_with("database query must end")
                || r.starts_with("database builder cannot")
                || r.starts_with("unknown database table")
                || r.starts_with("unknown index")
                || r.contains("index range")
                || r.starts_with("range ")
                || r.starts_with("invalid index range")
                || r.starts_with("invalid range root")
                || r.starts_with("unsupported index comparison")
                || r.starts_with("order must be")
        },
        example: ("/queries/q", "unsupported database operation offset"),
    },
    Rule {
        code: "Z08_HANDLER_SIGNATURE",
        feature: "handler signature (rest, default or destructured parameters)",
        v2: "action({ input: { ...columns }, handler(input, ctx) })",
        fix: "Give each parameter a name and a concrete type; read defaults inside the handler.",
        test: |_, r| {
            r.starts_with("rest and default parameters")
                || r.starts_with("destructured input parameters")
                || r.contains("must declare its context parameter")
                || r.starts_with("cannot read the destructured parameter")
                || r.starts_with("cannot read the parameter type")
        },
        example: (
            "/mutations/m",
            "rest and default parameters need a manual input schema when a default reads the context",
        ),
    },
    Rule {
        code: "Z08_HANDLER_TYPES",
        feature: "handler parameter without a concrete type",
        v2: "action input columns",
        fix: "Annotate each handler parameter with a concrete TypeScript type.",
        test: |_, r| {
            r.starts_with("handler parameters require explicit types")
                || r.starts_with("handler parameters need supported concrete")
                || r.starts_with("missing parameter type")
        },
        example: ("/mutations/m", "handler parameters require explicit types"),
    },
    Rule {
        code: "Z08_HANDLER_SHAPE",
        feature: "query, mutation or action declaration",
        v2: "action({ input, handler })",
        fix: "Declare each handler as query(...), mutation(...) or action(...) around one inline function.",
        test: |_, r| {
            r.starts_with("expected a handler helper")
                || r.starts_with("handler must be an inline function")
                || r.starts_with("cannot locate handler body")
                || r.starts_with("expected handler body")
                || r.starts_with("named nested functions")
                || r.starts_with("classes inside migrated handlers")
        },
        example: ("/queries/q", "handler must be an inline function"),
    },
    Rule {
        code: "Z08_NAME",
        feature: "handler, table or field name",
        v2: "ASCII identifiers, unique across actions and routes",
        fix: "Rename it to start with a letter and use only letters, digits and underscores.",
        test: |p, r| {
            r.starts_with("names must start with an ASCII letter")
                || (r.contains("collides") && !p.starts_with("/collections"))
                || r.starts_with("duplicate")
                || r.starts_with("reserved")
                || r.contains("names are reserved")
                || r.starts_with("shadowed context or reserved")
        },
        example: (
            "/queries/q-1",
            "names must start with an ASCII letter and contain only letters, digits or underscores",
        ),
    },
    Rule {
        code: "Z08_BUILD_GLOBAL",
        feature: "build-time global (declare const)",
        v2: "env(\"NAME\") or a constant",
        fix: "Replace the declared global with env(\"NAME\") or a constant.",
        test: |_, r| r.contains("names a build-time global"),
        example: ("", "`declare const API` names a build-time global that 0.8 injected"),
    },
    Rule {
        code: "Z08_AUTH_CALLBACK",
        feature: "auth callbacks and providers (onGuestUpgrade)",
        v2: "zero-identity (docs/identity-api.md)",
        fix: "Configure sign-in with zero-identity and move the callback's work into an action.",
        test: |p, _| p.starts_with("/auth/"),
        example: (
            "/auth/onGuestUpgrade",
            "identity callbacks/providers need an explicit zero-identity migration",
        ),
    },
    Rule {
        code: "Z08_SOCKETS",
        feature: "sockets",
        v2: "change notices (docs/realtime.md)",
        fix: "Replace the socket with watch() on the tables it announced.",
        test: |p, _| p == "/sockets" || p.starts_with("/sockets/"),
        example: (
            "/sockets/chat",
            "unsupported capsule feature; port it before migrating the application",
        ),
    },
    Rule {
        code: "Z08_COLLECTIONS",
        feature: "collections or sync",
        v2: "collections in zero.config.ts",
        fix: "Declare the collection in zero.config.ts.",
        test: |p, _| p.starts_with("/collections") || p.starts_with("/sync"),
        example: ("/collections/posts", "collection collides with a schema table"),
    },
    Rule {
        code: "Z08_SCHEMA",
        feature: "schema table or field",
        v2: "table() and column builders",
        fix: "Use literal field declarations with literal defaults.",
        test: |p, _| p.starts_with("/schema"),
        example: ("/schema/tasks/title", "default must be a literal scalar"),
    },
    Rule {
        code: "Z08_IMAGE_RESPONSE",
        feature: "ImageResponse (JSX on the server)",
        v2: "a route returning stored bytes",
        fix: "Render the image ahead of time and serve it from a route or public/.",
        test: |_, r| r.contains("uses JSX on the server"),
        example: ("", "server/og.tsx uses JSX on the server (ImageResponse)"),
    },
    Rule {
        code: "Z08_PACKAGE",
        feature: "imported package",
        v2: "local TypeScript helpers and supported installed packages",
        fix: "Copy the helper into the app, or import a package v2 supports.",
        test: |_, r| {
            r.contains("only local TypeScript helpers are copied")
                || r.contains("leaves the capsule")
                || r.contains("escapes the capsule")
        },
        example: (
            "",
            "package \"lodash\" needs a manual port; only local TypeScript helpers are copied",
        ),
    },
    Rule {
        code: "Z08_LANGUAGE",
        feature: "JavaScript construct: {construct}",
        v2: "the v2 language subset (docs/language-support.md)",
        fix: "Rewrite the construct in the supported subset; docs/language-support.md lists the alternatives.",
        test: |_, r| r.contains(LANGUAGE),
        example: (
            "",
            "generated application refused by v2 compiler: error: Zero code doesn't support `with`",
        ),
    },
    Rule {
        code: "Z08_HOST_API",
        feature: "host API without a v2 counterpart",
        v2: "the \"zero\" exports and the supported globals",
        fix: "Replace the call with a \"zero\" export, or move it to the browser.",
        test: |_, r| r.contains("isn't available in Zero code"),
        example: (
            "",
            "generated application refused by v2 compiler: error: `setTimeout` isn't available in Zero code",
        ),
    },
    Rule {
        code: "Z08_V2_COMPILE",
        feature: "converted code the v2 compiler refuses",
        v2: "zero check on the candidate",
        fix: "Fix the reported line in the 0.8 source so the converted app type-checks.",
        test: |_, r| r.starts_with("generated application refused by v2 compiler"),
        example: (
            "",
            "generated application refused by v2 compiler: error: this argument is unknown",
        ),
    },
    Rule {
        code: "Z08_CAPSULE_FEATURE",
        feature: "capsule option without a v2 mapping",
        v2: "defineConfig options (docs/config-api.md)",
        fix: "Remove the option or port what it does to zero.config.ts.",
        test: |_, r| {
            r.starts_with("unsupported capsule feature")
                || r.contains("needs an explicit port")
                || r.starts_with("an executable Spacefast config")
        },
        example: (
            "/storage",
            "unsupported capsule feature; port it before migrating the application",
        ),
    },
    Rule {
        code: "Z08_OUTPUT_EXISTS",
        feature: "existing output directory",
        v2: "a new output directory",
        fix: "Choose an --out directory that does not exist yet.",
        test: |_, r| r.starts_with("output already exists"),
        example: (
            "",
            "output already exists; choose a new directory; no files are overwritten",
        ),
    },
    Rule {
        code: "Z08_SOURCE_SHAPE",
        feature: "capsule source layout",
        v2: "zero.config.ts with defineConfig",
        fix: "Keep one capsule({...}) default export in server/index.ts with imports and pure helpers.",
        test: |_, _| true,
        example: ("", "expected imports, pure helpers and one capsule default export"),
    },
];

/// The rule a converter refusal falls under.
pub fn rule(pointer: &str, reason: &str) -> &'static Rule {
    let first = reason.lines().next().unwrap_or("");
    RULES
        .iter()
        .find(|r| {
            (r.test)(pointer, first)
                || (r.code == "Z08_LANGUAGE" || r.code == "Z08_HOST_API") && (r.test)(pointer, reason)
        })
        .expect("the last rule matches everything")
}

fn feature(rule: &Rule, pointer: &str, reason: &str) -> String {
    let service = pointer
        .split("/ctx/")
        .nth(1)
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("service");
    let construct = reason
        .split(LANGUAGE)
        .nth(1)
        .and_then(|rest| rest.lines().next())
        .unwrap_or("")
        .trim_end_matches('.');
    rule.feature
        .replace("{service}", service)
        .replace("{construct}", construct)
}

/// Fills in the contract on every refusal; `file` is where an unlocated refusal points.
pub fn finish(errors: &mut [Failure], file: &str, fresh: bool) {
    for error in errors {
        let mut rule = rule(&error.pointer, &error.reason);
        // Fresh tables are created, not adopted, so a schema refusal there has its own code and fix.
        if fresh && rule.code == "Z08_SCHEMA" {
            rule = &SCHEMA_FRESH;
        }
        // A page, layout or island pointer names its file; anything else unlocated is the server entry's.
        let named = ["/pages/", "/layouts/", "/islands/", "/_pages/", "/client/"]
            .iter()
            .any(|dir| error.pointer.starts_with(dir))
            .then(|| error.pointer[1..].replace("~1", "/").replace("~0", "~"));
        let (file, line, column) = error
            .location
            .as_deref()
            .cloned()
            .unwrap_or_else(|| (named.unwrap_or_else(|| file.to_string()), 1, 1));
        error.contract = Some(Box::new(Contract {
            code: rule.code,
            file,
            line,
            column,
            feature: feature(rule, &error.pointer, &error.reason),
            v2: rule.v2,
            fix: rule.fix,
        }));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_rule_is_complete_unique_and_claims_its_example() {
        let mut codes = std::collections::BTreeSet::new();
        assert_eq!(rule(SCHEMA_FRESH.example.0, SCHEMA_FRESH.example.1).code, "Z08_SCHEMA");
        for r in RULES.iter().chain([&SCHEMA_FRESH]) {
            assert!(r.code.starts_with("Z08_") && codes.insert(r.code), "{}", r.code);
            for (name, text) in [("feature", r.feature), ("v2", r.v2), ("fix", r.fix)] {
                assert!(!text.is_empty() && !text.contains('\n'), "{} {name}", r.code);
            }
            if r.code != SCHEMA_FRESH.code {
                assert_eq!(rule(r.example.0, r.example.1).code, r.code, "{:?}", r.example);
            }
        }
        let registry: serde_json::Value =
            serde_json::from_str(include_str!("../../../tests/compatibility/registry.json")).unwrap();
        let registered: std::collections::BTreeSet<&str> = registry["zero08"]
            .as_array()
            .expect("registry zero08")
            .iter()
            .map(|e| e["code"].as_str().unwrap())
            .collect();
        assert_eq!(
            registered, codes,
            "tests/compatibility/registry.json zero08 must list every code"
        );
    }

    #[test]
    fn the_construct_and_service_reach_the_feature() {
        let r = "generated application refused by v2 compiler: error: Zero code doesn't support generators\n  --> server/index.ts:3:1";
        assert_eq!(feature(rule("", r), "", r), "JavaScript construct: generators");
        let p = "/queries/q/ctx/jwt";
        assert_eq!(feature(rule(p, "unsupported context service; x"), p, ""), "ctx.jwt");
    }
}
