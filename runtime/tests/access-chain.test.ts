import { afterAll, beforeAll, expect, setSystemTime, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, writeFileSync } from "node:fs";
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
// The nonce the provider bound to each code, as Better Auth stores it beside the
// code. A code with no entry gets a handoff that names no nonce.
const nonceForCode = new Map<string, string>();
// The authorize endpoint and the issuer live on different origins here, as
// behind the dev relay or a split auth host.
const OAUTH_AUTHORIZE_URL = "https://api.spacefast.test/v1/auth/oauth2/authorize";
const OAUTH_ISSUER = "https://auth.spacefast.test/v1/auth";
const OAUTH_HOST = "oauth-chain.test";
// An invite email's one-use token, as the Space's invite link carries it.
const INVITE_TOKEN = "Bhf_1nv1t3_0ne_use_t0ken_x";
const OAUTH_SPACE = "spc_oauth_chain";
const OAUTH_VERSION = "ver_oauth_chain";

const HOST = "chain.test";
const SPACE = "spc_chain";
const VERSION = "ver_chain_1";
// A second Space so the gen-tuple test can move the overlay without disturbing
// the chain Space's live session.
const GEN_HOST = "chain-gen.test";
const GEN_SPACE = "spc_chain_gen";
const GEN_VERSION = "ver_chain_gen_1";
const SHARE_REFERENCE =
  `external:${createHash("sha256").update("spacefast-share\0consent.view").digest("hex")}` as const;
// Finalized but never promoted: only its immutable version host serves.
const PREVIEW_HOST = "chain-preview--v1.test";
const PREVIEW_SPACE = "spc_chain_preview";
const PREVIEW_VERSION = "ver_chain_preview_1";
const MEMBER = "member:mem_chain_owner";
// Contracts §5/§6 (D121/D123): a protected Space collapses every compiled cache
// class onto one policy. No cache may retain private bytes.
const PROTECTED = "private, no-store";
// Contracts §4: every `sfv2_` session cookie is signed with a key derived from
// this, so a projection without one can mint no session.
const EXCHANGE_CREDENTIAL = "runtime-chain-exchange-credential-0123456789";

// Set the real control-plane signing material before importing the mint or
// JWKS producer, so both come from production functions, not test fixtures.
const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-access-chain-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-access-chain-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-access-chain-test-token";

const [{ mintAuthorityToken }, { runtimeJwks }] = await Promise.all([
  import("../../apps/control-plane/src/access/authorize.ts"),
  import("../../apps/control-plane/src/runtime/auth.ts"),
]);

/**
 * The raw route config management compiles into the overlay's grant index. The
 * gen tuple's halves are set independently: the overlay stores
 * `[access_gen, session_ver]` and must never sum them (contracts §4/D30).
 */
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
          // The Grant's own generation, untouched by either half of the tuple.
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
      // The standard token request: form-encoded, at the issuer's token
      // endpoint, with nothing in it but OAuth's own parameters.
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
      // Sent to the configured issuer's token endpoint, not to the authorize
      // URL's origin: a split auth host keeps them apart.
      expect(request.headers.get("host")).toBe(new URL(OAUTH_ISSUER).host);
      expect(new URL(request.url).pathname).toBe("/v1/auth/oauth2/token");
      expect(request.headers.get("spacefast-runtime-exchange")).toBe(EXCHANGE_CREDENTIAL);
      expect(fields.client_id).toBe(`spacefast-share:${OAUTH_SPACE}`);
      expect(fields.redirect_uri).toBe(`https://${OAUTH_HOST}/__spacefast/callback`);
      expect(fields.code).toMatch(/^one-use-code/);
      expect(createHash("sha256").update(fields.code_verifier).digest("base64url")).toBe(
        expectedChallenge,
      );
      exchangeCount++;
      // The API suite owns native single-use codes; this boundary returns its refusal.
      if (usedCodes.has(fields.code))
        return Response.json(
          { error: "invalid_grant", error_description: "The sign-in server refused this code." },
          { status: 400 },
        );
      usedCodes.add(fields.code);
      // Share sign-in always signs in an account, so this handoff names one.
      const now = Math.floor(Date.now() / 1000);
      return Response.json({
        access_token: "opaque-access-token",
        token_type: "Bearer",
        expires_in: 300,
        scope: "openid profile email",
        // What this runtime's credential adds to the standard response.
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
            // The control plane copies the authorize request's nonce here; a
            // code the test never bound gets an empty one, which matches nothing.
            nonce: nonceForCode.get(fields.code) ?? "",
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
  ttlSeconds,
  authority = MEMBER,
}: {
  host?: string;
  spaceId?: string;
  generation?: number;
  ttlSeconds?: number;
  authority?: Parameters<typeof mintAuthorityToken>[0]["sub"];
} = {}) {
  return (
    await mintAuthorityToken({
      sub: authority,
      authorities: [authority],
      spaceId,
      sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
      generation,
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
  const capabilities = (cookie: string) => {
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
      echo json_encode(_stattic_grant_decision($projection, '/', ['kind' => 'live'], $refs, $refs)['capabilities']);
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
    return z.array(z.string()).parse(JSON.parse(php.stdout.toString()));
  };
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
  // The gate's own probe is a complete silent authorization request, and a
  // refusal nobody clicked for lands back on the page instead of on a wall.
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
  // The client names the Space; the request carries no parameter of its own for it.
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
  // Probed once: the next navigation renders the gate, whose button signs in
  // in this tab because the callback never closes a popup.
  const gate = await get(runtime, OAUTH_HOST, "/docs/?tab=9", {
    headers: { cookie: probeCookies, "sec-fetch-dest": "document" },
  });
  expect(gate.status).toBe(403);
  const gateHtml = await gate.text();
  expect(gateHtml).toContain(">Continue with Spacefast</a>");
  expect(gateHtml).not.toContain("data-sf-access-popup");
  // A refused account's "Use another account" is this Space's own sign-in
  // asking which account, so the door shows its chooser; it never takes the
  // older account lane.
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
  // The gate's button starts sign-in at the Space's own `/__spacefast/sign-in`;
  // bare `/__spacefast` is the door that offers it.
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
  // An invite email's raw one-use token reaches authorize untouched.
  expect(destination.searchParams.get("invite")).toBe(INVITE_TOKEN);
  // A plain start asks nothing; the bar's Switch account asks which account,
  // and nothing else a page sends becomes a prompt.
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
  // As the provider sends it: the response names the configured issuer, which
  // is not the authorize URL's origin.
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
    // A way back, not a dead end: it starts a fresh sign-in on this host.
    expect(restart).toContain('href="/__spacefast/sign-in?return=%2F">Sign In Again</a>');
  }
  const denied = await get(runtime, OAUTH_HOST, callback);
  expect(denied.status).toBe(403);
  expect(denied.headers.get("referrer-policy")).toBe("no-referrer");
  // Login CSRF: browser A's valid code and state, delivered to browser B (which
  // started sign-ins of its own), find no entry in B's cookie and sign B in as
  // nobody. The code is never even exchanged.
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
  // A handoff minted for another authorization request names another nonce and
  // opens nothing, even with this browser's state and a good code.
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
  // That attempt spent this browser's state, so start again for the real one.
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
  // An account session born here may renew silently after it expires, and
  // the overlay renews it at this host's account start, with the Space's own
  // sign-in.
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
  // The old cookie a browser kept still names the state; the spent code is refused.
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
          exchange: {
            ...config.authorization.accessPage.exchange,
            credential: `${EXCHANGE_CREDENTIAL}-rotated`,
          },
        },
      },
    },
  });
  // The box signs its sessions with a key of its own, so a new exchange
  // credential (a rotated SPACEFAST_SECRET_KEYS) signs nobody out
  // and leaves a sign-in in flight intact.
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

  // The account lane as a browser walks it: the control plane 303s the signed
  // handoff to the runtime's one way in, which trades it for a local session
  // and redirects to the requested page.
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

  // Still one use, whichever address spends it.
  expect((await redeemHandoff(runtime, HOST, token, "/docs/")).status).toBe(404);
  // A silent renewal that will not redeem returns to its page, signed out.
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
  // The two halves land in the overlay as separate ints (contracts §4).
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 0 });

  // A fresh mint per attempt: each carries its own jti, so a rejection never
  // borrows the replay guard's verdict.
  const redeem = async (generation: number): Promise<number> => {
    const token = await mintCallbackToken({ host: GEN_HOST, spaceId: GEN_SPACE, generation });
    const response = await redeemHandoff(runtime, GEN_HOST, token);
    return response.status;
  };

  // Minted for a generation the Space has not reached yet.
  expect(await redeem(2)).toBe(404);

  // access_gen 1 + session_ver 1: a summing runtime would see "2" here and
  // admit the generation-2 handoff. Rotating the session version signs everyone
  // out and must not advance the Grant generation.
  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(1, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 1 });
  expect(await redeem(2)).toBe(404);

  // access_gen 2 + session_ver 1: the sum is 3 and the handoff still admits,
  // because only the access_gen half is ever compared.
  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(2, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 2, session_ver: 1 });

  const admitted = await redeemHandoff(
    runtime,
    GEN_HOST,
    await mintCallbackToken({ host: GEN_HOST, spaceId: GEN_SPACE, generation: 2 }),
    "/docs/",
  );
  expect(admitted.status).toBe(303);
  const cookie = (admitted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  const served = await get(runtime, GEN_HOST, "/docs/", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(await served.text()).toBe("<h1>private docs</h1>\n");
});

test("a handoff minted outside the 60-second freshness window is refused", async () => {
  // Long-lived on purpose: `exp` is still in the future, so only the runtime's
  // 60s cap on the mint's `iat` can reject this token.
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
  // No production pointer means no issuer or keys: every handoff is refused.
  expect((await redeem()).status).toBe(404);

  // The owner Grant re-aimed at every version host: the shape a team Space's
  // `all_versions` Grant takes. A `live` Grant never reaches a version host.
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
  // A body without a config never wipes the access a version host admits with.
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
  // A write that died after space-config.json landed left a retired policy
  // document behind; the identical retry still finishes the cleanup.
  const retiredPolicy = path.join(spaceRoot(runtime, PREVIEW_SPACE), "policy.json");
  writeFileSync(retiredPolicy, "{}");
  const retry = await push(2);
  expect(await retry.json()).toMatchObject({ unchanged: true });
  expect(existsSync(retiredPolicy)).toBe(false);
  // A writer holding an older read (a finalize that started before an access
  // change) leaves the newer config in place.
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
