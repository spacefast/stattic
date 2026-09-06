import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  appendFileSync,
  chmodSync,
  copyFileSync,
  cpSync,
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  readlinkSync,
  realpathSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import { readActiveReleaseTarget } from "./active-release.ts";

// The installer has one mode: argv[1] carries the zip source (an https URL or
// a local path), SPACEFAST_RUNTIME_ENGINE_MD5/_REVISION/_NATIVE_SHA256 carry
// the expectations, and the JSON receipt on stdout is the whole report. No
// metadata endpoint, no callback.

type UpdateFixture = {
  root: string;
  publicRoot: string;
  installerPath: string;
  revision: string;
  zipUrl: string;
  md5: string;
  nativeSha256: string;
};

const roots: string[] = [];

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

async function startUpdateFixture(options?: {
  revision?: string;
  publicRoot?: string;
  visitorEngine?: boolean;
  /** Change the loader payload's bytes, and with them its installed identity. */
  loaderNote?: string;
  invalidSelfTest?: boolean;
  /** Ship a `trees` entry: a plugin-like directory plus an alias into it. */
  tree?: boolean;
  treeFiles?: Readonly<Record<string, string>>;
  treeAliases?: boolean;
}): Promise<UpdateFixture> {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-update-installer-"));
  roots.push(root);
  const publicRoot = options?.publicRoot ?? path.join(root, "public");
  const installerRoot = path.join(publicRoot, "__spacefast");
  const payload = path.join(root, "payload");
  const revision = options?.revision ?? "9f3c2ab7d1e4a06b9f3c2ab7d1e4a06b9f3c2ab7";

  mkdirSync(installerRoot, { recursive: true });
  copyFileSync(
    path.resolve(import.meta.dirname, "../installer.php"),
    path.join(installerRoot, "engine-update.php"),
  );
  mkdirSync(path.join(payload, "bin"), { recursive: true });
  mkdirSync(path.join(payload, "engine/shared"), { recursive: true });
  const selfTest = ["#!/bin/sh"];
  const payloadFiles = ["bin/stattic-runtime", "engine-manifest.json", "engine/shared/context.php"];
  const aliases: Array<{ source: string; path: string }> = [];
  if (options?.visitorEngine) {
    mkdirSync(path.join(payload, "engine/runtime"), { recursive: true });
    payloadFiles.push("custom-redirects.php", "engine/init.php", "engine/runtime/revision.php");
    writeFileSync(
      path.join(payload, "engine/init.php"),
      [
        "<?php",
        "require_once __DIR__ . '/shared/context.php';",
        "require_once __DIR__ . '/runtime/revision.php';",
        "header('Content-Type: application/json');",
        "echo json_encode(['context' => SPACEFAST_RUNTIME_ENGINE_REVISION, 'module' => VISITOR_MODULE_REVISION]);",
      ].join("\n"),
    );
    writeFileSync(
      path.join(payload, "engine/runtime/revision.php"),
      `<?php\nconst VISITOR_MODULE_REVISION = '${revision}';\n`,
    );
    copyFileSync(
      path.resolve(import.meta.dirname, "../custom-redirects.php"),
      path.join(payload, "custom-redirects.php"),
    );
    if (options?.loaderNote !== undefined) {
      appendFileSync(path.join(payload, "custom-redirects.php"), `// ${options.loaderNote}\n`);
    }
    aliases.push({ source: "custom-redirects.php", path: "index.php" });
  }
  selfTest.push(
    options?.invalidSelfTest
      ? `printf '%s\\n' '{"format":"wrong"}'`
      : `printf '%s\\n' '{"format":"stattic.runtime.self-test.v1"}'`,
    "",
  );
  writeFileSync(path.join(payload, "bin/stattic-runtime"), selfTest.join("\n"));
  writeFileSync(
    path.join(payload, "engine/shared/context.php"),
    `<?php\nconst SPACEFAST_RUNTIME_ENGINE_REVISION = '${revision}';\n`,
  );
  const trees: Array<{ source: string; path: string }> = [];
  const zipRoots = [...payloadFiles];
  if (options?.tree || options?.treeFiles) {
    const treeFiles = options.treeFiles ?? {
      "entry.php": "<?php // entry\n",
      "lib/deep.php": "<?php // deep\n",
    };
    for (const [relative, body] of Object.entries(treeFiles)) {
      const target = path.join(payload, "wordpress/test-plugin", relative);
      mkdirSync(path.dirname(target), { recursive: true });
      writeFileSync(target, body);
    }
    if (options.treeAliases) {
      for (const relative of Object.keys(treeFiles)) {
        const source = `wordpress/test-plugin/${relative}`;
        payloadFiles.push(source);
        aliases.push({ source, path: `wp-content/mu-plugins/test-plugin/${relative}` });
      }
    } else {
      trees.push({ source: "wordpress/test-plugin", path: "wp-content/mu-plugins/test-plugin" });
    }
    aliases.push({
      source: "wordpress/test-plugin/entry.php",
      path: "wp-content/mu-plugins/test-plugin.php",
    });
    zipRoots.push("wordpress");
  }
  writeFileSync(
    path.join(payload, "engine-manifest.json"),
    `${JSON.stringify({
      files: [...payloadFiles].toSorted(),
      executables: ["bin/stattic-runtime"],
      trees: options?.treeAliases ? undefined : trees,
      aliases,
    })}\n`,
  );
  const zipPath = path.join(root, `${revision}.zip`);
  execFileSync("zip", ["-qr", zipPath, ...zipRoots], { cwd: payload });
  const md5 = createHash("md5").update(readFileSync(zipPath)).digest("hex");
  const nativeSha256 = createHash("sha256")
    .update(readFileSync(path.join(payload, "bin/stattic-runtime")))
    .digest("hex");

  return {
    root,
    publicRoot,
    installerPath: path.join(installerRoot, "engine-update.php"),
    revision,
    zipUrl: zipPath,
    md5,
    nativeSha256,
  };
}

function installRootOf(publicRoot: string): string {
  return path.join(publicRoot, ".stattic");
}

function activeReleaseRoot(publicRoot: string): string {
  const installRoot = installRootOf(publicRoot);
  return path.join(installRoot, readActiveReleaseTarget(installRoot));
}

function treeReleaseVersions(publicRoot: string): string[] {
  const root = path.join(publicRoot, "wp-content/mu-plugins/spacefast-tree-releases/test-plugin");
  return existsSync(root) ? readdirSync(root).toSorted() : [];
}

function releasePayloadIdentity(releaseRoot: string): string {
  const files: string[] = [];
  const visit = (directory: string, prefix: string): void => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const relative = prefix === "" ? entry.name : `${prefix}/${entry.name}`;
      if (entry.isDirectory()) visit(path.join(directory, entry.name), relative);
      else if (entry.isFile() && relative !== ".payload-identity") files.push(relative);
      else if (!entry.isFile()) throw new Error(`unexpected release link: ${relative}`);
    }
  };
  visit(releaseRoot, "");
  const digest = createHash("sha256");
  for (const relative of files.toSorted()) {
    const target = path.join(releaseRoot, relative);
    const bytes = readFileSync(target);
    digest.update(`${relative}\0${lstatSync(target).mode & 0o7777}\0${bytes.byteLength}\0`);
    digest.update(bytes);
  }
  return digest.digest("hex");
}

function readVisitor(publicRoot: string): { context: string; module: string } {
  // SAFETY: the fixture's PHP visitor always emits this two-string JSON receipt.
  return JSON.parse(
    execFileSync("php", ["-d", "auto_prepend_file=", path.join(publicRoot, "index.php")], {
      encoding: "utf8",
      // A visitor simulation must look like a request: a bare CLI process is a
      // tool by contract and passes through the loader unserved.
      env: { ...process.env, REQUEST_METHOD: "GET", REQUEST_URI: "/" },
    }),
  ) as { context: string; module: string };
}

async function runInstaller(
  fixture: UpdateFixture,
  options?: {
    zipUrl?: string;
    md5?: string;
    nativeSha256?: string;
    publicRoot?: string;
    failurePhase?:
      | "pointer_publication"
      | "post_publication_check"
      | "tree_rollback"
      | "file_rollback";
  },
): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  const child = Bun.spawn({
    cmd: [
      "php",
      "-d",
      "auto_prepend_file=",
      fixture.installerPath,
      options?.zipUrl ?? fixture.zipUrl,
    ],
    stdout: "pipe",
    stderr: "pipe",
    env: {
      ...process.env,
      SPACEFAST_RUNTIME_ENGINE_MD5: options?.md5 ?? fixture.md5,
      SPACEFAST_RUNTIME_ENGINE_REVISION: fixture.revision,
      SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256: options?.nativeSha256 ?? "",
      SPACEFAST_RUNTIME_PUBLIC_ROOT: options?.publicRoot ?? "",
      SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE: options?.failurePhase ?? "",
    },
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(child.stdout).text(),
    new Response(child.stderr).text(),
    child.exited,
  ]);
  return { exitCode, stdout, stderr };
}

test("installs the engine tree and prints the receipt", async () => {
  const fixture = await startUpdateFixture();

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(0);
  expect(JSON.parse(result.stdout)).toMatchObject({
    status: "installed",
    engine_revision: fixture.revision,
    layout: "release",
    file_count: 3,
  });
  const releaseRoot = activeReleaseRoot(fixture.publicRoot);
  expect(existsSync(path.join(releaseRoot, "engine/shared/context.php"))).toBe(true);
  expect(existsSync(path.join(releaseRoot, "bin/stattic-runtime"))).toBe(true);
});

test("a manifest tree publishes one complete docroot directory", async () => {
  const fixture = await startUpdateFixture({ tree: true });
  const publicParents = ["wp-content", "wp-content/mu-plugins"].map((relative) =>
    path.join(fixture.publicRoot, relative),
  );
  for (const directory of publicParents) {
    mkdirSync(directory);
    execFileSync("chmod", ["1775", directory]);
  }

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(0);
  for (const directory of publicParents) {
    expect(lstatSync(directory).mode & 0o7777).toBe(0o1775);
  }
  // 3 listed files + 2 tree files staged, 2 files exposed through the public
  // tree, plus the explicit into-tree alias.
  expect(JSON.parse(result.stdout)).toMatchObject({ status: "installed", file_count: 8 });
  const releaseRoot = activeReleaseRoot(fixture.publicRoot);
  // Staged copy inside the release, like any listed file.
  expect(existsSync(path.join(releaseRoot, "wordpress/test-plugin/lib/deep.php"))).toBe(true);
  // Docroot mirror of the whole tree at its install path.
  expect(
    readFileSync(
      path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin/lib/deep.php"),
      "utf8",
    ),
  ).toBe("<?php // deep\n");
  expect(
    lstatSync(path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin")).isSymbolicLink(),
  ).toBe(true);
  const publicTree = path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin");
  const linkTarget = readlinkSync(publicTree);
  expect(path.isAbsolute(linkTarget)).toBe(false);
  expect(linkTarget).toStartWith("spacefast-tree-releases/test-plugin/");
  expect(realpathSync(publicTree)).toStartWith(
    path.join(fixture.publicRoot, "wp-content/mu-plugins/spacefast-tree-releases/test-plugin/"),
  );
  // The explicit alias whose source lives inside the tree: the file WordPress
  // auto-loads lands a level above the mirrored directory.
  expect(
    readFileSync(path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin.php"), "utf8"),
  ).toBe("<?php // entry\n");
});

test("a tree update replaces the directory without retaining removed files", async () => {
  const first = await startUpdateFixture({
    revision: "tree-release-one",
    treeAliases: true,
    treeFiles: {
      "entry.php": "<?php // entry one\n",
      "lib/deep.php": "<?php // deep one\n",
      "removed.php": "<?php // removed\n",
    },
  });
  expect((await runInstaller(first)).exitCode).toBe(0);
  const oldRelease = activeReleaseRoot(first.publicRoot);
  rmSync(path.join(oldRelease, ".payload-identity"));
  rmSync(
    path.join(
      installRootOf(first.publicRoot),
      "release-authorities",
      `${path.basename(oldRelease)}.json`,
    ),
  );

  const next = await startUpdateFixture({
    revision: "tree-release-two",
    publicRoot: first.publicRoot,
    treeFiles: {
      "entry.php": "<?php // entry two\n",
      "lib/deep.php": "<?php // deep two\n",
    },
  });
  const unownedFile = path.join(first.publicRoot, "wp-content/mu-plugins/test-plugin/customer.php");
  writeFileSync(unownedFile, "<?php // customer plugin extension\n");
  expect((await runInstaller(next)).exitCode).toBe(1);
  expect(readFileSync(unownedFile, "utf8")).toBe("<?php // customer plugin extension\n");
  rmSync(unownedFile);
  const updated = await runInstaller(next);

  expect(updated.exitCode).toBe(0);
  const publicTree = path.join(first.publicRoot, "wp-content/mu-plugins/test-plugin");
  expect(lstatSync(publicTree).isSymbolicLink()).toBe(true);
  expect(readFileSync(path.join(publicTree, "lib/deep.php"), "utf8")).toBe("<?php // deep two\n");
  expect(existsSync(path.join(publicTree, "removed.php"))).toBe(false);
  expect(realpathSync(publicTree)).toStartWith(
    path.join(first.publicRoot, "wp-content/mu-plugins/spacefast-tree-releases/test-plugin/"),
  );
  const later = await startUpdateFixture({
    revision: "tree-release-three",
    publicRoot: first.publicRoot,
    treeFiles: { "entry.php": "<?php // entry three\n" },
  });
  expect((await runInstaller(later)).exitCode).toBe(0);
  expect(readFileSync(path.join(publicTree, "entry.php"), "utf8")).toBe("<?php // entry three\n");
  expect(existsSync(path.join(publicTree, "lib/deep.php"))).toBe(false);
});

test("an out-of-tree bootstrap installer targets the configured public root", async () => {
  const fixture = await startUpdateFixture();
  const bootstrapRoot = path.join(fixture.root, "bootstrap-plugin");
  mkdirSync(bootstrapRoot, { recursive: true });
  const bootstrapInstaller = path.join(bootstrapRoot, "installer.php");
  copyFileSync(fixture.installerPath, bootstrapInstaller);

  const result = await runInstaller(
    { ...fixture, installerPath: bootstrapInstaller },
    { publicRoot: fixture.publicRoot },
  );

  expect(result.exitCode).toBe(0);
  expect(existsSync(path.join(fixture.publicRoot, ".stattic/active-release"))).toBe(true);
  expect(existsSync(path.join(fixture.root, ".stattic/active-release"))).toBe(false);
});

test("an embedded bootstrap regains control when the exact release is already current", async () => {
  const fixture = await startUpdateFixture();
  const installed = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });
  expect(installed.exitCode).toBe(0);

  const wrapper = path.join(fixture.root, "embedded-installer.php");
  writeFileSync(
    wrapper,
    `<?php
define('SPACEFAST_RUNTIME_INSTALLER_EMBEDDED', true);
$installer = $argv[1];
$zip = $argv[2];
$argv = [$installer, $zip];
require $installer;
echo "embedded-returned\\n";
`,
  );
  const child = Bun.spawn({
    cmd: ["php", "-d", "auto_prepend_file=", wrapper, fixture.installerPath, fixture.zipUrl],
    stdout: "pipe",
    stderr: "pipe",
    env: {
      ...process.env,
      SPACEFAST_RUNTIME_ENGINE_MD5: fixture.md5,
      SPACEFAST_RUNTIME_ENGINE_REVISION: fixture.revision,
      SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256: fixture.nativeSha256,
    },
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(child.stdout).text(),
    new Response(child.stderr).text(),
    child.exited,
  ]);

  expect(exitCode).toBe(0);
  expect(stderr).toBe("");
  expect(stdout).toContain('"status": "current"');
  expect(stdout).toContain("embedded-returned");
});

test("repairs public loader drift before syncing", async () => {
  const fixture = await startUpdateFixture({ visitorEngine: true });
  await runInstaller(fixture);

  const marker = path.join(fixture.publicRoot, ".stattic/loader-version");
  writeFileSync(marker, `${"0".repeat(64)}\n`);
  const repaired = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });
  expect(repaired.exitCode).toBe(0);
  expect(JSON.parse(repaired.stdout)).toMatchObject({ status: "installed" });
  expect(readFileSync(marker, "utf8")).not.toBe(`${"0".repeat(64)}\n`);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });

  const current = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(current.exitCode).toBe(0);
  expect(JSON.parse(current.stdout)).toMatchObject({
    status: "current",
    engine_revision: fixture.revision,
  });

  const loader = path.join(fixture.publicRoot, "index.php");
  writeFileSync(loader, "<?php // corrupted regular alias\n");
  const regularRepair = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });
  expect(regularRepair.exitCode).toBe(0);
  expect(JSON.parse(regularRepair.stdout)).toMatchObject({ status: "installed" });
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });
  rmSync(loader);
  symlinkSync(path.join(activeReleaseRoot(fixture.publicRoot), "custom-redirects.php"), loader);
  const symlinkRepair = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(symlinkRepair.exitCode).toBe(0);
  expect(JSON.parse(symlinkRepair.stdout)).toMatchObject({ status: "installed" });
  expect(lstatSync(loader).isSymbolicLink()).toBe(false);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });
});

test("repairs immutable release bytes and modes before syncing", async () => {
  const fixture = await startUpdateFixture({ visitorEngine: true, tree: true });
  expect(JSON.parse((await runInstaller(fixture)).stdout)).toMatchObject({ loader: "installed" });
  const firstRelease = activeReleaseRoot(fixture.publicRoot);
  const treeFile = path.join(firstRelease, "wordpress/test-plugin/lib/deep.php");
  const treeBytes = readFileSync(treeFile);
  writeFileSync(treeFile, "<?php // corrupt immutable tree\n");
  expect(
    JSON.parse((await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 })).stdout),
  ).toMatchObject({
    status: "installed",
  });
  expect(activeReleaseRoot(fixture.publicRoot)).not.toBe(firstRelease);
  expect(
    readFileSync(
      path.join(activeReleaseRoot(fixture.publicRoot), "wordpress/test-plugin/lib/deep.php"),
    ),
  ).toEqual(treeBytes);

  const secondRelease = activeReleaseRoot(fixture.publicRoot);
  const engineFile = path.join(secondRelease, "engine/init.php");
  const engineBytes = readFileSync(engineFile);
  writeFileSync(engineFile, "<?php // corrupt immutable engine\n");
  expect(
    JSON.parse((await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 })).stdout),
  ).toMatchObject({
    status: "installed",
  });
  expect(activeReleaseRoot(fixture.publicRoot)).not.toBe(secondRelease);
  expect(readFileSync(path.join(activeReleaseRoot(fixture.publicRoot), "engine/init.php"))).toEqual(
    engineBytes,
  );

  const thirdRelease = activeReleaseRoot(fixture.publicRoot);
  chmodSync(path.join(thirdRelease, "bin/stattic-runtime"), 0o644);
  expect(
    JSON.parse((await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 })).stdout),
  ).toMatchObject({
    status: "installed",
  });
  expect(activeReleaseRoot(fixture.publicRoot)).not.toBe(thirdRelease);
  expect(
    lstatSync(path.join(activeReleaseRoot(fixture.publicRoot), "bin/stattic-runtime")).mode & 0o777,
  ).toBe(0o755);

  const publicTree = path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin");
  writeFileSync(path.join(publicTree, "lib/deep.php"), "<?php // corrupt public copy\n");
  const publicRepair = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });
  expect(publicRepair.exitCode).toBe(0);
  expect(JSON.parse(publicRepair.stdout)).toMatchObject({ status: "installed" });
  expect(readFileSync(path.join(publicTree, "lib/deep.php"))).toEqual(treeBytes);
});

test("replaces stale native bytes even when the installed revision matches", async () => {
  // A matching revision string alone must not vouch for the binary: with the
  // digest stated and mismatched, the installer reinstalls.
  const fixture = await startUpdateFixture();
  await runInstaller(fixture);
  const installedNative = path.join(activeReleaseRoot(fixture.publicRoot), "bin/stattic-runtime");
  const expectedNative = readFileSync(installedNative);
  writeFileSync(installedNative, "stale native bytes");

  const repaired = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(repaired.exitCode).toBe(0);
  expect(JSON.parse(repaired.stdout)).toMatchObject({ status: "installed" });
  expect(
    readFileSync(path.join(activeReleaseRoot(fixture.publicRoot), "bin/stattic-runtime")),
  ).toEqual(expectedNative);
  expect(readFileSync(installedNative, "utf8")).toBe("stale native bytes");
});

test("refuses a non-loopback plain-http zip URL", async () => {
  const fixture = await startUpdateFixture();

  const result = await runInstaller(fixture, {
    zipUrl: "http://example.com/engines/whatever.zip",
  });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_zip_url_insecure");
});

test("fails on an md5 mismatch and leaves no downloaded artifacts behind", async () => {
  const old = await startUpdateFixture({ revision: "old-staging-release", visitorEngine: true });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const fixture = await startUpdateFixture({
    revision: "new-staging-release",
    visitorEngine: true,
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(fixture, { md5: "0".repeat(32) });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_md5_mismatch");
  // A caller retrying a corrupt artifact must not accumulate downloads until
  // the disk fills.
  const incoming = path.join(fixture.publicRoot, ".stattic/incoming");
  const leftovers = existsSync(incoming)
    ? readdirSync(incoming).filter((name) => name.endsWith(".zip"))
    : [];
  expect(leftovers).toEqual([]);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-staging-release",
    module: "old-staging-release",
  });
});

test("keeps the old engine usable when staged validation fails", async () => {
  const old = await startUpdateFixture({ revision: "old-validation-release", visitorEngine: true });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const oldRelease = activeReleaseRoot(old.publicRoot);
  const oldPointer = readFileSync(path.join(old.publicRoot, ".stattic/active-release"), "utf8");
  rmSync(path.join(oldRelease, ".payload-identity"));
  const fixture = await startUpdateFixture({
    revision: "new-validation-release",
    visitorEngine: true,
    invalidSelfTest: true,
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_native_self_test_failed:bin/stattic-runtime");
  expect(readFileSync(path.join(old.publicRoot, ".stattic/active-release"), "utf8")).toBe(
    oldPointer,
  );
  expect(existsSync(path.join(oldRelease, ".payload-identity"))).toBe(false);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-validation-release",
    module: "old-validation-release",
  });
});

test("keeps the active engine usable when pointer publication fails", async () => {
  const old = await startUpdateFixture({
    revision: "old-pointer-release",
    visitorEngine: true,
    treeFiles: {
      "entry.php": "<?php // old pointer entry\n",
      "removed.php": "<?php // old pointer removed\n",
    },
  });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const publicTree = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin");
  const publicLoader = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin.php");
  const loaderMarker = path.join(old.publicRoot, ".stattic/loader-version");
  const oldTreeTarget = realpathSync(publicTree);
  const oldTreeVersions = treeReleaseVersions(old.publicRoot);
  const oldLoaderBytes = readFileSync(publicLoader);
  const oldMarkerBytes = readFileSync(loaderMarker);
  const fixture = await startUpdateFixture({
    revision: "new-pointer-release",
    visitorEngine: true,
    loaderNote: "new pointer loader",
    treeFiles: { "entry.php": "<?php // new pointer entry\n" },
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(fixture, { failurePhase: "pointer_publication" });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_pointer_publication_failed");
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-pointer-release",
    module: "old-pointer-release",
  });
  expect(realpathSync(publicTree)).toBe(oldTreeTarget);
  expect(treeReleaseVersions(old.publicRoot)).toEqual(oldTreeVersions);
  expect(readFileSync(path.join(publicTree, "removed.php"), "utf8")).toContain("old pointer");
  expect(readFileSync(publicLoader)).toEqual(oldLoaderBytes);
  expect(readFileSync(loaderMarker)).toEqual(oldMarkerBytes);
});

test("rolls back the pointer when the post-publication check fails", async () => {
  const old = await startUpdateFixture({
    revision: "old-postcheck-release",
    visitorEngine: true,
    treeFiles: {
      "entry.php": "<?php // old postcheck entry\n",
      "removed.php": "<?php // old postcheck removed\n",
    },
  });
  expect((await runInstaller(old)).exitCode).toBe(0);
  expect(readVisitor(old.publicRoot)).toEqual({
    context: old.revision,
    module: old.revision,
  });
  const publicTree = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin");
  const publicLoader = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin.php");
  const loaderMarker = path.join(old.publicRoot, ".stattic/loader-version");
  const oldTreeTarget = realpathSync(publicTree);
  const oldTreeVersions = treeReleaseVersions(old.publicRoot);
  const oldLoaderBytes = readFileSync(publicLoader);
  const oldMarkerBytes = readFileSync(loaderMarker);
  const next = await startUpdateFixture({
    revision: "new-postcheck-release",
    visitorEngine: true,
    loaderNote: "new postcheck loader",
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(next, { failurePhase: "post_publication_check" });

  expect(result.exitCode).toBe(1);
  expect(JSON.parse(result.stdout)).toMatchObject({
    status: "failed",
    reason: "runtime_engine_post_publication_check_failed",
    rolled_back: true,
  });
  expect(readVisitor(old.publicRoot)).toEqual({
    context: old.revision,
    module: old.revision,
  });
  expect(realpathSync(publicTree)).toBe(oldTreeTarget);
  expect(treeReleaseVersions(old.publicRoot)).toEqual(oldTreeVersions);
  expect(readFileSync(path.join(publicTree, "removed.php"), "utf8")).toContain("old postcheck");
  expect(readFileSync(publicLoader)).toEqual(oldLoaderBytes);
  expect(readFileSync(loaderMarker)).toEqual(oldMarkerBytes);
});

for (const [name, pointerValue] of [
  ["malformed", "../../outside\n"],
  ["dangling", "releases/missing-release\n"],
] as const) {
  test(`preserves a ${name} regular active pointer when staging fails`, async () => {
    const fixture = await startUpdateFixture();
    const installRoot = path.join(fixture.publicRoot, ".stattic");
    mkdirSync(path.join(installRoot, "releases"), { recursive: true });
    const pointer = path.join(installRoot, "active-release");
    writeFileSync(pointer, pointerValue);

    const result = await runInstaller(fixture, { md5: "0".repeat(32) });

    expect(result.exitCode).toBe(1);
    expect(result.stderr).toContain("runtime_engine_md5_mismatch");
    expect(readFileSync(pointer, "utf8")).toBe(pointerValue);
  });
}

test("fails closed without removing an unsupported active pointer", async () => {
  const fixture = await startUpdateFixture();
  const installRoot = path.join(fixture.publicRoot, ".stattic");
  mkdirSync(installRoot, { recursive: true });
  const pointer = path.join(installRoot, "active-release");
  symlinkSync("releases/external-release", pointer);

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_active_pointer_invalid");
  expect(lstatSync(pointer).isSymbolicLink()).toBe(true);
  expect(readlinkSync(pointer)).toBe("releases/external-release");
});

test("fails closed when a regular pointer names a symlinked release", async () => {
  const fixture = await startUpdateFixture();
  const installRoot = path.join(fixture.publicRoot, ".stattic");
  const releasesRoot = path.join(installRoot, "releases");
  mkdirSync(releasesRoot, { recursive: true });
  symlinkSync(fixture.root, path.join(releasesRoot, "linked-release"));
  const pointer = path.join(installRoot, "active-release");
  writeFileSync(pointer, "releases/linked-release\n");

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_active_pointer_invalid");
  expect(readFileSync(pointer, "utf8")).toBe("releases/linked-release\n");
  expect(lstatSync(path.join(releasesRoot, "linked-release")).isSymbolicLink()).toBe(true);
});

test("preserves a corrupt release pointer until replacement staging succeeds", async () => {
  const fixture = await startUpdateFixture();
  const installRoot = path.join(fixture.publicRoot, ".stattic");
  const corrupt = path.join(installRoot, "releases/corrupt-release");
  mkdirSync(corrupt, { recursive: true });
  writeFileSync(path.join(corrupt, "sentinel"), "keep-corrupt-release");
  const pointer = path.join(installRoot, "active-release");
  writeFileSync(pointer, "releases/corrupt-release\n");

  const result = await runInstaller(fixture, { md5: "0".repeat(32) });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_md5_mismatch");
  expect(readFileSync(pointer, "utf8")).toBe("releases/corrupt-release\n");
  expect(readFileSync(path.join(corrupt, "sentinel"), "utf8")).toBe("keep-corrupt-release");
});

test("rejects an unowned directory at a newly introduced public tree", async () => {
  const fixture = await startUpdateFixture({ tree: true });
  const target = path.join(fixture.publicRoot, "wp-content/mu-plugins/test-plugin");
  mkdirSync(target, { recursive: true });
  writeFileSync(path.join(target, "sentinel.txt"), "user-owned");

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_tree_target_unowned");
  expect(readFileSync(path.join(target, "sentinel.txt"), "utf8")).toBe("user-owned");
  expect(lstatSync(target).isDirectory()).toBe(true);
});

for (const privatePath of [".stattic", ".stattic/incoming", ".stattic/releases"] as const) {
  test(`rejects a symlinked ${privatePath} root without writing through it`, async () => {
    const fixture = await startUpdateFixture();
    const outside = path.join(fixture.root, `outside-${path.basename(privatePath)}`);
    mkdirSync(outside, { recursive: true });
    writeFileSync(path.join(outside, "sentinel"), "outside-safe");
    const target = path.join(fixture.publicRoot, privatePath);
    mkdirSync(path.dirname(target), { recursive: true });
    symlinkSync(outside, target);

    const result = await runInstaller(fixture);

    expect(result.exitCode).toBe(1);
    expect(result.stderr).toMatch(/runtime_engine_(?:install_root|incoming|releases)_invalid/);
    expect(readFileSync(path.join(outside, "sentinel"), "utf8")).toBe("outside-safe");
    expect(readdirSync(outside)).toEqual(["sentinel"]);
  });
}

test("retires owned plugin paths without trusting a forged release manifest", async () => {
  const old = await startUpdateFixture({ revision: "retirement-old", tree: true });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const publicTree = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin");
  const publicLoader = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin.php");
  const wpConfig = path.join(old.publicRoot, "wp-config.php");
  writeFileSync(wpConfig, "<?php // sentinel config\n");
  const forged = path.join(old.publicRoot, ".stattic/releases/release-forged");
  cpSync(activeReleaseRoot(old.publicRoot), forged, { recursive: true });
  // SAFETY: startUpdateFixture writes this manifest with the alias and file arrays used below.
  const forgedManifest = JSON.parse(
    readFileSync(path.join(forged, "engine-manifest.json"), "utf8"),
  ) as {
    aliases: Array<{ source: string; path: string }>;
    files: string[];
  };
  forgedManifest.files.push("forged.php");
  forgedManifest.aliases.push({ source: "forged.php", path: "wp-config.php" });
  writeFileSync(path.join(forged, "forged.php"), "<?php // sentinel config\n");
  writeFileSync(path.join(forged, "engine-manifest.json"), `${JSON.stringify(forgedManifest)}\n`);
  writeFileSync(path.join(forged, ".payload-identity"), `${releasePayloadIdentity(forged)}\n`);
  const next = await startUpdateFixture({
    revision: "retirement-new",
    publicRoot: old.publicRoot,
  });

  expect((await runInstaller(next)).exitCode).toBe(0);

  expect(existsSync(publicTree)).toBe(false);
  expect(existsSync(publicLoader)).toBe(false);
  expect(readFileSync(wpConfig, "utf8")).toBe("<?php // sentinel config\n");
});

for (const failurePhase of ["tree_rollback", "file_rollback"] as const) {
  test(`preserves recovery artifacts when ${failurePhase.replace("_", " ")} fails`, async () => {
    const old = await startUpdateFixture({
      revision: `old-${failurePhase}`,
      visitorEngine: true,
      tree: true,
    });
    expect((await runInstaller(old)).exitCode).toBe(0);
    const publicTree = path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin");
    const publicAlias = path.join(old.publicRoot, "index.php");
    const loaderMarker = path.join(old.publicRoot, ".stattic/loader-version");
    const oldAliasBytes = readFileSync(publicAlias);
    const oldMarkerBytes = readFileSync(loaderMarker);
    if (failurePhase === "tree_rollback") {
      const oldTreeVersion = realpathSync(publicTree);
      rmSync(publicTree);
      cpSync(oldTreeVersion, publicTree, { recursive: true });
    }
    const releasesRoot = path.join(old.publicRoot, ".stattic/releases");
    const releaseCount = readdirSync(releasesRoot).length;
    const next = await startUpdateFixture({
      revision: `new-${failurePhase}`,
      visitorEngine: true,
      loaderNote: failurePhase,
      treeFiles: { "entry.php": `<?php // ${failurePhase}\n` },
      publicRoot: old.publicRoot,
    });

    const failed = await runInstaller(next, {
      failurePhase,
    });

    expect(failed.exitCode).toBe(1);
    expect(JSON.parse(failed.stdout)).toMatchObject({ rolled_back: false });
    expect(failed.stderr).toContain("runtime_engine_rollback_incomplete");
    expect(readdirSync(releasesRoot).length).toBeGreaterThan(releaseCount);
    const journal = path.join(old.publicRoot, ".stattic/rollback-failure.json");
    expect(JSON.parse(readFileSync(journal, "utf8"))).toMatchObject({
      format: "spacefast.runtime.rollback-failure.v1",
      result: { [failurePhase === "tree_rollback" ? "trees" : "files"]: false },
    });
    if (failurePhase === "tree_rollback") {
      expect(JSON.parse(readFileSync(journal, "utf8"))).toMatchObject({
        result: { files: false, trees: false },
      });
      expect(readFileSync(publicAlias)).not.toEqual(oldAliasBytes);
      expect(readFileSync(loaderMarker)).not.toEqual(oldMarkerBytes);
      expect(
        readdirSync(path.dirname(publicTree)).some((name) =>
          name.startsWith("test-plugin.previous."),
        ),
      ).toBe(true);
    } else {
      expect(
        readdirSync(old.publicRoot).some((name) => name.startsWith("index.php.previous.")),
      ).toBe(true);
    }

    const repaired = await runInstaller(old, { nativeSha256: old.nativeSha256 });
    expect(repaired.exitCode, repaired.stderr).toBe(0);
    expect(readVisitor(old.publicRoot)).toEqual({ context: old.revision, module: old.revision });
    expect(existsSync(journal)).toBe(false);
    expect(readdirSync(old.publicRoot).filter((name) => name.includes(".previous."))).toEqual([]);
    expect(
      readdirSync(path.dirname(publicTree)).filter((name) => name.includes(".previous.")),
    ).toEqual([]);
    expect(treeReleaseVersions(old.publicRoot)).toHaveLength(1);
  });
}

test("a hard-killed publication stays gated until a retry fully converges", async () => {
  const old = await startUpdateFixture({
    revision: "old-hard-crash",
    visitorEngine: true,
    tree: true,
  });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const next = await startUpdateFixture({
    revision: "new-hard-crash",
    visitorEngine: true,
    loaderNote: "hard crash",
    treeFiles: { "entry.php": "<?php // new hard crash\n" },
    publicRoot: old.publicRoot,
  });
  const pauseFile = path.join(next.root, "publication-paused");
  const child = Bun.spawn({
    cmd: ["php", "-d", "auto_prepend_file=", next.installerPath, next.zipUrl],
    stdout: "pipe",
    stderr: "pipe",
    env: {
      ...process.env,
      SPACEFAST_RUNTIME_ENGINE_MD5: next.md5,
      SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256: next.nativeSha256,
      SPACEFAST_RUNTIME_ENGINE_REVISION: next.revision,
      SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE: "pause_after_first_tree",
      SPACEFAST_RUNTIME_INSTALLER_TEST_PAUSE_FILE: pauseFile,
    },
  });
  const stdout = new Response(child.stdout).text();
  const stderr = new Response(child.stderr).text();
  const pauseDeadline = Date.now() + 5_000;
  while (!existsSync(pauseFile) && Date.now() < pauseDeadline) {
    await Bun.sleep(10);
  }
  expect(existsSync(pauseFile)).toBe(true);
  child.kill(9);
  const [exitCode] = await Promise.all([child.exited, stdout, stderr]);
  expect(exitCode).not.toBe(0);

  const transaction = path.join(old.publicRoot, ".stattic/install-transaction.json");
  expect(existsSync(transaction)).toBe(true);
  const userAliasBackup = path.join(old.publicRoot, "index.php.previous.4242-deadbeef");
  const userTreeBackup = path.join(
    old.publicRoot,
    "wp-content/mu-plugins/test-plugin.previous.4242-deadbeef",
  );
  writeFileSync(userAliasBackup, "user-owned alias backup\n");
  mkdirSync(userTreeBackup, { recursive: true });
  writeFileSync(path.join(userTreeBackup, "sentinel.txt"), "user-owned tree backup\n");
  const gated = Bun.spawnSync({
    cmd: ["php", "-d", "auto_prepend_file=", path.join(old.publicRoot, "index.php")],
    env: { ...process.env, REQUEST_METHOD: "GET", REQUEST_URI: "/" },
  });
  expect(JSON.parse(gated.stdout.toString())).toMatchObject({
    code: "runtime_engine_update_busy",
    status: 503,
  });

  const failedRetry = await runInstaller(next, {
    failurePhase: "post_publication_check",
    nativeSha256: next.nativeSha256,
  });
  expect(failedRetry.exitCode).toBe(1);
  expect(JSON.parse(failedRetry.stdout)).toMatchObject({ rolled_back: false });
  expect(existsSync(transaction)).toBe(true);
  const stillGated = Bun.spawnSync({
    cmd: ["php", "-d", "auto_prepend_file=", path.join(old.publicRoot, "index.php")],
    env: { ...process.env, REQUEST_METHOD: "GET", REQUEST_URI: "/" },
  });
  expect(JSON.parse(stillGated.stdout.toString())).toMatchObject({
    code: "runtime_engine_update_busy",
    status: 503,
  });

  const repaired = await runInstaller(next, { nativeSha256: next.nativeSha256 });
  expect(repaired.exitCode, repaired.stderr).toBe(0);
  expect(readVisitor(old.publicRoot)).toEqual({ context: next.revision, module: next.revision });
  expect(existsSync(transaction)).toBe(false);
  expect(existsSync(path.join(old.publicRoot, ".stattic/rollback-failure.json"))).toBe(false);
  expect(readFileSync(userAliasBackup, "utf8")).toBe("user-owned alias backup\n");
  expect(readFileSync(path.join(userTreeBackup, "sentinel.txt"), "utf8")).toBe(
    "user-owned tree backup\n",
  );
  expect(
    readFileSync(path.join(old.publicRoot, "wp-content/mu-plugins/test-plugin/entry.php"), "utf8"),
  ).toBe("<?php // new hard crash\n");
  expect(treeReleaseVersions(old.publicRoot)).toHaveLength(1);
});

test("serves only complete old or new revisions while the real installer flips the release", async () => {
  const oldRevision = "old-concurrent-release";
  const old = await startUpdateFixture({
    revision: oldRevision,
    visitorEngine: true,
  });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const fixture = await startUpdateFixture({
    revision: "new-concurrent-release",
    visitorEngine: true,
    publicRoot: old.publicRoot,
  });
  writeFileSync(path.join(fixture.publicRoot, ".stattic/storage/sentinel"), "keep-storage");
  mkdirSync(path.join(fixture.publicRoot, ".stattic/incoming"), { recursive: true });
  writeFileSync(path.join(fixture.publicRoot, ".stattic/incoming/sentinel"), "keep-scratch");

  const reservation = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: () => new Response("reserved"),
  });
  const port = reservation.port;
  reservation.stop(true);
  const php = Bun.spawn({
    cmd: [
      "php",
      "-d",
      "auto_prepend_file=",
      "-d",
      "opcache.enable_cli=1",
      "-d",
      "opcache.validate_timestamps=1",
      "-S",
      `127.0.0.1:${port}`,
      path.join(fixture.publicRoot, "index.php"),
    ],
    cwd: fixture.publicRoot,
    stdout: "ignore",
    stderr: "ignore",
  });
  const url = `http://127.0.0.1:${port}/visitor`;
  try {
    let ready: Response | null = null;
    const readyDeadline = Date.now() + 10_000;
    while (ready === null && Date.now() < readyDeadline) {
      ready = await fetch(url).catch(() => null);
    }
    expect(ready?.status).toBe(200);

    const observations: Array<{ status: number; body: string }> = [];
    let pumping = true;
    const pump = (async () => {
      // oxlint-disable-next-line no-unmodified-loop-condition -- the installer task stops the request pump after observing the new release
      while (pumping) {
        const response = await fetch(url);
        observations.push({ status: response.status, body: await response.text() });
      }
    })();

    const oldDeadline = Date.now() + 10_000;
    while (
      !observations.some((entry) => entry.body.includes(oldRevision)) &&
      Date.now() < oldDeadline
    ) {
      await fetch(url);
    }
    expect(observations.some((entry) => entry.body.includes(oldRevision))).toBe(true);
    const installed = await runInstaller(fixture);
    expect(installed.exitCode, installed.stderr).toBe(0);
    const newDeadline = Date.now() + 10_000;
    while (
      !observations.some((entry) => entry.body.includes(fixture.revision)) &&
      Date.now() < newDeadline
    ) {
      await fetch(url);
    }
    pumping = false;
    await pump;

    const unavailable = observations.filter((entry) => entry.status === 503);
    const decoded = observations
      .filter((entry) => entry.status === 200)
      .map((entry) => ({
        status: entry.status,
        // SAFETY: the same fixture visitor produced every captured JSON body.
        body: JSON.parse(entry.body) as { context: string; module: string },
      }));
    expect(decoded.length).toBeGreaterThan(1);
    expect(decoded.some((entry) => entry.body.context === oldRevision)).toBe(true);
    expect(decoded.some((entry) => entry.body.context === fixture.revision)).toBe(true);
    for (const entry of unavailable) {
      expect(JSON.parse(entry.body)).toMatchObject({
        code: "runtime_engine_update_busy",
        status: 503,
      });
    }
    expect(
      decoded.filter(
        (entry) =>
          entry.status !== 200 ||
          entry.body.context !== entry.body.module ||
          ![oldRevision, fixture.revision].includes(entry.body.context),
      ),
    ).toEqual([]);
    expect(readFileSync(path.join(fixture.publicRoot, ".stattic/storage/sentinel"), "utf8")).toBe(
      "keep-storage",
    );
    expect(readFileSync(path.join(fixture.publicRoot, ".stattic/incoming/sentinel"), "utf8")).toBe(
      "keep-scratch",
    );
    expect(existsSync(path.join(activeReleaseRoot(fixture.publicRoot), "engine/init.php"))).toBe(
      true,
    );
  } finally {
    php.kill("SIGKILL");
    await php.exited;
  }
}, 20_000);
