import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync } from "node:crypto";
// The v4 header contract (contracts §5, §15, §16):
//
//  * every request-independent `_headers` rule is BAKED into the response entry
//    at finalize, so what a visitor gets is the compiled header set plus the two
//    things the engine composes at send time — `cache-control` (from the entry's
//    cache class and whether this response is private) and `A8C-Edge-Cache`;
//  * the cache class is compiled from content: html, an already-hashed asset
//    name (`imm`), or revalidate. A publisher `Cache-Control` rule replaces the
//    class outright and is served verbatim;
//  * a private response is never immutable and never edge-stored;
//  * the provider owns `A8C-*`, `x-ac`/`x-sc`/`x-nc` and HSTS. A publisher can
//    name them; the engine strips them and states its own edge intent.
//
// Nginx/FastCGI handoff behavior is proven only on real wp.cloud.

import {
  deploy,
  get,
  publicAccessConfig,
  RESPONSES,
  responseEntries,
  responseEntry,
  type Runtime,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

let rt: Runtime;

const HOST = "headers.test";
const CONTRACT_HOST = "headers-contract.test";
const CONTRACT_404_HOST = "headers-404-contract.test";
const REJECTED_NAMES_HOST = "headers-rejected-names.test";

// The composed policies, one name each (contracts §15/§16).
const HTML_OPEN = "public, max-age=0, must-revalidate";
const IMMUTABLE = "public, max-age=31536000, immutable";
const REVALIDATE = "public, max-age=0, must-revalidate";
// Synthetic/unpurgeable URLs (404s, pattern-rule redirects) keep the bounded
// shared window instead of a cache class.
const EDGE_DEFAULT = "public, max-age=0, s-maxage=600, stale-while-revalidate=60";
// A tokened URL is the secret itself, so cache-policy.php's sticky no-store
// flag replaces whatever class or publisher policy the entry carried — the same
// answer the deny, 404, proxy and Zero lanes give for that request.
const TOKENED = "private, no-store";
// A share-link token makes the URL itself the secret.
// Every token that may ride `?__=` names its lane with a prefix; `sfl_` is a
// customer share link (context.php: STATTIC_ACCESS_QUERY_TOKEN_LINK_PREFIX).
const SHARE_TOKEN = "?__=sfl_aBcD-shareLink_0123456789";

const HASHED_JS = "static/main-D2mDMWBM.js";
const HASHED_JS_BODY = "void 0;\n";
const CSS_BODY = "body{color:red}\n";

const keyPair = generateKeyPairSync("ed25519");
const visitorJwk = keyPair.publicKey.export({ format: "jwk" });
const issuer = visitorIssuer(keyPair.publicKey);

function visitorConfig() {
  return {
    mode: "website",
    visitor_issuer: "spacefast-api",
    visitor_jwks: {
      keys: [
        {
          kty: "OKP",
          crv: "Ed25519",
          kid: issuer.kid,
          alg: "EdDSA",
          use: "sig",
          x: visitorJwk.x ?? "",
        },
      ],
    },
    session_version: 0,
  };
}

function publicConfig(target: "live" | "live_and_all_versions" = "live") {
  return publicAccessConfig(visitorConfig(), target);
}

/** The pair every response states together: browser policy + edge intent. */
function cachePolicy(response: Response): [string | null, string | null] {
  return [response.headers.get("cache-control"), response.headers.get("a8c-edge-cache")];
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_headers",
    versionId: "ver_headers_1",
    files: {
      "index.html": "<h1>headers</h1>\n",
      "about.html": "<h1>about</h1>\n",
      "owned.html": "<h1>provider headers</h1>\n",
      "café.html": "<h1>café</h1>\n",
      "café-agent": "agent redirect source\n",
      "target.html": "<h1>target</h1>\n",
      "doc.md": "# doc markdown\n",
      "cache.txt": "do not store\n",
      "private.txt": "private cache\n",
      "cached.txt": "publisher window\n",
      "remove.txt": "no generated metadata\n",
      "assets/app.js": "void 1;\n",
      // Three names that decide the `imm` class on their own reasons: a bundler
      // chunk id, a date, and a version number (D100 corrected).
      [HASHED_JS]: HASHED_JS_BODY,
      "static/photo_20240115.jpg": "not a hash\n",
      "static/jquery-3.7.1.min.js": "void 2;\n",
      "styles/site.css": CSS_BODY,
      "pinned/app.css": "body{color:blue}\n",
      _headers: [
        "/café.html",
        "  X-I18n-Rule: yes",
        "/about.html",
        "  X-About: yes",
        "  Cache-Control: no-cache",
        // A later block removing what an earlier one set: rules apply in
        // declaration order and the removal wins.
        "/about.html",
        "  ! X-About",
        "/assets/*",
        "  X-Asset: name=:splat",
        "/pinned/*",
        `  Cache-Control: ${IMMUTABLE}`,
        "/cache.txt",
        "  Cache-Control: no-store",
        "/private.txt",
        "  Cache-Control: private, max-age=0",
        "/cached.txt",
        "  Cache-Control: public, max-age=3600",
        "/remove.txt",
        "  ! ETag",
        "  ! Content-Type",
        "  ! X-Content-Type-Options",
        // Provider-owned channels a publisher must never reach.
        "/owned.html",
        "  A8C-Edge-Cache: no-cache",
        "  X-AC: hit",
      ].join("\n"),
      _redirects: [
        "/café-agent /target.html 302 Agent=true",
        "/old /new 301",
        "/r/* /target/:splat 302",
        "/beta /target.html 302 Cookie=beta",
        "/doc-rewrite /doc.md 200! Cookie=beta",
      ].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicConfig(),
      production_hostnames: [HOST],
      version_hostnames: [],
    },
  });

  // SPA fallback space: a rewrite target and a conditional rewrite onto it.
  await deploy(rt, {
    spaceId: "spc_headers_contract",
    versionId: "ver_headers_contract_1",
    files: {
      "index.html": "<h1>spa fallback</h1>\n",
      "target.html": "<h1>rewrite target</h1>\n",
      _redirects: ["/rewrite /target.html 200!", "/spa/set /target.html 200! Cookie=beta"].join(
        "\n",
      ),
      _headers: [
        "/rewrite",
        "  X-Original-Rule: yes",
        "/target.html",
        "  X-Target-Rule: yes",
        "/spa/set",
        "  Cache-Control: public, max-age=3600",
      ].join("\n"),
    },
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    activate: {
      route_name: "production",
      config: publicConfig("live_and_all_versions"),
      production_hostnames: [CONTRACT_HOST],
      version_hostnames: [],
    },
  });

  // Custom-404 space: the nearest-404 chain under a conditional rule.
  await deploy(rt, {
    spaceId: "spc_headers_404_contract",
    versionId: "ver_headers_404_contract_1",
    files: {
      "404.html": "<h1>custom 404</h1>\n",
      "target.html": "<h1>conditional target</h1>\n",
      _redirects: "/missing/set /target.html 200! Cookie=beta\n",
      _headers: ["/missing/set", "  Cache-Control: public, max-age=3600"].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicConfig(),
      production_hostnames: [CONTRACT_404_HOST],
      version_hostnames: [],
    },
  });

  // One version, two host classes: the noindex classification is a route
  // property, so the same compiled entries must answer differently (D138).
  await deploy(rt, {
    spaceId: "spc_robots_hdr",
    versionId: "ver_robots_hdr_1",
    files: {
      "index.html": "<h1>robots</h1>\n",
      "robots.txt": "User-agent: *\nAllow: /\n",
      _headers: ["/index.html", "  X-Robots-Tag: all"].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicConfig(),
      production_hostnames: ["indexable.test", "noindex.test"],
      noindex_production_hostnames: ["noindex.test"],
      version_hostnames: [],
    },
  });
});

afterAll(() => {
  rt?.stop();
});

test("exact header rules apply only to their path, in declaration order", async () => {
  const about = await get(rt, HOST, "/about.html");
  expect(about.status).toBe(200);
  // Set by the first block, removed by the second: the later rule wins.
  expect(about.headers.get("x-about")).toBeNull();
  // The wp.cloud edge ignores CDN-Cache-Control / Surrogate-Control, so the
  // runtime states its policy once, in Cache-Control.
  expect(about.headers.get("cdn-cache-control")).toBeNull();
  expect(about.headers.get("surrogate-control")).toBeNull();

  const index = await get(rt, HOST, "/");
  expect(index.headers.get("x-i18n-rule")).toBeNull();
});

test("browser paths keep one identity across compiled rules, access, and lookup", async () => {
  for (const requestPath of ["/caf%C3%A9.html", "/cafe%CC%81.html"]) {
    const unicode = await get(rt, HOST, requestPath);
    expect(unicode.status, requestPath).toBe(200);
    expect(await unicode.text(), requestPath).toBe("<h1>café</h1>\n");
    expect(unicode.headers.get("x-i18n-rule"), requestPath).toBe("yes");
  }

  for (const requestPath of ["/caf%C3%A9-agent", "/cafe%CC%81-agent"]) {
    const redirect = await get(rt, HOST, `${requestPath}?return=%2Fcaf%C3%A9`, {
      headers: { accept: "*/*", "user-agent": "curl/8.5.0" },
    });
    expect(redirect.status, requestPath).toBe(302);
    expect(redirect.headers.get("location"), requestPath).toBe("/target.html?return=%2Fcaf%C3%A9");
  }
});

// The cache class is a property of the content, decided once at finalize. Each
// row here fails for its own reason: an HTML page is always revalidated because
// its live URL can move; a name that already carries a content hash is frozen;
// a date, a version number and an ordinary asset name are not hashes and must
// revalidate.
test("cache classes are compiled from content, never guessed at request time", async () => {
  const cases = [
    ["/", HTML_OPEN],
    [`/${HASHED_JS}`, IMMUTABLE],
    ["/static/photo_20240115.jpg", REVALIDATE],
    ["/static/jquery-3.7.1.min.js", REVALIDATE],
    ["/styles/site.css", REVALIDATE],
  ] as const;
  for (const [requestPath, cacheControl] of cases) {
    const response = await get(rt, HOST, requestPath);
    expect(response.status, requestPath).toBe(200);
    // Every one of these is publicly storable, so each carries the edge opt-in.
    expect(cachePolicy(response), requestPath).toEqual([cacheControl, "cache"]);
  }
});

// A publisher Cache-Control replaces the class outright and is served exactly as
// written — no s-maxage grafted on, no bounded window added underneath it. The
// edge opt-in still follows the policy, so a restrictive one bypasses the edge.
test("a publisher Cache-Control rule is served verbatim and steers the edge opt-in", async () => {
  const cases = [
    ["/cache.txt", "no-store", "no-cache"],
    ["/private.txt", "private, max-age=0", "no-cache"],
    ["/cached.txt", "public, max-age=3600", "cache"],
    ["/pinned/app.css", IMMUTABLE, "cache"],
    ["/about.html", "no-cache", "no-cache"],
  ] as const;
  for (const [requestPath, cacheControl, edge] of cases) {
    const response = await get(rt, HOST, requestPath);
    expect(response.status, requestPath).toBe(200);
    expect(cachePolicy(response), requestPath).toEqual([cacheControl, edge]);
  }
});

// A share-link token turns any URL into a secret URL: the edge keys on
// host+path+query and ignores Vary, so nothing — not a publisher's immutable
// policy, not the compiled `imm` class — may leave a tokened response storable.
// `private, no-store` is the only private response policy;
// the token is an orthogonal sticky reason nothing may keep the bytes at all,
// which is why a revocation binds on the very next request. The two rows below
// fail for their own reasons — one replaces a compiled class, the other a
// publisher `Cache-Control` rule.
test("a tokened URL is private: never immutable, never edge-stored", async () => {
  const asset = await get(rt, HOST, `/${HASHED_JS}${SHARE_TOKEN}`);
  expect(asset.status).toBe(200);
  expect(cachePolicy(asset)).toEqual([TOKENED, "no-cache"]);
  expect(asset.headers.get("vary")).toBe("Cookie");
  expect(asset.headers.get("x-robots-tag")).toBe("noindex, nofollow");

  // A publisher policy on a private response is replaced, not merged.
  const pinned = await get(rt, HOST, `/pinned/app.css${SHARE_TOKEN}`);
  expect(pinned.status).toBe(200);
  expect(cachePolicy(pinned)).toEqual([TOKENED, "no-cache"]);
});

// §16: the edge stores a public PHP-lane response only when it sees BOTH a
// public Cache-Control and `A8C-Edge-Cache: cache`. That channel is the
// platform's — a tenant that names it (or the provider's x-ac/x-sc/x-nc
// diagnostics, or HSTS, which the provider rewrites) never reaches the wire.
test("the engine owns the edge-cache channel and the provider headers next to it", async () => {
  const response = await get(rt, HOST, "/owned.html");
  expect(response.status).toBe(200);
  expect(cachePolicy(response)).toEqual([HTML_OPEN, "cache"]);
  expect(response.headers.get("x-ac")).toBeNull();
  expect(response.headers.get("strict-transport-security")).toBeNull();
});

// A compiled artifact is replayed into every response, so a name the platform
// owns — or a string that is not a header name at all — has to die at compile
// time. The version still publishes; the rule simply never becomes an entry.
test("header names the platform owns never compile into an artifact", async () => {
  const names = ["Set-Cookie", "Strict-Transport-Security", "CDN-Cache-Control", "x-bad header"];
  const spaceId = "spc_headers_rejected_names";
  const versionId = "ver_headers_rejected_names_1";
  await deploy(rt, {
    spaceId,
    versionId,
    files: {
      "index.html": "<h1>bad</h1>\n",
      _headers: ["/index.html", ...names.map((name) => `  ${name}: x`)].join("\n"),
    },
    activate: {
      route_name: "production",
      config: publicConfig(),
      production_hostnames: [REJECTED_NAMES_HOST],
      version_hostnames: [],
    },
  });

  const compiled = Object.values(responseEntries(rt, spaceId, versionId)).flatMap((entry) =>
    Object.keys(entry[RESPONSES.entryKeys.headers] ?? {}),
  );
  for (const name of names) {
    expect(
      compiled.map((header) => header.toLowerCase()),
      name,
    ).not.toContain(name.toLowerCase());
  }

  // And nothing reaches the visitor either. (`x-bad header` is skipped: it is
  // not a legal header name, so it cannot even be looked up.)
  const served = await get(rt, REJECTED_NAMES_HOST, "/index.html");
  expect(served.status).toBe(200);
  for (const name of ["Set-Cookie", "Strict-Transport-Security", "CDN-Cache-Control"]) {
    expect(served.headers.get(name), name).toBeNull();
  }
});

// Removals are the publisher's to make over their own metadata, but they stop at
// the platform boundary: nosniff is re-asserted after every rule, and the ETag
// is the platform's validator (D121) rather than publisher metadata.
test("_headers removals cannot strip platform metadata", async () => {
  const response = await get(rt, HOST, "/remove.txt");
  expect(response.status).toBe(200);
  expect(response.headers.get("x-content-type-options")).toBe("nosniff");
  expect(response.headers.get("etag")).not.toBeNull();
  // Removing Content-Type must never leave tenant bytes served as a page.
  expect(response.headers.get("content-type") ?? "").not.toContain("text/html");
});

test("pattern rules expand placeholders from path captures", async () => {
  const response = await get(rt, HOST, "/assets/app.js");
  expect(response.status).toBe(200);
  expect(response.headers.get("x-asset")).toBe("name=app.js");
});

// `_headers` describe the URL the visitor asked for. An internal rewrite changes
// which bytes answer, not which rules matched.
test("_headers match the original URL of an internal rewrite", async () => {
  const response = await get(rt, CONTRACT_HOST, "/rewrite");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>rewrite target</h1>\n");
  expect(response.headers.get("x-original-rule")).toBe("yes");
  expect(response.headers.get("x-target-rule")).toBeNull();
});

// Unconditional redirects are a pure function of the cache key (host+path+query)
// and are storable; a condition-matched one is per-visitor and must never enter
// a shared cache. The two arrive by different routes — an exact unconditional
// rule is a compiled table entry, a pattern is answered by the ordered rules —
// and both have to state a policy.
test("redirects carry an explicit edge cache policy on both lanes", async () => {
  const compiled = await get(rt, HOST, "/old");
  expect(compiled.status).toBe(301);
  expect(compiled.headers.get("location")).toBe("/new");
  expect(cachePolicy(compiled)).toEqual([REVALIDATE, "cache"]);

  const pattern = await get(rt, HOST, "/r/abc");
  expect(pattern.status).toBe(302);
  expect(pattern.headers.get("location")).toBe("/target/abc");
  expect(cachePolicy(pattern)).toEqual([EDGE_DEFAULT, "cache"]);

  const conditional = await get(rt, HOST, "/beta", { headers: { cookie: "beta=1" } });
  expect(conditional.status).toBe(302);
  expect(conditional.headers.get("location")).toBe("/target.html");
  expect(cachePolicy(conditional)).toEqual(["no-store", "no-cache"]);

  // Without the cookie the rule does not match and normal serving proceeds.
  expect((await get(rt, HOST, "/beta")).status).not.toBe(302);
});

// A URL under a conditional rule is request-varying whether or not this visitor
// matched: the matched branch serves per-visitor bytes, and caching the
// unmatched branch would stop later matching visitors reaching the origin at
// all. Publisher policy on those URLs — set, removed or absent — cannot make
// them storable, because none of those request inputs is part of the cache key.
test("condition-matched responses never enter a shared cache", async () => {
  const matched = await get(rt, HOST, "/doc-rewrite", { headers: { cookie: "beta=1" } });
  expect(matched.status).toBe(200);
  expect(await matched.text()).toBe("# doc markdown\n");
  expect(cachePolicy(matched)).toEqual(["no-store", "no-cache"]);

  const cases = [
    // Unmatched candidate: a miss with no fallback lands on the platform page.
    [HOST, "/doc-rewrite", 404, "private, no-store"],
    // Unmatched candidate onto a SPA fallback, and onto a custom 404.
    [CONTRACT_HOST, "/spa/set", 200, "no-store"],
    [CONTRACT_404_HOST, "/missing/set", 404, "no-store"],
  ] as const;
  for (const [host, requestPath, status, cacheControl] of cases) {
    const response = await get(rt, host, requestPath);
    expect(response.status, requestPath).toBe(status);
    expect(cachePolicy(response), requestPath).toEqual([cacheControl, "no-cache"]);
  }
});

// Synthetic URLs no purge can enumerate: the SPA shell keeps the HTML policy
// (its bytes are a published page), while a 404 falls back to the bounded shared
// window rather than a year at the edge.
test("fallback and not-found responses state a bounded policy", async () => {
  const fallback = await get(rt, CONTRACT_HOST, "/spa/route");
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toBe("<h1>spa fallback</h1>\n");
  expect(cachePolicy(fallback)).toEqual([HTML_OPEN, "cache"]);

  const custom404 = await get(rt, CONTRACT_404_HOST, "/missing/page");
  expect(custom404.status).toBe(404);
  expect(await custom404.text()).toBe("<h1>custom 404</h1>\n");
  expect(cachePolicy(custom404)).toEqual([EDGE_DEFAULT, "cache"]);
});

// D138 corrected: robots.txt stops a crawler READING a page, never indexing its
// URL, so the noindex that binds is X-Robots-Tag on the HTML — which reaches the
// crawler because HTML never leaves the PHP lane. robots.txt is demoted to crawl
// control, compiled under the reserved key, and only a noindex host serves the
// platform's deny-all instead of the publisher's own file.
test("noindex hosts own X-Robots-Tag, and robots.txt is only crawl control", async () => {
  const indexable = await get(rt, "indexable.test", "/index.html");
  expect(indexable.headers.get("x-robots-tag")).toBe("all");

  const noindex = await get(rt, "noindex.test", "/index.html");
  expect(noindex.headers.get("x-robots-tag")).toBe("noindex, nofollow");

  expect(
    responseEntry(rt, "spc_robots_hdr", "ver_robots_hdr_1", RESPONSES.specialKeys.robots),
  ).not.toBeNull();

  const platformRobots = await get(rt, "noindex.test", "/robots.txt");
  expect(platformRobots.status).toBe(200);
  expect(await platformRobots.text()).toBe("User-agent: *\nDisallow: /\n");

  // An indexable host publishes its own crawl policy and must keep it.
  const publisherRobots = await get(rt, "indexable.test", "/robots.txt");
  expect(await publisherRobots.text()).toBe("User-agent: *\nAllow: /\n");
});
