import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const modulePath = path.join(repoRoot, "runtime/engine/shared/content-admin.php");

test("content admin tickets are one-use and sessions are host-bound and tamper-evident", async () => {
  const script = String.raw`
$GLOBALS['records'] = [];
$GLOBALS['authorizations'] = [];
const STATTIC_LOCK_WAIT = 'wait';
function _stattic_record_store(string $root, array $descriptor = []): array { return ['root' => $root]; }
function _stattic_record_store_ensure(array $store): void {}
function _stattic_record_store_sweep(array $store, ?int $now = null): int { return 0; }
function _stattic_record_store_claim(array $store, string $id, array $record, int $expiresAt): bool {
  if (isset($GLOBALS['records'][$id])) return false;
  $GLOBALS['records'][$id] = $record;
  return true;
}
function _stattic_record_store_delete(array $store, string $id): void { unset($GLOBALS['records'][$id]); }
function _stattic_record_store_mutate(array $store, string $id, callable $critical): mixed {
  return $critical($GLOBALS['records'][$id] ?? null);
}
function _stattic_lazy_minted_secret(string $root, string $name, int $bytes): ?string {
  return str_repeat('ab', $bytes);
}
function _stattic_id_valid(string $value): bool {
  return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1;
}
function _stattic_absolute_url_origin(string $value): ?string {
  $parts = parse_url($value);
  if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
  return $parts['scheme'] . '://' . $parts['host']
    . (isset($parts['port']) ? ':' . $parts['port'] : '');
}
function _sf_pointer_read(string $name, string $path): array {
  return array_key_exists($path, $GLOBALS['authorizations'])
    ? ['kind' => 'present', 'value' => $GLOBALS['authorizations'][$path]]
    : ['kind' => 'absent'];
}
function _stattic_space_write_lock_with(
  string $privateRoot,
  string $spaceId,
  string $mode,
  ?callable $unavailable,
  callable $critical
): mixed { return $critical(); }
function _stattic_runtime_write_json_atomic(string $path, array $value): void {
  $GLOBALS['authorizations'][$path] = $value;
}
require $argv[1];
$identity = ['subject' => 'content_' . str_repeat('a', 64)];
$authorization = _stattic_content_admin_apply_authorization('/private', [
  'spaceId' => 'spc_123',
  'accessGeneration' => 7,
]);
$ticket = _stattic_content_admin_mint_ticket('/private', 'space.example', $identity, $authorization, 'https://my.spacefast.test', 1000);
$first = _stattic_content_admin_consume_ticket('/private', $ticket['token'], 'space.example', 1001);
$again = _stattic_content_admin_consume_ticket('/private', $ticket['token'], 'space.example', 1001);
$wrongHostTicket = _stattic_content_admin_mint_ticket('/private', 'space.example', $identity, $authorization, 'https://my.spacefast.test', 1000);
$wrongHost = _stattic_content_admin_consume_ticket('/private', $wrongHostTicket['token'], 'other.example', 1001);
$session = _stattic_content_admin_mint_session('/private', 'space.example', 42, $authorization, 'https://my.spacefast.test', 1000);
$valid = _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 1001);
$tampered = substr($session['token'], 0, -1) . (str_ends_with($session['token'], 'A') ? 'B' : 'A');
$nextAuthorization = _stattic_content_admin_apply_authorization('/private', [
  'spaceId' => 'spc_123',
  'accessGeneration' => 8,
]);
$revoked = _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 1001);
$staleProjection = _stattic_content_admin_apply_authorization('/private', [
  'spaceId' => 'spc_123',
  'accessGeneration' => 7,
]);
echo json_encode([
  'first' => $first,
  'again' => $again,
  'wrong_host_ticket' => $wrongHost,
  'valid' => $valid,
  'revoked' => $revoked,
  'stale_projection' => $staleProjection,
  'wrong_host_session' => _stattic_content_admin_verify_session('/private', $session['token'], 'other.example', 1001),
  'expired_session' => _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 5001),
  'expiry_boundary' => _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 4600),
  'tampered_session' => _stattic_content_admin_verify_session('/private', $tampered, 'space.example', 1001),
  'paths' => [
    _stattic_content_admin_request_path('/wp-admin/edit.php'),
    _stattic_content_admin_request_path('/wp-json/wp/v2/posts'),
    _stattic_content_admin_request_path('/wp-login.php'),
    _stattic_content_admin_request_path('/index.html'),
  ],
]);
`;
  const process = Bun.spawn(["php", "-r", script, modulePath], {
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
    first: {
      identity: { subject: `content_${"a".repeat(64)}` },
      authorization: { space_id: "spc_123", access_generation: 7 },
      frame_origin: "https://my.spacefast.test",
    },
    again: null,
    wrong_host_ticket: null,
    valid: {
      user_id: 42,
      space_id: "spc_123",
      access_generation: 7,
      frame_origin: "https://my.spacefast.test",
      expires_at: 4600,
    },
    revoked: null,
    stale_projection: { space_id: "spc_123", access_generation: 8 },
    wrong_host_session: null,
    expired_session: null,
    expiry_boundary: null,
    tampered_session: null,
    paths: [true, true, false, false],
  });
});
