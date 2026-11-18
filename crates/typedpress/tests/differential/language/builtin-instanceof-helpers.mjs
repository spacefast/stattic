// Untyped JavaScript for tests/differential/language/builtin-instanceof.ts: `instanceof` with a
// built-in class on the right, through TypedPress's checked JavaScript dispatch. picomatch 4.0.7
// (`glob instanceof RegExp`) and files-sdk 3.1.2 (`pattern instanceof RegExp`) both branch on it.

export function instances() {
  const values = {
    regexp: /a+/g,
    built: new RegExp("b", "i"),
    map: new Map([["k", 1]]),
    set: new Set([1]),
    weakMap: new WeakMap(),
    weakSet: new WeakSet(),
    date: new Date(0),
    url: new URL("https://example.test/a?b=1"),
    params: new URLSearchParams("a=1"),
    controller: new AbortController(),
    object: {},
    array: [],
    string: "a+",
    number: 1,
    nothing: null,
  };
  values.signal = values.controller.signal;
  const table = {};
  for (const [key, value] of Object.entries(values)) {
    table[key] = [
      value instanceof RegExp,
      value instanceof Map,
      value instanceof Set,
      value instanceof WeakMap,
      value instanceof WeakSet,
      value instanceof Date,
      value instanceof URL,
      value instanceof URLSearchParams,
      value instanceof AbortController,
      value instanceof AbortSignal,
    ];
  }
  // picomatch's matchBase: a RegExp is used as is, anything else is compiled.
  const toRegex = (glob) => (glob instanceof RegExp ? glob : new RegExp(`^${glob}$`));
  return { table, matchBase: [toRegex(/x/).source, toRegex("y").source] };
}
