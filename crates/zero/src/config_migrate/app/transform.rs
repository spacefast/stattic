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
        return Err(fail("", format!("invalid TypeScript: {}", d.message)));
    }
    let file = diag::register_file(path, text);
    Lowerer {
        file,
        module: 0,
        class_context: std::cell::Cell::new(false),
        super_call: std::cell::Cell::new(false),
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
    let mut aliases = BTreeMap::new();
    let mut prelude = String::new();
    let mut root = None;
    for stmt in &body {
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
    let name = root
        .get("name")
        .map(|e| string(e, "/name"))
        .transpose()?
        .unwrap_or_else(|| "migrated-app".into());
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
                    handler(source, f, &tables, &at, &name, section == "mutations", signed_in)
                })();
                match result {
                    Ok(h) => {
                        handlers.push(h);
                        entries.push(entry(&at,"mapped","v2 action with named input properties and legacy row results; REST URL/envelope and error transport follow v2"));
                    }
                    Err(e) => errors.push(e),
                }
            }
        }
    }
    let mut config = String::from(
        "import { action as __z08Action, boolean as __z08Boolean, db as __z08Db, defineConfig as __z08Config, HttpError as __z08HttpError, json as __z08Json, real as __z08Real, table as __z08Table, text as __z08Text, email as __z08Email, userProjection as __z08Users, collection as __z08Collection, richText as __z08RichText, type InferSelectModel } from \"zero\";\n",
    );
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
    if users
        || root
            .get("auth")
            .is_some_and(|auth| !matches!(literal(auth), Ok(Value::Bool(false))))
    {
        config.push_str("users: {members: __z08Users({email: __z08Email().notNull().unique().max(191), name: __z08Text().notNull()})},");
        entries.push(entry(
            "/auth",
            "mapped",
            "Project WordPress users as members; migrate Spacefast accounts and sessions before activation.",
        ));
    }
    config.push_str(&format!("actions: {{\n{}\n}} }});\n", handlers.join(",\n")));
    entries.push(entry("","review","Adoption requires the original MariaDB database and --space binding. IDs above Number.MAX_SAFE_INTEGER, existing sessions, client routes, realtime and deployment activation need separate validation. No deployment is performed."));
    Ok(Transformed {
        config,
        content_config,
        connector_roles: Value::Object(connector_roles),
    })
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

fn handler(
    source: &str,
    f: &Func,
    tables: &BTreeMap<String, Table>,
    at: &str,
    name: &str,
    writes: bool,
    signed_in: bool,
) -> Result<String, Failure> {
    let Some(first) = f.params.first() else {
        return Err(fail(at, "handler must declare its context parameter"));
    };
    let Pat::Id(ctx, _) = &first.pat else {
        return Err(fail(at, "context destructuring requires a manual port"));
    };
    let mut input = vec![];
    let mut bindings = String::new();
    for p in f.params.iter().skip(1) {
        let Pat::Id(n, _) = &p.pat else {
            return Err(fail(at, "destructured input parameters require a manual port"));
        };
        ident(n, at)?;
        if p.rest || p.default.is_some() {
            return Err(fail(at, "rest and default parameters need a manual input schema"));
        }
        let ann = p
            .ann
            .as_ref()
            .ok_or_else(|| fail(at, "handler parameters require explicit types"))?;
        let text = raw(source, p.span);
        let ty = text
            .split_once(':')
            .map(|(_, s)| s.trim())
            .ok_or_else(|| fail(at, "missing parameter type"))?;
        input.push(format!("{}: {}", q(n), input_builder(ann, ty, p.optional, at)?));
        bindings.push_str(&format!(
            "const {n} = __z08input.{n}{};\n",
            if p.optional { " ?? undefined" } else { "" }
        ));
    }
    let mut r = Rewrite {
        source,
        ctx,
        tables,
        at,
        writes,
        edits: vec![],
    };
    let body = match &f.body {
        FnBody::Expr(e) => {
            r.expr(e)?;
            format!("return {};", r.apply(e.span))
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
    } else {
        ""
    };
    Ok(format!(
        "{}: __z08Action({{ input: {{ {} }}, access: \"anyone\", agents: false, async handler(__z08input, __z08ctx) {{\n{gate}{bindings}{body}\n}} }})",
        q(name),
        input.join(", ")
    ))
}

struct Rewrite<'a> {
    source: &'a str,
    ctx: &'a str,
    tables: &'a BTreeMap<String, Table>,
    at: &'a str,
    writes: bool,
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
                "displayName" => "(__z08ctx.user === null ? \"Signed out\" : __z08ctx.user.name)",
                _ => {
                    return Err(fail(
                        &pointer(&pointer(&pointer(self.at, "ctx"), "auth"), prop),
                        "legacy principal IDs, profiles and auth methods require an explicit identity mapping; no wp:<id> identity is invented",
                    ));
                }
            };
            self.edits.push((e.span, text.into()));
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
            E::Call { callee, args, .. } | E::New { callee, args } => {
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
            Stmt::Return(Some(e), _) => self.expr(e)?,
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
            Stmt::While(iter, body) => {
                self.expr(iter)?;
                for s in body {
                    self.stmt(s)?;
                }
            }
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
                        filters.push(format!("{{ {}: {{ {op}: {value} }} }}", q(&field)));
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
                    return Err(fail(&at, "database query must end with collect, count, first or take"));
                };
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
