// The other dispatch lane for the same entrypoint table: where no edge routing
// exists, init.php is auto-prepended and `_stattic_dispatch_public_alias_entrypoint`
// requires the entrypoint itself, ahead of the private-namespace 403. Driven by
// the same manifest-derived probe set as entrypoints.test.ts (which owns the
// probe-set-equality assertion — that check has no lane dependency, so one copy
// is the whole guard), so a new entrypoint is covered on both lanes or neither.
import { afterAll, beforeAll, expect, test } from "bun:test";

import { ENTRYPOINT_PATHS, ENTRYPOINT_PROBES } from "./entrypoint-probes.ts";
import { managementToken, runtimeHttpPath, startRuntime, type Runtime } from "./harness.ts";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime({ autoPrependInit: true });
}, 30000);

afterAll(() => rt?.stop());

for (const entrypointPath of ENTRYPOINT_PATHS) {
  test(`auto-prepended init dispatches ${entrypointPath} before the private-path guard`, async () => {
    const probe = ENTRYPOINT_PROBES[entrypointPath];
    if (probe === undefined) throw new Error(`no probe declared for ${entrypointPath}`);
    await probe.verify(await probe.request(rt));
  });
}

// The shared probes deliberately carry no credentials, so they prove the
// entrypoint ran but stop at its auth wall. This one goes the whole way through
// the management API on the dispatch lane: an alias dispatch that handed the
// script the wrong REQUEST_URI, or a bootstrap that never ran, cannot produce a
// real state payload.
test("auto-prepended init serves an authenticated management call end to end", async () => {
  const response = await fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/api.php/state")}`, {
    headers: { authorization: `Bearer ${managementToken("read_state")}` },
  });

  expect(response.status).toBe(200);
  const body = (await response.json()) as { ok?: boolean; runtime?: string; spaces?: unknown };
  expect(body.ok).toBe(true);
  expect(body.runtime).toBe("stattic-php");
  expect(Array.isArray(body.spaces)).toBe(true);
});
