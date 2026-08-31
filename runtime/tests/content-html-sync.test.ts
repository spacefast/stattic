// The HTML half of the source-sync lane, and the evidence behind the decision
// that customers never see block markup.
//
// Fidelity here is MEASURED, not assumed. Every row of the table below runs the
// real pinned blocks-engine transformer over the real block markup WordPress
// saves, and the suite fails the moment those pinned bytes behave differently
// than the kernel's gate expects. The gate is not a guess about what the
// transformer supports — it is derived from what this file observes.
//
// The reconciliation machinery (common base, compare-and-swap, merge,
// acknowledgement) is format-blind and is proven once in the Markdown suite.
// This file asserts only what the HTML serializer itself decides, plus one
// end-to-end reconcile proving a `.html` binding reaches the same lane.
import { expect, test } from "bun:test";
import { mkdtempSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { fetchBlocksEnginePlugin } from "../../scripts/fetch-blocks-engine.mjs";
import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";
import { problem, receipt, runScenario } from "./content-sync.test-helper.ts";

const repoRoot = path.resolve(import.meta.dir, "../..");
const toolkitPhar = await fetchToolkitPhar();
const blocksEnginePlugin = await fetchBlocksEnginePlugin();

/**
 * Calls the serializer directly, with no WordPress and no sync kernel around
 * it. The fidelity table is a property of these two files and the two pinned
 * artifacts they load; putting a post and a ledger in the way would prove the
 * same thing more slowly and blame the wrong layer when it broke.
 */
function serializer(calls: ReadonlyArray<{ fn: string; argument: string }>): string[] {
  const script = `<?php
declare(strict_types=1);
require_once 'phar://' . ${JSON.stringify(toolkitPhar)} . '/vendor/autoload.php';
require_once ${JSON.stringify(blocksEnginePlugin)};

// The serializers throw the kernel's error type, which normally comes from the
// kernel. Only its shape matters here.
final class Spacefast_Content_Error extends RuntimeException {
    public function __construct(public readonly int $status, public readonly string $codeName, string $message) {
        parent::__construct($message);
    }
}
require_once ${JSON.stringify(path.join(repoRoot, "runtime/engine/wordpress/content-markdown.php"))};
require_once ${JSON.stringify(path.join(repoRoot, "runtime/engine/wordpress/content-source-sync.php"))};
require_once ${JSON.stringify(path.join(repoRoot, "runtime/engine/wordpress/content-html.php"))};

$out = [];
foreach (json_decode(${JSON.stringify(JSON.stringify(calls))}, true) as $call) {
    try {
        $value = ($call['fn'])($call['argument']);
        $out[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    } catch (Spacefast_Content_Error $error) {
        $out[] = 'ERROR:' . $error->codeName;
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
`;
  const scriptPath = path.join(
    mkdtempSync(path.join(os.tmpdir(), "spacefast-html-serializer-")),
    "s.php",
  );
  writeFileSync(scriptPath, script);
  const run = Bun.spawnSync([process.env.PHP_BINARY ?? "php", scriptPath]);
  const stdout = run.stdout.toString();
  if (!run.success || stdout.trim() === "") {
    throw new Error(`html serializer failed: ${run.stderr.toString()}\n${stdout}`);
  }
  // SAFETY: the script above emits exactly one string per call, in order.
  return JSON.parse(stdout) as string[];
}

function blocksToHtml(blocks: readonly string[]): string[] {
  return serializer(
    blocks.map((argument) => ({ fn: "spacefast_content_html_from_blocks", argument })),
  );
}

function representable(blocks: readonly string[]): string[] {
  return serializer(
    blocks.map((argument) => ({ fn: "spacefast_content_html_representable", argument })),
  );
}

function canonicalHtml(documents: readonly string[]): string[] {
  return serializer(
    documents.map((argument) => ({ fn: "spacefast_content_html_canonical", argument })),
  );
}

/**
 * The block markup WordPress actually saves, for the vocabulary a page is
 * written in. These are the rows of the fidelity table.
 */
const CARRIED = {
  paragraph: "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->",
  "paragraph with inline formatting":
    "<!-- wp:paragraph -->\n" +
    '<p>Hello <strong>bold</strong>, <em>italic</em>, <a href="https://example.com">a link</a>.</p>\n' +
    "<!-- /wp:paragraph -->",
  heading:
    '<!-- wp:heading {"level":2} -->\n' +
    '<h2 class="wp-block-heading">A heading</h2>\n' +
    "<!-- /wp:heading -->",
  "unordered list":
    "<!-- wp:list -->" +
    '<ul class="wp-block-list">' +
    "<!-- wp:list-item --><li>One</li><!-- /wp:list-item -->" +
    "<!-- wp:list-item --><li>Two</li><!-- /wp:list-item -->" +
    "</ul>" +
    "<!-- /wp:list -->",
  "ordered list":
    '<!-- wp:list {"ordered":true} -->' +
    '<ol class="wp-block-list">' +
    "<!-- wp:list-item --><li>One</li><!-- /wp:list-item -->" +
    "</ol>" +
    "<!-- /wp:list -->",
  quote:
    "<!-- wp:quote -->" +
    '<blockquote class="wp-block-quote">' +
    "<!-- wp:paragraph --><p>Quoted words.</p><!-- /wp:paragraph -->" +
    "<cite>Ada</cite>" +
    "</blockquote>" +
    "<!-- /wp:quote -->",
  code: '<!-- wp:code -->\n<pre class="wp-block-code"><code>console.log(1);</code></pre>\n<!-- /wp:code -->',
  preformatted:
    '<!-- wp:preformatted -->\n<pre class="wp-block-preformatted">raw   text</pre>\n<!-- /wp:preformatted -->',
  image:
    "<!-- wp:image -->\n" +
    '<figure class="wp-block-image"><img src="https://example.com/a.png" alt="An A"/></figure>\n' +
    "<!-- /wp:image -->",
  "image with a caption":
    "<!-- wp:image -->\n" +
    '<figure class="wp-block-image"><img src="https://example.com/a.png" alt="An A"/>' +
    '<figcaption class="wp-element-caption">Caption</figcaption></figure>\n' +
    "<!-- /wp:image -->",
  columns:
    '<!-- wp:columns --><div class="wp-block-columns">' +
    '<!-- wp:column --><div class="wp-block-column">' +
    "<!-- wp:paragraph --><p>Left.</p><!-- /wp:paragraph --></div><!-- /wp:column -->" +
    '<!-- wp:column --><div class="wp-block-column">' +
    "<!-- wp:paragraph --><p>Right.</p><!-- /wp:paragraph --></div><!-- /wp:column -->" +
    "</div><!-- /wp:columns -->",
} as const;

/**
 * Documents the transformer cannot carry back. Each is a measured failure of
 * the pinned v0.6.2 bytes, and each names WHY, because the reason decides
 * whether a later version fixes it.
 */
const REFUSED = {
  // The transformer derives a `blocks-engine-table-<hash>` class from the
  // figure's own class list, so the class it adds feeds into the hash it
  // computes next time and the markup oscillates with period two. There is no
  // fixed point to store as a common base.
  table:
    "<!-- wp:table -->\n" +
    '<figure class="wp-block-table"><table><tbody><tr><td>a</td><td>b</td></tr></tbody></table></figure>\n' +
    "<!-- /wp:table -->",
  // `core/buttons` decays: first into a paragraph carrying a synthetic marker
  // class, then into a bare paragraph once that class round-trips away.
  buttons:
    '<!-- wp:buttons --><div class="wp-block-buttons">' +
    '<!-- wp:button --><div class="wp-block-button">' +
    '<a class="wp-block-button__link wp-element-button" href="https://example.com">Go</a>' +
    "</div><!-- /wp:button --></div><!-- /wp:buttons -->",
  // Embeds are on the transformer's own "context-required" list: the provider
  // resolution is Gutenberg's, so a `core/embed` comes back as a group.
  embed:
    '<!-- wp:embed {"url":"https://example.com/v","type":"video","providerNameSlug":"youtube"} -->\n' +
    '<figure class="wp-block-embed is-type-video is-provider-youtube">' +
    '<div class="wp-block-embed__wrapper">\nhttps://example.com/v\n</div></figure>\n' +
    "<!-- /wp:embed -->",
  // A `core/group` wrapper is dropped outright. This one is why a fixed-point
  // check alone is not enough: the collapsed document IS stable, it is just
  // missing a level.
  group:
    '<!-- wp:group --><div class="wp-block-group">' +
    "<!-- wp:paragraph --><p>Inside.</p><!-- /wp:paragraph -->" +
    "</div><!-- /wp:group -->",
  // `core/html` exists precisely to hold markup blocks do not model.
  "raw html": "<!-- wp:html -->\n<marquee>hi</marquee>\n<!-- /wp:html -->",
  // A paragraph's `align` becomes a plain `className`, so the editor's
  // alignment control would silently lose its value on the way back.
  "an aligned paragraph":
    '<!-- wp:paragraph {"align":"center"} -->\n' +
    '<p class="has-text-align-center">Centered.</p>\n' +
    "<!-- /wp:paragraph -->",
  // A dynamic block renders at request time and saves no inner HTML at all, so
  // serializing it produces nothing. Only a check on the BLOCKS can see this;
  // the HTML round trip of "" is a perfect fixed point.
  "a dynamic block": '<!-- wp:latest-posts {"postsToShow":3} /-->',
} as const;

test("the block vocabulary a page is written in survives a round trip through HTML", () => {
  const names = Object.keys(CARRIED);
  const blocks = Object.values(CARRIED);
  const verdicts = representable(blocks);
  const html = blocksToHtml(blocks);

  // The whole table in one assertion, so a regression names the row that broke
  // rather than failing at the first one.
  expect(Object.fromEntries(names.map((name, index) => [name, verdicts[index]]))).toEqual(
    Object.fromEntries(names.map((name) => [name, "true"])),
  );

  // Representable is the contract; this is the point of it. What a customer
  // opens is HTML, with no trace of how WordPress stored it.
  for (const document of html) {
    expect(document).not.toContain("<!-- wp:");
    expect(document.length).toBeGreaterThan(0);
  }
});

test("a document richer than HTML is refused by name instead of being flattened", () => {
  const names = Object.keys(REFUSED);
  const verdicts = representable(Object.values(REFUSED));
  expect(Object.fromEntries(names.map((name, index) => [name, verdicts[index]]))).toEqual(
    Object.fromEntries(names.map((name) => [name, "false"])),
  );
});

test("canonical HTML is a fixed point, and authored HTML that is not is refused", () => {
  const [heading, indented, article, dropped, form] = canonicalHtml([
    "<h2>Title</h2>",
    // Hand-authored spacing and indentation are not part of the document.
    "<h2>Title</h2>\n\n<p>Hello <strong>world</strong>.</p>\n\n<ul>\n  <li>One</li>\n</ul>\n",
    "<article>\n  <h1>Post</h1>\n  <p>Body.</p>\n</article>\n",
    // A wrapper the transformer discards. Storing this as the common base would
    // mean the next pull quietly deleted the div from the customer's file.
    '<div class="wp-block-group"><p>Inside.</p></div>',
    '<form><input name="q"></form>',
  ]);

  expect(heading).toBe('<h2 class="wp-block-heading">Title</h2>');
  // Canonicalizing what canonicalization produced changes nothing: that
  // stability is what lets the ledger digest it and call a no-op sync a no-op.
  expect(canonicalHtml([heading])[0]).toBe(heading);
  expect(canonicalHtml([indented])[0]).toBe(indented);

  expect(indented).toContain("<strong>world</strong>");
  expect(article).toContain("<article");
  expect(dropped).toBe("ERROR:content_html_not_representable");
  expect(form).toBe("ERROR:content_html_not_representable");
});

test("an HTML source file binds, takes a WordPress edit, and is written back as HTML", async () => {
  const source = "<h1>Launch</h1>\n\n<p>The first paragraph.</p>\n";
  const [bound, , pulled] = await runScenario("html", [
    { op: "reconcile", state: "initial", text: source },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Launch</h1><!-- /wp:heading -->' +
        "<!-- wp:paragraph --><p>Edited in WordPress.</p><!-- /wp:paragraph -->",
    },
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
  ]);

  const created = receipt(bound);
  expect(created.status).toBe("created");
  expect(created.ledger.format).toBe("html");
  expect(created.ledger.baseText).not.toContain("<!-- wp:");

  const pull = receipt(pulled);
  expect(pull.status).toBe("pulled");
  // The bytes bound for the customer's repo file: their edit, as HTML.
  expect(pull.sourceWrite?.text).toContain("Edited in WordPress.");
  expect(pull.sourceWrite?.text).not.toContain("<!-- wp:");
});

test("an edit HTML cannot carry leaves the source file untouched", async () => {
  const source = "<p>Original line.</p>\n";
  const [, , refused] = await runScenario("html", [
    { op: "reconcile", state: "initial", text: source },
    {
      // A table: measured above as the transformer's non-convergent case.
      op: "editInWordPress",
      blocks:
        "<!-- wp:table -->" +
        '<figure class="wp-block-table"><table><tbody><tr><td>a</td></tr></tbody></table></figure>' +
        "<!-- /wp:table -->",
    },
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
  ]);

  expect(problem(refused).code).toBe("content_html_not_representable");
});
