import { afterEach, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

const roots: string[] = [];

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

test("clean installer replaces stale native tools and preserves executable manifest entries", async () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-native-installer-"));
  roots.push(root);
  const publicRoot = path.join(root, "public");
  const installerRoot = path.join(publicRoot, "__spacefast");
  const payload = path.join(root, "payload");
  const revision = "runtime-native-installer-test";

  mkdirSync(installerRoot, { recursive: true });
  copyFileSync(
    path.resolve(import.meta.dirname, "../installer.php"),
    path.join(installerRoot, "installer.php"),
  );
  mkdirSync(path.join(payload, "bin"), { recursive: true });
  mkdirSync(path.join(payload, "engine/shared"), { recursive: true });
  writeFileSync(
    path.join(payload, "bin/stattic-runtime"),
    "#!/bin/sh\nprintf '%s\\n' 'runtime diagnostic' >&2\nprintf '%s\\n' '{\"format\":\"stattic.runtime.self-test.v1\"}'\n",
  );
  writeFileSync(
    path.join(payload, "engine/shared/context.php"),
    `<?php\nconst SPACEFAST_RUNTIME_ENGINE_REVISION = '${revision}';\n`,
  );
  writeFileSync(
    path.join(payload, "engine-manifest.json"),
    `${JSON.stringify({
      files: ["bin/stattic-runtime", "engine-manifest.json", "engine/shared/context.php"],
      executables: ["bin/stattic-runtime"],
      aliases: [],
    })}\n`,
  );

  const staleBin = path.join(publicRoot, ".stattic/bin");
  mkdirSync(staleBin, { recursive: true });
  writeFileSync(path.join(staleBin, "obsolete"), "stale");

  const zipPath = path.join(root, "runtime-engine.zip");
  execFileSync(
    "zip",
    ["-qr", zipPath, "bin/stattic-runtime", "engine-manifest.json", "engine/shared/context.php"],
    { cwd: payload },
  );
  const md5 = createHash("md5")
    .update(Buffer.from(await Bun.file(zipPath).arrayBuffer()))
    .digest("hex");
  const output = execFileSync(
    "php",
    ["-d", "auto_prepend_file=", path.join(installerRoot, "installer.php"), "--clean", zipPath],
    {
      encoding: "utf8",
      env: {
        ...process.env,
        SPACEFAST_RUNTIME_ENGINE_MD5: md5,
        SPACEFAST_RUNTIME_ENGINE_REVISION: revision,
      },
    },
  );

  expect(JSON.parse(output)).toMatchObject({
    status: "installed",
    clean: true,
    engine_revision: revision,
  });
  expect(existsSync(path.join(staleBin, "obsolete"))).toBe(false);
  expect(statSync(path.join(staleBin, "stattic-runtime")).mode & 0o777).toBe(0o755);
  expect(statSync(path.join(publicRoot, ".stattic/engine/shared/context.php")).mode & 0o777).toBe(
    0o644,
  );
});

test("installer rejects a hung native self-test within its deadline", async () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-native-installer-timeout-"));
  roots.push(root);
  const publicRoot = path.join(root, "public");
  const installerRoot = path.join(publicRoot, "__spacefast");
  const payload = path.join(root, "payload");
  const revision = "runtime-native-installer-timeout-test";

  mkdirSync(installerRoot, { recursive: true });
  copyFileSync(
    path.resolve(import.meta.dirname, "../installer.php"),
    path.join(installerRoot, "installer.php"),
  );
  mkdirSync(path.join(payload, "bin"), { recursive: true });
  mkdirSync(path.join(payload, "engine/shared"), { recursive: true });
  writeFileSync(path.join(payload, "bin/stattic-runtime"), "#!/bin/sh\nexec sleep 60\n");
  writeFileSync(
    path.join(payload, "engine/shared/context.php"),
    `<?php\nconst SPACEFAST_RUNTIME_ENGINE_REVISION = '${revision}';\n`,
  );
  writeFileSync(
    path.join(payload, "engine-manifest.json"),
    `${JSON.stringify({
      files: ["bin/stattic-runtime", "engine-manifest.json", "engine/shared/context.php"],
      executables: ["bin/stattic-runtime"],
      aliases: [],
    })}\n`,
  );
  const zipPath = path.join(root, "runtime-engine.zip");
  execFileSync(
    "zip",
    ["-qr", zipPath, "bin/stattic-runtime", "engine-manifest.json", "engine/shared/context.php"],
    { cwd: payload },
  );
  const md5 = createHash("md5")
    .update(Buffer.from(await Bun.file(zipPath).arrayBuffer()))
    .digest("hex");

  const started = Date.now();
  let failure: (NodeJS.ErrnoException & { stderr?: Buffer | string; status?: number }) | undefined;
  try {
    execFileSync(
      "php",
      ["-d", "auto_prepend_file=", path.join(installerRoot, "installer.php"), "--clean", zipPath],
      {
        timeout: 10_000,
        env: {
          ...process.env,
          SPACEFAST_RUNTIME_ENGINE_MD5: md5,
          SPACEFAST_RUNTIME_ENGINE_REVISION: revision,
        },
      },
    );
  } catch (error) {
    failure = error as typeof failure;
  }

  expect(failure?.status).toBe(1);
  expect(String(failure?.stderr)).toContain("runtime_native_self_test_failed:bin/stattic-runtime");
  expect(Date.now() - started).toBeLessThan(9_000);
}, 12_000);
