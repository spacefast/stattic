import { afterAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, readdirSync, statSync } from "node:fs";
import path from "node:path";

import { canonicalPagePath, commentRoomKey } from "../../packages/collab-sdk/src/core.ts";
import { ICON_PATHS } from "../../packages/collab-sdk/src/theme/icons.ts";
import { collabManifestSchema } from "../../packages/common/src/contracts/collab-manifest.ts";
// Fetched through the shipped constants, not string literals: PHP and TypeScript
// name these two URLs independently, and this suite is where a drift between
// them surfaces.
import {
  RUNTIME_COLLAB_MANIFEST_PATH,
  RUNTIME_COLLAB_THEME_PATH,
} from "../../packages/common/src/utils/runtime-paths.ts";
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
  ui: "default",
  // The control plane already parsed, validated and normalized every value
  // here, and rendered `css` from them. The runtime copies both verbatim.
  theme: {
    accent: "#4f46e5",
    background: "#101014",
    font: '"Inter", sans-serif',
    name: "Local SDK",
    logo: "https://cdn.example.test/logo.svg",
    hideBranding: false,
  },
  css: ":host,:root{--sf-collab-accent:#4f46e5}\n",
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

type FakeElement = {
  async: boolean;
  attributes: Record<string, string>;
  dataset: Record<string, string>;
  href: string;
  id: string;
  innerHTML: string;
  onerror?: () => void;
  rel: string;
  remove: () => void;
  setAttribute: (name: string, value: string) => void;
  src: string;
  style: { cssText: string };
  tagName: string;
  type: string;
};

type FakeWindow = {
  Spacefast?: { collabLoader?: unknown };
  __localTagLoaded?: boolean;
};

// Run an `sdk.js` body the way a browser would, against a DOM thin enough that
// every element it creates stays observable. The head is where the SDK puts the
// theme stylesheet and the overlay module; the body is where the placeholder
// orb lands.
function runSdk(
  source: string,
  options: { host?: string; pathname?: string; storedPlacement?: string } = {},
) {
  const head: FakeElement[] = [];
  const body: FakeElement[] = [];
  const events: string[] = [];
  const host = options.host ?? "local-sdk.site.test";
  const fakeWindow: FakeWindow & {
    dispatchEvent: (event: { type: string }) => boolean;
    CustomEvent: typeof CustomEvent;
  } = {
    dispatchEvent: (event) => {
      events.push(event.type);
      return true;
    },
    CustomEvent,
  };
  const fakeDocument = {
    body: {
      appendChild: (node: FakeElement) => {
        body.push(node);
      },
    },
    createElement: (tagName: string): FakeElement => {
      const element: FakeElement = {
        async: false,
        attributes: {},
        dataset: {},
        href: "",
        id: "",
        innerHTML: "",
        rel: "",
        setAttribute: (name: string, value: string) => {
          element.attributes[name] = value;
        },
        src: "",
        style: { cssText: "" },
        tagName,
        type: "",
        remove: () => {
          const at = body.indexOf(element);
          if (at !== -1) body.splice(at, 1);
        },
      };
      return element;
    },
    getElementById: (id: string) => body.find((node) => node.id === id) ?? null,
    head: {
      appendChild: (node: FakeElement) => {
        head.push(node);
      },
    },
  };
  Function(
    "window",
    "document",
    "location",
    "localStorage",
    "innerWidth",
    "innerHeight",
    source,
  )(
    fakeWindow,
    fakeDocument,
    { host, origin: `https://${host}`, pathname: options.pathname ?? "/" },
    { getItem: () => options.storedPlacement ?? null },
    1_000,
    900,
  );
  return { head, body, events, window: fakeWindow };
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
  const reviewKeys = generateKeyPairSync("ed25519");
  const reviewIssuer = visitorIssuer(reviewKeys.publicKey);
  accessConfig.visitor_issuer = "spacefast-api";
  accessConfig.visitor_jwks = {
    keys: [
      {
        ...reviewKeys.publicKey.export({ format: "jwk" }),
        kid: reviewIssuer.kid,
        alg: "EdDSA",
        use: "sig",
      },
    ],
  };
  // ONE SDK projection: the loader's config and the production tag module in
  // the same section. Nothing here names a second artifact.
  accessConfig.users = {
    enabled: true,
    providers: {
      google: { mode: "managed" },
      gravatar: { enabled: true },
      spacefast: { enabled: true },
    },
  };
  accessConfig.usersProviderConfig = {
    issuer: "https://api.spacefast.com/v1/auth",
    clientId: "space-test",
    googleStartUrl: "https://api.spacefast.com/v1/auth/space-users/spc_sdk/google/start",
    gravatarStartUrl: "https://api.spacefast.com/v1/auth/space-users/spc_sdk/gravatar/start",
    directGoogle: { clientId: "direct-test", clientSecret: "test-private-client-secret" },
    availability: { google: { managed: true, direct: true }, gravatar: true, spacefast: true },
    revision: "test-private-config",
  };
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
      version_hostnames: [{ hostname: "review-sdk.site.test", version_id: "ver_sdk_1" }],
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
  // Comments are not available for this surface, so the response carries no
  // Comments bytes at all: no theme stylesheet, no placeholder orb, no module
  // loader. The permissions toggle is a byte budget, not a disabled flag.
  expect(body).not.toContain("collab.css");
  expect(body).not.toContain("collab.json");
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
  const authConfig = await get(runtime, SITE, "/__zero/config");
  expect(authConfig.status).toBe(200);
  expect((await authConfig.json()).auth.signOutMethod).toBe("POST");
  const assigned: string[] = [];
  const browser = {
    href: "https://sdk.site.test/app",
    origin: "https://sdk.site.test",
    assign: (url: string) => assigned.push(url),
  };
  Function(
    "window",
    "document",
    "location",
    body +
      ";window.Spacefast.users.signInWithGoogle();window.Spacefast.users.signInWithGravatar();window.Spacefast.users.signInWithSpacefast();",
  )(fakeWindow, fakeDocument, browser);
  expect(assigned.map((url) => new URL(url).searchParams.get("provider"))).toEqual([
    "google",
    "gravatar",
    "spacefast",
  ]);
  expect(assigned.every((url) => new URL(url).origin === browser.origin)).toBe(true);
  expect(JSON.stringify(fakeWindow.Spacefast)).not.toContain("test-private-client-secret");
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
  // the live surface whatever the URL is decorated with — otherwise the preview
  // Comments lane could be consulted from the live site by anyone who appended
  // a query parameter. The manifest is where that verdict is now published.
  expect(await preview.text()).toContain("preview=preview-token");
  for (const url of [
    `${RUNTIME_COLLAB_MANIFEST_PATH}?preview=preview-token`,
    RUNTIME_COLLAB_MANIFEST_PATH,
  ]) {
    const manifest = await get(runtime, SITE, url);
    expect(manifest.status, url).toBe(200);
    // SAFETY: collabManifestSchema is asserted against a full manifest above;
    // this lane only reads back the one field the surface verdict writes.
    const parsed = (await manifest.json()) as { environment: string };
    expect(parsed.environment, url).toBe("production");
  }

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

  // A Space with nothing to join still answers, and says so: no socket and no
  // ticket URL, rather than a 404 the client would have to guess at. Nothing
  // named here that the page could not already see.
  const unconfigured = await get(runtime, SITE, RUNTIME_COLLAB_MANIFEST_PATH);
  expect(unconfigured.status).toBe(200);
  const unconfiguredManifest = collabManifestSchema.parse(await unconfigured.json());
  expect(unconfiguredManifest.cast).toBeNull();
  expect(unconfiguredManifest.ticketUrl).toBeNull();

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

  // Comments stay disabled, but a signed review may load the independent picker.
  const reviewId = randomUUID();
  const review = {
    id: reviewId,
    versionId: "ver_sdk_1",
    parentOrigin: "https://review.example.test",
    ancestors: ["https://chat.example.test"],
  };
  const now = Math.floor(Date.now() / 1000);
  const reviewToken = (versionId = review.versionId) =>
    "sfv_" +
    signEd25519Jwt(reviewKeys.privateKey, reviewIssuer.kid, {
      purpose: "system-view",
      sub: "system:spc_sdk",
      capabilities: ["page.view"],
      iss: "spacefast-api",
      aud: "spc_sdk",
      spaceId: "spc_sdk",
      host: "review-sdk.site.test",
      generation: 1,
      sessionVersion: 0,
      iat: now,
      nbf: now,
      exp: now + 3600,
      embed: review.parentOrigin,
      review: { ...review, versionId },
    });
  expect((await get(runtime, "review-sdk.site.test", "/")).status).toBe(403);
  expect(
    (await get(runtime, "review-sdk.site.test", "/?__=" + reviewToken("ver_other"))).status,
  ).toBe(403);
  const handoff = await get(runtime, "review-sdk.site.test", "/?__=" + reviewToken());
  expect(handoff.status).toBe(302);
  const reviewCookie =
    handoff.headers
      .getSetCookie()
      .find((cookie) => cookie.startsWith("spacefast_system_view_dev="))
      ?.split(";")[0] ?? "";
  expect(reviewCookie.length).toBeGreaterThan(0);
  const reviewedPage = await get(runtime, "review-sdk.site.test", "/", {
    headers: { cookie: reviewCookie },
  });
  expect(reviewedPage.status).toBe(200);
  expect(reviewedPage.headers.get("content-security-policy")).toContain(review.parentOrigin);
  expect(reviewedPage.headers.get("content-security-policy")).toContain(
    "https://chat.example.test",
  );
  const reviewedSdk = await get(runtime, "review-sdk.site.test", "/__spacefast/sdk.js?v=cached", {
    headers: { cookie: reviewCookie },
  });
  expect(reviewedSdk.status).toBe(200);
  expect(reviewedSdk.headers.get("cache-control")).toBe("private, no-store");
  const reviewNodes: Array<{ id?: string; type?: string; src?: string; textContent?: string }> = [];
  Function(
    "window",
    "document",
    await reviewedSdk.text(),
  )(
    {},
    {
      createElement: () => ({}),
      head: { appendChild: (node: (typeof reviewNodes)[number]) => reviewNodes.push(node) },
    },
  );
  expect(reviewNodes.map((node) => node.src).filter(Boolean)).toEqual([
    "https://cast.example.test/sdk/v1/review.js",
  ]);
  expect(
    JSON.parse(reviewNodes.find((node) => node.id === "sf-visual-review")?.textContent ?? "null"),
  ).toEqual({ ...review, spaceId: "spc_sdk" });
});

// A deployment that never configured an API base is misconfigured, and the
// manifest says so. It does NOT guess one from the relay's hostname: that
// rewrite (`cast.` -> `api.`) held for exactly one naming convention and
// silently produced a wrong-but-plausible origin for every other, while tying
// the API's address to the relay's.
test("an unconfigured API base leaves the SDK headless instead of guessing one", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: "" },
  });
  runtimes.push(runtime);

  const accessConfig = publicAccessConfig({ mode: "website", site_title: "Unconfigured" });
  accessConfig.sdk = {
    revision: "sdk-unconfigured-1",
    // The old heuristic would have turned this into `https://api.example.test`.
    config: { cast_api_base: "https://cast.example.test" },
    body: "window.__unconfiguredTagLoaded=true;",
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_unconfigured",
    versionId: "ver_sdk_unconfigured_1",
    files: { "index.html": '<h1>Unconfigured</h1><script src="/__spacefast/sdk.js"></script>\n' },
    activate: {
      route_name: "production",
      config: accessConfig,
      production_hostnames: ["unconfigured-sdk.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const manifestResponse = await get(
    runtime,
    "unconfigured-sdk.site.test",
    RUNTIME_COLLAB_MANIFEST_PATH,
  );
  expect(manifestResponse.status).toBe(200);
  const manifest: unknown = await manifestResponse.json();
  // ...and the SDK's own parser is the one that has to accept it, so a
  // producer/consumer drift fails here rather than at a visitor's first paint.
  // Comments still join (that gate is `cast` + `ticketUrl`); it is the lanes the
  // page pulls on its own behalf that refuse — see `registerReplyEmail` in
  // react/composer.tsx, which throws rather than POST at a guessed origin.
  expect(collabManifestSchema.parse(manifest).apiBase).toBeNull();

  // The preview tag loader is gated on the same value, so no script goes out to
  // a guessed host either.
  const response = await get(
    runtime,
    "unconfigured-sdk.site.test",
    "/__spacefast/sdk.js?preview=preview-token",
  );
  expect(response.status).toBe(200);
  const body = await response.text();
  const appended: Array<{ src: string }> = [];
  Function(
    "window",
    "document",
    body,
  )(
    { Spacefast: {} },
    {
      createElement: () => ({ async: false, dataset: {}, src: "", type: "" }),
      head: { appendChild: (script: { src: string }) => appended.push(script) },
    },
  );
  expect(appended).toHaveLength(0);
});

test("same-host Spacefast SDK route restores the in-page Comments module", async () => {
  // The API base is configuration and nothing else: this deployment names one
  // that is not the default and not a sibling of its relay's `cast.` origin,
  // and that is exactly the origin the manifest carries.
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: "https://api.example.test" },
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
  const accessPage = {
    displayName: "Local SDK",
    // Claimed: the descriptor naming an account is the gate the manifest
    // reads, and what it hands the page is this host's own start route.
    accountUrl: "https://api.spacefast.com/acquire/opaque-comments-target/account",
    connections: [],
    exchange: {
      passwordUrl: "https://api.spacefast.com/acquire/opaque-comments-target/password",
      tokenUrl: "https://api.spacefast.com/acquire/opaque-comments-target/token",
      emailUrl: "https://api.spacefast.com/acquire/opaque-comments-target/email",
      requestUrl: "https://api.spacefast.com/acquire/opaque-comments-target/request",
      logoutUrl: "https://api.spacefast.com/runtime/collaboration-sessions/revoke",
      commentsTicketUrl: "https://api.spacefast.com/runtime/comments/opaque-comments-target/ticket",
      credential: "runtime-comments-credential-0000000000000000000000000000",
    },
  };
  Object.assign(authorization, {
    acquireUrl: "https://api.spacefast.com/acquire/opaque-comments-target",
    accessPage,
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
  expect(response.headers.get("etag")).toMatch(/^"runtime:[a-f0-9]{64}"$/);
  const body = await response.text();

  // The manifest is its own document now, fetched by whoever boots the overlay
  // — Spacefast's bundle or a self-built UI. `collabManifestSchema` is the
  // contract both sides hold, so a producer/consumer drift on any field fails
  // here instead of at a visitor's first paint.
  const manifestResponse = await get(runtime, "local-sdk.site.test", RUNTIME_COLLAB_MANIFEST_PATH);
  expect(manifestResponse.status).toBe(200);
  expect(manifestResponse.headers.get("content-type")).toContain("application/json");
  expect(manifestResponse.headers.get("etag")).toMatch(/^"runtime:[a-f0-9]{64}"$/);
  const manifest: unknown = await manifestResponse.json();
  expect(collabManifestSchema.safeParse(manifest).success).toBe(true);
  expect(manifest).toEqual({
    version: 5,
    environment: "production",
    space: { id: "spc_sdk_local", name: "Local SDK", liveUrl: null },
    // The live host serves the live artifact: `current` is true and there is no
    // separate immutable URL to point at.
    artifact: { id: "ver_sdk_local_1", current: true, url: null },
    cast: {
      wsUrl: "wss://cast.example.test/socket/websocket",
      resourceKey: "resource_local",
    },
    ticketUrl: "http://local-sdk.site.test/__spacefast/comments/ticket",
    // Configuration and nothing else: this runtime names an API base that is
    // not a `cast.` sibling of its relay, and that is what the manifest carries.
    apiBase: "https://api.example.test",
    // Never the descriptor's own URL: the page goes to this host's account
    // start route, which is what mints browser state before the handoff.
    accountUrl: "http://local-sdk.site.test/__spacefast/access/account",
    features: { picker: true, drawing: true, capture: false, attachments: true },
    // The revocable read key rides the manifest: that IS the fresh-URL
    // mechanism, so its exact value is asserted against the box's key file.
    uploads: {
      base: "http://local-sdk.site.test/__stattic/u/",
      key: expect.stringMatching(/^[a-f0-9]{32}$/),
    },
    ui: "default",
    theme: LOCAL_COMMENTS.theme,
  });
  // The room key is the one thing NOT here: it is per page and this document is
  // shared across every page of the Space. The client derives it.
  expect(manifest).not.toHaveProperty("room_key");

  // The stylesheet is the control plane's rendering of that same theme, served
  // byte-for-byte. The runtime derives no colors.
  const themeResponse = await get(runtime, "local-sdk.site.test", RUNTIME_COLLAB_THEME_PATH);
  expect(themeResponse.status).toBe(200);
  expect(themeResponse.headers.get("content-type")).toContain("text/css");
  expect(themeResponse.headers.get("etag")).toMatch(/^"runtime:[a-f0-9]{64}"$/);
  expect(await themeResponse.text()).toBe(LOCAL_COMMENTS.css);

  // Both are read-only documents, and they refuse a write the way the rest of
  // the API does: a problem document, not the SDK's JavaScript refusal.
  const written = await get(runtime, "local-sdk.site.test", RUNTIME_COLLAB_MANIFEST_PATH, {
    method: "POST",
  });
  expect(written.status).toBe(405);
  expect(written.headers.get("allow")).toBe("GET, HEAD, OPTIONS");
  expect(await errorCode(written)).toBe("method_not_allowed");

  // The visitor last parked the orb on the left edge, a third of the way down.
  const storedPlacement = JSON.stringify({ edge: "left", along: 0.33, inset: 16 });
  const run = runSdk(body, {
    pathname: "/docs/getting-started",
    storedPlacement,
  });

  expect(body).not.toContain("document.cookie");
  expect(body).not.toContain("runtime-comments-credential-0000000000000000000000000000");
  // Two tags and no third: the theme stylesheet, and the overlay module told
  // where its manifest lives.
  expect(run.head.map((node) => node.tagName)).toEqual(["link", "script"]);
  expect(run.head[0]).toMatchObject({ rel: "stylesheet", href: RUNTIME_COLLAB_THEME_PATH });
  expect(run.head[1]).toMatchObject({
    async: true,
    src: "https://cast.example.test/sdk/v1/collab.js",
    type: "module",
  });
  expect(run.head[1]?.attributes["data-sf-config"]).toBe(RUNTIME_COLLAB_MANIFEST_PATH);

  // The orb is painted before a single Cast byte is fetched, in the placement
  // the visitor last chose, at the top of the stacking order.
  expect(run.body).toHaveLength(1);
  const placeholderOrb = run.body[0];
  expect(placeholderOrb?.id).toBe("sf-collab-boot-orb");
  expect(placeholderOrb?.style.cssText).toContain("position:fixed");
  expect(placeholderOrb?.style.cssText).toContain("z-index:2147483000");
  expect(placeholderOrb?.style.cssText).toContain("width:44px");
  // edge=left inset=16 -> `left:16px`; along 0.33 of a 900px tall viewport,
  // centred on a 44px disc -> top:275px (0.33*900-22).
  expect(placeholderOrb?.style.cssText).toContain("left:16px");
  expect(placeholderOrb?.style.cssText).toContain("top:275px");
  // It wears the Space's accent, straight off the serving state — as the disc's
  // fill, which is what the real orb boots into.
  expect(placeholderOrb?.style.cssText).toContain("background:#4f46e5");
  expect(placeholderOrb?.innerHTML).toContain("sf-boot-pulse");
  // The glyph and its ink are the SDK's, not a lookalike: a placeholder drawing
  // a different shape or a different ink is a visible swap at boot. #4f46e5 is
  // dark, so the ink is white.
  expect(placeholderOrb?.innerHTML).toContain(ICON_PATHS.comment);
  expect(placeholderOrb?.innerHTML).toContain('stroke="#ffffff"');

  // ...and it stands in for an overlay that is ARRIVING. A module that never
  // loads leaves it standing in for nothing, so the failure takes it down: a
  // disc pulsing forever is a worse lie than no orb at all.
  run.head[1]?.onerror?.();
  expect(run.body).toHaveLength(0);
  expect(run.events).toEqual(["spacefast:collab-error"]);

  // A preview session carries its token to BOTH documents, so all three resolve
  // against the same serving state and stay out of the immutable cache
  // together. One rewritten script tag is all the page needs: the token travels
  // from there into the tags `sdk.js` writes. It selects a tag release, not a
  // surface and not a draft serving state — whether this host is live or
  // preview is the serving state's own verdict, asserted above.
  const previewSdk = await get(
    runtime,
    "local-sdk.site.test",
    "/__spacefast/sdk.js?preview=tok_draft",
  );
  expect(previewSdk.status).toBe(200);
  const previewRun = runSdk(await previewSdk.text(), { pathname: "/docs/getting-started" });
  expect(previewRun.head.find((node) => node.rel === "stylesheet")?.href).toBe(
    `${RUNTIME_COLLAB_THEME_PATH}?preview=tok_draft`,
  );
  expect(previewRun.head.find((node) => node.type === "module")?.attributes["data-sf-config"]).toBe(
    `${RUNTIME_COLLAB_MANIFEST_PATH}?preview=tok_draft`,
  );

  expect(run.window.__localTagLoaded).toBe(true);
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
  expect(bakedBody).not.toContain("collab.css");
  expect(runSdk(bakedBody, { host: "baked-local.site.test" }).head).toEqual([]);
  // ...and the manifest reaches the SAME verdict. It has to: a `ui: "custom"`
  // client boots from this document alone and never sees the bootstrap, so a
  // weaker gate here would hand it a room the page itself refused to load.
  const bakedManifest = await get(runtime, "baked-local.site.test", RUNTIME_COLLAB_MANIFEST_PATH);
  expect(bakedManifest.status).toBe(200);
  const parsedBaked = collabManifestSchema.parse(await bakedManifest.json());
  expect(parsedBaked.cast).toBeNull();
  expect(parsedBaked.ticketUrl).toBeNull();
  expect(parsedBaked.uploads).toBeNull();

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
  // `apiBase` is this runtime's configured one whatever the relay looks like —
  // the unconfigured case is its own test above.

  // `ui` says WHOSE UI shows the comments, never whether there are any: that
  // switch is the Space's Comments setting, and it reaches here as
  // availability. `custom` hands over the theme and stays out of the page —
  // and when there is nothing to join, not one byte, stylesheet included.
  for (const [label, spaceSuffix, host, available] of [
    ["custom", "custom", "ui-custom.site.test", true],
    ["custom, nothing to join", "unavailable", "ui-unavailable.site.test", false],
  ] as const) {
    const uiConfig = structuredClone(accessConfig);
    uiConfig.sdk = {
      revision: `sdk-ui-${spaceSuffix}-1`,
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_local",
        comments: {
          ...LOCAL_COMMENTS,
          ui: "custom",
          live: available,
          theme: { ...LOCAL_COMMENTS.theme, accent: "#4F46E5" },
        },
      },
    };
    await deploy(runtime, {
      spaceId: `spc_sdk_ui_${spaceSuffix}`,
      versionId: `ver_sdk_ui_${spaceSuffix}_1`,
      files: { "index.html": `<h1>${label}</h1>\n` },
      activate: {
        route_name: "production",
        config: uiConfig,
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const uiSdk = await get(runtime, host, "/__spacefast/sdk.js");
    expect(uiSdk.status, label).toBe(200);
    const uiRun = runSdk(await uiSdk.text(), { host });
    expect(
      uiRun.head.map((node) => (node.rel === "stylesheet" ? node.href : node.src)),
      label,
    ).toEqual(available ? [RUNTIME_COLLAB_THEME_PATH] : []);
    expect(uiRun.body, label).toEqual([]);

    // ...and the manifest says the same thing to whoever fetches it.
    const uiManifest = await get(runtime, host, RUNTIME_COLLAB_MANIFEST_PATH);
    expect(uiManifest.status, label).toBe(200);
    const parsed = collabManifestSchema.parse(await uiManifest.json());
    expect(parsed.ui, label).toBe("custom");
    expect(parsed.cast === null, label).toBe(!available);
    // Whatever case the serving state wrote the accent in, the manifest
    // publishes the lowercase spelling `collabThemeSchema` requires — a client
    // parsing this document must not fail on how the value happens to be typed.
    expect(parsed.theme.accent, label).toBe("#4f46e5");
  }

  // Attachments off leaves nothing to upload to, so the manifest withholds the
  // base and the read key rather than advertising a store the UI must not use.
  // Unclaimed too, which is the other half of the account handoff: no account
  // to continue with means no URL to offer.
  const noUploadsConfig = structuredClone(accessConfig);
  noUploadsConfig.authorization = {
    ...authorization,
    accessPage: { ...accessPage, accountUrl: null },
  };
  noUploadsConfig.sdk = {
    revision: "sdk-no-uploads-1",
    config: {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_local",
      comments: {
        ...LOCAL_COMMENTS,
        features: { ...LOCAL_COMMENTS.features, attachments: false },
      },
    },
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_no_uploads",
    versionId: "ver_sdk_no_uploads_1",
    files: { "index.html": "<h1>No uploads</h1>\n" },
    activate: {
      route_name: "production",
      config: noUploadsConfig,
      production_hostnames: ["no-uploads.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // The accent is the one theme value that leaves JSON: the placeholder orb
  // concatenates it into an HTML attribute inside `innerHTML`. A serving state
  // carrying markup instead of a color must paint the default, not the markup —
  // otherwise one bad projection is stored XSS on every page of the Space.
  const hostileConfig = structuredClone(accessConfig);
  hostileConfig.sdk = {
    revision: "sdk-hostile-accent-1",
    config: {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_local",
      comments: {
        ...LOCAL_COMMENTS,
        theme: { ...LOCAL_COMMENTS.theme, accent: 'x"><img src=x onerror=1>' },
      },
    },
  };
  await deploy(runtime, {
    spaceId: "spc_sdk_hostile_accent",
    versionId: "ver_sdk_hostile_accent_1",
    files: { "index.html": "<h1>Hostile accent</h1>\n" },
    activate: {
      route_name: "production",
      config: hostileConfig,
      production_hostnames: ["hostile-accent.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const hostileSdk = await get(runtime, "hostile-accent.site.test", "/__spacefast/sdk.js");
  expect(hostileSdk.status).toBe(200);
  const hostileRun = runSdk(await hostileSdk.text(), { host: "hostile-accent.site.test" });
  const hostileOrb = hostileRun.body[0];
  expect(hostileOrb?.id).toBe("sf-collab-boot-orb");
  // The accent fills the disc, so it lands in a CSS declaration: only a literal
  // 6-hex is ever written there, and anything else is the overlay's fallback.
  expect(hostileOrb?.style.cssText).toContain("background:#ff603d");
  for (const painted of [hostileOrb?.style.cssText, hostileOrb?.innerHTML]) {
    expect(painted).not.toContain("<img");
    expect(painted).not.toContain("onerror");
  }
  // The manifest refuses it the same way, so a self-built UI reading `theme`
  // never receives it either.
  const hostileManifest = await get(
    runtime,
    "hostile-accent.site.test",
    RUNTIME_COLLAB_MANIFEST_PATH,
  );
  expect(collabManifestSchema.parse(await hostileManifest.json()).theme.accent).toBeNull();

  const noUploads = await get(runtime, "no-uploads.site.test", RUNTIME_COLLAB_MANIFEST_PATH);
  expect(noUploads.status).toBe(200);
  const parsedNoUploads = collabManifestSchema.parse(await noUploads.json());
  expect(parsedNoUploads.features.attachments).toBe(false);
  expect(parsedNoUploads.uploads).toBeNull();
  // An unclaimed Space names no account to continue with.
  expect(parsedNoUploads.accountUrl).toBeNull();
  // The rest of the Space still speaks: withholding the store is not a shutdown.
  expect(parsedNoUploads.cast).not.toBeNull();
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
      RUNTIME_COLLAB_MANIFEST_PATH,
    );
    expect(response.status).toBe(200);
    // THE headline: configuration is assembled here, from the overlay. The
    // control plane is not called at all — only the mint below reaches it.
    expect(exchanges).toHaveLength(0);
    const manifest = collabManifestSchema.parse(await response.json());
    expect(manifest.cast).toEqual({
      wsUrl: "wss://cast.example.test/socket/websocket",
      resourceKey: "resource_local",
    });
    expect(manifest.ticketUrl).toBe(
      "http://comments-exchange.site.test/__spacefast/comments/ticket",
    );
    // Commenting on the live host IS the live context: nothing to link back to.
    expect(manifest.artifact).toEqual({ id: "ver_sdk_exchange_1", current: true, url: null });
    // Reading the manifest costs the visitor no session: only the mint below does.
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
        notices: true,
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

    // The Zero mint answers on the canonical path the config response now
    // advertises AND on the legacy one frozen capsule clients baked. Either
    // spelling must reach zeroRealtimeTicketUrl, never the Comments ticket
    // lane, whose token Cast rejects.
    for (const zeroTicketPath of ["/__zero/realtime-ticket", "/__spacefast/zero/realtime-ticket"]) {
      const zeroTicket = await get(runtime, "comments-exchange.site.test", zeroTicketPath, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({ pagePath: "/docs" }),
      });
      expect({ path: zeroTicketPath, status: zeroTicket.status }).toEqual({
        path: zeroTicketPath,
        status: 200,
      });
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
    }

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
  // The room key the SDK's own derivation produces from what this host serves —
  // `commentRoomKey` is what the overlay puts in the join topic, run against
  // exactly the version block PHP emitted.
  const derivedRoomKey = async (host: string) => {
    const response = await get(runtime, host, RUNTIME_COLLAB_MANIFEST_PATH);
    expect(response.status).toBe(200);
    const { artifact } = collabManifestSchema.parse(await response.json());
    return commentRoomKey(spaceId, canonicalPagePath("/"), {
      id: artifact.id,
      current: artifact.current,
    });
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

  // ...and a rollback hands it back its draft room. The live host never gets
  // one: whatever the route map says, the Space's published surface IS the live
  // artifact, so its threads are the permanent record.
  await putRoute(runtime, spaceId, "production", routeBody(first));
  expect(await derivedRoomKey(versionHost)).toBe(`space:${spaceId}:draft:${second}:path:%2F`);
  expect(await derivedRoomKey("promote.site.test")).toBe(`space:${spaceId}:path:%2F`);
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
    const published = collabManifestSchema.parse(
      await get(runtime, "secure-comments.site.test", RUNTIME_COLLAB_MANIFEST_PATH).then((r) =>
        r.json(),
      ),
    );
    expect(published.ticketUrl).toBe(
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

  // The manifest and the theme are subresources of the same admitted page and
  // reach the runtime with the `sec-fetch-dest` each is loaded with: `empty`
  // for the SDK's `fetch()`, `style` for the `<link>` sdk.js writes — and the
  // theme arrives both ways, because the default UI fetches the same bytes to
  // adopt them inside its shadow root. Without the page's Referer scope they
  // would be enforced against their own URL, which no Grant lists — a 403 for a
  // visitor who is admitted to the page that embeds them.
  for (const [path, dest] of [
    [RUNTIME_COLLAB_MANIFEST_PATH, "empty"],
    [RUNTIME_COLLAB_THEME_PATH, "style"],
    [RUNTIME_COLLAB_THEME_PATH, "empty"],
  ] as const) {
    const scoped = await get(runtime, host, path, {
      headers: { cookie, referer: `https://${host}/docs/`, "sec-fetch-dest": dest },
    });
    expect(scoped.status).toBe(200);
    expect(scoped.headers.get("cache-control")).toBe("private, no-store");
    expect(scoped.headers.get("vary")).toContain("Cookie");

    // No session: the Referer selects a scope, it never grants one.
    const bareDocument = await get(runtime, host, path, {
      headers: { referer: `https://${host}/docs/`, "sec-fetch-dest": dest },
    });
    expect(bareDocument.status).toBe(403);
    expect(bareDocument.headers.get("etag")).toBeNull();

    // A session, but pointed at a page no Grant covers.
    const forgedDocument = await get(runtime, host, path, {
      headers: { cookie, referer: `https://${host}/hidden/`, "sec-fetch-dest": dest },
    });
    expect(forgedDocument.status).toBe(403);
    expect(forgedDocument.headers.get("etag")).toBeNull();

    // The dest has to match how the document is actually loaded: a `script`
    // fetch of the manifest is not the page's subresource.
    const wrongDest = await get(runtime, host, path, {
      headers: { cookie, referer: `https://${host}/docs/`, "sec-fetch-dest": "script" },
    });
    expect(wrongDest.status).toBe(403);
  }

  try {
    // An admitted visitor's session authorities ride the MINT — the one lane
    // that reaches the control plane at all.
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
    // A denied page answers the Comments lane with the JSON envelope, never the
    // HTML access gate — and without ever reaching the control plane.
    const denied = await mintTicket();
    expect(denied.status).toBe(401);
    expect(await errorCode(denied)).toBe("comments_denied");
    expect(denied.headers.get("content-type") ?? "").toContain("application/problem+json");
    expect(exchanges).toHaveLength(0);

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
