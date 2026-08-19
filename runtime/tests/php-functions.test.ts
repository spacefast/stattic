// PHP Functions (slice A): a committed `functions/<route>.php` EXECUTES at its
// derived route, inside the worker `_stattic_tenant_harden()` jailed first.
//
// What this suite holds, and only this: the serve-side seams the `t=php` lane
// adds — execution through the hardened worker, the sf_* helper prelude
// (sf_body/sf_json/sf_auth + the brokered sf_db/sf_spam/sf_email), the
// containment evidence read FROM INSIDE a handler, platform header ownership
// over handler output, and the route/non-route boundary under `functions/`.
// Deliberately not here:
//   * the compile shape (`t=php` action, hidden source, inert non-routes) —
//     responses.rs `functions_route_php_compiles_to_a_php_action_and_hides_its_source`;
//   * the inert-attachment serving of a stray root-level `.php` —
//     routing.test.ts "php-like uploads are inert text, never executed";
//   * the prelude's own jail/scrub mechanics under the CLI —
//     tenant-prelude.test.ts;
//   * what the service broker does with a frame once it has one (grant
//     enforcement, Akismet's shape, the outbox row) — services.rs's own tests
//     and the relay's transport suite, functions-relay.test.ts.
import { afterAll, beforeAll, expect, test } from "bun:test";
import path from "node:path";

import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";

const HOST = "phpfx.test";
const SPACE = "spc_phpfx";
const VERSION = "ver_phpfx_1";
// The task's canonical handler, verbatim: parsed body in, JSON out.
const HELLO_PHP = "<?php sf_json(['hello' => sf_body()['name'] ?? 'world']);\n";

// Containment evidence from INSIDE the handler reports the jail and the
// surfaces the scrub must have emptied.
const PROBE_PHP = `<?php
$serverLeaks = [];
foreach (array_keys($_SERVER) as $name) {
    if (is_string($name) && (str_starts_with($name, 'SPACEFAST_') || str_starts_with($name, 'DB_'))) {
        $serverLeaks[] = $name;
    }
}
sf_json([
    'open_basedir' => (string) ini_get('open_basedir'),
    'env_runtime_bin' => getenv('SPACEFAST_RUNTIME_BIN'),
    'server_leaks' => $serverLeaks,
    'auth' => sf_auth(),
]);
`;

// sf_db() is wired to the broker, so "unavailable" here means the SPACE has no
// database attached — a publish-time fact, answered as its own problem.
const DB_PHP = "<?php sf_db()->query('SELECT 1');\n";

// The one verified sender this fixture's runtime is configured with. It reaches
// the broker ONLY if the bridge resolved it before the jail: by the time the
// handler runs, getenv() cannot see it (the probe above proves that for
// SPACEFAST_RUNTIME_BIN, scrubbed by the same pass).
const SENDER = "hello@phpfx.test";

// Both brokered services, reported as the codes a handler can branch on. No
// Akismet key is configured, so the spam frame is refused by the broker before
// any upstream call — reaching that refusal at all means the native executor
// ran, under the jail, with the platform's own environment.
const SPAM_PHP = `<?php
try {
    sf_spam(['content' => 'buy pills', 'userIp' => '203.0.113.9', 'type' => 'contact-form']);
    sf_json(['code' => 'no_refusal']);
} catch (SpacefastServiceError $error) {
    sf_json(['code' => $error->errorCode]);
}
`;

// Two sends in one request: the first leaves `from` out and takes the space's
// configured sender, the second names an address the space has not proven it
// owns. Two different codes from two different gates — and a second brokered
// call in one request, which the bridge has to be able to make at all.
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

let rt: Runtime;

beforeAll(async () => {
  // Platform service configuration, exactly as a wp.cloud site carries it: a
  // process environment variable the tenant prelude will scrub away.
  rt = await startRuntime({ env: { SPACEFAST_SERVICE_EMAIL_SENDERS: SENDER } });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    metadata: { mode: "website", title: "PHP Functions" },
    files: {
      "index.html": "<h1>static home</h1>\n",
      "functions/hello.php": HELLO_PHP,
      "functions/probe.php": PROBE_PHP,
      "functions/spam.php": SPAM_PHP,
      "functions/email.php": EMAIL_PHP,
      "functions/db.php": DB_PHP,
      // A pattern-named module is not an expressible table key in this slice:
      // it must stay an inert attachment, not execute and not vanish.
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
  expect(post.headers.get("cache-control")).toBe("no-store");
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

  // HEAD answers headers only.
  const head = await get(rt, HOST, "/hello", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(await head.text()).toBe("");
});

test("the handler runs inside the prelude's jail, with platform secrets scrubbed", async () => {
  const blobsRoot = path.join(rt.storageRoot, "spaces", SPACE, "blobs");

  const response = await get(rt, HOST, "/probe");
  expect(response.status).toBe(200);
  const report = (await response.json()) as {
    open_basedir: string;
    env_runtime_bin: string | false;
    server_leaks: string[];
    auth: Record<string, unknown>;
  };

  // The jail is this space's own content store plus its per-request scratch
  // tmp — nothing else. (`ini_get` read from inside the tenant include.)
  expect(report.open_basedir.startsWith(`${blobsRoot}:`)).toBe(true);
  expect(report.open_basedir).toContain(
    `${path.join(rt.storageRoot, "spaces", SPACE, "tmp")}/php-fx-`,
  );

  // The scrub emptied every surface a handler or its subprocess reads.
  expect(report.env_runtime_bin).toBe(false);
  expect(report.server_leaks).toEqual([]);

  // sf_auth() consumed the engine's verified visitor context: no cookie means
  // the guest identity, in the Zero auth-context shape.
  expect(report.auth["isGuest"]).toBe(true);
  expect(report.auth["isAuthenticated"]).toBe(false);
  expect(report.auth["provider"]).toBe("guest");
});

test("brokered capabilities reach the native broker from inside the jail", async () => {
  // The spam frame reached the executor and came back refused BY IT: this
  // runtime has no Akismet key, and the broker checks that before any upstream
  // call. Only the broker emits that code, so reaching it proves a subprocess
  // still spawns after `open_basedir` is pinned — the jail is a check inside
  // PHP's own file APIs and never reaches `proc_open`, which is what
  // tenant-prelude.php CANNOT.A says from the other side. This is the claim
  // worth pinning by execution rather than by argument.
  const spam = await get(rt, HOST, "/spam");
  expect(spam.status).toBe(200);
  expect(((await spam.json()) as { code?: string }).code).toBe("service_not_configured");

  // Mail, same broker, and the second half of the containment story: the
  // configured sender is only reachable from process memory by now, so these
  // two codes are what the pre-jail resolution buys. (Bind after hardening
  // instead and they become service_payload_invalid / service_not_configured —
  // an empty `from` and a broker with no sender list.) The send with no `from`
  // takes the space's own verified address and gets as far as the outbox this
  // Space has no database for; the one naming a foreign address is refused as
  // unverified.
  const email = await get(rt, HOST, "/email");
  expect(email.status).toBe(200);
  expect(((await email.json()) as { codes?: string[] }).codes).toEqual([
    "email_outbox_unavailable",
    "email_sender_unverified",
  ]);

  // Same "no database attached" fact, answered as its own problem document
  // rather than a driver error, because sf_db() hands back a handle and has no
  // other moment to say so.
  const db = await get(rt, HOST, "/db");
  expect(db.status).toBe(503);
  expect(db.headers.get("content-type")).toBe("application/problem+json");
  expect(((await db.json()) as { code?: string }).code).toBe("php_function_database_unavailable");
});

test("the route/non-route boundary under functions/", async () => {
  // The handler source never serves — same answer as a genuine miss.
  const source = await get(rt, HOST, "/functions/hello.php");
  expect(source.status).toBe(404);

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
