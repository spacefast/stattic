import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";

const repoRoot = path.resolve(import.meta.dir, "../..");
const contentMarkdown = path.join(repoRoot, "runtime/engine/wordpress/content-markdown.php");
const toolkitPhar = await fetchToolkitPhar();

test("loading the toolkit inside WordPress leaves core's admin includes loadable", () => {
  const wordpressRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-wp-root-"));
  const probeRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-toolkit-order-"));
  const abspath = wordpressRoot + path.sep;
  mkdirSync(path.join(abspath, "wp-admin/includes"), { recursive: true });
  writeFileSync(
    path.join(abspath, "wp-admin/includes/media.php"),
    "<?php\nfunction wp_read_audio_metadata( $file ) { return 'core-media'; }\n",
  );

  const script = `<?php
declare(strict_types=1);
define('ABSPATH', ${JSON.stringify(abspath)});
$GLOBALS['SPACEFAST_CONTENT_PHP_TOOLKIT_PHAR'] = ${JSON.stringify(toolkitPhar)};
require ${JSON.stringify(contentMarkdown)};
spacefast_content_sync_require_toolkit();
require_once ABSPATH . 'wp-admin/includes/media.php';
echo json_encode([
  'metadata' => wp_read_audio_metadata('ignored'),
  'blocks' => spacefast_content_markdown_to_blocks('# Hi'),
], JSON_UNESCAPED_SLASHES);
`;
  try {
    const scriptPath = path.join(probeRoot, "probe.php");
    writeFileSync(scriptPath, script);
    const run = Bun.spawnSync([process.env.PHP_BINARY ?? "php", scriptPath]);
    const stdout = run.stdout.toString();
    if (!run.success || stdout.trim() === "") {
      throw new Error(`toolkit ordering probe failed: ${run.stderr.toString()}\n${stdout}`);
    }
    const output = z.object({ metadata: z.string(), blocks: z.string() }).parse(JSON.parse(stdout));
    expect(output.metadata).toBe("core-media");
    expect(output.blocks).toContain("wp:heading");
  } finally {
    rmSync(wordpressRoot, { recursive: true, force: true });
    rmSync(probeRoot, { recursive: true, force: true });
  }
});
