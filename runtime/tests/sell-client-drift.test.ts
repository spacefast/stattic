import { expect, test } from "bun:test";

import { buildSellPaymentBrowser } from "../../scripts/build-sell-payment.mjs";

test("the shipped payment element is freshly generated from the shared browser flow", async () => {
  const result = await buildSellPaymentBrowser(false);
  const output = result.outputFiles?.[0];
  if (!output) throw new Error("The browser payment bundle was not generated.");
  expect(
    new Uint8Array(
      await Bun.file(new URL("../engine/runtime/sell-client.js", import.meta.url)).arrayBuffer(),
    ),
  ).toEqual(output.contents);
});
