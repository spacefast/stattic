//! The strict YAML subset sync files use for front matter (design §5.2): block maps and lists, flow
//! collections on one line, plain/quoted scalars and `|` block scalars. Anything outside it (anchors,
//! aliases, tags, merge keys, duplicate keys, folded scalars, timestamps without a UTC offset) is refused
//! with its line, so a file can't silently mean something else to another YAML reader.

use regex::Regex;
use serde_json::{Map, Number, Value};
use std::sync::LazyLock;

#[derive(Debug, PartialEq)]
pub struct Error {
    pub line: usize,
    pub message: String,
}

type Result<T> = std::result::Result<T, Error>;

fn err<T>(line: usize, message: impl Into<String>) -> Result<T> {
    Err(Error {
        line,
        message: message.into(),
    })
}

/// A Markdown sync file: the front matter as an ordered map, and the body after it.
pub struct Document {
    pub front: Map<String, Value>,
    pub body: String,
}

/// Split `---` front matter from the body and parse it. CRLF is read as LF.
pub fn parse_document(text: &str) -> Result<Document> {
    let text = text.replace("\r\n", "\n");
    let Some(rest) = text.strip_prefix("---\n") else {
        return err(1, "a sync file starts with a --- front matter line");
    };
    let mut yaml_end = None;
    let mut offset = 0;
    for line in rest.split_inclusive('\n') {
        if line.trim_end_matches('\n') == "---" {
            yaml_end = Some((offset, offset + line.len()));
            break;
        }
        offset += line.len();
    }
    let Some((end, body_start)) = yaml_end else {
        return err(1, "the front matter has no closing --- line");
    };
    let front = match parse(&rest[..end], 2)? {
        Value::Object(map) => map,
        Value::Null => Map::new(),
        _ => return err(2, "the front matter must be a map of keys"),
    };
    Ok(Document {
        front,
        body: rest[body_start..].to_string(),
    })
}

/// Parse a YAML block whose first line is line `first` of the file.
pub fn parse(yaml: &str, first: usize) -> Result<Value> {
    // `split` yields an empty element after a final newline; it is the end of the text, not a line.
    let text = yaml.strip_suffix('\n').unwrap_or(yaml);
    let mut parser = Parser {
        lines: if text.is_empty() && yaml.is_empty() {
            Vec::new()
        } else {
            text.split('\n')
                .enumerate()
                .map(|(i, l)| (first + i, l.to_string()))
                .collect()
        },
        pos: 0,
    };
    let Some(i) = parser.next_content()? else {
        return Ok(Value::Null);
    };
    let indent = parser.indent(i)?;
    if indent != 0 {
        return err(parser.lines[i].0, "the front matter must not be indented");
    }
    let value = parser.block(0)?;
    if let Some(i) = parser.next_content()? {
        return err(parser.lines[i].0, "unexpected content; check the indentation");
    }
    Ok(value)
}

struct Parser {
    lines: Vec<(usize, String)>,
    pos: usize,
}

fn is_item(text: &str) -> bool {
    text == "-" || text.starts_with("- ")
}

impl Parser {
    fn indent(&self, i: usize) -> Result<usize> {
        let (no, line) = &self.lines[i];
        let indent = line.len() - line.trim_start_matches(' ').len();
        if line[indent..].starts_with('\t') {
            return err(*no, "indent with spaces, not tabs");
        }
        Ok(indent)
    }

    /// The next line holding content (not blank, not a comment), without consuming it.
    fn next_content(&self) -> Result<Option<usize>> {
        for i in self.pos..self.lines.len() {
            let trimmed = self.lines[i].1.trim_start_matches(' ');
            if trimmed.is_empty() || trimmed.starts_with('#') {
                continue;
            }
            self.indent(i)?;
            return Ok(Some(i));
        }
        Ok(None)
    }

    fn block(&mut self, indent: usize) -> Result<Value> {
        let i = self.next_content()?.expect("caller saw content");
        if is_item(&self.lines[i].1[indent..]) {
            self.list(indent)
        } else {
            self.map(indent)
        }
    }

    fn map(&mut self, indent: usize) -> Result<Value> {
        let mut out = Map::new();
        while let Some(i) = self.next_content()? {
            let at = self.indent(i)?;
            if at < indent {
                break;
            }
            let no = self.lines[i].0;
            if at > indent {
                return err(no, "unexpected indentation");
            }
            let text = self.lines[i].1[at..].to_string();
            if is_item(&text) {
                break;
            }
            let Some((key, rest)) = split_key(&text, no)? else {
                return err(no, "expected `key: value`");
            };
            if key == "<<" {
                return err(no, "merge keys (<<) are not supported; write the keys out");
            }
            if out.contains_key(&key) {
                return err(no, format!("duplicate key `{key}`"));
            }
            self.pos = i + 1;
            let value = self.value_after(rest.trim_start(), indent, no, true)?;
            out.insert(key, value);
        }
        Ok(Value::Object(out))
    }

    fn list(&mut self, indent: usize) -> Result<Value> {
        let mut out = Vec::new();
        while let Some(i) = self.next_content()? {
            let at = self.indent(i)?;
            let no = self.lines[i].0;
            if at < indent || (at == indent && !is_item(&self.lines[i].1[at..])) {
                break;
            }
            if at > indent {
                return err(no, "unexpected indentation");
            }
            let after_dash = &self.lines[i].1[at + 1..];
            let content = at + 1 + (after_dash.len() - after_dash.trim_start_matches(' ').len());
            let rest = self.lines[i].1[content..].to_string();
            if !rest.is_empty() && !rest.starts_with('#') && split_key(&rest, no)?.is_some() {
                // `- key: value` opens a map whose keys line up with `key`.
                self.lines[i].1 = format!("{}{rest}", " ".repeat(content));
                self.pos = i;
                out.push(self.map(content)?);
                continue;
            }
            self.pos = i + 1;
            out.push(self.value_after(&rest, indent, no, false)?);
        }
        Ok(Value::Array(out))
    }

    /// The value after `key:` or `-`: inline, a `|` block scalar, or a nested block below it.
    fn value_after(&mut self, rest: &str, indent: usize, no: usize, in_map: bool) -> Result<Value> {
        if rest.is_empty() || rest.starts_with('#') {
            return match self.next_content()? {
                Some(j) if self.indent(j)? > indent => {
                    let inner = self.indent(j)?;
                    self.block(inner)
                }
                Some(j) if in_map && self.indent(j)? == indent && is_item(&self.lines[j].1[indent..]) => {
                    self.list(indent)
                }
                _ => Ok(Value::Null),
            };
        }
        if rest.starts_with('|') {
            return self.block_scalar(rest, indent, no);
        }
        if rest.starts_with('>') {
            return err(no, "folded scalars (>) are not supported; use |");
        }
        inline(rest, no)
    }

    fn block_scalar(&mut self, header: &str, parent: usize, no: usize) -> Result<Value> {
        let chomp = match strip_comment(&header[1..]).trim() {
            "" => ' ',
            "-" => '-',
            "+" => '+',
            _ => return err(no, "block scalars take | , |- or |+ only"),
        };
        // The block's indentation is its first non-blank line's.
        let block = self.lines[self.pos..]
            .iter()
            .map(|(_, l)| l)
            .find(|l| !l.trim_start_matches(' ').is_empty())
            .map(|l| l.len() - l.trim_start_matches(' ').len());
        let mut lines: Vec<&str> = Vec::new();
        let mut end = self.pos;
        for (j, (_, line)) in self.lines.iter().enumerate().skip(self.pos) {
            let spaces = line.len() - line.trim_start_matches(' ').len();
            if line.trim_start_matches(' ').is_empty() {
                // A blank line keeps any spaces past the block's indentation: they are content.
                lines.push(block.map_or("", |b| line.get(b..).unwrap_or("")));
                end = j + 1;
                continue;
            }
            let block = block.expect("a non-blank line exists");
            if spaces <= parent || spaces < block {
                break;
            }
            lines.push(&line[block..]);
            end = j + 1;
        }
        // Trailing blank lines belong to the block only as newlines; a whitespace-only line is content.
        let trailing = lines.iter().rev().take_while(|l| l.is_empty()).count();
        // Trailing blank lines past the block belong to whatever follows, unless the block keeps them.
        if chomp != '+' {
            end -= self.lines[..end]
                .iter()
                .rev()
                .take_while(|(_, l)| l.trim().is_empty())
                .count();
        }
        self.pos = end;
        let body = lines[..lines.len() - trailing].join("\n");
        Ok(Value::String(match chomp {
            '-' => body,
            '+' => format!("{body}{}", "\n".repeat(trailing + 1)),
            _ if body.is_empty() => body,
            _ => format!("{body}\n"),
        }))
    }
}

/// `key: rest` → (key, rest); None when the line is not a map entry.
fn split_key(text: &str, no: usize) -> Result<Option<(String, String)>> {
    if text.starts_with('"') || text.starts_with('\'') {
        let (key, used) = quoted(text, no)?;
        let after = &text[used..];
        return Ok(match after.strip_prefix(':') {
            Some(rest) if rest.is_empty() || rest.starts_with(' ') => Some((key, rest.to_string())),
            _ => None,
        });
    }
    if text.starts_with(['[', '{']) {
        return Ok(None);
    }
    let bytes = text.as_bytes();
    for (i, b) in bytes.iter().enumerate() {
        if *b == b'#' && i > 0 && bytes[i - 1] == b' ' {
            return Ok(None);
        }
        if *b == b':' && (i + 1 == bytes.len() || bytes[i + 1] == b' ') {
            let key = text[..i].trim_end();
            if key.is_empty() {
                return Ok(None);
            }
            check_plain_start(key, no)?;
            return Ok(Some((key.to_string(), text[i + 1..].to_string())));
        }
    }
    Ok(None)
}

fn strip_comment(text: &str) -> &str {
    match text.find(" #") {
        Some(i) => &text[..i],
        None if text.starts_with('#') => "",
        None => text,
    }
}

fn check_plain_start(text: &str, no: usize) -> Result<()> {
    match text.chars().next() {
        Some('&') | Some('*') => err(no, "anchors and aliases (& *) are not supported; repeat the value"),
        Some('!') => err(no, "tags (!) are not supported"),
        Some('%') | Some('@') | Some('`') => err(no, format!("`{text}` must be quoted")),
        _ => Ok(()),
    }
}

/// A value on one line: a flow collection, a quoted string or a plain scalar.
fn inline(text: &str, no: usize) -> Result<Value> {
    let (value, used) = if text.starts_with(['[', '{']) {
        let mut at = 0;
        let value = flow(text, &mut at, no)?;
        (value, at)
    } else if text.starts_with(['"', '\'']) {
        let (s, used) = quoted(text, no)?;
        (Value::String(s), used)
    } else {
        let plain = strip_comment(text).trim_end();
        check_plain_start(plain, no)?;
        return resolve(plain, no);
    };
    let rest = text[used..].trim_start();
    if !rest.is_empty() && !rest.starts_with('#') {
        return err(no, format!("unexpected `{rest}` after the value"));
    }
    Ok(value)
}

fn flow(text: &str, at: &mut usize, no: usize) -> Result<Value> {
    let bytes = text.as_bytes();
    let skip = |at: &mut usize| {
        while *at < bytes.len() && bytes[*at] == b' ' {
            *at += 1;
        }
    };
    skip(at);
    match bytes.get(*at) {
        Some(b'[') => {
            *at += 1;
            let mut items = Vec::new();
            loop {
                skip(at);
                if bytes.get(*at) == Some(&b']') {
                    *at += 1;
                    return Ok(Value::Array(items));
                }
                items.push(flow(text, at, no)?);
                skip(at);
                match bytes.get(*at) {
                    Some(b',') => *at += 1,
                    Some(b']') => {}
                    _ => return err(no, "flow lists are `[a, b]` on one line"),
                }
            }
        }
        Some(b'{') => {
            *at += 1;
            let mut map = Map::new();
            loop {
                skip(at);
                if bytes.get(*at) == Some(&b'}') {
                    *at += 1;
                    return Ok(Value::Object(map));
                }
                let key = if matches!(bytes.get(*at), Some(b'"') | Some(b'\'')) {
                    let (key, used) = quoted(&text[*at..], no)?;
                    *at += used;
                    key
                } else {
                    let start = *at;
                    while *at < bytes.len() && !matches!(bytes[*at], b':' | b',' | b'}') {
                        *at += 1;
                    }
                    let key = text[start..*at].trim();
                    check_plain_start(key, no)?;
                    key.to_string()
                };
                if key == "<<" {
                    return err(no, "merge keys (<<) are not supported; write the keys out");
                }
                skip(at);
                if bytes.get(*at) != Some(&b':') {
                    return err(no, "flow maps are `{key: value}` on one line");
                }
                *at += 1;
                if map.contains_key(&key) {
                    return err(no, format!("duplicate key `{key}`"));
                }
                let value = flow(text, at, no)?;
                map.insert(key, value);
                skip(at);
                match bytes.get(*at) {
                    Some(b',') => *at += 1,
                    Some(b'}') => {}
                    _ => return err(no, "flow maps are `{key: value}` on one line"),
                }
            }
        }
        Some(b'"') | Some(b'\'') => {
            let (s, used) = quoted(&text[*at..], no)?;
            *at += used;
            Ok(Value::String(s))
        }
        Some(_) => {
            let start = *at;
            while *at < bytes.len() && !matches!(bytes[*at], b',' | b']' | b'}') {
                *at += 1;
            }
            let plain = text[start..*at].trim();
            check_plain_start(plain, no)?;
            resolve(plain, no)
        }
        None => err(no, "unterminated flow collection"),
    }
}

/// A quoted scalar at the start of `text`; returns it and the bytes it spans.
fn quoted(text: &str, no: usize) -> Result<(String, usize)> {
    let mut out = String::new();
    let mut chars = text.char_indices().skip(1).peekable();
    if text.starts_with('\'') {
        while let Some((i, c)) = chars.next() {
            if c == '\'' {
                if chars.peek().map(|(_, c)| *c) == Some('\'') {
                    chars.next();
                    out.push('\'');
                    continue;
                }
                return Ok((out, i + 1));
            }
            out.push(c);
        }
        return err(no, "unterminated quoted string");
    }
    while let Some((i, c)) = chars.next() {
        match c {
            '"' => return Ok((out, i + 1)),
            '\\' => {
                let Some((_, e)) = chars.next() else { break };
                let hex = |chars: &mut std::iter::Peekable<_>, n: usize| -> Result<u32> {
                    let digits: String = (0..n)
                        .filter_map(|_| chars.next().map(|(_, c): (usize, char)| c))
                        .collect();
                    u32::from_str_radix(&digits, 16)
                        .ok()
                        .filter(|_| digits.len() == n)
                        .map_or_else(|| err(no, "invalid escape"), Ok)
                };
                let decoded = match e {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'n' => '\n',
                    't' => '\t',
                    'r' => '\r',
                    'b' => '\u{8}',
                    'f' => '\u{c}',
                    '0' => '\0',
                    ' ' => ' ',
                    'x' => char::from_u32(hex(&mut chars, 2)?).unwrap_or('\u{fffd}'),
                    'U' => char::from_u32(hex(&mut chars, 8)?).map_or_else(|| err(no, "invalid escape"), Ok)?,
                    'u' => {
                        let high = hex(&mut chars, 4)?;
                        if (0xD800..0xDC00).contains(&high) {
                            if chars.next().map(|(_, c)| c) != Some('\\') || chars.next().map(|(_, c)| c) != Some('u') {
                                return err(no, "unpaired surrogate escape");
                            }
                            let low = hex(&mut chars, 4)?;
                            char::from_u32(0x10000 + ((high - 0xD800) << 10) + (low.wrapping_sub(0xDC00) & 0x3FF))
                                .map_or_else(|| err(no, "invalid escape"), Ok)?
                        } else {
                            char::from_u32(high).map_or_else(|| err(no, "invalid escape"), Ok)?
                        }
                    }
                    other => return err(no, format!("unsupported escape \\{other}")),
                };
                out.push(decoded);
            }
            c => out.push(c),
        }
    }
    err(no, "unterminated quoted string")
}

static INT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^-?(0|[1-9][0-9]*)$").unwrap());
static FLOAT: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?$").unwrap());
static LOCAL_TIME: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt ][0-9]{1,2}:[0-9]{2}(:[0-9]{2}(\.[0-9]+)?)?$").unwrap()
});

/// What a plain scalar means: null, a boolean, a number or a string.
pub fn resolve(plain: &str, no: usize) -> Result<Value> {
    Ok(match plain {
        "" | "~" | "null" | "Null" | "NULL" => Value::Null,
        "true" | "True" | "TRUE" => Value::Bool(true),
        "false" | "False" | "FALSE" => Value::Bool(false),
        ".inf" | "-.inf" | "+.inf" | ".nan" | ".NaN" | ".Inf" | "-.Inf" => {
            return err(no, format!("`{plain}` has no JSON value"));
        }
        _ if INT.is_match(plain) => match plain.parse::<i64>() {
            Ok(n) => Value::Number(n.into()),
            Err(_) => return err(no, format!("`{plain}` is too large; quote it to keep it as text")),
        },
        _ if FLOAT.is_match(plain) => match plain.parse::<f64>().ok().and_then(Number::from_f64) {
            Some(n) => Value::Number(n),
            None => return err(no, format!("`{plain}` is not a finite number")),
        },
        _ if LOCAL_TIME.is_match(plain) => {
            return err(
                no,
                format!("`{plain}` has no UTC offset; write timestamps as ISO UTC (…Z)"),
            );
        }
        _ => Value::String(plain.to_string()),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::sync::yaml_out;
    use serde_json::json;

    fn parsed(yaml: &str) -> Value {
        parse(yaml, 1).unwrap_or_else(|e| panic!("line {}: {}", e.line, e.message))
    }

    #[test]
    fn reads_the_documented_file_and_writes_it_back_byte_for_byte() {
        let text = "---\nuid: zr_9f2c41d07ab35e8c1d44\ntitle: Typed blocks\nslug: typed-blocks\nstatus: published\npublishedAt: 2026-11-12T18:30:00.000Z\nexcerpt: null\ncover: 12\ntags: [news, guide]\nauthorId: zr_6720f1c0a4be93d1e275\nseo:\n  metaTitle: Typed blocks\n  metaDescription: null\n  noindex: false\ngallery:\n  - image: 7\n    caption: Hero\n---\nBody in Markdown.\n";
        let doc = parse_document(text).unwrap();
        assert_eq!(
            Value::Object(doc.front.clone()),
            json!({"uid": "zr_9f2c41d07ab35e8c1d44", "title": "Typed blocks", "slug": "typed-blocks", "status": "published",
                "publishedAt": "2026-11-12T18:30:00.000Z", "excerpt": null, "cover": 12, "tags": ["news", "guide"],
                "authorId": "zr_6720f1c0a4be93d1e275", "seo": {"metaTitle": "Typed blocks", "metaDescription": null, "noindex": false},
                "gallery": [{"image": 7, "caption": "Hero"}]})
        );
        assert_eq!(doc.body, "Body in Markdown.\n");
        let mut fields = doc.front.clone();
        let uid = fields.shift_remove("uid").unwrap();
        assert_eq!(
            yaml_out::markdown(uid.as_str().unwrap(), &fields, Some(&doc.body)),
            text
        );
    }

    #[test]
    fn values_survive_emit_and_parse() {
        let fields = json!({
            "looksNumeric": "12", "looksBool": "true", "looksNull": "null", "yes": "yes", "empty": "", "spaced": " padded ",
            "colon": "a: b", "hash": "a #b", "dash": "- x", "quote": "say \"hi\"", "unicode": "é 😀", "control": "a\u{1}b",
            "multi": "line one\nline two\n", "noFinalNewline": "a\nb", "indentedFirst": "  code\nmore",
            "float": 1.5, "big": 1e21, "negative": -3, "list": ["a, b", "c"], "nested": [[1, 2], {"k": "v"}],
            "maps": [{"a": 1, "b": {"c": [true]}}, {}], "emptyList": [], "emptyMap": {}, "deep": {"x": {"y": null}},
            "quoted key: x": 1,
            "keepsTwo": "x\n\n", "keepsThree": "x\n\n\n", "spacesLine": "a\n  \nb\n", "lastSpaces": "a\n  ",
            "leadingBlank": "\n  code\n  more\n", "blankThenText": "\n\nx\n", "huge": 1e20, "bigger": 1.5e300, "maxInt": 9223372036854775807i64, "minInt": -9223372036854775808i64
        });
        let rendered = yaml_out::markdown("zr_00000000000000000000", fields.as_object().unwrap(), None);
        let doc = parse_document(&rendered).unwrap_or_else(|e| panic!("line {}: {}\n{rendered}", e.line, e.message));
        let mut front = doc.front;
        front.shift_remove("uid");
        assert_eq!(Value::Object(front), fields, "{rendered}");
        assert_eq!(doc.body, "");
    }

    #[test]
    fn accepts_common_yaml_spellings() {
        assert_eq!(
            parsed(
                "a:\n- 1\n- two\nb: 'it''s'\nc: \"\\u00e9\\n\"\nd: |-\n  x\n\n  y\ne: [ ]\nf: {k: v}  # comment\ng:\n"
            ),
            json!({"a": [1, "two"], "b": "it's", "c": "é\n", "d": "x\n\ny", "e": [], "f": {"k": "v"}, "g": null})
        );
    }

    #[test]
    fn refuses_what_is_outside_the_subset_with_its_line() {
        for (yaml, line, message) in [
            ("a: 1\nb: &x 2", 2, "anchors"),
            ("a: 1\nb: *x", 2, "anchors"),
            ("a: !!str 1", 1, "tags"),
            ("a: 1\n<<: {b: 2}", 2, "merge keys"),
            ("a: 1\nb: 2\na: 3", 3, "duplicate key `a`"),
            ("at: 2026-11-12T18:30:00", 1, "UTC offset"),
            ("a: >\n  folded", 1, "folded"),
            ("a: 1\n\tb: 2", 2, "tabs"),
            ("a:\n  b: 1\n c: 2", 3, "indentation"),
            ("a: [1, 2", 1, "flow lists"),
            ("a: 1\nm: {<<: {x: 1}}", 2, "merge keys"),
            ("m: {&k a: 1}", 1, "anchors"),
            ("m: {!t a: 1}", 1, "tags"),
            ("m: {a: 1, a: 2}", 1, "duplicate key `a`"),
        ] {
            let error = parse(yaml, 1).expect_err(yaml);
            assert_eq!(error.line, line, "{yaml}: {}", error.message);
            assert!(error.message.contains(message), "{yaml}: {}", error.message);
        }
        assert_eq!(parse_document("title: x\n").err().unwrap().line, 1);
        assert!(
            parse_document("---\ntitle: x\n").is_err(),
            "an unterminated front matter is refused"
        );
    }
}
