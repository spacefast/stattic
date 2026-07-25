// Outbound identity forwarding (internal-docs/access-plan.html §3.2 "Identity
// forwarding (SSO proxy)"): on ALLOWED token-gated requests that get proxied to
// an external origin the runtime forwards Spacefast-Access-Jwt/-Sub/-Grants,
// stripping the inbound copies of those exact headers first. Behavioral: the
// boundary checks run the real enforcer + the real proxy header collection
// through a CLI fixture (egress policy hard-denies loopback upstreams, so the
// curl leg itself cannot terminate locally); the ordering checks run the real
// HTTP server end to end.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync } from "node:crypto";
import path from "node:path";

import {
  deploy,
  get,
  putRoute,
  type Runtime,
  signEd25519Jwt,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

const PHP_BINARY = process.env.PHP_BINARY ?? "php";
const FIXTURE = path.resolve(import.meta.dir, "access-forwarding-check.php");

const HOST = "fwd.test";
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
]);

function visitorToken(host: string, sub: string, grants: string[]): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(tokenKeyPair.privateKey, ISSUER.kid, {
    sub,
    grants,
    aud: host,
    iat: now,
    exp: now + 3600,
  });
}

type FixtureResult = { protected: boolean; headers: string[] };

function runBoundary(spec: Record<string, unknown>): FixtureResult {
  const result = Bun.spawnSync([PHP_BINARY, "-d", "auto_prepend_file=", FIXTURE], {
    stdin: Buffer.from(JSON.stringify(spec)),
  });
  const stdout = result.stdout.toString();
  if (result.exitCode !== 0) {
    throw new Error(`fixture exited ${result.exitCode}: ${result.stderr.toString()}${stdout}`);
  }
  return JSON.parse(stdout) as FixtureResult;
}

function accessHeaders(headers: string[]): string[] {
  return headers.filter((line) => line.toLowerCase().startsWith("spacefast-access-"));
}

test("a token-authorized proxied request carries Jwt/Sub/Grants; a spoofed inbound Spacefast-Access-* never survives", () => {
  const token = visitorToken(HOST, "link:lnk_forward", ["link:lnk_forward"]);
  const result = runBoundary({
    server: {
      HTTP_HOST: HOST,
      HTTP_AUTHORIZATION: `Bearer ${token}`,
      // Direct-client spoof of the runtime→origin identity headers.
      HTTP_SPACEFAST_ACCESS_SUB: "user:mallory",
      HTTP_SPACEFAST_ACCESS_JWT: "forged.jwt.value",
      REMOTE_ADDR: "198.51.100.7",
    },
    inbound_headers: {
      "Spacefast-Access-Sub": "user:mallory",
      "Spacefast-Access-Jwt": "forged.jwt.value",
      "Spacefast-Access-Grants": "user:mallory",
      "X-Custom": "yes",
    },
    // Even an allowlist that names the identity headers cannot smuggle the
    // inbound copies across the boundary.
    forward_headers: ["X-Custom", "Spacefast-Access-Sub", "Spacefast-Access-Jwt"],
    serving: {
      policy: {
        rules: [
          {
            id: "team_only",
            match: { pathPattern: "/app/**" },
            effect: "challenge",
            auth: { requiredGrants: ["link:lnk_forward"], issuers: [ISSUER] },
          },
        ],
      },
    },
    host: HOST,
    path: "/app/data",
  });

  expect(result.protected).toBe(true);
  expect(result.headers).toContain(`Spacefast-Access-Jwt: ${token}`);
  expect(result.headers).toContain("Spacefast-Access-Sub: link:lnk_forward");
  expect(result.headers).toContain("Spacefast-Access-Grants: link:lnk_forward");
  expect(result.headers).toContain("X-Custom: yes");
  // Exactly one of each identity header — the verified value, never the spoof.
  expect(accessHeaders(result.headers)).toHaveLength(3);
  expect(JSON.stringify(result.headers)).not.toContain("mallory");
  expect(JSON.stringify(result.headers)).not.toContain("forged.jwt.value");
});

test("a password-basic-authorized proxied request forwards no identity headers", () => {
  const result = runBoundary({
    server: {
      HTTP_HOST: HOST,
      PHP_AUTH_USER: "ops",
      PHP_AUTH_PW: "swordfish",
    },
    inbound_headers: { "X-Custom": "yes" },
    forward_headers: ["X-Custom"],
    serving: {
      secrets: { basic_pw: "swordfish" },
      policy: {
        rules: [
          {
            id: "basic_wall",
            match: { pathPattern: "/app/**" },
            effect: "challenge",
            auth: {
              requiredGrants: ["pw:basic_wall"],
              acquire: [
                { type: "password", ref: "secret:basic_pw", transport: "basic", username: "ops" },
              ],
            },
          },
        ],
      },
    },
    host: HOST,
    path: "/app/data",
  });

  expect(result.protected).toBe(true);
  expect(result.headers).toContain("X-Custom: yes");
  expect(accessHeaders(result.headers)).toHaveLength(0);
});

test("an anonymous-allow proxied request forwards no identity headers", () => {
  const result = runBoundary({
    server: { HTTP_HOST: HOST },
    inbound_headers: { "Spacefast-Access-Sub": "user:mallory" },
    forward_headers: ["Spacefast-Access-Sub"],
    serving: {
      policy: { rules: [{ match: { pathPattern: "/app/**" }, effect: "allow" }] },
    },
    host: HOST,
    path: "/app/data",
  });

  expect(result.protected).toBe(false);
  expect(accessHeaders(result.headers)).toHaveLength(0);
});

test("route-configured static headers cannot impersonate identity either", () => {
  const result = runBoundary({
    server: { HTTP_HOST: HOST },
    inbound_headers: {},
    forward_headers: [],
    route_headers: { "Spacefast-Access-Sub": "user:route", "X-Api-Key": "k1" },
    serving: {
      policy: { rules: [{ match: { pathPattern: "/app/**" }, effect: "allow" }] },
    },
    host: HOST,
    path: "/app/data",
  });

  expect(result.headers).toContain("X-Api-Key: k1");
  expect(accessHeaders(result.headers)).toHaveLength(0);
});

// ---------------------------------------------------------------------------
// End-to-end ordering over the real server: proxied paths enforce access
// BEFORE the proxy dispatch — anonymous visitors are challenged, authorized
// visitors reach the proxy lane. Uses a plan-disabled proxy rule so the lane
// is provably reached without any egress/network dependency.
// ---------------------------------------------------------------------------

let rt: Runtime;
const HTTP_HOST = "fwdhttp.test";
const SPACE = "spc_fwd";
const VERSION = "ver_fwd_1";
const PROXY_MARKER = "proxy-lane-reached\n";

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: { "index.html": "<h1>fwd</h1>\n" },
    serving: {
      redirects_exact: {
        "/api": [
          {
            destination: "https://api.example.com/v1",
            status: 200,
            action: "proxy",
            disabled: true,
            disabledReason: PROXY_MARKER,
            order: 1,
          },
        ],
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HTTP_HOST],
      version_hostnames: [],
    },
  });
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: {
      policy: {
        rules: [
          {
            id: "api_gate",
            match: { pathPattern: "/api" },
            effect: "challenge",
            auth: { requiredGrants: ["link:lnk_proxy"], issuers: [ISSUER] },
          },
        ],
      },
    },
  });
});

afterAll(() => rt?.stop());

test("GET on a token-gated proxied path is challenged before the proxy dispatch", async () => {
  const anonymous = await get(rt, HTTP_HOST, "/api");
  expect(anonymous.status).toBe(401);
  expect(await anonymous.text()).not.toBe(PROXY_MARKER);

  // A spoofed identity header authorizes nothing.
  const spoofed = await get(rt, HTTP_HOST, "/api", {
    headers: { "spacefast-access-sub": "user:alice", "spacefast-access-grants": "user:alice" },
  });
  expect(spoofed.status).toBe(401);
});

test("an authorized GET passes the access gate and reaches the proxy lane", async () => {
  const token = visitorToken(HTTP_HOST, "link:lnk_proxy", ["link:lnk_proxy"]);
  const authorized = await get(rt, HTTP_HOST, "/api", {
    headers: { authorization: `Bearer ${token}` },
  });
  expect(authorized.status).toBe(403);
  expect(await authorized.text()).toBe(PROXY_MARKER);

  // The rest of the space still serves.
  expect((await get(rt, HTTP_HOST, "/")).status).toBe(200);
});
