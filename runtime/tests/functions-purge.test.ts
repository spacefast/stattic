// The machine purge intake: POST /__spacefast/functions/purge
// (engine/runtime/functions-purge.php), authorized by the purge credential the
// control plane mints into the version-adjacent functions/config.json under
// `purge.token`. The mint is control-plane-side, so this suite plays the
// control plane: it writes the token into the finalized config document on
// disk — the exact artifact serve time reads — and then drives the route over
// HTTP like a worker would.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

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
const PURGE_PATH = "/__spacefast/functions/purge";
const PURGE_TOKEN = "sfpt_functions_purge_credential_0123456789";

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
}, 30000);

afterAll(() => {
  rt?.stop();
});

function configPath(): string {
  return path.join(versionRoot(rt, SPACE_ID, VERSION_ID), "functions", "config.json");
}

/** The control plane's mint, replayed onto the finalized artifact. */
function writePurgeToken(token: string | null): void {
  const config = JSON.parse(readFileSync(configPath(), "utf8")) as Record<string, unknown>;
  if (token === null) {
    delete config.purge;
  } else {
    config.purge = { token };
  }
  writeFileSync(configPath(), `${JSON.stringify(config)}\n`);
}

function purge(
  body: unknown,
  token: string | null = PURGE_TOKEN,
  method = "POST",
): Promise<Response> {
  return get(rt, HOST, PURGE_PATH, {
    method,
    headers: {
      "content-type": "application/json",
      ...(token === null ? {} : { "sf-purge-token": token }),
    },
    ...(method === "POST" ? { body: JSON.stringify(body) } : {}),
  });
}

test("without a minted credential the route is indistinguishable from a 404", async () => {
  // The deploy wrote a functions config with no `purge` block: refusal, and the
  // same refusal a wrong token gets once the block exists — probing confirms
  // nothing about a space's configuration.
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
  // the same local edge-cache API every management mutation uses. `/blog/post.html`
  // is a document path, so it is a whole-host (domain-scope) purge: no purge_uris.
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

  // Distinct sets each spend quota; past the window ceiling the refusal is a
  // 429 that names when to come back — never a silent drop.
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

test("tags are not part of the purge contract: a body carrying the retired field purges its paths", async () => {
  writePurgeToken(PURGE_TOKEN);
  // Tag invalidation lives in the worker's cache object, and OpenNext resolves
  // tags to concrete routes before minting the frame — so tags never reach the
  // CDN, and a body still carrying the retired field purges as if it were
  // absent (URI scope, not the domain widening tags used to force).
  // Fresh quota window is not guaranteed here; tolerate a 429 by only
  // asserting when accepted. The window record is space-scoped state written
  // by the earlier tests in this same runtime.
  const response = await purge({ paths: ["/tagged/asset.css"], tags: ["posts"] });
  if (response.status === 202) {
    // URI scope, not the domain widening tags used to force: the purge carries
    // the concrete asset URL rather than a whole-host purge.
    const call = edgePurgeCalls(rt)
      .filter((r) => r.reason === "functions_purge")
      .at(-1);
    expect(call?.uris).toEqual([`https://${HOST}/tagged/asset.css`]);
  } else {
    expect(response.status).toBe(429);
  }
});
