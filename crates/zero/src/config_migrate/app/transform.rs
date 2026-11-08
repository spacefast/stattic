use super::super::{Entry, Failure, Source, entry, fail, input, pointer, translate};
use crate::diag::{self, Span};
use crate::ir::{ArrEl, DeclKind, Expr, ExprKind as E, FnBody, Func, ImportName, Pat, Prop, PropKey, Stmt, TyAnn};
use crate::lower::Lowerer;
use indexmap::IndexMap;
use oxc_allocator::Allocator;
use oxc_parser::Parser;
use oxc_span::SourceType;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

pub(super) fn parse(path: &Path, text: &str) -> Result<Vec<Stmt>, Failure> {
    let arena = Allocator::default();
    let parsed = Parser::new(&arena, text, SourceType::ts()).parse();
    if let Some(d) = parsed.diagnostics.errors().next() {
        // JSX in server code drew 0.8 ImageResponse elements; v2 server code is plain TypeScript.
        if path.extension().is_some_and(|e| e == "tsx")
            && Parser::new(&arena, text, SourceType::tsx())
                .parse()
                .diagnostics
                .errors()
                .next()
                .is_none()
        {
            return Err(fail(
                "",
                format!(
                    "{} uses JSX on the server (ImageResponse); render the image ahead of time or port it to a route that returns stored bytes",
                    path.display()
                ),
            ));
        }
        return Err(fail("", format!("invalid TypeScript: {}", d.message)));
    }
    let file = diag::register_file(path, text);
    Lowerer {
        file,
        module: 0,
        class_context: std::cell::Cell::new(false),
        super_call: std::cell::Cell::new(false),
        erase_types: false,
        erased_types: Default::default(),
    }
    .program(&parsed.program)
    .map_err(|d| fail("", diag::render(&d, Path::new("."))))
}

pub(super) fn imports(body: &[Stmt]) -> Vec<&str> {
    body.iter()
        .filter_map(|s| match s {
            Stmt::Import { source, .. } => Some(source.as_str()),
            Stmt::ExportNames { from: Some(source), .. } | Stmt::ExportAll { from: source, .. } => {
                Some(source.as_str())
            }
            _ => None,
        })
        .collect()
}

fn q(s: &str) -> String {
    serde_json::to_string(s).expect("string JSON")
}
fn raw(text: &str, span: Span) -> &str {
    &text[span.start as usize..span.end as usize]
}
fn ident(s: &str, at: &str) -> Result<(), Failure> {
    if s.is_empty()
        || !s.as_bytes()[0].is_ascii_alphabetic()
        || !s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
        || s.starts_with("__z08")
    {
        return Err(fail(
            at,
            "names must start with an ASCII letter and contain only letters, digits or underscores",
        ));
    }
    Ok(())
}
fn obj<'a>(e: &'a Expr, at: &str) -> Result<IndexMap<String, &'a Expr>, Failure> {
    let E::Object(props) = &e.kind else {
        return Err(fail(
            at,
            "expected a literal object; spreads and executable schema factories require a manual port",
        ));
    };
    let mut out = IndexMap::new();
    for p in props {
        let Prop::KV(PropKey::Static(key), value) = p else {
            return Err(fail(at, "spreads, methods and computed keys require a manual port"));
        };
        if out.insert(key.clone(), value).is_some() {
            return Err(fail(&pointer(at, key), "duplicate declaration"));
        }
    }
    Ok(out)
}
fn args(e: &Expr) -> Result<(&Expr, Vec<&Expr>), Failure> {
    let E::Call {
        callee,
        args,
        optional: false,
        ..
    } = &e.kind
    else {
        return Err(fail("", "expected a direct helper call"));
    };
    let a = args
        .iter()
        .map(|a| match a {
            ArrEl::Item(e) => Ok(e),
            _ => Err(fail("", "spread arguments require a manual port")),
        })
        .collect::<Result<_, _>>()?;
    Ok((callee, a))
}
fn literal(e: &Expr) -> Result<Value, Failure> {
    Ok(match &e.kind {
        E::Str(s) => s.clone().into(),
        E::Num(n) => json!(n),
        E::Bool(b) => (*b).into(),
        E::Null => Value::Null,
        E::Object(_) => Value::Object(
            obj(e, "")?
                .into_iter()
                .map(|(k, e)| Ok((k, literal(e)?)))
                .collect::<Result<_, Failure>>()?,
        ),
        E::Array(a) => Value::Array(
            a.iter()
                .map(|a| match a {
                    ArrEl::Item(e) => literal(e),
                    _ => Err(fail("", "expected literal array")),
                })
                .collect::<Result<_, _>>()?,
        ),
        E::As(e, _) => literal(e)?,
        E::Unary(crate::ir::UnOp::Neg, e) => json!(-literal(e)?.as_f64().ok_or_else(|| fail("", "expected a number"))?),
        _ => return Err(fail("", "expected a literal value")),
    })
}
fn string(e: &Expr, at: &str) -> Result<String, Failure> {
    literal(e)?
        .as_str()
        .map(str::to_owned)
        .ok_or_else(|| fail(at, "expected a literal string"))
}

struct Field {
    name: String,
    kind: String,
    optional: bool,
    default: Option<Value>,
}
impl Field {
    fn ty(&self) -> &str {
        match self.kind.as_str() {
            "boolean" => "boolean",
            "number" => "number",
            _ => "string",
        }
    }
    fn builder(&self) -> String {
        let mut s = match self.kind.as_str() {
            "boolean" => "__z08Boolean()",
            "number" => "__z08Real()",
            _ => "__z08Text()",
        }
        .to_string();
        if self.kind == "userId" {
            s.push_str(".min(1)");
        }
        if !self.optional || self.default.is_some() {
            s.push_str(".notNull()");
        }
        if let Some(d) = &self.default {
            s.push_str(&format!(".default({d})"));
        }
        s
    }
}
struct Table {
    name: String,
    physical: String,
    fields: Vec<Field>,
    indexes: BTreeMap<String, Vec<String>>,
}

fn table(e: &Expr, name: &str, space: Option<&str>, aliases: &BTreeMap<String, String>) -> Result<Table, Failure> {
    let at = pointer("/schema", name);
    ident(name, &at)?;
    let mut e = e;
    let mut indexes = BTreeMap::new();
    loop {
        let (callee, a) = args(e).map_err(|_| fail(&at, "expected table({...}).index(name, fields)"))?;
        match &callee.kind {
            E::Member { obj, prop, .. } if prop == "index" && a.len() == 2 => {
                let index = string(a[0], &at)?;
                let Value::Array(fields) = literal(a[1])? else {
                    return Err(fail(&at, "index fields must be literal strings"));
                };
                let fields = fields
                    .iter()
                    .map(|v| {
                        v.as_str()
                            .map(str::to_owned)
                            .ok_or_else(|| fail(&at, "index fields must be strings"))
                    })
                    .collect::<Result<Vec<_>, _>>()?;
                if index == "by_creation" || indexes.insert(index, fields).is_some() {
                    return Err(fail(&at, "duplicate or reserved index name"));
                }
                e = obj;
            }
            E::Ident(id) if aliases.get(id).map(String::as_str) == Some("table") && a.len() == 1 => {
                let mut fields = vec![];
                for (key, value) in obj(a[0], &at)? {
                    let at = pointer(&at, &key);
                    ident(&key, &at)?;
                    if ["id", "createdAt", "updatedAt"].contains(&key.as_str()) {
                        return Err(fail(&at, "implicit metadata column cannot be redeclared"));
                    }
                    let mut value = value;
                    let mut optional = false;
                    let mut default = None;
                    loop {
                        let (callee, a) = args(value).map_err(|_| fail(&at, "unsupported field declaration"))?;
                        match &callee.kind {
                            E::Member { obj, prop, .. } if prop == "optional" && a.is_empty() && !optional => {
                                optional = true;
                                value = obj;
                            }
                            E::Member { obj, prop, .. } if prop == "default" && a.len() == 1 && default.is_none() => {
                                default =
                                    Some(literal(a[0]).map_err(|_| fail(&at, "default must be a literal scalar"))?);
                                value = obj;
                            }
                            E::Ident(id)
                                if aliases.get(id).is_some_and(|s| {
                                    ["string", "number", "boolean", "id", "userId"].contains(&s.as_str())
                                }) =>
                            {
                                let kind = aliases[id].clone();
                                if (kind == "id" && (a.len() != 1 || string(a[0], &at).is_err()))
                                    || (kind != "id" && !a.is_empty())
                                {
                                    return Err(fail(&at, "invalid field arguments"));
                                }
                                if default.as_ref().is_some_and(|v| match kind.as_str() {
                                    "number" => !v.is_number(),
                                    "boolean" => !v.is_boolean(),
                                    _ => !v.is_string(),
                                }) {
                                    return Err(fail(&at, "default does not match the field type"));
                                }
                                fields.push(Field {
                                    name: key,
                                    kind,
                                    optional,
                                    default,
                                });
                                break;
                            }
                            _ => return Err(fail(&at, "unsupported field or modifier")),
                        }
                    }
                }
                for columns in indexes.values() {
                    for col in columns {
                        if !fields.iter().any(|f| &f.name == col) {
                            return Err(fail(&at, "index names an unknown field"));
                        }
                    }
                }
                let space = space.filter(|s| !s.is_empty()).ok_or_else(|| {
                    fail(
                        &at,
                        "--space is required to bind the actual sf_<space>_<table>_<hash> table",
                    )
                })?;
                return Ok(Table {
                    name: name.into(),
                    physical: physical(space, name),
                    fields,
                    indexes,
                });
            }
            _ => return Err(fail(&at, "unsupported table declaration")),
        }
    }
}

fn physical(space: &str, name: &str) -> String {
    fn part(s: &str) -> String {
        let re = regex::Regex::new("[^a-z0-9]+").expect("regex");
        let lower = s.to_ascii_lowercase();
        let s = re.replace_all(&lower, "_");
        let s: String = s.trim_matches('_').chars().take(48).collect();
        if s.is_empty() { "zero".into() } else { s }
    }
    let digest = Sha256::digest(format!("{space}\0{name}"))
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    let base = format!("sf_{}_{}", part(space), part(name));
    format!("{}_{:.10}", &base[..base.len().min(53)], digest)
}

/// Every v2 user projects as a member whose hidden `spacefastSubject` holds the 0.8 Space
/// subject (`usr_<64 hex>` for Space Users) copied by `wp zero migrate-spacefast`. Users created
/// after the migration answer `wp:<id>`, which no 0.8 subject can equal.
const IDENTITY: &str = r#"const __z08Members = __z08Users({email: __z08Email().notNull().unique().max(191), name: __z08Text().notNull(), spacefastSubject: __z08Text().max(191).access({ read: false, create: false, update: false })});
type __z08User = { id: number; name: string; role: "guest" | "subscriber" | "author" | "editor" | "admin" };
async function __z08Subject(user: __z08User | null): Promise<string | null> {
  if (user === null) return null;
  const member = await __z08Db.query.members.findFirst({ where: { id: user.id }, columns: { spacefastSubject: true } });
  const stored = member?.spacefastSubject;
  return stored != null && stored !== "" ? stored : "wp:" + String(user.id);
}
function __z08Auth(user: __z08User | null, subject: string | null) {
  const guest = user !== null && String(user.role) === "guest";
  return { userId: subject, displayName: user === null ? "Signed out" : guest ? "Guest" : user.name, isGuest: guest, isSignedIn: user !== null && !guest, isAuthenticated: user !== null && !guest };
}
function __z08RequireSignedIn(user: __z08User | null, subject: string | null) {
  if (user === null || subject === null || String(user.role) === "guest") throw new __z08HttpError(401, "unauthorized", "Sign in to continue.");
  return { userId: subject, displayName: user.name, isGuest: false, isSignedIn: true, isAuthenticated: true };
}
function __z08RequireIdentity(user: __z08User | null, subject: string | null) {
  if (user === null || subject === null) throw new __z08HttpError(401, "unauthorized", "An identity is required.");
  const guest = String(user.role) === "guest";
  return { userId: subject, displayName: guest ? "Guest" : user.name, isGuest: guest, isSignedIn: !guest, isAuthenticated: !guest };
}
"#;

/// v2 compiles `console.*` to the PHP error log; the declaration types it for editors.
const LOG: &str = "declare const console: { debug(...values: unknown[]): void; info(...values: unknown[]): void; warn(...values: unknown[]): void; error(...values: unknown[]): void };\n";

/// Index ranges keep 0.8's null rule: comparing against null is refused.
const RANGE: &str = r#"function __z08PageOptions(options: { numItems: number; cursor?: string | null }): { numItems: number; cursor: string | null } { return { numItems: options.numItems, cursor: options.cursor ?? null }; }
function __z08RangeText(value: string | null | undefined): string { if (value == null) throw new Error("Zero DB range filters cannot compare against null."); return value; }
function __z08RangeReal(value: number | null | undefined): number { if (value == null) throw new Error("Zero DB range filters cannot compare against null."); return value; }
function __z08RangeBoolean(value: boolean | null | undefined): boolean { if (value == null) throw new Error("Zero DB range filters cannot compare against null."); return value; }
"#;

pub(super) struct Transformed {
    pub config: String,
    pub content_config: Option<String>,
    pub connector_roles: Value,
}

pub(super) fn app(
    path: &Path,
    source: &str,
    space: Option<&str>,
    users: bool,
    content_posts: bool,
    entries: &mut Vec<Entry>,
    errors: &mut Vec<Failure>,
) -> Result<Transformed, Failure> {
    let body = parse(path, source)?;
    let mut responses = String::new();
    let mut aliases = BTreeMap::new();
    let mut prelude = String::new();
    let mut root = None;
    // `export` on a helper changes nothing in the generated module; the helper itself is copied.
    let body: Vec<&Stmt> = body
        .iter()
        .map(|s| match s {
            Stmt::Exported(inner) => inner.as_ref(),
            s => s,
        })
        .collect();
    if let Some(name) = ambient_globals(source).first() {
        return Err(fail(
            "",
            format!(
                "`declare const {name}` names a build-time global that 0.8 injected; replace it with env(\"{name}\") or a constant"
            ),
        ));
    }
    for &stmt in &body {
        match stmt {
            Stmt::Import {
                source: package,
                specs,
                span,
                ..
            } if package == "@spacefast/zero/server" => {
                for s in specs {
                    let ImportName::Named(name) = &s.imported else {
                        return Err(fail("", "legacy helpers must use named imports"));
                    };
                    if s.local.starts_with("__z08") || s.local == "InferSelectModel" {
                        return Err(fail("", "reserved generated import binding"));
                    }
                    // Structural 0.8 types the migrated handlers still name.
                    if let Some(shape) = legacy_type(name) {
                        // 0.8's `Id<"table">` names its table; v2 ids are strings, and the parameter
                        // stays so every `Id<...>` in the source still type-checks.
                        let params = if name == "Id" {
                            "<_Table extends string = string>"
                        } else {
                            ""
                        };
                        prelude.push_str(&format!("type {}{params} = {shape};\n", s.local));
                    }
                    if let Some(helper) = super::endpoints::helper(&s.local, name) {
                        responses.push_str(&helper);
                    }
                    aliases.insert(s.local.clone(), name.clone());
                }
                let _ = span;
            }
            Stmt::Import { specs, span, .. } => {
                if specs
                    .iter()
                    .any(|s| s.local.starts_with("__z08") || s.local == "InferSelectModel")
                {
                    return Err(fail("", "reserved generated import binding"));
                }
                prelude.push_str(raw(source, *span));
                prelude.push('\n');
            }
            Stmt::ExportDefault(e) if root.is_none() => root = Some(e),
            Stmt::Func(f) => {
                if f.name.as_ref().is_some_and(|n| n.starts_with("__z08")) {
                    return Err(fail("", "reserved generated helper name"));
                }
                prelude.push_str(raw(source, f.span));
                prelude.push('\n');
            }
            Stmt::Decl {
                span, pat, kind, init, ..
            } => {
                let mut names = vec![];
                pat.bindings(&mut names);
                if names.iter().any(|(n, _)| n.starts_with("__z08")) {
                    return Err(fail("", "__z08 names are reserved for generated bindings"));
                }
                // Class declarations already include their keyword; variable spans start at the binding.
                let class_declaration = init
                    .as_ref()
                    .is_some_and(|e| matches!(e.kind, E::Class(_)) && e.span == *span);
                if !class_declaration {
                    prelude.push_str(match kind {
                        DeclKind::Const => "const ",
                        DeclKind::Let => "let ",
                        DeclKind::Var => "var ",
                    });
                }
                prelude.push_str(raw(source, *span));
                prelude.push_str(";\n");
            }
            Stmt::TypeAlias { name, .. } => {
                if name.starts_with("__z08") || name == "InferSelectModel" {
                    return Err(fail("", "reserved generated type name"));
                }
            }
            _ => {
                return Err(fail(
                    "",
                    "expected imports, pure helpers and one capsule default export",
                ));
            }
        }
    }
    // Type aliases have no statement span in IR; parse their source bounds with oxc instead.
    prelude.push_str(&type_aliases(source));
    let root = root.ok_or_else(|| fail("", "expected default export capsule({...})"))?;
    let (callee, a) = args(root)?;
    if !matches!(&callee.kind, E::Ident(id) if aliases.get(id).map(String::as_str)==Some("capsule")) || a.len() != 1 {
        return Err(fail("", "expected capsule({...})"));
    }
    let root = obj(a[0], "")?;
    let title = root.get("name").map(|e| string(e, "/name")).transpose()?;
    // 0.8 names are display titles; v2 needs the plugin slug.
    let name = title
        .as_deref()
        .map(crate::extract::slugify_name)
        .unwrap_or_else(|| "migrated-app".into());
    if let Some(title) = title.as_deref().filter(|t| *t != name) {
        entries.push(entry(
            "/name",
            "mapped",
            format!("display name {title:?} becomes the plugin slug {name:?}"),
        ));
    }
    let mut tables = BTreeMap::new();
    if let Some(schema) = root.get("schema") {
        for (name, e) in obj(schema, "/schema")? {
            match table(e, &name, space, &aliases) {
                Ok(t) => {
                    entries.push(entry(&pointer("/schema",&name),"mapped",format!("bind existing SQL table {}; BIGINT id, TEXT metadata/scalars and DOUBLE numbers; no rows copied",t.physical)));
                    tables.insert(name, t);
                }
                Err(e) => errors.push(e),
            }
        }
    }
    let mut connector_roles = serde_json::Map::new();
    if let Some(connectors) = root.get("connectors") {
        for (name, e) in obj(connectors, "/connectors")? {
            let at = pointer("/connectors", &name);
            let parsed = (|| {
                let (c, a) = args(e)?;
                if !matches!(&c.kind,E::Ident(n) if aliases.get(n).map(String::as_str)==Some("connector"))
                    || a.len() != 1
                {
                    return Err(fail(&at, "expected connector({...})"));
                }
                let mut v = literal(a[0])?;
                let o = v.as_object_mut().ok_or_else(|| fail(&at, "expected connector role"))?;
                if o.keys().any(|k| !["as", "connector"].contains(&k.as_str())) {
                    return Err(fail(&at, "unknown connector role option"));
                }
                let role = match o.get("as").and_then(Value::as_str) {
                    Some("owner") => "personal",
                    Some("visitor") => "visitor",
                    Some("team") => "team",
                    _ => return Err(fail(&pointer(&at, "as"), "unknown connector authority")),
                };
                o.insert("as".into(), role.into());
                Ok(v)
            })();
            match parsed {
                Ok(v) => {
                    connector_roles.insert(name, v);
                    entries.push(entry(&at,"review","connector role normalized for the optional host overlay; credentials and grants are not migrated; ctx connector calls require a port"));
                }
                Err(e) => errors.push(e),
            }
        }
    }
    let mut signed_in = false;
    if let Some(auth) = root
        .get("auth")
        .filter(|auth| !matches!(literal(auth), Ok(Value::Bool(_))))
    {
        for (key, e) in obj(auth, "/auth")? {
            match (key.as_str(), literal(e).ok()) {
                ("requireSignIn", Some(Value::Bool(b))) => {
                    signed_in = b;
                    entries.push(entry("/auth/requireSignIn","mapped","carry requireSignIn to generated action gates using zero-identity; existing sessions and principal IDs require a separate identity migration"));
                }
                _ => errors.push(fail(
                    &pointer("/auth", &key),
                    "identity callbacks/providers need an explicit zero-identity migration",
                )),
            }
        }
    }
    for key in root.keys() {
        if ![
            "name",
            "schema",
            "queries",
            "mutations",
            "actions",
            "collections",
            "connectors",
            "auth",
            "realtime",
            "endpoints",
            "favicon",
        ]
        .contains(&key.as_str())
        {
            match obj(root[key], &pointer("", key)) {
                Ok(items) if !items.is_empty() => {
                    for name in items.keys() {
                        errors.push(fail(
                            &pointer(&pointer("", key), name),
                            "unsupported capsule feature; port it before migrating the application",
                        ));
                    }
                }
                _ => errors.push(fail(
                    &pointer("", key),
                    "unsupported capsule feature; port it before migrating the application",
                )),
            }
        }
    }
    // 0.8 type-checked `favicon` and nothing else: no 0.8 compiler, engine or runner revision read
    // it, so a 0.8 Space's browser icon was always its published `/favicon.ico` (or the shell's).
    if root.contains_key("favicon") {
        entries.push(entry(
            "/favicon",
            "review",
            "0.8 never served the favicon option, so dropping it changes nothing; the icon a visitor sees is still the published /favicon.ico. To keep shipping one, put it in the candidate's public/ directory",
        ));
    }
    if root.contains_key("realtime") {
        entries.push(entry("/realtime", "mapped", "v2 announces every committed write as a change notice, so the declaration is no longer needed; Spacefast delivers notices over Cast. Replace client useQuery subscriptions with watch(client, [\"<table>\"], read, onResult) from the generated client."));
    }
    let mut native_posts = content_posts.then(|| json!({}));
    let content_config = if let Some(collections) = root.get("collections") {
        for name in obj(collections, "/collections")?.keys() {
            if tables.contains_key(name) {
                errors.push(fail(
                    &pointer("/collections", name),
                    "collection collides with a schema table",
                ));
            }
        }
        let text = format!(
            "import {{ capsule }} from \"@spacefast/zero/server\"; export default capsule({{ name: {}, collections: {} }});",
            q(&name),
            raw(source, collections.span)
        );
        let input = input::parse(path, &text, Source::Zero).map(|mut v| {
            if let Some(defs) = v["collections"].as_object_mut()
                && let Some(posts) = defs.remove("posts")
            {
                native_posts = Some(posts);
            }
            v
        });
        match input.and_then(|v| translate::config(v, Source::Zero, entries)) {
            Ok(code) => Some(format!("{code}\nexport {{ collections, tables }};\n")),
            Err(e) => {
                errors.push(e);
                None
            }
        }
    } else {
        None
    };
    let mut helpers: Vec<String> = Vec::new();
    let has_users = users
        || root
            .get("auth")
            .is_some_and(|auth| !matches!(literal(auth), Ok(Value::Bool(false))));
    let mut handlers = Vec::new();
    let mut names = BTreeSet::new();
    for section in ["queries", "mutations", "actions"] {
        if let Some(group) = root.get(section) {
            for (name, e) in obj(group, &pointer("", section))? {
                let at = pointer(&pointer("", section), &name);
                let result = (|| {
                    ident(&name, &at)?;
                    if !names.insert(name.clone()) {
                        return Err(fail(&at, "action name collides across query/mutation/action groups"));
                    }
                    let (c, a) = args(e)?;
                    let expected = match section {
                        "queries" => "query",
                        "mutations" => "mutation",
                        _ => "action",
                    };
                    if !matches!(&c.kind,E::Ident(n) if aliases.get(n).map(String::as_str)==Some(expected))
                        || a.len() != 1
                    {
                        return Err(fail(&at, "expected a handler helper around one function"));
                    }
                    let E::Func(f) = &a[0].kind else {
                        return Err(fail(&at, "handler must be an inline function"));
                    };
                    handler(
                        source,
                        f,
                        &tables,
                        &at,
                        &name,
                        Mode {
                            writes: section == "mutations",
                            signed_in,
                            subjects: users,
                            sends: section != "queries",
                            route: None,
                        },
                    )
                })();
                match result {
                    Ok((h, generated)) => {
                        if generated.iter().any(|g| g.contains("__z08page_")) {
                            entries.push(entry(&at, "mapped", "paginate keeps 0.8's index order, numItems clamp and { page, isDone, continueCursor } result; cursors are new opaque strings, so clients restart from the first page after cutover"));
                        }
                        helpers.extend(generated);
                        handlers.push(h);
                        entries.push(entry(&at,"mapped","v2 action with named input properties and legacy row results; REST URL/envelope and error transport follow v2"));
                    }
                    Err(e) => errors.push(e),
                }
            }
        }
    }
    let mut routes: Vec<(String, String)> = Vec::new();
    let mut prefixes: Vec<(String, String, String, String)> = Vec::new();
    let mut seen = BTreeSet::new();
    if let Some(group) = root.get("endpoints") {
        for (name, e) in obj(group, "/endpoints")? {
            let at = pointer("/endpoints", &name);
            let result = (|| {
                ident(&name, &at)?;
                let (c, a) = args(e).map_err(|_| fail(&at, "expected endpoint({ method, path }, handler)"))?;
                if !matches!(&c.kind,E::Ident(n) if aliases.get(n).map(String::as_str)==Some("endpoint"))
                    || a.len() != 2
                {
                    return Err(fail(&at, "expected endpoint({ method, path }, handler)"));
                }
                let options = obj(a[0], &at)?;
                for key in options.keys() {
                    if !["method", "path", "readOnly", "mode"].contains(&key.as_str()) {
                        return Err(fail(&pointer(&at, key), "unknown endpoint option"));
                    }
                }
                let method = options
                    .get("method")
                    .map(|e| string(e, &pointer(&at, "method")))
                    .transpose()?
                    .ok_or_else(|| fail(&at, "endpoint needs a literal method"))?;
                let path = options
                    .get("path")
                    .map(|e| string(e, &pointer(&at, "path")))
                    .transpose()?
                    .ok_or_else(|| fail(&at, "endpoint needs a literal path"))?;
                let read_only = match options.get("readOnly").map(|e| literal(e)) {
                    None => None,
                    Some(Ok(Value::Bool(b))) => Some(b),
                    Some(_) => return Err(fail(&pointer(&at, "readOnly"), "readOnly must be a literal boolean")),
                };
                let route = super::endpoints::route(&method, &path, read_only, &at)?;
                let shape = route_shape(&route.path);
                if !seen.insert((route.method.clone(), shape)) {
                    return Err(fail(&at, "two endpoints share a method and path shape"));
                }
                if names.contains(&name) {
                    return Err(fail(&at, "endpoint name collides with a query, mutation or action"));
                }
                let E::Func(f) = &a[1].kind else {
                    return Err(fail(&at, "handler must be an inline function"));
                };
                handler(
                    source,
                    f,
                    &tables,
                    &at,
                    &name,
                    Mode {
                        writes: route.writes,
                        signed_in,
                        subjects: users,
                        sends: route.writes,
                        route: Some(&route),
                    },
                )
                .map(|h| (h, route))
            })();
            match result {
                Ok(((code, generated), route)) => {
                    let (prefixed, generated): (Vec<_>, Vec<_>) =
                        generated.into_iter().partition(|g| g.starts_with(PREFIX_MARK));
                    helpers.extend(generated);
                    if let (Some(prefix), Some(code)) = (&route.prefix, prefixed.into_iter().next()) {
                        prefixes.push((
                            route.method.clone(),
                            prefix.clone(),
                            at.clone(),
                            code[PREFIX_MARK.len()..].to_string(),
                        ));
                    }
                    routes.push((route.path.clone(), code));
                    entries.push(entry(
                        &at,
                        "mapped",
                        format!(
                            "v2 route {} {} with 0.8's result rules; callers keep the URL",
                            route.method, route.path
                        ),
                    ));
                    let identity = routes.last().is_some_and(|(_, c): &(String, String)| {
                        c.contains("__z08subject") || c.contains("__z08ctx.user")
                    });
                    if route.method != "GET" && (signed_in || identity) {
                        entries.push(entry(&at, "review", "a signed-in caller of an unsafe-method route must send X-WP-Nonce; without it the request runs anonymously"));
                    }
                }
                Err(e) => errors.push(e),
            }
        }
    }
    // An exact endpoint at the prefix wins over the splat in 0.8 (exact paths are served first).
    for (method, prefix, at, code) in prefixes {
        if seen.insert((method.clone(), route_shape(&prefix))) {
            entries.push(entry(
                &at,
                "mapped",
                format!("{method} {prefix} also reaches this handler with an empty splat, as in 0.8"),
            ));
            routes.push((prefix, code));
        }
    }
    if handlers
        .iter()
        .chain(routes.iter().map(|(_, c)| c))
        .any(|h| h.contains("__z08Subject("))
    {
        entries.push(entry(
            "/auth",
            "review",
            "ctx.auth.userId reads each member's copied Space Users subject (usr_...); users created after migration answer wp:<id>. Rows owned by 0.8 guest:<id> identities keep their stored owner but no v2 user maps to them, and anonymous writes answer 401 so the v2 client can start a guest session.",
        ));
    }
    let uses_crypto = handlers.iter().chain(routes.iter().map(|(_, c)| c)).any(|h| {
        ["__z08Sha256(", "__z08Hmac(", "__z08TimingSafeEqual("]
            .iter()
            .any(|f| h.contains(f))
    });
    if uses_crypto {
        entries.push(entry(
            "",
            "mapped",
            "ctx.crypto becomes node:crypto: createHash and createHmac give the same lowercase-hex SHA-256 and HMAC-SHA-256 over UTF-8, a key named by a variable is read from process.env (trimmed, refused when unset), and timingSafeEqual compares in constant time; calls are synchronous",
        ));
    }
    if handlers
        .iter()
        .chain(routes.iter().map(|(_, c)| c))
        .any(|h| h.contains("__z08Mail.send("))
    {
        entries.push(entry(
            "",
            "review",
            "ctx.email.send becomes mail.send from \"zero\": the message is queued in the handler's transaction and handed to the site's transport after commit. It resolves true once queued, where 0.8 resolved on transport acceptance; check the job queue for refusals. On Spacefast, from must stay on the Space's sending domain",
        ));
    }
    let mut config = String::from(
        "import { action as __z08Action, boolean as __z08Boolean, db as __z08Db, defineConfig as __z08Config, HttpError as __z08HttpError, json as __z08Json, real as __z08Real, table as __z08Table, text as __z08Text, email as __z08Email, userProjection as __z08Users, collection as __z08Collection, richText as __z08RichText, route as __z08Route, mail as __z08Mail, type InferSelectModel } from \"zero\";\n",
    );
    if uses_crypto {
        config.push_str(CRYPTO_PRELUDE);
    }
    if !routes.is_empty() || !responses.is_empty() {
        config.push_str(super::endpoints::PRELUDE);
        config.push_str(&responses);
    }
    config.push_str(&prelude);
    if content_config.is_some() {
        config.push_str("import * as __z08Content from \"./__z08-content\";\n");
    }
    if let Some(posts) = native_posts.as_ref() {
        if tables.contains_key("posts") {
            errors.push(fail("/collections/posts", "posts collides with a schema table"));
        }
        match super::spacefast::posts(posts, entries) {
            Ok(code) => config.push_str(&code),
            Err(e) => errors.push(e),
        }
    }
    for t in tables.values() {
        config.push_str(&table_code(t));
    }
    if has_users {
        config.push_str(IDENTITY);
    }
    config.push_str(RANGE);
    let mut emitted = BTreeSet::new();
    for h in &helpers {
        if emitted.insert(h.as_str()) {
            config.push_str(h);
        }
    }
    config.push_str("function __z08Key(id: string): number {\n  const n = Number(id);\n  if (!/^[1-9][0-9]*$/.test(id) || n > 9007199254740991) throw new __z08HttpError(422, \"legacy_id_range\", \"Expected a positive safe integer ID.\");\n  return n;\n}\n");
    config.push_str(&format!(
        "export default __z08Config({{ name: {}, tables: {{ {} {} }},",
        q(&name),
        if content_config.is_some() {
            "...__z08Content.tables,"
        } else {
            ""
        },
        tables
            .keys()
            .map(|n| format!("{}: __z08_{n}", q(n)))
            .collect::<Vec<_>>()
            .join(", ")
    ));
    if let Some(content) = &content_config {
        config.push_str(if native_posts.is_some() {
            "collections: {...__z08Content.collections, posts: __z08Posts},"
        } else {
            "collections: __z08Content.collections,"
        });
        if content.contains("  relations,\n") {
            config.push_str("relations: __z08Content.relations,");
        }
    }
    if content_config.is_none() && native_posts.is_some() {
        config.push_str("collections: {posts: __z08Posts},");
    }
    if has_users {
        config.push_str("users: {members: __z08Members},");
        entries.push(entry(
            "/auth",
            "mapped",
            "Project WordPress users as members; migrate Spacefast accounts and sessions before activation.",
        ));
    }
    if !routes.is_empty() {
        let routes: Vec<&str> = routes.iter().map(|(_, code)| code.as_str()).collect();
        config.push_str(&format!("routes: {{\n{}\n}},", routes.join(",\n")));
    }
    config.push_str(&format!("actions: {{\n{}\n}} }});\n", handlers.join(",\n")));
    entries.push(entry("","review","Adoption requires the original MariaDB database and --space binding. IDs above Number.MAX_SAFE_INTEGER, existing sessions, client routes, realtime and deployment activation need separate validation. No deployment is performed."));
    Ok(Transformed {
        config,
        content_config,
        connector_roles: Value::Object(connector_roles),
    })
}

/// `x.json()` / `x.text()` with no arguments: a fetch body read, which returns a promise.
fn returns_body_read(e: &Expr) -> bool {
    matches!(&e.kind, E::Call { callee, args, .. }
        if args.is_empty() && matches!(&callee.kind, E::Member { prop, .. } if prop == "json" || prop == "text"))
}

/// ctx.crypto's three functions over node:crypto, with 0.8's results: SHA-256 and HMAC-SHA-256 as
/// lowercase hex over UTF-8, a named key read from the environment (trimmed, refused when unset;
/// the compiler lowers `createHmac(alg, process.env.NAME)` that way) or `{ value }` used as given
/// (also when the value is `ctx.env.X`),
/// and a comparison that answers false for inputs of different byte lengths, where Node throws.
const CRYPTO_PRELUDE: &str = r#"import { createHash as __z08Hash, createHmac as __z08Hmac, timingSafeEqual as __z08Equal } from "node:crypto";
function __z08Sha256(data: string): string {
  return __z08Hash("sha256").update(data).digest("hex");
}
function __z08InlineKey(key: string | undefined): string {
  if (key === undefined) throw new TypeError("crypto.hmacSha256 key must be a variable name or { value }");
  return key;
}
function __z08TimingSafeEqual(a: string, b: string): boolean {
  return Buffer.byteLength(a) === Buffer.byteLength(b) && __z08Equal(Buffer.from(a), Buffer.from(b));
}
"#;

/// A route path with parameter names erased, so two routes v2 would refuse as ambiguous compare equal.
fn route_shape(path: &str) -> String {
    path.split('/')
        .map(|s| {
            if s.starts_with(':') {
                ":"
            } else if s.starts_with('*') {
                "*"
            } else {
                s
            }
        })
        .collect::<Vec<_>>()
        .join("/")
}

/// Names declared with `declare const|let|var` at module level.
fn ambient_globals(source: &str) -> Vec<String> {
    use oxc_ast::ast::{BindingPattern, Declaration, Statement};
    let arena = Allocator::default();
    let parsed = Parser::new(&arena, source, SourceType::ts()).parse();
    let mut out = vec![];
    for s in &parsed.program.body {
        let declaration = match s {
            Statement::VariableDeclaration(v) => Some(&**v),
            Statement::ExportDeclaration(e) => match &e.declaration {
                Declaration::VariableDeclaration(v) => Some(&**v),
                _ => None,
            },
            _ => None,
        };
        if let Some(v) = declaration.filter(|v| v.declare) {
            for d in &v.declarations {
                if let BindingPattern::BindingIdentifier(id) = &d.id {
                    out.push(id.name.to_string());
                }
            }
        }
    }
    out
}

fn type_aliases(source: &str) -> String {
    use oxc_ast::ast::Statement;
    use oxc_span::GetSpan;
    let arena = Allocator::default();
    let parsed = Parser::new(&arena, source, SourceType::ts()).parse();
    parsed
        .program
        .body
        .iter()
        .filter(|s| {
            matches!(
                s,
                Statement::TSTypeAliasDeclaration(_) | Statement::TSInterfaceDeclaration(_)
            )
        })
        .map(|s| {
            let sp = s.span();
            format!("{}\n", &source[sp.start as usize..sp.end as usize])
        })
        .collect()
}

fn table_code(t: &Table) -> String {
    let n = &t.name;
    let mut out = format!(
        "const __z08_{n} = __z08Table({{\ncreatedAt: __z08Text().notNull().$defaultFn(() => new Date().toISOString()),\nupdatedAt: __z08Text().notNull().$defaultFn(() => new Date().toISOString()).$onUpdate(() => new Date().toISOString()),\n"
    );
    for f in &t.fields {
        out.push_str(&format!("{}: {},\n", q(&f.name), f.builder()));
    }
    out.push_str(&format!("}}, {{ existingTable: {}, api: {{ list:false, get:false, create:false, update:false, delete:false }}, agents:false }});\n",q(&t.physical)));
    out.push_str(&format!("function __z08_{n}_row(row: InferSelectModel<typeof __z08_{n}>) {{ return {{ id: String(row.id), createdAt: row.createdAt, updatedAt: row.updatedAt,"));
    for f in &t.fields {
        out.push_str(&format!(
            "{}: row.{}{},",
            q(&f.name),
            f.name,
            match &f.default {
                Some(value) => format!(" ?? {value}"),
                None if f.optional => " ?? undefined".into(),
                None => String::new(),
            }
        ));
    }
    out.push_str("}; }\n");
    out.push_str(&format!("function __z08_{n}_nullable(row: InferSelectModel<typeof __z08_{n}> | null | undefined) {{ return row == null ? null : __z08_{n}_row(row); }}\n"));
    let patch = t
        .fields
        .iter()
        .map(|f| format!("{}?: {}{}", q(&f.name), f.ty(), if f.optional { " | null" } else { "" }))
        .collect::<Vec<_>>()
        .join("; ");
    let stored_patch = t
        .fields
        .iter()
        .map(|f| {
            format!(
                "{}?: {}{}",
                q(&f.name),
                f.ty(),
                if f.optional && f.default.is_none() {
                    " | null"
                } else {
                    ""
                }
            )
        })
        .collect::<Vec<_>>()
        .join("; ");
    out.push_str(&format!(
        "async function __z08_{n}_update(id: string, patch: {{ {patch} }}) {{\nconst set: {{ {stored_patch}; updatedAt: string }} = {{ updatedAt: new Date().toISOString() }};\n"
    ));
    let known = t.fields.iter().map(|f| q(&f.name)).collect::<Vec<_>>().join(", ");
    out.push_str(&format!("const allowed: string[] = [{known}]; for (const key of Object.keys(patch)) {{ if (!allowed.includes(key)) throw new Error(\"Unknown table field.\"); }}\n"));
    for f in &t.fields {
        let fallback = match &f.default {
            Some(v) if f.optional => format!("patch.{} ?? {v}", f.name),
            Some(v) => format!("patch.{} === undefined ? {v} : patch.{}", f.name, f.name),
            None if f.optional => format!("patch.{} ?? null", f.name),
            None => format!("patch.{}", f.name),
        };
        out.push_str(&format!("if (Object.keys(patch).includes({})) {{\n", q(&f.name)));
        if !f.optional && f.default.is_none() {
            out.push_str(&format!(
                "if (patch.{} === undefined) throw new Error(\"Required table field.\");\n",
                f.name
            ));
        }
        out.push_str(&format!("set.{} = {fallback};\n}}\n", f.name));
    }
    out.push_str(&format!("const current = await __z08Db.query.{n}.findFirst({{ where: {{ id: __z08Key(id) }} }});\nif (current == null) return null;\nconst rows = await __z08Db.update(__z08_{n}).set(set).where({{ id: __z08Key(id) }}).returning();\nconst row = rows[0]; return row == null ? null : __z08_{n}_row(row);\n}}\n"));
    out.push_str(&format!("async function __z08_{n}_get(id: string) {{ const row = await __z08Db.query.{n}.findFirst({{ where: {{ id: __z08Key(id) }} }}); return row == null ? null : __z08_{n}_row(row); }}\n"));
    let insert = t
        .fields
        .iter()
        .map(|f| {
            format!(
                "{}{}: {}{}",
                q(&f.name),
                if f.optional || f.default.is_some() { "?" } else { "" },
                f.ty(),
                if f.optional { " | null" } else { "" }
            )
        })
        .collect::<Vec<_>>()
        .join("; ");
    out.push_str(&format!(
        "async function __z08_{n}_insert(input: {{ {insert} }}) {{ const allowed: string[] = [{known}]; for (const key of Object.keys(input)) {{ if (!allowed.includes(key)) throw new Error(\"Unknown table field.\"); }} const timestamp = new Date().toISOString(); const rows = await __z08Db.insert(__z08_{n}).values({{ createdAt: timestamp, updatedAt: timestamp,"
    ));
    for f in &t.fields {
        let value = if let Some(d) = &f.default {
            if f.optional {
                format!("input.{} ?? {d}", f.name)
            } else {
                format!("input.{} === undefined ? {d} : input.{}", f.name, f.name)
            }
        } else if f.optional {
            format!("input.{} ?? null", f.name)
        } else {
            format!("input.{}", f.name)
        };
        out.push_str(&format!("{}: {value},", q(&f.name)));
    }
    out.push_str(&format!("}}).returning(); const row = rows[0]; if (row == null) throw new Error(\"Insert returned no row.\"); return __z08_{n}_row(row); }}\n"));
    out
}

/// 0.8 SDK types with a structural v2 meaning.
fn legacy_type(name: &str) -> Option<&'static str> {
    Some(match name {
        "PaginationOptions" => "{ numItems: number; cursor: string | null }",
        "Id" => "string",
        _ => return None,
    })
}

/// The default expressions inside a binding pattern (`{ a = x }`, `[b = y]`).
fn pattern_defaults<'a>(pattern: &'a Pat, out: &mut Vec<&'a Expr>) {
    match pattern {
        Pat::Id(..) => {}
        Pat::Default(inner, value) => {
            out.push(value);
            pattern_defaults(inner, out);
        }
        Pat::Obj { props, .. } => props.iter().for_each(|(_, p)| pattern_defaults(p, out)),
        Pat::Arr { elems, .. } => elems.iter().flatten().for_each(|p| pattern_defaults(p, out)),
    }
}

/// A destructured parameter's source text split into its pattern and its type annotation,
/// without a `= default`. Uses the parser, so object types and nested patterns are read exactly.
fn destructured_parameter(text: &str) -> Option<(String, String)> {
    use oxc_span::GetSpan;
    let arena = Allocator::default();
    let wrapped = format!("({text}) => 0");
    let parsed = Parser::new(&arena, &wrapped, SourceType::ts())
        .parse_expression()
        .ok()?;
    let oxc_ast::ast::Expression::ArrowFunctionExpression(arrow) = &parsed else {
        return None;
    };
    let item = arrow.params.items.first()?;
    let annotation = item.type_annotation.as_ref()?;
    let pattern = &wrapped[item.pattern.span().start as usize..item.pattern.span().end as usize];
    let ty = &wrapped[annotation.type_annotation.span().start as usize..annotation.type_annotation.span().end as usize];
    Some((pattern.to_string(), ty.to_string()))
}

fn input_builder(ty: &TyAnn, source_ty: &str, optional: bool, at: &str) -> Result<String, Failure> {
    let (base, nullable) = match ty {
        TyAnn::Str => ("__z08Text()".into(), false),
        TyAnn::Num => ("__z08Real()".into(), false),
        TyAnn::Bool => ("__z08Boolean()".into(), false),
        TyAnn::Obj(_) | TyAnn::Ref(..) | TyAnn::Array(_) => (format!("__z08Json().$type<{source_ty}>()"), false),
        TyAnn::Union(xs)
            if xs
                .iter()
                .filter(|x| !matches!(x, TyAnn::Null | TyAnn::Undefined))
                .count()
                == 1 =>
        {
            let inner = xs
                .iter()
                .find(|x| !matches!(x, TyAnn::Null | TyAnn::Undefined))
                .expect("one");
            return input_builder(inner, source_ty, true, at);
        }
        _ => return Err(fail(at, "handler parameters need supported concrete TypeScript types")),
    };
    Ok(format!(
        "{base}{}",
        if optional || nullable { "" } else { ".notNull()" }
    ))
}

/// How a generated action runs: its write mode and identity sources.
#[derive(Clone, Copy)]
struct Mode<'a> {
    writes: bool,
    signed_in: bool,
    subjects: bool,
    /// 0.8 gave every handler but a read (`query`, readOnly endpoint) `ctx.email`; reads could be
    /// replayed, so their send rejected.
    sends: bool,
    /// Set for a 0.8 endpoint, which becomes a v2 route at the same method and path.
    route: Option<&'a super::endpoints::Route>,
}

fn handler(
    source: &str,
    f: &Func,
    tables: &BTreeMap<String, Table>,
    at: &str,
    name: &str,
    mode: Mode,
) -> Result<(String, Vec<String>), Failure> {
    let Mode {
        writes,
        signed_in,
        subjects,
        sends,
        route,
    } = mode;
    if route.is_some() {
        return endpoint(source, f, tables, at, name, mode);
    }
    // A handler that ignores its context may omit it; no input then follows.
    let ctx = match f.params.first().map(|p| &p.pat) {
        None => "__z08unused",
        Some(Pat::Id(ctx, _)) => ctx.as_str(),
        Some(_) => return Err(fail(at, "context destructuring requires a manual port")),
    };
    let mut input = vec![];
    let mut bindings = String::new();
    for (position, p) in f.params.iter().skip(1).enumerate() {
        // A default runs in the handler's bindings, where the 0.8 context parameter no longer
        // exists (it became `__z08ctx`); a default that reads it needs a manual port.
        let mut defaults: Vec<&Expr> = p.default.iter().collect();
        pattern_defaults(&p.pat, &mut defaults);
        if ctx != "__z08unused" && defaults.iter().any(|d| crate::lower::reads_identifier(d, ctx)) {
            return Err(fail(
                at,
                "rest and default parameters need a manual input schema when a default reads the context",
            ));
        }
        // 0.8 called `handler(ctx, ...args)` with the client's positional arguments; each
        // parameter becomes one named v2 input. A destructured parameter becomes one JSON input
        // named after its position, destructured in the handler exactly as 0.8 did.
        let destructured = match &p.pat {
            Pat::Obj { .. } | Pat::Arr { .. } => true,
            Pat::Default(inner, _) => matches!(&**inner, Pat::Obj { .. } | Pat::Arr { .. }),
            Pat::Id(..) => false,
        };
        if destructured {
            if p.rest {
                return Err(fail(
                    at,
                    "destructured input parameters require a manual port when they collect rest arguments",
                ));
            }
            let ann = p
                .ann
                .as_ref()
                .ok_or_else(|| fail(at, "handler parameters require explicit types"))?;
            if !matches!(ann, TyAnn::Obj(_) | TyAnn::Ref(..) | TyAnn::Array(_) | TyAnn::Tuple(_)) {
                return Err(fail(
                    at,
                    "destructured input parameters require a manual port unless typed as an object or array",
                ));
            }
            let text = raw(source, p.span);
            let (pattern, ty) =
                destructured_parameter(text).ok_or_else(|| fail(at, "cannot read the destructured parameter"))?;
            let n = format!("arg{}", position + 1);
            if f.params
                .iter()
                .skip(1)
                .any(|other| matches!(&other.pat, Pat::Id(id, _) if *id == n))
            {
                return Err(fail(at, "destructured input parameters require a manual port"));
            }
            let optional = p.optional || p.default.is_some();
            input.push(format!(
                "{}: __z08Json().$type<{ty}>(){}",
                q(&n),
                if optional { "" } else { ".notNull()" }
            ));
            let value = match &p.default {
                Some(default) => format!("__z08input.{n} ?? ({})", raw(source, default.span)),
                None if p.optional => format!("__z08input.{n} ?? undefined"),
                None => format!("__z08input.{n}"),
            };
            bindings.push_str(&format!("const {pattern}: {ty} = {value};\n"));
            continue;
        }
        let n = match &p.pat {
            Pat::Id(n, _) => n.clone(),
            Pat::Default(inner, _) => {
                let Pat::Id(n, _) = &**inner else {
                    unreachable!("destructured above")
                };
                n.clone()
            }
            _ => unreachable!("destructured above"),
        };
        ident(&n, at)?;
        let ann = p
            .ann
            .as_ref()
            .ok_or_else(|| fail(at, "handler parameters require explicit types"))?;
        let text = raw(source, p.span);
        // The annotation text, without a `...` or a `= default` around it.
        let ty = text
            .split_once(':')
            .map(|(_, s)| s.trim())
            .ok_or_else(|| fail(at, "missing parameter type"))?;
        let ty = match &p.default {
            Some(default) => ty
                .strip_suffix(raw(source, default.span))
                .map(|t| t.trim_end().trim_end_matches('=').trim_end())
                .ok_or_else(|| fail(at, "cannot read the parameter type"))?,
            None => ty,
        };
        if p.rest {
            // `...xs: T[]` received the trailing positional arguments: one JSON array input.
            if !matches!(ann, TyAnn::Array(_)) && !ty.ends_with("[]") {
                return Err(fail(
                    at,
                    "rest and default parameters need a manual input schema unless the rest type is an array",
                ));
            }
            input.push(format!("{}: __z08Json().$type<{ty}>()", q(&n)));
            bindings.push_str(&format!("const {n}: {ty} = __z08input.{n} ?? [];\n"));
            continue;
        }
        let optional = p.optional || p.default.is_some();
        input.push(format!("{}: {}", q(&n), input_builder(ann, ty, optional, at)?));
        match &p.default {
            // 0.8 applied a default only to a missing argument. A v2 optional input reads a missing
            // value as null, so `??` is exact when the declared type excludes null; when it admits
            // null, an explicit null and a missing argument can no longer be told apart.
            Some(default) => {
                // A named type may alias a nullable one; without resolving it, refuse.
                if matches!(ann, TyAnn::Null | TyAnn::Unknown | TyAnn::Any(_) | TyAnn::Ref(..))
                    || matches!(ann, TyAnn::Union(xs) if xs.iter().any(|x| matches!(x, TyAnn::Null | TyAnn::Ref(..))))
                {
                    return Err(fail(
                        at,
                        "rest and default parameters need a manual input schema when the type allows null: v2 inputs read a missing argument as null",
                    ));
                }
                bindings.push_str(&format!(
                    "const {n}: {ty} = __z08input.{n} ?? ({});\n",
                    raw(source, default.span)
                ))
            }
            None => bindings.push_str(&format!(
                "const {n} = __z08input.{n}{};\n",
                if p.optional { " ?? undefined" } else { "" }
            )),
        }
    }
    let mut r = Rewrite {
        source,
        ctx,
        tables,
        at,
        writes,
        subjects,
        uses_subject: false,
        action: name,
        request: None,
        returns: vec![],
        params: vec![],
        logs: false,
        helpers: vec![],
        sends,
        // Every return of the handler itself, through its control flow but not nested functions.
        returned: super::endpoints::returns(f),
        edits: vec![],
    };
    let body = match &f.body {
        FnBody::Expr(e) => {
            r.expr(e)?;
            // `async () => response.json()` returned the promise and JavaScript awaited it.
            let text = r.apply(e.span);
            format!(
                "return {};",
                if returns_body_read(e) {
                    format!("await {text}")
                } else {
                    text
                }
            )
        }
        FnBody::Block(stmts) => {
            for s in stmts {
                r.stmt(s)?;
            }
            // Locate the body through the parser, including functions with object-shaped types.
            let arena = Allocator::default();
            let expression = Parser::new(&arena, raw(source, f.span), SourceType::ts())
                .parse_expression()
                .map_err(|_| fail(at, "cannot locate handler body"))?;
            use oxc_ast::ast::Expression;
            let body = match &expression {
                Expression::ArrowFunctionExpression(f) => f.get_function_body(),
                Expression::FunctionExpression(f) => f.body.as_deref(),
                _ => None,
            }
            .ok_or_else(|| fail(at, "expected handler body"))?;
            r.apply(Span {
                start: f.span.start + body.span.start + 1,
                end: f.span.start + body.span.end - 1,
                ..f.span
            })
        }
    };
    let gate = if signed_in {
        "if (__z08ctx.user === null || String(__z08ctx.user.role) === \"guest\") throw new __z08HttpError(401, \"unauthorized\", \"Sign in to continue.\");\n"
    } else if r.uses_subject && writes {
        // 0.8 gave every visitor a guest identity; a v2 client starts a guest session on this 401.
        "if (__z08ctx.user === null) throw new __z08HttpError(401, \"unauthorized\", \"Sign in to continue.\");\n"
    } else {
        ""
    };
    // Writes run behind the 401 gate below, so their subject is never null.
    let subject = match (r.uses_subject, writes || signed_in) {
        (false, _) => "",
        (true, false) => "const __z08subject = await __z08Subject(__z08ctx.user);\n",
        (true, true) => "const __z08subject: string = await __z08Subject(__z08ctx.user) ?? \"\";\n",
    };
    if r.logs {
        r.helpers.push(LOG.into());
    }
    let code = format!(
        "{}: __z08Action({{ input: {{ {} }}, access: \"anyone\", agents: false, async handler(__z08input, __z08ctx) {{\n{gate}{subject}{bindings}{body}\n}} }})",
        q(name),
        input.join(", ")
    );
    Ok((code, r.helpers))
}

/// A 0.8 endpoint as a v2 route: `(ctx, req)` becomes `(req, __z08ctx)` and every return goes
/// through 0.8's result rules.
fn endpoint(
    source: &str,
    f: &Func,
    tables: &BTreeMap<String, Table>,
    at: &str,
    name: &str,
    mode: Mode,
) -> Result<(String, Vec<String>), Failure> {
    let route = mode.route.expect("route");
    let ctx = match f.params.first().map(|p| &p.pat) {
        None => "__z08unused",
        Some(Pat::Id(ctx, _)) => ctx.as_str(),
        Some(_) => return Err(fail(at, "context destructuring requires a manual port")),
    };
    let request = super::endpoints::request_name(f, at)?;
    if f.params.len() > 2 {
        return Err(fail(at, "endpoint handlers take (ctx, request)"));
    }
    let mut r = Rewrite {
        source,
        ctx,
        tables,
        at,
        writes: route.writes,
        subjects: mode.subjects,
        uses_subject: false,
        action: name,
        request,
        returns: vec![],
        params: route
            .path
            .split('/')
            .filter_map(|s| s.strip_prefix(':').or_else(|| s.strip_prefix('*')).map(str::to_owned))
            .collect(),
        logs: false,
        helpers: vec![],
        sends: mode.sends,
        returned: super::endpoints::returns(f),
        edits: vec![],
    };
    for span in super::endpoints::returns(f) {
        r.returns.push(span);
    }
    let body = match &f.body {
        FnBody::Expr(e) => {
            r.expr(e)?;
            // `async () => response.json()` returned the promise and 0.8 awaited it, as handlers do.
            let await_body = if returns_body_read(e) { "await " } else { "" };
            format!("return __z08EndpointValue({await_body}{});", r.apply(e.span))
        }
        FnBody::Block(stmts) => {
            for s in stmts {
                r.stmt(s)?;
            }
            let arena = Allocator::default();
            let expression = Parser::new(&arena, raw(source, f.span), SourceType::ts())
                .parse_expression()
                .map_err(|_| fail(at, "cannot locate handler body"))?;
            use oxc_ast::ast::Expression;
            let body = match &expression {
                Expression::ArrowFunctionExpression(f) => f.get_function_body(),
                Expression::FunctionExpression(f) => f.body.as_deref(),
                _ => None,
            }
            .ok_or_else(|| fail(at, "expected handler body"))?;
            // A block that falls off the end returned undefined: 0.8 answered 204.
            let falls_off = !matches!(stmts.last(), Some(Stmt::Return(..) | Stmt::Throw(_)));
            format!(
                "{}{}",
                r.apply(Span {
                    start: f.span.start + body.span.start + 1,
                    end: f.span.start + body.span.end - 1,
                    ..f.span
                }),
                if falls_off {
                    "\nreturn __z08EndpointValue(null);"
                } else {
                    ""
                }
            )
        }
    };
    let gate = if mode.signed_in {
        "if (__z08ctx.user === null || String(__z08ctx.user.role) === \"guest\") throw new __z08HttpError(401, \"unauthorized\", \"Sign in to continue.\");\n"
    } else if r.uses_subject && route.writes {
        // As for actions: 0.8 gave every visitor a guest identity; a v2 client starts one on this 401.
        "if (__z08ctx.user === null) throw new __z08HttpError(401, \"unauthorized\", \"Sign in to continue.\");\n"
    } else {
        ""
    };
    // A writing route runs behind the gate above, so its subject is never null, as for actions.
    let subject = match (r.uses_subject, route.writes || mode.signed_in) {
        (false, _) => "",
        (true, false) => "const __z08subject = await __z08Subject(__z08ctx.user);\n",
        (true, true) => "const __z08subject: string = await __z08Subject(__z08ctx.user) ?? \"\";\n",
    };
    let req = request.unwrap_or("__z08req");
    if r.logs {
        r.helpers.push(LOG.into());
    }
    let handler = format!("access: \"anyone\", async handler({req}, __z08ctx) {{\n{gate}{subject}{body}\n}}");
    let code = route_entry(name, &route.method, &route.path, &handler);
    // 0.8's `/a/:splat` answered `/a` too; v2's `*splat` needs a segment, so the same handler can
    // also take the bare prefix, where `params.splat` reads "". The caller decides whether it may.
    if let Some(prefix) = &route.prefix {
        r.helpers.push(format!(
            "{PREFIX_MARK}{}",
            route_entry(&format!("{name}__z08prefix"), &route.method, prefix, &handler)
        ));
    }
    Ok((code, r.helpers))
}

/// Marks the bare-prefix route `endpoint()` returns among its helpers.
const PREFIX_MARK: &str = "\0prefix-route\0";

fn route_entry(name: &str, method: &str, path: &str, handler: &str) -> String {
    format!(
        "{}: __z08Route({{ method: {}, path: {}, {handler} }})",
        q(name),
        q(method),
        q(path)
    )
}

struct Rewrite<'a> {
    source: &'a str,
    ctx: &'a str,
    tables: &'a BTreeMap<String, Table>,
    at: &'a str,
    writes: bool,
    /// A `members` projection carries each user's 0.8 Space subject.
    subjects: bool,
    uses_subject: bool,
    /// The generated action's name, which keeps helper names and cursor sites unique.
    action: &'a str,
    /// An endpoint handler's request parameter, rewritten to the v2 route request.
    request: Option<&'a str>,
    /// Return-expression spans of an endpoint body, wrapped in 0.8's result rules.
    returns: Vec<Span>,
    /// The endpoint path's `:name` parameters.
    params: Vec<String>,
    /// `ctx.log` became `console.*`, which v2 compiles to the PHP error log.
    logs: bool,
    /// Module-level helpers generated for this handler (one per paginated call site).
    helpers: Vec<String>,
    /// Whether 0.8 gave this handler `ctx.email`.
    sends: bool,
    /// Spans of values the handler returns directly (an expression body or `return <e>`).
    returned: Vec<Span>,
    edits: Vec<(Span, String)>,
}
impl Rewrite<'_> {
    fn apply(&self, span: Span) -> String {
        let mut out = raw(self.source, span).to_string();
        let mut edits = self
            .edits
            .iter()
            .filter(|(s, _)| s.start >= span.start && s.end <= span.end)
            .collect::<Vec<_>>();
        edits.sort_by_key(|(s, _)| std::cmp::Reverse(s.start));
        for (s, text) in edits {
            out.replace_range((s.start - span.start) as usize..(s.end - span.start) as usize, text);
        }
        out
    }
    /// `ctx.crypto.hmacSha256({ key, data })`: a literal variable name becomes
    /// `process.env["NAME"]`, so the compiler applies 0.8's trimmed, required key; `{ value }`
    /// is the key as given.
    fn hmac(&mut self, args: &[ArrEl]) -> Result<String, Failure> {
        let at = pointer(&pointer(self.at, "ctx"), "crypto");
        let manual = || {
            fail(
                &at,
                "hmacSha256 needs a literal { key, data } object for the automatic port",
            )
        };
        let [
            ArrEl::Item(Expr {
                kind: E::Object(props), ..
            }),
        ] = args
        else {
            return Err(manual());
        };
        let (mut key, mut data) = (None, None);
        for p in props {
            match p {
                Prop::KV(PropKey::Static(k), v) if k == "key" => key = Some(v),
                Prop::KV(PropKey::Static(k), v) if k == "data" => data = Some(v),
                _ => return Err(manual()),
            }
        }
        let (Some(key), Some(data)) = (key, data) else {
            return Err(manual());
        };
        let key = match &key.kind {
            E::Str(name)
                if !name.is_empty()
                    && name.chars().all(|c| c == '_' || c.is_ascii_alphanumeric())
                    && !name.starts_with(|c: char| c.is_ascii_digit()) =>
            {
                format!("process.env[{}]!", q(name))
            }
            E::Object(kv) => match kv.as_slice() {
                // Used exactly as given, as 0.8 did: the helper keeps a `ctx.env.X` value off the
                // compiler's trimmed, required `createHmac(alg, process.env.X)` path.
                [Prop::KV(PropKey::Static(k), value)] if k == "value" => {
                    format!("__z08InlineKey({})", self.render(value)?)
                }
                _ => return Err(manual()),
            },
            _ => return Err(manual()),
        };
        let data = self.render(data)?;
        Ok(format!("__z08Hmac(\"sha256\", {key}).update({data}).digest(\"hex\")"))
    }

    fn render(&mut self, e: &Expr) -> Result<String, Failure> {
        let old = std::mem::take(&mut self.edits);
        self.expr(e)?;
        let text = self.apply(e.span);
        self.edits = old;
        Ok(text)
    }
    fn expr(&mut self, e: &Expr) -> Result<(), Failure> {
        if let E::Ident(name) = &e.kind
            && name.starts_with("__z08")
        {
            return Err(fail(self.at, "reserved generated identifier"));
        }
        if self.request.is_some() && self.request_use(e)? {
            return Ok(());
        }
        // `ctx.invalidate(...)` named the live queries a mutation refreshed. v2 announces every
        // committed write by table, so the call has no work left; it becomes a no-op.
        if let E::Call { callee, .. } = &e.kind
            && let E::Member { obj, prop, .. } = &callee.kind
            && prop == "invalidate"
            && matches!(&obj.kind, E::Ident(n) if n == self.ctx)
        {
            if !self.writes {
                return Err(fail(
                    &pointer(&pointer(self.at, "ctx"), "invalidate"),
                    "invalidate is only available in legacy mutations",
                ));
            }
            self.edits.push((e.span, "undefined".into()));
            return Ok(());
        }
        if let E::Await(inner) = &e.kind
            && let Some(text) = self.db(inner)?
        {
            self.edits.push((e.span, text));
            return Ok(());
        }
        // ctx.crypto returned promises; v2's crypto is synchronous with the same output.
        let (call, awaited) = match &e.kind {
            E::Await(inner) => (inner.as_ref(), true),
            _ => (e, false),
        };
        if let E::Call {
            callee,
            args,
            optional: false,
            ..
        } = &call.kind
            && let E::Member { obj, prop, .. } = &callee.kind
            && matches!(&obj.kind, E::Member { obj, prop: c, .. } if c == "crypto" && matches!(&obj.kind, E::Ident(n) if n == self.ctx))
        {
            if !["sha256", "hmacSha256", "timingSafeEqual"].contains(&prop.as_str()) {
                return Err(fail(
                    &pointer(&pointer(self.at, "ctx"), "crypto"),
                    format!("ctx.crypto.{prop} has no v2 counterpart"),
                ));
            }
            let rendered = args
                .iter()
                .map(|a| match a {
                    ArrEl::Item(e) => self.render(e),
                    ArrEl::Spread(_) => Err(fail(self.at, "spread crypto arguments need a manual port")),
                })
                .collect::<Result<Vec<_>, _>>()?;
            let text = match prop.as_str() {
                "hmacSha256" => self.hmac(args)?,
                "sha256" => format!("__z08Sha256({})", rendered.join(", ")),
                _ => format!("__z08TimingSafeEqual({})", rendered.join(", ")),
            };
            // An unawaited call is returned or awaited later in 0.8 code; the value stands in for
            // its promise either way. Storing it unawaited (`const p = ctx.crypto...`) and then
            // awaiting it is refused by the v2 compiler's await check, which is the port pointer.
            let _ = awaited;
            self.edits.push((e.span, format!("({text})")));
            return Ok(());
        }
        // ctx.email.send(message) → mail.send(message): the same EmailMessage, queued in the
        // handler's transaction and handed to the site's transport after commit, as 0.8's outbox did.
        if let E::Call {
            callee,
            args,
            optional: false,
            ..
        } = &call.kind
            && let E::Member { obj, prop, .. } = &callee.kind
            && matches!(&obj.kind, E::Member { obj, prop: svc, .. } if svc == "email" && matches!(&obj.kind, E::Ident(n) if n == self.ctx))
        {
            let at = pointer(&pointer(self.at, "ctx"), "email");
            if prop != "send" {
                return Err(fail(&at, format!("ctx.email.{prop} has no v2 counterpart")));
            }
            if !self.sends {
                return Err(fail(
                    &at,
                    "reads (queries, readOnly endpoints) could not send email in 0.8 either; move the send to a mutation, action or writing endpoint",
                ));
            }
            let [ArrEl::Item(message)] = args.as_slice() else {
                return Err(fail(&at, "ctx.email.send takes one message"));
            };
            let message = self.render(message)?;
            // 0.8 resolved to true on transport acceptance; v2 resolves once the message is queued.
            let send = format!("(await __z08Mail.send({message}), true)");
            // A returned promise was awaited by the 0.8 runtime; one left floating raced the response.
            if !awaited && !self.returned.contains(&e.span) {
                return Err(fail(&at, "an unawaited ctx.email.send needs a manual port; await it"));
            }
            self.edits.push((e.span, format!("({send})")));
            return Ok(());
        }
        if let Some(text) = self.db(e)? {
            self.edits.push((e.span, text));
            return Ok(());
        }
        if let E::Member { obj, prop, .. } = &e.kind
            && matches!(&obj.kind,E::Member{obj,prop:auth,..} if auth=="auth"&&matches!(&obj.kind,E::Ident(n) if n==self.ctx))
        {
            let text = match prop.as_str() {
                "isSignedIn" | "isAuthenticated" => {
                    "(__z08ctx.user !== null && String(__z08ctx.user.role) !== \"guest\")"
                }
                "isGuest" => "(__z08ctx.user !== null && String(__z08ctx.user.role) === \"guest\")",
                "displayName" => {
                    "(__z08ctx.user === null ? \"Signed out\" : String(__z08ctx.user.role) === \"guest\" ? \"Guest\" : __z08ctx.user?.name ?? \"Signed out\")"
                }
                "userId" if self.subjects => {
                    self.uses_subject = true;
                    "__z08subject"
                }
                _ => {
                    return Err(fail(
                        &pointer(&pointer(&pointer(self.at, "ctx"), "auth"), prop),
                        if prop == "userId" {
                            "ctx.auth.userId maps only for Space Users (pass --users): its usr_ subjects are copied with each user. Spacefast account principals and guest:<id> identities have no v2 user"
                        } else {
                            "legacy profiles and auth methods require an explicit identity mapping"
                        },
                    ));
                }
            };
            self.edits.push((e.span, text.into()));
            return Ok(());
        }
        // requireSignedIn() and requireIdentity() keep their 0.8 refusal and return the auth value.
        if let E::Call {
            callee,
            args,
            optional: false,
            ..
        } = &e.kind
            && args.is_empty()
            && let E::Member { obj, prop, .. } = &callee.kind
            && matches!(&obj.kind,E::Member{obj,prop:auth,..} if auth=="auth"&&matches!(&obj.kind,E::Ident(n) if n==self.ctx))
            && (prop == "requireSignedIn" || prop == "requireIdentity")
            && self.subjects
        {
            self.uses_subject = true;
            let helper = if prop == "requireSignedIn" {
                "__z08RequireSignedIn"
            } else {
                "__z08RequireIdentity"
            };
            self.edits
                .push((e.span, format!("{helper}(__z08ctx.user, __z08subject)")));
            return Ok(());
        }
        if let E::Member { obj, prop, .. } = &e.kind
            && prop == "auth"
            && matches!(&obj.kind, E::Ident(n) if n == self.ctx)
            && self.subjects
        {
            self.uses_subject = true;
            self.edits
                .push((e.span, "__z08Auth(__z08ctx.user, __z08subject)".into()));
            return Ok(());
        }
        if let Some(text) = self.service(e)? {
            self.edits.push((e.span, text));
            return Ok(());
        }
        if let E::Member { obj, prop, .. } = &e.kind
            && matches!(&obj.kind, E::Ident(n) if n == self.ctx)
        {
            return Err(fail(
                &pointer(&pointer(self.at, "ctx"), prop),
                "unsupported context service; port its authority and behavior explicitly",
            ));
        }
        match &e.kind {
            E::Ident(n) if n == self.ctx => {
                return Err(fail(
                    &pointer(self.at, "ctx"),
                    "unsupported context service, alias or dynamic use; port its behavior explicitly",
                ));
            }
            E::Array(a) => {
                for x in a {
                    self.arr(x)?;
                }
            }
            E::Object(p) => {
                for p in p {
                    match p {
                        Prop::KV(k, v) => {
                            if let PropKey::Computed(k) = k {
                                self.expr(k)?;
                            }
                            self.expr(v)?;
                        }
                        Prop::Spread(e) => self.expr(e)?,
                    }
                }
            }
            E::Template(_, es) | E::Sequence(es) => {
                for e in es {
                    self.expr(e)?;
                }
            }
            E::Func(f) => {
                if f.params.iter().any(|p| {
                    let mut ns = vec![];
                    p.pat.bindings(&mut ns);
                    ns.iter().any(|(n, _)| *n == self.ctx || n.starts_with("__z08"))
                }) {
                    return Err(fail(self.at, "shadowed context binding requires a manual port"));
                }
                match &f.body {
                    FnBody::Expr(e) => self.expr(e)?,
                    FnBody::Block(ss) => {
                        for s in ss {
                            self.stmt(s)?;
                        }
                    }
                }
            }
            E::Unary(_, e)
            | E::IsNullish(e, _)
            | E::Update { target: e, .. }
            | E::Chain(e)
            | E::Await(e)
            | E::As(e, _)
            | E::NonNull(e) => self.expr(e)?,
            E::Binary(_, a, b)
            | E::Logical(_, a, b)
            | E::Instanceof(a, b)
            | E::In(a, b)
            | E::Assign {
                target: a, value: b, ..
            } => {
                self.expr(a)?;
                self.expr(b)?;
            }
            E::Cond(a, b, c) => {
                self.expr(a)?;
                self.expr(b)?;
                self.expr(c)?;
            }
            E::Call { callee, args, .. } | E::New { callee, args, .. } => {
                self.expr(callee)?;
                for a in args {
                    self.arr(a)?;
                }
            }
            E::Member { obj, .. } => self.expr(obj)?,
            E::Index { obj, index, .. } => {
                self.expr(obj)?;
                self.expr(index)?;
            }
            E::Class(_) => return Err(fail(self.at, "classes inside migrated handlers require a manual port")),
            _ => {}
        }
        Ok(())
    }
    fn arr(&mut self, a: &ArrEl) -> Result<(), Failure> {
        match a {
            ArrEl::Item(e) | ArrEl::Spread(e) => self.expr(e),
        }
    }
    fn stmt(&mut self, s: &Stmt) -> Result<(), Failure> {
        match s {
            Stmt::Decl { pat, init, .. } => {
                let mut ns = vec![];
                pat.bindings(&mut ns);
                if ns.iter().any(|(n, _)| *n == self.ctx || n.starts_with("__z08")) {
                    return Err(fail(self.at, "reserved or shadowed binding"));
                }
                if let Some(e) = init {
                    self.expr(e)?;
                }
            }
            Stmt::Expr(e) | Stmt::Throw(e) | Stmt::ExportDefault(e) => self.expr(e)?,
            Stmt::Return(Some(e), _) => {
                self.expr(e)?;
                // `return response.json()` returned a promise the 0.8 runtime awaited; an endpoint's
                // return then passes through 0.8's result rules.
                let body_read = returns_body_read(e);
                if body_read || self.returns.contains(&e.span) {
                    let text = self.apply(e.span);
                    let text = if body_read { format!("await {text}") } else { text };
                    self.edits
                        .retain(|(s, _)| !(s.start >= e.span.start && s.end <= e.span.end));
                    self.edits.push((
                        e.span,
                        if self.returns.contains(&e.span) {
                            format!("__z08EndpointValue({text})")
                        } else {
                            text
                        },
                    ));
                }
            }
            Stmt::Return(None, span) if self.returns.contains(span) => {
                self.edits.push((*span, "return __z08EndpointValue(null);".into()));
            }
            Stmt::If(e, a, b) => {
                self.expr(e)?;
                for s in a {
                    self.stmt(s)?;
                }
                if let Some(b) = b {
                    for s in b {
                        self.stmt(s)?;
                    }
                }
            }
            Stmt::Block(ss) => {
                for s in ss {
                    self.stmt(s)?;
                }
            }
            Stmt::For {
                init,
                test,
                update,
                body,
                ..
            } => {
                if let Some(s) = init {
                    self.stmt(s)?;
                }
                for e in [test, update].into_iter().flatten() {
                    self.expr(e)?;
                }
                for s in body {
                    self.stmt(s)?;
                }
            }
            Stmt::ForOf { pat, iter, body, .. } => {
                let mut names = vec![];
                pat.bindings(&mut names);
                if names.iter().any(|(n, _)| *n == self.ctx || n.starts_with("__z08")) {
                    return Err(fail(self.at, "shadowed context or reserved binding"));
                }
                self.expr(iter)?;
                for s in body {
                    self.stmt(s)?;
                }
            }
            Stmt::ForIn { target, iter, body, .. } => {
                self.expr(target)?;
                self.expr(iter)?;
                for s in body {
                    self.stmt(s)?;
                }
            }
            Stmt::While(iter, body) | Stmt::DoWhile(body, iter) => {
                self.expr(iter)?;
                for s in body {
                    self.stmt(s)?;
                }
            }
            Stmt::Labeled(_, body, _) => self.stmt(body)?,
            Stmt::Try {
                block,
                handler,
                finalizer,
                param,
            } => {
                if param
                    .as_ref()
                    .is_some_and(|(name, _)| name == self.ctx || name.starts_with("__z08"))
                {
                    return Err(fail(self.at, "shadowed context or reserved catch binding"));
                }
                for ss in [Some(block), handler.as_ref(), finalizer.as_ref()]
                    .into_iter()
                    .flatten()
                {
                    for s in ss {
                        self.stmt(s)?;
                    }
                }
            }
            Stmt::Switch { disc, cases, .. } => {
                self.expr(disc)?;
                for (e, ss) in cases {
                    if let Some(e) = e {
                        self.expr(e)?;
                    }
                    for s in ss {
                        self.stmt(s)?;
                    }
                }
            }
            Stmt::Func(_) => return Err(fail(self.at, "named nested functions require a manual port")),
            _ => {}
        }
        Ok(())
    }
    /// `.paginate({ numItems, cursor })` keeps 0.8's keyset contract: the index order, then
    /// `createdAt` and `id`; `numItems` clamped to 1..1000; `{ page, isDone, continueCursor }`; and
    /// a cursor that only this call site accepts. The helper takes every range value as a
    /// parameter, so it captures nothing from the handler.
    #[allow(clippy::too_many_arguments)]
    fn paginate(
        &mut self,
        at: &str,
        t: &Table,
        index: &str,
        columns: &[String],
        direction: &str,
        params: &[(String, String, &str, &str, String)],
        options: &str,
    ) -> Result<String, Failure> {
        let field = |c: &str| t.fields.iter().find(|f| f.name == c);
        if let Some(c) = columns.iter().find(|c| field(c).is_some_and(|f| f.optional)) {
            return Err(fail(
                at,
                format!("pagination over optional field {c} needs a manual port; 0.8 cursors order NULL keys"),
            ));
        }
        let name = &t.name;
        let helper = format!("__z08page_{}_{}", self.action, self.page_sites());
        let site = format!("{}:{}:{name}:{index}:{direction}", self.action, self.page_sites());
        // Cursor keys: one per ordered column, encoded as strings so the cursor stays plain text.
        let kinds: Vec<&str> = columns
            .iter()
            .map(|c| match field(c).map(Field::ty) {
                Some("number") => "number",
                Some("boolean") => "boolean",
                _ if c == "id" => "number",
                _ => "string",
            })
            .collect();
        let cmp = if direction == "desc" { "lt" } else { "gt" };
        let range = params
            .iter()
            .enumerate()
            .map(|(i, (f, op, kind, _, _))| {
                if op == "eq" {
                    format!("{{ {}: {{ eq: p{i} as {} }} }}", q(f), params[i].3)
                } else {
                    format!("{{ {}: {{ {op}: __z08Range{kind}(p{i}) }} }}", q(f))
                }
            })
            .collect::<Vec<_>>();
        let branches = (0..columns.len())
            .map(|i| {
                let mut parts = (0..i)
                    .map(|j| format!("{{ {}: {{ eq: k{j} }} }}", q(&columns[j])))
                    .collect::<Vec<_>>();
                parts.push(format!("{{ {}: {{ {cmp}: k{i} }} }}", q(&columns[i])));
                format!("{{ AND: [{}] }}", parts.join(", "))
            })
            .collect::<Vec<_>>()
            .join(", ");
        let order = columns
            .iter()
            .map(|c| format!("{}: {}", q(c), q(direction)))
            .collect::<Vec<_>>()
            .join(", ");
        let signature = params
            .iter()
            .enumerate()
            .map(|(i, (_, _, _, ty, _))| format!("p{i}: {ty} | null | undefined"))
            .collect::<Vec<_>>();
        let decode = kinds
            .iter()
            .enumerate()
            .map(|(i, k)| match *k {
                "number" => format!("k{i} = Number(parts[{i}] ?? \"\"); if (!Number.isFinite(k{i})) throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\");"),
                "boolean" => format!("if (parts[{i}] !== \"1\" && parts[{i}] !== \"0\") throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\"); k{i} = parts[{i}] === \"1\";"),
                _ => format!("k{i} = parts[{i}] ?? \"\";"),
            })
            .collect::<Vec<_>>()
            .join("\n    ");
        let initial = kinds
            .iter()
            .enumerate()
            .map(|(i, k)| match *k {
                "number" => format!("let k{i} = 0;"),
                "boolean" => format!("let k{i} = false;"),
                _ => format!("let k{i} = \"\";"),
            })
            .collect::<Vec<_>>()
            .join(" ");
        let encode = columns
            .iter()
            .zip(&kinds)
            .map(|(c, k)| match *k {
                "boolean" => format!("(last.{c} ? \"1\" : \"0\")"),
                "string" => format!("last.{c}"),
                _ => format!("String(last.{c})"),
            })
            .collect::<Vec<_>>()
            .join(", ");
        let filter = |extra: Option<String>| {
            let mut all = range.clone();
            all.extend(extra);
            format!("where: {{ AND: [{}] }}, ", all.join(", "))
        };
        let scope = (0..params.len())
            .map(|i| format!("p{i} ?? null"))
            .collect::<Vec<_>>()
            .join(", ");
        let mut sig = vec!["options: { numItems: number; cursor: string | null }".to_string()];
        sig.extend(signature);
        // Columns read from the last raw row are typed by the table, so `last.<c>` is defined.
        self.helpers.push(format!(
            "async function {helper}({}) {{
  const size = Math.max(1, Math.min(1000, Number(options.numItems) || 0));
  const cursor = options.cursor;
  // Like 0.8's query fingerprint, a cursor only continues the same filter values.
  const scope = JSON.stringify([{scope}]) ?? \"\";
  {initial} let after = false;
  if (cursor !== null && cursor !== \"\") {{
    let parsed: {{ site?: unknown; scope?: unknown; keys?: unknown }} = {{}};
    try {{ parsed = JSON.parse(cursor) as {{ site?: unknown; scope?: unknown; keys?: unknown }}; }} catch (e) {{ throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\"); }}
    const site = parsed.site;
    const keys = parsed.keys;
    if (site !== {} || parsed.scope !== scope || typeof keys !== \"string\") throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\");
    // The key tuple is a JSON array of strings, so a key may hold any character.
    let decoded: unknown = null;
    try {{ decoded = JSON.parse(keys) as unknown; }} catch (e) {{ throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\"); }}
    if (!Array.isArray(decoded)) throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\");
    const parts: string[] = [];
    for (const part of decoded as unknown[]) {{ if (typeof part !== \"string\") throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\"); parts.push(part); }}
    if (parts.length !== {}) throw new __z08HttpError(400, \"invalid_cursor\", \"Invalid Zero pagination cursor.\");
    {decode}
    after = true;
  }}
  const rows = after
    ? await __z08Db.query.{name}.findMany({{ {}orderBy: {{ {order} }}, limit: size + 1 }})
    : await __z08Db.query.{name}.findMany({{ {}orderBy: {{ {order} }}, limit: size + 1 }});
  const items = rows.slice(0, size);
  const last = items[items.length - 1];
  const isDone = rows.length <= size;
  return {{ page: items.map(__z08_{name}_row), isDone, continueCursor: isDone || last == null ? null : JSON.stringify({{ site: {}, scope, keys: JSON.stringify([{encode}]) ?? \"\" }}) }};
}}
",
            sig.join(", "),
            q(&site),
            columns.len(),
            filter(Some(format!("{{ OR: [{branches}] }}"))),
            filter(None),
            q(&site),
        ));
        let mut call = vec![format!("__z08PageOptions({options})")];
        call.extend(params.iter().map(|(_, _, _, _, v)| v.clone()));
        Ok(format!("(await {helper}({}))", call.join(", ")))
    }

    /// 0.8's request API on a v2 route request (`{ method, path, params, query, headers, body }`).
    /// Returns `true` when `e` was rewritten as a whole.
    fn request_use(&mut self, e: &Expr) -> Result<bool, Failure> {
        let req = self.request.expect("request");
        let is_req = |e: &Expr| matches!(&e.kind, E::Ident(n) if n == req);
        let at = pointer(self.at, "request");
        let refuse = |what: &str| -> Result<bool, Failure> {
            Err(fail(
                &pointer(&at, what),
                "this request API has no v2 route equivalent; port it explicitly",
            ))
        };
        let (inner, awaited) = match &e.kind {
            E::Await(inner) => (inner.as_ref(), true),
            _ => (e, false),
        };
        if let E::Call {
            callee,
            args,
            optional: false,
            ..
        } = &inner.kind
            && let E::Member {
                obj,
                prop,
                optional: false,
                ..
            } = &callee.kind
        {
            if is_req(obj) {
                let text = match (prop.as_str(), args.is_empty(), awaited) {
                    ("text", true, true) => format!("{req}.body"),
                    ("json", true, true) => {
                        format!("(JSON.parse({req}.body === \"\" ? \"null\" : {req}.body) as unknown)")
                    }
                    _ => return refuse(prop),
                };
                self.edits.push((e.span, text));
                return Ok(true);
            }
            if let E::Member {
                obj: base, prop: part, ..
            } = &obj.kind
                && is_req(base)
                && (part == "headers" || part == "query")
                && !awaited
            {
                // `req.query` is a URLSearchParams-like RouteQuery: 0.8's calls map one to one.
                if part == "query" {
                    if !["get", "getAll", "has"].contains(&prop.as_str()) {
                        return refuse(&format!("query.{prop}"));
                    }
                    for a in args {
                        self.arr(a)?;
                    }
                    return Ok(true);
                }
                let [ArrEl::Item(arg)] = args.as_slice() else {
                    return refuse(&format!("{part}.{prop}"));
                };
                let key = self.render(arg)?;
                let key = format!("String({key}).toLowerCase()");
                let text = match prop.as_str() {
                    "get" => format!("({req}.headers[{key}] ?? null)"),
                    "has" => format!("({req}.headers[{key}] !== undefined)"),
                    _ => return refuse(&format!("{part}.{prop}")),
                };
                self.edits.push((e.span, text));
                return Ok(true);
            }
        }
        // `req.params.<name>`: decoded on both sides, and always present once the route matched.
        if let E::Member { obj, prop: param, .. } = &e.kind
            && let E::Member { obj, prop, .. } = &obj.kind
            && is_req(obj)
            && prop == "params"
        {
            if !self.params.contains(param) {
                return refuse(&format!("params.{param}"));
            }
            self.edits
                .push((e.span, format!("({req}.params[{}] ?? \"\")", q(param))));
            return Ok(true);
        }
        if let E::Member { obj, prop, .. } = &e.kind
            && is_req(obj)
        {
            return match prop.as_str() {
                // Uppercase on both sides.
                "method" => Ok(true),
                other => refuse(other),
            };
        }
        if is_req(e) {
            return refuse("value");
        }
        Ok(false)
    }

    /// Context services with a direct v2 counterpart:
    /// - `ctx.env.NAME` / `ctx.env["NAME"]` → `process.env["NAME"]`, the same process or wp-config value
    ///   (0.8 withheld secret variables named as signing keys; v2 has no such keys to withhold);
    /// - `ctx.log.info|warn|error|debug(...)` → `console.*`, written to the PHP error log.
    ///
    /// `ctx.invalidate` belongs to the realtime mapping.
    fn service(&mut self, e: &Expr) -> Result<Option<String>, Failure> {
        let ctx = self.ctx;
        let is = |e: &Expr, name: &str| matches!(&e.kind, E::Member { obj, prop, .. } if prop == name && matches!(&obj.kind, E::Ident(n) if n == ctx));
        match &e.kind {
            E::Member { obj, prop, .. } if is(obj, "env") => {
                ident(prop, &pointer(&pointer(self.at, "ctx"), "env"))?;
                Ok(Some(format!("process.env[{}]", q(prop))))
            }
            E::Index { obj, index, .. } if is(obj, "env") => {
                let E::Str(name) = &index.kind else {
                    return Err(fail(
                        &pointer(&pointer(self.at, "ctx"), "env"),
                        "env names must be literal",
                    ));
                };
                Ok(Some(format!("process.env[{}]", q(name))))
            }
            E::Call {
                callee,
                args,
                optional: false,
                ..
            } => {
                let E::Member { obj, prop, .. } = &callee.kind else {
                    return Ok(None);
                };
                if is(obj, "log") && ["info", "warn", "error", "debug"].contains(&prop.as_str()) {
                    let rendered = args
                        .iter()
                        .map(|a| match a {
                            ArrEl::Item(e) => self.render(e),
                            ArrEl::Spread(_) => Err(fail(self.at, "spread log arguments need a manual port")),
                        })
                        .collect::<Result<Vec<_>, _>>()?;
                    self.logs = true;
                    return Ok(Some(format!("console.{prop}({})", rendered.join(", "))));
                }
                Ok(None)
            }
            _ => Ok(None),
        }
    }

    fn page_sites(&self) -> usize {
        self.helpers.len()
    }

    fn db(&mut self, e: &Expr) -> Result<Option<String>, Failure> {
        let mut cursor = e;
        let mut chain = Vec::new();
        while let E::Call {
            callee,
            args,
            optional: false,
            ..
        } = &cursor.kind
        {
            let E::Member {
                obj,
                prop,
                optional: false,
                ..
            } = &callee.kind
            else {
                return Ok(None);
            };
            let a = args
                .iter()
                .map(|a| match a {
                    ArrEl::Item(e) => Ok(e),
                    _ => Err(fail(self.at, "spread database arguments require a manual port")),
                })
                .collect::<Result<Vec<_>, _>>()?;
            chain.push((prop.as_str(), a));
            cursor = obj;
        }
        let E::Member { obj, prop: name, .. } = &cursor.kind else {
            return Ok(None);
        };
        if !matches!(&obj.kind,E::Member{obj,prop,..} if prop=="db"&&matches!(&obj.kind,E::Ident(n) if n==self.ctx)) {
            return Ok(None);
        }
        let tables = self.tables;
        let t = tables
            .get(name)
            .ok_or_else(|| fail(self.at, format!("unknown database table {name}")))?;
        chain.reverse();
        let Some((op, a)) = chain.first() else {
            return Err(fail(self.at, "database handles cannot escape a handler"));
        };
        let at = pointer(&pointer(&pointer(self.at, "ctx"), "db"), name);
        if ["insert", "update", "delete"].contains(op) && !self.writes {
            return Err(fail(&at, "writes are only allowed in legacy mutations"));
        }
        let code = match *op {
            "get" if a.len() == 1 && chain.len() == 1 => format!("await __z08_{name}_get({})", self.render(a[0])?),
            "insert" if a.len() == 1 && chain.len() == 1 => {
                format!("await __z08_{name}_insert({})", self.render(a[0])?)
            }
            "update" if a.len() == 2 && chain.len() == 1 => {
                format!(
                    "await __z08_{name}_update({}, {})",
                    self.render(a[0])?,
                    self.render(a[1])?
                )
            }
            "delete" if a.len() == 1 && chain.len() == 1 => format!(
                "(await __z08Db.delete(__z08_{name}).where({{id: __z08Key({})}}).returning()).length > 0",
                self.render(a[0])?
            ),
            "withIndex" if (a.len() == 1 || a.len() == 2) && chain.len() >= 2 => {
                let index = string(a[0], &at)?;
                let columns = if index == "by_creation" {
                    vec!["createdAt".into(), "id".into()]
                } else {
                    let mut fields = t
                        .indexes
                        .get(&index)
                        .ok_or_else(|| fail(&at, format!("unknown index {index}")))?
                        .clone();
                    for f in ["createdAt", "id"] {
                        if !fields.iter().any(|s| s == f) {
                            fields.push(f.into());
                        }
                    }
                    fields
                };
                let mut filters = Vec::new();
                let mut params: Vec<(String, String, &str, &str, String)> = Vec::new();
                if let Some(range) = a.get(1) {
                    let E::Func(f) = &range.kind else {
                        return Err(fail(&at, "index range must be an arrow function"));
                    };
                    let [p] = f.params.as_slice() else {
                        return Err(fail(&at, "range must have one parameter"));
                    };
                    let Pat::Id(range, _) = &p.pat else {
                        return Err(fail(&at, "range parameter must be a name"));
                    };
                    let FnBody::Expr(initial) = &f.body else {
                        return Err(fail(&at, "range must be an expression"));
                    };
                    let mut current = initial;
                    let mut steps = vec![];
                    while let E::Call { callee, .. } = &current.kind {
                        let (_, a) = args(current)?;
                        let E::Member { obj, prop, .. } = &callee.kind else {
                            return Err(fail(&at, "invalid index range"));
                        };
                        if a.len() != 2 || !["eq", "gt", "gte", "lt", "lte"].contains(&prop.as_str()) {
                            return Err(fail(&at, "unsupported index comparison"));
                        }
                        let field = string(a[0], &at)?;
                        if !columns.contains(&field) {
                            return Err(fail(&at, "range field is not in the selected index"));
                        }
                        if t.fields.iter().any(|f| f.name == field && f.optional) {
                            return Err(fail(&at, "optional-field range ordering requires a manual port"));
                        }
                        let value = self.render(a[1])?;
                        let value = if field == "id" {
                            format!("__z08Key({value})")
                        } else {
                            value
                        };
                        steps.push((field, prop.as_str(), value));
                        current = obj;
                    }
                    if !matches!(&current.kind,E::Ident(n) if n==range) {
                        return Err(fail(&at, "invalid range root"));
                    }
                    steps.reverse();
                    let mut position = 0;
                    let mut range_field = None;
                    for (field, op, value) in steps {
                        let expected = columns.get(position);
                        if expected != Some(&field) && range_field.as_ref() != Some(&field) {
                            return Err(fail(
                                &at,
                                "range comparisons must follow the declared index field order",
                            ));
                        }
                        if op == "eq" && expected == Some(&field) {
                            position += 1;
                            range_field = None;
                        } else {
                            range_field = Some(field.clone());
                        }
                        // 0.8 compared a null equality as IS NULL and refused null ranges.
                        let (kind, ty) = match t.fields.iter().find(|f| f.name == field).map(|f| f.ty()) {
                            Some("number") => ("Real", "number"),
                            Some("boolean") => ("Boolean", "boolean"),
                            _ if field == "id" => ("Real", "number"),
                            _ => ("Text", "string"),
                        };
                        // The runtime compares `eq: null` as IS NULL, as 0.8 did; the cast only
                        // states that to TypeScript. Ranges refuse null like 0.8's runner.
                        filters.push(if op == "eq" {
                            format!("{{ {}: {{ eq: ({value}) as {ty} }} }}", q(&field))
                        } else {
                            format!("{{ {}: {{ {op}: __z08Range{kind}({value}) }} }}", q(&field))
                        });
                        params.push((field.clone(), op.to_string(), kind, ty, value));
                    }
                }
                let mut direction = "asc".to_string();
                let mut end = None;
                for (method, a) in chain.iter().skip(1) {
                    if end.is_some() {
                        return Err(fail(
                            &at,
                            "database builder cannot be reused after its terminal operation",
                        ));
                    }
                    match *method {
                        "order" if a.len() == 1 => {
                            let d = string(a[0], &at)?;
                            if d != "asc" && d != "desc" {
                                return Err(fail(&at, "order must be asc or desc"));
                            }
                            direction = d;
                        }
                        "collect" | "count" | "first" if a.is_empty() => end = Some((*method, None)),
                        "take" if a.len() == 1 => end = Some(("take", Some(self.render(a[0])?))),
                        "paginate" if a.len() == 1 => end = Some(("paginate", Some(self.render(a[0])?))),
                        _ => {
                            return Err(fail(
                                &at,
                                format!(
                                    "unsupported database operation {method}; pagination and dynamic builders need a manual port"
                                ),
                            ));
                        }
                    }
                }
                let Some((terminal, limit)) = end else {
                    return Err(fail(
                        &at,
                        "database query must end with collect, count, first, take or paginate",
                    ));
                };
                if terminal == "paginate" {
                    let options = limit.expect("paginate options");
                    return Ok(Some(
                        self.paginate(&at, t, &index, &columns, &direction, &params, &options)?,
                    ));
                }
                let order = columns
                    .iter()
                    .map(|n| format!("{}: {}", q(n), q(&direction)))
                    .collect::<Vec<_>>()
                    .join(", ");
                let filter = if filters.is_empty() {
                    String::new()
                } else {
                    format!("where: {{ AND: [{}] }},", filters.join(", "))
                };
                let limit = match terminal {
                    "first" => "limit: 1,".into(),
                    "take" => format!("limit: Math.max(0, Math.min(1000, {})),", limit.expect("take limit")),
                    _ => String::new(),
                };
                let projection = if terminal == "count" {
                    "columns: { id: true },"
                } else {
                    ""
                };
                let read = format!(
                    "await __z08Db.query.{name}.findMany({{ {filter} orderBy: {{ {order} }}, {limit} {projection} }})"
                );
                match terminal {
                    "count" => format!("({read}).length"),
                    "first" => format!(
                        "__z08_{name}_nullable(await __z08Db.query.{name}.findFirst({{ {filter} orderBy: {{ {order} }} }}))"
                    ),
                    _ => format!("({read}).map(__z08_{name}_row)"),
                }
            }
            _ => return Err(fail(&at, format!("unsupported database operation {op}"))),
        };
        Ok(Some(format!("({code})")))
    }
}
