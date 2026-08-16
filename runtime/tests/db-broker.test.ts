// Differential and behavioural coverage for the MySQL capability broker in
// engine/shared/db-broker.php.
//
// The broker's contract is that it is indistinguishable from
// crates/stattic-zero-runner/src/db.rs. Two engines that disagree about how a
// DECIMAL, an unsigned BIGINT, a BIT column or a DATETIME becomes JSON diverge
// silently in production, so the corpus below drives the *same* operation text
// through the real Rust runner and through the PHP library against the *same*
// MySQL instance and compares the response bytes.
//
// The Rust side is driven end to end: a Zero endpoint hands its request body
// straight to the `__statticDbHost` host function and returns the answer
// verbatim, so the comparison covers the real binary, not a reimplementation of
// it. The PHP side goes through db-broker-cli.php the same way s3.test.ts drives
// s3-cli.php — the broker has no HTTP surface of its own because the transports
// that will wrap it are separate lanes.
import { afterAll, beforeAll, expect, test } from "bun:test";
import path from "node:path";

import { CORPUS, ECHO_ENDPOINT, FIXTURE_DDL, op } from "./db-broker-corpus.ts";
import { deploy, get, publicAccessConfig, type Runtime, startRuntime } from "./harness.ts";
import {
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const HOST = "db-broker.test";
const REPO_ROOT = path.resolve(import.meta.dir, "../..");
const CLI_PATH = path.resolve(import.meta.dir, "db-broker-cli.php");
const MYSQL_ROOT_PASSWORD = "br0k3r-secret-pw";
const MYSQL_DATABASE = "broker_test";

let rt: Runtime;
let mysql: MysqlContainer;

beforeAll(async () => {
  const build = Bun.spawnSync({
    cmd: [
      "cargo",
      "build",
      "--locked",
      "-p",
      "stattic-runtime-compiler",
      "--bin",
      "stattic-runtime",
    ],
    cwd: REPO_ROOT,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stderr.toString()}`);
  }

  mysql = await startMysqlContainer({
    namePrefix: "stattic-db-broker",
    database: MYSQL_DATABASE,
    rootPassword: MYSQL_ROOT_PASSWORD,
  });
  mysql.exec(FIXTURE_DDL);

  rt = await startRuntime({
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_ZERO_RUNNER_DEBUG: "1",
    },
  });
  await deploy(rt, {
    spaceId: "spc_db_broker",
    versionId: "ver_db_broker_1",
    metadata: { mode: "website", title: "DB broker" },
    files: { "index.html": "<h1>db broker</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "POST",
          path: "/api/db",
          source: ECHO_ENDPOINT,
          schema_hash: "sha256:broker",
          capabilities: { db: true },
          db: {
            schemaHash: "sha256:broker",
            tables: {
              rows: { physicalName: "dt", primaryKey: "id", columns: { id: "id" } },
            },
          },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "DB broker" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}, 600_000);

afterAll(() => {
  rt?.stop();
  stopMysqlContainers();
});

// --- drivers --------------------------------------------------------------------------

type CliRequest = {
  action?: string;
  url?: string;
  source?: string | null;
  capabilities?: string[];
  operations?: string[];
  frames?: unknown[];
  hard?: boolean;
};

type CliResponse = {
  responses?: string[];
  frames?: Array<{
    protocol: string;
    id: number;
    result?: unknown;
    error?: { code: string; message: string };
  }>;
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

/** Runs one operation through the real Rust runner and returns its response text. */
async function rust(operation: string): Promise<string> {
  const response = await get(rt, HOST, "/api/db", {
    method: "POST",
    headers: { "content-type": "application/octet-stream" },
    body: operation,
  });
  const text = await response.text();
  if (response.status !== 200) {
    throw new Error(`rust runner returned ${response.status}: ${text}`);
  }
  return text;
}

// --- the differential corpus ------------------------------------------------------------

test("PHP and Rust encode every operation identically", async () => {
  const phpResponses =
    (await php({ operations: CORPUS.map(([, operation]) => operation) })).responses ?? [];
  expect(phpResponses.length).toBe(CORPUS.length);

  const mismatches: string[] = [];
  for (const [index, [name, operation, compare, rustBody]] of CORPUS.entries()) {
    const fromRust = await rust(rustBody ?? operation);
    const fromPhp = phpResponses[index];
    const same =
      compare === "code"
        ? (JSON.parse(fromRust) as { code?: string }).code ===
          (JSON.parse(fromPhp) as { code?: string }).code
        : fromRust === fromPhp;
    if (!same) {
      mismatches.push(`${name}\n    rust: ${fromRust}\n    php : ${fromPhp}`);
    }
  }

  expect(mismatches.join("\n\n")).toBe("");
}, 300_000);

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

  // A later request must both see no row and be able to take the same key
  // without blocking on a lock the dead request left behind.
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
  // 40 distinct statements against a cache of 32: every one is a miss, and the
  // 40th eviction has closed the earliest entries rather than leaking them.
  const operations = Array.from({ length: 40 }, (_, index) =>
    op({ sql: `SELECT ${index} AS v, id FROM dt WHERE id = ?`, params: [1] }),
  );
  const { metrics } = await php({ operations });
  expect(metrics?.stmtCacheMisses).toBe(40);
  expect(metrics?.stmtCacheHits).toBe(0);

  // Prepared statements are a server resource: eviction must actually close
  // them, so the server never holds more than the cache bound for this link.
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
  // One connection acquisition for the whole batch, and one prepare shared by
  // all 20 executions.
  expect(metrics?.stmtCacheMisses).toBe(1);
  expect(metrics?.stmtCacheHits).toBe(19);
});

test("an unsigned BIGINT parameter past PHP's integer range round trips exactly", async () => {
  // PHP has no unsigned 64-bit integer and mysqli cannot bind one, so an
  // integer literal past the signed range is carried as its exact digits and
  // bound as a string. That keeps writes exact — which is what a capsule store
  // needs — at the cost of `SELECT ?` echoing it back as a string where Rust
  // echoes a number. The write path is the one that must not lose data.
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

test("the row cap and the byte cap each refuse with their own code", async () => {
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

// --- capability gating and frames ----------------------------------------------------------

test("broker frames carry results and errors under the protocol envelope", async () => {
  const { frames } = await php({
    action: "frames",
    frames: [
      {
        protocol: "spacefast.broker.v1",
        id: 1,
        capability: "db.read",
        payload: { sql: "SELECT 1 AS a" },
      },
      {
        protocol: "spacefast.broker.v1",
        id: 2,
        capability: "db.read",
        payload: { sql: "SELECT * FROM nope" },
      },
      { protocol: "spacefast.broker.v1", id: 3, capability: "fetch", payload: {} },
      { protocol: "wrong.protocol", id: 4, capability: "db.read", payload: {} },
    ],
  });
  expect(frames?.[0]).toEqual({
    protocol: "spacefast.broker.v1",
    id: 1,
    result: { ok: true, rows: [{ a: 1 }] },
  });
  expect(frames?.[1]?.error?.code).toBe("zero_db_query_failed");
  expect(frames?.[2]?.error?.code).toBe("zero_db_frame_invalid");
  expect(frames?.[3]?.error?.code).toBe("zero_db_frame_invalid");
});

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
    // so it must not carry connection details with it.
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

test("the PHP broker and Rust runner pin the same database session", async () => {
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

  const fromRust = JSON.parse(await rust(op({ sql: sessionSql }))).rows[0] as Record<
    string,
    string
  >;
  expect(fromRust).toEqual(pinned);
});
