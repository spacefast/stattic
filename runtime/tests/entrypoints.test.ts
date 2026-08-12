// The routing table under /__spacefast/ is generated from the engine manifest
// (scripts/check-runtime-entrypoints.mjs). This suite is the behavioral half of
// that contract: every path the generator routes must really execute its own
// script on the wp.cloud request path — custom-redirects.php returning so the
// web server serves the file — and every path must have a probe, so the codegen
// can never outrun the coverage.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { copyFileSync, mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

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
    mkdirSync(path.join(root, ".stattic/engine/shared"), { recursive: true });
    // The gate reads the real context.php: it needs the generated entrypoint
    // table and _stattic_request_uri_path() from it, so the fixture defers to
    // the engine's own copy rather than restating either.
    writeFileSync(
      path.join(root, ".stattic/engine/shared/context.php"),
      `<?php require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/context.php"))};`,
    );
    writeFileSync(
      path.join(root, ".stattic/engine/init.php"),
      '<?php echo json_encode(["dbPassword" => defined("DB_PASSWORD") ? DB_PASSWORD : null]);',
    );
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

for (const entrypointPath of ENTRYPOINT_PATHS) {
  test(`${entrypointPath} runs its own script through the custom-redirects gate`, async () => {
    const probe = ENTRYPOINT_PROBES[entrypointPath];
    if (probe === undefined) throw new Error(`no probe declared for ${entrypointPath}`);
    await probe.verify(await probe.request(rt));
  });
}
