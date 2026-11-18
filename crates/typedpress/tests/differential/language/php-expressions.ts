// Readability changes must retain single evaluation and lazy keys/fallbacks.
import { action, defineConfig } from "typedpress";

const run = (handler: () => unknown) => action({ input: {}, handler });

export const optionalReads = run(() => {
  let reads = 0;
  let keys = 0;
  let fallbacks = 0;
  const present = (): { value: number } | null => { reads++; return { value: 0 }; };
  const missing = (): { value: number } | null => { reads++; return null; };
  const key = (): "value" => { keys++; return "value"; };
  const fallback = () => { fallbacks++; return 9; };
  const zero = present()?.value ?? 7;
  const absent = missing()?.value ?? -1;
  const lazyKey = missing()?.[key()] ?? 5;
  const liveKey = present()?.[key()] ?? 5;
  const skippedFallback = present()?.value ?? fallback();
  const usedFallback = missing()?.value ?? fallback();
  return [zero, absent, lazyKey, liveKey, skippedFallback, usedFallback, reads, keys, fallbacks];
});

function numberOrNull(value: number | null) { return value ?? 7; }
function stringOrNull(value: string | null) { return value ?? "fallback"; }
function boolOrNull(value: boolean | null) { return value ?? true; }
function identity(value: { value: number } | null) { return value ?? null; }

export const coalescing = run(() => {
  const object = { value: 1 };
  return [
    numberOrNull(null), numberOrNull(0), numberOrNull(NaN),
    stringOrNull(null), stringOrNull(""), stringOrNull("0"),
    boolOrNull(null), boolOrNull(false), identity(object) === object, identity(null),
  ];
});

function write(row: { value: number }, value: number) {
  row.value = value;
  row.value += 2;
  const assigned = (row.value = 7) + 1;
  return [row.value, assigned];
}

export const assignments = run(() => {
  let first = 0;
  let second = 0;
  first = (second = 2) + 3;
  const row = { value: 0 };
  const written = write(row, first);
  let target = { value: 1 };
  const original = target;
  target.value = (target = { value: 9 }).value + 1;
  return [first, second, written, row.value, target.value, original.value];
});

export const lazyArraysAndThrows = run(() => {
  const missing = (): number[] | null => null;
  const first = missing() ?? [];
  const second = missing() ?? [];
  const present = (): { value: number } | null => ({ value: 1 });
  const fail = (): number => { throw new Error("must stay lazy"); };
  const value = present()?.value ?? fail();
  let caught = "";
  const receiver = (): { value: number } | null => { throw new Error("receiver"); };
  try { receiver()?.value ?? 0; } catch (error) { caught = error.message; }
  return [first === second, first.length, second.length, value, caught];
});

export default defineConfig({ name: "language-php-expressions" });
