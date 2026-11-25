// Untyped JavaScript for tests/differential/language/bigint.ts: these run through SpacePress's checked
// JavaScript dispatch rather than typed TypeScript compilation.

function attempt(label, f) {
  try { return `${label}: ${String(f())}`; } catch (error) { return `${label}: ${error.name}: ${error.message}`; }
}

export function bigintUntyped() {
  const a = 2n ** 70n;
  const b = BigInt("123");
  return [
    String(a + b), String(a / b), String(a % b), String(-a >> 3n), String(a & 0xffffn), String(~b),
    String(a > 1e21), String(a == "1180591620717411303424"), String(b == 123), String(b === 123),
    typeof a, String(a), `${b}`, String(Number(b) + 1), String(BigInt(true) + 1n),
    String([1n, 2n].includes(2n)), b.toString(16), b.toString(undefined), String(b.valueOf()), (() => { const f = b.toString; return f.call(255n, 2); })(), String(0n ? "yes" : "no"), String(b + "x"),
    attempt("mix", () => a + 1),
    attempt("unary plus", () => +a),
    attempt("bad", () => BigInt("x")),
    attempt("null", () => BigInt(null)),
  ];
}
