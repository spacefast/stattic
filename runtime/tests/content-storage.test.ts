import { expect, test } from "bun:test";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");

// The storage feature at its own seam: WordPress attachments published as a
// Space's files through the Abilities API. The stubs below stand in for WP core
// (the post table, the term tree, the sideload, and the Abilities registry);
// everything they are driven by — Space membership, folder resolution,
// capability projection, trash semantics — is the engine's own code running for
// real.
test("the Space storage feature answers through the Abilities API under WordPress capability rules", async () => {
  const script = String.raw`
$posts = [];
$postMeta = [];
$terms = [];
$objectTerms = [];
$attachmentFiles = [];
$attachmentMeta = [];
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
final class TestPost {
  public function __construct(
    public int $ID,
    public string $post_type,
    public string $post_title,
    public string $post_mime_type,
    public string $post_status = 'inherit',
    public string $post_date_gmt = '2026-08-29 00:00:00',
    public int $post_parent = 0,
  ) {}
}
final class TestTerm {
  public function __construct(
    public int $term_id,
    public string $name,
    public string $slug,
    public int $parent,
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
function get_post(int $postId): ?object { return $GLOBALS['posts'][$postId] ?? null; }
function update_post_meta(int $postId, string $key, mixed $value): void {
  $GLOBALS['postMeta'][$postId][$key] = $value;
}
function get_post_meta(int $postId, string $key, bool $single = true): mixed {
  return $GLOBALS['postMeta'][$postId][$key] ?? '';
}
function wp_basename(string $filePath): string { return basename($filePath); }
function get_attached_file(int $postId): string { return $GLOBALS['attachmentFiles'][$postId] ?? ''; }
function wp_get_attachment_url(int $postId): string {
  return 'https://space.test/__spacefast/content-media/scope/' . basename(get_attached_file($postId));
}
function wp_get_attachment_metadata(int $postId): array {
  return $GLOBALS['attachmentMeta'][$postId] ?? [];
}
function wp_update_attachment_metadata(int $postId, array $metadata): void {
  $GLOBALS['attachmentMeta'][$postId] = $metadata;
}
function wp_generate_attachment_metadata(int $postId, string $filePath): array {
  return ['filesize' => strlen((string) @file_get_contents($filePath))];
}

// The term tree, reduced to what folders ask it for: slug lookup, insert under
// a parent, and walking a parent chain back to a path.
function get_term_by(string $field, string $value, string $taxonomy): object|false {
  foreach ($GLOBALS['terms'] as $term) {
    if ($field === 'slug' && $term->slug === $value) return $term;
  }
  return false;
}
function get_term(int $termId, string $taxonomy): object|null {
  return $GLOBALS['terms'][$termId] ?? null;
}
function wp_insert_term(string $name, string $taxonomy, array $args): array {
  $id = 500 + count($GLOBALS['terms']);
  $GLOBALS['terms'][$id] = new TestTerm($id, $name, $args['slug'], (int) $args['parent']);
  return ['term_id' => $id];
}
function wp_set_object_terms(int $postId, array $termIds, string $taxonomy, bool $append): void {
  $GLOBALS['objectTerms'][$postId] = $termIds;
}
function wp_get_object_terms(int $postId, string $taxonomy): array {
  return array_values(array_map(
    static fn (int $id): object => $GLOBALS['terms'][$id],
    $GLOBALS['objectTerms'][$postId] ?? []
  ));
}

// WP_Query for attachments, reduced to the clauses this surface builds: the
// Space meta fence, a status filter, a folder term, a title search, and a
// window. Every one of those is the engine's own construction.
function get_posts(array $query): array {
  $matched = [];
  foreach ($GLOBALS['posts'] as $post) {
    if ($post->post_type !== $query['post_type']) continue;
    if ($post->post_status !== $query['post_status']) continue;
    $clause = $query['meta_query'][0];
    if ((get_post_meta($post->ID, $clause['key'], true)) !== $clause['value']) continue;
    if (isset($query['tax_query'])) {
      $clause = $query['tax_query'][0];
      $held = $GLOBALS['objectTerms'][$post->ID] ?? [];
      if (($clause['operator'] ?? '') === 'NOT EXISTS') {
        if ($held !== []) continue;
      } else {
        $wanted = (int) $clause['terms'];
        $ok = in_array($wanted, $held, true);
        if (!$ok && ($clause['include_children'] ?? false)) {
          foreach ($held as $termId) {
            $term = $GLOBALS['terms'][$termId] ?? null;
            while ($term !== null) {
              if ($term->term_id === $wanted) { $ok = true; break 2; }
              $term = $GLOBALS['terms'][$term->parent] ?? null;
            }
          }
        }
        if (!$ok) continue;
      }
    }
    if (isset($query['s']) && stripos($post->post_title, $query['s']) === false) continue;
    if (isset($query['post_mime_type']) && $post->post_mime_type !== $query['post_mime_type']) continue;
    $matched[] = $post;
  }
  usort($matched, static fn (TestPost $a, TestPost $b): int => $b->ID <=> $a->ID);
  return array_map(
    static fn (TestPost $post): int => $post->ID,
    array_slice($matched, (int) $query['offset'], (int) $query['posts_per_page'])
  );
}

function wp_tempnam(string $filename): string {
  return tempnam(sys_get_temp_dir(), 'sfstorage');
}
// wp_handle_sideload's contract as this surface depends on it: WordPress owns
// the allowed-type verdict, and refuses by returning an error entry.
function wp_handle_sideload(array $file, array $overrides): array {
  $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $types = ['txt' => 'text/plain', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
  if (!isset($types[$extension])) return ['error' => 'Sorry, you are not allowed to upload this file type.'];
  // Stands in for the Space-scoped uploads root content-kernel.php's
  // upload_dir filter installs; WordPress keeps the caller's file name there.
  $uploads = sys_get_temp_dir() . '/sf-storage-uploads';
  @mkdir($uploads, 0o777, true);
  $target = $uploads . '/' . $file['name'];
  rename($file['tmp_name'], $target);
  return ['file' => $target, 'type' => $types[$extension], 'url' => 'https://space.test/' . $file['name']];
}
function wp_insert_attachment(array $attachment, string $filePath): int {
  $id = 200 + count($GLOBALS['posts']);
  $GLOBALS['posts'][$id] = new TestPost(
    $id,
    'attachment',
    $attachment['post_title'],
    $attachment['post_mime_type'],
    $attachment['post_status']
  );
  $GLOBALS['attachmentFiles'][$id] = $filePath;
  // WordPress fires add_attachment here; content-kernel.php's hook is what
  // stamps the Space, so the test calls the engine's own stamper rather than
  // writing the meta itself.
  spacefast_content_scope_attachment($id);
  return $id;
}
function wp_trash_post(int $postId): mixed {
  $post = $GLOBALS['posts'][$postId] ?? null;
  if ($post === null) return false;
  $post->post_status = 'trash';
  return $post;
}

function get_role(string $name): object {
  return (object) ['capabilities' => match ($name) {
    'administrator' => [
      'read' => true, 'edit_posts' => true, 'upload_files' => true,
      'edit_others_posts' => true, 'delete_others_posts' => true,
    ],
    'editor' => ['read' => true, 'edit_posts' => true, 'upload_files' => true,
      'edit_others_posts' => true, 'delete_others_posts' => true],
    default => ['read' => true],
  }];
}
// WordPress's chain, reduced: map_meta_cap turns edit_post/delete_post into the
// primitives, and user_has_cap answers from the projection
// content-principals.php derives for THIS request's Grant decision. The
// projection only applies to the request's own principal, so it is keyed to the
// established current user; the harness stands one in.
function get_current_user_id(): int { return 1; }
function current_user_can(string $capability, mixed ...$arguments): bool {
  $projected = spacefast_content_principal_capabilities([], [], [], (object) ['ID' => 1]);
  $primitive = match ($capability) {
    'edit_post' => 'edit_others_posts',
    'delete_post' => 'delete_others_posts',
    default => $capability,
  };
  return ($projected[$primitive] ?? false) === true;
}

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

function enter(string $spaceId, string $role): void {
  $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
  $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $role;
  $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = [
    'kind' => 'user',
    'actor_id' => 'usr_ada',
    'issuer' => 'https://issuer-a.example',
    'subject' => 'ada',
    'profile' => ['display_name' => 'Ada'],
  ];
}

enter('spc_alpha', 'editor');
spacefast_content_storage_register_abilities();
$abilityNames = array_keys($registered);
$abilityMeta = array_map(
  static fn (array $ability): array => [
    'category' => $ability['category'],
    'public' => $ability['meta']['public'],
    'annotations' => $ability['meta']['annotations'],
  ],
  $registered
);

// A subscriber holds no upload capability: WordPress refuses before any code
// here looks at a Space.
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'subscriber';
$listAsSubscriber = call_ability('zero/storage-list', []);
$uploadAsSubscriber = call_ability('zero/storage-upload', [
  'filename' => 'x.txt', 'contentBase64' => base64_encode('x'),
]);

$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$rootFile = call_ability('zero/storage-upload', [
  'filename' => 'notes.txt',
  'contentBase64' => base64_encode('hello storage'),
  'alt' => 'Some notes',
]);
$nested = call_ability('zero/storage-upload', [
  'filename' => 'shot.png',
  'contentBase64' => base64_encode('pretend-png'),
  'folder' => 'photos/2026',
  'title' => 'A screenshot',
]);
$refusedType = call_ability('zero/storage-upload', [
  'filename' => 'evil.php', 'contentBase64' => base64_encode('<?php'),
]);
$badBase64 = call_ability('zero/storage-upload', [
  'filename' => 'a.txt', 'contentBase64' => 'not base64!!',
]);
$traversalName = call_ability('zero/storage-upload', [
  'filename' => '../escape.txt', 'contentBase64' => base64_encode('x'),
]);
$traversalFolder = call_ability('zero/storage-upload', [
  'filename' => 'b.txt', 'contentBase64' => base64_encode('x'), 'folder' => 'photos/../../etc',
]);

$listAll = call_ability('zero/storage-list', []);
$listFolder = call_ability('zero/storage-list', ['folder' => 'photos/2026']);
$listParentShallow = call_ability('zero/storage-list', ['folder' => 'photos']);
$listParentDeep = call_ability('zero/storage-list', ['folder' => 'photos', 'recursive' => true]);
$listRootShallow = call_ability('zero/storage-list', ['folder' => '']);
$listRootDeep = call_ability('zero/storage-list', ['folder' => '', 'recursive' => true]);
$listMissingFolder = call_ability('zero/storage-list', ['folder' => 'nope']);
$searched = call_ability('zero/storage-list', ['search' => 'screenshot']);
$oversizedPage = call_ability('zero/storage-list', ['perPage' => 1000]);

$moved = call_ability('zero/storage-move', ['id' => $rootFile['id'], 'folder' => 'photos']);
$movedToRoot = call_ability('zero/storage-move', ['id' => $nested['id'], 'folder' => '']);

// Another Space on the same site cannot see or touch this one's files.
enter('spc_beta', 'editor');
$otherSpaceGet = call_ability('zero/storage-get', ['id' => $rootFile['id']]);
$otherSpaceDelete = call_ability('zero/storage-delete', ['id' => $rootFile['id']]);
$otherSpaceList = call_ability('zero/storage-list', []);

enter('spc_alpha', 'editor');
$deleted = call_ability('zero/storage-delete', ['id' => $rootFile['id']]);
$listAfterDelete = call_ability('zero/storage-list', []);
$getAfterDelete = call_ability('zero/storage-get', ['id' => $rootFile['id']]);

// The dispatch door runs the same permission_callback the agent door does.
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'subscriber';
$dispatchDenied = null;
try {
  spacefast_content_storage_dispatch('storage.list', ['operation' => 'storage.list']);
} catch (Spacefast_Content_Error $error) {
  $dispatchDenied = ['code' => $error->codeName, 'status' => $error->status];
}
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
$dispatched = spacefast_content_handle_request(
  ['operation' => 'storage.get', 'id' => $nested['id']],
  true
);

echo json_encode([
  'ability_names' => $abilityNames,
  'refused' => $refused,
  'ability_meta' => $abilityMeta,
  'list_as_subscriber' => $listAsSubscriber,
  'upload_as_subscriber' => $uploadAsSubscriber,
  'root_file' => $rootFile,
  'nested' => $nested,
  'refused_type' => $refusedType,
  'bad_base64' => $badBase64,
  'traversal_name' => $traversalName,
  'traversal_folder' => $traversalFolder,
  'list_all' => array_map(static fn (array $f): int => $f['id'], $listAll['files']),
  'list_folder' => array_map(static fn (array $f): int => $f['id'], $listFolder['files']),
  'list_parent_shallow' => array_map(static fn (array $f): int => $f['id'], $listParentShallow['files']),
  'list_parent_deep' => array_map(static fn (array $f): int => $f['id'], $listParentDeep['files']),
  'list_root_shallow' => array_map(static fn (array $f): int => $f['id'], $listRootShallow['files']),
  'list_root_deep' => array_map(static fn (array $f): int => $f['id'], $listRootDeep['files']),
  'list_missing_folder' => $listMissingFolder,
  'searched' => array_map(static fn (array $f): int => $f['id'], $searched['files']),
  'oversized_page' => $oversizedPage,
  'moved_folder' => $moved['folder'],
  'moved_to_root_folder' => $movedToRoot['folder'],
  'other_space_get' => $otherSpaceGet,
  'other_space_delete' => $otherSpaceDelete,
  'other_space_list' => $otherSpaceList['files'],
  'deleted' => $deleted,
  'list_after_delete' => array_map(static fn (array $f): int => $f['id'], $listAfterDelete['files']),
  'get_after_delete' => $getAfterDelete,
  'deleted_post_status' => $GLOBALS['posts'][$rootFile['id']]->post_status,
  'dispatch_denied' => $dispatchDenied,
  'dispatched_id' => $dispatched['id'],
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
    ability_names: string[];
    refused: string[];
    ability_meta: Record<
      string,
      { category: string; public: boolean; annotations: Record<string, boolean> }
    >;
    list_as_subscriber: { error: string; status: number };
    upload_as_subscriber: { error: string; status: number };
    root_file: { id: number; filename: string; alt: string; folder: string; mimeType: string };
    nested: { id: number; title: string; folder: string; mimeType: string };
    refused_type: { error: string; status: number };
    bad_base64: { error: string; status: number };
    traversal_name: { error: string; status: number };
    traversal_folder: { error: string; status: number };
    list_all: number[];
    list_folder: number[];
    list_parent_shallow: number[];
    list_parent_deep: number[];
    list_root_shallow: number[];
    list_root_deep: number[];
    list_missing_folder: { error: string; status: number };
    searched: number[];
    oversized_page: { error: string; status: number };
    moved_folder: string;
    moved_to_root_folder: string;
    other_space_get: { error: string; status: number };
    other_space_delete: { error: string; status: number };
    other_space_list: unknown[];
    deleted: { id: number; trashed: boolean };
    list_after_delete: number[];
    get_after_delete: { error: string; status: number };
    deleted_post_status: string;
    dispatch_denied: { code: string; status: number };
    dispatched_id: number;
  };

  // The whole surface is Abilities: one dispatcher, and the same registration
  // is what an agent reaches through the MCP adapter.
  expect({ names: result.ability_names, refused: result.refused }).toEqual({
    names: [
      "zero/storage-list",
      "zero/storage-get",
      "zero/storage-upload",
      "zero/storage-move",
      "zero/storage-delete",
    ],
    refused: [],
  });
  expect(result.ability_meta["zero/storage-list"]).toEqual({
    category: "zero-storage",
    public: true,
    annotations: { readonly: true, destructive: false, idempotent: true },
  });
  // Uploading twice is two files, so it is not idempotent; trashing the same
  // file twice lands in the same place, so it is.
  expect(result.ability_meta["zero/storage-upload"]?.annotations).toEqual({
    readonly: false,
    destructive: false,
    idempotent: false,
  });
  expect(result.ability_meta["zero/storage-delete"]?.annotations).toEqual({
    readonly: false,
    destructive: true,
    idempotent: true,
  });
  expect(Object.values(result.ability_meta).every((meta) => meta.public)).toBe(true);

  // Authorization is WordPress's: the projected role decides, and a subscriber
  // holds no upload capability.
  expect([result.list_as_subscriber, result.upload_as_subscriber]).toEqual([
    { error: "permission_denied", status: 403 },
    { error: "permission_denied", status: 403 },
  ]);

  // An upload is a real WordPress attachment, in the Space's own uploads root.
  expect(result.root_file).toMatchObject({
    filename: "notes.txt",
    alt: "Some notes",
    folder: "",
    mimeType: "text/plain",
  });
  expect(result.nested).toMatchObject({
    title: "A screenshot",
    folder: "photos/2026",
    mimeType: "image/png",
  });

  // WordPress owns the allowed-type verdict, and nothing that fails validation
  // becomes a stored file.
  expect([
    result.refused_type,
    result.bad_base64,
    result.traversal_name,
    result.traversal_folder,
  ]).toEqual([
    { error: "zero_storage_type_refused", status: 415 },
    { error: "zero_storage_content_invalid", status: 400 },
    { error: "zero_storage_filename_invalid", status: 400 },
    { error: "zero_storage_folder_invalid", status: 400 },
  ]);

  // Folders are a real tree: a listing is shallow unless it asks to recurse.
  expect(result.list_all).toEqual([result.nested.id, result.root_file.id]);
  expect(result.list_folder).toEqual([result.nested.id]);
  expect(result.list_parent_shallow).toEqual([]);
  expect(result.list_parent_deep).toEqual([result.nested.id]);
  // An empty folder path is the Space root: shallow returns only root files,
  // recursive returns every file (like an unfiltered listing).
  expect(result.list_root_shallow).toEqual([result.root_file.id]);
  expect(result.list_root_deep).toEqual([result.nested.id, result.root_file.id]);
  expect(result.list_missing_folder).toEqual({
    error: "zero_storage_folder_not_found",
    status: 404,
  });
  expect(result.searched).toEqual([result.nested.id]);
  expect(result.oversized_page).toEqual({ error: "zero_storage_page_invalid", status: 400 });

  // Moving sets the folder; an empty path is the Space root, not an error.
  expect([result.moved_folder, result.moved_to_root_folder]).toEqual(["photos", ""]);

  // One site hosts many Spaces: another Space's files are not there to read,
  // list, or trash.
  expect(result.other_space_get).toEqual({ error: "zero_storage_not_found", status: 404 });
  expect(result.other_space_delete).toEqual({ error: "zero_storage_not_found", status: 404 });
  expect(result.other_space_list).toEqual([]);

  // Deleting trashes: the file leaves every listing and stays recoverable
  // rather than being destroyed.
  expect(result.deleted).toEqual({ id: result.root_file.id, trashed: true });
  expect(result.deleted_post_status).toBe("trash");
  expect(result.list_after_delete).toEqual([result.nested.id]);
  expect(result.get_after_delete).toEqual({ error: "zero_storage_not_found", status: 404 });

  // The dispatcher reaches the same operations through the content endpoint,
  // and through the same permission_callback — one authorization, two doors.
  expect(result.dispatch_denied).toEqual({ code: "zero_storage_forbidden", status: 403 });
  expect(result.dispatched_id).toBe(result.nested.id);
});
