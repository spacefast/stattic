import { spawnSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync } from "node:fs";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dirname, "../../..");
const target = path.join(repoRoot, "packages/routing/dist/stattic-runtime-core.wasm");
const configured = process.env.STATTIC_RUNTIME_WASI_WASM?.trim();
if (configured && !existsSync(configured)) {
  throw new Error(`stattic_runtime_core_wasm_configured_missing:${configured}`);
}
const prebuilt =
  configured || path.join(repoRoot, "packages/routing/.artifacts/stattic-runtime-core.wasm");
if (existsSync(prebuilt)) {
  mkdirSync(path.dirname(target), { recursive: true });
  copyFileSync(prebuilt, target);
  process.exit(0);
}
const result = spawnSync(
  process.execPath,
  [path.join(repoRoot, "scripts/build-runtime-wasi.mjs"), target],
  { cwd: repoRoot, stdio: "inherit" },
);
if (result.status !== 0) process.exit(result.status ?? 1);
