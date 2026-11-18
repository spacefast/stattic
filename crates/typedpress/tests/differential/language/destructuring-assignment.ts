// Destructuring assignment (not declaration), which live Zero 0.8 apps use (first#138 census):
// swaps, reassigning several locals from a helper's tuple, and pulling fields into existing
// variables or object fields. Every action runs under Node and as compiled PHP; the JSON results
// must be byte-identical. `destructuring-assignment-helpers.mjs` runs the same shapes untyped.
import { action, defineConfig } from "typedpress";
import { destructuringUntyped as destructuringUntypedImpl } from "./destructuring-assignment-helpers.mjs";

const run = (handler: () => unknown) => action({ input: {}, handler });

function swaps(): number[] {
  let a = 1;
  let b = 2;
  let c = 3;
  [a, b] = [b, a];
  [a, b, c] = [c, a, b];
  return [a, b, c];
}

function bounds(values: number[]): [number, number] {
  let low = Infinity;
  let high = -Infinity;
  for (const v of values) {
    [low, high] = [Math.min(low, v), Math.max(high, v)];
  }
  return [low, high];
}

function arrays(): unknown[] {
  let first: number | undefined = 0;
  let third: number | undefined = 0;
  let rest: number[] = [];
  [first, , third, ...rest] = [1, 2, 3, 4, 5];
  let missing: number | undefined = 9;
  let fallback = 0;
  [missing, fallback = 7] = [];
  let nested: string | undefined = "";
  let deep = 0;
  [[nested], { deep }] = [["n"], { deep: 4 }] as [string[], { deep: number }];
  return [first, third, rest, missing, fallback, nested, deep];
}

type Profile = { name: string; city?: string; tags: string[]; extra: number };

function objects(profile: Profile): unknown[] {
  let name = "";
  let town = "";
  let tags: string[] = [];
  let others: { city?: string; tags: string[]; extra: number } = { tags: [], extra: 0 };
  ({ name, city: town = "unknown", tags } = profile);
  ({ name, ...others } = profile);
  const target: { label: string; list: string[] } = { label: "", list: [] };
  ({ name: target.label, tags: target.list } = profile);
  const key = "extra";
  let picked = 0;
  ({ [key]: picked } = profile);
  return [name, town, tags, others, target, picked];
}

/** Array patterns read the array live, so an earlier target write is seen by later reads. */
function live(): unknown[] {
  const a: (number | undefined)[] = [1, 2];
  [a[1], a[0]] = a;
  const b: (number | undefined)[] = [1, 2, 3];
  let rest: (number | undefined)[] = [];
  [b[2], ...rest] = b;
  let picked = 0;
  let others: { b: number } = { b: 0 };
  ({ ["a"]: picked, ...others } = { a: 1, b: 2 });
  const key = "a";
  let typedKey = 0;
  let typedRest: { b: number } = { b: 0 };
  ({ [key]: typedKey, ...typedRest } = { a: 3, b: 4 });
  return [a, b, rest, picked, others, typedKey, typedRest];
}

/** The assignment's value is the right side, whatever the pattern takes from it. */
function result(): unknown[] {
  let a = 0;
  let b = 0;
  const value = ([a, b] = [5, 6, 7]);
  let n = "";
  const object = ({ n } = { n: "x", m: "y" });
  return [value, a, b, object, n];
}

/** JavaScript resolves each target before reading its value, and runs defaults only for undefined. */
function order(): string[] {
  const log: string[] = [];
  const box: Record<string, string> = {};
  const key = (k: string) => { log.push(`key ${k}`); return k; };
  const target = () => { log.push("target"); return box; };
  const fallback = (v: string) => { log.push(`default ${v}`); return v; };
  [target()[key("a")], target()[key("b")] = fallback("B"), target()[key("c")] = fallback("C")] = ["x", undefined, null as unknown as string];
  log.push(JSON.stringify(box));
  return log;
}

function throws(): string[] {
  const attempt = (f: () => void) => { try { f(); return "ok"; } catch (error) { return (error as Error).name; } };
  let a = 0;
  return [
    attempt(() => { ({ a } = null as unknown as { a: number }); }),
    attempt(() => { ({ a } = undefined as unknown as { a: number }); }),
    String(a),
  ];
}

const BUILD_TIME_DESTRUCTURING = { swaps: swaps(), bounds: bounds([3, 1, 4]), arrays: arrays(), result: result() };

export const destructuringSwaps = run(() => swaps());

export const destructuringBounds = run(() => [bounds([3, 1, 4, 1, 5]), bounds([])]);

export const destructuringArrays = run(() => arrays());

export const destructuringObjects = run(() => [objects({ name: "Ada", tags: ["a"], extra: 1 }), objects({ name: "Bo", city: "Oslo", tags: [], extra: 2 })]);

export const destructuringResult = run(() => result());

export const destructuringLive = run(() => live());

export const destructuringOrder = run(() => order());

export const destructuringThrows = run(() => throws());

export const destructuringBuildTime = run(() => BUILD_TIME_DESTRUCTURING);

export const destructuringUntyped = run(() => destructuringUntypedImpl());

export default defineConfig({ name: "language-destructuring-assignment" });
