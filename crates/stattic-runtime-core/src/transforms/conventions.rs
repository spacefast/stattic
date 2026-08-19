use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};

use crate::routing::escape_regex_literal;

#[derive(Debug, Deserialize)]
pub struct RuntimeConventionsInput {
    #[serde(default)]
    pub redirects: Vec<Value>,
    #[serde(default)]
    pub headers: Vec<Value>,
}

#[derive(Debug, Default, Serialize)]
pub struct RuntimeConventions {
    pub headers_exact: Map<String, Value>,
    pub headers_pattern: Vec<Value>,
    pub redirects_exact: Map<String, Value>,
    pub redirects_pattern: Vec<Value>,
}

pub fn lower_runtime_conventions(input: RuntimeConventionsInput) -> RuntimeConventions {
    let mut output = RuntimeConventions::default();
    for (order, rule) in input.redirects.into_iter().enumerate() {
        let mut indexed = rule;
        indexed["order"] = json!(order);
        if indexed.get("match").and_then(Value::as_str) == Some("exact") {
            if let Some(source) = indexed.get("source").and_then(Value::as_str) {
                output
                    .redirects_exact
                    .entry(source.to_string())
                    .or_insert_with(|| Value::Array(Vec::new()))
                    .as_array_mut()
                    .expect("redirect bucket is an array")
                    .push(indexed);
            } else {
                output.redirects_pattern.push(indexed);
            }
        } else {
            output.redirects_pattern.push(indexed);
        }
    }
    for (order, rule) in input.headers.into_iter().enumerate() {
        let mut indexed = rule;
        indexed["order"] = json!(order);
        let path = indexed.get("path").and_then(Value::as_str);
        let regex = indexed.get("regex").and_then(Value::as_str);
        let hostless = indexed.get("host").is_none_or(Value::is_null);
        let exact = path
            .zip(regex)
            .is_some_and(|(path, regex)| regex == format!("^{}$", escape_regex_literal(path)));
        if exact && hostless {
            let path = path.expect("exact header path exists").to_string();
            output
                .headers_exact
                .entry(path)
                .or_insert_with(|| Value::Array(Vec::new()))
                .as_array_mut()
                .expect("header bucket is an array")
                .push(indexed);
        } else {
            output.headers_pattern.push(indexed);
        }
    }
    output
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn host_qualified_exact_headers_remain_request_time_patterns() {
        let output = lower_runtime_conventions(RuntimeConventionsInput {
            redirects: Vec::new(),
            headers: vec![json!({
                "path": "/docs",
                "host": "docs.example.test",
                "regex": "^/docs$",
                "operations": [],
                "headers": {},
            })],
        });
        assert!(output.headers_exact.is_empty());
        assert_eq!(output.headers_pattern.len(), 1);
    }
}
