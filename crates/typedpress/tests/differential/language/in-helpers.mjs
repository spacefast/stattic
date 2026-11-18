// Untyped JavaScript for tests/differential/language/in.ts: these run through TypedPress's checked
// JavaScript dispatch rather than typed TypeScript compilation.

export function inUntyped(keys) {
  const record = { a: 1, b: undefined, 0: null };
  const list = [10, undefined, 30];
  const nested = { inner: { deep: true } };
  const results = {
    record: keys.map((key) => key in record),
    list: keys.map((key) => key in list),
    numbers: [0 in list, 2 in list, 3 in list, -1 in list, 1.5 in list],
    functionMetadata: ["arguments" in (() => 1), "caller" in function () {}, "call" in (() => 1), "name" in (() => 1)],
    collections: ["size" in new Map(), "get" in new Map(), "add" in new Map(), "size" in new Set(), "add" in new Set(), "get" in new Set()],
    nested: ["inner" in nested, "deep" in nested, "deep" in nested.inner],
    primitives: ["text", 5, true, null, undefined].map((value) => {
      try { return String("x" in value); } catch (error) { return `${error.name}: ${error.message}`; }
    }),
  };
  record.c = 3;
  results.afterWrite = ["c" in record, "d" in record];
  return results;
}
