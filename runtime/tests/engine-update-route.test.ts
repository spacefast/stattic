// POST /engine/update runs the resident installer and relays its receipt.
// installer-update.test.ts holds the installer's own behavior; this file owns
// the route seam: validation, the current fast-path, the exec contract
// (argv URL + env checksums), and how installer verdicts map onto HTTP.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  api,
  dispatchCli,
  errorCode,
  managementToken,
  RUNTIME_HTTP_API_BASE,
  runtimeHttpPath,
  startRuntime,
  type Runtime,
} from "./harness";

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

function plantResidentInstaller(script: string, runtime = rt): void {
  const dir = path.join(runtime.root, "__spacefast");
  mkdirSync(dir, { recursive: true });
  const file = path.join(dir, "engine-update.php");
  writeFileSync(file, script);
  chmodSync(file, 0o644);
}

function removeResidentInstaller(): void {
  rmSync(path.join(rt.root, "__spacefast/engine-update.php"), { force: true });
}

test("rejects a plain-http zip_url", async () => {
  const response = await update({ ...UPDATE_BODY, zip_url: "http://github.example/engine.zip" });
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("runtime_engine_update_invalid");
});

test("answers current for the running revision without spawning anything", async () => {
  // The tree copy the harness serves carries revision 'source-tree'; no
  // resident installer exists, so reaching the exec path would 503.
  removeResidentInstaller();
  const response = await update({ ...UPDATE_BODY, revision: "source-tree" });
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({
    status: "current",
    engine_revision: "source-tree",
    layout: "release",
  });
});

test("runs the resident installer for the same revision when the lane did not pin a release", async () => {
  // The SSH dispatch lane loads the engine directly, so no active-release root
  // is pinned for the process. A revision match alone must not answer
  // "current" there. Only a pinned release layout proves the running bytes
  // are the published ones.
  const lane = await startRuntime();
  try {
    plantResidentInstaller(
      "<?php echo json_encode(['status' => 'installed', 'engine_revision' => getenv('SPACEFAST_RUNTIME_ENGINE_REVISION'), 'layout' => 'release']);",
      lane,
    );

    const result = await dispatchCli(
      lane,
      JSON.stringify({
        method: "POST",
        path: runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/engine/update`),
        authorization: `Bearer ${managementToken("update_engine")}`,
        body: JSON.stringify({ ...UPDATE_BODY, revision: "source-tree" }),
      }),
      { env: { PATH: process.env.PATH, HOME: process.env.HOME } },
    );

    expect(result.exitCode, result.stderr).toBe(0);
    const envelope: { status?: unknown; body?: unknown } = JSON.parse(result.stdout);
    expect(envelope.status).toBe(200);
    expect(envelope.body).toEqual({
      status: "installed",
      engine_revision: "source-tree",
      layout: "release",
    });
  } finally {
    lane.stop();
  }
});

test("503s when the resident installer is absent", async () => {
  removeResidentInstaller();
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(503);
  expect(await errorCode(response)).toBe("runtime_engine_installer_missing");
});

test("execs the resident installer with the argv URL and env checksums and relays its receipt", async () => {
  plantResidentInstaller(
    [
      "<?php",
      "echo json_encode([",
      "  'status' => 'installed',",
      "  'engine_revision' => getenv('SPACEFAST_RUNTIME_ENGINE_REVISION'),",
      "  'layout' => 'release',",
      "  'seen_zip_url' => $argv[1],",
      "  'seen_md5' => getenv('SPACEFAST_RUNTIME_ENGINE_MD5'),",
      "  'seen_native_sha256' => getenv('SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256'),",
      "]);",
    ].join("\n"),
  );
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({
    status: "installed",
    engine_revision: UPDATE_BODY.revision,
    layout: "release",
    seen_zip_url: UPDATE_BODY.zip_url,
    seen_md5: UPDATE_BODY.md5,
    seen_native_sha256: UPDATE_BODY.native_sha256,
  });
});

test("maps a busy installer to 409", async () => {
  plantResidentInstaller("<?php echo json_encode(['status' => 'busy']);");
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(409);
  expect(await errorCode(response)).toBe("runtime_engine_update_busy");
});

test("surfaces the installer's stderr code on a failed sync", async () => {
  plantResidentInstaller('<?php fwrite(STDERR, "runtime_engine_md5_mismatch\\n"); exit(1);');
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(424);
  const body = (await response.json()) as {
    code?: string;
    details?: { error?: string; exit_code?: number };
  };
  expect(body.code).toBe("runtime_engine_update_failed");
  expect(body.details).toMatchObject({ error: "runtime_engine_md5_mismatch", exit_code: 1 });
});

test("invalidates exactly the rewritten-in-place aliases: the loader copies and the resident installer", async () => {
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
    expect(paths).toHaveLength(9);
    expect(paths).toEqual(
      expect.arrayContaining([
        `${root}/custom-redirects.php`,
        `${root}/index.php`,
        `${root}/__spacefast/engine-update.php`,
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
