import { afterAll, beforeAll, expect, test } from "bun:test";
import { chmodSync, existsSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import net from "node:net";
import path from "node:path";

import { ZERO_RUNTIME_HOST_SOURCE } from "../../packages/cli/src/zero-runtime-host.ts";
import { deploy, finalizeRaw, get, sha256, type Runtime, startRuntime } from "./harness.ts";

let rt: Runtime;
let runnerPath: string;
let runnerWrapperPath: string;
let runnerCapturePath: string;
let compilerPath: string;
let compilerWrapperPath: string;
let compilerMarkerPath: string;
let endpointSource: string;
let mysqlContainer: string;
let mysqlUrl: string;

const HOST = "zero-rust-runner.test";
const GENERATED_HOST = "zero-generated-rust-runner.test";
const REDIRECT_ONLY_HOST = "zero-rust-redirect-only.test";
const REPO_ROOT = path.resolve(import.meta.dir, "../..");
const MYSQL_ROOT_PASSWORD = "stattic";
const MYSQL_DATABASE = "zero_test";
const MYSQL_IMAGE =
  "mirror.gcr.io/library/mysql:8.4@sha256:c831a0f11348d402b43d77453e17d770be2eef356615a2823fe0f5a0d6c8b9af";
const MYSQL_SETUP_TIMEOUT_MS = 180_000;

beforeAll(async () => {
  const build = Bun.spawnSync({
    cmd: [
      "cargo",
      "build",
      "--locked",
      "-p",
      "stattic-zero-runner",
      "-p",
      "stattic-runtime-compiler",
    ],
    cwd: REPO_ROOT,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (build.exitCode !== 0) {
    throw new Error(`cargo build failed:\n${build.stdout.toString()}\n${build.stderr.toString()}`);
  }
  runnerPath = path.join(REPO_ROOT, "target/debug/stattic-zero-runner");
  compilerPath = path.join(REPO_ROOT, "target/debug/stattic-runtime-compiler");
  const bunPath = Bun.which("bun") ?? "/usr/bin/env bun";
  const runnerWrapperRoot = path.join(
    "/tmp",
    `stattic-zero-runner-wrapper-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  );
  runnerWrapperPath = path.join(runnerWrapperRoot, "runner-wrapper.mjs");
  runnerCapturePath = path.join(runnerWrapperRoot, "invoke-envelope.json");
  await Bun.$`mkdir -p ${runnerWrapperRoot}`;
  writeFileSync(
    runnerWrapperPath,
    `#!${bunPath}
import { readFileSync, writeFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
const input = readFileSync(0);
if (process.argv.length === 2) {
  writeFileSync(${JSON.stringify(runnerCapturePath)}, input);
}
const result = spawnSync(${JSON.stringify(runnerPath)}, process.argv.slice(2), {
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
  chmodSync(runnerWrapperPath, 0o755);
  const compilerWrapperRoot = path.join(
    "/tmp",
    `stattic-runtime-compiler-wrapper-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  );
  compilerWrapperPath = path.join(compilerWrapperRoot, "compiler-wrapper.mjs");
  compilerMarkerPath = path.join(compilerWrapperRoot, "invocations.log");
  await Bun.$`mkdir -p ${compilerWrapperRoot}`;
  writeFileSync(
    compilerWrapperPath,
    `#!${bunPath}
import { appendFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
appendFileSync(${JSON.stringify(compilerMarkerPath)}, process.argv.slice(2).join(" ") + "\\n");
const result = spawnSync(${JSON.stringify(compilerPath)}, process.argv.slice(2), { stdio: "inherit" });
process.exit(result.status ?? 1);
`,
  );
  chmodSync(compilerWrapperPath, 0o755);

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
  mysqlUrl = await startMysql();

  rt = await startRuntime({
    env: {
      SPACEFAST_ZERO_RUNNER: runnerWrapperPath,
      SPACEFAST_ZERO_RUNNER_CAPTURE: runnerCapturePath,
      SPACEFAST_RUNTIME_FINALIZER_BIN: compilerWrapperPath,
      SPACEFAST_ZERO_RUNNER_DEBUG: "1",
      SPACEFAST_ZERO_DATABASE_URL: mysqlUrl,
    },
  });
  await deploy(rt, {
    spaceId: "spc_zero_rust",
    versionId: "ver_zero_rust_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Rust Runner" },
    files: {
      "index.html": "<h1>zero rust runner</h1>\n",
    },
    serving: {
      zero_endpoints: [
        {
          method: "POST",
          path: "/api/status",
          source: endpointSource,
          capabilities: { db: false },
        },
      ],
      zero_runs: [
        {
          run_id: "query_todos",
          source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
          schema_hash: "sha256:mysql",
          capabilities: { db: true },
          db: {
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
          },
        },
        {
          run_id: "mutation_addTodo",
          source:
            'globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: JSON.stringify(globalThis.__statticRealtime.publish("todos", {})) });',
          schema_hash: "sha256:mysql",
          capabilities: { db: true, realtime: true },
          db: {
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
          },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Rust Runner" },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  await deploy(rt, {
    spaceId: "spc_zero_generated_rust",
    versionId: "ver_zero_generated_rust_1",
    zeroMode: "active",
    metadata: { mode: "website", title: "Zero Generated Rust Runner" },
    files: {
      "index.html": "<h1>zero generated rust runner</h1>\n",
    },
    serving: {
      redirects_exact: {
        "/old-generated": [
          { destination: "/api/generated", status: 308, action: "redirect", order: 1 },
        ],
      },
      zero_endpoints: [
        {
          method: "POST",
          path: "/api/generated",
          source: endpointSource,
          capabilities: { db: false },
        },
        {
          method: "GET",
          path: "/api/generated/:id",
          source: endpointSource,
          capabilities: { db: true },
          db: {
            schemaHash: "sha256:generated",
            tables: {
              todos: {
                physicalName: "lb_spc_zero_generated_todos",
                primaryKey: "id",
                columns: {
                  id: "todo_id",
                  title: { physicalName: "todo_title" },
                  createdAt: {},
                },
              },
            },
          },
        },
        {
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
          db: {
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
          },
        },
        {
          method: "POST",
          path: "/api/generated/db-rollback",
          source: `
const endpoint = globalThis.__statticZeroEndpoint;
const table = endpoint.db.tables.todos.quotedName;
const rollback = JSON.parse(globalThis.__statticDb(JSON.stringify({
  mode: "transaction",
  statements: [
    {
      mode: "execute",
      sql: "INSERT INTO " + table + " (todo_title) VALUES (?)",
      params: ["rolled-back"]
    },
    {
      mode: "execute",
      sql: "INSERT INTO " + table + " (missing_column) VALUES (?)",
      params: ["boom"]
    }
  ]
})));
const rows = JSON.parse(globalThis.__statticDb(JSON.stringify({
  sql: "SELECT todo_id, todo_title FROM " + table + " WHERE todo_title = ?",
  params: ["rolled-back"]
})));
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: JSON.stringify({ rollback, rows: rows.rows })
});
`,
          capabilities: { db: true },
          db: {
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
          },
        },
        {
          method: "POST",
          path: "/api/generated/atomic-rollback",
          source: `${ZERO_RUNTIME_HOST_SOURCE}
const route = {
  method: "POST",
  path: "/api/generated/atomic-rollback",
};
const capsule = {
  endpoints: {
    atomicRollback: {
      ...route,
      handler(ctx) {
        let failure = "";
        try {
          ctx.transaction(() => {
            ctx.db.todos.insert({ id: "99001", title: "must-roll-back" });
            ctx.db.todos.insert({ id: "99001", title: "duplicate" });
          });
        } catch (error) {
          failure = String(error && error.message ? error.message : error);
        }
        return {
          duplicateRejected: failure.length > 0,
          row: ctx.db.todos.get("99001"),
        };
      },
    },
  },
};
await globalThis.__statticRunZeroEndpoint(capsule, route);`,
          schema_hash: "sha256:mysql",
          capabilities: { db: true, realtime: true },
          db: {
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
          },
        },
        {
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
      zero_runs: [
        {
          run_id: "query_todos",
          source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
          schema_hash: "sha256:mysql",
          capabilities: { db: true },
          db: {
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
          },
        },
        {
          run_id: "mutation_addTodo",
          source:
            'globalThis.__statticZeroResult = JSON.stringify({ status: 200, body: JSON.stringify(globalThis.__statticRealtime.publish("todos", {})) });',
          schema_hash: "sha256:mysql",
          capabilities: { db: true, realtime: true },
          db: {
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
          },
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Generated Rust Runner" },
      production_hostnames: [GENERATED_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  await deploy(rt, {
    spaceId: "spc_zero_redirect_only_rust",
    versionId: "ver_zero_redirect_only_rust_1",
    metadata: { mode: "website", title: "Zero Redirect Only Rust" },
    files: {
      "index.html": "<h1>zero redirect only rust</h1>\n",
      "assets/app.js": "void 0;\n",
    },
    serving: {
      headers_exact: {
        "/": [
          {
            order: 1,
            operations: [{ kind: "set", name: "X-Rust-Compiled-Header", value: "yes" }],
            headers: { "X-Rust-Compiled-Header": "yes" },
          },
        ],
      },
      headers_pattern: [
        {
          path: "/assets/*",
          regex: "^/assets/(?<file>[^/]+)$",
          order: 2,
          operations: [{ kind: "set", name: "X-Rust-Asset", value: "name=:file" }],
          headers: { "X-Rust-Asset": "name=:file" },
        },
      ],
      redirects_exact: {
        "/old-redirect-only": [
          { destination: "/new-redirect-only", status: 301, action: "redirect" },
        ],
      },
      redirects_pattern: [
        {
          source: "/rust-rewrite/*",
          regex: "^/rust-rewrite/(?<slug>.*)$",
          destination: "/index.html",
          status: 200,
          action: "rewrite",
          order: 2,
        },
      ],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Zero Redirect Only Rust" },
      production_hostnames: [REDIRECT_ONLY_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}, MYSQL_SETUP_TIMEOUT_MS);

afterAll(() => {
  rt?.stop();
  if (compilerWrapperPath) {
    rmSync(path.dirname(compilerWrapperPath), { recursive: true, force: true });
  }
  if (runnerWrapperPath) {
    rmSync(path.dirname(runnerWrapperPath), { recursive: true, force: true });
  }
  if (mysqlContainer) {
    Bun.spawnSync({ cmd: ["docker", "rm", "-f", mysqlContainer] });
  }
});

async function startMysql(): Promise<string> {
  mysqlContainer = `stattic-zero-mysql-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const port = await freeTcpPort();
  const run = Bun.spawnSync({
    cmd: [
      "docker",
      "run",
      "-d",
      "--rm",
      "--name",
      mysqlContainer,
      "-e",
      `MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}`,
      "-e",
      `MYSQL_DATABASE=${MYSQL_DATABASE}`,
      "-p",
      `127.0.0.1:${port}:3306`,
      MYSQL_IMAGE,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (run.exitCode !== 0) {
    throw new Error(
      `mysql container failed to start:\n${run.stdout.toString()}\n${run.stderr.toString()}`,
    );
  }
  await waitForMysql();
  await waitForTcpPort(port);
  return `mysql://root:${MYSQL_ROOT_PASSWORD}@127.0.0.1:${port}/${MYSQL_DATABASE}`;
}

async function freeTcpPort(): Promise<number> {
  const server = net.createServer();
  server.listen(0, "127.0.0.1");
  await new Promise((resolve) => server.once("listening", resolve));
  const port = (server.address() as net.AddressInfo).port;
  server.close();
  return port;
}

async function waitForMysql(): Promise<void> {
  const deadline = Date.now() + 60_000;
  for (;;) {
    const ping = Bun.spawnSync({
      cmd: [
        "docker",
        "exec",
        mysqlContainer,
        "mysqladmin",
        "ping",
        "-h",
        "127.0.0.1",
        "-uroot",
        `-p${MYSQL_ROOT_PASSWORD}`,
        "--silent",
      ],
      stdout: "pipe",
      stderr: "pipe",
    });
    if (ping.exitCode === 0) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(
        `mysql container did not become ready:\n${ping.stdout.toString()}\n${ping.stderr.toString()}`,
      );
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}

async function waitForTcpPort(port: number): Promise<void> {
  const deadline = Date.now() + 10_000;
  for (;;) {
    const connected = await new Promise<boolean>((resolve) => {
      const socket = net.createConnection({ host: "127.0.0.1", port });
      socket.once("connect", () => {
        socket.destroy();
        resolve(true);
      });
      socket.once("error", () => {
        socket.destroy();
        resolve(false);
      });
      socket.setTimeout(500, () => {
        socket.destroy();
        resolve(false);
      });
    });
    if (connected) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(`mysql host port did not become ready: 127.0.0.1:${port}`);
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
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
  const envelope = JSON.parse(readFileSync(runnerCapturePath, "utf8"));
  expect(Array.isArray(envelope.request.headers)).toBe(false);
  expect(Array.isArray(envelope.request.params)).toBe(false);
  expect(Array.isArray(envelope.variables)).toBe(false);
});

test("uses the Rust runtime compiler during PHP finalize", () => {
  expect(existsSync(compilerMarkerPath)).toBe(true);
  const invocations = readFileSync(compilerMarkerPath, "utf8");
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
  expect((await response.json()).error.code).toBe("zero_endpoint_compile_failed");
});

test("Rust compiler rejects equal-score overlapping Zero patterns in either order", async () => {
  const endpoints = [
    {
      method: "GET",
      path: "/api/:left/alpha",
      source: "globalThis.__statticZeroResult = JSON.stringify({ status: 200 });",
      endpoint_id: "endpoint.rust.left",
      capabilities: { db: false },
    },
    {
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
      error: { code: "zero_endpoint_duplicate" },
    });
  }
});

test("keeps bytecode/source/artifact private when using the Rust runner", async () => {
  const slug = `post_api_status_${sha256("POST\n/api/status\n0").slice(0, 12)}`;
  for (const artifactPath of [
    `/zero/endpoints/${slug}.json`,
    `/zero/endpoints/${slug}.source.js`,
    `/zero/endpoints/${slug}.bytecode`,
    "/zero/endpoints-index.json",
  ]) {
    const response = await get(rt, HOST, artifactPath);
    expect(response.status).toBe(404);
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
    spaceId: "spc_zero_generated_rust",
    dbInstalled: false,
    dbCapability: false,
  });
  const envelope = JSON.parse(readFileSync(runnerCapturePath, "utf8"));
  expect(envelope.endpointId).toBe("POST /api/generated");
  expect(envelope).not.toHaveProperty("artifactPath");
  expect(envelope).not.toHaveProperty("filesRoot");

  const routesPath = path.join(
    rt.storageRoot,
    "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1/zero/routes.php",
  );
  const parsedRoutes = Bun.spawnSync({
    cmd: [
      Bun.which("php") ?? "/usr/bin/php",
      "-r",
      "echo json_encode(require $argv[1]);",
      routesPath,
    ],
  });
  expect(parsedRoutes.exitCode).toBe(0);
  const zeroRoutes = JSON.parse(parsedRoutes.stdout.toString());
  expect(zeroRoutes.exact).toContainEqual(
    expect.objectContaining({
      endpoint_id: "POST /api/generated",
      method: "POST",
      pattern: "/api/generated",
    }),
  );
});

test("keeps exact redirects aligned with the Rust compiler manifest", async () => {
  const response = await get(rt, GENERATED_HOST, "/old-generated?from=redirect");

  expect(response.status).toBe(308);
  expect(response.headers.get("location")).toBe("/api/generated?from=redirect");
});

test("uses the Rust compiler manifest for redirects without zero endpoints", async () => {
  const response = await get(rt, REDIRECT_ONLY_HOST, "/old-redirect-only");

  expect(response.status).toBe(301);
  expect(response.headers.get("location")).toBe("/new-redirect-only");
});

test("keeps exact response headers aligned with the Rust compiler artifact", async () => {
  const response = await get(rt, REDIRECT_ONLY_HOST, "/");

  expect(response.status).toBe(200);
  expect(response.headers.get("x-rust-compiled-header")).toBe("yes");
});

test("keeps pattern response headers aligned with the Rust compiler artifact", async () => {
  const response = await get(rt, REDIRECT_ONLY_HOST, "/assets/app.js");

  expect(response.status).toBe(200);
  expect(response.headers.get("x-rust-asset")).toBe("name=app.js");
});

test("keeps pattern redirects aligned with the Rust compiler artifact", async () => {
  const response = await get(rt, REDIRECT_ONLY_HOST, "/rust-rewrite/page");

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("<h1>zero redirect only rust</h1>\n");
});

test("writes compiler-produced rule artifacts with runtime metadata", () => {
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces/spc_zero_redirect_only_rust/versions/ver_zero_redirect_only_rust_1",
  );

  for (const artifact of ["headers.php", "redirects.php"]) {
    const contents = readFileSync(path.join(versionRoot, artifact), "utf8");
    expect(contents).toContain("'runtime_schema' => 'static-runtime-v2'");
    expect(contents).toContain("'runtime_engine_version' => 'static-runtime-v2'");
    expect(contents).toContain("'generated_at' =>");
  }
});

test("writes compiler-produced Zero routes artifact when no base routes are merged", () => {
  const contents = readFileSync(
    path.join(
      rt.storageRoot,
      "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1/zero/routes.php",
    ),
    "utf8",
  );

  expect(contents).toContain("'runtime_schema' => 'static-runtime-v2'");
  expect(contents).toContain("'artifact_kind' => 'zero_routes'");
  expect(contents).toContain("'format' => 'stattic.zero.routes.v1'");
});

test("writes compiler-produced Zero endpoint index", () => {
  const index = JSON.parse(
    readFileSync(
      path.join(
        rt.storageRoot,
        "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1/zero/endpoints-index.json",
      ),
      "utf8",
    ),
  );

  expect(index).toMatchObject({
    runtime_schema: "static-runtime-v2",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.endpoints-index.v1",
    artifact_kind: "zero_endpoints_index",
  });
  expect(index.endpoints["POST /api/generated"]).toContain("zero/endpoints/post_api_generated_");
});

test("writes compiler-produced Zero run artifacts", () => {
  const versionRoot = path.join(
    rt.storageRoot,
    "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1",
  );
  const index = JSON.parse(readFileSync(path.join(versionRoot, "zero/runs-index.json"), "utf8"));
  expect(index).toMatchObject({
    runtime_schema: "static-runtime-v2",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.runs-index.v1",
    artifact_kind: "zero_runs_index",
  });
  expect(index.runs.query_todos).toContain("zero/runs/query_todos_");
  expect(index.runs.mutation_addTodo).toContain("zero/runs/mutation_addtodo_");

  const runArtifact = JSON.parse(
    readFileSync(path.join(versionRoot, index.runs.mutation_addTodo), "utf8"),
  );
  expect(runArtifact).toMatchObject({
    format: "stattic.zero.run.v1",
    kind: "run",
    runId: "mutation_addTodo",
    capabilities: { db: true, realtime: true },
  });
  expect(existsSync(path.join(versionRoot, runArtifact.sourcePath))).toBe(true);
  expect(existsSync(path.join(versionRoot, runArtifact.bytecodePath))).toBe(true);
  const source = readFileSync(path.join(versionRoot, runArtifact.sourcePath), "utf8");
  expect(source).toContain("Generated by stattic-zero-runner");
  expect(source).toContain("__statticDbHost");
  expect(source).toContain("__statticRealtime");
});

test("writes capability-specialized generated source during finalize", () => {
  const exactSlug = `post_api_generated_${sha256("POST\n/api/generated\n0").slice(0, 12)}`;
  const generatedSource = readFileSync(
    path.join(
      rt.storageRoot,
      "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1",
      `zero/endpoints/${exactSlug}.source.js`,
    ),
    "utf8",
  );

  expect(generatedSource).toContain("Generated by stattic-zero-runner");
  expect(generatedSource).toContain("__statticZeroBootstrap");
  expect(generatedSource).not.toContain("requestJson");
  expect(generatedSource).not.toContain("__statticDbHost");
  expect(generatedSource).toContain("__statticFetch");
  expect(generatedSource).toContain("__statticAuth");
  expect(generatedSource).toContain("__statticEnv");
  expect(generatedSource).toContain("__statticRealtime");
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
    spaceId: "spc_zero_generated_rust",
    dbInstalled: true,
    dbCapability: true,
  });
});

test("precomputes compact DB metadata for generated DB endpoints", () => {
  const dynamicSlug = `get_api_generated_id_${sha256("GET\n/api/generated/:id\n1").slice(0, 12)}`;
  const artifact = JSON.parse(
    readFileSync(
      path.join(
        rt.storageRoot,
        "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1",
        `zero/endpoints/${dynamicSlug}.json`,
      ),
      "utf8",
    ),
  );

  expect(artifact.db).toMatchObject({
    schemaHash: "sha256:generated",
    tables: {
      todos: {
        name: "todos",
        physicalName: "lb_spc_zero_generated_todos",
        quotedName: "`lb_spc_zero_generated_todos`",
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

test("applies compiler-produced Zero DB migrations during finalize", () => {
  const migrations = JSON.parse(
    readFileSync(
      path.join(
        rt.storageRoot,
        "spaces/spc_zero_generated_rust/versions/ver_zero_generated_rust_1/zero/migrations.json",
      ),
      "utf8",
    ),
  );

  expect(migrations).toMatchObject({
    runtime_schema: "static-runtime-v2",
    runtime_engine_version: "static-runtime-v2",
    format: "stattic.zero.migrations.v1",
    artifact_kind: "zero_migrations",
  });
  expect(migrations.statements).toContain(
    "CREATE TABLE IF NOT EXISTS `zero_items` (`todo_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `todo_title` TEXT NULL, PRIMARY KEY (`todo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  );
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

test("applies the native response-header policy to every endpoint response", async () => {
  // Safe custom headers pass through unchanged.
  const clean = await get(rt, GENERATED_HOST, "/api/generated/response-headers");
  expect(clean.status).toBe(200);
  expect(clean.headers.get("x-app-result")).toBe("ready");

  // A platform-reserved x-spacefast-* header fails the whole response closed
  // and never reaches the client.
  const forbidden = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=forbidden");
  expect(forbidden.status).toBe(502);
  expect(forbidden.headers.get("x-spacefast-zero-injected")).toBeNull();
  expect(await forbidden.json()).toMatchObject({
    error: { code: "zero_response_header_forbidden" },
  });

  // Oversized values and case-insensitive duplicates are rejected as invalid.
  const oversized = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=oversized");
  expect(oversized.status).toBe(502);
  expect(await oversized.json()).toMatchObject({
    error: { code: "zero_response_header_invalid" },
  });
  const duplicate = await get(rt, GENERATED_HOST, "/api/generated/response-headers?mode=duplicate");
  expect(duplicate.status).toBe(502);
  expect(await duplicate.json()).toMatchObject({
    error: { code: "zero_response_header_invalid" },
  });
});

test("the runner ignores ambient DATABASE_URL and requires the labeled URL", async () => {
  // Capture a real DB-endpoint envelope by making one request through PHP.
  const primed = await get(rt, GENERATED_HOST, "/api/generated/db", { method: "POST" });
  expect(primed.status).toBe(200);
  const envelope = readFileSync(runnerCapturePath);

  // Ambient DATABASE_URL alone (the pre-hardening fallback) no longer feeds
  // the DB layer: the operation fails closed before any connection.
  const ambientOnly = Bun.spawnSync({
    cmd: [runnerPath],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: { DATABASE_URL: mysqlUrl },
  });
  expect(ambientOnly.exitCode).toBe(0);
  const ambientResponse = JSON.parse(ambientOnly.stdout.toString());
  expect(ambientResponse.status).toBe(200);
  const ambientBody = JSON.parse(ambientResponse.body);
  expect(ambientBody.insert).toMatchObject({ ok: false, code: "zero_db_url_missing" });

  // A labeled URL with an unknown provenance label also fails closed.
  const mislabeled = Bun.spawnSync({
    cmd: [runnerPath],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysqlUrl,
      SPACEFAST_ZERO_DATABASE_URL_SOURCE: "ambient",
    },
  });
  expect(mislabeled.exitCode).toBe(0);
  const mislabeledBody = JSON.parse(JSON.parse(mislabeled.stdout.toString()).body);
  expect(mislabeledBody.insert).toMatchObject({ ok: false, code: "zero_db_url_invalid" });

  // The labeled provider URL is the one accepted path.
  const labeled = Bun.spawnSync({
    cmd: [runnerPath],
    stdin: envelope,
    stdout: "pipe",
    stderr: "pipe",
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysqlUrl,
      SPACEFAST_ZERO_DATABASE_URL_SOURCE: "provider",
    },
  });
  expect(labeled.exitCode).toBe(0);
  const labeledBody = JSON.parse(JSON.parse(labeled.stdout.toString()).body);
  expect(labeledBody.insert).toMatchObject({ ok: true });
});

test("rolls back failed Zero DB transactions through local MySQL", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/db-rollback", { method: "POST" });
  const text = await response.text();

  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  expect(JSON.parse(text)).toEqual({
    rollback: {
      ok: false,
      code: "zero_db_execute_failed",
      message: expect.stringContaining("missing_column"),
    },
    rows: [],
  });
});

test("makes ctx.transaction writes atomic through the real Rust runner", async () => {
  const response = await get(rt, GENERATED_HOST, "/api/generated/atomic-rollback", {
    method: "POST",
    headers: { "content-type": "application/json" },
  });
  const text = await response.text();

  if (response.status !== 200) {
    throw new Error(`expected 200, got ${response.status}: ${text}`);
  }
  expect(JSON.parse(text)).toEqual({
    duplicateRejected: true,
    row: null,
  });
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
