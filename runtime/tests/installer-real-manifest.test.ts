import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  copyFileSync,
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import { fetchToolkitPhar } from "../../scripts/fetch-wp-php-toolkit.mjs";
import { readActiveReleaseTarget } from "./active-release.ts";

// The URL-mode tests build their own fixture manifest, so a regression in the
// shipped `runtime/engine-manifest.json` (#1208 dropped `executables`) slipped
// past them. This one installs from the real manifest through the staged
// shared installer, exactly as the bootstrap plugin's `wp spacefast install`
// lane runs it (installer.php staged to `<docroot>/__spacefast/engine-update.php`).

const runtimeRoot = path.resolve(import.meta.dirname, "..");

const roots: string[] = [];

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

type RealManifestInstall = {
  publicRoot: string;
  residentInstaller: string;
  revision: string;
  /** Docroot-relative path of every file the payload's trees expand into. */
  treeSitePaths: string[];
  stdout: string;
  stderr: string;
  exitCode: number;
};

// One install straight out of `runtime/engine-manifest.json`: zips the shipped
// file list and runs the resident installer against it.
async function installFromShippedManifest(
  prepare?: (publicRoot: string) => void,
): Promise<RealManifestInstall> {
  await fetchToolkitPhar();
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-real-manifest-"));
  roots.push(root);
  const publicRoot = path.join(root, "public");
  const payload = path.join(root, "payload");
  const residentInstaller = path.join(publicRoot, "__spacefast/engine-update.php");

  // Production stages this file from the bootstrap plugin's bundled copy. It
  // is intentionally not an engine alias: the plugin invokes it through PHP
  // CLI, never php-fpm.
  mkdirSync(path.dirname(residentInstaller), { recursive: true });
  copyFileSync(path.join(runtimeRoot, "installer.php"), residentInstaller);

  // SAFETY: the shipped manifest is this repo's own build artifact; the file
  // list is the field under test.
  const manifest = JSON.parse(
    readFileSync(path.join(runtimeRoot, "engine-manifest.json"), "utf8"),
  ) as {
    files: string[];
    aliases: Array<{ source: string; path: string }>;
    trees?: Array<{ source: string; path: string }>;
  };
  for (const file of manifest.files) {
    mkdirSync(path.dirname(path.join(payload, file)), { recursive: true });
    if (file === "bin/stattic-runtime") {
      writeFileSync(
        path.join(payload, file),
        "#!/bin/sh\nprintf '%s\\n' '{\"format\":\"stattic.runtime.self-test.v1\"}'\n",
      );
      continue;
    }
    copyFileSync(path.join(runtimeRoot, file), path.join(payload, file));
  }

  // Trees are whole build-output directories the manifest ships recursively,
  // and a real payload always carries them — the installer refuses one that is
  // missing. Both are gitignored build output, so a fresh checkout has neither
  // and no test may produce them (the Zero dashboard needs its own nested
  // workspace install). Stand one in the same way `bin/stattic-runtime` is
  // stubbed above: this test's subject is the manifest, not a tree's contents.
  //
  // A stand-in is still a payload the installer has to accept, which fixes its
  // shape: every alias the manifest points inside a tree must resolve to a file
  // the payload carries, and a tree with no files at all is rejected outright.
  const treeSitePaths: string[] = [];
  for (const tree of manifest.trees ?? []) {
    const source = path.join(runtimeRoot, tree.source);
    const staged = path.join(payload, tree.source);
    if (existsSync(source)) {
      cpSync(source, staged, { recursive: true });
    } else {
      const aliased = manifest.aliases
        .filter((alias) => alias.source.startsWith(`${tree.source}/`))
        .map((alias) => alias.source.slice(tree.source.length + 1));
      mkdirSync(staged, { recursive: true });
      for (const relative of aliased.length > 0 ? aliased : ["index.php"]) {
        const file = path.join(staged, relative);
        mkdirSync(path.dirname(file), { recursive: true });
        writeFileSync(file, "<?php\n// build output stand-in\n");
      }
    }
    for (const relative of readdirSync(staged, { recursive: true, withFileTypes: true })) {
      if (!relative.isFile()) continue;
      const inTree = path.relative(staged, path.join(relative.parentPath, relative.name));
      treeSitePaths.push(`${tree.path}/${inTree.split(path.sep).join("/")}`);
    }
  }

  // Read the revision by evaluating the engine's own constant, not by
  // re-implementing the installer's regex. The installer re-reads and
  // hard-matches this value on extract, so a successful install proves the
  // shipped engine carries it.
  const revisionProbe = execFileSync(
    "php",
    [
      "-r",
      "require $argv[1]; echo SPACEFAST_RUNTIME_ENGINE_REVISION;",
      path.join(payload, "engine/shared/context.php"),
    ],
    { encoding: "utf8" },
  ).trim();
  expect(revisionProbe).not.toBe("");
  const revision = revisionProbe;

  const zipPath = path.join(root, "engine.zip");
  // Trees ride the zip as whole directories, exactly as the engine-zip build
  // ships them; `-r` walks each one.
  execFileSync(
    "zip",
    ["-qr", zipPath, ...manifest.files, ...(manifest.trees ?? []).map((tree) => tree.source)],
    { cwd: payload },
  );
  const zipBytes = readFileSync(zipPath);
  const md5 = createHash("md5").update(zipBytes).digest("hex");
  const nativeSha256 = createHash("sha256")
    .update(readFileSync(path.join(payload, "bin/stattic-runtime")))
    .digest("hex");

  prepare?.(publicRoot);

  const child = Bun.spawn({
    cmd: ["php", "-d", "auto_prepend_file=", residentInstaller, zipPath],
    stdout: "pipe",
    stderr: "pipe",
    env: {
      ...process.env,
      SPACEFAST_RUNTIME_ENGINE_MD5: md5,
      SPACEFAST_RUNTIME_ENGINE_REVISION: revision,
      SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256: nativeSha256,
    },
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(child.stdout).text(),
    new Response(child.stderr).text(),
    child.exited,
  ]);

  return {
    publicRoot,
    residentInstaller,
    revision: revision ?? "",
    treeSitePaths,
    stdout,
    stderr,
    exitCode,
  };
}

test("the shipped manifest installs executable engine bytes without owning the resident installer", async () => {
  const install = await installFromShippedManifest();

  expect(install.stderr).toBe("");
  expect(install.exitCode).toBe(0);
  expect(JSON.parse(install.stdout)).toMatchObject({
    status: "installed",
    engine_revision: install.revision,
  });

  const installRoot = path.join(install.publicRoot, ".stattic");
  const activeRelease = path.join(installRoot, readActiveReleaseTarget(installRoot));
  const installedBinary = path.join(activeRelease, "bin/stattic-runtime");
  expect(statSync(installedBinary).mode & 0o111).not.toBe(0);
  expect(statSync(path.join(activeRelease, "engine/init.php")).isFile()).toBe(true);
  const health = execFileSync(
    "php",
    [
      "-d",
      "auto_prepend_file=",
      "-r",
      "$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/__spacefast/health.php'; $_SERVER['HTTP_HOST']='visitor.test'; require $argv[1];",
      path.join(install.publicRoot, "__spacefast/health.php"),
    ],
    { encoding: "utf8" },
  );
  expect(JSON.parse(health)).toMatchObject({
    ok: true,
    engine_revision: install.revision,
    site_state: "configured",
  });
  const markdown = execFileSync(
    "php",
    [
      "-d",
      "auto_prepend_file=",
      "-r",
      "require $argv[1]; echo spacefast_content_markdown_to_blocks('# Installed Markdown');",
      path.join(activeRelease, "engine/wordpress/content-markdown.php"),
    ],
    { encoding: "utf8" },
  );
  expect(markdown).toContain("<!-- wp:heading");
  expect(markdown).toContain(">Installed Markdown</h1>");
  // The shipped manifest carries installer.php, so the install refreshed the
  // resident copy in place.
  expect(statSync(install.residentInstaller).isFile()).toBe(true);
});

/**
 * Aliases land one at a time, so a request can arrive with only a prefix of
 * them installed. A failed first install must roll the new tree back out rather
 * than leave an unreferenced partial public plugin behind.
 * `wp-content/mu-plugins/zero-admin.php` is what WordPress auto-loads and it
 * reaches into the sibling `zero-admin/` directory, which is a separate set of
 * aliases, so the directory has to be complete before the entry file appears.
 *
 * (The other half of that hazard — the entry file staying inert when the
 * directory is absent anyway — is the plugin's own behavior, held by
 * packages/zero-admin/test/build.test.ts.)
 */
test("a failed zero-admin loader publication rolls back its fresh tree", async () => {
  // A directory sitting where the entry file must land makes its rename fail,
  // which stops the install exactly there and leaves on disk precisely the
  // aliases ordered before it.
  const install = await installFromShippedManifest((publicRoot) => {
    const blocked = path.join(publicRoot, "wp-content/mu-plugins/zero-admin.php");
    mkdirSync(blocked, { recursive: true });
    writeFileSync(path.join(blocked, "occupied"), "");
  });

  expect(install.exitCode).toBe(1);
  expect(install.stderr).toContain(
    "runtime_engine_alias_install_failed:wp-content/mu-plugins/zero-admin.php",
  );
  // No part of a never-committed tree remains public after the alias failure.
  const treeFiles = install.treeSitePaths.filter((file) =>
    file.startsWith("wp-content/mu-plugins/zero-admin/"),
  );
  expect(treeFiles.length).toBeGreaterThan(0);
  expect(treeFiles.filter((file) => existsSync(path.join(install.publicRoot, file)))).toEqual([]);
  const treeReleases = path.join(
    install.publicRoot,
    "wp-content/mu-plugins/spacefast-tree-releases",
  );
  const publishedTreeFiles = existsSync(treeReleases)
    ? readdirSync(treeReleases, { recursive: true, withFileTypes: true }).filter(
        (entry) => entry.isFile() || entry.isSymbolicLink(),
      )
    : [];
  expect(publishedTreeFiles).toEqual([]);
  expect(existsSync(path.join(install.publicRoot, "index.php"))).toBe(false);
  expect(existsSync(path.join(install.publicRoot, ".stattic/loader-version"))).toBe(false);
  expect(existsSync(path.join(install.publicRoot, ".stattic/active-release"))).toBe(false);
});
