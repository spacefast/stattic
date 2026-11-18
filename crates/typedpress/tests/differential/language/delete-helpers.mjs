// Untyped JavaScript for tests/differential/language/delete.ts: these run through TypedPress's checked
// JavaScript dispatch rather than typed TypeScript compilation.

function attempt(label, remove) {
  try { return `${label}: ${remove()}`; } catch (error) { return `${label}: ${error.name}: ${error.message}`; }
}

export function deleteUntyped() {
  const record = { a: 1, b: 2, c: 3, 1: "one" };
  const removed = [delete record.b, delete record["a"], delete record[1], delete record.missing];
  const list = [1, 2, 3];
  list.extra = "x";
  function args(a, b, c) { return [delete arguments[1], arguments.length, 1 in arguments, Array.from(arguments)]; }
  return {
    removed,
    record,
    keys: Object.keys(record),
    inAfter: ["a" in record, "c" in record],
    list: [delete list.extra, delete list[9], list.length, "extra" in list],
    args: args("x", "y", "z"),
    refused: [
      attempt("array length", () => delete list.length),
      attempt("string index", () => delete "abc"[0]),
      attempt("string length", () => delete "abc".length),
      attempt("number", () => delete (5).foo),
      attempt("null", () => { const o = null; return delete o.foo; }),
      attempt("undefined", () => { let o; return delete o.foo; }),
      attempt("regex", () => delete /x/.lastIndex),
      attempt("Math", () => delete Math.E),
      attempt("map method", () => delete new Map().get),
      attempt("arguments length", () => { function f(a, b) { const r = delete arguments.length; return [r, arguments.length, "length" in arguments].join(","); } return f(1, 2); }),
      attempt("error message", () => { const e = new Error("gone"); const r = delete e.message; return [r, JSON.stringify(e.message), "message" in e, Object.hasOwn(e, "message")].join(","); }),
    ],
  };
}
