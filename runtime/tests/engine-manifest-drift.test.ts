// A generated↔source drift guard, the one sanctioned kind of source reader:
// `runtime/engine-manifest.json` is a hand-maintained index of this directory,
// and this asserts the index still matches the tree. It reads file NAMES, never
// file contents, and proves no behavior — the behavior lives in the suites that
// install from the manifest.
//
// The direction already covered elsewhere is manifest→disk: harness.ts's
// installEngine throws at cpSync for an entry with no file, and installer.php's
// read_engine_manifest validates shape, uniqueness and alias targets at install
// time. The gap is the other way. Add engine/shared/new-thing.php, require it
// from context.php, forget the manifest line, and every local test passes — the
// file is right there in the checkout — while every wp.cloud install ships a
// tree with a fatal missing require. That is #1208's failure class (a dropped
// `executables` entry shipped a non-executable finalizer) one direction over.
import { expect, test } from "bun:test";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";

const runtimeRoot = path.resolve(import.meta.dirname, "..");

const manifest = JSON.parse(
  readFileSync(path.join(runtimeRoot, "engine-manifest.json"), "utf8"),
) as { files: string[]; executables: string[]; aliases: Array<{ source: string; path: string }> };

// Tracked files under runtime/ that deliberately do NOT ship inside the engine
// zip. Every entry carries its reason, because the one way to silence this
// guard dishonestly is to add a path here — a visible diff a reviewer reads,
// unlike a forgotten manifest line.
const NOT_SHIPPED: Record<string, string> = {
  "browser-specs/": "real-browser runtime tests, never installed on a site",
  "tests/":
    "the test suite itself — bun/php test files, fixtures and the runner, never installed on a site",
  "README.md": "repo documentation: how the engine works, for us",
  "SKILL.md": "the agent-facing skill for working on the engine, repo-only",
};

// Manifest entries with no tracked source file, by design.
const BUILD_ARTIFACTS = new Set([
  // Built by scripts/build-runtime-native.mjs into gitignored runtime/bin/;
  // installer-real-manifest.test.ts stubs it for the same reason.
  "bin/stattic-runtime",
]);

// Walk the working tree rather than Git's index because the dev engine builder
// packages the working tree too. A newly added, not-yet-tracked PHP module must
// therefore be covered before its first commit; otherwise a changed caller can
// require it locally while the built artifact silently omits it. `bin/` is the
// one generated subtree and is reconciled separately through BUILD_ARTIFACTS.
function runtimeFilesOnDisk(directory = runtimeRoot, prefix = ""): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (relative === "bin" || relative.startsWith("bin/")) return [];
    return entry.isDirectory()
      ? runtimeFilesOnDisk(path.join(directory, entry.name), relative)
      : [relative];
  });
}

function shouldShip(file: string): boolean {
  return !Object.keys(NOT_SHIPPED).some((entry) =>
    entry.endsWith("/") ? file.startsWith(entry) : file === entry,
  );
}

test("engine-manifest.json lists exactly the runtime files that ship", () => {
  const tracked = runtimeFilesOnDisk().filter(shouldShip);
  const listed = manifest.files.filter((file) => !BUILD_ARTIFACTS.has(file));

  const missing = tracked.filter((file) => !listed.includes(file)).toSorted();
  expect(
    missing,
    `Engine files on disk but missing from runtime/engine-manifest.json:\n  ${missing.join("\n  ")}\nAdd them to "files" (and "aliases"/"executables" if they need an install path or the exec bit), or add them to NOT_SHIPPED in this test with a reason.`,
  ).toEqual([]);

  const orphaned = listed.filter((file) => !tracked.includes(file)).toSorted();
  expect(
    orphaned,
    `Listed in runtime/engine-manifest.json with no tracked file:\n  ${orphaned.join("\n  ")}\nThe install would fail at copy time. Remove them, or add them to BUILD_ARTIFACTS with the script that produces them.`,
  ).toEqual([]);
});

// An exclusion that no longer matches anything is a carve-out nobody has to
// justify any more — exactly the kind of entry that later gets reused to
// silence a real miss. Keep the list as short as the tree makes it.
test("every NOT_SHIPPED exclusion still covers something on disk", () => {
  const tracked = runtimeFilesOnDisk();
  const stale = Object.keys(NOT_SHIPPED).filter(
    (entry) =>
      !tracked.some((file) => (entry.endsWith("/") ? file.startsWith(entry) : file === entry)),
  );
  expect(stale).toEqual([]);
});

// installer.php enforces both of these at install time, where the symptom is a
// site-wide `runtime_engine_manifest_invalid` on a real rollout. Asserting them
// here turns that into a CI failure on the commit that caused it.
test("every manifest alias and executable points at a listed file", () => {
  const listed = new Set(manifest.files);
  expect(manifest.aliases.filter((alias) => !listed.has(alias.source))).toEqual([]);
  expect(manifest.executables.filter((file) => !listed.has(file))).toEqual([]);
});
