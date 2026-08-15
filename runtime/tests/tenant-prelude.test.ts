// Containment proof for the in-process tenant hardening prelude
// (runtime/engine/runtime/tenant-prelude.php). Runs the tenant-PHP probe
// (runtime/tests/fixtures/tenant-prelude-probe.php) under the local `php` CLI,
// two spaces on ONE simulated box, and asserts the boundary the prelude
// actually holds: a jailed filesystem view and a scrubbed environment,
// symmetric across spaces on the same box.
//
// This is the whole containment story on wp.cloud: a site owns no php-fpm/uid/
// disable_functions knobs, so the only OS-level isolation is a separate site.
// Same-team spaces sharing a box rely on this best-effort in-process boundary,
// which is why the team is the trust unit. A future kernel jail (per-space uid,
// disable_functions) would need a provider request, not a config file we ship.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { PHP_BINARY, RUNTIME_DIR } from "./harness.ts";

const PRELUDE_PATH = path.join(RUNTIME_DIR, "engine/runtime/tenant-prelude.php");
const PROBE_PATH = path.join(RUNTIME_DIR, "tests/fixtures/tenant-prelude-probe.php");

// The platform secrets a real worker carries when it reaches tenant dispatch:
// DB credentials, the raw persistent-data blob, an access JWT, a WordPress key.
// The prelude must remove every one from $_SERVER, $_ENV, and the C env.
const SECRET_ENV: Record<string, string> = {
  DB_PASSWORD: "s3cr3t-db-pass",
  DB_USER: "wp_db_user",
  SPACEFAST_ACCESS_JWT: "eyJhbGciOiJFZERTQSJ9.tenant-must-never-see-this",
  SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON: '{"DB_PASSWORD":"s3cr3t-db-pass"}',
  AUTH_KEY: "wordpress-auth-key",
};

let box: string;
type SpaceLayout = { id: string; phpRoot: string; tmp: string; selfSecret: string };
const spaces: Record<"A" | "B", SpaceLayout> = {} as never;
let docrootConfig: string;
let statticSecret: string;

beforeAll(() => {
  // One "box": a shared docroot with the platform's secret files, plus two
  // same-team space php-roots and their scratch tmps.
  // realpath so paths match the prelude's own realpath-normalised open_basedir
  // (on macOS /var is a symlink to /private/var).
  box = realpathSync(mkdtempSync(path.join(os.tmpdir(), "tenant-prelude-box-")));
  const docroot = path.join(box, "htdocs");
  mkdirSync(path.join(docroot, ".stattic"), { recursive: true });
  docrootConfig = path.join(docroot, ".atomic-persistent-data.json");
  writeFileSync(docrootConfig, '{"DB_PASSWORD":"s3cr3t-db-pass"}');
  statticSecret = path.join(docroot, ".stattic", "jwks.json");
  writeFileSync(statticSecret, '{"keys":["runtime-signing-secret"]}');

  for (const key of ["A", "B"] as const) {
    const id = `spc_prelude_${key}`;
    const phpRoot = path.join(box, "versions", id);
    const tmp = path.join(box, "tmp", id);
    mkdirSync(phpRoot, { recursive: true });
    mkdirSync(tmp, { recursive: true });
    const selfSecret = path.join(phpRoot, "space-secret.txt");
    writeFileSync(selfSecret, `owned-by-${id}`);
    spaces[key] = { id, phpRoot, tmp, selfSecret };
  }
});

afterAll(() => box && rmSync(box, { recursive: true, force: true }));

type ProbeResult = {
  space_id: string;
  open_basedir: string;
  baseline_read_sibling_before_prelude: { ok: boolean };
  read_self: { ok: boolean; bytes: string | null };
  read_sibling_space: { ok: boolean };
  read_docroot_config: { ok: boolean };
  read_stattic_secret: { ok: boolean };
  leaked_platform_env: Record<string, string[]>;
  dangerous_functions_still_callable: Record<string, boolean>;
};

// Run the probe as the space `self`, with `sibling` as the other same-box space.
function runProbe(self: SpaceLayout, sibling: SpaceLayout): ProbeResult {
  const result = spawnSync(PHP_BINARY, [PROBE_PATH], {
    encoding: "utf8",
    env: {
      ...process.env,
      ...SECRET_ENV,
      STATTIC_PRELUDE_PATH: PRELUDE_PATH,
      STATTIC_PROBE_SPACE_ID: self.id,
      STATTIC_PROBE_PHP_ROOT: self.phpRoot,
      STATTIC_PROBE_SCRATCH_TMP: self.tmp,
      STATTIC_PROBE_SELF_SECRET: self.selfSecret,
      STATTIC_PROBE_SIBLING_SECRET: sibling.selfSecret,
      STATTIC_PROBE_DOCROOT_CONFIG: docrootConfig,
      STATTIC_PROBE_STATTIC_SECRET: statticSecret,
    },
  });
  if (result.status !== 0) {
    throw new Error(`probe exited ${result.status}: ${result.stdout}\n${result.stderr}`);
  }
  return JSON.parse(result.stdout) as ProbeResult;
}

test("prelude jails each space to its own php-root and scrubs platform secrets", () => {
  const a = runProbe(spaces.A, spaces.B);
  const b = runProbe(spaces.B, spaces.A);

  // The jail is pinned to this space's php-root + its own tmp, nothing else.
  expect(a.open_basedir).toBe(`${spaces.A.phpRoot}:${spaces.A.tmp}`);

  // Baseline: the sibling secret WAS readable before the prelude ran — so the
  // block below is the prelude's doing, not an ambient permission.
  expect(a.baseline_read_sibling_before_prelude.ok).toBe(true);

  // Own code stays readable; everything outside the jail is denied.
  expect(a.read_self.ok).toBe(true);
  expect(a.read_self.bytes).toBe(`owned-by-${spaces.A.id}`);
  expect(a.read_sibling_space.ok).toBe(false); // another space's php-root
  expect(a.read_docroot_config.ok).toBe(false); // the raw wp.cloud config blob
  expect(a.read_stattic_secret.ok).toBe(false); // the .stattic/** runtime namespace

  // The pin is per-space and symmetric on a shared box.
  expect(b.open_basedir).toBe(`${spaces.B.phpRoot}:${spaces.B.tmp}`);
  expect(b.read_self.ok).toBe(true);
  expect(b.read_sibling_space.ok).toBe(false); // B cannot read A

  // No platform secret survives on any surface a handler or subprocess reads.
  // (PHP encodes an empty map as `[]`, so assert on the key set.)
  expect(Object.keys(a.leaked_platform_env)).toHaveLength(0);

  // The known, documented gap: function removal needs the provider pool, so the
  // exec family is still callable. The proof records it rather than hiding it.
  expect(a.dangerous_functions_still_callable.exec).toBe(true);
});
