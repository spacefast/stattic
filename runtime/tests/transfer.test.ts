import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, mkdirSync, readFileSync, utimesSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  apiJson,
  deploy,
  errorCode,
  get,
  sha256,
  shardFiles,
  startRuntime,
  type Runtime,
} from "./harness.ts";
import { startFakeS3, type FakeS3 } from "./s3-fake.ts";

type BucketRow = {
  id: string;
  endpoint: string;
  region: string;
  bucket: string;
  urlStyle: "path" | "vhost";
  getKeyId: string;
  getKeySecret: string;
  putKeyId: string;
  putKeySecret: string;
  integrity: "server_verified" | "unverified";
};

type JobRecord = {
  id: string;
  status: string;
  cursor?: Record<string, unknown>;
  progress?: { done: number; total: number };
  result?: unknown;
  error?: { code: string; message: string } | null;
};

type TickResponse = {
  lane: string;
  job: JobRecord | null;
  tick_status: string;
};

type PushResult = {
  blob_count: number;
  bytes_pushed: number;
  skipped_existing: number;
  versions: Array<{ version_id: string; file_count: number; total_bytes: number }>;
};

type BundleFile = { path: string; content_b64: string };
type BundleResponse = { chunk: { files: BundleFile[] }; next_cursor: string | null };

let fake: FakeS3;
let source: Runtime;
let target: Runtime;
let bucket: BucketRow;
let unverifiedBucket: BucketRow;
let seq = 0;

beforeAll(async () => {
  fake = await startFakeS3("transfer-test-bucket");
  bucket = {
    id: "transfer-bucket",
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
  // The shipped registry default is integrity 'unverified' (ranged read-back
  // verification after every PUT) — cover that lane too, same fake bucket.
  unverifiedBucket = { ...bucket, id: "transfer-bucket-unverified", integrity: "unverified" };
  const atomicData = {
    SPACEFAST_STORAGE_BUCKETS_JSON: JSON.stringify([bucket, unverifiedBucket]),
  };
  // Zero grace windows: only the cold-move test demotes anything, but a
  // real grace window would otherwise make that job's unlink step wait out
  // a real clock delay for no reason.
  const zeroGraceEnv = {
    SPACEFAST_TIER_DEMOTE_GRACE_SECONDS: "0",
    SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
  };
  source = await startRuntime({ atomicData, env: zeroGraceEnv });
  target = await startRuntime({ atomicData, env: zeroGraceEnv });
});

afterAll(() => {
  source?.stop();
  target?.stop();
  fake?.stop();
});

function nextId(prefix: string): string {
  seq += 1;
  return `${prefix}_${seq}`;
}

function bucketPayload(): Record<string, string> {
  return {
    id: bucket.id,
    endpoint: bucket.endpoint,
    region: bucket.region,
    bucket: bucket.bucket,
    urlStyle: bucket.urlStyle,
  };
}

function objectKey(spaceId: string, hash: string): string {
  return `spaces/${spaceId}/blobs/${hash.slice(0, 2)}/${hash}`;
}

async function createJob(
  rt: Runtime,
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

async function tick(rt: Runtime, budgetMs = 50000): Promise<TickResponse> {
  return apiJson<TickResponse>(
    rt,
    "POST",
    `/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=${budgetMs}`,
    "tick_engine_jobs",
    {},
  );
}

async function runJob(rt: Runtime, jobId: string, maxTicks = 80): Promise<JobRecord> {
  let last: JobRecord | null = null;
  for (let i = 0; i < maxTicks; i += 1) {
    const response = await tick(rt);
    if (response.job !== null) {
      last = response.job;
      if (response.job.id === jobId && ["complete", "failed"].includes(response.job.status)) {
        return response.job;
      }
    }
    if (response.tick_status === "retry_scheduled") {
      throw new Error(`job retried unexpectedly: ${JSON.stringify(response.job)}`);
    }
  }
  throw new Error(`job ${jobId} did not finish: ${JSON.stringify(last)}`);
}

async function createPush(spaceId: string, versionIds: string[]): Promise<JobRecord> {
  return createJob(source, "space_transfer_push", spaceId, {
    space_id: spaceId,
    version_ids: versionIds,
    bucket: bucketPayload(),
    check_only: false,
  });
}

async function pushSpace(spaceId: string, versionIds: string[]): Promise<PushResult> {
  const job = await createPush(spaceId, versionIds);
  const completed = await runJob(source, job.id);
  expect(completed.status).toBe("complete");
  return completed.result as PushResult;
}

async function streamBundle(
  from: Runtime,
  to: Runtime,
  spaceId: string,
  versionIds: string[],
  options: { includeServingState?: boolean } = {},
): Promise<{ chunks: number; sawNextCursor: boolean }> {
  let cursor: string | null = null;
  let chunks = 0;
  let sawNextCursor = false;
  do {
    const exported = await apiJson<BundleResponse>(
      from,
      "POST",
      "/__spacefast/api.php/transfer/bundle",
      "transfer_bundle",
      { space_id: spaceId },
      {
        space_id: spaceId,
        version_ids: versionIds,
        cursor,
        ...(options.includeServingState === undefined
          ? {}
          : { include_serving_state: options.includeServingState }),
      },
    );
    chunks += 1;
    sawNextCursor = sawNextCursor || exported.next_cursor !== null;
    await apiJson(
      to,
      "POST",
      "/__spacefast/api.php/transfer/bundle",
      "transfer_bundle",
      { space_id: spaceId },
      { space_id: spaceId, store: true, files: exported.chunk.files, done: false },
    );
    cursor = exported.next_cursor;
  } while (cursor !== null);
  await apiJson(
    to,
    "POST",
    "/__spacefast/api.php/transfer/bundle",
    "transfer_bundle",
    { space_id: spaceId },
    { space_id: spaceId, store: true, files: [], done: true },
  );
  return { chunks, sawNextCursor };
}

async function installSpace(
  spaceId: string,
  versionIds: string[],
  expected: PushResult["versions"],
): Promise<JobRecord> {
  const job = await createJob(target, "space_transfer_install", spaceId, {
    space_id: spaceId,
    version_ids: versionIds,
    bucket: bucketPayload(),
    live_version_id: null,
    artifact_source: { site_token_claims_hint: "test" },
    expected,
  });
  return runJob(target, job.id);
}

async function demoteOnSource(spaceId: string, versionId: string): Promise<JobRecord> {
  const job = await createJob(source, "tier_demote", spaceId, {
    space_id: spaceId,
    version_id: versionId,
    bucket: bucketPayload(),
  });
  return runJob(source, job.id);
}

async function deployTransferFixture(
  spaceId: string,
  versionId: string,
): Promise<Record<string, string>> {
  const files: Record<string, string> = {
    "index.html": `<h1>${spaceId}</h1>\n`,
    "index.html.br": `br:${spaceId}`,
    "index.html.gz": `gz:${spaceId}`,
  };
  for (let i = 0; i < 18; i += 1) {
    files[`assets/file-${i}.txt`] = `asset ${i} ${spaceId}\n`;
  }
  await deploy(source, {
    spaceId,
    versionId,
    files,
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [`${spaceId.replaceAll("_", "-")}.test`],
      version_hostnames: [],
    },
  });
  return files;
}

test("push -> bundle -> install -> commit preserves bytes, shards, sidecars, and version ids", async () => {
  const spaceId = nextId("spc_transfer_roundtrip");
  const versionId = nextId("ver_transfer_roundtrip");
  const files = await deployTransferFixture(spaceId, versionId);

  const pushed = await pushSpace(spaceId, [versionId]);
  expect(pushed.versions).toHaveLength(1);
  expect(pushed.versions[0]?.version_id).toBe(versionId);
  expect(pushed.versions[0]?.file_count).toBe(Object.keys(files).length);
  expect(fake.getObject(objectKey(spaceId, sha256(files["index.html"] ?? "")))).toBeDefined();
  expect(fake.getObject(objectKey(spaceId, sha256(files["index.html.br"] ?? "")))).toBeDefined();
  expect(fake.getObject(objectKey(spaceId, sha256(files["index.html.gz"] ?? "")))).toBeDefined();

  await streamBundle(source, target, spaceId, [versionId]);
  const installed = await installSpace(spaceId, [versionId], pushed.versions);
  expect(installed.status).toBe("complete");
  expect(installed.result).toEqual({
    staged: true,
    blobs_pulled: Object.keys(files).length,
    bytes_pulled: pushed.versions[0]?.total_bytes,
  });

  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/commit",
    "transfer_commit",
    { space_id: spaceId },
    { space_id: spaceId, version_ids: [versionId], live_version_id: versionId },
  );
  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/commit",
    "transfer_commit",
    { space_id: spaceId },
    { space_id: spaceId, version_ids: [versionId], live_version_id: versionId },
  );

  const targetRoot = path.join(target.storageRoot, "spaces", spaceId);
  expect(
    readFileSync(path.join(targetRoot, "versions", versionId, "files", "index.html"), "utf8"),
  ).toBe(files["index.html"]);
  expect(
    readFileSync(path.join(targetRoot, "versions", versionId, "files", "index.html.br"), "utf8"),
  ).toBe(files["index.html.br"]);
  expect(existsSync(path.join(targetRoot, "versions", versionId, "file-shards"))).toBe(true);
  expect(existsSync(path.join(targetRoot, "routes", "production.json"))).toBe(true);

  const putCount = fake.requests.filter((request) => request.method === "PUT").length;
  const rerun = await pushSpace(spaceId, [versionId]);
  const putCountAfterRerun = fake.requests.filter((request) => request.method === "PUT").length;
  expect(rerun.blob_count).toBe(0);
  expect(rerun.skipped_existing).toBe(Object.keys(files).length);
  expect(putCountAfterRerun).toBe(putCount);
});

test("version-only transfer installs mounted artifacts without replacing source-site serving state", async () => {
  const spaceId = nextId("spc_transfer_mount");
  const sourceVersionId = nextId("ver_transfer_mount_source");
  const targetVersionId = nextId("ver_transfer_mount_target");
  const sourceFiles = await deployTransferFixture(spaceId, sourceVersionId);
  const targetHost = `${spaceId.replaceAll("_", "-")}-target.test`;
  await deploy(target, {
    spaceId,
    versionId: targetVersionId,
    files: { "index.html": "<h1>target production stays live</h1>\n" },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [targetHost],
      version_hostnames: [],
    },
  });

  const pushed = await pushSpace(spaceId, [sourceVersionId]);
  await streamBundle(source, target, spaceId, [sourceVersionId], {
    includeServingState: false,
  });
  expect(
    existsSync(path.join(target.storageRoot, "transfer-staging", spaceId, "bundle", "routes")),
  ).toBe(false);

  const installed = await installSpace(spaceId, [sourceVersionId], pushed.versions);
  expect(installed.status).toBe("complete");
  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/commit",
    "transfer_commit",
    { space_id: spaceId },
    {
      space_id: spaceId,
      version_ids: [sourceVersionId],
      live_version_id: null,
      install_serving_state: false,
    },
  );

  expect(
    readFileSync(
      path.join(
        target.storageRoot,
        "spaces",
        spaceId,
        "versions",
        sourceVersionId,
        "files",
        "index.html",
      ),
      "utf8",
    ),
  ).toBe(sourceFiles["index.html"]);
  expect(
    JSON.parse(
      readFileSync(
        path.join(target.storageRoot, "spaces", spaceId, "routes", "production.json"),
        "utf8",
      ),
    ).version_id,
  ).toBe(targetVersionId);
  const served = await get(target, targetHost, "/");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("<h1>target production stays live</h1>\n");
});

test("push yields on a small budget and resumes from the persisted shard cursor", async () => {
  const spaceId = nextId("spc_transfer_resume");
  const versionId = nextId("ver_transfer_resume");
  await deployTransferFixture(spaceId, versionId);
  const job = await createPush(spaceId, [versionId]);

  const first = await tick(source, 0);
  expect(first.job?.id).toBe(job.id);
  expect(first.tick_status).toBe("yielded");
  expect(first.job?.status).toBe("pending");
  expect(first.job?.progress?.done).toBeGreaterThan(0);
  expect(first.job?.progress?.done).toBeLessThan(first.job?.progress?.total ?? 0);

  const completed = await runJob(source, job.id);
  expect(completed.status).toBe("complete");
});

test("push read-back-verifies zero-byte blobs on an unverified bucket without burning retries", async () => {
  const spaceId = nextId("spc_transfer_empty");
  const versionId = nextId("ver_transfer_empty");
  const files: Record<string, string> = {
    "index.html": `<h1>${spaceId}</h1>\n`,
    // Zero-byte file (.nojekyll-style placeholder): ranged read-back is
    // unsatisfiable, so verification must HEAD-short-circuit, not fail.
    "empty.txt": "",
    "assets/data.txt": `data ${spaceId}\n`,
  };
  await deploy(source, {
    spaceId,
    versionId,
    files,
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [`${spaceId.replaceAll("_", "-")}.test`],
      version_hostnames: [],
    },
  });

  const job = await createJob(source, "space_transfer_push", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
    bucket: {
      id: unverifiedBucket.id,
      endpoint: unverifiedBucket.endpoint,
      region: unverifiedBucket.region,
      bucket: unverifiedBucket.bucket,
      urlStyle: unverifiedBucket.urlStyle,
    },
    check_only: false,
  });
  // runJob throws on any retry_scheduled tick, so completing here proves the
  // zero-byte blob verified first try instead of burning retry attempts.
  const completed = await runJob(source, job.id);
  expect(completed.status).toBe("complete");
  const result = completed.result as PushResult;
  expect(result.blob_count).toBe(Object.keys(files).length);

  const emptyObject = fake.getObject(objectKey(spaceId, sha256("")));
  expect(emptyObject?.body.length).toBe(0);
  expect(fake.getObject(objectKey(spaceId, sha256(files["index.html"] ?? "")))).toBeDefined();
});

test("push rejects locally bit-rotted bytes on an unverified bucket instead of self-validating them", async () => {
  const spaceId = nextId("spc_transfer_bitrot");
  const versionId = nextId("ver_transfer_bitrot");
  const files = await deployTransferFixture(spaceId, versionId);
  const indexSha = sha256(files["index.html"] ?? "");
  const filePath = path.join(
    source.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "files",
    "index.html",
  );
  // Simulate local bit-rot AFTER finalize recorded the (still correct-looking)
  // sha256 in shard metadata: the on-disk bytes no longer hash to it. A
  // read-back that trusts "does the bucket match the local file" would
  // self-validate this — the fix must catch it against the RECORDED sha.
  writeFileSync(filePath, "corrupted-bytes-do-not-match-the-recorded-sha\n");

  const job = await createJob(source, "space_transfer_push", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
    bucket: {
      id: unverifiedBucket.id,
      endpoint: unverifiedBucket.endpoint,
      region: unverifiedBucket.region,
      bucket: unverifiedBucket.bucket,
      urlStyle: unverifiedBucket.urlStyle,
    },
    check_only: false,
  });
  const failed = await runJob(source, job.id);
  expect(failed.status).toBe("failed");
  expect(failed.error?.code).toBe("transfer_source_corrupted");
  // The corrupt bytes must never reach the shared CAS keyspace under the
  // recorded key — other spaces/versions dedupe against this exact key.
  expect(fake.getObject(objectKey(spaceId, indexSha))).toBeUndefined();
});

test("install pull keeps earlier batch successes when a transient bucket error forces a retry", async () => {
  const spaceId = nextId("spc_transfer_retrykeep");
  const versionId = nextId("ver_transfer_retrykeep");
  const files = await deployTransferFixture(spaceId, versionId);
  const pushed = await pushSpace(spaceId, [versionId]);
  await streamBundle(source, target, spaceId, [versionId]);

  // One transient 500 on the SECOND-lowest sha: it sits inside the first
  // curl-multi batch (the pull walks shas in sorted order) but is not the
  // cursor's front entry, so the batch-mates before it succeed on the first
  // attempt. The stepper's in-tick retry must re-fetch ONLY the failed blob
  // while keeping those earlier successes — dropping one would leave a blob
  // out of the space store and break the staged install.
  const sortedShas = Object.values(files)
    .map((content) => sha256(content))
    .toSorted();
  fake.failKey(objectKey(spaceId, sortedShas[1] ?? ""), 1, 500);
  const installed = await installSpace(spaceId, [versionId], pushed.versions);
  expect(installed.status).toBe("complete");
  expect(installed.result).toEqual({
    staged: true,
    blobs_pulled: Object.keys(files).length,
    bytes_pulled: pushed.versions[0]?.total_bytes,
  });
  for (const content of Object.values(files)) {
    const hash = sha256(content);
    const blobPath = path.join(
      target.storageRoot,
      "spaces",
      spaceId,
      "blobs",
      hash.slice(0, 2),
      hash,
    );
    expect(existsSync(blobPath)).toBe(true);
  }
});

test("install leases a reused CAS blob through stage and releases it on commit", async () => {
  const spaceId = nextId("spc_transfer_reuse_gc");
  const versionId = nextId("ver_transfer_reuse_gc");
  const files = { "index.html": `<h1>${spaceId}</h1>\n` };
  await deploy(source, {
    spaceId,
    versionId,
    files,
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [`${spaceId.replaceAll("_", "-")}.test`],
      version_hostnames: [],
    },
  });
  const pushed = await pushSpace(spaceId, [versionId]);
  await streamBundle(source, target, spaceId, [versionId]);

  const hash = sha256(files["index.html"]);
  const blobPath = path.join(
    target.storageRoot,
    "spaces",
    spaceId,
    "blobs",
    hash.slice(0, 2),
    hash,
  );
  mkdirSync(path.dirname(blobPath), { recursive: true });
  writeFileSync(blobPath, files["index.html"]);
  const old = Math.floor(Date.now() / 1000) - 10_000;
  utimesSync(blobPath, old, old);

  const job = await createJob(target, "space_transfer_install", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
    bucket: bucketPayload(),
    live_version_id: null,
    artifact_source: { site_token_claims_hint: "test" },
    expected: pushed.versions,
  });
  const pulled = await tick(target, 0);
  const leasePath = path.join(target.storageRoot, "transfer-staging", spaceId, "blob-leases", hash);
  const stagedFile = path.join(
    target.storageRoot,
    "transfer-staging",
    spaceId,
    "versions",
    versionId,
    "files",
    "index.html",
  );
  expect(pulled.job?.id).toBe(job.id);
  expect(pulled.job?.status).toBe("pending");
  expect(pulled.job?.cursor?.phase).toBe("stage");
  expect(existsSync(blobPath)).toBe(true);
  expect(existsSync(leasePath)).toBe(true);

  const completed = await runJob(target, job.id);
  expect(completed.status).toBe("complete");
  expect(completed.result).toEqual({ staged: true, blobs_pulled: 0, bytes_pulled: 0 });
  expect(existsSync(leasePath)).toBe(true);
  expect(readFileSync(stagedFile, "utf8")).toBe(files["index.html"]);

  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/commit",
    "transfer_commit",
    { space_id: spaceId },
    { space_id: spaceId, version_ids: [versionId], live_version_id: null },
  );
  expect(existsSync(leasePath)).toBe(false);
  expect(
    readFileSync(
      path.join(
        target.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        "index.html",
      ),
      "utf8",
    ),
  ).toBe(files["index.html"]);
});

test("install retries corrupted bucket blobs and fails fatal with transfer_verify_failed", async () => {
  const spaceId = nextId("spc_transfer_corrupt");
  const versionId = nextId("ver_transfer_corrupt");
  const files = await deployTransferFixture(spaceId, versionId);
  const pushed = await pushSpace(spaceId, [versionId]);
  await streamBundle(source, target, spaceId, [versionId]);

  const indexSha = sha256(files["index.html"] ?? "");
  fake.putObject(objectKey(spaceId, indexSha), Buffer.from("not the indexed bytes"));

  const job = await createJob(target, "space_transfer_install", spaceId, {
    space_id: spaceId,
    version_ids: [versionId],
    bucket: bucketPayload(),
    live_version_id: null,
    artifact_source: { site_token_claims_hint: "test" },
    expected: pushed.versions,
  });
  const failed = await runJob(target, job.id);
  expect(failed.status).toBe("failed");
  expect(failed.error?.code).toBe("transfer_verify_failed");
});

test("abort removes staging and cancels queued transfer jobs for the space", async () => {
  const spaceId = nextId("spc_transfer_abort");
  const stagingFile = path.join(
    target.storageRoot,
    "transfer-staging",
    spaceId,
    "bundle",
    "routes",
    "x.json",
  );
  mkdirSync(path.dirname(stagingFile), { recursive: true });
  writeFileSync(stagingFile, "{}\n");

  const job = await createJob(target, "space_transfer_install", spaceId, {
    space_id: spaceId,
    version_ids: ["ver_abort"],
    bucket: bucketPayload(),
    live_version_id: null,
    artifact_source: { site_token_claims_hint: "test" },
    expected: [],
  });

  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/abort",
    "transfer_abort",
    { space_id: spaceId },
    { space_id: spaceId },
  );
  expect(existsSync(path.join(target.storageRoot, "transfer-staging", spaceId))).toBe(false);

  const loaded = await apiJson<{ job: JobRecord }>(
    target,
    "GET",
    `/__spacefast/api.php/jobs/${job.id}`,
    "get_engine_job",
    { job_id: job.id },
  );
  expect(loaded.job.status).toBe("failed");
  expect(loaded.job.error?.code).toBe("transfer_aborted");
});

test("abort preserves completed jobs and frees cancelled idempotency keys for re-dispatch", async () => {
  const spaceId = nextId("spc_transfer_abort_mix");
  const versionId = nextId("ver_transfer_abort_mix");
  await deployTransferFixture(spaceId, versionId);
  const pushJob = await createPush(spaceId, [versionId]);
  const completedPush = await runJob(source, pushJob.id);
  expect(completedPush.status).toBe("complete");

  // A second, still-queued job under a FIXED idempotency key.
  const idempotencyKey = `space_transfer_push:${spaceId}:retry-probe`;
  const queued = await apiJson<{ job: JobRecord }>(
    source,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: spaceId },
    {
      type: "space_transfer_push",
      idempotency_key: idempotencyKey,
      payload: {
        space_id: spaceId,
        version_ids: [versionId],
        bucket: bucketPayload(),
        check_only: false,
      },
    },
    201,
  );

  await apiJson(
    source,
    "POST",
    "/__spacefast/api.php/transfer/abort",
    "transfer_abort",
    { space_id: spaceId },
    { space_id: spaceId },
  );

  // The completed push survives the abort untouched.
  const survivor = await apiJson<{ job: JobRecord }>(
    source,
    "GET",
    `/__spacefast/api.php/jobs/${pushJob.id}`,
    "get_engine_job",
    { job_id: pushJob.id },
  );
  expect(survivor.job.status).toBe("complete");

  // The queued job was cancelled...
  const cancelled = await apiJson<{ job: JobRecord }>(
    source,
    "GET",
    `/__spacefast/api.php/jobs/${queued.job.id}`,
    "get_engine_job",
    { job_id: queued.job.id },
  );
  expect(cancelled.job.status).toBe("failed");
  expect(cancelled.job.error?.code).toBe("transfer_aborted");

  // ...and its idempotency key can be re-dispatched fresh (cancellation is not
  // a terminal failure of the work).
  const recreated = await apiJson<{ job: JobRecord }>(
    source,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    { space_id: spaceId },
    {
      type: "space_transfer_push",
      idempotency_key: idempotencyKey,
      payload: {
        space_id: spaceId,
        version_ids: [versionId],
        bucket: bucketPayload(),
        check_only: false,
      },
    },
    201,
  );
  expect(recreated.job.id).not.toBe(queued.job.id);
  expect(recreated.job.status).toBe("pending");
  const rerun = await runJob(source, recreated.job.id);
  expect(rerun.status).toBe("complete");
});

test("bundle export chunks large artifact sets and target mode stores every chunk", async () => {
  const spaceId = nextId("spc_transfer_chunks");
  const versionId = nextId("ver_transfer_chunks");
  await deployTransferFixture(spaceId, versionId);
  const routesRoot = path.join(source.storageRoot, "spaces", spaceId, "routes", "bulk");
  mkdirSync(routesRoot, { recursive: true });
  const largeRoute = "x".repeat(220_000);
  for (let i = 0; i < 10; i += 1) {
    writeFileSync(
      path.join(routesRoot, `route-${i}.json`),
      JSON.stringify({ route: i, body: largeRoute }),
    );
  }

  const streamed = await streamBundle(source, target, spaceId, [versionId]);
  expect(streamed.chunks).toBeGreaterThan(1);
  expect(streamed.sawNextCursor).toBe(true);
  expect(
    readFileSync(
      path.join(
        target.storageRoot,
        "transfer-staging",
        spaceId,
        "bundle",
        "routes",
        "bulk",
        "route-7.json",
      ),
      "utf8",
    ),
  ).toContain(largeRoute);
});

test("transfer bundle target mode rejects traversal paths", async () => {
  const response = await api(
    target,
    "POST",
    "/__spacefast/api.php/transfer/bundle",
    "transfer_bundle",
    { space_id: "spc_transfer_escape" },
    {
      space_id: "spc_transfer_escape",
      store: true,
      files: [{ path: "../escape.txt", content_b64: Buffer.from("x").toString("base64") }],
      done: true,
    },
  );
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("invalid_file_path");
  expect(existsSync(path.join(target.storageRoot, "escape.txt"))).toBe(false);
});

// G8 / plan §7 "the space's already-tiered blobs never move at all... a move
// is metadata-only": a shard entry the source already demoted must move as a
// locator only. Confirmed bug (fixed alongside this test): before the fix,
// _stattic_transfer_needed_blobs queued every shard sha for GET regardless of
// its `local` flag, and _stattic_transfer_stage_versions unconditionally
// blob_link'd every entry — a move silently re-hydrated every demoted file
// back to local disk on the target, undoing all prior tiering.
test("cold move: an already-demoted shard entry copies as a locator, never re-pulled or re-linked, and still serves from the target", async () => {
  const spaceId = nextId("spc_transfer_cold");
  const versionId = nextId("ver_transfer_cold");
  const host = `${spaceId.replaceAll("_", "-")}.test`;
  const big = "cold-move-payload\n".repeat(2200);
  await deploy(source, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>cold move</h1>\n", "assets/big.txt": big },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });

  const demoted = await demoteOnSource(spaceId, versionId);
  expect(demoted.status).toBe("complete");
  expect(
    existsSync(
      path.join(
        source.storageRoot,
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
  const bigSha = sha256(big);
  const remoteBefore = shardFiles(source, spaceId, versionId)["assets/big.txt"];
  expect(remoteBefore.local).toBe(false);
  expect(remoteBefore.remote!.key).toBe(objectKey(spaceId, bigSha));

  const pushed = await pushSpace(spaceId, [versionId]);
  // The demoted blob is already correctly placed in the (shared, per-space)
  // bucket keyspace — push must skip it, not re-PUT it.
  expect(pushed.blob_count).toBe(1); // only index.html is new
  expect(pushed.versions[0]?.file_count).toBe(2);

  await streamBundle(source, target, spaceId, [versionId]);
  fake.requests.splice(0);
  const installed = await installSpace(spaceId, [versionId], pushed.versions);
  expect(installed.status).toBe("complete");
  const installResult = installed.result as { blobs_pulled: number; bytes_pulled: number };
  // Only the non-demoted index.html blob gets pulled onto the target.
  expect(installResult.blobs_pulled).toBe(1);
  expect(installResult.bytes_pulled).toBe(Buffer.byteLength("<h1>cold move</h1>\n"));
  const getKeys = fake.requests
    .filter((request) => request.method === "GET")
    .map((request) => request.path);
  expect(getKeys.some((key) => key.includes(bigSha))).toBe(false);

  const targetStagedBig = path.join(
    target.storageRoot,
    "transfer-staging",
    spaceId,
    "versions",
    versionId,
    "files",
    "assets",
    "big.txt",
  );
  expect(existsSync(targetStagedBig)).toBe(false);

  await apiJson(
    target,
    "POST",
    "/__spacefast/api.php/transfer/commit",
    "transfer_commit",
    { space_id: spaceId },
    { space_id: spaceId, version_ids: [versionId], live_version_id: versionId },
  );

  // The committed target version never got the demoted file's bytes locally,
  // but its shard metadata still carries the SAME bucket/key locator.
  expect(
    existsSync(
      path.join(
        target.storageRoot,
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
  const remoteAfter = shardFiles(target, spaceId, versionId)["assets/big.txt"];
  expect(remoteAfter.local).toBe(false);
  expect(remoteAfter.remote!.key).toBe(objectKey(spaceId, bigSha));

  // Serving from the target still works: the tiered fallback fetches the
  // SAME shared-bucket object the source pushed.
  fake.requests.splice(0);
  const served = await get(target, host, "/assets/big.txt");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(big);
  expect(
    fake.requests.some((request) => request.method === "GET" && request.path.includes(bigSha)),
  ).toBe(true);
});
