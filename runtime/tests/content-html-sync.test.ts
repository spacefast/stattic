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

import { contentSourceInspectionSchema } from "../../packages/common/src/contracts/content-sync.ts";
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
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_alpha';
function spacefast_content_space_id(): string { return $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? ''; }
function serialize_source_blocks(string $blocks): string { return spacefast_content_sync_from_blocks('html', $blocks); }
function normalize_source_media_urls(string $text): string { return spacefast_content_sync_source_media_urls($text); }

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

function canonicalBlockMarkup(blocks: readonly string[]): string[] {
  return serializer(
    blocks.map((argument) => ({ fn: "spacefast_content_html_canonical_block_markup", argument })),
  );
}

function canonicalHtml(documents: readonly string[]): string[] {
  return serializer(
    documents.map((argument) => ({ fn: "spacefast_content_html_canonical", argument })),
  );
}

test("source serialization makes this Space's media URLs host-relative", () => {
  const hash = (space: string) =>
    new Bun.CryptoHasher("sha256").update(space).digest("hex").slice(0, 32);
  const own = hash("spc_alpha");
  const other = hash("spc_beta");
  const [serialized, normalized] = serializer([
    {
      fn: "serialize_source_blocks",
      argument:
        `<!-- wp:paragraph -->` +
        `<p><img src=\"https://box.test/__spacefast/content-media/${own}/a.png\" ` +
        `srcset=\"https://box.test/__spacefast/content-media/${own}/a.png 1x\" ` +
        `href=\"http://box.test/__spacefast/content-media/${own}/link\"></p>` +
        "<!-- /wp:paragraph -->",
    },
    {
      fn: "normalize_source_media_urls",
      argument:
        `<!-- wp:image {\"url\":\"https://box.test/__spacefast/content-media/${own}/block.png\"} -->` +
        ` <img srcset=\"https://other.test/__spacefast/content-media/${other}/b.png 2x\">`,
    },
  ]);

  expect(serialized).toContain(`src="/__spacefast/content-media/${own}/a.png"`);
  expect(serialized).toContain(`srcset="/__spacefast/content-media/${own}/a.png 1x"`);
  expect(serialized).toContain(`href="/__spacefast/content-media/${own}/link"`);
  expect(serialized).not.toContain("box.test");
  expect(normalized).toContain(`"url":"/__spacefast/content-media/${own}/block.png"`);
  expect(normalized).toContain(`https://other.test/__spacefast/content-media/${other}/b.png 2x`);
});

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
  // What the editor saves: fixed layout is core/table's default, so it has no
  // comment attribute; auto layout is the explicit non-default.
  "a fixed-layout table":
    "<!-- wp:table -->" +
    '<figure class="wp-block-table"><table class="has-fixed-layout"><tbody><tr><td>a</td><td>b</td></tr></tbody></table></figure>' +
    "<!-- /wp:table -->",
  "an auto-layout table with a caption":
    '<!-- wp:table {"hasFixedLayout":false} -->' +
    '<figure class="wp-block-table"><table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>' +
    '<figcaption class="wp-element-caption">Cap</figcaption></figure>' +
    "<!-- /wp:table -->",
} as const;

/**
 * Documents the transformer cannot carry back. Each is a measured failure of
 * the pinned transformer, and each names WHY, because the reason decides
 * whether a later version fixes it.
 */
const REFUSED = {
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

test("WordPress-normalized image markup remains representable", () => {
  const ksesMarkup =
    '<!-- wp:image -->\n<figure class="wp-block-image"><img src="https://example.com/a.png" alt="An A" /></figure>\n<!-- /wp:image -->';
  expect(representable([ksesMarkup])).toEqual(["true"]);
});

test("a document richer than HTML is refused by name instead of being flattened", () => {
  const names = Object.keys(REFUSED);
  const verdicts = representable(Object.values(REFUSED));
  expect(Object.fromEntries(names.map((name, index) => [name, verdicts[index]]))).toEqual(
    Object.fromEntries(names.map((name) => [name, "false"])),
  );
});

test("HTML requiring unsupported parser repair is not treated as representable", () => {
  const adoptionAgencyMarkup =
    "<!-- wp:paragraph -->\n<p><font>a<b>b</font>c</b></p>\n<!-- /wp:paragraph -->";
  expect(representable([adoptionAgencyMarkup])).toEqual(["false"]);
});

test("nested block markup is normalized as one assembled HTML fragment", () => {
  const [markup] = canonicalBlockMarkup([CARRIED.columns]);
  expect(markup).toContain('<div class="wp-block-columns"><!-- wp:core/column -->');
  expect(markup).toContain("</div><!-- /wp:core/columns -->");
});

test("editor-spaced nested list blocks canonicalize like compact blocks", () => {
  const editorList =
    '<!-- wp:list -->\n<ul class="wp-block-list">\n' +
    "<!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n\n" +
    "<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item -->\n" +
    "</ul>\n<!-- /wp:list -->";
  expect(canonicalBlockMarkup([editorList])).toEqual(
    canonicalBlockMarkup([CARRIED["unordered list"]]),
  );
  expect(representable([editorList])).toEqual(["true"]);
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

test("documents stored by an earlier transformer stay representable after an upgrade", () => {
  // Exactly what Blocks Engine 0.6.2 stored for a header holding a description
  // list. Later releases label the header in List View (`metadata.name`) and
  // default generated blocks to the `custom/` namespace; neither may turn
  // existing documents into conflicts.
  const stored =
    '<!-- wp:group {"className":"hero","tagName":"header"} --><header class="wp-block-group hero">' +
    '<!-- wp:blocks-engine/description-list {"className":"facts","groups":[{"wrapper":{"className":"cell"},' +
    '"items":[{"tagName":"dt","content":"Client"},{"tagName":"dd","content":"Example Co"}]}]} -->' +
    '<dl class="facts"><div class="cell"><dt>Client</dt><dd>Example Co</dd></div></dl>' +
    "<!-- /wp:blocks-engine/description-list --></header><!-- /wp:group -->";
  // A label someone set in the editor is not something HTML can carry, so a
  // document holding one is still refused rather than silently losing it.
  const labeled = stored.replace(
    '{"className":"hero","tagName":"header"}',
    '{"className":"hero","tagName":"header","metadata":{"name":"Company facts"}}',
  );
  expect(representable([stored, labeled])).toEqual(["true", "false"]);
});

test("transformer List View labels are never stored, so routine edits stay representable", () => {
  const original =
    '<section class="hero"><h2>Pricing</h2><p>x</p></section><header class="top"><p>Hi</p></header>';
  const [stored] = serializer([{ fn: "spacefast_content_html_to_blocks", argument: original }]);
  expect(stored).not.toContain('"name"');
  // A heading edit and an appended landmark would each relabel a stored group.
  const [renamed, extended] = serializer([
    { fn: "spacefast_content_html_to_blocks", argument: original.replace("Pricing", "Plans") },
    {
      fn: "spacefast_content_html_to_blocks",
      argument: `${original}<footer class="end"><p>Bye</p></footer>`,
    },
  ]);
  expect(representable([stored, renamed, extended])).toEqual(["true", "true", "true"]);
  // Labels a person set in the editor cannot travel through HTML, so they refuse.
  expect(
    representable([
      '<!-- wp:heading {"metadata":{"name":"Plans"}} --><h2 class="wp-block-heading">Plans</h2><!-- /wp:heading -->',
      '<!-- wp:group {"metadata":{"name":"Hero"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    ]),
  ).toEqual(["false", "false"]);
});

test("captured page structures normalize once into a stored fixed point", () => {
  // Loose links, links carrying inline SVG, and button rows are what captured
  // marketing pages are made of. The transformer adds structure on the first
  // pass, so the raw capture is refused, but that first pass is the source an
  // importer writes and every later pass leaves it unchanged.
  const captured = [
    '<div class="cta"><a href="https://example.com/a">Start</a><a href="https://example.com/b">Message</a></div>',
    '<div><a class="btn" href="/contact/">Contact <svg viewBox="0 0 24 24" class="icon"><path d="M5 12h14"></path></svg></a></div>',
    '<div class="tags"><button type="button" class="pill">All</button><button type="button" class="pill">Strategy</button></div>',
  ];
  const once = serializer(
    captured.map((argument) => ({ fn: "spacefast_content_html_normalize", argument })),
  );
  const twice = serializer(
    once.map((argument) => ({ fn: "spacefast_content_html_normalize", argument })),
  );
  expect(twice).toEqual(once);
  expect(canonicalHtml(once)).toEqual(once);
  expect(canonicalHtml(captured)).toEqual(
    captured.map(() => "ERROR:content_html_not_representable"),
  );
});

test("an HTML source file binds, takes a WordPress edit, and is written back as HTML", async () => {
  const source = "<h1>Launch</h1>\n\n<p>The first paragraph.</p>\n";
  const [bound, , pulled] = await runScenario("html", [
    { op: "reconcile", state: "initial", text: source },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Launch</h1><!-- /wp:heading -->' +
        "<!-- wp:paragraph --><p>Edited in WordPress.</p><!-- /wp:paragraph -->" +
        '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/a.png" alt="An A" /></figure><!-- /wp:image -->',
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

test("inspection returns an unrepresentable WordPress edit for conflict resolution", async () => {
  const [, , inspection] = await runScenario("html", [
    { op: "reconcile", state: "initial", text: "<p>Original line.</p>\n" },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Edited.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    },
    { op: "inspectSource" },
  ]);

  expect(inspection.ok).toBe(true);
  if (inspection.ok) {
    const response = contentSourceInspectionSchema.parse(inspection.receipt);
    expect(response.wordpressText).toContain("Edited.");
  }
});

test("resolving an unrepresentable WordPress edit refuses without replacing it", async () => {
  const [, , before, refused, after] = await runScenario("html", [
    { op: "reconcile", state: "initial", text: "<p>Original line.</p>\n" },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Edited in WordPress.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    },
    { op: "inspectSource" },
    { op: "resolveSource", text: "<p>Selected resolution.</p>" },
    { op: "inspectSource" },
  ]);

  expect(before.ok).toBe(true);
  expect(problem(refused).code).toBe("content_html_not_representable");
  expect(after.ok).toBe(true);
  if (before.ok && after.ok) {
    const beforeInspection = contentSourceInspectionSchema.parse(before.receipt);
    const afterInspection = contentSourceInspectionSchema.parse(after.receipt);
    expect(afterInspection.wordpressText).toBe(beforeInspection.wordpressText);
  }
});
