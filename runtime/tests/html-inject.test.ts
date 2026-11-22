import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import type { RouteInjectionPlan } from "../../packages/common/src/contracts/injection.ts";
import { RUNTIME_TRACKER_SCRIPT_PATH } from "../../packages/common/src/utils/runtime-paths.ts";
import {
  blobPath,
  deploy,
  get,
  healthServing,
  PHP_BINARY,
  putRoute,
  publicAccessConfig,
  readBlob,
  RESPONSES,
  responseEntry,
  selfSignedCertificate,
  sha256,
  startRuntime,
  versionRoot,
  versionRootPointer,
  type Runtime,
} from "./harness.ts";

const HOST = "html-inject.test";
// The real binary a fake Zero 0.8 runner hands every non-invoke command to.
const RUNTIME_BIN = path.resolve(
  process.env.SPACEFAST_RUNTIME_BIN ??
    path.join(import.meta.dir, "../../target/debug/stattic-runtime"),
);
const META_HOST = "platform-meta.test";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
}, 30000);

afterAll(() => rt?.stop());

test("declared icons select exact version bytes through runtime-safe URLs across promotion and rollback", async () => {
  const spaceId = "spc_declared_icons";
  const live = "icons.test";
  const hosts = ["icons--a.test", "icons--b.test"];
  const route = (version: string) => ({
    version_id: version,
    config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
    production_hostnames: [live],
    noindex_production_hostnames: [],
    version_hostnames: hosts.map((hostname, i) => ({
      hostname,
      version_id: `ver_icons_${i === 0 ? "a" : "b"}`,
    })),
  });
  const paths = [
    "favicon.ico",
    "apple-touch-icon.png",
    "apple-touch-icon-180x180.png",
    "apple-touch-icon-precomposed.png",
  ];
  for (const version of ["a", "b"]) {
    await deploy(rt, {
      spaceId,
      versionId: `ver_icons_${version}`,
      files: {
        "index.html": "<html><head></head><body>Implicit</body></html>",
        "docs/index.html":
          '<html><head><link rel="shortcut icon" href="../favicon.ico?x=1&amp;y=2#tab"><link rel="apple-touch-icon" href="../apple-touch-icon.png"><link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png"><link rel="apple-touch-icon-precomposed" href="/apple-touch-icon-precomposed.png"><link rel="icon" href="/other.png"><link rel="icon" href="https://cdn.example.test/favicon.ico"><link rel="icon" href="data:image/png;base64,AA=="></head><body>Explicit</body></html>',
        "other.png": { content: `other-${version}`, contentType: "image/png" },
        ...Object.fromEntries(
          paths.map((icon) => [
            icon,
            {
              content: `${icon}-${version}\n`,
              contentType: icon.endsWith(".ico") ? "image/x-icon" : "image/png",
            },
          ]),
        ),
      },
      activate: { route_name: "production", ...route(`ver_icons_${version}`) },
    });
  }
  // B is live, A is immutable; promotion/rollback changes only the live answer.
  for (const version of ["b", "a", "b"]) {
    await putRoute(rt, spaceId, "production", route(`ver_icons_${version}`));
    for (const [host, expected] of [
      [live, version],
      [hosts[0], "a"],
      [hosts[1], "b"],
    ]) {
      expect(await healthServing(rt, host, `ver_icons_${expected}`)).toMatchObject({
        version_match: true,
      });
      const implicit = await (await get(rt, host, "/")).text();
      const declared = implicit.match(/<link rel="icon" href="([^"]+)"/);
      if (!declared) throw new Error("missing compiled implicit icon declaration");
      expect(declared[1]).toBe(
        `/favicon.ico;sf-icon?v=${sha256(`favicon.ico-${expected}\n`).slice(0, 12)}`,
      );
      const explicit = await (await get(rt, host, "/docs/")).text();
      expect(explicit).toContain('href="/favicon.ico;sf-icon?x=1&amp;y=2#tab"');
      expect(explicit).toContain('href="/other.png"');
      expect(explicit).toContain('href="https://cdn.example.test/favicon.ico"');
      expect(explicit).toContain('href="data:image/png;base64,AA=="');
      for (const icon of paths) {
        const url = icon === "favicon.ico" ? declared[1] : `/${icon};sf-icon`;
        if (icon !== "favicon.ico") expect(explicit).toContain(`href="${url}"`);
        const response = await get(rt, host, url);
        expect(response.status).toBe(200);
        expect(await response.text()).toBe(`${icon}-${expected}\n`);
        // The accel fallback owns its validator format; the alias retains it.
        expect(response.headers.get("etag")).toMatch(/^"[a-f0-9-]+"$/);
        expect(response.headers.get("content-type")).toBe(
          icon.endsWith(".ico") ? "image/x-icon" : "image/png",
        );
        expect(response.headers.get("content-length")).toBe(
          String(Buffer.byteLength(`${icon}-${expected}\n`)),
        );
        expect(response.headers.get("x-content-type-options")).toBe("nosniff");
        const repeated = await get(rt, host, url);
        expect(await repeated.text()).toBe(`${icon}-${expected}\n`);
        const head = await get(rt, host, url, { method: "HEAD" });
        expect(head.status).toBe(200);
        expect(await head.text()).toBe("");
        for (const header of [
          "etag",
          "content-type",
          "content-length",
          "cache-control",
          "x-content-type-options",
        ]) {
          expect(head.headers.get(header)).toBe(response.headers.get(header));
          expect(repeated.headers.get(header)).toBe(response.headers.get(header));
        }
      }
      expect(await (await get(rt, host, "/other.png")).text()).toBe(`other-${expected}`);
    }
  }
  // Reserved suffix syntax must not become a general file alias.
  for (const invalid of [
    "/other.png;sf-icon",
    "/docs/favicon.ico;sf-icon",
    "/favicon.ico;sf-icon/extra",
    "/favicon.ico;sf-icon;sf-icon",
    "/apple-touch-icon-a;b.png;sf-icon",
  ]) {
    const response = await get(rt, live, invalid);
    expect(response.status).toBe(404);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  }
});

test("runtime splices static inject placements and the one SDK tag into served HTML", async () => {
  const route = (versionId: string) => ({
    version_id: versionId,
    // Analytics on, so its pages have something for sdk.js to do; a page with
    // nothing on gets no tag (spacefast-sdk.test.ts, 0 bytes).
    config: {
      ...publicAccessConfig({ mode: "website" }),
      sdk: {
        revision: "sdk-1",
        config: {
          tracker: {
            src: RUNTIME_TRACKER_SCRIPT_PATH,
            endpoint: "https://beacon.example.test",
            key: "pk_html",
          },
        },
      },
    },
    production_hostnames: [HOST],
    noindex_production_hostnames: [],
    version_hostnames: [],
  });
  // What finalize bakes into every version with platform injection on.
  const baked = '<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  const engineTag = '<script defer src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  await deploy(rt, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_1",
    files: {
      "index.html":
        '<!doctype html><html><head><meta charset="utf-8"></head><body class="home"><h1>Home</h1></body></html>\n',
      // No body element, so the compiler cannot bake the tag into it, and the
      // marker only in a comment and another element's attribute.
      "bare.html":
        '<!doctype html><html><head><!-- <script data-spacefast-sdk="v1"></script> --><title>Bare</title></head><div data-spacefast-sdk="v1">Bare</div></html>\n',
      // Undecorated too, with no head or html element to take the tag.
      "fragment.html": "<main>Hello</main>\n",
      // The author's own share image remains in the published metadata.
      "shared.html":
        '<html><head><meta property="og:image" content="/share.png"></head><body>Shared</body></html>\n',
      "share.png": {
        content: new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x2a]),
        contentType: "image/png",
      },
    },
    serving: {
      config: {
        index: "index.html",
        listing: false,
        viewer: false,
        fallback: null,
        inject: {
          head: ['<meta name="spacefast-head" content="ok">'],
          bodyStart: ['<div id="spacefast-body-start"></div>'],
          bodyEnd: ["<script>window.spacefastBodyEnd = true;</script>", baked],
          noscript: ['<noscript><iframe src="https://example.test/ns"></iframe></noscript>'],
        },
      },
    },
    activate: { route_name: "production", ...route("ver_html_inject_1") },
  });

  const response = await get(rt, HOST, "/");
  expect(response.status).toBe(200);
  const html = await response.text();

  expect(html).toContain('<meta name="spacefast-head" content="ok">');
  expect(html).toContain("<noscript>");
  expect(html).toContain('id="spacefast-body-start"');
  expect(html).toContain("window.spacefastBodyEnd = true");

  // The baked tag is the page's one SDK tag; the runtime adds none beside it.
  expect(html.split("data-spacefast-sdk").length - 1).toBe(1);
  expect(html).toContain(baked);
  expect(html).toContain('<meta charset="utf-8">');
  expect(response.headers.get("content-length")).toBe(String(Buffer.byteLength(html)));
  // Every replica derives the same validator, so revalidation and ranges hold.
  const etag = response.headers.get("etag");
  expect(etag).toMatch(/^"[a-f0-9]{64}"$/);
  const repeated = await get(rt, HOST, "/");
  expect(repeated.headers.get("etag")).toBe(etag);
  expect(await repeated.text()).toBe(html);
  expect((await get(rt, HOST, "/", { headers: { "if-none-match": etag ?? "" } })).status).toBe(304);
  const range = await get(rt, HOST, "/", { headers: { range: "bytes=0-79" } });
  expect(range.status).toBe(206);
  expect(await range.text()).toBe(html.slice(0, 80));
  const head = await get(rt, HOST, "/", { method: "HEAD" });
  expect(head.headers.get("content-length")).toBe(response.headers.get("content-length"));
  expect(head.headers.get("etag")).toBe(etag);

  const bodyOpen = html.indexOf('<body class="home">');
  const noscript = html.indexOf("<!-- spacefast:noscript -->");
  const bodyStart = html.indexOf("<!-- spacefast:body-start -->");
  const heading = html.indexOf("<h1>Home</h1>");
  const bodyEnd = html.indexOf("<!-- spacefast:body-end -->");
  const bodyClose = html.indexOf("</body>");

  expect(bodyOpen).toBeGreaterThanOrEqual(0);
  expect(noscript).toBeGreaterThan(bodyOpen);
  expect(bodyStart).toBeGreaterThan(noscript);
  expect(heading).toBeGreaterThan(bodyStart);
  expect(bodyEnd).toBeGreaterThan(heading);
  expect(bodyClose).toBeGreaterThan(bodyEnd);

  // A page the compiler could not bake into gets the runtime's tag, decided
  // from the version's record alone, so a comment naming the marker changes
  // nothing.
  const bare = await (await get(rt, HOST, "/bare")).text();
  expect(bare).toContain(`<head>${engineTag}`);
  expect(bare.replace(/<!--[\s\S]*?-->/g, "").split("<script").length - 1).toBe(1);
  const record = readFileSync(
    path.join(
      versionRoot(rt, "spc_html_inject", "ver_html_inject_1"),
      versionRootPointer(rt, "spc_html_inject", "ver_html_inject_1")?.root ?? "",
    ),
    "utf8",
  );
  const bareBlob = responseEntry(rt, "spc_html_inject", "ver_html_inject_1", "/bare.html")?.[
    RESPONSES.entryKeys.blob
  ];
  expect(record).toContain(`'undecorated' =>`);
  expect(record).toContain(String(bareBlob));
  // No anchor at all: a fragment (an htmx or Turbo partial) keeps its bytes,
  // as on main, so a swap never runs the SDK again.
  const fragmentResponse = await get(rt, HOST, "/fragment");
  expect(await fragmentResponse.text()).toBe("<main>Hello</main>\n");
  expect(fragmentResponse.headers.get("content-length")).toBe(
    String(Buffer.byteLength("<main>Hello</main>\n")),
  );

  // The static lane never reads a page to decide its SDK tag. A large page
  // finalize could not decorate gets the tag, and so does the decorated page
  // above without one, when every byte past the head window serve.php already
  // reads is replaced with a fake baked tag: no read beyond that window
  // happens, or the fake would suppress the real one.
  await deploy(rt, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_large",
    files: {
      "index.html": `<!doctype html><html><head><title>Large</title></head>${"<p>row</p>".repeat(20000)}</html>\n`,
    },
    serving: { config: { inject: { bodyEnd: [baked] } } },
    activate: { route_name: "production", ...route("ver_html_inject_large") },
  });
  const largeBlob = String(
    responseEntry(rt, "spc_html_inject", "ver_html_inject_large", "/")?.[RESPONSES.entryKeys.blob],
  );
  const largePath = blobPath(rt, "spc_html_inject", largeBlob);
  const largeBytes = readFileSync(largePath);
  expect(largeBytes.length).toBeGreaterThan(65536);
  // Same length, so every framing decision is unchanged.
  const fake = Buffer.from(`<script async src="/x" data-spacefast-sdk="v1"></script>`);
  fake.copy(largeBytes, largeBytes.length - fake.length - 16);
  writeFileSync(largePath, largeBytes);
  const largeHead = await (await get(rt, HOST, "/", { headers: { range: "bytes=0-4095" } })).text();
  expect(largeHead).toContain(`<head>${engineTag}`);

  // A version from before the record baked the tag into every static page,
  // and the runtime adds nothing to it, exactly as before.
  await deploy(rt, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_legacy",
    files: {
      "index.html":
        "<!doctype html><html><head><title>Legacy</title></head><body>Legacy</body></html>\n",
    },
    serving: { config: { inject: { bodyEnd: [baked] } } },
    activate: { route_name: "production", ...route("ver_html_inject_legacy") },
  });
  const legacyRootPath = path.join(
    versionRoot(rt, "spc_html_inject", "ver_html_inject_legacy"),
    versionRootPointer(rt, "spc_html_inject", "ver_html_inject_legacy")?.root ?? "",
  );
  const stripped = Bun.spawnSync([
    PHP_BINARY,
    "-r",
    "$root = include $argv[1]; unset($root['inject']); file_put_contents($argv[1], '<?php return ' . var_export($root, true) . ';');",
    legacyRootPath,
  ]);
  expect(stripped.exitCode, stripped.stderr.toString()).toBe(0);
  expect(readFileSync(legacyRootPath, "utf8")).not.toContain("'inject'");
  const legacyHtml = await (await get(rt, HOST, "/")).text();
  expect(legacyHtml.split("data-spacefast-sdk").length - 1).toBe(1);
  expect(legacyHtml).toContain(baked);

  // Platform injection off: finalize bakes no tag and the runtime adds none.
  await deploy(rt, {
    spaceId: "spc_html_inject",
    versionId: "ver_html_inject_off",
    files: { "index.html": "<html><head></head><body>Off</body></html>" },
    activate: { route_name: "production", ...route("ver_html_inject_off") },
  });
  expect(await (await get(rt, HOST, "/")).text()).not.toContain("data-spacefast-sdk");

  // A Space that set `disable_all_injection` still gets an SDK section, which
  // says so: the undecorated page, the head with no image and the author's own
  // og:image all stay exactly as the version stored them.
  const optedOut = (sdk: { revision: string; injection?: boolean; config: object }) => ({
    ...route("ver_html_inject_1"),
    config: { ...publicAccessConfig({ mode: "website" }), sdk },
  });
  const storedBare = readBlob(rt, "spc_html_inject", String(bareBlob))?.toString("utf8");
  const sharedBlob = responseEntry(rt, "spc_html_inject", "ver_html_inject_1", "/shared.html")?.[
    RESPONSES.entryKeys.blob
  ];
  const storedShared = readBlob(rt, "spc_html_inject", String(sharedBlob))?.toString("utf8");
  await putRoute(
    rt,
    "spc_html_inject",
    "production",
    optedOut({ revision: "sdk-1", injection: false, config: {} }),
  );
  expect(await (await get(rt, HOST, "/bare")).text()).toBe(String(storedBare));
  expect(await (await get(rt, HOST, "/fragment")).text()).toBe("<main>Hello</main>\n");
  const homeOff = await (await get(rt, HOST, "/")).text();
  expect(homeOff).not.toContain('property="og:image"');
  expect(homeOff).toContain(baked);
  expect(storedShared).toContain('<meta property="og:image" content="/share.png">');
  expect(await (await get(rt, HOST, "/shared")).text()).toBe(String(storedShared));
  // Omitting the opt-out restores SDK injection without changing image metadata.
  await putRoute(rt, "spc_html_inject", "production", route("ver_html_inject_1"));
  expect(await (await get(rt, HOST, "/bare")).text()).toContain(`<head>${engineTag}`);
  const shared = await (await get(rt, HOST, "/shared")).text();
  expect(shared).toBe(String(storedShared));

  // A version whose own `sf.jsonc` opted out gets no runtime tag, while the
  // Space's SDK section, which every version shares, still says on. The same
  // page and inputs otherwise, so the record is the only difference.
  const fileOff = async (versionId: string, optOut: { platform_injection?: false }) => {
    await deploy(rt, {
      spaceId: "spc_html_inject",
      versionId,
      files: { "bare.html": "<html><head><title>File off</title></head><div>Off</div></html>" },
      serving: {
        config: {
          index: false,
          listing: false,
          viewer: false,
          fallback: null,
          inject: { bodyEnd: [baked] },
          ...optOut,
        },
      },
      activate: { route_name: "production", ...route(versionId) },
    });
    return await (await get(rt, HOST, "/bare")).text();
  };
  expect(await fileOff("ver_html_inject_file_on", {})).toContain(`<head>${engineTag}`);
  expect(await fileOff("ver_html_inject_file_off", { platform_injection: false })).not.toContain(
    "data-spacefast-sdk",
  );
});

test("static images and share-image metadata serve without PHP temporary files", async () => {
  // Reproduce the storage condition that used to crash HTML rendering.
  const unwritable = mkdtempSync(path.join(os.tmpdir(), "stattic-full-temp-"));
  chmodSync(unwritable, 0o500);
  // The API base lets a page baked before the screenshot route existed
  // (`retained.html` below) still name its social card on the API.
  const full = await startRuntime({
    phpIni: { sys_temp_dir: unwritable },
    env: { SPACEFAST_BROWSER_API_URL: "https://api.example.test" },
  });
  try {
    const page =
      '<html><head><meta property="og:image" content="/share.png"></head><body>Shared</body></html>\n';
    await deploy(full, {
      spaceId: "spc_full_temp",
      versionId: "ver_full_temp_1",
      finalize: {
        system_variables: { SPACEFAST_LIVE_URL: "https://full-temp.test/" },
        page_screenshot_url: "https://api.example.test/v1/screenshots/page",
      },
      serving: { config: { platform_meta: true } },
      files: {
        "index.html": page,
        "fallback.html": "<html><head></head><body>Screenshot</body></html>",
        "retained.html":
          '<html><head><meta data-spacefast-auto-image="og:image" property="og:image" content="https://spacefast.com/icon.png"><meta name="twitter:card" content="summary" data-spacefast-auto-image="twitter:card"><script>const sample = \'<meta data-spacefast-auto-image="og:image" content="fake.png">\';</script></head><body>Retained</body></html>',
        // A file whose name holds a literal percent: its card must name the
        // same file after the API decodes the card URL's `url` value once.
        "docs/a%20b.html": "<html><head></head><body>Percent</body></html>",
        "share.png": {
          content: new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x2a]),
          contentType: "image/png",
        },
      },
      activate: {
        route_name: "production",
        version_id: "ver_full_temp_1",
        config: publicAccessConfig({ mode: "website" }),
        production_hostnames: ["full-temp.test"],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const response = await get(full, "full-temp.test", "/");
    expect(response.status).toBe(200);
    const html = await response.text();
    expect(html).toContain('<meta property="og:image" content="/share.png">');
    expect(html).toContain("<body>Shared</body>");
    const screenshot = await get(full, "full-temp.test", "/fallback");
    expect(screenshot.status).toBe(200);
    const fallbackHtml = await screenshot.text();
    // The card names the API's capture of the address the visitor asked for.
    expect(fallbackHtml).toContain(
      'content="https://api.example.test/v1/screenshots/page?url=https%3A%2F%2Ffull-temp.test%2Ffallback&amp;version=ver_full_temp_1"',
    );
    expect(fallbackHtml).not.toContain("s0.wp.com");
    // The visitor asks for /docs/a%2520b (the runtime's own address for the
    // file `docs/a%20b.html`); the card URL carries that spelling encoded
    // once more, so one decode on the API gives it back.
    const percent = await get(full, "full-temp.test", "/docs/a%2520b");
    expect(percent.status).toBe(200);
    expect(await percent.text()).toContain(
      'content="https://api.example.test/v1/screenshots/page?url=https%3A%2F%2Ffull-temp.test%2Fdocs%2Fa%252520b&amp;version=ver_full_temp_1"',
    );
    expect(fallbackHtml).toContain("<body>Screenshot</body>");
    const retained = await get(full, "full-temp.test", "/retained?token=never-capture-this");
    const retainedHtml = await retained.text();
    expect(retained.status).toBe(200);
    expect(retainedHtml).toContain(
      'content="https://api.example.test/v1/screenshots/page?url=https%3A%2F%2Ffull-temp.test%2Fretained&amp;version=ver_full_temp_1"',
    );
    expect(retainedHtml).toContain('content="summary_large_image"');
    expect(retainedHtml).not.toContain("never-capture-this");
    expect(retainedHtml).toContain('content="fake.png"');
    const head = await get(full, "full-temp.test", "/retained", { method: "HEAD" });
    expect(head.headers.get("etag")).toBe(retained.headers.get("etag"));
    expect(Number(head.headers.get("content-length"))).toBe(Buffer.byteLength(retainedHtml));
    const ranged = await get(full, "full-temp.test", "/retained", {
      headers: { range: "bytes=0-99" },
    });
    expect(ranged.status).toBe(206);
    expect(await ranged.text()).toBe(retainedHtml.slice(0, 100));
  } finally {
    full.stop();
    chmodSync(unwritable, 0o700);
    rmSync(unwritable, { recursive: true, force: true });
  }
});

test("platform_meta bakes metadata and authored images keep their published URLs", async () => {
  const favicon = "fake-svg-bytes\n";
  const ogImage = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x2a]);
  await deploy(rt, {
    spaceId: "spc_platform_meta",
    versionId: "ver_platform_meta_1",
    files: {
      "index.html": "<!doctype html><html><head></head><body><h1>Plain site</h1></body></html>\n",
      "docs/index.html": `<html><head><meta CONTENT="../og.png?size=large&amp;v=1#preview" PROPERTY="OG:IMAGE"><meta name="twitter:image" content="share.png"><meta property="og:image:url" content="https://cdn.example.test/remote.png"><script>const fake = '<meta property="og:image" content="fake.png">';</script></head><body>Docs</body></html>`,
      "based/index.html":
        '<html><head><meta name="twitter:image" content="cover.png"><base href="https://cdn.example.test/images/"></head><body>Based</body></html>',
      "docs/share.png": { content: ogImage, contentType: "image/png" },
      "og.png": { content: ogImage, contentType: "image/png" },
      "favicon.svg": { content: favicon, contentType: "image/svg+xml" },
    },
    serving: {
      config: {
        index: "index.html",
        listing: false,
        viewer: false,
        fallback: null,
        meta: {
          title: "Platform title",
          description: "Platform description",
          image: "/og.png",
          favicon: "/favicon.svg",
        },
        platform_meta: true,
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [META_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const response = await get(rt, META_HOST, "/");
  expect(response.status).toBe(200);
  const html = await response.text();
  expect(html).toContain("<title>Platform title</title>");
  expect(html).toContain('<meta name="description" content="Platform description">');
  expect(html).toContain('<meta property="og:title" content="Platform title">');
  expect(html).toContain('<meta property="og:description" content="Platform description">');
  expect(html).toContain('<meta name="twitter:card" content="summary_large_image">');
  expect(html).toContain(
    `<meta property="og:image" content="/og.png?v=${sha256(ogImage).slice(0, 12)}">`,
  );
  expect(html).toContain(`<link rel="icon" href="/favicon.svg?v=${sha256(favicon).slice(0, 12)}">`);
  const docs = await (await get(rt, META_HOST, "/docs/")).text();
  expect(docs).toContain('CONTENT="/og.png?size=large&amp;v=1#preview" PROPERTY="OG:IMAGE"');
  expect(docs).toContain('<meta name="twitter:image" content="/docs/share.png">');
  expect(docs).toContain('content="https://cdn.example.test/remote.png"');
  expect(docs).toContain(`const fake = '<meta property="og:image" content="fake.png">';`);
  const based = await (await get(rt, META_HOST, "/based/")).text();
  expect(based).toContain(
    '<meta name="twitter:image" content="https://cdn.example.test/images/cover.png">',
  );
  expect(based).toContain('<base href="https://cdn.example.test/images/">');
});

// THE bypass test. Every page type and endpoint the engine serves, with
// injection off from each place it can be turned off: the Space (`sdk.injection:
// false` from a control plane that sends no plan), the version's own sf.jsonc
// (its root's `platform: false`), and a plan that says off. The answer must
// carry nothing Spacefast adds beyond what the plan never turns off, and every
// snippet the author wrote. A new engine injection that skips the plan adds
// bytes to one of these responses and fails here.
test("injection off reaches every engine page type and endpoint, from every source", async () => {
  const SPACE = "spc_inject_off";
  const LIVE = "inject-off.test";
  const VERSION_HOST = "inject-off--v.test";
  // A hostname the Space proxies whole, which serves its live version's plan.
  const WHOLE_PROXY = "inject-off-proxy.test";
  // A WordPress content page: the document the engine builds from a Page.
  const pageId = `page.${"d".repeat(32)}`;
  const bindingId = `sync.pages.${"d".repeat(32)}`;
  const seedText = "<p>Document</p>";
  const seed = JSON.stringify({
    bindingId,
    format: "tsx",
    modelRevision: `sha256:${"a".repeat(64)}`,
    text: seedText,
    sha256: `sha256:${sha256(seedText)}`,
  });
  const BAKED_SDK = '<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  // What finalize records for a static GA tag beside the author's snippets.
  const STATIC_GA =
    '<!--spacefast-static:ga:head:start--><script async src="https://www.googletagmanager.com/gtag/js?id=G-OFF"></script><!--spacefast-static:ga:head:end-->';
  const AUTHOR = {
    head: ['<meta name="author-head" content="kept">'],
    bodyStart: ['<div id="author-start"></div>'],
    bodyEnd: ["<script>window.authorEnd=1;</script>"],
    noscript: ['<noscript><img src="/author-noscript.gif"></noscript>'],
  };
  const authorSnippets = Object.values(AUTHOR).flat();
  // Every byte the engine itself writes into a page, by what it is.
  const PLATFORM_MARKERS = [
    "data-spacefast-sdk",
    "__spacefast/sdk.js",
    "spacefast-static:",
    "googletagmanager.com",
    "data-spacefast-auto-image",
    "/v1/screenshots/page?url=",
  ];
  const TAG_BODY = "window.__spacefastTagBody=1;";
  const beaconBody = "window.__beaconTracker=1;\n";
  const beacon = Bun.serve({
    port: 0,
    hostname: "127.0.0.1",
    fetch: () => new Response(beaconBody, { headers: { "content-type": "text/javascript" } }),
  });
  const upstreamPage = "<html><head><title>Up</title></head><body>upstream</body></html>";
  const upstream = Bun.serve({
    port: 0,
    hostname: "127.0.0.1",
    fetch: () => new Response(upstreamPage, { headers: { "content-type": "text/html" } }),
  });
  const workerPage = "<html><head><title>Worker</title></head><body>worker</body></html>";
  const { certificate, key } = selfSignedCertificate(rt.root, "inject-off-edge");
  const edge = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    tls: { cert: Bun.file(certificate), key: Bun.file(key) },
    fetch: () => new Response(workerPage, { headers: { "content-type": "text/html" } }),
  });
  // A Zero 0.8 runner that answers an HTML page; every other runner command
  // goes to the real binary.
  const zeroRunner = path.join(rt.root, "inject-off-runner.php");
  const zeroPage = "<html><head><title>Zero</title></head><body>zero</body></html>";
  writeFileSync(
    zeroRunner,
    `#!${PHP_BINARY}
<?php
if (($argv[1] ?? '') !== 'invoke') {
    $process = proc_open(array_merge([${JSON.stringify(RUNTIME_BIN)}], array_slice($argv, 1)), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    exit(is_resource($process) ? proc_close($process) : 1);
}
stream_get_contents(STDIN);
echo json_encode(['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => ${JSON.stringify(zeroPage)}]);
`,
  );
  chmodSync(zeroRunner, 0o755);
  // The WordPress boundary the content page lane calls, as content-page.test.ts
  // stands it in: one published page owned by this Space.
  const wordpressPage = "<p>WordPress document</p>";
  const off = await startRuntime({
    env: {
      SPACEFAST_EGRESS_TEST_ALLOWLIST: `127.0.0.1:${upstream.port}`,
      SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "inject-off-dispatch",
      SPACEFAST_RUNTIME_BIN: zeroRunner,
      SPACEFAST_API_BASE_URL: "https://api.spacefast.test",
    },
    phpIni: { "curl.cainfo": certificate },
  });
  writeFileSync(
    path.join(off.root, "wp-load.php"),
    `<?php
function spacefast_content_model_sync_binding($id) { return ['post_type' => 'page', 'format' => 'tsx', 'documentSeed' => ['sha256' => ${JSON.stringify(`sha256:${sha256(seedText)}`)}]]; }
function spacefast_content_sync_find_post($id, $binding, $adopt) { return (object) ['ID' => 7, 'post_status' => 'publish', 'post_title' => 'Doc', 'post_content' => ${JSON.stringify(wordpressPage)}]; }
function get_post_meta($id, $key, $single) { return ${JSON.stringify(SPACE)}; }
`,
  );
  try {
    const versionId = (name: string) => `ver_inject_off_${name}`;
    const tracker = {
      src: RUNTIME_TRACKER_SCRIPT_PATH,
      endpoint: `http://127.0.0.1:${beacon.port}`,
      key: "pk_inject_off",
    };
    // A section that is on in every way the engine reads one, so whatever
    // turns it off is the only difference.
    // The two switches a section can carry beside its contents.
    type SectionSwitches = { injection?: boolean; plan?: RouteInjectionPlan };
    type Section = ReturnType<typeof section>;
    const section = (extra: SectionSwitches) => ({
      revision: "sdk-off",
      config: {
        tracker,
        comments: { live: true, preview: true, ui: "default", features: {} },
        cast_api_base: "https://cast.example.test",
      },
      body: TAG_BODY,
      ...extra,
    });
    const route = (live: string, sdk: Section) => ({
      version_id: live,
      config: {
        ...publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
        entitlements: { externalProxy: true },
        sdk,
      },
      production_hostnames: [LIVE],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: live }],
      proxy_host_routes: [
        {
          hostname: LIVE,
          path_prefix: "/host-proxy",
          upstream: `http://127.0.0.1:${upstream.port}`,
        },
        { hostname: WHOLE_PROXY, upstream: `http://127.0.0.1:${upstream.port}` },
      ],
    });
    const deployVersion = (name: string, sdk: Section, fileOff = false) => {
      const decorated = {
        platform_meta: true,
        inject: {
          ...AUTHOR,
          head: [...AUTHOR.head, STATIC_GA],
          bodyEnd: [...AUTHOR.bodyEnd, BAKED_SDK],
        },
      };
      // The version's own sf.jsonc turning injection off.
      const config = fileOff ? { ...decorated, platform_injection: false } : decorated;
      return deploy(off, {
        spaceId: SPACE,
        versionId: versionId(name),
        metadata: { mode: "website", title: "Off" },
        files: {
          // Decorated at finalize: the baked SDK and GA are in its bytes.
          "index.html": "<html><head><title>Home</title></head><body>home</body></html>",
          // No `<body>`: the one static page the engine adds its own tag to.
          "bare.html": "<html><head><title>Bare</title></head><div>bare</div></html>",
          _redirects: `/proxy http://127.0.0.1:${upstream.port}/proxy 200\n`,
          "zero.config.json": "{}",
          [`_spacefast/pages/documents/${pageId}.json`]: seed,
        },
        serving: {
          config,
          pages: [{ id: pageId, path: "/doc", params: [], render: "document", bindingId }],
          zero_endpoints: [
            {
              execution_mode: "read",
              method: "GET",
              path: "/zero-page",
              source: "globalThis.__statticZeroResult = '{}';",
              endpoint_id: "GET /zero-page",
              capabilities: { db: false },
            },
          ],
        },
        functions: {
          artifact: {
            appName: "inject-off",
            entry: "worker.js",
            mainModule: "index.js",
            compatibilityDate: "2026-07-01",
            compatibilityFlags: [],
            routes: [{ method: "GET", path: "/fn", subtree: false }],
          },
          host: { hostname: `127.0.0.1:${edge.port}`, bundleUrl: "https://bundle.invalid/b.json" },
          grantedCapabilities: [],
        },
        activate: { route_name: "production", ...route(versionId(name), sdk) },
      });
    };

    // The bytes finalize stored for a static page, or null for a dynamic one.
    const staticBlob = (name: string, page: string) => {
      const key = { "/": "/", "/bare": "/bare.html" }[page];
      if (!key) return null;
      const blob = responseEntry(off, SPACE, versionId(name), key)?.[RESPONSES.entryKeys.blob];
      return readBlob(off, SPACE, String(blob))?.toString("utf8") ?? null;
    };
    const PLAN_ON = { mode: "on", items: { "sdk-tag": true, meta: true, tracker: ["live"] } };
    const planOff = (target: "default" | "version", name: string) => ({
      v: 1,
      default: target === "default" ? { mode: "off", items: {} } : PLAN_ON,
      versions: target === "version" ? { [versionId(name)]: { mode: "off", items: {} } } : {},
    });
    // What the same Space serves with injection on: the baseline each off
    // response is compared against, so this test also proves every lane it
    // reads really adds something when on.
    await deployVersion("on", section({}));
    const read = async (host: string, requestPath: string) => {
      const response = await get(off, host, requestPath, { headers: { accept: "text/html" } });
      return {
        status: response.status,
        type: response.headers.get("content-type"),
        body: await response.text(),
      };
    };
    const pages = ["/", "/bare", "/proxy", "/host-proxy/x", "/fn", "/zero-page", "/doc"];
    const on = Object.fromEntries(
      await Promise.all(pages.map(async (page) => [page, await read(LIVE, page)] as const)),
    );
    for (const page of pages) {
      expect(on[page]?.status, page).toBeLessThan(300);
      expect(
        PLATFORM_MARKERS.some((marker) => on[page]?.body.includes(marker)),
        `${page} carries a Spacefast addition when on`,
      ).toBe(true);
    }
    // The static home's share image is the serve-time rewrite of a baked
    // fallback, so it is there only when on.
    expect(on["/"]?.body).toContain("/v1/screenshots/page?url=");
    expect(staticBlob("on", "/")).toContain("data-spacefast-auto-image");
    expect(on["/doc"]?.body).toContain(wordpressPage);
    const wholeProxyOn = await read(WHOLE_PROXY, "/");
    expect(wholeProxyOn.body).toContain("data-spacefast-sdk");
    // The stub keeps the cache policy the on answer has on each host.
    const sdkOnCache: Record<string, string | null> = {};
    for (const host of [LIVE, VERSION_HOST]) {
      sdkOnCache[host] = (await get(off, host, "/__spacefast/sdk.js")).headers.get("cache-control");
    }
    const sdkOn = await read(LIVE, "/__spacefast/sdk.js");
    expect(sdkOn.body).toContain(TAG_BODY);
    // The tracker is in the config sdk.js boots from.
    expect(sdkOn.body).toMatch(/window\.Spacefast\.boot\(\{[^\n]*"tracker":\{/);
    expect((await read(LIVE, RUNTIME_TRACKER_SCRIPT_PATH)).body).toBe(beaconBody);

    for (const [source, sdk, fileOff] of [
      ["Space off, no plan", section({ injection: false }), false],
      ["sf.jsonc off, no plan", section({}), true],
      [
        "plan default off",
        section({ injection: true, plan: planOff("default", "plan_default") }),
        false,
      ],
      [
        "plan off for this version",
        section({ injection: true, plan: planOff("version", "plan_version") }),
        false,
      ],
    ] as const) {
      const name = source.startsWith("plan default")
        ? "plan_default"
        : source.startsWith("plan off")
          ? "plan_version"
          : source.startsWith("Space")
            ? "space"
            : "file";
      await deployVersion(name, sdk, fileOff);
      for (const host of [LIVE, VERSION_HOST]) {
        for (const page of pages) {
          if (host === VERSION_HOST && page === "/host-proxy/x") continue;
          const served = await read(host, page);
          const label = `${source}, ${host}${page}`;
          expect(served.status, label).toBeLessThan(300);
          // The author's snippets all stay where the page has a place for them.
          if (page === "/" || page === "/bare") {
            for (const snippet of authorSnippets) {
              if (page === "/bare" && snippet !== AUTHOR.head[0]) continue;
              expect(served.body, label).toContain(snippet);
            }
          } else {
            for (const snippet of authorSnippets) expect(served.body, label).toContain(snippet);
          }
          // Nothing Spacefast adds at serve time. A static page keeps what
          // finalize baked into that version's bytes (baked bytes stay with
          // their version), so it may carry a marker only as often as its
          // stored blob does, and never the serve-time share image. Every
          // other lane starts from bytes Spacefast never touched.
          const count = (text: string, marker: string) => text.split(marker).length - 1;
          const stored = staticBlob(name, page);
          for (const marker of PLATFORM_MARKERS) {
            const allowed =
              stored === null ||
              marker === "data-spacefast-auto-image" ||
              marker === "/v1/screenshots/page?url="
                ? 0
                : count(stored, marker);
            expect(count(served.body, marker), `${label}: ${marker}`).toBeLessThanOrEqual(allowed);
          }
          // Dynamic pages are the upstream's bytes plus the author's snippets.
          const upstreamBytes = {
            "/proxy": upstreamPage,
            "/host-proxy/x": upstreamPage,
            "/fn": workerPage,
            "/zero-page": zeroPage,
          }[page];
          if (upstreamBytes) {
            let stripped = served.body;
            for (const snippet of authorSnippets) stripped = stripped.replace(snippet, "");
            stripped = stripped.replaceAll(/<!-- \/?spacefast:[a-z-]+ -->/g, "");
            expect(stripped, label).toBe(upstreamBytes);
          }
        }
        if (host === LIVE) {
          // A whole-hostname proxy answers before any version is resolved and
          // serves the live version's answer.
          const wholeProxy = await read(WHOLE_PROXY, "/");
          let stripped = wholeProxy.body;
          for (const snippet of authorSnippets) stripped = stripped.replace(snippet, "");
          expect(
            stripped.replaceAll(/<!-- \/?spacefast:[a-z-]+ -->/g, ""),
            `${source}, whole proxy`,
          ).toBe(upstreamPage);
        }
        // sdk.js still answers 200 with the same type, and runs nothing.
        const sdkResponse = await get(off, host, "/__spacefast/sdk.js");
        expect(sdkResponse.status).toBe(200);
        expect(sdkResponse.headers.get("content-type")).toBe(
          "application/javascript; charset=utf-8",
        );
        expect(sdkResponse.headers.get("cache-control")).toBe(sdkOnCache[host]);
        expect(await sdkResponse.text(), `${source}, ${host} sdk.js`).toBe(
          "/* spacefast: injection off */\n",
        );
        // A tag preview, a review frame and a draft token load nothing either.
        for (const query of ["?preview=tok_off", "?review=1"]) {
          expect(
            await (await get(off, host, `/__spacefast/sdk.js${query}`)).text(),
            `${source} ${query}`,
          ).toBe("/* spacefast: injection off */\n");
        }
        const trackerResponse = await get(off, host, RUNTIME_TRACKER_SCRIPT_PATH);
        expect(trackerResponse.status).toBe(200);
        expect(await trackerResponse.text(), `${source}, ${host} t.js`).not.toContain(
          "__beaconTracker",
        );
      }
    }
  } finally {
    off.stop();
    beacon.stop(true);
    upstream.stop(true);
    edge.stop(true);
  }
}, 120000);
