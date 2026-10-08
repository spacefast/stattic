import { afterAll, beforeAll, expect, test } from "bun:test";
import { writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  deploy,
  managementToken,
  PHP_BINARY,
  publicAccessConfig,
  type Runtime,
  RUNTIME_TEST_ATOMIC_PREPEND,
  startRuntime,
} from "./harness.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const classifier = path.join(repoRoot, "runtime/engine/shared/content-request.php");

// Content model data reads and writes execute as Abilities through Zero. This
// endpoint is a control-plane lane only: every request it accepts names a
// management action, so there is no anonymous shape to classify.
async function classify(requests: Record<string, { operation?: string }>) {
  const script = String.raw`
require $argv[1];
$requests = json_decode($argv[2], true);
$out = [];
foreach ($requests as $name => $request) {
  $out[$name] = _stattic_content_management_action($request);
}
echo json_encode($out);
`;
  const process = Bun.spawn(["php", "-r", script, classifier, JSON.stringify(requests)], {
    cwd: repoRoot,
    stderr: "pipe",
    stdout: "pipe",
  });
  const [exitCode, stdout, stderr] = await Promise.all([
    process.exited,
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
  ]);
  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  // SAFETY: the classifier returns one entry per request, each the management
  // action string or PHP false, which is exactly this union.
  return JSON.parse(stdout) as Record<string, string | false>;
}

test("every content request names a management action before WordPress boots", async () => {
  expect(
    await classify({
      admin: { operation: "admin.launch" },
      rest: { operation: "rest.request" },
      knowledge: { operation: "knowledge.request" },
      designSystem: { operation: "design-system.mutate" },
      designRead: { operation: "design-system.read" },
      authorization: { operation: "authorization.apply" },
      stage: { operation: "model.stage" },
      activate: { operation: "model.activate" },
      reconcile: { operation: "source.reconcile" },
      acknowledge: { operation: "source.acknowledge" },
      materialize: { operation: "source.materialize" },
      invalid: { operation: "other" },
      absent: {},
    }),
  ).toEqual({
    admin: "content.admin.launch",
    rest: "content.rest.request",
    knowledge: "content.knowledge.request",
    designSystem: "content.design-system.mutate",
    designRead: "content.design-system.read",
    authorization: "content.authorization.apply",
    stage: "content.model.stage",
    activate: "content.model.activate",
    reconcile: "content.source.reconcile",
    acknowledge: "content.source.acknowledge",
    materialize: "content.source.materialize",
    invalid: false,
    absent: false,
  });
});

// The content endpoint answers only POST, and the provider edge is method-blind
// (it keys a stored response on host+path+query alone). Anything this endpoint
// advertised as publicly storable would be replayed to every later GET of the
// same URL, so the whole lane has to come out private no-store.
const CONTENT_HOST = "content-cache.test";
const CONTENT_SPACE = "spc_content_cache";

let runtime: Runtime;

beforeAll(async () => {
  runtime = await startRuntime();
  await deploy(runtime, {
    spaceId: CONTENT_SPACE,
    versionId: "ver_content_cache",
    files: { "index.html": "open" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({}, "live_and_all_versions"),
      production_hostnames: [CONTENT_HOST],
      version_hostnames: [],
    },
  });
  // The kernel lives in WordPress; this lane's contract is the HTTP envelope it
  // wraps a kernel answer in, so the fixture stands in for wp-load and returns
  // one.
  writeFileSync(
    path.join(runtime.root, "wp-load.php"),
    [
      "<?php",
      "function spacefast_content_handle_request(array $request): array {",
      "  return ['results' => ['posts' => ['items' => [], 'total' => 0]]];",
      "}",
      "",
    ].join("\n"),
  );
}, 30000);

afterAll(() => runtime?.stop());

test("a managed content answer is never left publicly storable", async () => {
  const response = await api(
    runtime,
    "POST",
    "/__spacefast/content.php",
    "content.model.activate",
    { space_id: CONTENT_SPACE },
    { operation: "model.activate", revision: null },
  );

  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ results: { posts: { items: [], total: 0 } } });
  expect(response.headers.get("cache-control")).toBe("private, no-store");
});

test("PUT file transfer does not widen other content operations", async () => {
  const response = await api(
    runtime,
    "PUT",
    "/__spacefast/content.php",
    "content.design-system.mutate",
    { space_id: CONTENT_SPACE },
    { operation: "model.activate", revision: null },
  );
  expect(response.status).toBe(405);
  expect(response.headers.get("allow")).toBe("POST");
});

test("PUT authenticates the upload lane before its body limit and memory increase", () => {
  const uploadToken = managementToken("content.design-system.mutate", { space_id: CONTENT_SPACE });
  const signatureIndex = uploadToken.lastIndexOf(".") + 1;
  const invalidSignature =
    uploadToken.slice(0, signatureIndex) +
    (uploadToken[signatureIndex] === "A" ? "B" : "A") +
    uploadToken.slice(signatureIndex + 1);
  const script = String.raw`
require $argv[1];
$_SERVER['REQUEST_METHOD'] = 'PUT';
$_SERVER['CONTENT_LENGTH'] = $argv[3];
if ($argv[4] !== '') $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[4];
register_shutdown_function(static function (): void {
  fwrite(STDERR, (string) ini_get('memory_limit'));
});
require $argv[2] . '/entrypoints/content.php';
`;
  for (const [token, status, code] of [
    ["", 401, "runtime_unauthorized"],
    [invalidSignature, 401, "runtime_token_bad_signature"],
    [
      managementToken("content.model.activate", { space_id: CONTENT_SPACE }),
      403,
      "runtime_action_forbidden",
    ],
  ] as const) {
    const result = Bun.spawnSync(
      [
        PHP_BINARY,
        "-d",
        "memory_limit=32M",
        "-r",
        script,
        RUNTIME_TEST_ATOMIC_PREPEND,
        runtime.engineRoot,
        String(128 * 1024 * 1024 + 1),
        token,
      ],
      { cwd: runtime.root },
    );
    expect(result.exitCode).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toMatchObject({ status, code });
    expect(result.stderr.toString()).toBe("32M");
  }
});
