// Untyped JavaScript for tests/differential/language/optional-calls.ts: optional calls run
// through TypedPress's checked JavaScript dispatch.

export function optionalCalls() {
  const log = [];
  const side = (tag, value) => {
    log.push(tag);
    return value;
  };
  const o = {
    f: (x) => `f${x}`,
    n: null,
    u: undefined,
    count: 0,
    self() {
      return this.count === 0;
    },
    inc() {
      this.count += 1;
      return this.count;
    },
  };
  const fn = (a) => a * 2;
  const nul = null;
  let und;
  const results = {
    // A plain callee that is null or undefined skips the call and its arguments.
    plain: [fn?.(3), nul?.(side("arg-null", 1)), und?.(side("arg-undef", 1))],
    // A member callee is read once; a missing or nullish method short-circuits.
    member: [o.f?.(1), o.n?.(side("arg-n", 1)), o.u?.(side("arg-u", 1)), o.missing?.(side("arg-m", 1))],
    // The receiver stays bound, so methods see `this`.
    thisKept: [o.self?.(), o.inc?.(), o.inc?.(), o.count],
    // Computed keys are evaluated once, before the null check on the callee.
    index: [o["f"]?.(2), o[side("key", "n")]?.(side("arg-ix", 1))],
    // Optional member steps followed by an optional call, and a plain continuation after a call.
    chained: [o?.f?.(4), nul?.x?.(), o.f?.(5).length],
    // files-sdk's shapes: `onProgress?.({ ... })` with and without a callback, `wait.cleanup?.()`.
    callback: [((onProgress) => onProgress?.({ done: 1 }))((event) => event.done), ((onProgress) => onProgress?.({ done: 1 }))(undefined)],
    cleanup: (() => {
      const wait = { cleanup: () => side("cleaned", true) };
      wait.cleanup?.();
      const runtime = {};
      runtime.cleanup?.();
      return "ok";
    })(),
    // Built-in and class methods through `?.()`.
    builtins: ["abc".toUpperCase?.(), [3, 1, 2].includes?.(2), new Map([["k", 1]]).get?.("k")],
  };
  try {
    o.count?.();
    results.notAFunction = "no error";
  } catch (error) {
    results.notAFunction = error.name;
  }
  results.log = [...log];
  return results;
}

export class Counter {
  constructor() {
    this.n = 0;
  }
  bump() {
    this.n += 1;
    return this.n;
  }
}

export function classMethods() {
  const c = new Counter();
  const none = null;
  return [c.bump?.(), c.bump?.(), c.missing?.(), none?.bump?.(), c.n];
}
