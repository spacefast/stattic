// Native crons, engine side: the two CLI entries a wp.cloud crontab command
// runs on the box.
//
// What this suite holds, and only this: the seams the CLI lane adds over the
// visitor lane it reuses — key -> path resolution out of the active version's
// crons manifest, the two headers the dispatcher asserts, the exit code the
// provider's cron-results webhook keys on, and the strip that makes
// `x-spacefast-cron` unforgeable from the wire. It does NOT re-prove PHP
// Functions execution, the jail, or the sf_* helpers: php-functions.test.ts
// owns those, and this lane reaches them through the same _sf_serve_fast() call
// engine/init.php makes.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

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

const HOST = "crons.test";
const SPACE = "spc_crons";
const VERSION = "ver_crons_1";
const EMPTY_HOST = "crons-empty.test";
const EMPTY_SPACE = "spc_crons_empty";
const EMPTY_VERSION = "ver_crons_empty_1";
const CRON_SECRET = "sk_cron_fixture_secret";
const PREPEND = "cron-cli-prepend.php";

// Reports the request context a cron handler is supposed to be able to branch
// on. Both names are request params, never process environment, so the tenant
// prelude's env scrub does not (and must not) reach them.
const REPORT_PHP = `<?php
sf_json([
    'cron' => $_SERVER['HTTP_X_SPACEFAST_CRON'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
]);
`;

const FAIL_PHP = `<?php
http_response_code(500);
echo "the job failed\\n";
`;

const FATAL_PHP = `<?php
trigger_error("the job crashed", E_USER_ERROR);
`;

const BODY_PHP = `<?php
sf_json(['body' => sf_body()]);
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
    { cwd: rt.root, env: { PATH: process.env.PATH, HOME: process.env.HOME }, stdio: "pipe" },
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
    exitCode: (code as number | null) ?? 1,
    stdout,
    stderr,
  }));
}

function receipt(stderr: string): Record<string, unknown> {
  const line = stderr
    .split("\n")
    .find((candidate) => candidate.startsWith("spacefast-invoke-result: "));
  if (line === undefined) throw new Error(`no receipt line in stderr:\n${stderr}`);
  return JSON.parse(line.slice("spacefast-invoke-result: ".length)) as Record<string, unknown>;
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
      "functions/cron/report.php": REPORT_PHP,
      "functions/cron/fail.php": FAIL_PHP,
      "functions/cron/fatal.php": FATAL_PHP,
      "functions/cron/body.php": BODY_PHP,
      "sf.jsonc": JSON.stringify({
        crons: [
          { path: "/cron/report", schedule: "0 7 * * *" },
          { path: "/cron/fail", schedule: "*/5 * * * *" },
          { path: "/cron/fatal", schedule: "hourly" },
        ],
      }),
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Crons" }, "live_and_all_versions"),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
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

  expect(result.exitCode).toBe(0);
  expect(receipt(result.stderr)).toEqual({ method: "GET", path: "/cron/report", status: 200 });

  const seen = JSON.parse(result.stdout) as { cron: string; authorization: string };
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
  const fatal = await runCli("cron.php", [`--host=${HOST}`, "--key=cron-fatal"]);
  expect(fatal.exitCode).toBe(1);
  expect(receipt(fatal.stderr)).toMatchObject({ path: "/cron/fatal", status: 500 });
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
  const result = await runCli("invoke.php", [`--host=${HOST}`, "--path=/index.html"]);
  expect(result.exitCode).toBe(0);
  expect(result.stdout).toBe("<h1>static home</h1>\n");
  expect(receipt(result.stderr)).toMatchObject({ path: "/index.html", status: 200 });

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
  expect(body.exitCode).toBe(0);
  expect(JSON.parse(body.stdout)).toEqual({ body: { from: "stdin" } });
});

test("a visitor cannot present the cron header", async () => {
  const response = await get(rt, HOST, "/cron/report", {
    headers: { "x-spacefast-cron": "report.0.forged" },
  });
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject({ cron: null });
});
