import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { chmodSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  deploy,
  get,
  postAccessCallback,
  putRoute,
  signEd25519Jwt,
  type Runtime,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

let rt: Runtime;
let runtimePath: string;
let runtimeRoot: string;

const SPACE_X = "spc_adm_x";
const SPACE_Y = "spc_adm_y";
const SPACE_Z = "spc_adm_z";
const VERSION_X = "ver_adm_x_1";
const VERSION_Y = "ver_adm_y_1";
const VERSION_Z = "ver_adm_z_1";
const HOST_X = "admission-x.test";
const HOST_Y = "admission-y.test";
const HOST_Z = "admission-z.test";
const LIMIT = 2;
// Contracts §4 (D84): the per-Space exchange credential is what every `sfv2_`
// session key is derived from (access-rules.php _stattic_access_session_hmac_key
// reads it off `authorization.accessPage.exchange.credential`). A projection
// without one can mint no session at all, so every fixture that opens a cookie
// has to carry it — admission is measured on protected, session-bearing lanes.
const EXCHANGE_CREDENTIAL = "runtime-admission-exchange-credential-0123456789";
const PHP_BINARY = process.env.PHP_BINARY ?? "php";
const GENERATION_FIXTURE = path.join(import.meta.dir, "fixtures/admission-counter-generation.php");
const REAL_RUNTIME_PATH = path.resolve(
  process.env.SPACEFAST_RUNTIME_BIN ??
    path.join(import.meta.dir, "../../target/debug/stattic-runtime"),
);

const visitorKeyPair = generateKeyPairSync("ed25519");
const visitorJwk = visitorKeyPair.publicKey.export({ format: "jwk" });
const visitor = visitorIssuer(visitorKeyPair.publicKey);
const visitorCookies = new Map<string, string>();

interface AdmissionCounterFile {
  count?: number;
  started_at?: number;
  updated_at?: number;
}

function accessProjection(input?: {
  mode?: "private" | "public";
  memberRefs?: string[];
  overrides?: Array<{ scope: string; mode: "limited"; indexable: false }>;
  people?: Array<{
    personId: string;
    grants: Array<{ id: string; scope: string; role: "viewer" }>;
  }>;
}) {
  const protectedScopes = (input?.overrides ?? []).map(({ scope }) => `${scope}/**`);
  const grants = [
    ...((input?.mode ?? "private") === "public"
      ? [
          {
            id: "grt_admission_public",
            generation: 1,
            audience: { kind: "public" },
            resources: { include: ["/**"], exclude: protectedScopes },
            capabilities: ["page.view"],
            constraints: {},
            target: { kind: "live" },
            source: { kind: "managed", reference: "test:admission-public" },
          },
        ]
      : []),
    ...(input?.memberRefs ?? []).map((reference, index) => ({
      id: `grt_admission_member_${index}`,
      generation: 1,
      audience: {
        kind: "external",
        issuer: "spacefast-membership",
        subject: reference.replace(/^member:/, ""),
      },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "system", reference: `test:admission-member:${index}` },
    })),
    ...(input?.people ?? []).flatMap((person) =>
      person.grants.map((grant) => ({
        id: grant.id,
        generation: 1,
        audience: { kind: "person", personId: person.personId },
        resources: { include: [`${grant.scope}/**`], exclude: [] },
        capabilities: ["page.view"],
        constraints: {},
        target: { kind: "live" },
        source: { kind: "managed", reference: `test:admission-person:${grant.id}` },
      })),
    ),
  ];
  return {
    projection_generation: 1,
    visitor_issuer: "spacefast-api",
    visitor_jwks: {
      keys: [
        {
          kty: "OKP",
          crv: "Ed25519",
          kid: visitor.kid,
          alg: "EdDSA",
          use: "sig",
          x: visitorJwk.x ?? "",
        },
      ],
    },
    session_version: 0,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/admission",
      accessPage: {
        displayName: null,
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/admission/password",
          tokenUrl: "https://access.spacefast.test/acquire/admission/token",
          requestUrl: "https://access.spacefast.test/acquire/admission/request",
          credential: EXCHANGE_CREDENTIAL,
        },
      },
      spaceClaimed: true,
      grants,
    },
  };
}

function visitorToken(host: string, spaceId: string, authorities: string[]): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(visitorKeyPair.privateKey, visitor.kid, {
    sub: authorities[0],
    purpose: "handoff",
    grants: authorities,
    authorities,
    iss: "spacefast-api",
    aud: spaceId,
    host,
    sv: 0,
    generation: 1,
    spaceId,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    iat: now,
    nbf: now,
    exp: now + 300,
    jti: randomUUID(),
  });
}

async function openAuthorities(
  runtime: Runtime,
  host: string,
  spaceId: string,
  authorities: string[],
): Promise<string> {
  const callback = await postAccessCallback(
    runtime,
    host,
    visitorToken(host, spaceId, authorities),
  );
  expect(callback.status).toBe(303);
  return (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
}

async function setAdmissionPolicy(
  spaceId: string,
  versionId: string,
  config = accessProjection({
    mode: "public",
    memberRefs: ["member:mem_admission"],
    overrides: [{ scope: "/private", mode: "limited", indexable: false }],
  }),
) {
  await putRoute(rt, spaceId, "production", {
    version_id: versionId,
    config: {
      admission: { concurrency: LIMIT },
      ...config,
    },
  });
}

// Deterministic fill strategy. php -S CANNOT reliably run two near-simultaneous
// HTTP requests on two different workers (write-lock.test.ts's header comment
// documents the same scheduling flaw), so filling the limiter with N concurrent
// held requests is a coin flip on a loaded CI runner: the second holder can
// serialize behind the first and the counter never reaches capacity. Instead
// each test proves REAL acquisition with a single held request (one request
// always admits, no simultaneity required), then fills the counter file to
// capacity directly — the same direct-counter idiom the tiering in-flight cap
// test and the stale-fallback test below already use — before probing the shed
// behavior. The hold keeps the real slot occupied while the probes run: the
// engine caps the test hold at 5s (shared/admission.php), so use the full cap
// and keep waitForCounter's deadline BELOW it — if the counter is observed at
// all, real hold time provably remains for the seed+probe steps, and the
// trackHold() guard turns a holder that released early into a loud failure
// instead of a vacuously-green probe against a purely synthetic counter.
const FILL_HOLD_US = "5000000";

function trackHold(request: Promise<Response>) {
  const state = { settled: false };
  const settle = () => {
    state.settled = true;
  };
  request.then(settle, settle);
  return {
    response: request,
    assertStillHeld() {
      expect(state.settled).toBe(false);
    },
  };
}

function held(pathname: string) {
  return get(rt, HOST_X, pathname, {
    headers: {
      cookie: visitorCookies.get(HOST_X) ?? "",
      "x-spacefast-test-admission-hold-us": FILL_HOLD_US,
    },
  });
}

function readFileCounter(counterPath: string) {
  try {
    if (!existsSync(`${counterPath}.generation`)) return null;
    const pointer = JSON.parse(readFileSync(`${counterPath}.generation`, "utf8")) as {
      generation?: string;
    };
    if (typeof pointer.generation !== "string") return null;
    const generationPath = `${counterPath}.${pointer.generation}`;
    if (!existsSync(generationPath)) return null;
    const counter: AdmissionCounterFile = JSON.parse(readFileSync(generationPath, "utf8"));
    return counter;
  } catch (error) {
    // PHP updates both snapshots under flock by truncating and rewriting them.
    // This lock-free poller can observe that brief empty-file window; let its
    // bounded callers retry instead of turning an in-progress write into a
    // test failure. A file can likewise disappear between existsSync and read.
    if (
      error instanceof SyntaxError ||
      (error instanceof Error && "code" in error && error.code === "ENOENT")
    ) {
      return null;
    }
    throw error;
  }
}

function counterFile(runtime: Runtime, spaceId: string): string {
  return path.join(runtime.storageRoot, "runtime/admission", `${spaceId}.json`);
}

// Fills the remaining capacity on top of the real held slot(s) by writing the
// engine's active generation. The still-running holder's shutdown release
// decrements this seeded value, which is also what keeps the underflow
// assertion in the stale-window test honest.
function seedCounter(runtime: Runtime, spaceId: string, count: number) {
  const counterPath = counterFile(runtime, spaceId);
  const pointerPath = `${counterPath}.generation`;
  if (!existsSync(pointerPath)) {
    throw new Error(`admission counter ${counterPath} has no active generation pointer`);
  }
  const pointer = JSON.parse(readFileSync(pointerPath, "utf8")) as { generation?: string };
  if (typeof pointer.generation !== "string") {
    throw new Error(`admission counter ${counterPath} has no active generation`);
  }
  const generationPath = `${counterPath}.${pointer.generation}`;
  if (!existsSync(generationPath)) {
    throw new Error(`admission counter ${counterPath} has no active generation file`);
  }
  const current: AdmissionCounterFile = JSON.parse(readFileSync(generationPath, "utf8"));
  writeFileSync(
    generationPath,
    `${JSON.stringify({
      count,
      started_at: current.started_at ?? Math.floor(Date.now() / 1000),
      updated_at: Math.floor(Date.now() / 1000),
    })}\n`,
  );
}

async function waitForCounter(spaceId: string, expectedCount: number) {
  const counterPath = counterFile(rt, spaceId);
  // Below the 5s hold: observing the counter within this window guarantees
  // the holder is still occupying its slot when the caller seeds and probes.
  const deadline = Date.now() + 4000;
  for (;;) {
    if ((readFileCounter(counterPath)?.count ?? 0) >= expectedCount) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(`counter ${spaceId} did not reach ${expectedCount}`);
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
}

beforeAll(async () => {
  runtimeRoot = path.join(os.tmpdir(), `stattic-admission-runtime-${Date.now()}`);
  runtimePath = path.join(runtimeRoot, "runtime.php");
  mkdirSync(runtimeRoot, { recursive: true });
  await Bun.write(
    runtimePath,
    `#!${Bun.which("php") ?? "/usr/bin/php"}
<?php
// One binary serves the finalize and Zero lanes, so delegate non-Zero commands.
$real = ${JSON.stringify(REAL_RUNTIME_PATH)};
if (!in_array($argv[1] ?? '', ['prepare', 'invoke'], true)) {
  $process = proc_open(
    array_merge([$real], array_slice($argv, 1)),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes
  );
  exit(is_resource($process) ? proc_close($process) : 1);
}
if (($argv[1] ?? '') === 'prepare') {
  copy($argv[2], $argv[5] ?? $argv[2]);
  file_put_contents($argv[3], "bytecode");
  exit(0);
}
if (($argv[1] ?? '') !== 'invoke') exit(2);
$input = json_decode(stream_get_contents(STDIN), true);
echo json_encode([
  'status' => 200,
  'headers' => ['content-type' => 'application/json'],
  'body' => json_encode(['ok' => true, 'path' => $input['request']['path'] ?? null]),
], JSON_UNESCAPED_SLASHES);
`,
  );
  chmodSync(runtimePath, 0o755);

  rt = await startRuntime({
    env: {
      // Generous pool: idle keep-alive connections from earlier requests pin
      // php -S workers, and a fill pair that lands on a single free worker
      // serializes — the counter then never reaches capacity.
      PHP_CLI_SERVER_WORKERS: "32",
      SPACEFAST_RUNTIME_TEST_ADMISSION_HOLD: "1",
      SPACEFAST_RUNTIME_BIN: runtimePath,
    },
  });
  await deploy(rt, {
    spaceId: SPACE_X,
    versionId: VERSION_X,
    files: {
      "index.html": "<h1>x</h1>\n",
      "assets/app.js": "window.__spacefastStaticFixture = true;\n",
      "assets/large.bin": "x".repeat(262144),
      "private/index.html": "<h1>private x</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/status",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/status",
          schema_hash: "sha256:admission",
          capabilities: { db: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST_X],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await deploy(rt, {
    spaceId: SPACE_Y,
    versionId: VERSION_Y,
    files: {
      "index.html": "<h1>y</h1>\n",
      "private/index.html": "<h1>private y</h1>\n",
      "assets/large.bin": "y".repeat(262144),
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST_Y],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // Space Z is Public except for a Limited /admin subtree. Public paths must
  // never be admission-counted just because another path is protected.
  await deploy(rt, {
    spaceId: SPACE_Z,
    versionId: VERSION_Z,
    files: {
      "index.html": "<h1>z</h1>\n",
      "admin/index.html": "<h1>admin z</h1>\n",
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST_Z],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await setAdmissionPolicy(SPACE_X, VERSION_X);
  await setAdmissionPolicy(SPACE_Y, VERSION_Y);
  await setAdmissionPolicy(
    SPACE_Z,
    VERSION_Z,
    accessProjection({
      mode: "public",
      overrides: [{ scope: "/admin", mode: "limited", indexable: false }],
      people: [
        {
          personId: "per_admission_admin",
          grants: [{ id: "pgr_admission_admin", scope: "/admin", role: "viewer" }],
        },
      ],
    }),
  );
  visitorCookies.set(HOST_X, await openAuthorities(rt, HOST_X, SPACE_X, ["member:mem_admission"]));
  visitorCookies.set(HOST_Y, await openAuthorities(rt, HOST_Y, SPACE_Y, ["member:mem_admission"]));
  visitorCookies.set(
    HOST_Z,
    await openAuthorities(rt, HOST_Z, SPACE_Z, ["person:per_admission_admin"]),
  );
});

afterAll(() => {
  rt?.stop();
  if (runtimeRoot) {
    rmSync(runtimeRoot, { recursive: true, force: true });
  }
});

beforeEach(() => {
  rmSync(path.join(rt.storageRoot, "runtime/admission"), { recursive: true, force: true });
});

test("protected uncacheable requests shed only the over-limit requests for one space", async () => {
  // One real holder proves the protected path actually acquires a slot...
  const holder = trackHold(held("/private/"));
  await waitForCounter(SPACE_X, 1);

  // ...an at-capacity-minus-one request still ADMITS (the boundary is real,
  // not an off-by-one shed)...
  seedCounter(rt, SPACE_X, LIMIT - 1);
  const lastSlot = await get(rt, HOST_X, "/private/", {
    headers: { cookie: visitorCookies.get(HOST_X) ?? "" },
  });
  expect(lastSlot.status).toBe(200);

  // ...and once the counter sits at capacity, the next request sheds while
  // the other space still serves.
  seedCounter(rt, SPACE_X, LIMIT);
  const shed = await get(rt, HOST_X, "/private/", {
    headers: { cookie: visitorCookies.get(HOST_X) ?? "" },
  });
  expect(shed.status).toBe(429);
  expect(shed.headers.get("retry-after")).toBe("2");
  expect(await shed.text()).toBe("Too Many Requests\n");

  const otherSpace = await get(rt, HOST_Y, "/private/", {
    headers: { cookie: visitorCookies.get(HOST_Y) ?? "" },
  });
  expect(otherSpace.status).toBe(200);

  holder.assertStillHeld();
  expect((await holder.response).status).toBe(200);
}, 8_000);

test(
  "large PHP-streamed public files use the per-space capacity gate",
  { timeout: 10_000 },
  async () => {
    const holder = trackHold(held("/assets/large.bin"));
    await waitForCounter(SPACE_X, 1);

    seedCounter(rt, SPACE_X, LIMIT);
    const shed = await get(rt, HOST_X, "/assets/large.bin");
    expect(shed.status).toBe(429);
    expect(shed.headers.get("retry-after")).toBe("2");

    const otherSpace = await get(rt, HOST_Y, "/assets/large.bin");
    expect(otherSpace.status).toBe(200);
    expect((await otherSpace.arrayBuffer()).byteLength).toBe(262144);

    holder.assertStillHeld();
    expect((await holder.response).status).toBe(200);
  },
);

test("static cache-miss file serving is not counted by the admission limiter", async () => {
  const responses = await Promise.all([
    get(rt, HOST_X, "/assets/app.js", {
      headers: { "x-spacefast-test-admission-hold-us": "600000" },
    }),
    get(rt, HOST_X, "/assets/app.js", {
      headers: { "x-spacefast-test-admission-hold-us": "600000" },
    }),
    get(rt, HOST_X, "/assets/app.js", {
      headers: { "x-spacefast-test-admission-hold-us": "600000" },
    }),
    get(rt, HOST_X, "/assets/app.js", {
      headers: { "x-spacefast-test-admission-hold-us": "600000" },
    }),
  ]);
  expect(responses.map((response) => response.status)).toEqual([200, 200, 200, 200]);
});

test("stale file fallback counters self-heal after a crashed request", async () => {
  const counterPath = path.join(rt.storageRoot, "runtime/admission", `${SPACE_X}.json`);
  mkdirSync(path.dirname(counterPath), { recursive: true });
  writeFileSync(counterPath, `${JSON.stringify({ count: 99, updated_at: 1 })}\n`);

  const response = await get(rt, HOST_X, "/private/", {
    headers: { cookie: visitorCookies.get(HOST_X) ?? "" },
  });
  expect(response.status).toBe(200);
});

test("Limited subtrees never admission-count Public paths", async () => {
  // Fill the limiter through the Limited /admin subtree, then assert unmatched
  // Public paths still serve.
  const holder = trackHold(
    get(rt, HOST_Z, "/admin/", {
      headers: {
        cookie: visitorCookies.get(HOST_Z) ?? "",
        "x-spacefast-test-admission-hold-us": FILL_HOLD_US,
      },
    }),
  );
  // The matched /admin/* path IS counted: the real holder moves the counter...
  await waitForCounter(SPACE_Z, 1);
  seedCounter(rt, SPACE_Z, LIMIT);

  const staticResponse = await get(rt, HOST_Z, "/");
  expect(staticResponse.status).toBe(200);
  expect(await staticResponse.text()).toBe("<h1>z</h1>\n");

  // ...and over-limit /admin/* requests shed 429.
  const adminShed = await get(rt, HOST_Z, "/admin/", {
    headers: { cookie: visitorCookies.get(HOST_Z) ?? "" },
  });
  expect(adminShed.status).toBe(429);
  expect(adminShed.headers.get("retry-after")).toBe("2");

  holder.assertStillHeld();
  expect((await holder.response).status).toBe(200);
}, 8_000);

test("write-method requests on protected paths acquire admission before access evaluation", async () => {
  // Contract A1: the slot is charged BEFORE canonical access evaluation runs
  // on a doomed request, so a credential-less
  // POST flood at a protected path is bounded by the limiter. The counter
  // reaching capacity from credential-less POSTs is itself the proof the
  // acquisition happens before the 401 renders.
  const holder = trackHold(
    get(rt, HOST_X, "/private/", {
      method: "POST",
      headers: { "x-spacefast-test-admission-hold-us": FILL_HOLD_US },
    }),
  );
  // The counter moves WHILE the credential-less POST is still in flight (its
  // 403 has not rendered yet — the hold sits between acquire and access
  // evaluation), which is the acquire-before-access proof.
  await waitForCounter(SPACE_X, 1);
  holder.assertStillHeld();
  seedCounter(rt, SPACE_X, LIMIT);

  const shed = await get(rt, HOST_X, "/private/", { method: "POST" });
  expect(shed.status).toBe(429);
  expect(shed.headers.get("retry-after")).toBe("2");

  // Write methods on paths no policy rule matches stay uncounted even while
  // the counter is at capacity.
  const unmatched = await get(rt, HOST_X, "/", { method: "POST" });
  expect(unmatched.status).not.toBe(429);

  expect((await holder.response).status).toBe(403);
}, 8_000);

test("Zero invocations and protected paths share one admission counter", async () => {
  // The counter fills via the protected FILE path; the ZERO path sheds on it.
  const holder = trackHold(held("/private/"));
  await waitForCounter(SPACE_X, 1);

  // Below capacity the zero path still serves — shedding below proves nothing
  // about sharing, so pin the admit side first.
  const belowLimit = await get(rt, HOST_X, "/api/status");
  expect(belowLimit.status).toBe(200);

  seedCounter(rt, SPACE_X, LIMIT);
  const zero = await get(rt, HOST_X, "/api/status");
  expect(zero.status).toBe(429);
  expect(zero.headers.get("retry-after")).toBe("2");

  holder.assertStillHeld();
  expect((await holder.response).status).toBe(200);
}, 8_000);

// Confirmed finding F4: the self-heal window was a hardcoded 15s constant
// that could expire under a genuinely still-in-flight holder — a fresh
// request would then re-admit a full `limit` while the original holders
// still occupied FPM slots, and the crash-recovery reset could also decrement
// a just-reset counter below zero. SPACEFAST_ADMISSION_STALE_SECONDS is now a
// real, wired config seam (default sized to request-timeout scale, not 15s);
// this proves the seam actually controls the boundary, that a held slot
// still counts fully WITHIN the window, and that the counter never goes
// negative across the reset/release race.
test("admission stale window is a real config knob: a held slot counts within it, and release never underflows", async () => {
  const host = "admission-window.test";
  const spaceId = "spc_adm_window";
  const versionId = "ver_adm_window_1";
  const windowSeconds = 1;
  // 4s hold, well past the 1s shrunk window. The stale check compares
  // whole-second time() values, so the wait before `afterWindow` below needs
  // a couple of extra integer-second boundaries of margin, not just
  // `windowSeconds` — this keeps the assertion robust to that flooring.
  const holdMicros = 4_000_000;
  const shrunk = await startRuntime({
    env: {
      PHP_CLI_SERVER_WORKERS: "32",
      SPACEFAST_ADMISSION_STALE_SECONDS: String(windowSeconds),
      SPACEFAST_RUNTIME_TEST_ADMISSION_HOLD: "1",
    },
  });
  try {
    await deploy(shrunk, {
      spaceId,
      versionId,
      files: { "index.html": "<h1>window root</h1>\n", "private/index.html": "<h1>window</h1>\n" },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    await putRoute(shrunk, spaceId, "production", {
      version_id: versionId,
      config: {
        admission: { concurrency: LIMIT },
        ...accessProjection({ memberRefs: ["member:mem_admission_window"] }),
      },
    });
    const cookie = await openAuthorities(shrunk, host, spaceId, ["member:mem_admission_window"]);

    const counterPath = path.join(shrunk.storageRoot, "runtime/admission", `${spaceId}.json`);
    const readCounter = () => readFileCounter(counterPath);

    // One REAL long-running holder (php -S cannot reliably start LIMIT
    // simultaneous requests — see the fill-strategy comment up top); the
    // remaining capacity is seeded directly, giving the same at-capacity
    // counter with a genuinely in-flight request behind it.
    const heldRequest = get(shrunk, host, "/private/", {
      headers: {
        cookie,
        "x-spacefast-test-admission-hold-us": String(holdMicros),
      },
    });
    // Deadline stays below holdMicros so a counter observation implies the
    // holder is still genuinely in flight when the window assertions run.
    const deadline = Date.now() + 3000;
    for (;;) {
      if ((readCounter()?.count ?? 0) >= 1) break;
      if (Date.now() > deadline) throw new Error(`counter ${spaceId} did not fill`);
      await new Promise((resolve) => setTimeout(resolve, 20));
    }
    seedCounter(shrunk, spaceId, LIMIT);

    // Well inside the shrunk window: the held slots still fully count.
    const shedInsideWindow = await get(shrunk, host, "/private/", {
      headers: { cookie },
    });
    expect(shedInsideWindow.status).toBe(429);

    // Past the shrunk window (originals still legitimately running until
    // holdMicros elapses): the crash-recovery self-heal fires and admits a
    // fresh request — this is the exact bypass the finding describes,
    // reproduced deterministically via the config seam rather than the old
    // unconfigurable 15s constant. The real fix is the wider DEFAULT so real
    // requests never cross it; this seam is what makes that provable at all.
    // Poll rather than sleeping a fixed span: inside the window every probe is
    // shed (429), and the first probe past it is admitted (200). The deadline
    // stays under holdMicros so the original holder is still genuinely in flight.
    let afterWindow: Response;
    const windowDeadline = Date.now() + (windowSeconds + 3) * 1000;
    for (;;) {
      afterWindow = await get(shrunk, host, "/private/", { headers: { cookie } });
      if (afterWindow.status === 200) break;
      // Still inside the stale window: the probe is shed, never admitted.
      expect(afterWindow.status).toBe(429);
      if (Date.now() > windowDeadline) {
        throw new Error("stale-window self-heal did not admit before the deadline");
      }
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    expect(afterWindow.status).toBe(200);

    expect((await heldRequest).status).toBe(200);

    // The original holder's release is scoped to its captured, now-superseded
    // generation, so it structurally cannot underflow the new post-reset
    // generation below zero. Releases run in a shutdown function AFTER the
    // response reaches the client, so give the write a beat to land.
    const releaseDeadline = Date.now() + 2000;
    while ((readCounter()?.count ?? -1) !== 0 && Date.now() < releaseDeadline) {
      await new Promise((resolve) => setTimeout(resolve, 20));
    }
    expect(readCounter()?.count).toBe(0);
  } finally {
    shrunk.stop();
  }
});

test("admission releases stay within their acquire generation", () => {
  const result = Bun.spawnSync({
    cmd: [PHP_BINARY, GENERATION_FIXTURE],
    stdout: "pipe",
    stderr: "pipe",
  });

  expect(result.exitCode, result.stderr.toString()).toBe(0);
  const output: unknown = JSON.parse(result.stdout.toString());
  expect(output).toEqual({
    request_b_admitted: true,
    generation_file_count_after_rotation: 1,
    count_after_stale_release: 1,
    fresh_results: [true, false],
    persisted_count_at_limit: 2,
    count_after_current_releases: 0,
    admitted_after_slots_freed: true,
    final_persisted_count: 0,
    generation_file_counts_after_rotations: [1, 1, 1, 1, 1],
    mixed_file_cutover: {
      request_b_admitted: true,
      legacy_path_exists_after_cutover: false,
      count_after_cutover: 1,
      count_after_legacy_release: 1,
      fresh_results: [true, false],
      persisted_count_at_limit: 2,
      final_persisted_count: 0,
    },
  });
});
