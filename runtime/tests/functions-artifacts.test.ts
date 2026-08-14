// Signed Functions bundle delivery, end to end through the real PHP origin.
//
// The bundle is a published file, but it is never public static content. The
// only successful read is the signed control route, which resolves the digest
// into the version's private `files/` tree.
import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  deploy,
  get,
  publicAccessConfig,
  RUNTIME_INSTANCE_ID,
  sha256,
  signToken,
  startRuntime,
  type Runtime,
} from "./harness.ts";

const HOST = "functions-artifacts.test";
const SPACE_ID = "spc_fx_artifacts";
const VERSION_ID = "ver_fx_artifacts_1";
const BUNDLE = JSON.stringify({
  format: "spacefast.functions.bundle.v1",
  mainModule: "index.js",
  modules: { "index.js": { js: "export default { fetch: () => new Response('hello') };" } },
});
const DIGEST = sha256(BUNDLE);
const STORAGE_PATH = `__spacefast/functions/bundles/${DIGEST}/bundle.json`;

let rt: Runtime | undefined;

beforeAll(async () => {
  const runtime = await startRuntime();
  rt = runtime;
  await deploy(runtime, {
    spaceId: SPACE_ID,
    versionId: VERSION_ID,
    files: { [STORAGE_PATH]: { content: BUNDLE, contentType: "application/json" } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("a signed bundle route reads the private published bundle", async () => {
  if (!rt) throw new Error("runtime_not_started");
  const raw = await get(rt, HOST, `/${STORAGE_PATH}`);
  expect([403, 404]).toContain(raw.status);

  const token = signToken({
    aud: "spacefast-functions-bundle",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: SPACE_ID,
    version_id: VERSION_ID,
    sha256: `sha256:${DIGEST}`,
  });
  const signed = await get(rt, HOST, `/__spacefast/functions/b/${DIGEST}/${token}/bundle.json`);
  expect(signed.status).toBe(200);
  expect(signed.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect(await signed.text()).toBe(BUNDLE);
});
