// The active-release pointer is a PHP artifact — `<?php return '<target>';` —
// so the serving loader resolves the release from opcache instead of reading
// and parsing a data file on every request. One form, written and read here, so
// a suite never restates it.

import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

export const ACTIVE_RELEASE_POINTER = "active-release.php";

export function writeActiveReleasePointer(installRoot: string, target: string): void {
  writeFileSync(path.join(installRoot, ACTIVE_RELEASE_POINTER), `<?php return '${target}';\n`);
}

/** The relative release path the pointer selects, e.g. `releases/release-1-ab`. */
export function readActiveReleaseTarget(installRoot: string): string {
  const pointer = readFileSync(path.join(installRoot, ACTIVE_RELEASE_POINTER), "utf8").trim();
  const target = /^<\?php return '(releases\/[A-Za-z0-9._-]+)';$/.exec(pointer)?.[1];
  if (target === undefined) throw new Error(`active-release pointer is unreadable: ${pointer}`);
  return target;
}
