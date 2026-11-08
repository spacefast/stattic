//! Standard host globals, lowered onto the PHP runtime: the global `fetch` (`Zero\Fetch`),
//! `Response`/`Headers`/`URLSearchParams`, `process.env.NAME` (`Zero\Env`), the global `crypto`'s
//! `randomUUID` and the `node:crypto` subset (`Zero\Crypto`).
//!
//! Every outbound call, crypto call and environment read is reported to [`Compiler::host_use`]
//! before its PHP is emitted, so one function sees all of them. Forms that cannot be lowered
//! soundly are refused here with a location and a fix.
use super::*;
use crate::builtins::BuiltinModule;

const NODE_CRYPTO: &[&str] = BuiltinModule::NodeCrypto.exports();
use crate::types::HostTy;

/// What a host lowering reaches outside the handler.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum HostUse<'n> {
    /// An outbound HTTP request (`fetch`), to the URL's static host when the source names one.
    Fetch(Option<&'n str>),
    /// A `node:crypto` or Web Crypto function, by its standard name.
    Crypto(&'static str),
    /// A runtime variable read (`process.env.NAME`, or a `createHmac` key read from it).
    EnvRead(&'n str),
}

const ALGORITHMS: &[&str] = &["md5", "sha1", "sha256", "sha384", "sha512"];
const ENCODINGS: &[&str] = &["hex", "base64", "base64url"];

impl Compiler<'_, '_> {
    /// The one place every host effect passes through, before its PHP is emitted. A plugin's
    /// effects are recorded here as capabilities (`plugins::KINDS`): `http` with the static host
    /// (`*` when the URL is built at runtime) and `env` with the variable name.
    pub(super) fn host_use(&mut self, what: HostUse<'_>, span: Span) -> Result<()> {
        match what {
            HostUse::Fetch(host) => {
                if self.fx().no_db {
                    return err_help(
                        span,
                        "access rules can't use fetch()",
                        "move it into a hook, action or route",
                    );
                }
                self.uses("http", host.unwrap_or("*"), span);
            }
            HostUse::EnvRead(name) => self.uses("env", name, span),
            // Hashing and random values reach nothing outside the handler; no capability.
            HostUse::Crypto(_) => {}
        }
        Ok(())
    }

    /// A plugin's fetches are checked against its locked hosts on every hop, so a runtime-built
    /// URL or a redirect can't leave the grant. A lock holding `*` answers null: any public host.
    fn fetch_hosts(&self) -> String {
        match &self.plugin {
            Some((i, _)) => format!(", \\ZeroApp\\{}()", self.host_functions[*i]),
            None => String::new(),
        }
    }

    fn is_global(&mut self, e: &Expr, g: &str) -> bool {
        matches!(&e.kind, ExprKind::Ident(n) if n == g)
            && matches!(self.resolve(g, e.span), Ok(Resolved::Global(x)) if x == g)
    }

    fn is_process_env(&mut self, e: &Expr) -> bool {
        match &e.kind {
            ExprKind::Member { obj, prop, .. } if prop == "env" => self.is_global(obj, "process"),
            _ => false,
        }
    }

    /// `process.env.NAME` or `process.env["NAME"]`, possibly asserted (`!`, `as string`).
    fn env_name(&mut self, e: &Expr) -> Option<String> {
        match &e.kind {
            ExprKind::NonNull(x) | ExprKind::As(x, _) => self.env_name(x),
            ExprKind::Member { obj, prop, .. } if self.is_process_env(obj) => Some(prop.clone()),
            ExprKind::Index { obj, index, .. } if self.is_process_env(obj) => match &index.kind {
                ExprKind::Str(s) if is_env_name(s) => Some(s.clone()),
                _ => None,
            },
            _ => None,
        }
    }

    fn env_read(&mut self, name: &str, span: Span) -> Result<Out> {
        self.host_use(HostUse::EnvRead(name), span)?;
        Ok(Out::atom(
            format!("(\\Zero\\Env::get({}) ?? \\Zero\\Js::undefined())", php_str(name)),
            Ty::union([Ty::Str, Ty::Undefined]),
        ))
    }

    /// A `node:crypto` function named by `e`: an imported binding or a namespace member.
    fn node_fn(&mut self, e: &Expr) -> Result<Option<&'static str>> {
        match &e.kind {
            ExprKind::Ident(n) => Ok(match self.resolve(n, e.span) {
                Ok(Resolved::Value(Value::Builtin(Builtin::NodeCrypto(Some(f))), _)) => Some(f),
                _ => None,
            }),
            ExprKind::Member {
                obj, prop, prop_span, ..
            } if self.is_node_namespace(obj) => match NODE_CRYPTO.iter().find(|f| **f == prop) {
                Some(f) => Ok(Some(f)),
                None => Err(unknown_node_crypto(prop, *prop_span)),
            },
            _ => Ok(None),
        }
    }

    fn is_node_namespace(&mut self, e: &Expr) -> bool {
        matches!(&e.kind, ExprKind::Ident(n)
            if matches!(self.resolve(n, e.span), Ok(Resolved::Value(Value::Builtin(Builtin::NodeCrypto(None)), _))))
    }

    /// Reads: `process.env.NAME`, and refusals for every other host member read.
    pub(super) fn host_member(&mut self, obj: &Expr, prop: &str, prop_span: Span) -> Result<Option<Out>> {
        if self.is_process_env(obj) {
            return self.env_read(prop, prop_span).map(Some);
        }
        if self.is_global(obj, "process") {
            return if prop == "env" {
                err_help(
                    Span {
                        end: prop_span.end,
                        ..obj.span
                    },
                    "`process.env` can only be read one variable at a time",
                    "write `process.env.NAME` or `process.env[\"NAME\"]`",
                )
            } else {
                err_help(
                    prop_span,
                    format!("`process.{prop}` isn't available in Zero code"),
                    "handlers run in PHP; only `process.env.NAME` is supported",
                )
            };
        }
        if self.is_global(obj, "crypto") {
            return match prop {
                "subtle" => err_help(
                    prop_span,
                    "`crypto.subtle` isn't available in Zero code",
                    "hash with `createHash(\"sha256\").update(text).digest(\"hex\")` from \"node:crypto\"",
                ),
                "randomUUID" => err_help(
                    prop_span,
                    "`crypto.randomUUID` can't be used as a value",
                    "call it: `crypto.randomUUID()`",
                ),
                _ => self.web_crypto_refusal(prop, prop_span),
            };
        }
        if self.is_global(obj, "Buffer") {
            return err_help(
                prop_span,
                "Buffer values aren't available in Zero code",
                "compare with `timingSafeEqual(Buffer.from(a), Buffer.from(b))` or measure with `Buffer.byteLength(text)`",
            );
        }
        if self.is_node_namespace(obj) {
            return match NODE_CRYPTO.iter().find(|f| **f == prop) {
                Some(f) => err_help(
                    prop_span,
                    format!("`{f}` can't be used as a value"),
                    "call it where it's used",
                ),
                None => Err(unknown_node_crypto(prop, prop_span)),
            };
        }
        Ok(None)
    }

    /// `process.env["NAME"]`; every other computed host read is refused.
    pub(super) fn host_index(&mut self, obj: &Expr, index: &Expr) -> Result<Option<Out>> {
        if self.is_process_env(obj) {
            return match &index.kind {
                ExprKind::Str(s) if is_env_name(s) => self.env_read(s, index.span).map(Some),
                _ => err_help(
                    index.span,
                    "`process.env` needs a literal variable name",
                    "write `process.env.NAME`; a computed name could let request input read any server variable",
                ),
            };
        }
        if ["process", "crypto", "Buffer"].iter().any(|g| self.is_global(obj, g)) {
            return err_help(index.span, "host objects can't be indexed", "use `process.env.NAME`");
        }
        Ok(None)
    }

    /// Writes to `process.env` and other host objects.
    pub(super) fn host_place(&mut self, target: &Expr) -> Result<()> {
        if let ExprKind::Member { obj, .. } | ExprKind::Index { obj, .. } = &target.kind
            && (self.is_process_env(obj) || ["process", "crypto", "Buffer"].iter().any(|g| self.is_global(obj, g)))
        {
            return err_help(
                target.span,
                "`process.env` and host objects are read-only in Zero code",
                "keep the value in a local variable or the database",
            );
        }
        Ok(())
    }

    /// Calls: `fetch`, `crypto.*`, `node:crypto`, `Buffer.byteLength`, and refusals for `require`
    /// and `process.*`. None for every other call.
    pub(super) fn host_call(&mut self, callee: &Expr, args: &[ArrEl], span: Span) -> Result<Option<Out>> {
        match &callee.kind {
            ExprKind::Ident(n) => match self.resolve(n, callee.span) {
                Ok(Resolved::Global("fetch")) => self.fetch_call(args, span).map(Some),
                Ok(Resolved::Global("require")) => err_help(
                    span,
                    "`require` only works at the top level of static CommonJS sources",
                    "use `import`, including `import { createHash } from \"node:crypto\"`",
                ),
                Ok(Resolved::Value(Value::Builtin(Builtin::NodeCrypto(Some(f))), _)) => {
                    self.node_crypto_call(f, args, span).map(Some)
                }
                _ => Ok(None),
            },
            ExprKind::Member {
                obj, prop, prop_span, ..
            } => {
                match prop.as_str() {
                    "digest" => {
                        if let Some(out) = self.digest(obj, args, span)? {
                            return Ok(Some(out));
                        }
                    }
                    "update" if self.hash_chain(callee)?.is_some() => {
                        return err_help(
                            span,
                            "a hash is compiled as one expression",
                            "finish it in place: `createHash(\"sha256\").update(text).digest(\"hex\")`",
                        );
                    }
                    "toString" => {
                        if let ExprKind::Call {
                            callee: inner,
                            args: size,
                            ..
                        } = &obj.kind
                            && self.node_fn(inner)? == Some("randomBytes")
                        {
                            return self.random_bytes(size, args, obj.span, span).map(Some);
                        }
                    }
                    _ => {}
                }
                if self.is_global(obj, "crypto") {
                    return self.web_crypto_call(prop, args, *prop_span, span).map(Some);
                }
                if self.is_global(obj, "Buffer") {
                    return self.buffer_call(prop, args, *prop_span, span).map(Some);
                }
                if self.is_global(obj, "process") || self.is_process_env(obj) {
                    return err_help(
                        *prop_span,
                        format!("`{prop}` isn't available on `process` in Zero code"),
                        "handlers run in PHP; only `process.env.NAME` is supported",
                    );
                }
                if self.is_node_namespace(obj)
                    && let Some(f) = self.node_fn(callee)?
                {
                    return self.node_crypto_call(f, args, span).map(Some);
                }
                Ok(None)
            }
            _ => Ok(None),
        }
    }

    /// `new Headers(init)` and `new URLSearchParams(init)`; other web constructors are refused.
    pub(super) fn host_new(&mut self, callee: &Expr, args: &[ArrEl], span: Span) -> Result<Option<Out>> {
        let ExprKind::Ident(n) = &callee.kind else {
            return Ok(None);
        };
        let Ok(Resolved::Global(g)) = self.resolve(n, callee.span) else {
            return Ok(None);
        };
        let (class, ty, ok): (&str, HostTy, fn(&Ty) -> bool) = match g {
            "Headers" => ("FetchHeaders", HostTy::Headers, header_init),
            "URLSearchParams" => ("SearchParams", HostTy::SearchParams, search_init),
            "URL" => {
                return err_help(
                    span,
                    "`new URL` isn't available in Zero code",
                    "build the URL as a string: encodeURIComponent(part) for path segments, `new URLSearchParams(...).toString()` for the query",
                );
            }
            "Request" => {
                return err_help(
                    span,
                    "`new Request` isn't available in Zero code",
                    "pass the URL and init to `fetch(url, init)`",
                );
            }
            "Response" => {
                return err_help(
                    span,
                    "`new Response` isn't available in Zero code",
                    "return a value, or `{ status, headers, body }` from a route",
                );
            }
            "FormData" | "Blob" => {
                return err_help(
                    span,
                    format!("`{g}` isn't available in Zero code"),
                    "send a string or URLSearchParams body",
                );
            }
            "AbortController" => {
                return err_help(
                    span,
                    "`AbortController` isn't available in Zero code",
                    "every fetch() call already ends after 10 s",
                );
            }
            _ => return Ok(None),
        };
        if args.len() > 1 {
            return err(span, format!("`new {g}` takes at most one argument"));
        }
        let init = match args.first() {
            None => "null".to_string(),
            Some(_) => {
                let e = self.arg(args, 0, span, "an init")?;
                let v = self.expr(e)?;
                let v = self.finish_value(v, e.span)?;
                if !ok(&v.ty) {
                    return err_help(
                        e.span,
                        format!("`new {g}` can't take {}", v.ty.describe()),
                        "pass an object of strings, an array of [name, value] string pairs, or another instance",
                    );
                }
                v.php
            }
        };
        Ok(Some(Out::atom(format!("(new \\Zero\\{class}({init}))"), Ty::Host(ty))))
    }

    /// `fetch(url, init?)`, the standard signature, → `Promise<Response>`.
    fn fetch_call(&mut self, args: &[ArrEl], span: Span) -> Result<Out> {
        if args.is_empty() || args.len() > 2 {
            return err_help(
                span,
                "fetch() takes a URL and an optional init",
                "write fetch(url, { method, headers, body })",
            );
        }
        let url_expr = self.arg(args, 0, span, "a URL")?;
        let host = static_host(url_expr);
        self.host_use(HostUse::Fetch(host.as_deref()), url_expr.span)?;
        let url = self.expr(url_expr)?;
        let url = self.finish_value(url, url_expr.span)?;
        if !text(&url.ty) {
            return err_help(
                url_expr.span,
                format!("fetch() needs a URL string, not {}", url.ty.describe()),
                "pass the URL as a string; URL and Request objects aren't available in Zero code",
            );
        }
        let init = match args.get(1) {
            None => "null".to_string(),
            Some(_) => {
                let init_expr = self.arg(args, 1, span, "init")?;
                let mut spans = HashMap::new();
                if let ExprKind::Object(props) = &init_expr.kind {
                    for p in props {
                        match p {
                            Prop::KV(PropKey::Static(k), v) => {
                                init_key(k, v.span)?;
                                spans.insert(k.clone(), v.span);
                            }
                            Prop::KV(PropKey::Computed(k), _) => {
                                return err(k.span, "fetch() init keys must be written out");
                            }
                            Prop::Spread(e) => return err(e.span, "write fetch() init as one object literal"),
                        }
                    }
                }
                let init = self.expr(init_expr)?;
                let init = self.finish_value(init, init_expr.span)?;
                if init.ty == Ty::Dynamic {
                    // Checked JavaScript: Zero\Fetch::call refuses other keys and values at runtime.
                    return Ok(Out::atom(
                        format!("\\Zero\\Fetch::call({}, {}{})", url.php, init.php, self.fetch_hosts()),
                        Ty::Promise(Box::new(Ty::Host(HostTy::Response))),
                    ));
                }
                let Ty::Obj(fields) = &init.ty else {
                    return err_help(
                        init_expr.span,
                        format!("fetch() init must be an object, not {}", init.ty.describe()),
                        "write fetch(url, { method, headers, body })",
                    );
                };
                for (k, (t, _)) in fields {
                    let at = spans.get(k).copied().unwrap_or(init_expr.span);
                    init_key(k, at)?;
                    init_value(k, t, at)?;
                }
                init.php
            }
        };
        Ok(Out::atom(
            format!("\\Zero\\Fetch::call({}, {init}{})", url.php, self.fetch_hosts()),
            Ty::Promise(Box::new(Ty::Host(HostTy::Response))),
        ))
    }

    /// `createHash(alg)` / `createHmac(alg, key)` under zero or more `.update(text)` calls:
    /// (function, its arguments, update calls outermost first).
    #[allow(clippy::type_complexity)]
    fn hash_chain<'e>(&mut self, mut e: &'e Expr) -> Result<Option<(&'static str, &'e [ArrEl], Vec<&'e [ArrEl]>)>> {
        let mut updates = Vec::new();
        loop {
            let ExprKind::Call { callee, args, .. } = &e.kind else {
                return Ok(None);
            };
            match &callee.kind {
                ExprKind::Member { obj, prop, .. } if prop == "update" => {
                    updates.push(args.as_slice());
                    e = obj;
                }
                _ => {
                    return Ok(match self.node_fn(callee)? {
                        Some(f @ ("createHash" | "createHmac")) => Some((f, args.as_slice(), updates)),
                        _ => None,
                    });
                }
            }
        }
    }

    /// `<hash chain>.digest(encoding)`; None when `chain` isn't a hash.
    fn digest(&mut self, chain: &Expr, args: &[ArrEl], span: Span) -> Result<Option<Out>> {
        let Some((f, base, mut updates)) = self.hash_chain(chain)? else {
            return Ok(None);
        };
        updates.reverse();
        let encoding = self.encoding(args, span, "digest")?;
        self.host_use(HostUse::Crypto(f), span)?;
        let algorithm = match base.first() {
            Some(ArrEl::Item(Expr {
                kind: ExprKind::Str(a), ..
            })) if ALGORITHMS.contains(&a.to_lowercase().as_str()) => a.to_lowercase(),
            Some(ArrEl::Item(e)) => {
                return err_help(
                    e.span,
                    format!("{f}() needs a literal algorithm name"),
                    format!("use one of {}", ALGORITHMS.join(", ")),
                );
            }
            _ => return err(chain.span, format!("{f}() needs an algorithm")),
        };
        let mut parts = Vec::new();
        for u in &updates {
            if u.len() != 1 {
                return err_help(
                    span,
                    "update() takes one string in Zero code",
                    "input encodings aren't supported; pass text",
                );
            }
            let e = self.arg(u, 0, span, "data")?;
            parts.push(self.string_arg(
                e,
                "update()",
                "Buffer and typed-array inputs aren't available; pass text",
            )?);
        }
        let parts = format!("[{}]", parts.join(", "));
        let php = match (f, base.len()) {
            ("createHash", 1) => format!(
                "\\Zero\\Crypto::hash({}, {parts}, {})",
                php_str(&algorithm),
                php_str(encoding)
            ),
            ("createHmac", 2) => {
                let key = self.arg(base, 1, span, "a key")?;
                if let Some(name) = self.env_name(key) {
                    self.host_use(HostUse::EnvRead(&name), key.span)?;
                    format!(
                        "\\Zero\\Crypto::hmacEnv({}, {}, {parts}, {})",
                        php_str(&algorithm),
                        php_str(&name),
                        php_str(encoding)
                    )
                } else {
                    let key = self.string_arg(
                        key,
                        "createHmac()",
                        "pass the key as a string, e.g. `process.env.SECRET!`",
                    )?;
                    format!(
                        "\\Zero\\Crypto::hmac({}, {key}, {parts}, {})",
                        php_str(&algorithm),
                        php_str(encoding)
                    )
                }
            }
            _ => {
                return err_help(
                    chain.span,
                    format!(
                        "{f}() takes {} here",
                        if f == "createHash" {
                            "an algorithm"
                        } else {
                            "an algorithm and a key"
                        }
                    ),
                    "options objects aren't supported",
                );
            }
        };
        Ok(Some(Out::atom(php, Ty::Str)))
    }

    /// `randomBytes(size).toString(encoding)`.
    fn random_bytes(&mut self, size: &[ArrEl], args: &[ArrEl], at: Span, span: Span) -> Result<Out> {
        let encoding = self.encoding(args, span, "toString")?;
        self.host_use(HostUse::Crypto("randomBytes"), span)?;
        if size.len() != 1 {
            return err_help(
                at,
                "randomBytes() takes a size here",
                "callbacks aren't supported; write randomBytes(n).toString(\"hex\")",
            );
        }
        let e = self.arg(size, 0, at, "a size")?;
        let n = self.expr(e)?;
        let n = self.finish_value(n, e.span)?;
        if n.ty != Ty::Dynamic && (!n.ty.is_num() || n.ty.is_nullable()) {
            return err(
                e.span,
                format!("randomBytes() needs a number of bytes, not {}", n.ty.describe()),
            );
        }
        Ok(Out::atom(
            format!("\\Zero\\Crypto::randomBytes({}, {})", n.php, php_str(encoding)),
            Ty::Str,
        ))
    }

    /// A direct call of a `node:crypto` function.
    fn node_crypto_call(&mut self, f: &'static str, args: &[ArrEl], span: Span) -> Result<Out> {
        match f {
            "randomUUID" => {
                if !args.is_empty() {
                    return err(span, "randomUUID() takes no options in Zero code");
                }
                self.host_use(HostUse::Crypto(f), span)?;
                Ok(Out::atom("\\Zero\\Crypto::randomUUID()", Ty::Str))
            }
            "timingSafeEqual" => {
                let mut argv = Vec::new();
                for i in 0..2 {
                    let e = self.arg(args, i, span, "a Buffer")?;
                    let Some(text) = self.buffer_from(e)? else {
                        return err_help(
                            e.span,
                            "timingSafeEqual() compares Buffers made from text",
                            "pass `Buffer.from(a)` and `Buffer.from(b)`; check `Buffer.byteLength` first, as unequal lengths throw",
                        );
                    };
                    argv.push(self.string_arg(text, "Buffer.from()", "pass text")?);
                }
                if args.len() != 2 {
                    return err(span, "timingSafeEqual() takes two Buffers");
                }
                self.host_use(HostUse::Crypto(f), span)?;
                Ok(Out::atom(
                    format!("\\Zero\\Crypto::timingSafeEqual({})", argv.join(", ")),
                    Ty::Bool,
                ))
            }
            "randomBytes" => err_help(
                span,
                "randomBytes() returns a Buffer, which Zero doesn't compile",
                "write `randomBytes(n).toString(\"hex\")`",
            ),
            _ => err_help(
                span,
                format!("{f}() returns a stateful object, which Zero compiles only as one expression"),
                if f == "createHash" {
                    "write `createHash(\"sha256\").update(text).digest(\"hex\")`"
                } else {
                    "write `createHmac(\"sha256\", key).update(text).digest(\"hex\")`"
                },
            ),
        }
    }

    /// `Buffer.from(text)` or `Buffer.from(text, "utf8")`: the text expression.
    fn buffer_from<'e>(&mut self, e: &'e Expr) -> Result<Option<&'e Expr>> {
        let ExprKind::Call { callee, args, .. } = &e.kind else {
            return Ok(None);
        };
        let ExprKind::Member { obj, prop, .. } = &callee.kind else {
            return Ok(None);
        };
        if prop != "from" || !self.is_global(obj, "Buffer") {
            return Ok(None);
        }
        utf8_only(args, e.span, "Buffer.from()")?;
        self.arg(args, 0, e.span, "text").map(Some)
    }

    fn buffer_call(&mut self, prop: &str, args: &[ArrEl], prop_span: Span, span: Span) -> Result<Out> {
        if prop != "byteLength" {
            return err_help(
                prop_span,
                "Buffer values aren't available in Zero code",
                "compare with `timingSafeEqual(Buffer.from(a), Buffer.from(b))` or measure with `Buffer.byteLength(text)`",
            );
        }
        utf8_only(args, span, "Buffer.byteLength()")?;
        let e = self.arg(args, 0, span, "text")?;
        let text = self.string_arg(e, "Buffer.byteLength()", "pass text")?;
        Ok(Out::atom(format!("strlen({text})"), Ty::Num))
    }

    fn web_crypto_call(&mut self, prop: &str, args: &[ArrEl], prop_span: Span, span: Span) -> Result<Out> {
        if prop != "randomUUID" {
            return self.web_crypto_refusal(prop, prop_span);
        }
        if !args.is_empty() {
            return err(span, "crypto.randomUUID() takes no arguments");
        }
        self.host_use(HostUse::Crypto("randomUUID"), span)?;
        Ok(Out::atom("\\Zero\\Crypto::randomUUID()", Ty::Str))
    }

    fn web_crypto_refusal<T>(&self, prop: &str, span: Span) -> Result<T> {
        match prop {
            "getRandomValues" => err_help(
                span,
                "`crypto.getRandomValues` needs typed arrays, which Zero doesn't compile",
                "use `randomBytes(n).toString(\"hex\")` from \"node:crypto\"",
            ),
            _ => err_help(
                span,
                format!("`crypto.{prop}` isn't available in Zero code"),
                "the global crypto has `randomUUID()`; import hashes and MACs from \"node:crypto\"",
            ),
        }
    }

    fn encoding(&self, args: &[ArrEl], span: Span, call: &str) -> Result<&'static str> {
        match args {
            [
                ArrEl::Item(Expr {
                    kind: ExprKind::Str(e), ..
                }),
            ] if ENCODINGS.contains(&e.as_str()) => Ok(ENCODINGS.iter().find(|x| **x == e).expect("listed")),
            [] => err_help(
                span,
                format!("{call}() without an encoding returns a Buffer, which Zero doesn't compile"),
                format!("write {call}(\"hex\")"),
            ),
            _ => err_help(
                span,
                format!("{call}() needs a literal encoding"),
                format!("use one of {}", ENCODINGS.join(", ")),
            ),
        }
    }

    fn string_arg(&mut self, e: &Expr, call: &str, help: &str) -> Result<String> {
        let v = self.expr(e)?;
        let v = self.finish_value(v, e.span)?;
        if !text(&v.ty) {
            return err_help(e.span, format!("{call} needs a string, not {}", v.ty.describe()), help);
        }
        Ok(v.php)
    }

    fn host_strings(&mut self, args: &[ArrEl], n: usize, span: Span, call: &str) -> Result<Vec<String>> {
        if args.len() != n {
            return err(
                span,
                format!("{call}() takes {n} string argument{}", if n == 1 { "" } else { "s" }),
            );
        }
        (0..n)
            .map(|i| {
                let e = self.arg(args, i, span, "a string")?;
                self.string_arg(e, &format!("{call}()"), "pass a string")
            })
            .collect()
    }

    /// Property reads on `Response`, `Headers` and `URLSearchParams`.
    pub(super) fn host_property(&mut self, h: &HostTy, o: &Out, prop: &str, span: Span) -> Result<Out> {
        let field = |name: &str, ty: Ty| Ok(Out::atom(format!("{}->{name}", o.wrapped()), ty));
        match (h, prop) {
            (HostTy::Response, "status") => field("status", Ty::Num),
            (HostTy::Response, "statusText" | "url") => field(prop, Ty::Str),
            (HostTy::Response, "ok" | "redirected") => field(prop, Ty::Bool),
            (HostTy::Response, "bodyUsed") => field("bodyUsed()", Ty::Bool),
            (HostTy::Response, "headers") => field("headers", Ty::Host(HostTy::Headers)),
            (HostTy::Response, "body") => err_help(
                span,
                "response streams aren't available in Zero code",
                "read the body with `await response.text()` or `await response.json()`",
            ),
            (_, m) if host_methods(h).contains(&m) => err_help(
                span,
                format!("`{}.{m}` can't be used as a value", h.name()),
                format!("call it: `.{m}(...)`"),
            ),
            _ => {
                let mut d = Diag::new(span, format!("{} has no property `{prop}` in Zero code", h.name()));
                let known: &[&str] = match h {
                    HostTy::Response => &["status", "statusText", "ok", "url", "redirected", "bodyUsed", "headers"],
                    _ => host_methods(h),
                };
                if let Some(s) = suggest(prop, known.iter().copied()) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                Err(d)
            }
        }
    }

    /// Method calls on `Response`, `Headers` and `URLSearchParams`.
    pub(super) fn host_method(
        &mut self,
        h: &HostTy,
        recv: &Out,
        m: &str,
        args: &[ArrEl],
        m_span: Span,
        span: Span,
    ) -> Result<Out> {
        let r = recv.wrapped();
        let call = |argv: &[String], ty: Ty| Ok(Out::atom(format!("{r}->{m}({})", argv.join(", ")), ty));
        match (h, m) {
            (HostTy::Response, "text" | "json") => {
                if !args.is_empty() {
                    return err(span, format!("{m}() takes no arguments"));
                }
                call(
                    &[],
                    Ty::Promise(Box::new(if m == "text" { Ty::Str } else { Ty::Unknown })),
                )
            }
            (HostTy::Response, "arrayBuffer" | "blob" | "bytes" | "formData") => err_help(
                m_span,
                "binary and form bodies aren't available in Zero code",
                "read the body with `await response.text()` or `await response.json()`",
            ),
            (HostTy::Response, "clone") => err_help(
                m_span,
                "responses can't be cloned in Zero code",
                "read the body once into a variable and use that",
            ),
            (HostTy::Headers | HostTy::SearchParams, "get") => {
                let argv = self.host_strings(args, 1, span, m)?;
                call(&argv, Ty::Str.nullable())
            }
            (HostTy::Headers | HostTy::SearchParams, "has") => {
                let argv = self.host_strings(args, 1, span, m)?;
                call(&argv, Ty::Bool)
            }
            (HostTy::Headers | HostTy::SearchParams, "delete") => {
                let argv = self.host_strings(args, 1, span, m)?;
                call(&argv, Ty::Null)
            }
            (HostTy::Headers | HostTy::SearchParams, "set" | "append") => {
                let argv = self.host_strings(args, 2, span, m)?;
                call(&argv, Ty::Null)
            }
            (HostTy::SearchParams, "getAll") => {
                let argv = self.host_strings(args, 1, span, m)?;
                call(&argv, Ty::Array(Box::new(Ty::Str)))
            }
            (HostTy::SearchParams, "toString") => {
                self.host_strings(args, 0, span, m)?;
                Ok(Out::atom(format!("((string) {r})"), Ty::Str))
            }
            (HostTy::Headers, "forEach") => {
                if args.len() != 1 {
                    return err(span, "forEach() takes a callback");
                }
                let e = self.arg(args, 0, span, "a callback")?;
                let f = self.callback(e, &[Ty::Str, Ty::Str, Ty::Host(HostTy::Headers)])?;
                call(&[f.php], Ty::Null)
            }
            (HostTy::Headers, "entries" | "keys" | "values" | "getSetCookie") => err_help(
                m_span,
                format!("`Headers.{m}` isn't available in Zero code"),
                "walk them with `headers.forEach((value, name) => ...)`; set-cookie is never returned",
            ),
            _ => {
                let mut d = Diag::new(m_span, format!("{} has no method `{m}` in Zero code", h.name()));
                if let Some(s) = suggest(m, host_methods(h).iter().copied()) {
                    d = d.help(format!("did you mean `{s}`?"));
                }
                Err(d)
            }
        }
    }
}

/// `(text)` or `(text, "utf8")`: Buffers here are always the UTF-8 bytes of a string.
fn utf8_only(args: &[ArrEl], span: Span, call: &str) -> Result<()> {
    match args {
        [_] => Ok(()),
        [
            _,
            ArrEl::Item(Expr {
                kind: ExprKind::Str(enc),
                ..
            }),
        ] if matches!(enc.as_str(), "utf8" | "utf-8") => Ok(()),
        [_, ArrEl::Item(x) | ArrEl::Spread(x)] => {
            err_help(x.span, format!("{call} reads text as UTF-8 here"), "omit the encoding")
        }
        _ => err(span, format!("{call} takes text here")),
    }
}

fn host_methods(h: &HostTy) -> &'static [&'static str] {
    match h {
        HostTy::Response => &["text", "json"],
        HostTy::Headers => &["get", "has", "set", "append", "delete", "forEach"],
        HostTy::SearchParams => &["get", "getAll", "has", "set", "append", "delete", "toString"],
    }
}

fn unknown_node_crypto(name: &str, span: Span) -> Diag {
    let d = Diag::new(span, format!("\"node:crypto\" has no `{name}` in Zero code"));
    match suggest(name, NODE_CRYPTO.iter().copied()) {
        Some(s) => d.help(format!("did you mean `{s}`?")),
        None => d.help(format!(
            "Zero compiles {} from \"node:crypto\" to PHP",
            NODE_CRYPTO.join(", ")
        )),
    }
}

/// A `RequestInit` key Zero lowers, or a located refusal with a fix.
fn init_key(key: &str, span: Span) -> Result<()> {
    match key {
        "method" | "headers" | "body" | "redirect" => Ok(()),
        "signal" => err_help(
            span,
            "fetch() `signal` isn't available in Zero code",
            "remove it; every call ends after 10 s",
        ),
        "credentials" | "mode" | "cache" | "referrer" | "referrerPolicy" | "integrity" | "keepalive" | "priority"
        | "window" | "duplex" | "dispatcher" => err_help(
            span,
            format!("fetch() `{key}` has no effect in a server-side request"),
            "remove it; Zero sends no cookies and keeps no HTTP cache",
        ),
        _ => {
            let mut d = Diag::new(span, format!("fetch() init has no option `{key}` in Zero code"));
            if let Some(s) = suggest(key, ["method", "headers", "body", "redirect"]) {
                d = d.help(format!("did you mean `{s}`?"));
            } else {
                d = d.help("fetch() takes method, headers, body and redirect");
            }
            Err(d)
        }
    }
}

fn absent(t: &Ty) -> bool {
    matches!(t, Ty::Null | Ty::Undefined)
}

fn init_value(key: &str, t: &Ty, span: Span) -> Result<()> {
    let ok = match key {
        "method" => t.all(|t| text(t) || absent(t)),
        "headers" => t.all(|t| header_init(t) || absent(t)),
        "body" => t.all(|t| text(t) || matches!(t, Ty::Host(HostTy::SearchParams)) || absent(t)),
        "redirect" => t.all(|t| match t {
            Ty::StrLit(v) | Ty::FreshStrLit(v) => matches!(v.as_str(), "follow" | "manual" | "error"),
            t => text(t) || absent(t),
        }),
        _ => true,
    };
    if ok {
        return Ok(());
    }
    err_help(
        span,
        format!("fetch() `{key}` can't be {}", t.describe()),
        match key {
            "body" => {
                "send a string (JSON.stringify(value) for JSON) or URLSearchParams; Blob, FormData, ArrayBuffer and streams aren't available"
            }
            "headers" => "pass an object of strings, an array of [name, value] string pairs, or a Headers",
            "redirect" => "use \"follow\", \"manual\" or \"error\"",
            _ => "pass a string",
        },
    )
}

/// A string, or a checked-JavaScript value the runtime classes validate (they throw TypeError).
fn text(t: &Ty) -> bool {
    *t == Ty::Dynamic || t.is_str() && !t.is_nullable()
}

fn string_pairs(t: &Ty) -> bool {
    let pair = |t: &Ty| match t {
        Ty::Tuple(p) | Ty::ArrayLiteral(p) => p.len() == 2 && p.iter().all(text),
        Ty::Array(p) | Ty::NonEmptyArray(p) => text(p),
        _ => false,
    };
    match t {
        Ty::Array(el) | Ty::NonEmptyArray(el) => el.all(pair),
        Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => ts.iter().all(pair),
        _ => false,
    }
}

fn string_record(t: &Ty) -> bool {
    match t {
        Ty::Obj(fields) => fields.values().all(|(t, optional)| !optional && text(t)),
        Ty::Map(v) => text(v),
        _ => false,
    }
}

fn header_init(t: &Ty) -> bool {
    *t == Ty::Dynamic || string_record(t) || string_pairs(t) || matches!(t, Ty::Host(HostTy::Headers))
}

fn search_init(t: &Ty) -> bool {
    text(t) || string_record(t) || string_pairs(t) || matches!(t, Ty::Host(HostTy::SearchParams))
}
