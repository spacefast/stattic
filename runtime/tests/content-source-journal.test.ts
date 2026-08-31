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
const CONTAINER_NAME_PREFIX = "stattic-content-source-journal";
const ROOT_PASSWORD = "content-source-journal-secret";
const DATABASE = "content_source_journal_test";

const toolkitPhar = await fetchToolkitPhar();
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
  const php = [
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
  writeFileSync(path.join(dir, "content-model.php"), php);
  writeFileSync(
    path.join(dir, "content-model.sha256"),
    `sha256:${new Bun.CryptoHasher("sha256").update(php).digest("hex")}`,
  );
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
function get_option(string $key) { global $options; return $options[$key] ?? false; }
function update_option(string $key, $value, $autoload = null): bool {
  global $options; $options[$key] = $value; return true;
}
function is_wp_error(mixed $value): bool { return false; }
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
final class Test_Wpdb {
  public function __construct(public mysqli $link) {}
  public function query(string $sql) {
    $result = $this->link->query($sql);
    return $result === false ? false : $this->link->affected_rows;
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

// A save that leaves the bound field byte-identical to the ledger is not a
// source change, however much else about the post moved.
$posts[$postId]['post_title'] = 'Renamed';
wp_save_post_revision($postId);
do_action('save_post', $postId, (object) $posts[$postId]);
probe('after-title-only-save', $link);

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

test(
  "an editor change to a bound field journals exactly once, and only if its save commits",
  async () => {
    const probes = await runScenario();

    // The reconciliation writes the post itself. Journalling that write would
    // hand the drain back the answer it had just produced.
    expect(rows(probes, "bound")).toEqual([]);
    // Neither is a save that moved everything about the post except the field
    // the binding names.
    expect(rows(probes, "after-title-only-save")).toEqual([]);

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
  },
  MYSQL_SETUP_TIMEOUT_MS + 60_000,
);
