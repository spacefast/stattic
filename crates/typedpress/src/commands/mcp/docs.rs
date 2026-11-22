//! TypedPress's own documentation, embedded at build time (`build.rs`) from `www/content/docs/`.
//! Searching and reading it here answers from the same revision as the binary, which a web search
//! cannot promise.

use crate::docs_files::DOCS;
use serde_json::{Value, json};

/// The scheme of a page's resource URI: `typedpress-docs:///www/content/docs/operations/mcp-and-agents.md`.
const SCHEME: &str = "typedpress-docs:///";
/// A page longer than this is answered as its outline; a section is read by name.
const WHOLE_PAGE: usize = 24_000;
const SNIPPET: usize = 320;

pub(super) fn uri(path: &str) -> String {
    format!("{SCHEME}{path}")
}

/// The page a resource URI or a path names.
pub(super) fn find(name: &str) -> Option<(&'static str, &'static str)> {
    let path = name.strip_prefix(SCHEME).unwrap_or(name).trim_start_matches("./");
    DOCS.iter().copied().find(|(doc, _)| *doc == path)
}

/// A page without its front matter, and the front matter's `title` and `description`.
fn parts(text: &'static str) -> (&'static str, Option<&'static str>, Option<&'static str>) {
    let Some(rest) = text.strip_prefix("---\n") else {
        return (text, None, None);
    };
    let Some(end) = rest.find("\n---\n") else {
        return (text, None, None);
    };
    let value = |key: &str| {
        rest[..end]
            .lines()
            .find_map(|line| line.strip_prefix(key)?.strip_prefix(':'))
            .map(|v| v.trim().trim_matches('"'))
            .filter(|v| !v.is_empty())
    };
    (rest[end + 5..].trim_start(), value("title"), value("description"))
}

/// A heading line's level and text. Lines inside a code fence are never headings; the caller tracks that.
fn heading(line: &str) -> Option<(usize, &str)> {
    let level = line.bytes().take_while(|b| *b == b'#').count();
    (1..=6)
        .contains(&level)
        .then(|| line[level..].strip_prefix(' '))
        .flatten()
        .map(|text| (level, text.trim()))
}

struct Section<'a> {
    level: usize,
    heading: &'a str,
    /// From the heading line to the next heading of any level.
    body: &'a str,
    /// Byte offset of the heading line in the page body.
    start: usize,
}

/// The page split at its headings. The text before the first heading is a section with no heading.
fn sections(body: &str) -> Vec<Section<'_>> {
    let mut out: Vec<Section> = Vec::new();
    let (mut at, mut fenced) = (0, false);
    let mut open = Section {
        level: 0,
        heading: "",
        body: "",
        start: 0,
    };
    for line in body.split_inclusive('\n') {
        if line.trim_start().starts_with("```") {
            fenced = !fenced;
        } else if !fenced && let Some((level, text)) = heading(line.trim_end()) {
            open.body = &body[open.start..at];
            out.push(open);
            open = Section {
                level,
                heading: text,
                body: "",
                start: at,
            };
        }
        at += line.len();
    }
    open.body = &body[open.start..];
    out.push(open);
    out.retain(|section| !section.body.trim().is_empty());
    out
}

fn title(path: &str, body: &str, front: Option<&str>) -> String {
    front
        .or_else(|| {
            sections(body)
                .iter()
                .find(|section| section.level == 1)
                .map(|section| section.heading)
        })
        .unwrap_or(path)
        .to_string()
}

/// Every page, for `resources/list`.
pub(super) fn pages() -> Vec<Value> {
    DOCS.iter()
        .map(|(path, text)| {
            let (body, front_title, description) = parts(text);
            let mut page = json!({
                "uri": uri(path),
                "name": path,
                "title": title(path, body, front_title),
                "mimeType": "text/markdown",
                "size": text.len(),
            });
            if let Some(description) = description {
                page["description"] = json!(description);
            }
            page
        })
        .collect()
}

fn words(text: &str) -> Vec<String> {
    text.split(|c: char| !(c.is_alphanumeric() || c == '_'))
        .filter(|word| word.chars().count() >= 2)
        .map(str::to_lowercase)
        .collect()
}

/// `text` cut to about [`SNIPPET`] bytes around its first match of any term, on character boundaries.
fn snippet(text: &str, terms: &[String]) -> String {
    let lower = text.to_lowercase();
    let hit = terms
        .iter()
        .filter_map(|term| lower.find(term.as_str()))
        .min()
        .unwrap_or(0);
    // Lowercasing can change byte lengths; fall back to the start when the offset is not usable.
    let hit = if lower.len() == text.len() { hit } else { 0 };
    let mut start = hit.saturating_sub(SNIPPET / 4);
    while !text.is_char_boundary(start) {
        start -= 1;
    }
    let mut end = (start + SNIPPET).min(text.len());
    while !text.is_char_boundary(end) {
        end += 1;
    }
    let cut = text[start..end].split_whitespace().collect::<Vec<_>>().join(" ");
    format!(
        "{}{cut}{}",
        if start > 0 { "…" } else { "" },
        if end < text.len() { "…" } else { "" }
    )
}

/// Sections ranked for `query` by which of its words they hold and where.
pub(super) fn search(query: &str, limit: usize) -> Vec<Value> {
    let terms = {
        let mut terms = words(query);
        let mut seen = std::collections::HashSet::new();
        terms.retain(|term| seen.insert(term.clone()));
        terms
    };
    if terms.is_empty() {
        return Vec::new();
    }
    let mut hits: Vec<(f64, Value)> = Vec::new();
    for (path, text) in DOCS {
        let (body, front_title, _) = parts(text);
        let page_title = title(path, body, front_title);
        let page_words = words(&format!("{path} {page_title}"));
        for section in sections(body) {
            let heading_words = words(section.heading);
            // Whole words, so `access` does not match `accessors`; a plural or a possessive still
            // matches (`rules` for `rule`, `site's` for `site`).
            let text_words = words(section.body);
            let same = |word: &String, term: &String| {
                word == term
                    || word.strip_suffix('s').is_some_and(|stem| stem == term)
                    || term.strip_suffix('s').is_some_and(|stem| stem == word)
            };
            // Each word of the query found counts, a heading most, the page's title next; mentions
            // in the text count less with each repeat, so a long section does not win by length.
            let mut score = 0.0;
            for term in &terms {
                let in_heading = heading_words.iter().filter(|word| same(word, term)).count();
                let in_page = page_words.iter().filter(|word| same(word, term)).count();
                let in_text = text_words.iter().filter(|word| same(word, term)).count();
                if in_heading + in_text > 0 {
                    score += 6.0 + 10.0 * in_heading as f64 + 3.0 * in_page as f64 + 4.0 * (in_text as f64).ln_1p();
                }
            }
            if score > 0.0 {
                hits.push((
                    score,
                    json!({
                        "path": path,
                        "title": page_title,
                        "section": section.heading,
                        // The heading is already in `section`; the snippet is the text under it.
                        "snippet": snippet(section.body.split_once('\n').map_or("", |(_, text)| text), &terms),
                    }),
                ));
            }
        }
    }
    // Stable: ties keep page order.
    hits.sort_by(|a, b| b.0.total_cmp(&a.0));
    hits.into_iter().take(limit).map(|(_, hit)| hit).collect()
}

/// One page with its outline, or one section of it with its subsections. A long page read whole
/// answers with its opening only, so a reader picks a section instead of receiving all of it.
pub(super) fn read(name: &str, section: Option<&str>) -> Result<Value, String> {
    let (path, text) = find(name).ok_or_else(|| {
        format!("no documentation page {name}; docs_search finds pages, and resources/list names every one")
    })?;
    let (body, front_title, _) = parts(text);
    let all = sections(body);
    let outline: Vec<&str> = all
        .iter()
        .filter(|s| (1..=3).contains(&s.level))
        .map(|s| s.heading)
        .collect();
    let mut out = json!({ "path": path, "title": title(path, body, front_title), "sections": outline });
    if let Some(wanted) = section {
        let at = all
            .iter()
            .position(|s| {
                s.level > 0
                    && s.heading
                        .eq_ignore_ascii_case(wanted.trim().trim_start_matches('#').trim())
            })
            .ok_or_else(|| {
                format!(
                    "{path} has no section {wanted:?}; its sections are: {}",
                    outline.join(" | ")
                )
            })?;
        let end = all[at + 1..]
            .iter()
            .find(|s| s.level <= all[at].level)
            .map_or(body.len(), |s| s.start);
        out["section"] = json!(all[at].heading);
        let text = body[all[at].start..end].trim_end();
        if text.len() <= WHOLE_PAGE {
            out["text"] = json!(text);
        } else {
            // A section as long as a page (the page's own title, or one long list): its opening,
            // and the subsections to read next.
            let mut cut = all[at + 1..]
                .iter()
                .find(|s| s.start < all[at].start + text.len())
                .map_or(WHOLE_PAGE, |s| s.start - all[at].start)
                .min(WHOLE_PAGE);
            while !text.is_char_boundary(cut) {
                cut -= 1;
            }
            out["text"] = json!(text[..cut].trim_end());
            out["truncated"] = json!(true);
            out["sections"] = json!(
                all[at + 1..]
                    .iter()
                    .take_while(|s| s.start < all[at].start + text.len())
                    .map(|s| s.heading)
                    .collect::<Vec<_>>()
            );
            out["next"] = json!("read one of `sections` by passing its name as `section`");
        }
    } else if body.len() <= WHOLE_PAGE {
        out["text"] = json!(body);
    } else {
        // The opening: the page up to its first subsection (the second heading when the page
        // starts with its title, the first otherwise).
        let opening = all
            .iter()
            .filter(|s| s.level > 0)
            .nth(usize::from(all.first().is_some_and(|s| s.level == 1)))
            .map_or(body.len(), |s| s.start)
            .min(WHOLE_PAGE);
        let mut end = opening;
        while !body.is_char_boundary(end) {
            end -= 1;
        }
        out["text"] = json!(body[..end].trim_end());
        out["truncated"] = json!(true);
        out["next"] = json!("read one section by passing its name as `section`");
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::{heading, sections};

    #[test]
    fn a_hash_line_inside_a_code_fence_is_not_a_heading() {
        let page = "# Title\n\nIntro.\n\n```sh\n# a shell comment\ntypedpress check\n```\n\n## Next\n\nMore.\n";
        let found: Vec<&str> = sections(page).iter().map(|s| s.heading).collect();
        assert_eq!(found, ["Title", "Next"]);
        assert!(sections(page)[0].body.contains("# a shell comment"));
    }

    #[test]
    fn a_heading_needs_a_space_after_its_hashes() {
        assert_eq!(heading("## Two words"), Some((2, "Two words")));
        assert_eq!(heading("#hashtag"), None);
        assert_eq!(heading("####### seven"), None);
    }
}
