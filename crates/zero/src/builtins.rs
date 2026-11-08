//! Accepted JavaScript built-in overloads. Arity and phase support live here;
//! type/coercion checks stay at the implementation boundary. `None` means refused.

use crate::diag::Diag;
use crate::diag::Span;

/// A Node built-in module the compiler supplies itself. Nothing is loaded for it; its functions
/// lower in compile/host.rs.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BuiltinModule {
    /// `"node:crypto"` / `"crypto"`, lowered onto `Zero\Crypto`.
    NodeCrypto,
}

impl BuiltinModule {
    /// The functions the module exports in Zero code.
    pub const fn exports(self) -> &'static [&'static str] {
        match self {
            BuiltinModule::NodeCrypto => &[
                "createHash",
                "createHmac",
                "randomBytes",
                "randomUUID",
                "timingSafeEqual",
            ],
        }
    }
}

/// The one lookup for Node built-in modules, shared by `import ... from` and `require(...)`.
pub fn module(spec: &str) -> Option<BuiltinModule> {
    match spec {
        "node:crypto" | "crypto" => Some(BuiltinModule::NodeCrypto),
        _ => None,
    }
}

#[derive(Clone, Copy)]
pub enum Phase {
    Evaluator,
    Emitter,
    Runtime,
}

#[derive(Clone, Copy)]
pub struct Arity {
    pub min: usize,
    pub max: usize,
}

#[derive(Clone, Copy)]
pub struct Contract {
    pub overload: &'static str,
    pub evaluator: Option<Arity>,
    pub emitter: Option<Arity>,
    pub runtime: Option<Arity>,
}

const fn arity(min: usize, max: usize) -> Option<Arity> {
    Some(Arity { min, max })
}
const VARIADIC: usize = usize::MAX;

/// Surface is `global`, a namespace, or an instance family (`string`, `array`, ...).
/// The runtime column describes checked JavaScript dispatch as well as typed helpers.
const fn overload(
    overload: &'static str,
    evaluator: Option<Arity>,
    emitter: Option<Arity>,
    runtime: Option<Arity>,
) -> Contract {
    Contract {
        overload,
        evaluator,
        emitter,
        runtime,
    }
}

// Columns: surface, names, overload, evaluator arity, emitter arity, runtime arity.
#[rustfmt::skip]
pub const CONTRACTS: &[(&str, &[&str], Contract)] = &[
    ("zero", &["collection", "table", "userProjection"], overload("fields: object, options?: object", arity(1, 2), None, None)),
    ("zero", &["global"], overload("name: string, fields: object, options?: object", arity(2, 3), None, None)),
    ("zero", &["defineRelations"], overload("schema: object, relations: function", arity(2, 2), None, None)),
    ("zero", &["defineConfig", "action", "job", "env"], overload("one configuration or environment name", arity(1, 1), None, None)),
    ("zero", &["files"], overload("name: string, options: object", arity(2, 2), None, None)),
    ("zero", &["id"], overload("no arguments", arity(0, 0), None, None)),
    ("zero", &["relation"], overload("options?: object", arity(0, 1), None, None)),
    ("field", &["integer", "real", "text", "boolean", "date", "timestamp", "json", "email", "url", "slug", "richText", "media"], overload("options?: object", arity(0, 1), None, None)),
    ("field", &["file", "select", "group", "repeater", "blocks"], overload("options: object", arity(1, 1), None, None)),
    ("column", &["localized", "notNull", "unique", "primaryKey", "defaultNow", "$type"], overload("no runtime arguments", arity(0, 0), None, None)),
    ("column", &["access", "showIf", "ui", "min", "max", "pattern", "validate", "default", "$default", "$defaultFn", "$onUpdate", "$onUpdateFn"], overload("one value or callback", arity(1, 1), None, None)),
    ("column", &["references"], overload("target: function, options?: object", arity(1, 2), None, None)),
    ("constructor", &["HttpError"], overload("runtime-only status: number, code: string, message: string, details?: object", None, arity(3, 4), None)),
    ("db", &["insert", "update", "delete"], overload("collection: static registered Collection reference", None, arity(1, 1), None)),
    ("db.builder", &["values", "set"], overload("one values or changes object", None, arity(1, 1), None)),
    ("db.builder", &["where"], overload("one filter object; updates require set(...) first", None, arity(1, 1), None)),
    ("db.builder", &["returning"], overload("no arguments", None, arity(0, 0), None)),
    ("global", &["String", "Number", "Boolean"], overload("value?: unknown", arity(0, 1), arity(0, 1), arity(0, 1))),
    ("global", &["BigInt"], overload("value: bigint|number|string|boolean (runtime only)", None, arity(1, 1), arity(1, 1))),
    ("BigInt", &["asIntN", "asUintN"], overload("bits: number, value: bigint (runtime only)", None, arity(2, 2), arity(2, 2))),
    ("bigint", &["toString"], overload("radix?: number (2..36)", None, arity(0, 1), arity(0, 1))),
    ("bigint", &["valueOf"], overload("no arguments", None, arity(0, 0), arity(0, 0))),
    ("bigint", &["toLocaleString"], overload("refused: locale formatting depends on the host", None, None, None)),
    ("global", &["parseInt"], overload("text: string, radix?: number; other than base 10/powers of two: safe integers only", arity(1, 2), arity(1, 2), arity(0, 2))),
    ("global", &["parseFloat"], overload("text: string", arity(1, 1), arity(1, 1), arity(0, 1))),
    ("global", &["isFinite", "isNaN"], overload("value?: unknown", arity(0, 1), arity(0, 1), arity(0, 1))),
    ("global", &["encodeURI", "encodeURIComponent"], overload("text: string", arity(1, 1), arity(1, 1), arity(1, 1))),
    ("global", &["structuredClone"], overload("value: data", None, arity(1, 1), arity(1, 1))),
    ("global", &["Error", "TypeError", "RangeError"], overload("runtime-only message?: string; JavaScript: options without cause", None, arity(0, 1), arity(0, 2))),
    ("Math", &["min", "max"], overload("...values: number[]", arity(0, VARIADIC), arity(0, VARIADIC), arity(0, VARIADIC))),
    ("Math", &["floor", "ceil", "abs", "sqrt", "trunc", "round"], overload("value: number", arity(0, 1), arity(1, 1), arity(0, 1))),
    ("Math", &["sign"], overload("value: number", None, arity(1, 1), arity(0, 1))),
    ("Math", &["log", "exp"], overload("value: number; exact identities and IEEE special values only", None, arity(1, 1), arity(0, 1))),
    ("Math", &["pow"], overload("base: number, exponent: number; exact powers, square roots and IEEE special values only", arity(0, 2), arity(2, 2), arity(0, 2))),
    ("Math", &["random"], overload("no arguments", None, arity(0, 0), arity(0, 0))),
    ("Date", &["now"], overload("no arguments", None, arity(0, 0), arity(0, 0))),
    ("Object", &["keys", "values", "entries"], overload("value: object", arity(1, 1), arity(1, 1), arity(1, 1))),
    ("Object", &["fromEntries"], overload("entries: [key, value][]", arity(1, 1), arity(1, 1), arity(1, 1))),
    ("Object", &["hasOwn"], overload("receiver: data, key: propertyKey (evaluator: primitive)", arity(2, 2), arity(2, 2), arity(2, 2))),
    ("Object", &["defineProperty"], overload("runtime-only target: plain object, key: symbol | string, descriptor: data descriptor", None, None, arity(3, 3))),
    ("Object", &["freeze"], overload("refused: object freezing is not modeled", None, None, None)),
    ("Object", &["assign"], overload("target: object | function, ...sources: object (build time only)", arity(1, VARIADIC), None, None)),
    ("Array", &["isArray"], overload("value?: unknown", arity(0, 1), arity(1, 1), arity(0, 1))),
    ("Array", &["from"], overload("source: iterable | {length: number}, mapper?: function", arity(1, 2), arity(1, 2), arity(1, 2))),
    ("Array", &["of"], overload("...values: unknown[]", None, None, arity(0, VARIADIC))),
    ("String", &["raw"], overload("template: { raw: string[] }, ...substitutions: unknown[]", arity(1, VARIADIC), arity(1, VARIADIC), arity(1, VARIADIC))),
    ("Number", &["isInteger", "isFinite"], overload("value?: unknown", arity(0, 1), arity(0, 1), arity(0, 1))),
    ("Number", &["isNaN"], overload("value?: unknown", None, None, arity(0, 1))),
    ("Number", &["parseInt"], overload("text: string, radix?: number; other than base 10/powers of two: safe integers only", None, arity(1, 2), arity(0, 2))),
    ("Number", &["parseFloat"], overload("text: string", None, arity(1, 1), arity(0, 1))),
    ("JSON", &["stringify"], overload("runtime-only value: data, null|undefined, space?: number|string", None, arity(1, 3), arity(0, 3))),
    ("JSON", &["parse"], overload("text: string, reviver?: null|undefined", None, arity(1, 2), arity(1, 2))),
    ("console", &["log", "info", "warn", "error", "debug"], overload("...values: unknown[]", None, arity(0, VARIADIC), arity(0, VARIADIC))),
    ("string", &["toLowerCase", "toUpperCase", "trim", "trimStart", "trimEnd", "toString"], overload("no arguments", arity(0, 0), arity(0, 0), arity(0, 0))),
    ("string", &["includes", "startsWith", "endsWith"], overload("search: string; runtime: search, position?: number", arity(1, 1), arity(1, 1), arity(0, 2))),
    ("string", &["slice"], overload("start?: number, end?: number", arity(0, 2), arity(0, 2), arity(0, 2))),
    ("string", &["split"], overload("separator: string, limit?: number", arity(1, 2), arity(0, 2), arity(0, 2))),
    ("string", &["repeat"], overload("count: number", arity(1, 1), arity(1, 1), arity(0, 1))),
    ("string", &["replace", "replaceAll"], overload("pattern: string|RegExp, replacement: string|function (evaluator: string)", arity(2, 2), arity(2, 2), arity(2, 2))),
    ("string", &["concat"], overload("...values: string[]", None, arity(0, VARIADIC), arity(0, VARIADIC))),
    ("string", &["indexOf", "lastIndexOf"], overload("search: string, position?: number", None, arity(1, 2), arity(0, 2))),
    ("string", &["substring"], overload("start?: number, end?: number", None, arity(0, 2), arity(0, 2))),
    ("string", &["padStart", "padEnd"], overload("target: number, fill?: string", None, arity(1, 2), arity(1, 2))),
    ("string", &["charAt"], overload("index?: number", None, arity(0, 1), arity(0, 1))),
    ("string", &["at"], overload("index: number", None, arity(1, 1), arity(0, 1))),
    ("string", &["charCodeAt"], overload("index?: number", None, None, arity(0, 1))),
    ("string", &["match"], overload("pattern: RegExp", None, arity(1, 1), arity(0, 1))),
    ("string", &["localeCompare"], overload("refused: locale collation depends on the host", None, None, None)),
    ("number", &["toFixed"], overload("digits?: number (0..100)", None, arity(0, 1), arity(0, 1))),
    ("global", &["RegExp"], overload("pattern?: string|RegExp, flags?: string", None, arity(0, 2), arity(0, 2))),
    ("constructor", &["RegExp"], overload("pattern?: string|RegExp, flags?: string", None, arity(0, 2), arity(0, 2))),
    ("constructor", &["Date"], overload("value?: unknown; primitive conversion, ISO date strings only", None, arity(0, 1), arity(0, 1))),
    ("constructor", &["Error", "TypeError", "RangeError"], overload("runtime-only message?: string; options without cause", None, arity(0, 2), arity(0, 2))),
    ("constructor", &["Map", "Set"], overload("entries?: iterable (evaluator: array of static data; read-only)", arity(0, 1), arity(0, 1), arity(0, 1))),
    ("constructor", &["WeakMap", "WeakSet"], overload("entries?: iterable of object keys (evaluator: none)", arity(0, 0), None, arity(0, 1))),
    ("constructor", &["Symbol"], overload("refused: Symbol is not a constructor; call Symbol(description)", None, None, None)),
    ("global", &["Symbol"], overload("description?: unknown", arity(0, 1), None, arity(0, 1))),
    ("Symbol", &["for"], overload("key: string", arity(1, 1), None, arity(0, 1))),
    ("Symbol", &["keyFor"], overload("symbol: symbol", arity(1, 1), None, arity(0, 1))),
    ("symbol", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("weakmap", &["get", "has", "delete"], overload("key: object", None, None, arity(0, 1))),
    ("weakmap", &["set"], overload("key: object, value: unknown", None, None, arity(0, 2))),
    ("weakset", &["add", "has", "delete"], overload("value: object", None, None, arity(0, 1))),
    ("constructor", &["Promise"], overload("executor: (resolve, reject) => void; imported JavaScript only", None, None, arity(1, 1))),
    ("constructor", &["ReadableStream", "WritableStream"], overload("source?: object, strategy?: { highWaterMark }; imported JavaScript only", None, None, arity(0, 2))),
    ("constructor", &["TransformStream"], overload("transformer?: object; imported JavaScript only", None, None, arity(0, 1))),
    ("Promise", &["resolve", "reject"], overload("value?: unknown; imported JavaScript only", None, None, arity(0, 1))),
    ("Promise", &["all", "allSettled", "any", "race"], overload("values: iterable; imported JavaScript only", None, None, arity(1, 1))),
    ("Promise", &["withResolvers"], overload("no arguments; imported JavaScript only", None, None, arity(0, 0))),
    ("promise", &["then"], overload("onFulfilled?: function, onRejected?: function", None, None, arity(0, 2))),
    ("promise", &["catch", "finally"], overload("callback?: function", None, None, arity(0, 1))),
    ("generator", &["next", "return", "throw"], overload("value?: unknown", None, None, arity(0, 1))),
    ("number", &["toString"], overload("decimal only", arity(0, 0), arity(0, 0), arity(0, 0))),
    ("array", &["map", "filter", "find", "findIndex", "some", "every", "flatMap", "forEach"], overload("callback: function (thisArg refused)", arity(1, 1), arity(1, 1), arity(1, 1))),
    ("array", &["findLast", "findLastIndex"], overload("callback: function (thisArg refused)", None, arity(1, 1), arity(1, 1))),
    ("array", &["reduce"], overload("callback: function, initial?: unknown", arity(1, 2), arity(1, 2), arity(1, 2))),
    ("array", &["includes", "indexOf"], overload("value: unknown, from?: number", arity(1, 2), arity(1, 2), arity(0, 2))),
    ("array", &["lastIndexOf"], overload("value: unknown, from?: number", None, arity(1, 2), arity(0, 2))),
    ("array", &["join"], overload("separator?: string", arity(0, 1), arity(0, 1), arity(0, 1))),
    ("array", &["slice"], overload("start?: number, end?: number", arity(0, 2), arity(0, 2), arity(0, 2))),
    ("array", &["concat", "push"], overload("...values: unknown[]", arity(0, VARIADIC), arity(0, VARIADIC), arity(0, VARIADIC))),
    ("array", &["unshift"], overload("...values: unknown[]", None, arity(0, VARIADIC), arity(0, VARIADIC))),
    ("array", &["pop", "shift", "reverse", "toReversed"], overload("no arguments; JavaScript dispatch ignores extra arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("array", &["sort", "toSorted"], overload("compare?: numeric key-difference function; finite primitive keys", None, arity(0, 1), arity(0, 1))),
    ("array", &["flat"], overload("depth?: 0 | 1 | null (typed; undefined defaults to 1); depth?: number (checked JavaScript)", None, arity(0, 1), arity(0, 1))),
    ("array", &["fill"], overload("value: unknown, start?: number, end?: number", None, arity(1, 3), arity(1, 3))),
    ("array", &["splice"], overload("start: number, deleteCount?: number, ...values: unknown[]", None, arity(1, VARIADIC), arity(0, VARIADIC))),
    ("array", &["at"], overload("index: number", None, arity(1, 1), arity(0, 1))),
    ("date", &["toISOString", "toJSON", "getTime", "valueOf"], overload("no arguments", None, arity(0, 0), arity(0, 0))),
    ("regex", &["test"], overload("text: string", None, arity(1, 1), arity(0, 1))),
    ("regex", &["exec"], overload("text: string", None, None, arity(0, 1))),
    ("set", &["has"], overload("value: unknown", arity(1, 1), arity(1, 1), arity(0, 1))),
    ("set", &["add", "delete"], overload("value: unknown", None, arity(1, 1), arity(0, 1))),
    ("map", &["get", "has"], overload("key: unknown", arity(1, 1), arity(1, 1), arity(0, 1))),
    ("map", &["delete"], overload("key: unknown", None, arity(1, 1), arity(0, 1))),
    ("map", &["set"], overload("key: unknown, value: unknown", None, arity(2, 2), arity(0, 2))),
    ("set", &["clear", "keys", "values", "entries"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("map", &["clear", "keys", "values", "entries"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("set", &["forEach"], overload("callback: function (thisArg refused)", None, arity(1, 1), arity(1, 2))),
    ("map", &["forEach"], overload("callback: function (thisArg refused)", None, arity(1, 1), arity(1, 2))),
    ("array", &["keys", "values", "entries"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("iterator", &["next"], overload("no arguments", None, None, arity(0, VARIADIC))),
    ("constructor", &["AbortController"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("abortcontroller", &["abort"], overload("reason?: unknown", None, arity(0, 1), arity(0, 1))),
    ("abortsignal", &["throwIfAborted"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("abortsignal", &["addEventListener", "removeEventListener"], overload("type: \"abort\", listener: function, options?: { once?: boolean } | boolean", None, arity(2, 3), arity(2, 3))),
    ("abortsignal", &["dispatchEvent"], overload("refused: synthetic events are not modeled", None, None, None)),
    ("AbortSignal", &["abort"], overload("reason?: unknown", None, arity(0, 1), arity(0, 1))),
    ("AbortSignal", &["timeout"], overload("ms: number; fires only while the request waits", None, arity(1, 1), arity(1, 1))),
    ("AbortSignal", &["any"], overload("signals: AbortSignal[]", None, arity(1, 1), arity(1, 1))),
    ("global", &["setTimeout"], overload("callback: function, delay?: number, ...args; runs only while the request waits", None, arity(1, VARIADIC), arity(1, VARIADIC))),
    ("global", &["clearTimeout"], overload("id?: number", None, arity(0, 1), arity(0, 1))),
    ("constructor", &["URL"], overload("url: string|URL, base?: string|URL; WHATWG URL Standard", None, arity(1, 2), arity(1, 2))),
    ("constructor", &["URLSearchParams"], overload("init?: string | [name, value][] | Record<string, string>", None, arity(0, 1), arity(0, 1))),
    ("URL", &["canParse", "parse"], overload("url: string|URL, base?: string|URL", None, arity(1, 2), arity(1, 2))),
    ("url", &["toString", "toJSON"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("urlsearchparams", &["get", "getAll"], overload("name: string", None, arity(1, 1), arity(1, 1))),
    ("urlsearchparams", &["has", "delete"], overload("name: string, value?: string", None, arity(1, 2), arity(1, 2))),
    ("urlsearchparams", &["append", "set"], overload("name: string, value: string", None, arity(2, 2), arity(2, 2))),
    ("urlsearchparams", &["sort", "toString", "keys", "values", "entries"], overload("no arguments", None, arity(0, 0), arity(0, VARIADIC))),
    ("urlsearchparams", &["forEach"], overload("callback: function (thisArg refused)", None, arity(1, 1), arity(1, 2))),
    ("URL", &["createObjectURL", "revokeObjectURL"], overload("refused: blob URLs need a document", None, None, None)),
    ("string", &["valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("number", &["valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("array", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("regex", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("set", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("map", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("object", &["constructor", "hasOwnProperty", "isPrototypeOf", "propertyIsEnumerable", "toLocaleString", "__defineGetter__", "__defineSetter__", "__lookupGetter__", "__lookupSetter__", "__proto__"], overload("inherited Object prototype properties are unsupported", None, None, None)),
    ("object", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("boolean", &["toString", "valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),
    ("function", &["call"], overload("thisArg?: unknown, ...arguments: unknown[]", None, None, arity(0, VARIADIC))),
    ("function", &["apply"], overload("thisArg?: unknown, arguments?: array-like", None, None, arity(0, 2))),
    ("function", &["valueOf"], overload("no semantic arguments", None, None, arity(0, VARIADIC))),

    ("array", &["copyWithin", "reduceRight", "toSpliced", "with", "toLocaleString"], overload("unimplemented native methods are refused", None, None, None)),
    ("string", &["anchor", "big", "blink", "bold", "codePointAt", "fontcolor", "fontsize", "fixed", "isWellFormed", "italics", "link", "matchAll", "normalize", "search", "small", "strike", "sub", "substr", "sup", "toWellFormed", "trimLeft", "trimRight", "toLocaleLowerCase", "toLocaleUpperCase"], overload("unimplemented native methods are refused", None, None, None)),
    ("number", &["toExponential", "toPrecision", "toLocaleString"], overload("unimplemented native methods are refused", None, None, None)),
    ("regex", &["compile"], overload("unimplemented native methods are refused", None, None, None)),
    ("date", &["toString", "toDateString", "toTimeString", "toUTCString", "toGMTString", "getDate", "setDate", "getDay", "getFullYear", "setFullYear", "getHours", "setHours", "getMilliseconds", "setMilliseconds", "getMinutes", "setMinutes", "getMonth", "setMonth", "getSeconds", "setSeconds", "setTime", "getTimezoneOffset", "getUTCDate", "setUTCDate", "getUTCDay", "getUTCFullYear", "setUTCFullYear", "getUTCHours", "setUTCHours", "getUTCMilliseconds", "setUTCMilliseconds", "getUTCMinutes", "setUTCMinutes", "getUTCMonth", "setUTCMonth", "getUTCSeconds", "setUTCSeconds", "getYear", "setYear", "toLocaleString", "toLocaleDateString", "toLocaleTimeString", "toTemporalInstant"], overload("unimplemented native methods are refused", None, None, None)),
    ("set", &["difference", "intersection", "isSubsetOf", "isSupersetOf", "isDisjointFrom", "symmetricDifference", "union"], overload("unimplemented native methods are refused", None, None, None)),
    ("map", &["getOrInsert", "getOrInsertComputed"], overload("unimplemented native methods are refused", None, None, None)),
    ("function", &["bind", "toString"], overload("unimplemented native methods are refused", None, None, None)),
    ("Math", &["acos", "acosh", "asin", "asinh", "atan", "atanh", "atan2", "cbrt", "expm1", "clz32", "cos", "cosh", "fround", "hypot", "imul", "log1p", "log2", "log10", "sin", "sinh", "tan", "tanh", "f16round"], overload("unimplemented native methods are refused", None, None, None)),
];

/// Only a numeric key difference has engine-independent ordering without callback effects.
pub fn sort_key(f: &crate::ir::Func) -> Option<(Vec<String>, bool)> {
    use crate::ir::{BinOp, Expr, ExprKind, FnBody, Pat, Stmt};
    fn path(e: &Expr) -> Option<(&str, Vec<String>)> {
        match &e.kind {
            ExprKind::Ident(name) => Some((name, Vec::new())),
            ExprKind::Member {
                obj,
                prop,
                optional: false,
                ..
            } => {
                let (name, mut keys) = path(obj)?;
                keys.push(prop.clone());
                Some((name, keys))
            }
            ExprKind::As(e, _) | ExprKind::NonNull(e) => path(e),
            _ => None,
        }
    }
    if f.is_async || f.params.len() != 2 || f.params.iter().any(|p| p.default.is_some() || p.rest) {
        return None;
    }
    let (Pat::Id(a, _), Pat::Id(b, _)) = (&f.params[0].pat, &f.params[1].pat) else {
        return None;
    };
    let expr = match &f.body {
        FnBody::Expr(e) => e,
        FnBody::Block(stmts) => match stmts.as_slice() {
            [Stmt::Return(Some(e), _)] => e,
            _ => return None,
        },
    };
    let ExprKind::Binary(BinOp::Sub, left, right) = &expr.kind else {
        return None;
    };
    let (ln, lp) = path(left)?;
    let (rn, rp) = path(right)?;
    if lp != rp {
        return None;
    }
    if ln == a && rn == b {
        Some((lp, false))
    } else if ln == b && rn == a {
        Some((lp, true))
    } else {
        None
    }
}

pub fn check_sort_callback(args: &[crate::ir::ArrEl]) -> Result<(), Diag> {
    use crate::ir::{ArrEl, ExprKind};
    if let Some(ArrEl::Item(e)) = args.first()
        && let ExprKind::Func(f) = &e.kind
        && sort_key(f).is_none()
    {
        return Err(Diag::new(e.span, "sorting requires a numeric key-difference comparator")
            .help("use (a, b) => a - b or (a, b) => a.rank - b.rank; effectful and arbitrary comparators aren't supported"));
    }
    Ok(())
}

pub fn check_date_literal(args: &[crate::ir::ArrEl], span: Span) -> Result<(), Diag> {
    use crate::ir::{ArrEl, ExprKind};
    let Some(ArrEl::Item(expr)) = args.first() else {
        return Ok(());
    };
    let ExprKind::Str(text) = &expr.kind else {
        return Ok(());
    };
    let syntax = regex::Regex::new(
        r"^(?:([+-][0-9]{6}|[0-9]{4})(?:-[0-9]{2}(?:-[0-9]{2})?)?|([+-][0-9]{6}|[0-9]{4})-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?(?:Z|[+-][0-9]{2}:[0-9]{2})?)$",
    )
    .expect("ISO date grammar");
    if !syntax.is_match(text) || text.starts_with("-000000") {
        return Err(Diag::new(span, "Date strings require the supported ISO format")
            .help("pass an ISO date/time string or an epoch timestamp; legacy date parsing isn't supported"));
    }
    Ok(())
}

pub fn contains_typed_date(ty: &crate::types::Ty) -> bool {
    use crate::types::Ty;
    match ty {
        Ty::Date => true,
        Ty::Obj(fields) => fields.values().any(|(ty, _)| contains_typed_date(ty)),
        Ty::Array(t) | Ty::NonEmptyArray(t) | Ty::Map(t) | Ty::Instance(_, t) => contains_typed_date(t),
        Ty::Tuple(items) | Ty::ArrayLiteral(items) | Ty::Union(items) => items.iter().any(contains_typed_date),
        _ => false,
    }
}

pub fn check_string_coercion_type(ty: &crate::types::Ty, span: Span) -> Result<(), Diag> {
    use crate::types::Ty;
    fn function(ty: &Ty) -> bool {
        match ty {
            Ty::Fn(..) => true,
            Ty::Array(t) | Ty::NonEmptyArray(t) => function(t),
            Ty::Tuple(items) | Ty::ArrayLiteral(items) | Ty::Union(items) => items.iter().any(function),
            _ => false,
        }
    }
    if contains_typed_date(ty) {
        return Err(Diag::new(span, "typed Date string coercion isn't supported")
            .help("call toISOString() or toJSON() explicitly"));
    }
    if function(ty) {
        return Err(Diag::new(span, "function string coercion isn't supported")
            .help("pass an explicit string; function source text is not preserved"));
    }
    Ok(())
}

pub fn check_json_serialization_type(ty: &crate::types::Ty, span: Span) -> Result<(), Diag> {
    use crate::types::Ty;
    fn custom(ty: &Ty) -> bool {
        match ty {
            Ty::Obj(fields) => fields.iter().any(|(key, (ty, _))| {
                key == "toJSON" && !ty.all(|t| !matches!(t, Ty::Fn(..) | Ty::Unknown | Ty::Dynamic)) || custom(ty)
            }),
            Ty::Array(t) | Ty::NonEmptyArray(t) | Ty::Map(t) | Ty::Instance(_, t) => custom(t),
            Ty::Tuple(items) | Ty::ArrayLiteral(items) | Ty::Union(items) => items.iter().any(custom),
            _ => false,
        }
    }
    if contains_typed_date(ty) {
        return Err(Diag::new(span, "typed Dates require explicit JSON serialization")
            .help("call toISOString() or toJSON() before JSON.stringify or returning a Date from an action"));
    }
    if custom(ty) {
        Err(Diag::new(span, "custom toJSON callbacks aren't supported")
            .help("serialize the callback's result explicitly before JSON.stringify or returning data from an action"))
    } else {
        Ok(())
    }
}

pub const UNSAFE_RADIX: &str = "parseInt with this radix requires a safe integer result";

pub fn check_parse_int_literals(args: &[crate::ir::ArrEl], span: Span) -> Result<(), Diag> {
    use crate::ir::{ArrEl, ExprKind, UnOp};
    let Some(ArrEl::Item(text)) = args.first() else {
        return Ok(());
    };
    let ExprKind::Str(text) = &text.kind else {
        return Ok(());
    };
    let radix = match args.get(1) {
        None => 0,
        Some(ArrEl::Item(expr)) => match &expr.kind {
            ExprKind::Undefined | ExprKind::Null => 0,
            ExprKind::Num(n) => crate::num::to_int32(*n),
            ExprKind::Unary(UnOp::Neg, expr) => match expr.kind {
                ExprKind::Num(n) => crate::num::to_int32(-n),
                _ => return Ok(()),
            },
            _ => return Ok(()),
        },
        _ => return Ok(()),
    };
    if crate::num::parse_int(text, radix).is_none() {
        return Err(Diag::new(span, UNSAFE_RADIX).help("use base 10, a power-of-two base, or a safe integer result"));
    }
    Ok(())
}

pub fn power_diagnostic(span: Span) -> Diag {
    Diag::new(span, crate::num::UNSUPPORTED_POWER)
        .help("use a safe integer power, an integral power of a binary base, a square root, or compute outside Zero")
}

pub fn check_power(a: f64, b: f64, span: Span) -> Result<(), Diag> {
    if crate::num::pow(a, b).is_none() {
        return Err(power_diagnostic(span));
    }
    Ok(())
}

fn literal_number(e: &crate::ir::Expr) -> Option<f64> {
    use crate::ir::{ExprKind, UnOp};
    match &e.kind {
        ExprKind::Num(n) => Some(*n),
        ExprKind::Unary(UnOp::Neg, e) => literal_number(e).map(|n| -n),
        ExprKind::Unary(UnOp::Plus, e) | ExprKind::As(e, _) | ExprKind::NonNull(e) => literal_number(e),
        ExprKind::Ident(n) if n == "NaN" => Some(f64::NAN),
        ExprKind::Ident(n) if n == "Infinity" => Some(f64::INFINITY),
        _ => None,
    }
}

pub fn check_transcendental_literals(method: &str, args: &[crate::ir::ArrEl], span: Span) -> Result<(), Diag> {
    if let Some(crate::ir::ArrEl::Item(e)) = args.first()
        && let Some(x) = literal_number(e)
        && crate::num::transcendental(method, x).is_none()
    {
        return Err(Diag::new(span, format!("Math.{method} requires an IEEE special value or an exact identity; engine-dependent rounding isn't supported"))
            .help("compute this value outside Zero"));
    }
    Ok(())
}

pub fn check_power_literals(args: &[crate::ir::ArrEl], span: Span) -> Result<(), Diag> {
    use crate::ir::ArrEl;
    if let [ArrEl::Item(a), ArrEl::Item(b)] = args
        && let (Some(a), Some(b)) = (literal_number(a), literal_number(b))
    {
        check_power(a, b, span)?;
    }
    Ok(())
}

pub fn contract(surface: &str, name: &str) -> Option<Contract> {
    CONTRACTS
        .iter()
        .find(|(s, names, _)| *s == surface && names.contains(&name))
        .map(|(_, _, c)| *c)
}

pub fn check(surface: &str, name: &str, count: usize, phase: Phase, span: Span) -> Result<(), Diag> {
    let Some(c) = contract(surface, name) else {
        return Ok(());
    };
    if matches!(phase, Phase::Evaluator) && surface == "Date" && name == "now" {
        return Err(Diag::new(
            span,
            "`Date.now()` isn't available while the config is evaluated (builds must be repeatable)",
        )
        .code(crate::diag::Code::BuildTimeClock)
        .help("compute the time in a hook or use timestamp().defaultNow()"));
    }
    let accepted = match phase {
        Phase::Evaluator => c.evaluator,
        Phase::Emitter => c.emitter,
        Phase::Runtime => c.runtime,
    };
    if accepted.is_some_and(|a| (a.min..=a.max).contains(&count)) {
        return Ok(());
    }
    let message = if surface == "string" && matches!(name, "includes" | "startsWith" | "endsWith") && count > 1 {
        format!("`{name}()` with a position isn't supported")
    } else if name == "localeCompare" {
        "localeCompare() isn't supported: locale collation depends on the host".into()
    } else {
        format!("unsupported overload of `{surface}.{name}` ({count} arguments)")
    };
    Err(Diag::new(span, message).help(format!("accepted contract: {}", c.overload)))
}

fn consuming_group_body(body: &str) -> bool {
    let body = if let Some(named) = body.strip_prefix("?<") {
        let Some((_, body)) = named.split_once('>') else {
            return false;
        };
        body
    } else {
        body.strip_prefix("?:").unwrap_or(body)
    };
    let mut in_class = false;
    let mut escaped = false;
    for c in body.chars() {
        if escaped {
            if !in_class && matches!(c, 'b' | 'B' | 'k' | '1'..='9') {
                return false;
            }
            escaped = false;
        } else if c == '\\' {
            escaped = true;
        } else if c == '[' {
            in_class = true;
        } else if c == ']' {
            in_class = false;
        } else if !in_class && matches!(c, '^' | '$') {
            return false;
        }
    }
    regex::Regex::new(body).is_ok_and(|re| !re.is_match(""))
}

/// ECMAScript EscapeRegExpPattern, used by source and string conversion.
pub fn regex_source(pattern: &str) -> String {
    if pattern.is_empty() {
        return "(?:)".into();
    }
    let mut out = String::new();
    let mut escaped = false;
    let mut in_class = false;
    for c in pattern.chars() {
        let line = match c {
            '\n' => Some("\\n"),
            '\r' => Some("\\r"),
            '\u{2028}' => Some("\\u2028"),
            '\u{2029}' => Some("\\u2029"),
            _ => None,
        };
        if let Some(line) = line {
            if escaped {
                out.pop();
            }
            out.push_str(line);
            escaped = false;
            continue;
        }
        if !escaped {
            match c {
                '[' => in_class = true,
                ']' => in_class = false,
                '/' if !in_class => out.push('\\'),
                _ => {}
            }
        }
        out.push(c);
        escaped = c == '\\' && !escaped;
    }
    out
}

pub fn check_regex_literals(args: &[crate::ir::ArrEl], span: Span) -> Result<(), Diag> {
    use crate::ir::{ArrEl, ExprKind};
    let Some(ArrEl::Item(pattern)) = args.first() else {
        return Ok(());
    };
    let ExprKind::Str(pattern) = &pattern.kind else {
        return Ok(());
    };
    let flags = match args.get(1) {
        None => "",
        Some(ArrEl::Item(flags)) => match &flags.kind {
            ExprKind::Str(flags) => flags,
            ExprKind::Undefined => "",
            _ => return Ok(()),
        },
        _ => return Ok(()),
    };
    check_regex(pattern, flags, span)
}

/// Refuse engine-specific capture retention rather than emulate a regex engine.
pub fn check_regex(pattern: &str, flags: &str, span: Span) -> Result<(), Diag> {
    if !flags.contains('u') && pattern.chars().any(|c| u32::from(c) > 0xffff) {
        return Err(Diag::new(span, "astral regex literals require Unicode mode")
            .help("add the u flag so each pattern atom is a Unicode code point"));
    }
    if flags.contains('i') && !pattern.is_ascii() {
        return Err(Diag::new(span, "ignore-case regexes require ASCII source and input")
            .help("remove ignore-case matching or use ASCII source and input"));
    }
    let bytes = pattern.as_bytes();
    let mut stack = Vec::new();
    let mut captures = 0;
    let mut in_class = false;
    let mut class_dash = false;
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'\\' => {
                if flags.contains('i') && bytes.get(i + 1).is_some_and(|c| matches!(c, b'u' | b'x')) {
                    return Err(
                        Diag::new(span, "ignore-case regexes don't support encoded character escapes")
                            .help("remove ignore-case matching or use literal ASCII characters"),
                    );
                }
                if in_class
                    && bytes
                        .get(i + 1)
                        .is_some_and(|c| matches!(c, b'd' | b'D' | b'w' | b'W' | b's' | b'S'))
                    && (class_dash || bytes.get(i + 2) == Some(&b'-'))
                {
                    return Err(
                        Diag::new(span, "character-class escapes adjacent to hyphens aren't supported")
                            .help("escape a literal hyphen with \\- or spell out the character range"),
                    );
                }
                if bytes.get(i + 1) == Some(&b'u') {
                    let rest = &pattern[i + 2..];
                    let braced = flags.contains('u') && rest.starts_with('{');
                    let hex = if braced {
                        rest.strip_prefix('{')
                            .and_then(|s| s.split_once('}'))
                            .map(|(hex, _)| hex)
                    } else {
                        rest.get(..4)
                    };
                    if let Some(cp) = hex.and_then(|hex| u32::from_str_radix(hex, 16).ok())
                        && (0xd800..=0xdfff).contains(&cp)
                    {
                        if !flags.contains('u') {
                            return Err(Diag::new(span, "surrogate regex escapes require Unicode mode")
                                .help("add the u flag or use a Unicode scalar character"));
                        }
                        if in_class {
                            let paired = !braced
                                && cp <= 0xdbff
                                && pattern.get(i + 6..i + 8) == Some("\\u")
                                && pattern
                                    .get(i + 8..i + 12)
                                    .and_then(|hex| u32::from_str_radix(hex, 16).ok())
                                    .is_some_and(|lo| (0xdc00..=0xdfff).contains(&lo));
                            if paired {
                                i += 12;
                                class_dash = false;
                                continue;
                            }
                            return Err(Diag::new(
                                span,
                                "lone surrogate escapes in regex character classes aren't supported",
                            )
                            .help("use a Unicode scalar character or a valid surrogate pair with the u flag"));
                        }
                    }
                }
                class_dash = false;
                if flags.contains('u') && bytes.get(i + 1).is_some_and(|c| matches!(c, b'p' | b'P')) {
                    return Err(Diag::new(span, "Unicode regex properties aren't supported")
                        .help("use an explicit character class; regex engines use different Unicode versions"));
                }
                i += 1;
            }
            b'[' => {
                in_class = true;
                class_dash = false;
            }
            b']' => {
                in_class = false;
                class_dash = false;
            }
            b'-' if in_class => class_dash = true,
            b'(' if !in_class => {
                let tail = &pattern[i + 1..];
                let special = tail.starts_with('?');
                let named = tail.starts_with("?<") && !tail.starts_with("?<=") && !tail.starts_with("?<!");
                if tail.starts_with('*')
                    || special && !named && !["?:", "?=", "?!", "?<=", "?<!"].iter().any(|p| tail.starts_with(p))
                {
                    return Err(Diag::new(span, "this regex group syntax isn't supported")
                        .help("use JavaScript capture, noncapturing or lookaround groups"));
                }
                stack.push((captures, i));
                if !special || named {
                    captures += 1;
                }
            }
            b')' if !in_class => {
                if let Some((before, start)) = stack.pop() {
                    if captures == before {
                        if bytes.get(i + 1).is_some_and(|b| matches!(b, b'*' | b'+' | b'?' | b'{'))
                            && !consuming_group_body(&pattern[start + 1..i])
                        {
                            return Err(
                                Diag::new(span, "quantified regex groups must have a provably nonempty body")
                                    .help("make each group iteration consume a character or remove its quantifier"),
                            );
                        }
                    } else {
                        if pattern[start + 1..].starts_with("?<=") || pattern[start + 1..].starts_with("?<!") {
                            return Err(Diag::new(span, "capturing lookbehind isn't supported")
                                .help("use noncapturing lookbehind or match the prefix outside the assertion"));
                        }
                        if bytes.get(i + 1).is_some_and(|b| matches!(b, b'*' | b'+' | b'{')) {
                            return Err(
                                Diag::new(span, "repeated regex groups containing captures aren't supported")
                                    .help("remove the capture or repeat the captured text outside the group"),
                            );
                        }
                        if bytes.get(i + 1) == Some(&b'?') && !consuming_group_body(&pattern[start + 1..i]) {
                            return Err(Diag::new(
                                span,
                                "optional regex groups containing captures must have a nonempty body",
                            )
                            .help("remove the optional group or require a nonempty capture"));
                        }
                    }
                }
            }
            _ => class_dash = false,
        }
        i += 1;
    }
    Ok(())
}

#[derive(Clone, Copy)]
pub struct ArrayMutation {
    /// Arguments whose values become receiver elements (excluding index/count arguments).
    inserted_start: usize,
    inserted_limit: Option<usize>,
}

impl ArrayMutation {
    pub fn inserted<T>(self, args: &[T]) -> &[T] {
        let start = self.inserted_start.min(args.len());
        let end = self
            .inserted_limit
            .map_or(args.len(), |n| start.saturating_add(n).min(args.len()));
        &args[start..end]
    }
}

pub fn array_mutation(name: &str) -> Option<ArrayMutation> {
    let (inserted_start, inserted_limit) = match name {
        "push" | "unshift" => (0, None),
        "splice" => (2, None),
        "fill" => (0, Some(1)),
        "pop" | "shift" | "sort" | "reverse" => (0, Some(0)),
        _ => return None,
    };
    Some(ArrayMutation {
        inserted_start,
        inserted_limit,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fmt::Write;

    #[test]
    fn runtime_contract_is_current() {
        let mut php = String::from(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Zero;\n\n// Generated from src/builtins.rs; UPDATE_BUILTINS=1 cargo test --lib builtins::tests\nfinal class Builtins\n{\n    private const ARITIES = [\n",
        );
        let mut keys = std::collections::HashSet::new();
        for (surface, names, c) in CONTRACTS {
            assert!(!c.overload.is_empty());
            for name in *names {
                let key = format!("{surface}.{name}");
                assert!(keys.insert(key.clone()), "duplicate {key}");
                let bounds = c.runtime.map_or("null".into(), |a| {
                    format!(
                        "[{}, {}]",
                        a.min,
                        if a.max == VARIADIC {
                            "PHP_INT_MAX".into()
                        } else {
                            a.max.to_string()
                        }
                    )
                });
                writeln!(php, "        '{key}' => {bounds},").unwrap();
            }
        }
        php.push_str("    ];\n\n    public static function has(string $surface, string $name): bool\n    {\n        $key = $surface . '.' . $name;\n        if (!array_key_exists($key, self::ARITIES)) { return false; }\n        if (self::ARITIES[$key] === null) { throw new JsError('Unsupported built-in ' . $key, 'TypeError'); }\n        return true;\n    }\n\n    public static function check(string $surface, string $name, int $count): void\n    {\n        $key = $surface . '.' . $name;\n        if (!array_key_exists($key, self::ARITIES)) { return; }\n        $arity = self::ARITIES[$key];\n        if ($arity === null || $count < $arity[0] || $count > $arity[1]) {\n            throw new JsError('Unsupported overload of ' . $key . ' (' . $count . ' arguments)', 'TypeError');\n        }\n    }\n}\n");
        let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("runtime/Zero/Builtins.php");
        if std::env::var_os("UPDATE_BUILTINS").is_some() {
            std::fs::write(&path, &php).unwrap();
        }
        assert_eq!(
            std::fs::read_to_string(path).unwrap(),
            php,
            "regenerate the runtime contract"
        );
    }
}
