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
