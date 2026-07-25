import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { generateKeyPairSync } from "node:crypto";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";

import {
  deploy,
  errorCode,
  finalizeRaw,
  get,
  type Runtime,
  signEd25519Jwt,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

const SHARED = "public, max-age=0, s-maxage=60, must-revalidate";
const PUBLIC_HOST = "proxy-upstream.test";
const ACCESS_HOST = "proxy-access-cache.test";
const ETAG = '"proxy-upstream-v1"';

const tokenKeyPair = generateKeyPairSync("ed25519");
const ISSUER = visitorIssuer(tokenKeyPair.publicKey, [
  "user:",
  "email:",
  "team:",
  "link:",
  "invite:",
  "space:",
  "svc:",
  "sso:",
]);

let upstream: Server;
let upstreamBaseUrl: string;
let rt: Runtime;
const revalidationHeaders: Array<string | undefined> = [];
const upstreamMethods: string[] = [];

function visitorToken(host: string): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(tokenKeyPair.privateKey, ISSUER.kid, {
    sub: "link:proxy-cache",
    grants: ["link:proxy-cache"],
    aud: host,
    iat: now,
    exp: now + 3600,
  });
}

function proxyRule(destination: string, cache: "shared" | null = "shared") {
  return { destination, status: 200, action: "proxy", cache, order: 1 };
}

function proxyRedirects(paths: string[]) {
  return Object.fromEntries(
    paths.map((requestPath) => [requestPath, [proxyRule(`${upstreamBaseUrl}${requestPath}`)]]),
  );
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
      response.end(`xff=${request.headers["x-forwarded-for"] ?? "none"}\n`);
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
    ...[200, 203, 300, 301, 302, 403, 404, 410, 500].map((status) => `/status/${status}`),
  ];
  await deploy(rt, {
    spaceId: "spc_proxy_upstream",
    versionId: "ver_proxy_upstream_1",
    files: { "index.html": "proxy upstream fixture\n" },
    serving: {
      redirects_exact: {
        ...proxyRedirects(publicPaths),
        "/uncacheable": [proxyRule(`${upstreamBaseUrl}/upstream/public`, null)],
        "/visitor/shared": [proxyRule(`${upstreamBaseUrl}/visitor-ip`)],
        "/visitor/unshared": [proxyRule(`${upstreamBaseUrl}/visitor-ip`, null)],
      },
    },
    activate: {
      route_name: "production",
      config: {},
      production_hostnames: [PUBLIC_HOST],
      version_hostnames: [],
    },
  });

  await deploy(rt, {
    spaceId: "spc_proxy_access_cache",
    versionId: "ver_proxy_access_cache_1",
    files: { "index.html": "proxy access fixture\n" },
    serving: { redirects_exact: proxyRedirects(["/access/password", "/access/token"]) },
    activate: {
      route_name: "production",
      config: {
        secrets: { proxy_password: "swordfish" },
        policy: {
          rules: [
            {
              id: "password_proxy",
              match: { pathPattern: "/access/password" },
              effect: "challenge",
              auth: {
                requiredGrants: ["pw:password_proxy"],
                acquire: [
                  {
                    type: "password",
                    ref: "secret:proxy_password",
                    transport: "basic",
                    username: "ops",
                  },
                ],
              },
            },
            {
              id: "token_proxy",
              match: { pathPattern: "/access/token" },
              effect: "challenge",
              auth: { requiredGrants: ["link:proxy-cache"], issuers: [ISSUER] },
            },
          ],
        },
      },
      production_hostnames: [ACCESS_HOST],
      version_hostnames: [],
    },
  });

  const currentRoute = await Bun.file(`${rt.storageRoot}/routes/current.php`).text();
  const generation = /'generation' => '([^']+)'/.exec(currentRoute)?.[1];
  if (!generation) throw new Error("runtime route generation is missing");
  const shard = new Bun.CryptoHasher("sha256").update(PUBLIC_HOST).digest("hex").slice(0, 2);
  const shardPath = `${rt.storageRoot}/routes/generations/${generation}/hosts/${shard}.php`;
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
  const inject = Bun.spawnSync([
    "php",
    "-r",
    `$path = $argv[1]; $host = $argv[2]; $routes = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR); $artifact = include $path; $artifact['host_routes'][$host] = array_merge($routes, $artifact['host_routes'][$host] ?? []); file_put_contents($path, "<?php\nreturn " . var_export($artifact, true) . ";\n");`,
    shardPath,
    PUBLIC_HOST,
    routeFixture,
  ]);
  if (inject.exitCode !== 0) {
    throw new Error(`failed to install proxy route fixture: ${inject.stderr.toString()}`);
  }
});

afterAll(async () => {
  rt?.stop();
  if (upstream) await close(upstream);
});

describe("proxy shared-cache policy against a real PHP upstream path", () => {
  test("the egress test seam permits only its exact loopback host and port", async () => {
    const allowedPort = Number(new URL(upstreamBaseUrl).port);
    const response = await finalizeRaw(
      rt,
      "spc_proxy_wrong_port",
      "ver_proxy_wrong_port_1",
      { "index.html": "wrong port fixture\n" },
      {
        serving: {
          redirects_exact: {
            "/proxy": [proxyRule(`http://127.0.0.1:${allowedPort + 1}/wrong-port`)],
          },
        },
      },
    );
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe("runtime_artifact_validation_failed");
  });

  test("artifact ingress rejects any cache mode other than the exact shared literal", async () => {
    const response = await finalizeRaw(
      rt,
      "spc_proxy_invalid_cache",
      "ver_proxy_invalid_cache_1",
      { "index.html": "invalid cache fixture\n" },
      {
        serving: {
          redirects_exact: {
            "/proxy": [
              {
                ...proxyRule(`${upstreamBaseUrl}/upstream/public`),
                cache: true,
              },
            ],
          },
        },
      },
    );
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe("runtime_artifact_validation_failed");
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

  test("access and forwarded verified identity always pin private no-store", async () => {
    const password = await get(rt, ACCESS_HOST, "/access/password", {
      headers: { authorization: `Basic ${Buffer.from("ops:swordfish").toString("base64")}` },
    });
    expect(password.status).toBe(200);
    expect(password.headers.get("cache-control")).toBe("private, no-store");

    const token = visitorToken(ACCESS_HOST);
    const identity = await get(rt, ACCESS_HOST, "/access/token", {
      headers: { authorization: `Bearer ${token}` },
    });
    expect(identity.status).toBe(200);
    expect(identity.headers.get("cache-control")).toBe("private, no-store");
    expect(await identity.json()).toEqual({
      jwt: token,
      sub: "link:proxy-cache",
      grants: "link:proxy-cache",
    });
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
    expect(postResponse.headers.get("cache-control")).toBe("no-store");
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

  test("a truncated cache candidate is withheld and converted to an uncacheable error", async () => {
    const response = await get(rt, PUBLIC_HOST, "/truncated");
    expect(response.status).toBe(502);
    expect(response.headers.get("cache-control")).toBe("no-store");
    expect(await response.text()).not.toContain("partial");
  });
});
