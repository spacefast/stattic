/// <reference lib="dom" />

// Node-only WASI host for the Rust runtime core (`stattic-runtime-core.wasm`,
// built from `crates/stattic-runtime-wasi`). Never import this module (or
// anything that reaches it: ./prepare.js, ./finalize.js, ./strict-config.js)
// from browser-shipped code — the browser surface is ./prepare-browser.js.

import { randomFillSync } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { WASI } from "node:wasi";

import type { BasicAuthPlan, RoutingCompilation, RoutingCompileOptions } from "./types.js";

type RuntimeCoreExports = {
  memory: WebAssembly.Memory;
  /** Absent on newer toolchains: LLD injects ctor calls into each export. */
  __wasm_call_ctors?: () => void;
  sf_alloc: (length: number) => number;
  sf_dealloc: (pointer: number, capacity: number) => void;
  sf_compile_routing: (pointer: number, length: number) => bigint;
  sf_prepare_finalize: (pointer: number, length: number) => bigint;
};

let cachedExports: RuntimeCoreExports | null = null;

type EmbeddedRuntimeCoreGlobal = typeof globalThis & {
  __STATTIC_RUNTIME_WASI_WASM__?: Uint8Array;
};

function runtimeCoreExports(): RuntimeCoreExports {
  if (cachedExports) return cachedExports;
  const directory = path.dirname(fileURLToPath(import.meta.url));
  const candidates = [
    path.join(directory, "stattic-runtime-core.wasm"),
    path.resolve(directory, "../.artifacts/stattic-runtime-core.wasm"),
    path.resolve(directory, "../../../target/wasm32-wasip1/release/stattic_runtime_wasi.wasm"),
  ];
  // oxlint-disable-next-line eslint/no-underscore-dangle -- injected build-time global name is a public bundler contract.
  const embedded = (globalThis as EmbeddedRuntimeCoreGlobal).__STATTIC_RUNTIME_WASI_WASM__;
  const wasmPath = candidates.find(existsSync);
  if (!embedded && !wasmPath) throw new Error("stattic_runtime_core_wasm_missing");

  let memory: WebAssembly.Memory | null = null;
  const wasi = new WASI({ version: "preview1", args: [], env: {}, preopens: {} });
  const imports = {
    ...wasi.wasiImport,
    random_get(pointer: number, length: number) {
      if (!memory) return 21;
      randomFillSync(new Uint8Array(memory.buffer, pointer, length));
      return 0;
    },
  };
  const wasmBytes = Uint8Array.from(embedded ?? readFileSync(wasmPath!));
  const module = new WebAssembly.Module(wasmBytes);
  const instance = new WebAssembly.Instance(module, { wasi_snapshot_preview1: imports });
  const exports = instance.exports as unknown as RuntimeCoreExports;
  memory = exports.memory;
  // oxlint-disable-next-line eslint/no-underscore-dangle -- WebAssembly toolchain ABI export name.
  exports.__wasm_call_ctors?.();
  cachedExports = exports;
  return exports;
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- WASM decoding follows the caller-selected protocol output type.
export function invokeFinalizerJson<T>(
  operation: "sf_compile_routing" | "sf_prepare_finalize",
  input: unknown,
): T {
  const exports = runtimeCoreExports();
  const encoded = new TextEncoder().encode(JSON.stringify(input));
  const inputPointer = exports.sf_alloc(encoded.length);
  try {
    new Uint8Array(exports.memory.buffer, inputPointer, encoded.length).set(encoded);
    const packed = exports[operation](inputPointer, encoded.length);
    const outputPointer = Number(packed >> 32n);
    const outputLength = Number(packed & 0xffff_ffffn);
    try {
      const decoded = new TextDecoder().decode(
        new Uint8Array(exports.memory.buffer, outputPointer, outputLength),
      );
      return JSON.parse(decoded) as T;
    } finally {
      exports.sf_dealloc(outputPointer, outputLength);
    }
  } finally {
    exports.sf_dealloc(inputPointer, encoded.length);
  }
}

/** Rust routing compilation always carries the Basic-Auth lowering plan. */
export type WasmRoutingCompilation = RoutingCompilation & {
  basicAuth: BasicAuthPlan[];
  sanitizedHeaders: string | null;
};

export function compileRoutingFilesWasm(input: {
  redirects?: string | undefined;
  headers?: string | undefined;
  options?: RoutingCompileOptions | undefined;
}): WasmRoutingCompilation {
  const result = invokeFinalizerJson<WasmRoutingCompilation & { error?: string }>(
    "sf_compile_routing",
    {
      redirects: input.redirects ?? "",
      headers: input.headers ?? "",
      assignedHostnames: input.options?.assignedHostnames ?? [],
      basicAuthAllowed: input.options?.basicAuthAllowed ?? true,
      basicAuthSaltContext: input.options?.basicAuthSaltContext,
    },
  );
  if (result.error) throw new Error(`stattic_runtime_core_routing_failed:${result.error}`);
  return result;
}

export function verifyBcryptPassword(password: string, hash: string): boolean {
  const response = invokeFinalizerJson<{
    format: string;
    ok: boolean;
    output?: boolean;
    error?: { code: string; message: string };
  }>("sf_prepare_finalize", {
    format: "spacefast.finalizer.prepare.request.v1",
    operation: "verify_bcrypt",
    input: { password, hash },
  });
  if (response.format !== "spacefast.finalizer.prepare.response.v1" || !response.ok) {
    throw new Error(
      `stattic_runtime_core_bcrypt_verify_failed:${response.error?.code ?? "unknown"}`,
    );
  }
  return response.output === true;
}
