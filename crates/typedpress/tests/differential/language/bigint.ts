// BigInt, which live Zero 0.8 apps use (first#138 census) for exact large ids, money in minor
// units and 64-bit hashes. Every action runs under Node and as compiled PHP; the JSON results must
// be byte-identical. BigInts can't be JSON-serialized, so results are spelled out as strings.
// `bigint-helpers.mjs` runs the same operations untyped. Exact arithmetic on random operands is
// also checked against Node in tests/php/bigint_test.php.
import { action, defineConfig } from "typedpress";
import { bigintUntyped as bigintUntypedImpl } from "./bigint-helpers.mjs";

const run = (handler: () => unknown) => action({ input: {}, handler });

function arithmetic(): string[] {
  const a = 123456789012345678901234567890n;
  const b = -987654321n;
  return [
    String(a + b), String(a - b), String(a * b), String(a / b), String(a % b),
    String(-7n / 2n), String(-7n % 2n), String(7n % -2n), String(2n ** 100n), String((-2n) ** 63n), String(0n ** 0n),
    String(-a), String(-0n), String(9007199254740993n + 1n),
  ];
}

function bits(): string[] {
  const x = 0xff00ff00ff00ff00ffn;
  return [
    String(x & 0xffffn), String(x | 1n), String(x ^ -1n), String(~x), String(~0n), String(-5n & 3n), String(-5n | 3n), String(-5n ^ 3n),
    String(1n << 100n), String(-1n >> 1n), String(-9n >> 2n), String(x >> 64n), String(5n << -1n), String(1n << 0n),
    String(BigInt.asIntN(8, 255n)), String(BigInt.asUintN(8, -1n)), String(BigInt.asIntN(64, 2n ** 63n)), String(BigInt.asUintN(0, 5n)),
  ];
}

function compare(): boolean[] {
  return [
    1n < 2n, 2n > 1.5, 2n < 2.5, 2n === 2n, (2n as unknown) === 2, 2n == (2 as unknown as bigint), 2n == ("2" as unknown as bigint),
    (1n as unknown as number) < NaN, (1n as unknown as number) < Infinity, 9007199254740993n > 9007199254740992, -0n === 0n,
  ];
}

function convert(): string[] {
  const id = BigInt("9007199254740993");
  return [
    String(BigInt(42)), String(BigInt("0x1f")), String(BigInt(" -12 ")), String(BigInt("")), String(BigInt(true)), String(BigInt(2 ** 60)),
    String(Number(id)), String(Number(-(2n ** 1100n))), id.toString(16), (-255n).toString(2), (35n).toString(36), `${id}`, typeof id, String(Boolean(0n)), String(!!1n),
  ];
}

function errors(): string[] {
  const attempt = (label: string, f: () => unknown) => { try { return `${label}: ${String(f())}`; } catch (error) { const e = error as Error; return `${label}: ${e.name}: ${e.message}`; } };
  return [
    attempt("mix", () => (1n as unknown as number) + 1),
    attempt("divide by zero", () => 1n / 0n),
    // V8 reworded this message between Node 22 builds, so only the error's name is compared.
    attempt("negative exponent", () => { try { return 2n ** -1n; } catch (error) { throw new Error((error as Error).name); } }),
    attempt("fraction", () => BigInt(1.5)),
    attempt("bad text", () => BigInt("1e3")),
    attempt("json", () => JSON.stringify({ id: 1n })),
    attempt("unsigned shift", () => (8n as unknown as number) >>> 1),
    attempt("math", () => Math.max(1n as unknown as number)),
  ];
}

/** `0n` is falsy, so `b || fallback` keeps both types; a stored big integer converts as its double. */
function edges(): string[] {
  const first = 0n as bigint;
  const fallback = first || "fallback";
  const big = 9007199254740993 as number;
  return [String(fallback), typeof fallback, String(BigInt(big)), (255n).toString(undefined)];
}

/** 0.8 apps keep exact ids and cents as BigInt and send them as strings. */
function money(): string[] {
  const prices = [1999n, 25000n, 7n];
  let total = 0n;
  for (const cents of prices) total += cents * 3n;
  const tax = (total * 825n) / 10000n;
  return [String(total), String(tax), `${total / 100n}.${String(total % 100n).padStart(2, "0")}`];
}

export const bigintArithmetic = run(() => arithmetic());

export const bigintBits = run(() => bits());

export const bigintCompare = run(() => compare());

export const bigintConvert = run(() => convert());

export const bigintErrors = run(() => errors());

export const bigintMoney = run(() => money());

export const bigintEdges = run(() => edges());

export const bigintUntyped = run(() => bigintUntypedImpl());

export default defineConfig({ name: "language-bigint" });
