// Untyped JavaScript for tests/differential/language/null-proto.ts: `__proto__: null` object
// literals as picomatch 4.0.7 writes them (lib/constants.js), read through TypedPress's checked
// JavaScript dispatch.

// picomatch lib/constants.js, verbatim.
const POSIX_REGEX_SOURCE = {
  __proto__: null,
  alnum: 'a-zA-Z0-9',
  alpha: 'a-zA-Z',
  ascii: '\\x00-\\x7F',
  blank: ' \\t',
  cntrl: '\\x00-\\x1F\\x7F',
  digit: '0-9',
  graph: '\\x21-\\x7E',
  lower: 'a-z',
  print: '\\x20-\\x7E ',
  punct: '\\-!"#$%&\'()\\*+,./:;<=>?@[\\]^_`{|}~',
  space: ' \\t\\r\\n\\v\\f',
  upper: 'A-Z',
  word: 'A-Za-z0-9_',
  xdigit: 'A-Fa-f0-9'
};

const constants = {
  REPLACEMENTS: {
    __proto__: null,
    '***': '*',
    '**/**': '**',
    '**/**/**': '**'
  },
  POSIX_REGEX_SOURCE
};

const PROTOTYPE_NAMES = ['constructor', 'toString', 'valueOf', 'hasOwnProperty', '__proto__', 'isPrototypeOf', 'propertyIsEnumerable', 'toLocaleString', '__defineGetter__', '__lookupGetter__'];

function attempt(run) {
  try { return run(); } catch (error) { return `${error.name}`; }
}

// A null-prototype object whose methods read `this`: the compiler rebuilds it per request as a
// receiver, which must keep the null prototype.
const scoped = {
  __proto__: null,
  n: 1,
  get(key) { return this[key]; },
  has(key) { return key in this; },
};

export function thisNullProto(inputs) {
  const names = [...inputs, 'n', 'get'];
  return {
    types: names.map((key) => typeof scoped.get(key)),
    present: names.map((key) => scoped.has(key)),
    constructor: typeof scoped.constructor,
  };
}

/** picomatch lib/parse.js: `input = REPLACEMENTS[input] || input` and `POSIX_REGEX_SOURCE[rest]`. */
export function picomatchLookups(inputs) {
  const { REPLACEMENTS } = constants;
  return {
    replaced: inputs.map((input) => REPLACEMENTS[input] || input),
    posix: inputs.map((rest) => { const posix = constants.POSIX_REGEX_SOURCE[rest]; return posix === undefined ? null : posix; }),
    keys: [Object.keys(REPLACEMENTS), Object.keys(POSIX_REGEX_SOURCE).length],
  };
}

export function untypedNullProto() {
  const table = { __proto__: null, a: 1, 'b-c': 2 };
  const plain = { a: 1, 'b-c': 2 };
  const forIn = [];
  for (const key in table) forIn.push(key);
  const spread = { ...table, z: 3 };
  const fresh = { __proto__: null, ...plain, ['comp' + 'uted']: 4 };
  const reads = PROTOTYPE_NAMES.map((name) => [name, typeof table[name], name in table, name in plain]);
  table.late = 'added';
  table.constructor = 'own too';
  table['toString'] = 'own as well';
  const afterWrite = [table.late, table.constructor, table.toString, 'constructor' in table, Object.keys(table)];
  delete table.late;
  return {
    reads,
    keys: Object.keys(fresh),
    values: Object.values(fresh),
    entries: Object.entries(fresh),
    forIn,
    spread: [JSON.stringify(spread), 'toString' in spread],
    json: JSON.stringify(fresh),
    afterWrite,
    afterDelete: ['late' in table, Object.keys(table)],
    method: attempt(() => table.hasOwnProperty('a')),
    computedMethod: attempt(() => table['toString']()),
    coercion: [attempt(() => String(fresh)), attempt(() => `${fresh}`), attempt(() => fresh + '')],
    hasOwn: [Object.hasOwn(table, 'a'), Object.hasOwn(table, 'toString')],
    optional: [table.missing?.x, typeof table.valueOf],
  };
}
