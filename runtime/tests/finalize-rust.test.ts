// Native (Rust) finalize path: publish -> finalize -> serve driven entirely by
// the stattic-runtime-compiler binary, fail-closed 503 when the binary is
// missing, process timeout reaping, and replacement move-aside/restore.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, existsSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  createDeclaredSession,
  deploy,
  errorCode,
  get,
  putFile,
  type Runtime,
  startRuntime,
} from "./harness.ts";

const REPO_ROOT = path.resolve(import.meta.dir, "../..");
const RUNTIME_ENGINE = path.resolve(import.meta.dir, "../engine");
const HOST = "native-finalize.test";
const AUTH_HOST = "native-finalize-auth.test";

let rt: Runtime;
let rtMissing: Runtime;
let compilerPath: string;
let wrapperRoot: string;
let wrapperPath: string;
let failMarkerPath: string;
let probePath: string;

function finalize(
  spaceId: string,
  versionId: string,
  body: Record<string, unknown>,
): Promise<Response> {
  return api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: spaceId, version_id: versionId },
    body,
  );
}

beforeAll(async () => {
  const build = Bun.spawnSync({
    cmd: ["cargo", "build", "--locked", "-p", "stattic-runtime-compiler"],
    cwd: REPO_ROOT,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stdout.toString()}\n${build.stderr.toString()}`);
  }
  compilerPath = path.join(REPO_ROOT, "target/debug/stattic-runtime-compiler");

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
const result = spawnSync(${JSON.stringify(compilerPath)}, process.argv.slice(2), { stdio: "inherit" });
process.exit(result.status ?? 1);
`,
  );
  chmodSync(wrapperPath, 0o755);

  rt = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_FINALIZER_BIN: wrapperPath,
    },
  });
  rtMissing = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_FINALIZER_BIN: path.join(wrapperRoot, "does-not-exist"),
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
      config: { mode: "website", site_title: "Native Finalize" },
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

  const journal = readFileSync(path.join(rt.storageRoot, "runtime/journal.jsonl"), "utf8")
    .trim()
    .split("\n")
    .map((line) => JSON.parse(line) as Record<string, unknown>);
  const finalized = journal.findLast(
    (event) => event.event === "version_finalized" && event.version_id === "ver_native_1",
  );
  expect(finalized).toBeDefined();
  expect(finalized).not.toHaveProperty("changed_paths");
  expect(finalized).not.toHaveProperty("unchanged");
});

test("raw Basic-Auth conventions flow into the unified access policy on activation", async () => {
  const spaceId = "spc_native_auth";
  const versionId = "ver_native_auth_1";
  const files: Record<string, string> = {
    "index.html": "<h1>auth home</h1>\n",
    "private/secret.html": "<h1>secret</h1>\n",
    _headers: "/private/*\n  Basic-Auth: reader:letmein\n",
  };
  const { uploadId, token } = await createDeclaredSession(rt, spaceId, versionId, files, {
    mode: "website",
    title: "Native Auth",
  });
  for (const [filePath, content] of Object.entries(files)) {
    const uploaded = await putFile(rt, uploadId, token, filePath, content);
    expect(uploaded.status).toBe(200);
  }
  const activation = {
    route_name: "production",
    config: { mode: "website" },
    production_hostnames: [AUTH_HOST],
    noindex_production_hostnames: [],
    version_hostnames: [],
  };
  const response = await finalize(spaceId, versionId, {
    upload_id: uploadId,
    activate: activation,
  });
  expect(response.status).toBe(200);
  const body = (await response.json()) as {
    status: string;
    zero_endpoint_count: number;
    access_rules: Array<{ id?: string }>;
    access_secrets: Record<string, string>;
  };
  expect(body.status).toBe("ready");
  expect(body.access_rules.length).toBe(1);
  const secretNames = Object.keys(body.access_secrets);
  expect(secretNames.length).toBe(1);
  // Verifier hashes only — the plaintext never crosses the finalizer boundary.
  expect(body.access_secrets[secretNames[0] ?? ""]).not.toContain("letmein");

  // The activation wrote the generated rule + verifier into the space's
  // unified policy lane (the same write the route pointer uses).
  const policy = JSON.parse(
    readFileSync(path.join(rt.storageRoot, "spaces", spaceId, "policy.json"), "utf8"),
  ) as { policy: { rules: Array<{ id?: string }> } };
  expect(policy.policy.rules.length).toBe(1);
  expect(policy.policy.rules[0]?.id).toBe(body.access_rules[0]?.id);
  const secrets = JSON.parse(
    readFileSync(path.join(rt.storageRoot, "spaces", spaceId, "policy-secrets.json"), "utf8"),
  ) as { secrets: Record<string, string> };
  expect(Object.keys(secrets.secrets)).toEqual(secretNames);

  // The plain page still serves.
  const home = await get(rt, AUTH_HOST, "/");
  expect(home.status).toBe(200);

  // If another publish replaces the pointer and its policy, a retry of the
  // already-finalized native version must reconstruct both from persisted
  // Rust output even though the upload session is gone.
  await deploy(rt, {
    spaceId,
    versionId: "ver_native_auth_plain",
    files: { "index.html": "<h1>plain replacement</h1>\n" },
    activate: activation,
  });
  const retry = await finalize(spaceId, versionId, {
    upload_id: uploadId,
    activate: activation,
  });
  expect(retry.status).toBe(200);
  const retriedPolicy = JSON.parse(
    readFileSync(path.join(rt.storageRoot, "spaces", spaceId, "policy.json"), "utf8"),
  ) as { policy: { rules: Array<{ id?: string }> } };
  expect(retriedPolicy.policy.rules[0]?.id).toBe(body.access_rules[0]?.id);
  const retriedSecrets = JSON.parse(
    readFileSync(path.join(rt.storageRoot, "spaces", spaceId, "policy-secrets.json"), "utf8"),
  ) as { secrets: Record<string, string> };
  expect(Object.keys(retriedSecrets.secrets)).toEqual(secretNames);
  expect(await (await get(rt, AUTH_HOST, "/")).text()).toBe("<h1>auth home</h1>\n");
  expect((await get(rt, AUTH_HOST, "/private/secret.html")).status).toBe(401);
});

test("missing binary fails closed with 503 and builds nothing", async () => {
  const spaceId = "spc_native_missing";
  const versionId = "ver_native_missing_1";
  const files = { "index.html": "<h1>never built</h1>\n" };
  const { uploadId, token } = await createDeclaredSession(rtMissing, spaceId, versionId, files);
  const uploaded = await putFile(rtMissing, uploadId, token, "index.html", files["index.html"]);
  expect(uploaded.status).toBe(200);
  const response = await api(
    rtMissing,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: spaceId, version_id: versionId },
    { upload_id: uploadId },
  );
  expect(response.status).toBe(503);
  expect(await errorCode(response)).toBe("finalize_unavailable");
  // Fail closed means no PHP fallback: nothing was materialized.
  expect(
    existsSync(path.join(rtMissing.storageRoot, "spaces", spaceId, "versions", versionId)),
  ).toBe(false);
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
        `require ${JSON.stringify(path.join(RUNTIME_ENGINE, "admin/finalize-rust.php"))};`,
        `$result = _stattic_runtime_run_finalizer_process([${JSON.stringify(sleeperPath)}], sys_get_temp_dir(), 1000);`,
        "echo json_encode([$result[0], $result[3]]);",
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
  expect(
    (await putFile(rt, first.uploadId, first.token, "index.html", originalFiles["index.html"]))
      .status,
  ).toBe(200);
  expect(
    (await putFile(rt, second.uploadId, second.token, "index.html", replacementFiles["index.html"]))
      .status,
  ).toBe(200);

  const initial = await finalize(spaceId, versionId, { upload_id: first.uploadId });
  expect(initial.status).toBe(200);
  const versionRoot = path.join(rt.storageRoot, "spaces", spaceId, "versions", versionId);
  expect(readFileSync(path.join(versionRoot, "files/index.html"), "utf8")).toBe(
    "<h1>original</h1>\n",
  );

  // Force the binary to fail; it records whether the version dir was present
  // when it started.
  rmSync(probePath, { force: true });
  writeFileSync(failMarkerPath, versionRoot);
  try {
    const failed = await finalize(spaceId, versionId, { upload_id: second.uploadId });
    expect(failed.status).toBe(422);
    expect(await errorCode(failed)).toBe("native_test_failure");
  } finally {
    rmSync(failMarkerPath, { force: true });
  }
  // PHP moved the previous immutable version aside before invoking the binary.
  const probe = JSON.parse(readFileSync(probePath, "utf8")) as { versionDirExists: boolean };
  expect(probe.versionDirExists).toBe(false);
  // The failure restored it: same bytes, no backup or quarantine debris left.
  expect(readFileSync(path.join(versionRoot, "files/index.html"), "utf8")).toBe(
    "<h1>original</h1>\n",
  );
  const siblings = readdirSync(path.dirname(versionRoot));
  expect(siblings.some((name) => name.includes(".rust-previous"))).toBe(false);
  expect(siblings.some((name) => name.includes(".rust-failed"))).toBe(false);

  // With the real binary back, a replacement finalize from different input is
  // rejected against the immutable version — and leaves it untouched.
  const mismatch = await finalize(spaceId, versionId, { upload_id: second.uploadId });
  expect(mismatch.status).toBe(409);
  expect(await errorCode(mismatch)).toBe("version_existing_mismatch");
  expect(readFileSync(path.join(versionRoot, "files/index.html"), "utf8")).toBe(
    "<h1>original</h1>\n",
  );
});
