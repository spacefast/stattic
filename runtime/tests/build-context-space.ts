import { mkdir, mkdtemp, writeFile } from "node:fs/promises";
import path from "node:path";

import { ZERO_PUBLIC_CLIENT_SHELL_PATH } from "@spacefast/common/contracts/zero";

import { runZeroInit, runZeroBuildForRoot } from "../../packages/cli/src/zero-commands.js";
import { assembleZeroCapsulePublish } from "../../packages/zero-compile/src/capsule.js";

const output = path.resolve(process.argv[2] ?? ".cache/context-acceptance");
await mkdir(output, { recursive: true });
const source = await mkdtemp(path.join(output, "space-"));
await runZeroInit({
  args: {},
  flags: { template: "context", "no-git": true, title: "Shared context" },
  cwd: source,
});
const compiled = await runZeroBuildForRoot({ args: { source }, flags: {}, cwd: source });
const published = assembleZeroCapsulePublish({
  finalizePayload: compiled.data.finalizePayload,
  contentModel: compiled.data.contentModel,
  authorFiles: [],
});
const publicRoot = path.join(source, "public");
await mkdir(publicRoot, { recursive: true });
for (const file of published.publishFiles) {
  const destination = path.join(publicRoot, file.path);
  await mkdir(path.dirname(destination), { recursive: true });
  await writeFile(destination, file.bytes);
}
await writeFile(
  path.join(output, "space-preview.json"),
  JSON.stringify({ publicRoot, source, shellPath: ZERO_PUBLIC_CLIENT_SHELL_PATH }),
);
console.log(`Built actual context Space source: ${source}`);
