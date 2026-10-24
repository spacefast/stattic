// How a Wrangler Worker shares its URL space with its `assets.directory`:
// `run_worker_first`, Cloudflare's SPA navigation rule, and the `env.ASSETS`
// read a worker makes through the relay. The edge host is unreachable here by
// design, so a 503 "Function execution is not available." is the proof that a
// request was dispatched rather than answered from disk.
import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  deploy,
  get,
  publicAccessConfig,
  type Runtime,
  RUNTIME_INSTANCE_ID,
  signToken,
  startRuntime,
} from "./harness.ts";

const ROUTED_HOST = "fx-assets-routed.test";
const SPA_HOST = "fx-assets-spa.test";
const ROUTED = { spaceId: "spc_fx_assets_routed", versionId: "ver_fx_assets_routed_1" };
const SPA = { spaceId: "spc_fx_assets_spa", versionId: "ver_fx_assets_spa_1" };

let rt: Runtime;

type AssetsConfig = {
  binding: string | null;
  runWorkerFirst: boolean | string[];
  navigationFallback: boolean;
};

function functionsConfig(assets: AssetsConfig) {
  return {
    artifact: {
      appName: "fx-assets",
      entry: "src/worker/index.js",
      mainModule: "index.js",
      compatibilityDate: "2025-11-24",
      compatibilityFlags: [],
      // A hand-written Worker claims every path the files do not answer.
      routes: [{ method: null, path: "/", subtree: true }],
      assets,
    },
    host: { hostname: "functions.invalid", bundleUrl: "https://example.test/bundle.json" },
    grantedCapabilities: ["storage.read", "storage.write"],
    relay: { url: `https://${ROUTED_HOST}/__spacefast/functions/relay`, token: "unused" },
  };
}

function activate(host: string) {
  return {
    route_name: "production",
    config: publicAccessConfig({ mode: "website", site_title: "assets" }),
    production_hostnames: [host],
    noindex_production_hostnames: [],
    version_hostnames: [],
  };
}

const FILES = {
  "index.html": "<h1>shell</h1>",
  "assets/app.3f9c2d1a.js": "void 0;",
  "api/static.txt": "asset under a worker-first path",
  "api/docs/intro.txt": "excluded from worker-first",
};

beforeAll(async () => {
  rt = await startRuntime({ atomicData: { SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "" } });
  await deploy(rt, {
    ...ROUTED,
    metadata: { mode: "website", title: "routed" },
    files: FILES,
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    functions: functionsConfig({
      binding: "ASSETS",
      runWorkerFirst: ["/api/*", "!/api/docs/*", "/mcp"],
      navigationFallback: false,
    }),
    activate: activate(ROUTED_HOST),
  });
  // An older version of the SPA Space that its hostname no longer serves.
  await deploy(rt, {
    spaceId: SPA.spaceId,
    versionId: "ver_fx_assets_spa_0",
    metadata: { mode: "website", title: "spa" },
    files: FILES,
    functions: functionsConfig({
      binding: "ASSETS",
      runWorkerFirst: false,
      navigationFallback: false,
    }),
  });
  await deploy(rt, {
    ...SPA,
    metadata: { mode: "website", title: "spa" },
    files: FILES,
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    functions: functionsConfig({ binding: null, runWorkerFirst: false, navigationFallback: true }),
    activate: activate(SPA_HOST),
  });
}, 120_000);

afterAll(async () => {
  await rt?.stop?.();
});

async function expectDispatched(response: Response, label: string) {
  expect(response.status, label).toBe(503);
  expect(await response.text(), label).toContain("Function execution is not available.");
}

test("run_worker_first puts the worker in front of matching files, and exclusions win", async () => {
  await expectDispatched(await get(rt, ROUTED_HOST, "/api/static.txt"), "/api/static.txt");
  await expectDispatched(await get(rt, ROUTED_HOST, "/mcp"), "/mcp");

  const excluded = await get(rt, ROUTED_HOST, "/api/docs/intro.txt");
  expect(excluded.status).toBe(200);
  expect(await excluded.text()).toBe("excluded from worker-first");

  // `/mcp` is exact: a glob only reaches below a path when it says `*`.
  const asset = await get(rt, ROUTED_HOST, "/assets/app.3f9c2d1a.js");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("void 0;");

  // A pattern list replaces the navigation rule: a navigation that misses
  // still reaches the worker, like Cloudflare's explicit routing.
  await expectDispatched(
    await get(rt, ROUTED_HOST, "/reading/42", { headers: { "Sec-Fetch-Mode": "navigate" } }),
    "routed navigation",
  );
});

test("an SPA navigation that misses gets the document; script fetches still reach the worker", async () => {
  const navigation = await get(rt, SPA_HOST, "/reading/42", {
    headers: { "Sec-Fetch-Mode": "navigate" },
  });
  expect(navigation.status).toBe(200);
  expect(await navigation.text()).toBe("<h1>shell</h1>");
  // The same URL answers differently by a header the shared cache cannot key on.
  expect(navigation.headers.get("cache-control")).toBe("no-store");
  expect(navigation.headers.get("a8c-edge-cache")).toBe("no-cache");

  await expectDispatched(await get(rt, SPA_HOST, "/reading/42"), "script fetch");
  await expectDispatched(
    await get(rt, SPA_HOST, "/api/things", { headers: { "Sec-Fetch-Mode": "cors" } }),
    "cors fetch",
  );
  // Files still win before either rule, and asset-looking misses are never
  // answered with the document.
  const file = await get(rt, SPA_HOST, "/api/static.txt", {
    headers: { "Sec-Fetch-Mode": "navigate" },
  });
  expect(await file.text()).toBe("asset under a worker-first path");
  await expectDispatched(
    await get(rt, SPA_HOST, "/missing.js", { headers: { "Sec-Fetch-Mode": "navigate" } }),
    "asset-looking navigation",
  );
});

function assetsToken(
  overrides: { space_id?: string; version_id?: string; capabilities?: string[] } = {},
): string {
  return signToken({
    aud: "spacefast-functions-relay",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: ROUTED.spaceId,
    version_id: ROUTED.versionId,
    capabilities: ["storage.read", "storage.write"],
    ...overrides,
  });
}

type AssetsFrame = {
  method?: string;
  path: string;
  host?: string;
  headers?: { [name: string]: string };
};

async function assetsRead(
  frame: AssetsFrame,
  token: string | null = assetsToken(),
  relayHost = ROUTED_HOST,
): Promise<Response> {
  const headers = new Headers({
    Connection: "close",
    host: relayHost,
    "content-type": "application/json",
    "sf-fx-broker": "assets",
  });
  if (token) headers.set("authorization", `Bearer ${token}`);
  return fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "POST",
    headers,
    body: JSON.stringify({ method: "GET", host: ROUTED_HOST, headers: {}, ...frame }),
  });
}

test("env.ASSETS reads the version's files without re-entering the worker", async () => {
  // The worker-first path answers with its file: the worker asked for it.
  const file = await assetsRead({ path: "/api/static.txt" });
  expect(file.status).toBe(200);
  expect(await file.text()).toBe("asset under a worker-first path");
  // The read travels as a POST to the relay URL, so no shared cache may keep it.
  expect(file.headers.get("cache-control")).toBe("private, no-store");
  expect(file.headers.get("a8c-edge-cache")).toBe("no-cache");

  // A miss takes the published not-found handling instead of the worker.
  const miss = await assetsRead({ path: "/reading/42?tab=cards" });
  expect(miss.status).toBe(200);
  expect(await miss.text()).toBe("<h1>shell</h1>");

  // Conditional and range headers mean what they mean for a visitor.
  const ranged = await assetsRead({
    path: "/assets/app.3f9c2d1a.js",
    headers: { range: "bytes=0-3" },
  });
  expect(ranged.status).toBe(206);
  expect(await ranged.text()).toBe("void");
});

test("env.ASSETS speaks only for its own version's files", async () => {
  // Platform paths are not assets, and the relay call's credential does not
  // survive into the request it carries.
  expect((await assetsRead({ path: "/__spacefast/functions/relay" })).status).toBe(404);
  // A hostname serving another Space is refused, whatever it is asked for.
  expect((await assetsRead({ path: "/index.html", host: SPA_HOST })).status).toBe(404);
  // So is a real version of the right Space that the hostname does not serve:
  // its worker reads its own files or none.
  const otherVersion = await assetsRead(
    { path: "/index.html", host: SPA_HOST },
    assetsToken({ space_id: SPA.spaceId, version_id: "ver_fx_assets_spa_0" }),
    SPA_HOST,
  );
  expect(otherVersion.status).toBe(404);
  // No read grant, no lane.
  expect(
    (await assetsRead({ path: "/index.html" }, assetsToken({ capabilities: ["db.read"] }))).status,
  ).toBe(403);
  expect((await assetsRead({ path: "/index.html" }, null)).status).toBe(401);
  // GET only: HEAD becomes a GET in the binding, because the answer rides a
  // POST whose body must match its Content-Length.
  for (const method of ["POST", "HEAD"]) {
    expect((await assetsRead({ method, path: "/index.html" })).status, method).toBe(400);
  }
  expect((await assetsRead({ path: "index.html" })).status).toBe(400);
});
