import { expect, test } from "bun:test";
import path from "node:path";

import { buildSpaceClient } from "../../scripts/build-space-client.mjs";

test("the served Space client and lazy modules are freshly built from the public package", async () => {
  const result = await buildSpaceClient(false);
  const outputs = result.outputFiles;
  if (!outputs?.length) throw new Error("The Space client bundle was not generated.");
  const root = path.join(import.meta.dir, "../engine/runtime/space-client");
  const expected = outputs.map((output) => path.relative(root, output.path)).toSorted();
  const actual = Array.fromAsync(new Bun.Glob("**/*.js").scan({ cwd: root }));
  expect((await actual).toSorted()).toEqual(expected);
  await Promise.all(
    outputs.map(async (output) => {
      expect(new Uint8Array(await Bun.file(output.path).arrayBuffer())).toEqual(output.contents);
    }),
  );
});
