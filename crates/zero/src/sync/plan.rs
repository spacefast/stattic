//! What to do with one uid (design §5.4 step 3), from its file, its site record and their base. Pure:
//! the caller performs the action. Bodies both sides changed are merged on the server first and the
//! result passed in.

use crate::sync::canon;
use crate::sync::merge::{self, Outcome};
use serde_json::{Map, Value, json};

/// A record and its digest.
#[derive(Clone, Debug, PartialEq)]
pub struct Item {
    pub record: Value,
    pub digest: String,
}

impl Item {
    pub fn new(record: Value) -> Item {
        let digest = canon::digest(&record);
        Item { record, digest }
    }
}

/// The site's side of a uid.
#[derive(Clone, Debug, PartialEq)]
pub enum Remote {
    Live(Item),
    /// Trashed, deleted or never there.
    Gone,
    /// Stored, but its body can't be written in this collection's format.
    Refused(String),
}

#[derive(Debug, PartialEq)]
pub enum Action {
    Nothing,
    /// Both sides hold the same record: remember it as the base.
    Adopt(Item),
    /// Write the file; the site already has it.
    Pull(Item),
    /// Send the record to the site (expecting `expect`), then write the file when it differs.
    Push {
        record: Value,
        expect: String,
    },
    TrashRemote {
        expect: String,
    },
    DeleteFile,
    /// Both sides are gone: drop the base.
    Forget,
    /// Fields (JSON Pointers) both sides changed differently, or `/` for a delete against an edit.
    Conflict(Vec<String>),
    Refused(String),
    /// A deletion can't be inferred this run (first run or an unreadable file in the directory).
    Skip(String),
}

/// Three bodies whose merge the server must decide: (base, ours, theirs).
pub type BodyMerge = (String, String, String);

/// The body merge a uid needs before plan() can finish, if any.
pub fn body_merge(local: Option<&Item>, remote: &Remote, base: Option<&Item>) -> Option<BodyMerge> {
    let (Some(l), Remote::Live(r)) = (local, remote) else {
        return None;
    };
    if l.digest == r.digest || base.is_some_and(|b| b.digest == l.digest || b.digest == r.digest) {
        return None;
    }
    let body = |item: Option<&Item>| item.and_then(|i| i.record["body"].as_str()).unwrap_or("").to_string();
    let (b, o, t) = (body(base), body(Some(l)), body(Some(r)));
    (o != t && o != b && t != b).then_some((b, o, t))
}

/// `merged_body`: the server's answer for body_merge() (Ok(text) or Err(()) when its hunks conflict).
pub fn plan(
    local: Option<&Item>,
    remote: &Remote,
    base: Option<&Item>,
    may_infer_deletion: bool,
    merged_body: Option<Result<String, ()>>,
) -> Action {
    let changed = |side: Option<&Item>| match (side, base) {
        (Some(s), Some(b)) => s.digest != b.digest,
        (None, None) => false,
        _ => true,
    };
    match (local, remote) {
        (_, Remote::Refused(message)) => Action::Refused(message.clone()),
        (None, Remote::Gone) => {
            if base.is_some() {
                Action::Forget
            } else {
                Action::Nothing
            }
        }
        (Some(l), Remote::Gone) => match base {
            None => Action::Push {
                record: l.record.clone(),
                expect: "absent".into(),
            },
            Some(_) if !changed(Some(l)) => Action::DeleteFile,
            Some(_) => Action::Conflict(vec!["/".into()]),
        },
        (None, Remote::Live(r)) => match base {
            None => Action::Pull(r.clone()),
            Some(_) if !may_infer_deletion => {
                Action::Skip("the file is missing, but deletions are off this run".into())
            }
            Some(_) if !changed(Some(r)) => Action::TrashRemote {
                expect: r.digest.clone(),
            },
            Some(_) => Action::Conflict(vec!["/".into()]),
        },
        (Some(l), Remote::Live(r)) => {
            if l.digest == r.digest {
                return if base.is_some_and(|b| b.digest == l.digest) {
                    Action::Nothing
                } else {
                    Action::Adopt(r.clone())
                };
            }
            match (changed(Some(l)), changed(Some(r))) {
                (false, true) => Action::Pull(r.clone()),
                (true, false) => Action::Push {
                    record: l.record.clone(),
                    expect: r.digest.clone(),
                },
                _ => both_changed(l, r, base, merged_body),
            }
        }
    }
}

fn both_changed(l: &Item, r: &Item, base: Option<&Item>, merged_body: Option<Result<String, ()>>) -> Action {
    let empty = Map::new();
    fn fields<'a>(item: Option<&'a Item>, empty: &'a Map<String, Value>) -> &'a Map<String, Value> {
        item.and_then(|i| i.record["fields"].as_object()).unwrap_or(empty)
    }
    let mut pointers = Vec::new();
    let merged = match merge::fields(fields(base, &empty), fields(Some(l), &empty), fields(Some(r), &empty)) {
        Outcome::Merged(m) => m,
        Outcome::Conflict(p) => {
            pointers = p;
            Map::new()
        }
    };
    let body = |item: Option<&Item>| item.map_or(Value::Null, |i| i.record["body"].clone());
    let (b, o, t) = (body(base), body(Some(l)), body(Some(r)));
    let merged_body = if o == t || t == b {
        o
    } else if o == b {
        t
    } else {
        match merged_body {
            Some(Ok(text)) if text.is_empty() => Value::Null,
            Some(Ok(text)) => Value::String(text),
            _ => {
                pointers.push("/body".into());
                Value::Null
            }
        }
    };
    if !pointers.is_empty() {
        return Action::Conflict(pointers);
    }
    let mut record = json!({"uid": l.record["uid"], "fields": merged, "body": merged_body});
    // A record whose content this user can't read carries no body either way: never send one.
    if r.record.get("body").is_none() {
        record
            .as_object_mut()
            .expect("a record is an object")
            .shift_remove("body");
    }
    Action::Push {
        record,
        expect: r.digest.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn item(title: &str, body: Option<&str>) -> Item {
        Item::new(json!({"uid": "zr_00000000000000000000", "fields": {"title": title, "tags": []}, "body": body}))
    }

    #[test]
    fn one_side_changed_moves_to_the_other() {
        let base = item("a", Some("x\n"));
        assert_eq!(
            plan(
                Some(&item("b", Some("x\n"))),
                &Remote::Live(base.clone()),
                Some(&base),
                true,
                None
            ),
            Action::Push {
                record: item("b", Some("x\n")).record,
                expect: base.digest.clone()
            }
        );
        assert_eq!(
            plan(
                Some(&base),
                &Remote::Live(item("c", Some("x\n"))),
                Some(&base),
                true,
                None
            ),
            Action::Pull(item("c", Some("x\n")))
        );
        assert_eq!(
            plan(Some(&base), &Remote::Live(base.clone()), Some(&base), true, None),
            Action::Nothing
        );
    }

    #[test]
    fn both_sides_changed_merge_fields_and_take_the_server_body_merge() {
        let base = item("a", Some("one\n\ntwo\n"));
        let ours = Item::new(
            json!({"uid": "zr_00000000000000000000", "fields": {"title": "a", "tags": ["x"]}, "body": "ONE\n\ntwo\n"}),
        );
        let theirs = item("b", Some("one\n\nTWO\n"));
        let need = body_merge(Some(&ours), &Remote::Live(theirs.clone()), Some(&base));
        assert_eq!(
            need,
            Some(("one\n\ntwo\n".into(), "ONE\n\ntwo\n".into(), "one\n\nTWO\n".into()))
        );
        let merged = plan(
            Some(&ours),
            &Remote::Live(theirs.clone()),
            Some(&base),
            true,
            Some(Ok("ONE\n\nTWO\n".into())),
        );
        assert_eq!(
            merged,
            Action::Push {
                record: json!({"uid": "zr_00000000000000000000", "fields": {"title": "b", "tags": ["x"]}, "body": "ONE\n\nTWO\n"}),
                expect: theirs.digest.clone(),
            }
        );
        assert_eq!(
            plan(Some(&ours), &Remote::Live(theirs), Some(&base), true, Some(Err(()))),
            Action::Conflict(vec!["/body".into()])
        );
    }

    #[test]
    fn a_field_changed_differently_on_both_sides_conflicts() {
        let base = item("a", None);
        assert_eq!(
            plan(
                Some(&item("b", None)),
                &Remote::Live(item("c", None)),
                Some(&base),
                true,
                None
            ),
            Action::Conflict(vec!["/fields/title".into()])
        );
    }

    #[test]
    fn deletions_need_the_other_side_unchanged() {
        let base = item("a", None);
        assert_eq!(
            plan(None, &Remote::Live(base.clone()), Some(&base), true, None),
            Action::TrashRemote {
                expect: base.digest.clone()
            }
        );
        assert_eq!(
            plan(None, &Remote::Live(item("b", None)), Some(&base), true, None),
            Action::Conflict(vec!["/".into()])
        );
        assert_eq!(
            plan(Some(&base), &Remote::Gone, Some(&base), true, None),
            Action::DeleteFile
        );
        assert_eq!(
            plan(Some(&item("b", None)), &Remote::Gone, Some(&base), true, None),
            Action::Conflict(vec!["/".into()])
        );
        assert_eq!(plan(None, &Remote::Gone, Some(&base), true, None), Action::Forget);
    }

    #[test]
    fn first_run_never_deletes() {
        let r = item("a", None);
        assert_eq!(
            plan(None, &Remote::Live(r.clone()), None, false, None),
            Action::Pull(r.clone())
        );
        assert_eq!(
            plan(Some(&r), &Remote::Gone, None, false, None),
            Action::Push {
                record: r.record.clone(),
                expect: "absent".into()
            }
        );
        assert!(matches!(
            plan(None, &Remote::Live(r.clone()), Some(&r), false, None),
            Action::Skip(_)
        ));
    }
}
