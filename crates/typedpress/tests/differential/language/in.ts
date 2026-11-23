// The `in` operator: own and inherited
// properties, arrays, arguments, class instances and statics, built-in values, union narrowing,
// and the TypeError for a primitive right side. Every action runs under Node and as compiled PHP;
// the JSON results must be byte-identical. `in-helpers.mjs` runs the same checks untyped.
import { defineConfig, mutation as server } from "typedpress";
import { inUntyped as inUntypedImpl } from "./in-helpers.mjs";

const run = (handler: () => unknown) => server({}, handler);

const KEYS = ["a", "b", "missing", "0", "1", "2", "-1", "1.5", "01", "length", "toString", "valueOf", "constructor", "hasOwnProperty", "__proto__", "push", "map", "undefined", "null", "4294967295"];

function own(): boolean[][] {
  const record: Record<string, unknown> = { a: 1, b: undefined, "0": null, undefined: 0 };
  const list = [10, undefined, 30];
  return [KEYS.map((key) => key in record), KEYS.map((key) => key in list)];
}

class InBase {
  label = "base";
  greet(): string { return this.label; }
  static kind = "base";
  static make(): InBase { return new InBase(); }
}
class InChild extends InBase {
  extra = 1;
  shout(): string { return this.greet().toUpperCase(); }
}

function classes(): boolean[] {
  const child = new InChild();
  return [
    "label" in child, "extra" in child, "greet" in child, "shout" in child, "toString" in child, "missing" in child,
    "kind" in InChild, "make" in InChild, "name" in InChild, "length" in InChild, "prototype" in InChild, "call" in InChild, "missing" in InChild,
  ];
}

type Shape = { kind: "circle"; radius: number } | { kind: "square"; side: number } | { kind: "dot"; radius?: number };

function area(shape: Shape): number {
  if ("side" in shape) return shape.side * shape.side;
  if (!("radius" in shape)) return 0;
  return shape.radius === undefined ? -1 : Math.round(shape.radius * shape.radius * 3);
}

/** TypeScript narrows `unknown` by `"key" in`; 0.8 apps use it on parsed JSON. */
function fromUnknown(value: unknown): string {
  if (typeof value === "object" && value !== null && "error" in value) return `error ${String(value.error)}`;
  if (typeof value === "object" && value !== null && "length" in value) return `length ${String(value.length)}`;
  return "plain";
}

/** Keys convert through ToPropertyKey, after the key expression runs and before the object is read. */
function keys(): unknown[] {
  const order: string[] = [];
  const record: Record<string, number> = { "1": 1, "true": 2, "null": 3, "1,2": 4, "-0": 5, "0": 6 };
  const key = (value: unknown) => { order.push("key"); return value as string; };
  const target = () => { order.push("object"); return record; };
  return [1 in record, (true as unknown as string) in record, (null as unknown as string) in record, ([1, 2] as unknown as string) in record, -0 in record, key(0) in target(), order];
}

function builtins(): boolean[] {
  const pattern = /a/g;
  const error = new TypeError("boom");
  const fn = (x: number) => x;
  return ["lastIndex" in pattern, "source" in pattern, "test" in pattern, "missing" in pattern, "message" in error, "name" in error, "toString" in error, "cause" in error,
    "call" in fn, "length" in fn, "name" in fn, "bind" in fn, "missing" in fn, "PI" in Math, "max" in Math, "missing" in Math];
}

function primitives(): string[] {
  const values: unknown[] = ["text", 5, true, null, undefined];
  return values.map((value) => {
    try { return String("length" in (value as object)); } catch (error) { const e = error as Error; return `${e.name}: ${e.message}`; }
  });
}

function args(first: string, ...rest: string[]): boolean[] {
  return ["0" in arguments, "1" in arguments, "2" in arguments, "length" in arguments, "callee" in arguments, "push" in arguments, "toString" in arguments];
}

function mutation(): boolean[] {
  const record: Record<string, number> = {};
  const before = "k" in record;
  record.k = 1;
  const list = [1, 2];
  list.pop();
  return [before, "k" in record, "1" in list, "0" in list];
}

const BUILD_TIME_IN = { own: own(), keys: keys(), shapes: [area({ kind: "circle", radius: 2 }), area({ kind: "square", side: 3 }), area({ kind: "dot" })] };

export const inOwn = run(() => own());

export const inClasses = run(() => classes());

export const inNarrowing = run(() => [area({ kind: "circle", radius: 2 }), area({ kind: "square", side: 3 }), area({ kind: "dot" }), area({ kind: "dot", radius: 1 })]);

export const inKeys = run(() => keys());

export const inUnknown = run(() => [fromUnknown(JSON.parse('{"error":"bad"}')), fromUnknown(JSON.parse("[1,2]")), fromUnknown(JSON.parse('{"ok":1}')), fromUnknown(JSON.parse('"text"')), fromUnknown(JSON.parse("null"))]);

export const inBuiltins = run(() => builtins());

export const inPrimitives = run(() => primitives());

export const inArguments = run(() => args("one", "two"));

export const inMutation = run(() => mutation());

export const inBuildTime = run(() => BUILD_TIME_IN);

export const inUntyped = run(() => inUntypedImpl(KEYS));

export default defineConfig({ name: "language-in" });
