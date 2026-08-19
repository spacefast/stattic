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
// The worker's warm cache seed: published the same way, read through the same
// signed route, but under its own token audience.
const SEED = JSON.stringify({
  format: "spacefast.functions.seed.v1",
  entries: [{ key: "/", status: 200, body: "seeded" }],
});
const SEED_DIGEST = sha256(SEED);
const SEED_STORAGE_PATH = `__spacefast/functions/seeds/${SEED_DIGEST}/seed.json`;

let rt: Runtime | undefined;

beforeAll(async () => {
  const runtime = await startRuntime();
  rt = runtime;
  await deploy(runtime, {
    spaceId: SPACE_ID,
    versionId: VERSION_ID,
    files: {
      [STORAGE_PATH]: { content: BUNDLE, contentType: "application/json" },
      [SEED_STORAGE_PATH]: { content: SEED, contentType: "application/json" },
    },
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
  // The execution edge re-fetches bundles on cold starts and verifies the
  // digest itself; the CDN holding the content-addressed, tokened URL is the
  // point of the public policy (functions-host loadBundle).
  expect(signed.headers.get("a8c-edge-cache")).toBe("cache");
  expect(await signed.text()).toBe(BUNDLE);
});

test("the cache seed rides the same signed route under its own audience", async () => {
  if (!rt) throw new Error("runtime_not_started");
  const seedToken = signToken({
    aud: "spacefast-functions-seed",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: SPACE_ID,
    version_id: VERSION_ID,
    sha256: `sha256:${SEED_DIGEST}`,
  });
  const seed = await get(
    rt,
    HOST,
    `/__spacefast/functions/b/${SEED_DIGEST}/${seedToken}/seed.json`,
  );
  expect(seed.status).toBe(200);
  expect(await seed.text()).toBe(SEED);

  // The terminal filename picks the audience, so neither token reads the other
  // kind — even bound to the right Space, version and digest. The bundle URL
  // ships to the execution edge in a dispatch header; if it also opened
  // seed.json, handing out a bundle would hand out the cache seed with it.
  const bundleTokenAtSeed = signToken({
    aud: "spacefast-functions-bundle",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: SPACE_ID,
    version_id: VERSION_ID,
    sha256: `sha256:${SEED_DIGEST}`,
  });
  expect(
    (await get(rt, HOST, `/__spacefast/functions/b/${SEED_DIGEST}/${bundleTokenAtSeed}/seed.json`))
      .status,
  ).toBe(404);

  const seedTokenAtBundle = signToken({
    aud: "spacefast-functions-seed",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: SPACE_ID,
    version_id: VERSION_ID,
    sha256: `sha256:${DIGEST}`,
  });
  expect(
    (await get(rt, HOST, `/__spacefast/functions/b/${DIGEST}/${seedTokenAtBundle}/bundle.json`))
      .status,
  ).toBe(404);
});
