// Full runtime acceptance against real WordPress and MariaDB.
// Disposable DB credentials are intentionally local to this isolated test server.
import { test } from "bun:test";
import assert from "node:assert/strict";
import {
  existsSync,
  cpSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  writeFileSync,
  rmSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";

import { z } from "zod";

import { zeroRuntimePhysicalTableName } from "../../apps/control-plane/src/legacy-zero.ts";
import { prepareZeroV2SourcePublish } from "../../packages/cli/src/zero-v2-source-publish.ts";
import type { RuntimeVersionFinalizeRequest } from "../../packages/common/src/contracts/runtime-api.ts";
import { collectManifest, readManifestFile } from "../../packages/sdk/src/publish/node.ts";
import { zeroV2WriteFixture } from "./fixtures/zero-v2-write.ts";
import {
  api,
  blobsHaveMissing,
  createDeclaredSession,
  deploy,
  finalize,
  get,
  publicAccessConfig,
  putRoute,
  putBlob,
  uploadSessionBlobs,
  startRuntime,
  versionRoot,
} from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

test(
  "hosted Zero versions share scoped WordPress state and preserve migration data",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-zero-v2-hosted-"));
    const cache = path.join(work, "cache");
    mkdirSync(cache);
    const project = path.join(work, "shop");
    mkdirSync(path.join(project, "src"), { recursive: true });
    mkdirSync(path.join(project, "node_modules/shared"), { recursive: true });
    mkdirSync(path.join(project, "public"));
    const migrationMetadata = {
      "0001_adopt.sql": "CREATE TABLE zero_untrusted_source_sql (id INT);",
      "snapshot.json": JSON.stringify({ version: 2, tables: {} }),
    };
    mkdirSync(path.join(project, "zero/migrations"), { recursive: true });
    for (const [name, content] of Object.entries(migrationMetadata)) {
      writeFileSync(path.join(project, "zero/migrations", name), content);
    }
    writeFileSync(
      path.join(project, "node_modules/shared/package.json"),
      JSON.stringify({ type: "module", exports: "./index.js" }),
    );
    writeFileSync(
      path.join(project, "node_modules/shared/index.js"),
      "export function suffix(value) { return value + '-installed'; }",
    );
    writeFileSync(
      path.join(project, "public/index.html"),
      "<!doctype html><title>Source compiled shop</title><h1>Shop</h1>",
    );
    writeFileSync(
      path.join(project, "public/catalog.json"),
      JSON.stringify(
        Array.from({ length: 100 }, (_, id) => ({ id, title: `Product ${id}`, price: 1999 + id })),
      ),
    );
    writeFileSync(
      path.join(project, "zero.config.ts"),
      `
import { defineConfig, table, text, integer, boolean, timestamp, action, db } from 'zero';
import { version } from './src/version';
import { suffix } from 'shared';
const customers = table({ name: text().notNull(), email: text().notNull().max(191).unique(), active: boolean().default(true), created: timestamp().defaultNow() });
const products = table({ title: text().notNull(), sku: text().max(191).unique(), price: integer().notNull(), stock: integer().default(0), published: boolean().default(false), ...(version === "v2" ? { category: text() } : {}) });
const orders = table({ customerId: integer().notNull(), status: text().notNull(), total: integer().notNull(), created: timestamp().defaultNow() });
const items = table({ orderId: integer().notNull(), productId: integer().notNull(), quantity: integer().notNull(), price: integer().notNull() });
const comments = table({ customerId: integer(), body: text().notNull(), approved: boolean().default(false), created: timestamp().defaultNow() });
export default defineConfig({ tables: { customers, products, orders, items, comments }, actions: {
  version: action({ access: 'anyone', handler() { return suffix(version); } }),
  environment: action({ access: 'anyone', handler() { return { value: process.env.APP_VALUE ?? null, empty: process.env.ZERO_PROOF_EMPTY ?? null, hostDatabaseHidden: process.env.DB_PASSWORD === undefined, hostProcessHidden: process.env.ZERO_PROOF_HOST_ONLY === undefined }; } }),
  shipping: action({ input: { amount: integer().notNull() }, access: 'anyone', handler({ amount }) { return amount > 5000 ? 0 : 599; } }),
  addCustomer: action({ input: { name: text().notNull(), email: text().notNull() }, access: 'anyone', async handler({ name, email }) { const rows = await db.insert(customers).values({ name, email }).returning(); return { id: rows[0]?.id ?? null }; } }),
  ...(version === "v2" ? { discount: action({ access: "anyone", handler() { return 10; } }) } : {})
} });
`,
    );

    const db = await startMysqlContainer({
      namePrefix: "sf-zero-v2-proof",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    // The control plane's callback receiver, standing in for the notice intake.
    const notices: { authorization: string; body: any }[] = [];
    let noticed: () => void = () => {};
    const receiver = Bun.serve({
      hostname: "127.0.0.1",
      port: 0,
      fetch: async (request) => {
        notices.push({
          authorization: request.headers.get("authorization") ?? "",
          body: await request.json(),
        });
        noticed();
        return Response.json({ ok: true });
      },
    });
    // The box's php-fpm namespace has no `wp` or `php` to spawn; finalize and the
    // Users lane get the same, so the users copy below is proven in-process.
    const fpmBin = path.join(work, "fpm-bin");
    mkdirSync(fpmBin);
    for (const name of ["wp", "php"]) {
      writeFileSync(path.join(fpmBin, name), "#!/bin/sh\nexit 127\n", { mode: 0o755 });
    }
    const rt = await startRuntime({
      env: {
        PATH: `${fpmBin}:${process.env.PATH ?? ""}`,
        ZERO_PROOF_HOST_ONLY: "host-process-canary",
        SPACEFAST_ZERO_DATABASE_URL: db.url,
        ZERO_PROOF_EMPTY: "host-nonempty-canary",
      },
    });
    const space = "spc_zero_v2_proof";
    const host = "zero-v2-proof.test";
    try {
      const wp = await installWordpress(rt, work, db, host);
      // Both boundaries are exercised against real runtime ingest.
      for (const files of [
        [
          { path: ".zero-source/manifest.json", size: 2 },
          { path: "unsafe.PHP", size: 2 },
        ],
        [{ path: ".zero-compiled/manifest.json", size: 2 }],
      ]) {
        const response = await api(
          rt,
          "POST",
          `/__spacefast/api.php/spaces/${space}/versions`,
          "create_version",
          { space_id: space },
          { version_id: "ver_refused", files },
        );
        assert.equal(response.status, 422, await response.text());
      }
      // Capture two source-only bundles through the CLI step used by sf publish.
      const publish = async (
        id: string,
        value: string,
        spaceId = space,
        sourceProject = project,
        authority: Pick<RuntimeVersionFinalizeRequest, "zero_generation"> = {
          zero_generation: "none",
        },
      ) => {
        if (sourceProject === project)
          writeFileSync(path.join(project, "src/version.ts"), `export const version = '${value}';`);
        const step = await prepareZeroV2SourcePublish(sourceProject);
        assert.ok(step);
        try {
          const manifest = await collectManifest(step.publishDirectory, true, {
            includeHashes: true,
            additionalFiles: step.additionalFiles,
          });
          assert.ok(manifest.files.some((f) => f.path === ".zero-source/vendor.zero.json"));
          assert.equal(
            manifest.files.some((f) => f.path.includes("node_modules/")),
            false,
          );
          const uploaded: Record<string, Uint8Array> = {};
          for (const file of manifest.files) uploaded[file.path] = await readManifestFile(file);
          const session = await createDeclaredSession(rt, spaceId, id, uploaded);
          const missing = await blobsHaveMissing(
            rt,
            session,
            session.manifest.flatMap((f) => (f.sha256 ? [f.sha256] : [])),
          );
          const missingSet = new Set(missing);
          const sent = Object.entries(uploaded).filter(([p]) =>
            missingSet.has(session.manifest.find((f) => f.path === p)?.sha256 ?? ""),
          );
          for (const sha of missing) {
            const entry = session.manifest.find((f) => f.sha256 === sha);
            assert.ok(entry);
            const response = await putBlob(rt, spaceId, session.token, sha, uploaded[entry.path]);
            assert.ok(response.ok, await response.text());
          }
          const response = await finalize(rt, spaceId, id, {
            upload_id: session.uploadId,
            ...authority,
            zero_v2: {
              variableValues: { APP_VALUE: `${spaceId}/${value}`, ZERO_PROOF_EMPTY: "" },
              realtime: {
                url: `http://127.0.0.1:${receiver.port}/v1/runtime/events/callback`,
                token: `notice-token-${spaceId}-${id}`,
                castResourceKey: `res_${spaceId}`,
                castWsUrl: "wss://cast.zero-v2-proof.test/_ws/socket/websocket",
              },
            },
          });
          const receipt = await response.json();
          assert.equal(response.status, 200, JSON.stringify(receipt));
          const metadata = JSON.parse(
            readFileSync(path.join(versionRoot(rt, spaceId, id), "metadata.json"), "utf8"),
          );
          assert.equal(metadata.zeroV2.compiler, "0.3.0");
          console.log(
            JSON.stringify({
              version: id,
              sourceFiles: manifest.files.length,
              uploadedBlobs: missing.length,
              uploadedPaths: sent.map(([p]) => p),
              telemetry: receipt.data?.telemetry ?? receipt.telemetry,
              metadata: metadata.zeroV2,
            }),
          );
          return {
            receipt,
            missing,
            sent: sent.map(([p, bytes]) => ({
              path: p,
              bytes: bytes.byteLength,
            })),
            metadata,
            sourceBytes: manifest.files
              .filter((f) => f.path.startsWith(".zero-source/"))
              .reduce((n, f) => n + f.size, 0),
            uploadBytes: sent.reduce((n, [, bytes]) => n + bytes.byteLength, 0),
            compileMs:
              receipt.data?.telemetry?.zeroV2CompileMs ?? receipt.telemetry?.zeroV2CompileMs,
          };
        } finally {
          await step.cleanup?.();
        }
      };
      const fixture = path.join(rt.root, "wp-content/mu-plugins/zero-scope-proof.php");
      const mailbox = path.join(work, "mail");
      mkdirSync(mailbox);
      writeFileSync(
        fixture,
        `<?php
// This disposable stack serves plain HTTP; production identity pages require HTTPS.
define('ZERO_IDENTITY_DEV_HTTP', true);
// Outgoing mail stays on disk, one file per recipient, so the suite reads sign-in codes.
add_filter('pre_wp_mail', static function ($short, array $atts) {
  file_put_contents(${JSON.stringify(mailbox)} . '/' . basename((string) $atts['to']), (string) $atts['message']);
  return true;
}, 10, 2);
add_action('rest_api_init', static function () {
  register_rest_route('zero-proof/v1', '/state', [
    'methods' => ['GET', 'POST'], 'permission_callback' => '__return_true',
    'callback' => static function ($request) {
      global $wpdb;
      if ($request->get_method() === 'POST') {
        $label = $request->get_param('label');
        update_option('zero_scope_proof', $label);
        $wpdb->insert($wpdb->prefix . 'zero_products', ['title' => $label, 'price' => 100]);
        wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => $label]);
        $user = wp_insert_user(['user_login' => 'same-login', 'user_pass' => 'disposable-scope-proof', 'display_name' => $label]);
        if (is_wp_error($user)) return $user;
        as_schedule_single_action(time() + 3600, 'zero_scope_proof_job', ['label' => $label], 'proof');
      }
      $cache = new WP_Object_Cache();
      $a = new Spacefast_Zero_Cache($cache, 'proof-a');
      $b = new Spacefast_Zero_Cache($cache, 'proof-b');
      $a->set('same-key', 'a', 'users'); $b->set('same-key', 'b', 'users');
      $before = [$a->get('same-key', 'users'), $b->get('same-key', 'users')];
      $a->flush();
      $cacheProof = [$before, $a->get('same-key', 'users'), $b->get('same-key', 'users')];
      return [
        'prefix' => $wpdb->prefix, 'salt' => hash('sha256', wp_salt('auth')), 'aliases' => apply_filters('zero_identity_session_cookie_aliases', []), 'option' => get_option('zero_scope_proof'),
        'products' => $wpdb->get_col('SELECT title FROM ' . $wpdb->prefix . 'zero_products'),
        'uploads' => wp_upload_dir(),
        'users' => array_values(array_map(static fn ($user) => $user->display_name, get_users())),
        'posts' => array_values(array_map(static fn ($post) => $post->post_title, get_posts())),
        'jobs' => array_values(array_map(static fn ($job) => $job->get_args(), as_get_scheduled_actions(['hook' => 'zero_scope_proof_job']))),
        'cache' => $cacheProof,
      ];
    },
  ]);
});
`,
      );
      const saltBefore = wp("eval", "echo hash('sha256', wp_salt('auth'));").trim();
      const first = await publish("ver_zero_v2_first", "v1");
      const second = await publish("ver_zero_v2_second", "v2");
      for (const id of ["ver_zero_v2_first", "ver_zero_v2_second"]) {
        for (const [name, content] of Object.entries(migrationMetadata)) {
          assert.equal(
            readFileSync(
              path.join(versionRoot(rt, space, id), ".zero-compiled/source-migrations", name),
              "utf8",
            ),
            content,
          );
        }
      }
      assert.equal(db.exec("SHOW TABLES LIKE 'zero_untrusted_source_sql'"), "");
      assert.equal(
        second.missing.length,
        2,
        "Republish uploads changed app and hash manifest, reuses vendor and assets",
      );
      assert.deepEqual(second.sent.map((f) => f.path).toSorted(), [
        ".zero-source/app.zero.json",
        ".zero-source/manifest.json",
      ]);
      // Config-only publications inherit the source catalog and must take the same lock.
      const retained = await createDeclaredSession(
        rt,
        space,
        "ver_zero_v2_retained",
        {},
        {
          reusableVersionId: "ver_zero_v2_second",
          retention: "all",
        },
      );
      const lock = Bun.spawn({
        cmd: [
          "php",
          "-r",
          "$h=fopen($argv[1],'c'); if (!flock($h, LOCK_EX)) exit(1); echo 'locked'; fflush(STDOUT); fgets(STDIN);",
          path.join(rt.storageRoot, "spaces", space, "zero-v2-finalize.lock"),
        ],
        stdin: "pipe",
        stdout: "pipe",
        stderr: "pipe",
      });
      try {
        const reader = lock.stdout.getReader();
        const acquired = await reader.read();
        reader.releaseLock();
        assert.equal(new TextDecoder().decode(acquired.value), "locked");
        const refused = await finalize(rt, space, retained.versionId, {
          upload_id: retained.uploadId,
          zero_generation: "v2",
        });
        assert.equal(refused.status, 409, await refused.clone().text());
        assert.equal((await refused.json()).code, "zero_v2_finalize_busy");
      } finally {
        lock.stdin.write("\n");
        lock.stdin.end();
        assert.equal(await lock.exited, 0);
      }
      const retainedResponse = await finalize(rt, space, retained.versionId, {
        upload_id: retained.uploadId,
        zero_generation: "v2",
      });
      assert.equal(retainedResponse.status, 200, await retainedResponse.text());
      const activate = (version: string) =>
        putRoute(rt, space, "production", {
          version_id: version,
          hostnames: [host],
          production_hostnames: [host],
          version_hostnames: [
            { hostname: "zero-v2-proof--v1.test", version_id: "ver_zero_v2_first" },
            { hostname: "zero-v2-proof--v2.test", version_id: "ver_zero_v2_second" },
            { hostname: "zero-v2-proof--empty.test", version_id: "ver_zero_v2_retained" },
          ],
          config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
        });
      await activate("ver_zero_v2_second");
      const moduleUrl = (source: string) => {
        const hash = new Bun.CryptoHasher("sha256").update(source).digest("hex");
        const file = path.join(work, `client-${hash}.mjs`);
        writeFileSync(file, source);
        return pathToFileURL(file).href;
      };
      const loadGeneratedClient = async (hostname: string) => {
        const response = await get(rt, hostname, "/__spacefast/zero/client.js");
        const javascript = await response.text();
        assert.equal(response.status, 200, javascript);
        const appUrl = moduleUrl(javascript);
        const shared = await get(rt, hostname, "/__spacefast/client.js");
        const sharedJavascript = await shared.text();
        assert.equal(shared.status, 200, sharedJavascript.slice(0, 1000));
        // Resolve the served module's absolute browser import for this local module runner.
        const module = await import(
          moduleUrl(
            sharedJavascript.replace('"/__spacefast/zero/client.js"', JSON.stringify(appUrl)),
          )
        );
        assert.deepEqual(module.getIdentity(), { userId: null });
        assert.equal(module.readConnectorRoleState("proof"), null);
        assert.equal(module.canonicalPagePath("/a/../proof/"), "/proof");
        assert.ok(Object.hasOwn(module, "createCollab"));
        const client = module.createClient({
          url: `http://${hostname}/wp-json/zero/v1`,
          auth: { guests: false },
          fetch: async (input: RequestInfo | URL, init?: RequestInit) => {
            const headers = Object.fromEntries(new Headers(init?.headers));
            const fetched = await get(
              rt,
              hostname,
              new URL(input instanceof Request ? input.url : String(input)).pathname,
              { ...init, headers },
            );
            return fetched;
          },
        });
        return { client, module, javascript };
      };
      const generatedClient = async (hostname: string, expected: string, category: boolean) => {
        const { client, module, javascript } = await loadGeneratedClient(hostname);
        assert.equal(module.SCHEMA.collections.products !== undefined, true);
        assert.equal(await client.actions.version(), expected);
        const types = await get(rt, hostname, "/__spacefast/zero/client.d.ts");
        assert.equal(types.status, 200);
        const declarations = await types.text();
        // These are generated declarations, not implementation-source assertions.
        assert.equal(declarations.includes("category"), category);
        return new Bun.CryptoHasher("sha256").update(javascript).digest("hex");
      };
      const firstClient = await generatedClient("zero-v2-proof--v1.test", "v1-installed", false);
      const secondClient = await generatedClient(host, "v2-installed", true);
      assert.notEqual(firstClient, secondClient);
      const call = async (hostname: string, expected: string) => {
        const response = await get(rt, hostname, "/wp-json/zero/v1/actions/version", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: "{}",
        });
        const body = await response.text();
        console.log(JSON.stringify({ hostname, status: response.status, body }));
        assert.equal(response.status, 200, body);
        assert.equal(JSON.parse(body).data, expected);
      };
      const environmentProof: Record<
        string,
        {
          value: string | null;
          empty: string | null;
          hostDatabaseHidden: boolean;
          hostProcessHidden: boolean;
        }
      > = {};
      const checkEnvironment = async (hostname: string, expected: string | null) => {
        const response = await get(rt, hostname, "/wp-json/zero/v1/actions/environment", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: "{}",
        });
        const body = await response.json();
        assert.equal(response.status, 200, JSON.stringify(body));
        assert.deepEqual(body.data, {
          value: expected,
          empty: expected === null ? null : "",
          hostDatabaseHidden: true,
          hostProcessHidden: true,
        });
        environmentProof[hostname] = body.data;
      };
      await call(host, "v2-installed");
      await call("zero-v2-proof--v1.test", "v1-installed");
      // Live updates: a committed write posts one id-only notice after the response.
      const beforeWrite = notices.length;
      const arrived = new Promise<void>((resolve) => (noticed = resolve));
      const written = await get(rt, host, "/wp-json/zero/v1/actions/addCustomer", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ name: "Realtime Ada", email: "ada@realtime.test" }),
      });
      const writtenBody = await written.text();
      assert.equal(written.status, 200, writtenBody);
      // The engine posts after the response is flushed; wait for that request itself.
      await Promise.race([
        arrived,
        new Promise((_, reject) => setTimeout(() => reject(new Error("no notice posted")), 5_000)),
      ]);
      const posted = notices.slice(beforeWrite);
      assert.equal(posted.length, 1, JSON.stringify(posted));
      assert.equal(posted[0].authorization, `Bearer notice-token-${space}-ver_zero_v2_second`);
      assert.equal(posted[0].body.event.event, "zero.changes");
      assert.equal(posted[0].body.event.space_id, space);
      assert.equal(posted[0].body.event.version_id, "ver_zero_v2_second");
      assert.deepEqual(posted[0].body.event.payload.changes, [
        { collection: "customers", ops: ["create"], ids: [JSON.parse(writtenBody).data.id] },
      ]);
      assert.equal(JSON.stringify(posted[0].body).includes("Realtime Ada"), false, "no row values");
      const transport = await get(rt, host, "/__spacefast/zero/realtime.js");
      const transportScript = await transport.text();
      assert.equal(transport.status, 200, transportScript.slice(0, 500));
      assert.ok(transportScript.includes(`"resourceKey":"res_${space}"`));
      assert.ok(transportScript.includes('"ticketPath":"/__zero/realtime-ticket"'));
      assert.equal(
        transportScript.includes("notice-token-"),
        false,
        "the callback token stays on the box",
      );
      const sdk = await get(rt, host, "/__spacefast/sdk.js");
      const sdkBody = await sdk.text();
      assert.ok(
        sdkBody.includes(JSON.stringify("/__spacefast/zero/realtime.js").replaceAll("/", "\\/")),
        `v2 pages load the transport: ${sdk.status} ${sdkBody.slice(0, 600)}`,
      );
      await checkEnvironment(host, `${space}/v2`);
      await checkEnvironment("zero-v2-proof--v1.test", `${space}/v1`);
      await checkEnvironment("zero-v2-proof--empty.test", null);
      const scopeState = async (hostname: string, label?: string) => {
        const response = await get(
          rt,
          hostname,
          "/wp-json/zero-proof/v1/state",
          label
            ? {
                method: "POST",
                headers: { "content-type": "application/json" },
                body: JSON.stringify({ label }),
              }
            : {},
        );
        const body = await response.text();
        assert.equal(response.status, 200, body);
        return JSON.parse(body);
      };
      const own = await scopeState(host, "Space A");
      const otherSpace = "spc_zero_v2_other";
      const otherHost = "zero-v2-other.test";
      await publish("ver_zero_v2_other", "v2", otherSpace);
      await putRoute(rt, otherSpace, "production", {
        version_id: "ver_zero_v2_other",
        hostnames: [otherHost],
        production_hostnames: [otherHost],
        config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
      });
      const other = await scopeState(otherHost, "Space B");
      await checkEnvironment(otherHost, `${otherSpace}/v2`);
      assert.notEqual(own.prefix, other.prefix);
      assert.equal(own.salt, saltBefore);
      assert.equal(other.salt, saltBefore);
      assert.deepEqual(own.aliases, []);
      assert.deepEqual(other.aliases, []);
      for (const [hostname, label] of [
        [host, "Space A"],
        [otherHost, "Space B"],
      ]) {
        const state = await scopeState(hostname);
        assert.equal(state.option, label);
        assert.deepEqual(state.users, [label]);
        assert.deepEqual(state.posts, [label]);
        assert.deepEqual(state.products, [label]);
        assert.equal(
          state.uploads.basedir,
          path.join(
            rt.storageRoot,
            "spaces",
            hostname === host ? space : otherSpace,
            "content-media",
          ),
        );
        assert.match(state.uploads.baseurl, /^\/__spacefast\/content-media\/[a-f0-9]{32}$/);
        assert.deepEqual(state.jobs, [{ label }]);
        assert.deepEqual(state.cache, [["a", "b"], false, "b"]);
      }
      assert.match(own.prefix, /^sfz_[a-f0-9]{24}_$/);
      assert.match(
        db.exec(`SHOW COLUMNS FROM ${own.prefix}zero_products LIKE 'category'`),
        /category/,
      );
      // Space Users on v2: the app declares no user collection, yet the Space's
      // users sign in on /identity and are the same user on /wp-json/zero/v1.
      const users = (managed: boolean) => ({
        users: {
          enabled: true,
          providers: {
            google: { mode: managed ? "managed" : "disabled" },
            gravatar: { enabled: false },
            spacefast: { enabled: false },
          },
        },
        usersProviderConfig: {
          issuer: "https://api.spacefast.com/v1/auth",
          clientId: "space-users-client",
          googleStartUrl:
            "https://api.spacefast.com/v1/auth/space-users/spc_zero_v2_proof/google/start",
          gravatarStartUrl:
            "https://api.spacefast.com/v1/auth/space-users/spc_zero_v2_proof/gravatar/start",
          directGoogle: null,
          availability: { google: { managed, direct: false }, gravatar: false, spacefast: false },
          revision: "users-test",
        },
      });
      const usersOn = async (spaceId: string, hostname: string, version: string) =>
        putRoute(rt, spaceId, "production", {
          version_id: version,
          hostnames: [hostname],
          production_hostnames: [hostname],
          config: publicAccessConfig(
            { zero_generation: "v2", ...users(spaceId === space) },
            "live_and_all_versions",
          ),
        });
      await usersOn(space, host, "ver_zero_v2_second");
      await usersOn(otherSpace, otherHost, "ver_zero_v2_other");
      const origin = (hostname: string) => `http://${hostname}`;
      const signIn = async (hostname: string, email: string) => {
        const identity = (operation: string, body: Record<string, string>) =>
          get(rt, hostname, `/__zero/auth/api/${operation}`, {
            method: "POST",
            headers: { "content-type": "application/json", origin: origin(hostname) },
            body: JSON.stringify(body),
          });
        const started = await identity("sign-in/email", { email });
        assert.equal(started.status, 200, await started.clone().text());
        const startedBody = z
          .object({ data: z.object({ challenge_id: z.string() }) })
          .parse(await started.json());
        const code = /Your code is (\d{6})/.exec(
          readFileSync(path.join(mailbox, email), "utf8"),
        )?.[1];
        assert.ok(code, "the sign-in code was mailed");
        const verified = await identity("sign-in/verify", {
          challenge_id: startedBody.data.challenge_id,
          code,
        });
        assert.deepEqual(await verified.json(), { data: { kind: "signed_in" } });
        const cookie = verified.headers
          .getSetCookie()
          .find((value) => value.startsWith("zi_session="))
          ?.split(";")[0];
        assert.ok(cookie, "sign-in sets the Space's session cookie");
        return cookie;
      };
      const ana = await signIn(host, `ana-${space}@example.test`);
      const me = async (hostname: string, cookie: string) => {
        const response = await get(rt, hostname, "/wp-json/zero/v1/me", {
          headers: { cookie, origin: origin(hostname) },
        });
        assert.equal(response.status, 200, await response.clone().text());
        return z
          .object({ data: z.object({ id: z.number(), name: z.string() }).nullable() })
          .parse(await response.json()).data;
      };
      const anaOnZero = await me(host, ana);
      assert.ok(anaOnZero, "the /identity session is a signed-in user on /wp-json/zero/v1");
      assert.equal(
        db.exec(`SELECT user_email FROM ${own.prefix}users WHERE ID=${anaOnZero.id}`),
        `ana-${space}@example.test`,
        "the user lives in the Space's own tables",
      );
      assert.equal(db.exec(`SELECT COUNT(*) FROM wp_users WHERE user_email LIKE 'ana-%'`), "0");
      const appUser = await get(rt, host, "/__zero/auth/user", {
        headers: { cookie: ana, origin: origin(host) },
      });
      const appUserBody = await appUser.json();
      assert.equal(appUser.status, 200, JSON.stringify(appUserBody));
      assert.equal(appUserBody.data.provider, "space-users");
      assert.equal(appUserBody.data.displayName, anaOnZero.name);
      assert.equal(
        appUserBody.data.userId,
        db.exec(
          `SELECT option_value FROM ${own.prefix}options WHERE option_name='spacefast_app_subject_${anaOnZero.id}_${space}'`,
        ),
        "/__zero/auth/user answers the same account's Space-scoped id",
      );
      assert.equal((await me(host, "")) ?? null, null, "no cookie, no user");
      assert.equal(await me(otherHost, ana), null, "Space A's session is nobody on Space B");
      const bob = await signIn(otherHost, `bob-${otherSpace}@example.test`);
      assert.ok(await me(otherHost, bob));
      assert.equal(await me(host, bob), null, "Space B's session is nobody on Space A");
      const sibling = await get(rt, host, "/wp-json/zero/v1/me", {
        headers: { cookie: ana, origin: "http://sibling.zero-v2-proof.test" },
      });
      assert.equal((await sibling.json()).data, null, "another origin borrows no identity");
      // Finding 2: Users binds the Space's current initialized version, not the one
      // a host serves. An immutable host for an older version answers the same user.
      await putRoute(rt, space, "production", {
        version_id: "ver_zero_v2_second",
        hostnames: [host],
        production_hostnames: [host],
        version_hostnames: [
          { hostname: "zero-v2-proof--v1.test", version_id: "ver_zero_v2_first" },
        ],
        config: publicAccessConfig(
          { zero_generation: "v2", ...users(true) },
          "live_and_all_versions",
        ),
      });
      const olderHost = await get(rt, "zero-v2-proof--v1.test", "/__zero/auth/user", {
        headers: { cookie: ana, origin: "http://zero-v2-proof--v1.test" },
      });
      assert.equal(olderHost.status, 200, await olderHost.clone().text());
      // Finding 3: a current version whose identity code predates Users is refused
      // with a republish instruction, never a crash.
      const currentPointer = path.join(rt.storageRoot, "spaces", space, "zero-v2-current.json");
      const currentBefore = readFileSync(currentPointer, "utf8");
      const currentVersion = z
        .object({ version_id: z.string() })
        .parse(JSON.parse(currentBefore)).version_id;
      const sessionsFile = path.join(
        versionRoot(rt, space, currentVersion),
        ".zero-compiled/plugins/zero-identity/src/Sessions.php",
      );
      const sessionsBefore = readFileSync(sessionsFile, "utf8");
      writeFileSync(
        sessionsFile,
        sessionsBefore.replace("function cookieName(", "function cookieNameBefore("),
      );
      try {
        const outdated = await get(rt, host, "/__zero/auth/user", {
          headers: { cookie: ana, origin: origin(host) },
        });
        assert.equal(outdated.status, 409, await outdated.clone().text());
        assert.equal((await outdated.json()).code, "space_users_zero_version_outdated");
      } finally {
        writeFileSync(sessionsFile, sessionsBefore);
      }
      assert.equal(readFileSync(currentPointer, "utf8"), currentBefore);
      // Managed Google: the Space's own start goes to the platform's start URL
      // with the Space's registered callback.
      const google = await get(rt, host, "/identity/provider/start?provider=google", {
        headers: { cookie: ana },
      });
      assert.equal(google.status, 302, await google.clone().text());
      const googleStart = new URL(google.headers.get("location") ?? "");
      assert.equal(
        googleStart.origin + googleStart.pathname,
        "https://api.spacefast.com/v1/auth/space-users/spc_zero_v2_proof/google/start",
      );
      assert.equal(googleStart.searchParams.get("client_id"), "space-users-client");
      assert.equal(
        googleStart.searchParams.get("redirect_uri"),
        `http://${host}/identity/provider/callback?provider=spacefast`,
      );
      assert.equal(googleStart.searchParams.get("code_challenge_method"), "S256");
      const config = await get(rt, host, "/__zero/auth/api/config", {
        headers: { origin: origin(host) },
      });
      assert.deepEqual((await config.json()).data.providers, ["google"]);
      // Native Spacefast sign-in, inside the Space's binding: the platform
      // identity gets one account in the Space's tables and keeps it.
      // The native user also carries `_spacefast_principal_id`, as a site Sign in
      // with Spacefast user does (content-principals.php writes it). It is still a
      // Space User: membership is having an identity account.
      const native = JSON.parse(
        wp(
          `--exec=require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/context.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/storage.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/zero-v2.php"))};_stattic_zero_v2_select(${JSON.stringify(rt.storageRoot)},${JSON.stringify(space)},"ver_zero_v2_second",["zero_generation"=>"v2","users"=>["enabled"=>true]]);`,
          "eval",
          "ob_start(); $first = spacefast_space_users_native_sign_in('https://api.spacefast.com/v1/auth', 'native-subject', 'Native Person');" +
            " $again = spacefast_space_users_native_sign_in('https://api.spacefast.com/v1/auth', 'native-subject', 'Native Person'); ob_end_clean();" +
            " $row = \\Zero\\Identity\\Plugin::$db->row('SELECT wp_user_id FROM ' . \\Zero\\Identity\\Plugin::$db->prefix . 'providers WHERE subject=%s', 'native-subject');" +
            " update_user_meta((int) $row['wp_user_id'], '_spacefast_principal_id', 'prn_native_person');" +
            " echo json_encode([$first, $again, (int) $row['wp_user_id'], get_userdata((int) $row['wp_user_id'])->display_name, $GLOBALS['wpdb']->prefix, \\Zero\\Identity\\Plugin::$sessions->create((int) $row['wp_user_id'])['secret']]);",
        ),
      );
      assert.deepEqual(
        native.slice(0, 2).map((result: { kind: string }) => result.kind),
        ["signed_in", "signed_in"],
      );
      assert.equal(native[3], "Native Person");
      assert.equal(native[4], own.prefix);
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM ${own.prefix}zero_identity_providers WHERE subject='native-subject'`,
        ),
        "1",
        "a second sign-in reuses the account",
      );
      // Finding 1: the native user, principal meta and all, is a Space User.
      const nativeCookie = `zi_session=${native[5]}`;
      const nativeUser = await get(rt, host, "/__zero/auth/user", {
        headers: { cookie: nativeCookie, origin: origin(host) },
      });
      assert.equal((await nativeUser.json()).data?.displayName, "Native Person");
      const nativeComplete = await get(rt, host, "/__zero/auth/complete", {
        headers: { cookie: nativeCookie },
      });
      assert.notEqual(
        nativeComplete.status === 401 ? (await nativeComplete.json()).code : nativeComplete.status,
        "space_users_sign_in_required",
        "a native Spacefast user completes sign-in",
      );
      // Dashboard management: the content entrypoint's ability lane, the same
      // request the control plane sends, answers from the Space's own tables.
      const runtimeOrigin = new URL(rt.baseUrl).origin;
      const manageRaw = async (
        name: string,
        input: Record<string, string | number | boolean>,
        method = "GET",
        target = space,
      ) => {
        const query = new URLSearchParams(
          Object.entries(input).map(([key, value]) => [`input[${key}]`, String(value)]),
        );
        const now = Math.floor(Date.now() / 1000);
        const response = await api(
          rt,
          "POST",
          "/__spacefast/content.php",
          "content.rest.request",
          { space_id: target },
          {
            operation: "rest.request",
            method,
            path: `/wp-abilities/v1/abilities/zero/wp-users-${name}/run?${query}`,
            publicOrigin: origin(host),
            principal: {
              format: "spacefast.principal",
              version: 1,
              audience: { spaceId: target, runtimeInstanceId: "rti_test", host: runtimeOrigin },
              sessionVersion: now,
              accessGeneration: 1,
              nonce: crypto.randomUUID(),
              issuedAt: now,
              expiresAt: now + 60,
              wordpressRole: "administrator",
              actor: { kind: "user", id: "usr_dashboard_owner" },
              subject: { issuer: "https://api.spacefast.com/v1/auth", subject: "dashboard-owner" },
            },
          },
        );
        return response;
      };
      const manage = async (
        name: string,
        input: Record<string, string | number | boolean>,
        method = "GET",
      ) => {
        const response = await manageRaw(name, input, method);
        assert.equal(response.status, 200, await response.clone().text());
        return z.object({ status: z.number(), body: z.unknown() }).parse(await response.json());
      };
      const account = z.object({
        id: z.number(),
        subject: z.string(),
        status: z.string(),
        emails: z.array(z.object({ email: z.string() })),
      });
      const listed = await manage("account-list", { page: 1, perPage: 20 });
      assert.equal(listed.status, 200, JSON.stringify(listed.body));
      const directory = z.object({ users: z.array(account) }).parse(listed.body).users;
      assert.deepEqual(
        directory
          .map((user) => user.emails.map((email) => email.email))
          .flat()
          .toSorted(),
        [`ana-${space}@example.test`],
        "lists the Space's account, not the dashboard principal, guests or Space B",
      );
      assert.ok(
        directory.some((user) => user.id === native[2]),
        "the native Spacefast user is listed",
      );
      // The dashboard principal the ability lane acted as has no identity account:
      // a session for it is nobody at /__zero/auth/complete.
      const principalId = Number(
        db.exec(
          `SELECT user_id FROM ${own.prefix}usermeta WHERE meta_key='_spacefast_principal_subject' AND meta_value='dashboard-owner'`,
        ),
      );
      assert.ok(principalId > 0, "the dashboard principal exists in the Space's tables");
      assert.ok(!directory.some((user) => user.id === principalId), "the principal is not listed");
      // zero-identity issues no session to a user without an identity account; a
      // session row for the principal, as if one existed, still finds nobody.
      const principalSecret = crypto.randomUUID().replaceAll("-", "");
      const principalNow = Math.floor(Date.now() / 1000);
      db.exec(
        `INSERT INTO ${own.prefix}zero_identity_sessions (id, wp_user_id, secret_hash, csrf, created_at, expires_at, verified_at, label) VALUES ('${crypto.randomUUID().replaceAll("-", "")}', ${principalId}, SHA2(CONCAT('zero-identity-session-v1', CHAR(0), '${principalSecret}'), 256), '${"c".repeat(64)}', ${principalNow}, ${principalNow + 3600}, ${principalNow}, 'test')`,
      );
      const principalComplete = await get(rt, host, "/__zero/auth/complete", {
        headers: { cookie: `zi_session=${principalSecret}` },
      });
      assert.equal(principalComplete.status, 401);
      assert.equal((await principalComplete.json()).code, "space_users_sign_in_required");
      const anaAccount = account.parse((await manage("account-get", { id: anaOnZero.id })).body);
      assert.equal(anaAccount.subject, appUserBody.data.userId);
      const fresh = await signIn(host, `fresh-${space}@example.test`);
      const freshUser = await me(host, fresh);
      assert.ok(freshUser);
      const sessions = z
        .object({ sessions: z.array(z.object({ revokedAt: z.number().nullable() })) })
        .parse((await manage("sessions-list", { id: freshUser.id })).body).sessions;
      assert.equal(sessions.filter((session) => session.revokedAt === null).length, 1);
      assert.deepEqual((await manage("sessions-revoke", { id: freshUser.id }, "DELETE")).body, {
        revoked: true,
      });
      assert.equal(await me(host, fresh), null, "a revoked session stops working on the app");
      const suspended = await manage("suspend", { id: anaOnZero.id, suspended: true }, "DELETE");
      assert.equal(account.parse(suspended.body).status, "suspended");
      assert.equal(await me(host, ana), null, "a suspended account is nobody on the app");
      const refusedSignIn = await get(rt, host, "/__zero/auth/api/sign-in/email", {
        method: "POST",
        headers: { "content-type": "application/json", origin: origin(host) },
        body: JSON.stringify({ email: `ana-${space}@example.test` }),
      });
      const refusedCode = /Your code is (\d{6})/.exec(
        readFileSync(path.join(mailbox, `ana-${space}@example.test`), "utf8"),
      )?.[1];
      const refusedVerify = await get(rt, host, "/__zero/auth/api/sign-in/verify", {
        method: "POST",
        headers: { "content-type": "application/json", origin: origin(host) },
        body: JSON.stringify({
          challenge_id: z
            .object({ data: z.object({ challenge_id: z.string() }) })
            .parse(await refusedSignIn.json()).data.challenge_id,
          code: refusedCode,
        }),
      });
      assert.notEqual(refusedVerify.status, 200, "a suspended account cannot sign in");
      await manage("suspend", { id: anaOnZero.id, suspended: false }, "DELETE");
      // Deletion is user-requested first, then completed from the dashboard.
      const deleting = await signIn(host, `fresh-${space}@example.test`);
      const csrf = z.object({ data: z.object({ csrf: z.string() }) }).parse(
        await (
          await get(rt, host, "/__zero/auth/api/account", {
            headers: { cookie: deleting, origin: origin(host) },
          })
        ).json(),
      ).data.csrf;
      const requested = await get(rt, host, "/__zero/auth/api/account/delete", {
        method: "POST",
        headers: {
          cookie: deleting,
          origin: origin(host),
          "content-type": "application/json",
          "x-identity-csrf": csrf,
        },
        body: JSON.stringify({ confirmation: "DELETE" }),
      });
      assert.equal(requested.status, 200, await requested.clone().text());
      assert.deepEqual((await manage("deletion-complete", { id: freshUser.id }, "DELETE")).body, {
        deleted: true,
      });
      assert.equal(
        account.parse((await manage("account-get", { id: freshUser.id })).body).status,
        "deleted",
      );
      // A real conflict keeps its status: an active account cannot complete deletion.
      assert.equal((await manage("deletion-complete", { id: anaOnZero.id }, "DELETE")).status, 409);
      // No content principal on the app's REST door: identity is the visitor's alone.
      const anonymous = await get(rt, host, "/wp-json/zero/v1/me", {
        headers: { origin: origin(host) },
      });
      assert.deepEqual(await anonymous.json(), { data: null });
      const optionsBefore = db.exec(
        `SELECT option_name,option_value FROM ${own.prefix}options WHERE option_name LIKE 'zero%schema' OR option_name='zero_runtime_tables' ORDER BY option_name`,
      );
      await activate("ver_zero_v2_first");
      await call(host, "v1-installed");
      await checkEnvironment(host, `${space}/v1`);
      await call("zero-v2-proof--v2.test", "v2-installed");
      assert.equal(await generatedClient(host, "v1-installed", false), firstClient);
      assert.equal(
        await generatedClient("zero-v2-proof--v2.test", "v2-installed", true),
        secondClient,
      );
      assert.equal(
        db.exec(
          `SELECT option_name,option_value FROM ${own.prefix}options WHERE option_name LIKE 'zero%schema' OR option_name='zero_runtime_tables' ORDER BY option_name`,
        ),
        optionsBefore,
        "Old requests and rollback do not migrate or change schema hashes",
      );
      // The migration's compiler-generated adoption is executed at finalize. Uploaded
      // migration files remain archival inputs, as proved above, rather than trusted SQL.
      const migrationSpace = "spc_zero_v2_migrated";
      const migrationHost = "zero-v2-migrated.test";
      const migrate = (version: string) => {
        const destination = path.join(work, `migrated-${version}`);
        cpSync(path.join(import.meta.dir, "fixtures/zero-v2-migrated"), destination, {
          recursive: true,
        });
        writeFileSync(
          path.join(destination, "server/version.ts"),
          `export const version = '${version}';`,
        );
        return { destination };
      };
      const migratedFirst = migrate("legacy-v1");
      const physicalTable = zeroRuntimePhysicalTableName(migrationSpace, "taskItems");
      db.exec(`CREATE TABLE \`${physicalTable}\` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    createdAt TEXT NULL, updatedAt TEXT NULL, title TEXT NULL, done TEXT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
      db.exec(`INSERT INTO \`${physicalTable}\` (id, createdAt, updatedAt, title, done) VALUES
    (41, '2026-03-01T00:00:00.123Z', '2026-03-01T00:00:00.123Z', 'Retained first', 'false'),
    (9007199254740900, '2026-03-01T00:00:00.123Z', '2026-03-01T00:00:00.123Z', 'Retained BIGINT', 'true')`);
      const retainedRows = db.exec(`SELECT * FROM \`${physicalTable}\` ORDER BY id`);
      const retainedDdl = db.exec(`SHOW CREATE TABLE \`${physicalTable}\``);
      await publish(
        "ver_zero_v2_migrated_first",
        "legacy-v1",
        migrationSpace,
        migratedFirst.destination,
      );
      assert.equal(db.exec(`SELECT * FROM \`${physicalTable}\` ORDER BY id`), retainedRows);
      assert.equal(db.exec(`SHOW CREATE TABLE \`${physicalTable}\``), retainedDdl);
      const activateMigration = (versionId: string) =>
        putRoute(rt, migrationSpace, "production", {
          version_id: versionId,
          hostnames: [migrationHost],
          production_hostnames: [migrationHost],
          version_hostnames: [
            { hostname: "zero-v2-migrated--v1.test", version_id: "ver_zero_v2_migrated_first" },
            { hostname: "zero-v2-migrated--v2.test", version_id: "ver_zero_v2_migrated_second" },
          ],
          config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
        });
      await activateMigration("ver_zero_v2_migrated_first");
      const { client: migratedClient } = await loadGeneratedClient(migrationHost);
      const existing = await migratedClient.actions.get({ id: "9007199254740900" });
      assert.deepEqual(existing, {
        id: "9007199254740900",
        title: "Retained BIGINT",
        done: true,
        createdAt: "2026-03-01T00:00:00.123Z",
        updatedAt: "2026-03-01T00:00:00.123Z",
      });
      const created = await migratedClient.actions.add({ title: "Hosted insert" });
      assert.equal(created.id, "9007199254740901");
      assert.equal(created.title, "Hosted insert");
      assert.equal(created.done, false);
      assert.equal(
        (await migratedClient.actions.setDone({ id: created.id, done: true })).done,
        true,
      );
      assert.equal(await migratedClient.actions.remove({ id: created.id }), true);
      await assert.rejects(migratedClient.actions.rollback(), /rollback proof/);
      assert.equal(db.exec(`SELECT * FROM \`${physicalTable}\` ORDER BY id`), retainedRows);
      // Adoption preserves all BIGINT rows. The migration's input adapter currently
      // refuses IDs outside JavaScript's safe integer range instead of rounding them.
      const unsupportedId = "9007199254740993";
      db.exec(`INSERT INTO \`${physicalTable}\` (id, createdAt, updatedAt, title, done) VALUES
    (${unsupportedId}, '2026-03-01T00:00:00.123Z', '2026-03-01T00:00:00.123Z', 'Unsafe integer retained', 'false')`);
      await assert.rejects(migratedClient.actions.get({ id: unsupportedId }), {
        status: 422,
        code: "legacy_id_range",
      });
      assert.deepEqual(
        (await migratedClient.actions.list()).map((row: { id: string }) => row.id),
        ["41", "9007199254740900", unsupportedId],
      );
      const rowsWithUnsupportedId = db.exec(`SELECT * FROM \`${physicalTable}\` ORDER BY id`);
      // Successful and rolled-back INSERTs advance MariaDB's AUTO_INCREMENT value.
      // Later publications and saved-code rollback must preserve its current value.
      const afterCrudDdl = db.exec(`SHOW CREATE TABLE \`${physicalTable}\``);
      const migratedSecond = migrate("legacy-v2");
      await publish(
        "ver_zero_v2_migrated_second",
        "legacy-v2",
        migrationSpace,
        migratedSecond.destination,
      );
      await activateMigration("ver_zero_v2_migrated_second");
      assert.equal(await migratedClient.actions.version(), "legacy-v2");
      assert.equal(
        await (await loadGeneratedClient("zero-v2-migrated--v1.test")).client.actions.version(),
        "legacy-v1",
      );
      await activateMigration("ver_zero_v2_migrated_first");
      assert.equal(await migratedClient.actions.version(), "legacy-v1");
      assert.deepEqual(await migratedClient.actions.get({ id: "9007199254740900" }), existing);
      assert.equal(
        await (await loadGeneratedClient("zero-v2-migrated--v2.test")).client.actions.version(),
        "legacy-v2",
      );
      await assert.rejects(migratedClient.actions.get({ id: unsupportedId }), {
        status: 422,
        code: "legacy_id_range",
      });
      assert.equal(
        db.exec(`SELECT * FROM \`${physicalTable}\` ORDER BY id`),
        rowsWithUnsupportedId,
      );
      assert.equal(db.exec(`SHOW CREATE TABLE \`${physicalTable}\``), afterCrudDdl);
      for (const privatePath of [
        "/.zero-source/manifest.json",
        "/.zero-compiled/plugins/zero-runtime/zero/bootstrap.php",
      ]) {
        const response = await get(rt, host, privatePath);
        assert.equal(response.status, 403, privatePath);
      }
      // Hosted MCP sends raw files; capture and compilation belong to the box.
      const rawSpace = "spc_zero_v2_raw";
      const rawVersion = "ver_zero_v2_raw";
      const rawFiles = {
        // The app declares its own users: its sign-in is the app's, not Space Users'.
        "zero.config.ts": `import { defineConfig, text, email, userProjection } from 'zero'; import { ping } from './actions'; export default defineConfig({ name: 'raw-hosted', users: { members: userProjection({ email: email().notNull().unique().max(191), name: text().notNull().default('') }) }, actions: { ping } });`,
        "actions.ts": `import { action } from 'zero'; export const ping = action({ access: 'anyone', handler() { return 'raw-hosted-action'; } });`,
        "public/index.html": "<!doctype html><title>Raw hosted source</title>",
      };
      // This Space had Users before its first v2 version: its account sits in
      // the site tables, made by spacefast-identity exactly as sign-in makes it.
      await deploy(rt, {
        spaceId: rawSpace,
        versionId: "ver_zero_v2_raw_static",
        files: { "index.html": "<!doctype html><title>Before Zero</title>" },
        activate: {
          route_name: "production",
          production_hostnames: ["zero-raw.test"],
          config: publicAccessConfig(users(false)),
        },
      });
      const spaceUser = JSON.parse(
        wp(
          `--exec=$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = ${JSON.stringify(rawSpace)}; $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['users' => ['enabled' => true]];`,
          "eval",
          "ob_start(); $plugin = \\Spacefast\\Identity\\Plugin::class; $uid = $plugin::$accounts->emailLogin('existing@example.test');" +
            " spacefast_space_users_join($uid); $session = $plugin::$sessions->create($uid); ob_end_clean();" +
            " echo json_encode(['id' => $uid, 'secret' => $session['secret']]);",
        ),
      );
      const rawSession = await createDeclaredSession(rt, rawSpace, rawVersion, rawFiles);
      await uploadSessionBlobs(rt, rawSession, rawFiles);
      const rawLock = Bun.spawn({
        cmd: [
          "php",
          "-r",
          "$h=fopen($argv[1],'c'); if (!flock($h, LOCK_EX)) exit(1); echo 'locked'; fflush(STDOUT); fgets(STDIN);",
          path.join(rt.storageRoot, "spaces", rawSpace, "zero-v2-finalize.lock"),
        ],
        stdin: "pipe",
        stdout: "pipe",
        stderr: "pipe",
      });
      try {
        const reader = rawLock.stdout.getReader();
        const signal = await reader.read();
        reader.releaseLock();
        assert.equal(new TextDecoder().decode(signal.value), "locked");
        const busy = await finalize(rt, rawSpace, rawVersion, {
          upload_id: rawSession.uploadId,
          zero_generation: "none",
        });
        assert.equal(busy.status, 409, await busy.clone().text());
        assert.equal((await busy.json()).code, "zero_v2_finalize_busy");
      } finally {
        rawLock.stdin.write("\n");
        rawLock.stdin.end();
        assert.equal(await rawLock.exited, 0);
      }
      const rawFinalized = await finalize(rt, rawSpace, rawVersion, {
        upload_id: rawSession.uploadId,
        zero_generation: "none",
      });
      assert.equal(rawFinalized.status, 200, await rawFinalized.text());
      await putRoute(rt, rawSpace, "production", {
        version_id: rawVersion,
        hostnames: ["zero-raw.test"],
        production_hostnames: ["zero-raw.test"],
        version_hostnames: [{ hostname: "zero-raw--immutable.test", version_id: rawVersion }],
        config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
      });
      for (const hostname of ["zero-raw.test", "zero-raw--immutable.test"]) {
        const action = await get(rt, hostname, "/wp-json/zero/v1/actions/ping", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: "{}",
        });
        assert.equal(action.status, 200, await action.clone().text());
        assert.deepEqual(await action.json(), { data: "raw-hosted-action" });
        const client = await get(rt, hostname, "/__spacefast/client.js");
        assert.equal(client.status, 200, await client.text());
      }
      // No users are copied, so a v2 Space whose accounts are still on the site
      // tables would orphan them. While any remain, the Space's Zero app runs
      // without Users: visitor sign-in pauses on every door, and the owner
      // keeps managing those accounts where they are. Every site row stays.
      const rawPrefix = `sfz_${new Bun.CryptoHasher("sha256").update(rawSpace).digest("hex").slice(0, 24)}_`;
      await putRoute(rt, rawSpace, "production", {
        version_id: rawVersion,
        hostnames: ["zero-raw.test"],
        production_hostnames: ["zero-raw.test"],
        config: publicAccessConfig(
          { zero_generation: "v2", ...users(false) },
          "live_and_all_versions",
        ),
      });
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${rawPrefix}users WHERE user_email='existing@example.test'`),
        "0",
        "no site user is copied",
      );
      const paused = await get(rt, "zero-raw.test", "/__zero/auth/api/sign-in/email", {
        method: "POST",
        headers: { "content-type": "application/json", origin: origin("zero-raw.test") },
        body: JSON.stringify({ email: "existing@example.test" }),
      });
      assert.equal(paused.status, 409, await paused.clone().text());
      assert.equal((await paused.json()).code, "space_users_site_accounts_present");
      // The app's own user collection keeps its sign-in on the paused Space:
      // zero-identity mounts for it as before, without Space Users routed onto it.
      const appAuth = await get(rt, "zero-raw.test", "/wp-json/zero/v1/auth/config");
      assert.equal(appAuth.status, 200, await appAuth.clone().text());
      assert.notEqual(
        z
          .object({ data: z.object({ issuer: z.string().nullable() }).loose() })
          .parse(await appAuth.json()).data.issuer,
        null,
        "the app's /auth is served by zero-identity",
      );
      // But Space Users does not bind to it: the Space's /identity sign-in is paused.
      const spaceUsersSession = await get(rt, "zero-raw.test", "/__zero/auth/user", {
        headers: { origin: origin("zero-raw.test") },
      });
      assert.equal(spaceUsersSession.status, 409, await spaceUsersSession.clone().text());
      // The app itself still serves, without Users.
      const pausedAction = await get(rt, "zero-raw.test", "/wp-json/zero/v1/actions/ping", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: "{}",
      });
      assert.equal(pausedAction.status, 200, await pausedAction.clone().text());
      // The owner manages the site accounts as before Zero v2, and completes a
      // pending erasure there.
      const pausedList = await manageRaw("account-list", { page: 1, perPage: 20 }, "GET", rawSpace);
      assert.equal(pausedList.status, 200, await pausedList.clone().text());
      const listedSite = z
        .object({ body: z.object({ users: z.array(z.object({ id: z.number() }).loose()) }) })
        .parse(await pausedList.json()).body.users;
      assert.ok(
        listedSite.some((user) => user.id === spaceUser.id),
        "the owner lists the site account",
      );
      db.exec(
        `UPDATE wp_sfi_accounts SET status='deletion_requested' WHERE wp_user_id=${spaceUser.id}`,
      );
      const erased = await manageRaw("deletion-complete", { id: spaceUser.id }, "DELETE", rawSpace);
      assert.equal(erased.status, 200, await erased.clone().text());
      assert.deepEqual(z.object({ body: z.unknown() }).parse(await erased.json()).body, {
        deleted: true,
      });
      assert.equal(
        db.exec(`SELECT status FROM wp_sfi_accounts WHERE wp_user_id=${spaceUser.id}`),
        "deleted",
        "the erasure completes on the site tables",
      );
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM wp_usermeta WHERE user_id=${spaceUser.id} AND meta_key='_spacefast_app_user' AND meta_value='${rawSpace}'`,
        ),
        "1",
        "the site account row stays where it is",
      );
      // With Users off, the accounts are still on the site tables: the owner
      // keeps managing them there, wherever Users is switched.
      const secondUser = Number(
        wp(
          `--exec=$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = ${JSON.stringify(rawSpace)}; $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['users' => ['enabled' => true]];`,
          "eval",
          "ob_start(); $plugin = \\Spacefast\\Identity\\Plugin::class; $uid = $plugin::$accounts->emailLogin('second@example.test');" +
            " spacefast_space_users_join($uid); ob_end_clean(); echo $uid;",
        )
          .trim()
          .split("\n")
          .at(-1),
      );
      await putRoute(rt, rawSpace, "production", {
        version_id: rawVersion,
        hostnames: ["zero-raw.test"],
        production_hostnames: ["zero-raw.test"],
        config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
      });
      const offList = await manageRaw("account-list", { page: 1, perPage: 20 }, "GET", rawSpace);
      assert.equal(offList.status, 200, await offList.clone().text());
      assert.ok(
        z
          .object({ body: z.object({ users: z.array(z.object({ id: z.number() }).loose()) }) })
          .parse(await offList.json())
          .body.users.some((user) => user.id === secondUser),
        "with Users off, the owner lists the site account",
      );
      db.exec(
        `UPDATE wp_sfi_accounts SET status='deletion_requested' WHERE wp_user_id=${secondUser}`,
      );
      const offErased = await manageRaw(
        "deletion-complete",
        { id: secondUser },
        "DELETE",
        rawSpace,
      );
      assert.equal(offErased.status, 200, await offErased.clone().text());
      assert.equal(
        db.exec(`SELECT status FROM wp_sfi_accounts WHERE wp_user_id=${secondUser}`),
        "deleted",
        "with Users off, the pending erasure completes on the site tables",
      );
      // The WordPress REST door must enforce the same generation as static delivery.
      for (const generation of [undefined, "legacy_0_8"]) {
        await putRoute(rt, rawSpace, "production", {
          version_id: rawVersion,
          hostnames: ["zero-raw.test"],
          production_hostnames: ["zero-raw.test"],
          config: publicAccessConfig(generation ? { zero_generation: generation } : {}),
        });
        for (const requestPath of [
          "/",
          "/__spacefast/client.js",
          "/wp-json/zero/v1/actions/ping",
        ]) {
          const headers = { accept: "application/json", "content-type": "application/json" };
          const request = requestPath.includes("/actions/")
            ? { headers, method: "POST", body: "{}" }
            : { headers };
          const refused = await get(rt, "zero-raw.test", requestPath, request);
          assert.equal(refused.status, 409, await refused.clone().text());
          assert.equal((await refused.json()).code, "zero_generation_locked");
        }
      }
      const retiredSpace = "spc_zero_retired_wordpress";
      await deploy(rt, {
        spaceId: retiredSpace,
        versionId: "ver_retired_legacy",
        files: { "index.html": "retained legacy version" },
        zero: {},
      });
      await putRoute(rt, retiredSpace, "production", {
        version_id: "ver_retired_legacy",
        hostnames: ["zero-retired.test"],
        production_hostnames: ["zero-retired.test"],
        version_hostnames: [
          { hostname: "zero-retired--immutable.test", version_id: "ver_retired_legacy" },
        ],
        config: publicAccessConfig({ zero_generation: "v2" }, "live_and_all_versions"),
      });
      const retired = await get(
        rt,
        "zero-retired--immutable.test",
        "/wp-json/zero/v1/actions/ping",
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: "{}",
        },
      );
      assert.equal(retired.status, 410, await retired.clone().text());
      assert.equal((await retired.json()).code, "zero_legacy_retired");
      const activePlugins = JSON.parse(wp("plugin", "list", "--status=active", "--format=json"));
      assert.equal(
        activePlugins.some((p: { name: string }) => p.name.startsWith("zero-")),
        false,
      );
    } catch (error) {
      throw new Error(
        `Hosted runtime acceptance failed.\n${rt.diagnostics()}\n${existsSync(path.join(rt.root, "wp-content/debug.log")) ? readFileSync(path.join(rt.root, "wp-content/debug.log"), "utf8") : ""}`,
        { cause: error },
      );
    } finally {
      receiver.stop(true);
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 180_000,
);

test(
  "Write seeds content once and preserves editable, locked and trashed records across publishes",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-zero-seed-"));
    const db = await startMysqlContainer({
      namePrefix: "sf-zero-seed",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    // The box's php-fpm namespace has no `wp` or `php` to spawn: finalize gets the same.
    // Only these names fail. The fixture server itself starts from PHP_BINARY, resolved
    // to an absolute path at module load, so it is unaffected.
    const fpmBin = path.join(work, "fpm-bin");
    mkdirSync(fpmBin);
    for (const name of ["wp", "php"]) {
      writeFileSync(path.join(fpmBin, name), "#!/bin/sh\nexit 127\n", { mode: 0o755 });
    }
    const rt = await startRuntime({
      env: {
        PATH: `${fpmBin}:${process.env.PATH ?? ""}`,
        SPACEFAST_ZERO_DATABASE_URL: db.url,
      },
    });
    try {
      const host = "zero-write.test";
      const space = "spc_zero_write";
      const wp = await installWordpress(rt, work, db, host);
      const files = zeroV2WriteFixture();
      const filename = "content/posts/a-first-post.md";
      const uid =
        "zr_" +
        new Bun.CryptoHasher("sha256")
          .update("zero-sync-file\0posts\0a-first-post.md")
          .digest("hex")
          .slice(0, 20);
      const counts = (
        overrides: Partial<{
          created: number;
          unchanged: number;
          skipped_existing: number;
          updated: number;
          trashed_skipped: number;
        }>,
      ) => ({
        posts: {
          created: 0,
          unchanged: 0,
          skipped_existing: 0,
          updated: 0,
          trashed_skipped: 0,
          ...overrides,
        },
      });
      const publish = async (
        version: string,
        source: Record<string, string>,
        expected: ReturnType<typeof counts>,
        selectedSpace = space,
        hostname = host,
      ) => {
        const session = await createDeclaredSession(rt, selectedSpace, version, source);
        await uploadSessionBlobs(rt, session, source);
        const response = await finalize(rt, selectedSpace, version, {
          upload_id: session.uploadId,
          zero_generation: version.endsWith("first") ? "none" : "v2",
        });
        assert.equal(response.status, 200, await response.clone().text());
        const receipt = await response.json();
        assert.deepEqual(receipt.zero_seed, expected);
        const compiled = path.join(versionRoot(rt, selectedSpace, version), ".zero-compiled");
        assert.deepEqual(
          JSON.parse(readFileSync(path.join(compiled, "seed-receipt.json"), "utf8")),
          expected,
        );
        assert.equal(existsSync(path.join(compiled, "ready.json")), true);
        await putRoute(rt, selectedSpace, "production", {
          version_id: version,
          hostnames: [hostname],
          production_hostnames: [hostname],
          config: publicAccessConfig({ zero_generation: "v2" }),
        });
      };
      const records = async (hostname = host) => {
        const response = await get(rt, hostname, "/wp-json/zero/v1/posts");
        assert.equal(response.status, 200, await response.clone().text());
        return z
          .object({
            data: z.array(
              z.object({
                id: z.number(),
                uid: z.string(),
                title: z.string(),
                slug: z.string(),
                body: z.string(),
              }),
            ),
          })
          .parse(await response.json()).data;
      };
      await publish("ver_write_first", files, counts({ created: 1 }));
      const replay = await finalize(rt, space, "ver_write_first", {
        upload_id: "upl_consumed_seed",
      });
      assert.equal(replay.status, 200, await replay.clone().text());
      assert.deepEqual((await replay.json()).zero_seed, counts({ created: 1 }));
      const first = await records();
      assert.equal(first.length, 1);
      assert.equal(first[0]?.uid, uid);
      assert.equal(first[0]?.title, "A first post");
      assert.match(first[0]?.body ?? "", /Hello.*<(?:b|strong)>Zero<\/(?:b|strong)>/s);
      const page = await get(rt, host, "/posts/a-first-post/");
      assert.equal(page.status, 200, await page.clone().text());
      assert.match(await page.text(), /<div id="posts"/);
      await publish("ver_write_identical", files, counts({ unchanged: 1 }));
      assert.deepEqual(await records(), first);
      const changed = {
        ...files,
        [filename]: files[filename].replace(
          "Hello **Zero**. This post came from Write.",
          "A file edit must not replace site content.",
        ),
      };
      await publish("ver_write_editable", changed, counts({ skipped_existing: 1 }));
      assert.deepEqual(await records(), first);
      const second = {
        ...changed,
        "content/posts/second-post.md":
          '---\ntitle: A second post\nslug: second-post\nstatus: published\npublishedAt: "2026-11-06T01:00:00Z"\n---\n\nSecond **body**.\n',
      };
      await publish("ver_write_second", second, counts({ created: 1, skipped_existing: 1 }));
      assert.equal((await records()).length, 2);
      const current = path.join(versionRoot(rt, space, "ver_write_second"), ".zero-compiled");
      const bootstrap = `require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/context.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/storage.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/zero-v2.php"))};_stattic_zero_v2_select(${JSON.stringify(rt.storageRoot)},${JSON.stringify(space)},"ver_write_second",["zero_generation"=>"v2"]);`;
      assert.ok(first[0]);
      wp(
        `--exec=${bootstrap}`,
        "eval",
        `if(!wp_trash_post(${first[0].id})) throw new RuntimeException('Trash failed');`,
      );
      await publish("ver_write_trashed", second, counts({ unchanged: 1, trashed_skipped: 1 }));
      const remaining = await records();
      assert.equal(remaining.length, 1);
      assert.equal(remaining[0]?.slug, "second-post");
      assert.equal(existsSync(path.join(current, "seed-receipt.json")), true);
      const locked = {
        ...files,
        "zero.config.ts": files["zero.config.ts"]
          .replace("collection, defineConfig", "table, defineConfig")
          .replace("posts = collection(", "posts = table(")
          .replace(
            "  body: richText(),",
            "  body: richText(),\n  status: text(),\n  publishedAt: text(),",
          )
          .replace("collections: { posts }", "tables: { posts }"),
      };
      await publish(
        "ver_locked_first",
        locked,
        counts({ created: 1 }),
        "spc_zero_locked",
        "zero-locked.test",
      );
      const lockedEdit = { ...locked, [filename]: changed[filename] };
      await publish(
        "ver_locked_changed",
        lockedEdit,
        counts({ updated: 1 }),
        "spc_zero_locked",
        "zero-locked.test",
      );
      const lockedRows = await records("zero-locked.test");
      assert.equal(lockedRows.length, 1);
      assert.match(lockedRows[0]?.body ?? "", /A file edit must not replace site content/);
      assert.equal((await records())[0]?.slug, "second-post");
      // A record the app refuses fails the publish with Zero's pointer, before the version is ready.
      const refused = {
        ...second,
        "content/posts/second-post.md": second["content/posts/second-post.md"].replace(
          "status: published",
          "status: archived",
        ),
      };
      const refusedSession = await createDeclaredSession(rt, space, "ver_write_refused", refused);
      await uploadSessionBlobs(rt, refusedSession, refused);
      const refusal = await finalize(rt, space, "ver_write_refused", {
        upload_id: refusedSession.uploadId,
        zero_generation: "v2",
      });
      assert.equal(refusal.status, 422, await refusal.clone().text());
      const problem = z
        .object({ code: z.string(), pointer: z.string() })
        .parse(await refusal.json());
      assert.equal(problem.code, "zero_v2_seed_failed");
      // Zero's own pointer, carried through unchanged.
      assert.equal(problem.pointer, "/fields/status");
      assert.equal(
        existsSync(
          path.join(versionRoot(rt, space, "ver_write_refused"), ".zero-compiled/ready.json"),
        ),
        false,
      );
    } finally {
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 180_000,
);
