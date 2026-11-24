// TypedPress on the runtime's pinned WordPress, with Two Factor installed as a TypedPress box gets it.
import { test } from "bun:test";
import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { prepareZeroV2SourcePublish } from "../../packages/cli/src/zero-v2-source-publish.ts";
import {
  deploy,
  get,
  initializeSpacePress,
  putRoute,
  publicAccessConfig,
  startRuntime,
} from "./harness.ts";
import { startMysqlContainer } from "./mysql-container.ts";
import { downloadPinnedPlugin, installWordpress } from "./zero-wordpress.ts";

test("sf capture publishes a TypedPress page and action on an isolated compatible host", async () => {
  const work = mkdtempSync(path.join(os.tmpdir(), "sf-typedpress-proof-"));
  let db: Awaited<ReturnType<typeof startMysqlContainer>> | undefined;
  let rt: Awaited<ReturnType<typeof startRuntime>> | undefined;
  try {
    db = await startMysqlContainer({
      namePrefix: "sf-typedpress-proof",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    rt = await startRuntime({ env: { SPACEFAST_ZERO_DATABASE_URL: db.url } });
    const host = "typedpress-proof.test";
    const wp = await installWordpress(rt, work, db, host);
    const project = path.join(work, "app");
    mkdirSync(path.join(project, "tp/app"), { recursive: true });
    mkdirSync(path.join(project, "tp/components"), { recursive: true });
    writeFileSync(
      path.join(project, "spacepress.config.ts"),
      'import {defineConfig} from "spacepress"; export default defineConfig({name:"hosted",admin:false});',
    );
    writeFileSync(
      path.join(project, "tp/actions.ts"),
      'import {mutation} from "spacepress"; export const ping = mutation({access:"anyone"}, async () => ({message:"pong"}));',
    );
    writeFileSync(
      path.join(project, "tp/app/page.tsx"),
      'import {Counter} from "../components/Counter"; export default function Home(){return <main><h1>SpacePress hosted page</h1><Counter /></main>;}',
    );
    writeFileSync(
      path.join(project, "tp/components/Counter.tsx"),
      '"use client"; import {useState} from "spacepress/client"; export function Counter(){const [count,setCount]=useState(0);return <button onClick={()=>setCount(count+1)}>{count}</button>;}',
    );
    const step = await prepareZeroV2SourcePublish(project);
    assert.ok(step);
    try {
      const files = {
        ...Object.fromEntries(
          (step.additionalFiles ?? []).map((file) => [
            path.relative(file.root, file.absolutePath),
            readFileSync(file.absolutePath),
          ]),
        ),
        // A Space rewrite onto a TypedPress page.
        _redirects: "/welcome / 200\n",
      };
      // A preview finalize touches no database, but refuses a box that cannot
      // give the version a preview database of its own.
      await assert.rejects(
        deploy(rt, {
          spaceId: "spc_unsupported",
          versionId: "ver_unsupported",
          files,
          finalize: { zero_generation: "none" },
        }),
        /SQLite Database Integration/,
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
      await deploy(rt, {
        spaceId: "spc_unsupported",
        versionId: "ver_unsupported_preview",
        files,
        finalize: { zero_generation: "none" },
      });
      // The runtime's pinned WordPress takes TypedPress, and the fixture carries
      // every other platform plugin, so the host check refuses only for the
      // missing Two Factor, when the version would go live.
      await assert.rejects(
        initializeSpacePress(rt, "spc_unsupported", "ver_unsupported_preview", null),
        /check platform requirements/,
      );
      // As box plugin repair leaves it: installed, active for no request but TypedPress's.
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
      assert.ok(
        !String(wp("option", "get", "active_plugins", "--format=json")).includes("two-factor"),
      );

      await deploy(rt, {
        spaceId: "spc_typedpress",
        versionId: "ver_typedpress",
        files,
        finalize: { zero_generation: "none" },
      });
      await initializeSpacePress(rt, "spc_typedpress", "ver_typedpress", null);
      await putRoute(rt, "spc_typedpress", "production", {
        version_id: "ver_typedpress",
        hostnames: [host],
        production_hostnames: [host],
        config: publicAccessConfig({ zero_generation: "spacepress" }, "live_and_all_versions"),
      });
      // An authored response that names a platform refusal code: the app's own
      // WordPress code sets the header while the SpacePress page renders.
      const muPlugins = path.join(rt.root, "wp-content/mu-plugins");
      mkdirSync(muPlugins, { recursive: true });
      writeFileSync(
        path.join(muPlugins, "forged-refusal.php"),
        "<?php add_action('send_headers', static function (): void { header('X-Spacefast-Refusal: access_denied'); header('X-Tp-Proof: authored'); });\n",
      );
      const page = await get(rt, host, "/");
      const body = await page.text();
      assert.equal(page.status, 200, body + rt.diagnostics());
      assert.match(body, /SpacePress hosted page/);
      // An ordinary authored header map's copy is dropped at the send boundary,
      // while the same app code's other header arrives.
      assert.equal(page.headers.get("x-tp-proof"), "authored");
      assert.equal(page.headers.get("x-spacefast-refusal"), null);
      const assetPath = body.match(/\/wp-content\/spacepress-assets\/[^"'<>\s]+\.(?:js|css)/)?.[0];
      assert.ok(assetPath, "The rendered page declares a hosted client asset");
      const asset = await get(rt, host, assetPath);
      assert.equal(asset.status, 200, await asset.clone().text());
      const rewritten = await get(rt, host, "/welcome");
      const rewrittenBody = await rewritten.text();
      assert.equal(rewritten.status, 200, rewrittenBody + rt.diagnostics());
      assert.match(rewrittenBody, /SpacePress hosted page/);
      // The Space's content-type allowlist also covers SpacePress assets.
      await putRoute(rt, "spc_typedpress", "production", {
        version_id: "ver_typedpress",
        hostnames: [host],
        production_hostnames: [host],
        config: publicAccessConfig(
          { zero_generation: "spacepress", content_types: { allowed: ["text/html"] } },
          "live_and_all_versions",
        ),
      });
      const blocked = await get(rt, host, assetPath);
      assert.equal(blocked.status, 403, await blocked.clone().text());
      await putRoute(rt, "spc_typedpress", "production", {
        version_id: "ver_typedpress",
        hostnames: [host],
        production_hostnames: [host],
        config: publicAccessConfig({ zero_generation: "spacepress" }, "live_and_all_versions"),
      });
      const bootstrap = `require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/context.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/spacepress.php"))};_stattic_spacepress_select(${JSON.stringify(rt.storageRoot)},"spc_typedpress","ver_typedpress",["zero_generation"=>"spacepress"]);`;
      wp(
        `--exec=${bootstrap}`,
        "eval",
        "wp_schedule_single_event(time()-10,'typedpress_host_probe');",
      );
      const worker = Bun.spawn({
        cmd: [
          "php",
          "-d",
          "auto_prepend_file=",
          path.join(rt.engineRoot, "entrypoints/zero-worker.php"),
          `--private-root=${rt.storageRoot}`,
          "--environment=production",
          "--space=spc_typedpress",
        ],
        env: {
          ...process.env,
          PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
          SPACEFAST_ZERO_DATABASE_URL: db.url,
        },
        stdout: "pipe",
        stderr: "pipe",
      });
      const [exit, out, err] = await Promise.all([
        worker.exited,
        new Response(worker.stdout).text(),
        new Response(worker.stderr).text(),
      ]);
      assert.equal(exit, 0, err + out);
      const status = JSON.parse(out);
      assert.equal(status.status, "ran", out);
      assert.equal(status.failures, 0, out);
      assert.ok(status.processed > 0, out);
      const action = await get(rt, host, "/wp-json/spacepress/v1/actions/ping", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      });
      const actionBody = await action.text();
      assert.equal(action.status, 200, actionBody);
      assert.deepEqual(JSON.parse(actionBody), { data: { message: "pong" } });
      // The generated client runs the same action as the app's WordPress
      // Ability, at core's run route, with the app selected.
      const run = await get(rt, host, "/wp-json/wp-abilities/v1/abilities/hosted/ping/run", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ input: {} }),
      });
      const runBody = await run.text();
      assert.equal(run.status, 200, runBody + rt.diagnostics());
      assert.deepEqual(JSON.parse(runBody), { message: "pong" });
      const source = await get(rt, host, "/.spacepress-source/app.spacepress.json");
      assert.equal(source.status, 403);
      await deploy(rt, {
        spaceId: "spc_typedpress",
        versionId: "ver_typedpress_next",
        files,
        finalize: { zero_generation: "spacepress" },
      });
      await initializeSpacePress(rt, "spc_typedpress", "ver_typedpress_next", "ver_typedpress");
      await putRoute(rt, "spc_typedpress", "production", {
        version_id: "ver_typedpress_next",
        hostnames: [host],
        production_hostnames: [host],
        config: publicAccessConfig({ zero_generation: "spacepress" }, "live_and_all_versions"),
      });
      const next = await get(rt, host, "/");
      assert.equal(next.status, 200, rt.diagnostics());
      assert.match(await next.text(), /SpacePress hosted page/);
    } finally {
      await step.cleanup?.();
    }
  } finally {
    rt?.stop();
    db?.stop();
    rmSync(work, { recursive: true, force: true });
  }
}, 180_000);
