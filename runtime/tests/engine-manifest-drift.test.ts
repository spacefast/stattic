// A generated↔source drift guard, the one sanctioned kind of source reader:
// `runtime/engine-manifest.json` is a hand-maintained index of this directory,
// and this asserts the index still matches the tree. It reads file NAMES, never
// file contents, and proves no behavior.
//
// The manifest→disk direction is already covered: harness.ts's installEngine
// throws at cpSync for an entry with no file, and installer.php's
// read_engine_manifest validates shape, uniqueness and alias targets at install
// time. The gap is the other way. Add engine/shared/new-thing.php, require it
// from context.php, forget the manifest line, and every local test passes while
// every wp.cloud install ships a tree with a fatal missing require. That is
// #1208's failure class (a dropped `executables` entry shipped a non-executable
// finalizer) one direction over.
import { expect, test } from "bun:test";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";

const runtimeRoot = path.resolve(import.meta.dirname, "..");

const manifest = JSON.parse(
  readFileSync(path.join(runtimeRoot, "engine-manifest.json"), "utf8"),
) as { files: string[]; executables: string[]; aliases: Array<{ source: string; path: string }> };

// Tracked files under runtime/ that deliberately do NOT ship in the engine zip.
// Every entry carries its reason: adding a path here is the one way to silence
// this guard, and it leaves a visible diff a reviewer reads.
const NOT_SHIPPED = {
  "bootstrap-plugin/":
    "signed box bootstrap plugin source, packaged only in the paired bootstrap zip",
  "browser-specs/": "real-browser runtime tests, never installed on a site",
  "node_modules/": "workspace dependency links, never installed on a site",
  "package.json": "the Turbo workspace boundary and test dependency graph, never installed",
  "tests/":
    "the test suite itself — bun/php test files, fixtures and the runner, never installed on a site",
  "README.md": "repo documentation: how the engine works, for us",
  "SKILL.md": "the agent-facing skill for working on the engine, repo-only",
  "php-fpm-readiness.php":
    "transition-only FPM probe uploaded and removed by the control plane before engine install",
} as const;

// Manifest entries with no tracked source file, by design.
const BUILD_ARTIFACTS = new Set([
  // Built by scripts/build-runtime-native.mjs into gitignored runtime/bin/;
  // installer-real-manifest.test.ts stubs it for the same reason.
  "bin/stattic-runtime",
  // The zero-admin plugin, built by packages/zero-admin/scripts/build.ts into
  // gitignored runtime/wordpress/zero-admin/. Its source lives in that package
  // (PHP included), and packages/zero-admin/test/engine-manifest.test.ts holds
  // these entries against it — this guard only needs to know they are built.
  "wordpress/zero-admin/admin-page.php",
  "wordpress/zero-admin/assets.php",
  "wordpress/zero-admin/bootstrap.php",
  "wordpress/zero-admin/routes.php",
  "wordpress/zero-admin/zero-admin.php",
  "wordpress/zero-admin/build/admin.js",
  "wordpress/zero-admin/build/admin.css",
  "wordpress/zero-admin/build/admin.asset.php",
]);

// Generated roots: present after a build, absent in a fresh checkout, so the
// walk has to skip them either way and BUILD_ARTIFACTS reconciles their
// manifest entries instead. Turbo task metadata is build output the same way.
const GENERATED_ROOTS = [".turbo", "bin", "wordpress/zero-admin"];

// Walk the working tree, not Git's index, because the dev engine builder
// packages the working tree too. An untracked PHP module must be covered before
// its first commit, or a caller requires it locally while the built artifact
// omits it.
function runtimeFilesOnDisk(directory = runtimeRoot, prefix = ""): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (GENERATED_ROOTS.some((root) => relative === root || relative.startsWith(`${root}/`))) {
      return [];
    }
    // Coverage-instrumented native children receive the runtime's production
    // env allowlist, so LLVM falls back to this cwd-relative profile name.
    if (/^default_[0-9]+_[0-9]+_[0-9]+\.profraw$/.test(relative)) return [];
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

// An exclusion matching nothing is an unjustified carve-out, and those get
// reused later to silence a real miss. Keep the list as short as the tree
// allows.
test("every NOT_SHIPPED exclusion still covers something on disk", () => {
  const tracked = runtimeFilesOnDisk();
  const stale = Object.keys(NOT_SHIPPED).filter(
    (entry) =>
      !tracked.some((file) => (entry.endsWith("/") ? file.startsWith(entry) : file === entry)),
  );
  expect(stale).toEqual([]);
});

// installer.php enforces both at install time, where the symptom is a site-wide
// `runtime_engine_manifest_invalid` on a real rollout. Asserting here turns that
// into a CI failure on the commit that caused it.
test("every manifest alias and executable points at a listed file", () => {
  const listed = new Set(manifest.files);
  expect(manifest.aliases.filter((alias) => !listed.has(alias.source))).toEqual([]);
  expect(manifest.executables.filter((file) => !listed.has(file))).toEqual([]);
});
