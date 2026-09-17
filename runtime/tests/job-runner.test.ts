// Engine job runner (contracts §10, D54/D55): admin/jobs.php's tick driver,
// dispatched over POST /jobs/tick and inspected over GET /jobs/{jobId}.
//
// v4 collapsed the framework to ONE registered maintenance tick and ONE demote
// operation, both on the bulk lane, so this file tests the RUNNER (admission,
// claim, fencing, reap, dead-letter, single-flight, housekeeping) and nothing
// about what a particular job does. `maintenance_tick` is the cheap stand-in
// for a job that runs; `tier_demote`'s multi-step/cursor behavior belongs to
// tiering.test.ts, which drives it across ticks against the S3 fake.
//
// A tick is a capability for ONE job: the engine takes its target from the
// verified token, so every tick here signs the job id and the job's Space.
// Box-wide work (reaping other lanes' abandoned runs, retention, GC) is the
// maintenance job's, never a targeted tick's.
//
// Delivery is pull-only (D53): the journal is the only sink, so lifecycle events
// are asserted in runtime/journal.jsonl, not against a callback origin. The
// drain route that hands those records back is callback-drain.test.ts's.
//
// Seeds go through the real create route and then have their MUTABLE state
// patched on disk. Admission is the only producer of an attested record, and
// the envelope covers identity and payload — which a state patch never touches
// — so this gives the states the route cannot produce (an orphaned `running`
// record, an aged dead letter) without hand-forging a job.
import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { spawn, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  utimesSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

import { api, apiJson, journalRecords, startRuntime, storagePath, type Runtime } from "./harness";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => {
  rt?.stop();
});

// Jobs are seeded onto disk, so a leftover pending job stays claimable by the
// next test's tick.
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

const SPACE = "spc_job_runner_test";

let jobSeq = 0;

function nextKey(label: string): string {
  jobSeq += 1;
  return `idem-${label}-${jobSeq}-${Math.random().toString(16).slice(2, 8)}`;
}

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

function readJob(jobId: string): JobRecord {
  const queued = jobPath(jobId);
  const source = existsSync(queued) ? queued : deadPath(jobId);
  return JSON.parse(readFileSync(source, "utf8")) as JobRecord;
}

/**
 * Re-stamp a record's envelope with the engine's OWN function, for the one
 * fixture admission cannot produce: a job whose type left the registry after it
 * was created (a queue outliving a deploy). Everything else keeps the envelope
 * admission gave it, because the envelope is what gives a record authority.
 */
function attestJob(record: JobRecord): JobRecord {
  const enginePath = path.join(rt.storageRoot, "..", "releases", "test", "engine");
  const script = `
require $argv[1] . '/admin/jobs.php';
$record = json_decode($argv[3], true);
$record['attestation'] = _stattic_runtime_job_attestation($argv[2], $record);
echo json_encode($record);
`;
  const stamped = spawnSync(
    "php",
    ["-r", script, enginePath, rt.storageRoot, JSON.stringify(record)],
    {
      encoding: "utf8",
    },
  );
  if (stamped.status !== 0) {
    throw new Error(`attestJob failed: ${stamped.stdout}${stamped.stderr}`);
  }
  // SAFETY: the helper above echoes the same record it was handed, with one
  // field replaced, and a non-zero exit already threw.
  return JSON.parse(stamped.stdout) as JobRecord;
}

function writeJob(record: JobRecord, target = jobPath(record.id)): void {
  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, JSON.stringify(record));
}

function backdate(target: string, ageSeconds: number): void {
  const at = new Date(Date.now() - ageSeconds * 1000);
  utimesSync(target, at, at);
}

type CreateInput = {
  type: string;
  idempotencyKey?: string;
  spaceId?: string;
  operationId?: string;
  payload?: JobRecord["payload"];
};

// Type, idempotency key and the exact serialized payload are signed alongside
// the Space and operation: the whole job, in one signature.
function createScope(input: CreateInput) {
  const key = input.idempotencyKey ?? nextKey(input.type);
  return {
    space_id: input.spaceId ?? SPACE,
    operation_id: input.operationId ?? `op_${key}`,
    job_scope: {
      type: input.type,
      idempotency_key: key,
      payload: JSON.stringify(input.payload ?? {}),
    },
  };
}

async function createJob(input: CreateInput, expectedStatus = 201): Promise<JobRecord> {
  const body = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    createScope(input),
    {},
    expectedStatus,
  );
  return body.job;
}

/** Create through the real route, then patch the state the route cannot produce. */
async function seedJob(
  input: CreateInput & { state?: Partial<JobRecord>; terminal?: boolean },
): Promise<JobRecord> {
  const created = await createJob(input);
  // Patch the ON-DISK record, not the public response: the response hides the
  // engine's envelope, and a seed without it is a record admission never made.
  const record = { ...readJob(created.id), ...(input.state ?? {}) };
  if (input.terminal) {
    rmSync(jobPath(record.id), { force: true });
    writeJob(record, deadPath(record.id));
  } else {
    writeJob(record);
  }
  return record;
}

type TickResponse = {
  lane: string;
  job: JobRecord | null;
  execution_timeout_seconds?: number | null;
  tick_status: string;
};

// A tick token names the job it may act on, the Space that job belongs to, and
// the operation it is being driven for — the whole immutable scope admission
// stamped, which is what the engine compares before it acts.
type JobScope = Pick<JobRecord, "id" | "space_id" | "operation_id">;

function tickScope(job: JobScope) {
  return { job_id: job.id, space_id: job.space_id, operation_id: job.operation_id };
}

async function tick(job: JobScope, budgetMs: number) {
  return apiJson<TickResponse>(
    rt,
    "POST",
    `/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=${budgetMs}`,
    "tick_engine_jobs",
    tickScope(job),
    {},
  );
}

function journalFor(jobId: string): Array<Record<string, unknown>> {
  return journalRecords(rt).filter((entry) => entry.job_id === jobId);
}

// The engine stripes job locks by sha256(jobId) beside the records they guard.
function jobLockPath(jobId: string): string {
  const stripe = createHash("sha256").update(jobId).digest("hex").slice(0, 2);
  return storagePath(rt, "runtime", "jobs", `job-${stripe}.lock`);
}

/** Waits for the holder process to exit, so the lock is provably free after it. */
type ReleaseLock = () => Promise<void>;

/** Hold a lock file from another process until the returned release resolves. */
async function holdLock(lockPath: string, holdMs: number): Promise<ReleaseLock> {
  const markerPath = `${lockPath}.${Math.random().toString(16).slice(2)}.ready`;
  const holderScript = `
$fh = fopen($argv[1], 'c');
if ($fh === false || !flock($fh, LOCK_EX)) { fwrite(STDERR, "lock_failed\\n"); exit(1); }
file_put_contents($argv[2], 'ready');
usleep((int) $argv[3]);
flock($fh, LOCK_UN);
fclose($fh);
`;
  mkdirSync(path.dirname(lockPath), { recursive: true });
  const holder = spawn("php", ["-r", holderScript, lockPath, markerPath, String(holdMs * 1000)]);
  const deadline = Date.now() + 5000;
  while (!existsSync(markerPath)) {
    if (Date.now() > deadline) {
      throw new Error("lock holder never signaled ready");
    }
    // eslint-disable-next-line no-await-in-loop -- polling the holder's own readiness marker
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  return () => new Promise<void>((resolve) => holder.on("exit", () => resolve()));
}

test("a targeted tick runs its signed job, bounds its execution, and leaves the lane alone", async () => {
  const target = await createJob({ type: "maintenance_tick" });
  const bystander = await createJob({ type: "maintenance_tick" });

  const first = await apiJson<TickResponse>(
    rt,
    "POST",
    `/__spacefast/api.php/jobs/tick?lane=bulk&job_id=${bystander.id}`,
    "tick_engine_jobs",
    tickScope(target),
    { budget_ms: 2500 },
    200,
  );
  expect(first.job?.id).toBe(target.id);
  expect(first.tick_status).toBe("complete");
  expect(first.job?.status).toBe("complete");
  // The lane lock is an OS flock, so a wedged worker would pin the lane forever.
  // The tick bounds itself past its cooperative budget (2.5s + 30s margin) so
  // PHP tears the worker down and the heartbeat reaper recovers.
  expect(first.execution_timeout_seconds).toBe(33);

  // The claimed job ran its stepper: the housekeeping pass reports the steps it
  // completed as the job result, and says so honestly.
  const completed = readJob(target.id);
  expect(completed.status).toBe("complete");
  expect(completed.heartbeat).toBeGreaterThan(0);
  // SAFETY: the maintenance stepper's result shape, asserted field by field below.
  const result = completed.result as { steps: string[]; failed: unknown[]; complete: boolean };
  expect(result.steps).toContain("retention");
  expect(result.failed).toEqual([]);
  expect(result.complete).toBe(true);

  // The compatibility query cannot redirect a tick away from its signed job.
  expect(readJob(bystander.id).status).toBe("pending");

  const second = await tick(bystander, 2500);
  expect(second.job?.id).toBe(bystander.id);
  expect(second.tick_status).toBe("complete");
});

test("a targeted tick does not reap another job's abandoned run", async () => {
  const stale = Math.floor(Date.now() / 1000) - 200; // past the 120s timeout
  const orphan = await seedJob({
    type: "maintenance_tick",
    state: { status: "running", heartbeat: stale },
  });
  // Any job EXCEPT the maintenance pass, which is the one job whose work is
  // box-wide by definition. This one dead-letters on its first step.
  const created = await createJob({ type: "maintenance_tick" });
  const target = attestJob({ ...readJob(created.id), type: "tier_promote" });
  writeJob(target);

  const result = await tick(target, 2500);
  expect(result.tick_status).toBe("dead_letter");

  // Box-wide recovery belongs to the maintenance pass. A Space-scoped tick that
  // requeued this would be acting on a job its token never named.
  const untouched = readJob(orphan.id);
  expect(untouched.status).toBe("running");
  expect(untouched.heartbeat).toBe(stale);
  expect(untouched.attempt).toBe(0);
});

test("an unrunnable job dead-letters, leaves the queue, and is announced on the journal", async () => {
  // A record whose type is not in the registry (D54 deleted tier_promote,
  // tier_cancel, blob_report and the transfer jobs, and a queue outlives a
  // deploy) is fatal on its first step, not retried. The type is part of the
  // job's identity, so this seeds an already-created record onto the type.
  const created = await createJob({ type: "maintenance_tick" });
  const job = attestJob({ ...readJob(created.id), type: "tier_promote" });
  writeJob(job);

  const result = await tick(job, 2500);
  expect(result.tick_status).toBe("dead_letter");
  expect(result.job?.status).toBe("failed");
  expect(existsSync(jobPath(job.id))).toBe(false);

  const dead = readJob(job.id);
  expect(dead.status).toBe("failed");
  expect(dead.attempt).toBe(1);
  expect(dead.error).toEqual({ code: "unknown_job_type", message: "unknown_job_type" });

  // Pull-only delivery (D53): the failure reaches the control plane as journal
  // records, in the wire shape its drain parses (job_type/lane/nested error).
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
    space_id: job.space_id,
    operation_id: job.operation_id,
  });
  expect(response.status).toBe(200);
  expect(((await response.json()) as { job: JobRecord }).job.status).toBe("failed");
});

test("a targeted tick recovers its OWN abandoned run, and it runs next tick", async () => {
  const job = await seedJob({
    type: "maintenance_tick",
    state: { status: "running", heartbeat: Math.floor(Date.now() / 1000) - 200 },
  });

  const reaped = await tick(job, 1000);
  expect(reaped.tick_status).toBe("retry_scheduled");

  const record = readJob(job.id);
  expect(record.status).toBe("pending");
  expect(record.attempt).toBe(1);
  expect(record.heartbeat).toBeNull();
  expect(record.first_failed_at).not.toBeNull();
  expect(record.not_before).not.toBeNull();
  expect(record.error?.code).toBe("heartbeat_timeout");

  // Real backoff is ~10s. The transition and the clean resume are under test,
  // not the wall clock, so make the job eligible again instead of waiting.
  writeJob({ ...record, not_before: null });

  const resumed = await tick(job, 2500);
  expect(resumed.job?.id).toBe(job.id);
  expect(resumed.tick_status).toBe("complete");
  expect(readJob(job.id).status).toBe("complete");
});

test("a held lane lock makes a tick a no-op, so a job is never claimed twice", async () => {
  // Single flight is an OS flock taken try-once (§22 "held → exit 0"), so hold
  // that exact lock from another process instead of racing two ticks on
  // wall-clock timing. The concurrent tick then provably overlaps, whatever the
  // scheduler does.
  const job = await createJob({ type: "maintenance_tick" });
  const release = await holdLock(storagePath(rt, "runtime", "jobs", "lane-bulk.lock"), 1500);

  const locked = await tick(job, 2500);
  expect(locked.tick_status).toBe("lane_locked");
  // Untouched: a locked tick never claims, so it cannot have run the job.
  expect(readJob(job.id).status).toBe("pending");

  await release();

  const released = await tick(job, 2500);
  expect(released.job?.id).toBe(job.id);
  expect(released.tick_status).toBe("complete");
});

test("a contended job lock costs the tick its budget, not the lock helper's own wait", async () => {
  const job = await createJob({ type: "maintenance_tick" });
  // The blocking-WAIT failure this replaces would sit on _stattic_lock_acquire's
  // fixed 10s deadline while holding the lane. The transition retries TRY
  // against the CALLER's clock instead, so the whole tick stays bounded.
  const release = await holdLock(jobLockPath(job.id), 3000);

  const startedAt = Date.now();
  const contended = await tick(job, 500);
  const elapsedMs = Date.now() - startedAt;

  expect(contended.tick_status).toBe("contended");
  expect(readJob(job.id).status).toBe("pending");
  expect(elapsedMs).toBeLessThan(3000);

  await release();

  const after = await tick(job, 2500);
  expect(after.tick_status).toBe("complete");
});

test("a tick token minted for another Space or another operation is refused before any work", async () => {
  const job = await createJob({ type: "maintenance_tick" });

  const refused = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=2500",
    "tick_engine_jobs",
    { job_id: job.id, space_id: "spc_someone_else", operation_id: job.operation_id },
    {},
  );
  expect(refused.status).toBe(403);
  // SAFETY: a 403 from the management lane is an RFC 9457 problem document.
  expect(((await refused.json()) as { code?: string }).code).toBe("runtime_job_space_mismatch");
  expect(readJob(job.id).status).toBe("pending");

  // The job's own Space, another operation's capability. A job is admitted for
  // ONE operation and carries it into every lifecycle event it emits, so a tick
  // signed for a different one would drive work that operation never asked for
  // and announce it under the operation that did.
  const wrongOperation = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=2500",
    "tick_engine_jobs",
    { job_id: job.id, space_id: job.space_id, operation_id: "op_somebody_else" },
    {},
  );
  expect(wrongOperation.status).toBe(403);
  // SAFETY: a 403 from the management lane is an RFC 9457 problem document.
  expect(((await wrongOperation.json()) as { code?: string }).code).toBe(
    "runtime_job_operation_mismatch",
  );
  expect(readJob(job.id).status).toBe("pending");

  // Same job, its own scope: the refusals were about the capability, not the job.
  expect((await tick(job, 2500)).tick_status).toBe("complete");
});

test("a contended abandoned job costs the lane neither its budget nor the orphans behind it", async () => {
  // Box-wide reaping is the one thing that touches jobs the tick was not signed
  // for, and it used to give EVERY abandoned job a fresh admission budget plus
  // finalization grace of its own. One held job lock therefore cost the lane
  // about four seconds whatever budget the tick asked for, and each further
  // contended job added its own — an unbounded wait inside the lane lock, which
  // is the exact failure the targeted-tick budget was introduced to kill.
  //
  // Spending the pass's own deadline on that lock bounds the tick but is not
  // enough: the queue is walked in ascending id order every pass and no cursor
  // moves past an obstruction, so one unrelated holder starved every abandoned
  // run behind it until it happened to let go. The bystander lock is taken
  // no-wait now, so the walk carries on to the orphans behind it in the SAME
  // pass.
  const stale = Math.floor(Date.now() / 1000) - 200; // past the 120s timeout
  const pass = await createJob({ type: "maintenance_tick" });
  // Distinct lock stripes: a holder on the pass job's own stripe would measure
  // its claim instead, and two holders on one stripe file would serialize.
  const stripes = new Set([jobLockPath(pass.id)]);
  const orphans: JobRecord[] = [];
  while (orphans.length < 2) {
    // eslint-disable-next-line no-await-in-loop -- each seed depends on the stripes already taken
    const orphan = await seedJob({
      type: "maintenance_tick",
      state: { status: "running", heartbeat: stale },
    });
    if (stripes.has(jobLockPath(orphan.id))) continue;
    stripes.add(jobLockPath(orphan.id));
    orphans.push(orphan);
  }
  // The reaper walks the queue store in ascending id order, so the held job has
  // to be the one that sorts FIRST for `behind` to sit past the obstruction.
  orphans.sort((left, right) => (left.id < right.id ? -1 : 1));
  const [held, behind] = orphans;
  const release = await holdLock(jobLockPath(held.id), 4000);

  const startedAt = Date.now();
  const contended = await tick(pass, 500);
  const elapsedMs = Date.now() - startedAt;

  // The holder outlives the budget, so the tick provably gave up on the lock
  // rather than outwaiting it.
  expect(elapsedMs).toBeLessThan(3000);
  // The obstruction itself is untouched and the pass says so, rather than
  // reporting a clean sweep it did not make.
  expect(readJob(held.id).status).toBe("running");
  expect(readJob(held.id).attempt).toBe(0);
  expect(contended.tick_status).toBe("yielded");
  expect(contended.job?.status).toBe("pending");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  expect((contended.job?.result as { complete: boolean }).complete).toBe(false);
  // The orphan behind it is unlocked and just as abandoned: waiting on somebody
  // else's lock is what used to deny it any recovery at all.
  expect(readJob(behind.id).status).toBe("pending");
  expect(readJob(behind.id).error?.code).toBe("heartbeat_timeout");

  await release();

  // Uncontended, with a budget that fits: the same pass recovers the holdout.
  const after = await tick(pass, 5000);
  expect(after.tick_status).toBe("complete");
  expect(readJob(held.id).status).toBe("pending");
  expect(readJob(held.id).error?.code).toBe("heartbeat_timeout");
});

test("POST /jobs takes the whole job from the signed scope, never the request body", async () => {
  const idempotencyKey = nextKey("create");
  const created = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    createScope({
      type: "maintenance_tick",
      idempotencyKey,
      spaceId: "spc_create_route",
      operationId: "op_create_route",
      payload: { note: "kept" },
    }),
    // D54: the rollout compatibility body cannot override the verified token.
    { type: "tier_demote", space_id: "spc_body_lie", payload: { note: "ignored" } },
    201,
  );
  expect(created.job.space_id).toBe("spc_create_route");
  expect(created.job.operation_id).toBe("op_create_route");
  expect(created.job.type).toBe("maintenance_tick");
  // Lane is derived from the type, never chosen by the caller.
  expect(created.job.lane).toBe("bulk");
  expect(created.job.status).toBe("pending");
  expect(created.job.payload).toEqual({ note: "kept" });

  // The same signed scope returns the ORIGINAL job, not a second one.
  const again = await createJob({
    type: "maintenance_tick",
    idempotencyKey,
    spaceId: "spc_create_route",
    operationId: "op_create_route",
    payload: { note: "kept" },
  });
  expect(again.id).toBe(created.job.id);

  // The created job is drivable through the real tick route end to end.
  const ran = await tick(created.job, 2500);
  expect(ran.job?.id).toBe(created.job.id);
  expect(ran.job?.status).toBe("complete");
});

test("one identity admits one job, across a terminal move and against any other scope", async () => {
  const idempotencyKey = nextKey("admission");
  const created = await createJob({
    type: "maintenance_tick",
    idempotencyKey,
    payload: { note: "original" },
  });

  // Same key, different work: a refusal, not somebody else's job handed back as
  // if it were this request's.
  const conflict = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    createScope({ type: "maintenance_tick", idempotencyKey, payload: { note: "different" } }),
    {},
  );
  expect(conflict.status).toBe(409);
  // SAFETY: a 409 from the management lane is an RFC 9457 problem document.
  expect(((await conflict.json()) as { code?: string }).code).toBe(
    "runtime_job_idempotency_conflict",
  );

  // Identity buckets by (type, Space, key), so payload equality is not the whole
  // question: reuse compares the COMPLETE immutable admission scope. Same tuple,
  // different operation is a different request, and handing it this job would
  // let it drive and announce work it never signed for.
  const otherOperation = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    createScope({
      type: "maintenance_tick",
      idempotencyKey,
      payload: { note: "original" },
      operationId: "op_somebody_else",
    }),
    {},
  );
  expect(otherOperation.status).toBe(409);
  // SAFETY: a 409 from the management lane is an RFC 9457 problem document.
  expect(((await otherOperation.json()) as { code?: string }).code).toBe(
    "runtime_job_idempotency_conflict",
  );
  expect(readJob(created.id).operation_id).toBe(created.operation_id);

  // Drive it into terminal storage, then re-admit the same identity. Admission
  // is a locked read of a derived id, so a record moving between the stores is
  // never invisible to both halves of a search the way a scan's was.
  //
  // An abandoned run that has spent its attempts is how a record really reaches
  // the dead store, and it only touches MUTABLE state. Forging a record onto
  // another type would move it too, but that record contradicts its own id —
  // identity is derived from the type — so it could not answer what admission
  // asks here.
  writeJob({
    ...readJob(created.id),
    status: "running",
    heartbeat: Math.floor(Date.now() / 1000) - 200,
    attempt: 5,
    max_attempts: 5,
  });
  expect((await tick(created, 2500)).tick_status).toBe("dead_letter");
  expect(existsSync(deadPath(created.id))).toBe(true);

  const readmitted = await createJob({
    type: "maintenance_tick",
    idempotencyKey,
    payload: { note: "original" },
  });
  expect(readmitted.id).toBe(created.id);
  expect(readmitted.status).toBe("failed");
  expect(existsSync(jobPath(created.id))).toBe(false);
});

test("GET /jobs/{jobId} returns the record's public fields, 404 for unknown ids", async () => {
  const created = await createJob({
    type: "maintenance_tick",
    spaceId: "spc_get_route",
    operationId: "op_get_route",
    payload: { note: "public" },
  });

  const response = await api(
    rt,
    "GET",
    `/__spacefast/api.php/jobs/${created.id}`,
    "get_engine_job",
    { job_id: created.id, space_id: "spc_get_route", operation_id: "op_get_route" },
  );
  expect(response.status).toBe(200);
  // SAFETY: a 200 from GET /jobs/{id} is the job envelope; `attestation` is the
  // field this case proves absent.
  const body = (await response.json()) as { job: JobRecord & { attestation?: string } };
  expect(body.job.id).toBe(created.id);
  // The payload is exactly what was signed: no bearer token smuggled into it,
  // and no engine-internal proof handed back out.
  expect(body.job.payload).toEqual({ note: "public" });
  expect(body.job.attestation).toBeUndefined();

  // The read is scoped the same way the tick is: a token carrying another
  // operation names a job it was never admitted for.
  const wrongOperation = await api(
    rt,
    "GET",
    `/__spacefast/api.php/jobs/${created.id}`,
    "get_engine_job",
    { job_id: created.id, space_id: "spc_get_route", operation_id: "op_somebody_else" },
  );
  expect(wrongOperation.status).toBe(403);
  // SAFETY: a 403 from the management lane is an RFC 9457 problem document.
  expect(((await wrongOperation.json()) as { code?: string }).code).toBe(
    "runtime_job_operation_mismatch",
  );

  const missing = await api(
    rt,
    "GET",
    "/__spacefast/api.php/jobs/job_does_not_exist",
    "get_engine_job",
    { job_id: "job_does_not_exist" },
  );
  expect(missing.status).toBe(404);
});

test("unreadable job state fails closed instead of reading as an absent job", async () => {
  const job = await createJob({ type: "maintenance_tick" });
  // A truncated write, a torn record, a half-copied restore: the file is there
  // and this process cannot use it. Reporting 404 would let a caller conclude
  // the job never existed and admit another.
  mkdirSync(deadDir(), { recursive: true });
  writeFileSync(deadPath(job.id), '{"id": "job_');

  const read = await api(rt, "GET", `/__spacefast/api.php/jobs/${job.id}`, "get_engine_job", {
    job_id: job.id,
    space_id: job.space_id,
    operation_id: job.operation_id,
  });
  expect(read.status).toBe(503);
  // SAFETY: a 503 from the management lane is an RFC 9457 problem document.
  expect(((await read.json()) as { code?: string }).code).toBe("runtime_job_state_unavailable");

  const ticked = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=2500",
    "tick_engine_jobs",
    tickScope(job),
    {},
  );
  expect(ticked.status).toBe(503);
  // Terminal storage wins the read, so the queued record is never run behind a
  // dead letter nobody can parse.
  expect(readJob(job.id).status).toBe("pending");
});

test("a record that never passed admission cannot be claimed or run", async () => {
  const job = await createJob({ type: "maintenance_tick" });
  // Readable, well-formed, and carrying admission's own envelope — but a
  // payload that envelope never covered. Nothing recomputes the envelope, so a
  // record edited on disk can never acquire authority for its new contents.
  writeJob({ ...readJob(job.id), payload: { note: "smuggled" } });

  const result = await tick(job, 2500);
  expect(result.tick_status).toBe("unavailable");
  expect(readJob(job.id).status).toBe("pending");
  expect(journalFor(job.id).map((entry) => entry.event)).toContain("job_attestation_invalid");
});

test("the jobs surface rejects an unknown type and an unknown lane as 422 problems", async () => {
  const badType = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    createScope({ type: "not_a_real_type" }),
    {},
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

test("the maintenance pass reaps box-wide and prunes past retention, never live work", async () => {
  // Dead letters keep a two-week window; a finished queue record keeps a day.
  // Without that, queue/ is append-only and every claim and idempotency lookup
  // re-reads every job the site has ever run. Unfinished work is never aged
  // out, however long it has waited for its backoff.
  const staleDead = await seedJob({ type: "maintenance_tick", terminal: true });
  const freshDead = await seedJob({ type: "maintenance_tick", terminal: true });
  const staleComplete = await seedJob({ type: "maintenance_tick", state: { status: "complete" } });
  const freshComplete = await seedJob({ type: "maintenance_tick", state: { status: "complete" } });
  const waitingPending = await seedJob({
    type: "maintenance_tick",
    state: { not_before: new Date(Date.now() + 60 * 60 * 1000).toISOString() },
  });
  // The abandoned run box-wide reaping owns: no targeted tick will ever see it.
  const orphan = await seedJob({
    type: "maintenance_tick",
    state: { status: "running", heartbeat: Math.floor(Date.now() / 1000) - 200 },
  });
  const pass = await createJob({ type: "maintenance_tick" });

  const day = 24 * 60 * 60;
  backdate(deadPath(staleDead.id), 15 * day);
  backdate(deadPath(freshDead.id), 60);
  backdate(jobPath(staleComplete.id), 25 * 60 * 60);
  backdate(jobPath(freshComplete.id), 60 * 60);
  backdate(jobPath(waitingPending.id), 25 * 60 * 60);

  const result = await tick(pass, 5000);
  expect(result.tick_status).toBe("complete");
  // SAFETY: the tick above completed, so the record carries the pass result.
  expect((result.job?.result as { steps: string[] }).steps).toContain("job_reap");

  const recovered = readJob(orphan.id);
  expect(recovered.status).toBe("pending");
  expect(recovered.error?.code).toBe("heartbeat_timeout");

  expect(existsSync(deadPath(staleDead.id))).toBe(false);
  expect(existsSync(deadPath(freshDead.id))).toBe(true);
  expect(existsSync(jobPath(staleComplete.id))).toBe(false);
  expect(existsSync(jobPath(freshComplete.id))).toBe(true);
  expect(existsSync(jobPath(waitingPending.id))).toBe(true);
});

/**
 * Seeds a reclaimable staging backlog in one PHP process: 20k entries through
 * bun's fs would cost more wall clock than the tick under test.
 */
function stageBacklog(relativeRoot: string, count: number, ageSeconds: number): void {
  const root = storagePath(rt, relativeRoot);
  const script = `
$root = $argv[1];
$at = time() - (int) $argv[3];
@mkdir($root, 0775, true);
for ($i = 0; $i < (int) $argv[2]; $i++) {
    $path = $root . '/backlog-' . $i . '.tmp';
    file_put_contents($path, 'x');
    touch($path, $at);
}
`;
  const seeded = spawnSync("php", ["-r", script, root, String(count), String(ageSeconds)], {
    encoding: "utf8",
  });
  if (seeded.status !== 0) {
    throw new Error(`stageBacklog failed: ${seeded.stdout}${seeded.stderr}`);
  }
}

function stagedCount(relativeRoot: string): number {
  const root = storagePath(rt, relativeRoot);
  return existsSync(root) ? readdirSync(root).length : 0;
}

/**
 * The same backlog shaped as ONE glob entry: an abandoned finalizer stage with
 * its files spread over nested directories, which is what a killed native
 * finalize actually leaves behind.
 */
function stageNestedBacklog(relativeRoot: string, perDirectory: number, ageSeconds: number): void {
  const root = storagePath(rt, relativeRoot);
  const script = `
$root = $argv[1];
$at = time() - (int) $argv[3];
$dirs = [$root, $root . '/files', $root . '/files/assets', $root . '/blobs'];
foreach ($dirs as $dir) {
    @mkdir($dir, 0775, true);
    for ($i = 0; $i < (int) $argv[2]; $i++) {
        $path = $dir . '/entry-' . $i;
        file_put_contents($path, 'x');
        touch($path, $at);
    }
}
foreach (array_reverse($dirs) as $dir) {
    touch($dir, $at);
}
`;
  const seeded = spawnSync("php", ["-r", script, root, String(perDirectory), String(ageSeconds)], {
    encoding: "utf8",
  });
  if (seeded.status !== 0) {
    throw new Error(`stageNestedBacklog failed: ${seeded.stdout}${seeded.stderr}`);
  }
}

function nestedStagedCount(relativeRoot: string): number {
  const root = storagePath(rt, relativeRoot);
  if (!existsSync(root)) return 0;
  let total = 0;
  for (const entry of readdirSync(root, { recursive: true, withFileTypes: true })) {
    if (entry.isFile()) total += 1;
  }
  return total;
}

test("a maintenance hook that could not finish leaves the pass incomplete and the job resumable", async () => {
  const idempotencyKey = nextKey("gc-skipped");
  const pass = await createJob({ type: "maintenance_tick", idempotencyKey });
  // The blob GC takes a try-lock of its own and does nothing when another
  // process holds it. Counting that as a completed step is what let the control
  // plane record a successful sweep — and impose its cooldown — for a pass that
  // collected nothing.
  const release = await holdLock(storagePath(rt, "runtime", "blob-gc.lock"), 1500);

  const skipped = await tick(pass, 2500);
  expect(skipped.tick_status).toBe("yielded");
  expect(skipped.job?.status).toBe("pending");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  const report = skipped.job?.result as {
    steps: string[];
    skipped: Array<{ step: string; reason: string }>;
    failed: unknown[];
    complete: boolean;
  };
  expect(report.complete).toBe(false);
  expect(report.steps).not.toContain("blob_gc");
  expect(report.skipped.map((entry) => entry.step)).toContain("blob_gc");

  // The control plane retries under the same operation-derived idempotency key.
  // It has to get a job it can still drive: a completed one whose GC never ran
  // is handed back forever, so the retry can never succeed.
  const readmitted = await createJob({ type: "maintenance_tick", idempotencyKey });
  expect(readmitted.id).toBe(pass.id);
  expect(readmitted.status).toBe("pending");

  await release();

  const clean = await tick(pass, 2500);
  expect(clean.tick_status).toBe("complete");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  expect((clean.job?.result as { complete: boolean }).complete).toBe(true);

  // The other way a hook fails to finish: it started inside the budget and ran
  // out mid-walk. Deciding at the door alone bounded WHICH hooks ran and nothing
  // else — retention reclaimed an entire staging backlog, hundreds of
  // milliseconds inside the bulk lane lock, and then reported the hooks behind
  // it skipped as if that time had been saved.
  const backlogRoot = "runtime/blob-staging";
  stageBacklog(backlogRoot, 20000, 2 * 24 * 60 * 60);
  // The glob pass runs on its own hourly cadence; drop the marker to make it due.
  rmSync(storagePath(rt, "runtime", "reclaim.marker"), { force: true });
  const budgetKey = nextKey("gc-budget");
  const budgetPass = await createJob({ type: "maintenance_tick", idempotencyKey: budgetKey });

  const budgeted = await tick(budgetPass, 150);
  expect(budgeted.tick_status).toBe("yielded");
  expect(budgeted.job?.status).toBe("pending");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  const budgetReport = budgeted.job?.result as {
    steps: string[];
    skipped: Array<{ step: string; reason: string }>;
    complete: boolean;
  };
  expect(budgetReport.complete).toBe(false);
  expect(budgetReport.steps).not.toContain("retention");
  expect(budgetReport.skipped.map((entry) => entry.step)).toContain("retention");
  // The walk stopped ON the deadline instead of taking the whole backlog with it.
  expect(stagedCount(backlogRoot)).toBeGreaterThan(0);

  const readmitted2 = await createJob({ type: "maintenance_tick", idempotencyKey: budgetKey });
  expect(readmitted2.id).toBe(budgetPass.id);
  expect(readmitted2.status).toBe("pending");

  // And the hourly cadence marker did NOT advance for a pass that left work
  // behind, so the resumed job owes the rest of the backlog now rather than in
  // an hour.
  const drained = await tick(budgetPass, 30000);
  expect(drained.tick_status).toBe("complete");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  expect((drained.job?.result as { complete: boolean }).complete).toBe(true);
  expect(stagedCount(backlogRoot)).toBe(0);

  // Third way, and the one a flat backlog cannot show: the budget bounded how
  // many ENTRIES the walk took, never the work one entry represents. An
  // abandoned finalizer stage is a single glob entry holding a whole version
  // tree, and it was deleted to completion once entered — measured at 276 ms of
  // held bulk lane for a 20 ms budget, with all 12,000 children gone.
  const treeRoot = "spaces/spc_budget/versions/.ver_budget.rust-finalizing";
  const perDirectory = 5000;
  stageNestedBacklog(treeRoot, perDirectory, 2 * 24 * 60 * 60);
  const seededFiles = nestedStagedCount(treeRoot);
  expect(seededFiles).toBe(4 * perDirectory);
  rmSync(storagePath(rt, "runtime", "reclaim.marker"), { force: true });
  const treeKey = nextKey("gc-tree");
  const treePass = await createJob({ type: "maintenance_tick", idempotencyKey: treeKey });

  const entered = await tick(treePass, 150);
  expect(entered.tick_status).toBe("yielded");
  // Progress was made INSIDE the entry, and the entry survived it.
  const afterFirst = nestedStagedCount(treeRoot);
  expect(afterFirst).toBeGreaterThan(0);
  expect(afterFirst).toBeLessThan(seededFiles);

  // Resumable at the SAME budget: deleting children moves the stage's own mtime
  // to now, and a sweep that let that happen would hide the remains behind a
  // fresh retention window instead of finishing them.
  let ticks = 1;
  while (existsSync(storagePath(rt, treeRoot)) && ticks < 60) {
    await tick(treePass, 150);
    ticks += 1;
  }
  expect(nestedStagedCount(treeRoot)).toBe(0);
  expect(existsSync(storagePath(rt, treeRoot))).toBe(false);
  expect(ticks).toBeGreaterThan(1);
});

test("a maintenance hook that throws keeps its job resumable, never complete", async () => {
  const spacesRoot = storagePath(rt, "spaces");
  rmSync(spacesRoot, { recursive: true, force: true });
  // Retention refuses to sweep half the box: an unenumerable space tree throws
  // rather than read as zero spaces. A plain file where that directory belongs
  // is that condition, for real.
  writeFileSync(spacesRoot, "not a directory");
  const idempotencyKey = nextKey("hook-threw");
  const pass = await createJob({ type: "maintenance_tick", idempotencyKey });
  try {
    const broken = await tick(pass, 2500);
    expect(broken.tick_status).toBe("yielded");
    expect(broken.job?.status).toBe("pending");
    // SAFETY: the maintenance stepper persists its pass report on every step.
    const report = broken.job?.result as {
      failed: Array<{ step: string; error: string }>;
      complete: boolean;
    };
    expect(report.complete).toBe(false);
    expect(report.failed.map((entry) => entry.step)).toContain("retention");

    const readmitted = await createJob({ type: "maintenance_tick", idempotencyKey });
    expect(readmitted.id).toBe(pass.id);
    expect(readmitted.status).toBe("pending");
  } finally {
    rmSync(spacesRoot, { force: true });
  }

  const recovered = await tick(pass, 2500);
  expect(recovered.tick_status).toBe("complete");
  // SAFETY: the maintenance stepper persists its pass report on every step.
  expect((recovered.job?.result as { complete: boolean }).complete).toBe(true);
});

// The other half of the same registry (engine/admin/retention.php). Staging
// roots are trees, not records, so their window lives in the table rather than
// in a store descriptor.
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
  // against a worker slower than the sweep.
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

  // .rust-finalizing is the mirror case: Rust deletes it on every path it
  // survives, so a surviving stage is a killed process's debris and takes the
  // short staging window. A stage from a finalize still in flight stays.
  const abandonedStage = storagePath(rt, "spaces/spc_stage/versions/.ver_stage.rust-finalizing");
  stage("spaces/spc_stage/versions/.ver_stage.rust-finalizing/files/index.html", 2 * day);
  backdate(abandonedStage, 2 * day);
  const liveStage = storagePath(rt, "spaces/spc_stage/versions/.ver_live.rust-finalizing");
  stage("spaces/spc_stage/versions/.ver_live.rust-finalizing/files/index.html", 60);
  backdate(liveStage, 60);

  // The glob pass runs on its own hourly cadence; drop the marker to force it.
  rmSync(storagePath(rt, "runtime", "reclaim.marker"), { force: true });
  const pass = await createJob({ type: "maintenance_tick" });
  await tick(pass, 5000);

  expect(existsSync(abandonedBlob)).toBe(false);
  expect(existsSync(liveBlob)).toBe(true);
  expect(existsSync(quarantine)).toBe(false);
  expect(existsSync(previous)).toBe(true);
  expect(existsSync(abandonedStage)).toBe(false);
  expect(existsSync(liveStage)).toBe(true);

  // Operators learn about it through the same journal event the other roots use.
  const reclaimed = journalRecords(rt).filter(
    (entry) => entry.event === "runtime_staging_reclaimed",
  );
  const roots = reclaimed.at(-1)?.roots as Record<string, unknown> | undefined;
  expect(Object.keys(roots ?? {})).toContain("spaces/*/versions/.*.rust-finalizing");
});
