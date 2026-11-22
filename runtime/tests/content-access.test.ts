import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const artifactsModule = path.join(repoRoot, "runtime/engine/shared/artifacts.php");
const accessModule = path.join(repoRoot, "runtime/engine/shared/content-access.php");
const adminModule = path.join(repoRoot, "runtime/engine/shared/content-admin.php");

test("content access resolves the host exposure via _stattic_v4_resolve_host and forwards only access credentials", async () => {
  const script = String.raw`
require $argv[1];
require $argv[2];
require $argv[3];

$root = realpath(sys_get_temp_dir()) . '/sf-content-access-' . bin2hex(random_bytes(6));
mkdir($root . '/routes', 0775, true);
mkdir($root . '/spaces/spc_1', 0775, true);

$writePhp = static function (string $path, array $value): void {
  file_put_contents($path, "<?php\nreturn " . var_export($value, true) . ";\n");
};

$host = 'space.example';
$shardKey = substr(hash('sha256', $host), 0, 2);

$setup = static function (string $scenario) use ($root, $host, $shardKey, $writePhp): void {
  $routesPath = $root . '/routes/current.json';
  $spacePath = $root . '/spaces/spc_1/space.json';
  @chmod($routesPath, 0644);
  @chmod($spacePath, 0644);
  @unlink($routesPath);
  @unlink($spacePath);

  if ($scenario === 'routes-unavailable') {
    file_put_contents($routesPath, '{not-json');
    return;
  }
  if ($scenario === 'routes-missing-gen') {
    file_put_contents($routesPath, json_encode(['schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN, 'shards' => []]));
    return;
  }
  if ($scenario === 'routes-schema-mismatch') {
    file_put_contents($routesPath, json_encode(['gen' => 1, 'schema' => 999, 'shards' => []]));
    return;
  }

  $shardName = 'shard-' . $scenario . '.php';
  $entry = ['space_id' => 'spc_1', 'route_name' => 'live'];
  if ($scenario === 'host-serve') $entry['route_action'] = ['action' => 'serve'];
  if ($scenario === 'host-tombstone') $entry['route_action'] = ['action' => 'tombstone'];
  if ($scenario === 'host-platform-error') $entry['route_action'] = ['action' => 'platform_error'];

  if ($scenario === 'host-absent') {
    $writePhp($root . '/routes/' . $shardName, ['hostnames' => [], 'host_routes' => []]);
  } else {
    $writePhp($root . '/routes/' . $shardName, ['hostnames' => [$host => $entry], 'host_routes' => []]);
  }
  file_put_contents($routesPath, json_encode([
    'gen' => 1,
    'schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN,
    'shards' => [$shardKey => $shardName],
  ]));

  if ($scenario === 'space-absent') {
    return;
  }
  if ($scenario === 'space-schema-mismatch') {
    file_put_contents($spacePath, json_encode(['schema' => 999, 'overlay' => 'overlay-none.php']));
    return;
  }

  $overlayName = 'overlay-' . $scenario . '.php';
  file_put_contents($spacePath, json_encode([
    'schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN,
    'overlay' => $overlayName,
  ]));
  $overlayPath = $root . '/spaces/spc_1/' . $overlayName;
  if ($scenario === 'overlay-missing') {
    @unlink($overlayPath);
    return;
  }
  if ($scenario === 'overlay-unavailable') {
    // A PHP file that returns a non-array (not absent) triggers unreadable artifact -> false -> unavailable.
    file_put_contents($overlayPath, "<?php return 'corrupt';\n");
    return;
  }
  if ($scenario === 'overlay-schema-mismatch') {
    $writePhp($overlayPath, ['schema' => 999, 'open' => true, 'versions' => ['live' => 'ver_1']]);
    return;
  }
  $overlay = [
    'schema' => STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN,
    'open' => $scenario === 'open',
    'versions' => $scenario === 'version-absent' ? [] : ['live' => 'ver_1'],
  ];
  if ($scenario === 'fenced') {
    $overlay['open'] = true;
    $overlay['fence'] = 'exposure';
  }
  $writePhp($overlayPath, $overlay);
};

$scenarios = [
  'open',
  'protected',
  'host-serve',
  'host-tombstone',
  'host-platform-error',
  'host-absent',
  'routes-unavailable',
  'routes-missing-gen',
  'routes-schema-mismatch',
  'space-absent',
  'space-schema-mismatch',
  'overlay-missing',
  'overlay-unavailable',
  'overlay-schema-mismatch',
  'fenced',
  'version-absent',
];
$results = [];
$holds = [];
try {
  foreach ($scenarios as $scenario) {
    $setup($scenario);
    $target = _stattic_content_access_target($root, 'SPACE.EXAMPLE');
    // Normalize serving to the fields asserted here.
    if ($target['kind'] === 'present') {
      $target['serving'] = [
        'space_id' => $target['serving']['space_id'],
        'version_id' => $target['serving']['version_id'],
      ];
    }
    $results[$scenario] = $target;
    // The editor gate reads the same target and refuses on the platform's own
    // hold only: an unpublished Space is what the editor exists to fill, and the
    // exposure fence is covered by the session's accessGeneration.
    $holds[$scenario] = _stattic_content_admin_platform_hold($root, 'SPACE.EXAMPLE', $target);
  }
} finally {
  foreach (glob($root . '/spaces/spc_1/*') ?: [] as $f) @unlink($f);
  @rmdir($root . '/spaces/spc_1');
  @rmdir($root . '/spaces');
  foreach (glob($root . '/routes/*') ?: [] as $f) @unlink($f);
  @rmdir($root . '/routes');
  @rmdir($root);
}
echo json_encode([
  'targets' => $results,
  'editorHolds' => $holds,
  'cookies' => _stattic_content_access_cookie_header(
    'analytics=drop; __Host-spacefast_session=session_ok; bad=drop; spacefast_system_view_dev=view_ok'
  ),
  'badCookie' => _stattic_content_access_cookie_header('__Host-spacefast_session=bad value'),
  'authorization' => _stattic_content_access_authorization_header('sfa_access_token_123456'),
  'badAuthorization' => _stattic_content_access_authorization_header("bad\r\ntoken"),
]);
`;
  const process = Bun.spawn(
    [
      "php",
      "-d",
      "error_log=/dev/null",
      "-d",
      "display_errors=0",
      "-r",
      script,
      artifactsModule,
      accessModule,
      adminModule,
    ],
    {
      cwd: repoRoot,
      stderr: "pipe",
      stdout: "pipe",
    },
  );
  const [exitCode, stdout, stderr] = await Promise.all([
    process.exited,
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
  ]);

  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  expect(JSON.parse(stdout)).toEqual({
    targets: {
      open: {
        kind: "present",
        open: true,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: { space_id: "spc_1", version_id: "ver_1" },
      },
      protected: {
        kind: "present",
        open: false,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: { space_id: "spc_1", version_id: "ver_1" },
      },
      "host-serve": {
        kind: "present",
        open: false,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: { space_id: "spc_1", version_id: "ver_1" },
      },
      "host-tombstone": { kind: "absent", hold: "tombstone" },
      "host-platform-error": { kind: "absent", hold: "platform_error" },
      "host-absent": { kind: "absent", hold: null },
      "routes-unavailable": { kind: "unavailable" },
      "routes-missing-gen": { kind: "absent", hold: null },
      "routes-schema-mismatch": { kind: "unavailable" },
      "space-absent": { kind: "denied" },
      "space-schema-mismatch": { kind: "unavailable" },
      "overlay-missing": { kind: "denied" },
      "overlay-unavailable": { kind: "unavailable" },
      "overlay-schema-mismatch": { kind: "unavailable" },
      fenced: { kind: "denied" },
      "version-absent": { kind: "absent", hold: null },
    },
    editorHolds: {
      open: null,
      protected: null,
      "host-serve": null,
      "host-tombstone": "tombstone",
      "host-platform-error": "platform_error",
      "host-absent": null,
      "routes-unavailable": null,
      "routes-missing-gen": null,
      "routes-schema-mismatch": null,
      "space-absent": null,
      "space-schema-mismatch": null,
      "overlay-missing": null,
      "overlay-unavailable": null,
      "overlay-schema-mismatch": null,
      fenced: null,
      "version-absent": null,
    },
    cookies: "__Host-spacefast_session=session_ok; spacefast_system_view_dev=view_ok",
    badCookie: "",
    authorization: "Bearer sfa_access_token_123456",
    badAuthorization: "",
  });
});
