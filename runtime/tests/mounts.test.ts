import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import path from "node:path";
import { brotliCompressSync, gzipSync } from "node:zlib";

import {
  deploy,
  get,
  managementToken,
  putRoute,
  runtimeHttpPath,
  sha256,
  type Runtime,
  startRuntime,
} from "./harness.ts";

const HOST = "mounts.test";
const APEX_SPACE = "spc_mount_apex";
const APEX_VERSION = "ver_mount_apex_1";
const TARGET_SPACE = "spc_mount_docs";
const TARGET_VERSION_1 = "ver_mount_docs_1";
const TARGET_VERSION_2 = "ver_mount_docs_2";
const ASSET = "console.log('mounted');\n";
const ASSET_BR = brotliCompressSync(Buffer.from(ASSET));
const ASSET_GZIP = gzipSync(Buffer.from(ASSET));

let rt: Runtime;

function routeBody(targetVersionId: string) {
  return {
    version_id: APEX_VERSION,
    config: { mode: "website" },
    production_hostnames: [HOST],
    noindex_production_hostnames: [],
    version_hostnames: [],
    host_canonical_redirects: [],
    static_mount_routes: [
      {
        hostname: HOST,
        path_prefix: "/docs",
        target_space_id: TARGET_SPACE,
        target_version_id: targetVersionId,
      },
    ],
  };
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: TARGET_SPACE,
    versionId: TARGET_VERSION_1,
    files: {
      "index.html": "<h1>docs v1</h1>\n",
      "guide.html": "<h1>guide v1</h1>\n",
      "asset.js": ASSET,
      "asset.js.br": ASSET_BR,
      "asset.js.gz": ASSET_GZIP,
      "404.html": "<h1>mounted 404</h1>\n",
    },
    serving: {
      redirects_exact: {
        "/jump": [{ destination: "/guide.html", status: 302, action: "redirect", order: 1 }],
      },
      headers_exact: {
        "/asset.js": [
          {
            order: 1,
            operations: [{ kind: "set", name: "X-Mounted", value: "yes" }],
            headers: { "X-Mounted": "yes" },
          },
        ],
      },
    },
  });
  await deploy(rt, {
    spaceId: TARGET_SPACE,
    versionId: TARGET_VERSION_2,
    files: {
      "index.html": "<h1>docs v2</h1>\n",
      "404.html": "<h1>mounted 404 v2</h1>\n",
    },
  });
  await deploy(rt, {
    spaceId: APEX_SPACE,
    versionId: APEX_VERSION,
    files: {
      "index.html": "<h1>apex</h1>\n",
      "apex-won.html": "<h1>apex redirect target</h1>\n",
      "apex.js": ASSET,
      "apex.js.br": ASSET_BR,
      "apex.js.gz": ASSET_GZIP,
      "leak.html": "<h1>must not leak</h1>\n",
      "404.html": "<h1>apex 404</h1>\n",
    },
    serving: {
      redirects_exact: {
        "/docs/apex": [
          { destination: "/apex-won.html", status: 307, action: "redirect", order: 1 },
        ],
        "/enter-docs": [
          {
            destination: "/docs/guide.html",
            status: 200,
            action: "rewrite",
            force: true,
            order: 2,
          },
        ],
        "/enter-docs-as-404": [
          {
            destination: "/docs/guide.html",
            status: 404,
            action: "notFound",
            force: true,
            order: 3,
          },
        ],
      },
    },
    activate: { route_name: "production", ...routeBody(TARGET_VERSION_1) },
  });
});

afterAll(() => rt?.stop());

test("mount root canonicalizes and apex rewrites can select a mount", async () => {
  const slashless = await get(rt, HOST, "/docs");
  expect(slashless.status).toBe(308);
  expect(slashless.headers.get("location")).toBe("/docs/");

  const rewritten = await get(rt, HOST, "/enter-docs");
  expect(rewritten.status).toBe(200);
  expect(rewritten.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_1);
  expect(await rewritten.text()).toBe("<h1>guide v1</h1>\n");

  const rewritten404 = await get(rt, HOST, "/enter-docs-as-404");
  expect(rewritten404.status).toBe(404);
  expect(rewritten404.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_1);
  expect(await rewritten404.text()).toBe("<h1>guide v1</h1>\n");
});

test("static mount route targets are bound to the management token", async () => {
  const response = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(
      `/__spacefast/api.php/spaces/${APEX_SPACE}/routes/production`,
    )}`,
    {
      method: "PUT",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${managementToken("update_route", {
          space_id: APEX_SPACE,
          route_name: "production",
        })}`,
      },
      body: JSON.stringify(routeBody(TARGET_VERSION_2)),
    },
  );
  expect(response.status).toBe(403);
  expect(await response.json()).toEqual({
    error: {
      code: "runtime_scope_forbidden",
      message: "Runtime token is not scoped to these static mount routes.",
    },
  });
});

test("hostname intent static mounts are bound to the management token", async () => {
  const authorizedRoutes = routeBody(TARGET_VERSION_1).static_mount_routes;
  const alteredIntent = routeBody(TARGET_VERSION_2);
  const response = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${APEX_SPACE}/hostname-intent`)}`,
    {
      method: "PUT",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${managementToken("update_hostname_intent", {
          space_id: APEX_SPACE,
          static_mount_routes_sha256: sha256(JSON.stringify(authorizedRoutes)),
        })}`,
      },
      body: JSON.stringify(alteredIntent),
    },
  );
  expect(response.status).toBe(403);
  expect(await response.json()).toEqual({
    error: {
      code: "runtime_scope_forbidden",
      message: "Runtime token is not scoped to these static mount routes.",
    },
  });
});

test("apex redirects win while mounted redirects and headers use relative paths", async () => {
  const apexRedirect = await get(rt, HOST, "/docs/apex");
  expect(apexRedirect.status).toBe(307);
  expect(apexRedirect.headers.get("location")).toBe("/apex-won.html");

  const mountedRedirect = await get(rt, HOST, "/docs/jump?from=test");
  expect(mountedRedirect.status).toBe(302);
  expect(mountedRedirect.headers.get("location")).toBe("/docs/guide.html?from=test");

  const asset = await get(rt, HOST, "/docs/asset.js");
  expect(asset.status).toBe(200);
  expect(asset.headers.get("x-mounted")).toBe("yes");
});

test("mounted misses use the mounted real 404 without apex fallthrough", async () => {
  const response = await get(rt, HOST, "/docs/leak.html");
  expect(response.status).toBe(404);
  expect(await response.text()).toBe("<h1>mounted 404</h1>\n");
  expect(response.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_1);
});

test("shared static delivery negotiates compression quality and rejects identity", async () => {
  for (const requestPath of ["/apex.js", "/docs/asset.js"]) {
    const gzip = await get(rt, HOST, requestPath, {
      headers: { "accept-encoding": "br;q=0.4, gzip;q=0.9, identity;q=0.1" },
    });
    expect(gzip.status).toBe(200);
    expect(gzip.headers.get("content-encoding")).toBe("gzip");
    expect(gzip.headers.get("content-length")).toBe(String(ASSET_GZIP.length));

    const unacceptable = await get(rt, HOST, requestPath, {
      headers: { "accept-encoding": "br;q=0, gzip;q=0, identity;q=0" },
    });
    expect(unacceptable.status).toBe(406);
    expect(unacceptable.headers.get("vary")?.toLowerCase()).toContain("accept-encoding");
  }
});

test("validators, If-Range, ranges, HEAD, and lengths match through apex and mounts", async () => {
  for (const requestPath of ["/apex.js", "/docs/asset.js"]) {
    const identity = await get(rt, HOST, requestPath, {
      headers: { "accept-encoding": "identity" },
    });
    expect(identity.status).toBe(200);
    expect(identity.headers.get("content-length")).toBe(String(Buffer.byteLength(ASSET)));
    const etag = identity.headers.get("etag") ?? "";
    const lastModified = identity.headers.get("last-modified") ?? "";
    await identity.text();

    const conditional = await get(rt, HOST, requestPath, {
      headers: { "accept-encoding": "identity", "if-none-match": etag },
    });
    expect(conditional.status).toBe(304);

    const matchingRange = await get(rt, HOST, requestPath, {
      headers: { "accept-encoding": "identity", range: "bytes=0-6", "if-range": etag },
    });
    expect(matchingRange.status).toBe(206);
    expect(matchingRange.headers.get("content-range")).toMatch(/^bytes 0-6\//);
    expect(matchingRange.headers.get("content-length")).toBe("7");
    expect(await matchingRange.text()).toBe("console");

    const dateRange = await get(rt, HOST, requestPath, {
      headers: {
        "accept-encoding": "identity",
        range: "bytes=0-6",
        "if-range": lastModified,
      },
    });
    expect(dateRange.status).toBe(206);
    expect(await dateRange.text()).toBe("console");

    const staleRange = await get(rt, HOST, requestPath, {
      headers: {
        "accept-encoding": "identity",
        range: "bytes=0-6",
        "if-range": '"different"',
      },
    });
    expect(staleRange.status).toBe(200);
    expect(staleRange.headers.get("content-range")).toBeNull();
    expect(staleRange.headers.get("content-length")).toBe(String(Buffer.byteLength(ASSET)));
    expect(await staleRange.text()).toBe(ASSET);

    const head = await get(rt, HOST, requestPath, {
      method: "HEAD",
      headers: { "accept-encoding": "br" },
    });
    expect(head.status).toBe(200);
    expect(head.headers.get("content-encoding")).toBe("br");
    expect(head.headers.get("content-length")).toBe(String(ASSET_BR.length));
    expect(await head.text()).toBe("");
  }
});

test("promotion and rollback change mounted cache identity", async () => {
  const before = await get(rt, HOST, "/docs/");
  const beforeEtag = before.headers.get("etag");
  expect(before.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_1);
  await before.text();

  await putRoute(rt, APEX_SPACE, "production", routeBody(TARGET_VERSION_2));
  const promoted = await get(rt, HOST, "/docs/");
  expect(promoted.status).toBe(200);
  expect(promoted.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_2);
  expect(promoted.headers.get("etag")).not.toBe(beforeEtag);
  expect(await promoted.text()).toBe("<h1>docs v2</h1>\n");
  const routeEvents = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8")
    .trim()
    .split("\n")
    .map((line) => JSON.parse(line) as Record<string, unknown>)
    .filter(
      (event) =>
        event.event === "route_updated" &&
        event.space_id === APEX_SPACE &&
        event.version_id === APEX_VERSION,
    );
  expect(routeEvents.at(-1)?.hostnames).toContain(HOST);
  expect(routeEvents.at(-1)?.changed_paths_known).toBe(false);

  await putRoute(rt, APEX_SPACE, "production", routeBody(TARGET_VERSION_1));
  const rolledBack = await get(rt, HOST, "/docs/");
  expect(rolledBack.status).toBe(200);
  expect(rolledBack.headers.get("x-spacefast-version")).toBe(TARGET_VERSION_1);
  expect(await rolledBack.text()).toBe("<h1>docs v1</h1>\n");
});
