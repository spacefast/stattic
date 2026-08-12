// Engine job runner (contracts §10, D54/D55): admin/jobs.php's tick driver,
// dispatched over POST /jobs/tick and inspected over GET /jobs/{jobId}.
//
// v4 collapsed the framework to ONE registered maintenance tick and ONE demote
// operation, both on the bulk lane, so this file tests the RUNNER — claim,
// heartbeat/reap, dead-letter, single-flight, housekeeping — and nothing about
// what a particular job does. `maintenance_tick` is the cheap stand-in for "a
// job that runs"; `tier_demote`'s own multi-step/cursor behavior belongs to
// tiering.test.ts, which drives it across ticks against the S3 fake.
//
// Delivery is pull-only (D53): the journal is the only sink, so lifecycle events
// are asserted where they are written — runtime/journal.jsonl — not against a
// callback origin. The drain route that hands those records back is
// callback-drain.test.ts's.
//
// Some fixtures seed job records directly onto disk (the record-store layout
// under runtime/jobs/{queue,dead}/). That is deliberate and minimal: claim
// ordering needs two jobs with distinct created_at, and created_at has
// one-second resolution, so the create route cannot produce the state; likewise
// an orphaned `running` record and an aged dead-letter have no public producer.
import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, utimesSync, writeFileSync } from "node:fs";
import path from "node:path";

import { api, apiJson, journalRecords, startRuntime, storagePath, type Runtime } from "./harness";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => {
  rt?.stop();
});

// Jobs are seeded onto disk, so a leftover pending job from a prior test would
// otherwise be claimable by the next test's tick.
beforeEach(() => {
  rmSync(storagePath(rt, "runtime", "jobs"), { recursive: true, force: true });
});

type JobRecord = {
  id: string;
  type: string;
  lane: string;
  space_id: string | null;
  operation_id: string | null;
  idempotency_key: string;
  status: string;
  attempt: number;
  max_attempts: number;
  first_failed_at: string | null;
  not_before: string | null;
  heartbeat: number | null;
  cursor: Record<string, unknown>;
  progress: { done: number; total: number };
  payload: Record<string, unknown>;
  result: unknown;
  error: { code: string; message: string } | null;
  created_at: string;
  updated_at: string;
};

let jobSeq = 0;

function queueDir(): string {
  return storagePath(rt, "runtime", "jobs", "queue");
}

function deadDir(): string {
  return storagePath(rt, "runtime", "jobs", "dead");
}

function jobPath(jobId: string): string {
  return path.join(queueDir(), `${jobId}.json`);
}

function deadPath(jobId: string): string {
  return path.join(deadDir(), `${jobId}.json`);
}

function jobRecord(overrides: Partial<JobRecord> & { type: string }): JobRecord {
  jobSeq += 1;
  const id = overrides.id ?? `job_seed${jobSeq}${Math.random().toString(16).slice(2, 8)}`;
  // Stable ascending created_at: the runner claims the lexicographically
  // smallest one, and gmdate('c') alone is only second-resolution.
  const createdAt = new Date(Date.now() - (1000 - jobSeq)).toISOString();
  return {
    id,
    type: overrides.type,
    lane: overrides.lane ?? "bulk",
    space_id: overrides.space_id ?? "spc_job_runner_test",
    operation_id: overrides.operation_id ?? `op_${id}`,
    idempotency_key: overrides.idempotency_key ?? `idem_${id}`,
    status: overrides.status ?? "pending",
    attempt: overrides.attempt ?? 0,
    max_attempts: overrides.max_attempts ?? 5,
    first_failed_at: overrides.first_failed_at ?? null,
    not_before: overrides.not_before ?? null,
    heartbeat: overrides.heartbeat ?? null,
    cursor: overrides.cursor ?? {},
    progress: overrides.progress ?? { done: 0, total: 0 },
    payload: overrides.payload ?? {},
    result: overrides.result ?? null,
    error: overrides.error ?? null,
    created_at: overrides.created_at ?? createdAt,
    updated_at: overrides.updated_at ?? createdAt,
  };
}

function seedJob(overrides: Partial<JobRecord> & { type: string }): JobRecord {
  const record = jobRecord(overrides);
  mkdirSync(queueDir(), { recursive: true });
  writeFileSync(jobPath(record.id), JSON.stringify(record));
  return record;
}

function seedDeadJob(overrides: Partial<JobRecord> & { type: string }): JobRecord {
  const record = jobRecord({ status: "failed", ...overrides });
  mkdirSync(deadDir(), { recursive: true });
  writeFileSync(deadPath(record.id), JSON.stringify(record));
  return record;
}

function readJob(jobId: string): JobRecord {
  const queued = jobPath(jobId);
  const source = existsSync(queued) ? queued : deadPath(jobId);
  return JSON.parse(readFileSync(source, "utf8")) as JobRecord;
}

function writeJob(record: JobRecord): void {
  writeFileSync(jobPath(record.id), JSON.stringify(record));
}

function backdate(target: string, ageSeconds: number): void {
  const at = new Date(Date.now() - ageSeconds * 1000);
  utimesSync(target, at, at);
}

type TickResponse = {
  lane: string;
  job: JobRecord | null;
  drained: boolean;
  execution_timeout_seconds?: number | null;
  tick_status: string;
};

async function tick(budgetMs: number, jobId?: string): Promise<TickResponse> {
  const query = new URLSearchParams({ lane: "bulk", budget_ms: String(budgetMs) });
  if (jobId !== undefined) {
    query.set("job_id", jobId);
  }
  return apiJson(rt, "POST", `/__spacefast/api.php/jobs/tick?${query}`, "tick_engine_jobs", {});
}

function journalFor(jobId: string): Array<Record<string, unknown>> {
  return journalRecords(rt).filter((entry) => entry.job_id === jobId);
}

test("a bulk tick claims the oldest pending job, runs it, and bounds its own execution", async () => {
  const older = seedJob({ type: "maintenance_tick" });
  const newer = seedJob({ type: "maintenance_tick" });

  const first = await tick(2500);
  expect(first.job?.id).toBe(older.id);
  expect(first.tick_status).toBe("complete");
  expect(first.job?.status).toBe("complete");
  // The lane lock is an OS flock, so a wedged worker would pin the lane forever:
  // the tick bounds itself past its cooperative budget (2.5s + 30s margin) so
  // PHP tears the worker down and the heartbeat reaper recovers.
  expect(first.execution_timeout_seconds).toBe(33);

  // The claimed job really ran its stepper: the housekeeping pass reports the
  // steps it completed as the job result.
  const completed = readJob(older.id);
  expect(completed.status).toBe("complete");
  expect(completed.heartbeat).toBeGreaterThan(0);
  expect((completed.result as { steps: string[] }).steps).toContain("retention");
  expect(readJob(newer.id).status).toBe("pending");

  const second = await tick(2500);
  expect(second.job?.id).toBe(newer.id);
  expect(second.tick_status).toBe("complete");
});

test("a targeted tick advances the requested job and leaves an older pending one alone", async () => {
  const older = seedJob({ type: "maintenance_tick" });
  const selected = seedJob({ type: "maintenance_tick" });

  const result = await tick(2500, selected.id);

  expect(result.job?.id).toBe(selected.id);
  expect(result.job?.status).toBe("complete");
  expect(readJob(older.id).status).toBe("pending");
});

test("an unrunnable job dead-letters, leaves the queue, and is announced on the journal", async () => {
  // A record whose type is not in the registry (D54 deleted tier_promote,
  // tier_cancel, blob_report and the transfer jobs, and a queue outlives a
  // deploy) is fatal on its first step, not retried.
  const job = seedJob({ type: "tier_promote", max_attempts: 5 });

  const result = await tick(2500);
  expect(result.tick_status).toBe("dead_letter");
  expect(result.job?.status).toBe("failed");
  expect(existsSync(jobPath(job.id))).toBe(false);

  const dead = readJob(job.id);
  expect(dead.status).toBe("failed");
  expect(dead.attempt).toBe(1);
  expect(dead.error).toEqual({ code: "unknown_job_type", message: "unknown_job_type" });

  // Pull-only delivery (D53): the failure reaches the control plane as journal
  // records, in the wire shape its drain parses — job_type/lane/nested error.
  const entries = journalFor(job.id);
  expect(entries.map((entry) => entry.event)).toContain("job_dead_lettered");
  const failed = entries.find((entry) => entry.event === "job_failed");
  expect(failed).toBeDefined();
  expect(failed?.job_type).toBe("tier_promote");
  expect(failed?.lane).toBe("bulk");
  expect(failed?.error).toEqual({ code: "unknown_job_type", message: "unknown_job_type" });
  expect(failed?.operation_id).toBe(job.operation_id);

  // A dead-lettered job stays inspectable: the control plane reads the terminal
  // state from the same route it polls while the job is live.
  const response = await api(rt, "GET", `/__spacefast/api.php/jobs/${job.id}`, "get_engine_job", {
    job_id: job.id,
  });
  expect(response.status).toBe(200);
  expect(((await response.json()) as { job: JobRecord }).job.status).toBe("failed");
});

test("reap requeues an orphaned running job past the heartbeat timeout, and it runs next tick", async () => {
  const job = seedJob({
    type: "maintenance_tick",
    status: "running",
    heartbeat: Math.floor(Date.now() / 1000) - 200, // past the 120s timeout
  });

  // The tick's own reap pass discovers the orphan. Nothing is claimable
  // afterwards — the reaped job's backoff pushed not_before into the future —
  // so the tick itself reports idle.
  const reaped = await tick(1000);
  expect(reaped.tick_status).toBe("idle");
  expect(reaped.job).toBeNull();

  const record = readJob(job.id);
  expect(record.status).toBe("pending");
  expect(record.attempt).toBe(1);
  expect(record.heartbeat).toBeNull();
  expect(record.first_failed_at).not.toBeNull();
  expect(record.not_before).not.toBeNull();
  expect(record.error?.code).toBe("heartbeat_timeout");

  // Real backoff is ~10s; the transition and the clean resume are what is under
  // test, not the wall clock, so make the job eligible again instead of waiting.
  record.not_before = null;
  writeJob(record);

  const resumed = await tick(2500);
  expect(resumed.job?.id).toBe(job.id);
  expect(resumed.tick_status).toBe("complete");
  expect(readJob(job.id).status).toBe("complete");
});

test("a held lane lock makes a tick a no-op, so a job is never claimed twice", async () => {
  // Single flight is an OS flock taken try-once (§22 "held → exit 0"), so the
  // honest way to prove no double-claim is to hold that exact lock from another
  // process rather than race two ticks on wall-clock timing: the concurrent
  // tick provably overlaps, whatever the scheduler does.
  const job = seedJob({ type: "maintenance_tick" });
  const lockPath = storagePath(rt, "runtime", "jobs", "lane-bulk.lock");
  const markerPath = storagePath(
    rt,
    "runtime",
    "jobs",
    `holder-ready-${Math.random().toString(16).slice(2)}.marker`,
  );

  const holderScript = `
$fh = fopen($argv[1], 'c');
if ($fh === false || !flock($fh, LOCK_EX)) { fwrite(STDERR, "lock_failed\\n"); exit(1); }
file_put_contents($argv[2], 'ready');
usleep((int) $argv[3]);
flock($fh, LOCK_UN);
fclose($fh);
`;
  const holder = spawn("php", ["-r", holderScript, lockPath, markerPath, "1500000"]);

  const deadline = Date.now() + 5000;
  while (!existsSync(markerPath)) {
    if (Date.now() > deadline) {
      throw new Error("lock holder never signaled ready");
    }
    await new Promise((resolve) => setTimeout(resolve, 10));
  }

  const locked = await tick(2500);
  expect(locked.tick_status).toBe("lane_locked");
  expect(locked.job).toBeNull();
  // Untouched: a locked tick never claims, so it cannot have run housekeeping
  // or the job either.
  expect(readJob(job.id).status).toBe("pending");

  await new Promise((resolve) => holder.on("exit", resolve));

  const released = await tick(2500);
  expect(released.job?.id).toBe(job.id);
  expect(released.tick_status).toBe("complete");
});

test("POST /jobs takes job identity from the token claims, never the body, and upserts", async () => {
  const idempotencyKey = `idem-create-${Math.random().toString(16).slice(2)}`;
  const claims = { space_id: "spc_create_route", operation_id: "op_create_route" };

  const created = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    claims,
    {
      type: "maintenance_tick",
      payload: { note: "kept" },
      idempotency_key: idempotencyKey,
      // D54: identity comes from the verified token, so a body that claims a
      // different space (or operation) changes nothing about the record.
      space_id: "spc_body_lie",
      operation_id: "op_body_lie",
    },
    201,
  );
  expect(created.job.space_id).toBe("spc_create_route");
  expect(created.job.operation_id).toBe("op_create_route");
  expect(created.job.type).toBe("maintenance_tick");
  // Lane is derived from the type, never chosen by the caller.
  expect(created.job.lane).toBe("bulk");
  expect(created.job.status).toBe("pending");
  expect(created.job.payload.note).toBe("kept");

  // Upsert: the same idempotency_key returns the ORIGINAL job, not a new one,
  // even with a different payload on the second call.
  const again = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    claims,
    { type: "maintenance_tick", payload: { note: "ignored" }, idempotency_key: idempotencyKey },
    201,
  );
  expect(again.job.id).toBe(created.job.id);
  expect(again.job.payload.note).toBe("kept");

  // The created job is drivable through the real tick route end to end.
  const ran = await tick(2500);
  expect(ran.job?.id).toBe(created.job.id);
  expect(ran.job?.status).toBe("complete");
});

test("GET /jobs/{jobId} returns the record without the captured claims, 404 for unknown ids", async () => {
  const created = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: "spc_get_route", operation_id: "op_get_route" },
    { type: "maintenance_tick", payload: { note: "public" }, idempotency_key: "idem-get-route" },
    201,
  );

  const response = await api(
    rt,
    "GET",
    `/__spacefast/api.php/jobs/${created.job.id}`,
    "get_engine_job",
    { job_id: created.job.id },
  );
  expect(response.status).toBe(200);
  const body = (await response.json()) as { job: JobRecord };
  expect(body.job.id).toBe(created.job.id);
  expect(body.job.payload.note).toBe("public");
  // create stashes the verified claims under payload._claims; no read surface
  // ever hands them back.
  expect(body.job.payload._claims).toBeUndefined();

  const missing = await api(
    rt,
    "GET",
    "/__spacefast/api.php/jobs/job_does_not_exist",
    "get_engine_job",
    { job_id: "job_does_not_exist" },
  );
  expect(missing.status).toBe(404);
});

test("the jobs surface rejects an unknown type and an unknown lane as 422 problems", async () => {
  const badType = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    {},
    { type: "not_a_real_type", payload: {}, idempotency_key: "idem-bad-type" },
  );
  expect(badType.status).toBe(422);
  expect(((await badType.json()) as { code?: string }).code).toBe("unknown_job_type");

  const badLane = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=nope",
    "tick_engine_jobs",
    {},
  );
  expect(badLane.status).toBe(422);
  expect(((await badLane.json()) as { code?: string }).code).toBe("invalid_lane");
});

test("housekeeping prunes dead-lettered and finished records past retention, never live work", async () => {
  // Dead letters keep a two-week window; a finished queue record keeps a day —
  // without it queue/ was append-only and every claim, and every idempotency
  // lookup, re-read the whole history of jobs the site had ever run. Unfinished
  // work is never aged out, however long it has been waiting for its backoff.
  const staleDead = seedDeadJob({ type: "maintenance_tick" });
  const freshDead = seedDeadJob({ type: "maintenance_tick" });
  const staleComplete = seedJob({ type: "maintenance_tick", status: "complete" });
  const freshComplete = seedJob({ type: "maintenance_tick", status: "complete" });
  const waitingPending = seedJob({
    type: "maintenance_tick",
    not_before: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
  });

  const day = 24 * 60 * 60;
  backdate(deadPath(staleDead.id), 15 * day);
  backdate(deadPath(freshDead.id), 60);
  backdate(jobPath(staleComplete.id), 25 * 60 * 60);
  backdate(jobPath(freshComplete.id), 60 * 60);
  backdate(jobPath(waitingPending.id), 25 * 60 * 60);

  // Nothing is claimable, so this is a housekeeping-only tick.
  const result = await tick(1000);
  expect(result.tick_status).toBe("idle");

  expect(existsSync(deadPath(staleDead.id))).toBe(false);
  expect(existsSync(deadPath(freshDead.id))).toBe(true);
  expect(existsSync(jobPath(staleComplete.id))).toBe(false);
  expect(existsSync(jobPath(freshComplete.id))).toBe(true);
  expect(existsSync(jobPath(waitingPending.id))).toBe(true);
});

// The other half of the same registry (engine/admin/retention.php): staging
// roots are trees, not records, so they carry their window in the table rather
// than in a store descriptor.
test("housekeeping reclaims abandoned staging roots and spares live recovery state", async () => {
  const stage = (relative: string, ageSeconds: number): string => {
    const target = storagePath(rt, relative);
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, "staged");
    backdate(target, ageSeconds);
    return target;
  };
  const day = 24 * 60 * 60;

  // Staging debris is no recovery source, so its window is only a margin
  // against a live worker slower than the sweep.
  const abandonedBlob = stage("runtime/blob-staging/blob-abandoned.tmp", 2 * day);
  const liveBlob = stage("runtime/blob-staging/blob-live.tmp", 60);

  // .rust-failed-* is the sole surviving artifact of a failed native finalize,
  // so it gets the long abandonment window. Its sibling .rust-previous is what
  // an interrupted finalize is restored FROM and is not registered at all.
  const quarantine = storagePath(rt, "spaces/spc_gc/versions/.ver_gc.rust-failed-ab12");
  stage("spaces/spc_gc/versions/.ver_gc.rust-failed-ab12/marker", 20 * day);
  backdate(quarantine, 20 * day);
  const previous = storagePath(rt, "spaces/spc_gc/versions/.ver_gc.rust-previous");
  stage("spaces/spc_gc/versions/.ver_gc.rust-previous/marker", 20 * day);
  backdate(previous, 20 * day);

  // The glob pass runs on its own hourly cadence; drop the marker to force it.
  rmSync(storagePath(rt, "runtime", "reclaim.marker"), { force: true });
  await tick(1000);

  expect(existsSync(abandonedBlob)).toBe(false);
  expect(existsSync(liveBlob)).toBe(true);
  expect(existsSync(quarantine)).toBe(false);
  expect(existsSync(previous)).toBe(true);
});
