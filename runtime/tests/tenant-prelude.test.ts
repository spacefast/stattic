// Containment proof for the in-process tenant hardening prelude
// (runtime/engine/runtime/tenant-prelude.php). Runs the tenant-PHP probe
// (runtime/tests/fixtures/tenant-prelude-probe.php) under the local `php` CLI,
// and asserts the boundary the prelude holds: a jailed filesystem view and a
// scrubbed environment.
//
// This is the whole containment story on wp.cloud: a site owns no php-fpm/uid/
// disable_functions knobs, so the only OS-level isolation is a separate site.
// A separate tenant pool with its own uid and disable_functions policy needs a
// provider capability, not a config file we ship.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { PHP_BINARY, RUNTIME_DIR } from "./harness.ts";

const PRELUDE_PATH = path.join(RUNTIME_DIR, "engine/runtime/tenant-prelude.php");
const PROBE_PATH = path.join(RUNTIME_DIR, "tests/fixtures/tenant-prelude-probe.php");

// The env a real worker carries when it reaches tenant dispatch. Platform
// control credentials are scrubbed. Application credentials used by the
// site's PHP runtime remain available.
const CONTROL_ENV = {
  SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "box-dispatch-token-tenant-must-never-see",
  SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON:
    '{"SPACEFAST_FUNCTIONS_DISPATCH_TOKEN":"box-dispatch-token-tenant-must-never-see"}',
} satisfies Record<string, string>;
const SITE_ENV = {
  DB_PASSWORD: "s3cr3t-db-pass",
  DB_USER: "wp_db_user",
  SPACEFAST_ACCESS_JWT: "eyJhbGciOiJFZERTQSJ9.site-scoped-same-team",
  AUTH_KEY: "wordpress-auth-key",
} satisfies Record<string, string>;

let siteRoot: string;
const SPACE_ID = "spc_prelude";
let phpRoot: string;
let scratchTmp: string;
let selfSecret: string;
let outsideSecret: string;
let docrootConfig: string;
let statticSecret: string;

beforeAll(() => {
  // One site with platform files, the active version's php-root, and its
  // scratch tmp.
  // realpath so paths match the prelude's own realpath-normalised open_basedir
  // (on macOS /var is a symlink to /private/var).
  siteRoot = realpathSync(mkdtempSync(path.join(os.tmpdir(), "tenant-prelude-site-")));
  const docroot = path.join(siteRoot, "htdocs");
  mkdirSync(path.join(docroot, ".stattic"), { recursive: true });
  docrootConfig = path.join(docroot, ".atomic-persistent-data.json");
  writeFileSync(docrootConfig, '{"DB_PASSWORD":"s3cr3t-db-pass"}');
  statticSecret = path.join(docroot, ".stattic", "jwks.json");
  writeFileSync(statticSecret, '{"keys":["runtime-signing-secret"]}');

  phpRoot = path.join(siteRoot, "versions", SPACE_ID);
  scratchTmp = path.join(siteRoot, "tmp", SPACE_ID);
  mkdirSync(phpRoot, { recursive: true });
  mkdirSync(scratchTmp, { recursive: true });
  selfSecret = path.join(phpRoot, "space-secret.txt");
  writeFileSync(selfSecret, `owned-by-${SPACE_ID}`);

  outsideSecret = path.join(siteRoot, "platform", "outside-secret.txt");
  mkdirSync(path.dirname(outsideSecret), { recursive: true });
  writeFileSync(outsideSecret, "outside-version-root");
});

afterAll(() => siteRoot && rmSync(siteRoot, { recursive: true, force: true }));

type ProbeResult = {
  space_id: string;
  open_basedir: string;
  baseline_read_outside_before_prelude: { ok: boolean };
  read_self: { ok: boolean; bytes: string | null };
  read_outside: { ok: boolean };
  read_docroot_config: { ok: boolean };
  read_stattic_secret: { ok: boolean };
  leaked_control_env: Record<string, string[]>;
  surviving_site_env: Record<string, string[]>;
  dangerous_functions_still_callable: Record<string, boolean>;
};

function runProbe(): ProbeResult {
  const result = spawnSync(PHP_BINARY, [PROBE_PATH], {
    encoding: "utf8",
    env: {
      ...process.env,
      ...CONTROL_ENV,
      ...SITE_ENV,
      STATTIC_PRELUDE_PATH: PRELUDE_PATH,
      STATTIC_PROBE_SPACE_ID: SPACE_ID,
      STATTIC_PROBE_PHP_ROOT: phpRoot,
      STATTIC_PROBE_SCRATCH_TMP: scratchTmp,
      STATTIC_PROBE_SELF_SECRET: selfSecret,
      STATTIC_PROBE_OUTSIDE_SECRET: outsideSecret,
      STATTIC_PROBE_DOCROOT_CONFIG: docrootConfig,
      STATTIC_PROBE_STATTIC_SECRET: statticSecret,
    },
  });
  if (result.status !== 0) {
    throw new Error(`probe exited ${result.status}: ${result.stdout}\n${result.stderr}`);
  }
  return JSON.parse(result.stdout) as ProbeResult;
}

test("prelude jails tenant PHP to its version root and scrubs control credentials", () => {
  const result = runProbe();

  // The jail is pinned to this space's php-root + its own tmp, nothing else.
  expect(result.open_basedir).toBe(`${phpRoot}:${scratchTmp}`);

  // Baseline: the outside file was readable before the prelude ran, so the
  // block below is the prelude's doing, not an ambient permission.
  expect(result.baseline_read_outside_before_prelude.ok).toBe(true);

  // Own code stays readable; everything outside the jail is denied.
  expect(result.read_self.ok).toBe(true);
  expect(result.read_self.bytes).toBe(`owned-by-${SPACE_ID}`);
  expect(result.read_outside.ok).toBe(false);
  expect(result.read_docroot_config.ok).toBe(false);
  expect(result.read_stattic_secret.ok).toBe(false);

  // No control credential survives on any surface a handler or subprocess reads.
  // (PHP encodes an empty map as `[]`, so assert on the key set.)
  expect(Object.keys(result.leaked_control_env)).toHaveLength(0);

  // Application credentials used by the site's PHP runtime stay readable.
  expect(result.surviving_site_env.DB_PASSWORD).toContain("getenv");
  expect(result.surviving_site_env.AUTH_KEY).toContain("getenv");
  expect(result.surviving_site_env.SPACEFAST_ACCESS_JWT).toContain("getenv");

  // The known, documented gap: function removal needs the provider pool, so the
  // exec family is still callable. The proof records it rather than hiding it.
  expect(result.dangerous_functions_still_callable.exec).toBe(true);
});
