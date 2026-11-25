// Untyped JavaScript for tests/differential/language/bitwise.ts: these run through SpacePress's checked
// JavaScript dispatch rather than typed TypeScript compilation.

function show(values) {
  return values.map((n) => (n === 0 && 1 / n < 0 ? "-0" : String(n)));
}

export function bitwise(edge, counts) {
  const out = {};
  const operands = [...edge, "12", " -3 ", "", "x", true, null, undefined, [5], { valueOf: () => 9 }];
  out.not = show(operands.map((x) => ~x));
  out.and = show(operands.map((x) => x & 0xff));
  out.or = show(operands.map((x) => x | 0));
  out.xor = show(operands.map((x) => x ^ 0x55));
  for (const count of [...counts, "2", true]) {
    out[`shl ${count}`] = show(operands.map((x) => x << count));
    out[`shr ${count}`] = show(operands.map((x) => x >> count));
    out[`ushr ${count}`] = show(operands.map((x) => x >>> count));
  }
  let value = 0x1234;
  value ^= 0xff;
  value <<= 4;
  value >>>= 1;
  value |= 1;
  value &= 0xfff;
  out.assigned = show([value]);
  let fnv = 0x811c9dc5;
  for (const ch of "Ärtsoppa 😀") {
    fnv ^= ch.charCodeAt(0);
    fnv = (fnv + ((fnv << 1) + (fnv << 4) + (fnv << 7) + (fnv << 8) + (fnv << 24))) >>> 0;
  }
  out.hash = String(fnv);
  return out;
}
