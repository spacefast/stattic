// Untyped JavaScript for tests/differential/language/this.ts: function-style objects, borrowed
// methods and call/apply, the shapes live 0.8 bundles use `this` outside classes for.

export function receivers() {
  const counter = { count: 1, inc(by) { this.count += by; return this; }, get() { return this.count; } };
  function who() { return this; }
  const greet = function (greeting) { return `${greeting}, ${this.name}`; };
  const ada = { name: "Ada", greet };
  const arrows = { n: 7, read() { const inner = () => this.n; return inner(); } };
  return [
    counter.inc(2).inc(3).get(),
    typeof who(),
    who.call(5),
    who.apply("s", []),
    greet.call({ name: "Bo" }, "Hi"),
    greet.apply({ name: "Cy" }, ["Yo"]),
    ada.greet("Hey"),
    ada["greet"]("Hello"),
    arrows.read(),
    typeof who.call(undefined),
    who.call(null),
  ];
}

export function detached() {
  const box = { value: 1, read() { return this === undefined ? "undefined this" : this.value; } };
  const read = box.read;
  return [box.read(), read()];
}

export function factory() {
  function makeStack() {
    return {
      items: [],
      push(item) { this.items.push(item); return this; },
      pop() { return this.items.pop(); },
      size() { return this.items.length; },
    };
  }
  const stack = makeStack().push("a").push("b");
  return [stack.pop(), stack.items, stack.size()];
}

// Module-level objects whose methods read `this`: the shape 0.8 bundles use most.
function whoModule() { return typeof this; }
export const mod = { who: whoModule };
export const store = { count: 2, get() { return this.count; } };
export const counter = { count: 0, inc() { this.count += 1; return this.count; }, peek() { return this.count; } };
// Siblings through `this`: one defined later, and one calling itself.
export const chain = {
  n: 3,
  first() { return this.second() + 1; },
  second() { return this.n * 10; },
  fact(k) { return k ? this.n * this.fact(k - 1) : 1; },
};
// Every kind of field stays readable through `this`: regexes, nested objects with methods, Maps.
export const rich = {
  re: /ab+/g,
  cfg: { v: 7, bump() { this.v += 1; return this.v; } },
  m: new Map([[1, 2]]),
  tags: ["a", "b"],
  kind() { return typeof this.re; },
  read() { return this.cfg.v; },
  size() { return this.m.size; },
  test() { return this.re.test("xabbb"); },
  first() { return this.tags[0]; },
};

export function moduleReceivers() {
  counter.inc();
  return [mod.who(), store.get(), counter.inc(), counter.peek(), counter.count, chain.first(), chain.fact(2)];
}

export function richReceivers() {
  const bumped = rich.cfg.bump();
  return [rich.kind(), bumped, rich.read(), rich.cfg.v, rich.size(), rich.test(), rich.first()];
}
