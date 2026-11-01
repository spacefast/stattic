// Native crons, engine side: the two CLI entries a wp.cloud crontab command
// runs on the box.
//
// The real Zero runner owns the supported handler fixtures. Authored PHP
// targets must refuse execution through the same visitor dispatch.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { once } from "node:events";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  deploy,
  get,
  PHP_BINARY,
  publicAccessConfig,
  type Runtime,
  RUNTIME_TEST_ATOMIC_PREPEND,
  startRuntime,
  versionRoot,
} from "./harness.ts";
import {
  cachePolicySource,
  cronReportSource,
  usersProtectedSource,
} from "./zero-contract-fixtures.ts";

const HOST = "crons.test";
const SPACE = "spc_crons";
const VERSION = "ver_crons_1";
const EMPTY_HOST = "crons-empty.test";
const EMPTY_SPACE = "spc_crons_empty";
const EMPTY_VERSION = "ver_crons_empty_1";
const CRON_SECRET = "sk_cron_fixture_secret";
const PREPEND = "cron-cli-prepend.php";

const FAIL_SOURCE = `globalThis.__statticZeroResult = JSON.stringify({ status: 500, body: "the job failed\\n" });`;
const BODY_SOURCE = `globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  body: JSON.stringify({ body: globalThis.__statticZeroRequest.bodyBase64 }),
});`;
const DISABLED_PHP = `<?php
file_put_contents(__DIR__ . '/cron-executed', 'executed');
sf_json(['authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null]);
`;

let rt: Runtime;

type CliResult = { exitCode: number; stdout: string; stderr: string };

// One CLI entry, as its own OS process — the whole point of the lane, so the
// suite runs it the way the crontab does rather than in-process. The atomic
// prepend and cwd mirror dispatchCli(): runtime config resolves from getcwd(),
// and a full inherited env moves cwd off rt.root on CI.
function runCli(entrypoint: string, args: string[], stdin = ""): Promise<CliResult> {
  const child = spawn(
    PHP_BINARY,
    [
      "-d",
      "opcache.enable_cli=0",
      "-d",
      `auto_prepend_file=${path.join(rt.root, PREPEND)}`,
      path.join(rt.engineRoot, "entrypoints", entrypoint),
      `--private-root=${rt.storageRoot}`,
      ...args,
    ],
    {
      cwd: rt.root,
      env: {
        PATH: process.env.PATH,
        HOME: process.env.HOME,
        SPACEFAST_RUNTIME_BIN: path.join(rt.engineRoot, "../bin/stattic-runtime"),
      },
      stdio: "pipe",
    },
  );
  let stdout = "";
  let stderr = "";
  child.stdout.on("data", (chunk: Buffer) => {
    stdout += chunk.toString();
  });
  child.stderr.on("data", (chunk: Buffer) => {
    stderr += chunk.toString();
  });
  child.stdin.end(stdin);
  return once(child, "close").then(([code]) => ({
    exitCode: z.number().int().nullable().parse(code) ?? 1,
    stdout,
    stderr,
  }));
}

function receipt(stderr: string) {
  const line = stderr
    .split("\n")
    .find((candidate) => candidate.startsWith("spacefast-invoke-result: "));
  if (line === undefined) throw new Error(`no receipt line in stderr:\n${stderr}`);
  return z
    .object({
      method: z.string(),
      path: z.string(),
      status: z.number().int(),
      engine_revision: z.string(),
      engine_source: z.enum(["site", "chroot"]),
    })
    .parse(JSON.parse(line.slice("spacefast-invoke-result: ".length)));
}

beforeAll(async () => {
  rt = await startRuntime();
  // Coverage and provider prepends can register shutdown work before the CLI
  // entrypoint. Reproduce the dangerous ordering: its warning must not replace
  // a tenant fatal before the receipt derives the exit status.
  writeFileSync(
    path.join(rt.root, PREPEND),
    `<?php
register_shutdown_function(static function (): void {
    trigger_error("prepend cleanup warning", E_USER_WARNING);
});
require ${JSON.stringify(RUNTIME_TEST_ATOMIC_PREPEND)};
`,
  );
  await deploy(rt, {
    spaceId: EMPTY_SPACE,
    versionId: EMPTY_VERSION,
    metadata: { mode: "website", title: "Empty crons" },
    files: { "index.html": "<h1>static home</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        { mode: "website", site_title: "Empty crons" },
        "live_and_all_versions",
      ),
      production_hostnames: [EMPTY_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    metadata: { mode: "website", title: "Crons" },
    files: {
      "index.html": "<h1>static home</h1>\n",
      "functions/cron/php.php": DISABLED_PHP,
      "sf.jsonc": JSON.stringify({
        crons: [
          { path: "/cron/report", schedule: "daily" },
          { path: "/cron/fail", schedule: "12h" },
          { path: "/cron/php", schedule: "hourly" },
        ],
      }),
    },
    activate: {
      route_name: "production",
      config: {
        ...publicAccessConfig({ mode: "website", site_title: "Crons" }, "live_and_all_versions"),
        billing: { emailRecipientsDaily: 20 },
      },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/cache-policy-contract",
          source: cachePolicySource,
          capabilities: { db: false, auth: true },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/users-contract",
          source: usersProtectedSource,
          capabilities: { db: false, auth: true },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/cron/report",
          source: cronReportSource,
          capabilities: { db: false },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/cron/fail",
          source: FAIL_SOURCE,
          capabilities: { db: false },
        },
        {
          execution_mode: "read",
          method: "POST",
          path: "/cron/body",
          source: BODY_SOURCE,
          capabilities: { db: false },
        },
      ],
    },
    // The space's own runtime variables, resolved beside the version at
    // finalize — the document functions-dispatch.php reads `sf-fx-env` from.
    functions: { variableValues: { CRON_SECRET } },
  });
});

afterAll(() => rt?.stop());

// A separate immutable version proves the missing-manifest refusal without
// mutating a catalog after the runtime has cached it.
test("a version that declares no crons refuses the key by name", async () => {
  const result = await runCli("cron.php", [`--host=${EMPTY_HOST}`, "--key=cron-report"]);
  expect(result.exitCode).toBe(3);
  expect(result.stderr).toContain("__spacefast/crons.json");
});

test("a declared cron runs its path and carries both proofs", async () => {
  const before = Math.floor(Date.now() / 60_000);
  const result = await runCli("cron.php", [`--host=${HOST}`, "--key=cron-report"]);
  const after = Math.floor(Date.now() / 60_000);

  expect(result.exitCode, result.stderr + result.stdout).toBe(0);
  // wp.cloud can merge the CLI streams; the receipt must start on its own line.
  expect(result.stdout.endsWith("\n")).toBe(true);
  expect(receipt(result.stderr)).toEqual({
    method: "GET",
    path: "/cron/report",
    status: 200,
    engine_revision: "source-tree",
    engine_source: "site",
  });

  const seen = z
    .object({ cron: z.string(), authorization: z.string() })
    .parse(JSON.parse(result.stdout));
  // The tenant's own secret, which is the half a handler can actually compare.
  expect(seen.authorization).toBe(`Bearer ${CRON_SECRET}`);
  // `<key>.<minute>.<hmac>`: the signature binds the header to this cron and
  // this minute, so a value that escapes cannot be replayed as another.
  const [key, minute, mac] = seen.cron.split(".");
  expect(key).toBe("cron-report");
  expect(Number(minute)).toBeGreaterThanOrEqual(before);
  expect(Number(minute)).toBeLessThanOrEqual(after);
  expect(mac).toMatch(/^[a-f0-9]{64}$/);
});

test("a route that answers 500 exits non-zero", async () => {
  const signingKeyPath = path.join(rt.storageRoot, "runtime", "cron-key.json");
  const signingKeyBefore = readFileSync(signingKeyPath, "utf8");
  const result = await runCli("cron.php", [`--host=${HOST}`, "--key=cron-fail"]);
  expect(result.exitCode).toBe(1);
  expect(receipt(result.stderr)).toMatchObject({ path: "/cron/fail", status: 500 });
  expect(result.stdout).toBe("the job failed\n");
  expect(readFileSync(signingKeyPath, "utf8")).toBe(signingKeyBefore);
});

test("an undeclared key is a named refusal", async () => {
  const result = await runCli("cron.php", [`--host=${HOST}`, "--key=nope"]);
  expect(result.exitCode).toBe(3);
  expect(result.stderr).toContain("no cron named");
});

test("malformed runtime variables fail the invocation closed", async () => {
  const configPath = path.join(versionRoot(rt, SPACE, VERSION), "functions/config.json");
  const configBefore = readFileSync(configPath, "utf8");
  try {
    writeFileSync(configPath, "{not-json\n");
    const result = await runCli("cron.php", [`--host=${HOST}`, "--key=cron-report"]);
    expect(result.exitCode).toBe(1);
    expect(result.stderr).toContain("cron runtime variables are temporarily unavailable");
    expect(result.stdout).toBe("");
  } finally {
    writeFileSync(configPath, configBefore);
  }
});

test("invoke runs any path through the visitor lane and streams the body", async () => {
  const result = await runCli("invoke.php", [`--host=${HOST}`, "--path=/"]);
  expect(result.exitCode, result.stderr + result.stdout).toBe(0);
  expect(result.stdout).toBe("<h1>static home</h1>\n");
  expect(receipt(result.stderr)).toMatchObject({ path: "/", status: 200 });

  const body = await runCli(
    "invoke.php",
    [
      `--host=${HOST}`,
      "--path=/cron/body",
      "--method=POST",
      "--header=content-type: application/json",
    ],
    '{"from":"stdin"}',
  );
  expect(body.exitCode, body.stderr + body.stdout).toBe(0);
  expect(JSON.parse(body.stdout)).toEqual({
    body: Buffer.from('{"from":"stdin"}').toString("base64"),
  });
});

test("declared PHP cron targets refuse execution without a handler side effect", async () => {
  const result = await runCli("cron.php", [`--host=${HOST}`, "--key=cron-php"]);
  expect(result.exitCode).toBe(1);
  expect(receipt(result.stderr)).toMatchObject({ method: "GET", path: "/cron/php", status: 503 });
  expect(JSON.parse(result.stdout)).toMatchObject({ status: 503, code: "tenant_php_unavailable" });
  const sha = createHash("sha256").update(DISABLED_PHP).digest("hex");
  expect(
    existsSync(
      path.join(rt.storageRoot, "spaces", SPACE, "blobs", sha.slice(0, 2), "cron-executed"),
    ),
  ).toBe(false);
});

test("a visitor cannot forge the cron proof sent to a Zero handler", async () => {
  const response = await get(rt, HOST, "/cron/report", {
    headers: { "x-spacefast-cron": "report.0.forged" },
  });
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ cron: null, authorization: null });
});

test("a prepend cleanup warning cannot hide a fatal from the CLI receipt", () => {
  const script = path.join(rt.root, "receipt-fatal.php");
  writeFileSync(
    script,
    `<?php
require ${JSON.stringify(path.join(rt.engineRoot, "runtime/cli-invoke.php"))};
_stattic_cli_arm_receipt(['method' => 'GET', 'path' => '/fatal']);
trigger_error("the job crashed", E_USER_ERROR);
`,
  );
  const result = spawnSync(
    PHP_BINARY,
    ["-d", `auto_prepend_file=${path.join(rt.root, PREPEND)}`, script],
    {
      cwd: rt.root,
      encoding: "utf8",
      env: { PATH: process.env.PATH, HOME: process.env.HOME },
    },
  );
  expect(result.status).toBe(1);
  expect(result.stderr).toContain("prepend cleanup warning");
  expect(receipt(result.stderr)).toMatchObject({ method: "GET", path: "/fatal", status: 500 });
});

test("the shared live-contract Zero endpoints enforce guest auth and no-store responses", async () => {
  const users = await get(rt, HOST, "/users-contract");
  expect(users.status).toBe(401);
  expect(await users.json()).toEqual({ error: "sign_in_required" });
  expect(users.headers.get("cache-control")).toContain("no-store");
  for (const { policy, headers, cacheControl } of [
    { policy: "no-store", headers: {}, cacheControl: "no-store" },
    {
      policy: "private-no-store",
      headers: { cookie: "app=fixture" },
      cacheControl: "private, no-store",
    },
  ]) {
    const response = await get(rt, HOST, `/cache-policy-contract?policy=${policy}`, { headers });
    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ policy, authenticated: false });
    expect(response.headers.get("cache-control")).toBe(cacheControl);
  }
});
