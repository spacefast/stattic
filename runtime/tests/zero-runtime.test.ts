import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync } from "node:crypto";
import { chmodSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { routeInventoryRecordV1Schema } from "../../packages/common/src/contracts/route-inventory.ts";
import { runtimeVersionFinalizeResponseSchema } from "../../packages/common/src/contracts/runtime-api.ts";
import {
  deploy,
  finalizeRaw,
  get,
  postAccessCallback,
  publicAccessConfig,
  putRoute,
  RESPONSES,
  type ResponseEntry,
  responseEntries,
  responseEntry,
  responseTableFiles,
  type Runtime,
  sha256,
  signEd25519Jwt,
  signToken,
  RUNTIME_INSTANCE_ID,
  startRuntime,
  versionRoot,
  versionMetadata,
  visitorIssuer,
} from "./harness.ts";

/**
 * The compiled Zero action of a response-table key, or null when it carries none
 * (contracts §5: `'a' => ['t' => 'zero', …]` under RESPONSES.entryKeys.action).
 */
function zeroAction(entry: ResponseEntry | null): Record<string, unknown> | null {
  const action = entry?.a;
  if (action === null || action === undefined) {
    return null;
  }
  return action.t === RESPONSES.actionTypes.zero ? action : null;
}

// The Space's per-Space exchange credential (contracts §4): every `sfv2_` session
// cookie is signed with a key derived from it, so a projection that carries no
// access-page exchange can mint no session at all and every handoff denies.
const EXCHANGE_CREDENTIAL = "runtime-zero-exchange-credential-0123456789";
const ACCESS_EXCHANGE = {
  passwordUrl: "https://api.spacefast.test/v1/access/acquire/zero/password",
  tokenUrl: "https://api.spacefast.test/v1/access/acquire/zero/token",
  requestUrl: "https://api.spacefast.test/v1/access/acquire/zero/request",
  credential: EXCHANGE_CREDENTIAL,
};

let rt: Runtime;
let runtimeRoot: string;
let runtimePath: string;
let capturePath: string;

const HOST = "zero-runtime.test";
const REAL_RUNTIME_PATH = path.resolve(
  process.env.SPACEFAST_RUNTIME_BIN ??
    path.join(import.meta.dir, "../../target/debug/stattic-runtime"),
);

beforeAll(async () => {
  runtimeRoot = mkdtempSync(path.join(os.tmpdir(), "stattic-runtime-test-"));
  runtimePath = path.join(runtimeRoot, "fake-runtime.php");
  capturePath = path.join(runtimeRoot, "capture.json");
  const phpPath = Bun.which("php") ?? "/usr/bin/php";
  writeFileSync(
    runtimePath,
    `#!${phpPath}
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
    $source = $argv[2] ?? '';
    $bytecode = $argv[3] ?? '';
    $generated = $argv[5] ?? $source;
    if ($source === '' || $bytecode === '' || !is_file($source)) {
        fwrite(STDERR, "prepare args invalid");
        exit(2);
    }
    file_put_contents($generated, file_get_contents($source));
    file_put_contents($bytecode, "fake-bytecode");
    exit(0);
}
if (($argv[1] ?? '') !== 'invoke') exit(2);
$input = stream_get_contents(STDIN);
$capture = ${JSON.stringify(capturePath)};
file_put_contents($capture, $input);
$envelope = json_decode($input, true);
$bodyPayload = [
    'ok' => true,
    'endpointId' => $envelope['endpointId'] ?? null,
    'method' => $envelope['request']['method'] ?? null,
    'path' => $envelope['request']['path'] ?? null,
    'params' => $envelope['request']['params'] ?? null,
];
if (($envelope['endpointId'] ?? null) === 'GET /api/env') {
    $bodyPayload['env'] = [
        'platform' => getenv('SPACEFAST_PLATFORM_SECRET') ?: null,
        'nativeAllowed' => getenv('APP_ALLOWED') ?: null,
        'nativePreload' => getenv('LD_PRELOAD') ?: null,
        'envelopeAllowed' => $envelope['variables']['APP_ALLOWED'] ?? null,
        'db' => getenv('SPACEFAST_ZERO_DATABASE_URL') ?: null,
        'dbSource' => getenv('SPACEFAST_ZERO_DATABASE_URL_SOURCE') ?: null,
        'ambient' => getenv('DATABASE_URL') ?: null,
    ];
    $bodyPayload['variables'] = $envelope['variables'] ?? null;
}
if (($envelope['endpointId'] ?? null) === 'GET /api/whoami') {
    // Echoed so a test can compare the identity two CONCURRENT requests saw;
    // the single capture file only ever holds the last envelope written.
    $bodyPayload['auth'] = $envelope['auth'] ?? null;
}
if (array_key_exists('artifactPath', $envelope)) {
    $bodyPayload['artifactPath'] = $envelope['artifactPath'];
}
$headers = [
    'content-type' => 'application/json; charset=utf-8',
    'x-zero-runner' => 'fake',
];
if (in_array($envelope['endpointId'] ?? null, ['GET /api/cached', 'GET /api/private-cached'], true)) {
    $headers['cache-control'] = 'public, max-age=60';
}
if (($envelope['endpointId'] ?? null) === 'GET /api/private-cached') {
    $headers['access-control-allow-origin'] = $envelope['request']['headers']['origin'] ?? '*';
    $headers['cross-origin-resource-policy'] = 'cross-origin';
    $headers['content-security-policy'] = 'frame-ancestors *';
    $headers['vary'] = 'Origin';
    $headers['x-robots-tag'] = 'all';
}
if (($envelope['endpointId'] ?? null) === 'GET /api/spoofed-headers') {
    // A compromised runner trying to smuggle platform-managed headers past
    // the PHP fail-closed boundary filter.
    $headers['X-Spacefast-Zero-Runner-Metrics'] = 'spoofed';
    $headers['x-stattic-internal'] = 'spoofed';
    $headers['Set-Cookie'] = 'evil=1';
    $headers['Location'] = '/pwned';
    // ...and the provider-owned edge channel (contracts §16): steering
    // A8C-Edge-Cache would put a per-visitor Zero response in the shared edge.
    $headers['A8C-Edge-Cache'] = 'cache';
    $headers['x-ac'] = 'spoofed';
    $headers['x-sc'] = 'spoofed';
    $headers['x-nc'] = 'spoofed';
    $headers['Strict-Transport-Security'] = 'max-age=0';
    $headers['x-app-ok'] = 'yes';
}
$body = json_encode($bodyPayload, JSON_UNESCAPED_SLASHES);
echo json_encode([
    'status' => 201,
    'headers' => $headers,
    'body' => $body,
    'metrics' => [
        'durationMs' => 7,
        'db' => [
            'operations' => 1,
            'connectMs' => 2.5,
            'queryMs' => 1.25,
            'executeMs' => 0,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
`,
  );
  chmodSync(runtimePath, 0o755);

  rt = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_BIN: runtimePath,
      SPACEFAST_ZERO_RUNNER_CAPTURE: capturePath,
      SPACEFAST_ZERO_DATABASE_URL: "mysql://zero-runtime.test/db",
      SPACEFAST_PLATFORM_SECRET: "must-not-leak",
      SPACEFAST_VISITOR_IP: "203.0.113.44",
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_runtime",
    versionId: "ver_zero_runtime_1",
    metadata: { mode: "website", title: "Zero Runtime" },
    files: {
      "index.html": "<h1>zero runtime</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/status",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "POST /api/status",
          schema_hash: "sha256:test",
          capabilities: { db: false },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/items/:id",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/items/:id",
          schema_hash: "sha256:test",
          capabilities: { db: false },
          db: { schemaHash: "sha256:dynamic" },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/default-capabilities",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/default-capabilities",
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/foo...bar/:id",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /foo...bar/:id",
          capabilities: { db: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Runtime" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => {
  rt?.stop();
  if (runtimeRoot) {
    rmSync(runtimeRoot, { recursive: true, force: true });
  }
});

test("invokes a finalized Zero lookup action through a fresh runner process", async () => {
  const response = await get(rt, HOST, "/api/status?debug=1", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "spacefast-visitor-ip": "198.51.100.7",
      "x-test-header": "present",
    },
    body: JSON.stringify({ ping: true }),
  });

  expect(response.status).toBe(201);
  expect(response.headers.get("x-zero-runner")).toBe("fake");
  expect(response.headers.get("x-spacefast-zero-runner-metrics")).toBeNull();
  expect(await response.json()).toMatchObject({
    ok: true,
    endpointId: "POST /api/status",
    method: "POST",
    path: "/api/status",
    params: [],
  });

  const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
  expect(envelope.protocol).toBe("stattic.zero.invoke.v1");
  expect(envelope.versionRoot).toEndWith("/spc_zero_runtime/versions/ver_zero_runtime_1");
  expect(envelope.endpointId).toBe("POST /api/status");
  expect(envelope.executionMode).toBe("write");
  expect(envelope.request).toMatchObject({
    method: "POST",
    path: "/api/status",
    uri: "/api/status?debug=1",
    host: HOST,
    query: "debug=1",
    params: {},
  });
  expect(envelope.request.headers["x-test-header"]).toBe("present");
  expect(envelope.request.headers["spacefast-visitor-ip"]).toBeUndefined();
  expect(Buffer.from(envelope.request.bodyBase64, "base64").toString("utf8")).toBe(
    JSON.stringify({ ping: true }),
  );
  expect(envelope.context).toMatchObject({
    spaceId: "spc_zero_runtime",
    versionId: "ver_zero_runtime_1",
    schemaHash: "sha256:test",
    visitorIp: "203.0.113.44",
    authRef: "current",
    variablesRef: "finalized",
  });
});

test("finalizes an NFC Unicode Zero endpoint route", async () => {
  const response = await finalizeRaw(
    rt,
    "spc_zero_unicode_route",
    "ver_zero_unicode_route_1",
    { "index.html": "<h1>unicode zero route</h1>\n" },
    {
      serving: {
        zero_endpoints: [
          {
            execution_mode: "read",
            method: "GET",
            path: "/api/café",
            source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
            capabilities: { db: false },
          },
        ],
      },
    },
  );

  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({ zero_endpoint_count: 1 });
});

/**
 * `action.run` left the vocabulary this engine compiles, but the Zero clients
 * baked into already-published capsules still send it and still read an
 * `action.result` frame back. Those bundles cannot be rebuilt, so both halves
 * of the exchange stay served — the same standing the `/__spacefast/zero/*`
 * route spellings have beside `/__zero/*`.
 */
test("serves the retired action.run operation and its action.result frame", async () => {
  const host = "zero-action-run.test";
  const actionRuntime = await startRuntime({
    env: { SPACEFAST_RUNTIME_BIN: runtimePath, SPACEFAST_ZERO_RUNNER_CAPTURE: capturePath },
  });
  try {
    await deploy(actionRuntime, {
      spaceId: "spc_zero_action_run",
      versionId: "ver_zero_action_run_1",
      zero: { auth: { provider: "gravatar" } },
      metadata: { mode: "website", title: "Zero Action Run" },
      files: { "index.html": "<h1>zero action run</h1>\n" },
      serving: {
        zero_runs: [
          {
            execution_mode: "write",
            run_id: "action_notify",
            source: "globalThis.__statticZeroResult = '{}';",
            capabilities: { db: false },
          },
        ],
      },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", site_title: "Zero Action Run" }),
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const response = await get(actionRuntime, host, "/__zero/run", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ id: "run-1", op: "action.run", name: "notify" }),
    });

    expect(response.status).toBe(200);
    expect(await response.json()).toMatchObject({
      id: "run-1",
      op: "action.result",
      ok: true,
      result: { ok: true, endpointId: "action_notify" },
    });
    // An action was never confined to the read lane, so it keeps the write one.
    expect(JSON.parse(readFileSync(capturePath, "utf8")).executionMode).toBe("write");
  } finally {
    actionRuntime.stop();
  }
});

test("Zero identity uses the canonical access session (guest fallback, then member)", async () => {
  const key = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(key.publicKey);
  const jwk = key.publicKey.export({ format: "jwk" });
  const host = "zero-identity.test";
  const idRuntime = await startRuntime({
    env: { SPACEFAST_RUNTIME_BIN: runtimePath, SPACEFAST_ZERO_RUNNER_CAPTURE: capturePath },
  });
  try {
    await deploy(idRuntime, {
      spaceId: "spc_zero_identity",
      versionId: "ver_zero_identity_1",
      zero: { auth: { provider: "gravatar" } },
      metadata: { mode: "website", title: "Zero Identity" },
      files: { "index.html": "<h1>zero identity</h1>\n" },
      serving: {
        zero_runs: [
          {
            execution_mode: "read",
            run_id: "query_whoami",
            source: "globalThis.__statticZeroResult = '{}';",
            capabilities: { db: false },
          },
          {
            execution_mode: "write",
            run_id: "mutation_whoami",
            source: "globalThis.__statticZeroResult = '{}';",
            capabilities: { db: false },
          },
          {
            execution_mode: "write",
            run_id: "mutation_updateProfile",
            source: "globalThis.__statticZeroResult = '{}';",
            capabilities: { db: false },
          },
        ],
        zero_endpoints: [
          {
            execution_mode: "read",
            method: "GET",
            path: "/api/whoami",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/whoami",
            schema_hash: "sha256:identity",
            capabilities: { db: false },
          },
          {
            execution_mode: "write",
            method: "POST",
            path: "/api/update-profile",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "POST /api/update-profile",
            schema_hash: "sha256:identity-write",
            capabilities: { db: false },
          },
        ],
      },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", site_title: "Zero Identity" }),
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    await putRoute(idRuntime, "spc_zero_identity", "production", {
      version_id: "ver_zero_identity_1",
      config: {
        projection_generation: 1,
        visitor_issuer: "spacefast-api",
        visitor_jwks: {
          keys: [
            {
              kty: "OKP",
              crv: "Ed25519",
              kid: issuer.kid,
              alg: "EdDSA",
              use: "sig",
              x: jwk.x ?? "",
            },
          ],
        },
        session_version: 0,
        authorization: {
          generation: 1,
          sessionVersion: 0,
          fence: "none",
          acquireUrl: "https://access.spacefast.test/acquire/zero",
          accessPage: {
            displayName: "Zero Identity",
            accountUrl: "https://api.spacefast.test/v1/access/acquire/opaque-zero",
            connections: [],
            exchange: ACCESS_EXCHANGE,
          },
          spaceClaimed: true,
          grants: [
            {
              id: "grt_zero_identity_public",
              generation: 1,
              audience: { kind: "public" },
              resources: { include: ["/**"], exclude: [] },
              capabilities: ["page.view"],
              constraints: {},
              target: { kind: "live" },
              source: { kind: "managed", reference: "test:zero-identity-public" },
            },
            {
              id: "grt_zero_identity_member",
              generation: 1,
              audience: {
                kind: "external",
                issuer: "spacefast-membership",
                subject: "mem_zero",
              },
              resources: { include: ["/**"], exclude: [] },
              capabilities: ["page.view"],
              constraints: {},
              target: { kind: "live" },
              source: { kind: "system", reference: "test:zero-identity-member" },
            },
            {
              id: "grt_zero_identity_person",
              generation: 1,
              audience: { kind: "person", personId: "per_zero" },
              resources: { include: ["/**"], exclude: [] },
              capabilities: ["page.view"],
              constraints: {},
              target: { kind: "live" },
              source: { kind: "managed", reference: "test:zero-identity-person" },
            },
          ],
        },
      },
    });

    // The canonical spelling and the frozen-client alias reach the same
    // auth_start operation with byte-identical results.
    for (const startPath of [
      "/__zero/auth/start?returnTo=%2Faccount%3Ftab%3Dprofile",
      "/__spacefast/zero/auth/gravatar/start?returnTo=%2Faccount%3Ftab%3Dprofile",
    ]) {
      const signIn = await get(idRuntime, host, startPath);
      expect({ status: signIn.status, body: await signIn.clone().text() }).toEqual({
        status: 302,
        body: "Redirecting.\n",
      });
      const signInUrl = new URL(signIn.headers.get("location") ?? "");
      expect(signInUrl.origin + signInUrl.pathname).toBe(
        "https://api.spacefast.test/v1/access/acquire/opaque-zero",
      );
      expect(signInUrl.searchParams.get("host")).toBe(host);
      expect(signInUrl.searchParams.get("return")).toBe("/account?tab=profile");
      expect(signInUrl.searchParams.get("browserState")).toMatch(/^[a-f0-9]{64}$/);
      expect(signIn.headers.get("set-cookie")).toContain("spacefast_access_state_dev_");
    }

    const visitorConnect = await get(
      idRuntime,
      host,
      "/__spacefast/zero/connectors/tracker/connect?return_to=%2Faccount",
    );
    expect(visitorConnect.status).toBe(302);
    const visitorSignIn = new URL(visitorConnect.headers.get("location") ?? "");
    expect(visitorSignIn.origin).toBe("https://api.spacefast.test");
    expect(visitorSignIn.searchParams.get("return")).toBe(
      "/__spacefast/zero/connectors/tracker/connect?return_to=%2Faccount",
    );
    expect(visitorConnect.headers.get("cache-control")).toContain("no-store");
    const expiredCallback = await get(
      idRuntime,
      host,
      "/__spacefast/zero/connectors/tracker/callback?code=private-code&state=private-state",
    );
    expect(expiredCallback.status).toBe(302);
    expect(new URL(expiredCallback.headers.get("location") ?? "").searchParams.get("return")).toBe(
      "/__spacefast/zero/connectors/tracker/connect",
    );

    // The DOCUMENT settles the guest session, before the page can open a single
    // XHR. A Zero client's first render fires auth.get, query.subscribe and
    // mutation.run together; if each cookie-less request minted its own session
    // the browser would keep whichever Set-Cookie landed last, and the identity
    // the page displays would disagree with the one its mutation ran under.
    // Capsules bake their client at build time and may never be rebuilt, so
    // client-side serialization cannot be the fix.
    const document = await get(idRuntime, host, "/");
    expect(document.status).toBe(200);
    const documentCookie = (document.headers.get("set-cookie") ?? "").split(";", 1)[0];
    expect(documentCookie).toMatch(
      /^spacefast_session(?:_dev)?=sfa1_[A-Za-z0-9_-]+\.[a-f0-9]{64}$/,
    );
    // Stateful, so no shared cache may hand one visitor's session to the next.
    expect(document.headers.get("cache-control")).toBe("private, no-store");
    expect(document.headers.get("a8c-edge-cache")).toBe("no-cache");

    // The first XHRs now all present that cookie: none of them mints, and every
    // one of them reports the same guest.
    const concurrent = await Promise.all([
      get(idRuntime, host, "/api/whoami", { headers: { cookie: documentCookie } }),
      get(idRuntime, host, "/api/whoami", { headers: { cookie: documentCookie } }),
    ]);
    expect(concurrent.map((response) => response.status)).toEqual([201, 201]);
    expect(concurrent.map((response) => response.headers.get("set-cookie"))).toEqual([null, null]);
    const [firstIdentity, secondIdentity] = await Promise.all(
      concurrent.map(async (response) => (await response.json()).auth),
    );
    expect(firstIdentity).toMatchObject({ isGuest: true, isAuthenticated: false });
    expect(firstIdentity.userId).toMatch(/^guest:anon_[a-f0-9]{32}$/);
    expect(secondIdentity.userId).toBe(firstIdentity.userId);
    const guestUserId = firstIdentity.userId;

    // Idempotent: a returning visitor is not re-minted, so that document is an
    // ordinary shared-cacheable static response again.
    const returningDocument = await get(idRuntime, host, "/", {
      headers: { cookie: documentCookie },
    });
    expect(returningDocument.status).toBe(200);
    expect(returningDocument.headers.get("set-cookie")).toBeNull();
    expect(returningDocument.headers.get("a8c-edge-cache")).toBe("cache");

    // The XHR lane still mints for a request that arrives without a document
    // (a direct API call, or an edge-cached page), and each such visitor is
    // distinct.
    rmSync(capturePath, { force: true });
    const otherGuest = await get(idRuntime, host, "/api/whoami");
    expect(otherGuest.status).toBe(201);
    expect((otherGuest.headers.get("set-cookie") ?? "").split(";", 1)[0]).toMatch(
      /^spacefast_session(?:_dev)?=sfa1_[A-Za-z0-9_-]+\.[a-f0-9]{64}$/,
    );
    let envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth.userId).toMatch(/^guest:anon_[a-f0-9]{32}$/);
    expect(envelope.auth.userId).not.toBe(guestUserId);

    const now = Math.floor(Date.now() / 1000);
    const multibyteName = "界".repeat(120);
    const token = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "member:mem_zero",
      purpose: "handoff",
      grants: ["member:mem_zero"],
      authorities: ["member:mem_zero"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      sv: 0,
      generation: 1,
      spaceId: "spc_zero_identity",
      // WHO the session is. Zero reads this and nothing else — a member
      // authority alone never makes a visitor a signed-in user.
      principal: "account:usr_zero",
      sid: "1".repeat(64),
      iat: now,
      nbf: now,
      exp: now + 3600,
      jti: "jti_zero_identity",
      profile: {
        name: `  ${multibyteName}  `,
        username: "ada",
        avatar_url: "https://gravatar.com/avatar/abc123abc123abc123abc123abc123ab?s=160",
      },
    });

    const handoff = await postAccessCallback(idRuntime, host, token, "/api/whoami");
    expect(handoff.status).toBe(303);
    expect(handoff.headers.get("location")).toBe("/api/whoami");
    const sessionCookie = (handoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
    // `sfv2_` IS the session: a signed claim set, not an opaque id into a file
    // store (contracts §7 — the file-session machinery is deleted).
    expect(sessionCookie).toMatch(/^spacefast_session(?:_dev)?=sfv2_[A-Za-z0-9_-]+\.[a-f0-9]{64}$/);

    rmSync(capturePath, { force: true });
    const authed = await get(idRuntime, host, "/api/whoami", {
      headers: { cookie: sessionCookie },
    });
    expect(authed.status).toBe(201);
    envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth).toMatchObject({
      userId: "account:usr_zero",
      provider: "gravatar",
      displayName: multibyteName,
      picture: "https://gravatar.com/avatar/abc123abc123abc123abc123abc123ab?s=160",
      profileUrl: "https://gravatar.com/abc123abc123abc123abc123abc123ab",
      user: {
        id: "account:usr_zero",
        displayName: multibyteName,
        picture: "https://gravatar.com/avatar/abc123abc123abc123abc123abc123ab?s=160",
        profileUrl: "https://gravatar.com/abc123abc123abc123abc123abc123ab",
      },
      isGuest: false,
      isAuthenticated: true,
    });

    const workerToken = signToken({
      aud: "spacefast-functions-relay",
      runtime_instance_id: RUNTIME_INSTANCE_ID,
      space_id: "spc_zero_identity",
      version_id: "ver_zero_identity_1",
      capabilities: ["zero.call"],
    });
    for (const [op, cookie, principal] of [
      ["query.run", sessionCookie, "account:usr_zero"],
      ["mutation.run", "", "service:spc_zero_identity"],
    ]) {
      const response = await get(idRuntime, host, "/__spacefast/functions/relay", {
        method: "POST",
        headers: {
          authorization: `Bearer ${workerToken}`,
          "sf-fx-broker": "zero",
          "sf-fx-visitor-host": host,
          cookie: cookie ?? "",
          "content-type": "application/json",
        },
        body: JSON.stringify({ op, name: "whoami", args: [{ forwarded: true }] }),
      });
      expect(response.status, await response.clone().text()).toBe(200);
      const frame = await response.json();
      expect(frame).toMatchObject({
        ok: true,
        op: op === "query.run" ? "query.result" : "mutation.result",
      });
      const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
      expect(envelope.auth.userId).toBe(principal);
      expect(envelope.auth.provider).toBe(cookie ? "gravatar" : "service");
      expect(envelope.context.versionId).toBe("ver_zero_identity_1");
      expect(envelope.request.path).toBe("/__spacefast/zero/run");
      expect(envelope.request.headers.authorization).toBeUndefined();
      expect(envelope.request.headers["sf-fx-broker"]).toBeUndefined();
      expect(
        JSON.parse(Buffer.from(envelope.request.bodyBase64, "base64").toString()).args,
      ).toEqual([{ forwarded: true }]);
    }

    // Cookie identity is ambient browser authority. A sibling origin is
    // same-site and can carry it, but cannot run a mutation. The exact origin
    // and JSON content type are both required before the runner is spawned.
    rmSync(capturePath, { force: true });
    const siblingMutation = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/json",
        origin: "http://sibling.zero-identity.test",
        "sec-fetch-site": "same-site",
      },
      body: JSON.stringify({ id: "mutation-sibling", op: "mutation.run", name: "updateProfile" }),
    });
    expect(siblingMutation.status).toBe(403);
    expect(await siblingMutation.json()).toMatchObject({ code: "zero_mutation_origin_invalid" });
    expect(existsSync(capturePath)).toBe(false);

    const missingFetchMetadata = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/json",
        origin: `http://${host}`,
      },
      body: JSON.stringify({
        id: "mutation-no-fetch-site",
        op: "mutation.run",
        name: "updateProfile",
      }),
    });
    expect(missingFetchMetadata.status).toBe(403);
    expect(existsSync(capturePath)).toBe(false);

    const wrongContentType = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "text/plain",
        origin: `http://${host}`,
        "sec-fetch-site": "same-origin",
      },
      body: JSON.stringify({ id: "mutation-text", op: "mutation.run", name: "updateProfile" }),
    });
    expect(wrongContentType.status).toBe(403);
    expect(existsSync(capturePath)).toBe(false);

    const browserMutation = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/json; charset=utf-8",
        origin: `http://${host}`,
        "sec-fetch-site": "same-origin",
      },
      body: JSON.stringify({ id: "mutation-browser", op: "mutation.run", name: "updateProfile" }),
    });
    expect(browserMutation.status).toBe(200);
    expect(await browserMutation.json()).toMatchObject({
      id: "mutation-browser",
      op: "mutation.result",
      ok: true,
    });

    rmSync(capturePath, { force: true });
    const directFormMutation = await get(idRuntime, host, "/api/update-profile", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/x-www-form-urlencoded",
        origin: `http://${host}`,
        "sec-fetch-site": "same-origin",
      },
      body: "name=Browser",
    });
    expect(directFormMutation.status).toBe(403);
    expect(existsSync(capturePath)).toBe(false);

    const directMutation = await get(idRuntime, host, "/api/update-profile", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/octet-stream",
        origin: `http://${host}`,
        "sec-fetch-site": "same-origin",
      },
      body: "profile-update",
    });
    expect(directMutation.status).toBe(201);

    // A consumed browser handoff is not platform bearer authority. Replaying
    // it in the explicit header must fail before tenant code runs, even when a
    // valid browser cookie is present beside it.
    rmSync(capturePath, { force: true });
    const replayedHandoffMutation = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        cookie: sessionCookie,
        "content-type": "application/json",
        "x-sf-authorization": `Bearer ${token}`,
      },
      body: JSON.stringify({
        id: "mutation-replayed-handoff",
        op: "mutation.run",
        name: "updateProfile",
      }),
    });
    expect(replayedHandoffMutation.status).toBe(403);
    expect(existsSync(capturePath)).toBe(false);

    // A signed system-view proof is still read-only. It cannot borrow the
    // explicit-header exemption to execute a write.
    const viewToken = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "system:spc_zero_identity",
      purpose: "system-view",
      capabilities: ["page.view"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      spaceId: "spc_zero_identity",
      generation: 1,
      iat: now,
      nbf: now,
      exp: now + 3600,
    });
    const viewMutation = await get(idRuntime, host, "/__zero/run", {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-sf-authorization": `Bearer ${viewToken}`,
      },
      body: JSON.stringify({ id: "mutation-view", op: "mutation.run", name: "updateProfile" }),
    });
    expect(viewMutation.status).toBe(403);
    expect(existsSync(capturePath)).toBe(false);

    // A non-browser credential exchange carries a fresh one-use handoff. It
    // has no ambient cookie to protect and does not need browser-only headers.
    const bearerToken = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "member:mem_zero",
      purpose: "handoff",
      authorities: ["member:mem_zero"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      spaceId: "spc_zero_identity",
      generation: 1,
      sid: "2".repeat(64),
      iat: now,
      nbf: now,
      exp: now + 60,
      jti: "jti_zero_machine_mutation",
    });
    const bearerRequest = {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-sf-authorization": `Bearer ${bearerToken}`,
      },
      body: JSON.stringify({ id: "mutation-bearer", op: "mutation.run", name: "updateProfile" }),
    };
    const bearerMutation = await get(idRuntime, host, "/__zero/run", bearerRequest);
    expect(bearerMutation.status).toBe(200);
    const replayedBearerMutation = await get(idRuntime, host, "/__zero/run", bearerRequest);
    expect(replayedBearerMutation.status).toBe(403);

    // The agent token is a different signed purpose: reusable until expiry,
    // not a handoff whose jti is consumed by its first request. Its issue time
    // is deliberately outside the handoff freshness window.
    const runtimeBearerToken = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "member:mem_zero",
      purpose: "runtime-bearer",
      authorities: ["member:mem_zero"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      spaceId: "spc_zero_identity",
      generation: 1,
      sid: "3".repeat(64),
      iat: now - 120,
      nbf: now - 120,
      exp: now + 3600,
      jti: "jti_zero_reusable_agent_bearer",
    });
    const runtimeBearerRequest = {
      method: "POST",
      headers: {
        "content-type": "application/json",
        "x-sf-authorization": `Bearer ${runtimeBearerToken}`,
      },
      body: JSON.stringify({
        id: "mutation-runtime-bearer",
        op: "mutation.run",
        name: "updateProfile",
      }),
    };
    const firstRuntimeBearerMutation = await get(
      idRuntime,
      host,
      "/__zero/run",
      runtimeBearerRequest,
    );
    expect(firstRuntimeBearerMutation.status).toBe(200);
    const secondRuntimeBearerMutation = await get(
      idRuntime,
      host,
      "/__zero/run",
      runtimeBearerRequest,
    );
    expect(secondRuntimeBearerMutation.status).toBe(200);

    const sessionToken = sessionCookie.slice(sessionCookie.indexOf("=") + 1);
    const storageUpload = await get(
      idRuntime,
      host,
      `/storage?sf_token=${encodeURIComponent(sessionToken)}`,
      {
        method: "POST",
        headers: { "content-type": "text/plain" },
        body: "member-owned storage",
      },
    );
    expect(storageUpload.status).toBe(201);
    expect(await storageUpload.json()).toMatchObject({
      contentType: "text/plain",
      size: 20,
    });

    // Identity is stated, never inferred. The same member authority without a
    // principal claim proves only what the session may do, so Zero still sees
    // a guest.
    const capabilityOnly = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "member:mem_zero",
      purpose: "handoff",
      grants: ["member:mem_zero"],
      authorities: ["member:mem_zero"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      sv: 0,
      generation: 1,
      spaceId: "spc_zero_identity",
      sid: "3".repeat(64),
      iat: now,
      nbf: now,
      exp: now + 3600,
      jti: "jti_zero_identity_capability_only",
    });
    const capabilityHandoff = await postAccessCallback(
      idRuntime,
      host,
      capabilityOnly,
      "/api/whoami",
    );
    expect(capabilityHandoff.status).toBe(303);
    const capabilityCookie = (capabilityHandoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
    rmSync(capturePath, { force: true });
    const stillGuest = await get(idRuntime, host, "/api/whoami", {
      headers: { cookie: capabilityCookie },
    });
    expect(stillGuest.status).toBe(201);
    envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth).toMatchObject({
      isGuest: true,
      isAuthenticated: false,
    });
    expect(envelope.auth.userId).toMatch(/^guest:anon_[a-f0-9]{32}$/);

    // An invited Person who accepted has no platform account and is still
    // somebody: the principal names them, so Zero sees a signed-in identity.
    const personToken = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "person:per_zero",
      purpose: "handoff",
      grants: ["person:per_zero"],
      authorities: ["person:per_zero"],
      iss: "spacefast-api",
      aud: "spc_zero_identity",
      host,
      sv: 0,
      generation: 1,
      spaceId: "spc_zero_identity",
      principal: "person:per_zero",
      sid: "4".repeat(64),
      iat: now,
      nbf: now,
      exp: now + 3600,
      jti: "jti_zero_identity_person",
      profile: { name: "Invited Reviewer" },
    });
    const personHandoff = await postAccessCallback(idRuntime, host, personToken, "/api/whoami");
    expect(personHandoff.status).toBe(303);
    const personCookie = (personHandoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
    rmSync(capturePath, { force: true });
    const asPerson = await get(idRuntime, host, "/api/whoami", {
      headers: { cookie: personCookie },
    });
    expect(asPerson.status).toBe(201);
    envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth).toMatchObject({
      userId: "person:per_zero",
      displayName: "Invited Reviewer",
      isGuest: false,
      isAuthenticated: true,
    });

    const signOut = await get(
      idRuntime,
      host,
      "/__spacefast/zero/auth/sign-out?returnTo=%2Fgoodbye%3Ffrom%3Dzero",
    );
    expect(signOut.status).toBe(302);
    expect(signOut.headers.get("location")).toBe(
      "https://zero-identity.test/__spacefast/access/logout?return=%2Fgoodbye%3Ffrom%3Dzero",
    );
  } finally {
    idRuntime.stop();
  }
});

test("exposes Zero runner metrics only when the metrics header is enabled", async () => {
  const metricsHost = "zero-metrics.test";
  const metricsRuntime = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_BIN: runtimePath,
      SPACEFAST_ZERO_METRICS_HEADER: "1",
    },
  });
  try {
    await deploy(metricsRuntime, {
      spaceId: "spc_zero_metrics",
      versionId: "ver_zero_metrics_1",
      metadata: { mode: "website", title: "Zero Metrics" },
      files: {
        "index.html": "<h1>zero metrics</h1>\n",
      },
      serving: {
        zero_endpoints: [
          {
            execution_mode: "read",
            method: "GET",
            path: "/api/metrics",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/metrics",
            capabilities: { db: false },
          },
        ],
      },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", site_title: "Zero Metrics" }),
        production_hostnames: [metricsHost],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const response = await get(metricsRuntime, metricsHost, "/api/metrics");
    expect(response.status).toBe(201);
    const encoded = response.headers.get("x-spacefast-zero-runner-metrics");
    expect(encoded).toBeString();
    expect(JSON.parse(Buffer.from(encoded ?? "", "base64").toString("utf8"))).toEqual({
      durationMs: 7,
      db: {
        operations: 1,
        connectMs: 2.5,
        queryMs: 1.25,
        executeMs: 0,
      },
    });
  } finally {
    metricsRuntime.stop();
  }
});

test("native compiler defaults omitted Zero capabilities conservatively", () => {
  const root = versionRoot(rt, "spc_zero_runtime", "ver_zero_runtime_1");
  const exactSlug = `post_api_status_${sha256("POST\n/api/status\n0").slice(0, 12)}`;
  const defaultSlug = `get_api_default_capabilities_${sha256(
    "GET\n/api/default-capabilities\n2",
  ).slice(0, 12)}`;

  // The write-side authorities default closed: a `read` handler may not carry
  // one, so an omitted field can never hand it a grant the runner then refuses.
  const partial = JSON.parse(
    readFileSync(path.join(root, `zero/endpoints/${exactSlug}.json`), "utf8"),
  );
  expect(partial.capabilities).toEqual({
    db: false,
    fetch: false,
    auth: true,
    env: true,
    realtime: false,
    logging: true,
    gravatar: true,
    spam: true,
    email: false,
    content: false,
    connectors: false,
    storage: false,
  });

  const omitted = JSON.parse(
    readFileSync(path.join(root, `zero/endpoints/${defaultSlug}.json`), "utf8"),
  );
  expect(omitted.capabilities).toEqual({
    db: true,
    fetch: false,
    auth: true,
    env: true,
    realtime: false,
    logging: true,
    gravatar: true,
    spam: true,
    email: false,
    content: false,
    connectors: false,
    storage: false,
  });
});

test("exact Zero requests dispatch from the compiled response table with their declared identity", async () => {
  const host = "zero-manifest.test";
  await deploy(rt, {
    spaceId: "spc_zero_manifest",
    versionId: "ver_zero_manifest_1",
    metadata: { mode: "website", title: "Zero Manifest" },
    files: {
      "index.html": "<h1>zero manifest</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/manifest",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "POST /api/manifest",
          schema_hash: "sha256:manifest",
          capabilities: { db: false, fetch: false, auth: false, env: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Manifest" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const artifactPath = `zero/endpoints/post_api_manifest_${sha256("POST\n/api/manifest\n0").slice(
    0,
    12,
  )}.json`;
  // §5: an exact Zero path is a compiled response-table key carrying a `zero`
  // action. Only patterns (`/api/:id`) still live in zero/routes.php.
  expect(
    zeroAction(responseEntry(rt, "spc_zero_manifest", "ver_zero_manifest_1", "/api/manifest")),
  ).toMatchObject({
    t: "zero",
    endpoint: "POST /api/manifest",
    artifact: artifactPath,
    execution_mode: "write",
    // A PHP list decodes to an index-keyed map (harness `phpArtifact`).
    methods: { 0: "POST" },
    schema_hash: "sha256:manifest",
  });

  const response = await get(rt, host, "/api/manifest", { method: "POST" });
  expect(response.status).toBe(201);
  expect(await response.json()).toEqual({
    ok: true,
    artifactPath: "zero/endpoints/post_api_manifest_600f83287452.json",
    endpointId: "POST /api/manifest",
    method: "POST",
    path: "/api/manifest",
    params: [],
  });

  // The envelope always names the artifact explicitly; the runner binds
  // identity by asserting the artifact's own endpoint id against it.
  const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
  expect(envelope.endpointId).toBe("POST /api/manifest");
  expect(envelope.artifactPath).toBe("zero/endpoints/post_api_manifest_600f83287452.json");
  expect(envelope.context.schemaHash).toBe("sha256:manifest");
});

test("exact Zero routes beat colliding static files", async () => {
  const activeHost = "zero-exact-fallback-active.test";
  await deploy(rt, {
    spaceId: "spc_zero_exact_fallback_active",
    versionId: "ver_zero_exact_fallback_active_1",
    metadata: { mode: "website", title: "Zero Exact Fallback Active" },
    files: {
      "api/exact": "static file must not win\n",
      "index.html": "<h1>zero exact fallback active</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/exact",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/exact active",
          capabilities: { db: false, fetch: false, auth: false, env: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({
        mode: "website",
        site_title: "Zero Exact Fallback Active",
      }),
      production_hostnames: [activeHost],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // §5: the collision is decided at compile time — `api/exact` publishes a file
  // AND declares an endpoint, and the one response-table key that answers it
  // carries the Zero action, so the static bytes are unreachable by construction.
  const collidingKey = responseEntry(
    rt,
    "spc_zero_exact_fallback_active",
    "ver_zero_exact_fallback_active_1",
    "/api/exact",
  );
  expect(zeroAction(collidingKey)).toMatchObject({ endpoint: "GET /api/exact active" });
  expect(collidingKey?.[RESPONSES.entryKeys.blob]).toBeNull();

  const activeResponse = await get(rt, activeHost, "/api/exact");
  expect(activeResponse.status).toBe(201);
  expect(await activeResponse.json()).toMatchObject({
    endpointId: "GET /api/exact active",
    method: "GET",
    path: "/api/exact",
  });
});

test("a Zero action cannot borrow another endpoint id's indexed artifact", async () => {
  // The serve path validates the ROOT only and never re-validates entries
  // (§5/D4/D86), so a corrupted table entry is exactly the shape this defends
  // against: dispatch must follow the artifact the entry names.
  const root = versionRoot(rt, "spc_zero_runtime", "ver_zero_runtime_1");
  const tablePath = Object.values(responseTableFiles(rt, "spc_zero_runtime", "ver_zero_runtime_1"))
    .map((file) => path.join(root, file))
    .find((file) => readFileSync(file, "utf8").includes("'endpoint' => 'POST /api/status'"));
  if (tablePath === undefined) {
    throw new Error("no response table carries the POST /api/status Zero action");
  }
  const originalTable = readFileSync(tablePath);
  const artifactPath = `zero/endpoints/post_api_status_${sha256("POST\n/api/status\n0").slice(
    0,
    12,
  )}.json`;
  try {
    // Point the POST /api/status entry at another endpoint's id while keeping
    // its own artifact: the runner must dispatch the artifact the entry names,
    // never the artifact the borrowed id indexes.
    const tampered = originalTable
      .toString("utf8")
      .replace("'endpoint' => 'POST /api/status'", "'endpoint' => 'GET /api/items/:id'");
    expect(tampered).not.toBe(originalTable.toString("utf8"));
    writeFileSync(tablePath ?? "", tampered);

    const response = await get(rt, HOST, "/api/status", { method: "POST" });
    expect(response.status).toBe(201);
    expect(await response.json()).toMatchObject({
      endpointId: "GET /api/items/:id",
      artifactPath,
      method: "POST",
      path: "/api/status",
    });
  } finally {
    writeFileSync(tablePath ?? "", originalTable);
  }
});

test("resolves dynamic Zero routes and params before spawning the runner", async () => {
  const response = await get(rt, HOST, "/api/items/todo_123?debug=1", { method: "GET" });

  expect(response.status).toBe(201);
  expect(await response.json()).toMatchObject({
    ok: true,
    endpointId: "GET /api/items/:id",
    method: "GET",
    path: "/api/items/todo_123",
    params: { id: "todo_123" },
  });

  const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
  expect(envelope.endpointId).toBe("GET /api/items/:id");
  expect(envelope.request.params).toEqual({ id: "todo_123" });
  expect(envelope.context.schemaHash).toBe("sha256:dynamic");
});

test("dynamic Zero route buckets accept literal consecutive dots", async () => {
  const response = await get(rt, HOST, "/foo...bar/todo_456", { method: "GET" });

  expect(response.status).toBe(201);
  expect(await response.json()).toMatchObject({
    ok: true,
    endpointId: "GET /foo...bar/:id",
    method: "GET",
    path: "/foo...bar/todo_456",
    params: { id: "todo_456" },
  });
});

test("rejects methods that match a dynamic Zero path but not its method", async () => {
  const response = await get(rt, HOST, "/api/items/todo_123", { method: "POST" });

  expect(response.status).toBe(405);
  expect(await response.text()).toContain("Method Not Allowed");
});

test("rejects methods not declared by the Zero action", async () => {
  const response = await get(rt, HOST, "/api/status", { method: "GET" });

  expect(response.status).toBe(405);
  expect(await response.text()).toContain("Method Not Allowed");
});

test("keeps tenant variables in the envelope and the native runner environment platform-only", async () => {
  const host = "zero-env.test";
  await deploy(rt, {
    spaceId: "spc_zero_env",
    versionId: "ver_zero_env_1",
    metadata: { mode: "website", title: "Zero Env" },
    files: {
      "index.html": "<h1>zero env</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/env",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/env",
          capabilities: { db: false, env: true },
        },
      ],
    },
    zero: {
      variableValues: {
        APP_ALLOWED: "allowed-value",
        LD_PRELOAD: "/nonexistent/stattic-security-regression.so",
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Env" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, host, "/api/env");
  expect(response.status).toBe(201);
  expect(await response.json()).toMatchObject({
    env: {
      platform: null,
      nativeAllowed: null,
      nativePreload: null,
      envelopeAllowed: "allowed-value",
      db: "mysql://zero-runtime.test/db",
      dbSource: "provider",
      ambient: null,
    },
    variables: { APP_ALLOWED: "allowed-value" },
  });
});

test("an app-declared DATABASE_URL variable reaches the runner labeled application", async () => {
  const host = "zero-app-db.test";
  await deploy(rt, {
    spaceId: "spc_zero_app_db",
    versionId: "ver_zero_app_db_1",
    metadata: { mode: "website", title: "Zero App DB" },
    files: {
      "index.html": "<h1>zero app db</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/env",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/env",
          capabilities: { db: true, env: true },
        },
      ],
    },
    zero: {
      variableValues: { DATABASE_URL: "mysql://app.example/db" },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero App DB" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, host, "/api/env");
  expect(response.status).toBe(201);
  expect(await response.json()).toMatchObject({
    env: {
      db: "mysql://app.example/db",
      dbSource: "application",
      ambient: null,
    },
    variables: { DATABASE_URL: "mysql://app.example/db" },
  });
});

test("trusted provider DATABASE_URL provenance survives the finalized runtime artifact", async () => {
  const host = "zero-provider-db.test";
  await deploy(rt, {
    spaceId: "spc_zero_provider_db",
    versionId: "ver_zero_provider_db_1",
    metadata: { mode: "website", title: "Zero Provider DB" },
    files: {
      "index.html": "<h1>zero provider db</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/env",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/env",
          capabilities: { db: true, env: true },
        },
      ],
    },
    zero: {
      variableValues: { DATABASE_URL: "mysql://provider.internal/db" },
      databaseUrlSource: "provider",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Provider DB" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, host, "/api/env");
  expect(response.status).toBe(201);
  expect(await response.json()).toMatchObject({
    env: {
      db: "mysql://provider.internal/db",
      dbSource: "provider",
      ambient: null,
    },
    variables: { DATABASE_URL: "mysql://provider.internal/db" },
  });
});

test("platform-managed headers from the runner never reach the client", async () => {
  const host = "zero-spoofed-headers.test";
  await deploy(rt, {
    spaceId: "spc_zero_spoofed_headers",
    versionId: "ver_zero_spoofed_headers_1",
    metadata: { mode: "website", title: "Zero Spoofed Headers" },
    files: {
      "index.html": "<h1>zero spoofed headers</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/spoofed-headers",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/spoofed-headers",
          capabilities: { db: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Spoofed Headers" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, host, "/api/spoofed-headers", { redirect: "manual" });
  expect(response.status).toBe(201);
  // Innocent runner headers pass through the fail-closed boundary filter...
  expect(response.headers.get("x-app-ok")).toBe("yes");
  expect(response.headers.get("x-zero-runner")).toBe("fake");
  // ...while platform-managed and reserved-prefix names are dropped.
  expect(response.headers.get("x-spacefast-zero-runner-metrics")).toBeNull();
  expect(response.headers.get("x-stattic-internal")).toBeNull();
  expect(response.headers.get("set-cookie")).toBeNull();
  expect(response.headers.get("location")).toBeNull();
  // §16: the provider's own channel is not the tenant's to write. The runner
  // asked for `A8C-Edge-Cache: cache`; the engine is the only writer of that
  // header and its own no-store verdict opts this response out instead.
  expect(response.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(response.headers.get("x-ac")).toBeNull();
  expect(response.headers.get("x-sc")).toBeNull();
  expect(response.headers.get("x-nc")).toBeNull();
  // HSTS is provider-owned and rewritten upstream — the runtime never emits one.
  expect(response.headers.get("strict-transport-security")).toBeNull();
});

test("does not spawn the Zero runner for static or not-found requests", async () => {
  // §6: the visitor lane answers a static hit and a miss out of the compiled
  // table alone, so Zero code is never even loaded. The table says why: the only
  // keys carrying a `zero` action are the declared endpoints and the Zero
  // control routes — `/` carries none, and `/missing` is not a key at all.
  const entries = responseEntries(rt, "spc_zero_runtime", "ver_zero_runtime_1");
  expect(zeroAction(entries["/"] ?? null)).toBeNull();
  expect(entries["/"]?.[RESPONSES.entryKeys.blob]).toBe(sha256("<h1>zero runtime</h1>\n"));
  expect(entries["/missing"]).toBeUndefined();
  expect(zeroAction(entries["/api/status"] ?? null)).toMatchObject({
    endpoint: "POST /api/status",
  });

  rmSync(capturePath, { force: true });

  const response = await get(rt, HOST, "/");
  const missing = await get(rt, HOST, "/missing");

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>zero runtime</h1>\n");
  expect(missing.status).toBe(404);
  expect(existsSync(capturePath)).toBe(false);
});

test("does not spawn the Zero runner for redirects, headers, or canonical client pages", async () => {
  const host = "zero-runtime-nonzero.test";
  await deploy(rt, {
    spaceId: "spc_zero_nonzero",
    versionId: "ver_zero_nonzero_1",
    metadata: { mode: "website", title: "Zero Nonzero" },
    files: {
      "index.html": "<h1>zero nonzero</h1>\n",
      "_spacefast/pages/client.html": "<h1>client page</h1>\n",
      _redirects: "/old / 302\n",
      _headers: ["/", "  X-Static-Hot-Path: yes"].join("\n"),
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/zero",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/zero",
          schema_hash: "sha256:nonzero",
          capabilities: { db: false },
        },
      ],
      config: { index: "index.html" },
      pages: [
        {
          id: "page.client",
          path: "/client/route",
          render: "client",
          shell: "_spacefast/pages/client.html",
          params: [],
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Nonzero" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // Only the endpoint invokes the runner; client pages use their declared shell.
  const entries = responseEntries(rt, "spc_zero_nonzero", "ver_zero_nonzero_1");
  expect(zeroAction(entries["/api/zero"] ?? null)).toMatchObject({ endpoint: "GET /api/zero" });
  for (const key of ["/old", "/", "/_spacefast/pages/client.html"]) {
    expect({ key, zero: zeroAction(entries[key] ?? null) }).toEqual({ key, zero: null });
  }
  expect(entries["/old"]?.[RESPONSES.entryKeys.headers]).toMatchObject({ location: "/" });

  rmSync(capturePath, { force: true });

  const redirect = await get(rt, host, "/old");
  const staticWithHeader = await get(rt, host, "/");
  const fallback = await get(rt, host, "/client/route");
  const missing = await get(rt, host, "/missing");

  expect(redirect.status).toBe(302);
  expect(redirect.headers.get("location")).toBe("/");
  expect(staticWithHeader.status).toBe(200);
  expect(staticWithHeader.headers.get("x-static-hot-path")).toBe("yes");
  expect(await staticWithHeader.text()).toBe("<h1>zero nonzero</h1>\n");
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>client page</h1>\n");
  expect(missing.status).toBe(404);
  expect(existsSync(capturePath)).toBe(false);
});

test("runner and path declarations cannot put Zero responses in shared cache", async () => {
  const host = "zero-cache-policy.test";
  await deploy(rt, {
    spaceId: "spc_zero_cache",
    versionId: "ver_zero_cache_1",
    metadata: { mode: "website", title: "Zero Cache" },
    files: { "index.html": "<h1>zero cache</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/cached",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/cached",
          capabilities: { db: false },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/uncached",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/uncached",
          capabilities: { db: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Cache" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // The fake runner declares `cache-control: public, max-age=60` for
  // GET /api/cached. Zero receives request headers and resolved auth, while the
  // wp.cloud edge looks up host+path+query before PHP and ignores Cookie/Vary.
  // A response-time declaration therefore cannot safely opt this dynamic route
  // into shared cache.
  const declared = await get(rt, host, "/api/cached");
  expect(declared.status).toBe(201);
  expect(declared.headers.get("cache-control")).toBe("no-store");
  expect(declared.headers.get("cdn-cache-control")).toBeNull();
  expect(declared.headers.get("surrogate-control")).toBeNull();
  // §16: on the PHP lane a public Cache-Control alone buys nothing — the edge
  // stores a response only with the A8C opt-in too. The engine derives that
  // header from the policy it is about to send, so a no-store Zero response
  // states the opt-OUT and can never be stored even if the edge saw a public
  // policy from somewhere else.
  expect(declared.headers.get("a8c-edge-cache")).toBe("no-cache");

  // A cookie-bearing invocation keeps the stronger per-visitor boundary.
  const withCookie = await get(rt, host, "/api/cached", {
    headers: { cookie: "publisher_preference=whatever" },
  });
  expect(withCookie.status).toBe(201);
  expect(withCookie.headers.get("cache-control")).toBe("private, no-store");
  expect(withCookie.headers.get("a8c-edge-cache")).toBe("no-cache");

  // No declared policy has the same dynamic no-store boundary.
  const undeclared = await get(rt, host, "/api/uncached");
  expect(undeclared.status).toBe(201);
  expect(undeclared.headers.get("cache-control")).toBe("no-store");
  expect(undeclared.headers.get("cdn-cache-control")).toBeNull();
  expect(undeclared.headers.get("a8c-edge-cache")).toBe("no-cache");
});

test("an access-protected Zero endpoint pins private revalidation over a runner-declared policy", async () => {
  const key = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(key.publicKey);
  const jwk = key.publicKey.export({ format: "jwk" });
  const host = "zero-cache-private.test";
  await deploy(rt, {
    spaceId: "spc_zero_cache_private",
    versionId: "ver_zero_cache_private_1",
    metadata: { mode: "website", title: "Zero Cache Private" },
    files: { "index.html": "<h1>zero cache private</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/private-cached",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/private-cached",
          capabilities: { db: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Cache Private" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await putRoute(rt, "spc_zero_cache_private", "production", {
    version_id: "ver_zero_cache_private_1",
    config: {
      visitor_issuer: "spacefast-api",
      visitor_jwks: {
        keys: [
          {
            kty: "OKP",
            crv: "Ed25519",
            kid: issuer.kid,
            alg: "EdDSA",
            use: "sig",
            x: jwk.x ?? "",
          },
        ],
      },
      projection_generation: 1,
      authorization: {
        generation: 1,
        sessionVersion: 0,
        fence: "none",
        acquireUrl: "https://access.spacefast.test/acquire/zero-cache",
        accessPage: {
          displayName: "Zero Cache Private",
          accountUrl: null,
          connections: [],
          exchange: ACCESS_EXCHANGE,
        },
        spaceClaimed: true,
        grants: [
          {
            id: "grt_zero_cache_private",
            generation: 1,
            audience: {
              kind: "external",
              issuer: "spacefast-membership",
              subject: "mem_zero_cache",
            },
            resources: { include: ["/api/private-cached"], exclude: [] },
            capabilities: ["page.view"],
            constraints: {},
            target: { kind: "live" },
            source: { kind: "managed", reference: "test:zero-cache-private" },
          },
        ],
      },
    },
  });

  const now = Math.floor(Date.now() / 1000);
  const token = signEd25519Jwt(key.privateKey, issuer.kid, {
    sub: "member:mem_zero_cache",
    purpose: "handoff",
    grants: ["member:mem_zero_cache"],
    authorities: ["member:mem_zero_cache"],
    iss: "spacefast-api",
    aud: "spc_zero_cache_private",
    host,
    spaceId: "spc_zero_cache_private",
    generation: 1,
    sid: "2".repeat(64),
    iat: now,
    nbf: now,
    exp: now + 3600,
    jti: "jti_zero_cache_private",
  });
  const handoff = await postAccessCallback(rt, host, token, "/api/private-cached");
  expect(handoff.status).toBe(303);
  const sessionCookie = (handoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
  expect(sessionCookie).toMatch(/^spacefast_session(?:_dev)?=sfv2_[A-Za-z0-9_-]+\.[a-f0-9]{64}$/);

  // The fake runner declares `cache-control: public, max-age=60` for this
  // endpoint too — the access-protected path must discard it and pin the
  // never-shared-cache verdict.
  const response = await get(rt, host, "/api/private-cached", {
    headers: { cookie: sessionCookie, origin: `https://${host}` },
  });
  expect(response.status).toBe(201);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("vary")).toContain("Cookie");
  expect(response.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  expect(response.headers.get("cross-origin-resource-policy")).toBe("same-origin");
  expect(response.headers.get("content-security-policy")).toBe("frame-ancestors 'self'");
  expect(response.headers.get("access-control-allow-origin")).toBeNull();
  expect(response.headers.get("cdn-cache-control")).toBeNull();
  expect(response.headers.get("surrogate-control")).toBeNull();
  // §16: private never opts the edge in, whatever the runner declared.
  expect(response.headers.get("a8c-edge-cache")).toBe("no-cache");
});

test("declared Zero pages serve the shell while unknown paths use the normal 404", async () => {
  const finalizeResponse = await finalizeRaw(
    rt,
    "spc_zero_pages",
    "ver_zero_pages",
    {
      "_spacefast/pages/client.html": "<main>Page shell</main>",
      "pages/source.md": "---\nraw: true\n---\nPrivate source",
      "_spacefast/pages/documents/page.document.html": "<p>Document seed</p>",
      "asset.txt": "Asset",
      _redirects: "/about /moved 302!\n/issues/* /asset.txt 200\n",
    },
    {
      serving: {
        config: { index: "index.html" },
        pages: [
          {
            id: "page.document",
            render: "document",
            bindingId: "sync.pages.document",
            path: "/issues/new",
            params: [],
          },
          {
            id: "page.about",
            render: "client",
            shell: "_spacefast/pages/client.html",
            path: "/about",
            params: [],
          },
          {
            id: "page.issue",
            render: "client",
            shell: "_spacefast/pages/client.html",
            path: "/issues/:id",
            params: ["id"],
          },
          {
            id: "page.docs",
            render: "client",
            shell: "_spacefast/pages/client.html",
            path: "/docs/*slug",
            params: ["slug"],
          },
        ],
      },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website" }),
        production_hostnames: ["zero-pages.test"],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    },
  );
  expect(finalizeResponse.status).toBe(200);
  const receipt = runtimeVersionFinalizeResponseSchema.parse(await finalizeResponse.json());
  const pages = receipt.route_inventory?.routes.filter((route) => route.kind === "page");
  expect(pages?.map((route) => routeInventoryRecordV1Schema.parse(route).runtime)).toEqual([
    "php",
    "routing",
    "routing",
    "routing",
  ]);
  expect(versionMetadata(rt, "spc_zero_pages", "ver_zero_pages")?.diagnostics).toEqual(
    expect.arrayContaining([
      expect.objectContaining({ code: "page_redirect_overlap", path: "/about" }),
    ]),
  );
  const resolver = Bun.spawnSync([
    Bun.which("php") ?? "/usr/bin/php",
    "-r",
    `require $argv[1]; $paths = json_decode($argv[3], true); echo json_encode(array_map(fn($path) => _stattic_page_resolve($argv[2], $path), $paths));`,
    path.resolve(import.meta.dir, "../engine/runtime/zero.php"),
    versionRoot(rt, "spc_zero_pages", "ver_zero_pages"),
    JSON.stringify([
      "/issues/new",
      "/issues/%6eew",
      "/issues/123",
      "/docs/a/b",
      "/docs",
      "/issues/a%2fb",
    ]),
  ]);
  expect(resolver.exitCode).toBe(0);
  expect(
    JSON.parse(resolver.stdout.toString()).map((page: { id: string } | null) => page?.id ?? null),
  ).toEqual(["page.document", "page.document", "page.issue", "page.docs", null, null]);
  for (const path of ["/issues/123", "/docs/a/b"]) {
    const response = await get(rt, "zero-pages.test", path);
    expect(response.status).toBe(200);
    expect(await response.text()).toContain("<main>Page shell</main>");
  }
  expect((await get(rt, "zero-pages.test", "/pages/source.md")).status).toBe(404);
  expect(
    (await get(rt, "zero-pages.test", "/_spacefast/pages/documents/page.document.html")).status,
  ).toBe(404);
  expect((await get(rt, "zero-pages.test", "/docs")).status).toBe(404);
  expect((await get(rt, "zero-pages.test", "/")).status).toBe(404);
  expect((await get(rt, "zero-pages.test", "/issues/123", { method: "POST" })).status).toBe(405);
  expect((await get(rt, "zero-pages.test", "/unknown")).status).toBe(404);
  expect((await get(rt, "zero-pages.test", "/missing.js")).status).toBe(404);
  expect(await (await get(rt, "zero-pages.test", "/asset.txt")).text()).toBe("Asset");
  expect((await get(rt, "zero-pages.test", "/about")).status).toBe(302);
});

test("explicit page inventories replace the standalone Zero app fallback", async () => {
  for (const pages of [undefined, []]) {
    const suffix = pages === undefined ? "absent" : "empty";
    const host = `zero-api-only-${suffix}.test`;
    await deploy(rt, {
      spaceId: `spc_zero_api_only_${suffix}`,
      versionId: `ver_zero_api_only_${suffix}`,
      zero: {},
      metadata: { mode: "website" },
      files: { "index.html": "<main>Authored static home</main>" },
      serving: {
        config: { fallback: { path: "index.html", status: 200 } },
        pages,
      },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website" }),
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const deepLink = await get(rt, host, "/not-a-page");
    expect(deepLink.status).toBe(pages === undefined ? 200 : 404);
    if (pages === undefined)
      expect(await deepLink.text()).toBe("<main>Authored static home</main>");
    expect(await (await get(rt, host, "/")).text()).toBe("<main>Authored static home</main>");
  }
});
