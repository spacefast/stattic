import { expect, test } from "bun:test";
import { execFileSync, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";

const packageRoot = path.resolve(import.meta.dirname, "..");
const repoRoot = path.resolve(packageRoot, "../..");

test("the package contains the pure-TS compiler and one fresh Rust wasm artifact", async () => {
  execFileSync("bun", ["run", "build"], { cwd: packageRoot, stdio: "pipe" });
  const dist = path.join(packageRoot, "dist");
  const files = readdirSync(dist, { recursive: true }).map(String).toSorted();

  // The browser-safe pure-TS surface stays in dist alongside the wasm-backed
  // node surface.
  for (const expected of [
    "compile.js",
    "match.js",
    "plan.js",
    "prepare.js",
    "finalize.js",
    "prepare-browser.js",
    "browser.js",
    "stattic-runtime-core.wasm",
  ]) {
    expect(files).toContain(expected);
  }

  const packaged = readFileSync(path.join(dist, "stattic-runtime-core.wasm"));
  const built = readFileSync(
    path.join(repoRoot, "target/wasm32-wasip1/release/stattic_runtime_wasi.wasm"),
  );
  expect(createHash("sha256").update(packaged).digest("hex")).toBe(
    createHash("sha256").update(built).digest("hex"),
  );

  const { compileRoutingFilesWasm } = await import(
    `${new URL("../dist/finalize.js", import.meta.url).href}?artifact-test=${Date.now()}`
  );
  const compiled = compileRoutingFilesWasm({
    redirects: "https://www.example.com/old /new 301",
    headers: "/new\n  X-Artifact: rust",
    options: { assignedHostnames: ["www.example.com"] },
  });
  expect(compiled.redirects).toHaveLength(1);
  expect(compiled.headers).toHaveLength(1);
  expect(compiled.diagnostics).toEqual([]);
}, 240_000);

test("an explicit missing Rust WASM artifact fails closed", () => {
  const configured = path.join(packageRoot, ".artifacts/does-not-exist.wasm");
  const result = spawnSync(
    process.execPath,
    [path.join(packageRoot, "scripts/copy-finalizer-wasm.mjs")],
    {
      cwd: packageRoot,
      encoding: "utf8",
      env: { ...process.env, STATTIC_RUNTIME_WASI_WASM: configured },
    },
  );
  expect(result.status).not.toBe(0);
  expect(`${result.stdout}\n${result.stderr}`).toContain(
    `stattic_runtime_core_wasm_configured_missing:${configured}`,
  );
});
