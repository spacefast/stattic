// The machine purge intake: POST /__spacefast/functions/purge
// (engine/runtime/functions-purge.php), authorized by the token the control
// plane mints into functions/config.json under `purge.token`. This suite plays
// the control plane: it writes the token into the finalized config on disk,
// then drives the route over HTTP like a worker would.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  deploy,
  edgePurgeCalls,
  get,
  publicAccessConfig,
  startRuntime,
  versionRoot,
  type Runtime,
} from "./harness";

const HOST = "functions-purge.test";
const SPACE_ID = "spc_fx_purge";
const VERSION_ID = "ver_fx_purge_1";
const RETIRED_TAG_HOST = "functions-purge-retired-tag.test";
const RETIRED_TAG_SPACE_ID = "spc_fx_purge_retired_tag";
const RETIRED_TAG_VERSION_ID = "ver_fx_purge_retired_tag_1";
const PURGE_PATH = "/__spacefast/functions/purge";
const PURGE_TOKEN = "sfpt_functions_purge_credential_0123456789";
const functionsConfigSchema = z
  .object({ purge: z.object({ token: z.string() }).nullable().optional() })
  .passthrough();

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime({ captureEdgePurges: true });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: VERSION_ID,
    metadata: { mode: "website", title: "Functions purge" },
    files: { "index.html": "<h1>purge</h1>" },
    functions: {
      artifact: {
        appName: "functions-purge",
        entry: "worker.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        routes: [{ method: null, path: "/api", subtree: true }],
      },
      host: { hostname: "functions.invalid", bundleUrl: "https://example.test/bundle.json" },
      grantedCapabilities: [],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Functions purge" }),
      production_hostnames: [HOST],
      version_hostnames: [],
    },
  });
  await deploy(rt, {
    spaceId: RETIRED_TAG_SPACE_ID,
    versionId: RETIRED_TAG_VERSION_ID,
    metadata: { mode: "website", title: "Functions purge retired tag" },
    files: { "index.html": "<h1>retired tag purge</h1>" },
    functions: {
      artifact: {
        appName: "functions-purge-retired-tag",
        entry: "worker.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        routes: [{ method: null, path: "/api", subtree: true }],
      },
      host: { hostname: "functions.invalid", bundleUrl: "https://example.test/bundle.json" },
      grantedCapabilities: [],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Functions purge retired tag" }),
      production_hostnames: [RETIRED_TAG_HOST],
      version_hostnames: [],
    },
  });
}, 30000);

afterAll(() => {
  rt?.stop();
});

function configPath(spaceId = SPACE_ID, versionId = VERSION_ID): string {
  return path.join(versionRoot(rt, spaceId, versionId), "functions", "config.json");
}

/** The control plane's mint, replayed onto the finalized artifact. */
function writePurgeToken(token: string | null, spaceId = SPACE_ID, versionId = VERSION_ID): void {
  const target = configPath(spaceId, versionId);
  const config = functionsConfigSchema.parse(JSON.parse(readFileSync(target, "utf8")));
  if (token === null) {
    delete config.purge;
  } else {
    config.purge = { token };
  }
  writeFileSync(target, `${JSON.stringify(config)}\n`);
}

function purge(
  body: unknown,
  token: string | null = PURGE_TOKEN,
  method = "POST",
  host = HOST,
): Promise<Response> {
  return get(rt, host, PURGE_PATH, {
    method,
    headers: {
      "content-type": "application/json",
      ...(token === null ? {} : { "sf-purge-token": token }),
    },
    ...(method === "POST" ? { body: JSON.stringify(body) } : {}),
  });
}

test("without a minted credential the route is indistinguishable from a 404", async () => {
  // The deploy wrote no `purge` block. A wrong token gets the same refusal, so
  // probing confirms nothing about a space's configuration.
  const unminted = await purge({ paths: ["/x"], tags: [] });
  expect(unminted.status).toBe(404);

  writePurgeToken(PURGE_TOKEN);
  const wrong = await purge({ paths: ["/x"], tags: [] }, "not-the-token");
  expect(wrong.status).toBe(404);
  expect(await wrong.text()).toBe(await unminted.text());

  const missing = await purge({ paths: ["/x"], tags: [] }, null);
  expect(missing.status).toBe(404);
});

test("the contract: POST only, bearer in sf-purge-token, 202 {accepted:true}", async () => {
  writePurgeToken(PURGE_TOKEN);
  const rejected = await purge(null, PURGE_TOKEN, "GET");
  expect(rejected.status).toBe(405);
  expect(rejected.headers.get("allow")).toBe("POST");

  const accepted = await purge({ paths: ["/blog/post.html"], tags: [] });
  expect(accepted.status).toBe(202);
  expect(await accepted.json()).toEqual({ accepted: true });

  // The accepted request drove a purge POST to the space's intent hostname over
  // the local edge-cache API. Every purge is whole-host: no purge_uris.
  const calls = edgePurgeCalls(rt).filter((call) => call.reason === "functions_purge");
  expect(calls.length).toBeGreaterThanOrEqual(1);
  expect(calls.at(-1)?.hostname).toBe(HOST);
  expect(calls.at(-1)?.uris).toEqual([]);
});

test("a malformed or empty purge names its refusal instead of purging nothing", async () => {
  writePurgeToken(PURGE_TOKEN);
  const invalid = await purge("not an object");
  expect(invalid.status).toBe(422);
  expect(((await invalid.json()) as { code?: string }).code).toBe("purge_invalid_body");

  const empty = await purge({ paths: [], tags: [] });
  expect(empty.status).toBe(422);
  expect(((await empty.json()) as { code?: string }).code).toBe("purge_empty");

  // Invalid entries are dropped fail-closed, so an all-invalid set is empty.
  const junk = await purge({ paths: ["no-leading-slash", 42], tags: [""] });
  expect(junk.status).toBe(422);
});

test("identical sets coalesce; distinct sets spend the per-space quota", async () => {
  writePurgeToken(PURGE_TOKEN);
  const before = edgePurgeCalls(rt).filter((r) => r.reason === "functions_purge").length;

  // The same set twice inside the coalesce window: both accepted, ONE purge.
  const first = await purge({ paths: ["/coalesce/a.css"], tags: [] });
  const repeat = await purge({ paths: ["/coalesce/a.css"], tags: [] });
  expect(first.status).toBe(202);
  expect(repeat.status).toBe(202);
  const afterCoalesce = edgePurgeCalls(rt).filter((r) => r.reason === "functions_purge").length;
  expect(afterCoalesce).toBe(before + 1);

  // Distinct sets each spend quota. Past the window ceiling the refusal is a
  // 429 that names when to come back, never a silent drop.
  let limited: Response | null = null;
  for (let i = 0; i < 12; i += 1) {
    const response = await purge({ paths: [`/quota/${i}.css`], tags: [] });
    if (response.status === 429) {
      limited = response;
      break;
    }
    expect(response.status).toBe(202);
  }
  expect(limited).not.toBeNull();
  expect(Number(limited?.headers.get("retry-after"))).toBeGreaterThan(0);
  expect(((await limited?.json()) as { code?: string }).code).toBe("purge_rate_limited");
});

test("tags are not part of the purge contract: a body carrying the retired field still purges", async () => {
  writePurgeToken(PURGE_TOKEN, RETIRED_TAG_SPACE_ID, RETIRED_TAG_VERSION_ID);
  // Tag invalidation lives in the worker's cache object, and OpenNext resolves
  // tags to concrete routes before minting the frame, so tags never reach the
  // CDN. A body still carrying the retired field purges as if it were absent.
  // Separate Space, so the quota test above cannot turn a missing purge into an
  // accepted 429 branch.
  const before = edgePurgeCalls(rt).length;
  const response = await purge(
    { paths: ["/tagged/asset.css"], tags: ["posts"] },
    PURGE_TOKEN,
    "POST",
    RETIRED_TAG_HOST,
  );
  expect(response.status).toBe(202);
  expect(edgePurgeCalls(rt).slice(before)).toEqual([
    { hostname: RETIRED_TAG_HOST, reason: "functions_purge", uris: [] },
  ]);
});
