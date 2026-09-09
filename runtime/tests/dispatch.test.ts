import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
// SSH management dispatcher (engine/admin/dispatch.php): one JSON request
// envelope on stdin, one {status, body} envelope on stdout, the SAME handlers
// and JWT verification as the HTTP management surface. This is the WP.Cloud
// provider-adapter transport for edge-protection 403/429 on the management
// path; the self-host operator contract stays pure HTTP and never needs it.
import path from "node:path";

import {
  deploy,
  dispatchCli,
  PHP_BINARY,
  get,
  managementToken,
  publicAccessConfig,
  runtimeHttpPath,
  signToken,
  startRuntime,
  type Runtime,
} from "./harness";

let rt: Runtime;

const DISPATCH_TEMPLATE_SOURCE = "export const API = '{{ vars.API }}';\n";
const DISPATCH_TEMPLATE_SERVED = "export const API = 'dispatch-served';\n";

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_dsp",
    versionId: "ver_dsp_1",
    files: {
      "index.html": "one",
      "config.js": DISPATCH_TEMPLATE_SOURCE,
      "sf.jsonc": '{ "templates": ["config.js"] }\n',
    },
    finalize: {
      variable_scopes: [{ kind: "space", values: { API: { value: "dispatch-served" } } }],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
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

// The dispatcher itself owns E_ALL + log_errors, so a broken handler surfaces
// on stderr without each caller remembering process flags.
function dispatchRaw(
  stdin: string,
  env: Record<string, string | undefined> = {
    PATH: process.env.PATH,
    HOME: process.env.HOME,
  },
): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  return dispatchCli(rt, stdin, { env });
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
  return (envelope.body as { code?: string }).code ?? "";
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

test("state reports unavailable instead of erasing state after a failed read", async () => {
  const currentPath = path.join(rt.storageRoot, "routes", "current.json");
  chmodSync(currentPath, 0o000);
  try {
    const unavailable = await dispatch({
      method: "GET",
      path: runtimeHttpPath("/__spacefast/api.php/state"),
      authorization: `Bearer ${managementToken("read_state")}`,
    });
    expect(unavailable.status).toBe(503);
    expect(errorCode(unavailable)).toBe("runtime_management_unavailable");
  } finally {
    chmodSync(currentPath, 0o644);
  }

  const recovered = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(recovered.status).toBe(200);
  expect(recovered.body.spaces).toEqual(
    expect.arrayContaining([expect.objectContaining({ space_id: "spc_dsp" })]),
  );
});

test("dispatch runs through the fake Atomic top-level without runtime config env", async () => {
  // No SPACEFAST_* in the environment: the config the handler runs on has to
  // come from the installed top-level bootstrap the fake Atomic prepends, not
  // from the shell. Reading back the deployed space proves it resolved the real
  // storage root rather than merely booting.
  const state = await dispatchWithoutRuntimeEnv({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(state.status).toBe(200);
  expect(state.body.ok).toBe(true);
  const spaces = state.body.spaces as Array<{ space_id: string }>;
  expect(spaces.map((space) => space.space_id)).toContain("spc_dsp");
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
      config: publicAccessConfig(),
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
  const replay = await dispatchRaw(
    JSON.stringify({
      method: "GET",
      path: runtimeHttpPath("/__spacefast/api.php/state"),
      authorization: `Bearer ${replayedToken}`,
    }),
  );
  expect(JSON.parse(replay.stdout)).toMatchObject({
    status: 403,
    body: { code: "runtime_jti_replayed" },
  });
  expect(replay.stderr).not.toContain("replay_guard_unavailable ");
});

test("replay-guard storage failure answers 503 retryable, never a false 403 replay", async () => {
  // A real directory obstruction must retain its native cause without logging
  // token identity or claiming that the disk is full.
  const diagnostics = (stderr: string) => {
    const prefix = "spacefast runtime replay_guard_unavailable ";
    return stderr
      .split("\n")
      .filter((line) => line.includes(prefix))
      .map((line) => JSON.parse(line.slice(line.indexOf(prefix) + prefix.length)));
  };
  const jtiDir = path.join(rt.storageRoot, "runtime", "jti");
  rmSync(jtiDir, { recursive: true, force: true });
  writeFileSync(jtiDir, "not a directory");
  try {
    const blocked = await dispatchRaw(
      JSON.stringify({
        method: "GET",
        path: runtimeHttpPath("/__spacefast/api.php/state"),
        authorization: `Bearer ${managementToken("read_state")}`,
      }),
    );
    expect(JSON.parse(blocked.stdout)).toMatchObject({
      status: 503,
      body: {
        code: "runtime_replay_guard_unavailable",
        detail: "Runtime token replay guard storage is unavailable.",
      },
    });
    expect(diagnostics(blocked.stderr)).toEqual([
      {
        phase: "directory",
        operation: "mkdir",
        reason: "File exists",
      },
    ]);
  } finally {
    rmSync(jtiDir, { force: true });
  }
  // A real per-process file-size limit fails fwrite after exclusive create.
  // The later stat check must not replace that original cause in the diagnostic.
  // Sync capture uses regular files on Linux, which the same limit would block.
  const limited = Bun.spawn(
    [
      PHP_BINARY,
      "-r",
      `
    require $argv[1] . '/shared/context.php';
    require $argv[1] . '/shared/jwt.php';
    mkdir($argv[2] . '/runtime/jti', 0775, true);
    $limits = posix_getrlimit();
    $soft = $limits['soft filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft filesize'];
    $hard = $limits['hard filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard filesize'];
    pcntl_signal(SIGXFSZ, SIG_IGN);
    posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, $hard);
    try {
        $verdict = _stattic_jwt_consume_jti($argv[2], 'management', 'local-size-limit', time() + 60, time());
    } finally {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
    }
    echo $verdict;
  `,
      rt.engineRoot,
      rt.storageRoot,
    ],
    { stdout: "pipe", stderr: "pipe" },
  );
  const [exitCode, stdout, stderr] = await Promise.all([
    limited.exited,
    new Response(limited.stdout).text(),
    new Response(limited.stderr).text(),
  ]);
  expect({
    exitCode,
    failure: exitCode === 0 ? null : { stdout, stderr },
  }).toEqual({ exitCode: 0, failure: null });
  expect(stdout).toBe("unavailable");
  expect(diagnostics(stderr)).toEqual([
    {
      phase: "claim",
      operation: "fwrite",
      reason: "File too large",
    },
  ]);
  // Writable again: the same action with a fresh token recovers.
  const recovered = await dispatch({
    method: "GET",
    path: runtimeHttpPath("/__spacefast/api.php/state"),
    authorization: `Bearer ${managementToken("read_state")}`,
  });
  expect(recovered.status).toBe(200);
});

test("bounded version bytes use a dispatch envelope while other binary endpoints stay rejected", async () => {
  const versionBytes = await dispatch({
    method: "GET",
    path: runtimeHttpPath(
      "/__spacefast/api.php/spaces/spc_dsp/versions/ver_dsp_1/source?path=config.js&view=served&max_bytes=128",
    ),
    authorization: `Bearer ${managementToken("read_version_source", {
      space_id: "spc_dsp",
      version_id: "ver_dsp_1",
    })}`,
  });
  expect(versionBytes.status).toBe(200);
  expect(Buffer.from(String(versionBytes.body.body_base64), "base64").toString("utf8")).toBe(
    DISPATCH_TEMPLATE_SERVED,
  );
  expect(versionBytes.body.size).toBe(Buffer.byteLength(DISPATCH_TEMPLATE_SERVED));

  const sourceBytes = await dispatch({
    method: "GET",
    path: runtimeHttpPath(
      "/__spacefast/api.php/spaces/spc_dsp/versions/ver_dsp_1/source?path=config.js&view=source&max_bytes=128",
    ),
    authorization: `Bearer ${managementToken("read_version_source", {
      space_id: "spc_dsp",
      version_id: "ver_dsp_1",
    })}`,
  });
  expect(sourceBytes.status).toBe(200);
  expect(Buffer.from(String(sourceBytes.body.body_base64), "base64").toString("utf8")).toBe(
    DISPATCH_TEMPLATE_SOURCE,
  );

  // Other binary rows still reject before their handlers can stream raw bytes
  // into the JSON-only dispatch transport.
  const binaryRoute = await dispatch({
    method: "GET",
    path: runtimeHttpPath(
      "/__spacefast/api.php/spaces/spc_dispatch/build-sources/bld_dispatch/body",
    ),
    authorization: `Bearer ${managementToken("build_source_read", {
      space_id: "spc_dispatch",
      build_id: "bld_dispatch",
    })}`,
  });
  expect(binaryRoute.status).toBe(400);
  expect(errorCode(binaryRoute)).toBe("runtime_dispatch_unsupported_path");

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
