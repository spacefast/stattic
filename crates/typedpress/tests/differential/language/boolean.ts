// The global `Boolean`, called and used as a function value: `.filter(Boolean)`, aliases, user
// higher-order functions and captured configuration values. picomatch 4.0.7 (a files-sdk
// dependency) uses both forms. ToBoolean is false for 0, -0, NaN, "", null, undefined, false and
// 0n, and true for everything else, including "0", "false", [], {} and functions. Every action
// runs under Node and as compiled PHP; the JSON results must be byte-identical.
import { defineConfig, mutation } from "typedpress";
import { falsyAndTruthy, untypedBoolean } from "./boolean-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

function values(): unknown[] {
  return [0, -0, NaN, "", null, undefined, false, 0n, "0", "false", [], {}, 1, -1, "a", true, 1n, Infinity, () => 0];
}

function applyEach(fn: (value: unknown) => boolean, input: unknown[]): boolean[] {
  const out: boolean[] = [];
  for (const value of input) out.push(fn(value));
  return out;
}

// Evaluated while the config is built (no bigint there), then captured by handlers.
const TRUTH = Boolean;
const BUILD_VALUES: unknown[] = [0, -0, NaN, "", null, undefined, false, "0", "false", [], {}, 1, "a", true, () => 0];
const BUILD_TIME = {
  called: BUILD_VALUES.map((value) => Boolean(value)),
  mapped: BUILD_VALUES.map(Boolean),
  kept: BUILD_VALUES.filter(Boolean).map((value) => typeof value),
  alias: [TRUTH(0), TRUTH("0"), TRUTH === Boolean],
  higherOrder: applyEach(Boolean, BUILD_VALUES),
  noArgs: Boolean(),
};

export const booleanBuildTime = run(() => BUILD_TIME);

export const booleanCalled = run(() => values().map((value) => Boolean(value)));

export const booleanMapped = run(() => values().map(Boolean));

export const booleanFiltered = run(() => values().filter(Boolean).map((value) => typeof value));

export const booleanSomeEvery = run(() => [values().some(Boolean), values().every(Boolean), ["0", "false", [], {}].every(Boolean), [0, "", null].some(Boolean)]);

export const booleanAlias = run(() => {
  const truth = Boolean;
  return [truth(0), truth("0"), truth([]), truth({}), truth(), truth === Boolean, values().map(truth)];
});

export const booleanHigherOrder = run(() => applyEach(Boolean, values()));

export const booleanCaptured = run(() => [values().map(TRUTH), TRUTH(""), TRUTH("false"), values().filter(TRUTH).length]);

export const booleanGlobalThis = run(() => [values().map(globalThis.Boolean), typeof Boolean, Boolean()]);

export const booleanImportedValues = run(() => falsyAndTruthy().map(Boolean));

export const booleanUntyped = run(() => untypedBoolean());

export default defineConfig({ name: "language-boolean" });
