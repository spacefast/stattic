// Local CAS collection preserves declared, pinned, and retained commerce bytes.
import { afterAll, beforeAll, expect, test } from "bun:test";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  utimesSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

import { z } from "zod";

import { runtimeStorageObjectSchema } from "@spacefast/common/contracts/runtime-storage";

import {
  managementToken,
  runtimeHttpPath,
  apiJson,
  blobPath,
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

/**
 * A box of its own whose live-set scan yields after every declaration it
 * reads. The maintenance pass is box-wide, so each checkpoint test gets a fresh
 * box: a Space left behind by an earlier test would be rescanned on every tick.
 */
async function withSteppedRuntime(run: (stepped: Runtime) => Promise<void>): Promise<void> {
  const stepped = await startRuntime({
    env: {
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
      SPACEFAST_LOCAL_BLOB_GC_UNDECLARED_MIN_GRACE_SECONDS: "0",
      SPACEFAST_LOCAL_BLOB_GC_LIVE_SET_STEP_LIMIT: "1",
    },
  });
  try {
    await run(stepped);
  } finally {
    stepped.stop();
  }
}

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

/** Drive one pass to completion, reporting how many ticks it took. */
async function completeMaintenance(
  runtime: Runtime,
  job: JobRecord,
  onTick?: () => void | Promise<void>,
): Promise<{ job: JobRecord; ticks: number }> {
  let record = job;
  let ticks = 0;
  while (record.status !== "complete") {
    ticks += 1;
    if (ticks > 40)
      throw new Error(`maintenance pass ${job.id} did not converge in ${ticks} ticks`);
    record = await tickMaintenance(runtime, record);
    await onTick?.();
  }
  return { job: record, ticks };
}

function liveSetCheckpointPath(runtime: Runtime, spaceId: string): string {
  return storagePath(runtime, "spaces", spaceId, "gc", "live-set.json");
}

/** The undeclared blobs the last pass found, keyed by sha (cas.php gc/marks.json). */
function gcMarks(runtime: Runtime, spaceId: string): Record<string, number> {
  const file = storagePath(runtime, "spaces", spaceId, "gc", "marks.json");
  return existsSync(file)
    ? z.record(z.string(), z.number()).parse(JSON.parse(readFileSync(file, "utf8")))
    : {};
}

const liveSetCheckpointSchema = z.object({
  version: z.number(),
  phase: z.enum(["versions", "declarations", "complete"]),
  cursor: z.string().nullable(),
  completed_at: z.number().nullable(),
  delta_since: z.number().nullable(),
  fold_started_at: z.number().nullable(),
  fold_seen: z.record(z.string(), z.number()),
});

function liveSetCheckpoint(runtime: Runtime, spaceId: string) {
  return liveSetCheckpointSchema.parse(
    JSON.parse(readFileSync(liveSetCheckpointPath(runtime, spaceId), "utf8")),
  );
}

function plantOrphanBlob(runtime: Runtime, spaceId: string, body: string): string {
  const sha = sha256(body);
  mkdirSync(path.dirname(blobPath(runtime, spaceId, sha)), { recursive: true });
  writeFileSync(blobPath(runtime, spaceId, sha), body);
  return sha;
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
    // The scan kept what it had and its cursor stays before the unreadable
    // version, so the next pass retries that one, not the whole Space.
    expect(liveSetCheckpoint(rt, spaceId)).toMatchObject({ phase: "versions", cursor: null });
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

test("the hold blocks targeted purges and preserves unreferenced blobs while live content still serves", async () => {
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

  const deleted = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/versions/${versionOld}/delete`)}`,
    {
      method: "POST",
      headers: {
        authorization: `Bearer ${managementToken("delete_version", { space_id: spaceId, version_id: versionOld })}`,
      },
    },
  );
  expect(deleted.status).toBe(503);
  expect(await errorCode(deleted)).toBe("evidence_retention_hold");
  // Remove only the test declaration so the collector faces genuinely orphaned bytes.
  rmSync(versionRoot(rt, spaceId, versionOld), { recursive: true, force: true });

  await maintenancePass(rt, spaceId);

  expect(existsSync(versionRoot(rt, spaceId, versionOld))).toBe(false);
  expect(existsSync(versionRoot(rt, spaceId, versionLive))).toBe(true);

  // The deleted version was the only declaration naming these bytes, so the
  // maintenance pass collects them. The routed version and its private source
  // object remain declared and serving.
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
  expect(deletePaidSpace.status).toBe(503);
  expect(await errorCode(deletePaidSpace)).toBe("evidence_retention_hold");

  const collected = journalRecords(rt).find(
    (e) => e.event === "local_blob_gc" && (e.collected ?? []).includes(oldSha),
  );
  expect(collected).toBeUndefined();
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

test("the live-set scan checkpoints across ticks instead of restarting on every one", () =>
  withSteppedRuntime(async (stepped) => {
    // The dashboard Space's maintenance pass timed out on every run for three
    // weeks: its live-set walk could not finish inside one tick's budget, and
    // every tick started it over. A walk that yields now resumes from where it
    // stopped, and the pass converges.
    const spaceId = nextId("spc_cas_ckpt");
    const host = hostFor(spaceId);
    const versions = [nextId("ver_cas_ckpt_a"), nextId("ver_cas_ckpt_b"), nextId("ver_cas_ckpt_c")];
    for (const versionId of versions) {
      await deployFixture(stepped, spaceId, versionId, host, {
        [`assets/${versionId}.txt`]: `${versionId}\n`.repeat(200),
      });
    }
    const liveShas = versions.map((versionId) =>
      entrySha(stepped, spaceId, versionId, `/assets/${versionId}.txt`),
    );
    const orphanSha = plantOrphanBlob(stepped, spaceId, "declared by nothing\n".repeat(100));
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);
    // A sibling Space on the same box whose scan also spans ticks. The run
    // completes only when every Space's pass completes in the same tick, so a
    // Space whose pass completed must keep its scan until then.
    const siblingId = nextId("spc_cas_ckpt_sibling");
    for (const versionId of [nextId("ver_cas_sib_a"), nextId("ver_cas_sib_b")]) {
      await deployFixture(stepped, siblingId, versionId, hostFor(siblingId), {
        [`assets/${versionId}.txt`]: `${versionId}\n`.repeat(200),
      });
    }

    let checkpointsSeen = 0;
    const { ticks } = await completeMaintenance(
      stepped,
      await createMaintenanceJob(stepped, spaceId),
      () => {
        if (!existsSync(checkpoint)) return;
        checkpointsSeen += 1;
        expect(liveSetCheckpoint(stepped, spaceId).version).toBe(1);
      },
    );

    // One declaration per tick: three versions plus their retained session records.
    expect(ticks).toBeGreaterThan(versions.length);
    expect(checkpointsSeen).toBeGreaterThan(0);
    // The run that walked every prefix against the scan discards it.
    expect(existsSync(checkpoint)).toBe(false);
    expect(existsSync(liveSetCheckpointPath(stepped, siblingId))).toBe(false);
    const events = journalRecords(stepped).filter((event) => event.space_id === spaceId);
    expect(
      events.filter((event) => event.event === "blob_gc_live_set_yielded").length,
    ).toBeGreaterThan(0);
    expect(events.find((event) => event.event === "blob_gc_live_set_complete")).toMatchObject({
      versions: versions.length,
    });
    // The decision the pass reached: the orphan is a candidate, the declared bytes are not.
    const marks = gcMarks(stepped, spaceId);
    expect(marks[orphanSha]).toBeDefined();
    for (const [index, sha] of liveShas.entries()) {
      expect(marks[sha]).toBeUndefined();
      expect(readBlob(stepped, spaceId, sha)?.toString("utf8")).toBe(
        `${versions[index]}\n`.repeat(200),
      );
    }
  }));

test("declarations made after the live-set scan began are folded into the pass that uses it", () =>
  withSteppedRuntime(async (stepped) => {
    // The scan is a checkpoint of the past; the pass folds in what was declared
    // since, so a publish that lands mid-scan is never read as orphaned. Three
    // ways to land mid-scan, each past the point the resumed scan would look:
    // a version sorting before the cursor, a version root the scan already read
    // being rewritten, and a declaration record under a root already walked.
    const spaceId = nextId("spc_cas_delta");
    const host = hostFor(spaceId);
    const middle = nextId("ver_cas_delta_m");
    const last = nextId("ver_cas_delta_z");
    await deployFixture(stepped, spaceId, middle, host, {
      "assets/middle.txt": "middle\n".repeat(200),
    });
    await deployFixture(stepped, spaceId, last, host, { "assets/last.txt": "last\n".repeat(200) });
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);

    // One tick: the scan reads the first version and yields with its cursor on it.
    let job = await tickMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));
    expect(job.status).toBe("pending");
    expect(liveSetCheckpoint(stepped, spaceId)).toMatchObject({
      phase: "versions",
      cursor: middle,
    });

    // A version that sorts BEFORE the cursor: the resumed scan never visits it.
    const early = nextId("ver_cas_delta_a");
    await deployFixture(stepped, spaceId, early, host, {
      "assets/early.txt": "early\n".repeat(200),
    });
    const earlySha = entrySha(stepped, spaceId, early, "/assets/early.txt");
    // A root the scan already read, rewritten with a table naming new bytes.
    const lateTableSha = plantOrphanBlob(stepped, spaceId, "named by a late table\n".repeat(100));
    writeFileSync(
      path.join(versionRoot(stepped, spaceId, middle), "responses-late.php"),
      `<?php return ['${lateTableSha}'];\n`,
    );
    const orphanSha = plantOrphanBlob(stepped, spaceId, "orphaned mid-scan\n".repeat(100));

    // Tick until the scan has walked past the uploads root, then declare under it.
    let lateRecordSha: string | null = null;
    ({ job } = await completeMaintenance(stepped, job, () => {
      if (lateRecordSha !== null || !existsSync(checkpoint)) return;
      const state = liveSetCheckpoint(stepped, spaceId);
      if (state.phase !== "declarations" || !state.cursor?.startsWith("1:")) return;
      lateRecordSha = plantOrphanBlob(stepped, spaceId, "named by a late record\n".repeat(100));
      const uploads = storagePath(stepped, "spaces", spaceId, "uploads");
      mkdirSync(uploads, { recursive: true });
      writeFileSync(
        path.join(uploads, "late.json"),
        JSON.stringify({ sha256: lateRecordSha, created_at: new Date().toISOString() }),
      );
    }));
    expect(job.status).toBe("complete");
    expect(lateRecordSha).not.toBeNull();
    // One declaration per tick applies to the fold too: it yielded and resumed.
    expect(
      journalRecords(stepped).some(
        (event) =>
          event.event === "blob_gc_live_set_yielded" &&
          event.space_id === spaceId &&
          event.phase === "fold",
      ),
    ).toBe(true);
    // The scan itself finished with the two versions it started from...
    expect(
      journalRecords(stepped).find(
        (event) => event.event === "blob_gc_live_set_complete" && event.space_id === spaceId,
      ),
    ).toMatchObject({ versions: 2 });
    // ...and the pass still decided against every late declaration.
    const marks = gcMarks(stepped, spaceId);
    expect(marks[orphanSha]).toBeDefined();
    expect(marks[earlySha]).toBeUndefined();
    expect(marks[lateTableSha]).toBeUndefined();
    expect(marks[z.string().parse(lateRecordSha)]).toBeUndefined();
    expect(readBlob(stepped, spaceId, earlySha)?.toString("utf8")).toBe("early\n".repeat(200));
    expect(existsSync(checkpoint)).toBe(false);
  }));

test("the next run scans afresh, so bytes orphaned after a run become candidates", () =>
  withSteppedRuntime(async (stepped) => {
    // A checkpoint serves one box-wide run. Were it kept, what retention
    // removed after the scan would stay in the live set and never be collected.
    const spaceId = nextId("spc_cas_fresh");
    const host = hostFor(spaceId);
    const keep = nextId("ver_cas_fresh_keep");
    const drop = nextId("ver_cas_fresh_drop");
    await deployFixture(stepped, spaceId, keep, host, { "assets/keep.txt": "keep\n".repeat(200) });
    await deployFixture(stepped, spaceId, drop, host, { "assets/drop.txt": "drop\n".repeat(200) });
    const keepSha = entrySha(stepped, spaceId, keep, "/assets/keep.txt");
    const dropSha = entrySha(stepped, spaceId, drop, "/assets/drop.txt");

    await completeMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));
    expect(gcMarks(stepped, spaceId)[dropSha]).toBeUndefined();

    // Retire the version the way retention would. Its publish-session record
    // is retained under the evidence hold and still names the bytes, so it goes
    // too: only then is nothing left declaring them.
    rmSync(versionRoot(stepped, spaceId, drop), { recursive: true, force: true });
    rmSync(storagePath(stepped, "spaces", spaceId, "publish-sessions"), {
      recursive: true,
      force: true,
    });
    await completeMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));

    const scans = journalRecords(stepped).filter(
      (event) => event.event === "blob_gc_live_set_complete" && event.space_id === spaceId,
    );
    expect(scans.map((event) => event.versions)).toEqual([2, 1]);
    const marks = gcMarks(stepped, spaceId);
    expect(marks[dropSha]).toBeDefined();
    expect(marks[keepSha]).toBeUndefined();
  }));

test("a completed scan past its age bound is discarded rather than served stale", () =>
  withSteppedRuntime(async (stepped) => {
    const spaceId = nextId("spc_cas_stale");
    const host = hostFor(spaceId);
    const versionId = nextId("ver_cas_stale");
    await deployFixture(stepped, spaceId, versionId, host, {
      "assets/live.txt": "live\n".repeat(200),
    });
    const liveSha = entrySha(stepped, spaceId, versionId, "/assets/live.txt");
    // A scan that completed long ago and names nothing: served as is, it would
    // read every live blob as undeclared.
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);
    mkdirSync(path.dirname(checkpoint), { recursive: true });
    writeFileSync(
      checkpoint,
      JSON.stringify({
        version: 1,
        started_at: 1,
        completed_at: 2,
        delta_since: 0,
        phase: "complete",
        cursor: null,
        fold_started_at: null,
        fold_seen: {},
        shas: {},
        version_ids: {},
      }),
    );

    await completeMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));

    const events = journalRecords(stepped).filter((event) => event.space_id === spaceId);
    expect(events.find((event) => event.event === "blob_gc_live_set_expired")).toMatchObject({
      completed_at: 2,
    });
    expect(events.find((event) => event.event === "blob_gc_live_set_complete")).toMatchObject({
      versions: 1,
    });
    expect(gcMarks(stepped, spaceId)[liveSha]).toBeUndefined();
    expect(readBlob(stepped, spaceId, liveSha)?.toString("utf8")).toBe("live\n".repeat(200));
  }));

test("a fold that resumes across ticks is saved and reads what it has not seen", () =>
  withSteppedRuntime(async (stepped) => {
    // A fold the budget cut short resumes from its ledger. Two things must
    // hold: a resumed fold that finds nothing left still saves its cleared
    // ledger (else the stale ledger is resumed forever), and a version
    // published meanwhile is in the answer the pass decides against.
    const spaceId = nextId("spc_cas_resume");
    const host = hostFor(spaceId);
    const middle = nextId("ver_cas_resume_m");
    await deployFixture(stepped, spaceId, middle, host, {
      "assets/middle.txt": "middle\n".repeat(200),
    });
    // A second version means two session records: the scan yields between
    // them, past the uploads root, which is when the test declares under it.
    await deployFixture(stepped, spaceId, nextId("ver_cas_resume_o"), host, {
      "assets/other.txt": "other\n".repeat(200),
    });
    const uploads = storagePath(stepped, "spaces", spaceId, "uploads");
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);
    const declare = (name: string, body: string): string => {
      const sha = plantOrphanBlob(stepped, spaceId, body);
      mkdirSync(uploads, { recursive: true });
      writeFileSync(
        path.join(uploads, name),
        JSON.stringify({ sha256: sha, created_at: new Date().toISOString() }),
      );
      return sha;
    };

    let stage: "scanning" | "folding" | "resumed" = "scanning";
    let firstSha: string | null = null;
    let earlySha: string | null = null;
    const orphanSha = plantOrphanBlob(stepped, spaceId, "orphan\n".repeat(100));
    const { job } = await completeMaintenance(
      stepped,
      await createMaintenanceJob(stepped, spaceId),
      async () => {
        if (!existsSync(checkpoint)) return;
        const state = liveSetCheckpoint(stepped, spaceId);
        if (
          stage === "scanning" &&
          state.phase === "declarations" &&
          state.cursor?.startsWith("1:")
        ) {
          // The scan has walked past the uploads root: two records declared
          // under it now are the next fold's work, one per tick.
          firstSha = declare("first.json", "first record\n".repeat(100));
          declare("second.json", "second record\n".repeat(100));
          stage = "folding";
          return;
        }
        if (stage === "folding" && "1:0:first.json" in state.fold_seen) {
          // The fold yielded after the first record. Before it resumes, the
          // second record is gone and a version sorting before it lands.
          rmSync(path.join(uploads, "second.json"));
          const early = nextId("ver_cas_resume_a");
          await deployFixture(stepped, spaceId, early, host, {
            "assets/early.txt": "early\n".repeat(200),
          });
          earlySha = entrySha(stepped, spaceId, early, "/assets/early.txt");
          stage = "resumed";
          return;
        }
        if (stage === "resumed" && state.fold_started_at === null) {
          // A finished fold's ledger is not left on disk to be resumed again.
          expect(state.fold_seen).toEqual({});
        }
      },
    );
    expect(job.status).toBe("complete");
    expect(stage).toBe("resumed");
    const marks = gcMarks(stepped, spaceId);
    expect(marks[orphanSha]).toBeDefined();
    expect(marks[z.string().parse(firstSha)]).toBeUndefined();
    expect(marks[z.string().parse(earlySha)]).toBeUndefined();
  }));

test("an unreadable declaration leaves the scan and the fold waiting on that one file", () =>
  withSteppedRuntime(async (stepped) => {
    // A declaration that cannot be read makes the live set unknowable, so the
    // pass stays unfinished, but neither the scan nor the fold starts over:
    // the checkpoint keeps what was read and retries the one file.
    const spaceId = nextId("spc_cas_unreadable");
    const host = hostFor(spaceId);
    const first = nextId("ver_cas_unreadable_a");
    const second = nextId("ver_cas_unreadable_b");
    await deployFixture(stepped, spaceId, first, host, {
      "assets/first.txt": "first\n".repeat(200),
    });
    await deployFixture(stepped, spaceId, second, host, {
      "assets/second.txt": "second\n".repeat(200),
    });
    const secondSha = entrySha(stepped, spaceId, second, "/assets/second.txt");
    const secondTable = path.join(
      versionRoot(stepped, spaceId, second),
      z.string().parse(Object.values(responseTableFiles(stepped, spaceId, second))[0]),
    );
    const uploads = storagePath(stepped, "spaces", spaceId, "uploads");
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);
    const yieldSchema = z.object({
      phase: z.string().optional(),
      reason: z.string().optional(),
      versions: z.number().optional(),
    });
    const yields = () =>
      journalRecords(stepped)
        .filter((event) => event.event === "blob_gc_live_set_yielded" && event.space_id === spaceId)
        .map((event) => yieldSchema.parse(event));

    // Scan: the first version is read, the second cannot be.
    chmodSync(secondTable, 0o000);
    let job = await tickMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));
    try {
      job = await tickMaintenance(stepped, job);
      expect(job.status).toBe("pending");
      expect(liveSetCheckpoint(stepped, spaceId)).toMatchObject({
        phase: "versions",
        cursor: first,
      });
      expect(yields().at(-1)).toMatchObject({
        phase: "versions",
        reason: "unreadable",
        versions: 1,
      });
    } finally {
      chmodSync(secondTable, 0o644);
    }

    // Fold: once the scan is past the uploads root, declare a record there
    // that cannot be read, then let it be read.
    let recordSha: string | null = null;
    let recordPath: string | null = null;
    let foldBlocked = false;
    ({ job } = await completeMaintenance(stepped, job, () => {
      if (!existsSync(checkpoint)) return;
      const state = liveSetCheckpoint(stepped, spaceId);
      if (recordSha === null && state.phase === "declarations" && state.cursor?.startsWith("1:")) {
        recordSha = plantOrphanBlob(stepped, spaceId, "behind a closed door\n".repeat(100));
        mkdirSync(uploads, { recursive: true });
        recordPath = path.join(uploads, "closed.json");
        writeFileSync(
          recordPath,
          JSON.stringify({ sha256: recordSha, created_at: new Date().toISOString() }),
        );
        chmodSync(recordPath, 0o000);
        return;
      }
      const last = yields().at(-1);
      if (
        recordPath !== null &&
        !foldBlocked &&
        last?.phase === "fold" &&
        last.reason === "unreadable"
      ) {
        // The fold kept its ledger and will retry the one file it could not read.
        expect(state.fold_started_at).not.toBeNull();
        expect("1:0:closed.json" in state.fold_seen).toBe(false);
        foldBlocked = true;
        chmodSync(recordPath, 0o644);
      }
    }));
    expect(job.status).toBe("complete");
    expect(foldBlocked).toBe(true);
    // The scan never re-read the first version, and both declarations count.
    expect(
      journalRecords(stepped).find(
        (event) => event.event === "blob_gc_live_set_complete" && event.space_id === spaceId,
      ),
    ).toMatchObject({ versions: 2 });
    const marks = gcMarks(stepped, spaceId);
    expect(marks[secondSha]).toBeUndefined();
    expect(marks[z.string().parse(recordSha)]).toBeUndefined();
  }));

test("a resumed fold that reads nothing still saves its finished state", () =>
  withSteppedRuntime(async (stepped) => {
    // The zero-fold path: the fold yields after one of two records, the other
    // record vanishes, and the resumed fold walks to the end reading nothing.
    // Its cleared ledger and advanced watermark must still reach disk, or the
    // stale ledger is resumed on every later tick.
    const spaceId = nextId("spc_cas_zero_fold");
    const host = hostFor(spaceId);
    for (const versionId of [nextId("ver_cas_zero_fold_a"), nextId("ver_cas_zero_fold_b")]) {
      await deployFixture(stepped, spaceId, versionId, host, {
        [`assets/${versionId}.txt`]: `${versionId}\n`.repeat(200),
      });
    }
    const uploads = storagePath(stepped, "spaces", spaceId, "uploads");
    const checkpoint = liveSetCheckpointPath(stepped, spaceId);
    // A first pass settles what the engine writes lazily beside a version
    // (catalog sidecars). Everything that exists before the second pass is
    // then dated outside the fold's one-second slack, so nothing is due in
    // that pass's fold but what this test declares.
    await completeMaintenance(stepped, await createMaintenanceJob(stepped, spaceId));
    const past = new Date(Date.now() - 60_000);
    for (const root of ["versions", "publish-sessions"]) {
      for (const name of readdirSync(storagePath(stepped, "spaces", spaceId, root))) {
        utimesSync(storagePath(stepped, "spaces", spaceId, root, name), past, past);
      }
    }
    // A sibling Space whose scan outlasts this test keeps the box-wide run
    // open, so this Space's checkpoint is still on disk to inspect after its
    // own pass completes. Without a save, disk would still show the stale fold.
    const siblingId = nextId("spc_cas_zero_fold_sibling");
    for (let index = 0; index < 6; index += 1) {
      const versionId = nextId("ver_cas_zero_fold_sib");
      await deployFixture(stepped, siblingId, versionId, hostFor(siblingId), {
        [`assets/${versionId}.txt`]: `${versionId}\n`.repeat(200),
      });
    }
    let stage: "scanning" | "folding" | "resumed" | "checked" = "scanning";
    let foldStartedAt: number | null = null;
    const { job } = await completeMaintenance(
      stepped,
      await createMaintenanceJob(stepped, spaceId),
      () => {
        if (!existsSync(checkpoint)) return;
        const state = liveSetCheckpoint(stepped, spaceId);
        if (
          stage === "scanning" &&
          state.phase === "declarations" &&
          state.cursor?.startsWith("1:")
        ) {
          mkdirSync(uploads, { recursive: true });
          for (const name of ["first.json", "second.json"]) {
            const sha = plantOrphanBlob(stepped, spaceId, `${name}\n`.repeat(100));
            writeFileSync(
              path.join(uploads, name),
              JSON.stringify({ sha256: sha, created_at: new Date().toISOString() }),
            );
          }
          stage = "folding";
          return;
        }
        if (stage === "folding" && "1:0:first.json" in state.fold_seen) {
          foldStartedAt = state.fold_started_at;
          rmSync(path.join(uploads, "second.json"));
          stage = "resumed";
          return;
        }
        if (stage === "resumed") {
          // The resumed fold read nothing and finished: disk says so.
          expect(state.fold_started_at).toBeNull();
          expect(state.fold_seen).toEqual({});
          expect(state.delta_since).toBe(z.number().parse(foldStartedAt) - 1);
          stage = "checked";
        }
      },
    );
    expect(job.status).toBe("complete");
    expect(stage).toBe("checked");
  }));
