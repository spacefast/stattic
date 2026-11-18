// Untyped JavaScript for tests/differential/language/control-flow.ts: these run through TypedPress's
// checked JavaScript dispatch rather than typed TypeScript compilation.

export function controlFlowUntyped() {
  const trace = [];
  let n = 0;
  do {
    n++;
    if (n % 2) continue;
    trace.push(n);
  } while (n < 6);
  outer: for (const key in { a: 1, b: 2, c: 3 }) {
    for (const ch of "xyz") {
      if (ch === "y" && key === "b") continue outer;
      if (key === "c") break outer;
      trace.push(key + ch);
    }
  }
  let m = 10;
  done: {
    while (true) {
      m--;
      if (m < 7) break done;
    }
  }
  trace.push(m);
  return trace;
}
