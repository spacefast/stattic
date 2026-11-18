//! Stylesheets imported by components and pages (docs/decisions/2026-11-08-tsx-to-php.md, "CSS").
//!
//! The compiler doesn't parse CSS into rules; it scans tokens far enough to do three things:
//! - find the class selectors of a CSS module (`x.module.css`), so they can be renamed;
//! - refuse what a copied stylesheet can't keep: `@import` (the imported file would not travel) and
//!   a relative `url(...)` (the stylesheet is served from another folder than its source);
//! - refuse the CSS-module features TypedPress doesn't implement (`:global`, `:local`, `composes`).
//!
//! Strings, comments and `url(...)` bodies are skipped as tokens, so a `.x` inside them is never
//! taken for a class.

/// A refusal at a byte offset of the stylesheet.
#[derive(Debug, PartialEq, Eq)]
pub struct Refusal {
    pub at: usize,
    pub message: String,
    pub help: &'static str,
}

/// What a scan found: each class selector's byte range (without the `.`) and name, in order.
#[derive(Debug, Default)]
pub struct Scanned {
    pub classes: Vec<(usize, usize, String)>,
}

fn ident_start(c: u8) -> bool {
    c.is_ascii_alphabetic() || c == b'_' || c == b'-' || c >= 0x80
}

fn ident_char(c: u8) -> bool {
    ident_start(c) || c.is_ascii_digit()
}

/// Scans `text`. `module` collects class selectors and refuses the CSS-module keywords.
pub fn scan(text: &str, module: bool) -> Result<Scanned, Refusal> {
    let b = text.as_bytes();
    let mut out = Scanned::default();
    let mut i = 0;
    // One entry per open `{`: whether that block holds rules (true) or declarations (false).
    let mut blocks: Vec<bool> = Vec::new();
    // Whether the text since the last `{`, `}` or `;` is an at-rule prelude.
    let mut at_rule = false;
    let mut prelude_start = 0;
    while i < b.len() {
        let c = b[i];
        match c {
            b'/' if b.get(i + 1) == Some(&b'*') => {
                i = text[i + 2..].find("*/").map_or(b.len(), |e| i + 2 + e + 2);
                continue;
            }
            b'"' | b'\'' => {
                i = string_end(b, i);
                continue;
            }
            b'\\' => {
                i += 2;
                continue;
            }
            b'{' => {
                let prelude = text[prelude_start..i].trim();
                // `@media`, `@supports`, `@layer`, `@container` and `@document` hold rules; every
                // other block (`@font-face`, a rule's own body, a keyframe) holds declarations.
                let rules = at_rule
                    && ["@media", "@supports", "@layer", "@container", "@document", "@scope"]
                        .iter()
                        .any(|r| prelude.starts_with(r));
                blocks.push(rules);
                at_rule = false;
                prelude_start = i + 1;
            }
            b'}' => {
                blocks.pop();
                at_rule = false;
                prelude_start = i + 1;
            }
            b';' => {
                at_rule = false;
                prelude_start = i + 1;
            }
            b'@' if text[prelude_start..i].trim().is_empty() => {
                at_rule = true;
                let name: String = text[i + 1..]
                    .bytes()
                    .take_while(|c| ident_char(*c))
                    .map(char::from)
                    .collect();
                if name.eq_ignore_ascii_case("import") {
                    return Err(Refusal {
                        at: i,
                        message: "`@import` in an imported stylesheet isn't supported".into(),
                        help: "import each stylesheet from the component or page that uses it: `import \"./theme.css\";`",
                    });
                }
            }
            _ => {}
        }
        let in_declarations = blocks.last().is_some_and(|rules| !rules);
        // `url(` anywhere: its body is one token, and a relative one would point at the wrong folder.
        if (c == b'u' || c == b'U')
            && text[i..].len() >= 4
            && text[i..i + 4].eq_ignore_ascii_case("url(")
            && (i == 0 || !ident_char(b[i - 1]))
        {
            let start = i + 4;
            let (end, target) = url_body(text, start);
            let target = target.trim().trim_matches(['"', '\'']);
            let absolute = target.is_empty()
                || target.starts_with('/')
                || target.starts_with('#')
                || target.contains("://")
                || target.to_ascii_lowercase().starts_with("data:");
            if !absolute {
                return Err(Refusal {
                    at: i,
                    message: format!(
                        "`url({target})` is relative to the stylesheet, which is served from another folder"
                    ),
                    help: "use an absolute URL (put the file in public/ and write `url(/name.svg)`), or import the image in TSX and pass its URL",
                });
            }
            i = end;
            continue;
        }
        if module && !in_declarations && !at_rule {
            if c == b':' {
                let name: String = text[i + 1..]
                    .bytes()
                    .take_while(|c| ident_char(*c))
                    .map(char::from)
                    .collect();
                if name == "global" || name == "local" {
                    return Err(Refusal {
                        at: i,
                        message: format!("`:{name}` isn't supported in CSS modules"),
                        help: "put selectors that mustn't be renamed in a plain stylesheet (`x.css`) and import it beside the module",
                    });
                }
            }
            if c == b'.'
                && b.get(i + 1).is_some_and(|n| {
                    ident_start(*n) && *n != b'-' || *n == b'-' && b.get(i + 2).is_some_and(|m| ident_start(*m))
                })
            {
                let start = i + 1;
                let mut end = start;
                while end < b.len() && ident_char(b[end]) {
                    end += 1;
                }
                out.classes.push((start, end, text[start..end].to_string()));
                i = end;
                continue;
            }
        }
        if module && in_declarations && text[i..].starts_with("composes") && (i == 0 || !ident_char(b[i - 1])) {
            let rest = text[i + "composes".len()..].trim_start();
            if rest.starts_with(':') {
                return Err(Refusal {
                    at: i,
                    message: "`composes` isn't supported in CSS modules".into(),
                    help: "list both classes on the element instead: `className={`${styles.a} ${styles.b}`}`",
                });
            }
        }
        i += 1;
    }
    Ok(out)
}

fn string_end(b: &[u8], start: usize) -> usize {
    let quote = b[start];
    let mut i = start + 1;
    while i < b.len() {
        match b[i] {
            b'\\' => i += 2,
            c if c == quote => return i + 1,
            b'\n' => return i,
            _ => i += 1,
        }
    }
    b.len()
}

/// The end of a `url(` body (after its `)`), and the body.
fn url_body(text: &str, start: usize) -> (usize, &str) {
    let b = text.as_bytes();
    let mut i = start;
    while i < b.len() && b[i].is_ascii_whitespace() {
        i += 1;
    }
    if i < b.len() && (b[i] == b'"' || b[i] == b'\'') {
        let end = string_end(b, i);
        let close = text[end..].find(')').map_or(b.len(), |e| end + e + 1);
        return (close, &text[start..close.saturating_sub(1).max(start)]);
    }
    let close = text[start..].find(')').map_or(b.len(), |e| start + e);
    (close.saturating_add(1).min(b.len()), &text[start..close])
}

/// `text` with each scanned class renamed by `name`.
pub fn rename(text: &str, scanned: &Scanned, name: impl Fn(&str) -> String) -> String {
    let mut out = String::with_capacity(text.len() + scanned.classes.len() * 8);
    let mut at = 0;
    for (start, end, class) in &scanned.classes {
        out.push_str(&text[at..*start]);
        out.push_str(&name(class));
        at = *end;
    }
    out.push_str(&text[at..]);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn classes(text: &str) -> Vec<String> {
        scan(text, true)
            .unwrap()
            .classes
            .into_iter()
            .map(|(_, _, c)| c)
            .collect()
    }

    #[test]
    fn class_selectors_are_found_outside_declarations_strings_and_urls() {
        assert_eq!(
            classes(".note { max-width: 42rem; } .back a.dek:hover, .x-y > .a_b { color: red; }"),
            ["note", "back", "dek", "x-y", "a_b"]
        );
        // Numbers, strings, comments and url() bodies hold no classes.
        assert_eq!(
            classes("/* .c */ .a { margin: .5rem; content: \".s\"; background: url(/x.y/z.png); }"),
            ["a"]
        );
        // At-rule preludes are not selectors; rules inside @media are.
        assert_eq!(classes("@media (min-width: .5em) { .a { x: 1 } }"), ["a"]);
        assert_eq!(classes("@font-face { src: url(/f.woff2); } .b {}"), ["b"]);
    }

    #[test]
    fn renaming_keeps_everything_else_byte_for_byte() {
        let text = ".note{max-width:42rem}\n.back .note { color: #47524c }\n";
        let scanned = scan(text, true).unwrap();
        assert_eq!(
            rename(text, &scanned, |c| format!("note_{c}")),
            ".note_note{max-width:42rem}\n.note_back .note_note { color: #47524c }\n"
        );
    }

    #[test]
    fn refusals_name_what_to_do() {
        let refused = |t: &str, module: bool| scan(t, module).unwrap_err().message;
        assert!(refused("@import \"x.css\";", false).contains("@import"));
        assert!(refused(".a { background: url(./x.png) }", false).contains("relative"));
        assert!(refused(".a { background: url('img/x.png') }", false).contains("relative"));
        assert!(refused(":global(.a) {}", true).contains(":global"));
        assert!(refused(".a { composes: b; }", true).contains("composes"));
        // A plain stylesheet keeps its selectors as written, :global included.
        assert!(scan(":global(.a) { background: url(data:image/png;base64,AA) }", false).is_ok());
        assert!(scan(".a { background: url(\"https://x.test/a.png\") }", false).is_ok());
    }
}
