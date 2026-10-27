// A site runs its own release unless its persistent data selects an
// environment's channel of the Spacefast chroot. These run the real loaders against a chroot stood up
// beside the box: the selected revision serves and proves itself while the
// site's own install root keeps its data, and an incomplete chroot falls back
// to the site's release.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash } from "node:crypto";
import {
  chmodSync,
  copyFileSync,
  cpSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  statSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  api,
  RUNTIME_ATOMIC_DATA,
  RUNTIME_HTTP_API_BASE,
  startRuntime,
  type Runtime,
} from "./harness";

const CHROOT_REVISION = "c".repeat(40);

let rt: Runtime;
let chrootRoot: string;

beforeAll(async () => {
  chrootRoot = realpathSync(mkdtempSync(path.join(os.tmpdir(), "spacefast-chroot-")));
  rt = await startRuntime({
    env: { SPACEFAST_TEST_CHROOT_ROOT: chrootRoot },
    atomicData: { SPACEFAST_RUNTIME_ENGINE_SOURCE: "chroot/production/latest" },
  });
  // The external's layout: the release without its binary, the binary under
  // usr/local/bin, and a channel link beside each. The revision constant is the
  // only byte that differs from the site's release, so a response naming it
  // came from the chroot engine.
  const siteRelease = path.dirname(rt.engineRoot);
  const engine = path.join(chrootRoot, "scripts", `sf-engine-${CHROOT_REVISION}`);
  cpSync(siteRelease, engine, {
    recursive: true,
    filter: (source) => source !== path.join(siteRelease, "bin"),
  });
  const context = path.join(engine, "engine/shared/context.php");
  writeFileSync(
    context,
    readFileSync(context, "utf8").replace(
      "const SPACEFAST_RUNTIME_ENGINE_REVISION = 'source-tree';",
      `const SPACEFAST_RUNTIME_ENGINE_REVISION = '${CHROOT_REVISION}';`,
    ),
  );
  const binary = path.join(chrootRoot, "usr/local/bin", `sf-bin-${CHROOT_REVISION}`);
  mkdirSync(binary, { recursive: true });
  copyFileSync(path.join(siteRelease, "bin/stattic-runtime"), path.join(binary, "stattic-runtime"));
  chmodSync(path.join(binary, "stattic-runtime"), 0o755);
  symlinkSync(
    `sf-engine-${CHROOT_REVISION}`,
    path.join(chrootRoot, "scripts/sf-engine-production-latest"),
  );
  symlinkSync(
    `sf-bin-${CHROOT_REVISION}`,
    path.join(chrootRoot, "usr/local/bin/sf-bin-production-latest"),
  );
});

afterAll(() => {
  rt?.stop();
  if (chrootRoot) rmSync(chrootRoot, { recursive: true, force: true });
});

async function expectHealth(expected: Record<string, string | null>): Promise<void> {
  const response = await fetch(`${rt.baseUrl}/__spacefast/health.php`);
  expect(response.status).toBe(200);
  expect(await response.json()).toMatchObject(expected);
}

test("serves and proves the chroot revision the site selected", async () => {
  // `configured` means the engine found the site's storage, which it can only
  // do through the install root the loader named: the chroot engine is not
  // inside it.
  await expectHealth({
    engine_revision: CHROOT_REVISION,
    engine_source: "chroot",
    engine_source_reason: "chroot",
    chroot_revision: CHROOT_REVISION,
    site_state: "configured",
  });

  const nativeSha256 = createHash("sha256")
    .update(
      readFileSync(
        path.join(chrootRoot, "usr/local/bin", `sf-bin-${CHROOT_REVISION}`, "stattic-runtime"),
      ),
    )
    .digest("hex");
  const update = (revision: string) =>
    api(
      rt,
      "POST",
      `${RUNTIME_HTTP_API_BASE}/engine/update`,
      "update_engine",
      {},
      {
        revision,
        zip_url: "https://github.example/engines/chroot.zip",
        md5: "6d34a1f0c8b2e97d5a41f3c9b0d2e816",
        native_sha256: nativeSha256,
      },
    );
  const current = await update(CHROOT_REVISION);
  expect(current.status).toBe(200);
  expect(await current.json()).toEqual({
    status: "current",
    engine_revision: CHROOT_REVISION,
    layout: "release",
    engine_source: "chroot",
  });
  // The site's own release is not what serves, so it is not current.
  expect((await update("source-tree")).status).toBe(409);
});

test("falls back to the site's release when the chroot is unselected or cannot serve it", async () => {
  const persistentData = path.join(rt.root, ".atomic-persistent-data.json");
  const selected = readFileSync(persistentData);
  writeFileSync(persistentData, `${JSON.stringify(RUNTIME_ATOMIC_DATA)}\n`);
  try {
    await expectHealth({
      engine_revision: "source-tree",
      engine_source: "site",
      engine_source_reason: "unselected",
      chroot_revision: null,
    });
  } finally {
    writeFileSync(persistentData, selected);
  }

  // Each file stands for one way the chroot fails the site, and health names
  // it: a revision without its binary, and an engine without this selection
  // rule, whose loaders would stop the site reading its channel once installed.
  for (const [file, reason] of [
    [
      path.join(chrootRoot, "usr/local/bin", `sf-bin-${CHROOT_REVISION}`, "stattic-runtime"),
      "chroot_binary_missing",
    ],
    [
      path.join(
        chrootRoot,
        "scripts",
        `sf-engine-${CHROOT_REVISION}`,
        "engine/shared/engine-selection.php",
      ),
      "chroot_engine_unsupported",
    ],
  ] as const) {
    const bytes = readFileSync(file);
    const { mode } = statSync(file);
    rmSync(file);
    try {
      await expectHealth({
        engine_revision: "source-tree",
        engine_source: "site",
        engine_source_reason: reason,
        chroot_revision: CHROOT_REVISION,
        site_state: "configured",
      });
    } finally {
      writeFileSync(file, bytes, { mode });
    }
  }
  await expectHealth({ engine_source: "chroot" });
});
