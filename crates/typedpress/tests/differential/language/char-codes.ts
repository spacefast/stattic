// String.fromCharCode and String.fromCodePoint, which ret 0.5 (reached from files-sdk through
// safe-regex2) calls at request time. fromCharCode takes each argument ToUint16 as one UTF-16 code
// unit, so surrogate pairs combine; fromCodePoint takes code points and throws a RangeError outside
// 0..0x10FFFF. Every action runs under Node and as compiled PHP; the JSON results must be
// byte-identical, in typed TypeScript and in imported JavaScript.
import { action, defineConfig } from "typedpress";
import { charCodes } from "./char-codes-helpers.mjs";

function typedImpl(): string[] {
  return [String.fromCharCode(72, 105), String.fromCharCode(0xd83d, 0xde00), String.fromCodePoint(0x1f600, 33), String.fromCharCode()];
}

export const untyped = action({ input: {}, handler: () => charCodes() });
export const typed = action({ input: {}, handler: () => typedImpl() });

export default defineConfig({ name: "language-char-codes" });
