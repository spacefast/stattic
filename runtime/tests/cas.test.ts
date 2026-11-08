// The CAS keeps every byte until an explicit delete: maintenance never collects.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, rmSync } from "node:fs";

import { z } from "zod";

import { runtimeStorageObjectSchema } from "@spacefast/common/contracts/runtime-storage";

import {
  api,
  managementToken,
  runtimeHttpPath,
  apiJson,
  deploy,
  get,
  errorCode,
  journalRecords,
  publicAccessConfig,
  readBlob,
  responseEntry,
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
  rt = await startRuntime();
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

/** The box-wide housekeeping pass, in one tick. */
async function maintenancePass(runtime: Runtime, spaceId: string): Promise<JobRecord> {
  return await tickMaintenance(runtime, await createMaintenanceJob(runtime, spaceId));
}

async function createMaintenanceJob(runtime: Runtime, spaceId: string): Promise<JobRecord> {
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
  return job;
}

async function tickMaintenance(runtime: Runtime, job: JobRecord): Promise<JobRecord> {
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

test("a retired version keeps its tree and maintenance keeps every byte", async () => {
  const spaceId = nextId("spc_cas_delete");
  const versionOld = nextId("ver_cas_delete_old");
  const versionLive = nextId("ver_cas_delete_live");
  const host = hostFor(spaceId);
  const oldBody = "only-in-the-old-version\n".repeat(1500);
  // A private path: it answers no URL, so no response table names its bytes.
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

  // Retiring the old version only tombstones its hostname; its tree stays.
  const tombstoned = await api(
    rt,
    "PUT",
    `/__spacefast/api.php/spaces/${spaceId}/tombstones`,
    "update_tombstones",
    { space_id: spaceId },
    { mode: "add", hostnames: [`old.${host}`], reason: "version_deleted" },
  );
  expect(tombstoned.status).toBe(200);
  await tombstoned.arrayBuffer();
  expect(existsSync(versionRoot(rt, spaceId, versionOld))).toBe(true);
  expect(existsSync(versionRoot(rt, spaceId, versionLive))).toBe(true);

  // A finished maintenance pass deletes nothing on its own: the bytes only the
  // deleted version named stay in the CAS beside the routed version's.
  expect((await maintenancePass(rt, spaceId)).status).toBe("complete");
  expect(readBlob(rt, spaceId, oldSha)?.toString("utf8")).toBe(oldBody);
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
  // An explicit Space delete still refuses while purchased files are retained.
  expect(deletePaidSpace.status).toBe(409);
  expect(await errorCode(deletePaidSpace)).toBe("storage_object_retained");

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
