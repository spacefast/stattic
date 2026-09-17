import { expect, test } from "bun:test";
import { writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import { api, PHP_BINARY, startRuntime } from "./harness.ts";

test("independent Bun processes reach their own PHP fixture and signing key", async () => {
  const runtime = await startRuntime();
  const launcher = path.join(runtime.root, "competing-php");
  writeFileSync(
    launcher,
    `#!${process.execPath}
import { spawn } from "node:child_process";
const args = process.argv.slice(2);
const competitor = spawn(${JSON.stringify(PHP_BINARY)}, args, {
  cwd: ${JSON.stringify(runtime.root)},
  stdio: ["ignore", "ignore", "pipe"],
});
await new Promise((resolve, reject) => {
  competitor.stderr.on("data", (chunk) => {
    if (chunk.toString().includes("Development Server")) resolve();
  });
  competitor.once("error", reject);
  competitor.once("exit", () => reject(new Error("Competing PHP exited")));
});
// Keep the competitor alive even if the intended PHP cannot bind its port.
spawn(${JSON.stringify(PHP_BINARY)}, args, { stdio: ["ignore", "ignore", "inherit"] });
`,
    { mode: 0o755 },
  );
  const receipt = z.object({ origin: z.url(), status: z.number() });
  const ready = Promise.withResolvers<z.infer<typeof receipt>>();
  const child = Bun.spawn(
    [
      process.execPath,
      "--eval",
      `
import { api, startRuntime } from ${JSON.stringify(new URL("./harness.ts", import.meta.url).href)};
const stop = new Promise((resolve) => process.once("message", resolve));
const runtime = await startRuntime({ phpBinary: ${JSON.stringify(launcher)} });
process.once("SIGTERM", () => {
  runtime.stop();
  process.exit(1);
});
try {
  const response = await api(runtime, "GET", "/__spacefast/api.php/state", "read_state");
  process.send({ origin: runtime.baseUrl, status: response.status });
  await stop;
} finally {
  runtime.stop();
}
`,
    ],
    {
      stdout: "ignore",
      stderr: "pipe",
      ipc(message) {
        const result = receipt.safeParse(message);
        if (result.success) ready.resolve(result.data);
        else ready.reject(result.error);
      },
      onExit(_child, code) {
        ready.reject(new Error(`Fixture process exited before readiness: ${code}`));
      },
    },
  );
  const deadline = AbortSignal.timeout(20_000);
  const abort = () => ready.reject(new Error("Fixture process readiness timed out"));
  deadline.addEventListener("abort", abort, { once: true });
  try {
    const childReceipt = await ready.promise;
    expect(childReceipt.origin).not.toBe(runtime.baseUrl);
    expect(childReceipt.status).toBe(200);
    const response = await api(runtime, "GET", "/__spacefast/api.php/state", "read_state");
    expect(response.status).toBe(200);
    child.send("stop");
    expect(await child.exited, await new Response(child.stderr).text()).toBe(0);
  } finally {
    deadline.removeEventListener("abort", abort);
    child.kill();
    await child.exited;
    runtime.stop();
  }
}, 30_000);

test("a failed PHP spawn rejects startup", async () => {
  await expect(startRuntime({ phpBinary: "/nonexistent-spacefast-test-php" })).rejects.toThrow(
    "PHP fixture startup failed.",
  );
});
