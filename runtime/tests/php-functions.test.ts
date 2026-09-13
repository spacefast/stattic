// PHP Functions (slice A): a committed `functions/<route>.php` executes at its
// derived route, inside the worker's `_stattic_tenant_harden()` jail.
//
// This suite holds the serve-side seams the `t=php` lane adds: execution
// through the hardened worker, the sf_* prelude, containment evidence read from
// inside a handler, platform header ownership over handler output, and the
// route/non-route boundary under `functions/`. Covered elsewhere:
//   * the compile shape (`t=php` action, hidden source, inert non-routes):
//     responses.rs `functions_route_php_compiles_to_a_php_action_and_hides_its_source`;
//   * a stray root-level `.php` served inert:
//     routing.test.ts "php-like uploads are inert text, never executed";
//   * the prelude's own jail/scrub mechanics under the CLI:
//     tenant-prelude.test.ts;
//   * what the service broker does with a frame (grant enforcement, Akismet's
//     shape, the outbox row): services.rs and functions-relay.test.ts.
import { afterAll, beforeAll, expect, test } from "bun:test";
import path from "node:path";
import { gzipSync } from "node:zlib";

import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";

const HOST = "phpfx.test";
const SPACE = "spc_phpfx";
const VERSION = "ver_phpfx_1";
// The canonical handler: parsed body in, JSON out.
const HELLO_PHP = "<?php sf_json(['hello' => sf_body()['name'] ?? 'world']);\n";

// Reports, from inside the handler, the jail and the surfaces the scrub emptied.
const PROBE_PHP = `<?php
sf_json([
    'open_basedir' => (string) ini_get('open_basedir'),
    // The fleet-wide dispatch credential MUST be gone from every surface (the
    // prelude scrubs it and the blob that embeds it); a same-team site-scoped
    // value stays, because the team owns the box.
    'dispatch_token' => getenv('SPACEFAST_FUNCTIONS_DISPATCH_TOKEN'),
    'dispatch_token_server' => $_SERVER['SPACEFAST_FUNCTIONS_DISPATCH_TOKEN'] ?? null,
    'site_env_runtime_bin' => getenv('SPACEFAST_RUNTIME_BIN'),
    'api_url' => sf_api_url('/health'),
    'auth' => sf_auth(),
]);
`;

// sf_db() is wired to the broker, so "unavailable" means the Space has no
// database attached, answered as its own problem.
const DB_PHP = "<?php sf_db()->query('SELECT 1');\n";

const CACHE_PHP = `<?php
header('Cache-Control: public, max-age=3600');
if (isset($_GET['cookie'])) {
    header('Set-Cookie: preview=one; Path=/; HttpOnly; SameSite=Lax');
}
header('Content-Type: text/plain; charset=utf-8');
echo 'cached';
`;

// The one verified sender this runtime is configured with. It reaches the
// broker because the bridge resolved it into process memory before the jail.
const SENDER = "hello@phpfx.test";

// No Akismet key is configured, so the broker refuses the spam frame before any
// upstream call. Reaching that refusal means the native executor ran, under the
// jail, with the platform's own environment.
const SPAM_PHP = `<?php
try {
    sf_spam(['content' => 'buy pills', 'userIp' => '203.0.113.9', 'type' => 'contact-form']);
    sf_json(['code' => 'no_refusal']);
} catch (SpacefastServiceError $error) {
    sf_json(['code' => $error->errorCode]);
}
`;

// Three seams in one request. The check names no userIp, so only the
// dispatch-time trusted visitor IP can carry it past validation. Corrections
// get no request defaults: this request's visitor is the moderator, not the
// spammer being reported, so the one omitting userIp fails validation here.
const SPAM_EVIDENCE_PHP = `<?php
$codes = [];
foreach ([
    static fn() => sf_spam(['content' => 'buy pills', 'type' => 'contact-form']),
    static fn() => sf_report_spam(['content' => 'buy pills', 'userIp' => '203.0.113.9']),
    static fn() => sf_report_ham(['content' => 'a real signup']),
] as $call) {
    try {
        $call();
        $codes[] = 'no_refusal';
    } catch (SpacefastServiceError $error) {
        $codes[] = $error->errorCode;
    }
}
sf_json(['codes' => $codes]);
`;

// Two sends in one request: the first omits `from` and takes the space's
// configured sender, the second names an unproven address. Two gates, two
// codes, and a second brokered call in one request.
const EMAIL_PHP = `<?php
$codes = [];
foreach ([null, 'nobody@elsewhere.test'] as $from) {
    $message = ['to' => 'visitor@example.com', 'subject' => 'Thanks', 'text' => "Got it.\\n"];
    if ($from !== null) {
        $message['from'] = $from;
    }
    try {
        $codes[] = sf_email($message);
    } catch (SpacefastServiceError $error) {
        $codes[] = $error->errorCode;
    }
}
sf_json(['codes' => $codes]);
`;

// A second Space in the shape a standalone Functions app ships: a compiled
// worker's configuration beside the version tree (which is where the selected
// variable values live) and committed `.php` routes served by this lane. It is
// deliberately UNCLAIMED, so its egress scope differs from the claimed Space
// above and the same probe below answers differently on each.
const FG_HOST = "phpfx-unclaimed.test";
const FG_SPACE = "spc_phpfx_unclaimed";
const FG_VERSION = "ver_phpfx_unclaimed_1";
const FG_TOKEN = "gh-secret-only-sf-env-answers";

// The selection is the platform's: a Space variable reaches the handler, and the
// namespaces the platform reserves do not, however the configuration spells
// them. `getenv` is the control: the prelude emptied that surface.
const ENV_PHP = `<?php
sf_json([
    'selected' => sf_env('GITHUB_TOKEN'),
    'absent' => sf_env('NOT_CONFIGURED'),
    'reserved' => sf_env('SPACEFAST_FUNCTIONS_DISPATCH_TOKEN'),
    'database' => sf_env('DATABASE_URL'),
    'from_process_env' => getenv('GITHUB_TOKEN'),
]);
`;

// Six targets, none of which may reach a socket except the first, and that one
// only on a claimed Space and only far enough to spend a 1ms budget. Every
// address here is a literal, so nothing resolves.
const FETCH_PHP = `<?php
$codes = [];
foreach ([
    ['https://8.8.8.8/', []],
    ['http://api.github.com/', []],
    ['https://127.0.0.1/', []],
    ['https://user:pw@api.github.com/', []],
    ['https://api.github.com/', ['headers' => ['X-Smuggle' => "one\\r\\nHost: evil.test"]]],
    ['https://169.254.169.254/latest/meta-data/', []],
] as [$url, $options]) {
    try {
        sf_fetch($url, $options + ['timeoutMs' => 1]);
        $codes[] = 'no_refusal';
    } catch (SpacefastFetchError $error) {
        $codes[] = $error->errorCode;
    }
}
sf_json(['codes' => $codes]);
`;

let rt: Runtime;

beforeAll(async () => {
  // Platform service configuration as a wp.cloud site carries it: process
  // environment the tenant prelude will scrub away.
  rt = await startRuntime({
    env: {
      SPACEFAST_SERVICE_EMAIL_SENDERS: SENDER,
      // The ingress-owned visitor identity, captured at dispatch into the spam
      // frame default, so sf_spam's default never depends on the handler
      // reading env.
      SPACEFAST_VISITOR_IP: "203.0.113.77",
      // A fleet-wide credential the prelude must keep from the handler.
      SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fleet-dispatch-token-handler-must-not-see",
    },
    // PCOV corrupts its allocator after several requests whose handler tightens
    // open_basedir (`munmap_chunk(): invalid pointer` on the Ubuntu runner), so
    // this suite runs the real PHP binary. Other suites own PHP line coverage.
    phpBinary: process.env.SPACEFAST_REAL_PHP ?? "php",
  });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    metadata: { mode: "website", title: "PHP Functions" },
    files: {
      "index.html": "<h1>static home</h1>\n",
      "functions/hello.php": HELLO_PHP,
      "functions/hello.php.gz": gzipSync(Buffer.from(HELLO_PHP)),
      "functions/probe.php": PROBE_PHP,
      "functions/spam.php": SPAM_PHP,
      "functions/spam-evidence.php": SPAM_EVIDENCE_PHP,
      "functions/email.php": EMAIL_PHP,
      "functions/db.php": DB_PHP,
      "functions/cache.php": CACHE_PHP,
      "functions/fetch.php": FETCH_PHP,
      // A pattern-named module is not an expressible table key in this slice:
      // it stays an inert attachment, neither executed nor dropped.
      "functions/[id].php": "<?php echo 'never executed';\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "PHP Functions" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const unclaimed = publicAccessConfig({ mode: "website", site_title: "PHP Functions unclaimed" });
  await deploy(rt, {
    spaceId: FG_SPACE,
    versionId: FG_VERSION,
    metadata: { mode: "website", title: "PHP Functions unclaimed" },
    files: {
      "index.html": "<h1>unclaimed home</h1>\n",
      "functions/env.php": ENV_PHP,
      "functions/fetch.php": FETCH_PHP,
    },
    // The worker configuration the control plane writes beside the version
    // tree. `variableValues` is the selection finalize resolved; a publish
    // reaches `files/` and can never forge it.
    functions: {
      artifact: {
        appName: "phpfx-unclaimed",
        entry: "handler.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        routes: [],
      },
      host: { hostname: "functions.invalid", bundleUrl: "https://example.test/bundle.json" },
      grantedCapabilities: [],
      variables: { names: ["GITHUB_TOKEN"] },
      variableValues: {
        GITHUB_TOKEN: FG_TOKEN,
        SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fleet-credential-sf-env-must-not-answer",
        DATABASE_URL: "mysql://user:pw@db.internal/app",
      },
    },
    activate: {
      route_name: "production",
      config: {
        ...unclaimed,
        authorization: { ...unclaimed["authorization"], spaceClaimed: false },
      },
      production_hostnames: [FG_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("a functions route executes: parsed body in, JSON out, platform cache policy on", async () => {
  const post = await get(rt, HOST, "/hello", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ name: "batuhan" }),
  });
  expect(post.status).toBe(200);
  expect(post.headers.get("content-type")).toBe("application/json; charset=utf-8");
  // The platform owns caching for a dynamic URL the purger cannot enumerate.
  expect(post.headers.get("cache-control")).toBe("private, no-store");
  expect(await post.json()).toEqual({ hello: "batuhan" });

  // Form bodies parse through the same sf_body() seam.
  const form = await get(rt, HOST, "/hello", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ name: "form" }).toString(),
  });
  expect(await form.json()).toEqual({ hello: "form" });

  // No body at all is not an error: the handler's `?? 'world'` decides.
  const bare = await get(rt, HOST, "/hello");
  expect(bare.status).toBe(200);
  expect(await bare.json()).toEqual({ hello: "world" });

  const head = await get(rt, HOST, "/hello", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(await head.text()).toBe("");
});

test("the handler runs inside the prelude's jail, with platform secrets scrubbed", async () => {
  const blobsRoot = path.join(rt.storageRoot, "spaces", SPACE, "blobs");

  const response = await get(rt, HOST, "/probe");
  expect(response.status).toBe(200);
  // SAFETY: test-owned probe fixture; every field is pinned below, so a shape
  // drift fails the test rather than hiding.
  const report = (await response.json()) as {
    open_basedir: string;
    dispatch_token: string | false;
    dispatch_token_server: string | null;
    site_env_runtime_bin: string | false;
    api_url: string;
    auth: Record<string, unknown>;
  };

  // The jail is this space's content store plus its per-request scratch tmp,
  // nothing else.
  expect(report.open_basedir.startsWith(`${blobsRoot}:`)).toBe(true);
  expect(report.open_basedir).toContain(
    `${path.join(rt.storageRoot, "spaces", SPACE, "tmp")}/php-fx-`,
  );

  // The fleet-wide dispatch credential is gone from every surface a handler or
  // its subprocess reads. A site-scoped value is left: the team owns the box.
  expect(report.dispatch_token).toBe(false);
  expect(report.dispatch_token_server).toBeNull();
  expect(report.site_env_runtime_bin).not.toBe(false);
  expect(report.api_url).toBe("https://api.spacefast.com/health");

  // sf_auth() consumed the engine's verified visitor context: no cookie means
  // the guest identity.
  expect(report.auth["isGuest"]).toBe(true);
  expect(report.auth["isAuthenticated"]).toBe(false);
  expect(report.auth["provider"]).toBe("guest");
});

test("a safe public PHP function response opts into the edge cache", async () => {
  const cached = await get(rt, HOST, "/cache");
  expect(cached.status).toBe(200);
  expect(cached.headers.get("cache-control")).toBe("public, max-age=3600");
  expect(cached.headers.get("a8c-edge-cache")).toBe("cache");
  expect(await cached.text()).toBe("cached");

  // The provider cache is method-blind. A POST must not populate the URL that
  // a later GET reads, even when the handler declares a public policy.
  const post = await get(rt, HOST, "/cache", { method: "POST" });
  expect(post.status).toBe(200);
  expect(post.headers.get("cache-control")).toBe("private, no-store");
  expect(post.headers.get("a8c-edge-cache")).toBe("no-cache");

  // A stateful response revokes the shared grant before the platform writes
  // the edge opt-in. The Set-Cookie still reaches the visitor.
  const stateful = await get(rt, HOST, "/cache?cookie=1");
  expect(stateful.status).toBe(200);
  expect(stateful.headers.get("cache-control")).toBe("no-store");
  expect(stateful.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(stateful.headers.get("set-cookie")).toContain("preview=one");
});

test("brokered capabilities reach the native broker from inside the jail", async () => {
  // Only the broker emits this code, so reaching it proves a subprocess still
  // spawns after `open_basedir` is pinned: the jail is a check inside PHP's own
  // file APIs and never reaches `proc_open` (tenant-prelude.php CANNOT.A).
  const spam = await get(rt, HOST, "/spam");
  expect(spam.status).toBe(200);
  expect(((await spam.json()) as { code?: string }).code).toBe("service_not_configured");

  // Same broker, second half of the containment story: the configured sender is
  // reachable only from process memory by now, so these two codes are what the
  // pre-jail resolution buys. Bind after hardening instead and they become
  // service_payload_invalid / service_not_configured.
  const email = await get(rt, HOST, "/email");
  expect(email.status).toBe(200);
  expect(((await email.json()) as { codes?: string[] }).codes).toEqual([
    "email_outbox_unavailable",
    "email_sender_unverified",
  ]);

  // "No database attached" answered as its own problem document, not a driver
  // error: sf_db() hands back a handle and has no other moment to say so.
  const db = await get(rt, HOST, "/db");
  expect(db.status).toBe(503);
  expect(db.headers.get("content-type")).toBe("application/problem+json");
  expect(((await db.json()) as { code?: string }).code).toBe("php_function_database_unavailable");
});

test("spam evidence: the trusted visitor IP fills a check's userIp; corrections name their own", async () => {
  const response = await get(rt, HOST, "/spam-evidence");
  expect(response.status).toBe(200);
  expect(((await response.json()) as { codes?: string[] }).codes).toEqual([
    // Past validation with no caller-named userIp: the trusted visitor IP
    // captured at dispatch carried it to the broker.
    "service_not_configured",
    // The correction verb exists in this lane and reaches the same broker.
    "service_not_configured",
    // A correction without its own userIp never spawns: no request defaults.
    "service_payload_invalid",
  ]);
});

test("a Space whose only code is PHP still receives its variables", async () => {
  // No worker, no capsule: `index.html` plus a handler, which is the whole of
  // what declares this lane. Neither runtime config artifact exists for such a
  // version, so its values arrive in the PHP lane's own — the shape finalize
  // sends when it has nowhere else to put them.
  const space = "spc_phpfx_only";
  await deploy(rt, {
    spaceId: space,
    versionId: "ver_phpfx_only_1",
    metadata: { mode: "website", title: "PHP only" },
    files: { "index.html": "<h1>php only</h1>\n", "functions/env.php": ENV_PHP },
    php: { variableValues: { GITHUB_TOKEN: FG_TOKEN } },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "PHP only" }),
      production_hostnames: ["phpfx-only.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, "phpfx-only.test", "/env");
  expect(response.status).toBe(200);
  // SAFETY: ENV_PHP above is this fixture's own handler; both fields are pinned
  // below, so a shape drift fails rather than hides.
  const report = (await response.json()) as { selected: string | null; absent: string | null };
  expect(report.selected).toBe(FG_TOKEN);
  expect(report.absent).toBeNull();
});

test("sf_env answers this Space's own selected variables, and nothing the platform owns", async () => {
  const response = await get(rt, FG_HOST, "/env");
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({
    // Resolved from the worker configuration before the jail denied the file
    // it lives in.
    selected: FG_TOKEN,
    // Not configured reads as absent, never as a configured empty string.
    absent: null,
    // The platform's namespace and the connection-string family stay withheld
    // even when the configuration carries them: sf_db() hands this lane frames,
    // and a worker that could name SPACEFAST_* could spoof one.
    reserved: null,
    database: null,
    // sf_env is the only reader. The prelude emptied the process environment,
    // so a handler reaching for the same name there finds nothing.
    from_process_env: false,
  });
});

test("sf_fetch applies the Space's own egress scope, and refuses before it connects", async () => {
  // Anonymous: the trusted list only, and the refusal is the upsell.
  const unclaimed = await get(rt, FG_HOST, "/fetch");
  expect(unclaimed.status).toBe(200);
  // SAFETY: test-owned probe fixture; every code is pinned below, so a shape
  // drift fails the test rather than hiding.
  expect(((await unclaimed.json()) as { codes?: string[] }).codes).toEqual([
    "zero_fetch_host_untrusted",
    // Only HTTPS leaves a tenant handler.
    "zero_fetch_payload_invalid",
    // Loopback, and an infrastructure denial keeps its own reason: nobody is
    // told to claim a Space to reach one.
    "zero_fetch_target_denied",
    // Smuggled URL credentials.
    "zero_fetch_payload_invalid",
    // A header value that would rewrite the request.
    "zero_fetch_payload_invalid",
    // Cloud metadata.
    "zero_fetch_target_denied",
  ]);

  // Claimed: the same probe, one different answer. The public literal now
  // passes the scope and spends its 1ms budget against a socket, which is what
  // proves this lane binds serving's claim flag rather than defaulting to it.
  const claimed = await get(rt, HOST, "/fetch");
  expect(claimed.status).toBe(200);
  // SAFETY: as above — the same fixture, fully pinned.
  expect(((await claimed.json()) as { codes?: string[] }).codes).toEqual([
    "zero_fetch_upstream_unavailable",
    "zero_fetch_payload_invalid",
    "zero_fetch_target_denied",
    "zero_fetch_payload_invalid",
    "zero_fetch_payload_invalid",
    "zero_fetch_target_denied",
  ]);
});

test("the route/non-route boundary under functions/", async () => {
  // The handler source never serves: same answer as a genuine miss.
  const source = await get(rt, HOST, "/functions/hello.php");
  expect(source.status).toBe(404);
  const compressedSource = await get(rt, HOST, "/functions/hello.php.gz");
  expect(compressedSource.status).toBe(404);

  // A pattern-named .php is not routable in this slice and stays inert.
  const inert = await get(rt, HOST, "/functions/%5Bid%5D.php");
  expect(inert.status).toBe(200);
  expect(inert.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  expect(inert.headers.get("content-disposition")).toBe("attachment");
  expect(await inert.text()).toBe("<?php echo 'never executed';\n");

  // Static resolution is untouched beside the routes.
  const home = await get(rt, HOST, "/");
  expect(home.status).toBe(200);
  expect(await home.text()).toBe("<h1>static home</h1>\n");
});
