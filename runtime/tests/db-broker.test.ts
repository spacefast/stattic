// Behavioural coverage for the parent-process MySQL broker in
// engine/shared/db-broker.php. Tenant QuickJS no longer shares this raw SQL
// protocol; its native host accepts only structured, artifact-scoped operations.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { FIXTURE_DDL, op } from "./db-broker-corpus.ts";
import {
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const CLI_PATH = path.resolve(import.meta.dir, "db-broker-cli.php");
const MYSQL_ROOT_PASSWORD = "br0k3r-secret-pw";
const MYSQL_DATABASE = "broker_test";

let mysql: MysqlContainer;
const artifactRoot = mkdtempSync(path.join(os.tmpdir(), "stattic-db-broker-migrations-"));

beforeAll(async () => {
  mysql = await startMysqlContainer({
    namePrefix: "stattic-db-broker",
    database: MYSQL_DATABASE,
    rootPassword: MYSQL_ROOT_PASSWORD,
  });
  mysql.exec(FIXTURE_DDL);
}, 600_000);

afterAll(() => {
  stopMysqlContainers();
  rmSync(artifactRoot, { recursive: true, force: true });
});

// --- drivers --------------------------------------------------------------------------

type CliRequest = {
  space?: string;
  databases?: typeof d1Databases;
  migrate?: boolean;
  action?: string;
  url?: string;
  source?: string | null;
  capabilities?: string[];
  operations?: string[];
  path?: string;
  hard?: boolean;
};

type CliResponse = {
  error?: string;
  responses?: string[];
  migrate?: { ok: true } | { ok: false; code: string; message: string };
  metrics?: {
    operations: number;
    connectMs: number;
    queryMs: number;
    executeMs: number;
    stmtCacheHits: number;
    stmtCacheMisses: number;
  } | null;
  abandoned?: string;
};

/** Raw stdout of a CLI run that is expected to die, so its exit code is not a failure. */
async function phpRaw(
  request: CliRequest,
  env: Record<string, string> = {},
): Promise<{ stdout: string; stderr: string; exitCode: number }> {
  const proc = Bun.spawn(["php", CLI_PATH, JSON.stringify({ url: mysql.url, ...request })], {
    env: { ...process.env, ...env },
    stdout: "pipe",
    stderr: "pipe",
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  return { stdout, stderr, exitCode };
}

async function php(request: CliRequest, env: Record<string, string> = {}): Promise<CliResponse> {
  const { stdout, stderr, exitCode } = await phpRaw(request, env);
  if (exitCode !== 0) {
    throw new Error(`db-broker-cli.php exited ${exitCode}: ${stderr}\n${stdout}`);
  }
  return JSON.parse(stdout.trim().split("\n").pop() as string) as CliResponse;
}

// --- transaction semantics ---------------------------------------------------------------

test("an explicit transaction commits and is visible afterwards", async () => {
  const { responses } = await php({
    operations: [
      op({ mode: "execute", sql: "DELETE FROM lifecycle" }),
      op({ mode: "transaction_begin" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (1, 'committed')" }),
      op({ mode: "transaction_commit" }),
      op({ sql: "SELECT v FROM lifecycle WHERE id = 1" }),
    ],
  });
  expect(responses?.[1]).toBe('{"ok":true}');
  expect(responses?.[3]).toBe('{"ok":true}');
  expect(responses?.[4]).toBe('{"ok":true,"rows":[{"v":"committed"}]}');
});

test("an explicit rollback discards the write", async () => {
  const { responses } = await php({
    operations: [
      op({ mode: "execute", sql: "DELETE FROM lifecycle" }),
      op({ mode: "transaction_begin" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (2, 'rolled back')" }),
      op({ mode: "transaction_rollback" }),
      op({ sql: "SELECT COUNT(*) AS n FROM lifecycle WHERE id = 2" }),
    ],
  });
  expect(responses?.[4]).toBe('{"ok":true,"rows":[{"n":0}]}');
});

test("a second begin while a transaction is open is refused", async () => {
  const { responses } = await php({
    operations: [op({ mode: "transaction_begin" }), op({ mode: "transaction_begin" })],
  });
  expect(JSON.parse(responses?.[1] as string).code).toBe("zero_db_transaction_active");
});

// The parent lane's own state edges. The runner never reaches these — it
// refuses every control spelling outright (transaction_control_tests in
// db.rs) — so their codes are the broker's alone to hold.
test("transaction control state edges answer the broker's own codes", async () => {
  const { responses } = await php({
    operations: [
      op({ mode: "transaction_commit" }),
      op({ mode: "transaction_rollback" }),
      op({ mode: "transaction", statements: [] }),
      op({
        mode: "transaction",
        statements: Array.from({ length: 65 }, () => ({ sql: "SELECT 1" })),
      }),
    ],
  });
  const refusals = (responses ?? []).map((body) => JSON.parse(String(body)));
  expect(refusals.map((refusal) => refusal.code)).toEqual([
    "zero_db_transaction_missing",
    "zero_db_transaction_missing",
    "zero_db_transaction_invalid",
    "zero_db_transaction_invalid",
  ]);
  // An oversized batch says how big it was and what the limit is.
  expect(refusals[3].message).toBe(
    "Database batch has 65 statements; the limit is 64. Split it into smaller batches.",
  );
});

test("a batch transaction rolls back entirely when one statement fails", async () => {
  const { responses } = await php({
    operations: [
      op({ mode: "execute", sql: "DELETE FROM lifecycle" }),
      op({
        mode: "transaction",
        statements: [
          { mode: "execute", sql: "INSERT INTO lifecycle VALUES (3, 'first')" },
          { mode: "execute", sql: "INSERT INTO lifecycle VALUES (3, 'duplicate key')" },
        ],
      }),
      op({ sql: "SELECT COUNT(*) AS n FROM lifecycle" }),
    ],
  });
  expect(JSON.parse(responses?.[1] as string).code).toBe("zero_db_execute_failed");
  expect(responses?.[2]).toBe('{"ok":true,"rows":[{"n":0}]}');
});

test("a batch transaction returns one result per statement", async () => {
  const { responses } = await php({
    operations: [
      op({ mode: "execute", sql: "DELETE FROM lifecycle" }),
      op({
        mode: "transaction",
        statements: [
          { mode: "execute", sql: "INSERT INTO lifecycle VALUES (4, 'a')" },
          { mode: "execute", sql: "INSERT INTO lifecycle VALUES (5, 'b')" },
          { sql: "SELECT COUNT(*) AS n FROM lifecycle" },
        ],
      }),
    ],
  });
  const batch = JSON.parse(responses?.[1] as string) as { ok: boolean; results: unknown[] };
  expect(batch.ok).toBe(true);
  expect(batch.results).toEqual([
    { affectedRows: 1, lastInsertId: 0, ok: true },
    { affectedRows: 1, lastInsertId: 0, ok: true },
    { ok: true, rows: [{ n: 2 }] },
  ]);

  // A write sent in query mode, as a D1 client's all() sends it, still reports
  // what it changed next to its empty row list.
  const { responses: written } = await php({
    operations: [op({ sql: "DELETE FROM lifecycle WHERE id = ?", params: [4] })],
  });
  expect(written?.[0]).toBe('{"affectedRows":1,"lastInsertId":0,"ok":true,"rows":[]}');
});

test("a transaction abandoned by a dying request leaves no rows and no locks", async () => {
  await php({ operations: [op({ mode: "execute", sql: "DELETE FROM lifecycle" })] });

  // SIGKILL between the write and the commit: no shutdown functions, no
  // destructors, no ROLLBACK from that process. Only the pooled connection's
  // reset on reuse can clean this up.
  const killed = await phpRaw({
    action: "abandon_transaction",
    hard: true,
    operations: [
      op({ mode: "transaction_begin" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (6, 'abandoned')" }),
    ],
  });
  expect(killed.stdout).toContain('"abandoned":"hard"');
  expect(killed.exitCode).not.toBe(0);

  // A later request must see no row and take the same key without blocking on a
  // lock the dead request left behind.
  const { responses } = await php({
    operations: [
      op({ sql: "SELECT COUNT(*) AS n FROM lifecycle WHERE id = 6" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (6, 'reclaimed')" }),
    ],
  });
  expect(responses?.[0]).toBe('{"ok":true,"rows":[{"n":0}]}');
  expect(JSON.parse(responses?.[1] as string).ok).toBe(true);
});

test("a transaction left open at the end of a request is rolled back", async () => {
  await php({ operations: [op({ mode: "execute", sql: "DELETE FROM lifecycle" })] });
  await php({
    action: "abandon_transaction",
    operations: [
      op({ mode: "transaction_begin" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (7, 'never committed')" }),
    ],
  });
  const { responses } = await php({
    operations: [op({ sql: "SELECT COUNT(*) AS n FROM lifecycle WHERE id = 7" })],
  });
  expect(responses?.[0]).toBe('{"ok":true,"rows":[{"n":0}]}');
});

// --- efficiency behaviour ------------------------------------------------------------------

test("repeated SQL reuses one prepared statement", async () => {
  const { metrics } = await php({
    operations: Array.from({ length: 20 }, () =>
      op({ sql: "SELECT id FROM dt WHERE id = ?", params: [1] }),
    ),
  });
  expect(metrics?.operations).toBe(20);
  expect(metrics?.stmtCacheMisses).toBe(1);
  expect(metrics?.stmtCacheHits).toBe(19);
});

test("the statement cache is bounded and evicts", async () => {
  // 40 distinct statements against a cache of 32: every one is a miss.
  const operations = Array.from({ length: 40 }, (_, index) =>
    op({ sql: `SELECT ${index} AS v, id FROM dt WHERE id = ?`, params: [1] }),
  );
  const { metrics } = await php({ operations });
  expect(metrics?.stmtCacheMisses).toBe(40);
  expect(metrics?.stmtCacheHits).toBe(0);

  // Prepared statements are a server resource: eviction must close them, so the
  // server never holds more than the cache bound for this link.
  const { responses } = await php({
    operations: [...operations, op({ sql: "SHOW SESSION STATUS LIKE 'Prepared_stmt_count'" })],
  });
  const status = JSON.parse(responses?.[40] as string) as { rows: Array<{ Value: string }> };
  expect(Number(status.rows[0]?.Value)).toBeLessThanOrEqual(33);
});

test("a 20-statement batch is one brokered call", async () => {
  const statements = Array.from({ length: 20 }, (_, index) => ({
    mode: "execute",
    sql: "INSERT INTO lifecycle VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
    params: [100 + index, `batch-${index}`],
  }));
  const { responses, metrics } = await php({
    operations: [op({ mode: "transaction", statements })],
  });
  const batch = JSON.parse(responses?.[0] as string) as { ok: boolean; results: unknown[] };
  expect(batch.ok).toBe(true);
  expect(batch.results.length).toBe(20);
  // One prepare shared by all 20 executions.
  expect(metrics?.stmtCacheMisses).toBe(1);
  expect(metrics?.stmtCacheHits).toBe(19);
});

test("an unsigned BIGINT parameter past PHP's integer range round trips exactly", async () => {
  // PHP has no unsigned 64-bit integer and mysqli cannot bind one, so a literal
  // past the signed range is carried as its exact digits and bound as a string.
  // Writes stay exact, at the cost of `SELECT ?` echoing back a string where
  // Rust echoes a number.
  const { responses } = await php({
    operations: [
      op({ mode: "execute", sql: "DELETE FROM lifecycle" }),
      op({
        mode: "execute",
        sql: "CREATE TABLE IF NOT EXISTS ubig (id BIGINT UNSIGNED PRIMARY KEY)",
      }),
      op({ mode: "execute", sql: "DELETE FROM ubig" }),
      '{"mode":"execute","sql":"INSERT INTO ubig VALUES (?)","params":[18446744073709551615]}',
      op({ sql: "SELECT id FROM ubig" }),
    ],
  });
  expect(responses?.[4]).toBe('{"ok":true,"rows":[{"id":18446744073709551615}]}');
});

test("a cached statement is not reused across different parameter types", async () => {
  // MySQL fixes a statement's result metadata to the types of its first
  // execution, so a cache keyed on SQL alone would decode `SELECT ?` bound to a
  // string as the double the previous execution bound.
  const { responses, metrics } = await php({
    operations: [
      op({ sql: "SELECT ? AS v", params: [1.5] }),
      op({ sql: "SELECT ? AS v", params: ["str"] }),
      op({ sql: "SELECT ? AS v", params: [7] }),
      op({ sql: "SELECT ? AS v", params: ["str again"] }),
    ],
  });
  expect(responses?.[0]).toBe('{"ok":true,"rows":[{"v":1.5}]}');
  expect(responses?.[1]).toBe('{"ok":true,"rows":[{"v":"str"}]}');
  expect(responses?.[2]).toBe('{"ok":true,"rows":[{"v":7}]}');
  expect(responses?.[3]).toBe('{"ok":true,"rows":[{"v":"str again"}]}');
  // Three distinct type signatures, and the fourth call reuses the string one.
  expect(metrics?.stmtCacheMisses).toBe(3);
  expect(metrics?.stmtCacheHits).toBe(1);
});

// --- bounded output ---------------------------------------------------------------------

test("each size cap refuses with its own code", async () => {
  const paramsRefused = await php({
    operations: [op({ sql: "SELECT 1", params: Array.from({ length: 257 }, () => 1) })],
  });
  expect(JSON.parse(String(paramsRefused.responses?.[0]))).toMatchObject({
    code: "zero_db_too_many_params",
    message:
      "Database statement has 257 bound parameters; the limit is 256. Split it into smaller statements.",
  });

  const rowsCapped = await php({
    operations: [op({ sql: "SELECT id FROM wide" })],
  });
  expect(JSON.parse(rowsCapped.responses?.[0] as string).ok).toBe(true);

  const rowsRefused = await php(
    { operations: [op({ sql: "SELECT id FROM wide" })] },
    {
      SPACEFAST_ZERO_DB_ROWS_MAX: "10",
    },
  );
  expect(JSON.parse(rowsRefused.responses?.[0] as string).code).toBe(
    "zero_db_result_too_many_rows",
  );

  const bytesRefused = await php(
    { operations: [op({ sql: "SELECT payload FROM wide" })] },
    {
      SPACEFAST_ZERO_DB_RESULT_BYTES_MAX: "4096",
    },
  );
  expect(JSON.parse(bytesRefused.responses?.[0] as string).code).toBe("zero_db_result_too_large");
});

// --- capability gating ---------------------------------------------------------------------

test("a read-only grant refuses a mutation", async () => {
  const { responses } = await php({
    capabilities: ["db.read"],
    operations: [
      op({ sql: "SELECT 1 AS a" }),
      op({ mode: "execute", sql: "INSERT INTO lifecycle VALUES (9, 'denied')" }),
    ],
  });
  expect(JSON.parse(responses?.[0] as string).ok).toBe(true);
  expect(JSON.parse(responses?.[1] as string).code).toBe("zero_db_capability_denied");
});

// --- the credential never escapes ------------------------------------------------------------

test("no failure path leaks the database URL", async () => {
  const probes: CliRequest[] = [
    // Wrong password, unreachable host, malformed URL, unparsable scheme.
    {
      url: "mysql://root:hunter2-secret-pw@127.0.0.1:1/nope",
      operations: [op({ sql: "SELECT 1" })],
    },
    {
      url: "mysql://root:hunter2-secret-pw@127.0.0.1:65534/nope",
      operations: [op({ sql: "SELECT 1" })],
    },
    {
      url: "postgres://root:hunter2-secret-pw@127.0.0.1:5432/nope",
      operations: [op({ sql: "SELECT 1" })],
    },
    { url: "not a url at all", operations: [op({ sql: "SELECT 1" })] },
    { url: mysql.url, source: "bogus-label", operations: [op({ sql: "SELECT 1" })] },
    // A live connection whose statements fail: driver text reaches the tenant,
    // so it must not carry connection details.
    { operations: [op({ sql: "SELECT * FROM does_not_exist" }), op({ sql: "SELEKT 1" })] },
  ];

  for (const probe of probes) {
    const result = await phpRaw(probe);
    const haystack = `${result.stdout}\n${result.stderr}`;
    expect(haystack).not.toContain("hunter2-secret-pw");
    expect(haystack).not.toContain("127.0.0.1");
    expect(haystack).not.toContain("mysql://");
  }
});

// --- session pinning ---------------------------------------------------------------------------

test("the PHP broker pins its database session", async () => {
  const sessionSql =
    "SELECT @@session.sql_mode AS sql_mode, @@session.time_zone AS time_zone," +
    " @@session.transaction_isolation AS isolation," +
    " @@session.collation_connection AS collation_connection," +
    " @@session.character_set_client AS character_set_client";

  const { responses } = await php({ operations: [op({ sql: sessionSql })] });
  const pinned = JSON.parse(responses?.[0] as string).rows[0] as Record<string, string>;
  expect(pinned.character_set_client).toBe("utf8mb4");
  expect(pinned.collation_connection).toBe("utf8mb4_0900_as_cs");
  expect(pinned.time_zone).toBe("+00:00");
  expect(pinned.isolation).toBe("REPEATABLE-READ");
  expect(pinned.sql_mode).toContain("STRICT_TRANS_TABLES");
});

// --- migrations ----------------------------------------------------------------------------------
//
// PHP applies a version's compiled schema (generate.php calls
// _stattic_db_broker_apply_migrations at publish). zero-db-dump.test.ts covers
// the publish-to-serve path; this proves the applier's contract: the statements
// land, a replay is a no-op, and anything else stops the publish.

/** Writes one `stattic.zero.migrations.v1` artifact and returns its path. */
function migrationsArtifact(name: string, statements: string[]): string {
  const file = path.join(artifactRoot, `${name}.json`);
  writeFileSync(
    file,
    JSON.stringify({
      format: "stattic.zero.migrations.v1",
      artifact_kind: "zero_migrations",
      statements,
    }),
  );
  return file;
}

test("compiled migrations apply, and applying the same artifact again is a no-op", async () => {
  // The statement shapes the compiler emits, in order. `DROP INDEX` names
  // an index that never existed, the ordinary case: the artifact cannot know
  // whether the last publish created the index it drops.
  const artifact = migrationsArtifact("apply", [
    "CREATE TABLE IF NOT EXISTS mig_notes (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE mig_notes ADD COLUMN title TEXT NULL",
    "DROP INDEX mig_notes_retired ON mig_notes",
    "CREATE INDEX mig_notes_title ON mig_notes (title(32))",
    "CREATE UNIQUE INDEX mig_notes_unique_title ON mig_notes (title(32))",
  ]);

  expect((await php({ action: "migrate", path: artifact })).migrate).toEqual({ ok: true });
  expect(mysql.exec("SELECT COUNT(*) FROM mig_notes")).toBe("0");
  expect(mysql.exec("SHOW INDEX FROM mig_notes WHERE Key_name = 'mig_notes_title'")).not.toBe("");

  // Duplicate column (1060) and duplicate key (1061) mean "already applied", so
  // a second publish of an unchanged schema succeeds instead of failing the
  // version.
  expect((await php({ action: "migrate", path: artifact })).migrate).toEqual({ ok: true });
  expect(
    mysql.exec(
      "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()" +
        " AND table_name = 'mig_notes' AND column_name = 'title'",
    ),
  ).toBe("1");
});

test("a statement the server rejects fails the publish instead of half-migrating", async () => {
  const artifact = migrationsArtifact("reject", [
    "CREATE TABLE IF NOT EXISTS mig_partial (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
    "ALTER TABLE mig_partial ADD COLUMN broken NOT_A_COLUMN_TYPE",
    "ALTER TABLE mig_partial ADD COLUMN never_reached TEXT NULL",
  ]);

  const { migrate } = await php({ action: "migrate", path: artifact });
  expect(migrate?.ok).toBe(false);
  expect(migrate).toMatchObject({ code: "zero_migration_failed" });
  // Statements before the failure stand, since DDL cannot be rolled back, but
  // the run stops there rather than skipping a statement the schema needs.
  expect(
    mysql.exec(
      "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()" +
        " AND table_name = 'mig_partial' AND column_name = 'never_reached'",
    ),
  ).toBe("0");
});

test("an artifact this engine did not compile is refused before it connects", async () => {
  const foreign = path.join(artifactRoot, "foreign.json");
  writeFileSync(
    foreign,
    JSON.stringify({
      format: "stattic.zero.migrations.v0",
      artifact_kind: "zero_migrations",
      statements: ["DROP TABLE mig_notes"],
    }),
  );

  // The unusable URL is the assertion: reaching the connection would answer
  // zero_db_url_invalid, so the artifact code proves the format check ran first
  // and no foreign statement was issued.
  const { migrate } = await php({ action: "migrate", path: foreign, url: "not a url at all" });
  expect(migrate).toEqual({
    ok: false,
    code: "zero_migration_artifact_invalid",
    message: "Zero migration artifact format is unsupported.",
  });
});

const d1Databases = [
  {
    binding: "DB",
    databaseName: "links",
    migrations: [
      {
        name: "0001.sql",
        sql: "CREATE TABLE links (slug TEXT PRIMARY KEY, url TEXT NOT NULL, clicks INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now'))); CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);",
      },
      {
        name: "0002.sql",
        sql: "ALTER TABLE links ADD COLUMN country TEXT; CREATE INDEX IF NOT EXISTS by_country ON links(country, created_at);",
      },
    ],
  },
];

type D1Statement = { sql: string; params?: Array<string | number | null> };
type D1Operation = (D1Statement | { mode: "transaction"; statements: D1Statement[] }) & {
  d1?: string | null;
};

async function d1(input: {
  space?: string;
  databases?: typeof d1Databases;
  migrate?: boolean;
  operations?: D1Operation[];
}) {
  const result = await php({
    action: "d1",
    space: input.space ?? "spc_d1",
    databases: input.databases ?? d1Databases,
    migrate: input.migrate ?? false,
    capabilities: ["db.read", "db.write"],
    operations: input.operations?.map((operation) => JSON.stringify({ d1: "DB", ...operation })),
  });
  return { ...result, responses: result.responses?.map((response) => JSON.parse(response)) };
}

test("D1 migrations replay without data loss, and prepared SQL preserves conflicts, dates and atomic batches", async () => {
  expect(await d1({ migrate: true })).toEqual({ responses: [] });
  const setup = await Promise.all(
    ["first", "second"].map((value) =>
      d1({
        operations: [
          {
            sql: "INSERT INTO settings (key,value) VALUES ('setup',?) ON CONFLICT(key) DO NOTHING",
            params: [value],
          },
        ],
      }),
    ),
  );
  expect(setup.map((r) => r.responses?.[0].affectedRows).sort()).toEqual([0, 1]);
  const inserted = await d1({
    operations: [
      {
        sql: "INSERT INTO links (slug,url) VALUES (?,?)",
        params: ["Launch", "https://example.com/a'\\b"],
      },
      {
        sql: "INSERT INTO links (slug,url) VALUES (?,?)",
        params: ["launch", "https://example.com/other"],
      },
      {
        sql: "INSERT INTO settings (key,value) VALUES ('password','first') ON CONFLICT(key) DO NOTHING",
      },
      {
        sql: "INSERT INTO settings (key,value) VALUES ('password','ignored') ON CONFLICT(key) DO NOTHING",
      },
      {
        sql: "INSERT INTO settings (key,value) VALUES ('password','updated') ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      },
      {
        sql: "INSERT INTO settings (key,value) VALUES ('password','updated') ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      },
    ],
  });
  expect(inserted.responses?.map((r) => r.affectedRows)).toEqual([1, 1, 1, 0, 1, 1]);
  expect(await d1({ migrate: true })).toEqual({ responses: [] });
  const read = await d1({
    operations: [
      { sql: "SELECT url, clicks FROM links WHERE slug=?", params: ["Launch"] },
      { sql: "SELECT value FROM settings WHERE key='password'" },
      {
        sql: "SELECT date(created_at) AS day, count(*) AS n FROM links WHERE created_at >= datetime('now','-7 days') GROUP BY day ORDER BY day",
      },
      {
        mode: "transaction",
        statements: [
          { sql: "UPDATE links SET clicks=clicks+1 WHERE slug='Launch'" },
          { sql: "INSERT INTO links (slug,url) VALUES ('Launch','duplicate')" },
        ],
      },
      { sql: "SELECT clicks FROM links WHERE slug='Launch'" },
    ],
  });
  expect(read.responses?.[0]).toMatchObject({
    ok: true,
    rows: [{ url: "https://example.com/a'\\b", clicks: 0 }],
  });
  expect(read.responses?.[1]).toMatchObject({ rows: [{ value: "updated" }] });
  expect(read.responses?.[2]).toMatchObject({
    rows: [{ day: new Date().toISOString().slice(0, 10), n: 2 }],
  });
  expect(read.responses?.[3]).toEqual({
    ok: false,
    code: "d1_error",
    message: "UNIQUE constraint failed",
  });
  expect(read.responses?.[4]).toMatchObject({ rows: [{ clicks: 0 }] });
  const edited = structuredClone(d1Databases);
  const migration = edited[0]?.migrations[0];
  if (!migration) throw new Error("Missing migration fixture");
  migration.sql += " -- edited";
  expect((await d1({ migrate: true, databases: edited })).error).toStartWith(
    "D1_MIGRATION_CHANGED:",
  );
});

test("D1 refuses table escapes and unsupported SQL before execution and isolates database identities", async () => {
  expect(await d1({ space: "spc_other", migrate: true })).toEqual({ responses: [] });
  const result = await d1({
    space: "spc_other",
    operations: [
      { sql: "SELECT * FROM links" },
      { sql: "SELECT * FROM links, notes" },
      { sql: "SELECT * FROM links JOIN notes ON 1=1" },
      { sql: "SELECT (SELECT body FROM notes) FROM links" },
      { sql: "SELECT * FROM links; DROP TABLE notes" },
      { sql: "SELECT LOAD_FILE('/etc/passwd') FROM links" },
      { sql: "SELECT * FROM links", d1: "OTHER" },
      { sql: "SELECT * FROM links", d1: null },
    ],
  });
  expect(result.responses?.[0]).toEqual({ ok: true, rows: [] });
  expect(result.responses?.slice(1).map((r) => [r.ok, r.code])).toEqual(
    Array.from({ length: 7 }, () => [false, "d1_error"]),
  );
});
