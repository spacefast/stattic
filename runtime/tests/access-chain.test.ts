import { afterAll, beforeAll, describe, expect, setSystemTime, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, rmSync, writeFileSync } from "node:fs";
import { readdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { z } from "zod";

import { collabManifestSchema } from "../../packages/common/src/contracts/collab-manifest.ts";
import { RUNTIME_COLLAB_MANIFEST_PATH } from "../../packages/common/src/utils/runtime-paths.ts";
import {
  api,
  deploy,
  get,
  putRoute,
  RUNTIME_HTTP_API_BASE,
  type Runtime,
  signEd25519Jwt,
  spaceOverlay,
  spaceRoot,
  spaceSessionKey,
  startRuntime,
} from "./harness.ts";

let runtime: Runtime;
let exchangeServer: ReturnType<typeof Bun.serve>;
let exchangeCount = 0;
let expectedChallenge = "";
const usedCodes = new Set<string>();
const nonceForCode = new Map<string, string>();
const sessionVersionForCode = new Map<string, number>();
let beforeHandoff: ((code: string) => Promise<void>) | null = null;
let linkHandoff: (() => Promise<string>) | null = null;
const LINK_PATH = "/acquire/opaque/link";
const TOKEN_EXCHANGE_PATH = "/acquire/opaque/token";
let tokenHandoff: (() => Promise<string>) | null = null;
// The authorize endpoint and the issuer live on different origins here, as
// behind the dev relay or a split auth host.
const OAUTH_AUTHORIZE_URL = "https://api.spacefast.test/v1/auth/oauth2/authorize";
const OAUTH_ISSUER = "https://auth.spacefast.test/v1/auth";
// Where the box trades codes, on the API it syncs from: behind the dev relay
// that is not the issuer's host, which the box cannot reach.
const OAUTH_TOKEN_URL = "https://relay.spacefast.test/v1/auth/oauth2/token";
const tokenHosts: string[] = [];
const OAUTH_HOST = "oauth-chain.test";
const INVITE_TOKEN = "Bhf_1nv1t3_0ne_use_t0ken_x";
const OAUTH_SPACE = "spc_oauth_chain";
const OAUTH_VERSION = "ver_oauth_chain";

const HOST = "chain.test";
const SPACE = "spc_chain";
const VERSION = "ver_chain_1";
const GEN_HOST = "chain-gen.test";
const GEN_SPACE = "spc_chain_gen";
const GEN_VERSION = "ver_chain_gen_1";
const SHARE_REFERENCE =
  `external:${createHash("sha256").update("spacefast-share\0consent.view").digest("hex")}` as const;
const PREVIEW_HOST = "chain-preview--v1.test";
const PREVIEW_SPACE = "spc_chain_preview";
const PREVIEW_VERSION = "ver_chain_preview_1";
const MEMBER = "member:mem_chain_owner";
const PROTECTED = "private, no-store";
const EXCHANGE_CREDENTIAL = "runtime-chain-exchange-credential-0123456789";

const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-access-chain-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-access-chain-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-access-chain-test-token";
process.env.WP_CLOUD_CLIENT_NAME = "runtime-access-chain-test";

const [{ mintAuthorityToken }, { runtimeJwks }, unclaimedTerms, { signToken }, accessCore] =
  await Promise.all([
    import("../../apps/control-plane/src/access/authorize.ts"),
    import("../../apps/control-plane/src/runtime/auth.ts"),
    import("../../apps/control-plane/src/access/unclaimed-terms.ts"),
    import("../../apps/control-plane/src/auth/token-signer.ts"),
    import("../../packages/common/src/contracts/access-core.ts"),
  ]);

function accessConfig(accessGeneration: number, sessionVersion: number) {
  return {
    projection_generation: accessGeneration,
    authorization: {
      generation: accessGeneration,
      sessionVersion,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/opaque",
      accessPage: {
        displayName: null,
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/opaque/password",
          tokenUrl: "https://access.spacefast.test/acquire/opaque/token",
          requestUrl: "https://access.spacefast.test/acquire/opaque/request",
          credential: EXCHANGE_CREDENTIAL,
        },
      },
      spaceClaimed: true,
      grants: [
        {
          id: "grt_chain_owner",
          generation: 1,
          audience: {
            kind: "external",
            issuer: "spacefast-membership",
            subject: "mem_chain_owner",
          },
          resources: { include: ["/**"], exclude: [] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:access-chain" },
        },
      ],
    },
    visitor_issuer: "spacefast-api",
    visitor_jwks: runtimeJwks(),
  };
}

beforeAll(async () => {
  exchangeServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const exchangePath = new URL(request.url).pathname;
      if (exchangePath === LINK_PATH) {
        if (!linkHandoff) return Response.json({ code: "not_found" }, { status: 404 });
        return Response.json(
          { fields: { token: await linkHandoff(), return: "/docs/" } },
          { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
        );
      }
      if (exchangePath === TOKEN_EXCHANGE_PATH) {
        if (!tokenHandoff) return Response.json({ code: "unknown_credential" }, { status: 403 });
        return Response.json({ fields: { token: await tokenHandoff(), return: "/" } });
      }
      expect(request.headers.get("content-type")).toBe("application/x-www-form-urlencoded");
      const fields = z
        .strictObject({
          grant_type: z.literal("authorization_code"),
          client_id: z.string(),
          redirect_uri: z.string(),
          code: z.string(),
          code_verifier: z.string(),
        })
        .parse(Object.fromEntries(new URLSearchParams(await request.text())));
      // The named token URL's host, or the issuer's when a control plane names none.
      tokenHosts.push(request.headers.get("host") ?? "");
      expect(new URL(request.url).pathname).toBe("/v1/auth/oauth2/token");
      expect(request.headers.get("spacefast-runtime-exchange")).toBe(EXCHANGE_CREDENTIAL);
      expect(fields.client_id).toBe(`spacefast-share:${OAUTH_SPACE}`);
      expect(fields.redirect_uri).toBe(`https://${OAUTH_HOST}/__spacefast/callback`);
      expect(fields.code).toMatch(/^one-use-code/);
      expect(createHash("sha256").update(fields.code_verifier).digest("base64url")).toBe(
        expectedChallenge,
      );
      exchangeCount++;
      if (usedCodes.has(fields.code))
        return Response.json(
          { error: "invalid_grant", error_description: "The sign-in server refused this code." },
          { status: 400 },
        );
      usedCodes.add(fields.code);
      if (beforeHandoff) await beforeHandoff(fields.code);
      const now = Math.floor(Date.now() / 1000);
      return Response.json({
        access_token: "opaque-access-token",
        token_type: "Bearer",
        expires_in: 300,
        scope: "openid profile email",
        spacefast_handoff: {
          token: signEd25519Jwt(platformKey.privateKey, runtimeJwks().keys[0]?.kid ?? "", {
            sub: SHARE_REFERENCE,
            principal: "account:usr_oauth_chain",
            purpose: "handoff",
            authorities: [SHARE_REFERENCE],
            iss: "spacefast-api",
            aud: OAUTH_SPACE,
            host: OAUTH_HOST,
            spaceId: OAUTH_SPACE,
            generation: 1,
            sid: createHash("sha256").update(randomUUID()).digest("hex"),
            emailVerified: false,
            nonce: nonceForCode.get(fields.code) ?? "",
            sessionVersion: sessionVersionForCode.get(fields.code),
            iat: now,
            nbf: now,
            exp: now + 60,
            jti: randomUUID(),
          }),
          expires_at: now + 60,
        },
      });
    },
  });
  runtime = await startRuntime({
    visitorScheme: "https",
    env: {
      SPACEFAST_INSECURE_COOKIES: "",
      SPACEFAST_RUNTIME_TEST_ACCESS_SESSION_CLOCK: "1",
      PHP_CLI_SERVER_WORKERS: "4",
      SPACEFAST_ACCESS_EXCHANGE_TEST_CONNECT_ORIGIN: `http://127.0.0.1:${exchangeServer.port}`,
    },
  });
  const files = {
    "index.html": "<h1>private</h1>\n",
    "docs/index.html": "<h1>private docs</h1>\n",
  };
  await deploy(runtime, {
    spaceId: SPACE,
    versionId: VERSION,
    files,
    activate: {
      route_name: "production",
      config: accessConfig(1, 0),
      production_hostnames: [HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: GEN_SPACE,
    versionId: GEN_VERSION,
    files,
    activate: {
      route_name: "production",
      config: accessConfig(1, 0),
      production_hostnames: [GEN_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, { spaceId: PREVIEW_SPACE, versionId: PREVIEW_VERSION, files });
  const intent = await api(
    runtime,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${PREVIEW_SPACE}/hostname-intent`,
    "update_hostname_intent",
    { space_id: PREVIEW_SPACE },
    {
      production_hostnames: [],
      version_hostnames: [{ hostname: PREVIEW_HOST, version_id: PREVIEW_VERSION }],
    },
  );
  expect(intent.status).toBe(200);
});

afterAll(() => {
  runtime?.stop();
  exchangeServer?.stop(true);
});

async function mintCallbackToken({
  host = HOST,
  spaceId = SPACE,
  generation = 1,
  sessionVersion = 0,
  ttlSeconds,
  authority = MEMBER,
  principal,
}: {
  host?: string;
  spaceId?: string;
  generation?: number;
  sessionVersion?: number;
  ttlSeconds?: number;
  authority?: Parameters<typeof mintAuthorityToken>[0]["sub"];
  principal?: Parameters<typeof mintAuthorityToken>[0]["principal"];
} = {}) {
  return (
    await mintAuthorityToken({
      sub: authority,
      principal,
      authorities: [authority],
      spaceId,
      sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
      generation,
      sessionVersion,
      audience: host,
      jti: `jti_${randomUUID()}`,
      ...(ttlSeconds === undefined ? {} : { ttlSeconds }),
    })
  ).token;
}

function redeemHandoff(rt: Runtime, host: string, token: string, returnTo = "/") {
  return get(
    rt,
    host,
    `/__spacefast/in/${encodeURIComponent(token)}?return=${encodeURIComponent(returnTo)}`,
  );
}

test("OAuth callback binds the browser, keeps PKCE server-side and creates one runtime session", async () => {
  const config = accessConfig(1, 0);
  const owner = config.authorization.grants[0];
  if (!owner) throw new Error("owner fixture missing");
  owner.capabilities = [
    "page.view",
    "comments.read",
    "comments.write",
    "content.publish",
    "access.manage",
  ];
  config.authorization.grants.push({
    ...owner,
    id: "grt_consent_view",
    audience: { kind: "external", issuer: "spacefast-share", subject: "consent.view" },
    capabilities: ["page.view"],
  });
  config.authorization.grants.push({
    ...owner,
    id: "grt_chain_link",
    audience: { kind: "link", linkId: "lnk_chain" },
    capabilities: ["page.view", "comments.read"],
  });
  const sessionOf = (cookie: string) => {
    const php = Bun.spawnSync(
      [
        process.env.PHP_BINARY ?? "php",
        "-r",
        `
      require $argv[1];
      $input = json_decode(stream_get_contents(STDIN), true);
      $projection = _stattic_compile_authorization_projection($input['authorization']);
      $serving = ['space_id' => '${OAUTH_SPACE}', 'authorization' => [...$projection, 'sessionKey' => $input['sessionKey']]];
      $claims = _stattic_access_session_decode($serving, '${OAUTH_HOST}', $input['cookie']);
      if ($claims === null) throw new RuntimeException('invalid signed session');
      $refs = array_column($claims['authorities'], 'reference');
      echo json_encode([
        'principal' => $claims['principal'] ?? null,
        'identity' => $claims['identity']['subject'] ?? null,
        'capabilities' => _stattic_grant_decision($projection, '/', ['kind' => 'live'], $refs, $refs)['capabilities'],
      ]);
    `,
        fileURLToPath(new URL("../engine/runtime/access-rules.php", import.meta.url)),
      ],
      {
        stdin: Buffer.from(
          JSON.stringify({
            authorization: config.authorization,
            sessionKey: spaceSessionKey(runtime, OAUTH_SPACE),
            cookie: cookie.slice(cookie.indexOf("=") + 1),
          }),
        ),
      },
    );
    expect(php.exitCode, php.stderr.toString()).toBe(0);
    return z
      .object({
        principal: z.string().nullable(),
        identity: z.string().nullable(),
        capabilities: z.array(z.string()),
      })
      .parse(JSON.parse(php.stdout.toString()));
  };
  const capabilities = (cookie: string) => sessionOf(cookie).capabilities;
  await deploy(runtime, {
    spaceId: OAUTH_SPACE,
    versionId: OAUTH_VERSION,
    files: { "index.html": "private OAuth home", "docs/index.html": "private OAuth docs" },
    activate: {
      route_name: "production",
      production_hostnames: [OAUTH_HOST, "oauth-alias.test"],
      version_hostnames: [],
      config: {
        ...config,
        authorization: {
          ...config.authorization,
          accessPage: {
            ...config.authorization.accessPage,
            accountUrl: OAUTH_AUTHORIZE_URL,
            issuer: OAUTH_ISSUER,
            tokenUrl: OAUTH_TOKEN_URL,
          },
        },
      },
    },
  });
  const privateRoot = `${runtime.storageRoot}/spaces/${OAUTH_SPACE}`;
  const before = await readdir(privateRoot, { recursive: true });
  const jar = new Map<string, string>();
  const keepCookies = (response: Response) => {
    for (const header of response.headers.getSetCookie()) {
      const pair = header.split(";")[0] ?? "";
      const split = pair.indexOf("=");
      const name = pair.slice(0, split);
      if (/max-age=0/i.test(header) || pair.slice(split + 1) === "") jar.delete(name);
      else jar.set(name, pair);
    }
  };
  const cookies = () => [...jar.values()].join("; ");
  const challenges = new Set<string>();
  let browserCookie = "";
  for (let attempt = 0; attempt < 30; attempt++) {
    const response = await get(runtime, OAUTH_HOST, "/__spacefast/sign-in", {
      headers: { cookie: browserCookie },
    });
    expect(response.status).toBe(302);
    expect(response.headers.get("referrer-policy")).toBe("no-referrer");
    challenges.add(
      new URL(response.headers.get("location") ?? "").searchParams.get("code_challenge") ?? "",
    );
    keepCookies(response);
    browserCookie = cookies();
  }
  expect(challenges.size).toBe(30);
  expect(jar.size).toBe(1);
  expect(browserCookie.length).toBeLessThan(3600);
  expect(
    (await readdir(privateRoot, { recursive: true })).filter((path) => !before.includes(path)),
  ).toEqual([]);
  const probe = await get(runtime, OAUTH_HOST, "/docs/?tab=9", {
    headers: { "sec-fetch-dest": "document" },
  });
  expect(probe.status).toBe(302);
  const probed = new URL(probe.headers.get("location") ?? "");
  expect(probed.origin + probed.pathname).toBe(OAUTH_AUTHORIZE_URL);
  expect(probed.searchParams.get("client_id")).toBe(`spacefast-share:${OAUTH_SPACE}`);
  expect(probed.searchParams.get("response_type")).toBe("code");
  expect(probed.searchParams.get("scope")).toBe("openid profile email");
  expect(probed.searchParams.get("nonce")).toMatch(/^[a-f0-9]{64}$/);
  expect(probed.searchParams.has("space_id")).toBe(false);
  expect(probed.searchParams.get("redirect_uri")).toBe(
    `https://${OAUTH_HOST}/__spacefast/callback`,
  );
  expect(probed.searchParams.get("prompt")).toBe("none");
  expect(probed.searchParams.get("code_challenge")).toMatch(/^[A-Za-z0-9_-]{43}$/);
  expect(probed.searchParams.get("return")).toBe("/docs/?tab=9");
  const probeCookies = probe.headers
    .getSetCookie()
    .map((header) => header.split(";")[0] ?? "")
    .join("; ");
  const refusedProbe = await get(
    runtime,
    OAUTH_HOST,
    `/__spacefast/callback?error=access_denied&state=${probed.searchParams.get("state")}&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
    { headers: { cookie: probeCookies } },
  );
  expect(refusedProbe.status).toBe(303);
  expect(refusedProbe.headers.get("location")).toBe("/docs/?tab=9");
  const gate = await get(runtime, OAUTH_HOST, "/docs/?tab=9", {
    headers: { cookie: probeCookies, "sec-fetch-dest": "document" },
  });
  expect(gate.status).toBe(403);
  const gateHtml = await gate.text();
  expect(gateHtml).toContain(">Continue with Spacefast</a>");
  expect(gateHtml).not.toContain("data-sf-access-popup");
  const refused = await (
    await get(runtime, OAUTH_HOST, "/docs/?sf_access=no-grant", {
      headers: { cookie: probeCookies },
    })
  ).text();
  const switchHref = refused.match(/href="([^"]+)">Use another account<\/a>/)?.[1];
  expect(switchHref).toBe("/__spacefast/sign-in?return=%2Fdocs%2F&amp;prompt=select_account");
  const switched = await get(runtime, OAUTH_HOST, switchHref?.replaceAll("&amp;", "&") ?? "");
  expect(switched.status).toBe(302);
  const switchedTo = new URL(switched.headers.get("location") ?? "");
  expect(switchedTo.origin + switchedTo.pathname).toBe(OAUTH_AUTHORIZE_URL);
  expect(switchedTo.searchParams.get("prompt")).toBe("select_account");
  expect(switchedTo.searchParams.get("return")).toBe("/docs/");
  expect(exchangeCount).toBe(0);
  const ownerResponse = await redeemHandoff(
    runtime,
    OAUTH_HOST,
    await mintCallbackToken({ host: OAUTH_HOST, spaceId: OAUTH_SPACE }),
  );
  expect(ownerResponse.status).toBe(303);
  const ownerCookie =
    ownerResponse.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_session="))
      ?.split(";")[0] ?? "";
  expect(capabilities(ownerCookie)).toContain("access.manage");
  const startedAt = Math.floor(Date.now() / 1000);
  expect(gateHtml).toContain('href="/__spacefast/sign-in?return=%2Fdocs%2F%3Ftab%3D9"');
  const start = await get(
    runtime,
    OAUTH_HOST,
    `/__spacefast/sign-in?return=%2Fdocs%2F%3Ftab%3D2&invite=${INVITE_TOKEN}`,
    { headers: { cookie: ownerCookie, "x-spacefast-test-access-session-now": String(startedAt) } },
  );
  expect(start.status).toBe(302);
  const destination = new URL(start.headers.get("location") ?? "");
  expect(destination.origin + destination.pathname).toBe(OAUTH_AUTHORIZE_URL);
  expect(destination.searchParams.get("code_challenge_method")).toBe("S256");
  expect(destination.searchParams.has("code_verifier")).toBe(false);
  expect(destination.searchParams.get("return")).toBe("/docs/?tab=2");
  expect(destination.searchParams.get("invite")).toBe(INVITE_TOKEN);
  expect(destination.searchParams.has("prompt")).toBe(false);
  // A page the older gate rendered before this Space took the new sign-in
  // still links its switch as `addAccount=1`, which asks the same.
  for (const [query, expected] of [
    ["prompt=select_account", "select_account"],
    ["addAccount=1", "select_account"],
    ["prompt=login", null],
  ] as const) {
    const asked = await get(
      runtime,
      OAUTH_HOST,
      `/__spacefast/sign-in?return=%2Fdocs%2F&${query}`,
      { headers: { cookie: ownerCookie } },
    );
    expect(asked.status).toBe(302);
    expect(new URL(asked.headers.get("location") ?? "").searchParams.get("prompt")).toBe(expected);
  }
  expectedChallenge = destination.searchParams.get("code_challenge") ?? "";
  const state = destination.searchParams.get("state");
  expect(state).toMatch(/^[a-f0-9]{64}$/);
  const cookie = (start.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  nonceForCode.set("one-use-code", destination.searchParams.get("nonce") ?? "");
  const callback = `/__spacefast/callback?code=one-use-code&state=${state}&iss=${encodeURIComponent(OAUTH_ISSUER)}`;
  // RFC 9207: a response that names another issuer, the authorize origin's
  // guess included, or none at all is refused before its code goes anywhere.
  for (const iss of [
    "https://other.example/v1/auth",
    "https://api.spacefast.test/v1/auth",
    undefined,
  ]) {
    const mixedUp = await get(
      runtime,
      OAUTH_HOST,
      `/__spacefast/callback?code=one-use-code&state=${state}${iss ? `&iss=${encodeURIComponent(iss)}` : ""}`,
      { headers: { cookie } },
    );
    expect(mixedUp.status).toBe(403);
    expect(mixedUp.headers.get("content-type")).toBe("text/html; charset=utf-8");
    const restart = await mixedUp.text();
    expect(restart).toContain("<h1>Sign-in didn’t finish</h1>");
    expect(restart).toContain("Start sign-in again in this browser.");
    expect(restart).toContain('href="/__spacefast/sign-in?return=%2F">Sign In Again</a>');
  }
  const denied = await get(runtime, OAUTH_HOST, callback);
  expect(denied.status).toBe(403);
  expect(denied.headers.get("referrer-policy")).toBe("no-referrer");
  const browserB = await get(runtime, OAUTH_HOST, callback, { headers: { cookie: browserCookie } });
  expect(browserB.status).toBe(403);
  expect(
    browserB.headers.getSetCookie().some((value) => value.startsWith("__Host-spacefast_session=")),
  ).toBe(false);
  expect((await get(runtime, "oauth-alias.test", callback, { headers: { cookie } })).status).toBe(
    403,
  );
  expect(
    (
      await get(runtime, OAUTH_HOST, callback, {
        headers: { cookie, "x-spacefast-test-access-session-now": String(startedAt + 600) },
      })
    ).status,
  ).toBe(403);
  const tamperedCookie = cookie.slice(0, -1) + (cookie.endsWith("0") ? "1" : "0");
  expect(
    (await get(runtime, OAUTH_HOST, callback, { headers: { cookie: tamperedCookie } })).status,
  ).toBe(403);
  expect(exchangeCount).toBe(0);
  nonceForCode.set("one-use-code-other-nonce", "f".repeat(64));
  const otherNonce = await get(
    runtime,
    OAUTH_HOST,
    `/__spacefast/callback?code=one-use-code-other-nonce&state=${state}&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
    { headers: { cookie: `${cookie}; ${ownerCookie}` } },
  );
  expect(otherNonce.status).toBe(303);
  expect(otherNonce.headers.get("location")).toBe("/docs/?tab=2&sf_access=exchange-unavailable");
  expect(
    otherNonce.headers
      .getSetCookie()
      .some((value) => value.startsWith("__Host-spacefast_session=")),
  ).toBe(false);
  expect(exchangeCount).toBe(1);
  expect(tokenHosts).toEqual([new URL(OAUTH_TOKEN_URL).host]);
  const restart = await get(
    runtime,
    OAUTH_HOST,
    `/__spacefast/sign-in?return=%2Fdocs%2F%3Ftab%3D2&invite=${INVITE_TOKEN}`,
    { headers: { cookie: ownerCookie } },
  );
  const restarted = new URL(restart.headers.get("location") ?? "");
  expectedChallenge = restarted.searchParams.get("code_challenge") ?? "";
  nonceForCode.set("one-use-code", restarted.searchParams.get("nonce") ?? "");
  const restartedState = restarted.searchParams.get("state") ?? "";
  const restartedCookie = (restart.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  const restartedCallback = `/__spacefast/callback?code=one-use-code&state=${restartedState}&iss=${encodeURIComponent(OAUTH_ISSUER)}`;
  const accepted = await get(runtime, OAUTH_HOST, restartedCallback, {
    headers: { cookie: `${restartedCookie}; ${ownerCookie}` },
  });
  expect(accepted.status).toBe(303);
  expect(accepted.headers.get("referrer-policy")).toBe("no-referrer");
  expect(accepted.headers.get("location")).toBe("/docs/?tab=2");
  const sessionHeader = accepted.headers
    .getSetCookie()
    .find((value) => value.includes("spacefast_session"));
  expect(sessionHeader).toContain("HttpOnly");
  expect(sessionHeader).toContain("__Host-spacefast_session=");
  expect(sessionHeader).toContain("Secure");
  expect(
    accepted.headers
      .getSetCookie()
      .some((value) => value.startsWith("__Host-spacefast_returning=1")),
  ).toBe(true);
  const signedInSession = (
    accepted.headers.getSetCookie().find((value) => value.includes("spacefast_session")) ?? ""
  ).split(";")[0];
  const manifest = collabManifestSchema.parse(
    await (
      await get(runtime, OAUTH_HOST, RUNTIME_COLLAB_MANIFEST_PATH, {
        headers: { cookie: signedInSession ?? "" },
      })
    ).json(),
  );
  expect(manifest.returningAccountCookie).toBe("__Host-spacefast_returning");
  expect(manifest.accountUrl).toBe(`https://${OAUTH_HOST}/__spacefast/access/account`);
  const renewal = await get(
    runtime,
    OAUTH_HOST,
    "/__spacefast/access/account?silent=1&return=%2Fdocs%2F%3F__%3Dsfl_secret",
    { headers: { cookie: "__Host-spacefast_returning=1" } },
  );
  expect(renewal.status).toBe(302);
  const renewed = new URL(renewal.headers.get("location") ?? "");
  expect(renewed.origin + renewed.pathname).toBe(OAUTH_AUTHORIZE_URL);
  expect(renewed.searchParams.get("client_id")).toBe(`spacefast-share:${OAUTH_SPACE}`);
  expect(renewed.searchParams.get("prompt")).toBe("none");
  expect(renewed.searchParams.get("return")).toBe("/docs/");
  expect(
    renewal.headers
      .getSetCookie()
      .some(
        (value) =>
          value.startsWith("__Host-spacefast_returning=;") ||
          /^__Host-spacefast_returning=[^;]*;.*Max-Age=0/i.test(value),
      ),
  ).toBe(true);
  const session = sessionHeader?.split(";")[0] ?? "";
  expect(capabilities(session)).toEqual(["page.view"]);
  expect((await get(runtime, OAUTH_HOST, "/docs/", { headers: { cookie: session } })).status).toBe(
    200,
  );
  expect(
    accepted.headers.getSetCookie().some((value) => value.startsWith(`${cookie.split("=")[0]}=;`)),
  ).toBe(true);
  const replay = await get(runtime, OAUTH_HOST, restartedCallback, {
    headers: { cookie: restartedCookie },
  });
  expect(replay.status).toBe(303);
  expect(replay.headers.get("location")).toBe("/docs/?tab=2&sf_access=exchange-unavailable");
  expect(
    replay.headers.getSetCookie().some((value) => value.startsWith("__Host-spacefast_session=")),
  ).toBe(false);
  expect(exchangeCount).toBe(3);
  jar.clear();
  const tabs: URL[] = [];
  for (let tab = 0; tab < 4; tab++) {
    const begin = await get(runtime, OAUTH_HOST, `/__spacefast/sign-in?return=/docs/?tab=${tab}`, {
      headers: { cookie: cookies() },
    });
    keepCookies(begin);
    tabs.push(new URL(begin.headers.get("location") ?? ""));
  }
  const oldest = tabs[0];
  expect(oldest).toBeDefined();
  const evicted = await get(
    runtime,
    OAUTH_HOST,
    `/__spacefast/callback?state=${oldest?.searchParams.get("state")}&code=one-use-code-evicted&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
    { headers: { cookie: cookies() } },
  );
  expect(evicted.status).toBe(403);
  expect(await evicted.text()).toContain("Start sign-in again in this browser.");
  for (const [tab, url] of tabs.entries()) {
    if (tab === 0) continue;
    expectedChallenge = url.searchParams.get("code_challenge") ?? "";
    nonceForCode.set(`one-use-code-${tab}`, url.searchParams.get("nonce") ?? "");
    const response = await get(
      runtime,
      OAUTH_HOST,
      `/__spacefast/callback?state=${url.searchParams.get("state")}&code=one-use-code-${tab}&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
      { headers: { cookie: cookies() } },
    );
    expect(response.status).toBe(303);
    expect(response.headers.get("location")).toBe(`/docs/?tab=${tab}`);
    keepCookies(response);
  }
  expect([...jar.keys()].filter((name) => name.startsWith("__Host-spacefast_oauth_"))).toEqual([]);
  const handoff = (claims: { sub: string; principal?: string }) => {
    const now = Math.floor(Date.now() / 1000);
    return signEd25519Jwt(platformKey.privateKey, runtimeJwks().keys[0]?.kid ?? "", {
      ...claims,
      purpose: "handoff",
      authorities: [claims.sub],
      iss: "spacefast-api",
      aud: OAUTH_SPACE,
      host: OAUTH_HOST,
      spaceId: OAUTH_SPACE,
      generation: 1,
      sid: createHash("sha256").update(randomUUID()).digest("hex"),
      emailVerified: false,
      iat: now,
      nbf: now,
      exp: now + 60,
      jti: randomUUID(),
    });
  };
  const redeemOver = async (held: string, token: string) => {
    const response = await get(
      runtime,
      OAUTH_HOST,
      `/__spacefast/in/${encodeURIComponent(token)}?return=%2Fdocs%2F`,
      { headers: { cookie: held } },
    );
    expect(response.status).toBe(303);
    return (
      response.headers
        .getSetCookie()
        .find((value) => value.startsWith("__Host-spacefast_session="))
        ?.split(";")[0] ?? ""
    );
  };
  const manager = await redeemOver(
    "",
    handoff({ sub: MEMBER, principal: "account:usr_oauth_chain" }),
  );
  expect(capabilities(manager)).toContain("access.manage");
  const otherAccount = await redeemOver(
    manager,
    handoff({ sub: SHARE_REFERENCE, principal: "account:usr_oauth_other" }),
  );
  expect(sessionOf(otherAccount)).toEqual({
    principal: "account:usr_oauth_other",
    identity: null,
    capabilities: ["page.view"],
  });
  const sameAccount = await redeemOver(
    manager,
    handoff({ sub: SHARE_REFERENCE, principal: "account:usr_oauth_chain" }),
  );
  expect(sessionOf(sameAccount).principal).toBe("account:usr_oauth_chain");
  expect(capabilities(sameAccount)).toContain("access.manage");
  const managerWithLink = await redeemOver(manager, handoff({ sub: "link:lnk_chain" }));
  expect(sessionOf(managerWithLink).principal).toBe("account:usr_oauth_chain");
  expect(capabilities(managerWithLink)).toEqual(
    expect.arrayContaining(["access.manage", "comments.read"]),
  );
  const guest = await redeemOver("", handoff({ sub: "link:lnk_chain" }));
  expect(capabilities(guest)).toContain("comments.read");
  const guestSignedIn = await redeemOver(
    guest,
    handoff({ sub: SHARE_REFERENCE, principal: "account:usr_oauth_other" }),
  );
  expect(sessionOf(guestSignedIn).principal).toBe("account:usr_oauth_other");
  expect(capabilities(guestSignedIn)).toContain("comments.read");
  const exchangesBeforeRotation = exchangeCount;
  await putRoute(runtime, OAUTH_SPACE, "production", {
    version_id: OAUTH_VERSION,
    config: {
      ...config,
      authorization: {
        ...config.authorization,
        accessPage: {
          ...config.authorization.accessPage,
          accountUrl: OAUTH_AUTHORIZE_URL,
          issuer: OAUTH_ISSUER,
          tokenUrl: OAUTH_TOKEN_URL,
          exchange: {
            ...config.authorization.accessPage.exchange,
            credential: `${EXCHANGE_CREDENTIAL}-rotated`,
          },
        },
      },
    },
  });
  expect((await get(runtime, OAUTH_HOST, "/docs/", { headers: { cookie: session } })).status).toBe(
    200,
  );
  expect(capabilities(session)).toEqual(["page.view"]);
  expect(exchangeCount).toBe(exchangesBeforeRotation);
});

test("control-plane mint and JWKS drive a real private runtime session", async () => {
  const anonymous = await get(runtime, HOST, "/docs/");
  expect(anonymous.status).toBe(403);
  expect(anonymous.headers.get("cache-control")).toBe("private, no-store");

  const token = await mintCallbackToken();
  const callback = await redeemHandoff(runtime, HOST, token, "/docs/");
  expect(callback.status).toBe(303);
  expect(callback.headers.get("location")).toBe("/docs/");
  expect(callback.headers.get("cache-control")).toBe("private, no-store");
  expect(callback.headers.get("referrer-policy")).toBe("no-referrer");
  const cookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_session");

  const served = await get(runtime, HOST, "/docs/", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe(PROTECTED);
  expect(await served.text()).toBe("<h1>private docs</h1>\n");

  const replay = await redeemHandoff(runtime, HOST, token, "/docs/");
  expect(replay.status).toBe(404);
  expect((await get(runtime, HOST, "/docs/", { headers: { cookie } })).status).toBe(200);
});

test("an old control plane's /__sf/redeem handoff signs the person in on this engine", async () => {
  // The URL a control plane that predates /__spacefast/in/ builds (S12). An old
  // image can still reach this engine during a deploy.
  const { oldEngineHandoffUrl } = await import("../../apps/control-plane/src/compat/old-engine.ts");
  const token = await mintCallbackToken();
  const legacy = oldEngineHandoffUrl(true, { host: HOST, token, returnTo: "/docs/" });
  expect(legacy.pathname).toBe("/__sf/redeem");
  const callback = await get(runtime, HOST, `${legacy.pathname}${legacy.search}`);
  expect(callback.status).toBe(303);
  expect(callback.headers.get("location")).toBe("/docs/");
  expect(callback.headers.get("cache-control")).toBe("private, no-store");
  expect(callback.headers.get("referrer-policy")).toBe("no-referrer");
  const cookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect((await get(runtime, HOST, "/docs/", { headers: { cookie } })).status).toBe(200);

  expect((await redeemHandoff(runtime, HOST, token, "/docs/")).status).toBe(404);
  const silent = oldEngineHandoffUrl(true, { host: HOST, token, returnTo: "/docs/", silent: true });
  const bounced = await get(runtime, HOST, `${silent.pathname}${silent.search}`);
  expect(bounced.status).toBe(303);
  expect(bounced.headers.get("location")).toBe("/docs/");
  expect(bounced.headers.get("set-cookie")).toBeNull();

  // A released CLI posts an old control plane's JSON handoff there as a
  // same-origin form; a cross-origin post never spends the token.
  const posted = await mintCallbackToken();
  const form = (origin: string) =>
    get(runtime, HOST, legacy.pathname, {
      method: "POST",
      headers: { origin, "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ token: posted, return: "/docs/" }).toString(),
    });
  expect((await form("https://elsewhere.example.test")).status).toBe(403);
  const cli = await form(`https://${HOST}`);
  expect(cli.status).toBe(303);
  expect(cli.headers.get("location")).toBe("/docs/");
  const cliCookie = (cli.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect((await get(runtime, HOST, "/docs/", { headers: { cookie: cliCookie } })).status).toBe(200);
});

test("a handoff is gated on the overlay's access_gen alone, never on the summed tuple", async () => {
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 0 });

  const redeem = async (generation: number): Promise<number> => {
    const token = await mintCallbackToken({ host: GEN_HOST, spaceId: GEN_SPACE, generation });
    const response = await redeemHandoff(runtime, GEN_HOST, token);
    return response.status;
  };

  expect(await redeem(2)).toBe(404);

  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(1, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 1 });
  expect(await redeem(2)).toBe(404);

  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(2, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 2, session_ver: 1 });

  const admitted = await redeemHandoff(
    runtime,
    GEN_HOST,
    await mintCallbackToken({
      host: GEN_HOST,
      spaceId: GEN_SPACE,
      generation: 2,
      sessionVersion: 1,
    }),
    "/docs/",
  );
  expect(admitted.status).toBe(303);
  const cookie = (admitted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  const served = await get(runtime, GEN_HOST, "/docs/", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("<h1>private docs</h1>\n");
});

test("a handoff issued before everyone was signed out opens no session", async () => {
  const host = "chain-held.test";
  const spaceId = "spc_chain_held";
  const versionId = "ver_chain_held_1";
  const project = (sessionVersion: number) =>
    putRoute(runtime, spaceId, "production", {
      version_id: versionId,
      config: accessConfig(1, sessionVersion),
    });
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>private</h1>\n", "docs/index.html": "<h1>private docs</h1>\n" },
    activate: {
      route_name: "production",
      config: accessConfig(1, 0),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const mint = (sessionVersion: number) =>
    mintCallbackToken({ host, spaceId, sessionVersion, principal: "account:usr_held" });
  const sessionCookie = (response: Response) =>
    response.headers
      .getSetCookie()
      .find((value) => value.startsWith("__Host-spacefast_session="))
      ?.split(";")[0];
  const docs = (cookie: string) => get(runtime, host, "/docs/", { headers: { cookie } });
  const legacy = (token: string, extra = "") =>
    get(
      runtime,
      host,
      `/__sf/redeem?sf_token=${encodeURIComponent(token)}&return=%2Fdocs%2F${extra}`,
    );

  const held = await Promise.all([mint(0), mint(0), mint(0), mint(0)]);
  await project(1);
  const [inPath, redeemGet, redeemPost, silent] = [
    await redeemHandoff(runtime, host, held[0] ?? "", "/docs/"),
    await legacy(held[1] ?? ""),
    await get(runtime, host, "/__sf/redeem", {
      method: "POST",
      headers: { origin: `https://${host}`, "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ token: held[2] ?? "", return: "/docs/" }).toString(),
    }),
    await legacy(held[3] ?? "", "&silent=1"),
  ];
  for (const refused of [inPath, redeemGet, redeemPost]) {
    expect(refused.status).toBe(404);
    expect(sessionCookie(refused)).toBeUndefined();
  }
  expect(silent.status).toBe(303);
  expect(silent.headers.get("location")).toBe("/docs/");
  expect(sessionCookie(silent)).toBeUndefined();
  const fresh = await redeemHandoff(runtime, host, await mint(1), "/docs/");
  expect(fresh.status).toBe(303);
  expect(fresh.headers.get("location")).toBe("/docs/");
  expect((await docs(sessionCookie(fresh) ?? "")).status).toBe(200);

  // An older control plane names no version: accepted as before.
  const now = Math.floor(Date.now() / 1000);
  const unversioned = signEd25519Jwt(platformKey.privateKey, runtimeJwks().keys[0]?.kid ?? "", {
    sub: MEMBER,
    principal: "account:usr_held",
    purpose: "handoff",
    v: 1,
    authorities: [MEMBER],
    iss: "spacefast-api",
    aud: spaceId,
    host,
    spaceId,
    generation: 1,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    emailVerified: false,
    iat: now,
    nbf: now,
    exp: now + 60,
    jti: randomUUID(),
  });
  const older = await redeemHandoff(runtime, host, unversioned, "/docs/");
  expect(older.status).toBe(303);
  expect(older.headers.get("location")).toBe("/docs/");
  expect((await docs(sessionCookie(older) ?? "")).status).toBe(200);

  const ahead = await redeemHandoff(runtime, host, await mint(2), "/docs/");
  expect(ahead.status).toBe(303);
  const aheadCookie = sessionCookie(ahead) ?? "";
  expect((await docs(aheadCookie)).status).toBe(200);
  await project(2);
  expect((await docs(aheadCookie)).status).toBe(403);
});

test("a link attached while everyone is signed out carries nothing from the session it joins", async () => {
  const host = "chain-attach.test";
  const spaceId = "spc_chain_attach";
  const versionId = "ver_chain_attach_1";
  const config = (sessionVersion: number) => {
    const base = accessConfig(1, sessionVersion);
    const { exchange } = base.authorization.accessPage;
    return {
      ...base,
      authorization: {
        ...base.authorization,
        accessPage: {
          ...base.authorization.accessPage,
          exchange: { ...exchange, linkUrl: `https://access.spacefast.test${LINK_PATH}` },
        },
        grants: [
          ...base.authorization.grants,
          {
            ...base.authorization.grants[0],
            id: "grt_chain_attach_link",
            audience: { kind: "link", linkId: "lnk_chain_attach" },
            resources: { include: ["/docs/**"], exclude: [] },
          },
        ],
      },
    };
  };
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>private</h1>\n", "docs/index.html": "<h1>private docs</h1>\n" },
    activate: {
      route_name: "production",
      config: config(0),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const sessionCookie = (response: Response) =>
    response.headers
      .getSetCookie()
      .find((value) => value.startsWith("__Host-spacefast_session="))
      ?.split(";")[0] ?? "";
  const page = (cookie: string, pagePath: string) =>
    get(runtime, host, pagePath, { headers: { cookie } });
  const claims = (cookie: string) => {
    const body = cookie.slice(cookie.indexOf("=") + 1 + "sfv2_".length);
    return z
      .object({
        principal: z.string(),
        sessionVersion: z.number(),
        identity: z.unknown().optional(),
      })
      .parse(JSON.parse(Buffer.from(body.slice(0, body.lastIndexOf(".")), "base64url").toString()));
  };

  const signedIn = await redeemHandoff(
    runtime,
    host,
    await mintCallbackToken({ host, spaceId, sessionVersion: 0, principal: "account:usr_attach" }),
    "/",
  );
  const owner = sessionCookie(signedIn);
  expect((await page(owner, "/")).status).toBe(200);

  linkHandoff = async () => {
    await putRoute(runtime, spaceId, "production", { version_id: versionId, config: config(1) });
    return mintCallbackToken({
      host,
      spaceId,
      sessionVersion: 1,
      authority: "link:lnk_chain_attach",
    });
  };
  let attached: Response;
  try {
    attached = await get(
      runtime,
      host,
      "/__spacefast/in/sfl_chain-attach_0123456789?return=%2Fdocs%2F",
      {
        headers: { cookie: owner },
      },
    );
  } finally {
    linkHandoff = null;
  }
  expect(attached.status).toBe(303);
  expect(attached.headers.get("location")).toBe("/docs/");
  const linked = sessionCookie(attached);
  expect((await page(linked, "/docs/")).status).toBe(200);
  expect((await page(linked, "/")).status).toBe(403);
  expect(claims(linked)).toMatchObject({ principal: "anonymous", sessionVersion: 1 });
  expect(claims(linked).identity).toBeUndefined();
  expect((await page(owner, "/")).status).toBe(403);
});

test("a real-authority sign-in is capped at view and comment and ends by its session id", async () => {
  const host = "chain-real.test";
  const spaceId = "spc_chain_real";
  const versionId = "ver_chain_real_1";
  const config = (generation: number, endedSessions?: string[]) => {
    const base = accessConfig(generation, 0);
    const owner = base.authorization.grants[0];
    if (!owner) throw new Error("owner fixture missing");
    owner.capabilities = [
      "page.view",
      "comments.read",
      "comments.write",
      "content.publish",
      "access.manage",
    ];
    return {
      ...base,
      authorization:
        endedSessions === undefined ? base.authorization : { ...base.authorization, endedSessions },
    };
  };
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>private</h1>\n" },
    activate: {
      route_name: "production",
      config: config(1),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const capped = async (consent: string) => {
    const sessionId = createHash("sha256").update(randomUUID()).digest("hex");
    const consentAuthority =
      `external:${createHash("sha256").update(`spacefast-share\0${consent}`).digest("hex")}` as const;
    const token = (
      await mintAuthorityToken({
        sub: consentAuthority,
        principal: `account:usr_${consent}`,
        authorities: [consentAuthority],
        spaceOrigin: {
          authorities: [MEMBER],
          capabilities: ["page.view", "comments.read", "comments.write"],
        },
        spaceId,
        sessionId,
        generation: 1,
        sessionVersion: 0,
        audience: host,
        jti: `jti_${randomUUID()}`,
      })
    ).token;
    const response = await redeemHandoff(runtime, host, token, "/");
    expect(response.status).toBe(303);
    const cookie =
      response.headers
        .getSetCookie()
        .find((value) => value.startsWith("__Host-spacefast_session="))
        ?.split(";")[0] ?? "";
    expect(cookie).toContain("sfv2_");
    return { sessionId, cookie };
  };
  const first = await capped("cns_first");
  const second = await capped("cns_second");
  const page = (cookie: string) => get(runtime, host, "/", { headers: { cookie } });
  expect((await page(first.cookie)).status).toBe(200);
  const fenced = config(1);
  await putRoute(runtime, spaceId, "production", {
    version_id: versionId,
    config: { ...fenced, authorization: { ...fenced.authorization, fence: "exposure" } },
  });
  expect((await page(first.cookie)).status).toBe(403);
  await putRoute(runtime, spaceId, "production", { version_id: versionId, config: config(1) });
  expect((await page(first.cookie)).status).toBe(200);
  await putRoute(runtime, spaceId, "production", {
    version_id: versionId,
    config: config(1, [first.sessionId]),
  });
  expect((await page(first.cookie)).status).toBe(403);
  expect((await page(second.cookie)).status).toBe(200);
});

test("a header handoff issued before everyone was signed out admits nothing", async () => {
  const host = "chain-header.test";
  const spaceId = "spc_chain_header";
  const versionId = "ver_chain_header_1";
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "index.html": "<h1>private</h1>\n" },
    activate: {
      route_name: "production",
      config: accessConfig(1, 0),
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
  const signOutEveryone = (sessionVersion: number) =>
    putRoute(runtime, spaceId, "production", {
      version_id: versionId,
      config: accessConfig(1, sessionVersion),
    });
  const present = (token: string) =>
    get(runtime, host, "/", { headers: { "x-sf-authorization": `Bearer ${token}` } });
  const presentOpaque = (token: Promise<string>, during?: () => Promise<void>) => {
    tokenHandoff = async () => {
      const minted = await token;
      await during?.();
      return minted;
    };
    return get(runtime, host, "/", {
      headers: { "x-sf-authorization": "Bearer sfm_header_opaque_credential_0001" },
    }).finally(() => {
      tokenHandoff = null;
    });
  };
  const mint = (sessionVersion: number) => mintCallbackToken({ host, spaceId, sessionVersion });
  expect((await presentOpaque(mint(0))).status).toBe(200);

  const stale = await presentOpaque(mint(0), () => signOutEveryone(1));
  expect(stale.status).toBe(403);
  expect(stale.headers.get("set-cookie") ?? "").not.toContain("sfv2_");

  const held = await mint(0);
  expect((await present(held)).status).toBe(403);
  await signOutEveryone(0);
  expect((await present(held)).status).toBe(200);
  expect((await present(held)).status).toBe(403);
  await signOutEveryone(1);

  expect((await presentOpaque(mint(1))).status).toBe(200);

  // An older control plane names no version: admitted as before.
  const now = Math.floor(Date.now() / 1000);
  const unversioned = signEd25519Jwt(platformKey.privateKey, runtimeJwks().keys[0]?.kid ?? "", {
    sub: MEMBER,
    purpose: "handoff",
    v: 1,
    authorities: [MEMBER],
    iss: "spacefast-api",
    aud: spaceId,
    host,
    spaceId,
    generation: 1,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    emailVerified: false,
    iat: now,
    nbf: now,
    exp: now + 60,
    jti: randomUUID(),
  });
  expect((await present(unversioned)).status).toBe(200);
});

describe("a Share sign-in that crosses a sign-out of everyone", () => {
  const versionId = "ver_oauth_chain_signed_out";
  const shareConfig = (sessionVersion: number) => {
    const config = accessConfig(1, sessionVersion);
    const owner = config.authorization.grants[0];
    if (!owner) throw new Error("owner fixture missing");
    config.authorization.grants.push({
      ...owner,
      id: "grt_consent_view",
      audience: { kind: "external", issuer: "spacefast-share", subject: "consent.view" },
    });
    return {
      ...config,
      authorization: {
        ...config.authorization,
        accessPage: {
          ...config.authorization.accessPage,
          accountUrl: OAUTH_AUTHORIZE_URL,
          issuer: OAUTH_ISSUER,
        },
      },
    };
  };
  const project = (sessionVersion: number) =>
    putRoute(runtime, OAUTH_SPACE, "production", {
      version_id: versionId,
      config: shareConfig(sessionVersion),
    });
  let deployed = false;
  const reset = async (sessionVersion: number) => {
    if (!deployed) {
      await deploy(runtime, {
        spaceId: OAUTH_SPACE,
        versionId,
        files: { "index.html": "private OAuth home", "docs/index.html": "private OAuth docs" },
        activate: {
          route_name: "production",
          production_hostnames: [OAUTH_HOST, "oauth-alias.test"],
          version_hostnames: [],
          config: shareConfig(sessionVersion),
        },
      });
      deployed = true;
    } else await project(sessionVersion);
  };

  const browse = (issuerVersion: () => number) => {
    const jar = new Map<string, string>();
    const authorizations: URL[] = [];
    const exchangesBefore = exchangeCount;
    const keep = (response: Response) => {
      for (const header of response.headers.getSetCookie()) {
        const pair = header.split(";")[0] ?? "";
        const name = pair.slice(0, pair.indexOf("="));
        if (/max-age=0/i.test(header) || pair.endsWith("=")) jar.delete(name);
        else jar.set(name, pair);
      }
    };
    const request = async (path: string) => {
      const response = await get(runtime, OAUTH_HOST, path, {
        headers: { cookie: [...jar.values()].join("; "), "sec-fetch-dest": "document" },
      });
      keep(response);
      return response;
    };
    const follow = async (start: string) => {
      let response = await request(start);
      for (let hop = 0; hop < 20 && response.status >= 300 && response.status < 400; hop++) {
        const location = new URL(response.headers.get("location") ?? "", `https://${OAUTH_HOST}`);
        if (location.origin + location.pathname === OAUTH_AUTHORIZE_URL) {
          authorizations.push(location);
          const code = `one-use-code-crossing-${randomUUID()}`;
          expectedChallenge = location.searchParams.get("code_challenge") ?? "";
          nonceForCode.set(code, location.searchParams.get("nonce") ?? "");
          sessionVersionForCode.set(code, issuerVersion());
          response = await request(
            `/__spacefast/callback?code=${code}&state=${location.searchParams.get("state")}&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
          );
        } else response = await request(location.pathname + location.search);
      }
      return response;
    };
    return {
      follow,
      authorizations,
      exchanges: () => exchangeCount - exchangesBefore,
      signedIn: () =>
        /^__Host-spacefast_session=sfv2_/.test(jar.get("__Host-spacefast_session") ?? ""),
    };
  };

  test("a silent probe that gets a stale handoff stops at the gate", async () => {
    await reset(1);
    const browser = browse(() => 0);
    const page = await browser.follow("/docs/");
    expect(page.status).toBe(403);
    expect(await page.text()).toContain(">Continue with Spacefast</a>");
    expect(browser.authorizations.map((url) => url.searchParams.get("prompt"))).toEqual(["none"]);
    expect(browser.exchanges()).toBe(1);
    expect(browser.signedIn()).toBe(false);
    expect((await browser.follow("/docs/")).status).toBe(403);
    expect(browser.authorizations).toHaveLength(1);
  });

  test("an explicit sign-in that gets a stale handoff stops at the gate with its retry", async () => {
    await reset(1);
    const browser = browse(() => 0);
    const page = await browser.follow("/__spacefast/sign-in?return=%2Fdocs%2F");
    expect(page.status).toBe(403);
    const html = await page.text();
    expect(html).toContain("Couldn&#039;t reach Spacefast to check that. Try again in a moment.");
    expect(html).toContain('href="/__spacefast/sign-in?return=%2Fdocs%2F"');
    expect(browser.authorizations.map((url) => url.searchParams.get("prompt"))).toEqual([null]);
    expect(browser.exchanges()).toBe(1);
    expect(browser.signedIn()).toBe(false);
  });

  test("a stale handoff for another authorization request gets the invalid-exchange answer", async () => {
    await reset(1);
    const start = await get(runtime, OAUTH_HOST, "/__spacefast/sign-in?return=%2Fdocs%2F");
    const authorize = new URL(start.headers.get("location") ?? "");
    expectedChallenge = authorize.searchParams.get("code_challenge") ?? "";
    nonceForCode.set("one-use-code-other-request-stale", "e".repeat(64));
    sessionVersionForCode.set("one-use-code-other-request-stale", 0);
    const callback = await get(
      runtime,
      OAUTH_HOST,
      `/__spacefast/callback?code=one-use-code-other-request-stale&state=${authorize.searchParams.get("state")}&iss=${encodeURIComponent(OAUTH_ISSUER)}`,
      { headers: { cookie: (start.headers.get("set-cookie") ?? "").split(";")[0] ?? "" } },
    );
    expect(callback.status).toBe(303);
    expect(callback.headers.get("location")).toBe("/docs/?sf_access=exchange-unavailable");
    expect(
      callback.headers
        .getSetCookie()
        .some((value) => value.startsWith("__Host-spacefast_session=")),
    ).toBe(false);
  });

  test("a sign-out that reaches the box while the callback waits is honoured", async () => {
    await reset(1);
    const stale = browse(() => 1);
    beforeHandoff = () => project(2);
    try {
      const page = await stale.follow("/__spacefast/sign-in?return=%2Fdocs%2F");
      expect(page.status).toBe(403);
      expect(stale.signedIn()).toBe(false);
    } finally {
      beforeHandoff = null;
    }
    await reset(2);
    const current = browse(() => 2);
    beforeHandoff = async (code) => {
      await project(3);
      sessionVersionForCode.set(code, 3);
    };
    try {
      const page = await current.follow("/__spacefast/sign-in?return=%2Fdocs%2F");
      expect(page.status).toBe(200);
      expect(await page.text()).toBe("private OAuth docs");
      expect(current.authorizations).toHaveLength(1);
      expect(current.exchanges()).toBe(1);
    } finally {
      beforeHandoff = null;
    }
  });
});

test("a handoff minted outside the 60-second freshness window is refused", async () => {
  setSystemTime(new Date(Date.now() - 120_000));
  let stale: string;
  try {
    stale = await mintCallbackToken({ ttlSeconds: 600 });
  } finally {
    setSystemTime();
  }
  const response = await redeemHandoff(runtime, HOST, stale);
  expect(response.status).toBe(404);
});

test("a never-live Space admits its owner to a version host once its Space config lands", async () => {
  const redeem = async () =>
    redeemHandoff(
      runtime,
      PREVIEW_HOST,
      await mintCallbackToken({ host: PREVIEW_HOST, spaceId: PREVIEW_SPACE, generation: 2 }),
      "/docs/",
    );
  expect((await redeem()).status).toBe(404);

  const versionHostConfig = (accessGeneration: number) => {
    const config = accessConfig(accessGeneration, 0);
    const authorization = z
      .object({ grants: z.array(z.object({}).passthrough()) })
      .passthrough()
      .parse(config.authorization);
    config.authorization = {
      ...authorization,
      grants: authorization.grants.map((grant) => ({
        ...grant,
        target: { kind: "all_versions" },
      })),
    };
    return config;
  };
  const push = (accessGeneration: number) =>
    api(
      runtime,
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${PREVIEW_SPACE}/config`,
      "update_space_config",
      { space_id: PREVIEW_SPACE },
      { config: versionHostConfig(accessGeneration) },
    );
  expect((await push(2)).status).toBe(200);
  expect(spaceOverlay(runtime, PREVIEW_SPACE)).toMatchObject({ access_gen: 2 });
  const empty = await api(
    runtime,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${PREVIEW_SPACE}/config`,
    "update_space_config",
    { space_id: PREVIEW_SPACE },
    {},
  );
  expect(empty.status).toBe(422);
  expect(spaceOverlay(runtime, PREVIEW_SPACE)).toMatchObject({ access_gen: 2 });
  const retiredPolicy = path.join(spaceRoot(runtime, PREVIEW_SPACE), "policy.json");
  writeFileSync(retiredPolicy, "{}");
  const retry = await push(2);
  expect(await retry.json()).toMatchObject({ unchanged: true });
  expect(existsSync(retiredPolicy)).toBe(false);
  const stale = await push(1);
  expect(stale.status).toBe(200);
  expect(await stale.json()).toMatchObject({ unchanged: true });
  expect(spaceOverlay(runtime, PREVIEW_SPACE)).toMatchObject({ access_gen: 2 });

  const admitted = await redeem();
  expect(admitted.status).toBe(303);
  const cookie = (admitted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  const served = await get(runtime, PREVIEW_HOST, "/docs/", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe(PROTECTED);
  expect(await served.text()).toBe("<h1>private docs</h1>\n");
});

test("the hybrid unlock card: returning accounts skip it, a first visit checks silently once", async () => {
  const HYBRID_HOST = "hybrid-unlock.test";
  const HYBRID_SPACE = "spc_hybrid_unlock";
  const config = accessConfig(1, 0);
  const owner = config.authorization.grants[0];
  if (!owner) throw new Error("owner fixture missing");
  const grants = [
    owner,
    {
      ...owner,
      id: "grt_hybrid_password",
      audience: { kind: "password", credentialId: "pwd_hybrid" },
    },
  ];
  await deploy(runtime, {
    spaceId: HYBRID_SPACE,
    versionId: "ver_hybrid_unlock",
    files: { "index.html": "<h1>hybrid</h1>\n" },
    activate: {
      route_name: "production",
      production_hostnames: [HYBRID_HOST],
      version_hostnames: [],
      config: {
        ...config,
        authorization: {
          ...config.authorization,
          grants,
          accessPage: {
            ...config.authorization.accessPage,
            displayName: "Hybrid Notes",
            accountUrl: OAUTH_AUTHORIZE_URL,
            issuer: OAUTH_ISSUER,
          },
        },
      },
    },
  });
  const navigate = { "sec-fetch-dest": "document" };
  const first = await get(runtime, HYBRID_HOST, "/__spacefast/unlock?return=%2F", {
    headers: navigate,
  });
  expect(first.status).toBe(302);
  const silent = new URL(first.headers.get("location") ?? "");
  expect(silent.origin + silent.pathname).toBe(OAUTH_AUTHORIZE_URL);
  expect(silent.searchParams.get("prompt")).toBe("none");
  expect(silent.searchParams.get("return")).toBe("/__spacefast/unlock?return=%2F&silent=tried");
  const cookieless = await get(
    runtime,
    HYBRID_HOST,
    "/__spacefast/unlock?return=%2F&silent=tried",
    {
      headers: navigate,
    },
  );
  expect(cookieless.status).toBe(401);
  expect(await cookieless.text()).toContain('name="password"');
  const tried = first.headers
    .getSetCookie()
    .map((header) => header.split(";")[0] ?? "")
    .join("; ");
  expect(tried).toContain(`__Host-spacefast_unlock_tried_${HYBRID_SPACE}=1`);
  const card = await get(runtime, HYBRID_HOST, "/__spacefast/unlock?return=%2F", {
    headers: { ...navigate, cookie: tried },
  });
  expect(card.status).toBe(401);
  const html = await card.text();
  const spacefast = html.indexOf(">Continue with Spacefast</a>");
  const password = html.indexOf('name="password"');
  expect(spacefast).toBeGreaterThan(-1);
  expect(password).toBeGreaterThan(spacefast);
  expect(html).toContain("or enter password");
  expect(html).toContain("<h1>Hybrid Notes</h1>");
  const returning = await get(runtime, HYBRID_HOST, "/__spacefast/unlock?return=%2Fdocs%2F", {
    headers: { ...navigate, cookie: "__Host-spacefast_returning=1" },
  });
  expect(returning.status).toBe(302);
  expect(returning.headers.get("location")).toBe("/__spacefast/sign-in?return=%2Fdocs%2F");
  expect(returning.headers.get("referrer-policy")).toBe("no-referrer");
  const byPassword = await get(
    runtime,
    HYBRID_HOST,
    "/__spacefast/unlock?return=%2Fdocs%2F&method=password",
    { headers: { ...navigate, cookie: "__Host-spacefast_returning=1" } },
  );
  expect(byPassword.status).toBe(401);
  const byPasswordHtml = await byPassword.text();
  expect(byPasswordHtml).toContain('name="password"');
  expect(byPasswordHtml).toContain('name="return" value="/docs/"');
  const fetched = await get(runtime, HYBRID_HOST, "/__spacefast/unlock", {
    headers: { "sec-fetch-dest": "empty" },
  });
  expect(fetched.status).toBe(401);
});

test("a box out of touch for over an hour fails private pages closed and keeps public ones", async () => {
  const PUBLIC_STALE_HOST = "public-stale.test";
  const base = accessConfig(1, 0);
  const anyone = base.authorization.grants[0];
  if (!anyone) throw new Error("grant fixture missing");
  const publicConfig = {
    ...base,
    authorization: {
      ...base.authorization,
      grants: [{ ...anyone, id: "grt_public_stale", audience: { kind: "public" } }],
    },
  };
  await deploy(runtime, {
    spaceId: "spc_public_stale",
    versionId: "ver_public_stale",
    files: { "index.html": "<h1>open</h1>\n" },
    activate: {
      route_name: "production",
      production_hostnames: [PUBLIC_STALE_HOST],
      version_hostnames: [],
      config: publicConfig,
    },
  });
  const syncPath = path.join(runtime.storageRoot, "runtime/box-sync.json");
  const lease = { box_sync_lease: 10_800 };
  const beat = (scope: { box_sync_lease: number; iat?: number; exp?: number } = lease) =>
    api(runtime, "POST", `${RUNTIME_HTTP_API_BASE}/heartbeat`, "box_heartbeat", scope);
  const callback = await redeemHandoff(runtime, HOST, await mintCallbackToken(), "/");
  const admitted = { cookie: (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "" };
  const privateStatus = async () => (await get(runtime, HOST, "/", { headers: admitted })).status;
  const staleAt = Math.floor(Date.now() / 1000) - 3660;
  const writeWatermark = (value: Record<string, number>) =>
    writeFileSync(syncPath, `${JSON.stringify(value)}\n`);

  rmSync(syncPath, { force: true });
  await putRoute(runtime, "spc_public_stale", "production", {
    version_id: "ver_public_stale",
    config: publicConfig,
    production_hostnames: [PUBLIC_STALE_HOST],
    version_hostnames: [],
  });
  expect(existsSync(syncPath)).toBe(false);

  const heartbeat = await beat();
  expect(heartbeat.status).toBe(200);
  const recorded = z
    .object({
      ok: z.literal(true),
      synced_at: z.number(),
      enforce_until: z.number(),
      stale_after_seconds: z.literal(3600),
    })
    .parse(await heartbeat.json());
  expect(Math.abs(recorded.synced_at - Date.now() / 1000)).toBeLessThan(120);
  expect(recorded.enforce_until - recorded.synced_at).toBe(10_800);
  expect(await privateStatus()).toBe(200);

  writeWatermark({ synced_at: staleAt, enforce_until: staleAt + 10_800 });
  const stalePrivate = await get(runtime, HOST, "/", { headers: admitted });
  expect(stalePrivate.status).toBe(503);
  expect(stalePrivate.headers.get("retry-after")).toBe("60");
  expect(stalePrivate.headers.get("x-spacefast-unavailable")).toBe("box_sync_stale");
  expect(stalePrivate.headers.get("cache-control")).toBe("no-store");
  const stalePublic = await get(runtime, PUBLIC_STALE_HOST, "/");
  expect(stalePublic.status).toBe(200);
  expect(await stalePublic.text()).toContain("open");

  const replayed = await beat({ ...lease, iat: staleAt - 10, exp: staleAt + 290 });
  expect(replayed.status).toBe(401);
  expect(await privateStatus()).toBe(503);
  await putRoute(runtime, "spc_public_stale", "production", {
    version_id: "ver_public_stale",
    config: publicConfig,
    production_hostnames: [PUBLIC_STALE_HOST],
    version_hostnames: [],
  });
  expect(await privateStatus()).toBe(503);
  expect((await beat()).status).toBe(200);
  expect(await privateStatus()).toBe(200);

  writeWatermark({ synced_at: staleAt - 10_800, enforce_until: staleAt - 60 });
  expect(await privateStatus()).toBe(200);

  // A write from a control plane without the sweep (a rollback) disarms the
  // box at once, inside the lease.
  writeWatermark({ synced_at: staleAt, enforce_until: staleAt + 10_800 });
  expect(await privateStatus()).toBe(503);
  const unstamped = await api(
    runtime,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/spc_public_stale/routes/production`,
    "update_route",
    { space_id: "spc_public_stale", route_name: "production", box_sync: undefined },
    {
      version_id: "ver_public_stale",
      config: publicConfig,
      production_hostnames: [PUBLIC_STALE_HOST],
      version_hostnames: [],
    },
  );
  expect(unstamped.status).toBe(200);
  expect(existsSync(syncPath)).toBe(false);
  expect(await privateStatus()).toBe(200);
});

function privateSince(spaceId: string): number | null {
  const probe = Bun.spawnSync([
    "php",
    "-r",
    `$p = json_decode(file_get_contents($argv[1] . '/space.json'), true);
     $o = include $argv[1] . '/' . $p['overlay'];
     echo json_encode($o['private_since'] ?? null);`,
    spaceRoot(runtime, spaceId),
  ]);
  if (probe.exitCode !== 0) throw new Error(probe.stderr.toString());
  return z.number().nullable().parse(JSON.parse(probe.stdout.toString()));
}

test("going private clears a returning browser's public copies, once the gate answers", async () => {
  const host = "went-private.test";
  const spaceId = "spc_went_private";
  const publicConfig = {
    ...accessConfig(1, 0),
    public_exposure: {
      v: 1,
      public: true,
      authorizationDigest: "0".repeat(64),
      contentTypes: null,
      externalProxy: false,
      unmodeled: "",
    },
  };
  const owner = publicConfig.authorization.grants[0];
  if (!owner) throw new Error("grant fixture missing");
  publicConfig.authorization = {
    ...publicConfig.authorization,
    grants: [{ ...owner, id: "grt_went_public", audience: { kind: "public" } }],
  };
  await deploy(runtime, {
    spaceId,
    versionId: "ver_went_private",
    files: { "index.html": "<h1>was public</h1>\n" },
    activate: {
      route_name: "production",
      production_hostnames: [host],
      version_hostnames: [],
      config: publicConfig,
    },
  });
  expect((await get(runtime, host, "/")).status).toBe(200);
  const route = (config: ReturnType<typeof accessConfig>) =>
    putRoute(runtime, spaceId, "production", {
      version_id: "ver_went_private",
      config,
      production_hostnames: [host],
      version_hostnames: [],
    });
  await route(accessConfig(2, 0));
  const gate = await get(runtime, host, "/");
  expect(gate.status).toBe(403);
  expect(gate.headers.get("clear-site-data")).toBe('"cache", "storage"');
  await route(accessConfig(3, 0));
  expect((await get(runtime, host, "/")).headers.get("clear-site-data")).not.toBeNull();
  expect((await get(runtime, HOST, "/")).headers.get("clear-site-data")).toBeNull();

  const fencedHost = "went-fenced.test";
  const fencedSpace = "spc_went_fenced";
  const fencedExposure = { ...publicConfig.public_exposure, public: false };
  const fenced = {
    ...publicConfig,
    public_exposure: fencedExposure,
    authorization: { ...publicConfig.authorization, fence: "exposure" },
  };
  const privateConfig = {
    ...accessConfig(4, 0),
    authorization: { ...accessConfig(4, 0).authorization },
  };
  await deploy(runtime, {
    spaceId: fencedSpace,
    versionId: "ver_went_fenced",
    files: { "index.html": "<h1>public</h1>\n" },
    activate: {
      route_name: "production",
      production_hostnames: [fencedHost],
      version_hostnames: [],
      config: publicConfig,
    },
  });
  const fencedRoute = (config: typeof publicConfig | ReturnType<typeof accessConfig>) =>
    putRoute(runtime, fencedSpace, "production", {
      version_id: "ver_went_fenced",
      config,
      production_hostnames: [fencedHost],
      version_hostnames: [],
    });
  await fencedRoute(fenced);
  const held = await get(runtime, fencedHost, "/");
  expect(held.status).not.toBe(200);
  expect(held.headers.get("clear-site-data")).toBeNull();
  await fencedRoute(publicConfig);
  const lifted = await get(runtime, fencedHost, "/");
  expect(lifted.status).toBe(200);
  expect(lifted.headers.get("clear-site-data")).toBeNull();
  const partlyPrivate = (fence: string) => ({
    ...publicConfig,
    public_exposure: fence === "none" ? publicConfig.public_exposure : fencedExposure,
    authorization: {
      ...publicConfig.authorization,
      fence,
      grants: [
        ...publicConfig.authorization.grants.map((grant) => ({
          ...grant,
          resources: { include: ["/**"], exclude: ["/members/**"] },
        })),
        {
          ...owner,
          id: "grt_went_fenced_members",
          resources: { include: ["/members/**"], exclude: [] },
        },
      ],
    },
  });
  await fencedRoute(partlyPrivate("none"));
  await fencedRoute(partlyPrivate("exposure"));
  expect(privateSince(fencedSpace)).toBeNull();
  await fencedRoute(partlyPrivate("none"));
  const membersGate = await get(runtime, fencedHost, "/members/");
  expect(membersGate.status).toBe(403);
  expect(membersGate.headers.get("clear-site-data")).toBeNull();
  await fencedRoute(fenced);
  expect(privateSince(fencedSpace)).toBeNull();
  await fencedRoute(privateConfig);
  expect(privateSince(fencedSpace)).toEqual(expect.any(Number));
  const wentPrivate = await get(runtime, fencedHost, "/");
  expect(wentPrivate.status).toBe(403);
  expect(wentPrivate.headers.get("clear-site-data")).toBe('"cache", "storage"');
});

test("a service worker script from a reserved path is refused", async () => {
  for (const path of ["/__spacefast/sdk.js", "/__spacefast/unlock", "/__"]) {
    const worker = await get(runtime, HOST, path, { headers: { "service-worker": "script" } });
    expect(worker.status, path).toBe(403);
  }
  expect((await get(runtime, HOST, "/__")).status).toBe(200);
});

test("an unclaimed preview asks each browser to agree to the current Terms, and again when they change", async () => {
  const host = "terms-preview.test";
  const spaceId = "spc_terms_preview";
  const termsUrl = "https://api.spacefast.test/v1/spaces/spc_terms_preview/preview-terms";
  const config = (version: string) => {
    const base = accessConfig(1, 0);
    const owner = base.authorization.grants[0];
    if (!owner) throw new Error("grant fixture missing");
    return {
      ...base,
      authorization: {
        ...base.authorization,
        spaceClaimed: false,
        grants: [{ ...owner, id: "grt_terms_preview", audience: { kind: "public" } }],
        accessPage: { ...base.authorization.accessPage, previewTerms: { url: termsUrl, version } },
      },
    };
  };
  await deploy(runtime, {
    spaceId,
    versionId: "ver_terms_preview",
    files: { "index.html": "<h1>preview</h1>\n", "app.css": "h1{}\n" },
    activate: {
      route_name: "production",
      production_hostnames: [host],
      version_hostnames: [],
      config: config(accessCore.UNCLAIMED_TERMS_VERSION),
    },
  });
  const navigate = { "sec-fetch-dest": "document" };
  const first = await get(runtime, host, "/docs?tab=2", { headers: navigate });
  expect(first.status).toBe(302);
  expect(first.headers.get("location")).toBe(`${termsUrl}?return=%2Fdocs%3Ftab%3D2&host=${host}`);
  const long = await get(runtime, host, `/docs?q=${"x".repeat(2100)}`, { headers: navigate });
  expect(long.status).toBe(302);
  expect(long.headers.get("location")).toBe(`${termsUrl}?return=%2F&host=${host}`);
  expect((await get(runtime, host, "/app.css")).status).toBe(200);

  const { UNCLAIMED_TERMS_VERSION } = accessCore;
  const back = await get(
    runtime,
    host,
    `/?sf_terms=${await unclaimedTerms.mintTermsAcceptanceToken({ spaceId, host, acceptanceId: `uta_${randomUUID()}` })}`,
    { headers: navigate },
  );
  expect(back.status).toBe(303);
  expect(back.headers.get("location")).toBe("/");
  const cookie =
    back.headers
      .getSetCookie()
      .find((line) => line.startsWith(`__Host-spacefast_terms_${spaceId}=`))
      ?.split(";")[0] ?? "";
  expect(cookie).not.toBe("");
  expect((await get(runtime, host, "/", { headers: { ...navigate, cookie } })).status).toBe(200);

  const { token: stale } = await signToken(
    { purpose: "terms-acceptance", issuer: "spacefast-api", audience: spaceId },
    { host, termsVersion: "2025-01-01", jti: `uta_${randomUUID()}` },
    { ttlSeconds: 120 },
  );
  expect(UNCLAIMED_TERMS_VERSION).not.toBe("2025-01-01");
  const refused = await get(runtime, host, `/?sf_terms=${stale}`, { headers: navigate });
  expect(refused.status).toBe(303);
  expect(refused.headers.getSetCookie().some((line) => line.includes("spacefast_terms_"))).toBe(
    false,
  );
  const elsewhere = await unclaimedTerms.mintTermsAcceptanceToken({
    spaceId: "spc_other_preview",
    host,
    acceptanceId: `uta_${randomUUID()}`,
  });
  const crossSpace = await get(runtime, host, `/?sf_terms=${elsewhere}`, { headers: navigate });
  expect(crossSpace.headers.getSetCookie().some((line) => line.includes("spacefast_terms_"))).toBe(
    false,
  );

  await putRoute(runtime, spaceId, "production", {
    version_id: "ver_terms_preview",
    config: config("2026-12-01"),
    production_hostnames: [host],
    version_hostnames: [],
  });
  const again = await get(runtime, host, "/", { headers: { ...navigate, cookie } });
  expect(again.status).toBe(302);
  expect(again.headers.get("location")).toBe(`${termsUrl}?return=%2F&host=${host}`);
});
