import { expect, test } from "bun:test";
import { copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");
const contentLoader = path.join(repoRoot, "runtime/wordpress-content-loader.php");

test("the MU loader follows immutable engine and compiled content pointers", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-content-loader-"));
  const publicRoot = path.join(root, "public");
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
  const revisions = ["a".repeat(64), "b".repeat(64)];
  for (const revision of revisions) {
    const compiledRoot = path.join(publicRoot, ".stattic/storage/content/releases", revision);
    mkdirSync(compiledRoot, { recursive: true });
    writeFileSync(
      path.join(compiledRoot, "payloadwp.php"),
      `<?php $GLOBALS['loaded_content'] = ${JSON.stringify(revision)};`,
    );
  }
  mkdirSync(path.join(publicRoot, ".stattic/storage/content"), { recursive: true });

  const load = (release: string, revision: string) => {
    writeFileSync(path.join(publicRoot, ".stattic/active-release"), `releases/${release}\n`);
    writeFileSync(
      path.join(publicRoot, ".stattic/storage/content/active-release"),
      `${revision}\n`,
    );
    const result = Bun.spawnSync({
      cmd: [
        "php",
        "-d",
        "auto_prepend_file=",
        "-r",
        `require $argv[1]; echo json_encode([$GLOBALS['loaded_kernel'] ?? null, $GLOBALS['loaded_content'] ?? null]);`,
        muPlugin,
      ],
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    return JSON.parse(result.stdout.toString());
  };

  try {
    expect(load("release-a", revisions[0])).toEqual(["release-a", revisions[0]]);
    expect(load("release-b", revisions[1])).toEqual(["release-b", revisions[1]]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("the WordPress content kernel isolates Spaces while validating writes and compiling queries", async () => {
  const script = String.raw`
$savedPost = null;
$savedMeta = [];
$savedThumbnail = 0;
$insertCount = 0;
$contentManifest = null;
define('ABSPATH', '/srv/wordpress/');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$_SERVER['HTTP_HOST'] = 'alpha.spacefast.test';
class ContentTestWpdb {
  public array $queries = [];
  public function prepare(string $query, string $value): string { return $query . ':' . $value; }
  public function get_var(string $query): string {
    $this->queries[] = $query;
    return '1';
  }
}
$wpdb = new ContentTestWpdb();
function get_posts(array $args): array { return []; }
function get_option(string $name, mixed $default): mixed {
  global $contentManifest;
  return $name === spacefast_content_option_name(SPACEFAST_CONTENT_MANIFEST_OPTION) && is_array($contentManifest)
    ? $contentManifest
    : $default;
}
function get_post(int $id): ?object {
  global $savedPost;
  if ($id === 7) return (object) ['ID' => 7, 'post_type' => 'page'];
  if ($id === 9) return (object) ['ID' => 9, 'post_type' => 'attachment'];
  return $savedPost;
}
function get_post_meta(int $id, string $key, bool $single): mixed {
  global $savedMeta;
  if ($id === 9 && $key === SPACEFAST_CONTENT_SPACE_META) return 'spc_alpha';
  return $savedMeta[$key] ?? '';
}
function wp_insert_post(array $post, bool $returnError): int {
  global $insertCount, $savedPost;
  $insertCount += 1;
  $savedPost = (object) array_merge([
    'ID' => 41,
    'post_content' => '',
    'post_excerpt' => '',
    'post_name' => '',
    'post_status' => 'draft',
    'post_title' => '',
  ], $post, ['ID' => 41]);
  return 41;
}
function is_wp_error(mixed $value): bool { return false; }
function update_post_meta(int $id, string $key, mixed $value): void {
  global $savedMeta;
  $savedMeta[$key] = $value;
}
function sanitize_title(string $value): string { return strtolower(str_replace(' ', '-', $value)); }
function sanitize_textarea_field(string $value): string { return 'text:' . $value; }
function wp_kses_post(string $value): string { return 'html:' . $value; }
function set_post_thumbnail(int $id, int $mediaId): void {
  global $savedThumbnail;
  $savedThumbnail = $mediaId;
}
function get_post_thumbnail_id(object $post): int {
  global $savedThumbnail;
  return $savedThumbnail;
}
require $argv[1];
$manifest = spacefast_content_validate_manifest([
  'format' => SPACEFAST_CONTENT_SCHEMA_FORMAT,
  'version' => SPACEFAST_CONTENT_API_VERSION,
  'collections' => [
    'events' => [
      'resourceId' => 'events_01k4t7x8',
      'name' => 'events',
      'label' => 'Events',
      'singularLabel' => 'Event',
      'fields' => [
        'starts_at' => ['type' => 'date', 'label' => 'Starts at', 'required' => true],
        'capacity' => ['type' => 'number', 'label' => 'Capacity'],
      ],
    ],
  ],
]);
$contentManifest = $manifest;
$collection = $manifest['collections']['events'] + [
  'post_type' => spacefast_content_post_type($manifest['collections']['events']['resourceId']),
  'builtin' => false,
];
$args = spacefast_content_compile_query_args([
  'where' => [['field' => 'capacity', 'operator' => 'gte', 'value' => 50]],
  'orderBy' => [['field' => 'starts_at', 'direction' => 'asc']],
  'limit' => 10,
], $collection, false);
$post = spacefast_content_upsert_document([
  'externalId' => 'source:home',
  'collection' => 'posts',
  'slug' => 'Hello World',
  'title' => 'Hello',
  'status' => 'publish',
  'fields' => ['content' => '<p>Hello</p>', 'excerpt' => 'Short', 'featured_media' => 9],
], true);
$wrongTypeCode = '';
try {
  spacefast_content_upsert_document([
    'id' => 7,
    'collection' => 'posts',
    'slug' => 'wrong',
    'title' => 'Wrong',
    'fields' => [],
  ], true);
} catch (Spacefast_Content_Error $error) {
  $wrongTypeCode = $error->codeName;
}
$fieldCode = '';
try {
  spacefast_content_upsert_document([
    'externalId' => 'event:missing-date',
    'collection' => 'events',
    'slug' => 'missing-date',
    'title' => 'Missing date',
    'fields' => ['capacity' => 12],
  ], true);
} catch (Spacefast_Content_Error $error) {
  $fieldCode = $error->codeName;
}
$bothIdentityCode = '';
try {
  spacefast_content_upsert_document([
    'id' => 41,
    'externalId' => 'source:home',
    'collection' => 'posts',
    'slug' => 'ambiguous',
    'title' => 'Ambiguous',
    'fields' => [],
  ], true);
} catch (Spacefast_Content_Error $error) {
  $bothIdentityCode = $error->codeName;
}
$alphaPostType = spacefast_content_post_type('events_01k4t7x8');
$alphaOption = spacefast_content_option_name(SPACEFAST_CONTENT_MANIFEST_OPTION);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_beta';
$isolation = [
  $alphaPostType !== spacefast_content_post_type('events_01k4t7x8'),
  $alphaOption !== spacefast_content_option_name(SPACEFAST_CONTENT_MANIFEST_OPTION),
  !spacefast_content_post_belongs_to_space(9),
  spacefast_content_scope_meta_cap(['edit_posts'], 'edit_post', 1, [9]) === ['do_not_allow'],
];
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$scopedOrQuery = spacefast_content_scope_meta_query([
  'relation' => 'OR',
  ['key' => 'featured', 'value' => '1'],
  ['key' => 'promoted', 'value' => '1'],
]);
$mediaArgs = spacefast_content_compile_query_args([
  'where' => [['field' => 'id', 'operator' => 'in', 'value' => [9, '12']]],
], spacefast_content_resolve_collection('media'), false);
$uploads = spacefast_content_scope_upload_dir([
  'basedir' => '/srv/uploads',
  'baseurl' => 'https://space.example/wp-content/uploads',
  'subdir' => '/2026/08',
]);
$capabilities = [
  spacefast_content_scope_meta_cap(['edit_posts'], 'edit_post', 1, [9]),
  spacefast_content_scope_meta_cap(['edit_posts'], 'edit_post', 1, [7]),
];
echo json_encode([
  'public' => $manifest['collections']['events']['public'],
  'post_type' => $args['post_type'],
  'space_filter' => $args['meta_query'][0],
  'limit' => $args['posts_per_page'],
  'filter' => $args['meta_query'][1],
  'sort' => [$args['meta_key'], $args['orderby'], $args['order']],
  'cursor' => spacefast_content_decode_cursor(spacefast_content_encode_cursor(3)),
  'post' => $post,
  'external_id' => $savedMeta[SPACEFAST_CONTENT_EXTERNAL_ID_META] ?? null,
  'locks' => array_map(static fn (string $query): string => str_contains($query, 'RELEASE') ? 'release' : 'acquire', $wpdb->queries),
  'wrong_type_code' => $wrongTypeCode,
  'field_code' => $fieldCode,
  'both_identity_code' => $bothIdentityCode,
  'insert_count' => $insertCount,
  'isolation' => $isolation,
  'scoped_or_query' => $scopedOrQuery,
  'capabilities' => $capabilities,
  'media_query' => [$mediaArgs['post_type'], $mediaArgs['post_status'], $mediaArgs['post__in']],
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

  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  expect(JSON.parse(stdout)).toEqual({
    cursor: 3,
    filter: { compare: ">=", key: "capacity", type: "NUMERIC", value: 50 },
    limit: 10,
    post_type: `sf_${new Bun.CryptoHasher("sha256").update("spc_alpha|events_01k4t7x8").digest("hex").slice(0, 16)}`,
    space_filter: {
      compare: "=",
      key: "_spacefast_space_id",
      value: "spc_alpha",
    },
    public: true,
    sort: ["starts_at", "meta_value", "ASC"],
    post: {
      createdAt: "",
      fields: {
        content: "html:<p>Hello</p>",
        excerpt: "text:Short",
        featured_media: 9,
      },
      id: 41,
      slug: "hello-world",
      status: "publish",
      title: "Hello",
      updatedAt: "",
    },
    external_id: "source:home",
    locks: ["acquire", "release", "acquire", "release", "acquire", "release"],
    wrong_type_code: "content_document_not_found",
    field_code: "content_field_required",
    both_identity_code: "content_document_identity_invalid",
    insert_count: 1,
    isolation: [true, true, true, true],
    scoped_or_query: {
      0: {
        compare: "=",
        key: "_spacefast_space_id",
        value: "spc_alpha",
      },
      1: {
        0: { key: "featured", value: "1" },
        1: { key: "promoted", value: "1" },
        relation: "OR",
      },
      relation: "AND",
    },
    capabilities: [["edit_posts"], ["do_not_allow"]],
    media_query: ["attachment", "inherit", [9, 12]],
    upload_scope: [
      "/srv/wordpress/.stattic/storage/spaces/spc_alpha/content-media",
      `https://alpha.spacefast.test/__spacefast/content-media/${new Bun.CryptoHasher("sha256").update("spc_alpha").digest("hex").slice(0, 32)}`,
      "/srv/wordpress/.stattic/storage/spaces/spc_alpha/content-media/2026/08",
      `https://alpha.spacefast.test/__spacefast/content-media/${new Bun.CryptoHasher("sha256").update("spc_alpha").digest("hex").slice(0, 32)}/2026/08`,
    ],
    request_url: "https://alpha.spacefast.test/wp-admin/edit.php?post_type=post",
  });
});

test("collection resource identity survives renames and cannot change in place", async () => {
  const script = String.raw`
$contentManifest = [
  'format' => 'spacefast.content.schema',
  'version' => 1,
  'collections' => [
    'events' => [
      'resourceId' => 'events_01k4t7x8',
      'name' => 'events',
      'label' => 'Events',
      'singularLabel' => 'Event',
      'fields' => [],
    ],
  ],
];
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
function get_option(string $name, mixed $default): mixed {
  global $contentManifest;
  return $name === spacefast_content_option_name(SPACEFAST_CONTENT_MANIFEST_OPTION)
    ? $contentManifest
    : $default;
}
function update_option(string $name, mixed $value, bool $autoload): void {
  global $contentManifest;
  if ($name === spacefast_content_option_name(SPACEFAST_CONTENT_MANIFEST_OPTION)) {
    $contentManifest = $value;
  }
}
require $argv[1];
$replacement = $contentManifest;
$replacement['collections']['events']['resourceId'] = 'events_01newidentity';
$immutableCode = '';
try {
  spacefast_content_apply_schema($replacement, true);
} catch (Spacefast_Content_Error $error) {
  $immutableCode = $error->codeName;
}
$forked = $contentManifest;
$forked['collections']['calendar'] = $forked['collections']['events'];
$forked['collections']['calendar']['name'] = 'calendar';
$forked['collections']['calendar']['resourceId'] = 'calendar_01newid';
unset($forked['collections']['events']);
$forkCode = '';
try {
  spacefast_content_apply_schema($forked, true);
} catch (Spacefast_Content_Error $error) {
  $forkCode = $error->codeName;
}
$renamed = $contentManifest;
$renamed['collections']['calendar'] = $renamed['collections']['events'];
$renamed['collections']['calendar']['name'] = 'calendar';
unset($renamed['collections']['events']);
$result = spacefast_content_apply_schema($renamed, true);
echo json_encode([
  'immutable_code' => $immutableCode,
  'fork_code' => $forkCode,
  'renamed_resource_id' => $contentManifest['collections']['calendar']['resourceId'] ?? null,
  'revision' => $result['revision'],
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
    immutable_code: "content_collection_identity_immutable",
    fork_code: "content_collection_identity_immutable",
    renamed_resource_id: "events_01k4t7x8",
    revision: expect.stringMatching(/^[a-f0-9]{64}$/),
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
$publicRest = spacefast_content_disable_rest_api(null);
$GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = 17;
$adminRest = spacefast_content_disable_rest_api(null);
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
  'multiple_media' => spacefast_content_sanitize_field_value(
    ['type' => 'media', 'multiple' => true],
    '4,0,9'
  ),
  'scf' => [
    [$scfText['type'], $scfText['rows'], $scfText['maxlength']],
    [$scfMedia['type'], $scfMedia['return_format']],
    str_starts_with($scfText['key'], 'field_'),
  ],
  'rest' => [$publicRest->code, $publicRest->data['status'], $adminRest],
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
    multiple_media: [4, 9],
    scf: [["textarea", 8, 280], ["gallery", "id"], true],
    rest: ["spacefast_rest_disabled", 404, null],
  });
});

test("compiled Payload collections feed Spacefast queries, rendering, and SCF", async () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-payload-schema-"));
  writeFileSync(
    path.join(root, "schema.json"),
    JSON.stringify({
      schema_version: 3,
      collections: [
        {
          slug: "articles",
          wordpress_storage: "post",
          wordpress_post_type: "pwp_articles",
          label: "Articles",
          singular_label: "Article",
          fields: [
            { name: "title", field_type: "text", required: true },
            { name: "body", field_type: "richText", required: false },
            { name: "summary", field_type: "textarea", label: "Summary", required: false },
            { name: "rating", field_type: "number", required: false },
            { name: "layout", field_type: "group", required: false },
          ],
          hooks: {},
          access: { read: [] },
          versions: { enabled: true, drafts: true, max_per_doc: 20 },
          auth: { enabled: false },
          upload: { enabled: false },
        },
      ],
      globals: [
        {
          slug: "site-settings",
          label: "Site settings",
          wordpress_option_name: "payloadwp_global_site_settings",
          fields: [{ name: "tagline", field_type: "text", required: false }],
          hooks: {},
          access: {},
          versions: { enabled: true, drafts: false, max_per_doc: 10 },
        },
      ],
      hooks: [],
      localization: null,
    }),
  );
  writeFileSync(
    path.join(root, "hooks.json"),
    JSON.stringify({
      hooks: [{ id: "hook.quick", target: "quick_js", capabilities: [] }],
    }),
  );
  const script = String.raw`
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] = $argv[2];
define('PAYLOADWP_RUNNER', '/runtime/stattic-runtime');
$hookProcess = null;
function _stattic_runtime_run_subprocess(
  array $command,
  ?array $environment,
  ?string $stdin,
  ?string $cwd,
  ?int $timeout,
  ?int $stdoutLimit,
  ?int $stderrLimit,
): array {
  global $hookProcess;
  $hookProcess = [$command, json_decode((string) $stdin, true), $cwd, $timeout, $stdoutLimit, $stderrLimit];
  return [
    'spawned' => true,
    'timedOut' => false,
    'exitCode' => 0,
    'stdout' => '{"ran":"quickjs"}',
    'stderr' => '',
  ];
}
$options = [];
class WP_Query {
  public array $posts;
  public int $found_posts = 1;
  public int $max_num_pages = 1;
  public function __construct(public array $args) {
    $this->posts = [(object) [
      'ID' => 73,
      'post_type' => 'pwp_articles',
      'post_name' => 'native-wordpress',
      'post_status' => 'publish',
      'post_title' => 'Native WordPress',
      'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
      'post_excerpt' => '',
    ]];
  }
}
function get_post_meta(int $id, string $key, bool $single): mixed {
  return match ($key) {
    'payloadwp_summary' => 'Compiled summary',
    'payloadwp_rating' => '4.5',
    'payloadwp_layout' => '{"theme":"news"}',
    default => '',
  };
}
function get_option(string $name, mixed $default): mixed { global $options; return $options[$name] ?? $default; }
function update_option(string $name, mixed $value, bool $autoload): void { global $options; $options[$name] = $value; }
function get_the_title(object $post): string { return $post->post_title; }
function get_post_time(string $format, bool $gmt, object $post): string { return '2026-08-26T00:00:00+00:00'; }
function get_post_modified_time(string $format, bool $gmt, object $post): string { return '2026-08-26T01:00:00+00:00'; }
function apply_filters(string $name, string $content): string { return '<main>' . $content . '</main>'; }
require $argv[1];
$collection = spacefast_content_resolve_collection('articles');
$publicQueryCode = '';
try {
  spacefast_content_execute_query(['collection' => 'articles'], false);
} catch (Spacefast_Content_Error $error) {
  $publicQueryCode = $error->codeName;
}
$query = spacefast_content_compile_query_args([
  'where' => [['field' => 'rating', 'operator' => 'gte', 'value' => 4]],
], $collection, false);
$post = (new WP_Query([]))->posts[0];
$item = spacefast_content_serialize_post($post, $collection, null);
$blocks = spacefast_content_render_document([
  'format' => 'spacefast.content.render', 'version' => 1, 'collection' => 'articles',
  'slug' => 'native-wordpress', 'field' => 'body', 'output' => 'blocks',
], true);
$html = spacefast_content_render_document([
  'format' => 'spacefast.content.render', 'version' => 1, 'collection' => 'articles',
  'id' => 73, 'field' => 'body', 'output' => 'html',
], true);
$summary = spacefast_content_normalize_compiled_field(
  spacefast_content_compiled_schema()['collections'][0]['fields'][2]
);
$summary['definition']['name'] = $summary['definition']['storageName'];
$scf = spacefast_content_scf_field('payloadwp:articles', 'summary', $summary['definition']);
$layout = spacefast_content_normalize_compiled_field(
  spacefast_content_compiled_schema()['collections'][0]['fields'][4]
);
$nested = spacefast_content_normalize_compiled_fields([[
  'name' => null,
  'field_type' => 'tabs',
  'children' => [[
    'name' => 'seoTitle',
    'field_type' => 'text',
    'localized' => true,
  ]],
]]);
$related = spacefast_content_scf_field('payloadwp:articles', 'related', [
  'type' => 'relation',
  'collections' => ['articles', 'media'],
  'multiple' => true,
]);
$global = spacefast_content_compiled_schema()['globals'][0];
$globalField = spacefast_content_normalize_compiled_field($global['fields'][0]);
$globalField['definition']['globalOption'] = spacefast_content_compiled_global_option_name($global);
$globalField['definition']['globalField'] = $globalField['name'];
spacefast_content_prepare_scf_value('Built on WordPress', 'option', [
  'spacefast_definition' => $globalField['definition'],
]);
echo json_encode([
  'post_type' => $collection['post_type'],
  'scoped' => $collection['scoped'],
  'public_query_code' => $publicQueryCode,
  'filter' => $query['meta_query'][0],
  'item' => $item,
  'blocks' => $blocks,
  'html' => $html,
  'scf' => [$scf['name'], $scf['type'], $scf['rows']],
  'scf_json' => spacefast_content_prepare_scf_value('{"theme":"news"}', 73, [
    'spacefast_definition' => $layout['definition'],
  ]),
  'nested' => [$nested[0]['name'], $nested[0]['definition']['type']],
  'related' => [$related['type'], $related['post_type']],
  'global' => spacefast_content_load_scf_value(null, 'option', [
    'spacefast_definition' => $globalField['definition'],
  ]),
  'global_option_names' => array_keys($options),
  'quickjs' => spacefast_content_run_payload_hook(null, 'hook.quick', ['value' => 1]),
  'hook_process' => $hookProcess,
]);
`;
  try {
    const process = Bun.spawn(["php", "-r", script, kernel, root], {
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
      post_type: "pwp_articles",
      scoped: false,
      public_query_code: "content_collection_not_found",
      filter: { compare: ">=", key: "payloadwp_rating", type: "NUMERIC", value: 4 },
      item: {
        id: 73,
        slug: "native-wordpress",
        status: "publish",
        title: "Native WordPress",
        createdAt: "2026-08-26T00:00:00+00:00",
        updatedAt: "2026-08-26T01:00:00+00:00",
        fields: {
          title: "Native WordPress",
          body: "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->",
          summary: "Compiled summary",
          rating: 4.5,
          layout: { theme: "news" },
        },
      },
      blocks: {
        id: 73,
        content: "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->",
        output: "blocks",
      },
      html: {
        id: 73,
        content: "<main><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></main>",
        output: "html",
      },
      scf: ["payloadwp_summary", "textarea", 16],
      scf_json: { theme: "news" },
      nested: ["seoTitle", "json"],
      related: ["relationship", ["pwp_articles", "attachment"]],
      global: "Built on WordPress",
      global_option_names: [
        `payloadwp_global_site_settings_${new Bun.CryptoHasher("sha256").update("spc_alpha").digest("hex")}`,
      ],
      quickjs: { ran: "quickjs" },
      hook_process: [
        [
          "/runtime/stattic-runtime",
          "run-hook",
          "--manifest",
          path.join(root, "hooks.json"),
          "--id",
          "hook.quick",
        ],
        { value: 1 },
        root,
        5000,
        2097152,
        65536,
      ],
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("compiled Payload auth collections defer identity to Spacefast Auth", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-payload-auth-"));
  writeFileSync(
    path.join(root, "schema.json"),
    JSON.stringify({
      schema_version: 3,
      collections: [
        {
          slug: "users",
          wordpress_storage: "user",
          fields: [],
          auth: { enabled: true },
        },
      ],
      globals: [],
      hooks: [],
    }),
  );
  for (const artifact of [
    "hooks.json",
    "payloadwp.php",
    "payloadwp-admin.js",
    "payloadwp-admin.css",
  ]) {
    writeFileSync(path.join(root, artifact), artifact === "hooks.json" ? '{"hooks":[]}' : "");
  }
  const script = String.raw`
require $argv[1];
try {
  spacefast_content_validate_compiler_artifact($argv[2]);
  echo json_encode(null);
} catch (Spacefast_Content_Error $error) {
  echo json_encode([$error->status, $error->codeName, $error->getMessage()]);
}
`;
  try {
    const process = Bun.spawnSync(["php", "-r", script, kernel, root], {
      cwd: repoRoot,
      stderr: "pipe",
      stdout: "pipe",
    });
    expect(process.exitCode, process.stderr.toString()).toBe(0);
    expect(JSON.parse(process.stdout.toString())).toEqual([
      422,
      "content_auth_collection_unsupported",
      "Payload auth collections must use Spacefast Auth.",
    ]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("content admin users persist only a pseudonymous editor identity", async () => {
  const script = String.raw`
$inserted = null;
$created = null;
$current = null;
function get_user_by(string $field, mixed $value): object|false {
  global $created;
  if ($field === 'login' && $created === null) return false;
  if ($field === 'id' && $created !== null) return $created;
  return false;
}
function wp_insert_user(array $user): int {
  global $created, $inserted;
  $inserted = $user;
  $created = (object) [
    'ID' => 57,
    'display_name' => $user['display_name'],
    'user_email' => $user['user_email'],
  ];
  return 57;
}
function is_wp_error(mixed $value): bool { return false; }
function wp_set_current_user(int $userId): object {
  global $created, $current;
  $current = $userId;
  return $created;
}
require $argv[1];
$GLOBALS['SPACEFAST_CONTENT_ADMIN_IDENTITY'] = [
  'subject' => 'content_' . str_repeat('b', 64),
];
$userId = spacefast_content_admin_establish_user();
echo json_encode(['user_id' => $userId, 'current' => $current, 'inserted' => $inserted]);
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
    current: 57,
    user_id: 57,
    inserted: {
      display_name: "Spacefast editor",
      role: "editor",
      user_email: `spacefast_${new Bun.CryptoHasher("sha256")
        .update(`content_${"b".repeat(64)}`)
        .digest("hex")
        .slice(0, 24)}@spacefast.invalid`,
      user_login: `spacefast_${new Bun.CryptoHasher("sha256")
        .update(`content_${"b".repeat(64)}`)
        .digest("hex")
        .slice(0, 24)}`,
      user_pass: expect.stringMatching(/^[a-f0-9]{64}$/),
    },
  });
});

test("content admin authentication mints a short-lived WordPress secure cookie", async () => {
  const script = String.raw`
define('SECURE_AUTH_COOKIE', 'wordpress_sec_test');
$generated = null;
function wp_generate_auth_cookie(int $userId, int $expiration, string $scheme): string {
  global $generated;
  $generated = compact('userId', 'expiration', 'scheme');
  return 'signed-wordpress-cookie';
}
require $argv[1];
$before = time();
$cookie = spacefast_content_admin_auth_cookie(57, 3600);
$after = time();
echo json_encode([
  'cookie' => $cookie,
  'generated' => $generated,
  'expiration_in_range' => $generated['expiration'] >= $before + 3600
    && $generated['expiration'] <= $after + 3600,
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
    cookie: {
      name: "wordpress_sec_test",
      value: "signed-wordpress-cookie",
    },
    generated: {
      userId: 57,
      expiration: expect.any(Number),
      scheme: "secure_auth",
    },
    expiration_in_range: true,
  });
});

test("custom collection writes enforce the applied field schema before persistence", async () => {
  const script = String.raw`
require $argv[1];
$collection = [
  'fields' => [
    'starts_at' => ['type' => 'date', 'label' => 'Starts at', 'required' => true],
    'capacity' => ['type' => 'number', 'label' => 'Capacity'],
    'summary' => ['type' => 'text', 'label' => 'Summary', 'maxLength' => 5],
    'tracks' => [
      'type' => 'select',
      'label' => 'Tracks',
      'multiple' => true,
      'options' => ['design', 'code'],
    ],
    'images' => ['type' => 'media', 'label' => 'Images', 'multiple' => true],
  ],
];
$cases = [
  ['capacity' => 12],
  ['starts_at' => '2026-02-30'],
  ['starts_at' => '2026-08-26', 'capacity' => 'many'],
  ['starts_at' => '2026-08-26', 'summary' => 'too long'],
  ['starts_at' => '2026-08-26', 'tracks' => 'design'],
  ['starts_at' => '2026-08-26', 'images' => array_fill(0, 101, 1)],
];
$codes = [];
foreach ($cases as $fields) {
  try {
    spacefast_content_validate_document_fields($collection, $fields);
    $codes[] = 'accepted';
  } catch (Spacefast_Content_Error $error) {
    $codes[] = $error->codeName;
  }
}
$valid = spacefast_content_validate_document_fields($collection, [
  'starts_at' => '2026-08-26',
  'capacity' => '42.5',
  'summary' => 'Hello',
  'tracks' => ['design', 'code'],
  'images' => ['7', 9, 7],
]);
echo json_encode(['codes' => $codes, 'valid' => $valid]);
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
    codes: [
      "content_field_required",
      "content_field_invalid",
      "content_field_invalid",
      "content_field_invalid",
      "content_field_invalid",
      "content_field_invalid",
    ],
    valid: {
      starts_at: "2026-08-26",
      capacity: 42.5,
      summary: "Hello",
      tracks: ["design", "code"],
      images: [7, 9],
    },
  });
});
