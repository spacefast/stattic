//! Astro's `src/content.config.ts` read as SpacePress kinds (docs/decisions/2026-11-14-astro-content-config.md).
//!
//! A SpacePress project may keep the content collections of an Astro site it grew from:
//!
//! ```ts
//! // src/content.config.ts
//! import { defineCollection, z } from "astro:content";
//! import { glob } from "astro/loaders";
//!
//! const blog = defineCollection({
//!   loader: glob({ pattern: "**/*.md", base: "./src/content/blog" }),
//!   schema: z.object({ title: z.string(), pubDate: z.coerce.date(), tags: z.array(z.string()).optional() }),
//! });
//! export const collections = { blog };
//! ```
//!
//! Each collection becomes a `collection()` whose files sync from the glob's folder, exactly as if
//! `sp/` declared it: `export const blog = collection({ ...fields }, { sync: "src/content/blog/**/*.md" })`.
//! The file is read, never run: Astro's modules are not SpacePress's to evaluate, so only the plain forms
//! Astro's documentation writes are understood, and anything else is a build error at its place.
//!
//! The module this writes is TypeScript in SpacePress's own forms, loaded like any module under `sp/`:
//! the kinds are then checked, compiled and synced by the code that handles every kind.

use crate::diag::{self, Code, Diag, Result, Span};
use oxc_allocator::Allocator;
use oxc_ast::ast as a;
use oxc_parser::Parser;
use oxc_span::{GetSpan, SourceType};
use std::borrow::Cow;
use std::fmt::Write as _;
use std::path::{Path, PathBuf};

/// Where Astro keeps its collections, relative to the project root.
pub const CONFIG: &str = "src/content.config.ts";
/// The module the collections are written into, as if it were under `sp/` (it never is on disk).
pub const MODULE: &str = "sp/_astro_content.ts";

/// One field of a collection, as the TypeScript expression of its SpacePress builder.
struct Field {
    name: String,
    builder: String,
}

struct Collection {
    name: String,
    /// The sync pattern: `<base>/**/*.md`, a folder tree, so a file's path is its slug as in Astro.
    sync: String,
    fields: Vec<Field>,
    /// The field the title option points at.
    title: String,
    /// `**/*.md`: a folder tree. `*.md`: the folder alone.
    nested: bool,
}

/// The SpacePress module for `src/content.config.ts`, or `None` when the project has none.
pub fn module(fs: &dyn crate::loader::Fs, root: &Path) -> Result<Option<(PathBuf, String)>> {
    let path = root.join(CONFIG);
    if !fs.is_file(&path) {
        return Ok(None);
    }
    let source = fs
        .read_to_string(&path)
        .map_err(|e| Diag::new(Span::NONE, format!("can't read {CONFIG}: {e}")).code(Code::Import))?;
    let file = diag::register_file(&path, &source);
    let collections = read(&source, file)?;
    Ok(Some((root.join(MODULE), write(&collections))))
}

fn write(collections: &[Collection]) -> String {
    // The builders under names no collection can take (a collection may be called `text`).
    let mut out = String::from(
        "// Generated from src/content.config.ts: each Astro content collection as a SpacePress kind.\nimport { collection as __collection, text as __text, slug as __slug, richText as __richText, number as __number, integer as __integer, boolean as __boolean, date as __date, url as __url, email as __email, json as __json, select as __select } from \"spacepress\";\n",
    );
    for c in collections {
        let mut fields: Vec<String> = c
            .fields
            .iter()
            .map(|f| format!("{}: {}", key(&f.name), f.builder))
            .collect();
        // A folder tree's file name is its slug; a flat folder's slug follows the title, as
        // `spacepress sync` names its files by slug.
        fields.push(if c.nested {
            "slug: __slug()".into()
        } else {
            format!("slug: __slug({{ source: {:?} }})", c.title)
        });
        fields.push("body: __richText().optional()".into());
        let _ = writeln!(
            out,
            "export const {} = __collection({{ {} }}, {{ title: {:?}, content: \"body\", hierarchical: {}, sync: {:?}, access: {{ read: \"anyone\", create: \"editor\", update: \"editor\", delete: \"editor\" }} }});",
            c.name,
            fields.join(", "),
            c.title,
            c.nested,
            c.sync
        );
    }
    out
}

fn key(name: &str) -> String {
    if name.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
        && !name.starts_with(|c: char| c.is_ascii_digit())
    {
        name.to_string()
    } else {
        format!("{name:?}")
    }
}

/// Fields SpacePress adds to a synced, hierarchical kind with drafts, or that its files never hold.
const RESERVED: &[(&str, &str)] = &[
    ("id", "every kind has an integer `id`"),
    ("uid", "SpacePress's sync identifies each file by `uid`"),
    ("slug", "the file's name is its slug"),
    ("body", "the Markdown below the front matter is the body"),
    ("parent", "the file's folder is its parent"),
    ("order", "SpacePress adds `order` to a folder tree"),
    ("status", "SpacePress adds `status` for drafts"),
    ("publishedAt", "SpacePress adds `publishedAt` for drafts"),
    ("locale", "SpacePress reserves it for translations"),
    ("translations", "SpacePress reserves it for translations"),
    ("translationOf", "SpacePress reserves it for translations"),
    ("translationGroup", "SpacePress reserves it for translations"),
];

/// Collection names SpacePress serves a route of its own under (extract.rs `RESERVED_COLLECTIONS`).
const RESERVED_KINDS: &[&str] = &[
    "media", "files", "globals", "actions", "me", "_schema", "auth", "services", "search",
];
/// WordPress post types are at most 20 characters, and SpacePress's are `sp_<name>`.
const MAX_NAME: usize = 20 - "sp_".len();

/// A name SpacePress can use as an identifier: a letter, then letters, digits and underscores.
fn ident(name: &str) -> bool {
    let mut chars = name.chars();
    chars.next().is_some_and(|c| c.is_ascii_alphabetic()) && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
}

struct Reader<'s> {
    source: &'s str,
    file: u32,
}

fn span(file: u32, s: oxc_span::Span) -> Span {
    Span {
        file,
        start: s.start,
        end: s.end,
    }
}

/// The callee name of `name(...)`, if the expression is a call of a plain identifier.
fn called<'b>(e: &'b a::Expression<'_>) -> Option<(&'b a::CallExpression<'b>, &'b str)>
where
    'b: 'b,
{
    match e.get_inner_expression() {
        a::Expression::CallExpression(c) => match c.callee.get_inner_expression() {
            a::Expression::Identifier(i) => Some((c, i.name.as_str())),
            _ => None,
        },
        _ => None,
    }
}

fn read(source: &str, file: u32) -> Result<Vec<Collection>> {
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, SourceType::ts()).parse();
    if let Some(e) = parsed.diagnostics.errors().next() {
        let at = e
            .labels
            .first()
            .map(|l| Span {
                file,
                start: l.offset(),
                end: l.offset() + l.len(),
            })
            .unwrap_or(Span { file, start: 0, end: 0 });
        return Err(Diag::new(at, format!("syntax error: {}", e.message)).code(Code::Syntax));
    }
    // Every top-level `const x = ...`, exported or not. Nothing else in the file is read: imports,
    // helpers and statements the collections don't use are Astro's.
    let mut consts: Vec<(&str, &a::Expression<'_>)> = Vec::new();
    for stmt in &parsed.program.body {
        let declaration = match stmt {
            a::Statement::VariableDeclaration(v) => v.as_ref(),
            a::Statement::ExportDeclaration(e) => match &e.declaration {
                a::Declaration::VariableDeclaration(v) => v.as_ref(),
                _ => continue,
            },
            _ => continue,
        };
        for d in &declaration.declarations {
            if let (a::BindingPattern::BindingIdentifier(id), Some(init)) = (&d.id, &d.init) {
                consts.push((id.name.as_str(), init));
            }
        }
    }
    let Some((_, collections)) = consts.iter().find(|(n, _)| *n == "collections") else {
        return Err(Diag::new(
            Span { file, start: 0, end: 0 },
            "src/content.config.ts defines no `collections`",
        )
        .help("export them: `export const collections = { blog }`")
        .code(Code::Schema));
    };
    let a::Expression::ObjectExpression(object) = collections.get_inner_expression() else {
        return Err(unsupported(
            file,
            collections.span(),
            "this value of `collections`",
            "write `export const collections = { blog, docs }`",
        ));
    };
    let reader = Reader { source, file };
    let mut out = Vec::new();
    for prop in &object.properties {
        let a::ObjectPropertyKind::ObjectProperty(p) = prop else {
            return Err(unsupported(
                file,
                prop.span(),
                "a spread in `collections`",
                "list each collection by name",
            ));
        };
        let Some(name) = property_name(file, p)? else {
            return Err(unsupported(
                file,
                p.span,
                "a computed collection name",
                "name it: `blog: defineCollection(...)`",
            ));
        };
        // `blog` (shorthand or a reference) names a `const blog = defineCollection(...)`. A
        // collection defined elsewhere (imported) isn't read: it stays Astro's.
        let value = match p.value.get_inner_expression() {
            a::Expression::Identifier(id) => match consts.iter().find(|(n, _)| *n == id.name.as_str()) {
                Some((_, e)) => *e,
                None => continue,
            },
            other => other,
        };
        let Some(c) = reader.define(&name, value)? else {
            continue;
        };
        let key = span(file, p.key.span());
        let physical = crate::schema::physical_name(&name);
        if !ident(&name) || name.starts_with("__") {
            return Err(Diag::new(key, format!("collection `{name}` can't be a SpacePress kind's name"))
                .help("name it with letters, digits and underscores, starting with a letter: it is the kind's API name")
                .code(Code::Schema));
        }
        if RESERVED_KINDS.contains(&physical.as_str()) {
            return Err(
                Diag::new(key, format!("collection `{name}` can't be a SpacePress kind's name"))
                    .help(format!(
                        "SpacePress serves a route of its own at `{physical}`; rename the collection"
                    ))
                    .code(Code::Schema),
            );
        }
        if physical.len() > MAX_NAME {
            return Err(Diag::new(key, format!("collection `{name}` can't be a SpacePress kind's name"))
                .help(format!("a kind is a WordPress post type `sp_{physical}`, at most 20 characters; use a name of at most {MAX_NAME}"))
                .code(Code::Schema));
        }
        out.push(c);
    }
    Ok(out)
}

/// A property's name as UTF-8, or `None` for a computed key. A name with a lone UTF-16 surrogate,
/// which UTF-8 can't hold, is refused.
fn property_name<'a>(file: u32, p: &a::ObjectProperty<'a>) -> Result<Option<Cow<'a, str>>> {
    let Some(name) = p.key.static_name().filter(|_| !p.computed) else {
        return Ok(None);
    };
    match name.into_cow_str() {
        Some(name) => Ok(Some(name)),
        None => Err(lone_surrogate(file, p.key.span())),
    }
}

/// A string value as UTF-8; one with a lone UTF-16 surrogate, which UTF-8 can't hold, is refused.
fn utf8<'a>(file: u32, s: &a::StringLiteral<'a>) -> Result<&'a str> {
    s.value.as_str().ok_or_else(|| lone_surrogate(file, s.span))
}

fn lone_surrogate(file: u32, s: oxc_span::Span) -> Diag {
    unsupported(
        file,
        s,
        "a string with a lone UTF-16 surrogate",
        "use complete characters; a lone surrogate can't be stored as UTF-8",
    )
}

fn unsupported(file: u32, s: oxc_span::Span, what: &str, help: &str) -> Diag {
    Diag::new(
        span(file, s),
        format!("{what} in src/content.config.ts can't be read as a SpacePress kind"),
    )
    .help(help)
    .code(Code::Schema)
}

impl<'s> Reader<'s> {
    fn at(&self, s: oxc_span::Span) -> Span {
        span(self.file, s)
    }

    /// The kind for one collection, or `None` for a collection whose files aren't SpacePress's (any
    /// loader other than `glob()`, such as `spLoader()` reading a SpacePress site, stays Astro's).
    fn define(&self, name: &str, value: &a::Expression<'_>) -> Result<Option<Collection>> {
        let fail = |s: oxc_span::Span, what: &str, help: &str| {
            Diag::new(
                self.at(s),
                format!("{what} in src/content.config.ts can't be read as a SpacePress kind"),
            )
            .help(help)
            .code(Code::Schema)
        };
        let Some((call, "defineCollection")) = called(value) else {
            return Err(fail(
                value.span(),
                "this collection",
                "write it as `defineCollection({ loader: glob(...), schema: z.object({ ... }) })`",
            ));
        };
        let Some(a::Expression::ObjectExpression(options)) = call
            .arguments
            .first()
            .and_then(|e| e.as_expression())
            .map(|e| e.get_inner_expression())
        else {
            return Err(fail(call.span, "this collection", "pass `defineCollection` one object"));
        };
        let (mut loader, mut schema) = (None, None);
        for prop in &options.properties {
            if let a::ObjectPropertyKind::ObjectProperty(p) = prop {
                match p.key.static_name().as_ref().and_then(|n| n.as_str()) {
                    Some("loader") => loader = Some(&p.value),
                    Some("schema") => schema = Some(&p.value),
                    _ => {}
                }
            }
        }
        let Some(loader) = loader else {
            return Err(fail(
                call.span,
                &format!("collection `{name}` without a loader"),
                "add `loader: glob({ pattern: \"**/*.md\", base: \"./src/content/<name>\" })`",
            ));
        };
        if let a::Expression::Identifier(id) = loader.get_inner_expression() {
            return Err(fail(
                id.span,
                &format!("the loader `{}` of `{name}`", id.name),
                "write the loader in place, `loader: glob({ pattern, base })`, so SpacePress can read which files it syncs",
            ));
        }
        if !matches!(called(loader), Some((_, "glob"))) {
            return Ok(None);
        }
        let (sync, nested) = self.glob(loader, name)?;
        let Some(schema) = schema else {
            return Err(fail(
                call.span,
                &format!("collection `{name}` without a schema"),
                "add `schema: z.object({ title: z.string() })`: SpacePress needs the fields to make a kind",
            ));
        };
        let schema = match schema.get_inner_expression() {
            // `schema: ({ image }) => z.object(...)`: a helper it uses fails at that field.
            a::Expression::ArrowFunctionExpression(f) => match f.get_expression() {
                Some(e) => e,
                None => {
                    return Err(fail(
                        f.span,
                        "a schema function with a block body",
                        "return `z.object({ ... })` directly",
                    ));
                }
            },
            other => other,
        };
        let fields = self.object(schema)?;
        for f in &fields {
            if !ident(&f.name) {
                return Err(Diag::new(
                    self.at(f.key),
                    format!("field `{}` of `{name}` can't be a SpacePress field", f.name),
                )
                .help("name it with letters, digits and underscores, starting with a letter")
                .code(Code::Schema));
            }
            if let Some((_, why)) = RESERVED.iter().find(|(r, _)| *r == f.name) {
                return Err(Diag::new(
                    self.at(f.span),
                    format!("field `{}` of `{name}` can't be a SpacePress field", f.name),
                )
                .help(format!("{why}; rename the field"))
                .code(Code::Schema));
            }
        }
        let title = ["title", "name", "heading"]
            .into_iter()
            .find(|t| fields.iter().any(|f| f.name == *t && f.text && f.required))
            .map(String::from)
            .ok_or_else(|| {
                fail(
                    schema.span(),
                    &format!("collection `{name}` without a title"),
                    "add `title: z.string()` (or `name`): a SpacePress kind has a title, the post's title in WordPress",
                )
            })?;
        Ok(Some(Collection {
            name: name.to_string(),
            sync,
            fields: fields
                .into_iter()
                .map(|f| Field {
                    name: f.name,
                    builder: f.builder,
                })
                .collect(),
            title,
            nested,
        }))
    }

    /// `glob({ pattern, base })` → SpacePress's sync pattern for the base folder.
    fn glob(&self, loader: &a::Expression<'_>, name: &str) -> Result<(String, bool)> {
        let fail = |s: oxc_span::Span, help: &str| {
            Diag::new(
                self.at(s),
                format!("the loader of `{name}` can't be read as a SpacePress kind's files"),
            )
            .help(help.to_string())
            .code(Code::Schema)
        };
        let a::Expression::CallExpression(call) = loader.get_inner_expression() else {
            return Err(fail(
                loader.span(),
                "use `glob({ pattern: \"**/*.md\", base: \"./src/content/<name>\" })`: SpacePress syncs a folder of Markdown files",
            ));
        };
        if !matches!(call.callee.get_inner_expression(), a::Expression::Identifier(i) if i.name == "glob") {
            return Err(fail(
                call.span,
                "use `glob({ pattern: \"**/*.md\", base: \"./src/content/<name>\" })`: SpacePress syncs a folder of Markdown files; a remote or `file()` loader has no files for it",
            ));
        }
        let Some(a::Expression::ObjectExpression(options)) = call
            .arguments
            .first()
            .and_then(|e| e.as_expression())
            .map(|e| e.get_inner_expression())
        else {
            return Err(fail(call.span, "pass `glob` one object: `{ pattern, base }`"));
        };
        let (mut pattern, mut base) = (None, None);
        for prop in &options.properties {
            let a::ObjectPropertyKind::ObjectProperty(p) = prop else {
                return Err(fail(prop.span(), "write `pattern` and `base` out"));
            };
            let value = match p.value.get_inner_expression() {
                a::Expression::StringLiteral(s) => utf8(self.file, s)?.to_string(),
                a::Expression::TemplateLiteral(t) if t.expressions.is_empty() && t.quasis.len() == 1 => {
                    t.quasis[0].value.raw.to_string()
                }
                other => return Err(fail(other.span(), "write the pattern and base as plain strings")),
            };
            match p.key.static_name().as_ref().and_then(|n| n.as_str()) {
                Some("pattern") => pattern = Some((value, p.value.span())),
                Some("base") => base = Some((value, p.value.span())),
                Some("generateId") => {
                    return Err(fail(
                        p.span,
                        "remove `generateId`: a SpacePress kind's entry is named by its file, its slug",
                    ));
                }
                _ => return Err(fail(p.span, "`glob` takes `pattern` and `base`")),
            }
        }
        let Some((pattern, pattern_span)) = pattern else {
            return Err(fail(call.span, "add `pattern: \"**/*.md\"`"));
        };
        // `*.md` reads the folder alone and `**/*.md` the folders below it too; both name an entry by
        // its path below the base, which a folder tree also does (`2024/post.md` is `2024/post`).
        // `**/*.md` is a folder tree: an entry's path below the base is its slug and its folders its
        // parents, as Astro names entries. `*.md` is the folder alone. Patterns that leave files out
        // (`[^_]*.md`) aren't: sync reads every Markdown file in the folder.
        let nested = match pattern.as_str() {
            "**/*.md" => true,
            "*.md" => false,
            _ => {
                return Err(fail(
                    pattern_span,
                    &format!(
                        "the pattern `{pattern}` isn't one SpacePress syncs: use \"**/*.md\" or \"*.md\"; SpacePress syncs every Markdown file in the folder, so .mdx, .json and patterns that leave files out (`_drafts`) aren't read"
                    ),
                ));
            }
        };
        let base = base.unwrap_or_else(|| (".".to_string(), call.span));
        let dir = base.0.trim_start_matches("./").trim_end_matches('/');
        if dir.is_empty()
            || dir == "."
            || dir.starts_with('/')
            || dir
                .split('/')
                .any(|p| p == ".." || p.is_empty() || p.contains(['*', '\\', ':']))
        {
            return Err(fail(
                base.1,
                "set `base` to a folder inside the project, e.g. \"./src/content/blog\"",
            ));
        }
        Ok((
            if nested {
                format!("{dir}/**/*.md")
            } else {
                format!("{dir}/*.md")
            },
            nested,
        ))
    }

    /// `z.object({ ... })` → its fields.
    fn object(&self, schema: &a::Expression<'_>) -> Result<Vec<Mapped>> {
        let (base, chain) = self.chain(schema)?;
        if base.0 != "object" || !chain.is_empty() {
            return Err(Diag::new(
                self.at(schema.span()),
                "the schema in src/content.config.ts can't be read as a SpacePress kind",
            )
            .help("write it as `z.object({ ... })`; extend, merge, transforms and refinements aren't read")
            .code(Code::Schema));
        }
        let Some(a::Expression::ObjectExpression(shape)) = base
            .1
            .first()
            .and_then(|e| e.as_expression())
            .map(|e| e.get_inner_expression())
        else {
            return Err(Diag::new(
                self.at(schema.span()),
                "the schema in src/content.config.ts can't be read as a SpacePress kind",
            )
            .help("pass `z.object` an object of fields")
            .code(Code::Schema));
        };
        let mut out = Vec::new();
        for prop in &shape.properties {
            let a::ObjectPropertyKind::ObjectProperty(p) = prop else {
                return Err(Diag::new(
                    self.at(prop.span()),
                    "a spread in the schema can't be read as a SpacePress kind",
                )
                .help("write each field out")
                .code(Code::Schema));
            };
            let Some(name) = property_name(self.file, p)? else {
                return Err(Diag::new(
                    self.at(p.span),
                    "a computed field name can't be read as a SpacePress kind",
                )
                .help("name the field")
                .code(Code::Schema));
            };
            let mut field = self.field(&name, &p.value)?;
            field.key = p.key.span();
            out.push(field);
        }
        Ok(out)
    }

    /// `z.<base>(args).<m>(args)...` → (base name and arguments, modifiers outward).
    #[allow(clippy::type_complexity)]
    fn chain<'b, 'a>(
        &self,
        e: &'b a::Expression<'a>,
    ) -> Result<(
        (String, &'b [a::Argument<'a>], oxc_span::Span),
        Vec<(String, &'b [a::Argument<'a>], oxc_span::Span)>,
    )> {
        let mut modifiers = Vec::new();
        let mut current = e.get_inner_expression();
        loop {
            let a::Expression::CallExpression(call) = current else {
                return Err(Diag::new(
                    self.at(current.span()),
                    "this field type can't be read as a SpacePress field",
                )
                .help("write fields with `z.` (`z.string()`, `z.number()`, ...)")
                .code(Code::Schema));
            };
            let m = match call.callee.get_inner_expression() {
                a::Expression::StaticMemberExpression(m) => m,
                // A helper Astro passes the schema function: `image()`, `reference("authors")`.
                a::Expression::Identifier(f) => {
                    let help = match f.name.as_str() {
                        "image" => {
                            "keep the image's URL as `z.string()` and the file in the media library or public/: an `image()` path is resolved by Astro's build, which SpacePress doesn't run"
                        }
                        "reference" => {
                            "keep the referenced entry's slug as `z.string()`, or declare the relation in sp/ with ref()"
                        }
                        _ => "write the field with `z.`",
                    };
                    return Err(Diag::new(
                        self.at(call.span),
                        format!("`{}()` has no SpacePress field type", f.name),
                    )
                    .help(help)
                    .code(Code::Schema));
                }
                _ => {
                    return Err(Diag::new(
                        self.at(call.span),
                        "this field type can't be read as a SpacePress field",
                    )
                    .help("write fields with `z.` (`z.string()`, `z.number()`, ...)")
                    .code(Code::Schema));
                }
            };
            let name = m.property.name.to_string();
            match m.object.get_inner_expression() {
                a::Expression::Identifier(z) if z.name == "z" => {
                    modifiers.reverse();
                    return Ok(((name, call.arguments.as_slice(), call.span), modifiers));
                }
                // `z.coerce.date()`
                a::Expression::StaticMemberExpression(c)
                    if c.property.name == "coerce"
                        && matches!(c.object.get_inner_expression(), a::Expression::Identifier(z) if z.name == "z") =>
                {
                    modifiers.reverse();
                    return Ok((
                        (format!("coerce.{name}"), call.arguments.as_slice(), call.span),
                        modifiers,
                    ));
                }
                inner => {
                    modifiers.push((name, call.arguments.as_slice(), call.span));
                    current = inner;
                }
            }
        }
    }

    fn field(&self, name: &str, value: &a::Expression<'_>) -> Result<Mapped> {
        let refuse = |s: oxc_span::Span, what: String, help: &str| {
            Diag::new(
                self.at(s),
                format!("field `{name}`: {what} has no SpacePress field type"),
            )
            .help(help.to_string())
            .code(Code::Schema)
        };
        let ((base, args, base_span), modifiers) = self.chain(value).map_err(|mut d| {
            d.message = format!("field `{name}`: {}", d.message);
            d
        })?;
        let mut optional = false;
        let mut default: Option<String> = None;
        let mut extra = String::new();
        let mut enum_values: Option<Vec<String>> = None;
        let (mut builder, text) = match base.as_str() {
            "string" => ("__text()".to_string(), true),
            "number" | "coerce.number" => ("__number()".to_string(), false),
            "boolean" | "coerce.boolean" => ("__boolean()".to_string(), false),
            // Astro's own example: a date written `2022-07-08` or `'Jul 08 2022'` in front matter.
            // SpacePress stores a calendar date and reads `YYYY-MM-DD`; a sync with another form is refused
            // at the file, by the field's own check.
            "date" | "coerce.date" => ("__date()".to_string(), false),
            "enum" => {
                let values = match args
                    .first()
                    .and_then(|e| e.as_expression())
                    .map(|e| e.get_inner_expression())
                {
                    Some(a::Expression::ArrayExpression(arr)) => arr
                        .elements
                        .iter()
                        .map(|el| match el.as_expression().map(|e| e.get_inner_expression()) {
                            Some(a::Expression::StringLiteral(s)) => Ok(utf8(self.file, s)?.to_string()),
                            _ => Err(refuse(
                                el.span(),
                                "an enum value that isn't a string".into(),
                                "list the values as strings",
                            )),
                        })
                        .collect::<Result<Vec<_>>>()?,
                    _ => {
                        return Err(refuse(
                            base_span,
                            "`z.enum` without a list of values".into(),
                            "write `z.enum([\"a\", \"b\"])`",
                        ));
                    }
                };
                if values.is_empty() {
                    return Err(refuse(base_span, "an empty `z.enum`".into(), "list at least one value"));
                }
                enum_values = Some(values);
                (String::new(), false)
            }
            "array" => {
                let item = args.first().and_then(|e| e.as_expression()).ok_or_else(|| {
                    refuse(
                        base_span,
                        "`z.array` without an item type".into(),
                        "write `z.array(z.string())`",
                    )
                })?;
                let ((item_base, item_args, item_span), item_mods) = self.chain(item)?;
                if !item_mods.is_empty() {
                    return Err(refuse(
                        item_span,
                        "a list item with modifiers".into(),
                        "write the item type plainly: `z.array(z.string())`",
                    ));
                }
                match item_base.as_str() {
                    "string" => ("__json<string[]>()".to_string(), false),
                    "number" => ("__json<number[]>()".to_string(), false),
                    "enum" => {
                        let Some(a::Expression::ArrayExpression(arr)) = item_args
                            .first()
                            .and_then(|e| e.as_expression())
                            .map(|e| e.get_inner_expression())
                        else {
                            return Err(refuse(
                                item_span,
                                "`z.enum` without a list of values".into(),
                                "write `z.array(z.enum([\"a\", \"b\"]))`",
                            ));
                        };
                        let mut values = Vec::new();
                        for el in &arr.elements {
                            match el.as_expression().map(|e| e.get_inner_expression()) {
                                Some(a::Expression::StringLiteral(s)) => {
                                    values.push(format!("{:?}", utf8(self.file, s)?))
                                }
                                _ => {
                                    return Err(refuse(
                                        el.span(),
                                        "an enum value that isn't a string".into(),
                                        "list the values as strings",
                                    ));
                                }
                            }
                        }
                        (format!("__select([{}]).many()", values.join(", ")), false)
                    }
                    other => {
                        return Err(refuse(
                            item_span,
                            format!("a list of `z.{other}()`"),
                            "a list holds strings, numbers or enum values; keep other lists in sp/ as a repeater()",
                        ));
                    }
                }
            }
            other => {
                let help = match other {
                    "object" => {
                        "a nested object has no plain field: declare the kind in sp/ with group(), or flatten it"
                    }
                    "union" | "discriminatedUnion" | "intersection" => {
                        "a field holds one type: pick it, or declare the kind in sp/"
                    }
                    "record" | "map" | "unknown" | "any" => {
                        "a field has a known type; use json() in a kind declared in sp/"
                    }
                    "bigint" => "use z.number()",
                    "literal" => "use z.enum([...]) with the one value",
                    _ => {
                        "SpacePress maps z.string(), z.number(), z.boolean(), z.date(), z.enum() and z.array() of strings, numbers or enum values"
                    }
                };
                return Err(refuse(base_span, format!("`z.{other}()`"), help));
            }
        };
        let mut is_url = false;
        let mut is_email = false;
        for (m, margs, mspan) in &modifiers {
            let number = || -> Result<f64> {
                match margs
                    .first()
                    .and_then(|e| e.as_expression())
                    .map(|e| e.get_inner_expression())
                {
                    Some(a::Expression::NumericLiteral(n)) => Ok(n.value),
                    _ => Err(refuse(
                        *mspan,
                        format!("`.{m}()` with a value that isn't a number"),
                        "write a number literal",
                    )),
                }
            };
            match m.as_str() {
                "optional" | "nullable" | "nullish" => optional = true,
                "default" => {
                    let Some(v) = margs.first().and_then(|e| e.as_expression()) else {
                        return Err(refuse(
                            *mspan,
                            "`.default()` without a value".into(),
                            "write `.default(value)`",
                        ));
                    };
                    let s = v.span();
                    let text = &self.source[s.start as usize..s.end as usize];
                    let fits = match v.get_inner_expression() {
                        a::Expression::StringLiteral(_) => base == "string" || base == "enum",
                        a::Expression::NumericLiteral(_) | a::Expression::UnaryExpression(_) => {
                            base.ends_with("number")
                        }
                        a::Expression::BooleanLiteral(_) => base.ends_with("boolean"),
                        a::Expression::ArrayExpression(_) => base == "array",
                        _ => true,
                    };
                    if !fits {
                        return Err(Diag::new(
                            self.at(s),
                            format!("field `{name}`: its default isn't a `z.{base}()` value"),
                        )
                        .help("give the default the field's own type")
                        .code(Code::Schema));
                    }
                    match v.get_inner_expression() {
                        a::Expression::StringLiteral(_)
                        | a::Expression::NumericLiteral(_)
                        | a::Expression::BooleanLiteral(_) => default = Some(text.to_string()),
                        a::Expression::ArrayExpression(arr) if arr.elements.is_empty() => default = Some("[]".into()),
                        a::Expression::UnaryExpression(u)
                            if u.operator == oxc_syntax::operator::UnaryOperator::UnaryNegation
                                && matches!(u.argument, a::Expression::NumericLiteral(_)) =>
                        {
                            default = Some(text.to_string())
                        }
                        _ => {
                            return Err(refuse(
                                s,
                                "a default that isn't a plain value".into(),
                                "write a string, number, boolean or `[]`",
                            ));
                        }
                    }
                }
                "url" if base == "string" => is_url = true,
                "email" if base == "string" => is_email = true,
                "int" if base.ends_with("number") => builder = "__integer()".into(),
                "min" | "max" if base.ends_with("number") || base == "string" => {
                    let _ = write!(extra, ".{m}({})", number()?);
                }
                // Above zero is at least one only for whole numbers.
                "positive" if base.ends_with("number") && builder == "__integer()" => extra.push_str(".min(1)"),
                "nonnegative" if base.ends_with("number") => extra.push_str(".min(0)"),
                "describe" => {}
                other => {
                    return Err(refuse(
                        *mspan,
                        format!("`.{other}()` on `z.{base}()`"),
                        "SpacePress maps .optional(), .default(), .min(), .max(), .int(), .url() and .email(); transforms, refinements and other modifiers aren't read",
                    ));
                }
            }
        }
        if is_url {
            builder = "__url()".into();
        }
        if is_email {
            builder = "__email()".into();
        }
        if let Some(values) = enum_values {
            builder = format!(
                "__select([{}])",
                values.iter().map(|v| format!("{v:?}")).collect::<Vec<_>>().join(", ")
            );
        }
        let mut out = format!("{builder}{extra}");
        match (&default, optional) {
            (Some(d), _) => {
                let _ = write!(out, ".default({d})");
            }
            (None, true) => out.push_str(".optional()"),
            (None, false) => {}
        }
        Ok(Mapped {
            name: name.to_string(),
            builder: out,
            text: text && !is_url && !is_email,
            required: !optional && default.is_none(),
            span: oxc_span::Span::new(value.span().start, value.span().end),
            key: oxc_span::Span::new(value.span().start, value.span().end),
        })
    }
}

struct Mapped {
    name: String,
    builder: String,
    /// A plain string: it can be the kind's title.
    text: bool,
    /// Neither optional nor defaulted: a title must be.
    required: bool,
    /// The field's name in the schema.
    key: oxc_span::Span,
    span: oxc_span::Span,
}
