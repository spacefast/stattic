// Untyped JavaScript for tests/differential/language/tagged-templates.ts: these run through
// SpacePress's checked JavaScript dispatch rather than typed TypeScript compilation.

function sql(strings, ...values) {
  return { text: strings.reduce((out, part, i) => out + "$" + i + part), values };
}

function cooked(strings) {
  return [strings[0], strings.raw[0], strings.length];
}

export function taggedUntyped() {
  const id = 7;
  const name = "x";
  return {
    sql: sql`select * from t where id = ${id} and name = ${name}`,
    cooked: cooked`\u{1F600} tab\t`,
    invalid: cooked`\unicode and \xZZ`,
    raw: String.raw`a\tb${1}c`,
    rawCall: String.raw({ raw: ["x", "y", "z"] }, 1, 2, 3, 4),
    frozen: (() => { const site = (s) => s; const s = site`a`; try { s[0] = "z"; return "wrote"; } catch (e) { return e.name; } })(),
    frozenNoOps: (() => { const site = (s) => s; const attempt = (f) => { try { return JSON.stringify(f()); } catch (e) { return e.name; } }; return [attempt(() => site`a`.reverse()), attempt(() => site`a`.sort()), attempt(() => site`a${1}b`.reverse()), attempt(() => site`a${1}b`.sort())]; })(),
  };
}
