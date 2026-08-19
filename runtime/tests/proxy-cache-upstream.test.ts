import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { gzipSync } from "node:zlib";

import {
  deploy,
  errorCode,
  finalizeRaw,
  get,
  PHP_BINARY,
  postAccessCallback,
  readBlob,
  type Runtime,
  sha256,
  signEd25519Jwt,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

const SHARED = "public, max-age=0, s-maxage=600, stale-while-revalidate=60";
const PROTECTED = "private, no-store";
const PUBLIC_HOST = "proxy-upstream.test";
const PUBLIC_HOST_ROUTE = "proxy-route-public.test";
const ACCESS_HOST = "proxy-access-cache.test";
const ACCESS_HOST_ROUTE = "proxy-route-private.test";
const ETAG = '"proxy-upstream-v1"';

// A proxy upstream is a user-supplied URL. Under nginx these headers do not describe
// the response, they make the server produce a different one: an internal read of the
// runtime's private storage root — here a blob belonging to the OTHER space, which on a
// shared wp.cloud site is the only place its bytes exist (contracts §2/§8: the v4
// compiler writes no per-version file tree) — or, for X-Accel-Limit-Rate, a pinned
// worker for the length of the transfer.
const CROSS_SPACE_PRIVATE_BODY = "proxy access fixture\n";
const CROSS_SPACE_PRIVATE_SHA = sha256(CROSS_SPACE_PRIVATE_BODY);
const CROSS_SPACE_PRIVATE_PATH = `/.stattic/storage/spaces/spc_proxy_access_cache/blobs/${CROSS_SPACE_PRIVATE_SHA.slice(0, 2)}/${CROSS_SPACE_PRIVATE_SHA}`;
const INTERNAL_REDIRECT_HEADERS: Record<string, string> = {
  "x-accel-redirect": CROSS_SPACE_PRIVATE_PATH,
  "x-accel-buffering": "no",
  "x-accel-expires": "off",
  "x-accel-charset": "utf-8",
  "x-accel-limit-rate": "1",
  "x-sendfile": CROSS_SPACE_PRIVATE_PATH,
  "x-lighttpd-send-file": CROSS_SPACE_PRIVATE_PATH,
  "x-lighttpd-sendfile": CROSS_SPACE_PRIVATE_PATH,
  "x-lighttpd-sendfile2": `${CROSS_SPACE_PRIVATE_PATH} 0:${CROSS_SPACE_PRIVATE_BODY.length}`,
  "x-reproxy-url": CROSS_SPACE_PRIVATE_PATH,
};
const GZIP_RELAY_BODY = "compressed relay body\n";

const tokenKeyPair = generateKeyPairSync("ed25519");
const ISSUER = visitorIssuer(tokenKeyPair.publicKey);
const visitorJwk = tokenKeyPair.publicKey.export({ format: "jwk" });
let accessCookie = "";

let upstream: Server;
let upstreamBaseUrl: string;
let rt: Runtime;
const revalidationHeaders: Array<string | undefined> = [];
const upstreamMethods: string[] = [];

function visitorToken(host: string): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(tokenKeyPair.privateKey, ISSUER.kid, {
    sub: "link:proxy-cache",
    purpose: "handoff",
    grants: ["link:proxy-cache"],
    authorities: ["link:proxy-cache"],
    iss: "spacefast-api",
    aud: "spc_proxy_access_cache",
    host,
    spaceId: "spc_proxy_access_cache",
    generation: 1,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    iat: now,
    nbf: now,
    exp: now + 3600,
    jti: randomUUID(),
  });
}

function accessProjection() {
  return {
    // Every compiled proxy rule is plan-gated on `external_proxy`; this file is
    // about what a proxy DOES once the gate is open, so the route carries the
    // entitlement. The gate itself is proven in routing.test.ts.
    entitlements: { externalProxy: true },
    visitor_issuer: "spacefast-api",
    visitor_jwks: {
      keys: [
        {
          kty: "OKP",
          crv: "Ed25519",
          kid: ISSUER.kid,
          alg: "EdDSA",
          use: "sig",
          x: visitorJwk.x ?? "",
        },
      ],
    },
    projection_generation: 1,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/proxy-cache",
      spaceClaimed: true,
      // Every sfv2_/sfa1_ session key is derived from this credential
      // (contracts §4: `exchange.credential`, per space) — without the
      // descriptor a protected Space can neither mint nor verify a session,
      // so the link handoff below would have nothing to trade the token for.
      accessPage: {
        displayName: "Proxy cache",
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/proxy-cache/password",
          linkUrl: "https://access.spacefast.test/acquire/proxy-cache/link",
          tokenUrl: "https://access.spacefast.test/acquire/proxy-cache/token",
          requestUrl: "https://access.spacefast.test/acquire/proxy-cache/request",
          credential: "runtime-exchange-credential-0123456789abcdef",
        },
      },
      grants: [
        {
          id: "grt_proxy_public",
          generation: 1,
          audience: { kind: "public" },
          resources: { include: ["/**"], exclude: ["/access/**"] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:proxy-public" },
        },
        {
          id: "grt_proxy_link",
          generation: 1,
          audience: { kind: "link", linkId: "proxy-cache" },
          resources: { include: ["/access/**"], exclude: [] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:proxy-link" },
        },
      ],
    },
  };
}

/** One `_redirects` proxy line: an absolute-URL 200, shared-cached by default. */
function proxyRule(source: string, destination: string, cache: "shared" | null = "shared") {
  return `${source} ${destination} 200${cache === "shared" ? " cache=shared" : ""}`;
}

/** The `_redirects` file proxying each path straight through to the upstream. */
function proxyRedirects(paths: string[]) {
  return paths.map((requestPath) => proxyRule(requestPath, `${upstreamBaseUrl}${requestPath}`));
}

function listen(server: Server): Promise<void> {
  return new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      server.off("error", reject);
      resolve();
    });
  });
}

function close(server: Server): Promise<void> {
  return new Promise((resolve, reject) =>
    server.close((error) => (error ? reject(error) : resolve())),
  );
}

beforeAll(async () => {
  upstream = createServer((request, response) => {
    const requestPath = new URL(request.url ?? "/", "http://upstream.test").pathname;
    upstreamMethods.push(`${request.method} ${requestPath}`);
    response.setHeader("Content-Type", "text/plain");
    response.setHeader("Cache-Control", "public, max-age=300");

    if (requestPath === "/personalized/set-cookie") {
      response.setHeader("Set-Cookie", "session=private; Path=/");
    }
    if (requestPath === "/personalized/vary-cookie") response.setHeader("Vary", "Cookie");
    if (requestPath === "/personalized/vary-authorization") {
      response.setHeader("Vary", "Authorization");
    }
    if (requestPath === "/personalized/vary-forwarded-for") {
      response.setHeader("Vary", "X-Forwarded-For");
    }
    if (requestPath === "/personalized/vary-star") response.setHeader("Vary", "*");
    if (requestPath === "/safe/vary-encoding") response.setHeader("Vary", "Accept-Encoding");
    if (requestPath === "/upstream/private") {
      response.setHeader("Cache-Control", 'public, PRIVATE="Authorization"');
    }
    if (requestPath === "/upstream/no-store") response.setHeader("Cache-Control", "NO-STORE");
    if (requestPath === "/upstream/no-cache") response.setHeader("Cache-Control", "No-Cache");
    if (requestPath === "/upstream/pragma") response.setHeader("Pragma", "no-cache");

    if (requestPath.startsWith("/status/")) {
      response.statusCode = Number(requestPath.slice("/status/".length));
    }

    if (requestPath.startsWith("/sendfile/")) {
      const name = requestPath.slice("/sendfile/".length);
      response.setHeader(name, INTERNAL_REDIRECT_HEADERS[name] ?? "");
      response.end(`${name}\n`);
      return;
    }

    if (requestPath === "/relay/location") {
      response.statusCode = 301;
      response.setHeader("Location", "https://relayed.example/moved");
      response.end("moved\n");
      return;
    }

    if (requestPath === "/relay/content-encoding") {
      // curl is never given CURLOPT_ENCODING, so these bytes reach the visitor
      // still compressed — Content-Encoding is the only thing that makes them
      // readable.
      response.setHeader("Content-Encoding", "gzip");
      response.end(gzipSync(Buffer.from(GZIP_RELAY_BODY)));
      return;
    }

    if (requestPath === "/relay/content-range") {
      response.statusCode = 206;
      response.setHeader("Content-Range", "bytes 0-3/9");
      response.end("part");
      return;
    }

    if (requestPath === "/relay/allow") {
      response.statusCode = 405;
      response.setHeader("Allow", "GET, HEAD");
      response.end("upstream method rejection\n");
      return;
    }

    if (requestPath === "/revalidate") {
      revalidationHeaders.push(request.headers["if-none-match"]);
      response.setHeader("ETag", ETAG);
      if (request.headers["if-none-match"] === ETAG) response.statusCode = 304;
    }

    if (requestPath === "/truncated") {
      response.setHeader("Content-Length", "64");
      response.write("partial");
      response.destroy();
      return;
    }

    if (requestPath === "/access/token") {
      response.setHeader("Content-Type", "application/json");
      response.setHeader("Access-Control-Allow-Origin", "https://attacker.example");
      response.setHeader("Cross-Origin-Resource-Policy", "cross-origin");
      response.setHeader("Content-Security-Policy", "frame-ancestors *");
      response.setHeader("Vary", "Origin");
      response.setHeader("X-Robots-Tag", "all");
      response.end(
        JSON.stringify({
          jwt: request.headers["spacefast-access-jwt"] ?? null,
          sub: request.headers["spacefast-access-sub"] ?? null,
          grants: request.headers["spacefast-access-grants"] ?? null,
        }),
      );
      return;
    }

    if (requestPath === "/visitor-ip") {
      const forwardedFor = request.headers["x-forwarded-for"];
      response.end(
        `xff=${Array.isArray(forwardedFor) ? forwardedFor.join(", ") : (forwardedFor ?? "none")}\n`,
      );
      return;
    }

    if (requestPath === "/echo-query") {
      response.end(`query=${new URL(request.url ?? "/", "http://upstream.test").search}\n`);
      return;
    }

    if (requestPath.startsWith("/configured-headers")) {
      response.setHeader("Content-Type", "application/json");
      response.end(
        JSON.stringify({
          authorization: request.headers.authorization ?? null,
          cookie: request.headers.cookie ?? null,
        }),
      );
      return;
    }

    response.end(`${request.method} ${requestPath}\n`);
  });
  await listen(upstream);
  const address = upstream.address() as AddressInfo;
  upstreamBaseUrl = `http://127.0.0.1:${address.port}`;
  rt = await startRuntime({
    env: { SPACEFAST_EGRESS_TEST_ALLOWLIST: `127.0.0.1:${address.port}` },
  });

  const publicPaths = [
    "/personalized/set-cookie",
    "/personalized/vary-cookie",
    "/personalized/vary-authorization",
    "/personalized/vary-forwarded-for",
    "/personalized/vary-star",
    "/safe/vary-encoding",
    "/upstream/private",
    "/upstream/no-store",
    "/upstream/no-cache",
    "/upstream/pragma",
    "/upstream/public",
    "/revalidate",
    "/truncated",
    "/echo-query",
    "/relay/location",
    "/relay/content-encoding",
    "/relay/content-range",
    "/relay/allow",
    ...Object.keys(INTERNAL_REDIRECT_HEADERS).map((name) => `/sendfile/${name}`),
    ...[200, 203, 300, 301, 302, 403, 404, 410, 500].map((status) => `/status/${status}`),
  ];
  await deploy(rt, {
    spaceId: "spc_proxy_upstream",
    versionId: "ver_proxy_upstream_1",
    files: {
      "index.html": "proxy upstream fixture\n",
      _redirects: [
        ...proxyRedirects(publicPaths),
        proxyRule("/trailing-prefix", `${upstreamBaseUrl}/destination/`),
        proxyRule("/uncacheable", `${upstreamBaseUrl}/upstream/public`, null),
        proxyRule("/visitor/shared", `${upstreamBaseUrl}/visitor-ip`),
        proxyRule("/visitor/unshared", `${upstreamBaseUrl}/visitor-ip`, null),
        "",
      ].join("\n"),
    },
    activate: {
      route_name: "production",
      config: accessProjection(),
      production_hostnames: [PUBLIC_HOST],
      version_hostnames: [],
      proxy_host_routes: [
        { hostname: PUBLIC_HOST_ROUTE, upstream: upstreamBaseUrl },
        { hostname: PUBLIC_HOST, path_prefix: "/proxy", upstream: `${upstreamBaseUrl}/api` },
      ],
    },
  });

  await deploy(rt, {
    spaceId: "spc_proxy_access_cache",
    versionId: "ver_proxy_access_cache_1",
    files: {
      "index.html": CROSS_SPACE_PRIVATE_BODY,
      _redirects: `${proxyRedirects(["/access/token"]).join("\n")}\n`,
    },
    activate: {
      route_name: "production",
      config: {
        ...accessProjection(),
      },
      production_hostnames: [ACCESS_HOST],
      version_hostnames: [],
      proxy_host_routes: [{ hostname: ACCESS_HOST_ROUTE, upstream: upstreamBaseUrl }],
    },
  });
  // The internal-redirect fixtures below are only a real attack if the path they
  // name holds another Space's private bytes. In v4 that is the CAS blob, so
  // prove it exists before asserting the runtime refuses to relay a pointer to it.
  expect(readBlob(rt, "spc_proxy_access_cache", CROSS_SPACE_PRIVATE_SHA)?.toString("utf8")).toBe(
    CROSS_SPACE_PRIVATE_BODY,
  );

  const configuredHeaderRoute = (location: string, headers: Record<string, string>) => ({
    location,
    route_action: {
      action: "proxy",
      upstream: `${upstreamBaseUrl}/configured-headers`,
      target_prefix: "/",
      methods: ["GET", "HEAD"],
      headers,
      forwardHeaders: [],
      cache: "shared",
      bodySizeLimitBytes: 1_048_576,
      timeoutSeconds: 30,
      connectTimeoutSeconds: 10,
    },
  });
  const routeFixture = JSON.stringify([
    configuredHeaderRoute("/configured-authorization", {
      Authorization: "Bearer configured-token",
    }),
    configuredHeaderRoute("/configured-cookie", { Cookie: "session=configured" }),
  ]);
  // Configured proxy headers have no ingress spelling (generate.php compiles
  // `headers` empty for every host proxy route), so the fixture is installed by
  // writing the host shard the way the compiler does: a NEW content-addressed
  // artifact plus a pointer swap (contracts §1/§3). Never rewrite a shard in
  // place — its name is the hash of its bytes.
  const inject = Bun.spawnSync([
    PHP_BINARY,
    "-r",
    [
      "require $argv[1];",
      "$routesRoot = $argv[2] . '/routes';",
      "$host = $argv[3];",
      "$fixtures = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);",
      "$pointer = json_decode((string) file_get_contents($routesRoot . '/current.json'), true, 512, JSON_THROW_ON_ERROR);",
      "$shard = substr(hash('sha256', $host), 0, 2);",
      "$name = $pointer['shards'][$shard] ?? null;",
      "if (!is_string($name)) { fwrite(STDERR, 'no shard owns ' . $host); exit(1); }",
      "$artifact = include $routesRoot . '/' . $name;",
      "$spaceId = $artifact['hostnames'][$host]['space_id'] ?? '';",
      "foreach ($fixtures as &$route) { $route['space_id'] = $spaceId; }",
      "unset($route);",
      "$artifact['host_routes'][$host] = array_merge($fixtures, $artifact['host_routes'][$host] ?? []);",
      "$written = _sf_php_artifact_write($routesRoot . '/shards', 'hosts-' . $shard, _sf_php_artifact_source($artifact));",
      "_sf_json_write($routesRoot . '/previous.json', $pointer);",
      "$pointer['shards'][$shard] = 'shards/' . $written;",
      "$pointer['gen'] = ((int) $pointer['gen']) + 1;",
      "_sf_pointer_swap($routesRoot . '/current.json', $pointer);",
    ].join("\n"),
    `${rt.engineRoot}/shared/pointers.php`,
    rt.storageRoot,
    PUBLIC_HOST,
    routeFixture,
  ]);
  if (inject.exitCode !== 0) {
    throw new Error(`failed to install proxy route fixture: ${inject.stderr.toString()}`);
  }
  // Install the content-addressed route fixture before the visitor path first
  // reads the pointer. The PHP server owns a separate APCu cache from the CLI
  // process that swapped the pointer, so priming it earlier would retain the
  // old generation until its TTL expires.
  const callback = await postAccessCallback(
    rt,
    ACCESS_HOST,
    visitorToken(ACCESS_HOST),
    "/access/token",
  );
  expect(callback.status).toBe(303);
  accessCookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
});

afterAll(async () => {
  rt?.stop();
  if (upstream) await close(upstream);
});

describe("proxy shared-cache policy against a real PHP upstream path", () => {
  test("host proxy routes enforce their owning production access projection", async () => {
    const publicProxy = await get(rt, PUBLIC_HOST_ROUTE, "/upstream/public");
    expect(publicProxy.status).toBe(200);
    expect(await publicProxy.text()).toBe("GET /upstream/public\n");
    expect(publicProxy.headers.get("x-spacefast-version")).toBeNull();

    const denied = await get(rt, ACCESS_HOST_ROUTE, "/access/token");
    expect(denied.status).toBe(403);
    expect(denied.headers.get("cache-control")).toBe("private, no-store");
    expect(denied.headers.get("x-spacefast-version")).toBeNull();

    const callback = await postAccessCallback(
      rt,
      ACCESS_HOST_ROUTE,
      visitorToken(ACCESS_HOST_ROUTE),
      "/access/token",
    );
    expect(callback.status).toBe(303);
    const cookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
    const admitted = await get(rt, ACCESS_HOST_ROUTE, "/access/token", {
      headers: { cookie, origin: `https://${ACCESS_HOST_ROUTE}` },
    });
    expect(admitted.status).toBe(200);
    const identity = (await admitted.json()) as {
      sub?: string;
      grants?: string;
    };
    expect(identity.sub).toBeNull();
    expect(identity.grants).toBeNull();
    expect(admitted.headers.get("cache-control")).toBe(PROTECTED);
    expect(admitted.headers.get("x-spacefast-version")).toBeNull();
  });

  test("residual encoded dot segments cannot escape a proxy route's upstream prefix", async () => {
    const beforeRequestCount = upstreamMethods.length;

    const traversals = [];
    for (let residualDepth = 1; residualDepth <= 4; residualDepth++) {
      const encodedDot = `%${"25".repeat(residualDepth)}2e`;
      traversals.push(await get(rt, PUBLIC_HOST, `/proxy/${encodedDot}${encodedDot}/private`));
    }

    expect(traversals.map(({ status }) => status)).toEqual([403, 403, 403, 403]);
    expect(traversals.map(({ headers }) => headers.get("cache-control"))).toEqual([
      "no-store",
      "no-store",
      "no-store",
      "no-store",
    ]);
    expect(upstreamMethods).toHaveLength(beforeRequestCount);
  });

  test("the egress test seam permits only its exact loopback host and port", async () => {
    const allowedPort = Number(new URL(upstreamBaseUrl).port);
    const response = await finalizeRaw(
      rt,
      "spc_proxy_wrong_port",
      "ver_proxy_wrong_port_1",
      {
        "index.html": "wrong port fixture\n",
        _redirects: `${proxyRule("/proxy", `http://127.0.0.1:${allowedPort + 1}/wrong-port`)}\n`,
      },
      {},
    );
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe("proxy_upstream_denied");
  });

  test("Set-Cookie and unsafe Vary values revoke shared caching", async () => {
    await Promise.all(
      [
        "/personalized/set-cookie",
        "/personalized/vary-cookie",
        "/personalized/vary-authorization",
        "/personalized/vary-forwarded-for",
        "/personalized/vary-star",
      ].map(async (requestPath) => {
        const response = await get(rt, PUBLIC_HOST, requestPath);
        expect(response.status, requestPath).toBe(200);
        expect(response.headers.get("cache-control"), requestPath).toBe("no-store");
      }),
    );
    const cookie = await get(rt, PUBLIC_HOST, "/personalized/set-cookie");
    expect(cookie.headers.has("set-cookie")).toBe(false);

    const encoding = await get(rt, PUBLIC_HOST, "/safe/vary-encoding");
    expect(encoding.headers.get("cache-control")).toBe(SHARED);
  });

  test("canonical access pins private revalidation and never forwards visitor capability", async () => {
    const denied = await get(rt, ACCESS_HOST, "/access/token");
    expect(denied.status).toBe(403);
    expect(denied.headers.get("cache-control")).toBe("private, no-store");

    const identity = await get(rt, ACCESS_HOST, "/access/token", {
      headers: { cookie: accessCookie, origin: `https://${ACCESS_HOST}` },
    });
    expect(identity.status).toBe(200);
    expect(identity.headers.get("cache-control")).toBe("private, no-store");
    expect(identity.headers.get("vary")).toContain("Cookie");
    expect(identity.headers.get("x-robots-tag")).toBe("noindex, nofollow");
    expect(identity.headers.get("cross-origin-resource-policy")).toBe("same-origin");
    expect(identity.headers.get("content-security-policy")).toBe("frame-ancestors 'self'");
    expect(identity.headers.get("access-control-allow-origin")).toBeNull();
    expect(await identity.json()).toEqual({
      jwt: null,
      sub: null,
      grants: null,
    });
  });

  test("an exact proxy destination keeps its terminal slash at the route root", async () => {
    const response = await get(rt, PUBLIC_HOST, "/trailing-prefix/");
    expect(response.status).toBe(200);
    expect(await response.text()).toBe("GET /destination/\n");
  });

  test("a share-link token never reaches an upstream and never leaves a cacheable response", async () => {
    const forwarded = await get(
      rt,
      PUBLIC_HOST,
      "/echo-query?page=2&__=sfl_aBcD-shareLink_0123456789&sort=asc",
    );
    expect(forwarded.status).toBe(200);
    // The publisher's origin sees the visitor's own parameters and nothing of
    // the capability that admitted them.
    expect(await forwarded.text()).toBe("query=?page=2&sort=asc\n");
    // Public proxy route, public Grant — the token alone pins the response
    // out of the shared cache it would otherwise be eligible for.
    expect(forwarded.headers.get("cache-control")).toBe("private, no-store");

    const untokened = await get(rt, PUBLIC_HOST, "/echo-query?page=2");
    expect(untokened.headers.get("cache-control")).toBe(SHARED);
  });

  test("only the bounded upstream status set receives shared cache policy", async () => {
    await Promise.all(
      [200, 203, 300, 301, 404, 410].map(async (status) => {
        const response = await get(rt, PUBLIC_HOST, `/status/${status}`);
        expect(response.status).toBe(status);
        expect(response.headers.get("cache-control"), String(status)).toBe(SHARED);
      }),
    );
    await Promise.all(
      [302, 403, 500].map(async (status) => {
        const response = await get(rt, PUBLIC_HOST, `/status/${status}`);
        expect(response.status).toBe(status);
        expect(response.headers.get("cache-control"), String(status)).toBe("no-store");
      }),
    );
  });

  test("upstream private and no-cache directives revoke but permissive TTLs never replace platform policy", async () => {
    await Promise.all(
      ["/upstream/private", "/upstream/no-store", "/upstream/no-cache", "/upstream/pragma"].map(
        async (requestPath) => {
          const response = await get(rt, PUBLIC_HOST, requestPath);
          expect(response.headers.get("cache-control"), requestPath).toBe("no-store");
        },
      ),
    );
    const permissive = await get(rt, PUBLIC_HOST, "/upstream/public");
    expect(permissive.headers.get("cache-control")).toBe(SHARED);
    const uncacheable = await get(rt, PUBLIC_HOST, "/uncacheable");
    expect(uncacheable.headers.get("cache-control")).toBe("no-store");
  });

  test("shared requests omit the visitor address while unshared proxies preserve X-Forwarded-For", async () => {
    const sharedFirst = await get(rt, PUBLIC_HOST, "/visitor/shared");
    const sharedSecond = await get(rt, PUBLIC_HOST, "/visitor/shared");
    expect(sharedFirst.status).toBe(200);
    expect(sharedSecond.status).toBe(200);
    expect(sharedFirst.headers.get("cache-control")).toBe(SHARED);
    expect(sharedSecond.headers.get("cache-control")).toBe(SHARED);
    expect(await sharedFirst.text()).toBe("xff=none\n");
    expect(await sharedSecond.text()).toBe("xff=none\n");

    const unshared = await get(rt, PUBLIC_HOST, "/visitor/unshared");
    expect(unshared.headers.get("cache-control")).toBe("no-store");
    expect(await unshared.text()).toBe("xff=127.0.0.1\n");
  });

  test("configured Authorization and Cookie route headers can reach upstream but never shared cache", async () => {
    const authorization = await get(rt, PUBLIC_HOST, "/configured-authorization");
    expect(authorization.status).toBe(200);
    expect(authorization.headers.get("cache-control")).toBe("no-store");
    expect(await authorization.json()).toEqual({
      authorization: "Bearer configured-token",
      cookie: null,
    });

    const cookie = await get(rt, PUBLIC_HOST, "/configured-cookie");
    expect(cookie.status).toBe(200);
    expect(cookie.headers.get("cache-control")).toBe("no-store");
    expect(await cookie.json()).toEqual({ authorization: null, cookie: "session=configured" });
  });

  test("GET and HEAD are eligible while a write method fails before reaching upstream", async () => {
    const getResponse = await get(rt, PUBLIC_HOST, "/upstream/public");
    expect(getResponse.headers.get("cache-control")).toBe(SHARED);
    const headResponse = await get(rt, PUBLIC_HOST, "/upstream/public", { method: "HEAD" });
    expect(headResponse.status).toBe(200);
    expect(headResponse.headers.get("cache-control")).toBe(SHARED);

    const beforePost = upstreamMethods.length;
    const postResponse = await get(rt, PUBLIC_HOST, "/upstream/public", { method: "POST" });
    expect(postResponse.status).toBe(405);
    // The provider edge is method-blind, so every non-GET/HEAD response is
    // pinned private no-store and opted out, whatever the lane composed.
    expect(postResponse.headers.get("cache-control")).toBe("private, no-store");
    expect(postResponse.headers.get("a8c-edge-cache")).toBe("no-cache");
    expect(upstreamMethods).toHaveLength(beforePost);
  });

  test("ETag revalidation forwards validators and preserves shared policy on 304", async () => {
    const miss = await get(rt, PUBLIC_HOST, "/revalidate");
    expect(miss.status).toBe(200);
    expect(miss.headers.get("etag")).toBe(ETAG);
    expect(miss.headers.get("cache-control")).toBe(SHARED);

    const revalidated = await get(rt, PUBLIC_HOST, "/revalidate", {
      headers: { "if-none-match": ETAG },
    });
    expect(revalidated.status).toBe(304);
    expect(revalidated.headers.get("etag")).toBe(ETAG);
    expect(revalidated.headers.get("cache-control")).toBe(SHARED);
    expect(revalidationHeaders).toEqual([undefined, ETAG]);
  });

  test("no spelling of the internal-redirect/sendfile family relays from an upstream", async () => {
    // php -S honours none of these headers, so this proves the runtime never puts them
    // back on the wire — it cannot prove nginx would have served the private tree. That
    // half needs the real provider (e2e-tests/direct).
    await Promise.all(
      Object.keys(INTERNAL_REDIRECT_HEADERS).map(async (name) => {
        const response = await get(rt, PUBLIC_HOST, `/sendfile/${name}`);
        expect(response.status, name).toBe(200);
        expect(response.headers.get(name), name).toBeNull();
        const body = await response.text();
        expect(body, name).toBe(`${name}\n`);
        expect(body, name).not.toContain(CROSS_SPACE_PRIVATE_BODY);
      }),
    );
  });

  test("ordinary response headers a naive strip set would break still relay", async () => {
    const redirect = await get(rt, PUBLIC_HOST, "/relay/location");
    expect(redirect.status).toBe(301);
    expect(redirect.headers.get("location")).toBe("https://relayed.example/moved");

    // The runtime relays the upstream's bytes without decoding them, so a readable body
    // here is the proof Content-Encoding survived the relay.
    const compressed = await get(rt, PUBLIC_HOST, "/relay/content-encoding");
    expect(compressed.status).toBe(200);
    expect(await compressed.text()).toBe(GZIP_RELAY_BODY);

    const ranged = await get(rt, PUBLIC_HOST, "/relay/content-range");
    expect(ranged.status).toBe(206);
    expect(ranged.headers.get("content-range")).toBe("bytes 0-3/9");
    expect(await ranged.text()).toBe("part");

    const rejected = await get(rt, PUBLIC_HOST, "/relay/allow");
    expect(rejected.status).toBe(405);
    expect(rejected.headers.get("allow")).toBe("GET, HEAD");
  });

  test("a truncated cache candidate is withheld and converted to an uncacheable error", async () => {
    const response = await get(rt, PUBLIC_HOST, "/truncated");
    expect(response.status).toBe(502);
    expect(response.headers.get("cache-control")).toBe("no-store");
    expect(await response.text()).not.toContain("partial");
  });
});
