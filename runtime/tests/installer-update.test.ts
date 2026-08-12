import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

// The installer has one mode: argv[1] carries the zip source (an https URL or
// a local path), SPACEFAST_RUNTIME_ENGINE_MD5/_REVISION/_NATIVE_SHA256 carry
// the expectations, and the JSON receipt on stdout is the whole report — no
// metadata endpoint, no callback.

type UpdateFixture = {
  root: string;
  publicRoot: string;
  installerPath: string;
  revision: string;
  zipUrl: string;
  md5: string;
  nativeSha256: string;
  downloadCount: () => number;
  stop: () => void;
};

const roots: string[] = [];
const servers: Array<{ stop: (force?: boolean) => void }> = [];

afterEach(() => {
  for (const server of servers.splice(0)) {
    server.stop(true);
  }
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

async function startUpdateFixture(options?: {
  revision?: string;
  /** Ship the installer itself in the payload (the self-refresh path). */
  shipInstaller?: boolean;
  /**
   * Have the staged native self-test delete one staged PHP module before the
   * swap. The module then goes live as a missing path, and the post-swap
   * opcache_invalidate() of it fails — the only deterministic way to reach
   * D56's failure verdict, since opcache_invalidate() returns true for every
   * resolvable path and an absent/denied OPcache is reported as "inactive" on
   * purpose (a CLI without opcache must not be a permanent alarm).
   */
  vanishingModule?: boolean;
}): Promise<UpdateFixture> {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-update-installer-"));
  roots.push(root);
  const publicRoot = path.join(root, "public");
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
  if (options?.shipInstaller) {
    payloadFiles.push("installer.php");
    copyFileSync(
      path.resolve(import.meta.dirname, "../installer.php"),
      path.join(payload, "installer.php"),
    );
  }
  if (options?.vanishingModule) {
    payloadFiles.push("engine/shared/vanishing.php");
    writeFileSync(path.join(payload, "engine/shared/vanishing.php"), "<?php\n// staged module\n");
    // The self-test runs against the staged tree, before the swap.
    selfTest.push('rm -f "$(dirname "$0")/../engine/shared/vanishing.php"');
  }
  selfTest.push(`printf '%s\\n' '{"format":"stattic.runtime.self-test.v1"}'`, "");
  writeFileSync(path.join(payload, "bin/stattic-runtime"), selfTest.join("\n"));
  writeFileSync(
    path.join(payload, "engine/shared/context.php"),
    `<?php\nconst SPACEFAST_RUNTIME_ENGINE_REVISION = '${revision}';\n`,
  );
  writeFileSync(
    path.join(payload, "engine-manifest.json"),
    `${JSON.stringify({
      files: [...payloadFiles].toSorted(),
      executables: ["bin/stattic-runtime"],
      aliases: [],
    })}\n`,
  );
  const zipPath = path.join(root, `${revision}.zip`);
  execFileSync("zip", ["-qr", zipPath, ...payloadFiles], { cwd: payload });
  const zipBytes = Buffer.from(await Bun.file(zipPath).arrayBuffer());
  const md5 = createHash("md5").update(zipBytes).digest("hex");
  const nativeSha256 = createHash("sha256")
    .update(readFileSync(path.join(payload, "bin/stattic-runtime")))
    .digest("hex");

  let downloads = 0;
  const server = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: (request) => {
      const url = new URL(request.url);
      if (url.pathname === `/engines/${revision}.zip`) {
        downloads += 1;
        return new Response(zipBytes, { headers: { "content-type": "application/zip" } });
      }
      return new Response("not found", { status: 404 });
    },
  });
  servers.push(server);

  return {
    root,
    publicRoot,
    installerPath: path.join(installerRoot, "engine-update.php"),
    revision,
    zipUrl: `http://127.0.0.1:${server.port}/engines/${revision}.zip`,
    md5,
    nativeSha256,
    downloadCount: () => downloads,
    stop: () => server.stop(true),
  };
}

async function runInstaller(
  fixture: UpdateFixture,
  options?: { zipUrl?: string; md5?: string; nativeSha256?: string },
): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  const child = Bun.spawn({
    // OPcache on: the installer's D56 verdict is only a real signal when the
    // extension is actually active in the run, and php-fpm always has it.
    cmd: [
      "php",
      "-d",
      "auto_prepend_file=",
      "-d",
      "opcache.enable_cli=1",
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
    },
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(child.stdout).text(),
    new Response(child.stderr).text(),
    child.exited,
  ]);
  return { exitCode, stdout, stderr };
}

test("installs the engine tree from a URL and prints the receipt", async () => {
  const fixture = await startUpdateFixture();

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(0);
  expect(JSON.parse(result.stdout)).toMatchObject({
    status: "installed",
    engine_revision: fixture.revision,
    file_count: 3,
    // D56: every installed module was invalidated, and the receipt says so.
    opcache: "invalidated",
  });
  expect(existsSync(path.join(fixture.publicRoot, ".stattic/engine/shared/context.php"))).toBe(
    true,
  );
  expect(existsSync(path.join(fixture.publicRoot, ".stattic/bin/stattic-runtime"))).toBe(true);
  expect(fixture.downloadCount()).toBe(1);
});

test("refreshes the resident installer from a payload that ships installer.php", async () => {
  // The resident copy self-updates from every install, so the updater never
  // needs a second SSH delivery.
  const fixture = await startUpdateFixture({ shipInstaller: true });
  writeFileSync(fixture.installerPath, readFileSync(fixture.installerPath, "utf8"));
  const payloadInstaller = readFileSync(
    path.resolve(import.meta.dirname, "../installer.php"),
  );

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(0);
  expect(readFileSync(path.join(fixture.publicRoot, "__spacefast/engine-update.php"))).toEqual(
    payloadInstaller,
  );
});

test("converges without downloading when revision and native digest already match", async () => {
  const fixture = await startUpdateFixture();
  await runInstaller(fixture);

  const converged = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(converged.exitCode).toBe(0);
  expect(JSON.parse(converged.stdout)).toMatchObject({
    status: "converged",
    engine_revision: fixture.revision,
  });
  expect(fixture.downloadCount()).toBe(1);
});

test("replaces stale native bytes even when the installed revision matches", async () => {
  // A matching revision string alone must not vouch for the binary: with the
  // digest stated and mismatched, the installer reinstalls.
  const fixture = await startUpdateFixture();
  await runInstaller(fixture);
  const installedNative = path.join(fixture.publicRoot, ".stattic/bin/stattic-runtime");
  const expectedNative = readFileSync(installedNative);
  writeFileSync(installedNative, "stale native bytes");

  const repaired = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(repaired.exitCode).toBe(0);
  expect(JSON.parse(repaired.stdout)).toMatchObject({ status: "installed" });
  expect(readFileSync(installedNative)).toEqual(expectedNative);
  expect(fixture.downloadCount()).toBe(2);
});

test("refuses a non-loopback plain-http zip URL", async () => {
  const fixture = await startUpdateFixture();

  const result = await runInstaller(fixture, {
    zipUrl: "http://example.com/engines/whatever.zip",
  });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_zip_url_insecure");
  expect(fixture.downloadCount()).toBe(0);
});

test("fails on an md5 mismatch and leaves no downloaded artifacts behind", async () => {
  const fixture = await startUpdateFixture();

  const result = await runInstaller(fixture, { md5: "0".repeat(32) });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_md5_mismatch");
  // Failure cleanup: a caller retrying a corrupt artifact must not accumulate
  // downloads until the disk fills.
  const incoming = path.join(fixture.publicRoot, ".stattic/incoming");
  const leftovers = existsSync(incoming)
    ? readdirSync(incoming).filter((name) => name.endsWith(".zip"))
    : [];
  expect(leftovers).toEqual([]);
});

test("fails the converge when opcache invalidation fails", async () => {
  // D56: the swapped bytes are live and correct either way, but an
  // invalidation php-fpm did not honour means it may keep executing the
  // previous revision's compiled modules — a failed converge, not a success.
  const opcacheProbe = Bun.spawnSync({
    cmd: [
      "php",
      "-d",
      "opcache.enable_cli=1",
      "-r",
      "$s = @opcache_get_status(false); echo is_array($s) && ($s['opcache_enabled'] ?? false) === true ? 'active' : 'inactive';",
    ],
    env: process.env,
  });
  expect(
    opcacheProbe.stdout.toString(),
    "PHP must load Zend OPcache for the installer to reach D56's invalidation verdict",
  ).toBe("active");

  const fixture = await startUpdateFixture({ vanishingModule: true });

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("opcache_invalidation_failed");
  expect(JSON.parse(result.stdout)).toMatchObject({
    status: "failed",
    reason: "opcache_invalidation_failed",
    opcache: "stale",
    opcache_stale_count: 1,
    engine_revision: fixture.revision,
  });
  // The swap still stands — the new engine is live, the converge is not.
  expect(
    readFileSync(path.join(fixture.publicRoot, ".stattic/engine/shared/context.php"), "utf8"),
  ).toContain(fixture.revision);
});
