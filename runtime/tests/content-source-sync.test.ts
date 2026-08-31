// The Markdown half of the source-sync lane, driven through the real pinned
// php-toolkit serializer. The reconciliation machinery it exercises — common
// base, compare-and-swap, three-way merge, acknowledgement — is format-blind
// and is proven here once; the HTML suite next door asserts only what its own
// serializer decides.
import { expect, test } from "bun:test";

import { verifySyncLedgerV1 } from "../../packages/common/src/contracts/content-contract-verification.ts";
import { problem, receipt, runScenario } from "./content-sync.test-helper.ts";

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
