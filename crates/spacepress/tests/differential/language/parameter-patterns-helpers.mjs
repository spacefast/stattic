// Untyped JavaScript for tests/differential/language/parameter-patterns.ts: destructured
// parameters in imported JavaScript, the shapes npm libraries (p-map's pMapIterable) use.

export function options(iterable, { concurrency = 5, backpressure = concurrency, preserveOrder = true } = {}) {
  return [iterable, concurrency, backpressure, preserveOrder];
}

export function rest({ a, ...others }) {
  return [a, others];
}

export function pair([x, y = x], ...more) {
  return [x, y, more];
}

export function nested({ outer: { inner = "default" } = {}, list: [first, ...tail] = [] }) {
  return [inner, first, tail];
}

export function strings([head, ...letters]) {
  return [head, letters];
}

export function missing(value) {
  try {
    const { field } = value;
    return field;
  } catch (error) {
    return error.name;
  }
}
