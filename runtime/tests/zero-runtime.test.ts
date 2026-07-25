import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync } from "node:crypto";
import {
  chmodSync,
  existsSync,
  mkdtempSync,
  readFileSync,
  renameSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  api,
  createDeclaredSession,
  deploy,
  finalizeRaw,
  get,
  putFile,
  putRoute,
  sha256,
  type Runtime,
  signEd25519Jwt,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

let rt: Runtime;
let runnerRoot: string;
let runnerPath: string;
let capturePath: string;

const HOST = "zero-runtime.test";

beforeAll(async () => {
  runnerRoot = mkdtempSync(path.join(os.tmpdir(), "stattic-zero-runner-test-"));
  runnerPath = path.join(runnerRoot, "fake-zero-runner.php");
  capturePath = path.join(runnerRoot, "capture.json");
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
        fwrite(STDERR, "compile args invalid");
        exit(2);
    }
    file_put_contents($generated, file_get_contents($source));
    file_put_contents($bytecode, "fake-bytecode");
    exit(0);
}
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
if (($envelope['endpointId'] ?? null) === 'GET /api/spoofed-headers') {
    // A compromised runner trying to smuggle platform-managed headers past
    // the PHP fail-closed boundary filter.
    $headers['X-Spacefast-Zero-Runner-Metrics'] = 'spoofed';
    $headers['x-stattic-internal'] = 'spoofed';
    $headers['Set-Cookie'] = 'evil=1';
    $headers['Location'] = '/pwned';
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
  chmodSync(runnerPath, 0o755);

  rt = await startRuntime({
    env: {
      SPACEFAST_ZERO_RUNNER: runnerPath,
      SPACEFAST_ZERO_RUNNER_CAPTURE: capturePath,
      SPACEFAST_ZERO_DATABASE_URL: "mysql://zero-runtime.test/db",
      SPACEFAST_PLATFORM_SECRET: "must-not-leak",
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_runtime",
    versionId: "ver_zero_runtime_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Runtime" },
    files: {
      "index.html": "<h1>zero runtime</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "POST",
          path: "/api/status",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "POST /api/status",
          schema_hash: "sha256:test",
          capabilities: { db: false },
        },
        {
          method: "GET",
          path: "/api/items/:id",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/items/:id",
          schema_hash: "sha256:test",
          capabilities: { db: false },
          db: { schemaHash: "sha256:dynamic" },
        },
        {
          method: "GET",
          path: "/api/default-capabilities",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/default-capabilities",
        },
        {
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
      config: { mode: "website", site_title: "Zero Runtime" },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => {
  rt?.stop();
  if (runnerRoot) {
    rmSync(runnerRoot, { recursive: true, force: true });
  }
});

test("invokes a finalized Zero lookup action through a fresh runner process", async () => {
  const response = await get(rt, HOST, "/api/status?debug=1", {
    method: "POST",
    headers: { "content-type": "application/json", "x-test-header": "present" },
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
  expect(envelope).not.toHaveProperty("filesRoot");
  expect(envelope.request).toMatchObject({
    method: "POST",
    path: "/api/status",
    uri: "/api/status?debug=1",
    host: HOST,
    query: "debug=1",
    params: {},
  });
  expect(envelope.request.headers["x-test-header"]).toBe("present");
  expect(Buffer.from(envelope.request.bodyBase64, "base64").toString("utf8")).toBe(
    JSON.stringify({ ping: true }),
  );
  expect(envelope.context).toMatchObject({
    spaceId: "spc_zero_runtime",
    versionId: "ver_zero_runtime_1",
    schemaHash: "sha256:test",
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

test("Zero identity uses the local access session (guest fallback, then commenter)", async () => {
  // Shoo is gone (access-plan X-2): Zero identity verifies the ONE visitor
  // session locally. The callback trades a signed handoff for that session;
  // user:/email: grants become the commenter and anything else is guest:local.
  const key = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(key.publicKey, ["user:", "email:"]);
  const host = "zero-identity.test";
  const idRuntime = await startRuntime({
    env: { SPACEFAST_ZERO_RUNNER: runnerPath, SPACEFAST_ZERO_RUNNER_CAPTURE: capturePath },
  });
  try {
    await deploy(idRuntime, {
      spaceId: "spc_zero_identity",
      versionId: "ver_zero_identity_1",
      zeroMode: "active",
      metadata: { mode: "website", title: "Zero Identity" },
      files: { "index.html": "<h1>zero identity</h1>\n" },
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/whoami",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/whoami",
            schema_hash: "sha256:identity",
            capabilities: { db: false },
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website", site_title: "Zero Identity" },
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    // Register the issuer via a policy rule that gates nothing served (issuers
    // are gathered from every rule; this one never matches /api/whoami).
    await putRoute(idRuntime, "spc_zero_identity", "production", {
      version_id: "ver_zero_identity_1",
      config: {
        policy: {
          rules: [
            {
              match: { pathPattern: "/__never/**" },
              effect: "challenge",
              auth: { requiredGrants: ["user:x"], issuers: [issuer] },
            },
          ],
        },
      },
    });

    rmSync(capturePath, { force: true });
    const guest = await get(idRuntime, host, "/api/whoami");
    expect(guest.status).toBe(201);
    let envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth).toMatchObject({
      userId: "guest:local",
      isGuest: true,
      isAuthenticated: false,
    });

    const now = Math.floor(Date.now() / 1000);
    const token = signEd25519Jwt(key.privateKey, issuer.kid, {
      sub: "user:usr_zero",
      grants: ["user:usr_zero", "email:zero@example.com"],
      aud: host,
      iat: now,
      exp: now + 3600,
      jti: "jti_zero_identity",
    });

    const handoff = await get(
      idRuntime,
      host,
      `/__spacefast/access-callback.php?token=${encodeURIComponent(token)}&return=${encodeURIComponent("/api/whoami")}`,
    );
    expect(handoff.status).toBe(303);
    expect(handoff.headers.get("location")).toBe("/api/whoami");
    const sessionCookie = (handoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
    expect(sessionCookie).toMatch(/^spacefast_access=sfv1_[a-f0-9]{64}$/);

    rmSync(capturePath, { force: true });
    const authed = await get(idRuntime, host, "/api/whoami", {
      headers: { cookie: sessionCookie },
    });
    expect(authed.status).toBe(201);
    envelope = JSON.parse(readFileSync(capturePath, "utf8"));
    expect(envelope.auth).toMatchObject({
      userId: "user:usr_zero",
      isGuest: false,
      isAuthenticated: true,
      email: "zero@example.com",
    });
  } finally {
    idRuntime.stop();
  }
});

test("exposes Zero runner metrics only when the metrics header is enabled", async () => {
  const metricsHost = "zero-metrics.test";
  const metricsRuntime = await startRuntime({
    env: {
      SPACEFAST_ZERO_RUNNER: runnerPath,
      SPACEFAST_ZERO_METRICS_HEADER: "1",
    },
  });
  try {
    await deploy(metricsRuntime, {
      spaceId: "spc_zero_metrics",
      versionId: "ver_zero_metrics_1",
      zeroMode: "active",
      metadata: { mode: "website", title: "Zero Metrics" },
      files: {
        "index.html": "<h1>zero metrics</h1>\n",
      },
      serving: {
        zero_endpoints: [
          {
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
        config: { mode: "website", site_title: "Zero Metrics" },
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
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_runtime",
    "versions",
    "ver_zero_runtime_1",
  );
  const exactSlug = `post_api_status_${sha256("POST\n/api/status\n0").slice(0, 12)}`;
  const defaultSlug = `get_api_default_capabilities_${sha256(
    "GET\n/api/default-capabilities\n2",
  ).slice(0, 12)}`;

  const partial = JSON.parse(
    readFileSync(path.join(versionRoot, `zero/endpoints/${exactSlug}.json`), "utf8"),
  );
  expect(partial.capabilities).toEqual({
    db: false,
    fetch: true,
    auth: true,
    env: true,
    realtime: true,
    logging: true,
  });

  const omitted = JSON.parse(
    readFileSync(path.join(versionRoot, `zero/endpoints/${defaultSlug}.json`), "utf8"),
  );
  expect(omitted.capabilities).toEqual({
    db: true,
    fetch: true,
    auth: true,
    env: true,
    realtime: true,
    logging: true,
  });
});

test("exact Zero requests are resolved from the PHP manifest before the legacy lookup", async () => {
  const host = "zero-manifest.test";
  await deploy(rt, {
    spaceId: "spc_zero_manifest",
    versionId: "ver_zero_manifest_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Manifest" },
    files: {
      "index.html": "<h1>zero manifest</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero Manifest" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const artifactPath = `zero/endpoints/post_api_manifest_${sha256("POST\n/api/manifest\n0").slice(
    0,
    12,
  )}.json`;
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_manifest",
    "versions",
    "ver_zero_manifest_1",
  );
  const manifestPath = path.join(versionRoot, "php-manifest.php");
  const routesPath = path.join(versionRoot, "zero", "routes.php");
  const parsedRoutes = Bun.spawnSync({
    cmd: [
      Bun.which("php") ?? "/usr/bin/php",
      "-r",
      "echo json_encode(require $argv[1]);",
      routesPath,
    ],
  });
  expect(parsedRoutes.exitCode).toBe(0);
  const zeroRoutes = JSON.parse(parsedRoutes.stdout.toString());
  expect(existsSync(manifestPath)).toBe(true);
  expect(zeroRoutes.exact).toContainEqual(
    expect.objectContaining({
      method: "POST",
      pattern: "/api/manifest",
      endpoint_id: "POST /api/manifest",
      artifact: artifactPath,
    }),
  );

  writeFileSync(
    manifestPath,
    `<?php
return [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_zero_manifest_1',
    'routes' => [
        [
            'action' => 'invoke_zero',
            'pattern' => '/api/manifest',
            'method' => 'POST',
            'endpointId' => 'POST /api/manifest from manifest',
            'zeroArtifact' => '${artifactPath}',
            'schemaHash' => 'sha256:manifest-from-php-manifest',
            'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false, 'realtime' => false],
        ],
    ],
];
`,
  );

  const response = await get(rt, host, "/api/manifest", { method: "POST" });
  expect(response.status).toBe(201);
  expect(await response.json()).toEqual({
    ok: true,
    endpointId: "POST /api/manifest from manifest",
    artifactPath,
    method: "POST",
    path: "/api/manifest",
    params: [],
  });

  const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
  expect(envelope.endpointId).toBe("POST /api/manifest from manifest");
  expect(envelope.artifactPath).toBe(artifactPath);
  expect(envelope.context.schemaHash).toBe("sha256:manifest-from-php-manifest");
});

test("native-compiled exact Zero routes beat colliding files without the PHP manifest", async () => {
  const activeHost = "zero-exact-fallback-active.test";
  const activeVersionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_exact_fallback_active",
    "versions",
    "ver_zero_exact_fallback_active_1",
  );
  await deploy(rt, {
    spaceId: "spc_zero_exact_fallback_active",
    versionId: "ver_zero_exact_fallback_active_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Exact Fallback Active" },
    files: {
      "api/exact": "static file must not win\n",
      "index.html": "<h1>zero exact fallback active</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero Exact Fallback Active" },
      production_hostnames: [activeHost],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const activatingHost = "zero-exact-fallback-activating.test";
  const activatingVersionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_exact_fallback_activating",
    "versions",
    "ver_zero_exact_fallback_activating_1",
  );
  await deploy(rt, {
    spaceId: "spc_zero_exact_fallback_activating",
    versionId: "ver_zero_exact_fallback_activating_1",
    zeroMode: "activating",
    metadata: { mode: "website", title: "Zero Exact Fallback Activating" },
    files: {
      "api/exact": "static file must not win\n",
      "index.html": "<h1>zero exact fallback activating</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/exact",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/exact activating",
          capabilities: { db: false, fetch: false, auth: false, env: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Exact Fallback Activating" },
      production_hostnames: [activatingHost],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const activeManifest = path.join(activeVersionRoot, "php-manifest.php");
  const activatingManifest = path.join(activatingVersionRoot, "php-manifest.php");
  const activeManifestBackup = `${activeManifest}.disabled`;
  const activatingManifestBackup = `${activatingManifest}.disabled`;
  renameSync(activeManifest, activeManifestBackup);
  renameSync(activatingManifest, activatingManifestBackup);
  try {
    const activeResponse = await get(rt, activeHost, "/api/exact");
    expect(activeResponse.status).toBe(201);
    expect(await activeResponse.json()).toMatchObject({
      endpointId: "GET /api/exact active",
      method: "GET",
      path: "/api/exact",
    });

    const activatingResponse = await get(rt, activatingHost, "/api/exact");
    expect(activatingResponse.status).toBe(503);
    expect(await activatingResponse.json()).toMatchObject({
      error: { code: "zero_activating" },
    });
  } finally {
    renameSync(activeManifestBackup, activeManifest);
    renameSync(activatingManifestBackup, activatingManifest);
  }
});

test("a Zero route cannot borrow another endpoint id's indexed artifact", async () => {
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_runtime",
    "versions",
    "ver_zero_runtime_1",
  );
  const manifestPath = path.join(versionRoot, "php-manifest.php");
  const originalManifest = readFileSync(manifestPath);
  const artifactPath = `zero/endpoints/post_api_status_${sha256("POST\n/api/status\n0").slice(
    0,
    12,
  )}.json`;
  try {
    writeFileSync(
      manifestPath,
      `<?php
return [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_zero_runtime_1',
    'routes' => [[
        'action' => 'invoke_zero',
        'pattern' => '/api/status',
        'method' => 'POST',
        'endpointId' => 'GET /api/items/:id',
        'zeroArtifact' => '${artifactPath}',
        'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false, 'realtime' => false],
    ]],
];
`,
    );

    const response = await get(rt, HOST, "/api/status", { method: "POST" });
    expect(response.status).toBe(201);
    expect(await response.json()).toMatchObject({
      endpointId: "GET /api/items/:id",
      artifactPath,
      method: "POST",
      path: "/api/status",
    });
  } finally {
    writeFileSync(manifestPath, originalManifest);
  }
});

test("native compiler rejects equal-score overlapping Zero patterns in either order", async () => {
  const endpoints = [
    {
      method: "GET",
      path: "/api/:left/alpha",
      source: "globalThis.__statticZeroResult = '{}';",
      endpoint_id: "endpoint.compiler.left",
    },
    {
      method: "GET",
      path: "/api/beta/:right",
      source: "globalThis.__statticZeroResult = '{}';",
      endpoint_id: "endpoint.compiler.right",
    },
  ];
  for (const reversed of [false, true]) {
    const suffix = reversed ? "reversed" : "forward";
    const response = await finalizeRaw(
      rt,
      `spc_zero_ambiguous_${suffix}`,
      `ver_zero_ambiguous_${suffix}_1`,
      { "index.html": "<h1>ambiguous</h1>\n" },
      {
        serving: { zero_endpoints: reversed ? endpoints.toReversed() : endpoints },
      },
    );
    expect(response.status).toBe(422);
    expect(await response.json()).toMatchObject({
      error: { code: "zero_endpoint_duplicate" },
    });
  }
});

test("dynamic Zero requests are resolved from the PHP manifest before zero-routes", async () => {
  const host = "zero-dynamic-manifest.test";
  await deploy(rt, {
    spaceId: "spc_zero_dynamic_manifest",
    versionId: "ver_zero_dynamic_manifest_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Dynamic Manifest" },
    files: {
      "index.html": "<h1>zero dynamic manifest</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/manifest-items/:id",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/manifest-items/:id",
          schema_hash: "sha256:dynamic-manifest",
          capabilities: { db: false, fetch: false, auth: false, env: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Dynamic Manifest" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const artifactPath = `zero/endpoints/get_api_manifest_items_id_${sha256(
    "GET\n/api/manifest-items/:id\n0",
  ).slice(0, 12)}.json`;
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_dynamic_manifest",
    "versions",
    "ver_zero_dynamic_manifest_1",
  );
  const manifestPath = path.join(versionRoot, "php-manifest.php");
  const generatedManifest = readFileSync(manifestPath, "utf8");
  expect(generatedManifest).toContain("'pattern' => '/api/manifest-items/:id'");
  expect(generatedManifest).toContain("'zeroArtifact' =>");

  writeFileSync(
    manifestPath,
    `<?php
return [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_zero_dynamic_manifest_1',
    'routes' => [
        [
            'action' => 'invoke_zero',
            'pattern' => '/api/manifest-items/:id',
            'method' => 'GET',
            'endpointId' => 'GET /api/manifest-items/:id from manifest',
            'zeroArtifact' => '${artifactPath}',
            'schemaHash' => 'sha256:dynamic-manifest-from-php-manifest',
            'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false, 'realtime' => false],
        ],
    ],
];
`,
  );

  const response = await get(rt, host, "/api/manifest-items/todo_456?debug=1", { method: "GET" });
  expect(response.status).toBe(201);
  expect(await response.json()).toEqual({
    ok: true,
    endpointId: "GET /api/manifest-items/:id from manifest",
    artifactPath,
    method: "GET",
    path: "/api/manifest-items/todo_456",
    params: { id: "todo_456" },
  });

  const envelope = JSON.parse(readFileSync(capturePath, "utf8"));
  expect(envelope.endpointId).toBe("GET /api/manifest-items/:id from manifest");
  expect(envelope.artifactPath).toBe(artifactPath);
  expect(envelope.request.params).toEqual({ id: "todo_456" });
  expect(envelope.context.schemaHash).toBe("sha256:dynamic-manifest-from-php-manifest");
});

test("dynamic Zero method mismatch is rejected from the PHP manifest before zero-routes", async () => {
  const host = "zero-dynamic-method-manifest.test";
  await deploy(rt, {
    spaceId: "spc_zero_dynamic_method_manifest",
    versionId: "ver_zero_dynamic_method_manifest_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Dynamic Method Manifest" },
    files: {
      "index.html": "<h1>zero dynamic method manifest</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/method-items/:id",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/method-items/:id",
          capabilities: { db: false, fetch: false, auth: false, env: false },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Dynamic Method Manifest" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_zero_dynamic_method_manifest",
    "versions",
    "ver_zero_dynamic_method_manifest_1",
  );
  renameSync(
    path.join(versionRoot, "zero/routes.php"),
    path.join(versionRoot, "zero/routes.php.disabled"),
  );

  const response = await get(rt, host, "/api/method-items/todo_789", { method: "POST" });
  expect(response.status).toBe(405);
  expect(await response.text()).toContain("Method Not Allowed");
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

test("keeps compiled Zero artifacts private", async () => {
  const exactSlug = `post_api_status_${sha256("POST\n/api/status\n0").slice(0, 12)}`;
  const dynamicSlug = `get_api_items_id_${sha256("GET\n/api/items/:id\n1").slice(0, 12)}`;
  for (const artifactPath of [
    `/zero/endpoints/${exactSlug}.json`,
    `/zero/endpoints/${dynamicSlug}.json`,
  ]) {
    const response = await get(rt, HOST, artifactPath);
    expect(response.status).toBe(404);
  }
});

test("finalize defaults Zero endpoints to activating when zero_mode is absent", async () => {
  const host = "zero-activating.test";
  const { uploadId, token } = await createDeclaredSession(
    rt,
    "spc_zero_activating",
    "ver_zero_activating_1",
    { "index.html": "<h1>activating</h1>\n" },
  );
  expect(await putFile(rt, uploadId, token, "index.html", "<h1>activating</h1>\n")).toHaveProperty(
    "status",
    200,
  );

  const finalized = await api(
    rt,
    "POST",
    "/__spacefast/api.php/spaces/spc_zero_activating/versions/ver_zero_activating_1/finalize",
    "finalize_version",
    { space_id: "spc_zero_activating", version_id: "ver_zero_activating_1" },
    {
      upload_id: uploadId,
      serving: {
        zero_endpoints: [
          {
            method: "GET",
            path: "/api/activating",
            source: "globalThis.__statticZeroResult = '{}';",
            endpoint_id: "GET /api/activating",
            capabilities: { db: false },
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "website", site_title: "Zero Activating" },
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    },
  );
  expect(finalized.status).toBe(200);
  expect(await finalized.json()).toMatchObject({ zero_endpoint_count: 1 });

  rmSync(capturePath, { force: true });
  const staticResponse = await get(rt, host, "/");
  expect(staticResponse.status).toBe(200);
  expect(await staticResponse.text()).toBe("<h1>activating</h1>\n");

  const zeroResponse = await get(rt, host, "/api/activating");
  expect(zeroResponse.status).toBe(503);
  expect(zeroResponse.headers.get("cache-control")).toBe("no-store");
  expect(await zeroResponse.json()).toMatchObject({
    error: { code: "zero_activating" },
  });
  expect(existsSync(capturePath)).toBe(false);
});

test("keeps tenant variables in the envelope and the native runner environment platform-only", async () => {
  const host = "zero-env.test";
  await deploy(rt, {
    spaceId: "spc_zero_env",
    versionId: "ver_zero_env_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Env" },
    files: {
      "index.html": "<h1>zero env</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero Env" },
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
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero App DB" },
    files: {
      "index.html": "<h1>zero app db</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero App DB" },
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

test("platform-managed headers from the runner never reach the client", async () => {
  const host = "zero-spoofed-headers.test";
  await deploy(rt, {
    spaceId: "spc_zero_spoofed_headers",
    versionId: "ver_zero_spoofed_headers_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Spoofed Headers" },
    files: {
      "index.html": "<h1>zero spoofed headers</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero Spoofed Headers" },
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
});

test("does not spawn the Zero runner for static or not-found requests", async () => {
  rmSync(capturePath, { force: true });

  const response = await get(rt, HOST, "/");
  const missing = await get(rt, HOST, "/missing");

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>zero runtime</h1>\n");
  expect(missing.status).toBe(404);
  expect(existsSync(capturePath)).toBe(false);
});

test("does not spawn the Zero runner for redirect, header, fallback, or proxy paths", async () => {
  const host = "zero-runtime-nonzero.test";
  await deploy(rt, {
    spaceId: "spc_zero_nonzero",
    versionId: "ver_zero_nonzero_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Nonzero" },
    files: {
      "index.html": "<h1>zero nonzero</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/zero",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/zero",
          schema_hash: "sha256:nonzero",
          capabilities: { db: false },
        },
      ],
      redirects_exact: {
        "/old": [{ destination: "/", status: 302, action: "redirect", order: 1 }],
        "/proxy-disabled": [
          {
            destination: "https://api.example.com/v1",
            status: 200,
            action: "proxy",
            disabled: true,
            disabledReason: "External proxying is not available on this plan.\n",
            order: 2,
          },
        ],
      },
      headers_exact: {
        "/": [
          {
            order: 1,
            operations: [{ kind: "set", name: "X-Static-Hot-Path", value: "yes" }],
            headers: { "X-Static-Hot-Path": "yes" },
          },
        ],
      },
      config: { fallback: { path: "index.html", status: 200 } },
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Nonzero" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  rmSync(capturePath, { force: true });

  const redirect = await get(rt, host, "/old");
  const staticWithHeader = await get(rt, host, "/");
  const fallback = await get(rt, host, "/client/route");
  const proxyDisabled = await get(rt, host, "/proxy-disabled");
  const missing = await get(rt, host, "/missing");

  expect(redirect.status).toBe(302);
  expect(redirect.headers.get("location")).toBe("/");
  expect(staticWithHeader.status).toBe(200);
  expect(staticWithHeader.headers.get("x-static-hot-path")).toBe("yes");
  expect(await staticWithHeader.text()).toBe("<h1>zero nonzero</h1>\n");
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>zero nonzero</h1>\n");
  expect(proxyDisabled.status).toBe(403);
  expect(await proxyDisabled.text()).toBe("External proxying is not available on this plan.\n");
  expect(missing.status).toBe(200);
  expect(await missing.text()).toBe("<h1>zero nonzero</h1>\n");
  expect(existsSync(capturePath)).toBe(false);
});

test("a runner-declared Cache-Control relays to the client; no declaration forces no-store", async () => {
  const host = "zero-cache-policy.test";
  await deploy(rt, {
    spaceId: "spc_zero_cache",
    versionId: "ver_zero_cache_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Cache" },
    files: { "index.html": "<h1>zero cache</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/cached",
          source: "globalThis.__statticZeroResult = '{}';",
          endpoint_id: "GET /api/cached",
          capabilities: { db: false },
        },
        {
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
      config: { mode: "website", site_title: "Zero Cache" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // The fake runner declares `cache-control: public, max-age=60` for
  // GET /api/cached only: the declared policy must reach the client and mirror
  // to the edge tier (CDN-Cache-Control / Surrogate-Control).
  const declared = await get(rt, host, "/api/cached");
  expect(declared.status).toBe(201);
  expect(declared.headers.get("cache-control")).toBe("public, max-age=60");
  expect(declared.headers.get("cdn-cache-control")).toBe("public, max-age=60");
  expect(declared.headers.get("surrogate-control")).toBe("public, max-age=60");

  // No declared policy: the dynamic response must carry an explicit no-store,
  // never an ambient cacheable default.
  const undeclared = await get(rt, host, "/api/uncached");
  expect(undeclared.status).toBe(201);
  expect(undeclared.headers.get("cache-control")).toBe("no-store");
  expect(undeclared.headers.get("cdn-cache-control")).toBe("no-store");
});

test("an access-protected Zero endpoint pins private, no-store over a runner-declared policy", async () => {
  const key = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(key.publicKey, ["user:", "email:"]);
  const host = "zero-cache-private.test";
  await deploy(rt, {
    spaceId: "spc_zero_cache_private",
    versionId: "ver_zero_cache_private_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Cache Private" },
    files: { "index.html": "<h1>zero cache private</h1>\n" },
    serving: {
      zero_endpoints: [
        {
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
      config: { mode: "website", site_title: "Zero Cache Private" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await putRoute(rt, "spc_zero_cache_private", "production", {
    version_id: "ver_zero_cache_private_1",
    config: {
      policy: {
        rules: [
          {
            match: { pathPattern: "/api/**" },
            effect: "challenge",
            auth: { requiredGrants: ["user:usr_zero_cache"], issuers: [issuer] },
          },
        ],
      },
    },
  });

  const now = Math.floor(Date.now() / 1000);
  const token = signEd25519Jwt(key.privateKey, issuer.kid, {
    sub: "user:usr_zero_cache",
    grants: ["user:usr_zero_cache"],
    aud: host,
    iat: now,
    exp: now + 3600,
    jti: "jti_zero_cache_private",
  });
  const handoff = await get(
    rt,
    host,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(token)}&return=${encodeURIComponent("/api/private-cached")}`,
  );
  expect(handoff.status).toBe(303);
  const sessionCookie = (handoff.headers.get("set-cookie") ?? "").split(";", 1)[0];
  expect(sessionCookie).toMatch(/^spacefast_access=sfv1_[a-f0-9]{64}$/);

  // The fake runner declares `cache-control: public, max-age=60` for this
  // endpoint too — the access-protected path must discard it and pin the
  // never-shared-cache verdict.
  const response = await get(rt, host, "/api/private-cached", {
    headers: { cookie: sessionCookie },
  });
  expect(response.status).toBe(201);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("cdn-cache-control")).toBe("private, no-store");
  expect(response.headers.get("surrogate-control")).toBe("private, no-store");
});
