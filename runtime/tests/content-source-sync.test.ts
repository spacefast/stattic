// The Markdown half of the source-sync lane, driven through the real pinned
// php-toolkit serializer. The reconciliation machinery it exercises — common
// base, compare-and-swap, three-way merge, acknowledgement — is format-blind
// and is proven here once; the HTML suite next door asserts only what its own
// serializer decides.
import { expect, test } from "bun:test";

import { verifySyncLedgerV1 } from "../../packages/common/src/contracts/content-contract-verification.ts";
import { syncMaterializeReceiptV1Schema } from "../../packages/common/src/contracts/content-sync.ts";
import {
  materialized,
  problem,
  receipt,
  runScenario,
  TSX_BINDING,
  TSX_SOURCE,
  type StepResult,
} from "./content-sync.test-helper.ts";

function inspected(result: StepResult | undefined) {
  if (!result?.ok || result.receipt.format !== "test.driver")
    throw new Error(JSON.stringify(result));
  return result.receipt;
}

test("release activation seeds canonical documents and preserves editor takeover identity", async () => {
  const text =
    '<!-- wp:paragraph --><p>Compiled page.</p><!-- /wp:paragraph --><!-- wp:latest-posts {"postsToShow":3} /-->';
  const edited = "<!-- wp:paragraph -->\n<p>Editor owns this.</p>\n<!-- /wp:paragraph -->";
  const outcomes = await runScenario("html", [
    { op: "activatePage", format: "tsx", text },
    { op: "renderPage" },
    { op: "inspectPage" },
    { op: "editInWordPress", blocks: edited },
    { op: "activatePage", format: "tsx", text },
    { op: "inspectPage" },
    { op: "activatePage", format: "tsx", text: "<p>New code.</p>" },
    { op: "inspectPage" },
    { op: "editInWordPress", blocks: edited },
    { op: "materialize", target: "binding" },
    { op: "activatePage", format: "html", text: "<p>Editor owns this.</p>" },
    { op: "inspectPage" },
    { op: "activatePage", format: "tsx", text: "<p>Stale code.</p>" },
    { op: "inspectPage" },
    // A failed replacement may clear the editor's active model while this
    // version still serves. Its sealed model must keep live editor bytes visible.
    { op: "renderPage", clearActiveRelease: true },
    { op: "renderPage", snapshot: { text, format: "tsx" } },
  ]);
  const renders = outcomes.filter(
    (outcome) =>
      outcome.ok &&
      outcome.receipt.format === "test.driver" &&
      outcome.receipt.status === "rendered",
  );
  const results = outcomes.filter((outcome) => !renders.includes(outcome));
  expect(inspected(renders[0]).html).toContain("Compiled page.");
  expect(inspected(renders[1]).html).toContain("Editor owns this.");
  expect(inspected(renders[1]).html).not.toContain("Compiled page.");
  expect(inspected(renders[2]).html).toContain("Compiled page.");
  expect(inspected(renders[2]).html).toContain('<!-- wp:latest-posts {"postsToShow":3} /-->');
  expect(inspected(renders[2]).html).not.toContain("Editor owns this.");
  const initial = inspected(results[1]);
  expect(initial).toMatchObject({
    postStatus: "publish",
    permalink: "https://space.test/docs/about",
    externalId: `source:${TSX_BINDING}`,
    spaceId: "spc_alpha",
  });
  expect(initial.blocks).toBe(text);
  expect(initial.ledger?.textDigest).toBe(
    `sha256:${new Bun.CryptoHasher("sha256").update(text).digest("hex")}`,
  );
  expect(inspected(results[4]).blocks).toBe(edited);
  expect(inspected(results[6]).blocks).toContain("New code.");
  expect(materialized(results[8]).sourceWrite.source).toBe("pages/docs/about.html");
  const takeover = inspected(results[10]);
  expect(results[9]?.ok).toBe(true);
  expect(takeover.postId).toBe(initial.postId);
  expect(takeover.permalink).toBe(initial.permalink);
  expect(takeover.externalId).toBe(initial.externalId);
  expect(takeover.ledger?.source).toBe("pages/docs/about.html");
  expect(takeover.blocks).toContain("Editor owns this.");
  expect(problem(results[11]).code).toBe("content_document_editor_owned");
  expect(inspected(results[12]).blocks).toBe(takeover.blocks);
});

test("documents restore only sealed island modules present in the served version", async () => {
  const boot = "/_spacefast/islands/0123456789abcdef/boot.js";
  const script = `<script type="module" src="${boot}"></script>`;
  const mount = '<div data-zero-component="counter">Clicks: 0</div>';
  const text = `<!-- wp:html -->${mount}${script}<!-- /wp:html -->`;
  const results = await runScenario("html", [
    { op: "activatePage", format: "tsx", text },
    // The persistence boundary supplies a mount with no script, as KSES does.
    { op: "editInWordPress", blocks: `<!-- wp:html -->${mount}<!-- /wp:html -->` },
    { op: "renderPage", assets: [boot] },
    { op: "renderPage", assets: [] },
    { op: "renderPage", assets: [boot], snapshot: { text, format: "tsx" } },
  ]);
  const live = inspected(results[2]).html;
  expect(live).toContain(mount);
  expect(live?.split(script)).toHaveLength(2);
  expect(inspected(results[3]).html).not.toContain(script);
  // Immutable content already carries the module and must not duplicate it.
  expect(inspected(results[4]).html?.split(script)).toHaveLength(2);

  // Editor takeover keeps source text unchanged; only the sealed artifact
  // carries the regenerated module URL for live and immutable HTML rendering.
  const htmlSource = "<p>Editor page.</p>";
  const html = await runScenario("html", [
    { op: "activatePage", format: "html", text: htmlSource },
    { op: "renderPage", assets: [boot], islandsBootUrl: boot },
    {
      op: "renderPage",
      assets: [boot],
      islandsBootUrl: boot,
      snapshot: { text: htmlSource, format: "html" },
    },
  ]);
  expect(inspected(html[1]).html?.split(script)).toHaveLength(2);
  expect(inspected(html[2]).html?.split(script)).toHaveLength(2);
});

test("sealed Markdown activation retains editor-only edits and refuses conflicting or corrupt source", async () => {
  const original = "Original paragraph.\n";
  const edited = "<!-- wp:paragraph -->\n<p>Editor paragraph.</p>\n<!-- /wp:paragraph -->";
  const results = await runScenario("md", [
    { op: "activatePage", format: "md", text: original },
    { op: "inspectPage" },
    { op: "editInWordPress", blocks: edited },
    { op: "activatePage", format: "md", text: original, release: "new-app-code" },
    { op: "inspectPage" },
    { op: "activatePage", format: "md", text: "Conflicting source.\n" },
    { op: "inspectPage" },
    { op: "activatePage", format: "md", text: "Editor paragraph.\n" },
    { op: "inspectPage" },
    { op: "activatePage", format: "md", text: original, invalidDigest: true },
    { op: "inspectPage" },
  ]);
  expect(inspected(results[1]).postStatus).toBe("publish");
  expect(inspected(results[4]).blocks).toBe(edited);
  expect(inspected(results[4]).ledger).toEqual(inspected(results[1]).ledger);
  expect(problem(results[5]).code).toBe("content_sync_conflict");
  expect(inspected(results[6]).activeRevision).toBe(inspected(results[4]).activeRevision);
  expect(inspected(results[6]).blocks).toBe(edited);
  expect(results[7]?.ok).toBe(true);
  expect(inspected(results[8]).ledger?.baseText).toContain("Editor paragraph.");
  expect(problem(results[9]).code).toBe("content_document_seed_invalid");
  expect(inspected(results[10]).activeRevision).toBe(inspected(results[8]).activeRevision);
});

test("a repo Markdown file binds, survives a WordPress edit, and round-trips back byte-stable", async () => {
  const source = "# Launch\n\nThe first paragraph.\n\n- alpha\n- beta\n";
  const [bound, , pulled] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: source },
    // The editor rewrites the body. Blocks are what WordPress stores, so the
    // pull has to come back through the serializer, not through stored text.
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} -->\n' +
        '<h1 class="wp-block-heading" id="launch">Launch</h1>\n' +
        "<!-- /wp:heading -->\n\n" +
        "<!-- wp:paragraph -->\n<p>Edited in WordPress.</p>\n<!-- /wp:paragraph -->\n",
    },
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
  ]);

  const created = receipt(bound);
  expect(created.status).toBe("created");

  // The ledger's base is the serializer's canonical spelling of the file, and
  // re-binding that exact text must be a no-op rather than a fresh change.
  expect(created.ledger.baseText).toContain("# Launch");
  // SAFETY: the ledger's branded digest types are the contract's; the kernel
  // produced these bytes and this call is what proves they parse and verify.
  await verifySyncLedgerV1(created.ledger as never);

  const pull = receipt(pulled);
  expect(pull.status).toBe("pulled");
  expect(pull.sourceWrite?.text).toBe(pull.ledger.baseText);
  expect(pull.sourceWrite?.text).toContain("Edited in WordPress.");
  // SAFETY: same branded-digest reason as the ledger verified above.
  await verifySyncLedgerV1(pull.ledger as never);
});

test("re-reconciling the same source file reports no change", async () => {
  // The raw file, not the ledger's canonical spelling: a file nobody touched
  // must stay unchanged even though the serializer normalizes it. Digesting
  // raw bytes instead of canonical ones would report a change on every sync.
  const source = "# Launch\n\nThe first paragraph.\n";
  const [, unchanged] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: source },
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
  ]);
  expect(receipt(unchanged).status).toBe("unchanged");
});

test("edits to different parts of a document merge instead of conflicting", async () => {
  const base = "# Launch\n\nAlpha paragraph.\n\nBravo paragraph.\n";
  const [, , merged] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: base },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} -->\n' +
        '<h1 class="wp-block-heading" id="launch">Launch</h1>\n' +
        "<!-- /wp:heading -->\n\n" +
        "<!-- wp:paragraph -->\n<p>Alpha paragraph.</p>\n<!-- /wp:paragraph -->\n\n" +
        "<!-- wp:paragraph -->\n<p>Bravo rewritten by the editor.</p>\n<!-- /wp:paragraph -->\n",
    },
    {
      // The repo changed the first paragraph while WordPress changed the last.
      op: "reconcile",
      state: "bound",
      text: "# Launch\n\nAlpha rewritten in the repo.\n\nBravo paragraph.\n",
      baseRevision: "@previous",
    },
  ]);

  const result = receipt(merged);
  expect(result.status).toBe("pulled");
  const settled = result.sourceWrite?.text ?? "";
  expect(settled).toContain("Alpha rewritten in the repo.");
  expect(settled).toContain("Bravo rewritten by the editor.");
});

test("edits to the same line on both sides conflict with all three representations", async () => {
  const base = "# Launch\n\nOriginal line.\n";
  const [, , conflicted] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: base },
    {
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} -->\n' +
        '<h1 class="wp-block-heading" id="launch">Launch</h1>\n' +
        "<!-- /wp:heading -->\n\n" +
        "<!-- wp:paragraph -->\n<p>Editor version.</p>\n<!-- /wp:paragraph -->\n",
    },
    {
      op: "reconcile",
      state: "bound",
      text: "# Launch\n\nRepo version.\n",
      baseRevision: "@previous",
    },
  ]);

  const failure = problem(conflicted);
  expect(failure.code).toBe("content_sync_conflict");
  expect(failure.details?.source.text).toContain("Repo version.");
  expect(failure.details?.wordpress.text).toContain("Editor version.");
  expect(failure.details?.base.text).toContain("Original line.");
});

test("a stale base revision is refused instead of overwriting the common base", async () => {
  const base = "# Launch\n\nOriginal line.\n";
  const [, stale] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: base },
    {
      op: "reconcile",
      state: "bound",
      text: "# Launch\n\nRepo version.\n",
      baseRevision: `sha256:${"b".repeat(64)}`,
    },
  ]);
  expect(problem(stale).code).toBe("content_sync_conflict");
});

test("WordPress formatting Markdown cannot carry is never flattened into a source file", async () => {
  const base = "# Launch\n\nOriginal line.\n";
  const [, , refused] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: base },
    {
      // `align` and `className` do not survive a Markdown round trip. Writing
      // this document out as Markdown would silently drop them.
      op: "editInWordPress",
      blocks:
        '<!-- wp:heading {"level":1} -->\n' +
        '<h1 class="wp-block-heading" id="launch">Launch</h1>\n' +
        "<!-- /wp:heading -->\n\n" +
        '<!-- wp:paragraph {"align":"center","className":"lead"} -->\n' +
        '<p class="has-text-align-center lead">Styled in the editor.</p>\n' +
        "<!-- /wp:paragraph -->\n",
    },
    {
      op: "reconcile",
      state: "bound",
      text: base,
      baseRevision: "@previous",
    },
  ]);

  expect(problem(refused).code).toBe("content_markdown_not_representable");
});

test("a prepared source write is acknowledged, and only against the ledger it came from", async () => {
  const source = "# Launch\n\nThe first paragraph.\n";
  const edit = {
    op: "editInWordPress",
    blocks: "<!-- wp:paragraph -->\n<p>Edited in WordPress.</p>\n<!-- /wp:paragraph -->\n",
  } as const;

  const [, , pulled, acknowledged] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: source },
    edit,
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
    { op: "acknowledge", baseRevision: "@previous" },
  ]);
  expect(receipt(pulled).status).toBe("pulled");
  expect(receipt(acknowledged).status).toBe("acknowledged");

  // A revision the ledger never held cannot close a prepared write: the caller
  // would be claiming it landed bytes nobody reconciled.
  const [, , , stale] = await runScenario("md", [
    { op: "reconcile", state: "initial", text: source },
    edit,
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
    { op: "acknowledge", baseRevision: `sha256:${"c".repeat(64)}` },
  ]);
  expect(problem(stale).code).toBe("content_sync_stale_acknowledgement");
});

test("the receipt book is bounded, so the oldest operation stops replaying", async () => {
  const source = "# Launch\n\nThe first paragraph.\n";
  const edit = {
    op: "editInWordPress",
    blocks: "<!-- wp:paragraph -->\n<p>Edited in WordPress.</p>\n<!-- /wp:paragraph -->\n",
  } as const;
  // One more reconcile than the book holds, each with its own operationId, so
  // the pull's receipt is the entry pushed out. Nothing else about the binding
  // changes across them: same source, same WordPress content, so the ledger
  // revision holds still and only the receipt book moves.
  const fill = Array.from({ length: 20 }, () => ({
    op: "reconcile" as const,
    state: "bound" as const,
    text: source,
    baseRevision: "@previous",
  }));

  const results = await runScenario("md", [
    { op: "reconcile", state: "initial", text: source },
    edit,
    { op: "reconcile", state: "bound", text: source, baseRevision: "@previous" },
    // Acknowledging op 2 while it is still held proves the eviction below is
    // the book filling up, not the acknowledgement being wrong to begin with.
    { op: "acknowledge", baseRevision: "@previous", ackOp: 2 },
    ...fill,
    { op: "acknowledge", baseRevision: "@previous", ackOp: 2 },
  ]);

  expect(receipt(results[2]).status).toBe("pulled");
  expect(receipt(results[3]).status).toBe("acknowledged");
  expect(problem(results[results.length - 1]).code).toBe("content_sync_not_prepared");
});

// Materialization is the other direction of the same lane: a document WordPress
// holds and no file backs yet. There is no common base to move, so the answer is
// a NEW path plus the canonical text of what WordPress holds — never a merge.

// One document per serializer, each spelled the way that serializer's own suite
// pins as representable: Markdown carries the heading anchor, the blocks-engine
// HTML transformer does not.
const MARKDOWN_BLOCKS =
  '<!-- wp:heading {"level":1} -->\n' +
  '<h1 class="wp-block-heading" id="hello-world">Hello world</h1>\n' +
  "<!-- /wp:heading -->\n\n" +
  "<!-- wp:paragraph -->\n<p>Written in the editor.</p>\n<!-- /wp:paragraph -->\n";
const HTML_BLOCKS =
  '<!-- wp:heading {"level":1} -->\n' +
  '<h1 class="wp-block-heading">Hello world</h1>\n' +
  "<!-- /wp:heading -->\n\n" +
  "<!-- wp:paragraph -->\n<p>Written in the editor.</p>\n<!-- /wp:paragraph -->\n";

function digest(text: string) {
  return `sha256:${new Bun.CryptoHasher("sha256").update(text).digest("hex")}`;
}

test("an editor-created page materializes once under canonical pages", async () => {
  const [, first, second, unmanaged] = await runScenario("md", [
    { op: "createInWordPress", slug: "hello-world", blocks: MARKDOWN_BLOCKS, postType: "page" },
    { op: "materialize", target: "post" },
    { op: "materialize", target: "post" },
    { op: "materialize", target: "post", managed: false },
  ]);

  const prepared = materialized(first);
  expect(prepared.status).toBe("materialized");
  // The glob's directory and suffix decide the path; the slug is the post's own.
  expect(prepared.sourceWrite.source).toBe("pages/hello-world.md");
  // A new file: the compare-and-swap the drain performs is "nothing is there".
  expect(prepared.sourceWrite.expectedSourceRevision).toBe("absent");
  expect(prepared.sourceWrite.state).toBe("prepared");
  expect(prepared.sourceWrite.text).toContain("# Hello world");
  expect(prepared.sourceWrite.text).toContain("Written in the editor.");
  expect(prepared.sourceWrite.textDigest).toBe(digest(prepared.sourceWrite.text));

  // A post that already has a path keeps it: the second call mints nothing.
  const repeated = materialized(second);
  expect(repeated.status).toBe("skipped");
  expect(repeated.sourceWrite.source).toBe(prepared.sourceWrite.source);

  expect(problem(unmanaged).code).toBe("content_auth_required");
});

test("a document richer than its materialization format is refused, not flattened", async () => {
  const [, refused] = await runScenario("md", [
    {
      op: "createInWordPress",
      slug: "styled",
      // `align` and `className` do not survive a Markdown round trip, so this
      // document has no honest Markdown spelling — the existing representability
      // gate is what says so, and materialization must not get a second one.
      blocks:
        '<!-- wp:paragraph {"align":"center","className":"lead"} -->\n' +
        '<p class="has-text-align-center lead">Styled in the editor.</p>\n' +
        "<!-- /wp:paragraph -->\n",
    },
    { op: "materialize", target: "post" },
  ]);
  expect(problem(refused).code).toBe("content_markdown_not_representable");
});

test("the editor taking over a compiled page materializes it as HTML beside the source", async () => {
  const [, taken] = await runScenario("md", [
    { op: "createInWordPress", slug: "about", blocks: HTML_BLOCKS, postType: "page", bound: true },
    { op: "materialize", target: "binding" },
  ]);

  const prepared = materialized(taken);
  expect(prepared.status).toBe("materialized");
  // HTML, not Markdown: a page authored as code must not be handed back in the
  // format most likely to refuse it. The takeover file sits beside the source it
  // supersedes, same directory and slug, different extension.
  expect(TSX_SOURCE).toBe("pages/docs/about.tsx");
  expect(prepared.sourceWrite.source).toBe("pages/docs/about.html");
  expect(prepared.sourceWrite.expectedSourceRevision).toBe("absent");
  expect(prepared.sourceWrite.text).toContain('<h1 class="wp-block-heading">Hello world</h1>');
  expect(prepared.sourceWrite.text).toContain("Written in the editor.");

  // The wire between the two halves, pinned in one place. The scenario drove the
  // engine with the exact body `materializeRuntimeContentSource` sends — a
  // `postId` beside the `bindingId` on the takeover — and what came back has to
  // be what the drain's own schema accepts, field for field, or the drain
  // rejects a receipt the engine considers well formed.
  expect(syncMaterializeReceiptV1Schema.parse(prepared)).toEqual(prepared);
});

test("canonical document lookup is binding-scoped and source adoption is explicit", async () => {
  const results = await runScenario("md", [
    { op: "createInWordPress", slug: "about", blocks: HTML_BLOCKS, postType: "page" },
    {
      op: "createInWordPress",
      slug: "about",
      blocks: HTML_BLOCKS,
      postType: "page",
      bound: true,
      spaceId: "spc_other",
    },
    { op: "lookupPage" },
    {
      op: "createInWordPress",
      slug: "about",
      blocks: HTML_BLOCKS,
      postType: "page",
      source: TSX_SOURCE,
    },
    { op: "lookupPage", adopt: true },
    { op: "lookupPage" },
    {
      op: "createInWordPress",
      slug: "unrelated-slug",
      blocks: HTML_BLOCKS,
      postType: "page",
      bound: true,
    },
    { op: "lookupPage" },
    {
      op: "createInWordPress",
      slug: "duplicate",
      blocks: HTML_BLOCKS,
      postType: "page",
      bound: true,
    },
    { op: "lookupPage" },
  ]);
  expect(results[2]).toEqual({
    ok: true,
    receipt: { format: "test.driver", status: "lookup", postId: null },
  });
  expect(results[4]).toEqual({
    ok: true,
    receipt: { format: "test.driver", status: "lookup", postId: 102 },
  });
  expect(results[5]).toEqual(results[2]);
  expect(results[7]).toEqual({
    ok: true,
    receipt: { format: "test.driver", status: "lookup", postId: 103 },
  });
  expect(problem(results[9])).toMatchObject({ code: "content_document_identity_conflict" });
});
