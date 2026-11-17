// A Space's own credential as the installed engine uses it: the key it holds,
// the public half it hands the control plane, the token it gets with
// `private_key_jwt`, and the older credential it falls back to whenever the
// control plane has not registered that key (or refuses its token).
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createPublicKey, verify } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  api,
  apiJson,
  deploy,
  get,
  publicAccessConfig,
  RUNTIME_HTTP_API_BASE,
  selfSignedCertificate,
  runtimeHttpPath,
  type Runtime,
  spaceRoot,
  startRuntime,
} from "./harness.ts";

const SPACE = "spc_space_identity";
const VERSION = "ver_space_identity";
const HOST = "space-identity.test";
const EXCHANGE_CREDENTIAL = "space-identity-exchange-credential-0123456789";
const CLIENT_ID = `spacefast-space:${SPACE}`;
const PASSWORD = "correct horse battery staple";
// What the route config grants, the Functions scope with the rest.
const SCOPE =
  "space-runtime:exchange space-runtime:callback space-runtime:connectors space-runtime:functions";

let runtime: Runtime;
let controlPlane: ReturnType<typeof Bun.serve>;
let origin = "";
// What the stand-in control plane does with a Space token.
let tokenEndpoint: "issue" | "absent" | "limited" = "issue";
let exchangeAcceptsToken = true;
const calls: Array<{
  path: string;
  authorization: string | null;
  legacy: string | null;
  form: URLSearchParams;
}> = [];
let lastAssertion = "";

const jwkSchema = z.object({
  kty: z.literal("OKP"),
  crv: z.literal("Ed25519"),
  x: z.string(),
  kid: z.string(),
  alg: z.literal("EdDSA"),
  use: z.literal("sig"),
});

type SpaceIdentityBlock = {
  clientId: string;
  tokenUrl: string;
  resource: string;
  kid: string;
  scope: string;
};

type AccessPageFixture = {
  displayName: null;
  accountUrl: null;
  exchange: { passwordUrl: string; tokenUrl: string; requestUrl: string; credential: string };
  identity?: SpaceIdentityBlock;
};

function accessPage(identityKid: string | null): AccessPageFixture {
  const page: AccessPageFixture = {
    displayName: null,
    accountUrl: null,
    exchange: {
      passwordUrl: `${origin}/acquire/space-identity/password`,
      tokenUrl: `${origin}/acquire/space-identity/token`,
      requestUrl: `${origin}/acquire/space-identity/request`,
      credential: EXCHANGE_CREDENTIAL,
    },
  };
  // A control plane that has registered the box's key names it; one that has
  // not leaves the block out.
  if (identityKid !== null)
    page.identity = {
      clientId: CLIENT_ID,
      tokenUrl: `${origin}/v1/auth/oauth2/token`,
      resource: `${origin}/space-runtime`,
      kid: identityKid,
      scope: SCOPE,
    };
  return page;
}

let generation = 0;
/** The Functions config finalize writes into a version: a Worker for every path. */
type FunctionsFixture = {
  artifact: {
    appName: string;
    entry: string;
    mainModule: string;
    compatibilityDate: string;
    compatibilityFlags: string[];
    routes: Array<{ method: null; path: string; subtree: boolean }>;
  };
  host: { hostname: string; bundleUrl: string };
  grantedCapabilities: string[];
  usage: { url: string; token: string };
};

async function activate(identityKid: string | null, functions?: FunctionsFixture) {
  generation += 1;
  await deploy(runtime, {
    spaceId: SPACE,
    versionId: `${VERSION}_${generation}`,
    files: { "index.html": "<h1>private</h1>\n" },
    ...(functions ? { functions } : undefined),
    activate: {
      route_name: "production",
      config: {
        projection_generation: generation,
        // A Space with a Worker is public here, so a visit reaches dispatch.
        ...(functions ? { public_exposure: publicAccessConfig().public_exposure } : undefined),
        authorization: {
          generation,
          sessionVersion: 0,
          fence: "none",
          acquireUrl: `${origin}/acquire/space-identity`,
          spaceClaimed: true,
          accessPage: accessPage(identityKid),
          grants: [
            ...(functions
              ? [
                  {
                    id: "grt_space_identity_public",
                    generation: 1,
                    audience: { kind: "public" },
                    resources: { include: ["/**"], exclude: [] },
                    capabilities: ["page.view"],
                    constraints: {},
                    target: { kind: "live" },
                    source: { kind: "managed", reference: "test:space-identity" },
                  },
                ]
              : []),
            {
              id: "grt_space_identity_password",
              generation: 1,
              audience: { kind: "password", credentialId: "pwd_space_identity" },
              resources: { include: ["/**"], exclude: [] },
              capabilities: ["page.view"],
              constraints: {},
              target: { kind: "live" },
              source: { kind: "managed", reference: "test:space-identity" },
            },
          ],
        },
      },
      production_hostnames: [HOST],
    },
  });
}

async function boxKeys() {
  const response = await apiJson<{ space_id: string; jwk: unknown }>(
    runtime,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/identity`,
    "read_space_identity",
    { space_id: SPACE },
  );
  expect(response.space_id).toBe(SPACE);
  return { jwk: jwkSchema.parse(response.jwk) };
}

async function boxKey() {
  return (await boxKeys()).jwk;
}

async function submitPassword() {
  calls.length = 0;
  return get(runtime, HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded", origin: `https://${HOST}` },
    body: new URLSearchParams({ password: PASSWORD, return: "/" }),
  });
}

function exchangeCalls() {
  return calls.filter((call) => call.path === "/acquire/space-identity/password");
}

beforeAll(async () => {
  controlPlane = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const url = new URL(request.url);
      const form = new URLSearchParams(await request.text());
      calls.push({
        path: url.pathname,
        authorization: request.headers.get("authorization"),
        legacy: request.headers.get("spacefast-runtime-exchange"),
        form,
      });
      if (url.pathname === "/v1/auth/oauth2/token") {
        // An older control plane has no Space client: the provider refuses.
        if (tokenEndpoint === "absent")
          return Response.json({ error: "invalid_client" }, { status: 400 });
        if (tokenEndpoint === "limited")
          return Response.json(
            { error: "rate_limited" },
            { status: 429, headers: { "retry-after": "5" } },
          );
        lastAssertion = form.get("client_assertion") ?? "";
        return Response.json({
          access_token: `space-token-${calls.length}`,
          token_type: "Bearer",
          expires_in: 300,
          scope: form.get("scope"),
        });
      }
      if (url.pathname === "/acquire/space-identity/password") {
        const bearer = request.headers.get("authorization");
        const legacy = request.headers.get("spacefast-runtime-exchange");
        // A newer control plane takes the token; an older image never reads
        // Authorization on this lane and checks only the older credential.
        const accepted = exchangeAcceptsToken
          ? bearer !== null || legacy === EXCHANGE_CREDENTIAL
          : legacy === EXCHANGE_CREDENTIAL;
        // A control plane that does not accept the token answers as for an unknown target.
        if (!accepted) return Response.json({ code: "not_found" }, { status: 404 });
        return Response.json({ code: "invalid_password" }, { status: 403 });
      }
      return new Response("not found", { status: 404 });
    },
  });
  origin = `http://127.0.0.1:${controlPlane.port}`;
  runtime = await startRuntime({
    visitorScheme: "https",
    env: { SPACEFAST_INSECURE_COOKIES: "" },
  });
});

afterAll(() => {
  runtime?.stop();
  controlPlane?.stop(true);
});

test("the box makes one Ed25519 key per Space, keeps it private, and hands out only the public half", async () => {
  await activate(null);
  const first = await boxKey();
  const again = await boxKey();
  expect(again).toEqual(first);
  const stored = path.join(spaceRoot(runtime, SPACE), "identity", "key.json");
  expect(existsSync(stored)).toBe(true);
  // The private half stays on disk under the private root and never in the answer.
  expect(JSON.stringify(first)).not.toContain(
    z.object({ secret: z.string() }).parse(JSON.parse(readFileSync(stored, "utf8"))).secret,
  );
  // Only a management token for this action reads it.
  const unauthenticated = await fetch(
    `${runtime.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/identity`)}`,
  );
  expect([401, 403]).toContain(unauthenticated.status);
  expect(await unauthenticated.text()).not.toContain(first.x);
  const wrongAction = await api(
    runtime,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/identity`,
    "read_state",
    { space_id: SPACE },
  );
  expect(wrongAction.status).toBe(403);
  // A token for another Space reads nothing here.
  const otherSpace = await api(
    runtime,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/identity`,
    "read_space_identity",
    { space_id: "spc_someone_else" },
  );
  expect(otherSpace.status).toBe(403);
});

test("a control plane that never read the key gets the older credential and no token request", async () => {
  await activate(null);
  tokenEndpoint = "issue";
  const response = await submitPassword();
  expect(response.status).toBe(303);
  expect(calls.some((call) => call.path === "/v1/auth/oauth2/token")).toBe(false);
  const [call] = exchangeCalls();
  expect(call?.legacy).toBe(EXCHANGE_CREDENTIAL);
  expect(call?.authorization).toBeNull();
});

test("once its key is named, the box signs a private_key_jwt assertion and sends its Space token", async () => {
  const key = await boxKey();
  await activate(key.kid);
  tokenEndpoint = "issue";
  exchangeAcceptsToken = true;
  await submitPassword();
  const tokenCall = calls.find((call) => call.path === "/v1/auth/oauth2/token");
  expect(tokenCall?.form.get("grant_type")).toBe("client_credentials");
  expect(tokenCall?.form.get("client_id")).toBe(CLIENT_ID);
  expect(tokenCall?.form.get("client_assertion_type")).toBe(
    "urn:ietf:params:oauth:client-assertion-type:jwt-bearer",
  );
  expect(tokenCall?.form.get("resource")).toBe(`${origin}/space-runtime`);
  // RFC 7523: iss and sub are the client, aud the token endpoint, signed by the box's key.
  const [header, payload, signature] = lastAssertion.split(".");
  const claims = z
    .object({ iss: z.string(), sub: z.string(), aud: z.string(), exp: z.number(), jti: z.string() })
    .parse(JSON.parse(Buffer.from(payload ?? "", "base64url").toString("utf8")));
  expect(claims.iss).toBe(CLIENT_ID);
  expect(claims.sub).toBe(CLIENT_ID);
  expect(claims.aud).toBe(`${origin}/v1/auth/oauth2/token`);
  expect(JSON.parse(Buffer.from(header ?? "", "base64url").toString("utf8")).kid).toBe(key.kid);
  expect(
    verify(
      null,
      Buffer.from(`${header}.${payload}`),
      createPublicKey({ key: { kty: "OKP", crv: "Ed25519", x: key.x }, format: "jwk" }),
      Buffer.from(signature ?? "", "base64url"),
    ),
  ).toBe(true);
  const [call] = exchangeCalls();
  expect(call?.authorization).toMatch(/^Bearer space-token-/);

  // The token is cached: the next call asks for none.
  await submitPassword();
  expect(calls.some((entry) => entry.path === "/v1/auth/oauth2/token")).toBe(false);
  expect(exchangeCalls()[0]?.authorization).toMatch(/^Bearer space-token-/);
});

test("a control plane rolled back to an older image still admits the box", async () => {
  // The box still holds a route config naming its key, but the image now
  // serving never reads the token. The older credential rides beside it.
  const key = await boxKey();
  await activate(key.kid);
  exchangeAcceptsToken = false;
  try {
    const response = await submitPassword();
    expect(response.status).toBe(303);
    const [call] = exchangeCalls();
    expect(call?.legacy).toBe(EXCHANGE_CREDENTIAL);
    // A wrong password is the answer, not "unknown target": the box was admitted.
    expect(response.headers.get("location") ?? "").not.toContain("exchange-unavailable");
  } finally {
    exchangeAcceptsToken = true;
  }
});

test("a token endpoint that refuses the Space client leaves the box on its older credential", async () => {
  const key = await boxKey();
  await activate(key.kid);
  // Drop the cached token, as after its five minutes.
  await Bun.write(path.join(spaceRoot(runtime, SPACE), "identity", "token.json"), "{}\n");
  tokenEndpoint = "absent";
  try {
    const response = await submitPassword();
    expect(response.status).toBe(303);
    const [call] = exchangeCalls();
    expect(call?.authorization).toBeNull();
    expect(call?.legacy).toBe(EXCHANGE_CREDENTIAL);
    // The refusal is remembered: the next visit does not ask again at once.
    await submitPassword();
    expect(calls.some((entry) => entry.path === "/v1/auth/oauth2/token")).toBe(false);
  } finally {
    tokenEndpoint = "issue";
  }
});

test("a rate-limited renewal keeps the box on its current token", async () => {
  const key = await boxKey();
  await activate(key.kid);
  const tokenFile = path.join(spaceRoot(runtime, SPACE), "identity", "token.json");
  // A token inside its renewal window, still valid for another half minute.
  await Bun.write(
    tokenFile,
    JSON.stringify({
      token: "space-token-current",
      expires_at: Math.floor(Date.now() / 1000) + 30,
      kid: key.kid,
      client_id: CLIENT_ID,
      scope: SCOPE,
    }) + "\n",
  );
  tokenEndpoint = "limited";
  try {
    await submitPassword();
    expect(calls.some((entry) => entry.path === "/v1/auth/oauth2/token")).toBe(true);
    // The control plane may have retired the older credential for this Space:
    // the box keeps presenting the token it has.
    expect(exchangeCalls()[0]?.authorization).toBe("Bearer space-token-current");
    // It waits as told before asking again.
    await submitPassword();
    expect(calls.some((entry) => entry.path === "/v1/auth/oauth2/token")).toBe(false);
    expect(exchangeCalls()[0]?.authorization).toBe("Bearer space-token-current");
  } finally {
    tokenEndpoint = "issue";
    await Bun.write(tokenFile, "{}\n");
  }
});

test("a route config naming another key keeps the older credential", async () => {
  await activate("a-key-this-box-never-made");
  const response = await submitPassword();
  expect(response.status).toBe(303);
  expect(calls.some((call) => call.path === "/v1/auth/oauth2/token")).toBe(false);
  expect(exchangeCalls()[0]?.authorization).toBeNull();
  expect(exchangeCalls()[0]?.legacy).toBe(EXCHANGE_CREDENTIAL);
});

test("a Worker dispatch carries the Space token once the route config grants the Functions scope", async () => {
  // A TLS stand-in for the execution host, which records what each dispatch
  // carried. The host itself never verifies the token: it forwards it on its
  // admission check, where the control plane does (packages/functions-host).
  const { certificate, key: tlsKey } = selfSignedCertificate(runtime.root, "space-identity-edge");
  const dispatches: Array<{ spaceToken: string | null; usageToken: string | null }> = [];
  const edge = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    tls: { cert: Bun.file(certificate), key: Bun.file(tlsKey) },
    fetch(request) {
      dispatches.push({
        spaceToken: request.headers.get("sf-fx-space-token"),
        usageToken: request.headers.get("sf-fx-usage-token"),
      });
      return new Response("worker", { headers: { "content-type": "text/plain" } });
    },
  });
  const edgeRuntime = runtime;
  runtime = await startRuntime({
    visitorScheme: "https",
    env: {
      SPACEFAST_INSECURE_COOKIES: "",
      SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fx-dispatch-token",
    },
    phpIni: { "curl.cainfo": certificate },
  });
  const functions: FunctionsFixture = {
    artifact: {
      appName: "space-identity-worker",
      entry: "worker.js",
      mainModule: "index.js",
      compatibilityDate: "2026-07-01",
      compatibilityFlags: [],
      routes: [{ method: null, path: "/", subtree: true }],
    },
    host: { hostname: `127.0.0.1:${edge.port}`, bundleUrl: "https://bundle.invalid/bundle.json" },
    grantedCapabilities: [],
    usage: { url: `${origin}/v1/runtime/functions/usage`, token: "fx-usage-token" },
  };
  try {
    // No key named: the dispatch carries the version's token and nothing new.
    await activate(null, functions);
    expect((await get(runtime, HOST, "/api/hello")).status).toBe(200);
    expect(dispatches.at(-1)).toEqual({ spaceToken: null, usageToken: "fx-usage-token" });

    // The key named, with the Functions scope: the box asks for its token and sends it.
    const key = await boxKey();
    await activate(key.kid, functions);
    calls.length = 0;
    expect((await get(runtime, HOST, "/api/hello")).status).toBe(200);
    const tokenCall = calls.find((call) => call.path === "/v1/auth/oauth2/token");
    expect(tokenCall?.form.get("scope")).toBe(SCOPE);
    expect(dispatches.at(-1)?.spaceToken).toMatch(/^space-token-/);
    expect(dispatches.at(-1)?.usageToken).toBe("fx-usage-token");
  } finally {
    runtime.stop();
    runtime = edgeRuntime;
    edge.stop(true);
  }
});

test("the box keeps its one key across route syncs and serves only its public half", async () => {
  const stored = path.join(spaceRoot(runtime, SPACE), "identity", "key.json");
  const before = await boxKey();
  const secret = z
    .object({ secret: z.string() })
    .parse(JSON.parse(readFileSync(stored, "utf8"))).secret;
  await activate(before.kid);
  await activate(before.kid);
  // Nothing rotates on a schedule: the same key, and the box still signs with it.
  expect(await boxKeys()).toEqual({ jwk: before });
  expect(
    z.object({ secret: z.string() }).parse(JSON.parse(readFileSync(stored, "utf8"))).secret,
  ).toBe(secret);
  await Bun.write(path.join(spaceRoot(runtime, SPACE), "identity", "token.json"), "{}\n");
  await submitPassword();
  const [header, payload, signature] = lastAssertion.split(".");
  expect(JSON.parse(Buffer.from(header ?? "", "base64url").toString("utf8")).kid).toBe(before.kid);
  expect(
    verify(
      null,
      Buffer.from(`${header}.${payload}`),
      createPublicKey({ key: { kty: "OKP", crv: "Ed25519", x: before.x }, format: "jwk" }),
      Buffer.from(signature ?? "", "base64url"),
    ),
  ).toBe(true);
});
