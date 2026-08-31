import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, readdirSync } from "node:fs";
import path from "node:path";

import {
  deploy,
  get,
  problemDocument,
  putRoute,
  signEd25519Jwt,
  spaceRoot,
  startRuntime,
  type Runtime,
  visitorIssuer,
} from "./harness.ts";

// The runtime-rendered visitor access page: lanes from the projected grants,
// presentation from the accessPage descriptor, credentials verified centrally
// through the server-to-server exchange. That exchange is a local fake here,
// minting real handoff tokens with the suite's visitor key.

let runtime: Runtime;
let exchange: ReturnType<typeof Bun.serve>;
let exchangeOrigin = "";

const LANES_HOST = "lanes.access-page.test";
const LANES_SPACE = "spc_access_page_lanes";
const LANES_VERSION = "ver_access_page_lanes";
const SILENT_HOST = "silent.access-page.test";
const SILENT_SPACE = "spc_access_page_silent";
const SILENT_VERSION = "ver_access_page_silent";
const PUBLIC_HOST = "public-account.access-page.test";
const PUBLIC_SPACE = "spc_access_page_public_account";
const PUBLIC_VERSION = "ver_access_page_public_account";
const PUBLIC_GRANT = "grt_public_account";
const PUBLIC_AUTHORITY = `external:grant.${PUBLIC_GRANT}`;
// Identity-bound AND link-shareable: the one shape where a link arrival can
// chain the silent account probe before landing.
const CHAIN_HOST = "chain.access-page.test";
const CHAIN_SPACE = "spc_access_page_chain";
const CHAIN_VERSION = "ver_access_page_chain";
const SOLE_SSO_HOST = "sole-sso.access-page.test";
const SOLE_SSO_SPACE = "spc_access_page_sole_sso";
const SOLE_SSO_VERSION = "ver_access_page_sole_sso";
const CUSTOM_HOST = "custom.access-page.test";
const CUSTOM_SPACE = "spc_access_page_custom";
const CUSTOM_VERSION = "ver_access_page_custom";
const UNCLAIMED_HOST = "unclaimed.access-page.test";
const UNCLAIMED_SPACE = "spc_access_page_unclaimed";
const UNCLAIMED_VERSION = "ver_access_page_unclaimed";
const FENCED_HOST = "fenced.access-page.test";
const FENCED_SPACE = "spc_access_page_fenced";
const FENCED_VERSION = "ver_access_page_fenced";
// The dashboard framing a private version: an `embed` claim names the one
// origin the platform vouched for as this request's framer.
const EMBED_ORIGIN = "https://dashboard.access-page.test";
// Mirrors STATTIC_SYSTEM_VIEW_COOKIE_SECONDS in runtime/engine/shared/context.php:
// the window a top-level system reader's echo cookie gets.
const SYSTEM_VIEW_ECHO_SECONDS = 5 * 60;

const EXCHANGE_CREDENTIAL = "runtime-exchange-credential-0123456789abcdef";
const PASSWORD = "correct horse battery staple";
// A share link is the destination URL with this token on it, durable and
// multi-use until its Grant is revoked. Every token that may ride `?__=`
// declares its lane with a prefix (context.php:
// STATTIC_ACCESS_QUERY_TOKEN_*_PREFIX).
const LINK_PREFIX = "sfl_";
const SYSTEM_VIEW_PREFIX = "sfv_";
const FRAME_SESSION_PREFIX = "sff_";
const FRAME_ORIGIN = "https://wordpress.example";
const FRAME_LINK_ID = "lnk_page_frame";
const FRAME_LINK_REVISION = 2;
const LINK_TOKEN = `${LINK_PREFIX}aBcD-shareLink_0123456789`;
const API_TOKEN = "sfa_ownerIntegrationToken123";
const UNCLAIMED_LINK_TOKEN = `${LINK_PREFIX}unclaimed-shareLink_0123456789`;
const CHAIN_LINK_TOKEN = `${LINK_PREFIX}chain-shareLink_0123456789`;
let linkRevoked = false;
const ACCOUNT_URL = "https://api.access-page.test/v1/access/acquire/runtime-page";
const SSO_START_URL =
  "https://access.access-page.test/identity/acn_okta/start?spaceId=spc_access_page_lanes";

// Both session forms are `<prefix><base64url payload>.<hmac>` (contracts §7):
// the cookie is the session. `sfv2_` carries authorities plus the
// [access_gen, session_ver] tuple; `sfa1_` carries no authority.
const RECORDED_SESSION_PREFIX = "sfv2_";
const STATELESS_SESSION_PREFIX = "sfa1_";

const keyPair = generateKeyPairSync("ed25519");
const visitorJwk = keyPair.publicKey.export({ format: "jwk" });
const issuer = visitorIssuer(keyPair.publicKey);

const exchangeRequests: Array<{
  pathname: string;
  headers: Record<string, string>;
  form: URLSearchParams;
}> = [];

// The payload is the value the runtime handed the browser, so a test reads the
// minted session without a server-side lookup.
function sessionPayload(cookie: string): Record<string, unknown> {
  const value = cookie.split("=", 2)[1] ?? "";
  const prefix = [RECORDED_SESSION_PREFIX, STATELESS_SESSION_PREFIX].find((candidate) =>
    value.startsWith(candidate),
  );
  if (prefix === undefined) {
    throw new Error(`not a Spacefast session cookie: ${cookie}`);
  }
  const encoded = value.slice(prefix.length).split(".")[0] ?? "";
  return JSON.parse(Buffer.from(encoded, "base64url").toString("utf8")) as Record<string, unknown>;
}

// The only server-side session state left (§7/D85): one record per recorded
// session, so a single session can be revoked exactly. A visitor who proved
// nothing never appears here.
function sessionRecords(spaceId: string): string[] {
  const directory = path.join(spaceRoot(runtime, spaceId), "sessions");
  return existsSync(directory)
    ? readdirSync(directory).filter((file) => /^[a-f0-9]{64}\.json$/.test(file))
    : [];
}

function sessionRecordPath(spaceId: string, cookie: string): string {
  const sessionId = sessionPayload(cookie).sid;
  if (typeof sessionId !== "string") {
    throw new Error("session cookie has no string sid");
  }
  return path.join(spaceRoot(runtime, spaceId), "sessions", `${sessionId}.json`);
}

function sessionCookie(response: Response): string {
  return (
    response.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_session="))
      ?.split(";")[0] ?? ""
  );
}

function systemViewCookie(response: Response): string {
  return (
    response.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_system_view="))
      ?.split(";")[0] ?? ""
  );
}

function frameSessionCookie(response: Response): string {
  return (
    response.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_frame="))
      ?.split(";")[0] ?? ""
  );
}

function frameSessionSetCookie(response: Response): string {
  return (
    response.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_frame=")) ?? ""
  );
}

function handoffToken(
  host: string,
  spaceId: string,
  authorities: string[],
  identity: { principal?: string; profile?: Record<string, string> } = {},
): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(keyPair.privateKey, issuer.kid, {
    sub: authorities[0],
    purpose: "handoff",
    authorities,
    grants: authorities,
    iss: "spacefast-api",
    aud: spaceId,
    host,
    spaceId,
    generation: 0,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    emailVerified: false,
    iat: now,
    nbf: now,
    exp: now + 60,
    jti: randomUUID(),
    ...identity,
  });
}

// Internal surfaces (thumbnail fetchers, admin view links) present a signed
// token on the same `?__=` param. The JWT stays the authority. The runtime may
// echo it into a short host-only cookie so the renderer can fetch page assets,
// but creates no visitor session and no server-side record.
function systemViewToken(
  overrides: Record<string, unknown> = {},
  host = LANES_HOST,
  spaceId = LANES_SPACE,
): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(keyPair.privateKey, issuer.kid, {
    sub: `system:${spaceId}`,
    purpose: "system-view",
    capabilities: ["page.view"],
    iss: "spacefast-api",
    aud: spaceId,
    host,
    spaceId,
    generation: 0,
    sessionVersion: 0,
    iat: now,
    nbf: now,
    exp: now + 3600,
    ...overrides,
  });
}

// What the platform binds a Frame proof to. The live shape names the Version
// the channel currently serves, so a republish invalidates the proof; a branch
// or Version target names its own host.
type FrameTarget =
  | { kind: "live"; versionId: string; routeRevision: string }
  | { kind: "branch"; branch: string }
  | { kind: "version"; versionId: string };

// A Frame proof: one signed Grant decision (`frame`, the contract object in
// packages/common frameSessionClaimsV1Schema) beside the flat host/space/epoch
// claims every visitor token carries. The platform mints this in
// apps/control-plane/src/access/frame-session-token.ts.
function frameSessionToken(
  overrides: {
    origin?: string;
    linkRevision?: number;
    target?: FrameTarget;
    exp?: number;
    sessionVersion?: number;
    capabilities?: string[];
  } = {},
  host = LANES_HOST,
  spaceId = LANES_SPACE,
): string {
  const now = Math.floor(Date.now() / 1000);
  const expiresAt = overrides.exp ?? now + 300;
  const origin = overrides.origin ?? FRAME_ORIGIN;
  const sessionVersion = overrides.sessionVersion ?? 0;
  const nonce = randomUUID();
  const principalAssertion = {
    format: "spacefast.principal",
    version: 1,
    audience: { spaceId, runtimeInstanceId: spaceId, host: `https://${host}` },
    actor: { kind: "user", id: "usr_frame_viewer" },
    subject: { issuer: "https://api.example/v1/auth", subject: "usr_frame_viewer" },
    wordpressRole: "subscriber",
    sessionVersion,
    accessGeneration: 0,
    nonce,
    issuedAt: now,
    expiresAt,
  };
  return signEd25519Jwt(keyPair.privateKey, issuer.kid, {
    purpose: "frame-session",
    iss: "spacefast-api",
    aud: spaceId,
    host,
    spaceId,
    runtime_instance_id: spaceId,
    path: "/docs",
    generation: 0,
    sessionVersion,
    principalAssertion,
    frame: {
      format: "spacefast.frame-session",
      version: 1,
      launchTicketId: randomUUID(),
      audience: { spaceId, runtimeInstanceId: spaceId, host: `https://${host}` },
      link: { id: FRAME_LINK_ID, revision: overrides.linkRevision ?? FRAME_LINK_REVISION },
      principal: { kind: "user", id: "usr_frame_viewer", assertionNonce: nonce },
      // The live channel resolves to the immutable Version it is serving, so a
      // republish changes the proof's target and closes it.
      target: overrides.target ?? {
        kind: "live",
        versionId: LANES_VERSION,
        routeRevision: createHash("sha256").update(`${spaceId}:live`).digest("hex"),
      },
      surface: "wordpress.preview",
      parentOrigin: origin,
      capabilities: overrides.capabilities ?? ["page.view"],
      grantDecisionDigest: createHash("sha256").update(`${spaceId}:${origin}`).digest("hex"),
      accessGeneration: 0,
      sessionVersion,
      nonce: randomUUID(),
      issuedAt: now,
      expiresAt,
    },
    iat: now,
    nbf: now,
    exp: expiresAt,
  });
}

function accessConfig(input: {
  spaceId: string;
  grants: Array<Record<string, unknown>>;
  accessPage: Record<string, unknown> | null;
  spaceClaimed?: boolean;
  fence?: string;
  // The Space's access generation. Every Grant mutation moves it (the control
  // plane bumps `spaces.access_generation` in the same transaction), and the
  // runtime compares a session's `[access_gen, session_ver]` tuple against it.
  // A fixture that edits a Grant without moving it models a mutation the
  // platform cannot produce.
  generation?: number;
}) {
  const generation = input.generation ?? 0;
  return {
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
    projection_generation: generation,
    authorization: {
      generation,
      sessionVersion: 0,
      fence: input.fence ?? "none",
      acquireUrl: "https://access.access-page.test/acquire/runtime-page",
      spaceClaimed: input.spaceClaimed ?? true,
      ...(input.accessPage === null ? {} : { accessPage: input.accessPage }),
      grants: input.grants,
    },
  };
}

function grant(
  id: string,
  audience: Record<string, string>,
  constraints: Record<string, unknown> = {},
  generation = 1,
): Record<string, unknown> {
  return {
    id,
    generation,
    audience,
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view", "comments.read"],
    constraints,
    target: { kind: "live" },
    source: { kind: "managed", reference: id },
  };
}

// The lanes fixture's projection, re-derived whenever a test re-activates the
// route (the exchange origin is only known once the fake is listening).
function lanesAccessPage(): Record<string, unknown> {
  return {
    displayName: "Design Handbook",
    accountUrl: ACCOUNT_URL,
    connections: [{ id: "acn_okta", label: "Okta", startUrl: SSO_START_URL }],
    exchange: {
      passwordUrl: `${exchangeOrigin}/acquire/runtime-page/password`,
      linkUrl: `${exchangeOrigin}/acquire/runtime-page/link`,
      tokenUrl: `${exchangeOrigin}/acquire/runtime-page/token`,
      emailUrl: `${exchangeOrigin}/acquire/runtime-page/email`,
      requestUrl: `${exchangeOrigin}/acquire/runtime-page/request`,
      credential: EXCHANGE_CREDENTIAL,
    },
  };
}

function lanesGrants(shareGeneration = 1): Array<Record<string, unknown>> {
  return [
    grant("grt_page_password", { kind: "password", credentialId: "pwd_page_test" }),
    grant("grt_page_share", { kind: "link", linkId: "lnk_page_share" }, {}, shareGeneration),
    {
      ...grant(
        "grt_page_frame",
        { kind: "link", linkId: FRAME_LINK_ID },
        { frameOrigin: FRAME_ORIGIN },
        FRAME_LINK_REVISION,
      ),
      resources: { include: ["/docs/**"], exclude: [] },
      capabilities: ["page.view"],
    },
  ];
}

// The central exchange refuses with an RFC 9457 problem document, like every
// other control-plane answer the runtime parses.
function problem(status: number, code: string, extra: Record<string, unknown> = {}): Response {
  return Response.json(problemDocument(status, code, undefined, extra), {
    status,
    headers: { "content-type": "application/problem+json" },
  });
}

beforeAll(async () => {
  exchange = Bun.serve({
    port: 0,
    hostname: "127.0.0.1",
    fetch: async (request) => {
      const url = new URL(request.url);
      const form = new URLSearchParams(await request.text());
      exchangeRequests.push({
        pathname: url.pathname,
        headers: Object.fromEntries(request.headers.entries()),
        form,
      });
      if (url.pathname === "/acquire/runtime-page/password") {
        if (request.headers.get("spacefast-runtime-exchange") !== EXCHANGE_CREDENTIAL) {
          return problem(404, "not_found");
        }
        const submitted = form.get("password") ?? "";
        if (submitted === PASSWORD) {
          const host = form.get("host") ?? "";
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: handoffToken(host, LANES_SPACE, ["password:pwd_page_test"]),
                return: form.get("landingPath") ?? "/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        if (submitted === "rate-limited-password") {
          return problem(429, "rate_limited");
        }
        return problem(403, "invalid_password");
      }
      if (url.pathname === "/acquire/runtime-page/link") {
        if (request.headers.get("spacefast-runtime-exchange") !== EXCHANGE_CREDENTIAL) {
          return problem(404, "not_found");
        }
        const presented = form.get("token") ?? "";
        if (presented === LINK_TOKEN && !linkRevoked) {
          const host = form.get("host") ?? "";
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: handoffToken(host, LANES_SPACE, ["link:lnk_page_share"]),
                return: "/docs/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        if (presented === CHAIN_LINK_TOKEN) {
          const host = form.get("host") ?? "";
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: handoffToken(host, CHAIN_SPACE, ["link:lnk_chain_share"]),
                return: "/docs/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        if (presented === UNCLAIMED_LINK_TOKEN) {
          const host = form.get("host") ?? "";
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: handoffToken(host, UNCLAIMED_SPACE, ["link:lnk_page_author"]),
                return: "/docs/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        return problem(404, "unknown_link");
      }
      if (url.pathname === "/acquire/runtime-page/token") {
        if (request.headers.get("spacefast-runtime-exchange") !== EXCHANGE_CREDENTIAL) {
          return problem(404, "not_found");
        }
        const presented = form.get("token") ?? "";
        const host = form.get("host") ?? "";
        if (presented === API_TOKEN) {
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: systemViewToken({ scope: form.get("landingPath") ?? "/" }),
                return: form.get("landingPath") ?? "/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        if (presented === LINK_TOKEN && !linkRevoked) {
          return Response.json(
            {
              action: `https://${host}/__sf/redeem`,
              fields: {
                token: handoffToken(host, LANES_SPACE, ["link:lnk_page_share"]),
                return: form.get("landingPath") ?? "/",
              },
            },
            { headers: { "content-type": "application/vnd.spacefast.access-handoff+json" } },
          );
        }
        return problem(401, "invalid_credential");
      }
      if (url.pathname === "/acquire/runtime-page/email") {
        if (request.headers.get("spacefast-runtime-exchange") !== EXCHANGE_CREDENTIAL) {
          return problem(404, "not_found");
        }
        return Response.json({ data: { status: "pending" } }, { status: 202 });
      }
      if (url.pathname === "/acquire/runtime-page/request") {
        if (request.headers.get("spacefast-runtime-exchange") !== EXCHANGE_CREDENTIAL) {
          return problem(404, "not_found");
        }
        return Response.json({ data: { status: "pending" } }, { status: 202 });
      }
      return new Response("not found", { status: 404 });
    },
  });
  exchangeOrigin = `http://127.0.0.1:${exchange.port}`;
  runtime = await startRuntime({ env: { SPACEFAST_INSECURE_COOKIES: "" } });
  const fixtures = [
    {
      spaceId: LANES_SPACE,
      versionId: LANES_VERSION,
      host: LANES_HOST,
      grants: lanesGrants(),
      accessPage: lanesAccessPage(),
    },
    {
      spaceId: SILENT_SPACE,
      versionId: SILENT_VERSION,
      host: SILENT_HOST,
      grants: [
        grant("grt_page_member", {
          kind: "external",
          issuer: "spacefast-membership",
          subject: "mem_page_owner",
        }),
      ],
      accessPage: {
        displayName: null,
        accountUrl: ACCOUNT_URL,
        connections: [],
        exchange: {
          passwordUrl: `${exchangeOrigin}/acquire/runtime-page/password`,
          tokenUrl: `${exchangeOrigin}/acquire/runtime-page/token`,
          requestUrl: `${exchangeOrigin}/acquire/runtime-page/request`,
          credential: EXCHANGE_CREDENTIAL,
        },
      },
    },
    {
      spaceId: PUBLIC_SPACE,
      versionId: PUBLIC_VERSION,
      host: PUBLIC_HOST,
      grants: [
        grant(PUBLIC_GRANT, { kind: "public" }),
        {
          ...grant(
            "grt_public_frame",
            { kind: "link", linkId: "lnk_public_frame" },
            { frameOrigin: FRAME_ORIGIN },
          ),
          resources: { include: ["/docs/**"], exclude: [] },
          capabilities: ["page.view"],
        },
      ],
      accessPage: lanesAccessPage(),
    },
    {
      spaceId: CHAIN_SPACE,
      versionId: CHAIN_VERSION,
      host: CHAIN_HOST,
      grants: [
        grant("grt_chain_member", {
          kind: "external",
          issuer: "spacefast-membership",
          subject: "mem_chain_owner",
        }),
        grant("grt_chain_share", { kind: "link", linkId: "lnk_chain_share" }),
      ],
      accessPage: {
        displayName: "Chain Handbook",
        accountUrl: ACCOUNT_URL,
        connections: [],
        exchange: {
          passwordUrl: `${exchangeOrigin}/acquire/runtime-page/password`,
          linkUrl: `${exchangeOrigin}/acquire/runtime-page/link`,
          tokenUrl: `${exchangeOrigin}/acquire/runtime-page/token`,
          requestUrl: `${exchangeOrigin}/acquire/runtime-page/request`,
          credential: EXCHANGE_CREDENTIAL,
        },
      },
    },
    {
      spaceId: SOLE_SSO_SPACE,
      versionId: SOLE_SSO_VERSION,
      host: SOLE_SSO_HOST,
      grants: [
        grant("grt_page_sso", {
          kind: "external",
          issuer: "https://identity.example/oidc",
          subject: "user@example.com",
        }),
      ],
      accessPage: {
        displayName: null,
        accountUrl: null,
        connections: [{ id: "acn_sole", label: "Acme SSO", startUrl: SSO_START_URL }],
        exchange: null,
      },
    },
    // An anonymous publish: one pre-claim author Link, nothing to sign into.
    {
      spaceId: UNCLAIMED_SPACE,
      versionId: UNCLAIMED_VERSION,
      host: UNCLAIMED_HOST,
      spaceClaimed: false,
      grants: [grant("grt_page_open_author", { kind: "link", linkId: "lnk_page_author" })],
      accessPage: {
        displayName: "Escaped Filter",
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: `${exchangeOrigin}/acquire/runtime-page/password`,
          linkUrl: `${exchangeOrigin}/acquire/runtime-page/link`,
          tokenUrl: `${exchangeOrigin}/acquire/runtime-page/token`,
          emailUrl: `${exchangeOrigin}/acquire/runtime-page/email`,
          requestUrl: `${exchangeOrigin}/acquire/runtime-page/request`,
          logoutUrl: `${exchangeOrigin}/runtime/collaboration-sessions/revoke`,
          credential: EXCHANGE_CREDENTIAL,
        },
      },
    },
    // Unclaimed AND fenced: the fence outranks the notice.
    {
      spaceId: FENCED_SPACE,
      versionId: FENCED_VERSION,
      host: FENCED_HOST,
      spaceClaimed: false,
      fence: "exposure",
      grants: [grant("grt_page_fenced_author", { kind: "link", linkId: "lnk_page_fenced" })],
      accessPage: {
        displayName: "Fenced Space",
        accountUrl: null,
        connections: [],
        exchange: null,
      },
    },
  ];
  for (const fixture of fixtures) {
    await deploy(runtime, {
      spaceId: fixture.spaceId,
      versionId: fixture.versionId,
      files: {
        "index.html": `<html><body>home of ${fixture.spaceId}</body></html>`,
        "docs/index.html": `<html><head><link rel="stylesheet" href="site.css"></head><body>docs of ${fixture.spaceId}</body></html>`,
        "docs/site.css": "body { color: #123456; }",
        "docs/app-D2mDMWBM.js": "globalThis.spacefastProtectedAsset = true;\n",
        "docs/guide/index.html": `<html><body>guide of ${fixture.spaceId}</body></html>`,
        "admin/index.html": `<html><body>admin of ${fixture.spaceId}</body></html>`,
      },
      activate: {
        route_name: "production",
        config: accessConfig({
          spaceId: fixture.spaceId,
          grants: fixture.grants,
          accessPage: fixture.accessPage,
          ...("spaceClaimed" in fixture ? { spaceClaimed: fixture.spaceClaimed } : {}),
          ...("fence" in fixture ? { fence: fixture.fence } : {}),
        }),
        production_hostnames: [fixture.host],
      },
    });
  }
  // A publisher-supplied access template: the compiled artifact carries the
  // runtime slots and the runtime injects the lane blocks at serve time.
  await deploy(runtime, {
    spaceId: CUSTOM_SPACE,
    versionId: CUSTOM_VERSION,
    files: { "index.html": "<html><body>custom home</body></html>" },
    pageArtifacts: {
      "page-access":
        "<!doctype html><html><body><h1>Acme gate</h1>" +
        "<!--sf-runtime:access-status:start--><!--sf-runtime:access-status:end-->" +
        "<!--sf-runtime:access-lanes:start--><p>fallback</p><!--sf-runtime:access-lanes:end-->" +
        "</body></html>",
    },
    serving: {
      config: {
        index: false,
        listing: false,
        viewer: false,
        pages: { routes: { "/": { pages: { access: "page-access" } } }, previews: {} },
      },
    },
    activate: {
      route_name: "production",
      config: accessConfig({
        spaceId: CUSTOM_SPACE,
        grants: [grant("grt_page_custom_pw", { kind: "password", credentialId: "pwd_custom" })],
        accessPage: {
          displayName: "Acme",
          accountUrl: null,
          connections: [],
          exchange: {
            passwordUrl: `${exchangeOrigin}/acquire/runtime-page/password`,
            tokenUrl: `${exchangeOrigin}/acquire/runtime-page/token`,
            emailUrl: `${exchangeOrigin}/acquire/runtime-page/email`,
            requestUrl: `${exchangeOrigin}/acquire/runtime-page/request`,
            logoutUrl: `${exchangeOrigin}/runtime/collaboration-sessions/revoke`,
            credential: EXCHANGE_CREDENTIAL,
          },
        },
      }),
      production_hostnames: [CUSTOM_HOST],
    },
  });
});

afterAll(async () => {
  await runtime?.stop();
  exchange?.stop(true);
});

test("the deny surface renders the access page with every configured lane", async () => {
  const response = await get(runtime, LANES_HOST, "/docs/?sf_access=no-grant");
  expect(response.status).toBe(403);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  const html = await response.text();
  expect(html).toContain("Design Handbook is private");
  expect(html).toContain("Continue with Spacefast");
  expect(html).toContain("Continue with Okta");
  expect(html).toContain('action="/__spacefast/access/password"');
  expect(html).toContain('name="password"');
  expect(html).toContain("Request an invite");
  const requestDetails = html.match(
    /<details\b[^>]*class="[^"]*\bsf-access-request\b[^"]*"[^>]*>/,
  )?.[0];
  expect(requestDetails).toBeDefined();
  expect(requestDetails).toMatch(/\sopen(?:\s|>)/);
  expect(html).toContain('name="return" value="/docs/"');
  expect(html).toContain('<script src="/__spacefast/access/client.js" defer></script>');
  const clientScript = await get(runtime, LANES_HOST, "/__spacefast/access/client.js");
  expect(clientScript.status).toBe(200);
  expect(clientScript.headers.get("content-type")).toBe("application/javascript; charset=utf-8");
  // Central URLs carry host and return so the flows come back to this page.
  expect(html).toContain("host=lanes.access-page.test");
  expect(html).toContain("return=%2Fdocs%2F");
});

test("state codes render as page states and never echo unknown values", async () => {
  // Central account/link transitions need dashboard identity or single-use
  // emailed credentials and cannot run in the local browser fixture, so assert
  // their status copy here. The control-plane route suite owns the decisions
  // and redirects that produce these codes.
  const centralStateCopy = {
    "no-grant": "hasn&#039;t let you in yet",
    "link-expired": "That link has expired",
    "link-revoked": "That link was turned off",
    "link-invalid": "That link doesn&#039;t work anymore",
    "open-used": "That open link was already used",
  } as const;
  for (const [code, copy] of Object.entries(centralStateCopy)) {
    const rendered = await get(runtime, LANES_HOST, `/?sf_access=${code}`);
    expect(rendered.status).toBe(403);
    const html = await rendered.text();
    expect(html).toContain(copy);
    // The state param is stripped from the return path the forms carry.
    expect(html).toContain('name="return" value="/"');
  }

  const unknown = await get(runtime, LANES_HOST, "/?sf_access=<script>alert(1)</script>");
  const unknownHtml = await unknown.text();
  expect(unknownHtml).not.toContain("alert(1)");
  expect(unknownHtml).not.toContain('<p class="sf-copy sf-access-status"');
});

test("the JSON representation stays a machine-readable 403", async () => {
  const response = await get(runtime, LANES_HOST, "/docs/", {
    headers: { accept: "application/json", "sec-fetch-mode": "cors" },
  });
  expect(response.status).toBe(403);
  expect(response.headers.get("content-type")).toContain("application/problem+json");
  const body = await response.json();
  expect(body.code).toBe("access_denied");
});

test("an unclaimed space points the visitor at the link it cannot render", async () => {
  const response = await get(runtime, UNCLAIMED_HOST, "/docs/");
  expect(response.status).toBe(403);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  const html = await response.text();
  expect(html).toContain("Escaped Filter is private");
  expect(html).toContain("Ask your agent or check your logs for the link you need to unlock");
  // Still lane-less: no form, no account lane, nothing to submit.
  expect(html).not.toContain('name="password"');
  expect(html).not.toContain("Continue with Spacefast");

  // The hidden Link lane still opens the destination though this Space renders
  // no password, account, request, or identity lane.
  const opened = await get(runtime, UNCLAIMED_HOST, `/__/${UNCLAIMED_LINK_TOKEN}`);
  expect(opened.status).toBe(303);
  expect(opened.headers.get("location")).toBe("/docs/");
  const cookie = (opened.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  const followed = await get(runtime, UNCLAIMED_HOST, "/docs/", { headers: { cookie } });
  expect(followed.status).toBe(200);
  expect(await followed.text()).toContain(`docs of ${UNCLAIMED_SPACE}`);
});

test("a fenced space keeps the uniform deny even while unclaimed", async () => {
  const response = await get(runtime, FENCED_HOST, "/docs/");
  expect(response.status).toBe(403);
  const html = await response.text();
  expect(html).toContain("This page is private");
  expect(html).not.toContain("Ask your agent");
  expect(html).not.toContain("Fenced Space");
});

test("the password lane verifies centrally and admits with zero visible redirects", async () => {
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${LANES_HOST}`,
    },
    body: new URLSearchParams({ password: PASSWORD, return: "/docs/" }),
  });
  expect(submitted.status).toBe(303);
  expect(submitted.headers.get("location")).toBe("/docs/");
  const cookie = sessionCookie(submitted);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);
  // The cookie is the session (§7/D120): it names its authority and the
  // [access_gen, session_ver] tuple the hot path compares against the overlay,
  // so the next request is admitted without reading a file.
  const claims = sessionPayload(cookie);
  expect(claims.spaceId).toBe(LANES_SPACE);
  expect(claims.host).toBe(LANES_HOST);
  expect(claims.accessGeneration).toBe(0);
  expect(claims.sessionVersion).toBe(0);

  const admitted = await get(runtime, LANES_HOST, "/docs/", { headers: { cookie } });
  expect(admitted.status).toBe(200);
  expect(await admitted.text()).toContain("docs of spc_access_page_lanes");

  const call = exchangeRequests.findLast(
    (request) => request.pathname === "/acquire/runtime-page/password",
  );
  expect(call).toBeDefined();
  expect(call?.headers["spacefast-runtime-exchange"]).toBe(EXCHANGE_CREDENTIAL);
  expect(call?.headers["spacefast-visitor-ip"]).toBeDefined();
  expect(call?.form.get("host")).toBe(LANES_HOST);
  expect(call?.form.get("landingPath")).toBe("/docs/");
});

test("a wrong password bounces back to the page with an allowlisted state", async () => {
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${LANES_HOST}`,
    },
    body: new URLSearchParams({ password: "wrong password", return: "/docs/" }),
  });
  expect(submitted.status).toBe(303);
  expect(submitted.headers.get("location")).toBe("/docs/?sf_access=invalid-password");
  expect(submitted.headers.get("set-cookie")).toBeNull();

  const rendered = await get(runtime, LANES_HOST, "/docs/?sf_access=invalid-password");
  const html = await rendered.text();
  expect(html).toContain("That password didn&#039;t work.");
});

test("central rate limiting surfaces as its own state", async () => {
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${LANES_HOST}`,
    },
    body: new URLSearchParams({ password: "rate-limited-password", return: "/" }),
  });
  expect(submitted.status).toBe(303);
  expect(submitted.headers.get("location")).toBe("/?sf_access=rate-limited");
});

test("cross-site password submissions are refused", async () => {
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: "https://evil.example",
    },
    body: new URLSearchParams({ password: PASSWORD, return: "/" }),
  });
  expect(submitted.status).toBe(403);
  expect(submitted.headers.get("set-cookie")).toBeNull();
});

const originMetadataCases: Array<{
  name: string;
  origin?: string;
  secFetchSite?: string;
  accepted: boolean;
}> = [
  // No Origin and no same-origin fetch metadata proves nothing about where the
  // submission came from, so refuse it.
  { name: "absent Origin + absent Sec-Fetch-Site", accepted: false },
  { name: "absent Origin + Sec-Fetch-Site none", secFetchSite: "none", accepted: false },
  {
    name: "absent Origin + Sec-Fetch-Site same-origin",
    secFetchSite: "same-origin",
    accepted: true,
  },
  {
    name: "absent Origin + Sec-Fetch-Site cross-site",
    secFetchSite: "cross-site",
    accepted: false,
  },
  {
    name: "absent Origin + Sec-Fetch-Site same-site",
    secFetchSite: "same-site",
    accepted: false,
  },
  {
    name: "null Origin + Sec-Fetch-Site same-origin",
    origin: "null",
    secFetchSite: "same-origin",
    accepted: true,
  },
  { name: "null Origin + absent Sec-Fetch-Site", origin: "null", accepted: false },
  {
    name: "null Origin + Sec-Fetch-Site none",
    origin: "null",
    secFetchSite: "none",
    accepted: false,
  },
  {
    name: "null Origin + Sec-Fetch-Site same-site",
    origin: "null",
    secFetchSite: "same-site",
    accepted: false,
  },
  {
    name: "null Origin + Sec-Fetch-Site cross-site",
    origin: "null",
    secFetchSite: "cross-site",
    accepted: false,
  },
  {
    name: "null Origin + unrecognized Sec-Fetch-Site",
    origin: "null",
    secFetchSite: "unexpected",
    accepted: false,
  },
];

test.each(originMetadataCases)("password origin metadata contract: $name", async (entry) => {
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/password", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      ...(entry.origin === undefined ? {} : { origin: entry.origin }),
      ...(entry.secFetchSite === undefined ? {} : { "sec-fetch-site": entry.secFetchSite }),
    },
    body: new URLSearchParams({ password: PASSWORD, return: "/" }),
  });
  expect(submitted.status).toBe(entry.accepted ? 303 : 403);
  if (entry.accepted) {
    expect(submitted.headers.get("set-cookie")).toContain(RECORDED_SESSION_PREFIX);
  } else {
    expect(submitted.headers.get("set-cookie")).toBeNull();
  }
});

test("the request-invite lane relays centrally and remembers only the requested scope", async () => {
  const recordsBefore = sessionRecords(LANES_SPACE);
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/request", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${LANES_HOST}`,
    },
    body: new URLSearchParams({
      email: "visitor@example.com",
      message: "It's me",
      return: "/docs/",
    }),
  });
  expect(submitted.status).toBe(303);
  expect(submitted.headers.get("location")).toBe("/docs/?sf_access=request-pending");
  // The requested scope is remembered on the host session, not in a cookie of
  // its own. Nobody proved anything here, so that session is the stateless
  // form: asking for an invite writes no revocation record, and a flood of
  // requests cannot evict anyone who did prove something.
  const requested = sessionCookie(submitted);
  expect(requested).toStartWith(`__Host-spacefast_session=${STATELESS_SESSION_PREFIX}`);
  expect(sessionRecords(LANES_SPACE)).toEqual(recordsBefore);

  const pending = await get(runtime, LANES_HOST, "/docs/?sf_access=request-pending", {
    headers: { cookie: requested },
  });
  const pendingHtml = await pending.text();
  expect(pendingHtml).toContain("Request sent.");
  expect(pendingHtml).not.toContain("Request again</summary>");

  const rendered = await get(runtime, LANES_HOST, "/docs/guide/", {
    headers: { cookie: requested },
  });
  const html = await rendered.text();
  expect(html).toContain("Invite requested for /docs.");
  expect(html).toContain("Request again</summary>");
  expect(html).not.toContain("Need access? Request an invite</summary>");

  const unrelated = await get(runtime, LANES_HOST, "/admin/", {
    headers: { cookie: requested },
  });
  const unrelatedHtml = await unrelated.text();
  expect(unrelatedHtml).toContain("Need access? Request an invite</summary>");
  expect(unrelatedHtml).not.toContain("Invite requested for /docs.");

  const call = exchangeRequests.findLast(
    (request) => request.pathname === "/acquire/runtime-page/request",
  );
  expect(call?.form.get("email")).toBe("visitor@example.com");
  expect(call?.form.get("message")).toBe("It's me");
});

test("an invalid email never leaves this host", async () => {
  const before = exchangeRequests.length;
  const submitted = await get(runtime, LANES_HOST, "/__spacefast/access/request", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${LANES_HOST}`,
    },
    body: new URLSearchParams({ email: "not-an-email", return: "/" }),
  });
  expect(submitted.status).toBe(303);
  expect(submitted.headers.get("location")).toBe("/?sf_access=request-failed");
  expect(exchangeRequests.length).toBe(before);
});

test("identity-bound spaces probe the account session silently, once per browser", async () => {
  const probe = await get(runtime, SILENT_HOST, "/docs/", {
    headers: { "sec-fetch-dest": "document" },
  });
  expect(probe.status).toBe(302);
  const location = probe.headers.get("location") ?? "";
  expect(location.startsWith(ACCOUNT_URL)).toBe(true);
  expect(location).toContain("silent=1");
  expect(location).toContain(`host=${SILENT_HOST}`);
  expect(location).toContain("return=%2Fdocs%2F");
  // No probe cookie of its own: the probe is remembered on the host session,
  // which this visitor did not have until now. That session is the stateless
  // form, so an unauthenticated bounce writes no revocation record and curling
  // this URL in a loop evicts no real session.
  const guard = sessionCookie(probe);
  expect(guard).toStartWith(`__Host-spacefast_session=${STATELESS_SESSION_PREFIX}`);
  expect(sessionRecords(SILENT_SPACE)).toEqual([]);

  const rendered = await get(runtime, SILENT_HOST, "/docs/", {
    headers: { cookie: guard },
  });
  expect(rendered.status).toBe(403);
  expect(await rendered.text()).toContain("Continue with Spacefast");

  // The memory is host-bound: the same value replayed on a sibling host proves
  // nothing there and is dropped instead of suppressing that host's probe.
  const replayed = await get(runtime, CHAIN_HOST, "/docs/", {
    headers: { cookie: guard, "sec-fetch-dest": "document" },
  });
  expect(replayed.status).toBe(302);
  expect(replayed.headers.get("location") ?? "").toContain("silent=1");

  // A share-link token minted for another Space opens nothing here, so this
  // arrival is an ordinary unadmitted visitor and gets the same probe.
  const tokened = await get(runtime, SILENT_HOST, `/docs/?__=${LINK_TOKEN}`, {
    headers: { "sec-fetch-dest": "document" },
  });
  expect(tokened.status).toBe(302);
  expect(tokened.headers.get("location") ?? "").toContain("silent=1");
});

// The dead credential is gone by the time the page renders, so the diagnosis
// moves onto the stateless session that replaces it. That is why the visitor is
// told they were signed out instead of meeting a blank "this is private" wall.
test("a session that expired still explains itself on the next navigation", async () => {
  const dead = `${RECORDED_SESSION_PREFIX}${"0".repeat(64)}.${"0".repeat(64)}`;
  const cleared = await get(runtime, LANES_HOST, "/docs/", {
    headers: { cookie: `__Host-spacefast_session=${dead}` },
  });
  expect(cleared.status).toBe(403);
  expect(await cleared.text()).toContain("Your access expired");
  const replacement = sessionCookie(cleared);
  expect(replacement).toStartWith(`__Host-spacefast_session=${STATELESS_SESSION_PREFIX}`);
  expect(replacement).not.toContain(dead);

  const next = await get(runtime, LANES_HOST, "/docs/", {
    headers: { cookie: replacement },
  });
  expect(next.status).toBe(403);
  expect(await next.text()).toContain("Your access expired");

  // It carries the diagnosis and nothing else: it opens no page the visitor was
  // not already entitled to, and names no authority.
  expect(sessionPayload(replacement).authorities).toBeUndefined();
  expect(
    (await get(runtime, LANES_HOST, "/docs/guide/", { headers: { cookie: replacement } })).status,
  ).toBe(403);
});

test("a bounced silent probe renders the page instead of looping", async () => {
  const checked = await get(runtime, SILENT_HOST, "/docs/?sf_access=checked");
  expect(checked.status).toBe(403);
  const html = await checked.text();
  expect(html).toContain("Continue with Spacefast");

  const noGrant = await get(runtime, SILENT_HOST, "/docs/?sf_access=no-grant");
  expect(await noGrant.text()).toContain("hasn&#039;t let you in yet");
});

test("a sole SSO lane skips the chooser entirely", async () => {
  const probe = await get(runtime, SOLE_SSO_HOST, "/", {
    headers: { "sec-fetch-dest": "document" },
  });
  expect(probe.status).toBe(302);
  const location = probe.headers.get("location") ?? "";
  expect(location.startsWith(SSO_START_URL)).toBe(true);
  expect(location).toContain(`host=${SOLE_SSO_HOST}`);
  // This projection carries no exchange credential, so no key exists to sign a
  // stateless session and the probe cannot be remembered. It degrades to
  // bouncing again next time, never to writing a session nobody can verify.
  expect(probe.headers.getSetCookie()).toEqual([]);
  expect(sessionRecords(SOLE_SSO_SPACE)).toEqual([]);

  const rendered = await get(runtime, SOLE_SSO_HOST, "/?sf_access=checked");
  expect(rendered.status).toBe(403);
  expect(await rendered.text()).toContain("Continue with Acme SSO");
});

test("only a positively identified top-level document gets the silent redirect", async () => {
  const fetchRequest = await get(runtime, SILENT_HOST, "/docs/", {
    headers: { accept: "application/json", "sec-fetch-mode": "cors" },
  });
  expect(fetchRequest.status).toBe(403);

  const subresource = await get(runtime, SILENT_HOST, "/docs/", {
    headers: { "sec-fetch-dest": "image" },
  });
  expect(subresource.status).toBe(403);

  // Missing metadata is not evidence of a top-level page. Some dev proxies and
  // embedded browsers strip it; rendering the gate remains usable in either.
  const unknown = await get(runtime, SILENT_HOST, "/docs/");
  expect(unknown.status).toBe(403);
});

test("a publisher access template keeps the working lane blocks", async () => {
  const rendered = await get(runtime, CUSTOM_HOST, "/");
  expect(rendered.status).toBe(403);
  const html = await rendered.text();
  expect(html).toContain("<h1>Acme gate</h1>");
  expect(html).toContain('action="/__spacefast/access/password"');
  expect(html).not.toContain("<p>fallback</p>");
  expect(html).not.toContain("sf-runtime:access-lanes:start");

  const expired = await get(runtime, CUSTOM_HOST, "/?sf_access=link-expired");
  const expiredHtml = await expired.text();
  expect(expiredHtml).toContain("That link has expired");
  // The status renders once, in its slot, never duplicated into the lanes.
  expect(expiredHtml.split("That link has expired").length).toBe(2);
});

// The account lane: the control plane 303s a signed handoff straight at the
// runtime, so a signed-in visitor with authority never sees an interstitial.
test("the account lane redeems by GET, in the page or in a popup", async () => {
  const token = handoffToken(LANES_HOST, LANES_SPACE, ["password:pwd_page_test"]);
  const redeem = await get(
    runtime,
    LANES_HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(token)}&return=%2Fdocs%2F`,
  );
  expect(redeem.status).toBe(303);
  expect(redeem.headers.get("location")).toBe("/docs/");
  expect(redeem.headers.get("cache-control")).toBe("no-store");
  expect(redeem.headers.get("referrer-policy")).toBe("no-referrer");
  const cookie = sessionCookie(redeem);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);
  const admitted = await get(runtime, LANES_HOST, "/docs/", { headers: { cookie } });
  expect(admitted.status).toBe(200);

  // One use only: the same handoff cannot be replayed into a second session.
  const replayed = await get(
    runtime,
    LANES_HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(token)}&return=%2Fdocs%2F`,
  );
  expect(replayed.status).toBe(403);
  expect(replayed.headers.get("set-cookie")).toBeNull();

  const popupToken = handoffToken(LANES_HOST, LANES_SPACE, ["password:pwd_page_test"]);
  const popup = await get(
    runtime,
    LANES_HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(popupToken)}&return=%2Fdocs%2F&display=popup`,
  );
  expect(popup.status).toBe(200);
  expect(popup.headers.get("set-cookie") ?? "").toContain(RECORDED_SESSION_PREFIX);
  const html = await popup.text();
  expect(html).toContain("sf-access-complete");
  expect(html).toContain("window.close()");
  expect(html).not.toContain(popupToken);
});

test("a public Grant retains a signed-in account identity without team membership", async () => {
  const token = handoffToken(PUBLIC_HOST, PUBLIC_SPACE, [PUBLIC_AUTHORITY], {
    principal: "account:usr_public_voter",
    profile: {
      name: "Public Voter",
      avatar_url: "https://gravatar.com/avatar/public-voter",
    },
  });
  const redeem = await get(
    runtime,
    PUBLIC_HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(token)}&return=%2F`,
  );
  expect(redeem.status).toBe(303);
  expect(redeem.headers.get("location")).toBe("/");

  const cookie = sessionCookie(redeem);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);
  expect(sessionPayload(cookie)).toMatchObject({
    principal: "account:usr_public_voter",
    profile: {
      name: "Public Voter",
      avatar_url: "https://gravatar.com/avatar/public-voter",
    },
    authorities: [{ r: PUBLIC_AUTHORITY }],
  });
  expect(sessionRecords(PUBLIC_SPACE)).toHaveLength(1);
  expect((await get(runtime, PUBLIC_HOST, "/", { headers: { cookie } })).status).toBe(200);
});

// The shape of a share link: the page you want with the secret appended,
// returned as the response. One request, 200, content. A link an agent cannot
// follow with one request is not a link.
test("a share token on the URL returns that page in the same response", async () => {
  const before = exchangeRequests.length;
  const opened = await get(runtime, LANES_HOST, `/docs/guide/?__=${LINK_TOKEN}`);
  expect(opened.status).toBe(200);
  expect(await opened.text()).toContain(`guide of ${LANES_SPACE}`);

  // One control-plane call and no browser hop: nothing here asks the client to
  // go anywhere.
  expect(
    exchangeRequests
      .slice(before)
      .filter((request) => request.pathname === "/acquire/runtime-page/link"),
  ).toHaveLength(1);
  expect(opened.headers.get("location")).toBeNull();

  // The secret is in the URL, so the URL must not travel: no shared cache, no
  // Referer to the third-party assets this page loads.
  expect(opened.headers.get("cache-control")).toBe("private, no-store");
  expect(opened.headers.get("referrer-policy")).toBe("no-referrer");

  // The cookie rides the same response, which lets the token drop out of every
  // later URL.
  const cookie = sessionCookie(opened);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);
  const followed = await get(runtime, LANES_HOST, "/docs/", { headers: { cookie } });
  expect(followed.status).toBe(200);

  // The link's stored landing path does not override the requested URL. The
  // exchange fake answers this token with return: "/docs/", and the guide was
  // still served above.
  const deep = await get(runtime, LANES_HOST, `/docs/?__=${LINK_TOKEN}`);
  expect(deep.status).toBe(200);
  expect(await deep.text()).toContain(`docs of ${LANES_SPACE}`);

  // A compiled canonical redirect happens after a successful exchange. It keeps
  // ordinary query state and the Link token, so a cookie-less agent following
  // the Location still lands on the page; only off-origin targets shed the
  // token. The session cookie still rides this response for cookie clients.
  const canonical = await get(runtime, LANES_HOST, `/docs?view=compact&__=${LINK_TOKEN}&page=2`);
  expect(canonical.status).toBe(308);
  expect(canonical.headers.get("location")).toBe(`/docs/?view=compact&__=${LINK_TOKEN}&page=2`);
  const canonicalCookie = sessionCookie(canonical);
  expect(canonicalCookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);
  const canonicalFollow = await get(runtime, LANES_HOST, "/docs/?view=compact&page=2", {
    headers: { cookie: canonicalCookie },
  });
  expect(canonicalFollow.status).toBe(200);
  expect(await canonicalFollow.text()).toContain(`docs of ${LANES_SPACE}`);
});

// The older reserved-path form, kept so links minted before the query lane keep
// resolving. It still costs the redirect the query lane deleted.
test("an access URL sets a session and redirects to a clean live URL", async () => {
  const before = exchangeRequests.length;
  const opened = await get(runtime, LANES_HOST, `/__/${LINK_TOKEN}`);
  expect(opened.status).toBe(303);
  expect(opened.headers.get("location")).toBe("/docs/");
  // The response hands out the host session cookie, so it is private as well
  // as uncacheable.
  expect(opened.headers.get("cache-control")).toBe("private, no-store");
  expect(opened.headers.get("referrer-policy")).toBe("no-referrer");
  expect(await opened.text()).toBe("");
  const cookie = sessionCookie(opened);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);

  const call = exchangeRequests
    .slice(before)
    .find((request) => request.pathname === "/acquire/runtime-page/link");
  expect(call?.headers["spacefast-runtime-exchange"]).toBe(EXCHANGE_CREDENTIAL);
  expect(call?.headers["spacefast-visitor-ip"]).toBeDefined();
  expect(call?.form.get("host")).toBe(LANES_HOST);
  expect(call?.form.get("landingPath")).toBeNull();
  expect(call?.form.get("token")).toBe(LINK_TOKEN);

  // The cookie carries the visitor from there, with no token in later URLs.
  const followed = await get(runtime, LANES_HOST, "/docs/guide/", { headers: { cookie } });
  expect(followed.status).toBe(200);

  const head = await get(runtime, LANES_HOST, `/__/${LINK_TOKEN}`, { method: "HEAD" });
  expect(head.status).toBe(303);
  expect(head.headers.get("location")).toBe("/docs/");
});

// A link says what the holder may do; it never says who they are. When the
// Space knows accounts, one silent account bounce is chained in before the
// clean URL so a signed-in member arriving by link stops being a guest.
test("a link arrival chains one silent account probe before the clean URL", async () => {
  const opened = await get(runtime, CHAIN_HOST, `/__/${CHAIN_LINK_TOKEN}`);
  expect(opened.status).toBe(303);
  const location = opened.headers.get("location") ?? "";
  expect(location.startsWith(ACCOUNT_URL)).toBe(true);
  expect(location).toContain("silent=1");
  expect(location).toContain(`host=${CHAIN_HOST}`);
  expect(location).toContain("return=%2Fdocs%2F");
  const cookie = sessionCookie(opened);
  expect(cookie).toStartWith(`__Host-spacefast_session=${RECORDED_SESSION_PREFIX}`);

  // The link already admitted: the detour is about identity, not access.
  const admitted = await get(runtime, CHAIN_HOST, "/docs/", { headers: { cookie } });
  expect(admitted.status).toBe(200);
  expect(await admitted.text()).toContain(`docs of ${CHAIN_SPACE}`);

  // Exactly one probe per browser: the second arrival carries the recorded
  // check forward across the session rotation and lands on the clean URL.
  const again = await get(runtime, CHAIN_HOST, `/__/${CHAIN_LINK_TOKEN}`, {
    headers: { cookie },
  });
  expect(again.status).toBe(303);
  expect(again.headers.get("location")).toBe("/docs/");
});

test("X-SF-Authorization admits access and owner API tokens", async () => {
  const before = exchangeRequests.length;
  const linked = await get(runtime, LANES_HOST, "/docs/", {
    headers: {
      authorization: "Bearer customer-application-token",
      "x-sf-authorization": `Bearer ${LINK_TOKEN}`,
    },
  });
  expect(linked.status).toBe(200);
  expect(await linked.text()).toContain("docs of spc_access_page_lanes");
  expect(linked.headers.get("set-cookie")).toBeNull();
  const call = exchangeRequests
    .slice(before)
    .find((request) => request.pathname === "/acquire/runtime-page/token");
  expect(call?.form.get("landingPath")).toBe("/docs/");
  expect(call?.form.get("token")).toBe(LINK_TOKEN);

  const owner = await get(runtime, LANES_HOST, "/docs/", {
    headers: { "x-sf-authorization": `Bearer ${API_TOKEN}` },
  });
  expect(owner.status).toBe(200);
  expect(owner.headers.get("set-cookie")).toBeNull();
});

test("revoking the Grant behind a link closes the sessions it opened", async () => {
  const admitted = await get(runtime, LANES_HOST, `/__/${LINK_TOKEN}`);
  const cookie = sessionCookie(admitted);
  expect((await get(runtime, LANES_HOST, "/docs/", { headers: { cookie } })).status).toBe(200);
  const recordPath = sessionRecordPath(LANES_SPACE, cookie);
  expect(existsSync(recordPath)).toBe(true);
  const opened = sessionPayload(cookie);

  linkRevoked = true;
  await putRoute(runtime, LANES_SPACE, "production", {
    version_id: LANES_VERSION,
    config: accessConfig({
      spaceId: LANES_SPACE,
      // A revoked Link is a new Grant generation and the Space's access
      // generation moves with it. That tuple change pulls a live session off
      // the hot lane and re-checks the generation hashes it carries, so every
      // session with the old authority stops here.
      grants: lanesGrants(2),
      generation: 1,
      accessPage: lanesAccessPage(),
    }),
  });
  try {
    const denied = await get(runtime, LANES_HOST, "/docs/", { headers: { cookie } });
    expect(denied.status).toBe(403);
    // The Grant is gone, so the session it opened is too and its revocation
    // record retires with it. The identity the session carried moves onto the
    // stateless session, so a comment thread does not change author because a
    // link expired.
    expect(existsSync(recordPath)).toBe(false);
    const replacement = sessionCookie(denied);
    expect(replacement).toStartWith(`__Host-spacefast_session=${STATELESS_SESSION_PREFIX}`);
    expect(sessionPayload(replacement).sid).toBe(opened.sid);
    // And it is only an identity: it opens nothing the revocation closed.
    expect(
      (await get(runtime, LANES_HOST, "/docs/", { headers: { cookie: replacement } })).status,
    ).toBe(403);

    // Same token on the query lane: a revoked link opens nothing there either,
    // and the deny stays the Space's uniform one.
    const onQuery = await get(runtime, LANES_HOST, `/docs/?__=${LINK_TOKEN}`);
    expect(onQuery.status).toBe(403);
    expect(await onQuery.text()).not.toContain(`docs of ${LANES_SPACE}`);
    expect(sessionCookie(onQuery)).toBe("");

    const before = exchangeRequests.length;
    const retried = await get(runtime, LANES_HOST, `/__/${LINK_TOKEN}`);
    expect(retried.status).toBe(404);
    expect(retried.headers.get("set-cookie")).toBeNull();
    expect(
      exchangeRequests
        .slice(before)
        .filter((request) => request.pathname === "/acquire/runtime-page/link"),
    ).toHaveLength(1);
  } finally {
    linkRevoked = false;
    await putRoute(runtime, LANES_SPACE, "production", {
      version_id: LANES_VERSION,
      config: accessConfig({
        spaceId: LANES_SPACE,
        grants: lanesGrants(),
        accessPage: lanesAccessPage(),
      }),
    });
  }
});

test("an invalid access URL never serves customer content", async () => {
  const unknown = await get(runtime, LANES_HOST, "/__/notAValidShareToken00");
  expect(unknown.status).toBe(404);
  expect(unknown.headers.get("set-cookie")).toBeNull();
  expect(unknown.headers.get("cache-control")).toBe("no-store");
  expect(unknown.headers.get("referrer-policy")).toBe("no-referrer");

  // A token that names no lane is refused by name, with a diagnostic. Sniffing
  // the shape instead drops opaque customer tokens silently, leaving the
  // visitor at the gate and the operator with nothing to see.
  const before = exchangeRequests.length;
  for (const candidateToken of ["notAValidShareToken00", "sfz_someOtherThing0000", "", "a.b.c"]) {
    const refused = await get(runtime, LANES_HOST, `/docs/?__=${candidateToken}`);
    expect(refused.status).toBe(400);
    expect(refused.headers.get("x-spacefast-access-diagnostic")).toBe("query-token-unrecognized");
    expect(await refused.text()).not.toContain(`docs of ${LANES_SPACE}`);
    expect(refused.headers.get("set-cookie")).toBeNull();
    expect(refused.headers.get("referrer-policy")).toBe("no-referrer");
  }
  // A refusal is decided locally and spends nothing: no exchange, no session.
  expect(exchangeRequests.length).toBe(before);
});

// A well-formed token the control plane does not open behaves as if the
// parameter were absent: the Space's own gate, no token error, no 500. A prober
// learns nothing about whether the token ever existed.
test("a share token that opens nothing serves the gate, not content", async () => {
  const refused = await get(runtime, LANES_HOST, `/docs/?__=${LINK_PREFIX}neverMintedToken0000`);
  expect(refused.status).toBe(403);
  const body = await refused.text();
  expect(body).not.toContain(`docs of ${LANES_SPACE}`);
  expect(body).toContain("sf-access-password-input");
  expect(sessionCookie(refused)).toBe("");
  expect(refused.headers.get("cache-control")).toBe("private, no-store");
  expect(refused.headers.get("referrer-policy")).toBe("no-referrer");
});

// The system lane: same param, different kind of secret.
test("a system view token serves the page and its same-origin assets without a session", async () => {
  const before = exchangeRequests.length;
  const recordsBefore = sessionRecords(LANES_SPACE);
  const opened = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${SYSTEM_VIEW_PREFIX}${systemViewToken()}`,
  );
  expect(opened.status).toBe(200);
  expect(await opened.text()).toContain("docs of spc_access_page_lanes");
  // The short cookie carries the same signed proof to subresources. It is not a
  // visitor session and creates no revocation record.
  const assetCookie = systemViewCookie(opened);
  expect(assetCookie).toStartWith("__Host-spacefast_system_view=");
  expect(sessionCookie(opened)).toBe("");
  expect(sessionRecords(LANES_SPACE)).toEqual(recordsBefore);
  expect(opened.headers.get("cache-control")).toBe("private, no-store");
  expect(opened.headers.get("referrer-policy")).toBe("no-referrer");
  const asset = await get(runtime, LANES_HOST, "/docs/site.css", {
    headers: { cookie: assetCookie },
  });
  expect(asset.status).toBe(200);
  expect(await asset.text()).toContain("color: #123456");

  // wp.cloud claims ordinary .js URLs before PHP can set their cache policy, so
  // the authenticated request moves to a reserved extension-safe URL that
  // re-verifies the same proof before serving.
  const protectedAsset = await get(runtime, LANES_HOST, "/docs/app-D2mDMWBM.js", {
    headers: { cookie: assetCookie },
  });
  expect(protectedAsset.status).toBe(307);
  expect(protectedAsset.headers.get("location")).toBe("/docs/app-D2mDMWBM.js;sf-private");
  expect(protectedAsset.headers.get("cache-control")).toBe("private, no-store");
  const aliasedAsset = await get(runtime, LANES_HOST, "/docs/app-D2mDMWBM.js;sf-private", {
    headers: { cookie: assetCookie },
  });
  expect(aliasedAsset.status).toBe(200);
  expect(await aliasedAsset.text()).toContain("spacefastProtectedAsset");
  expect(aliasedAsset.headers.get("cache-control")).toBe("private, no-store");
  // Verified locally against the runtime's own keys. The exchange never hears
  // about a system token.
  expect(exchangeRequests.length).toBe(before);

  // An absent scope is whole-space on purpose: a thumbnail fetcher names no
  // path. A present one is a hard boundary, including the space root.
  const scopedToken = systemViewToken({ scope: "/docs" });
  const scoped = await get(runtime, LANES_HOST, `/docs/?__=${SYSTEM_VIEW_PREFIX}${scopedToken}`);
  expect(scoped.status).toBe(200);
  const scopedCookie = systemViewCookie(scoped);
  expect(
    (await get(runtime, LANES_HOST, "/docs/guide/", { headers: { cookie: scopedCookie } })).status,
  ).toBe(200);
  expect(
    (await get(runtime, LANES_HOST, "/admin/", { headers: { cookie: scopedCookie } })).status,
  ).toBe(403);
  expect(
    (await get(runtime, LANES_HOST, `/docs/guide/?__=${SYSTEM_VIEW_PREFIX}${scopedToken}`)).status,
  ).toBe(200);
  for (const outside of ["/", "/admin/"]) {
    const refused = await get(
      runtime,
      LANES_HOST,
      `${outside}?__=${SYSTEM_VIEW_PREFIX}${scopedToken}`,
    );
    expect(refused.status).toBe(403);
    expect(refused.headers.get("set-cookie")).toBeNull();
  }
});

// The dashboard's version preview: the same stateless proof, minted with the
// one origin allowed to frame it. Without both halves — the widened
// frame-ancestors and a cookie a cross-site iframe may store and send — the
// framed page renders as a bare document with none of its assets.
test("an embed proof spends its token on one hop, then names its framer", async () => {
  const expiresAt = Math.floor(Date.now() / 1000) + 900;
  const opened = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${SYSTEM_VIEW_PREFIX}${systemViewToken({ embed: EMBED_ORIGIN, exp: expiresAt })}`,
  );
  // The exchange completes in one hop. The framed document is the CUSTOMER'S
  // page, and a token left on `location.search` is a working credential for the
  // whole private version that every script on that page can read.
  expect(opened.status).toBe(302);
  expect(opened.headers.get("location")).toBe("/docs/");
  expect(opened.headers.get("cache-control")).toBe("private, no-store");
  expect(opened.headers.get("referrer-policy")).toBe("no-referrer");
  // The echo cookie IS the token, so it dies with it instead of on the short
  // top-level window a thumbnail fetcher gets.
  const framed =
    opened.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_system_view=")) ?? "";
  expect(Number(/Max-Age=(\d+)/.exec(framed)?.[1])).toBeGreaterThan(SYSTEM_VIEW_ECHO_SECONDS);

  // What the browser actually frames is the redirected request, carrying the
  // cookie and no secret. It is that response whose CSP has to admit the framer.
  const document = await get(runtime, LANES_HOST, "/docs/", {
    headers: { cookie: framed.split(";")[0] ?? "" },
  });
  expect(document.status).toBe(200);
  expect(await document.text()).toContain("docs of spc_access_page_lanes");
  // The embed origin joins the parents the Link projection already admitted —
  // the proof widens the set, it does not replace it — and nothing else gets in.
  expect(document.headers.get("content-security-policy")).toBe(
    `frame-ancestors 'self' ${FRAME_ORIGIN} ${EMBED_ORIGIN}`,
  );

  // Permission is per-request and per-proof: the same page opened by a system
  // reader that named no framer answers in place, stays unframeable, and keeps
  // the default echo window.
  const topLevel = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${SYSTEM_VIEW_PREFIX}${systemViewToken()}`,
  );
  expect(topLevel.status).toBe(200);
  expect(topLevel.headers.get("content-security-policy")).toContain("frame-ancestors 'self'");
  expect(topLevel.headers.get("content-security-policy")).not.toContain(EMBED_ORIGIN);
  const echo = topLevel.headers
    .getSetCookie()
    .find((cookie) => cookie.startsWith("__Host-spacefast_system_view="));
  expect(Number(/Max-Age=(\d+)/.exec(echo ?? "")?.[1])).toBe(SYSTEM_VIEW_ECHO_SECONDS);
});

test("a framed preview cookie carries what a cross-site iframe requires", async () => {
  const opened = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${SYSTEM_VIEW_PREFIX}${systemViewToken({ embed: EMBED_ORIGIN })}`,
  );
  // The token is spent on the redirect; the cookie is what the frame carries.
  expect(opened.status).toBe(302);
  const framed =
    opened.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_system_view=")) ?? "";
  // A Lax cookie is neither stored nor sent in a third-party frame, and
  // Partitioned is what keeps this one out of the visitor's top-level jar.
  expect(framed).toContain("HttpOnly");
  expect(framed).toContain("Secure");
  expect(framed).toContain("SameSite=None");
  expect(framed).toContain("Partitioned");

  // The whole point: the page's own assets present the cookie, not the URL.
  const asset = await get(runtime, LANES_HOST, "/docs/site.css", {
    headers: { cookie: framed.split(";")[0] ?? "" },
  });
  expect(asset.status).toBe(200);
  expect(await asset.text()).toContain("color: #123456");

  // A top-level reader keeps the same-site echo it has always had.
  const topLevel = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${SYSTEM_VIEW_PREFIX}${systemViewToken()}`,
  );
  const echo =
    topLevel.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("__Host-spacefast_system_view=")) ?? "";
  expect(echo).toContain("SameSite=Lax");
  expect(echo).not.toContain("Partitioned");
});

test("a private Frame proof is origin, target, revision, and Link-resource bound", async () => {
  const recordsBefore = sessionRecords(LANES_SPACE);
  const opened = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${FRAME_SESSION_PREFIX}${frameSessionToken()}`,
  );
  expect(opened.status).toBe(200);
  expect(opened.headers.get("cache-control")).toBe("private, no-store");
  expect(opened.headers.get("content-security-policy")).toBe(
    `frame-ancestors 'self' ${FRAME_ORIGIN}`,
  );
  const cookie = frameSessionCookie(opened);
  expect(cookie).toStartWith("__Host-spacefast_frame=");
  expect(frameSessionSetCookie(opened)).toContain("; Secure; Partitioned");
  expect(frameSessionSetCookie(opened)).toContain("; SameSite=None;");
  expect(sessionCookie(opened)).toBe("");
  expect(sessionRecords(LANES_SPACE)).toEqual(recordsBefore);

  const scopedAsset = await get(runtime, LANES_HOST, "/docs/site.css", {
    headers: { cookie },
  });
  expect(scopedAsset.status).toBe(200);
  expect(scopedAsset.headers.get("cache-control")).toBe("private, no-store");

  // This is the short-lived session proof, not the one-use Link acquisition.
  // It must replay for document reloads and assets until its exact expiry.
  const replayed = await get(
    runtime,
    LANES_HOST,
    `/docs/?__=${FRAME_SESSION_PREFIX}${frameSessionToken()}`,
  );
  expect(replayed.status).toBe(200);
  expect(replayed.headers.get("cache-control")).toBe("private, no-store");

  // Fetch metadata is not authority. A same-origin request outside the Link's
  // resources stays closed even when it looks like a stylesheet subrequest.
  const arbitrarySubresource = await get(runtime, LANES_HOST, "/admin/", {
    headers: { cookie, "sec-fetch-dest": "style", "sec-fetch-mode": "no-cors" },
  });
  expect(arbitrarySubresource.status).toBe(403);

  // Each row is a separate binding, and each fails alone for its own reason.
  const refusals: Array<[string, string]> = [
    [
      "a parent origin the Link never named",
      frameSessionToken({ origin: "https://other.example" }),
    ],
    ["a superseded Link revision", frameSessionToken({ linkRevision: FRAME_LINK_REVISION - 1 })],
    [
      "a target this host does not serve",
      frameSessionToken({ target: { kind: "branch", branch: "preview" } }),
    ],
    ["a rotated session version", frameSessionToken({ sessionVersion: 1 })],
    ["an expired proof", frameSessionToken({ exp: Math.floor(Date.now() / 1000) - 1 })],
  ];
  for (const [reason, token] of refusals) {
    const refused = await get(runtime, LANES_HOST, `/docs/?__=${FRAME_SESSION_PREFIX}${token}`);
    expect(`${reason}: ${String(refused.status)}`).toBe(`${reason}: 403`);
    expect(frameSessionCookie(refused)).toBe("");
  }
});

test("a public Frame path keeps its clean shared-cache response and projects only CSP", async () => {
  const response = await get(runtime, PUBLIC_HOST, "/docs/");
  expect(response.status).toBe(200);
  expect(response.headers.get("cache-control")).not.toContain("no-store");
  expect(response.headers.get("vary")).toBeNull();
  expect(response.headers.get("set-cookie")).toBeNull();
  expect(response.headers.get("content-security-policy")).toBe(
    `frame-ancestors 'self' ${FRAME_ORIGIN}`,
  );

  const outside = await get(runtime, PUBLIC_HOST, "/admin/");
  expect(outside.status).toBe(200);
  expect(outside.headers.get("content-security-policy")).toBeNull();
});

const systemViewRefusals: Array<{ name: string; token: () => string }> = [
  {
    name: "expired",
    token: () => systemViewToken({ exp: Math.floor(Date.now() / 1000) - 600 }),
  },
  { name: "minted for another host", token: () => systemViewToken({}, SILENT_HOST) },
  { name: "minted for another space", token: () => systemViewToken({}, LANES_HOST, SILENT_SPACE) },
  { name: "carrying no system purpose", token: () => systemViewToken({ purpose: undefined }) },
  {
    name: "claiming more than page.view",
    token: () => systemViewToken({ capabilities: ["page.view", "access.manage"] }),
  },
  {
    name: "minted before a session-version rotation",
    token: () => systemViewToken({ sessionVersion: 1 }),
  },
  // An explicit-but-unusable scope fails closed: it must never widen to the
  // whole Space the way an absent scope does.
  { name: "carrying an unreadable scope", token: () => systemViewToken({ scope: "docs/../.." }) },
  // Same shape as an unreadable scope, one directive over: a signed claim this
  // engine cannot honour must fail the token, never degrade to "no framer".
  {
    name: "carrying an embed claim that is not an origin",
    token: () => systemViewToken({ embed: "javascript:alert(1)" }),
  },
  { name: "carrying a non-string scope", token: () => systemViewToken({ scope: 7 }) },
  {
    name: "a redeem handoff presented as a view token",
    token: () => handoffToken(LANES_HOST, LANES_SPACE, ["password:pwd_page_test"]),
  },
];

test.each(systemViewRefusals)("a system view token $name opens nothing", async (entry) => {
  const refused = await get(runtime, LANES_HOST, `/docs/?__=${SYSTEM_VIEW_PREFIX}${entry.token()}`);
  // Exactly as if the parameter were absent: the gate, and no session.
  expect(refused.status).toBe(403);
  expect(refused.headers.get("set-cookie")).toBeNull();
  expect(refused.headers.get("cache-control")).toBe("private, no-store");
});

test("a system view token can never be redeemed into a session", async () => {
  const redeem = await get(
    runtime,
    LANES_HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(systemViewToken())}&return=%2Fdocs%2F`,
  );
  expect(redeem.status).toBe(403);
  expect(redeem.headers.get("set-cookie")).toBeNull();
});
