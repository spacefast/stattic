// Every committed client (`tp/_generated/client/` in the repository, examples included) holds
// the files `typedpress build` copies from runtime/client/ unchanged. A change to the runtime that
// doesn't rebuild every committed client fails here, not later in an app that imports a stale copy.
// This needs no build, so it runs on every PR. scripts/check-generated.sh (the rust job's `rest`
// shard, on main) builds each example and compares everything under tp/_generated/.
//
// Run: node --test runtime/client/committed.test.ts
// Fix: `typedpress build` in each project this names, and commit its tp/_generated/client/.

import { test } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const repo = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const runtime = (file: string) => readFileSync(join(repo, "runtime/client", file), "utf8");

// The files src/client.rs copies; README.md gets the app's name in place of {{name}}.
const COPIED = ["index.ts", "react.ts", "README.md", "LICENSE"];

const clients = execFileSync("git", ["ls-files", "--", ":(glob)**/tp/_generated/client/index.ts"], { cwd: repo, encoding: "utf8" })
  .split("\n")
  .filter(Boolean)
  .map((path) => dirname(path));

test("the repository has committed clients to check", () => {
  assert.ok(clients.length > 0, "git ls-files found no tp/_generated/client/index.ts");
});

for (const dir of clients) {
  test(`${dir} matches runtime/client/`, () => {
    const name = /"name":\s*"([^"]*)"/.exec(readFileSync(join(repo, dir, "schema.ts"), "utf8"))?.[1];
    assert.ok(name, `${dir}/schema.ts names no app`);
    const commerce = existsSync(join(repo, dir, 'commerce.ts'));
    const stale = [...COPIED, ...(commerce ? ["commerce.ts", "cart-store.ts", "cart-react.ts"] : [])].filter((file) => {
      let committed: string;
      try {
        committed = readFileSync(join(repo, dir, file), "utf8");
      } catch {
        return true;
      }
      let expected = runtime(file).replaceAll("{{name}}", name);
      if (commerce && file === "index.ts") expected += '\nexport * from "./commerce.ts";\nexport * from "./cart-store.ts";\n';
      if (commerce && file === "react.ts") expected += '\nexport * from "./cart-react.ts";\n';
      if (commerce && file === "LICENSE") expected += "\nPortions are adapted from Spacefast's Woo Store SDK (@woo/storefront-core),\nhttps://github.com/spacefast/woo-store-sdk, under the MIT License above.\n";
      return committed !== expected;
    });
    assert.deepEqual(stale, [], `${dir} is stale: run \`typedpress build\` in ${dirname(dirname(dirname(dir)))} and commit ${dir}/`);
  });
}
