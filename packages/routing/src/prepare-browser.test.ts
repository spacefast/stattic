import { expect, test } from "bun:test";

import { instantiateBrowserFinalizer, invokeBrowserFinalizerJsonWithExports } from "./browser.js";
import {
  prepareRequest,
  unwrapPrepareResponse,
  type AnalyzeFinalizeOutput,
} from "./prepare-protocol.js";

test("browser WASI host runs the canonical Rust config parser", async () => {
  const wasm = await Bun.file(
    new URL("../dist/stattic-runtime-core.wasm", import.meta.url),
  ).arrayBuffer();
  const exports = instantiateBrowserFinalizer(wasm);
  const output = unwrapPrepareResponse<AnalyzeFinalizeOutput>(
    invokeBrowserFinalizerJsonWithExports(
      exports,
      prepareRequest("analyze", {
        configCandidates: {
          "spacefast.jsonc": `{
            // JSONC is parsed by Rust in the browser too.
            "fallback": "/index.html",
          }`,
        },
      }),
    ),
  );

  expect(output.selectedConfigPath).toBe("spacefast.jsonc");
  expect(output.config).toMatchObject({ fallback: "/index.html" });
  expect(output.diagnostics).toEqual([]);
});
