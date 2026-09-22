import { createHash } from "node:crypto";
import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
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
await rm(output, { recursive: true, force: true });
for (const [name, bytes] of files) {
  const relative = name.slice("spacefast-identity/".length);
  if (!relative || name.endsWith("/")) continue;
  const target = path.join(output, relative);
  // oxlint-disable-next-line eslint/no-await-in-loop -- bounded archive extraction in deterministic order.
  await mkdir(path.dirname(target), { recursive: true });
  // oxlint-disable-next-line eslint/no-await-in-loop -- bounded archive extraction in deterministic order.
  await writeFile(target, bytes);
}
