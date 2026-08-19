import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { deploy, get, publicAccessConfig, startRuntime, type Runtime } from "./harness.ts";

const HOST = "zero-atomic-config.test";
const DATABASE_URL = "mysql://zero%3Auser:p%40ss%2Fword%3F%23@127.0.0.1/zero%20database";
const CALLBACK_TOKEN = "atomic-callback-token";
const REALTIME_TOKEN = "atomic-realtime-token";
const REAL_RUNTIME_PATH = path.resolve(
  process.env.SPACEFAST_RUNTIME_BIN ??
    path.join(import.meta.dir, "../../target/debug/stattic-runtime"),
);

type ReceivedCallback = {
  authorization: string;
  body: unknown;
};

type ReceivedReplay = {
  authorization: string;
  realtimeToken: string;
};

let rt: Runtime;
let runtimeRoot: string;
let receiver: ReturnType<typeof Bun.serve>;
let resolveCallback: (callback: ReceivedCallback) => void;
let callbackReceived: Promise<ReceivedCallback>;
const callbacks: ReceivedCallback[] = [];
const replays: ReceivedReplay[] = [];

function receiveCallbackWithin(timeoutMs: number): Promise<ReceivedCallback> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(
      () => reject(new Error("Zero callback was not received")),
      timeoutMs,
    );
    callbackReceived.then((callback) => {
      clearTimeout(timeout);
      resolve(callback);
    }, reject);
  });
}

beforeAll(async () => {
  callbackReceived = new Promise((resolve) => {
    resolveCallback = resolve;
  });
  receiver = Bun.serve({
    port: 0,
    fetch: async (request) => {
      const requestUrl = new URL(request.url);
      if (requestUrl.pathname === "/events") {
        const callback = {
          authorization: request.headers.get("authorization") ?? "",
          body: await request.json().catch(() => null),
        };
        callbacks.push(callback);
        resolveCallback(callback);
        return new Response(null, { status: 204 });
      }
      if (requestUrl.pathname === "/replay") {
        replays.push({
          authorization: request.headers.get("authorization") ?? "",
          realtimeToken: request.headers.get("x-spacefast-runtime-realtime-token") ?? "",
        });
        return Response.json({ events: [{ id: "evt_atomic_config" }] });
      }
      return new Response("not found", { status: 404 });
    },
  });

  runtimeRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-runtime-atomic-config-"));
  const runtimePath = path.join(runtimeRoot, "fake-runtime.php");
  const phpPath = Bun.which("php") ?? "/usr/bin/php";
  // One binary serves the finalize and Zero lanes. Only `invoke` is faked here
  // (so the runner's env and its emitted events are observable); every other
  // subcommand — `finalize` above all — delegates to the real binary.
  writeFileSync(
    runtimePath,
    `#!${phpPath}
<?php
$real = ${JSON.stringify(REAL_RUNTIME_PATH)};
if (($argv[1] ?? '') !== 'invoke') {
    $process = proc_open(
        array_merge([$real], array_slice($argv, 1)),
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 1);
}
$envelope = json_decode(stream_get_contents(STDIN), true);
$body = json_encode([
    'ok' => true,
    'endpointId' => $envelope['endpointId'] ?? null,
    'databaseUrl' => getenv('SPACEFAST_ZERO_DATABASE_URL') ?: null,
], JSON_UNESCAPED_SLASHES);
echo json_encode([
    'status' => 201,
    'headers' => [
        'content-type' => 'application/json; charset=utf-8',
        'x-zero-runner' => 'atomic-config',
    ],
    'body' => $body,
    'events' => [[
        'event' => 'zero.realtime',
        'payload' => [
            'eventId' => 'evt_atomic_config',
            'changedTables' => ['comments'],
            'changedQueries' => ['commentsByPage'],
        ],
    ]],
], JSON_UNESCAPED_SLASHES);
`,
  );
  chmodSync(runtimePath, 0o755);

  const receiverUrl = `http://127.0.0.1:${receiver.port}`;
  rt = await startRuntime({
    atomicData: {
      SPACEFAST_RUNTIME_BIN: runtimePath,
      // Fleet secret for the replay lane: used only when the version carries no
      // eventCallback token of its own.
      SPACEFAST_ZERO_REALTIME_TOKEN: REALTIME_TOKEN,
      SPACEFAST_ZERO_CALLBACK_ALLOWED_HOSTS: "127.0.0.1",
    },
    // Provider-owned DB_* values are ordinary process configuration, not
    // duplicated Spacefast persistent data. An omitted DB_HOST exercises the
    // standard local-MySQL default observed on the public runtime lane.
    env: {
      DB_NAME: "zero database",
      DB_USER: "zero:user",
      DB_PASSWORD: "p@ss/word?#",
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_atomic_config",
    versionId: "ver_zero_atomic_config_1",
    metadata: { mode: "website", title: "Zero Atomic Config" },
    files: { "index.html": "<h1>Zero Atomic Config</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/atomic-config",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/atomic-config",
          capabilities: { db: true, realtime: true },
        },
      ],
    },
    zero: {
      realtime: {
        replayUrl: `${receiverUrl}/replay`,
        eventCallback: {
          url: `${receiverUrl}/events`,
          token: CALLBACK_TOKEN,
        },
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Atomic Config" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => {
  rt?.stop();
  receiver?.stop(true);
  if (runtimeRoot) {
    rmSync(runtimeRoot, { recursive: true, force: true });
  }
});

// Zero realtime is the one live push lane the runtime keeps (contracts §10 —
// everything else drains from the journal). This pins where its credentials and
// destinations come from: the per-version `zero.realtime` config, never a
// global, and never at all when the version declares none.
test("Zero realtime uses per-version config and stays silent without it", async () => {
  const endpoint = await get(rt, HOST, "/api/atomic-config");
  expect(endpoint.status).toBe(201);
  expect(endpoint.headers.get("x-zero-runner")).toBe("atomic-config");
  // The provider DB_* tuple is assembled into a percent-encoded URL under the
  // reserved name; the tenant runner never sees DB_USER/DB_PASSWORD.
  expect(await endpoint.json()).toEqual({
    ok: true,
    endpointId: "GET /api/atomic-config",
    databaseUrl: DATABASE_URL,
  });

  const callback = await receiveCallbackWithin(2_000);
  expect(callback).toEqual({
    authorization: `Bearer ${CALLBACK_TOKEN}`,
    body: {
      event: {
        event: "zero.realtime",
        payload: {
          eventId: "evt_atomic_config",
          changedTables: ["comments"],
          changedQueries: ["commentsByPage"],
        },
        space_id: "spc_zero_atomic_config",
        version_id: "ver_zero_atomic_config_1",
        created_at: expect.any(String),
      },
    },
  });

  const replay = await get(rt, HOST, "/__spacefast/zero/realtime/events");
  expect(replay.status).toBe(200);
  expect(await replay.json()).toEqual({ events: [{ id: "evt_atomic_config" }] });
  // The version's own callback token authenticates the replay; the fleet secret
  // is not also leaked upstream.
  expect(replays).toEqual([{ authorization: `Bearer ${CALLBACK_TOKEN}`, realtimeToken: "" }]);

  await deploy(rt, {
    spaceId: "spc_zero_atomic_config",
    versionId: "ver_zero_atomic_config_2",
    metadata: { mode: "website", title: "Zero Atomic Config Without Callback" },
    files: { "index.html": "<h1>Zero Atomic Config Without Callback</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/atomic-config",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/atomic-config",
          capabilities: { db: true, realtime: true },
        },
      ],
    },
    zero: {
      realtime: { replayUrl: `http://127.0.0.1:${receiver.port}/replay` },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({
        mode: "website",
        site_title: "Zero Atomic Config Without Callback",
      }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const noCallbackEndpoint = await get(rt, HOST, "/api/atomic-config");
  expect(noCallbackEndpoint.status).toBe(201);
  expect(await noCallbackEndpoint.json()).toEqual({
    ok: true,
    endpointId: "GET /api/atomic-config",
    databaseUrl: DATABASE_URL,
  });
  // The runner emitted an event again, but this version declares no callback
  // destination: nothing is delivered anywhere.
  expect(callbacks).toHaveLength(1);

  // Replay still works without a per-version token — it falls back to the fleet
  // secret from process config, presented under its own header.
  const fallbackReplay = await get(rt, HOST, "/__spacefast/zero/realtime/events");
  expect(fallbackReplay.status).toBe(200);
  expect(replays[1]).toEqual({ authorization: "", realtimeToken: REALTIME_TOKEN });
});
