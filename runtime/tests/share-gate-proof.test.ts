// The access gate's proof that it started a sign-in, as the engine mints it.
// Only the gate's own probe for a page this visitor cannot open carries
// `sf_gate`; `/__spacefast/sign-in` (an app's `signIn()`, the bar's "Switch
// account") never does, since any page or app on the Space can send a browser
// there. The proof is checked with the control plane's own verifier against
// the box's public key, so the two sides cannot drift apart.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { shareGateProofMatches } from "../../apps/control-plane/src/share/gate-proof.ts";
import { startPhpServer } from "./harness.ts";

const SPACE = "spc_gate_proof";
const HOST = "gate-proof.test";
const AUTHORIZE = "https://api.spacefast.test/v1/auth/oauth2/authorize";
const ISSUER = "https://auth.spacefast.test/v1/auth";
const CREDENTIAL = "gate-proof-exchange-credential-0123456789";
const engine = path.resolve(import.meta.dir, "../engine");

let root = "";
let server: Awaited<ReturnType<typeof startPhpServer>>;

/** The route config's identity block, once the control plane registered `kid`. */
function serving(kid: string | null) {
  return {
    space_id: SPACE,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/gate",
      spaceClaimed: true,
      sessionKey: "a".repeat(64),
      accessPage: {
        displayName: "Gate proof",
        accountUrl: AUTHORIZE,
        issuer: ISSUER,
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/gate/password",
          tokenUrl: "https://access.spacefast.test/acquire/gate/token",
          requestUrl: "https://access.spacefast.test/acquire/gate/request",
          credential: CREDENTIAL,
        },
        ...(kid
          ? {
              identity: {
                clientId: `spacefast-space:${SPACE}`,
                tokenUrl: `${ISSUER}/oauth2/token`,
                resource: "https://api.spacefast.test/runtime",
                kid,
                scope: "space-runtime:exchange",
              },
            }
          : undefined),
      },
      grants: [
        {
          id: "grt_gate_member",
          generation: 1,
          audience: { kind: "external", issuer: "spacefast-membership", subject: "mem_gate" },
          resources: { include: ["/**"], exclude: [] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:gate-proof" },
        },
      ],
    },
  };
}

beforeAll(async () => {
  root = mkdtempSync(path.join(os.tmpdir(), "sf-gate-proof-"));
  // A box's private root, with the runtime directory its write lock lives in.
  mkdirSync(path.join(root, ".stattic", "storage", "runtime"), { recursive: true });
  // Serves a protected Space through the real access enforcement entry point
  // (`_stattic_access_enforce_v4`), plus the runtime door (`/__spacefast`) and
  // sign-in start (`/__spacefast/sign-in`).
  writeFileSync(
    path.join(root, "router.php"),
    `<?php
require_once ${JSON.stringify(path.join(engine, "shared/context.php"))};
require_once ${JSON.stringify(path.join(engine, "runtime/access-rules.php"))};
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($path === '/__spacefast/health.php') { echo 'ok'; exit; }
_stattic_access_private_root(${JSON.stringify(path.join(root, ".stattic", "storage"))});
// The box's identity key, as its first route sync mints it.
if ($path === '/mint-key') {
    require_once ${JSON.stringify(path.join(engine, "shared/space-identity.php"))};
    $key = _stattic_space_identity_key(_stattic_access_private_root(), ${JSON.stringify(SPACE)});
    header('Content-Type: application/json');
    echo json_encode(['kid' => $key['kid'] ?? null, 'public' => $key['public'] ?? null]);
    exit;
}
$raw = json_decode((string) file_get_contents(${JSON.stringify(path.join(root, "serving.json"))}), true);
$projection = _stattic_compile_authorization_projection($raw['authorization']);
if ($projection === null) { http_response_code(500); echo 'bad projection'; exit; }
$serving = ['space_id' => $raw['space_id'], 'authorization' => [...$projection, 'sessionKey' => $raw['authorization']['sessionKey']]];
$GLOBALS['SPACEFAST_PAGE_SERVING'] = $serving;
if ($path === '/__spacefast/sign-in') _stattic_access_handle_account_start($serving, ${JSON.stringify(HOST)});
if ($path === '/__spacefast') _stattic_access_handle_door($serving, ${JSON.stringify(HOST)});
_stattic_access_enforce_v4(${JSON.stringify(HOST)}, $path, $path);
echo 'admitted';
`,
  );
  server = await startPhpServer({
    args: [],
    router: path.join(root, "router.php"),
    cwd: root,
    env: { ...process.env, SPACEFAST_ZERO_CALLBACK_ALLOWED_HOSTS: "" },
  });
});

afterAll(() => {
  server?.stop();
  if (root) rmSync(root, { recursive: true, force: true });
});

const keySchema = z.object({ kid: z.string(), public: z.string() });

/** The box's identity key under its private root; only its public half comes back. */
async function boxKey() {
  return keySchema.parse(await (await fetch(new URL("/mint-key", server.baseUrl))).json());
}

async function requestPage(
  pathname: string,
  kid: string | null,
  headers: Record<string, string> = {},
) {
  writeFileSync(path.join(root, "serving.json"), JSON.stringify(serving(kid)));
  return fetch(new URL(pathname, server.baseUrl), { redirect: "manual", headers });
}

async function start(pathname: string, kid: string | null, headers: Record<string, string> = {}) {
  const response = await requestPage(pathname, kid, headers);
  expect(response.status, server.diagnostics()).toBe(302);
  const location = new URL(response.headers.get("location") ?? "");
  expect(location.origin + location.pathname).toBe(AUTHORIZE);
  return location;
}

function jwk(key: z.infer<typeof keySchema>) {
  return {
    kty: "OKP",
    crv: "Ed25519",
    x: Buffer.from(key.public, "base64").toString("base64url"),
    kid: key.kid,
    alg: "EdDSA",
    use: "sig",
  };
}

const userNavigation = {
  "sec-fetch-dest": "document",
  "sec-fetch-mode": "navigate",
  "sec-fetch-user": "?1",
};

test("a user-activated document navigation to a protected page carries a proof the control plane accepts", async () => {
  const key = await boxKey();
  const probe = await start(
    "/docs/?tab=1&q=hello%20world&tag=one&tag=two&a.b=3",
    key.kid,
    userNavigation,
  );
  expect(probe.searchParams.get("prompt")).toBe("none");
  const proof = probe.searchParams.get("sf_gate");
  expect(proof).toMatch(/^[\w-]+\.[\w-]+\.[\w-]+$/);
  const query = {
    space_id: SPACE,
    redirect_uri: probe.searchParams.get("redirect_uri") ?? "",
    code_challenge: probe.searchParams.get("code_challenge") ?? "",
    return: probe.searchParams.get("return") ?? "",
  };
  expect(query.return).toBe("/docs/?tab=1&q=hello%20world&tag=one&tag=two&a.b=3");
  const verify = (proven: string, overrides: Partial<typeof query> = {}, audience = ISSUER) =>
    shareGateProofMatches(proven, { ...query, ...overrides }, { keys: [jwk(key)], audience });
  expect(await verify(proof ?? "")).toBe(true);
  // Query normalization matches %20 and + as one page.
  expect(
    await verify(proof ?? "", { return: "/docs/?tab=1&q=hello+world&tag=one&tag=two&a.b=3" }),
  ).toBe(true);
  // Bound to this challenge, this refused resource, this callback and this issuer.
  expect(await verify(proof ?? "", { code_challenge: "x".repeat(43) })).toBe(false);
  expect(await verify(proof ?? "", { return: "/" })).toBe(false);
  expect(await verify(proof ?? "", { return: "/docs/?tab=2" })).toBe(false);
  expect(
    await verify(proof ?? "", { redirect_uri: "https://other.test/__spacefast/callback" }),
  ).toBe(false);
  expect(
    await verify(proof ?? "", { redirect_uri: `https://${HOST}/__spacefast/other-callback` }),
  ).toBe(false);
  expect(await verify(proof ?? "", {}, "https://elsewhere.test/v1/auth")).toBe(false);
  // Under another Space's name it verifies as nothing.
  expect(await verify(proof ?? "", { space_id: "spc_someone_else" })).toBe(false);
});

test("navigations without user activation, fetches, iframes, and the runtime door get no proof", async () => {
  const key = await boxKey();
  // Script-driven `location =` (no `Sec-Fetch-User: ?1`): probes without a proof.
  const withoutActivation = await start("/docs/?tab=1", key.kid, {
    "sec-fetch-dest": "document",
    "sec-fetch-mode": "navigate",
  });
  expect(withoutActivation.searchParams.get("prompt")).toBe("none");
  expect(withoutActivation.searchParams.has("sf_gate")).toBe(false);
  // A fetch gets no silent redirect and no proof.
  const fetched = await requestPage("/docs/?tab=1", key.kid, {
    "sec-fetch-dest": "empty",
    "sec-fetch-mode": "cors",
  });
  expect(fetched.status).toBe(403);
  expect(fetched.headers.get("location")).toBeNull();
  // An iframe gets no silent redirect and no proof, even with `Sec-Fetch-User: ?1`.
  const framed = await requestPage("/docs/?tab=1", key.kid, {
    "sec-fetch-dest": "iframe",
    "sec-fetch-mode": "navigate",
    "sec-fetch-user": "?1",
  });
  expect(framed.status).toBe(403);
  expect(framed.headers.get("location")).toBeNull();
  // The runtime door `/__spacefast?return=...` takes a caller-supplied return, so it mints no proof.
  const doorProbe = await start("/__spacefast?return=%2Fdocs%2F", key.kid, userNavigation);
  expect(doorProbe.searchParams.has("sf_gate")).toBe(false);
});

test("the Space's own sign-in start never carries the proof", async () => {
  const key = await boxKey();
  for (const query of ["", "&prompt=select_account", "&silent=1"]) {
    const signIn = await start(
      `/__spacefast/sign-in?return=%2Fdocs%2F${query}`,
      key.kid,
      userNavigation,
    );
    expect(signIn.searchParams.has("sf_gate")).toBe(false);
  }
});

test("a box whose key the control plane has not registered sends no proof", async () => {
  const probe = await start("/docs/", null, userNavigation);
  expect(probe.searchParams.get("prompt")).toBe("none");
  expect(probe.searchParams.has("sf_gate")).toBe(false);
});
