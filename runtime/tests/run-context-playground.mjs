/**
 * Real WordPress/SQLite acceptance. Run through pinned wp-playground-cli, not a
 * database substitute. WordPress is the exact archive the platform component
 * lock pins for every Space.
 */
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { openSync } from "node:fs";
import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { SPACEFAST_WORDPRESS_COMPONENT_RELEASE as release } from "../../packages/common/dist/contracts/runtime-components.js";
import { generateContentModelPhp } from "../../packages/zero-compile/dist/content-model-php.js";
import { compileZeroContentModel } from "../../packages/zero-compile/dist/content-model.js";
import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const output = path.join(root, ".cache/context-acceptance");
await mkdir(output, { recursive: true });
await rm(path.join(output, "receipt.json"), { force: true });
await rm(path.join(output, "failure.txt"), { force: true });
const compiled = await compileZeroContentModel({
  declarations: {
    collections: {
      pages: { fields: { content: { kind: "blocks", source: "content/context.blocks" } } },
    },
  },
  readDirectory: async (directory) => (directory === "content" ? ["context.blocks"] : []),
  readSource: async () => "<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->",
});
assert.ok(compiled);
const generated = await generateContentModelPhp(compiled.artifacts.model);
const spaceId = `spc_${"d".repeat(32)}`;
const modelRoot = `.cache/context-acceptance/.stattic/storage/spaces/${spaceId}/content-model/releases/${compiled.artifacts.model.revision.slice(7)}`;
await mkdir(path.join(root, modelRoot), { recursive: true });
await writeFile(path.join(root, modelRoot, "content-model.php"), generated.php);
await writeFile(path.join(root, modelRoot, "content-model.sha256"), generated.sha256);
await fetchToolkitPhar();
const config = {
  revision: compiled.artifacts.model.revision,
  spaceId,
  modelRoot: `/spacefast/${modelRoot}`,
  bindingId: compiled.artifacts.model.syncBindings[0].id,
};
await writeFile(path.join(output, "config.json"), JSON.stringify(config));

// The kernel loads as a must-use plugin, as on a box: before any request work.
const mu = path.join(output, "mu-plugins");
await mkdir(mu, { recursive: true });
const kernelLoader = (preview) => `<?php
${preview ? "// The engine-supported local-only cookie flag; this is a disposable HTTP preview.\ndefine('SPACEFAST_INSECURE_COOKIES', '1');\n" : ""}$config = json_decode(file_get_contents('/spacefast/.cache/context-acceptance/config.json'), true);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId'];
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = '/spacefast/.cache/context-acceptance/.stattic/storage';
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $config['modelRoot'];
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $config['revision'];
$GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = 'http://127.0.0.1:9419';
require_once '/spacefast/runtime/engine/shared/lock.php';
require_once '/spacefast/runtime/engine/wordpress/content-kernel.php';
${preview ? "require_once '/spacefast/runtime/tests/context-preview-session.php';\n" : ""}`;
await writeFile(path.join(mu, "spacefast-context.php"), kernelLoader(false));
const wordpressMounts = [
  "--mount",
  `${root}:/spacefast`,
  "--mount",
  `${mu}:/wordpress/wp-content/mu-plugins`,
];

const blueprint = {
  preferredVersions: { php: "8.5", wp: release.wordpress.archiveUrl },
  steps: [
    {
      step: "runPHP",
      code: "<?php try { require '/wordpress/wp-load.php'; require '/spacefast/runtime/tests/fixtures/context-roundtrip.php'; } catch (Throwable $error) { file_put_contents('/spacefast/.cache/context-acceptance/failure.txt', get_class($error) . ': ' . $error->getMessage() . '\\n' . $error->getTraceAsString()); exit(1); }",
    },
  ],
};
const blueprintPath = path.join(output, "blueprint.json");
await writeFile(blueprintPath, JSON.stringify(blueprint));
const result = spawnSync(
  "wp-playground-cli",
  ["run-blueprint", "--php", "8.5", "--blueprint", blueprintPath, ...wordpressMounts],
  { cwd: root, stdio: "inherit" },
);
if (result.status !== 0)
  console.error(
    await readFile(path.join(output, "failure.txt"), "utf8").catch(
      () => "No PHP exception receipt was written.",
    ),
  );
assert.equal(result.status, 0, "WordPress blueprint must complete");
const receipt = JSON.parse(await readFile(path.join(output, "receipt.json"), "utf8"));
assert.deepEqual(receipt, {
  saved: 200,
  stale: 409,
  peerSaved: 200,
  nativeBypass: 409,
  foreignSpace: 404,
  unauthorized: 403,
  history: true,
  markdown: true,
  rendered: true,
  source: true,
});
console.log("Real WordPress context round trip:", receipt);

if (process.argv.includes("--serve")) {
  await writeFile(path.join(mu, "spacefast-context.php"), kernelLoader(true));
  const previewBlueprint = {
    ...blueprint,
    steps: blueprint.steps.map((step) =>
      step.step === "runPHP"
        ? {
            ...step,
            code: step.code.replace("<?php ", "<?php define('SPACEFAST_CONTEXT_PREVIEW', true); "),
          }
        : step,
    ),
  };
  const build = spawnSync("bun", ["runtime/tests/build-context-space.ts", output], {
    cwd: root,
    stdio: "inherit",
  });
  assert.equal(build.status, 0, "The actual Space must compile before previewing it.");
  const previewPath = path.join(output, "preview.json");
  await writeFile(previewPath, JSON.stringify(previewBlueprint));
  const log = openSync(path.join(output, "preview.log"), "a");
  const server = spawn(
    "wp-playground-cli",
    [
      "server",
      "--php",
      "8.5",
      "--port",
      "9417",
      "--wp",
      release.wordpress.archiveUrl,
      // One worker: parallel workers contend for the one SQLite file and answer
      // with database-error pages instead of JSON. A box runs MySQL; this is a
      // local-preview limit.
      "--workers",
      "1",
      "--blueprint",
      previewPath,
      ...wordpressMounts,
    ],
    { cwd: root, detached: true, stdio: ["ignore", log, log] },
  );
  server.unref();
  await writeFile(path.join(output, "preview.pid"), String(server.pid));
  const deadline = Date.now() + 60000;
  let ready = false;
  while (Date.now() < deadline) {
    try {
      const response = await fetch("http://127.0.0.1:9417/wp-json/spacefast/v1/context", {
        redirect: "manual",
      });
      if (response.ok && (await response.json()).source === "content/context.blocks") {
        ready = true;
        break;
      }
    } catch {
      /* The native server is still starting. */
    }
  }
  assert.equal(ready, true, "The actual WordPress context must be ready before opening its Space.");
  const space = spawn(process.execPath, ["runtime/tests/serve-context-space.mjs"], {
    cwd: root,
    detached: true,
    stdio: ["ignore", log, log],
  });
  space.unref();
  await writeFile(path.join(output, "space-preview.pid"), String(space.pid));
  console.log("Context Space preview: http://127.0.0.1:9419/");
}
