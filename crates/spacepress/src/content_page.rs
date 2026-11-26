//! `page.md` under `sp/app/`: a content-owned page (docs/spec/rendering.md §2,
//! docs/decisions/2026-11-13-content-pages.md).
//!
//! The file is the first version of a real WordPress Page. The build reads its front matter and
//! body; the site stores the body as block markup in a Page record the first time it runs this
//! build (`SpacePress\Wp\ContentPages`), and from then on an editor can change the Page in wp-admin.
//! The router serves the route like any other page: this module gives it two generated functions,
//! a component that renders the stored Page and a `metadata` function that reads its title and
//! excerpt, so layouts, guards, the cache policy and the Markdown twin work unchanged.
//!
//! Front matter (all optional when the body opens with a `# Heading`):
//! - `title`: the Page title; without it the body's first `# Heading` is the title;
//! - `description`: the Page excerpt, and the page's meta description;
//! - `status`: `published` (the default) or `draft`;
//! - `canonical` and `robots: { index, follow }`: as in a page's `metadata`.
//!
//! Any other key is a build error at its line, so a typo never silently does nothing.

use crate::app::{RouteKind, Segment};
use crate::compile::{php_json, php_str};
use crate::diag::{Diag, Span};
use crate::schema::AppFile;
use crate::{extract, loader};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

/// The route file name of a content-owned page.
pub const FILE: &str = "page.md";

const KEYS: &[&str] = &["title", "description", "status", "canonical", "robots", "id"];

/// One `page.md`, as the build read it.
#[derive(Debug, Clone)]
pub struct Page {
    /// The route file, relative to `sp/` (`app/about/page.md`).
    pub file: String,
    /// The URL path the route serves (`/about`).
    pub path: String,
    /// The Page's slug: the route's last segment, or `home` for the root.
    pub slug: String,
    pub title: String,
    pub description: Option<String>,
    pub draft: bool,
    /// A layout above the page has a `guard`: the Page is stored private, so WordPress's own
    /// public surfaces (REST, sitemaps, feeds) never show what the route protects.
    pub guarded: bool,
    /// `canonical` and `robots`, which stay with the code.
    pub fixed: serde_json::Map<String, Value>,
    /// The Markdown body, converted to blocks when the site stores the Page.
    pub body: String,
    /// SHA-256 of everything the site stores for the page (title, description, status, body): a
    /// changed digest is a new version to store.
    pub digest: String,
    /// The generated component and `metadata` functions.
    pub component: String,
    pub metadata: String,
}

impl Page {
    /// The WordPress status the site stores: a draft stays one, a page under a guard is private.
    pub fn status(&self) -> &'static str {
        match (self.draft, self.guarded) {
            (true, _) => "draft",
            (false, true) => "private",
            (false, false) => "publish",
        }
    }
}

fn stem(file: &str) -> String {
    let mut out = String::new();
    for c in file.chars() {
        if c.is_ascii_alphanumeric() {
            out.push(c.to_ascii_lowercase());
        } else if !out.ends_with('_') {
            out.push('_');
        }
    }
    out.trim_matches('_').to_string()
}

/// The byte offset of 1-based `line` in `text`.
fn offset(text: &str, line: usize) -> u32 {
    text.split_inclusive('\n')
        .take(line.saturating_sub(1))
        .map(str::len)
        .sum::<usize>() as u32
}

/// The line a top-level front matter key is on, for diagnostics.
fn key_line(text: &str, key: &str) -> usize {
    text.lines()
        .position(|l| {
            l.strip_prefix(key)
                .is_some_and(|rest| rest.trim_start().starts_with(':'))
        })
        .map_or(1, |i| i + 1)
}

/// A body that opens with `# Heading`: the heading's text, and the body after it.
fn leading_heading(body: &str) -> Option<(String, String)> {
    let trimmed = body.trim_start_matches('\n');
    let (first, rest) = trimmed.split_once('\n').unwrap_or((trimmed, ""));
    let text = first.strip_prefix("# ")?.trim();
    // A closing `#` sequence is one after a space (CommonMark): `# Learn C#` keeps its `#`.
    let text = match text.trim_end_matches('#') {
        rest if rest.len() < text.len() && (rest.is_empty() || rest.ends_with(' ')) => rest.trim(),
        _ => text,
    };
    (!text.is_empty()).then(|| (text.to_string(), rest.trim_start_matches('\n').to_string()))
}

/// Reads every `page.md` route into the app's route table: its entry in `content`, and the file
/// entry the router reads (`component`, `metadata`).
pub fn register(program: &loader::Program, ext: &mut extract::Extracted) -> Result<(), Diag> {
    let first = program.layout.root_path(&program.root);
    let mut pages = Vec::new();
    // One id space: the page.tsx `route` exports already in the table, then each page.md's `id:`.
    let mut ids: Vec<(String, String, Span)> = ext
        .schema
        .app
        .iter()
        .flat_map(|a| &a.routes)
        .filter_map(|r| Some((r.id.clone(), r.page.clone()?, r.explicit_id?)))
        .collect();
    for route in &program.app.routes {
        if route.kind != RouteKind::Page || !route.file.ends_with(FILE) {
            continue;
        }
        let path = first.join(&route.file);
        let text = program
            .fs
            .read_to_string(&path)
            .map_err(|e| Diag::new(Span::NONE, format!("can't read {}: {e}", route.file)))?
            .replace("\r\n", "\n");
        let file = crate::diag::register_file(&path, &text);
        let at = |line: usize| {
            let start = offset(&text, line);
            Span {
                file,
                start,
                end: start,
            }
        };
        // One Page has one URL: a dynamic segment would give it many.
        if let Some(dynamic) = route.segments.iter().find(|s| !matches!(s, Segment::Literal(_))) {
            let name = match dynamic {
                Segment::Param(n) => format!("[{n}]"),
                Segment::CatchAll(n) => format!("[...{n}]"),
                Segment::OptionalCatchAll(n) => format!("[[...{n}]]"),
                Segment::Literal(l) => l.clone(),
            };
            return Err(
                Diag::new(at(1), format!("{} is below `{name}`: a page.md is one page with one URL", route.file)).help(
                    "write page.tsx for a dynamic route; for many Markdown pages, sync a folder into a kind and render it from page.tsx",
                ),
            );
        }
        let (front, body) = if text.starts_with("---\n") {
            let doc = crate::sync::front_matter::parse_document(&text).map_err(|e| Diag::new(at(e.line), e.message))?;
            (doc.front, doc.body)
        } else {
            (serde_json::Map::new(), text.clone())
        };
        for key in front.keys() {
            if !KEYS.contains(&key.as_str()) {
                let hint = crate::diag::suggest(key, KEYS.iter().copied())
                    .map(|s| format!("did you mean `{s}`? "))
                    .unwrap_or_default();
                return Err(Diag::new(
                    at(key_line(&text, key)),
                    format!("page.md has no front matter key `{key}`"),
                )
                .help(format!("{hint}a page.md takes {}", KEYS.join(", "))));
            }
        }
        let text_key = |key: &str| -> Result<Option<String>, Diag> {
            match front.get(key) {
                None | Some(Value::Null) => Ok(None),
                Some(Value::String(s)) if !s.trim().is_empty() => Ok(Some(s.trim().to_string())),
                Some(_) => Err(Diag::new(at(key_line(&text, key)), format!("`{key}` must be text"))
                    .help(format!("write `{key}: Some words`"))),
            }
        };
        let (title, body) = match (text_key("title")?, leading_heading(&body)) {
            // The same heading in both places is shown once, as the Page title.
            (Some(title), Some((heading, rest))) if heading == title => (title, rest),
            (Some(title), _) => (title, body),
            (None, Some((heading, rest))) => (heading, rest),
            (None, None) => {
                return Err(Diag::new(at(1), format!("{} has no title", route.file))
                    .help("add front matter (`---`, `title: About`, `---`) or open the page with `# About`"));
            }
        };
        let draft = match front.get("status") {
            None | Some(Value::Null) => false,
            Some(Value::String(s)) if s == "published" => false,
            Some(Value::String(s)) if s == "draft" => true,
            Some(_) => {
                return Err(
                    Diag::new(at(key_line(&text, "status")), "`status` must be published or draft")
                        .help("write `status: draft` to keep the page from visitors; leave it out to publish"),
                );
            }
        };
        let mut fixed = serde_json::Map::new();
        if let Some(canonical) = text_key("canonical")? {
            fixed.insert("canonical".into(), canonical.into());
        }
        match front.get("robots") {
            None | Some(Value::Null) => {}
            Some(Value::Object(robots))
                if robots
                    .iter()
                    .all(|(k, v)| matches!(k.as_str(), "index" | "follow") && v.is_boolean()) =>
            {
                fixed.insert("robots".into(), Value::Object(robots.clone()));
            }
            Some(_) => {
                return Err(Diag::new(
                    at(key_line(&text, "robots")),
                    "`robots` must be { index?: boolean, follow?: boolean }",
                )
                .help("write `robots: { index: false }`"));
            }
        }
        let description = text_key("description")?;
        // `id: notes.colophon` keeps the Page when the file moves to another URL.
        let id = match text_key("id")? {
            None => None,
            Some(id)
                if id.len() <= 100
                    && id
                        .chars()
                        .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | '_')) =>
            {
                Some((id, at(key_line(&text, "id"))))
            }
            Some(_) => {
                return Err(
                    Diag::new(at(key_line(&text, "id")), "`id` is letters, digits, `.`, `-` and `_`")
                        .help("write `id: about.colophon`"),
                );
            }
        };
        let slug = match route.segments.last() {
            Some(Segment::Literal(l)) => l.clone(),
            _ => "home".into(),
        };
        // `a-b/` and `a_b/` spell one stem; the path's hash keeps their functions apart.
        let stem = format!(
            "{}_{}",
            stem(&route.file),
            crate::hex(&Sha256::digest(route.file.as_bytes())[..4])
        );
        // Keyed by URL, or by its `id`: the Page stands for the address, so moving the file between
        // route groups (`(site)/guide` → `(docs)/guide`) keeps its Page, and an `id` keeps it when
        // the address changes too.
        let key = match &id {
            Some((id, span)) => {
                if let Some(other) = ids.iter().find(|(i, _, _)| i == id) {
                    return Err(Diag::new(*span, format!("id `{id}` is already {}'s", other.1))
                        .help("give each page its own id; an id keeps the page's WordPress Page when the file moves"));
                }
                ids.push((id.clone(), route.file.clone(), *span));
                format!("id:{id}")
            }
            None => route.pattern(),
        };
        pages.push((
            key,
            route.layouts.clone(),
            Page {
                file: route.file.clone(),
                path: route.pattern(),
                slug,
                title,
                description,
                draft,
                guarded: false,
                fixed,
                body,
                digest: String::new(),
                component: format!("{stem}__default"),
                metadata: format!("{stem}__metadata"),
            },
        ));
    }
    let Some(app) = ext.schema.app.as_mut() else {
        return Ok(());
    };
    for (id, layouts, mut page) in pages {
        page.guarded = layouts
            .iter()
            .any(|l| app.files.get(l).is_some_and(|f| f.guard.is_some()));
        page.digest = crate::hex(Sha256::digest(
            serde_json::to_vec(&json!([page.title, page.description, page.status(), page.body]))
                .expect("JSON of strings"),
        ));
        app.files.insert(
            page.file.clone(),
            AppFile {
                component: Some(page.component.clone()),
                metadata: Some(json!({ "fn": page.metadata })),
                ..AppFile::default()
            },
        );
        app.content.insert(id, page);
    }
    Ok(())
}

/// The generated functions the router calls for each `page.md`, appended to `functions.php`.
pub fn functions_php(schema: &crate::schema::Schema) -> String {
    let mut out = String::new();
    for (route, page) in schema.app.iter().flat_map(|app| &app.content) {
        let route = php_str(route);
        out.push_str(&format!(
            "/**\n * Render the WordPress Page for this Markdown route.\n *\n * @return \\SpacePress\\Html\n */\nfunction {}(): \\SpacePress\\Html {{\n\treturn \\SpacePress\\Wp\\ContentPages::html({route});\n}}\n\n",
            page.component
        ));
        out.push_str(&format!(
            "/**\n * Read the WordPress Page metadata for this Markdown route.\n *\n * @return \\SpacePress\\JsObject\n */\nfunction {}(): \\SpacePress\\JsObject {{\n\treturn \\SpacePress\\Wp\\ContentPages::metadata({route}, {});\n}}\n\n",
            page.metadata,
            php_json(&Value::Object(page.fixed.clone()))
        ));
    }
    out
}

/// The `content` entry of `schema.php`'s `app`: what the runtime needs to store and find each Page.
pub fn schema_php(schema: &crate::schema::App) -> String {
    let mut out = String::from("        'content' => [\n");
    for (route, page) in &schema.content {
        out.push_str(&format!(
            "            {} => ['path' => {}, 'slug' => {}, 'title' => {}, 'description' => {}, 'status' => {}, 'digest' => {}],\n",
            php_str(route),
            php_str(&page.path),
            php_str(&page.slug),
            php_str(&page.title),
            page.description.as_deref().map(php_str).unwrap_or_else(|| "null".into()),
            php_str(page.status()),
            php_str(&page.digest),
        ));
    }
    out.push_str("        ],\n");
    out
}

/// `sp/app/content.php` in the app plugin: each page's Markdown body, by route. A PHP file, so
/// a web server that serves the plugin folder never hands out a draft's source.
pub fn bodies_php(schema: &crate::schema::Schema) -> Option<String> {
    let content = &schema.app.as_ref()?.content;
    if content.is_empty() {
        return None;
    }
    let mut out = String::from(
        "<?php\n\n// Generated by `spacepress build` from sp/app/**/page.md. Do not edit.\n\ndeclare(strict_types=1);\n\nreturn [\n",
    );
    for (route, page) in content {
        out.push_str(&format!("    {} => {},\n", php_str(route), php_str(&page.body)));
    }
    out.push_str("];\n");
    Some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_leading_heading_is_the_title_only_when_it_is_the_first_line() {
        assert_eq!(
            leading_heading("\n# About us #\n\nText.\n"),
            Some(("About us".into(), "Text.\n".into()))
        );
        assert_eq!(
            leading_heading("# Learn C#\n"),
            Some(("Learn C#".into(), String::new()))
        );
        assert_eq!(leading_heading("Intro.\n\n# Later\n"), None);
        assert_eq!(leading_heading("## Section\n"), None);
    }

    #[test]
    fn stems_keep_page_md_apart_from_page_tsx() {
        assert_eq!(stem("app/(site)/about/page.md"), "app_site_about_page_md");
        assert_eq!(
            stem("app/a-b/page.md"),
            stem("app/a_b/page.md"),
            "why the hash is added"
        );
    }
}
