import { createHash } from "node:crypto";
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { NodeType, parse, type HTMLElement, type Node } from "node-html-parser";

import { compileRoutingFiles } from "../../packages/routing/src/compile.ts";
import {
  apiJson,
  createDeclaredSession,
  putFile,
  startRuntime,
  type Runtime,
} from "../../runtime/tests/harness.ts";

const BLOCK_ELEMENTS = new Set([
  "address",
  "article",
  "aside",
  "blockquote",
  "body",
  "details",
  "dialog",
  "div",
  "dl",
  "fieldset",
  "figcaption",
  "figure",
  "footer",
  "form",
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "head",
  "header",
  "hgroup",
  "hr",
  "html",
  "li",
  "main",
  "menu",
  "nav",
  "ol",
  "p",
  "pre",
  "search",
  "section",
  "table",
  "ul",
]);
const WHITESPACE_PRESERVING_ELEMENTS = new Set(["pre", "script", "style", "textarea"]);
const VOLATILE_STRUCTURAL_KEYS = new Set(["generated_at", "generatedAt"]);
const FIXED_GENERATED_AT = "2026-07-18T00:00:00Z";
const FIXED_READY_AT = 1_768_608_000;

type JsonObject = Record<string, unknown>;
type CanonicalNode =
  | ["comment", string]
  | ["element", string, Array<[string, string]>, CanonicalNode[]]
  | ["text", string];

export type FixtureCorpus = {
  name: string;
  files: Record<string, string>;
  metadata: JsonObject;
  finalizeBody: JsonObject;
  declaredUriMarkdownDeltas: DeclaredUriMarkdownDelta[];
};

export type DeclaredUriMarkdownDelta = {
  source: string;
  rendered: string;
};

export type ParitySnapshot = {
  digest: string;
  files: Record<string, string>;
};

export type ParityDiff = {
  file: string;
  baseline: string | null;
  candidate: string | null;
  detail: string;
};

export type CorpusParityResult = {
  corpus: string;
  baselineKind: "runtime-adapter-native-rust";
  // Historical v4 field names retained for consumers of the benchmark JSON.
  // `php*` now means the production PHP adapter invoking the native finalizer.
  phpDigest: string;
  rustDigest: string;
  equal: boolean;
  comparedFileCount: number;
  diffs: ParityDiff[];
  phpOutput: JsonObject;
  phpDiagnostics: unknown[];
  rustOutput: JsonObject;
  phpRenderedLink: RenderedMarkupProof | null;
  rustRenderedLink: RenderedMarkupProof | null;
  phpRenderedImage: RenderedMarkupProof | null;
  rustRenderedImage: RenderedMarkupProof | null;
  declaredUriMarkdownDeltas: Array<DeclaredUriMarkdownDelta & RenderedUriProof>;
};

type RenderedMarkupProof = {
  file: string;
  line: string;
};

type RenderedUriProof = {
  link: RenderedMarkupProof;
  image: RenderedMarkupProof;
};

/*
 * -----------------------------------------------------------------------------
 * NATIVE URI-BEARING MARKDOWN PROOF
 * -----------------------------------------------------------------------------
 * The pre-cutover benchmark treated these source/rendered pairs as a declared
 * delta because the retired PHP finalizer could not render them. After the
 * native-only cutover, both the production PHP adapter and direct compiler
 * invocation must render the link and image and produce identical complete
 * snapshots. This keeps the equal-work harness useful without restoring a
 * dead production finalizer lane.
 * -----------------------------------------------------------------------------
 */

export function normalizeRenderedPage(html: string): string {
  return html.replaceAll("\r\n", "\n").replaceAll("\r", "\n");
}

export function renderedPageDigest(pages: string[]): string {
  return sha256(JSON.stringify(pages.map((page) => canonicalizeRenderedPage(page))));
}

export function normalizeStructuralOutput(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(normalizeStructuralOutput);
  if (!isObject(value)) return value;

  const normalized: JsonObject = {};
  for (const key of Object.keys(value).toSorted()) {
    if (VOLATILE_STRUCTURAL_KEYS.has(key)) continue;
    normalized[key] = normalizeStructuralOutput(value[key]);
  }
  return normalized;
}

export function createParitySnapshot(files: Record<string, unknown>): ParitySnapshot {
  const canonicalFiles: Record<string, string> = {};
  for (const file of Object.keys(files).toSorted()) {
    canonicalFiles[file] = stableJson(files[file]);
  }
  return {
    digest: sha256(JSON.stringify(canonicalFiles)),
    files: canonicalFiles,
  };
}

export function compareParitySnapshots(
  baseline: ParitySnapshot,
  candidate: ParitySnapshot,
): ParityDiff[] {
  const names = [
    ...new Set([...Object.keys(baseline.files), ...Object.keys(candidate.files)]),
  ].toSorted();
  const diffs: ParityDiff[] = [];
  for (const file of names) {
    const left = baseline.files[file] ?? null;
    const right = candidate.files[file] ?? null;
    if (left === right) continue;
    diffs.push({ file, baseline: left, candidate: right, detail: lineDiff(left, right) });
  }
  return diffs;
}

export function buildMarkdownHeavyCorpus(pageCount = 24, paragraphsPerPage = 8): FixtureCorpus {
  assertPositiveInteger(pageCount, "markdown page count");
  assertPositiveInteger(paragraphsPerPage, "paragraphs per markdown page");
  const files: Record<string, string> = {
    "_layout.html":
      '<!doctype html><html><head><meta charset="utf-8"><title>{{ page.title }}</title></head><body><main>{{ content }}</main></body></html>\n',
    _redirects: ["/legacy /docs/page-00000/ 301", "/guides/* /docs/:splat 302", ""].join("\n"),
    _headers: [
      "/docs/*",
      "  X-Equal-Work: markdown-heavy",
      "  Cache-Control: public, max-age=60",
      "",
    ].join("\n"),
    "404.html": "<!doctype html><title>Root not found</title><p>root-404</p>\n",
    "docs/404.html": "<!doctype html><title>Docs not found</title><p>docs-404</p>\n",
    "fallback.html": "<!doctype html><title>Fallback</title><p>fallback-page</p>\n",
  };
  for (let page = 0; page < pageCount; page += 1) {
    const padded = String(page).padStart(5, "0");
    const paragraphs = Array.from({ length: paragraphsPerPage }, (_, paragraph) =>
      [
        `PARA_${page}_${paragraph}`,
        `*EM_${page}_${paragraph}*`,
        `**STRONG_${page}_${paragraph}**`,
        `\`CODE_${page}_${paragraph}\``,
        `[LINK_${page}_${paragraph}](https://example.test/${page}/${paragraph})`,
        `![IMAGE_${page}_${paragraph}](/assets/image-${page}-${paragraph}.png)`,
      ].join(" "),
    );
    files[`docs/page-${padded}.md`] = [
      "---",
      `title: Page ${page}`,
      "description: Equal-work markdown fixture",
      "---",
      "",
      `# Heading ${page}`,
      "",
      ...paragraphs.flatMap((paragraph) => [paragraph, ""]),
    ].join("\n");
  }
  const corpus = fixtureCorpus("markdown-heavy", files, {
    experimental_gutenberg: true,
    index: "index.html",
    listing: false,
    viewer: false,
    fallback: { path: "fallback.html", status: 404 },
  });
  corpus.declaredUriMarkdownDeltas = Array.from({ length: pageCount }, (_, page) => {
    const padded = String(page).padStart(5, "0");
    return {
      source: `docs/page-${padded}.md`,
      rendered: `docs/page-${padded}/index.html`,
    };
  });
  return corpus;
}

export function buildManySmallPagesCorpus(pageCount = 250): FixtureCorpus {
  assertPositiveInteger(pageCount, "small page count");
  const files: Record<string, string> = {
    _redirects: ["/old-home / 308", "/old-docs/* /pages/:splat 301", ""].join("\n"),
    _headers: [
      "/*",
      "  X-Equal-Work: many-small-pages",
      "/pages/private/*",
      "  Cache-Control: private, max-age=0",
      "",
    ].join("\n"),
    "index.html": "<!doctype html><title>Small pages</title><h1>Small pages</h1>\n",
    "404.html": "<!doctype html><title>Root 404</title><p>root-small-404</p>\n",
    "pages/404.html": "<!doctype html><title>Pages 404</title><p>pages-small-404</p>\n",
  };
  for (let page = 0; page < pageCount; page += 1) {
    const padded = String(page).padStart(5, "0");
    files[`pages/bucket-${page % 17}/page-${padded}/index.html`] =
      `<!doctype html><title>Small ${page}</title><p data-page="${page}">PAGE_${page}</p>\n`;
  }
  return fixtureCorpus("many-small-pages", files, {
    index: "index.html",
    listing: false,
    viewer: false,
    fallback: null,
  });
}

export async function runCorpusParity(options: {
  corpus: FixtureCorpus;
  rustBinary: string;
}): Promise<CorpusParityResult> {
  const tempRoot = mkdtempSync(
    path.join(os.tmpdir(), `spacefast-finalizer-parity-${options.corpus.name}-`),
  );
  const rustPrivateRoot = path.join(tempRoot, "rust-storage");
  const rustOutputPath = path.join(tempRoot, "rust-output.json");
  const spaceId = `spc_parity_${safeId(options.corpus.name)}`;
  const versionId = `ver_parity_${safeId(options.corpus.name)}`;
  let runtime: Runtime | null = null;
  try {
    runtime = await startRuntime({
      env: { SPACEFAST_RUNTIME_COMPILER: "", SPACEFAST_RUNTIME_COMPILER_BIN: "" },
    });
    const { uploadId, token } = await createDeclaredSession(
      runtime,
      spaceId,
      versionId,
      options.corpus.files,
      options.corpus.metadata,
    );
    for (const [file, content] of Object.entries(options.corpus.files)) {
      const response = await putFile(runtime, uploadId, token, file, content);
      if (response.status !== 200) {
        throw new Error(
          `php_fixture_upload_failed:${file}:${response.status}:${await response.text()}`,
        );
      }
    }
    const sessionPath = path.join(
      runtime.storageRoot,
      "runtime",
      "uploads",
      uploadId,
      "session.json",
    );
    const session = readJson(sessionPath);
    const body = { upload_id: uploadId, ...options.corpus.finalizeBody };
    mirrorUploadForRust(runtime.storageRoot, rustPrivateRoot, uploadId);
    const phpOutput = await apiJson<JsonObject>(
      runtime,
      "POST",
      `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/finalize`,
      "finalize_version",
      { space_id: spaceId, version_id: versionId },
      body,
    );

    const rustInputPath = path.join(tempRoot, "rust-input.json");
    writeFileSync(
      rustInputPath,
      `${JSON.stringify(
        {
          format: "stattic.runtime.finalize.input.v2",
          versionRoot: rustPrivateRoot,
          spaceId,
          versionId,
          uploadId,
          previousPack: null,
          generatedAt: FIXED_GENERATED_AT,
          readyAt: FIXED_READY_AT,
          session,
          body,
          zeroEndpoints: [],
          zeroRuns: [],
        },
        null,
        2,
      )}\n`,
    );
    runRustFinalizer(options.rustBinary, rustInputPath, rustOutputPath);
    const rustOutput = readJson(rustOutputPath);
    assertRustOutput(rustOutput);

    const phpVersionRoot = path.join(runtime.storageRoot, "spaces", spaceId, "versions", versionId);
    const rustVersionRoot = path.join(rustPrivateRoot, "spaces", spaceId, "versions", versionId);
    const phpSnapshot = snapshotFinalizedVersion(phpVersionRoot);
    const rustSnapshot = snapshotFinalizedVersion(rustVersionRoot);
    const phpMetadata = readJson(path.join(phpVersionRoot, "metadata.json"));
    const phpDiagnostics = Array.isArray(phpMetadata.diagnostics) ? phpMetadata.diagnostics : [];
    assertDeclaredRustUriRendering(phpVersionRoot, options.corpus.declaredUriMarkdownDeltas);
    const declaredUriMarkdownDeltas = assertDeclaredRustUriRendering(
      rustVersionRoot,
      options.corpus.declaredUriMarkdownDeltas,
    );
    const diffs = compareParitySnapshots(phpSnapshot, rustSnapshot);
    return {
      corpus: options.corpus.name,
      baselineKind: "runtime-adapter-native-rust",
      phpDigest: phpSnapshot.digest,
      rustDigest: rustSnapshot.digest,
      equal: diffs.length === 0,
      comparedFileCount: new Set([
        ...Object.keys(phpSnapshot.files),
        ...Object.keys(rustSnapshot.files),
      ]).size,
      diffs,
      phpOutput,
      phpDiagnostics,
      rustOutput,
      phpRenderedLink: renderedMarkupProof(phpVersionRoot, "<a href="),
      rustRenderedLink: renderedMarkupProof(rustVersionRoot, "<a href="),
      phpRenderedImage: renderedMarkupProof(phpVersionRoot, "<img src="),
      rustRenderedImage: renderedMarkupProof(rustVersionRoot, "<img src="),
      declaredUriMarkdownDeltas,
    };
  } finally {
    runtime?.stop();
    rmSync(tempRoot, { recursive: true, force: true });
  }
}

function fixtureCorpus(
  name: string,
  files: Record<string, string>,
  config: JsonObject,
): FixtureCorpus {
  const routing = compileRoutingFiles({ redirects: files._redirects, headers: files._headers });
  const routingErrors = routing.diagnostics.filter((diagnostic) => diagnostic.severity === "error");
  if (routingErrors.length > 0) {
    throw new Error(`invalid_parity_fixture_routing:${name}:${JSON.stringify(routingErrors)}`);
  }
  const servingRules = runtimeServingRules(routing.redirects, routing.headers);
  return {
    name,
    files,
    metadata: {
      title: `Equal-work ${name}`,
      description: `Finalizer parity fixture: ${name}`,
      spa: false,
    },
    finalizeBody: {
      ready_at: FIXED_READY_AT,
      convention_files: { redirects: files._redirects, headers: files._headers },
      serving: { config, ...servingRules, zero_endpoints: [], zero_runs: [] },
      zero_mode: "activating",
    },
    declaredUriMarkdownDeltas: [],
  };
}

function runtimeServingRules(
  redirects: ReturnType<typeof compileRoutingFiles>["redirects"],
  headers: ReturnType<typeof compileRoutingFiles>["headers"],
): JsonObject {
  const redirectsExact: Record<string, JsonObject[]> = {};
  const redirectsPattern: JsonObject[] = [];
  for (const [order, rule] of redirects.entries()) {
    const indexedRule = { ...rule, order };
    if (rule.match === "exact") {
      (redirectsExact[rule.source] ??= []).push(indexedRule);
    } else {
      redirectsPattern.push(indexedRule);
    }
  }

  const headersExact: Record<string, JsonObject[]> = {};
  const headersPattern: JsonObject[] = [];
  for (const [order, rule] of headers.entries()) {
    const indexedRule = { ...rule, order };
    if (rule.regex === `^${escapeRegexLiteral(rule.path)}$`) {
      (headersExact[rule.path] ??= []).push(indexedRule);
    } else {
      headersPattern.push(indexedRule);
    }
  }
  return {
    redirects_exact: redirectsExact,
    redirects_pattern: redirectsPattern,
    headers_exact: headersExact,
    headers_pattern: headersPattern,
  };
}

function escapeRegexLiteral(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function snapshotFinalizedVersion(versionRoot: string): ParitySnapshot {
  const serving = readPhpArray(path.join(versionRoot, "serving.php"));
  const routeIndex = readPhpArray(path.join(versionRoot, "php-manifest.php"));
  const headers = readPhpArray(path.join(versionRoot, "headers.php"));
  const redirects = readPhpArray(path.join(versionRoot, "redirects.php"));
  if (!isObject(serving)) throw new Error(`invalid_serving_artifact:${versionRoot}`);
  const snapshotFiles: Record<string, unknown> = {
    "structure/serving.json": normalizeStructuralOutput(serving),
    "structure/route-index.json": normalizeStructuralOutput(routeIndex),
    "structure/lookup-map.json": normalizeStructuralOutput(serving.lookup),
    "structure/redirects.json": normalizeStructuralOutput(redirects),
    "structure/headers.json": normalizeStructuralOutput(headers),
    "structure/fallback-nearest-404.json": normalizeStructuralOutput({
      fallback: serving.fallback,
      nearest_404: serving.nearest_404,
      not_found: serving.not_found,
    }),
  };
  const publicFiles = Array.isArray(serving.public_files) ? serving.public_files : [];
  for (const file of publicFiles) {
    if (typeof file !== "string" || !file.toLowerCase().endsWith(".html")) continue;
    const filePath = path.join(versionRoot, "files", file);
    const html = readFileSync(filePath, "utf8");
    snapshotFiles[`pages/${file}.json`] = canonicalizeRenderedPage(html);
  }
  return createParitySnapshot(snapshotFiles);
}

function assertDeclaredRustUriRendering(
  versionRoot: string,
  declaredDeltas: DeclaredUriMarkdownDelta[],
): Array<DeclaredUriMarkdownDelta & RenderedUriProof> {
  return declaredDeltas.map((delta) => {
    const html = readFileSync(path.join(versionRoot, "files", delta.rendered), "utf8");
    const link = markupProofForFile(delta.rendered, html, "<a href=");
    const image = markupProofForFile(delta.rendered, html, "<img src=");
    if (link === null || image === null) {
      throw new Error(
        `rust_declared_uri_markdown_not_rendered:${delta.rendered}:link=${link !== null}:image=${image !== null}`,
      );
    }
    return { ...delta, link, image };
  });
}

function renderedMarkupProof(versionRoot: string, needle: string): RenderedMarkupProof | null {
  const serving = readPhpArray(path.join(versionRoot, "serving.php"));
  if (!isObject(serving) || !Array.isArray(serving.public_files)) return null;
  for (const file of serving.public_files) {
    if (typeof file !== "string" || !file.toLowerCase().endsWith(".html")) continue;
    const html = readFileSync(path.join(versionRoot, "files", file), "utf8");
    const proof = markupProofForFile(file, html, needle);
    if (proof !== null) return proof;
  }
  return null;
}

function markupProofForFile(
  file: string,
  html: string,
  needle: string,
): RenderedMarkupProof | null {
  const line = html.split("\n").find((candidate) => candidate.includes(needle));
  return line === undefined ? null : { file, line: line.trim() };
}

function runRustFinalizer(binary: string, inputPath: string, outputPath: string): void {
  const process = Bun.spawnSync({
    cmd: [binary, "finalize", "--input", inputPath, "--output", outputPath],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (process.exitCode !== 0) {
    throw new Error(
      `rust_finalize_failed:exit=${process.exitCode}:stdout=${process.stdout.toString()}:stderr=${process.stderr.toString()}`,
    );
  }
}

function assertRustOutput(output: JsonObject): void {
  if (output.format !== "stattic.runtime.finalize.output.v2") {
    throw new Error(`rust_finalize_output_format_invalid:${String(output.format)}`);
  }
  const diagnostics = Array.isArray(output.diagnostics) ? output.diagnostics : [];
  const errors = diagnostics.filter(
    (diagnostic) => isObject(diagnostic) && diagnostic.severity === "error",
  );
  if (errors.length > 0) throw new Error(`rust_finalize_diagnostics:${JSON.stringify(errors)}`);
}

export function mirrorUploadForRust(
  phpPrivateRoot: string,
  rustPrivateRoot: string,
  uploadId: string,
): void {
  const relativeUploadRoot = path.join("runtime", "uploads", uploadId);
  const source = path.join(phpPrivateRoot, relativeUploadRoot);
  const target = path.join(rustPrivateRoot, relativeUploadRoot);
  mkdirSync(path.dirname(target), { recursive: true });
  cpSync(source, target, { recursive: true });
}

function readPhpArray(file: string): unknown {
  const process = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      "$value = include $argv[1]; echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);",
      file,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (process.exitCode !== 0) {
    throw new Error(`php_artifact_read_failed:${file}:${process.stderr.toString()}`);
  }
  return JSON.parse(process.stdout.toString()) as unknown;
}

function readJson(file: string): JsonObject {
  const parsed = JSON.parse(readFileSync(file, "utf8")) as unknown;
  if (!isObject(parsed)) throw new Error(`json_object_expected:${file}`);
  return parsed;
}

function canonicalizeRenderedPage(html: string): CanonicalNode[] {
  const root = parse(normalizeRenderedPage(html), { comment: true });
  return canonicalizeChildren(root);
}

function canonicalizeChildren(parent: HTMLElement): CanonicalNode[] {
  const nodes: CanonicalNode[] = [];
  for (const [index, node] of parent.childNodes.entries()) {
    if (isIgnorableBlockFormattingWhitespace(parent, node, index)) continue;
    nodes.push(canonicalizeNode(node));
  }
  return nodes;
}

function canonicalizeNode(node: Node): CanonicalNode {
  if (node.nodeType === NodeType.TEXT_NODE) return ["text", node.rawText];
  if (node.nodeType === NodeType.COMMENT_NODE) return ["comment", node.rawText];
  const element = node as HTMLElement;
  return [
    "element",
    element.rawTagName.toLowerCase(),
    Object.entries(element.attributes).toSorted(([left], [right]) => left.localeCompare(right)),
    canonicalizeChildren(element),
  ];
}

function isIgnorableBlockFormattingWhitespace(
  parent: HTMLElement,
  node: Node,
  index: number,
): boolean {
  if (node.nodeType !== NodeType.TEXT_NODE || !/^\s+$/.test(node.rawText)) return false;
  if (WHITESPACE_PRESERVING_ELEMENTS.has(parent.rawTagName?.toLowerCase() ?? "")) return false;
  const previous = parent.childNodes[index - 1];
  const next = parent.childNodes[index + 1];
  return isBlockElement(previous) || isBlockElement(next);
}

function isBlockElement(node: Node | undefined): boolean {
  return (
    node?.nodeType === NodeType.ELEMENT_NODE && BLOCK_ELEMENTS.has(node.rawTagName.toLowerCase())
  );
}

function stableJson(value: unknown): string {
  return `${JSON.stringify(normalizeStructuralOutput(value), null, 2)}\n`;
}

function lineDiff(left: string | null, right: string | null): string {
  if (left === null) return "only in candidate";
  if (right === null) return "only in runtime-adapter baseline";
  const leftLines = left.split("\n");
  const rightLines = right.split("\n");
  const length = Math.max(leftLines.length, rightLines.length);
  for (let index = 0; index < length; index += 1) {
    if (leftLines[index] === rightLines[index]) continue;
    return [
      `line ${index + 1}`,
      `- ${leftLines[index] ?? "<missing>"}`,
      `+ ${rightLines[index] ?? "<missing>"}`,
    ].join("\n");
  }
  return "content differs";
}

function sha256(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

function isObject(value: unknown): value is JsonObject {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function assertPositiveInteger(value: number, label: string): void {
  if (!Number.isInteger(value) || value < 1) throw new Error(`${label} must be a positive integer`);
}

function safeId(value: string): string {
  return value.replaceAll(/[^a-z0-9_]/g, "_");
}
