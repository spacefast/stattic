// Access enforcement against the v4 engine (contracts §7, §15, §16).
//
// The v4 session is the cookie: `sfv2_<base64url(claims)>.<hmac>`, signed with a
// key derived from the Space's runtime exchange credential. Authorities, their
// generations, the [access_gen, session_ver] tuple and the expiry all ride in
// the value; the hot path verifies one HMAC and reads NO file (D120). The only
// server-side artifact is a small revocation record at
// `spaces/<s>/sessions/<sid>.json`, consulted exclusively on the cold lane —
// which is what makes exact revocation possible without a global logout (D85).
//
// Because the signing key IS the exchange credential, this file mints and edits
// cookies the way the runtime does (see `encodeSession`) instead of poking at a
// session store that no longer exists. Every fixture therefore has to publish an
// access-page exchange, or no session can be minted for it at all.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { createHash, createHmac, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  deploy,
  type EdgePurgeCall,
  edgePurgeCalls,
  get,
  journalRecords,
  PHP_BINARY,
  postAccessCallback,
  putRoute,
  signEd25519Jwt,
  spaceRoot,
  startRuntime,
  storagePath,
  type Runtime,
  visitorIssuer,
} from "./harness.ts";

let runtime: Runtime;
let logoutServer: ReturnType<typeof Bun.serve>;
let logoutOrigin = "http://127.0.0.1";
const collaborationLogoutCalls: Array<Record<string, string>> = [];
let failCollaborationLogout = false;
let collaborationLogoutMissing = false;

// An external audience is (issuer, subject), and the reference the engine
// derives for it hashes both (_stattic_grant_audience_reference). The subject is
// unrecoverable from the reference, so fixtures mint the pair here and the
// projection builder looks the subject back up.
const EXTERNAL_IDENTITY_ISSUER = "https://identity.example/oidc";
const externalSubjectByReference = new Map<string, string>();
function externalAuthorityReference(subject: string): string {
  const reference = `external:${createHash("sha256")
    .update(`${EXTERNAL_IDENTITY_ISSUER}\0${subject}`)
    .digest("hex")}`;
  externalSubjectByReference.set(reference, subject);
  return reference;
}

const PRIVATE_HOST = "private.access.test";
const PRIVATE_VERSION_HOST = "private-version.access.test";
const OTHER_PRIVATE_HOST = "other-private.access.test";
const PRIVATE_SPACE = "spc_access_private";
const PRIVATE_VERSION = "ver_access_private";
const PUBLIC_HOST = "public.access.test";
const PUBLIC_SPACE = "spc_access_public";
const PUBLIC_VERSION = "ver_access_public";
const LANELESS_HOST = "laneless.access.test";
const LANELESS_SPACE = "spc_access_laneless";
const LANELESS_VERSION = "ver_access_laneless";
const BRANCH_LIVE_HOST = "branch-live.access.test";
const BRANCH_HOST = "branch-preview.access.test";
const BRANCH_SPACE = "spc_access_branch";
const BRANCH_VERSION = "ver_access_branch";
const BRANCH_CLAIM_ID = "clm_access_branch";
const PATH_HOST = "paths.access.test";
const PATH_SPACE = "spc_access_paths";
const PATH_VERSION = "ver_access_paths";
const CACHE_HOST = "cache.access.test";
// Version-pinned alias, `v<number>--` shaped like production immutable hosts:
// a content publish must never purge it; the public→private sweep must.
const CACHE_VERSION_HOST = "v7--cache.access.test";
const CACHE_SPACE = "spc_access_cache";
const CACHE_VERSION = "ver_access_cache";
const SCOPED_HOST = "scoped.access.test";
const SCOPED_SPACE = "spc_access_scoped";
const SCOPED_VERSION = "ver_access_scoped";
const SESSION_HOST = "session.access.test";
const SESSION_SPACE = "spc_access_session";
const SESSION_VERSION = "ver_access_session";
const AUTHORITY_LRU_HOST = "authority-lru.access.test";
const AUTHORITY_LRU_SPACE = "spc_access_authority_lru";
const AUTHORITY_LRU_VERSION = "ver_access_authority_lru";
const BOUNDARY_HOST = "boundary.access.test";
const BOUNDARY_SPACE = "spc_access_boundary";
const BOUNDARY_VERSION = "ver_access_boundary";
const ALLOWED_FALLBACK_HOST = "allowed-fallback.access.test";
const ALLOWED_NEAREST_HOST = "allowed-nearest.access.test";
const CREDENTIAL_SCOPE_HOST = "credential-scope.access.test";
const CREDENTIAL_SCOPE_SPACE = "spc_access_credential_scope";
const CREDENTIAL_SCOPE_VERSION = "ver_access_credential_scope";

const EXCHANGE_CREDENTIAL = "runtime-exchange-credential-0123456789abcdef";
const COOKIE_NAME = "spacefast_session_dev";
const SESSION_PREFIX = "sfv2_";
const ANONYMOUS_PREFIX = "sfa1_";
const EDGE_CACHE_HEADER = "a8c-edge-cache";
// access-rules.php: SPACEFAST_ACCESS_SESSION_*.
const SESSION_IDLE_SECONDS = 604_800;
const SESSION_ABSOLUTE_SECONDS = 2_592_000;
const SESSION_TOUCH_INTERVAL_SECONDS = 21_600;
const SESSION_CLAIM_TTL_SECONDS = 900;
const SESSION_AUTHORITY_LIMIT = 16;
const SESSION_CLOCK_HEADER = "x-spacefast-test-access-session-now";
// The runtime never reads a request's scheme — X-Forwarded-Proto is
// attacker-controlled (contracts §16) — so it builds its own origin from the
// SPACEFAST_INSECURE_COOKIES config the harness sets, which is http here. That
// origin is what the logout CSRF check compares against and what the
// collaboration revoke's returnUrl carries.
const RUNTIME_SCHEME = "http";
// The private Space's own live origin, as its Collab overlay carries it.
const SPACE_LIVE_ORIGIN = `${RUNTIME_SCHEME}://${PRIVATE_HOST}`;

const keyPair = generateKeyPairSync("ed25519");
const visitorJwk = keyPair.publicKey.export({ format: "jwk" });
const issuer = visitorIssuer(keyPair.publicKey);

function spaceForHost(host: string): string {
  const groups: Array<[string, string[]]> = [
    [PRIVATE_SPACE, [PRIVATE_HOST, PRIVATE_VERSION_HOST, OTHER_PRIVATE_HOST]],
    [PUBLIC_SPACE, [PUBLIC_HOST]],
    [LANELESS_SPACE, [LANELESS_HOST]],
    [BRANCH_SPACE, [BRANCH_LIVE_HOST, BRANCH_HOST]],
    [PATH_SPACE, [PATH_HOST]],
    [CACHE_SPACE, [CACHE_HOST]],
    [SCOPED_SPACE, [SCOPED_HOST]],
    [SESSION_SPACE, [SESSION_HOST]],
    [AUTHORITY_LRU_SPACE, [AUTHORITY_LRU_HOST]],
    [BOUNDARY_SPACE, [BOUNDARY_HOST]],
    ["spc_access_allowed_fallback", [ALLOWED_FALLBACK_HOST]],
    ["spc_access_allowed_nearest", [ALLOWED_NEAREST_HOST]],
    [CREDENTIAL_SCOPE_SPACE, [CREDENTIAL_SCOPE_HOST]],
  ];
  return groups.find(([, hosts]) => hosts.includes(host))?.[0] ?? PRIVATE_SPACE;
}

// ---------------------------------------------------------------------------
// Route projections
// ---------------------------------------------------------------------------

type ProjectionInput = {
  mode?: "private" | "public";
  accessFence?: "none" | "ownership" | "exposure";
  accessGeneration?: number;
  grantGenerations?: Record<string, number>;
  sessionVersion?: number;
  memberRefs?: string[];
  /**
   * The access-page exchange. Present by default: its `credential` is the
   * material every `sfv2_`/`sfa1_` cookie is signed with (access-rules.php
   * `_stattic_access_session_hmac_key`), so a Space without it can hold no
   * session at all. `"stale"` is a bundle written before collaboration logout
   * existed; `false` is the lane-less Space that only ever shows the uniform
   * deny.
   */
  exchange?: boolean | "stale";
  publicConstraints?: Record<string, unknown>;
  overrides?: Array<{ scope: string; mode: "limited" | "public" }>;
  people?: Array<{ personId: string; grants: Array<{ id: string; scope: string }> }>;
  links?: Array<{
    linkId: string;
    scopes: string[];
    expiresAt: string | null;
    requireVerifiedEmail?: boolean;
    target?: { kind: "live" } | { kind: "all_versions" };
  }>;
  claimPreview?: { claimId: string; expiresAt: string };
};

function projection(input: ProjectionInput = {}) {
  const grants: Array<Record<string, unknown>> = [];
  const resource = (scope: string) => (scope === "/" ? "/**" : `${scope}/**`);
  const exclusions = (scope: string) =>
    (input.overrides ?? [])
      .filter(
        (override) =>
          override.scope !== scope && (scope === "/" || override.scope.startsWith(`${scope}/`)),
      )
      .map((override) => resource(override.scope));
  const addLive = (
    id: string,
    audience: Record<string, string>,
    scope: string,
    constraints: Record<string, unknown> = {},
    target: { kind: "live" } | { kind: "all_versions" } = { kind: "live" },
  ) =>
    grants.push({
      id,
      generation: input.grantGenerations?.[id] ?? 1,
      audience,
      resources: { include: [resource(scope)], exclude: exclusions(scope) },
      capabilities: ["page.view", "comments.read"],
      constraints,
      target,
      source: { kind: "managed", reference: id },
    });

  if ((input.mode ?? "private") === "public") {
    addLive("grt_public_root", { kind: "public" }, "/", input.publicConstraints ?? {});
  }
  for (const override of input.overrides ?? []) {
    if (override.mode === "public") {
      addLive(`grt_public_${override.scope}`, { kind: "public" }, override.scope);
    }
  }
  for (const person of input.people ?? []) {
    for (const personGrant of person.grants) {
      addLive(personGrant.id, { kind: "person", personId: person.personId }, personGrant.scope);
    }
  }
  for (const link of input.links ?? []) {
    for (const scope of link.scopes) {
      addLive(
        `grt_${link.linkId}_${scope}`,
        { kind: "link", linkId: link.linkId },
        scope,
        {
          ...(link.expiresAt ? { expiresAt: link.expiresAt } : {}),
          ...(link.requireVerifiedEmail ? { requireVerifiedEmail: true } : {}),
        },
        link.target,
      );
    }
  }
  for (const reference of input.memberRefs ?? []) {
    const audience = reference.startsWith("member:")
      ? { kind: "external", issuer: "spacefast-membership", subject: reference.slice(7) }
      : {
          kind: "external",
          issuer: EXTERNAL_IDENTITY_ISSUER,
          subject: externalSubjectByReference.get(reference) ?? reference,
        };
    for (const target of [{ kind: "live" }, { kind: "all_versions" }]) {
      grants.push({
        id: `grt_manager_${reference}_${target.kind}`,
        generation: input.grantGenerations?.[`grt_manager_${reference}_${target.kind}`] ?? 1,
        audience,
        resources: { include: ["/**"], exclude: [] },
        capabilities: [
          "page.view",
          "comments.read",
          "comments.write",
          "content.publish",
          "access.manage",
        ],
        constraints: {},
        target,
        source: { kind: "system", reference },
      });
    }
  }
  if (input.claimPreview) {
    grants.push({
      id: "grt_claim_preview",
      generation: input.grantGenerations?.grt_claim_preview ?? 1,
      audience: { kind: "external", issuer: "claim-preview", subject: input.claimPreview.claimId },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view", "comments.read"],
      constraints: { expiresAt: input.claimPreview.expiresAt },
      target: { kind: "all_versions" },
      source: { kind: "system", reference: "claim-preview" },
    });
  }

  const exchange = input.exchange ?? true;
  const generation = (input.accessGeneration ?? 0) + (input.sessionVersion ?? 0);
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
      sessionVersion: input.sessionVersion ?? 0,
      fence: input.accessFence ?? "none",
      acquireUrl: "https://access.spacefast.test/acquire/runtime-test",
      ...(exchange === false
        ? {}
        : {
            accessPage: {
              displayName: null,
              accountUrl: null,
              connections: [],
              exchange: {
                passwordUrl: `${logoutOrigin}/acquire/runtime-test/password`,
                tokenUrl: `${logoutOrigin}/acquire/runtime-test/token`,
                requestUrl: `${logoutOrigin}/acquire/runtime-test/request`,
                ...(exchange === "stale"
                  ? {}
                  : { logoutUrl: `${logoutOrigin}/runtime/collaboration-sessions/revoke` }),
                credential: EXCHANGE_CREDENTIAL,
              },
            },
          }),
      spaceClaimed: true,
      grants,
    },
  };
}

/**
 * The Public fixture's resting state. The member Grant is what lets this Space
 * mint a session at all — an unconstrained Public Grant admits everyone and
 * therefore proves nothing about identity — and it never makes the anonymous
 * response private, because a Public Grant with no constraints stays
 * shared-cacheable.
 */
function publicBaseConfig() {
  return projection({ mode: "public", memberRefs: ["member:mem_public_owner"] });
}

// ---------------------------------------------------------------------------
// Handoff tokens and the redeem lane
// ---------------------------------------------------------------------------

function callbackToken(
  host: string,
  authorities: string[],
  {
    audience = spaceForHost(host),
    tokenHost = host,
    emailVerified = false,
    // Only the account lane states a principal. Every other lane proves a
    // capability and says nothing about who is holding it.
    principal,
    generation = 0,
  }: {
    audience?: string | null;
    tokenHost?: string | null;
    emailVerified?: boolean;
    principal?: string;
    generation?: number;
  } = {},
) {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(keyPair.privateKey, issuer.kid, {
    sub: authorities[0],
    purpose: "handoff",
    grants: authorities,
    authorities,
    iss: "spacefast-api",
    ...(principal === undefined ? {} : { principal }),
    ...(audience === null ? {} : { aud: audience }),
    ...(tokenHost === null ? {} : { host: tokenHost }),
    spaceId: spaceForHost(host),
    generation,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    emailVerified,
    iat: now,
    nbf: now,
    exp: now + 300,
    jti: randomUUID(),
  });
}

function setCookieValue(response: Response): string {
  return (response.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
}

async function openAuthorities(
  host: string,
  authorities: string[],
  options: {
    cookie?: string;
    emailVerified?: boolean;
    principal?: string;
    now?: number;
  } = {},
): Promise<string> {
  const token = callbackToken(host, authorities, {
    emailVerified: options.emailVerified,
    principal: options.principal,
  });
  const callback = await postAccessCallback(runtime, host, token, "/", options.cookie);
  expect(callback.status).toBe(303);
  const cookie = setCookieValue(callback);
  expect(cookie).toStartWith(`${COOKIE_NAME}=${SESSION_PREFIX}`);
  return cookie;
}

function sessionClockHeaders(now: number): Record<string, string> {
  return { [SESSION_CLOCK_HEADER]: String(now) };
}

// ---------------------------------------------------------------------------
// The cookie IS the session: mint, read and edit it the way the runtime does
// (access-rules.php `_stattic_access_session_encode` / `_decode`).
// ---------------------------------------------------------------------------

type SessionAuthority = {
  reference: string;
  generation: string;
  emailVerifiedUntil: number | null;
  openedAt: number;
  lastSeenAt: number;
};

type SessionClaims = {
  v: number;
  sid: string;
  principal: string;
  authorities: SessionAuthority[];
  sessionVersion: number;
  accessGeneration: number;
  spaceId: string;
  host: string;
  iat: number;
  exp: number;
  sid?: string;
  anonymousId?: string;
  identityCheckedAt?: number;
  [key: string]: unknown;
};

// SPACEFAST_ACCESS_SESSION_AUTHORITY_KEYS: entries travel under one-letter keys
// because a Set-Cookie has ~4096 bytes for everything.
const AUTHORITY_KEYS = [
  ["reference", "r"],
  ["generation", "g"],
  ["emailVerifiedUntil", "e"],
  ["openedAt", "o"],
  ["lastSeenAt", "s"],
] as const;

function sessionSigningKey(label: string): string {
  return createHmac("sha256", EXCHANGE_CREDENTIAL).update(label).digest("hex");
}

function cookieCredential(cookie: string): string {
  return cookie.split("=", 2)[1] ?? "";
}

function decodeSession(cookie: string): SessionClaims {
  const credential = cookieCredential(cookie);
  if (!credential.startsWith(SESSION_PREFIX)) {
    throw new Error(`not a runtime session cookie: ${credential.slice(0, 12)}…`);
  }
  const body = credential.slice(SESSION_PREFIX.length);
  const encoded = body.slice(0, body.lastIndexOf("."));
  const claims = JSON.parse(Buffer.from(encoded, "base64url").toString("utf8")) as Record<
    string,
    unknown
  >;
  const packed = Array.isArray(claims.authorities) ? claims.authorities : [];
  return {
    ...claims,
    authorities: packed.map((row) => {
      const entry = row as Record<string, unknown>;
      const unpacked: Record<string, unknown> = { emailVerifiedUntil: null };
      for (const [long, short] of AUTHORITY_KEYS) {
        if (short in entry) unpacked[long] = entry[short];
      }
      return unpacked as SessionAuthority;
    }),
  } as SessionClaims;
}

function encodeSession(host: string, claims: SessionClaims): string {
  const packed = claims.authorities.map((entry) => {
    const row: Record<string, unknown> = {};
    for (const [long, short] of AUTHORITY_KEYS) {
      if (entry[long] !== null && entry[long] !== undefined) row[short] = entry[long];
    }
    return row;
  });
  const encoded = Buffer.from(JSON.stringify({ ...claims, authorities: packed }), "utf8").toString(
    "base64url",
  );
  const signature = createHmac("sha256", sessionSigningKey("spacefast-access-session"))
    .update(`${host}\0${encoded}`)
    .digest("hex");
  return `${COOKIE_NAME}=${SESSION_PREFIX}${encoded}.${signature}`;
}

/** Rewrites a cookie's claims and re-signs it, exactly as the runtime would. */
function editSession(host: string, cookie: string, edit: (claims: SessionClaims) => void): string {
  const claims = decodeSession(cookie);
  edit(claims);
  return encodeSession(host, claims);
}

function authorityIn(claims: SessionClaims, reference: string): SessionAuthority {
  const entry = claims.authorities.find((candidate) => candidate.reference === reference);
  if (!entry) throw new Error(`session carries no authority ${reference}`);
  return entry;
}

function authorityReferences(cookie: string): string[] {
  return decodeSession(cookie)
    .authorities.map((entry) => entry.reference)
    .toSorted();
}

// The authority-less form of the same cookie: a visitor who proved nothing.
// Built here the way a browser presents it so the runtime's reader is exercised
// end to end rather than trusted. `sv` is the session-version half of the gen
// tuple (D30, never summed with access_gen): the stateless session is re-keyed
// on it so the "sign everyone out" lever reaches a visitor who holds no
// authority, and a payload that omits it verifies against nothing.
function anonymousSessionCookie(
  host: string,
  payload: Record<string, unknown>,
  sessionVersion = 0,
): string {
  const encoded = Buffer.from(JSON.stringify({ ...payload, sv: sessionVersion }), "utf8").toString(
    "base64url",
  );
  const signature = createHmac("sha256", sessionSigningKey("spacefast-anonymous-session"))
    .update(`${host}\0${encoded}`)
    .digest("hex");
  return `${COOKIE_NAME}=${ANONYMOUS_PREFIX}${encoded}.${signature}`;
}

function anonymousSessionPayload(cookie: string): Record<string, unknown> {
  const credential = cookieCredential(cookie);
  const encoded = credential.slice(ANONYMOUS_PREFIX.length).split(".")[0] ?? "";
  return JSON.parse(Buffer.from(encoded, "base64url").toString("utf8")) as Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// The revocation record (contracts §7 / D85): spaces/<s>/sessions/<sid>.json
// ---------------------------------------------------------------------------

function sessionsDirectory(spaceId: string): string {
  return path.join(spaceRoot(runtime, spaceId), "sessions");
}

function sessionRecordPath(spaceId: string, cookie: string): string {
  return path.join(sessionsDirectory(spaceId), `${decodeSession(cookie).sid}.json`);
}

function sessionRecordFiles(spaceId: string): string[] {
  const directory = sessionsDirectory(spaceId);
  return existsSync(directory)
    ? readdirSync(directory).filter((file) => /^[a-f0-9]{64}\.json$/.test(file))
    : [];
}

async function pollUntil<T>(probe: () => T | null, label: string): Promise<T> {
  const deadline = Date.now() + 5_000;
  for (;;) {
    const result = probe();
    if (result !== null) return result;
    if (Date.now() >= deadline) throw new Error(`timed out waiting for ${label}`);
    await Bun.sleep(10);
  }
}

// ---------------------------------------------------------------------------

const FIXTURE_FILES = {
  "index.html": "<h1>home</h1>\n",
  "docs/index.html": "<h1>docs</h1>\n",
  "docs/secret.html": "<h1>docs secret</h1>\n",
  "shared/index.html": "<h1>shared</h1>\n",
  "verified/index.html": "<h1>verified</h1>\n",
  "admin/index.html": "<h1>admin</h1>\n",
};

// A publisher may describe their own bytes; they may never widen the platform's
// authorization boundary. `/docs/*` deliberately asks for everything a private
// response must neutralize.
const PERMISSIVE_HEADERS = [
  "/docs/",
  "  Access-Control-Allow-Origin: *",
  "  Cross-Origin-Resource-Policy: cross-origin",
  "  Content-Security-Policy: frame-ancestors *",
  "  X-Robots-Tag: all",
].join("\n");

/**
 * The Collab overlay a Space with Comments carries. `live_url` is the only part
 * of it the framing boundary reads — the Space's own live origin, which is the
 * one origin besides the responding host that the runtime can prove belongs to
 * this Space.
 */
function collabOverlay(liveUrl: string): Record<string, unknown> {
  return {
    revision: "access-collab-1",
    config: {
      cast_api_base: "https://cast.access.test",
      comments: { live: true, preview: true, live_url: liveUrl },
    },
  };
}

beforeAll(async () => {
  logoutServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const fields = Object.fromEntries(
        [...(await request.formData()).entries()].map(([key, value]) => [key, String(value)]),
      );
      collaborationLogoutCalls.push(fields);
      if (failCollaborationLogout) {
        return Response.json({ error: { code: "unavailable" } }, { status: 503 });
      }
      if (collaborationLogoutMissing) {
        return Response.json({ error: { code: "not_found" } }, { status: 404 });
      }
      return Response.json({
        data: { clearUrl: `${logoutOrigin}/logout-cleared/${fields.sessionId ?? "missing"}` },
      });
    },
  });
  logoutOrigin = `http://${logoutServer.hostname}:${logoutServer.port}`;
  runtime = await startRuntime({
    captureEdgePurges: true,
    env: {
      PHP_CLI_SERVER_WORKERS: "8",
      SPACEFAST_RUNTIME_TEST_ACCESS_SESSION_CLOCK: "1",
    },
  });

  const fixtures: Array<{
    spaceId: string;
    versionId: string;
    hosts: string[];
    versionHosts?: string[];
    files?: Record<string, string>;
    config: ReturnType<typeof projection> & { sdk?: Record<string, unknown> };
  }> = [
    {
      spaceId: PRIVATE_SPACE,
      versionId: PRIVATE_VERSION,
      hosts: [PRIVATE_HOST, OTHER_PRIVATE_HOST],
      files: { _headers: PERMISSIVE_HEADERS },
      config: {
        ...projection({
          memberRefs: ["member:mem_owner", externalAuthorityReference("a".repeat(64))],
          links: [
            {
              linkId: "lnk_all_versions",
              scopes: ["/"],
              expiresAt: null,
              target: { kind: "all_versions" },
            },
          ],
        }),
        sdk: collabOverlay(SPACE_LIVE_ORIGIN + "/"),
      },
      versionHosts: [PRIVATE_VERSION_HOST],
    },
    {
      spaceId: PUBLIC_SPACE,
      versionId: PUBLIC_VERSION,
      hosts: [PUBLIC_HOST],
      config: publicBaseConfig(),
    },
    {
      spaceId: LANELESS_SPACE,
      versionId: LANELESS_VERSION,
      hosts: [LANELESS_HOST],
      // A scheme-relative `live_url`: origin-shaped, but not an origin. It is
      // the laundering vector the framing boundary must refuse outright.
      config: { ...projection({ exchange: false }), sdk: collabOverlay("//evil.test/") },
    },
    {
      spaceId: BRANCH_SPACE,
      versionId: BRANCH_VERSION,
      hosts: [BRANCH_LIVE_HOST],
      config: projection({
        mode: "public",
        memberRefs: ["member:mem_branch_owner"],
        claimPreview: {
          claimId: BRANCH_CLAIM_ID,
          expiresAt: new Date(Date.now() + 60 * 60_000).toISOString(),
        },
      }),
    },
    {
      spaceId: PATH_SPACE,
      versionId: PATH_VERSION,
      hosts: [PATH_HOST],
      config: projection({ mode: "public" }),
    },
    {
      spaceId: CACHE_SPACE,
      versionId: CACHE_VERSION,
      hosts: [CACHE_HOST],
      versionHosts: [CACHE_VERSION_HOST],
      config: projection({ mode: "public" }),
    },
    {
      spaceId: SCOPED_SPACE,
      versionId: SCOPED_VERSION,
      hosts: [SCOPED_HOST],
      config: projection({
        memberRefs: ["member:mem_owner"],
        overrides: [{ scope: "/docs", mode: "limited" }],
        people: [
          { personId: "per_broad", grants: [{ id: "pgr_broad", scope: "/" }] },
          { personId: "per_docs", grants: [{ id: "pgr_docs", scope: "/docs" }] },
        ],
        links: [{ linkId: "lnk_shared", scopes: ["/shared"], expiresAt: null }],
      }),
    },
    {
      spaceId: SESSION_SPACE,
      versionId: SESSION_VERSION,
      hosts: [SESSION_HOST],
      config: projection({
        memberRefs: ["member:mem_session"],
        people: [{ personId: "per_session", grants: [{ id: "pgr_session", scope: "/" }] }],
      }),
    },
    {
      spaceId: AUTHORITY_LRU_SPACE,
      versionId: AUTHORITY_LRU_VERSION,
      hosts: [AUTHORITY_LRU_HOST],
      files: Object.fromEntries(
        Array.from({ length: 17 }, (_, index) => [
          `p${index}/index.html`,
          `<h1>page ${index}</h1>\n`,
        ]),
      ),
      config: projection({
        people: Array.from({ length: 17 }, (_, index) => ({
          personId: `per_lru_${index}`,
          grants: [{ id: `pgr_lru_${index}`, scope: `/p${index}` }],
        })),
      }),
    },
    {
      spaceId: CREDENTIAL_SCOPE_SPACE,
      versionId: CREDENTIAL_SCOPE_VERSION,
      hosts: [CREDENTIAL_SCOPE_HOST],
      config: projection({
        links: [
          {
            linkId: "lnk_email_bound",
            scopes: ["/verified"],
            expiresAt: null,
            requireVerifiedEmail: true,
          },
          { linkId: "lnk_unrelated", scopes: ["/shared"], expiresAt: null },
        ],
      }),
    },
    {
      spaceId: BOUNDARY_SPACE,
      versionId: BOUNDARY_VERSION,
      hosts: [BOUNDARY_HOST],
      files: { _headers: PERMISSIVE_HEADERS },
      config: projection({
        mode: "public",
        memberRefs: ["member:mem_boundary_owner"],
        overrides: [
          { scope: "/docs", mode: "limited" },
          { scope: "/private", mode: "limited" },
        ],
      }),
    },
  ];

  for (const fixture of fixtures) {
    await deploy(runtime, {
      spaceId: fixture.spaceId,
      versionId: fixture.versionId,
      files: { ...FIXTURE_FILES, ...fixture.files },
      activate: {
        route_name: "production",
        config: fixture.config,
        production_hostnames: fixture.hosts,
        version_hostnames: (fixture.versionHosts ?? []).map((hostname) => ({
          hostname,
          version_id: fixture.versionId,
        })),
      },
    });
  }
});

afterAll(() => {
  runtime?.stop();
  logoutServer?.stop(true);
});

// ---------------------------------------------------------------------------
// Admission
// ---------------------------------------------------------------------------

test("canonical admission is private by default, host-bound, and neutralizes publisher headers", async () => {
  const anonymous = await get(runtime, PRIVATE_HOST, "/docs/");
  expect(anonymous.status).toBe(403);
  expect(anonymous.headers.get("cache-control")).toBe("private, no-store");
  expect(anonymous.headers.get("x-spacefast-version")).toBe(PRIVATE_VERSION);
  expect(await anonymous.text()).not.toContain("<h1>docs</h1>");

  const cookie = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  const admitted = await get(runtime, PRIVATE_HOST, "/docs/", { headers: { cookie } });
  expect(admitted.status).toBe(200);
  expect(await admitted.text()).toContain("<h1>docs</h1>");
  // Private bytes never enter a shared cache, and the edge is told so.
  expect(admitted.headers.get("cache-control")).toStartWith("private");
  expect(admitted.headers.get(EDGE_CACHE_HEADER)).toBe("no-cache");
  expect(admitted.headers.get("vary")).toContain("Cookie");
  // The publisher asked for `*` on all three; the platform boundary wins.
  expect(admitted.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  expect(admitted.headers.get("cross-origin-resource-policy")).toBe("same-origin");

  // An all-Versions Link covers immutable Version hosts ONLY — never the live
  // host, never a branch (#1983 aligned the grant contract across the engine
  // and packages/common; a live URL needs a live-target Link). The same
  // authority is refused on live and admitted on the Version host.
  const liveLinkCookie = await openAuthorities(PRIVATE_HOST, ["link:lnk_all_versions"]);
  expect(
    (await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: liveLinkCookie } })).status,
  ).toBe(403);
  const versionLinkCookie = await openAuthorities(PRIVATE_VERSION_HOST, ["link:lnk_all_versions"]);
  expect(
    (await get(runtime, PRIVATE_VERSION_HOST, "/", { headers: { cookie: versionLinkCookie } }))
      .status,
  ).toBe(200);
  // Private content is framable by the Space's OWN surfaces and nothing else.
  // `'self'` is the Collab shell framing the page it reviews; the live origin is
  // time travel framing a version host from the live Space. The equality is the
  // refusal: the publisher's `*` is gone, and no other origin — not even
  // OTHER_PRIVATE_HOST, a sibling host of this same Space that the runtime
  // cannot enumerate — is admitted.
  expect(admitted.headers.get("content-security-policy")).toBe(
    `frame-ancestors 'self' ${SPACE_LIVE_ORIGIN}`,
  );
  expect(admitted.headers.get("access-control-allow-origin")).toBeNull();

  // The cookie is signed with the host inside the message, so a sibling host
  // that shares the Space still rejects it.
  const crossHost = await get(runtime, OTHER_PRIVATE_HOST, "/docs/", { headers: { cookie } });
  expect(crossHost.status).toBe(403);
  expect(crossHost.headers.get("cache-control")).toBe("private, no-store");

  const external = await openAuthorities(PRIVATE_HOST, [
    externalAuthorityReference("a".repeat(64)),
  ]);
  expect((await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: external } })).status).toBe(
    200,
  );
});

test("a Space with no visitor lanes denies uniformly and discloses nothing", async () => {
  const denied = await get(runtime, LANELESS_HOST, "/");
  expect(denied.status).toBe(403);
  expect(denied.headers.get("cache-control")).toBe("private, no-store");
  expect(denied.headers.get("x-robots-tag")).toBe("noindex, nofollow");
  expect(denied.headers.get("cross-origin-resource-policy")).toBe("same-origin");
  // This Space's overlay carries a scheme-relative `live_url`. A value that is
  // not a parseable origin contributes nothing — the boundary narrows to
  // `'self'` rather than degrading to something an attacker chose.
  expect(denied.headers.get("content-security-policy")).toBe("frame-ancestors 'self'");
  expect(denied.headers.get("access-control-allow-origin")).toBeNull();
  const body = await denied.text();
  expect(body).toContain("This page is private");
  // No lane, no acquisition surface, no hostname echoed back to a crawler.
  expect(body).not.toContain("sf-access-request");
  expect(body).not.toContain(LANELESS_HOST);
});

test("access callback handoffs require both the Space audience and request host", async () => {
  const authorities = ["member:mem_owner"];

  const missingAudience = await postAccessCallback(
    runtime,
    PRIVATE_HOST,
    callbackToken(PRIVATE_HOST, authorities, { audience: null }),
  );
  expect(missingAudience.status).toBe(403);
  expect(missingAudience.headers.get("set-cookie")).toBeNull();

  const mismatchedAudience = await postAccessCallback(
    runtime,
    PRIVATE_HOST,
    callbackToken(PRIVATE_HOST, authorities, { audience: "spc_access_elsewhere" }),
  );
  expect(mismatchedAudience.status).toBe(403);
  expect(mismatchedAudience.headers.get("set-cookie")).toBeNull();

  const mismatchedHost = await postAccessCallback(
    runtime,
    PRIVATE_HOST,
    callbackToken(PRIVATE_HOST, authorities, { tokenHost: OTHER_PRIVATE_HOST }),
  );
  expect(mismatchedHost.status).toBe(403);
  expect(mismatchedHost.headers.get("set-cookie")).toBeNull();

  const matching = await postAccessCallback(
    runtime,
    PRIVATE_HOST,
    callbackToken(PRIVATE_HOST, authorities),
  );
  expect(matching.status).toBe(303);
  expect(matching.headers.get("referrer-policy")).toBe("no-referrer");
  expect(setCookieValue(matching)).toStartWith(`${COOKIE_NAME}=${SESSION_PREFIX}`);

  // The browser handoff reads `sf_token` from a GET and `token` from a POST
  // body. A scanner replaying the POST field name in a query admits nothing and
  // echoes nothing back; a HEAD probe is not a lane at all.
  const token = callbackToken(PRIVATE_HOST, authorities);
  for (const [method, status] of [
    ["GET", 403],
    ["HEAD", 405],
  ] as const) {
    const scanner = await get(
      runtime,
      PRIVATE_HOST,
      `/__sf/redeem?token=${encodeURIComponent(token)}&return=%2Fdocs%2F`,
      { method },
    );
    expect(scanner.status, method).toBe(status);
    expect(scanner.headers.get("set-cookie")).toBeNull();
    expect(await scanner.text()).not.toContain(token);
  }
});

test("a signed platform header admits directly without creating a browser cookie", async () => {
  const token = callbackToken(PRIVATE_HOST, ["member:mem_owner"]);
  const admitted = await get(runtime, PRIVATE_HOST, "/docs/", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  expect(admitted.status).toBe(200);
  expect(admitted.headers.get("set-cookie")).toBeNull();
  expect(admitted.headers.get("cache-control")).toStartWith("private");

  const wrongHost = await get(runtime, OTHER_PRIVATE_HOST, "/docs/", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  expect(wrongHost.status).toBe(403);
});

// ---------------------------------------------------------------------------
// The session itself (contracts §7, D85/D120)
// ---------------------------------------------------------------------------

test("the cookie decides the request and the record only revokes it", async () => {
  const cookie = await openAuthorities(SESSION_HOST, ["member:mem_session"]);
  const claims = decodeSession(cookie);
  expect(claims.spaceId).toBe(SESSION_SPACE);
  expect(claims.host).toBe(SESSION_HOST);
  expect(claims.principal).toBe("anonymous");
  expect(authorityReferences(cookie)).toEqual(["member:mem_session"]);

  const recordPath = sessionRecordPath(SESSION_SPACE, cookie);
  expect(existsSync(recordPath)).toBe(true);
  // The record holds no authority: losing it can only ever deny.
  const record = JSON.parse(readFileSync(recordPath, "utf8")) as Record<string, unknown>;
  expect(record.sid).toBe(claims.sid);
  expect(record.spaceId).toBe(SESSION_SPACE);
  expect(record).not.toHaveProperty("authorities");

  // Hot lane (D120): fresh claims read no file, so a missing record changes
  // nothing until the claims go stale.
  rmSync(recordPath, { force: true });
  const hot = await get(runtime, SESSION_HOST, "/", { headers: { cookie } });
  expect(hot.status).toBe(200);
  expect(hot.headers.get("set-cookie")).toBeNull();

  // Cold lane: aged-out claims consult the record exactly once. It is gone, so
  // THIS session ends and the browser is handed the authority-less form.
  const stale = editSession(SESSION_HOST, cookie, (value) => {
    value.exp = value.iat - 1;
  });
  const revoked = await get(runtime, SESSION_HOST, "/", { headers: { cookie: stale } });
  expect(revoked.status).toBe(403);
  expect(revoked.headers.get("set-cookie")).toContain(`${COOKIE_NAME}=${ANONYMOUS_PREFIX}`);

  // A second, live session of the same Space is untouched by that revocation.
  const survivor = await openAuthorities(SESSION_HOST, ["member:mem_session"]);
  const survivorStale = editSession(SESSION_HOST, survivor, (value) => {
    value.exp = value.iat - 1;
  });
  const renewed = await get(runtime, SESSION_HOST, "/", { headers: { cookie: survivorStale } });
  expect(renewed.status).toBe(200);
  // Its record was still there, so the cold lane re-minted rather than denied.
  const reissued = setCookieValue(renewed);
  expect(reissued).toStartWith(`${COOKIE_NAME}=${SESSION_PREFIX}`);
  const reissuedClaims = decodeSession(reissued);
  expect(reissuedClaims.sid).toBe(decodeSession(survivor).sid);
  expect(reissuedClaims.exp).toBeGreaterThan(reissuedClaims.iat);
  expect(reissuedClaims.exp - reissuedClaims.iat).toBe(SESSION_CLAIM_TTL_SECONDS);
});

test("a globally retired session version denies every cookie minted before it", async () => {
  const cookie = await openAuthorities(SESSION_HOST, ["member:mem_session"]);
  expect((await get(runtime, SESSION_HOST, "/", { headers: { cookie } })).status).toBe(200);
  try {
    await putRoute(runtime, SESSION_SPACE, "production", {
      version_id: SESSION_VERSION,
      config: projection({
        memberRefs: ["member:mem_session"],
        accessGeneration: 1,
        sessionVersion: 1,
      }),
    });
    const loggedOutEverywhere = await get(runtime, SESSION_HOST, "/", { headers: { cookie } });
    expect(loggedOutEverywhere.status).toBe(403);
    expect(loggedOutEverywhere.headers.get("cache-control")).toBe("private, no-store");
    // The record is not what denied this: session_ver is a tuple member the
    // cookie carries, checked before any file is consulted.
    expect(existsSync(sessionRecordPath(SESSION_SPACE, cookie))).toBe(true);
  } finally {
    await putRoute(runtime, SESSION_SPACE, "production", {
      version_id: SESSION_VERSION,
      config: projection({
        memberRefs: ["member:mem_session"],
        people: [{ personId: "per_session", grants: [{ id: "pgr_session", scope: "/" }] }],
      }),
    });
  }
});

test("a durable session survives an access-generation move but not losing its Grant", async () => {
  const cookie = await openAuthorities(SESSION_HOST, ["member:mem_session"]);
  try {
    // Ordinary Grant edits rotate the generation so stale handoffs cannot be
    // redeemed. A browser session follows its exact still-live authority
    // instead — this is what lets a scoped Link created before a Space is
    // claimed keep working after the claim.
    await putRoute(runtime, SESSION_SPACE, "production", {
      version_id: SESSION_VERSION,
      config: projection({ memberRefs: ["member:mem_session"], accessGeneration: 1 }),
    });
    const survived = await get(runtime, SESSION_HOST, "/", { headers: { cookie } });
    expect(survived.status).toBe(200);
    // The gen moved, so the cold lane re-minted the cookie against it.
    expect(decodeSession(setCookieValue(survived)).accessGeneration).toBe(1);

    // Withdraw the Grant entirely: the authority no longer resolves, so the
    // re-mint retains nothing and the session retires.
    await putRoute(runtime, SESSION_SPACE, "production", {
      version_id: SESSION_VERSION,
      config: projection({ accessGeneration: 2 }),
    });
    const removed = await get(runtime, SESSION_HOST, "/", { headers: { cookie } });
    expect(removed.status).toBe(403);
    expect(existsSync(sessionRecordPath(SESSION_SPACE, cookie))).toBe(false);
  } finally {
    await putRoute(runtime, SESSION_SPACE, "production", {
      version_id: SESSION_VERSION,
      config: projection({
        memberRefs: ["member:mem_session"],
        people: [{ personId: "per_session", grants: [{ id: "pgr_session", scope: "/" }] }],
      }),
    });
  }
});

test("logout revokes only the presented session and fails closed when the record survives", async () => {
  const logout = (cookie?: string) =>
    get(runtime, PRIVATE_HOST, "/__spacefast/access/logout?return=/", {
      method: "POST",
      headers: {
        origin: `${RUNTIME_SCHEME}://${PRIVATE_HOST}`,
        "sec-fetch-site": "same-origin",
        "content-type": "application/x-www-form-urlencoded",
        ...(cookie ? { cookie } : {}),
      },
      body: new URLSearchParams({ return: "/" }),
    });

  const firstCookie = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  const secondCookie = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  expect((await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: firstCookie } })).status).toBe(
    200,
  );

  // A logout is state-changing, so it needs a same-origin top-level request.
  const crossSite = await get(runtime, PRIVATE_HOST, "/__spacefast/access/logout?return=/", {
    headers: { cookie: firstCookie, "sec-fetch-site": "cross-site" },
  });
  expect(crossSite.status).toBe(403);
  const subresource = await get(runtime, PRIVATE_HOST, "/__spacefast/access/logout?return=/", {
    headers: {
      cookie: firstCookie,
      "sec-fetch-site": "same-origin",
      "sec-fetch-mode": "no-cors",
      "sec-fetch-dest": "image",
    },
  });
  expect(subresource.status).toBe(403);
  expect((await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: firstCookie } })).status).toBe(
    200,
  );

  const callsBefore = collaborationLogoutCalls.length;
  const loggedOut = await logout(firstCookie);
  expect(loggedOut.status).toBe(303);
  expect(loggedOut.headers.get("set-cookie")).toContain("Max-Age=0");
  const revokes = collaborationLogoutCalls.slice(callsBefore);
  expect(revokes).toHaveLength(1);
  const revoked = revokes[0] as {
    spaceId: string;
    sessionId: string;
    runtimeHost: string;
    returnUrl: string;
  };
  expect(revoked.spaceId).toBe(PRIVATE_SPACE);
  expect(revoked.runtimeHost).toBe(PRIVATE_HOST);
  expect(revoked.returnUrl).toBe(`${RUNTIME_SCHEME}://${PRIVATE_HOST}/`);
  // Ending the access session ends its Cast session: one id names both. It is
  // the session id from the signed claims, never the cookie value, which is a
  // bearer credential and must not reach the control plane or a Cast room.
  expect(revoked.sessionId).toBe(decodeSession(firstCookie).sid);
  expect(loggedOut.headers.get("location")).toBe(
    `${logoutOrigin}/logout-cleared/${revoked.sessionId}`,
  );
  expect(existsSync(sessionRecordPath(PRIVATE_SPACE, firstCookie))).toBe(false);

  // Replaying the revoked credential proves nothing once its claims age out,
  // and the other session of the same visitor is untouched.
  const replayed = await get(runtime, PRIVATE_HOST, "/", {
    headers: {
      cookie: editSession(PRIVATE_HOST, firstCookie, (claims) => {
        claims.exp = claims.iat - 1;
      }),
    },
  });
  expect(replayed.status).toBe(403);
  expect(replayed.headers.get("set-cookie")).toContain(`${COOKIE_NAME}=${ANONYMOUS_PREFIX}`);
  expect(
    (await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: secondCookie } })).status,
  ).toBe(200);

  // Nothing to revoke is a successful logout, not an error.
  expect((await logout()).status).toBe(303);
  expect((await logout(`${COOKIE_NAME}=malformed`)).status).toBe(303);

  // Central revocation is part of the logout: 404 means there was no Comments
  // session to end (fine), 503 means the logout did not happen (fail closed).
  const preRegistration = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  collaborationLogoutMissing = true;
  try {
    const missing = await logout(preRegistration);
    expect(missing.status).toBe(303);
    expect(missing.headers.get("location")).toBe("/");
  } finally {
    collaborationLogoutMissing = false;
  }
  expect(existsSync(sessionRecordPath(PRIVATE_SPACE, preRegistration))).toBe(false);

  const centralFailure = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  failCollaborationLogout = true;
  try {
    const failed = await logout(centralFailure);
    expect(failed.status).toBe(503);
    expect(failed.headers.get("set-cookie")).toBeNull();
  } finally {
    failCollaborationLogout = false;
  }
  expect(existsSync(sessionRecordPath(PRIVATE_SPACE, centralFailure))).toBe(true);
  expect(
    (await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: centralFailure } })).status,
  ).toBe(200);

  // Reporting success while the record survives would leave a revoked cookie
  // working. A record path that cannot be unlinked is the fail-closed case.
  const undeletable = await openAuthorities(PRIVATE_HOST, ["member:mem_owner"]);
  const undeletablePath = sessionRecordPath(PRIVATE_SPACE, undeletable);
  const contents = readFileSync(undeletablePath);
  rmSync(undeletablePath, { force: true });
  mkdirSync(undeletablePath);
  writeFileSync(path.join(undeletablePath, "occupied"), "");
  try {
    const failed = await logout(undeletable);
    expect(failed.status).toBe(503);
    expect(failed.headers.get("location")).toBeNull();
    expect(failed.headers.get("set-cookie")).toBeNull();
  } finally {
    rmSync(undeletablePath, { recursive: true, force: true });
    writeFileSync(undeletablePath, contents);
  }
  expect((await get(runtime, PRIVATE_HOST, "/", { headers: { cookie: undeletable } })).status).toBe(
    200,
  );
});

test("a route bundle written before collaboration logout keeps its access-request lane", async () => {
  await putRoute(runtime, PRIVATE_SPACE, "production", {
    version_id: PRIVATE_VERSION,
    config: projection({
      memberRefs: ["member:mem_owner", externalAuthorityReference("a".repeat(64))],
      exchange: "stale",
    }),
  });
  try {
    const response = await get(runtime, PRIVATE_HOST, "/");
    expect(response.status).toBe(403);
    expect(await response.text()).toContain('class="sf-access-request"');
  } finally {
    await putRoute(runtime, PRIVATE_SPACE, "production", {
      version_id: PRIVATE_VERSION,
      config: projection({
        memberRefs: ["member:mem_owner", externalAuthorityReference("a".repeat(64))],
      }),
    });
  }
});

// ---------------------------------------------------------------------------
// Public admission and the edge
// ---------------------------------------------------------------------------

test("unconstrained Public access is anonymously cacheable and opts the edge in", async () => {
  const anonymous = await get(runtime, PUBLIC_HOST, "/");
  expect(anonymous.status).toBe(200);
  const publicCacheControl = anonymous.headers.get("cache-control") ?? "";
  expect(publicCacheControl).toContain("public");
  expect(publicCacheControl).not.toContain("private");
  // §16: a public PHP-lane response is only stored at the edge when it carries
  // BOTH a public Cache-Control and the A8C opt-in.
  expect(anonymous.headers.get(EDGE_CACHE_HEADER)).toBe("cache");

  // An identity that changes nothing about the bytes must not privatize a
  // URL-stable public page.
  const irrelevant = await openAuthorities(PUBLIC_HOST, ["member:mem_public_owner"]);
  const withIdentity = await get(runtime, PUBLIC_HOST, "/", {
    headers: { cookie: irrelevant },
  });
  expect(withIdentity.status).toBe(200);
  expect(withIdentity.headers.get("cache-control")).toBe(publicCacheControl);
  expect(withIdentity.headers.get(EDGE_CACHE_HEADER)).toBe("cache");
  expect(withIdentity.headers.get("set-cookie")).toBeNull();

  // A cookie that verifies against nothing still gets the public bytes, but the
  // response that retires it is state-changing and stays out of every cache. It
  // is replaced by the authority-less form rather than deleted, so the next
  // navigation can still say why the visitor is signed out.
  const forged = `${COOKIE_NAME}=${SESSION_PREFIX}not-a-real-session.deadbeef`;
  const cleared = await get(runtime, PUBLIC_HOST, "/", { headers: { cookie: forged } });
  expect(cleared.status).toBe(200);
  expect(cleared.headers.get("cache-control")).toBe("private, no-store");
  expect(cleared.headers.get(EDGE_CACHE_HEADER)).toBe("no-cache");
  expect(cleared.headers.get("set-cookie")).toContain(`${COOKIE_NAME}=${ANONYMOUS_PREFIX}`);

  // Machine callers never fall back: an unusable platform bearer is a denial.
  const invalidBearer = await get(runtime, PUBLIC_HOST, "/", {
    headers: { "x-sf-authorization": "Bearer invalid-public-machine-token" },
  });
  expect(invalidBearer.status).toBe(403);
  expect(invalidBearer.headers.get("cache-control")).toBe("private, no-store");

  // A customer application's own Authorization header is not ours to read.
  const customerAuthorization = await get(runtime, PUBLIC_HOST, "/", {
    headers: { authorization: "Bearer customer-application-token" },
  });
  expect(customerAuthorization.status).toBe(200);
});

test("Grant constraints gate a Public Grant per request", async () => {
  try {
    // Any constraint at all makes the Grant conditional, so the response stops
    // being shared-cacheable even while it still admits everyone.
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: projection({
        mode: "public",
        publicConstraints: { expiresAt: new Date(Date.now() + 60_000).toISOString() },
      }),
    });
    const expiring = await get(runtime, PUBLIC_HOST, "/");
    expect(expiring.status).toBe(200);
    expect(expiring.headers.get("cache-control")).toStartWith("private");
    expect(expiring.headers.get(EDGE_CACHE_HEADER)).toBe("no-cache");

    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: projection({
        mode: "public",
        publicConstraints: { network: { excludedUserAgentSubstrings: ["BadBot"] } },
      }),
    });
    expect(
      (await get(runtime, PUBLIC_HOST, "/", { headers: { "user-agent": "Mozilla/5.0" } })).status,
    ).toBe(200);
    expect(
      (await get(runtime, PUBLIC_HOST, "/", { headers: { "user-agent": "BadBot/1.0" } })).status,
    ).toBe(403);
    // No user agent at all is not proof of not being the excluded one.
    expect((await get(runtime, PUBLIC_HOST, "/", { headers: { "user-agent": "" } })).status).toBe(
      403,
    );

    // A country selector reads the server-set fastcgi_param only, which this
    // fixture never sets — so every country rule fails closed.
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: projection({
        mode: "public",
        publicConstraints: { network: { countries: ["US"] } },
      }),
    });
    const noCountry = await get(runtime, PUBLIC_HOST, "/");
    expect(noCountry.status).toBe(403);
    expect(noCountry.headers.get("cache-control")).toBe("private, no-store");
  } finally {
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: publicBaseConfig(),
    });
  }
});

test("an IP network constraint admits nobody and is journaled once", async () => {
  try {
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: projection({
        mode: "public",
        // §16: X-Real-IP / CF-Connecting-IP reach PHP attacker-controlled, so
        // "matches the CIDR" could only ever mean "sent the right header".
        publicConstraints: { network: { ipCidrs: ["127.0.0.0/8"] } },
      }),
    });
    const loopback = await get(runtime, PUBLIC_HOST, "/", {
      headers: { "x-real-ip": "127.0.0.1", "cf-connecting-ip": "127.0.0.1" },
    });
    expect(loopback.status).toBe(403);
    expect(loopback.headers.get("cache-control")).toBe("private, no-store");

    const journaled = await pollUntil(
      () =>
        journalRecords(runtime).find(
          (record) => record.event === "network_grant_ignored" && record.space_id === PUBLIC_SPACE,
        ) ?? null,
      "network_grant_ignored journal record",
    );
    expect(journaled.reason).toBe("ip_constraints_unenforceable");
  } finally {
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: publicBaseConfig(),
    });
  }
});

// The edge-cache purge POSTs the runtime made for one mutation, captured by the
// loopback stand-in. The php -S harness has no fastcgi_finish_request, so the
// engine's post-response purge falls back to synchronous execution: by the time
// a mutation's response returns, its purge calls are already recorded. Each
// call is one hostname; a multi-host sweep is several calls in serving order.
async function edgePurgeCallsBy(
  rt: Runtime,
  mutate: () => Promise<unknown>,
): Promise<EdgePurgeCall[]> {
  const before = edgePurgeCalls(rt).length;
  await mutate();
  return edgePurgeCalls(rt).slice(before);
}

test("a Public path becoming Private announces both exposure digests and denies the cached URL", async () => {
  const before = await get(runtime, CACHE_HOST, "/docs/");
  expect(before.status).toBe(200);
  const etag = before.headers.get("etag");
  expect(etag).not.toBeNull();

  const publicConfigDigest = "c".repeat(64);
  const privateConfigDigest = "d".repeat(64);
  const publicExposure = {
    v: 4,
    public: true,
    authorizationDigest: "a".repeat(64),
    contentTypes: null,
    externalProxy: false,
    unmodeled: "{}",
  };
  const privateExposure = {
    ...publicExposure,
    public: false,
    authorizationDigest: "b".repeat(64),
  };
  // Still public: a content-shaped route write purges only the live serving
  // hostname — the version-pinned alias never changes bytes on activation.
  const activationPurge = await edgePurgeCallsBy(runtime, () =>
    putRoute(runtime, CACHE_SPACE, "production", {
      version_id: CACHE_VERSION,
      config: {
        ...projection({ mode: "public" }),
        public_exposure: publicExposure,
        public_exposure_digest: publicConfigDigest,
      },
      config_digest: publicConfigDigest,
    }),
  );
  expect(activationPurge.map((call) => call.hostname)).toEqual([CACHE_HOST]);
  expect(activationPurge[0]?.reason).toBe("route_updated");
  // Public→private: the one transition that owes EVERY alias a sweep — the
  // edge holds long-TTL copies of the formerly-public HTML on all of them,
  // live host first, version-pinned aliases behind it.
  const sweepPurge = await edgePurgeCallsBy(runtime, () =>
    putRoute(runtime, CACHE_SPACE, "production", {
      version_id: CACHE_VERSION,
      config: {
        ...projection(),
        public_exposure: privateExposure,
        public_exposure_digest: privateConfigDigest,
      },
      config_digest: privateConfigDigest,
    }),
  );
  // Every named host in full (domain scope: no purge_uris), live host first,
  // version-pinned alias behind it — one POST each.
  expect(sweepPurge.every((call) => call.reason === "space_access_privatized")).toBe(true);
  expect(sweepPurge.every((call) => call.uris.length === 0)).toBe(true);
  expect(sweepPurge.map((call) => call.hostname)).toEqual([CACHE_HOST, CACHE_VERSION_HOST]);

  // Version ids cannot say whether cached anonymous bytes just became private —
  // a config-only access change keeps the same pointer — so the before/after
  // exposure descriptors in the journal are what answers that.
  const routeEvent = journalRecords(runtime).findLast(
    (event) =>
      event.event === "route_updated" &&
      event.space_id === CACHE_SPACE &&
      event.version_id === CACHE_VERSION,
  );
  expect(routeEvent?.previous_public_exposure_digest).toBe(publicConfigDigest);
  expect(routeEvent?.public_exposure_digest).toBe(privateConfigDigest);
  expect(routeEvent?.previous_public_exposure).toEqual(publicExposure);
  expect(routeEvent?.public_exposure).toEqual(privateExposure);

  // Authorization runs before anything a stale browser copy could present, so a
  // held validator can never be traded back into the bytes it describes.
  const conditional = await get(runtime, CACHE_HOST, "/docs/", {
    headers: { "if-none-match": etag ?? '"missing"' },
  });
  expect(conditional.status).toBe(403);
  expect(conditional.headers.get("cache-control")).toBe("private, no-store");
});

// ---------------------------------------------------------------------------
// Fences (D82)
// ---------------------------------------------------------------------------

test("an exposure fence denies an existing session before the stale projection converges", async () => {
  const staleLink: ProjectionInput = {
    links: [{ linkId: "lnk_deferred_revoke", scopes: ["/shared"], expiresAt: null }],
  };
  await putRoute(runtime, PRIVATE_SPACE, "production", {
    version_id: PRIVATE_VERSION,
    config: projection(staleLink),
  });
  try {
    const cookie = await openAuthorities(PRIVATE_HOST, ["link:lnk_deferred_revoke"]);
    expect((await get(runtime, PRIVATE_HOST, "/shared/", { headers: { cookie } })).status).toBe(
      200,
    );

    // The desired projection still contains the revoked Link, so the
    // transition's temporary exposure fence is the only thing that can deny an
    // already-minted session while background convergence is parked.
    await putRoute(runtime, PRIVATE_SPACE, "production", {
      version_id: PRIVATE_VERSION,
      config: projection({ ...staleLink, accessFence: "exposure" }),
    });
    const denied = await get(runtime, PRIVATE_HOST, "/shared/", { headers: { cookie } });
    expect(denied.status).toBe(403);
    expect(denied.headers.get("cache-control")).toContain("no-store");

    // A fenced Space accepts no handoff either: a session minted mid-fence
    // would outlive the hold it was supposed to be kept behind.
    const handoff = await postAccessCallback(
      runtime,
      PRIVATE_HOST,
      callbackToken(PRIVATE_HOST, ["member:mem_owner"]),
      "/",
    );
    expect(handoff.status).toBe(403);
    expect(handoff.headers.get("set-cookie")).toBeNull();
  } finally {
    await putRoute(runtime, PRIVATE_SPACE, "production", {
      version_id: PRIVATE_VERSION,
      config: projection({
        memberRefs: ["member:mem_owner", externalAuthorityReference("a".repeat(64))],
      }),
    });
  }
});

test("an ownership fence preserves Public production but denies identity-bound paths", async () => {
  try {
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: projection({
        mode: "public",
        accessFence: "ownership",
        memberRefs: ["member:mem_public_owner"],
        overrides: [{ scope: "/private", mode: "limited" }],
      }),
    });

    const anonymous = await get(runtime, PUBLIC_HOST, "/");
    expect(anonymous.status).toBe(200);
    expect(anonymous.headers.get("cache-control")).toContain("public");

    // Nothing identity-bound resolves while ownership is in question, not even
    // for the member who would otherwise hold it.
    const ownerCookie = await openAuthorities(PUBLIC_HOST, ["member:mem_public_owner"]);
    const privatePath = await get(runtime, PUBLIC_HOST, "/private", {
      headers: { cookie: ownerCookie },
    });
    expect(privatePath.status).toBe(403);
    expect(privatePath.headers.get("cache-control")).toBe("private, no-store");
  } finally {
    await putRoute(runtime, PUBLIC_SPACE, "production", {
      version_id: PUBLIC_VERSION,
      config: publicBaseConfig(),
    });
  }
});

// ---------------------------------------------------------------------------
// Scopes
// ---------------------------------------------------------------------------

test("Open callbacks union Link and member refs while a scoped Grant blocks broader paths", async () => {
  const linkCookie = await openAuthorities(SCOPED_HOST, ["link:lnk_shared"]);
  expect(
    (await get(runtime, SCOPED_HOST, "/shared/", { headers: { cookie: linkCookie } })).status,
  ).toBe(200);
  expect(
    (await get(runtime, SCOPED_HOST, "/admin/", { headers: { cookie: linkCookie } })).status,
  ).toBe(403);

  // Attaching is one visitor gaining a capability, not a second visitor: the
  // session id rotates to the handoff's, the record the browser left is gone,
  // and the Comments identity rides across so a thread does not change author
  // mid-conversation. Neither authority states an identity, so the attached
  // session is still anonymous.
  const linkClaims = decodeSession(linkCookie);
  const unionCookie = await openAuthorities(SCOPED_HOST, ["member:mem_owner"], {
    cookie: linkCookie,
  });
  expect(
    (await get(runtime, SCOPED_HOST, "/shared/", { headers: { cookie: unionCookie } })).status,
  ).toBe(200);
  expect(
    (await get(runtime, SCOPED_HOST, "/admin/", { headers: { cookie: unionCookie } })).status,
  ).toBe(200);
  expect(authorityReferences(unionCookie)).toEqual(["link:lnk_shared", "member:mem_owner"]);
  expect(existsSync(sessionRecordPath(SCOPED_SPACE, linkCookie))).toBe(false);
  const unionClaims = decodeSession(unionCookie);
  // Attaching a credential rotates the session — the one id Cast disconnects
  // moves with it. The visitor's pseudonym does not: renaming them mid-thread
  // because they opened a share Link would be the bug.
  expect(unionClaims.sid).not.toBe(linkClaims.sid);
  expect(unionClaims.anonymousId).toBe(linkClaims.anonymousId);
  expect(linkClaims.principal).toBe("anonymous");
  expect(unionClaims.principal).toBe("anonymous");

  // An account-lane handoff states who the session is. A later link redeem
  // proves only a capability, so it must not re-anonymize them — the exact
  // shape that used to demote a signed-in member arriving by share link.
  const accountCookie = await openAuthorities(SCOPED_HOST, ["member:mem_owner"], {
    principal: "account:usr_scoped",
  });
  expect(decodeSession(accountCookie).principal).toBe("account:usr_scoped");
  const accountByLink = await openAuthorities(SCOPED_HOST, ["link:lnk_shared"], {
    cookie: accountCookie,
  });
  expect(decodeSession(accountByLink).principal).toBe("account:usr_scoped");
  expect(authorityReferences(accountByLink)).toEqual(["link:lnk_shared", "member:mem_owner"]);

  // The authority-less form: what a visitor holds before they prove anything.
  // It admits nothing and is not a session in the store — presenting it can
  // never make the runtime write one. Redeeming an authority over it upgrades
  // that visitor in place, carrying the pseudonym they already had.
  const carried = {
    sid: "a".repeat(64),
    anonymousId: `anon_${"b".repeat(32)}`,
  };
  const stateless = anonymousSessionCookie(SCOPED_HOST, carried);
  const storedBefore = sessionRecordFiles(SCOPED_SPACE).length;
  expect(
    (await get(runtime, SCOPED_HOST, "/shared/", { headers: { cookie: stateless } })).status,
  ).toBe(403);
  expect(sessionRecordFiles(SCOPED_SPACE)).toHaveLength(storedBefore);

  const upgraded = await openAuthorities(SCOPED_HOST, ["link:lnk_shared"], { cookie: stateless });
  expect(
    (await get(runtime, SCOPED_HOST, "/shared/", { headers: { cookie: upgraded } })).status,
  ).toBe(200);
  const upgradedClaims = decodeSession(upgraded);
  expect(upgradedClaims.sid).not.toBe(carried.sid);
  expect(upgradedClaims.anonymousId).toBe(carried.anonymousId);
  expect(upgradedClaims.principal).toBe("anonymous");

  // A broad Grant does not reach into a scope carved out from under it; the
  // narrower Grant on that scope is the only thing that opens it.
  const broadPerson = await openAuthorities(SCOPED_HOST, ["person:per_broad"]);
  expect(
    (await get(runtime, SCOPED_HOST, "/docs/", { headers: { cookie: broadPerson } })).status,
  ).toBe(403);
  const scopedPerson = await openAuthorities(SCOPED_HOST, ["person:per_docs"]);
  const admitted = await get(runtime, SCOPED_HOST, "/docs/", { headers: { cookie: scopedPerson } });
  expect(admitted.status).toBe(200);
  expect(admitted.headers.get("cache-control")).toStartWith("private");
});

test("a scope exclusion cannot be widened by an alternate spelling of the same path", async () => {
  expect((await get(runtime, BOUNDARY_HOST, "/")).status).toBe(200);

  for (const requestPath of [
    "/docs/",
    "/docs/index.html",
    "/docs//",
    "/docs/secret",
    "/docs%2Fsecret",
    "/docs/./secret",
  ]) {
    const response = await get(runtime, BOUNDARY_HOST, requestPath);
    expect(response.status, requestPath).toBeGreaterThanOrEqual(400);
    const body = await response.text();
    expect(body, requestPath).not.toContain("docs secret");
    expect(body, requestPath).not.toContain("<h1>docs</h1>");
  }

  // A traversal that would climb out of the excluded scope is not a path
  // identity at all; it never resolves to the bytes it was aiming past.
  for (const requestPath of ["/safe/%2e%2e%2fprivate", "/safe/%252e%252e/private"]) {
    const response = await get(runtime, BOUNDARY_HOST, requestPath);
    expect(response.status, requestPath).toBeGreaterThanOrEqual(400);
  }

  const cookie = await openAuthorities(BOUNDARY_HOST, ["member:mem_boundary_owner"]);
  const admitted = await get(runtime, BOUNDARY_HOST, "/docs/", { headers: { cookie } });
  expect(admitted.status).toBe(200);
  expect(await admitted.text()).toContain("<h1>docs</h1>");
});

test("ambiguous path identities fail closed instead of being sanitized", async () => {
  for (const requestPath of ["/%2fdocs", "/%5cdocs", "/%3fdocs", "/%23docs", "/%00docs"]) {
    const response = await get(runtime, PATH_HOST, requestPath);
    expect(response.status, requestPath).toBeGreaterThanOrEqual(400);
  }
});

// D110: normalization is intl's Normalizer behind an ASCII fast path — the
// hand-rolled shared/unicode.php tables are gone. One scope identity per page,
// whichever spelling the request carried, and `index.html` is the same identity
// as the directory it indexes.
test("scope identities NFC-normalize and canonicalize their index alias", () => {
  const accessRulesPath = path.resolve(import.meta.dir, "../engine/runtime/access-rules.php");
  const probe = spawnSync(
    PHP_BINARY,
    [
      "-r",
      [
        "require $argv[1];",
        "echo json_encode([",
        "_stattic_scope_path('/docs/guide.html'),",
        "_stattic_scope_path('/docs/index.html'),",
        "_stattic_scope_path('/docs/'),",
        "_stattic_artifact_scope_path('/docs/index.html'),",
        '_stattic_scope_path("/cafe\\u{0301}"),',
        '_stattic_scope_path("/caf\\u{00e9}"),',
        // Invalid UTF-8 is not a path identity at all, in either lane.
        '_stattic_scope_path("/\\xff"),',
        "]);",
      ].join(" "),
      accessRulesPath,
    ],
    { encoding: "utf8" },
  );
  expect(probe.status).toBe(0);
  expect(probe.stderr).toBe("");
  expect(JSON.parse(probe.stdout)).toEqual([
    "/docs/guide.html",
    "/docs",
    "/docs",
    "/docs/index.html",
    "/café",
    "/café",
    null,
  ]);
});

test("private subresources are same-host even with a valid authority", async () => {
  const cookie = await openAuthorities(BOUNDARY_HOST, ["member:mem_boundary_owner"]);
  const sameHost = await get(runtime, BOUNDARY_HOST, "/docs/", {
    headers: {
      cookie,
      referer: `https://${BOUNDARY_HOST}/`,
      "sec-fetch-dest": "script",
      "sec-fetch-mode": "no-cors",
    },
  });
  expect(sameHost.status).toBe(200);

  const crossHost = await get(runtime, BOUNDARY_HOST, "/docs/", {
    headers: {
      cookie,
      referer: `https://${PUBLIC_HOST}/`,
      "sec-fetch-dest": "script",
      "sec-fetch-mode": "no-cors",
    },
  });
  expect(crossHost.status).toBe(403);
  expect(crossHost.headers.get("x-spacefast-access-diagnostic")).toBe(
    "cross-host-private-subresource",
  );
  expect(crossHost.headers.get("cache-control")).toBe("private, no-store");
});

test("authorized protected fallbacks and nearest-404 documents still serve", async () => {
  await deploy(runtime, {
    spaceId: "spc_access_allowed_fallback",
    versionId: "ver_access_allowed_fallback",
    files: { "index.html": "<h1>allowed fallback bytes</h1>\n" },
    serving: { config: { fallback: { path: "index.html", status: 200 } } },
    activate: {
      route_name: "production",
      config: projection({ memberRefs: ["member:mem_allowed_fallback"] }),
      production_hostnames: [ALLOWED_FALLBACK_HOST],
    },
  });
  expect((await get(runtime, ALLOWED_FALLBACK_HOST, "/client-route")).status).toBe(403);
  const fallbackCookie = await openAuthorities(ALLOWED_FALLBACK_HOST, [
    "member:mem_allowed_fallback",
  ]);
  const fallback = await get(runtime, ALLOWED_FALLBACK_HOST, "/client-route", {
    headers: { cookie: fallbackCookie },
  });
  expect(fallback.status).toBe(200);
  expect(await fallback.text()).toContain("allowed fallback bytes");
  expect(fallback.headers.get("cache-control")).toStartWith("private");

  await deploy(runtime, {
    spaceId: "spc_access_allowed_nearest",
    versionId: "ver_access_allowed_nearest",
    files: { "docs/404.html": "<h1>allowed nearest bytes</h1>\n" },
    activate: {
      route_name: "production",
      config: projection({
        links: [{ linkId: "lnk_allowed_nearest", scopes: ["/docs"], expiresAt: null }],
      }),
      production_hostnames: [ALLOWED_NEAREST_HOST],
    },
  });
  expect((await get(runtime, ALLOWED_NEAREST_HOST, "/docs/missing")).status).toBe(403);
  const nearestCookie = await openAuthorities(ALLOWED_NEAREST_HOST, ["link:lnk_allowed_nearest"]);
  const nearest = await get(runtime, ALLOWED_NEAREST_HOST, "/docs/missing", {
    headers: { cookie: nearestCookie },
  });
  expect(nearest.status).toBe(404);
  expect(await nearest.text()).toContain("allowed nearest bytes");
  expect(nearest.headers.get("cache-control")).toStartWith("private");
});

test("Public admission stays live-only while branch previews require management or Open", async () => {
  const live = await get(runtime, BRANCH_LIVE_HOST, "/");
  expect(live.status).toBe(200);
  expect(live.headers.get("cache-control")).toContain("public");

  const spaceConfig = projection({
    mode: "public",
    memberRefs: ["member:mem_branch_owner"],
    claimPreview: {
      claimId: BRANCH_CLAIM_ID,
      expiresAt: new Date(Date.now() + 60 * 60_000).toISOString(),
    },
  });
  // A Public Grant on the live version says nothing about an immutable branch
  // preview; only a grant that names that branch does. Access is a SPACE
  // property in v4 — one overlay carries the whole grant set (contracts §4) —
  // so a branch preview is admitted by a `target: branch` Grant in that set,
  // never by a second projection hung off the branch route.
  spaceConfig.authorization.grants.push(
    {
      id: "grt_branch_manager",
      generation: 1,
      audience: {
        kind: "external",
        issuer: "spacefast-membership",
        subject: "mem_branch_owner",
      },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view", "access.manage"],
      constraints: {},
      target: { kind: "branch", branch: "branch-docs" },
      source: { kind: "system", reference: "member:mem_branch_owner" },
    },
    {
      id: "grt_branch_open",
      generation: 1,
      audience: { kind: "external", issuer: "claim-preview", subject: BRANCH_CLAIM_ID },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: { expiresAt: new Date(Date.now() + 60 * 60_000).toISOString() },
      target: { kind: "branch", branch: "branch-docs" },
      source: { kind: "system", reference: "claim-preview" },
    },
  );

  await putRoute(runtime, BRANCH_SPACE, "production", {
    version_id: BRANCH_VERSION,
    config: spaceConfig,
  });
  // The branch route only maps a hostname at a version; it carries no access
  // answer of its own.
  await putRoute(runtime, BRANCH_SPACE, "branch-docs", {
    version_id: BRANCH_VERSION,
    production_hostnames: [BRANCH_HOST],
    noindex_production_hostnames: [BRANCH_HOST],
    version_hostnames: [],
  });

  const anonymousBranch = await get(runtime, BRANCH_HOST, "/");
  expect(anonymousBranch.status).toBe(403);
  expect(anonymousBranch.headers.get("cache-control")).toBe("private, no-store");

  const managementCookie = await openAuthorities(BRANCH_HOST, ["member:mem_branch_owner"]);
  const managementBranch = await get(runtime, BRANCH_HOST, "/", {
    headers: { cookie: managementCookie },
  });
  expect(managementBranch.status).toBe(200);
  expect(managementBranch.headers.get("cache-control")).toStartWith("private");

  const openCookie = await openAuthorities(BRANCH_HOST, [`claim-preview:${BRANCH_CLAIM_ID}`]);
  const openBranch = await get(runtime, BRANCH_HOST, "/", { headers: { cookie: openCookie } });
  expect(openBranch.status).toBe(200);
  expect(openBranch.headers.get("cache-control")).toStartWith("private");
});

// ---------------------------------------------------------------------------
// Authorities inside one session
// ---------------------------------------------------------------------------

test("a maxUses credential session remains authorized after its winning redemption", async () => {
  const config = projection();
  config.authorization.grants.push({
    id: "grt_single_use_link",
    generation: 1,
    audience: { kind: "link", linkId: "single_use_link" },
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view"],
    constraints: { maxUses: 1 },
    target: { kind: "live" },
    source: { kind: "managed", reference: "credential:link:single_use_link" },
  });
  await putRoute(runtime, PRIVATE_SPACE, "production", {
    version_id: PRIVATE_VERSION,
    config,
  });
  try {
    // maxUses is consumed at acquisition. The session it produced keeps working
    // — a one-use link is not a one-page-view link.
    const cookie = await openAuthorities(PRIVATE_HOST, ["link:single_use_link"]);
    for (const requestPath of ["/", "/docs/"]) {
      const admitted = await get(runtime, PRIVATE_HOST, requestPath, { headers: { cookie } });
      expect(admitted.status, requestPath).toBe(200);
    }
  } finally {
    await putRoute(runtime, PRIVATE_SPACE, "production", {
      version_id: PRIVATE_VERSION,
      config: projection({
        memberRefs: ["member:mem_owner", externalAuthorityReference("a".repeat(64))],
      }),
    });
  }
});

test("authority entries expire independently and unrelated use does not extend them", async () => {
  const now = Math.floor(Date.now() / 1000);
  const twoAuthorities = async () => {
    const first = await openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_3"]);
    return openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_4"], { cookie: first });
  };

  // Absolute lifetime is per authority: the aged one is dropped on the next
  // re-mint while the session — and its other authority — survives.
  const absolute = editSession(AUTHORITY_LRU_HOST, await twoAuthorities(), (claims) => {
    const entry = authorityIn(claims, "person:per_lru_3");
    entry.openedAt = now - SESSION_ABSOLUTE_SECONDS - 1;
    entry.lastSeenAt = now;
  });
  expect(
    (await get(runtime, AUTHORITY_LRU_HOST, "/p3/", { headers: { cookie: absolute } })).status,
  ).toBe(403);
  const afterAbsolute = await get(runtime, AUTHORITY_LRU_HOST, "/p4/", {
    headers: { cookie: absolute },
  });
  expect(afterAbsolute.status).toBe(200);
  expect(authorityReferences(setCookieValue(afterAbsolute))).toEqual(["person:per_lru_4"]);

  // Idle expiry is the same shape, measured from last use rather than acquisition.
  const idle = editSession(AUTHORITY_LRU_HOST, await twoAuthorities(), (claims) => {
    const entry = authorityIn(claims, "person:per_lru_3");
    entry.openedAt = now - SESSION_IDLE_SECONDS - 60;
    entry.lastSeenAt = now - SESSION_IDLE_SECONDS - 1;
  });
  expect(
    (await get(runtime, AUTHORITY_LRU_HOST, "/p3/", { headers: { cookie: idle } })).status,
  ).toBe(403);
  expect(
    (await get(runtime, AUTHORITY_LRU_HOST, "/p4/", { headers: { cookie: idle } })).status,
  ).toBe(200);

  // Use moves ONLY the authority that admitted the request, and only once per
  // touch interval — a read-mostly session re-mints nothing at all.
  const preserved = { openedAt: now - 1_000, lastSeenAt: now - 500 };
  const recent = { openedAt: now - 60, lastSeenAt: now - 2 };
  const untouched = editSession(AUTHORITY_LRU_HOST, await twoAuthorities(), (claims) => {
    Object.assign(authorityIn(claims, "person:per_lru_3"), preserved);
    Object.assign(authorityIn(claims, "person:per_lru_4"), recent);
  });
  const noTouch = await get(runtime, AUTHORITY_LRU_HOST, "/p4/", {
    headers: { cookie: untouched },
  });
  expect(noTouch.status).toBe(200);
  expect(noTouch.headers.get("set-cookie")).toBeNull();

  const stale = {
    openedAt: now - SESSION_TOUCH_INTERVAL_SECONDS - 60,
    lastSeenAt: now - SESSION_TOUCH_INTERVAL_SECONDS - 1,
  };
  const touchDue = editSession(AUTHORITY_LRU_HOST, untouched, (claims) => {
    Object.assign(authorityIn(claims, "person:per_lru_4"), stale);
  });
  const touched = await get(runtime, AUTHORITY_LRU_HOST, "/p4/", {
    headers: { cookie: touchDue },
  });
  expect(touched.status).toBe(200);
  const touchedClaims = decodeSession(setCookieValue(touched));
  expect(authorityIn(touchedClaims, "person:per_lru_4").lastSeenAt).toBeGreaterThan(
    stale.lastSeenAt,
  );
  expect(authorityIn(touchedClaims, "person:per_lru_3")).toMatchObject(preserved);
});

test("reopening refreshes only that authority proof", async () => {
  const now = Math.floor(Date.now() / 1000);
  const first = await openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_10"]);
  const both = await openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_11"], {
    cookie: first,
  });
  const otherTimes = { openedAt: now - 9_000, lastSeenAt: now - 4_000 };
  const aged = editSession(AUTHORITY_LRU_HOST, both, (claims) => {
    Object.assign(authorityIn(claims, "person:per_lru_10"), {
      openedAt: now - 10_000,
      lastSeenAt: now - 5_000,
    });
    Object.assign(authorityIn(claims, "person:per_lru_11"), otherTimes);
  });

  const reopened = await openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_10"], {
    cookie: aged,
  });
  const claims = decodeSession(reopened);
  const refreshed = authorityIn(claims, "person:per_lru_10");
  expect(refreshed.openedAt).toBeGreaterThan(now - 10_000);
  expect(refreshed.lastSeenAt).toBe(refreshed.openedAt);
  expect(authorityIn(claims, "person:per_lru_11")).toMatchObject(otherTimes);
});

test("the seventeenth authority evicts the least recently seen and reopening restores it", async () => {
  let cookie: string | undefined;
  for (let index = 0; index < SESSION_AUTHORITY_LIMIT + 1; index += 1) {
    cookie = await openAuthorities(AUTHORITY_LRU_HOST, [`person:per_lru_${index}`], { cookie });
  }
  if (cookie === undefined) throw new Error("no session was minted");
  expect(decodeSession(cookie).authorities).toHaveLength(SESSION_AUTHORITY_LIMIT);
  // p0 was acquired first and never used since, so it is what the cap sheds.
  expect(authorityReferences(cookie)).not.toContain("person:per_lru_0");
  expect((await get(runtime, AUTHORITY_LRU_HOST, "/p0/", { headers: { cookie } })).status).toBe(
    403,
  );
  expect((await get(runtime, AUTHORITY_LRU_HOST, "/p1/", { headers: { cookie } })).status).toBe(
    200,
  );

  // Recency, not acquisition age: p1 is the oldest proof by openedAt, but it is
  // also the most recently seen, so the next acquisition must not shed it.
  const now = Math.floor(Date.now() / 1000);
  const recentlyUsed = editSession(AUTHORITY_LRU_HOST, cookie, (claims) => {
    for (const entry of claims.authorities) {
      entry.openedAt = now - 3_000;
      entry.lastSeenAt = now - 600;
    }
    const head = authorityIn(claims, "person:per_lru_1");
    head.openedAt = now - 9_000;
    head.lastSeenAt = now - 1;
  });

  const reopened = await openAuthorities(AUTHORITY_LRU_HOST, ["person:per_lru_0"], {
    cookie: recentlyUsed,
  });
  expect(decodeSession(reopened).authorities).toHaveLength(SESSION_AUTHORITY_LIMIT);
  expect(authorityReferences(reopened)).toContain("person:per_lru_1");
  expect(
    (await get(runtime, AUTHORITY_LRU_HOST, "/p0/", { headers: { cookie: reopened } })).status,
  ).toBe(200);
  expect(
    (await get(runtime, AUTHORITY_LRU_HOST, "/p1/", { headers: { cookie: reopened } })).status,
  ).toBe(200);
  // SPACEFAST_ACCESS_SESSION_MAX_BYTES: a Set-Cookie has ~4096 bytes for
  // everything, so the signed claim payload stays inside its own budget.
  const payload = cookieCredential(reopened).slice(SESSION_PREFIX.length).split(".")[0] ?? "";
  expect(payload.length).toBeLessThanOrEqual(3900);
});

test("credential rotation and verified email are scoped to one Grant authority", async () => {
  const links = [
    {
      linkId: "lnk_email_bound",
      scopes: ["/verified"],
      expiresAt: null,
      requireVerifiedEmail: true,
    },
    { linkId: "lnk_unrelated", scopes: ["/shared"], expiresAt: null },
  ];
  const verified = await openAuthorities(CREDENTIAL_SCOPE_HOST, ["link:lnk_email_bound"], {
    emailVerified: true,
  });
  let cookie = await openAuthorities(CREDENTIAL_SCOPE_HOST, ["link:lnk_unrelated"], {
    cookie: verified,
  });

  expect(
    (await get(runtime, CREDENTIAL_SCOPE_HOST, "/verified/", { headers: { cookie } })).status,
  ).toBe(200);
  expect(
    (await get(runtime, CREDENTIAL_SCOPE_HOST, "/shared/", { headers: { cookie } })).status,
  ).toBe(200);
  expect(
    authorityIn(decodeSession(cookie), "link:lnk_email_bound").emailVerifiedUntil,
  ).not.toBeNull();
  // Only the authority the verified handoff named carries the proof.
  expect(authorityIn(decodeSession(cookie), "link:lnk_unrelated").emailVerifiedUntil).toBeNull();

  // Letting the email proof lapse closes the Grant that required it and leaves
  // the unrelated one alone.
  const lapsed = editSession(CREDENTIAL_SCOPE_HOST, cookie, (claims) => {
    authorityIn(claims, "link:lnk_email_bound").emailVerifiedUntil =
      Math.floor(Date.now() / 1000) - 1;
  });
  expect(
    (await get(runtime, CREDENTIAL_SCOPE_HOST, "/verified/", { headers: { cookie: lapsed } }))
      .status,
  ).toBe(403);
  expect(
    (await get(runtime, CREDENTIAL_SCOPE_HOST, "/shared/", { headers: { cookie: lapsed } })).status,
  ).toBe(200);

  try {
    // Rotating one Link's Grant generation retires that authority on the next
    // revalidation; the session and its other authority survive.
    cookie = await openAuthorities(CREDENTIAL_SCOPE_HOST, ["link:lnk_email_bound"], {
      cookie,
      emailVerified: true,
    });
    await putRoute(runtime, CREDENTIAL_SCOPE_SPACE, "production", {
      version_id: CREDENTIAL_SCOPE_VERSION,
      config: projection({
        links,
        accessGeneration: 1,
        grantGenerations: { "grt_lnk_email_bound_/verified": 2 },
      }),
    });
    const rotated = await get(runtime, CREDENTIAL_SCOPE_HOST, "/shared/", { headers: { cookie } });
    expect(rotated.status).toBe(200);
    expect(authorityReferences(setCookieValue(rotated))).toEqual(["link:lnk_unrelated"]);
    expect(
      (await get(runtime, CREDENTIAL_SCOPE_HOST, "/verified/", { headers: { cookie } })).status,
    ).toBe(403);
  } finally {
    await putRoute(runtime, CREDENTIAL_SCOPE_SPACE, "production", {
      version_id: CREDENTIAL_SCOPE_VERSION,
      config: projection({ links }),
    });
  }
});

// The session records ONE generation per authority, and it is a digest over
// every Grant that admits that authority — not the one Grant it happened to
// redeem. Committing to the whole set is what makes rotating the real admitting
// Grant unforgeable: with a single candidate standing in for the set, whichever
// Grant sorted first kept a revoked credential alive. The accepted cost is that
// any other edit to that set — an added overlapping Grant here — also retires
// the authority, which re-acquisition resolves.
test("editing the Grant set behind an authority retires the session and degrades it in place", async () => {
  const links = [{ linkId: "lnk_unrelated", scopes: ["/shared"], expiresAt: null }];
  await putRoute(runtime, CREDENTIAL_SCOPE_SPACE, "production", {
    version_id: CREDENTIAL_SCOPE_VERSION,
    config: projection({ links }),
  });
  const carried = {
    sid: "c".repeat(64),
    anonymousId: `anon_${"d".repeat(32)}`,
  };
  const cookie = await openAuthorities(CREDENTIAL_SCOPE_HOST, ["link:lnk_unrelated"], {
    cookie: anonymousSessionCookie(CREDENTIAL_SCOPE_HOST, carried),
  });
  expect(
    (await get(runtime, CREDENTIAL_SCOPE_HOST, "/shared/", { headers: { cookie } })).status,
  ).toBe(200);

  const additiveGrant = {
    id: "grt_a_added_later",
    generation: 1,
    audience: { kind: "link", linkId: "lnk_unrelated" },
    resources: { include: ["/shared/**"], exclude: [] },
    capabilities: ["page.view"],
    constraints: {},
    target: { kind: "live" },
    source: { kind: "managed", reference: "grt_a_added_later" },
  };
  const additive = projection({ links, accessGeneration: 1 });
  additive.authorization.grants.push(additiveGrant);
  await putRoute(runtime, CREDENTIAL_SCOPE_SPACE, "production", {
    version_id: CREDENTIAL_SCOPE_VERSION,
    config: additive,
  });
  const revoked = await get(runtime, CREDENTIAL_SCOPE_HOST, "/shared/", { headers: { cookie } });
  expect(revoked.status).toBe(403);
  // The edit took away what this visitor MAY DO, not WHO they are.
  // The record retires and the browser is handed the authority-less form,
  // keeping the pseudonym it was already using — losing that would rename the
  // visitor mid-thread — and the session id it was already on, so the Cast
  // session survives the demotion. Plus the one fact that explains the wall in
  // front of them, so the page says their access expired rather than only that
  // it is private.
  expect(existsSync(sessionRecordPath(CREDENTIAL_SCOPE_SPACE, cookie))).toBe(false);
  const replacement =
    revoked.headers
      .getSetCookie()
      .find((value) => value.startsWith(`${COOKIE_NAME}=`))
      ?.split(";")[0] ?? "";
  expect(replacement).toStartWith(`${COOKIE_NAME}=${ANONYMOUS_PREFIX}`);
  const retained = anonymousSessionPayload(replacement);
  expect(retained.sid).toBe(decodeSession(cookie).sid);
  expect(retained.anonymousId).toBe(carried.anonymousId);
  expect(typeof retained.expiredAt).toBe("number");
  expect(await revoked.text()).toContain("Your access expired");

  await putRoute(runtime, CREDENTIAL_SCOPE_SPACE, "production", {
    version_id: CREDENTIAL_SCOPE_VERSION,
    config: projection({
      links: [
        {
          linkId: "lnk_email_bound",
          scopes: ["/verified"],
          expiresAt: null,
          requireVerifiedEmail: true,
        },
        ...links,
      ],
    }),
  });
});

test("a host-clock rollback inside the verifier's skew admits, and beyond it fails closed", async () => {
  const wallNow = Math.floor(Date.now() / 1000);
  const cookie = await openAuthorities(SESSION_HOST, ["member:mem_session"]);
  const request = (now: number) =>
    get(runtime, SESSION_HOST, "/", { headers: { cookie, ...sessionClockHeaders(now) } });

  // A 60-second rollback stays inside the bounded skew the verifier allows.
  expect((await request(wallNow - 60)).status).toBe(200);
  // A much larger one fails closed for the interval rather than destroying a
  // session that is merely future-dated relative to this request's clock.
  expect((await request(wallNow - 900)).status).toBe(403);
  expect(existsSync(sessionRecordPath(SESSION_SPACE, cookie))).toBe(true);
  expect((await request(wallNow)).status).toBe(200);
});
