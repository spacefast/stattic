import { afterAll, expect, test } from "bun:test";
import { createHash, createHmac, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { Window, type ShadowRoot } from "happy-dom";
import { z } from "zod";

import {
  remixButtonScript,
  referralChipScript,
} from "../../apps/control-plane/src/tags/platform-tags.ts";
import { canonicalPagePath, commentRoomKey } from "../../packages/collab-sdk/src/core.ts";
import { ICON_PATHS } from "../../packages/collab-sdk/src/theme/icons.ts";
import { SPACEFAST_MARK_PATH } from "../../packages/common/src/brand-assets.ts";
import { collabManifestSchema } from "../../packages/common/src/contracts/collab-manifest.ts";
import type { RouteInjectionPlan } from "../../packages/common/src/contracts/injection.ts";
import {
  sellRuntimeCheckoutRequestSchema,
  type SellRuntimeCheckoutRequest,
  sellRuntimeProductRequestSchema,
  sellPublicProductSchema,
} from "../../packages/common/src/contracts/sell.ts";
// Fetched through the shipped constants, not string literals: PHP and TypeScript
// name these two URLs independently, and this suite is where a drift between
// them surfaces.
import {
  RUNTIME_COLLAB_MANIFEST_PATH,
  RUNTIME_COLLAB_THEME_PATH,
  RUNTIME_SELL_CHECKOUT_PATH,
  RUNTIME_SELL_PRODUCT_PATH,
  RUNTIME_SELL_CLIENT_PATH,
  RUNTIME_TRACKER_SCRIPT_PATH,
} from "../../packages/common/src/utils/runtime-paths.ts";
import {
  deploy,
  errorCode,
  get,
  runtimeHttpPath,
  prependHostRoutes,
  redeemHandoff,
  problemDocument,
  publicAccessConfig,
  putRoute,
  signEd25519Jwt,
  spaceOverlay,
  spaceRoot,
  startRuntime,
  storagePath,
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
  options: {
    host?: string;
    pathname?: string;
    storedPlacement?: string;
    background?: string;
    cookie?: string;
    cookieThrows?: boolean;
    /**
     * A second SDK tag on the same page, run after the first with this
     * `document.cookie` (another tab signed in meanwhile).
     */
    secondTagCookie?: string;
  } = {},
) {
  let cookie = options.cookie;
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
    get cookie(): string {
      // A sandboxed frame throws on reading document.cookie.
      if (cookie === undefined && options.cookieThrows) throw new Error("SecurityError");
      return cookie ?? "";
    },
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
  const run = Function(
    "window",
    "document",
    "location",
    "localStorage",
    "innerWidth",
    "innerHeight",
    "getComputedStyle",
    source,
  );
  const runOnce = () =>
    run(
      fakeWindow,
      fakeDocument,
      { host, origin: `https://${host}`, pathname: options.pathname ?? "/" },
      { getItem: () => options.storedPlacement ?? null },
      1_000,
      900,
      // The body's painted background; html and anything else paint none.
      (element: typeof fakeDocument.body) => ({
        backgroundColor:
          element === fakeDocument.body && options.background
            ? options.background
            : "rgba(0, 0, 0, 0)",
      }),
    );
  runOnce();
  if (options.secondTagCookie !== undefined) {
    cookie = options.secondTagCookie;
    runOnce();
  }
  return { head, body, events, window: fakeWindow };
}

/**
 * The config sdk.js boots from: the one JSON object the runtime appends to the
 * static script (`window.Spacefast.boot(<config>)`). Null when the response is
 * the "injection off" comment.
 */
const bootConfigSchema = z
  .object({
    reload: z.object({ preview: z.string().optional(), review: z.boolean().optional() }).optional(),
    users: z.boolean().optional(),
    tracker: z
      .object({ src: z.string(), site: z.string(), endpoint: z.string() })
      .loose()
      .optional(),
    collab: z
      .object({ theme: z.string(), config: z.string(), module: z.string().optional() })
      .optional(),
    bar: z
      .object({
        frame: z.string(),
        dashboard: z.string(),
        module: z.string(),
        public: z.object({ marker: z.string() }).optional(),
        mark: z
          .object({
            links: z.array(z.object({ label: z.string(), href: z.string() })),
            signIn: z.string().optional(),
          })
          .optional(),
      })
      .optional(),
    scripts: z.array(z.object({ src: z.string() }).loose()).optional(),
    runtime: z.object({ live: z.boolean(), brand: z.string() }).optional(),
  })
  .strict();
function bootConfig(body: string) {
  const call = body.match(/\nwindow\.Spacefast\.boot\((\{.*?\})(?:,function\(\)\{|\);)/s);
  return call ? bootConfigSchema.parse(JSON.parse(call[1] ?? "{}")) : null;
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
    config: {
      cast_api_base: "https://cast.example.test",
      tracker: {
        src: RUNTIME_TRACKER_SCRIPT_PATH,
        endpoint: "https://beacon.example.test",
        key: "pk_sdk",
        consent: "wait",
      },
    },
    body: "window.__embeddedTagLoaded=true;window.__embeddedTagRuns=(window.__embeddedTagRuns||0)+1;",
  };
  await deploy(runtime, {
    spaceId: "spc_sdk",
    versionId: "ver_sdk_1",
    // A page with no body element, which the compiler cannot bake the tag
    // into: the runtime fills the gap, and on a tag-preview request its tag
    // names the draft release.
    files: { "index.html": "<html><head><title>SDK</title></head><h1>SDK</h1></html>\n" },
    serving: {
      config: {
        inject: {
          bodyEnd: ['<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>'],
        },
      },
    },
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
  // Comments are not available for this surface, so the config names no
  // Comments at all, and nothing about Cast's room or its ticket.
  const booted = bootConfig(body);
  expect(booted?.collab).toBeUndefined();
  expect(booted?.users).toBe(true);
  expect(body).not.toContain("resource_key");
  expect(body).not.toContain("cast_ws_url");
  expect(body).not.toContain("comments/ticket");
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
  expect(fakeWindow.__embeddedTagRuns).toBe(1);
  expect(assigned.map((url) => new URL(url).searchParams.get("provider"))).toEqual([
    "google",
    "gravatar",
    "spacefast",
  ]);
  expect(assigned.every((url) => new URL(url).origin === browser.origin)).toBe(true);
  expect(JSON.stringify(fakeWindow.Spacefast)).not.toContain("test-private-client-secret");
  // With Comments off, the one script the loader adds is the analytics tracker,
  // from this host and reporting to Beacon, not to the host it came from,
  // marked as injected so a tag the author placed wins, and waiting for a
  // grant as this Space asks. The body ran twice above and added it once.
  expect(appended).toEqual([
    {
      async: true,
      dataset: {
        site: "pk_sdk",
        endpoint: "https://beacon.example.test",
        consent: "wait",
        injected: "",
      },
      src: RUNTIME_TRACKER_SCRIPT_PATH,
      type: "",
    },
  ]);
  expect(fakeWindow.__embeddedTagLoaded).toBe(true);
  expect(response.headers.get("content-type")).toContain("application/javascript");
  expect(response.headers.get("access-control-allow-origin")).toBe("*");
  expect(response.headers.get("cross-origin-resource-policy")).toBe("cross-origin");
  expect(response.headers.get("timing-allow-origin")).toBe("*");
  expect(response.headers.get("etag")).toContain("runtime:");
  expect(response.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, must-revalidate",
  );
  expect(response.headers.get("x-spacefast-sdk-revision")).toContain("runtime:");
  expect(response.headers.get("vary")).toBeNull();

  // Version tokens do not make revocable overlay state immutable.
  const versioned = await get(runtime, SITE, "/__spacefast/sdk.js?v=engine-rev-abc", {
    headers: { origin: "https://third.example.test" },
  });
  expect(versioned.status).toBe(200);
  expect(await versioned.text()).toBe(body);
  expect(versioned.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=600, must-revalidate",
  );
  expect(versioned.headers.get("access-control-allow-origin")).toBe("*");

  // Capability-selected previews must not enter a shared cache.
  const versionedPreview = await get(
    runtime,
    SITE,
    "/__spacefast/sdk.js?v=engine-rev-abc&preview=preview-token",
  );
  expect(versionedPreview.status).toBe(200);
  expect(versionedPreview.headers.get("cache-control")).toBe("private, no-store");

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
  expect(preview.headers.get("cache-control")).toBe("private, no-store");
  // A `?preview=` token names a tag release, never a surface. The live host is
  // the live surface whatever the URL is decorated with — otherwise the preview
  // Comments lane could be consulted from the live site by anyone who appended
  // a query parameter. The manifest is where that verdict is now published.
  const previewSdk = await preview.text();
  // A draft is not a visit: a preview token loads no tracker.
  expect(bootConfig(previewSdk)?.tracker).toBeUndefined();
  const whitespaceWindow = { location: { search: "?spacefast_tag_preview=%20" } };
  Function("window", "document", body)(whitespaceWindow, fakeDocument);
  expect(Object.hasOwn(whitespaceWindow, "__embeddedTagRuns")).toBe(true);
  const draftWindow = { location: { search: "?spacefast_tag_preview=preview-token" } };
  const draftScripts: Array<{ src: string }> = [];
  const draftDocument = {
    createElement: () => ({ src: "", dataset: {} }),
    head: { appendChild: (script: { src: string }) => draftScripts.push(script) },
  };
  Function("window", "document", body)(draftWindow, draftDocument);
  Function("window", "document", body)(draftWindow, draftDocument);
  expect(Object.hasOwn(draftWindow, "__embeddedTagRuns")).toBe(false);
  expect(draftScripts.map((script) => script.src)).toEqual([
    "/__spacefast/sdk.js?preview=preview-token",
  ]);
  Function("window", "document", previewSdk)(draftWindow, draftDocument);
  expect(Object.hasOwn(draftWindow, "__embeddedTagRuns")).toBe(false);
  expect(draftScripts.filter((script) => script.src.includes("/tags/sdk.js")).length).toBe(1);
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
    "public, max-age=0, s-maxage=600, must-revalidate",
  );
  expect(previewPage.headers.get("cdn-cache-control")).toBeNull();
  expect(previewPage.headers.get("surrogate-control")).toBeNull();
  expect(previewPage.headers.get("set-cookie") ?? "").not.toContain("spacefast_tag_preview");
  const previewBody = await previewPage.text();
  // The page carries no tag of its own: the runtime adds the one tag, first in
  // <head>.
  expect(previewBody).toContain(
    '<head><script defer src="/__spacefast/sdk.js?preview=preview-token" data-spacefast-sdk="v1"></script>',
  );
  expect(previewBody.match(/data-spacefast-sdk/g)?.length).toBe(1);
  expect(previewPage.headers.get("content-length")).toBe(String(Buffer.byteLength(previewBody)));
  // A preview page never revalidates against the live page's validator.
  const livePage = await get(runtime, SITE, "/");
  expect(await livePage.text()).not.toContain("preview=");
  expect(previewPage.headers.get("etag")).not.toBe(livePage.headers.get("etag"));

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
  // The engine adds its SDK tag without looking for the page's own, so a page
  // can load the bootstrap twice: the review module still loads once.
  const reviewWindow = {};
  const reviewDocument = {
    createElement: () => ({}),
    head: { appendChild: (node: (typeof reviewNodes)[number]) => reviewNodes.push(node) },
  };
  const reviewedBody = await reviewedSdk.text();
  Function("window", "document", reviewedBody)(reviewWindow, reviewDocument);
  Function("window", "document", reviewedBody)(reviewWindow, reviewDocument);
  // Neither is a review on a version host: the review script and no tracker.
  expect(reviewNodes.map((node) => node.src).filter(Boolean)).toEqual([
    "https://cast.example.test/sdk/v1/review.js",
  ]);
  expect(
    JSON.parse(reviewNodes.find((node) => node.id === "sf-visual-review")?.textContent ?? "null"),
  ).toEqual({ ...review, spaceId: "spc_sdk" });
});

// The Remix button the owner turned on for a source-available Space: drawn on
// the live host, never on a version host, a preview draft or inside a frame,
// and only for a plain https remix link.
test("sdk.js draws platform buttons on the live surface only", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const host = "remix.site.test";
  const versionHost = "remix--v1.site.test";
  const remixUrl =
    "https://my.spacefast.com/new?git=https%3A%2F%2Fgit.spacefast.com%2Fstudio%2Fremix";
  const referralUrl = "https://spacefast.com/?sf_ref=studio";
  const routeBody = (remix: string | null) => {
    const config = publicAccessConfig(
      { mode: "website", site_title: "Remix" },
      "live_and_all_versions",
    );
    config.sdk = {
      revision: `sdk-remix-${remix ?? "none"}`,
      config: {},
      body: remixButtonScript(remix) + referralChipScript(remix === remixUrl ? referralUrl : remix),
    };
    return {
      version_id: "ver_remix_1",
      config,
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: versionHost, version_id: "ver_remix_1" }],
    };
  };
  await deploy(runtime, {
    spaceId: "spc_remix",
    versionId: "ver_remix_1",
    files: { "index.html": "<h1>Remix me</h1>\n" },
    activate: { route_name: "production", ...routeBody(remixUrl) },
  });
  const windows: Window[] = [];
  const draw = (body: string, framed = false) => {
    const view = new Window({ url: `https://${host}/` });
    windows.push(view);
    const shadows: ShadowRoot[] = [];
    const attachShadow = view.HTMLElement.prototype.attachShadow;
    view.HTMLElement.prototype.attachShadow = function (options) {
      const shadow = attachShadow.call(this, options);
      shadows.push(shadow);
      return shadow;
    };
    Object.defineProperty(view, "top", { value: framed ? {} : view });
    view.eval(body);
    view.eval(body);
    return { view, shadow: shadows[0], referralShadow: shadows[1] };
  };
  try {
    const live = await get(runtime, host, "/__spacefast/sdk.js?v=saved-revision");
    expect(live.status).toBe(200);
    const liveBody = await live.text();
    const { view, shadow, referralShadow } = draw(liveBody);
    expect(view.document.querySelectorAll("#sf-remix")).toHaveLength(1);
    expect(view.document.getElementById("sf-remix")?.style.left).toBe("16px");
    expect(shadow?.querySelector("a")?.href).toBe(remixUrl);
    expect(shadow?.querySelector("a")?.getAttribute("aria-label")).toBe(
      "Remix this space on Spacefast",
    );
    expect(shadow?.querySelector("a")?.target).toBe("_blank");
    expect(shadow?.querySelector("path")?.getAttribute("d")).toBe(SPACEFAST_MARK_PATH);
    expect(shadow?.textContent).toContain("Remix this space");
    expect(view.document.querySelectorAll("#sf-referral")).toHaveLength(1);
    expect(view.document.getElementById("sf-referral")?.style.right).toBe("16px");
    expect(referralShadow?.querySelector("a")?.href).toBe(referralUrl);
    expect(referralShadow?.querySelector("a")?.textContent).toContain("Made with Spacefast");
    expect(draw(liveBody, true).view.document.getElementById("sf-referral")).toBeNull();
    expect(draw(liveBody, true).view.document.getElementById("sf-remix")).toBeNull();
    for (const response of [
      await get(runtime, versionHost, "/__spacefast/sdk.js"),
      await get(runtime, host, "/__spacefast/sdk.js?preview=preview-token"),
      await get(runtime, host, "/__spacefast/sdk.js?review=review-token"),
    ]) {
      expect(response.status).toBe(200);
      const drawn = draw(await response.text());
      expect(drawn.view.document.getElementById("sf-remix")).toBeNull();
      expect(drawn.view.document.getElementById("sf-referral")).toBeNull();
    }
    for (const refused of ["javascript:alert(1)", "http://my.spacefast.com/new", null]) {
      await putRoute(runtime, "spc_remix", "production", routeBody(refused));
      const response = await get(runtime, host, "/__spacefast/sdk.js?v=saved-revision", {
        headers: { "if-none-match": live.headers.get("etag") ?? "" },
      });
      expect(response.status).toBe(200);
      expect(response.headers.get("etag")).not.toBe(live.headers.get("etag"));
      expect(response.headers.get("cache-control")).toBe(
        "public, max-age=0, s-maxage=600, must-revalidate",
      );
      const drawn = draw(await response.text());
      expect(drawn.view.document.getElementById("sf-remix")).toBeNull();
      expect(drawn.view.document.getElementById("sf-referral")).toBeNull();
    }
  } finally {
    await Promise.all(windows.map((view) => view.happyDOM.close()));
  }
});

// t.js is Beacon's, served from the Space's own origin. The box keeps one copy
// per Beacon, so a miss anyone can manufacture never reaches Beacon, and Beacon
// being down is the last copy or an empty script, never an error on the page.
test("t.js is Beacon's tracker from a copy the box fills once, and never an error", async () => {
  const TRACKER = "window.__beaconTracker=true;\n";
  const NEXT_TRACKER = "window.__beaconTracker=2;\n";
  let release = TRACKER;
  // Beacon's edge answers no-cache when it served its copy stale.
  let edgeStale = false;
  const upstream: Array<{ url: string; cookie: string | null }> = [];
  const beacon = Bun.serve({
    port: 0,
    fetch(request) {
      const url = new URL(request.url);
      upstream.push({ url: url.pathname + url.search, cookie: request.headers.get("cookie") });
      return new Response(release, {
        headers: {
          "content-type": "text/javascript",
          "cache-control": edgeStale ? "no-cache" : "public, max-age=300",
        },
      });
    },
  });
  const endpoint = `http://127.0.0.1:${beacon.port}`;
  const nowhere = Bun.serve({ port: 0, fetch: () => new Response(null) });
  const unreachable = `http://127.0.0.1:${nowhere.port}`;
  await nowhere.stop(true);
  const errorLog = path.join(os.tmpdir(), `sdk-tracker-${randomUUID()}.log`);
  try {
    const runtime = await startRuntime({ phpIni: { error_log: errorLog } });
    runtimes.push(runtime);
    const host = "tracker.site.test";
    const routeBody = (tracker: Record<string, string> | null) => {
      const config = publicAccessConfig({ mode: "website", site_title: "Tracker" });
      config.sdk = { revision: "sdk-tracker-1", config: { tracker } };
      return {
        version_id: "ver_tracker_1",
        config,
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      };
    };
    const tracked = { src: RUNTIME_TRACKER_SCRIPT_PATH, endpoint, key: "pk_tracker" };
    await deploy(runtime, {
      spaceId: "spc_tracker",
      versionId: "ver_tracker_1",
      files: { "index.html": "<h1>Tracked</h1>\n" },
      activate: { route_name: "production", ...routeBody(tracked) },
    });
    const tracker = (query = "") => get(runtime, host, `${RUNTIME_TRACKER_SCRIPT_PATH}${query}`);
    // Every answer that is not Beacon's script: valid JavaScript that does
    // nothing, held for a minute.
    const expectEmpty = async (response: Response) => {
      expect(response.status).toBe(200);
      const body = await response.text();
      expect(body).not.toContain("__beaconTracker");
      expect(() => Function(body)).not.toThrow();
      expect(response.headers.get("cache-control")).toBe("public, max-age=60");
      expect(response.headers.get("a8c-edge-cache")).toBe("cache");
      return body;
    };

    const served = await get(runtime, host, RUNTIME_TRACKER_SCRIPT_PATH, {
      headers: { cookie: "harmless_publisher_cookie=one" },
    });
    expect(served.status).toBe(200);
    expect(await served.text()).toBe(TRACKER);
    expect(served.headers.get("content-type")).toContain("application/javascript");
    expect(served.headers.get("cache-control")).toBe("public, max-age=60, s-maxage=300");
    expect(served.headers.get("a8c-edge-cache")).toBe("cache");
    // The visitor's cookies stay on this host.
    expect(upstream).toEqual([{ url: "/t.js", cookie: null }]);

    // The next miss is the box's copy, not another request to Beacon.
    expect(await (await tracker()).text()).toBe(TRACKER);
    // A query is a new edge key, so anyone can make one miss after another
    // with it. None of them reaches Beacon.
    await expectEmpty(await tracker("?cb=1"));
    await expectEmpty(await tracker("?cb=2"));
    expect(upstream).toEqual([{ url: "/t.js", cookie: null }]);

    // Past its lifetime, the box takes Beacon's new release, and says so in
    // the PHP log by the short shas of the old and new ETags.
    const copyPath = path.join(
      runtime.storageRoot,
      "runtime/tracker",
      `${createHash("sha256").update(endpoint).digest("hex")}.json`,
    );
    expect(JSON.parse(readFileSync(copyPath, "utf8"))).toMatchObject({ body: TRACKER });
    const expire = (body: string) =>
      writeFileSync(copyPath, JSON.stringify({ fetched_at: 0, attempted_at: 0, body }));
    const shortSha = (body: string) => createHash("sha256").update(body).digest("hex").slice(0, 12);
    release = NEXT_TRACKER;
    expire(TRACKER);
    const refreshed = await tracker();
    expect(await refreshed.text()).toBe(NEXT_TRACKER);
    expect(refreshed.headers.get("cache-control")).toBe("public, max-age=60, s-maxage=300");
    expect(readFileSync(errorLog, "utf8")).toContain(
      `spacefast tracker copy refreshed endpoint=${endpoint}` +
        ` sha256=${shortSha(NEXT_TRACKER)} previous=${shortSha(TRACKER)}`,
    );

    // A no-cache answer is served, but not as fresh, and the box does not ask
    // again until the retry window has passed. Then it is fresh again.
    edgeStale = true;
    expire(NEXT_TRACKER);
    const marked = await tracker();
    expect(await marked.text()).toBe(NEXT_TRACKER);
    expect(marked.headers.get("cache-control")).toBe("public, max-age=60");
    const asked = upstream.length;
    expect((await tracker()).headers.get("cache-control")).toBe("public, max-age=60");
    expect(upstream).toHaveLength(asked);
    edgeStale = false;
    expire(NEXT_TRACKER);
    const renewed = await tracker();
    expect(await renewed.text()).toBe(NEXT_TRACKER);
    expect(renewed.headers.get("cache-control")).toBe("public, max-age=60, s-maxage=300");
    expect(upstream).toHaveLength(asked + 1);

    // Past its lifetime with Beacon down, the box serves its last copy for a
    // minute at a time instead of nothing.
    expire(NEXT_TRACKER);
    await beacon.stop(true);
    const stale = await tracker();
    expect(await stale.text()).toBe(NEXT_TRACKER);
    expect(stale.headers.get("cache-control")).toBe("public, max-age=60");

    // A Beacon the box never reached leaves it nothing to serve.
    await putRoute(runtime, "spc_tracker", "production", {
      ...routeBody({ ...tracked, endpoint: unreachable }),
    });
    const empty = await expectEmpty(await tracker());

    // Opting out is a route update, not a publish: the loader goes from sdk.js,
    // and t.js answers exactly as it does for a query, so it tells a visitor
    // nothing about the Space's analytics.
    await putRoute(runtime, "spc_tracker", "production", routeBody(null));
    expect(await expectEmpty(await tracker())).toBe(empty);
    expect(await (await get(runtime, host, "/__spacefast/sdk.js")).text()).not.toContain(
      RUNTIME_TRACKER_SCRIPT_PATH,
    );
  } finally {
    await beacon.stop(true);
    rmSync(errorLog, { force: true });
  }
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
    mentionableUrl: "http://local-sdk.site.test/__spacefast/comments/mentionable",
    // Configuration and nothing else: this runtime names an API base that is
    // not a `cast.` sibling of its relay, and that is what the manifest carries.
    apiBase: "https://api.example.test",
    // Never the descriptor's own URL: the page goes to this host's account
    // start route, which is what mints browser state before the handoff.
    accountUrl: "http://local-sdk.site.test/__spacefast/access/account",
    // Renewal runs only through the Space's own sign-in, and this Space signs
    // in through the account broker, so the overlay is never told to renew.
    returningAccountCookie: null,
    features: { picker: true, drawing: true, capture: false, attachments: true },
    // The revocable read key rides the manifest: that IS the fresh-URL
    // mechanism. Its exact value is asserted below.
    uploads: {
      base: "http://local-sdk.site.test/__stattic/u/",
      key: expect.stringMatching(/^[a-f0-9]{32}$/),
    },
    ui: "default",
    theme: LOCAL_COMMENTS.theme,
  });
  // An open Space hands this manifest to anonymous visitors, so its key is
  // this Space's own, derived from the box secret. The box secret itself, which
  // would open every Space's uploads on the box, never appears.
  // SAFETY: the runtime writes storage-read-key.json in this test harness with a
  // fixed `{ key: string }` shape; we read back the value this test just caused
  // to be written.
  const boxSecret = (
    JSON.parse(readFileSync(storagePath(runtime, "runtime", "storage-read-key.json"), "utf8")) as {
      key: string;
    }
  ).key;
  const manifestKey = collabManifestSchema.parse(manifest).uploads?.key;
  expect(manifestKey).toBe(
    createHmac("sha256", boxSecret)
      .update("storage-read-key:v1:spc_sdk_local")
      .digest("hex")
      .slice(0, 32),
  );
  expect(manifestKey).not.toBe(boxSecret);
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
  // Neutral ink read off the site, never the Space's color, which is what the
  // real orb boots into: a page that paints no background is the browser's
  // light canvas, so the disc is dark and its ink white.
  expect(placeholderOrb?.style.cssText).toContain("background:#1d1d22");
  expect(placeholderOrb?.style.cssText).not.toContain("#4f46e5");
  expect(placeholderOrb?.innerHTML).toContain("sf-boot-pulse");
  // The glyph and its ink are the SDK's, not a lookalike: a placeholder drawing
  // a different shape or a different ink is a visible swap at boot.
  expect(placeholderOrb?.innerHTML).toContain(ICON_PATHS.comment);
  expect(placeholderOrb?.innerHTML).toContain('stroke="#ffffff"');
  // On a dark site the disc turns light, with dark ink.
  const onDark = runSdk(body, { storedPlacement, background: "rgb(20, 18, 16)" }).body[0];
  expect(onDark?.style.cssText).toContain("background:#f4f4f6");
  expect(onDark?.innerHTML).toContain('stroke="#16161b"');

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
  // No Cast origin the visitor could reach means no Comments: nothing in the
  // config, so no placeholder orb and no module loader.
  expect(bootConfig(bakedBody)?.collab).toBeUndefined();
  expect(bakedBody).not.toContain("resource_baked_local");
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
  // The disc is neutral ink, never the accent, so a hostile accent has no way
  // into its CSS declaration at all.
  expect(hostileOrb?.style.cssText).toContain("background:#1d1d22");
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
  // An unclaimed Space names no account to continue with, nor a marker to
  // come back to one.
  expect(parsedNoUploads.accountUrl).toBeNull();
  expect(parsedNoUploads.returningAccountCookie).toBeNull();
  // The rest of the Space still speaks: withholding the store is not a shutdown.
  expect(parsedNoUploads.cast).not.toBeNull();
});

test("Comments configuration stays on-origin while the runtime authenticates upstream", async () => {
  const exchanges: Array<{ credential: string | null; payload: Record<string, unknown> }> = [];
  const checkouts: Array<{
    credential: string | null;
    pagePath: string | null;
    payload: SellRuntimeCheckoutRequest;
  }> = [];
  let ticketFailureCode: string | null = null;
  const central = Bun.serve({
    port: 0,
    async fetch(request) {
      if (new URL(request.url).pathname === "/sell/runtime/spc_sdk_exchange/product") {
        const input = sellRuntimeProductRequestSchema.parse(await request.json());
        if (
          input.deploymentId !== "ver_sdk_exchange_1" ||
          request.headers.get("spacefast-runtime-exchange") !==
            "runtime-comments-credential-0000000000000000000000000000"
        )
          return Response.json({ code: "access_denied" }, { status: 403 });
        return Response.json({
          data: sellPublicProductSchema.parse({
            key: input.productKey,
            name: "Guide",
            kind: "digital",
            price: { amountMinor: 1900, currency: "usd" },
            testMode: true,
          }),
        });
      }
      if (new URL(request.url).pathname === "/sell/runtime/spc_sdk_exchange/checkout") {
        checkouts.push({
          credential: request.headers.get("spacefast-runtime-exchange"),
          pagePath: request.headers.get("spacefast-visitor-page-path"),
          payload: sellRuntimeCheckoutRequestSchema.parse(await request.json()),
        });
        return Response.json({
          data: { status: "processing", purchaseToken: "signed-locator", testMode: true },
        });
      }
      const fields = new URLSearchParams(await request.text());
      exchanges.push({
        credential: request.headers.get("spacefast-runtime-exchange"),
        payload: JSON.parse(fields.get("payload") ?? "{}") as Record<string, unknown>,
      });
      if (new URL(request.url).pathname.endsWith("/mentionable")) {
        return Response.json({ data: [{ userId: "usr_zebra", name: "Zebra", avatarUrl: null }] });
      }
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
          commentsMentionableUrl: `${central.url}runtime/comments/runtime-comments/mentionable`,
          zeroRealtimeTicketUrl: `${central.url}acquire/runtime-comments/zero/realtime-ticket`,
          sellCheckoutUrl: `${central.url}sell/runtime/spc_sdk_exchange/checkout`,
          sellProductUrl: `${central.url}sell/runtime/spc_sdk_exchange/product`,
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
      files: {
        "docs/index.html": "<h1>Docs</h1>\n",
        [`${Array.from({ length: 5 }, () => "界".repeat(50)).join("/")}/index.html`]:
          "<h1>Unicode route</h1>\n",
      },
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

    // Well-formed on purpose: even a valid guest id the page names is ignored.
    const pageChosenId = `pg1_${"p".repeat(43)}`;
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
          identity: { anonymousId: pageChosenId, name: "Page visitor", namedByUser: true },
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
    expect(firstIdentity.anonymousId).toMatch(/^pg1_[A-Za-z0-9_-]{43}$/);
    expect(firstIdentity.anonymousId).not.toBe(pageChosenId);
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
    expect(tamperedIdentity.anonymousId).toMatch(/^pg1_[A-Za-z0-9_-]{43}$/);
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

    const unicodeRequestPath = `/${Array.from({ length: 5 }, () => "界".repeat(50)).join("/")}`;
    const encodedRequestPath = `${unicodeRequestPath.split("/").map(encodeURIComponent).join("/")}/`;
    expect(new TextEncoder().encode(encodedRequestPath).length).toBeGreaterThan(2_048);
    const unicodePage = await get(runtime, "comments-exchange.site.test", encodedRequestPath);
    expect(unicodePage.status).toBe(200);
    expect(await unicodePage.text()).toContain("<h1>Unicode route</h1>");

    const byteBoundary = `/docs/${"😀".repeat(510)}ab`;
    expect(new TextEncoder().encode(byteBoundary).length).toBe(2_048);
    const boundaryTicket = (pagePath: string) =>
      get(runtime, "comments-exchange.site.test", "/__spacefast/comments/ticket", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
        },
        body: JSON.stringify({
          pagePath,
          identity: { anonymousId: "anon_browser_chosen", name: "Page visitor", namedByUser: true },
        }),
      });
    const boundaryResponse = await boundaryTicket(byteBoundary);
    expect(boundaryResponse.status, await boundaryResponse.clone().text()).toBe(200);
    expect(exchanges.at(-1)?.payload["pagePath"]).toBe(byteBoundary);
    const encodedBoundary = await boundaryTicket(encodedRequestPath);
    expect(encodedBoundary.status, await encodedBoundary.clone().text()).toBe(200);
    expect(exchanges.at(-1)?.payload["pagePath"]).toBe(unicodeRequestPath);
    const beforeOverflow = exchanges.length;
    const overflowTicket = await boundaryTicket(`${byteBoundary}x`);
    expect(overflowTicket.status).toBe(401);
    expect(await errorCode(overflowTicket)).toBe("comments_path_invalid");
    expect(exchanges.length).toBe(beforeOverflow);
    // `%2541` decodes to `%41` here; forwarding it would let the control plane
    // decode it again into `A` and sign a ticket for another page's room.
    const nestedPercent = await boundaryTicket("/docs/a%2541");
    expect(nestedPercent.status).toBe(401);
    expect(await errorCode(nestedPercent)).toBe("comments_path_invalid");
    expect(exchanges.length).toBe(beforeOverflow);

    const mentionSearch = await get(
      runtime,
      "comments-exchange.site.test",
      "/__spacefast/comments/mentionable",
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
          "sec-fetch-site": "same-origin",
          cookie: commentsCookie,
        },
        body: JSON.stringify({ pagePath: "/docs", query: "Zebra" }),
      },
    );
    expect(mentionSearch.status).toBe(200);
    expect(await mentionSearch.json()).toEqual({
      data: [{ userId: "usr_zebra", name: "Zebra", avatarUrl: null }],
    });
    expect(exchanges.at(-1)).toMatchObject({
      credential: "runtime-comments-credential-0000000000000000000000000000",
      payload: {
        origin: "http://comments-exchange.site.test",
        pagePath: "/docs",
        query: "Zebra",
        principal: "anonymous",
        visitorSessionId: firstSessionId,
        authorities: [],
      },
    });

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
    const checkoutInput = {
      pagePath: "/docs",
      productKey: "guide",
      attemptKey: randomUUID(),
      attemptCreatedAt: new Date().toISOString(),
    };
    const checkout = (
      input: typeof checkoutInput & { deploymentId?: string } = checkoutInput,
      origin = "http://comments-exchange.site.test",
    ) =>
      get(runtime, "comments-exchange.site.test", RUNTIME_SELL_CHECKOUT_PATH, {
        method: "POST",
        headers: { "content-type": "application/json", origin },
        body: JSON.stringify(input),
      });
    const checkoutResponse = await checkout();
    expect(checkoutResponse.status).toBe(200);
    expect(await checkoutResponse.json()).toEqual({
      data: { status: "processing", purchaseToken: "signed-locator", testMode: true },
    });
    expect(checkouts).toEqual([
      {
        credential: "runtime-comments-credential-0000000000000000000000000000",
        pagePath: "/docs",
        payload: {
          deploymentId: "ver_sdk_exchange_1",
          productKey: checkoutInput.productKey,
          attemptKey: checkoutInput.attemptKey,
          attemptCreatedAt: checkoutInput.attemptCreatedAt,
        },
      },
    ]);
    expect(checkoutResponse.headers.get("cache-control")).toBe("no-store");
    expect(checkoutResponse.headers.getSetCookie()).toHaveLength(0);
    expect((await checkout({ ...checkoutInput, deploymentId: "ver_browser_chosen" })).status).toBe(
      422,
    );
    expect((await checkout(checkoutInput, "https://attacker.example")).status).toBe(403);
    expect(checkouts).toHaveLength(1);
    // A directory page keeps its slash, so Checkout's back link lands without a redirect.
    expect((await checkout({ ...checkoutInput, pagePath: "/docs/" })).status).toBe(200);
    expect(checkouts.at(-1)?.pagePath).toBe("/docs/");
    checkouts.pop();
    const productResponse = await get(
      runtime,
      "comments-exchange.site.test",
      RUNTIME_SELL_PRODUCT_PATH,
      {
        method: "POST",
        headers: {
          "content-type": "application/json",
          origin: "http://comments-exchange.site.test",
        },
        body: JSON.stringify({ pagePath: "/docs", productKey: "guide" }),
      },
    );
    expect(productResponse.status).toBe(200);
    expect(await productResponse.json()).toEqual({
      data: {
        key: "guide",
        name: "Guide",
        kind: "digital",
        price: { amountMinor: 1900, currency: "usd" },
        testMode: true,
      },
    });
    expect(productResponse.headers.get("cache-control")).toBe("no-store");
    const paymentScript = await get(
      runtime,
      "comments-exchange.site.test",
      RUNTIME_SELL_CLIENT_PATH,
    );
    expect(paymentScript.status).toBe(200);
    expect(paymentScript.headers.get("content-type")).toBe("application/javascript; charset=utf-8");
    expect(paymentScript.headers.get("cache-control")).toBe("no-store");
    expect(paymentScript.headers.get("x-content-type-options")).toBe("nosniff");
    expect((await paymentScript.arrayBuffer()).byteLength).toBeGreaterThan(0);
    const paymentScriptHead = await get(
      runtime,
      "comments-exchange.site.test",
      RUNTIME_SELL_CLIENT_PATH,
      { method: "HEAD" },
    );
    expect(paymentScriptHead.status).toBe(200);
    expect((await paymentScriptHead.arrayBuffer()).byteLength).toBe(0);
    const exchangesBeforeOutage = exchanges.length;

    // An unreachable control plane is a 502, distinguishable from denial.
    central.stop(true);
    const outage = await ticket();
    expect(outage.status).toBe(502);
    expect(outage.headers.get("cache-control")).toContain("no-store");
    expect(outage.headers.get("content-type")).toContain("application/problem+json");
    expect(await errorCode(outage)).toBe("comments_exchange_unavailable");
    expect(exchanges.length).toBe(exchangesBeforeOutage);
    const checkoutOutage = await checkout();
    expect(checkoutOutage.status).toBe(503);
    expect(await errorCode(checkoutOutage)).toBe("provider_error");
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
          identity: { name: "Visitor", namedByUser: false },
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
      tracker: {
        src: RUNTIME_TRACKER_SCRIPT_PATH,
        endpoint: "https://beacon.example.test",
        key: "pk_private",
      },
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

  // t.js waits for the access check too: a visitor who may not view the Space
  // gets what any other path there gives, so nothing says it has analytics on.
  // The access page names the path to come back to, and only that differs.
  const answer = async (requestPath: string, headers: Record<string, string> = {}) => {
    const response = await get(runtime, host, requestPath, { headers });
    return {
      status: response.status,
      cacheControl: response.headers.get("cache-control"),
      contentType: response.headers.get("content-type"),
      body: (await response.text()).replaceAll(requestPath, "<path>"),
    };
  };
  expect(await answer(RUNTIME_TRACKER_SCRIPT_PATH)).toEqual(await answer("/docs/app.js"));

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
  const callback = await redeemHandoff(runtime, host, token);
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
  // The admitted page's sdk.js writes the t.js tag, and t.js is the page's
  // subresource as sdk.js is, kept out of shared caches.
  expect(admittedBody).toContain(RUNTIME_TRACKER_SCRIPT_PATH);
  expect(
    await answer(RUNTIME_TRACKER_SCRIPT_PATH, {
      cookie,
      referer: `https://${host}/docs/`,
      "sec-fetch-dest": "script",
    }),
  ).toMatchObject({ status: 200, cacheControl: "private, no-store" });

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
    expect(identifiedClaims["anonymousId"]).toMatch(/^pg1_[A-Za-z0-9_-]{43}$/);

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

// The bar replaces the collab overlay and the separate t.js tag only where the
// control plane projected it (an engine with `runtime.space-bar.v1`) and only on
// a private Space. A public Space, and any projection without `bar`, keep the
// tags they have today.
test("sdk.js loads the Space bar only where the bar is projected and the Space is private", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_CAST_API_URL: "https://cast.example.test" },
  });
  runtimes.push(runtime);
  // A private Space: only the platform's own files are public, so sdk.js
  // answers while `/` stays behind the gate.
  // Comments on, so without the bar this Space would load the collab overlay.
  const accessPage = {
    displayName: "Bar",
    accountUrl: "https://api.spacefast.com/acquire/bar/account",
    connections: [],
    exchange: {
      passwordUrl: "https://api.spacefast.com/acquire/bar/password",
      tokenUrl: "https://api.spacefast.com/acquire/bar/token",
      emailUrl: "https://api.spacefast.com/acquire/bar/email",
      requestUrl: "https://api.spacefast.com/acquire/bar/request",
      logoutUrl: "https://api.spacefast.com/runtime/collaboration-sessions/revoke",
      commentsTicketUrl: "https://api.spacefast.com/runtime/comments/bar/ticket",
      credential: "runtime-comments-credential-0000000000000000000000000000",
    },
  };
  // A private Space opens only the platform's own files without a session, so
  // sdk.js answers while `/` stays behind the gate.
  const routeConfig = (visibility: "private" | "public") => {
    const config = publicAccessConfig({ mode: "website", site_title: "Bar" });
    const publicGrant = {
      id: "grt_runtime_public_live",
      generation: 1,
      name: "Public",
      audience: { kind: "public" },
      resources: {
        include: visibility === "private" ? ["/__spacefast/**"] : ["/**"],
        exclude: [],
      },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "managed", reference: "test:runtime-public" },
    };
    return {
      ...config,
      authorization: {
        generation: 1,
        sessionVersion: 0,
        fence: "none",
        acquireUrl: "https://api.spacefast.com/acquire/bar",
        spaceClaimed: true,
        accessPage,
        grants: [publicGrant],
      },
    };
  };
  const tracker = {
    src: RUNTIME_TRACKER_SCRIPT_PATH,
    endpoint: "https://beacon.example.test",
    key: "pk_bar",
    consent: "count",
  };
  const bar = {
    frame_url: "https://access.example.test/bar/spc_bar",
    dashboard_url: "https://my.example.test/spaces/spc_bar",
  };
  const activate = async (visibility: "private" | "public", withBar: boolean) => {
    const sdkConfig = {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_bar",
      comments: LOCAL_COMMENTS,
      tracker,
      bar: withBar ? bar : null,
    };
    await putRoute(runtime, "spc_bar", "production", {
      version_id: "ver_bar_1",
      config: {
        ...routeConfig(visibility),
        sdk: { revision: `sdk-bar-${visibility}-${withBar}`, config: sdkConfig },
      },
      production_hostnames: ["bar.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    });
  };
  await deploy(runtime, {
    spaceId: "spc_bar",
    versionId: "ver_bar_1",
    files: { "index.html": "<h1>Bar</h1>\n" },
    activate: {
      route_name: "production",
      config: routeConfig("private"),
      production_hostnames: ["bar.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // What this host's sdk.js boots from: one config, the same for everyone.
  const sdk = async () => {
    const response = await get(runtime, "bar.site.test", "/__spacefast/sdk.js");
    expect(response.status).toBe(200);
    return bootConfig(await response.text());
  };

  await activate("private", false);
  expect((await get(runtime, "bar.site.test", "/")).status).not.toBe(200);
  // Private, no bar projected (an old engine's projection): the tracker and
  // the Comments overlay, and no bar.
  const today = await sdk();
  expect(today?.tracker?.src).toBe(RUNTIME_TRACKER_SCRIPT_PATH);
  expect(today?.collab?.module).toBe("https://cast.example.test/sdk/v1/collab.js");
  expect(today?.bar).toBeUndefined();

  // Private, bar projected: the bar's frame and its CDN module, beside the
  // tracker and Comments. sdk.js decides in the page, from one session read,
  // whether this visitor gets the frame or the standalone Comments, so a
  // share-link or password holder keeps Comments.
  await activate("private", true);
  const withBar = await sdk();
  expect(withBar?.bar).toEqual({
    frame: bar.frame_url,
    dashboard: bar.dashboard_url,
    module: "https://cast.example.test/sdk/v1/bar.js",
  });
  expect(withBar?.tracker?.site).toBe("pk_bar");
  expect(withBar?.collab?.module).toBe("https://cast.example.test/sdk/v1/collab.js");

  // Public, bar projected but not offered on public hosts: no bar there.
  await activate("public", true);
  const open = await sdk();
  expect(open?.bar).toBeUndefined();
  expect(open?.collab?.module).toBe("https://cast.example.test/sdk/v1/collab.js");
});

const sessionResponseSchema = z.object({ data: z.object({ bar: z.boolean().optional() }) });

// A public Space whose engine offers the bar there (`public_spaces`): sdk.js
// is still one cached answer for everyone; only a browser holding the
// returning-account marker loads client.js, and the session it asks decides.
test("on a public Space the bar loads only behind the sign-in marker, and only a member gets it", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_CAST_API_URL: "https://cast.example.test" },
  });
  runtimes.push(runtime);
  const host = "open-bar.site.test";
  const keyPair = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(keyPair.publicKey);
  const publicJwk = keyPair.publicKey.export({ format: "jwk" });
  const publicGrant = {
    id: "grt_open_bar_public",
    generation: 1,
    name: "Public",
    audience: { kind: "public" },
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view", "comments.read", "comments.write"],
    constraints: {},
    target: { kind: "live" },
    source: { kind: "managed", reference: "test:open-bar-public" },
  };
  // The current projection shape: one team Grant, matched by any member's
  // `member:<membership>` authority through the projection's teamId.
  const memberGrant = {
    id: "grt_open_bar_member",
    generation: 1,
    audience: { kind: "team", teamId: "team_open_bar" },
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view", "comments.read", "comments.write"],
    constraints: {},
    target: { kind: "live" },
    source: { kind: "system", reference: "test:open-bar-member" },
  };
  const personGrant = {
    ...memberGrant,
    id: "grt_open_bar_person",
    audience: { kind: "person", personId: "per_open_bar" },
    source: { kind: "system", reference: "test:open-bar-person" },
  };
  const domainGrant = {
    ...memberGrant,
    id: "grt_open_bar_domain",
    audience: { kind: "external", issuer: "spacefast-email-domain", subject: "bakecrew.test" },
    source: { kind: "system", reference: "test:open-bar-domain" },
  };
  // A domain Grant that starts tomorrow: it admits nobody yet, so the consent
  // projected from it doesn't belong either.
  const scheduledGrant = {
    ...memberGrant,
    id: "grt_open_bar_scheduled",
    audience: { kind: "external", issuer: "spacefast-email-domain", subject: "later.test" },
    constraints: { notBefore: new Date(Date.now() + 86_400_000).toISOString() },
    source: { kind: "system", reference: "test:open-bar-scheduled" },
  };
  const linkGrant = {
    ...memberGrant,
    id: "grt_open_bar_link",
    audience: { kind: "link", linkId: "lnk_open_bar" },
    source: { kind: "system", reference: "test:open-bar-link" },
  };
  // What the Space's own sign-in produces: one consent authority per account,
  // projected by the control plane from each Grant the account reaches
  // (share-oauth-authority.ts withShareConsentGrants). A member's consent is
  // projected from the member Grant (and the public one); a stranger's only
  // from the public one.
  const consentGrant = (consent: string, from: { id: string }) => ({
    ...from,
    id: `grt_${createHash("sha256").update(`${consent}\0${from.id}`).digest("hex")}`,
    audience: { kind: "external", issuer: "spacefast-share", subject: consent },
    source: { kind: "system", reference: `share-consent:${consent}:${from.id}` },
  });
  const consentAuthority = (consent: string) =>
    `external:${createHash("sha256").update(`spacefast-share\0${consent}`).digest("hex")}`;
  const consentGrants = [
    consentGrant("cns_member", memberGrant),
    consentGrant("cns_member", publicGrant),
    consentGrant("cns_stranger", publicGrant),
    // Signed in today through the public Grant, with a scheduled Grant for
    // the same consent.
    consentGrant("cns_scheduled", publicGrant),
    consentGrant("cns_scheduled", scheduledGrant),
  ];
  const bar = {
    frame_url: "https://access.example.test/bar/spc_open_bar",
    dashboard_url: "https://my.example.test/spaces/spc_open_bar",
  };
  const activate = async (publicSpaces: boolean) => {
    const config = publicAccessConfig({ mode: "website", site_title: "Open bar" });
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
      acquireUrl: "https://api.spacefast.com/acquire/open-bar",
      spaceClaimed: true,
      teamId: "team_open_bar",
      membershipEpoch: 1,
      grants: [
        publicGrant,
        memberGrant,
        personGrant,
        domainGrant,
        scheduledGrant,
        linkGrant,
        ...consentGrants,
      ],
      // Comments on, so the overlay is what a visitor without the bar keeps.
      accessPage: {
        displayName: "Open bar",
        accountUrl: "https://api.spacefast.com/acquire/open-bar/account",
        connections: [],
        exchange: {
          passwordUrl: "https://api.spacefast.com/acquire/open-bar/password",
          tokenUrl: "https://api.spacefast.com/acquire/open-bar/token",
          emailUrl: "https://api.spacefast.com/acquire/open-bar/email",
          requestUrl: "https://api.spacefast.com/acquire/open-bar/request",
          logoutUrl: "https://api.spacefast.com/runtime/collaboration-sessions/revoke",
          commentsTicketUrl: "https://api.spacefast.com/runtime/comments/open-bar/ticket",
          credential: "runtime-comments-credential-0000000000000000000000000000",
        },
      },
    };
    config.sdk = {
      revision: `sdk-open-bar-${publicSpaces}`,
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_open_bar",
        comments: LOCAL_COMMENTS,
        // A tracker, so the page's start of it can be seen.
        tracker: {
          src: RUNTIME_TRACKER_SCRIPT_PATH,
          endpoint: "https://beacon.example.test",
          key: "pk_open_bar",
          consent: "count",
        },
        bar: publicSpaces ? { ...bar, public_spaces: true } : bar,
      },
    };
    return config;
  };
  await deploy(runtime, {
    spaceId: "spc_open_bar",
    versionId: "ver_open_bar_1",
    files: { "index.html": "<h1>Open</h1>\n" },
    activate: {
      route_name: "production",
      config: await activate(true),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // The session decides: a member gets `bar: true`; a signed-in stranger,
  // admitted by the public Grant alone, gets `bar: false`.
  const sessionFor = async (authority: string) => {
    const now = Math.floor(Date.now() / 1000);
    const token = signEd25519Jwt(keyPair.privateKey, issuer.kid, {
      sub: authority,
      purpose: "handoff",
      grants: [authority],
      authorities: [authority],
      principal: "account:usr_open_bar",
      iss: "spacefast-api",
      aud: "spc_open_bar",
      host,
      sv: 0,
      generation: 1,
      spaceId: "spc_open_bar",
      sid: createHash("sha256").update(randomUUID()).digest("hex"),
      iat: now,
      nbf: now,
      exp: now + 300,
      jti: randomUUID(),
    });
    const callback = await redeemHandoff(runtime, host, token);
    expect(callback.status).toBe(303);
    // The next visit's cookies are exactly what the handoff set: the session
    // for requests, and what the page's script can read for document.cookie.
    const setCookies = callback.headers.getSetCookie();
    const pair = (line: string) => line.split(";")[0] ?? "";
    const cookie = setCookies.map(pair).join("; ");
    const documentCookie = setCookies
      .filter((line) => !/;\s*httponly/i.test(line))
      .map(pair)
      .join("; ");
    const response = await get(runtime, host, "/__spacefast/access/session", {
      headers: { cookie },
    });
    expect(response.status, await response.clone().text()).toBe(200);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    return { body: sessionResponseSchema.parse(await response.json()), cookie, documentCookie };
  };
  const sdkFor = async (cookie?: string) => {
    const response = await get(runtime, host, "/__spacefast/sdk.js", {
      headers: cookie ? { cookie } : {},
    });
    expect(response.status).toBe(200);
    return { body: await response.text(), cacheControl: response.headers.get("cache-control") };
  };
  // A member arriving from Manage on Spacefast: the handoff signs them in and
  // leaves the returning-account marker, which is all the next page load sees.
  const member = await sessionFor("member:mem_open_bar");
  const marker = member.documentCookie;
  expect(marker).toContain("spacefast_returning_dev=1");

  // One answer for everyone: the same bytes and the public cache policy with
  // the marker, without it, and with a session; nothing in it names a visitor.
  const anonymous = await sdkFor();
  for (const cookie of [member.documentCookie, member.cookie]) {
    expect(await sdkFor(cookie)).toEqual(anonymous);
  }
  expect(anonymous.cacheControl).toContain("public");
  // The bar is offered here only behind the marker.
  expect(bootConfig(anonymous.body)?.bar?.public).toEqual({ marker: "spacefast_returning_dev=" });
  // Anyone the frame is not for gets the mark. This Space signs in through
  // the older account lane, not Sign in with Spacefast, so the mark offers no
  // Sign in of its own; it still leads to the Space on Spacefast.
  expect(bootConfig(anonymous.body)?.bar?.mark).toEqual({ links: [] });

  // The page, run: without the marker it never asks the session and loads
  // the standalone Comments; with it, a member's session gets the bar module.
  const page = (documentCookie: string, sessionCookie: string) =>
    bootSdkInPage(anonymous.body, {
      host,
      documentCookie,
      session: (path, fetchImpl) =>
        fetchImpl(`${runtime.baseUrl}${runtimeHttpPath(path)}`, {
          redirect: "manual",
          headers: { Connection: "close", Host: host, cookie: sessionCookie },
        }),
    });
  // No marker: no session read, and the mark (Comments load on its click),
  // nothing from the CDN on load.
  const withoutMarker = await page("", "");
  expect(withoutMarker.sessionReads).toBe(0);
  expect(withoutMarker.barState).toBe("anonymous_mark");
  expect(withoutMarker.added).not.toContain("https://cast.example.test/sdk/v1/collab.js");
  expect(withoutMarker.added).not.toContain("https://cast.example.test/sdk/v1/bar.js");
  const memberPage = await page(member.documentCookie, member.cookie);
  expect(memberPage.sessionReads).toBe(1);
  expect(memberPage.added).toContain("https://cast.example.test/sdk/v1/bar.js");
  expect(memberPage.added).not.toContain("https://cast.example.test/sdk/v1/collab.js");
  expect(memberPage.barState).toBe("frame_booting");

  expect(member.body.data.bar).toBe(true);
  const stranger = await sessionFor("external:grant.grt_open_bar_public");
  expect(stranger.body.data.bar).toBe(false);

  // A marker-bearing stranger: one session read, no frame, the mark.
  const strangerPage = await page(stranger.documentCookie, stranger.cookie);
  expect(strangerPage.sessionReads).toBe(1);
  expect(strangerPage.added).toEqual([`https://${host}${RUNTIME_TRACKER_SCRIPT_PATH}`]);
  expect(strangerPage.barState).toBe("anonymous_mark");
  // Signed in through the Space's own sign-in: the consent of a member
  // belongs; the consent of a stranger, projected only from the public Grant,
  // does not.
  expect((await sessionFor(consentAuthority("cns_member"))).body.data.bar).toBe(true);
  expect((await sessionFor(consentAuthority("cns_stranger"))).body.data.bar).toBe(false);
  // A public-only sign-in whose consent a scheduled Grant also names gets no
  // bar before that Grant starts, and neither does the domain it names.
  expect((await sessionFor(consentAuthority("cns_scheduled"))).body.data.bar).toBe(false);
  const laterAuthority = `external:${createHash("sha256").update("spacefast-email-domain\0later.test").digest("hex")}`;
  expect((await sessionFor(laterAuthority)).body.data.bar).toBe(false);
  // Let in by email domain: belongs, as on a private Space.
  const domainAuthority = `external:${createHash("sha256").update("spacefast-email-domain\0bakecrew.test").digest("hex")}`;
  expect((await sessionFor(domainAuthority)).body.data.bar).toBe(true);
  // Someone invited by email (shared in) belongs too, as on a private Space.
  expect((await sessionFor("person:per_open_bar")).body.data.bar).toBe(true);
  // A share link admits a visitor, not a member.
  expect((await sessionFor("link:lnk_open_bar")).body.data.bar).toBe(false);
  const anonymousSession = await get(runtime, host, "/__spacefast/access/session");
  expect(await anonymousSession.json()).toEqual({ data: { state: "anonymous" } });

  // An engine the control plane did not tell (an older control plane, or a
  // box without the capability): today's public Space, with no guard at all.
  await putRoute(runtime, "spc_open_bar", "production", {
    version_id: "ver_open_bar_1",
    config: await activate(false),
    production_hostnames: [host],
    noindex_production_hostnames: [],
    version_hostnames: [],
  });
  const off = await sdkFor();
  // That engine offers no bar on the public host, marker or not.
  expect(bootConfig(off.body)?.bar).toBeUndefined();
  expect(bootConfig(off.body)?.collab?.module).toBe("https://cast.example.test/sdk/v1/collab.js");
});

/**
 * Runs the served sdk.js in one happy-dom page, with the page's session read
 * from the runtime. Returns the script URLs it added, how many session reads
 * it made, and the bar machine's state (null with no bar on the page).
 */
async function bootSdkInPage(
  sdkBody: string,
  options: {
    host: string;
    documentCookie: string;
    session: (path: string, fetchImpl: typeof fetch) => Promise<Response>;
  },
) {
  const realFetch = globalThis.fetch;
  const view = new Window({
    url: `https://${options.host}/`,
    settings: {
      enableJavaScriptEvaluation: true,
      suppressInsecureJavaScriptEnvironmentWarning: true,
      disableJavaScriptFileLoading: true,
      handleDisabledFileLoadingAsSuccess: true,
    },
  });
  for (const pair of options.documentCookie.split("; ").filter(Boolean)) {
    view.document.cookie = pair;
  }
  let sessionReads = 0;
  Object.defineProperty(view, "fetch", {
    configurable: true,
    value: async (input: string | URL) => {
      const url = new URL(String(input), `https://${options.host}/`);
      if (url.pathname === "/__spacefast/session") sessionReads += 1;
      return options.session(url.pathname, realFetch);
    },
  });
  const scripts = () =>
    [...view.document.querySelectorAll("script")].map((script) => script.src).filter(Boolean);
  try {
    const script = view.document.createElement("script");
    script.textContent = sdkBody;
    view.document.head.appendChild(script);
    // The boot reads the session (when it asks), then adds what it decided.
    // Settled once it loaded a CDN module or drew the mark (which loads none).
    const settled = () =>
      scripts().some((src) => /\/sdk\/v1\/(?:collab|bar)\.js$/.test(src)) ||
      view.document.querySelector("spacefast-bar[data-sf-bar]") !== null;
    const deadline = Date.now() + 5_000;
    while (!settled() && Date.now() < deadline) {
      await new Promise((resolve) => view.setTimeout(resolve, 10));
    }
    if (!settled()) throw new Error("sdk.js did not boot");
    const bar = view.document.querySelector("spacefast-bar");
    return {
      added: scripts(),
      sessionReads,
      barState: bar instanceof view.HTMLElement ? (bar.dataset["sfBar"] ?? null) : null,
    };
  } finally {
    await view.happyDOM.abort();
  }
}

test("only named routes are public under the private platform namespaces", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  // `/__first` has no handler yet, so it refuses like an unknown Zero path.
  const denied = await Promise.all(
    ["/__spacefast/private.js", "/__zero/unknown", "/__first/client.js"].map(async (path) => {
      const response = await get(runtime, SITE, path);
      return { path, status: response.status, body: await response.text() };
    }),
  );
  for (const response of denied) expect(response.status, response.path).toBe(403);
  // One uniform denial, so the response never says which namespace was guessed.
  expect(new Set(denied.map((response) => response.body)).size).toBe(1);
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
  // The runtime's own answer: the platform script, booted with nothing but
  // the tag-draft reload a baked tag may still need.
  expect(bootConfig(body)).toEqual({
    reload: { preview: "spacefast_tag_preview" },
    runtime: { live: true, brand: "Spacefast" },
  });

  // The same publish's ordinary rules are untouched: the fix reserves the
  // platform's own routes, it does not stop a Space routing itself.
  const nearMiss = await get(runtime, SITE, "/__spanish/page");
  expect(nearMiss.status).toBe(200);
  expect(await nearMiss.text()).toBe("<h1>ordinary</h1>\n");

  const moved = await get(runtime, SITE, "/moved");
  expect(moved.status).toBe(301);
  expect(moved.headers.get("location")).toBe("/index.html");
});

test("sdk.js and t.js carry each module only while the plan has it on, per version and surface", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const host = "plan.site.test";
  const versionHost = "plan--v1.site.test";
  const TAG_BODY = "window.__planTagBody=1;";
  const tracker = {
    src: RUNTIME_TRACKER_SCRIPT_PATH,
    endpoint: "https://beacon.example.test",
    key: "pk_plan",
  };
  const routeBody = (plan: RouteInjectionPlan | undefined) => {
    const config = publicAccessConfig(
      { mode: "website", site_title: "Plan" },
      "live_and_all_versions",
    );
    const section = {
      revision: `sdk-plan-${JSON.stringify(plan ?? null).length}`,
      config: { tracker },
      body: TAG_BODY,
    };
    // A route without a plan is what every control plane sends today.
    config.sdk = plan ? { ...section, injection: true, plan } : section;
    return {
      version_id: "ver_plan_1",
      config,
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: versionHost, version_id: "ver_plan_1" }],
    };
  };
  await deploy(runtime, {
    spaceId: "spc_plan",
    versionId: "ver_plan_1",
    files: { "index.html": "<h1>Plan</h1>\n" },
    activate: { route_name: "production", ...routeBody(undefined) },
  });
  const sdk = async (on: string, query = "") =>
    (await get(runtime, on, `/__spacefast/sdk.js${query}`)).text();

  // A plan that keeps the tag body and the live-only tracker, and leaves the
  // review and tag-preview modules out: only what it names is written. That
  // these answers follow it at all proves the route PUT and the overlay it
  // builds kept the plan.
  await putRoute(
    runtime,
    "spc_plan",
    "production",
    routeBody({
      v: 1,
      default: { mode: "on", items: { "tags-dynamic": true, tracker: ["live"] } },
      versions: {},
    }),
  );
  const live = await sdk(host);
  expect(live).toContain(TAG_BODY);
  expect(bootConfig(live)?.tracker?.site).toBe("pk_plan");
  expect(bootConfig(live)?.reload).toBeUndefined();
  expect(bootConfig(await sdk(host, "?preview=tok_plan"))?.scripts).toBeUndefined();
  // The tracker is live-only: a version host loads the tag body but no tracker.
  const version = await sdk(versionHost);
  expect(version).toContain(TAG_BODY);
  expect(bootConfig(version)?.tracker).toBeUndefined();

  // The served version's own entry wins over the default, on every host.
  await putRoute(
    runtime,
    "spc_plan",
    "production",
    routeBody({
      v: 1,
      default: { mode: "on", items: { "tags-dynamic": true, tracker: ["live"], review: true } },
      versions: { ver_plan_1: { mode: "off", items: {} } },
    }),
  );
  for (const on of [host, versionHost]) {
    const response = await get(runtime, on, "/__spacefast/sdk.js");
    expect(response.status).toBe(200);
    expect(response.headers.get("content-type")).toBe("application/javascript; charset=utf-8");
    expect(await response.text()).toBe("/* spacefast: injection off */\n");
  }
  // t.js answers with the empty script rather than Beacon's, without asking
  // Beacon (its endpoint here does not resolve).
  const trackerScript = await get(runtime, host, RUNTIME_TRACKER_SCRIPT_PATH);
  expect(trackerScript.status).toBe(200);
  expect(await trackerScript.text()).toBe("/* No Spacefast analytics here. */\n");

  // A control plane that sends no plan keeps today's answers.
  await putRoute(runtime, "spc_plan", "production", routeBody(undefined));
  const legacy = await sdk(host);
  expect(legacy).toContain(TAG_BODY);
  expect(bootConfig(legacy)?.tracker?.site).toBe("pk_plan");
  // The live host offers only the tag-draft reload; a visual review only
  // ever opens a version host, which offers both.
  expect(bootConfig(legacy)?.reload).toEqual({ preview: "spacefast_tag_preview" });
  expect(bootConfig(await sdk(versionHost))?.reload).toEqual({
    preview: "spacefast_tag_preview",
    review: true,
  });
});

// A public live Space with nothing on: no Comments, no bar, no Remix, no
// analytics, no tags. Its pages carry no Spacefast script at all.
test("a default public live Space gets 0 bytes of Spacefast JavaScript", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const host = "zero-bytes.site.test";
  const versionHost = "zero-bytes--v1.site.test";
  const config = publicAccessConfig(
    { mode: "website", site_title: "Zero" },
    "live_and_all_versions",
  );
  config.sdk = { revision: "sdk-zero", config: {} };
  await deploy(runtime, {
    spaceId: "spc_zero_bytes",
    versionId: "ver_zero_bytes_1",
    // A page the compiler could not decorate, so only the engine could add a tag.
    files: { "index.html": "<html><head><title>Zero</title></head><h1>Zero</h1></html>\n" },
    serving: {
      config: {
        inject: {
          bodyEnd: ['<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>'],
        },
      },
    },
    activate: {
      route_name: "production",
      config,
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: versionHost, version_id: "ver_zero_bytes_1" }],
    },
  });
  const page = await (await get(runtime, host, "/")).text();
  expect(page).toContain("<h1>Zero</h1>");
  expect(page).not.toContain("<script");
  // A page that still carries its own baked tag keeps its tag-draft reload,
  // and nothing more.
  const sdk = await get(runtime, host, "/__spacefast/sdk.js");
  expect(bootConfig(await sdk.text())).toEqual({
    reload: { preview: "spacefast_tag_preview" },
    runtime: { live: true, brand: "Spacefast" },
  });
  // A visual review only ever opens a version host, and is known only in the
  // browser: that page gets the tag, so the review layer can still load.
  const versionPage = await (await get(runtime, versionHost, "/")).text();
  expect(versionPage).toContain('src="/__spacefast/sdk.js"');
  expect(
    bootConfig(await (await get(runtime, versionHost, "/__spacefast/sdk.js")).text())?.reload,
  ).toEqual({ preview: "spacefast_tag_preview", review: true });

  // Turn analytics on and the same page gets the one tag.
  config.sdk = {
    revision: "sdk-zero-tracker",
    config: {
      tracker: {
        src: RUNTIME_TRACKER_SCRIPT_PATH,
        endpoint: "https://beacon.example.test",
        key: "pk_zero",
      },
    },
  };
  await putRoute(runtime, "spc_zero_bytes", "production", {
    version_id: "ver_zero_bytes_1",
    config,
    production_hostnames: [host],
    noindex_production_hostnames: [],
    version_hostnames: [{ hostname: versionHost, version_id: "ver_zero_bytes_1" }],
  });
  const tracked = await (await get(runtime, host, "/")).text();
  expect(tracked).toContain('src="/__spacefast/sdk.js"');
  expect(
    bootConfig(await (await get(runtime, host, "/__spacefast/sdk.js")).text())?.tracker?.site,
  ).toBe("pk_zero");
});

test("Comments boots only while the plan has it on", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const host = "plan-comments.site.test";
  // Everything Comments needs to be available, so the plan is the only thing
  // that decides: a ticket URL, and a Cast base a visitor can reach.
  const accessPage = {
    displayName: "Plan comments",
    accountUrl: null,
    connections: [],
    // A usable exchange needs its password and request lanes too.
    exchange: {
      passwordUrl: "https://api.spacefast.com/acquire/plan-comments/password",
      requestUrl: "https://api.spacefast.com/acquire/plan-comments/request",
      commentsTicketUrl: "https://api.spacefast.com/runtime/comments/plan-comments/ticket",
      credential: "runtime-comments-credential-0000000000000000000000000000",
    },
  };
  const routeBody = (items: Record<string, boolean>) => {
    const { authorization, ...config } = publicAccessConfig({}, "live_and_all_versions");
    const plan: RouteInjectionPlan = { v: 1, default: { mode: "on", items }, versions: {} };
    const sdk = {
      revision: `sdk-plan-comments-${Object.keys(items).length}`,
      config: {
        cast_api_base: "https://cast.example.test",
        cast_ws_url: "wss://cast.example.test/socket/websocket",
        cast_resource_key: "resource_plan",
        comments: LOCAL_COMMENTS,
      },
      injection: true,
      plan,
    };
    return {
      version_id: "ver_plan_comments_1",
      config: { ...config, authorization: Object.assign({}, authorization, { accessPage }), sdk },
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    };
  };
  await deploy(runtime, {
    spaceId: "spc_plan_comments",
    versionId: "ver_plan_comments_1",
    files: { "index.html": "<h1>Comments</h1>\n" },
    activate: { route_name: "production", ...routeBody({ comments: true }) },
  });
  const comments = async () => {
    const sdk = await (await get(runtime, host, "/__spacefast/sdk.js")).text();
    const manifest = collabManifestSchema.parse(
      await (await get(runtime, host, RUNTIME_COLLAB_MANIFEST_PATH)).json(),
    );
    return {
      loader: sdk.includes("https://cast.example.test/sdk/v1/collab.js"),
      cast: manifest.cast,
      ticketUrl: manifest.ticketUrl,
    };
  };

  expect(await comments()).toEqual({
    loader: true,
    cast: { wsUrl: "wss://cast.example.test/socket/websocket", resourceKey: "resource_plan" },
    ticketUrl: `http://${host}/__spacefast/comments/ticket`,
  });

  // The same route with `comments` left out of the plan: no loader in sdk.js,
  // and a manifest that names nowhere to join.
  await putRoute(runtime, "spc_plan_comments", "production", routeBody({ "tags-dynamic": true }));
  expect(await comments()).toEqual({ loader: false, cast: null, ticketUrl: null });
});

test("a pinned mount's page follows its own version, while sdk.js and t.js follow the host's", async () => {
  // No control plane compiles a sub-path version mount today (version targets
  // are whole-host routes), so this pins the behavior for a hand-written shard
  // rather than a reachable state. sdk.js and t.js are absolute paths outside
  // the mount, so they answer for the version the host serves; the mounted
  // page answers for its own. A page baked while its version was on keeps its
  // tag either way, since the tag names no version.
  const runtime = await startRuntime();
  runtimes.push(runtime);
  const host = "pinned-mount.site.test";
  const hostVersion = "ver_pinned_host";
  const pinnedVersion = "ver_pinned_mount";
  const BAKED_SDK = '<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  const config = publicAccessConfig({ mode: "website" }, "live_and_all_versions");
  config.sdk = {
    revision: "sdk-pinned-1",
    config: {
      tracker: {
        src: RUNTIME_TRACKER_SCRIPT_PATH,
        endpoint: "https://beacon.example.test",
        key: "pk_pinned",
      },
    },
    body: "window.__pinnedTagBody=1;",
  };
  const page = (versionId: string) => ({
    "index.html": `<html><head><title>${versionId}</title></head><body>${versionId}</body></html>`,
  });
  // The pinned version was published with injection on; the host's own
  // version turned it off in its sf.jsonc.
  await deploy(runtime, {
    spaceId: "spc_pinned",
    versionId: pinnedVersion,
    files: page(pinnedVersion),
    serving: { config: { inject: { bodyEnd: [BAKED_SDK] } } },
  });
  await deploy(runtime, {
    spaceId: "spc_pinned",
    versionId: hostVersion,
    files: page(hostVersion),
    serving: { config: { inject: {}, platform_injection: false } },
    activate: {
      route_name: "production",
      version_id: hostVersion,
      config,
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  prependHostRoutes(runtime, host, [
    {
      location: "/pinned",
      route_action: { action: "serve", version_id: pinnedVersion, target_prefix: "/" },
    },
  ]);

  const mounted = await (await get(runtime, host, "/pinned/")).text();
  expect(mounted).toContain(pinnedVersion);
  expect(mounted).toContain('data-spacefast-sdk="v1"');
  const root = await (await get(runtime, host, "/")).text();
  expect(root).toContain(hostVersion);
  expect(root).not.toContain("data-spacefast-sdk");

  expect(await (await get(runtime, host, "/__spacefast/sdk.js")).text()).toBe(
    "/* spacefast: injection off */\n",
  );
  expect(await (await get(runtime, host, RUNTIME_TRACKER_SCRIPT_PATH)).text()).toBe(
    "/* No Spacefast analytics here. */\n",
  );
});
