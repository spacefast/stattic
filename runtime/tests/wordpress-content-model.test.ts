import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { generateContentModelPhp } from "../../apps/control-plane/src/versions/version-content-model.ts";
import {
  compileZeroContentModel,
  parseContentDeclarations,
} from "../../packages/zero-compile/src/content-model.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");
const fixturePath = path.join(
  repoRoot,
  "packages/common/src/contracts/fixtures/content-platform-v1.json",
);

function fixtureContentModel() {
  return JSON.parse(readFileSync(fixturePath, "utf8")).artifacts.model;
}

function spaceDigest(spaceId: string, length: number): string {
  return new Bun.CryptoHasher("sha256").update(spaceId).digest("hex").slice(0, length);
}

// wp_ + zero_ + the 16-character Space digest the kernel scopes physical Tables
// with. Two Spaces on one wp.cloud site both declaring `reactions` must not
// meet in the same table.
const alphaTablePrefix = `wp_zero_${spaceDigest("spc_alpha", 16)}_`;

/**
 * The WordPress a release meets on a box: the registries it writes into, the
 * hook system it fires through, and a $wpdb faithful enough to refuse a second
 * CREATE TABLE. Shared, so a new release shape is proved against the same
 * WordPress every other shape is — not a friendlier one written to suit it.
 */
const WORDPRESS_STUB = String.raw`
$registered = [
  'taxonomies' => [],
  'blocks' => [],
  'meta' => [],
  'abilities' => [],
  'ability_categories' => [],
  'scf' => [],
];
$hooks = [];
$currentAction = null;
$refused = [];
$terms = [];
$savedPosts = [];
$savedMeta = [];

// Faithful enough to catch the one thing activation has to survive: it re-runs
// on every publish, promote and rollback, and MySQL refuses to CREATE a table
// that already exists unless the statement says IF NOT EXISTS.
final class ContentModelTestWpdb {
  public string $prefix = 'wp_';
  public array $queries = [];
  public array $created = [];
  public array $ledger = [];
  public function prepare(string $query, mixed ...$values): string {
    foreach ($values as $value) {
      $quoted = "'" . str_replace("'", "''", (string) $value) . "'";
      $query = preg_replace('/%s/', $quoted, $query, 1);
    }
    return $query;
  }
  public function query(string $query): int|false {
    $this->queries[] = $query;
    // A backtick cannot travel through this script's template literal, so the
    // quoting around each identifier is matched as a wildcard.
    if (preg_match('/^CREATE TABLE (IF NOT EXISTS )?.([a-z0-9_]+)./i', $query, $match) === 1) {
      $exists = isset($this->created[$match[2]]);
      $this->created[$match[2]] = true;
      return $exists && $match[1] === '' ? false : 1;
    }
    if (preg_match("/^INSERT INTO .([a-z0-9_]+). \(revision.*VALUES \('([^']+)'/", $query, $match) === 1) {
      $this->ledger[$match[1]][] = $match[2];
    }
    return 1;
  }
  public function get_var(string $query): mixed {
    $this->queries[] = $query;
    return preg_match("/^SELECT revision FROM .([a-z0-9_]+). WHERE revision = '([^']+)'/", $query, $match) === 1
      && in_array($match[2], $this->ledger[$match[1]] ?? [], true)
        ? $match[2]
        : null;
  }
}
$wpdb = new ContentModelTestWpdb();

function register_taxonomy(string $name, array $types, array $args): void {
  global $registered; $registered['taxonomies'][$name] = [$types, $args];
}
function register_block_type(string $name, array $args): void {
  global $registered; $registered['blocks'][$name] = $args;
}
function register_post_meta(string $type, string $key, array $args): void {
  global $registered; $registered['meta'][$type . ':' . $key] = $args;
}
// Enough of WordPress's hook API to run the kernel's own wiring rather than
// hand-calling past it, because WHICH action a registration happens on is
// exactly what the Abilities API enforces.
function add_action(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): void {
  $GLOBALS['hooks'][$hook][$priority][] = $callback;
}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): void {
  add_action($hook, $callback, $priority, $arguments);
}
function remove_action(string $hook, mixed $callback, int $priority = 10): void {}
function doing_action(string $hook): bool { return $GLOBALS['currentAction'] === $hook; }
function do_action(string $hook): void {
  $callbacks = $GLOBALS['hooks'][$hook] ?? [];
  ksort($callbacks);
  $GLOBALS['currentAction'] = $hook;
  try {
    foreach ($callbacks as $priorityGroup) {
      foreach ($priorityGroup as $callback) $callback();
    }
  } finally {
    $GLOBALS['currentAction'] = null;
  }
}
// WP_Abilities_Registry's own admission rules: a name outside the pattern, or a
// registration made off the API's action, registers nothing. Refusals are
// recorded so a regression shows up as an absent Ability rather than a pass.
function wp_register_ability_category(string $name, array $args): void {
  if (!doing_action('wp_abilities_api_categories_init')
    || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
    $GLOBALS['refused'][] = $name;
    return;
  }
  $GLOBALS['registered']['ability_categories'][] = $name;
}
function wp_register_ability(string $name, array $args): void {
  if (!doing_action('wp_abilities_api_init')
    || preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name) !== 1
    || isset($GLOBALS['registered']['abilities'][$name])) {
    $GLOBALS['refused'][] = $name;
    return;
  }
  $GLOBALS['registered']['abilities'][$name] = $args;
}
function acf_add_local_field_group(array $group): void {
  global $registered; $registered['scf'][] = $group;
}
function term_exists(string $slug, string $taxonomy): array|false {
  global $terms; return $terms[$slug] ?? false;
}
function wp_insert_term(string $name, string $taxonomy, array $args): array {
  global $terms; $term = ['term_id' => count($terms) + 1]; $terms[$args['slug']] = $term; return $term;
}
function update_term_meta(int $termId, string $key, mixed $value): void {}
function get_posts(array $args): array {
  if (($args['post_type'] ?? null) !== 'page') return [];
  return [(object) [
    'ID' => 77,
    'post_status' => 'publish',
    'post_content' => 'editor-composition',
  ]];
}
// An editor's page: their own blocks around the one block the content model owns,
// plus a stray second copy the reconcile has to collapse.
function parse_blocks(string $content): array {
  return [
    ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'before']],
    ['blockName' => 'zero/component', 'attrs' => ['sourceKey' => 'old']],
    ['blockName' => 'core/quote', 'attrs' => ['content' => 'after']],
    ['blockName' => 'zero/component', 'attrs' => ['sourceKey' => 'duplicate']],
  ];
}
function serialize_blocks(array $blocks): string { return json_encode($blocks, JSON_UNESCAPED_SLASHES); }
function wp_insert_post(array $post, bool $returnError): int {
  global $savedPosts; $savedPosts[] = $post; return (int) ($post['ID'] ?? 91);
}
function update_post_meta(int $postId, string $key, mixed $value): void {
  global $savedMeta; $savedMeta[$postId][$key] = $value;
}
function get_post_meta(int $postId, string $key, bool $single): mixed {
  global $savedMeta;
  if (isset($savedMeta[$postId][$key])) return $savedMeta[$postId][$key];
  // 22 is another Space's row on the same box; everything else is spc_alpha's.
  return $key === '_spacefast_space_id' ? ($postId === 22 ? 'spc_beta' : 'spc_alpha') : '';
}
function get_post(int $id): ?object {
  return match ($id) {
    21, 22 => (object) ['ID' => $id, 'post_type' => 'post'],
    23 => (object) ['ID' => 23, 'post_type' => 'attachment'],
    default => null,
  };
}
function is_wp_error(mixed $value): bool { return false; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }

`;

test("a generated PHP ContentModelRelease activates as native WordPress content, Tables, Pages, and Abilities", async () => {
  const contentModel = fixtureContentModel();
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wordpress-content-model-"));
  const storage = path.join(root, ".stattic/storage");
  const releaseRoot = path.join(
    storage,
    "spaces/spc_alpha/content-model/releases",
    contentModel.revision.slice("sha256:".length),
  );
  mkdirSync(storage, { recursive: true });
  const generated = await generateContentModelPhp(contentModel);

  const script = `${WORDPRESS_STUB}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
require $argv[1];

$staged = spacefast_content_model_stage_release($argv[3], $argv[4], $argv[5], true);
$activation = spacefast_content_model_activate_release($argv[3], true);
// Publish, promote and rollback all activate again. Nothing may break, and the
// migration ledger must not gain a second row for the same revision.
$reactivation = spacefast_content_model_activate_release($argv[3], true);
$releaseRoot = $argv[2] . '/spaces/spc_alpha/content-model/releases/' . substr($argv[3], 7);
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $argv[3];
// The kernel's own wiring, fired in WordPress's order. The init action must
// leave the Abilities registry empty: registration belongs to the API's own
// actions.
do_action('init');
$abilitiesAfterInit = array_keys($registered['abilities']);
do_action('wp_abilities_api_categories_init');
do_action('wp_abilities_api_init');
spacefast_content_model_register_scf_field_groups();

$projects = spacefast_content_model_collection_projection('projects');
$alphaReactions = spacefast_content_model_table_name($wpdb, 'reactions');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_beta';
$betaReactions = spacefast_content_model_table_name($wpdb, 'reactions');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';

$ability = $registered['abilities']['zero/endpoint-preview'];
$denial = null;
try { $ability['execute_callback'](['limit' => 1]); }
catch (Spacefast_Content_Error $error) { $denial = $error->codeName; }
$GLOBALS['SPACEFAST_CONTENT_GRANTED_CAPABILITIES'] = ['page.view'];
$GLOBALS['SPACEFAST_CONTENT_ABILITY_AUTHORIZER'] = static fn (array $descriptor, mixed $input, string $spaceId): bool =>
  $spaceId === 'spc_alpha' && $descriptor['reads'] === ['content.projects', 'table.reactions'];
$GLOBALS['SPACEFAST_CONTENT_ABILITY_DISPATCHER'] = static fn (array $descriptor, mixed $input): array =>
  ['ability' => $descriptor['id'], 'input' => $input];
$abilityResult = $ability['execute_callback'](['limit' => 1]);
$renderedBlock = $registered['blocks']['zero/component']['render_callback']([
  'sourceKey' => 'client/pages/projects.tsx',
  'componentId' => 'project-grid',
  'props' => ['id' => 'project-list-input', 'sha256' => str_repeat('a', 64)],
]);

// SCF is the write seam now, so reference integrity is enforced there: a
// relation may only point at a row of the right resource in THIS Space.
$reference = static fn (array $definition, mixed $value): mixed =>
  spacefast_content_validate_scf_value(true, $value, ['spacefast_definition' => $definition], null);
$projectsRelation = ['type' => 'relation', 'collection' => 'projects'];
$references = [
  $reference($projectsRelation, 21),
  $reference($projectsRelation, 22),
  $reference($projectsRelation, 23),
  $reference(['type' => 'json'], '{"theme":"news"}'),
  $reference(['type' => 'json'], 'not json'),
];

$pageBlocks = json_decode($savedPosts[0]['post_content'], true);
echo json_encode([
  'staged' => $staged,
  'activation' => $activation,
  'reactivation' => $reactivation,
  'pointer' => trim((string) file_get_contents($argv[2] . '/spaces/spc_alpha/content-model/active-release')),
  'post_types' => [
    $projects['post_type'],
    spacefast_content_model_collection_projection('pages')['post_type'],
    spacefast_content_model_collection_projection('media')['post_type'],
  ],
  'taxonomy' => array_keys($registered['taxonomies']),
  'collection_term' => $projects['collection_term'],
  'tables' => [$alphaReactions, $betaReactions],
  'ledger' => $wpdb->ledger,
  'created_tables' => array_keys($wpdb->created),
  'page' => [
    'id' => $savedPosts[0]['ID'],
    'surrounding' => [$pageBlocks[0]['blockName'], $pageBlocks[2]['blockName']],
    'component_count' => count(array_filter($pageBlocks, static fn (array $block): bool => $block['blockName'] === 'zero/component')),
    'component' => $pageBlocks[1]['attrs'],
    'source_key' => $savedMeta[77]['_zero_page_source_key'],
  ],
  'rest_meta' => array_keys($registered['meta']),
  'scf_target' => $registered['scf'][0]['location'][0][0],
  'ability' => [$denial, $abilityResult],
  'ability_names' => array_keys($registered['abilities']),
  'ability_categories' => $registered['ability_categories'],
  'abilities_after_init' => $abilitiesAfterInit,
  'refused' => $refused,
  'ability_label' => $ability['label'],
  'ability_category' => $ability['category'],
  'ability_meta' => $ability['meta'],
  'write_ability_meta' => $registered['abilities']['zero/mutation-react']['meta'],
  'rendered_block' => $renderedBlock,
  'references' => $references,
  'sync_binding' => spacefast_content_model_sync_binding('sync.projects-body'),
]);
`;

  try {
    const result = Bun.spawnSync(
      [
        "php",
        "-r",
        script,
        kernel,
        storage,
        contentModel.revision,
        generated.php,
        generated.sha256,
      ],
      { cwd: repoRoot, stderr: "pipe", stdout: "pipe" },
    );
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    const output = JSON.parse(result.stdout.toString());

    expect(output.staged).toEqual({
      revision: contentModel.revision,
      artifactDigest: generated.sha256,
      staged: true,
    });
    expect(output.activation).toEqual({ revision: contentModel.revision, tables: 1, pages: 1 });
    expect(output.reactivation).toEqual(output.activation);
    expect(output.pointer).toBe(contentModel.revision);

    // Every resource lands on a native WordPress post type; only the collection
    // is separated, by term rather than by a bespoke post type.
    expect(output.post_types).toEqual(["post", "page", "attachment"]);
    expect(output.taxonomy).toEqual(["zero_collection", "zero_folder"]);
    expect(output.collection_term).toBe(`sf-${spaceDigest("spc_alpha", 16)}-projects`);
    expect(output.scf_target).toMatchObject({
      param: "post_taxonomy",
      operator: "==",
      value: `zero_collection:${output.collection_term}`,
    });
    expect(output.rest_meta).toContain("post:_zero_projects_deck");

    // Tables carry the Space, and the same revision applies exactly once.
    expect(output.tables[0]).toBe(`${alphaTablePrefix}reactions`);
    expect(output.tables[1]).toBe(`wp_zero_${spaceDigest("spc_beta", 16)}_reactions`);
    expect(output.created_tables).toEqual([
      `${alphaTablePrefix}migrations`,
      `${alphaTablePrefix}reactions`,
    ]);
    expect(output.ledger).toEqual({ [`${alphaTablePrefix}migrations`]: [contentModel.revision] });

    // The content model owns one locked block; the editor keeps everything around it,
    // and a second copy is collapsed rather than duplicated.
    expect(output.page).toMatchObject({
      id: 77,
      surrounding: ["core/paragraph", "core/quote"],
      component_count: 1,
      component: {
        sourceKey: "client/pages/projects.tsx",
        componentId: "project-grid",
        lock: { move: true, remove: true },
      },
      source_key: "client/pages/projects.tsx",
    });
    expect(output.rendered_block).toContain('data-zero-component="project-grid"');
    expect(output.rendered_block).toContain('data-zero-source="client/pages/projects.tsx"');

    // Registration lands where the Abilities API accepts it, under names its
    // registry admits, and nowhere else: nothing registers on `init`, and no
    // registration is refused.
    expect({
      afterInit: output.abilities_after_init,
      refused: output.refused,
      categories: output.ability_categories,
      names: output.ability_names,
    }).toEqual({
      afterInit: [],
      refused: [],
      // The users and storage features register on the same kernel hooks, so
      // their categories and abilities land in the same registry pass as the
      // content model's.
      categories: ["zero-wp-users", "zero-storage", "zero-content"],
      names: [
        "zero/wp-users-list",
        "zero/wp-users-get",
        "zero/wp-users-create",
        "zero/wp-users-update",
        "zero/storage-list",
        "zero/storage-get",
        "zero/storage-upload",
        "zero/storage-move",
        "zero/storage-delete",
        "zero/content-projects-list",
        "zero/query-featured-projects",
        "zero/mutation-react",
        "zero/endpoint-webhook",
        "zero/endpoint-preview",
      ],
    });
    // The wire name is the registry's key alone. The content model id stays the
    // Ability's label, and stays what the dispatcher is addressed by.
    expect(output.ability_label).toBe("endpoint.preview");
    expect(output.ability_category).toBe("zero-content");
    // `public` is what carries a compiled Ability to the REST surface and, via
    // the MCP adapter, to agents; the annotations tell an agent whether calling
    // it changes anything. Both follow the content model's own read/write mode.
    expect(output.ability_meta).toEqual({
      public: true,
      kind: "endpoint",
      mode: "read",
      reads: ["content.projects", "table.reactions"],
      writes: [],
      annotations: { readonly: true, destructive: false, idempotent: true },
    });
    expect(output.write_ability_meta.annotations).toEqual({
      readonly: false,
      destructive: true,
      idempotent: false,
    });
    expect(output.ability).toEqual([
      "content_ability_denied",
      { ability: "endpoint.preview", input: { limit: 1 } },
    ]);
    expect(output.references).toEqual([
      true,
      "The referenced content does not belong to this Space or resource.",
      "The referenced content does not belong to this Space or resource.",
      true,
      "Enter valid JSON.",
    ]);
    expect(output.sync_binding).toEqual({
      resourceId: "projects",
      fieldId: "project-body",
      source: "content/projects/launch.md",
      // The serializer the kernel will reconcile this file through, decided by
      // its extension at compile time and never by the caller.
      format: "md",
      slug: "launch",
      post_type: "post",
      field_storage: "_zero_projects_body",
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

// Staging and activation are separate so the live content model can follow the
// live version. This pins the half rollback depends on: pointing at an
// already-staged release, and clearing the pointer for a version with none.
test("content model activation follows the live version and refuses unknown releases", async () => {
  const contentModel = fixtureContentModel();
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wordpress-content-model-pointer-"));
  const storage = path.join(root, ".stattic/storage");
  const generated = await generateContentModelPhp(contentModel);
  // A second release that differs only in the revision it claims, so both can
  // be staged side by side and the pointer has somewhere to roll back to.
  const olderRevision = `sha256:${"e".repeat(64)}`;
  const olderPhp = generated.php.replace(contentModel.revision, olderRevision);
  const olderDigest = `sha256:${new Bun.CryptoHasher("sha256").update(olderPhp).digest("hex")}`;

  // Only Tables are reachable here: the term, Page and REST projections all
  // stand down when their WordPress functions are absent, which leaves the
  // pointer as the one thing under test.
  const script = String.raw`
final class PointerTestWpdb {
  public string $prefix = 'wp_';
  public function prepare(string $query, mixed ...$values): string { return $query; }
  public function query(string $query): int { return 1; }
  public function get_var(string $query): mixed { return null; }
}
$wpdb = new PointerTestWpdb();
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
require $argv[1];
$pointer = $argv[2] . '/spaces/spc_alpha/content-model/active-release';
$read = static fn (): ?string => is_file($pointer) ? trim((string) file_get_contents($pointer)) : null;
$catch = static function (callable $run): string {
  try { $run(); return 'no_error'; }
  catch (Spacefast_Content_Error $error) { return $error->codeName; }
};
spacefast_content_model_stage_release($argv[3], $argv[4], $argv[5], true);
spacefast_content_model_stage_release($argv[6], $argv[7], $argv[8], true);
spacefast_content_model_activate_release($argv[3], true);
$afterActivate = $read();
spacefast_content_model_activate_release($argv[6], true);
$afterRollback = $read();
$cleared = spacefast_content_model_activate_release(null, true);
echo json_encode([
  'after_activate' => $afterActivate,
  'after_rollback' => $afterRollback,
  'cleared' => $cleared,
  'after_clear' => $read(),
  'unknown_release' => $catch(static fn () => spacefast_content_model_activate_release('sha256:' . str_repeat('c', 64), true)),
  'malformed_revision' => $catch(static fn () => spacefast_content_model_activate_release('not-a-revision', true)),
  'unmanaged' => $catch(static fn () => spacefast_content_model_activate_release($argv[3], false)),
  'unmanaged_stage' => $catch(static fn () => spacefast_content_model_stage_release($argv[3], $argv[4], $argv[5], false)),
]);
`;
  try {
    const result = Bun.spawnSync(
      [
        "php",
        "-r",
        script,
        kernel,
        storage,
        contentModel.revision,
        generated.php,
        generated.sha256,
        olderRevision,
        olderPhp,
        olderDigest,
      ],
      { cwd: repoRoot, stderr: "pipe", stdout: "pipe" },
    );
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({
      after_activate: contentModel.revision,
      // Rolling back to the release an older version bound restores that model.
      after_rollback: olderRevision,
      cleared: { revision: null, tables: 0, pages: 0 },
      // A version that shipped no content model leaves the Space with none.
      after_clear: null,
      unknown_release: "content_model_not_found",
      malformed_revision: "content_model_revision_invalid",
      unmanaged: "content_auth_required",
      unmanaged_stage: "content_auth_required",
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("activation refuses a generated PHP ContentModelRelease whose immutable bytes were changed", async () => {
  const contentModel = fixtureContentModel();
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wordpress-content-model-tamper-"));
  const storage = path.join(root, ".stattic/storage");
  const releaseRoot = path.join(
    storage,
    "spaces/spc_alpha/content-model/releases",
    contentModel.revision.slice("sha256:".length),
  );
  mkdirSync(releaseRoot, { recursive: true });
  const generated = await generateContentModelPhp(contentModel);
  writeFileSync(
    path.join(releaseRoot, "content-model.php"),
    generated.php.replace("project-grid", "changed-grid"),
  );
  writeFileSync(path.join(releaseRoot, "content-model.sha256"), `${generated.sha256}\n`);
  const script = String.raw`
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
require $argv[1];
try { spacefast_content_model_activate_release($argv[3], true); }
catch (Spacefast_Content_Error $error) { echo $error->codeName; }
`;
  try {
    const result = Bun.spawnSync(["php", "-r", script, kernel, storage, contentModel.revision], {
      cwd: repoRoot,
      stderr: "pipe",
      stdout: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(result.stdout.toString()).toBe("content_model_digest_mismatch");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

// content.managed is an internal, default-off beta, so there is no compat shim
// for the retired Payload release shape: activation stops with one stable code
// that tells the operator the only fix.
test("activation refuses a Payload-shaped release with a republish instruction", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wordpress-content-model-legacy-"));
  const storage = path.join(root, ".stattic/storage");
  const revision = `sha256:${"d".repeat(64)}`;
  const releaseRoot = path.join(
    storage,
    "spaces/spc_alpha/content-model/releases",
    revision.slice("sha256:".length),
  );
  mkdirSync(releaseRoot, { recursive: true });
  writeFileSync(path.join(releaseRoot, "schema.json"), '{"schema_version":3}');
  writeFileSync(path.join(releaseRoot, "payloadwp.php"), "<?php");
  const script = String.raw`
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
require $argv[1];
try { spacefast_content_model_activate_release($argv[3], true); }
catch (Spacefast_Content_Error $error) {
  echo json_encode([$error->status, $error->codeName, $error->getMessage()]);
}
`;
  try {
    const result = Bun.spawnSync(["php", "-r", script, kernel, storage, revision], {
      cwd: repoRoot,
      stderr: "pipe",
      stdout: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual([
      409,
      "content_model_republish_required",
      "This content release uses the retired Payload format. Republish the Space to activate its WordPress content model.",
    ]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

/**
 * The producer end of the lane, proved against the same WordPress.
 *
 * Everything above compiles a fixture that was written by hand. This one
 * compiles a capsule the way an author writes it — `capsule({ collections })`
 * in the Zero app source — and activates what the compiler produced.
 *
 * The shape matters as much as the content: a content-only Space declares no
 * Abilities, no Lakebed tables, and no pages, so this is the only case here
 * where activation has to survive all three being empty. A kernel that assumed
 * at least one Ability, or an activation receipt that counted a table it never
 * created, would pass every other test on this file and break the first Space
 * that only wanted to publish posts.
 */
test("a capsule's own content declarations activate as native WordPress content", async () => {
  const source = `import { capsule } from "@spacefast/zero/server";

export default capsule({
  collections: {
    posts: { label: "Writing", fields: { body: { kind: "blocks", source: "content/posts/*.md" } } },
    projects: { fields: { deck: { kind: "text" }, status: { kind: "enum", values: ["draft", "shipped"] } } },
  },
});
`;
  const declarations = parseContentDeclarations(source, source);
  if (declarations === null) throw new Error("expected content declarations");
  const { artifacts } = await compileZeroContentModel({
    declarations,
    readDirectory: async () => ["launch.md"],
  });
  const contentModel = artifacts.model;

  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-capsule-content-model-"));
  const storage = path.join(root, ".stattic/storage");
  mkdirSync(storage, { recursive: true });
  const generated = await generateContentModelPhp(contentModel);

  const script = `${WORDPRESS_STUB}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
require $argv[1];

$staged = spacefast_content_model_stage_release($argv[3], $argv[4], $argv[5], true);
$activation = spacefast_content_model_activate_release($argv[3], true);
$releaseRoot = $argv[2] . '/spaces/spc_alpha/content-model/releases/' . substr($argv[3], 7);
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $argv[3];
do_action('init');
do_action('wp_abilities_api_categories_init');
do_action('wp_abilities_api_init');
spacefast_content_model_register_scf_field_groups();

echo json_encode([
  'staged' => $staged,
  'activation' => $activation,
  'post_types' => [
    spacefast_content_model_collection_projection('posts')['post_type'],
    spacefast_content_model_collection_projection('projects')['post_type'],
  ],
  'taxonomy' => array_keys($registered['taxonomies']),
  'abilities' => array_keys($registered['abilities']),
  'collection_term' => spacefast_content_model_collection_projection('projects')['collection_term'],
  'rest_meta' => array_keys($registered['meta']),
  'scf_titles' => array_map(static fn (array $group): string => $group['title'], $registered['scf']),
]);
`;
  try {
    const result = Bun.spawnSync(
      [
        "php",
        "-r",
        script,
        kernel,
        storage,
        contentModel.revision,
        generated.php,
        generated.sha256,
      ],
      { cwd: repoRoot, stderr: "pipe", stdout: "pipe" },
    );
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    const output = JSON.parse(result.stdout.toString());

    expect(output.staged).toEqual({
      revision: contentModel.revision,
      artifactDigest: generated.sha256,
      staged: true,
    });
    // Nothing declared, nothing counted: the receipt must not invent a table or
    // a page the capsule never asked for.
    expect(output.activation).toEqual({ revision: contentModel.revision, tables: 0, pages: 0 });
    // A content-only Space contributes no Abilities of its own. What remains in
    // the registry is the platform's own user and storage surface, which every Space gets
    // whether or not it declared anything — so the model added nothing and,
    // just as importantly, took nothing away.
    expect(output.abilities).toEqual([
      "zero/wp-users-list",
      "zero/wp-users-get",
      "zero/wp-users-create",
      "zero/wp-users-update",
      "zero/storage-list",
      "zero/storage-get",
      "zero/storage-upload",
      "zero/storage-move",
      "zero/storage-delete",
    ]);

    // Both land on WordPress's own `post`: the adopted native because it is
    // one, the collection because a collection is a post plus a term — never a
    // generated post type.
    expect(output.post_types).toEqual(["post", "post"]);
    expect(output.taxonomy).toEqual(["zero_collection", "zero_folder"]);
    expect(output.collection_term).toBe(`sf-${spaceDigest("spc_alpha", 16)}-projects`);
    expect(output.rest_meta).toContain("post:_zero_posts_body");
    expect(output.rest_meta).toContain("post:_zero_projects_deck");
    expect(output.scf_titles).toEqual(["Projects fields"]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
