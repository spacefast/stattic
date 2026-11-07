//! One `zero sync` run (design §5.4): for every synced collection, references first, read the files,
//! the site and the bases, plan each uid, apply, and record the new bases.

use crate::sync::base::Bases;
use crate::sync::client::{Client, Problem};
use crate::sync::conflict::{self, Side};
use crate::sync::files::{json_record, md_record};
use crate::sync::plan::{self, Action, Item, Remote};
use crate::sync::uid::{for_file, valid as valid_uid};
use crate::sync::{Deferred, Report, write_atomic, yaml_out};
use indexmap::IndexMap;
use serde_json::{Map, Value, json};
use std::path::{Path, PathBuf};

pub struct Options {
    pub only: Option<Vec<String>>,
    /// Plan only: write nothing to either side.
    pub check: bool,
}

/// A synced collection, as `/_schema` describes it.
struct Collection {
    name: String,
    dir: PathBuf,
    format: String,
    slug: Option<String>,
    /// The collection designates a content column: the file's body (`body` key in JSON) is the record body.
    content: bool,
    /// Columns a record's `fields` carries, in schema order.
    fields: Vec<String>,
    references: Vec<String>,
}

/// Collections that sync, targets of references first (a cycle keeps config order).
fn collections(schema: &Value, project: &Path, only: &Option<Vec<String>>) -> Result<Vec<Collection>, String> {
    let all = schema["collections"]
        .as_object()
        .ok_or("the site's /_schema has no collections")?;
    let mut synced: IndexMap<String, Collection> = IndexMap::new();
    for (name, spec) in all {
        let Some(sync) = spec["sync"].as_object() else { continue };
        if only.as_ref().is_some_and(|o| !o.contains(name)) {
            continue;
        }
        let content = spec["content"].as_str();
        // A JSON file's `body` key is the content column; an ordinary column named `body` beside one
        // would have no key of its own.
        if sync["format"] == "json" && content.is_some_and(|c| c != "body") && spec["columns"].get("body").is_some() {
            return Err(format!(
                "{name}: syncs as JSON with a content column, so it can't also have a column named body; rename that column"
            ));
        }
        let pk = spec["primaryKey"].as_str().unwrap_or("id");
        let columns = spec["columns"].as_object().cloned().unwrap_or_default();
        let fields = columns
            .iter()
            .filter(|(c, def)| {
                *c != pk
                    && Some(c.as_str()) != content
                    && *c != "uid"
                    && def.get("onUpdate").is_none()
                    && def["hidden"] != true
                    && (def["derived"] != true || (*c == "translations" && spec["localized"] == true))
            })
            .map(|(c, _)| c.clone())
            .collect();
        let references = columns
            .values()
            .filter_map(|def| def["references"]["collection"].as_str().map(String::from))
            .filter(|target| target != name)
            .collect();
        synced.insert(
            name.clone(),
            Collection {
                name: name.clone(),
                dir: project.join(sync["dir"].as_str().unwrap_or(name)),
                format: sync["format"].as_str().unwrap_or("md").into(),
                slug: spec["slug"].as_str().map(String::from),
                content: content.is_some(),
                fields,
                references,
            },
        );
    }
    if let Some(unknown) = only.iter().flatten().find(|o| !synced.contains_key(*o)) {
        return Err(format!("--only {unknown}: no such synced collection"));
    }
    let mut ordered: Vec<Collection> = Vec::new();
    let mut pending: Vec<String> = synced.keys().cloned().collect();
    while !pending.is_empty() {
        let ready = pending
            .iter()
            .position(|n| synced[n].references.iter().all(|r| !pending.contains(r) || r == n))
            .unwrap_or(0);
        let name = pending.remove(ready);
        ordered.push(synced.shift_remove(&name).expect("pending"));
    }
    Ok(ordered)
}

/// A file read from a sync directory.
struct File {
    path: PathBuf,
    item: Item,
    /// sha256 of the bytes read: before replacing, moving or deleting the file, it must still hold them.
    bytes: String,
}

fn bytes_digest(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(bytes).iter().map(|b| format!("{b:02x}")).collect()
}

/// The file still holds what this run read (or, with None, there is still no file).
fn unchanged(path: &Path, read: Option<&str>) -> bool {
    match (std::fs::read(path), read) {
        (Ok(now), Some(read)) => bytes_digest(&now) == read,
        (Err(e), None) => e.kind() == std::io::ErrorKind::NotFound,
        _ => false,
    }
}

/// A record without its body: content the user can't read (field access) arrives that way, and a file
/// can't carry what it never received, so local records are compared and pushed without one too.
fn without_body(item: &Item) -> Item {
    let mut record = item.record.clone();
    if let Some(r) = record.as_object_mut() {
        r.shift_remove("body");
    }
    Item::new(record)
}

/// Every record file of a collection by uid; files without a uid get one written in first.
fn read_files(c: &Collection, report: &mut Report, check: bool) -> (IndexMap<String, File>, bool) {
    let mut out = IndexMap::new();
    let mut clean = true;
    let entries = match std::fs::read_dir(&c.dir) {
        Ok(entries) => entries,
        // No directory yet: genuinely no files (first pull creates it).
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return (out, true),
        // Anything else would look like every file was deleted: report it and infer no deletions.
        Err(e) => {
            report.fail(format!("{}: {e}", report.rel(&c.dir)));
            return (out, false);
        }
    };
    let mut paths: Vec<PathBuf> = Vec::new();
    for entry in entries {
        match entry {
            Ok(entry) => paths.push(entry.path()),
            Err(e) => {
                report.fail(format!("{}: {e}", report.rel(&c.dir)));
                clean = false;
            }
        }
    }
    paths.retain(|p| p.extension().and_then(|e| e.to_str()) == Some(c.format.as_str()));
    paths.sort();
    for path in paths {
        let rel = report.rel(&path);
        let raw = match std::fs::read(&path) {
            Ok(b) => b,
            Err(e) => {
                report.fail(format!("{rel}: {e}"));
                clean = false;
                continue;
            }
        };
        let Ok(text) = String::from_utf8(raw.clone()) else {
            report.fail(format!("{rel}: not UTF-8 text"));
            clean = false;
            continue;
        };
        let mut bytes = bytes_digest(&raw);
        let parsed = if c.format == "json" {
            json_record(&text, c.content)
        } else {
            md_record(&text)
        };
        let (mut front, body) = match parsed {
            Ok(p) => p,
            Err((line, message)) => {
                report.fail(format!("{rel}:{line}: {message}"));
                clean = false;
                continue;
            }
        };
        let uid = match front.shift_remove("uid") {
            Some(Value::String(u)) if valid_uid(&u) => u,
            None if !check => {
                let uid = for_file(&c.name, &path.file_name().unwrap_or_default().to_string_lossy());
                let text = render(c, &uid, &front, body.as_deref());
                // Never write over an edit (or recreate a deletion) made since the file was read.
                if !unchanged(&path, Some(&bytes)) {
                    report.fail(format!(
                        "{rel}: changed while zero sync ran; left as it is, run zero sync again"
                    ));
                    clean = false;
                    continue;
                }
                if let Err(e) = write_atomic(&path, text.as_bytes()) {
                    report.fail(format!("{rel}: {e}"));
                    clean = false;
                    continue;
                }
                report.written(&path);
                bytes = bytes_digest(text.as_bytes());
                uid
            }
            None => {
                report.pending(format!("{rel}: new file; zero sync will give it a uid and push it"));
                continue;
            }
            Some(_) => {
                report.fail(format!(
                    "{rel}: uid must be zr_ followed by 20 lowercase hex characters"
                ));
                clean = false;
                continue;
            }
        };
        if let Some(other) = out.get(&uid) {
            let other: &File = other;
            report.fail(format!("{rel}: uid {uid} is also in {}", report.rel(&other.path)));
            clean = false;
            continue;
        }
        let record = json!({"uid": uid, "fields": front, "body": body});
        out.insert(
            uid,
            File {
                path,
                item: Item::new(record),
                bytes,
            },
        );
    }
    (out, clean)
}

/// The file for a record, its fields in the collection's order.
fn render(c: &Collection, uid: &str, fields: &Map<String, Value>, body: Option<&str>) -> String {
    let mut ordered = Map::new();
    for name in &c.fields {
        if let Some(v) = fields.get(name) {
            ordered.insert(name.clone(), v.clone());
        }
    }
    for (k, v) in fields {
        if !ordered.contains_key(k) {
            ordered.insert(k.clone(), v.clone());
        }
    }
    if c.format == "json" {
        let mut out = Map::new();
        out.insert("uid".into(), Value::String(uid.into()));
        out.extend(ordered);
        if c.content {
            out.insert("body".into(), body.map_or(Value::Null, |b| Value::String(b.into())));
        }
        serde_json::to_string_pretty(&Value::Object(out)).expect("serializable") + "\n"
    } else {
        yaml_out::markdown(uid, &ordered, body)
    }
}

/// `<slug>.md` when the collection has a slug and the record a usable one, else `<uid>.md`.
fn file_name(c: &Collection, record: &Value) -> String {
    let slug = c.slug.as_ref().and_then(|s| record["fields"][s].as_str()).filter(|s| {
        !s.is_empty() && !s.starts_with('.') && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_')
    });
    format!(
        "{}.{}",
        slug.unwrap_or(record["uid"].as_str().unwrap_or("record")),
        c.format
    )
}

/// The site's records of a collection, all pages. Absence from this inventory means "deleted on the
/// site", so every page and entry is validated before anything is concluded from it.
fn pull(client: &Client, c: &Collection) -> Result<IndexMap<String, Remote>, Problem> {
    let invalid = |why: String| Problem {
        status: 200,
        body: json!({"detail": format!("/sync/{}: {why}", c.name), "code": "invalid_response"}),
    };
    let mut out = IndexMap::new();
    let mut after: i64 = 0;
    loop {
        let page = client.get(&format!("/sync/{}?after={after}&first=100", c.name))?;
        let (Some(records), Some(next)) = (page["records"].as_array(), page.get("next")) else {
            return Err(invalid("the answer is not a records page".into()));
        };
        let next = match next {
            Value::Null => None,
            n => match n.as_i64() {
                Some(n) if n > after => Some(n),
                _ => return Err(invalid(format!("the page cursor {n} does not advance past {after}"))),
            },
        };
        if next.is_some() && records.is_empty() {
            return Err(invalid("a page with more to come is empty".into()));
        }
        for item in records {
            let (uid, remote) = entry(item).map_err(&invalid)?;
            if out.insert(uid.clone(), remote).is_some() {
                return Err(invalid(format!("{uid} is listed twice")));
            }
        }
        match next {
            Some(n) => after = n,
            None => return Ok(out),
        }
    }
}

/// One entry of a records page: trashed, refused or a live record with its digest.
fn entry(item: &Value) -> Result<(String, Remote), String> {
    let digest_ok =
        |d: &str| d.len() == 71 && d.starts_with("sha256:") && d[7..].bytes().all(|b| b.is_ascii_hexdigit());
    if item["trashed"] == true {
        let uid = item["uid"]
            .as_str()
            .filter(|u| valid_uid(u))
            .ok_or("a trashed entry has no valid uid")?;
        return Ok((uid.into(), Remote::Gone));
    }
    if item["trashed"] != false {
        return Err(format!("an entry has no trashed flag: {item}"));
    }
    if let Some(refused) = item["refused"].as_str() {
        let uid = item["uid"]
            .as_str()
            .filter(|u| valid_uid(u))
            .ok_or("a refused entry has no valid uid")?;
        return Ok((
            uid.into(),
            Remote::Refused(item["message"].as_str().unwrap_or(refused).to_string()),
        ));
    }
    let record = &item["record"];
    let uid = record["uid"]
        .as_str()
        .filter(|u| valid_uid(u))
        .ok_or_else(|| format!("an entry has no valid record uid: {item}"))?;
    let digest = item["digest"]
        .as_str()
        .filter(|d| digest_ok(d))
        .ok_or_else(|| format!("{uid} has no valid digest"))?;
    // `body` is absent when this user can't read the content (field access); present, it's text or null.
    let body_ok = record.get("body").is_none_or(|b| b.is_null() || b.is_string());
    if !record["fields"].is_object() || !body_ok {
        return Err(format!("{uid} is not a record"));
    }
    // The digest is what the next write expects and what the base remembers: it must be this record's.
    if crate::sync::canon::digest(record) != digest {
        return Err(format!("{uid}'s digest doesn't match its record"));
    }
    Ok((
        uid.into(),
        Remote::Live(Item {
            record: record.clone(),
            digest: digest.into(),
        }),
    ))
}

pub fn run(project: &Path, client: &Client, options: &Options, report: &mut Report) -> Result<(), String> {
    let schema = client
        .get("/_schema")
        .map_err(|p| report.problem("could not read the site's schema", &p))?;
    let site = schema["site"]["id"]
        .as_str()
        .ok_or("the site's /_schema has no site id; rebuild and deploy with this Zero version")?
        .to_string();
    let mut bases = Bases::load(project)?;
    let known = bases.knows_site(&site);
    if !known {
        report.note("first sync with this site: records are matched, nothing is deleted".into());
    }
    let all = collections(&schema, project, &options.only)?;
    // Every base change is made only after both sides landed, so whatever the run did is recorded
    // even when a later step fails: a run that stops early never leaves an older base behind.
    let outcome = run_collections(client, &all, &site, known, &mut bases, options, report);
    if !options.check {
        bases.touch(&site);
        if bases.save()? {
            report.written(bases.path());
        }
    }
    outcome
}

#[allow(clippy::too_many_arguments)]
fn run_collections(
    client: &Client,
    all: &[Collection],
    site: &str,
    known: bool,
    bases: &mut Bases,
    options: &Options,
    report: &mut Report,
) -> Result<(), String> {
    for c in all {
        collection(client, c, site, known, bases, options, report, None)?;
    }
    // Records whose references weren't on the site yet (cycles, self-references): once more, now that
    // every collection's records have been pushed. Their bases only move when they land.
    // Records waiting on references: pass again while each pass lands something, then report the rest.
    loop {
        let deferred = std::mem::take(&mut report.deferred);
        if deferred.is_empty() {
            break;
        }
        report.identities = 0;
        for c in all {
            let records: IndexMap<String, Deferred> = deferred
                .iter()
                .filter(|d| d.collection == c.name)
                .map(|d| (d.uid.clone(), d.clone()))
                .collect();
            if !records.is_empty() {
                collection(client, c, site, known, bases, options, report, Some(&records))?;
            }
        }
        // Progress is anything the site gained this pass: a record completing, or an identity created
        // (the next pass may need it). The same records waiting on the same state means nothing can move.
        let state = |ds: &[Deferred]| {
            let mut s: Vec<(String, String, String)> = ds
                .iter()
                .map(|d| (d.collection.clone(), d.uid.clone(), d.expect.clone()))
                .collect();
            s.sort();
            s
        };
        if report.identities == 0 && state(&report.deferred) == state(&deferred) {
            for d in std::mem::take(&mut report.deferred) {
                report.fail(format!("{}/{}: references a record that isn't on the site and can't be created first; sync it, or make one of the references nullable", d.collection, d.uid));
            }
            break;
        }
    }
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn collection(
    client: &Client,
    c: &Collection,
    site: &str,
    known: bool,
    bases: &mut Bases,
    options: &Options,
    report: &mut Report,
    // A later pass: exactly these uids, each with the record planned earlier and the digest it expects.
    only: Option<&IndexMap<String, Deferred>>,
) -> Result<(), String> {
    let (files, clean) = read_files(c, report, options.check);
    let mut remote = pull(client, c).map_err(|p| report.problem(&c.name, &p))?;
    let base_entries = bases.collection(site, &c.name);
    let mut uids: Vec<String> = files.keys().cloned().collect();
    for uid in remote.keys().chain(base_entries.keys()) {
        if !uids.contains(uid) {
            uids.push(uid.clone());
        }
    }
    if let Some(only) = only {
        uids.retain(|u| only.contains_key(u));
    }
    // Bodies both sides changed are merged on the server, in batches.
    let mut merges: Vec<(String, plan::BodyMerge)> = Vec::new();
    let base_of = |uid: &str| {
        base_entries.get(uid).map(|b| Item {
            record: b["record"].clone(),
            digest: b["digest"].as_str().unwrap_or_default().into(),
        })
    };
    for uid in &uids {
        let r = remote.get(uid).cloned().unwrap_or(Remote::Gone);
        if let Some(m) = plan::body_merge(files.get(uid).map(|f| &f.item), &r, base_of(uid).as_ref()) {
            merges.push((uid.clone(), m));
        }
    }
    let mut merged: IndexMap<String, Result<String, ()>> = IndexMap::new();
    for chunk in merges.chunks(100) {
        let records: Vec<Value> = chunk
            .iter()
            .map(|(_, (b, o, t))| json!({"base": b, "ours": o, "theirs": t}))
            .collect();
        let answer = client
            .post(&format!("/sync/{}/merge", c.name), &json!({"records": records}))
            .map_err(|p| report.problem(&c.name, &p))?;
        for ((uid, _), result) in chunk.iter().zip(answer["records"].as_array().into_iter().flatten()) {
            merged.insert(uid.clone(), result["merged"].as_str().map(String::from).ok_or(()));
        }
    }
    for uid in &uids {
        let mut r = remote.shift_remove(uid).unwrap_or(Remote::Gone);
        let base = base_of(uid);
        // Content this user can't read (field access) has no body on the site, so neither in the base nor
        // in the comparison: the file's record is compared and pushed without one.
        let hidden_body = match &r {
            Remote::Live(item) => item.record.get("body").is_none(),
            _ => base.as_ref().is_some_and(|b| b.record.get("body").is_none()),
        };
        let bodiless;
        let file = match files.get(uid) {
            Some(f) if hidden_body && f.item.record.get("body").is_some() => {
                bodiless = File {
                    path: f.path.clone(),
                    item: without_body(&f.item),
                    bytes: f.bytes.clone(),
                };
                Some(&bodiless)
            }
            other => other,
        };
        let mut retried = false;
        loop {
            // A later pass sends the record the first pass planned (merged, if it was), expecting exactly the
            // state it was planned against (or its identity push created). If the site changed since, the
            // push is stale and the record is planned again against what is there now.
            // ...unless the file changed while it waited: then the edit is planned like any other.
            let waiting = only.and_then(|o| o.get(uid));
            let forced = (!retried)
                .then_some(waiting)
                .flatten()
                .filter(|d| d.local == file.map(|f| f.item.digest.clone()))
                .map(|d| Action::Push {
                    record: d.record.clone(),
                    expect: d.expect.clone(),
                });
            let action = forced.unwrap_or_else(|| match waiting {
                // An identity this run created is provisional: nobody agreed on it. Its file changed, so
                // plan the file against the site as it was before the identity existed (absent, no base):
                // an edit is pushed whole over the identity, a deletion trashes it.
                Some(d) if d.identity && remote_digest(&r) == Some(d.expect.as_str()) => match file {
                    Some(f) => Action::Push {
                        record: f.item.record.clone(),
                        expect: d.expect.clone(),
                    },
                    // An unreadable file is skipped, never read as a deletion (§5.4 step 2).
                    None if clean => Action::TrashRemote {
                        expect: d.expect.clone(),
                    },
                    None => Action::Skip("its file can't be read; the record waits for it".into()),
                },
                _ => plan::plan(
                    file.map(|f| &f.item),
                    &r,
                    base.as_ref(),
                    known && clean,
                    merged.get(uid).cloned(),
                ),
            });
            match perform(
                client,
                c,
                uid,
                action,
                file,
                base.as_ref(),
                &r,
                site,
                bases,
                options,
                report,
                waiting.is_some_and(|d| d.identity),
            ) {
                Ok(()) => break,
                Err(Stale) if !retried => {
                    // Re-plan once against what the site holds now.
                    retried = true;
                    r = pull(client, c)
                        .map_err(|p| report.problem(&c.name, &p))?
                        .shift_remove(uid)
                        .unwrap_or(Remote::Gone);
                    // The body merge was against the old remote body: merge against the new one, or none.
                    merged.shift_remove(uid);
                    if let Some((b, o, t)) = plan::body_merge(file.map(|f| &f.item), &r, base.as_ref()) {
                        let answer = client
                            .post(
                                &format!("/sync/{}/merge", c.name),
                                &json!({"base": b, "ours": o, "theirs": t}),
                            )
                            .map_err(|p| report.problem(&c.name, &p))?;
                        merged.insert(uid.clone(), answer["merged"].as_str().map(String::from).ok_or(()));
                    }
                }
                Err(Stale) => {
                    report.fail(format!("{}/{uid}: the site kept changing; run zero sync again", c.name));
                    break;
                }
            }
        }
    }
    Ok(())
}

struct Stale;

#[allow(clippy::too_many_arguments)]
fn perform(
    client: &Client,
    c: &Collection,
    uid: &str,
    action: Action,
    file: Option<&File>,
    base: Option<&Item>,
    remote: &Remote,
    site: &str,
    bases: &mut Bases,
    options: &Options,
    report: &mut Report,
    // The record waits as an identity this run created (a later pass): it stays provisional.
    only_identity: bool,
) -> Result<(), Stale> {
    let name = &c.name;
    let pending = |report: &mut Report, what: &str| report.pending(format!("{name}/{uid}: {what}"));
    match action {
        // Both sides agree; a file whose slug changed on both sides alike still moves.
        Action::Nothing => {
            if let Some(f) = file.filter(|f| f.path != c.dir.join(file_name(c, &f.item.record))) {
                if options.check {
                    pending(report, "move the file to its slug");
                } else {
                    write_record(c, Some(f), &f.item.record, report);
                }
            }
        }
        Action::Skip(why) => report.note(format!("{name}/{uid}: {why}")),
        Action::Refused(message) => report.refused(message),
        Action::Adopt(item) => {
            // Both sides already agree; a slug both changed alike still moves the file, and the base
            // moves once the file is where it belongs.
            let misplaced = file.filter(|f| f.path != c.dir.join(file_name(c, &item.record)));
            if options.check {
                if misplaced.is_some() {
                    pending(report, "move the file to its slug");
                }
            } else if misplaced.is_none() || write_record(c, misplaced, &item.record, report) {
                bases.set(site, name, uid, &item.digest, &item.record);
            }
        }
        Action::Forget => {
            if !options.check {
                bases.remove(site, name, uid);
            }
        }
        Action::Pull(item) => {
            if options.check {
                pending(report, "pull from the site");
                return Ok(());
            }
            if write_record(c, file, &item.record, report) {
                bases.set(site, name, uid, &item.digest, &item.record);
                report.applied();
            }
        }
        Action::Push { record, expect } => {
            if options.check {
                pending(report, "push to the site");
                return Ok(());
            }
            match client.post(&format!("/sync/{name}"), &json!({"record": &record, "expect": &expect})) {
                Ok(answer) => {
                    let stored = answer
                        .get("record")
                        .filter(|r| r.is_object())
                        .cloned()
                        .unwrap_or_else(|| record.clone());
                    let digest = answer["digest"].as_str().unwrap_or_default().to_string();
                    // The file already holds the stored record unless the server reshaped it or its slug moved it.
                    let in_place = file.is_some_and(|f| {
                        f.item.digest == digest
                            && f.path == c.dir.join(file_name(c, &stored))
                            && unchanged(&f.path, Some(&f.bytes))
                    });
                    let landed = in_place || write_record(c, file, &stored, report);
                    if landed {
                        bases.set(site, name, uid, &digest, &stored);
                        report.applied();
                    }
                }
                Err(p) if p.code() == "sync_stale" => return Err(Stale),
                // A reference to a record a later collection (or this one) pushes this run: retry once
                // after every collection's first pass (§5.4's identity-then-fields order for cycles).
                // Only field-target refusals carry a pointer; incoming-constraint errors surface directly.
                Err(p) if p.code() == "reference_violation" && p.body["pointer"].as_str().is_some() => {
                    // A reference to a record not on the site yet (a later collection's, this one's, a cycle):
                    // the record waits for another pass. A new record is created first without the
                    // references the site names as missing (identity first), so records that point at each
                    // other can all exist. Its base doesn't move until the whole record lands.
                    let mut expect_next = expect.clone();
                    // Still provisional from an earlier pass, or created now.
                    let mut identity = only_identity;
                    if expect == "absent" {
                        // When its own required references aren't there yet either, it is retried after others land.
                        if let Ok(digest) = identity_push(client, name, &record, &p) {
                            expect_next = digest;
                            identity = true;
                            report.identities += 1;
                        }
                    }
                    report.defer(Deferred {
                        collection: name.clone(),
                        uid: uid.to_string(),
                        record: record.clone(),
                        expect: expect_next,
                        local: file.map(|f| f.item.digest.clone()),
                        identity,
                    });
                }
                Err(p) => {
                    let message = report.problem(&format!("{name}/{uid}"), &p);
                    report.fail(message);
                }
            }
        }
        Action::TrashRemote { expect } => {
            if options.check {
                pending(report, "trash on the site (its file was deleted)");
                return Ok(());
            }
            // The file was read as missing before the site was pulled: if it came back meanwhile, or
            // the directory can't be told apart from that now, trash nothing (the next run sees it).
            match reappeared(c, uid) {
                Ok(None) => {}
                Ok(Some(back)) => {
                    report.fail(format!(
                        "{}: reappeared while zero sync ran; nothing was trashed, run zero sync again",
                        report.rel(&back)
                    ));
                    return Ok(());
                }
                Err(e) => {
                    report.fail(format!("{e}; can't confirm the file is gone, so nothing was trashed"));
                    return Ok(());
                }
            }
            match client.post(&format!("/sync/{name}/trash"), &json!({"uid": uid, "expect": expect})) {
                Ok(_) => {
                    // The record is in the site's trash. Whether or not the file came back during the
                    // request, the base goes: a returned file then reads as new and is pushed again
                    // (which restores it from the trash), instead of matching the base and being deleted.
                    bases.remove(site, name, uid);
                    match reappeared(c, uid) {
                        Ok(None) => report.applied(),
                        Ok(Some(back)) => report.fail(format!(
                            "{}: came back while its record was being trashed; the next zero sync pushes it again",
                            report.rel(&back)
                        )),
                        Err(e) => report.fail(format!(
                            "{e}; the record was trashed, check the file and run zero sync again"
                        )),
                    }
                }
                Err(p) if p.code() == "sync_stale" => return Err(Stale),
                Err(p) => {
                    let message = report.problem(&format!("{name}/{uid}"), &p);
                    report.fail(message);
                }
            }
        }
        Action::DeleteFile => {
            if options.check {
                pending(report, "delete the file (trashed on the site)");
                return Ok(());
            }
            let f = file.expect("a file to delete");
            let path = &f.path;
            if !unchanged(path, Some(&f.bytes)) {
                report.fail(format!(
                    "{}: changed while zero sync ran; left as it is, run zero sync again",
                    report.rel(path)
                ));
                return Ok(());
            }
            match std::fs::remove_file(path) {
                Ok(()) => {
                    report.written(path);
                    bases.remove(site, name, uid);
                    report.applied();
                }
                Err(e) => report.fail(format!("{}: {e}", report.rel(path))),
            }
        }
        Action::Conflict(pointers) => {
            let theirs = match remote {
                Remote::Live(item) => Some(item),
                _ => None,
            };
            let path = report.project().join(".zero/conflicts").join(format!("{uid}.md"));
            if !options.check {
                let text = conflict::render(conflict::Conflict {
                    uid,
                    pointers: &pointers,
                    local_digest: file.map_or("absent", |f| f.item.digest.as_str()),
                    remote_digest: theirs.map_or("absent", |t| t.digest.as_str()),
                    ours: Side {
                        record: file.map(|f| &f.item.record),
                    },
                    base: Side {
                        record: base.map(|b| &b.record),
                    },
                    theirs: Side {
                        record: theirs.map(|t| &t.record),
                    },
                });
                // An existing draft is the user's, edited or not: never rewrite it. If either side
                // moved since it was written, `resolve` says so (its header digests no longer match),
                // and deleting the draft makes the next run write a fresh one.
                if !path.exists()
                    && let Err(e) = write_atomic(&path, text.as_bytes())
                {
                    report.fail(format!("{}: {e}", report.rel(&path)));
                    return Ok(());
                }
            }
            report.conflict(format!("{name}/{uid}: {} → {}", pointers.join(", "), report.rel(&path)));
        }
    }
    Ok(())
}

/// A file in the collection's directory holding this uid (a deletion that was undone), if any.
/// Whether a record file holding this uid (top-level `uid`) is in the collection's directory now.
/// `Err` when that can't be told (a directory or file that can't be read or parsed): never treat
/// that as absence.
fn reappeared(c: &Collection, uid: &str) -> Result<Option<PathBuf>, String> {
    let entries = match std::fs::read_dir(&c.dir) {
        Ok(entries) => entries,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(e) => return Err(format!("{}: {e}", c.dir.display())),
    };
    for entry in entries {
        let path = entry.map_err(|e| format!("{}: {e}", c.dir.display()))?.path();
        if path.extension().and_then(|e| e.to_str()) != Some(c.format.as_str()) {
            continue;
        }
        let text = std::fs::read_to_string(&path).map_err(|e| format!("{}: {e}", path.display()))?;
        let parsed = if c.format == "json" {
            json_record(&text, c.content)
        } else {
            md_record(&text)
        };
        let (front, _) = parsed.map_err(|(line, m)| format!("{}:{line}: {m}", path.display()))?;
        if front.get("uid").and_then(Value::as_str) == Some(uid) {
            return Ok(Some(path));
        }
    }
    Ok(None)
}

fn remote_digest(r: &Remote) -> Option<&str> {
    match r {
        Remote::Live(item) => Some(item.digest.as_str()),
        _ => None,
    }
}

/// Create a record's identity with refused references set to null, one at a time as the site's generic
/// `reference_violation` pointer names them (`/<column>`). Missing and unreadable targets receive the
/// same treatment; no retry bypasses access checks. Returns the digest for the full push to expect.
fn identity_push(client: &Client, name: &str, record: &Value, first: &Problem) -> Result<String, Problem> {
    let mut identity = record.clone();
    let mut problem = Problem {
        status: first.status,
        body: first.body.clone(),
    };
    loop {
        let column = problem.body["pointer"]
            .as_str()
            .and_then(|p| p.strip_prefix('/'))
            .filter(|c| !c.contains('/'))
            .map(|c| c.replace("~1", "/").replace("~0", "~"));
        match column {
            Some(c) if identity["fields"].get(&c).is_some_and(|v| !v.is_null()) => {
                identity["fields"][c.as_str()] = Value::Null;
            }
            _ => return Err(problem),
        }
        match client.post(
            &format!("/sync/{name}"),
            &json!({"record": identity, "expect": "absent"}),
        ) {
            Ok(answer) => return answer["digest"].as_str().map(String::from).ok_or(problem),
            Err(p) if p.code() == "reference_violation" => problem = p,
            Err(p) => return Err(p),
        }
    }
}

/// Write a record's file where it belongs, moving it when its slug changed. True when it landed.
fn write_record(c: &Collection, current: Option<&File>, record: &Value, report: &mut Report) -> bool {
    let empty = Map::new();
    let uid = record["uid"].as_str().unwrap_or_default();
    let target = c.dir.join(file_name(c, record));
    // Someone may have edited or removed the file since it was read: never write over that. The edit
    // stays, the base doesn't move, and the next run plans it.
    let changed = match current {
        Some(f) => !unchanged(&f.path, Some(&f.bytes)),
        None => !unchanged(&target, None),
    };
    if changed {
        let shown = current.map_or(target.clone(), |f| f.path.clone());
        report.fail(format!(
            "{}: changed while zero sync ran; left as it is, run zero sync again",
            report.rel(&shown)
        ));
        return false;
    }
    let current = current.map(|f| f.path.as_path());
    let text = render(
        c,
        uid,
        record["fields"].as_object().unwrap_or(&empty),
        record["body"].as_str(),
    );
    if target.exists() && current != Some(target.as_path()) {
        report.fail(format!(
            "{}: already holds another record; rename one of them",
            report.rel(&target)
        ));
        return false;
    }
    if let Err(e) = write_atomic(&target, text.as_bytes()) {
        report.fail(format!("{}: {e}", report.rel(&target)));
        return false;
    }
    report.written(&target);
    if let Some(old) = current.filter(|old| *old != target.as_path()) {
        // Two files with one uid would make the next run read either: don't call this landed.
        if let Err(e) = std::fs::remove_file(old) {
            report.fail(format!(
                "{}: moved to {} but couldn't remove the old file: {e}",
                report.rel(old),
                report.rel(&target)
            ));
            report.written(&target);
            return false;
        }
        report.written(old);
    }
    true
}

/// `zero sync resolve <uid>`: the edited conflict file becomes the record on both sides.
pub fn resolve(project: &Path, client: &Client, uid: &str, report: &mut Report) -> Result<(), String> {
    let path = project.join(".zero/conflicts").join(format!("{uid}.md"));
    let raw = std::fs::read(&path).map_err(|e| format!("{}: {e}", report.rel(&path)))?;
    let draft = bytes_digest(&raw);
    let text = String::from_utf8(raw).map_err(|_| format!("{}: not UTF-8 text", report.rel(&path)))?;
    let (files_digest, site_digest) =
        conflict::digests(&text).ok_or("the conflict file's header is missing; rerun zero sync")?;
    let resolved = conflict::resolved(&text)?;
    let schema = client.get("/_schema").map_err(|p| report.problem("site", &p))?;
    let site = schema["site"]["id"]
        .as_str()
        .ok_or("the site's /_schema has no site id")?
        .to_string();
    let mut bases = Bases::load(project)?;
    for c in collections(&schema, project, &None)? {
        let (files, _) = read_files(&c, report, true);
        let remote = pull(client, &c).map_err(|p| report.problem("site", &p))?;
        let in_files = files.get(uid);
        let on_site = remote.get(uid);
        if in_files.is_none() && on_site.is_none() && bases.get(&site, &c.name, uid).is_none() {
            continue;
        }
        // The same projection the run that wrote the conflict used: no body where the site has none.
        let base = bases.get(&site, &c.name, uid).map(|b| b["record"].clone());
        let hidden_body = match on_site {
            Some(Remote::Live(i)) => i.record.get("body").is_none(),
            _ => base.as_ref().is_some_and(|b| b.get("body").is_none()),
        };
        let local_now = in_files.map_or("absent".to_string(), |f| {
            if hidden_body {
                without_body(&f.item).digest
            } else {
                f.item.digest.clone()
            }
        });
        let remote_now = match on_site {
            Some(Remote::Live(i)) => i.digest.clone(),
            _ => "absent".into(),
        };
        if local_now != files_digest || remote_now != site_digest {
            return Err(format!(
                "{uid} changed since the conflict was written; delete .zero/conflicts/{uid}.md and run zero sync to write a fresh one"
            ));
        }
        // Keeping the deleted side: the draft below its header is exactly `(deleted)`. Both sides go.
        if resolved.trim() == "(deleted)" {
            if let Some(f) = in_files
                && !unchanged(&f.path, Some(&f.bytes))
            {
                return Err(format!(
                    "{} changed while resolve ran; nothing was deleted",
                    report.rel(&f.path)
                ));
            }
            if site_digest != "absent" {
                client
                    .post(
                        &format!("/sync/{}/trash", c.name),
                        &json!({"uid": uid, "expect": site_digest}),
                    )
                    .map_err(|p| report.problem("site", &p))?;
            }
            // The record is in the site's trash: its base goes whatever happens to the file, so a file
            // that survives reads as new next run and is pushed back rather than deleted.
            bases.remove(&site, &c.name, uid);
            if bases.save()? {
                report.written(bases.path());
            }
            if let Some(f) = in_files {
                // Edited while the trash request ran: keep that edit, and the draft with it.
                if !unchanged(&f.path, Some(&f.bytes)) {
                    return Err(format!(
                        "{} changed while resolve ran; the record was trashed but your newer file is kept (the next zero sync pushes it back)",
                        report.rel(&f.path)
                    ));
                }
                std::fs::remove_file(&f.path).map_err(|e| format!("{}: {e}", report.rel(&f.path)))?;
                report.written(&f.path);
            }
            report.applied();
            if !unchanged(&path, Some(&draft)) {
                return Err(format!(
                    "{} changed while resolve ran; the deletion was applied and your newer edit is kept there",
                    report.rel(&path)
                ));
            }
            std::fs::remove_file(&path).map_err(|e| e.to_string())?;
            report.written(&path);
            return Ok(());
        }
        let (mut front, body) =
            md_record(&resolved).map_err(|(line, m)| format!("{}:{}: {m}", report.rel(&path), line + 6))?;
        if front.shift_remove("uid").as_ref().and_then(Value::as_str) != Some(uid) {
            return Err("the resolved record must keep its uid".into());
        }
        let mut record = json!({"uid": uid, "fields": front, "body": body});
        if hidden_body {
            record
                .as_object_mut()
                .expect("a record is an object")
                .shift_remove("body");
        }
        let answer = client
            .post(
                &format!("/sync/{}", c.name),
                &json!({"record": record, "expect": site_digest}),
            )
            .map_err(|p| report.problem("site", &p))?;
        let stored = answer
            .get("record")
            .filter(|r| r.is_object())
            .cloned()
            .unwrap_or(record);
        if !write_record(&c, in_files, &stored, report) {
            return Err("the record was applied on the site but its file could not be written".into());
        }
        bases.set(
            &site,
            &c.name,
            uid,
            answer["digest"].as_str().unwrap_or_default(),
            &stored,
        );
        if bases.save()? {
            report.written(bases.path());
        }
        report.applied();
        // The draft may have been edited while resolve ran: keep that edit rather than delete it.
        if !unchanged(&path, Some(&draft)) {
            // Point the kept draft at the state just applied, so `resolve` can apply it in turn.
            let now = std::fs::read_to_string(&path).map_err(|e| e.to_string())?;
            let local = if hidden_body {
                without_body(&Item::new(stored.clone())).digest
            } else {
                Item::new(stored.clone()).digest
            };
            let site_digest = answer["digest"].as_str().unwrap_or_default();
            write_atomic(&path, conflict::with_digests(&now, &local, site_digest).as_bytes())?;
            return Err(format!(
                "{} changed while resolve ran; the version read before was applied, and your newer edit is kept there (run zero sync resolve again to apply it)",
                report.rel(&path)
            ));
        }
        std::fs::remove_file(&path).map_err(|e| e.to_string())?;
        report.written(&path);
        return Ok(());
    }
    Err(format!("no synced record has uid {uid}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_file_changed_since_it_was_read_is_not_unchanged() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("n.md");
        std::fs::write(&path, "read").unwrap();
        let read = bytes_digest(b"read");
        assert!(unchanged(&path, Some(&read)));
        std::fs::write(&path, "edited").unwrap();
        assert!(!unchanged(&path, Some(&read)), "an edit since the read");
        std::fs::remove_file(&path).unwrap();
        assert!(!unchanged(&path, Some(&read)), "a deletion since the read");
        assert!(unchanged(&path, None), "still no file");
    }

    #[test]
    fn records_page_entries_must_be_well_formed() {
        let digest = format!("sha256:{}", "0".repeat(64));
        let record = json!({"uid": "zr_00000000000000000000", "fields": {}, "body": null});
        let live = json!({"record": record, "digest": crate::sync::canon::digest(&record), "trashed": false});
        assert!(matches!(entry(&live), Ok((_, Remote::Live(_)))));
        // No body key: content this user can't read (field access), still a live record.
        let hidden = json!({"uid": "zr_00000000000000000000", "fields": {}});
        assert!(matches!(
            entry(&json!({"record": hidden, "digest": crate::sync::canon::digest(&hidden), "trashed": false})),
            Ok((_, Remote::Live(_)))
        ));
        assert!(matches!(
            entry(&json!({"uid": "zr_00000000000000000000", "record": null, "digest": null, "trashed": true})),
            Ok((_, Remote::Gone))
        ));
        for bad in [
            json!({}),
            json!({"trashed": false}),
            json!({"record": {"uid": "nope", "fields": {}, "body": null}, "digest": digest, "trashed": false}),
            json!({"record": {"uid": "zr_00000000000000000000", "fields": {}, "body": null}, "digest": "sha256:x", "trashed": false}),
            json!({"record": {"uid": "zr_00000000000000000000", "fields": [], "body": null}, "digest": digest, "trashed": false}),
            json!({"record": {"uid": "zr_00000000000000000000", "fields": {}, "body": 7}, "digest": crate::sync::canon::digest(&json!({"uid": "zr_00000000000000000000", "fields": {}, "body": 7})), "trashed": false}),
            json!({"record": record, "digest": digest, "trashed": false}),
            json!({"uid": "", "trashed": true}),
        ] {
            assert!(entry(&bad).is_err(), "{bad}");
        }
    }
}
