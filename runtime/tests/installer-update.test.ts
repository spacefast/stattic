import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  appendFileSync,
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

import { ACTIVE_RELEASE_POINTER, readActiveReleaseTarget } from "./active-release.ts";

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
};

const roots: string[] = [];

afterEach(() => {
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
  publicRoot?: string;
  visitorEngine?: boolean;
  /** Change the loader payload's bytes, and with them its installed identity. */
  loaderNote?: string;
  invalidSelfTest?: boolean;
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
  writeFileSync(
    path.join(payload, "engine-manifest.json"),
    `${JSON.stringify({
      files: [...payloadFiles].toSorted(),
      executables: ["bin/stattic-runtime"],
      aliases,
    })}\n`,
  );
  const zipPath = path.join(root, `${revision}.zip`);
  execFileSync("zip", ["-qr", zipPath, ...payloadFiles], { cwd: payload });
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

function installLegacyVisitor(publicRoot: string, revision: string): void {
  const engineRoot = path.join(publicRoot, ".stattic/engine");
  mkdirSync(path.join(engineRoot, "shared"), { recursive: true });
  mkdirSync(path.join(engineRoot, "runtime"), { recursive: true });
  mkdirSync(path.join(publicRoot, ".stattic/storage"), { recursive: true });
  writeFileSync(
    path.join(engineRoot, "shared/context.php"),
    `<?php\nconst SPACEFAST_RUNTIME_ENGINE_REVISION = '${revision}';\n`,
  );
  writeFileSync(
    path.join(engineRoot, "runtime/revision.php"),
    `<?php\nconst VISITOR_MODULE_REVISION = '${revision}';\n`,
  );
  writeFileSync(
    path.join(engineRoot, "init.php"),
    [
      "<?php",
      "require_once __DIR__ . '/shared/context.php';",
      "require_once __DIR__ . '/runtime/revision.php';",
      "header('Content-Type: application/json');",
      "echo json_encode(['context' => SPACEFAST_RUNTIME_ENGINE_REVISION, 'module' => VISITOR_MODULE_REVISION]);",
    ].join("\n"),
  );
  writeFileSync(
    path.join(publicRoot, "index.php"),
    "<?php require_once __DIR__ . '/.stattic/engine/init.php';\n",
  );
}

function readVisitor(publicRoot: string): { context: string; module: string } {
  return JSON.parse(
    execFileSync("php", ["-d", "auto_prepend_file=", path.join(publicRoot, "index.php")], {
      encoding: "utf8",
    }),
  ) as { context: string; module: string };
}

async function runInstaller(
  fixture: UpdateFixture,
  options?: {
    zipUrl?: string;
    md5?: string;
    nativeSha256?: string;
    failurePhase?: "pointer_publication";
  },
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
    file_count: 3,
    // D56: every installed module was invalidated, and the receipt says so.
    opcache: "invalidated",
  });
  const releaseRoot = activeReleaseRoot(fixture.publicRoot);
  expect(existsSync(path.join(releaseRoot, "engine/shared/context.php"))).toBe(true);
  expect(existsSync(path.join(releaseRoot, "bin/stattic-runtime"))).toBe(true);
});

test("refreshes the resident installer from a payload that ships installer.php", async () => {
  // The resident copy self-updates from every install, so the updater never
  // needs a second SSH delivery.
  const fixture = await startUpdateFixture({ shipInstaller: true });
  writeFileSync(fixture.installerPath, readFileSync(fixture.installerPath, "utf8"));
  const payloadInstaller = readFileSync(path.resolve(import.meta.dirname, "../installer.php"));

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(0);
  expect(readFileSync(path.join(fixture.publicRoot, "__spacefast/engine-update.php"))).toEqual(
    payloadInstaller,
  );
});

test("repairs an interrupted loader migration before converging", async () => {
  const fixture = await startUpdateFixture({ visitorEngine: true });
  await runInstaller(fixture);

  rmSync(path.join(fixture.publicRoot, ".stattic/loader-version"));
  writeFileSync(path.join(fixture.publicRoot, "index.php"), "<?php exit(70);\n");
  const repaired = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });
  expect(repaired.exitCode).toBe(0);
  expect(JSON.parse(repaired.stdout)).toMatchObject({ status: "installed" });
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });

  const converged = await runInstaller(fixture, { nativeSha256: fixture.nativeSha256 });

  expect(converged.exitCode).toBe(0);
  expect(JSON.parse(converged.stdout)).toMatchObject({
    status: "converged",
    engine_revision: fixture.revision,
  });
});

test("reinstalls the loader only when the payload's loader bytes differ from the marker", async () => {
  // The marker is the identity of the loader that is installed, so a loader
  // fix reaches a box that already converged — with a frozen marker literal,
  // the first install was the last one that could ever place these files.
  const fixture = await startUpdateFixture({ visitorEngine: true });
  expect(JSON.parse((await runInstaller(fixture)).stdout)).toMatchObject({ loader: "installed" });
  const loader = path.join(fixture.publicRoot, "index.php");
  writeFileSync(loader, "<?php exit(70);\n");

  const unchanged = await runInstaller(fixture);

  expect(JSON.parse(unchanged.stdout)).toMatchObject({ status: "installed", loader: "current" });
  expect(readFileSync(loader, "utf8")).toBe("<?php exit(70);\n");

  const next = await startUpdateFixture({
    revision: "loader-change-release",
    visitorEngine: true,
    loaderNote: "loader identity moved",
    publicRoot: fixture.publicRoot,
  });
  const reinstalled = await runInstaller(next);

  expect(JSON.parse(reinstalled.stdout)).toMatchObject({
    status: "installed",
    loader: "installed",
  });
  expect(readFileSync(loader, "utf8")).toContain("loader identity moved");
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: next.revision,
    module: next.revision,
  });
});

test("serves through a legacy plain-text pointer and republishes it as the PHP artifact", async () => {
  const fixture = await startUpdateFixture({ visitorEngine: true });
  expect((await runInstaller(fixture)).exitCode).toBe(0);
  const installRoot = installRootOf(fixture.publicRoot);
  const installed = readActiveReleaseTarget(installRoot);

  // A box whose last activation predates the PHP pointer carries only the
  // plain-text file, and its loader has to keep serving from it.
  rmSync(path.join(installRoot, ACTIVE_RELEASE_POINTER));
  writeFileSync(path.join(installRoot, "active-release"), `${installed}\n`);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });

  const upgraded = await runInstaller(fixture);

  expect(upgraded.exitCode, upgraded.stderr).toBe(0);
  expect(readActiveReleaseTarget(installRoot)).not.toBe(installed);
  expect(existsSync(path.join(installRoot, "active-release"))).toBe(false);
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: fixture.revision,
    module: fixture.revision,
  });
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
  const fixture = await startUpdateFixture({ visitorEngine: true });
  installLegacyVisitor(fixture.publicRoot, "old-staging-release");

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
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-staging-release",
    module: "old-staging-release",
  });
});

test("keeps the old engine usable when staged validation fails", async () => {
  const fixture = await startUpdateFixture({ visitorEngine: true, invalidSelfTest: true });
  installLegacyVisitor(fixture.publicRoot, "old-validation-release");

  const result = await runInstaller(fixture);

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_native_self_test_failed:bin/stattic-runtime");
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-validation-release",
    module: "old-validation-release",
  });
});

test("keeps the active engine usable when pointer publication fails", async () => {
  const old = await startUpdateFixture({ revision: "old-pointer-release", visitorEngine: true });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const fixture = await startUpdateFixture({
    revision: "new-pointer-release",
    visitorEngine: true,
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(fixture, { failurePhase: "pointer_publication" });

  expect(result.exitCode).toBe(1);
  expect(result.stderr).toContain("runtime_engine_pointer_publication_failed");
  expect(readVisitor(fixture.publicRoot)).toEqual({
    context: "old-pointer-release",
    module: "old-pointer-release",
  });
});

test("rolls back the pointer when the post-publication native check fails", async () => {
  const old = await startUpdateFixture({ revision: "old-postcheck-release", visitorEngine: true });
  expect((await runInstaller(old)).exitCode).toBe(0);
  expect(readVisitor(old.publicRoot)).toEqual({
    context: old.revision,
    module: old.revision,
  });
  const next = await startUpdateFixture({
    revision: "new-postcheck-release",
    visitorEngine: true,
    publicRoot: old.publicRoot,
  });

  const result = await runInstaller(next, { nativeSha256: "0".repeat(64) });

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
});

test("serves only complete old or new revisions while the real installer flips the release", async () => {
  const oldRevision = "old-concurrent-release";
  const fixture = await startUpdateFixture({
    revision: "new-concurrent-release",
    visitorEngine: true,
  });
  installLegacyVisitor(fixture.publicRoot, oldRevision);
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
      "-d",
      "opcache.revalidate_freq=0",
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

    const decoded = observations.map((entry) => ({
      status: entry.status,
      body: JSON.parse(entry.body) as { context: string; module: string },
    }));
    expect(decoded.length).toBeGreaterThan(1);
    expect(decoded.some((entry) => entry.body.context === oldRevision)).toBe(true);
    expect(decoded.some((entry) => entry.body.context === fixture.revision)).toBe(true);
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
    expect(existsSync(path.join(fixture.publicRoot, ".stattic/engine/init.php"))).toBe(true);
  } finally {
    php.kill("SIGKILL");
    await php.exited;
  }
}, 20_000);

test("rolls back to the usable old release when opcache invalidation fails", async () => {
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

  const old = await startUpdateFixture({ revision: "old-opcache-release" });
  expect((await runInstaller(old)).exitCode).toBe(0);
  const fixture = await startUpdateFixture({
    revision: "new-opcache-release",
    vanishingModule: true,
    publicRoot: old.publicRoot,
  });

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
  expect(JSON.parse(result.stdout)).toMatchObject({ rolled_back: true });
  expect(
    readFileSync(
      path.join(activeReleaseRoot(fixture.publicRoot), "engine/shared/context.php"),
      "utf8",
    ),
  ).toContain(old.revision);
});
