import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  deploy,
  get,
  putRoute,
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

test("declared icons select exact version bytes through runtime-safe URLs across promotion and rollback", async () => {
  const spaceId = "spc_declared_icons";
  const live = "icons.test";
  const hosts = ["icons--a.test", "icons--b.test"];
  const route = (version: string) => ({
    version_id: version,
    config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
    production_hostnames: [live],
    noindex_production_hostnames: [],
    version_hostnames: hosts.map((hostname, i) => ({
      hostname,
      version_id: `ver_icons_${i === 0 ? "a" : "b"}`,
    })),
  });
  const paths = [
    "favicon.ico",
    "apple-touch-icon.png",
    "apple-touch-icon-180x180.png",
    "apple-touch-icon-precomposed.png",
  ];
  for (const version of ["a", "b"]) {
    await deploy(rt, {
      spaceId,
      versionId: `ver_icons_${version}`,
      files: {
        "index.html": "<html><head></head><body>Implicit</body></html>",
        "docs/index.html":
          '<html><head><link rel="shortcut icon" href="../favicon.ico?x=1&amp;y=2#tab"><link rel="apple-touch-icon" href="../apple-touch-icon.png"><link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png"><link rel="apple-touch-icon-precomposed" href="/apple-touch-icon-precomposed.png"><link rel="icon" href="/other.png"><link rel="icon" href="https://cdn.example.test/favicon.ico"><link rel="icon" href="data:image/png;base64,AA=="></head><body>Explicit</body></html>',
        "other.png": { content: `other-${version}`, contentType: "image/png" },
        ...Object.fromEntries(
          paths.map((icon) => [
            icon,
            {
              content: `${icon}-${version}\n`,
              contentType: icon.endsWith(".ico") ? "image/x-icon" : "image/png",
            },
          ]),
        ),
      },
      activate: { route_name: "production", ...route(`ver_icons_${version}`) },
    });
  }
  // B is live, A is immutable; promotion/rollback changes only the live answer.
  for (const version of ["b", "a", "b"]) {
    await putRoute(rt, spaceId, "production", route(`ver_icons_${version}`));
    for (const [host, expected] of [
      [live, version],
      [hosts[0], "a"],
      [hosts[1], "b"],
    ]) {
      const implicit = await (await get(rt, host, "/")).text();
      const declared = implicit.match(/<link rel="icon" href="([^"]+)"/);
      if (!declared) throw new Error("missing compiled implicit icon declaration");
      expect(declared[1]).toBe(
        `/favicon.ico;sf-icon?v=${sha256(`favicon.ico-${expected}\n`).slice(0, 12)}`,
      );
      const explicit = await (await get(rt, host, "/docs/")).text();
      expect(explicit).toContain('href="/favicon.ico;sf-icon?x=1&amp;y=2#tab"');
      expect(explicit).toContain('href="/other.png"');
      expect(explicit).toContain('href="https://cdn.example.test/favicon.ico"');
      expect(explicit).toContain('href="data:image/png;base64,AA=="');
      for (const icon of paths) {
        const url = icon === "favicon.ico" ? declared[1] : `/${icon};sf-icon`;
        if (icon !== "favicon.ico") expect(explicit).toContain(`href="${url}"`);
        const response = await get(rt, host, url);
        expect(response.status).toBe(200);
        expect(await response.text()).toBe(`${icon}-${expected}\n`);
        expect(response.headers.get("x-spacefast-version")).toBe(`ver_icons_${expected}`);
        // The accel fallback owns its validator format; the alias retains it.
        expect(response.headers.get("etag")).toMatch(/^"[a-f0-9-]+"$/);
        expect(response.headers.get("content-type")).toBe(
          icon.endsWith(".ico") ? "image/x-icon" : "image/png",
        );
        expect(response.headers.get("content-length")).toBe(
          String(Buffer.byteLength(`${icon}-${expected}\n`)),
        );
        expect(response.headers.get("x-content-type-options")).toBe("nosniff");
        const repeated = await get(rt, host, url);
        expect(await repeated.text()).toBe(`${icon}-${expected}\n`);
        const head = await get(rt, host, url, { method: "HEAD" });
        expect(head.status).toBe(200);
        expect(await head.text()).toBe("");
        for (const header of [
          "etag",
          "content-type",
          "content-length",
          "cache-control",
          "x-content-type-options",
        ]) {
          expect(head.headers.get(header)).toBe(response.headers.get(header));
          expect(repeated.headers.get(header)).toBe(response.headers.get(header));
        }
      }
      expect(await (await get(rt, host, "/other.png")).text()).toBe(`other-${expected}`);
    }
  }
  // Reserved suffix syntax must not become a general file alias.
  for (const invalid of [
    "/other.png;sf-icon",
    "/docs/favicon.ico;sf-icon",
    "/favicon.ico;sf-icon/extra",
    "/favicon.ico;sf-icon;sf-icon",
    "/apple-touch-icon-a;b.png;sf-icon",
  ]) {
    const response = await get(rt, live, invalid);
    expect(response.status).toBe(404);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  }
});

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
