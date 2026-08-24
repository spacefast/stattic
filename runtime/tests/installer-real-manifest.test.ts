import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import { residentInstallerTransfer } from "../../apps/control-plane/src/runtime/resident-installer.js";
import { readActiveReleaseTarget } from "./active-release.ts";

// The URL-mode tests build their own fixture manifest, so a regression in the
// shipped `runtime/engine-manifest.json` (#1208 dropped `executables`) slipped
// past them. This one installs from the real manifest through the resident
// installer, as the /engine/update route runs it.

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
  stdout: string;
  stderr: string;
  exitCode: number;
};

// One install straight out of `runtime/engine-manifest.json`: zips the shipped
// file list and runs the resident installer against it.
async function installFromShippedManifest(): Promise<RealManifestInstall> {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-real-manifest-"));
  roots.push(root);
  const publicRoot = path.join(root, "public");
  const payload = path.join(root, "payload");
  const transfer = residentInstallerTransfer(path.join(runtimeRoot, "installer.php"));
  const residentInstaller = path.join(publicRoot, transfer.remotePath.replace(/^htdocs\//, ""));

  // Production lands this file over SSH. It is intentionally not an engine
  // alias: provider cron invokes it through PHP CLI, never php-fpm.
  mkdirSync(path.dirname(residentInstaller), { recursive: true });
  copyFileSync(transfer.localPath, residentInstaller);

  const manifest = JSON.parse(
    readFileSync(path.join(runtimeRoot, "engine-manifest.json"), "utf8"),
  ) as { files: string[] };
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
  execFileSync("zip", ["-qr", zipPath, ...manifest.files], { cwd: payload });
  const zipBytes = readFileSync(zipPath);
  const md5 = createHash("md5").update(zipBytes).digest("hex");
  const nativeSha256 = createHash("sha256")
    .update(readFileSync(path.join(payload, "bin/stattic-runtime")))
    .digest("hex");

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
  // The shipped manifest carries installer.php, so the install refreshed the
  // resident copy in place.
  expect(statSync(install.residentInstaller).isFile()).toBe(true);
});
