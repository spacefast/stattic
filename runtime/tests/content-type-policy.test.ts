// Generic serve-time content-type allowlist (route-config `content_types`):
// the engine refuses files whose stored Content-Type matches no pattern —
// exact types or `prefix/*` wildcards — with the pushed blocked_message. The
// control plane uses it for unclaimed anonymous spaces (no opaque binaries
// pre-claim); the engine itself is policy-blind, and a null push clears the
// restriction (what a claim's route sync does).
import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  deploy,
  get,
  publicAccessConfig,
  putRoute,
  type Runtime,
  startRuntime,
} from "./harness.ts";

let rt: Runtime;

const SITE = "anon.test";
const SPACE = "spc_anon_ct";
const VERSION = "ver_anon_ct_1";

// The list the control plane pushes for unclaimed anonymous spaces
// (packages/common publish-policy ANONYMOUS_SERVE_CONTENT_TYPES).
const ANON_ALLOWED = [
  "text/*",
  "image/*",
  "font/*",
  "application/json",
  "application/manifest+json",
  "application/javascript",
  "application/gzip",
  "application/xml",
  "application/rss+xml",
  "application/atom+xml",
];
const BLOCKED_MESSAGE =
  "This file type isn't served on unclaimed spaces. Claim the space to serve it.";

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    metadata: { mode: "website", title: "Anon Content Types" },
    files: {
      "index.html": "<h1>anon</h1>\n",
      "docs/index.html": "<h1>anon docs</h1>\n",
      "assets/app.js": "console.log('anon');\n",
      "assets/logo.png": Buffer.from([0x89, 0x50, 0x4e, 0x47]),
      // .bin has no mime mapping: stored as application/octet-stream.
      "downloads/payload.bin": Buffer.from([0x4d, 0x5a, 0x90, 0x00]),
      // Extensionless files store as application/octet-stream too.
      "downloads/tool": Buffer.from([0x7f, 0x45, 0x4c, 0x46]),
      // Gzip payloads (pagefind search fragments) stay serveable.
      "pagefind/index/en.pf_index": Buffer.from([0x1f, 0x8b, 0x08, 0x00, 0x01]),
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({
        mode: "website",
        content_types: { allowed: ANON_ALLOWED, blocked_message: BLOCKED_MESSAGE },
      }),
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("web content types keep serving under the allowlist", async () => {
  for (const [path, contentType] of [
    ["/index.html", "text/html; charset=utf-8"],
    ["/assets/app.js", "text/javascript; charset=utf-8"],
    ["/assets/logo.png", "image/png"],
    ["/pagefind/index/en.pf_index", "application/gzip"],
  ] as const) {
    const response = await get(rt, SITE, path);
    expect(response.status).toBe(200);
    expect(response.headers.get("content-type")).toBe(contentType);
  }

  const directory = await get(rt, SITE, "/docs/");
  expect(directory.status).toBe(200);
  expect(await directory.text()).toBe("<h1>anon docs</h1>\n");
  const canonical = await get(rt, SITE, "/docs");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("/docs/");
});

test("octet-stream payloads are refused with the pushed message", async () => {
  for (const path of ["/downloads/payload.bin", "/downloads/tool"]) {
    const response = await get(rt, SITE, path);
    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("no-store");
    expect(response.headers.get("x-robots-tag")).toBe("noindex, nofollow");
    expect(await response.text()).toBe(`${BLOCKED_MESSAGE}\n`);
  }
});

test("a null content_types push clears the restriction (the claim transition)", async () => {
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: publicAccessConfig({ content_types: null }),
  });
  const response = await get(rt, SITE, "/downloads/payload.bin");
  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toBe("application/octet-stream");
});
