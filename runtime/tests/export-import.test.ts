// Space export/import: archive shape (manifest.json naming, access-policy entry),
// full roundtrip back to a serving space, version ID remapping, the zip-bomb
// compression-ratio guard, and rejection of executable or unexpected artifacts.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import { unzipSync, zipSync } from "fflate";

import {
  api,
  apiJson,
  buildZip,
  deploy,
  errorCode,
  finalizeRaw,
  get,
  managementToken,
  putRoute,
  RUNTIME_INSTANCE_ID,
  runtimeHttpPath,
  sha256,
  signToken,
  type Runtime,
  startRuntime,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_exp";
const VERSION = "ver_exp_1";
const HOST = "exp.test";
const INDEX = "<h1>export</h1>\n";
const PHP_BINARY = process.env.PHP_BINARY ?? "php";

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: { "index.html": INDEX, "docs/a.txt": "alpha\n" },
    activate: {
      route_name: "production",
      config: {
        mode: "website",
        policy: {
          rules: [{ effect: "allow", match: { pathPattern: "/" } }],
        },
      },
      production_hostnames: [HOST],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

type JobStatus = {
  status: string;
  export_id?: string;
  import_id?: string;
  version_ids?: string[];
  cursor?: number;
  processed_files?: number;
  total_files?: number;
  total_uncompressed_bytes?: number;
};

async function runExport(spaceId: string): Promise<Buffer> {
  let status = await apiJson<JobStatus>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/exports`,
    "start_space_export",
    { space_id: spaceId },
    {},
    201,
  );
  const exportId = status.export_id ?? "";
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) {
      throw new Error(`export did not complete: ${status.status}`);
    }
    status = await apiJson<JobStatus>(
      rt,
      "POST",
      `/__spacefast/api.php/exports/${exportId}/step`,
      "step_space_export",
      { space_id: spaceId, export_id: exportId },
    );
  }
  const download = await api(
    rt,
    "GET",
    `/__spacefast/api.php/exports/${exportId}/archive`,
    "download_space_export",
    { space_id: spaceId, export_id: exportId },
  );
  expect(download.status).toBe(200);
  expect(download.headers.get("content-type")).toBe("application/zip");
  return Buffer.from(await download.arrayBuffer());
}

async function startImport(
  spaceId: string,
  body: Record<string, unknown> = {},
  runtime = rt,
): Promise<string> {
  const status = await apiJson<JobStatus>(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/imports`,
    "start_space_import",
    { space_id: spaceId },
    { install_access_policy: true, ...body },
    201,
  );
  expect(status.status).toBe("waiting_for_archive");
  return status.import_id ?? "";
}

async function uploadImportArchive(
  spaceId: string,
  importId: string,
  archive: Buffer,
  runtime = rt,
): Promise<Response> {
  return fetch(
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
}

function withDeclaredZipSize(archive: Buffer, entryName: string, declaredSize: number): Buffer {
  const bytes = Buffer.from(archive);
  const endOfDirectorySignature = 0x06054b50;
  const centralEntrySignature = 0x02014b50;
  let endOfDirectory = -1;
  for (let offset = bytes.length - 22; offset >= Math.max(0, bytes.length - 65_557); offset -= 1) {
    if (bytes.readUInt32LE(offset) === endOfDirectorySignature) {
      endOfDirectory = offset;
      break;
    }
  }
  if (endOfDirectory < 0) throw new Error("ZIP end-of-directory record not found");

  const entryCount = bytes.readUInt16LE(endOfDirectory + 10);
  let offset = bytes.readUInt32LE(endOfDirectory + 16);
  for (let index = 0; index < entryCount; index += 1) {
    if (bytes.readUInt32LE(offset) !== centralEntrySignature) {
      throw new Error("ZIP central-directory entry is malformed");
    }
    const nameLength = bytes.readUInt16LE(offset + 28);
    const extraLength = bytes.readUInt16LE(offset + 30);
    const commentLength = bytes.readUInt16LE(offset + 32);
    const name = bytes.subarray(offset + 46, offset + 46 + nameLength).toString("utf8");
    if (name === entryName) {
      bytes.writeUInt32LE(declaredSize, offset + 24);
      return bytes;
    }
    offset += 46 + nameLength + extraLength + commentLength;
  }
  throw new Error(`ZIP entry not found: ${entryName}`);
}

function buildZipRecordFlood(targetPath: string, directoryCount: number): void {
  const descriptor = JSON.stringify({ format: "spacefast_export_v1", versions: [] });
  const build = Bun.spawnSync([
    PHP_BINARY,
    "-d",
    "memory_limit=256M",
    "-r",
    [
      "$zip = new ZipArchive();",
      "if ($zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { exit(2); }",
      `$zip->addFromString('spacefast_export_v1/spacefast.json', ${JSON.stringify(descriptor)});`,
      "$count = max(0, (int) $argv[2]);",
      "for ($index = 0; $index < $count; $index += 1) {",
      "  if (!$zip->addEmptyDir('padding/' . str_pad((string) $index, 6, '0', STR_PAD_LEFT))) { exit(3); }",
      "}",
      "$zip->addFromString('spacefast_export_v1/unexpected.txt', 'unexpected');",
      "if (!$zip->close()) { exit(4); }",
    ].join(" "),
    targetPath,
    String(directoryCount),
  ]);
  if (build.exitCode !== 0) {
    throw new Error(`ZIP record-flood fixture failed: ${build.stderr.toString()}`);
  }
}

async function runImport(spaceId: string, importId: string, archive: Buffer): Promise<JobStatus> {
  const uploaded = await uploadImportArchive(spaceId, importId, archive);
  if (uploaded.status !== 200) {
    throw new Error(`import archive upload -> ${uploaded.status}: ${await uploaded.text()}`);
  }
  let status = (await uploaded.json()) as JobStatus;
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) {
      throw new Error(`import did not complete: ${status.status}`);
    }
    status = await apiJson<JobStatus>(
      rt,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: spaceId, import_id: importId },
    );
  }
  return status;
}

function holdFlockUntilReleased(
  lockPath: string,
): Promise<{ release: () => void; exited: Promise<void> }> {
  mkdirSync(path.dirname(lockPath), { recursive: true });
  return new Promise((resolve, reject) => {
    const child = spawn(PHP_BINARY, [
      "-r",
      [
        '$f = fopen($argv[1], "c");',
        "if ($f === false || !flock($f, LOCK_EX)) { exit(2); }",
        'fwrite(STDOUT, "locked\\n"); fflush(STDOUT);',
        "fgets(STDIN);",
        "flock($f, LOCK_UN); fclose($f);",
      ].join(" "),
      lockPath,
    ]);
    let ready = false;
    let exitedResolve: (() => void) | undefined;
    let exitedReject: ((error: Error) => void) | undefined;
    const exited = new Promise<void>((resolveExited, rejectExited) => {
      exitedResolve = resolveExited;
      exitedReject = rejectExited;
    });
    child.stdout.on("data", (chunk: Buffer) => {
      if (!ready && chunk.toString().includes("locked")) {
        ready = true;
        resolve({
          release: () => child.stdin.end("release\n"),
          exited,
        });
      }
    });
    child.on("error", (error) => {
      if (!ready) reject(error);
      exitedReject?.(error);
    });
    child.on("exit", (code) => {
      if (!ready) {
        reject(new Error("flock holder exited early with code " + code));
        return;
      }
      if (code === 0) exitedResolve?.();
      else exitedReject?.(new Error("flock holder exited with code " + code));
    });
  });
}

test("the import archive upload honors the per-import upload lock", async () => {
  const archive = await runExport(SPACE);
  const targetSpace = "spc_import_race_target";
  const importId = await startImport(targetSpace);
  const lockPath = path.join(rt.storageRoot, "runtime", "space-import-locks", importId + ".lock");
  const holder = await holdFlockUntilReleased(lockPath);
  let released = false;
  try {
    const blocked = await fetch(
      rt.baseUrl + runtimeHttpPath("/__spacefast/api.php/imports/" + importId + "/archive"),
      {
        method: "PUT",
        headers: {
          authorization:
            "Bearer " +
            managementToken("upload_space_import", {
              import_id: importId,
            }),
        },
        body: archive,
      },
    );
    expect(blocked.status).toBe(503);
    expect(await errorCode(blocked)).toBe("runtime_write_lock_unavailable");

    const waiting = await apiJson<JobStatus>(
      rt,
      "GET",
      "/__spacefast/api.php/imports/" + importId,
      "get_space_import",
      { space_id: targetSpace, import_id: importId },
    );
    expect(waiting.status).toBe("waiting_for_archive");

    holder.release();
    released = true;
    await holder.exited;

    const accepted = await uploadImportArchive(targetSpace, importId, archive);
    expect(accepted.status).toBe(200);

    const committed = await fetch(
      rt.baseUrl + runtimeHttpPath("/__spacefast/api.php/imports/" + importId + "/archive"),
      {
        method: "PUT",
        headers: {
          authorization:
            "Bearer " +
            managementToken("upload_space_import", {
              import_id: importId,
            }),
        },
        body: archive,
      },
    );
    expect(committed.status).toBe(409);
    expect(await errorCode(committed)).toBe("space_import_archive_locked");
  } finally {
    if (!released) holder.release();
    await holder.exited.catch(() => undefined);
  }
}, 30_000);

test("a prepared import revalidates an executable archive member before staging it", async () => {
  const cleanArchive = await runExport(SPACE);
  const entries = unzipSync(new Uint8Array(cleanArchive));
  const servingName = Object.keys(entries).find((name) => name.endsWith("/serving.php"));
  expect(servingName).toBeDefined();

  const targetSpace = "spc_prepared_archive_revalidation";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(targetSpace, importId, cleanArchive);
  expect(uploaded.status).toBe(200);

  const jobRoot = path.join(rt.storageRoot, "runtime", "space-imports", importId);
  const executionMarker = path.join(jobRoot, "executable-artifact-ran");
  entries[servingName ?? ""] = new TextEncoder().encode(
    `<?php\nfile_put_contents(${JSON.stringify(executionMarker)}, 'ran');\nreturn [];\n`,
  );
  writeFileSync(path.join(jobRoot, "archive.zip"), Buffer.from(zipSync(entries, { level: 6 })));

  const rejected = await api(
    rt,
    "POST",
    `/__spacefast/api.php/imports/${importId}/step`,
    "step_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(rejected.status).toBe(422);
  expect(await errorCode(rejected)).toBe("space_import_php_artifact_invalid");
  expect(existsSync(executionMarker)).toBe(false);
  expect(existsSync(path.join(jobRoot, "staging", "versions", VERSION, "serving.php"))).toBe(false);

  const preserved = await apiJson<JobStatus>(
    rt,
    "GET",
    `/__spacefast/api.php/imports/${importId}`,
    "get_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(preserved.status).toBe("pending");
  expect(preserved.cursor).toBe(0);
  expect(preserved.processed_files).toBe(0);
});

test("the root descriptor is bounded before import", async () => {
  const archivePath = path.join(rt.root, "oversized-root.zip");
  buildZip(archivePath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      zeros: 2 * 1024 * 1024 + 1,
    },
  ]);
  const targetSpace = "spc_oversized_root";
  const importId = await startImport(targetSpace);
  const response = await uploadImportArchive(targetSpace, importId, readFileSync(archivePath));
  expect(response.status).toBe(413);
  expect(await errorCode(response)).toBe("space_import_entry_too_large");
});

test("JSON imports reject ZIP entries whose declared and decompressed sizes disagree", async () => {
  const descriptorPath = "spacefast_export_v1/spacefast.json";
  const policyPath = "spacefast_export_v1/space/access-policy.json";
  const zeroPath = "spacefast_export_v1/versions/ver_size_mismatch/zero/endpoints/endpoint.json";
  const cases: Array<{
    spaceId: string;
    mismatchPath: string;
    expectedCode: string;
    entries: Record<string, string>;
  }> = [
    {
      spaceId: "spc_size_mismatch_descriptor",
      mismatchPath: descriptorPath,
      expectedCode: "space_import_archive_invalid",
      entries: {
        [descriptorPath]: JSON.stringify({ format: "spacefast_export_v1", versions: [] }),
      },
    },
    {
      spaceId: "spc_size_mismatch_policy",
      mismatchPath: policyPath,
      expectedCode: "space_import_archive_invalid",
      entries: {
        [descriptorPath]: JSON.stringify({ format: "spacefast_export_v1", versions: [] }),
        [policyPath]: JSON.stringify({ policy: null }),
      },
    },
    {
      spaceId: "spc_size_mismatch_zero",
      mismatchPath: zeroPath,
      expectedCode: "space_import_entry_invalid",
      entries: {
        [descriptorPath]: JSON.stringify({
          format: "spacefast_export_v1",
          versions: ["ver_size_mismatch"],
        }),
        [zeroPath]: "{}",
      },
    },
  ];

  for (const item of cases) {
    const encodedEntries = Object.fromEntries(
      Object.entries(item.entries).map(([name, content]) => [
        name,
        new TextEncoder().encode(content + " "),
      ]),
    );
    const archive = Buffer.from(zipSync(encodedEntries, { level: 6 }));
    const declaredSize = new TextEncoder().encode(item.entries[item.mismatchPath]).byteLength;
    const mismatchedArchive = withDeclaredZipSize(archive, item.mismatchPath, declaredSize);
    const importId = await startImport(item.spaceId);
    const response = await uploadImportArchive(item.spaceId, importId, mismatchedArchive);
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe(item.expectedCode);
  }
});

test("nested Zero endpoint JSON is capped at two MiB", async () => {
  const archivePath = path.join(rt.root, "oversized-zero-endpoint.zip");
  buildZip(archivePath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      content: JSON.stringify({ format: "spacefast_export_v1", versions: ["ver_zero_large"] }),
    },
    {
      name: "spacefast_export_v1/versions/ver_zero_large/zero/endpoints/nested/large.json",
      zeros: 2 * 1024 * 1024 + 1,
    },
  ]);

  const importId = await startImport("spc_zero_large");
  const response = await uploadImportArchive("spc_zero_large", importId, readFileSync(archivePath));
  expect(response.status).toBe(413);
  expect(await errorCode(response)).toBe("space_import_entry_too_large");
});

test("archive accounting includes descriptor and policy bytes exactly once without counting them as files", async () => {
  const archive = await runExport(SPACE);
  const entries = unzipSync(new Uint8Array(archive));
  const descriptor = "spacefast_export_v1/spacefast.json";
  const policy = "spacefast_export_v1/space/access-policy.json";
  const versionPrefix = "spacefast_export_v1/versions/";
  expect(entries[descriptor]).toBeDefined();
  expect(entries[policy]).toBeDefined();
  const versionEntries = Object.entries(entries).filter(
    ([name]) => name.startsWith(versionPrefix) && !name.endsWith("/"),
  );
  const expectedBytes =
    (entries[descriptor]?.byteLength ?? 0) +
    (entries[policy]?.byteLength ?? 0) +
    versionEntries.reduce((total, [, bytes]) => total + bytes.byteLength, 0);

  const targetSpace = "spc_import_accounting";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(targetSpace, importId, archive);
  expect(uploaded.status).toBe(200);
  const status = (await uploaded.json()) as JobStatus;
  expect(status).toMatchObject({
    status: "pending",
    cursor: 0,
    processed_files: 0,
    total_files: versionEntries.length,
    total_uncompressed_bytes: expectedBytes,
  });
});

test("every ZIP record counts toward the archive-entry ceiling before entry processing", async () => {
  const archivePath = path.join(rt.root, "entry-flood.zip");
  // The descriptor plus 100,001 directory records exactly fill the archive
  // entry ceiling. The otherwise-unexpected record is the one record over it.
  buildZipRecordFlood(archivePath, 100_001);

  const importId = await startImport("spc_entry_flood");
  const response = await uploadImportArchive(
    "spc_entry_flood",
    importId,
    readFileSync(archivePath),
  );
  expect(response.status).toBe(413);
  expect(await errorCode(response)).toBe("space_import_archive_too_large");
}, 30_000);

test("directory records preserve materialized-file counts and cursor semantics", async () => {
  const entries = unzipSync(new Uint8Array(await runExport(SPACE)));
  const materializedFiles = Object.keys(entries).filter(
    (name) => name.startsWith("spacefast_export_v1/versions/") && !name.endsWith("/"),
  ).length;
  for (let index = 0; index < 750; index += 1) {
    entries[
      `spacefast_export_v1/versions/${VERSION}/files/empty/${index.toString().padStart(4, "0")}/`
    ] = new Uint8Array();
  }
  const archive = Buffer.from(zipSync(entries, { level: 6 }));

  const targetSpace = "spc_import_directories";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(targetSpace, importId, archive);
  expect(uploaded.status).toBe(200);
  expect(await uploaded.json()).toMatchObject({
    status: "pending",
    cursor: 0,
    processed_files: 0,
    total_files: materializedFiles,
  });

  const completed = await apiJson<JobStatus>(
    rt,
    "POST",
    `/__spacefast/api.php/imports/${importId}/step`,
    "step_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(completed.status).toBe("complete");
  expect(completed.processed_files).toBe(materializedFiles);
  expect(completed.cursor).toBe(Object.keys(entries).length);
  expect(
    existsSync(
      path.join(rt.storageRoot, "spaces", targetSpace, "versions", VERSION, "metadata.json"),
    ),
  ).toBe(true);
});

test("a resumed import validates old staged PHP before final materialization", async () => {
  const baseEntries = unzipSync(new Uint8Array(await runExport(SPACE)));
  const descriptorName = "spacefast_export_v1/spacefast.json";
  expect(baseEntries[descriptorName]).toBeDefined();

  const paddedEntries: Record<string, Uint8Array> = {
    [descriptorName]: baseEntries[descriptorName] ?? new Uint8Array(),
  };
  for (const [name, bytes] of Object.entries(baseEntries)) {
    if (name !== descriptorName) paddedEntries[name] = bytes;
  }
  for (let index = 0; index <= 500; index += 1) {
    const suffix = index.toString().padStart(4, "0");
    paddedEntries[`spacefast_export_v1/versions/${VERSION}/files/padding/${suffix}.txt`] =
      new TextEncoder().encode(`padding-${suffix}\n`);
  }
  const targetSpace = "spc_resumed_staging_revalidation";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(
    targetSpace,
    importId,
    Buffer.from(zipSync(paddedEntries, { level: 6 })),
  );
  expect(uploaded.status).toBe(200);

  const firstStep = await api(
    rt,
    "POST",
    `/__spacefast/api.php/imports/${importId}/step`,
    "step_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(firstStep.status).toBe(200);
  const afterFirstStep = (await firstStep.json()) as JobStatus;
  expect(afterFirstStep.status).toBe("running");
  expect(afterFirstStep.processed_files).toBe(500);
  expect(afterFirstStep.cursor).toBeGreaterThan(500);

  const jobRoot = path.join(rt.storageRoot, "runtime", "space-imports", importId);
  const stagedServing = path.join(jobRoot, "staging", "versions", VERSION, "serving.php");
  expect(existsSync(stagedServing)).toBe(true);
  const nextChunkFile = path.join(
    jobRoot,
    "staging",
    "versions",
    VERSION,
    "files",
    "padding",
    "0500.txt",
  );
  expect(existsSync(nextChunkFile)).toBe(false);

  const executionMarker = path.join(jobRoot, "executable-artifact-ran");
  const executableArtifact = new TextEncoder().encode(
    `<?php\nfile_put_contents(${JSON.stringify(executionMarker)}, 'ran');\nreturn [];\n`,
  );
  writeFileSync(stagedServing, executableArtifact);
  const rejectedStaging = await api(
    rt,
    "POST",
    `/__spacefast/api.php/imports/${importId}/step`,
    "step_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(rejectedStaging.status).toBe(422);
  expect(await errorCode(rejectedStaging)).toBe("space_import_php_artifact_invalid");
  expect(existsSync(executionMarker)).toBe(false);
  expect(existsSync(path.join(rt.storageRoot, "spaces", targetSpace, "versions", VERSION))).toBe(
    false,
  );

  const preserved = await apiJson<JobStatus>(
    rt,
    "GET",
    `/__spacefast/api.php/imports/${importId}`,
    "get_space_import",
    { space_id: targetSpace, import_id: importId },
  );
  expect(preserved.status).toBe("running");
  expect(preserved.cursor).toBe(afterFirstStep.cursor);
  expect(preserved.processed_files).toBe(afterFirstStep.processed_files);
});

test("export/import roundtrip restores versions, access policy, and serving", async () => {
  const archive = await runExport(SPACE);

  // Spec archive shape: per-version manifest.json (not the on-disk metadata.json)
  // and explicit space config under space/access-policy.json.
  const entries = archive.toString("latin1");
  expect(entries).toContain("spacefast_export_v1/spacefast.json");
  expect(entries).toContain(`spacefast_export_v1/versions/${VERSION}/manifest.json`);
  expect(entries).toContain(`spacefast_export_v1/versions/${VERSION}/files/index.html`);
  expect(entries).toContain("spacefast_export_v1/space/access-policy.json");
  // Exports emit only the current layout; the pre-rename name must never reappear.
  expect(entries).not.toContain("stattic_export_v1/");
  expect(entries).not.toContain(`${VERSION}/metadata.json`);

  // Wipe the space, then import the archive back.
  await apiJson(rt, "POST", `/__spacefast/api.php/spaces/${SPACE}/delete`, "delete_space", {
    space_id: SPACE,
  });
  expect((await get(rt, HOST, "/")).status).toBe(503);

  const importId = await startImport(SPACE);
  const status = await runImport(SPACE, importId, archive);
  expect(status.version_ids).toEqual([VERSION]);

  // Disk name is restored to metadata.json and the unified access policy is reinstalled.
  const spaceRoot = path.join(rt.storageRoot, "spaces", SPACE);
  expect(existsSync(path.join(spaceRoot, "versions", VERSION, "metadata.json"))).toBe(true);
  const restoredPolicy = JSON.parse(readFileSync(path.join(spaceRoot, "policy.json"), "utf8")) as {
    policy: { rules: Array<{ effect: string }> };
  };
  expect(restoredPolicy.policy.rules[0]?.effect).toBe("allow");

  // Imported versions are not public until a route points at them.
  expect((await get(rt, HOST, "/")).status).toBe(503);
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: { mode: "website" },
    production_hostnames: [HOST],
    version_hostnames: [],
  });
  const served = await get(rt, HOST, "/");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(INDEX);
});

test("managed import preserves the target access policy and secrets", async () => {
  const targetSpace = "spc_import_policy_preserved";
  const spaceRoot = path.join(rt.storageRoot, "spaces", targetSpace);
  mkdirSync(spaceRoot, { recursive: true });
  const authoritativePolicy = {
    space_id: targetSpace,
    policy: { rules: [{ effect: "deny", match: { pathPattern: "/" } }] },
    updated_at: "authoritative-policy",
  };
  const authoritativeSecrets = {
    space_id: targetSpace,
    secrets: { gate: "authoritative-secret" },
    updated_at: "authoritative-secrets",
  };
  writeFileSync(path.join(spaceRoot, "policy.json"), JSON.stringify(authoritativePolicy));
  writeFileSync(path.join(spaceRoot, "policy-secrets.json"), JSON.stringify(authoritativeSecrets));

  const archive = Buffer.from(
    zipSync({
      "spacefast_export_v1/spacefast.json": new TextEncoder().encode(
        JSON.stringify({ format: "spacefast_export_v1", versions: [] }),
      ),
      "spacefast_export_v1/space/access-policy.json": new TextEncoder().encode(
        JSON.stringify({
          policy: { rules: [{ effect: "allow", match: { pathPattern: "/" } }] },
          secrets: { gate: "archive-secret" },
        }),
      ),
    }),
  );
  const importId = await startImport(targetSpace, { install_access_policy: false });
  await runImport(targetSpace, importId, archive);

  expect(JSON.parse(readFileSync(path.join(spaceRoot, "policy.json"), "utf8"))).toEqual(
    authoritativePolicy,
  );
  expect(JSON.parse(readFileSync(path.join(spaceRoot, "policy-secrets.json"), "utf8"))).toEqual(
    authoritativeSecrets,
  );
});

test("imports remap source version ids through version_id_map", async () => {
  await deploy(rt, {
    spaceId: "spc_remap_src",
    versionId: "ver_src_1",
    files: { "index.html": "<h1>remap</h1>\n" },
  });
  const archive = await runExport("spc_remap_src");

  const importId = await startImport("spc_remap_dst", {
    version_id_map: { ver_src_1: "ver_dst_1" },
  });
  const status = await runImport("spc_remap_dst", importId, archive);
  expect(status.version_ids).toEqual(["ver_dst_1"]);

  const versionsRoot = path.join(rt.storageRoot, "spaces", "spc_remap_dst", "versions");
  expect(existsSync(path.join(versionsRoot, "ver_dst_1", "serving.php"))).toBe(true);
  expect(existsSync(path.join(versionsRoot, "ver_src_1"))).toBe(false);

  await putRoute(rt, "spc_remap_dst", "production", {
    version_id: "ver_dst_1",
    config: { mode: "website" },
    production_hostnames: ["remap.test"],
    version_hostnames: [],
  });
  const served = await get(rt, "remap.test", "/");
  expect(served.status).toBe(200);
  expect(served.headers.get("x-spacefast-version")).toBe("ver_dst_1");
});

test("scan manifest follows imported serving shards when inert provenance omits a served file", async () => {
  const archive = await runExport(SPACE);
  const entries = unzipSync(new Uint8Array(archive));
  const manifestName = Object.keys(entries).find((name) =>
    name.endsWith(`/versions/${VERSION}/manifest.json`),
  );
  expect(manifestName).toBeDefined();
  const manifest = JSON.parse(Buffer.from(entries[manifestName ?? ""] ?? []).toString("utf8")) as {
    files: Record<string, unknown>;
  };
  expect(manifest.files["docs/a.txt"]).toBeDefined();
  delete manifest.files["docs/a.txt"];
  entries[manifestName ?? ""] = new TextEncoder().encode(JSON.stringify(manifest));
  const craftedArchive = Buffer.from(zipSync(entries, { level: 6 }));

  const targetSpace = "spc_scan_shard_truth";
  const importId = await startImport(targetSpace);
  const status = await runImport(targetSpace, importId, craftedArchive);
  expect(status.version_ids).toEqual([VERSION]);
  await putRoute(rt, targetSpace, "production", {
    version_id: VERSION,
    config: { mode: "website" },
    production_hostnames: ["scan-shard-truth.test"],
    version_hostnames: [],
  });
  const served = await get(rt, "scan-shard-truth.test", "/docs/a.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("alpha\n");

  const manifestToken = signToken({
    aud: "stattic-runtime-file-fetch",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: targetSpace,
    version_id: VERSION,
    scope: "scan_manifest",
    variant_route: "production",
  });
  const scanManifest = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(
      `/__spacefast/api.php/spaces/${targetSpace}/versions/${VERSION}/scan-manifest?variant_route=production`,
    )}`,
    { headers: { authorization: `Bearer ${manifestToken}` } },
  );
  expect(scanManifest.status).toBe(200);
  const scanFiles = (await scanManifest.json()) as {
    files: Array<{ path: string; sha256: string }>;
  };
  const servedDigest = sha256("alpha\n");
  expect(scanFiles.files).toContainEqual({
    path: "docs/a.txt",
    size: 6,
    sha256: servedDigest,
    content_type: "text/plain; charset=utf-8",
  });

  const fileToken = signToken({
    aud: "stattic-runtime-file-fetch",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: targetSpace,
    version_id: VERSION,
    sha256: servedDigest,
  });
  const byHash = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(
      `/__spacefast/api.php/spaces/${targetSpace}/versions/${VERSION}/files/by-hash/${servedDigest}`,
    )}`,
    { headers: { authorization: `Bearer ${fileToken}` } },
  );
  expect(byHash.status).toBe(200);
  expect(await byHash.text()).toBe("alpha\n");
});

test("import rejects a forged clean shard digest that does not match served bytes", async () => {
  const archive = await runExport(SPACE);
  const entries = unzipSync(new Uint8Array(archive));
  const actualDigest = sha256("alpha\n");
  const forgedCleanDigest = sha256(INDEX);
  const shardName = Object.keys(entries).find((name) => {
    if (!name.includes(`/versions/${VERSION}/file-shards/`) || !name.endsWith(".php")) {
      return false;
    }
    const source = Buffer.from(entries[name] ?? []).toString("utf8");
    return source.includes("docs/a.txt") && source.includes(actualDigest);
  });
  expect(shardName).toBeDefined();
  const shardSource = Buffer.from(entries[shardName ?? ""] ?? []).toString("utf8");
  const forgedShard = shardSource.replaceAll(actualDigest, forgedCleanDigest);
  expect(forgedShard).not.toBe(shardSource);
  entries[shardName ?? ""] = new TextEncoder().encode(forgedShard);
  const craftedArchive = Buffer.from(zipSync(entries, { level: 6 }));

  const targetSpace = "spc_forged_scan_digest";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(targetSpace, importId, craftedArchive);
  expect(uploaded.status).toBe(200);
  let status = (await uploaded.json()) as JobStatus;
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) throw new Error(`forged import did not settle: ${status.status}`);
    const step = await api(
      rt,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: targetSpace, import_id: importId },
    );
    if (step.status !== 200) {
      expect(step.status).toBe(422);
      expect(await errorCode(step)).toBe("space_import_archive_invalid");
      return;
    }
    status = (await step.json()) as JobStatus;
  }
  throw new Error("forged import unexpectedly completed");
});

test("import rejects same-size production variant bytes that do not match their digest", async () => {
  const sourceSpace = "spc_variant_digest_src";
  const sourceVersion = "ver_variant_digest_1";
  const productionVariant = "<h1>live-a</h1>\n";
  const tamperedVariant = "<h1>live-b</h1>\n";
  expect(Buffer.byteLength(tamperedVariant)).toBe(Buffer.byteLength(productionVariant));

  const finalize = await finalizeRaw(
    rt,
    sourceSpace,
    sourceVersion,
    { "index.html": "<h1>source</h1>\n" },
    {
      template_files: { "index.html": "<h1>base</h1>\n" },
      template_variants: { production: { "index.html": productionVariant } },
    },
  );
  expect(finalize.status).toBe(200);

  const archive = await runExport(sourceSpace);
  const entries = unzipSync(new Uint8Array(archive));
  const variantName = `spacefast_export_v1/versions/${sourceVersion}/files-variants/production/index.html`;
  expect(Buffer.from(entries[variantName] ?? []).toString("utf8")).toBe(productionVariant);
  entries[variantName] = new TextEncoder().encode(tamperedVariant);
  const craftedArchive = Buffer.from(zipSync(entries, { level: 6 }));

  const targetSpace = "spc_variant_digest_dst";
  const importId = await startImport(targetSpace);
  const uploaded = await uploadImportArchive(targetSpace, importId, craftedArchive);
  expect(uploaded.status).toBe(200);
  let status = (await uploaded.json()) as JobStatus;
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) throw new Error(`tampered variant import did not settle: ${status.status}`);
    const step = await api(
      rt,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: targetSpace, import_id: importId },
    );
    if (step.status !== 200) {
      expect(step.status).toBe(422);
      expect(await errorCode(step)).toBe("space_import_archive_invalid");
      return;
    }
    status = (await step.json()) as JobStatus;
  }
  throw new Error("tampered variant import unexpectedly completed");
});

test("zip-bomb archives are rejected by the compression-ratio guard", async () => {
  const bombPath = path.join(rt.root, "bomb.zip");
  buildZip(bombPath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      content: JSON.stringify({ format: "spacefast_export_v1", versions: ["ver_bomb"] }),
    },
    // 32 MiB of zeros compresses to a few KiB: far beyond the 200x ratio limit.
    { name: "spacefast_export_v1/versions/ver_bomb/files/big.bin", zeros: 32 * 1024 * 1024 },
  ]);

  const importId = await startImport("spc_bomb");
  const response = await uploadImportArchive("spc_bomb", importId, readFileSync(bombPath));
  expect(response.status).toBe(413);
  expect(await errorCode(response)).toBe("space_import_compression_ratio_exceeded");
});

test("imported PHP artifacts must be inert generated data", async () => {
  const evilPath = path.join(rt.root, "evil.zip");
  buildZip(evilPath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      content: JSON.stringify({ format: "spacefast_export_v1", versions: ["ver_evil"] }),
    },
    {
      name: "spacefast_export_v1/versions/ver_evil/serving.php",
      content: "<?php\nreturn shell_exec('id');\n",
    },
  ]);

  const importId = await startImport("spc_evil");
  const response = await uploadImportArchive("spc_evil", importId, readFileSync(evilPath));
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("space_import_php_artifact_invalid");
});

test("archives with unexpected entries or wrong format are rejected", async () => {
  const strayPath = path.join(rt.root, "stray.zip");
  buildZip(strayPath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      content: JSON.stringify({ format: "spacefast_export_v1", versions: [] }),
    },
    { name: "spacefast_export_v1/outside.txt", content: "stray" },
  ]);
  const strayImport = await startImport("spc_stray");
  const stray = await uploadImportArchive("spc_stray", strayImport, readFileSync(strayPath));
  expect(stray.status).toBe(422);
  expect(await errorCode(stray)).toBe("space_import_archive_invalid");

  const wrongFormatPath = path.join(rt.root, "wrong-format.zip");
  buildZip(wrongFormatPath, [
    { name: "spacefast_export_v1/spacefast.json", content: JSON.stringify({ format: "v0" }) },
  ]);
  const wrongImport = await startImport("spc_wrongfmt");
  const wrong = await uploadImportArchive(
    "spc_wrongfmt",
    wrongImport,
    readFileSync(wrongFormatPath),
  );
  expect(wrong.status).toBe(422);
  expect(await errorCode(wrong)).toBe("space_import_archive_invalid");
});

test("archives with versions missing from the manifest are rejected", async () => {
  const unlistedPath = path.join(rt.root, "unlisted.zip");
  buildZip(unlistedPath, [
    {
      name: "spacefast_export_v1/spacefast.json",
      content: JSON.stringify({ format: "spacefast_export_v1", versions: [] }),
    },
    { name: "spacefast_export_v1/versions/ver_ghost/files/index.html", content: "<h1>x</h1>" },
  ]);
  const importId = await startImport("spc_unlisted");
  const response = await uploadImportArchive("spc_unlisted", importId, readFileSync(unlistedPath));
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("space_import_archive_invalid");
});

test("legacy stattic_export_v1 archives are rejected", async () => {
  // Pre-rename archives (root dir `stattic_export_v1/`, `stattic.json`
  // descriptor) are no longer importable: the runtime accepts exactly one
  // layout. Both the honest legacy format id and a legacy tree claiming the
  // current id must fail with the standard invalid-archive rejection.
  const claims = ["stattic_export_v1", "spacefast_export_v1"];
  for (const [index, claimedFormat] of claims.entries()) {
    const legacyPath = path.join(rt.root, `legacy-${index}.zip`);
    buildZip(legacyPath, [
      {
        name: "stattic_export_v1/stattic.json",
        content: JSON.stringify({ format: claimedFormat, versions: [VERSION] }),
      },
      { name: `stattic_export_v1/versions/${VERSION}/files/index.html`, content: INDEX },
      {
        name: "stattic_export_v1/space/access-policy.json",
        content: JSON.stringify({ policy: null }),
      },
    ]);
    const targetSpace = `spc_legacy_${index}`;
    const importId = await startImport(targetSpace);
    const response = await uploadImportArchive(targetSpace, importId, readFileSync(legacyPath));
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe("space_import_archive_invalid");
  }
});

// G1 (plan §9 / decision I-4): importing a Zero-endpoint version must never
// let it dispatch live execution on a shared box. The engine's own fail-safe
// (import defaults zero_mode to "activating" unless the control plane
// explicitly authorizes "active") is exercised directly here, at the
// archive/install seam — the control-plane pre-gate that decides which value
// to send is covered separately in
// apps/control-plane/src/archives/import-materialize.test.ts.
// Zero-bearing imports rebind their executable graph through the native
// compiler; the two Zero import tests provision the real binary. Built once,
// lazily, so the many non-Zero tests in this file never pay for cargo.
let cachedCompilerPath: string | null = null;
function builtCompilerPath(): string {
  if (cachedCompilerPath) return cachedCompilerPath;
  const repoRoot = path.resolve(import.meta.dir, "../..");
  const build = Bun.spawnSync({
    cmd: ["cargo", "build", "--locked", "-p", "stattic-runtime-compiler"],
    cwd: repoRoot,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stderr.toString()}`);
  }
  cachedCompilerPath = path.join(repoRoot, "target/debug/stattic-runtime-compiler");
  return cachedCompilerPath;
}

function startFakeZeroRunner(): { runnerPath: string; stop: () => void } {
  const runnerRoot = mkdtempSync(path.join(os.tmpdir(), "stattic-zero-import-runner-"));
  const runnerPath = path.join(runnerRoot, "fake-zero-runner.php");
  const phpPath = Bun.which("php") ?? "/usr/bin/php";
  writeFileSync(
    runnerPath,
    `#!${phpPath}
<?php
if (($argv[1] ?? '') === 'compile') {
    $source = $argv[2] ?? '';
    $bytecode = $argv[3] ?? '';
    $generated = $argv[5] ?? $source;
    if ($source === '' || $bytecode === '' || !is_file($source)) {
        fwrite(STDERR, "compile args invalid");
        exit(2);
    }
    file_put_contents($generated, file_get_contents($source));
    file_put_contents($bytecode, "fake-bytecode");
    exit(0);
}
echo json_encode(['status' => 201, 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}']);
`,
  );
  chmodSync(runnerPath, 0o755);
  return { runnerPath, stop: () => {} };
}

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
    if (steps > 20) {
      throw new Error(`export did not complete: ${status.status}`);
    }
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

async function startAndUploadImport(
  runtime: Runtime,
  spaceId: string,
  archive: Buffer,
  body: Record<string, unknown> = {},
): Promise<{ status: string; version_ids?: string[] }> {
  const started = await apiJson<{ import_id?: string; status: string }>(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/imports`,
    "start_space_import",
    { space_id: spaceId },
    { install_access_policy: true, ...body },
    201,
  );
  const importId = started.import_id ?? "";
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
  let status = (await uploaded.json()) as { status: string; version_ids?: string[] };
  for (let steps = 0; status.status !== "complete"; steps += 1) {
    if (steps > 20) {
      throw new Error(`import did not complete: ${status.status}`);
    }
    status = await apiJson(
      runtime,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: spaceId, import_id: importId },
    );
  }
  return status;
}

function phpArtifact(entries: Record<string, Uint8Array>, name: string): Record<string, unknown> {
  const bytes = entries[name];
  if (!bytes) throw new Error(`missing PHP artifact: ${name}`);
  const decoded = Bun.spawnSync(
    [
      PHP_BINARY,
      "-r",
      [
        "$source = stream_get_contents(STDIN);",
        "if (!is_string($source) || !str_starts_with($source, '<?php')) { exit(2); }",
        "$artifact = eval(substr($source, 5));",
        "echo json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);",
      ].join(" "),
    ],
    { stdin: Buffer.from(bytes) },
  );
  if (decoded.exitCode !== 0) {
    throw new Error(`could not decode PHP artifact ${name}: ${decoded.stderr.toString()}`);
  }
  return objectValue(JSON.parse(decoded.stdout.toString()), name);
}

function replacePhpArtifact(
  entries: Record<string, Uint8Array>,
  name: string,
  artifact: Record<string, unknown>,
): void {
  const encoded = Bun.spawnSync(
    [
      PHP_BINARY,
      "-r",
      [
        "$artifact = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);",
        `echo "<?php\\nreturn " . var_export($artifact, true) . ";\\n";`,
      ].join(" "),
    ],
    { stdin: Buffer.from(JSON.stringify(artifact)) },
  );
  if (encoded.exitCode !== 0) {
    throw new Error(`could not encode PHP artifact ${name}: ${encoded.stderr.toString()}`);
  }
  entries[name] = new Uint8Array(encoded.stdout);
}

function objectValue(value: unknown, label: string): Record<string, unknown> {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new Error(`${label} must be an object`);
  }
  return value as Record<string, unknown>;
}

function arrayValue(value: unknown, label: string): unknown[] {
  if (!Array.isArray(value)) throw new Error(`${label} must be an array`);
  return value;
}

function stringValue(value: unknown, label: string): string {
  if (typeof value !== "string" || value === "") throw new Error(`${label} must be a string`);
  return value;
}

function zeroRouteEntries(routes: Record<string, unknown>): Array<Record<string, unknown>> {
  const entries = [
    ...arrayValue(routes.exact, "zero routes exact"),
    ...arrayValue(routes.fallback, "zero routes fallback"),
  ];
  for (const bucket of Object.values(
    objectValue(routes.by_first_segment, "zero route segment buckets"),
  )) {
    entries.push(...arrayValue(bucket, "zero route segment bucket"));
  }
  return entries.map((entry, index) => objectValue(entry, `zero route ${index}`));
}

async function expectImportedArtifactRejection(
  runtime: Runtime,
  spaceId: string,
  archive: Buffer,
  artifact: string,
): Promise<void> {
  const importId = await startImport(spaceId, { zero_mode: "active" }, runtime);
  const uploaded = await uploadImportArchive(spaceId, importId, archive, runtime);
  if (uploaded.status !== 200) {
    throw new Error(
      `tampered Zero artifact upload -> ${uploaded.status}: ${await uploaded.text()}`,
    );
  }
  let status = (await uploaded.json()) as JobStatus;
  for (let steps = 0; steps <= 20; steps += 1) {
    if (status.status === "complete") {
      throw new Error("tampered Zero artifact import unexpectedly completed");
    }
    const step = await api(
      runtime,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: spaceId, import_id: importId },
    );
    if (step.status !== 200) {
      expect(step.status).toBe(422);
      expect(await step.json()).toMatchObject({
        error: {
          code: "runtime_artifact_validation_failed",
          details: { artifact },
        },
      });
      return;
    }
    status = (await step.json()) as JobStatus;
  }
  throw new Error("tampered Zero artifact import did not settle");
}

test("import rejects one endpoint id mapped to different Zero route artifacts", async () => {
  const runner = startFakeZeroRunner();
  const zeroRt = await startRuntime({ env: { SPACEFAST_ZERO_RUNNER: runner.runnerPath } });
  try {
    const sourceSpace = "spc_zero_duplicate_id_source";
    const sourceVersion = "ver_zero_duplicate_id_source_1";
    const sourceHost = "zero-duplicate-id-source.test";
    const firstId = "endpoint.first";
    const secondId = "endpoint.second";
    await deploy(zeroRt, {
      spaceId: sourceSpace,
      versionId: sourceVersion,
      zeroMode: "active",
      files: { "index.html": "<h1>duplicate id source</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/first",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: firstId,
          },
          {
            method: "GET",
            path: "/api/items/:id",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: secondId,
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [sourceHost],
        version_hostnames: [],
      },
    });

    const getResponse = await get(zeroRt, sourceHost, "/api/first");
    expect(getResponse.status).toBe(201);
    const headResponse = await get(zeroRt, sourceHost, "/api/first", { method: "HEAD" });
    expect(headResponse.status).toBe(201);
    expect(await headResponse.text()).toBe("");

    const archive = await exportSpace(zeroRt, sourceSpace);
    const entries = unzipSync(new Uint8Array(archive)) as Record<string, Uint8Array>;
    const prefix = `spacefast_export_v1/versions/${sourceVersion}/`;
    const indexName = `${prefix}zero/endpoints-index.json`;
    const index = objectValue(
      JSON.parse(Buffer.from(entries[indexName] ?? []).toString("utf8")),
      "Zero endpoint index",
    );
    const endpoints = objectValue(index.endpoints, "Zero endpoint mappings");
    const secondArtifactPath = stringValue(endpoints[secondId], "second endpoint artifact");
    delete endpoints[secondId];
    endpoints[firstId] = secondArtifactPath;
    entries[indexName] = new TextEncoder().encode(JSON.stringify(index));

    const secondArtifactName = prefix + secondArtifactPath;
    const secondArtifact = objectValue(
      JSON.parse(Buffer.from(entries[secondArtifactName] ?? []).toString("utf8")),
      "second Zero endpoint artifact",
    );
    secondArtifact.endpointId = firstId;
    entries[secondArtifactName] = new TextEncoder().encode(JSON.stringify(secondArtifact));

    const routesName = `${prefix}zero/routes.php`;
    const routes = phpArtifact(entries, routesName);
    const secondRoute = zeroRouteEntries(routes).find((entry) => entry.endpoint_id === secondId);
    if (!secondRoute) throw new Error("missing second Zero route");
    secondRoute.endpoint_id = firstId;
    replacePhpArtifact(entries, routesName, routes);

    const manifestName = `${prefix}php-manifest.php`;
    const manifest = phpArtifact(entries, manifestName);
    const manifestRoute = arrayValue(manifest.routes, "PHP manifest routes")
      .map((entry, routeIndex) => objectValue(entry, `PHP manifest route ${routeIndex}`))
      .find((entry) => entry.endpointId === secondId);
    if (!manifestRoute) throw new Error("missing second PHP manifest route");
    manifestRoute.endpointId = firstId;
    replacePhpArtifact(entries, manifestName, manifest);

    await expectImportedArtifactRejection(
      zeroRt,
      "spc_zero_duplicate_id_target",
      Buffer.from(zipSync(entries, { level: 6 })),
      "zero_endpoint_index",
    );
  } finally {
    zeroRt.stop();
    runner.stop();
  }
});

test("import rejects matcher-equivalent Zero routes in either declaration order", async () => {
  const runner = startFakeZeroRunner();
  const zeroRt = await startRuntime({ env: { SPACEFAST_ZERO_RUNNER: runner.runnerPath } });
  try {
    const sourceSpace = "spc_zero_route_conflict_source";
    const sourceVersion = "ver_zero_route_conflict_source_1";
    const firstId = "endpoint.pattern.first";
    const secondId = "endpoint.pattern.second";
    await deploy(zeroRt, {
      spaceId: sourceSpace,
      versionId: sourceVersion,
      zeroMode: "active",
      files: { "index.html": "<h1>route conflict source</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/:left/alpha",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: firstId,
          },
          {
            method: "GET",
            path: "/api/:right/bravo",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: secondId,
          },
        ],
      },
    });

    const archive = await exportSpace(zeroRt, sourceSpace);
    const prefix = `spacefast_export_v1/versions/${sourceVersion}/`;
    for (const reversed of [false, true]) {
      const entries = unzipSync(new Uint8Array(archive)) as Record<string, Uint8Array>;
      const indexName = `${prefix}zero/endpoints-index.json`;
      const index = objectValue(
        JSON.parse(Buffer.from(entries[indexName] ?? []).toString("utf8")),
        "Zero endpoint index",
      );
      const endpoints = objectValue(index.endpoints, "Zero endpoint mappings");
      const secondArtifactPath = stringValue(endpoints[secondId], "second endpoint artifact");

      const routesName = `${prefix}zero/routes.php`;
      const routes = phpArtifact(entries, routesName);
      const routeBuckets = objectValue(routes.by_first_segment, "Zero route segment buckets");
      const routeBucket = arrayValue(routeBuckets.api, "api Zero route bucket");
      const secondRoute = routeBucket
        .map((entry, routeIndex) => objectValue(entry, `Zero route ${routeIndex}`))
        .find((entry) => entry.endpoint_id === secondId);
      if (!secondRoute) throw new Error("missing second Zero route");
      secondRoute.pattern = "/api/:right/alpha";
      if (reversed) routeBuckets.api = routeBucket.toReversed();
      replacePhpArtifact(entries, routesName, routes);

      const manifestName = `${prefix}php-manifest.php`;
      const manifest = phpArtifact(entries, manifestName);
      const manifestRoutes = arrayValue(manifest.routes, "PHP manifest routes");
      const staticRoutes = manifestRoutes.filter(
        (entry, routeIndex) =>
          objectValue(entry, `PHP manifest route ${routeIndex}`).action !== "invoke_zero",
      );
      const endpointRoutes = manifestRoutes
        .filter(
          (entry, routeIndex) =>
            objectValue(entry, `PHP manifest route ${routeIndex}`).action === "invoke_zero",
        )
        .map((entry, routeIndex) => objectValue(entry, `Zero manifest route ${routeIndex}`));
      const secondManifestRoute = endpointRoutes.find((entry) => entry.endpointId === secondId);
      if (!secondManifestRoute) throw new Error("missing second PHP manifest route");
      secondManifestRoute.pattern = "/api/:right/alpha";
      manifest.routes = [
        ...staticRoutes,
        ...(reversed ? endpointRoutes.toReversed() : endpointRoutes),
      ];
      replacePhpArtifact(entries, manifestName, manifest);

      const secondArtifactName = prefix + secondArtifactPath;
      const secondArtifact = objectValue(
        JSON.parse(Buffer.from(entries[secondArtifactName] ?? []).toString("utf8")),
        "second Zero endpoint artifact",
      );
      secondArtifact.path = "/api/:right/alpha";
      entries[secondArtifactName] = new TextEncoder().encode(JSON.stringify(secondArtifact));

      await expectImportedArtifactRejection(
        zeroRt,
        `spc_zero_route_conflict_${reversed ? "reversed" : "forward"}`,
        Buffer.from(zipSync(entries, { level: 6 })),
        "zero_endpoint_index",
      );
    }
  } finally {
    zeroRt.stop();
    runner.stop();
  }
});

test("PHP-manifest-only Zero artifacts are confined and cross-validated before serving", async () => {
  const runner = startFakeZeroRunner();
  const zeroRt = await startRuntime({ env: { SPACEFAST_ZERO_RUNNER: runner.runnerPath } });
  try {
    const sourceSpace = "spc_manifest_only_source";
    const sourceVersion = "ver_manifest_only_source_1";
    const sourceHost = "manifest-only-source.test";
    await deploy(zeroRt, {
      spaceId: sourceSpace,
      versionId: sourceVersion,
      zeroMode: "active",
      files: { "index.html": "<h1>manifest-only source</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/manifest-only",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/manifest-only",
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [sourceHost],
        version_hostnames: [],
      },
    });
    const sourceResponse = await get(zeroRt, sourceHost, "/api/manifest-only");
    expect(sourceResponse.status).toBe(201);

    const exportedArchive = await exportSpace(zeroRt, sourceSpace);
    const entries = unzipSync(new Uint8Array(exportedArchive));
    const versionPrefix = `spacefast_export_v1/versions/${sourceVersion}/`;
    const phpManifestName = `${versionPrefix}php-manifest.php`;
    const manifestSource = Buffer.from(entries[phpManifestName] ?? []).toString("utf8");
    const zeroArtifactMatch = manifestSource.match(/'zeroArtifact'\s*=>\s*'([^']+\.json)'/);
    expect(zeroArtifactMatch?.[1]).toStartWith("zero/endpoints/");
    const originalArtifactPath = zeroArtifactMatch?.[1] ?? "";
    const originalArtifact = JSON.parse(
      Buffer.from(entries[versionPrefix + originalArtifactPath] ?? []).toString("utf8"),
    ) as Record<string, unknown>;
    const oversizedArtifact = new TextEncoder().encode(
      JSON.stringify({
        ...originalArtifact,
        padding: randomBytes(2_359_296).toString("base64"),
      }),
    );
    expect(oversizedArtifact.byteLength).toBeGreaterThan(3 * 1024 * 1024);

    const publicArtifactPath = "files/large.json";
    entries[versionPrefix + publicArtifactPath] = oversizedArtifact;
    const tamperedManifest = manifestSource.replace(
      zeroArtifactMatch?.[0] ?? "",
      (zeroArtifactMatch?.[0] ?? "").replace(originalArtifactPath, publicArtifactPath),
    );
    expect(tamperedManifest).not.toBe(manifestSource);
    entries[phpManifestName] = new TextEncoder().encode(tamperedManifest);
    const tamperedArchive = Buffer.from(zipSync(entries, { level: 6 }));

    const targetSpace = "spc_manifest_only_target";
    const importId = await startImport(targetSpace, { zero_mode: "active" }, zeroRt);
    const uploaded = await uploadImportArchive(targetSpace, importId, tamperedArchive, zeroRt);
    // Intake accepts ordinary files at the general file cap. The cross-artifact
    // invariant is enforced by the real final validation step below.
    expect(uploaded.status).toBe(200);

    const rejected = await api(
      zeroRt,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: targetSpace, import_id: importId },
    );
    expect(rejected.status).toBe(422);
    expect(await rejected.json()).toMatchObject({
      error: {
        code: "runtime_artifact_validation_failed",
        details: { artifact: "php_manifest", path: publicArtifactPath },
      },
    });
    expect(
      existsSync(path.join(zeroRt.storageRoot, "spaces", targetSpace, "versions", sourceVersion)),
    ).toBe(false);

    const route = await putRoute(
      zeroRt,
      targetSpace,
      "production",
      {
        version_id: sourceVersion,
        config: { mode: "website" },
        production_hostnames: ["manifest-only-target.test"],
        version_hostnames: [],
      },
      404,
    );
    expect(await errorCode(route)).toBe("version_not_found");
    expect((await get(zeroRt, "manifest-only-target.test", "/api/manifest-only")).status).toBe(503);

    // A path inside zero/endpoints is not enough by itself: a PHP-manifest-only
    // reference joins the same endpoint metadata/route cross-check as serving.php
    // and zero/routes.php.
    const referenceEntries = unzipSync(new Uint8Array(exportedArchive));
    const phpOnlyArtifactPath = "zero/endpoints/import/php-manifest-only.json";
    referenceEntries[versionPrefix + phpOnlyArtifactPath] = new TextEncoder().encode(
      JSON.stringify({ ...originalArtifact, path: "/api/not-manifest-only" }),
    );
    referenceEntries[phpManifestName] = new TextEncoder().encode(
      manifestSource.replace(
        zeroArtifactMatch?.[0] ?? "",
        (zeroArtifactMatch?.[0] ?? "").replace(originalArtifactPath, phpOnlyArtifactPath),
      ),
    );
    const mismatchSpace = "spc_manifest_only_mismatch";
    const mismatchImport = await startImport(mismatchSpace, { zero_mode: "active" }, zeroRt);
    const mismatchUploaded = await uploadImportArchive(
      mismatchSpace,
      mismatchImport,
      Buffer.from(zipSync(referenceEntries, { level: 6 })),
      zeroRt,
    );
    expect(mismatchUploaded.status).toBe(200);
    const mismatchRejected = await api(
      zeroRt,
      "POST",
      `/__spacefast/api.php/imports/${mismatchImport}/step`,
      "step_space_import",
      { space_id: mismatchSpace, import_id: mismatchImport },
    );
    expect(mismatchRejected.status).toBe(422);
    expect(await mismatchRejected.json()).toMatchObject({
      error: {
        code: "runtime_artifact_validation_failed",
        details: { artifact: "zero_endpoint", path: phpOnlyArtifactPath },
      },
    });
  } finally {
    zeroRt.stop();
    runner.stop();
  }
});

test("importing a Zero-endpoint archive without zero_mode installs the engine's fail-safe activating stub (no invoke_zero on the shared box), static routes still 200", async () => {
  const runner = startFakeZeroRunner();
  const zeroRt = await startRuntime({
    env: {
      SPACEFAST_ZERO_RUNNER: runner.runnerPath,
      SPACEFAST_RUNTIME_FINALIZER_BIN: builtCompilerPath(),
    },
  });
  try {
    const sourceSpace = "spc_zero_import_source";
    const sourceVersion = "ver_zero_import_source_1";
    await deploy(zeroRt, {
      spaceId: sourceSpace,
      versionId: sourceVersion,
      zeroMode: "active",
      files: { "index.html": "<h1>zero import</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/exact",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/exact",
          },
          {
            method: "GET",
            path: "/api/items/:id",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/items/:id",
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: ["zero-import-source.test"],
        version_hostnames: [],
      },
    });
    const archive = await exportSpace(zeroRt, sourceSpace);

    // No zero_mode in the start body: the engine's own fail-safe must land
    // on "activating" — the control plane authorizing "active" is a
    // SEPARATE, explicit act (import-materialize.test.ts covers that gate).
    const targetSpace = "spc_zero_import_activating";
    const imported = await startAndUploadImport(zeroRt, targetSpace, archive);
    expect(imported.status).toBe("complete");
    const versionId = imported.version_ids?.[0] ?? "";

    const host = "zero-import-activating.test";
    await putRoute(zeroRt, targetSpace, "production", {
      version_id: versionId,
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    });

    const exactZero = await get(zeroRt, host, "/api/exact");
    expect(exactZero.status).toBe(503);
    expect(await errorCode(exactZero)).toBe("zero_activating");

    const patternZero = await get(zeroRt, host, "/api/items/42");
    expect(patternZero.status).toBe(503);
    expect(await errorCode(patternZero)).toBe("zero_activating");

    const staticPage = await get(zeroRt, host, "/");
    expect(staticPage.status).toBe(200);
    expect(await staticPage.text()).toBe("<h1>zero import</h1>\n");

    // Explicit zero_mode:"active" (the shape the control plane sends once its
    // own gate proves the target is dedicated+active) installs live dispatch.
    const activeSpace = "spc_zero_import_active";
    const importedActive = await startAndUploadImport(zeroRt, activeSpace, archive, {
      zero_mode: "active",
    });
    expect(importedActive.status).toBe("complete");
    const activeVersionId = importedActive.version_ids?.[0] ?? "";
    const activeHost = "zero-import-active.test";
    await putRoute(zeroRt, activeSpace, "production", {
      version_id: activeVersionId,
      config: { mode: "website" },
      production_hostnames: [activeHost],
      version_hostnames: [],
    });
    const liveZero = await get(zeroRt, activeHost, "/api/exact");
    expect(liveZero.status).toBe(201);
    expect(await liveZero.json()).toEqual({ ok: true });
    const livePatternZero = await get(zeroRt, activeHost, "/api/items/42");
    expect(livePatternZero.status).toBe(201);
  } finally {
    zeroRt.stop();
    runner.stop();
  }
});

test("a tampered archive that empties zero/endpoints-index.json but keeps live invoke_zero dispatch is rejected by the rebind before anything installs", async () => {
  const runner = startFakeZeroRunner();
  const zeroRt = await startRuntime({
    env: {
      SPACEFAST_ZERO_RUNNER: runner.runnerPath,
      SPACEFAST_RUNTIME_FINALIZER_BIN: builtCompilerPath(),
    },
  });
  try {
    const sourceSpace = "spc_zero_tamper_source";
    await deploy(zeroRt, {
      spaceId: sourceSpace,
      versionId: "ver_zero_tamper_source_1",
      zeroMode: "active",
      files: { "index.html": "<h1>tampered zero import</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/exact",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/exact",
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: ["zero-tamper-source.test"],
        version_hostnames: [],
      },
    });
    const archive = await exportSpace(zeroRt, sourceSpace);

    // Tamper: drop every zero/endpoints-index.json from the archive while
    // leaving the invoke_zero dispatch in serving.php / php-manifest.php /
    // zero/routes.php intact. A malicious or truncated export can do exactly
    // this (a genuinely endpoint-free version simply ships no index file), so
    // the install-time neutralization must not trust the index's endpoint count.
    const entries = unzipSync(new Uint8Array(archive));
    let dropped = 0;
    for (const name of Object.keys(entries)) {
      if (name.endsWith("/zero/endpoints-index.json")) {
        delete entries[name];
        dropped += 1;
      }
    }
    expect(dropped).toBeGreaterThan(0);
    const tampered = Buffer.from(zipSync(entries, { level: 6 }));

    const targetSpace = "spc_zero_tamper_rejected";
    // Live invoke_zero dispatch with no endpoint index cannot be rebuilt for
    // the target space; the rebind refuses the archive rather than carrying
    // or stubbing another space's executable graph.
    const failed = await runZeroImportUntilError(zeroRt, targetSpace, tampered);
    expect(failed.status).toBe(422);
    expect(await errorCode(failed)).toBe("space_import_rebind_failed");
    expect(existsSync(path.join(zeroRt.storageRoot, "spaces", targetSpace, "versions"))).toBe(
      false,
    );
  } finally {
    zeroRt.stop();
    runner.stop();
  }
});

async function runZeroImportUntilError(
  runtime: Runtime,
  spaceId: string,
  archive: Buffer,
): Promise<Response> {
  const started = await apiJson<{ import_id?: string; status: string }>(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/imports`,
    "start_space_import",
    { space_id: spaceId },
    {},
    201,
  );
  const importId = started.import_id ?? "";
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
  for (let steps = 0; steps <= 20; steps += 1) {
    const step = await api(
      runtime,
      "POST",
      `/__spacefast/api.php/imports/${importId}/step`,
      "step_space_import",
      { space_id: spaceId, import_id: importId },
    );
    if (step.status !== 200) {
      return step;
    }
    const status = (await step.json()) as { status: string };
    if (status.status === "complete") {
      throw new Error("import unexpectedly completed");
    }
  }
  throw new Error("import did not settle in an error");
}
