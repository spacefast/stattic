import { expect, test } from "bun:test";
import { copyFileSync, mkdirSync, mkdtempSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");
const contentLoader = path.join(repoRoot, "runtime/wordpress-content-loader.php");
const accessRules = path.join(repoRoot, "runtime/engine/runtime/access-rules.php");

// The engine release pointer is box state: one immutable engine serves the whole
// site. The ContentModelRelease is per Space, data-only, and selected only after an
// exact request Space is known. The loader never executes generated PHP.
test("the MU loader follows the engine pointer and selects one Space's immutable contentModel", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-content-loader-"));
  // The loader resolves the release through realpath, and the system temp dir
  // is itself a symlink on macOS.
  const publicRoot = path.join(realpathSync(root), "public");
  const muPlugin = path.join(publicRoot, "wp-content/mu-plugins/spacefast-content.php");
  mkdirSync(path.dirname(muPlugin), { recursive: true });
  copyFileSync(contentLoader, muPlugin);

  for (const release of ["release-a", "release-b"]) {
    const releaseRoot = path.join(publicRoot, ".stattic/releases", release);
    mkdirSync(path.join(releaseRoot, "engine/wordpress"), { recursive: true });
    writeFileSync(
      path.join(releaseRoot, "engine/wordpress/content-kernel.php"),
      `<?php $GLOBALS['loaded_kernel'] = ${JSON.stringify(release)};`,
    );
  }
  // Two Spaces on one box, each with its own immutable ContentModelRelease pointer.
  const spaces = { spc_alpha: "a".repeat(64), spc_beta: "b".repeat(64) };
  for (const [spaceId, revision] of Object.entries(spaces)) {
    const contentModelRoot = path.join(
      publicRoot,
      ".stattic/storage/spaces",
      spaceId,
      "content-model",
    );
    mkdirSync(path.join(contentModelRoot, "releases", revision), { recursive: true });
    writeFileSync(path.join(contentModelRoot, "active-release"), `sha256:${revision}\n`);
  }

  const load = (release: string, spaceId: string | null) => {
    writeFileSync(path.join(publicRoot, ".stattic/active-release"), `releases/${release}\n`);
    const scope =
      spaceId === null
        ? ""
        : `$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = ${JSON.stringify(spaceId)};`;
    const result = Bun.spawnSync({
      cmd: [
        "php",
        "-d",
        "auto_prepend_file=",
        "-r",
        `${scope} require $argv[1]; echo json_encode([$GLOBALS['loaded_kernel'] ?? null, $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] ?? null, $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] ?? null]);`,
        muPlugin,
      ],
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    return JSON.parse(result.stdout.toString());
  };

  try {
    expect(load("release-a", "spc_alpha")).toEqual([
      "release-a",
      `sha256:${spaces.spc_alpha}`,
      path.join(
        publicRoot,
        ".stattic/storage/spaces/spc_alpha/content-model/releases",
        spaces.spc_alpha,
      ),
    ]);
    // Same box, same engine release, different Space: the compile spc_alpha
    // just published must not follow the request over.
    expect(load("release-b", "spc_beta")).toEqual([
      "release-b",
      `sha256:${spaces.spc_beta}`,
      path.join(
        publicRoot,
        ".stattic/storage/spaces/spc_beta/content-model/releases",
        spaces.spc_beta,
      ),
    ]);
    // A Space with nothing compiled, and a request carrying no Space at all
    // (wp-cron): the engine still loads, no compiled release does.
    expect(load("release-b", "spc_gamma")).toEqual(["release-b", null, null]);
    expect(load("release-b", null)).toEqual(["release-b", null, null]);
    expect(load("release-b", "../spc_alpha")).toEqual(["release-b", null, null]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

// One wp.cloud site hosts many Spaces. Native post types and a shared media
// library are what the WordPress content model cutover left behind, so isolation is
// no longer a per-Space post type: it is the Space stamp on every row, the
// clause forced into every query, and the capability that goes away without it.
test("the WordPress content kernel keeps one Space's native content out of another's", async () => {
  const script = String.raw`
define('ABSPATH', '/srv/wordpress/');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$_SERVER['HTTP_HOST'] = 'alpha.spacefast.test';
$savedMeta = [];

// 9 is spc_alpha's attachment, 7 is spc_beta's, 5 is a revision of 9, and 3 is
// a post type no contentModel of this Space owns.
function get_post(int $id): ?object {
  return match ($id) {
    9 => (object) ['ID' => 9, 'post_type' => 'attachment'],
    7 => (object) ['ID' => 7, 'post_type' => 'attachment'],
    5 => (object) ['ID' => 5, 'post_type' => 'revision', 'post_parent' => 9],
    3 => (object) ['ID' => 3, 'post_type' => 'wpcom_menu'],
    default => null,
  };
}
function get_post_meta(int $id, string $key, bool $single): mixed {
  global $savedMeta;
  if (isset($savedMeta[$id][$key])) return $savedMeta[$id][$key];
  return $key === SPACEFAST_CONTENT_SPACE_META
    ? ([9 => 'spc_alpha', 7 => 'spc_beta', 3 => 'spc_alpha'][$id] ?? '')
    : '';
}
function update_post_meta(int $id, string $key, mixed $value): void {
  global $savedMeta; $savedMeta[$id][$key] = $value;
}
final class ScopeTestQuery {
  public array $vars = [];
  public function get(string $name): mixed { return $this->vars[$name] ?? null; }
  public function set(string $name, mixed $value): void { $this->vars[$name] = $value; }
}
require $argv[1];

// Uploading stamps the Space onto the attachment WordPress just created.
spacefast_content_scope_attachment(11);

$postQuery = new ScopeTestQuery();
spacefast_content_scope_post_query($postQuery);
$capability = static fn (int $postId): array =>
  spacefast_content_scope_meta_cap(['edit_posts'], 'edit_post', 1, [$postId]);
$capabilities = [$capability(9), $capability(7), $capability(5), $capability(3)];

$alphaOwnsNine = spacefast_content_post_belongs_to_space(9);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_beta';
$betaOwnsNine = spacefast_content_post_belongs_to_space(9);
$betaCapability = $capability(9);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';

$uploads = spacefast_content_scope_upload_dir([
  'basedir' => '/srv/uploads',
  'baseurl' => 'https://space.example/wp-content/uploads',
  'subdir' => '/2026/08',
]);
echo json_encode([
  'attachment_stamp' => $savedMeta[11][SPACEFAST_CONTENT_SPACE_META],
  'post_query' => $postQuery->get('meta_query'),
  'attachment_query' => spacefast_content_scope_attachment_query(['post_status' => 'inherit']),
  // An OR the caller wrote keeps its own shape, wrapped under a mandatory AND.
  'nested_or' => spacefast_content_scope_meta_query([
    'relation' => 'OR',
    ['key' => 'featured', 'value' => '1'],
    ['key' => 'promoted', 'value' => '1'],
  ]),
  // Already scoped: the clause is not stacked a second time.
  'already_scoped' => spacefast_content_scope_meta_query([
    ['key' => SPACEFAST_CONTENT_SPACE_META, 'value' => 'spc_alpha', 'compare' => '='],
  ]),
  'capabilities' => $capabilities,
  'ownership' => [$alphaOwnsNine, $betaOwnsNine, $betaCapability],
  'upload_scope' => [$uploads['basedir'], $uploads['baseurl'], $uploads['path'], $uploads['url']],
  'request_url' => spacefast_content_request_url('https://provider.example/wp-admin/edit.php?post_type=post'),
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

  const spaceClause = { key: "_spacefast_space_id", value: "spc_alpha", compare: "=" };
  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  expect(JSON.parse(stdout)).toEqual({
    attachment_stamp: "spc_alpha",
    post_query: [spaceClause],
    attachment_query: { post_status: "inherit", meta_query: [spaceClause] },
    nested_or: {
      relation: "AND",
      0: spaceClause,
      1: {
        relation: "OR",
        0: { key: "featured", value: "1" },
        1: { key: "promoted", value: "1" },
      },
    },
    already_scoped: [spaceClause],
    // Own attachment: allowed. Another Space's: denied. A revision follows its
    // parent. A post type no content model owns is denied whoever holds it.
    capabilities: [["edit_posts"], ["do_not_allow"], ["edit_posts"], ["do_not_allow"]],
    // The same row seen from the other Space on the same box.
    ownership: [true, false, ["do_not_allow"]],
    upload_scope: [
      "/srv/wordpress/.stattic/storage/spaces/spc_alpha/content-media",
      `https://alpha.spacefast.test/__spacefast/content-media/${new Bun.CryptoHasher("sha256").update("spc_alpha").digest("hex").slice(0, 32)}`,
      "/srv/wordpress/.stattic/storage/spaces/spc_alpha/content-media/2026/08",
      `https://alpha.spacefast.test/__spacefast/content-media/${new Bun.CryptoHasher("sha256").update("spc_alpha").digest("hex").slice(0, 32)}/2026/08`,
    ],
    // WordPress must never hand the provider's own hostname to the browser.
    request_url: "https://alpha.spacefast.test/wp-admin/edit.php?post_type=post",
  });
});
test("the managed WordPress surface admits content work and rejects Spacefast-owned screens", async () => {
  const script = String.raw`
$bootstrapCalls = 0;
function _stattic_runtime_bootstrap_config(): void {
  global $bootstrapCalls;
  $bootstrapCalls++;
}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
class WP_Error {
  public function __construct(public string $code, public string $message, public array $data) {}
}
require $argv[1];
$GLOBALS['pagenow'] = '';
spacefast_content_lock_admin();
$scfText = spacefast_content_scf_field('articles_01k4t7x8', 'summary', [
  'type' => 'text',
  'label' => 'Summary',
  'multiline' => true,
  'maxLength' => 280,
]);
$scfMedia = spacefast_content_scf_field('articles_01k4t7x8', 'gallery', [
  'type' => 'media',
  'label' => 'Gallery',
  'multiple' => true,
]);
echo json_encode([
  'bootstrap_calls' => $bootstrapCalls,
  'allowed' => array_map('spacefast_content_admin_page_allowed', [
    'edit.php', 'post.php', 'post-new.php', 'upload.php', 'media.php',
    'revision.php', 'edit-tags.php', 'admin-ajax.php', 'load-scripts.php',
    'admin.php',
  ]),
  'blocked' => array_map('spacefast_content_admin_page_allowed', [
    'plugins.php', 'themes.php', 'users.php', 'options-general.php',
    'tools.php', 'profile.php', 'edit-comments.php', 'site-health.php',
  ]),
  'scf' => [
    [$scfText['type'], $scfText['rows'], $scfText['maxlength']],
    [$scfMedia['type'], $scfMedia['return_format']],
    str_starts_with($scfText['key'], 'field_'),
  ],
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
  expect(JSON.parse(stdout)).toEqual({
    bootstrap_calls: 1,
    allowed: Array(10).fill(true),
    blocked: Array(8).fill(false),
    scf: [["textarea", 8, 280], ["gallery", "id"], true],
  });
});

test("managed API and admin requests converge on durable issuer-subject WordPress principals", async () => {
  const script = String.raw`
$inserted = [];
$users = [];
$options = [];
$metas = [];
$current = null;
final class TestUser {
  public string $role = '';
  public function __construct(
    public int $ID,
    public string $user_login,
    public string $display_name,
    public string $user_email = '',
  ) {}
  public function set_role(string $role): void { $this->role = $role; }
}
function get_user_by(string $field, mixed $value): object|false {
  global $users;
  foreach ($users as $user) {
    if ($field === 'login' && $user->user_login === $value) return $user;
    if ($field === 'id' && $user->ID === (int) $value) return $user;
  }
  return false;
}
function wp_insert_user(array $user): int {
  global $users, $inserted;
  $id = 57 + count($users);
  $inserted[] = $user;
  $users[] = new TestUser($id, $user['user_login'], $user['display_name']);
  return $id;
}
function is_wp_error(mixed $value): bool { return false; }
function wp_set_current_user(int $userId): object {
  global $current;
  $current = $userId;
  return get_user_by('id', $userId);
}
function get_current_user_id(): int { global $current; return (int) ($current ?? 0); }
function get_option(string $name, mixed $default = false): mixed {
  return $GLOBALS['options'][$name] ?? $default;
}
function update_option(string $name, mixed $value, bool $autoload = false): bool {
  $GLOBALS['options'][$name] = $value;
  return true;
}
function update_user_meta(int $userId, string $name, mixed $value): void {
  $GLOBALS['metas'][$userId][$name] = $value;
}
function delete_user_meta(int $userId, string $name): void { unset($GLOBALS['metas'][$userId][$name]); }
function get_role(string $name): object {
  return (object) ['capabilities' => match ($name) {
    'administrator' => ['edit_posts' => true, 'publish_posts' => true, 'manage_options' => true],
    'editor' => ['edit_posts' => true, 'publish_posts' => true],
    default => ['read' => true],
  }];
}
function wp_update_user(array $update): int {
  $user = get_user_by('id', $update['ID']);
  if (isset($update['display_name'])) $user->display_name = $update['display_name'];
  if (isset($update['user_email'])) $user->user_email = $update['user_email'];
  return $user->ID;
}
require $argv[1];
// The access layer's own derivation, loaded side by side, so the WordPress
// principal key and the Grant audience reference cannot drift apart.
require $argv[2];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
  'kind' => 'user',
  'actor_id' => 'usr_1',
  'issuer' => 'https://issuer-a.example',
  'subject' => 'shared-subject',
  'profile' => ['display_name' => 'Ada'],
];
$apiUser = spacefast_content_principal_current_user(0);
$authoredPost = ['post_author' => $apiUser];
$adminUser = spacefast_content_principal_establish_user();
// WordPress hands the filter the user whose caps are being tested. Project only
// onto the request's own principal, so pass that user rather than a bare null.
$projectedCapabilities = spacefast_content_principal_capabilities(
  [], [], [], get_user_by('id', get_current_user_id())
);
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL']['profile'] = ['display_name' => 'Grace'];
$repeatUser = spacefast_content_principal_establish_user();
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
$administratorCapabilities = spacefast_content_principal_capabilities(
  [], [], [], get_user_by('id', get_current_user_id())
);
// The same projection must NOT reach a different user: WordPress runs this
// filter for whoever's caps are tested, and the Grant decision belongs to the
// request's principal alone.
$otherUserCapabilities = spacefast_content_principal_capabilities(
  ['read' => true], [], [], (object) ['ID' => get_current_user_id() + 1]
);
$sameUserAsAdministrator = spacefast_content_principal_establish_user();
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
  'kind' => 'user',
  'actor_id' => 'usr_2',
  'issuer' => 'https://issuer-b.example',
  'subject' => 'shared-subject',
  'profile' => ['display_name' => 'Grace'],
];
$otherIssuerUser = spacefast_content_principal_establish_user();
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
  'kind' => 'service',
  'actor_id' => 'api-key:key_1',
  'profile' => ['display_name' => 'Publisher'],
];
$serviceUser = spacefast_content_principal_establish_user();
// A commenter earns no role, so no WordPress user is created for them at all.
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
  'kind' => 'user',
  'actor_id' => 'usr_3',
  'issuer' => 'https://issuer-c.example',
  'subject' => 'commenter',
  'profile' => ['display_name' => 'Commenter'],
];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = null;
$rolelessUser = spacefast_content_principal_establish_user();
$rolelessCapabilities = spacefast_content_principal_capabilities(['read' => true], [], [], null);
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = ['kind' => 'anonymous'];
$anonymousUser = spacefast_content_principal_establish_user();
unset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL']);
$afterRevocation = spacefast_content_principal_current_user(0);
echo json_encode([
  'api_user' => $apiUser,
  'admin_user' => $adminUser,
  'repeat_user' => $repeatUser,
  'same_user_as_administrator' => $sameUserAsAdministrator,
  'other_issuer_user' => $otherIssuerUser,
  'service_user' => $serviceUser,
  'roleless_user' => $rolelessUser,
  'anonymous_user' => $anonymousUser,
  'after_revocation' => $afterRevocation,
  'authored_post' => $authoredPost,
  'current' => $current,
  'inserted' => $inserted,
  'users' => array_map(static fn (TestUser $user): array => [
    'id' => $user->ID,
    'login' => $user->user_login,
    'display_name' => $user->display_name,
    'email' => $user->user_email,
    'role' => $user->role,
  ], $users),
  'metas' => $metas,
  'projected_capabilities' => $projectedCapabilities,
  'administrator_capabilities' => $administratorCapabilities,
  'other_user_capabilities' => $otherUserCapabilities,
  'roleless_capabilities' => $rolelessCapabilities,
  'authority_agrees' => [
    spacefast_content_principal_authority('https://issuer-a.example', 'shared-subject'),
    _stattic_grant_audience_reference([
      'kind' => 'external',
      'issuer' => 'https://issuer-a.example',
      'subject' => 'shared-subject',
    ]),
    spacefast_content_principal_authority('spacefast-membership', 'mbr_1'),
    _stattic_grant_audience_reference([
      'kind' => 'external',
      'issuer' => 'spacefast-membership',
      'subject' => 'mbr_1',
    ]),
  ],
]);
`;
  const process = Bun.spawn(["php", "-r", script, kernel, accessRules], {
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
  // the expectations below fail loudly if the script ever stops emitting it.
  const result = JSON.parse(stdout) as {
    api_user: number;
    admin_user: number;
    repeat_user: number;
    same_user_as_administrator: number;
    other_issuer_user: number;
    service_user: number;
    roleless_user: number;
    anonymous_user: number;
    after_revocation: number;
    authored_post: { post_author: number };
    inserted: Array<Record<string, string>>;
    users: Array<Record<string, string | number>>;
    metas: Record<string, Record<string, string>>;
    projected_capabilities: Record<string, boolean>;
    administrator_capabilities: Record<string, boolean>;
    other_user_capabilities: Record<string, boolean>;
    roleless_capabilities: Record<string, boolean>;
    authority_agrees: string[];
  };
  expect(result.api_user).toBe(57);
  expect(result.admin_user).toBe(57);
  expect(result.repeat_user).toBe(57);
  // A stronger role for the same person is the same person: authority keys the
  // WordPress user, the role only decides what that request may touch.
  expect(result.same_user_as_administrator).toBe(57);
  expect(result.other_issuer_user).toBe(58);
  expect(result.service_user).toBe(59);
  // No role, no user: a commenter's work is the platform's, not this site's.
  expect(result.roleless_user).toBe(0);
  expect(result.roleless_capabilities).toEqual({ read: true });
  expect(result.anonymous_user).toBe(0);
  expect(result.after_revocation).toBe(0);
  expect(result.authored_post.post_author).toBe(57);
  expect(result.inserted).toHaveLength(3);
  expect(result.users.map((user) => user.display_name)).toEqual(["Grace", "Grace", "Publisher"]);
  expect(new Set(result.users.map((user) => user.login)).size).toBe(3);
  expect(result.users.every((user) => user.email === "")).toBe(true);
  expect(result.users.every((user) => user.role === "")).toBe(true);
  expect(result.projected_capabilities).toEqual({ edit_posts: true, publish_posts: true });
  expect(result.administrator_capabilities).toEqual({
    edit_posts: true,
    publish_posts: true,
    manage_options: true,
  });
  // A second user's capability test gets nothing from this request's principal.
  expect(result.other_user_capabilities).toEqual({ read: true });
  expect(result.metas["57"]?._spacefast_principal_issuer).toBe("https://issuer-a.example");
  expect(result.metas["58"]?._spacefast_principal_issuer).toBe("https://issuer-b.example");
  expect(result.metas["59"]?._spacefast_principal_kind).toBe("service");
  // The WordPress principal key IS the platform authority reference, hashed
  // form and reserved-issuer prefix form alike.
  expect(result.authority_agrees[0]).toBe(result.authority_agrees[1]);
  expect(result.authority_agrees[2]).toBe(result.authority_agrees[3]);
  expect(result.metas["57"]?._spacefast_principal_id).toBe(result.authority_agrees[0]);
});

test("the managed Space title shadows WordPress blogname", async () => {
  const script = String.raw`
$options = [
  'blogname' => 'Changed in WordPress',
  'spacefast_space_title' => 'The docs Space',
];
function get_option(string $name, mixed $default = false): mixed {
  return $GLOBALS['options'][$name] ?? $default;
}
require $argv[1];
echo json_encode([
  'stored_blogname' => get_option('blogname'),
  'effective_blogname' => spacefast_content_managed_site_title(false),
]);
`;
  const process = Bun.spawnSync(["php", "-r", script, kernel], {
    cwd: repoRoot,
    stderr: "pipe",
    stdout: "pipe",
  });

  expect(process.exitCode, process.stderr.toString()).toBe(0);
  expect(JSON.parse(process.stdout.toString())).toEqual({
    stored_blogname: "Changed in WordPress",
    effective_blogname: "The docs Space",
  });
});

test("content admin authentication mints matching WordPress admin and REST cookies", async () => {
  const script = String.raw`
define('SECURE_AUTH_COOKIE', 'wordpress_sec_test');
define('LOGGED_IN_COOKIE', 'wordpress_logged_in_test');
$generated = [];
class WP_Session_Tokens {
  public static function get_instance(int $userId): object {
    return new class($userId) {
      public function __construct(private int $userId) {}
      public function create(int $expiration): string { return 'session-token-' . $this->userId; }
    };
  }
}
function wp_generate_auth_cookie(int $userId, int $expiration, string $scheme, string $token): string {
  global $generated;
  $generated[] = compact('userId', 'expiration', 'scheme', 'token');
  return 'signed-' . $scheme . '-cookie';
}
require $argv[1];
$before = time();
$cookies = spacefast_content_admin_auth_cookies(57, 3600);
$after = time();
echo json_encode([
  'cookies' => $cookies,
  'generated' => $generated,
  'expiration_in_range' => $generated[0]['expiration'] >= $before + 3600
    && $generated[0]['expiration'] <= $after + 3600,
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
  expect(JSON.parse(stdout)).toEqual({
    cookies: [
      { name: "wordpress_sec_test", value: "signed-secure_auth-cookie" },
      { name: "wordpress_logged_in_test", value: "signed-logged_in-cookie" },
    ],
    generated: [
      {
        userId: 57,
        expiration: expect.any(Number),
        scheme: "secure_auth",
        token: "session-token-57",
      },
      {
        userId: 57,
        expiration: expect.any(Number),
        scheme: "logged_in",
        token: "session-token-57",
      },
    ],
    expiration_in_range: true,
  });
});

test("content admin handshake reports the applied session expiry to its exact dashboard origin", async () => {
  const script = String.raw`
require $argv[1];
$GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] = 'https://my.spacefast.test';
$GLOBALS['SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT'] = 1787950800;
$ready = spacefast_content_admin_handshake_payload(1787947200);
$GLOBALS['SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT'] = 1787947199;
$expired = spacefast_content_admin_handshake_payload(1787947200);
echo json_encode(['ready' => $ready, 'expired' => $expired]);
`;
  const process = Bun.spawnSync(["php", "-r", script, kernel], {
    cwd: repoRoot,
    stderr: "pipe",
    stdout: "pipe",
  });

  expect(process.exitCode, process.stderr.toString()).toBe(0);
  expect(JSON.parse(process.stdout.toString())).toEqual({
    ready: {
      type: "spacefast.content.admin.ready",
      version: 1,
      expiresAt: "2026-08-28T21:00:00Z",
      origin: "https://my.spacefast.test",
    },
    expired: null,
  });
});

test("a verified content session enables Gutenberg REST and scopes users to the Space on both REST doors", async () => {
  const script = String.raw`
class WP_Error {
  public function __construct(
    public string $code,
    public string $message,
    public array $data,
  ) {}
}
require $argv[1];
$closed = spacefast_content_disable_rest_api(null);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
// Door 1: the editor session, keyed by SPACEFAST_CONTENT_ADMIN_USER_ID.
$GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = 57;
$sessionDoor = [
  'rest' => spacefast_content_disable_rest_api(null),
  'user_query' => spacefast_content_scope_rest_user_query(['orderby' => 'name'], null),
];
// Door 2: the WP API door, admitted by a principal role with no editor user
// id. The user scope must still apply, or /wp/v2/users enumerates every Space.
unset($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID']);
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
$apiDoor = [
  'rest' => spacefast_content_disable_rest_api(null),
  'user_query' => spacefast_content_scope_rest_user_query(['orderby' => 'name'], null),
];
echo json_encode([
  'closed' => [$closed->code, $closed->data['status']],
  'session_door' => $sessionDoor,
  'api_door' => $apiDoor,
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

  const scopedQuery = {
    orderby: "name",
    meta_key: "_spacefast_space_id",
    meta_value: "spc_alpha",
  };
  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  expect(JSON.parse(stdout)).toEqual({
    closed: ["spacefast_rest_disabled", 404],
    session_door: { rest: null, user_query: scopedQuery },
    api_door: { rest: null, user_query: scopedQuery },
  });
});

test("the REST gate's admission enables WordPress REST, with or without a role", async () => {
  // spacefast_content_disable_rest_api is not a second authorization: the WP API
  // door already ran the access engine and, on admission, set
  // SPACEFAST_CONTENT_REST_ADMITTED. An admitted anonymous request (no editor
  // user, no resolved role) must therefore get WordPress's own unauthenticated
  // answer, while a request that reached WordPress without the gate still 404s.
  const script = String.raw`
class WP_Error {
  public function __construct(
    public string $code,
    public string $message,
    public array $data,
  ) {}
}
require $argv[1];
function verdict(mixed $result): mixed {
  return $result instanceof WP_Error ? [$result->code, $result->data['status']] : $result;
}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
// Reached WordPress with a Space scope but no gate admission: still closed.
$noGate = verdict(spacefast_content_disable_rest_api(null));
// The gate admitted an anonymous request: no editor user, no role, marker set.
$GLOBALS['SPACEFAST_CONTENT_REST_ADMITTED'] = true;
$anonymousAdmitted = verdict(spacefast_content_disable_rest_api(null));
echo json_encode(['no_gate' => $noGate, 'anonymous_admitted' => $anonymousAdmitted]);
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
  expect(JSON.parse(stdout)).toEqual({
    no_gate: ["spacefast_rest_disabled", 404],
    anonymous_admitted: null,
  });
});
