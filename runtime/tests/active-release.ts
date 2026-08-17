// One form, written and read here, so a suite never restates it.

import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

export const ACTIVE_RELEASE_POINTER = "active-release";

export function writeActiveReleasePointer(installRoot: string, target: string): void {
  writeFileSync(path.join(installRoot, ACTIVE_RELEASE_POINTER), `${target}\n`);
}

/** The relative release path the pointer selects, e.g. `releases/release-1-ab`. */
export function readActiveReleaseTarget(installRoot: string): string {
  const target = readFileSync(path.join(installRoot, ACTIVE_RELEASE_POINTER), "utf8").trim();
  if (!/^releases\/[A-Za-z0-9._-]+$/.test(target)) {
    throw new Error(`active-release pointer is unreadable: ${target}`);
  }
  return target;
}
