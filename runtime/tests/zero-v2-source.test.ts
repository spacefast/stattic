// Source privacy and asset projection through real WordPress/MariaDB.
// Credentials are intentionally disposable and used only by this isolated stack.
import { test } from "bun:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  createDeclaredSession,
  uploadSessionBlobs,
  finalize,
  putRoute,
  get,
  publicAccessConfig,
  startRuntime,
} from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

test(
  "raw Zero uploads serve public assets at the root and keep the project private",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-zero-source-privacy-"));
    const db = await startMysqlContainer({
      namePrefix: "sf-zero-v2-source",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    const rt = await startRuntime({
      env: {
        PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
        SPACEFAST_ZERO_V2_PROTOTYPE: "1",
      },
    });
    const space = "spc_zero_v2_source";
    const host = "zero-v2-source.test";
    try {
      await installWordpress(rt, work, db, host);

      const files = {
        "zero.config.ts":
          'import { defineConfig } from "zero"; import { ping } from "./actions"; export default defineConfig({name:"raw-privacy", actions:{ping}});',
        "actions.ts":
          'import { action } from "zero"; export const ping = action({access:"anyone", handler(){return "private-action";}});',
        "zero/private.ts": 'export const privateValue = "never public";',
        "notes.txt": "Project notes stay private too.",
        "notes.md": "# Private project notes",
        "public/index.html": "<!doctype html><title>Raw app</title><h1>Raw public app</h1>",
      };
      const version = "ver_zero_v2_source";
      const session = await createDeclaredSession(rt, space, version, files);
      await uploadSessionBlobs(rt, session, files);
      const response = await finalize(rt, space, version, {
        upload_id: session.uploadId,
        zero_generation: "none",
      });
      assert.equal(response.status, 200, await response.text());
      await putRoute(rt, space, "production", {
        version_id: version,
        hostnames: [host],
        production_hostnames: [host],
        config: publicAccessConfig({ zero_generation: "v2" }),
      });
      const home = await get(rt, host, "/");
      assert.equal(home.status, 200);
      assert.match(await home.text(), /<h1>Raw public app<\/h1>/);
      for (const requestPath of [
        "/zero.config.ts",
        "/actions.ts",
        "/zero/private.ts",
        "/notes.txt",
        "/notes.md",
        "/notes.html",
        "/public/index.html",
      ]) {
        const refused = await get(rt, host, requestPath);
        assert.equal(refused.status, 404, requestPath);
        assert.doesNotMatch(
          await refused.text(),
          /private-action|Private project notes|never public/,
        );
      }
    } finally {
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 120_000,
);
