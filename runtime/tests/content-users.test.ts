import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");

// The users feature at its own seam: WordPress's user model published through
// the Abilities API. The stubs below stand in for WP core (the user table, role
// capabilities, and the Abilities registry); everything they are driven by —
// membership, capability projection, authority identity — is the engine's own
// code running for real.
test("the Space users feature answers through the Abilities API under WordPress capability rules", async () => {
  const script = String.raw`
$users = [];
$userMeta = [];
$options = [];
$registered = [];
$refused = [];

final class WP_Error {
  public function __construct(
    public string $code,
    public string $message,
    public array $data = [],
  ) {}
}
final class TestUser {
  public function __construct(
    public int $ID,
    public string $user_login,
    public string $display_name,
  ) {}
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $name, mixed $default = false): mixed {
  return $GLOBALS['options'][$name] ?? $default;
}
function update_option(string $name, mixed $value, bool $autoload = false): bool {
  $GLOBALS['options'][$name] = $value;
  return true;
}
function get_user_by(string $field, mixed $value): object|false {
  foreach ($GLOBALS['users'] as $user) {
    if ($field === 'login' && $user->user_login === $value) return $user;
    if ($field === 'id' && $user->ID === (int) $value) return $user;
  }
  return false;
}
function get_userdata(int $userId): object|false { return get_user_by('id', $userId); }
function wp_insert_user(array $user): int {
  $id = 101 + count($GLOBALS['users']);
  $GLOBALS['users'][] = new TestUser($id, $user['user_login'], $user['display_name']);
  return $id;
}
function wp_update_user(array $update): int {
  $user = get_user_by('id', (int) $update['ID']);
  if (isset($update['display_name'])) $user->display_name = $update['display_name'];
  return $user->ID;
}
function update_user_meta(int $userId, string $key, mixed $value): void {
  $GLOBALS['userMeta'][$userId][$key] = [$value];
}
function add_user_meta(int $userId, string $key, mixed $value): void {
  $GLOBALS['userMeta'][$userId][$key][] = $value;
}
function delete_user_meta(int $userId, string $key): void {
  unset($GLOBALS['userMeta'][$userId][$key]);
}
function get_user_meta(int $userId, string $key, bool $single = true): mixed {
  $values = $GLOBALS['userMeta'][$userId][$key] ?? [];
  return $single ? ($values[0] ?? '') : $values;
}
// WP_User_Query, reduced to what the users feature asks it for: a meta match,
// an ordered window, and an optional wildcard search over named columns.
function get_users(array $query): array {
  $matched = [];
  foreach ($GLOBALS['users'] as $user) {
    if (!in_array($query['meta_value'], get_user_meta($user->ID, $query['meta_key'], false), true)) {
      continue;
    }
    $search = trim((string) ($query['search'] ?? ''), '*');
    if ($search !== '') {
      $haystack = '';
      foreach ($query['search_columns'] as $column) $haystack .= ' ' . $user->$column;
      if (stripos($haystack, $search) === false) continue;
    }
    $matched[] = $user;
  }
  usort($matched, static fn (TestUser $a, TestUser $b): int => $a->ID <=> $b->ID);
  return array_slice($matched, (int) $query['offset'], (int) $query['number']);
}
function get_role(string $name): object {
  // The primitive capabilities WordPress ships on these roles, trimmed to the
  // ones this surface consults.
  return (object) ['capabilities' => match ($name) {
    'administrator' => [
      'read' => true,
      'edit_posts' => true,
      'list_users' => true,
      'create_users' => true,
      'edit_users' => true,
    ],
    'editor' => ['read' => true, 'edit_posts' => true, 'publish_posts' => true],
    default => ['read' => true],
  }];
}
function wp_set_current_user(int $userId): object {
  $GLOBALS['currentUserId'] = $userId;
  return get_user_by('id', $userId);
}
function get_current_user_id(): int { return (int) ($GLOBALS['currentUserId'] ?? 0); }
// WordPress's own chain, reduced: map_meta_cap turns the edit_user meta cap
// into the edit_users primitive, and user_has_cap answers from the projection
// content-principals.php derives for THIS request's Grant decision. WordPress
// hands that filter the user whose caps are being tested, so the projection only
// reaches the request's own established principal — the same current-user wiring
// content-kernel.test.ts drives.
function current_user_can(string $capability, mixed ...$arguments): bool {
  $projected = spacefast_content_principal_capabilities(
    [], [], [], get_user_by('id', get_current_user_id())
  );
  $primitive = $capability === 'edit_user' ? 'edit_users' : $capability;
  return ($projected[$primitive] ?? false) === true;
}
// WP_Abilities_Registry::register()'s own admission rules: it refuses any name
// outside this pattern (one namespace, one slug, no further slashes) and any
// registration missing a required property. Refusals are recorded, not thrown,
// so an invalid registration shows up as a missing ability below.
function wp_register_ability(string $name, array $ability): void {
  if (preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name) !== 1) {
    $GLOBALS['refused'][] = $name;
    return;
  }
  foreach (['label', 'description', 'category', 'execute_callback', 'permission_callback'] as $required) {
    if (empty($ability[$required])) {
      $GLOBALS['refused'][] = $name;
      return;
    }
  }
  $GLOBALS['registered'][$name] = $ability;
}

require $argv[1];

function call_ability(string $name, mixed $input): mixed {
  $ability = $GLOBALS['registered'][$name] ?? null;
  if ($ability === null) return ['error' => 'unregistered', 'status' => 0];
  if (($ability['permission_callback'])($input) !== true) {
    return ['error' => 'permission_denied', 'status' => 403];
  }
  $result = ($ability['execute_callback'])($input);
  return $result instanceof WP_Error
    ? ['error' => $result->code, 'status' => $result->data['status'] ?? 0]
    : $result;
}

function sign_in(string $spaceId, string $role, string $subject, string $displayName): int {
  $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
  $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $role;
  $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
    'kind' => 'user',
    'actor_id' => 'usr_' . $subject,
    'issuer' => 'https://issuer-a.example',
    'subject' => $subject,
    'profile' => ['display_name' => $displayName],
  ];
  return (int) spacefast_content_principal_current_user(0);
}

// Two Spaces on one site, each with a person who signed in to it.
$ada = sign_in('spc_alpha', 'editor', 'ada', 'Ada');
$bob = sign_in('spc_beta', 'editor', 'bob', 'Bob');

// Back in the first Space, acting as its signed-in admin. The Abilities API
// dispatches after plugins_loaded has made the principal the current user, so
// establish that here before any capability check. Abilities register per
// request, against its scope.
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
wp_set_current_user($ada);
spacefast_content_users_register_abilities();
$abilityNames = array_keys($registered);
$abilityMeta = array_map(
  static fn (array $ability): array => [
    'category' => $ability['category'],
    'public' => $ability['meta']['public'],
    'annotations' => $ability['meta']['annotations'],
  ],
  $registered
);

// An editor holds no user capability: WordPress refuses before any code here
// looks at a Space.
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$listAsEditor = call_ability('zero/wp-users-list', []);
$createAsEditor = call_ability('zero/wp-users-create', [
  'issuer' => 'https://issuer-a.example',
  'subject' => 'mallory',
]);
$updateAsEditor = call_ability('zero/wp-users-update', ['id' => $ada, 'displayName' => 'Not Ada']);

$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
$listAsAdministrator = call_ability('zero/wp-users-list', []);
$otherSpaceUser = call_ability('zero/wp-users-get', ['id' => $bob]);
$otherSpaceUpdate = call_ability('zero/wp-users-update', ['id' => $bob, 'displayName' => 'Renamed']);

// Creating a user for an authority that already signed in returns that same
// WordPress row: one authority, one user, whichever door it came through.
$createdExisting = call_ability('zero/wp-users-create', [
  'issuer' => 'https://issuer-a.example',
  'subject' => 'ada',
  'displayName' => 'Ada Lovelace',
]);
$created = call_ability('zero/wp-users-create', [
  'issuer' => 'https://issuer-b.example',
  'subject' => 'carol',
  'displayName' => 'Carol',
]);
$createdWithoutAuthority = call_ability('zero/wp-users-create', ['issuer' => '', 'subject' => 'x']);
$searched = call_ability('zero/wp-users-list', ['search' => 'Carol']);
$paged = call_ability('zero/wp-users-list', ['page' => 2, 'perPage' => 1]);
$oversizedPage = call_ability('zero/wp-users-list', ['perPage' => 1000]);
$updated = call_ability('zero/wp-users-update', ['id' => $ada, 'displayName' => 'Ada Byron']);
$readBack = call_ability('zero/wp-users-get', ['id' => $ada]);

// The authority whose WordPress row exists only in the other Space stays out of
// reach: create refuses rather than renaming it or stamping this Space onto it,
// exactly the fence get and update enforce.
$foreignCreate = call_ability('zero/wp-users-create', [
  'issuer' => 'https://issuer-a.example',
  'subject' => 'bob',
  'displayName' => 'Hijacked',
]);
$listAfterForeignCreate = call_ability('zero/wp-users-list', []);

echo json_encode([
  'ada' => $ada,
  'bob' => $bob,
  'ability_names' => $abilityNames,
  'refused' => $refused,
  'ability_meta' => $abilityMeta,
  'list_as_editor' => $listAsEditor,
  'create_as_editor' => $createAsEditor,
  'update_as_editor' => $updateAsEditor,
  'list_as_administrator' => $listAsAdministrator,
  'other_space_user' => $otherSpaceUser,
  'other_space_update' => $otherSpaceUpdate,
  'created_existing' => $createdExisting,
  'created' => $created,
  'created_without_authority' => $createdWithoutAuthority,
  'searched' => $searched,
  'paged' => $paged,
  'oversized_page' => $oversizedPage,
  'updated' => $updated,
  'read_back' => $readBack,
  'foreign_create' => $foreignCreate,
  'list_after_foreign_create' => $listAfterForeignCreate,
  'bob_display_name' => get_userdata($bob)->display_name,
]);
`;
  const process = Bun.spawn(["php", "-r", script, kernel], {
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
  // SAFETY: the shape is the echo statement at the end of the PHP script above;
  // the expectations below fail loudly if the script stops emitting it.
  const result = JSON.parse(stdout) as {
    ada: number;
    bob: number;
    ability_names: string[];
    refused: string[];
    ability_meta: Record<
      string,
      { category: string; public: boolean; annotations: Record<string, boolean> }
    >;
    list_as_editor: { error: string; status: number };
    create_as_editor: { error: string; status: number };
    update_as_editor: { error: string; status: number };
    list_as_administrator: {
      page: number;
      perPage: number;
      users: Array<{ id: number; displayName: string; principal: Record<string, string> | null }>;
    };
    other_space_user: { error: string; status: number };
    other_space_update: { error: string; status: number };
    created_existing: { id: number; displayName: string };
    created: { id: number; displayName: string; principal: Record<string, string> | null };
    created_without_authority: { error: string; status: number };
    searched: { users: Array<{ id: number }> };
    paged: { page: number; users: Array<{ id: number }> };
    oversized_page: { error: string; status: number };
    updated: { id: number; displayName: string };
    read_back: { id: number; displayName: string };
    foreign_create: { error: string; status: number };
    list_after_foreign_create: { users: Array<{ id: number }> };
    bob_display_name: string;
  };

  // The whole surface is Abilities: one dispatcher, and the same registration
  // is what an agent reaches through the MCP adapter.
  expect({ names: result.ability_names, refused: result.refused }).toEqual({
    names: [
      "zero/wp-users-list",
      "zero/wp-users-get",
      "zero/wp-users-create",
      "zero/wp-users-update",
    ],
    refused: [],
  });
  expect(result.ability_meta["zero/wp-users-list"]).toEqual({
    category: "zero-wp-users",
    public: true,
    annotations: { readonly: true, destructive: false, idempotent: true },
  });
  expect(result.ability_meta["zero/wp-users-update"]?.annotations).toEqual({
    readonly: false,
    destructive: true,
    idempotent: true,
  });
  // Every one of them reaches clients — REST, and agents through the MCP
  // adapter — off this single registration.
  expect(Object.values(result.ability_meta).every((meta) => meta.public)).toBe(true);

  // Authorization is WordPress's: the projected role decides, and an editor
  // holds no user capability.
  expect([result.list_as_editor, result.create_as_editor, result.update_as_editor]).toEqual([
    { error: "permission_denied", status: 403 },
    { error: "permission_denied", status: 403 },
    { error: "permission_denied", status: 403 },
  ]);

  // One site hosts many Spaces: the directory is the request Space's, and a
  // user from another Space is not there to read or to rename.
  expect(result.list_as_administrator.users.map((user) => user.id)).toEqual([result.ada]);
  expect(result.other_space_user).toEqual({ error: "zero_wp_users_not_found", status: 404 });
  expect(result.other_space_update).toEqual({ error: "zero_wp_users_not_found", status: 404 });
  expect(result.bob_display_name).toBe("Bob");

  // One authority, one user: creating for an authority that already signed in
  // lands on that row rather than opening a second account for one person — and
  // returns it unchanged, since create never rewrites an existing profile (the
  // "Ada Lovelace" it was called with does not overwrite "Ada").
  expect(result.created_existing.id).toBe(result.ada);
  expect(result.created_existing.displayName).toBe("Ada");
  expect(result.created.id).not.toBe(result.ada);
  expect(result.created.principal).toEqual({
    kind: "user",
    issuer: "https://issuer-b.example",
    subject: "carol",
  });
  expect(result.created_without_authority).toEqual({
    error: "zero_wp_users_authority_invalid",
    status: 400,
  });

  // A created user joins the Space, so it is immediately in the directory.
  expect(result.searched.users.map((user) => user.id)).toEqual([result.created.id]);
  expect(result.paged).toMatchObject({ page: 2, users: [{ id: result.created.id }] });
  expect(result.oversized_page).toEqual({ error: "zero_wp_users_page_invalid", status: 400 });

  expect(result.updated.displayName).toBe("Ada Byron");
  expect(result.read_back).toMatchObject({ id: result.ada, displayName: "Ada Byron" });

  // Create is fenced to this Space like get and update: an authority whose row
  // lives only in another Space is refused, not renamed and not adopted here.
  expect(result.foreign_create).toEqual({
    error: "zero_wp_users_authority_elsewhere",
    status: 409,
  });
  expect(result.list_after_foreign_create.users.map((user) => user.id)).not.toContain(result.bob);
});
