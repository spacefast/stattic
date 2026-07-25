/// <reference lib="dom" />

// Browser WebAssembly host for the Rust runtime core. This module must stay
// free of Node imports; the wasm artifact is fetch-loaded next to the bundle.

type RuntimeCoreExports = {
  memory: WebAssembly.Memory;
  /** Absent on newer toolchains: LLD injects ctor calls into each export. */
  __wasm_call_ctors?: () => void;
  sf_alloc: (length: number) => number;
  sf_dealloc: (pointer: number, capacity: number) => void;
  sf_prepare_finalize: (pointer: number, length: number) => bigint;
};

const ERRNO_SUCCESS = 0;
const ERRNO_NOSYS = 52;

function writeU32(memory: WebAssembly.Memory | null, pointer: number, value: number): number {
  if (!memory) return ERRNO_NOSYS;
  new DataView(memory.buffer).setUint32(pointer, value, true);
  return ERRNO_SUCCESS;
}

export function instantiateBrowserFinalizer(wasmBytes: BufferSource): RuntimeCoreExports {
  let memory: WebAssembly.Memory | null = null;
  const unavailable = () => ERRNO_NOSYS;
  const imports = {
    random_get(pointer: number, length: number) {
      if (!memory) return ERRNO_NOSYS;
      const destination = new Uint8Array(memory.buffer, pointer, length);
      for (let offset = 0; offset < destination.length; offset += 65_536) {
        crypto.getRandomValues(destination.subarray(offset, offset + 65_536));
      }
      return ERRNO_SUCCESS;
    },
    environ_get: () => ERRNO_SUCCESS,
    environ_sizes_get(countPointer: number, sizePointer: number) {
      const count = writeU32(memory, countPointer, 0);
      const size = writeU32(memory, sizePointer, 0);
      return count === ERRNO_SUCCESS && size === ERRNO_SUCCESS ? ERRNO_SUCCESS : ERRNO_NOSYS;
    },
    clock_time_get(_clockId: number, _precision: bigint, timePointer: number) {
      if (!memory) return ERRNO_NOSYS;
      new DataView(memory.buffer).setBigUint64(timePointer, BigInt(Date.now()) * 1_000_000n, true);
      return ERRNO_SUCCESS;
    },
    fd_close: unavailable,
    fd_fdstat_get: unavailable,
    fd_filestat_get: unavailable,
    fd_prestat_get: unavailable,
    fd_prestat_dir_name: unavailable,
    fd_read: unavailable,
    fd_readdir: unavailable,
    fd_seek: unavailable,
    fd_write(_fd: number, _iovs: number, _iovsLength: number, writtenPointer: number) {
      return writeU32(memory, writtenPointer, 0);
    },
    path_create_directory: unavailable,
    path_filestat_get: unavailable,
    path_link: unavailable,
    path_open: unavailable,
    path_readlink: unavailable,
    path_remove_directory: unavailable,
    path_rename: unavailable,
    path_unlink_file: unavailable,
    proc_exit(exitCode: number): never {
      throw new Error(`stattic_runtime_core_wasm_exit:${exitCode}`);
    },
  };
  const module = new WebAssembly.Module(wasmBytes);
  const instance = new WebAssembly.Instance(module, { wasi_snapshot_preview1: imports });
  const exports = instance.exports as unknown as RuntimeCoreExports;
  memory = exports.memory;
  // oxlint-disable-next-line eslint/no-underscore-dangle -- WebAssembly toolchain ABI export name.
  exports.__wasm_call_ctors?.();
  return exports;
}

let exportsPromise: Promise<RuntimeCoreExports> | null = null;

async function browserFinalizerExports(): Promise<RuntimeCoreExports> {
  exportsPromise ??= fetch(new URL("./stattic-runtime-core.wasm", import.meta.url)).then(
    async (response) => {
      if (!response.ok)
        throw new Error(`stattic_runtime_core_wasm_fetch_failed:${response.status}`);
      return instantiateBrowserFinalizer(await response.arrayBuffer());
    },
  );
  return exportsPromise;
}

export async function invokeBrowserFinalizerJson<T>(input: unknown): Promise<T> {
  return invokeBrowserFinalizerJsonWithExports(await browserFinalizerExports(), input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- WASM decoding follows the caller-selected protocol output type.
export function invokeBrowserFinalizerJsonWithExports<T>(
  exports: RuntimeCoreExports,
  input: unknown,
): T {
  const encoded = new TextEncoder().encode(JSON.stringify(input));
  const inputPointer = exports.sf_alloc(encoded.length);
  try {
    new Uint8Array(exports.memory.buffer, inputPointer, encoded.length).set(encoded);
    const packed = exports.sf_prepare_finalize(inputPointer, encoded.length);
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
