// Array.prototype.sort and toSorted, which files-sdk 3.1.2 uses with no comparator
// (`Object.keys(PROVIDERS).toSorted()`), with a three-way string comparator, with
// `a.key < b.key ? -1 : 1` (never 0) and with numeric key differences. Default sorting compares
// UTF-16 code units of each element's string form, so numbers sort lexicographically and astral
// characters sort before U+E000..U+FFFF. Undefined goes last and never reaches the comparator.
// Comparators may be inconsistent or effectful: TypedPress runs V8's TimSort step for step, so the
// comparator sees Node's pairs in Node's order. The checksum cases record every call.
// Every action runs under Node and as compiled PHP; module-level constants run in the build-time
// evaluator. `sort-helpers.mjs` runs the same checks untyped.
import { action, defineConfig } from "typedpress";
import { sortObjects as sortObjectsImpl, sortUntyped as sortUntypedImpl, sortUntypedSequences as sortUntypedSequencesImpl } from "./sort-helpers.mjs";

const run = (handler: () => unknown) => action({ input: {}, handler });

const STRINGS = ["b", "a", "B", "é", "e", "z", "🙂", "\uff61", "\ue000", "ä", "A", "aa", "", "a🙂", "a\uffff", "Z", "10", "9"];

function defaults(): unknown[] {
  const numbers = [10, 9, 1, -1, 0.5, 100, 2, -0.25, 1e21, 3];
  const mixed: unknown[] = [true, null, 3, "3", "null", false, undefined, "", 10, "true", undefined, -1];
  const strings = [...STRINGS];
  const same = strings.sort();
  return [same === strings, strings, numbers.toSorted(), numbers, mixed.toSorted(), [[2], [1, 0], [1], []].toSorted().map((x) => x.join("+"))];
}

type Entry = { key: string; partNumber: number; index: number };

function entries(): Entry[] {
  const keys = ["m", "b", "z", "b", "a", "m", "é", "B", "a", "🙂"];
  return keys.map((key, index) => ({ key, partNumber: (index * 7) % 5, index }));
}

function compareKeys(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

function comparators(): unknown[] {
  const ids = (list: Entry[]) => list.map((e) => e.index);
  const list = entries();
  return [
    ids(entries().sort((a, b) => (a.key < b.key ? -1 : 1))),
    ids(entries().sort((a, b) => a.partNumber - b.partNumber)),
    ids(entries().sort((a, b) => b.partNumber - a.partNumber)),
    ids(entries().toSorted((a, b) => compareKeys(a.key, b.key))),
    ids(entries().sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : 0))),
    ids(list.toSorted(() => NaN)),
    ids(list.toSorted(() => 0)),
    ids(list.toSorted(() => -1)),
    ids(list.toSorted(() => 1)),
    ids(list),
    [3, 1, 2, Infinity, -Infinity, 0, -0].toSorted((a, b) => a - b).map((x) => (x === 0 && 1 / x < 0 ? "-0" : String(x))),
    [Infinity, 1, Infinity, -Infinity, 2].toSorted((a, b) => b - a),
    [undefined, 3, undefined, 1, 2].sort((a, b) => (a ?? 0) - (b ?? 0)),
  ];
}

/** Every comparator call, and the order, as Node's TimSort makes them. */
function calls(): unknown[] {
  const log: string[] = [];
  const result = [5, 1, 4, undefined, 2, 3, 1].sort((a, b) => { log.push(`${a}:${b}`); return (a ?? 0) - (b ?? 0); });
  return [log, result];
}

/** A comparator that throws leaves the array as it was: V8 sorts a copy and writes it back at the end. */
function throwing(): unknown[] {
  const thrown: number[] = [4, 3, 2, 1, 7, 0];
  const seen: string[] = [];
  let message = "";
  try {
    thrown.sort((a, b) => { seen.push(`${a}:${b}`); if (a === 2) throw new Error("stop"); return a - b; });
  } catch (error) {
    message = (error as Error).message;
  }
  return [thrown, seen, message];
}

/** A Park-Miller generator, exact in doubles, so Node and PHP see the same sequence. */
function generator(seed: number): () => number {
  let state = seed;
  return () => { state = (state * 48271) % 2147483647; return state; };
}

function patterns(length: number, seed: number): number[][] {
  const next = generator(seed);
  const random = Array.from({ length }, () => next() % 50);
  const runs = Array.from({ length }, (_, i) => (i < length / 2 ? i : i - length / 2));
  const descending = Array.from({ length }, (_, i) => length - i);
  const saw = Array.from({ length }, (_, i) => i % 37);
  const nearly = Array.from({ length }, (_, i) => i);
  for (let i = 0; i < length / 20; i++) {
    const a = next() % length;
    const b = next() % length;
    const t = nearly[a]!;
    nearly[a] = nearly[b]!;
    nearly[b] = t;
  }
  const blocks = Array.from({ length }, (_, i) => (Math.floor(i / 64) % 2 === 0 ? i : length * 2 - i));
  return [random, runs, descending, saw, nearly, blocks];
}

/** Sorts each pattern with a consistent comparator, an inconsistent one and a random one, recording call counts and checksums. */
function sequences(): number[][] {
  const out: number[][] = [];
  for (const length of [2, 7, 33, 65, 300, 1000]) {
    for (const items of patterns(length, length + 11)) {
      const random = generator(length * 3 + 1);
      let count = 0;
      let sum = 0;
      const record = (a: number, b: number) => { count++; sum = (sum * 31 + a * 7 + b) % 1000003; };
      const plain = items.toSorted((a, b) => { record(a, b); return a - b; });
      const flaky = items.toSorted((a, b) => { record(a, b); return count % 17 === 0 ? b - a : a - b; });
      const noisy = items.toSorted((a, b) => { record(a, b); return (random() % 3) - 1; });
      let order = 0;
      for (const list of [plain, flaky, noisy]) {
        for (const value of list) order = (order * 31 + value) % 1000003;
      }
      out.push([count, sum, order]);
    }
  }
  return out;
}

function stability(): unknown[] {
  const rows = Array.from({ length: 200 }, (_, index) => ({ k: (index * 13) % 7, index }));
  const byDifference = rows.toSorted((a, b) => a.k - b.k).map((r) => r.index);
  const byThreeWay = rows.toSorted((a, b) => (a.k === b.k ? 0 : a.k < b.k ? -1 : 1)).map((r) => r.index);
  return [byDifference, byThreeWay, byDifference.join() === byThreeWay.join()];
}

function mutation(): unknown[] {
  const original = [3, 1, 2];
  const copy = original.toSorted();
  const sorted = original.sort((a, b) => b - a);
  // A comparator that grows the array: sort writes the sorted snapshot over the first
  // slots and keeps what was appended; toSorted leaves the receiver alone.
  const grown = [3, 1, 2];
  grown.sort((a, b) => { if (grown.length < 5) grown.push(9); return a - b; });
  const source = [3, 1, 2];
  const fresh = source.toSorted((a, b) => { if (source.length < 5) source.push(9); return a - b; });
  return [copy, original, sorted === original, copy === original, grown, source, fresh];
}

type Compare = (a: string, b: string) => number;

/** Values typed as comparators that are not functions throw TypeError, as in Node; PHP callable names never run. */
function notFunctions(): string[] {
  const attempt = (compare: unknown) => {
    try { return JSON.stringify(["b", "c", "a"].sort(compare as Compare)); } catch (error) { return (error as Error).name; }
  };
  return ["strcmp", "max", 5, {}, [1]].map(attempt);
}

const BUILD_TIME_SORT = { defaults: defaults(), comparators: comparators(), calls: calls(), sequences: sequences(), stability: stability(), mutation: mutation(), untyped: sortUntypedImpl(), untypedSequences: sortUntypedSequencesImpl() };

export const sortDefaults = run(() => defaults());

export const sortComparators = run(() => comparators());

export const sortCalls = run(() => calls());

export const sortThrowing = run(() => throwing());

export const sortSequences = run(() => sequences());

export const sortStability = run(() => stability());

export const sortMutation = run(() => mutation());

export const sortNotFunctions = run(() => notFunctions());

export const sortBuildTime = run(() => BUILD_TIME_SORT);

export const sortUntyped = run(() => sortUntypedImpl());

export const sortUntypedSequences = run(() => sortUntypedSequencesImpl());

export const sortObjects = run(() => sortObjectsImpl());

export default defineConfig({ name: "language-sort" });
