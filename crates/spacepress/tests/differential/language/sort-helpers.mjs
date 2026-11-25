// Untyped JavaScript for tests/differential/language/sort.ts: the files-sdk 3.1.2 sort shapes
// verbatim, run through SpacePress's checked JavaScript dispatch, plus comparator results that need
// ToNumber and default sorting of objects that convert themselves.

const PROVIDERS = { s3: 1, gcs: 2, azure: 3, r2: 4, b2: 5, "do-spaces": 6, Local: 7, memory: 8 };
const isProviderSlug = (key) => key !== "memory";
// Module top level: configuration evaluation runs these.
const PROVIDER_NAMES = Object.keys(PROVIDERS).filter(isProviderSlug).toSorted();
const ALL = Object.keys(PROVIDERS).toSorted();

function compareKeys(a, b) {
  return a < b ? -1 : a > b ? 1 : 0;
}

function stableJson(value) {
  if (value === null || typeof value !== "object") return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map(stableJson).join(",")}]`;
  return `{${Object.keys(value).toSorted().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(",")}}`;
}

function list(store, prefix) {
  return [...store.entries()].filter(([key]) => key.startsWith(prefix)).toSorted(([a], [b]) => compareKeys(a, b)).map(([key]) => key);
}

export function sortUntyped() {
  const out = [{ key: "b" }, { key: "a" }, { key: "c" }, { key: "a" }, { key: "B" }].map((entry, index) => ({ ...entry, index }));
  out.sort((a, b) => a.key < b.key ? -1 : 1);
  const results = [{ partNumber: 3, etag: "c" }, { partNumber: 1, etag: "a" }, { partNumber: 2, etag: "b" }, { partNumber: 1, etag: "a2" }];
  results.sort((a, b) => a.partNumber - b.partNumber);
  const errors = [{ index: 4 }, { index: 0 }, { index: 2 }];
  errors.sort((a, b) => a.index - b.index);
  return {
    providers: PROVIDER_NAMES,
    all: ALL,
    runtimeAll: Object.keys(PROVIDERS).toSorted(),
    out: out.map((entry) => `${entry.key}${entry.index}`),
    results: results.map((r) => r.etag),
    errors: errors.map((e) => e.index),
    // Comparator results go through ToNumber: strings, booleans and null.
    coerced: [
      [3, 1, 2].sort((a, b) => String(a - b)),
      [3, 1, 2].sort((a, b) => a > b),
      [3, 1, 2].sort((a, b) => (a < b ? null : 1)),
      [3, 1, 2].sort((a, b) => (a < b ? "-1" : "x")),
    ],
    holesAndUndefined: [undefined, "b", undefined, "a"].sort(),
    sameArray: (() => { const a = [2, 1]; return a.sort() === a; })(),
  };
}

/**
 * Objects that convert themselves, which only handlers run (configuration evaluation refuses
 * custom coercion): default sorting converts at every comparison, and a comparator result
 * converts through valueOf.
 */
export function sortObjects() {
  const tagged = { toString() { return "m"; } };
  const ordered = [{ toString: () => "b" }, tagged, { toString: () => "a" }].sort().map((x) => String(x));
  let conversions = 0;
  const counted = [3, 1, 2, 5, 4].map((n) => ({ toString() { conversions++; return String(n); } })).sort().map((x) => String(x));
  const valued = [3, 1, 2].sort((a, b) => (a < b ? { valueOf: () => -1 } : { valueOf: () => 1 }));
  const store = new Map([["dir/b", 1], ["dir/a", 2], ["other", 3], ["dir/é", 4], ["dir/B", 5], ["dir/🙂", 6], ["dir/\uffff", 7]]);
  const attempt = (f) => { try { return f(); } catch (error) { return error.name; } };
  // A comparator that shrinks the array: sort still writes back every sorted element.
  const shrunk = [3, 1, 2, 4];
  shrunk.sort((a, b) => { if (shrunk.length > 2) shrunk.pop(); return a - b; });
  return {
    ordered, counted, conversions, valued, shrunk,
    // A generator comparator returns a generator object: ToNumber gives NaN, so no element moves.
    generator: [[3, 1, 2].sort(function* (a, b) { return a - b; }), [{ k: 2 }, { k: 1 }].toSorted(function* (a, b) { return a.k - b.k; }).map((x) => x.k)],
    json: stableJson({ z: 1, a: [{ y: 2, b: 1 }], m: { k: null, c: "x" }, "🙂": 1, "\ue000": 2 }),
    list: list(store, "dir/"),
    plainObjects: [{ k: 1 }, [2], { k: 0 }].sort().map((x) => JSON.stringify(x)),
    notAFunction: [attempt(() => [2, 1].sort(null)), attempt(() => [2, 1].sort("x")), attempt(() => [2, 1].toSorted({})), attempt(() => [1].sort(5))],
  };
}

/** Comparator calls recorded under the files-sdk shapes and an inconsistent comparator. */
export function sortUntypedSequences() {
  const log = [];
  const keys = ["q", "c", "x", "c", "a", "m", "b", "z", "a", "y", "k", "d", "c", "n"];
  const rows = keys.map((key, index) => ({ key, index }));
  const sorted = rows.toSorted((a, b) => { log.push(`${a.index}/${b.index}`); return a.key < b.key ? -1 : 1; });
  let calls = 0;
  const effects = Array.from({ length: 80 }, (_, i) => (i * 37) % 23).sort((a, b) => { calls++; return calls % 5 === 0 ? 1 : a - b; });
  return [log, sorted.map((r) => r.index), calls, effects];
}
