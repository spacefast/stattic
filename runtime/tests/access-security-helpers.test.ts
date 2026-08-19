import { expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import path from "node:path";

import { PHP_BINARY } from "./harness.ts";

function phpAccessRulesProbe(code: string) {
  const accessRulesPath = path.resolve(import.meta.dir, "../engine/runtime/access-rules.php");
  return spawnSync(PHP_BINARY, ["-r", `require $argv[1]; ${code}`, accessRulesPath], {
    encoding: "utf8",
  });
}

test("exchange connect-origin overrides require explicit process test mode", () => {
  const probe = phpAccessRulesProbe(
    [
      "putenv('SPACEFAST_ACCESS_EXCHANGE_TEST_CONNECT_ORIGIN=http://attacker.test');",
      "$GLOBALS['SPACEFAST_ATOMIC_DATA']['SPACEFAST_ACCESS_EXCHANGE_TEST_CONNECT_ORIGIN'] = 'http://persistent-attacker.test';",
      "putenv('SPACEFAST_RUNTIME_TEST_MODE');",
      "$production = _stattic_access_test_connect_origin();",
      "putenv('SPACEFAST_RUNTIME_TEST_MODE=1');",
      "$test = _stattic_access_test_connect_origin();",
      "echo json_encode([$production, $test]);",
    ].join(" "),
  );
  expect(probe.status).toBe(0);
  expect(probe.stderr).toBe("");
  expect(JSON.parse(probe.stdout)).toEqual(["", "http://attacker.test"]);
});

test("logout GET accepts only a top-level same-origin document navigation", () => {
  const probe = phpAccessRulesProbe(
    [
      "$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';",
      "$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';",
      "$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';",
      "$document = _stattic_access_logout_request_allowed('GET', 'private.example.test');",
      "$_SERVER['HTTP_SEC_FETCH_MODE'] = 'no-cors';",
      "$_SERVER['HTTP_SEC_FETCH_DEST'] = 'image';",
      "$image = _stattic_access_logout_request_allowed('GET', 'private.example.test');",
      "echo json_encode([$document, $image]);",
    ].join(" "),
  );
  expect(probe.status).toBe(0);
  expect(probe.stderr).toBe("");
  expect(JSON.parse(probe.stdout)).toEqual([true, false]);
});
