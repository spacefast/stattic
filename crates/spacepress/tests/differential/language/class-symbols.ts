// Symbol-keyed class methods, `static [Symbol.hasInstance]` behind `instanceof`, and class
// prototypes as targets for symbol brands and `in`, as files-sdk 3.1.2 writes them
// (class-symbols-helpers.mjs copies its dist). Authored TypeScript has no symbols, so it uses
// those classes through `instanceof` and `in` on values the helpers create. Every action runs
// under Node and as compiled PHP; the JSON results must be byte-identical.
import { defineConfig, mutation } from "spacepress";
import {
  Files2,
  FilesError2,
  NotFound,
  forgedError,
  hasInstance,
  makeError,
  prototypeBrands,
  prototypeMembers,
  symbolKinds,
  symbolMethods,
} from "./class-symbols-helpers.mjs";

const run = (handler: () => unknown) => mutation({}, handler);

function classify(value: unknown): string {
  if (value instanceof NotFound) return "not-found";
  if (value instanceof FilesError2) return "files-error";
  if (value instanceof Error) return "error";
  return "other";
}

function typed(): unknown[] {
  const values: unknown[] = [makeError("Invalid", "a"), new NotFound("b"), forgedError(), new Error("c"), {}, null, "text"];
  const keys = ["list", "prefix", "then", "constructor", "missing"];
  return [values.map(classify), keys.map((key) => key in Files2.prototype)];
}

export const classSymbolMethods = run(() => symbolMethods());

export const classSymbolKinds = run(() => symbolKinds());

export const classHasInstance = run(() => hasInstance());

export const classPrototypeBrands = run(() => prototypeBrands());

export const classPrototypeMembers = run(() => prototypeMembers());

export const classTyped = run(() => typed());

export default defineConfig({ name: "language-class-symbols" });
