// Builds the Rust runtime core as a wasm32-wasip1 cdylib with the repository's
// pinned toolchain and copies the artifact to each output path argument.
// JavaScript consumers ship it as `stattic-runtime-core.wasm`.

import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

import { copyFileAtomic } from "../packages/routing/scripts/copy-file-atomic.mjs";

const repoRoot = path.resolve(import.meta.dirname, "..");
const toolchainFile = readFileSync(path.join(repoRoot, "rust-toolchain.toml"), "utf8");
const toolchain = toolchainFile.match(/^channel\s*=\s*"([^"]+)"/m)?.[1];
if (!toolchain) throw new Error("rust_toolchain_channel_missing");

const target = "wasm32-wasip1";
const add = spawnSync("rustup", ["target", "add", "--toolchain", toolchain, target], {
  cwd: repoRoot,
  stdio: "inherit",
});
if (add.error) throw add.error;
if (add.status !== 0) process.exit(add.status ?? 1);
const compiler = spawnSync("rustup", ["which", "--toolchain", toolchain, "rustc"], {
  cwd: repoRoot,
  encoding: "utf8",
});
if (compiler.error) throw compiler.error;
if (compiler.status !== 0) {
  process.stderr.write(compiler.stderr ?? "");
  process.exit(compiler.status ?? 1);
}
const rustc = compiler.stdout.trim();
if (!rustc) throw new Error("rust_toolchain_compiler_missing");

const result = spawnSync(
  "rustup",
  [
    "run",
    toolchain,
    "cargo",
    "build",
    "--release",
    "--locked",
    "--target",
    target,
    "-p",
    "stattic-runtime-wasi",
  ],
  { cwd: repoRoot, env: { ...process.env, RUSTC: rustc }, stdio: "inherit" },
);
if (result.error) throw result.error;
if (result.status !== 0) process.exit(result.status ?? 1);

const source = path.join(repoRoot, "target/wasm32-wasip1/release/stattic_runtime_wasi.wasm");
for (const output of process.argv.slice(2)) {
  const destination = path.resolve(output);
  copyFileAtomic(source, destination);
}
