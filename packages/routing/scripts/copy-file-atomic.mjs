import { copyFileSync, mkdirSync, mkdtempSync, renameSync, rmSync } from "node:fs";
import path from "node:path";

export function copyFileAtomic(source, destination) {
  const directory = path.dirname(destination);
  mkdirSync(directory, { recursive: true });
  const staging = mkdtempSync(path.join(directory, `.${path.basename(destination)}-`));
  try {
    const pending = path.join(staging, "artifact");
    copyFileSync(source, pending);
    renameSync(pending, destination);
  } finally {
    rmSync(staging, { recursive: true, force: true });
  }
}
