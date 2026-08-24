import { afterAll, beforeAll, expect, setSystemTime, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";

import {
  deploy,
  get,
  postAccessCallback,
  putRoute,
  type Runtime,
  spaceOverlay,
  startRuntime,
} from "./harness.ts";

let runtime: Runtime;

const HOST = "chain.test";
const SPACE = "spc_chain";
const VERSION = "ver_chain_1";
// A second Space so the gen-tuple test can move the overlay without disturbing
// the chain Space's live session.
const GEN_HOST = "chain-gen.test";
const GEN_SPACE = "spc_chain_gen";
const GEN_VERSION = "ver_chain_gen_1";
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
function accessConfig(accessGeneration: number, sessionVersion: number): Record<string, unknown> {
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
  runtime = await startRuntime();
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
});

afterAll(() => runtime?.stop());

async function mintCallbackToken({
  host = HOST,
  spaceId = SPACE,
  generation = 1,
  ttlSeconds,
}: {
  host?: string;
  spaceId?: string;
  generation?: number;
  ttlSeconds?: number;
} = {}) {
  return (
    await mintAuthorityToken({
      sub: MEMBER,
      authorities: [MEMBER],
      spaceId,
      sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
      generation,
      audience: host,
      jti: `jti_${randomUUID()}`,
      ...(ttlSeconds === undefined ? {} : { ttlSeconds }),
    })
  ).token;
}

test("control-plane mint and JWKS drive a real private runtime session", async () => {
  const anonymous = await get(runtime, HOST, "/docs/");
  expect(anonymous.status).toBe(403);
  expect(anonymous.headers.get("cache-control")).toBe("private, no-store");

  // The account lane as a browser walks it: the control plane 303s the signed
  // handoff into the runtime's redeem endpoint, which trades it for a local
  // session and redirects to the requested page.
  const token = await mintCallbackToken();
  const callback = await get(
    runtime,
    HOST,
    `/__sf/redeem?sf_token=${encodeURIComponent(token)}&return=%2Fdocs%2F`,
  );
  expect(callback.status).toBe(303);
  expect(callback.headers.get("location")).toBe("/docs/");
  expect(callback.headers.get("cache-control")).toBe("no-store");
  expect(callback.headers.get("referrer-policy")).toBe("no-referrer");
  const cookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_session");

  const served = await get(runtime, HOST, "/docs/", { headers: { cookie } });
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe(PROTECTED);
  expect(await served.text()).toBe("<h1>private docs</h1>\n");

  const replay = await postAccessCallback(runtime, HOST, token, "/docs/");
  expect(replay.status).toBe(403);
  expect((await get(runtime, HOST, "/docs/", { headers: { cookie } })).status).toBe(200);
});

test("a handoff is gated on the overlay's access_gen alone, never on the summed tuple", async () => {
  // The two halves land in the overlay as separate ints (contracts §4).
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 0 });

  // A fresh mint per attempt: each carries its own jti, so a rejection never
  // borrows the replay guard's verdict.
  const redeem = async (generation: number): Promise<number> => {
    const token = await mintCallbackToken({ host: GEN_HOST, spaceId: GEN_SPACE, generation });
    const response = await postAccessCallback(runtime, GEN_HOST, token);
    return response.status;
  };

  // Minted for a generation the Space has not reached yet.
  expect(await redeem(2)).toBe(403);

  // access_gen 1 + session_ver 1: a summing runtime would see "2" here and
  // admit the generation-2 handoff. Rotating the session version signs everyone
  // out and must not advance the Grant generation.
  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(1, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 1, session_ver: 1 });
  expect(await redeem(2)).toBe(403);

  // access_gen 2 + session_ver 1: the sum is 3 and the handoff still admits,
  // because only the access_gen half is ever compared.
  await putRoute(runtime, GEN_SPACE, "production", {
    version_id: GEN_VERSION,
    config: accessConfig(2, 1),
  });
  expect(spaceOverlay(runtime, GEN_SPACE)).toMatchObject({ access_gen: 2, session_ver: 1 });

  const admitted = await postAccessCallback(
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

test("a handoff minted outside the redeem freshness window is refused", async () => {
  // Long-lived on purpose: `exp` is still in the future, so only the runtime's
  // 60s cap on the mint's `iat` can reject this token.
  setSystemTime(new Date(Date.now() - 120_000));
  let stale: string;
  try {
    stale = await mintCallbackToken({ ttlSeconds: 600 });
  } finally {
    setSystemTime();
  }
  const response = await postAccessCallback(runtime, HOST, stale);
  expect(response.status).toBe(403);
});
