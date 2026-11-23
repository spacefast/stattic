// `value instanceof RegExp` (and Map, Set, WeakMap, WeakSet, Date, URL, URLSearchParams,
// AbortController, AbortSignal) in imported JavaScript, which picomatch and files-sdk branch on.
// Every action runs under Node and as compiled PHP; the JSON results must be byte-identical.
import { defineConfig, mutation } from "typedpress";
import { instances as instancesImpl } from "./builtin-instanceof-helpers.mjs";

export const instances = mutation({}, () => instancesImpl());

export default defineConfig({ name: "language-builtin-instanceof" });
