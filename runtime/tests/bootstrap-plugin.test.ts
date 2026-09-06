import { afterEach, expect, test } from "bun:test";
import { access, copyFile, mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";

import { z } from "zod";

const pluginPath = new URL("../bootstrap-plugin/spacefast-bootstrap.php", import.meta.url).pathname;
const atomicPrependPath = new URL("./atomic-prepend.php", import.meta.url).pathname;
const roots: string[] = [];
const syntheticInstallerOutcomeSchema = z.record(z.string(), z.unknown());

afterEach(async () => {
  await Promise.all(roots.splice(0).map((root) => rm(root, { recursive: true, force: true })));
});

async function testRoot(prefix: string): Promise<string> {
  const root = await mkdtemp(path.join(tmpdir(), prefix));
  roots.push(root);
  return root;
}

async function readTrustAnchor(input: { prelude: string; cwd?: string }): Promise<string> {
  const php = `
    define('WP_CLI', true);
    ${input.prelude}
    require $argv[1];
    require $argv[2];
    echo spacefast_bootstrap_jwks_b64();
  `;
  const process = Bun.spawn(["php", "-r", php, atomicPrependPath, pluginPath], {
    cwd: input.cwd,
    stdout: "pipe",
    stderr: "pipe",
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
    process.exited,
  ]);
  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  return stdout;
}

async function runSyntheticInstaller(
  installerSource: string,
  options?: { graceSeconds?: number; timeoutSeconds?: number },
) {
  const root = await testRoot("spacefast-bootstrap-installer-");
  const publicRoot = path.join(root, "public");
  const pluginRoot = path.join(root, "plugin");
  await mkdir(path.join(publicRoot, "wp-content"), { recursive: true });
  await mkdir(pluginRoot, { recursive: true });
  const isolatedPlugin = path.join(pluginRoot, "spacefast-bootstrap.php");
  await copyFile(pluginPath, isolatedPlugin);
  await writeFile(path.join(pluginRoot, "installer.php"), installerSource);
  const php = [
    `define('WP_CLI', true);`,
    `define('WP_CONTENT_DIR', ${JSON.stringify(path.join(publicRoot, "wp-content"))});`,
    `define('SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS', ${options?.timeoutSeconds ?? 4});`,
    `define('SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS', ${options?.graceSeconds ?? 1});`,
    `require ${JSON.stringify(isolatedPlugin)};`,
    `$outcome = spacefast_bootstrap_run_installer([`,
    `  'zip_url' => 'https://example.test/runtime.zip',`,
    `  'md5' => '${"0".repeat(32)}',`,
    `  'revision' => 'expected-revision',`,
    `  'native_sha256' => '',`,
    `]);`,
    `echo json_encode($outcome);`,
  ].join("\n");
  const result = Bun.spawnSync({ cmd: ["php", "-r", php], env: process.env });
  return {
    outcome: syntheticInstallerOutcomeSchema.parse(JSON.parse(result.stdout.toString())),
    root,
    stderr: result.stderr.toString(),
  };
}

test("a fresh box reads its trust anchor through Atomic_Persistent_Data", async () => {
  // The provider never define()s persistent data or exports it as env; the
  // class is the only exposure a pre-engine box has (live-verified 2026-08-31).
  const root = await testRoot("spacefast-bootstrap-");
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_JWKS_B64: "persistent-data-jwks" }),
  );
  expect(await readTrustAnchor({ prelude: "", cwd: root })).toBe("persistent-data-jwks");
});

test("an installed engine's constant shadows persistent data", async () => {
  const root = await testRoot("spacefast-bootstrap-");
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_JWKS_B64: "persistent-data-jwks" }),
  );
  expect(
    await readTrustAnchor({
      prelude: "define('SPACEFAST_RUNTIME_JWKS_B64', 'engine-shim-jwks');",
      cwd: root,
    }),
  ).toBe("engine-shim-jwks");
});

test("a malformed Ed25519 signature is rejected without throwing", () => {
  const php = `
    define('WP_CLI', true);
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $b64url = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'kid' => 'test', 'x' => $b64url($public)]]];
    define('SPACEFAST_RUNTIME_JWKS_B64', base64_encode(json_encode($jwks)));
    require $argv[1];
    $header = $b64url(json_encode(['alg' => 'EdDSA', 'kid' => 'test']));
    $payload = $b64url(json_encode(['exp' => time() + 60]));
    echo json_encode(spacefast_bootstrap_verify_jwt($header . '.' . $payload . '.AA'));
  `;
  const result = Bun.spawnSync({ cmd: ["php", "-r", php, pluginPath], env: process.env });
  expect({ exitCode: result.exitCode, stderr: result.stderr.toString() }).toEqual({
    exitCode: 0,
    stderr: "",
  });
  expect(result.stdout.toString()).toBe("null");
});

test("an installer output overflow is capped and receives rollback grace", async () => {
  const { outcome, root, stderr } = await runSyntheticInstaller(`<?php
$state = dirname(__DIR__, 2);
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($state): void {
    file_put_contents($state . '/overflow-term', 'term');
    exit(0);
});
echo str_repeat('x', 70000);
while (true) { usleep(10000); }
`);
  expect(stderr).toBe("");
  expect(outcome).toEqual({ error: "installer_output_limit" });
  expect(await Bun.file(path.join(root, "overflow-term")).text()).toBe("term");
});

test("an installer receipt must prove the requested release", async () => {
  const { outcome, stderr } = await runSyntheticInstaller(
    `<?php echo json_encode(['status' => 'installed', 'engine_revision' => 'wrong', 'layout' => 'legacy']);`,
  );
  expect(stderr).toBe("");
  expect(outcome).toMatchObject({ error: "installer_receipt_invalid" });
});

test("an installer lock collision is not reported as WP-CLI success", async () => {
  const root = await testRoot("spacefast-bootstrap-busy-");
  const publicRoot = path.join(root, "public");
  const pluginRoot = path.join(root, "plugin");
  await mkdir(path.join(publicRoot, "wp-content"), { recursive: true });
  await mkdir(pluginRoot, { recursive: true });
  const isolatedPlugin = path.join(pluginRoot, "spacefast-bootstrap.php");
  await copyFile(pluginPath, isolatedPlugin);
  await copyFile(
    new URL("../installer.php", import.meta.url),
    path.join(pluginRoot, "installer.php"),
  );
  const php = [
    `define('WP_CLI', true);`,
    `define('WP_CONTENT_DIR', ${JSON.stringify(path.join(publicRoot, "wp-content"))});`,
    `require ${JSON.stringify(isolatedPlugin)};`,
    `$lockRoot = ${JSON.stringify(path.join(publicRoot, ".stattic"))};`,
    `mkdir($lockRoot, 0755, true);`,
    `$lock = fopen($lockRoot . '/installer.lock', 'c');`,
    `flock($lock, LOCK_EX);`,
    `$outcome = spacefast_bootstrap_run_installer([`,
    `  'zip_url' => 'https://example.test/runtime.zip',`,
    `  'md5' => '${"0".repeat(32)}',`,
    `  'revision' => 'busy-proof',`,
    `  'native_sha256' => '',`,
    `]);`,
    `echo json_encode($outcome);`,
  ].join("\n");

  const result = Bun.spawnSync({ cmd: ["php", "-r", php], env: process.env });

  expect(result.exitCode, result.stderr.toString()).toBe(0);
  expect(JSON.parse(result.stdout.toString())).toMatchObject({
    error: "installer_busy",
    receipt: { status: "busy" },
  });
});

test("an installer timeout gives the process group a rollback grace before hard kill", async () => {
  const root = await testRoot("spacefast-bootstrap-timeout-");
  const publicRoot = path.join(root, "public");
  const pluginRoot = path.join(root, "plugin");
  await mkdir(path.join(publicRoot, "wp-content"), { recursive: true });
  await mkdir(pluginRoot, { recursive: true });
  const isolatedPlugin = path.join(pluginRoot, "spacefast-bootstrap.php");
  await copyFile(pluginPath, isolatedPlugin);
  await writeFile(
    path.join(pluginRoot, "installer.php"),
    `<?php
$docroot = dirname(__DIR__);
$state = ${JSON.stringify(root)};
mkdir($docroot . '/.stattic', 0755, true);
$lock = fopen($docroot . '/.stattic/installer.lock', 'ce');
flock($lock, LOCK_EX);
pcntl_async_signals(true);
$child = pcntl_fork();
if ($child === 0) {
    pcntl_signal(SIGTERM, static function () use ($state): void { file_put_contents($state . '/child-term', 'term'); });
    file_put_contents($state . '/child-pid', (string) getmypid());
    while (true) { usleep(10000); }
}
pcntl_signal(SIGTERM, static function () use ($state): void { file_put_contents($state . '/leader-term', 'term'); });
file_put_contents($state . '/leader-pid', (string) getmypid());
while (true) { usleep(10000); }
`,
  );
  const php = [
    `define('WP_CLI', true);`,
    `define('WP_CONTENT_DIR', ${JSON.stringify(path.join(publicRoot, "wp-content"))});`,
    `define('SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS', 2);`,
    `define('SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS', 1);`,
    `require ${JSON.stringify(isolatedPlugin)};`,
    `$outcome = spacefast_bootstrap_run_installer([`,
    `  'zip_url' => 'https://example.test/runtime.zip',`,
    `  'md5' => '${"0".repeat(32)}',`,
    `  'revision' => 'timeout-proof',`,
    `  'native_sha256' => '',`,
    `]);`,
    `echo json_encode($outcome);`,
  ].join("\n");

  const result = Bun.spawnSync({ cmd: ["php", "-r", php], env: process.env });

  expect(result.exitCode, result.stderr.toString()).toBe(0);
  expect(JSON.parse(result.stdout.toString())).toEqual({ error: "installer_timeout" });
  expect(await Bun.file(path.join(root, "leader-term")).text()).toBe("term");
  expect(await Bun.file(path.join(root, "child-term")).text()).toBe("term");
  const lockProbe = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      '$lock = fopen($argv[1], "ce"); exit(flock($lock, LOCK_EX | LOCK_NB) ? 0 : 1);',
      path.join(publicRoot, ".stattic/installer.lock"),
    ],
  });
  expect(lockProbe.exitCode).toBe(0);
});

async function restoreConfig(root: string, config: Record<string, string>, providerContext = true) {
  const entrypoint = new URL("../bootstrap-plugin/restore-config.php", import.meta.url).pathname;
  const process = Bun.spawn(
    ["php", "-d", `auto_prepend_file=${providerContext ? atomicPrependPath : ""}`, entrypoint],
    {
      cwd: root,
      stdin: new TextEncoder().encode(JSON.stringify(config)),
      stdout: "pipe",
      stderr: "pipe",
    },
  );
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
    process.exited,
  ]);
  const result = JSON.parse(stdout);
  expect({ exitCode, stderr }).toEqual({ exitCode: result.error ? 1 : 0, stderr: "" });
  return result;
}

test("restoring missing runtime config preserves tenant files and existing matching config", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-repair-"));
  const content = path.join(root, ".stattic/storage/spaces/spc_owned/blobs/content");
  await mkdir(path.dirname(content), { recursive: true });
  await writeFile(content, "tenant-content");
  const config = {
    SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned",
    SPACEFAST_API_BASE_URL: "https://api.example.test",
  };
  expect(await restoreConfig(root, config)).toEqual({ status: "restored" });
  const configPath = path.join(root, ".stattic/storage/config.php");
  const original = await readFile(configPath, "utf8");
  expect(
    await restoreConfig(root, { ...config, SPACEFAST_API_BASE_URL: "https://other.test" }),
  ).toEqual({ status: "unchanged" });
  expect(await readFile(configPath, "utf8")).toBe(original);
  expect(await readFile(content, "utf8")).toBe("tenant-content");
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_other" })).toEqual({
    error: "bootstrap_config_runtime_id_conflict",
  });
  expect(await readFile(configPath, "utf8")).toBe(original);
  await rm(root, { recursive: true, force: true });
});

test("missing config does not permit replacing a provider-persisted runtime identity", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-conflict-"));
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_INSTANCE_ID: "box_other" }),
  );
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned" })).toEqual({
    error: "bootstrap_config_runtime_id_conflict",
  });
  await expect(access(path.join(root, ".stattic/storage/config.php"))).rejects.toMatchObject({
    code: "ENOENT",
  });
  await rm(root, { recursive: true, force: true });
});

test("config restoration refuses to run without the provider prepend", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-no-provider-"));
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned" }, false)).toEqual({
    error: "bootstrap_config_provider_context_missing",
  });
  await expect(access(path.join(root, ".stattic/storage/config.php"))).rejects.toMatchObject({
    code: "ENOENT",
  });
  await rm(root, { recursive: true, force: true });
});
