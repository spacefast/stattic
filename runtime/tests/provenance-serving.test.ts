// Provenance and readiness at the serving seam (contracts §5/§6, §14).
//
// Two claims live here, and nothing else:
//
//  * every response the visitor lane produces — object, redirect, 404, denial,
//    probe — carries `X-Spacefast-Runtime` and, once a version is selected,
//    `X-Spacefast-Version` (shared/context.php `_stattic_emit_runtime_identity`).
//    On a denial that pair is the only thing separating our access wall from an
//    upstream proxy's;
//  * the readiness target finalize hands back (`metadata.readinessTarget`, read
//    by admin/management.php) is a promise about what the serving lane will
//    answer for one path. This file is where that promise is checked against the
//    real response — the compiler's projection math itself is proven in
//    `crates/stattic-runtime-core/src/site_finalize.rs` unit tests and is not
//    restated here.
//
// Deliberately absent: Range. §15 deleted the PHP Range lane because the
// platform never delivers Range to the origin (§16, measured), so there is
// nothing left to prove locally. Conditional requests are the same story.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  deploy,
  errorCode,
  finalize,
  finalizeRaw,
  get,
  publicAccessConfig,
  putRoute,
  type Runtime,
  startRuntime,
  versionMetadata,
  versionRoot,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_provenance";
const VERSION = "ver_provenance_1";
const HOST = "provenance.test";
const VERSION_HOST = "v1--provenance.test";

const PROBE_SPACE = "spc_probe_redirect";
const PROBE_VERSION = "ver_probe_redirect_1";
const PROBE_HOST = "probe-redirect.test";

const PAGE = "<h1>public provenance</h1>\n";
const ASSET = new Uint8Array(Array.from({ length: 65_536 }, (_, index) => index % 251));

function expectProvenance(response: Response, versionId: string, label = ""): void {
  expect(response.headers.get("x-spacefast-runtime"), label).toBe("1");
  expect(response.headers.get("x-spacefast-version"), label).toBe(versionId);
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": PAGE,
      "assets/public.bin": ASSET,
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: VERSION }],
    },
  });

  // Everything on this host redirects, so it can only answer the probe by
  // terminating before route actions are consulted.
  await deploy(rt, {
    spaceId: PROBE_SPACE,
    versionId: PROBE_VERSION,
    files: {
      "index.html": "<h1>redirected apex</h1>\n",
      _redirects: "/* /elsewhere 302\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [PROBE_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("public objects carry exact provenance and bytes on every hostname", async () => {
  const page = await get(rt, HOST, "/");
  expect(page.status).toBe(200);
  expect(await page.text()).toBe(PAGE);
  expectProvenance(page, VERSION);

  // The bytes come out of the CAS through the compiled entry, so a whole-object
  // read is also the proof that the blob the table names is the blob served.
  for (const host of [HOST, VERSION_HOST]) {
    const asset = await get(rt, host, "/assets/public.bin");
    expect(asset.status, host).toBe(200);
    expectProvenance(asset, VERSION, host);
    expect(new Uint8Array(await asset.arrayBuffer()), host).toEqual(ASSET);
  }

  // HEAD advertises what the GET would send, without a body.
  const head = await get(rt, HOST, "/assets/public.bin", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(ASSET.byteLength));
  expectProvenance(head, VERSION);
  expect((await head.arrayBuffer()).byteLength).toBe(0);

  const proof = await get(rt, HOST, "/spacefast-readiness/*");
  expect(proof.status).toBe(404);
  expectProvenance(proof, VERSION);
});

test("the production probe answers before any route action, with runtime identity only", async () => {
  const redirected = await get(rt, PROBE_HOST, "/spacefast-readiness/*");
  expect(redirected.status).toBe(302);
  expect(redirected.headers.get("location")).toBe("/elsewhere");
  expectProvenance(redirected, PROBE_VERSION);

  const probe = await get(rt, PROBE_HOST, "/__stattic_probe", { method: "HEAD" });
  expect(probe.status).toBe(204);
  // The probe proves the route pointer itself, so it terminates before the host
  // entry and overlay are resolved: there is no selected version to name.
  expect(probe.headers.get("x-spacefast-runtime")).toBe("1");
  expect(probe.headers.get("x-spacefast-version")).toBeNull();
  expect((await probe.arrayBuffer()).byteLength).toBe(0);
});

type HealthBody = {
  ok: boolean;
  engine_revision: string;
  request_hostname: string;
  serving?: { state: string; version_match: boolean; edge_purge_pending: boolean };
};

async function health(host: string, query = ""): Promise<{ response: Response; body: HealthBody }> {
  const response = await get(rt, host, `/__spacefast/health.php${query}`);
  // SAFETY: Each test asserts the fields it reads.
  return { response, body: (await response.json()) as HealthBody };
}

test("health with a version says whether the hostname serves it, before any route action", async () => {
  // Everything on this host redirects, so health can only answer by stopping
  // before authored routing.
  const redirected = await get(rt, PROBE_HOST, "/");
  expect(redirected.status).toBe(302);
  expect(redirected.headers.get("location")).toBe("/elsewhere");

  const matched = await health(PROBE_HOST, `?v=${PROBE_VERSION}`);
  expect(matched.response.status).toBe(200);
  expect(matched.response.headers.get("cache-control")).toBe("no-store");
  // A yes/no for the version the caller names; health never names one itself.
  expect(matched.response.headers.get("x-spacefast-runtime")).toBe("1");
  expect(matched.response.headers.get("x-spacefast-version")).toBeNull();
  expect(matched.body).toMatchObject({ ok: true, request_hostname: PROBE_HOST });
  expect(matched.body.engine_revision).toEqual(expect.any(String));
  expect(matched.body.serving).toEqual({
    state: "serving",
    version_match: true,
    edge_purge_pending: false,
  });

  // Another version is a mismatch, not a failure: the edge may still be behind.
  const behind = await health(PROBE_HOST, `?v=${VERSION}`);
  expect(behind.response.status).toBe(200);
  expect(behind.body.serving).toMatchObject({ state: "serving", version_match: false });

  // A hostname no Space claims serves nothing.
  const unknown = await health("unclaimed.test", `?v=${VERSION}`);
  expect(unknown.response.status).toBe(200);
  expect(unknown.response.headers.get("x-spacefast-version")).toBeNull();
  expect(unknown.body.serving).toMatchObject({ state: "undeployed", version_match: false });

  const invalid = await get(rt, PROBE_HOST, "/__spacefast/health.php?v=..%2Fescape");
  expect(invalid.status).toBe(400);

  // A pointer schema this engine does not read fails every visitor request, so
  // health must not call the hostname served.
  const pointer = path.join(rt.storageRoot, "routes", "current.json");
  const original = readFileSync(pointer, "utf8");
  try {
    writeFileSync(pointer, JSON.stringify({ ...JSON.parse(original), schema: 999 }));
    expect((await get(rt, PROBE_HOST, "/")).status).toBe(500);
    const skewed = await health(PROBE_HOST, `?v=${PROBE_VERSION}`);
    expect(skewed.body.serving).toMatchObject({ state: "unavailable", version_match: false });
  } finally {
    writeFileSync(pointer, original);
  }
});

test("the control plane proves this engine's hostnames through health.php alone", async () => {
  // The control plane's readiness, unmodified, against this engine. Readiness
  // reaches hostnames over https; each request goes to this runtime with that
  // hostname as Host, so every answer is the engine's own.
  // The control plane validates its own config on import; it reads none here.
  process.env.AUTH_WPCOM_CLIENT_ID ??= "runtime-provenance-test";
  process.env.AUTH_WPCOM_CLIENT_SECRET ??= "runtime-provenance-test-secret";
  process.env.WP_CLOUD_CLIENT_NAME ??= "runtime-provenance-test";
  process.env.WP_CLOUD_API_TOKEN ??= "runtime-provenance-test-token";
  const { awaitRuntimeReadiness, runtimeHealthProbe } =
    await import("../../apps/control-plane/src/runtime/runtime-health.ts");
  const realFetch = globalThis.fetch;
  const requests: string[] = [];
  globalThis.fetch = Object.assign(
    async (input: Parameters<typeof fetch>[0], init?: RequestInit) => {
      const url = new URL(input instanceof Request ? input.url : String(input));
      requests.push(`${url.host}${url.pathname}`);
      const response = await realFetch(`${rt.baseUrl}${url.pathname}${url.search}`, {
        method: init?.method ?? "GET",
        redirect: "manual",
        headers: { Connection: "close", Host: url.host },
      });
      // No answer it read named a version in a header.
      expect(response.headers.get("x-spacefast-version")).toBeNull();
      return response;
    },
    { preconnect: realFetch.preconnect },
  );
  try {
    // A redirect-everything host and a version host, both proved by health.
    await awaitRuntimeReadiness(
      [
        runtimeHealthProbe({ hostname: PROBE_HOST, versionId: PROBE_VERSION, kind: "production" }),
        runtimeHealthProbe({ hostname: VERSION_HOST, versionId: VERSION, kind: "version" }),
      ],
      { budgetMs: 10_000 },
    );
    expect(requests.toSorted()).toEqual([
      `${PROBE_HOST}/__spacefast/health.php`,
      `${VERSION_HOST}/__spacefast/health.php`,
    ]);
    // A version the host does not serve is never ready.
    let refused = false;
    await awaitRuntimeReadiness(
      [runtimeHealthProbe({ hostname: PROBE_HOST, versionId: VERSION, kind: "production" })],
      { budgetMs: 300 },
    ).catch(() => {
      refused = true;
    });
    expect(refused).toBe(true);
    expect(requests.every((request) => request.endsWith("/__spacefast/health.php"))).toBe(true);
  } finally {
    globalThis.fetch = realFetch;
  }
});

test("health reports a hostname's edge purge until the provider accepts it", async () => {
  // The real queue, written and drained the way a serving mutation does. This
  // harness has no edge gateway, so the record is enqueued directly and a
  // gateway that accepts every purge then drains it.
  const gateway = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: () => Response.json({ message: "OK", data: [] }),
  });
  // Async: the gateway answers on this process's event loop.
  const purge = async (code: string) => {
    const php = Bun.spawn(
      [
        "php",
        "-r",
        [
          "define('ATOMIC_SITE_ID', '123');",
          "define('ATOMIC_SITE_API_KEY', 'test-purge-key');",
          "define('SPACEFAST_EDGE_CACHE_API_BASE', $argv[2]);",
          "require $argv[3] . '/shared/purge.php';",
          "$root = $argv[1];",
          code,
        ].join("\n"),
        rt.storageRoot,
        `http://127.0.0.1:${gateway.port}/api/v1.0`,
        rt.engineRoot,
      ],
      { stdout: "pipe", stderr: "pipe" },
    );
    const [stdout, stderr, exitCode] = await Promise.all([
      new Response(php.stdout).text(),
      new Response(php.stderr).text(),
      php.exited,
    ]);
    expect(exitCode, stderr).toBe(0);
    return stdout;
  };
  try {
    await purge(`_stattic_runtime_purge_enqueue($root, ['${PROBE_HOST}'], 'route_updated');`);
    const pending = await health(PROBE_HOST, `?v=${PROBE_VERSION}`);
    expect(pending.body.serving).toEqual({
      state: "serving",
      version_match: true,
      edge_purge_pending: true,
    });

    expect(
      await purge(
        `echo json_encode(_stattic_runtime_purge_drain($root, microtime(true) + 5, null, ['${PROBE_HOST}']));`,
      ),
    ).toBe("true");
    const settled = await health(PROBE_HOST, `?v=${PROBE_VERSION}`);
    expect(settled.body.serving).toMatchObject({ edge_purge_pending: false });
  } finally {
    await gateway.stop(true);
  }
});

test("health without a version loads no serving state", async () => {
  const plain = await health(PROBE_HOST);
  expect(plain.response.status).toBe(200);
  expect(plain.response.headers.get("x-spacefast-runtime")).toBe("1");
  expect(plain.response.headers.get("x-spacefast-version")).toBeNull();
  expect(plain.body.serving).toBeUndefined();
});

test("health proves a private Space's version without admitting the visitor", async () => {
  const spaceId = "spc_health_private";
  const versionId = "ver_health_private_1";
  const hostname = "health-private.test";
  await deploy(rt, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>private</h1>\n" },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [hostname],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const page = await get(rt, hostname, "/");
  expect([302, 401, 403]).toContain(page.status);

  const proved = await health(hostname, `?v=${versionId}`);
  expect(proved.response.status).toBe(200);
  expect(proved.body.serving).toMatchObject({ state: "serving", version_match: true });

  // Health reads the version root the way the visitor lane does: anything
  // short of a root with tables in this engine's schema is not served.
  const root = versionRoot(rt, spaceId, versionId);
  const rootPointer = path.join(root, "root.json");
  const written = readFileSync(rootPointer, "utf8");
  // SAFETY: root.json is the runtime's own pointer; the reads below fail if its shape changes.
  const artifact = path.join(root, (JSON.parse(written) as { root: string }).root);
  const compiled = readFileSync(artifact, "utf8");
  const asked = async (expected: string) => {
    const answer = await health(hostname, `?v=${versionId}`);
    expect(answer.response.status).toBe(200);
    expect(answer.body.serving).toMatchObject({ state: expected, version_match: false });
  };
  try {
    // Not written yet, or a pointer without a root: visitors get "version pending".
    rmSync(rootPointer);
    await asked("pending");
    writeFileSync(rootPointer, JSON.stringify({ root: 42 }));
    await asked("pending");
    writeFileSync(rootPointer, written);
    // A root compiled for a schema this engine can't read: visitors get the
    // invariant page, so health reports unavailable, still as JSON.
    writeFileSync(artifact, compiled.replace(/'schema' => \d+/, "'schema' => 999"));
    expect((await get(rt, hostname, "/")).status).toBeGreaterThanOrEqual(500);
    await asked("unavailable");
  } finally {
    writeFileSync(rootPointer, written);
    writeFileSync(artifact, compiled);
  }
  expect((await health(hostname, `?v=${versionId}`)).body.serving).toMatchObject({
    state: "serving",
  });

  // A Space overlay this engine can't read: JSON unavailable, never an error page.
  const spaceRoot = path.join(rt.storageRoot, "spaces", spaceId);
  const spacePointer = path.join(spaceRoot, "space.json");
  const overlayPath = path.join(
    spaceRoot,
    // SAFETY: space.json is the runtime's own pointer; the reads below fail if its shape changes.
    (JSON.parse(readFileSync(spacePointer, "utf8")) as { overlay: string }).overlay,
  );
  const overlay = readFileSync(overlayPath, "utf8");
  try {
    writeFileSync(overlayPath, overlay.replace(/'schema' => \d+/, "'schema' => 999"));
    await asked("unavailable");
  } finally {
    writeFileSync(overlayPath, overlay);
  }
});

test("health calls a Zero version pending while the visitor lane refuses it", async () => {
  const spaceId = "spc_health_zero";
  const hostname = "health-zero.test";
  const ask = async (versionId: string) => (await health(hostname, `?v=${versionId}`)).body.serving;
  const route = (versionId: string, zeroGeneration: string) =>
    putRoute(rt, spaceId, "production", {
      version_id: versionId,
      hostnames: [hostname],
      production_hostnames: [hostname],
      config: publicAccessConfig({ zero_generation: zeroGeneration }),
    });

  // A Zero 0.8 version once its Space activates v2: the cordon retires it.
  await deploy(rt, {
    spaceId,
    versionId: "ver_health_zero_legacy",
    files: { "index.html": "legacy\n" },
    zero: {},
  });
  await route("ver_health_zero_legacy", "legacy_0_8");
  expect(await ask("ver_health_zero_legacy")).toMatchObject({
    state: "serving",
    version_match: true,
  });
  await route("ver_health_zero_legacy", "v2");
  expect((await get(rt, hostname, "/")).status).toBe(410);
  expect(await ask("ver_health_zero_legacy")).toMatchObject({
    state: "pending",
    version_match: false,
  });

  // A compiled Zero v2 version is not live until initialized: its static
  // files serve, but every Zero request refuses it until `ready.json` exists.
  await deploy(rt, {
    spaceId,
    versionId: "ver_health_zero_v2",
    files: { "index.html": "compiled\n" },
  });
  const compiled = path.join(versionRoot(rt, spaceId, "ver_health_zero_v2"), ".zero-compiled");
  const ready = path.join(compiled, "ready.json");
  mkdirSync(compiled, { recursive: true });
  writeFileSync(path.join(compiled, "manifest.json"), "{}");
  writeFileSync(ready, JSON.stringify({ version_id: "ver_health_zero_v2" }));
  await route("ver_health_zero_v2", "v2");
  expect(await ask("ver_health_zero_v2")).toMatchObject({ state: "serving", version_match: true });
  rmSync(ready);
  expect(await ask("ver_health_zero_v2")).toMatchObject({ state: "pending", version_match: false });
});

test("every finalized readiness target names a status the serving lane answers", async () => {
  const rows = [
    {
      // The plain case: one public object, no rules, no access wall.
      suffix: "object",
      files: { "probe.txt": "probe\n" },
      open: true,
      target: { path: "/probe.txt", expected_statuses: [200, 302, 401, 403] },
      status: 200,
      location: null,
    },
    {
      // A redirect entry owns the selected path; the readiness set carries its
      // final status instead of the object's 200.
      suffix: "redirect",
      files: {
        "proof.html": "redirected proof\n",
        _redirects: "/proof.html /destination 303\n",
      },
      open: true,
      target: { path: "/proof.html", expected_statuses: [302, 303, 401, 403] },
      status: 303,
      location: "/destination",
    },
    {
      // A forced notFound makes the object unreachable at its own path.
      suffix: "not-found",
      files: {
        "probe.txt": "probe\n",
        _redirects: "/probe.txt /missing 404!\n",
      },
      open: true,
      target: { path: "/probe.txt", expected_statuses: [302, 401, 403, 404] },
      status: 404,
      location: null,
    },
    {
      // Access is mutable route state, not a finalized-version property: the
      // compiler admits 401/403 for a path it otherwise projects as 200, and a
      // closed overlay then answers exactly that.
      suffix: "denied",
      files: { "proof.html": "protected proof\n" },
      open: false,
      // 308 too: an admitted visitor's request for the page's own `.html` URL
      // moves to the URL it is served at.
      target: { path: "/proof.html", expected_statuses: [200, 302, 308, 401, 403] },
      status: 403,
      location: null,
    },
    {
      // A robots-only version can still publish a generated root listing.
      suffix: "robots-listing",
      files: { "robots.txt": "User-agent: *\nDisallow: /\n" },
      open: true,
      serving: { config: { listing: true } },
      target: { path: "/", expected_statuses: [200, 302, 401, 403] },
      status: 200,
      location: null,
    },
    {
      // No public object at all: readiness falls back to root, which the
      // nearest-404 chain answers.
      suffix: "no-object",
      files: { "sf.jsonc": "{}\n" },
      open: true,
      target: { path: "/", expected_statuses: [302, 401, 403, 404] },
      status: 404,
      location: null,
    },
  ] as const;

  for (const row of rows) {
    const spaceId = `spc_readiness_${row.suffix.replace(/-/g, "_")}`;
    const versionId = `ver_readiness_${row.suffix.replace(/-/g, "_")}`;
    const hostname = `${row.suffix}-readiness.test`;
    const finalized = await finalizeRaw(rt, spaceId, versionId, row.files, {
      serving: "serving" in row ? row.serving : undefined,
      activate: {
        route_name: "production",
        config: row.open
          ? publicAccessConfig({ mode: "website" }, "live_and_all_versions")
          : { mode: "website" },
        production_hostnames: [hostname],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const body = (await finalized.json()) as { readiness_target: { expected_statuses: number[] } };
    expect(finalized.status, `${row.suffix}: ${JSON.stringify(body)}`).toBe(200);
    expect(body.readiness_target, row.suffix).toEqual(row.target);

    const response = await get(rt, hostname, row.target.path, { method: "HEAD" });
    expect(response.status, row.suffix).toBe(row.status);
    expect(body.readiness_target.expected_statuses, row.suffix).toContain(response.status);
    expect(response.headers.get("location"), row.suffix).toBe(row.location);
    expectProvenance(response, versionId, row.suffix);
  }
});

test("a replayed finalize repeats the readiness target, and refuses to report an unusable one", async () => {
  const spaceId = "spc_readiness_replay";
  const versionId = "ver_readiness_replay";
  await deploy(rt, {
    spaceId,
    versionId,
    files: { "probe.txt": "probe\n" },
  });

  // The control plane retries finalize with a session the runtime has already
  // consumed; the replay must hand back the same target it probes against.
  const replay = await finalize(rt, spaceId, versionId, { upload_id: "upl_readiness_replay_gone" });
  expect(replay.status).toBe(200);
  expect(((await replay.json()) as { readiness_target: unknown }).readiness_target).toEqual({
    path: "/probe.txt",
    expected_statuses: [200, 302, 401, 403],
  });

  // A target the runtime cannot honor (418 is not a readiness status it can
  // ever answer with) is never reported as ready.
  const metadata = versionMetadata(rt, spaceId, versionId);
  expect(metadata).not.toBeNull();
  writeFileSync(
    path.join(versionRoot(rt, spaceId, versionId), "metadata.json"),
    JSON.stringify({
      ...metadata,
      readinessTarget: { path: "/probe.txt", expected_statuses: [418] },
    }),
  );

  const unusable = await finalize(rt, spaceId, versionId, {
    upload_id: "upl_readiness_replay_gone",
  });
  expect(unusable.status).toBe(500);
  expect(await errorCode(unusable)).toBe("runtime_readiness_target_missing");
});
