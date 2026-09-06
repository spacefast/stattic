import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { readFileSync, mkdirSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
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
  audience: { kind: "machine"; machineId: string } | { kind: "public" };
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

/** What the gate established, as WordPress would find it. */
async function wpContext(host: string, requestPath: string, headers: Record<string, string> = {}) {
  const response = await get(runtime, host, requestPath, { headers });
  const body = await response.text();
  return {
    status: response.status,
    body,
    context: response.status === 200 ? JSON.parse(body) : null,
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
      config: {},
      theme_css:
        ":root{--sf-accent:#6d28d9;--sf-bg:#faf7ff;--sf-fg:#20182b;--sf-font:Inter,sans-serif}",
    },
    activate: {
      route_name: "production",
      config: accessConfig(true),
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
      "header('Content-Type: application/json', true);",
      "$principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;",
      "echo json_encode([",
      "  'served_by' => 'wordpress',",
      "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
      "  'role' => $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] ?? null,",
      "  'principal_kind' => is_array($principal) ? ($principal['kind'] ?? null) : null,",
      "  'actor_id' => is_array($principal) ? ($principal['actor_id'] ?? null) : null,",
      "  'themes' => defined('WP_USE_THEMES') ? WP_USE_THEMES : null,",
      "  'rest_admitted' => (bool) ($GLOBALS['SPACEFAST_CONTENT_REST_ADMITTED'] ?? false),",
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
      "function spacefast_content_model_resource($resourceId) {",
      "  foreach (spacefast_content_model_active_release()['postTypes'] as $resource) {",
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
  const admitted = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });

  expect(admitted.status).toBe(200);
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
    // The gate admitted this request, so the kernel filter serves REST.
    rest_admitted: true,
    model_revision: SERVED_MODEL_REVISION,
  });

  // The query spelling of the same lane, for a Space without pretty permalinks.
  const queryForm = await wpContext(OPEN_HOST, "/?rest_route=/wp/v2/posts", {
    "x-sf-authorization": `Bearer ${token}`,
  });
  expect(queryForm.context).toMatchObject({ served_by: "wordpress", role: "editor" });
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
    // Still marked admitted with no role: the gate admitted an anonymous
    // request, and the kernel filter serves WordPress's unauthenticated answer
    // rather than 404-ing a request the gate never refused.
    rest_admitted: true,
    model_revision: SERVED_MODEL_REVISION,
  });
});

test("an unusable credential is refused rather than downgraded", async () => {
  // Machine callers never fall back to anonymous: the whole point of presenting
  // a credential is that its failure is visible.
  const garbage = await wpContext(OPEN_HOST, "/wp-json/wp/v2/posts", {
    "x-sf-authorization": "Bearer not-a-real-credential",
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

test("/wp-admin keeps its single door", async () => {
  // The REST door is deliberately narrower than the editor lane: a credential
  // that reaches the API does not open the editor's HTML surface, which is
  // reachable only through the session its launch minted.
  const token = await machineToken();
  const response = await get(runtime, OPEN_HOST, "/wp-admin/edit.php", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  expect(response.status).toBe(401);
  expect(await response.text()).toContain("content_admin_session_invalid");
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

test("a co-hosted static Space never boots WordPress on /wp-json", async () => {
  // One wp.cloud site hosts many Spaces, so the site-wide wp-blog-header.php
  // exists for every Space here. Whether THIS Space has a REST API is answered
  // by the version's model reference, which STATIC_SPACE does not have — so
  // /wp-json is an ordinary URL it does not publish, not a
  // door into the co-hosted managed Space's kernel. It must 404 without booting
  // WordPress, even with a credential the Space itself issued.
  const anonymous = await get(runtime, STATIC_HOST, "/wp-json/wp/v2/posts");
  const anonymousBody = await anonymous.text();
  expect(anonymous.status).toBe(404);
  expect(anonymousBody).not.toContain("served_by");

  const token = await machineToken({ spaceId: STATIC_SPACE, host: STATIC_HOST });
  const credentialed = await get(runtime, STATIC_HOST, "/wp-json/wp/v2/posts", {
    headers: { "x-sf-authorization": `Bearer ${token}` },
  });
  const credentialedBody = await credentialed.text();
  expect(credentialed.status).toBe(404);
  expect(credentialedBody).not.toContain("served_by");

  // The query spelling is the same non-answer.
  const query = await get(runtime, STATIC_HOST, "/?rest_route=/wp/v2/posts");
  expect(await query.text()).not.toContain("served_by");
});

test("canonical WordPress documents share Customization styles", async () => {
  const page = await get(runtime, OPEN_HOST, "/about");
  const pageBody = await page.text();
  expect(page.status).toBe(200);
  expect(pageBody).toContain("WordPress about");
  expect(pageBody).toContain("/__spacefast_generated/theme.css");
  expect(pageBody).toContain('<script id="spacefast-sdk-js" src="/__spacefast/sdk.js"></script>');

  const collision = await get(runtime, OPEN_HOST, "/collision/");
  const collisionBody = await collision.text();
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

  expect(postBody).toContain('<script id="spacefast-sdk-js" src="/__spacefast/sdk.js"></script>');
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
  expect(itemBody).toContain('<script id="spacefast-sdk-js" src="/__spacefast/sdk.js"></script>');
});
