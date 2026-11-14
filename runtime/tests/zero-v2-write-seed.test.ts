// Zero v2 seed acceptance against real WordPress and MariaDB. Its own file so
// run.sh starts it beside zero-v2-hosted.test.ts instead of after it.
// Disposable DB credentials are intentionally local to this isolated test server.
import { test } from "bun:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { zeroV2WriteFixture } from "./fixtures/zero-v2-write.ts";
import {
  createDeclaredSession,
  finalize,
  get,
  publicAccessConfig,
  putRoute,
  startRuntime,
  uploadSessionBlobs,
  versionRoot,
} from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

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
