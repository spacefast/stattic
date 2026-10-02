// Regenerates the editor-saved fixtures beside this file:
//   bun runtime/tests/fixtures/content-markdown-editor/generate.ts
//
// Each fixture is what the block editor stores after an author opens
// document.md's WordPress document, makes one edit, and saves. The markup is
// written by the editor's own parser and serializer (@wordpress/blocks with the
// core block library registered), so the representability tests check the
// spelling WordPress actually stores. The editor re-spells a document on every
// save: whitespace, default attributes, attribute order, `anchor`, and a code
// block's line breaks (`<br>` on import, newlines once its text is touched). A
// hand-written fixture leaves all of that out, so it passes even when real
// edits fail. The .html files are excluded from oxfmt for the same reason.
//
// "legacy-*" starts from the importer's markup before heading ids were dropped
// from storage. That is the shape documents created by earlier engines still
// have in WordPress.
import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
import path from "node:path";

type EditorBlock = { readonly name: string; readonly isValid: boolean };
type RichTextValue = { readonly toHTMLString: (options: { preserveWhiteSpace: true }) => string };
type EditorAttributes = { readonly content?: string | RichTextValue; readonly anchor?: string };
type AttributeSchema = { readonly type?: string };
type BlockSettings = { attributes?: { readonly [name: string]: AttributeSchema } };
type SaveProps = { id?: string | null };
type BlockApi = {
  parse(markup: string): EditorBlock[];
  serialize(blocks: readonly EditorBlock[]): string;
  cloneBlock(block: EditorBlock, attributes: EditorAttributes): EditorBlock;
  createBlock(name: string, attributes: EditorAttributes): EditorBlock;
  hasBlockSupport(block: BlockSettings | string, feature: string): boolean;
};
type HooksApi = {
  addFilter(
    hook: string,
    namespace: string,
    callback: (...values: never[]) => BlockSettings | SaveProps,
  ): void;
};

const here = import.meta.dir;
const repoRoot = path.resolve(here, "../../../..");
const resolveFrom = (workspace: string, id: string) =>
  Bun.resolveSync(id, path.join(repoRoot, workspace));

const blocksEntry = resolveFrom("packages/zero-compile", "@wordpress/blocks");
const libraryEntry = resolveFrom("packages/zero-compile", "@wordpress/block-library");
// The one hooks instance @wordpress/blocks registers through.
const hooksEntry = Bun.resolveSync("@wordpress/hooks", path.dirname(blocksEntry));
// The rich-text values the parser sources a code block's content into.
const richTextEntry = Bun.resolveSync("@wordpress/rich-text", path.dirname(blocksEntry));
const { GlobalRegistrator } = await import(resolveFrom("apps/my", "@happy-dom/global-registrator"));

GlobalRegistrator.register();
// hpq, the parser's attribute sourcing, asks
// `Object.prototype.hasOwnProperty.call(element.attributes, name)`. A browser
// answers true for a present attribute. happy-dom does not, so without this
// every sourced attribute reads as absent.
const attributesGetter = Object.getOwnPropertyDescriptor(Element.prototype, "attributes")?.get;
if (attributesGetter === undefined) throw new Error("happy-dom moved Element#attributes");
Object.defineProperty(Element.prototype, "attributes", {
  configurable: true,
  get(this: Element) {
    const attributes: NamedNodeMap = attributesGetter.call(this);
    return new Proxy(attributes, {
      getOwnPropertyDescriptor(target, key) {
        const attribute = target.getNamedItem(String(key));
        return attribute === null
          ? Reflect.getOwnPropertyDescriptor(target, key)
          : { value: attribute, configurable: true, enumerable: true, writable: false };
      },
    });
  },
});

const hooks: HooksApi = await import(hooksEntry);
const blocks: BlockApi = await import(blocksEntry);
const richText: {
  RichTextData: {
    fromHTMLString(html: string, options: { preserveWhiteSpace: true }): RichTextValue;
  };
} = await import(richTextEntry);
// Anchor support, as @wordpress/block-editor registers it (src/hooks/anchor.js).
// The editor always loads block-editor. A bare block library would not have
// these two filters.
hooks.addFilter("blocks.registerBlockType", "core/anchor/attribute", (settings: BlockSettings) => {
  if (settings.attributes?.anchor?.type !== undefined) return settings;
  if (blocks.hasBlockSupport(settings, "anchor")) {
    settings.attributes = { ...settings.attributes, anchor: { type: "string" } };
  }
  return settings;
});
hooks.addFilter(
  "blocks.getSaveContent.extraProps",
  "core/anchor/save-props",
  (extraProps: SaveProps, blockType: BlockSettings, attributes: EditorAttributes) => {
    if (blocks.hasBlockSupport(blockType, "anchor")) {
      extraProps.id = attributes.anchor === "" ? null : attributes.anchor;
    }
    return extraProps;
  },
);
const library: { registerCoreBlocks(): void } = await import(libraryEntry);
library.registerCoreBlocks();

function imported(
  fn: "spacefast_content_markdown_to_blocks" | "spacefast_content_markdown_consume",
) {
  const script = [
    "declare(strict_types=1);",
    "final class Spacefast_Content_Error extends RuntimeException {",
    "  public function __construct(public readonly int $status, public readonly string $codeName, string $message) { parent::__construct($message); }",
    "}",
    `$GLOBALS['SPACEFAST_CONTENT_PHP_TOOLKIT_PHAR'] = ${JSON.stringify(path.join(repoRoot, "runtime/engine/vendor/php-toolkit.phar"))};`,
    `require ${JSON.stringify(path.join(repoRoot, "runtime/engine/wordpress/content-markdown.php"))};`,
    `echo ${fn}(file_get_contents(${JSON.stringify(path.join(here, "document.md"))}));`,
  ].join("\n");
  return execFileSync(process.env.PHP_BINARY ?? "php", ["-r", script], { encoding: "utf8" });
}

function nth(list: readonly EditorBlock[], name: string, n: number): number {
  const index = list.flatMap((block, at) => (block.name === name ? [at] : []))[n];
  if (index === undefined) throw new Error(`document.md has no ${name} #${n}`);
  return index;
}

function replaced(list: readonly EditorBlock[], index: number, block: EditorBlock) {
  return list.map((existing, at) => (at === index ? block : existing));
}

function blockAt(list: readonly EditorBlock[], index: number): EditorBlock {
  const block = list[index];
  if (block === undefined) throw new Error(`document.md has no block #${index}`);
  return block;
}

const edits = {
  saved: (list: readonly EditorBlock[]) => list,
  reordered: (list: readonly EditorBlock[]) => {
    const first = nth(list, "core/paragraph", 0);
    const second = nth(list, "core/paragraph", 1);
    return replaced(replaced(list, first, blockAt(list, second)), second, blockAt(list, first));
  },
  renamed: (list: readonly EditorBlock[]) => {
    const heading = nth(list, "core/heading", 1);
    return replaced(
      list,
      heading,
      blocks.cloneBlock(blockAt(list, heading), { content: "Common threats" }),
    );
  },
  inserted: (list: readonly EditorBlock[]) => {
    const heading = nth(list, "core/heading", 1);
    return [
      ...list.slice(0, heading),
      blocks.createBlock("core/heading", { content: "Defenses" }),
      blocks.createBlock("core/paragraph", { content: "Patch early." }),
      ...list.slice(heading),
    ];
  },
  bolded: (list: readonly EditorBlock[]) => {
    const paragraph = nth(list, "core/paragraph", 1);
    return replaced(
      list,
      paragraph,
      blocks.cloneBlock(blockAt(list, paragraph), {
        content: "Every system has <strong>one</strong>.",
      }),
    );
  },
  // Typing in a code block replaces the imported `<br>`s with the newlines
  // the editor's rich-text value keeps, and its save escapes `[`.
  coded: (list: readonly EditorBlock[]) => {
    const code = nth(list, "core/code", 0);
    return replaced(
      list,
      code,
      blocks.cloneBlock(blockAt(list, code), {
        content: richText.RichTextData.fromHTMLString(
          "if (user.isAdmin &amp;&amp; !user.mfa[0]) {<br>  deny();<br>}",
          { preserveWhiteSpace: true },
        ),
      }),
    );
  },
  anchored: (list: readonly EditorBlock[]) => {
    const heading = nth(list, "core/heading", 1);
    return replaced(
      list,
      heading,
      blocks.cloneBlock(blockAt(list, heading), { anchor: "threat-list" }),
    );
  },
} satisfies Record<string, (list: readonly EditorBlock[]) => readonly EditorBlock[]>;

function save(markup: string, edit: (list: readonly EditorBlock[]) => readonly EditorBlock[]) {
  const parsed = blocks.parse(markup);
  const invalid = parsed.filter((block) => !block.isValid).map((block) => block.name);
  if (invalid.length > 0) throw new Error(`The editor rejects the imported ${invalid.join(", ")}`);
  return `${blocks.serialize(edit(parsed))}\n`;
}

const stored = imported("spacefast_content_markdown_to_blocks");
const legacy = imported("spacefast_content_markdown_consume");
for (const [name, edit] of Object.entries(edits)) {
  writeFileSync(path.join(here, `${name}.html`), save(stored, edit));
}
writeFileSync(path.join(here, "legacy-reordered.html"), save(legacy, edits.reordered));
writeFileSync(path.join(here, "legacy-renamed.html"), save(legacy, edits.renamed));
