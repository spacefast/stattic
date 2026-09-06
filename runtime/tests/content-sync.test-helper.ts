// Drives the real source-sync kernel against the real pinned serializers: the
// WordPress php-toolkit for Markdown and Automattic's blocks-engine transformer
// for HTML. Nothing here stubs the serializer or the block layer, because what
// those pinned bytes actually do to a document IS the lane — a substitute would
// prove nothing about what a repo file ends up holding.
//
// One driver, two formats. The reconciliation is format-blind, so re-running it
// per format would be nine copies of one proof; each format's suite asserts
// only what its own serializer decides.
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { fetchBlocksEnginePlugin } from "../../scripts/fetch-blocks-engine.mjs";
import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");
const SPACE_ID = "spc_alpha";
const BINDING = "sync.projects-body";
/**
 * A compile-class binding: `.tsx` compiles into blocks and nothing writes back
 * to it, so the editor taking its page over produces a NEW HTML file rather
 * than a reconciliation. Declared beside the two-way binding because one
 * release carries both classes.
 */
export const TSX_BINDING =
  "sync.pages." + new Bun.CryptoHasher("sha256").update("/docs/about").digest("hex").slice(0, 32);
export const TSX_SOURCE = "pages/docs/about.tsx";
/** Where a post the editor created — no file behind it — materializes. */
export const MATERIALIZE_DIRECTORY = "content/posts";

export type SyncFormat = "md" | "html";

/** One source path per format, so a binding's format and its extension agree. */
const SOURCE = {
  md: "content/projects/launch.md",
  html: "content/projects/launch.html",
} as const satisfies Record<SyncFormat, string>;

// Both pinned artifacts are build dependencies, like the runtime engine zip:
// fetched once, cached outside the tree, and required rather than skipped
// around, so a missing one fails loudly instead of quietly proving less.
const toolkitPhar = await fetchToolkitPhar();
const blocksEnginePlugin = await fetchBlocksEnginePlugin();

/**
 * A release root the real `spacefast_content_model_read_release` will accept: the
 * binding the kernel resolves has to come from a verified ContentModelRelease, not
 * from the request, so the test builds one instead of stubbing the lookup.
 */
function releaseRoot(format: SyncFormat) {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-source-sync-"));
  const storage = path.join(root, ".stattic/storage");
  const revision = `sha256:${"a".repeat(64)}`;
  const dir = path.join(storage, `spaces/${SPACE_ID}/content-model/releases`, revision.slice(7));
  mkdirSync(dir, { recursive: true });
  // The content model's own digest is over the generated PHP, and the kernel checks
  // it, so this writes the real one rather than a placeholder.
  const php = contentModelPhp(revision, format);
  writeFileSync(path.join(dir, "content-model.php"), php);
  writeFileSync(
    path.join(dir, "content-model.sha256"),
    `sha256:${new Bun.CryptoHasher("sha256").update(php).digest("hex")}`,
  );
  return { storage, revision, releaseDir: dir };
}

/** One built-in resource, in the release's own PHP spelling. */
function resource(id: string, postType: string) {
  return `['id' => '${id}', 'label' => '${id}', 'kind' => 'builtin', 'postType' => '${postType}', 'publicRead' => true, 'fields' => []]`;
}

function contentModelPhp(revision: string, format: SyncFormat) {
  // PHP array literal, written directly: the compiler's exporter is a different
  // lane's unit, and this test only needs one valid release on disk.
  const binding = [
    `'id' => '${BINDING}'`,
    `'resourceId' => 'projects'`,
    `'fieldId' => 'project-body'`,
    `'source' => '${SOURCE[format]}'`,
    `'format' => '${format}'`,
    `'slug' => 'launch'`,
    `'postType' => 'post'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  const tsxBinding = [
    `'id' => '${TSX_BINDING}'`,
    `'resourceId' => 'pages'`,
    `'fieldId' => 'page-body'`,
    `'source' => '${TSX_SOURCE}'`,
    `'publicPath' => '/docs/about'`,
    `'format' => 'tsx'`,
    `'slug' => 'page.${TSX_BINDING.slice(11)}'`,
    `'postType' => 'page'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  // Task 1's release field, resolved to its post type and storage exactly as a
  // binding is: where a document WordPress created and no file backs yet goes.
  const materialization = [
    `'resourceId' => 'posts'`,
    `'fieldId' => 'post-body'`,
    `'directory' => '${MATERIALIZE_DIRECTORY}'`,
    `'suffix' => '.md'`,
    `'format' => 'md'`,
    `'postType' => 'post'`,
    `'fieldStorage' => 'post_content'`,
  ].join(", ");
  return [
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
    `    'syncBindings' => [[${binding}], [${tsxBinding}]],`,
    `    'materializations' => [[${materialization}], ['resourceId' => 'pages', 'fieldId' => 'page-body', 'directory' => 'pages', 'suffix' => '.md', 'format' => 'md', 'postType' => 'page', 'fieldStorage' => 'post_content']],`,
    "];",
    "",
  ].join("\n");
}

/**
 * WordPress stubs for exactly the surface the sync kernel touches. Serialization
 * is deliberately absent: the PHAR defines the real parse_blocks() and
 * WP_HTML_Tag_Processor this kernel reads blocks through, and the blocks-engine
 * plugin defines the real HTML transformer.
 */
const WP_STUBS = String.raw`
$posts = [];
$meta = [];
$nextId = 100;
$wpdb = new class {
  public string $prefix = 'wp_';
  private array $snapshot = [];
  public function prepare(string $query, mixed ...$values): string { return $query; }
  public function get_var(string $query): mixed { return null; }
  public function query(string $query): int {
    if ($query === 'START TRANSACTION') $this->snapshot = [$GLOBALS['posts'], $GLOBALS['meta']];
    if ($query === 'ROLLBACK' && $this->snapshot !== []) [$GLOBALS['posts'], $GLOBALS['meta']] = $this->snapshot;
    return 1;
  }
};

// The kernel registers its WordPress hooks at require time. The PHAR's compat
// layer already defines some of them, so each is only filled in when missing.
foreach (['add_action', 'add_filter', 'remove_action', 'remove_filter'] as $hook) {
  if (!function_exists($hook)) {
    eval("function {$hook}() {}");
  }
}
foreach (['__return_false', '__return_true', '__return_zero', '__return_empty_string'] as $fn) {
  if (!function_exists($fn)) {
    eval("function {$fn}() { return null; }");
  }
}

function wp_insert_post(array $post, bool $returnError = false): int {
  global $posts, $nextId;
  $id = (int) ($post['ID'] ?? 0);
  if ($id === 0) { $id = $nextId++; }
  $existing = $posts[$id] ?? [];
  $posts[$id] = array_merge($existing, $post, ['ID' => $id]);
  return $id;
}
function get_post(int $id): ?object {
  global $posts;
  return isset($posts[$id]) ? (object) $posts[$id] : null;
}
function get_posts(array $args): array {
  global $posts, $meta;
  $out = [];
  foreach ($posts as $id => $post) {
    $ok = true;
    foreach (($args['meta_query'] ?? []) as $key => $clause) {
      if ($key === 'relation' || !is_array($clause)) continue;
      if (($meta[$id][$clause['key']] ?? null) !== $clause['value']) { $ok = false; }
    }
    if (isset($args['name']) && ($post['post_name'] ?? null) !== $args['name']) $ok = false;
    if (isset($args['post_type']) && $args['post_type'] !== 'any'
        && ($post['post_type'] ?? null) !== $args['post_type']) $ok = false;
    if ($ok) $out[] = (object) $post;
  }
  return $out;
}
function update_post_meta(int $id, string $key, mixed $value): void {
  global $meta; $meta[$id][$key] = $value;
}
function get_post_meta(int $id, string $key, bool $single = false): mixed {
  global $meta; return $meta[$id][$key] ?? '';
}
function is_wp_error(mixed $value): bool { return false; }
function home_url(string $path): string { return "https://space.test" . $path; }
function sanitize_title(string $value): string { return strtolower($value); }
function wp_save_post_revision(int $id): int { return $id + 1000; }
function wp_get_post_revisions(int $id, array $args = []): array {
  return [(object) ['ID' => $id + 1000]];
}
`;

export type Step =
  | {
      op: "activatePage";
      text: string;
      format: SyncFormat | "tsx";
      release?: string;
      invalidDigest?: boolean;
    }
  | { op: "inspectPage" }
  | { op: "renderPage"; snapshot?: { text: string; format: SyncFormat | "tsx" } }
  | { op: "reconcile"; state: "initial" | "bound"; text: string; baseRevision?: string }
  | { op: "editInWordPress"; blocks: string }
  // `ackOp` closes an operation other than the most recent one, which is how a
  // test reaches back to a receipt the store may since have evicted.
  | { op: "acknowledge"; baseRevision: string; ackOp?: number }
  // A document WordPress owns: created in the editor, carrying the Space meta
  // every other path stamps and no sync external id, because no file backs it.
  | {
      op: "createInWordPress";
      slug: string;
      blocks: string;
      postType?: "post" | "page";
      bound?: boolean;
      spaceId?: string;
      source?: string;
    }
  | { op: "lookupPage"; adopt?: boolean }
  // `post` materializes the editor-created document; `binding` is the
  // compile-class takeover, which names the binding instead.
  | { op: "materialize"; target: "post" | "binding"; managed?: boolean };

export type SyncLedger = {
  version: 1;
  bindingId: string;
  source: string;
  format: SyncFormat;
  baseText: string;
  textDigest: string;
  blocksDigest: string;
  wordpressRevisionId: number;
  serializerVersion: 1;
  revision: string;
  lastDirection: "push" | "pull";
};

type PreparedSourceWrite = {
  state: string;
  source: string;
  text: string;
  expectedSourceRevision: string;
  textDigest: string;
};

export type SyncReceipt = {
  // A reconciliation and the acknowledgement that closes it are both spellings
  // of the same ledger-carrying receipt.
  format: "spacefast.content-sync" | "spacefast.content-sync-ack-receipt";
  status: string;
  ledger: SyncLedger;
  sourceWrite?: PreparedSourceWrite;
};

/** The receipt a materialization answers with, pinned by Task 6's contract. */
export type MaterializeReceipt = {
  format: "spacefast.content-materialize";
  version: 1;
  status: string;
  operationId: string;
  sourceWrite: PreparedSourceWrite;
};

/** The scenario driver's own marker for a step that is not a kernel call. */
type DriverReceipt = {
  format: "test.driver";
  status: string;
  postId?: number | null;
  postStatus?: string;
  permalink?: string;
  blocks?: string;
  externalId?: string;
  spaceId?: string;
  ledger?: SyncLedger;
  activeRevision?: string;
  html?: string;
};

type SyncRepresentation = { text: string; digest: string; revision: string };

export type SyncProblem = {
  code: string;
  message?: string;
  details?: { base: SyncRepresentation; source: SyncRepresentation; wordpress: SyncRepresentation };
};

export type StepResult =
  | { ok: true; receipt: SyncReceipt | MaterializeReceipt | DriverReceipt }
  | { ok: false; error: SyncProblem };

export async function runScenario(format: SyncFormat, steps: Step[]): Promise<StepResult[]> {
  const { storage, revision, releaseDir } = releaseRoot(format);
  const script = `<?php
declare(strict_types=1);
${WP_STUBS}
require_once 'phar://' . ${JSON.stringify(toolkitPhar)} . '/vendor/autoload.php';
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = ${JSON.stringify(SPACE_ID)};
$GLOBALS['SPACEFAST_CONTENT_BLOCKS_ENGINE_PLUGIN'] = ${JSON.stringify(blocksEnginePlugin)};
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = ${JSON.stringify(storage)};
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = ${JSON.stringify(releaseDir)};
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = ${JSON.stringify(revision)};
require_once ${JSON.stringify(kernel)};

$steps = json_decode(${JSON.stringify(JSON.stringify(steps))}, true);
$results = [];
$op = 0;
$lastLedgerRevision = null;
foreach ($steps as $step) {
  // "@previous" chains a step onto the ledger the step before it produced,
  // which is what a real caller does with the revision from its last receipt.
  if (($step['baseRevision'] ?? null) === '@previous') {
    $step['baseRevision'] = $lastLedgerRevision ?? 'unset';
  }
  if ($step['op'] === 'editInWordPress') {
    // Stand in for a human editing in wp-admin: the post content changes
    // underneath the ledger, which is exactly what the pull path reconciles.
    global $posts;
    foreach ($posts as $id => $post) { $posts[$id]['post_content'] = $step['blocks']; }
    $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'edited']];
    continue;
  }
  if ($step['op'] === 'createInWordPress') {
    // wp-admin creating a document: the Space meta every write path stamps, and
    // deliberately no sync external id, because no file backs it yet.
    $createdId = wp_insert_post([
      'post_type' => $step['postType'] ?? 'post',
      'post_status' => 'draft',
      'post_name' => $step['slug'],
      'post_title' => $step['slug'],
      'post_content' => $step['blocks'],
    ]);
    update_post_meta($createdId, SPACEFAST_CONTENT_SPACE_META, $step['spaceId'] ?? ${JSON.stringify(SPACE_ID)});
    if (!empty($step['bound'])) update_post_meta($createdId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . ${JSON.stringify(TSX_BINDING)});
    if (isset($step['source'])) update_post_meta($createdId, SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META, $step['source']);
    $GLOBALS['createdPostId'] = $createdId;
    $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'created', 'postId' => $createdId]];
    continue;
  }
  try {
    if ($step['op'] === 'activatePage') {
      $model = require ${JSON.stringify(path.join(releaseDir, "content-model.php"))};
      $binding = $model['syncBindings'][1];
      $binding['format'] = $step['format'];
      $binding['source'] = preg_replace('/\\\\.tsx$/', '.' . $step['format'], $binding['source']);
      $digest = 'sha256:' . hash('sha256', $step['text']);
      $binding['documentSeed'] = ['text' => $step['text'], 'sha256' => !empty($step['invalidDigest']) ? 'sha256:' . str_repeat('0', 64) : $digest];
      if ($step['format'] === 'tsx') $binding['compiled'] = ['sha256' => $digest];
      $model['syncBindings'] = [$binding];
      $model['revision'] = 'sha256:' . hash('sha256', json_encode($step));
      $php = '<?php return ' . var_export($model, true) . ';';
      spacefast_content_model_stage_release($model['revision'], $php, 'sha256:' . hash('sha256', $php), true);
      spacefast_content_handle_request(['operation' => 'model.activate', 'revision' => $model['revision']], true);
      $GLOBALS['firstPublishedRevision'] ??= $model['revision'];
      $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'activated']];
      continue;
    }
    if ($step['op'] === 'inspectPage') {
      $binding = spacefast_content_model_sync_binding(${JSON.stringify(TSX_BINDING)});
      $found = spacefast_content_sync_find_post(${JSON.stringify(TSX_BINDING)}, $binding, false);
      $id = is_object($found) ? (int) $found->ID : 0;
      $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'inspected',
        'postId' => $id, 'postStatus' => $found->post_status ?? null, 'blocks' => $found->post_content ?? null,
        'externalId' => get_post_meta($id, SPACEFAST_CONTENT_EXTERNAL_ID_META, true),
        'spaceId' => get_post_meta($id, SPACEFAST_CONTENT_SPACE_META, true),
        'permalink' => spacefast_content_model_page_link('https://space.test/' . ($found->post_name ?? ''), $id),
        'ledger' => spacefast_content_sync_ledger($id),
        'activeRevision' => _stattic_private_tree_read_pointer(${JSON.stringify(storage + "/spaces/" + SPACE_ID + "/content-model/active-release")}, 128),
      ]];
      continue;
    }
    if ($step['op'] === 'renderPage') {
      $route = ['id' => 'page.' . substr(${JSON.stringify(TSX_BINDING)}, 11), 'path' => '/docs/about', 'render' => 'document', 'bindingId' => ${JSON.stringify(TSX_BINDING)}, 'params' => []];
      $snapshot = isset($step['snapshot']) ? [...$step['snapshot'], 'bindingId' => $route['bindingId'], 'modelRevision' => $GLOBALS['firstPublishedRevision'], 'sha256' => 'sha256:' . hash('sha256', $step['snapshot']['text'])] : null;
      $context = ['space_id' => ${JSON.stringify(SPACE_ID)}, 'private_root' => ${JSON.stringify(storage)}, 'serving' => ['immutable' => $snapshot !== null], 'version_id' => 'ver_sealed', 'version_dir' => '/sealed', 'root' => []];
      $renderScript = '<?php ' . base64_decode('${Buffer.from(WP_STUBS).toString("base64")}');
      foreach (['SPACEFAST_CONTENT_SPACE_ID', 'SPACEFAST_CONTENT_PRIVATE_ROOT', 'SPACEFAST_CONTENT_MODEL_RELEASE_ROOT', 'SPACEFAST_CONTENT_MODEL_REVISION', 'SPACEFAST_CONTENT_BLOCKS_ENGINE_PLUGIN'] as $key) {
        $renderScript .= '$GLOBALS[' . var_export($key, true) . '] = ' . var_export($GLOBALS[$key], true) . ';';
      }
      if ($snapshot !== null) {
        $renderScript .= '$GLOBALS["SPACEFAST_CONTENT_MODEL_REVISION"] = ' . var_export($snapshot['modelRevision'], true) . ';';
        $renderScript .= '$GLOBALS["SPACEFAST_CONTENT_MODEL_RELEASE_ROOT"] = ' . var_export(${JSON.stringify(storage + "/spaces/" + SPACE_ID + "/content-model/releases/")} . substr($snapshot['modelRevision'], 7), true) . ';';
      }
      $renderScript .= '$posts = ' . var_export($posts, true) . '; $meta = ' . var_export($meta, true) . ';';
      $renderScript .= 'require_once ' . var_export('phar://' . ${JSON.stringify(toolkitPhar)} . '/vendor/autoload.php', true) . ';';
      $renderScript .= 'require_once ' . var_export(${JSON.stringify(kernel)}, true) . ';';
      $renderScript .= 'require_once ' . var_export(${JSON.stringify(path.join(repoRoot, "runtime/engine/runtime/content-page.php"))}, true) . ';';
      $renderScript .= 'define("STATTIC_RUNTIME_THEME_STYLESHEET_URL", "/theme.css"); define("STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB", "b"); define("STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH", "l");';
      $renderScript .= '$snapshotBytes = ' . var_export(json_encode($snapshot), true) . ';';
      $renderScript .= 'require_once ' . var_export(${JSON.stringify(path.join(repoRoot, "runtime/engine/shared/storage.php"))}, true) . ';';
      $renderScript .= '$context = ' . var_export($context, true) . '; $route = ' . var_export($route, true) . ';';
      $renderScript .= '$versionRoot = _stattic_version_root($context["private_root"], $context["space_id"], $context["version_id"]); if (!is_dir($versionRoot)) mkdir($versionRoot, 0775, true);';
      $renderScript .= 'file_put_contents($versionRoot . "/metadata.json", json_encode(["catalog" => ["format" => STATTIC_RUNTIME_VERSION_CATALOG_FORMAT, "spaceId" => $context["space_id"], "versionId" => $context["version_id"], "paths" => ["_spacefast/pages/documents/" . $route["id"] . ".json" => ["source" => ["sha256" => hash("sha256", $snapshotBytes), "size" => strlen($snapshotBytes), "contentType" => "application/json"]]], "variants" => []]]));';
      $renderScript .= 'function _stattic_v4_entry($dir, $root, $key) { return null; }';
      $renderScript .= 'function _stattic_v4_blob_contents($context, $sha) { return $GLOBALS["snapshotBytes"]; }';
      $renderScript .= '_stattic_wordpress_page_try_serve(' . var_export($context, true) . ', "/docs/about", "GET", ' . var_export($route, true) . ');';
      $renderPath = tempnam(sys_get_temp_dir(), 'sf-page-render-');
      file_put_contents($renderPath, $renderScript);
      file_put_contents(dirname(dirname(${JSON.stringify(storage)})) . '/wp-load.php', '<?php');
      $html = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($renderPath));
      unlink($renderPath);
      $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'rendered', 'html' => $html]];
      continue;
    }
    if ($step['op'] === 'lookupPage') {
      $found = spacefast_content_sync_find_post(${JSON.stringify(TSX_BINDING)}, spacefast_content_model_sync_binding(${JSON.stringify(TSX_BINDING)}), $step['adopt'] ?? false);
      $results[] = ['ok' => true, 'receipt' => ['format' => 'test.driver', 'status' => 'lookup', 'postId' => $found->ID ?? null]];
      continue;
    }
    if ($step['op'] === 'materialize') {
      $op++;
      // Verbatim the body \`materializeRuntimeContentSource\` sends: the
      // operation, the post, the operation id, and \`bindingId\` only on the
      // takeover. The control plane names the post on both calls, so the
      // takeover arm has to keep resolving through the binding with a
      // \`postId\` sitting right beside it.
      $request = [
        'operation' => 'source.materialize',
        'postId' => $GLOBALS['createdPostId'] ?? 0,
        'operationId' => 'op_' . str_pad((string) $op, 6, '0', STR_PAD_LEFT),
      ];
      if ($step['target'] === 'binding') {
        $request['bindingId'] = ${JSON.stringify(TSX_BINDING)};
      }
      $results[] = [
        'ok' => true,
        'receipt' => spacefast_content_handle_request($request, $step['managed'] ?? true),
      ];
      continue;
    }
    if ($step['op'] === 'acknowledge') {
      // An acknowledgement closes the operation that prepared the write, so it
      // carries that operation's id rather than starting a new one.
      $request = [
        'operation' => 'source.acknowledge',
        'format' => 'spacefast.content-sync-ack',
        'version' => 1,
        'bindingId' => ${JSON.stringify(BINDING)},
        'operationId' => 'op_' . str_pad((string) ($step['ackOp'] ?? $op), 6, '0', STR_PAD_LEFT),
        'baseRevision' => $step['baseRevision'],
      ];
    } else {
      $op++;
      $request = [
        'operation' => 'source.reconcile',
        'state' => $step['state'],
        'bindingId' => ${JSON.stringify(BINDING)},
        'source' => ${JSON.stringify(SOURCE[format])},
        'text' => $step['text'],
        'observedSourceRevision' => 'git-' . $op,
        'operationId' => 'op_' . str_pad((string) $op, 6, '0', STR_PAD_LEFT),
      ];
      if (isset($step['baseRevision'])) { $request['baseRevision'] = $step['baseRevision']; }
    }
    $receipt = spacefast_content_handle_request($request, true);
    if (isset($receipt['ledger']['revision'])) {
      $lastLedgerRevision = $receipt['ledger']['revision'];
    }
    $results[] = ['ok' => true, 'receipt' => $receipt];
  } catch (Spacefast_Content_Conflict $conflict) {
    $results[] = ['ok' => false, 'error' => ['code' => $conflict->codeName, 'details' => $conflict->details()]];
  } catch (Spacefast_Content_Error $error) {
    $results[] = ['ok' => false, 'error' => ['code' => $error->codeName, 'message' => $error->getMessage()]];
  }
}
echo json_encode($results, JSON_UNESCAPED_SLASHES);
`;
  const scriptPath = path.join(
    mkdtempSync(path.join(os.tmpdir(), "spacefast-sync-script-")),
    "s.php",
  );
  writeFileSync(scriptPath, script);
  const run = Bun.spawnSync([process.env.PHP_BINARY ?? "php", scriptPath]);
  const stdout = run.stdout.toString();
  if (!run.success || stdout.trim() === "") {
    throw new Error(`sync kernel failed: ${run.stderr.toString()}\n${stdout}`);
  }
  // SAFETY: the scenario script emits exactly this shape, one entry per step,
  // and every field the assertions read is checked below before it is used.
  return JSON.parse(stdout) as StepResult[];
}

export function receipt(result: StepResult): SyncReceipt {
  if (!result.ok) throw new Error(`expected a receipt, got ${JSON.stringify(result.error)}`);
  if (
    result.receipt.format === "spacefast.content-materialize" ||
    result.receipt.format === "test.driver"
  ) {
    throw new Error(`expected a sync receipt, got ${JSON.stringify(result.receipt)}`);
  }
  return result.receipt;
}

export function materialized(result: StepResult): MaterializeReceipt {
  if (!result.ok) throw new Error(`expected a receipt, got ${JSON.stringify(result.error)}`);
  if (result.receipt.format !== "spacefast.content-materialize") {
    throw new Error(`expected a materialize receipt, got ${JSON.stringify(result.receipt)}`);
  }
  return result.receipt;
}

export function problem(result: StepResult) {
  if (result.ok) throw new Error(`expected a failure, got ${JSON.stringify(result.receipt)}`);
  return result.error;
}
