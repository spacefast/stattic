import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  deploy,
  get,
  publicAccessConfig,
  responseEntry,
  sha256,
  startRuntime,
  type Runtime,
} from "./harness.ts";

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
      "docs/index.html": `<!doctype html><html><head><meta CONTENT="../og.png?size=large&amp;v=1#preview" PROPERTY="OG:IMAGE"><meta name="twitter:image" content="share.png"><meta property="og:image:url" content="https://cdn.example.test/remote.png"><meta name="twitter:image:src" content="//cdn.example.test/other.png"><script>const fake = '<meta property="og:image" content="fake.png">';</script></head><body>Docs${"tail".repeat(18000)}</body></html>`,
      "based/index.html":
        '<html><head><meta name="twitter:image" content="cover.png"><base href="https://cdn.example.test/images/"></head><body>Based</body></html>',
      "docs/share.png": { content: ogImage, contentType: "image/png" },
      "share.png": { content: ogImage, contentType: "image/png" },
      _redirects: "/tour /docs/index.html 200\n",
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
  // Scraper caches key on the URL, so og:image must carry the image's content
  // hash or unfurls pin to the first upload.
  expect(html).toContain(
    `<meta property="og:image" content="http://${META_HOST}/og.png?v=${sha256(ogImage).slice(0, 12)}">`,
  );
  expect(html).toContain(`<link rel="icon" href="/favicon.svg?v=${sha256(favicon).slice(0, 12)}">`);

  const docsResponse = await get(rt, META_HOST, "/docs/");
  expect(docsResponse.status).toBe(200);
  const docs = await docsResponse.text();
  expect(docs).toContain(`content="http://${META_HOST}/og.png?size=large&amp;v=1#preview"`);
  expect(docs).toContain(`content="http://${META_HOST}/docs/share.png"`);
  expect(docs).toContain('content="https://cdn.example.test/remote.png"');
  expect(docs).toContain('content="//cdn.example.test/other.png"');
  expect(docs).toContain(`const fake = '<meta property="og:image" content="fake.png">';`);
  expect(Number(docsResponse.headers.get("content-length"))).toBe(Buffer.byteLength(docs));
  const docsHead = await get(rt, META_HOST, "/docs/", { method: "HEAD" });
  expect(docsHead.headers.get("content-length")).toBe(docsResponse.headers.get("content-length"));
  const renderedEtag = docsResponse.headers.get("etag");
  expect(renderedEtag).toMatch(/^"[a-f0-9]{64}"$/);
  expect(docsHead.headers.get("etag")).toBe(renderedEtag);
  const unchanged = await get(rt, META_HOST, "/docs/", {
    headers: { "if-none-match": renderedEtag ?? "" },
  });
  expect(unchanged.status).toBe(304);
  expect(await unchanged.text()).toBe("");
  const slice = await get(rt, META_HOST, "/docs/", {
    headers: { range: "bytes=65530-65670", "if-range": renderedEtag ?? "" },
  });
  expect(slice.status).toBe(206);
  expect(await slice.text()).toBe(docs.slice(65530, 65671));
  expect(await docsHead.text()).toBe("");
  const compiledEtag = responseEntry(rt, "spc_platform_meta", "ver_platform_meta_1", "/docs/")?.et;
  if (!compiledEtag) throw new Error("missing compiled HTML validator");
  const stale = await get(rt, META_HOST, "/docs/", {
    headers: { "if-match": `"${compiledEtag}"` },
  });
  expect(stale.status).toBe(412);
  expect(stale.headers.get("cache-control")).toBe("private, no-store");
  expect(await stale.text()).toBe("");
  const existing = await get(rt, META_HOST, "/docs/", { headers: { "if-none-match": "*" } });
  expect(existing.status).toBe(304);
  expect(await existing.text()).toBe("");
  const unpublishedValidator = await get(rt, META_HOST, "/docs/", {
    headers: { "if-none-match": `"${compiledEtag}"` },
  });
  expect(unpublishedValidator.status).toBe(200);
  expect(await unpublishedValidator.text()).toBe(docs);
  const based = await (await get(rt, META_HOST, "/based/")).text();
  expect(based).toContain('content="https://cdn.example.test/images/cover.png"');
  const rewrittenResponse = await get(rt, META_HOST, "/tour");
  expect(rewrittenResponse.status).toBe(200);
  const rewritten = await rewrittenResponse.text();
  expect(rewritten).toContain(`content="http://${META_HOST}/docs/share.png"`);
});
