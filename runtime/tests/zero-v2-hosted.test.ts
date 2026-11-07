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
import { defineConfig, table, text, integer, boolean, timestamp, action, env, db } from 'zero';
import { version } from './src/version';
import { suffix } from 'shared';
const bootValue = env('APP_VALUE');
const customers = table({ name: text().notNull(), email: text().notNull().max(191).unique(), active: boolean().default(true), created: timestamp().defaultNow() });
const products = table({ title: text().notNull(), sku: text().max(191).unique(), price: integer().notNull(), stock: integer().default(0), published: boolean().default(false), ...(version === "v2" ? { category: text() } : {}) });
const orders = table({ customerId: integer().notNull(), status: text().notNull(), total: integer().notNull(), created: timestamp().defaultNow() });
const items = table({ orderId: integer().notNull(), productId: integer().notNull(), quantity: integer().notNull(), price: integer().notNull() });
const comments = table({ customerId: integer(), body: text().notNull(), approved: boolean().default(false), created: timestamp().defaultNow() });
export default defineConfig({ tables: { customers, products, orders, items, comments }, actions: {
  version: action({ access: 'anyone', handler() { return suffix(version); } }),
  environment: action({ access: 'anyone', handler() { return { value: env('APP_VALUE'), bootValue, empty: env('ZERO_PROOF_EMPTY'), hostDatabaseHidden: env('DB_PASSWORD') === null, hostProcessHidden: env('ZERO_PROOF_HOST_ONLY') === null }; } }),
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
    const rt = await startRuntime({
      env: {
        PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
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
      writeFileSync(
        fixture,
        `<?php
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
          bootValue: string | null;
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
          bootValue: expected,
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
        "zero.config.ts": `import { defineConfig } from 'zero'; import { ping } from './actions'; export default defineConfig({ name: 'raw-hosted', actions: { ping } });`,
        "actions.ts": `import { action } from 'zero'; export const ping = action({ access: 'anyone', handler() { return 'raw-hosted-action'; } });`,
        "public/index.html": "<!doctype html><title>Raw hosted source</title>",
      };
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
    const rt = await startRuntime({
      env: {
        PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
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
    } finally {
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 180_000,
);
