import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, createHmac } from "node:crypto";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  DASHBOARD_ORIGIN,
  deploy,
  get,
  publicAccessConfig,
  type Runtime,
  startRuntime,
} from "./harness.ts";
import {
  compileZeroContentModel,
  generateContentModelPhp,
  parseContentDeclarations,
} from "./legacy-zero.ts";

let runtime: Runtime;

const PRIVATE_HOST = "content-media-private.test";
const PRIVATE_SPACE = "spc_content_media_private";
const OPEN_HOST = "content-media-open.test";
const OPEN_SPACE = "spc_content_media_open";
const RESTRICTED_HOST = "content-media-restricted.test";
const RESTRICTED_SPACE = "spc_content_media_restricted";
const UNPUBLISHED_HOST = "content-media-unpublished.test";
const UNPUBLISHED_SPACE = "spc_content_media_unpublished";
const RELATIVE_MEDIA_PATH = "2026/08/secret.txt";

function scope(spaceId: string): string {
  return createHash("sha256").update(spaceId).digest("hex").slice(0, 32);
}

function mediaUrl(spaceId: string, relativePath = RELATIVE_MEDIA_PATH): string {
  return `/__spacefast/content-media/${scope(spaceId)}/${relativePath}`;
}

function writeMedia(spaceId: string, relativePath: string, contents: string): void {
  const target = path.join(runtime.storageRoot, "spaces", spaceId, "content-media", relativePath);
  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, contents);
}

function mintContentAdminSession(host: string, spaceId: string): string {
  const secretHex = "ab".repeat(32);
  const authorizationRoot = path.join(runtime.storageRoot, "spaces", spaceId);
  mkdirSync(authorizationRoot, { recursive: true });
  writeFileSync(
    path.join(authorizationRoot, "content-admin-authorization.json"),
    `${JSON.stringify({ space_id: spaceId, access_generation: 7 })}\n`,
  );
  const runtimeRoot = path.join(runtime.storageRoot, "runtime");
  mkdirSync(runtimeRoot, { recursive: true });
  writeFileSync(
    path.join(runtimeRoot, "content-admin-session-key.json"),
    `${JSON.stringify({ key: secretHex, minted_at: "2026-08-26T00:00:00Z" })}\n`,
  );
  const payload = Buffer.from(
    JSON.stringify({
      host,
      user_id: 42,
      principal: { kind: "user", issuer: "spacefast", subject: "sub_media_test" },
      space_id: spaceId,
      access_generation: 7,
      wordpress_role: "editor",
      frame_origin: DASHBOARD_ORIGIN,
      public_origin: "https://content-media-public.test",
      access: { surface: "wordpress" },
      expires_at: Math.floor(Date.now() / 1000) + 3600,
    }),
  ).toString("base64url");
  const signature = createHmac("sha256", Buffer.from(secretHex, "hex"))
    .update(payload)
    .digest("base64url");
  return `${payload}.${signature}`;
}

function protectedConfig() {
  return {
    projection_generation: 1,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/content-media",
      accessPage: {
        displayName: "Protected media",
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/content-media/password",
          tokenUrl: "https://access.spacefast.test/acquire/content-media/token",
          requestUrl: "https://access.spacefast.test/acquire/content-media/request",
          credential: "content-media-exchange-credential-0123456789",
        },
      },
      spaceClaimed: true,
      grants: [],
    },
    visitor_issuer: "spacefast-api",
    visitor_jwks: { keys: [] },
  };
}

beforeAll(async () => {
  runtime = await startRuntime();
  await deploy(runtime, {
    spaceId: PRIVATE_SPACE,
    versionId: "ver_content_media_private",
    files: { "index.html": "private" },
    activate: {
      route_name: "production",
      config: protectedConfig(),
      production_hostnames: [PRIVATE_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: OPEN_SPACE,
    versionId: "ver_content_media_open",
    files: { "index.html": "open" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({}, "live_and_all_versions"),
      production_hostnames: [OPEN_HOST],
      version_hostnames: [],
    },
  });
  const source = `import { capsule } from "@spacefast/zero/server"; export default capsule({ collections: { media: { publicRead: false, fields: {} } } });`;
  const declarations = parseContentDeclarations(source, source);
  if (declarations === null) throw new Error("expected media declaration");
  const { artifacts } = await compileZeroContentModel({
    declarations,
    readDirectory: async () => [],
  });
  const model = await generateContentModelPhp(artifacts.model);
  const modelRoot = path.join(
    runtime.storageRoot,
    "spaces",
    RESTRICTED_SPACE,
    "content-model",
    "releases",
    artifacts.model.revision.slice(7),
  );
  mkdirSync(modelRoot, { recursive: true });
  writeFileSync(path.join(modelRoot, "content-model.php"), model.php);
  writeFileSync(path.join(modelRoot, "content-model.sha256"), `${model.sha256}\n`);
  await deploy(runtime, {
    spaceId: RESTRICTED_SPACE,
    versionId: "ver_content_media_restricted",
    files: {
      "index.html": "open",
      "_spacefast/pages/documents/model.json": JSON.stringify({
        revision: artifacts.model.revision,
      }),
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({}, "live_and_all_versions"),
      production_hostnames: [RESTRICTED_HOST],
      version_hostnames: [],
    },
  });
  writeMedia(RESTRICTED_SPACE, RELATIVE_MEDIA_PATH, "restricted-media-bytes");
  writeMedia(PRIVATE_SPACE, RELATIVE_MEDIA_PATH, "private-media-bytes");
  writeMedia(OPEN_SPACE, RELATIVE_MEDIA_PATH, "open-media-bytes");
  writeMedia(UNPUBLISHED_SPACE, RELATIVE_MEDIA_PATH, "unpublished-media-bytes");
  writeMedia(OPEN_SPACE, "2026/08/blocked.php", "<?php echo 'must not execute';");
});

afterAll(() => runtime?.stop());

test("protected Content media never reaches an unauthenticated requester", async () => {
  const response = await get(runtime, PRIVATE_HOST, mediaUrl(PRIVATE_SPACE));

  expect(response.status).toBe(403);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(await response.text()).not.toContain("private-media-bytes");
  const restricted = await get(runtime, RESTRICTED_HOST, mediaUrl(RESTRICTED_SPACE));
  expect(restricted.status).toBe(404);
  const editor = mintContentAdminSession(RESTRICTED_HOST, RESTRICTED_SPACE);
  const allowed = await get(runtime, RESTRICTED_HOST, mediaUrl(RESTRICTED_SPACE), {
    headers: { cookie: `spacefast_content_admin=${editor}` },
  });
  expect(allowed.status).toBe(200);
  expect(await allowed.text()).toBe("restricted-media-bytes");
});

test("a host-bound Content admin session reads unpublished Space media", async () => {
  const session = mintContentAdminSession(UNPUBLISHED_HOST, UNPUBLISHED_SPACE);
  const requestPath = mediaUrl(UNPUBLISHED_SPACE);
  const response = await get(runtime, UNPUBLISHED_HOST, requestPath, {
    headers: { cookie: `spacefast_content_admin=${session}` },
  });

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("unpublished-media-bytes");
  expect((await get(runtime, UNPUBLISHED_HOST, requestPath)).status).toBe(404);
  expect(
    (
      await get(runtime, "content-media-wrong-host.test", requestPath, {
        headers: { cookie: `spacefast_content_admin=${session}` },
      })
    ).status,
  ).toBe(404);
});

test("open Content media streams only through its host-bound Space scope", async () => {
  const response = await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE));
  expect(response.status).toBe(200);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("content-type")).toStartWith("text/plain");
  expect(await response.text()).toBe("open-media-bytes");

  expect((await get(runtime, OPEN_HOST, mediaUrl(PRIVATE_SPACE))).status).toBe(404);
  expect(
    (await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE, "2026/%2e%2e/secret.txt"))).status,
  ).toBe(404);
  expect(
    (await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE, "2026%5c..%5csecret.txt"))).status,
  ).toBe(404);
  expect((await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE, "2026/08/blocked.php"))).status).toBe(
    404,
  );
});

test("Content media HEAD proves authorization without returning bytes", async () => {
  const response = await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE), {
    method: "HEAD",
  });
  expect(response.status).toBe(200);
  expect(response.headers.get("content-length")).toBe(String("open-media-bytes".length));
  expect(await response.text()).toBe("");
});
