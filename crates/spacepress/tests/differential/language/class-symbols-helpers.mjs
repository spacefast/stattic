// Untyped JavaScript for tests/differential/language/class-symbols.ts: files-sdk 3.1.2's class
// patterns, copied from its Bun-bundled dist (chunk-8wzs004d.js, chunk-k3s1pj0f.js,
// chunk-xczk9qxg.js) with only the surrounding members trimmed.

// chunk-tyfgva58.js
var isObject = (value) => typeof value === "object" && value !== null;

// chunk-k3s1pj0f.js:7
var FOLD_PROVIDER_EVENT = Symbol.for("files-sdk.foldProviderEvent");

// chunk-8wzs004d.js, verbatim apart from the trimmed static helpers.
var DETERMINISTIC_CODES = new Set([
  "Invalid",
  "ReadOnly",
  "Unsupported"
]);
var FILES_ERROR_BRAND = Symbol.for("files-sdk.FilesError");

class FilesError2 extends Error {
  static [Symbol.hasInstance](value) {
    if (this !== FilesError2) {
      return Function.prototype[Symbol.hasInstance].call(this, value);
    }
    return isObject(value) && FILES_ERROR_BRAND in value;
  }
  code;
  permanent;
  cause;
  constructor(code, message, cause, opts) {
    super(message);
    this.name = "FilesError";
    this.code = code;
    this.permanent = opts?.permanent === true || DETERMINISTIC_CODES.has(code);
    this.cause = cause;
  }
  static wrap(cause, fallbackCode = "Provider") {
    if (cause instanceof FilesError2) {
      return cause;
    }
    const message = cause instanceof Error ? cause.message : String(cause);
    return new FilesError2(fallbackCode, message, cause);
  }
}
Object.defineProperty(FilesError2.prototype, FILES_ERROR_BRAND, { value: true });
var DISPOSITION_UNSUPPORTED_BRAND = /* @__PURE__ */ Symbol.for("files-sdk.DispositionUnsupported");
var dispositionUnsupported = (message) => {
  const error = new FilesError2("Unsupported", message);
  Object.defineProperty(error, DISPOSITION_UNSUPPORTED_BRAND, { value: true });
  return error;
};
var isDispositionUnsupported = (error) => error instanceof FilesError2 && (DISPOSITION_UNSUPPORTED_BRAND in error);

// A subclass inherits the static [Symbol.hasInstance]; `this !== FilesError2` there, so it
// falls through to the ordinary prototype-chain check.
class NotFound extends FilesError2 {
  constructor(message) {
    super("NotFound", message);
  }
}

// chunk-xczk9qxg.js: a symbol-keyed method that reads private state, and the extension
// collision check `key in Files2.prototype`.
class Files2 {
  #prefix;
  #plugins;
  constructor(opts = {}) {
    this.#prefix = opts.prefix;
    this.#plugins = opts.plugins;
  }
  get prefix() { return this.#prefix ?? ""; }
  list() { return []; }
  static create(opts) { return new Files2(opts); }
  [FOLD_PROVIDER_EVENT](event) {
    let folded = event;
    if (this.#prefix) {
      const scoped = `${this.#prefix}/`;
      if (!event.key.startsWith(scoped) || event.key === scoped) {
        return null;
      }
      folded = { ...event, key: event.key.slice(scoped.length) };
    }
    const plugins = this.#plugins ?? [];
    for (let i = plugins.length - 1;i >= 0 && folded; i -= 1) {
      const hook = plugins[i]?.event;
      if (hook) {
        folded = hook(folded);
      }
    }
    return folded;
  }
  static [FOLD_PROVIDER_EVENT](label) { return `static ${label}`; }
  extensionCollides(surface) {
    const out = [];
    for (const key of Object.keys(surface)) {
      out.push(key === "then" || key in Files2.prototype);
    }
    return out;
  }
}
class ScopedFiles extends Files2 {
  [FOLD_PROVIDER_EVENT](event) {
    const folded = super.list();
    return { scoped: true, key: event.key, list: folded.length };
  }
}

// Read while the module is evaluated, then captured by the handlers.
const PROTOTYPE = Files2.prototype;
const LOCAL = Symbol("local");
Object.defineProperty(Files2.prototype, LOCAL, { value: "local-brand", writable: false });

const attempt = (run) => { try { return run(); } catch (error) { return `${error.name}`; } };

export { Files2, FilesError2, NotFound };
export const makeError = (code, message) => new FilesError2(code, message);
export const forgedError = () => ({ [FILES_ERROR_BRAND]: true });

export function symbolMethods() {
  const plain = new Files2();
  const scoped = new Files2({ prefix: "tenant", plugins: [{ event: (e) => ({ ...e, seen: true }) }, {}] });
  const child = new ScopedFiles();
  const probe = (files) => files[FOLD_PROVIDER_EVENT]({ type: "upload", key: "tenant/a.txt" });
  return [
    probe(plain), probe(scoped), scoped[FOLD_PROVIDER_EVENT]({ key: "other/b.txt" }), scoped[FOLD_PROVIDER_EVENT]({ key: "tenant/" }),
    probe(child), Files2[FOLD_PROVIDER_EVENT]("call"), ScopedFiles[FOLD_PROVIDER_EVENT]("inherited"),
    typeof plain[FOLD_PROVIDER_EVENT], plain[FOLD_PROVIDER_EVENT] === scoped[FOLD_PROVIDER_EVENT], plain[FOLD_PROVIDER_EVENT] === child[FOLD_PROVIDER_EVENT],
    plain[FOLD_PROVIDER_EVENT].call(scoped, { key: "tenant/c" }),
    FOLD_PROVIDER_EVENT in plain, FOLD_PROVIDER_EVENT in Files2, FOLD_PROVIDER_EVENT in {}, Object.keys(plain), JSON.stringify(plain),
    attempt(() => { plain[FOLD_PROVIDER_EVENT] = () => "own"; return plain[FOLD_PROVIDER_EVENT](); }),
    probe(new Files2()),
  ];
}

export function hasInstance() {
  const error = new FilesError2("Invalid", "bad input");
  const notFound = new NotFound("missing");
  const disposition = dispositionUnsupported("no disposition");
  const forged = { [FILES_ERROR_BRAND]: true };
  const native = new Error("native");
  return [
    error instanceof FilesError2, notFound instanceof FilesError2, notFound instanceof NotFound, error instanceof NotFound,
    forged instanceof FilesError2, forged instanceof NotFound, native instanceof FilesError2, null instanceof FilesError2, "text" instanceof FilesError2,
    error instanceof Error, notFound instanceof Error, ({}) instanceof FilesError2,
    FilesError2.wrap(error) === error, FilesError2.wrap(native).code, FilesError2.wrap("boom").message, FilesError2.wrap(forged) === forged,
    isDispositionUnsupported(disposition), isDispositionUnsupported(error), error.permanent, notFound.permanent, error.name, `${error}`,
    Function.prototype[Symbol.hasInstance].call(FilesError2, forged), Function.prototype[Symbol.hasInstance].call(FilesError2, error),
    Function.prototype[Symbol.hasInstance].call({}, error), FilesError2[Symbol.hasInstance](forged), NotFound[Symbol.hasInstance](error),
    typeof Files2[Symbol.hasInstance], Files2[Symbol.hasInstance](new Files2()),
  ];
}

export function prototypeBrands() {
  const error = new FilesError2("Invalid", "x");
  const notFound = new NotFound("y");
  const plain = new Files2();
  return [
    FILES_ERROR_BRAND in error, FILES_ERROR_BRAND in notFound, FILES_ERROR_BRAND in {}, FILES_ERROR_BRAND in { [FILES_ERROR_BRAND]: 0 },
    FILES_ERROR_BRAND in FilesError2.prototype, FILES_ERROR_BRAND in NotFound.prototype, FILES_ERROR_BRAND in plain,
    error[FILES_ERROR_BRAND], notFound[FILES_ERROR_BRAND], plain[FILES_ERROR_BRAND] === undefined, FilesError2.prototype[FILES_ERROR_BRAND],
    Object.keys(error), JSON.stringify(error), Object.hasOwn(error, FILES_ERROR_BRAND),
    attempt(() => { error[FILES_ERROR_BRAND] = false; return error[FILES_ERROR_BRAND]; }),
    plain[LOCAL], attempt(() => { plain[LOCAL] = "own"; return plain[LOCAL]; }), LOCAL in plain,
    attempt(() => Object.defineProperty(FilesError2.prototype, FILES_ERROR_BRAND, { value: false })),
    Object.defineProperty(Files2.prototype, Symbol.for("late"), { value: 1, configurable: true }) === Files2.prototype,
    plain[Symbol.for("late")], Symbol.for("late") in new ScopedFiles(),
  ];
}

// Generator and async methods under symbol keys, and a static symbol method reading `this`.
const ITEMS = Symbol("items");
const LOAD = Symbol.for("load");
class Bag {
  values = [1, 2, 3];
  *[ITEMS]() { for (const value of this.values) yield value * 10; }
  async [LOAD](extra) { const base = await Promise.resolve(this.values.length); return base + extra; }
  static [LOAD]() { return this === Bag ? "Bag" : "subclass"; }
}
class BigBag extends Bag {
  *[ITEMS]() { yield 0; yield* this.values; yield 99; }
}

export async function symbolKinds() {
  const bag = new Bag();
  return [[...bag[ITEMS]()], await bag[LOAD](4), Bag[LOAD](), BigBag[LOAD](), [...new BigBag()[ITEMS]()]];
}

export function prototypeMembers() {
  const files = new Files2();
  const keys = ["list", "prefix", "create", "constructor", "then", "upload", "toString", "hasOwnProperty", "#prefix", "prototype"];
  return [
    keys.map((key) => key in Files2.prototype), keys.map((key) => key in ScopedFiles.prototype),
    files.extensionCollides({ list: 1, then: 2, upload: 3, prefix: 4 }),
    PROTOTYPE === Files2.prototype, Files2.prototype === ScopedFiles.prototype, Files2.prototype.constructor === Files2,
    ScopedFiles.prototype.constructor === ScopedFiles, Files2.prototype.list === files.list, typeof Files2.prototype.list,
    Files2.prototype.list.call(files), Files2.prototype.upload === undefined, typeof Files2.prototype, typeof PROTOTYPE,
  ];
}
