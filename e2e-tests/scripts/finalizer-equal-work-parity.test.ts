import { expect, test } from "bun:test";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  buildManySmallPagesCorpus,
  buildMarkdownHeavyCorpus,
  compareParitySnapshots,
  createParitySnapshot,
  mirrorUploadForRust,
  normalizeRenderedPage,
  normalizeStructuralOutput,
  renderedPageDigest,
} from "./finalizer-equal-work-parity.ts";

test("Rust finalize storage mirrors the PHP upload under the private storage root", () => {
  const tempRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-finalizer-storage-test-"));
  const phpPrivateRoot = path.join(tempRoot, "php-storage");
  const rustPrivateRoot = path.join(tempRoot, "rust-storage");
  const uploadId = "upl_parity";
  const phpUploadRoot = path.join(phpPrivateRoot, "runtime", "uploads", uploadId);
  try {
    mkdirSync(path.join(phpUploadRoot, "files", "docs"), { recursive: true });
    writeFileSync(path.join(phpUploadRoot, "files", "docs", "index.html"), "same bytes");
    writeFileSync(path.join(phpUploadRoot, "session.json"), '{"mode":"declared"}\n');

    mirrorUploadForRust(phpPrivateRoot, rustPrivateRoot, uploadId);

    expect(
      readFileSync(
        path.join(rustPrivateRoot, "runtime", "uploads", uploadId, "files", "docs", "index.html"),
        "utf8",
      ),
    ).toBe("same bytes");
    expect(
      readFileSync(
        path.join(rustPrivateRoot, "runtime", "uploads", uploadId, "session.json"),
        "utf8",
      ),
    ).toBe('{"mode":"declared"}\n');
    expect(existsSync(path.join(rustPrivateRoot, "files"))).toBe(false);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test("fixture corpora compile raw conventions for the runtime adapter baseline", () => {
  const markdown = buildMarkdownHeavyCorpus(3, 2);
  const small = buildManySmallPagesCorpus(5);

  expect(Object.keys(markdown.files).filter((file) => file.endsWith(".md"))).toHaveLength(3);
  expect(Object.keys(small.files).filter((file) => file.includes("/page-"))).toHaveLength(5);
  expect(markdown.finalizeBody).toMatchObject({
    convention_files: { redirects: markdown.files._redirects, headers: markdown.files._headers },
    serving: {
      redirects_exact: { "/legacy": [{ action: "redirect", status: 301 }] },
      headers_pattern: [{ path: "/docs/*" }],
    },
  });
});

test("equal-work parity preserves whitespace text nodes between inline elements", () => {
  const withWordBoundary = normalizeRenderedPage("<p><em>alpha</em> <strong>beta</strong></p>\r\n");
  const withoutWordBoundary = normalizeRenderedPage("<p><em>alpha</em><strong>beta</strong></p>\n");

  expect(withWordBoundary).toBe("<p><em>alpha</em> <strong>beta</strong></p>\n");
  expect(renderedPageDigest([withWordBoundary])).not.toBe(
    renderedPageDigest([withoutWordBoundary]),
  );
});

test("equal-work parity ignores platform line endings and non-rendered block formatting", () => {
  const php = "<main>\r\n<h1>Title</h1>\r\n\r\n<p>One</p>\r\n</main>";
  const rust = "<main><h1>Title</h1>\n<p>One</p>\n</main>";

  expect(renderedPageDigest([php])).toBe(renderedPageDigest([rust]));
});

test("structural normalization removes timestamps but preserves ordered rule arrays", () => {
  const normalized = normalizeStructuralOutput({
    generated_at: "now",
    z: 1,
    rules: [{ destination: "/first" }, { destination: "/second" }],
    nested: { generatedAt: "later", b: 2, a: 1 },
  });

  expect(normalized).toEqual({
    nested: { a: 1, b: 2 },
    rules: [{ destination: "/first" }, { destination: "/second" }],
    z: 1,
  });
});

test("snapshot comparison reports added and changed files independently", () => {
  const baseline = createParitySnapshot({
    "structure/lookup-map.json": { route: "one" },
    "pages/index.html.json": ["baseline"],
  });
  const candidate = createParitySnapshot({
    "structure/lookup-map.json": { route: "two" },
    "pages/index.html.json": ["baseline"],
    "pages/new.html.json": ["candidate"],
  });

  expect(compareParitySnapshots(baseline, candidate).map((diff) => diff.file)).toEqual([
    "pages/new.html.json",
    "structure/lookup-map.json",
  ]);
});
