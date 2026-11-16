// Builds the engine's generated dashboard plugin when it is missing. Kept apart
// from harness.ts so a test runner can call it outside `bun test`: the build
// installs into a nested workspace and cannot run in several processes at
// once, so a runner that starts harnesses side by side builds it first.
import { spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import path from "node:path";

const RUNTIME_DIR = path.resolve(import.meta.dir, "..");
const REPO_ROOT = path.resolve(RUNTIME_DIR, "..");

export function ensureZeroDashboardPlugin(): void {
  if (existsSync(path.join(RUNTIME_DIR, "wordpress/zero-dashboard/next-admin.php"))) return;
  const build = spawnSync(
    process.execPath,
    [path.join(REPO_ROOT, "legacy/zero-0.8/dashboard/scripts/build.ts")],
    {
      cwd: REPO_ROOT,
      encoding: "utf8",
    },
  );
  if (build.status !== 0) {
    throw new Error(`Zero dashboard build failed:\n${build.stdout}\n${build.stderr}`);
  }
}
