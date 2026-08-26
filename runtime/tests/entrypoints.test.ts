// The routing table under /__spacefast/ is generated from the engine manifest
// (scripts/check-runtime-entrypoints.mjs). This suite is the behavioral half of
// that contract: every path the generator routes must really execute its own
// script on the wp.cloud request path — custom-redirects.php returning so the
// web server serves the file — and every path must have a probe, so the codegen
// can never outrun the coverage.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { copyFileSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { writeActiveReleasePointer } from "./active-release.ts";
import { ENTRYPOINT_PATHS, ENTRYPOINT_PROBES } from "./entrypoint-probes.ts";
import { startRuntime, type Runtime } from "./harness.ts";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
}, 30000);

afterAll(() => rt?.stop());

test("every manifest entrypoint carries its own behavioral probe", () => {
  expect(Object.keys(ENTRYPOINT_PROBES).toSorted()).toEqual(ENTRYPOINT_PATHS);
});

test("wp.cloud config is decryptable before a clean route enters the runtime", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wpcloud-config-"));
  try {
    const engineRoot = path.join(root, ".stattic/releases/test/engine");
    mkdirSync(path.join(engineRoot, "shared"), { recursive: true });
    mkdirSync(path.join(engineRoot, "entrypoints"), { recursive: true });
    // The gate reads the real context.php: it needs the generated entrypoint
    // table and _stattic_request_uri_path() from it, so the fixture defers to
    // the engine's own copy rather than restating either.
    writeFileSync(
      path.join(engineRoot, "shared/context.php"),
      `<?php require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/context.php"))};`,
    );
    writeFileSync(
      path.join(engineRoot, "init.php"),
      '<?php echo json_encode(["dbPassword" => defined("DB_PASSWORD") ? DB_PASSWORD : null]);',
    );
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        "putenv('DB_PASSWORD=encrypted-data-key');",
        `$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(root)};`,
        "$_SERVER['SCRIPT_FILENAME'] = '/scripts/wordpress.php';",
        "$_SERVER['REQUEST_METHOD'] = 'GET';",
        "$_SERVER['REQUEST_URI'] = '/api/echo';",
        "$_SERVER['HTTP_HOST'] = 'example.test';",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", driver],
      stdout: "pipe",
      stderr: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({ dbPassword: "encrypted-data-key" });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("the content admin gate loads persistent config before it builds frame policy", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-content-admin-config-"));
  try {
    const sharedRoot = path.join(root, ".stattic/releases/test/engine/shared");
    mkdirSync(sharedRoot, { recursive: true });
    writeFileSync(
      path.join(sharedRoot, "bootstrap-config.php"),
      "<?php define('SPACEFAST_DASHBOARD_ORIGIN', 'https://my.sf.localhost');",
    );
    writeFileSync(
      path.join(sharedRoot, "context.php"),
      `<?php
function _stattic_dashboard_origin(): string {
  $GLOBALS['dashboard_config_loaded'] = defined('SPACEFAST_DASHBOARD_ORIGIN');
  return defined('SPACEFAST_DASHBOARD_ORIGIN') ? SPACEFAST_DASHBOARD_ORIGIN : '';
}
function _stattic_normalize_hostname(string $host): string { return $host; }
function _stattic_content_admin_cookie_name(): string { return 'spacefast_content_admin'; }
function _stattic_problem_response(int $status, string $code, string $message): never { exit($status); }
`,
    );
    writeFileSync(path.join(sharedRoot, "storage.php"), "<?php");
    writeFileSync(
      path.join(sharedRoot, "content-admin.php"),
      `<?php
function _stattic_content_admin_verify_session(string $root, string $token, string $host): ?array {
  return [
    'user_id' => 57,
    'space_id' => 'spc_test',
    'frame_origin' => 'https://my.sf.localhost',
  ];
}
`,
    );
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        `$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(root)};`,
        `$_SERVER['SCRIPT_FILENAME'] = ${JSON.stringify(path.join(root, "wp-admin/edit.php"))};`,
        "$_SERVER['REQUEST_METHOD'] = 'GET';",
        "$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';",
        "$_SERVER['HTTP_HOST'] = 'space.example';",
        "$_COOKIE['spacefast_content_admin'] = 'valid-session';",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
        "echo json_encode([",
        "  'dashboard_config_loaded' => $GLOBALS['dashboard_config_loaded'] ?? false,",
        "  'user_id' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null,",
        "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
        "  'frame_origin' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] ?? null,",
        "]);",
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", driver],
      stdout: "pipe",
      stderr: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({
      dashboard_config_loaded: true,
      user_id: 57,
      space_id: "spc_test",
      frame_origin: "https://my.sf.localhost",
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("runtime config and dashboard origin recover after the provider class loads", () => {
  const script = String.raw`
require $argv[1];
require $argv[2];
$before = _stattic_dashboard_origin();
class Atomic_Persistent_Data implements IteratorAggregate {
  public function getIterator(): Traversable {
    return new ArrayIterator(['SPACEFAST_DASHBOARD_ORIGIN' => 'https://my.sf.localhost']);
  }
}
_stattic_runtime_bootstrap_config();
echo json_encode([
  'before' => $before,
  'after' => _stattic_dashboard_origin(),
]);
`;
  const result = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      script,
      path.resolve(import.meta.dir, "../engine/shared/bootstrap-config.php"),
      path.resolve(import.meta.dir, "../engine/shared/context.php"),
    ],
    stdout: "pipe",
    stderr: "pipe",
  });

  expect(result.exitCode, result.stderr.toString()).toBe(0);
  expect(JSON.parse(result.stdout.toString())).toEqual({
    before: "",
    after: "https://my.sf.localhost",
  });
});

test("a CLI process with no request passes through custom-redirects unserved", () => {
  // The purge worker (and wp-cli) load WordPress from the CLI, which includes
  // custom-redirects.php. Classifying that process as a visitor request for '/'
  // serves the space and exits the tool mid-run — the bug that left engine
  // purge records queued forever. The contract: no REQUEST_METHOD, no serving.
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-cli-guard-"));
  try {
    const engineRoot = path.join(root, ".stattic/releases/test/engine");
    mkdirSync(path.join(engineRoot, "shared"), { recursive: true });
    mkdirSync(path.join(engineRoot, "entrypoints"), { recursive: true });
    writeFileSync(
      path.join(engineRoot, "shared/context.php"),
      `<?php require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/context.php"))};`,
    );
    writeFileSync(path.join(engineRoot, "init.php"), '<?php echo "SERVED"; exit(0);');
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
        "echo 'CLI_TOOL_RAN';",
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", driver],
      stdout: "pipe",
      stderr: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(result.stdout.toString()).toBe("CLI_TOOL_RAN");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("custom-redirects passes a nonce-bound FPM readiness probe through the private namespace", async () => {
  const nonce = "a".repeat(32);
  const publicPath = `/__spacefast/php-fpm-readiness-${nonce}.php`;
  const probePath = path.join(rt.root, publicPath);
  mkdirSync(path.dirname(probePath), { recursive: true });
  copyFileSync(path.resolve(import.meta.dir, "../php-fpm-readiness.php"), probePath);

  const response = await fetch(`${rt.baseUrl}${publicPath}?nonce=${nonce}`);

  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toContain("application/json");
  expect(await response.json()).toEqual({
    nonce,
    php_version: expect.any(String),
    php_version_id: expect.any(Number),
    php_sapi: expect.any(String),
  });
});

test("the provider gate refuses unmanaged WordPress entrypoints before WordPress runs", async () => {
  for (const pathname of [
    "/wp-login.php",
    "/wp-login.php/continue",
    "/xmlrpc.php",
    "/wp-comments-post.php",
    "/wp-content/plugins/example/direct.php",
  ]) {
    const response = await fetch(`${rt.baseUrl}${pathname}`);
    expect(response.status, pathname).toBe(404);
    expect(response.headers.get("cache-control"), pathname).toBe("private, no-store");
    expect(await response.text(), pathname).toBe("Not Found");
  }
});

test("the loader restores full PHP error logging without leaking diagnostics to stdout", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-error-reporting-"));
  try {
    const errorLog = path.join(root, "php-error.log");
    const missingPath = path.join(root, "warning-probe-missing.txt");
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        `require ${JSON.stringify(path.resolve(import.meta.dir, "../custom-redirects.php"))};`,
        `file_get_contents(${JSON.stringify(missingPath)});`,
        "echo json_encode([",
        "    'all' => error_reporting() === E_ALL,",
        "    'logging' => filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOL),",
        "    'displaying' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL),",
        "]);",
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: [
        "php",
        "-d",
        "auto_prepend_file=",
        "-d",
        "error_reporting=0",
        "-d",
        "log_errors=0",
        "-d",
        "display_errors=1",
        "-d",
        `error_log=${errorLog}`,
        driver,
      ],
      stdout: "pipe",
      stderr: "pipe",
    });

    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({
      all: true,
      logging: true,
      displaying: false,
    });
    expect(result.stderr.toString()).toBe("");
    expect(readFileSync(errorLog, "utf8")).toContain(missingPath);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

for (const entrypointPath of ENTRYPOINT_PATHS) {
  test(`${entrypointPath} runs its own script through the custom-redirects gate`, async () => {
    const probe = ENTRYPOINT_PROBES[entrypointPath];
    if (probe === undefined) throw new Error(`no probe declared for ${entrypointPath}`);
    await probe.verify(await probe.request(rt));
  });
}
