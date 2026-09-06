// POST /engine/update is the FPM receipt lane. Installation belongs to the
// provider task; this seam validates the requested release, proves an exact
// active release, and reports divergence without starting a child process.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { writeActiveReleasePointer } from "./active-release.ts";
import { api, errorCode, RUNTIME_HTTP_API_BASE, startRuntime, type Runtime } from "./harness";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

const UPDATE_BODY = {
  revision: "9f3c2ab7d1e4a06b",
  zip_url: "https://github.example/engines/9f3c2ab7d1e4a06b.zip",
  md5: "6d34a1f0c8b2e97d5a41f3c9b0d2e816",
  native_sha256: "b".repeat(64),
};

function update(body: unknown, runtime = rt): Promise<Response> {
  return api(runtime, "POST", `${RUNTIME_HTTP_API_BASE}/engine/update`, "update_engine", {}, body);
}

function sourceUpdateBody() {
  const nativeSha256 = createHash("sha256")
    .update(readFileSync(path.join(path.dirname(rt.engineRoot), "bin/stattic-runtime")))
    .digest("hex");
  return { ...UPDATE_BODY, revision: "source-tree", native_sha256: nativeSha256 };
}

test("rejects an invalid revision", async () => {
  const response = await update({ ...UPDATE_BODY, revision: "not a revision" });
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("runtime_engine_update_invalid");
});

test("answers current for the running revision without spawning anything", async () => {
  const response = await update(sourceUpdateBody());
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({
    status: "current",
    engine_revision: "source-tree",
    layout: "release",
  });
});

test("returns a retryable HTTP receipt while publication is locked", async () => {
  const lockPath = path.join(rt.root, ".stattic/publication.lock");
  const holder = Bun.spawn({
    cmd: [
      "php",
      "-r",
      '$lock = fopen($argv[1], "ce"); flock($lock, LOCK_EX); echo "locked\\n"; flush(); sleep(30);',
      lockPath,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  const reader = holder.stdout.getReader();
  try {
    const ready = await reader.read();
    expect(new TextDecoder().decode(ready.value)).toContain("locked");
    const response = await update(sourceUpdateBody());
    expect(response.status).toBe(503);
    expect(response.headers.get("retry-after")).toBe("1");
    expect(await errorCode(response)).toBe("runtime_engine_update_busy");
  } finally {
    holder.kill("SIGKILL");
    await holder.exited;
    reader.releaseLock();
  }
  expect((await update(sourceUpdateBody())).status).toBe(200);
});

test("rejects loader drift and incomplete transactions", async () => {
  const installRoot = path.join(rt.root, ".stattic");
  const marker = path.join(installRoot, "loader-version");
  const markerBytes = readFileSync(marker);
  writeFileSync(marker, `${"0".repeat(64)}\n`);
  try {
    const drifted = await update(sourceUpdateBody());
    expect(drifted.status).toBe(409);
    expect(await errorCode(drifted)).toBe("runtime_engine_update_required");
  } finally {
    writeFileSync(marker, markerBytes);
  }
  const transaction = path.join(installRoot, "install-transaction.json");
  writeFileSync(transaction, "{}\n");
  try {
    const incomplete = await update(sourceUpdateBody());
    expect(incomplete.status).toBe(503);
    expect(await errorCode(incomplete)).toBe("runtime_engine_update_busy");
  } finally {
    rmSync(transaction, { force: true });
  }
});

test("reports that a divergent release needs a provider install", async () => {
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(409);
  const body = await response.json();
  expect(body.code).toBe("runtime_engine_update_required");
  expect(body.details).toEqual({ active_revision: "source-tree", layout: "release" });
});

test("rejects a request snapshot after the active pointer moves", () => {
  const installRoot = path.join(rt.root, ".stattic");
  const nextRelease = path.join(installRoot, "releases/next");
  cpSync(rt.engineRoot, nextRelease, { recursive: true });
  writeActiveReleasePointer(installRoot, "releases/next");
  try {
    const script = [
      `require ${JSON.stringify(path.resolve(import.meta.dirname, "../engine/admin/engine-update.php"))};`,
      `$GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $argv[1];`,
      `echo _stattic_engine_release_layout_active($argv[2]) ? 'active' : 'stale';`,
    ].join("\n");
    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", "-r", script, rt.engineRoot, rt.storageRoot],
      env: process.env,
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(result.stdout.toString()).toBe("stale");
  } finally {
    writeActiveReleasePointer(installRoot, "releases/test");
  }
});

test("invalidates exactly the rewritten-in-place serving aliases", async () => {
  // These aliases are the ONLY engine files a sync reinstalls under an
  // unchanged path, and the fleet runs opcache.validate_timestamps=Off, so an
  // alias FPM does not invalidate keeps executing its old compiled module. The
  // invalidate loop is a PHP guarantee; what the runtime owns, and what breaks
  // if the entrypoint set drifts, is the absolute path set derived here from the
  // same constant serve.php dispatches on. PHP 8.5's CLI SAPIs retain no SHM
  // opcache entries across a run, so the eviction itself has no local
  // observable; the wp.cloud sync suite covers it end to end.
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-alias-opcache-"));
  try {
    const script = [
      "<?php",
      `require ${JSON.stringify(path.resolve(import.meta.dirname, "../engine/admin/engine-update.php"))};`,
      `echo json_encode(_stattic_engine_update_alias_paths(${JSON.stringify(`${root}/.stattic/storage`)}));`,
    ].join("\n");
    const scriptPath = path.join(root, "probe-script.php");
    writeFileSync(scriptPath, script);
    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", scriptPath],
      env: process.env,
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    const paths: unknown = JSON.parse(result.stdout.toString());
    expect(paths).toHaveLength(8);
    expect(paths).toEqual(
      expect.arrayContaining([
        `${root}/custom-redirects.php`,
        `${root}/index.php`,
        `${root}/__spacefast/api.php`,
        `${root}/__spacefast/content-admin.php`,
        `${root}/__spacefast/content.php`,
        `${root}/__spacefast/health.php`,
        `${root}/__spacefast/upload.php`,
        `${root}/wp-content/mu-plugins/spacefast-content.php`,
      ]),
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
