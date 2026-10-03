import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { startPhpServer } from "./harness.ts";

test("the document shell applies platform head inserts once without changing CSP or HEAD", async () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "sf-document-head-"));
  const privateRoot = path.join(root, ".stattic/storage");
  const versionRoot = path.join(privateRoot, "spaces/spc_document/versions/ver_document");
  mkdirSync(versionRoot, { recursive: true });
  const pageId = `page.${"1".repeat(32)}`;
  const bindingId = `sync.pages.${"1".repeat(32)}`;
  const text = "<p>Published document.</p>";
  const seed = JSON.stringify({
    bindingId,
    format: "tsx",
    modelRevision: `sha256:${"2".repeat(64)}`,
    text,
    sha256: `sha256:${new Bun.CryptoHasher("sha256").update(text).digest("hex")}`,
  });
  const snippet = '<meta name="platform-head" content="present">';
  writeFileSync(
    path.join(root, "wp-load.php"),
    `<?php
function spacefast_content_model_sync_binding($id) { return ['post_type' => 'page', 'format' => 'tsx', 'documentSeed' => ['sha256' => json_decode($GLOBALS['snapshotBytes'], true)['sha256']]]; }
function spacefast_content_sync_find_post($id, $binding, $adopt) {
    return (object) ['ID' => 1, 'post_status' => 'publish', 'post_title' => 'Document', 'post_content' => '<p>Live document.</p>'];
}
function get_post_meta($id, $key, $single) { return 'spc_document'; }
function wp_head() { echo '<meta name="wordpress-head" content="present">'; }
function wp_footer() { echo '<footer>WordPress footer</footer>'; }
`,
  );
  const router = path.join(root, "router.php");
  writeFileSync(
    router,
    `<?php
require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/storage.php"))};
require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/runtime/content-page.php"))};
$snapshotBytes = ${JSON.stringify(seed)};
$context = ['private_root' => ${JSON.stringify(privateRoot)}, 'space_id' => 'spc_document', 'version_id' => 'ver_document', 'serving' => ['immutable' => isset($_GET['immutable']), 'inject' => ['head' => isset($_GET['disabled']) ? [] : [${JSON.stringify(snippet)}]]]];
$route = ['id' => '${pageId}', 'bindingId' => '${bindingId}', 'path' => '/', 'render' => 'document', 'params' => []];
file_put_contents(_stattic_version_root($context['private_root'], 'spc_document', 'ver_document') . '/metadata.json', json_encode(['catalog' => ['format' => STATTIC_RUNTIME_VERSION_CATALOG_FORMAT, 'spaceId' => 'spc_document', 'versionId' => 'ver_document', 'paths' => ['_spacefast/pages/documents/${pageId}.json' => ['source' => ['sha256' => hash('sha256', $snapshotBytes), 'size' => strlen($snapshotBytes), 'contentType' => 'application/json']]], 'variants' => []]]));
function _stattic_v4_blob_contents($context, $sha) { return $GLOBALS['snapshotBytes']; }
function _stattic_v4_entry($dir, $root, $key) { return null; }
define('STATTIC_RUNTIME_THEME_STYLESHEET_URL', '/theme.css');
header("Content-Security-Policy: frame-ancestors 'none'");
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['immutable'])) header('Content-Length: 1');
_stattic_wordpress_page_try_serve($context, '/', $_SERVER['REQUEST_METHOD'], $route);
http_response_code(500);
echo 'Document route declined';
`,
  );
  try {
    const server = await startPhpServer({ args: [], router, cwd: root, env: process.env });
    try {
      for (const query of ["", "?immutable=1"]) {
        const response = await fetch(`${server.baseUrl}/${query}`);
        expect(response.status).toBe(200);
        expect(response.headers.get("cache-control")).toBe("private, no-store");
        expect(response.headers.get("content-security-policy")).toBe("frame-ancestors 'none'");
        expect(response.headers.get("content-length")).toBeNull();
        const html = await response.text();
        expect(html.split(snippet)).toHaveLength(2);
        expect(html).toContain(`<head>${snippet}<meta charset="utf-8">`);
        expect(html).toContain('<meta name="wordpress-head" content="present">');
        expect(html).toContain("WordPress footer</footer></body></html>");
        expect(html).toContain(query === "" ? "Live document." : "Published document.");
      }
      const head = await fetch(`${server.baseUrl}/`, { method: "HEAD" });
      expect(head.status).toBe(200);
      expect(head.headers.get("cache-control")).toBe("private, no-store");
      expect(head.headers.get("content-security-policy")).toBe("frame-ancestors 'none'");
      expect(await head.text()).toBe("");
      const disabled = await fetch(`${server.baseUrl}/?disabled=1`);
      expect(disabled.status).toBe(200);
      expect(await disabled.text()).not.toContain(snippet);
    } finally {
      server.stop();
    }
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
