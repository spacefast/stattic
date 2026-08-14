// The engine's OWN pages, on the schema-v4 runtime.
//
// v4 compiles every publisher-authored response at finalize (contracts §5): the
// nearest-404 chain lives at the reserved keys `\0404:<dir>`/`\0404`, listings
// and the single-file viewer are compiled listing entries, and there is no
// request-time selection of a publisher document any more. Those seams belong to
// routing.test.ts, and the protected-space cache policy belongs to
// access-rules.test.ts — neither is re-proven here.
//
// What is left is the class of response the PUBLISHER can never own: the
// platform fault pages (tombstones, undeployed), the fonts they embed, and the
// front door's uniform denial for the reserved namespace. Those exist precisely
// so a suspended or taken-down Space cannot answer with its own bytes, which is
// why they are tested against a Space that shipped documents of its own.
import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  api,
  deploy,
  finalizeRaw,
  get,
  publicAccessConfig,
  type Runtime,
  startRuntime,
} from "./harness.ts";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

// Documents the publisher shipped, in both places a version can carry HTML: the
// finalize-rendered page artifacts under the version root, and ordinary content.
// Every platform page below has to ignore all of it.
const PAGE_ARTIFACTS = {
  "page-denied":
    "<!doctype html><html><body><h1>Acme says no</h1><!--sf-runtime:denial:start--><p>Fallback denial</p><!--sf-runtime:denial:end--></body></html>",
  "page-404":
    "<!doctype html><html><body><h1>Acme lost it</h1><p><!--sf-runtime:request-path:start-->fallback<!--sf-runtime:request-path:end--></p></body></html>",
};

const SITE_FILES = {
  "index.html": "<!doctype html><html><body><h1>Acme home</h1></body></html>\n",
};

async function deploySite(spaceId: string, versionId: string, host: string): Promise<void> {
  await deploy(rt, {
    spaceId,
    versionId,
    files: SITE_FILES,
    pageArtifacts: PAGE_ARTIFACTS,
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
}

function tombstone(spaceId: string, body: Record<string, unknown>): Promise<Response> {
  return api(
    rt,
    "PUT",
    `/__spacefast/api.php/spaces/${spaceId}/tombstones`,
    "update_tombstones",
    { space_id: spaceId },
    body,
  );
}

test("a tombstoned space answers with the engine's page, never its own documents", async () => {
  const host = "pages-fault.test";
  await deploySite("spc_pages_fault", "ver_pages_fault_1", host);
  // The live version still serves right up to the takedown, so a leak below is
  // the tombstone failing to outrank it rather than an empty Space.
  expect(await (await get(rt, host, "/")).text()).toContain("Acme home");

  const suspended = await tombstone("spc_pages_fault", {
    hostnames: [host],
    reason: "tenant_suspended",
  });
  expect(suspended.status).toBe(200);

  const response = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  expect(response.status).toBe(402);
  const html = await response.text();
  expect(html).toContain("This space is paused");
  expect(html).toContain("Need help?");
  // Platform-owned page: the Spacefast wordmark and its fonts, not the tenant's
  // brand, and not the site-page footer line either.
  expect(html).toContain('@font-face{font-family:"Recoleta"');
  expect(html).toContain('@font-face{font-family:"Haskoy"');
  // Fonts load from the shared origins (one warm cache across every space
  // hostname), with the rendered faces preloaded ahead of the stylesheet.
  expect(html).toContain(
    'src:url("https://spacefast.com/assets/fonts/haskoy-latin-variable.woff2")',
  );
  expect(html).toContain(
    '<link rel="preload" href="https://wordpress.com/i/fonts/recoleta/400.woff2" as="font" type="font/woff2" crossorigin>',
  );
  expect(html).not.toContain("__spacefast/pages/fonts");
  expect(html).not.toContain("Best way to share what your agent made");
  expect(html).not.toContain("Acme");
});

test("the retired per-space font path takes the uniform private-namespace denial", async () => {
  const host = "pages-fonts.test";
  await deploySite("spc_pages_fonts", "ver_pages_fonts_1", host);

  const rejected = await get(rt, host, "/__spacefast/pages/fonts/haskoy-latin-variable.woff2");
  expect(rejected.status).toBe(403);
  expect(await rejected.text()).toBe("Forbidden.\n");
});

test("reserved access-path variants deny uniformly instead of falling through to content", async () => {
  const host = "pages-access-prefix.test";
  // Deliberately an OPEN space: everything here would serve if a near-miss
  // spelling fell through to the version, so nothing masks a fall-through.
  await deploySite("spc_pages_access_prefix", "ver_pages_access_prefix_1", host);

  for (const requestPath of ["/__spacefast/access/logout/", "/__spacefast/access/LOGOUT"]) {
    const response = await get(rt, host, requestPath, { headers: { Accept: "text/html" } });
    expect(response.status, requestPath).toBe(403);
    expect(response.headers.get("cache-control"), requestPath).toBe("no-store");
    expect(response.headers.get("vary"), requestPath).toContain("Cookie");
    expect(response.headers.get("x-robots-tag"), requestPath).toBe("noindex, nofollow");
    expect(response.headers.get("cross-origin-resource-policy"), requestPath).toBe("same-origin");
    expect(response.headers.get("content-security-policy"), requestPath).toBe(
      "frame-ancestors 'self'",
    );
    const body = await response.text();
    expect(body, requestPath).not.toContain("Acme home");
    expect(body, requestPath).not.toContain("Acme lost it");
  }
});

test("CSAM stays byte-identical to undeployed for every negotiated representation", async () => {
  const host = "pages-csam.test";
  await deploySite("spc_pages_csam", "ver_pages_csam_1", host);
  const takedown = await tombstone("spc_pages_csam", { hostnames: [host], category: "csam" });
  expect(takedown.status).toBe(200);

  // The CSAM page declares no-store, so it negotiates — which is exactly where a
  // difference would hide. Every representation has to match a host that was
  // never deployed at all, or the 503 itself tells a reporter the Space exists.
  await Promise.all(
    ["text/html", "application/json", "text/plain"].map(async (accept) => {
      const [csam, undeployed] = await Promise.all([
        get(rt, host, "/", { headers: { Accept: accept } }),
        get(rt, "never-deployed.test", "/", { headers: { Accept: accept } }),
      ]);
      expect(csam.status, accept).toBe(503);
      expect(csam.status, accept).toBe(undeployed.status);
      expect(csam.headers.get("x-robots-tag"), accept).toBeNull();
      expect(await csam.text(), accept).toBe(await undeployed.text());
    }),
  );
});

test("finalize rejects malformed page artifact keys", async () => {
  const response = await finalizeRaw(
    rt,
    "spc_pages_bad",
    "ver_pages_bad_1",
    { "index.html": "ok" },
    { page_artifacts: { "../escape": "<html></html>" } },
  );
  expect(response.status).toBe(422);
  expect((await response.json()).code).toBe("invalid_page_artifacts");
});
