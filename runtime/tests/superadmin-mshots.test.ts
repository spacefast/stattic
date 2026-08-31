// The superadmin dashboard ships `functions/mshots.php` as tenant content on
// its own Space, so the shipped file must execute through the `t=php` lane it
// deploys onto. This suite runs THAT file, not a fixture: the regression it
// pins (curl options spread through `...$options`, which renumbers integer
// CURLOPT_* keys and made `curl_setopt_array` throw before any request left
// the box) shipped as a permanent 500 `php_function_error` on every thumbnail.
//
// The lane's own seams (jail, scrub, headers, brokered capabilities) live in
// php-functions.test.ts and are not re-proven here.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import path from "node:path";

import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";

const HOST = "superadmin-mshots.test";
const SPACE = "spc_superadmin_mshots";
const MSHOTS_API_URL = "https://127.0.0.1:9";
const MSHOTS_PHP = readFileSync(
  path.join(import.meta.dir, "../../apps/superadmin/public/functions/mshots.php"),
  "utf8",
).replace("__SPACEFAST_API_URL_BASE64__", Buffer.from(MSHOTS_API_URL).toString("base64"));

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime({
    // An https base nothing listens on: valid to every configuration check the
    // handler runs, unreachable to curl. The resolver call must FAIL AS A
    // TRANSPORT ERROR, which only happens after its per-call curl options
    // (header/POST/body) applied cleanly.
    atomicData: { SPACEFAST_API_BASE_URL: "https://127.0.0.1:9" },
    // Same constraint as php-functions.test.ts: handlers tighten open_basedir,
    // which corrupts PCOV's allocator, so this suite runs the real PHP binary.
    phpBinary: process.env.SPACEFAST_REAL_PHP ?? "php",
  });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: "ver_superadmin_mshots_1",
    metadata: { mode: "website", title: "Superadmin mShots" },
    files: {
      "index.html": "<h1>superadmin</h1>\n",
      "functions/mshots.php": MSHOTS_PHP,
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Superadmin mShots" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("an unreachable resolver answers as the handler's own 503, not an engine 500", async () => {
  const response = await get(rt, HOST, "/mshots?token=header.payload.signature&count=3");
  expect(response.status).toBe(503);
  expect(response.headers.get("content-type")).toBe("text/plain; charset=utf-8");
  expect(await response.text()).toBe("The thumbnail resolver is temporarily unavailable.\n");
});
