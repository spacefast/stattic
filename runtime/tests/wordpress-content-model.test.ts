import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { generateContentModelPhp } from "../../packages/zero-compile/src/content-model-php.ts";
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
// The theme every managed Space renders through, as it ships in the engine.
const managedThemeDirectory = path.join(repoRoot, "runtime/wordpress/managed-theme");

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
$assignedTerms = [];
// The wp_template rows sitting on this box, across every Space co-hosted on it.
$templatePosts = [];

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
function remove_action(string $hook, mixed $callback, int $priority = 10): void {
  $GLOBALS['hooks'][$hook][$priority] = array_values(array_filter(
    $GLOBALS['hooks'][$hook][$priority] ?? [],
    static fn (mixed $registered): bool => $registered !== $callback
  ));
}
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
  $callbacks = $GLOBALS['hooks'][$hook] ?? [];
  ksort($callbacks);
  foreach ($callbacks as $priorityGroup) {
    foreach ($priorityGroup as $callback) $value = $callback($value, ...$arguments);
  }
  return $value;
}
function __return_false(): bool { return false; }
// WordPress's option store, seeded the way a managed site actually comes up
// (measured on a real box): the provider's dated permalink structure, and a
// default theme that is named but not installed.
$options = [
  'permalink_structure' => '/%year%/%monthnum%/%day%/%postname%/',
  'stylesheet' => 'twentytwentyfive',
  'template' => 'twentytwentyfive',
];
function switch_theme(string $stylesheet): void {
  $GLOBALS['options']['stylesheet'] = $stylesheet;
  $GLOBALS['options']['template'] = $stylesheet;
}
// Existence is a real filesystem answer: the fixture points $themeDir at the
// managed theme the engine actually ships, and nothing else is on this box.
final class ContentModelTestTheme {
  public function __construct(private string $slug) {}
  public function exists(): bool {
    return $this->slug === 'spacefast-managed' && is_dir((string) ($GLOBALS['themeDir'] ?? ''));
  }
}
function wp_get_theme(string $stylesheet = ''): object {
  return new ContentModelTestTheme($stylesheet === '' ? (string) get_option('stylesheet') : $stylesheet);
}
function get_option(string $name, mixed $default = false): mixed {
  return $GLOBALS['options'][$name] ?? $default;
}
function get_stylesheet(): string { return (string) get_option('stylesheet'); }
function update_option(string $name, mixed $value, mixed $autoload = null): bool {
  $GLOBALS['options'][$name] = $value;
  return true;
}
function doing_action(string $hook): bool { return $GLOBALS['currentAction'] === $hook; }
function do_action(string $hook, mixed ...$arguments): void {
  $callbacks = $GLOBALS['hooks'][$hook] ?? [];
  ksort($callbacks);
  $GLOBALS['currentAction'] = $hook;
  try {
    foreach ($callbacks as $priorityGroup) {
      foreach ($priorityGroup as $callback) $callback(...$arguments);
    }
  } finally {
    $GLOBALS['currentAction'] = null;
  }
}
// The managed theme as it sits on a box, so a template's default markup is the
// theme's real bytes rather than a transcription of them.
function get_theme_file_path(string $file = ''): string {
  return ($GLOBALS['themeDir'] ?? '') . '/' . $file;
}
/** Enough of WP_Query for the pre_get_posts scoping filter to act on. */
final class ContentModelTestQuery {
  public array $vars = [];
  public function get(string $key): mixed { return $this->vars[$key] ?? ''; }
  public function set(string $key, mixed $value): void { $this->vars[$key] = $value; }
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
function wp_set_object_terms(int $postId, mixed $terms, string $taxonomy): void {
  $GLOBALS['assignedTerms'][$postId][$taxonomy] = (array) $terms;
}
function get_posts(array $args): array {
  $postType = $args['post_type'] ?? null;
  if ($postType !== 'wp_template') return [];
  // The database, not the fence: a query that carries no Space clause gets every
  // co-hosted Space's row, so dropping the clause shows up as a leak rather than
  // as a template that quietly went missing.
  $names = is_array($args['post_name__in'] ?? null) ? $args['post_name__in'] : [];
  $clause = is_array($args['meta_query'] ?? null) ? ($args['meta_query'][0] ?? null) : null;
  $scope = is_array($clause) && ($clause['key'] ?? null) === '_spacefast_space_id'
    ? (string) ($clause['value'] ?? '')
    : null;
  $found = [];
  foreach ($GLOBALS['templatePosts'] as $post) {
    if (!in_array($post->post_name, $names, true)) continue;
    if ($scope !== null
      && ($GLOBALS['savedMeta'][$post->ID]['_spacefast_space_id'] ?? null) !== $scope) continue;
    $found[] = $post;
  }
  return $found;
}
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
    // 24 is the one that sits in a collection; the rest are plain posts.
    21, 22, 24 => (object) ['ID' => $id, 'post_type' => 'post'],
    23 => (object) ['ID' => 23, 'post_type' => 'attachment'],
    default => null,
  };
}
// Core's own semantics, which the by-id read verdict leans on: an empty $term
// asks whether the object carries ANY term in the taxonomy, and an array asks
// whether it carries any of them.
function has_term(mixed $term, string $taxonomy, mixed $post = null): bool {
  if ($taxonomy !== 'zero_collection') return false;
  $postId = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
  $carried = $GLOBALS['objectTerms'][$postId] ?? [];
  if ($term === '' || $term === []) return $carried !== [];
  foreach ((array) $term as $one) { if (in_array($one, $carried, true)) return true; }
  return false;
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
class WP_Error {
  public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {}
}

`;

test("a generated PHP ContentModelRelease activates as native WordPress content, Tables, and Abilities", async () => {
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
$GLOBALS['themeDir'] = $argv[6];
// WordPress registers its canonical redirect in default-filters.php, which runs
// long before mu-plugins. The kernel therefore loads with it already in place,
// which is the only state in which removing it means anything.
add_action('template_redirect', 'redirect_canonical');
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
  'sourceKey' => 'client/components/projects.tsx',
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

// The site editor's own post types are scoped by the same two hooks every other
// content type uses: a stamp on save, a meta clause on read. Without both, one
// co-hosted Space would see and overwrite another's templates and global styles.
// Two saved rows under one theme on one box: 301 is this Space's edit of the
// single template, 303 is a co-hosted Space's edit of the page template.
$templatePosts = [
  (object) ['ID' => 301, 'post_name' => 'single', 'post_content' => 'alpha-saved-single'],
  (object) ['ID' => 303, 'post_name' => 'page', 'post_content' => 'beta-saved-page'],
];
$savedMeta[303]['_spacefast_space_id'] = 'spc_beta';
do_action('save_post', 301, (object) ['ID' => 301, 'post_type' => 'wp_template']);
do_action('save_post', 302, (object) ['ID' => 302, 'post_type' => 'wp_global_styles']);
$templateQuery = new ContentModelTestQuery();
spacefast_content_scope_post_query($templateQuery);
// What core asks for when it resolves a document's template: the hierarchy's
// slugs, through the kernel's own registered filter.
$filteredTemplates = array_map(
  static fn (object $template): array => [
    'slug' => $template->slug,
    'content' => $template->content,
  ],
  apply_filters('get_block_templates', [], ['slug__in' => ['single', 'page']], 'wp_template')
);

$templates = array_map(
  static fn (object $template): array => [
    'slug' => $template->slug,
    'type' => $template->type,
    'content' => $template->content,
  ],
  spacefast_content_templates_for_release()
);
// A Space with no active release has no templates to offer, and asking must not
// cost it the screen.
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = null;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = null;
$templatesWithoutRelease = spacefast_content_templates_for_release();
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $argv[3];

echo json_encode([
  'templates' => $templates,
  'templates_without_release' => $templatesWithoutRelease,
  'template_scope' => [
    $savedMeta[301]['_spacefast_space_id'] ?? null,
    $savedMeta[302]['_spacefast_space_id'] ?? null,
  ],
  'template_query' => $templateQuery->get('meta_query'),
  'template_theme_term' => $assignedTerms[301]['wp_theme'] ?? null,
  'filtered_templates' => $filteredTemplates,
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
  'permalink_structure' => get_option('permalink_structure'),
  'stylesheet' => get_option('stylesheet'),
  'canonical_redirect_still_hooked' => in_array(
    'redirect_canonical',
    array_merge(...array_values($hooks['template_redirect'] ?? [[]])),
    true
  ),
  'canonical_filtered' => apply_filters('redirect_canonical', 'https://space.test/2026/09/01/hello/'),
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
        managedThemeDirectory,
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
    expect(output.activation).toMatchObject({ revision: contentModel.revision, tables: 1 });

    // One template per resource a reader can land on, each opening in the site
    // editor with exactly the markup the theme already renders — so a Space that
    // never edits one looks identical to how it looked before it had any.
    // Media is deliberately absent: an attachment is not a page.
    const themeMarkup = readFileSync(
      path.join(managedThemeDirectory, "templates/index.html"),
      "utf8",
    );
    expect(output.templates).toEqual([
      { slug: "single", type: "wp_template", content: themeMarkup },
      { slug: "page", type: "wp_template", content: themeMarkup },
      { slug: "single-projects", type: "wp_template", content: themeMarkup },
    ]);
    expect(output.templates_without_release).toEqual([]);

    // Templates and global styles are private to the Space that saved them. A
    // wp_template row is scoped by the wp_theme taxonomy, not by Space, so
    // without this stamp every co-hosted Space would edit one shared set.
    expect(output.template_scope).toEqual(["spc_alpha", "spc_alpha"]);
    expect(output.template_query).toEqual([
      { key: "_spacefast_space_id", value: "spc_alpha", compare: "=" },
    ]);
    // And it carries the theme association core scopes template rows by.
    // `get_block_templates()` — the query WordPress's own front controller
    // resolves a document's template through — is fenced by a wp_theme tax_query,
    // so a row saved without this term is one core cannot see at all, and every
    // Space's site-editor edit lost to the release default on the lane that
    // actually renders.
    expect(output.template_theme_term).toEqual(["spacefast-managed"]);

    // What that resolution then gets: this Space's saved markup for the slug it
    // saved, and the release default for the slug it did not. `page` IS saved on
    // this box — by a co-hosted Space — and answering with it would be a leak,
    // not a wrong screen.
    expect(output.filtered_templates).toEqual([
      { slug: "single", content: "alpha-saved-single" },
      { slug: "page", content: themeMarkup },
    ]);
    expect(output.reactivation).toEqual(output.activation);
    expect(output.pointer).toBe(contentModel.revision);

    // A Space publishes flat slugs, so activation makes WordPress's own
    // permalinks say the same thing. Left on the provider's dated default,
    // WordPress computed a different canonical URL for the same post and
    // redirected every published slug away from the URL a reader was given.
    expect(output.permalink_structure).toBe("/%postname%/");
    // And the theme the engine ships is the one the site renders through. A
    // managed site points at a provider default that is not installed, so every
    // template resolved to nothing and WordPress served an empty document.
    expect(output.stylesheet).toBe("spacefast-managed");
    // And the redirect itself is gone, both ways it can be reached: the serving
    // lane resolves a path and answers it, so nothing downstream gets to decide
    // that path meant somewhere else.
    expect(output.canonical_redirect_still_hooked).toBe(false);
    expect(output.canonical_filtered).toBe(false);

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
      // The release declares a sync binding, so `init` installs the journal the
      // binding writes through. It is listed here because the fixture now holds
      // WordPress's option store: the install gates on get_option/update_option,
      // which a real site always has and this stub previously did not.
      "spacefast_content_source_journal",
    ]);
    expect(output.ledger).toEqual({ [`${alphaTablePrefix}migrations`]: [contentModel.revision] });

    expect(output.rendered_block).toContain('data-zero-component="project-grid"');
    expect(output.rendered_block).toContain('data-zero-source="client/components/projects.tsx"');

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
    expect(output.sync_binding).toMatchObject({
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

// publicRead decides what an anonymous reader may see. 24 sits in the declared
// projects collection, so publicRead is false; 21 is a plain post, so it is
// true. Both lanes are asked, because a filtered list beside an open by-id
// route is the worse half of a half-fix.
$GLOBALS['objectTerms'] = [24 => [spacefast_content_model_collection_term_slug('spc_alpha', 'projects')]];
$readQuery = static function (): array {
  $query = new ContentModelTestQuery();
  spacefast_content_scope_post_query($query);
  return ['meta' => $query->get('meta_query'), 'tax' => $query->get('tax_query')];
};
$readCaps = static fn (int $userId): array => [
  'private' => spacefast_content_scope_meta_cap(['read'], 'read_post', $userId, [24]),
  'public' => spacefast_content_scope_meta_cap(['read'], 'read_post', $userId, [21]),
];
$anonymousQuery = $readQuery();
$anonymousCaps = $readCaps(0);
$restGuard = static fn (int $postId): mixed =>
  spacefast_content_rest_guard_single_read(['id' => 'response'], (object) ['ID' => $postId], null);
$restGuardVerdict = static fn (mixed $result): mixed =>
  $result instanceof WP_Error ? ['status' => $result->data['status'] ?? null] : $result;
$anonymousRestGuard = [
  'private' => $restGuardVerdict($restGuard(24)),
  'public' => $restGuardVerdict($restGuard(21)),
];
$GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = 5;
$editorQuery = $readQuery();
$editorCaps = $readCaps(5);
$editorRestGuard = $restGuardVerdict($restGuard(24));
$GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = null;
// A Space with no release has no declared collection to hide, and asking must
// not cost it the clause that scopes it to its own content.
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = null;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = null;
$releaselessQuery = $readQuery();
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $argv[3];

echo json_encode([
  'staged' => $staged,
  'activation' => $activation,
  'anonymous_query' => $anonymousQuery,
  'editor_query' => $editorQuery,
  'releaseless_query' => $releaselessQuery,
  'anonymous_caps' => $anonymousCaps,
  'editor_caps' => $editorCaps,
  'anonymous_rest_guard' => $anonymousRestGuard,
  'editor_rest_guard' => $editorRestGuard,
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

    // `publicRead` is the gate it was always described as. A declared collection
    // defaults to private, and its items live on `post` behind a term — so an
    // anonymous reader's query excludes that term while keeping the Space clause,
    // and the by-id read is refused the same way rather than staying open.
    const spaceClause = [{ key: "_spacefast_space_id", value: "spc_alpha", compare: "=" }];
    const projectsTerm = `sf-${spaceDigest("spc_alpha", 16)}-projects`;
    expect(output.anonymous_query).toEqual({
      meta: spaceClause,
      tax: [
        {
          taxonomy: "zero_collection",
          field: "slug",
          terms: [projectsTerm],
          operator: "NOT IN",
        },
      ],
    });
    expect(output.anonymous_caps).toEqual({ private: ["do_not_allow"], public: ["read"] });
    // The by-id REST read runs through rest_prepare_{post_type}, which WordPress
    // evaluates even for a published post — unlike the read_post cap. The private
    // collection item is refused with a 404; the plain post's response is kept.
    expect(output.anonymous_rest_guard).toEqual({
      private: { status: 404 },
      public: { id: "response" },
    });
    // An editor session keeps seeing everything in its own Space, on both lanes.
    expect(output.editor_query).toEqual({ meta: spaceClause, tax: "" });
    expect(output.editor_caps).toEqual({ private: ["read"], public: ["read"] });
    expect(output.editor_rest_guard).toEqual({ id: "response" });
    expect(output.releaseless_query).toEqual({ meta: spaceClause, tax: "" });

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

/**
 * The read half of a collection, which the generated client already asks for.
 *
 * `content.<collection>.list()` sends `zero_collection=<term slug>`, because a
 * slug is the only name a build can know — core's own taxonomy filters take
 * term ids, which no capsule can predict. Until the kernel honors it, every
 * collection read answered with every post in the Space, which is a wrong
 * answer rather than a missing feature.
 *
 * The privacy exclusion is the reason this is one test and not two: naming a
 * private collection's slug must return nothing, so the filter has to compose
 * with `publicRead` rather than stand in for it.
 */
test("a collection read filters by its term and never past publicRead", async () => {
  const source = `import { capsule } from "@spacefast/zero/server";

export default capsule({
  collections: {
    notes: { publicRead: true, fields: { deck: { kind: "text" } } },
    projects: { fields: { deck: { kind: "text" } } },
  },
});
`;
  const declarations = parseContentDeclarations(source, source);
  if (declarations === null) throw new Error("expected content declarations");
  const { artifacts } = await compileZeroContentModel({
    declarations,
    readDirectory: async () => [],
  });
  const contentModel = artifacts.model;

  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-capsule-collection-read-"));
  const storage = path.join(root, ".stattic/storage");
  mkdirSync(storage, { recursive: true });
  const generated = await generateContentModelPhp(contentModel);

  const script = `${WORDPRESS_STUB}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $argv[2];
// The lane this parameter travels on: WordPress's own REST front controller,
// which the WP API door hands an admitted request to.
define('REST_REQUEST', true);
require $argv[1];

spacefast_content_model_stage_release($argv[3], $argv[4], $argv[5], true);
spacefast_content_model_activate_release($argv[3], true);
$releaseRoot = $argv[2] . '/spaces/spc_alpha/content-model/releases/' . substr($argv[3], 7);
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $argv[3];
do_action('init');

$read = static function (?string $slug): mixed {
  if ($slug === null) { unset($_GET['zero_collection']); } else { $_GET['zero_collection'] = $slug; }
  $query = new ContentModelTestQuery();
  spacefast_content_scope_post_query($query);
  return $query->get('tax_query');
};
$notes = spacefast_content_model_collection_term_slug('spc_alpha', 'notes');
$projects = spacefast_content_model_collection_term_slug('spc_alpha', 'projects');

$anonymousNotes = $read($notes);
$anonymousProjects = $read($projects);
$anonymousNone = $read(null);
$GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = 5;
$editorProjects = $read($projects);
$editorJunk = $read('not a slug');

echo json_encode([
  'terms' => ['notes' => $notes, 'projects' => $projects],
  'anonymous_notes' => $anonymousNotes,
  'anonymous_projects' => $anonymousProjects,
  'anonymous_none' => $anonymousNone,
  'editor_projects' => $editorProjects,
  'editor_junk' => $editorJunk,
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

    const notesTerm = `sf-${spaceDigest("spc_alpha", 16)}-notes`;
    const projectsTerm = `sf-${spaceDigest("spc_alpha", 16)}-projects`;
    expect(output.terms).toEqual({ notes: notesTerm, projects: projectsTerm });

    const isIn = (terms: string[]) => ({
      taxonomy: "zero_collection",
      field: "slug",
      terms,
      operator: "IN",
    });
    const notIn = {
      taxonomy: "zero_collection",
      field: "slug",
      terms: [projectsTerm],
      operator: "NOT IN",
    };

    // A publicRead collection: the read narrows to that collection's own items,
    // and the private exclusion rides along untouched.
    expect(output.anonymous_notes).toEqual({
      relation: "AND",
      0: notIn,
      1: [isIn([notesTerm])],
    });
    // Naming a private collection's slug is not a way past publicRead: the
    // exclusion still lands, so the two clauses can be satisfied by nothing.
    expect(output.anonymous_projects).toEqual({
      relation: "AND",
      0: notIn,
      1: [isIn([projectsTerm])],
    });
    // Without the parameter, nothing changes — this is the same answer the
    // publicRead case above pins, reached through a request that named nothing.
    expect(output.anonymous_none).toEqual([notIn]);
    // An editor may read the private collection, so the read is the filter alone.
    expect(output.editor_projects).toEqual([isIn([projectsTerm])]);
    // A value that is not a term slug names no collection, so it filters nothing.
    expect(output.editor_junk).toBe("");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
