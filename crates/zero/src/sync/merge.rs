//! Per-field three-way merge of sync records (design §5.4 step 3). Fields merge independently against
//! the base; maps merge member-wise; lists and scalars are atomic. Bodies are merged by line on the
//! server; this takes its answer as `body` input.

use serde_json::{Map, Value};

/// Where a field ended up after merging.
#[derive(Debug, PartialEq)]
pub enum Outcome {
    Merged(Map<String, Value>),
    /// JSON Pointers (`/fields/title`, `/fields/seo/metaTitle`) of the fields both sides changed differently.
    Conflict(Vec<String>),
}

/// Merge the `fields` of two records against their base. Absence and null are different values.
pub fn fields(base: &Map<String, Value>, ours: &Map<String, Value>, theirs: &Map<String, Value>) -> Outcome {
    let mut conflicts = Vec::new();
    let merged = map(base, ours, theirs, "/fields", &mut conflicts);
    if conflicts.is_empty() {
        Outcome::Merged(merged)
    } else {
        Outcome::Conflict(conflicts)
    }
}

fn map(
    base: &Map<String, Value>,
    ours: &Map<String, Value>,
    theirs: &Map<String, Value>,
    at: &str,
    conflicts: &mut Vec<String>,
) -> Map<String, Value> {
    let mut keys: Vec<&String> = Vec::new();
    for key in theirs.keys().chain(ours.keys()).chain(base.keys()) {
        if !keys.contains(&key) {
            keys.push(key);
        }
    }
    let mut out = Map::new();
    for key in keys {
        let pointer = format!("{at}/{}", key.replace('~', "~0").replace('/', "~1"));
        if let Some(value) = value(base.get(key), ours.get(key), theirs.get(key), &pointer, conflicts) {
            out.insert(key.clone(), value);
        }
    }
    out
}

/// One value: None = absent in the result.
fn value(
    base: Option<&Value>,
    ours: Option<&Value>,
    theirs: Option<&Value>,
    at: &str,
    conflicts: &mut Vec<String>,
) -> Option<Value> {
    if ours == theirs {
        return ours.cloned();
    }
    if ours == base {
        return theirs.cloned();
    }
    if theirs == base {
        return ours.cloned();
    }
    if let (Some(Value::Object(b)), Some(Value::Object(o)), Some(Value::Object(t))) = (base, ours, theirs) {
        return Some(Value::Object(map(b, o, t, at, conflicts)));
    }
    conflicts.push(at.to_string());
    ours.cloned()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn obj(v: Value) -> Map<String, Value> {
        v.as_object().unwrap().clone()
    }

    fn merge(base: Value, ours: Value, theirs: Value) -> Outcome {
        fields(&obj(base), &obj(ours), &obj(theirs))
    }

    #[test]
    fn one_side_changed_takes_that_side() {
        assert_eq!(
            merge(
                json!({"title": "a", "tags": ["x"]}),
                json!({"title": "b", "tags": ["x"]}),
                json!({"title": "a", "tags": ["y"]})
            ),
            Outcome::Merged(obj(json!({"title": "b", "tags": ["y"]})))
        );
    }

    #[test]
    fn both_changed_to_the_same_value_agree() {
        assert_eq!(
            merge(json!({"t": 1}), json!({"t": 2}), json!({"t": 2})),
            Outcome::Merged(obj(json!({"t": 2})))
        );
    }

    #[test]
    fn a_scalar_changed_differently_conflicts() {
        assert_eq!(
            merge(
                json!({"t": "a", "u": 1}),
                json!({"t": "b", "u": 1}),
                json!({"t": "c", "u": 2})
            ),
            Outcome::Conflict(vec!["/fields/t".into()])
        );
    }

    #[test]
    fn maps_merge_member_wise() {
        assert_eq!(
            merge(
                json!({"seo": {"a": 1, "b": 1}}),
                json!({"seo": {"a": 2, "b": 1}}),
                json!({"seo": {"a": 1, "b": 3}})
            ),
            Outcome::Merged(obj(json!({"seo": {"a": 2, "b": 3}})))
        );
        assert_eq!(
            merge(
                json!({"seo": {"a/b": 1}}),
                json!({"seo": {"a/b": 2}}),
                json!({"seo": {"a/b": 3}})
            ),
            Outcome::Conflict(vec!["/fields/seo/a~1b".into()])
        );
    }

    #[test]
    fn lists_are_atomic() {
        assert_eq!(
            merge(json!({"l": [1, 2]}), json!({"l": [1, 2, 3]}), json!({"l": [0, 1, 2]})),
            Outcome::Conflict(vec!["/fields/l".into()])
        );
    }

    #[test]
    fn null_and_absent_differ() {
        assert_eq!(
            merge(json!({"e": "x"}), json!({"e": null}), json!({})),
            Outcome::Conflict(vec!["/fields/e".into()])
        );
        assert_eq!(
            merge(json!({"e": "x"}), json!({"e": null}), json!({"e": "x"})),
            Outcome::Merged(obj(json!({"e": null})))
        );
    }
}
