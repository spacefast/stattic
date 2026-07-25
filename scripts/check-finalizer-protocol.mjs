// Drift guard for the generated finalizer protocol files. Regenerates
// `packages/routing/src/protocol.generated.ts` and
// `runtime/engine/shared/finalizer-protocol.generated.php` from the Rust
// authorities (`stattic_runtime_core::protocol` + `config/current.rs`) and
// fails when the checked-in copies differ. Mirrors the cargo-test drift
// checks in `crates/stattic-runtime-core/src/protocol.rs`.

import { spawnSync } from "node:child_process";
import { writeFileSync } from "node:fs";
import path from "node:path";

const repoRoot = path.resolve(import.meta.dirname, "..");

const outputs = [
  { args: [], file: "packages/routing/src/protocol.generated.ts" },
  { args: ["--php"], file: "runtime/engine/shared/finalizer-protocol.generated.php" },
];

for (const { args, file } of outputs) {
  const generate = spawnSync(
    "cargo",
    [
      "run",
      "--locked",
      "--quiet",
      "-p",
      "stattic-runtime-compiler",
      "--bin",
      "protocol-codegen",
      "--",
      ...args,
    ],
    { cwd: repoRoot, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 },
  );
  if (generate.status !== 0) {
    process.stderr.write(generate.stderr ?? "");
    throw new Error(`finalizer_protocol_codegen_failed:${file}`);
  }
  writeFileSync(path.join(repoRoot, file), generate.stdout);
  if (file.endsWith(".ts")) {
    // Generated TypeScript is checked in formatter-clean (same pattern as the
    // wpcloud-sdk codegen); PHP stays byte-exact against the Rust emitter.
    const format = spawnSync(path.join(repoRoot, "node_modules/.bin/oxfmt"), [file], {
      cwd: repoRoot,
      stdio: "inherit",
    });
    if (format.status !== 0) throw new Error(`finalizer_protocol_format_failed:${file}`);
  }
}

const diff = spawnSync("git", ["diff", "--exit-code", "--", ...outputs.map((o) => o.file)], {
  cwd: repoRoot,
  stdio: "inherit",
});
if (diff.status !== 0) {
  console.error(
    "Generated finalizer protocol files are stale. The regenerated output has been " +
      "written in place; review and commit it.",
  );
  process.exit(1);
}
console.log("Finalizer protocol generated files are current.");
