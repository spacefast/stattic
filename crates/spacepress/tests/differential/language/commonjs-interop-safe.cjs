'use strict'
// The shape of safe-regex2 5.1 index.js: two requires of a function-valued module.exports,
// one of them destructured, and a function export carrying itself as properties.
const parse = require('./commonjs-interop-ret.cjs')
const { types } = require('./commonjs-interop-ret.cjs')

function safeRegex (re, options) {
  const limit = options?.limit ?? 25
  let reps = 0
  for (const token of parse(re)) {
    if (token.type === types.REPETITION) reps++
  }
  return reps <= limit
}

function isRegExp (x) {
  return Object.prototype.toString.call(x) === '[object RegExp]'
}

module.exports = safeRegex
module.exports.default = safeRegex
module.exports.safeRegex = safeRegex
module.exports.isRegExp = isRegExp
module.exports.shape = {
  sameModule: parse === require('./commonjs-interop-ret.cjs'),
  typesFromDestructuring: types === parse.types,
  keys: Object.keys(parse),
  ownTypes: Object.prototype.hasOwnProperty.call(parse, 'types'),
  typeofParse: typeof parse,
  typeofExport: typeof module.exports,
  ownKeysOfExport: Object.keys(module.exports),
}
