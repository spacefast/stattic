//! `.zero/conflicts/<uid>.md` (design §5.4 step 5): both sides and their base in one file, in the
//! layout git uses, so the user resolves it in any editor. Nothing is written to either side until then.

use crate::sync::yaml_out;
use serde_json::{Map, Value};

/// One side of a conflicted record. `record` None = that side deleted it.
pub struct Side<'a> {
    pub record: Option<&'a Value>,
}

/// One conflicted uid: the fields both sides changed, both digests it was computed from, three sides.
pub struct Conflict<'a> {
    pub uid: &'a str,
    pub pointers: &'a [String],
    pub local_digest: &'a str,
    pub remote_digest: &'a str,
    pub ours: Side<'a>,
    pub base: Side<'a>,
    pub theirs: Side<'a>,
}

/// The conflict file: a header naming the fields and both digests it was computed from, then
/// `<<<<<<< files` / `||||||| base` / `=======` / `>>>>>>> wordpress` hunks over the rendered files.
pub fn render(c: Conflict) -> String {
    let uid = c.uid;
    let mut out = String::new();
    out.push_str(&format!("# Conflict: {uid}\n"));
    out.push_str(&format!("# Fields: {}\n", c.pointers.join(", ")));
    out.push_str(&format!("# Files: {}\n", c.local_digest));
    out.push_str(&format!("# Site: {}\n", c.remote_digest));
    out.push_str("# Edit the record between the markers into the version to keep (or leave only `(deleted)` to\n");
    out.push_str("# delete it on both sides), delete every marker line,\n");
    out.push_str(&format!("# then run: zero sync resolve {uid}\n"));
    out.push_str(&format!("<<<<<<< files\n{}", rendered(uid, c.ours.record)));
    out.push_str(&format!("||||||| base\n{}", rendered(uid, c.base.record)));
    out.push_str(&format!("=======\n{}", rendered(uid, c.theirs.record)));
    out.push_str(">>>>>>> wordpress\n");
    out
}

/// The conflict file with its header's digests replaced: a draft kept by `resolve` (edited while
/// resolve applied the earlier version) becomes resolvable against the state that version left.
pub fn with_digests(text: &str, files: &str, site: &str) -> String {
    let mut out = String::new();
    let mut header = true;
    for line in text.split_inclusive('\n') {
        if header && line.starts_with("# Files: ") {
            out.push_str(&format!("# Files: {files}\n"));
        } else if header && line.starts_with("# Site: ") {
            out.push_str(&format!("# Site: {site}\n"));
        } else {
            header &= line.starts_with('#');
            out.push_str(line);
        }
    }
    out
}

fn rendered(uid: &str, record: Option<&Value>) -> String {
    match record {
        None => "(deleted)\n".into(),
        Some(record) => {
            let empty = Map::new();
            let text = yaml_out::markdown(
                uid,
                record["fields"].as_object().unwrap_or(&empty),
                record["body"].as_str(),
            );
            if text.ends_with('\n') { text } else { text + "\n" }
        }
    }
}

/// The digests recorded in a conflict file's header: (files, site).
pub fn digests(text: &str) -> Option<(String, String)> {
    let mut files = None;
    let mut site = None;
    for line in text.lines() {
        if let Some(d) = line.strip_prefix("# Files: ") {
            files = Some(d.to_string());
        } else if let Some(d) = line.strip_prefix("# Site: ") {
            site = Some(d.to_string());
        } else if !line.starts_with('#') {
            break;
        }
    }
    Some((files?, site?))
}

/// The resolved record text: the conflict file minus its header, or an error while markers remain.
pub fn resolved(text: &str) -> Result<String, String> {
    let body: Vec<&str> = text.lines().skip_while(|l| l.starts_with('#')).collect();
    for marker in ["<<<<<<<", "|||||||", "=======", ">>>>>>>"] {
        if let Some(n) = body.iter().position(|l| l.starts_with(marker)) {
            return Err(format!(
                "the conflict still has a `{marker}` marker on its line {}; keep one version",
                n + 1
            ));
        }
    }
    Ok(body.join("\n") + "\n")
}
