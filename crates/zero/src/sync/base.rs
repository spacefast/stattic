//! `.zero/sync-base.json` (design §5.4 step 1): the last record both sides agreed on, per site, per
//! collection, per uid. Written through a temp file and rename, never partially.

use serde_json::{Map, Value, json};
use std::path::{Path, PathBuf};

pub struct Bases {
    path: PathBuf,
    doc: Value,
}

impl Bases {
    pub fn load(project: &Path) -> Result<Bases, String> {
        let path = project.join(".zero/sync-base.json");
        let doc = match std::fs::read(&path) {
            Ok(bytes) => {
                let doc: Value = serde_json::from_slice(&bytes).map_err(|e| format!("{}: {e}", path.display()))?;
                if doc["version"] != 1 || !doc["sites"].is_object() {
                    return Err(format!("{}: unsupported sync base; expected version 1", path.display()));
                }
                doc
            }
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => json!({"version": 1, "sites": {}}),
            Err(e) => return Err(format!("{}: {e}", path.display())),
        };
        Ok(Bases { path, doc })
    }

    /// False on the first run against this site: no bases, so nothing may be deleted.
    pub fn knows_site(&self, site: &str) -> bool {
        self.doc["sites"].get(site).is_some()
    }

    pub fn collection(&self, site: &str, collection: &str) -> Map<String, Value> {
        self.doc["sites"][site][collection]
            .as_object()
            .cloned()
            .unwrap_or_default()
    }

    /// The base record for a uid, as `{ digest, record }`.
    pub fn get(&self, site: &str, collection: &str, uid: &str) -> Option<&Value> {
        self.doc["sites"].get(site)?.get(collection)?.get(uid)
    }

    pub fn set(&mut self, site: &str, collection: &str, uid: &str, digest: &str, record: &Value) {
        let collections = self.site_mut(site);
        let entry = collections.entry(collection).or_insert_with(|| json!({}));
        entry[uid] = json!({"digest": digest, "record": record});
    }

    pub fn remove(&mut self, site: &str, collection: &str, uid: &str) {
        if let Some(entries) = self.site_mut(site).get_mut(collection).and_then(Value::as_object_mut) {
            entries.shift_remove(uid);
        }
    }

    /// Record that this site has been seen, so later runs may delete.
    pub fn touch(&mut self, site: &str) {
        self.site_mut(site);
    }

    fn site_mut(&mut self, site: &str) -> &mut Map<String, Value> {
        let sites = self.doc["sites"].as_object_mut().expect("sites is an object");
        sites
            .entry(site)
            .or_insert_with(|| json!({}))
            .as_object_mut()
            .expect("site is an object")
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    /// Ok(true) when the file changed.
    pub fn save(&self) -> Result<bool, String> {
        let text = serde_json::to_string_pretty(&self.doc).expect("serializable") + "\n";
        if std::fs::read(&self.path).is_ok_and(|old| old == text.as_bytes()) {
            return Ok(false);
        }
        super::write_atomic(&self.path, text.as_bytes())?;
        Ok(true)
    }
}
