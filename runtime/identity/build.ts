import { createHash } from "node:crypto";
import { mkdir, mkdtemp, readFile, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { unzipSync } from "fflate";
import { z } from "zod";

const root = path.dirname(fileURLToPath(import.meta.url));
const lock = z
  .object({ version: z.string(), sha256: z.string().regex(/^[a-f0-9]{64}$/) })
  .parse(JSON.parse(await readFile(path.join(root, "lock.json"), "utf8")));
const archive = await readFile(path.join(root, "spacefast-identity.zip"));
if (createHash("sha256").update(archive).digest("hex") !== lock.sha256) {
  throw new Error("space_users_component_digest_mismatch");
}
const files = Object.entries(unzipSync(archive));
for (const [name] of files) {
  if (
    !/^spacefast-identity\/(?:[A-Za-z0-9_.@+-]+\/)*[A-Za-z0-9_.@+-]*$/.test(name) ||
    name.split("/").some((part) => part === "." || part === "..")
  ) {
    throw new Error("space_users_component_path_invalid");
  }
}
const output = path.resolve(root, "../wordpress/spacefast-identity");
// Parallel runtime test files each run this build, and their harnesses copy the
// tree while others may still be building. A tree from this exact archive is
// never touched again; a missing or older one is extracted privately and
// swapped in under a short lock, so no reader sees a half-written tree.
// The marker and lock live with the build inputs, outside every shipped tree.
const marker = path.join(root, ".installed-sha256");
const current = async () => (await readFile(marker, "utf8").catch(() => "")).trim() === lock.sha256;
if (!(await current())) {
  await mkdir(path.dirname(output), { recursive: true });
  const staging = await mkdtemp(`${output}.build-`);
  try {
    for (const [name, bytes] of files) {
      const relative = name.slice("spacefast-identity/".length);
      if (!relative || name.endsWith("/")) continue;
      const target = path.join(staging, relative);
      // oxlint-disable-next-line eslint/no-await-in-loop -- bounded archive extraction in deterministic order.
      await mkdir(path.dirname(target), { recursive: true });
      // oxlint-disable-next-line eslint/no-await-in-loop -- bounded archive extraction in deterministic order.
      await writeFile(target, bytes);
    }
    const installLock = path.join(root, ".install.lock");
    for (;;) {
      try {
        await mkdir(installLock);
        break;
      } catch (error) {
        if (!(error instanceof Error) || !("code" in error) || error.code !== "EEXIST") throw error;
        // oxlint-disable-next-line eslint/no-await-in-loop -- poll the install lock.
        await new Promise((resolve) => setTimeout(resolve, 50));
      }
    }
    try {
      if (!(await current())) {
        const previous = `${staging}.previous`;
        await rename(output, previous).catch((error: NodeJS.ErrnoException) => {
          if (error.code !== "ENOENT") throw error;
        });
        await rename(staging, output);
        await writeFile(marker, `${lock.sha256}\n`);
        await rm(previous, { recursive: true, force: true });
      }
    } finally {
      await rm(installLock, { recursive: true, force: true });
    }
  } finally {
    await rm(staging, { recursive: true, force: true });
  }
}
