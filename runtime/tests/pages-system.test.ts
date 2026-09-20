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
  "platform-partner-suspended":
    '<!doctype html><html><body><header>Partner Cloud</header><h1>This space is paused</h1><a href="https://partner.example/help">Need help?</a></body></html>',
  "platform-partner-undeployed":
    "<!doctype html><html><body><header>Partner Cloud</header><h1>Waiting for launch</h1></body></html>",
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

async function deployPartnerSite(spaceId: string, versionId: string, host: string): Promise<void> {
  await deploy(rt, {
    spaceId,
    versionId,
    files: SITE_FILES,
    pageArtifacts: PAGE_ARTIFACTS,
    serving: {
      config: {
        pages: {
          routes: {},
          previews: {},
          platform: {
            suspended: "platform-partner-suspended",
            undeployed: "platform-partner-undeployed",
          },
        },
      },
    },
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
  // Recoleta is the only downloaded face — body and mono are system stacks.
  expect(html).not.toContain("spacefast.com/assets/fonts");
  // It loads from the shared origin (one warm cache across every space
  // hostname), with the rendered weight preloaded ahead of the stylesheet.
  expect(html).toContain(
    '<link rel="preload" href="https://wordpress.com/i/fonts/recoleta/400.woff2" as="font" type="font/woff2" crossorigin>',
  );
  expect(html).not.toContain("__spacefast/pages/fonts");
  expect(html).not.toContain("Best way to share what your agent made");
  expect(html).not.toContain("Acme");

  // A per-principal suspension (`site_suspended`) serves the SAME 402 suspended
  // page as a tenant suspension: both differentiate to the suspended variant.
  const siteSuspended = await tombstone("spc_pages_fault", {
    hostnames: [host],
    reason: "site_suspended",
  });
  expect(siteSuspended.status).toBe(200);
  const perPrincipal = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  expect(perPrincipal.status).toBe(402);
  expect(await perPrincipal.text()).toContain("This space is paused");

  const archived = await tombstone("spc_pages_fault", { hostnames: [host], reason: "archived" });
  expect(archived.status).toBe(200);
  const archivedPage = await get(rt, host, "/");
  expect(archivedPage.status).toBe(404);
  expect(await archivedPage.text()).not.toContain("Acme home");
  const restored = await tombstone("spc_pages_fault", { hostnames: [host], mode: "remove" });
  expect(restored.status).toBe(200);
  const restoredPage = await get(rt, host, "/");
  expect(restoredPage.status).toBe(200);
  expect(await restoredPage.text()).toContain("Acme home");
});

test("a partner tombstone uses its compiled de-branded platform page", async () => {
  const host = "pages-partner-fault.test";
  await deployPartnerSite("spc_pages_partner_fault", "ver_pages_partner_fault_1", host);
  const suspended = await tombstone("spc_pages_partner_fault", {
    hostnames: [host],
    reason: "tenant_suspended",
  });
  expect(suspended.status).toBe(200);

  const response = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  expect(response.status).toBe(402);
  const html = await response.text();
  expect(html).toContain("Partner Cloud");
  expect(html).toContain("https://partner.example/help");
  expect(html).not.toContain("Spacefast");
  expect(html).not.toContain("spacefast.com");
  expect(html).not.toContain("Recoleta");
  expect(html).not.toContain("wordpress.com");
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
      "frame-ancestors 'none'",
    );
    const body = await response.text();
    expect(body, requestPath).not.toContain("Acme home");
    expect(body, requestPath).not.toContain("Acme lost it");
  }
});

test("CSAM stays byte-identical to undeployed for every negotiated representation", async () => {
  const host = "pages-csam.test";
  // Even a partner version carrying an undeployed artifact takes the neutral
  // built-in path for CSAM, so the response cannot reveal that the host existed.
  await deployPartnerSite("spc_pages_csam", "ver_pages_csam_1", host);
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

// The other de-branding lane: instead of compiling a replacement artifact per
// version, a white-label host sets ONE brand document and the built-in pages
// follow. This is the whole config lane end to end — the engine's config value
// reaches both the rendered page and the problem document it negotiates to.
test("a brand document rebrands the built-in pages a version can never own", async () => {
  const branded = await startRuntime({
    env: {
      SPACEFAST_BRAND_JSON: JSON.stringify({
        name: "Partner Cloud",
        url: "https://partner.example",
        helpUrl: "https://partner.example/help",
        tagline: "Ship what you made",
        problemDocsBaseUrl: "https://partner.example/errors",
        wordmarkUrl: "https://partner.example/logo.svg",
        fonts: [],
      }),
    },
  });
  try {
    // An unknown host takes the built-in undeployed page, the one response no
    // Space can supply an artifact for — so nothing but the document can brand it.
    const host = "pages-branded.test";
    const page = await get(branded, host, "/", { headers: { Accept: "text/html" } });
    expect(page.status).toBe(503);
    const html = await page.text();
    // The mark is the host's image, not the compiled-in Spacefast letterforms
    // relabelled — a wordmark URL is what makes a rebrand honest.
    expect(html).toContain(
      '<img class="sf-wordmark" src="https://partner.example/logo.svg" alt="Partner Cloud">',
    );
    expect(html).not.toContain('<svg class="sf-wordmark"');
    expect(html).toContain('href="https://partner.example/help"');
    expect(html).not.toContain("Spacefast");
    expect(html).not.toContain("spacefast.com");
    // Declaring no faces downloads none: no @font-face rule, no preload link.
    expect(html).not.toContain("@font-face");
    expect(html).not.toContain('rel="preload"');

    const problem = await get(branded, host, "/", { headers: { Accept: "application/json" } });
    expect(problem.headers.get("content-type")).toBe("application/problem+json; charset=utf-8");
    expect((await problem.json()).type).toBe("https://partner.example/errors/undeployed");
  } finally {
    branded.stop();
  }
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
