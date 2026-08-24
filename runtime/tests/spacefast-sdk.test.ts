import { afterAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, readdirSync, statSync } from "node:fs";
import path from "node:path";

import { canonicalPagePath, parseOverlayConfig } from "../../packages/collab-sdk/src/entry.ts";
import { readManifest } from "../../packages/collab-sdk/src/manifest.ts";
import {
  deploy,
  errorCode,
  get,
  postAccessCallback,
  problemDocument,
  publicAccessConfig,
  putRoute,
  signEd25519Jwt,
  spaceRoot,
  startRuntime,
  type Runtime,
  visitorIssuer,
} from "./harness.ts";

const SITE = "sdk.site.test";

const TENANT_MARKER = "TENANT SDK IMPOSTOR";
const TENANT_JS = `window.__hijacked=${JSON.stringify(TENANT_MARKER)};\n`;

// The space-level Comments block the control plane writes into the overlay.
// Everything per-request — this page's room key, the runtime's own same-origin
// ticket endpoint, which version host the visitor is on — is the runtime's to
// add, which is exactly why the config lane no longer leaves the serving host.
const LOCAL_COMMENTS = {
  live: true,
  preview: true,
  live_url: "https://live.example.test/",
  theme: { accent: null, hide_branding: false },
  features: { picker: true, drawing: true, capture: false, attachments: true, notices: true },
};

// Contracts §7 (D33/D85/D120): the cookie IS the session — `<prefix><base64url
// claims>.<hmac>`. `sfv2_` carries the authorities, the [access_gen,
// session_ver] tuple and the Comments identity; `sfa1_` carries no authority at
// all. The only server-side state left is one small revocation record per
// recorded session at spaces/<s>/sessions/<sid>.json.
const RECORDED_SESSION_PREFIX = "sfv2_";
const STATELESS_SESSION_PREFIX = "sfa1_";

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

function sessionRecords(runtime: Runtime, spaceId: string): string[] {
  const directory = path.join(spaceRoot(runtime, spaceId), "sessions");
  return existsSync(directory)
    ? readdirSync(directory).filter((file) => /^[a-f0-9]{64}\.json$/.test(file))
    : [];
}

let runtimes: Runtime[] = [];

afterAll(() => {
  for (const runtime of runtimes) runtime.stop();
});

test("same-host Spacefast SDK route boots tags without exposing a Comments surface", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_CAST_API_URL: "https://cast.example.test" },
  });
  runtimes.push(runtime);

  const accessConfig = publicAccessConfig({ mode: "website", site_title: "SDK" });
  // ONE SDK projection: the loader's config and the production tag module in
  // the same section. Nothing here names a second artifact.
  accessConfig.sdk = {
    revision: "sdk-public-1",
    config: { cast_api_base: "https://cast.example.test" },
    body: "window.__embeddedTagLoaded=true;",
  };
  await deploy(runtime, {
    spaceId: "spc_sdk",
    versionId: "ver_sdk_1",
    files: { "index.html": '<h1>SDK</h1><script src="/__spacefast/sdk.js"></script>\n' },
    activate: {
      route_name: "production",
      config: accessConfig,
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(runtime, SITE, "/__spacefast/sdk.js", {
    headers: {
      cookie: "harmless_publisher_cookie=one",
      origin: "https://first.example.test",
    },
  });
  expect(response.status).toBe(200);
  const body = await response.text();
  expect(body).toContain("window.Spacefast=window.Spacefast||{}");
  expect(body).toContain('"apiBase":"https://api.spacefast.com"');
  // Comments are not available for this surface, so the response carries no
  // Comments bytes at all: no embedded config, no placeholder orb, no module
  // loader. The permissions toggle is a byte budget, not a disabled flag.
  expect(body).not.toContain('"config"');
  expect(body).not.toContain("sf-collab-boot-orb");
  expect(body).not.toContain("collab.js");
  expect(body).not.toContain("resource_key");
  expect(body).not.toContain("cast_ws_url");
  expect(body).not.toContain("comments/ticket");
  expect(body).not.toContain("postMessage");
  const appended: Array<{
    async: boolean;
    dataset: Record<string, string>;
    src: string;
    type?: string;
  }> = [];
  const fakeWindow: Record<string, unknown> = {};
  const fakeDocument = {
    createElement: () => ({ async: false, dataset: {}, src: "", type: "" }),
    head: {
      appendChild: (script: (typeof appended)[number]) => appended.push(script),
    },
  };
  Function("window", "document", body)(fakeWindow, fakeDocument);
  expect(appended).toHaveLength(0);
  expect(fakeWindow.__embeddedTagLoaded).toBe(true);
  expect(response.headers.get("content-type")).toContain("application/javascript");
  expect(response.headers.get("access-control-allow-origin")).toBe("*");
  expect(response.headers.get("cross-origin-resource-policy")).toBe("cross-origin");
  expect(response.headers.get("timing-allow-origin")).toBe("*");
  expect(response.headers.get("etag")).toContain("runtime:");
  expect(response.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, stale-while-revalidate=60",
  );
  expect(response.headers.get("x-spacefast-sdk-revision")).toContain("runtime:");
  expect(response.headers.get("vary")).toBeNull();

  // A versioned SDK URL (?v=<token>, baked in by the control plane) is
  // content-addressed, so the same-host response may pin immutably while still
  // keeping ACAO * for cross-origin loads. The unversioned URL above must never
  // be immutable — an engine revision would freeze into the visitor's cache.
  const versioned = await get(runtime, SITE, "/__spacefast/sdk.js?v=engine-rev-abc", {
    headers: { origin: "https://third.example.test" },
  });
  expect(versioned.status).toBe(200);
  expect(await versioned.text()).toBe(body);
  expect(versioned.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect(versioned.headers.get("access-control-allow-origin")).toBe("*");

  // Preview overrides the version token: preview bytes are capability-selected,
  // so a versioned preview URL stays short-lived and revalidated, never pinned.
  const versionedPreview = await get(
    runtime,
    SITE,
    "/__spacefast/sdk.js?v=engine-rev-abc&preview=preview-token",
  );
  expect(versionedPreview.status).toBe(200);
  expect(versionedPreview.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, stale-while-revalidate=60",
  );

  const otherOrigin = await get(runtime, SITE, "/__spacefast/sdk.js", {
    headers: { origin: "https://second.example.test" },
  });
  expect(otherOrigin.status).toBe(200);
  expect(await otherOrigin.text()).toBe(body);
  expect(otherOrigin.headers.get("access-control-allow-origin")).toBe("*");
  expect(otherOrigin.headers.get("x-spacefast-sdk-revision")).toBe(
    response.headers.get("x-spacefast-sdk-revision"),
  );
  expect(otherOrigin.headers.get("vary")).toBeNull();

  const preview = await get(runtime, SITE, "/__spacefast/sdk.js?preview=preview-token", {
    headers: {
      "if-none-match": response.headers.get("etag") ?? "",
    },
  });
  expect(preview.status).toBe(200);
  expect(preview.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, stale-while-revalidate=60",
  );
  // A `?preview=` token names a tag release, never a surface. The live host is
  // the live surface whatever the script URL is decorated with — otherwise the
  // preview Comments lane could be consulted from the live site by anyone who
  // appended a query parameter.
  expect(await preview.text()).toContain('"environment":"production"');
  expect(await (await get(runtime, SITE, "/__spacefast/sdk.js")).text()).toContain(
    '"environment":"production"',
  );

  const genericPreview = await get(runtime, SITE, "/__spacefast/sdk.js", {
    headers: { referer: `https://${SITE}/docs?preview=true` },
  });
  expect(genericPreview.status).toBe(200);

  const genericPreviewPage = await get(runtime, SITE, "/?preview=true");
  expect(genericPreviewPage.status).toBe(200);
  expect(genericPreviewPage.headers.get("set-cookie") ?? "").not.toContain("spacefast_tag_preview");

  const previewPage = await get(runtime, SITE, "/?spacefast_tag_preview=preview-token");
  expect(previewPage.status).toBe(200);
  expect(previewPage.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, stale-while-revalidate=60",
  );
  expect(previewPage.headers.get("cdn-cache-control")).toBeNull();
  expect(previewPage.headers.get("surrogate-control")).toBeNull();
  expect(previewPage.headers.get("set-cookie") ?? "").not.toContain("spacefast_tag_preview");
  const previewBody = await previewPage.text();
  expect(previewBody).toContain("/__spacefast/sdk.js?preview=preview-token");
  expect(previewPage.headers.get("content-length")).toBe(String(Buffer.byteLength(previewBody)));

  const previewHead = await get(runtime, SITE, "/?spacefast_tag_preview=preview-token", {
    method: "HEAD",
  });
  expect(previewHead.status).toBe(200);
  expect(previewHead.headers.get("content-length")).toBe(previewPage.headers.get("content-length"));
  expect(await previewHead.text()).toBe("");

  // Configuration is answered on this host, out of the overlay — the lane never
  // leaves the serving host, so "no Comments here" is a disabled config rather
  // than a refusal that would be indistinguishable from an outage.
  const unconfigured = await get(runtime, SITE, "/__spacefast/comments/config", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      origin: `http://${SITE}`,
      "sec-fetch-site": "same-origin",
    },
    body: JSON.stringify({ pagePath: "/" }),
  });
  expect(unconfigured.status).toBe(200);
  const unconfiguredConfig = (await unconfigured.json()) as {
    data: { enabled: boolean; resource_key: string | null; ws_url: string | null };
  };
  // Nothing to connect to, and nothing named that the page could not already
  // see: no resource key, no socket. The room key is not here at all — it is
  // per page, and this response is not.
  expect(unconfiguredConfig.data).toMatchObject({
    enabled: false,
    resource_key: null,
    ws_url: null,
  });
  expect(unconfiguredConfig.data).not.toHaveProperty("room_key");

  const staleIdentity = await get(runtime, SITE, "/__spacefast/sdk.js", {
    headers: {
      cookie: `spacefast_session_dev=${RECORDED_SESSION_PREFIX}${"d".repeat(64)}`,
      referer: `https://${SITE}/`,
      "sec-fetch-dest": "script",
    },
  });
  expect(staleIdentity.status).toBe(200);
  // One cookie to clear, and no diagnostic marker beside it: the expiry
  // travels through the flow that renders next, not through a second cookie.
  // This Space projects no exchange credential, so the runtime holds no key to
  // mint the `sfa1_` replacement that would carry it — the cookie just goes.
  expect(staleIdentity.headers.getSetCookie()).toEqual([
    "spacefast_session_dev=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax",
  ]);
  expect(staleIdentity.headers.get("cache-control")).toBe("private, no-store");
  expect(staleIdentity.headers.get("access-control-allow-origin")).toBeNull();
});

test("same-host Spacefast SDK route restores the in-page Comments module", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: "" },
  });
  runtimes.push(runtime);

  const accessConfig = publicAccessConfig(
    { mode: "website", site_title: "Local SDK" },
    "live_and_all_versions",
  );
  const authorization = accessConfig.authorization;
  if (typeof authorization !== "object" || authorization === null) {
    throw new Error("public access fixture is missing authorization");
  }
  Object.assign(authorization, {
    acquireUrl: "https://api.spacefast.com/acquire/opaque-comments-target",
    accessPage: {
      displayName: "Local SDK",
      accountUrl: null,
      connections: [],
      exchange: {
        passwordUrl: "https://api.spacefast.com/acquire/opaque-comments-target/password",
        tokenUrl: "https://api.spacefast.com/acquire/opaque-comments-target/token",
        emailUrl: "https://api.spacefast.com/acquire/opaque-comments-target/email",
        requestUrl: "https://api.spacefast.com/acquire/opaque-comments-target/request",
        logoutUrl: "https://api.spacefast.com/runtime/collaboration-sessions/revoke",
        commentsTicketUrl:
          "https://api.spacefast.com/runtime/comments/opaque-comments-target/ticket",
        credential: "runtime-comments-credential-0000000000000000000000000000",
      },
    },
  });
  accessConfig.sdk = {
    revision: "sdk-local-1",
    config: {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_local",
      comments: LOCAL_COMMENTS,
    },
    body: "window.__localTagLoaded=true;",
  };

  await deploy(runtime, {
    spaceId: "spc_sdk_local",
    versionId: "ver_sdk_local_1",
    files: {
      "index.html": '<h1>Local SDK</h1><script src="/__spacefast/sdk.js"></script>\n',
      "plain.html": "<main>Plain preview</main>\n",
    },
    activate: {
      route_name: "production",
      config: accessConfig,
      production_hostnames: ["local-sdk.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [
        { hostname: "version-local-sdk.site.test", version_id: "ver_sdk_local_1" },
      ],
    },
  });

  const response = await get(runtime, "local-sdk.site.test", "/__spacefast/sdk.js");
  expect(response.status).toBe(200);
  const body = await response.text();
  const appendedScripts: Array<{
    async: boolean;
    dataset: Record<string, string>;
    src: string;
    type: string;
  }> = [];
  type FakeElement = {
    async: boolean;
    dataset: Record<string, string>;
    id: string;
    innerHTML: string;
    onerror?: () => void;
    remove: () => void;
    src: string;
    style: { cssText: string };
    tagName: string;
    type: string;
  };
  const appendedToBody: FakeElement[] = [];
  const collabErrors: string[] = [];
  const fakeWindow: {
    Spacefast?: {
      collabLoader?: unknown;
      manifest?: { apiBase?: string; config?: Record<string, unknown> };
    };
    __localTagLoaded?: boolean;
    dispatchEvent?: (event: { type: string }) => boolean;
    CustomEvent?: typeof CustomEvent;
  } = {
    dispatchEvent: (event) => {
      collabErrors.push(event.type);
      return true;
    },
    CustomEvent,
  };
  const fakeDocument = {
    body: {
      appendChild: (node: FakeElement) => {
        appendedToBody.push(node);
      },
    },
    createElement: (tagName: string) => {
      const element = {
        async: false,
        dataset: {},
        href: "",
        id: "",
        innerHTML: "",
        rel: "",
        setAttribute: () => undefined,
        src: "",
        style: { cssText: "" },
        tagName,
        target: "",
        textContent: "",
        type: "",
        remove: () => {
          const at = appendedToBody.indexOf(element as unknown as FakeElement);
          if (at !== -1) appendedToBody.splice(at, 1);
        },
      };
      return element;
    },
    getElementById: (id: string) => appendedToBody.find((node) => node.id === id) ?? null,
    head: {
      appendChild: (script: (typeof appendedScripts)[number]) => {
        appendedScripts.push(script);
      },
    },
  };

  // The visitor last parked the orb on the left edge, a third of the way down.
  const storedPlacement = JSON.stringify({ edge: "left", along: 0.33, inset: 16 });
  Function(
    "window",
    "document",
    "location",
    "localStorage",
    "innerWidth",
    "innerHeight",
    body,
  )(
    fakeWindow,
    fakeDocument,
    {
      host: "local-sdk.site.test",
      origin: "https://local-sdk.site.test",
      pathname: "/docs/getting-started",
    },
    { getItem: () => storedPlacement },
    1_000,
    900,
  );

  // The whole OverlayConfig arrives inline: boot asks this host for nothing.
  expect(fakeWindow.Spacefast?.manifest).toEqual({
    version: 4,
    environment: "production",
    host: "local-sdk.site.test",
    spaceId: "spc_sdk_local",
    versionId: "ver_sdk_local_1",
    apiBase: "https://api.example.test",
    accountUrl: null,
    layout: "/__/collab",
    config: {
      enabled: true,
      resource_key: "resource_local",
      version: { id: null, current: "ver_sdk_local_1", url: null },
      space: { live_url: null },
      theme: { accent: null, hide_branding: false },
      ws_url: "wss://cast.example.test/socket/websocket",
      endpoints: {
        ticket: "http://local-sdk.site.test/__spacefast/comments/ticket",
        storage: "http://local-sdk.site.test/storage",
      },
      // The revocable read key rides the served config: that IS the fresh-URL
      // mechanism, so its exact value is asserted against the box's key file.
      uploads: {
        base: "http://local-sdk.site.test/__stattic/u/",
        key: expect.stringMatching(/^[a-f0-9]{32}$/),
      },
      features: { picker: true, drawing: true, capture: false, attachments: true, notices: true },
    },
  });
  // The room key is the one thing NOT here: it is per page and this response
  // is shared across every page of the Space. The SDK derives it.
  expect(fakeWindow.Spacefast?.manifest?.config).not.toHaveProperty("room_key");

  // ...and the shape above is not just the shape this test wrote down: the
  // SDK's own parsers are the ones that have to accept it. `readManifest` and
  // `parseOverlayConfig` are exactly what `boot()` runs, in the same order,
  // against exactly what PHP emitted. A producer/consumer drift on any field
  // either parser insists on fails here instead of at a visitor's first paint.
  const parsedManifest = readManifest(fakeWindow.Spacefast?.manifest);
  expect(parsedManifest).not.toBeNull();
  const pagePath = canonicalPagePath("/docs/getting-started");
  const parsedConfig = parseOverlayConfig(
    parsedManifest?.config,
    parsedManifest?.spaceId ?? "",
    pagePath,
  );
  expect(parsedConfig).toMatchObject({
    enabled: true,
    resource_key: "resource_local",
    room_key: "space:spc_sdk_local:path:%2Fdocs%2Fgetting-started",
    ws_url: "wss://cast.example.test/socket/websocket",
    endpoints: { ticket: "http://local-sdk.site.test/__spacefast/comments/ticket" },
    features: { picker: true, drawing: true, capture: false, attachments: true, notices: true },
  });
  expect(body).not.toContain("document.cookie");
  expect(body).not.toContain("runtime-comments-credential-0000000000000000000000000000");
  expect(appendedScripts).toHaveLength(1);
  expect(appendedScripts[0]).toMatchObject({
    async: true,
    src: "https://cast.example.test/sdk/v1/collab.js",
    type: "module",
  });

  // The orb is painted before a single Cast byte is fetched, in the placement
  // the visitor last chose, at the top of the stacking order.
  expect(appendedToBody).toHaveLength(1);
  const placeholderOrb = appendedToBody[0];
  expect(placeholderOrb?.id).toBe("sf-collab-boot-orb");
  expect(placeholderOrb?.style.cssText).toContain("position:fixed");
  expect(placeholderOrb?.style.cssText).toContain("z-index:2147483000");
  expect(placeholderOrb?.style.cssText).toContain("width:44px");
  // edge=left inset=16 -> `left:16px`; along 0.33 of a 900px tall viewport,
  // centred on a 44px disc -> top:275px (0.33*900-22).
  expect(placeholderOrb?.style.cssText).toContain("left:16px");
  expect(placeholderOrb?.style.cssText).toContain("top:275px");
  expect(placeholderOrb?.innerHTML).toContain("#ff603d");
  expect(placeholderOrb?.innerHTML).toContain("sf-boot-pulse");

  // ...and it stands in for an overlay that is ARRIVING. A module that never
  // loads leaves it standing in for nothing, so the failure takes it down: a
  // disc pulsing forever is a worse lie than no orb at all.
  (appendedScripts[0] as unknown as { onerror?: () => void }).onerror?.();
  expect(appendedToBody).toHaveLength(0);
  expect(collabErrors).toEqual(["spacefast:collab-error"]);

  // The same response inside an iframe paints nothing: framed documents
  // suppress the orb for their whole life (collab-frame-plan §2), so a
  // placeholder there could only flash. The SDK module still loads — the frame
  // handshake is what it does in there.
  const framedScripts: typeof appendedScripts = [];
  const framedBody: FakeElement[] = [];
  Function(
    "window",
    "document",
    "location",
    "localStorage",
    "innerWidth",
    "innerHeight",
    body,
  )(
    { self: { name: "iframe" }, top: { name: "shell" } },
    {
      ...fakeDocument,
      body: { appendChild: (node: FakeElement) => framedBody.push(node) },
      head: {
        appendChild: (script: (typeof appendedScripts)[number]) => framedScripts.push(script),
      },
    },
    {
      host: "local-sdk.site.test",
      origin: "https://local-sdk.site.test",
      pathname: "/docs/getting-started",
    },
    { getItem: () => storedPlacement },
    1_000,
    900,
  );
  expect(framedBody).toHaveLength(0);
  expect(framedScripts.map((script) => script.src)).toEqual([
    "https://cast.example.test/sdk/v1/collab.js",
  ]);

  expect(fakeWindow.__localTagLoaded).toBe(true);
  expect(response.headers.get("access-control-allow-origin")).toBe("*");

  // Insurance against a version baked against a developer machine: a Cast
  // origin only that machine resolves must never be injected into a page a
  // real visitor loaded. The rest of the SDK still boots.
  const claimedConfig = structuredClone(accessConfig) as typeof accessConfig & {
    authorization: { accessPage: { accountUrl: string | null } };
    sdk: { config: Record<string, unknown> };
  };
  claimedConfig.authorization.accessPage.accountUrl =
    "https://api.spacefast.com/v1/access/acquire/opaque-comments-target";
  claimedConfig.sdk.config = {
    cast_api_base: "https://cast.sf.localhost",
    cast_ws_url: "wss://cast.sf.localhost/socket/websocket",
    cast_resource_key: "resource_baked_local",
    comments: LOCAL_COMMENTS,
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_baked_local",
    versionId: "ver_sdk_baked_local_1",
    files: { "index.html": "<h1>Baked local</h1>\n" },
    activate: {
      route_name: "production",
      config: claimedConfig,
      production_hostnames: ["baked-local.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const bakedRemote = await get(runtime, "baked-local.site.test", "/__spacefast/sdk.js");
  expect(bakedRemote.status).toBe(200);
  const bakedBody = await bakedRemote.text();
  // No Cast origin the visitor could reach means no Comments: nothing embedded,
  // no placeholder orb, no module loader.
  expect(bakedBody).not.toContain("collab.js");
  expect(bakedBody).not.toContain("sf-collab-boot-orb");
  expect(bakedBody).not.toContain("resource_baked_local");
  // The manifest still names where a guest goes to become themselves, so the
  // SDK's identity CTA has somewhere to send them.
  expect(bakedBody).toContain(
    '"accountUrl":"https://api.spacefast.com/v1/access/acquire/opaque-comments-target"',
  );

  // The other half of the same rule: a wholly local stack (dev, docker e2e)
  // reaches its own control plane locally too, so a local Cast origin is
  // internally consistent and Comments must still load.
  const localStackConfig = structuredClone(accessConfig) as typeof accessConfig & {
    authorization: { accessPage: { exchange: Record<string, string> } };
    sdk: { config: Record<string, unknown> };
  };
  for (const [key, value] of Object.entries(localStackConfig.authorization.accessPage.exchange)) {
    if (value.startsWith("https://api.spacefast.com")) {
      localStackConfig.authorization.accessPage.exchange[key] = value.replace(
        "https://api.spacefast.com",
        "http://localhost:3100",
      );
    }
  }
  localStackConfig.sdk.config = {
    cast_api_base: "http://localhost:4400",
    cast_ws_url: "ws://localhost:4400/socket/websocket",
    cast_resource_key: "resource_local_stack",
    comments: LOCAL_COMMENTS,
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_local_stack",
    versionId: "ver_sdk_local_stack_1",
    files: { "index.html": "<h1>Local stack</h1>\n" },
    activate: {
      route_name: "production",
      config: localStackConfig,
      production_hostnames: ["local-stack.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const localStack = await get(runtime, "local-stack.site.test", "/__spacefast/sdk.js");
  expect(localStack.status).toBe(200);
  expect(await localStack.text()).toContain('"http://localhost:4400/sdk/v1/collab.js"');
});

test("Comments configuration stays on-origin while the runtime authenticates upstream", async () => {
  const exchanges: Array<{ credential: string | null; payload: Record<string, unknown> }> = [];
  let ticketFailureCode: string | null = null;
  const central = Bun.serve({
    port: 0,
    async fetch(request) {
      const fields = new URLSearchParams(await request.text());
      exchanges.push({
        credential: request.headers.get("spacefast-runtime-exchange"),
        payload: JSON.parse(fields.get("payload") ?? "{}") as Record<string, unknown>,
      });
      if (new URL(request.url).pathname.endsWith("/version-urls")) {
        return Response.json({
          data: [{ id: "ver_other", url: "https://other.view.test/", number: 4, createdAt: null }],
        });
      }
      if (new URL(request.url).pathname.endsWith("/zero/realtime-ticket")) {
        return Response.json({
          data: { token: "zero-cast-ticket-token", expiresAt: "2099-01-01T00:00:00.000Z" },
        });
      }
      if (new URL(request.url).pathname.endsWith("/ticket")) {
        if (ticketFailureCode) {
          return Response.json(
            {
              type: `https://spacefast.com/docs/errors/${ticketFailureCode}`,
              title: "Comments reauth required",
              status: 401,
              detail: "Reload this page to sign in again.",
              code: ticketFailureCode,
            },
            { status: 401, headers: { "content-type": "application/problem+json" } },
          );
        }
        return Response.json({
          data: {
            token: "cast-ticket-token",
            expiresAt: "2099-01-01T00:00:00.000Z",
            cast: { resource: "resource_local", room: "space:spc_sdk_exchange:path:%2Fdocs" },
          },
        });
      }
      // Nothing else reaches the control plane on these lanes: configuration
      // is the overlay's, and only mints travel.
      return Response.json({ error: { code: "unexpected_exchange_lane" } }, { status: 500 });
    },
  });
  try {
    const runtime = await startRuntime();
    runtimes.push(runtime);
    const config = publicAccessConfig({ mode: "website", site_title: "Local SDK exchange" });
    const authorization = config.authorization;
    if (typeof authorization !== "object" || authorization === null) {
      throw new Error("public access fixture is missing authorization");
    }
    Object.assign(authorization, {
      accessPage: {
        displayName: "Local SDK exchange",
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: `${central.url}acquire/runtime-comments/password`,
          tokenUrl: `${central.url}acquire/runtime-comments/token`,
          requestUrl: `${central.url}acquire/runtime-comments/request`,
          commentsTicketUrl: `${central.url}runtime/comments/runtime-comments/ticket`,
          commentsVersionUrlsUrl: `${central.url}runtime/comments/runtime-comments/version-urls`,
          zeroRealtimeTicketUrl: `${central.url}acquire/runtime-comments/zero/realtime-ticket`,
          credential: "runtime-comments-credential-0000000000000000000000000000",
        },
      },
    });
    config.sdk = {
      revision: "sdk-exchange-1",
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_local",
        comments: LOCAL_COMMENTS,
      },
    };
    await deploy(runtime, {
      spaceId: "spc_sdk_exchange",
      versionId: "ver_sdk_exchange_1",
      files: { "docs/index.html": "<h1>Docs</h1>\n" },
      activate: {
        route_name: "production",
        config,
        production_hostnames: ["comments-exchange.site.test"],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const response = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/config",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs" }),
      },
    );
    expect(response.status).toBe(200);
    // THE headline: configuration is assembled here, from the overlay. The
    // control plane is not called at all. This lane is no longer on the boot
    // path — the same bytes ride the SDK bootstrap — so all it does now is let
    // a running overlay sync on a toggle flipped since that response was
    // generated.
    expect(exchanges).toHaveLength(0);
    const payload = (await response.json()) as { data: Record<string, unknown> };
    expect(payload.data).toEqual({
      enabled: true,
      resource_key: "resource_local",
      // Comments-on-live IS the live context: nothing to link back to.
      version: { id: null, current: "ver_sdk_exchange_1", url: null },
      space: { live_url: null },
      theme: { accent: null, hide_branding: false },
      ws_url: "wss://cast.example.test/socket/websocket",
      endpoints: {
        ticket: "http://comments-exchange.site.test/__spacefast/comments/ticket",
        storage: "http://comments-exchange.site.test/storage",
      },
      uploads: {
        base: "http://comments-exchange.site.test/__stattic/u/",
        key: expect.stringMatching(/^[a-f0-9]{32}$/),
      },
      features: { picker: true, drawing: true, capture: false, attachments: true, notices: true },
    });
    expect(response.headers.get("cache-control")).toContain("no-store");
    // A config request costs the visitor no session: only the mint below does.
    expect(response.headers.getSetCookie()).toHaveLength(0);

    const ticket = (headers: Record<string, string> = {}) =>
      get(runtime, "comments-exchange.site.test", "/__spacefast/comments/ticket", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
          ...headers,
        },
        body: JSON.stringify({
          pagePath: "/docs",
          identity: { anonymousId: "anon_browser_chosen", name: "Page visitor", namedByUser: true },
        }),
      });
    const forwardedIdentity = (exchange?: { payload: Record<string, unknown> }) =>
      (exchange?.payload["identity"] ?? {}) as {
        anonymousId?: string;
        name?: string;
        namedByUser?: boolean;
      };

    // A visitor with no admission (this space is public) still gets ONE host
    // session — started right here — and their whole Comments identity lives
    // in it. The page-chosen anonymous id never reaches the control plane.
    // That session is stateless: a public visitor proved nothing, so the ticket
    // lane writes no revocation record under spaces/<s>/sessions and cannot be
    // flooded into evicting the records of visitors who did.
    const minted = await ticket();
    expect(minted.status).toBe(200);
    expect(((await minted.json()) as { data: { token: string } }).data.token).toBe(
      "cast-ticket-token",
    );
    const mintedCookies = minted.headers
      .getSetCookie()
      .map((cookie) => cookie.split(";", 1)[0] ?? "");
    expect(mintedCookies).toHaveLength(1);
    const commentsCookie = mintedCookies[0] ?? "";
    expect(commentsCookie).toStartWith(`spacefast_session_dev=${STATELESS_SESSION_PREFIX}`);
    expect(sessionRecords(runtime, "spc_sdk_exchange")).toEqual([]);
    // The mint is the ONLY lane that reaches the control plane, and it states
    // who the visitor is: anonymous on a public page with no admission, read
    // from the session rather than guessed from the (empty) authority list.
    expect(exchanges).toHaveLength(1);
    expect(exchanges[0]).toMatchObject({
      credential: "runtime-comments-credential-0000000000000000000000000000",
      payload: {
        origin: "http://comments-exchange.site.test",
        pagePath: "/docs",
        principal: "anonymous",
        authorities: [],
      },
    });
    const firstIdentity = forwardedIdentity(exchanges[0]);
    expect(firstIdentity.anonymousId).toMatch(/^anon_[a-f0-9]{32}$/);
    expect(firstIdentity.name).toBe("Page visitor");
    expect(firstIdentity.namedByUser).toBe(true);
    const firstSessionId = exchanges[0]?.payload["visitorSessionId"];
    expect(firstSessionId).toMatch(/^[a-f0-9]{64}$/);

    const replayed = await ticket({ cookie: commentsCookie });
    expect(replayed.status).toBe(200);
    expect(replayed.headers.getSetCookie()).toHaveLength(0);
    expect(forwardedIdentity(exchanges[1]).anonymousId).toBe(firstIdentity.anonymousId);
    expect(exchanges[1]?.payload["visitorSessionId"]).toBe(firstSessionId);

    // An unknown session id degrades to a fresh identity instead of trusting
    // the presented one.
    const tampered = await ticket({
      cookie: commentsCookie.endsWith("0")
        ? `${commentsCookie.slice(0, -1)}1`
        : `${commentsCookie.slice(0, -1)}0`,
    });
    expect(tampered.status).toBe(200);
    const tamperedIdentity = forwardedIdentity(exchanges[2]);
    expect(tamperedIdentity.anonymousId).toMatch(/^anon_[a-f0-9]{32}$/);
    expect(tamperedIdentity.anonymousId).not.toBe(firstIdentity.anonymousId);

    const zeroTicket = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/zero/realtime-ticket",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs" }),
      },
    );
    expect(zeroTicket.status).toBe(200);
    expect(await zeroTicket.json()).toEqual({
      data: { token: "zero-cast-ticket-token", expiresAt: "2099-01-01T00:00:00.000Z" },
    });
    expect(zeroTicket.headers.getSetCookie()).toHaveLength(0);
    expect(exchanges.at(-1)).toEqual({
      credential: "runtime-comments-credential-0000000000000000000000000000",
      payload: {
        origin: "http://comments-exchange.site.test",
        pagePath: "/docs",
        versionId: "ver_sdk_exchange_1",
      },
    });

    // Cross-version thread links ride the same relay: a published page can
    // only reach the control plane through its own host, and the page states
    // just the ids it wants — never the space, version or visitor identity.
    const versionUrls = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/version-urls",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs", ids: ["ver_other", "ver_other", ""] }),
      },
    );
    expect(versionUrls.status).toBe(200);
    expect(await versionUrls.json()).toEqual({
      data: [{ id: "ver_other", url: "https://other.view.test/", number: 4, createdAt: null }],
    });
    const relayed = exchanges.at(-1);
    expect(relayed?.credential).toBe("runtime-comments-credential-0000000000000000000000000000");
    expect(relayed?.payload["ids"]).toEqual(["ver_other"]);
    expect(relayed?.payload["pagePath"]).toBe("/docs");

    // A lookup naming nothing is refused here rather than proxied into a
    // schema failure upstream.
    const before = exchanges.length;
    const emptyLookup = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/version-urls",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs", ids: [] }),
      },
    );
    expect(emptyLookup.status).toBe(401);
    expect(await errorCode(emptyLookup)).toBe("comments_versions_invalid");
    expect(exchanges.length).toBe(before);

    // The lane is POST-only and fetch-only: a GET is refused outright and a
    // cross-site POST never reaches the exchange.
    const got = await get(runtime, "comments-exchange.site.test", "/__spacefast/comments/ticket");
    expect(got.status).toBe(405);
    expect(got.headers.get("allow")).toBe("POST");
    const crossSite = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/ticket",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "https://evil.example",
          "sec-fetch-site": "cross-site",
        },
        body: JSON.stringify({ pagePath: "/docs" }),
      },
    );
    expect(crossSite.status).toBe(401);
    expect(await errorCode(crossSite)).toBe("comments_origin_invalid");

    // A malformed identity is refused with its own code, never proxied.
    const badIdentity = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/ticket",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs", identity: { name: 5, namedByUser: "yes" } }),
      },
    );
    expect(badIdentity.status).toBe(401);
    expect(await errorCode(badIdentity)).toBe("comments_identity_invalid");

    // A refused mint preserves the host session: Comments cannot rewrite the
    // visitor's broader Space identity over an exchange failure.
    ticketFailureCode = "comments_reauth_required";
    const staleSession = await ticket({ cookie: commentsCookie });
    expect(staleSession.status).toBe(401);
    expect(staleSession.headers.get("content-type")).toContain("application/problem+json");
    expect(await errorCode(staleSession)).toBe("comments_reauth_required");
    expect(staleSession.headers.getSetCookie()).toHaveLength(0);
    ticketFailureCode = null;
    const exchangesBeforeOutage = exchanges.length;

    // An unreachable control plane is a 502, distinguishable from denial.
    central.stop(true);
    const outage = await ticket();
    expect(outage.status).toBe(502);
    expect(outage.headers.get("cache-control")).toContain("no-store");
    expect(outage.headers.get("content-type")).toContain("application/problem+json");
    expect(await errorCode(outage)).toBe("comments_exchange_unavailable");
    expect(exchanges.length).toBe(exchangesBeforeOutage);
  } finally {
    central.stop(true);
  }
});

test("a promote repoints the room key the overlay derives on a version host", async () => {
  // The room a page belongs to turns on ONE comparison — is the version being
  // served the live one — and the two sides read it from different places: the
  // SDK from `version.current` in this overlay, the mint from the live channel
  // pointer in the control plane's database. So `version.current` may never be
  // a snapshot of whatever was live when the version was published: a promote
  // that left it stale would have a version host derive its own draft room
  // while the ticket named the persistent one, and Cast binds a ticket to the
  // room in the join topic. Fresh inputs pass on stale code — only an actual
  // promote (and a rollback back off it) proves this.
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const spaceId = "spc_sdk_promote";
  const first = "ver_sdk_promote_1";
  const second = "ver_sdk_promote_2";
  const versionHost = "version-2-promote.site.test";
  const routeBody = (versionId: string) => {
    const config = publicAccessConfig({}, "live_and_all_versions");
    const authorization = config.authorization;
    if (typeof authorization !== "object" || authorization === null) {
      throw new Error("public access fixture is missing authorization");
    }
    Object.assign(authorization, {
      accessPage: {
        displayName: "Promote",
        accountUrl: null,
        connections: [],
        exchange: {
          commentsTicketUrl: "https://api.example.test/runtime/comments/opaque/ticket",
          credential: "runtime-comments-credential-0000000000000000000000000000",
        },
      },
    });
    config.sdk = {
      revision: "sdk-promote-1",
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_local",
        comments: LOCAL_COMMENTS,
      },
    };
    return {
      version_id: versionId,
      config,
      production_hostnames: ["promote.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [
        { hostname: "version-1-promote.site.test", version_id: first },
        { hostname: versionHost, version_id: second },
      ],
    };
  };
  // The room key the SDK's own parser derives from what this host serves —
  // `parseOverlayConfig` is what boot() runs, so this is the key the overlay
  // would put in the join topic.
  const derivedRoomKey = async (host: string) => {
    const response = await get(runtime, host, "/__spacefast/comments/config", {
      method: "POST",
      headers: { "content-type": "application/json", origin: `http://${host}` },
      body: JSON.stringify({ pagePath: "/" }),
    });
    expect(response.status).toBe(200);
    const payload = (await response.json()) as { data: unknown };
    return parseOverlayConfig(payload.data, spaceId, canonicalPagePath("/"))?.room_key ?? null;
  };

  await deploy(runtime, {
    spaceId,
    versionId: first,
    files: { "index.html": "<h1>One</h1>\n" },
    activate: { route_name: "production", ...routeBody(first) },
  });
  await deploy(runtime, { spaceId, versionId: second, files: { "index.html": "<h1>Two</h1>\n" } });
  await putRoute(runtime, spaceId, "production", routeBody(first));

  // Previewed but not live: its own draft room, so review threads stay off the
  // published page's permanent record.
  expect(await derivedRoomKey(versionHost)).toBe(`space:${spaceId}:draft:${second}:path:%2F`);

  await putRoute(runtime, spaceId, "production", routeBody(second));

  // Promoted: the same host is now serving the live version, so its threads
  // ARE the published page's conversation.
  expect(await derivedRoomKey(versionHost)).toBe(`space:${spaceId}:path:%2F`);
  expect(await derivedRoomKey("promote.site.test")).toBe(`space:${spaceId}:path:%2F`);

  // ...and a rollback hands it back its draft room.
  await putRoute(runtime, spaceId, "production", routeBody(first));
  expect(await derivedRoomKey(versionHost)).toBe(`space:${spaceId}:draft:${second}:path:%2F`);
});

test("a secure runtime reports the https published origin to the exchange", async () => {
  const exchanges: Array<Record<string, unknown>> = [];
  const central = Bun.serve({
    port: 0,
    async fetch(request) {
      const fields = new URLSearchParams(await request.text());
      exchanges.push(JSON.parse(fields.get("payload") ?? "{}") as Record<string, unknown>);
      return Response.json({
        data: {
          token: "cast-ticket-token",
          expiresAt: "2099-01-01T00:00:00.000Z",
          cast: { resource: "resource_secure", room: "space:spc_sdk_secure:path:%2Fdocs" },
        },
      });
    },
  });
  try {
    const runtime = await startRuntime({ env: { SPACEFAST_INSECURE_COOKIES: "" } });
    runtimes.push(runtime);
    const config = publicAccessConfig({ mode: "website", site_title: "Secure exchange" });
    const authorization = config.authorization;
    if (typeof authorization !== "object" || authorization === null) {
      throw new Error("public access fixture is missing authorization");
    }
    Object.assign(authorization, {
      accessPage: {
        displayName: "Secure exchange",
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: `${central.url}acquire/runtime-comments/password`,
          tokenUrl: `${central.url}acquire/runtime-comments/token`,
          requestUrl: `${central.url}acquire/runtime-comments/request`,
          commentsTicketUrl: `${central.url}runtime/comments/runtime-comments/ticket`,
          credential: "runtime-comments-credential-0000000000000000000000000000",
        },
      },
    });
    config.sdk = {
      revision: "sdk-secure-1",
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_secure",
        comments: LOCAL_COMMENTS,
      },
    };
    await deploy(runtime, {
      spaceId: "spc_sdk_secure",
      versionId: "ver_sdk_secure_1",
      files: { "docs/index.html": "<h1>Docs</h1>\n" },
      activate: {
        route_name: "production",
        config,
        production_hostnames: ["secure-comments.site.test"],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const response = await get(
      runtime,
      "secure-comments.site.test",
      "/__spacefast/comments/ticket",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "https://secure-comments.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({
          pagePath: "/docs",
          identity: { anonymousId: "anon_x", name: "Visitor", namedByUser: false },
        }),
      },
    );
    expect(response.status).toBe(200);
    expect(exchanges[0]?.["origin"]).toBe("https://secure-comments.site.test");
    // The same origin the runtime reports upstream is the one it hands the
    // browser for its own ticket endpoint.
    const configLane = (await get(
      runtime,
      "secure-comments.site.test",
      "/__spacefast/comments/config",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "https://secure-comments.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs" }),
      },
    ).then((r) => r.json())) as { data: { endpoints: { ticket: string } } };
    expect(configLane.data.endpoints.ticket).toBe(
      "https://secure-comments.site.test/__spacefast/comments/ticket",
    );
  } finally {
    central.stop(true);
  }
});

test("private SDK bytes require admission before any body", async () => {
  const exchanges: Array<Record<string, unknown>> = [];
  let ticketFailureCode: string | null = null;
  const central = Bun.serve({
    port: 0,
    async fetch(request) {
      const fields = new URLSearchParams(await request.text());
      exchanges.push(JSON.parse(fields.get("payload") ?? "{}") as Record<string, unknown>);
      if (new URL(request.url).pathname.endsWith("/ticket") && ticketFailureCode) {
        return Response.json(problemDocument(401, ticketFailureCode), {
          status: 401,
          headers: { "content-type": "application/problem+json" },
        });
      }
      return Response.json({
        data: {
          token: "cast-ticket-token",
          expiresAt: "2099-01-01T00:00:00.000Z",
          cast: { resource: "resource_private", room: "space:spc_sdk_private:path:%2Fdocs" },
        },
      });
    },
  });
  const runtime = await startRuntime();
  runtimes.push(runtime);

  const host = "private-sdk.site.test";
  const authority = "member:mem_private_sdk";
  const keyPair = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(keyPair.publicKey);
  const publicJwk = keyPair.publicKey.export({ format: "jwk" });
  const config = publicAccessConfig({ mode: "website", site_title: "Private SDK" });
  config.visitor_issuer = "spacefast-api";
  config.visitor_jwks = {
    keys: [
      {
        kty: "OKP",
        crv: "Ed25519",
        kid: issuer.kid,
        alg: "EdDSA",
        use: "sig",
        x: publicJwk.x ?? "",
      },
    ],
  };
  config.session_version = 0;
  config.authorization = {
    generation: 1,
    sessionVersion: 0,
    fence: "none",
    acquireUrl: "https://access.spacefast.test/acquire/private-sdk",
    spaceClaimed: true,
    grants: [
      {
        id: "grt_private_sdk_public_page",
        generation: 1,
        audience: { kind: "public" },
        resources: { include: ["/public"], exclude: [] },
        capabilities: ["page.view"],
        constraints: {},
        target: { kind: "live" },
        source: { kind: "system", reference: "test:private-sdk-public-page" },
      },
      {
        id: "grt_private_sdk_member",
        generation: 1,
        audience: {
          kind: "external",
          issuer: "spacefast-membership",
          subject: authority.replace(/^member:/, ""),
        },
        resources: { include: ["/docs/**"], exclude: [] },
        capabilities: ["page.view"],
        constraints: {},
        target: { kind: "live" },
        source: { kind: "system", reference: "test:private-sdk-member" },
      },
    ],
    accessPage: {
      displayName: "Private SDK",
      accountUrl: null,
      connections: [],
      exchange: {
        passwordUrl: `${central.url}acquire/private-sdk/password`,
        tokenUrl: `${central.url}acquire/private-sdk/token`,
        requestUrl: `${central.url}acquire/private-sdk/request`,
        commentsTicketUrl: `${central.url}runtime/comments/private-sdk/ticket`,
        credential: "runtime-comments-credential-0000000000000000000000000000",
      },
    },
  };
  config.sdk = {
    revision: "sdk-private-1",
    config: {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_private",
      comments: LOCAL_COMMENTS,
    },
    body: 'window.__privateTagSecret="private-tag-marker";',
  };

  await deploy(runtime, {
    spaceId: "spc_sdk_private",
    versionId: "ver_sdk_private_1",
    files: {
      "index.html": '<script src="/__spacefast/sdk.js"></script>\n',
      "docs/index.html": '<h1>Private docs</h1><script src="/__spacefast/sdk.js"></script>\n',
    },
    activate: {
      route_name: "production",
      config,
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const bare = await get(runtime, host, "/__spacefast/sdk.js");
  expect(bare.status).toBe(403);
  expect(bare.headers.get("etag")).toBeNull();

  const forgedPageContext = await get(runtime, host, "/__spacefast/sdk.js", {
    headers: {
      referer: `https://${host}/public`,
      "sec-fetch-dest": "script",
    },
  });
  expect(forgedPageContext.status).toBe(403);
  expect(forgedPageContext.headers.get("etag")).toBeNull();

  const now = Math.floor(Date.now() / 1000);
  const token = signEd25519Jwt(keyPair.privateKey, issuer.kid, {
    sub: authority,
    purpose: "handoff",
    grants: [authority],
    authorities: [authority],
    iss: "spacefast-api",
    aud: "spc_sdk_private",
    host,
    sv: 0,
    generation: 1,
    spaceId: "spc_sdk_private",
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    iat: now,
    nbf: now,
    exp: now + 300,
    jti: randomUUID(),
  });
  const callback = await postAccessCallback(runtime, host, token);
  expect(callback.status).toBe(303);
  const cookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";

  const admittedPage = await get(runtime, host, "/docs/", { headers: { cookie } });
  expect(admittedPage.status).toBe(200);
  expect(await admittedPage.text()).toContain("Private docs");

  const admitted = await get(runtime, host, "/__spacefast/sdk.js", {
    headers: {
      cookie,
      referer: `https://${host}/docs/`,
      "sec-fetch-dest": "script",
    },
  });
  expect(admitted.status).toBe(200);
  const admittedBody = await admitted.text();
  expect(admittedBody).toContain('"spaceId":"spc_sdk_private"');
  expect(admittedBody).toContain("private-tag-marker");
  expect(admitted.headers.get("cache-control")).toBe("private, no-store");
  expect(admitted.headers.get("vary")).toContain("Cookie");
  expect(admitted.headers.get("access-control-allow-origin")).toBeNull();
  expect(admitted.headers.get("cross-origin-resource-policy")).toBe("same-origin");
  // The validator is still precomputed per entry (§15/D121), but PHP never
  // answers a conditional request with it: the edge does that on a HIT, and
  // If-None-Match never reaches the origin at all (§16, C19). What matters here
  // is that the validator only ever rides an ADMITTED response — a denial
  // leaks no ETag a revoked visitor could keep revalidating against.
  const privateEtag = admitted.headers.get("etag") ?? "";
  expect(privateEtag).toContain("runtime:");

  try {
    const commentsConfig = (extra: Record<string, string> = {}) =>
      get(runtime, host, "/__spacefast/comments/config", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: `http://${host}`,
          "sec-fetch-site": "same-origin",
          ...extra,
        },
        // Inside the member Grant's scope: the session admits this page, and
        // the same request without the cookie does not.
        body: JSON.stringify({ pagePath: "/docs" }),
      });

    // A denied page answers the Comments fetch lane with the JSON envelope,
    // never the HTML access gate.
    const denied = await commentsConfig();
    expect(denied.status).toBe(401);
    expect(await errorCode(denied)).toBe("comments_denied");
    expect(denied.headers.get("content-type") ?? "").toContain("application/problem+json");

    // Configuration for an admitted visitor is still answered locally: the
    // admission check is the runtime's, and so is the answer.
    const admittedConfig = await commentsConfig({ cookie });
    expect(admittedConfig.status).toBe(200);
    expect(exchanges).toHaveLength(0);
    // An admitted visitor's session authorities ride the MINT — the one lane
    // that still reaches the control plane.
    const mintTicket = (extra: Record<string, string> = {}) =>
      get(runtime, host, "/__spacefast/comments/ticket", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: `http://${host}`,
          "sec-fetch-site": "same-origin",
          ...extra,
        },
        body: JSON.stringify({
          pagePath: "/docs",
          identity: { name: "Member", namedByUser: false },
        }),
      });
    const admittedMint = await mintTicket({ cookie });
    expect(admittedMint.status).toBe(200);
    // An admitted visitor carries their Comments identity in the access session
    // itself (§7: the claims ARE the record), and it is minted WITH the session
    // rather than beside it — so a mint hands back no cookie at all: never a
    // second cookie, and never a replacement session, which would silently drop
    // the authorities the browser's cookie already names.
    expect(admittedMint.headers.getSetCookie()).toHaveLength(0);
    expect(cookie).toStartWith(`spacefast_session_dev=${RECORDED_SESSION_PREFIX}`);
    const identifiedClaims = sessionPayload(cookie);
    expect(identifiedClaims["authorities"]).toHaveLength(1);
    expect(sessionRecords(runtime, "spc_sdk_private")).toEqual([
      `${String(identifiedClaims["sid"])}.json`,
    ]);
    const forwarded = exchanges[0] as {
      authorities?: Array<{
        authorityReference: string;
        authorityGeneration: string;
        sessionVersion: number;
      }>;
    };
    expect(forwarded.authorities).toHaveLength(1);
    expect(forwarded.authorities?.[0]?.authorityReference).toBe(authority);
    expect(forwarded.authorities?.[0]?.authorityGeneration).toMatch(/^[a-f0-9]{64}$/);
    expect(forwarded.authorities?.[0]?.sessionVersion).toBe(0);
    // One session id, and it is the visitor session's own: the id the runtime
    // records, the id the cookie names, and the id the Cast ticket projects.
    // There is no second Comments identity to keep in step with it.
    const visitorSessionId = exchanges[0]["visitorSessionId"];
    expect(visitorSessionId).toBe(identifiedClaims["sid"]);
    // The pseudonym is the one thing that is NOT the session id: it survives a
    // credential attach, which rotates the session.
    expect(identifiedClaims["anonymousId"]).toMatch(/^anon_[a-f0-9]{32}$/);

    // Stable across mints: the next exchange reuses what the cookie carries
    // instead of minting a second identity beside it.
    const reused = await mintTicket({ cookie });
    expect(reused.headers.getSetCookie()).toHaveLength(0);
    expect(exchanges[1]["visitorSessionId"]).toBe(visitorSessionId);

    // A refused mint is reported, never repaired by rewriting the session: the
    // cookie carries page access too, so touching it would log the visitor out
    // of the Space over a Comments failure.
    ticketFailureCode = "comments_reauth_required";
    const refusedTicket = await mintTicket({ cookie });
    expect(refusedTicket.status).toBe(401);
    expect(await errorCode(refusedTicket)).toBe("comments_reauth_required");
    expect(refusedTicket.headers.getSetCookie()).toHaveLength(0);
    ticketFailureCode = null;
    expect(sessionRecords(runtime, "spc_sdk_private")).toEqual([
      `${String(identifiedClaims["sid"])}.json`,
    ]);
    // Still admitted, and still the same session.
    expect((await get(runtime, host, "/docs/", { headers: { cookie } })).status).toBe(200);
    await mintTicket({ cookie });
    expect(exchanges.at(-1)?.["visitorSessionId"]).toBe(visitorSessionId);
  } finally {
    central.stop(true);
  }
});

// The overlay is opcached PHP included on every request this Space serves, so a
// large tag release must not live in it. Content-addressing it keeps the SDK
// response byte-identical either way — which is what makes the split invisible
// to the browser and safe for the immutable cache policy.
test("a large tag module rides the blob store, not the overlay", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  const marker = "window.__bigTag=".concat('"', "x".repeat(64 * 1024), '";');
  const accessConfig = publicAccessConfig({ mode: "website", site_title: "Big tag" });
  accessConfig.sdk = {
    revision: "sdk-big-1",
    config: { cast_api_base: "https://cast.example.test" },
    body: marker,
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_big",
    versionId: "ver_sdk_big_1",
    files: { "index.html": '<script src="/__spacefast/sdk.js"></script>\n' },
    activate: {
      route_name: "production",
      config: accessConfig,
      production_hostnames: ["big-tag.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(runtime, "big-tag.site.test", "/__spacefast/sdk.js");
  expect(response.status).toBe(200);
  expect(await response.text()).toContain(marker);

  // The bytes are in the CAS and the overlay only names them, so the artifact
  // the serve path includes per request stays small.
  const overlays = path.join(spaceRoot(runtime, "spc_sdk_big"), "overlays");
  const overlaySizes = readdirSync(overlays).map(
    (file) => statSync(path.join(overlays, file)).size,
  );
  expect(Math.max(...overlaySizes)).toBeLessThan(32 * 1024);

  // ...and the blob GC's live set knows about them: they are declared by the
  // overlay alone, by no version at all.
  const blobs = path.join(spaceRoot(runtime, "spc_sdk_big"), "blobs");
  const bodySha = createHash("sha256").update(marker).digest("hex");
  expect(existsSync(path.join(blobs, bodySha.slice(0, 2), bodySha))).toBe(true);
});

test("only the SDK file is public under the Spacefast namespace", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  const response = await get(runtime, SITE, "/__spacefast/private.js");
  expect(response.status).toBe(403);
});

// The reservation has to hold against a routing RULE, not only against a
// published file. A rule's source is a request path like any other, and
// serve.php runs the rules stage ahead of its reserved dispatch ladder, so a
// rewrite gets to mutate the path the SDK dispatch then compares. Nothing about
// this needs an attacker request: the `<script src="/__spacefast/sdk.js">` tag
// is baked into the published HTML, so every visitor to every page would run
// the tenant bytes in place of the platform SDK.
test("a routing rule cannot rewrite the reserved SDK route onto tenant bytes", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  const accessConfig = publicAccessConfig({ mode: "website", site_title: "Hijack" });
  accessConfig.sdk = { revision: "sdk-hijack-1", config: {} };
  await deploy(runtime, {
    spaceId: "spc_sdk_rule",
    versionId: "ver_sdk_rule_1",
    files: {
      "index.html": '<h1>Hijack</h1><script src="/__spacefast/sdk.js"></script>\n',
      "my-own.js": TENANT_JS,
      "__spanish/page.html": "<h1>ordinary</h1>\n",
      _redirects: [
        "/__spacefast/sdk.js /my-own.js 200",
        // Reservation is whole-segment at the front door, so this near miss is
        // ordinary tenant content and its rule must keep working.
        "/__spanish/page /__spanish/page.html 200!",
        "/moved /index.html 301",
      ].join("\n"),
    },
    activate: {
      route_name: "production",
      config: accessConfig,
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const sdk = await get(runtime, SITE, "/__spacefast/sdk.js");
  expect(sdk.status).toBe(200);
  const body = await sdk.text();
  expect(body).not.toContain(TENANT_MARKER);
  expect(body).toContain("window.Spacefast=window.Spacefast||{}");

  // The same publish's ordinary rules are untouched: the fix reserves the
  // platform's own routes, it does not stop a Space routing itself.
  const nearMiss = await get(runtime, SITE, "/__spanish/page");
  expect(nearMiss.status).toBe(200);
  expect(await nearMiss.text()).toBe("<h1>ordinary</h1>\n");

  const moved = await get(runtime, SITE, "/moved");
  expect(moved.status).toBe(301);
  expect(moved.headers.get("location")).toBe("/index.html");
});
