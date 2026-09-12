import { expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { existsSync, readdirSync, readFileSync, watch } from "node:fs";
import { mkdtemp, readdir, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

import { fetchBlocksEnginePlugin } from "../../scripts/fetch-blocks-engine.mjs";

function snapshot(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true, recursive: true })
    .filter((entry) => entry.isFile())
    .map((entry) => {
      const file = path.join(entry.parentPath, entry.name);
      const digest = createHash("sha256").update(readFileSync(file)).digest("hex");
      return `${path.relative(directory, file)}:${digest}`;
    })
    .toSorted();
}

test("concurrent plugin fetches expose only a complete pinned tree", async () => {
  const reference = await fetchBlocksEnginePlugin();
  const expected = snapshot(path.dirname(reference));
  const temporary = await mkdtemp(path.join(os.tmpdir(), "blocks-engine-cache-"));
  const cacheDir = path.join(temporary, "cache");
  const published = Promise.withResolvers<void>();
  const observations: string[][] = [];
  const watcher = watch(temporary, () => {
    if (!existsSync(cacheDir)) return;
    const pluginRoot = path.join(cacheDir, path.basename(path.dirname(reference)));
    observations.push(existsSync(pluginRoot) ? snapshot(pluginRoot) : []);
    published.resolve();
  });
  try {
    const results = await Promise.allSettled(
      Array.from({ length: 8 }, async () => {
        const plugin = await fetchBlocksEnginePlugin({ cacheDir });
        expect(snapshot(path.dirname(plugin))).toEqual(expected);
        // Force a concurrent refresh while other workers read the same tree.
        await fetchBlocksEnginePlugin({ cacheDir, force: true });
        expect(snapshot(path.dirname(plugin))).toEqual(expected);
      }),
    );
    for (const result of results) {
      if (result.status === "rejected") throw result.reason;
    }
    await published.promise;
    for (const observation of observations) expect(observation).toEqual(expected);
    expect(await readdir(temporary)).toEqual(["cache"]);
  } finally {
    watcher.close();
    await rm(temporary, { recursive: true, force: true });
  }
});
