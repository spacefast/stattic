import { afterAll, beforeAll, expect, test } from "bun:test";

import { api, deploy, finalizeRaw, get, putRoute, type Runtime, startRuntime } from "./harness.ts";

let rt: Runtime;
const passwordHash = Bun.password.hashSync("swordfish", { algorithm: "bcrypt", cost: 4 });

const documents = {
  "page-password":
    '<!doctype html><html><body><h1>Acme private</h1><!--sf-runtime:challenge:start--><form method="post"><input name="_pw" type="password"><button>Go</button></form><!--sf-runtime:challenge:end--></body></html>',
  "page-password-handwritten":
    '<!doctype html><html><body><h1>Legacy private</h1><form method="post"><input name="_pw" type="password"><button>Go</button></form></body></html>',
  "page-denied":
    "<!doctype html><html><body><h1>Acme says no</h1><!--sf-runtime:denial:start--><p>Fallback denial</p><!--sf-runtime:denial:end--></body></html>",
  "page-login":
    '<!doctype html><html><body><h1>Acme login</h1><!--sf-runtime:challenge:start--><a href="/__spacefast/access/login">Log in</a><!--sf-runtime:challenge:end--></body></html>',
  "page-404":
    "<!doctype html><html><body><h1>Acme lost it</h1><p><!--sf-runtime:request-path:start-->fallback<!--sf-runtime:request-path:end--></p><small><!--sf-runtime:request-path:start-->fallback<!--sf-runtime:request-path:end--></small></body></html>",
  "page-index": '<!doctype html><html><body><a href="/report.pdf">report.pdf</a></body></html>',
  "page-preview": "<!doctype html><html><body><h1>Preview report.pdf</h1></body></html>",
};

const pointers = {
  routes: {
    "/": {
      pages: {
        "404": "page-404",
        password: "page-password",
        denied: "page-denied",
        login: "page-login",
      },
      index: "page-index",
    },
  },
  previews: { "/report.pdf": "page-preview" },
};

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

async function deployPages(
  host: string,
  spaceId: string,
  versionId: string,
  runtime: Runtime = rt,
  passwordArtifact = "page-password",
): Promise<void> {
  await deploy(runtime, {
    spaceId,
    versionId,
    files: { "report.pdf": "report bytes" },
    pageArtifacts: documents,
    serving: {
      config: {
        index: false,
        listing: true,
        viewer: true,
        pages: {
          ...pointers,
          routes: {
            "/": {
              ...pointers.routes["/"],
              pages: { ...pointers.routes["/"].pages, password: passwordArtifact },
            },
          },
        },
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "files" },
      production_hostnames: [host],
      version_hostnames: [],
    },
  });
}

function expectCachePolicy(response: Response, policy: string): void {
  expect(response.headers.get("cache-control")).toBe(policy);
  expect(response.headers.get("cdn-cache-control")).toBe(policy);
  expect(response.headers.get("surrogate-control")).toBe(policy);
}

test("gate pages select immutable HTML artifacts and preserve enforcement headers", async () => {
  const host = "pages-gate.test";
  await deployPages(host, "spc_pages_gate", "ver_pages_gate_1");
  await putRoute(rt, "spc_pages_gate", "production", {
    version_id: "ver_pages_gate_1",
    config: {
      policy: {
        sessionVersion: 0,
        rules: [
          {
            id: "pw",
            match: { pathPattern: "/**" },
            effect: "challenge",
            auth: {
              requiredGrants: ["pw:pw"],
              acquire: [{ type: "password", ref: "secret:site_pw", transport: "form" }],
            },
          },
        ],
      },
      secrets: { site_pw: passwordHash },
    },
  });

  const response = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  expect(response.status).toBe(401);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(response.headers.get("x-robots-tag")).toContain("noindex");
  const initialHtml = await response.text();
  expect(initialHtml).toContain("Acme private");
  expect(initialHtml).toContain('name="_pw"');
  expect(initialHtml).not.toContain("sf-runtime:challenge");

  const invalid = await get(rt, host, "/", {
    method: "POST",
    headers: { Accept: "text/html", "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ _pw: "wrong" }).toString(),
  });
  const invalidHtml = await invalid.text();
  expect(invalid.status).toBe(401);
  expect(invalidHtml).toContain('aria-invalid="true"');
  expect(invalidHtml).toContain('aria-describedby="sf-pw-error"');
  expect(invalidHtml).toContain('role="alert"');

  const json = await get(rt, host, "/", { headers: { Accept: "application/json" } });
  expect(await json.json()).toMatchObject({
    error: {
      code: "password_required",
      status: 401,
      page: "password",
      action: { method: "POST", field: "_pw" },
    },
  });
});

test("legacy hand-written password pages fall back safely for Basic-only challenges", async () => {
  const host = "pages-basic-legacy.test";
  await deployPages(
    host,
    "spc_pages_basic_legacy",
    "ver_pages_basic_legacy_1",
    rt,
    "page-password-handwritten",
  );
  await putRoute(rt, "spc_pages_basic_legacy", "production", {
    version_id: "ver_pages_basic_legacy_1",
    config: {
      policy: {
        sessionVersion: 0,
        rules: [
          {
            id: "basic",
            match: { pathPattern: "/**" },
            effect: "challenge",
            auth: {
              requiredGrants: ["pw:basic"],
              acquire: [{ type: "password", ref: "secret:site_pw", transport: "basic" }],
            },
          },
        ],
      },
      secrets: { site_pw: passwordHash },
    },
  });
  const response = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  const html = await response.text();
  expect(response.status).toBe(401);
  expect(response.headers.get("www-authenticate")).toBe('Basic realm="Spacefast"');
  expect(html).toContain("This space is protected");
  expect(html).toContain("Need help?");
  expect(html).toContain("Best way to share what your agent made");
  expect(html).toContain('@font-face{font-family:"Merriweather"');
  expect(html).toContain('@font-face{font-family:"Haskoy"');
  expect(html).not.toContain("Legacy private");
  expect(html).not.toContain('name="_pw"');
});

test("the static login page owns host and return when handing off to identity", async () => {
  const host = "pages-login.test";
  await deployPages(host, "spc_pages_login", "ver_pages_login_1");
  await putRoute(rt, "spc_pages_login", "production", {
    version_id: "ver_pages_login_1",
    config: {
      policy: {
        sessionVersion: 0,
        rules: [
          {
            id: "login",
            match: { pathPattern: "/private/**" },
            effect: "challenge",
            auth: {
              requiredGrants: ["user:*"],
              acquire: [
                {
                  type: "login",
                  url: "https://api.example.test/v1/access/authorize?space=spc_pages_login&connection=acn_test&host=stale.example.test&return=%2Fstale",
                },
              ],
            },
          },
        ],
      },
    },
  });
  const gate = await get(rt, host, "/private/report", { headers: { Accept: "text/html" } });
  expect(gate.status).toBe(401);
  expect(await gate.text()).toContain("Acme login");

  const handoff = await get(rt, host, "/__spacefast/access/login?return=%2Fprivate%2Freport");
  expect(handoff.status).toBe(302);
  expect(handoff.headers.get("location")).toBe(
    "https://api.example.test/v1/access/authorize?space=spc_pages_login&connection=acn_test&host=pages-login.test&return=%2Fprivate%2Freport",
  );

  await putRoute(rt, "spc_pages_login", "production", {
    version_id: "ver_pages_login_1",
    config: {
      policy: {
        sessionVersion: 0,
        rules: [
          {
            id: "login",
            match: { pathPattern: "/private/**" },
            effect: "challenge",
            auth: {
              requiredGrants: ["user:*"],
              acquire: [{ type: "login", url: "http://identity.example.test/authorize" }],
            },
          },
        ],
      },
    },
  });
  const unsafeHandoff = await get(rt, host, "/__spacefast/access/login?return=%2Fprivate%2Freport");
  expect(unsafeHandoff.status).toBe(403);
  expect(unsafeHandoff.headers.get("location")).toBeNull();
});

test("every site-authored response negotiates stable JSON and one-line text", async () => {
  const host = "pages-negotiate.test";
  await deployPages(host, "spc_pages_negotiate", "ver_pages_negotiate_1");
  await putRoute(rt, "spc_pages_negotiate", "production", {
    version_id: "ver_pages_negotiate_1",
    config: {
      policy: {
        sessionVersion: 0,
        rules: [{ id: "deny", match: { pathPattern: "/private/**" }, effect: "deny" }],
      },
    },
  });

  const deniedHtml = await get(rt, host, "/private/x", { headers: { Accept: "text/html" } });
  expect(deniedHtml.status).toBe(403);
  const deniedBody = await deniedHtml.text();
  expect(deniedBody).toContain("Acme says no");
  expect(deniedBody).toContain("Access to this space is restricted.");
  expect(deniedBody).not.toContain("Fallback denial");

  const denied = await get(rt, host, "/private/x", { headers: { Accept: "application/json" } });
  expect(denied.status).toBe(403);
  expect(denied.headers.get("vary")).toContain("Accept");
  expect(denied.headers.get("vary")).toContain("Sec-Fetch-Mode");
  expect(await denied.json()).toEqual({
    error: {
      code: "access_denied",
      status: 403,
      page: "denied",
      message: "Access to this space is restricted.",
    },
  });

  const missing = await get(rt, host, "/missing", { headers: { Accept: "text/plain" } });
  expect(missing.status).toBe(404);
  expect(await missing.text()).toBe("Not Found\n");
});

test("404, index, and preview select their publish-rendered artifacts", async () => {
  const host = "pages-content.test";
  await deployPages(host, "spc_pages_content", "ver_pages_content_1");

  const index = await get(rt, host, "/", { headers: { Accept: "text/html" } });
  expect(index.status).toBe(200);
  expect(await index.text()).toContain("report.pdf");

  const preview = await get(rt, host, "/report.pdf", { headers: { Accept: "text/html" } });
  expect(preview.status).toBe(200);
  expect(await preview.text()).toContain("Preview report.pdf");

  const negotiatedRaw = await get(rt, host, "/report.pdf", {
    headers: { Accept: "application/pdf" },
  });
  expect(negotiatedRaw.status).toBe(200);
  expect(negotiatedRaw.headers.get("vary")).toContain("Accept");
  expect(negotiatedRaw.headers.get("vary")).toContain("Sec-Fetch-Mode");
  expect(await negotiatedRaw.text()).toBe("report bytes");

  const raw = await get(rt, host, "/report.pdf?spacefast_raw=1", {
    headers: { Accept: "text/html" },
  });
  expect(raw.status).toBe(200);
  expect(await raw.text()).toBe("report bytes");

  const missing = await get(rt, host, "/missing", { headers: { Accept: "text/html" } });
  expect(missing.status).toBe(404);
  const missingHtml = await missing.text();
  expect(missingHtml).toContain("Acme lost it");
  expect(missingHtml.match(/\/missing/g)).toHaveLength(2);
  expect(missingHtml).not.toContain("sf-runtime:request-path");
});

test("publish-rendered pages inherit private access caching without changing public defaults", async () => {
  const host = "pages-country-cache.test";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    const spaceId = "spc_pages_country_cache";
    const versionId = "ver_pages_country_cache_1";
    await deploy(trusted, {
      spaceId,
      versionId,
      files: { "report.pdf": "report bytes", "docs/index.html": "<h1>docs</h1>" },
      pageArtifacts: documents,
      serving: {
        config: {
          index: false,
          listing: true,
          viewer: true,
          pages: pointers,
        },
        headers_exact: {
          "/docs/": [
            {
              order: 1,
              operations: [
                {
                  kind: "set",
                  name: "Cache-Control",
                  value: "public, max-age=0, s-maxage=600, must-revalidate",
                },
                { kind: "set", name: "X-Cache-Shape", value: "custom-header" },
              ],
              headers: {
                "Cache-Control": "public, max-age=0, s-maxage=600, must-revalidate",
                "X-Cache-Shape": "custom-header",
              },
            },
          ],
        },
        redirects_exact: {
          "/compiled": [
            {
              destination: "/compiled-target",
              status: 302,
              action: "redirect",
              order: 1,
            },
          ],
        },
        redirects_pattern: [
          {
            source: "/artifact/*",
            regex: "^/artifact/(?<splat>.*)$",
            destination: "/artifact-target/:splat",
            status: 302,
            action: "redirect",
            order: 2,
          },
        ],
      },
      activate: {
        route_name: "production",
        config: { mode: "files" },
        production_hostnames: [host],
        version_hostnames: [],
      },
    });

    const routeFixture = JSON.stringify([
      {
        location: "/host-redirect",
        route_action: {
          action: "redirect",
          destination: "/host-target",
          status: 302,
          cache_control: "public, max-age=0, s-maxage=60, must-revalidate",
        },
      },
    ]);
    const injectHostRedirect = async () => {
      const currentRoute = await Bun.file(`${trusted.storageRoot}/routes/current.php`).text();
      const generation = /'generation' => '([^']+)'/.exec(currentRoute)?.[1];
      if (!generation) throw new Error("runtime route generation is missing");
      const shard = new Bun.CryptoHasher("sha256").update(host).digest("hex").slice(0, 2);
      const shardPath = `${trusted.storageRoot}/routes/generations/${generation}/hosts/${shard}.php`;
      const inject = Bun.spawnSync([
        "php",
        "-r",
        `$path = $argv[1]; $host = $argv[2]; $routes = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR); $artifact = include $path; $artifact['host_routes'][$host] = array_merge($routes, $artifact['host_routes'][$host] ?? []); file_put_contents($path, "<?php\\nreturn " . var_export($artifact, true) . ";\\n");`,
        shardPath,
        host,
        routeFixture,
      ]);
      if (inject.exitCode !== 0) {
        throw new Error(`failed to install redirect route fixture: ${inject.stderr.toString()}`);
      }
    };
    await injectHostRedirect();

    const pageRequests = [
      { path: "/", status: 200, body: "report.pdf", cacheShape: null },
      { path: "/report.pdf", status: 200, body: "Preview report.pdf", cacheShape: null },
      { path: "/missing", status: 404, body: "Acme lost it", cacheShape: null },
      { path: "/docs/", status: 200, body: "docs", cacheShape: "custom-header" },
    ] as const;
    const terminalRequests = [
      { path: "/missing.css", status: 404, location: null },
      { path: "/host-redirect", status: 302, location: "/host-target" },
      { path: "/compiled", status: 302, location: "/compiled-target" },
      { path: "/artifact/value", status: 302, location: "/artifact-target/value" },
      { path: "/docs", status: 308, location: "/docs/" },
    ] as const;

    await Promise.all(
      pageRequests.map(async (request) => {
        const response = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "DE" },
        });
        expect(response.status, request.path).toBe(request.status);
        expect(response.headers.get("cache-control"), request.path).toContain("public");
        expect(response.headers.get("cache-control"), request.path).not.toContain("no-store");
        expect(response.headers.get("x-cache-shape"), request.path).toBe(request.cacheShape);
        expect(await response.text(), request.path).toContain(request.body);
      }),
    );
    await Promise.all(
      terminalRequests.map(async (request) => {
        const response = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "DE" },
        });
        expect(response.status, request.path).toBe(request.status);
        expect(response.headers.get("location"), request.path).toBe(request.location);
        expect(response.headers.get("cache-control"), request.path).toContain("public");
        expect(response.headers.get("cache-control"), request.path).not.toContain("no-store");
      }),
    );

    await putRoute(trusted, spaceId, "production", {
      version_id: versionId,
      config: {
        policy: {
          rules: [
            {
              match: { pathPattern: "/**", countryNotIn: ["DE"] },
              effect: "deny",
              reasonCode: "geo-block",
            },
          ],
        },
      },
    });
    await injectHostRedirect();

    await Promise.all(
      pageRequests.map(async (request) => {
        // The allowed response is deliberately first: if it were public, a
        // shared edge could replay it before the denied request reached PHP.
        const allowed = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "DE" },
        });
        expect(allowed.status, request.path).toBe(request.status);
        expect(allowed.headers.get("cache-control"), request.path).toBe("private, no-store");
        expect(allowed.headers.get("cdn-cache-control"), request.path).toBe("private, no-store");
        expect(allowed.headers.get("surrogate-control"), request.path).toBe("private, no-store");
        expect(allowed.headers.get("x-cache-shape"), request.path).toBe(request.cacheShape);
        expect(await allowed.text(), request.path).toContain(request.body);

        const denied = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "US" },
        });
        expect(denied.status, request.path).toBe(403);
        expect(denied.headers.get("cache-control"), request.path).toBe("private, no-store");
      }),
    );
    await Promise.all(
      terminalRequests.map(async (request) => {
        // Preserve the same cache-poisoning order for every terminal response.
        const allowed = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "DE" },
        });
        expect(allowed.status, request.path).toBe(request.status);
        expect(allowed.headers.get("location"), request.path).toBe(request.location);
        expect(allowed.headers.get("cache-control"), request.path).toBe("private, no-store");
        expect(allowed.headers.get("cdn-cache-control"), request.path).toBe("private, no-store");
        expect(allowed.headers.get("surrogate-control"), request.path).toBe("private, no-store");

        const denied = await get(trusted, host, request.path, {
          headers: { accept: "text/html", "cf-ipcountry": "US" },
        });
        expect(denied.status, request.path).toBe(403);
        expect(denied.headers.get("location"), request.path).toBeNull();
        expect(denied.headers.get("cache-control"), request.path).toBe("private, no-store");
      }),
    );
  } finally {
    trusted.stop();
  }
});

test("tag-preview HEAD keeps country-dependent responses out of shared caches", async () => {
  const host = "pages-tag-preview-country-cache.test";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    const spaceId = "spc_pages_tag_preview_country_cache";
    const versionId = "ver_pages_tag_preview_country_cache_1";
    const previewPath = "/?spacefast_tag_preview=same-preview-token";
    const publicPolicy = "public, max-age=0, s-maxage=60, must-revalidate";
    const privatePolicy = "private, no-store";

    await deploy(trusted, {
      spaceId,
      versionId,
      files: {
        "index.html":
          '<!doctype html><html><body><script src="/__spacefast/sdk.js"></script></body></html>',
      },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        version_hostnames: [],
      },
    });

    const publicGet = await get(trusted, host, previewPath, {
      headers: { "cf-ipcountry": "DE" },
    });
    expect(publicGet.status).toBe(200);
    expectCachePolicy(publicGet, publicPolicy);
    expect(await publicGet.text()).toContain("/__spacefast/sdk.js?preview=same-preview-token");

    const publicHead = await get(trusted, host, previewPath, {
      method: "HEAD",
      headers: { "cf-ipcountry": "DE" },
    });
    expect(publicHead.status).toBe(200);
    expectCachePolicy(publicHead, publicPolicy);
    expect(await publicHead.text()).toBe("");

    await putRoute(trusted, spaceId, "production", {
      version_id: versionId,
      config: {
        policy: {
          rules: [
            {
              match: { pathPattern: "/**", countryNotIn: ["DE"] },
              effect: "deny",
              reasonCode: "geo-block",
            },
          ],
        },
      },
    });

    const allowedGet = await get(trusted, host, previewPath, {
      headers: { "cf-ipcountry": "DE" },
    });
    expect(allowedGet.status).toBe(200);
    expectCachePolicy(allowedGet, privatePolicy);
    expect(await allowedGet.text()).toContain("/__spacefast/sdk.js?preview=same-preview-token");

    // Keep the cache-poisoning order and URL exact: an allowed HEAD must not
    // become a public shared-cache entry before the denied request reaches PHP.
    const allowedHead = await get(trusted, host, previewPath, {
      method: "HEAD",
      headers: { "cf-ipcountry": "DE" },
    });
    expect(allowedHead.status).toBe(200);
    expectCachePolicy(allowedHead, privatePolicy);
    expect(await allowedHead.text()).toBe("");

    const deniedHead = await get(trusted, host, previewPath, {
      method: "HEAD",
      headers: { "cf-ipcountry": "US" },
    });
    expect(deniedHead.status).toBe(403);
    expect(deniedHead.headers.get("cache-control")).toBe(privatePolicy);
    expect(await deniedHead.text()).toBe("");
  } finally {
    trusted.stop();
  }
});

test("generated noindex robots inherit private access caching without changing public defaults", async () => {
  const host = "pages-robots-country-cache.test";
  const trusted = await startRuntime({ env: { SPACEFAST_TRUSTED_EDGE_HEADERS: "1" } });
  try {
    const spaceId = "spc_pages_robots_country_cache";
    const versionId = "ver_pages_robots_country_cache_1";
    await deploy(trusted, {
      spaceId,
      versionId,
      files: { "index.html": "<h1>robots cache</h1>\n" },
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: [host],
        noindex_production_hostnames: [host],
        version_hostnames: [],
      },
    });

    const publicRobots = await get(trusted, host, "/robots.txt", {
      headers: { "cf-ipcountry": "DE" },
    });
    expect(publicRobots.status).toBe(200);
    expect(publicRobots.headers.get("cache-control")).toBe(
      "public, max-age=0, s-maxage=300, must-revalidate",
    );
    expect(publicRobots.headers.get("cdn-cache-control")).toBeNull();
    expect(publicRobots.headers.get("surrogate-control")).toBeNull();
    expect(await publicRobots.text()).toBe("User-agent: *\nDisallow: /\n");

    await putRoute(trusted, spaceId, "production", {
      version_id: versionId,
      config: {
        policy: {
          rules: [
            {
              match: { pathPattern: "/robots.txt", countryNotIn: ["DE"] },
              effect: "deny",
              reasonCode: "geo-block",
            },
          ],
        },
      },
    });

    // Deliberately fetch the allowed variant first: a public response could be
    // replayed by a shared cache before the denied request reaches PHP.
    const allowed = await get(trusted, host, "/robots.txt", {
      headers: { "cf-ipcountry": "DE" },
    });
    expect(allowed.status).toBe(200);
    expect(allowed.headers.get("cache-control")).toBe("private, no-store");
    expect(allowed.headers.get("cdn-cache-control")).toBe("private, no-store");
    expect(allowed.headers.get("surrogate-control")).toBe("private, no-store");
    expect(await allowed.text()).toBe("User-agent: *\nDisallow: /\n");

    const denied = await get(trusted, host, "/robots.txt", {
      headers: { "cf-ipcountry": "US" },
    });
    expect(denied.status).toBe(403);
    expect(denied.headers.get("cache-control")).toBe("private, no-store");
    expect(denied.headers.get("cdn-cache-control")).toBeNull();
    expect(denied.headers.get("surrogate-control")).toBeNull();
  } finally {
    trusted.stop();
  }
});

test("engine-owned fault pages ignore site artifacts", async () => {
  await deployPages("pages-fault.test", "spc_pages_fault", "ver_pages_fault_1");
  await api(
    rt,
    "PUT",
    "/__spacefast/api.php/spaces/spc_pages_fault/tombstones",
    "update_tombstones",
    { space_id: "spc_pages_fault" },
    { hostnames: ["pages-fault-tomb.test"], reason: "tenant_suspended" },
  );
  const response = await get(rt, "pages-fault-tomb.test", "/", {
    headers: { Accept: "text/html" },
  });
  expect(response.status).toBe(402);
  const html = await response.text();
  expect(html).toContain("This space is paused");
  expect(html).toContain("Need help?");
  expect(html).toContain('@font-face{font-family:"Merriweather"');
  expect(html).toContain('@font-face{font-family:"Haskoy"');
  expect(html).not.toContain("Best way to share what your agent made");
  expect(html).not.toContain("Acme");

  const font = await get(
    rt,
    "pages-fault-tomb.test",
    "/__spacefast/pages/fonts/haskoy-latin-variable.woff2",
  );
  expect(font.status).toBe(200);
  expect(font.headers.get("content-type")).toBe("font/woff2");
  expect(font.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect((await font.arrayBuffer()).byteLength).toBeGreaterThan(50_000);

  await Promise.all(
    [
      "/__SPACEFAST/pages/fonts/haskoy-latin-variable.woff2",
      "/__spacefast/pages/fonts%2Fhaskoy-latin-variable.woff2",
    ].map(async (alias) => {
      const rejected = await get(rt, "pages-fault-tomb.test", alias);
      expect(rejected.status).toBe(403);
      expect(await rejected.text()).toBe("Forbidden.\n");
    }),
  );
});

test("CSAM stays byte-identical to undeployed for every negotiated representation", async () => {
  await deployPages("pages-csam.test", "spc_pages_csam", "ver_pages_csam_1");
  await api(
    rt,
    "PUT",
    "/__spacefast/api.php/spaces/spc_pages_csam/tombstones",
    "update_tombstones",
    { space_id: "spc_pages_csam" },
    { hostnames: ["pages-csam-tomb.test"], category: "csam" },
  );
  await Promise.all(
    ["text/html", "application/json", "text/plain"].map(async (accept) => {
      const [csam, undeployed] = await Promise.all([
        get(rt, "pages-csam-tomb.test", "/", { headers: { Accept: accept } }),
        get(rt, "never-deployed.test", "/", { headers: { Accept: accept } }),
      ]);
      expect(csam.status).toBe(undeployed.status);
      expect(await csam.text()).toBe(await undeployed.text());
    }),
  );
});

test("finalize rejects malformed page artifact keys", async () => {
  const response = await finalizeRaw(
    rt,
    "spc_pages_bad",
    "ver_pages_bad_1",
    { "index.html": "ok" },
    { page_artifacts: { "../escape": "<html></html>" } },
  );
  expect(response.status).toBe(422);
  expect((await response.json()).error?.code).toBe("invalid_page_artifacts");
});
