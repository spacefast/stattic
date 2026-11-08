// A Space's connector roles, end to end through the real serve path: a page's
// `fetch("/__spacefast/connectors/<role>/<tool>")`, the visitor account routes
// beside it, and the broker credential the Zero runner and Functions relay read.
// The control plane is a loopback stand-in that records what reached it, so each
// test asserts what the runtime forwarded and what the browser got back.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";

import { z } from "zod";

import {
  RUNTIME_ACCESS_SESSION_PATH,
  RUNTIME_COLLAB_MANIFEST_PATH,
  RUNTIME_SPACE_CLIENT_CHUNKS_PREFIX,
  RUNTIME_SPACE_CLIENT_PATH,
  RUNTIME_SPACE_CLIENT_TYPES_PATH,
} from "../../packages/common/src/utils/runtime-paths.ts";
import {
  deploy,
  get,
  PHP_BINARY,
  postAccessCallback,
  publicAccessConfig,
  putRoute,
  type Runtime,
  signEd25519Jwt,
  startRuntime,
  versionRoot,
  visitorIssuer,
} from "./harness.ts";

const HOST = "connectors.test";
const SPACE_ID = "spc_connectors";
const VERSION_ID = "ver_connectors_1";
const TOKEN = "ver_connectors_1.version-connectors-token";
const TYPES =
  'import type { SpaceClient } from "@spacefast/client";\nexport declare const space: SpaceClient<{ crm: {} }>;\n';
const LEGACY_TOKEN = "ver_connectors_2.legacy-zero-config-token";
const EXCHANGE_CREDENTIAL = "runtime-connectors-exchange-credential-0123456789";

// What the runtime sends upstream: a call frame or a visitor-connection frame.
const forwardedBodySchema = z.looseObject({
  role: z.string(),
  visitor: z.object({ subject: z.string() }).nullable().optional(),
});
type Forwarded = {
  path: string;
  authorization: string | null;
  body: z.infer<typeof forwardedBodySchema>;
};
const forwarded: Forwarded[] = [];
// What the stand-in answers next: by role for calls, `visitor:<role>` for the
// visitor-connection frames.
const answers: Record<string, () => Response> = {};

const controlPlane = Bun.serve({
  hostname: "127.0.0.1",
  port: 0,
  async fetch(request) {
    const body = forwardedBodySchema.parse(await request.json());
    const url = new URL(request.url);
    forwarded.push({
      path: url.pathname,
      authorization: request.headers.get("authorization"),
      body,
    });
    const visitorAnswer = url.pathname.includes("/visitor-connections/")
      ? answers[`visitor:${body.role}`]?.()
      : undefined;
    if (visitorAnswer) return visitorAnswer;
    if (url.pathname.endsWith("/visitor-connections/start")) {
      return Response.json({ data: { authorizeUrl: "https://provider.example/authorize?x=1" } });
    }
    if (url.pathname.endsWith("/visitor-connections/complete")) {
      return Response.json({ data: { returnTo: `https://${HOST}/dashboard?tab=crm` } });
    }
    if (url.pathname.endsWith("/visitor-connections/revoke")) {
      return Response.json({ data: null });
    }
    const role = body.role;
    // By default it answers the way a control plane older than shared reads
    // does: the result only on the run.
    return (
      answers[role]?.() ??
      Response.json({
        state: { role, code: "ok", message: "Connected." },
        run: { status: "succeeded", result: { deals: [{ id: "deal_1", stage: "won" }] } },
      })
    );
  },
});

const visitorKey = generateKeyPairSync("ed25519");
const visitor = visitorIssuer(visitorKey.publicKey);
const visitorJwk = visitorKey.publicKey.export({ format: "jwk" });

/**
 * The Space's route config: public pages, a members-only `/private`, and the
 * `crm` role's data members-only too. Connector data follows the same Grants as
 * every other path.
 */
function routeConfig() {
  const config = publicAccessConfig({ mode: "website", site_title: "Connectors" });
  return {
    ...config,
    visitor_issuer: "spacefast-api",
    visitor_jwks: {
      keys: [
        { kty: "OKP", crv: "Ed25519", kid: visitor.kid, alg: "EdDSA", use: "sig", x: visitorJwk.x },
      ],
    },
    session_version: 0,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/connectors",
      spaceClaimed: true,
      accessPage: {
        displayName: "Connectors",
        accountUrl: "https://api.spacefast.test/v1/access/acquire/connectors",
        connections: [],
        exchange: {
          passwordUrl: "https://api.spacefast.test/v1/access/acquire/connectors/password",
          tokenUrl: "https://api.spacefast.test/v1/access/acquire/connectors/token",
          requestUrl: "https://api.spacefast.test/v1/access/acquire/connectors/request",
          credential: EXCHANGE_CREDENTIAL,
        },
      },
      grants: [
        {
          id: "grt_connectors_public",
          generation: 1,
          name: "Public",
          audience: { kind: "public" },
          resources: {
            include: ["/**"],
            exclude: ["/private/**", "/__spacefast/connectors/crm/**"],
          },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:connectors-public" },
        },
        {
          id: "grt_connectors_member",
          generation: 1,
          audience: { kind: "external", issuer: "spacefast-membership", subject: "mem_crm" },
          resources: { include: ["/**"], exclude: [] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "system", reference: "test:connectors-member" },
        },
      ],
    },
  };
}

let rt: Runtime;
let memberCookie = "";

beforeAll(async () => {
  rt = await startRuntime({ atomicData: { SPACEFAST_API_BASE_URL: controlPlane.url.toString() } });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: VERSION_ID,
    metadata: { mode: "website", title: "Connectors" },
    files: { "index.html": "<h1>deals</h1>\n", "private/index.html": "<h1>private</h1>\n" },
    finalize: { connectors_token: TOKEN, connectors_types: TYPES },
    activate: {
      route_name: "production",
      config: routeConfig(),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const now = Math.floor(Date.now() / 1000);
  const handoff = await postAccessCallback(
    rt,
    HOST,
    signEd25519Jwt(visitorKey.privateKey, visitor.kid, {
      sub: "member:mem_crm",
      purpose: "handoff",
      grants: ["member:mem_crm"],
      authorities: ["member:mem_crm"],
      iss: "spacefast-api",
      aud: SPACE_ID,
      host: HOST,
      sv: 0,
      generation: 1,
      spaceId: SPACE_ID,
      principal: "account:usr_crm",
      sid: createHash("sha256").update(randomUUID()).digest("hex"),
      iat: now,
      nbf: now,
      exp: now + 3600,
      jti: randomUUID(),
    }),
  );
  expect(handoff.status).toBe(303);
  memberCookie = (handoff.headers.get("set-cookie") ?? "").split(";", 1)[0] ?? "";
  expect(memberCookie).toContain("sfv2_");
}, 120_000);

afterAll(() => {
  rt?.stop();
  controlPlane.stop(true);
});

/** A request from the Space's own page: its cookie and same-origin fetch metadata. */
function call(requestPath: string, init: Parameters<typeof get>[3] = {}) {
  forwarded.length = 0;
  return get(rt, HOST, requestPath, {
    ...init,
    headers: { cookie: memberCookie, "sec-fetch-site": "same-origin", ...init.headers },
  });
}

test("finalize keeps the credential and the types beside the version, not in it", () => {
  const root = versionRoot(rt, SPACE_ID, VERSION_ID);
  expect(readFileSync(path.join(root, "connectors/token"), "utf8")).toBe(TOKEN);
  expect(readFileSync(path.join(root, "space-client.d.ts"), "utf8")).toBe(TYPES);
});

test("the version's types are served as long as its data would be", async () => {
  const types = await call(RUNTIME_SPACE_CLIENT_TYPES_PATH);
  expect(types.status).toBe(200);
  expect(types.headers.get("content-type")).toBe("application/typescript; charset=utf-8");
  expect(types.headers.get("cache-control")).toBe("private, no-store");
  expect(await types.text()).toBe(TYPES);
  // The declaration is an artifact, so nothing reaches the control plane.
  expect(forwarded).toEqual([]);
  expect((await call(RUNTIME_SPACE_CLIENT_TYPES_PATH, { method: "POST" })).status).toBe(405);
});

test("the public Space client serves executable Access, connector and collaboration modules", async () => {
  // Public for anyone, member or not: it holds no Space's data.
  const client = await get(rt, HOST, RUNTIME_SPACE_CLIENT_PATH);
  expect(client.status).toBe(200);
  expect(client.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
  expect(client.headers.get("cache-control")).toBe("public, max-age=0, must-revalidate");
  const body = await client.text();
  const etag = client.headers.get("etag") ?? "";
  expect(etag).toMatch(/^"[a-f0-9]{64}"$/);
  const revalidated = await get(rt, HOST, RUNTIME_SPACE_CLIENT_PATH, {
    headers: { "if-none-match": etag },
  });
  expect(revalidated.status).toBe(304);
  const head = await get(rt, HOST, RUNTIME_SPACE_CLIENT_PATH, { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("etag")).toBe(etag);
  expect(await head.text()).toBe("");
  const post = await get(rt, HOST, RUNTIME_SPACE_CLIENT_PATH, { method: "POST" });
  expect(post.status).toBe(405);
  expect(post.headers.get("allow")).toBe("GET, HEAD");
  const nested = await get(rt, HOST, `${RUNTIME_SPACE_CLIENT_CHUNKS_PREFIX}nested/client.js`);
  expect(nested.status).toBe(404);
  expect(await nested.json()).toMatchObject({ code: "not_found" });

  // Import the actual modules the runtime returned, including the chunks used
  // by collaboration. Relative module imports resolve as they do on the page.
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-client-http-"));
  try {
    writeFileSync(path.join(root, "client.mjs"), body);
    mkdirSync(path.join(root, "chunks"));
    const chunkNames = readdirSync(
      path.join(import.meta.dir, "../engine/runtime/space-client/chunks"),
    );
    expect(chunkNames.length).toBeGreaterThan(0);
    await Promise.all(
      chunkNames.map(async (filename) => {
        const chunk = await get(rt, HOST, `${RUNTIME_SPACE_CLIENT_CHUNKS_PREFIX}${filename}`);
        expect(chunk.status).toBe(200);
        expect(chunk.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
        expect(chunk.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
        writeFileSync(path.join(root, "chunks", filename), await chunk.text());
      }),
    );
    const module = await import(pathToFileURL(path.join(root, "client.mjs")).href);
    const manifest = await get(rt, HOST, RUNTIME_COLLAB_MANIFEST_PATH);
    expect(manifest.status).toBe(200);
    const requested: string[] = [];
    const space = module.createSpaceClient({
      connectors: { storage: null },
      collaboration: {
        manifestUrl: `data:application/json;base64,${Buffer.from(await manifest.text()).toString("base64")}`,
      },
      fetch: async (input: string) => {
        requested.push(input);
        return get(rt, HOST, input, { headers: { cookie: memberCookie } });
      },
    });
    try {
      expect(await space.connectors.crm["deals.list"]({ limit: 5 })).toEqual({
        state: "ok",
        data: { deals: [{ id: "deal_1", stage: "won" }] },
      });
      expect(await space.access.getSession()).toEqual({
        state: "authenticated",
        principal: "account:usr_crm",
        displayName: null,
      });
      await expect(space.collaboration.connect()).rejects.toThrow(/no room to join/i);
      expect(requested).toEqual([
        RUNTIME_ACCESS_SESSION_PATH,
        `/__spacefast/connectors/crm/deals.list?__args=${encodeURIComponent('{"limit":5}')}`,
        RUNTIME_ACCESS_SESSION_PATH,
      ]);
    } finally {
      space.destroy();
      module.space.destroy();
    }
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("a tool's result reaches the page as the provider's payload, with its HTTP response", async () => {
  answers.crm = () =>
    Response.json({
      state: { role: "crm", code: "ok", message: "Connected." },
      result: {
        ok: true,
        data: { deals: [] },
        http: { status: 200, headers: { link: '<https://api.example/deals?page=2>; rel="next"' } },
      },
    });
  try {
    expect(await (await call("/__spacefast/connectors/crm/deals.list")).json()).toEqual({
      data: { deals: [] },
      http: { status: 200, headers: { link: '<https://api.example/deals?page=2>; rel="next"' } },
      state: "ok",
    });
  } finally {
    delete answers.crm;
  }
});

test("a GET from the Space's pages reads with query args, typed __args, the page surface and the viewer", async () => {
  const response = await call(
    `/__spacefast/connectors/crm/deals.list?stage=won&limit=5&__args=${encodeURIComponent(
      JSON.stringify({ limit: 5, open: false }),
    )}`,
    { headers: { "idempotency-key": "page-load-1", "x-spacefast-visitor": "account:forged" } },
  );
  expect(response.status, await response.clone().text()).toBe(200);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(await response.json()).toEqual({
    data: { deals: [{ id: "deal_1", stage: "won" }] },
    state: "ok",
  });
  expect(forwarded).toEqual([
    {
      path: "/v1/runtime/connectors/calls",
      authorization: `Bearer ${TOKEN}`,
      body: {
        surface: "page",
        role: "crm",
        tool: "deals.list",
        args: { stage: "won", limit: 5, open: false },
        // The access principal the session proved, never a header.
        visitor: { subject: "account:usr_crm" },
        handler: { name: "page", mode: "read" },
        acceptsSharedReads: true,
        requestId: "page-load-1",
      },
    },
  ]);

  // Another site's page reading with the viewer's cookie is refused and sent
  // to the API. The `inbox` role's data is public, so the access check admits
  // these and the origin check is what refuses them. A GET with no fetch
  // metadata at all (older Safari) still reads.
  for (const headers of [
    { "sec-fetch-site": "cross-site" },
    { "sec-fetch-site": "same-site" },
    { origin: "https://elsewhere.example", "sec-fetch-site": "" },
  ]) {
    const refused = await call("/__spacefast/connectors/inbox/messages.list", { headers });
    expect(refused.status).toBe(403);
    expect(refused.headers.get("content-type")).toContain("application/problem+json");
    expect(await refused.json()).toMatchObject({
      code: "browser_origin_not_allowed",
      detail: "Call this Space's connectors from its own pages. Elsewhere, use the Spacefast API.",
    });
    expect(forwarded).toEqual([]);
  }
  const bare = await call("/__spacefast/connectors/inbox/messages.list", {
    headers: { "sec-fetch-site": "" },
  });
  expect(bare.status, await bare.clone().text()).toBe(200);
});

test("a POST writes from the Space's own pages only, up to 64 KiB", async () => {
  const origin = `http://${HOST}`;
  const sameOrigin = await call("/__spacefast/connectors/crm/deals.create", {
    method: "POST",
    headers: { origin, "content-type": "application/json" },
    body: JSON.stringify({ title: "New deal", amount: 10 }),
  });
  expect(sameOrigin.status, await sameOrigin.clone().text()).toBe(200);
  expect(forwarded[0]?.body).toMatchObject({
    args: { title: "New deal", amount: 10 },
    handler: { name: "page", mode: "write" },
  });

  // The `inbox` role's data is public, so the access check admits these and
  // the origin check is what refuses them.
  const crossOrigin: Array<{ origin?: string; "sec-fetch-site": string }> = [
    { origin: "https://elsewhere.example", "sec-fetch-site": "" },
    { origin, "sec-fetch-site": "same-site" },
    { origin, "sec-fetch-site": "cross-site" },
    { "sec-fetch-site": "cross-site" },
    // A write proves its origin; one with no headers at all is refused.
    { "sec-fetch-site": "" },
  ];
  for (const headers of crossOrigin) {
    const refused = await call("/__spacefast/connectors/inbox/messages.send", {
      method: "POST",
      headers: { ...headers, "content-type": "application/json" },
      body: "{}",
    });
    expect(refused.status).toBe(403);
    expect(refused.headers.get("content-type")).toContain("application/problem+json");
    expect(await refused.json()).toMatchObject({ code: "browser_origin_not_allowed" });
    expect(forwarded).toEqual([]);
  }
  expect(
    (
      await call("/__spacefast/connectors/inbox/messages.send", {
        method: "POST",
        headers: { "sec-fetch-site": "same-origin", "content-type": "application/json" },
        body: "{}",
      })
    ).status,
  ).toBe(200);

  const tooLarge = await call("/__spacefast/connectors/crm/deals.create", {
    method: "POST",
    headers: { origin, "content-type": "application/json" },
    body: JSON.stringify({ note: "x".repeat(64 * 1024) }),
  });
  expect(tooLarge.status).toBe(413);
  expect(await tooLarge.json()).toMatchObject({ code: "runtime_request_body_too_large" });
  expect(forwarded).toEqual([]);
});

test("data is exactly as private as its Grants: a visitor they refuse reaches nothing", async () => {
  forwarded.length = 0;
  const anonymous = await get(rt, HOST, "/__spacefast/connectors/crm/deals.list", {
    headers: { accept: "application/json" },
  });
  expect(anonymous.status).toBe(403);
  expect(anonymous.headers.get("cache-control")).toContain("no-store");
  expect(forwarded).toEqual([]);
  // The same anonymous visitor reads the public page, and calls a public role.
  expect((await get(rt, HOST, "/")).status).toBe(200);
  const open = await get(rt, HOST, "/__spacefast/connectors/inbox/messages.list");
  expect(open.status).toBe(200);
  expect(forwarded.map((entry) => entry.body.visitor)).toEqual([null]);
});

test("call states reach the page as data, with the connect link", async () => {
  // A shared read answers from the cache: no run, the result and its age only.
  answers.inbox = () =>
    Response.json({
      state: {
        role: "inbox",
        code: "ok",
        message: "Connected.",
        cachedAt: "2026-11-01T10:00:00.000Z",
      },
      result: { messages: [] },
    });
  expect(await (await call("/__spacefast/connectors/inbox/messages.list")).json()).toEqual({
    data: { messages: [] },
    state: "ok",
    cachedAt: "2026-11-01T10:00:00.000Z",
  });
  answers.inbox = () =>
    Response.json({
      state: {
        role: "inbox",
        code: "connect_required",
        message: "Connect your account to continue.",
        connectUrl: "/__spacefast/connectors/inbox/connect",
      },
    });
  answers.board = () =>
    Response.json({
      state: {
        role: "board",
        code: "approval_pending",
        pendingReason: "running",
        message: "Still working on it. Try again in a moment.",
        runId: "cxr_1",
      },
    });
  const connect = await call("/__spacefast/connectors/inbox/messages.list");
  expect(connect.status).toBe(200);
  expect(connect.headers.get("cache-control")).toBe("private, no-store");
  expect(await connect.json()).toEqual({
    data: null,
    state: "connect_required",
    message: "Connect your account to continue.",
    connectUrl: "/__spacefast/connectors/inbox/connect",
  });
  expect(await (await call("/__spacefast/connectors/board/items.list")).json()).toEqual({
    data: null,
    state: "running",
    message: "Still working on it. Try again in a moment.",
  });
});

test("control-plane problems pass through with their code; unknown roles are 404", async () => {
  answers.narrow = () =>
    Response.json(
      {
        type: "https://spacefast.com/docs/errors/connector_tool_not_allowed",
        title: "Connector tool not allowed",
        status: 403,
        detail: "The narrow role does not allow deals.delete.",
        code: "connector_tool_not_allowed",
      },
      { status: 403, headers: { "content-type": "application/problem+json" } },
    );
  answers.missing = () =>
    Response.json(
      { status: 404, code: "connector_role_unknown", detail: "No missing role." },
      { status: 404, headers: { "content-type": "application/problem+json" } },
    );
  const refused = await call("/__spacefast/connectors/narrow/deals.delete");
  expect(refused.status).toBe(403);
  expect(refused.headers.get("content-type")).toContain("application/problem+json");
  expect(refused.headers.get("cache-control")).toBe("private, no-store");
  expect(await refused.json()).toMatchObject({
    code: "connector_tool_not_allowed",
    detail: "The narrow role does not allow deals.delete.",
  });
  const unknown = await call("/__spacefast/connectors/missing/deals.list");
  expect(unknown.status).toBe(404);
  expect(await unknown.json()).toMatchObject({ code: "connector_role_unknown" });
  // The visitor routes relay the control plane's verdict the same way, not as
  // an outage.
  answers["visitor:missing"] = () =>
    Response.json(
      {
        status: 422,
        code: "connector_role_unavailable",
        detail: "The site owner needs to choose a connector for this role.",
      },
      { status: 422, headers: { "content-type": "application/problem+json" } },
    );
  const unknownConnect = await call("/__spacefast/connectors/missing/connect");
  expect(unknownConnect.status).toBe(422);
  expect(unknownConnect.headers.get("cache-control")).toBe("private, no-store");
  expect(await unknownConnect.json()).toMatchObject({ code: "connector_role_unavailable" });
  // Not a role name at all: refused before anything is forwarded.
  const malformed = await call("/__spacefast/connectors/Bad-Role/deals.list");
  expect(malformed.status).toBe(403);
  expect(forwarded).toEqual([]);
});

test("visitor accounts connect, complete and disconnect under the role's own path", async () => {
  // Signed out: sign in first, then come back to the same connect.
  const signedOut = await get(
    rt,
    HOST,
    "/__spacefast/connectors/inbox/connect?return_to=%2Fdashboard",
  );
  expect(signedOut.status).toBe(302);
  expect(signedOut.headers.get("cache-control")).toContain("no-store");
  const signIn = new URL(signedOut.headers.get("location") ?? "", `http://${HOST}`);
  expect(signIn.pathname).toBe("/__spacefast/access/account");
  expect(signIn.searchParams.get("return")).toBe(
    "/__spacefast/connectors/inbox/connect?return_to=%2Fdashboard",
  );
  const expiredCallback = await get(
    rt,
    HOST,
    "/__spacefast/connectors/inbox/callback?code=c&state=s",
  );
  expect(
    new URL(expiredCallback.headers.get("location") ?? "", `http://${HOST}`).searchParams.get(
      "return",
    ),
  ).toBe("/__spacefast/connectors/inbox/connect");

  const connect = await call("/__spacefast/connectors/inbox/connect?return_to=%2Fdashboard");
  expect(connect.status).toBe(302);
  expect(connect.headers.get("location")).toBe("https://provider.example/authorize?x=1");
  expect(forwarded).toEqual([
    {
      path: "/v1/runtime/connectors/visitor-connections/start",
      authorization: `Bearer ${TOKEN}`,
      body: {
        role: "inbox",
        visitorSubject: "account:usr_crm",
        origin: `https://${HOST}`,
        returnTo: "/dashboard",
      },
    },
  ]);

  // The provider sends the viewer back cross-site; the sealed state, not the
  // origin, protects the callback.
  const callback = await call("/__spacefast/connectors/inbox/callback?code=c0de&state=st4te", {
    headers: { "sec-fetch-site": "cross-site" },
  });
  expect(callback.status).toBe(200);
  expect(callback.headers.get("cache-control")).toBe("private, no-store");
  expect(forwarded[0]).toMatchObject({
    path: "/v1/runtime/connectors/visitor-connections/complete",
    body: { role: "inbox", visitorSubject: "account:usr_crm", code: "c0de", state: "st4te" },
  });
  const page = await callback.text();
  expect(page).toContain('"type":"spacefast:connector-connected"');
  expect(page).toContain("/dashboard?tab=crm");

  const crossOrigin = await call("/__spacefast/connectors/inbox/disconnect", {
    method: "POST",
    headers: { origin: "https://elsewhere.example" },
  });
  expect(crossOrigin.status).toBe(403);
  expect(forwarded).toEqual([]);
  const disconnect = await call("/__spacefast/connectors/inbox/disconnect", {
    method: "POST",
    headers: { origin: `http://${HOST}` },
  });
  expect(disconnect.status).toBe(200);
  expect(await disconnect.json()).toEqual({ data: null });
  expect(forwarded).toEqual([
    {
      path: "/v1/runtime/connectors/visitor-connections/revoke",
      authorization: `Bearer ${TOKEN}`,
      body: { role: "inbox", visitorSubject: "account:usr_crm" },
    },
  ]);
  // The operation names are reserved: they are never called as tools.
  expect((await call("/__spacefast/connectors/inbox/connect", { method: "POST" })).status).toBe(
    405,
  );

  // Legacy Zero 0.8: delete when no live version is Zero 0.8. A capsule built
  // against that client opens and disconnects under the old prefix, and its
  // disconnect() is a DELETE on the bare role.
  const legacyConnect = await call("/__spacefast/zero/connectors/inbox/connect?return_to=%2Fa");
  expect(legacyConnect.status).toBe(302);
  expect(legacyConnect.headers.get("location")).toBe("https://provider.example/authorize?x=1");
  expect(forwarded[0]).toMatchObject({
    path: "/v1/runtime/connectors/visitor-connections/start",
    body: { role: "inbox", visitorSubject: "account:usr_crm", returnTo: "/a" },
  });
  const legacyCallback = await call(
    "/__spacefast/zero/connectors/inbox/callback?code=c0de&state=st4te",
  );
  expect(legacyCallback.status).toBe(200);
  expect(forwarded[0]?.path).toBe("/v1/runtime/connectors/visitor-connections/complete");
  for (const [legacyPath, method] of [
    ["/__spacefast/zero/connectors/inbox", "DELETE"],
    ["/__spacefast/zero/connectors/inbox/disconnect", "POST"],
  ] as const) {
    const legacyDisconnect = await call(legacyPath, {
      method,
      headers: { origin: `http://${HOST}` },
    });
    expect(legacyDisconnect.status, legacyPath).toBe(200);
    expect(forwarded).toEqual([
      {
        path: "/v1/runtime/connectors/visitor-connections/revoke",
        authorization: `Bearer ${TOKEN}`,
        body: { role: "inbox", visitorSubject: "account:usr_crm" },
      },
    ]);
  }
  // A signed-out viewer comes back to the new spelling of the same route.
  const legacySignedOut = await get(rt, HOST, "/__spacefast/zero/connectors/inbox/connect");
  expect(
    new URL(legacySignedOut.headers.get("location") ?? "", `http://${HOST}`).searchParams.get(
      "return",
    ),
  ).toBe("/__spacefast/connectors/inbox/connect");
  // The old prefix only ever carried the visitor account routes.
  expect((await call("/__spacefast/zero/connectors/inbox/messages.list")).status).toBe(403);
});

test("a version with no roles has no credential and answers every role as unknown", async () => {
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: "ver_connectors_2",
    metadata: { mode: "website", title: "Connectors" },
    files: { "index.html": "<h1>no roles</h1>\n" },
  });
  await putRoute(rt, SPACE_ID, "production", {
    version_id: "ver_connectors_2",
    config: routeConfig(),
  });
  try {
    const response = await call("/__spacefast/connectors/crm/deals.list");
    expect(response.status).toBe(404);
    expect(await response.json()).toMatchObject({ code: "connector_role_unknown" });
    expect(forwarded).toEqual([]);
    const types = await call(RUNTIME_SPACE_CLIENT_TYPES_PATH);
    expect(types.status).toBe(404);
    expect(await types.json()).toMatchObject({ code: "not_found" });

    // Legacy Zero 0.8: delete when no live version is Zero 0.8. A version an
    // older finalize wrote keeps its credential in the capsule config only.
    const legacyRoot = versionRoot(rt, SPACE_ID, "ver_connectors_2");
    mkdirSync(path.join(legacyRoot, "zero"), { recursive: true });
    writeFileSync(
      path.join(legacyRoot, "zero/config.json"),
      JSON.stringify({ runtimeKind: "zero", connectors: { token: LEGACY_TOKEN } }),
    );
    const legacy = await call("/__spacefast/connectors/crm/deals.list");
    expect(legacy.status, await legacy.clone().text()).toBe(200);
    expect(forwarded[0]?.authorization).toBe(`Bearer ${LEGACY_TOKEN}`);

    // A control plane from before the shared credential sends a capsule's
    // credential only in the Zero config; this engine keeps it beside the
    // version and serves the roles with it.
    const oldControlPlaneToken = "ver_connectors_3.zero-config-only-token";
    await deploy(rt, {
      spaceId: SPACE_ID,
      versionId: "ver_connectors_3",
      metadata: { mode: "website", title: "Connectors" },
      files: { "index.html": "<h1>old control plane</h1>\n" },
      finalize: { zero: { connectors: { token: oldControlPlaneToken } } },
    });
    await putRoute(rt, SPACE_ID, "production", {
      version_id: "ver_connectors_3",
      config: routeConfig(),
    });
    expect(
      readFileSync(
        path.join(versionRoot(rt, SPACE_ID, "ver_connectors_3"), "connectors/token"),
        "utf8",
      ),
    ).toBe(oldControlPlaneToken);
    const fromOldControlPlane = await call("/__spacefast/connectors/crm/deals.list");
    expect(fromOldControlPlane.status, await fromOldControlPlane.clone().text()).toBe(200);
    expect(forwarded[0]?.authorization).toBe(`Bearer ${oldControlPlaneToken}`);
  } finally {
    await putRoute(rt, SPACE_ID, "production", { version_id: VERSION_ID, config: routeConfig() });
  }
});

test("a call takes a slot from the Space's admission budget before it waits upstream", async () => {
  await putRoute(rt, SPACE_ID, "production", {
    version_id: VERSION_ID,
    config: { ...routeConfig(), admission: { concurrency: 2 } },
  });
  // The Space's counter, full: the engine's own generation layout.
  const counter = path.join(rt.storageRoot, "runtime/admission", `${SPACE_ID}.json`);
  const generation = randomUUID().replaceAll("-", "");
  const now = Math.floor(Date.now() / 1000);
  mkdirSync(path.dirname(counter), { recursive: true });
  writeFileSync(
    `${counter}.${generation}`,
    JSON.stringify({ count: 2, started_at: now, updated_at: now }),
  );
  writeFileSync(`${counter}.generation`, JSON.stringify({ generation }));
  try {
    forwarded.length = 0;
    // An anonymous read of the public page costs no slot; its public data does.
    expect((await get(rt, HOST, "/")).status).toBe(200);
    const shed = await get(rt, HOST, "/__spacefast/connectors/inbox/messages.list");
    expect(shed.status).toBe(429);
    expect(shed.headers.get("retry-after")).toBe("2");
    expect(forwarded).toEqual([]);
  } finally {
    rmSync(`${counter}.${generation}`, { force: true });
    rmSync(`${counter}.generation`, { force: true });
    await putRoute(rt, SPACE_ID, "production", { version_id: VERSION_ID, config: routeConfig() });
  }
});

test("on a Space with Users on, the viewer is the Users account a Zero handler sees", async () => {
  const users = {
    enabled: true,
    providers: {
      google: { mode: "disabled" },
      gravatar: { enabled: false },
      spacefast: { enabled: true },
    },
  };
  await putRoute(rt, SPACE_ID, "production", {
    version_id: VERSION_ID,
    config: { ...routeConfig(), users },
  });
  try {
    // The access session is not the app's identity: the member still signs in
    // through the Space's own Users sign-in, and returns to connect.
    const signedOut = await call("/__spacefast/connectors/inbox/connect");
    expect(signedOut.status).toBe(302);
    const signIn = new URL(signedOut.headers.get("location") ?? "", `http://${HOST}`);
    expect(signIn.pathname).toBe("/__zero/auth/start");
    expect(signIn.searchParams.get("provider")).toBe("spacefast");
    expect(signIn.searchParams.get("returnTo")).toBe("/__spacefast/connectors/inbox/connect");
    expect(forwarded).toEqual([]);
  } finally {
    await putRoute(rt, SPACE_ID, "production", { version_id: VERSION_ID, config: routeConfig() });
  }

  // A verified Users session: the page route and the Zero broker name the same
  // subject, and an access principal alone names nobody.
  const probe = Bun.spawnSync([
    PHP_BINARY,
    "-r",
    `require $argv[1]; require $argv[2];
    $serving = ['space_id' => $argv[3], 'users' => json_decode($argv[4], true)];
    $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'] = ['userId' => $argv[5], 'provider' => 'space-users', 'isAuthenticated' => true, 'isGuest' => false];
    $page = _stattic_connectors_visitor($serving, 'users.test');
    $zero = _stattic_zero_service_identity(['auth' => _stattic_zero_auth_context($serving, 'users.test')]);
    $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'] = null;
    echo json_encode(['page' => $page, 'zero' => $zero['visitor'], 'signedOut' => _stattic_connectors_visitor($serving, 'users.test')]);`,
    path.join(rt.engineRoot, "runtime/zero.php"),
    path.join(rt.engineRoot, "runtime/connectors.php"),
    SPACE_ID,
    JSON.stringify(users),
    `usr_${"a".repeat(64)}`,
  ]);
  expect(probe.stderr.toString()).toBe("");
  expect(probe.exitCode).toBe(0);
  const result = JSON.parse(probe.stdout.toString());
  expect(result.page).toEqual({ subject: `usr_${"a".repeat(64)}` });
  expect(result.zero).toEqual(result.page);
  expect(result.signedOut).toBeNull();
});

test("the service broker reads the same credential with the visitor the runtime resolved", () => {
  const probe = Bun.spawnSync([
    PHP_BINARY,
    "-r",
    `require $argv[1];
    define('SPACEFAST_API_BASE_URL', 'https://api.spacefast.test');
    _stattic_access_private_root($argv[2]);
    $identity = _stattic_zero_service_identity(['auth' => ['isAuthenticated' => true, 'userId' => 'alias:alice', 'subject' => 'visitor:alice', 'email' => 'alice@example.test', 'emailVerified' => true], 'context' => ['spaceId' => $argv[3], 'versionId' => $argv[4]]]);
    $guest = _stattic_zero_service_identity(['auth' => ['isAuthenticated' => false, 'userId' => 'guest:alice'], 'context' => ['spaceId' => $argv[3], 'versionId' => $argv[4]]]);
    $other = _stattic_service_broker_env(['spaceId' => $argv[3], 'versionId' => 'ver_without_roles']);
    echo json_encode(['visitor' => $identity['visitor'], 'guest' => $guest['visitor'], 'env' => _stattic_service_broker_env($identity), 'other' => array_key_exists('SPACEFAST_SERVICE_CONNECTORS_TOKEN', $other)]);`,
    path.join(rt.engineRoot, "runtime/zero.php"),
    rt.storageRoot,
    SPACE_ID,
    VERSION_ID,
  ]);
  expect(probe.stderr.toString()).toBe("");
  expect(probe.exitCode).toBe(0);
  const result = JSON.parse(probe.stdout.toString());
  expect(result.visitor).toEqual({
    subject: "visitor:alice",
    email: "alice@example.test",
    emailVerified: true,
  });
  expect(result.guest).toBeNull();
  expect(result.env).toMatchObject({
    SPACEFAST_SERVICE_CONNECTORS_URL: "https://api.spacefast.test/v1/runtime/connectors/calls",
    SPACEFAST_SERVICE_CONNECTORS_TOKEN: TOKEN,
    SPACEFAST_SERVICE_CONNECTORS_VISITOR: JSON.stringify(result.visitor),
  });
  expect(result.other).toBe(false);
});

test("connector states and null results survive the Zero run envelopes", () => {
  const state = {
    code: "approval_pending",
    message: "This action is waiting for approval.",
    runId: "run_test",
    role: "tracker",
  };
  for (const [op, field] of [
    ["query.run", "data"],
    ["mutation.run", "result"],
    ["action.run", "result"],
  ]) {
    for (const value of [
      { __connector: state },
      { __connector: { ...state, code: "running" } },
      null,
    ]) {
      const probe = Bun.spawnSync([
        PHP_BINARY,
        "-r",
        'require $argv[1]; _stattic_zero_send_run_frame($argv[2], "issues", [], ["status" => 200], $argv[3]);',
        path.join(rt.engineRoot, "runtime/zero.php"),
        op,
        JSON.stringify(value),
      ]);
      expect(probe.stderr.toString()).toBe("");
      expect(probe.exitCode).toBe(0);
      const frame = JSON.parse(probe.stdout.toString());
      expect(frame).toMatchObject({ ok: true, [field]: value });
      expect(Object.hasOwn(frame, "id")).toBe(false);
    }
  }
});
