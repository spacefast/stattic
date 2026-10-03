import { expect, test } from "bun:test";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { FINALIZER_PROTOCOL } from "../../packages/routing/src/protocol.generated.ts";

type MigrationFailure = { ok: false; code: string; message: string };

// Admission runs before the connection. An invalid URL makes any attempt to
// connect observable without starting a database or issuing migration SQL.
test("migration artifact admission checks format and statement capacity before connecting", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "stattic-db-admission-"));
  const apply = (format: string, count: number): MigrationFailure => {
    const artifact = path.join(root, "migrations.json");
    writeFileSync(
      artifact,
      JSON.stringify({
        format,
        artifact_kind: "zero_migrations",
        statements: Array.from({ length: count }, (_, index) => `SELECT ${index}`),
      }),
    );
    const result = Bun.spawnSync([
      "php",
      path.join(import.meta.dir, "db-broker-cli.php"),
      JSON.stringify({ action: "migrate", path: artifact, url: "not a url at all" }),
    ]);
    expect(result.exitCode).toBe(0);
    expect(result.stderr.toString()).toBe("");
    return JSON.parse(result.stdout.toString()).migrate;
  };
  try {
    expect(apply("stattic.zero.migrations.v0", 1)).toEqual({
      ok: false,
      code: "zero_migration_artifact_invalid",
      message: "Zero migration artifact format is unsupported.",
    });
    const limit = FINALIZER_PROTOCOL.limits.zeroMigrationStatementsMax;
    expect(apply("stattic.zero.migrations.v1", limit)).toMatchObject({
      ok: false,
      code: "zero_db_url_invalid",
    });
    expect(apply("stattic.zero.migrations.v1", limit + 1)).toEqual({
      ok: false,
      code: "zero_migration_artifact_invalid",
      message: "Zero migration artifact has too many statements.",
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
