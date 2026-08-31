// The management dump of a version's Zero database (admin/zero-db.php), against
// the real PHP engine.
//
// Two lanes. Routing, the management JWT, the contract shape and which tables a
// caller may address are all decided from the version's compiled artifacts
// before a database connection exists, so those tests need no MySQL. Only the
// row dump needs a live server plus PHP's mysqli extension, so it stands alone
// at the bottom of this file and brings up its own container.
import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  runtimeDbDumpResponseSchema,
  runtimeDbExportMetadataSchema,
  runtimeDbExportPageSchema,
} from "../../packages/common/src/contracts/runtime-db.ts";
import {
  api,
  deploy,
  dispatchCli,
  managementToken,
  publicAccessConfig,
  runtimeHttpPath,
  startRuntime,
  type Runtime,
} from "./harness.ts";
import { startMysqlContainer, stopMysqlContainers } from "./mysql-container.ts";

const SPACE_ID = "spc_zero_dump";
// A capsule with no tables: enough for the route, the scope refusal and the
// contract shape without a database.
const ZERO_VERSION_ID = "ver_zero_dump_1";
const STATIC_VERSION_ID = "ver_zero_dump_static";
const FUNCTIONS_VERSION_ID = "ver_zero_dump_functions";
const MIXED_VERSION_ID = "ver_zero_dump_mixed";
const UNSCOPED_VERSION_ID = "ver_zero_dump_unscoped";
const SCHEMA_HASH = `sha256:${"a".repeat(64)}`;
const ENDPOINT_SOURCE = "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });";

const MYSQL_ROOT_PASSWORD = "zero-dump-secret-pw";
const MYSQL_DATABASE = "zero_dump_test";

let rt: Runtime;
let mysqlRuntime: Runtime | null = null;

function dumpPath(
  versionId: string,
  query: Record<string, string> = {},
  spaceId: string = SPACE_ID,
): string {
  const search = new URLSearchParams(query).toString();
  return `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/zero/db/dump${
    search ? `?${search}` : ""
  }`;
}

async function dump(
  runtime: Runtime,
  versionId: string,
  query: Record<string, string> = {},
  spaceId: string = SPACE_ID,
): Promise<Response> {
  return api(runtime, "GET", dumpPath(versionId, query, spaceId), "zero_db_dump", {
    space_id: spaceId,
    version_id: versionId,
  });
}

function exportPath(
  versionId: string,
  query: Record<string, string> = {},
  spaceId: string = SPACE_ID,
): string {
  const search = new URLSearchParams(query).toString();
  return `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/zero/db/export${
    search ? `?${search}` : ""
  }`;
}

async function exportDatabase(
  runtime: Runtime,
  versionId: string,
  query: Record<string, string> = {},
  spaceId: string = SPACE_ID,
): Promise<Response> {
  return api(runtime, "GET", exportPath(versionId, query, spaceId), "zero_db_export", {
    space_id: spaceId,
    version_id: versionId,
  });
}

/** Reads a dump and validates it against the contract the control plane parses. */
async function dumpOk(
  runtime: Runtime,
  versionId: string,
  query: Record<string, string> = {},
  spaceId: string = SPACE_ID,
) {
  const response = await dump(runtime, versionId, query, spaceId);
  const text = await response.text();
  expect(`${response.status} ${text}`).toStartWith("200");
  return runtimeDbDumpResponseSchema.parse(JSON.parse(text));
}

async function errorCode(response: Response): Promise<string> {
  const body = (await response.json()) as { code?: string };
  return body.code ?? "";
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: ZERO_VERSION_ID,
    metadata: { mode: "website", title: "Zero DB dump" },
    files: { "index.html": "<h1>zero db dump</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/status",
          source: ENDPOINT_SOURCE,
          capabilities: { db: false },
        },
      ],
    },
    zero: {
      migrations: {
        format: "stattic.zero.migrations.v1",
        mode: "safe",
        schemaHash: SCHEMA_HASH,
        operations: [],
      },
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero DB dump" }),
      production_hostnames: ["zero-db-dump.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: STATIC_VERSION_ID,
    metadata: { mode: "website", title: "Zero DB dump static" },
    files: { "index.html": "<h1>static</h1>\n" },
  });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: FUNCTIONS_VERSION_ID,
    metadata: { mode: "website", title: "Zero DB dump functions" },
    files: { "index.html": "<h1>functions</h1>\n" },
    functions: {
      artifact: { appName: "shop", entry: "handler.ts" },
      grantedCapabilities: ["db.read", "db.write"],
    },
  });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: MIXED_VERSION_ID,
    metadata: { mode: "website", title: "Zero DB dump mixed" },
    files: { "index.html": "<h1>mixed</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          method: "GET",
          path: "/api/status",
          source: ENDPOINT_SOURCE,
          capabilities: { db: false },
        },
      ],
    },
    zero: {
      migrations: {
        format: "stattic.zero.migrations.v1",
        mode: "safe",
        schemaHash: SCHEMA_HASH,
        operations: [],
      },
    },
    functions: {
      artifact: { appName: "mixed", entry: "functions/health.ts" },
      grantedCapabilities: [],
    },
  });
}, 120_000);

afterAll(() => {
  rt?.stop();
  mysqlRuntime?.stop();
  stopMysqlContainers();
});

test("a version whose capsule declares no tables dumps an empty database", async () => {
  const dumped = await dumpOk(rt, ZERO_VERSION_ID);
  expect(dumped).toEqual({
    versionId: ZERO_VERSION_ID,
    // artifactId is the control plane's name for the build; the engine reports
    // only the version it was asked about.
    artifactId: null,
    schemaHash: SCHEMA_HASH,
    tables: [],
  });
});

test("a version with no Zero config at all answers the same shape", async () => {
  const dumped = await dumpOk(rt, STATIC_VERSION_ID);
  expect(dumped).toEqual({
    versionId: STATIC_VERSION_ID,
    artifactId: null,
    schemaHash: null,
    tables: [],
  });
});

/** `{table: "status code"}` for a set of candidate table names. */
async function refusals(names: readonly string[]): Promise<Record<string, string>> {
  const results = await Promise.all(
    names.map(async (table) => {
      const response = await dump(rt, ZERO_VERSION_ID, { table });
      return [table, `${response.status} ${await errorCode(response)}`] as const;
    }),
  );
  return Object.fromEntries(results);
}

test("only tables this version's capsule declares can be addressed", async () => {
  // WordPress core, another space's scoped tables and the db-broker fixture all
  // live in the same MySQL as a capsule's own. None belongs to this capsule, so
  // all three are refused before a connection exists.
  expect(await refusals(["wp_users", "sf_spc_other_todos_0123456789", "dt"])).toEqual({
    wp_users: "404 zero_db_table_not_found",
    sf_spc_other_todos_0123456789: "404 zero_db_table_not_found",
    dt: "404 zero_db_table_not_found",
  });
});

test("a table name that is not an identifier is refused before anything reads it", async () => {
  expect(await refusals(["wp_users; DROP TABLE wp_users", "../../etc/passwd", "todos`"])).toEqual({
    "wp_users; DROP TABLE wp_users": "422 zero_db_table_invalid",
    "../../etc/passwd": "422 zero_db_table_invalid",
    "todos`": "422 zero_db_table_invalid",
  });
});

test("a Functions version is refused rather than answered as an empty database", async () => {
  // A worker's tables are created over the relay under names nothing declares.
  // `tables: []` would read as "your worker stored nothing", the one answer this
  // route must never give.
  const response = await dump(rt, FUNCTIONS_VERSION_ID);
  expect(response.status).toBe(409);
  expect(await errorCode(response)).toBe("zero_db_kind_unsupported");
});

test("a mixed version keeps its capsule database surface", async () => {
  const dumped = await dumpOk(rt, MIXED_VERSION_ID);
  expect(dumped).toEqual({
    versionId: MIXED_VERSION_ID,
    artifactId: null,
    schemaHash: SCHEMA_HASH,
    tables: [],
  });
});

test("dumping a version this runtime never stored is a 404", async () => {
  const response = await dump(rt, "ver_zero_dump_missing");
  expect(response.status).toBe(404);
  expect(await errorCode(response)).toBe("version_not_found");
});

test("the dump requires a management JWT scoped to this action, space and version", async () => {
  const unauthenticated = await fetch(`${rt.baseUrl}${runtimeHttpPath(dumpPath(ZERO_VERSION_ID))}`);
  expect(unauthenticated.status).toBe(401);

  const wrongAction = await fetch(`${rt.baseUrl}${runtimeHttpPath(dumpPath(ZERO_VERSION_ID))}`, {
    headers: { authorization: `Bearer ${managementToken("read_state")}` },
  });
  expect(wrongAction.status).toBe(403);

  const wrongSpace = await api(rt, "GET", dumpPath(ZERO_VERSION_ID), "zero_db_dump", {
    space_id: "spc_someone_else",
    version_id: ZERO_VERSION_ID,
  });
  expect(wrongSpace.status).toBe(403);

  const wrongVersion = await api(rt, "GET", dumpPath(ZERO_VERSION_ID), "zero_db_dump", {
    space_id: SPACE_ID,
    version_id: STATIC_VERSION_ID,
  });
  expect(wrongVersion.status).toBe(403);
});

test("the dump runs the same handler over the SSH dispatch transport", async () => {
  const result = await dispatchCli(
    rt,
    JSON.stringify({
      method: "GET",
      path: runtimeHttpPath(dumpPath(ZERO_VERSION_ID)),
      authorization: `Bearer ${managementToken("zero_db_dump", {
        space_id: SPACE_ID,
        version_id: ZERO_VERSION_ID,
      })}`,
    }),
    { env: { PATH: process.env.PATH, HOME: process.env.HOME } },
  );
  expect(
    result.exitCode,
    `dispatch exited in ${rt.root} with stdout:\n${result.stdout}\nstderr:\n${result.stderr}`,
  ).toBe(0);
  const envelope = JSON.parse(result.stdout) as { status: number; body: unknown };
  expect(envelope.status).toBe(200);
  expect(runtimeDbDumpResponseSchema.parse(envelope.body)).toEqual({
    versionId: ZERO_VERSION_ID,
    artifactId: null,
    schemaHash: SCHEMA_HASH,
    tables: [],
  });
});

// --- live database --------------------------------------------------------------------
//
// REQUIRES: docker for the MySQL server, and a PHP built with mysqli, which the
// broker connects through. Nothing above this line depends on either.
test("rows come back for the capsule's scoped tables, ordered and bounded by the limit", async () => {
  const mysql = await startMysqlContainer({
    namePrefix: "stattic-zero-dump",
    database: MYSQL_DATABASE,
    rootPassword: MYSQL_ROOT_PASSWORD,
  });
  const spaceId = "spc_zero_dump_live";
  const versionId = "ver_zero_dump_live_1";
  const runtime = await startRuntime({
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_ZERO_DB_READ_DEADLINE_MS: "100",
    },
  });
  mysqlRuntime = runtime;

  // Finalize compiles the capsule and applies its migrations, so the engine
  // creates the physical tables below, not the fixture.
  await deploy(runtime, {
    spaceId,
    versionId,
    metadata: { mode: "website", title: "Zero DB dump live" },
    files: { "index.html": "<h1>zero db dump live</h1>\n" },
    serving: {
      zero_runs: [
        {
          run_id: "query_notes",
          source: ENDPOINT_SOURCE,
          schema_hash: SCHEMA_HASH,
          capabilities: { db: true },
          db: {
            schemaHash: SCHEMA_HASH,
            tables: {
              notes: {
                physicalName: "sf_spc_zero_dump_live_notes_1f76cb914b",
                primaryKey: "id",
                columns: {
                  id: "note_id",
                  createdAt: { physicalName: "note_created_at" },
                  title: { physicalName: "note_title" },
                },
              },
            },
          },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero DB dump live" }),
      production_hostnames: ["zero-db-dump-live.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  mysql.exec(
    "INSERT INTO sf_spc_zero_dump_live_notes_1f76cb914b (note_id, note_created_at, note_title) VALUES (1, '2026-01-01T00:00:00.000Z', 'first'), (2, '2026-01-01T00:00:00.000Z', 'second'), (3, '2026-01-02T00:00:00.000Z', 'third')",
  );

  const all = await dumpOk(runtime, versionId, {}, spaceId);
  expect(all.versionId).toBe(versionId);
  expect(all.schemaHash).toBe(SCHEMA_HASH);
  expect(all.tables.map((table) => table.name)).toEqual(["notes"]);
  expect(all.tables[0]?.rows).toEqual([
    { note_id: 1, note_created_at: "2026-01-01T00:00:00.000Z", note_title: "first" },
    { note_id: 2, note_created_at: "2026-01-01T00:00:00.000Z", note_title: "second" },
    { note_id: 3, note_created_at: "2026-01-02T00:00:00.000Z", note_title: "third" },
  ]);

  // Re-apply runs the statements the deploy already ran, against a schema that
  // now holds rows. Replaying a stored plan has to be a no-op: repairing a
  // half-created schema must not empty the table.
  const reapplied = await api(
    runtime,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/zero/migrate`,
    "apply_zero_migrations",
    { space_id: spaceId, version_id: versionId },
  );
  expect(reapplied.status).toBe(200);
  expect(await reapplied.json()).toEqual({
    space_id: spaceId,
    version_id: versionId,
    applied: true,
  });
  expect(mysql.exec("SELECT COUNT(*) FROM sf_spc_zero_dump_live_notes_1f76cb914b")).toBe("3");

  // Naming the table answers with that table alone; the limit bounds rows, not
  // tables.
  const named = await dumpOk(runtime, versionId, { table: "notes", limit: "2" }, spaceId);
  expect(named.tables.map((table) => table.name)).toEqual(["notes"]);
  expect(named.tables[0]?.rows).toEqual([
    { note_id: 1, note_created_at: "2026-01-01T00:00:00.000Z", note_title: "first" },
    { note_id: 2, note_created_at: "2026-01-01T00:00:00.000Z", note_title: "second" },
  ]);

  // The same scope refusal the no-database lane proves, here against a table
  // that really exists in this database.
  const foreign = await dump(
    runtime,
    versionId,
    { table: "sf_spc_zero_dump_live_notes_1f76cb914b" },
    spaceId,
  );
  expect(foreign.status).toBe(404);
  expect(await errorCode(foreign)).toBe("zero_db_table_not_found");

  const metadataResponse = await exportDatabase(runtime, versionId, {}, spaceId);
  expect(metadataResponse.status).toBe(200);
  expect(runtimeDbExportMetadataSchema.parse(await metadataResponse.json())).toEqual({
    spaceId,
    versionId,
    schemaHash: SCHEMA_HASH,
    consistency: "per_page",
    ordering: { tables: "name_asc", rows: "created_at_id_asc" },
    tables: ["notes"],
  });
  const firstExportResponse = await exportDatabase(
    runtime,
    versionId,
    { table: "notes", schemaHash: SCHEMA_HASH, limit: "2" },
    spaceId,
  );
  expect(firstExportResponse.status).toBe(200);
  const firstExport = runtimeDbExportPageSchema.parse(await firstExportResponse.json());
  expect(firstExport.rows.map((row) => row.note_id)).toEqual([1, 2]);
  expect(firstExport.cursor).toBeString();
  const secondExportResponse = await exportDatabase(
    runtime,
    versionId,
    {
      table: "notes",
      schemaHash: SCHEMA_HASH,
      limit: "2",
      cursor: firstExport.cursor ?? "",
    },
    spaceId,
  );
  expect(secondExportResponse.status).toBe(200);
  const secondExport = runtimeDbExportPageSchema.parse(await secondExportResponse.json());
  expect(secondExport.rows.map((row) => row.note_id)).toEqual([3]);
  expect(secondExport.cursor).toBeNull();

  const staleExport = await exportDatabase(
    runtime,
    versionId,
    { table: "notes", schemaHash: `sha256:${"b".repeat(64)}` },
    spaceId,
  );
  expect(staleExport.status).toBe(409);
  expect(await errorCode(staleExport)).toBe("zero_db_export_schema_changed");

  // A second version whose compiled artifacts point at another Space's scoped
  // table. This deploy really creates that table, and the dump still refuses:
  // the physical name is not the one this Space's scoping rule produces.
  await deploy(runtime, {
    spaceId,
    versionId: UNSCOPED_VERSION_ID,
    metadata: { mode: "website", title: "Zero DB dump unscoped" },
    files: { "index.html": "<h1>unscoped</h1>\n" },
    serving: {
      zero_runs: [
        {
          run_id: "query_notes",
          source: ENDPOINT_SOURCE,
          schema_hash: SCHEMA_HASH,
          capabilities: { db: true },
          db: {
            schemaHash: SCHEMA_HASH,
            tables: {
              notes: {
                physicalName: "sf_spc_other_space_notes_8d8f76bb72",
                primaryKey: "id",
                columns: { id: "note_id", title: { physicalName: "note_title" } },
              },
            },
          },
        },
      ],
    },
  });
  const unscoped = await dump(runtime, UNSCOPED_VERSION_ID, {}, spaceId);
  expect(unscoped.status).toBe(409);
  expect(await errorCode(unscoped)).toBe("zero_db_table_unscoped");

  // The scope check runs before the requested-table lookup, so no name reaches
  // the database.
  const unscopedNamed = await dump(runtime, UNSCOPED_VERSION_ID, { table: "notes" }, spaceId);
  expect(unscopedNamed.status).toBe(409);
  expect(await errorCode(unscopedNamed)).toBe("zero_db_table_unscoped");

  // Hold a real MySQL table lock from another connection. The marker row is the
  // signal: the export starts only after the server granted the lock, with no
  // timing guess.
  const lockClient = Bun.spawn(
    [
      "docker",
      "exec",
      "-i",
      mysql.name,
      "mysql",
      "-uroot",
      `-p${MYSQL_ROOT_PASSWORD}`,
      "--default-character-set=utf8mb4",
      "--unbuffered",
      "-N",
      "-B",
      MYSQL_DATABASE,
    ],
    { stdin: "pipe", stdout: "pipe", stderr: "pipe" },
  );
  lockClient.stdin.write(
    "LOCK TABLES sf_spc_zero_dump_live_notes_1f76cb914b WRITE; SELECT 'locked';\n",
  );
  await lockClient.stdin.flush();
  const lockReader = lockClient.stdout.getReader();
  const lockSignal = await lockReader.read();
  lockReader.releaseLock();
  expect(new TextDecoder().decode(lockSignal.value)).toContain("locked");
  try {
    // Neither a server-side lock nor a dead socket may hold a PHP-FPM worker
    // past the read deadline.
    const deadlineStarted = performance.now();
    const blockedExport = await exportDatabase(
      runtime,
      versionId,
      { table: "notes", schemaHash: SCHEMA_HASH, limit: "1" },
      spaceId,
    );
    const deadlineElapsedMs = performance.now() - deadlineStarted;
    expect(blockedExport.status).toBe(500);
    expect(await errorCode(blockedExport)).toBe("zero_db_export_failed");
    expect(deadlineElapsedMs).toBeLessThan(5_000);
  } finally {
    lockClient.stdin.write("UNLOCK TABLES; quit\n");
    lockClient.stdin.end();
    await lockClient.exited;
  }
}, 600_000);
