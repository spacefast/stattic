// Routing precedence: redirects, rewrites, SPA fallback, nearest 404, directory
// behavior, private artifacts, robots/noindex host classes, and inert PHP-like files.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn, type ChildProcessWithoutNullStreams } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { brotliCompressSync, gzipSync } from "node:zlib";

import {
  api,
  deploy,
  errorCode,
  finalizeRaw,
  get,
  RUNTIME_TEST_ATOMIC_PREPEND,
  RUNTIME_TEST_ROUTER,
  sha256,
  type Runtime,
  startRuntime,
} from "./harness.ts";

let rt: Runtime;

const SITE = "site.test";
const PREVIEW = "site--preview.test";
const VERSION_HOST = "site--v1.test";
const WWW = "www.site.test";

const INDEX = "<h1>home</h1>\n";
const ABOUT = "<h1>about</h1>\n";
const ROOT_404 = "<h1>root 404</h1>\n";
const BLOG_404 = "<h1>blog 404</h1>\n";
const PRECOMPRESSED_JS = "console.log('precompressed');\n";
const PRECOMPRESSED_JS_BR = brotliCompressSync(Buffer.from(PRECOMPRESSED_JS));
const PRECOMPRESSED_JS_GZIP = gzipSync(Buffer.from(PRECOMPRESSED_JS));
const GZIP_PAYLOAD = Buffer.from([
  0x1f, 0x8b, 0x08, 0x00, 0x70, 0x61, 0x79, 0x6c, 0x6f, 0x61, 0x64,
]);

async function freePort(): Promise<number> {
  const server = net.createServer();
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  if (address === null || typeof address === "string") {
    server.close();
    throw new Error("runtime_test_listener_missing_port");
  }
  await new Promise<void>((resolve, reject) =>
    server.close((error) => (error ? reject(error) : resolve())),
  );
  return address.port;
}

function waitForPhpServer(server: ChildProcessWithoutNullStreams): Promise<void> {
  return new Promise((resolve, reject) => {
    let stderr = "";
    const timeout = setTimeout(() => finish(new Error("php_server_start_timeout")), 10_000);
    const onData = (chunk: Buffer) => {
      stderr += chunk.toString();
      if (stderr.includes(" Development Server (") && stderr.includes(") started")) finish();
    };
    const onExit = (code: number | null) => finish(new Error(`php_exited:${code}`));
    const finish = (error?: Error) => {
      clearTimeout(timeout);
      server.stderr.off("data", onData);
      server.off("exit", onExit);
      if (error) reject(error);
      else resolve();
    };
    server.stderr.on("data", onData);
    server.once("exit", onExit);
  });
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_site",
    versionId: "ver_site_1",
    metadata: { mode: "website", title: "Routing" },
    files: {
      "index.html": INDEX,
      "about.html": ABOUT,
      "404.html": ROOT_404,
      "docs/index.html": "<h1>docs</h1>\n",
      "agents-doc/index.html": "<h1>agents doc html</h1>\n",
      "agents-doc.md": "# Agents doc markdown\n",
      "agent-handoff.html": "<h1>agent handoff html</h1>\n",
      "agent-handoff.md": "# Agent handoff markdown\n",
      "ai/index.html": "<h1>ai html</h1>\n",
      "ai.txt": "# ai agent setup\n",
      "ai.md": "# ai brief markdown\n",
      "blog/404.html": BLOG_404,
      "blog/post.html": "<h1>post</h1>\n",
      "assets/app.js": "console.log('app');\n",
      "assets/screenshot.png": Buffer.from([0x89, 0x50, 0x4e, 0x47]),
      "assets/precompressed.js": PRECOMPRESSED_JS,
      "assets/precompressed.js.br": PRECOMPRESSED_JS_BR,
      "assets/precompressed.js.gz": PRECOMPRESSED_JS_GZIP,
      "pagefind/wasm.unknown.pagefind": GZIP_PAYLOAD,
      "pagefind/pagefind.en_hash.pf_meta": GZIP_PAYLOAD,
      "pagefind/index/en_hash.pf_index": GZIP_PAYLOAD,
      "pagefind/fragment/en_hash.pf_fragment": GZIP_PAYLOAD,
      "inert.php": "<?php echo 'never executed';\n",
      "_headers.html": "<h1>headers doc</h1>\n",
      _redirects: "/old /about.html 301\n",
      _HEADERS: "ordinary uppercase file\n",
      "SF.JSONC": '{ "private": true }\n',
      "SF.JSONC.gz": gzipSync(Buffer.from('{ "private": true }\n')),
      ".well-known/security.txt": "Contact: mailto:security@site.test\n",
    },
    serving: {
      redirects_exact: {
        "/old": [{ destination: "/about.html", status: 301, action: "redirect", order: 1 }],
        "/found": [{ destination: "/about.html", status: 302, action: "redirect", order: 2 }],
        "/docs-redirect": [{ destination: "/docs/", status: 302, action: "redirect", order: 3 }],
        "/about.html": [
          { destination: "/index.html", status: 200, action: "rewrite", force: true, order: 4 },
        ],
        "/agents-doc": [
          {
            destination: "/agents-doc.md",
            status: 200,
            action: "rewrite",
            force: true,
            conditions: [{ kind: "agent", values: ["true"] }],
            order: 5,
          },
        ],
        // Bare /agent is an exact rule now that the malformed-link catchers
        // are segment-bounded (no /agent* prefix splat).
        "/agent": [
          {
            destination: "/agent-handoff.md",
            status: 200,
            action: "rewrite",
            conditions: [{ kind: "agent", values: ["true"] }],
            order: 10,
          },
          {
            destination: "/agent-handoff.html",
            status: 200,
            action: "rewrite",
            order: 11,
          },
        ],
        // The website's /ai negotiation rule (apps/www/src/lib/routing.mjs):
        // agents get the plain-text setup, browsers the Astro page.
        "/ai": [
          {
            destination: "/ai.txt",
            status: 200,
            action: "rewrite",
            force: true,
            conditions: [{ kind: "agent", values: ["true"] }],
            order: 12,
          },
        ],
      },
      redirects_pattern: [
        {
          source: "/app/*",
          regex: "^/app/(?<splat>.*)$",
          destination: "/index.html",
          status: 200,
          action: "rewrite",
          order: 6,
        },
        {
          source: "/gone/*",
          regex: "^/gone/(?<splat>.*)$",
          destination: "/blog/404.html",
          status: 404,
          action: "notFound",
          order: 7,
        },
        // The compiled shape of the dashboard's agent-handoff document rules
        // (apps/my/public/_redirects): one static document for every id.
        {
          source: "/agent/*",
          regex: "^/agent/(?<splat>.*)$",
          destination: "/agent-handoff.md",
          status: 200,
          action: "rewrite",
          force: true,
          conditions: [{ kind: "agent", values: ["true"] }],
          order: 8,
        },
        {
          source: "/agent/*",
          regex: "^/agent/(?<splat>.*)$",
          destination: "/agent-handoff.html",
          status: 200,
          action: "rewrite",
          force: true,
          order: 9,
        },
        // The non-forced malformed-link catchers, SEGMENT-BOUNDED: mangled
        // single-segment paths (/agent%2F<id>, /agent.<junk>) still serve the
        // document, but valid team slugs like /agent-team, /agents, /agentic
        // fall through to normal routing — a prefix splat (/agent*) would
        // permanently shadow every agent-prefixed team dashboard.
        {
          source: "/agent%2F*",
          regex: "^/agent%2F(?<splat>.*)$",
          destination: "/agent-handoff.md",
          status: 200,
          action: "rewrite",
          conditions: [{ kind: "agent", values: ["true"] }],
          order: 13,
        },
        {
          source: "/agent%2F*",
          regex: "^/agent%2F(?<splat>.*)$",
          destination: "/agent-handoff.html",
          status: 200,
          action: "rewrite",
          order: 14,
        },
        {
          source: "/agent%2f*",
          regex: "^/agent%2f(?<splat>.*)$",
          destination: "/agent-handoff.md",
          status: 200,
          action: "rewrite",
          conditions: [{ kind: "agent", values: ["true"] }],
          order: 15,
        },
        {
          source: "/agent%2f*",
          regex: "^/agent%2f(?<splat>.*)$",
          destination: "/agent-handoff.html",
          status: 200,
          action: "rewrite",
          order: 16,
        },
        {
          source: "/agent.*",
          regex: "^/agent\\.(?<splat>.*)$",
          destination: "/agent-handoff.md",
          status: 200,
          action: "rewrite",
          conditions: [{ kind: "agent", values: ["true"] }],
          order: 17,
        },
        {
          source: "/agent.*",
          regex: "^/agent\\.(?<splat>.*)$",
          destination: "/agent-handoff.html",
          status: 200,
          action: "rewrite",
          order: 18,
        },
      ],
      // The website's _headers rules for /ai: user rules can never set Vary
      // (platform-managed), so the negotiated URL opts out of shared caches.
      // Header rules match the SERVED (post-rewrite) path, so the rewrite
      // destination needs its own rule for the agent representation.
      headers_exact: {
        "/ai": [
          {
            order: 1,
            operations: [{ kind: "set", name: "Cache-Control", value: "no-store" }],
            headers: { "Cache-Control": "no-store" },
          },
        ],
        "/ai/": [
          {
            order: 2,
            operations: [{ kind: "set", name: "Cache-Control", value: "no-store" }],
            headers: { "Cache-Control": "no-store" },
          },
        ],
        "/ai.txt": [
          {
            order: 3,
            operations: [{ kind: "set", name: "Cache-Control", value: "no-store" }],
            headers: { "Cache-Control": "no-store" },
          },
        ],
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Routing" },
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("serves exact files with precomputed metadata", async () => {
  const response = await get(rt, SITE, "/assets/app.js");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe("console.log('app');\n");
  expect(response.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
  expect(response.headers.get("x-content-type-options")).toBe("nosniff");
  expect(response.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
  expect(response.headers.get("cdn-cache-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
  expect(response.headers.get("surrogate-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
  expect(response.headers.get("etag")).toMatch(/^"[a-f0-9]{64}"$/);
  expect(response.headers.get("x-spacefast-version")).toBe("ver_site_1");
  expect(response.headers.get("set-cookie")).toBeNull();
});

test("unhashed uploaded assets keep browser revalidation while caching at the edge", async () => {
  const production = await get(rt, SITE, "/assets/screenshot.png");
  expect(production.status).toBe(200);
  expect(production.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
  expect(production.headers.get("set-cookie")).toBeNull();
});

test("serves precompressed sidecars for encoded static requests", async () => {
  const brotli = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "gzip;q=0.5, br" },
  });
  expect(brotli.status).toBe(200);
  expect(brotli.headers.get("content-encoding")).toBe("br");
  expect(brotli.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
  expect(brotli.headers.get("content-length")).toBe(String(PRECOMPRESSED_JS_BR.length));
  expect(brotli.headers.get("vary")?.toLowerCase()).toContain("accept-encoding");
  expect(await brotli.text()).toBe(PRECOMPRESSED_JS);

  const gzip = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "gzip" },
  });
  expect(gzip.status).toBe(200);
  expect(gzip.headers.get("content-encoding")).toBe("gzip");
  expect(gzip.headers.get("content-length")).toBe(String(PRECOMPRESSED_JS_GZIP.length));
  expect(await gzip.text()).toBe(PRECOMPRESSED_JS);

  const identity = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "identity" },
  });
  expect(identity.status).toBe(200);
  expect(identity.headers.get("content-encoding")).toBeNull();
  expect(identity.headers.get("content-length")).toBe(String(Buffer.byteLength(PRECOMPRESSED_JS)));
  expect(await identity.text()).toBe(PRECOMPRESSED_JS);
});

test("precompressed sidecars preserve conditional, HEAD, range, and direct sidecar behavior", async () => {
  const head = await get(rt, SITE, "/assets/precompressed.js", {
    method: "HEAD",
    headers: { "accept-encoding": "br" },
  });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-encoding")).toBe("br");
  expect(head.headers.get("content-length")).toBe(String(PRECOMPRESSED_JS_BR.length));
  expect(await head.text()).toBe("");

  const encoded = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "br" },
  });
  const encodedEtag = encoded.headers.get("etag") ?? "";
  await encoded.text();
  const conditional = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "br", "if-none-match": encodedEtag },
  });
  expect(conditional.status).toBe(304);
  expect(conditional.headers.get("content-encoding")).toBe("br");

  const range = await get(rt, SITE, "/assets/precompressed.js", {
    headers: { "accept-encoding": "br", range: "bytes=0-6" },
  });
  expect(range.status).toBe(206);
  expect(range.headers.get("content-encoding")).toBeNull();
  expect(await range.text()).toBe("console");
  expect(range.headers.get("content-range")).toMatch(/^bytes 0-6\//);

  const directSidecar = await get(rt, SITE, "/assets/precompressed.js.gz");
  expect(directSidecar.status).toBe(200);
  expect(directSidecar.headers.get("content-type")).toBe("application/gzip");
  expect(directSidecar.headers.get("content-encoding")).toBeNull();
});

test("serves gzip payloads under opaque extensions without HTTP content encoding", async () => {
  for (const requestPath of [
    "/pagefind/wasm.unknown.pagefind",
    "/pagefind/pagefind.en_hash.pf_meta",
    "/pagefind/index/en_hash.pf_index",
    "/pagefind/fragment/en_hash.pf_fragment",
  ]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status).toBe(200);
    expect(response.headers.get("content-type")).toBe("application/gzip");
    expect(response.headers.get("content-encoding")).toBeNull();
    expect(response.headers.get("x-content-type-options")).toBe("nosniff");
  }
});

test("serves index.html for / and the canonical trailing-slash directory path", async () => {
  for (const requestPath of ["/", "/docs/"]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status).toBe(200);
  }
  expect(await (await get(rt, SITE, "/")).text()).toBe(INDEX);
  expect(await (await get(rt, SITE, "/docs/")).text()).toBe("<h1>docs</h1>\n");
});

test("W7.2 slashless directory requests 308-redirect to the trailing-slash URL", async () => {
  // `/docs` resolves to `docs/index.html`; canonicalize to `/docs/` so the
  // document's relative links/assets resolve against the directory, not `/`.
  // The query string is preserved on the redirect.
  const redirect = await get(rt, SITE, "/docs?team=acme");
  expect(redirect.status).toBe(308);
  expect(redirect.headers.get("location")).toBe("/docs/?team=acme");
});

test("exact route rules preserve slashless directory URLs for conditional rewrites", async () => {
  const human = await get(rt, SITE, "/agents-doc");
  expect(human.status).toBe(200);
  expect(await human.text()).toBe("<h1>agents doc html</h1>\n");

  const agent = await get(rt, SITE, "/agents-doc", {
    headers: { accept: "text/plain", "user-agent": "Codex" },
  });
  expect(agent.status).toBe(200);
  expect(await agent.text()).toBe("# Agents doc markdown\n");
});

test("agent detection covers shell fetches and markdown accept negotiation", async () => {
  // curl/wget defaults (Accept: */*) and a bare text/markdown Accept are agent
  // fetches; a text/markdown preference alongside text/html stays a browser.
  for (const headers of [
    { accept: "*/*", "user-agent": "curl/8.5.0" },
    { accept: "*/*", "user-agent": "Wget/1.21.4 (linux-gnu)" },
    { accept: "text/markdown", "user-agent": "SomethingNiche/1.0" },
  ]) {
    const response = await get(rt, SITE, "/agents-doc", { headers });
    expect(response.status).toBe(200);
    expect(await response.text()).toBe("# Agents doc markdown\n");
  }

  const mixedAccept = await get(rt, SITE, "/agents-doc", {
    headers: { accept: "text/html, text/markdown", "user-agent": "SomethingNiche/1.0" },
  });
  expect(mixedAccept.status).toBe(200);
  expect(await mixedAccept.text()).toBe("<h1>agents doc html</h1>\n");
});

test("negotiated /ai keeps both representations out of shared caches", async () => {
  // /ai serves two representations at one URL (Agent=true rewrites to
  // /ai.txt) and Vary is platform-managed — a shared cache keyed on the URL
  // alone would pin whichever representation landed first for everyone. The
  // _headers no-store rule must reach browsers AND the CDN trio (suppressing
  // the default one-year s-maxage) on both representations, while the
  // negotiation itself keeps working.
  const agent = await get(rt, SITE, "/ai", {
    headers: { accept: "*/*", "user-agent": "curl/8.5.0" },
  });
  expect(agent.status).toBe(200);
  expect(await agent.text()).toBe("# ai agent setup\n");
  expect(agent.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  // Agent negotiation is a conditional rewrite. In addition to the /ai
  // no-store policy, the runtime's current conditional-rewrite hardening pins
  // it private so a URL shared by browser and agent clients cannot be reused.
  expect(agent.headers.get("cache-control")).toBe("private, no-store");
  expect(agent.headers.get("cdn-cache-control")).toBe("private, no-store");
  expect(agent.headers.get("surrogate-control")).toBe("private, no-store");

  const browser = await get(rt, SITE, "/ai", {
    headers: {
      accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      "user-agent":
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    },
  });
  expect(browser.status).toBe(200);
  expect(await browser.text()).toBe("<h1>ai html</h1>\n");
  expect(browser.headers.get("content-type")).toBe("text/html; charset=utf-8");
  expect(browser.headers.get("cache-control")).toBe("no-store");
  expect(browser.headers.get("cdn-cache-control")).toBe("no-store");
  expect(browser.headers.get("surrogate-control")).toBe("no-store");

  const slashBrowser = await get(rt, SITE, "/ai/", {
    headers: {
      accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      "user-agent": "Mozilla/5.0 Safari/537.36",
    },
  });
  expect(slashBrowser.status).toBe(200);
  expect(await slashBrowser.text()).toBe("<h1>ai html</h1>\n");
  expect(slashBrowser.headers.get("cache-control")).toBe("no-store");
  expect(slashBrowser.headers.get("cdn-cache-control")).toBe("no-store");
  expect(slashBrowser.headers.get("surrogate-control")).toBe("no-store");

  // Direct fetches of the agent representation share the policy: header rules
  // match the served path, so /ai.txt cached at the edge would still be the
  // bytes a rewritten /ai response is built from.
  const direct = await get(rt, SITE, "/ai.txt");
  expect(direct.status).toBe(200);
  expect(direct.headers.get("cache-control")).toBe("no-store");
  expect(direct.headers.get("cdn-cache-control")).toBe("no-store");
});

test("markdown files fall back to text/markdown in the runtime MIME map", async () => {
  // Uploads without a declared MIME (the harness path; prod declares via
  // contentTypeForPath) hit _stattic_runtime_mime — .md used to fall through
  // to application/octet-stream there.
  const markdown = await get(rt, SITE, "/ai.md");
  expect(markdown.status).toBe(200);
  expect(await markdown.text()).toBe("# ai brief markdown\n");
  expect(markdown.headers.get("content-type")).toBe("text/markdown; charset=utf-8");
  // Single representation at its own URL — normal shared caching applies.
  expect(markdown.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
});

test("agent handoff document pattern serves identical bytes for every well-formed id", async () => {
  // The dashboard's /agent/<documentId>#<secret> links: fetches carry no
  // fragment, so the served document must be one static artifact — real and
  // fake ids indistinguishable by status and bytes, markdown for agents, full
  // HTML for browsers, no client JS required either way.
  const browserHeaders = {
    accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "user-agent":
      "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
  };
  const realId = "/agent/aghf_1a2b3c4d5e6f7a8b9c0d";
  const fakeId = "/agent/aghf_00000000000000000000";

  for (const headers of [
    { accept: "text/plain", "user-agent": "Claude-User/1.0" },
    { accept: "*/*", "user-agent": "curl/8.5.0" },
  ]) {
    const agentReal = await get(rt, SITE, realId, { headers });
    const agentFake = await get(rt, SITE, fakeId, { headers });
    expect(agentReal.status).toBe(200);
    expect(agentFake.status).toBe(200);
    expect(await agentReal.text()).toBe("# Agent handoff markdown\n");
    expect(await agentFake.text()).toBe("# Agent handoff markdown\n");
  }

  const browserReal = await get(rt, SITE, realId, { headers: browserHeaders });
  const browserFake = await get(rt, SITE, fakeId, { headers: browserHeaders });
  expect(browserReal.status).toBe(200);
  expect(browserFake.status).toBe(200);
  const browserBody = await browserReal.text();
  expect(browserBody).toBe("<h1>agent handoff html</h1>\n");
  expect(await browserFake.text()).toBe(browserBody);
});

test("malformed handoff paths serve the document; the real files serve their own bytes", async () => {
  // Bare /agent and single-segment mutations (an encoded slash) must never
  // fall through to the SPA shell: a shell round-trip would let client-side
  // auth routing copy the URL (fragment included) into a returnTo query.
  const browserHeaders = {
    accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "user-agent":
      "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
  };
  for (const mangled of ["/agent", "/agent%2Faghf_demo", "/agent%2faghf_demo", "/agent.anything"]) {
    const browser = await get(rt, SITE, mangled, { headers: browserHeaders });
    expect(browser.status).toBe(200);
    expect(await browser.text()).toBe("<h1>agent handoff html</h1>\n");

    const agent = await get(rt, SITE, mangled, {
      headers: { accept: "*/*", "user-agent": "curl/8.5.0" },
    });
    expect(agent.status).toBe(200);
    expect(await agent.text()).toBe("# Agent handoff markdown\n");
  }

  // Segment-bounded catchers: valid team slugs that merely share the prefix
  // (the migration's own agent -> agent-team rename target above all) must
  // fall through to normal routing, never the handoff document. None of these
  // exist in the fixture, so fall-through means the root 404 page.
  for (const slugPath of ["/agent-team", "/agents", "/agentic", "/agent-team/~/settings"]) {
    const browser = await get(rt, SITE, slugPath, { headers: browserHeaders });
    expect(browser.status).toBe(404);
    expect(await browser.text()).toBe(ROOT_404);

    const agent = await get(rt, SITE, slugPath, {
      headers: { accept: "*/*", "user-agent": "curl/8.5.0" },
    });
    expect(agent.status).toBe(404);
    expect(await agent.text()).toBe(ROOT_404);
  }

  // The catcher rules are non-forced: direct requests for the generated
  // assets keep serving the asset itself for every audience.
  const directMarkdown = await get(rt, SITE, "/agent-handoff.md", { headers: browserHeaders });
  expect(directMarkdown.status).toBe(200);
  expect(await directMarkdown.text()).toBe("# Agent handoff markdown\n");
  const directHtml = await get(rt, SITE, "/agent-handoff.html", {
    headers: { accept: "*/*", "user-agent": "curl/8.5.0" },
  });
  expect(directHtml.status).toBe(200);
  expect(await directHtml.text()).toBe("<h1>agent handoff html</h1>\n");
});

test("W7.1 clean URLs serve <path>.html for extensionless requests", async () => {
  // `blog/post.html` is published with no directory-index form; an extensionless
  // request for `/blog/post` must serve those bytes as a 200 (Hugo uglyURLs /
  // Jekyll / hand-written flat HTML) instead of 404ing.
  const response = await get(rt, SITE, "/blog/post");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>post</h1>\n");
});

test("W7.1 clean URLs never resurrect a reserved/terminal path", async () => {
  // `_headers.html` is a normal published file (reachable at its own URL), but
  // `/_headers` is a reserved convention path that must stay a terminal 404 — the
  // clean-URL probe must not serve `_headers.html` there.
  const reserved = await get(rt, SITE, "/_headers");
  expect(reserved.status).toBe(404);

  const direct = await get(rt, SITE, "/_headers.html");
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe("<h1>headers doc</h1>\n");
});

test("exact redirects win and carry status-derived cache policy", async () => {
  const permanent = await get(rt, SITE, "/old");
  expect(permanent.status).toBe(301);
  expect(permanent.headers.get("location")).toBe("/about.html");
  expect(permanent.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");

  const temporary = await get(rt, SITE, "/found?q=1");
  expect(temporary.status).toBe(302);
  expect(temporary.headers.get("location")).toBe("/about.html?q=1");

  const directory = await get(rt, SITE, "/docs-redirect");
  expect(directory.status).toBe(302);
  expect(directory.headers.get("location")).toBe("/docs/");
});

test("non-forced rewrites yield to existing files; forced rewrites do not", async () => {
  // /app/* rewrite applies because the target path has no committed file.
  const rewritten = await get(rt, SITE, "/app/deep/route");
  expect(rewritten.status).toBe(200);
  expect(await rewritten.text()).toBe(INDEX);

  // /about.html exists, but the rule is forced, so the rewrite still applies.
  const forced = await get(rt, SITE, "/about.html");
  expect(forced.status).toBe(200);
  expect(await forced.text()).toBe(INDEX);
});

test("404 rewrite rules serve the target file with a 404 status", async () => {
  const response = await get(rt, SITE, "/gone/forever");
  expect(response.status).toBe(404);
  expect(await response.text()).toBe(BLOG_404);
});

test("nearest 404 wins inside its directory, root 404 elsewhere", async () => {
  const nested = await get(rt, SITE, "/blog/missing");
  expect(nested.status).toBe(404);
  expect(await nested.text()).toBe(BLOG_404);

  const root = await get(rt, SITE, "/missing");
  expect(root.status).toBe(404);
  expect(await root.text()).toBe(ROOT_404);
});

test("version artifacts and encoded variants are never served", async () => {
  // _redirects is committed content but compiles to a not_found lookup action.
  const direct = await get(rt, SITE, "/_redirects");
  expect(direct.status).toBe(404);

  const encoded = await get(rt, SITE, "/%5Fredirects");
  expect(encoded.status).toBe(404);

  const mixedCaseConfig = await get(rt, SITE, "/SF.JSONC");
  expect(mixedCaseConfig.status).toBe(404);

  const mixedCaseSidecar = await get(rt, SITE, "/SF.JSONC.gz");
  expect(mixedCaseSidecar.status).toBe(404);

  const uppercaseConventionLikeFile = await get(rt, SITE, "/_HEADERS");
  expect(uppercaseConventionLikeFile.status).toBe(200);
  expect(await uppercaseConventionLikeFile.text()).toBe("ordinary uppercase file\n");
});

test("finalize writes a PHP manifest and static serving consumes it", async () => {
  const host = "manifest.site.test";
  await deploy(rt, {
    spaceId: "spc_manifest",
    versionId: "ver_manifest_1",
    files: {
      "index.html": "<h1>manifest index</h1>\n",
      "alt.html": "<h1>manifest alt</h1>\n",
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Manifest" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_manifest",
    "versions",
    "ver_manifest_1",
  );
  const manifestPath = path.join(versionRoot, "php-manifest.php");
  const serving = readFileSync(path.join(versionRoot, "serving.php"), "utf8");
  expect(existsSync(manifestPath)).toBe(true);
  expect(serving).toContain("'php_manifest' => true");

  writeFileSync(
    manifestPath,
    `<?php
return [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_manifest_1',
    'routes' => [
        ['action' => 'serve_static', 'pattern' => '/', 'file' => 'alt.html'],
    ],
];
`,
  );

  const response = await get(rt, host, "/");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>manifest alt</h1>\n");
});

test("finalize writes redirect records and serving consumes them from the PHP manifest", async () => {
  const host = "manifest-redirect.site.test";
  await deploy(rt, {
    spaceId: "spc_manifest_redirect",
    versionId: "ver_manifest_redirect_1",
    files: {
      "index.html": "<h1>manifest redirect</h1>\n",
    },
    serving: {
      redirects_exact: {
        "/from": [
          { destination: "/legacy-destination", status: 302, action: "redirect", order: 1 },
        ],
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Manifest Redirect" },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const versionRoot = path.join(
    rt.storageRoot,
    "spaces",
    "spc_manifest_redirect",
    "versions",
    "ver_manifest_redirect_1",
  );
  const manifestPath = path.join(versionRoot, "php-manifest.php");
  expect(readFileSync(manifestPath, "utf8")).toContain("'action' => 'redirect'");

  writeFileSync(
    manifestPath,
    `<?php
return [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_manifest_redirect_1',
    'routes' => [
        [
            'action' => 'redirect',
            'pattern' => '/from',
            'destination' => '/manifest-destination',
            'status' => 302,
            'cacheControl' => 'public, max-age=0, s-maxage=60, must-revalidate',
        ],
    ],
];
`,
  );

  const response = await get(rt, host, "/from?debug=1");
  expect(response.status).toBe(302);
  expect(response.headers.get("location")).toBe("/manifest-destination?debug=1");
});

test("dot paths are forbidden except /.well-known", async () => {
  for (const requestPath of ["/.env", "/.stattic/storage/runtime/jwks.json", "/a/.git/config"]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status).toBe(403);
  }
  const wellKnown = await get(rt, SITE, "/.well-known/security.txt");
  expect(wellKnown.status).toBe(200);
  expect(await wellKnown.text()).toContain("security@site.test");
});

test("literal consecutive dots in a file name survive manifest finalization", async () => {
  const host = "literal-dots.test";
  const filePath = "generated/src/apps/www/src/pages/docs/catch...all.html";
  const contents = "Astro catch-all source\n";
  await deploy(rt, {
    spaceId: "spc_literal_dots",
    versionId: "ver_literal_dots_1",
    files: { [filePath]: contents },
    activate: {
      route_name: "production",
      config: {},
      production_hostnames: [host],
      version_hostnames: [],
    },
  });

  const response = await get(rt, host, `/${filePath}`);
  const body = await response.text();
  expect(body).toBe(contents);
  expect(response.status).toBe(200);
});

test("php-like files are inert text, never executed", async () => {
  const response = await get(rt, SITE, "/inert.php");
  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  expect(await response.text()).toBe("<?php echo 'never executed';\n");
});

test("non-GET methods are rejected with 405", async () => {
  const response = await get(rt, SITE, "/about.html", { method: "DELETE" });
  expect(response.status).toBe(405);
});

test("HEAD returns headers and length without a body", async () => {
  const response = await get(rt, SITE, "/blog/post.html", { method: "HEAD" });
  expect(response.status).toBe(200);
  expect(response.headers.get("content-length")).toBe(String(Buffer.byteLength("<h1>post</h1>\n")));
  expect(await response.text()).toBe("");
});

test("conditional and range requests use precomputed metadata", async () => {
  const first = await get(rt, SITE, "/assets/app.js");
  const etag = first.headers.get("etag") ?? "";
  await first.text();

  const conditional = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-none-match": etag },
  });
  expect(conditional.status).toBe(304);

  const range = await get(rt, SITE, "/assets/app.js", { headers: { range: "bytes=0-6" } });
  expect(range.status).toBe(206);
  expect(await range.text()).toBe("console");
  expect(range.headers.get("content-range")).toMatch(/^bytes 0-6\//);
});

test("If-None-Match honors *, tag lists, and weak comparison", async () => {
  const first = await get(rt, SITE, "/assets/app.js");
  const etag = first.headers.get("etag") ?? "";
  const lastModified = first.headers.get("last-modified") ?? "";
  await first.text();
  expect(etag).toMatch(/^"[a-f0-9]{64}"$/);

  // `*` matches any current representation.
  const wildcard = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-none-match": "*" },
  });
  expect(wildcard.status).toBe(304);

  // A comma-separated list whose members include the current tag still validates.
  const list = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-none-match": `"deadbeef", ${etag}` },
  });
  expect(list.status).toBe(304);

  // Weak comparison: a W/-marked echo of our strong tag validates (RFC 7232 §3.2).
  const weak = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-none-match": `W/${etag}` },
  });
  expect(weak.status).toBe(304);

  // A non-matching tag serves 200 — even when If-Modified-Since would match —
  // because If-None-Match takes precedence (RFC 7232 §3.3).
  const staleTag = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-none-match": '"deadbeef"', "if-modified-since": lastModified },
  });
  expect(staleTag.status).toBe(200);
  await staleTag.text();

  // 304 responses still carry the validators and (SWR-augmented) cache policy.
  expect(wildcard.headers.get("etag")).toBe(etag);
  expect(wildcard.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60",
  );
});

test("If-Modified-Since validates by date, not just exact echo", async () => {
  const first = await get(rt, SITE, "/assets/app.js");
  const lastModified = first.headers.get("last-modified") ?? "";
  await first.text();
  expect(lastModified).not.toBe("");

  const lastModifiedEpoch = Date.parse(lastModified);
  expect(Number.isNaN(lastModifiedEpoch)).toBe(false);

  // A client date strictly after the file's Last-Modified yields 304.
  const future = new Date(lastModifiedEpoch + 60_000).toUTCString();
  const notModified = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-modified-since": future },
  });
  expect(notModified.status).toBe(304);

  // A client date strictly before the file's Last-Modified serves the body.
  const past = new Date(lastModifiedEpoch - 60_000).toUTCString();
  const modified = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-modified-since": past },
  });
  expect(modified.status).toBe(200);
  await modified.text();

  // A syntactically HTTP-date-shaped but self-inconsistent value (wrong weekday
  // for its date) must be rejected — not silently coerced into a later valid
  // date that could manufacture a spurious 304 — so the body is served.
  const wrongWeekday = "Fri, 06 Nov 1994 08:49:37 GMT"; // 06 Nov 1994 was a Sunday
  const malformed = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-modified-since": wrongWeekday },
  });
  expect(malformed.status).toBe(200);
  await malformed.text();

  // A free-form relative date (never a valid HTTP-date) is ignored, not parsed
  // against the server clock.
  const relative = await get(rt, SITE, "/assets/app.js", {
    headers: { "if-modified-since": "tomorrow" },
  });
  expect(relative.status).toBe(200);
  await relative.text();
});

test("unknown hosts get the undeployed platform page", async () => {
  const response = await get(rt, "unknown.test", "/");
  expect(response.status).toBe(503);
});

test("ordinary public paths route without loading Atomic management config", async () => {
  const response = await fetch(`${rt.baseUrl}/index.html`);
  expect(response.status).toBe(503);
});

test("management and upload paths on public hosts are rejected before JWT parsing", async () => {
  const management = await get(rt, SITE, "/__spacefast/api.php/spaces/spc_site/versions", {
    method: "POST",
  });
  expect(management.status).toBe(404);
  const upload = await get(rt, SITE, "/__spacefast/upload.php/upl_x/files/index.html", {
    method: "PUT",
    body: "x",
  });
  expect(upload.status).toBe(404);
});

test("SPA mode serves index.html for unknown routes", async () => {
  await deploy(rt, {
    spaceId: "spc_spa",
    versionId: "ver_spa_1",
    metadata: { title: "SPA" },
    files: { "index.html": "<h1>spa</h1>\n", "main.js": "void 0;\n" },
    serving: {
      config: { fallback: { path: "index.html", status: 200 } },
      // Even an over-broad user rule cannot make the unbounded SPA fallback
      // cache surface immutable. The direct file class remains user-controlled;
      // synthetic fallback/error responses are platform-bounded below.
      headers_pattern: [
        {
          path: "/*",
          regex: "^/(?<splat>.*)$",
          order: 1,
          operations: [
            {
              kind: "set",
              name: "Cache-Control",
              value: "public, max-age=31536000, immutable",
            },
          ],
          headers: { "Cache-Control": "public, max-age=31536000, immutable" },
        },
      ],
    },
    activate: {
      route_name: "production",
      production_hostnames: ["spa.test"],
      version_hostnames: [],
    },
  });

  const fallback = await get(rt, "spa.test", "/client/route/42");
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>spa</h1>\n");
  expect(fallback.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate, stale-while-revalidate=60",
  );
  expect(fallback.headers.get("cdn-cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate, stale-while-revalidate=60",
  );

  const missingAsset = await get(rt, "spa.test", "/main-old.js");
  expect(missingAsset.status).toBe(404);
  expect(await missingAsset.text()).toBe("Not Found\n");
  expect(missingAsset.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );

  // W7.3: a missing path carrying a known binary/document extension (here `.pdf`,
  // which is not in the terminal static-asset set) must 404 — not receive the SPA
  // index shell, which would return HTML with a 200 for a download request.
  const missingDoc = await get(rt, "spa.test", "/files/report.pdf");
  expect(missingDoc.status).toBe(404);
  expect(await missingDoc.text()).toBe("Not Found\n");

  // W7.3: dotted client-side routes (no known asset extension) stay SPA-eligible
  // and still fall through to the index — the guard is a denylist, not "any dot".
  const dottedRoute = await get(rt, "spa.test", "/users/jane.doe");
  expect(dottedRoute.status).toBe(200);
  expect(await dottedRoute.text()).toBe("<h1>spa</h1>\n");

  const asset = await get(rt, "spa.test", "/main.js");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("void 0;\n");
});

test("W7.1 clean URLs default off behind an SPA fallback, explicit config wins", async () => {
  // Default: a 200-status SPA fallback owns extensionless routes — /doc must
  // serve the SPA shell, never resolve the flat doc.html (spec invariant: no
  // implicit extension guessing on SPA sites).
  await deploy(rt, {
    spaceId: "spc_spa_clean",
    versionId: "ver_spa_clean_1",
    files: { "index.html": "<h1>spa shell</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    activate: {
      route_name: "production",
      production_hostnames: ["spa-clean.test"],
      version_hostnames: [],
    },
  });
  const spaDefault = await get(rt, "spa-clean.test", "/doc");
  expect(spaDefault.status).toBe(200);
  expect(await spaDefault.text()).toBe("<h1>spa shell</h1>\n");
  const direct = await get(rt, "spa-clean.test", "/doc.html");
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe("<h1>doc</h1>\n");

  // Explicit clean_urls: true on the same SPA shape re-enables the alias.
  await deploy(rt, {
    spaceId: "spc_spa_clean_on",
    versionId: "ver_spa_clean_on_1",
    files: { "index.html": "<h1>spa shell</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: {
      config: { fallback: { path: "index.html", status: 200 }, clean_urls: true },
    },
    activate: {
      route_name: "production",
      production_hostnames: ["spa-clean-on.test"],
      version_hostnames: [],
    },
  });
  const forcedOn = await get(rt, "spa-clean-on.test", "/doc");
  expect(forcedOn.status).toBe(200);
  expect(await forcedOn.text()).toBe("<h1>doc</h1>\n");
  // Unmatched routes still hit the SPA fallback with the knob on.
  const stillSpa = await get(rt, "spa-clean-on.test", "/client/route");
  expect(stillSpa.status).toBe(200);
  expect(await stillSpa.text()).toBe("<h1>spa shell</h1>\n");
});

test("W7.1 explicit clean_urls: false disables the alias on a plain static site", async () => {
  await deploy(rt, {
    spaceId: "spc_static_clean_off",
    versionId: "ver_static_clean_off_1",
    files: { "index.html": "<h1>home</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { clean_urls: false } },
    activate: {
      route_name: "production",
      production_hostnames: ["static-clean-off.test"],
      version_hostnames: [],
    },
  });
  const off = await get(rt, "static-clean-off.test", "/doc");
  expect(off.status).toBe(404);
  const direct = await get(rt, "static-clean-off.test", "/doc.html");
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe("<h1>doc</h1>\n");
});

test("W7.1 a 404-status fallback keeps clean URLs on by default", async () => {
  // A 404 fallback is a custom not-found page, not SPA routing — clean URLs
  // stay on, and misses still serve the custom 404.
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
      production_hostnames: ["fof-clean.test"],
      version_hostnames: [],
    },
  });
  const clean = await get(rt, "fof-clean.test", "/doc");
  expect(clean.status).toBe(200);
  expect(await clean.text()).toBe("<h1>doc</h1>\n");
  const missing = await get(rt, "fof-clean.test", "/nope");
  expect(missing.status).toBe(404);
  expect(await missing.text()).toBe("<h1>custom 404</h1>\n");
});

test("files mode generates directory listings", async () => {
  await deploy(rt, {
    spaceId: "spc_files",
    versionId: "ver_files_1",
    metadata: { mode: "files", title: "Drop" },
    files: {
      "report.pdf.txt": "report\n",
      "data/items.json": "[]\n",
      "assets/app.js": PRECOMPRESSED_JS,
      "assets/app.js.br": PRECOMPRESSED_JS_BR,
      "standalone.br": Buffer.from("standalone compressed asset"),
      "SF.JSONC": '{ "private": true }\n',
      "SF.JSONC.gz": gzipSync(Buffer.from('{ "private": true }\n')),
      ".SF/CONFIG.JSON": '{ "private": true }\n',
    },
    pageArtifacts: {
      "index-root":
        '<a href="/report.pdf.txt">report.pdf.txt</a><a href="/data/">data</a><a href="/assets/">assets</a><a href="/standalone.br">standalone.br</a>',
      "index-data": '<a href="/data/items.json">items.json</a>',
      "index-assets": '<a href="/assets/app.js">app.js</a>',
    },
    serving: {
      config: {
        pages: {
          routes: {
            "/": { index: "index-root" },
            "/data/": { index: "index-data" },
            "/assets/": { index: "index-assets" },
          },
          previews: {},
        },
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "files" },
      production_hostnames: ["files.test"],
      version_hostnames: [],
    },
  });

  const listing = await get(rt, "files.test", "/", { headers: { accept: "text/html" } });
  expect(listing.status).toBe(200);
  const html = await listing.text();
  expect(html).toContain('href="/report.pdf.txt"');
  expect(html).toContain('href="/data/"');
  expect(html).toContain('href="/assets/"');
  expect(html).toContain('href="/standalone.br"');
  expect(html).not.toContain('href="/SF.JSONC"');
  expect(html).not.toContain('href="/SF.JSONC.gz"');
  expect(html).not.toContain('href="/.SF/"');

  const nested = await get(rt, "files.test", "/data/", { headers: { accept: "text/html" } });
  expect(nested.status).toBe(200);
  expect(await nested.text()).toContain('href="/data/items.json"');

  const assets = await get(rt, "files.test", "/assets/", { headers: { accept: "text/html" } });
  expect(assets.status).toBe(200);
  const assetsHtml = await assets.text();
  expect(assetsHtml).toContain('href="/assets/app.js"');
  expect(assetsHtml).not.toContain('href="/assets/app.js.br"');

  const brotli = await get(rt, "files.test", "/assets/app.js", {
    headers: { "accept-encoding": "br" },
  });
  expect(brotli.status).toBe(200);
  expect(brotli.headers.get("content-encoding")).toBe("br");
  expect(await brotli.text()).toBe(PRECOMPRESSED_JS);
});

test("a single HTML file serves as the index via the lookup rewrite", async () => {
  await deploy(rt, {
    spaceId: "spc_single",
    versionId: "ver_single_1",
    files: {
      "page.html": "<h1>single</h1>\n",
      // Convention files never count toward the single-file inference.
      _redirects: "# none\n",
      "sf.jsonc": "{}\n",
    },
    activate: {
      route_name: "production",
      config: {},
      production_hostnames: ["single.test"],
      version_hostnames: [],
    },
  });

  const root = await get(rt, "single.test", "/");
  expect(root.status).toBe(200);
  expect(await root.text()).toBe("<h1>single</h1>\n");

  const direct = await get(rt, "single.test", "/page.html");
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe("<h1>single</h1>\n");
});

test("W7.1 clean URL does not serve a literal `<name>.html/` directory index", async () => {
  await deploy(rt, {
    spaceId: "spc_htmldir",
    versionId: "ver_htmldir_1",
    files: {
      "index.html": "<h1>root</h1>\n",
      "weird.html/index.html": "<h1>weird dir</h1>\n",
    },
    activate: {
      route_name: "production",
      production_hostnames: ["htmldir.test"],
      version_hostnames: [],
    },
  });

  // The directory's own index is reachable at its directory URL.
  const dir = await get(rt, "htmldir.test", "/weird.html/");
  expect(dir.status).toBe(200);
  expect(await dir.text()).toBe("<h1>weird dir</h1>\n");

  // But `/weird` must NOT clean-URL-resolve to `weird.html/index.html` — there is
  // no flat `weird.html` file — so it 404s instead of serving the directory index.
  const clean = await get(rt, "htmldir.test", "/weird");
  expect(clean.status).toBe(404);
});

test("multi-file versions without index.html keep artifact-style defaults", async () => {
  await deploy(rt, {
    spaceId: "spc_multi",
    versionId: "ver_multi_1",
    files: { "a.html": "<h1>a</h1>\n", "b.html": "<h1>b</h1>\n" },
    pageArtifacts: { "index-root": '<a href="/a.html">a.html</a><a href="/b.html">b.html</a>' },
    serving: { config: { pages: { routes: { "/": { index: "index-root" } }, previews: {} } } },
    activate: {
      route_name: "production",
      config: {},
      production_hostnames: ["multi.test"],
      version_hostnames: [],
    },
  });

  const listing = await get(rt, "multi.test", "/", { headers: { accept: "text/html" } });
  expect(listing.status).toBe(200);
  const html = await listing.text();
  expect(html).toContain('href="/a.html"');
  expect(html).toContain('href="/b.html"');
});

test("robots/noindex host classes are precomputed per hostname", async () => {
  await deploy(rt, {
    spaceId: "spc_robots",
    versionId: "ver_robots_1",
    files: { "index.html": "<h1>robots</h1>\n", "robots.txt": "User-agent: *\nAllow: /\n" },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: ["robots.test", PREVIEW],
      noindex_production_hostnames: [PREVIEW],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: "ver_robots_1" }],
      host_canonical_redirects: [
        { from: WWW, to: "https://robots.test", status: 308 },
        {
          from: "redirect-domain.test",
          to: "https://destination.example/base",
          status: 302,
        },
      ],
    },
  });

  // Canonical production host: indexable, user robots.txt served as-is.
  const canonical = await get(rt, "robots.test", "/");
  expect(canonical.status).toBe(200);
  expect(canonical.headers.get("x-robots-tag")).toBeNull();
  const userRobots = await get(rt, "robots.test", "/robots.txt");
  expect(await userRobots.text()).toBe("User-agent: *\nAllow: /\n");

  // Channel-style noindex host: platform noindex header + disallow-all robots.txt.
  const preview = await get(rt, PREVIEW, "/");
  expect(preview.status).toBe(200);
  expect(preview.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  const previewRobots = await get(rt, PREVIEW, "/robots.txt");
  expect(previewRobots.status).toBe(200);
  expect(await previewRobots.text()).toBe("User-agent: *\nDisallow: /\n");

  // Immutable version host: pinned version, always noindex.
  const version = await get(rt, VERSION_HOST, "/");
  expect(version.status).toBe(200);
  expect(version.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  expect(version.headers.get("x-spacefast-version")).toBe("ver_robots_1");
  const versionRobots = await get(rt, VERSION_HOST, "/robots.txt");
  expect(await versionRobots.text()).toBe("User-agent: *\nDisallow: /\n");

  // Host canonicalization: 308 redirect preserving the request path.
  const redirected = await get(rt, WWW, "/blog/post.html");
  expect(redirected.status).toBe(308);
  expect(redirected.headers.get("location")).toBe("https://robots.test/blog/post.html");

  const domainRedirect = await get(rt, "redirect-domain.test", "/blog/post.html");
  expect(domainRedirect.status).toBe(302);
  expect(domainRedirect.headers.get("location")).toBe(
    "https://destination.example/base/blog/post.html",
  );
});

test("finalize rejects proxy rules targeting internal or non-public upstreams", async () => {
  const upstreams = [
    "https://127.0.0.1/api",
    "https://169.254.169.254/latest/meta-data",
    "https://api.spacefast.com/v1",
    "https://wpc-manage-site.view.fast/x",
    "https://localhost/api",
  ];
  for (const [index, upstream] of upstreams.entries()) {
    const versionId = `ver_proxy_${index}`;
    const response = await finalizeRaw(
      rt,
      "spc_proxy",
      versionId,
      { "index.html": "<h1>proxy</h1>\n" },
      {
        serving: {
          redirects_exact: {
            "/api": [{ destination: upstream, status: 200, action: "proxy", order: 1 }],
          },
        },
      },
    );
    expect(response.status, upstream).toBe(422);
    expect(await errorCode(response)).toBe("runtime_artifact_validation_failed");
  }
});

test("finalize rejects private config targets in fallbacks and rewrites", async () => {
  const files = {
    "index.html": "<h1>private target</h1>\n",
    "SF.JSONC": "private config\n",
  };
  const [fallback, rewrite] = await Promise.all([
    finalizeRaw(rt, "spc_private_fallback", "ver_private_fallback_1", files, {
      serving: { config: { fallback: { path: "SF.JSONC", status: 200 } } },
    }),
    finalizeRaw(rt, "spc_private_rewrite", "ver_private_rewrite_1", files, {
      serving: {
        redirects_exact: {
          "/leak": [{ destination: "/SF.JSONC", status: 200, action: "rewrite", order: 1 }],
        },
      },
    }),
  ]);

  expect([fallback.status, rewrite.status]).toEqual([422, 422]);
  expect(await errorCode(fallback)).toBe("invalid_serving_config");
  expect(await errorCode(rewrite)).toBe("runtime_artifact_validation_failed");
});

test("repair-space clears opcache invalidation repair state after rebuilding routes", async () => {
  const phpIniRoot = mkdtempSync(path.join(os.tmpdir(), "stattic-opcache-api-denied-"));
  const opcacheProbePath = path.join(phpIniRoot, "probe.php");
  const deniedApiPrefix = "/__spacefast_opcache_api_denied__/";
  // PHP does not guarantee that opcache_invalidate() returns false merely
  // because OPcache is disabled. Deny the API by caller path so every PHP
  // build exercises the repair-state branch deterministically.
  writeFileSync(
    opcacheProbePath,
    "<?php echo function_exists('opcache_invalidate') && @opcache_invalidate(__FILE__, true) === false ? 'denied' : 'available';\n",
  );
  const opcacheProbe = Bun.spawnSync({
    cmd: ["php", "-d", `opcache.restrict_api=${deniedApiPrefix}`, opcacheProbePath],
    env: process.env,
  });
  expect(
    opcacheProbe.stdout.toString(),
    "PHP must load Zend OPcache and deny its API for this test to exercise invalidation failure",
  ).toBe("denied");
  let repairRuntime: Runtime | undefined;
  const spaceId = "spc_opcache_repair";
  try {
    repairRuntime = await startRuntime({
      phpIni: { "opcache.restrict_api": deniedApiPrefix },
    });
    await deploy(repairRuntime, {
      spaceId,
      versionId: "ver_opcache_repair_1",
      files: { "index.html": "repair me" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: ["opcache-repair.test"],
        version_hostnames: [],
      },
    });

    const repairStatePath = path.join(repairRuntime.storageRoot, "runtime/repair-state.json");
    const repairState = JSON.parse(readFileSync(repairStatePath, "utf8")) as {
      code?: unknown;
      details?: { path?: unknown };
    };
    expect(repairState.code).toBe("opcache_invalidation_failed");
    expect(typeof repairState.details?.path).toBe("string");

    const journalPath = path.join(repairRuntime.storageRoot, "runtime/journal.jsonl");
    const failuresBeforeRepair = readFileSync(journalPath, "utf8")
      .trim()
      .split("\n")
      .map((line) => JSON.parse(line) as { code?: unknown; event?: unknown })
      .filter(
        (entry) =>
          entry.event === "runtime_repair_required" && entry.code === "opcache_invalidation_failed",
      ).length;

    const repaired = await api(
      repairRuntime,
      "POST",
      `/__spacefast/api.php/spaces/${spaceId}/repair`,
      "repair_space",
      { space_id: spaceId },
    );
    const failuresAfterRepair = readFileSync(journalPath, "utf8")
      .trim()
      .split("\n")
      .map((line) => JSON.parse(line) as { code?: unknown; event?: unknown })
      .filter(
        (entry) =>
          entry.event === "runtime_repair_required" && entry.code === "opcache_invalidation_failed",
      ).length;
    expect(
      failuresAfterRepair - failuresBeforeRepair,
      "the repair rebuild must exercise a fresh OPcache invalidation failure",
    ).toBeGreaterThan(0);
    expect(repaired.status).toBe(200);
    expect(await repaired.json()).toEqual({ space_id: spaceId, status: "repaired" });
    expect(existsSync(repairStatePath)).toBe(false);
  } finally {
    repairRuntime?.stop();
    rmSync(phpIniRoot, { recursive: true, force: true });
  }
});

test("public requests load only the retained modules needed for their request class", async () => {
  const moduleRuntime = await startRuntime();
  const spaceId = "spc_lazy_modules";
  const versionId = "ver_lazy_modules_1";
  const host = "lazy-modules.test";
  let instrumentedRoot: string | undefined;
  let instrumentedServer: ChildProcessWithoutNullStreams | undefined;
  try {
    await deploy(moduleRuntime, {
      spaceId,
      versionId,
      files: {
        "index.html": "static module path",
        "assets/tiered.txt": "tier module path",
      },
      serving: {
        redirects_exact: {
          "/proxy": [
            {
              destination: "https://example.com/api",
              status: 200,
              action: "proxy",
              disabled: true,
              disabledReason: "proxy disabled for module test",
              order: 1,
            },
          ],
        },
      },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [host],
        version_hostnames: [],
      },
    });

    const tieredPath = "assets/tiered.txt";
    const shardPath = path.join(
      moduleRuntime.storageRoot,
      "spaces",
      spaceId,
      "versions",
      versionId,
      "file-shards",
      `${sha256(tieredPath).slice(0, 2)}.php`,
    );
    const rewritten = Bun.spawnSync([
      "php",
      "-r",
      '$f=include $argv[1];$f["files"][$argv[2]]["local"]=false;$f["files"][$argv[2]]["remote"]=["bucket"=>"missing-test-bucket","key"=>"missing-test-key","enc"=>"identity"];file_put_contents($argv[1],"<?php\\nreturn ".var_export($f,true).";\\n");',
      shardPath,
      tieredPath,
    ]);
    expect(rewritten.exitCode).toBe(0);
    rmSync(
      path.join(
        moduleRuntime.storageRoot,
        "spaces",
        spaceId,
        "versions",
        versionId,
        "files",
        tieredPath,
      ),
    );

    // Resolved (realpathSync) because enginePrefix below is compared against
    // get_included_files() output, which PHP reports as resolved real paths —
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

    const port = await freePort();
    const instrumentedBaseUrl = `http://127.0.0.1:${port}`;
    instrumentedServer = spawn(
      "php",
      [
        "-d",
        "opcache.enable_cli=0",
        "-d",
        `auto_prepend_file=${testPrepend}`,
        "-S",
        `127.0.0.1:${port}`,
        instrumentedRouter,
      ],
      { cwd: instrumentedRoot, stdio: "pipe", env: process.env },
    );
    await waitForPhpServer(instrumentedServer);
    const health = await fetch(`${instrumentedBaseUrl}/__spacefast/health.php`);
    expect(health.status).toBe(200);
    const instrumentedRuntime = {
      baseUrl: instrumentedBaseUrl,
      root: instrumentedRoot,
      storageRoot: path.join(instrumentedRoot, ".stattic/storage"),
      stop: () => undefined,
    };

    const staticResponse = await get(instrumentedRuntime, host, "/");
    expect(staticResponse.status).toBe(200);
    expect(await staticResponse.text()).toBe("static module path");
    const proxyResponse = await get(instrumentedRuntime, host, "/proxy");
    expect(proxyResponse.status).toBe(403);
    const tierResponse = await get(instrumentedRuntime, host, `/${tieredPath}`);
    expect(tierResponse.status).toBe(503);

    const enginePrefix = path.join(instrumentedRoot, ".stattic/engine/");
    // The instrumentation record is appended by a shutdown handler, which runs
    // strictly AFTER the response bytes reach the client (and after the
    // engine's own post-response deferred work), so an awaited fetch() does
    // not imply its record has landed yet — and a concurrent append can leave
    // a partial trailing line. Poll until every probed URI has a record,
    // tolerating not-yet-complete lines, instead of reading the log once.
    type IncludedRecord = { uri: string; files: string[] };
    const probedUris = ["/", "/proxy", `/${tieredPath}`];
    let records: IncludedRecord[] = [];
    const logDeadline = Date.now() + 10_000;
    for (;;) {
      records = (existsSync(includedLog) ? readFileSync(includedLog, "utf8") : "")
        .split("\n")
        .flatMap((line): IncludedRecord[] => {
          if (line === "") return [];
          try {
            return [JSON.parse(line) as IncludedRecord];
          } catch {
            return []; // mid-append partial line; complete on a later poll
          }
        });
      if (probedUris.every((uri) => records.some((record) => record.uri === uri))) {
        break;
      }
      if (Date.now() > logDeadline) {
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
        .toSorted();

    expect(modulesFor("/")).toEqual(
      [
        "init.php",
        "runtime/php-manifest.php",
        "runtime/serve.php",
        "shared/admission.php",
        "shared/artifacts.php",
        "shared/context.php",
        "shared/safety.php",
        "shared/storage.php",
      ].toSorted(),
    );
    expect(modulesFor("/proxy")).toEqual(
      [
        "init.php",
        "runtime/php-manifest.php",
        "runtime/proxy.php",
        "runtime/redirects.php",
        "runtime/rules.php",
        "runtime/serve.php",
        "shared/admission.php",
        "shared/artifacts.php",
        "shared/context.php",
        "shared/egress.php",
        "shared/errors.php",
        "shared/safety.php",
        "shared/storage.php",
      ].toSorted(),
    );
    expect(modulesFor(`/${tieredPath}`)).toEqual(
      [
        "init.php",
        "runtime/php-manifest.php",
        "runtime/serve.php",
        "runtime/tier.php",
        "shared/admission.php",
        "shared/artifacts.php",
        "shared/bootstrap-config.php",
        "shared/context.php",
        "shared/errors.php",
        "shared/s3.php",
        "shared/safety.php",
        "shared/storage.php",
      ].toSorted(),
    );
  } finally {
    instrumentedServer?.kill();
    if (instrumentedRoot) rmSync(instrumentedRoot, { recursive: true, force: true });
    moduleRuntime.stop();
  }
});

test("plan-disabled external proxy rules render the platform restriction page", async () => {
  await deploy(rt, {
    spaceId: "spc_proxy_off",
    versionId: "ver_proxy_off_1",
    files: { "index.html": "<h1>proxy off</h1>\n" },
    serving: {
      redirects_exact: {
        "/api": [
          {
            destination: "https://api.example.com/v1",
            status: 200,
            action: "proxy",
            disabled: true,
            disabledReason: "External proxying is not available on this plan.\n",
            order: 1,
          },
        ],
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: ["proxyoff.test"],
      version_hostnames: [],
    },
  });

  const restricted = await get(rt, "proxyoff.test", "/api");
  expect(restricted.status).toBe(403);
  expect(await restricted.text()).toBe("External proxying is not available on this plan.\n");

  // The rest of the space serves normally.
  expect((await get(rt, "proxyoff.test", "/")).status).toBe(200);
});

test("tombstoned hostnames return the removed platform page", async () => {
  await deploy(rt, {
    spaceId: "spc_tomb",
    versionId: "ver_tomb_1",
    files: { "index.html": "<h1>tomb</h1>\n" },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: ["tomb.test"],
      version_hostnames: [],
    },
  });
  const response = await api(
    rt,
    "PUT",
    "/__spacefast/api.php/spaces/spc_tomb/tombstones",
    "update_tombstones",
    { space_id: "spc_tomb" },
    { hostnames: ["gone.tomb.test"], mode: "replace" },
  );
  expect(response.status).toBe(200);

  const tombstoned = await get(rt, "gone.tomb.test", "/");
  expect(tombstoned.status).toBe(404);
  expect(tombstoned.headers.get("x-robots-tag")).toBe("noindex, nofollow");

  // The served hostname is unaffected.
  const live = await get(rt, "tomb.test", "/");
  expect(live.status).toBe(200);
});
