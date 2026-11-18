// Untyped JavaScript for tests/differential/language/destructuring-assignment.ts: these run
// through TypedPress's checked JavaScript dispatch rather than typed TypeScript compilation.

export function destructuringUntyped() {
  let a = 1;
  let b = 2;
  [a, b] = [b, a];
  let x;
  let y;
  let rest;
  ({ x, y = "dy", ...rest } = { x: 1, z: 3, w: 4 });
  const target = {};
  [target.first, target.second = "two"] = ["one"];
  let chars;
  [...chars] = "héllo";
  let p;
  let q;
  ({ p: [p, q] } = { p: [7, 8] });
  const live = [1, 2];
  [live[1], live[0]] = live;
  const tail = [1, 2, 3];
  let after;
  [tail[2], ...after] = tail;
  return { a, b, x, y, rest, target, chars, p, q, live, tail, after };
}
