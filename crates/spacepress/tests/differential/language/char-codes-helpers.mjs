// Untyped JavaScript for tests/differential/language/char-codes.ts: String.fromCharCode and
// String.fromCodePoint through SpacePress's checked JavaScript dispatch, as ret 0.5 (a files-sdk
// dependency) rebuilds regex sources from token code points.

export function charCodes() {
  const out = {
    ascii: String.fromCharCode(72, 105),
    none: String.fromCharCode(),
    latin: String.fromCharCode(233, 0xe9, 0x00fc),
    bmp: String.fromCharCode(0x4e2d, 0x6587),
    pair: String.fromCharCode(0xd83d, 0xde00),
    wraps: [String.fromCharCode(65 + 65536), String.fromCharCode(-65471), String.fromCharCode(65.9)],
    coerced: [String.fromCharCode("66"), String.fromCharCode(NaN, 67), String.fromCharCode(true)],
    roundTrip: "aé中".split("").map((c) => String.fromCharCode(c.charCodeAt(0))).join(""),
    codePoints: [String.fromCodePoint(0x1f600), String.fromCodePoint(97, 0x10ffff).length, String.fromCodePoint()],
  };
  // ret's writeSetToken and reconstruct: escape set syntax, otherwise the character itself.
  const setChar = (charCode) => (charCode === 94 ? "\\^" : charCode === 92 ? "\\\\" : charCode === 93 ? "\\]" : charCode === 45 ? "\\-" : String.fromCharCode(charCode));
  out.retSet = [94, 92, 93, 45, 97, 0x2603].map(setChar).join("");
  const special = (c) => (/[[\]{}^$.|?*+()]/.test(c) ? `\\${c}` : c);
  out.retEscape = [46, 120, 42, 40].map((code) => special(String.fromCharCode(code))).join("");
  try { out.badPoint = String.fromCodePoint(0x110000); } catch (error) { out.badPoint = error.name; }
  try { out.fraction = String.fromCodePoint(1.5); } catch (error) { out.fraction = error.name; }
  try { out.negative = String.fromCodePoint(-1); } catch (error) { out.negative = error.name; }
  return out;
}
