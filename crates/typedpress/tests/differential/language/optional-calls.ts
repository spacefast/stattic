// Optional calls `f?.()`, `o.m?.()` and `o[k]?.()` in imported JavaScript, which files-sdk 3.1.2's
// npm build uses (`onProgress?.({ ... })`, `wait.cleanup?.()`). A null or undefined callee skips the
// call and its arguments; a member callee keeps its receiver. Every action runs under Node and as
// compiled PHP; the JSON results must be byte-identical. Typed TypeScript refuses optional calls
// (tests/fixtures/reject-optional-call-typed).
import { action, defineConfig } from "typedpress";
import { classMethods, optionalCalls } from "./optional-calls-helpers.mjs";

const run = (handler: () => unknown) => action({ input: {}, handler });

export const calls = run(() => optionalCalls());

export const classes = run(() => classMethods());

export default defineConfig({ name: "language-optional-calls" });
