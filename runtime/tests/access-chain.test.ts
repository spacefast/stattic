// X-45 end-to-end redirect-chain journey (internal-docs/access-plan.html
// §3/§4.1). The coverage audit found the full chain was never exercised
// end-to-end: the control-plane half (mint -> 302 Location shape,
// apps/control-plane/src/access/routes.test.ts) and the runtime half
// (access-callback.php driven by ad-hoc TEST-FORGED tokens,
// access-rules.test.ts) were only ever proven apart — the
// mock-inherited-blindness seam. This file drives ONE journey through the
// REAL runtime over http: anonymous challenge -> mint with the REAL
// control-plane `mintGrantToken` (apps/control-plane/src/access/authorize.ts,
// imported directly, not reimplemented) -> the real callback verifies it ->
// the protected page serves -> single-use jti and the iat window are
// enforced. Step 2 is a genuine control-plane mint (the actual production
// signing code), not a shape-alike forgery — closing the seam the plan warns
// about for everything the runtime side can observe. The one leg this file
// cannot drive is the live `/v1/access/authorize` HTTP hop itself (session
// cookie, DB-backed policy lookup) — that requires the full control-plane app
// + Postgres and is covered separately (routes.test.ts mints via HTTP and
// asserts the 302 Location/claims; the full docker e2e lane wires both real
// processes together over local-atomic).
import { afterAll, beforeAll, expect, setSystemTime, test } from "bun:test";
import { generateKeyPairSync, randomUUID } from "node:crypto";

import { deploy, get, putRoute, type Runtime, startRuntime } from "./harness.ts";

let rt: Runtime;

const HOST = "chain.test";
const SPACE = "spc_chain";
const VERSION = "ver_chain_1";

// The ONE platform visitor-token signing key
// (apps/control-plane/src/access/authorize.ts's `mintGrantToken`, kid
// `spacefast-runtime-v1`) is an env-configured Ed25519 key
// (SPACEFAST_RUNTIME_JWT_PRIVATE_KEY). Generate a real key pair and point the
// control-plane signer at it — the public half is what the rule's `issuers`
// entry trusts, exactly like a real space's effective policy carries it.
const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");

// Imported AFTER the signing key env var is set (mintGrantToken memoizes its
// signing key on first call) — this is the real control-plane minting
// function, not a reimplementation.
const { mintGrantToken } = await import("../../apps/control-plane/src/access/authorize.ts");

const platformPublicJwk = platformKey.publicKey.export({ format: "jwk" }) as JsonWebKey;
const ISSUER = {
  kid: "spacefast-runtime-v1",
  alg: "EdDSA",
  publicKey: platformPublicJwk.x ?? "",
  grantNamespaces: [
    "user:",
    "email:",
    "team:",
    "link:",
    "invite:",
    "space:",
    "svc:",
    "sso:",
    "ext:",
  ],
};

async function setPolicy(rules: unknown[], sessionVersion = 0): Promise<void> {
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: { policy: { rules, sessionVersion } },
  });
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": "<h1>open</h1>\n",
      "protected/index.html": "<h1>protected</h1>\n",
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("X-45: full authorize-chain journey — anonymous challenge, real control-plane mint, real callback, served, single-use jti, iat window", async () => {
  await setPolicy([
    {
      id: "chain_gate",
      match: { pathPattern: "/protected/**" },
      effect: "challenge",
      auth: {
        requiredGrants: ["user:usr_chain_visitor"],
        issuers: [ISSUER],
        acquire: [
          {
            type: "login",
            url: "https://api.spacefast.com/v1/access/authorize",
            label: "Continue with Spacefast",
          },
        ],
      },
    },
  ]);

  // 1. Anonymous visitor GETs the protected path: CHALLENGED, not denied —
  // the page links through the stable same-origin login endpoint. That runtime
  // endpoint, not the compiler, owns the actual serving host and return path.
  const anonymous = await get(rt, HOST, "/protected/");
  expect(anonymous.status).toBe(401);
  expect(anonymous.headers.get("content-type")).toContain("text/html");
  const challengeBody = await anonymous.text();
  expect(challengeBody).toContain("/__spacefast/access/login?method=0&amp;return=%2Fprotected%2F");
  expect(challengeBody).not.toContain("api.spacefast.com/v1/access/authorize");
  const handoff = await get(rt, HOST, "/__spacefast/access/login?return=%2Fprotected%2F");
  expect(handoff.status).toBe(302);
  expect(handoff.headers.get("location")).toBe(
    `https://api.spacefast.com/v1/access/authorize?host=${HOST}&return=%2Fprotected%2F`,
  );

  // 2. Mint through the REAL control-plane `mintGrantToken` — the exact
  // production function `apps/control-plane/src/access/authorize-routes.ts`
  // calls for a satisfied member, signing with the same key material a real
  // deployment configures.
  const jti = `jti_${randomUUID()}`;
  const { token } = await mintGrantToken({
    sub: "user:usr_chain_visitor",
    grants: ["user:usr_chain_visitor"],
    audience: HOST,
    sv: 0,
    jti,
  });

  // 3. The REAL runtime callback verifies a genuinely control-plane-minted
  // token: sets the cookie, 303s to the sanitized clean return, no-store.
  // This is the seam — the mint above ran the real control-plane signer, and
  // everything from here (served, replay, iat window) rides through
  // access-callback.php for real.
  const callback = `/__spacefast/access-callback.php?token=${encodeURIComponent(token)}&return=/protected/%3Fnext%3D1`;
  const first = await get(rt, HOST, callback);
  expect(first.status).toBe(303);
  expect(first.headers.get("location")).toBe("/protected/?next=1");
  expect(first.headers.get("cache-control")).toBe("no-store");
  const setCookie = first.headers.get("set-cookie") ?? "";
  expect(setCookie).toContain("spacefast_access=");
  expect(setCookie).toContain("HttpOnly");
  const cookie = setCookie.split(";")[0] ?? "";

  // 4. GET the protected path WITH the returned cookie: the visitor is now in.
  const served = await get(rt, HOST, "/protected/?next=1", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe("private, no-store");
  expect(await served.text()).toBe("<h1>protected</h1>\n");

  // 5. Single-use jti: replaying the SAME callback token is rejected.
  const replay = await get(rt, HOST, callback);
  expect(replay.status).toBe(403);
  // The first cookie still works — replay rejection doesn't revoke the
  // already-issued session.
  expect((await get(rt, HOST, "/protected/", { headers: { cookie } })).status).toBe(200);

  // 6. iat window: a token whose `iat` is older than 5 minutes (but still
  // carries a jti, so the window check applies) is rejected even though it
  // is otherwise well-formed and unexpired. Fake the clock back 301s so the
  // REAL mintGrantToken stamps a stale `iat` (mintGrantToken always uses
  // Date.now(), it takes no iat override) — still a genuine control-plane
  // mint, just minted "in the past".
  setSystemTime(new Date(Date.now() - 301_000));
  let stale: string;
  try {
    stale = (
      await mintGrantToken({
        sub: "user:usr_chain_visitor",
        grants: ["user:usr_chain_visitor"],
        audience: HOST,
        sv: 0,
        jti: `jti_${randomUUID()}`,
      })
    ).token;
  } finally {
    setSystemTime();
  }
  const staleCallback = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(stale)}&return=/protected/`,
  );
  expect(staleCallback.status).toBe(403);
});
