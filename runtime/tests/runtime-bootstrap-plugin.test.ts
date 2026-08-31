import { expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { copyFile, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

const plugin = path.resolve(import.meta.dir, "../bootstrap/spacefast-runtime-bootstrap.php");

async function bootstrapFixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), "spacefast-bootstrap-plugin-"));
  const fixturePlugin = path.join(root, "spacefast-runtime-bootstrap.php");
  await copyFile(plugin, fixturePlugin);
  await writeFile(
    path.join(root, "release.php"),
    `<?php
const SPACEFAST_RUNTIME_BOOTSTRAP_REVISION = 'dev-cafebabefeed';
const SPACEFAST_RUNTIME_BOOTSTRAP_ZIP_URL = 'https://releases.example/runtime-engine.zip';
const SPACEFAST_RUNTIME_BOOTSTRAP_MD5 = '${"a".repeat(32)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_NATIVE_SHA256 = '${"b".repeat(64)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_URL = 'https://releases.example/data-liberation.zip';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_VERSION = '0.9.0';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_ARCHIVE_SHA256 = '${"c".repeat(64)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_PHAR_SHA256 = '${"d".repeat(64)}';
`,
  );
  return { root, plugin: fixturePlugin };
}

function readConfig(fixturePlugin: string) {
  const script = String.raw`
define('ABSPATH', '/tmp/spacefast-bootstrap-test/');
function register_activation_hook(string $file, callable|string $callback): void {}
require $argv[1];
echo json_encode(spacefast_runtime_bootstrap_config(), JSON_THROW_ON_ERROR);
`;
  return Bun.spawnSync(["php", "-r", script, fixturePlugin]);
}

test("the runtime bootstrap plugin accepts only an immutable HTTPS release", async () => {
  const fixture = await bootstrapFixture();
  try {
    const accepted = readConfig(fixture.plugin);
    expect(accepted.exitCode).toBe(0);
    expect(accepted.stderr.toString()).toBe("");
    expect(JSON.parse(accepted.stdout.toString())).toEqual({
      revision: "dev-cafebabefeed",
      zip_url: "https://releases.example/runtime-engine.zip",
      md5: "a".repeat(32),
      native_sha256: "b".repeat(64),
      data_liberation: {
        url: "https://releases.example/data-liberation.zip",
        version: "0.9.0",
        archive_sha256: "c".repeat(64),
        phar_sha256: "d".repeat(64),
      },
    });

    await writeFile(
      path.join(fixture.root, "release.php"),
      `<?php
const SPACEFAST_RUNTIME_BOOTSTRAP_REVISION = 'dev-cafebabefeed';
const SPACEFAST_RUNTIME_BOOTSTRAP_ZIP_URL = 'http://releases.example/runtime-engine.zip';
const SPACEFAST_RUNTIME_BOOTSTRAP_MD5 = '${"a".repeat(32)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_NATIVE_SHA256 = '${"b".repeat(64)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_URL = 'https://releases.example/data-liberation.zip';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_VERSION = '0.9.0';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_ARCHIVE_SHA256 = '${"c".repeat(64)}';
const SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_PHAR_SHA256 = '${"d".repeat(64)}';
`,
    );
    const rejected = readConfig(fixture.plugin);
    expect(rejected.exitCode).not.toBe(0);
    expect(rejected.stderr.toString()).toContain("runtime_bootstrap_config_invalid");
  } finally {
    await rm(fixture.root, { recursive: true, force: true });
  }
});

test("the runtime bootstrap installs the pinned Markdown toolkit once and leaves it inactive", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "spacefast-bootstrap-toolkit-"));
  const pluginRoot = path.join(root, "plugins");
  const archive = path.join(root, "data-liberation.zip");
  const pharBody = "pinned toolkit bytes";
  await mkdir(pluginRoot);
  await writeFile(archive, "pinned archive bytes");
  try {
    const script = String.raw`
define('ABSPATH', $argv[2] . '/');
define('WP_PLUGIN_DIR', $argv[3]);
$GLOBALS['install_count'] = 0;
$GLOBALS['active'] = false;
function register_activation_hook(string $file, callable|string $callback): void {}
function is_wp_error(mixed $value): bool { return false; }
function download_url(string $url, int $timeout): string { return $GLOBALS['archive']; }
function is_plugin_active(string $plugin): bool { return $GLOBALS['active']; }
function deactivate_plugins(string|array $plugins, bool $silent = false): void { $GLOBALS['active'] = false; }
function wp_clean_plugins_cache(bool $clearUpdateCache = true): void {}
final class Automatic_Upgrader_Skin {}
final class Plugin_Upgrader {
    public function __construct(object $skin) {}
    public function install(string $archive, array $options = []): bool {
        $GLOBALS['install_count']++;
        $root = WP_PLUGIN_DIR . '/data-liberation';
        if (!is_dir($root)) mkdir($root, 0777, true);
        file_put_contents($root . '/php-toolkit.phar', $GLOBALS['phar_body']);
        file_put_contents($root . '/plugin.php', '<?php');
        return true;
    }
}
$GLOBALS['archive'] = $argv[4];
$GLOBALS['phar_body'] = $argv[5];
require $argv[1];
$component = [
    'url' => 'https://releases.example/data-liberation.zip',
    'version' => '0.9.0',
    'archive_sha256' => hash_file('sha256', $GLOBALS['archive']),
    'phar_sha256' => hash('sha256', $GLOBALS['phar_body']),
];
spacefast_runtime_bootstrap_install_data_liberation($component);
spacefast_runtime_bootstrap_install_data_liberation($component);
echo json_encode([
    'installs' => $GLOBALS['install_count'],
    'active' => $GLOBALS['active'],
    'phar' => hash_file('sha256', WP_PLUGIN_DIR . '/data-liberation/php-toolkit.phar'),
], JSON_THROW_ON_ERROR);
`;
    const php = Bun.spawnSync(["php", "-r", script, plugin, root, pluginRoot, archive, pharBody]);
    expect(php.exitCode).toBe(0);
    expect(php.stderr.toString()).toBe("");
    expect(JSON.parse(php.stdout.toString())).toEqual({
      installs: 1,
      active: false,
      phar: createHash("sha256").update(pharBody).digest("hex"),
    });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("the runtime bootstrap targets the writable root that owns wp-content", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "spacefast-bootstrap-root-"));
  const contentRoot = path.join(root, "wp-content");
  await mkdir(contentRoot);
  try {
    const script = String.raw`
define('ABSPATH', '/provider/read-only-wordpress-core/');
define('WP_CONTENT_DIR', $argv[2]);
function register_activation_hook(string $file, callable|string $callback): void {}
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
require $argv[1];
echo spacefast_runtime_bootstrap_public_root();
`;
    const php = Bun.spawnSync(["php", "-r", script, plugin, contentRoot]);
    expect(php.exitCode).toBe(0);
    expect(php.stderr.toString()).toBe("");
    expect(php.stdout.toString()).toBe(root);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
