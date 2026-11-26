import { afterAll, beforeAll, expect, test } from "bun:test";
import {
  chmodSync,
  cpSync,
  existsSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";
import { brotliCompressSync, gzipSync } from "node:zlib";

import { z } from "zod";

import {
  api,
  apiJson,
  blobGateToken,
  createDeclaredSession,
  deploy,
  edgePurgeCalls,
  errorCode,
  finalize,
  finalizeRaw,
  get,
  healthServing,
  getBlob,
  publicAccessConfig,
  putRoute,
  RESPONSES,
  responseTableFiles,
  RUNTIME_HTTP_API_BASE,
  RUNTIME_TEST_ATOMIC_PREPEND,
  RUNTIME_TEST_ROUTER,
  type PhpServer,
  sha256,
  storagePath,
  type Runtime,
  startPhpServer,
  startRuntime,
  uploadSessionBlobs,
  versionRootArtifact,
} from "./harness.ts";

let rt: Runtime;
let redirectReceiver: ReturnType<typeof Bun.serve>;

function redirectReceiverUrl(pathname: string): string {
  return new URL(pathname, redirectReceiver.url).toString();
}

const SITE = "site.test";
const WWW = "www.site.test";
const VERSION_HOST = "site--v1.test";

const INDEX = "<h1>home</h1>\n";
const ROOT_404 = "<h1>root 404</h1>\n";
const BLOG_404 = "<h1>blog 404</h1>\n";
const POST = "<h1>post</h1>\n";
const APP_JS = "console.log('app');\n";
const APP_JS_GZIP = gzipSync(Buffer.from(APP_JS));
const APP_JS_BROTLI = brotliCompressSync(Buffer.from(APP_JS));
const GUIDE_HTML = "<h1>browser guide</h1>\n";
const GUIDE_MD = "---\nraw: true\n---\n# agent guide\n";
const GUIDE_MD_GZIP = gzipSync(Buffer.from(GUIDE_MD));
const DRAFT_MD = "---\ndraft: true\n---\n# Private draft\n";
const GZIP_PAYLOAD = Buffer.from([
  0x1f, 0x8b, 0x08, 0x00, 0x70, 0x61, 0x79, 0x6c, 0x6f, 0x61, 0x64,
]);

const BROWSER_HEADERS = {
  accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
  "user-agent":
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
};
const AGENT_HEADERS = { accept: "*/*", "user-agent": "curl/8.5.0" };

beforeAll(async () => {
  redirectReceiver = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: async (request) => Response.json({ method: request.method, body: await request.text() }),
  });
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_site",
    versionId: "ver_site_1",
    metadata: { mode: "website", title: "Routing" },
    files: {
      "index.html": INDEX,
      "about.html": "<h1>about</h1>\n",
      "loop-page.html": "<h1>loop page</h1>\n",
      "café.html": "<h1>café</h1>\n",
      "404.html": ROOT_404,
      "docs/index.html": "<h1>docs</h1>\n",
      "zero/index.html": "<h1>zero</h1>\n",
      "zero/notes.txt": "zero notes\n",
      "blog/404.html": BLOG_404,
      "blog/post.html": POST,
      "assets/app.js": APP_JS,
      "movie.mp4": "0123456789".repeat(100200),
      "empty.txt": "",
      "assets/app.js.gz": APP_JS_GZIP,
      "assets/app.js.br": APP_JS_BROTLI,
      "pagefind/index.pf_index": GZIP_PAYLOAD,
      "yield/keep.txt": "kept\n",
      "guide.html": GUIDE_HTML,
      "guide.md": GUIDE_MD,
      "guide.md.gz": GUIDE_MD_GZIP,
      "draft.md": DRAFT_MD,
      "draft.md.gz": gzipSync(Buffer.from(DRAFT_MD)),
      "inert.php": "<?php echo 'never executed';\n",
      _headers: [
        "/inert.php",
        "  Content-Type: text/html; charset=utf-8",
        "  ! Content-Disposition",
      ].join("\n"),
      _HEADERS: "ordinary uppercase file\n",
      "SF.JSONC": '{ "private": true }\n',
      "SF.JSONC.gz": gzipSync(Buffer.from('{ "private": true }\n')),
      "public/sf.jsonc": '{ "private": "nested" }\n',
      "docs/_headers": "/*\n  X-Nested: private\n",
      "server/index.ts": "export const secret = 'server source';\n",
      "functions/hello.ts": "export const secret = 'function source';\n",
      "server/about.html": "<h1>server page</h1>\n",
      ".well-known/security.txt": "Contact: mailto:security@site.test\n",
      _redirects: [
        "/old /about.html 301",
        "/loop-page /loop-page.html 301",
        "/found /about.html 302",
        "/docs-redirect /docs/ 302",
        "/about.html /index.html 200!",
        "/unicode-nfd /café.html 200!",
        "/unicode-encoded /caf%C3%A9.html 200!",
        "/yield/* /index.html 200",
        "/gone/* /blog/404.html 404",
        "/guide/* /guide.md 200! Agent=true",
        "/guide/* /guide.html 200!",
        `/preserve/* ${redirectReceiverUrl("/receiver")} 307`,
        "/escape/* /:splat 302",
        "/campaign utm_source=:source /landing/:source 302",
      ].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        { mode: "website", site_title: "Routing" },
        "live_and_all_versions",
      ),
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: "ver_site_1" }],
      host_canonical_redirects: [
        { from: WWW, to: "https://site.test", status: 308 },
        { from: "renamed.test", to: "https://site.test", status: 307 },
        { from: "redirect-domain.test", to: "https://destination.example/base", status: 302 },
      ],
    },
  });
});
afterAll(() => {
  rt?.stop();
  redirectReceiver?.stop(true);
});

test("a version publishes one content-addressed root over a single response table", () => {
  const root = versionRootArtifact(rt, "spc_site", "ver_site_1");
  expect(root?.schema).toBe(RESPONSES.schema);
  const tables = responseTableFiles(rt, "spc_site", "ver_site_1");
  expect(Object.keys(tables)).toEqual([RESPONSES.tableSingleKey]);
  expect(tables[RESPONSES.tableSingleKey]).toStartWith(`${RESPONSES.tableBasename}-`);
});

test("a published path serves its compiled entry and precomputed validator", async () => {
  const response = await get(rt, SITE, "/assets/app.js");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe(APP_JS);
  expect(response.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
  expect(response.headers.get("etag")).toBe(`"${sha256(APP_JS)}"`);
  expect(response.headers.get("x-content-type-options")).toBe("nosniff");
  expect(await healthServing(rt, SITE, "ver_site_1")).toMatchObject({ version_match: true });
  expect(response.headers.get("content-encoding")).toBeNull();
});

test("compressed uploads are served as the bytes they are, never as an encoding", async () => {
  const sidecar = await get(rt, SITE, "/assets/app.js.gz");
  expect(sidecar.status).toBe(200);
  expect(sidecar.headers.get("content-type")).toBe("application/gzip");
  expect(sidecar.headers.get("content-encoding")).toBeNull();
  expect(new Uint8Array(await sidecar.arrayBuffer())).toEqual(new Uint8Array(APP_JS_GZIP));

  const brotli = await get(rt, SITE, "/assets/app.js.br");
  expect(brotli.status).toBe(200);
  expect(brotli.headers.get("content-encoding")).toBeNull();
  expect(new Uint8Array(await brotli.arrayBuffer())).toEqual(new Uint8Array(APP_JS_BROTLI));

  const rawMarkdown = await get(rt, SITE, "/guide.md.gz");
  expect(rawMarkdown.status).toBe(200);
  expect(new Uint8Array(await rawMarkdown.arrayBuffer())).toEqual(new Uint8Array(GUIDE_MD_GZIP));

  const source = await get(rt, SITE, "/assets/app.js", {
    headers: { "accept-encoding": "br, gzip" },
  });
  expect(source.headers.get("content-encoding")).toBeNull();
  expect(await source.text()).toBe(APP_JS);

  const opaque = await get(rt, SITE, "/pagefind/index.pf_index");
  expect(opaque.status).toBe(200);
  expect(opaque.headers.get("content-type")).toBe("application/gzip");
  expect(opaque.headers.get("content-encoding")).toBeNull();
});

test("index and directory URLs resolve to their compiled keys", async () => {
  expect(await (await get(rt, SITE, "/")).text()).toBe(INDEX);
  expect(await (await get(rt, SITE, "/docs/")).text()).toBe("<h1>docs</h1>\n");
  // Browsers send `[]|{}^` raw in the query (PHP form arrays among them);
  // strict RFC 3986 refuses them, but they never make a path invalid.
  const lenientQuery = await get(rt, SITE, "/?a[]=1&q={x|y}^");
  expect(lenientQuery.status).toBe(200);
  expect(await lenientQuery.text()).toBe(INDEX);

  // W7.2: `/docs` resolves to `docs/index.html`, so canonicalize to the
  // trailing-slash form — relative links in the document resolve against the
  // directory, not the root.
  const canonical = await get(rt, SITE, "/docs");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("/docs/");

  expect(await (await get(rt, SITE, "/zero/")).text()).toBe("<h1>zero</h1>\n");
  expect(await (await get(rt, SITE, "/zero/notes.txt")).text()).toBe("zero notes\n");
});

test("W7.1 clean URLs serve <path>.html and canonicalize the slashed form", async () => {
  const clean = await get(rt, SITE, "/blog/post");
  expect(clean.status).toBe(200);
  expect(await clean.text()).toBe(POST);

  const slashed = await get(rt, SITE, "/blog/post/");
  expect(slashed.status).toBe(308);
  expect(slashed.headers.get("location")).toBe("/blog/post");

  for (const [from, to] of [
    ["/blog/post.html?ref=a", "/blog/post?ref=a"],
    ["/index.html", "/"],
    ["/docs/index.html", "/docs/"],
    ["/docs/index", "/docs/"],
    ["/index", "/"],
  ]) {
    const moved = await get(rt, SITE, from);
    expect(moved.status, from).toBe(308);
    expect(moved.headers.get("location"), from).toBe(to);
  }
  const ruled = await get(rt, SITE, "/loop-page");
  expect(ruled.status).toBe(301);
  expect(ruled.headers.get("location")).toBe("/loop-page.html");
  const kept = await get(rt, SITE, "/loop-page.html");
  expect(kept.status).toBe(200);
  expect(await kept.text()).toBe("<h1>loop page</h1>\n");
  const rewritten = await get(rt, SITE, "/yield/absent-page");
  expect(rewritten.status).toBe(200);
  expect(await rewritten.text()).toBe(INDEX);
});

test("the nearest custom 404 answers, walking up from the requested directory", async () => {
  const nested = await get(rt, SITE, "/blog/missing");
  expect(nested.status).toBe(404);
  expect(await nested.text()).toBe(BLOG_404);

  const root = await get(rt, SITE, "/missing", { headers: { range: "bytes=0-1" } });
  expect(root.status).toBe(404);
  expect(root.headers.get("content-range")).toBeNull();
  expect(await root.text()).toBe(ROOT_404);

  const ruled = await get(rt, SITE, "/gone/forever");
  expect(ruled.status).toBe(404);
  expect(await ruled.text()).toBe(BLOG_404);
});

test("private source files and their companions are never served", async () => {
  for (const requestPath of [
    "/_redirects",
    "/%5Fredirects",
    "/SF.JSONC",
    "/SF.JSONC.gz",
    "/draft.md.gz",
    "/public/sf.jsonc",
    "/docs/_headers",
    "/server/index.ts",
    "/functions/hello.ts",
  ]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status, requestPath).toBe(404);
    expect(await response.text(), requestPath).toBe(ROOT_404);
  }

  const uppercase = await get(rt, SITE, "/_HEADERS");
  expect(uppercase.status).toBe(200);
  expect(await uppercase.text()).toBe("ordinary uppercase file\n");
  const serverPage = await get(rt, SITE, "/server/about");
  expect(serverPage.status).toBe(200);
  expect(await serverPage.text()).toBe("<h1>server page</h1>\n");
  expect((await get(rt, SITE, "/docs/")).status).toBe(200);
});

test("dot paths are forbidden except /.well-known", async () => {
  for (const requestPath of ["/.env", "/.stattic/storage/runtime/jwks.json", "/a/.git/config"]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status, requestPath).toBe(403);
  }
  const wellKnown = await get(rt, SITE, "/.well-known/security.txt");
  expect(wellKnown.status).toBe(200);
  expect(await wellKnown.text()).toContain("security@site.test");
});

test("path identity is NFC at both intake and serve time", async () => {
  const nfc = "café.html"; // U+00E9
  const nfd = "café.html"; // e + U+0301
  const content = "<h1>cafe</h1>\n";

  const entry = { size: content.length, sha256: sha256(content) };
  const duplicate = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/spc_nfc_dup/versions`,
    "create_version",
    { space_id: "spc_nfc_dup" },
    {
      version_id: "ver_nfc_dup_1",
      files: [
        { path: nfc, ...entry },
        { path: nfd, ...entry },
      ],
    },
  );
  expect(duplicate.status).toBe(422);
  expect(await errorCode(duplicate)).toBe("manifest_duplicate_path");

  await deploy(rt, {
    spaceId: "spc_nfc",
    versionId: "ver_nfc_1",
    files: { "index.html": "<h1>root</h1>\n", [nfd]: content },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: ["nfc.test"],
      version_hostnames: [],
    },
  });

  for (const requestForm of [nfc, nfd]) {
    const clean = requestForm.replace(/\.html$/, "");
    const response = await get(rt, "nfc.test", `/${encodeURIComponent(clean)}`);
    expect(response.status).toBe(200);
    expect(await response.text()).toBe(content);
  }
});

test("an encoded separator is rejected rather than resolved", async () => {
  // `%2F` inside a segment is ambiguous between intermediaries; the canonical
  // path gate refuses it instead of guessing, and the refusal is unstorable.
  for (const ambiguous of ["/agent%2Faghf_demo", "/agent%2faghf_demo"]) {
    const response = await get(rt, SITE, ambiguous, { headers: BROWSER_HEADERS });
    expect(response.status, ambiguous).toBe(403);
    expect(response.headers.get("cache-control"), ambiguous).toContain("no-store");
  }
});

test("literal consecutive dots in a file name survive finalization", async () => {
  const host = "literal-dots.test";
  const filePath = "generated/src/apps/www/src/pages/docs/catch...all.html";
  const contents = "Astro catch-all source\n";
  await deploy(rt, {
    spaceId: "spc_literal_dots",
    versionId: "ver_literal_dots_1",
    files: { [filePath]: contents },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });

  const own = await get(rt, host, `/${filePath}`);
  expect(own.status).toBe(308);
  expect(own.headers.get("location")).toBe("/");
  const response = await get(rt, host, "/");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe(contents);
});

test("php-like uploads are neither executed nor served as source", async () => {
  const response = await get(rt, SITE, "/inert.php");
  expect(response.status).toBe(404);
  expect(await response.text()).not.toContain("never executed");
});

test("large exact redirect catalogs preserve normalized ordered matching", async () => {
  const host = "redirect-catalog.test";
  await deploy(rt, {
    spaceId: "spc_redirect_catalog",
    versionId: "ver_redirect_catalog",
    files: {
      "index.html": INDEX,
      "legacy-11999/index.html": "<h1>redirect fallback</h1>\n",
      _redirects: [
        "/ordered/:slug//* /pattern/:slug 301",
        "/ordered/intro /exact 301",
        ...Array.from({ length: 12_000 }, (_, index) => `/legacy-${index} /current-${index} 301`),
        "/* /wrong-later-pattern 301",
      ].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  for (const pathname of [
    "/legacy-11999",
    "/legacy-11999/",
    "/legacy-11999//",
    "/legacy-%31%31%39%39%39//",
  ]) {
    const permanent = await get(rt, host, `${pathname}?campaign=summer&tag=a%2Bb&tag=c%20d`);
    expect(permanent.status).toBe(301);
    expect(permanent.headers.get("location")).toBe(
      "/current-11999?campaign=summer&tag=a%2Bb&tag=c%20d",
    );
  }

  const ordered = await get(rt, host, "/ordered/intro//");
  expect(ordered.status).toBe(301);
  expect(ordered.headers.get("location")).toBe("/pattern/intro");
  const normalized = await get(rt, host, "/ordered/intro/");
  expect(normalized.status).toBe(301);
  expect(normalized.headers.get("location")).toBe("/exact");

  const temporary = await get(rt, SITE, "/found");
  expect(temporary.status).toBe(302);
  expect(temporary.headers.get("location")).toBe("/about.html");

  const directory = await get(rt, SITE, "/docs-redirect");
  expect(directory.status).toBe(302);
  expect(directory.headers.get("location")).toBe("/docs/");
});

test("a Space whose only routing is its overlay serves it", async () => {
  const OVERLAY_HOST = "overlay.test";
  const OVERLAY_VERSION_HOST = "overlay--v1.test";
  await deploy(rt, {
    spaceId: "spc_overlay",
    versionId: "ver_overlay_1",
    metadata: { title: "Overlay" },
    files: {
      "index.html": "<h1>overlay</h1>\n",
      "dashboard.html": "<h1>dashboard</h1>\n",
    },
    finalize: {
      routing_overlay: {
        redirects: [{ source: "/old", destination: "/dashboard.html", status: 302 }],
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({}, "live_and_all_versions"),
      production_hostnames: [OVERLAY_HOST],
      version_hostnames: [{ hostname: OVERLAY_VERSION_HOST, version_id: "ver_overlay_1" }],
    },
  });

  const served = await get(rt, OVERLAY_VERSION_HOST, "/old");
  expect(served.status).toBe(302);
  expect(served.headers.get("location")).toBe("/dashboard.html");
});

test("an edge-placed rule is the edge's only on the hosts the edge was told about", async () => {
  const PLACED_HOST = "placed.test";
  const ATTACHED_LATER_HOST = "attached-later.test";
  const BRANCH_HOST = "branch.test";
  const PLACED_VERSION_HOST = "placed--v1.test";
  const LEGACY = "<h1>legacy</h1>\n";
  await deploy(rt, {
    spaceId: "spc_placed",
    versionId: "ver_placed_1",
    metadata: { title: "Placed" },
    files: {
      "index.html": "<h1>placed</h1>\n",
      "legacy.html": LEGACY,
      "new.html": "<h1>new</h1>\n",
      _redirects: "/legacy.html /new.html 301!",
    },
    finalize: {
      placement_enabled: true,
      routing_production_hostnames: [PLACED_HOST, BRANCH_HOST],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: [PLACED_HOST, ATTACHED_LATER_HOST],
      version_hostnames: [{ hostname: PLACED_VERSION_HOST, version_id: "ver_placed_1" }],
    },
  });

  const production = await get(rt, PLACED_HOST, "/legacy.html");
  expect(production.status).toBe(200);
  expect(await production.text()).toBe(LEGACY);
  expect(production.headers.get("location")).toBeNull();

  const attachedLater = await get(rt, ATTACHED_LATER_HOST, "/legacy.html");
  expect(attachedLater.status).toBe(301);
  expect(attachedLater.headers.get("location")).toBe("/new.html");

  const preview = await get(rt, PLACED_VERSION_HOST, "/legacy.html");
  expect(preview.status).toBe(301);
  expect(preview.headers.get("location")).toBe("/new.html");

  await putRoute(rt, "spc_placed", "beta", {
    version_id: "ver_placed_1",
    config: publicAccessConfig(),
    production_hostnames: [BRANCH_HOST],
    noindex_production_hostnames: [],
    version_hostnames: [],
  });
  const branch = await get(rt, BRANCH_HOST, "/legacy.html");
  expect(branch.status).toBe(301);
  expect(branch.headers.get("location")).toBe("/new.html");
});

test("ordered redirects preserve the request query and RFC method semantics", async () => {
  const withQuery = await get(rt, SITE, "/preserve/resource?ref=1");
  expect(withQuery.status).toBe(307);
  expect(withQuery.headers.get("location")).toBe(`${redirectReceiverUrl("/receiver")}?ref=1`);

  const body = "field=value";
  for (const method of ["POST", "PUT"]) {
    const followed = await fetch(`${rt.baseUrl}/preserve/form`, {
      method,
      headers: { Connection: "close", Host: SITE },
      body,
      redirect: "follow",
    });
    expect(followed.status, method).toBe(200);
    expect(await followed.json(), method).toEqual({ method, body });
  }

  const rejected = await get(rt, SITE, "/preserve/blocked", { method: "TRACE" });
  expect(rejected.status).toBe(405);
  expect(rejected.headers.get("allow")).toBe("GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS");
  expect(rejected.headers.get("location")).toBeNull();
});

// The edge leaves tracking parameters such as utm_source out of its cache key.
// A stored redirect that copied one visitor's query string would send those
// tags to the next visitor on the path, so only a redirect that copied nothing
// may be shared.
test("a redirect that copies the visitor's query string is never shared-cached", async () => {
  const places: [string, string, string, string][] = [
    ["canonical address", SITE, "/docs", "/docs/"],
    ["exact rule", SITE, "/found", "/about.html"],
    ["pattern rule", SITE, "/preserve/resource", redirectReceiverUrl("/receiver")],
    ["host redirect", WWW, "/blog/post.html", "https://site.test/blog/post.html"],
  ];
  for (const [label, host, path, location] of places) {
    const plain = await get(rt, host, path);
    expect(plain.headers.get("location"), label).toBe(location);
    expect(plain.headers.get("cache-control"), label).toContain("public");
    expect(plain.headers.get("a8c-edge-cache"), label).not.toBe("no-cache");

    for (const source of ["a", "b"]) {
      const tagged = await get(rt, host, `${path}?utm_source=${source}`);
      expect(tagged.headers.get("location"), label).toBe(`${location}?utm_source=${source}`);
      expect(tagged.headers.get("cache-control"), label).toBe("private, no-store");
      expect(tagged.headers.get("a8c-edge-cache"), label).toBe("no-cache");
    }
  }

  const ruled = await get(rt, SITE, "/campaign?utm_source=a");
  expect(ruled.status).toBe(302);
  expect(ruled.headers.get("location")).toBe("/landing/a");
  expect(ruled.headers.get("cache-control")).toContain("public");
});

test("capture expansion cannot turn a same-origin redirect into an authority", async () => {
  const response = await get(rt, SITE, "/escape//evil.example/path");
  expect(response.status).toBe(404);
  expect(response.headers.get("location")).toBeNull();
});

test("forced rewrites override committed bytes; non-forced ones yield to them", async () => {
  const forced = await get(rt, SITE, "/about.html");
  expect(forced.status).toBe(200);
  expect(await forced.text()).toBe(INDEX);

  const existing = await get(rt, SITE, "/yield/keep.txt");
  expect(existing.status).toBe(200);
  expect(await existing.text()).toBe("kept\n");

  const missing = await get(rt, SITE, "/yield/absent.txt");
  expect(missing.status).toBe(200);
  expect(await missing.text()).toBe(INDEX);

  for (const requestPath of ["/unicode-nfd", "/unicode-encoded"]) {
    const unicode = await get(rt, SITE, requestPath);
    expect(unicode.status, requestPath).toBe(200);
    expect(await unicode.text(), requestPath).toBe("<h1>café</h1>\n");
  }
});

test("a conditional rule picks one representation per visitor class", async () => {
  const guidePaths = ["/guide/install", "/guide/publish"];
  for (const requestPath of guidePaths) {
    const browser = await get(rt, SITE, requestPath, { headers: BROWSER_HEADERS });
    expect(browser.status, requestPath).toBe(200);
    expect(await browser.text(), requestPath).toBe(GUIDE_HTML);
    expect(browser.headers.get("cache-control"), requestPath).toBe("no-store");
    expect(browser.headers.get("vary"), requestPath).toContain("Accept");
    expect(browser.headers.get("vary"), requestPath).toContain("User-Agent");

    const agent = await get(rt, SITE, requestPath, { headers: AGENT_HEADERS });
    expect(agent.status, requestPath).toBe(200);
    expect(await agent.text(), requestPath).toBe(GUIDE_MD);
    expect(agent.headers.get("cache-control"), requestPath).toBe("no-store");
    expect(agent.headers.get("vary"), requestPath).toContain("Accept");
    expect(agent.headers.get("vary"), requestPath).toContain("User-Agent");
  }

  for (const slug of ["/guide-team", "/guides", "/guide-team/~/settings"]) {
    const response = await get(rt, SITE, slug, { headers: BROWSER_HEADERS });
    expect(response.status, slug).toBe(404);
    expect(await response.text(), slug).toBe(ROOT_404);
  }

  const direct = await get(rt, SITE, "/guide.md", { headers: BROWSER_HEADERS });
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe(GUIDE_MD);
});

test("static entries answer GET and HEAD only", async () => {
  const rejected = await get(rt, SITE, "/blog/post.html", { method: "DELETE" });
  expect(rejected.status).toBe(405);
  expect(rejected.headers.get("allow")).toBe("GET, HEAD");

  const head = await get(rt, SITE, "/blog/post", {
    method: "HEAD",
    headers: { range: "bytes=0-1" },
  });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(Buffer.byteLength(POST)));
  expect(await head.text()).toBe("");
});

test("PHP fallback serves single byte ranges and checks validators before slicing", async () => {
  const plainHtml = await get(rt, SITE, "/");
  const htmlEtag = plainHtml.headers.get("etag");
  if (htmlEtag === null) throw new Error("missing unchanged HTML validator");
  expect(await plainHtml.text()).toBe(INDEX);
  const unchangedHtml = await get(rt, SITE, "/", {
    headers: { "if-none-match": htmlEtag },
  });
  expect(unchangedHtml.status).toBe(304);
  expect(await unchangedHtml.text()).toBe("");
  const staleHtml = await get(rt, SITE, "/", { headers: { "if-match": '"stale"' } });
  expect(staleHtml.status).toBe(412);
  expect(await staleHtml.text()).toBe("");
  const body = "0123456789".repeat(100200);
  const full = await get(rt, SITE, "/movie.mp4");
  expect(full.status).toBe(200);
  expect(full.headers.get("accept-ranges")).toBe("bytes");
  expect(await full.text()).toBe(body);
  const etag = full.headers.get("etag");
  if (etag === null) throw new Error("missing media validator");
  const cases = [
    ["bytes=1000000-1000999", 1000000, 1000999],
    ["bytes=-3", body.length - 3, body.length - 1],
    [`bytes=${body.length - 2}-`, body.length - 2, body.length - 1],
    [`bytes=${body.length - 2}-999999999999999999999`, body.length - 2, body.length - 1],
  ] as const;
  for (const [range, start, end] of cases) {
    const response = await get(rt, SITE, "/movie.mp4", { headers: { range, "if-range": etag } });
    expect(response.status, range).toBe(206);
    expect(response.headers.get("content-range")).toBe(`bytes ${start}-${end}/${body.length}`);
    expect(response.headers.get("content-length")).toBe(String(end - start + 1));
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(await response.text()).toBe(body.slice(start, end + 1));
  }
  for (const [requestPath, range, length] of [
    ["/movie.mp4", `bytes=${body.length}-`, body.length],
    ["/movie.mp4", "bytes=-0", body.length],
    ["/empty.txt", "bytes=0-0", 0],
  ] as const) {
    const response = await get(rt, SITE, requestPath, { headers: { range } });
    expect(response.status).toBe(416);
    expect(response.headers.get("content-range")).toBe(`bytes */${length}`);
    expect(await response.text()).toBe("");
  }
  for (const headers of [
    { range: "bytes=4-2" },
    { range: "bytes=0-1,4-5" },
    { range: "items=0-1" },
    { range: "bytes=0-1", "if-range": `W/${etag}` },
    { range: "bytes=0-1", "if-range": '"stale"' },
    { range: "bytes=0-1", "if-range": "Wed, 21 Oct 2015 07:28:00 GMT" },
  ]) {
    const response = await get(rt, SITE, "/movie.mp4", { headers });
    expect(response.status).toBe(200);
    expect(response.headers.get("content-range")).toBeNull();
    expect(await response.text()).toBe(body);
  }
  const unchanged = await get(rt, SITE, "/movie.mp4", {
    headers: { range: "bytes=0-1", "if-none-match": `"other", W/${etag}` },
  });
  expect(unchanged.status).toBe(304);
  expect(unchanged.headers.get("cache-control")).toBe("private, no-store");
  expect(unchanged.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(unchanged.headers.get("content-range")).toBeNull();
  expect(await unchanged.text()).toBe("");
  const stale = await get(rt, SITE, "/movie.mp4", {
    headers: { range: "bytes=0-1", "if-match": '"stale"' },
  });
  expect(stale.status).toBe(412);
  expect(stale.headers.get("cache-control")).toBe("private, no-store");
  expect(await stale.text()).toBe("");
});

test("host classes: canonical redirects, and a version-pinned host serves its version", async () => {
  const canonical = await get(rt, WWW, "/blog/post.html?from=www&return=%2Fdocs%3Fa%3D1");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe(
    "https://site.test/blog/post.html?from=www&return=%2Fdocs%3Fa%3D1",
  );
  expect(await healthServing(rt, WWW, "ver_site_1")).toMatchObject({
    state: "serving",
    version_match: true,
  });
  // A 308 keeps the method, so a POST is redirected too, never answered 405.
  const posted = await get(rt, WWW, "/blog/post.html?from=www", { method: "POST" });
  expect(posted.status).toBe(308);
  expect(posted.headers.get("location")).toBe("https://site.test/blog/post.html?from=www");

  const renamed = await get(rt, "renamed.test", "/blog/post.html?from=old&view=full");
  expect(renamed.status).toBe(307);
  expect(renamed.headers.get("location")).toBe(
    "https://site.test/blog/post.html?from=old&view=full",
  );

  const domain = await get(rt, "redirect-domain.test", "/blog/post.html");
  expect(domain.status).toBe(302);
  expect(domain.headers.get("location")).toBe("https://destination.example/base/blog/post.html");

  const pinned = await get(rt, VERSION_HOST, "/");
  expect(pinned.status).toBe(200);
  expect(await pinned.text()).toBe(INDEX);
  expect(await healthServing(rt, VERSION_HOST, "ver_site_1")).toMatchObject({
    version_match: true,
  });
});

test("unknown hosts get the undeployed platform page", async () => {
  const response = await get(rt, "unknown.test", "/");
  expect(response.status).toBe(404);
  expect(response.headers.get("cache-control")).toBe("no-store");
  expect(response.headers.get("x-spacefast-undeployed")).toBe("1");
  expect(await response.text()).toContain("This space hasn't been published yet.");
});

test("the primary hostname serves content while runtime APIs remain authenticated", async () => {
  const content = await get(rt, SITE, "/");
  expect(content.status).toBe(200);
  expect(await content.text()).toBe(INDEX);

  const management = await get(rt, SITE, "/__spacefast/api.php?route=/state");
  expect(management.status).toBe(401);
  const upload = await get(rt, SITE, "/__spacefast/upload.php?route=/spaces/spc_site/blobs/have", {
    method: "POST",
    body: JSON.stringify({ shas: [] }),
  });
  expect(upload.status).toBe(401);
});

test("SPA mode serves the shell for app routes, never for asset-looking paths", async () => {
  await deploy(rt, {
    spaceId: "spc_spa",
    versionId: "ver_spa_1",
    metadata: { title: "SPA" },
    files: { "index.html": "<h1>spa</h1>\n", "main.js": "void 0;\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["spa.test"],
      version_hostnames: [],
    },
  });

  const wordpressLoader = path.join(rt.root, "wp-load.php");
  writeFileSync(
    wordpressLoader,
    "<?php throw new RuntimeException('An unclaimed SPA route booted WordPress');",
  );
  const fallback = await get(rt, "spa.test", "/client/route/42");
  rmSync(wordpressLoader);
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>spa</h1>\n");

  for (const requestPath of ["/main-old.js", "/files/report.pdf"]) {
    const missing = await get(rt, "spa.test", requestPath);
    expect(missing.status, requestPath).toBe(404);
    expect(await missing.text(), requestPath).toContain("Page not found");
  }

  const dotted = await get(rt, "spa.test", "/users/jane.doe");
  expect(dotted.status).toBe(200);
  expect(await dotted.text()).toBe("<h1>spa</h1>\n");

  for (let depth = 2; depth <= 4; depth++) {
    const encodedDot = `%${"25".repeat(depth)}2e`;
    const ambiguous = await get(rt, "spa.test", `/a/${encodedDot}${encodedDot}/x.js`);
    expect(ambiguous.status, `depth ${depth}`).toBe(404);
    expect(ambiguous.headers.get("cache-control"), `depth ${depth}`).toBe("no-store");
  }

  writeFileSync(
    storagePath(rt, "spaces", "spc_spa", "content-redirects.json"),
    JSON.stringify({
      exact: {
        "/old-slug": [{ action: "redirect", destination: "/new-slug", status: 301, order: 0 }],
        "/unpublished-rename": [
          {
            action: "redirect",
            destination: "/not-published",
            status: 301,
            order: 0,
            requiresPublishedDestination: true,
          },
        ],
        "/published-rename": [
          {
            action: "redirect",
            destination: "/main.js",
            status: 301,
            order: 0,
            requiresPublishedDestination: true,
          },
        ],
        "/main.js": [{ action: "redirect", destination: "/wrong", status: 301, order: 0 }],
      },
      pattern: [],
    }),
  );
  for (const requestPath of ["/unpublished-rename", "/unpublished-rename/"]) {
    const unpublished = await get(rt, "spa.test", requestPath);
    expect(unpublished.status, requestPath).toBe(200);
    expect(await unpublished.text(), requestPath).toBe("<h1>spa</h1>\n");
  }
  const published = await get(rt, "spa.test", "/published-rename");
  expect(published.status).toBe(301);
  expect(published.headers.get("location")).toBe("/main.js");
  const renamed = await get(rt, "spa.test", "/old-slug");
  expect(renamed.status).toBe(301);
  expect(renamed.headers.get("location")).toBe("/new-slug");
  expect(renamed.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, must-revalidate",
  );
  const asset = await get(rt, "spa.test", "/main.js");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("void 0;\n");
});

test("a configured SPA fallback owns `/` without hiding prerendered pages", async () => {
  const shell = "<h1>app shell</h1>\n";
  const files = {
    "_shell.html": shell,
    "secondary.html": "<h1>secondary</h1>\n",
    "sign-in/index.html": "<h1>sign in</h1>\n",
    "sign-in/_shell.html": "<h1>not a directory index</h1>\n",
  };
  for (const [suffix, config] of [
    ["inferred", {}],
    ["pinned", { index: "_shell.html", fallback: { path: "_shell.html", status: 200 } }],
  ] as const) {
    const host = `spa-root-${suffix}.test`;
    await deploy(rt, {
      spaceId: `spc_spa_root_${suffix}`,
      versionId: `ver_spa_root_${suffix}_1`,
      files,
      serving: { config },
      activate: {
        route_name: "production",
        config: publicAccessConfig(),
        production_hostnames: [host],
        version_hostnames: [],
      },
    });

    const home = await get(rt, host, "/", { headers: { accept: "text/html" } });
    expect(home.status, suffix).toBe(200);
    expect(await home.text(), suffix).toBe(shell);
    expect(await (await get(rt, host, "/client/route/42")).text(), suffix).toBe(shell);
    expect(await (await get(rt, host, "/secondary")).text(), suffix).toBe("<h1>secondary</h1>\n");
    expect(await (await get(rt, host, "/sign-in/")).text(), suffix).toBe("<h1>sign in</h1>\n");
  }
});

test("an app build gets its SPA fallback with no config", async () => {
  const cases = [
    {
      label: "Nuxt generate (200.html beside index.html and 404.html)",
      files: {
        "index.html": "<h1>home</h1>\n",
        "200.html": "<h1>nuxt shell</h1>\n",
        "404.html": "<h1>nuxt 404</h1>\n",
        "_nuxt/entry.js": "void 0;\n",
      },
      route: { status: 200, body: "<h1>nuxt shell</h1>\n" },
    },
    {
      label: "Vite SPA with an extra page",
      files: {
        "index.html": "<h1>vite shell</h1>\n",
        "privacy.html": "<h1>privacy</h1>\n",
        "assets/index-DiwrgTda.js": "void 0;\n",
      },
      route: { status: 200, body: "<h1>vite shell</h1>\n" },
    },
    {
      label: "Next.js export (a root 404.html means routes can miss)",
      files: {
        "index.html": "<h1>next home</h1>\n",
        "404.html": "<h1>next 404</h1>\n",
        "_next/static/chunks/main.js": "void 0;\n",
      },
      route: { status: 404, body: "<h1>next 404</h1>\n" },
    },
    {
      label: "Vite SPA that opted out",
      files: { "index.html": "<h1>vite shell</h1>\n", "assets/index-DiwrgTda.js": "void 0;\n" },
      config: { fallback: false },
      route: { status: 404 },
    },
  ];
  for (const [index, { label, files, config, route }] of cases.entries()) {
    const host = `spa-inferred-${index}.test`;
    await deploy(rt, {
      spaceId: `spc_spa_inferred_${index}`,
      versionId: `ver_spa_inferred_${index}_1`,
      files,
      serving: { config: config ?? {} },
      activate: {
        route_name: "production",
        config: publicAccessConfig(),
        production_hostnames: [host],
        version_hostnames: [],
      },
    });
    const deep = await get(rt, host, "/dashboard/42");
    expect(deep.status, label).toBe(route.status);
    if (route.body) expect(await deep.text(), label).toBe(route.body);
    expect((await get(rt, host, "/assets/missing.js")).status, label).toBe(404);
  }
  expect(await (await get(rt, "spa-inferred-1.test", "/privacy")).text()).toBe(
    "<h1>privacy</h1>\n",
  );
});

test("W7.1 a committed page wins its clean URL over a 200 SPA fallback unless clean URLs are off", async () => {
  await deploy(rt, {
    spaceId: "spc_spa_clean",
    versionId: "ver_spa_clean_1",
    files: { "index.html": "<h1>spa shell</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["spa-clean.test"],
      version_hostnames: [],
    },
  });
  expect(await (await get(rt, "spa-clean.test", "/doc")).text()).toBe("<h1>doc</h1>\n");
  expect(await (await get(rt, "spa-clean.test", "/client/route")).text()).toBe(
    "<h1>spa shell</h1>\n",
  );

  await deploy(rt, {
    spaceId: "spc_spa_clean_off",
    versionId: "ver_spa_clean_off_1",
    files: { "index.html": "<h1>spa shell</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 }, clean_urls: false } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["spa-clean-off.test"],
      version_hostnames: [],
    },
  });
  expect(await (await get(rt, "spa-clean-off.test", "/doc")).text()).toBe("<h1>spa shell</h1>\n");
  expect(await (await get(rt, "spa-clean-off.test", "/doc.html")).text()).toBe("<h1>doc</h1>\n");
});

test("W7.1 clean URLs follow the config, and a 404 fallback is not SPA routing", async () => {
  await deploy(rt, {
    spaceId: "spc_static_clean_off",
    versionId: "ver_static_clean_off_1",
    files: { "index.html": "<h1>home</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { clean_urls: false } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["static-clean-off.test"],
      version_hostnames: [],
    },
  });
  expect((await get(rt, "static-clean-off.test", "/doc")).status).toBe(404);
  expect(await (await get(rt, "static-clean-off.test", "/doc.html")).text()).toBe("<h1>doc</h1>\n");

  await deploy(rt, {
    spaceId: "spc_404_clean",
    versionId: "ver_404_clean_1",
    files: {
      "index.html": "<h1>home</h1>\n",
      "doc.html": "<h1>doc</h1>\n",
      "404.html": "<h1>custom 404</h1>\n",
    },
    serving: { config: { fallback: { path: "404.html", status: 404 } } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["fof-clean.test"],
      version_hostnames: [],
    },
  });
  expect(await (await get(rt, "fof-clean.test", "/doc")).text()).toBe("<h1>doc</h1>\n");
  const missing = await get(rt, "fof-clean.test", "/nope");
  expect(missing.status).toBe(404);
  expect(await missing.text()).toBe("<h1>custom 404</h1>\n");
});

test("W7.1 a clean URL never resolves to a literal `<name>.html/` directory index", async () => {
  await deploy(rt, {
    spaceId: "spc_htmldir",
    versionId: "ver_htmldir_1",
    files: { "index.html": "<h1>root</h1>\n", "weird.html/index.html": "<h1>weird dir</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["htmldir.test"],
      version_hostnames: [],
    },
  });

  const directory = await get(rt, "htmldir.test", "/weird.html/");
  expect(directory.status).toBe(200);
  expect(await directory.text()).toBe("<h1>weird dir</h1>\n");

  expect((await get(rt, "htmldir.test", "/weird")).status).toBe(404);
});

test("a version with no index compiles a directory listing per directory", async () => {
  await deploy(rt, {
    spaceId: "spc_files",
    versionId: "ver_files_1",
    metadata: { mode: "files", title: "Drop" },
    files: {
      "report.pdf.txt": "report\n",
      "notes.md": "---\nraw: true\n---\n# notes\n",
      "data/items.json": "[]\n",
      "SF.JSONC": '{ "private": true }\n',
      ".SF/CONFIG.JSON": '{ "private": true }\n',
    },
    serving: { config: { listing: true, viewer: false } },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "files" }),
      production_hostnames: ["files.test"],
      version_hostnames: [],
    },
  });

  const listing = await get(rt, "files.test", "/", { headers: { accept: "text/html" } });
  expect(listing.status).toBe(200);
  expect(listing.headers.get("content-type")).toBe("text/html; charset=utf-8");
  const html = await listing.text();
  expect(html).toContain('href="/report.pdf.txt"');
  expect(html).toContain('href="/notes.md"');
  expect(html).toContain('href="/data/"');
  expect(html).not.toContain('href="/SF.JSONC"');
  expect(html).not.toContain('href="/.SF/"');
  expect(html.indexOf('href="/data/"')).toBeLessThan(html.indexOf('href="/notes.md"'));
  expect(html.indexOf('href="/notes.md"')).toBeLessThan(html.indexOf('href="/report.pdf.txt"'));

  const nested = await get(rt, "files.test", "/data/", { headers: { accept: "text/html" } });
  expect(nested.status).toBe(200);
  expect(await nested.text()).toContain('href="/data/items.json"');

  const canonical = await get(rt, "files.test", "/data");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("/data/");
});

test("committed index documents answer their directory even when the index knob is off", async () => {
  const root = "<h1>root index</h1>\n";
  const docs = "<h1>docs index</h1>\n";
  await deploy(rt, {
    spaceId: "spc_index_off",
    versionId: "ver_index_off_1",
    metadata: { mode: "files", title: "Files with index documents" },
    files: { "index.html": root, "docs/index.html": docs },
    serving: { config: { index: false, fallback: null, listing: true, viewer: true } },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "files" }),
      production_hostnames: ["indexoff.test"],
      version_hostnames: [],
    },
  });

  expect(await (await get(rt, "indexoff.test", "/")).text()).toBe(root);
  expect(await (await get(rt, "indexoff.test", "/docs/")).text()).toBe(docs);

  const slashless = await get(rt, "indexoff.test", "/docs");
  expect(slashless.status).toBe(308);
  expect(slashless.headers.get("location")).toBe("/docs/");
});

test("a single HTML file becomes the version's index", async () => {
  await deploy(rt, {
    spaceId: "spc_single",
    versionId: "ver_single_1",
    files: {
      "page.html": "<h1>single</h1>\n",
      _redirects: "# none\n",
      "sf.jsonc": "{}\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["single.test"],
      version_hostnames: [],
    },
  });

  expect(await (await get(rt, "single.test", "/")).text()).toBe("<h1>single</h1>\n");
  const own = await get(rt, "single.test", "/page.html");
  expect(own.status).toBe(308);
  expect(own.headers.get("location")).toBe("/");
});

test("a Functions version dispatches its compiled routes; everything else routes normally", async () => {
  const host = "functions-routes.test";
  await deploy(rt, {
    spaceId: "spc_functions_routes",
    versionId: "ver_functions_routes_1",
    metadata: { mode: "website", title: "Functions routes" },
    files: {
      "index.html": "<h1>home</h1>",
      "api/static.txt": "asset wins",
      "api/app.3f9c2d1a.js": "void 0;",
      "hybrid.txt": "hybrid bytes",
    },
    functions: {
      artifact: {
        appName: "functions-routes",
        entry: "worker.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        bypassCookies: ["__prerender_bypass", "__next_preview_data"],
        routes: [
          { method: null, path: "/api", subtree: true },
          { method: "POST", path: "/submit", subtree: false },
          { method: "PUT", path: "/hybrid.txt", subtree: false },
        ],
      },
      host: { hostname: "functions.invalid", bundleUrl: "https://example.test/bundle.json" },
      grantedCapabilities: [],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        { mode: "website", site_title: "Functions routes" },
        "live_and_all_versions",
      ),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  for (const requestPath of ["/api", "/api/users/1"]) {
    const matched = await get(rt, host, requestPath);
    expect(matched.status, requestPath).toBe(503);
    expect(await matched.text(), requestPath).toContain("Function execution is not available.");
  }
  expect((await get(rt, host, "/submit", { method: "POST" })).status).toBe(503);

  const asset = await get(rt, host, "/api/static.txt");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("asset wins");
  // The provider's shared cache ignores Cookie/Vary and resolves before PHP.
  // This ordinary response therefore cannot warm the URL: doing so would make
  // the draft-cookie dispatch below unreachable on the next request.
  expect(asset.headers.get("cache-control")).toBe("no-store");
  expect(asset.headers.get("a8c-edge-cache")).toBe("no-cache");

  const hashed = await get(rt, host, "/api/app.3f9c2d1a.js");
  expect(hashed.status).toBe(200);
  expect(hashed.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect(hashed.headers.get("a8c-edge-cache")).toBe("cache");
  const hashedDraft = await get(rt, host, "/api/app.3f9c2d1a.js", {
    headers: { Cookie: "__prerender_bypass=1" },
  });
  expect(hashedDraft.status).toBe(200);
  expect(await hashedDraft.text()).toBe("void 0;");

  const functionsConfig = storagePath(
    rt,
    "spaces",
    "spc_functions_routes",
    "versions",
    "ver_functions_routes_1",
    "functions",
    "config.json",
  );
  chmodSync(functionsConfig, 0o000);
  try {
    const unknownBypassPolicy = await get(rt, host, "/api/static.txt");
    expect(unknownBypassPolicy.status).toBe(200);
    expect(await unknownBypassPolicy.text()).toBe("asset wins");
    expect(unknownBypassPolicy.headers.get("cache-control")).toBe("no-store");
    expect(unknownBypassPolicy.headers.get("a8c-edge-cache")).toBe("no-cache");
  } finally {
    chmodSync(functionsConfig, 0o644);
  }

  const functionsRoutes = storagePath(
    rt,
    "spaces",
    "spc_functions_routes",
    "versions",
    "ver_functions_routes_1",
    "functions",
    "routes.php",
  );
  chmodSync(functionsRoutes, 0o000);
  try {
    const unreadableRoutes = await get(rt, host, "/api/static.txt");
    expect(unreadableRoutes.status).toBe(200);
    expect(await unreadableRoutes.text()).toBe("asset wins");
    expect(unreadableRoutes.headers.get("cache-control")).toBe("no-store");
    expect(unreadableRoutes.headers.get("a8c-edge-cache")).toBe("no-cache");
  } finally {
    chmodSync(functionsRoutes, 0o644);
  }

  const routesBefore = readFileSync(functionsRoutes, "utf8");
  try {
    writeFileSync(functionsRoutes, "<?php this is not valid PHP\n");
    const malformedRoutes = await get(rt, host, "/api/static.txt");
    expect(malformedRoutes.status).toBe(200);
    expect(await malformedRoutes.text()).toBe("asset wins");
    expect(malformedRoutes.headers.get("cache-control")).toBe("no-store");
    expect(malformedRoutes.headers.get("a8c-edge-cache")).toBe("no-cache");
  } finally {
    writeFileSync(functionsRoutes, routesBefore);
  }

  for (const cookie of ["__prerender_bypass=1", "a=b; __next_preview_data=xyz"]) {
    const draft = await get(rt, host, "/api/static.txt", { headers: { Cookie: cookie } });
    expect(draft.status, cookie).toBe(503);
    expect(await draft.text(), cookie).toContain("Function execution is not available.");
  }

  for (const cookie of [
    "foo__prerender_bypass=1",
    "sf_session=abc",
    "__prerender_bypass_other=1",
  ]) {
    const plain = await get(rt, host, "/api/static.txt", { headers: { Cookie: cookie } });
    expect(plain.status, cookie).toBe(200);
    expect(await plain.text(), cookie).toBe("asset wins");
  }

  const unclaimed = await get(rt, host, "/", { headers: { Cookie: "__prerender_bypass=1" } });
  expect(unclaimed.status).toBe(200);
  expect(await unclaimed.text()).toBe("<h1>home</h1>");
  expect(unclaimed.headers.get("cache-control")).toContain("public");
  expect(unclaimed.headers.get("cache-control")).not.toContain("no-store");
  expect(unclaimed.headers.get("a8c-edge-cache")).toBe("cache");

  for (const requestPath of ["/nope", "/deep/junk/path.js"]) {
    expect((await get(rt, host, requestPath)).status, requestPath).toBe(404);
  }
  const wrongMethod = await get(rt, host, "/submit");
  expect(wrongMethod.status).toBe(405);
  expect(wrongMethod.headers.get("allow")).toBe("POST");
  expect(await (await get(rt, host, "/")).text()).toBe("<h1>home</h1>");

  const claimed = await get(rt, host, "/api/static.txt", { method: "POST" });
  expect(claimed.status).toBe(503);
  expect(await claimed.text()).toContain("Function execution is not available.");

  const union = await get(rt, host, "/hybrid.txt", { method: "POST" });
  expect(union.status).toBe(405);
  expect(union.headers.get("allow")).toBe("GET, HEAD, PUT");
  // The provider edge is method-blind (it keys on host+path+query alone), so
  // every response to a non-GET/HEAD request opts out of the edge and pins
  // itself private no-store, whatever the lane composed.
  expect(union.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(union.headers.get("cache-control")).toBe("private, no-store");

  expect((await get(rt, host, "/hybrid.txt", { method: "PUT" })).status).toBe(503);
  expect(await (await get(rt, host, "/hybrid.txt")).text()).toBe("hybrid bytes");
});

test("a planGated proxy rule follows the synced entitlements doc, without republishing", async () => {
  const host = "plangated.test";
  const route = (entitlements: unknown) => ({
    version_id: "ver_plan_gated_1",
    config: publicAccessConfig({ mode: "website", entitlements }),
    production_hostnames: [host],
    version_hostnames: [],
  });

  await deploy(rt, {
    spaceId: "spc_plan_gated",
    versionId: "ver_plan_gated_1",
    files: {
      "index.html": "<h1>gated</h1>\n",
      _redirects: "/api https://example.com/ 200\n",
    },
    activate: { route_name: "production", ...route({ externalProxy: false }) },
  });

  const restricted = async () => {
    const response = await get(rt, host, "/api");
    return { status: response.status, body: await response.text() };
  };

  await putRoute(rt, "spc_plan_gated", "production", route({ externalProxy: true }));
  const entitled = await restricted();
  expect(entitled.status).not.toBe(403);
  expect(entitled.body).not.toMatch(/free_external_proxy_disabled/);

  for (const entitlements of [{ externalProxy: false }, undefined, null]) {
    await putRoute(rt, "spc_plan_gated", "production", route(entitlements));
    const denied = await restricted();
    expect(denied.status).toBe(403);
    expect(denied.body).toMatch(/free_external_proxy_disabled/);
  }
});

test("template variants select per-channel bytes; a version host serves the committed base", async () => {
  const template = "export const API = '{{ vars.API }}';\n";
  const base = "export const API = 'base';\n";
  const productionVariant = "export const API = 'production';\n";
  const previewVariant = "export const API = 'preview';\n";
  const host = "variants.test";
  const versionHost = "ver-variants.test";

  await deploy(rt, {
    spaceId: "spc_variants",
    versionId: "ver_variants_1",
    files: {
      "index.html":
        '<!doctype html><html><head></head><body><!--#include virtual="/parts/header-shared.html" --><main>{{ vars.API }}</main></body></html>\n',
      "docs/guide.html":
        '<!doctype html><html><head></head><body><!--#include virtual="/parts/header-shared.html" --><main>guide</main></body></html>\n',
      "parts/header-shared.html": "<header>{{ vars.API }}</header>",
      "config.js": template,
      "sf.jsonc": '{ "templates": ["config.js", "index.html", "parts/header-shared.html"] }\n',
    },
    serving: {
      config: { inject: { head: ['<meta name="channel-decoration" content="rendered">'] } },
    },
    finalize: {
      variable_scopes: [
        {
          kind: "space",
          values: {
            API: {
              value: "base",
              channelValues: { production: "production", preview: "preview" },
            },
          },
        },
      ],
      channels: [
        { name: "production", route_name: "production" },
        { name: "preview", route_name: "preview" },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
      production_hostnames: [host],
      version_hostnames: [{ hostname: versionHost, version_id: "ver_variants_1" }],
    },
  });

  const production = await get(rt, host, "/config.js");
  expect(production.status).toBe(200);
  expect(await production.text()).toBe(productionVariant);
  for (const [pagePath, expected] of [
    ["/", "<header>production</header><main>production</main>"],
    ["/docs/guide", "<header>production</header><main>guide</main>"],
  ]) {
    const page = await get(rt, host, pagePath);
    expect(page.status).toBe(200);
    const body = await page.text();
    expect(body).toContain(expected);
    expect(body).toContain('name="channel-decoration"');
    const pinnedPage = await get(rt, versionHost, pagePath);
    expect(pinnedPage.status).toBe(200);
    const pinnedBody = await pinnedPage.text();
    expect(pinnedBody).toContain(expected.replaceAll("production", "base"));
    expect(pinnedBody).toContain('name="channel-decoration"');
  }

  const pinned = await get(rt, versionHost, "/config.js");
  expect(pinned.status).toBe(200);
  expect(await pinned.text()).toBe(base);
  expect(pinned.headers.get("etag")).not.toBe(production.headers.get("etag"));

  const scan = await apiJson<{
    files: Array<{ path: string; sha256: string; variant_route?: string }>;
  }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/spc_variants/versions/ver_variants_1/files?view=served&channel=preview`,
    "list_version_files",
    { space_id: "spc_variants", version_id: "ver_variants_1" },
  );
  expect(scan.files).toContainEqual(
    expect.objectContaining({
      path: "config.js",
      sha256: sha256(previewVariant),
      variant_route: "preview",
    }),
  );
  expect(scan.files).toContainEqual(
    expect.objectContaining({ path: "config.js", sha256: sha256(base) }),
  );

  const variantBlob = await getBlob(
    rt,
    host,
    blobGateToken("spc_variants", sha256(previewVariant), {
      versionId: "ver_variants_1",
      variantRoute: "preview",
    }),
  );
  expect(variantBlob.status).toBe(200);
  expect(await variantBlob.text()).toBe(previewVariant);
  const previewGuide = scan.files.find(
    (file) => file.path === "docs/guide.html" && file.variant_route === "preview",
  );
  if (!previewGuide) throw new Error("Missing rendered preview-channel guide");
  const previewPage = await getBlob(
    rt,
    host,
    blobGateToken("spc_variants", previewGuide.sha256, {
      versionId: "ver_variants_1",
      variantRoute: "preview",
    }),
  );
  expect(previewPage.status).toBe(200);
  const previewBody = await previewPage.text();
  expect(previewBody).toContain("<header>preview</header><main>guide</main>");
  expect(previewBody).toContain('name="channel-decoration"');
  expect(sha256(previewBody)).toBe(previewGuide.sha256);
});

test("tombstoned hostnames return the removed platform page", async () => {
  await deploy(rt, {
    spaceId: "spc_tomb",
    versionId: "ver_tomb_1",
    files: { "index.html": "<h1>tomb</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: ["tomb.test"],
      version_hostnames: [],
    },
  });
  const updated = await api(
    rt,
    "PUT",
    "/__spacefast/api.php/spaces/spc_tomb/tombstones",
    "update_tombstones",
    { space_id: "spc_tomb" },
    { hostnames: ["gone.tomb.test", "also-gone.tomb.test"], mode: "replace" },
  );
  expect(updated.status).toBe(200);

  const tombstoned = await get(rt, "gone.tomb.test", "/");
  expect(tombstoned.status).toBe(404);
  expect(tombstoned.headers.get("x-spacefast-runtime")).toBe("1");

  const tombstonesPath = storagePath(rt, "spaces", "spc_tomb", "tombstones.json");
  chmodSync(tombstonesPath, 0o000);
  try {
    const failedAdd = await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_tomb/tombstones",
      "update_tombstones",
      { space_id: "spc_tomb" },
      { hostnames: ["newly-gone.tomb.test"], mode: "add" },
    );
    expect(failedAdd.status).toBe(500);
  } finally {
    chmodSync(tombstonesPath, 0o644);
  }
  expect((await get(rt, "gone.tomb.test", "/")).status).toBe(404);
  expect((await get(rt, "also-gone.tomb.test", "/")).status).toBe(404);

  const healedAdd = await api(
    rt,
    "PUT",
    "/__spacefast/api.php/spaces/spc_tomb/tombstones",
    "update_tombstones",
    { space_id: "spc_tomb" },
    { hostnames: ["newly-gone.tomb.test"], mode: "add" },
  );
  expect(healedAdd.status).toBe(200);
  expect((await get(rt, "newly-gone.tomb.test", "/")).status).toBe(404);
  expect((await get(rt, "gone.tomb.test", "/")).status).toBe(404);

  expect((await get(rt, "tomb.test", "/")).status).toBe(200);
});

test("finalize activation atomically releases immutable version tombstones", async () => {
  const spaceId = "spc_atomic_tombstone";
  const liveHost = "atomic-tombstone.test";
  const versionHost = "v2.atomic-tombstone.test";
  const retainedVersionHost = "v1.atomic-tombstone.test";

  await deploy(rt, {
    spaceId,
    versionId: "ver_atomic_tombstone_1",
    files: { "index.html": "<h1>first</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
      production_hostnames: [liveHost],
      version_hostnames: [{ hostname: retainedVersionHost, version_id: "ver_atomic_tombstone_1" }],
    },
  });
  await apiJson(
    rt,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
    "update_tombstones",
    { space_id: spaceId },
    { hostnames: [versionHost, retainedVersionHost], mode: "replace" },
  );
  expect((await get(rt, versionHost, "/")).status).toBe(404);
  expect((await get(rt, retainedVersionHost, "/")).status).toBe(404);

  const secondVersionId = "ver_atomic_tombstone_2";
  const secondFiles = { "index.html": "<h1>second</h1>\n" };
  const secondSession = await createDeclaredSession(rt, spaceId, secondVersionId, secondFiles);
  await uploadSessionBlobs(rt, secondSession, secondFiles);
  const secondFinalizeBody = {
    upload_id: secondSession.uploadId,
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
      config_digest: sha256("atomic-tombstone-config"),
      production_hostnames: [liveHost],
      version_hostnames: [
        { hostname: retainedVersionHost, version_id: "ver_atomic_tombstone_1" },
        { hostname: versionHost, version_id: secondVersionId },
      ],
    },
  };
  expect((await finalize(rt, spaceId, secondVersionId, secondFinalizeBody)).status).toBe(200);

  const released = await get(rt, versionHost, "/");
  expect(released.status).toBe(200);
  expect(await healthServing(rt, versionHost, secondVersionId)).toMatchObject({
    version_match: true,
  });
  expect(await released.text()).toBe("<h1>second</h1>\n");
  expect((await get(rt, retainedVersionHost, "/")).status).toBe(404);

  const routePointerPath = storagePath(rt, "spaces", spaceId, "routes", "production.json");
  const incompleteRoutePointer = z
    .looseObject({
      activation_event_id: z.unknown().optional(),
      activation_operation_id: z.unknown().optional(),
      activation_request_digest: z.unknown().optional(),
    })
    .parse(JSON.parse(readFileSync(routePointerPath, "utf8")));
  delete incompleteRoutePointer.activation_event_id;
  delete incompleteRoutePointer.activation_operation_id;
  delete incompleteRoutePointer.activation_request_digest;
  writeFileSync(routePointerPath, JSON.stringify(incompleteRoutePointer));
  await apiJson(
    rt,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
    "update_tombstones",
    { space_id: spaceId },
    { hostnames: [versionHost], mode: "add" },
  );
  expect((await get(rt, versionHost, "/")).status).toBe(404);
  expect((await finalize(rt, spaceId, secondVersionId, secondFinalizeBody)).status).toBe(200);
  expect((await get(rt, versionHost, "/")).status).toBe(200);

  await apiJson(
    rt,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
    "update_tombstones",
    { space_id: spaceId },
    { hostnames: [versionHost], mode: "add" },
  );
  expect((await get(rt, versionHost, "/")).status).toBe(404);
  expect((await finalize(rt, spaceId, secondVersionId, secondFinalizeBody)).status).toBe(200);
  expect((await get(rt, versionHost, "/")).status).toBe(404);
});

test("finalize purges released tombstones once and leaves unchanged replays cached", async () => {
  const rt = await startRuntime({ captureEdgePurges: true });
  try {
    const spaceId = "spc_release_purge_replay";
    const liveHost = "release-purge.test";
    const versionHost = "v2.release-purge.test";

    await deploy(rt, {
      spaceId,
      versionId: "ver_release_purge_1",
      files: { "index.html": "<h1>first</h1>\n" },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
        production_hostnames: [liveHost],
      },
    });
    await apiJson(
      rt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [versionHost], mode: "replace" },
    );
    expect((await get(rt, versionHost, "/")).status).toBe(404);

    const secondVersionId = "ver_release_purge_2";
    const secondFiles = { "index.html": "<h1>second</h1>\n" };
    const secondSession = await createDeclaredSession(rt, spaceId, secondVersionId, secondFiles);
    await uploadSessionBlobs(rt, secondSession, secondFiles);
    const secondFinalizeBody = {
      upload_id: secondSession.uploadId,
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
        config_digest: sha256("release-purge-config"),
        production_hostnames: [liveHost],
        version_hostnames: [{ hostname: versionHost, version_id: secondVersionId }],
      },
    };

    const activation = await finalize(rt, spaceId, secondVersionId, secondFinalizeBody);
    expect(activation.status).toBe(200);
    const activationBody = z
      .looseObject({ purge: z.looseObject({ mode: z.string().optional() }).optional() })
      .parse(await activation.json());
    expect(activationBody.purge?.mode).toBe("domain");
    expect((await get(rt, versionHost, "/")).status).toBe(200);

    const purgesBefore = edgePurgeCalls(rt).length;
    expect(edgePurgeCalls(rt).some((purge) => purge.hostname === versionHost)).toBe(true);
    const replay = await finalize(rt, spaceId, secondVersionId, secondFinalizeBody);
    expect(replay.status).toBe(200);
    expect((await get(rt, versionHost, "/")).status).toBe(200);
    expect(edgePurgeCalls(rt).length).toBe(purgesBefore);

    const versionPurges = edgePurgeCalls(rt).filter(
      (purge) => purge.hostname === versionHost,
    ).length;
    const thirdVersionId = "ver_release_purge_3";
    const thirdFiles = { "index.html": "<h1>third</h1>\n" };
    const thirdSession = await createDeclaredSession(rt, spaceId, thirdVersionId, thirdFiles);
    await uploadSessionBlobs(rt, thirdSession, thirdFiles);
    expect(
      (
        await finalize(rt, spaceId, thirdVersionId, {
          upload_id: thirdSession.uploadId,
          activate: {
            route_name: "production",
            config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
            config_digest: sha256("release-purge-config-3"),
            production_hostnames: [liveHost],
            version_hostnames: [{ hostname: versionHost, version_id: secondVersionId }],
          },
        })
      ).status,
    ).toBe(200);
    expect(edgePurgeCalls(rt).filter((purge) => purge.hostname === versionHost).length).toBe(
      versionPurges,
    );
  } finally {
    rt.stop();
  }
});

test("update_tombstones purges only hostnames whose tombstone state is affected", async () => {
  const purgeRt = await startRuntime({ captureEdgePurges: true });
  try {
    const spaceId = "spc_tomb_purge_scope";
    const liveHost = "live.tomb-scope.test";
    const v1Host = "v1.tomb-scope.test";
    const existingTombHost = "existing.tomb-scope.test";
    const addedTombHost = "added.tomb-scope.test";
    const replacedTombHost = "replaced.tomb-scope.test";

    await deploy(purgeRt, {
      spaceId,
      versionId: "ver_tomb_scope_1",
      files: { "index.html": "<h1>live</h1>\n" },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
        production_hostnames: [liveHost],
        version_hostnames: [{ hostname: v1Host, version_id: "ver_tomb_scope_1" }],
      },
    });
    await apiJson(
      purgeRt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [existingTombHost], mode: "replace", reason: "version_deleted" },
    );

    // mode: "add" purges ONLY the requested hostname, never prior tombstones or intent routes.
    const beforeAdd = edgePurgeCalls(purgeRt).length;
    const addResult = await apiJson(
      purgeRt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [addedTombHost], mode: "add", reason: "version_deleted" },
    );
    expect(addResult.tombstone_count).toBe(2);
    expect(
      edgePurgeCalls(purgeRt)
        .slice(beforeAdd)
        .map((call) => call.hostname),
    ).toEqual([addedTombHost]);
    expect((await get(purgeRt, existingTombHost, "/")).status).toBe(404);
    expect((await get(purgeRt, addedTombHost, "/")).status).toBe(404);
    expect((await get(purgeRt, liveHost, "/")).status).toBe(200);
    expect((await get(purgeRt, v1Host, "/")).status).toBe(200);

    // mode: "remove" purges ONLY the removed hostname.
    const beforeRemove = edgePurgeCalls(purgeRt).length;
    const removeResult = await apiJson(
      purgeRt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [addedTombHost], mode: "remove" },
    );
    expect(removeResult.tombstone_count).toBe(1);
    expect(
      edgePurgeCalls(purgeRt)
        .slice(beforeRemove)
        .map((call) => call.hostname),
    ).toEqual([addedTombHost]);
    expect((await get(purgeRt, existingTombHost, "/")).status).toBe(404);

    // mode: "replace" purges BOTH newly added and newly removed tombstones, not intent routes.
    const beforeReplace = edgePurgeCalls(purgeRt).length;
    const replaceResult = await apiJson(
      purgeRt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [replacedTombHost], mode: "replace", reason: "version_deleted" },
    );
    expect(replaceResult.tombstone_count).toBe(1);
    expect(
      edgePurgeCalls(purgeRt)
        .slice(beforeReplace)
        .map((call) => call.hostname)
        .toSorted(),
    ).toEqual([existingTombHost, replacedTombHost].toSorted());
    expect((await get(purgeRt, replacedTombHost, "/")).status).toBe(404);
    expect((await get(purgeRt, liveHost, "/")).status).toBe(200);

    // mode: "replace" with [] purges the cleared prior tombstone.
    const beforeClear = edgePurgeCalls(purgeRt).length;
    const clearResult = await apiJson(
      purgeRt,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [], mode: "replace" },
    );
    expect(clearResult.tombstone_count).toBe(0);
    expect(
      edgePurgeCalls(purgeRt)
        .slice(beforeClear)
        .map((call) => call.hostname),
    ).toEqual([replacedTombHost]);
  } finally {
    purgeRt.stop();
  }
});

test("finalize rejects proxy rules targeting internal or non-public upstreams", async () => {
  const upstreams = [
    "https://127.0.0.1/api",
    "https://169.254.169.254/latest/meta-data",
    "https://site.view.fast/x",
    "https://localhost/api",
  ];
  for (const [index, upstream] of upstreams.entries()) {
    const response = await finalizeRaw(
      rt,
      "spc_proxy",
      `ver_proxy_${index}`,
      { "index.html": "<h1>proxy</h1>\n", _redirects: `/api ${upstream} 200\n` },
      {},
    );
    expect(response.status, upstream).toBe(422);
    expect(await errorCode(response), upstream).toBe("proxy_upstream_denied");
  }
});

test("finalize accepts a proxy rule targeting the Spacefast API", async () => {
  const response = await finalizeRaw(
    rt,
    "spc_proxy_api",
    "ver_proxy_api_1",
    {
      "index.html": "<h1>proxy</h1>\n",
      _redirects: "/setup.md https://api.spacefast.com/setup.md 200\n",
    },
    {},
  );

  expect(response.status).toBe(200);
});

test("finalize rejects private config targets in fallbacks and rewrites", async () => {
  const files = { "index.html": "<h1>private target</h1>\n", "SF.JSONC": "{}\n" };
  const [fallback, rewrite] = await Promise.all([
    finalizeRaw(rt, "spc_private_fallback", "ver_private_fallback_1", files, {
      serving: { config: { fallback: { path: "SF.JSONC", status: 200 } } },
    }),
    finalizeRaw(
      rt,
      "spc_private_rewrite",
      "ver_private_rewrite_1",
      { ...files, _redirects: "/leak /SF.JSONC 200\n" },
      {},
    ),
  ]);

  expect([fallback.status, rewrite.status]).toEqual([422, 422]);
  expect(await errorCode(fallback)).toBe("invalid_serving_config");
  expect(await errorCode(rewrite)).toBe("runtime_artifact_validation_failed");
});

test("public requests load only the modules their request class needs", async () => {
  const moduleRuntime = await startRuntime();
  const host = "lazy-modules.test";
  let instrumentedRoot: string | undefined;
  let instrumentedServer: PhpServer | undefined;
  try {
    await deploy(moduleRuntime, {
      spaceId: "spc_lazy_modules",
      versionId: "ver_lazy_modules_1",
      files: { "index.html": "static module path" },
      activate: {
        route_name: "production",
        config: publicAccessConfig({}, "live_and_all_versions"),
        production_hostnames: [host],
        version_hostnames: [],
      },
    });

    // Resolved (realpathSync) because the engine prefix below is compared
    // against get_included_files(), which PHP reports as resolved real paths —
    // an unresolved macOS /var/folders tmpdir makes every module list empty.
    instrumentedRoot = realpathSync(
      mkdtempSync(path.join(os.tmpdir(), "stattic-runtime-modules-")),
    );
    cpSync(moduleRuntime.root, instrumentedRoot, { recursive: true });
    const includedLog = path.join(instrumentedRoot, ".stattic/included-files.jsonl");
    const testPrepend = path.join(instrumentedRoot, ".stattic/test-prepend.php");
    writeFileSync(
      testPrepend,
      [
        "<?php",
        `require ${JSON.stringify(RUNTIME_TEST_ATOMIC_PREPEND)};`,
        `register_shutdown_function(static function (): void { file_put_contents(${JSON.stringify(includedLog)}, json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? '', 'files' => get_included_files()], JSON_UNESCAPED_SLASHES) . "\\n", FILE_APPEND | LOCK_EX); });`,
        "",
      ].join("\n"),
    );
    const instrumentedRouter = path.join(instrumentedRoot, ".stattic/test-router.php");
    writeFileSync(
      instrumentedRouter,
      [
        "<?php",
        "require (string) ini_get('auto_prepend_file');",
        `require ${JSON.stringify(RUNTIME_TEST_ROUTER)};`,
        "",
      ].join("\n"),
    );

    instrumentedServer = await startPhpServer({
      args: ["-d", `auto_prepend_file=${testPrepend}`],
      router: instrumentedRouter,
      cwd: instrumentedRoot,
      env: process.env,
    });
    const instrumentedBaseUrl = instrumentedServer.baseUrl;
    const instrumentedRuntime: Runtime = {
      baseUrl: instrumentedBaseUrl,
      root: instrumentedRoot,
      engineRoot: path.join(instrumentedRoot, ".stattic/releases/test/engine"),
      storageRoot: path.join(instrumentedRoot, ".stattic/storage"),
      processId: instrumentedServer.processId,
      diagnostics: instrumentedServer.diagnostics,
      stop: () => undefined,
    };

    const health = await fetch(`${instrumentedBaseUrl}/__spacefast/health.php`);
    expect(health.status).toBe(200);
    const page = await get(instrumentedRuntime, host, "/");
    expect(page.status).toBe(200);
    expect(await page.text()).toBe("static module path");

    const enginePrefix = `${instrumentedRuntime.engineRoot}/`;
    type IncludedRecord = { uri: string; files: string[] };
    const probedUris = ["/", "/__spacefast/health.php"];
    let records: IncludedRecord[] = [];
    const deadline = Date.now() + 10_000;
    for (;;) {
      records = (existsSync(includedLog) ? readFileSync(includedLog, "utf8") : "")
        .split("\n")
        .flatMap((line): IncludedRecord[] => {
          if (line === "") return [];
          try {
            return [JSON.parse(line) as IncludedRecord];
          } catch {
            return [];
          }
        });
      if (probedUris.every((uri) => records.some((record) => record.uri === uri))) break;
      if (Date.now() > deadline) {
        throw new Error(
          `included-files log never recorded all probed URIs; have: ${records
            .map((record) => record.uri)
            .join(", ")}`,
        );
      }
      await new Promise((resolve) => setTimeout(resolve, 25));
    }
    const modulesFor = (uri: string) =>
      records
        .find((record) => record.uri === uri)
        ?.files.filter((file) => file.startsWith(enginePrefix))
        .map((file) => file.slice(enginePrefix.length))
        .toSorted() ?? [];

    expect(modulesFor("/__spacefast/health.php")).toEqual(
      [
        "entrypoints/health.php",
        "loader/request.php",
        "shared/brand.php",
        "shared/context.php",
        "shared/finalizer-protocol.generated.php",
        "shared/problem.php",
        "shared/response.php",
      ].toSorted(),
    );

    const staticModules = modulesFor("/");
    for (const required of [
      "init.php",
      "runtime/serve.php",
      "shared/artifacts.php",
      "shared/context.php",
      "shared/pointers.php",
    ]) {
      expect(staticModules, required).toContain(required);
    }
    for (const forbidden of [
      "runtime/access-rules.php",
      "shared/bootstrap-config.php",
      "shared/jwt.php",
      "shared/storage.php",
    ]) {
      expect(staticModules, forbidden).not.toContain(forbidden);
    }
  } finally {
    instrumentedServer?.stop();
    if (instrumentedRoot) rmSync(instrumentedRoot, { recursive: true, force: true });
    moduleRuntime.stop();
  }
});
