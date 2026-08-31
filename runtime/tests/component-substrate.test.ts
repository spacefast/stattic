import { expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

test("component staging observes WordPress tables and provisions its delivery tables", () => {
  const componentApi = path.resolve(import.meta.dir, "../engine/admin/components.php");
  const script = String.raw`
require $argv[1];
final class ComponentTestDatabase {
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public array $tables;
    public function __construct() {
        $this->tables = [
            'wp_options', 'wp_posts', 'wp_postmeta', 'wp_users',
            'wp_usermeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships',
        ];
    }
    public function prepare(string $query, string $table): array { return [$query, $table]; }
    public function get_var(array $prepared): string { return in_array($prepared[1], $this->tables, true) ? '1' : '0'; }
    public function query(string $statement): int|false {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS ([A-Za-z0-9_]+)/', $statement, $match) !== 1) return false;
        $this->tables[] = $match[1];
        return 0;
    }
}
$db = new ComponentTestDatabase();
$problems = [];
$wordpressReady = _stattic_component_wordpress_tables_ready($db);
$db->tables = array_values(array_filter($db->tables, fn (string $table): bool => $table !== 'wp_posts'));
$wordpressMissing = _stattic_component_wordpress_tables_ready($db);
$db->tables[] = 'wp_posts';
$provisioned = _stattic_component_provision_application_tables($db, $problems);
echo json_encode([
    'wordpressReady' => $wordpressReady,
    'wordpressMissing' => $wordpressMissing,
    'provisioned' => $provisioned,
    'problems' => $problems,
    'application' => _stattic_application_substrate_readiness($db),
], JSON_THROW_ON_ERROR);
`;
  const php = Bun.spawnSync(["php", "-r", script, componentApi]);

  expect(php.stderr.toString()).toBe("");
  expect(JSON.parse(php.stdout.toString())).toEqual({
    wordpressReady: true,
    wordpressMissing: false,
    provisioned: true,
    problems: [],
    application: { mail: { state: "ready" }, journal: { state: "ready" } },
  });
});

test("blocked component receipts report the routing state they observed", () => {
  const componentApi = path.resolve(import.meta.dir, "../engine/admin/components.php");
  const script = String.raw`
require $argv[1];
$payload = _stattic_component_blocked_receipt(
    ['format' => 'spacefast.component-placement'],
    [['code' => 'routing_receipt_stale', 'detail' => 'stale']],
    ['snapshotRevision' => 'sha256:' . str_repeat('a', 64), 'inventoryDigest' => 'sha256:' . str_repeat('b', 64)]
);
echo json_encode($payload, JSON_THROW_ON_ERROR);
`;
  const php = Bun.spawnSync(["php", "-r", script, componentApi]);

  expect(php.stderr.toString()).toBe("");
  expect(JSON.parse(php.stdout.toString())).toEqual({
    format: "spacefast.component-placement",
    status: "blocked",
    observedRouting: {
      snapshotRevision: `sha256:${"a".repeat(64)}`,
      inventoryDigest: `sha256:${"b".repeat(64)}`,
    },
    problems: [{ code: "routing_receipt_stale", detail: "stale" }],
  });
});

test("component staging removes the spent runtime bootstrap before readiness", async () => {
  const componentApi = path.resolve(import.meta.dir, "../engine/admin/components.php");
  const root = await mkdtemp(path.join(os.tmpdir(), "spacefast-component-bootstrap-"));
  const pluginRoot = path.join(root, "spacefast-runtime-bootstrap");
  await mkdir(pluginRoot);
  await writeFile(path.join(pluginRoot, "spacefast-runtime-bootstrap.php"), "<?php");
  try {
    const script = String.raw`
define('WP_PLUGIN_DIR', $argv[2]);
$GLOBALS['bootstrap_active'] = true;
function is_plugin_active(string $plugin): bool { return $GLOBALS['bootstrap_active']; }
function deactivate_plugins(string|array $plugins, bool $silent = false): void { $GLOBALS['bootstrap_active'] = false; }
function delete_plugins(array $plugins): bool {
    $root = WP_PLUGIN_DIR . '/spacefast-runtime-bootstrap';
    unlink($root . '/spacefast-runtime-bootstrap.php');
    rmdir($root);
    return true;
}
require $argv[1];
$problems = [];
_stattic_component_remove_runtime_bootstrap($problems);
echo json_encode([
    'active' => $GLOBALS['bootstrap_active'],
    'present' => is_dir(WP_PLUGIN_DIR . '/spacefast-runtime-bootstrap'),
    'problems' => $problems,
], JSON_THROW_ON_ERROR);
`;
    const php = Bun.spawnSync(["php", "-r", script, componentApi, root]);
    expect(php.stderr.toString()).toBe("");
    expect(JSON.parse(php.stdout.toString())).toEqual({
      active: false,
      present: false,
      problems: [],
    });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("component staging verifies Data Liberation bytes and keeps its plugin inactive", async () => {
  const componentApi = path.resolve(import.meta.dir, "../engine/admin/components.php");
  const root = await mkdtemp(path.join(os.tmpdir(), "spacefast-component-toolkit-"));
  const includes = path.join(root, "wp-admin/includes");
  const pluginRoot = path.join(root, "plugins/data-liberation");
  const pharBody = "locked toolkit";
  await mkdir(includes, { recursive: true });
  await mkdir(pluginRoot, { recursive: true });
  await writeFile(path.join(includes, "plugin.php"), "<?php");
  await writeFile(path.join(pluginRoot, "plugin.php"), "<?php");
  await writeFile(path.join(pluginRoot, "php-toolkit.phar"), pharBody);
  try {
    const script = String.raw`
define('ABSPATH', $argv[2] . '/');
define('WP_PLUGIN_DIR', $argv[3]);
$GLOBALS['active'] = ['data-liberation/plugin.php' => true];
function get_plugins(): array {
    return [
        'secure-custom-fields/scf.php' => ['TextDomain' => 'secure-custom-fields', 'Version' => '6.9.5'],
        'redirection/redirection.php' => ['TextDomain' => 'redirection', 'Version' => '5.10.0'],
        'block-transformer/plugin.php' => ['TextDomain' => 'blocks-engine-php-transformer', 'Version' => '0.6.2'],
        'data-liberation/plugin.php' => ['TextDomain' => '', 'Version' => ''],
    ];
}
function is_plugin_active(string $plugin): bool { return $GLOBALS['active'][$plugin] ?? true; }
function activate_plugin(string $plugin, string $redirect = '', bool $networkWide = false, bool $silent = false): null {
    $GLOBALS['active'][$plugin] = true;
    return null;
}
function deactivate_plugins(string|array $plugins, bool $silent = false): void {
    foreach ((array) $plugins as $plugin) $GLOBALS['active'][$plugin] = false;
}
require $argv[1];
$lock = ['components' => [
    ['id' => 'secure-custom-fields', 'version' => '6.9.5'],
    ['id' => 'redirection', 'version' => '5.10.0'],
    ['id' => 'block-transformer', 'version' => '0.6.2'],
    ['id' => 'data-liberation', 'installedArtifact' => [
        'path' => 'data-liberation/php-toolkit.phar',
        'sha256' => 'sha256:' . $argv[4],
    ]],
]];
$problems = [];
$onDemand = _stattic_component_plugins($lock, $problems);
echo json_encode([
    'active' => is_plugin_active('data-liberation/plugin.php'),
    'problems' => $problems,
    'onDemand' => $onDemand,
], JSON_THROW_ON_ERROR);
`;
    const php = Bun.spawnSync([
      "php",
      "-r",
      script,
      componentApi,
      root,
      path.join(root, "plugins"),
      createHash("sha256").update(pharBody).digest("hex"),
    ]);
    expect(php.stderr.toString()).toBe("");
    expect(JSON.parse(php.stdout.toString())).toEqual({
      active: false,
      problems: [],
      onDemand: [],
    });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
