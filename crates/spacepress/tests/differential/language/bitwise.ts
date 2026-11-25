// Bitwise operators. Every action runs
// under Node and as compiled PHP; the JSON results must be byte-identical. Each construct is
// exercised three ways where the compiler has three paths: typed TypeScript, checked JavaScript
// (`bitwise-helpers.mjs`, untyped), and configuration-time evaluation (a module constant).
// JSON cannot carry -0, NaN or Infinity, so `show` spells numbers out first.
import { defineConfig, mutation } from "spacepress";
import { bitwise as untypedBitwise } from "./bitwise-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

/** Numbers as text, so -0, NaN and the infinities survive JSON. */
function show(values: number[]): string[] {
  return values.map((n) => (n === 0 && 1 / n < 0 ? "-0" : String(n)));
}

// ---- bitwise operators -------------------------------------------------------------------------

const EDGE = [0, -0, 1, -1, 5, -5, 2.9, -2.9, 255, 2147483647, 2147483648, -2147483648, -2147483649, 4294967295, 4294967296, 4294967297, 9007199254740991, -9007199254740991, 1e21, -1e21, 2 ** 53 + 2, NaN, Infinity, -Infinity];
const COUNTS = [0, 1, 4, 31, 32, 33, 63, 64, -1, -31, -32, -33, 2.7, NaN, 4294967296, 4294967297];

function bitwiseTableImpl(): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  out.not = show(EDGE.map((x) => ~x));
  out.doubleNot = show(EDGE.map((x) => ~~x));
  out.orZero = show(EDGE.map((x) => x | 0));
  out.ushrZero = show(EDGE.map((x) => x >>> 0));
  out.and = show(EDGE.map((x) => x & 0xff));
  out.andNegative = show(EDGE.map((x) => x & -256));
  out.or = show(EDGE.map((x) => x | 0x0f0f));
  out.xor = show(EDGE.map((x) => x ^ -1));
  out.xorSelf = show(EDGE.map((x) => x ^ x));
  for (const count of COUNTS) {
    out[`shl ${count}`] = show(EDGE.map((x) => x << count));
    out[`shr ${count}`] = show(EDGE.map((x) => x >> count));
    out[`ushr ${count}`] = show(EDGE.map((x) => x >>> count));
  }
  return out;
}

/** A 32-bit FNV-1a hash (shift-add multiply) and a djb2 hash: the shapes 0.8 apps use bitwise for. */
function hashes(codes: number[]): string[] {
  let fnv = 0x811c9dc5;
  let djb = 5381;
  for (const code of codes) {
    fnv ^= code;
    fnv += (fnv << 1) + (fnv << 4) + (fnv << 7) + (fnv << 8) + (fnv << 24);
    djb = ((djb << 5) + djb + code) | 0;
  }
  return [String(fnv >>> 0), String(djb), String(djb >>> 0)];
}

function bitwiseAssignmentsImpl() {
  let a = 0b1010;
  a &= 0b0110;
  let b = 1;
  b |= 6;
  let c = 0xff;
  c ^= 0x0f;
  let d = 3;
  d <<= 30;
  let e = -16;
  e >>= 2;
  let f = -16;
  f >>>= 28;
  const box = { flags: 1, list: [2] };
  box.flags |= 8;
  box.list[0] <<= 3;
  const flags = { read: 1 << 0, write: 1 << 1, exec: 1 << 2 };
  const mode = flags.read | flags.exec;
  return [a, b, c, d, e, f, box.flags, box.list[0], mode, mode & flags.write, (mode & flags.exec) === flags.exec ? 1 : 0];
}

/** Operand coercion: strings, booleans, null, and the order operands are read in. */
function bitwiseCoercionImpl(): string[] {
  const order: string[] = [];
  const read = (label: string, value: number) => { order.push(label); return value; };
  const values: unknown[] = ["12", " 0x10 ", "", "abc", true, false, null, [7], ["8"], [], undefined];
  const coerced = values.map((v) => (v as number) | 0);
  const shifted = values.map((v) => (v as number) >>> 1);
  const ordered = read("left", 6) & read("right", 3);
  return [...show(coerced), ...show(shifted), String(ordered), order.join(",")];
}

const BUILD_TIME_BITWISE = { table: bitwiseTableImpl(), hashes: hashes([90, 101, 114, 111, 8594, 0xd83d, 0xde00]), assignments: bitwiseAssignmentsImpl() };

export const bitwiseTable = run(() => bitwiseTableImpl());

export const bitwiseHashes = run(() => [hashes([]), hashes([104, 101, 108, 108, 111]), hashes([196, 114, 116, 0xffff, 0x10000, -1, 2.5])]);

export const bitwiseAssignments = run(() => bitwiseAssignmentsImpl());

export const bitwiseCoercion = run(() => bitwiseCoercionImpl());

export const bitwiseBuildTime = run(() => BUILD_TIME_BITWISE);

export const bitwiseUntyped = run(() => untypedBitwise(EDGE, COUNTS));

export default defineConfig({ name: "language-bitwise" });
