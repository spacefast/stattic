import { fileURLToPath } from "node:url";

const FIXTURE_PROCESS = fileURLToPath(
  new URL("./control-plane-access-fixture.ts", import.meta.url),
);
const TEST_FILE = fileURLToPath(new URL("./origin-null-access.test.ts", import.meta.url));

const fixtureProcess = Bun.spawn([process.execPath, FIXTURE_PROCESS], {
  env: process.env,
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
}

process.exit(exitCode);
