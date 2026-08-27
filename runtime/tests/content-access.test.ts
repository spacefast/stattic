import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const accessModule = path.join(repoRoot, "runtime/engine/shared/content-access.php");
const adminModule = path.join(repoRoot, "runtime/engine/shared/content-admin.php");

test("content access resolves the host exposure and forwards only access credentials", async () => {
  const script = String.raw`
const STATTIC_SESSION_COOKIE = '__Host-spacefast_session';
const STATTIC_SESSION_DEV_COOKIE = 'spacefast_session_dev';
const STATTIC_SYSTEM_VIEW_COOKIE = '__Host-spacefast_system_view';
const STATTIC_SYSTEM_VIEW_DEV_COOKIE = 'spacefast_system_view_dev';

$scenario = 'open';
function _sf_pointer_read(string $key, string $path): array {
  global $scenario;
  if ($scenario === 'routes-unavailable' && $key === 'routes') {
    return ['kind' => 'unavailable'];
  }
  return $key === 'routes'
    ? ['kind' => 'present', 'value' => ['gen' => 1]]
    : ['kind' => 'present', 'value' => ['id' => 'spc_1']];
}
function _stattic_normalize_hostname(string $host): string { return strtolower($host); }
function _stattic_v4_host_lookup(string $root, array $routes, string $host): array|false|null {
  global $scenario;
  if ($scenario === 'host-unavailable') return false;
  if ($scenario === 'host-absent') return null;
  $entry = ['space_id' => 'spc_1', 'route_name' => 'live'];
  if ($scenario === 'host-serve') $entry['route_action'] = ['action' => 'serve'];
  if ($scenario === 'host-tombstone') $entry['route_action'] = ['action' => 'tombstone'];
  if ($scenario === 'host-platform-error') $entry['route_action'] = ['action' => 'platform_error'];
  return ['entry' => $entry, 'routes' => []];
}
function _stattic_v4_overlay(string $root, string $spaceId, array $space): array|false|null {
  global $scenario;
  if ($scenario === 'overlay-unavailable') return false;
  if ($scenario === 'fenced') return ['open' => true, 'fence' => 'exposure'];
  return ['open' => $scenario === 'open', 'versions' => ['live' => 'ver_1']];
}
function _stattic_v4_version_for_host(array $host, array $overlay): ?string {
  global $scenario;
  return $scenario === 'version-absent' ? null : 'ver_1';
}
function _stattic_v4_legacy_serving(string $spaceId, ?string $versionId, array $host, array $overlay): array {
  return ['space_id' => $spaceId, 'version_id' => $versionId, 'authorization' => ['open' => $overlay['open']]];
}

require $argv[1];
require $argv[2];
$scenarios = ['open', 'protected', 'host-serve', 'host-tombstone', 'host-platform-error', 'host-absent', 'routes-unavailable', 'overlay-unavailable', 'fenced', 'version-absent'];
$results = [];
$holds = [];
foreach ($scenarios as $scenario) {
  $results[$scenario] = _stattic_content_access_target('/private', 'SPACE.EXAMPLE');
  // The editor gate reads the same target and refuses on the platform's own
  // hold only: an unpublished Space is what the editor exists to fill, and the
  // exposure fence is covered by the session's accessGeneration.
  $holds[$scenario] = _stattic_content_admin_platform_hold('/private', 'SPACE.EXAMPLE');
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
  const process = Bun.spawn(["php", "-r", script, accessModule, adminModule], {
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
    targets: {
      open: {
        kind: "present",
        open: true,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: {
          space_id: "spc_1",
          version_id: "ver_1",
          authorization: { open: true },
        },
      },
      protected: {
        kind: "present",
        open: false,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: {
          space_id: "spc_1",
          version_id: "ver_1",
          authorization: { open: false },
        },
      },
      "host-serve": {
        kind: "present",
        open: false,
        space_id: "spc_1",
        version_id: "ver_1",
        serving: {
          space_id: "spc_1",
          version_id: "ver_1",
          authorization: { open: false },
        },
      },
      "host-tombstone": { kind: "absent", hold: "tombstone" },
      "host-platform-error": { kind: "absent", hold: "platform_error" },
      "host-absent": { kind: "absent", hold: null },
      "routes-unavailable": { kind: "unavailable" },
      "overlay-unavailable": { kind: "unavailable" },
      fenced: { kind: "unavailable" },
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
      "overlay-unavailable": null,
      fenced: null,
      "version-absent": null,
    },
    cookies: "__Host-spacefast_session=session_ok; spacefast_system_view_dev=view_ok",
    badCookie: "",
    authorization: "Bearer sfa_access_token_123456",
    badAuthorization: "",
  });
});
