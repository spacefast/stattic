import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  blobPath,
  deploy,
  get,
  PHP_BINARY,
  putRoute,
  publicAccessConfig,
  readBlob,
  RESPONSES,
  responseEntry,
  sha256,
  startRuntime,
  versionRoot,
  versionRootPointer,
  type Runtime,
} from "./harness.ts";

const HOST = "html-inject.test";
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
        expect(response.headers.get("x-spacefast-version")).toBe(`ver_icons_${expected}`);
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
    config: { ...publicAccessConfig({ mode: "website" }), sdk: { revision: "sdk-1", config: {} } },
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
  await putRoute(rt, "spc_html_inject", "production", optedOut({ revision: "sdk-1", config: {} }));
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

test("static images and mShots metadata serve without PHP temporary files", async () => {
  // Reproduce the storage condition that used to crash HTML rendering.
  const unwritable = mkdtempSync(path.join(os.tmpdir(), "stattic-full-temp-"));
  chmodSync(unwritable, 0o500);
  const full = await startRuntime({ phpIni: { sys_temp_dir: unwritable } });
  try {
    const page =
      '<html><head><meta property="og:image" content="/share.png"></head><body>Shared</body></html>\n';
    await deploy(full, {
      spaceId: "spc_full_temp",
      versionId: "ver_full_temp_1",
      finalize: { system_variables: { SPACEFAST_LIVE_URL: "https://full-temp.test/" } },
      serving: { config: { platform_meta: true } },
      files: {
        "index.html": page,
        "fallback.html": "<html><head></head><body>Screenshot</body></html>",
        "retained.html":
          '<html><head><meta data-spacefast-auto-image="og:image" property="og:image" content="https://spacefast.com/icon.png"><meta name="twitter:card" content="summary" data-spacefast-auto-image="twitter:card"><script>const sample = \'<meta data-spacefast-auto-image="og:image" content="fake.png">\';</script></head><body>Retained</body></html>',
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
    expect(fallbackHtml).toContain(
      'content="https://s0.wp.com/mshots/v1/https%3A%2F%2Ffull-temp.test%2Ffallback?w=1200&amp;h=630"',
    );
    expect(fallbackHtml).toContain("<body>Screenshot</body>");
    const retained = await get(full, "full-temp.test", "/retained?token=never-capture-this");
    const retainedHtml = await retained.text();
    expect(retained.status).toBe(200);
    expect(retainedHtml).toContain(
      'content="https://s0.wp.com/mshots/v1/https%3A%2F%2Ffull-temp.test%2Fretained?w=1200&amp;h=630"',
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
