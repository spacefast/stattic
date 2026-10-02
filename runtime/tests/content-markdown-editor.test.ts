// What the block editor does to a Markdown-bound document, and whether
// Markdown can still carry it afterwards.
//
// Every document here is markup the editor's own serializer wrote (see
// fixtures/content-markdown-editor/generate.ts). Hand-spelled fixtures copy the
// importer's spelling and miss what any save changes: whitespace, default
// attributes, attribute order, and the `anchor` the editor copies out of a
// heading's id. That gap made one paragraph move block every publish.
import { expect, test } from "bun:test";
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";
import { receipt, runScenario } from "./content-sync.test-helper.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const fixtures = path.join(import.meta.dir, "fixtures/content-markdown-editor");
const toolkitPhar = await fetchToolkitPhar();
const fixture = (name: string) => readFileSync(path.join(fixtures, name), "utf8");
const document = fixture("document.md");

/** Runs PHP against content-markdown.php alone, with no WordPress or kernel around it. */
function php(body: string): string {
  const script = `<?php
declare(strict_types=1);
require_once 'phar://' . ${JSON.stringify(toolkitPhar)} . '/vendor/autoload.php';
final class Spacefast_Content_Error extends RuntimeException {
    public function __construct(public readonly int $status, public readonly string $codeName, string $message) {
        parent::__construct($message);
    }
}
require_once ${JSON.stringify(path.join(repoRoot, "runtime/engine/wordpress/content-markdown.php"))};
${body}
`;
  const dir = mkdtempSync(path.join(os.tmpdir(), "spacefast-markdown-editor-"));
  const file = path.join(dir, "probe.php");
  writeFileSync(file, script);
  const run = Bun.spawnSync([process.env.PHP_BINARY ?? "php", file]);
  const stdout = run.stdout.toString();
  if (!run.success || stdout.trim() === "") {
    throw new Error(`markdown probe failed: ${run.stderr.toString()}\n${stdout}`);
  }
  return stdout;
}

function representable(names: readonly string[]) {
  const documents = Object.fromEntries(names.map((name) => [name, fixture(name)]));
  const out = php(`
$out = [];
foreach (json_decode(${JSON.stringify(JSON.stringify(documents))}, true) as $name => $blocks) {
    $out[$name] = spacefast_content_markdown_representable($blocks);
}
echo json_encode($out);
`);
  return z.record(z.string(), z.boolean()).parse(JSON.parse(out));
}

test("ordinary editor edits to a Markdown document stay representable", () => {
  expect(
    representable([
      "saved.html",
      "reordered.html",
      "renamed.html",
      "inserted.html",
      "bolded.html",
      "coded.html",
      // A document an earlier engine imported, with ids stored on its headings,
      // after the edit that blocked publishing.
      "legacy-reordered.html",
    ]),
  ).toEqual({
    "saved.html": true,
    "reordered.html": true,
    "renamed.html": true,
    "inserted.html": true,
    "bolded.html": true,
    "coded.html": true,
    "legacy-reordered.html": true,
  });
});

test("an anchor the author set is something Markdown cannot carry", () => {
  expect(
    representable([
      "anchored.html",
      // A stored id that no longer matches its heading's text is a real anchor
      // now. Clearing it in the editor makes the document representable again.
      "legacy-renamed.html",
    ]),
  ).toEqual({ "anchored.html": false, "legacy-renamed.html": false });
});

test("a document an earlier engine stored untouched stays representable", () => {
  // The gate also runs on blocks nobody edited (a title change, a merge), so
  // the importer's old spelling of code fences and images must still pass.
  const sources = {
    document,
    js: "```js\nfoo()\n```",
    plain: "```\nfoo()\n```",
    image: "![a](https://example.com/y.png)",
  };
  const out = php(`
$out = [];
foreach (json_decode(${JSON.stringify(JSON.stringify(sources))}, true) as $name => $markdown) {
    $out[$name] = spacefast_content_markdown_representable(spacefast_content_markdown_consume($markdown));
}
echo json_encode($out);
`);
  expect(z.record(z.string(), z.boolean()).parse(JSON.parse(out))).toEqual({
    document: true,
    js: true,
    plain: true,
    image: true,
  });
});

test("stored Markdown documents carry no heading ids, and rendering derives the importer's", () => {
  const headings = [
    "# Problem: Attacks",
    "## Using `foo()` & *bar* [link](https://example.com)!",
    "### Hello, World — 2026",
    "> ## Quoted heading",
    // Inline HTML is not heading text to the importer, and an image's alt text is.
    "## Step 1: <kbd>Ctrl</kbd>+C",
    "## Foo <br> bar",
    "## Logo ![alt text](https://example.com/logo.png) here",
  ].join("\n\n");
  const probe = php(`
$markdown = ${JSON.stringify(headings)};
$stored = spacefast_content_markdown_to_blocks($markdown);
preg_match_all('/<h\\d class="wp-block-heading" id="([^"]*)">/', spacefast_content_markdown_consume($markdown), $toolkit);
preg_match_all('/<h\\d[^>]*>.*?<\\/h\\d>/', $stored, $storedHeadings);

$GLOBALS['SPACEFAST_CONTENT_RENDER_FORMAT'] = 'md';
$rendered = array_map(static fn (string $html): string => spacefast_content_markdown_render_heading($html), $storedHeadings[0]);
preg_match_all('/ id="([^"]*)"/', implode('', $rendered), $renderedIds);

$authored = spacefast_content_markdown_render_heading('<h2 id="threat-list" class="wp-block-heading">Common attacks</h2>');
$GLOBALS['SPACEFAST_CONTENT_RENDER_FORMAT'] = 'html';
$otherFormat = spacefast_content_markdown_render_heading('<h2 class="wp-block-heading">Common attacks</h2>');
echo json_encode([
    'storedIds' => preg_match('/<h\\d[^>]* id=/', $stored),
    'toolkitIds' => $toolkit[1],
    'renderedIds' => $renderedIds[1],
    'renderedFirst' => $rendered[0],
    'authored' => $authored,
    'otherFormat' => $otherFormat,
    // A document imported before ids left storage still carries the importer's ids.
    'legacyRepresentable' => spacefast_content_markdown_representable(spacefast_content_markdown_consume($markdown)),
    'canonicalUnchanged' => spacefast_content_markdown_canonical($markdown) === spacefast_content_markdown_from_blocks(spacefast_content_markdown_consume($markdown)),
]);
`);
  const out = z
    .object({
      storedIds: z.number(),
      toolkitIds: z.array(z.string()),
      renderedIds: z.array(z.string()),
      renderedFirst: z.string(),
      authored: z.string(),
      otherFormat: z.string(),
      legacyRepresentable: z.boolean(),
      canonicalUnchanged: z.boolean(),
    })
    .parse(JSON.parse(probe));

  expect(out.storedIds).toBe(0);
  expect(out.toolkitIds).toEqual([
    "problem-attacks",
    "using-bar-link-",
    "hello-world-2026",
    "quoted-heading",
    "step-1-ctrl-c",
    "foo-bar",
    "logo-alt-text-here",
  ]);
  // Fragment links into a document imported before ids left storage still resolve.
  expect(out.renderedIds).toEqual(out.toolkitIds);
  expect(out.renderedFirst).toBe(
    '<h1 id="problem-attacks" class="wp-block-heading">Problem: Attacks</h1>',
  );
  expect(out.authored).toBe('<h2 id="threat-list" class="wp-block-heading">Common attacks</h2>');
  expect(out.otherFormat).toBe('<h2 class="wp-block-heading">Common attacks</h2>');
  // The ledger digests canonical Markdown, so this change must not move it.
  expect(out.legacyRepresentable).toBe(true);
  expect(out.canonicalUnchanged).toBe(true);
});

test("an editor edit to a Markdown document pulls back into its source file", async () => {
  const [, , pulled] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: document },
    { op: "editInWordPress", blocks: fixture("coded.html") },
    { op: "reconcile", state: "bound", text: document, baseRevision: "@previous" },
  ]);
  const pull = receipt(pulled);
  expect(pull.status).toBe("pulled");
  const text = pull.sourceWrite?.text ?? "";
  // The fence keeps its language, and the code its indentation, after an edit
  // in a block core gives no `language` attribute.
  expect(text).toContain("```js\nif (user.isAdmin && !user.mfa[0]) {\n  deny();\n}\n```");
  expect(text).toContain("![Threat model](https://example.com/threats.png)");
});

test("a source file that already matches an editor edit settles without a conflict", async () => {
  // The author mirrors the editor's change in the repo before the drain pulls
  // it, so both sides moved to the same document.
  const mirrored = document.replace(
    "Attackers look for the **weakest** link. Read the [OWASP list](https://owasp.org/Top10/).\n\nEvery system has one.",
    "Every system has one.\n\nAttackers look for the **weakest** link. Read the [OWASP list](https://owasp.org/Top10/).",
  );
  expect(mirrored).not.toBe(document);
  const [, , settled] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: document },
    { op: "editInWordPress", blocks: fixture("legacy-reordered.html") },
    { op: "reconcile", state: "bound", text: mirrored, baseRevision: "@previous" },
  ]);
  const merged = receipt(settled);
  expect(merged.ledger.baseText).toContain("Every system has one.\n\nAttackers look for");
});
