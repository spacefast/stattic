//! One JSON-with-comments reader for every finalizer config surface.

use serde_json::{Map, Number, Value};
use std::collections::BTreeMap;

#[derive(Debug)]
pub struct ParseError {
    pub message: String,
    pub offset: usize,
}

pub struct Document {
    pub value: Value,
    pub key_offsets: BTreeMap<String, usize>,
    pub object_keys: BTreeMap<String, Vec<String>>,
    /// Byte range of every string VALUE literal, quotes included, keyed by the
    /// path that holds it. Editing a document in place (variable substitution
    /// writes into rule strings and nothing else) needs the span, not the
    /// decoded value.
    pub string_spans: BTreeMap<String, (usize, usize)>,
}

pub fn parse(source: &str) -> Result<Value, String> {
    parse_document(source)
        .map(|document| document.value)
        .map_err(|error| error.message)
}

pub fn parse_document(source: &str) -> Result<Document, ParseError> {
    let mut reader = Reader::new(source);
    let value = reader.read()?;
    Ok(Document {
        value,
        key_offsets: reader.key_offsets,
        object_keys: reader.object_keys,
        string_spans: reader.string_spans,
    })
}

struct Reader<'a> {
    source: &'a str,
    offset: usize,
    key_offsets: BTreeMap<String, usize>,
    object_keys: BTreeMap<String, Vec<String>>,
    string_spans: BTreeMap<String, (usize, usize)>,
}

impl<'a> Reader<'a> {
    fn new(source: &'a str) -> Self {
        Self {
            source,
            offset: 0,
            key_offsets: BTreeMap::new(),
            object_keys: BTreeMap::new(),
            string_spans: BTreeMap::new(),
        }
    }

    fn read(&mut self) -> Result<Value, ParseError> {
        let value = self.value("$")?;
        self.trivia()?;
        if self.offset != self.source.len() {
            return self.fail("Unexpected content", self.offset);
        }
        Ok(value)
    }

    fn value(&mut self, path: &str) -> Result<Value, ParseError> {
        self.trivia()?;
        match self.byte() {
            Some(b'{') => self.object(path),
            Some(b'[') => self.array(path),
            Some(b'"') => {
                let start = self.offset;
                let value = self.string().map(Value::String)?;
                self.string_spans.insert(path.into(), (start, self.offset));
                Ok(value)
            }
            _ => self.token(),
        }
    }

    fn object(&mut self, path: &str) -> Result<Value, ParseError> {
        self.offset += 1;
        self.trivia()?;
        let mut map = Map::new();
        let mut keys = Vec::new();
        if self.byte() == Some(b'}') {
            self.offset += 1;
            self.object_keys.insert(path.into(), keys);
            return Ok(Value::Object(map));
        }
        loop {
            self.trivia()?;
            let key_offset = self.offset;
            if self.byte() != Some(b'"') {
                return self.fail("Expected an object key", self.offset);
            }
            let key = self.string()?;
            if keys.contains(&key) {
                return self.fail(&format!("Duplicate key {key:?}"), key_offset);
            }
            let child = format!("{path}.{key}");
            self.key_offsets.insert(child.clone(), key_offset);
            keys.push(key.clone());
            self.trivia()?;
            if self.byte() != Some(b':') {
                return self.fail("Expected ':' after object key", self.offset);
            }
            self.offset += 1;
            map.insert(key, self.value(&child)?);
            self.trivia()?;
            match self.byte() {
                Some(b'}') => {
                    self.offset += 1;
                    self.object_keys.insert(path.into(), keys);
                    return Ok(Value::Object(map));
                }
                Some(b',') => {
                    self.offset += 1;
                    self.trivia()?;
                    if self.byte() == Some(b'}') {
                        self.offset += 1;
                        self.object_keys.insert(path.into(), keys);
                        return Ok(Value::Object(map));
                    }
                }
                _ => return self.fail("Expected ',' or '}'", self.offset),
            }
        }
    }

    fn array(&mut self, path: &str) -> Result<Value, ParseError> {
        self.offset += 1;
        self.trivia()?;
        let mut values = Vec::new();
        if self.byte() == Some(b']') {
            self.offset += 1;
            return Ok(Value::Array(values));
        }
        loop {
            let child = format!("{path}[{}]", values.len());
            self.key_offsets.insert(child.clone(), self.offset);
            values.push(self.value(&child)?);
            self.trivia()?;
            match self.byte() {
                Some(b']') => {
                    self.offset += 1;
                    return Ok(Value::Array(values));
                }
                Some(b',') => {
                    self.offset += 1;
                    self.trivia()?;
                    if self.byte() == Some(b']') {
                        self.offset += 1;
                        return Ok(Value::Array(values));
                    }
                }
                _ => return self.fail("Expected ',' or ']'", self.offset),
            }
        }
    }

    fn string(&mut self) -> Result<String, ParseError> {
        let start = self.offset;
        self.offset += 1;
        while self.offset < self.source.len() {
            let at = self.offset;
            let byte = self.source.as_bytes()[self.offset];
            self.offset += 1;
            if byte == b'"' {
                return serde_json::from_str(&self.source[start..self.offset]).map_err(|_| {
                    ParseError {
                        message: "Invalid string.".into(),
                        offset: start,
                    }
                });
            }
            if byte < 0x20 {
                return self.fail("Unescaped control character in string", at);
            }
            if byte != b'\\' {
                continue;
            }
            let escape_at = self.offset;
            let Some(escaped) = self.byte() else {
                return self.fail("Unterminated string", start);
            };
            self.offset += 1;
            if b"\"\\/bfnrt".contains(&escaped) {
                continue;
            }
            if escaped != b'u' {
                return self.fail("Invalid escape sequence in string", escape_at);
            }
            for _ in 0..4 {
                let Some(hex) = self.byte() else {
                    return self.fail("Invalid Unicode escape sequence in string", self.offset);
                };
                if !hex.is_ascii_hexdigit() {
                    return self.fail("Invalid Unicode escape sequence in string", self.offset);
                }
                self.offset += 1;
            }
        }
        self.fail("Unterminated string", start)
    }

    fn token(&mut self) -> Result<Value, ParseError> {
        let rest = &self.source[self.offset..];
        for token in ["true", "false", "null"] {
            if rest.starts_with(token) {
                self.offset += token.len();
                return serde_json::from_str(token).map_err(|_| unreachable!());
            }
        }
        let end = rest
            .find(|character: char| {
                character.is_ascii_whitespace() || [',', '}', ']'].contains(&character)
            })
            .unwrap_or(rest.len());
        if end > 0 {
            if let Ok(number) = rest[..end].parse::<Number>() {
                self.offset += end;
                return Ok(Value::Number(number));
            }
        }
        self.fail("Expected a JSON value", self.offset)
    }

    fn trivia(&mut self) -> Result<(), ParseError> {
        loop {
            while self
                .byte()
                .is_some_and(|byte| matches!(byte, b' ' | b'\t' | b'\r' | b'\n'))
            {
                self.offset += 1;
            }
            if self.source[self.offset..].starts_with("//") {
                self.offset = self.source[self.offset + 2..]
                    .find('\n')
                    .map_or(self.source.len(), |next| self.offset + 3 + next);
            } else if self.source[self.offset..].starts_with("/*") {
                let Some(end) = self.source[self.offset + 2..].find("*/") else {
                    return self.fail("Unterminated block comment", self.offset);
                };
                self.offset += end + 4;
            } else {
                return Ok(());
            }
        }
    }

    fn byte(&self) -> Option<u8> {
        self.source.as_bytes().get(self.offset).copied()
    }

    fn fail<T>(&self, message: &str, offset: usize) -> Result<T, ParseError> {
        Err(ParseError {
            message: format!(
                "{}{}",
                message,
                if message.ends_with('.') { "" } else { "." }
            ),
            offset,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn accepts_comments_and_trailing_commas() {
        assert_eq!(
            parse("{/* comment */ \"a\": [1, 2,],}").expect("valid JSONC"),
            json!({"a": [1, 2]})
        );
    }

    #[test]
    fn rejects_duplicate_keys() {
        assert!(parse("{\"a\":1,\"a\":2}")
            .expect_err("duplicate key should fail")
            .contains("Duplicate key"));
    }
}
