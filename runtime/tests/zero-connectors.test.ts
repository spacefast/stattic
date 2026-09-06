import { expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, realpathSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { PHP_BINARY } from "./harness.ts";

test("connector broker credentials and visitor identity stay in the runtime environment", () => {
  const probe = spawnSync(
    PHP_BINARY,
    [
      "-r",
      `
    require $argv[1];
    define('SPACEFAST_API_BASE_URL', 'https://api.spacefast.test');
    $identity = _stattic_zero_service_identity(['auth' => ['isAuthenticated' => true, 'userId' => 'alias:alice', 'subject' => 'visitor:alice', 'email' => 'alice@example.test', 'emailVerified' => true], 'context' => ['spaceId' => 'spc_test', 'versionId' => 'ver_test']]);
    $guest = _stattic_zero_service_identity(['auth' => ['isAuthenticated' => false, 'userId' => 'guest:alice']]);
    echo json_encode(['visitor' => $identity['visitor'], 'guest' => $guest['visitor'], 'env' => _stattic_service_broker_env($identity, ['connectors' => ['token' => 'test-version-token']])]);
  `,
      path.resolve(import.meta.dir, "../engine/runtime/zero.php"),
    ],
    { encoding: "utf8" },
  );
  expect(probe.status).toBe(0);
  expect(probe.stderr).toBe("");
  const result = JSON.parse(probe.stdout);
  expect(result.visitor).toEqual({
    subject: "visitor:alice",
    email: "alice@example.test",
    emailVerified: true,
  });
  expect(result.guest).toBeNull();
  expect(result.env).toMatchObject({
    SPACEFAST_SERVICE_CONNECTORS_URL: "https://api.spacefast.test/v1/runtime/connectors/calls",
    SPACEFAST_ZERO_CONNECTORS_TOKEN: "test-version-token",
    SPACEFAST_SERVICE_CONNECTORS_TOKEN: "test-version-token",
    SPACEFAST_SERVICE_CONNECTORS_VISITOR: JSON.stringify(result.visitor),
    SPACEFAST_SERVICE_SPACE_ID: "spc_test",
    SPACEFAST_SERVICE_VERSION_ID: "ver_test",
  });
});

test("connector states survive the PHP query and mutation envelopes", () => {
  const state = {
    code: "approval_pending",
    message: "This action is waiting for approval.",
    runId: "run_test",
    role: "tracker",
  };
  for (const [op, field] of [
    ["query.run", "data"],
    ["mutation.run", "result"],
  ]) {
    const probe = spawnSync(
      PHP_BINARY,
      [
        "-r",
        'require $argv[1]; _stattic_zero_send_run_frame($argv[2], "issues", [], ["status" => 200], $argv[3]);',
        path.resolve(import.meta.dir, "../engine/runtime/zero.php"),
        op,
        JSON.stringify({ __connector: state }),
      ],
      { encoding: "utf8" },
    );
    expect(probe.status).toBe(0);
    expect(probe.stderr).toBe("");
    expect(JSON.parse(probe.stdout)).toMatchObject({ ok: true, [field]: { __connector: state } });
  }
});

test("finalize persists the connector credential outside tenant variables", () => {
  const directory = realpathSync(mkdtempSync(path.join(os.tmpdir(), "zero-connectors-config-")));
  try {
    const versionRoot = path.join(directory, ".stattic/storage/spaces/spc_test/versions/ver_test");
    mkdirSync(versionRoot, { recursive: true });
    const probe = spawnSync(
      PHP_BINARY,
      [
        "-r",
        `require $argv[1]; require $argv[2];
      _stattic_access_private_root($argv[3]);
      _stattic_runtime_write_zero_config_artifact($argv[3], ['connectors' => ['token' => 'version-private-token'], 'variableValues' => ['APP_NAME' => 'example']]);
      $config = _stattic_zero_runtime_config($argv[3]);
      echo json_encode(['connectors' => $config['connectors'], 'variables' => $config['variableValues']]);`,
        path.resolve(import.meta.dir, "../engine/admin/management.php"),
        path.resolve(import.meta.dir, "../engine/runtime/zero.php"),
        versionRoot,
      ],
      { encoding: "utf8" },
    );
    expect(probe.status).toBe(0);
    expect(probe.stderr).toBe("");
    expect(JSON.parse(probe.stdout)).toEqual({
      connectors: { token: "version-private-token" },
      variables: { APP_NAME: "example" },
    });
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
