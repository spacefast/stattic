import { afterAll, beforeAll, expect, test } from "bun:test";

import { deploy, get, publicAccessConfig, sha256, startRuntime, type Runtime } from "./harness.ts";

const HOST = "html-inject.test";
const META_HOST = "platform-meta.test";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
}, 30000);

afterAll(() => rt?.stop());

test("runtime splices static inject placements into served HTML", async () => {
  await deploy(rt, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_1",
    files: {
      "index.html":
        '<!doctype html><html><head><meta charset="utf-8"></head><body class="home"><h1>Home</h1></body></html>\n',
    },
    serving: {
      config: {
        index: "index.html",
        listing: false,
        viewer: false,
        fallback: null,
        inject: {
          head: ['<meta name="spacefast-head" content="ok">'],
          bodyStart: ['<div id="spacefast-body-start"></div>'],
          bodyEnd: ["<script>window.spacefastBodyEnd = true;</script>"],
          noscript: ['<noscript><iframe src="https://example.test/ns"></iframe></noscript>'],
        },
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(rt, HOST, "/");
  expect(response.status).toBe(200);
  const html = await response.text();

  expect(html).toContain('<meta name="spacefast-head" content="ok">');
  expect(html).toContain("<noscript>");
  expect(html).toContain('id="spacefast-body-start"');
  expect(html).toContain("window.spacefastBodyEnd = true");

  const bodyOpen = html.indexOf('<body class="home">');
  const noscript = html.indexOf("<!-- spacefast:noscript -->");
  const bodyStart = html.indexOf("<!-- spacefast:body-start -->");
  const heading = html.indexOf("<h1>Home</h1>");
  const bodyEnd = html.indexOf("<!-- spacefast:body-end -->");
  const bodyClose = html.indexOf("</body>");

  expect(bodyOpen).toBeGreaterThanOrEqual(0);
  expect(noscript).toBeGreaterThan(bodyOpen);
  expect(bodyStart).toBeGreaterThan(noscript);
  expect(heading).toBeGreaterThan(bodyStart);
  expect(bodyEnd).toBeGreaterThan(heading);
  expect(bodyClose).toBeGreaterThan(bodyEnd);
});

test("platform_meta renders the space's meta into <head> and cache-busts local assets", async () => {
  const ogImage = "fake-png-bytes\n";
  const favicon = "fake-svg-bytes\n";
  await deploy(rt, {
    spaceId: "spc_platform_meta",
    versionId: "ver_platform_meta_1",
    files: {
      "index.html": "<!doctype html><html><head></head><body><h1>Plain site</h1></body></html>\n",
      "og.png": { content: ogImage, contentType: "image/png" },
      "favicon.svg": { content: favicon, contentType: "image/svg+xml" },
    },
    serving: {
      config: {
        index: "index.html",
        listing: false,
        viewer: false,
        fallback: null,
        meta: {
          title: "Platform title",
          description: "Platform description",
          image: "/og.png",
          favicon: "/favicon.svg",
        },
        platform_meta: true,
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [META_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const html = await (await get(rt, META_HOST, "/")).text();

  expect(html).toContain("<title>Platform title</title>");
  expect(html).toContain('<meta name="description" content="Platform description">');
  expect(html).toContain('<meta property="og:title" content="Platform title">');
  expect(html).toContain('<meta property="og:description" content="Platform description">');
  expect(html).toContain('<meta name="twitter:card" content="summary_large_image">');
  // Scraper caches key on the URL, so the emitted og:image has to carry the
  // image's own content hash -- otherwise unfurls pin to the first upload.
  expect(html).toContain(
    `<meta property="og:image" content="/og.png?v=${sha256(ogImage).slice(0, 12)}">`,
  );
  expect(html).toContain(`<link rel="icon" href="/favicon.svg?v=${sha256(favicon).slice(0, 12)}">`);
});
