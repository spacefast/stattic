// Real WordPress and WP-CLI bytes shared by the full runtime acceptance suites.
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readFileSync, symlinkSync, writeFileSync } from "node:fs";
import path from "node:path";

import type { Runtime } from "./harness.ts";
import type { MysqlContainer } from "./mysql-container.ts";

export const WORDPRESS_IMAGE =
  "wordpress@sha256:63eda59d220083d9ed1fd9685ffdd5e0405959fd2fec73a734b631487561e12f";

/**
 * A pinned WordPress.org plugin archive in `dir`, checked against its digest.
 * downloads.wordpress.org answers the odd request with a 502 or drops a body
 * mid-transfer, so network failures, interrupted body reads and 5xx responses
 * are retried within one 60s deadline; the digest still checks whatever
 * arrives, so a retry cannot hide a bad archive.
 */
export async function downloadPinnedPlugin(
  dir: string,
  slug: string,
  version: string,
  digest: string,
): Promise<string> {
  const target = path.join(dir, `${slug}.${version}.zip`);
  if (!existsSync(target)) {
    const url = `https://downloads.wordpress.org/plugin/${slug}.${version}.zip`;
    const deadline = Date.now() + 60_000;
    let failure = "deadline passed before the first attempt";
    let done = false;
    for (let attempt = 1; !done && deadline - Date.now() > 1_000; attempt += 1) {
      const signal = AbortSignal.timeout(Math.min(20_000, deadline - Date.now()));
      let response: Response | undefined;
      try {
        // oxlint-disable-next-line no-await-in-loop -- attempts are sequential retries
        response = await fetch(url, { signal });
        if (response.status === 200) {
          // oxlint-disable-next-line no-await-in-loop -- the body read is part of the attempt
          const bytes = new Uint8Array(await response.arrayBuffer());
          writeFileSync(target, bytes);
          done = true;
          break;
        }
        failure = `HTTP ${response.status}`;
      } catch (cause) {
        failure = String(cause);
      }
      // Release the connection of a refused or broken response before moving on.
      // oxlint-disable-next-line no-await-in-loop -- cleanup belongs to this attempt
      await response?.body?.cancel().catch(() => {});
      if (response && response.status < 500 && response.status !== 200) break;
      // oxlint-disable-next-line no-await-in-loop -- backoff between sequential retries
      await Bun.sleep(Math.min(attempt * 2_000, Math.max(0, deadline - Date.now())));
    }
    if (!done) throw new Error(`${url}: ${failure}`);
  }
  assert.equal(new Bun.CryptoHasher("sha256").update(readFileSync(target)).digest("hex"), digest);
  return target;
}

export async function installWordpress(
  rt: Runtime,
  work: string,
  db: MysqlContainer,
  host: string,
  // The Zero suites need their plugins; a suite of plain WordPress skips the
  // downloads. `coreCron` leaves core's request-spawned cron on, which
  // SpacePress previews run their jobs from; the other suites drive jobs only
  // through the worker.
  options: { plugins?: boolean; coreCron?: boolean } = {},
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
      WORDPRESS_IMAGE,
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
${options.coreCron ? "" : "define('DISABLE_WP_CRON',true); "}define('WP_ENVIRONMENT_TYPE','local');
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
  if (options.plugins === false) return wp;
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
    // oxlint-disable-next-line no-await-in-loop -- plugins install in order
    const archive = await downloadPinnedPlugin(cache, slug, version, digest);
    wp("plugin", "install", archive, "--activate");
  }
  return wp;
}
