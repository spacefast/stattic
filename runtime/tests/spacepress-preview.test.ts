// SpacePress previews on their own SQLite database, on the runtime's pinned
// WordPress with the box's SpacePress plugins installed as box repair leaves them.
import { Database } from "bun:sqlite";
import { test } from "bun:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { prepareZeroV2SourcePublish } from "../../packages/cli/src/zero-v2-source-publish.ts";
import {
  RUNTIME_HTTP_API_BASE,
  RUNTIME_INSTANCE_ID,
  apiJson,
  deploy,
  initializeSpacePress,
  get,
  putRoute,
  publicAccessConfig,
  signToken,
  startRuntime,
} from "./harness.ts";
import { startMysqlContainer } from "./mysql-container.ts";
import { downloadPinnedPlugin, installWordpress } from "./zero-wordpress.ts";

const SPACE = "spc_sp_preview";
const LIVE = "sp-preview-live.test";
const versionHost = (version: string) => `${version.replaceAll("_", "-")}.sp-preview.test`;

test("a SpacePress preview seeds its own SQLite database and promote initializes the site, compiling again when its baseline is stale", async () => {
  const work = mkdtempSync(path.join(os.tmpdir(), "sf-spacepress-preview-"));
  let db: Awaited<ReturnType<typeof startMysqlContainer>> | undefined;
  let rt: Awaited<ReturnType<typeof startRuntime>> | undefined;
  const holders: Bun.Subprocess[] = [];
  try {
    db = await startMysqlContainer({
      namePrefix: "sf-spacepress-preview",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    rt = await startRuntime({
      env: { SPACEFAST_ZERO_DATABASE_URL: db.url, PHP_CLI_SERVER_WORKERS: "4" },
    });
    const wp = await installWordpress(rt, work, db, LIVE, { coreCron: true });
    // Installed and inactive, as box plugin repair leaves SpacePress boxes.
    wp(
      "plugin",
      "install",
      await downloadPinnedPlugin(
        work,
        "two-factor",
        "0.17.0",
        "4d1b0f8e5d82e24876c2b4e86f119122e28a8dbaf76dfde495173226c3356f37",
      ),
    );
    wp(
      "plugin",
      "install",
      await downloadPinnedPlugin(
        work,
        "sqlite-database-integration",
        "3.1.3",
        "f6ce35e8419d0b9326221a907627d905e25c2a5ba2afc86492811f14c851efe0",
      ),
    );
    // Reports the database a request's WordPress booted on.
    mkdirSync(path.join(rt.root, "wp-content/mu-plugins"), { recursive: true });
    writeFileSync(
      path.join(rt.root, "wp-content/mu-plugins/test-database-probe.php"),
      "<?php add_action('rest_api_init', static function (): void { register_rest_route('spacepress/v1', '/test-database', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static fn (): array => ['database' => get_class($GLOBALS['wpdb']), 'guarded' => isset($GLOBALS['SPACEFAST_PREVIEW_GUARD'])]]); });\n",
    );
    // The drop-in is the site's. The engine installs none, and a preview opens
    // its own database even beside one that, like Query Monitor's, replaces
    // $wpdb on every request.
    const siteDropIn = path.join(rt.root, "wp-content/db.php");
    assert.ok(!existsSync(siteDropIn), "the engine installs no wp-content/db.php");
    const siteDropInSource =
      "<?php require_once ABSPATH . WPINC . '/class-wpdb.php'; final class Test_Site_DB extends wpdb {} $wpdb = new Test_Site_DB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);\n";
    writeFileSync(siteDropIn, siteDropInSource);
    // Test fixture only: core's cron spawn goes to the preview's public host,
    // which here is this php -S server, and one job records where it ran.
    const jobs = path.join(work, "jobs.log");
    writeFileSync(
      path.join(rt.root, "wp-content/mu-plugins/zz-preview-cron-fixture.php"),
      `<?php
add_action('http_api_curl', static function ($handle, $args, $url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && str_ends_with($host, '.test')) {
        curl_setopt($handle, CURLOPT_CONNECT_TO, [$host . ':80:127.0.0.1:${new URL(rt.baseUrl).port}']);
    }
}, 10, 3);
add_action('init', static function () {
    if (($_SERVER['HTTP_X_SCHEDULE_TEST_JOB'] ?? '') === '1') wp_schedule_single_event(time() - 1, 'sf_preview_test_job');
});
add_action('sf_preview_test_job', static function () {
    file_put_contents(${JSON.stringify(jobs)}, json_encode([
        'version' => $GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW']['version_id'] ?? null,
        'cron' => defined('DOING_CRON'),
        'path' => explode('?', (string) ($_SERVER['REQUEST_URI'] ?? ''))[0],
    ]) . "\\n", FILE_APPEND);
});
`,
    );

    const project = path.join(work, "app");
    mkdirSync(path.join(project, "tp/app"), { recursive: true });
    mkdirSync(path.join(project, "content/notes"), { recursive: true });
    // `column` adds one column to a table, so versions differ in their migrations.
    const config = (column = "") =>
      writeFileSync(
        path.join(project, "spacepress.config.ts"),
        [
          'import {defineConfig, collection, table, integer, text, richText, mutation, db} from "spacepress";',
          'export const notes = collection({title: text().max(160), body: richText().optional()}, {title: "title", content: "body", sync: "content/notes/*.md", access: {read: "anyone", create: "editor", update: "editor", delete: "editor"}});',
          `export const votes = table({weight: integer().default(1)${column ? `, ${column}: text().optional()` : ""}});`,
          'export const titles = mutation({access: "anyone"}, async () => { return (await db.query.notes.findMany()).map((note) => note.title).sort(); });',
          'export const flavor = mutation({access: "anyone"}, async () => { return process.env.FLAVOR; });',
          'export default defineConfig({name: "previews", admin: false});',
        ].join("\n"),
      );
    writeFileSync(
      path.join(project, "tp/app/page.tsx"),
      "export default function Home(){return <main><h1>SpacePress preview page</h1></main>;}",
    );
    const publish = async (versionId: string, note: string, column = "") => {
      config(column);
      // One file per version: a seed skips a record whose file it already imported.
      rmSync(path.join(project, "content/notes"), { recursive: true, force: true });
      mkdirSync(path.join(project, "content/notes"), { recursive: true });
      writeFileSync(
        path.join(project, `content/notes/${versionId}.md`),
        `---\ntitle: ${note}\n---\n`,
      );
      const step = await prepareZeroV2SourcePublish(project);
      assert.ok(step);
      try {
        await deploy(rt!, {
          spaceId: SPACE,
          versionId,
          files: Object.fromEntries(
            (step.additionalFiles ?? []).map((file) => [
              path.relative(file.root, file.absolutePath),
              readFileSync(file.absolutePath),
            ]),
          ),
          // A preview finalize, as the control plane sends it: the base set
          // for the production binding until promote replaces it, and the
          // set resolved with the version's build branch for its preview.
          finalize: {
            zero_generation: "spacepress",
            zero_v2: {
              variableValues: { FLAVOR: "base" },
              previewVariableValues: { FLAVOR: `branch ${versionId}` },
            },
          },
        });
      } finally {
        await step.cleanup?.();
      }
    };
    const route = (production: string, versions: string[]) =>
      putRoute(rt!, SPACE, "production", {
        version_id: production,
        hostnames: [LIVE],
        production_hostnames: [LIVE],
        version_hostnames: versions.map((version_id) => ({
          hostname: versionHost(version_id),
          version_id,
        })),
        config: publicAccessConfig({ zero_generation: "spacepress" }, "live_and_all_versions"),
      });
    const titles = async (host: string) => {
      const response = await get(rt!, host, "/wp-json/spacepress/v1/actions/titles", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      });
      const body = await response.text();
      assert.equal(response.status, 200, body + rt!.diagnostics());
      return z.array(z.string()).parse(JSON.parse(body).data);
    };
    const flavor = async (host: string) => {
      const response = await get(rt!, host, "/wp-json/spacepress/v1/actions/flavor", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      });
      const body = await response.text();
      assert.equal(response.status, 200, body + rt!.diagnostics());
      return z.string().parse(JSON.parse(body).data);
    };
    // Live runs on the site's own WordPress tables.
    const mysqlTitles = () =>
      String(
        wp(
          "eval",
          `global $wpdb; echo implode(",", $wpdb->get_col("SELECT post_title FROM {$wpdb->prefix}posts WHERE post_status='publish'"));`,
        ),
      );
    const voteColumns = () =>
      String(
        wp(
          "eval",
          `global $wpdb; $t = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%votes'"); if (count($t) !== 1) { echo "tables:" . implode(",", $wpdb->get_col("SHOW TABLES")); return; } echo implode(",", $wpdb->get_col("SHOW COLUMNS FROM {$t[0]}"));`,
        ),
      ).split(",");
    const manifest = (root: string) =>
      z
        .object({ build: z.string() })
        .parse(JSON.parse(readFileSync(path.join(root, "manifest.json"), "utf8")));
    const compiled = (version: string) =>
      path.join(rt!.storageRoot, "spaces", SPACE, "versions", version, ".zero-compiled");
    const previewDir = (version: string) =>
      path.join(rt!.storageRoot, "spaces", SPACE, "previews", version);

    // v1 goes live: its MySQL initialize runs first, and the route write that
    // follows only moves the pointer. A route write never migrates.
    await publish("ver_live", "Live note");
    assert.ok(!existsSync(path.join(compiled("ver_live"), "ready.json")));
    await assert.rejects(route("ver_live", ["ver_live"]), /spacepress_promote_initialize_required/);
    assert.ok(!existsSync(path.join(compiled("ver_live"), "ready.json")));
    assert.equal(
      (
        await apiJson<{ initialized: boolean }>(
          rt,
          "POST",
          `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_live/spacepress/initialize`,
          "initialize_spacepress_version",
          { space_id: SPACE, version_id: "ver_live" },
          {
            expected_live_version_id: null,
            zero_v2: { variableValues: { FLAVOR: "production one" } },
          },
        )
      ).initialized,
      true,
    );
    await route("ver_live", ["ver_live"]);
    assert.ok(existsSync(path.join(compiled("ver_live"), "ready.json")));
    assert.deepEqual(await titles(LIVE), ["Live note"]);
    assert.equal(await flavor(LIVE), "production one");

    // Two previews published while v1 is live touch no database. Each adds
    // its own column, so each one's migrations are compiled against v1.
    await publish("ver_next", "Preview note", "summary");
    await publish("ver_other", "Other note", "subtitle");
    await route("ver_live", ["ver_live", "ver_next", "ver_other"]);
    assert.ok(existsSync(path.join(compiled("ver_next"), "finalized.json")));
    assert.ok(!existsSync(path.join(compiled("ver_next"), "ready.json")));
    assert.ok(!existsSync(previewDir("ver_next")), "an unvisited preview costs nothing");
    assert.doesNotMatch(mysqlTitles(), /Preview note/);
    // A link to the live version serves the live site, from MySQL.
    assert.deepEqual(await titles(versionHost("ver_live")), ["Live note"]);
    assert.ok(!existsSync(previewDir("ver_live")));

    // While another request seeds, the next one is answered before WordPress loads.
    mkdirSync(previewDir("ver_next"), { recursive: true });
    const holder = Bun.spawn({
      cmd: [
        "php",
        "-r",
        `$h=fopen(${JSON.stringify(path.join(previewDir("ver_next"), ".seed.lock"))},'c');flock($h,LOCK_EX);echo "held\\n";fgets(STDIN);`,
      ],
      stdin: "pipe",
      stdout: "pipe",
    });
    holders.push(holder);
    const reader = holder.stdout.getReader();
    assert.match(new TextDecoder().decode((await reader.read()).value), /held/);
    const preparing = await get(rt, versionHost("ver_next"), "/", {
      headers: { Accept: "text/html" },
    });
    const preparingBody = await preparing.text();
    assert.equal(preparing.status, 503, preparingBody + rt.diagnostics());
    assert.equal(preparing.headers.get("retry-after"), "2");
    assert.match(preparingBody, /http-equiv="refresh"/);
    const preparingApi = await get(
      rt,
      versionHost("ver_next"),
      "/wp-json/spacepress/v1/actions/titles",
      {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: "{}",
      },
    );
    assert.equal(preparingApi.status, 503);
    assert.equal(JSON.parse(await preparingApi.text()).code, "spacepress_preview_preparing");
    holder.kill();
    await holder.exited;

    // The first visit seeds the preview's own database from its own code.
    const page = await get(rt, versionHost("ver_next"), "/");
    const body = await page.text();
    assert.equal(page.status, 200, body + rt.diagnostics());
    assert.match(body, /SpacePress preview page/);
    assert.ok(existsSync(path.join(previewDir("ver_next"), "ready.json")));
    assert.deepEqual(await titles(versionHost("ver_next")), ["Preview note"]);
    // A version's Functions call SpacePress through the relay, which arrives on
    // the Space's default hostname: a preview's calls still run on its own
    // database, and live's on the site's.
    const relayProbe = async (versionId: string, status = 200) => {
      const token = signToken({
        aud: "spacefast-functions-relay",
        runtime_instance_id: RUNTIME_INSTANCE_ID,
        space_id: SPACE,
        version_id: versionId,
        ownership_revision: null,
        capabilities: ["spacepress.call"],
      });
      const response = await fetch(`${rt!.baseUrl}/__spacefast/functions/relay`, {
        method: "POST",
        headers: {
          Connection: "close",
          host: LIVE,
          authorization: `Bearer ${token}`,
          "sf-fx-broker": "spacepress",
          "content-type": "application/json",
        },
        body: JSON.stringify({
          method: "POST",
          path: "/wp-json/spacepress/v1/test-database",
          body: "{}",
        }),
      });
      const text = await response.text();
      assert.equal(response.status, status, text + rt!.diagnostics());
      return status === 200
        ? z.object({ database: z.string(), guarded: z.boolean() }).parse(JSON.parse(text))
        : null;
    };
    const relayDatabase = async (versionId: string) => (await relayProbe(versionId))?.database;
    assert.equal(await relayDatabase("ver_next"), "Spacefast_Preview_SQLite_DB");
    assert.equal(await relayDatabase("ver_live"), "Test_Site_DB");
    assert.equal(readFileSync(siteDropIn, "utf8"), siteDropInSource);
    // A request bound to its preview database stays a preview, holding its
    // side effects, though a promote moved the route pointer after it bound.
    const pointer = path.join(rt.storageRoot, "spaces", SPACE, "routes", "production.json");
    const livePointer = readFileSync(pointer, "utf8");
    writeFileSync(pointer, JSON.stringify({ ...JSON.parse(livePointer), version_id: "ver_next" }));
    try {
      assert.deepEqual(await relayProbe("ver_next"), {
        database: "Spacefast_Preview_SQLite_DB",
        guarded: true,
      });
    } finally {
      writeFileSync(pointer, livePointer);
    }
    // A site whose own SQLite setup names DB_PATH keeps it, and a preview
    // there refuses rather than open that database.
    writeFileSync(
      siteDropIn,
      siteDropInSource.replace(
        "<?php ",
        `<?php define('DB_PATH', ${JSON.stringify(path.join(work, "site.sqlite"))}); `,
      ),
    );
    try {
      await relayProbe("ver_next", 500);
      assert.equal(await relayDatabase("ver_live"), "Test_Site_DB");
    } finally {
      writeFileSync(siteDropIn, siteDropInSource);
    }
    // A preview seeded before builds were pinned has a ready marker but no
    // manifest.json. While its version serves the build the marker names, it
    // keeps its database, rows the seed never wrote included; after a
    // recompile it seeds again.
    const previewRow = (write: boolean): boolean => {
      const db = new Database(path.join(previewDir("ver_next"), "database.sqlite"));
      try {
        const options = db
          .query<{ name: string }, []>(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sfp%\\_options' ESCAPE '\\'",
          )
          .get();
        assert.ok(options);
        if (write)
          db.run(
            `INSERT INTO "${options.name}" (option_name, option_value, autoload) VALUES ('sf_test_reviewer_row', 'kept', 'off')`,
          );
        return (
          db
            .query(`SELECT 1 FROM "${options.name}" WHERE option_name = 'sf_test_reviewer_row'`)
            .get() !== null
        );
      } finally {
        db.close();
      }
    };
    const unpinned = async (): Promise<void> => {
      rmSync(path.join(previewDir("ver_next"), "manifest.json"));
      const response = await get(rt!, versionHost("ver_next"), "/");
      const body = await response.text();
      assert.equal(response.status, 200, body + rt!.diagnostics());
      assert.ok(existsSync(path.join(previewDir("ver_next"), "manifest.json")));
      assert.deepEqual(await titles(versionHost("ver_next")), ["Preview note"]);
    };
    assert.equal(previewRow(true), true);
    const scope = readFileSync(path.join(previewDir("ver_next"), "scope"), "utf8");
    await unpinned();
    assert.equal(readFileSync(path.join(previewDir("ver_next"), "scope"), "utf8"), scope);
    assert.equal(previewRow(false), true, "the pinned preview keeps its database");
    const readyPath = path.join(previewDir("ver_next"), "ready.json");
    writeFileSync(
      readyPath,
      JSON.stringify({ ...JSON.parse(readFileSync(readyPath, "utf8")), build: "recompiled-away" }),
    );
    await unpinned();
    assert.notEqual(readFileSync(path.join(previewDir("ver_next"), "scope"), "utf8"), scope);
    assert.equal(previewRow(false), false, "a preview of a replaced build seeds again");
    assert.ok(
      existsSync(path.join(previewDir("ver_next"), "database.sqlite")),
      String(Bun.spawnSync(["ls", "-la", previewDir("ver_next")]).stdout),
    );
    const sqlite = new Database(path.join(previewDir("ver_next"), "database.sqlite"));
    const tables = z
      .array(z.object({ name: z.string() }))
      .parse(
        sqlite
          .query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'sfp%posts'")
          .all(),
      );
    assert.equal(tables.length, 1);
    sqlite.close();
    // Live keeps its own data, its MySQL initialize marker and its seed.
    assert.doesNotMatch(mysqlTitles(), /Preview note/);
    assert.ok(!existsSync(path.join(compiled("ver_next"), "ready.json")));
    // The preview's SpacePress ready marker is its own store's, not the version's.
    const { build } = manifest(compiled("ver_next"));
    assert.ok(existsSync(path.join(previewDir("ver_next"), `store/ready/${build}.json`)));
    assert.ok(!existsSync(path.join(compiled("ver_next"), `store/ready/${build}.json`)));
    assert.deepEqual(await titles(LIVE), ["Live note"]);
    // Two previews and live, each on its own data and its own values.
    assert.deepEqual(await titles(versionHost("ver_other")), ["Other note"]);
    assert.deepEqual(await titles(versionHost("ver_next")), ["Preview note"]);
    assert.equal(await flavor(versionHost("ver_next")), "branch ver_next");
    assert.equal(await flavor(LIVE), "production one");

    // A job scheduled in a preview runs from that preview's own traffic: the
    // request's shutdown spawn reaches the preview cron entrypoint, bound to it.
    // The log holds only that run, never one from the stock /wp-cron.php.
    // An earlier visit's spawn may still hold core's cron lock; a due job then
    // waits for the next request, as on a box.
    const deadline = Date.now() + 30_000;
    for (let attempt = 0; !existsSync(jobs) && Date.now() < deadline; attempt++) {
      const visit = await get(rt, versionHost("ver_next"), "/", {
        headers: attempt === 0 ? { "X-Schedule-Test-Job": "1" } : {},
      });
      assert.equal(visit.status, 200, (await visit.text()) + rt.diagnostics());
      const wait = Date.now() + 2_000;
      while (!existsSync(jobs) && Date.now() < wait) await Bun.sleep(100);
    }
    assert.ok(existsSync(jobs), `the preview job never ran${rt.diagnostics()}`);
    assert.deepEqual(
      readFileSync(jobs, "utf8")
        .trim()
        .split("\n")
        .map((line) =>
          z
            .object({ version: z.string().nullable(), cron: z.boolean(), path: z.string() })
            .parse(JSON.parse(line)),
        ),
      [{ version: "ver_next", cron: true, path: "/__spacefast/preview-cron.php" }],
    );

    // The entrypoint runs jobs only for a current preview named by a live token.
    const { key } = z
      .object({ key: z.string() })
      .parse(
        JSON.parse(
          readFileSync(path.join(rt.storageRoot, "runtime/preview-cron-key.json"), "utf8"),
        ),
      );
    const token = (version_id: string, exp = Math.floor(Date.now() / 1000) + 120) => {
      const encoded = Buffer.from(JSON.stringify({ space_id: SPACE, version_id, exp })).toString(
        "base64url",
      );
      return `${encoded}.${createHmac("sha256", key).update(encoded).digest("hex")}`;
    };
    const runCron = async (host: string, value?: string) => {
      const response = await get(rt!, host, "/__spacefast/preview-cron.php", {
        method: "POST",
        headers: value === undefined ? {} : { "Spacefast-Preview-Cron": value },
      });
      const text = await response.text();
      const code = text === "" ? null : z.object({ code: z.string() }).parse(JSON.parse(text)).code;
      return { status: response.status, code };
    };
    const cronRefused = { status: 403, code: "spacepress_preview_cron_refused" };
    assert.deepEqual(await runCron(versionHost("ver_next")), cronRefused);
    assert.deepEqual(
      await runCron(versionHost("ver_next"), token("ver_next", Math.floor(Date.now() / 1000) - 1)),
      cronRefused,
    );
    assert.deepEqual(await runCron(versionHost("ver_next"), `${token("ver_next")}0`), cronRefused);
    assert.deepEqual(await runCron(versionHost("ver_next"), token("ver_other")), cronRefused);
    // A key that can't be read skips that request's spawn; it never fails
    // the page that triggered it, and never falls back to /wp-cron.php.
    const keyFile = path.join(rt.storageRoot, "runtime/preview-cron-key.json");
    const goodKey = readFileSync(keyFile, "utf8");
    writeFileSync(keyFile, "{not json");
    try {
      const spawn = Bun.spawnSync({
        cmd: [
          "php",
          "-d",
          "auto_prepend_file=",
          "-r",
          [
            `require ${JSON.stringify(path.join(rt.engineRoot, "shared/context.php"))};`,
            `require ${JSON.stringify(path.join(rt.engineRoot, "shared/spacepress-preview.php"))};`,
            "function spacefast_content_request_url($u) { return 'https://preview.test' . $u; }",
            `$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = ${JSON.stringify(rt.storageRoot)};`,
            `$GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'] = ['space_id' => ${JSON.stringify(SPACE)}, 'version_id' => 'ver_next'];`,
            "echo json_encode(_stattic_spacepress_preview_cron_request(['url' => 'https://preview.test/wp-cron.php?doing_wp_cron=1', 'args' => []]));",
          ].join(""),
        ],
        stdout: "pipe",
        stderr: "pipe",
      });
      assert.equal(spawn.exitCode, 0, spawn.stderr.toString());
      assert.equal(
        z.object({ url: z.string() }).parse(JSON.parse(spawn.stdout.toString())).url,
        "",
      );
      assert.match(spawn.stderr.toString(), /preview cron spawn skipped/);
    } finally {
      writeFileSync(keyFile, goodKey);
    }
    // The live version, on its own link or the live host, is never a preview.
    assert.deepEqual(await runCron(versionHost("ver_live"), token("ver_live")), cronRefused);
    assert.deepEqual(await runCron(LIVE, token("ver_live")), cronRefused);
    assert.ok(!existsSync(previewDir("ver_live")), "cron never binds the live version");
    assert.deepEqual(await runCron(versionHost("ver_next"), token("ver_next")), {
      status: 200,
      code: null,
    });

    // Promote runs the version's production initialize, though its preview seeded:
    // first on its own, under finalize's timeout and on the production values
    // it carries rather than the base set, then the route switch.
    const initialize = (
      expected: string | null,
      status = 200,
      variableValues: Record<string, string> = { FLAVOR: "production two" },
    ) =>
      apiJson<{ initialized?: boolean; variables_current?: boolean; code?: string }>(
        rt!,
        "POST",
        `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_next/spacepress/initialize`,
        "initialize_spacepress_version",
        { space_id: SPACE, version_id: "ver_next" },
        {
          expected_live_version_id: expected,
          zero_v2: { variableValues },
        },
        status,
      );
    // A promote checked against another live version never migrates live data.
    const liveBeforeRefusal = mysqlTitles();
    assert.equal((await initialize("ver_other", 409)).code, "spacepress_promote_live_changed");
    assert.ok(!existsSync(path.join(compiled("ver_next"), "ready.json")));
    assert.equal(mysqlTitles(), liveBeforeRefusal);
    // An initialize that moved the baseline pointer but died before its ready
    // marker resumes on retry rather than refusing its own baseline.
    writeFileSync(
      path.join(rt.storageRoot, "spaces", SPACE, "spacepress-current.json"),
      JSON.stringify({ version_id: "ver_next" }),
    );
    assert.deepEqual(await initialize("ver_live"), {
      space_id: SPACE,
      version_id: "ver_next",
      initialized: true,
      variables_current: true,
    });
    // A retry finds its own values; a version initialized on another set
    // keeps it and says so.
    assert.equal((await initialize("ver_live")).variables_current, true);
    const otherSet = await initialize("ver_live", 200, { FLAVOR: "production three" });
    assert.equal(otherSet.initialized, false);
    assert.equal(otherSet.variables_current, false);
    const nextBuild = manifest(compiled("ver_next")).build;
    await route("ver_next", ["ver_live", "ver_next", "ver_other"]);
    // A replay after its own route switch landed still names the old live
    // version; the version is initialized, so the box answers it, not 409.
    assert.equal((await initialize("ver_live")).initialized, false);
    assert.ok(existsSync(path.join(compiled("ver_next"), "ready.json")));
    assert.match(mysqlTitles(), /Preview note/);
    assert.ok(voteColumns().includes("summary"), voteColumns().join(","));
    // Its baseline was live, so it initialized the build it was published with.
    assert.equal(manifest(compiled("ver_next")).build, nextBuild);
    // Its own link now serves the live site; the old live version is a preview.
    assert.deepEqual(await titles(versionHost("ver_next")), ["Live note", "Preview note"]);
    assert.deepEqual(await titles(LIVE), ["Live note", "Preview note"]);
    assert.equal(await flavor(LIVE), "production two");
    assert.equal(await flavor(versionHost("ver_live")), "branch ver_live");

    // The other preview was compiled against v1, but the site now has v2's
    // schema. Promote compiles its migrations again against v2, then
    // initializes.
    const otherSeeded = manifest(compiled("ver_other"));
    assert.equal(manifest(previewDir("ver_other")).build, otherSeeded.build);
    assert.equal(
      (await initializeSpacePress(rt, SPACE, "ver_other", "ver_next")).initialized,
      true,
    );
    await route("ver_other", ["ver_live", "ver_next", "ver_other"]);
    const otherLive = manifest(compiled("ver_other"));
    assert.notEqual(otherLive.build, otherSeeded.build);
    const finalized = z
      .object({ baseline_version_id: z.string() })
      .parse(JSON.parse(readFileSync(path.join(compiled("ver_other"), "finalized.json"), "utf8")));
    assert.equal(finalized.baseline_version_id, "ver_next");
    assert.ok(existsSync(path.join(compiled("ver_other"), `store/ready/${otherLive.build}.json`)));
    assert.match(mysqlTitles(), /Other note/);
    const columns = voteColumns();
    assert.ok(columns.includes("subtitle"), columns.join(","));
    // SpacePress retires a removed column rather than dropping it, so v2's stays.
    assert.ok(columns.includes("summary"), columns.join(","));
    assert.deepEqual(await titles(LIVE), ["Live note", "Other note", "Preview note"]);

    // Roll back: the other version is a preview again, and its preview still
    // loads the build it seeded, on the database seeded for it.
    await route("ver_next", ["ver_live", "ver_next", "ver_other"]);
    assert.deepEqual(await titles(versionHost("ver_other")), ["Other note"]);
    assert.equal(manifest(previewDir("ver_other")).build, otherSeeded.build);
    assert.ok(
      existsSync(path.join(previewDir("ver_other"), `store/ready/${otherSeeded.build}.json`)),
    );
    assert.ok(
      !existsSync(path.join(previewDir("ver_other"), `store/ready/${otherLive.build}.json`)),
    );

    // A version whose compile would now serve something else than it
    // published still refuses the promote, and live stays as it was.
    await publish("ver_changed", "Changed note");
    const changedManifest = path.join(compiled("ver_changed"), "manifest.json");
    writeFileSync(
      changedManifest,
      JSON.stringify({
        ...JSON.parse(readFileSync(changedManifest, "utf8")),
        pagePaths: ["/elsewhere"],
      }),
    );
    writeFileSync(
      path.join(compiled("ver_changed"), "finalized.json"),
      JSON.stringify({ version_id: "ver_changed", baseline_version_id: "ver_live" }),
    );
    const refused = await initializeSpacePress(rt, SPACE, "ver_changed", "ver_next", 409);
    assert.equal(refused.code, "spacepress_promote_baseline_stale");
    assert.match(JSON.stringify(refused.details), /spacepress_recompile_changed/);
    assert.ok(!existsSync(path.join(compiled("ver_changed"), "ready.json")));
    assert.deepEqual(await titles(LIVE), ["Live note", "Other note", "Preview note"]);

    // A rollback to an initialized version never waits on another publish's
    // Zero lock: its initialize call has nothing to do and the route write
    // only moves the pointer. It keeps the set it went live with, as every
    // Zero version keeps the set it finalized with.
    const finalizeLock = Bun.spawn({
      cmd: [
        "php",
        "-r",
        `$h=fopen(${JSON.stringify(path.join(rt.storageRoot, "spaces", SPACE, "zero-v2-finalize.lock"))},'c');flock($h,LOCK_EX);echo "held\\n";fgets(STDIN);`,
      ],
      stdin: "pipe",
      stdout: "pipe",
    });
    holders.push(finalizeLock);
    assert.match(
      new TextDecoder().decode((await finalizeLock.stdout.getReader().read()).value),
      /held/,
    );
    assert.equal(
      (await initializeSpacePress(rt, SPACE, "ver_live", "ver_next")).initialized,
      false,
    );
    await route("ver_live", ["ver_live", "ver_next", "ver_other"]);
    assert.equal(await flavor(LIVE), "production one");
    await route("ver_next", ["ver_live", "ver_next", "ver_other"]);
    assert.equal(await flavor(LIVE), "production two");
    finalizeLock.kill();
    await finalizeLock.exited;

    // A version's preview goes with its last hostname.
    assert.ok(existsSync(previewDir("ver_other")));
    await route("ver_next", ["ver_live", "ver_next"]);
    assert.ok(!existsSync(previewDir("ver_other")));
  } finally {
    for (const holder of holders) holder.kill();
    rt?.stop();
    db?.stop();
    rmSync(work, { recursive: true, force: true });
  }
}, 300_000);
