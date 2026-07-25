// Engine job runner (plan §22): admin/jobs.php's two-lane tick driver, dispatched
// over POST /jobs/tick and inspected over GET /jobs/{jobId}. Response shapes
// ({lane, job, drained} for tick; {job} for create/get) match the control
// plane's established contract (packages/common/src/contracts/runtime-api.ts,
// apps/control-plane/src/runtime/engine-jobs.ts) — a sibling of this task.
// Most fixtures below seed job records directly onto disk (mirroring the
// on-disk schema §22 defines) so internal states unreachable through the
// public create contract — a custom max_attempts, a pre-set cursor, a poison
// stepper — are directly testable; the create route itself gets its own
// round-trip test.
import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, utimesSync, writeFileSync } from "node:fs";
import path from "node:path";

import { api, apiJson, startRuntime, type Runtime } from "./harness";

let rt: Runtime;
const receivedCallbacks: Array<{ authorization: string; body: unknown }> = [];
let receiver: ReturnType<typeof Bun.serve>;

beforeAll(async () => {
  receiver = Bun.serve({
    port: 0,
    fetch: async (request) => {
      receivedCallbacks.push({
        authorization: request.headers.get("authorization") ?? "",
        body: await request.json().catch(() => null),
      });
      return new Response("ok", { status: 200 });
    },
  });
  // PHP_CLI_SERVER_WORKERS enables genuine concurrent request handling in the
  // built-in dev server (confirmed: without it, php -S serves one request at
  // a time) — required for the "two concurrent ticks" test below.
  rt = await startRuntime({ env: { PHP_CLI_SERVER_WORKERS: "4" } });
});

afterAll(() => {
  rt?.stop();
  receiver?.stop(true);
});

// Every test gets a clean job/callback surface: jobs are seeded directly onto
// disk (no create route to reset state through), so leftover pending jobs
// from a prior test would otherwise be claimable by the next test's ticks.
beforeEach(() => {
  receivedCallbacks.length = 0;
  for (const dir of ["runtime/jobs", "runtime/callbacks"]) {
    rmSync(path.join(rt.storageRoot, dir), { recursive: true, force: true });
  }
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

function jobsQueueDir(): string {
  return path.join(rt.storageRoot, "runtime/jobs/queue");
}

function jobsDeadDir(): string {
  return path.join(rt.storageRoot, "runtime/jobs/dead");
}

function jobPath(jobId: string): string {
  return path.join(jobsQueueDir(), `${jobId}.json`);
}

function seedJob(overrides: Partial<JobRecord> & { type: string; lane: string }): JobRecord {
  jobSeq += 1;
  const id = overrides.id ?? `job_test${jobSeq}${Math.random().toString(16).slice(2, 8)}`;
  const now = new Date(Date.now() - (1000 - jobSeq)).toISOString(); // stable ascending created_at
  const record: JobRecord = {
    id,
    type: overrides.type,
    lane: overrides.lane,
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
    created_at: overrides.created_at ?? now,
    updated_at: overrides.updated_at ?? now,
  };
  mkdirSync(jobsQueueDir(), { recursive: true });
  writeFileSync(jobPath(id), JSON.stringify(record));
  return record;
}

function readJob(jobId: string): JobRecord {
  const queuePath = jobPath(jobId);
  const source = existsSync(queuePath) ? queuePath : path.join(jobsDeadDir(), `${jobId}.json`);
  return JSON.parse(readFileSync(source, "utf8")) as JobRecord;
}

type TickResponse = {
  lane: string;
  job: JobRecord | null;
  drained: boolean;
  execution_timeout_seconds?: number | null;
  tick_status: string;
};

async function tick(lane: string, budgetMs?: number, jobId?: string): Promise<TickResponse> {
  const query = new URLSearchParams({ lane });
  if (budgetMs !== undefined) {
    query.set("budget_ms", String(budgetMs));
  }
  if (jobId !== undefined) {
    query.set("job_id", jobId);
  }
  return apiJson(rt, "POST", `/__spacefast/api.php/jobs/tick?${query}`, "tick_engine_jobs", {});
}

function seedPendingCallback(overrides: {
  callbackUrl: string;
  callbackToken: string;
  event: Record<string, unknown>;
}): string {
  const id = `cb_test${Math.random().toString(16).slice(2, 10)}`;
  const dir = path.join(rt.storageRoot, "runtime/callbacks/pending");
  mkdirSync(dir, { recursive: true });
  writeFileSync(
    path.join(dir, `${id}.json`),
    JSON.stringify({
      id,
      status: "pending",
      callback_url: overrides.callbackUrl,
      callback_token: overrides.callbackToken,
      operation_id: "op_seeded_callback",
      event: overrides.event,
      created_at: new Date().toISOString(),
      attempts: 0,
    }),
  );
  return id;
}

test("bulk ticks apply a bounded process execution timeout", async () => {
  const result = await tick("bulk", 2500);

  expect(result.tick_status).toBe("idle");
  expect(result.execution_timeout_seconds).toBe(33);
});

test("claim/heartbeat/cursor resume across ticks (budget-limited yields)", async () => {
  const job = seedJob({ type: "test_counter", lane: "bulk", payload: { target: 3 } });

  const first = await tick("bulk", 0);
  expect(first.job?.id).toBe(job.id);
  expect(first.tick_status).toBe("yielded");
  let record = readJob(job.id);
  expect(record.status).toBe("pending");
  expect(record.cursor.count).toBe(1);
  expect(typeof record.heartbeat).toBe("number");

  const second = await tick("bulk", 0);
  expect(second.job?.id).toBe(job.id);
  expect(second.tick_status).toBe("yielded");
  record = readJob(job.id);
  expect(record.cursor.count).toBe(2);

  const third = await tick("bulk", 0);
  expect(third.job?.id).toBe(job.id);
  expect(third.tick_status).toBe("complete");
  expect(third.job?.status).toBe("complete");
  record = readJob(job.id);
  expect(record.status).toBe("complete");
  expect(record.cursor.count).toBe(3);
  expect(record.result).toEqual({ count: 3 });
});

test("targeted bulk ticks advance the requested pending job", async () => {
  const older = seedJob({ type: "test_counter", lane: "bulk", payload: { target: 1 } });
  const selected = seedJob({ type: "test_counter", lane: "bulk", payload: { target: 1 } });

  const result = await tick("bulk", 1000, selected.id);

  expect(result.job?.id).toBe(selected.id);
  expect(result.job?.status).toBe("complete");
  expect(readJob(older.id).status).toBe("pending");
});

test("poison job dead-letters after max_attempts and fires a job_failed callback", async () => {
  const job = seedJob({
    type: "test_poison",
    lane: "bulk",
    max_attempts: 2,
    payload: {
      _claims: {
        operation_id: "op_poison",
        callback_url: `http://127.0.0.1:${receiver.port}/`,
        callback_token: "poison-secret",
      },
    },
  });

  const first = await tick("bulk", 5000);
  expect(first.job?.id).toBe(job.id);
  expect(first.tick_status).toBe("retry_scheduled");
  expect(first.job?.status).toBe("pending");
  let record = readJob(job.id);
  expect(record.status).toBe("pending");
  expect(record.attempt).toBe(1);
  expect(record.not_before).not.toBeNull();

  // Real backoff is minutes; the retry/dead-letter transition itself is what's
  // under test here, not the wall-clock wait, so make the job eligible again
  // immediately rather than sleeping through a real backoff window.
  record.not_before = null;
  writeFileSync(jobPath(job.id), JSON.stringify(record));

  const second = await tick("bulk", 5000);
  expect(second.job?.id).toBe(job.id);
  expect(second.tick_status).toBe("dead_letter");
  expect(second.job?.status).toBe("failed");
  expect(existsSync(jobPath(job.id))).toBe(false);
  const dead = readJob(job.id);
  expect(dead.status).toBe("failed");
  expect(dead.error?.code).toBe("test_poison_failure");

  const delivered = receivedCallbacks.find(
    (entry) => entry.authorization === "Bearer poison-secret",
  );
  expect(delivered).toBeDefined();
  const event = (delivered?.body as { event?: Record<string, unknown> } | null)?.event;
  expect(event?.event).toBe("job_failed");
  expect(event?.job_id).toBe(job.id);
  // Wire shape the control plane's asEngineJobCallbackEvent actually parses
  // (job_type/lane/nested error, not the bare type/code the engine used to send).
  expect(event?.job_type).toBe("test_poison");
  expect(event?.lane).toBe("bulk");
  expect(event?.error).toEqual({ code: "test_poison_failure", message: "test_poison_failure" });
});

test("reap requeues an orphaned running job past the heartbeat timeout, and it resumes cleanly", async () => {
  const job = seedJob({
    type: "test_counter",
    lane: "bulk",
    status: "running",
    heartbeat: Math.floor(Date.now() / 1000) - 200, // past the 120s timeout
    payload: { target: 2 },
  });

  // The tick's own reap pass (not the stepper) discovers the orphan: nothing
  // else is eligible to claim since the reaped job's backoff pushed
  // not_before into the future, so the tick itself is idle.
  const reaped = await tick("bulk", 1000);
  expect(reaped.tick_status).toBe("idle");
  expect(reaped.job).toBeNull();

  let record = readJob(job.id);
  expect(record.status).toBe("pending");
  expect(record.attempt).toBe(1);
  expect(record.heartbeat).toBeNull();
  expect(record.first_failed_at).not.toBeNull();
  expect(record.not_before).not.toBeNull();
  expect(record.error?.code).toBe("heartbeat_timeout");
  expect(record.cursor.count).toBeUndefined(); // untouched by the reap itself

  // Real backoff is ~10s; what's under test is the reap transition and that
  // the job resumes correctly afterward, not the wall-clock wait.
  record.not_before = null;
  writeFileSync(jobPath(job.id), JSON.stringify(record));

  const resumed = await tick("bulk", 0);
  expect(resumed.job?.id).toBe(job.id);
  expect(resumed.tick_status).toBe("yielded");
  record = readJob(job.id);
  expect(record.cursor.count).toBe(1);

  const completed = await tick("bulk", 0);
  expect(completed.tick_status).toBe("complete");
  record = readJob(job.id);
  expect(record.status).toBe("complete");
  expect(record.result).toEqual({ count: 2 });
});

test("reap dead-letters an orphaned running job once max_attempts is exhausted, firing job_failed", async () => {
  const job = seedJob({
    type: "test_counter",
    lane: "bulk",
    status: "running",
    heartbeat: Math.floor(Date.now() / 1000) - 200,
    max_attempts: 1,
    attempt: 0,
    payload: {
      target: 2,
      _claims: {
        operation_id: "op_reap_dead_letter",
        callback_url: `http://127.0.0.1:${receiver.port}/`,
        callback_token: "reap-secret",
      },
    },
  });

  const result = await tick("bulk", 1000);
  expect(result.tick_status).toBe("idle");
  expect(result.job).toBeNull();
  expect(existsSync(jobPath(job.id))).toBe(false);

  const dead = readJob(job.id);
  expect(dead.status).toBe("failed");
  expect(dead.error?.code).toBe("heartbeat_timeout");
  expect(dead.attempt).toBe(1);

  const delivered = receivedCallbacks.find((entry) => entry.authorization === "Bearer reap-secret");
  expect(delivered).toBeDefined();
  const event = (delivered?.body as { event?: Record<string, unknown> } | null)?.event;
  expect(event?.event).toBe("job_failed");
  expect(event?.job_id).toBe(job.id);
  expect(event?.job_type).toBe("test_counter");
  expect(event?.lane).toBe("bulk");
  expect(event?.error).toEqual({ code: "heartbeat_timeout", message: "heartbeat_timeout" });
});

test("lane isolation: a held bulk lock never blocks an interactive tick", async () => {
  const lockDir = path.join(rt.storageRoot, "runtime/jobs");
  mkdirSync(lockDir, { recursive: true });
  const lockPath = path.join(lockDir, "lane-bulk.lock");
  const markerPath = path.join(
    lockDir,
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

  try {
    const bulkJob = seedJob({ type: "test_counter", lane: "bulk", payload: { target: 1 } });
    const bulkResult = await tick("bulk", 1000);
    expect(bulkResult.tick_status).toBe("lane_locked");
    expect(bulkResult.job).toBeNull();
    // The bulk job is untouched — the locked tick never claimed it.
    expect(readJob(bulkJob.id).status).toBe("pending");

    const interactiveJob = seedJob({
      type: "test_counter",
      lane: "interactive",
      payload: { target: 1 },
    });
    const interactiveResult = await tick("interactive", 1000);
    expect(interactiveResult.tick_status).toBe("complete");
    expect(interactiveResult.job?.id).toBe(interactiveJob.id);
  } finally {
    await new Promise((resolve) => holder.on("exit", resolve));
  }
});

test("two concurrent bulk ticks never double-claim the same job", async () => {
  // Racing two independent `php -S` worker dispatches on wall-clock timing
  // alone is exactly the flaky-timing pattern the house rules ban — instead,
  // fire the first tick, then poll the job's OWN on-disk record (a real
  // signal, not a sleep) until it flips to 'running' before firing the
  // second: this deterministically guarantees genuine overlap regardless of
  // OS scheduling, because the first tick is provably still inside its
  // step_delay_us-long stepper call (holding the lane lock) at that point.
  const job = seedJob({
    type: "test_counter",
    lane: "bulk",
    payload: { target: 2, step_delay_us: 1500000 }, // ~3s of work per claim
  });

  const firstPromise = tick("bulk", 5000);
  const deadline = Date.now() + 5000;
  while (readJob(job.id).status !== "running") {
    if (Date.now() > deadline) {
      throw new Error("job never reached 'running' before the deadline");
    }
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  const secondPromise = tick("bulk", 5000);

  const [a, b] = await Promise.all([firstPromise, secondPromise]);
  const statuses = [a.tick_status, b.tick_status].toSorted();
  const ranIds = [a.job?.id ?? null, b.job?.id ?? null];

  // Exactly one dispatch claimed the job; the other found the lane locked.
  expect(ranIds.filter((id) => id === job.id)).toHaveLength(1);
  expect(ranIds.filter((id) => id === null)).toHaveLength(1);
  expect(statuses).toContain("lane_locked");

  const record = readJob(job.id);
  expect(record.cursor.count).toBeLessThanOrEqual(2);
  expect(record.cursor.count).toBeGreaterThan(0);
});

test("bulk tick self-drains pending callbacks with no control-plane drain call", async () => {
  const callbackId = seedPendingCallback({
    callbackUrl: `http://127.0.0.1:${receiver.port}/`,
    callbackToken: "self-drain-secret",
    event: { event: "tier_hit", path: "assets/app.js" },
  });

  const result = await tick("bulk", 1000);
  expect(result.tick_status).toBe("idle");
  expect(result.drained).toBe(true);

  const delivered = receivedCallbacks.find(
    (entry) => entry.authorization === "Bearer self-drain-secret",
  );
  expect(delivered).toBeDefined();
  expect((delivered?.body as { event?: Record<string, unknown> } | null)?.event?.event).toBe(
    "tier_hit",
  );
  expect(
    existsSync(path.join(rt.storageRoot, "runtime/callbacks/pending", `${callbackId}.json`)),
  ).toBe(false);
  expect(
    existsSync(path.join(rt.storageRoot, "runtime/callbacks/delivered", `${callbackId}.json`)),
  ).toBe(true);
});

test("self-drain keeps an unreachable-origin callback retryable in pending/ instead of dropping it via the pull fallback", async () => {
  // Port 1 is refused without root: a deterministic, immediate "unreachable
  // origin" without a real network timeout.
  const callbackId = seedPendingCallback({
    callbackUrl: "http://127.0.0.1:1/",
    callbackToken: "unreachable-secret",
    event: { event: "tier_hit", path: "assets/unreachable.js" },
  });
  const pendingPath = path.join(rt.storageRoot, "runtime/callbacks/pending", `${callbackId}.json`);
  const deliveredPath = path.join(
    rt.storageRoot,
    "runtime/callbacks/delivered",
    `${callbackId}.json`,
  );

  const result = await tick("bulk", 5000);
  expect(result.tick_status).toBe("idle");
  expect(result.drained).toBe(true);

  // Self-drain has no HTTP caller to hand the event back to (unlike
  // /events/drain's pull fallback), so a push failure must leave the callback
  // retryable on disk, not move it to delivered/ as 'returned' and lose it.
  expect(existsSync(pendingPath)).toBe(true);
  expect(existsSync(deliveredPath)).toBe(false);
  const callback = JSON.parse(readFileSync(pendingPath, "utf8")) as {
    status: string;
    attempts: number;
  };
  expect(callback.status).toBe("pending");
  expect(callback.attempts).toBe(1);

  // It is genuinely retryable: a later drain against a reachable receiver
  // still delivers it.
  const rewritten = JSON.parse(readFileSync(pendingPath, "utf8")) as Record<string, unknown>;
  rewritten.callback_url = `http://127.0.0.1:${receiver.port}/`;
  writeFileSync(pendingPath, JSON.stringify(rewritten));

  const second = await tick("bulk", 5000);
  expect(second.drained).toBe(true);
  const delivered = receivedCallbacks.find(
    (entry) => entry.authorization === "Bearer unreachable-secret",
  );
  expect(delivered).toBeDefined();
  expect(existsSync(pendingPath)).toBe(false);
  expect(existsSync(deliveredPath)).toBe(true);
});

test("interactive tick never self-drains (bulk-lane-only per §22)", async () => {
  const callbackId = seedPendingCallback({
    callbackUrl: `http://127.0.0.1:${receiver.port}/`,
    callbackToken: "not-drained-secret",
    event: { event: "tier_hit", path: "assets/should-not-drain.js" },
  });

  const result = await tick("interactive", 1000);
  expect(result.drained).toBe(false);

  expect(
    receivedCallbacks.some((entry) => entry.authorization === "Bearer not-drained-secret"),
  ).toBe(false);
  expect(
    existsSync(path.join(rt.storageRoot, "runtime/callbacks/pending", `${callbackId}.json`)),
  ).toBe(true);
});

test("GET /jobs/{jobId} returns {job} minus payload secrets, 404 for unknown ids", async () => {
  const job = seedJob({
    type: "test_counter",
    lane: "bulk",
    payload: { target: 2, _claims: { callback_token: "super-secret", operation_id: "op_x" } },
  });

  const response = await api(rt, "GET", `/__spacefast/api.php/jobs/${job.id}`, "get_engine_job", {
    job_id: job.id,
  });
  expect(response.status).toBe(200);
  const body = (await response.json()) as { job: JobRecord };
  expect(body.job.id).toBe(job.id);
  expect(body.job.payload._claims).toBeUndefined();
  expect(body.job.payload.target).toBe(2);

  const missing = await api(
    rt,
    "GET",
    "/__spacefast/api.php/jobs/job_does_not_exist",
    "get_engine_job",
    { job_id: "job_does_not_exist" },
  );
  expect(missing.status).toBe(404);
});

test("dead-lettered jobs are still readable via GET /jobs/{jobId}", async () => {
  const job = seedJob({ type: "test_poison", lane: "bulk", max_attempts: 1, payload: {} });
  const result = await tick("bulk", 5000);
  expect(result.tick_status).toBe("dead_letter");

  const response = await api(rt, "GET", `/__spacefast/api.php/jobs/${job.id}`, "get_engine_job", {
    job_id: job.id,
  });
  expect(response.status).toBe(200);
  const body = (await response.json()) as { job: JobRecord };
  expect(body.job.status).toBe("failed");
});

test("invalid lane is rejected before any lock is touched", async () => {
  const response = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=nope",
    "tick_engine_jobs",
    {},
  );
  expect(response.status).toBe(422);
  const body = (await response.json()) as { error?: { code?: string } };
  expect(body.error?.code).toBe("invalid_lane");
});

test("POST /jobs creates a job scoped by JWT claims and upserts by idempotency_key", async () => {
  const idempotencyKey = `idem-create-${Math.random().toString(16).slice(2)}`;
  const createBody = {
    type: "test_counter",
    payload: { target: 2 },
    idempotency_key: idempotencyKey,
  };

  const created = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: "spc_create_route" },
    createBody,
    201,
  );
  expect(created.job.type).toBe("test_counter");
  expect(created.job.lane).toBe("bulk");
  expect(created.job.status).toBe("pending");
  expect(created.job.space_id).toBe("spc_create_route");
  expect(created.job.payload.target).toBe(2);
  expect(created.job.payload._claims).toBeUndefined();

  // Upsert: the same idempotency_key returns the ORIGINAL job, not a new one,
  // even with a different payload on the second call.
  const again = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: "spc_create_route" },
    { type: "test_counter", payload: { target: 999 }, idempotency_key: idempotencyKey },
    201,
  );
  expect(again.job.id).toBe(created.job.id);
  expect(again.job.payload.target).toBe(2);

  // The created job is drivable through the real tick route end to end.
  const first = await tick("bulk", 0);
  expect(first.job?.id).toBe(created.job.id);
  const second = await tick("bulk", 0);
  expect(second.job?.id).toBe(created.job.id);
  expect(second.job?.status).toBe("complete");
});

test("POST /jobs rejects an unknown job type", async () => {
  const response = await api(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    {},
    { type: "not_a_real_type", payload: {}, idempotency_key: "idem-bad-type" },
  );
  expect(response.status).toBe(422);
  const body = (await response.json()) as { error?: { code?: string } };
  expect(body.error?.code).toBe("unknown_job_type");
});

test("bulk tick housekeeping prunes dead-lettered jobs past the 14-day retention", async () => {
  const stale = seedJob({ type: "test_poison", lane: "bulk", max_attempts: 1, payload: {} });
  const fresh = seedJob({ type: "test_poison", lane: "bulk", max_attempts: 1, payload: {} });

  // Dead-letter both, then backdate only the stale one's file past retention.
  await tick("bulk", 5000);
  expect(readJob(stale.id).status).toBe("failed");
  const staleDeadPath = path.join(jobsDeadDir(), `${stale.id}.json`);
  const old = new Date(Date.now() - 15 * 24 * 60 * 60 * 1000);
  utimesSync(staleDeadPath, old, old);

  await tick("bulk", 5000);
  expect(readJob(fresh.id).status).toBe("failed");

  // A housekeeping-only tick (idle lane) is what actually prunes it.
  await tick("bulk", 1000);
  expect(existsSync(staleDeadPath)).toBe(false);
  expect(existsSync(path.join(jobsDeadDir(), `${fresh.id}.json`))).toBe(true);
});
