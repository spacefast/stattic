import { afterAll, beforeAll, expect, test } from "bun:test";

import type { RuntimeVersionFinalizeRequest } from "../../packages/common/src/contracts/runtime-api.ts";
import {
  createDeclaredSession,
  deploy,
  finalize,
  get,
  publicAccessConfig,
  putRoute,
  type Runtime,
  startRuntime,
  uploadSessionBlobs,
} from "./harness.ts";

let rt: Runtime;
const space = "spc_zero_cordon";

beforeAll(async () => {
  rt = await startRuntime();
});
afterAll(() => rt?.stop());

test("finalize requires generation authority and refuses mixed and locked generations", async () => {
  const cases: {
    id: string;
    files: Record<string, string>;
    body: Pick<RuntimeVersionFinalizeRequest, "zero_generation"> & { zero?: Record<string, never> };
    code: string;
  }[] = [
    {
      id: "ver_source_legacy",
      files: { ".zero-source/manifest.json": "{}" },
      body: { zero_generation: "legacy_0_8" },
      code: "zero_migration_required",
    },
    {
      id: "ver_raw_legacy",
      files: { "zero.config.ts": "export default {};" },
      body: { zero_generation: "legacy_0_8" },
      code: "zero_migration_required",
    },
    {
      id: "ver_source_missing",
      files: { ".zero-source/manifest.json": "{}" },
      body: {},
      code: "zero_generation_locked",
    },
    {
      id: "ver_capsule_v2",
      files: { "index.html": "legacy" },
      body: { zero_generation: "v2", zero: {} },
      code: "zero_generation_locked",
    },
    {
      id: "ver_mixed",
      files: { ".zero-source/manifest.json": "{}" },
      body: { zero_generation: "v2", zero: {} },
      code: "zero_mixed_generation",
    },
  ];
  for (const item of cases) {
    const session = await createDeclaredSession(rt, space, item.id, item.files);
    await uploadSessionBlobs(rt, session, item.files);
    const response = await finalize(rt, space, item.id, {
      upload_id: session.uploadId,
      ...item.body,
    });
    expect(await response.json()).toMatchObject({ status: 409, code: item.code });
    expect(response.status).toBe(409);
  }
});

test("an immutable legacy version retires when its Space activates v2", async () => {
  await deploy(rt, {
    spaceId: space,
    versionId: "ver_legacy",
    files: { "index.html": "retained legacy" },
    zero: {},
  });
  const activate = (generation: string) =>
    putRoute(rt, space, "production", {
      version_id: "ver_legacy",
      hostnames: ["zero-cordon.test"],
      production_hostnames: ["zero-cordon.test"],
      version_hostnames: [{ hostname: "zero-cordon--legacy.test", version_id: "ver_legacy" }],
      config: publicAccessConfig({ zero_generation: generation }, "live_and_all_versions"),
    });
  await activate("legacy_0_8");
  const legacy = await get(rt, "zero-cordon--legacy.test", "/");
  expect(legacy.status).toBe(200);
  expect(await legacy.text()).toContain("retained legacy");
  await activate("v2");
  for (const host of ["zero-cordon.test", "zero-cordon--legacy.test"]) {
    const retired = await get(rt, host, "/", { headers: { accept: "application/json" } });
    expect(retired.status).toBe(410);
    expect(await retired.json()).toMatchObject({
      code: "zero_legacy_retired",
      details: { live_hostname: "zero-cordon.test" },
    });
  }
});
