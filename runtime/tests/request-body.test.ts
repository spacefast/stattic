import { expect, test } from "bun:test";
import path from "node:path";

const shared = path.resolve(import.meta.dir, "../engine/shared");

function jsonBody(input: { body: string; declared?: number; limit?: number }) {
  const script = `
require $argv[1] . '/context.php';
require $argv[1] . '/storage.php';
$input = json_decode($argv[2], true);
_stattic_request_body_override($input['body']);
if (isset($input['declared'])) $_SERVER['CONTENT_LENGTH'] = $input['declared'];
$body = isset($input['limit']) ? _stattic_json_body($input['limit']) : _stattic_json_body();
echo json_encode(['status' => 200, 'data' => $body]);
`;
  const result = Bun.spawnSync([
    process.env.PHP_BINARY ?? "php",
    "-r",
    script,
    shared,
    JSON.stringify(input),
  ]);
  expect(result.exitCode).toBe(0);
  expect(result.stderr.toString()).toBe("");
  const output: unknown = JSON.parse(result.stdout.toString());
  return output;
}

test("management JSON bounds declared and actual bytes before decoding", () => {
  const body = '{"value":"ok"}';
  expect(jsonBody({ body, limit: body.length })).toEqual({ status: 200, data: { value: "ok" } });
  expect(jsonBody({ body, declared: body.length + 1, limit: body.length })).toMatchObject({
    status: 413,
    code: "runtime_request_body_too_large",
    details: { limit: body.length },
  });
  expect(jsonBody({ body, limit: body.length - 1 })).toMatchObject({
    status: 413,
    code: "runtime_request_body_too_large",
    details: { limit: body.length - 1 },
  });
  // The default uses the already-published PHP lane budget, even for empty bodies.
  expect(jsonBody({ body: "", declared: 104_857_601 })).toMatchObject({
    status: 413,
    code: "runtime_request_body_too_large",
    details: { limit: 104_857_600 },
  });
});
