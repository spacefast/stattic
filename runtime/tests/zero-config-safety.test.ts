import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { deploy, get, startRuntime, type Runtime } from "./harness.ts";

const HOST = "zero-atomic-config.test";
const DATABASE_URL = "mysql://zero-atomic-config.test/db";
const CALLBACK_TOKEN = "atomic-callback-token";
const REALTIME_TOKEN = "atomic-realtime-token";

type ReceivedCallback = {
  authorization: string;
  body: unknown;
};

let rt: Runtime;
let runnerRoot: string;
let receiver: ReturnType<typeof Bun.serve>;
let resolveCallback: (callback: ReceivedCallback) => void;
let callbackReceived: Promise<ReceivedCallback>;
const callbacks: ReceivedCallback[] = [];
const legacyCallbacks: ReceivedCallback[] = [];
const replayTokens: string[] = [];

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
      if (requestUrl.pathname === "/legacy-events") {
        legacyCallbacks.push({
          authorization: request.headers.get("authorization") ?? "",
          body: await request.json().catch(() => null),
        });
        return new Response(null, { status: 204 });
      }
      if (requestUrl.pathname === "/replay") {
        replayTokens.push(request.headers.get("x-spacefast-zero-realtime-token") ?? "");
        return Response.json({ events: [{ id: "evt_atomic_config" }] });
      }
      return new Response("not found", { status: 404 });
    },
  });

  runnerRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-zero-atomic-config-"));
  const runnerPath = path.join(runnerRoot, "fake-zero-runner.php");
  const phpPath = Bun.which("php") ?? "/usr/bin/php";
  writeFileSync(
    runnerPath,
    `#!${phpPath}
<?php
if (($argv[1] ?? '') === 'compile') {
    $source = $argv[2] ?? '';
    $bytecode = $argv[3] ?? '';
    $generated = $argv[5] ?? $source;
    if ($source === '' || $bytecode === '' || !is_file($source)) {
        exit(2);
    }
    file_put_contents($generated, file_get_contents($source));
    file_put_contents($bytecode, 'fake-bytecode');
    exit(0);
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
        'event' => 'zero.log',
        'level' => 'info',
        'message' => 'Atomic config loaded',
    ]],
], JSON_UNESCAPED_SLASHES);
`,
  );
  chmodSync(runnerPath, 0o755);

  const receiverUrl = `http://127.0.0.1:${receiver.port}`;
  rt = await startRuntime({
    atomicData: {
      SPACEFAST_ZERO_RUNNER: runnerPath,
      SPACEFAST_ZERO_DATABASE_URL: DATABASE_URL,
      SPACEFAST_ZERO_REALTIME_TOKEN: REALTIME_TOKEN,
      SPACEFAST_ZERO_CALLBACK_ALLOWED_HOSTS: "127.0.0.1",
      // Poison values for the retired global callback vars: per-version
      // config is the only legitimate source, so any delivery to
      // /legacy-events means the sender regressed to the old env fallback.
      SPACEFAST_ZERO_EVENT_CALLBACK_URL: `${receiverUrl}/legacy-events`,
      SPACEFAST_ZERO_EVENT_CALLBACK_TOKEN: "legacy-poison-token",
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_atomic_config",
    versionId: "ver_zero_atomic_config_1",
    zeroMode: "active",
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
      config: { mode: "website", site_title: "Zero Atomic Config" },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => {
  rt?.stop();
  receiver?.stop(true);
  if (runnerRoot) {
    rmSync(runnerRoot, { recursive: true, force: true });
  }
});

test("Zero callbacks use per-version config, wrap events, and stay disabled without config", async () => {
  const endpoint = await get(rt, HOST, "/api/atomic-config");
  expect(endpoint.status).toBe(201);
  expect(endpoint.headers.get("x-zero-runner")).toBe("atomic-config");
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
        event: "zero.log",
        level: "info",
        message: "Atomic config loaded",
        space_id: "spc_zero_atomic_config",
        version_id: "ver_zero_atomic_config_1",
        created_at: expect.any(String),
      },
    },
  });

  const replay = await get(rt, HOST, "/__spacefast/zero/realtime/events");
  expect(replay.status).toBe(200);
  expect(await replay.json()).toEqual({ events: [{ id: "evt_atomic_config" }] });
  expect(replayTokens).toEqual([REALTIME_TOKEN]);

  await deploy(rt, {
    spaceId: "spc_zero_atomic_config",
    versionId: "ver_zero_atomic_config_2",
    zeroMode: "active",
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
      config: { mode: "website", site_title: "Zero Atomic Config Without Callback" },
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
  expect(callbacks).toHaveLength(1);
  // Neither version delivered to the poisoned legacy env-var target: without
  // per-version eventCallback config the sender stays silent instead of
  // falling back to the retired globals.
  expect(legacyCallbacks).toHaveLength(0);
});
