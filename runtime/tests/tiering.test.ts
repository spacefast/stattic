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
import { brotliCompressSync } from "node:zlib";

import {
  api,
  apiJson,
  createDeclaredSession,
  deploy,
  get,
  managementToken,
  putFile,
  sha256,
  shardFiles,
  startRuntime,
  uploadToken,
  type Runtime,
} from "./harness.ts";
import { startFakeS3, type FakeS3 } from "./s3-fake.ts";

type BucketRow = {
  id: string;
  endpoint: string;
  region: string;
  bucket: string;
  urlStyle: "path";
  getKeyId: string;
  getKeySecret: string;
  putKeyId: string;
  putKeySecret: string;
  integrity: "server_verified" | "unverified";
};

type JobRecord = {
  id: string;
  status: string;
  result?: unknown;
  progress?: { done: number; total: number };
};

type TickResponse = { lane: string; job: JobRecord | null; tick_status: string };

// Staged callback envelope written under runtime/callbacks/pending/*.json
// (see `_stattic_runtime_append_journal` callers in shared/storage.php): a
// callback_token plus the arbitrary journal-shaped event it wraps.
type PendingCallback = {
  callback_token?: string;
  event?: {
    event?: string;
    bucket?: string;
    spaceId?: string;
    inodes?: number;
  };
};

let fake: FakeS3;
let rt: Runtime;
let bucket: BucketRow;
let unverifiedBucket: BucketRow;
let seq = 0;

function writeAdmissionFileCounter(counterPath: string, count: number, updatedAt: number) {
  const pointerPath = `${counterPath}.generation`;
  const pointer = existsSync(pointerPath)
    ? (JSON.parse(readFileSync(pointerPath, "utf8")) as { generation?: string })
    : { generation: randomBytes(16).toString("hex") };
  if (typeof pointer.generation !== "string") {
    throw new Error(`admission counter ${counterPath} has no active generation`);
  }
  writeFileSync(pointerPath, JSON.stringify({ generation: pointer.generation }));
  writeFileSync(
    `${counterPath}.${pointer.generation}`,
    JSON.stringify({ count, updated_at: updatedAt }),
  );
}

beforeAll(async () => {
  fake = await startFakeS3("tier-test-bucket");
  bucket = {
    id: "tier-bucket",
    endpoint: fake.url,
    region: "us-east-1",
    bucket: fake.bucket,
    urlStyle: "path",
    getKeyId: "GETKEY",
    getKeySecret: "get-secret",
    putKeyId: "PUTKEY",
    putKeySecret: "put-secret",
    integrity: "server_verified",
  };
  // Same fake bucket, different registry row: exercises the ranged
  // read-back verify lane (the shipped registry default is 'unverified').
  unverifiedBucket = { ...bucket, id: "tier-bucket-unverified", integrity: "unverified" };
  rt = await startRuntime({
    atomicData: {
      SPACEFAST_STORAGE_BUCKETS_JSON: JSON.stringify([bucket, unverifiedBucket]),
    },
    env: {
      SPACEFAST_TIER_DEMOTE_GRACE_SECONDS: "0",
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
      SPACEFAST_TIER_FETCH_BREAKER_FAILURES: "3",
      SPACEFAST_TIER_FETCH_MAX_IN_FLIGHT: "1",
      // The in-flight cap assertions seed runtime/tier/inflight.json directly,
      // so pin the file backend: on hosts whose php ships apcu with
      // apc.enabled=1 (GitHub runners), _stattic_admission_backend() would
      // otherwise pick apcu and silently ignore the seeded counter file.
      SPACEFAST_ADMISSION_COUNTER_BACKEND: "file",
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

function bucketPayload(row: BucketRow = bucket): Record<string, string> {
  return {
    id: row.id,
    endpoint: row.endpoint,
    region: row.region,
    bucket: row.bucket,
    urlStyle: row.urlStyle,
  };
}

function objectKey(spaceId: string, hash: string): string {
  return `spaces/${spaceId}/blobs/${hash.slice(0, 2)}/${hash}`;
}

async function createJob(
  type: string,
  spaceId: string,
  payload: Record<string, unknown>,
): Promise<JobRecord> {
  const created = await apiJson<{ job: JobRecord }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: spaceId },
    { type, idempotency_key: `${type}:${spaceId}:${nextId("idem")}`, payload },
    201,
  );
  return created.job;
}

async function tick(scope: Record<string, unknown> = {}): Promise<TickResponse> {
  return apiJson<TickResponse>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=50000",
    "tick_engine_jobs",
    scope,
  );
}

async function tickInteractive(): Promise<TickResponse> {
  return apiJson<TickResponse>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs/tick?lane=interactive&budget_ms=50000",
    "tick_engine_jobs",
    {},
  );
}

function journalEvents(): Array<Record<string, unknown>> {
  const raw = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8");
  return raw
    .split("\n")
    .filter((line) => line.trim() !== "")
    .map((line) => JSON.parse(line) as Record<string, unknown>);
}

async function runJob(jobId: string, maxTicks = 30): Promise<JobRecord> {
  for (let i = 0; i < maxTicks; i += 1) {
    const response = await tick();
    if (response.job?.id === jobId && ["complete", "failed"].includes(response.job.status)) {
      return response.job;
    }
  }
  throw new Error(`job ${jobId} did not finish`);
}

async function deployTierFixture(
  spaceId: string,
  versionId: string,
  host: string,
  body = "x".repeat(40000),
): Promise<string> {
  await deploy(rt, {
    spaceId,
    versionId,
    files: {
      "index.html": `<html><body><script src="/__spacefast/sdk.js"></script>${body}</body></html>`,
      "assets/big.txt": body,
      "assets/small.txt": "small",
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  return body;
}

async function demote(
  spaceId: string,
  versionId: string,
  row: BucketRow = bucket,
): Promise<JobRecord> {
  const job = await createJob("tier_demote", spaceId, {
    space_id: spaceId,
    version_id: versionId,
    bucket: bucketPayload(row),
  });
  return runJob(job.id);
}

test("finalize stores version files as per-space blob hardlinks and stamps tier_class", async () => {
  const spaceId = nextId("spc_tier_finalize");
  const versionA = nextId("ver_tier_finalize_a");
  const versionB = nextId("ver_tier_finalize_b");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const large = "shared-large-payload\n".repeat(2200);
  await deployTierFixture(spaceId, versionA, host, large);
  await deploy(rt, {
    spaceId,
    versionId: versionB,
    files: {
      "assets/big.txt": large,
      "assets/other.txt": "other",
      // tier_class is declared by the producer, never sniffed from the path:
      // user files under a directory named zero/ or whose path merely contains
      // "template-variant" are user files and stay demote-eligible.
      "zero/big-user-file.txt": large,
      "blog/template-variants-explained.html": large,
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });

  const a = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionA,
    "files",
    "assets",
    "big.txt",
  );
  const b = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionB,
    "files",
    "assets",
    "big.txt",
  );
  const statA = statSync(a);
  const statB = statSync(b);
  expect(statA.ino).toBe(statB.ino);
  expect(statA.nlink).toBeGreaterThan(1);

  const meta = shardFiles(rt, spaceId, versionB)["assets/big.txt"];
  expect(meta.local).toBe(true);
  expect(meta.tier_class).toBe("eligible");
  expect(shardFiles(rt, spaceId, versionB)["assets/other.txt"].tier_class).toBe("small");
  // Path-substring sniffing is gone: these user files were previously
  // misclassified 'artifact' and excluded from tier demotion forever.
  expect(shardFiles(rt, spaceId, versionB)["zero/big-user-file.txt"].tier_class).toBe("eligible");
  expect(
    shardFiles(rt, spaceId, versionB)["blog/template-variants-explained.html"].tier_class,
  ).toBe("eligible");
});

// D21: the 32KB demote-eligibility floor is a real env knob, not just a
// constant next to a comment claiming it's configurable.
test("the demote-eligibility size floor is a real env knob (SPACEFAST_TIER_MIN_DEMOTE_BYTES)", async () => {
  const shrunk = await startRuntime({
    env: { SPACEFAST_TIER_MIN_DEMOTE_BYTES: "10" },
  });
  try {
    const spaceId = "spc_tier_min_bytes_knob";
    const versionId = "ver_tier_min_bytes_knob_1";
    const host = "tier-min-bytes-knob.test";
    await deploy(shrunk, {
      spaceId,
      versionId,
      files: {
        "index.html": "<html><body></body></html>",
        // 20 bytes: below the shipped 32KB default (would stamp 'small'),
        // above the lowered 10-byte knob (must stamp 'eligible').
        "assets/tiny.txt": "twenty-byte-payload!",
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        version_hostnames: [],
      },
    });
    const tinyMeta = shardFiles(shrunk, spaceId, versionId)["assets/tiny.txt"];
    expect(tinyMeta.tier_class).toBe("eligible");
  } finally {
    shrunk.stop();
  }
});

test("declared sha mismatch is rejected before finalize commits bytes", async () => {
  const spaceId = nextId("spc_tier_sha");
  const versionId = nextId("ver_tier_sha");
  const { uploadId, token } = await createDeclaredSession(rt, spaceId, versionId, {
    "assets/big.txt": Buffer.from("declared"),
  });
  const response = await putFile(rt, uploadId, token, "assets/big.txt", Buffer.from("different"));
  expect([200, 409, 422]).toContain(response.status);
  if (response.status !== 200) {
    return;
  }
  const finalized = await fetch(
    `${rt.baseUrl}/__spacefast/api.php?${new URLSearchParams({ route: `/spaces/${spaceId}/versions/${versionId}/finalize` })}`,
    {
      method: "POST",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${managementToken("finalize_version", { space_id: spaceId, version_id: versionId })}`,
      },
      body: JSON.stringify({ upload_id: uploadId }),
    },
  );
  expect(finalized.status).toBe(409);
});

test("demote rejects locally bit-rotted bytes on an unverified bucket instead of self-validating them", async () => {
  const spaceId = nextId("spc_tier_bitrot");
  const versionId = nextId("ver_tier_bitrot");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);
  const hash = sha256(body);
  const filePath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "files",
    "assets",
    "big.txt",
  );
  // Bit-rot AFTER finalize recorded the (still correct-looking) sha256 —
  // the demoter must catch this against the RECORDED sha instead of
  // read-back-comparing the bucket object to this same corrupt local file.
  writeFileSync(filePath, "corrupted-bytes-do-not-match-the-recorded-sha");

  const failed = await demote(spaceId, versionId, unverifiedBucket);
  expect(failed.status).toBe("failed");
  expect((failed as JobRecord & { error?: { code?: string } }).error?.code).toBe(
    "transfer_source_corrupted",
  );
  expect(fake.getObject(objectKey(spaceId, hash))).toBeUndefined();
});

test("demote serves remote bytes, conditionals and HEAD avoid S3, range/416 are correct, and promote restores local bytes", async () => {
  const spaceId = nextId("spc_tier_serve");
  const versionId = nextId("ver_tier_serve");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);
  const hash = sha256(body);
  const completed = await demote(spaceId, versionId);
  expect(completed.status).toBe("complete");
  expect(fake.getObject(objectKey(spaceId, hash))).toBeDefined();
  expect(
    existsSync(
      path.join(
        rt.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        "assets",
        "big.txt",
      ),
    ),
  ).toBe(false);
  expect(shardFiles(rt, spaceId, versionId)["assets/small.txt"].local).toBe(true);

  fake.requests.splice(0);
  const served = await get(rt, host, "/assets/big.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(body);
  const etag = served.headers.get("etag") ?? "";
  expect(fake.requests.some((r) => r.method === "GET")).toBe(true);

  fake.requests.splice(0);
  const conditional = await get(rt, host, "/assets/big.txt", {
    headers: { "if-none-match": etag },
  });
  expect(conditional.status).toBe(304);
  expect(fake.requests).toHaveLength(0);

  const head = await get(rt, host, "/assets/big.txt", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(await head.text()).toBe("");
  expect(fake.requests).toHaveLength(0);

  const range = await get(rt, host, "/assets/big.txt", { headers: { range: "bytes=2-9" } });
  expect(range.status).toBe(206);
  expect(range.headers.get("content-range")).toBe(`bytes 2-9/${Buffer.byteLength(body)}`);
  expect(await range.text()).toBe(body.slice(2, 10));

  const unsatRemote = await get(rt, host, "/assets/big.txt", {
    headers: { range: `bytes=${body.length + 100}-${body.length + 200}` },
  });
  expect(unsatRemote.status).toBe(416);
  expect(unsatRemote.headers.get("content-range")).toBe(`bytes */${Buffer.byteLength(body)}`);

  const multiRange = await get(rt, host, "/assets/big.txt", {
    headers: { range: "bytes=0-1,4-5" },
  });
  expect(multiRange.status).toBe(200);
  expect(await multiRange.text()).toBe(body);

  const transformed = await get(rt, host, "/?spacefast_tag_preview=preview-token");
  expect(transformed.status).toBe(200);
  expect(await transformed.text()).toContain("/__spacefast/sdk.js?preview=preview-token");

  // Confirmed finding F6: the dynamic-HTML-transform branch used to fetch
  // (and for a tiered file, dial the bucket for) the body before its own
  // HEAD check, even though a HEAD response never sends a body. HEAD on this
  // same tiered tag-preview URL must answer from shard metadata alone.
  fake.requests.splice(0);
  const headTransformed = await get(rt, host, "/?spacefast_tag_preview=preview-token", {
    method: "HEAD",
  });
  expect(headTransformed.status).toBe(200);
  expect(await headTransformed.text()).toBe("");
  expect(fake.requests).toHaveLength(0);

  const promote = await createJob("tier_promote", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
  });
  const promoted = await runJob(promote.id);
  expect(promoted.status).toBe("complete");
  expect(
    readFileSync(
      path.join(
        rt.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        "assets",
        "big.txt",
      ),
      "utf8",
    ),
  ).toBe(body);
  expect(shardFiles(rt, spaceId, versionId)["assets/big.txt"].remote).toBeDefined();
});

test("a bucket-side 416 on a remote range fetch relays as a client 416 and never trips the breaker", async () => {
  // Confirmed finding F7: tier.php's remote-range branch mapped ANY
  // non-2xx bucket status (including a genuine 416) to a generic
  // tier-fetch-failure — 503 + a breaker strike — instead of relaying the
  // client-range problem it actually is. Reproduce a bucket-side 416 that
  // survives serve.php's own local-satisfiability pre-check: shrink the
  // ACTUAL bucket object (mimics drift/corruption) while the shard still
  // records the original, larger size, so the local check lets the range
  // through and the bucket then legitimately 416s against its real size.
  const spaceId = nextId("spc_tier_416");
  const versionId = nextId("ver_tier_416");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);
  const hash = sha256(body);
  expect((await demote(spaceId, versionId)).status).toBe("complete");
  rmSync(path.join(rt.storageRoot, "runtime", "tier", "breakers"), {
    recursive: true,
    force: true,
  });

  const truncated = Buffer.from(body).subarray(0, 10);
  fake.putObject(objectKey(spaceId, hash), truncated);

  fake.requests.splice(0);
  const outOfRange = await get(rt, host, "/assets/big.txt", {
    headers: { range: `bytes=${body.length - 5}-${body.length - 1}` },
  });
  expect(outOfRange.status).toBe(416);
  expect(outOfRange.headers.get("content-range")).toBe(`bytes */${Buffer.byteLength(body)}`);

  // A 416 is a client-range problem, not a bucket failure: it must not trip
  // the breaker — the very next range request still dials and serves.
  const stillServing = await get(rt, host, "/assets/big.txt", {
    headers: { range: "bytes=0-3" },
  });
  expect(stillServing.status).toBe(206);
  expect(await stillServing.text()).toBe(body.slice(0, 4));
});

test("local fopen failure falls through to remote and breaker stops dialing after consecutive failures", async () => {
  const spaceId = nextId("spc_tier_breaker");
  const versionId = nextId("ver_tier_breaker");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);
  await demote(spaceId, versionId);
  const localPath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "files",
    "assets",
    "big.txt",
  );
  rmSync(localPath, { force: true });
  const fromRemote = await get(rt, host, "/assets/big.txt");
  expect(fromRemote.status).toBe(200);
  expect(await fromRemote.text()).toBe(body);

  const tierRuntimeDir = path.join(rt.storageRoot, "runtime", "tier");
  mkdirSync(tierRuntimeDir, { recursive: true });
  const inflightCounterPath = path.join(tierRuntimeDir, "inflight.json");
  writeAdmissionFileCounter(inflightCounterPath, 1, Math.floor(Date.now() / 1000));
  fake.requests.splice(0);
  const capped = await get(rt, host, "/assets/big.txt");
  expect(capped.status).toBe(503);
  expect(capped.headers.get("retry-after")).toBe("30");
  expect(fake.requests).toHaveLength(0);
  writeAdmissionFileCounter(inflightCounterPath, 0, Math.floor(Date.now() / 1000));
  const underCap = await get(rt, host, "/assets/big.txt");
  expect(underCap.status).toBe(200);
  expect(await underCap.text()).toBe(body);

  fake.requests.splice(0);
  fake.failNext(3, 500);
  for (let i = 0; i < 3; i += 1) {
    const failed = await get(rt, host, "/assets/big.txt");
    expect(failed.status).toBe(503);
    await failed.text();
  }
  const dialed = fake.requests.length;
  const shed = await get(rt, host, "/assets/big.txt");
  expect(shed.status).toBe(503);
  expect(shed.headers.get("retry-after")).toBe("30");
  expect(fake.requests).toHaveLength(dialed);
});

test("blob report, local blob GC, disk report, and pruning hook use shard truth and route re-checks", async () => {
  const spaceId = nextId("spc_tier_house");
  const versionOld = nextId("ver_tier_house_old");
  const versionId = nextId("ver_tier_house_live");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  await deployTierFixture(spaceId, versionOld, host, "y".repeat(40000));
  const body = await deployTierFixture(spaceId, versionId, host);
  const hash = sha256(body);
  await demote(spaceId, versionId);

  const report = await createJob("blob_report", spaceId, {
    space_id: spaceId,
    bucket_id: bucket.id,
  });
  expect((await runJob(report.id)).status).toBe("complete");
  let journal = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8");
  expect(journal).toContain('"event":"space.blob.report"');
  expect(journal).toContain(hash);

  await tick();
  const blobPath = path.join(rt.storageRoot, "spaces", spaceId, "blobs", hash.slice(0, 2), hash);
  expect(existsSync(blobPath)).toBe(false);
  journal = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8");
  expect(journal).toContain('"event":"space.disk.report"');

  // Retention policy arrives through the management write surface (never a
  // raw file drop): invalid bodies are rejected, valid pushes land atomically
  // in retention-policy.json for the housekeeping hook.
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
    { prunable_version_ids: [versionOld, versionId] },
  );
  expect(policy).toEqual({ space_id: spaceId, prunable_count: 2 });

  const spaceRoot = path.join(rt.storageRoot, "spaces", spaceId);
  await tick();
  // The engine re-checks route pointers before rm: the routed (live) version
  // is refused even though the control plane listed it; the unrouted one is
  // pruned and its tree reclaimed.
  expect(existsSync(path.join(spaceRoot, "versions", versionOld))).toBe(false);
  expect(existsSync(path.join(spaceRoot, "versions", versionId))).toBe(true);
  // The breaker test above deliberately left the bucket breaker open; clear
  // it so this serve exercises the remote path, not the 60s open window.
  rmSync(path.join(rt.storageRoot, "runtime", "tier", "breakers"), {
    recursive: true,
    force: true,
  });
  const served = await get(rt, host, "/assets/big.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(body);
  journal = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8");
  expect(journal).toContain('"versionIds":["' + versionOld + '"]');
  expect(journal).toContain('"refusedVersionIds":["' + versionId + '"]');
});

test("publish with retained files succeeds after the reusable version was demoted (re-warm, no 409)", async () => {
  const spaceId = nextId("spc_tier_rewarm");
  const versionA = nextId("ver_tier_rewarm_a");
  const versionB = nextId("ver_tier_rewarm_b");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = "w".repeat(40000);
  const bodySha = sha256(body);
  await deploy(rt, {
    spaceId,
    versionId: versionA,
    files: { "index.html": "<html>rewarm</html>", "assets/big.txt": body },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const demoted = await demote(spaceId, versionA);
  expect(demoted.status).toBe("complete");
  expect(
    existsSync(
      path.join(
        rt.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionA,
        "files",
        "assets",
        "big.txt",
      ),
    ),
  ).toBe(false);
  // Simulate the full Tier-B cold state: the local blob GC already reclaimed
  // the space-store copy, so the ONLY remaining bytes are in the bucket.
  const blobPath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "blobs",
    bodySha.slice(0, 2),
    bodySha,
  );
  rmSync(blobPath, { force: true });
  expect(fake.getObject(objectKey(spaceId, bodySha))).toBeDefined();

  const indexB = "<html>rewarm v2</html>";
  const created = await apiJson<{ upload_id: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions`,
    "create_version",
    { space_id: spaceId },
    {
      version_id: versionB,
      files: [{ path: "index.html", size: Buffer.byteLength(indexB), sha256: sha256(indexB) }],
      retained_files: [{ path: "assets/big.txt", size: Buffer.byteLength(body), sha256: bodySha }],
      reusable_version_id: versionA,
    },
    201,
  );
  const token = uploadToken(spaceId, created.upload_id, versionB, "declared");
  const put = await putFile(rt, created.upload_id, token, "index.html", Buffer.from(indexB));
  expect(put.status).toBe(200);
  // apiJson throws on any non-200 — the OLD behavior was a hard 409
  // version_reusable_file_missing here.
  await apiJson(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionB}/finalize`,
    "finalize_version",
    { space_id: spaceId, version_id: versionB },
    {
      upload_id: created.upload_id,
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        version_hostnames: [],
      },
    },
  );
  const rematerialized = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionB,
    "files",
    "assets",
    "big.txt",
  );
  expect(readFileSync(rematerialized, "utf8")).toBe(body);
  // The fetched bytes landed back in the space blob store (CAS), not as a loose copy.
  expect(existsSync(blobPath)).toBe(true);
  const served = await get(rt, host, "/assets/big.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(body);
  expect(served.headers.get("etag")).toBe(`"${bodySha}"`);
});

test("tier_cancel dead-letters queued tier jobs from the interactive lane and allows re-dispatch under the same idempotency key", async () => {
  const spaceId = nextId("spc_tier_cancel");
  const versionId = nextId("ver_tier_cancel");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  await deployTierFixture(spaceId, versionId, host);

  const idempotencyKey = `tier_demote:${spaceId}:${versionId}:fixed`;
  const createDemote = async () =>
    apiJson<{ job: JobRecord & { error?: { code?: string } } }>(
      rt,
      "POST",
      "/__spacefast/api.php/jobs",
      "create_engine_job",
      { space_id: spaceId },
      {
        type: "tier_demote",
        idempotency_key: idempotencyKey,
        payload: { space_id: spaceId, version_id: versionId, bucket: bucketPayload() },
      },
      201,
    );
  const demoteJob = (await createDemote()).job;
  expect(demoteJob.status).toBe("pending");

  const cancel = await createJob("tier_cancel", spaceId, { space_id: spaceId });
  const cancelTick = await tickInteractive();
  expect(cancelTick.job?.id).toBe(cancel.id);
  expect(cancelTick.job?.status).toBe("complete");

  const afterCancel = await apiJson<{ job: JobRecord & { error?: { code?: string } } }>(
    rt,
    "GET",
    `/__spacefast/api.php/jobs/${demoteJob.id}`,
    "get_engine_job",
    { job_id: demoteJob.id },
  );
  expect(afterCancel.job.status).toBe("failed");
  expect(afterCancel.job.error?.code).toBe("tier_aborted");

  // A canceled job is a cancellation, not a terminal failure: the same
  // idempotency key must start FRESH work (the move-commit re-dispatch path).
  const recreated = (await createDemote()).job;
  expect(recreated.id).not.toBe(demoteJob.id);
  expect(recreated.status).toBe("pending");

  // Leave the lane clean for the rest of the suite.
  await createJob("tier_cancel", spaceId, { space_id: spaceId });
  await tickInteractive();
  const afterSecondCancel = await apiJson<{ job: JobRecord & { error?: { code?: string } } }>(
    rt,
    "GET",
    `/__spacefast/api.php/jobs/${recreated.id}`,
    "get_engine_job",
    { job_id: recreated.id },
  );
  expect(afterSecondCancel.job.status).toBe("failed");
});

test("demote retry reconciles a crash between shard rewrite and unlink (hardlinks released, bytes counted once)", async () => {
  const spaceId = nextId("spc_tier_crash");
  const versionId = nextId("ver_tier_crash");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = "z".repeat(40000);
  const bodySha = sha256(body);
  await deploy(rt, {
    spaceId,
    versionId,
    files: { "index.html": "<html>crash</html>", "assets/big.txt": body },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  // Simulate the §17 crash window: the shard rewrite (local:false + remote)
  // landed but the process died before the unlink list reached the cursor —
  // the hardlink is still on disk and NOTHING was emitted (the callback is
  // the last step of the demote order).
  const shard = sha256("assets/big.txt").slice(0, 2);
  const shardPath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "file-shards",
    `${shard}.php`,
  );
  const rewrite = Bun.spawnSync([
    "php",
    "-r",
    '$f=include $argv[1];$f["files"][$argv[2]]["local"]=false;$f["files"][$argv[2]]["remote"]=["bucket"=>$argv[3],"key"=>$argv[4],"enc"=>"identity"];file_put_contents($argv[1],"<?php\nreturn ".var_export($f,true).";\n");',
    shardPath,
    "assets/big.txt",
    bucket.id,
    objectKey(spaceId, bodySha),
  ]);
  expect(rewrite.exitCode).toBe(0);
  const filePath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "files",
    "assets",
    "big.txt",
  );
  expect(existsSync(filePath)).toBe(true);

  const completed = await demote(spaceId, versionId);
  expect(completed.status).toBe("complete");
  const stats = completed.result as { bytesMoved: number; blobCount: number };
  expect(stats.bytesMoved).toBe(Buffer.byteLength(body));
  expect(stats.blobCount).toBe(1);
  expect(existsSync(filePath)).toBe(false);
});

test("promote emits per-shard byte DELTAS, never the cumulative running total", async () => {
  const spaceId = nextId("spc_tier_delta");
  const versionId = nextId("ver_tier_delta");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  // Two eligible files in two DIFFERENT shards (shard = sha256(path)[0:2]).
  let pathA = "assets/delta-a.txt";
  let pathB = "assets/delta-b.txt";
  for (let i = 0; sha256(pathA).slice(0, 2) === sha256(pathB).slice(0, 2); i += 1) {
    pathB = `assets/delta-b-${i}.txt`;
  }
  const bodyA = "a".repeat(40000);
  const bodyB = "b".repeat(50000);
  await deploy(rt, {
    spaceId,
    versionId,
    files: { "index.html": "<html>delta</html>", [pathA]: bodyA, [pathB]: bodyB },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  expect((await demote(spaceId, versionId)).status).toBe("complete");

  const promote = await createJob("tier_promote", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
  });
  expect((await runJob(promote.id)).status).toBe("complete");

  const promotedEvents = journalEvents().filter(
    (event) => event.event === "space.tier.promoted" && event.spaceId === spaceId,
  );
  expect(promotedEvents.length).toBe(2);
  const perShardBytes = promotedEvents
    .map((event) => event.bytesPromoted as number)
    .toSorted((a, b) => a - b);
  expect(perShardBytes).toEqual([Buffer.byteLength(bodyA), Buffer.byteLength(bodyB)]);
  for (const event of promotedEvents) {
    expect(event.blobCount).toBe(1);
  }
});

function readZipEntry(zipPath: string, entryName: string): string {
  const proc = Bun.spawnSync([
    "php",
    "-r",
    `$z = new ZipArchive();` +
      `if ($z->open(${JSON.stringify(zipPath)}) !== true) { fwrite(STDERR, 'open failed'); exit(1); }` +
      `$data = $z->getFromName(${JSON.stringify(entryName)});` +
      `if ($data === false) { fwrite(STDERR, 'entry missing'); exit(2); }` +
      `echo $data;`,
  ]);
  if (proc.exitCode !== 0) {
    throw new Error(
      `readZipEntry(${entryName}) failed: ${proc.stderr.toString() || proc.exitCode}`,
    );
  }
  return proc.stdout.toString();
}

async function runExport(spaceId: string): Promise<string> {
  let status = await apiJson<{ export_id?: string; status: string }>(
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
    status = await apiJson(
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
  const zipPath = path.join(rt.root, `${exportId}.zip`);
  writeFileSync(zipPath, Buffer.from(await download.arrayBuffer()));
  return zipPath;
}

test("space export recovers real bytes for a demoted (tiered) file instead of silently dropping it", async () => {
  const spaceId = nextId("spc_tier_export");
  const versionId = nextId("ver_tier_export");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);

  const completed = await demote(spaceId, versionId);
  expect(completed.status).toBe("complete");
  const onDiskPath = path.join(
    rt.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "files",
    "assets",
    "big.txt",
  );
  // Confirm the file genuinely left local disk before exporting — this test
  // is only meaningful if the export has to recover the bytes from the
  // bucket rather than finding them locally.
  expect(existsSync(onDiskPath)).toBe(false);
  expect(shardFiles(rt, spaceId, versionId)["assets/big.txt"].local).toBe(false);

  const zipPath = await runExport(spaceId);
  const exported = readZipEntry(
    zipPath,
    `spacefast_export_v1/versions/${versionId}/files/assets/big.txt`,
  );
  expect(exported).toBe(body);

  // The small, never-demoted file must still be present too (regression
  // guard: the remote-entry fold-in must not crowd out ordinary local files).
  const exportedSmall = readZipEntry(
    zipPath,
    `spacefast_export_v1/versions/${versionId}/files/assets/small.txt`,
  );
  expect(exportedSmall).toBe("small");
});

test("precompressed sidecars demote with their base file: locators flip, bytes leave disk, br serving survives from the bucket", async () => {
  const spaceId = nextId("spc_tier_sidecar");
  const versionId = nextId("ver_tier_sidecar");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  // Incompressible-ish content so the .br sidecar itself clears the 32KB
  // demote floor (representations are independent entries with independent
  // eligibility).
  const content = randomBytes(45000).toString("base64");
  const br = brotliCompressSync(Buffer.from(content));
  expect(br.length).toBeGreaterThanOrEqual(32768);
  const contentSha = sha256(content);
  const brSha = sha256(br);
  await deploy(rt, {
    spaceId,
    versionId,
    files: {
      "index.html": "<html>sidecar</html>",
      "assets/data.txt": content,
      "assets/data.txt.br": br,
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const baseMetaBefore = shardFiles(rt, spaceId, versionId)["assets/data.txt"];
  expect(baseMetaBefore.compressed?.br?.sha256).toBe(brSha);

  const completed = await demote(spaceId, versionId);
  expect(completed.status).toBe("complete");
  const stats = completed.result as { bytesMoved: number };
  expect(stats.bytesMoved).toBe(Buffer.byteLength(content) + br.length);

  const files = shardFiles(rt, spaceId, versionId);
  expect(files["assets/data.txt"].local).toBe(false);
  expect(files["assets/data.txt"].compressed.br.local).toBe(false);
  expect(files["assets/data.txt"].compressed.br.remote.key).toBe(objectKey(spaceId, brSha));
  expect(files["assets/data.txt.br"].local).toBe(false);
  expect(
    existsSync(
      path.join(
        rt.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        "assets",
        "data.txt",
      ),
    ),
  ).toBe(false);
  expect(
    existsSync(
      path.join(
        rt.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        "assets",
        "data.txt.br",
      ),
    ),
  ).toBe(false);
  expect(fake.getObject(objectKey(spaceId, contentSha))).toBeDefined();
  expect(fake.getObject(objectKey(spaceId, brSha))).toBeDefined();

  fake.requests.splice(0);
  const served = await get(rt, host, "/assets/data.txt", { headers: { "accept-encoding": "br" } });
  expect(served.status).toBe(200);
  expect(served.headers.get("etag")).toBe(`"${brSha}"`);
  const raw = Buffer.from(await served.arrayBuffer());
  // Bun's fetch may or may not transparently decode br; either surface proves
  // the br representation streamed intact from the bucket (the ETag + S3 key
  // assertions pin WHICH representation it was).
  expect(raw.equals(br) || raw.toString("utf8") === content).toBe(true);
  expect(fake.requests.some((r) => r.method === "GET" && r.path.includes(brSha))).toBe(true);
});

test("dynamic HTML transform over a demoted page honors the breaker and the in-flight cap (503 without dialing)", async () => {
  const spaceId = nextId("spc_tier_transform");
  const versionId = nextId("ver_tier_transform");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const body = await deployTierFixture(spaceId, versionId, host);
  expect((await demote(spaceId, versionId)).status).toBe("complete");
  const tierRuntimeDir = path.join(rt.storageRoot, "runtime", "tier");
  const breakerDir = path.join(tierRuntimeDir, "breakers");
  rmSync(breakerDir, { recursive: true, force: true });

  const warm = await get(rt, host, "/?spacefast_tag_preview=preview-token");
  expect(warm.status).toBe(200);
  expect(await warm.text()).toContain(body);

  // Open breaker: the transform read must 503 WITHOUT dialing the bucket.
  mkdirSync(breakerDir, { recursive: true });
  const now = Math.floor(Date.now() / 1000);
  writeFileSync(
    path.join(breakerDir, `${bucket.id}.json`),
    JSON.stringify({ failures: 3, open_until: now + 60, updated_at: now }),
  );
  fake.requests.splice(0);
  const tripped = await get(rt, host, "/?spacefast_tag_preview=preview-token");
  expect(tripped.status).toBe(503);
  expect(tripped.headers.get("retry-after")).toBe("30");
  expect(fake.requests).toHaveLength(0);
  rmSync(breakerDir, { recursive: true, force: true });

  // In-flight cap (limit 1 in this suite): same guarantee.
  mkdirSync(tierRuntimeDir, { recursive: true });
  const inflightCounterPath = path.join(tierRuntimeDir, "inflight.json");
  writeAdmissionFileCounter(inflightCounterPath, 1, now);
  fake.requests.splice(0);
  const capped = await get(rt, host, "/?spacefast_tag_preview=preview-token");
  expect(capped.status).toBe(503);
  expect(capped.headers.get("retry-after")).toBe("30");
  expect(fake.requests).toHaveLength(0);
  writeAdmissionFileCounter(inflightCounterPath, 0, now);

  const recovered = await get(rt, host, "/?spacefast_tag_preview=preview-token");
  expect(recovered.status).toBe(200);
});

test("bulk tick with callback claims ships spooled tier_fetch_failed and housekeeping reports as REAL pending callbacks", async () => {
  const spaceId = nextId("spc_tier_canary");
  const versionId = nextId("ver_tier_canary");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  await deployTierFixture(spaceId, versionId, host);
  expect((await demote(spaceId, versionId)).status).toBe("complete");
  rmSync(path.join(rt.storageRoot, "runtime", "tier", "breakers"), {
    recursive: true,
    force: true,
  });

  // One real bucket failure on the serve path → journaled AND spooled (the
  // serve path has no callback claims of its own).
  fake.failNext(1, 500);
  const failed = await get(rt, host, "/assets/big.txt");
  expect(failed.status).toBe(503);
  await failed.text();
  const spoolDir = path.join(rt.storageRoot, "runtime", "tier", "pending-events");
  expect(readdirSync(spoolDir).length).toBeGreaterThan(0);
  rmSync(path.join(rt.storageRoot, "runtime", "tier", "breakers"), {
    recursive: true,
    force: true,
  });

  // A fresh space whose disk-report marker does not exist yet: the claimed
  // tick's housekeeping pass must stage its report as a pending callback too.
  const freshSpace = nextId("spc_tier_canary_fresh");
  const freshVersion = nextId("ver_tier_canary_fresh");
  await deploy(rt, {
    spaceId: freshSpace,
    versionId: freshVersion,
    files: { "index.html": "<html>fresh</html>" },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [`${freshSpace.replaceAll("_", "-")}.test`],
      version_hostnames: [],
    },
  });

  // 127.0.0.1:9 refuses immediately: staged callbacks stay pending (retryable)
  // where the test can observe them.
  await tick({ callback_url: "http://127.0.0.1:9/", callback_token: "tok-canary" });

  expect(readdirSync(spoolDir)).toHaveLength(0);
  const pendingDir = path.join(rt.storageRoot, "runtime", "callbacks", "pending");
  const pending = readdirSync(pendingDir).map(
    (name) => JSON.parse(readFileSync(path.join(pendingDir, name), "utf8")) as PendingCallback,
  );
  const canaryEvents = pending.filter((entry) => entry.callback_token === "tok-canary");
  const fetchFailed = canaryEvents.find((entry) => entry.event?.event === "tier_fetch_failed");
  expect(fetchFailed?.event?.bucket).toBe(bucket.id);
  const diskReport = canaryEvents.find(
    (entry) => entry.event?.event === "space.disk.report" && entry.event?.spaceId === freshSpace,
  );
  expect(diskReport?.event?.inodes).toBeGreaterThan(0);

  // Keep later ticks from retrying the unreachable receiver forever.
  for (const name of readdirSync(pendingDir)) {
    const entry = JSON.parse(readFileSync(path.join(pendingDir, name), "utf8")) as PendingCallback;
    if (entry.callback_token === "tok-canary") {
      rmSync(path.join(pendingDir, name), { force: true });
    }
  }
});
