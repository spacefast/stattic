// Which bytes answer which URL, on the schema-v4 engine.
//
// v4 compiles every answer at finalize (contracts §5): one response table keyed
// by request path, plus the reserved keys the serve path probes when no exact
// key matches — `\0spa`, `\0404:<dir>`/`\0404`, `\0rules`, `\0robots`. Routing is
// therefore "which key answers, and what did the ordered residue do first",
// which is what this file tests.
//
// Deliberately NOT here, because another suite owns the seam:
//   * cache-control classes, the `A8C-Edge-Cache` opt-in, `_headers` rules,
//     noindex/robots.txt and the accel lane -> headers.test.ts;
//   * the agent-detection table itself -> agent-detection.test.ts (this file
//     only proves that a conditional rule selects a representation);
//   * access decisions and protected-space policy -> access-rules.test.ts;
//   * Zero and Functions execution -> zero-runtime.test.ts, functions-*.test.ts.
//
// Gone with the mechanisms they described (§15/§16): compression negotiation and
// sidecar selection (nginx compresses both lanes on the fly; PHP never sends
// Content-Encoding), Range/206/416, and conditional 304 handling — the platform
// never delivers If-None-Match/If-Modified-Since/Range to the origin, and the
// edge answers conditionals off its own HIT. The ETag is still EMITTED per entry
// and asserted below; nothing in PHP compares it.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn, type ChildProcessWithoutNullStreams } from "node:child_process";
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
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { gzipSync } from "node:zlib";

import {
  api,
  apiJson,
  blobGateToken,
  deploy,
  errorCode,
  finalizeRaw,
  get,
  getBlob,
  publicAccessConfig,
  putRoute,
  RESPONSES,
  responseTableFiles,
  RUNTIME_HTTP_API_BASE,
  RUNTIME_TEST_ATOMIC_PREPEND,
  RUNTIME_TEST_ROUTER,
  sha256,
  storagePath,
  type Runtime,
  startRuntime,
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
const GUIDE_HTML = "<h1>browser guide</h1>\n";
const GUIDE_MD = "# agent guide\n";
// A gzip member under an extension nothing has a MIME for: finalize sniffs the
// magic bytes so the response describes the bytes, and no lane invents a
// Content-Encoding for them.
const GZIP_PAYLOAD = Buffer.from([
  0x1f, 0x8b, 0x08, 0x00, 0x70, 0x61, 0x79, 0x6c, 0x6f, 0x61, 0x64,
]);

const BROWSER_HEADERS = {
  accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
  "user-agent":
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
};
const AGENT_HEADERS = { accept: "*/*", "user-agent": "curl/8.5.0" };

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
      "café.html": "<h1>café</h1>\n",
      "404.html": ROOT_404,
      "docs/index.html": "<h1>docs</h1>\n",
      "blog/404.html": BLOG_404,
      "blog/post.html": POST,
      "assets/app.js": APP_JS,
      // A published `.gz` is an ordinary file at its own URL: v4 compiles no
      // sidecar relationship and never negotiates an encoding.
      "assets/app.js.gz": APP_JS_GZIP,
      "pagefind/index.pf_index": GZIP_PAYLOAD,
      "yield/keep.txt": "kept\n",
      "guide.html": GUIDE_HTML,
      "guide.md": GUIDE_MD,
      "inert.php": "<?php echo 'never executed';\n",
      _headers: [
        "/inert.php",
        "  Content-Type: text/html; charset=utf-8",
        "  ! Content-Disposition",
      ].join("\n"),
      // Uppercase near-misses of the convention names are ordinary content.
      _HEADERS: "ordinary uppercase file\n",
      "SF.JSONC": '{ "private": true }\n',
      "SF.JSONC.gz": gzipSync(Buffer.from('{ "private": true }\n')),
      ".well-known/security.txt": "Contact: mailto:security@site.test\n",
      // The fixture's only redirect input: native finalize parses it and lowers
      // it into compiled keys plus the ordered `\0rules` residue.
      _redirects: [
        "/old /about.html 301",
        "/found /about.html 302",
        "/docs-redirect /docs/ 302",
        // Forced: the target path is a committed file and the rewrite still wins.
        "/about.html /index.html 200!",
        "/unicode-nfd /café.html 200!",
        "/unicode-encoded /caf%C3%A9.html 200!",
        // Non-forced: yields wherever bytes exist, applies where they do not.
        "/yield/* /index.html 200",
        "/gone/* /blog/404.html 404",
        // One path family with two representations, chosen per visitor.
        "/guide/* /guide.md 200! Agent=true",
        "/guide/* /guide.html 200!",
        `/preserve/* ${redirectReceiverUrl("/receiver")} 307`,
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

// The version is its root pointer, the content-addressed root it names, and the
// tables that root names (§5). One table until the 512 KiB split, and the serve
// path validates the root's schema and nothing else (D4/D86).
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
  // Compiled once at finalize and quoted exactly once at send time (D121/D132).
  expect(response.headers.get("etag")).toBe(`"${sha256(APP_JS)}"`);
  expect(response.headers.get("x-content-type-options")).toBe("nosniff");
  expect(response.headers.get("x-spacefast-version")).toBe("ver_site_1");
  // Nothing in this runtime compresses or negotiates: no sidecar is consulted,
  // and no lane invents a Content-Encoding.
  expect(response.headers.get("content-encoding")).toBeNull();
});

test("compressed uploads are served as the bytes they are, never as an encoding", async () => {
  // A `.gz` published beside its source is a file, not a representation of one.
  const sidecar = await get(rt, SITE, "/assets/app.js.gz");
  expect(sidecar.status).toBe(200);
  expect(sidecar.headers.get("content-type")).toBe("application/gzip");
  expect(sidecar.headers.get("content-encoding")).toBeNull();
  expect(new Uint8Array(await sidecar.arrayBuffer())).toEqual(new Uint8Array(APP_JS_GZIP));

  // Its source keeps serving identity bytes whatever the client accepts.
  const source = await get(rt, SITE, "/assets/app.js", {
    headers: { "accept-encoding": "br, gzip" },
  });
  expect(source.headers.get("content-encoding")).toBeNull();
  expect(await source.text()).toBe(APP_JS);

  // Gzip magic under an extension no MIME table knows: the sniffed type
  // describes the bytes, and it is still not an encoding.
  const opaque = await get(rt, SITE, "/pagefind/index.pf_index");
  expect(opaque.status).toBe(200);
  expect(opaque.headers.get("content-type")).toBe("application/gzip");
  expect(opaque.headers.get("content-encoding")).toBeNull();
});

test("index and directory URLs resolve to their compiled keys", async () => {
  expect(await (await get(rt, SITE, "/")).text()).toBe(INDEX);
  expect(await (await get(rt, SITE, "/docs/")).text()).toBe("<h1>docs</h1>\n");

  // W7.2: `/docs` resolves to `docs/index.html`, so canonicalize to the
  // trailing-slash form — relative links in the document resolve against the
  // directory, not the root.
  const canonical = await get(rt, SITE, "/docs");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("/docs/");
});

test("W7.1 clean URLs serve <path>.html and canonicalize the slashed form", async () => {
  // `blog/post.html` has no directory-index form; an extensionless request must
  // still serve those bytes (Hugo uglyURLs / Jekyll / hand-written flat HTML).
  const clean = await get(rt, SITE, "/blog/post");
  expect(clean.status).toBe(200);
  expect(await clean.text()).toBe(POST);

  const slashed = await get(rt, SITE, "/blog/post/");
  expect(slashed.status).toBe(308);
  expect(slashed.headers.get("location")).toBe("/blog/post");
});

test("the nearest custom 404 answers, walking up from the requested directory", async () => {
  // `\0404:blog` for anything under /blog, `\0404` for everything else.
  const nested = await get(rt, SITE, "/blog/missing");
  expect(nested.status).toBe(404);
  expect(await nested.text()).toBe(BLOG_404);

  const root = await get(rt, SITE, "/missing");
  expect(root.status).toBe(404);
  expect(await root.text()).toBe(ROOT_404);

  // A `404!` rule names its own document and keeps the rewritten status.
  const ruled = await get(rt, SITE, "/gone/forever");
  expect(ruled.status).toBe(404);
  expect(await ruled.text()).toBe(BLOG_404);
});

test("convention and config files are never served, under any spelling", async () => {
  // Committed content that compiles to a not-found action: the miss is
  // indistinguishable from any other, so nothing confirms the file exists.
  for (const requestPath of ["/_redirects", "/%5Fredirects", "/SF.JSONC", "/SF.JSONC.gz"]) {
    const response = await get(rt, SITE, requestPath);
    expect(response.status, requestPath).toBe(404);
    expect(await response.text(), requestPath).toBe(ROOT_404);
  }

  // An uppercase near-miss is ordinary content, not a convention file.
  const uppercase = await get(rt, SITE, "/_HEADERS");
  expect(uppercase.status).toBe(200);
  expect(await uppercase.text()).toBe("ordinary uppercase file\n");
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

// Path identity is NFC, decided once at intake: two spellings of the same name
// are the same file, so the manifest may not carry both and either spelling must
// answer the same bytes at serve time.
test("path identity is NFC at both intake and serve time", async () => {
  const nfc = "café.html"; // U+00E9
  const nfd = "café.html"; // e + U+0301
  const content = "<h1>cafe</h1>\n";

  // Declared, not finalized: path policy is enforced once, where the manifest
  // enters the runtime.
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

  // Declared as NFD, addressable by either spelling.
  for (const requestForm of [nfc, nfd]) {
    const response = await get(rt, "nfc.test", `/${encodeURIComponent(requestForm)}`);
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

  const response = await get(rt, host, `/${filePath}`);
  expect(response.status).toBe(200);
  expect(await response.text()).toBe(contents);
});

test("php-like uploads are inert text, never executed", async () => {
  const response = await get(rt, SITE, "/inert.php");
  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  // D135: the header that survives X-Accel-Redirect and still stops a browser
  // treating tenant bytes as a page, since nginx drops nosniff.
  expect(response.headers.get("content-disposition")).toBe("attachment");
  expect(await response.text()).toBe("<?php echo 'never executed';\n");
});

test("exact redirects answer from their compiled key", async () => {
  const permanent = await get(rt, SITE, "/old");
  expect(permanent.status).toBe(301);
  expect(permanent.headers.get("location")).toBe("/about.html");

  const temporary = await get(rt, SITE, "/found");
  expect(temporary.status).toBe(302);
  expect(temporary.headers.get("location")).toBe("/about.html");

  const directory = await get(rt, SITE, "/docs-redirect");
  expect(directory.status).toBe(302);
  expect(directory.headers.get("location")).toBe("/docs/");
});

test("ordered redirects preserve the request query and RFC method semantics", async () => {
  // The ordered lane is a pure function of host+path+query, so the query it did
  // not consume travels to the destination.
  const withQuery = await get(rt, SITE, "/preserve/resource?ref=1");
  expect(withQuery.status).toBe(307);
  expect(withQuery.headers.get("location")).toBe(`${redirectReceiverUrl("/receiver")}?ref=1`);

  // 307 preserves the method AND the body; the receiver reports what arrived.
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

  // A method no visitor lane accepts is refused by the rule that matched, and
  // the refusal advertises every method that could have selected the redirect.
  const rejected = await get(rt, SITE, "/preserve/blocked", { method: "TRACE" });
  expect(rejected.status).toBe(405);
  expect(rejected.headers.get("allow")).toBe("GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS");
  expect(rejected.headers.get("location")).toBeNull();
});

test("forced rewrites override committed bytes; non-forced ones yield to them", async () => {
  // `/about.html` is a committed file, and the rule is forced.
  const forced = await get(rt, SITE, "/about.html");
  expect(forced.status).toBe(200);
  expect(await forced.text()).toBe(INDEX);

  // Same pattern, not forced: bytes win where they exist...
  const existing = await get(rt, SITE, "/yield/keep.txt");
  expect(existing.status).toBe(200);
  expect(await existing.text()).toBe("kept\n");

  // ...and the rewrite applies where they do not.
  const missing = await get(rt, SITE, "/yield/absent.txt");
  expect(missing.status).toBe(200);
  expect(await missing.text()).toBe(INDEX);

  // A rewrite target resolves through the same NFC path identity as a request,
  // whether the rule spelled it decomposed or percent-encoded.
  for (const requestPath of ["/unicode-nfd", "/unicode-encoded"]) {
    const unicode = await get(rt, SITE, requestPath);
    expect(unicode.status, requestPath).toBe(200);
    expect(await unicode.text(), requestPath).toBe("<h1>café</h1>\n");
  }
});

test("a conditional rule picks one representation per visitor class", async () => {
  // Markdown goes to agents and HTML goes to browsers.
  // (Which requests count as agents is agent-detection.test.ts's table.)
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
    // Two bodies at one URL that the edge keys without Vary: unstorable.
    expect(agent.headers.get("cache-control"), requestPath).toBe("no-store");
    expect(agent.headers.get("vary"), requestPath).toContain("Accept");
    expect(agent.headers.get("vary"), requestPath).toContain("User-Agent");
  }

  // The catchers are segment-bounded, so a slug that merely shares the prefix
  // falls through to ordinary routing — here, the root 404.
  for (const slug of ["/guide-team", "/guides", "/guide-team/~/settings"]) {
    const response = await get(rt, SITE, slug, { headers: BROWSER_HEADERS });
    expect(response.status, slug).toBe(404);
    expect(await response.text(), slug).toBe(ROOT_404);
  }

  // The catchers claim `/guide/…` and nothing else, so the source document
  // keeps serving its own bytes at its own URL, to every audience.
  const direct = await get(rt, SITE, "/guide.md", { headers: BROWSER_HEADERS });
  expect(direct.status).toBe(200);
  expect(await direct.text()).toBe(GUIDE_MD);
});

test("static entries answer GET and HEAD only", async () => {
  const rejected = await get(rt, SITE, "/blog/post.html", { method: "DELETE" });
  expect(rejected.status).toBe(405);
  expect(rejected.headers.get("allow")).toBe("GET, HEAD");

  const head = await get(rt, SITE, "/blog/post.html", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(Buffer.byteLength(POST)));
  expect(await head.text()).toBe("");
});

test("host classes: canonical redirects, and a version-pinned host serves its version", async () => {
  // Host canonicalization keeps the path and the query.
  const canonical = await get(rt, WWW, "/blog/post.html");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("https://site.test/blog/post.html");
  // No version was selected, so the response names none.
  expect(canonical.headers.get("x-spacefast-version")).toBeNull();

  const renamed = await get(rt, "renamed.test", "/blog/post.html?from=old&view=full");
  expect(renamed.status).toBe(307);
  expect(renamed.headers.get("location")).toBe(
    "https://site.test/blog/post.html?from=old&view=full",
  );

  // A redirect to another domain keeps the destination's own base path.
  const domain = await get(rt, "redirect-domain.test", "/blog/post.html");
  expect(domain.status).toBe(302);
  expect(domain.headers.get("location")).toBe("https://destination.example/base/blog/post.html");

  // A version host names its version outright instead of following the route.
  const pinned = await get(rt, VERSION_HOST, "/");
  expect(pinned.status).toBe(200);
  expect(await pinned.text()).toBe(INDEX);
  expect(pinned.headers.get("x-spacefast-version")).toBe("ver_site_1");
});

test("unknown hosts get the undeployed platform page", async () => {
  const response = await get(rt, "unknown.test", "/");
  expect(response.status).toBe(503);
});

test("the primary hostname serves content while runtime APIs remain authenticated", async () => {
  const content = await get(rt, SITE, "/index.html");
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

  const fallback = await get(rt, "spa.test", "/client/route/42");
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>spa</h1>\n");

  // W7.3: the fallback is an application-route shell, not a catch-all asset
  // server. A missing path carrying a known asset or document extension must
  // 404 rather than hand a download request an HTML 200.
  for (const requestPath of ["/main-old.js", "/files/report.pdf"]) {
    const missing = await get(rt, "spa.test", requestPath);
    expect(missing.status, requestPath).toBe(404);
    expect(await missing.text(), requestPath).toContain("Page not found");
  }

  // ...but the guard is a denylist, not "any dot": dotted client-side routes
  // stay SPA-eligible.
  const dotted = await get(rt, "spa.test", "/users/jane.doe");
  expect(dotted.status).toBe(200);
  expect(await dotted.text()).toBe("<h1>spa</h1>\n");

  // A residual dot segment is ambiguous to a deeper decoder, so its miss must
  // never become a shared 404.
  for (let depth = 2; depth <= 4; depth++) {
    const encodedDot = `%${"25".repeat(depth)}2e`;
    const ambiguous = await get(rt, "spa.test", `/a/${encodedDot}${encodedDot}/x.js`);
    expect(ambiguous.status, `depth ${depth}`).toBe(404);
    expect(ambiguous.headers.get("cache-control"), `depth ${depth}`).toBe("no-store");
  }

  const asset = await get(rt, "spa.test", "/main.js");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("void 0;\n");
});

// A configured 200 fallback IS the version's homepage declaration, so `/` is the
// first application route the shell owns — not a directory the runtime may
// infer a file browser for. A dashboard build that emitted `_shell.html` plus a
// second root-level HTML file and no `index.html` served `<title>Files</title>`
// at `/` while every deep route correctly served the shell.
test("a configured SPA fallback owns `/` when no root index can be inferred", async () => {
  const shell = "<h1>app shell</h1>\n";
  await deploy(rt, {
    spaceId: "spc_spa_root",
    versionId: "ver_spa_root_1",
    files: { "_shell.html": shell, "secondary.html": "<h1>secondary</h1>\n" },
    serving: { config: { fallback: { path: "_shell.html", status: 200 } } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["spa-root.test"],
      version_hostnames: [],
    },
  });

  const home = await get(rt, "spa-root.test", "/", { headers: { accept: "text/html" } });
  expect(home.status).toBe(200);
  expect(await home.text()).toBe(shell);

  // The deep routes that already worked keep working, and the second root
  // document is still reachable at its own path.
  expect(await (await get(rt, "spa-root.test", "/client/route/42")).text()).toBe(shell);
  expect(await (await get(rt, "spa-root.test", "/secondary.html")).text()).toBe(
    "<h1>secondary</h1>\n",
  );
});

test("W7.1 a 200 SPA fallback owns extensionless routes unless clean URLs are asked for", async () => {
  // Default: no implicit extension guessing on an SPA site — /doc is a client
  // route, not a probe for doc.html.
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
  expect(await (await get(rt, "spa-clean.test", "/doc")).text()).toBe("<h1>spa shell</h1>\n");
  expect(await (await get(rt, "spa-clean.test", "/doc.html")).text()).toBe("<h1>doc</h1>\n");

  // Explicit clean_urls re-enables the alias on the same shape, and unmatched
  // routes still reach the shell.
  await deploy(rt, {
    spaceId: "spc_spa_clean_on",
    versionId: "ver_spa_clean_on_1",
    files: { "index.html": "<h1>spa shell</h1>\n", "doc.html": "<h1>doc</h1>\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 }, clean_urls: true } },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["spa-clean-on.test"],
      version_hostnames: [],
    },
  });
  expect(await (await get(rt, "spa-clean-on.test", "/doc")).text()).toBe("<h1>doc</h1>\n");
  expect(await (await get(rt, "spa-clean-on.test", "/client/route")).text()).toBe(
    "<h1>spa shell</h1>\n",
  );
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

  // A 404-status fallback is a custom not-found page, not SPA routing, so clean
  // URLs stay on by default and misses still serve the custom document.
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

  // There is no flat `weird.html` file, so `/weird` has no key to alias.
  expect((await get(rt, "htmldir.test", "/weird")).status).toBe(404);
});

test("a version with no index compiles a directory listing per directory", async () => {
  await deploy(rt, {
    spaceId: "spc_files",
    versionId: "ver_files_1",
    metadata: { mode: "files", title: "Drop" },
    files: {
      "report.pdf.txt": "report\n",
      "notes.md": "# notes\n",
      "data/items.json": "[]\n",
      "SF.JSONC": '{ "private": true }\n',
      ".SF/CONFIG.JSON": '{ "private": true }\n',
    },
    // A version with no root index infers files mode anyway; state it so the
    // fixture cannot drift with the inference rules of another suite.
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
  // Private configuration is not content, in the listing or anywhere else.
  expect(html).not.toContain('href="/SF.JSONC"');
  expect(html).not.toContain('href="/.SF/"');
  // Compiled listing order: every subdirectory first, then files alphabetically.
  expect(html.indexOf('href="/data/"')).toBeLessThan(html.indexOf('href="/notes.md"'));
  expect(html.indexOf('href="/notes.md"')).toBeLessThan(html.indexOf('href="/report.pdf.txt"'));

  const nested = await get(rt, "files.test", "/data/", { headers: { accept: "text/html" } });
  expect(nested.status).toBe(200);
  expect(await nested.text()).toContain('href="/data/items.json"');

  // The listing route canonicalizes its slashless form like any directory.
  const canonical = await get(rt, "files.test", "/data");
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe("/data/");
});

// `index: false` turns off the *inferred* index, not the publisher's own files:
// a real index.html is still the document for its directory, at every depth.
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
      // Convention files never count toward the single-file inference.
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
  expect(await (await get(rt, "single.test", "/page.html")).text()).toBe("<h1>single</h1>\n");
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
      // A content-hashed name under the claimed subtree: its bytes are the
      // same in and out of a draft session, so the bypass must not touch it.
      "api/app.3f9c2d1a.js": "void 0;",
      // Committed bytes AND a Functions route at another method on one path:
      // the method-rule assertions below need both lanes to own it.
      "hybrid.txt": "hybrid bytes",
    },
    functions: {
      artifact: {
        appName: "functions-routes",
        entry: "worker.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        // A draft/preview session is named by cookie: a request carrying one of
        // these must reach the worker even where the page was extracted to disk.
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
      config: publicAccessConfig({ mode: "website", site_title: "Functions routes" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // A matched route dispatches. The 503 is the edge host being unreachable by
  // design, which is what proves dispatch was entered at all.
  for (const requestPath of ["/api", "/api/users/1"]) {
    const matched = await get(rt, host, requestPath);
    expect(matched.status, requestPath).toBe(503);
    expect(await matched.text(), requestPath).toContain("Function execution is not available.");
  }
  expect((await get(rt, host, "/submit", { method: "POST" })).status).toBe(503);

  // Committed bytes under a covering route still win: they are a table key.
  const asset = await get(rt, host, "/api/static.txt");
  expect(asset.status).toBe(200);
  expect(await asset.text()).toBe("asset wins");
  // The provider's shared cache ignores Cookie/Vary and resolves before PHP.
  // This ordinary response therefore cannot warm the URL: doing so would make
  // the draft-cookie dispatch below unreachable on the next request.
  expect(asset.headers.get("cache-control")).toBe("no-store");
  expect(asset.headers.get("a8c-edge-cache")).toBe("no-cache");

  // A content-addressed entry is carved out of the bypass on both sides: its
  // bytes are identical in and out of a draft session, so a whole-site
  // middleware claim costs it neither its immutable year nor its edge copy.
  const hashed = await get(rt, host, "/api/app.3f9c2d1a.js");
  expect(hashed.status).toBe(200);
  expect(hashed.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect(hashed.headers.get("a8c-edge-cache")).toBe("cache");
  // ...and a draft session keeps answering from disk instead of dispatching.
  const hashedDraft = await get(rt, host, "/api/app.3f9c2d1a.js", {
    headers: { Cookie: "__prerender_bypass=1" },
  });
  expect(hashedDraft.status).toBe(200);
  expect(await hashedDraft.text()).toBe("void 0;");

  // Failure is not "no bypass": if the sidecar exists but cannot be read, an
  // ordinary response must remain uncacheable or it can permanently hide a
  // later draft cookie from PHP at this URL.
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

  // ...unless the request is a draft session. A declared bypass cookie on a
  // path the worker claims makes the committed file yield to dispatch — the
  // 503 again standing in for the unreachable edge.
  for (const cookie of ["__prerender_bypass=1", "a=b; __next_preview_data=xyz"]) {
    const draft = await get(rt, host, "/api/static.txt", { headers: { Cookie: cookie } });
    expect(draft.status, cookie).toBe(503);
    expect(await draft.text(), cookie).toContain("Function execution is not available.");
  }

  // The cookie name is matched at a cookie boundary, not as a substring: a
  // different cookie that merely ends with a declared name is somebody else's
  // cookie, and must not turn every visitor into a draft session.
  for (const cookie of [
    "foo__prerender_bypass=1",
    "sf_session=abc",
    "__prerender_bypass_other=1",
  ]) {
    const plain = await get(rt, host, "/api/static.txt", { headers: { Cookie: cookie } });
    expect(plain.status, cookie).toBe(200);
    expect(await plain.text(), cookie).toBe("asset wins");
  }

  // The bypass is route-scoped too: a committed path no route claims keeps
  // answering from disk however the request is cookied.
  const unclaimed = await get(rt, host, "/", { headers: { Cookie: "__prerender_bypass=1" } });
  expect(unclaimed.status).toBe(200);
  expect(await unclaimed.text()).toBe("<h1>home</h1>");
  expect(unclaimed.headers.get("cache-control")).toContain("public");
  expect(unclaimed.headers.get("cache-control")).not.toContain("no-store");
  expect(unclaimed.headers.get("a8c-edge-cache")).toBe("cache");

  // Unmatched paths are ordinary misses — the worker never wakes for junk.
  for (const requestPath of ["/nope", "/deep/junk/path.js"]) {
    expect((await get(rt, host, requestPath)).status, requestPath).toBe(404);
  }
  const wrongMethod = await get(rt, host, "/submit");
  expect(wrongMethod.status).toBe(405);
  expect(wrongMethod.headers.get("allow")).toBe("POST");
  expect(await (await get(rt, host, "/")).text()).toBe("<h1>home</h1>");

  // The method rule: a committed file declines a non-GET/HEAD request instead
  // of terminating it, so the covering Functions subtree still claims the
  // method — the 503 proves dispatch was entered, exactly as above.
  const claimed = await get(rt, host, "/api/static.txt", { method: "POST" });
  expect(claimed.status).toBe(503);
  expect(await claimed.text()).toContain("Function execution is not available.");

  // When no lane claims the method, the refusal names the Allow UNION
  // accumulated across lanes — the static entry's {GET, HEAD} plus the
  // router's PUT — and never falls through to a 404: the path exists.
  const union = await get(rt, host, "/hybrid.txt", { method: "POST" });
  expect(union.status).toBe(405);
  expect(union.headers.get("allow")).toBe("GET, HEAD, PUT");
  // The provider edge is method-blind (it keys on host+path+query alone), so
  // every response to a non-GET/HEAD request opts out of the edge and pins
  // itself private no-store, whatever the lane composed.
  expect(union.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(union.headers.get("cache-control")).toBe("private, no-store");

  // The claimed method dispatches; the file keeps answering its own verbs.
  expect((await get(rt, host, "/hybrid.txt", { method: "PUT" })).status).toBe(503);
  expect(await (await get(rt, host, "/hybrid.txt")).text()).toBe("hybrid bytes");
});

// The artifact is plan-agnostic: the compiler stamps `planGated` on every proxy
// rule and names no plan, so the capability has to follow the entitlements doc
// the control plane syncs onto the route -- with no republish, and failing
// closed whenever that doc is missing.
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
      // Egress safety rejects loopback/private targets at finalize AND at
      // connect time, so a local stand-in upstream is impossible. Only the
      // gating decision is asserted -- never the proxied bytes.
      _redirects: "/api https://example.com/ 200\n",
    },
    activate: { route_name: "production", ...route({ externalProxy: false }) },
  });

  const restricted = async () => {
    const response = await get(rt, host, "/api");
    return { status: response.status, body: await response.text() };
  };

  // Entitled: the same compiled artifact stops rendering the restriction page.
  await putRoute(rt, "spc_plan_gated", "production", route({ externalProxy: true }));
  const entitled = await restricted();
  expect(entitled.status).not.toBe(403);
  expect(entitled.body).not.toMatch(/free_external_proxy_disabled/);

  // Downgrade, an absent field, and an explicit null all fail closed: a sync lag
  // may only ever withhold the capability, never grant it.
  for (const entitlements of [{ externalProxy: false }, undefined, null]) {
    await putRoute(rt, "spc_plan_gated", "production", route(entitlements));
    const denied = await restricted();
    expect(denied.status).toBe(403);
    expect(denied.body).toMatch(/free_external_proxy_disabled/);
  }
});

// Channel template variants: one committed version, per-channel bytes chosen at
// finalize. The version's own hostname always serves what was committed, so a
// version host stays a faithful view of the artifact rather than of a channel.
test("template variants select per-channel bytes; a version host serves the committed base", async () => {
  // The version commits ONE template; the channel values are what differ.
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
      "index.html": "<h1>variants</h1>\n",
      "config.js": template,
      "sf.jsonc": '{ "templates": ["config.js"] }\n',
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
        { name: "production", routeName: "production" },
        { name: "preview", routeName: "preview" },
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

  // The version host is pinned to the artifact, not to the live channel.
  const pinned = await get(rt, versionHost, "/config.js");
  expect(pinned.status).toBe(200);
  expect(await pinned.text()).toBe(base);
  expect(pinned.headers.get("etag")).not.toBe(production.headers.get("etag"));

  // The other channel's bytes are compiled and selectable by channel name. (A
  // branch channel admits nobody anonymously, so its SERVING is an access proof
  // and lives in access-rules.test.ts; what is asserted here is that finalize
  // bound distinct bytes per channel.)
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
  // A tombstone still names the runtime that produced it — otherwise it is
  // indistinguishable from the provider's 404 for a host we never served. It
  // names no version, because the point of a tombstone is that none is servable.
  expect(tombstoned.headers.get("x-spacefast-runtime")).toBe("1");
  expect(tombstoned.headers.get("x-spacefast-version")).toBeNull();

  // An incremental tombstone write is a read-modify-write. If the existing
  // document cannot be read, abort instead of replacing every prior legal or
  // suspension tombstone with only the incoming delta.
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

  // The served hostname is unaffected.
  expect((await get(rt, "tomb.test", "/")).status).toBe(200);
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

// The Spacefast API is a public origin, and the serving enforcer in this
// runtime has never denied it. Finalize must agree, or a route this runtime
// would proxy happily fails a publish the space owner cannot fix from config.
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
  // The finalizer reads the config it finds, so the private file has to be a
  // real one: what is under test is that it can never be SERVED, by either door.
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

// D34/§6: the visitor lane is the artifact walk and nothing else. Every module
// below is a cost every request pays, so the set is pinned here rather than left
// to whichever `require_once` someone adds at the top of a shared file — the
// prohibited list is the contract: no access code for an open Space, no
// bootstrap-config (its Atomic_Persistent_Data decrypt is a per-request
// decrypt), no storage.php, no tier/S3 code on a local hit.
test("public requests load only the modules their request class needs", async () => {
  const moduleRuntime = await startRuntime();
  const host = "lazy-modules.test";
  let instrumentedRoot: string | undefined;
  let instrumentedServer: ChildProcessWithoutNullStreams | undefined;
  try {
    await deploy(moduleRuntime, {
      spaceId: "spc_lazy_modules",
      versionId: "ver_lazy_modules_1",
      files: { "index.html": "static module path" },
      activate: {
        route_name: "production",
        config: publicAccessConfig(
          {},
          // The whole point of this test: only a Space whose public grants cover
          // both targets unconditionally compiles to `open`, and only an `open`
          // Space is allowed to skip the access modules below.
          "live_and_all_versions",
        ),
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
    const instrumentedRuntime: Runtime = {
      baseUrl: instrumentedBaseUrl,
      root: instrumentedRoot,
      engineRoot: path.join(instrumentedRoot, ".stattic/releases/test/engine"),
      storageRoot: path.join(instrumentedRoot, ".stattic/storage"),
      processId: instrumentedServer.pid ?? 0,
      stop: () => undefined,
    };

    const health = await fetch(`${instrumentedBaseUrl}/__spacefast/health.php`);
    expect(health.status).toBe(200);
    const page = await get(instrumentedRuntime, host, "/");
    expect(page.status).toBe(200);
    expect(await page.text()).toBe("static module path");

    const enginePrefix = `${instrumentedRuntime.engineRoot}/`;
    // The record is appended by a shutdown handler, which runs strictly AFTER
    // the response bytes reach the client (and after the engine's own
    // post-response deferred work), so an awaited fetch() does not imply its
    // record has landed — and a concurrent append can leave a partial trailing
    // line. Poll until every probed URI has a record.
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
            return []; // mid-append partial line; complete on a later poll
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

    // Health is the no-auth path monitors poll hardest, and it is deliberately
    // built from engine constants, the request host and the shared response
    // emitter alone. Nothing else pins that: a `require_once
    // shared/bootstrap-config.php` added to health.php would keep answering 200
    // with the same body while paying a persistent-data decrypt on every poll.
    expect(modulesFor("/__spacefast/health.php")).toEqual(
      [
        "entrypoints/health.php",
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
      "runtime/tier.php",
      "shared/bootstrap-config.php",
      "shared/jwt.php",
      "shared/s3.php",
      "shared/storage.php",
    ]) {
      expect(staticModules, forbidden).not.toContain(forbidden);
    }
  } finally {
    instrumentedServer?.kill();
    if (instrumentedRoot) rmSync(instrumentedRoot, { recursive: true, force: true });
    moduleRuntime.stop();
  }
});
