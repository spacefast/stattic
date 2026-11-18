// Untyped JavaScript for tests/differential/language/function-expressions.ts. Bundlers and npm
// libraries emit named function expressions (`var x = function name() {...}`), often recursive.

export function named() {
  const fact = function f(n) { return n <= 1 ? 1 : n * f(n - 1); };
  const shadowParam = function h(h) { return h; };
  const shadowVar = function k() { var k = 2; return k; };
  const own = function g() { return typeof g; };
  const fib = function fibonacci(n) { return n < 2 ? n : fibonacci(n - 1) + fibonacci(n - 2); };
  const callbacks = [1, 2, 3].map(function square(x) { return x * x; });
  return [fact(5), shadowParam(3), shadowVar(), own(), fib(10), callbacks];
}

export function recursiveLocal() {
  function walk(node) { return node.children.reduce((sum, child) => sum + walk(child), 1); }
  return walk({ children: [{ children: [] }, { children: [{ children: [] }] }] });
}

export function hoistedVar() {
  const fn = function f(flag) { if (flag) { var f; } f = 2; return f; };
  return [fn(true), fn(false)];
}

// A const arrow or function expression that names its own binding: the closure is the whole
// initializer, so it can only run after the binding holds it.
export function selfBound() {
  const fact = n => (n <= 1 ? 1 : n * fact(n - 1));
  const fib = n => (n < 2 ? n : fib(n - 1) + fib(n - 2));
  const sum = function (list) { return list.length === 0 ? 0 : list[0] + sum(list.slice(1)); };
  const both = function inner(n) { return n <= 0 ? 0 : 1 + both(n - 1) + inner(0); };
  let countdown = n => (n <= 0 ? "done" : countdown(n - 1));
  const original = countdown;
  countdown = () => "replaced";
  const shadowParam = (shadowParam, n) => (n <= 0 ? shadowParam : shadowParam + 1);
  const shadowInner = n => { const shadowInner = n * 2; return shadowInner; };
  const nested = n => { const step = m => (m <= 0 ? 0 : 1 + nested(m - 1)); return step(n); };
  const perIteration = [];
  for (let i = 0; i < 3; i++) {
    const again = n => (n <= 0 ? i : again(n - 1));
    perIteration.push(again);
  }
  return [
    fact(5), fib(10), sum([1, 2, 3, 4]), both(3), original(4),
    shadowParam(7, 1), shadowInner(5), nested(4), perIteration.map(f => f(2)),
  ];
}

// A `let` that names itself and is then reassigned to something that isn't a function: the old
// closure's self-call reads the binding as it is now, so it must throw a catchable TypeError, never
// call a PHP function named by a string or fail as a PHP error.
export function selfBoundReassigned() {
  const outcome = (run) => { try { return run(); } catch (error) { return [error.name, error instanceof TypeError]; } };
  let byName = s => (s.length > 3 ? byName(s.slice(1)) : s);
  const keepName = byName;
  byName = "strtoupper";
  let toNull = n => (n <= 0 ? "done" : toNull(n - 1));
  const keepNull = toNull;
  toNull = null;
  let toNumber = n => (n <= 0 ? "done" : toNumber(n - 1));
  const keepNumber = toNumber;
  toNumber = 5;
  let selfAssigns = () => { selfAssigns = 3; return 1; };
  const first = selfAssigns();
  return [outcome(() => keepName("hello")), outcome(() => keepNull(2)), outcome(() => keepNumber(2)), keepName("abc"), keepNull(0), [first, selfAssigns]];
}

// Async and generator function expressions naming their own binding.
export async function selfBoundSuspending() {
  const depth = async n => (n <= 0 ? 0 : 1 + (await depth(n - 1)));
  const countdown = function* (n) { if (n > 0) { yield n; yield* countdown(n - 1); } };
  return [await depth(3), [...countdown(3)]];
}

// picomatch 4.0.7 lib/parse.js `parse.fastpaths`: a recursive const arrow in a switch default.
export function fastpath(input) {
  const star = "[^/]*?";
  const create = str => {
    switch (str) {
      case "*":
        return `(?!\\.)${star}`;
      case "**":
        return "(?:.*)";
      case "*.*":
        return `(?!\\.)${star}\\.${star}`;
      default: {
        const match = /^(.*?)\.(\w+)$/.exec(str);
        if (!match) return;
        const source = create(match[1]);
        if (!source) return;
        return source + "\\." + match[2];
      }
    }
  };
  return [create(input), create("a.b.c"), create("*.md"), create("*.*.ts"), String(create("a/b"))];
}
