import { expect, test } from "bun:test";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";

import { z } from "zod";

import { api, PHP_BINARY, startPhpServer, startRuntime } from "./harness.ts";
import { WORDPRESS_IMAGE } from "./zero-wordpress.ts";

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

test("failed Linux PHP startup removes its container", async () => {
  const root = mkdtempSync(path.join(tmpdir(), "spacefast-linux-php-test-"));
  const router = path.join(root, "router.php");
  try {
    writeFileSync(router, "<?php http_response_code(503);");
    await expect(
      startPhpServer({ linux: true, args: [], router, cwd: root, env: {} }),
    ).rejects.toMatchObject({ cause: { message: "php_health_failed:503" } });
    const containers = Bun.spawnSync([
      "docker",
      "ps",
      "--all",
      "--quiet",
      "--filter",
      `volume=${root}`,
    ]);
    expect(containers.exitCode, containers.stderr.toString()).toBe(0);
    expect(containers.stdout.toString().trim()).toBe("");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}, 360_000);

test("failed startup reaps its creator before removing a potentially late container", async () => {
  const docker = Bun.which("docker");
  if (!docker) throw new Error("Docker is required for the Linux fixture tests");
  const root = mkdtempSync(path.join(tmpdir(), "spacefast-linux-php-race-"));
  const state = path.join(root, "creator.json");
  const image = WORDPRESS_IMAGE;
  writeFileSync(
    path.join(root, "docker"),
    `#!${process.execPath}
import { readFileSync, writeFileSync } from "node:fs";
const args = process.argv.slice(2);
if (args[0] === "run") {
  const server = Bun.serve({ hostname: "127.0.0.1", port: 0, fetch: () => new Response("unready", { status: 503 }) });
  writeFileSync(${JSON.stringify(state)}, JSON.stringify({ pid: process.pid, name: args[args.indexOf("--name") + 1] }));
  console.error("Development Server (http://127.0.0.1:" + server.port + ") started");
} else if (args[0] === "rm") {
  const removed = Bun.spawnSync([${JSON.stringify(docker)}, ...args]);
  const creator = JSON.parse(readFileSync(${JSON.stringify(state)}, "utf8"));
  let alive = true;
  try { process.kill(creator.pid, 0); } catch { alive = false; }
  // Complete a daemon-side create after removal saw no container, while
  // the creator still has permission to dispatch it. Use real Docker bytes.
  if (alive) {
    const created = Bun.spawnSync([${JSON.stringify(docker)}, "create", "--name", creator.name,
      "--platform", "linux/amd64", "--mount", ${JSON.stringify(`type=bind,source=${root},target=${root}`)},
      "--mount", "type=tmpfs,destination=/var/www/html", "--entrypoint", "php", ${JSON.stringify(image)}, "-r", "exit(0);"]);
    if (created.exitCode !== 0) throw new Error(created.stderr.toString());
  }
  process.stdout.write(removed.stdout);
  process.stderr.write(removed.stderr);
  process.exit(removed.exitCode);
} else {
  throw new Error("Unexpected Docker command: " + args[0]);
}
`,
    { mode: 0o755 },
  );
  const containers = () => {
    const result = Bun.spawnSync([
      "docker",
      "ps",
      "--all",
      "--quiet",
      "--filter",
      `volume=${root}`,
    ]);
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    return result.stdout.toString().trim().split(/\s+/).filter(Boolean);
  };
  try {
    const child = Bun.spawn(
      [
        process.execPath,
        "--eval",
        `import { startPhpServer } from ${JSON.stringify(new URL("./harness.ts", import.meta.url).href)};
try {
  await startPhpServer({ linux: true, cwd: ${JSON.stringify(root)}, args: [], router: "router.php", env: {} });
  throw new Error("Unexpected fixture readiness");
} catch (error) {
  if (error.cause?.message !== "php_health_failed:503") throw error;
}`,
      ],
      { env: { ...process.env, PATH: root }, stdout: "pipe", stderr: "pipe" },
    );
    const stderr = await new Response(child.stderr).text();
    expect(await child.exited, stderr).toBe(0);
    expect(containers()).toEqual([]);
  } finally {
    for (const name of containers()) {
      const removed = Bun.spawnSync(["docker", "rm", "--force", name]);
      expect(removed.exitCode, removed.stderr.toString()).toBe(0);
    }
    rmSync(root, { recursive: true, force: true });
  }
}, 30_000);

test("missing Docker explains the released runtime prerequisites", async () => {
  await expect(
    startPhpServer({
      linux: true,
      cwd: tmpdir(),
      args: [],
      router: "router.php",
      env: { PATH: "/nonexistent-spacefast-test-bin" },
    }),
  ).rejects.toThrow("require Docker with linux/amd64 support and host networking");
});
