// The real Rust lane, end to end: `stattic-runtime` compiles Zero endpoints and
// runs their QuickJS bytecode, and finalize turns everything a version answers
// into schema-v4 response-table entries (contracts §5). Serving of redirects and
// `_headers` belongs to routing.test.ts and headers.test.ts. This file owns only
// that the compiler writes those answers, the Zero endpoints and the Zero pack
// into the artifacts the serve path reads.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, existsSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { ZERO_RUNTIME_HOST_SOURCE } from "../../packages/zero-compile/src/runtime-host.ts";
import {
  blobPath,
  deploy,
  finalizeRaw,
  get,
  phpArtifact,
  publicAccessConfig,
  responseEntries,
  type Runtime,
  sha256,
  spaceRoot,
  startRuntime,
  versionRoot,
} from "./harness.ts";
import {
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

let rt: Runtime;
let runtimePath: string;
let runtimeWrapperPath: string;
let runtimeCapturePath: string;
let runtimeMarkerPath: string;
let endpointSource: string;
let mysql: MysqlContainer;

const HOST = "zero-rust-runner.test";
const GENERATED_HOST = "zero-generated-rust-runner.test";
const GENERATED_SPACE = "spc_zero_generated_rust";
const GENERATED_VERSION = "ver_zero_generated_rust_1";
const REPUBLISH_HOST = "zero-republish-rust-runner.test";
const REPUBLISH_SPACE = "spc_zero_republish_rust";
const REPUBLISH_TABLE = "sf_spc_zero_republish_todos";
const REPO_ROOT = path.resolve(import.meta.dir, "../..");
const MYSQL_ROOT_PASSWORD = "stattic";
const MYSQL_DATABASE = "zero_test";
const MYSQL_SETUP_TIMEOUT_MS = 180_000;
const MYSQL_TODOS_DB = {
  schemaHash: "sha256:mysql",
  tables: {
    todos: {
      physicalName: "zero_items",
      primaryKey: "id",
      columns: {
        id: "todo_id",
        title: { physicalName: "todo_title" },
      },
    },
  },
} as const;
const SHARED_ZERO_RUNS = [
  {
    execution_mode: "read",
    run_id: "query_todos",
    source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
    schema_hash: "sha256:mysql",
    capabilities: { db: true },
    db: MYSQL_TODOS_DB,
  },
  {
    execution_mode: "write",
    run_id: "mutation_addTodo",
    source:
      'globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: JSON.stringify(globalThis.__statticRealtime.publish("todos", {})) });',
    schema_hash: "sha256:mysql",
    capabilities: { db: true, realtime: true },
    db: MYSQL_TODOS_DB,
  },
] as const;

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
    throw new Error(`cargo build failed:\n${build.stdout.toString()}\n${build.stderr.toString()}`);
  }
  runtimePath = path.join(REPO_ROOT, "target/debug/stattic-runtime");
  const bunPath = Bun.which("bun") ?? "/usr/bin/env bun";
  const runtimeWrapperRoot = path.join(
    "/tmp",
    `stattic-runtime-wrapper-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  );
  runtimeWrapperPath = path.join(runtimeWrapperRoot, "runtime-wrapper.mjs");
  runtimeCapturePath = path.join(runtimeWrapperRoot, "invoke-envelope.json");
  runtimeMarkerPath = path.join(runtimeWrapperRoot, "invocations.log");
  await Bun.$`mkdir -p ${runtimeWrapperRoot}`;
  writeFileSync(
    runtimeWrapperPath,
    `#!${bunPath}
import { appendFileSync, readFileSync, writeFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
const input = readFileSync(0);
if (process.argv[2] === "invoke") {
  writeFileSync(${JSON.stringify(runtimeCapturePath)}, input);
}
appendFileSync(${JSON.stringify(runtimeMarkerPath)}, process.argv.slice(2).join(" ") + "\\n");
const result = spawnSync(${JSON.stringify(runtimePath)}, process.argv.slice(2), {
  input,
  stdout: "pipe",
  stderr: "pipe",
  env: process.env
});
writeFileSync(1, result.stdout);
writeFileSync(2, result.stderr);
process.exit(result.status ?? 1);
`,
  );
  chmodSync(runtimeWrapperPath, 0o755);

  endpointSource = `
const request = globalThis.__statticZeroRequest;
const context = globalThis.__statticZeroContext;
const endpoint = globalThis.__statticZeroEndpoint;
const capabilities = globalThis.__statticZeroCapabilities;
globalThis.__statticZeroResult = JSON.stringify({
  status: 203,
  headers: {
    "content-type": "application/json; charset=utf-8",
    "x-zero-runner": "rust"
  },
  body: JSON.stringify({
    endpointId: endpoint.endpointId,
    method: request.method,
    path: request.path,
    params: request.params,
    body: request.bodyBase64,
    spaceId: context.spaceId,
    dbInstalled: typeof globalThis.__statticDb !== "undefined",
    dbCapability: capabilities.db === true
  })
});
`;
  mysql = await startMysqlContainer({
    namePrefix: "stattic-zero-mysql",
    database: MYSQL_DATABASE,
    rootPassword: MYSQL_ROOT_PASSWORD,
  });

  rt = await startRuntime({
    env: {
      SPACEFAST_RUNTIME_BIN: runtimeWrapperPath,
      SPACEFAST_ZERO_RUNNER_CAPTURE: runtimeCapturePath,
      SPACEFAST_ZERO_RUNNER_DEBUG: "1",
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_SERVICE_EMAIL_SENDERS: "hello@example.com",
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_rust",
    versionId: "ver_zero_rust_1",
    metadata: { mode: "website", title: "Zero Rust Runner" },
    files: {
      "index.html": "<h1>zero rust runner</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/status",
          source: endpointSource,
          capabilities: { db: false },
        },
      ],
      zero_runs: SHARED_ZERO_RUNS,
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Zero Rust Runner" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  await deploy(rt, {
    spaceId: GENERATED_SPACE,
    versionId: GENERATED_VERSION,
    metadata: { mode: "website", title: "Zero Generated Rust Runner" },
    files: {
      "index.html": "<h1>zero generated rust runner</h1>\n",
      _redirects: "/old-generated /api/generated 308\n",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/generated",
          source: endpointSource,
          capabilities: { db: false },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/generated/:id",
          source: endpointSource,
          capabilities: { db: true },
          db: {
            schemaHash: "sha256:generated",
            migrationOperations: [
              {
                op: "drop_index",
                table: "todos",
                name: "by_title",
                explicit: "drop",
              },
              {
                op: "add_index",
                table: "todos",
                name: "by_title",
                columns: ["title"],
                unique: false,
              },
            ],
            tables: {
              todos: {
                physicalName: "sf_spc_zero_generated_todos",
                primaryKey: "id",
                columns: {
                  id: "todo_id",
                  title: { physicalName: "todo_title" },
                  createdAt: {},
                },
                indexes: {
                  by_title: { fields: ["title"] },
                },
              },
            },
          },
        },
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/generated/db",
          source: `
const endpoint = globalThis.__statticZeroEndpoint;
const table = endpoint.db.tables.todos.quotedName;
const insert = JSON.parse(globalThis.__statticDb(JSON.stringify({
  mode: "execute",
  sql: "INSERT INTO " + table + " (todo_title) VALUES (?)",
  params: ["from-zero"]
})));
const rows = JSON.parse(globalThis.__statticDb(JSON.stringify({
  sql: "SELECT todo_id, todo_title FROM " + table + " ORDER BY todo_id"
})));
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ insert, rows: rows.rows })
});
`,
          capabilities: { db: true },
          db: MYSQL_TODOS_DB,
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/generated/read-law",
          source: `
const table = globalThis.__statticZeroEndpoint.db.tables.todos.quotedName;
const count = () => JSON.parse(globalThis.__statticDb(JSON.stringify({
  sql: "SELECT COUNT(*) AS total FROM " + table + " WHERE todo_title = ?",
  params: ["repeatable-read-probe"]
}))).rows[0].total;
const before = count();
globalThis.__statticDb(JSON.stringify({ sql: "SELECT SLEEP(1)" }));
const after = count();
const write = JSON.parse(globalThis.__statticDb(JSON.stringify({
  mode: "execute",
  sql: "INSERT INTO " + table + " (todo_title) VALUES (?)",
  params: ["read-mode-write"]
})));
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ before, after, write })
});
`,
          capabilities: { db: true },
          db: MYSQL_TODOS_DB,
        },
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/generated/atomic-rollback",
          source: `${ZERO_RUNTIME_HOST_SOURCE}
const route = {
  mode: "write",
  method: "POST",
  path: "/api/generated/atomic-rollback",
};
const capsule = {
  endpoints: {
    atomicRollback: {
      ...route,
      async handler(ctx) {
        await ctx.db.todos.insert({ title: "must-roll-back-invalid-output" });
        return { ok: true };
      },
    },
  },
};
await globalThis.__statticRunZeroEndpoint(capsule, route);
globalThis.__statticZeroResult = "{";`,
          schema_hash: "sha256:mysql",
          capabilities: { db: true, realtime: true },
          db: MYSQL_TODOS_DB,
        },
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/generated/atomic-mail",
          source: `${ZERO_RUNTIME_HOST_SOURCE}
const route = {
  mode: "write",
  method: "POST",
  path: "/api/generated/atomic-mail",
};
const capsule = {
  endpoints: {
    atomicMail: {
      ...route,
      async handler(ctx) {
        await ctx.db.todos.insert({ id: "99003", title: "committed-mail" });
        const committed = await ctx.email.send({
          from: "hello@example.com",
          to: "reader@example.com",
          subject: "committed",
          text: "commits with the row",
        });
        return {
          committedRow: await ctx.db.todos.get("99003"),
          committed,
        };
      },
    },
  },
};
await globalThis.__statticRunZeroEndpoint(capsule, route);`,
          schema_hash: "sha256:mysql",
          capabilities: { db: true, email: true },
          db: MYSQL_TODOS_DB,
        },
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/generated/atomic-mail-rollback",
          source: `${ZERO_RUNTIME_HOST_SOURCE}
const route = {
  mode: "write",
  method: "POST",
  path: "/api/generated/atomic-mail-rollback",
};
const capsule = {
  endpoints: {
    atomicMailRollback: {
      ...route,
      async handler(ctx) {
        await ctx.db.todos.insert({ id: "99002", title: "rolled-back-mail" });
        await ctx.email.send({
          from: "hello@example.com",
          to: "reader@example.com",
          subject: "rolled back",
          text: "must not leave the transaction",
        });
        throw new Error("rollback mail");
      },
    },
  },
};
await globalThis.__statticRunZeroEndpoint(capsule, route);`,
          schema_hash: "sha256:mysql",
          capabilities: { db: true, email: true },
          db: MYSQL_TODOS_DB,
        },
        {
          method: "POST",
          path: "/api/generated/lakebed-db",
          source: `${ZERO_RUNTIME_HOST_SOURCE}
const route = {
  mode: "write",
  method: "POST",
  path: "/api/generated/lakebed-db",
};
const capsule = {
  endpoints: {
    lakebedDb: {
      ...route,
      async handler(ctx) {
        const first = await ctx.db.messages.insert({ pinned: true, title: "first" });
        const second = await ctx.db.messages.insert({ pinned: false, title: "second" });
        const custom = await ctx.db.messages
          .withIndex("by_title", (q) => q.eq("title", "first"))
          .collect();
        const pinned = await ctx.db.messages
          .withIndex("by_pinned", (q) => q.eq("pinned", true))
          .collect();
        const collected = await ctx.db.messages
          .withIndex("by_creation")
          .order("desc")
          .collect();
        const pageOne = await ctx.db.messages
          .withIndex("by_creation")
          .order("asc")
          .paginate({ cursor: null, numItems: 1 });
        const deletedPageOne = await ctx.db.messages.delete(first.id);
        const pageTwo = await ctx.db.messages
          .withIndex("by_creation")
          .order("asc")
          .paginate({ cursor: pageOne.continueCursor, numItems: 1 });
        let crossQueryRejected = false;
        try {
          await ctx.db.messages
            .withIndex("by_title", (q) => q.eq("title", "second"))
            .paginate({ cursor: pageOne.continueCursor, numItems: 1 });
        } catch {
          crossQueryRejected = true;
        }
        let tamperRejected = false;
        try {
          await ctx.db.messages
            .withIndex("by_creation")
            .order("asc")
            .paginate({ cursor: pageOne.continueCursor.slice(0, -2) + "zz", numItems: 1 });
        } catch {
          tamperRejected = true;
        }
        const longTitle = "bounded-context-".padEnd(3200, "x");
        const longFirst = await ctx.db.messages.insert({ pinned: false, title: longTitle });
        const longSecond = await ctx.db.messages.insert({ pinned: false, title: longTitle });
        const longPageOne = await ctx.db.messages
          .where("title", longTitle)
          .paginate({ cursor: null, numItems: 1 });
        const longPageTwo = await ctx.db.messages
          .where("title", longTitle)
          .paginate({ cursor: longPageOne.continueCursor, numItems: 1 });
        await ctx.db.messages.delete(longFirst.id);
        await ctx.db.messages.delete(longSecond.id);
        const updated = await ctx.db.messages.update(second.id, { title: "updated" });
        const deleted = await ctx.db.messages.delete(second.id);
        return {
          collected: collected.map((row) => row.title),
          crossQueryRejected,
          custom: custom.map((row) => row.title),
          deleted,
          deletedPageOne,
          first: { ...first, createdAt: typeof first.createdAt, updatedAt: typeof first.updatedAt },
          longCursorLength: longPageOne.continueCursor.length,
          longPageTwo: longPageTwo.page.map((row) => row.title.length),
          pageOne: pageOne.page.map((row) => row.title),
          pageTwo: pageTwo.page.map((row) => row.title),
          pinned: pinned.map((row) => ({ pinned: row.pinned, title: row.title })),
          tamperRejected,
          updated: updated && updated.title,
        };
      },
    },
  },
};
await globalThis.__statticRunZeroEndpoint(capsule, route);`,
          schema_hash: "sha256:lakebed",
          capabilities: { db: true, realtime: true },
          db: {
            schemaHash: "sha256:lakebed",
            migrationOperations: [
              {
                op: "add_index",
                table: "messages",
                name: "by_title",
                columns: ["title"],
                unique: false,
              },
              {
                op: "add_index",
                table: "messages",
                name: "by_pinned",
                columns: ["pinned"],
                unique: false,
              },
            ],
            tables: {
              messages: {
                physicalName: "lakebed_items",
                primaryKey: "id",
                columns: {
                  id: "item_id",
                  createdAt: "created_at",
                  updatedAt: "updated_at",
                  title: "item_title",
                  pinned: { physicalName: "item_pinned", type: "boolean" },
                },
                indexes: {
                  by_title: { fields: ["title"] },
                  by_pinned: { fields: ["pinned"] },
                },
              },
            },
          },
        },
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/generated/response-headers",
          source: `
const query = globalThis.__statticZeroRequest.query;
const headers = { "Content-Type": "application/json; charset=utf-8", "X-App-Result": "ready" };
if (query === "mode=forbidden") {
  headers["x-spacefast-zero-injected"] = "1";
}
if (query === "mode=oversized") {
  headers["x-big"] = "a".repeat(9000);
}
if (query === "mode=duplicate") {
  headers["X-App"] = "one";
  headers["x-app"] = "two";
}
globalThis.__statticZeroResult = JSON.stringify({ status: 200, headers, body: "{}" });
`,
          capabilities: { db: false },
        },
      ],
      zero_runs: SHARED_ZERO_RUNS,
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({
        mode: "website",
        site_title: "Zero Generated Rust Runner",
      }),
      production_hostnames: [GENERATED_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}, MYSQL_SETUP_TIMEOUT_MS);

afterAll(() => {
  rt?.stop();
  if (runtimeWrapperPath) {
    rmSync(path.dirname(runtimeWrapperPath), { recursive: true, force: true });
  }
  stopMysqlContainers();
});

/** A file inside the generated space's version root (contracts §2 layout). */
function generatedVersionFile(...segments: string[]): string {
  return path.join(versionRoot(rt, GENERATED_SPACE, GENERATED_VERSION), ...segments);
}

test("invokes finalized QuickJS bytecode through the real Rust runner", async () => {
  const response = await get(rt, HOST, "/api/status", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ ok: true }),
  });
  const text = await response.text();

  if (response.status !== 203) {
    throw new Error(`expected 203, got ${response.status}: ${text}`);
  }
  expect(response.headers.get("x-zero-runner")).toBe("rust");
  expect(JSON.parse(text)).toEqual({
    endpointId: "POST /api/status",
    method: "POST",
    path: "/api/status",
    params: {},
    body: Buffer.from(JSON.stringify({ ok: true })).toString("base64"),
    spaceId: "spc_zero_rust",
    dbInstalled: false,
    dbCapability: false,
  });
  const envelope = JSON.parse(readFileSync(runtimeCapturePath, "utf8"));
  expect(Array.isArray(envelope.request.headers)).toBe(false);
  expect(Array.isArray(envelope.request.params)).toBe(false);
  expect(Array.isArray(envelope.variables)).toBe(false);
});

test("uses the Rust runtime compiler during PHP finalize", () => {
  expect(existsSync(runtimeMarkerPath)).toBe(true);
  const invocations = readFileSync(runtimeMarkerPath, "utf8");
  expect(invocations).toContain("finalize");
  expect(invocations).toContain("--input");
  expect(invocations).toContain("--output");
});

test("surfaces Rust runtime compiler diagnostics during finalize", async () => {
  const spaceId = "spc_zero_runtime_compiler_invalid";
  const versionId = "ver_zero_runtime_compiler_invalid_1";
  const response = await finalizeRaw(
    rt,
    spaceId,
    versionId,
    { "index.html": "<h1>invalid zero</h1>\n" },
    {
      serving: {
        zero_endpoints: [
          {
            execution_mode: "read",
            method: "GET",
            path: "/api/broken",
            source: "export {",
            capabilities: { db: false },
          },
        ],
      },
    },
  );

  expect(response.status).toBe(422);
  expect((await response.json()).code).toBe("zero_endpoint_compile_failed");
});

test("Rust compiler rejects equal-score overlapping Zero patterns in either order", async () => {
  const endpoints = [
    {
      execution_mode: "read",
      method: "GET",
      path: "/api/:left/alpha",
      source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
      endpoint_id: "endpoint.rust.left",
      capabilities: { db: false },
    },
    {
      execution_mode: "read",
      method: "GET",
      path: "/api/beta/:right",
      source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
      endpoint_id: "endpoint.rust.right",
      capabilities: { db: false },
    },
  ];
  for (const reversed of [false, true]) {
    const suffix = reversed ? "reversed" : "forward";
    const response = await finalizeRaw(
      rt,
      `spc_zero_rust_ambiguous_${suffix}`,
      `ver_zero_rust_ambiguous_${suffix}_1`,
      { "index.html": "<h1>ambiguous</h1>\n" },
      {
        serving: { zero_endpoints: reversed ? endpoints.toReversed() : endpoints },
      },
    );
    expect(response.status).toBe(422);
    expect(await response.json()).toMatchObject({
      code: "zero_endpoint_duplicate",
    });
  }
});

test("compiles zero_endpoints during finalize and invokes generated bytecode", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ generated: true }),
  });
  const text = await response.text();

  if (response.status !== 203) {
    throw new Error(`expected 203, got ${response.status}: ${text}`);
  }
  expect(response.headers.get("x-zero-runner")).toBe("rust");
  expect(JSON.parse(text)).toEqual({
    endpointId: "POST /api/generated",
    method: "POST",
    path: "/api/generated",
    params: {},
    body: Buffer.from(JSON.stringify({ generated: true })).toString("base64"),
    spaceId: GENERATED_SPACE,
    dbInstalled: false,
    dbCapability: false,
  });
  const envelope = JSON.parse(readFileSync(runtimeCapturePath, "utf8"));
  expect(envelope.endpointId).toBe("POST /api/generated");
  expect(envelope.executionMode).toBe("write");
  expect(envelope.artifactPath).toBe("zero/endpoints/post_api_generated_04e4ec1c0c3f.json");
});

// Schema v4 has no per-version `redirects.php` / `headers.php`: an exact
// publisher redirect is a compiled response-table entry, and a Zero endpoint at
// a static path is an action on the entry for that path (§5). Both come out of
// the same finalize into the same table, so a compiler that dropped one shows up
// here.
test("compiles Zero endpoints and publisher redirects into one response table", () => {
  const entries = responseEntries(rt, GENERATED_SPACE, GENERATED_VERSION);

  expect(entries["/api/generated"]).toMatchObject({
    s: 200,
    a: { t: "zero", endpoint: "POST /api/generated", execution_mode: "write" },
  });
  expect(entries["/old-generated"]).toMatchObject({
    s: 308,
    h: { location: "/api/generated" },
  });
  // A redirect has no body to send, so it never rides the accel lane.
  expect(entries["/old-generated"]?.b).toBeNull();
});

test("writes compiler-produced Zero routes artifact when no base routes are merged", () => {
  const routes = phpArtifact<{
    runtime_schema: string;
    runtime_engine_version: string;
    format: string;
    artifact_kind: string;
    exact: Record<
      string,
      { endpoint_id: string; execution_mode: string; method: string; pattern: string }
    >;
  }>(generatedVersionFile("zero/routes.php"));

  expect(routes).toMatchObject({
    runtime_schema: "static-runtime-v4",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.routes.v1",
    artifact_kind: "zero_routes",
  });
  expect(Object.values(routes?.exact ?? {})).toContainEqual(
    expect.objectContaining({
      endpoint_id: "POST /api/generated",
      execution_mode: "write",
      method: "POST",
      pattern: "/api/generated",
    }),
  );
});

test("writes compiler-produced Zero endpoint index", () => {
  const index = JSON.parse(readFileSync(generatedVersionFile("zero/endpoints-index.json"), "utf8"));

  expect(index).toMatchObject({
    runtime_schema: "static-runtime-v4",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.endpoints-index.v1",
    artifact_kind: "zero_endpoints_index",
  });
  expect(index.endpoints["POST /api/generated"]).toContain("zero/endpoints/post_api_generated_");
});

test("writes compiler-produced Zero run artifacts", () => {
  const index = JSON.parse(readFileSync(generatedVersionFile("zero/runs-index.json"), "utf8"));
  expect(index).toMatchObject({
    runtime_schema: "static-runtime-v4",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.runs-index.v1",
    artifact_kind: "zero_runs_index",
  });
  expect(index.runs.query_todos).toContain("zero/runs/query_todos_");
  expect(index.runs.mutation_addTodo).toContain("zero/runs/mutation_addtodo_");

  const runArtifact = JSON.parse(
    readFileSync(generatedVersionFile(index.runs.mutation_addTodo), "utf8"),
  );
  expect(runArtifact).toMatchObject({
    format: "stattic.zero.run.v1",
    kind: "run",
    runId: "mutation_addTodo",
    executionMode: "write",
    capabilities: { db: true, realtime: true },
  });
  expect(existsSync(generatedVersionFile(runArtifact.sourcePath))).toBe(true);
  expect(existsSync(generatedVersionFile(runArtifact.bytecodePath))).toBe(true);
});

test("compiles generated dynamic zero_endpoints into the route artifact", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/todo_42", { method: "GET" });
  const text = await response.text();

  if (response.status !== 203) {
    throw new Error(`expected 203, got ${response.status}: ${text}`);
  }
  expect(JSON.parse(text)).toEqual({
    endpointId: "GET /api/generated/:id",
    method: "GET",
    path: "/api/generated/todo_42",
    params: { id: "todo_42" },
    body: "",
    spaceId: GENERATED_SPACE,
    dbInstalled: true,
    dbCapability: true,
  });
});

test("precomputes compact DB metadata for generated DB endpoints", () => {
  const dynamicSlug = `get_api_generated_id_${sha256("GET\n/api/generated/:id\n1").slice(0, 12)}`;
  const artifact = JSON.parse(
    readFileSync(generatedVersionFile(`zero/endpoints/${dynamicSlug}.json`), "utf8"),
  );

  expect(artifact.db).toMatchObject({
    schemaHash: "sha256:generated",
    tables: {
      todos: {
        name: "todos",
        physicalName: "sf_spc_zero_generated_todos",
        quotedName: "`sf_spc_zero_generated_todos`",
        primaryKey: "id",
        columns: {
          id: { name: "id", physicalName: "todo_id", quotedName: "`todo_id`" },
          title: { name: "title", physicalName: "todo_title", quotedName: "`todo_title`" },
          createdAt: {
            name: "createdAt",
            physicalName: "createdAt",
            quotedName: "`createdAt`",
          },
        },
      },
    },
  });
});

/**
 * One publish of the republish space. `withNote` adds a `note` field the first
 * publish never had, planned the way the CLI plans it: an `add_column` op plus
 * an index over the new field.
 */
async function publishRepublishSpace(versionId: string, withNote: boolean) {
  const noteColumns = withNote
    ? {
        note: { physicalName: "todo_note" },
      }
    : {};
  await deploy(rt, {
    spaceId: REPUBLISH_SPACE,
    versionId,
    metadata: { mode: "website", title: "Zero Republish Rust Runner" },
    files: { "index.html": "<h1>zero republish rust runner</h1>\n" },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/republish/db",
          source: `
const endpoint = globalThis.__statticZeroEndpoint;
const table = endpoint.db.tables.todos.quotedName;
const note = endpoint.db.tables.todos.columns.note.quotedName;
const insert = JSON.parse(globalThis.__statticDb(JSON.stringify({
  mode: "execute",
  sql: "INSERT INTO " + table + " (todo_title, " + note + ") VALUES (?, ?)",
  params: ["republished", "added-field"]
})));
const rows = JSON.parse(globalThis.__statticDb(JSON.stringify({
  sql: "SELECT todo_title, " + note + " AS note FROM " + table + " WHERE " + note + " IS NOT NULL ORDER BY todo_id"
})));
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ insert, rows: rows.rows })
});
`,
          capabilities: { db: true },
          db: {
            schemaHash: withNote ? "sha256:republish-note" : "sha256:republish",
            migrationOperations: withNote
              ? [
                  { op: "add_column", table: "todos", column: { name: "note", type: "string" } },
                  {
                    op: "add_index",
                    table: "todos",
                    name: "by_note",
                    columns: ["note"],
                    unique: false,
                  },
                ]
              : [],
            tables: {
              todos: {
                physicalName: REPUBLISH_TABLE,
                primaryKey: "id",
                columns: {
                  id: "todo_id",
                  title: { physicalName: "todo_title" },
                  createdAt: {},
                  ...noteColumns,
                },
                ...(withNote ? { indexes: { by_note: { fields: ["note"] } } } : {}),
              },
            },
          },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({
        mode: "website",
        site_title: "Zero Republish Rust Runner",
      }),
      production_hostnames: [REPUBLISH_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}

function republishColumns(): string {
  const show = Bun.spawnSync({
    cmd: [
      "docker",
      "exec",
      mysql.name,
      "mysql",
      "-N",
      "-uroot",
      `-p${MYSQL_ROOT_PASSWORD}`,
      MYSQL_DATABASE,
      "-e",
      `SHOW COLUMNS FROM ${REPUBLISH_TABLE}`,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (show.exitCode !== 0) {
    throw new Error(`SHOW COLUMNS failed: ${show.stderr.toString()}`);
  }
  return show.stdout.toString();
}

test("republishing with an added schema field adds the column and lets writes use it", async () => {
  await publishRepublishSpace("ver_zero_republish_rust_1", false);
  expect(republishColumns()).not.toContain("todo_note");

  // `CREATE TABLE IF NOT EXISTS` is a no-op by now, so only the ALTER can add
  // the column, and it has to land before the index that reads it.
  await publishRepublishSpace("ver_zero_republish_rust_2", true);
  const migrations = JSON.parse(
    readFileSync(
      path.join(
        versionRoot(rt, REPUBLISH_SPACE, "ver_zero_republish_rust_2"),
        "zero/migrations.json",
      ),
      "utf8",
    ),
  );
  const alter = `ALTER TABLE \`${REPUBLISH_TABLE}\` ADD COLUMN \`todo_note\` TEXT NULL`;
  expect(migrations.statements).toContain(alter);
  expect(migrations.statements.indexOf(alter)).toBeLessThan(
    migrations.statements.indexOf(
      `CREATE INDEX \`by_note\` ON \`${REPUBLISH_TABLE}\` (\`todo_note\`(191), \`createdAt\`(191), \`todo_id\`)`,
    ),
  );
  expect(republishColumns()).toContain("todo_note");

  const response = await get(rt, REPUBLISH_HOST, "/api/republish/db", { method: "POST" });
  const text = await response.text();
  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  expect(JSON.parse(text)).toMatchObject({
    insert: { ok: true, affectedRows: 1 },
    rows: [{ note: "added-field", todo_title: "republished" }],
  });

  // MySQL has no `ADD COLUMN IF NOT EXISTS`, so a later publish replays the
  // ALTER. A duplicate column must survive like a replayed index.
  await publishRepublishSpace("ver_zero_republish_rust_3", true);
  const replayed = await get(rt, REPUBLISH_HOST, "/api/republish/db", { method: "POST" });
  expect(replayed.status).toBe(200);
});

test("applies compiler-produced Zero DB migrations during finalize", () => {
  const migrations = JSON.parse(readFileSync(generatedVersionFile("zero/migrations.json"), "utf8"));

  expect(migrations).toMatchObject({
    runtime_schema: "static-runtime-v4",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.migrations.v1",
    artifact_kind: "zero_migrations",
  });
  expect(migrations.statements).toContain(
    "CREATE TABLE IF NOT EXISTS `zero_items` (`todo_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `todo_title` TEXT NULL, PRIMARY KEY (`todo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  );
  expect(migrations.statements).toContain(
    "CREATE INDEX `by_title` ON `sf_spc_zero_generated_todos` (`todo_title`(191), `createdAt`(191), `todo_id`)",
  );
  expect(migrations.statements).toContain("DROP INDEX `by_title` ON `sf_spc_zero_generated_todos`");
  expect(
    migrations.statements.indexOf("DROP INDEX `by_title` ON `sf_spc_zero_generated_todos`"),
  ).toBeLessThan(
    migrations.statements.indexOf(
      "CREATE INDEX `by_title` ON `sf_spc_zero_generated_todos` (`todo_title`(191), `createdAt`(191), `todo_id`)",
    ),
  );

  const showIndex = Bun.spawnSync({
    cmd: [
      "docker",
      "exec",
      mysql.name,
      "mysql",
      "-N",
      "-uroot",
      `-p${MYSQL_ROOT_PASSWORD}`,
      MYSQL_DATABASE,
      "-e",
      "SHOW INDEX FROM sf_spc_zero_generated_todos WHERE Key_name = 'by_title'",
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  expect(showIndex.exitCode).toBe(0);
  expect(showIndex.stdout.toString()).toContain("by_title");
  expect(showIndex.stdout.toString()).toContain("todo_title");
});

test("executes Zero DB query and mutation through local MySQL", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/db", { method: "POST" });
  const text = await response.text();

  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  expect(JSON.parse(text)).toEqual({
    insert: {
      ok: true,
      affectedRows: 1,
      lastInsertId: 1,
    },
    rows: [
      {
        todo_id: 1,
        todo_title: "from-zero",
      },
    ],
  });
});

test("holds read handlers on one repeatable-read snapshot and rejects writes", async () => {
  mysql.exec(
    "DELETE FROM zero_items WHERE todo_title IN ('repeatable-read-probe', 'read-mode-write')",
  );
  const pendingResponse = get(rt, GENERATED_HOST, "/api/generated/read-law");
  let sleeping = false;
  for (let attempt = 0; attempt < 100 && !sleeping; attempt += 1) {
    sleeping =
      mysql.exec(
        "SELECT COUNT(*) FROM information_schema.processlist WHERE INFO LIKE 'SELECT SLEEP(1)%'",
      ) === "1";
  }
  expect(sleeping).toBe(true);
  mysql.exec("INSERT INTO zero_items (todo_title) VALUES ('repeatable-read-probe')");

  const response = await pendingResponse;
  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({
    before: 0,
    after: 0,
    write: {
      ok: false,
      code: "zero_db_read_only",
      message: "A Zero read handler cannot execute a database write.",
    },
  });
  expect(mysql.exec("SELECT COUNT(*) FROM zero_items WHERE todo_title = 'read-mode-write'")).toBe(
    "0",
  );
});

test("executes the Lakebed database v1 API through the real Rust runner", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/lakebed-db", {
    method: "POST",
  });
  const text = await response.text();

  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  const body = JSON.parse(text);
  expect(body.longCursorLength).toBeLessThan(4_096);
  expect(body).toMatchObject({
    collected: ["second", "first"],
    crossQueryRejected: true,
    custom: ["first"],
    deleted: true,
    deletedPageOne: true,
    first: {
      createdAt: "string",
      title: "first",
      updatedAt: "string",
    },
    longPageTwo: [3200],
    pageOne: ["first"],
    pageTwo: ["second"],
    pinned: [{ pinned: true, title: "first" }],
    tamperRejected: true,
    updated: "updated",
  });
});

// Space storage (D38): a record in `spaces/<s>/uploads/objects/<id>.json`, bytes
// in the CAS and nowhere else, served by PHP without entering tenant code.
// Ranges/HEAD/206 and conditional 304s belong to the platform (§14, §16/C19).
// PHP never sees Range or If-None-Match, so they are not asserted here.
test("serves space storage from the PHP runtime without invoking tenant code", async () => {
  const markerBefore = existsSync(runtimeMarkerPath) ? readFileSync(runtimeMarkerPath, "utf8") : "";
  const upload = await get(rt, GENERATED_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "text/plain" },
    body: "runtime object",
  });
  expect(upload.status).toBe(201);
  const object = (await upload.json()) as {
    id: string;
    contentType: string;
    size: number;
    url: string;
  };
  expect(object).toMatchObject({ contentType: "text/plain", size: 14 });
  expect(object.id).toMatch(/^[a-f0-9]{32}$/);
  // Fresh at response time: the public URL carries the runtime read key.
  expect(object.url).toMatch(new RegExp(`/__stattic/u/${object.id}\\?k=[a-f0-9]{32}$`));
  const objectSha = sha256("runtime object");
  const recordPath = path.join(
    spaceRoot(rt, GENERATED_SPACE),
    "uploads",
    "objects",
    `${object.id}.json`,
  );
  const casPath = blobPath(rt, GENERATED_SPACE, objectSha);
  // The record is the authority; the CAS is the only byte store, so there is no
  // companion body file to hardlink (§8).
  expect(JSON.parse(readFileSync(recordPath, "utf8"))).toMatchObject({
    contentType: "text/plain",
    sha256: objectSha,
    size: 14,
  });
  expect(readFileSync(casPath, "utf8")).toBe("runtime object");

  const read = await get(rt, GENERATED_HOST, `/storage/${object.id}`);
  expect(read.status).toBe(200);
  expect(read.headers.get("content-security-policy")).toContain("sandbox");
  // D132: the validator nginx would derive from the placed file, so a
  // PHP-served and an accel-served copy answer identically.
  const etag = read.headers.get("etag");
  expect(etag).toMatch(/^"[0-9a-f]+-[0-9a-f]+"$/);
  expect(await read.text()).toBe("runtime object");

  const svg = await get(rt, GENERATED_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "image/svg+xml" },
    body: '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>',
  });
  expect(svg.status).toBe(201);

  const blocked = await get(rt, GENERATED_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "application/octet-stream" },
    body: Buffer.from([0xca, 0xfe, 0xba, 0xbe, 0, 0, 0, 0]),
  });
  expect(blocked.status).toBe(415);
  expect(await blocked.json()).toMatchObject({
    code: "storage_content_blocked",
  });

  const removed = await get(rt, GENERATED_HOST, `/storage/${object.id}`, {
    method: "DELETE",
  });
  expect(removed.status).toBe(204);
  expect(
    (
      await get(rt, GENERATED_HOST, `/storage/${object.id}`, {
        method: "DELETE",
      })
    ).status,
  ).toBe(204);
  expect((await get(rt, GENERATED_HOST, `/storage/${object.id}`)).status).toBe(404);
  // The delete removes the record, never the blob: the bytes may back another
  // record or version, and only the GC's live set releases them.
  expect(existsSync(recordPath)).toBe(false);
  expect(existsSync(casPath)).toBe(true);
  expect(existsSync(runtimeMarkerPath) ? readFileSync(runtimeMarkerPath, "utf8") : "").toBe(
    markerBefore,
  );
});

test("applies the native response-header policy to every endpoint response", async () => {
  // Safe custom headers pass through unchanged.
  const clean = await get(rt, GENERATED_HOST, "/api/generated/response-headers");
  expect(clean.status).toBe(200);
  expect(clean.headers.get("x-app-result")).toBe("ready");

  // A platform-reserved x-spacefast-* header fails the response closed.
  const forbidden = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=forbidden");
  expect(forbidden.status).toBe(502);
  expect(forbidden.headers.get("x-spacefast-zero-injected")).toBeNull();
  expect(await forbidden.json()).toMatchObject({
    code: "zero_response_header_forbidden",
  });

  // Oversized values and case-insensitive duplicates are rejected as invalid.
  const oversized = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=oversized");
  expect(oversized.status).toBe(502);
  expect(await oversized.json()).toMatchObject({
    code: "zero_response_header_invalid",
  });
  const duplicate = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=duplicate");
  expect(duplicate.status).toBe(502);
  expect(await duplicate.json()).toMatchObject({
    code: "zero_response_header_invalid",
  });
});

test("the runner ignores ambient DATABASE_URL and requires the labeled URL", async () => {
  // One request through PHP to capture a real DB-endpoint envelope.
  const primed = await get(rt, GENERATED_HOST, "/api/generated/db", { method: "POST" });
  expect(primed.status).toBe(200);
  const envelope = readFileSync(runtimeCapturePath);

  // The runtime owns the transaction, so an unusable URL fails the invocation
  // open before any handler code runs — the refusal never reaches a handler as
  // a per-call result.
  const ambientOnly = Bun.spawnSync({
    cmd: [runtimePath, "invoke"],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: { DATABASE_URL: mysql.url },
  });
  expect(ambientOnly.exitCode).toBe(0);
  const ambientResponse = JSON.parse(ambientOnly.stdout.toString());
  expect(ambientResponse.status).toBe(503);
  // Ambient DATABASE_URL is not an input at all, so the labeled name is absent.
  expect(JSON.parse(ambientResponse.body).code).toBe("zero_db_url_missing");

  // A labeled URL with an unknown provenance label also fails closed.
  const mislabeled = Bun.spawnSync({
    cmd: [runtimePath, "invoke"],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_ZERO_DATABASE_URL_SOURCE: "ambient",
    },
  });
  expect(mislabeled.exitCode).toBe(0);
  const mislabeledResponse = JSON.parse(mislabeled.stdout.toString());
  expect(mislabeledResponse.status).toBe(503);
  expect(JSON.parse(mislabeledResponse.body).code).toBe("zero_db_url_invalid");

  // The labeled provider URL is the one accepted path.
  const labeled = Bun.spawnSync({
    cmd: [runtimePath, "invoke"],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_ZERO_DATABASE_URL_SOURCE: "provider",
    },
  });
  expect(labeled.exitCode).toBe(0);
  const labeledBody = JSON.parse(JSON.parse(labeled.stdout.toString()).body);
  expect(labeledBody.insert).toMatchObject({ ok: true });
});

test("rolls back parent-owned writes when the complete handler output is invalid", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/atomic-rollback", {
    method: "POST",
    headers: { "content-type": "application/json" },
  });
  const text = await response.text();

  expect(response.status).toBe(502);
  expect(JSON.parse(text)).toMatchObject({ code: "zero_js_response_malformed" });
  expect(
    mysql.exec(
      "SELECT COUNT(*) FROM zero_items WHERE todo_title = 'must-roll-back-invalid-output'",
    ),
  ).toBe("0");
});

test("mail intent lives and dies with the invocation transaction", async () => {
  // Under the execution law the runner owns one transaction per write-mode
  // invocation; a handler cannot open or close its own. A throwing handler
  // therefore takes its outbox insert down with its row write, and a
  // succeeding one commits both — intent cannot outlive the write it
  // announces.
  const rolledBack = await get(rt, GENERATED_HOST, "/api/generated/atomic-mail-rollback", {
    method: "POST",
    headers: { "content-type": "application/json", "x-request-id": "inv_atomic_mail_rb" },
  });
  expect(rolledBack.status).toBe(500);

  const response = await get(rt, GENERATED_HOST, "/api/generated/atomic-mail", {
    method: "POST",
    headers: { "content-type": "application/json", "x-request-id": "inv_atomic_mail" },
  });
  const text = await response.text();
  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  // SAFETY: the 200 above is this fixture endpoint's own JSON, shaped by the
  // handler defined in this file.
  const result = JSON.parse(text) as {
    committedRow: { title?: string } | null;
    committed: { messageId?: string };
  };
  const messageId = result.committed.messageId ?? "";
  expect(messageId).toMatch(/^msg_[a-f0-9]{32}$/);
  expect(result.committedRow?.title).toBe("committed-mail");

  // The thrown invocation's row is gone with its intent.
  expect(mysql.exec("SELECT todo_title FROM zero_items WHERE todo_id = '99002';")).toBe("");

  // One outbox row, and it is the committed one.
  const outbox = mysql.exec(
    "SELECT message_id, state, CONVERT(payload_json USING utf8mb4) FROM _spacefast_email_outbox;",
  );
  expect(outbox.split("\n")).toHaveLength(1);
  expect(outbox).toContain(messageId);
  expect(outbox).toContain("queued");
  expect(outbox).toContain("commits with the row");
  expect(outbox).not.toContain("must not leave the transaction");
});

test("keeps finalize-generated Zero artifacts private", async () => {
  const exactSlug = `post_api_generated_${sha256("POST\n/api/generated\n0").slice(0, 12)}`;
  const dynamicSlug = `get_api_generated_id_${sha256("GET\n/api/generated/:id\n1").slice(0, 12)}`;
  const runSlug = `mutation_addtodo_${sha256("mutation_addTodo\n1").slice(0, 12)}`;
  for (const artifactPath of [
    `/zero/endpoints/${exactSlug}.json`,
    `/zero/endpoints/${exactSlug}.source.js`,
    `/zero/endpoints/${exactSlug}.bytecode`,
    `/zero/endpoints/${dynamicSlug}.json`,
    `/zero/endpoints/${dynamicSlug}.source.js`,
    `/zero/endpoints/${dynamicSlug}.bytecode`,
    "/zero/endpoints-index.json",
    `/zero/runs/${runSlug}.json`,
    `/zero/runs/${runSlug}.source.js`,
    `/zero/runs/${runSlug}.bytecode`,
    "/zero/runs-index.json",
  ]) {
    const response = await get(rt, GENERATED_HOST, artifactPath);
    expect(response.status).toBe(404);
  }
});
