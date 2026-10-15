import { afterAll, beforeAll, expect, test } from "bun:test";
import path from "node:path";

import { z } from "zod";

import { op } from "./db-broker-corpus.ts";
import {
  MYSQL_SETUP_TIMEOUT_MS,
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const CLI_PATH = path.resolve(import.meta.dir, "db-broker-cli.php");
const CONTAINER_NAME_PREFIX = "stattic-db-broker-mariadb";
const ROOT_PASSWORD = "mariadb-deadline-secret";

let mariaDb: MysqlContainer;

beforeAll(async () => {
  mariaDb = await startMysqlContainer({
    namePrefix: CONTAINER_NAME_PREFIX,
    database: "broker_mariadb_test",
    rootPassword: ROOT_PASSWORD,
    flavor: "mariadb",
  });
}, MYSQL_SETUP_TIMEOUT_MS + 10_000);

afterAll(() => {
  mariaDb?.stop();
  stopMysqlContainers(CONTAINER_NAME_PREFIX);
});

test("PHP read deadlines use MariaDB's session timeout", async () => {
  const started = performance.now();
  const proc = Bun.spawn(
    [
      "php",
      CLI_PATH,
      JSON.stringify({
        url: mariaDb.url,
        source: "provider",
        capabilities: ["db.read"],
        readDeadlineMs: 100,
        operations: [op({ sql: "SELECT 1 AS ok" }), op({ sql: "SELECT SLEEP(2)" })],
      }),
    ],
    { stdout: "pipe", stderr: "pipe" },
  );
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  const elapsedMs = performance.now() - started;

  expect(`${exitCode} ${stderr}`).toStartWith("0 ");
  const payload = z.object({ responses: z.array(z.string()) }).parse(JSON.parse(stdout));
  expect(payload.responses[0]).toBe('{"ok":true,"rows":[{"ok":1}]}');
  const deadlineFailure = z
    .object({ code: z.string() })
    .parse(JSON.parse(payload.responses[1] ?? "null"));
  expect(deadlineFailure.code).toBe("zero_db_query_failed");
  expect(elapsedMs).toBeLessThan(5_000);
});

// MariaDB ignores MySQL's per-statement hint, so a dump's row counts are bounded
// by the session's own limits instead. Narrowing them must cut a statement while
// the socket keeps its read timeout: once restored, a longer read on the same
// link still succeeds.
test("narrowed statement limits cut a MariaDB read, and restoring them frees the link", async () => {
  const proc = Bun.spawn(
    [
      "php",
      CLI_PATH,
      JSON.stringify({
        url: mariaDb.url,
        source: "provider",
        capabilities: ["db.read"],
        readDeadlineMs: 10_000,
        action: "statement_limits",
        statementMs: 300,
        lockWaitSeconds: 1,
        limited: [op({ sql: "SELECT SLEEP(2) AS slept" })],
        after: [op({ sql: "SELECT SLEEP(1) AS slept" })],
      }),
    ],
    { stdout: "pipe", stderr: "pipe" },
  );
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);

  expect(`${exitCode} ${stderr}`).toStartWith("0 ");
  const payload = z
    .object({
      narrowed: z.boolean(),
      limited: z.array(z.string()),
      restored: z.boolean(),
      after: z.array(z.string()),
    })
    .parse(JSON.parse(stdout));
  expect(payload.narrowed).toBe(true);
  expect(z.object({ code: z.string() }).parse(JSON.parse(payload.limited[0] ?? "null")).code).toBe(
    "zero_db_query_failed",
  );
  expect(payload.restored).toBe(true);
  expect(payload.after[0]).toBe('{"ok":true,"rows":[{"slept":0}]}');
});
