// `this` outside classes: object-literal methods,
// borrowed methods, call/apply and arrows inside methods. Every action runs under Node and as
// compiled PHP; the JSON results must be byte-identical. `this-helpers.mjs` runs the untyped forms.
import { defineConfig, mutation } from "spacepress";
import * as thisModule from "./this-helpers.mjs";
import { detached, factory, mod, moduleReceivers, receivers, rich, richReceivers, store } from "./this-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

// A module-level literal is built once, while the config is evaluated. Its methods type `this` as
// the module object, its fields plus its sibling methods, as an inline literal does (first#205).
const plain = { first() { return this.second(); }, second() { return 1; } };
const api = {
  n: 2,
  first() { return this.second() + 1; },
  second() { return this.n * 10; },
  fact(k: number): number { return k ? this.n * this.fact(k - 1) : 1; },
  bump() { this.n += 1; return this.n; },
  later() { const read = () => this.n; return read(); },
  nested: { v: 7, read() { return this.v; } },
};
const annotated = { n: 4, half(this: { n: number }) { return this.n / 2; }, twice(this: void, x: number) { return x * 2; } };
// A method read through `this` and called without its object gets an undefined `this` and throws
// a catchable TypeError, as in Node. A field written `value as T` is typed `T` through `this`.
const detachable = {
  n: 1,
  f() { return this.n; },
  viaLocal() { const h = this.f; try { return h(); } catch { return "threw"; } },
  viaCall() { try { return this.f.call(null); } catch { return "threw"; } },
  getF() { return this.f; },
};
const declared = {
  v: null as string | null,
  x: 1 as number | string,
  set(s: string) { this.v = s; this.x = s.length; return [this.v, this.x]; },
};

function who(this: unknown): string {
  return typeof this;
}

function tag(this: unknown, x: number): unknown[] {
  return [typeof this, x];
}

function describe(this: { name: string; role: string }, prefix: string): string {
  return `${prefix}${this.name} (${this.role})`;
}

export const thisReceivers = run(() => receivers());

export const thisDetached = run(() => detached());

export const thisFactory = run(() => factory());

export const thisTypedLiteral = run(() => {
  const counter = {
    count: 1,
    label: "c",
    inc(by: number): number { this.count += by; return this.count; },
    describe(): string { return `${this.label}=${this.count}`; },
  };
  counter.inc(2);
  return [counter.inc(3), counter.describe()];
});

export const thisModuleObjects = run(() => [mod.who(), store.get(), thisModule.store.get(), thisModule.mod.who(), moduleReceivers()]);

export const thisRichFields = run(() => [rich.kind(), rich.size(), richReceivers()]);

export const thisDirectHelper = run(() => [who(), tag(1), tag.call("r", 2), who.apply(5, [])]);

export const thisSpreadLiteral = run(() => {
  const base = { count: 1 };
  const counter = { ...base, step: 2, inc(): number { this.count += this.step; return this.count; } };
  return [counter.inc(), counter.inc()];
});

export const thisTypedParameter = run(() => [describe.call({ name: "Ada", role: "admin" }, "Dr "), describe.apply({ name: "Bo", role: "editor" }, [""])]);

// A callback reading `this` keeps its argument positions; `this: void` alone takes no receiver.
export const thisCallbacks = run(() => {
  const api = { f(this: void, x: number): number { return x * 2; }, count: 1 };
  return [
    ["x", "yy"].map(function (value, i) { return [typeof this, value.length, i]; }),
    [1, 2, 3].reduce(function (acc, v) { void this; return acc + v; }, 0),
    api.count + api.f(2),
  ];
});

// A method may call a sibling defined after it, itself, or one whose type it needs first.
export const thisSiblingMethods = run(() => {
  const o = {
    n: 2,
    first() { return this.second() + 1; },
    second() { return this.n * 10; },
    fact(k: number): number { return k ? this.n * this.fact(k - 1) : 1; },
  };
  // A field typed unknown is still a field `this` can read.
  const parsed = { data: JSON.parse("[1]") as unknown, kind(): string { return Array.isArray(this.data) ? "array" : "other"; } };
  return [o.first(), o.fact(3), { ...o, n: 3 }.first(), parsed.kind()];
});

export const thisModuleLiteral = run(() => [
  plain.first(),
  api.first(),
  api.fact(3),
  api.bump(),
  api.first(),
  api.later(),
  api.nested.read(),
  annotated.half(),
  annotated.twice(3),
]);

export const thisModuleDetached = run(() => {
  let fromGetter: unknown;
  try { fromGetter = detachable.getF()(); } catch { fromGetter = "threw"; }
  return [detachable.viaLocal(), detachable.viaCall(), fromGetter, declared.set("abc"), declared.v];
});

export default defineConfig({ name: "language-this" });
