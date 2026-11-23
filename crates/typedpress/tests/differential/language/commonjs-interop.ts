// TypeScript, esbuild and Babel CommonJS interop helpers, run while the config is evaluated:
// Object.create, Object.defineProperty with value/get/set and attributes,
// Object.getOwnPropertyDescriptor, Object.prototype.hasOwnProperty.call, module-level `this`
// as module.exports, and properties on a function module.exports. The modules follow ret 0.5
// and safe-regex2 5.1 (files-sdk dependencies) statement for statement. Every action runs under
// Node and as compiled PHP; the JSON results must be byte-identical.
import { defineConfig, mutation } from "typedpress";
import safeRegex from "./commonjs-interop-safe.cjs";
import { results } from "./commonjs-descriptors.cjs";
import ret from "./commonjs-interop-ret.cjs";

const run = (handler: () => unknown) => mutation({}, handler);

// Called while the config is evaluated.
const BUILD_TIME = [safeRegex("a+b"), safeRegex("a+b+c+", { limit: 2 }), safeRegex.isRegExp(/x/), safeRegex.isRegExp("x")];

export const interopReExports = run(() => ret.before);

export const interopFunctionExports = run(() => safeRegex.shape);

export const interopBuildTimeCalls = run(() => BUILD_TIME);

// The same functions compiled to PHP and called per request.
export const interopRunTimeCalls = run(() => [safeRegex("a+b"), safeRegex("a+b+c+", { limit: 2 }), safeRegex.safeRegex("x+")]);

export const descriptorSemantics = run(() => results);

export default defineConfig({ name: "language-commonjs-interop" });
