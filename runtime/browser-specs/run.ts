import { randomBytes } from "node:crypto";
import { fileURLToPath } from "node:url";

import {
  databaseUrlWithName,
  testWorkerDatabaseName,
} from "../../apps/control-plane/src/test/test-database-names.ts";
import {
  dropTestDatabases,
  provisionTestDatabases,
} from "../../apps/control-plane/src/test/test-database-provisioning.ts";

const FIXTURE_PROCESS = fileURLToPath(
  new URL("./control-plane-access-fixture.ts", import.meta.url),
);
const TEST_FILE = fileURLToPath(new URL("./origin-null-access.test.ts", import.meta.url));

// A bare local run must not share the dev database: a running `bun run dev`
// worker is NOTIFY-woken on operation insert and claims this fixture's email
// operations, minting their URLs with its own env instead of the fixture's.
// Clone a run-owned database exactly like `bun run test` does; CI passes an
// isolated SPACEFAST_TEST_DATABASE_URL and skips this.
let dropIsolatedDatabase: (() => Promise<void>) | undefined;
let fixtureDatabaseUrl = process.env.SPACEFAST_TEST_DATABASE_URL;
if (fixtureDatabaseUrl === undefined) {
  const adminUrl =
    process.env.SPACEFAST_TEST_DATABASE_ADMIN_URL ??
    "postgres://stattic:stattic@127.0.0.1:25432/stattic";
  const runId = randomBytes(6).toString("hex");
  await provisionTestDatabases({
    adminUrl,
    migrationsFolder: fileURLToPath(new URL("../../apps/control-plane/drizzle", import.meta.url)),
    runId,
    workerCount: 1,
  });
  fixtureDatabaseUrl = databaseUrlWithName(adminUrl, testWorkerDatabaseName(runId, 1));
  dropIsolatedDatabase = () => dropTestDatabases({ adminUrl, runId, workerCount: 1 });
}

const fixtureProcess = Bun.spawn([process.execPath, FIXTURE_PROCESS], {
  env: { ...process.env, SPACEFAST_TEST_DATABASE_URL: fixtureDatabaseUrl },
  stdout: "pipe",
  stderr: "inherit",
});

async function readFixture(): Promise<string> {
  const reader = fixtureProcess.stdout.getReader();
  const decoder = new TextDecoder();
  let buffered = "";
  for (;;) {
    // oxlint-disable-next-line no-await-in-loop -- stream chunks are sequential by definition.
    const chunk = await reader.read();
    if (chunk.done) throw new Error("control-plane access fixture exited before becoming ready");
    buffered += decoder.decode(chunk.value, { stream: true });
    const newline = buffered.indexOf("\n");
    if (newline >= 0) return buffered.slice(0, newline);
  }
}

let exitCode = 1;
try {
  const fixture = await readFixture();
  const test = Bun.spawn(
    [process.execPath, "test", TEST_FILE, "--timeout", "120000", ...process.argv.slice(2)],
    {
      env: { ...process.env, SPACEFAST_BROWSER_FIXTURE_JSON: fixture },
      stdin: "inherit",
      stdout: "inherit",
      stderr: "inherit",
    },
  );
  exitCode = await test.exited;
} finally {
  fixtureProcess.kill("SIGTERM");
  await fixtureProcess.exited;
  await dropIsolatedDatabase?.();
}

process.exit(exitCode);
