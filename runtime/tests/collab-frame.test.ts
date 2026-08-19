// `/__/collab` — the Collab frame shell route (collab-frame-plan §4).
//
// Everything here is proven through requests: the route is reserved from
// tenants, it 404s where the Comments lane is off, its headers refuse
// cross-origin framing and any store, and `?path=` admits only a same-origin
// page path. The document it emits carries a JSON manifest, so the path verdict
// is readable in the response instead of inferred.

import { afterAll, expect, test } from "bun:test";

import {
  deploy,
  errorCode,
  get,
  publicAccessConfig,
  startRuntime,
  type Runtime,
} from "./harness.ts";

const OPEN_SITE = "frame.site.test";
const QUIET_SITE = "frame-off.site.test";
const ROOM_SITE = "frame-room.site.test";

const COMMENTS_ON = {
  live: true,
  preview: true,
  live_url: "https://frame.site.test/",
  theme: { accent: "#22c55e", hide_branding: false },
  features: { picker: true, drawing: true, capture: false, attachments: true, notices: true },
};

let runtimes: Runtime[] = [];
let started: Promise<Runtime> | null = null;

afterAll(() => {
  for (const runtime of runtimes) runtime.stop();
});

/** The boot-neutral manifest the shell reads, out of the served document. */
function frameManifest(html: string): Record<string, unknown> {
  const match = /<script type="application\/json" id="sf-frame">(.*?)<\/script>/s.exec(html);
  if (match?.[1] === undefined) throw new Error(`no frame manifest in: ${html.slice(0, 400)}`);
  return JSON.parse(match[1]) as Record<string, unknown>;
}

/** The manifest the SDK loader installs, by running the served bytes. */
async function sdkManifest(runtime: Runtime, host: string): Promise<Record<string, unknown>> {
  const response = await get(runtime, host, "/__spacefast/sdk.js");
  expect(response.status).toBe(200);
  const scope: { Spacefast?: { manifest?: Record<string, unknown> } } = {};
  Function(
    "window",
    "document",
    await response.text(),
  )(scope, {
    createElement: () => ({ dataset: {}, style: {} }),
    head: { appendChild: () => undefined },
    body: { appendChild: () => undefined },
  });
  const manifest = scope.Spacefast?.manifest;
  if (!manifest) throw new Error(`the SDK response for ${host} installed no manifest`);
  return manifest;
}

const ATTACKER_ORIGIN = "https://attacker.example";

/** Two Spaces on one runtime: Comments on the live surface, and Comments off. */
async function frameRuntime(): Promise<Runtime> {
  started ??= (async () => {
    const runtime = await startRuntime({
      atomicData: { SPACEFAST_CAST_API_URL: "https://cast.example.test" },
    });
    runtimes.push(runtime);

    const open = publicAccessConfig({ mode: "website", site_title: "Frame" });
    const authorization = open.authorization as Record<string, unknown>;
    authorization.accessPage = { displayName: "Acme" };
    open.sdk = {
      revision: "frame-1",
      config: { cast_api_base: "https://cast.example.test", comments: COMMENTS_ON },
    };
    await deploy(runtime, {
      spaceId: "spc_frame",
      versionId: "ver_frame_1",
      files: {
        "index.html": "<h1>Home</h1>\n",
        "pricing/index.html": "<h1>Pricing</h1>\n",
        // A publisher trying to own the review link. The route is reserved, so
        // these bytes can never be what `/__/collab` answers.
        "__/collab": "PUBLISHED IMPOSTOR\n",
        // ...and the same attempt as a routing RULE. A redirect terminates
        // inside the rules stage, which serve.php runs BEFORE its reserved
        // dispatch ladder, so the file-shadow defence above never gets a turn.
        _redirects: [
          `/__/collab ${ATTACKER_ORIGIN}/ 302`,
          // Reservation is whole-segment, so this near miss is ordinary tenant
          // content and its rule must keep working.
          "/__spanish/page /pricing/ 302",
        ].join("\n"),
        // Published, but this Space compiled no room from it (no `pages.collab`
        // pointer), so the template source is just another private page source.
        "_pages/collab.html": "TEMPLATE SOURCE\n",
      },
      activate: {
        route_name: "production",
        config: open,
        production_hostnames: [OPEN_SITE],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const quiet = publicAccessConfig({ mode: "website", site_title: "Quiet" });
    quiet.sdk = {
      revision: "frame-2",
      config: {
        cast_api_base: "https://cast.example.test",
        comments: { ...COMMENTS_ON, live: false },
      },
    };
    await deploy(runtime, {
      spaceId: "spc_frame_off",
      versionId: "ver_frame_off_1",
      files: { "index.html": "<h1>Quiet</h1>\n" },
      activate: {
        route_name: "production",
        config: quiet,
        production_hostnames: [QUIET_SITE],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const room = publicAccessConfig({ mode: "website", site_title: "Room" });
    room.sdk = {
      revision: "frame-3",
      config: { cast_api_base: "https://cast.example.test", comments: COMMENTS_ON },
    };
    await deploy(runtime, {
      spaceId: "spc_frame_room",
      versionId: "ver_frame_room_1",
      files: {
        "index.html": "<h1>Room</h1>\n",
        "_pages/collab.html": "TEMPLATE SOURCE\n",
      },
      pageArtifacts: { "page-collab": "<!doctype html><html><body>COMPILED ROOM</body></html>" },
      serving: { config: { pages: { routes: {}, previews: {}, collab: "page-collab" } } },
      activate: {
        route_name: "production",
        config: room,
        production_hostnames: [ROOM_SITE],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    return runtime;
  })();
  return started;
}

test("a published review room answers at its URL while its template source stays private", async () => {
  const runtime = await frameRuntime();

  const room = await get(runtime, ROOM_SITE, "/_pages/collab.html");
  expect(room.status).toBe(200);
  expect(room.headers.get("content-type")).toBe("text/html; charset=utf-8");
  const html = await room.text();
  expect(html).toContain("COMPILED ROOM");
  expect(html).not.toContain("TEMPLATE SOURCE");

  // No compiled room means no room: the same path is an ordinary private page
  // source, indistinguishable from a genuine miss.
  const missing = await get(runtime, OPEN_SITE, "/_pages/collab.html");
  expect(missing.status).toBe(404);
  expect(await missing.text()).not.toContain("TEMPLATE SOURCE");

  // A tokened request is private like on any other URL: the room still
  // answers, and the response is pinned out of every shared cache.
  const tokened = await get(
    runtime,
    ROOM_SITE,
    "/_pages/collab.html?__=sfl_room-token_0123456789",
    {
      headers: { Accept: "text/html" },
    },
  );
  expect(tokened.status).toBe(200);
  expect(await tokened.text()).toContain("COMPILED ROOM");
  expect(tokened.headers.get("cache-control")).toBe("private, no-store");
  expect(tokened.headers.get("a8c-edge-cache")).toBe("no-cache");

  // ...and the same pointer is what sends the orb there. The browser never
  // learns a Space can have a room of its own: the runtime resolves the expand
  // target and the manifest carries the answer, whichever way it went.
  expect([
    (await sdkManifest(runtime, ROOM_SITE)).layout,
    (await sdkManifest(runtime, OPEN_SITE)).layout,
  ]).toEqual(["/_pages/collab.html", "/__/collab"]);
});

test("the frame shell is reserved from tenants and ships unframeable, unstorable", async () => {
  const runtime = await frameRuntime();

  const response = await get(runtime, OPEN_SITE, "/__/collab?path=/pricing");
  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toBe("text/html; charset=utf-8");
  // R-5: the chrome that hosts capability-gated actions is never frameable
  // cross-origin. R-20: its manifest varies by viewer, so nothing stores it.
  expect(response.headers.get("content-security-policy")).toBe("frame-ancestors 'self'");
  expect(response.headers.get("cache-control")).toBe("no-store");

  const html = await response.text();
  expect(html).not.toContain("PUBLISHED IMPOSTOR");
  expect(html).toContain('src="https://cast.example.test/sdk/v1/frame.js"');
  expect(frameManifest(html)).toEqual({
    v: 1,
    path: "/pricing",
    space: { name: "Acme" },
    theme: { accent: "#22c55e", hide_branding: false },
  });

  // ...and the published file at that path is unreachable: the shell answers.
  const impostor = await get(runtime, OPEN_SITE, "/__/collab");
  expect(impostor.status).toBe(200);
  expect(await impostor.text()).not.toContain("PUBLISHED IMPOSTOR");
  // The reviewer opened a platform-minted link on a Spacefast-issued hostname,
  // so a tenant redirect rule must not be able to bounce them off it either.
  expect(impostor.headers.get("location")).toBeNull();

  // The near miss stays a tenant route: reserving the review link is not
  // reserving the shape of it.
  const nearMiss = await get(runtime, OPEN_SITE, "/__spanish/page");
  expect(nearMiss.status).toBe(302);
  expect(nearMiss.headers.get("location")).toBe("/pricing/");
});

test("the frame is a Comments surface, not a bypass: lane off, no shell", async () => {
  const runtime = await frameRuntime();

  const response = await get(runtime, QUIET_SITE, "/__/collab?path=/");
  expect(response.status).toBe(404);
  expect(response.headers.get("content-type")).toContain("application/problem+json");
  expect(await errorCode(response)).toBe("collab_frame_not_found");
});

test("?path= admits only a same-origin page path", async () => {
  const runtime = await frameRuntime();

  // Null is the shell's quiet not-found state: the document still renders, and
  // a rejected path never reaches an iframe src.
  const cases: [string, string | null][] = [
    ["/pricing", "/pricing"],
    ["/./pricing/../pricing", "/pricing"],
    ["//evil.com", null],
    ["https://evil.com/x", null],
    ["/../secret", null],
    [String.raw`/a\b`, null],
  ];
  for (const [asked, expected] of cases) {
    const response = await get(runtime, OPEN_SITE, `/__/collab?path=${encodeURIComponent(asked)}`);
    expect(response.status).toBe(200);
    expect([asked, frameManifest(await response.text())["path"]]).toEqual([asked, expected]);
  }

  // No page asked for is the Space's root, not a refusal.
  const bare = await get(runtime, OPEN_SITE, "/__/collab");
  expect(frameManifest(await bare.text())["path"]).toBe("/");
});
