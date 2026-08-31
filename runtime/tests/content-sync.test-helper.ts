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
  return [
    "<?php",
    "declare(strict_types=1);",
    "",
    "return [",
    "    'format' => 'spacefast.wordpress-content-model.php',",
    "    'version' => 1,",
    `    'revision' => '${revision}',`,
    "    'postTypes' => [], 'scfFieldGroups' => [], 'tables' => [],",
    "    'pages' => [], 'abilities' => [], 'hooks' => [],",
    `    'syncBindings' => [[${binding}]],`,
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
function sanitize_title(string $value): string { return strtolower($value); }
function wp_save_post_revision(int $id): int { return $id + 1000; }
function wp_get_post_revisions(int $id, array $args = []): array {
  return [(object) ['ID' => $id + 1000]];
}
`;

export type Step =
  | { op: "reconcile"; state: "initial" | "bound"; text: string; baseRevision?: string }
  | { op: "editInWordPress"; blocks: string }
  // `ackOp` closes an operation other than the most recent one, which is how a
  // test reaches back to a receipt the store may since have evicted.
  | { op: "acknowledge"; baseRevision: string; ackOp?: number };

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

export type SyncReceipt = {
  status: string;
  ledger: SyncLedger;
  sourceWrite?: { text: string; expectedSourceRevision: string; textDigest: string };
};

type SyncRepresentation = { text: string; digest: string; revision: string };

export type SyncProblem = {
  code: string;
  message?: string;
  details?: { base: SyncRepresentation; source: SyncRepresentation; wordpress: SyncRepresentation };
};

export type StepResult = { ok: true; receipt: SyncReceipt } | { ok: false; error: SyncProblem };

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
    $results[] = ['ok' => true, 'receipt' => ['status' => 'edited']];
    continue;
  }
  try {
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

export function receipt(result: StepResult) {
  if (!result.ok) throw new Error(`expected a receipt, got ${JSON.stringify(result.error)}`);
  return result.receipt;
}

export function problem(result: StepResult) {
  if (result.ok) throw new Error(`expected a failure, got ${JSON.stringify(result.receipt)}`);
  return result.error;
}
