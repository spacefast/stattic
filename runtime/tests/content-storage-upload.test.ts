import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dir, "../..");
const kernel = path.join(repoRoot, "runtime/engine/wordpress/content-kernel.php");

// Real WordPress declares the sideload helpers only in wp-admin/includes, which
// an ability run never loads. The storage test's global stubs hide that, so
// this one declares them where core does and nowhere else.
test("the upload ability loads core's media helpers and refuses an unwritable uploads root before any write", async () => {
  const abspath = mkdtempSync(path.join(tmpdir(), "sf-storage-abspath-"));
  const includes = path.join(abspath, "wp-admin/includes");
  mkdirSync(includes, { recursive: true });
  writeFileSync(
    path.join(includes, "file.php"),
    [
      "<?php",
      "function wp_tempnam(string $filename): string { $GLOBALS['staged'] = true; return tempnam(sys_get_temp_dir(), 'sf'); }",
      "function wp_handle_sideload(array $file, array $overrides): array { $GLOBALS['sideloaded'] = true; return ['error' => 'unreachable']; }",
    ].join("\n"),
  );
  writeFileSync(
    path.join(includes, "image.php"),
    "<?php function wp_generate_attachment_metadata(int $id, string $file): array { return []; }",
  );
  writeFileSync(
    path.join(includes, "media.php"),
    "<?php function wp_read_audio_metadata(string $file): array { return []; }",
  );

  const script = String.raw`
define('ABSPATH', $argv[2] . '/');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
$GLOBALS['staged'] = false;
$GLOBALS['sideloaded'] = false;
$GLOBALS['folders'] = 0;
final class WP_Error {
  public function __construct(public string $code, public string $message, public array $data = []) {}
}
function wp_insert_attachment(array $attachment, string $file): int { return 0; }
// What core returns when wp_mkdir_p() cannot create the Space's directory.
function wp_upload_dir(): array {
  return ['error' => 'Unable to create directory. Is its parent directory writable by the server?'];
}
function term_exists(...$arguments) { $GLOBALS['folders']++; return null; }
function wp_insert_term(...$arguments) { $GLOBALS['folders']++; return ['term_id' => 1]; }
require $argv[1];
$loadedBefore = function_exists('wp_handle_sideload');
$result = spacefast_content_storage_upload([
  'filename' => 'about.webp',
  'contentBase64' => base64_encode('bytes'),
  'folder' => 'imports',
]);
echo json_encode([
  'loaded_before' => $loadedBefore,
  'loaded' => [function_exists('wp_handle_sideload'), function_exists('wp_tempnam'), function_exists('wp_generate_attachment_metadata')],
  'error' => [$result->code, $result->data['status']],
  'side_effects' => [$GLOBALS['staged'], $GLOBALS['sideloaded'], $GLOBALS['folders']],
]);
`;
  try {
    const process = Bun.spawn(["php", "-r", script, kernel, abspath], {
      cwd: repoRoot,
      stderr: "pipe",
      stdout: "pipe",
    });
    const [exitCode, stdout, stderr] = await Promise.all([
      process.exited,
      new Response(process.stdout).text(),
      new Response(process.stderr).text(),
    ]);
    expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
    expect(JSON.parse(stdout)).toEqual({
      loaded_before: false,
      loaded: [true, true, true],
      error: ["zero_storage_unavailable", 503],
      side_effects: [false, false, 0],
    });
  } finally {
    rmSync(abspath, { recursive: true, force: true });
  }
});
