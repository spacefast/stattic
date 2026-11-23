// A site runs the engine its persistent data states: an environment's channel
// of the Spacefast chroot, or `site` (or, from before boxes stated one,
// nothing) for its own release. These run the real thin loader against a
// chroot stood up beside the box: the selected revision's own loader body
// serves and proves itself while the site's own install root keeps its data,
// and a selected chroot that cannot serve answers 503, never the site's
// release. The control plane's commands ask engine/shared/engine-selection.php
// instead, so every case also checks that it answers the same.
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
  // The site starts on its own release: a lane it stated before the chroot
  // below exists would answer 503 to the harness's readiness check.
  rt = await startRuntime({ env: { SPACEFAST_TEST_CHROOT_ROOT: chrootRoot } });
  // The external's layout: the release without its binary, the binary under
  // usr/local/bin, and a channel link beside each. The revision constant is the
  // only byte that differs from the site's release, so a response naming it
  // came from the chroot engine.
  const siteRelease = path.dirname(rt.engineRoot);
  // Each request names the loader body that served it, so a response says
  // which engine's body ran and from which path.
  const body = path.join(rt.engineRoot, "loader/request.php");
  writeFileSync(
    body,
    readFileSync(body, "utf8").replace(
      "declare(strict_types=1);\n",
      "declare(strict_types=1);\nheader('X-Test-Loader-Body: ' . __DIR__);\n",
    ),
  );
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
  const persistentData = path.join(rt.root, ".atomic-persistent-data.json");
  writeFileSync(
    persistentData,
    `${JSON.stringify({
      ...JSON.parse(readFileSync(persistentData, "utf8")),
      SPACEFAST_RUNTIME_ENGINE_SOURCE: "chroot/production/latest",
    })}\n`,
  );
});

afterAll(() => {
  rt?.stop();
  if (chrootRoot) rmSync(chrootRoot, { recursive: true, force: true });
});

const chrootEngine = () => path.join(chrootRoot, "scripts", `sf-engine-${CHROOT_REVISION}`);

/** What engine-selection.php, which the control plane's commands ask, answers. */
function commandSelection(): {
  source: string;
  reason: string;
  chroot_revision: string | null;
  release_root: string | null;
} {
  const command = Bun.spawnSync({
    cmd: [
      "php",
      "-d",
      "auto_prepend_file=",
      "-r",
      // `php -r` runs no prepend, so the command loads the provider's itself,
      // as the control plane's command bootstrap does.
      "require $argv[1]; require $argv[2]; echo json_encode(_spacefast_runtime_engine_source($argv[3]));",
      path.join(import.meta.dir, "atomic-prepend.php"),
      path.join(rt.engineRoot, "shared/engine-selection.php"),
      path.dirname(rt.engineRoot),
    ],
    cwd: rt.root,
    env: { ...process.env, SPACEFAST_TEST_CHROOT_ROOT: chrootRoot },
    stdout: "pipe",
    stderr: "pipe",
  });
  expect(command.exitCode, command.stderr.toString()).toBe(0);
  return JSON.parse(command.stdout.toString());
}

/**
 * Health reports the thin loader's choice; the control plane's commands make
 * theirs with engine-selection.php from the site's release. Both must agree.
 */
async function expectSelection(
  expected: Record<string, string | number | null>,
  loaderBody?: string,
): Promise<void> {
  const response = await fetch(`${rt.baseUrl}/__spacefast/health.php`);
  expect(response.status).toBe(200);
  const health = await response.json();
  expect(health).toMatchObject(expected);
  if (loaderBody !== undefined) {
    expect(response.headers.get("x-test-loader-body")).toBe(loaderBody);
  }
  const selection = commandSelection();
  expect({
    engine_source: selection.source,
    engine_source_reason: selection.reason,
    chroot_revision: selection.chroot_revision,
  }).toEqual({
    engine_source: health.engine_source,
    engine_source_reason: health.engine_source_reason,
    chroot_revision: health.chroot_revision,
  });
}

/**
 * A stated engine that cannot serve: every request, health included, answers
 * 503 with why, and a command gets no engine to run.
 */
async function expectUnavailable(expected: {
  lane: string | null;
  reason: string;
  chroot_revision: string | null;
}): Promise<void> {
  for (const target of ["/__spacefast/health.php", "/"]) {
    const response = await fetch(`${rt.baseUrl}${target}`);
    expect(response.status).toBe(503);
    expect(response.headers.get("content-type")).toBe("application/problem+json");
    expect(response.headers.get("retry-after")).toBe("30");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(await response.json()).toEqual({
      type: "https://spacefast.com/problems/runtime_engine_unavailable",
      title: "Runtime engine unavailable",
      status: 503,
      code: "runtime_engine_unavailable",
      details: expected,
    });
  }
  expect(commandSelection()).toEqual({
    release_root: null,
    native_binary: null,
    source: "unavailable",
    reason: expected.reason,
    chroot_revision: expected.chroot_revision,
  });
}

function chrootNativeSha256(): string {
  return createHash("sha256")
    .update(
      readFileSync(
        path.join(chrootRoot, "usr/local/bin", `sf-bin-${CHROOT_REVISION}`, "stattic-runtime"),
      ),
    )
    .digest("hex");
}

function update(revision: string) {
  return api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/engine/update`,
    "update_engine",
    {},
    {
      revision,
      zip_url: "https://github.example/engines/chroot.zip",
      md5: "6d34a1f0c8b2e97d5a41f3c9b0d2e816",
      native_sha256: chrootNativeSha256(),
    },
  );
}

test("serves and proves the chroot revision the site selected", async () => {
  // `configured` means the engine found the site's storage, which it can only
  // do through the install root the loader named: the chroot engine is not
  // inside it. The body ran from the revision directory, never through the
  // channel link, so OPcache keys it by revision.
  await expectSelection(
    {
      engine_revision: CHROOT_REVISION,
      engine_source: "chroot",
      engine_source_reason: "chroot",
      chroot_revision: CHROOT_REVISION,
      loader_version: 1,
      site_state: "configured",
    },
    path.join(chrootEngine(), "engine/loader"),
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

  // The thin MU loader must boot a selected engine whose REST helper is
  // missing, as in an engine that predates REST hooks. Use the real channel
  // selector, not a preselected global.
  symlinkSync(
    `sf-engine-${CHROOT_REVISION}`,
    path.join(chrootRoot, "scripts/sf-engine-production-previous"),
  );
  const selectedEngine = path.join(chrootRoot, "scripts", `sf-engine-${CHROOT_REVISION}`);
  const rest = path.join(selectedEngine, "engine/shared/wordpress-rest.php");
  const restBytes = readFileSync(rest);
  const muPlugin = path.join(rt.root, "wp-content/mu-plugins/spacefast-content.php");
  mkdirSync(path.dirname(muPlugin), { recursive: true });
  copyFileSync(path.resolve(import.meta.dir, "../custom-redirects.php"), muPlugin);
  rmSync(rest);
  try {
    const boot = Bun.spawnSync({
      cmd: [
        "php",
        "-d",
        "auto_prepend_file=",
        "-r",
        `
        class Atomic_Persistent_Data { public string $SPACEFAST_RUNTIME_ENGINE_SOURCE = 'chroot/production/previous'; }
        define('SPACEFAST_RUNTIME_CHROOT_ROOT', $argv[1]);
        require $argv[2];
        echo json_encode(['wordpress' => 'served', 'release' => $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'], 'source' => $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE']]);
      `,
        chrootRoot,
        muPlugin,
      ],
    });
    expect(boot.exitCode, boot.stderr.toString()).toBe(0);
    expect(JSON.parse(boot.stdout.toString())).toEqual({
      wordpress: "served",
      release: selectedEngine,
      source: "chroot",
    });
  } finally {
    writeFileSync(rest, restBytes);
  }
});

/**
 * Gives the chroot revision a loader with these bytes, and a lock that digests
 * them, as a build of that loader would.
 */
function setChrootLoader(bytes: string): void {
  writeFileSync(path.join(chrootEngine(), "custom-redirects.php"), bytes);
  const command = Bun.spawnSync({
    cmd: [
      "php",
      "-d",
      "auto_prepend_file=",
      "-r",
      String.raw`require $argv[1];
$lock = json_decode(file_get_contents($argv[2]), false);
foreach ($lock->components as $component) {
    if ($component->id === 'immutable-loader') {
        $component->sha256 = 'sha256:' . hash_file('sha256', $argv[3]);
    }
}
unset($lock->digest);
$lock->digest = _stattic_component_digest($lock);
file_put_contents($argv[2], json_encode($lock, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));`,
      path.join(chrootEngine(), "engine/admin/components.php"),
      path.join(chrootEngine(), "engine/wordpress/components.json"),
      path.join(chrootEngine(), "custom-redirects.php"),
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  expect(command.exitCode, command.stderr.toString()).toBe(0);
}

test("the chroot receipt accepts only the site's own loader bytes", async () => {
  const updateRequired = async () => {
    const response = await update(CHROOT_REVISION);
    expect(response.status).toBe(409);
    expect(await response.json()).toMatchObject({
      code: "runtime_engine_update_required",
      details: { active_revision: CHROOT_REVISION, engine_source: "chroot" },
    });
  };
  // Every path the engine installs the loader at, as the installer reads them.
  // SAFETY: engine-manifest.json is the installer's own input; a shape change
  // fails the check below.
  const manifest = JSON.parse(
    readFileSync(path.resolve(import.meta.dir, "../engine-manifest.json"), "utf8"),
  ) as { aliases: { source: string; path: string }[] };
  const loaderCopies = manifest.aliases
    .filter((alias) => alias.source === "custom-redirects.php")
    .map((alias) => path.join(rt.root, alias.path));
  expect(loaderCopies).toContain(path.join(rt.root, "wp-content/mu-plugins/spacefast-content.php"));
  const muPlugin = path.join(rt.root, "wp-content/mu-plugins/spacefast-content.php");
  const loader = readFileSync(muPlugin, "utf8");
  const chrootLoader = path.join(chrootEngine(), "custom-redirects.php");
  const chrootLock = path.join(chrootEngine(), "engine/wordpress/components.json");
  const chrootLockBytes = readFileSync(chrootLock);

  // The site carries the chroot revision's loader: a chroot move that keeps
  // the loader needs no install.
  expect((await update(CHROOT_REVISION)).status).toBe(200);

  // A loader copy with other bytes is not this revision's, whatever it says.
  writeFileSync(muPlugin, `${loader}// a loader with other bytes\n`);
  try {
    await updateRequired();
  } finally {
    writeFileSync(muPlugin, loader);
  }

  // A chroot revision whose loader adds a managed HTTPS apex: the receipt
  // doesn't accept the site until its install lays the new loader down, so the apex
  // reaches every site the revision serves.
  const withApex = loader.replace(
    "$httpsApexes = ['space.fast', 'view.fast'];",
    "$httpsApexes = ['space.fast', 'view.fast', 'example.fast'];",
  );
  expect(withApex).not.toBe(loader);
  try {
    setChrootLoader(withApex);
    await updateRequired();
    for (const copy of loaderCopies) writeFileSync(copy, withApex);
    expect((await update(CHROOT_REVISION)).status).toBe(200);
  } finally {
    for (const copy of loaderCopies) writeFileSync(copy, loader);
    writeFileSync(chrootLoader, loader);
    writeFileSync(chrootLock, chrootLockBytes);
  }
  expect((await update(CHROOT_REVISION)).status).toBe(200);
});

test("a request before a first install publishes the release pointer is retried", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-first-install-"));
  try {
    const publicRoot = realpathSync(root);
    const installRoot = path.join(publicRoot, ".stattic");
    mkdirSync(installRoot);
    writeFileSync(path.join(installRoot, "publication.lock"), "");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(publicRoot, "index.php"),
    );
    const visit = () =>
      Bun.spawnSync({
        cmd: ["php", "-d", "auto_prepend_file=", path.join(publicRoot, "index.php")],
        env: { ...process.env, REQUEST_METHOD: "GET", REQUEST_URI: "/" },
      });

    // The installer has laid the loader down and its journal is open; the
    // pointer comes next.
    const journal = path.join(installRoot, "install-transaction.json");
    writeFileSync(journal, `${JSON.stringify({ phase: "publishing" })}\n`);
    const publishing = visit();
    expect(publishing.exitCode, publishing.stderr.toString()).toBe(0);
    expect(JSON.parse(publishing.stdout.toString())).toMatchObject({
      code: "runtime_engine_update_busy",
      status: 503,
    });

    // No install under way: a missing pointer is a broken site, not a wait.
    rmSync(journal);
    const broken = visit();
    expect(broken.exitCode).toBe(1);
    expect(broken.stdout.toString()).toBe("");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("runs the site's release only when the site states it or states nothing", async () => {
  const persistentData = path.join(rt.root, ".atomic-persistent-data.json");
  const selected = readFileSync(persistentData);
  for (const [data, reason] of [
    [RUNTIME_ATOMIC_DATA, "unselected"],
    [{ ...RUNTIME_ATOMIC_DATA, SPACEFAST_RUNTIME_ENGINE_SOURCE: "site" }, "site"],
  ] as const) {
    writeFileSync(persistentData, `${JSON.stringify(data)}\n`);
    try {
      await expectSelection(
        {
          engine_revision: "source-tree",
          engine_source: "site",
          engine_source_reason: reason,
          chroot_revision: null,
          loader_version: 1,
        },
        path.join(rt.engineRoot, "loader"),
      );
    } finally {
      writeFileSync(persistentData, selected);
    }
  }
});

test("a stated chroot lane that cannot serve answers 503, never the site's release", async () => {
  const persistentData = path.join(rt.root, ".atomic-persistent-data.json");
  const selected = readFileSync(persistentData);

  // A channel this chroot has no link for has nothing to offer, and a value
  // the control plane would never write names no engine at all.
  for (const [selection, expected] of [
    ["chroot/staging/latest", { lane: "chroot/staging/latest", reason: "chroot_missing" }],
    ["chroot/development/latest", { lane: null, reason: "selection_invalid" }],
  ] as const) {
    writeFileSync(
      persistentData,
      `${JSON.stringify({ ...RUNTIME_ATOMIC_DATA, SPACEFAST_RUNTIME_ENGINE_SOURCE: selection })}\n`,
    );
    try {
      await expectUnavailable({ ...expected, chroot_revision: null });
    } finally {
      writeFileSync(persistentData, selected);
    }
  }

  // Each file stands for one way the chroot fails the site, and the 503 names
  // it: a revision without its binary, and an engine without this selection
  // rule, whose loaders would stop the site reading its channel once installed.
  for (const [file, reason] of [
    [
      path.join(
        chrootRoot,
        "scripts",
        `sf-engine-${CHROOT_REVISION}`,
        "engine/wordpress/components.json",
      ),
      "chroot_containment_missing",
    ],
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
    // A revision from before the thin loader has no body to hand requests to.
    [
      path.join(chrootRoot, "scripts", `sf-engine-${CHROOT_REVISION}`, "engine/loader/request.php"),
      "chroot_engine_unsupported",
    ],
  ] as const) {
    const bytes = readFileSync(file);
    const { mode } = statSync(file);
    rmSync(file);
    // A valid older inventory can claim app support while lacking containment.
    if (reason === "chroot_containment_missing") {
      writeFileSync(
        file,
        JSON.stringify({ components: [{ id: "runtime-engine", provides: ["runtime.serving"] }] }),
      );
    }
    try {
      await expectUnavailable({
        lane: "chroot/production/latest",
        reason,
        chroot_revision: CHROOT_REVISION,
      });
    } finally {
      writeFileSync(file, bytes, { mode });
    }
  }
  await expectSelection({ engine_source: "chroot" });
});
