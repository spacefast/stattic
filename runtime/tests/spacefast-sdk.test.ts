import { afterAll, expect, test } from "bun:test";

import { deploy, get, startRuntime, type Runtime } from "./harness.ts";

const SITE = "sdk.site.test";

let runtimes: Runtime[] = [];

afterAll(() => {
  for (const runtime of runtimes) runtime.stop();
});

test("same-host Spacefast SDK route boots public tags without a collaboration resource", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_CAST_API_URL: "https://cast.example.test" },
  });
  runtimes.push(runtime);

  await deploy(runtime, {
    spaceId: "spc_sdk",
    versionId: "ver_sdk_1",
    files: { "index.html": '<h1>SDK</h1><script src="/__spacefast/sdk.js"></script>\n' },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "SDK" },
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(runtime, SITE, "/__spacefast/sdk.js");
  expect(response.status).toBe(200);
  const body = await response.text();
  expect(body).toContain("window.Spacefast=window.Spacefast||{}");
  expect(body).toContain('"apiBase":"https://api.spacefast.com"');
  expect(body).toContain('"apiBase":"https://cast.example.test"');
  expect(body).toContain('"resourceKey":null');
  const appended: Array<{ async: boolean; dataset: Record<string, string>; src: string }> = [];
  const fakeWindow: Record<string, unknown> = {};
  const fakeDocument = {
    createElement: () => ({ async: false, dataset: {}, src: "" }),
    head: {
      appendChild: (script: (typeof appended)[number]) => appended.push(script),
    },
  };
  Function("window", "document", body)(fakeWindow, fakeDocument);
  expect(appended).toEqual([
    {
      async: true,
      dataset: { spacefastSdk: "v1" },
      src: `https://api.spacefast.com/v1/spaces/spc_sdk/tags/sdk.js?host=${SITE}`,
    },
  ]);
  expect(response.headers.get("content-type")).toContain("application/javascript");
  expect(response.headers.get("access-control-allow-origin")).toBe("*");
  expect(response.headers.get("cross-origin-resource-policy")).toBe("cross-origin");
  expect(response.headers.get("timing-allow-origin")).toBe("*");
  expect(response.headers.get("etag")).toContain("runtime:");
  expect(response.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );
  expect(response.headers.get("x-spacefast-sdk-revision")).toContain("runtime:");

  const preview = await get(runtime, SITE, "/__spacefast/sdk.js?preview=preview-token", {
    headers: {
      "if-none-match": response.headers.get("etag") ?? "",
    },
  });
  expect(preview.status).toBe(200);
  expect(preview.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );
  expect(await preview.text()).toContain('"environment":"preview"');

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
    "public, max-age=0, s-maxage=60, must-revalidate",
  );
  expect(previewPage.headers.get("cdn-cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );
  expect(previewPage.headers.get("surrogate-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );
  expect(previewPage.headers.get("set-cookie") ?? "").not.toContain("spacefast_tag_preview");
  expect(await previewPage.text()).toContain("/__spacefast/sdk.js?preview=preview-token");

  const cached = await get(runtime, SITE, "/__spacefast/sdk.js", {
    headers: { "if-none-match": response.headers.get("etag") ?? "" },
  });
  expect(cached.status).toBe(304);
  expect(await cached.text()).toBe("");
});

test("same-host Spacefast SDK route boots tags and the native collaboration SDK", async () => {
  const runtime = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: "" },
  });
  runtimes.push(runtime);

  await deploy(runtime, {
    spaceId: "spc_sdk_local",
    versionId: "ver_sdk_local_1",
    files: { "index.html": '<h1>Local SDK</h1><script src="/__spacefast/sdk.js"></script>\n' },
    serving: {
      config: {
        spacefast_sdk: {
          cast_api_base: "https://cast.example.test",
          cast_ws_url: "wss://cast.example.test/socket/websocket",
          cast_resource_key: "resource_local",
        },
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Local SDK" },
      production_hostnames: ["local-sdk.site.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  const response = await get(runtime, "local-sdk.site.test", "/__spacefast/sdk.js");
  expect(response.status).toBe(200);
  const body = await response.text();
  const appended: Array<{
    async: boolean;
    dataset: Record<string, string>;
    onerror?: () => void;
    src: string;
    type: string;
  }> = [];
  const collabErrors: Array<{ detail: unknown; type: string }> = [];
  const consoleErrors: unknown[][] = [];
  const fakeWindow: {
    dispatchEvent: (event: Event) => boolean;
    Spacefast?: {
      collabLoader?: unknown;
      manifest?: {
        apiBase?: string;
        cast?: { apiBase?: string; resourceKey?: string; wsPath?: string };
      };
    };
  } = {
    dispatchEvent: (event) => {
      collabErrors.push({
        type: event.type,
        detail: event instanceof CustomEvent ? event.detail : null,
      });
      return true;
    },
  };
  const fakeDocument = {
    createElement: () => ({ async: false, dataset: {}, src: "", type: "" }),
    head: {
      appendChild: (script: (typeof appended)[number]) => {
        appended.push(script);
      },
    },
  };

  Function(
    "window",
    "document",
    "console",
    body,
  )(fakeWindow, fakeDocument, {
    error: (...args: unknown[]) => consoleErrors.push(args),
  });

  expect(fakeWindow.Spacefast?.manifest).toEqual({
    version: 3,
    environment: "production",
    host: "local-sdk.site.test",
    spaceId: "spc_sdk_local",
    versionId: "ver_sdk_local_1",
    apiBase: "https://api.example.test",
    cast: {
      apiBase: "https://cast.example.test",
      wsPath: "wss://cast.example.test/socket/websocket",
      resourceKey: "resource_local",
    },
  });
  expect(appended).toHaveLength(2);
  expect(appended[0]).toEqual({
    async: true,
    dataset: { spacefastSdk: "v1" },
    src: "https://api.example.test/v1/spaces/spc_sdk_local/tags/sdk.js?host=local-sdk.site.test",
    type: "",
  });
  expect(appended[1]).toEqual({
    async: true,
    dataset: {},
    onerror: expect.any(Function),
    src: "https://cast.example.test/sdk/v1/collab.js",
    type: "module",
  });
  appended[1]?.onerror?.();
  expect(collabErrors).toEqual([
    {
      type: "spacefast:collab-error",
      detail: { stage: "module", message: "Spacefast Comments module failed to load" },
    },
  ]);
  expect(consoleErrors).toHaveLength(1);
  expect(consoleErrors[0]?.[0]).toBeInstanceOf(Error);
  expect(response.headers.get("access-control-allow-origin")).toBe("*");
});

test("only the SDK file is public under the Spacefast namespace", async () => {
  const runtime = await startRuntime();
  runtimes.push(runtime);

  const response = await get(runtime, SITE, "/__spacefast/private.js");
  expect(response.status).toBe(403);
});
