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

function visitorSession(): string {
  const probe = Bun.spawnSync([
    "php",
    "-r",
    `putenv('SPACEFAST_INSECURE_COOKIES=1');
     require $argv[1] . '/engine/shared/context.php';
     require $argv[1] . '/engine/shared/storage.php';
     require $argv[1] . '/engine/shared/content-access.php';
     require $argv[1] . '/engine/runtime/serve.php';
     require $argv[1] . '/engine/runtime/access-rules.php';
     _stattic_access_private_root($argv[2]);
     _sf_load_generated_config($argv[2]);
     $serving = _stattic_content_access_target($argv[2], $argv[3])['serving'];
     $GLOBALS['SPACEFAST_PAGE_SERVING'] = $serving;
     $credential = _stattic_access_session_create($serving, [
       'sub' => 'media-visitor', 'exp' => time() + 3600,
       'claims' => ['spaceId' => $argv[4], 'sid' => hash('sha256', 'media-visitor'),
         'principal' => 'account:media-visitor',
         'identity' => ['issuer' => 'https://api.spacefast.com/v1/auth', 'subject' => 'media-visitor'],
         'authorities' => ['member:mem_media_visitor']],
     ], $argv[3], 0, $serving['authorization']);
     if ($credential === null) exit(1);
     echo _stattic_session_cookie_name() . '=' . $credential;`,
    path.dirname(runtime.engineRoot),
    runtime.storageRoot,
    PRIVATE_HOST,
    PRIVATE_SPACE,
  ]);
  expect(probe.exitCode, probe.stderr.toString()).toBe(0);
  return probe.stdout.toString();
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
      grants: [
        {
          id: "grt_media_visitor",
          generation: 1,
          audience: {
            kind: "external",
            issuer: "spacefast-membership",
            subject: "mem_media_visitor",
          },
          resources: { include: ["/**"], exclude: [] },
          capabilities: ["page.view", "content.publish"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:media" },
        },
      ],
    },
    visitor_issuer: "spacefast-api",
    visitor_jwks: { keys: [] },
  };
}

beforeAll(async () => {
  runtime = await startRuntime();
  const source = `import { capsule } from "@spacefast/zero/server"; export default capsule({ collections: { media: { publicRead: false, fields: {} } } });`;
  const declarations = parseContentDeclarations(source, source);
  if (declarations === null) throw new Error("expected media declaration");
  const { artifacts } = await compileZeroContentModel({
    declarations,
    readDirectory: async () => [],
  });
  const model = await generateContentModelPhp(artifacts.model);
  for (const spaceId of [PRIVATE_SPACE, RESTRICTED_SPACE]) {
    const modelRoot = path.join(
      runtime.storageRoot,
      "spaces",
      spaceId,
      "content-model",
      "releases",
      artifacts.model.revision.slice(7),
    );
    mkdirSync(modelRoot, { recursive: true });
    writeFileSync(path.join(modelRoot, "content-model.php"), model.php);
    writeFileSync(path.join(modelRoot, "content-model.sha256"), `${model.sha256}\n`);
  }
  await deploy(runtime, {
    spaceId: PRIVATE_SPACE,
    versionId: "ver_content_media_private",
    files: {
      "index.html": "private",
      "_spacefast/pages/documents/model.json": JSON.stringify({
        revision: artifacts.model.revision,
      }),
    },
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
  const cookie = visitorSession();
  const navigation = await get(runtime, PRIVATE_HOST, mediaUrl(PRIVATE_SPACE), {
    headers: {
      cookie,
      referer: "https://slack.com/",
      "sec-fetch-site": "cross-site",
      "sec-fetch-mode": "navigate",
      "sec-fetch-dest": "document",
    },
  });
  expect(navigation.status, await navigation.clone().text()).toBe(200);
  expect(await navigation.text()).toBe("private-media-bytes");
  const sibling = await get(runtime, PRIVATE_HOST, mediaUrl(PRIVATE_SPACE), {
    headers: {
      cookie,
      origin: "http://sibling.test",
      "sec-fetch-site": "same-site",
      "sec-fetch-mode": "cors",
      "sec-fetch-dest": "empty",
    },
  });
  expect(sibling.status).toBe(403);
  expect(await sibling.text()).not.toContain("private-media-bytes");
});

test("a host-bound Content admin session reads unpublished Space media", async () => {
  const session = mintContentAdminSession(UNPUBLISHED_HOST, UNPUBLISHED_SPACE);
  const requestPath = mediaUrl(UNPUBLISHED_SPACE);
  const response = await get(runtime, UNPUBLISHED_HOST, requestPath, {
    headers: {
      cookie: `spacefast_content_admin=${session}`,
      referer: "https://slack.com/",
      "sec-fetch-site": "cross-site",
      "sec-fetch-mode": "navigate",
      "sec-fetch-dest": "document",
    },
  });

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("unpublished-media-bytes");
  for (const [origin, site] of [
    ["https://sibling.space.fast", "same-site"],
    ["https://other.example", "cross-site"],
  ]) {
    const subresource = await get(runtime, UNPUBLISHED_HOST, requestPath, {
      headers: {
        cookie: `spacefast_content_admin=${session}`,
        origin,
        "sec-fetch-site": site,
        "sec-fetch-mode": "cors",
        "sec-fetch-dest": "empty",
      },
    });
    expect(subresource.status).toBe(404);
    expect(await subresource.text()).not.toContain("unpublished-media-bytes");
  }
  // A sibling's iframe or no-referrer image carries the cookie but no Origin.
  for (const [mode, dest] of [
    ["navigate", "iframe"],
    ["no-cors", "image"],
  ]) {
    const embedded = await get(runtime, UNPUBLISHED_HOST, requestPath, {
      headers: {
        cookie: `spacefast_content_admin=${session}`,
        "sec-fetch-site": "same-site",
        "sec-fetch-mode": mode,
        "sec-fetch-dest": dest,
      },
    });
    expect(embedded.status, dest).toBe(404);
    expect(await embedded.text()).not.toContain("unpublished-media-bytes");
  }
  const ownPage = await get(runtime, UNPUBLISHED_HOST, requestPath, {
    headers: {
      cookie: `spacefast_content_admin=${session}`,
      "sec-fetch-site": "same-origin",
      "sec-fetch-mode": "no-cors",
      "sec-fetch-dest": "image",
    },
  });
  expect(ownPage.status).toBe(200);
  expect(await ownPage.text()).toBe("unpublished-media-bytes");
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
  const embedded = await get(runtime, OPEN_HOST, mediaUrl(OPEN_SPACE), {
    headers: {
      referer: "https://sibling.space.fast/",
      "sec-fetch-site": "same-site",
      "sec-fetch-mode": "no-cors",
      "sec-fetch-dest": "image",
    },
  });
  expect(embedded.status).toBe(200);
  expect(await embedded.text()).toBe("open-media-bytes");

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
