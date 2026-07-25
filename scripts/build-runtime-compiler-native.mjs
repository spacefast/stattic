import { spawnSync } from "node:child_process";
import { copyFileSync, mkdirSync, mkdtempSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dirname, "..");
const [compilerOutput, zeroRunnerOutput, wasiOutput] = process.argv.slice(2);
if (!compilerOutput || !zeroRunnerOutput || !wasiOutput) {
  throw new Error("expected compiler, Zero runner, and WASI output paths");
}

const exportRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-runtime-native-"));
try {
  const result = spawnSync(
    "docker",
    [
      "buildx",
      "build",
      "--platform",
      "linux/amd64",
      "--target",
      "export",
      "--output",
      `type=local,dest=${exportRoot}`,
      "-f",
      "infra/runtime-compiler/Dockerfile",
      ".",
    ],
    { cwd: repoRoot, stdio: "inherit" },
  );
  if (result.status !== 0) process.exit(result.status ?? 1);

  for (const [sourceName, output] of [
    ["stattic-runtime-compiler", compilerOutput],
    ["stattic-zero-runner", zeroRunnerOutput],
    ["stattic-runtime-core.wasm", wasiOutput],
  ]) {
    const destination = path.resolve(output);
    mkdirSync(path.dirname(destination), { recursive: true });
    copyFileSync(path.join(exportRoot, sourceName), destination);
  }
} finally {
  rmSync(exportRoot, { recursive: true, force: true });
}
