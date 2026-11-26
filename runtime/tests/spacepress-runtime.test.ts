// Executable runtime verification for:
// 1. Zero hot-path PHP loading of `engine/deprecated/` across static, CF-Worker,
//    and SpacePress requests (asserting real `get_included_files()` verdicts).
// 2. SpacePress core runtime on Atomic's native `$table_prefix` (preserving
//    Jetpack & WooCommerce in `active_plugins`, configuring SP_* constants and
//    filters, and never claiming `/identity/*` at root).
// 3. SpacePress PHP -> Spacefast Connectors via the `sp_connectors_call` filter.
// 4. CF Workers -> SpacePress relay (`SF-Fx-Broker: spacepress`) forwarding
//    visitor auth cookies or establishing the Space service principal.
// 5. Side-by-side serving precedence: static assets -> SpacePress -> CF Workers -> SPA/404.
// 6. Hard-deleted PHP Functions returning inline `410 php_functions_retired`.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import type { RouteInjectionPlan } from "../../packages/common/src/contracts/injection.ts";
import {
  deploy,
  get,
  PHP_BINARY,
  publicAccessConfig,
  putRoute,
  redeemHandoff,
  type Runtime,
  RUNTIME_DIR,
  RUNTIME_INSTANCE_ID,
  selfSignedCertificate,
  signEd25519Jwt,
  signToken,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

const STATIC_HOST = "static-clean.test";
const STATIC_SPACE = "spc_static_clean";
const STATIC_VERSION = "ver_static_clean_1";

const HYBRID_HOST = "spacepress-hybrid.test";
const HYBRID_SPACE = "spc_sp_hybrid";
const HYBRID_VERSION = "ver_sp_hybrid_1";

const LEGACY_PHP_HOST = "php-retired.test";
const LEGACY_PHP_SPACE = "spc_php_retired";
const LEGACY_PHP_VERSION = "ver_php_retired_1";

const PRIVATE_HOST = "spacepress-private.test";
const PRIVATE_SPACE = "spc_sp_private";
const PRIVATE_VERSION = "ver_sp_private_1";

const LEGACY_PREFIX_HOST = "spacepress-legacy-prefix.test";
const LEGACY_PREFIX_SPACE = "spc_sp_legacy_prefix";
const LEGACY_PREFIX_VERSION = "ver_sp_legacy_prefix_1";

const BUILD_HASH = "a".repeat(64);
const RUNTIME_REV = "b".repeat(64);
const APP_HASH = "c".repeat(64);

let rt: Runtime;
let tlsDir: string;
let workerServer: ReturnType<typeof Bun.serve>;
let connectorServer: ReturnType<typeof Bun.serve>;
const connectorCallBodySchema = z.object({
  surface: z.string(),
  role: z.string(),
  tool: z.string(),
  args: z.object({ repo: z.string() }),
  acceptsSharedReads: z.boolean(),
  requestId: z.string().optional(),
  visitor: z.object({
    subject: z.string(),
    email: z.string(),
  }),
});

const spRestBodySchema = z.object({
  route: z.string(),
  method: z.string(),
  query: z.union([z.record(z.string(), z.string()), z.array(z.never())]),
  body: z.string(),
  tablePrefix: z.string(),
  activePlugins: z.array(z.string()),
  apiPrefix: z.string().nullable(),
  authPrefix: z.string().nullable(),
  assetsDir: z.string().nullable(),
  assetsUrl: z.string().nullable(),
  cookie: z.union([z.record(z.string(), z.string()), z.array(z.never())]),
  authHeaderPresent: z.boolean(),
  servicePrincipal: z
    .object({
      kind: z.string(),
      subject: z.string(),
      space_id: z.string(),
      version_id: z.string(),
    })
    .nullable(),
});

const spPageBodySchema = z.object({
  surface: z.string(),
  uri: z.string(),
  tablePrefix: z.string(),
  activePlugins: z.array(z.string()),
  apiPrefix: z.string().nullable(),
  authPrefix: z.string().nullable(),
  assetsUrl: z.string().nullable(),
  principal: z
    .object({
      kind: z.string(),
      issuer: z.string(),
      subject: z.string(),
      wordpress_role: z.string(),
    })
    .nullable(),
  spaceRole: z
    .object({
      role: z.string(),
      issuer: z.string(),
      subject: z.string(),
    })
    .nullable(),
});

const connectorAnswerSchema = z.object({
  ok: z.boolean(),
  state: z.string(),
  data: z.object({
    issues: z.array(z.object({ number: z.number(), title: z.string() })),
  }),
});
const connectorResponseSchema = z.object({ connector: connectorAnswerSchema });

const directLoaderResultSchema = z.object({
  prefix: z.string(),
  table_prefix: z.string(),
  filters: z.array(z.string()),
  hasPreOptionActivePlugins: z.boolean(),
  apiPrefix: z.string(),
  authPrefix: z.string(),
  assetsUrl: z.string(),
  deprecated: z.array(z.string()),
  loaderCalls: z.number(),
});

const rootPtrSchema = z.object({ root: z.string() });

const connectorCalls: Array<{
  path: string;
  authorization: string | null;
  versionId: string | null;
  body: z.infer<typeof connectorCallBodySchema>;
}> = [];
const workerRequests: Array<{ path: string; method: string }> = [];

function writeSpacePressStub(
  storageRoot: string,
  docRoot: string,
  spaceId: string,
  versionId: string,
): void {
  const versionRoot = path.join(storageRoot, "spaces", spaceId, "versions", versionId);
  const compiled = path.join(versionRoot, ".spacepress-compiled");
  const runtimeDir = path.join(compiled, "store", "runtime", RUNTIME_REV);
  // Browser files live in the Space's private storage. The public-docroot copy
  // earlier engines kept is planted here as a stale leftover: Nginx would
  // serve it past every access rule, so the engine must remove it.
  const assetsDir = path.join(storageRoot, "spaces", spaceId, "spacepress-assets");
  const staleDocrootAssets = path.join(docRoot, "wp-content", "spacepress-assets");
  mkdirSync(runtimeDir, { recursive: true });
  mkdirSync(assetsDir, { recursive: true });
  mkdirSync(staleDocrootAssets, { recursive: true });
  writeFileSync(path.join(staleDocrootAssets, "stale.js"), "console.log('stale-docroot');\n");
  mkdirSync(path.join(docRoot, "wp-includes"), { recursive: true });

  writeFileSync(
    path.join(compiled, "manifest.json"),
    JSON.stringify({
      format: "spacepress.hosted.v1",
      hostContract: 1,
      build: BUILD_HASH,
      runtimeRevision: RUNTIME_REV,
      appHash: APP_HASH,
      wordpress: "6.0",
      pagePaths: ["/app/dashboard", "/posts/:slug"],
      routes: [{ path: "/sp-route/*rest" }, { path: "/prebuilt/*rest" }],
    }),
  );
  writeFileSync(path.join(compiled, "ready.json"), JSON.stringify({ version_id: versionId }));
  writeFileSync(path.join(compiled, "variables.json"), JSON.stringify({ APP_LABEL: "SpacePress" }));
  writeFileSync(path.join(assetsDir, "client.js"), "console.log('spacepress-asset');\n");

  // Connectors token beside the version so `sp_connectors_call` can authenticate.
  mkdirSync(path.join(versionRoot, "connectors"), { recursive: true });
  writeFileSync(path.join(versionRoot, "connectors", "token"), "sp_conn_tok_fixture");

  // Minimal SpacePress loader stub that records the options it received.
  writeFileSync(
    path.join(runtimeDir, "spacepress-loader.php"),
    `<?php
declare(strict_types=1);
namespace SpacePress\\Render;
final class Prebuilt {
    public static function prerendering(): bool {
        return ($_SERVER['HTTP_X_SPACEPRESS_PRERENDER'] ?? '') === 'valid-hmac-token';
    }
    public static function serve(): void {
        if (self::prerendering()) {
            return;
        }
        $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        if ($path === '/prebuilt/about') {
            $etag = '"prebuilt-html-etag"';
            header('X-SpacePress-Prebuilt: 1', true);
            header('Content-Type: text/html; charset=utf-8', true);
            header('ETag: ' . $etag, true);
            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                exit;
            }
            $doc = '<!doctype html><html><head><title>Prebuilt HTML</title></head><body><main>Prebuilt Body</main></body></html>';
            header('Content-Length: ' . strlen($doc), true);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                echo $doc;
            }
            exit;
        }
        if ($path === '/prebuilt/about.md') {
            $etag = '"prebuilt-md-etag"';
            header('X-SpacePress-Prebuilt: 1', true);
            header('Content-Type: text/markdown; charset=utf-8', true);
            header('ETag: ' . $etag, true);
            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                exit;
            }
            $md = "# Prebuilt Markdown\n";
            header('Content-Length: ' . strlen($md), true);
            echo $md;
            exit;
        }
    }
}
namespace SpacePress\\Loader;
final class Loader {
    public static array $loaded = [];
    public static function load(string $store, string $build, array $options = []): void {
        self::$loaded = ['store' => $store, 'build' => $build, 'options' => $options];
        if (function_exists('add_action')) {
            add_action('plugins_loaded', [\\SpacePress\\Render\\Prebuilt::class, 'serve'], PHP_INT_MAX);
        }
    }
    public static function initialize(string $store, string $build): array {
        return ['migrations' => [], 'seed' => null];
    }
}
`,
  );

  // Minimal WordPress runtime stub exercising native `$table_prefix`, intact
  // `active_plugins`, `loader/wordpress.php`, `WP_REST_Request`, and `sp_connectors_call`.
  const wpLoadPhp = `<?php
declare(strict_types=1);
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('WPINC')) define('WPINC', 'wp-includes');
if (!defined('WP_CONTENT_DIR')) define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['table_prefix'] = 'wp_atomic_';
$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_atomic_';
    public string $users = 'wp_atomic_users';
    public string $usermeta = 'wp_atomic_usermeta';
    public function set_prefix(string $p): void { $this->prefix = $p; }
};
$GLOBALS['sf_test_filters'] = [];
$GLOBALS['sf_test_active_plugins'] = ['jetpack/jetpack.php', 'woocommerce/woocommerce.php'];
if (!function_exists('__return_false')) {
    function __return_false(): bool { return false; }
    function __return_true(): bool { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable|string|array $fn, int $priority = 10, int $args = 1): bool {
        $GLOBALS['sf_test_filters'][$tag][$priority][] = [$fn, $args];
        return true;
    }
    function add_action(string $tag, callable|string|array $fn, int $priority = 10, int $args = 1): bool {
        return add_filter($tag, $fn, $priority, $args);
    }
    function remove_filter(string $tag, mixed $fn = null, int $priority = 10): bool { return true; }
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed {
        $byPriority = $GLOBALS['sf_test_filters'][$tag] ?? [];
        ksort($byPriority, SORT_NUMERIC);
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$fn, $accepted]) {
                if (!is_callable($fn)) continue;
                $callArgs = array_slice(array_merge([$value], $args), 0, max(1, $accepted));
                $value = $fn(...$callArgs);
            }
        }
        return $value;
    }
    function do_action(string $tag, mixed ...$args): void {
        $byPriority = $GLOBALS['sf_test_filters'][$tag] ?? [];
        ksort($byPriority, SORT_NUMERIC);
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$fn, $accepted]) {
                if (!is_callable($fn)) continue;
                $callArgs = array_slice($args, 0, max(0, $accepted));
                $fn(...$callArgs);
            }
        }
    }
    function did_action(string $tag): int { return 0; }
    function wp(): void {
        $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        if ($path === '/sp-route/early-html') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8', true);
            header('ETag: "sp-early-etag"', true);
            echo '<!doctype html><html><head><title>Early Route</title></head><body><main>Early HTML</main></body></html>';
            exit;
        }
        if ($path === '/sp-route/early-json') {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['route' => 'early-json'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($path === '/sp-route/clean-buffers') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header_register_callback(static function (): void {});
        }
        if ($path === '/sp-route/clean-and-replace') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8', true);
            echo '<!doctype html><html lang="discarded">';
            ob_clean();
            echo '<!doctype html><html><head><title>Replacement</title></head><body><main>Fresh</main></body></html>';
            exit;
        }
        if ($path === '/sp-route/clean-after-head') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8', true);
            echo '<!doctype html><html><head><title>First</title></head><body>first';
            ob_clean();
            echo '<!doctype html><html><head><title>Second</title></head><body><main>Second</main></body></html>';
            exit;
        }
    }
    function wp_get_current_user(): object {
        return (object) ['ID' => 42, 'user_email' => 'viewer@example.test'];
    }
    function get_option(string $name, mixed $default = false): mixed {
        if ($name === 'active_plugins') {
            return apply_filters('option_active_plugins', $GLOBALS['sf_test_active_plugins']);
        }
        return $default;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '') {}
        public function get_error_code(): string { return $this->code; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        public array $query = [];
        public array $headers = [];
        public string $body = '';
        public function __construct(public string $method, public string $route) {}
        public function get_route(): string { return $this->route; }
        public function set_query_params(array $q): void { $this->query = $q; }
        public function set_header(string $k, string $v): void { $this->headers[strtolower($k)] = $v; }
        public function set_body(string $b): void { $this->body = $b; }
    }
    class WP_REST_Response {
        public function __construct(public mixed $data, public int $status = 200, public array $headers = []) {}
        public function get_status(): int { return $this->status; }
        public function get_headers(): array { return $this->headers; }
    }
    function rest_get_server(): object {
        return new class {
            public function dispatch(WP_REST_Request $req): WP_REST_Response {
                if ($req->route === '/spacepress/v1/actions/connector-test') {
                    $conn = apply_filters('sp_connectors_call', null, 'github', 'issues.list', ['repo' => 'spacefast']);
                    return new WP_REST_Response(['connector' => $conn], 200, ['X-SpacePress-Handled' => '1']);
                }
                if ($req->route === '/spacepress/v1/actions/connector-queued') {
                    $queued = static fn (string $repo, string $jobId, int $attempt = 1): mixed => apply_filters(
                        'sp_connectors_call', null, 'github', 'issues.list', ['repo' => $repo],
                        ['mode' => 'queued', 'jobId' => $jobId, 'attempt' => $attempt]
                    );
                    $done = $queued('spacefast', 'job_7');
                    $down = $queued('down', 'job_8');
                    $waiting = $queued('approval', 'job_9');
                    $approved = $queued('approval', 'job_9', 2);
                    return new WP_REST_Response([
                        'done' => $done,
                        'retry' => $down instanceof WP_Error ? $down->get_error_code() : $down,
                        'waiting' => $waiting instanceof WP_Error ? $waiting->get_error_code() : $waiting,
                        'approved' => $approved,
                    ], 200, ['X-SpacePress-Handled' => '1']);
                }
                return new WP_REST_Response([
                    'route' => $req->route,
                    'method' => $req->method,
                    'query' => $req->query,
                    'body' => $req->body,
                    'tablePrefix' => $GLOBALS['wpdb']->prefix,
                    'activePlugins' => get_option('active_plugins'),
                    'apiPrefix' => defined('SP_API_PREFIX') ? SP_API_PREFIX : null,
                    'authPrefix' => defined('SP_AUTH_PREFIX') ? SP_AUTH_PREFIX : null,
                    'assetsDir' => defined('SP_ASSETS_DIR') ? SP_ASSETS_DIR : null,
                    'assetsUrl' => defined('SP_ASSETS_URL') ? SP_ASSETS_URL : null,
                    'cookie' => $_COOKIE,
                    'authHeaderPresent' => isset($_SERVER['HTTP_AUTHORIZATION']),
                    'servicePrincipal' => $GLOBALS['SPACEFAST_SPACEPRESS_SERVICE_PRINCIPAL'] ?? null,
                    'loaderOptions' => \\SpacePress\\Loader\\Loader::$loaded['options'] ?? null,
                ], 200, ['X-SpacePress-Handled' => '1']);
            }
            public function send_headers(array $headers): void {
                foreach ($headers as $k => $v) header($k . ': ' . $v, true);
            }
            public function response_to_data(WP_REST_Response $res, bool $embed): mixed { return $res->data; }
        };
    }
}
$muPlugin = __DIR__ . '/wp-content/mu-plugins/spacefast-content.php';
if (is_file($muPlugin)) {
    require_once $muPlugin;
}
// Simulate WordPress wp_magic_quotes() slashing $_GET before plugins_loaded / wp_loaded.
$_GET = array_map(static fn ($v) => is_string($v) ? addslashes($v) : $v, $_GET);
do_action('plugins_loaded');
`;
  writeFileSync(path.join(docRoot, "wp-load.php"), wpLoadPhp);
  writeFileSync(
    path.join(docRoot, "wp-blog-header.php"),
    `<?php
declare(strict_types=1);
require_once __DIR__ . '/wp-load.php';
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
$route = str_starts_with($path, '/wp-json') ? substr($path, strlen('/wp-json')) : $path;
$req = new WP_REST_Request(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $route);
$req->set_query_params($_GET);
$req->set_body((string) file_get_contents('php://input'));
$server = rest_get_server();
$res = $server->dispatch($req);
$server->send_headers($res->get_headers());
http_response_code($res->get_status());
header('Content-Type: application/json; charset=utf-8', true);
echo json_encode($server->response_to_data($res, false), JSON_UNESCAPED_SLASHES);
`,
  );
  writeFileSync(
    path.join(docRoot, "wp-includes", "template-loader.php"),
    `<?php
declare(strict_types=1);
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
if ($path === '/sp-route/redirect') {
    http_response_code(302);
    header('Location: /posts/hello-html', true);
    header('Content-Type: text/html; charset=utf-8', true);
    exit;
}
if (
    $path === '/posts/hello-html'
    || $path === '/posts/stream-return-html'
    || $path === '/wp-admin/new/html-screen'
    || $path === '/__spacefast/auth/html-login'
) {
    $doc = '<!doctype html><html><head><title>SpacePress HTML</title></head><body><main>Hello SpacePress</main></body></html>';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8', true);
    header('ETag: "sp-upstream-etag"', true);
    header('Last-Modified: Wed, 21 Oct 2015 07:28:00 GMT', true);
    header('Content-Length: ' . strlen($doc), true);
    if (str_starts_with($path, '/wp-admin/new/')) {
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self' https://my.spacefast.test");
    }
    echo $doc;
    if ($path !== '/posts/stream-return-html') {
        exit;
    }
    return;
}
http_response_code(200);
header('Content-Type: application/json; charset=utf-8', true);
// The admin frames itself by CSP while core still adds its own frame header
// on every admin screen, as WordPress's send_frame_options_header does.
if (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/wp-admin/new/')) {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self' https://my.spacefast.test");
}
echo json_encode([
    'surface' => 'spacepress-page',
    'uri' => $_SERVER['REQUEST_URI'] ?? '/',
    'tablePrefix' => $GLOBALS['wpdb']->prefix,
    'activePlugins' => get_option('active_plugins'),
    'apiPrefix' => defined('SP_API_PREFIX') ? SP_API_PREFIX : null,
    'authPrefix' => defined('SP_AUTH_PREFIX') ? SP_AUTH_PREFIX : null,
    'assetsUrl' => defined('SP_ASSETS_URL') ? SP_ASSETS_URL : null,
    'principal' => $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null,
    'spaceRole' => $GLOBALS['SPACEFAST_SPACE_ROLE'] ?? null,
], JSON_UNESCAPED_SLASHES);
`,
  );
}

beforeAll(async () => {
  connectorServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const url = new URL(request.url);
      const body = connectorCallBodySchema.parse(await request.json());
      connectorCalls.push({
        path: url.pathname,
        authorization: request.headers.get("authorization"),
        versionId: request.headers.get("spacefast-version-id"),
        body,
      });
      if (body.args.repo === "down") return new Response("unavailable", { status: 503 });
      // The relay replays a request id's run: pending until it is approved, then its result.
      const earlier = connectorCalls.filter((call) => call.body.requestId === body.requestId);
      if (body.args.repo === "approval" && earlier.length === 1) {
        return Response.json({
          state: { code: "approval_pending", message: "Waiting for approval." },
        });
      }
      return Response.json({
        state: { code: "ok", message: "Connected." },
        result: { ok: true, data: { issues: [{ number: 1, title: "SpacePress core" }] } },
      });
    },
  });

  tlsDir = mkdtempSync(path.join(os.tmpdir(), "sf-sp-tls-"));
  const { certificate, key: tlsKey } = selfSignedCertificate(tlsDir, "spacepress-edge");
  workerServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    tls: { cert: Bun.file(certificate), key: Bun.file(tlsKey) },
    fetch(request) {
      const url = new URL(request.url);
      workerRequests.push({ path: url.pathname, method: request.method });
      return Response.json({ surface: "cf-worker", path: url.pathname });
    },
  });

  rt = await startRuntime({
    atomicData: {
      SPACEFAST_API_BASE_URL: `http://127.0.0.1:${connectorServer.port}`,
      SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fx_dispatch_tok_test",
    },
    phpIni: { "curl.cainfo": certificate },
  });

  // 1. Pure static Space.
  await deploy(rt, {
    spaceId: STATIC_SPACE,
    versionId: STATIC_VERSION,
    files: { "index.html": "<h1>Static Home</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        { mode: "website", site_title: "Static" },
        "live_and_all_versions",
      ),
      production_hostnames: [STATIC_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // 2. Hybrid SpacePress + static assets + CF Functions Space.
  await deploy(rt, {
    spaceId: HYBRID_SPACE,
    versionId: HYBRID_VERSION,
    files: {
      "index.html": "<h1>Static Landing</h1>\n",
      "assets/app.js": "console.log('static-asset');\n",
    },
    finalize: {
      functions: {
        runtimeKind: "functions",
        host: {
          hostname: `127.0.0.1:${workerServer.port}`,
          scheme: "http",
          bundleUrl: "http://127.0.0.1/bundle.json",
          relayUrl: `http://${HYBRID_HOST}/__spacefast/functions/relay`,
          relayToken: "unused",
        },
        artifact: {
          format: "spacefast.functions.worker.v1",
          appName: "hybrid-worker",
          entry: "worker.ts",
          mainModule: "worker.js",
          compatibilityDate: "2026-01-01",
          compatibilityFlags: [],
          bundle: {
            path: "__spacefast/functions/bundles/0000/bundle.json",
            sha256: "sha256:" + "0".repeat(64),
            size: 16,
          },
          routes: [
            { method: null, path: "/worker", subtree: true },
            // WordPress owns /wp-json on a SpacePress version: this route never wins.
            { method: null, path: "/wp-json", subtree: true },
          ],
          bypassCookies: [],
          capabilities: { logging: false },
          platform: { nextCache: false },
        },
        capabilities: ["spacepress.call", "storage.read"],
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        { mode: "website", site_title: "Hybrid", zero_generation: "spacepress" },
        "live_and_all_versions",
      ),
      production_hostnames: [HYBRID_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  writeSpacePressStub(rt.storageRoot, rt.root, HYBRID_SPACE, HYBRID_VERSION);

  // 2a. A private SpacePress Space: no public grant, so every request needs access.
  await deploy(rt, {
    spaceId: PRIVATE_SPACE,
    versionId: PRIVATE_VERSION,
    files: { "index.html": "<h1>Private Landing</h1>\n" },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Private SpacePress", zero_generation: "spacepress" },
      production_hostnames: [PRIVATE_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  writeSpacePressStub(rt.storageRoot, rt.root, PRIVATE_SPACE, PRIVATE_VERSION);

  // 2b. Upgraded SpacePress (`spacepress.hosted.v1`) carrying `legacy_prefix: true`
  //     from route config through overlay and serving so `sfz_<sha>_` stays bound.
  await deploy(rt, {
    spaceId: LEGACY_PREFIX_SPACE,
    versionId: LEGACY_PREFIX_VERSION,
    files: { "index.html": "<h1>Legacy Prefix Landing</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig(
        {
          mode: "website",
          site_title: "Legacy Prefix SpacePress",
          zero_generation: "spacepress",
          legacy_prefix: true,
        },
        "live_and_all_versions",
      ),
      production_hostnames: [LEGACY_PREFIX_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  writeSpacePressStub(rt.storageRoot, rt.root, LEGACY_PREFIX_SPACE, LEGACY_PREFIX_VERSION);

  // 3. Space with a historical compiled `t=php` entry (retired PHP Functions).
  await deploy(rt, {
    spaceId: LEGACY_PHP_SPACE,
    versionId: LEGACY_PHP_VERSION,
    files: {
      "index.html": "<h1>Retired PHP Home</h1>\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Retired PHP" }),
      production_hostnames: [LEGACY_PHP_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  // Inject a historical `t=php` response-table entry as older finalized versions carry.
  const legacyVersionDir = path.join(
    rt.storageRoot,
    "spaces",
    LEGACY_PHP_SPACE,
    "versions",
    LEGACY_PHP_VERSION,
  );
  const rootPtr = rootPtrSchema.parse(
    JSON.parse(readFileSync(path.join(legacyVersionDir, "root.json"), "utf8")),
  );
  const tableRel = spawnSync(
    PHP_BINARY,
    [
      "-r",
      `$r = require $argv[1]; echo $r['tables']['*'] ?? '';`,
      path.join(legacyVersionDir, rootPtr.root),
    ],
    { encoding: "utf8" },
  ).stdout.trim();
  const tablePath = path.join(legacyVersionDir, tableRel);
  const tablePhp = readFileSync(tablePath, "utf8");
  writeFileSync(
    tablePath,
    tablePhp.replace(/return \[/, "return [\n  '/hello' => ['a' => ['t' => 'php']],\n"),
  );
});

afterAll(() => {
  rt?.stop();
  workerServer?.stop(true);
  connectorServer?.stop(true);
  if (tlsDir) rmSync(tlsDir, { recursive: true, force: true });
});

const probeHeaders = { "x-spacefast-test-included-files": "1" };

test("static, CF Worker, and SpacePress hot-path requests load ZERO files under engine/deprecated/", async () => {
  // Static hit and static 404.
  const staticHome = await get(rt, STATIC_HOST, "/", { headers: probeHeaders });
  expect(staticHome.status).toBe(200);
  expect(staticHome.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");

  const static404 = await get(rt, STATIC_HOST, "/no-such-page", { headers: probeHeaders });
  expect(static404.status).toBe(404);
  expect(static404.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");

  // Health probe with ?v=.
  const health = await get(rt, STATIC_HOST, `/__spacefast/health.php?v=${STATIC_VERSION}`, {
    headers: probeHeaders,
  });
  expect(health.status).toBe(200);
  expect(health.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");

  // Hybrid Space: static asset wins first.
  const staticAsset = await get(rt, HYBRID_HOST, "/assets/app.js", { headers: probeHeaders });
  expect(staticAsset.status).toBe(200);
  expect(await staticAsset.text()).toContain("static-asset");
  expect(staticAsset.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");

  // SpacePress REST API (`/wp-json/spacepress/v1/*`) preserves native $table_prefix and plugins.
  const spRest = await get(rt, HYBRID_HOST, "/wp-json/spacepress/v1/actions/ping", {
    headers: probeHeaders,
  });
  expect(spRest.status, await spRest.clone().text()).toBe(200);
  expect(spRest.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  const spRestBody = spRestBodySchema.parse(await spRest.json());
  expect(spRestBody.route).toBe("/spacepress/v1/actions/ping");
  expect(spRestBody.tablePrefix).toBe("wp_atomic_");
  expect(spRestBody.activePlugins).toEqual(["jetpack/jetpack.php", "woocommerce/woocommerce.php"]);
  expect(spRestBody.apiPrefix).toBe("/wp-json/spacepress/v1");
  expect(spRestBody.authPrefix).toBe("/__spacefast/auth");
  expect(spRestBody.assetsUrl).toBe("/wp-content/spacepress-assets");
  expect(spRestBody.assetsDir).toBe(
    path.join(rt.storageRoot, "spaces", HYBRID_SPACE, "spacepress-assets"),
  );
  // Booting SpacePress removed the public-docroot copy Nginx would serve unchecked.
  expect(existsSync(path.join(rt.root, "wp-content", "spacepress-assets"))).toBe(false);

  // SpacePress auth prefix (`/__spacefast/auth/*`), admin (`/wp-admin/new/*`), and declared page.
  for (const spPath of ["/__spacefast/auth/login", "/wp-admin/new/posts", "/app/dashboard"]) {
    const res = await get(rt, HYBRID_HOST, spPath, { headers: probeHeaders });
    expect(res.status, `${spPath}: ${await res.clone().text()}`).toBe(200);
    expect(res.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
    const json = spPageBodySchema.parse(await res.json());
    expect(json.surface).toBe("spacepress-page");
    expect(json.tablePrefix).toBe("wp_atomic_");
    expect(json.activePlugins).toEqual(["jetpack/jetpack.php", "woocommerce/woocommerce.php"]);
    // frame-ancestors decides framing; a second, conflicting X-Frame-Options never leaves.
    if (spPath.startsWith("/wp-admin/new/")) {
      expect(res.headers.get("content-security-policy")).toContain("frame-ancestors 'self'");
      expect(res.headers.get("x-frame-options")).toBeNull();
    } else {
      expect(res.headers.get("content-security-policy")).toBeNull();
    }
  }

  // SpacePress asset under `/wp-content/spacepress-assets/*`.
  const spAsset = await get(rt, HYBRID_HOST, "/wp-content/spacepress-assets/client.js", {
    headers: probeHeaders,
  });
  expect(spAsset.status).toBe(200);
  expect(await spAsset.text()).toContain("spacepress-asset");
  expect(spAsset.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  // A private Space's SpacePress asset answers only after its access check.
  const privateAsset = await get(rt, PRIVATE_HOST, "/wp-content/spacepress-assets/client.js");
  expect(privateAsset.status).not.toBe(200);
  expect(await privateAsset.text()).not.toContain("spacepress-asset");
  // The retired docroot copy is never an asset source.
  const staleAsset = await get(rt, HYBRID_HOST, "/wp-content/spacepress-assets/stale.js", {
    headers: probeHeaders,
  });
  expect(staleAsset.status).toBe(404);

  // CF Worker route runs side-by-side after SpacePress with zero deprecated includes.
  const workerRes = await get(rt, HYBRID_HOST, "/worker/hello", { headers: probeHeaders });
  expect(workerRes.status, await workerRes.clone().text()).toBe(200);
  expect(workerRes.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  expect(await workerRes.json()).toEqual({ surface: "cf-worker", path: "/worker/hello" });
  expect(workerRequests).toContainEqual({ path: "/worker/hello", method: "GET" });

  // A worker route never takes WordPress's REST API from a SpacePress version:
  // the app API and core's ability run route, which the generated client uses.
  // WordPress answers each one: it dispatches the route below `/wp-json`.
  for (const restPath of [
    "/wp-json",
    "/wp-json/spacepress/v1/actions/ping",
    "/wp-json/wp-abilities/v1/abilities/hybrid/ping/run",
  ]) {
    const res = await get(rt, HYBRID_HOST, restPath, {
      method: "POST",
      headers: probeHeaders,
      body: "{}",
    });
    expect(res.status, `${restPath}: ${await res.clone().text()}`).toBe(200);
    expect(res.headers.get("cache-control"), restPath).toBe("private, no-store");
    expect(spRestBodySchema.parse(await res.json()).route, restPath).toBe(
      restPath.slice("/wp-json".length),
    );
  }
  expect(workerRequests.filter((r) => r.path.startsWith("/wp-json"))).toEqual([]);

  // Retired PHP Functions return inline 410 `php_functions_retired` without loading deprecated files.
  const retired = await get(rt, LEGACY_PHP_HOST, "/hello", { headers: probeHeaders });
  expect(retired.status).toBe(410);
  expect(retired.headers.get("cache-control")).toBe("no-store");
  expect(retired.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  expect(await retired.json()).toMatchObject({ status: 410, code: "php_functions_retired" });
});

test("SpacePress sp_connectors_call filter calls Spacefast connector relay with viewer subject and credential", async () => {
  connectorCalls.length = 0;
  const res = await get(rt, HYBRID_HOST, "/wp-json/spacepress/v1/actions/connector-test", {
    headers: probeHeaders,
  });
  expect(res.status, await res.clone().text()).toBe(200);
  expect(res.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  const body = connectorResponseSchema.parse(await res.json());
  expect(body.connector).toEqual({
    ok: true,
    state: "ok",
    data: { issues: [{ number: 1, title: "SpacePress core" }] },
  });
  expect(connectorCalls).toHaveLength(1);
  expect(connectorCalls[0]?.path).toBe("/v1/runtime/connectors/calls");
  expect(connectorCalls[0]?.authorization).toBe("Bearer sp_conn_tok_fixture");
  expect(connectorCalls[0]?.body).toMatchObject({
    surface: "zero",
    role: "github",
    tool: "issues.list",
    args: { repo: "spacefast" },
    acceptsSharedReads: true,
    visitor: {
      subject: `wp:${HYBRID_SPACE}:42`,
      email: "viewer@example.test",
    },
  });
});

test("a queued SpacePress connector call replays by job id and asks for a retry while the relay is down or approval is pending", async () => {
  connectorCalls.length = 0;
  const res = await get(rt, HYBRID_HOST, "/wp-json/spacepress/v1/actions/connector-queued", {
    headers: probeHeaders,
  });
  expect(res.status, await res.clone().text()).toBe(200);
  const body = z
    .object({
      done: connectorAnswerSchema,
      retry: z.string(),
      waiting: z.string(),
      approved: connectorAnswerSchema,
    })
    .parse(await res.json());
  expect(body.done).toEqual({
    ok: true,
    state: "ok",
    data: { issues: [{ number: 1, title: "SpacePress core" }] },
  });
  expect(body.retry).toBe("spacefast_connector_unavailable");
  expect(body.waiting).toBe("spacefast_connector_approval_pending");
  // The retry after approval sends the same request id and gets the run's result.
  expect(body.approved).toEqual(body.done);
  expect(connectorCalls.map((call) => call.body.requestId)).toEqual([
    "spacepress-job:job_7",
    "spacepress-job:job_8",
    "spacepress-job:job_9",
    "spacepress-job:job_9",
  ]);
});

test("CF Workers relay spacepress broker forwards visitor cookies or Space service principal with zero deprecated includes", async () => {
  const relayToken = signToken({
    aud: "spacefast-functions-relay",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: HYBRID_SPACE,
    version_id: HYBRID_VERSION,
    ownership_revision: null,
    capabilities: ["spacepress.call"],
  });

  // 1. Service principal when no visitor cookie is forwarded.
  const serviceCall = await fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "POST",
    headers: {
      host: HYBRID_HOST,
      authorization: `Bearer ${relayToken}`,
      "sf-fx-broker": "spacepress",
      "content-type": "application/json",
      ...probeHeaders,
    },
    body: JSON.stringify({
      method: "POST",
      path: "/wp-json/spacepress/v1/actions/ping?from=worker",
      body: { hello: "world" },
    }),
  });
  expect(serviceCall.status, await serviceCall.clone().text()).toBe(200);
  expect(serviceCall.headers.get("cache-control")).toBe("no-store");
  expect(serviceCall.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  const serviceBody = spRestBodySchema.parse(await serviceCall.json());
  expect(serviceBody.route).toBe("/spacepress/v1/actions/ping");
  expect(serviceBody.method).toBe("POST");
  expect(serviceBody.query).toEqual({ from: "worker" });
  expect(serviceBody.body).toBe('{"hello":"world"}');
  expect(serviceBody.authHeaderPresent).toBe(false);
  expect(serviceBody.servicePrincipal).toMatchObject({
    kind: "service",
    subject: `service:${HYBRID_SPACE}`,
    space_id: HYBRID_SPACE,
    version_id: HYBRID_VERSION,
  });

  // 2. Visitor auth cookies forwarded (platform admin cookie stripped, no service principal).
  const visitorCall = await fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "POST",
    headers: {
      host: HYBRID_HOST,
      authorization: `Bearer ${relayToken}`,
      "sf-fx-broker": "spacepress",
      "content-type": "application/json",
      ...probeHeaders,
    },
    body: JSON.stringify({
      method: "GET",
      path: "/wp-json/spacepress/v1/session",
      headers: {
        cookie: "wordpress_logged_in_abc=visitor_cookie; __Host-spacefast_content_admin=strip_me",
      },
    }),
  });
  expect(visitorCall.status, await visitorCall.clone().text()).toBe(200);
  expect(visitorCall.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
  const visitorBody = spRestBodySchema.parse(await visitorCall.json());
  expect(visitorBody.cookie).toEqual({ wordpress_logged_in_abc: "visitor_cookie" });
  expect(visitorBody.servicePrincipal).toBeNull();

  // 3. Routes outside SpacePress / Abilities REST namespaces are refused.
  const forbidden = await fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "POST",
    headers: {
      host: HYBRID_HOST,
      authorization: `Bearer ${relayToken}`,
      "sf-fx-broker": "spacepress",
      "content-type": "application/json",
    },
    body: JSON.stringify({ method: "GET", path: "/wp-json/wp/v2/users" }),
  });
  expect(forbidden.status).toBe(403);
  expect(await forbidden.json()).toMatchObject({ code: "spacepress_route_forbidden" });
});

test("direct WordPress loader for SpacePress keeps native $table_prefix and zero deprecated includes", () => {
  const work = mkdtempSync(path.join(os.tmpdir(), "sf-sp-direct-"));
  try {
    const compiled = path.join(work, "compiled");
    const runtimeDir = path.join(compiled, "store", "runtime", RUNTIME_REV);
    mkdirSync(runtimeDir, { recursive: true });
    writeFileSync(
      path.join(compiled, "manifest.json"),
      JSON.stringify({
        format: "spacepress.hosted.v1",
        hostContract: 1,
        build: BUILD_HASH,
        runtimeRevision: RUNTIME_REV,
        appHash: APP_HASH,
      }),
    );
    writeFileSync(
      path.join(runtimeDir, "spacepress-loader.php"),
      `<?php
namespace SpacePress\\Loader;
final class Loader {
    public static array $calls = [];
    public static function load(string $store, string $build, array $options = []): void {
        self::$calls[] = compact('store', 'build', 'options');
    }
}
`,
    );

    const php = `
$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_native_';
    public string $users = 'wp_native_users';
    public string $usermeta = 'wp_native_usermeta';
    public function set_prefix(string $p): void { $this->prefix = $p; }
};
$GLOBALS['table_prefix'] = 'wp_native_';
$GLOBALS['filters'] = [];
function __return_false(): bool { return false; }
function __return_true(): bool { return true; }
function add_filter(string $tag, callable|string $fn, int $p = 10, int $a = 1): bool { $GLOBALS['filters'][$tag][] = $fn; return true; }
function add_action(string $tag, callable|string $fn, int $p = 10, int $a = 1): bool { return add_filter($tag, $fn, $p, $a); }
$GLOBALS['SPACEFAST_SPACEPRESS_ROOT'] = ${JSON.stringify(compiled)};
$GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT'] = [
    'private_root' => ${JSON.stringify(path.join(work, ".stattic/storage"))},
    'space_id' => 'spc_direct',
    'version_id' => 'ver_direct',
    'version_root' => ${JSON.stringify(work)},
    'compiled_root' => ${JSON.stringify(compiled)},
    'serving' => [],
    'initializing' => false,
];
$loader = require ${JSON.stringify(path.join(RUNTIME_DIR, "engine/loader/wordpress.php"))};
$loader([
    'public_root' => ${JSON.stringify(work)},
    'install_root' => ${JSON.stringify(path.join(work, ".stattic"))},
    'engine_root' => ${JSON.stringify(RUNTIME_DIR)},
    'native_binary' => '',
    'engine_source' => 'site',
    'engine_source_reason' => null,
    'chroot_revision' => null,
    'loader_version' => 1,
]);
$deprecated = array_values(array_filter(
    get_included_files(),
    static fn (string $f): bool => str_contains(str_replace('\\\\', '/', $f), '/engine/deprecated/')
));
echo json_encode([
    'prefix' => $GLOBALS['wpdb']->prefix,
    'table_prefix' => $GLOBALS['table_prefix'],
    'filters' => array_keys($GLOBALS['filters']),
    'hasPreOptionActivePlugins' => isset($GLOBALS['filters']['pre_option_active_plugins']),
    'apiPrefix' => SP_API_PREFIX,
    'authPrefix' => SP_AUTH_PREFIX,
    'assetsUrl' => SP_ASSETS_URL,
    'deprecated' => $deprecated,
    'loaderCalls' => count(\\SpacePress\\Loader\\Loader::$calls),
]);
`;
    const run = spawnSync(PHP_BINARY, ["-r", php], { encoding: "utf8" });
    expect(run.status, run.stderr).toBe(0);
    const result = directLoaderResultSchema.parse(JSON.parse(run.stdout));
    expect(result.prefix).toBe("wp_native_");
    expect(result.table_prefix).toBe("wp_native_");
    expect(result.hasPreOptionActivePlugins).toBe(false);
    expect(result.apiPrefix).toBe("/wp-json/spacepress/v1");
    expect(result.authPrefix).toBe("/__spacefast/auth");
    expect(result.assetsUrl).toBe("/wp-content/spacepress-assets");
    expect(result.deprecated).toEqual([]);
    expect(result.loaderCalls).toBe(1);
    expect(result.filters).toContain("sp_connectors_call");
    expect(result.filters).toContain("sp_mail_transport");
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test("legacy_prefix route config survives overlay and binds sfz_<sha>_ for spacepress.hosted.v1 requests and worker", async () => {
  const expectedPrefix = `sfz_${createHash("sha256").update(LEGACY_PREFIX_SPACE).digest("hex").slice(0, 24)}_`;
  const res = await get(rt, LEGACY_PREFIX_HOST, "/wp-json/spacepress/v1/actions/ping");
  expect(res.status, await res.clone().text()).toBe(200);
  const body = spRestBodySchema.parse(await res.json());
  expect(body.tablePrefix).toBe(expectedPrefix);

  const workerTargetSchema = z.object({
    runtime: z.string(),
    version_id: z.string(),
    legacy_prefix: z.boolean(),
    legacy_typedpress: z.boolean(),
  });
  const php = `
require_once ${JSON.stringify(path.join(RUNTIME_DIR, "engine/deprecated/zero-v2/zero-v2-worker.php"))};
echo json_encode(_stattic_zero_worker_target(${JSON.stringify(rt.storageRoot)}, ${JSON.stringify(LEGACY_PREFIX_SPACE)}, 'production'));
`;
  const run = spawnSync(PHP_BINARY, ["-r", php], { encoding: "utf8" });
  expect(run.status, run.stderr).toBe(0);
  const target = workerTargetSchema.parse(JSON.parse(run.stdout));
  expect(target).toEqual({
    runtime: "spacepress",
    version_id: LEGACY_PREFIX_VERSION,
    legacy_prefix: true,
    legacy_typedpress: false,
  });
});

test("signed-in requests to SpacePress /wp-admin/new and sub-screens resolve the visitor principal and Space role", async () => {
  const keys = generateKeyPairSync("ed25519");
  const issuer = visitorIssuer(keys.publicKey);
  const authIssuer = `http://127.0.0.1:${connectorServer.port}/v1/auth`;
  const authority = `external:${createHash("sha256").update("spacefast-share\0sp_admin").digest("hex")}`;
  await putRoute(rt, PRIVATE_SPACE, "production", {
    version_id: PRIVATE_VERSION,
    hostnames: [PRIVATE_HOST],
    production_hostnames: [PRIVATE_HOST],
    config: {
      mode: "website",
      site_title: "Private SpacePress",
      zero_generation: "spacepress",
      projection_generation: 1,
      visitor_issuer: "spacefast-api",
      visitor_jwks: {
        keys: [
          {
            ...keys.publicKey.export({ format: "jwk" }),
            kid: issuer.kid,
            alg: "EdDSA",
            use: "sig",
          },
        ],
      },
      authorization: {
        generation: 1,
        sessionVersion: 0,
        sessionKey: "e".repeat(64),
        fence: "none",
        acquireUrl: "https://access.spacefast.test/acquire/sp-private",
        spaceClaimed: true,
        accessPage: {
          displayName: "Private SpacePress",
          accountUrl: `${authIssuer}/oauth2/authorize`,
          issuer: authIssuer,
          connections: [],
          exchange: {
            passwordUrl: "https://access.spacefast.test/acquire/sp-private/password",
            tokenUrl: "https://access.spacefast.test/acquire/sp-private/token",
            requestUrl: "https://access.spacefast.test/acquire/sp-private/request",
            credential: "spacepress-private-exchange-credential-0123456789",
          },
        },
        grants: [
          {
            id: "grt_sp_admin",
            generation: 1,
            audience: { kind: "external", issuer: "spacefast-share", subject: "sp_admin" },
            resources: { include: ["/**"], exclude: [] },
            capabilities: [
              "page.view",
              "comments.read",
              "comments.write",
              "content.publish",
              "access.manage",
            ],
            constraints: {},
            target: { kind: "live" },
            source: { kind: "system", reference: "share-consent:sp_admin:grt_sp_admin" },
          },
        ],
      },
    },
  });

  // Anonymous requests to private SpacePress admin screens remain denied.
  const anonymous = await get(rt, PRIVATE_HOST, "/wp-admin/new/screens/todo-admin-screen");
  expect(anonymous.status).toBe(403);

  const now = Math.floor(Date.now() / 1000);
  const handoff = signEd25519Jwt(keys.privateKey, issuer.kid, {
    sub: authority,
    purpose: "handoff",
    authorities: [authority],
    principal: "account:sp_admin",
    identity: { issuer: authIssuer, subject: "sp_admin" },
    iss: "spacefast-api",
    aud: PRIVATE_SPACE,
    host: PRIVATE_HOST,
    spaceId: PRIVATE_SPACE,
    generation: 1,
    sessionVersion: 0,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    iat: now,
    nbf: now,
    exp: now + 300,
    jti: randomUUID(),
  });
  const signIn = await redeemHandoff(rt, PRIVATE_HOST, handoff, "/wp-admin/new");
  expect(signIn.status, await signIn.clone().text()).toBe(303);
  const cookie = (signIn.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
  expect(cookie).toStartWith("spacefast_session_dev=sfv2_");

  // Both `/wp-admin/new` and nested screens stay in the SpacePress lane while
  // resolving `_stattic_wordpress_role_for_capabilities()` through the real loader.
  for (const adminPath of ["/wp-admin/new", "/wp-admin/new/screens/todo-admin-screen"]) {
    // oxlint-disable-next-line no-await-in-loop -- sequential requests verify each admin route
    const res = await get(rt, PRIVATE_HOST, adminPath, {
      headers: { cookie, ...probeHeaders },
    });
    expect(res.status, `${adminPath}: ${await res.clone().text()}\n${rt.diagnostics()}`).toBe(200);
    expect(res.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
    expect(res.headers.get("cache-control")).toBe("private, no-store");
    expect(res.headers.get("a8c-edge-cache")).toBe("no-cache");
    if (adminPath.startsWith("/wp-admin/new/")) {
      expect(res.headers.get("content-security-policy")).toContain("frame-ancestors 'self'");
      expect(res.headers.get("x-frame-options")).toBeNull();
    }
    const body = spPageBodySchema.parse(await res.json());
    expect(body.surface).toBe("spacepress-page");
    expect(body.uri).toBe(adminPath);
    expect(body.principal).toEqual({
      kind: "user",
      issuer: authIssuer,
      subject: "sp_admin",
      wordpress_role: "subscriber",
    });
    expect(body.spaceRole).toEqual({
      role: "manager",
      issuer: authIssuer,
      subject: "sp_admin",
    });
  }

  // `rest_route` on `/wp-admin/new*` must still pass through the WordPress REST
  // door rather than bypassing origin and `_method` checks into SpacePress.
  for (const restAttackPath of [
    "/wp-admin/new/?rest_route=/wp/v2/posts/1&_method=DELETE",
    "/wp-admin/new?rest_route=/wp/v2/posts/1&_method=DELETE",
  ]) {
    // oxlint-disable-next-line no-await-in-loop -- sequential requests verify each selector spelling
    const restRes = await get(rt, PRIVATE_HOST, restAttackPath, {
      headers: {
        cookie,
        "sec-fetch-site": "cross-site",
      },
    });
    expect(restRes.status, `${restAttackPath}: ${await restRes.clone().text()}`).toBe(403);
    expect(await restRes.json()).toMatchObject({ code: "rest_origin_denied" });
  }

  // A cross-origin unsafe request to `/wp-admin/new/*` must never run with the
  // visitor's lent principal or Space role.
  const crossOriginPost = await get(rt, PRIVATE_HOST, "/wp-admin/new/screens/todo-admin-screen", {
    method: "POST",
    headers: {
      cookie,
      origin: "https://attacker.example",
      "sec-fetch-site": "cross-site",
    },
  });
  expect(crossOriginPost.status).not.toBe(500);
  if (crossOriginPost.status === 200) {
    const postBody = spPageBodySchema.parse(await crossOriginPost.json());
    expect(postBody.principal).toBeNull();
    expect(postBody.spaceRole).toBeNull();
  } else {
    expect(crossOriginPost.status).toBe(403);
  }
});

test("SpacePress HTML pages inject Spacefast SDK and snippets, honor injection plans, and exclude admin/auth/REST/non-HTML", async () => {
  const host = "spacepress-html-inject.test";
  const spaceId = "spc_sp_html_inject";
  const versionOn = "ver_sp_html_inject_on";
  const versionFileOff = "ver_sp_html_inject_file_off";
  const bakedSdk = '<script async src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  const engineSdk = '<script defer src="/__spacefast/sdk.js" data-spacefast-sdk="v1"></script>';
  const staticGa =
    '<!--spacefast-static:ga:head:start--><script async src="https://www.googletagmanager.com/gtag/js?id=G-SP"></script><!--spacefast-static:ga:head:end-->';
  const authorHead = '<meta name="author-head" content="sp-kept">';
  const authorNoscript = '<noscript><img src="/author-sp-ns.gif"></noscript>';
  const authorBodyStart = '<div id="author-sp-start"></div>';
  const authorBodyEnd = "<script>window.authorSpEnd=1;</script>";
  type SpacePressSdkSection = {
    revision: string;
    body?: string;
    injection?: boolean;
    plan?: RouteInjectionPlan;
    config: {
      tracker?: { src: string; endpoint: string; key: string };
      comments?: { live: boolean; preview: boolean; ui: string; features: Record<string, never> };
    };
  };
  const sdkOn: SpacePressSdkSection = {
    revision: "sdk-sp-1",
    body: "window.__spTagGraph=1;",
    config: {
      tracker: {
        src: "/__spacefast/t.js",
        endpoint: "https://beacon.example.test",
        key: "pk_sp_html",
      },
      comments: { live: true, preview: true, ui: "default", features: {} },
    },
  };
  const routeFor = (versionId: string, sdk: SpacePressSdkSection) => ({
    version_id: versionId,
    config: {
      ...publicAccessConfig(
        { mode: "website", site_title: "SP Inject", zero_generation: "spacepress" },
        "live_and_all_versions",
      ),
      sdk,
    },
    production_hostnames: [host],
    noindex_production_hostnames: [],
    version_hostnames: [],
  });

  await deploy(rt, {
    spaceId,
    versionId: versionOn,
    files: { "index.html": "<h1>Static Home</h1>\n" },
    serving: {
      config: {
        inject: {
          head: [authorHead, staticGa],
          noscript: [authorNoscript],
          bodyStart: [authorBodyStart],
          bodyEnd: [authorBodyEnd, bakedSdk],
        },
      },
    },
    activate: { route_name: "production", ...routeFor(versionOn, sdkOn) },
  });
  writeSpacePressStub(rt.storageRoot, rt.root, spaceId, versionOn);

  // 1. Both template-loader HTML pages (exit and normal return) and early
  //    do_parse_request HTML routes receive the one SDK tag and all snippets.
  for (const htmlPath of [
    "/posts/hello-html",
    "/posts/stream-return-html",
    "/sp-route/early-html",
  ]) {
    // oxlint-disable-next-line no-await-in-loop -- verify each SpacePress HTML response shape sequentially
    const res = await get(rt, host, htmlPath, { headers: probeHeaders });
    const html = await res.text();
    expect(res.status, `${htmlPath}: ${html}`).toBe(200);
    expect(res.headers.get("x-spacefast-test-deprecated-includes")).toBe("none");
    expect(res.headers.get("origin-agent-cluster")).toBe("?1");
    expect(res.headers.get("permissions-policy")).toBe("document-domain=()");
    expect(res.headers.get("etag")).toBeNull();
    expect(res.headers.get("last-modified")).toBeNull();
    expect(res.headers.get("content-length")).toBeNull();
    expect(html).toContain(`<head>${engineSdk}${authorHead}${staticGa}`);
    expect(html.split("data-spacefast-sdk").length - 1).toBe(1);
    expect(html).toContain(`<body>${authorNoscript}${authorBodyStart}`);
    expect(html).toContain(`${authorBodyEnd}</body>`);
  }

  // 2. Tag preview query parameter propagates raw (unslashed despite wp_magic_quotes)
  //    to the injected SDK script URL and opts out of shared/edge caching.
  const previewRes = await get(
    rt,
    host,
    "/posts/hello-html?spacefast_tag_preview=tok_sp%27preview",
    { headers: probeHeaders },
  );
  expect(previewRes.status).toBe(200);
  expect(previewRes.headers.get("cache-control")).toBe("private, no-store");
  expect(previewRes.headers.get("a8c-edge-cache")).toBe("no-cache");
  const previewHtml = await previewRes.text();
  expect(previewHtml).toContain(
    '<script defer src="/__spacefast/sdk.js?preview=tok_sp%27preview" data-spacefast-sdk="v1"></script>',
  );
  expect(previewHtml.split("data-spacefast-sdk").length - 1).toBe(1);

  // 2b. Prebuilt HTML pages served on plugins_loaded receive live SDK/snippet
  //     injection and withhold stale 304 validators, while prebuilt Markdown
  //     twins stay untouched and retain 304 validation.
  const prebuiltHtmlRes = await get(rt, host, "/prebuilt/about", {
    headers: {
      ...probeHeaders,
      accept: "text/html",
      "if-none-match": '"prebuilt-html-etag"',
    },
  });
  expect(prebuiltHtmlRes.status).toBe(200);
  expect(prebuiltHtmlRes.headers.get("x-spacepress-prebuilt")).toBe("1");
  expect(prebuiltHtmlRes.headers.get("etag")).toBeNull();
  expect(prebuiltHtmlRes.headers.get("content-length")).toBeNull();
  expect(prebuiltHtmlRes.headers.get("origin-agent-cluster")).toBe("?1");
  expect(prebuiltHtmlRes.headers.get("permissions-policy")).toBe("document-domain=()");
  const prebuiltHtml = await prebuiltHtmlRes.text();
  expect(prebuiltHtml).toContain(`<head>${engineSdk}${authorHead}${staticGa}`);
  expect(prebuiltHtml).toContain(`<body>${authorNoscript}${authorBodyStart}`);
  expect(prebuiltHtml).toContain(`${authorBodyEnd}</body>`);

  const prebuiltMd = await get(rt, host, "/prebuilt/about.md", {
    headers: { ...probeHeaders, accept: "text/markdown" },
  });
  expect(prebuiltMd.status).toBe(200);
  expect(await prebuiltMd.text()).toBe("# Prebuilt Markdown\n");
  const prebuiltMd304 = await get(rt, host, "/prebuilt/about.md", {
    headers: {
      ...probeHeaders,
      accept: "text/markdown",
      "if-none-match": '"prebuilt-md-etag"',
    },
  });
  expect(prebuiltMd304.status).toBe(304);
  // Browser-style navigation to a Markdown twin (Sec-Fetch-Dest: document + Accept: text/html)
  // must also retain its 304 validator.
  const prebuiltMdBrowser304 = await get(rt, host, "/prebuilt/about.md", {
    headers: {
      ...probeHeaders,
      accept: "text/markdown, text/html;q=0.9",
      "sec-fetch-dest": "document",
      "if-none-match": '"prebuilt-md-etag"',
    },
  });
  expect(prebuiltMdBrowser304.status).toBe(304);

  // HEAD on prebuilt HTML agrees with injected GET: withholds stale 304 validators
  // and drops un-injected ETag / Last-Modified / Content-Length headers.
  const prebuiltHead = await get(rt, host, "/prebuilt/about", {
    method: "HEAD",
    headers: {
      ...probeHeaders,
      accept: "text/html",
      "if-none-match": '"prebuilt-html-etag"',
    },
  });
  expect(prebuiltHead.status).toBe(200);
  expect(prebuiltHead.headers.get("etag")).toBeNull();
  expect(prebuiltHead.headers.get("last-modified")).toBeNull();
  expect(prebuiltHead.headers.get("content-length")).toBeNull();
  expect(await prebuiltHead.text()).toBe("");

  // 2c. Verified SpacePress prerender loopbacks stay un-injected (so prebuilt
  //     files written to disk never bake in runtime SDK/snippets), whereas a
  //     forged prerender header still gets injection.
  const prerenderHtml = await (
    await get(rt, host, "/posts/hello-html", {
      headers: { ...probeHeaders, "x-spacepress-prerender": "valid-hmac-token" },
    })
  ).text();
  expect(prerenderHtml).toContain("<title>SpacePress HTML</title>");
  expect(prerenderHtml).not.toContain("data-spacefast-sdk");
  expect(prerenderHtml).not.toContain("spacefast-static:");
  expect(prerenderHtml).not.toContain("author-head");

  const forgedPrerenderHtml = await (
    await get(rt, host, "/posts/hello-html", {
      headers: { ...probeHeaders, "x-spacepress-prerender": "forged-token" },
    })
  ).text();
  expect(forgedPrerenderHtml).toContain(`<head>${engineSdk}${authorHead}${staticGa}`);

  // 2d. Code that clears output buffers and replaces the header callback inside
  //     wp() still gets document isolation headers after wp() returns, and a
  //     non-final ob_clean() resets the HTML insertion filter before head emission.
  const cleanBuffersRes = await get(rt, host, "/sp-route/clean-buffers", {
    headers: probeHeaders,
  });
  expect(cleanBuffersRes.status).toBe(200);
  expect(cleanBuffersRes.headers.get("origin-agent-cluster")).toBe("?1");
  expect(cleanBuffersRes.headers.get("permissions-policy")).toBe("document-domain=()");
  expect(spPageBodySchema.parse(await cleanBuffersRes.json()).surface).toBe("spacepress-page");

  const cleanReplaceRes = await get(rt, host, "/sp-route/clean-and-replace", {
    headers: probeHeaders,
  });
  expect(cleanReplaceRes.status).toBe(200);
  const cleanReplaceHtml = await cleanReplaceRes.text();
  expect(cleanReplaceHtml).not.toContain('lang="discarded"');
  expect(cleanReplaceHtml).toContain(
    `<head>${engineSdk}${authorHead}${staticGa}<title>Replacement</title>`,
  );
  expect(cleanReplaceHtml.split("data-spacefast-sdk").length - 1).toBe(1);
  expect(cleanReplaceHtml).toContain(`<body>${authorNoscript}${authorBodyStart}`);
  expect(cleanReplaceHtml).toContain(`${authorBodyEnd}</body>`);

  // Once the head has already been emitted (chunk_size 1), a later ob_clean()
  // cannot take those bytes back and must not inject the SDK or snippets twice.
  const cleanAfterHeadRes = await get(rt, host, "/sp-route/clean-after-head", {
    headers: probeHeaders,
  });
  expect(cleanAfterHeadRes.status).toBe(200);
  const cleanAfterHeadHtml = await cleanAfterHeadRes.text();
  expect(cleanAfterHeadHtml.split("data-spacefast-sdk").length - 1).toBe(1);
  expect(cleanAfterHeadHtml.split(authorHead).length - 1).toBe(1);
  expect(cleanAfterHeadHtml.split(authorBodyStart).length - 1).toBe(1);
  expect(cleanAfterHeadHtml.split(authorBodyEnd).length - 1).toBe(1);
  expect(cleanAfterHeadHtml).toContain(`${authorBodyEnd}</body>`);

  // 3. Admin, reserved auth, REST, non-HTML, HEAD, and empty redirects are excluded.
  for (const excludedPath of ["/wp-admin/new/html-screen", "/__spacefast/auth/html-login"]) {
    // oxlint-disable-next-line no-await-in-loop -- check each excluded HTML surface
    const res = await get(rt, host, excludedPath, { headers: probeHeaders });
    const html = await res.text();
    expect(res.status, excludedPath).toBe(200);
    expect(html).toContain("<title>SpacePress HTML</title>");
    expect(html).not.toContain("data-spacefast-sdk");
    expect(html).not.toContain("spacefast-static:");
    expect(html).not.toContain("author-head");
    if (excludedPath.startsWith("/wp-admin/new/")) {
      expect(res.headers.get("content-security-policy")).toContain("frame-ancestors 'self'");
      expect(res.headers.get("x-frame-options")).toBeNull();
    }
  }
  const earlyJson = await get(rt, host, "/sp-route/early-json", { headers: probeHeaders });
  expect(earlyJson.status).toBe(200);
  expect(await earlyJson.json()).toEqual({ route: "early-json" });

  const restRes = await get(rt, host, "/wp-json/spacepress/v1/actions/ping", {
    headers: probeHeaders,
  });
  expect(restRes.status).toBe(200);
  expect(spRestBodySchema.parse(await restRes.json()).route).toBe("/spacepress/v1/actions/ping");

  const headRes = await get(rt, host, "/posts/hello-html", {
    method: "HEAD",
    headers: probeHeaders,
  });
  expect(headRes.status).toBe(200);
  expect(await headRes.text()).toBe("");

  const journalPath = path.join(rt.storageRoot, "runtime", "journal.jsonl");
  const journalBefore = existsSync(journalPath) ? readFileSync(journalPath, "utf8") : "";
  const redirectRes = await get(rt, host, "/sp-route/redirect", { redirect: "manual" });
  expect(redirectRes.status).toBe(302);
  expect(redirectRes.headers.get("location")).toBe("/posts/hello-html");
  expect(await redirectRes.text()).toBe("");
  const journalAfter = existsSync(journalPath) ? readFileSync(journalPath, "utf8") : "";
  expect(journalAfter.slice(journalBefore.length)).not.toContain("html_insert_anchor_missing");

  // 4. Injection opt-outs (Space off, plan default off, plan version off, sf.jsonc off)
  //    suppress SDK + baked static tags while retaining author snippets.
  const switches: SpacePressSdkSection[] = [
    { ...sdkOn, injection: false },
    { ...sdkOn, plan: { v: 1, default: { mode: "off", items: {} }, versions: {} } },
    {
      ...sdkOn,
      plan: {
        v: 1,
        default: { mode: "on", items: { "sdk-tag": true, "tags-baked": true, tracker: true } },
        versions: { [versionOn]: { mode: "off", items: {} } },
      },
    },
  ];
  for (const sdkOverride of switches) {
    // oxlint-disable-next-line no-await-in-loop -- sequential route updates verify each switch
    await putRoute(rt, spaceId, "production", routeFor(versionOn, sdkOverride));
    // oxlint-disable-next-line no-await-in-loop -- sequential request after route update
    const html = await (await get(rt, host, "/posts/hello-html")).text();
    expect(html).not.toContain("data-spacefast-sdk");
    expect(html).not.toContain("spacefast-static:");
    expect(html).toContain(authorHead);
    expect(html).toContain(authorNoscript);
    expect(html).toContain(authorBodyStart);
    expect(html).toContain(authorBodyEnd);
  }

  // With no SDK features active (no tag body, tracker, comments, bar, or users),
  // 0 bytes of Spacefast SDK script are injected.
  await putRoute(
    rt,
    spaceId,
    "production",
    routeFor(versionOn, { revision: "sdk-empty", config: {} }),
  );
  const idleHtml = await (await get(rt, host, "/posts/hello-html")).text();
  expect(idleHtml).not.toContain("data-spacefast-sdk");
  expect(idleHtml).toContain(authorHead);

  // Version-level sf.jsonc opt-out (`platform_injection: false`).
  await deploy(rt, {
    spaceId,
    versionId: versionFileOff,
    files: { "index.html": "<h1>Static Home</h1>\n" },
    serving: {
      config: {
        platform_injection: false,
        inject: {
          head: [authorHead, staticGa],
          bodyEnd: [authorBodyEnd, bakedSdk],
        },
      },
    },
    activate: { route_name: "production", ...routeFor(versionFileOff, sdkOn) },
  });
  writeSpacePressStub(rt.storageRoot, rt.root, spaceId, versionFileOff);
  const fileOffHtml = await (await get(rt, host, "/posts/hello-html")).text();
  expect(fileOffHtml).not.toContain("data-spacefast-sdk");
  expect(fileOffHtml).not.toContain("spacefast-static:");
  expect(fileOffHtml).toContain(authorHead);
  expect(fileOffHtml).toContain(authorBodyEnd);
});
