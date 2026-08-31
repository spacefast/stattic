import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { readFileSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { deploy, get, type Runtime, startRuntime } from "./harness.ts";

// The WP API door: /wp-json reaches WordPress as the principal a Space
// credential resolves to. The gate's contract is the WordPress context it
// establishes and whether it hands off at all, so WordPress core is stood in for
// by a wp-blog-header.php that reports that context — the same seam
// content-request.test.ts uses for wp-load.php. What WordPress then does with a
// role is content-kernel.php's own (user_has_cap, rest_authentication_errors).

let runtime: Runtime;

const OPEN_HOST = "wp-api-open.test";
const OPEN_SPACE = "spc_wp_api_open";
const CLOSED_HOST = "wp-api-closed.test";
const CLOSED_SPACE = "spc_wp_api_closed";
// A Space whose only public Grant excludes `/wp-json/**`: pages are public, the
// REST API is not. Both spellings of a REST call must be denied the same way.
const EXCLUDED_HOST = "wp-api-excluded.test";
const EXCLUDED_SPACE = "spc_wp_api_excluded";
// A fully-open Space (public on `live` AND `all_versions`, no fence) whose
// overlay `open` flag is true, so the serve path skips access enforcement for
// the anonymous answer.
const FULLY_OPEN_HOST = "wp-api-fully-open.test";
const FULLY_OPEN_SPACE = "spc_wp_api_fully_open";
// A static Space co-hosted on the same site: a Public Grant but no content
// model, so /wp-json must not boot the site-wide WordPress kernel.
const STATIC_HOST = "wp-api-static.test";
const STATIC_SPACE = "spc_wp_api_static";
const MACHINE = "mac_wp_api_agent";
const EXCHANGE_CREDENTIAL = "runtime-wp-api-exchange-credential-0123456789";

const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-wp-api-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-wp-api-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-wp-api-test-token";

const [{ mintAuthorityToken }, { runtimeJwks }] = await Promise.all([
  import("../../apps/control-plane/src/access/authorize.ts"),
  import("../../apps/control-plane/src/runtime/auth.ts"),
]);

type TestGrant = {
  id: string;
  generation: number;
  audience: { kind: "machine"; machineId: string } | { kind: "public" };
  resources: { include: string[]; exclude: string[] };
  capabilities: string[];
  constraints: object;
  target: { kind: string };
  source: { kind: string; reference: string };
};

/**
 * A Space whose only non-public authority is one machine credential. Withholding
 * the Public Grant is what makes the closed Space protected — the same thing
 * that closes it on the serve path.
 *
 * `excludeRest` scopes the Public Grant to exclude `/wp-json/**` (pages public,
 * REST private). `allVersionsPublic` adds a second, unconditional Public Grant
 * on `all_versions` so the overlay's `open` flag compiles to true — the state
 * where the serve path skips access enforcement for the anonymous answer.
 */
function accessConfig(
  publicGrant: boolean,
  {
    excludeRest = false,
    allVersionsPublic = false,
  }: {
    excludeRest?: boolean;
    allVersionsPublic?: boolean;
  } = {},
) {
  const grants: TestGrant[] = [
    // The agent's credential. `content.publish` is what earns it `editor`.
    {
      id: "grt_wp_api_machine",
      generation: 1,
      audience: { kind: "machine", machineId: MACHINE },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view", "content.publish"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "managed", reference: "test:wp-api" },
    },
  ];
  if (publicGrant) {
    grants.push({
      id: "grt_wp_api_public",
      generation: 1,
      audience: { kind: "public" },
      resources: { include: ["/**"], exclude: excludeRest ? ["/wp-json/**"] : [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "managed", reference: "test:wp-api" },
    });
  }
  if (allVersionsPublic) {
    // The second half of an unconditionally-open Space: the compiler flags
    // `open` only when a Public Grant is unconditional on BOTH live and
    // all_versions.
    grants.push({
      id: "grt_wp_api_public_all",
      generation: 1,
      audience: { kind: "public" },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "all_versions" },
      source: { kind: "managed", reference: "test:wp-api" },
    });
  }
  return {
    public_exposure: {
      v: 1,
      public: publicGrant,
      authorizationDigest: "0".repeat(64),
      contentTypes: null,
      externalProxy: false,
      unmodeled: "",
    },
    projection_generation: 1,
    authorization: {
      generation: 1,
      sessionVersion: 0,
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
      grants,
    },
    visitor_issuer: "spacefast-api",
    visitor_jwks: runtimeJwks(),
  };
}

async function machineToken({
  host = OPEN_HOST,
  spaceId = OPEN_SPACE,
}: { host?: string; spaceId?: string } = {}) {
  return (
    await mintAuthorityToken({
      sub: `machine:${MACHINE}`,
      authorities: [`machine:${MACHINE}`],
      spaceId,
      sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
      generation: 1,
      audience: host,
      jti: `jti_${randomUUID()}`,
    })
  ).token;
}

/** What the gate established, as WordPress would find it. */
async function wpContext(host: string, requestPath: string, headers: Record<string, string> = {}) {
  const response = await get(runtime, host, requestPath, { headers });
  const body = await response.text();
  return {
    status: response.status,
    body,
    context: response.status === 200 ? JSON.parse(body) : null,
  };
}

beforeAll(async () => {
  runtime = await startRuntime();
  const files = { "index.html": "<h1>space</h1>\n" };
  await deploy(runtime, {
    spaceId: OPEN_SPACE,
    versionId: "ver_wp_api_open",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(true),
      production_hostnames: [OPEN_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: CLOSED_SPACE,
    versionId: "ver_wp_api_closed",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(false),
      production_hostnames: [CLOSED_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: EXCLUDED_SPACE,
    versionId: "ver_wp_api_excluded",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(true, { excludeRest: true }),
      production_hostnames: [EXCLUDED_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: FULLY_OPEN_SPACE,
    versionId: "ver_wp_api_fully_open",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(true, { allVersionsPublic: true }),
      production_hostnames: [FULLY_OPEN_HOST],
      version_hostnames: [],
    },
  });
  // A static Space co-hosted on the same site: it has a Public Grant but no
  // content model, so it must never boot the site-wide WordPress on /wp-json.
  await deploy(runtime, {
    spaceId: STATIC_SPACE,
    versionId: "ver_wp_api_static",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(true),
      production_hostnames: [STATIC_HOST],
      version_hostnames: [],
    },
  });
  // A managed-WordPress Space is one with a content-model/active-release pointer;
  // that per-Space marker — not the site-wide front controller — is what says a
  // Space has a REST API. STATIC_SPACE deliberately gets none.
  for (const spaceId of [OPEN_SPACE, CLOSED_SPACE, EXCLUDED_SPACE, FULLY_OPEN_SPACE]) {
    const modelRoot = path.join(
      runtime.root,
      ".stattic",
      "storage",
      "spaces",
      spaceId,
      "content-model",
    );
    mkdirSync(modelRoot, { recursive: true });
    writeFileSync(path.join(modelRoot, "active-release"), `sha256:${"a".repeat(64)}\n`);
  }
  // WordPress's front controller. Reaching it at all is the hand-off the gate
  // owes the REST lane; the globals are the identity it hands over.
  writeFileSync(
    path.join(runtime.root, "wp-blog-header.php"),
    [
      "<?php",
      "header('Content-Type: application/json', true);",
      "$principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;",
      "echo json_encode([",
      "  'served_by' => 'wordpress',",
      "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
      "  'role' => $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] ?? null,",
      "  'principal_kind' => is_array($principal) ? ($principal['kind'] ?? null) : null,",
      "  'actor_id' => is_array($principal) ? ($principal['actor_id'] ?? null) : null,",
      "  'themes' => defined('WP_USE_THEMES') ? WP_USE_THEMES : null,",
      "  'rest_admitted' => (bool) ($GLOBALS['SPACEFAST_CONTENT_REST_ADMITTED'] ?? false),",
      "]);",
      "",
    ].join("\n"),
  );
}, 30000);

afterAll(() => runtime?.stop());

test("a Space credential reaches WordPress REST as the principal its Grants earn", async () => {
  const token = await machineToken();
  const admitted = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });

  expect(admitted.status).toBe(200);
  expect(admitted.context).toEqual({
    served_by: "wordpress",
    space_id: OPEN_SPACE,
    // `content.publish` on the credential's Grant, mapped by the runtime half of
    // wordpressRoleForGrantCapabilities.
    role: "editor",
    // An API key is not a person: it reaches WordPress as a service actor keyed
    // by the credential id, so it owns one durable user rather than borrowing
    // somebody's.
    principal_kind: "service",
    actor_id: MACHINE,
    themes: false,
    // The gate admitted this request, so the kernel filter serves REST.
    rest_admitted: true,
  });

  // The query spelling of the same lane, for a Space without pretty permalinks.
  const queryForm = await wpContext(OPEN_HOST, "/?rest_route=/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(queryForm.context).toMatchObject({ served_by: "wordpress", role: "editor" });
});

test("REST without a Spacefast credential is WordPress's own unauthenticated answer", async () => {
  // A public Space admits the request exactly as it admits a page view, and
  // hands WordPress no principal — so WordPress answers as nobody, which is what
  // it would do on its own. The door is not a second authorization.
  const anonymous = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts");

  expect(anonymous.status).toBe(200);
  expect(anonymous.context).toEqual({
    served_by: "wordpress",
    space_id: OPEN_SPACE,
    role: null,
    principal_kind: null,
    actor_id: null,
    themes: false,
    // Still marked admitted with no role: the gate admitted an anonymous
    // request, and the kernel filter serves WordPress's unauthenticated answer
    // rather than 404-ing a request the gate never refused.
    rest_admitted: true,
  });
});

test("an unusable credential is refused rather than downgraded", async () => {
  // Machine callers never fall back to anonymous: the whole point of presenting
  // a credential is that its failure is visible.
  const garbage = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": "Bearer not-a-real-credential",
  });
  expect(garbage.status).toBe(403);
  expect(garbage.context).toBeNull();
  expect(garbage.body).not.toContain("served_by");

  // Expiry, signature and replay are _stattic_visitor_verify's own, proven
  // where they live (access-chain.test.ts); the case above is what THIS lane
  // adds — a credential the runtime cannot use denies instead of quietly
  // serving the public answer.

  // A well-formed, validly signed, unexpired credential for a DIFFERENT Space
  // is its own failure mode: the audience binding has to reach this lane too.
  const foreign = await machineToken({ spaceId: CLOSED_SPACE, host: CLOSED_HOST });
  const crossed = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${foreign}`,
  });
  expect(crossed.status).toBe(403);
  expect(crossed.body).not.toContain("served_by");
});

test("a protected Space answers REST the way it answers a page", async () => {
  // No Public Grant, no credential: the access engine denies, WordPress never
  // runs, and the response says nothing about the Space behind it.
  const denied = await wpContext(CLOSED_HOST, "/wp-json/wp/v2/posts");
  expect(denied.status).not.toBe(200);
  expect(denied.body).not.toContain("served_by");
  expect(denied.body).not.toContain(CLOSED_SPACE);

  // The credential that Space did issue still gets in.
  const token = await machineToken({ spaceId: CLOSED_SPACE, host: CLOSED_HOST });
  const admitted = await wpContext(CLOSED_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(admitted.status).toBe(200);
  expect(admitted.context).toMatchObject({
    served_by: "wordpress",
    space_id: CLOSED_SPACE,
    role: "editor",
  });
});

test("a Space with no WordPress never claims /wp-json", async () => {
  // Most Spaces are static. On those /wp-json is an ordinary URL the Space does
  // not publish, so it gets the Space's own answer — not an editor-session gate
  // for an editor that does not exist.
  const frontController = path.join(runtime.root, "wp-blog-header.php");
  const saved = readFileSync(frontController);
  rmSync(frontController);
  try {
    const response = await get(runtime, OPEN_HOST, "/wp-json/wp/v2/posts");
    const body = await response.text();
    expect(response.status).toBe(404);
    expect(body).not.toContain("content_admin_session_invalid");
    expect(body).not.toContain("served_by");
  } finally {
    writeFileSync(frontController, saved);
  }
});

test("/wp-admin keeps its single door", async () => {
  // The REST door is deliberately narrower than the editor lane: a credential
  // that reaches the API does not open the editor's HTML surface, which is
  // reachable only through the session its launch minted.
  const token = await machineToken();
  const response = await get(runtime, OPEN_HOST, "/wp-admin/edit.php", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  expect(response.status).toBe(401);
  expect(await response.text()).toContain("content_admin_session_invalid");
});

test("both REST spellings honour a Grant that scopes /wp-json", async () => {
  // The Space's page is public, but its only Public Grant excludes `/wp-json/**`.
  // Both ways of naming the SAME REST resource must be denied identically — the
  // query spelling `/?rest_route=` addresses `/wp-json/...` and is enforced
  // against that path, not against `/`. Otherwise the query form reaches REST
  // under the `/` policy the exclude never touched.
  const home = await get(runtime, EXCLUDED_HOST, "/");
  expect(home.status).toBe(200);

  const pretty = await wpContext(EXCLUDED_HOST, "/wp-json/wp/v2/posts");
  expect(pretty.status).not.toBe(200);
  expect(pretty.body).not.toContain("served_by");

  const query = await wpContext(EXCLUDED_HOST, "/?rest_route=/wp/v2/posts");
  expect(query.status).not.toBe(200);
  expect(query.body).not.toContain("served_by");
});

test("a fully-open Space still refuses an unusable credential", async () => {
  // `open` is true here, so the anonymous answer skips enforcement — but a
  // presented credential is still a credential. An unusable one denies rather
  // than falling through to WordPress's public answer, so a machine caller's
  // failure is never hidden behind a 200.
  const garbage = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": "Bearer not-a-real-credential",
  });
  expect(garbage.status).toBe(403);
  expect(garbage.body).not.toContain("served_by");

  // No credential is the anonymous path, unchanged: WordPress answers as nobody.
  const anonymous = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts");
  expect(anonymous.status).toBe(200);
  expect(anonymous.context).toMatchObject({ served_by: "wordpress", role: null });

  // A valid credential still elevates on an open Space.
  const token = await machineToken({ spaceId: FULLY_OPEN_SPACE, host: FULLY_OPEN_HOST });
  const admitted = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(admitted.status).toBe(200);
  expect(admitted.context).toMatchObject({ served_by: "wordpress", role: "editor" });
});

test("a co-hosted static Space never boots WordPress on /wp-json", async () => {
  // One wp.cloud site hosts many Spaces, so the site-wide wp-blog-header.php
  // exists for every Space here. Whether THIS Space has a REST API is answered
  // per Space by its content-model/active-release pointer, which STATIC_SPACE
  // does not have — so /wp-json is an ordinary URL it does not publish, not a
  // door into the co-hosted managed Space's kernel. It must 404 without booting
  // WordPress, even with a credential the Space itself issued.
  const anonymous = await get(runtime, STATIC_HOST, "/wp-json/wp/v2/posts");
  const anonymousBody = await anonymous.text();
  expect(anonymous.status).toBe(404);
  expect(anonymousBody).not.toContain("served_by");

  const token = await machineToken({ spaceId: STATIC_SPACE, host: STATIC_HOST });
  const credentialed = await get(runtime, STATIC_HOST, "/wp-json/wp/v2/posts", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  const credentialedBody = await credentialed.text();
  expect(credentialed.status).toBe(404);
  expect(credentialedBody).not.toContain("served_by");

  // The query spelling is the same non-answer.
  const query = await get(runtime, STATIC_HOST, "/?rest_route=/wp/v2/posts");
  expect(await query.text()).not.toContain("served_by");
});
