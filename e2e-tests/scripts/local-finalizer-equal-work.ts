import { existsSync } from "node:fs";
import path from "node:path";

import {
  buildManySmallPagesCorpus,
  buildMarkdownHeavyCorpus,
  runCorpusParity,
  type CorpusParityResult,
} from "./finalizer-equal-work-parity.ts";

const options = parseArgs(process.argv.slice(2));
if (!existsSync(options.binary)) {
  throw new Error(
    `runtime compiler not found at ${options.binary}; build it with cargo build --locked -p stattic-runtime-compiler or pass --binary`,
  );
}

const corpora = [
  buildMarkdownHeavyCorpus(options.markdownPages, options.paragraphsPerPage),
  buildManySmallPagesCorpus(options.smallPages),
];
const results: CorpusParityResult[] = [];
for (const corpus of corpora) {
  process.stderr.write(`finalizer equal-work: ${corpus.name}\n`);
  results.push(await runCorpusParity({ corpus, rustBinary: options.binary }));
}

const output = {
  format: "spacefast.finalizer.equal-work.v4",
  rustInputFormat: "stattic.runtime.finalize.input.v2",
  rustBinary: options.binary,
  fixture: {
    markdownPages: options.markdownPages,
    paragraphsPerMarkdownPage: options.paragraphsPerPage,
    smallPages: options.smallPages,
  },
  equal: results.every((result) => result.equal),
  results,
};
process.stdout.write(`${JSON.stringify(output, null, 2)}\n`);
if (!output.equal) process.exitCode = 1;

function parseArgs(args: string[]): {
  binary: string;
  markdownPages: number;
  paragraphsPerPage: number;
  smallPages: number;
} {
  const values = new Map<string, string>();
  for (let index = 0; index < args.length; index += 2) {
    const flag = args[index];
    const value = args[index + 1];
    if (!flag?.startsWith("--") || value === undefined) throw new Error(usage());
    values.set(flag, value);
  }
  for (const flag of values.keys()) {
    if (!["--binary", "--markdown-pages", "--paragraphs", "--small-pages"].includes(flag)) {
      throw new Error(`unknown argument ${flag}\n${usage()}`);
    }
  }
  return {
    binary: path.resolve(
      values.get("--binary") ??
        process.env.SPACEFAST_RUNTIME_FINALIZER_BIN ??
        path.join(import.meta.dir, "../../target/debug/stattic-runtime-compiler"),
    ),
    markdownPages: integerOption(values.get("--markdown-pages") ?? "24", "--markdown-pages"),
    paragraphsPerPage: integerOption(values.get("--paragraphs") ?? "8", "--paragraphs"),
    smallPages: integerOption(values.get("--small-pages") ?? "250", "--small-pages"),
  };
}

function integerOption(value: string, flag: string): number {
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed < 1)
    throw new Error(`${flag} must be a positive integer`);
  return parsed;
}

function usage(): string {
  return "usage: bun scripts/local-finalizer-equal-work.ts [--binary <path>] [--markdown-pages <n>] [--paragraphs <n>] [--small-pages <n>]";
}
