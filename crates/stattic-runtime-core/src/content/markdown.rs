//! Markdown pages: pulldown-cmark rendering decorated with the Gutenberg
//! block classes the static theme layer styles.

use pulldown_cmark::{
    html, CodeBlockKind, CowStr, Event, HeadingLevel, Options, Parser, Tag, TagEnd,
};
use serde_json::{Map, Value};

use super::frontmatter::split_frontmatter;
use super::support::{escape_attr, first_heading, string_in, string_in_opt, title_from_path};
use super::Page;
use crate::finalize::civil_from_days;

pub(super) fn markdown_page(
    path: &str,
    source: &str,
    site_meta: Option<&Map<String, Value>>,
    diagnostics: &mut Vec<Value>,
) -> std::result::Result<Page, String> {
    let (frontmatter, body) = split_frontmatter(source, path, diagnostics);
    let mut rendered = String::new();
    let parser = Parser::new_ext(body, Options::all());
    html::push_html(&mut rendered, decorate_markdown_events(parser).into_iter());
    let title = string_in(&frontmatter, "title")
        .or_else(|| first_heading(&rendered))
        .unwrap_or_else(|| title_from_path(path));
    let description =
        string_in(&frontmatter, "description").or_else(|| string_in_opt(site_meta, "description"));
    let image = string_in(&frontmatter, "image").or_else(|| string_in_opt(site_meta, "image"));
    let date = frontmatter.get("date").and_then(frontmatter_date);
    let layout = string_in(&frontmatter, "layout");
    let draft = frontmatter.get("draft").is_some_and(|v| {
        v.as_bool().unwrap_or(false)
            || v.as_str()
                .is_some_and(|s| matches!(s.to_ascii_lowercase().as_str(), "true" | "yes" | "1"))
    });
    let output_path = markdown_output_path(path);
    Ok(Page {
        source_path: path.into(),
        output_path,
        content: rendered,
        title,
        description,
        image,
        date,
        layout,
        draft,
        layout_rendered: false,
    })
}

pub(super) fn markdown_output_path(path: &str) -> String {
    let lower = path.to_ascii_lowercase();
    let stem = if lower.ends_with(".markdown") {
        &path[..path.len() - 9]
    } else {
        &path[..path.len() - 3]
    };
    if stem
        .rsplit('/')
        .next()
        .is_some_and(|v| v.eq_ignore_ascii_case("index"))
    {
        format!("{}.html", stem.strip_suffix("index").unwrap_or(stem))
    } else {
        format!("{stem}/index.html")
    }
}

fn decorate_markdown_events<'a>(events: impl Iterator<Item = Event<'a>>) -> Vec<Event<'a>> {
    let events: Vec<_> = events.collect();
    let mut decorated = Vec::new();
    let mut index = 0;
    while index < events.len() {
        if matches!(events[index], Event::Start(Tag::Paragraph)) {
            if let Some(end_offset) = events[index + 1..]
                .iter()
                .position(|event| matches!(event, Event::End(TagEnd::Paragraph)))
            {
                let end = index + 1 + end_offset;
                let inner = &events[index + 1..end];
                if matches!(inner.first(), Some(Event::Start(Tag::Image { .. })))
                    && matches!(inner.last(), Some(Event::End(TagEnd::Image)))
                {
                    decorated.push(Event::Html(CowStr::Borrowed(
                        "<figure class=\"wp-block-image\">",
                    )));
                    decorated.extend(inner.iter().cloned());
                    decorated.push(Event::Html(CowStr::Borrowed("</figure>\n")));
                    index = end + 1;
                    continue;
                }
            }
        }
        let event = events[index].clone();
        match event {
            Event::Start(Tag::Heading {
                level,
                id,
                classes,
                attrs,
            }) => {
                let level = heading_level_number(level);
                let generated_id = heading_text(&events[index + 1..])
                    .map(|text| heading_id(&text))
                    .filter(|value| !value.is_empty());
                let id = id.as_deref().map(str::to_string).or(generated_id);
                let mut opening = format!("<h{level} class=\"wp-block-heading");
                for class in classes {
                    opening.push(' ');
                    opening.push_str(&escape_attr(&class));
                }
                opening.push('"');
                if let Some(id) = id {
                    opening.push_str(" id=\"");
                    opening.push_str(&escape_attr(&id));
                    opening.push('"');
                }
                for (name, value) in attrs {
                    opening.push(' ');
                    opening.push_str(&escape_attr(&name));
                    if let Some(value) = value {
                        opening.push_str("=\"");
                        opening.push_str(&escape_attr(&value));
                        opening.push('"');
                    }
                }
                opening.push('>');
                decorated.push(Event::Html(CowStr::Boxed(opening.into_boxed_str())));
            }
            Event::End(TagEnd::Heading(level)) => {
                let level = heading_level_number(level);
                decorated.push(Event::Html(CowStr::Boxed(
                    format!("</h{level}>\n").into_boxed_str(),
                )));
            }
            Event::Start(Tag::Strong) => {
                decorated.push(Event::Html(CowStr::Borrowed("<b>")));
            }
            Event::End(TagEnd::Strong) => {
                decorated.push(Event::Html(CowStr::Borrowed("</b>")));
            }
            Event::Start(Tag::Table(alignments)) => {
                decorated.push(Event::Html(CowStr::Borrowed(
                    "<figure class=\"wp-block-table\">",
                )));
                decorated.push(Event::Start(Tag::Table(alignments)));
            }
            Event::End(TagEnd::Table) => {
                decorated.push(Event::End(TagEnd::Table));
                decorated.push(Event::Html(CowStr::Borrowed("</figure>")));
            }
            Event::Start(Tag::BlockQuote(_)) => decorated.push(Event::Html(CowStr::Borrowed(
                "<blockquote class=\"wp-block-quote\">\n",
            ))),
            Event::End(TagEnd::BlockQuote(_)) => {
                decorated.push(Event::Html(CowStr::Borrowed("</blockquote>\n")))
            }
            Event::Start(Tag::CodeBlock(kind)) => {
                let opening = match kind {
                    CodeBlockKind::Indented => "<pre class=\"wp-block-code\"><code>".into(),
                    CodeBlockKind::Fenced(language) => format!(
                        "<pre class=\"wp-block-code\"><code class=\"language-{}\">",
                        escape_attr(language.trim())
                    ),
                };
                decorated.push(Event::Html(CowStr::Boxed(opening.into_boxed_str())));
            }
            Event::End(TagEnd::CodeBlock) => {
                decorated.push(Event::Html(CowStr::Borrowed("</code></pre>\n")))
            }
            Event::Start(Tag::List(start)) => {
                let opening = match start {
                    Some(1) => "<ol class=\"wp-block-list\">\n".into(),
                    Some(start) => {
                        format!("<ol class=\"wp-block-list\" start=\"{start}\">\n")
                    }
                    None => "<ul class=\"wp-block-list\">\n".into(),
                };
                decorated.push(Event::Html(CowStr::Boxed(opening.into_boxed_str())));
            }
            Event::End(TagEnd::List(ordered)) => {
                decorated.push(Event::Html(CowStr::Borrowed(if ordered {
                    "</ol>\n"
                } else {
                    "</ul>\n"
                })))
            }
            other => decorated.push(other),
        }
        index += 1;
    }
    decorated
}

fn heading_level_number(level: HeadingLevel) -> u8 {
    match level {
        HeadingLevel::H1 => 1,
        HeadingLevel::H2 => 2,
        HeadingLevel::H3 => 3,
        HeadingLevel::H4 => 4,
        HeadingLevel::H5 => 5,
        HeadingLevel::H6 => 6,
    }
}

fn heading_text(events: &[Event<'_>]) -> Option<String> {
    let mut text = String::new();
    for event in events {
        match event {
            Event::End(TagEnd::Heading(_)) => break,
            Event::Text(value) | Event::Code(value) => text.push_str(value),
            Event::SoftBreak | Event::HardBreak => text.push(' '),
            _ => {}
        }
    }
    let text = text.trim();
    (!text.is_empty()).then(|| text.to_string())
}

fn heading_id(text: &str) -> String {
    let mut output = String::with_capacity(text.len());
    let mut separator = false;
    for character in text.chars().flat_map(char::to_lowercase) {
        if character.is_alphanumeric() {
            if separator && !output.is_empty() {
                output.push('-');
            }
            separator = false;
            output.push(character);
        } else {
            separator = true;
        }
    }
    output
}

fn frontmatter_date(value: &Value) -> Option<String> {
    if let Some(value) = value
        .as_str()
        .map(str::trim)
        .filter(|value| !value.is_empty())
    {
        return Some(value.to_string());
    }
    let timestamp = value.as_i64()?;
    let days = timestamp.div_euclid(86_400);
    let (year, month, day) = civil_from_days(days);
    Some(format!("{year:04}-{month:02}-{day:02}"))
}
