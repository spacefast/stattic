// Untyped JavaScript for tests/differential/language/object-reflect.ts: Object.assign,
// Reflect.ownKeys and Object.prototype.toString.call through TypedPress's checked JavaScript dispatch.

const BRAND = Symbol.for("object-reflect.brand");

export function assigns() {
  const target = { b: 1, a: 2 };
  const returned = Object.assign(target, { c: 3, a: 9 }, null, undefined, { d: 4 });
  const merged = Object.assign({}, { x: 1 }, { y: 2 }, { x: 3 });
  const numbered = Object.assign({ z: 0 }, { 2: "two", 1: "one", k: "k" });
  const withSymbol = Object.assign({}, { [BRAND]: true, plain: 1 });
  const fromArray = Object.assign({}, ["p", "q"]);
  const intoArray = Object.assign([1, 2], { 1: "b", extra: "e" });
  const copied = { nested: { deep: 1 } };
  const shallow = Object.assign({}, copied);
  shallow.nested.deep = 2;
  let error;
  try {
    Object.assign(null, { a: 1 });
  } catch (e) {
    error = e.name;
  }
  return {
    returnsTarget: returned === target,
    target,
    keyOrder: Object.keys(target),
    merged,
    numbered: Object.keys(numbered),
    withSymbol: [withSymbol[BRAND], withSymbol.plain],
    fromArray,
    intoArray: [intoArray[0], intoArray[1], intoArray.extra, intoArray.length],
    shallowShares: copied.nested.deep,
    nullTarget: error,
  };
}

export function ownKeys() {
  const o = { b: 1, 2: "two", a: 2, 1: "one" };
  o[BRAND] = "brand";
  const plugin = { list: () => 1, get: () => 2 };
  // files-sdk's plugin surface check: every own key of the surface, strings then symbols.
  const seen = [];
  for (const key of Reflect.ownKeys(plugin)) {
    if (key === "then") throw new Error("then");
    seen.push(String(key));
  }
  return {
    object: Reflect.ownKeys(o).map((k) => (typeof k === "symbol" ? k.description : k)),
    array: Reflect.ownKeys(["x", "y"]),
    empty: Reflect.ownKeys({}),
    plugin: seen,
  };
}

export function tags() {
  const values = [/x/, [], {}, null, undefined, "s", 1, true, new Map(), new Set(), new Date(0), () => 1, new Error("e"), Symbol("t")];
  // safe-regex2's check, exactly: Object.prototype.toString.call(x) === '[object RegExp]'
  const isRegExp = (x) => Object.prototype.toString.call(x) === "[object RegExp]";
  return { tags: values.map((v) => Object.prototype.toString.call(v)), isRegExp: [isRegExp(/a/), isRegExp("a"), isRegExp({})] };
}
