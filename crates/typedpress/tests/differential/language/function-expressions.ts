// Named function expressions and recursive local functions, which esbuild bundles of live 0.8 apps
// contain. Every action runs under Node and as compiled PHP; the JSON results must be
// byte-identical.
import { defineConfig, mutation } from "typedpress";
import { fastpath, hoistedVar, named, recursiveLocal, selfBound, selfBoundReassigned, selfBoundSuspending } from "./function-expressions-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

function typedRecursion(): number[] {
  function fact(n: number): number { return n <= 1 ? 1 : n * fact(n - 1); }
  const even = function isEven(n: number): boolean { return n === 0 ? true : !isEven(n - 1); };
  const squares = [1, 2, 3].map(function square(x) { return x * x; });
  return [fact(6), even(4) ? 1 : 0, even(7) ? 1 : 0, ...squares];
}

// A const arrow or function expression naming its own binding, typed by its own signature or by
// the declaration's function type.
function typedSelfBound(): unknown[] {
  const fact = (n: number): number => (n <= 1 ? 1 : n * fact(n - 1));
  const fib = function (n: number): number { return n < 2 ? n : fib(n - 1) + fib(n - 2); };
  const join = (parts: string[]): string => (parts.length === 0 ? "" : parts[0] + "/" + join(parts.slice(1)));
  const shadow = (shadow: number): number => shadow + 1;
  const depth = (n: number): number => {
    const step = (m: number): number => (m <= 0 ? 0 : 1 + depth(m - 1));
    return step(n);
  };
  const perIteration: ((n: number) => number)[] = [];
  for (const i of [0, 1, 2]) {
    const again = (n: number): number => (n <= 0 ? i : again(n - 1));
    perIteration.push(again);
  }
  return [fact(6), fib(12), join(["a", "b", "c"]), shadow(1), depth(3), perIteration.map((f) => f(2))];
}

export const functionNamed = run(() => named());

export const functionRecursiveLocal = run(() => recursiveLocal());

export const functionTyped = run(() => typedRecursion());

export const functionHoistedVar = run(() => hoistedVar());

export const functionSelfBound = run(() => selfBound());

export const functionSelfBoundReassigned = run(() => selfBoundReassigned());

export const functionSelfBoundTyped = run(() => typedSelfBound());

export const functionSelfBoundSuspending = mutation({}, async () => await selfBoundSuspending());

export const functionFastpath = run(() => fastpath("*.ts"));

export default defineConfig({ name: "language-function-expressions" });
