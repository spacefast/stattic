import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { existsSync } from "node:fs";
import path from "node:path";
import { gzipSync } from "node:zlib";

import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";

const HOST = "phpfx.test";
const SPACE = "spc_phpfx";
const VERSION = "ver_phpfx_1";
const HANDLER = `<?php
file_put_contents(__DIR__ . '/handler-executed', 'executed');
sf_json(['rawHeaders' => getallheaders(), 'rawCookie' => getenv('HTTP_COOKIE')]);
`;
let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime({ phpBinary: process.env.SPACEFAST_REAL_PHP ?? "php" });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": "<h1>static home</h1>\n",
      "functions/hello.php": HANDLER,
      "functions/hello.php.gz": gzipSync(Buffer.from(HANDLER)),
      "functions/[id].php": "<?php echo 'never executed';\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "PHP Functions" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("HTTP PHP handlers refuse execution before tenant code can read raw credentials", async () => {
  for (const request of [
    { method: "GET" },
    {
      method: "POST",
      headers: {
        cookie: "__Host-spacefast_session=platform-cookie; app=customer-cookie",
        "x-sf-authorization": "fixture-platform-token",
      },
      body: "authored handler must not read this body",
    },
  ]) {
    const response = await get(rt, HOST, "/hello", request);
    expect(response.status).toBe(503);
    expect(response.headers.get("content-type")).toBe("application/problem+json");
    expect(response.headers.get("cache-control")).toBe("no-store");
    expect(await response.json()).toMatchObject({ status: 503, code: "tenant_php_unavailable" });
  }
  const head = await get(rt, HOST, "/hello", { method: "HEAD" });
  expect(head.status).toBe(503);
  expect(head.headers.get("cache-control")).toBe("no-store");
  expect(await head.text()).toBe("");
  const sha = createHash("sha256").update(HANDLER).digest("hex");
  expect(
    existsSync(
      path.join(rt.storageRoot, "spaces", SPACE, "blobs", sha.slice(0, 2), "handler-executed"),
    ),
  ).toBe(false);
});

test("PHP source stays hidden while adjacent static content remains available", async () => {
  const source = await get(rt, HOST, "/functions/hello.php");
  expect(source.status).toBe(404);
  const compressedSource = await get(rt, HOST, "/functions/hello.php.gz");
  expect(compressedSource.status).toBe(404);
  const inert = await get(rt, HOST, "/functions/%5Bid%5D.php");
  expect(inert.status).toBe(200);
  expect(inert.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  expect(inert.headers.get("content-disposition")).toBe("attachment");
  expect(await inert.text()).toBe("<?php echo 'never executed';\n");
  const home = await get(rt, HOST, "/");
  expect(home.status).toBe(200);
  expect(await home.text()).toBe("<h1>static home</h1>\n");
});
