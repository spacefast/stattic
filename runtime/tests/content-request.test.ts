import { expect, test } from "bun:test";
import path from "node:path";

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
  'schema' => _stattic_content_management_action(['operation' => 'schema.apply']),
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
    schema: "content.schema.apply",
  });
});
