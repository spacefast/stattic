// crypto.subtle.digest(algorithm, data) in imported JavaScript: SHA-1/256/384/512 by name (any case)
// or { name }, over an ArrayBuffer, a typed array, a subarray or a DataView, resolving to an
// ArrayBuffer; an unknown algorithm or non-binary data rejects (NotSupportedError, TypeError).
// files-sdk 3.1.2's sha256Hex is included verbatim. Every action runs under Node and as compiled
// PHP; the JSON results must be byte-identical. Typed TypeScript refuses crypto.subtle
// (tests/fixtures/reject-crypto-subtle).
import { action, defineConfig } from "typedpress";
import { digests as digestsImpl } from "./subtle-digest-helpers.mjs";

export const digests = action({ input: {}, handler: async () => await digestsImpl() });

export default defineConfig({ name: "language-subtle-digest" });
