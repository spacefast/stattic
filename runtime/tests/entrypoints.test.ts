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
    copyFileSync(
      path.resolve(import.meta.dir, "../engine/entrypoints/prepend.php"),
      path.join(engineRoot, "entrypoints/prepend.php"),
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
    copyFileSync(
      path.resolve(import.meta.dir, "../engine/entrypoints/prepend.php"),
      path.join(engineRoot, "entrypoints/prepend.php"),
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
