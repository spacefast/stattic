import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const modulePath = path.join(repoRoot, "runtime/engine/shared/content-admin.php");
const principalModulePath = path.join(repoRoot, "runtime/engine/shared/content-principal.php");

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
function _stattic_runtime_instance_id(): string { return 'rti_content'; }
function _stattic_cookies_secure(): bool { return true; }
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
require $argv[2];
$assertion = [
  'format' => 'spacefast.principal',
  'version' => 1,
  'audience' => [
    'spaceId' => 'spc_123',
    'runtimeInstanceId' => 'rti_content',
    'host' => 'https://space.example',
  ],
  'sessionVersion' => 3,
  'accessGeneration' => 7,
  'nonce' => 'principal-nonce-1234',
  'issuedAt' => 990,
  'expiresAt' => 1050,
  'wordpressRole' => 'editor',
  'actor' => ['kind' => 'user', 'id' => 'usr_123'],
  'subject' => ['issuer' => 'https://api.spacefast.test/v1/auth', 'subject' => 'usr_123'],
  'profile' => ['displayName' => 'Ada'],
];
$serviceAssertion = $assertion;
$serviceAssertion['actor'] = ['kind' => 'service', 'id' => 'api-key:key_123'];
unset($serviceAssertion['subject']);
$anonymousAssertion = $assertion;
$anonymousAssertion['actor'] = ['kind' => 'anonymous'];
$anonymousAssertion['wordpressRole'] = null;
unset($anonymousAssertion['subject'], $anonymousAssertion['profile']);
$principal = _stattic_content_principal_assertion($assertion, 'spc_123', 'space.example', 1000);
$authorization = _stattic_content_admin_apply_authorization('/private', [
  'spaceId' => 'spc_123',
  'accessGeneration' => 7,
]);
$ticket = _stattic_content_admin_mint_ticket('/private', 'space.example', $principal, $authorization, 'editor', 'https://my.spacefast.test', null, 1000);
$first = _stattic_content_admin_consume_ticket('/private', $ticket['token'], 'space.example', 1001);
$again = _stattic_content_admin_consume_ticket('/private', $ticket['token'], 'space.example', 1001);
$wrongHostTicket = _stattic_content_admin_mint_ticket('/private', 'space.example', $principal, $authorization, 'editor', 'https://my.spacefast.test', null, 1000);
$wrongHost = _stattic_content_admin_consume_ticket('/private', $wrongHostTicket['token'], 'other.example', 1001);
// A landing screen rides the ticket, and only from the closed set. The launch
// entrypoint refuses an unrecognized one outright; the mint refuses to carry it
// either way, so a record can never name a screen redemption would honour.
$usersTicket = _stattic_content_admin_mint_ticket('/private', 'space.example', $principal, $authorization, 'editor', 'https://my.spacefast.test', 'users', 1000);
$usersLaunch = _stattic_content_admin_consume_ticket('/private', $usersTicket['token'], 'space.example', 1001);
$inventedTicket = _stattic_content_admin_mint_ticket('/private', 'space.example', $principal, $authorization, 'editor', 'https://my.spacefast.test', 'plugins', 1000);
$inventedLaunch = _stattic_content_admin_consume_ticket('/private', $inventedTicket['token'], 'space.example', 1001);
$session = _stattic_content_admin_mint_session('/private', 'space.example', 42, $principal, $authorization, 'editor', 'https://my.spacefast.test', 1000);
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
  'users_screen' => $usersLaunch['screen'],
  'invented_screen' => $inventedLaunch['screen'],
  'wrong_host_ticket' => $wrongHost,
  'valid' => $valid,
  'revoked' => $revoked,
  'stale_projection' => $staleProjection,
  'wrong_host_session' => _stattic_content_admin_verify_session('/private', $session['token'], 'other.example', 1001),
  'expired_session' => _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 5001),
  'expiry_boundary' => _stattic_content_admin_verify_session('/private', $session['token'], 'space.example', 4600),
  'tampered_session' => _stattic_content_admin_verify_session('/private', $tampered, 'space.example', 1001),
  'principal' => $principal,
  'wrong_audience' => _stattic_content_principal_assertion(
    array_replace_recursive($assertion, ['audience' => ['spaceId' => 'spc_other']]),
    'spc_123',
    'space.example',
    1000
  ),
  'expired_assertion' => _stattic_content_principal_assertion($assertion, 'spc_123', 'space.example', 1050),
  'email_is_not_identity' => _stattic_content_principal_assertion(
    $assertion + ['email' => 'same@example.com'],
    'spc_123',
    'space.example',
    1000
  ),
  // The role is not optional and not free-form: it is the control plane's Grant
  // decision, so an assertion that omits it or invents one is not an assertion.
  'role_missing' => _stattic_content_principal_assertion(
    array_diff_key($assertion, ['wordpressRole' => null]),
    'spc_123',
    'space.example',
    1000
  ),
  'role_unknown' => _stattic_content_principal_assertion(
    array_replace($assertion, ['wordpressRole' => 'author']),
    'spc_123',
    'space.example',
    1000
  ),
  'anonymous_with_role' => _stattic_content_principal_assertion(
    array_replace($anonymousAssertion, ['wordpressRole' => 'editor']),
    'spc_123',
    'space.example',
    1000
  ),
  'service' => _stattic_content_principal_assertion($serviceAssertion, 'spc_123', 'space.example', 1000),
  'anonymous' => _stattic_content_principal_assertion($anonymousAssertion, 'spc_123', 'space.example', 1000),
  // The editor lane is /wp-admin, /wp-json AND /?rest_route=: the admin
  // screens save through the REST API, which answers on the query form when
  // pretty permalinks are off. A gate that answers one and not the others has
  // a hole.
  'paths' => [
    _stattic_content_admin_request_path('/wp-admin'),
    _stattic_content_admin_request_path('/wp-admin/edit.php'),
    _stattic_content_admin_request_path('/wp-json'),
    _stattic_content_admin_request_path('/wp-json/wp/v2/posts'),
    _stattic_content_admin_request_path('/', ['rest_route' => '/wp/v2/types']),
    _stattic_content_admin_request_path('/'),
    _stattic_content_admin_request_path('/wp-adminx'),
    _stattic_content_admin_request_path('/wp-login.php'),
    _stattic_content_admin_request_path('/index.html'),
  ],
]);
`;
  const process = Bun.spawn(["php", "-r", script, modulePath, principalModulePath], {
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
      principal: {
        kind: "user",
        actor_id: "usr_123",
        session_version: 3,
        access_generation: 7,
        nonce: "principal-nonce-1234",
        expires_at: 1050,
        wordpress_role: "editor",
        profile: { display_name: "Ada" },
        issuer: "https://api.spacefast.test/v1/auth",
        subject: "usr_123",
      },
      authorization: { space_id: "spc_123", access_generation: 7 },
      wordpress_role: "editor",
      frame_origin: "https://my.spacefast.test",
      screen: null,
    },
    again: null,
    users_screen: "users",
    invented_screen: null,
    wrong_host_ticket: null,
    valid: {
      user_id: 42,
      principal: {
        kind: "user",
        actor_id: "usr_123",
        session_version: 3,
        access_generation: 7,
        nonce: "principal-nonce-1234",
        expires_at: 1050,
        wordpress_role: "editor",
        profile: { display_name: "Ada" },
        issuer: "https://api.spacefast.test/v1/auth",
        subject: "usr_123",
      },
      space_id: "spc_123",
      access_generation: 7,
      wordpress_role: "editor",
      frame_origin: "https://my.spacefast.test",
      expires_at: 4600,
    },
    revoked: null,
    stale_projection: { space_id: "spc_123", access_generation: 8 },
    wrong_host_session: null,
    expired_session: null,
    expiry_boundary: null,
    tampered_session: null,
    principal: {
      kind: "user",
      actor_id: "usr_123",
      session_version: 3,
      access_generation: 7,
      nonce: "principal-nonce-1234",
      expires_at: 1050,
      wordpress_role: "editor",
      profile: { display_name: "Ada" },
      issuer: "https://api.spacefast.test/v1/auth",
      subject: "usr_123",
    },
    wrong_audience: null,
    expired_assertion: null,
    email_is_not_identity: null,
    role_missing: null,
    role_unknown: null,
    anonymous_with_role: null,
    service: {
      kind: "service",
      actor_id: "api-key:key_123",
      session_version: 3,
      access_generation: 7,
      nonce: "principal-nonce-1234",
      expires_at: 1050,
      wordpress_role: "editor",
      profile: { display_name: "Ada" },
    },
    anonymous: {
      kind: "anonymous",
      session_version: 3,
      access_generation: 7,
      nonce: "principal-nonce-1234",
      expires_at: 1050,
      wordpress_role: null,
    },
    paths: [true, true, true, true, true, false, false, false, false],
  });
});

// A generated↔source parity guard: the grant→WordPress-role mapping has two
// authors because the two lanes learn the capabilities at different moments
// (see _stattic_wordpress_role_for_capabilities). Neither is generated from the
// other, so this runs both over the same inputs and fails when they diverge.
test("the runtime's grant→WordPress-role mapping matches the control plane's", async () => {
  const { wordpressRoleForGrantCapabilities } =
    await import("../../packages/common/src/contracts/principal-assertion.ts");
  // Every capability alone, nothing at all, and the combinations where the
  // precedence between them is the whole answer.
  const cases = [
    [],
    ["page.view"],
    ["comments.read"],
    ["comments.write"],
    ["content.publish"],
    ["access.manage"],
    ["page.view", "content.publish"],
    ["page.view", "content.publish", "access.manage"],
    ["comments.write", "content.publish"],
    ["comments.write", "page.view"],
  ] as const;

  const script = String.raw`
require $argv[1];
$cases = json_decode($argv[2], true);
echo json_encode(array_map(
  static fn (array $capabilities): ?string => _stattic_wordpress_role_for_capabilities($capabilities),
  $cases
));
`;
  const process = Bun.spawn(["php", "-r", script, modulePath, JSON.stringify(cases)], {
    cwd: repoRoot,
    stderr: "pipe",
    stdout: "pipe",
  });
  const [exitCode, stdout, stderr] = await Promise.all([
    process.exited,
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
  ]);
  expect(exitCode, stderr).toBe(0);
  expect(JSON.parse(stdout)).toEqual(cases.map((c) => wordpressRoleForGrantCapabilities(c)));
});

// The query spelling of REST (`/?rest_route=`) addresses the same resource as
// the pretty spelling but arrives at `/`. It must canonicalize to the SAME
// `/wp-json` path the pretty spelling names, or a Grant that scopes `/wp-json`
// would bind only one of the two ways to call the identical endpoint.
test("the REST access path canonicalizes the query spelling onto /wp-json", async () => {
  const cases = [
    ["/wp-json", {}],
    ["/wp-json/wp/v2/posts", {}],
    ["/", { rest_route: "/wp/v2/posts" }],
    ["/", { rest_route: "/" }],
    ["/", { rest_route: "" }],
    ["/", { rest_route: "wp/v2/posts" }],
    ["/", { rest_route: [] }],
    ["/", {}],
    ["/about", {}],
  ] as const;

  const script = String.raw`
require $argv[1];
$cases = json_decode($argv[2], true);
echo json_encode(array_map(
  static fn (array $case): string => _stattic_content_rest_access_path($case[0], $case[1]),
  $cases
));
`;
  const process = Bun.spawn(["php", "-r", script, modulePath, JSON.stringify(cases)], {
    cwd: repoRoot,
    stderr: "pipe",
    stdout: "pipe",
  });
  const [exitCode, stdout, stderr] = await Promise.all([
    process.exited,
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
  ]);
  expect(exitCode, stderr).toBe(0);
  expect(JSON.parse(stdout)).toEqual([
    "/wp-json",
    "/wp-json/wp/v2/posts",
    // Both spellings of wp/v2/posts land on the same enforced path.
    "/wp-json/wp/v2/posts",
    "/wp-json",
    "/wp-json",
    "/wp-json/wp/v2/posts",
    // An array (`?rest_route[]=`) or otherwise unusable value still names the
    // REST root, never `/`.
    "/wp-json",
    "/",
    "/about",
  ]);
});
