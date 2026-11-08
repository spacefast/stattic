import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { readFileSync, mkdirSync, renameSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import path from "node:path";

import { deploy, get, type Runtime, startRuntime } from "./harness.ts";

// The WP API door: /wp-json reaches WordPress as the principal a Space
// credential resolves to. The gate's contract is the WordPress context it
// establishes and whether it hands off at all, so WordPress core is stood in for
// by a wp-blog-header.php that reports that context — the same seam
// content-request.test.ts uses for wp-load.php. What WordPress then does with a
// role is content-kernel.php's own (user_has_cap, rest_authentication_errors).

let runtime: Runtime;
/** Where the provider keeps WordPress core: not the document root. */
let wordpressCoreRoot: string;

const OPEN_HOST = "wp-api-open.test";
// The one SDK tag the runtime adds to every HTML page.
const SDK_TAG = '<script defer src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
const OPEN_SPACE = "spc_wp_api_open";
const CLOSED_HOST = "wp-api-closed.test";
const CLOSED_SPACE = "spc_wp_api_closed";
// A Space whose only public Grant excludes `/wp-json/**`: pages are public, the
// REST API is not. Both spellings of a REST call must be denied the same way.
const EXCLUDED_HOST = "wp-api-excluded.test";
const EXCLUDED_SPACE = "spc_wp_api_excluded";
// A fully-open Space (public on `live` AND `all_versions`, no fence) whose
// overlay `open` flag is true, so the serve path skips access enforcement for
// the anonymous answer.
const FULLY_OPEN_HOST = "wp-api-fully-open.test";
const FULLY_OPEN_SPACE = "spc_wp_api_fully_open";
// A static Space co-hosted on the same site: a Public Grant but no content
// model, so /wp-json must not boot the site-wide WordPress kernel.
const STATIC_HOST = "wp-api-static.test";
const STATIC_SPACE = "spc_wp_api_static";
const MACHINE = "mac_wp_api_agent";
const SERVED_MODEL_REVISION = `sha256:${"a".repeat(64)}`;
const EXCHANGE_CREDENTIAL = "runtime-wp-api-exchange-credential-0123456789";

const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-wp-api-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-wp-api-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-wp-api-test-token";

const [{ mintRuntimeBearerToken }, { runtimeJwks }] = await Promise.all([
  import("../../apps/control-plane/src/access/authorize.ts"),
  import("../../apps/control-plane/src/runtime/auth.ts"),
]);

type TestGrant = {
  id: string;
  generation: number;
  audience:
    | { kind: "machine"; machineId: string }
    | { kind: "public" }
    | { kind: "external"; issuer: string; subject: string };
  resources: { include: string[]; exclude: string[] };
  capabilities: string[];
  constraints: object;
  target: { kind: string };
  source: { kind: string; reference: string };
};

/**
 * A Space whose only non-public authority is one machine credential. Withholding
 * the Public Grant is what makes the closed Space protected — the same thing
 * that closes it on the serve path.
 *
 * `excludeRest` scopes the Public Grant to exclude `/wp-json/**` (pages public,
 * REST private). `allVersionsPublic` adds a second, unconditional Public Grant
 * on `all_versions` so the overlay's `open` flag compiles to true — the state
 * where the serve path skips access enforcement for the anonymous answer.
 */
function accessConfig(
  publicGrant: boolean,
  {
    excludeRest = false,
    allVersionsPublic = false,
  }: {
    excludeRest?: boolean;
    allVersionsPublic?: boolean;
  } = {},
) {
  const grants: TestGrant[] = [
    // The agent's credential. `content.publish` is what earns it `editor`.
    {
      id: "grt_wp_api_machine",
      generation: 1,
      audience: { kind: "machine", machineId: MACHINE },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view", "content.publish"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "managed", reference: "test:wp-api" },
    },
  ];
  grants.push({
    id: "grt_wp_api_cookie_user",
    generation: 1,
    audience: { kind: "external", issuer: "spacefast-membership", subject: "mem_cookie_user" },
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view", "content.publish"],
    constraints: {},
    target: { kind: "live" },
    source: { kind: "managed", reference: "test:wp-api-cookie" },
  });
  if (publicGrant) {
    grants.push({
      id: "grt_wp_api_public",
      generation: 1,
      audience: { kind: "public" },
      resources: { include: ["/**"], exclude: excludeRest ? ["/wp-json/**"] : [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "managed", reference: "test:wp-api" },
    });
  }
  if (allVersionsPublic) {
    // The second half of an unconditionally-open Space: the compiler flags
    // `open` only when a Public Grant is unconditional on BOTH live and
    // all_versions.
    grants.push({
      id: "grt_wp_api_public_all",
      generation: 1,
      audience: { kind: "public" },
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "all_versions" },
      source: { kind: "managed", reference: "test:wp-api" },
    });
  }
  return {
    public_exposure: {
      v: 1,
      public: publicGrant,
      authorizationDigest: "0".repeat(64),
      contentTypes: null,
      externalProxy: false,
      unmodeled: "",
    },
    projection_generation: 1,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/opaque",
      accessPage: {
        displayName: null,
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/opaque/password",
          tokenUrl: "https://access.spacefast.test/acquire/opaque/token",
          requestUrl: "https://access.spacefast.test/acquire/opaque/request",
          credential: EXCHANGE_CREDENTIAL,
        },
      },
      spaceClaimed: true,
      grants,
    },
    visitor_issuer: "spacefast-api",
    visitor_jwks: runtimeJwks(),
  };
}

async function machineToken({
  host = OPEN_HOST,
  spaceId = OPEN_SPACE,
}: { host?: string; spaceId?: string } = {}) {
  return (
    await mintRuntimeBearerToken({
      machineAuthority: `machine:${MACHINE}`,
      spaceId,
      sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
      generation: 1,
      audience: host,
      jti: `jti_${randomUUID()}`,
    })
  ).token;
}

function principalCookie(kind: "editor" | "access"): string {
  const probe = Bun.spawnSync([
    "php",
    "-r",
    `putenv('SPACEFAST_INSECURE_COOKIES=1');
     require $argv[1] . '/engine/shared/context.php';
     require $argv[1] . '/engine/shared/storage.php';
     require $argv[1] . '/engine/shared/content-admin.php';
     require $argv[1] . '/engine/shared/content-access.php';
     require $argv[1] . '/engine/runtime/serve.php';
     require $argv[1] . '/engine/runtime/access-rules.php';
     _stattic_access_private_root($argv[2]);
     if ($argv[5] === 'access') {
       _sf_load_generated_config($argv[2]);
       $serving = _stattic_content_access_target($argv[2], $argv[3])['serving'];
       $GLOBALS['SPACEFAST_PAGE_SERVING'] = $serving;
       $credential = _stattic_access_session_create($serving, [
         'sub' => 'cookie-user', 'exp' => time() + 3600,
         'claims' => ['spaceId' => $argv[4], 'sid' => hash('sha256', 'wp-api-cookie'),
           'principal' => 'account:cookie-user',
           'identity' => ['issuer' => 'https://api.spacefast.com/v1/auth', 'subject' => 'cookie-user'],
           'authorities' => ['member:mem_cookie_user']],
       ], $argv[3], 0, $serving['authorization']);
       if ($credential === null) exit(1);
       echo _stattic_session_cookie_name() . '=' . $credential;
       exit;
     }
     $authorization = ['space_id' => $argv[4], 'access_generation' => 1];
     _stattic_content_admin_apply_authorization($argv[2], $authorization);
     $session = _stattic_content_admin_mint_session($argv[2], $argv[3], 42,
       ['kind' => 'user', 'issuer' => 'spacefast', 'subject' => 'cookie-editor'],
       $authorization, 'administrator', 'https://my.spacefast.test', ['surface' => 'wordpress'], 'https://' . $argv[3]);
     if ($session === null) exit(1);
     echo _stattic_content_admin_cookie_name() . '=' . $session['token'];`,
    path.dirname(runtime.engineRoot),
    runtime.storageRoot,
    OPEN_HOST,
    OPEN_SPACE,
    kind,
  ]);
  expect(probe.exitCode, probe.stderr.toString()).toBe(0);
  return probe.stdout.toString();
}

/** What the gate established, as WordPress would find it. */
async function wpContext(host: string, requestPath: string, headers: Record<string, string> = {}) {
  const response = await get(runtime, host, requestPath, { headers });
  const body = await response.text();
  return {
    status: response.status,
    body,
    context: response.status === 200 ? JSON.parse(body) : null,
    headers: response.headers,
  };
}

const documentFixtures = {
  "/": "hello",
  "/about": "about",
  "/docs/about": "launch",
  "/archive": "archive",
  "/foreign": "foreign",
};
const documentTargets = new Map(Object.entries(documentFixtures));
const documentPages = Object.keys(documentFixtures).map((route) => {
  const digest = createHash("sha256").update(route).digest("hex").slice(0, 32);
  return {
    id: `page.${digest}`,
    path: route,
    params: [],
    render: "document",
    bindingId: `sync.pages.${digest}`,
  };
});

beforeAll(async () => {
  runtime = await startRuntime();
  wordpressCoreRoot = path.join(runtime.root, "__wp__");
  const files = {
    "index.html": "<!doctype html><html><head></head><body><h1>space</h1></body></html>\n",
    "collision/index.html":
      "<!doctype html><html><head></head><body><h1>static collision</h1></body></html>\n",
  };
  const modelReference = {
    "_spacefast/pages/documents/model.json": JSON.stringify({ revision: SERVED_MODEL_REVISION }),
  };
  const documentSeeds = Object.fromEntries(
    documentPages.map((page) => [
      `_spacefast/pages/documents/${page.id}.json`,
      JSON.stringify({
        bindingId: page.bindingId,
        modelRevision: SERVED_MODEL_REVISION,
        format: "tsx",
        text: "",
        sha256: `sha256:${createHash("sha256").update("").digest("hex")}`,
      }),
    ]),
  );
  await deploy(runtime, {
    spaceId: OPEN_SPACE,
    versionId: "ver_wp_api_open",
    files: {
      "collision/index.html": files["collision/index.html"],
      ...modelReference,
      ...documentSeeds,
    },
    serving: {
      pages: documentPages,
      // Every finalize with platform injection on records the baked SDK tag.
      config: {
        inject: {
          bodyEnd: ['<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>'],
        },
      },
      theme_css:
        ":root{--sf-accent:#6d28d9;--sf-bg:#faf7ff;--sf-fg:#20182b;--sf-font:Inter,sans-serif}",
    },
    activate: {
      route_name: "production",
      config: { ...accessConfig(true), sdk: { revision: "sdk-1", config: {} } },
      production_hostnames: [OPEN_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: CLOSED_SPACE,
    versionId: "ver_wp_api_closed",
    files: { ...files, ...modelReference },
    activate: {
      route_name: "production",
      config: accessConfig(false),
      production_hostnames: [CLOSED_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: EXCLUDED_SPACE,
    versionId: "ver_wp_api_excluded",
    files: { ...files, ...modelReference },
    activate: {
      route_name: "production",
      config: accessConfig(true, { excludeRest: true }),
      production_hostnames: [EXCLUDED_HOST],
      version_hostnames: [],
    },
  });
  await deploy(runtime, {
    spaceId: FULLY_OPEN_SPACE,
    versionId: "ver_wp_api_fully_open",
    files: { ...files, ...modelReference },
    activate: {
      route_name: "production",
      config: accessConfig(true, { allVersionsPublic: true }),
      production_hostnames: [FULLY_OPEN_HOST],
      version_hostnames: [],
    },
  });
  // A static Space co-hosted on the same site: it has a Public Grant but no
  // content model, so it must never boot the site-wide WordPress on /wp-json.
  await deploy(runtime, {
    spaceId: STATIC_SPACE,
    versionId: "ver_wp_api_static",
    files,
    activate: {
      route_name: "production",
      config: accessConfig(true),
      production_hostnames: [STATIC_HOST],
      version_hostnames: [],
    },
  });
  // The editor has moved to another model while these versions still serve.
  // Public REST must use the model each version sealed, including versions
  // without document pages. STATIC_SPACE deliberately ships no reference.
  for (const spaceId of [
    OPEN_SPACE,
    CLOSED_SPACE,
    EXCLUDED_SPACE,
    FULLY_OPEN_SPACE,
    STATIC_SPACE,
  ]) {
    const modelRoot = path.join(
      runtime.root,
      ".stattic",
      "storage",
      "spaces",
      spaceId,
      "content-model",
    );
    const release = path.join(modelRoot, "releases", SERVED_MODEL_REVISION.slice(7));
    mkdirSync(release, { recursive: true });
    writeFileSync(path.join(release, "content-model.php"), "<?php return [];\n");
    writeFileSync(path.join(modelRoot, "active-release"), `sha256:${"b".repeat(64)}\n`);
  }
  // The provider's layout, which is what makes finding the front controller a
  // question at all: WordPress core lives under `__wp__/` and only wp-load.php
  // is linked into the root the engine installs into. A lane that names a core
  // file beside that root names a file which does not exist.
  mkdirSync(wordpressCoreRoot, { recursive: true });
  symlinkSync(path.join("__wp__", "wp-load.php"), path.join(runtime.root, "wp-load.php"));

  // WordPress's front controller. Reaching it at all is the hand-off the gate
  // owes the REST lane; the globals are the identity it hands over.
  writeFileSync(
    path.join(wordpressCoreRoot, "wp-blog-header.php"),
    [
      "<?php",
      "header_register_callback(static function () {});",
      "header('Origin-Agent-Cluster: ?0');",
      "header('Permissions-Policy: camera=(), document-domain=*');",
      "header('Content-Type: application/json', true);",
      "$principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;",
      "echo json_encode([",
      "  'served_by' => 'wordpress',",
      "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
      "  'role' => $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] ?? null,",
      "  'principal_kind' => is_array($principal) ? ($principal['kind'] ?? null) : null,",
      "  'actor_id' => is_array($principal) ? ($principal['actor_id'] ?? null) : null,",
      "  'themes' => defined('WP_USE_THEMES') ? WP_USE_THEMES : null,",
      "  'model_revision' => $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] ?? null,",
      "]);",
      "",
    ].join("\n"),
  );
  // The public Page lane boots WordPress without its front controller or
  // theme, then resolves the selected document by binding. This fixture
  // is the WordPress boundary: it exposes the same functions the lane calls
  // while keeping the routing assertion focused on Spacefast's precedence.
  writeFileSync(
    path.join(wordpressCoreRoot, "wp-load.php"),
    [
      "<?php",
      "if (!defined('OBJECT')) define('OBJECT', 'OBJECT');",
      // Published documents cover Space ownership, blocks, and island mounts.
      "$GLOBALS['spacefast_test_documents'] = [",
      "  'about' => [41, 'page', '<p>WordPress about</p>'],",
      "  'collision' => [42, 'page', '<p>WordPress collision</p>'],",
      "  'hello' => [43, 'page', '<p>WordPress hello</p>'],",
      "  'foreign' => [44, 'page', '<p>WordPress foreign</p>'],",
      "  'archive' => [45, 'page', '<!-- wp:query {\"queryId\":7} --><!-- /wp:query -->'],",
      // A collection document carrying an island: same post type as `hello`, told
      // apart only by its collection term, and holding the core/html mount that
      // do_blocks has to pass through untouched.
      '  \'launch\' => [46, \'page\', \'<!-- wp:html --><div data-zero-component="counter" data-zero-source="client/components/counter.tsx" data-zero-export="Counter" data-zero-props="{&quot;label&quot;:&quot;Hi&quot;}">Prerendered</div><script type="module" src="/_spacefast/islands/abc123/boot.js"></script><!-- /wp:html -->\'],',
      "];",
      `$GLOBALS['spacefast_test_binding_paths'] = json_decode('${JSON.stringify(Object.fromEntries(documentPages.map((page) => [page.bindingId, documentTargets.get(page.path)])))}', true);`,
      "function spacefast_content_model_sync_binding($bindingId) { return isset($GLOBALS['spacefast_test_binding_paths'][$bindingId]) ? ['post_type' => 'page'] : null; }",
      "function spacefast_content_sync_find_post($bindingId, $binding, $adopt = true) {",
      "  $path = $GLOBALS['spacefast_test_binding_paths'][$bindingId] ?? '';",
      "  $entry = $GLOBALS['spacefast_test_documents'][$path] ?? null;",
      "  if ($entry === null) return null;",
      "  return (object) [",
      "    'ID' => $entry[0],",
      "    'post_type' => $entry[1],",
      "    'post_status' => 'publish',",
      "    'post_title' => ucfirst($path),",
      "    'post_excerpt' => '',",
      "    'post_content' => $entry[2],",
      "    'post_modified_gmt' => '2026-09-01 02:00:00',",
      "  ];",
      "}",
      "function get_post_meta($postId, $key, $single = false) {",
      "  if ($key !== '_spacefast_space_id') return '';",
      "  return $postId === 44 ? 'spc_wp_api_other' : ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');",
      "}",
      "$GLOBALS['spacefast_test_hooks'] = [];",
      "$GLOBALS['spacefast_test_filters'] = [];",
      "$GLOBALS['spacefast_test_scripts'] = [];",
      "function add_action($hook, $callback) { $GLOBALS['spacefast_test_hooks'][$hook][] = $callback; }",
      "function add_filter($hook, $callback) { $GLOBALS['spacefast_test_filters'][$hook][] = $callback; }",
      "function do_action($hook) {",
      "  foreach ($GLOBALS['spacefast_test_hooks'][$hook] ?? [] as $callback) { $callback(); }",
      "}",
      "function wp_enqueue_script($handle, $src, $deps = [], $version = false, $args = []) {",
      "  $GLOBALS['spacefast_test_scripts'][$handle] = ['src' => $src, 'args' => $args];",
      "}",
      "function wp_head() { do_action('wp_enqueue_scripts'); do_action('wp_head'); }",
      "function wp_footer() {",
      "  do_action('wp_footer');",
      "  foreach ($GLOBALS['spacefast_test_scripts'] as $handle => $script) {",
      "    echo '<script id=\"' . $handle . '-js\" src=\"' . $script['src'] . '\"></script>';",
      "  }",
      "}",
      "function apply_filters($hook, $value) {",
      "  foreach ($GLOBALS['spacefast_test_filters'][$hook] ?? [] as $callback) { $value = $callback($value); }",
      "  return $value;",
      "}",
      // WordPress registers do_blocks on `the_content`, and do_blocks is what
      // runs render_block. The lane's whole archive story is that it must not
      // break that filter; this stand-in renders the three block shapes the lane
      // depends on: a template's core/post-content, a static core/html rendered
      // as its own inner markup, and the one dynamic block.
      "add_filter('the_content', function ($content) {",
      "  $content = str_replace(",
      "    '<!-- wp:post-content /-->',",
      "    (string) ($GLOBALS['post']->post_content ?? ''),",
      "    $content",
      "  );",
      "  $content = preg_replace('/<!-- wp:html -->(.*?)<!-- \\/wp:html -->/s', '$1', $content);",
      "  return preg_replace(",
      "    '/<!-- wp:query .*?<!-- \\/wp:query -->/s',",
      "    '<ul class=\"wp-block-post-template\"><li>Hello</li></ul>',",
      "    $content",
      "  );",
      "});",
      // The real content-templates.php resolves the template, so what this lane
      // serves is the kernel's own answer rather than a transcription of it. The
      // release is supplied as data — which is what a fixture is for — and it
      // deliberately declares no `posts` resource, so `hello` and `launch` are
      // the same post type with no post-type template between them and the
      // collection term is the only thing that can tell them apart.
      "const SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY = 'zero_collection';",
      "function spacefast_content_model_collection_term_slug($spaceId, $resourceId) {",
      "  return 'sf-' . substr(hash('sha256', $spaceId), 0, 16) . '-' . str_replace(['.', '_'], '-', $resourceId);",
      "}",
      "function spacefast_content_space_id() { return (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? ''); }",
      "function spacefast_content_require_space_id() { return spacefast_content_space_id(); }",
      "function spacefast_content_space_meta_clause() {",
      "  return ['key' => '_spacefast_space_id', 'value' => spacefast_content_space_id(), 'compare' => '='];",
      "}",
      "function spacefast_content_model_active_release() {",
      "  return ['postTypes' => [",
      "    ['id' => 'pages', 'kind' => 'pages', 'postType' => 'page', 'label' => 'Pages'],",
      "    ['id' => 'projects', 'kind' => 'collection', 'postType' => 'post', 'label' => 'Projects'],",
      "  ]];",
      "}",
      "function spacefast_content_model_resources() { return spacefast_content_model_active_release()['postTypes']; }",
      "function spacefast_content_model_resource($resourceId) {",
      "  foreach (spacefast_content_model_resources() as $resource) {",
      "    if ($resource['id'] === $resourceId) return $resource;",
      "  }",
      "  return null;",
      "}",
      "function spacefast_content_collection_for_post_type($postType) {",
      "  return match ($postType) {",
      "    'post' => ['name' => 'posts'],",
      "    'page' => ['name' => 'pages'],",
      "    default => null,",
      "  };",
      "}",
      "function wp_get_object_terms($postId, $taxonomy, $args = []) {",
      "  if ($taxonomy !== 'zero_collection' || (int) $postId !== 46) return [];",
      "  return [spacefast_content_model_collection_term_slug(spacefast_content_space_id(), 'projects')];",
      "}",
      // A Space's own saved edits, which win over the release's default markup.
      "function get_posts($args) {",
      "  $saved = [",
      "    'page' => '<div class=\"sf-space-template\"><!-- wp:post-content /--></div>',",
      "    'single-projects' => '<div class=\"sf-collection-template\"><!-- wp:post-content /--></div>',",
      "  ];",
      "  if (($args['post_type'] ?? '') !== 'wp_template') return [];",
      "  $found = [];",
      "  foreach ((array) ($args['post_name__in'] ?? []) as $index => $name) {",
      "    if (!isset($saved[$name])) continue;",
      "    $found[] = (object) [",
      "      'ID' => 900 + $index,",
      "      'post_name' => $name,",
      "      'post_status' => 'publish',",
      "      'post_content' => $saved[$name],",
      "    ];",
      "  }",
      "  return $found;",
      "}",
      `require ${JSON.stringify(path.join(import.meta.dir, "../engine/wordpress/content-templates.php"))};`,
      "function setup_postdata($post) { $GLOBALS['post'] = $post; }",
      "function wp_reset_postdata() {}",
      "",
    ].join("\n"),
  );
}, 30000);

afterAll(() => runtime?.stop());

test("a Space credential reaches WordPress REST as the principal its Grants earn", async () => {
  const token = await machineToken();
  const cookie = principalCookie("editor");
  const editor = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    cookie,
    origin: `http://${OPEN_HOST}`,
    "sec-fetch-site": "same-origin",
  });
  expect(editor.status).toBe(200);
  expect(editor.context).toMatchObject({ role: "administrator", principal_kind: "user" });
  const admitted = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
    cookie,
    origin: `http://${OPEN_HOST}`,
    "sec-fetch-site": "same-origin",
  });

  expect(admitted.status, admitted.body).toBe(200);
  expect(admitted.headers.get("origin-agent-cluster")).toBe("?1");
  expect(admitted.headers.get("permissions-policy")).toBe("document-domain=(), camera=()");
  expect(admitted.context).toEqual({
    served_by: "wordpress",
    space_id: OPEN_SPACE,
    // `content.publish` on the credential's Grant, mapped by the runtime half of
    // wordpressRoleForGrantCapabilities.
    role: "editor",
    // An API key is not a person: it reaches WordPress as a service actor keyed
    // by the credential id, so it owns one durable user rather than borrowing
    // somebody's.
    principal_kind: "service",
    actor_id: MACHINE,
    themes: false,
    model_revision: SERVED_MODEL_REVISION,
  });

  // The query spelling of the same lane, for a Space without pretty permalinks.
  const queryForm = await wpContext(OPEN_HOST, "/?rest_route=/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(queryForm.context).toMatchObject({ served_by: "wordpress", role: "editor" });
  const machineWrite = await get(runtime, OPEN_HOST, "/wp-json/wp/v2/posts", {
    method: "POST",
    headers: {
      "x-sf-authorization": `Bearer ${token}`,
      cookie,
      origin: "https://sibling.test",
      "sec-fetch-site": "same-site",
    },
  });
  expect(machineWrite.status, await machineWrite.clone().text()).toBe(200);
  expect(await machineWrite.json()).toMatchObject({
    served_by: "wordpress",
    role: "editor",
    principal_kind: "service",
    actor_id: MACHINE,
  });
  const accessCookie = principalCookie("access");
  const accessUser = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    cookie: accessCookie,
    origin: `http://${OPEN_HOST}`,
    "sec-fetch-site": "same-origin",
  });
  expect(accessUser.status, accessUser.body).toBe(200);
  expect(accessUser.context).toMatchObject({
    principal_kind: "user",
    actor_id: "cookie-user",
    role: "editor",
  });
  const deniedAccess = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    cookie: accessCookie,
    origin: "http://sibling.test",
    "sec-fetch-site": "same-site",
    authorization: "Bearer junk",
  });
  expect(deniedAccess.status).toBe(403);
  expect(deniedAccess.context).toBeNull();
  const machineWithAccess = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    cookie: accessCookie,
    origin: `http://${OPEN_HOST}`,
    "sec-fetch-site": "same-origin",
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(machineWithAccess.status, machineWithAccess.body).toBe(200);
  expect(machineWithAccess.context).toMatchObject({
    principal_kind: "service",
    actor_id: MACHINE,
    role: "editor",
  });

  // Without a docroot front controller the gate boots WordPress immediately.
  // That pass must load response.php too, before WordPress flushes its body.
  const indexPath = path.join(runtime.root, "index.php");
  const savedIndexPath = path.join(runtime.root, "index.php.saved");
  renameSync(indexPath, savedIndexPath);
  try {
    const immediate = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
      "x-sf-authorization": `Bearer ${token}`,
    });
    expect(immediate.status).toBe(200);
    expect(immediate.context).toEqual(admitted.context);
    expect(immediate.headers.get("origin-agent-cluster")).toBe("?1");
    expect(immediate.headers.get("permissions-policy")).toBe("document-domain=(), camera=()");
  } finally {
    renameSync(savedIndexPath, indexPath);
  }
});

test("REST without a Spacefast credential is WordPress's own unauthenticated answer", async () => {
  // A public Space admits the request exactly as it admits a page view, and
  // hands WordPress no principal — so WordPress answers as nobody, which is what
  // it would do on its own. The door is not a second authorization.
  const anonymous = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts");

  expect(anonymous.status).toBe(200);
  expect(anonymous.context).toEqual({
    served_by: "wordpress",
    space_id: OPEN_SPACE,
    role: null,
    principal_kind: null,
    actor_id: null,
    themes: false,
    model_revision: SERVED_MODEL_REVISION,
  });

  const indexPath = path.join(runtime.root, "index.php");
  const installedIndex = readFileSync(indexPath);
  writeFileSync(
    indexPath,
    [
      "<?php",
      "set_error_handler(static function ($severity, $message) { throw new ErrorException($message, 0, $severity); });",
      "define('WP_USE_THEMES', true);",
      `require ${JSON.stringify(path.join(wordpressCoreRoot, "wp-blog-header.php"))};`,
    ].join("\n"),
  );
  try {
    const providerController = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts");
    expect(providerController.status).toBe(200);
    expect(providerController.context).toMatchObject({
      served_by: "wordpress",
      space_id: OPEN_SPACE,
      themes: true,
    });
  } finally {
    writeFileSync(indexPath, installedIndex);
  }
});

test("an unusable credential is refused rather than downgraded", async () => {
  // Machine callers never fall back to anonymous: the whole point of presenting
  // a credential is that its failure is visible.
  const garbage = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": "Bearer not-a-real-credential",
    cookie: principalCookie("editor"),
    origin: "http://sibling.test",
    "sec-fetch-site": "same-site",
  });
  expect(garbage.status).toBe(403);
  expect(garbage.context).toBeNull();
  expect(garbage.body).not.toContain("served_by");

  // Expiry, signature and replay are _stattic_visitor_verify's own, proven
  // where they live (access-chain.test.ts); the case above is what THIS lane
  // adds — a credential the runtime cannot use denies instead of quietly
  // serving the public answer.

  // A well-formed, validly signed, unexpired credential for a DIFFERENT Space
  // is its own failure mode: the audience binding has to reach this lane too.
  const foreign = await machineToken({ spaceId: CLOSED_SPACE, host: CLOSED_HOST });
  const crossed = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${foreign}`,
  });
  expect(crossed.status).toBe(403);
  expect(crossed.body).not.toContain("served_by");
});

test("a protected Space answers REST the way it answers a page", async () => {
  // No Public Grant, no credential: the access engine denies, WordPress never
  // runs, and the response says nothing about the Space behind it.
  const denied = await wpContext(CLOSED_HOST, "/wp-json/wp/v2/posts");
  expect(denied.status).not.toBe(200);
  expect(denied.body).not.toContain("served_by");
  expect(denied.body).not.toContain(CLOSED_SPACE);

  // The credential that Space did issue still gets in.
  const token = await machineToken({ spaceId: CLOSED_SPACE, host: CLOSED_HOST });
  const admitted = await wpContext(CLOSED_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(admitted.status).toBe(200);
  expect(admitted.context).toMatchObject({
    served_by: "wordpress",
    space_id: CLOSED_SPACE,
    role: "editor",
  });
});

test("a Space with no WordPress never claims /wp-json", async () => {
  // Most Spaces are static. On those /wp-json is an ordinary URL the Space does
  // not publish, so it gets the Space's own answer — not an editor-session gate
  // for an editor that does not exist.
  const frontController = path.join(wordpressCoreRoot, "wp-blog-header.php");
  const saved = readFileSync(frontController);
  rmSync(frontController);
  try {
    const response = await get(runtime, OPEN_HOST, "/wp-json/wp/v2/posts");
    const body = await response.text();
    expect(response.status).toBe(404);
    expect(body).not.toContain("content_admin_session_invalid");
    expect(body).not.toContain("served_by");
  } finally {
    writeFileSync(frontController, saved);
  }
});

test("classic WordPress admin receives the same authenticated Space scope", async () => {
  mkdirSync(path.join(runtime.root, "wp-admin"), { recursive: true });
  writeFileSync(
    path.join(runtime.root, "wp-admin/edit.php"),
    "<?php header('Content-Type: application/json'); echo json_encode(['space' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'], 'role' => $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE']]);",
  );
  const token = await machineToken();
  const response = await get(runtime, OPEN_HOST, "/wp-admin/edit.php", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ space: OPEN_SPACE, role: "editor" });

  const includesRoot = path.join(runtime.root, "wp-admin/includes");
  mkdirSync(includesRoot, { recursive: true });
  writeFileSync(path.join(includesRoot, "file.php"), "<?php echo 'internal include executed';");
  // nginx resolves merged slashes and percent-escapes to the same script, so
  // those spellings must not slip past the rule.
  for (const spelling of [
    "/wp-admin/includes/file.php",
    "/wp-admin//includes/file.php",
    "/wp-admin/%69ncludes/file.php",
  ]) {
    const internalInclude = await get(runtime, OPEN_HOST, spelling, {
      headers: { "x-sf-authorization": `Bearer ${token}` },
    });
    expect(internalInclude.status, spelling).toBe(404);
    expect(await internalInclude.text()).toBe("Not Found");
  }
});

test("both REST spellings honour a Grant that scopes /wp-json", async () => {
  // The Space's page is public, but its only Public Grant excludes `/wp-json/**`.
  // Both ways of naming the SAME REST resource must be denied identically — the
  // query spelling `/?rest_route=` addresses `/wp-json/...` and is enforced
  // against that path, not against `/`. Otherwise the query form reaches REST
  // under the `/` policy the exclude never touched.
  const home = await get(runtime, EXCLUDED_HOST, "/");
  expect(home.status).toBe(200);

  const pretty = await wpContext(EXCLUDED_HOST, "/wp-json/wp/v2/posts");
  expect(pretty.status).not.toBe(200);
  expect(pretty.body).not.toContain("served_by");

  const query = await wpContext(EXCLUDED_HOST, "/?rest_route=/wp/v2/posts");
  expect(query.status).not.toBe(200);
  expect(query.body).not.toContain("served_by");
});

test("a fully-open Space still refuses an unusable credential", async () => {
  // `open` is true here, so the anonymous answer skips enforcement — but a
  // presented credential is still a credential. An unusable one denies rather
  // than falling through to WordPress's public answer, so a machine caller's
  // failure is never hidden behind a 200.
  const garbage = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": "Bearer not-a-real-credential",
  });
  expect(garbage.status).toBe(403);
  expect(garbage.body).not.toContain("served_by");

  // No credential is the anonymous path, unchanged: WordPress answers as nobody.
  const anonymous = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts");
  expect(anonymous.status).toBe(200);
  expect(anonymous.context).toMatchObject({ served_by: "wordpress", role: null });

  // A valid credential still elevates on an open Space.
  const token = await machineToken({ spaceId: FULLY_OPEN_SPACE, host: FULLY_OPEN_HOST });
  const admitted = await wpContext(FULLY_OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(admitted.status).toBe(200);
  expect(admitted.context).toMatchObject({ served_by: "wordpress", role: "editor" });
});

test("WordPress REST is available by default and retains the static Space's scope", async () => {
  const anonymous = await wpContext(STATIC_HOST, "/wp-json/wp/v2/posts");
  expect(anonymous.status).toBe(200);
  expect(anonymous.context).toMatchObject({
    served_by: "wordpress",
    space_id: STATIC_SPACE,
    role: null,
  });
  const query = await wpContext(STATIC_HOST, "/?rest_route=/wp/v2/posts");
  expect(query.status).toBe(200);
  expect(query.context).toMatchObject({ served_by: "wordpress", space_id: STATIC_SPACE });
});

test("canonical WordPress documents share Customization styles", async () => {
  const page = await get(runtime, OPEN_HOST, "/about");
  const pageBody = await page.text();
  expect(page.status).toBe(200);
  expect(pageBody).toContain("WordPress about");
  expect(pageBody).toContain("/__spacefast_generated/theme.css");
  expect(pageBody.split(SDK_TAG)).toHaveLength(2);
  expect(pageBody.indexOf(SDK_TAG)).toBeLessThan(pageBody.indexOf("</head>"));

  const collision = await get(runtime, OPEN_HOST, "/collision/");
  const collisionBody = await collision.text();
  const queryCollision = await get(runtime, OPEN_HOST, "/collision/?rest_route=/wp/v2/posts");
  expect(queryCollision.status).toBe(200);
  expect(await queryCollision.text()).toContain("static collision");
  const postCollision = await get(runtime, OPEN_HOST, "/collision/", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: "rest_route=/wp/v2/posts",
  });
  expect(postCollision.status).toBe(405);
  expect(collision.status).toBe(200);
  expect(collisionBody).toContain("static collision");
  expect(collisionBody).not.toContain("WordPress collision");
  expect(collisionBody).toContain("/__spacefast_generated/theme.css");

  const theme = await get(runtime, OPEN_HOST, "/__spacefast_generated/theme.css");
  expect(theme.status).toBe(200);
  expect(await theme.text()).toContain("--sf-accent:#6d28d9");

  const unmanaged = await get(runtime, STATIC_HOST, "/about");
  expect(unmanaged.status).toBe(404);
  expect(await unmanaged.text()).not.toContain("WordPress about");
});

test("canonical root and nested documents preserve templates and islands", async () => {
  const post = await get(runtime, OPEN_HOST, "/");
  const postBody = await post.text();
  expect(post.status).toBe(200);
  expect(postBody).toContain("WordPress hello");

  expect(postBody.split(SDK_TAG)).toHaveLength(2);
  expect(postBody).toContain('<div class="sf-space-template">');

  // Ownership is checked before the method gate, so a co-hosted Space's post is
  // a miss that says nothing rather than a 405 that confirms it exists.
  const foreign = await get(runtime, OPEN_HOST, "/foreign");
  const foreignBody = await foreign.text();
  expect(foreign.status).toBe(404);
  expect(foreignBody).not.toContain("WordPress foreign");
  expect(foreignBody).not.toContain("Foreign");

  const written = await get(runtime, OPEN_HOST, "/", { method: "POST" });
  expect(written.status).toBe(405);

  // An archive needs no archive machinery: its markup holds core/query, and
  // `the_content` already runs do_blocks. What this lane must do is not break
  // that — no main query, and postdata set around the filter.
  const archive = await get(runtime, OPEN_HOST, "/archive");
  const archiveBody = await archive.text();
  expect(archive.status).toBe(200);
  expect(archiveBody).toContain('<ul class="wp-block-post-template"><li>Hello</li></ul>');
  expect(archiveBody).not.toContain("<!-- wp:query");
  // A Space-owned template renders INSTEAD of the lane's heading-and-content
  // frame, and its own markup is what `the_content` ran over — which is what
  // makes core/post-content and core/post-title mean anything at all.
  expect(archiveBody).toContain('<div class="sf-space-template">');
  expect(archiveBody).not.toContain("<article><h1>Archive</h1>");

  // The collection term selects its own template instead of the page template.
  const item = await get(runtime, OPEN_HOST, "/docs/about");
  const itemBody = await item.text();
  expect(item.status).toBe(200);
  expect(itemBody).toContain('<div class="sf-collection-template">');
  expect(itemBody).not.toContain("<article><h1>Launch</h1>");

  // do_blocks renders a static core/html block as its own inner HTML, so an
  // island's mount and its boot module reach the reader intact on this lane —
  // which is why the island block is core/html and must stay that way.
  expect(itemBody).toContain('data-zero-source="client/components/counter.tsx"');
  expect(itemBody).toContain('data-zero-export="Counter"');
  expect(itemBody).toContain(
    '<script type="module" src="/_spacefast/islands/abc123/boot.js"></script>',
  );
  expect(itemBody).toContain("Prerendered");
  expect(itemBody).not.toContain("<!-- wp:html");
  expect(itemBody.split(SDK_TAG)).toHaveLength(2);
});
