// Local CAS collection preserves declared, pinned, and retained commerce bytes.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import { runtimeStorageObjectSchema } from "@spacefast/common/contracts/runtime-storage";

import {
  managementToken,
  runtimeHttpPath,
  apiJson,
  createDeclaredSession,
  deploy,
  get,
  errorCode,
  journalRecords,
  publicAccessConfig,
  putBlob,
  readBlob,
  responseEntry,
  responseTableFiles,
  sha256,
  startRuntime,
  storagePath,
  versionRoot,
  type Runtime,
} from "./harness.ts";

type JobRecord = {
  id: string;
  space_id: string | null;
  operation_id: string;
  status: string;
  result?: unknown;
  progress?: { done: number; total: number };
};

type TickResponse = { job: JobRecord | null };

let rt: Runtime;
let seq = 0;

beforeAll(async () => {
  rt = await startRuntime({
    env: {
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
      SPACEFAST_LOCAL_BLOB_GC_UNDECLARED_MIN_GRACE_SECONDS: "0",
    },
  });
});

afterAll(() => rt?.stop());

function nextId(prefix: string): string {
  seq += 1;
  return `${prefix}_${seq}`;
}

function hostFor(spaceId: string): string {
  return `${spaceId.replaceAll("_", "-")}.test`;
}

/** The blob a compiled entry names — the only reference the serve path follows. */
function entrySha(runtime: Runtime, spaceId: string, versionId: string, key: string): string {
  return z.string().parse(responseEntry(runtime, spaceId, versionId, key)?.b);
}

/** One tick of ONE signed job: the engine has no lane-wide tick. */
async function tickJob(runtime: Runtime, job: JobRecord, budgetMs = 50000): Promise<TickResponse> {
  return apiJson<TickResponse>(
    runtime,
    "POST",
    `/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=${budgetMs}`,
    "tick_engine_jobs",
    // The whole immutable scope admission stamped: a tick is a capability for
    // one job, under the one operation that asked for it.
    { job_id: job.id, space_id: job.space_id, operation_id: job.operation_id },
    {},
  );
}

/**
 * The box-wide housekeeping pass: retention, blob GC, the disk report. Every
 * hook runs in ONE stepper call, so a pass either completes in a single tick or
 * yields with work it could not finish — `complete` when it swept, `pending`
 * when a hook was skipped or threw.
 */
async function maintenancePass(runtime: Runtime, spaceId: string): Promise<JobRecord> {
  const { job } = await apiJson<{ job: JobRecord }>(
    runtime,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    {
      space_id: spaceId,
      operation_id: `op_maintenance_${spaceId}`,
      job_scope: {
        type: "maintenance_tick",
        idempotency_key: `maintenance_tick:${spaceId}:${nextId("idem")}`,
        payload: "{}",
      },
    },
    {},
    201,
  );
  const response = await tickJob(runtime, job);
  if (response.job?.id !== job.id) {
    throw new Error(`maintenance pass ${job.id} was not the job the tick reported`);
  }
  return response.job;
}

async function deployFixture(
  runtime: Runtime,
  spaceId: string,
  versionId: string,
  host: string,
  files: Record<string, string>,
): Promise<void> {
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "index.html": "<html><body>cas</body></html>", ...files },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}

test("a publish session in flight keeps its declared bytes out of the GC's reach", async () => {
  // D92: the bytes of a version that does not exist yet are named by nothing the
  // GC's live-set walk would otherwise find. Without the session's declaration
  // and its pin, a maintenance pass landing mid-publish collects them and
  // finalize fails on bytes the uploader already delivered.
  const spaceId = nextId("spc_cas_pin");
  const versionId = nextId("ver_cas_pin");
  const content = "pinned-bytes\n".repeat(3000);
  const sha = sha256(content);

  const session = await createDeclaredSession(rt, spaceId, versionId, {
    "assets/pinned.txt": content,
  });
  const put = await putBlob(rt, spaceId, session.token, sha, content);
  expect(put.status).toBe(200);
  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(content);

  await maintenancePass(rt, spaceId);

  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(content);
  expect(
    journalRecords(rt).some(
      (e) => e.event === "local_blob_gc" && (e.collected ?? []).includes(sha),
    ),
  ).toBe(false);
});

test("the blob GC deletes nothing when a live response table cannot be read", async () => {
  const spaceId = nextId("spc_cas_unreadable_table");
  const versionId = nextId("ver_cas_unreadable_table");
  const host = hostFor(spaceId);
  const body = "still-live-through-the-table\n".repeat(1500);
  await deployFixture(rt, spaceId, versionId, host, { "assets/live.txt": body });
  const sha = entrySha(rt, spaceId, versionId, "/assets/live.txt");
  const tableName = Object.values(responseTableFiles(rt, spaceId, versionId))[0];
  if (tableName === undefined) throw new Error("version response table missing");
  const tablePath = path.join(versionRoot(rt, spaceId, versionId), tableName);
  const renderCache = path.join(versionRoot(rt, spaceId, versionId), "..", "zero-render-cache");
  mkdirSync(renderCache, { recursive: true });
  writeFileSync(path.join(renderCache, "cached-image.webp"), "derived render cache");

  chmodSync(tablePath, 0o000);
  try {
    // A GC that could not read the live set collected nothing, so the pass says
    // it is unfinished and stays resumable rather than completing on a sweep it
    // never made.
    const blocked = await maintenancePass(rt, spaceId);
    expect(blocked.status).toBe("pending");
    // SAFETY: the maintenance stepper persists its pass report on every step.
    expect(blocked.result).toMatchObject({ complete: false });
    expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(body);
    expect(
      journalRecords(rt).some(
        (event) => event.event === "local_blob_gc" && (event.collected ?? []).includes(sha),
      ),
    ).toBe(false);
  } finally {
    chmodSync(tablePath, 0o644);
  }

  const recovered = await maintenancePass(rt, spaceId);
  expect(recovered.status).toBe("complete");
  expect(readFileSync(path.join(renderCache, "cached-image.webp"), "utf8")).toBe(
    "derived render cache",
  );
  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(body);
});

test("targeted version deletion makes its exclusive blobs collectable and leaves the live version serving", async () => {
  const spaceId = nextId("spc_cas_delete");
  const versionOld = nextId("ver_cas_delete_old");
  const versionLive = nextId("ver_cas_delete_live");
  const host = hostFor(spaceId);
  const oldBody = "only-in-the-old-version\n".repeat(1500);
  // A private path: it answers no URL, so no response table names its bytes and
  // the version's catalog is the ONLY thing keeping them alive. The sweep is
  // shape-agnostic by design (cas.php `_stattic_cas_collect_shas`), and this
  // is what proves it still reaches a source-only object.
  const privateBody = "never served, still ours\n";
  const privateSha = sha256(privateBody);
  await deployFixture(rt, spaceId, versionOld, host, { "assets/old.txt": oldBody });
  await deployFixture(rt, spaceId, versionLive, host, {
    "assets/live.txt": "still routed",
    ".hidden/notes.txt": privateBody,
  });
  const paidBody = "purchased edition independent of deployments";
  const uploaded = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/storage/commerce`)}`,
    {
      method: "POST",
      headers: {
        "content-type": "text/plain",
        authorization: `Bearer ${managementToken("storage_upload_commerce", { space_id: spaceId, storage_uploader_id: "fixture-owner" })}`,
      },
      body: paidBody,
    },
  );
  expect(uploaded.status).toBe(201);
  const paid = runtimeStorageObjectSchema.parse(await uploaded.json());
  expect(
    await apiJson(
      rt,
      "POST",
      `/__spacefast/api.php/spaces/${spaceId}/storage/commerce/${paid.id}/retain`,
      "storage_retain_commerce",
      { space_id: spaceId, storage_object_id: paid.id },
      { sha256: paid.sha256 },
    ),
  ).toEqual({ id: paid.id, sha256: paid.sha256, retained: true });
  // Remove the original upload declaration: the paid snapshot must be the only
  // remaining root for these bytes, and must also supply the download record.
  rmSync(storagePath(rt, "spaces", spaceId, "uploads"), { recursive: true, force: true });
  const oldSha = entrySha(rt, spaceId, versionOld, "/assets/old.txt");
  const liveSha = entrySha(rt, spaceId, versionLive, "/assets/live.txt");

  const deleted = await apiJson<{ space_id: string; version_id: string; status: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionOld}/delete`,
    "delete_version",
    { space_id: spaceId, version_id: versionOld },
  );
  expect(deleted).toMatchObject({
    space_id: spaceId,
    version_id: versionOld,
    status: "deleted",
  });

  await maintenancePass(rt, spaceId);

  expect(existsSync(versionRoot(rt, spaceId, versionOld))).toBe(false);
  expect(existsSync(versionRoot(rt, spaceId, versionLive))).toBe(true);

  // The deleted version was the only declaration naming these bytes, so the
  // maintenance pass collects them. The routed version and its private source
  // object remain declared and serving.
  expect(readBlob(rt, spaceId, oldSha)).toBeNull();
  expect(readBlob(rt, spaceId, paid.sha256)?.toString("utf8")).toBe(paidBody);
  const paidDownload = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/storage/commerce/${paid.id}`)}`,
    {
      headers: {
        authorization: `Bearer ${managementToken("storage_read_commerce", { space_id: spaceId, storage_object_id: paid.id })}`,
      },
    },
  );
  expect(paidDownload.status).toBe(200);
  expect(await paidDownload.text()).toBe(paidBody);
  const deletePaidSpace = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/delete`)}`,
    {
      method: "POST",
      headers: {
        authorization: `Bearer ${managementToken("delete_space", { space_id: spaceId })}`,
      },
    },
  );
  expect(deletePaidSpace.status).toBe(409);
  expect(await errorCode(deletePaidSpace)).toBe("storage_object_retained");

  const collected = journalRecords(rt).find(
    (e) => e.event === "local_blob_gc" && (e.collected ?? []).includes(oldSha),
  );
  expect(collected).toBeDefined();
  expect(readBlob(rt, spaceId, liveSha)?.toString("utf8")).toBe("still routed");
  expect(readBlob(rt, spaceId, privateSha)?.toString("utf8")).toBe(privateBody);
  const served = await get(rt, host, "/assets/live.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("still routed");

  const report = journalRecords(rt).find(
    (e) => e.event === "space.disk.report" && e.spaceId === spaceId,
  );
  expect(Number(report?.inodes)).toBeGreaterThan(0);
});
