// Import rebind (`stattic-runtime-compiler --rebind-import`): a space archive
// imported into a NEW target space never installs the source space's compiled
// Zero executable graph verbatim. The native rebind recompiles the imported
// finalized sources for the target space (ABI-v2, bytecode-only), deletes the
// old graph, rewrites the dispatch artifacts, and rebinds identity — and a
// Zero-bearing import without the binary fails closed 503, while non-Zero
// imports never invoke the binary at all.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";

import { unzipSync, zipSync } from "fflate";

import {
  api,
  apiJson,
  deploy,
  errorCode,
  get,
  managementToken,
  putRoute,
  runtimeHttpPath,
  sha256,
  type Runtime,
  startRuntime,
} from "./harness.ts";

const REPO_ROOT = path.resolve(import.meta.dir, "../..");

let rt: Runtime;
let rtMissing: Runtime;

// Finalized-program shape: the source echoes the invoke context so the serving
// response proves WHICH space identity the recompiled program runs under.
const IDENTITY_SOURCE = `
const context = globalThis.__statticZeroContext;
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ spaceId: context.spaceId, versionId: context.versionId })
});
`;

const PARAMS_SOURCE = `
const request = globalThis.__statticZeroRequest;
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ id: request.params.id })
});
`;

beforeAll(async () => {
  const build = Bun.spawnSync({
    cmd: [
      "cargo",
      "build",
      "--locked",
      "-p",
      "stattic-runtime-compiler",
      "-p",
      "stattic-zero-runner",
    ],
    cwd: REPO_ROOT,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stdout.toString()}\n${build.stderr.toString()}`);
  }
  const compilerPath = path.join(REPO_ROOT, "target/debug/stattic-runtime-compiler");
  const runnerPath = path.join(REPO_ROOT, "target/debug/stattic-zero-runner");

  rt = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_FINALIZER_BIN: compilerPath,
      SPACEFAST_ZERO_RUNNER: runnerPath,
    },
  });
  // No usable compiler binary: Zero-bearing imports must fail closed; static
  // imports must not need the binary at all.
  rtMissing = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_FINALIZER_BIN: "/nonexistent/stattic-runtime-compiler",
      SPACEFAST_ZERO_RUNNER: runnerPath,
    },
  });
});

afterAll(() => {
  rt?.stop();
  rtMissing?.stop();
});

async function exportSpace(runtime: Runtime, spaceId: string): Promise<Buffer> {
  let status = await apiJson<{ export_id?: string; status: string }>(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/exports`,
    "start_space_export",
    { space_id: spaceId },
    {},
    201,
  );
  const exportId = status.export_id ?? "";
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) throw new Error(`export did not complete: ${status.status}`);
    status = await apiJson(
      runtime,
      "POST",
      `/__spacefast/api.php/exports/${exportId}/step`,
      "step_space_export",
      { space_id: spaceId, export_id: exportId },
    );
  }
  const download = await api(
    runtime,
    "GET",
    `/__spacefast/api.php/exports/${exportId}/archive`,
    "download_space_export",
    { space_id: spaceId, export_id: exportId },
  );
  expect(download.status).toBe(200);
  return Buffer.from(await download.arrayBuffer());
}

async function startImport(
  runtime: Runtime,
  spaceId: string,
  body: Record<string, unknown> = {},
): Promise<string> {
  const status = await apiJson<{ import_id?: string; status: string }>(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/imports`,
    "start_space_import",
    { space_id: spaceId },
    body,
    201,
  );
  expect(status.status).toBe("waiting_for_archive");
  return status.import_id ?? "";
}

async function uploadImportArchive(
  runtime: Runtime,
  spaceId: string,
  importId: string,
  archive: Buffer,
): Promise<{ status: string }> {
  const uploaded = await fetch(
    `${runtime.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/imports/${importId}/archive`)}`,
    {
      method: "PUT",
      headers: {
        authorization: `Bearer ${managementToken("upload_space_import", {
          space_id: spaceId,
          import_id: importId,
        })}`,
      },
      body: archive,
    },
  );
  if (uploaded.status !== 200) {
    throw new Error(`import archive upload -> ${uploaded.status}: ${await uploaded.text()}`);
  }
  return (await uploaded.json()) as { status: string };
}

function stepImport(runtime: Runtime, spaceId: string, importId: string): Promise<Response> {
  return api(
    runtime,
    "POST",
    `/__spacefast/api.php/imports/${importId}/step`,
    "step_space_import",
    { space_id: spaceId, import_id: importId },
  );
}

// Drives an import to completion, returning the terminal status response body.
async function runImport(
  runtime: Runtime,
  spaceId: string,
  archive: Buffer,
  body: Record<string, unknown> = {},
): Promise<{ status: string; version_ids?: string[] }> {
  const importId = await startImport(runtime, spaceId, body);
  let status = (await uploadImportArchive(runtime, spaceId, importId, archive)) as {
    status: string;
    version_ids?: string[];
  };
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) throw new Error(`import did not complete: ${status.status}`);
    status = await apiJson(
      runtime,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      {
        space_id: spaceId,
        import_id: importId,
      },
    );
  }
  return status;
}

// Steps an import until it errors, returning the terminal error response.
async function runImportUntilError(
  runtime: Runtime,
  spaceId: string,
  archive: Buffer,
  body: Record<string, unknown> = {},
): Promise<Response> {
  const importId = await startImport(runtime, spaceId, body);
  let status = await uploadImportArchive(runtime, spaceId, importId, archive);
  for (let steps = 0; steps <= 20; steps += 1) {
    if (status.status === "complete") {
      throw new Error("import unexpectedly completed");
    }
    const step = await stepImport(runtime, spaceId, importId);
    if (step.status !== 200) {
      return step;
    }
    status = (await step.json()) as { status: string };
  }
  throw new Error("import did not settle in an error");
}

type EndpointArtifact = {
  endpointId: string;
  sourcePath: string;
  bytecodePath: string;
  bytecodeSha256: string;
  runnerAbi: string;
};

function archiveEndpointArtifact(
  entries: Record<string, Uint8Array>,
  prefix: string,
  endpointId: string,
): { artifactName: string; artifact: EndpointArtifact } {
  const index = JSON.parse(
    Buffer.from(entries[`${prefix}zero/endpoints-index.json`] ?? []).toString("utf8"),
  ) as { endpoints: Record<string, string> };
  const artifactPath = index.endpoints[endpointId];
  if (!artifactPath) throw new Error(`missing endpoint index entry: ${endpointId}`);
  const artifactName = prefix + artifactPath;
  const artifact = JSON.parse(
    Buffer.from(entries[artifactName] ?? []).toString("utf8"),
  ) as EndpointArtifact;
  return { artifactName, artifact };
}

async function deployZeroSource(
  spaceId: string,
  versionId: string,
  host: string,
  zero: Record<string, unknown> = {},
): Promise<void> {
  await deploy(rt, {
    spaceId,
    versionId,
    zeroMode: "active",
    files: { "index.html": "<h1>rebind source</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/whoami",
          source: IDENTITY_SOURCE,
          endpoint_id: "GET /api/whoami",
        },
        {
          method: "GET",
          path: "/api/items/:id",
          source: PARAMS_SOURCE,
          endpoint_id: "GET /api/items/:id",
        },
      ],
    },
    zero,
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}

test("imported Zero programs are recompiled for the target space: forged self-consistent bytecode is rebuilt, endpoints execute under the target identity", async () => {
  const sourceSpace = "spc_rebind_source";
  const sourceVersion = "ver_rebind_source_1";
  await deployZeroSource(sourceSpace, sourceVersion, "rebind-source.test", {});

  // The source space serves its own identity before export.
  const sourceWho = await get(rt, "rebind-source.test", "/api/whoami");
  expect(sourceWho.status).toBe(200);
  expect(await sourceWho.json()).toEqual({ spaceId: sourceSpace, versionId: sourceVersion });

  const archive = await exportSpace(rt, sourceSpace);
  const entries = unzipSync(new Uint8Array(archive)) as Record<string, Uint8Array>;
  const prefix = `spacefast_export_v1/versions/${sourceVersion}/`;

  // Forge the executable graph the way a malicious archive can: malicious
  // bytecode bytes with the artifact's declared hash updated to match, so the
  // pair is self-consistent and survives every hash cross-check.
  const { artifactName, artifact } = archiveEndpointArtifact(entries, prefix, "GET /api/whoami");
  const malicious = new TextEncoder().encode("forged bytes that are not QuickJS bytecode");
  entries[prefix + artifact.bytecodePath] = malicious;
  entries[artifactName] = new TextEncoder().encode(
    JSON.stringify({ ...artifact, bytecodeSha256: `sha256:${sha256(Buffer.from(malicious))}` }),
  );
  const tampered = Buffer.from(zipSync(entries, { level: 6 }));

  const targetSpace = "spc_rebind_target";
  const imported = await runImport(rt, targetSpace, tampered, { zero_mode: "active" });
  expect(imported.status).toBe("complete");
  const versionId = imported.version_ids?.[0] ?? "";
  expect(versionId).toBe(sourceVersion);

  // The forged bytecode never survived install: the graph was recompiled from
  // the finalized source and re-hashed.
  const versionRoot = path.join(rt.storageRoot, "spaces", targetSpace, "versions", versionId);
  const installedIndex = JSON.parse(
    readFileSync(path.join(versionRoot, "zero/endpoints-index.json"), "utf8"),
  ) as { endpoints: Record<string, string> };
  const installedArtifact = JSON.parse(
    readFileSync(path.join(versionRoot, installedIndex.endpoints["GET /api/whoami"] ?? ""), "utf8"),
  ) as EndpointArtifact;
  const installedBytecode = readFileSync(path.join(versionRoot, installedArtifact.bytecodePath));
  expect(installedBytecode.equals(Buffer.from(malicious))).toBe(false);
  expect(installedArtifact.bytecodeSha256).toBe(`sha256:${sha256(installedBytecode)}`);
  expect(installedArtifact.runnerAbi).toBe("stattic-zero-runner-abi-v2");

  // Identity rebind landed in the immutable metadata.
  const metadata = JSON.parse(readFileSync(path.join(versionRoot, "metadata.json"), "utf8")) as {
    spaceId: string;
    versionId: string;
    zeroEndpointCount: number;
  };
  expect(metadata.spaceId).toBe(targetSpace);
  expect(metadata.versionId).toBe(versionId);
  expect(metadata.zeroEndpointCount).toBe(2);

  // The recompiled endpoints execute — under the TARGET space identity.
  const host = "rebind-target.test";
  await putRoute(rt, targetSpace, "production", {
    version_id: versionId,
    config: { mode: "website" },
    production_hostnames: [host],
    version_hostnames: [],
  });
  const who = await get(rt, host, "/api/whoami");
  expect(who.status).toBe(200);
  expect(await who.json()).toEqual({ spaceId: targetSpace, versionId });
  const item = await get(rt, host, "/api/items/42");
  expect(item.status).toBe(200);
  expect(await item.json()).toEqual({ id: "42" });
  const staticPage = await get(rt, host, "/");
  expect(staticPage.status).toBe(200);
});

test("rebound imports still compose with the activating fail-safe on a shared box", async () => {
  const archive = await exportSpace(rt, "spc_rebind_source");
  const targetSpace = "spc_rebind_activating";
  // No zero_mode: the engine fail-safe lands on activating even though the
  // graph was recompiled.
  const imported = await runImport(rt, targetSpace, archive);
  expect(imported.status).toBe("complete");
  const versionId = imported.version_ids?.[0] ?? "";

  const host = "rebind-activating.test";
  await putRoute(rt, targetSpace, "production", {
    version_id: versionId,
    config: { mode: "website" },
    production_hostnames: [host],
    version_hostnames: [],
  });
  const zeroReq = await get(rt, host, "/api/whoami");
  expect(zeroReq.status).toBe(503);
  expect(await errorCode(zeroReq)).toBe("zero_activating");
  const staticPage = await get(rt, host, "/");
  expect(staticPage.status).toBe(200);
  expect(await staticPage.text()).toBe("<h1>rebind source</h1>\n");
});

test("a Zero-bearing import without the native binary fails closed 503 and installs nothing", async () => {
  const archive = await exportSpace(rt, "spc_rebind_source");
  const targetSpace = "spc_rebind_no_binary";
  const failed = await runImportUntilError(rtMissing, targetSpace, archive, {
    zero_mode: "active",
  });
  expect(failed.status).toBe(503);
  expect(await errorCode(failed)).toBe("runtime_finalizer_unavailable");
  // Fail closed means the source space's executable graph was never published.
  expect(
    existsSync(
      path.join(rtMissing.storageRoot, "spaces", targetSpace, "versions", "ver_rebind_source_1"),
    ),
  ).toBe(false);
});

test("a non-Zero import completes and serves without the native binary", async () => {
  const sourceSpace = "spc_rebind_static_source";
  const sourceVersion = "ver_rebind_static_1";
  await deploy(rt, {
    spaceId: sourceSpace,
    versionId: sourceVersion,
    files: { "index.html": "<h1>static only</h1>\n" },
  });
  const archive = await exportSpace(rt, sourceSpace);

  const targetSpace = "spc_rebind_static_target";
  const imported = await runImport(rtMissing, targetSpace, archive);
  expect(imported.status).toBe("complete");
  const versionId = imported.version_ids?.[0] ?? "";
  expect(versionId).toBe(sourceVersion);

  const host = "rebind-static-target.test";
  await putRoute(rtMissing, targetSpace, "production", {
    version_id: versionId,
    config: { mode: "website" },
    production_hostnames: [host],
    version_hostnames: [],
  });
  const page = await get(rtMissing, host, "/");
  expect(page.status).toBe(200);
  expect(await page.text()).toBe("<h1>static only</h1>\n");
});

test("source-bound Zero bindings never cross the import boundary: bindings-required 422 without zero_rebind, target bindings installed with it", async () => {
  const sourceSpace = "spc_rebind_bound_source";
  const sourceVersion = "ver_rebind_bound_1";
  await deployZeroSource(sourceSpace, sourceVersion, "rebind-bound-source.test", {
    variableValues: { GREETING: "source-secret" },
  });
  const archive = await exportSpace(rt, sourceSpace);

  // Without target bindings the import refuses to install the source-bound
  // config.
  const refused = await runImportUntilError(rt, "spc_rebind_bound_refused", archive, {
    zero_mode: "active",
  });
  expect(refused.status).toBe(422);
  expect(await errorCode(refused)).toBe("space_import_zero_target_bindings_required");
  expect(
    existsSync(
      path.join(rt.storageRoot, "spaces", "spc_rebind_bound_refused", "versions", sourceVersion),
    ),
  ).toBe(false);

  // With zero_rebind target bindings the import completes and the installed
  // config carries ONLY the target values.
  const targetSpace = "spc_rebind_bound_target";
  const imported = await runImport(rt, targetSpace, archive, {
    zero_mode: "active",
    zero_rebind: { [sourceVersion]: { variableValues: { GREETING: "target-value" } } },
  });
  expect(imported.status).toBe("complete");
  // Status polls never echo the caller-supplied bindings back.
  expect("zero_rebind" in imported).toBe(false);
  const config = JSON.parse(
    readFileSync(
      path.join(
        rt.storageRoot,
        "spaces",
        targetSpace,
        "versions",
        sourceVersion,
        "zero/config.json",
      ),
      "utf8",
    ),
  ) as { variableValues: Record<string, string> };
  expect(config.variableValues).toEqual({ GREETING: "target-value" });
  const rawConfig = readFileSync(
    path.join(rt.storageRoot, "spaces", targetSpace, "versions", sourceVersion, "zero/config.json"),
    "utf8",
  );
  expect(rawConfig).not.toContain("source-secret");
});
