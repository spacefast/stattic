import { afterAll, beforeAll, expect, test } from "bun:test";
import { rmSync, writeFileSync } from "node:fs";
// SSH management dispatcher (engine/admin/dispatch.php): one JSON request
// envelope on stdin, one {status, body} envelope on stdout, the SAME handlers
// and JWT verification as the HTTP management surface. This is the WP.Cloud
// provider-adapter transport for edge-protection 403/429 on the management
// path; the self-host operator contract stays pure HTTP and never needs it.
import path from "node:path";

import {
  createDeclaredSession,
  RUNTIME_UPLOAD_API_BASE,
  deploy,
  dispatchCli,
  get,
  managementToken,
  runtimeHttpPath,
  runtimeUploadHttpPath,
  signToken,
  startRuntime,
  type Runtime,
} from "./harness";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_dsp",
    versionId: "ver_dsp_1",
    files: { "index.html": "one" },
    activate: {
      route_name: "production",
      config: {},
      production_hostnames: ["dispatch.test"],
      version_hostnames: [],
    },
  });
  await deploy(rt, {
    spaceId: "spc_dsp",
    versionId: "ver_dsp_2",
    files: { "index.html": "two" },
  });
}, 30000);

afterAll(() => {
  rt?.stop();
});

type DispatchEnvelope = { status: number; body: Record<string, unknown> };

// Surfaces PHP notices/warnings (log_errors + E_ALL) so a broken handler shows
// up as stderr in the failure message rather than a silent bad envelope.
function dispatchRaw(
  stdin: string,
  env: Record<string, string | undefined> = {
    PATH: process.env.PATH,
    HOME: process.env.HOME,
  },
): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  return dispatchCli(rt, stdin, {
    env,
    phpFlags: ["-d log_errors=1", "-d error_reporting=E_ALL"],
  });
}

async function dispatch(request: Record<string, unknown>): Promise<DispatchEnvelope> {
  const result = await dispatchRaw(JSON.stringify(request));
  expect(
    result.exitCode,
    `dispatch exited in ${rt.root} with stdout:\n${result.stdout}\nstderr:\n${result.stderr}`,
  ).toBe(0);
  return JSON.parse(result.stdout) as DispatchEnvelope;
}

async function dispatchWithoutRuntimeEnv(
  request: Record<string, unknown>,
): Promise<DispatchEnvelope> {
  const result = await dispatchRaw(JSON.stringify(request), {
    PATH: process.env.PATH,
  });
  expect(
    result.exitCode,
    `dispatch exited in ${rt.root} with stdout:\n${result.stdout}\nstderr:\n${result.stderr}`,
  ).toBe(0);
  return JSON.parse(result.stdout) as DispatchEnvelope;
}

function errorCode(envelope: DispatchEnvelope): string {
  return (envelope.body.error as { code?: string } | undefined)?.code ?? "";
}

test("state over dispatch runs the same handler as HTTP", async () => {
  const envelope = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(envelope.status).toBe(200);
  expect(envelope.body.ok).toBe(true);
  expect(envelope.body.runtime).toBe("stattic-php");
  // Generation-state summary rides the same response shape as HTTP.
  expect(Array.isArray(envelope.body.spaces)).toBe(true);
});

test("dispatch runs through the fake Atomic top-level without runtime config env", async () => {
  const state = await dispatchWithoutRuntimeEnv({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(state.status).toBe(200);
  expect(state.body.ok).toBe(true);

  const content = "uploaded through fake Atomic\n";
  const { uploadId, token } = await createDeclaredSession(
    rt,
    "spc_dsp",
    "ver_dsp_installed_config",
    { "config.html": content },
  );
  const upload = await dispatchWithoutRuntimeEnv({
    method: "PUT",
    path: runtimeUploadHttpPath(`${RUNTIME_UPLOAD_API_BASE}/${uploadId}/files/config.html`),
    authorization: `Bearer ${token}`,
    body: Buffer.from(content).toString("base64"),
    bodyEncoding: "base64",
  });
  expect(upload.status).toBe(200);
});

test("a route PUT over dispatch mutates serving exactly like HTTP", async () => {
  const envelope = await dispatch({
    method: "PUT",
    path: runtimeHttpPath("/__spacefast/api.php/spaces/spc_dsp/routes/production"),
    authorization: `Bearer ${managementToken("update_route", {
      space_id: "spc_dsp",
      route_name: "production",
    })}`,
    body: JSON.stringify({
      version_id: "ver_dsp_2",
      production_hostnames: ["dispatch.test"],
      version_hostnames: [],
    }),
  });
  expect(envelope.status).toBe(200);
  expect(envelope.body.version_id).toBe("ver_dsp_2");

  const served = await get(rt, "dispatch.test", "/");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("two");
});

test("management JWTs are verified identically: bad signature, wrong action, replayed jti", async () => {
  const rogue = signToken(
    {
      aud: "stattic-runtime-management",
      runtime_instance_id: "rti_test",
      operation_id: "op_read_state",
      action: "read_state",
      jti: "jti_rogue",
    },
    { rogueKey: true },
  );
  const badSignature = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${rogue}`,
  });
  expect(badSignature.status).toBe(401);
  expect(errorCode(badSignature)).toBe("runtime_token_bad_signature");

  const wrongAction = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("update_route")}`,
  });
  expect(wrongAction.status).toBe(403);
  expect(errorCode(wrongAction)).toBe("runtime_action_forbidden");

  const replayedToken = managementToken("read_state");
  const first = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${replayedToken}`,
  });
  expect(first.status).toBe(200);
  const replay = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${replayedToken}`,
  });
  expect(replay.status).toBe(403);
  expect(errorCode(replay)).toBe("runtime_jti_replayed");
});

test("replay-guard storage failure answers 503 retryable, never a false 403 replay", async () => {
  // A replay verdict requires a marker on disk. When the marker write fails
  // (disk quota, read-only mount) a fresh token must get a retryable 503 —
  // a 403 here once masked a disk-full outage as an auth failure and blocked
  // the rescue operations that would have freed the disk.
  // Simulate the storage failure by occupying the jti directory path with a
  // regular file: every marker write then fails exactly as it does on a full
  // or read-only disk. (chmod can't simulate this — the engine re-chmods the
  // directory writable on every request.)
  const jtiDir = path.join(rt.storageRoot, "runtime", "jti");
  rmSync(jtiDir, { recursive: true, force: true });
  writeFileSync(jtiDir, "not a directory");
  try {
    const blocked = await dispatch({
      method: "GET",
      path: runtimeHttpPath("/__spacefast/api.php/state"),
      authorization: `Bearer ${managementToken("read_state")}`,
    });
    expect(blocked.status).toBe(503);
    expect(errorCode(blocked)).toBe("runtime_replay_guard_unavailable");
  } finally {
    rmSync(jtiDir, { force: true });
  }
  // Writable again: the same action with a fresh token recovers.
  const recovered = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(recovered.status).toBe(200);
});

test("binary archive endpoints and malformed envelopes are rejected", async () => {
  const archive = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/exports/sex_x/archive"),
    authorization: `Bearer ${managementToken("download_space_export", { export_id: "sex_x" })}`,
  });
  expect(archive.status).toBe(400);
  expect(errorCode(archive)).toBe("runtime_dispatch_unsupported_path");

  await Promise.all(
    [
      "not json",
      JSON.stringify({
        method: "DELETE",
        path: runtimeHttpPath("/__spacefast/api.php/state"),
        authorization: "Bearer x",
      }),
      JSON.stringify({ method: "GET", path: "/elsewhere", authorization: "Bearer x" }),
      JSON.stringify({ method: "GET", path: runtimeHttpPath("/__spacefast/api.php/state") }),
    ].map(async (invalid) => {
      const result = await dispatchRaw(invalid);
      expect(result.exitCode).toBe(0);
      const envelope = JSON.parse(result.stdout) as DispatchEnvelope;
      expect(envelope.status).toBe(400);
      expect(errorCode(envelope)).toBe("runtime_dispatch_invalid_request");
    }),
  );

  const unknownRoute = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/nope"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(unknownRoute.status).toBe(404);
  expect(errorCode(unknownRoute)).toBe("runtime_route_not_found");
});
