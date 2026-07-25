// Unified access enforcement (packages/common/src/contracts/access.ts,
// internal-docs/access-plan.html §3). THE one `policy.rules` lane the runtime
// enforcer (access-rules.php) evaluates first-match-wins with exactly ONE
// satisfaction test (grants ∩ requiredGrants). Behavioral only — every check
// runs the runtime and asserts the observable outcome.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHmac, generateKeyPairSync, randomUUID } from "node:crypto";
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { ISO_COUNTRY_CODES } from "../../packages/common/src/contracts/countries.ts";
import {
  api,
  base64url as b64url,
  deploy,
  errorCode,
  get,
  managementToken,
  putRoute,
  runtimeHttpPath,
  type Runtime,
  sha256,
  signEd25519Jwt,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

let rt: Runtime;

const HOST = "acc.test";
const DOCS_HOST = "docs.acc.test";
const OTHER_HOST = "www.other.test";
const VERSION_HOST = "acc--v1.test";
const SPACE = "spc_acc";
const VERSION = "ver_acc_1";
const PASSWORD = "swordfish";
const passwordHash = Bun.password.hashSync(PASSWORD, { algorithm: "bcrypt", cost: 4 });

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
  "ext:",
]);
const EXTERNAL_ISSUER = {
  ...ISSUER,
  grantNamespaces: ["ext:acn_host:"],
};

type TokenClaims = {
  sub?: string;
  grants?: string[];
  aud?: string | null;
  sv?: number;
  jti?: string;
  iat?: number;
  exp?: number;
  nbf?: number;
};

// A platform-issued (Ed25519) visitor token.
function visitorToken(claims: TokenClaims = {}, options?: { omitExp?: boolean }): string {
  const now = Math.floor(Date.now() / 1000);
  const payload: Record<string, unknown> = {
    sub: "user:alice",
    grants: ["user:alice"],
    aud: HOST,
    iat: now,
    exp: now + 3600,
    ...claims,
  };
  if (options?.omitExp) Reflect.deleteProperty(payload, "exp");
  return signEd25519Jwt(tokenKeyPair.privateKey, ISSUER.kid, payload);
}

async function accessSessionCredential(
  claims: TokenClaims = {},
  returnPath = "/private/",
): Promise<{ cookie: string; token: string; sourceToken: string }> {
  const sourceToken = visitorToken({ jti: `jti_${randomUUID()}`, ...claims });
  const accepted = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(sourceToken)}&return=${encodeURIComponent(returnPath)}`,
  );
  expect(accepted.status).toBe(303);
  const cookie = (accepted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=sfv1_");
  return { cookie, token: cookie.replace(/^spacefast_access=/, ""), sourceToken };
}

// A token forged with the space-local HS256 key (derived from a stored secret +
// sessionVersion). Used to prove the namespace filter drops non-`pw:` grants.
function localPwToken(
  ruleId: string,
  secret: string,
  sessionVersion: number,
  grants: string[],
): string {
  const now = Math.floor(Date.now() / 1000);
  const key = createHmac("sha256", secret)
    .update(`spacefast-pw-key:v1:${ruleId}:${sessionVersion}`)
    .digest();
  const header = b64url(JSON.stringify({ alg: "HS256", typ: "JWT", kid: "spacefast-local-pw-v1" }));
  const payload = b64url(
    JSON.stringify({
      sub: "pw:anon",
      grants,
      aud: HOST,
      sv: sessionVersion,
      iat: now,
      exp: now + 3600,
    }),
  );
  const signingInput = `${header}.${payload}`;
  const signature = createHmac("sha256", key).update(signingInput).digest("base64url");
  return `${signingInput}.${signature}`;
}

async function setPolicy(
  rules: unknown[] | null,
  secrets: Record<string, string> | null = null,
  sessionVersion: number | null = null,
  issuers: unknown[] | null = null,
): Promise<void> {
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: {
      policy:
        rules === null
          ? null
          : {
              rules,
              ...(sessionVersion === null ? {} : { sessionVersion }),
              ...(issuers === null ? {} : { issuers }),
            },
      secrets,
    },
  });
}

function revocationsPath(): string {
  return path.join(rt.storageRoot, "spaces", SPACE, "revocations.json");
}

function writeRevocations(revocations: {
  grants?: Record<string, number>;
  subs?: Record<string, number>;
}): void {
  const spaceRoot = path.join(rt.storageRoot, "spaces", SPACE);
  mkdirSync(spaceRoot, { recursive: true });
  writeFileSync(
    revocationsPath(),
    `${JSON.stringify(
      {
        grants: revocations.grants ?? {},
        subs: revocations.subs ?? {},
        updatedAt: Math.floor(Date.now() / 1000),
      },
      null,
      2,
    )}\n`,
  );
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": "<h1>open</h1>\n",
      "assets/app.12345678.js": "console.log('immutable');\n",
      "private/index.html": "<h1>private</h1>\n",
      "members/index.html": "<h1>members</h1>\n",
      "viewer/index.html": "<h1>viewer</h1>\n",
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST, DOCS_HOST, OTHER_HOST],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: VERSION }],
    },
  });
});

afterAll(() => rt?.stop());

test("password form mints a local pw: token into spacefast_access; /access/me answers for it", async () => {
  await setPolicy(
    [
      {
        id: "pw_private",
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:pw_private"],
          acquire: [{ type: "password", ref: "secret:site_pw", transport: "form" }],
        },
      },
    ],
    { site_pw: passwordHash },
    0,
  );

  expect((await get(rt, HOST, "/")).status).toBe(200);

  const walled = await get(rt, HOST, "/private/");
  expect(walled.status).toBe(401);
  expect(walled.headers.get("content-type")).toContain("text/html");
  expect(walled.headers.get("x-robots-tag")).toContain("noindex");
  const walledBody = await walled.text();
  expect(walledBody).toContain('name="_pw"');
  expect(walledBody).not.toContain("did not work");

  const wrong = await get(rt, HOST, "/private/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: "nope" }).toString(),
  });
  expect(wrong.status).toBe(401);
  expect(await wrong.text()).toContain("did not work");

  const granted = await get(rt, HOST, "/private/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: PASSWORD }).toString(),
  });
  expect(granted.status).toBe(303);
  expect(granted.headers.get("location")).toBe("/private/");
  const setCookie = granted.headers.get("set-cookie") ?? "";
  expect(setCookie).toContain("spacefast_access=");
  expect(setCookie).toContain("HttpOnly");
  const cookie = setCookie.split(";")[0] ?? "";

  const authed = await get(rt, HOST, "/private/", { headers: { cookie } });
  expect(authed.status).toBe(200);
  expect(authed.headers.get("cache-control")).toBe("private, no-store");
  expect(await authed.text()).toBe("<h1>private</h1>\n");

  const me = await get(rt, HOST, "/__spacefast/access/me", { headers: { cookie } });
  expect(me.status).toBe(200);
  expect(me.headers.get("cache-control")).toBe("no-store");
  const meBody = (await me.json()) as { sub: string; grants: string[] };
  expect(meBody.sub).toBe("pw:anon");
  expect(meBody.grants).toEqual(["pw:pw_private"]);
});

test("a whole-space wall (match:{}) covers the literal /index.html directory-index document", async () => {
  // Regression guard for the handbook exposure (ops-handbook-retained-version-closure):
  // a space password walls `/` but a request for the literal index file `/index.html`
  // (or a nested `<dir>/index.html`) must NOT slip past the same challenge. The wall
  // is enforced on the requested path in serve.php, so the directory-index document
  // named explicitly is as protected as its slash form — anonymous readers get 401
  // with the challenge, never the index bytes.
  await setPolicy(
    [
      {
        id: "space-password",
        match: {},
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:space-password"],
          acquire: [{ type: "password", ref: "secret:site_pw", transport: "form" }],
        },
      },
    ],
    { site_pw: passwordHash },
    0,
  );

  await Promise.all(
    ["/index.html", "/private/index.html", "/members/index.html"].map(async (literal) => {
      const res = await get(rt, HOST, literal);
      const body = await res.text();
      expect({ literal, status: res.status, form: body.includes('name="_pw"') }).toEqual({
        literal,
        status: 401,
        form: true,
      });
      // The challenge must not disclose the walled document's bytes.
      expect(body).not.toContain("<h1>open</h1>");
      expect(body).not.toContain("<h1>private</h1>");
      expect(body).not.toContain("<h1>members</h1>");
    }),
  );

  // A valid password serves the literal index document (200) — the wall gates it,
  // it does not break serving it to an authorized reader.
  const granted = await get(rt, HOST, "/index.html", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: PASSWORD }).toString(),
  });
  const cookie = (granted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=");
  const authed = await get(rt, HOST, "/index.html", { headers: { cookie } });
  expect(authed.status).toBe(200);
  expect(await authed.text()).toBe("<h1>open</h1>\n");
});

test("a pw: wall pass is re-challenged at a different unsatisfied password wall", async () => {
  await setPolicy(
    [
      {
        id: "pw_a",
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:pw_a"],
          acquire: [{ type: "password", ref: "secret:pw_a", transport: "form" }],
        },
      },
      {
        id: "pw_b",
        match: { pathPattern: "/members/**" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:pw_b"],
          acquire: [{ type: "password", ref: "secret:pw_b", transport: "form" }],
        },
      },
    ],
    { pw_a: passwordHash, pw_b: passwordHash },
    0,
  );

  const grantedA = await get(rt, HOST, "/private/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: PASSWORD }).toString(),
  });
  expect(grantedA.status).toBe(303);
  const cookie = (grantedA.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=");

  const wallB = await get(rt, HOST, "/members/", { headers: { cookie } });
  expect(wallB.status).toBe(401);
  expect(wallB.headers.get("content-type")).toContain("text/html");
  const body = await wallB.text();
  expect(body).toContain('name="_pw"');
  expect(body).not.toContain("Signed in as pw:anon");
});

test("basicAuth acquire (transport basic) is satisfied statelessly per request", async () => {
  await setPolicy(
    [
      {
        id: "basic_all",
        match: { host: HOST },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:basic_all"],
          acquire: [
            { type: "password", ref: "secret:basic_pw", transport: "basic", username: "ops" },
          ],
        },
      },
    ],
    { basic_pw: PASSWORD },
    0,
  );

  const denied = await get(rt, HOST, "/");
  expect(denied.status).toBe(401);
  expect(denied.headers.get("www-authenticate") ?? "").toContain("Basic");

  const granted = await get(rt, HOST, "/", {
    headers: { authorization: `Basic ${Buffer.from(`ops:${PASSWORD}`).toString("base64")}` },
  });
  expect(granted.status).toBe(200);
  expect(granted.headers.get("cache-control")).toBe("private, no-store");

  // No cookie is set — Basic is replayed each request.
  expect(granted.headers.get("set-cookie")).toBeNull();
  expect((await get(rt, OTHER_HOST, "/")).status).toBe(200);
});

test("basicAuth hostPattern challenges matching hostnames", async () => {
  await setPolicy(
    [
      {
        id: "basic_wildcard",
        match: { hostPattern: "acc.test" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:basic_wildcard"],
          acquire: [
            { type: "password", ref: "secret:basic_pw", transport: "basic", username: "ops" },
          ],
        },
      },
    ],
    { basic_pw: PASSWORD },
    0,
  );

  const denied = await get(rt, HOST, "/");
  expect(denied.status).toBe(401);
  expect(denied.headers.get("www-authenticate") ?? "").toContain("Basic");

  const granted = await get(rt, HOST, "/", {
    headers: { authorization: `Basic ${Buffer.from(`ops:${PASSWORD}`).toString("base64")}` },
  });
  expect(granted.status).toBe(200);
});

test("basicAuth hostTemplate challenges placeholder hostnames", async () => {
  await setPolicy(
    [
      {
        id: "basic_template",
        match: { hostTemplate: ":branch.acc.test" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:basic_template"],
          acquire: [
            { type: "password", ref: "secret:basic_pw", transport: "basic", username: "ops" },
          ],
        },
      },
    ],
    { basic_pw: PASSWORD },
    0,
  );

  const denied = await get(rt, DOCS_HOST, "/");
  expect(denied.status).toBe(401);
  expect(denied.headers.get("www-authenticate") ?? "").toContain("Basic");

  const granted = await get(rt, DOCS_HOST, "/", {
    headers: { authorization: `Basic ${Buffer.from(`ops:${PASSWORD}`).toString("base64")}` },
  });
  expect(granted.status).toBe(200);
  expect((await get(rt, OTHER_HOST, "/")).status).toBe(200);
});

test("deny returns 403 with reason and message; unprotected paths keep the edge default", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "deny",
      reasonCode: "space-locked",
      message: "This area is locked.",
    },
  ]);

  const denied = await get(rt, HOST, "/private/");
  expect(denied.status).toBe(403);
  expect(denied.headers.get("x-spacefast-reason")).toBe("space-locked");
  expect(denied.headers.get("cache-control")).toContain("no-store");
  expect(await denied.text()).toContain("This area is locked.");

  const open = await get(rt, HOST, "/");
  expect(open.status).toBe(200);
  expect(open.headers.get("cache-control")).not.toContain("no-store");
});

test("token challenge: anonymous is challenged, a valid-but-unsatisfied visitor is denied (no loop)", async () => {
  await setPolicy([
    {
      id: "team_only",
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: {
        requiredGrants: ["user:alice"],
        issuers: [ISSUER],
        acquire: [
          {
            type: "login",
            url: "https://api.spacefast.com/v1/access/authorize?space=spc_runtime_test",
            label: "Continue with Spacefast",
          },
          {
            type: "login",
            url: "https://shop.example.test/authorize?space=spc_runtime_test&sv=7",
            label: "Continue with Shop",
          },
        ],
      },
    },
  ]);

  const anonymous = await get(rt, HOST, "/private/?next=1");
  expect(anonymous.status).toBe(401);
  expect(anonymous.headers.get("cache-control")).toContain("no-store");
  const body = await anonymous.text();
  expect(body).toContain("/__spacefast/access/login?method=0&amp;return=");
  expect(body).toContain("/__spacefast/access/login?method=1&amp;return=");
  expect(body).toContain("return=%2Fprivate%2F%3Fnext%3D1");
  expect(body).not.toContain("api.spacefast.com/v1/access/authorize");
  expect(body).not.toContain("shop.example.test/authorize");
  expect(body).not.toContain("iframe");

  const shopHandoff = await get(
    rt,
    HOST,
    "/__spacefast/access/login?method=1&return=%2Fprivate%2F%3Fnext%3D1",
  );
  expect(shopHandoff.status).toBe(302);
  expect(shopHandoff.headers.get("location")).toBe(
    `https://shop.example.test/authorize?space=spc_runtime_test&sv=7&host=${HOST}&return=%2Fprivate%2F%3Fnext%3D1`,
  );

  const directBroadToken = visitorToken();
  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${directBroadToken}` } }))
      .status,
  ).toBe(401);

  // Signed in via callback, but the wrong identity: deny, never re-challenge.
  const bob = await accessSessionCredential({ sub: "user:bob", grants: ["user:bob"] });
  const denied = await get(rt, HOST, "/private/", {
    headers: { cookie: bob.cookie },
  });
  expect(denied.status).toBe(403);
  expect(await denied.text()).toContain("Signed in as user:bob");

  // The right identity passes after callback exchange.
  const alice = await accessSessionCredential();
  const granted = await get(rt, HOST, "/private/", {
    headers: { cookie: alice.cookie },
  });
  expect(granted.status).toBe(200);
});

test("Bearer is checked before the cookie", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);
  const alice = await accessSessionCredential();
  const response = await get(rt, HOST, "/private/", {
    headers: {
      cookie: `spacefast_access=${visitorToken({ sub: "user:bob", grants: ["user:bob"] })}`,
      authorization: `Bearer ${alice.token}`,
    },
  });
  expect(response.status).toBe(200);
});

test("sv mismatch is rejected", async () => {
  await setPolicy(
    [
      {
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
      },
    ],
    null,
    3,
  );
  const stale = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${visitorToken({ sv: 0 })}` },
  });
  expect(stale.status).toBe(401);

  const currentSession = await accessSessionCredential({ sv: 3 });
  const current = await get(rt, HOST, "/private/", { headers: { cookie: currentSession.cookie } });
  expect(current.status).toBe(200);
});

test("a past expiresAt rule is skipped at match time", async () => {
  const past = Math.floor(Date.now() / 1000) - 60;
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "deny",
      reasonCode: "expired-block",
      expiresAt: past,
    },
    { match: { pathPattern: "/private/**" }, effect: "allow" },
  ]);
  const response = await get(rt, HOST, "/private/");
  expect(response.status).toBe(200);
});

test("the space-local HS256 key can only sign pw: grants — anything else is dropped", async () => {
  await setPolicy(
    [
      {
        id: "team_only",
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
      },
    ],
    { site_pw: passwordHash },
    0,
  );
  // A local-key token forging user:alice: the namespace filter (["pw:"]) drops
  // the grant, so it cannot satisfy a user: rule.
  const forged = localPwToken("team_only", passwordHash, 0, ["user:alice"]);
  const response = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${forged}` },
  });
  expect(response.status).toBe(401);
});

test("password rotation / sv bump kills outstanding wall passes", async () => {
  await setPolicy(
    [
      {
        id: "pw_wall",
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:pw_wall"],
          acquire: [{ type: "password", ref: "secret:wall_pw", transport: "form" }],
        },
      },
    ],
    { wall_pw: passwordHash },
    0,
  );
  const granted = await get(rt, HOST, "/private/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: PASSWORD }).toString(),
  });
  const cookie = (granted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(200);

  // Bump the session version: the derivation changes, the old pass is inert.
  await setPolicy(
    [
      {
        id: "pw_wall",
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: {
          requiredGrants: ["pw:pw_wall"],
          acquire: [{ type: "password", ref: "secret:wall_pw", transport: "form" }],
        },
      },
    ],
    { wall_pw: passwordHash },
    1,
  );
  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(401);
});

test("access callback: iat window + single-use jti (replay rejected); aud pinned", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);

  const jti = `jti_${randomUUID()}`;
  const token = visitorToken({ jti });
  const callback = `/__spacefast/access-callback.php?token=${encodeURIComponent(token)}&return=/private/%3Fnext%3D1`;

  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(401);

  const first = await get(rt, HOST, callback);
  expect(first.status).toBe(303);
  expect(first.headers.get("location")).toBe("/private/?next=1");
  const setCookie = first.headers.get("set-cookie") ?? "";
  expect(setCookie).toContain("spacefast_access=sfv1_");
  expect(setCookie).not.toContain(encodeURIComponent(token));
  const cookie = setCookie.split(";")[0] ?? "";

  // Replay of the same single-use jti is rejected.
  const replay = await get(rt, HOST, callback);
  expect(replay.status).toBe(403);

  expect(
    (await get(rt, HOST, "/private/", { headers: { cookie: `spacefast_access=${token}` } })).status,
  ).toBe(401);

  const authed = await get(rt, HOST, "/private/?next=1", { headers: { cookie } });
  expect(authed.status).toBe(200);

  // Audience mismatch is rejected.
  const wrongAud = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(visitorToken({ aud: "elsewhere.test" }))}&return=/private/`,
  );
  expect(wrongAud.status).toBe(403);
});

test("access callback sessions stop authorizing when the verifier issuer is removed", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);

  const token = visitorToken({ jti: `jti_${randomUUID()}` });
  const accepted = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(token)}&return=/private/`,
  );
  expect(accepted.status).toBe(303);
  const cookie = (accepted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=sfv1_");
  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(200);

  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [] },
    },
  ]);

  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(401);
  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(401);
});

test("access callback requires jti for external handoffs while invite credentials stay re-clickable", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/external/**" },
      effect: "challenge",
      auth: { requiredGrants: ["ext:acn_shop:wc-subscriber"], issuers: [ISSUER] },
    },
  ]);

  const externalNoJti = visitorToken({
    sub: "wp:42",
    grants: ["ext:acn_shop:wc-subscriber"],
  });
  const rejected = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(externalNoJti)}&return=/external/`,
  );
  expect(rejected.status).toBe(403);

  const externalWithJti = visitorToken({
    sub: "wp:42",
    grants: ["ext:acn_shop:wc-subscriber"],
    jti: `jti_${randomUUID()}`,
  });
  const callback = `/__spacefast/access-callback.php?token=${encodeURIComponent(externalWithJti)}&return=/external/`;
  expect((await get(rt, HOST, callback)).status).toBe(303);
  expect((await get(rt, HOST, callback)).status).toBe(403);

  await setPolicy([
    {
      match: { pathPattern: "/invite/**" },
      effect: "challenge",
      auth: { requiredGrants: ["invite:sin_test"], issuers: [ISSUER] },
    },
    {
      match: { pathPattern: "/viewer/**" },
      effect: "challenge",
      auth: { requiredGrants: ["space:spc_acc:viewer"], issuers: [ISSUER] },
    },
  ]);

  const replayableInviteOnlyToken = visitorToken({
    sub: "invite:sin_test",
    grants: ["invite:sin_test"],
  });
  const inviteCallback = `/__spacefast/access-callback.php?token=${encodeURIComponent(replayableInviteOnlyToken)}&return=/invite/`;
  expect((await get(rt, HOST, inviteCallback)).status).toBe(303);
  expect((await get(rt, HOST, inviteCallback)).status).toBe(303);

  const broadInviteNoJti = visitorToken({
    sub: "invite:sin_test",
    grants: ["invite:sin_test", "space:spc_acc:viewer", "email:reader@example.test"],
  });
  const broaderGrantCallback = `/__spacefast/access-callback.php?token=${encodeURIComponent(broadInviteNoJti)}&return=/invite/`;
  expect((await get(rt, HOST, broaderGrantCallback)).status).toBe(403);

  const broadInviteWithJti = visitorToken({
    sub: "invite:sin_test",
    grants: ["invite:sin_test", "space:spc_acc:viewer", "email:reader@example.test"],
    jti: `jti_${randomUUID()}`,
  });
  const broadInviteCallback = `/__spacefast/access-callback.php?token=${encodeURIComponent(broadInviteWithJti)}&return=/invite/`;
  const broadInviteAccepted = await get(rt, HOST, broadInviteCallback);
  expect(broadInviteAccepted.status).toBe(303);
  const broadCookie = (broadInviteAccepted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(broadCookie).toContain("spacefast_access=sfv1_");
  expect((await get(rt, HOST, broadInviteCallback)).status).toBe(403);
  expect(
    (
      await get(rt, HOST, "/viewer/", {
        headers: { authorization: `Bearer ${broadInviteWithJti}` },
      })
    ).status,
  ).toBe(401);
  expect(
    (
      await get(rt, HOST, "/viewer/", {
        headers: { cookie: `spacefast_access=${broadInviteWithJti}` },
      })
    ).status,
  ).toBe(401);
  expect((await get(rt, HOST, "/viewer/", { headers: { cookie: broadCookie } })).status).toBe(200);
});

test("BYO external callback tokens must carry a host audience", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["ext:acn_host:subscriber"], issuers: [EXTERNAL_ISSUER] },
    },
  ]);

  const withoutAudience = visitorToken({
    sub: "ext:acn_host:visitor",
    grants: ["ext:acn_host:subscriber"],
    aud: undefined,
    jti: `jti_${randomUUID()}`,
  });
  const rejected = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(withoutAudience)}&return=/private/`,
  );
  expect(rejected.status).toBe(403);

  const withAudience = visitorToken({
    sub: "ext:acn_host:visitor",
    grants: ["ext:acn_host:subscriber"],
    aud: HOST,
    jti: `jti_${randomUUID()}`,
  });
  const accepted = await get(
    rt,
    HOST,
    `/__spacefast/access-callback.php?token=${encodeURIComponent(withAudience)}&return=/private/`,
  );
  expect(accepted.status).toBe(303);
  const cookie = accepted.headers.get("set-cookie")?.split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=");
  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(200);
});

test("sf_share trades the param for the cookie, 303s to the clean URL, no-store, replayable", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk1"], issuers: [ISSUER] },
    },
  ]);
  const share = visitorToken({ sub: "link:lnk1", grants: ["link:lnk1"] });

  const trade = await get(rt, HOST, `/private/?sf_share=${encodeURIComponent(share)}&x=1`);
  expect(trade.status).toBe(303);
  expect(trade.headers.get("location")).toBe("/private/?x=1");
  expect(trade.headers.get("cache-control")).toBe("no-store");
  const cookie = (trade.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_access=");

  expect((await get(rt, HOST, "/private/", { headers: { cookie } })).status).toBe(200);

  // Replayable: the share URL is the credential (no jti).
  const again = await get(rt, HOST, `/private/?sf_share=${encodeURIComponent(share)}`);
  expect(again.status).toBe(303);

  // A no-expiry share link is a durable URL, not a token with a hidden fuse.
  // Only the signed, audience-bound, single-link shape may omit exp; the
  // resulting browser session remains bounded and can be refreshed by
  // opening the URL again.
  const durableShare = visitorToken(
    {
      sub: "link:lnk1",
      grants: ["link:lnk1"],
    },
    { omitExp: true },
  );
  const durableTrade = await get(
    rt,
    HOST,
    `/private/?sf_share=${encodeURIComponent(durableShare)}`,
  );
  expect(durableTrade.status).toBe(303);
  const durableCookie = (durableTrade.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(durableCookie).toContain("spacefast_access=");
  expect((await get(rt, HOST, "/private/", { headers: { cookie: durableCookie } })).status).toBe(
    200,
  );

  const expirylessMember = visitorToken({}, { omitExp: true });
  expect(
    (
      await get(rt, HOST, "/private/", {
        headers: { authorization: `Bearer ${expirylessMember}` },
      })
    ).status,
  ).toBe(401);

  const broadInviteHandoff = visitorToken({
    sub: "invite:sin_share",
    grants: ["invite:sin_share", "space:spc_acc:viewer", "email:reader@example.test"],
    jti: `jti_${randomUUID()}`,
  });
  const inviteTrade = await get(
    rt,
    HOST,
    `/private/?sf_share=${encodeURIComponent(broadInviteHandoff)}`,
  );
  expect(inviteTrade.status).toBe(303);
  expect(inviteTrade.headers.get("location")).toBe("/private/");
  expect(inviteTrade.headers.get("set-cookie") ?? "").not.toContain("spacefast_access=");

  const externalHandoff = visitorToken({
    sub: "wp:42",
    grants: ["ext:acn_shop:wc-subscriber"],
    jti: `jti_${randomUUID()}`,
  });
  const externalTrade = await get(
    rt,
    HOST,
    `/private/?sf_share=${encodeURIComponent(externalHandoff)}`,
  );
  expect(externalTrade.status).toBe(303);
  expect(externalTrade.headers.get("set-cookie") ?? "").not.toContain("spacefast_access=");
});

test("revoked link grant is challenged on the next request without a policy change", async () => {
  writeRevocations({});
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_A"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_A", grants: ["link:lnk_A"] });

  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(200);

  writeRevocations({ grants: { "link:lnk_A": Math.floor(Date.now() / 1000) } });
  const revoked = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(revoked.status).toBe(401);
});

test("tombstoned sub is rejected for /access/me and enforcement", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_sub"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({
    sub: "email:carol@acme.com",
    grants: ["link:lnk_sub"],
  });
  writeRevocations({ subs: { "email:carol@acme.com": Math.floor(Date.now() / 1000) } });
  const cookie = `spacefast_access=${token}`;

  const me = await get(rt, HOST, "/__spacefast/access/me", { headers: { cookie } });
  expect(me.status).toBe(401);
  expect(((await me.json()) as { error: { code: string } }).error.code).toBe(
    "access_unauthenticated",
  );

  const enforced = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(enforced.status).toBe(401);
});

test("revoking one grant preserves other grants and /access/me omits the revoked grant", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_B"], issuers: [ISSUER] },
    },
  ]);
  writeRevocations({ grants: { "link:lnk_A": Math.floor(Date.now() / 1000) } });
  const token = visitorToken({
    sub: "link:bundle",
    grants: ["link:lnk_A", "link:lnk_B"],
  });
  const cookie = `spacefast_access=${token}`;

  const allowed = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(allowed.status).toBe(200);

  const me = await get(rt, HOST, "/__spacefast/access/me", { headers: { cookie } });
  expect(me.status).toBe(200);
  expect(((await me.json()) as { grants: string[] }).grants).toEqual(["link:lnk_B"]);
});

test("management revocation writes tombstones, rejects visitor tokens, and unrevoke removes them", async () => {
  writeRevocations({});
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_mgmt"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_mgmt", grants: ["link:lnk_mgmt"] });
  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(200);

  const revoke = await api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/access/revocations`,
    "revoke_grant",
    { space_id: SPACE },
    { grant: "link:lnk_mgmt" },
  );
  expect(revoke.status).toBe(200);
  const storedAfterRevoke = JSON.parse(readFileSync(revocationsPath(), "utf8")) as {
    grants: Record<string, number>;
  };
  expect(typeof storedAfterRevoke.grants["link:lnk_mgmt"]).toBe("number");

  const rejected = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(rejected.status).toBe(401);

  const visitorWrite = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE}/access/revocations`)}`,
    {
      method: "POST",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${visitorToken({ sub: "link:lnk_mgmt", grants: ["link:lnk_mgmt"] })}`,
      },
      body: JSON.stringify({ grant: "link:lnk_mgmt" }),
    },
  );
  expect(visitorWrite.status).toBe(401);
  expect(await errorCode(visitorWrite)).toBe("runtime_token_expired");

  const unrevoke = await api(
    rt,
    "DELETE",
    `/__spacefast/api.php/spaces/${SPACE}/access/revocations`,
    "unrevoke_grant",
    { space_id: SPACE },
    { grant: "link:lnk_mgmt" },
  );
  expect(unrevoke.status).toBe(200);
  const storedAfterUnrevoke = JSON.parse(readFileSync(revocationsPath(), "utf8")) as {
    grants: Record<string, number>;
  };
  expect(storedAfterUnrevoke.grants["link:lnk_mgmt"]).toBeUndefined();
  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(200);
});

function repairStatePath(): string {
  return path.join(rt.storageRoot, "runtime", "repair-state.json");
}

function journalPath(): string {
  return path.join(rt.storageRoot, "runtime", "journal.jsonl");
}

test("missing revocations.json allows grant-backed access (the legitimate empty state)", async () => {
  rmSync(revocationsPath(), { force: true });
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_missing"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_missing", grants: ["link:lnk_missing"] });

  const allowed = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(allowed.status).toBe(200);
});

test("corrupt revocations.json denies grant-backed access (403, not a 500) and journals an engine-health event", async () => {
  rmSync(repairStatePath(), { force: true });
  writeFileSync(revocationsPath(), "{ this is not valid json ][");
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_corrupt"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_corrupt", grants: ["link:lnk_corrupt"] });

  const denied = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  // Fail closed: never the ordinary 401 re-challenge (retrying would not
  // help — this is an infra fault) and never a 500 (uncaught error).
  expect(denied.status).toBe(403);
  expect(denied.headers.get("content-type")).toContain("text/html");
  const body = await denied.text();
  expect(body).not.toContain("Signed in as"); // the identity itself came back unverified
  expect(body).not.toContain("did not work"); // never the wrong-password copy

  // The read layer journals an engine-health event so the control plane's
  // pull surfaces the corrupt file (same repair-state.json + journal.jsonl
  // pattern as an opcache-invalidation failure).
  const repairState = JSON.parse(readFileSync(repairStatePath(), "utf8")) as {
    code: string;
    details: { space_id?: string };
  };
  expect(repairState.code).toBe("revocations_unreadable");
  expect(repairState.details.space_id).toBe(SPACE);

  const journaled = readFileSync(journalPath(), "utf8")
    .trim()
    .split("\n")
    .map(
      (line) =>
        JSON.parse(line) as { event: string; code?: string; details?: { space_id?: string } },
    )
    .filter(
      (entry) =>
        entry.event === "runtime_repair_required" && entry.code === "revocations_unreadable",
    );
  expect(journaled.length).toBeGreaterThan(0);
  expect(journaled.at(-1)?.details?.space_id).toBe(SPACE);
});

test("corrupt revocations.json hard-denies even when the matched rule would fall through", async () => {
  // The dangerous shape: an ALLOW rule whose grant test fails (the token was
  // failed closed because revocations are unreadable) degrades to `continue`
  // — before the request-level check, the scan then ran past the fault and
  // the final fall-through ALLOWED the request. The unavailable flag must
  // hard-403 the request the moment any rule's token check hits it; no later
  // rule and no fall-through may override.
  writeFileSync(revocationsPath(), "{ still not valid json ][");
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "allow",
      auth: { requiredGrants: ["link:lnk_fallthrough"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_fallthrough", grants: ["link:lnk_fallthrough"] });

  const denied = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(denied.status).toBe(403);

  // Public/no-token requests never consult revocations, so the same corrupt
  // file must not affect them: the unsatisfied allow rule falls through to
  // the normal (allowed, private) outcome.
  const anonymous = await get(rt, HOST, "/private/");
  expect(anonymous.status).toBe(200);
});

test("a valid revocations.json behaves normally again after a prior corruption", async () => {
  writeRevocations({});
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_recovered"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_recovered", grants: ["link:lnk_recovered"] });

  const allowed = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(allowed.status).toBe(200);

  writeRevocations({ grants: { "link:lnk_recovered": Math.floor(Date.now() / 1000) } });
  const revoked = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(revoked.status).toBe(401);
});

test("route config.revocations REPLACES the stored grant set (dropping unlisted grants past the grace window) and leaves subs untouched", async () => {
  // Older than the replace grace window (STATTIC_REVOCATIONS_REPLACE_GRACE_SECONDS
  // = 600s in management.php): a genuinely stale entry, not a concurrent-revoke
  // race, so REPLACE must still drop it.
  const staleTimestamp = Math.floor(Date.now() / 1000) - 700;
  writeRevocations({
    grants: { "link:lnk_old": staleTimestamp },
    subs: { "sub:preserved": Math.floor(Date.now() / 1000) },
  });
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_full_replace"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({
    sub: "link:lnk_full_replace",
    grants: ["link:lnk_full_replace"],
  });
  // Not revoked yet: the seeded set only carries lnk_old.
  expect(
    (await get(rt, HOST, "/private/", { headers: { authorization: `Bearer ${token}` } })).status,
  ).toBe(200);

  // A durable sync (runtime.sync/publish/activation) carries the FULL
  // authoritative set on config.revocations — the runtime replaces its
  // grants bucket wholesale, converging even when lnk_old was never
  // explicitly unrevoked and no instant revoke_grant call ever landed for
  // lnk_full_replace.
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: { revocations: ["link:lnk_full_replace"] },
  });

  const stored = JSON.parse(readFileSync(revocationsPath(), "utf8")) as {
    grants: Record<string, number>;
    subs: Record<string, number>;
  };
  expect(typeof stored.grants["link:lnk_full_replace"]).toBe("number");
  expect(stored.grants["link:lnk_old"]).toBeUndefined();
  expect(typeof stored.subs["sub:preserved"]).toBe("number");

  const rejected = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(rejected.status).toBe(401);
});

test("route config.revocations REPLACE does not clear a grant tombstoned moments ago (in-flight-snapshot race)", async () => {
  // Simulates the race a stale route-config snapshot can hit: the instant
  // best-effort revoke_grant call already tombstoned lnk_racing (timestamp
  // "just now"), then a config.revocations REPLACE computed BEFORE that
  // revoke's Postgres commit — and delayed in flight — lands without
  // lnk_racing in its set. It must survive (grace window), not be wiped and
  // made usable again.
  writeRevocations({
    grants: { "link:lnk_racing": Math.floor(Date.now() / 1000) },
  });
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["link:lnk_racing"], issuers: [ISSUER] },
    },
  ]);
  const token = visitorToken({ sub: "link:lnk_racing", grants: ["link:lnk_racing"] });

  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: { revocations: ["link:lnk_unrelated"] },
  });

  const stored = JSON.parse(readFileSync(revocationsPath(), "utf8")) as {
    grants: Record<string, number>;
  };
  expect(typeof stored.grants["link:lnk_racing"]).toBe("number");

  const rejected = await get(rt, HOST, "/private/", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(rejected.status).toBe(401);
});

test("logout clears the cookie and 303s to a validated same-origin return", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);
  const response = await get(rt, HOST, "/__spacefast/access/logout?return=/private/");
  expect(response.status).toBe(303);
  expect(response.headers.get("location")).toBe("/private/");
  expect(response.headers.get("set-cookie") ?? "").toContain("spacefast_access=;");
});

test("/access/me and /access/token: 200 with a valid cookie, 401 anonymous, always no-store", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);
  const { cookie, token: sessionToken } = await accessSessionCredential();

  const meAnon = await get(rt, HOST, "/__spacefast/access/me");
  expect(meAnon.status).toBe(401);
  expect(meAnon.headers.get("cache-control")).toBe("no-store");
  expect(((await meAnon.json()) as { error: { code: string } }).error.code).toBe(
    "access_unauthenticated",
  );

  const me = await get(rt, HOST, "/__spacefast/access/me", { headers: { cookie } });
  expect(me.status).toBe(200);
  expect(((await me.json()) as { grants: string[] }).grants).toEqual(["user:alice"]);

  const tokenAnon = await get(rt, HOST, "/__spacefast/access/token");
  expect(tokenAnon.status).toBe(401);
  expect(tokenAnon.headers.get("cache-control")).toBe("no-store");

  const token = await get(rt, HOST, "/__spacefast/access/token", { headers: { cookie } });
  expect(token.status).toBe(200);
  expect(token.headers.get("cache-control")).toBe("no-store");
  expect(await token.text()).toBe(sessionToken);
});

test("/access/me accepts a callback session on a public policy with top-level issuers", async () => {
  await setPolicy([], null, 0, [ISSUER]);
  const { cookie } = await accessSessionCredential();

  const me = await get(rt, HOST, "/__spacefast/access/me", {
    headers: { cookie },
  });
  expect(me.status).toBe(200);
  expect(me.headers.get("cache-control")).toBe("no-store");
  const body = (await me.json()) as { sub: string; grants: string[] };
  expect(body.sub).toBe("user:alice");
  expect(body.grants).toEqual(["user:alice"]);
});

test("fetch/XHR gets 401 JSON, never an HTML challenge; OPTIONS is never challenged", async () => {
  await setPolicy([
    {
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: { requiredGrants: ["user:alice"], issuers: [ISSUER] },
    },
  ]);

  const xhr = await get(rt, HOST, "/private/", { headers: { "sec-fetch-mode": "cors" } });
  expect(xhr.status).toBe(401);
  expect(xhr.headers.get("content-type")).toContain("application/json");
  expect(((await xhr.json()) as { error: { code: string } }).error.code).toBe(
    "access_session_expired",
  );

  const options = await get(rt, HOST, "/private/", { method: "OPTIONS" });
  expect(options.status).not.toBe(401);
});

test("canonicalization corpus: percent-decoded, dot-segment, and mixed-case-host forms all match", async () => {
  await setPolicy([
    {
      match: { host: HOST, pathPattern: "/members/**" },
      effect: "deny",
      reasonCode: "members-locked",
    },
  ]);

  const percent = await get(rt, HOST, "/%6Dembers/");
  expect(percent.status).toBe(403);
  expect(percent.headers.get("x-spacefast-reason")).toBe("members-locked");

  const dots = await get(rt, HOST, "/members/../members/");
  expect(dots.status).toBe(403);

  // Host match is case-insensitive.
  const upper = await get(rt, "ACC.TEST", "/members/");
  expect(upper.status).toBe(403);
});

test("brute-force throttle engages after repeated password failures", async () => {
  const ruleId = `throttle_${randomUUID().replace(/-/g, "")}`;
  await setPolicy(
    [
      {
        id: ruleId,
        match: { pathPattern: "/private/**" },
        effect: "challenge",
        auth: {
          requiredGrants: [`pw:${ruleId}`],
          acquire: [{ type: "password", ref: "secret:throttle_pw", transport: "form" }],
        },
      },
    ],
    { throttle_pw: passwordHash },
    0,
  );

  const attempt = () =>
    get(rt, HOST, "/private/", {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ _pw: "wrong" }).toString(),
    });

  const attemptUntilThrottle = async (remaining: number): Promise<boolean> => {
    const response = await attempt();
    if (response.status === 429) {
      expect(response.headers.get("retry-after")).not.toBeNull();
      return true;
    }
    expect(response.status).toBe(401);
    return remaining > 1 ? attemptUntilThrottle(remaining - 1) : false;
  };
  expect(await attemptUntilThrottle(8)).toBe(true);
});

test("host match restricts immutable version hosts independently", async () => {
  await setPolicy([
    { match: { host: VERSION_HOST }, effect: "deny", reasonCode: "old-version-locked" },
  ]);
  const version = await get(rt, VERSION_HOST, "/");
  expect(version.status).toBe(403);
  expect(version.headers.get("x-spacefast-reason")).toBe("old-version-locked");
  expect((await get(rt, HOST, "/")).status).toBe(200);
});

test("clearing the policy restores public serving", async () => {
  await setPolicy([{ match: {}, effect: "deny" }]);
  expect((await get(rt, HOST, "/")).status).toBe(403);
  await setPolicy(null, null);
  expect((await get(rt, HOST, "/")).status).toBe(200);
});

// ---------------------------------------------------------------------------
// Access-event journal (X-37 / access-plan §5.6b): every enforced decision
// appends an accessEventSchema-shaped NDJSON line under the private root; the
// cloud pulls it through the management `access_events` action with a
// {file, offset} cursor. Grants NEVER appear — only grantsHash.
// ---------------------------------------------------------------------------

type AccessEvent = {
  sub: string | null;
  grantsHash: string | null;
  ruleId: string | null;
  effect: "allow" | "deny" | "challenge";
  reasonCode: string | null;
  host: string;
  path: string;
  ts: number;
};
type AccessEventsPull = {
  events: AccessEvent[];
  cursor: { file: string; offset: number } | null;
  done: boolean;
  dropped: number;
};

async function pullAccessEvents(cursor?: {
  file: string;
  offset: number;
}): Promise<AccessEventsPull> {
  const query = cursor ? `?file=${cursor.file}&offset=${cursor.offset}` : "";
  const response = await api(
    rt,
    "GET",
    `/__spacefast/api.php/access-events${query}`,
    "access_events",
  );
  expect(response.status).toBe(200);
  return (await response.json()) as AccessEventsPull;
}

test("enforced decisions journal schema-shaped events with grantsHash and no raw grants; the cursor advances", async () => {
  const ruleId = `evt_${randomUUID().replace(/-/g, "")}`;
  await setPolicy([
    {
      id: ruleId,
      match: { pathPattern: "/private/**" },
      effect: "challenge",
      auth: {
        requiredGrants: ["user:alice"],
        issuers: [ISSUER],
        acquire: [{ type: "login", url: "https://api.spacefast.com/v1/access/authorize" }],
      },
    },
  ]);

  expect((await get(rt, HOST, "/private/")).status).toBe(401); // anonymous → challenge
  const bob = await accessSessionCredential({ sub: "user:bob", grants: ["user:bob"] });
  expect(
    (
      await get(rt, HOST, "/private/", {
        headers: { cookie: bob.cookie },
      })
    ).status,
  ).toBe(403); // identified-but-unsatisfied → deny
  const alice = await accessSessionCredential();
  expect((await get(rt, HOST, "/private/", { headers: { cookie: alice.cookie } })).status).toBe(
    200,
  ); // satisfied → allow

  const pull = await pullAccessEvents();
  expect(pull.done).toBe(true);
  expect(pull.dropped).toBe(0);
  expect(pull.cursor).not.toBeNull();
  const mine = pull.events.filter((event) => event.ruleId === ruleId);
  expect(mine.map((event) => event.effect)).toEqual(["challenge", "deny", "allow"]);

  const [challenge, deny, allow] = mine as [AccessEvent, AccessEvent, AccessEvent];
  // Exactly the accessEventSchema keys — never a raw grants field.
  for (const event of mine) {
    expect(Object.keys(event).toSorted()).toEqual([
      "effect",
      "grantsHash",
      "host",
      "path",
      "reasonCode",
      "ruleId",
      "sub",
      "ts",
    ]);
    expect(event.host).toBe(HOST);
    expect(event.path).toBe("/private/");
    expect(typeof event.ts).toBe("number");
  }
  expect(challenge.sub).toBeNull();
  expect(challenge.grantsHash).toBeNull();
  expect(deny.sub).toBe("user:bob");
  expect(deny.grantsHash).toBe(sha256("user:bob"));
  expect(allow.sub).toBe("user:alice");
  expect(allow.grantsHash).toBe(sha256("user:alice"));
  expect(JSON.stringify(mine)).not.toContain('"grants"');

  // Cursor-advance: only the decisions after the cursor come back.
  const cursor = pull.cursor as { file: string; offset: number };
  expect((await get(rt, HOST, "/private/", { headers: { cookie: alice.cookie } })).status).toBe(
    200,
  );
  const next = await pullAccessEvents(cursor);
  expect(next.events).toHaveLength(1);
  expect(next.events[0]?.ruleId).toBe(ruleId);
  expect(next.events[0]?.effect).toBe("allow");
  expect(next.cursor?.file).toBe(cursor.file);
  expect(next.cursor?.offset).toBeGreaterThan(cursor.offset);

  // Nothing new → an empty page at the same cursor.
  const idle = await pullAccessEvents(next.cursor as { file: string; offset: number });
  expect(idle.events).toHaveLength(0);
  expect(idle.done).toBe(true);
});

test("an anonymous firewall deny journals sub null with the rule's reasonCode", async () => {
  const ruleId = `evt_${randomUUID().replace(/-/g, "")}`;
  await setPolicy([
    { id: ruleId, match: { pathPattern: "/members/**" }, effect: "deny", reasonCode: "geo-block" },
  ]);
  expect((await get(rt, HOST, "/members/")).status).toBe(403);

  const pull = await pullAccessEvents();
  const mine = pull.events.filter((event) => event.ruleId === ruleId);
  expect(mine).toHaveLength(1);
  expect(mine[0]).toMatchObject({
    sub: null,
    grantsHash: null,
    effect: "deny",
    reasonCode: "geo-block",
    host: HOST,
    path: "/members/",
  });
});

test("the access-events pull requires the management JWT with the access_events action", async () => {
  const unauthenticated = await fetch(`${rt.baseUrl}/__spacefast/api.php?route=/access-events`);
  expect(unauthenticated.status).toBe(401);

  const wrongAction = await fetch(`${rt.baseUrl}/__spacefast/api.php?route=/access-events`, {
    headers: { authorization: `Bearer ${managementToken("read_state")}` },
  });
  expect(wrongAction.status).toBe(403);

  const badCursor = await api(
    rt,
    "GET",
    "/__spacefast/api.php/access-events?file=..%2Fjournal.jsonl",
    "access_events",
  );
  expect(badCursor.status).toBe(422);
});

test("trusted-header contract: a spoofed CF-IPCountry cannot fire a country block on an untrusted edge", async () => {
  const countryHost = "country.test";
  // Default runtime is untrusted: CF-IPCountry is stripped inbound.
  const untrusted = await startRuntime();
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    await Promise.all(
      [untrusted, trusted].map(async (runtime) => {
        await deploy(runtime, {
          spaceId: "spc_country",
          versionId: "ver_country_1",
          files: { "index.html": "<h1>country</h1>\n" },
          activate: {
            route_name: "production",
            config: { mode: "website" },
            production_hostnames: [countryHost],
            version_hostnames: [],
          },
        });
        await putRoute(runtime, "spc_country", "production", {
          version_id: "ver_country_1",
          config: {
            policy: {
              rules: [{ match: { country: "US" }, effect: "deny", reasonCode: "geo-block" }],
            },
          },
        });
      }),
    );

    // Untrusted edge strips the spoofed geo header — the block cannot fire.
    const spoofed = await get(untrusted, countryHost, "/", { headers: { "cf-ipcountry": "US" } });
    expect(spoofed.status).toBe(200);

    // A trusted edge honors the header — the block fires.
    const honored = await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "US" } });
    expect(honored.status).toBe(403);
    expect(honored.headers.get("x-spacefast-reason")).toBe("geo-block");
  } finally {
    untrusted.stop();
    trusted.stop();
  }
});

test("runtime health probe bypasses broad and country-conditioned visitor access", async () => {
  const probeHost = "access-probe.test";
  const spaceId = "spc_access_probe";
  const versionId = "ver_access_probe_1";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    await deploy(trusted, {
      spaceId,
      versionId,
      files: { "index.html": "<h1>probe target</h1>\n" },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [probeHost],
        version_hostnames: [],
      },
    });

    const assertHealthyProbe = async (
      policyName: string,
      deniedHeaders: Record<string, string>,
    ): Promise<void> => {
      const denied = await get(trusted, probeHost, "/", { headers: deniedHeaders });
      expect(denied.status, policyName).toBe(403);

      for (const method of ["GET", "HEAD"] as const) {
        const probe = await get(
          trusted,
          probeHost,
          `/__stattic_probe?__stattic_probe=${policyName}`,
          { method, headers: deniedHeaders },
        );
        expect(probe.status, `${policyName}:${method}`).toBe(204);
        expect(probe.headers.get("x-spacefast-runtime"), `${policyName}:${method}`).toBe("1");
        expect(probe.headers.get("x-spacefast-version"), `${policyName}:${method}`).toBe(versionId);
        expect(probe.headers.get("cache-control"), `${policyName}:${method}`).toBe(
          "no-store, no-cache, must-revalidate",
        );
        expect(await probe.text(), `${policyName}:${method}`).toBe("");
      }
    };

    await putRoute(trusted, spaceId, "production", {
      version_id: versionId,
      config: {
        policy: {
          rules: [
            {
              match: { pathPattern: "/**" },
              effect: "deny",
              reasonCode: "broad-block",
            },
          ],
        },
      },
    });
    await assertHealthyProbe("broad", {});

    await putRoute(trusted, spaceId, "production", {
      version_id: versionId,
      config: {
        policy: {
          rules: [
            {
              match: { countryNotIn: ["DE"] },
              effect: "deny",
              reasonCode: "country-block",
            },
          ],
        },
      },
    });
    await assertHealthyProbe("country", { "cf-ipcountry": "US" });
  } finally {
    trusted.stop();
  }
});

test("countryNotIn rules stay cache-private across every request-varying match predicate", async () => {
  const countryHost = "country-exclusive.test";
  const otherHost = "country-exclusive-other.test";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    await deploy(trusted, {
      spaceId: "spc_country_exclusive",
      versionId: "ver_country_exclusive_1",
      files: {
        "index.html": "<h1>country exclusive</h1>\n",
        "private/index.html": "<h1>country private</h1>\n",
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [countryHost, otherHost],
        version_hostnames: [],
      },
    });

    await putRoute(trusted, "spc_country_exclusive", "production", {
      version_id: "ver_country_exclusive_1",
      config: {
        policy: {
          rules: [
            { match: { countryNotIn: ["DE"] }, effect: "allow" },
            { match: { country: "DE" }, effect: "deny", reasonCode: "geo-block" },
          ],
        },
      },
    });
    const anonymousAllow = await get(trusted, countryHost, "/", {
      headers: { "cf-ipcountry": "US" },
    });
    expect(anonymousAllow.status).toBe(200);
    expect(anonymousAllow.headers.get("cache-control")).toBe("private, no-store");
    expect(anonymousAllow.headers.get("cdn-cache-control")).toBe("private, no-store");
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "DE" } })).status,
    ).toBe(403);

    const cases = [
      {
        name: "country",
        varyingMatch: { country: "US" },
        publicHeaders: { "cf-ipcountry": "DE" },
        deniedHeaders: { "cf-ipcountry": "US" },
      },
      {
        name: "ipCidrs",
        varyingMatch: { ipCidrs: ["203.0.113.0/24"] },
        publicHeaders: { "cf-ipcountry": "DE", "cf-connecting-ip": "198.51.100.1" },
        deniedHeaders: { "cf-ipcountry": "US", "cf-connecting-ip": "203.0.113.7" },
      },
      {
        name: "agent",
        varyingMatch: { agent: "BlockedBot" },
        publicHeaders: { "cf-ipcountry": "DE", "user-agent": "Mozilla/5.0" },
        deniedHeaders: { "cf-ipcountry": "US", "user-agent": "BlockedBot/1.0" },
      },
      {
        name: "header",
        varyingMatch: { header: { name: "x-segment", value: "blocked" } },
        publicHeaders: { "cf-ipcountry": "DE", "x-segment": "open" },
        deniedHeaders: { "cf-ipcountry": "US", "x-segment": "blocked" },
      },
    ] as const;

    const assertScenario = async (scenario: (typeof cases)[number]): Promise<void> => {
      await putRoute(trusted, "spc_country_exclusive", "production", {
        version_id: "ver_country_exclusive_1",
        config: {
          policy: {
            rules: [
              {
                match: {
                  host: countryHost,
                  pathPattern: "/private/**",
                  channel: "live",
                  countryNotIn: ["DE"],
                  ...scenario.varyingMatch,
                },
                effect: "deny",
                reasonCode: `geo-${scenario.name}`,
              },
            ],
          },
        },
      });

      // This request misses the request-varying match, including countryNotIn,
      // but its 200 shares a cache key with a request the rule denies.
      const allowedResponse = await get(trusted, countryHost, "/private/", {
        headers: scenario.publicHeaders,
      });
      expect(allowedResponse.status, scenario.name).toBe(200);
      expect(allowedResponse.headers.get("cache-control"), scenario.name).toBe("private, no-store");
      expect(allowedResponse.headers.get("cdn-cache-control"), scenario.name).toBe(
        "private, no-store",
      );

      const denied = await get(trusted, countryHost, "/private/", {
        headers: scenario.deniedHeaders,
      });
      expect(denied.status, scenario.name).toBe(403);
      expect(denied.headers.get("x-spacefast-reason"), scenario.name).toBe(`geo-${scenario.name}`);

      // Cache-path classification must retain host and path scope.
      const outsidePath = await get(trusted, countryHost, "/", {
        headers: scenario.publicHeaders,
      });
      expect(outsidePath.status, scenario.name).toBe(200);
      expect(outsidePath.headers.get("cache-control"), scenario.name).not.toContain("no-store");

      const outsideHost = await get(trusted, otherHost, "/private/", {
        headers: scenario.publicHeaders,
      });
      expect(outsideHost.status, scenario.name).toBe(200);
      expect(outsideHost.headers.get("cache-control"), scenario.name).not.toContain("no-store");
    };

    // Route configuration is shared by this runtime, so exercise each policy
    // serially instead of racing updates against requests from another case.
    await assertScenario(cases[0]);
    await assertScenario(cases[1]);
    await assertScenario(cases[2]);
    await assertScenario(cases[3]);
  } finally {
    trusted.stop();
  }
});

test("country allowlist denies Tor, unknown, malformed, and missing trusted-edge values", async () => {
  const countryHost = "country-allow.test";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    await deploy(trusted, {
      spaceId: "spc_country_allow",
      versionId: "ver_country_allow_1",
      files: { "index.html": "<h1>country allow</h1>\n" },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [countryHost],
        version_hostnames: [],
      },
    });
    await putRoute(trusted, "spc_country_allow", "production", {
      version_id: "ver_country_allow_1",
      config: {
        policy: {
          rules: [
            {
              match: { countryNotIn: ["US"] },
              effect: "deny",
              reasonCode: "geo-allowlist",
            },
          ],
        },
      },
    });

    const allowedCountries = ["US", "us"];
    const allowedResponses = await Promise.all(
      allowedCountries.map((country) =>
        get(trusted, countryHost, "/", { headers: { "cf-ipcountry": country } }),
      ),
    );
    for (const [index, country] of allowedCountries.entries()) {
      const allowed = allowedResponses[index];
      expect(allowed.status, country).toBe(200);
      expect(allowed.headers.get("cache-control"), country).toContain("private");
      expect(allowed.headers.get("cache-control"), country).toContain("no-store");
    }
    const deniedCountries = ["DE", "XX", "T1", "USA"];
    const deniedResponses = await Promise.all(
      deniedCountries.map((country) =>
        get(trusted, countryHost, "/", { headers: { "cf-ipcountry": country } }),
      ),
    );
    for (const [index] of deniedCountries.entries()) {
      const denied = deniedResponses[index];
      expect(denied.status).toBe(403);
      expect(denied.headers.get("x-spacefast-reason")).toBe("geo-allowlist");
    }
    expect((await get(trusted, countryHost, "/")).status).toBe(403);

    const legacyAllowRules = ISO_COUNTRY_CODES.filter((code) => code !== "US").map((code) => ({
      id: `firewall-country:allow:${code}`,
      match: { country: code },
      effect: "deny",
      managedBy: "firewall",
      reasonCode: "legacy-geo-allowlist",
    }));
    await putRoute(trusted, "spc_country_allow", "production", {
      version_id: "ver_country_allow_1",
      config: { policy: { rules: legacyAllowRules } },
    });
    const legacyAllowed = await get(trusted, countryHost, "/", {
      headers: { "cf-ipcountry": "US" },
    });
    expect(legacyAllowed.status).toBe(200);
    expect(legacyAllowed.headers.get("cache-control")).toContain("private");
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "XX" } })).status,
    ).toBe(403);
    expect((await get(trusted, countryHost, "/")).status).toBe(403);

    await putRoute(trusted, "spc_country_allow", "production", {
      version_id: "ver_country_allow_1",
      config: {
        policy: {
          rules: ISO_COUNTRY_CODES.map((code) => ({
            id: `firewall-country:allow:${code}`,
            match: { country: code },
            effect: "deny",
            managedBy: "firewall",
            reasonCode: "legacy-empty-geo-allowlist",
          })),
        },
      },
    });
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "US" } })).status,
    ).toBe(403);
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "XX" } })).status,
    ).toBe(403);
    expect((await get(trusted, countryHost, "/")).status).toBe(403);

    await putRoute(trusted, "spc_country_allow", "production", {
      version_id: "ver_country_allow_1",
      config: {
        policy: {
          rules: [{ match: { country: "RU" }, effect: "deny", reasonCode: "geo-block" }],
        },
      },
    });
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "RU" } })).status,
    ).toBe(403);
    expect(
      (await get(trusted, countryHost, "/", { headers: { "cf-ipcountry": "XX" } })).status,
    ).toBe(200);
    expect((await get(trusted, countryHost, "/")).status).toBe(200);
  } finally {
    trusted.stop();
  }
});

test("the access cookie is Secure by default; SPACEFAST_INSECURE_COOKIES=1 is the only escape", async () => {
  const host = "secure-cookie.test";
  const space = "spc_secure_cookie";
  const version = "ver_secure_cookie_1";

  async function mintCookie(runtime: Runtime): Promise<string> {
    await deploy(runtime, {
      spaceId: space,
      versionId: version,
      files: { "index.html": "<h1>open</h1>\n", "private/index.html": "<h1>private</h1>\n" },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        version_hostnames: [],
      },
    });
    await putRoute(runtime, space, "production", {
      version_id: version,
      config: {
        policy: {
          rules: [
            {
              id: "pw_secure",
              match: { pathPattern: "/private/**" },
              effect: "challenge",
              auth: {
                requiredGrants: ["pw:pw_secure"],
                acquire: [{ type: "password", ref: "secret:site_pw", transport: "form" }],
              },
            },
          ],
          sessionVersion: 0,
        },
        secrets: { site_pw: passwordHash },
      },
    });
    const granted = await get(runtime, host, "/private/", {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ _pw: PASSWORD }).toString(),
    });
    expect(granted.status).toBe(303);
    const setCookie = granted.headers.get("set-cookie") ?? "";
    expect(setCookie).toContain("spacefast_access=");
    return setCookie;
  }

  // Production default: SPACEFAST_INSECURE_COOKIES unset, so the cookie
  // carries Secure even though the harness itself talks plain http.
  const secureRt = await startRuntime({ env: { SPACEFAST_INSECURE_COOKIES: "" } });
  try {
    const setCookie = await mintCookie(secureRt);
    expect(setCookie).toContain("; Secure");
  } finally {
    secureRt.stop();
  }

  // The dev/test escape: with SPACEFAST_INSECURE_COOKIES=1 (what the shared
  // `rt` in this file runs with — see harness.ts DEFAULT_ENV), Secure is
  // omitted so a spec-compliant cookie jar sends it back over http://127.0.0.1.
  const setCookie = await mintCookie(rt);
  expect(setCookie).not.toContain("Secure");
});

test("the union sharing rule admits by ANY key: password form, share link, or team grant", async () => {
  // The exact shape the cloud compiler emits for every restricted space
  // (access v2): ONE rule carrying the fail-closed floor plus every key, with
  // the password form and login legs as acquire choices on the same rule.
  await setPolicy(
    [
      {
        id: "sharing-general",
        match: {},
        effect: "challenge",
        auth: {
          requiredGrants: ["team:team_u:viewer", "pw:sharing-general", "link:lnk_u"],
          issuers: [ISSUER],
          acquire: [
            { type: "password", ref: "secret:space-password", transport: "form" },
            { type: "login", url: "https://login.example.test/authorize" },
          ],
        },
        reasonCode: "sharing_restricted",
        message: "This space is restricted.",
      },
    ],
    { "space-password": passwordHash },
    0,
  );

  // Anonymous: challenged with the password form AND the login leg (chooser).
  const walled = await get(rt, HOST, "/");
  expect(walled.status).toBe(401);
  const walledBody = await walled.text();
  expect(walledBody).toContain('name="_pw"');
  // The login leg renders as the runtime's indirected login endpoint (the
  // acquire URL is never inlined), beside the password form — one chooser.
  expect(walledBody).toContain("/__spacefast/access/login?method=");

  // Key 1 — the password form mints pw:{ruleId} and admits.
  const granted = await get(rt, HOST, "/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: PASSWORD }).toString(),
  });
  expect(granted.status).toBe(303);
  const pwCookie = (granted.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(pwCookie).toContain("spacefast_access=");
  expect((await get(rt, HOST, "/", { headers: { cookie: pwCookie } })).status).toBe(200);
  const me = await get(rt, HOST, "/__spacefast/access/me", { headers: { cookie: pwCookie } });
  const meBody = (await me.json()) as { grants: string[] };
  expect(meBody.grants).toEqual(["pw:sharing-general"]);

  // Key 2 — a share-link token trades ?sf_share= for the cookie and admits.
  const share = visitorToken({ sub: "link:lnk_u", grants: ["link:lnk_u"] });
  const trade = await get(rt, HOST, `/?sf_share=${encodeURIComponent(share)}`);
  expect(trade.status).toBe(303);
  const linkCookie = (trade.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect((await get(rt, HOST, "/", { headers: { cookie: linkCookie } })).status).toBe(200);

  // Floor — a member session's team grant intersects the same rule (member
  // tokens ride the callback exchange, never a raw broad-token cookie).
  const member = await accessSessionCredential({
    sub: "usr_member",
    grants: ["user:usr_member", "team:team_u:viewer"],
  });
  expect((await get(rt, HOST, "/", { headers: { cookie: member.cookie } })).status).toBe(200);

  // A wrong password on the union rule re-renders the chooser, never a loop.
  const wrong = await get(rt, HOST, "/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: "nope" }).toString(),
  });
  expect(wrong.status).toBe(401);
  expect(await wrong.text()).toContain("did not work");
});
