"use strict";
// The shape of ret 0.5 dist/index.js: tsc's __createBinding/__exportStar re-exports, a getter
// export, then module.exports replaced by a function that carries properties.
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __exportStar = (this && this.__exportStar) || function(m, exports) {
    for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.types = void 0;
const types_1 = require("./commonjs-interop-types.cjs");
Object.defineProperty(exports, "types", { enumerable: true, get: function () { return types_1.types; } });
__exportStar(require("./commonjs-interop-tokenizer.cjs"), exports);
const tokenizer_1 = require("./commonjs-interop-tokenizer.cjs");
// What the re-exporting `exports` object looked like before module.exports was replaced.
const before = exports;
exports.default = tokenizer_1.tokenizer;
module.exports = tokenizer_1.tokenizer;
module.exports.types = types_1.types;
module.exports.before = {
    keys: Object.keys(before),
    forIn: (() => { const out = []; for (const k in before) out.push(k); return out; })(),
    esModule: before.__esModule,
    typesLive: before.types === types_1.types,
    tokenizerIsFunction: typeof before.tokenizer,
    descriptors: ["__esModule", "types", "tokenizer", "default", "missing"].map((k) => {
        const d = Object.getOwnPropertyDescriptor(before, k);
        return d === undefined ? null : { keys: Object.keys(d), get: typeof d.get, value: typeof d.value, enumerable: d.enumerable, configurable: d.configurable, writable: d.writable === undefined ? null : d.writable };
    }),
    own: ["__esModule", "types", "tokenizer", "toString", "hasOwnProperty"].map((k) => Object.prototype.hasOwnProperty.call(before, k)),
};
