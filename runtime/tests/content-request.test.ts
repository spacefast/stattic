import { afterAll, beforeAll, expect, test } from "bun:test";
import { writeFileSync } from "node:fs";
import path from "node:path";

import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const classifier = path.join(repoRoot, "runtime/engine/shared/content-request.php");

test("content requests opt into management authorization before WordPress boots", async () => {
  const script = String.raw`
require $argv[1];
$query = ['format' => 'spacefast.content.query', 'queries' => ['posts' => ['collection' => 'posts']]];
echo json_encode([
  'public' => _stattic_content_management_action($query),
  'managed' => _stattic_content_management_action($query + ['managed' => true]),
  'draft' => _stattic_content_management_action([
    'format' => 'spacefast.content.query',
    'queries' => ['preview' => ['collection' => 'posts', 'status' => 'draft']],
  ]),
  'compile' => _stattic_content_management_action(['operation' => 'schema.compile']),
  'activate' => _stattic_content_management_action(['operation' => 'schema.activate']),
  'admin' => _stattic_content_management_action(['operation' => 'admin.launch']),
  'authorization' => _stattic_content_management_action(['operation' => 'authorization.apply']),
  'document' => _stattic_content_management_action(['operation' => 'document.upsert']),
  'markdown' => _stattic_content_management_action(['operation' => 'markdown.sync']),
  'invalid' => _stattic_content_management_action(['operation' => 'other']),
]);
`;
  const process = Bun.spawn(["php", "-r", script, classifier], {
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
  expect(JSON.parse(stdout)).toEqual({
    admin: "content.admin.launch",
    authorization: "content.authorization.apply",
    document: "content.document.upsert",
    draft: "content.query",
    invalid: false,
    managed: "content.query",
    markdown: "content.markdown.sync",
    public: null,
    compile: "content.schema.compile",
    activate: "content.schema.activate",
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
      "function spacefast_content_handle_request(array $request, bool $managed): array {",
      "  return ['results' => ['posts' => ['items' => [], 'total' => 0]]];",
      "}",
      "",
    ].join("\n"),
  );
}, 30000);

afterAll(() => runtime?.stop());

test("a public content read is answered but never left publicly storable", async () => {
  const response = await get(runtime, CONTENT_HOST, "/__spacefast/content.php", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      format: "spacefast.content.query",
      queries: { posts: { collection: "posts" } },
    }),
  });

  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ results: { posts: { items: [], total: 0 } } });
  expect(response.headers.get("cache-control")).toBe("private, no-store");
});
