// Behavioral coverage for shared/s3.php (plan §26/§28, DECISIONS I-12/I-13).
// Drives the real signer/client via a dedicated PHP CLI entry point
// (s3-cli.php) against the in-process fake S3 fixture (s3-fake.ts) — no
// source-text assertions, only real HTTP round trips and real PHP output.
//
// shared/s3.php is not yet in engine-manifest.json (a later integrator wires
// the tiered-serve/moves-V2 call sites and adds it there), so this suite
// bypasses the manifest-driven startRuntime() harness and spawns s3-cli.php
// directly against its real repo path instead. See s3-cli.php's header.
import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { startFakeS3, type FakeS3 } from "./s3-fake.ts";

const S3_CLI_PATH = path.resolve(import.meta.dir, "s3-cli.php");

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
  integrity: string;
};

function bucketRow(fake: FakeS3, urlStyle: "path" | "vhost", endpointHost: string): BucketRow {
  return {
    id: "test-bucket",
    endpoint: `http://${endpointHost}:${new URL(fake.url).port}`,
    region: "us-east-1",
    bucket: fake.bucket,
    urlStyle,
    getKeyId: "GETKEY",
    getKeySecret: "get-secret-value",
    putKeyId: "PUTKEY",
    putKeySecret: "put-secret-value",
    integrity: "server_verified",
  };
}

function resolveEntry(fake: FakeS3, host: string): string {
  return `${host}:${new URL(fake.url).port}:127.0.0.1`;
}

async function runS3Cli(request: Record<string, unknown>, manifest: BucketRow[]): Promise<any> {
  const proc = Bun.spawn(["php", S3_CLI_PATH, JSON.stringify(request)], {
    env: { ...process.env, SPACEFAST_STORAGE_BUCKETS_JSON: JSON.stringify(manifest) },
    stdout: "pipe",
    stderr: "pipe",
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  if (exitCode !== 0) {
    throw new Error(`s3-cli.php exited ${exitCode}: ${stderr}`);
  }
  return JSON.parse(stdout.trim());
}

// Test-only: exercises _stattic_s3_sign()/_stattic_s3_locator() directly with
// a deliberately wrong payload hash (production code paths never do this —
// every PUT call site in shared/s3.php computes the real hash) to prove the
// fixture (and by extension a real endpoint per the plan §18 probe) rejects
// a mismatched x-amz-content-sha256 with 400, independent of the
// Authorization-shape check.
async function runS3CliPutWrongHash(
  request: Record<string, unknown>,
  manifest: BucketRow[],
): Promise<any> {
  return runS3Cli({ ...request, op: "put_wrong_hash" }, manifest);
}

describe("shared/s3.php SigV4 signer + client", () => {
  let fake: FakeS3;
  let tmpDir: string;

  beforeAll(async () => {
    fake = await startFakeS3("placement-v2-test-bucket");
    tmpDir = mkdtempSync(path.join(os.tmpdir(), "stattic-s3-test-"));
  });

  afterAll(() => {
    fake.stop();
    rmSync(tmpDir, { recursive: true, force: true });
  });

  test("signed GET round-trip: path-style addressing", async () => {
    const body = Buffer.from("hello from path-style\n");
    fake.putObject("spaces/spc_1/blobs/ab/abc123", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/ab/abc123",
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
    expect(Buffer.from(result.body_base64, "base64").toString()).toBe(body.toString());

    const getRequest = fake.requests.at(-1);
    expect(getRequest?.method).toBe("GET");
    expect(getRequest?.path).toBe("/placement-v2-test-bucket/spaces/spc_1/blobs/ab/abc123");
  });

  test("signed GET round-trip: virtual-host-style addressing", async () => {
    const body = Buffer.from("hello from vhost-style\n");
    fake.putObject("spaces/spc_1/blobs/cd/cdef456", body);
    const manifest = [bucketRow(fake, "vhost", "s3.fake.test")];
    const vhostHost = `${fake.bucket}.s3.fake.test`;

    const result = await runS3Cli(
      {
        op: "get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/cd/cdef456",
        options: { resolve: [resolveEntry(fake, vhostHost)] },
      },
      manifest,
    );

    expect(result.ok).toBe(true);
    expect(Buffer.from(result.body_base64, "base64").toString()).toBe(body.toString());
  });

  test("HEAD returns metadata without a body", async () => {
    const body = Buffer.from("head me\n");
    fake.putObject("spaces/spc_1/blobs/11/head-object", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "head",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/11/head-object",
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
    expect(Buffer.from(result.body_base64, "base64").length).toBe(0);
  });

  test("PUT signs the real payload hash and the fixture accepts it", async () => {
    const body = Buffer.from("uploaded content\n");
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "put",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/ef/uploaded",
        body_base64: body.toString("base64"),
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
    const stored = fake.getObject("spaces/spc_1/blobs/ef/uploaded");
    expect(stored?.body.toString()).toBe(body.toString());

    const putRequest = fake.requests.find((r) => r.method === "PUT");
    expect(putRequest?.headers["x-amz-content-sha256"]).toBeDefined();
    expect(putRequest?.headers["x-amz-content-sha256"]).not.toBe("UNSIGNED-PAYLOAD");
  });

  test("PUT with a mismatched declared payload hash is rejected by the fixture", async () => {
    const body = Buffer.from("this body does not match the declared hash\n");
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3CliPutWrongHash(
      {
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/ff/should-not-land",
        body_base64: body.toString("base64"),
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.ok).toBe(false);
    expect(result.status).toBe(400);
    expect(fake.getObject("spaces/spc_1/blobs/ff/should-not-land")).toBeUndefined();
  });

  test("Range relay: satisfiable range answers 206 with Content-Range", async () => {
    const body = Buffer.from("0123456789");
    fake.putObject("spaces/spc_1/blobs/22/ranged", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/22/ranged",
        options: { range: "bytes=2-4", resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.status).toBe(206);
    expect(Buffer.from(result.body_base64, "base64").toString()).toBe("234");
    expect(result.headers["content-range"]).toBe("bytes 2-4/10");
  });

  test("Range relay: unsatisfiable range answers 416", async () => {
    const body = Buffer.from("short");
    fake.putObject("spaces/spc_1/blobs/33/short", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/33/short",
        options: { range: "bytes=1000-2000", resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.status).toBe(416);
  });

  test("If-None-Match short-circuits to 304", async () => {
    const body = Buffer.from("cached content\n");
    const object = fake.putObject("spaces/spc_1/blobs/44/cached", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/44/cached",
        options: { if_none_match: object.etag, resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.status).toBe(304);
    expect(Buffer.from(result.body_base64, "base64").length).toBe(0);
  });

  test("_stattic_s3_open streams a signed GET via write/header callbacks", async () => {
    const body = Buffer.from("streamed via curl write callback\n");
    fake.putObject("spaces/spc_1/blobs/55/streamed", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "stream_get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/55/streamed",
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
    expect(Buffer.from(result.body_base64, "base64").toString()).toBe(body.toString());
  });

  test("_stattic_s3_open relays a 206 Range response through the header callback", async () => {
    const body = Buffer.from("abcdefghij");
    fake.putObject("spaces/spc_1/blobs/66/streamed-range", body);
    const manifest = [bucketRow(fake, "path", "s3.fake.test")];

    const result = await runS3Cli(
      {
        op: "stream_get",
        bucket_id: "test-bucket",
        key: "spaces/spc_1/blobs/66/streamed-range",
        range: "bytes=0-2",
        options: { resolve: [resolveEntry(fake, "s3.fake.test")] },
      },
      manifest,
    );

    expect(result.status).toBe(206);
    expect(Buffer.from(result.body_base64, "base64").toString()).toBe("abc");
    expect(result.headers["content-range"]).toBe("bytes 0-2/10");
  });

  test("parallel multi-GET: happy path across items respects the stream cap", async () => {
    // multi_get has no per-item `options`/resolve bag (unlike the single-item
    // get/put/head/stream_get ops above, which already cover vhost/path-style
    // DNS-override plumbing) — point the endpoint straight at the loopback IP
    // so this test isolates the parallel-transfer behavior itself.
    const ipManifest = [{ ...bucketRow(fake, "path", "s3.fake.test"), endpoint: fake.url }];
    const items = ["a", "b", "c", "d", "e"].map((letter) => {
      const key = `spaces/spc_1/blobs/multi/${letter}`;
      const body = Buffer.from(`multi-get payload ${letter}\n`);
      const object = fake.putObject(key, body);
      return {
        id: letter,
        bucket: "test-bucket",
        key,
        dest_path: path.join(tmpDir, `multi-ok-${letter}`),
        sha256: object.sha256,
      };
    });

    const result = await runS3Cli({ op: "multi_get", items, streams: 2 }, ipManifest);

    for (const letter of ["a", "b", "c", "d", "e"]) {
      expect(result[letter].ok).toBe(true);
      expect(result[letter].bytes).toBeGreaterThan(0);
      const written = readFileSync(path.join(tmpDir, `multi-ok-${letter}`), "utf8");
      expect(written).toBe(`multi-get payload ${letter}\n`);
    }
  });

  test("parallel multi-GET: sha mismatch is caught and no corrupted file is left behind", async () => {
    const key = "spaces/spc_1/blobs/multi/corrupt";
    fake.putObject(key, Buffer.from("the real bytes\n"));
    const ipManifest = [{ ...bucketRow(fake, "path", "s3.fake.test"), endpoint: fake.url }];
    const destPath = path.join(tmpDir, "multi-corrupt");

    const result = await runS3Cli(
      {
        op: "multi_get",
        items: [
          {
            id: "corrupt",
            bucket: "test-bucket",
            key,
            dest_path: destPath,
            // Deliberately wrong expected digest — simulates a corrupted
            // download (bytes changed in flight) being caught by the
            // hash_update-in-the-write-callback verification.
            sha256: "0".repeat(64),
          },
        ],
      },
      ipManifest,
    );

    expect(result.corrupt.ok).toBe(false);
    expect(result.corrupt.error).toBe("s3_integrity_mismatch");
    expect(() => readFileSync(destPath)).toThrow();
    expect(() => readFileSync(`${destPath}.part`)).toThrow();
  });

  test("parallel multi-PUT: uploads local files and verifies fixture receipt", async () => {
    const fs = await import("node:fs");
    const sourceA = path.join(tmpDir, "put-source-a.txt");
    const sourceB = path.join(tmpDir, "put-source-b.txt");
    fs.writeFileSync(sourceA, "multi-put payload a\n");
    fs.writeFileSync(sourceB, "multi-put payload b\n");
    const ipManifest = [{ ...bucketRow(fake, "path", "s3.fake.test"), endpoint: fake.url }];

    const result = await runS3Cli(
      {
        op: "multi_put",
        items: [
          {
            id: "a",
            bucket: "test-bucket",
            key: "spaces/spc_1/blobs/multi-put/a",
            source_path: sourceA,
          },
          {
            id: "b",
            bucket: "test-bucket",
            key: "spaces/spc_1/blobs/multi-put/b",
            source_path: sourceB,
          },
        ],
      },
      ipManifest,
    );

    expect(result.a.ok).toBe(true);
    expect(result.b.ok).toBe(true);
    expect(fake.getObject("spaces/spc_1/blobs/multi-put/a")?.body.toString()).toBe(
      "multi-put payload a\n",
    );
    expect(fake.getObject("spaces/spc_1/blobs/multi-put/b")?.body.toString()).toBe(
      "multi-put payload b\n",
    );
  });

  test("an injected 503 surfaces as a clean HTTP-level failure, not a crash", async () => {
    fake.failNext(1, 503);
    const ipManifest = [{ ...bucketRow(fake, "path", "s3.fake.test"), endpoint: fake.url }];

    const result = await runS3Cli(
      { op: "get", bucket_id: "test-bucket", key: "spaces/spc_1/blobs/does-not-matter/x" },
      ipManifest,
    );

    expect(result.ok).toBe(false);
    expect(result.status).toBe(503);
  });

  test("an unreachable endpoint surfaces a clean transport error code, not a hang", async () => {
    // Port 1 is a privileged, never-bound port — connection is refused
    // immediately, exercising the transport-failure path without waiting out
    // the real 10s connect / 30s total timeouts.
    const manifest = [
      { ...bucketRow(fake, "path", "s3.fake.test"), endpoint: "http://127.0.0.1:1" },
    ];

    const result = await runS3Cli(
      { op: "get", bucket_id: "test-bucket", key: "anything" },
      manifest,
    );

    expect(result.ok).toBe(false);
    expect(result.status).toBe(0);
    expect(typeof result.error).toBe("string");
    expect(result.error.startsWith("s3_transport_error:")).toBe(true);
  });
});
