// Names the native runtime binary the tests run, building it when there is
// none. Kept apart from harness.ts so a test runner can call it outside
// `bun test`: on a miss every harness process would otherwise install the
// toolchain and build at once, and a runner that starts harnesses side by
// side resolves the binary first.
import { spawnSync } from "node:child_process";
import { chmodSync, existsSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

const RUNTIME_DIR = path.resolve(import.meta.dir, "..");
const REPO_ROOT = path.resolve(RUNTIME_DIR, "..");
const nativeBinaryPaths = new Map<string, string>();
function cargoDebugBinary(binary: string) {
  const metadata = spawnSync("cargo", ["metadata", "--format-version", "1", "--no-deps"], {
    cwd: REPO_ROOT,
    encoding: "utf8",
  });
  if (metadata.status !== 0) {
    throw new Error(`cargo metadata failed:\n${metadata.stdout}\n${metadata.stderr}`);
  }
  const parsed = z
    .object({ target_directory: z.string().min(1) })
    .safeParse(JSON.parse(metadata.stdout));
  if (!parsed.success) {
    throw new Error("cargo metadata returned no target_directory");
  }
  return path.join(
    parsed.data.target_directory,
    "debug",
    `${binary}${process.platform === "win32" ? ".exe" : ""}`,
  );
}

function packagedBinaryRuns(binary: string, packaged: string) {
  // GitHub workspace artifacts preserve the packaged bytes but not Unix
  // executable modes. Restore the engine-manifest contract before probing.
  chmodSync(packaged, 0o755);
  const probe = spawnSync(packaged, ["--self-test"], { encoding: "utf8" });
  if (probe.status === 0) return true;
  if (probe.error && "code" in probe.error && probe.error.code === "ENOEXEC") {
    // A locally cached Linux bundle is not executable on a macOS developer
    // host. Build the host test binary; never mask a runnable packaged
    // binary that reports a failed self-test.
    return false;
  }
  throw new Error(
    `packaged runtime binary failed (${binary}):\n${probe.stdout ?? ""}\n${probe.stderr ?? ""}`,
  );
}

function runtimeNativeBinaryPath(binary: string, packageName: string, configured?: string) {
  const cached = nativeBinaryPaths.get(binary);
  if (cached) return cached;
  if (configured && existsSync(configured)) {
    nativeBinaryPaths.set(binary, configured);
    return configured;
  }
  // In CI, `runtime/bin` is restored from a cache that main writes, keyed on
  // every native input, so a hit always matches this checkout's Rust; on a
  // miss the job builds with cargo below. The directory is also
  // gitignored, which means a developer host can hold one from any earlier
  // build — and preferring it made the suite silently test a stale compiler
  // against fresh PHP. Outside CI the checkout is the only trustworthy source;
  // cargo makes an unchanged rebuild a no-op anyway. `configured` above still
  // lets anyone point at a specific binary deliberately.
  const packaged = path.join(RUNTIME_DIR, "bin", binary);
  if (process.env.CI && existsSync(packaged) && packagedBinaryRuns(binary, packaged)) {
    nativeBinaryPaths.set(binary, packaged);
    return packaged;
  }
  const candidate = cargoDebugBinary(binary);
  const build = spawnSync("cargo", ["build", "--locked", "-p", packageName], {
    cwd: REPO_ROOT,
    encoding: "utf8",
  });
  if (build.status !== 0) {
    throw new Error(`runtime native build failed (${binary}):\n${build.stdout}\n${build.stderr}`);
  }
  if (!existsSync(candidate)) {
    throw new Error(`runtime native build produced no artifact: ${candidate}`);
  }
  nativeBinaryPaths.set(binary, candidate);
  return candidate;
}

/**
 * The native runtime binary these tests run: the one SPACEFAST_RUNTIME_BIN
 * names, in CI the packaged binary restored for this checkout's native inputs,
 * and otherwise a cargo debug build.
 */
export function runtimeBinaryPath() {
  return runtimeNativeBinaryPath(
    "stattic-runtime",
    "stattic-runtime-compiler",
    process.env.SPACEFAST_RUNTIME_BIN,
  );
}
