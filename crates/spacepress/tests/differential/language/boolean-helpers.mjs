// Untyped JavaScript for tests/differential/language/boolean.ts: the global `Boolean` called and
// passed as a value, as picomatch 4.0.7 (a files-sdk dependency) does in lib/parse.js and
// lib/picomatch.js, run through SpacePress's checked JavaScript dispatch.

export function falsyAndTruthy() {
  return [0, -0, NaN, '', null, undefined, false, 0n, '0', 'false', [], {}, 1, -1, 'a', true, 1n, Infinity, () => 0];
}

function applyEach(fn, values) {
  const out = [];
  for (const value of values) out.push(fn(value));
  return out;
}

// picomatch lib/parse.js: `branches.map(normalizeSimpleBranch).filter(Boolean)`.
function normalizeSimpleBranch(value) {
  let branch = value.trim();
  while (branch.startsWith('./')) branch = branch.slice(2);
  return branch;
}

export function untypedBoolean() {
  const values = falsyAndTruthy();
  const truth = Boolean;
  const branches = ['./a', '', '  ', './', 'b'];
  // picomatch lib/picomatch.js: `isMatch: Boolean(match)`.
  const match = /^(\w+)\.md$/.exec('readme.md');
  const miss = /^(\w+)\.md$/.exec('readme.ts');
  return {
    called: values.map((value) => Boolean(value)),
    mapped: values.map(Boolean),
    kept: values.filter(Boolean).map((value) => typeof value),
    some: [values.some(Boolean), [0, '', null].some(Boolean)],
    every: [values.every(Boolean), ['0', [], {}].every(Boolean)],
    find: [values.findIndex(Boolean), typeof values.find(Boolean)],
    alias: [truth(0), truth('0'), truth([]), truth(), truth(0, 1), truth === Boolean],
    higherOrder: applyEach(Boolean, values),
    picomatch: [branches.map(normalizeSimpleBranch).filter(Boolean), Boolean(match), Boolean(miss)],
    typeOf: typeof Boolean,
    noArgs: Boolean(),
  };
}
