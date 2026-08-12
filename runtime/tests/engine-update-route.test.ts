// POST /engine/update — the management route that runs the resident installer
// and relays its receipt. The real installer's behavior is held by
// installer-update.test.ts; this file owns the route seam: validation, the
// converged fast-path, the exec contract (argv URL + env checksums), and how
// installer verdicts map onto HTTP.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  errorCode,
  RUNTIME_HTTP_API_BASE,
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

function update(body: unknown): Promise<Response> {
  return api(rt, "POST", `${RUNTIME_HTTP_API_BASE}/engine/update`, "update_engine", {}, body);
}

function plantResidentInstaller(script: string): void {
  const dir = path.join(rt.root, "__spacefast");
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

test("answers converged for the running revision without spawning anything", async () => {
  // The tree copy the harness serves carries revision 'source-tree'; no
  // resident installer exists, so reaching the exec path would 503.
  removeResidentInstaller();
  const response = await update({ ...UPDATE_BODY, revision: "source-tree" });
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({
    status: "converged",
    engine_revision: "source-tree",
  });
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

test("surfaces the installer's stderr code on a failed converge", async () => {
  plantResidentInstaller(
    "<?php fwrite(STDERR, \"runtime_engine_md5_mismatch\\n\"); exit(1);",
  );
  const response = await update(UPDATE_BODY);
  expect(response.status).toBe(424);
  const body = (await response.json()) as {
    code?: string;
    details?: { error?: string; exit_code?: number };
  };
  expect(body.code).toBe("runtime_engine_update_failed");
  expect(body.details).toMatchObject({ error: "runtime_engine_md5_mismatch", exit_code: 1 });
});
