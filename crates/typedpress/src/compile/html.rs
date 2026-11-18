//! Static JSX at build time: the markup `\TypedPress\Html::element` renders for an intrinsic element
//! whose tag and attributes are all literal strings or booleans, so the compiler can emit it as one
//! string (`Compiler::jsx_element`). This follows runtime/TypedPress/Html.php byte for byte; an element
//! it can't reproduce exactly (`None`) stays on the runtime path. The tables are copies of
//! Html.php's, and a test checks they still are.

/// A literal attribute value: `name="text"`, a bare `name` or `name={true}`, `name={false}`.
#[derive(Clone, Copy)]
pub(super) enum Lit<'a> {
    Str(&'a str),
    Bool(bool),
}

/// Whether `Html::element` renders `tag` from its attributes and children alone. Form controls
/// (value and checked handling, attribute order), `pre`/`listing` (a doubled leading newline that
/// depends on the child) and custom elements (their own attribute rules) are left to it.
pub(super) fn foldable_tag(tag: &str) -> bool {
    !matches!(
        tag,
        "input" | "textarea" | "select" | "option" | "button" | "form" | "pre" | "listing"
    ) && !tag.contains('-')
}

/// HTML void elements, by the exact tag `Html::element` receives.
pub(super) fn is_void(tag: &str) -> bool {
    VOID.contains(&tag)
}

/// `<tag` followed by its attributes, as `Html::element` writes them (without the closing `>` or
/// `/>`); `None` when an attribute's rendering isn't reproduced here.
pub(super) fn open_tag(tag: &str, attrs: &[(&str, Lit)]) -> Option<String> {
    // A PHP array literal: a repeated name keeps its first position and takes its last value.
    let mut merged: Vec<(&str, Lit)> = Vec::new();
    for &(name, value) in attrs {
        match merged.iter_mut().find(|(n, _)| *n == name) {
            Some(slot) => slot.1 = value,
            None => merged.push((name, value)),
        }
    }
    let mut out = format!("<{tag}");
    // Html::attributes.
    for (name, value) in merged {
        if matches!(
            name,
            "children" | "key" | "ref" | "suppressContentEditableWarning" | "suppressHydrationWarning"
        ) {
            continue;
        }
        let written = alias(name).unwrap_or(if name == "xlinkHref" { "xlink:href" } else { name });
        // Html::safeName's pattern admits these ASCII names; anything else is left to it.
        if !written.is_ascii() {
            return None;
        }
        if !safe_ascii_name(written) {
            continue;
        }
        let lower = written.to_ascii_lowercase();
        let folded = match lower.strip_prefix("xlink:") {
            Some(rest) => format!("xlink{rest}"),
            None => lower,
        };
        if folded.len() > 2 && folded.starts_with("on") {
            continue;
        }
        if folded == "srcdoc" {
            continue;
        }
        let url = URL.contains(&folded.as_str()) || URL.contains(&folded.strip_prefix("xlink").unwrap_or(&folded));
        if url
            && let Lit::Str(text) = value
            && !super::safe_url(text)
        {
            continue;
        }
        out.push_str(&attribute(name, value, tag)?);
    }
    Some(out)
}

/// Html::attribute: one attribute of an HTML or SVG element.
fn attribute(name: &str, value: Lit, tag: &str) -> Option<String> {
    // Js::str and Js::truthy of the value.
    let text = match value {
        Lit::Str(s) => s,
        Lit::Bool(true) => "true",
        Lit::Bool(false) => "false",
    };
    let truthy = match value {
        Lit::Str(s) => !s.is_empty(),
        Lit::Bool(b) => b,
    };
    let escape = super::jsx_escape;
    if name.eq_ignore_ascii_case("style") {
        return None;
    }
    if matches!(name, "href" | "src" | "action" | "formAction" | "xlinkHref") {
        let Lit::Str(url) = value else {
            return Some(String::new());
        };
        if url.is_empty() && (name == "src" || (name == "href" && tag != "a")) {
            return Some(String::new());
        }
        let name = if name == "xlinkHref" { "xlink:href" } else { name };
        return Some(format!(" {name}=\"{}\"", escape(url)));
    }
    if matches!(name, "defaultValue" | "defaultChecked" | "innerHTML") {
        return Some(String::new());
    }
    if matches!(name, "autoFocus" | "multiple" | "muted") {
        return Some(if truthy {
            format!(" {}=\"\"", name.to_ascii_lowercase())
        } else {
            String::new()
        });
    }
    if BOOLEAN.contains(&name) {
        return Some(if truthy { format!(" {name}=\"\"") } else { String::new() });
    }
    if BOOLEANISH.contains(&name) {
        return Some(format!(" {name}=\"{}\"", escape(text)));
    }
    if matches!(name, "capture" | "download") {
        return Some(match value {
            Lit::Bool(true) => format!(" {name}=\"\""),
            Lit::Bool(false) => String::new(),
            Lit::Str(s) => format!(" {name}=\"{}\"", escape(s)),
        });
    }
    // Numeric attributes go through JavaScript's ToNumber: left to the runtime.
    if matches!(name, "cols" | "rows" | "size" | "span" | "rowSpan" | "start") {
        return None;
    }
    if name.len() > 2 && name.get(..2).is_some_and(|p| p.eq_ignore_ascii_case("on")) {
        return Some(String::new());
    }
    let name = alias(name).unwrap_or(name);
    if matches!(value, Lit::Bool(_)) {
        let prefix = name.get(..5).map(str::to_ascii_lowercase);
        if !matches!(prefix.as_deref(), Some("data-" | "aria-")) {
            return Some(String::new());
        }
    }
    Some(format!(" {name}=\"{}\"", escape(text)))
}

fn alias(name: &str) -> Option<&'static str> {
    ALIASES.iter().find(|(from, _)| *from == name).map(|(_, to)| *to)
}

/// React's VALID_ATTRIBUTE_NAME_REGEX (Html::safeName) on an ASCII name.
fn safe_ascii_name(name: &str) -> bool {
    let mut chars = name.chars();
    chars
        .next()
        .is_some_and(|c| c.is_ascii_alphabetic() || c == '_' || c == ':')
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | ':' | '-' | '.'))
}

const ALIASES: &[(&str, &str)] = &[
    ("className", "class"),
    ("htmlFor", "for"),
    ("tabIndex", "tabindex"),
    ("acceptCharset", "accept-charset"),
    ("httpEquiv", "http-equiv"),
    ("crossOrigin", "crossorigin"),
    ("accentHeight", "accent-height"),
    ("alignmentBaseline", "alignment-baseline"),
    ("arabicForm", "arabic-form"),
    ("baselineShift", "baseline-shift"),
    ("capHeight", "cap-height"),
    ("clipPath", "clip-path"),
    ("clipRule", "clip-rule"),
    ("colorInterpolation", "color-interpolation"),
    ("colorInterpolationFilters", "color-interpolation-filters"),
    ("colorProfile", "color-profile"),
    ("colorRendering", "color-rendering"),
    ("dominantBaseline", "dominant-baseline"),
    ("enableBackground", "enable-background"),
    ("fillOpacity", "fill-opacity"),
    ("fillRule", "fill-rule"),
    ("floodColor", "flood-color"),
    ("floodOpacity", "flood-opacity"),
    ("fontFamily", "font-family"),
    ("fontSize", "font-size"),
    ("fontSizeAdjust", "font-size-adjust"),
    ("fontStretch", "font-stretch"),
    ("fontStyle", "font-style"),
    ("fontVariant", "font-variant"),
    ("fontWeight", "font-weight"),
    ("glyphName", "glyph-name"),
    ("glyphOrientationHorizontal", "glyph-orientation-horizontal"),
    ("glyphOrientationVertical", "glyph-orientation-vertical"),
    ("horizAdvX", "horiz-adv-x"),
    ("horizOriginX", "horiz-origin-x"),
    ("imageRendering", "image-rendering"),
    ("letterSpacing", "letter-spacing"),
    ("lightingColor", "lighting-color"),
    ("markerEnd", "marker-end"),
    ("markerMid", "marker-mid"),
    ("markerStart", "marker-start"),
    ("overlinePosition", "overline-position"),
    ("overlineThickness", "overline-thickness"),
    ("paintOrder", "paint-order"),
    ("panose-1", "panose-1"),
    ("pointerEvents", "pointer-events"),
    ("renderingIntent", "rendering-intent"),
    ("shapeRendering", "shape-rendering"),
    ("stopColor", "stop-color"),
    ("stopOpacity", "stop-opacity"),
    ("strikethroughPosition", "strikethrough-position"),
    ("strikethroughThickness", "strikethrough-thickness"),
    ("strokeDasharray", "stroke-dasharray"),
    ("strokeDashoffset", "stroke-dashoffset"),
    ("strokeLinecap", "stroke-linecap"),
    ("strokeLinejoin", "stroke-linejoin"),
    ("strokeMiterlimit", "stroke-miterlimit"),
    ("strokeOpacity", "stroke-opacity"),
    ("strokeWidth", "stroke-width"),
    ("textAnchor", "text-anchor"),
    ("textDecoration", "text-decoration"),
    ("textRendering", "text-rendering"),
    ("transformOrigin", "transform-origin"),
    ("underlinePosition", "underline-position"),
    ("underlineThickness", "underline-thickness"),
    ("unicodeBidi", "unicode-bidi"),
    ("unicodeRange", "unicode-range"),
    ("unitsPerEm", "units-per-em"),
    ("vAlphabetic", "v-alphabetic"),
    ("vHanging", "v-hanging"),
    ("vIdeographic", "v-ideographic"),
    ("vMathematical", "v-mathematical"),
    ("vectorEffect", "vector-effect"),
    ("vertAdvY", "vert-adv-y"),
    ("vertOriginX", "vert-origin-x"),
    ("vertOriginY", "vert-origin-y"),
    ("wordSpacing", "word-spacing"),
    ("writingMode", "writing-mode"),
    ("xmlnsXlink", "xmlns:xlink"),
    ("xHeight", "x-height"),
    ("xlinkActuate", "xlink:actuate"),
    ("xlinkArcrole", "xlink:arcrole"),
    ("xlinkRole", "xlink:role"),
    ("xlinkShow", "xlink:show"),
    ("xlinkTitle", "xlink:title"),
    ("xlinkType", "xlink:type"),
    ("xmlBase", "xml:base"),
    ("xmlLang", "xml:lang"),
    ("xmlSpace", "xml:space"),
];

const VOID: &[&str] = &[
    "area", "base", "br", "col", "embed", "hr", "img", "input", "keygen", "link", "meta", "param", "source", "track",
    "wbr", "menuitem",
];

const BOOLEAN: &[&str] = &[
    "allowFullScreen",
    "async",
    "autoPlay",
    "controls",
    "default",
    "defer",
    "disabled",
    "disablePictureInPicture",
    "disableRemotePlayback",
    "formNoValidate",
    "hidden",
    "inert",
    "loop",
    "noModule",
    "noValidate",
    "open",
    "playsInline",
    "readOnly",
    "required",
    "reversed",
    "scoped",
    "seamless",
    "itemScope",
];

const BOOLEANISH: &[&str] = &[
    "contentEditable",
    "spellCheck",
    "draggable",
    "value",
    "autoReverse",
    "externalResourcesRequired",
    "focusable",
    "preserveAlpha",
];

const URL: &[&str] = &[
    "href",
    "src",
    "action",
    "formaction",
    "xlinkhref",
    "data",
    "ping",
    "poster",
    "background",
    "cite",
    "codebase",
    "longdesc",
    "manifest",
    "srcset",
    "imagesrcset",
    "icon",
    "profile",
    "usemap",
    "lowsrc",
    "dynsrc",
    "archive",
    "classid",
];

#[cfg(test)]
mod tests {
    use super::*;

    /// The tables are Html.php's, entry for entry and in order.
    #[test]
    fn tables_match_the_runtime() {
        let php = include_str!("../../runtime/TypedPress/Html.php");
        let body = |name: &str| {
            let start = php.find(&format!("private const {name} = [")).expect(name);
            let rest = &php[start..];
            rest[..rest.find("];").unwrap()].to_string()
        };
        let quoted = regex::Regex::new(r"'([^']*)' => (?:'([^']*)'|true)").unwrap();
        let pairs = |name: &str| -> Vec<(String, String)> {
            quoted
                .captures_iter(&body(name))
                .map(|c| (c[1].to_string(), c.get(2).map_or("", |m| m.as_str()).to_string()))
                .collect()
        };
        let keys = |name: &str| pairs(name).into_iter().map(|(k, _)| k).collect::<Vec<_>>();
        assert_eq!(
            pairs("ALIASES"),
            ALIASES
                .iter()
                .map(|(a, b)| (a.to_string(), b.to_string()))
                .collect::<Vec<_>>()
        );
        assert_eq!(keys("VOID"), VOID);
        assert_eq!(keys("BOOLEAN"), BOOLEAN);
        assert_eq!(keys("BOOLEANISH"), BOOLEANISH);
        assert_eq!(keys("URL"), URL);
    }

    /// Every tag, attribute name and literal value below, alone and in pairs, rendered here and by
    /// `\\TypedPress\\Html::element` in PHP: the same bytes, or `None` here.
    #[test]
    fn open_tag_matches_html_element_in_php() {
        if std::process::Command::new("php").arg("-v").output().is_err() {
            return;
        }
        let tags = [
            "div",
            "a",
            "img",
            "br",
            "svg",
            "use",
            "td",
            "p",
            "video",
            "iframe",
            "foreignObject",
            "hr",
        ];
        let mut names: Vec<&str> = ALIASES.iter().map(|(name, _)| *name).collect();
        names.extend(BOOLEAN);
        names.extend(BOOLEANISH);
        names.extend(URL);
        names.extend([
            "href",
            "src",
            "action",
            "formAction",
            "xlinkHref",
            "xlink:href",
            "defaultValue",
            "defaultChecked",
            "innerHTML",
            "autoFocus",
            "multiple",
            "muted",
            "capture",
            "download",
            "children",
            "key",
            "ref",
            "suppressHydrationWarning",
            "onClick",
            "ONCLICK",
            "on",
            "data-x",
            "aria-label",
            "DATA-y",
            "srcDoc",
            "srcdoc",
            "id",
            "title",
            "class",
            "for",
            "HREF",
            "Src",
            "XLINK:HREF",
            "xlinkhref",
            "a.b",
            "_x",
            ":x",
            "x-",
            "1x",
            "a b",
            "a\n",
            "lang",
            "dir",
        ]);
        let values = [
            Lit::Str(""),
            Lit::Str("x"),
            Lit::Str("javascript:alert(1)"),
            Lit::Str(" JavaScript:x"),
            Lit::Str("//evil.example"),
            Lit::Str("https://ok.example/?a=1&b=2"),
            Lit::Str("<&\"'>"),
            Lit::Str("0"),
            Lit::Str("false"),
            Lit::Str("é ü"),
            Lit::Bool(true),
            Lit::Bool(false),
        ];
        let mut cases: Vec<(&str, Vec<(&str, Lit)>)> = Vec::new();
        for tag in tags {
            for name in &names {
                for value in values {
                    cases.push((tag, vec![(name, value)]));
                }
            }
        }
        // Pairs, repeated names and orders.
        for (a, b) in [
            ("id", "className"),
            ("className", "id"),
            ("id", "id"),
            ("hidden", "hidden"),
            ("href", "title"),
        ] {
            for value in values {
                cases.push(("a", vec![(a, value), (b, Lit::Str("2"))]));
                cases.push(("div", vec![(a, Lit::Str("1")), (b, value)]));
            }
        }
        let mut php = String::from("<?php\nrequire $argv[1];\n$cases = [\n");
        let mut expected = Vec::new();
        for (tag, attrs) in &cases {
            let Some(open) = open_tag(tag, attrs) else { continue };
            let html = if is_void(tag) {
                format!("{open}/>")
            } else {
                format!("{open}></{tag}>")
            };
            expected.push(html);
            let attrs: Vec<String> = attrs
                .iter()
                .map(|(name, value)| {
                    let value = match value {
                        Lit::Str(s) => super::super::php_str(s),
                        Lit::Bool(b) => b.to_string(),
                    };
                    format!("{} => {value}", super::super::php_str(name))
                })
                .collect();
            php.push_str(&format!(
                "    [{}, [{}]],\n",
                super::super::php_str(tag),
                attrs.join(", ")
            ));
        }
        php.push_str("];\nforeach ($cases as [$tag, $attrs]) { echo json_encode(\\TypedPress\\Html::element($tag, $attrs)->html()), \"\\n\"; }\n");
        let dir = tempfile::tempdir().unwrap();
        let script = dir.path().join("cases.php");
        std::fs::write(&script, php).unwrap();
        let runtime = concat!(env!("CARGO_MANIFEST_DIR"), "/runtime/TypedPress/bootstrap.php");
        let out = std::process::Command::new("php")
            .arg(&script)
            .arg(runtime)
            .output()
            .unwrap();
        assert!(out.status.success(), "{}", String::from_utf8_lossy(&out.stderr));
        let rendered: Vec<String> = String::from_utf8(out.stdout)
            .unwrap()
            .lines()
            .map(|line| serde_json::from_str(line).unwrap())
            .collect();
        assert_eq!(rendered.len(), expected.len());
        let mut mismatches = Vec::new();
        for ((want, got), (tag, attrs)) in rendered
            .iter()
            .zip(&expected)
            .zip(cases.iter().filter(|(t, a)| open_tag(t, a).is_some()))
        {
            if want != got {
                let attrs: Vec<String> = attrs.iter().map(|(n, _)| n.to_string()).collect();
                mismatches.push(format!("<{tag}> {attrs:?}: php {want} / rust {got}"));
            }
        }
        assert!(
            mismatches.is_empty(),
            "{} of {}:\n{}",
            mismatches.len(),
            expected.len(),
            mismatches.join("\n")
        );
        // Most cases fold; the rest (style, numeric attributes, non-ASCII names) stay at run time.
        assert!(
            expected.len() * 10 > cases.len() * 9,
            "{} of {} folded",
            expected.len(),
            cases.len()
        );
    }

    #[test]
    fn literal_attributes_render_as_the_runtime_does() {
        let open = |tag: &str, attrs: &[(&str, Lit)]| open_tag(tag, attrs).unwrap();
        assert_eq!(
            open(
                "a",
                &[
                    ("className", Lit::Str("x")),
                    ("href", Lit::Str("")),
                    ("hidden", Lit::Bool(true))
                ]
            ),
            r#"<a class="x" href="" hidden="""#
        );
        assert_eq!(open("img", &[("src", Lit::Str(""))]), "<img");
        assert_eq!(
            open("div", &[("draggable", Lit::Bool(true)), ("data-x", Lit::Bool(false))]),
            r#"<div draggable="true" data-x="false""#
        );
        assert_eq!(open("div", &[("title", Lit::Bool(true))]), "<div");
        assert_eq!(open("a", &[("href", Lit::Str("javascript:x"))]), "<a");
        assert_eq!(
            open("p", &[("id", Lit::Str("a")), ("id", Lit::Str("b"))]),
            r#"<p id="b""#
        );
        assert_eq!(
            open("p", &[("title", Lit::Str("<\"&'>"))]),
            r#"<p title="&lt;&quot;&amp;&#x27;&gt;""#
        );
        assert!(open_tag("td", &[("rowSpan", Lit::Str("2"))]).is_none());
        assert!(open_tag("p", &[("style", Lit::Str("color:red"))]).is_none());
    }
}
