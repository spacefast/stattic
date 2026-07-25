import { afterAll, beforeAll, expect, test } from "bun:test";
// Header operations: exact/pattern matching, placeholder expansion, set/remove
// merge semantics, the platform-managed header denylist at finalize, compiled
// Basic-Auth rules, and the platform-managed X-Robots-Tag override guard.
import path from "node:path";

import { deploy, errorCode, finalizeRaw, get, type Runtime, startRuntime } from "./harness.ts";

let rt: Runtime;

const HOST = "headers.test";
const VERSION_HOST = "ver-pin.headers.test";
const IMMUTABLE = "public, max-age=31536000, s-maxage=31536000, immutable";
const REVALIDATE =
  "public, max-age=0, s-maxage=31536000, must-revalidate, stale-while-revalidate=60";
const BASIC_USER = "deploy";
const BASIC_PASSWORD = "open sesame";
const basicHash = Bun.password.hashSync(BASIC_PASSWORD, { algorithm: "bcrypt", cost: 4 });

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_headers",
    versionId: "ver_headers_1",
    files: {
      "index.html": "<h1>headers</h1>\n",
      "about.html": "<h1>about</h1>\n",
      "assets/app.js": "void 0;\n",
      "assets/auth-client-P1Tx-Cyu.js": "void 1;\n",
      "chunks/app-D2mDMWBM.js": "void 2;\n",
      "secure/index.html": "<h1>secure</h1>\n",
    },
    serving: {
      headers_exact: {
        "/about.html": [
          {
            order: 1,
            operations: [
              { kind: "set", name: "X-About", value: "yes" },
              { kind: "set", name: "X-Multi", value: "one" },
              { kind: "set", name: "Cache-Control", value: "no-cache" },
            ],
            headers: { "X-About": "yes", "X-Multi": "one", "Cache-Control": "no-cache" },
          },
          {
            order: 2,
            operations: [
              { kind: "set", name: "X-Multi", value: "two" },
              { kind: "remove", name: "X-About" },
            ],
            headers: { "X-Multi": "two" },
          },
        ],
      },
      headers_pattern: [
        {
          path: "/assets/*",
          regex: "^/assets/(?<file>[^/]+)$",
          order: 3,
          operations: [{ kind: "set", name: "X-Asset", value: "name=:file" }],
          headers: { "X-Asset": "name=:file" },
        },
        {
          path: "/chunks/*",
          regex: "^/chunks/(?<file>[^/]+)$",
          order: 4,
          operations: [{ kind: "set", name: "Cache-Control", value: IMMUTABLE }],
          headers: { "Cache-Control": IMMUTABLE },
        },
        {
          path: "/secure/*",
          regex: "^/secure(/.*)?$",
          order: 5,
          operations: [
            {
              kind: "basicAuth",
              name: "Basic-Auth",
              value: JSON.stringify([{ username: BASIC_USER, passwordHash: basicHash }]),
            },
          ],
          headers: {},
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: "ver_headers_1" }],
    },
  });
});

afterAll(() => rt?.stop());

test("exact header rules apply only to their path", async () => {
  const about = await get(rt, HOST, "/about.html");
  expect(about.status).toBe(200);
  expect(about.headers.get("x-multi")).toBe("one,two");
  expect(about.headers.get("cache-control")).toBe("no-cache");
  expect(about.headers.get("cdn-cache-control")).toBe("no-cache");
  // Set then removed by a later rule.
  expect(about.headers.get("x-about")).toBeNull();

  const index = await get(rt, HOST, "/");
  expect(index.headers.get("x-multi")).toBeNull();
});

test("pattern rules expand placeholders from path captures", async () => {
  const response = await get(rt, HOST, "/assets/app.js");
  expect(response.status).toBe(200);
  expect(response.headers.get("x-asset")).toBe("name=app.js");
});

// Immutable caching is declared, never guessed: bundler-hashed filenames outside
// the guaranteed framework dirs default to browser revalidation, a `_headers`
// Cache-Control rule opts a path in, and version-pinned hosts (the hostname embeds
// the version, so bytes can never change) pin everything non-HTML.
test("hash-looking chunks default to browser revalidation, not filename guessing", async () => {
  const response = await get(rt, HOST, "/assets/auth-client-P1Tx-Cyu.js");
  expect(response.status).toBe(200);
  expect(response.headers.get("cache-control")).toBe(REVALIDATE);
  expect(response.headers.get("cdn-cache-control")).toBe(REVALIDATE);
  expect(response.headers.get("surrogate-control")).toBe(REVALIDATE);
});

test("a _headers Cache-Control rule declares a path immutable", async () => {
  const response = await get(rt, HOST, "/chunks/app-D2mDMWBM.js");
  expect(response.status).toBe(200);
  expect(response.headers.get("cache-control")).toBe(IMMUTABLE);
  expect(response.headers.get("cdn-cache-control")).toBe(IMMUTABLE);
  expect(response.headers.get("surrogate-control")).toBe(IMMUTABLE);
});

test("version-pinned hosts serve every non-HTML file immutable", async () => {
  const asset = await get(rt, VERSION_HOST, "/assets/app.js");
  expect(asset.status).toBe(200);
  expect(asset.headers.get("cache-control")).toBe(IMMUTABLE);
  expect(asset.headers.get("cdn-cache-control")).toBe(IMMUTABLE);

  // HTML stays revalidatable even on pinned hosts: request-time transforms
  // (claim banner, preview overlays) may rewrite the body.
  const html = await get(rt, VERSION_HOST, "/index.html");
  expect(html.status).toBe(200);
  expect(html.headers.get("cache-control")).toBe(REVALIDATE);
});

// The legacy _headers basic-auth enforcement lane is deleted (access-plan §2):
// basicAuth now compiles to file-lane password-acquire rules enforced through
// the ONE access model (covered by access-rules.test.ts, transport "basic").
// The `basicAuth` header op still compiles (the compiler flip is cloud-side),
// but the runtime no longer enforces it via the header lane.

test("finalize rejects platform-managed headers in compiled artifacts", async () => {
  const response = await finalizeRaw(
    rt,
    "spc_headers",
    "ver_headers_bad",
    { "index.html": "<h1>bad</h1>\n" },
    {
      serving: {
        headers_exact: {
          "/index.html": [
            {
              order: 1,
              operations: [{ kind: "set", name: "Set-Cookie", value: "session=stolen" }],
              headers: { "Set-Cookie": "session=stolen" },
            },
          ],
        },
      },
    },
  );
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("runtime_artifact_validation_failed");
});

// Artifact-lane redirects/rewrites and edge cache policy: unconditional rules
// are a pure function of the cache key (host+path+query) and carry the short
// default edge TTL; condition-matched rules are per-visitor and must never
// enter a shared cache.
test("artifact-lane redirects carry an explicit edge cache policy", async () => {
  await deploy(rt, {
    spaceId: "spc_redirect_cache",
    versionId: "ver_redirect_cache_1",
    files: {
      "index.html": "<h1>redirect cache</h1>\n",
      "doc.html": "<h1>doc html</h1>\n",
      "doc.md": "# doc markdown\n",
      "beta-on.html": "<h1>beta</h1>\n",
    },
    serving: {
      redirects_exact: {
        "/beta": [
          {
            destination: "/beta-on.html",
            status: 302,
            action: "redirect",
            conditions: [{ kind: "cookie", values: ["beta"] }],
            order: 1,
          },
        ],
        "/doc": [
          {
            destination: "/doc.md",
            status: 200,
            action: "rewrite",
            force: true,
            conditions: [{ kind: "cookie", values: ["beta"] }],
            order: 2,
          },
        ],
      },
      redirects_pattern: [
        {
          source: "/r/*",
          regex: "^/r/(?<splat>.*)$",
          destination: "/target/:splat",
          status: 302,
          action: "redirect",
          order: 3,
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: ["redirect-cache.test"],
      version_hostnames: [],
    },
  });

  // Unconditional pattern redirect: explicit short default edge policy.
  const pattern = await get(rt, "redirect-cache.test", "/r/abc");
  expect(pattern.status).toBe(302);
  expect(pattern.headers.get("location")).toBe("/target/abc");
  expect(pattern.headers.get("cache-control")).toBe(
    "public, max-age=0, s-maxage=60, must-revalidate",
  );

  // Condition-matched redirect: per-visitor, never stored.
  const conditional = await get(rt, "redirect-cache.test", "/beta", {
    headers: { cookie: "beta=1" },
  });
  expect(conditional.status).toBe(302);
  expect(conditional.headers.get("location")).toBe("/beta-on.html");
  expect(conditional.headers.get("cache-control")).toBe("no-store");

  // Without the cookie the rule does not match and normal serving proceeds.
  const unmatched = await get(rt, "redirect-cache.test", "/beta");
  expect(unmatched.status).not.toBe(302);
});

test("condition-matched rewrites are never shared-cacheable", async () => {
  // With the cookie: the rewrite serves per-visitor bytes — private, no-store
  // on every cache-policy channel.
  const conditional = await get(rt, "redirect-cache.test", "/doc", {
    headers: { cookie: "beta=1" },
  });
  expect(conditional.status).toBe(200);
  expect(await conditional.text()).toBe("# doc markdown\n");
  expect(conditional.headers.get("cache-control")).toBe("private, no-store");
  expect(conditional.headers.get("cdn-cache-control")).toBe("private, no-store");
  expect(conditional.headers.get("surrogate-control")).toBe("private, no-store");

  // Without the cookie the same URL serves the normal file with the normal
  // shared-cache policy.
  const plain = await get(rt, "redirect-cache.test", "/doc");
  expect(plain.status).toBe(200);
  expect(await plain.text()).toBe("<h1>doc html</h1>\n");
  expect(plain.headers.get("cache-control")).toBe(REVALIDATE);
});

test("_headers cannot override X-Robots-Tag on noindex hosts", async () => {
  await deploy(rt, {
    spaceId: "spc_robots_hdr",
    versionId: "ver_robots_hdr_1",
    files: { "index.html": "<h1>robots</h1>\n" },
    serving: {
      headers_exact: {
        "/index.html": [
          {
            order: 1,
            operations: [{ kind: "set", name: "X-Robots-Tag", value: "all" }],
            headers: { "X-Robots-Tag": "all" },
          },
        ],
      },
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: ["indexable.test", "noindex.test"],
      noindex_production_hostnames: ["noindex.test"],
      version_hostnames: [],
    },
  });

  // Indexable host: the user rule applies.
  const indexable = await get(rt, "indexable.test", "/index.html");
  expect(indexable.headers.get("x-robots-tag")).toBe("all");

  // Noindex-classed host: the platform header is the only X-Robots-Tag value.
  const noindex = await get(rt, "noindex.test", "/index.html");
  expect(noindex.headers.get("x-robots-tag")).toBe("noindex, nofollow");
});

// The header artifact carries ONE lane. The parallel `auth` lane is deleted:
// basic-auth compiles to unified access rules (access-rules.test.ts), and
// finalize writes headers.php without an `auth` section — while artifacts
// finalized before the deletion (and imports of archives exported back then)
// still carry one and must keep loading, validating, and serving.
const headersArtifactPath = () =>
  path.join(rt.storageRoot, "spaces", "spc_headers", "versions", "ver_headers_1", "headers.php");

test("finalize writes a headers artifact without an auth lane", () => {
  const result = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      "$a = include $argv[1]; echo json_encode(['auth' => array_key_exists('auth', $a), 'headers' => is_array($a['headers'] ?? null)]);",
      "--",
      headersArtifactPath(),
    ],
  });
  expect(result.exitCode).toBe(0);
  expect(JSON.parse(result.stdout.toString())).toEqual({ auth: false, headers: true });
});

test("a legacy headers artifact with an auth lane still validates and serves", async () => {
  // Rewrite the finalized artifact into the old two-lane format: an `auth`
  // section holding a compiled-credentials basicAuth rule.
  const inject = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      `$path = $argv[1];
$a = include $path;
$a['auth'] = [
    'exact' => ['/secure' => [[
        'operations' => [[
            'kind' => 'basicAuth',
            'name' => 'Basic-Auth',
            'value' => '',
            'credentials' => [['username' => 'deploy', 'passwordHash' => 'legacy-hash']],
        ]],
        'headers' => [],
    ]]],
    'pattern' => [],
];
file_put_contents($path, '<?php return ' . var_export($a, true) . ';');`,
      "--",
      headersArtifactPath(),
    ],
  });
  expect(inject.exitCode).toBe(0);

  // The verify/import validator accepts the legacy section instead of
  // requiring or rejecting it ("ok" means no validation-failure response).
  const validate = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      "require $argv[1]; _stattic_runtime_validate_rule_artifact($argv[2], 'headers'); echo 'ok';",
      "--",
      path.join(import.meta.dir, "../engine/admin/generate.php"),
      headersArtifactPath(),
    ],
  });
  expect(validate.stdout.toString()).toBe("ok");
  expect(validate.exitCode).toBe(0);

  // Serve time reads only the `headers` lane; the legacy key is ignored.
  const about = await get(rt, HOST, "/about.html");
  expect(about.status).toBe(200);
  expect(about.headers.get("x-multi")).toBe("one,two");
});
