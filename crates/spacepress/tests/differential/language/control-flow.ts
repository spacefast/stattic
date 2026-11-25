// `do…while` and labeled statements: retry
// loops that run at least once, and `break outer` / `continue outer` across nested loops.
// Every action runs under Node and as compiled PHP; the JSON results must be byte-identical.
// `control-flow-helpers.mjs` runs the same shapes untyped.
import { defineConfig, mutation } from "spacepress";
import { controlFlowUntyped } from "./control-flow-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

function doWhile(): unknown[] {
  const trace: string[] = [];
  let once = 0;
  do {
    once++;
  } while (false);
  let n = 0;
  do {
    n++;
    if (n === 2) continue;
    trace.push(`n${n}`);
    if (n === 4) break;
  } while (n < 10);
  let tests = 0;
  let k = 0;
  do {
    k += 3;
  } while ((tests++, k < 7));
  // A retry loop: the body runs before the condition is read.
  const attempts: number[] = [];
  let attempt = 0;
  let ok = false;
  do {
    attempt++;
    attempts.push(attempt);
    ok = attempt >= 3;
  } while (!ok && attempt < 5);
  return [once, n, trace, k, tests, attempts, ok];
}

/** The first pair with a given sum: `break outer` leaves both loops. */
function findPair(values: number[], sum: number): number[] {
  let found: number[] = [];
  outer: for (let i = 0; i < values.length; i++) {
    for (let j = i + 1; j < values.length; j++) {
      if ((values[i] ?? 0) + (values[j] ?? 0) === sum) {
        found = [i, j];
        break outer;
      }
    }
  }
  return found;
}

/** `continue outer` skips the rest of the inner loop and the outer body. */
function rows(grid: number[][]): string[] {
  const out: string[] = [];
  rows: for (const row of grid) {
    let line = "";
    for (const cell of row) {
      if (cell < 0) continue rows;
      if (cell === 0) continue;
      line += String(cell);
    }
    out.push(line);
  }
  return out;
}

/** Labels across a switch, a while, a do-while, nested labels and a labeled block. */
function mixed(): string[] {
  const trace: string[] = [];
  let i = 0;
  scan: while (i < 6) {
    i++;
    switch (i % 3) {
      case 0:
        trace.push(`skip${i}`);
        continue scan;
      case 1:
        if (i > 4) break scan;
        break;
      default:
        trace.push(`two${i}`);
    }
    trace.push(`end${i}`);
  }
  let d = 0;
  again: do {
    d++;
    for (let j = 0; j < 3; j++) {
      if (j === 1) continue again;
      trace.push(`d${d}j${j}`);
    }
  } while (d < 3);
  block: {
    trace.push("block start");
    if (d === 3) break block;
    trace.push("never");
  }
  a: b: for (let x = 0; x < 3; x++) {
    for (let y = 0; y < 3; y++) {
      if (y === 1) continue a;
      if (x === 2) break b;
      trace.push(`x${x}y${y}`);
    }
  }
  inner: {
    for (const z of [1, 2, 3]) {
      if (z === 2) break inner;
      trace.push(`z${z}`);
    }
    trace.push("after loop");
  }
  trace.push("done");
  return trace;
}

function returns(values: number[]): number {
  search: do {
    for (const value of values) {
      if (value > 10) return value;
      if (value < 0) break search;
    }
    return -1;
  } while (false);
  return -2;
}

const BUILD_TIME_CONTROL_FLOW = { doWhile: doWhile(), pair: findPair([1, 4, 6, 3], 9), rows: rows([[1, 0, 2], [3, -1, 4], [5]]), mixed: mixed() };

export const controlDoWhile = run(() => doWhile());

export const controlBreakOuter = run(() => [findPair([1, 4, 6, 3], 9), findPair([1, 2], 10), findPair([], 0)]);

export const controlContinueOuter = run(() => rows([[1, 0, 2], [3, -1, 4], [5], []]));

export const controlMixed = run(() => mixed());

export const controlReturns = run(() => [returns([1, 20]), returns([1, -5, 30]), returns([1, 2])]);

export const controlBuildTime = run(() => BUILD_TIME_CONTROL_FLOW);

export const controlUntyped = run(() => controlFlowUntyped());

export default defineConfig({ name: "language-control-flow" });
