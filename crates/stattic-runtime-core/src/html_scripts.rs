//! HTML5 execution eligibility and mapped script text for the native cookie detector.
use html5ever::tendril::StrTendril;
use html5ever::tokenizer::{
    states::RawKind, Doctype, Tag, TagKind, Token, TokenSink, TokenSinkResult,
};
use html5ever::tree_builder::{
    ElementFlags, NodeOrText, QuirksMode, Tracer, TreeBuilder, TreeSink,
};
use html5ever::{local_name, ns, Attribute, QualName};
use html5gum::{
    DefaultEmitter, Emitter, ForwardingEmitter, State, Token as SpannedToken, Tokenizer,
};
use oxc_span::SourceType;
use std::borrow::Cow;
use std::cell::{Cell, Ref, RefCell};

const MAX_NODES: usize = 100_000;
const MAX_TOKENS: usize = 1_000_000;
const MAX_DEPTH: usize = 512;
const MAX_TAG_BYTES: usize = 64 * 1024;

type Handle = usize;

#[derive(Default)]
struct Node {
    name: Option<QualName>,
    parent: Option<Handle>,
    first: Option<Handle>,
    last: Option<Handle>,
    previous: Option<Handle>,
    next: Option<Handle>,
    template: Option<Handle>,
    integration_point: bool,
    script: Option<Script>,
    shadow: bool,
}

#[derive(Default)]
struct Sink {
    nodes: RefCell<Vec<Node>>,
    token: Cell<RangeKind>,
    exhausted: Cell<bool>,
    pending_text: RefCell<Characters>,
    text_offset: Cell<usize>,
    text_is_null: Cell<bool>,
}

#[derive(Clone, Copy, Default)]
enum RangeKind {
    Start(usize),
    #[default]
    Other,
}

// Character spans map parser text back to the uploaded bytes. Adjacent unchanged
// spans coalesce; entity expansions and discarded markup never shift diagnostics.
#[derive(Default)]
struct Characters {
    text: String,
    spans: Vec<TextSpan>,
    overflow: bool,
}
struct TextSpan {
    end: usize,
    source: usize,
    direct: bool,
}
impl Characters {
    fn push(&mut self, text: &str, source: usize, direct: bool) {
        if self.overflow {
            return;
        }
        if self.text.len() + text.len() > super::MAX_SCRIPT {
            self.text.clear();
            self.spans.clear();
            self.overflow = true;
            return;
        }
        self.text.push_str(text);
        let previous_start = self.spans.iter().rev().nth(1).map_or(0, |span| span.end);
        if let Some(last) = self.spans.last_mut() {
            if direct && last.direct && last.source + last.end - previous_start == source {
                last.end = self.text.len();
                return;
            }
        }
        self.spans.push(TextSpan {
            end: self.text.len(),
            source,
            direct,
        });
    }
    fn source_offset(&self, offset: usize) -> usize {
        let index = self.spans.partition_point(|span| span.end <= offset);
        let span = &self.spans[index];
        let start = if index == 0 {
            0
        } else {
            self.spans[index - 1].end
        };
        span.source + if span.direct { offset - start } else { 0 }
    }
}

pub(super) struct Script {
    pub start: usize,
    end: usize,
    pub kind: SourceType,
    characters: Option<Characters>,
}
impl Script {
    pub fn text<'a>(&'a self, source: &'a str) -> &'a str {
        match &self.characters {
            Some(characters) => &characters.text,
            None => &source[self.start..self.end],
        }
    }
    pub fn source_offset(&self, offset: usize) -> usize {
        self.characters
            .as_ref()
            .map_or(self.start + offset, |text| text.source_offset(offset))
    }
}

impl Sink {
    fn allocate(&self, node: Node) -> Handle {
        let mut nodes = self.nodes.borrow_mut();
        let id = nodes.len();
        // TreeSink cannot interrupt a tree-builder operation. Finish this token,
        // then abandon the document before feeding another token.
        if id + 1 >= MAX_NODES {
            self.exhausted.set(true);
        }
        nodes.push(node);
        id
    }

    fn connected(nodes: &[Node], mut id: Handle) -> bool {
        loop {
            if id == 0 {
                return true;
            }
            match nodes[id].parent {
                Some(parent) => id = parent,
                None => return false,
            }
        }
    }

    fn append_text(&self, parent: Handle, text: StrTendril) {
        let pending = self.pending_text.borrow();
        let offset = self.text_offset.get();
        let mut nodes = self.nodes.borrow_mut();
        let Some(Script {
            characters: Some(characters),
            ..
        }) = &mut nodes[parent].script
        else {
            return;
        };
        // SVG uses child text content, excluding comment and element children.
        if pending.overflow {
            characters.overflow = true;
            characters.text.clear();
            characters.spans.clear();
        } else if self.text_is_null.get() {
            characters.push(&text, pending.source_offset(offset), false);
        } else {
            let mut begin = offset;
            let mut span_start = 0;
            for span in &pending.spans {
                let end = span.end.min(offset + text.len());
                if end > begin {
                    characters.push(
                        &text[begin - offset..end - offset],
                        span.source + if span.direct { begin - span_start } else { 0 },
                        span.direct,
                    );
                    begin = end;
                }
                if begin == offset + text.len() {
                    break;
                }
                span_start = span.end;
            }
        }
    }

    fn detach(nodes: &mut [Node], id: Handle) {
        let (parent, previous, next) = (nodes[id].parent, nodes[id].previous, nodes[id].next);
        if let Some(previous) = previous {
            nodes[previous].next = next;
        } else if let Some(parent) = parent {
            nodes[parent].first = next;
        }
        if let Some(next) = next {
            nodes[next].previous = previous;
        } else if let Some(parent) = parent {
            nodes[parent].last = previous;
        }
        nodes[id].parent = None;
        nodes[id].previous = None;
        nodes[id].next = None;
    }

    fn attach(&self, parent: Handle, child: Handle, before: Option<Handle>) {
        let mut nodes = self.nodes.borrow_mut();
        Self::detach(&mut nodes, child);
        let previous = before.and_then(|id| nodes[id].previous).or_else(|| {
            if before.is_none() {
                nodes[parent].last
            } else {
                None
            }
        });
        nodes[child].parent = Some(parent);
        nodes[child].previous = previous;
        nodes[child].next = before;
        if let Some(previous) = previous {
            nodes[previous].next = Some(child);
        } else {
            nodes[parent].first = Some(child);
        }
        if let Some(before) = before {
            nodes[before].previous = Some(child);
        } else {
            nodes[parent].last = Some(child);
        }
    }
}

fn script_type(name: &QualName, attrs: &[Attribute]) -> Option<SourceType> {
    let svg = name.ns == ns!(svg);
    if name.local != local_name!("script") || (!svg && name.ns != ns!(html)) {
        return None;
    }
    let attribute = |namespace, local: &str| {
        attrs
            .iter()
            .find(|a| a.name.ns == namespace && &*a.name.local == local)
    };
    if (svg && (attribute(ns!(), "href").is_some() || attribute(ns!(xlink), "href").is_some()))
        || (!svg && attribute(ns!(), "src").is_some())
    {
        return None;
    }
    let kind = attribute(ns!(), "type")
        .map(|a| a.value.to_string())
        .unwrap_or_else(|| {
            if !svg {
                if let Some(language) = attribute(ns!(), "language").filter(|a| !a.value.is_empty())
                {
                    return format!("text/{}", language.value);
                }
            }
            String::new()
        });
    if kind.eq_ignore_ascii_case("module") {
        return Some(SourceType::mjs());
    }
    if !svg {
        if let (Some(target), Some(event)) = (attribute(ns!(), "for"), attribute(ns!(), "event")) {
            let target = target
                .value
                .trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\u{c}'));
            let event = event
                .value
                .trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\u{c}'));
            if !target.eq_ignore_ascii_case("window")
                || !(event.eq_ignore_ascii_case("onload") || event.eq_ignore_ascii_case("onload()"))
            {
                return None;
            }
        }
    }
    if kind.is_empty() {
        return Some(SourceType::script());
    }
    match kind
        .trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\u{b}' | '\u{c}'))
        .to_ascii_lowercase()
        .as_str()
    {
        "text/javascript"
        | "application/javascript"
        | "text/ecmascript"
        | "application/ecmascript"
        | "application/x-ecmascript"
        | "application/x-javascript"
        | "text/javascript1.0"
        | "text/javascript1.1"
        | "text/javascript1.2"
        | "text/javascript1.3"
        | "text/javascript1.4"
        | "text/javascript1.5"
        | "text/jscript"
        | "text/livescript"
        | "text/x-ecmascript"
        | "text/x-javascript" => Some(SourceType::script()),
        _ => None,
    }
}

// https://html.spec.whatwg.org/multipage/custom-elements.html#valid-custom-element-name
fn valid_custom_element_name(name: &str) -> bool {
    let mut chars = name.chars();
    if !matches!(chars.next(), Some('a'..='z')) || !name.contains('-') {
        return false;
    }
    if matches!(
        name,
        "annotation-xml"
            | "color-profile"
            | "font-face"
            | "font-face-src"
            | "font-face-uri"
            | "font-face-format"
            | "font-face-name"
            | "missing-glyph"
    ) {
        return false;
    }
    chars.all(|c| {
        !c.is_ascii_uppercase()
            && !matches!(c, '\0' | '\t' | '\n' | '\u{c}' | '\r' | ' ' | '/' | '>')
    })
}

impl TreeSink for Sink {
    type Handle = Handle;
    type Output = Self;
    type ElemName<'a> = Ref<'a, QualName>;

    fn finish(self) -> Self {
        self
    }
    fn parse_error(&self, _: Cow<'static, str>) {}
    fn get_document(&self) -> Handle {
        0
    }
    fn same_node(&self, x: &Handle, y: &Handle) -> bool {
        x == y
    }
    fn set_quirks_mode(&self, _: QuirksMode) {}
    fn elem_name<'a>(&'a self, target: &'a Handle) -> Ref<'a, QualName> {
        Ref::map(self.nodes.borrow(), |nodes| {
            nodes[*target].name.as_ref().expect("element")
        })
    }
    fn create_element(&self, name: QualName, attrs: Vec<Attribute>, flags: ElementFlags) -> Handle {
        let script = match self.token.get() {
            RangeKind::Start(start) => script_type(&name, &attrs).map(|kind| Script {
                start,
                end: start,
                kind,
                characters: (name.ns == ns!(svg)).then(Characters::default),
            }),
            _ => None,
        };
        let template = flags.template.then(|| self.allocate(Node::default()));
        self.allocate(Node {
            name: Some(name),
            template,
            script,
            integration_point: flags.mathml_annotation_xml_integration_point,
            ..Node::default()
        })
    }
    fn create_comment(&self, _: StrTendril) -> Handle {
        self.allocate(Node::default())
    }
    fn create_pi(&self, _: StrTendril, _: StrTendril) -> Handle {
        self.allocate(Node::default())
    }
    fn append_doctype_to_document(&self, _: StrTendril, _: StrTendril, _: StrTendril) {}
    fn append(&self, parent: &Handle, child: NodeOrText<Handle>) {
        match child {
            NodeOrText::AppendNode(child) => self.attach(*parent, child, None),
            NodeOrText::AppendText(text) => self.append_text(*parent, text),
        }
    }
    fn append_before_sibling(&self, sibling: &Handle, child: NodeOrText<Handle>) {
        let parent = self.nodes.borrow()[*sibling]
            .parent
            .expect("attached sibling");
        match child {
            NodeOrText::AppendNode(child) => self.attach(parent, child, Some(*sibling)),
            NodeOrText::AppendText(text) => self.append_text(parent, text),
        }
    }
    fn append_based_on_parent_node(
        &self,
        element: &Handle,
        previous: &Handle,
        child: NodeOrText<Handle>,
    ) {
        let attached = self.nodes.borrow()[*element].parent.is_some();
        if attached {
            self.append_before_sibling(element, child);
        } else {
            self.append(previous, child);
        }
    }
    fn attach_declarative_shadow(&self, host: &Handle, template: &Handle, _: &[Attribute]) -> bool {
        let mut nodes = self.nodes.borrow_mut();
        let Some(name) = &nodes[*host].name else {
            return false;
        };
        let valid = name.ns == ns!(html)
            && (matches!(
                name.local.as_ref(),
                "article"
                    | "aside"
                    | "blockquote"
                    | "body"
                    | "div"
                    | "footer"
                    | "h1"
                    | "h2"
                    | "h3"
                    | "h4"
                    | "h5"
                    | "h6"
                    | "header"
                    | "main"
                    | "nav"
                    | "p"
                    | "section"
                    | "span"
            ) || valid_custom_element_name(name.local.as_ref()));
        if !valid || nodes[*host].shadow {
            return false;
        }
        nodes[*host].shadow = true;
        let fragment = nodes[*template].template.expect("template");
        nodes[fragment].parent = Some(*host);
        true
    }
    fn get_template_contents(&self, target: &Handle) -> Handle {
        self.nodes.borrow()[*target].template.expect("template")
    }
    fn is_mathml_annotation_xml_integration_point(&self, target: &Handle) -> bool {
        self.nodes.borrow()[*target].integration_point
    }
    fn add_attrs_if_missing(&self, _: &Handle, _: Vec<Attribute>) {
        // The tree builder only merges attributes onto html and body elements.
    }
    fn remove_from_parent(&self, target: &Handle) {
        Self::detach(&mut self.nodes.borrow_mut(), *target);
    }
    fn reparent_children(&self, node: &Handle, new_parent: &Handle) {
        loop {
            let child = self.nodes.borrow()[*node].first;
            let Some(child) = child else { break };
            self.attach(*new_parent, child, None);
        }
    }
}

#[derive(Default)]
struct ParserHandles {
    handles: RefCell<Vec<Handle>>,
}
impl Tracer for ParserHandles {
    type Handle = Handle;
    fn trace_handle(&self, node: &Handle) {
        self.handles.borrow_mut().push(*node);
    }
}

// html5gum supplies byte spans; html5ever alone chooses tokenizer states and
// foreign-content recovery. There is no independently maintained HTML grammar.
struct SpannedEmitter<'a> {
    inner: DefaultEmitter<usize>,
    tree: &'a TreeBuilder<Handle, Sink>,
    source: &'a str,
    position: usize,
    opening: usize,
    in_tag: bool,
    text: Characters,
}
impl ForwardingEmitter for SpannedEmitter<'_> {
    type Token = SpannedToken<usize>;
    fn inner(&mut self) -> &mut impl Emitter<Token = Self::Token> {
        &mut self.inner
    }
    fn move_position(&mut self, offset: isize) {
        self.position = self.position.saturating_add_signed(offset);
        Emitter::move_position(&mut self.inner, offset);
        if self.in_tag && self.position.saturating_sub(self.opening) > MAX_TAG_BYTES {
            self.tree.sink.exhausted.set(true);
        }
    }
    fn start_open_tag(&mut self) {
        self.opening = self.position.saturating_sub(1);
        Emitter::start_open_tag(&mut self.inner);
    }
    fn init_start_tag(&mut self) {
        self.in_tag = true;
        Emitter::init_start_tag(&mut self.inner);
    }
    fn init_end_tag(&mut self) {
        self.in_tag = true;
        Emitter::init_end_tag(&mut self.inner);
    }
    fn push_attribute_name(&mut self, bytes: &[u8]) {
        if !self.tree.sink.exhausted.get() {
            Emitter::push_attribute_name(&mut self.inner, bytes);
        }
    }
    fn push_attribute_value(&mut self, bytes: &[u8]) {
        if !self.tree.sink.exhausted.get() {
            Emitter::push_attribute_value(&mut self.inner, bytes);
        }
    }
    fn push_tag_name(&mut self, bytes: &[u8]) {
        if !self.tree.sink.exhausted.get() {
            Emitter::push_tag_name(&mut self.inner, bytes);
        }
    }
    fn emit_current_tag(&mut self) -> Option<State> {
        self.in_tag = false;
        Emitter::emit_current_tag(&mut self.inner)
    }
    fn pop_token(&mut self) -> Option<Self::Token> {
        if self.tree.sink.exhausted.get() {
            return Some(SpannedToken::String(Default::default()));
        }
        let token = Emitter::pop_token(&mut self.inner);
        if matches!(token, Some(SpannedToken::String(_))) {
            *self.tree.sink.pending_text.borrow_mut() = std::mem::take(&mut self.text);
        }
        token
    }
    fn emit_string(&mut self, bytes: &[u8]) {
        let text = String::from_utf8_lossy(bytes);
        let start = self.position.saturating_sub(bytes.len());
        let direct = self.source.as_bytes().get(start..self.position) == Some(bytes);
        self.text.push(
            &text,
            if direct {
                start
            } else {
                self.position.saturating_sub(1)
            },
            direct,
        );
        Emitter::emit_string(&mut self.inner, bytes);
    }
    fn adjusted_current_node_present_but_not_in_html_namespace(&mut self) -> bool {
        self.tree
            .adjusted_current_node_present_but_not_in_html_namespace()
    }
}

fn tendril(bytes: &[u8]) -> StrTendril {
    StrTendril::from_slice(&String::from_utf8_lossy(bytes))
}
fn tag(name: &[u8], kind: TagKind, self_closing: bool, attrs: Vec<Attribute>) -> Token {
    Token::TagToken(Tag {
        kind,
        name: String::from_utf8_lossy(name).as_ref().into(),
        self_closing,
        attrs,
        had_duplicate_attributes: false,
    })
}

pub(super) fn scripts(source: &str) -> Vec<Script> {
    let sink = Sink::default();
    sink.allocate(Node::default());
    let tree = TreeBuilder::new(sink, Default::default());
    let emitter = SpannedEmitter {
        inner: DefaultEmitter::new_with_span(),
        tree: &tree,
        source,
        position: 0,
        opening: 0,
        in_tag: false,
        text: Characters::default(),
    };
    let mut tokenizer = Tokenizer::new_with_emitter(source, emitter);
    let mut count = 0;
    let trace = ParserHandles::default();
    let mut scripts = Vec::new();
    while let Some(token) = tokenizer.next() {
        let token = token.expect("in-memory HTML read cannot fail");
        if tree.sink.exhausted.get() {
            return Vec::new();
        }
        count += 1;
        if count > MAX_TOKENS {
            return Vec::new();
        }
        tree.sink.token.set(RangeKind::Other);
        trace.handles.borrow_mut().clear();
        tree.trace_handles(&trace);
        let closing_scripts = if matches!(&token, SpannedToken::EndTag(end) if end.name.as_ref() == b"script")
        {
            trace
                .handles
                .borrow()
                .iter()
                .copied()
                .filter(|id| tree.sink.nodes.borrow()[*id].script.is_some())
                .collect::<Vec<_>>()
        } else {
            Vec::new()
        };
        let mut closing_offset = None;
        let token = match token {
            SpannedToken::StartTag(start) => {
                if start.span.end - start.span.start > MAX_TAG_BYTES {
                    return Vec::new();
                }
                tree.sink.token.set(RangeKind::Start(start.span.end));
                let attrs = start
                    .attributes
                    .into_iter()
                    .map(|(name, value)| Attribute {
                        name: QualName::new(
                            None,
                            ns!(),
                            String::from_utf8_lossy(&name).as_ref().into(),
                        ),
                        value: if name.as_ref() == b"shadowrootmode" {
                            tendril(&value.to_ascii_lowercase())
                        } else {
                            tendril(&value)
                        },
                    })
                    .collect();
                tag(&start.name, TagKind::StartTag, start.self_closing, attrs)
            }
            SpannedToken::EndTag(end) => {
                if end.span.end - end.span.start > MAX_TAG_BYTES {
                    return Vec::new();
                }
                if end.name.as_ref() == b"script" {
                    closing_offset = Some(end.span.start);
                }
                tag(&end.name, TagKind::EndTag, false, Vec::new())
            }
            SpannedToken::String(text) => {
                // NUL is a separate token in html5ever; it affects HTML recovery.
                tree.sink.text_offset.set(0);
                tree.sink.text_is_null.set(false);
                let mut parts = text.split(|b| *b == 0).peekable();
                while let Some(part) = parts.next() {
                    let _ = tree.process_token(Token::CharacterTokens(tendril(part)), 1);
                    tree.sink
                        .text_offset
                        .set(tree.sink.text_offset.get() + part.len());
                    if parts.peek().is_some() {
                        tree.sink.text_is_null.set(true);
                        let _ = tree.process_token(Token::NullCharacterToken, 1);
                        tree.sink.text_is_null.set(false);
                        tree.sink.text_offset.set(tree.sink.text_offset.get() + 1);
                    }
                }
                trace.handles.borrow_mut().clear();
                tree.trace_handles(&trace);
                if tree.sink.exhausted.get() || trace.handles.borrow().len() > MAX_DEPTH {
                    return Vec::new();
                }
                continue;
            }
            SpannedToken::Comment(text) => Token::CommentToken(tendril(&text)),
            SpannedToken::Doctype(doctype) => Token::DoctypeToken(Doctype {
                name: Some(tendril(&doctype.name)),
                public_id: doctype.public_identifier.map(|s| tendril(&s)),
                system_id: doctype.system_identifier.map(|s| tendril(&s)),
                force_quirks: doctype.force_quirks,
            }),
            SpannedToken::Error(_) => continue,
        };
        match tree.process_token(token, 1) {
            TokenSinkResult::RawData(RawKind::Rcdata) => tokenizer.set_state(State::RcData),
            TokenSinkResult::RawData(RawKind::Rawtext) => tokenizer.set_state(State::RawText),
            TokenSinkResult::RawData(RawKind::ScriptData) => tokenizer.set_state(State::ScriptData),
            TokenSinkResult::Plaintext => tokenizer.set_state(State::PlainText),
            TokenSinkResult::Script(_) => tokenizer.set_state(State::Data),
            TokenSinkResult::Continue | TokenSinkResult::EncodingIndicator(_) => {}
            // The tree builder only requests the initial ScriptData state.
            TokenSinkResult::RawData(RawKind::ScriptDataEscaped(_)) => return Vec::new(),
        }
        trace.handles.borrow_mut().clear();
        tree.trace_handles(&trace);
        if tree.sink.exhausted.get() || trace.handles.borrow().len() > MAX_DEPTH {
            return Vec::new();
        }
        if let Some(end) = closing_offset {
            let handles = trace.handles.borrow();
            let mut nodes = tree.sink.nodes.borrow_mut();
            for id in closing_scripts {
                if !handles.contains(&id) {
                    if Sink::connected(&nodes, id) {
                        if let Some(mut script) = nodes[id].script.take() {
                            script.end = end;
                            if script.end - script.start <= super::MAX_SCRIPT
                                && !script.characters.as_ref().is_some_and(|text| text.overflow)
                            {
                                scripts.push(script);
                            }
                        }
                    } else {
                        nodes[id].script = None;
                    }
                }
            }
        }
    }
    tree.sink.token.set(RangeKind::Other);
    let _ = tree.process_token(Token::EOFToken, 1);
    tree.end();
    if tree.sink.exhausted.get() {
        return Vec::new();
    }
    scripts.sort_unstable_by_key(|script| script.start);
    scripts
}
