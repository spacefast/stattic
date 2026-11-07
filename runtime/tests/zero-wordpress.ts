// Real WordPress and WP-CLI bytes shared by the full runtime acceptance suites.
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readFileSync, symlinkSync, writeFileSync } from "node:fs";
import path from "node:path";

import type { Runtime } from "./harness.ts";
import type { MysqlContainer } from "./mysql-container.ts";

export async function installWordpress(
  rt: Runtime,
  work: string,
  db: MysqlContainer,
  host: string,
) {
  const cache = path.join(work, "cache");
  mkdirSync(cache, { recursive: true });
  const quote = (value: string) => "'" + value.replaceAll("'", "'\\''") + "'";
  function run(cmd: string[], env: Record<string, string> = {}): string {
    const result = Bun.spawnSync({
      cmd,
      env: { ...process.env, ...env },
      stdout: "pipe",
      stderr: "pipe",
    });
    if (result.exitCode !== 0)
      throw new Error(`${cmd[0]} failed:\n${result.stdout}\n${result.stderr}`);
    return result.stdout.toString();
  }
  async function download(url: string, target: string) {
    if (existsSync(target)) return;
    const response = await fetch(url);
    assert.equal(response.status, 200);
    writeFileSync(target, new Uint8Array(await response.arrayBuffer()));
  }

  const wordpressImage =
    "wordpress@sha256:63eda59d220083d9ed1fd9685ffdd5e0405959fd2fec73a734b631487561e12f";
  const cliImage =
    "wordpress@sha256:01998c8b665a74fe4154f94df8dd14afab558c83fed7be3235c13e7f06378bbe";
  // Official image bytes make setup reproducible without a prepopulated cache.
  const extract = Bun.spawnSync({
    cmd: [
      "docker",
      "run",
      "--rm",
      "--entrypoint",
      "tar",
      wordpressImage,
      "-C",
      "/usr/src/wordpress",
      "-czf",
      "-",
      ".",
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  assert.equal(extract.exitCode, 0, extract.stderr.toString());
  writeFileSync(path.join(cache, "wordpress.tar.gz"), extract.stdout);
  const cli = Bun.spawnSync({
    cmd: ["docker", "run", "--rm", "--entrypoint", "cat", cliImage, "/usr/local/bin/wp"],
    stdout: "pipe",
    stderr: "pipe",
  });
  assert.equal(cli.exitCode, 0, cli.stderr.toString());
  writeFileSync(path.join(cache, "wp-cli.phar"), cli.stdout);
  mkdirSync(path.join(work, "bin"));
  writeFileSync(
    path.join(work, "bin/wp"),
    `#!/bin/sh\nexec php ${quote(path.join(cache, "wp-cli.phar"))} "$@"\n`,
    { mode: 0o755 },
  );
  const core = path.join(rt.root, "__wp__");
  mkdirSync(core);
  run(["tar", "-xzf", path.join(cache, "wordpress.tar.gz"), "-C", core]);
  symlinkSync(path.join(core, "wp-load.php"), path.join(rt.root, "wp-load.php"));
  const database = new URL(db.url);
  const php = (value: string) => JSON.stringify(value);
  writeFileSync(
    path.join(core, "wp-config.php"),
    `<?php
define('DB_NAME','wordpress'); define('DB_USER','root'); define('DB_PASSWORD','disposable-zero-v2-proof');
define('DB_HOST',${php(database.host)}); define('DB_CHARSET','utf8mb4');
$table_prefix='wp_'; define('WP_CONTENT_DIR',${php(path.join(rt.root, "wp-content"))});
define('WP_CONTENT_URL','http://${host}/wp-content'); define('WP_DEBUG',true); define('WP_DEBUG_DISPLAY',false);
define('DISABLE_WP_CRON',true); define('WP_ENVIRONMENT_TYPE','local');
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/'); require_once ABSPATH.'wp-settings.php';
`,
  );
  const wp = (...args: string[]) =>
    run([
      "php",
      "-d",
      "display_errors=stderr",
      path.join(cache, "wp-cli.phar"),
      `--path=${core}`,
      ...args,
    ]);
  wp(
    "core",
    "install",
    `--url=http://${host}`,
    "--title=Zero local proof",
    "--admin_user=admin",
    "--admin_password=disposable-local-admin",
    "--admin_email=admin@example.test",
    "--skip-email",
  );
  wp("rewrite", "structure", "/%postname%/");
  for (const [slug, version, digest] of [
    [
      "secure-custom-fields",
      "6.9.7",
      "5b10edab948f64b84d44d81a29fd5794a2573205e0d5e7ea9538024fc93ff52c",
    ],
    [
      "action-scheduler",
      "4.2.0",
      "a360712a5adb066a0d60db76cbe2768234731cbcc363ba7dc2d51bf4559e4551",
    ],
    ["mcp-adapter", "0.7.1", "719d81aa1250615acfd8256f0acc8c13f1712f1ae398c3d5883c8b166024fe9b"],
  ]) {
    const archive = path.join(cache, `${slug}.${version}.zip`);
    await download(`https://downloads.wordpress.org/plugin/${slug}.${version}.zip`, archive);
    assert.equal(
      new Bun.CryptoHasher("sha256").update(readFileSync(archive)).digest("hex"),
      digest,
    );
    wp("plugin", "install", archive, "--activate");
  }
  return wp;
}
