// Native (Rust) finalize path: publish -> finalize -> serve driven entirely by
// the stattic-runtime-compiler binary, fail-closed 503 when the binary is
// missing, process timeout reaping, and replacement move-aside/restore.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, existsSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { runtimeVersionFinalizeResponseSchema } from "../../packages/common/src/contracts/runtime-api.ts";
import {
  createDeclaredSession,
  deploy,
  errorCode,
  finalize,
  finalizeRaw,
  get,
  journalRecords,
  publicAccessConfig,
  readBlob,
  responseEntry,
  type Runtime,
  startRuntime,
  uploadSessionBlobs,
  versionRoot,
} from "./harness.ts";

const REPO_ROOT = path.resolve(import.meta.dir, "../..");
const RUNTIME_ENGINE = path.resolve(import.meta.dir, "../engine");
const HOST = "native-finalize.test";

let rt: Runtime;
let rtMissing: Runtime;
let runtimePath: string;
let wrapperRoot: string;
let wrapperPath: string;
let failMarkerPath: string;
let probePath: string;

/**
 * What a published version actually answers with at `key`, read through the v4
 * artifact chain: root.json -> root-<h16>.php -> responses-<h16>.php -> the CAS
 * blob the entry names (contracts §2/§5). Version trees hold no bytes any more,
 * so this — not a `files/` read — is how a test proves which bytes a version owns.
 */
function publishedBody(spaceId: string, versionId: string, key: string): string | null {
  const sha = responseEntry(rt, spaceId, versionId, key)?.b;
  if (typeof sha !== "string") return null;
  return readBlob(rt, spaceId, sha)?.toString("utf8") ?? null;
}

beforeAll(async () => {
  const build = Bun.spawnSync({
    cmd: [
      "cargo",
      "build",
      "--locked",
      "-p",
      "stattic-runtime-compiler",
      "--bin",
      "stattic-runtime",
    ],
    cwd: REPO_ROOT,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stdout.toString()}\n${build.stderr.toString()}`);
  }
  runtimePath = path.join(REPO_ROOT, "target/debug/stattic-runtime");

  // Wrapper binary: passes through to the real compiler unless the fail
  // marker exists. On a forced failure it records whether the version dir
  // named inside the marker existed at invocation time — the observable proof
  // that PHP moved the previous version aside before starting the binary.
  const bunPath = Bun.which("bun") ?? "/usr/bin/env bun";
  wrapperRoot = path.join(
    "/tmp",
    `stattic-native-finalize-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  );
  wrapperPath = path.join(wrapperRoot, "compiler-wrapper.mjs");
  failMarkerPath = path.join(wrapperRoot, "fail-marker");
  probePath = path.join(wrapperRoot, "probe.json");
  await Bun.$`mkdir -p ${wrapperRoot}`;
  writeFileSync(
    wrapperPath,
    `#!${bunPath}
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
if (existsSync(${JSON.stringify(failMarkerPath)})) {
  const versionDir = readFileSync(${JSON.stringify(failMarkerPath)}, "utf8").trim();
  writeFileSync(
    ${JSON.stringify(probePath)},
    JSON.stringify({ versionDirExists: existsSync(versionDir) }),
  );
  const outputIndex = process.argv.indexOf("--output");
  if (outputIndex < 0 || !process.argv[outputIndex + 1]) process.exit(3);
  writeFileSync(
    process.argv[outputIndex + 1],
    JSON.stringify({
      format: "stattic.runtime.finalize.error.v2",
      code: "native_test_failure",
      message: "Forced by the native test wrapper.",
      details: {},
    }),
  );
  process.stderr.write("native test wrapper forced a structured failure\\n");
  process.exit(2);
}
const result = spawnSync(${JSON.stringify(runtimePath)}, process.argv.slice(2), { stdio: "inherit" });
process.exit(result.status ?? 1);
`,
  );
  chmodSync(wrapperPath, 0o755);

  rt = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_BIN: wrapperPath,
    },
  });
  rtMissing = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_BIN: path.join(wrapperRoot, "does-not-exist"),
    },
  });
});

afterAll(() => {
  rt?.stop();
  rtMissing?.stop();
  if (wrapperRoot) {
    rmSync(wrapperRoot, { recursive: true, force: true });
  }
});

test("native finalize publishes and serves a site end to end", async () => {
  await deploy(rt, {
    spaceId: "spc_native",
    versionId: "ver_native_1",
    metadata: { mode: "website", title: "Native Finalize" },
    files: {
      "index.html": "<h1>native home</h1>\n",
      "docs/guide.html": "<h1>guide</h1>\n",
      _redirects: "/old /docs/guide.html 301\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Native Finalize" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const home = await get(rt, HOST, "/");
  expect(home.status).toBe(200);
  expect(await home.text()).toBe("<h1>native home</h1>\n");

  const guide = await get(rt, HOST, "/docs/guide.html");
  expect(guide.status).toBe(200);
  expect(await guide.text()).toBe("<h1>guide</h1>\n");

  // The committed `_redirects` convention compiled inside the Rust finalizer
  // and is enforced by the serving path.
  const redirect = await get(rt, HOST, "/old");
  expect(redirect.status).toBe(301);
  expect(redirect.headers.get("location")).toBe("/docs/guide.html");

  const missing = await get(rt, HOST, "/nope");
  expect(missing.status).toBe(404);

  const finalized = journalRecords(rt).findLast(
    (event) => event.event === "version_finalized" && event.version_id === "ver_native_1",
  );
  expect(finalized).toMatchObject({
    space_id: "spc_native",
    version_id: "ver_native_1",
    route_name: "production",
  });
});

// The space card addresses this path on the version's own public URL, so the
// choice has to be a path the version SERVES publicly. It is made once, in the
// finalizer, from the catalog — the control plane stores what it is told.
test("finalize names the version's first public served image for the space card", async () => {
  const files = {
    ".hidden/cover.png": "private cover bytes\n",
    "assets/logo.png": "public logo bytes\n",
    "assets/zzz.png": "second image\n",
    "index.html": "<h1>gallery</h1>\n",
  };
  const session = await createDeclaredSession(rt, "spc_preview", "ver_preview_1", files);
  await uploadSessionBlobs(rt, session, files);
  const response = await finalize(rt, "spc_preview", "ver_preview_1", {
    upload_id: session.uploadId,
  });
  expect(response.status).toBe(200);
  // Parsed through the control plane's own contract, so this receipt is
  // checked against the shape that consumer requires rather than a restatement
  // of it here.
  const body = runtimeVersionFinalizeResponseSchema.parse(await response.json());
  // Not `.hidden/cover.png`, which sorts first but is never served, and not
  // `assets/zzz.png`, which is an image but not the first one.
  expect(body.preview_image_path).toBe("assets/logo.png");
  // Stage telemetry describes the RUN, so unlike everything else in this
  // receipt it cannot be read back from immutable metadata: it rides the
  // native finalizer's envelope through PHP untouched.
  expect(body.telemetry?.stagedFiles).toBe(Object.keys(files).length);
  expect(body.telemetry?.skippedFiles).toBe(0);

  const textOnly = { "index.html": "<h1>no pictures</h1>\n" };
  const plain = await createDeclaredSession(rt, "spc_preview", "ver_preview_2", textOnly);
  await uploadSessionBlobs(rt, plain, textOnly);
  const plainResponse = await finalize(rt, "spc_preview", "ver_preview_2", {
    upload_id: plain.uploadId,
  });
  expect(plainResponse.status).toBe(200);
  expect(
    ((await plainResponse.json()) as { preview_image_path: string | null }).preview_image_path,
  ).toBeNull();
});

test("missing binary fails closed with 503 and builds nothing", async () => {
  const spaceId = "spc_native_missing";
  const versionId = "ver_native_missing_1";
  const response = await finalizeRaw(
    rtMissing,
    spaceId,
    versionId,
    { "index.html": "<h1>never built</h1>\n" },
    {},
  );
  expect(response.status).toBe(503);
  expect(await errorCode(response)).toBe("finalize_unavailable");
  // Fail closed means no PHP fallback: no version root, so nothing to serve
  // and nothing that reads as finalized (root.json is the finalized marker).
  expect(existsSync(versionRoot(rtMissing, spaceId, versionId))).toBe(false);
});

test("timed-out native child is killed and reaped within the deadline", () => {
  const sleeperPath = path.join(wrapperRoot, "sleeper.sh");
  writeFileSync(sleeperPath, "#!/bin/sh\nexec sleep 60\n");
  chmodSync(sleeperPath, 0o755);
  const started = Date.now();
  const probe = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      [
        `require ${JSON.stringify(path.join(RUNTIME_ENGINE, "shared/native-process.php"))};`,
        `$result = _stattic_runtime_run_subprocess([${JSON.stringify(sleeperPath)}], null, null, sys_get_temp_dir(), 1000);`,
        "echo json_encode([$result['exitCode'], $result['timedOut']]);",
      ].join(" "),
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  const elapsedMs = Date.now() - started;
  expect(probe.exitCode).toBe(0);
  const [exitCode, timedOut] = JSON.parse(probe.stdout.toString()) as [number, boolean];
  expect(timedOut).toBe(true);
  expect(exitCode).not.toBe(0);
  // The 60s sleeper was killed and reaped at the 1s deadline — the call
  // returned as soon as the child was gone, not when the sleep finished.
  expect(elapsedMs).toBeLessThan(10_000);
});

test("replacement moves the previous version aside and a failure restores it", async () => {
  const spaceId = "spc_native_replace";
  const versionId = "ver_native_replace_1";
  const originalFiles = { "index.html": "<h1>original</h1>\n" };
  const replacementFiles = { "index.html": "<h1>replacement</h1>\n" };

  // Both sessions exist before the version does (create_version rejects
  // sessions for committed versions) — the second one is the crash-window
  // re-finalize that meets an already-published immutable version.
  const first = await createDeclaredSession(rt, spaceId, versionId, originalFiles);
  const second = await createDeclaredSession(rt, spaceId, versionId, replacementFiles);
  await uploadSessionBlobs(rt, first, originalFiles);
  await uploadSessionBlobs(rt, second, replacementFiles);

  const initial = await finalize(rt, spaceId, versionId, { upload_id: first.uploadId });
  expect(initial.status).toBe(200);
  const root = versionRoot(rt, spaceId, versionId);
  expect(publishedBody(spaceId, versionId, "/index.html")).toBe("<h1>original</h1>\n");

  // Force the binary to fail; it records whether the version dir was present
  // when it started.
  rmSync(probePath, { force: true });
  writeFileSync(failMarkerPath, root);
  try {
    const failed = await finalize(rt, spaceId, versionId, { upload_id: second.uploadId });
    expect(failed.status).toBe(422);
    expect(await errorCode(failed)).toBe("native_test_failure");
  } finally {
    rmSync(failMarkerPath, { force: true });
  }
  // PHP moved the previous immutable version aside before invoking the binary.
  const probe = JSON.parse(readFileSync(probePath, "utf8")) as { versionDirExists: boolean };
  expect(probe.versionDirExists).toBe(false);
  // The failure restored it: the same pointer chain answers with the same
  // bytes, and no backup or quarantine debris is left behind.
  expect(publishedBody(spaceId, versionId, "/index.html")).toBe("<h1>original</h1>\n");
  const siblings = readdirSync(path.dirname(root));
  expect(siblings.some((name) => name.includes(".rust-previous"))).toBe(false);
  expect(siblings.some((name) => name.includes(".rust-failed"))).toBe(false);

  // With the real binary back, a replacement finalize from different input is
  // rejected against the immutable version — and leaves it untouched.
  const mismatch = await finalize(rt, spaceId, versionId, { upload_id: second.uploadId });
  expect(mismatch.status).toBe(409);
  expect(await errorCode(mismatch)).toBe("version_existing_mismatch");
  expect(publishedBody(spaceId, versionId, "/index.html")).toBe("<h1>original</h1>\n");
});
