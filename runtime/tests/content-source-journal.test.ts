// Drives the real content kernel against a real MySQL server, because the only
// property that matters here is a database property: the journal row appended
// from `save_post` lives on `$wpdb`'s connection, so a rolled-back save takes
// its intent with it. A fake `$wpdb` would prove the insert was attempted and
// nothing about whether intent can outlive the write.
//
// The serializer is the real pinned php-toolkit, as in
// content-source-sync.test.ts: the ledger this lane reads is produced by the
// real serializer or it is not the ledger.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { fetchBlocksEnginePlugin } from "../../scripts/fetch-blocks-engine.mjs";
import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";
import {
  MYSQL_SETUP_TIMEOUT_MS,
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");
const journalTable = path.join(repoRoot, "runtime/engine/shared/content-source-journal.php");
const applicationJournal = path.join(repoRoot, "runtime/engine/shared/application-journal.php");
const SPACE_ID = "spc_alpha";
const SOURCE = "content/projects/launch.md";
const BINDING = "sync.projects-body";
const TSX_BINDING = "sync.pages-about";
const TSX_SOURCE = "content/pages/about.tsx";
const CONTAINER_NAME_PREFIX = "stattic-content-source-journal";
const ROOT_PASSWORD = "content-source-journal-secret";
const DATABASE = "content_source_journal_test";

const toolkitPhar = await fetchToolkitPhar();
const blocksEnginePlugin = await fetchBlocksEnginePlugin();
let mysql: MysqlContainer;

beforeAll(async () => {
  mysql = await startMysqlContainer({
    namePrefix: CONTAINER_NAME_PREFIX,
    database: DATABASE,
    rootPassword: ROOT_PASSWORD,
    flavor: "mariadb",
  });
}, MYSQL_SETUP_TIMEOUT_MS + 10_000);

afterAll(() => {
  mysql?.stop();
  stopMysqlContainers(CONTAINER_NAME_PREFIX);
});

/** A ContentModelRelease the real `spacefast_content_model_read_release` accepts. */
function releaseRoot() {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-source-journal-"));
  const storage = path.join(root, ".stattic/storage");
  const revision = `sha256:${"a".repeat(64)}`;
  const dir = path.join(storage, `spaces/${SPACE_ID}/content-model/releases`, revision.slice(7));
  mkdirSync(dir, { recursive: true });
  const binding = [
    `'id' => '${BINDING}'`,
    `'resourceId' => 'projects'`,
    `'fieldId' => 'project-body'`,
    `'source' => '${SOURCE}'`,
    `'format' => 'md'`,
    `'slug' => 'launch'`,
    `'postType' => 'post'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  // A compile-class binding beside the two-way one. Nothing writes back to a
  // `.tsx`, so its post has no ledger and today's ledger lookup is what stops it
  // journalling at all.
  const tsxBinding = [
    `'id' => '${TSX_BINDING}'`,
    `'resourceId' => 'pages'`,
    `'fieldId' => 'page-body'`,
    `'source' => '${TSX_SOURCE}'`,
    `'format' => 'tsx'`,
    `'slug' => 'about'`,
    `'postType' => 'page'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  const materializedBinding = binding
    .replace(BINDING, "sync.posts-created")
    .replace(SOURCE, "content/posts/hello-world.md")
    .replace("'launch'", "'hello-world'");
  const scheduledBinding = binding
    .replace(BINDING, "sync.posts-scheduled")
    .replace(SOURCE, "content/posts/scheduled.md")
    .replace("'launch'", "'scheduled'");
  const materialization = [
    `'resourceId' => 'posts'`,
    `'fieldId' => 'post-body'`,
    `'directory' => 'content/posts'`,
    `'suffix' => '.md'`,
    `'format' => 'md'`,
    `'postType' => 'post'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  const resource = (id: string, postType: string) =>
    `['id' => '${id}', 'label' => '${id}', 'kind' => 'builtin', 'postType' => '${postType}', 'publicRead' => true, 'fields' => []]`;
  const php = [
    "<?php",
    "declare(strict_types=1);",
    "",
    "return [",
    "    'format' => 'spacefast.wordpress-content-model.php',",
    "    'version' => 1,",
    `    'revision' => '${revision}',`,
    `    'postTypes' => [${resource("posts", "post")}, ${resource("pages", "page")}],`,
    "    'scfFieldGroups' => [], 'tables' => [],",
    "    'pages' => [], 'abilities' => [], 'hooks' => [],",
    `    'syncBindings' => [[${binding}], [${tsxBinding}], [${binding.replace(BINDING, "sync.projects-meta").replace(SOURCE, "content/projects/details.md").replace("'launch'", "'details'").replace("'post_content'", "'project_body'")}], [${materializedBinding}], [${scheduledBinding}]],`,
    `    'materializations' => [[${materialization}]],`,
    "];",
    "",
  ].join("\n");
  writeFileSync(path.join(dir, "content-model.php"), php);
  writeFileSync(
    path.join(dir, "content-model.sha256"),
    `sha256:${new Bun.CryptoHasher("sha256").update(php).digest("hex")}`,
  );
  writeFileSync(path.join(storage, `spaces/${SPACE_ID}/content-model/active-release`), revision);
  return { storage, revision, releaseDir: dir };
}

/**
 * WordPress for exactly the surface this lane touches, plus a real hook
 * registry: the kernel registers `save_post` at require time and the point of
 * the test is that firing that hook journals, so a no-op `add_action` would
 * quietly test nothing. These are defined before the PHAR loads so its own
 * `function_exists`-guarded compat shims stand down.
 */
const WP_STUBS = String.raw`
$posts = [];
$meta = [];
$options = [];
$hooks = [];
$nextId = 100;

function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void {
  global $hooks; $hooks[$hook][] = [$priority, $callback, $args];
}
function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function remove_action(...$a): void {}
function remove_filter(...$a): void {}
function do_action(string $hook, ...$args): void {
  global $hooks;
  $registered = $hooks[$hook] ?? [];
  usort($registered, static fn ($a, $b) => $a[0] <=> $b[0]);
  foreach ($registered as [$priority, $callback, $accepted]) {
    $callback(...array_slice($args, 0, $accepted));
  }
}
function __return_false() { return false; }
function __return_true() { return true; }
function __return_zero() { return 0; }
function __return_empty_string() { return ''; }

function wp_insert_post(array $post, bool $returnError = false): int {
  global $posts, $nextId;
  $id = (int) ($post['ID'] ?? 0);
  if ($id === 0) { $id = $nextId++; }
  $posts[$id] = array_merge($posts[$id] ?? [], $post, ['ID' => $id]);
  do_action('save_post', $id, (object) $posts[$id]);
  return $id;
}
function check_and_publish_future_post(int $postId): void {
  $post = get_post($postId);
  if ($post && $post->post_status === 'future') {
    wp_insert_post(['ID' => $postId, 'post_status' => 'publish']);
  }
}
function get_permalink(object $post): string { return 'https://space.test/' . $post->post_name; }
function get_post(int $id): ?object {
  global $posts;
  return isset($posts[$id]) ? (object) $posts[$id] : null;
}
function get_posts(array $args): array {
  global $posts, $meta;
  $out = [];
  foreach ($posts as $id => $post) {
    $ok = true;
    if (isset($args['post_status']) && $args['post_status'] !== 'any' && !in_array($post['post_status'] ?? '', (array) $args['post_status'], true)) $ok = false;
    foreach (($args['meta_query'] ?? []) as $key => $clause) {
      if ($key === 'relation' || !is_array($clause)) continue;
      if (($meta[$id][$clause['key']] ?? null) !== $clause['value']) { $ok = false; }
    }
    if (isset($args['name']) && ($post['post_name'] ?? null) !== $args['name']) $ok = false;
    if (isset($args['post_name__in']) && !in_array($post['post_name'] ?? '', $args['post_name__in'], true)) $ok = false;
    if (isset($args['post_type']) && $args['post_type'] !== 'any'
        && ($post['post_type'] ?? null) !== $args['post_type']) $ok = false;
    if ($ok) $out[] = (object) $post;
  }
  return $out;
}
function update_post_meta(int $id, string $key, mixed $value): void {
  global $meta;
  $existed = array_key_exists($key, $meta[$id] ?? []);
  $meta[$id][$key] = $value;
  do_action($existed ? 'updated_post_meta' : 'added_post_meta', 1, $id, $key, $value);
}
function wp_update_post(array $post, bool $error = false): int { return wp_insert_post($post, $error); }
function wp_untrash_post(int $id): object|false {
  global $posts;
  if (!isset($posts[$id])) return false;
  $posts[$id]['post_status'] = 'draft';
  return get_post($id);
}
function wp_trash_post(int $id): object|false {
  $post = get_post($id);
  if (!$post) return false;
  update_post_meta($id, '_wp_trash_meta_status', $post->post_status);
  wp_insert_post(['ID' => $id, 'post_status' => 'trash']);
  return get_post($id);
}
function delete_post_meta(int $id, string $key): bool {
  global $meta; unset($meta[$id][$key]); return true;
}
function get_post_meta(int $id, string $key, bool $single = false): mixed {
  global $meta; return $meta[$id][$key] ?? '';
}
function get_option(string $key) { global $options; return $options[$key] ?? false; }
function update_option(string $key, $value, $autoload = null): bool {
  global $options; $options[$key] = $value; return true;
}
function is_wp_error(mixed $value): bool { return false; }
function get_date_from_gmt(string $value): string { return $value; }
function sanitize_title(string $value): string { return strtolower($value); }
// Every save cuts a revision, so the newest revision id moves with the post.
$revisions = [];
function wp_save_post_revision(int $id): int {
  global $revisions;
  $revisions[$id] = ($revisions[$id] ?? $id * 10) + 1;
  return $revisions[$id];
}
function wp_get_post_revisions(int $id, array $args = []): array {
  global $revisions;
  return [(object) ['ID' => $revisions[$id] ?? $id]];
}
function wp_is_post_revision(int $id) { return false; }
function wp_is_post_autosave(int $id) { return false; }
function wp_get_current_user(): object {
  return (object) ['display_name' => 'Robin Vega', 'user_email' => 'robin@example.com'];
}

/**
 * $wpdb over the real server. Only prepare/query are used by the lane, and
 * prepare follows WordPress's contract: bare %s/%d placeholders that the
 * method itself quotes.
 */
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
final class Test_Wpdb {
  public function __construct(public mysqli $link) {}
  public function query(string $sql) {
    $result = $this->link->query($sql);
    return $result === false ? false : $this->link->affected_rows;
  }
  public function get_results(string $sql, string $mode): array {
    return $this->link->query($sql)->fetch_all(MYSQLI_ASSOC);
  }
  public function prepare(string $sql, ...$args): string {
    $index = 0;
    return (string) preg_replace_callback('/%[sdf]/', function (array $match) use (&$index, $args): string {
      $value = $args[$index++] ?? '';
      return $match[0] === '%d'
        ? (string) (int) $value
        : "'" . $this->link->real_escape_string((string) $value) . "'";
    }, $sql);
  }
}
`;

const journalRowSchema = z.object({
  entry_id: z.string(),
  binding_id: z.string(),
  open_binding_id: z.string().nullable(),
  state: z.string(),
  payload: z.object({
    bindingId: z.string(),
    source: z.string(),
    postId: z.number(),
    wordpressRevisionId: z.number(),
    baseRevision: z.string(),
    author: z.object({ name: z.string(), email: z.string() }),
  }),
});
type JournalRow = z.infer<typeof journalRowSchema>;

const claimSchema = z.object({
  entry: z.object({
    id: z.string(),
    spaceId: z.string(),
    operationId: z.string(),
    store: z.string(),
    kind: z.string(),
  }),
  fence: z.object({ sink: z.string(), attempt: z.number() }),
  idempotencyKey: z.string(),
});

type Probe = { step: string; rows: z.core.JSONType };

async function runScenario(): Promise<Probe[]> {
  const { storage, revision, releaseDir } = releaseRoot();
  const url = new URL(mysql.url);
  const script = `<?php
declare(strict_types=1);
${WP_STUBS}
require_once 'phar://' . ${JSON.stringify(toolkitPhar)} . '/vendor/autoload.php';
require_once ${JSON.stringify(journalTable)};
require_once ${JSON.stringify(applicationJournal)};
putenv('SPACEFAST_APPLICATION_JOURNAL_SINKS=control-plane:mail,control-plane:content-source');
$GLOBALS['SPACEFAST_CONTENT_BLOCKS_ENGINE_PLUGIN'] = ${JSON.stringify(blocksEnginePlugin)};
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = ${JSON.stringify(SPACE_ID)};
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = ${JSON.stringify(releaseDir)};
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = ${JSON.stringify(revision)};

$link = new mysqli(${JSON.stringify(url.hostname)}, 'root', ${JSON.stringify(ROOT_PASSWORD)}, ${JSON.stringify(DATABASE)}, ${Number(url.port)});
$link->set_charset('utf8mb4');
$wpdb = new Test_Wpdb($link);

require_once ${JSON.stringify(kernel)};

$probes = [];
function journal_rows(mysqli $link): array {
  $result = $link->query('SELECT entry_id, binding_id, open_binding_id, state, attempt_count, payload_json FROM ' . STATTIC_CONTENT_SOURCE_JOURNAL_TABLE . ' ORDER BY entry_id');
  $rows = [];
  foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
    $row['payload'] = json_decode((string) $row['payload_json'], true);
    unset($row['payload_json']);
    $rows[] = $row;
  }
  return $rows;
}
function probe(string $step, mysqli $link): void {
  global $probes;
  $probes[] = ['step' => $step, 'rows' => journal_rows($link)];
}

// WordPress boot: the kernel's init hooks install the journal table.
do_action('init');

// Bind the document. This writes the post through wp_insert_post, which fires
// save_post — the reconciliation's own write must not journal itself.
$receipt = spacefast_content_handle_request([
  'operation' => 'source.reconcile',
  'state' => 'initial',
  'bindingId' => ${JSON.stringify(BINDING)},
  'source' => ${JSON.stringify(SOURCE)},
  'text' => "# Launch\n\nThe first paragraph.\n",
  'observedSourceRevision' => 'blob-1',
  'operationId' => 'op_000001',
], true);
$probes[] = ['step' => 'bound', 'rows' => journal_rows($link)];
$postId = null;
global $posts;
foreach ($posts as $id => $post) { $postId = $id; }

// One editor save: WordPress cuts the revision, then fires save_post.
function editor_save(int $postId, string $blocks): void {
  global $posts;
  $posts[$postId]['post_content'] = $blocks;
  wp_save_post_revision($postId);
  do_action('save_post', $postId, (object) $posts[$postId]);
}

// Metadata edits carry source intent even when the body stays unchanged.
$titleBefore = $posts[$postId]['post_title'];
$link->query('START TRANSACTION');
$posts[$postId]['post_title'] = 'Renamed';
wp_save_post_revision($postId);
do_action('save_post', $postId, (object) $posts[$postId]);
probe('after-title-only-save', $link);
$link->query('ROLLBACK');
$posts[$postId]['post_title'] = $titleBefore;

// An editor save whose transaction rolls back.
$link->query('START TRANSACTION');
editor_save($postId, "<!-- wp:paragraph -->\n<p>Rolled back.</p>\n<!-- /wp:paragraph -->");
probe('inside-rolled-back-save', $link);
$link->query('ROLLBACK');
probe('after-rollback', $link);

// The same edit, committed.
editor_save($postId, "<!-- wp:paragraph -->\n<p>Committed edit.</p>\n<!-- /wp:paragraph -->");
probe('after-first-save', $link);

// A second save before anything drained folds onto the pending entry.
editor_save($postId, "<!-- wp:paragraph -->\n<p>Second edit.</p>\n<!-- /wp:paragraph -->");
probe('after-second-save', $link);

$probes[] = ['step' => 'legacy-mail-drain', 'rows' => _stattic_application_journal_claim($link, 'control-plane:mail', 25, 120)];
$claims = _stattic_application_journal_claim($link, STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK, 25, 120);
$probes[] = ['step' => 'claims', 'rows' => $claims];
probe('after-claim', $link);

$claim = $claims[0];
$completed = _stattic_application_journal_complete($link, [
  'format' => 'spacefast.application-delivery',
  'version' => 1,
  'fence' => $claim['fence'],
  'idempotencyKey' => $claim['idempotencyKey'],
  'recordedAt' => gmdate('Y-m-d\TH:i:s\Z'),
  'status' => 'delivered',
  'downstreamReceipt' => 'commit-abc',
]);
$probes[] = ['step' => 'completed', 'rows' => $completed];
probe('after-complete', $link);

// A post the editor created under a materializing collection. It has no file
// behind it, so it has no binding and no ledger — today's two skips — and the
// only thing to record is that a path has to be minted for it.
$createdId = wp_insert_post([
  'post_type' => 'post',
  'post_status' => 'draft',
  'post_name' => 'hello-world',
  'post_title' => 'Hello world',
  'post_content' => "<!-- wp:paragraph -->\n<p>Editor draft.</p>\n<!-- /wp:paragraph -->",
]);
update_post_meta($createdId, SPACEFAST_CONTENT_SPACE_META, ${JSON.stringify(SPACE_ID)});
probe('after-editor-create', $link);
editor_save($createdId, "<!-- wp:paragraph -->\n<p>Second draft.</p>\n<!-- /wp:paragraph -->");
probe('after-editor-second-save', $link);
$firstMaterialized = spacefast_content_materialize_source(['operationId' => 'op_firstmaterialize', 'postId' => $createdId], true);
$pendingInspection = spacefast_content_inspect_source(['bindingId' => 'materialize.' . $createdId], true);
$pendingResolution = [
  'operationId' => 'op_pendingresolution', 'bindingId' => 'materialize.' . $createdId,
  'source' => $pendingInspection['source'], 'observedSourceRevision' => 'blob-occupied',
  'expectedBaseRevision' => $pendingInspection['baseRevision'], 'expectedWordpressDigest' => $pendingInspection['wordpressDigest'],
  'text' => "Resolved first draft.\\n",
];
$pendingStale = [];
foreach (['expectedBaseRevision', 'expectedWordpressDigest'] as $field) {
  try {
    spacefast_content_resolve_source([...$pendingResolution, $field => 'sha256:' . str_repeat('0', 64)], true);
    $pendingStale[$field] = 'unexpected_success';
  } catch (Spacefast_Content_Error $error) { $pendingStale[$field] = $error->codeName; }
}
$pendingResolved = spacefast_content_resolve_source($pendingResolution, true);
$pendingReplay = spacefast_content_resolve_source($pendingResolution, true);
$resolvedMaterialized = spacefast_content_sync_receipt($createdId, 'op_pendingresolution');
$probes[] = ['step' => 'pending-resolution', 'rows' => [
  'inspection' => $pendingInspection, 'stale' => $pendingStale, 'resolved' => $pendingResolved,
  'replay' => $pendingReplay, 'receipt' => $resolvedMaterialized,
]];
probe('after-pending-resolution', $link);
$posts[$createdId]['post_title'] = 'Updated during build';
$posts[$createdId]['post_name'] = 'renamed-during-build';
editor_save($createdId, "<!-- wp:paragraph -->\n<p>Third draft during build.</p>\n<!-- /wp:paragraph -->");
probe('after-materialization-pending-save', $link);
$repeatMaterialized = spacefast_content_materialize_source(['operationId' => 'op_repeatmaterialize', 'postId' => $createdId], true);
$adopted = spacefast_content_sync_without_journal(static fn () => spacefast_content_sync_publish_document([
  'bindingId' => 'sync.posts-created', 'source' => 'content/posts/hello-world.md', 'format' => 'md',
  'text' => $resolvedMaterialized['sourceWrite']['text'], 'observedSourceRevision' => 'blob-resolved-materialized',
  'operationId' => 'op_activatematerialized', 'binding' => spacefast_content_model_sync_binding('sync.posts-created'),
]));
spacefast_content_source_journal_record_save($createdId);
probe('after-materialization-adopted', $link);
$probes[] = ['step' => 'materialization-adoption', 'rows' => [
  'first' => $firstMaterialized, 'resolved' => $resolvedMaterialized, 'repeat' => $repeatMaterialized, 'adopted' => $adopted,
  'post' => get_post($createdId), 'ledger' => spacefast_content_sync_ledger($createdId),
]];


// A page whose only source is the TSX the compiler owns. A compile-class
// binding never gets a ledger, so the ledger lookup is what stops this save
// journalling today.
$pageId = wp_insert_post([
  'post_type' => 'page',
  'post_status' => 'publish',
  'post_name' => 'about',
  'post_title' => 'About',
  'post_content' => "<!-- wp:paragraph -->\n<p>Compiled page.</p>\n<!-- /wp:paragraph -->",
]);
update_post_meta($pageId, SPACEFAST_CONTENT_SPACE_META, ${JSON.stringify(SPACE_ID)});
update_post_meta($pageId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . ${JSON.stringify(TSX_BINDING)});
probe('after-compile-class-save', $link);
$conversion = ['operation' => 'source.convert', 'operationId' => 'op_explicitconversion', 'postId' => $pageId, 'bindingId' => '${TSX_BINDING}'];
spacefast_content_request_conversion($conversion, true);
spacefast_content_request_conversion($conversion, true);
probe('after-explicit-conversion', $link);
spacefast_content_materialize_source(['operationId' => 'op_prepareconversion', 'bindingId' => '${TSX_BINDING}'], true);
$conversionInspection = spacefast_content_inspect_source(['bindingId' => '${TSX_BINDING}'], true);
spacefast_content_resolve_source([
  'operationId' => 'op_resolveconversion', 'bindingId' => '${TSX_BINDING}',
  'source' => $conversionInspection['source'], 'observedSourceRevision' => 'blob-existing-html',
  'expectedBaseRevision' => $conversionInspection['baseRevision'], 'expectedWordpressDigest' => $conversionInspection['wordpressDigest'],
  'text' => '<p>Resolved component page.</p>',
], true);
$probes[] = ['step' => 'conversion-resolution', 'rows' => [
  'inspection' => $conversionInspection, 'receipt' => spacefast_content_sync_receipt($pageId, 'op_resolveconversion'),
]];
probe('after-conversion-resolution', $link);
$inspection = spacefast_content_inspect_source(['bindingId' => ${JSON.stringify(BINDING)}], true);
$resolution = [
  'operationId' => 'op_explicitresolution', 'bindingId' => ${JSON.stringify(BINDING)},
  'source' => ${JSON.stringify(SOURCE)}, 'observedSourceRevision' => 'blob-current',
  'expectedBaseRevision' => $inspection['baseRevision'], 'expectedWordpressDigest' => $inspection['wordpressDigest'],
  'text' => "# Resolved\n\nThe selected result.\n",
];
try {
  spacefast_content_resolve_source([...$resolution, 'expectedWordpressDigest' => 'sha256:' . str_repeat('0', 64)], true);
  $probes[] = ['step' => 'stale-resolution', 'rows' => ['code' => 'unexpected_success']];
} catch (Spacefast_Content_Error $error) {
  $probes[] = ['step' => 'stale-resolution', 'rows' => ['code' => $error->codeName]];
}
spacefast_content_resolve_source($resolution, true);
spacefast_content_resolve_source($resolution, true);
probe('after-resolution', $link);
$probes[] = ['step' => 'resolved-receipt', 'rows' => spacefast_content_sync_receipt($postId, 'op_explicitresolution')];
$probes[] = ['step' => 'sync-status', 'rows' => spacefast_content_source_journal_status()];

$retryClaims = _stattic_application_journal_claim($link, STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK, 25, 120);
foreach ($retryClaims as $retryClaim) {
  if ($retryClaim['entry']['operationId'] !== 'op_explicitresolution') continue;
  _stattic_application_journal_complete($link, [
    'format' => 'spacefast.application-delivery', 'version' => 1,
    'fence' => $retryClaim['fence'], 'idempotencyKey' => $retryClaim['idempotencyKey'],
    'recordedAt' => gmdate('Y-m-d\\TH:i:s\\Z'), 'status' => 'retry',
    'retryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 60),
    'problem' => ['code' => 'content_sync_transient', 'message' => 'Try again.'],
  ]);
}
$probes[] = ['step' => 'retry-status', 'rows' => spacefast_content_source_journal_status()];
spacefast_content_source_journal_retry('op_explicitresolution');
$probes[] = ['step' => 'retried-status', 'rows' => spacefast_content_source_journal_status()];

spacefast_content_handle_request([
  'operation' => 'source.reconcile', 'state' => 'initial',
  'bindingId' => 'sync.projects-meta', 'source' => 'content/projects/details.md',
  'text' => "# Details\n\nOriginal field.\n", 'observedSourceRevision' => 'blob-meta',
  'operationId' => 'op_initialmeta',
], true);
$metaPost = spacefast_content_sync_find_post('sync.projects-meta', spacefast_content_model_sync_binding('sync.projects-meta'));
update_post_meta((int) $metaPost->ID, 'unrelated_field', 'Keep this in WordPress.');
probe('after-unrelated-meta', $link);
update_post_meta((int) $metaPost->ID, 'project_body', "<!-- wp:paragraph -->\n<p>Updated field.</p>\n<!-- /wp:paragraph -->");
probe('after-bound-meta', $link);

spacefast_content_handle_request([
  'operation' => 'source.reconcile', 'state' => 'initial', 'bindingId' => 'sync.posts-scheduled',
  'source' => 'content/posts/scheduled.md',
  'text' => '<!-- spacefast:document {"version":1,"title":"Scheduled","slug":"scheduled","status":"future","dateGmt":"2099-01-01T12:00:00Z"} -->' . "\nScheduled body.\n",
  'observedSourceRevision' => 'blob-scheduled', 'operationId' => 'op_initialscheduled',
], true);
$scheduledPost = spacefast_content_sync_find_post('sync.posts-scheduled', spacefast_content_model_sync_binding('sync.posts-scheduled'));
$GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = ${JSON.stringify(path.join(path.dirname(storage), "releases/test-engine"))};
unset($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'], $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'], $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION']);
do_action('publish_future_post', (int) $scheduledPost->ID);
probe('after-scheduled-publication', $link);
$probes[] = ['step' => 'scheduled-context', 'rows' => [
  'postStatus' => get_post((int) $scheduledPost->ID)->post_status,
  'space' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,
  'model' => $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] ?? null,
  'routes' => json_decode(file_get_contents(${JSON.stringify(storage + "/spaces/" + SPACE_ID + "/wordpress-routes.json")}), true),
]];

$probes[] = ['step' => 'ids', 'rows' => ['created' => $createdId, 'page' => $pageId]];

echo json_encode($probes, JSON_UNESCAPED_SLASHES);
`;
  const scriptPath = path.join(
    mkdtempSync(path.join(os.tmpdir(), "spacefast-source-journal-script-")),
    "s.php",
  );
  writeFileSync(scriptPath, script);
  const run = Bun.spawnSync([process.env.PHP_BINARY ?? "php", scriptPath], {
    env: { ...process.env, SPACEFAST_CONTENT_PHP_TOOLKIT_PHAR: toolkitPhar },
  });
  const stdout = run.stdout.toString();
  if (!run.success || stdout.trim() === "") {
    throw new Error(`content source journal scenario failed: ${run.stderr.toString()}\n${stdout}`);
  }
  // SAFETY: the scenario emits exactly this shape, one entry per probe, and
  // every field the assertions read is narrowed below before it is used.
  return JSON.parse(stdout) as Probe[];
}

function at(probes: Probe[], step: string): Probe {
  const found = probes.find((probe) => probe.step === step);
  if (!found) throw new Error(`no probe for ${step}: ${JSON.stringify(probes.map((p) => p.step))}`);
  return found;
}

function rows(probes: Probe[], step: string): JournalRow[] {
  return journalRowSchema.array().parse(at(probes, step).rows);
}

// A materialization has no binding and no common base, so its payload names the
// resource and the document instead of a binding and a base revision.
const materializeRowSchema = z.object({
  entry_id: z.string(),
  binding_id: z.string(),
  open_binding_id: z.string().nullable(),
  state: z.string(),
  payload: z.object({
    resourceId: z.string(),
    postId: z.number(),
    wordpressRevisionId: z.number(),
    author: z.object({ name: z.string(), email: z.string() }),
  }),
});

const anyRowSchema = z.object({ binding_id: z.string() }).loose();

/** The rows a step left whose binding id starts with `prefix`. */
function rowsFor(probes: Probe[], step: string, prefix: string) {
  return anyRowSchema
    .array()
    .parse(at(probes, step).rows)
    .filter((row) => row.binding_id.startsWith(prefix));
}

// One scenario, two behaviors. Standing up MariaDB and replaying the whole save
// sequence twice would buy nothing: both tests read probes from the same run.
let scenario: Promise<Probe[]> | null = null;
const probesOnce = () => (scenario ??= runScenario());

test(
  "an editor change to a bound field journals exactly once, and only if its save commits",
  async () => {
    const probes = await probesOnce();

    // The reconciliation writes the post itself. Journalling that write would
    // hand the drain back the answer it had just produced.
    expect(rows(probes, "bound")).toEqual([]);
    // A title-only edit still needs to reach source metadata.
    expect(rows(probes, "after-title-only-save")).toHaveLength(1);

    // Intent is visible inside the transaction that appended it...
    expect(rows(probes, "inside-rolled-back-save")).toHaveLength(1);
    // ...and cannot outlive it.
    expect(rows(probes, "after-rollback")).toEqual([]);

    const first = rows(probes, "after-first-save");
    expect(first).toHaveLength(1);
    const payload = journalRowSchema.parse(first[0]).payload;
    expect(payload.bindingId).toBe(BINDING);
    expect(payload.source).toBe(SOURCE);
    expect(payload.author).toEqual({ name: "Robin Vega", email: "robin@example.com" });
    expect(payload.baseRevision).toMatch(/^sha256:[a-f0-9]{64}$/);
    expect(first[0]?.state).toBe("queued");
    expect(first[0]?.open_binding_id).toBe(BINDING);

    // A burst of saves is one pending change to one file, carrying the latest
    // revision rather than a row per keystroke.
    const second = rows(probes, "after-second-save");
    expect(second).toHaveLength(1);
    expect(second[0]?.entry_id).toBe(first[0]?.entry_id);
    expect(second[0]?.payload).not.toEqual(first[0]?.payload);

    expect(at(probes, "legacy-mail-drain").rows).toEqual([]);
    const claims = claimSchema.array().parse(at(probes, "claims").rows);
    expect(claims).toHaveLength(1);
    const claim = claimSchema.parse(claims[0]);
    expect(claim.entry.kind).toBe("content-source-changed");
    expect(claim.entry.store).toBe("wordpress");
    expect(claim.entry.spaceId).toBe(SPACE_ID);
    expect(claim.entry.id).toBe(`${SPACE_ID}:${claim.entry.operationId}:0`);
    expect(claim.fence.sink).toBe("control-plane:content-source");
    expect(claim.fence.attempt).toBe(1);
    expect(claim.idempotencyKey).toBe(`${claim.entry.id}:control-plane:content-source`);

    // Claiming releases the coalescing slot, so a save landing mid-delivery
    // opens a fresh entry instead of rewriting one a drainer already read.
    const claimed = rows(probes, "after-claim");
    expect(claimed[0]?.state).toBe("delivering");
    expect(claimed[0]?.open_binding_id).toBeNull();

    expect(at(probes, "completed").rows).toBe(true);
    expect(rows(probes, "after-complete")[0]?.state).toBe("delivered");
    expect(rowsFor(probes, "after-unrelated-meta", "sync.projects-meta")).toEqual([]);
    const metaRows = rowsFor(probes, "after-bound-meta", "sync.projects-meta");
    expect(metaRows).toHaveLength(1);
    expect(journalRowSchema.parse(metaRows[0]).payload.source).toBe("content/projects/details.md");
    const scheduledRows = rowsFor(probes, "after-scheduled-publication", "sync.posts-scheduled");
    expect(scheduledRows).toHaveLength(1);
    expect(journalRowSchema.parse(scheduledRows[0]).payload.source).toBe(
      "content/posts/scheduled.md",
    );
    const scheduledContext = z
      .object({
        postStatus: z.string(),
        space: z.null(),
        model: z.null(),
        routes: z.record(z.string(), z.unknown()),
      })
      .parse(at(probes, "scheduled-context").rows);
    expect(scheduledContext.postStatus).toBe("publish");
    expect(scheduledContext.routes["/scheduled"]).toMatchObject({ postType: "post" });
  },
  MYSQL_SETUP_TIMEOUT_MS + 60_000,
);

test(
  "editor content sync and explicit component conversion share the durable journal",
  async () => {
    const probes = await probesOnce();
    const ids = z.object({ created: z.number(), page: z.number() }).parse(at(probes, "ids").rows);

    // A post the editor created has no binding to name, so the journal names the
    // synthetic one, which cannot collide with a compiler-minted `sync.*` id and
    // gives the same burst coalescing for free.
    const created = rowsFor(probes, "after-editor-create", "materialize.");
    expect(created).toHaveLength(1);
    const first = materializeRowSchema.parse(created[0]);
    expect(first.binding_id).toBe(`materialize.${ids.created}`);
    expect(first.open_binding_id).toBe(`materialize.${ids.created}`);
    expect(first.state).toBe("queued");
    expect(first.payload.resourceId).toBe("posts");
    expect(first.payload.postId).toBe(ids.created);
    expect(first.payload.author).toEqual({ name: "Robin Vega", email: "robin@example.com" });

    // A burst of saves on the same unbound document is one pending change.
    const second = rowsFor(probes, "after-editor-second-save", "materialize.");
    expect(second).toHaveLength(1);
    expect(second[0]?.entry_id).toBe(first.entry_id);
    expect(materializeRowSchema.parse(second[0]).payload.wordpressRevisionId).toBeGreaterThan(
      first.payload.wordpressRevisionId,
    );

    expect(rowsFor(probes, "after-materialization-pending-save", "materialize.")).toEqual(
      rowsFor(probes, "after-pending-resolution", "materialize."),
    );
    const pending = z
      .object({
        inspection: z.object({ bindingId: z.string(), source: z.string(), baseText: z.string() }),
        stale: z.record(z.string(), z.string()),
        resolved: z.object({ status: z.string(), postId: z.number() }),
        replay: z.object({ status: z.string(), postId: z.number() }),
        receipt: z.object({
          sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
        }),
      })
      .parse(at(probes, "pending-resolution").rows);
    expect(pending.inspection.bindingId).toBe(`materialize.${ids.created}`);
    expect(pending.inspection.source).toBe("content/posts/hello-world.md");
    expect(pending.inspection.baseText).toContain("Second draft.");
    expect(pending.stale).toEqual({
      expectedBaseRevision: "content_sync_resolution_stale",
      expectedWordpressDigest: "content_sync_resolution_stale",
    });
    expect(pending.resolved).toEqual({ status: "queued", postId: ids.created });
    expect(pending.replay).toEqual(pending.resolved);
    expect(pending.receipt.sourceWrite).toMatchObject({ expectedSourceRevision: "blob-occupied" });
    expect(pending.receipt.sourceWrite.text).toContain("Resolved first draft.");
    const adoption = z
      .object({
        first: z.object({
          sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
        }),
        resolved: z.object({
          sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
        }),
        repeat: z.object({
          sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
        }),
        adopted: z.object({ status: z.string(), postId: z.number() }),
        post: z.object({ post_content: z.string(), post_title: z.string(), post_name: z.string() }),
        ledger: z.object({ baseText: z.string() }),
      })
      .parse(at(probes, "materialization-adoption").rows);
    expect(adoption.repeat.sourceWrite).toEqual(adoption.resolved.sourceWrite);
    expect(adoption.first.sourceWrite.expectedSourceRevision).toBe("absent");
    expect(adoption.adopted).toEqual({ status: "adopted", postId: ids.created });
    expect(adoption.post.post_content).toContain("Third draft during build.");
    expect(adoption.post.post_title).toBe("Updated during build");
    expect(adoption.post.post_name).toBe("renamed-during-build");
    expect(adoption.ledger.baseText).toBe(adoption.resolved.sourceWrite.text);
    expect(rowsFor(probes, "after-materialization-adopted", "sync.posts-created")).toHaveLength(1);

    expect(rowsFor(probes, "after-compile-class-save", TSX_BINDING)).toEqual([]);
    const compiled = rowsFor(probes, "after-explicit-conversion", TSX_BINDING);
    expect(compiled).toHaveLength(1);
    const row = journalRowSchema.parse(compiled[0]);
    expect(row.entry_id).toBe(`${SPACE_ID}:op_explicitconversion:0`);
    expect(row.payload.source).toBe(TSX_SOURCE);
    expect(row.payload.postId).toBe(ids.page);
    const conversion = z
      .object({
        inspection: z.object({ source: z.string() }),
        receipt: z.object({
          sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
        }),
      })
      .parse(at(probes, "conversion-resolution").rows);
    expect(conversion.inspection.source).toBe(TSX_SOURCE.replace(/\.tsx$/, ".html"));
    expect(conversion.receipt.sourceWrite.expectedSourceRevision).toBe("blob-existing-html");
    expect(conversion.receipt.sourceWrite.text).toContain(`"componentSource":"${TSX_SOURCE}"`);
    expect(conversion.receipt.sourceWrite.text).toContain("Resolved component page.");
    const resolvedConversion = rowsFor(probes, "after-conversion-resolution", TSX_BINDING).find(
      (entry) => entry.entry_id === `${SPACE_ID}:op_resolveconversion:0`,
    );
    expect(
      z.object({ payload: z.object({ intent: z.string() }) }).parse(resolvedConversion).payload
        .intent,
    ).toBe("convert");
    expect(z.object({ code: z.string() }).parse(at(probes, "stale-resolution").rows).code).toBe(
      "content_sync_resolution_stale",
    );
    const resolved = rowsFor(probes, "after-resolution", BINDING).filter(
      (entry) => entry.entry_id === `${SPACE_ID}:op_explicitresolution:0`,
    );
    expect(resolved).toHaveLength(1);
    const prepared = z
      .object({
        status: z.literal("pulled"),
        sourceWrite: z.object({ text: z.string(), expectedSourceRevision: z.string() }),
      })
      .parse(at(probes, "resolved-receipt").rows);
    expect(prepared.sourceWrite.text).toContain("The selected result.");
    expect(prepared.sourceWrite.expectedSourceRevision).toBe("blob-current");
    const sync = z
      .object({ operations: z.array(z.object({ operationId: z.string(), state: z.string() })) })
      .parse(at(probes, "sync-status").rows);
    expect(
      sync.operations.find((entry) => entry.operationId === "op_explicitresolution")?.state,
    ).toBe("queued");
    const operationStatus = (step: string) =>
      z
        .object({
          operations: z.array(
            z.object({ operationId: z.string(), state: z.string(), attemptCount: z.number() }),
          ),
        })
        .parse(at(probes, step).rows)
        .operations.find((entry) => entry.operationId === "op_explicitresolution");
    expect(operationStatus("retry-status")).toMatchObject({ state: "retry", attemptCount: 1 });
    expect(operationStatus("retried-status")).toMatchObject({ state: "queued", attemptCount: 0 });
  },
  MYSQL_SETUP_TIMEOUT_MS + 60_000,
);
