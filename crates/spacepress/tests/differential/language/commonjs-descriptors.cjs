'use strict'
// Property descriptor semantics while a CommonJS module runs: getters stay live, non-enumerable
// properties are skipped by for-in, Object.keys, spread and JSON, and hasOwnProperty.call sees
// own properties of objects, arrays, functions and null-prototype objects.
const keys = (o) => { const out = []; for (const k in o) out.push(k); return out }
const own = (o, k) => Object.prototype.hasOwnProperty.call(o, k)
const describe = (d) => d === undefined ? null : Object.keys(d).map((k) => k + ':' + (typeof d[k] === 'function' ? 'function' : String(d[k]))).join(',')

let counter = 1
const live = {}
const returned = Object.defineProperty(live, 'count', { enumerable: true, get: function () { return counter } })
const first = live.count
counter = 5
const second = live.count

const hidden = { a: 1 }
Object.defineProperty(hidden, 'b', { value: 2 })
Object.defineProperty(hidden, 'c', { value: 3, enumerable: true })

const box = {}
let stored = 0
Object.defineProperty(box, 'v', { get () { return stored * 2 }, set (x) { stored = x }, enumerable: false, configurable: true })
box.v = 21

const redefined = { x: 1 }
Object.defineProperty(redefined, 'x', { enumerable: false })
Object.defineProperty(redefined, 'x', { value: 9 })

const bare = Object.create(null)
bare.k = 1
const plain = Object.create(Object.prototype)
plain.k = 2
function fn () {}
fn.extra = 1
Object.defineProperty(fn, 'quiet', { value: 2 })

exports.results = {
  liveReads: [first, second],
  returnsTarget: returned === live,
  hiddenKeys: Object.keys(hidden),
  hiddenForIn: keys(hidden),
  hiddenSpread: Object.keys({ ...hidden }),
  hiddenRead: [hidden.a, hidden.b, hidden.c],
  hiddenIn: 'b' in hidden,
  descriptors: [
    describe(Object.getOwnPropertyDescriptor(hidden, 'a')),
    describe(Object.getOwnPropertyDescriptor(hidden, 'b')),
    describe(Object.getOwnPropertyDescriptor(live, 'count')),
    describe(Object.getOwnPropertyDescriptor(box, 'v')),
    describe(Object.getOwnPropertyDescriptor(redefined, 'x')),
    describe(Object.getOwnPropertyDescriptor([7], '0')),
    describe(Object.getOwnPropertyDescriptor([7], 'length')),
    describe(Object.getOwnPropertyDescriptor('ab', '1')),
    describe(Object.getOwnPropertyDescriptor(hidden, 'missing')),
    describe(Object.getOwnPropertyDescriptor(fn, 'quiet')),
  ],
  accessor: [box.v, stored, Object.keys(box)],
  redefined: [redefined.x, Object.keys(redefined)],
  ownObject: ['a', 'b', 'missing', 'toString', 'hasOwnProperty'].map((k) => own(hidden, k)),
  ownArray: ['0', '1', 'length', 'push'].map((k) => own([1], k)),
  ownNullProto: ['k', 'toString'].map((k) => own(bare, k)),
  ownFunction: ['extra', 'quiet', 'missing'].map((k) => own(fn, k)),
  ownString: ['0', 'length', '5'].map((k) => own('ab', k)),
  enumerable: [Object.prototype.propertyIsEnumerable.call(hidden, 'a'), Object.prototype.propertyIsEnumerable.call(hidden, 'b')],
  createNull: [bare.k, typeof bare.toString, 'toString' in bare, Object.keys(bare)],
  createObject: [plain.k, 'toString' in plain, Object.keys(plain)],
  functionKeys: Object.keys(fn),
  tags: [Object.prototype.toString.call([]), Object.prototype.toString.call(null), Object.prototype.toString.call(/x/), Object.prototype.toString.call(fn), Object.prototype.toString.call(hidden)],
  methodThis: { n: 3, f (a) { return [this.n, a] } }.f(4),
}
