// Storage tiering on the schema-v4 engine (contracts §8, D14–D21/D68–D74/
// D90–D93/D117–D119, §15/§17).
//
// The model these tests hold, and nothing else:
//   * the CAS is the only byte store. There is no version file tree, no
//     file-shard entry carrying `local`/`remote`/`tier_class`, no per-file
//     locator — a blob is `spaces/<s>/blobs/<aa>/<sha>` and the disk is the
//     truth (D14/D15).
//   * demote UPLOADS and MARKS. It never unlinks (D91); the GC releases a
//     marked body after grace, so no reader can lose bytes under itself.
//   * a released blob comes back through promote-on-read: admission, one S3
//     GET, a digest check, a CAS install (D17/D68/D69). Failure or shed is a
//     503 — never a partial body, never an install of unverified bytes.
//   * collection orders by PUBLISH time only. §17 removed read recency
//     entirely (no read-notes, no recency index, nothing on the hot path);
//     §18 removed the local byte budget/eviction lane — demote is the ONLY
//     thing that sends a live blob to S3, and only when explicitly asked.
//     A wrongly demoted live blob self-heals with one promote.
//   * a blob file's time is never written as bookkeeping — it is the accel-lane
//     validator (§15/D132), so the one thing asserted about it is that PHP's
//     install lands on exactly the stamp the Rust finalizer used.
//
// Deliberately NOT here (contracts §14): ranges, HEAD, 206 and 304. The
// platform strips Range/If-None-Match before PHP and answers conditionals at
// the edge (§16), so a local `php -S` proves nothing about them.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { randomBytes } from "node:crypto";
import {
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

import {
  api,
  apiJson,
  blobPath,
  createDeclaredSession,
  deploy,
  get,
  journalRecords,
  publicAccessConfig,
  putBlob,
  readBlob,
  responseEntry,
  sha256,
  startRuntime,
  storagePath,
  versionRoot,
  type Runtime,
} from "./harness.ts";
import { startFakeS3, type FakeS3 } from "./s3-fake.ts";

type JobRecord = { id: string; status: string; result?: unknown };

type TickResponse = { job: JobRecord | null };

let fake: FakeS3;
/** GC grace 0: a marked body is released by the next maintenance pass. */
let rt: Runtime;
let seq = 0;

// One row, so `_stattic_s3_default_bucket_id()` resolves without a pin — the
// shape the control plane pushes (exactly one active bucket per site). The
// registry default is `unverified`, the row that makes no integrity promise and
// therefore has to be read back before any local byte is released.
function bucketsJson(): string {
  return JSON.stringify([
    {
      id: "tier-bucket",
      endpoint: fake.url,
      region: "us-east-1",
      bucket: fake.bucket,
      urlStyle: "path",
      getKeyId: "GETKEY",
      getKeySecret: "get-secret",
      putKeyId: "PUTKEY",
      putKeySecret: "put-secret",
      integrity: "unverified",
    },
  ]);
}

beforeAll(async () => {
  fake = await startFakeS3("tier-test-bucket");
  rt = await startRuntime({
    atomicData: { SPACEFAST_STORAGE_BUCKETS_JSON: bucketsJson() },
    env: {
      SPACEFAST_TIERING_ENABLED: "1",
      // Collect on the spot: every maintenance pass in this suite is meant to
      // finish the mark-then-delete cycle rather than leave it half-done.
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
      // The undeclared lane keeps a grace floor of its own (it covers the
      // finalizer's install-then-declare window, which grace=0 would otherwise
      // close to nothing). This suite really does want same-tick collection, so
      // it opts out explicitly.
      SPACEFAST_LOCAL_BLOB_GC_UNDECLARED_MIN_GRACE_SECONDS: "0",
      // The shed test seeds the promote admission counter directly, so pin the
      // file backend: on a host whose php ships apcu with apc.enabled=1 the
      // acquire would read apcu and ignore the seeded file.
      SPACEFAST_ADMISSION_COUNTER_BACKEND: "file",
      SPACEFAST_TIER_PROMOTE_CONC_PER_SPACE: "1",
    },
  });
});

afterAll(() => {
  rt?.stop();
  fake?.stop();
});

function nextId(prefix: string): string {
  seq += 1;
  return `${prefix}_${seq}`;
}

function hostFor(spaceId: string): string {
  return `${spaceId.replaceAll("_", "-")}.test`;
}

/** D16: derived at every call site, never stored — not in an entry, not in a payload. */
function objectKey(spaceId: string, sha: string): string {
  return `spaces/${spaceId}/blobs/${sha.slice(0, 2)}/${sha}`;
}

function demoteMarkPath(runtime: Runtime, spaceId: string, sha: string): string {
  return `${blobPath(runtime, spaceId, sha)}.demote`;
}

/** Every blob body this space currently holds locally (marks are not bodies). */
function casBlobs(runtime: Runtime, spaceId: string): string[] {
  const root = storagePath(runtime, "spaces", spaceId, "blobs");
  if (!existsSync(root)) return [];
  const shas: string[] = [];
  for (const prefix of readdirSync(root)) {
    for (const entry of readdirSync(path.join(root, prefix))) {
      if (/^[a-f0-9]{64}$/.test(entry)) shas.push(entry);
    }
  }
  return shas;
}

function casBytes(runtime: Runtime, spaceId: string): number {
  return casBlobs(runtime, spaceId).reduce(
    (total, sha) => total + statSync(blobPath(runtime, spaceId, sha)).size,
    0,
  );
}

/** The blob a compiled entry names — the only reference the serve path follows. */
function entrySha(runtime: Runtime, spaceId: string, versionId: string, key: string): string {
  const sha = responseEntry(runtime, spaceId, versionId, key)?.b;
  if (typeof sha !== "string") {
    throw new Error(`response entry ${key} of ${versionId} names no blob`);
  }
  return sha;
}

async function createJob(
  runtime: Runtime,
  type: string,
  spaceId: string,
  payload: Record<string, unknown>,
): Promise<JobRecord> {
  const created = await apiJson<{ job: JobRecord }>(
    runtime,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: spaceId },
    { type, idempotency_key: `${type}:${spaceId}:${nextId("idem")}`, payload },
    201,
  );
  return created.job;
}

/** One bulk tick: claims a job if there is one, then runs the maintenance pass. */
async function tick(runtime: Runtime): Promise<TickResponse> {
  return apiJson<TickResponse>(
    runtime,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=50000",
    "tick_engine_jobs",
    {},
  );
}

async function runJob(runtime: Runtime, jobId: string, maxTicks = 30): Promise<JobRecord> {
  for (let i = 0; i < maxTicks; i += 1) {
    const response = await tick(runtime);
    if (response.job?.id === jobId && ["complete", "failed"].includes(response.job.status)) {
      return response.job;
    }
  }
  throw new Error(`job ${jobId} did not finish`);
}

/**
 * The one demote operation (contracts §10). `shas` absent means "release this
 * space's cold bytes"; a list targets exactly those blobs.
 */
async function demote(runtime: Runtime, spaceId: string, shas?: string[]): Promise<JobRecord> {
  const job = await createJob(runtime, "tier_demote", spaceId, {
    space_id: spaceId,
    ...(shas ? { shas } : {}),
  });
  return runJob(runtime, job.id);
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
    files: { "index.html": "<html><body>tier</body></html>", ...files },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}

// The admission counter's on-disk layout (shared/admission.php): a generation
// pointer plus one count file per generation. Seeding it is how a test holds a
// slot without a second concurrent request.
function writeAdmissionCounter(counterPath: string, count: number): void {
  mkdirSync(path.dirname(counterPath), { recursive: true });
  const pointerPath = `${counterPath}.generation`;
  const pointer = existsSync(pointerPath)
    ? (JSON.parse(readFileSync(pointerPath, "utf8")) as { generation?: string })
    : {};
  const generation =
    typeof pointer.generation === "string" ? pointer.generation : randomBytes(16).toString("hex");
  writeFileSync(pointerPath, `${JSON.stringify({ generation })}\n`);
  writeFileSync(
    `${counterPath}.${generation}`,
    `${JSON.stringify({ count, updated_at: Math.floor(Date.now() / 1000) })}\n`,
  );
}

function promoteAdmissionCounterPath(runtime: Runtime, spaceId: string): string {
  return storagePath(runtime, "runtime", "admission", `tier_promote-${spaceId}.json`);
}

test("demote syncs every live blob to its derived key, unlinks it, and leaves the mark for lazy rehydration", async () => {
  const spaceId = nextId("spc_tier_demote");
  const versionId = nextId("ver_tier_demote");
  const host = hostFor(spaceId);
  const body = "x".repeat(40000);
  await deployFixture(rt, spaceId, versionId, host, { "assets/big.txt": body });

  const sha = entrySha(rt, spaceId, versionId, "/assets/big.txt");
  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(body);
  const before = casBlobs(rt, spaceId);
  const bytesBefore = casBytes(rt, spaceId);

  fake.requests.splice(0);
  const completed = await demote(rt, spaceId);
  expect(completed.status).toBe("complete");
  // No `shas`, so the whole space is released: every local blob, counted once.
  expect(completed.result).toEqual({ bytesMoved: bytesBefore, blobCount: before.length });

  const key = objectKey(spaceId, sha);
  expect(fake.getObject(key)?.body.toString("utf8")).toBe(body);
  expect(fake.requests.filter((r) => r.method === "PUT" && r.path.endsWith(key))).toHaveLength(1);
  // An `unverified` bucket makes no integrity promise, so the demoter reads the
  // object back before any local byte is released.
  expect(fake.requests.some((r) => r.method === "HEAD" && r.path.endsWith(key))).toBe(true);

  // The explicit demote operation releases the site's disk immediately after
  // the verified sync. The mark is the evidence that S3 holds these bytes.
  expect(readBlob(rt, spaceId, sha)).toBeNull();
  expect(existsSync(demoteMarkPath(rt, spaceId, sha))).toBe(true);
  expect(casBlobs(rt, spaceId)).toEqual([]);

  const events = journalRecords(rt);
  expect(events.some((e) => e.event === "space.tier.demoted" && e.space_id === spaceId)).toBe(true);
  expect(
    events.some(
      (e) =>
        e.event === "local_blob_gc" && [...(e.evicted ?? []), ...(e.collected ?? [])].includes(sha),
    ),
  ).toBe(false);
});

test("a released blob promotes back on read at the finalizer's content-derived stamp, and the next read never dials", async () => {
  const spaceId = nextId("spc_tier_promote");
  const versionId = nextId("ver_tier_promote");
  const host = hostFor(spaceId);
  const body = "promote-me\n".repeat(4000);
  await deployFixture(rt, spaceId, versionId, host, { "assets/big.txt": body });

  const sha = entrySha(rt, spaceId, versionId, "/assets/big.txt");
  // nginx derives `ETag: "<hex mtime>-<hex size>"` from the inode, so identical
  // content must land on identical times whichever implementation installed it.
  const finalizedMtimeMs = statSync(blobPath(rt, spaceId, sha)).mtimeMs;
  expect((await demote(rt, spaceId, [sha])).status).toBe("complete");
  expect(readBlob(rt, spaceId, sha)).toBeNull();

  fake.requests.splice(0);
  const served = await get(rt, host, "/assets/big.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(body);
  expect(
    fake.requests.some((r) => r.method === "GET" && r.path.endsWith(objectKey(spaceId, sha))),
  ).toBe(true);

  // The bytes land in the CAS first — a promote is never a stream from S3 to
  // the visitor — carrying the same stamp the Rust finalizer wrote.
  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(body);
  expect(statSync(blobPath(rt, spaceId, sha)).mtimeMs).toBe(finalizedMtimeMs);
  // The mark goes with the reinstall, or the next GC pass would collect these
  // bytes again and every request would pay another S3 GET.
  expect(existsSync(demoteMarkPath(rt, spaceId, sha))).toBe(false);

  fake.requests.splice(0);
  const again = await get(rt, host, "/assets/big.txt");
  expect(again.status).toBe(200);
  expect(await again.text()).toBe(body);
  expect(fake.requests).toHaveLength(0);
});

test("promote refuses a bucket object whose bytes do not match its key: 503, nothing installed, nothing served", async () => {
  const spaceId = nextId("spc_tier_mismatch");
  const versionId = nextId("ver_tier_mismatch");
  const host = hostFor(spaceId);
  const body = "verify-me\n".repeat(4000);
  await deployFixture(rt, spaceId, versionId, host, { "assets/big.txt": body });

  const sha = entrySha(rt, spaceId, versionId, "/assets/big.txt");
  expect((await demote(rt, spaceId, [sha])).status).toBe("complete");
  // The bucket object drifts away from the key it is filed under. D69: the
  // digest is computed while downloading and a mismatch is dropped, never
  // installed and never served — the alternative is serving whatever an
  // attacker or a corrupted bucket put at a derivable key.
  fake.putObject(objectKey(spaceId, sha), Buffer.from("tampered"));

  const shed = await get(rt, host, "/assets/big.txt");
  expect(shed.status).toBe(503);
  expect(shed.headers.get("retry-after")).toBe("5");
  expect(readBlob(rt, spaceId, sha)).toBeNull();

  // Not a one-request fluke: the entry stays unservable while the object is
  // wrong, rather than degrading to the tampered bytes on a retry.
  const retried = await get(rt, host, "/assets/big.txt");
  expect(retried.status).toBe(503);
  await retried.text();

  const failure = journalRecords(rt).find(
    (e) => e.event === "tier_promote_failed" && e.sha256 === sha,
  );
  expect(failure?.reason).toBe("blob_sha_mismatch");
});

test("promote sheds above the per-space concurrency cap: 503 without dialing the bucket", async () => {
  const spaceId = nextId("spc_tier_shed");
  const versionId = nextId("ver_tier_shed");
  const host = hostFor(spaceId);
  const body = "shed-me\n".repeat(4000);
  await deployFixture(rt, spaceId, versionId, host, { "assets/big.txt": body });

  const sha = entrySha(rt, spaceId, versionId, "/assets/big.txt");
  expect((await demote(rt, spaceId, [sha])).status).toBe("complete");

  // D68: admission IS the breaker. With the one slot of this suite's cap held,
  // a cold space under load sheds instead of queueing every worker on the same
  // bucket — and it sheds BEFORE the round trip, not after it times out.
  const counterPath = promoteAdmissionCounterPath(rt, spaceId);
  writeAdmissionCounter(counterPath, 1);
  fake.requests.splice(0);
  const capped = await get(rt, host, "/assets/big.txt");
  expect(capped.status).toBe(503);
  expect(capped.headers.get("retry-after")).toBe("5");
  expect(fake.requests).toHaveLength(0);
  await capped.text();

  writeAdmissionCounter(counterPath, 0);
  const admitted = await get(rt, host, "/assets/big.txt");
  expect(admitted.status).toBe(200);
  expect(await admitted.text()).toBe(body);

  expect(journalRecords(rt).some((e) => e.event === "tier_promote_shed" && e.sha256 === sha)).toBe(
    true,
  );
});

test("a publish session in flight keeps its declared bytes out of the GC's reach", async () => {
  // D92: the bytes of a version that does not exist yet are named by nothing the
  // GC's live-set walk would otherwise find. Without the session's declaration
  // and its pin, a maintenance pass landing mid-publish collects them and
  // finalize fails on bytes the uploader already delivered.
  const spaceId = nextId("spc_tier_pin");
  const versionId = nextId("ver_tier_pin");
  const content = "pinned-bytes\n".repeat(3000);
  const sha = sha256(content);

  const session = await createDeclaredSession(rt, spaceId, versionId, {
    "assets/pinned.txt": content,
  });
  const put = await putBlob(rt, spaceId, session.token, sha, content);
  expect(put.status).toBe(200);
  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(content);

  await tick(rt);

  expect(readBlob(rt, spaceId, sha)?.toString("utf8")).toBe(content);
  expect(
    journalRecords(rt).some(
      (e) =>
        e.event === "local_blob_gc" && [...(e.collected ?? []), ...(e.evicted ?? [])].includes(sha),
    ),
  ).toBe(false);
});

test("the maintenance tick refuses to prune a routed version, trashes the unrouted one, collects the blobs it alone referenced, and reports disk usage", async () => {
  const spaceId = nextId("spc_tier_prune");
  const versionOld = nextId("ver_tier_prune_old");
  const versionLive = nextId("ver_tier_prune_live");
  const host = hostFor(spaceId);
  const oldBody = "only-in-the-old-version\n".repeat(1500);
  // A private path: it answers no URL, so no response table names its bytes and
  // the version's catalog is the ONLY thing keeping them alive. The sweep is
  // shape-agnostic by design (tier.php `_stattic_tier_collect_shas`), and this
  // is what proves it still reaches a source-only object.
  const privateBody = "never served, still ours\n";
  const privateSha = sha256(privateBody);
  await deployFixture(rt, spaceId, versionOld, host, { "assets/old.txt": oldBody });
  await deployFixture(rt, spaceId, versionLive, host, {
    "assets/live.txt": "still routed",
    ".hidden/notes.txt": privateBody,
  });
  const oldSha = entrySha(rt, spaceId, versionOld, "/assets/old.txt");
  const liveSha = entrySha(rt, spaceId, versionLive, "/assets/live.txt");

  // Retention policy arrives through the management write surface, never a raw
  // file drop, and an invalid body never lands.
  const invalid = await api(
    rt,
    "PUT",
    `/__spacefast/api.php/spaces/${spaceId}/retention-policy`,
    "update_retention_policy",
    { space_id: spaceId },
    { prunable_version_ids: "nope" },
  );
  expect(invalid.status).toBe(422);
  const policy = await apiJson<{ space_id: string; prunable_count: number }>(
    rt,
    "PUT",
    `/__spacefast/api.php/spaces/${spaceId}/retention-policy`,
    "update_retention_policy",
    { space_id: spaceId },
    { prunable_version_ids: [versionOld, versionLive] },
  );
  expect(policy.space_id).toBe(spaceId);
  expect(policy.prunable_count).toBe(2);

  await tick(rt);

  // The engine re-checks the space's route pointers before it moves anything:
  // the control plane listed the routed version, and the engine refuses it.
  expect(existsSync(versionRoot(rt, spaceId, versionOld))).toBe(false);
  expect(existsSync(versionRoot(rt, spaceId, versionLive))).toBe(true);
  const pruned = journalRecords(rt).find(
    (e) => e.event === "space.versions.pruned" && e.spaceId === spaceId,
  );
  expect(pruned?.versionIds).toEqual([versionOld]);
  expect(pruned?.refusedVersionIds).toEqual([versionLive]);

  // The pruned version was the only declaration naming those bytes, so the same
  // pass collects them as garbage. The routed version's blob is untouched and
  // still serves — the live set is read from what survived, not from a list.
  expect(readBlob(rt, spaceId, oldSha)).toBeNull();
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

test("tiering is disabled by default: jobs and promote-on-read move no bytes", async () => {
  const parked = await startRuntime({
    atomicData: { SPACEFAST_STORAGE_BUCKETS_JSON: bucketsJson() },
    env: {
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
    },
  });
  try {
    const spaceId = nextId("spc_tiering_parked");
    const versionId = nextId("ver_tiering_parked");
    const host = hostFor(spaceId);
    const body = "wp-cloud-local\n".repeat(4000);
    await deployFixture(parked, spaceId, versionId, host, { "assets/local.txt": body });
    const sha = entrySha(parked, spaceId, versionId, "/assets/local.txt");
    const key = objectKey(spaceId, sha);

    fake.requests.splice(0);
    const rejected = await api(
      parked,
      "POST",
      "/__spacefast/api.php/jobs",
      "create_engine_job",
      { space_id: spaceId },
      {
        type: "tier_demote",
        idempotency_key: `tier-parked:${spaceId}`,
        payload: { space_id: spaceId },
      },
    );
    expect(rejected.status).toBe(422);
    expect(((await rejected.json()) as { code?: string }).code).toBe("tiering_disabled");

    await tick(parked);
    expect(readBlob(parked, spaceId, sha)?.toString("utf8")).toBe(body);
    expect(existsSync(demoteMarkPath(parked, spaceId, sha))).toBe(false);
    expect(fake.getObject(key)).toBeUndefined();
    expect(fake.requests).toHaveLength(0);

    // Even a pre-existing remote-only reference cannot dial or install while
    // the switch is off. Re-enabling later retains the original recovery path.
    fake.putObject(key, Buffer.from(body));
    rmSync(blobPath(parked, spaceId, sha));
    writeFileSync(demoteMarkPath(parked, spaceId, sha), "{}\n");
    fake.requests.splice(0);
    const served = await get(parked, host, "/assets/local.txt");
    expect(served.status).toBe(503);
    expect(readBlob(parked, spaceId, sha)).toBeNull();
    expect(fake.requests).toHaveLength(0);
  } finally {
    parked.stop();
  }
});
